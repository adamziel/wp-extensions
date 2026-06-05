<?php
declare(strict_types=1);

/**
 * Converts raw visible text into normalized, optionally stemmed index terms.
 *
 * The pipeline owns tokenization, Unicode normalization, CJK segmentation,
 * optional term namespacing, and optional stemming. Analyzer classes use it for
 * both documents and queries so both sides apply the same lexical contract.
 */
final class WP_FTS_LanguagePipeline
{
    private WP_FTS_Normalizer $normalizer;
    private WP_FTS_SnowballStemmer $snowballStemmer;
    private WP_FTS_PolishStemmer $polishStemmer;
    private ?WP_FTS_Stemmer $customStemmer;
    private bool $enableStemming;
    private bool $namespaceTerms;
    private int $minTermLen;
    private int $maxTermBytes;

    /**
     * Configure the token analysis pipeline.
     *
     * Use `normalizer`, `snowball_stemmer`, or `polish_stemmer` to inject test
     * doubles. Use `stemmer` for a custom stemmer; callables may accept either
     * `($term)` or `($term, $language)`. `namespace_terms` is normally false for
     * the high-level indexer, which namespaces after weighting.
     *
     * @param array{
     *   normalizer?:WP_FTS_Normalizer,
     *   snowball_stemmer?:WP_FTS_SnowballStemmer,
     *   polish_stemmer?:WP_FTS_PolishStemmer,
     *   stemmer?:WP_FTS_Stemmer|callable|null,
     *   enable_stemming?:bool,
     *   namespace_terms?:bool,
     *   min_term_len?:int,
     *   max_term_bytes?:int,
     *   fold_diacritics?:bool,
     *   polish_stemming?:string
     * } $options
     */
    public function __construct(array $options = [])
    {
        $this->normalizer = $options['normalizer'] ?? new WP_FTS_Normalizer([
            'fold_diacritics' => (bool) ($options['fold_diacritics'] ?? true),
        ]);
        $this->snowballStemmer = $options['snowball_stemmer'] ?? new WP_FTS_SnowballStemmer();
        $this->polishStemmer = $options['polish_stemmer'] ?? new WP_FTS_PolishStemmer(
            (string) ($options['polish_stemming'] ?? 'conservative')
        );
        $this->customStemmer = $this->normalize_custom_stemmer($options['stemmer'] ?? null);
        $this->enableStemming = (bool) ($options['enable_stemming'] ?? false);
        $this->namespaceTerms = (bool) ($options['namespace_terms'] ?? false);
        $this->minTermLen = max(1, (int) ($options['min_term_len'] ?? 2));
        $this->maxTermBytes = max(1, (int) ($options['max_term_bytes'] ?? 255));
    }

    /**
     * Analyze text and return only term strings.
     *
     * Use this for legacy consumers that do not need per-term language metadata.
     * New document/search code generally uses `analyze_detailed()`.
     *
     * @param string $text Plain visible text to tokenize.
     * @param string $language Document or query language hint.
     * @return string[]
     */
    public function analyze(string $text, string $language): array
    {
        return array_map(
            static fn(array $term): string => $term['term'],
            $this->analyze_detailed($text, $language)
        );
    }

    /**
     * Analyze text and keep the language attached to every returned term.
     *
     * The language is canonicalized once per call and applied to every token.
     * When `namespace_terms` is enabled, each `term` value is stored as
     * `lang . "\\x1e" . term`; otherwise terms are returned without the
     * namespace and the caller can decide when to namespace them.
     *
     * @param string $text Plain visible text to tokenize.
     * @param string $language Document or query language hint.
     * @return array<int,array{term:string,lang:string}>
     */
    public function analyze_detailed(string $text, string $language): array
    {
        $language = $this->canonicalize_language($language);
        $terms = [];

        foreach ($this->tokenize($text) as $rawToken) {
            $term = $this->normalize_raw_token($rawToken['text'], $language, $rawToken['is_cjk']);
            if ($term === null) {
                continue;
            }

            $terms[] = [
                'term' => $this->namespaceTerms ? $this->namespace_term($language, $term) : $term,
                'lang' => $language,
            ];
        }

        return $terms;
    }

    /**
     * Canonicalize a language tag using the configured normalizer.
     */
    public function canonicalize_language(string $language): string
    {
        return $this->normalizer->canonicalize_language($language);
    }

    /**
     * Return the primary language subtag for a language tag.
     */
    public function base_language(string $language): string
    {
        return $this->normalizer->base_language($language);
    }

    /**
     * Build a namespaced term key using `lang . "\\x1e" . term`.
     *
     * @param string $language Language partition.
     * @param string $term Normalized lexical term.
     * @return string Namespaced storage key.
     */
    public function namespace_term(string $language, string $term): string
    {
        return $this->canonicalize_language($language) . "\x1e" . $term;
    }

    /**
     * Normalize one raw token and apply stemming/length filters.
     *
     * CJK tokens skip stemming and Latin minimum-length pruning because the CJK
     * tokenizer already emits single characters and bigrams as lexical units.
     *
     * @param string $rawToken Token text before case folding and dialect maps.
     * @param string $language Language used for normalization and stemming.
     * @param bool $isCjk True when the token came from a CJK script run.
     * @return string|null Normalized term, or null when filtered by length or
     *         byte-size limits.
     */
    public function normalize_raw_token(string $rawToken, string $language, bool $isCjk = false): ?string
    {
        $language = $this->canonicalize_language($language);
        $term = $this->normalizer->normalize_token($rawToken, $language);

        if ($isCjk) {
            // CJK n-grams are already the lexical units; stemming and Latin
            // minimum-length pruning would damage recall for single characters.
        } elseif ($this->customStemmer !== null) {
            $term = $this->customStemmer->stem($term, $language);
        } elseif ($this->enableStemming) {
            $term = $this->stem_for_language($term, $language);
        }

        $length = function_exists('mb_strlen') ? mb_strlen($term, 'UTF-8') : strlen($term);
        if ((!$isCjk && $length < $this->minTermLen) || strlen($term) > $this->maxTermBytes) {
            return null;
        }

        return $term;
    }

    /**
     * Split text into raw tokens and CJK lexical units.
     *
     * The Unicode path first collects mixed-script word runs, then splits CJK and
     * non-CJK runs so a Latin+CJK+Latin word run does not become one token. When
     * Unicode regex support is unavailable, the fallback keeps ASCII tokens only.
     *
     * @param string $text Plain visible text.
     * @return array<int,array{text:string,is_cjk:bool}>
     */
    private function tokenize(string $text): array
    {
        $matches = [];
        if (@preg_match_all('/[\p{L}\p{M}\p{N}_]+/u', $text, $matches) !== false) {
            $tokens = [];
            foreach ($matches[0] ?? [] as $rawToken) {
                foreach ($this->split_script_runs($rawToken) as $run) {
                    if ($run['is_cjk']) {
                        foreach ($this->cjk_tokens($run['text']) as $cjkToken) {
                            $tokens[] = ['text' => $cjkToken, 'is_cjk' => true];
                        }
                        continue;
                    }

                    $tokens[] = ['text' => $run['text'], 'is_cjk' => false];
                }
            }

            return $tokens;
        }

        $ascii = preg_replace('/[^\x20-\x7E]+/', ' ', $text) ?? '';
        preg_match_all('/[A-Za-z0-9_]+/', $ascii, $matches);

        return array_map(
            static fn(string $token): array => ['text' => $token, 'is_cjk' => false],
            $matches[0] ?? []
        );
    }

    /**
     * Split a token whenever it crosses between CJK and non-CJK scripts.
     *
     * @param string $token Raw token from the Unicode tokenizer.
     * @return array<int,array{text:string,is_cjk:bool}>
     */
    private function split_script_runs(string $token): array
    {
        $runs = [];
        $current = '';
        $currentIsCjk = null;

        foreach ($this->utf8_chars($token) as $char) {
            $isCjk = $this->is_cjk_char($char);
            if ($current !== '' && $isCjk !== $currentIsCjk) {
                $runs[] = ['text' => $current, 'is_cjk' => (bool) $currentIsCjk];
                $current = '';
            }

            $current .= $char;
            $currentIsCjk = $isCjk;
        }

        if ($current !== '') {
            $runs[] = ['text' => $current, 'is_cjk' => (bool) $currentIsCjk];
        }

        return $runs;
    }

    /**
     * Build CJK tokens from a single CJK script run.
     *
     * Single-character runs are kept as-is. Longer runs become overlapping
     * bigrams, which gives query-time matching more context without requiring a
     * dictionary segmenter.
     *
     * @param string $run CJK-only text run.
     * @return string[]
     */
    private function cjk_tokens(string $run): array
    {
        $chars = $this->utf8_chars($run);
        $count = count($chars);
        if ($count <= 1) {
            return $chars;
        }

        $tokens = [];
        for ($i = 0; $i < $count - 1; $i++) {
            $tokens[] = $chars[$i] . $chars[$i + 1];
        }

        return $tokens;
    }

    /**
     * Return UTF-8 characters as individual strings.
     *
     * Invalid UTF-8 yields an empty list so callers can safely drop the bad run.
     *
     * @param string $text UTF-8 text.
     * @return string[]
     */
    private function utf8_chars(string $text): array
    {
        if (!preg_match_all('/./us', $text, $matches)) {
            return [];
        }

        return $matches[0];
    }

    /**
     * Detect scripts handled by the CJK tokenizer path.
     */
    private function is_cjk_char(string $char): bool
    {
        return (bool) preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $char);
    }

    /**
     * Route stemming to the language-specific adapter.
     *
     * Polish uses the conservative local stemmer. Other enabled languages go
     * through the Snowball adapter, which returns the original term when the
     * language is unsupported.
     */
    private function stem_for_language(string $term, string $language): string
    {
        $base = $this->base_language($language);
        if ($base === 'pl') {
            return $this->polishStemmer->stem($term, $language);
        }

        return $this->snowballStemmer->stem($term, $language);
    }

    /**
     * Normalize a caller-supplied stemmer option into a stemmer object.
     *
     * @param mixed $stemmer `WP_FTS_Stemmer`, callable, or null.
     * @return WP_FTS_Stemmer|null Adapter object, or null when no custom stemmer
     *         was supplied.
     */
    private function normalize_custom_stemmer(mixed $stemmer): ?WP_FTS_Stemmer
    {
        if ($stemmer instanceof WP_FTS_Stemmer) {
            return $stemmer;
        }

        if (is_callable($stemmer)) {
            return new WP_FTS_CallbackStemmer($stemmer);
        }

        return null;
    }
}
