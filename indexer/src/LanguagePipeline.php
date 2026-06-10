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
    private WP_FTS_Stemmer $polishStemmer;
    private ?WP_FTS_Stemmer $customStemmer;
    /** @var array<string,WP_FTS_Stemmer> */
    private array $customStemmersByLanguage;
    /** @var callable|null */
    private $cjkTokenizer;
    private bool $enableStemming;
    private bool $namespaceTerms;
    private int $minTermLen;
    private int $maxTermBytes;
    private string $indexSignature;

    /**
     * Configure the token analysis pipeline.
     *
     * Use `normalizer`, `snowball_stemmer`, or `polish_stemmer` to inject test
     * doubles. Use `stemmer` for a custom stemmer; callables may accept either
     * `($term)` or `($term, $language)`. Use `stemmers_by_lang` for verified
     * language-specific custom stemmers. `cjk_tokenizer` may return dictionary
     * segments for one CJK run; the built-in bigram path remains the fallback.
     * `namespace_terms` is normally false for the high-level indexer, which
     * namespaces after weighting.
     *
     * @param array{
     *   normalizer?:WP_FTS_Normalizer,
     *   snowball_stemmer?:WP_FTS_SnowballStemmer,
     *   polish_stemmer?:WP_FTS_Stemmer,
     *   polish_lemma_pack?:bool|string|array<string,mixed>|null,
     *   polish_lemmatizer_pack?:bool|string|array<string,mixed>|null,
     *   stemmer?:WP_FTS_Stemmer|callable|null,
     *   stemmers_by_lang?:array<string,WP_FTS_Stemmer|callable|null>,
     *   stemmers?:array<string,WP_FTS_Stemmer|callable|null>,
     *   cjk_tokenizer?:callable|null,
     *   cjk_segmenter?:callable|null,
     *   token_normalizer?:callable|null,
     *   chinese_script_map?:array<string,string>|array<string,array<string,string>>,
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
            'token_normalizer' => $options['token_normalizer'] ?? null,
            'chinese_script_map' => $options['chinese_script_map'] ?? [],
        ]);
        $this->snowballStemmer = $options['snowball_stemmer'] ?? new WP_FTS_SnowballStemmer();
        $configuredPolishStemmer = $options['polish_stemmer'] ?? null;
        $this->polishStemmer = $configuredPolishStemmer instanceof WP_FTS_Stemmer
            ? $configuredPolishStemmer
            : $this->default_polish_stemmer($options);
        $this->customStemmer = $this->normalize_custom_stemmer($options['stemmer'] ?? null);
        $this->customStemmersByLanguage = $this->normalize_custom_stemmers_by_language(
            $options['stemmers_by_lang'] ?? $options['stemmers'] ?? []
        );
        $tokenizer = $options['cjk_tokenizer'] ?? $options['cjk_segmenter'] ?? null;
        $this->cjkTokenizer = is_callable($tokenizer) ? $tokenizer : null;
        $this->enableStemming = (bool) ($options['enable_stemming'] ?? true);
        $this->namespaceTerms = (bool) ($options['namespace_terms'] ?? false);
        $this->minTermLen = max(1, (int) ($options['min_term_len'] ?? 2));
        $this->maxTermBytes = max(1, (int) ($options['max_term_bytes'] ?? 255));
        $this->indexSignature = $this->buildIndexSignature($options, $tokenizer);
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

        foreach ($this->tokenize($text, $language) as $rawToken) {
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
     * Return the language-pipeline behavior signature for stale-document checks.
     */
    public function index_signature(): string
    {
        return $this->indexSignature;
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
        } else {
            $customStemmer = $this->custom_stemmer_for_language($language);
            if ($customStemmer !== null) {
                $term = $customStemmer->stem($term, $language);
            } elseif ($this->customStemmer !== null) {
                $term = $this->customStemmer->stem($term, $language);
            } elseif ($this->enableStemming) {
                $term = $this->stem_for_language($term, $language);
            }
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
    private function tokenize(string $text, string $language): array
    {
        $matches = [];
        if (@preg_match_all('/[\p{L}\p{M}\p{N}_]+/u', $text, $matches) !== false) {
            $tokens = [];
            foreach ($matches[0] ?? [] as $rawToken) {
                foreach ($this->split_script_runs($rawToken) as $run) {
                    if ($run['is_cjk']) {
                        foreach ($this->cjk_tokens($run['text'], $language) as $cjkToken) {
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
    private function cjk_tokens(string $run, string $language): array
    {
        if ($this->cjkTokenizer !== null) {
            try {
                $tokens = ($this->cjkTokenizer)($run, $this->canonicalize_language($language));
                $tokens = $this->normalize_tokenizer_result($tokens);
                if ($tokens !== []) {
                    return $tokens;
                }
            } catch (Throwable) {
                // Segmenters are optional extension points; fall through to the
                // deterministic built-in bigram tokenizer on failures.
            }
        }

        return $this->fallback_cjk_tokens($run);
    }

    /**
     * Normalize custom CJK tokenizer output to non-empty token strings.
     *
     * @param mixed $tokens User segmenter result.
     * @return string[]
     */
    private function normalize_tokenizer_result(mixed $tokens): array
    {
        if (!is_iterable($tokens)) {
            return [];
        }

        $normalized = [];
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $token = $token['text'] ?? $token['term'] ?? null;
            }
            if (!is_scalar($token)) {
                continue;
            }

            $token = trim((string) $token);
            if ($token !== '') {
                $normalized[] = $token;
            }
        }

        return $normalized;
    }

    /**
     * Built-in CJK fallback tokenizer using overlapping bigrams.
     *
     * @param string $run CJK-only text run.
     * @return string[]
     */
    private function fallback_cjk_tokens(string $run): array
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

    /**
     * Normalize a language-to-stemmer map.
     *
     * @param mixed $stemmers
     * @return array<string,WP_FTS_Stemmer>
     */
    private function normalize_custom_stemmers_by_language(mixed $stemmers): array
    {
        if (!is_array($stemmers)) {
            return [];
        }

        $normalized = [];
        foreach ($stemmers as $language => $stemmer) {
            $canonicalLanguage = $this->canonicalize_language((string) $language);
            $stemmer = $this->normalize_custom_stemmer($stemmer);
            if ($stemmer !== null) {
                $normalized[$canonicalLanguage] = $stemmer;
            }
        }

        return $normalized;
    }

    /**
     * Return a custom stemmer for a full language tag or its base language.
     */
    private function custom_stemmer_for_language(string $language): ?WP_FTS_Stemmer
    {
        $language = $this->canonicalize_language($language);

        return $this->customStemmersByLanguage[$language]
            ?? $this->customStemmersByLanguage[$this->base_language($language)]
            ?? null;
    }

    /**
     * Build a stable signature for tokenization, normalization, and stemming.
     *
     * @param array<string,mixed> $options Constructor options.
     */
    private function buildIndexSignature(array $options, mixed $tokenizer): string
    {
        $polishMode = strtolower(trim((string) ($options['polish_stemming'] ?? 'conservative')));
        if (!in_array($polishMode, ['conservative', 'verified', 'none'], true)) {
            $polishMode = 'conservative';
        }
        $payload = [
            'contract' => 'wp-fts-language-pipeline',
            'version' => 2,
            'min_term_len' => $this->minTermLen,
            'max_term_bytes' => $this->maxTermBytes,
            'fold_diacritics' => (bool) ($options['fold_diacritics'] ?? true),
            'namespace_terms' => $this->namespaceTerms,
            'enable_stemming' => $this->enableStemming,
            'polish_stemming' => $polishMode,
            'stemmer' => $this->componentSignature($options['stemmer'] ?? null),
            'stemmers_by_lang' => $this->stemmersByLanguageSignature($options['stemmers_by_lang'] ?? $options['stemmers'] ?? []),
            'cjk_tokenizer' => $this->componentSignature($tokenizer),
            'token_normalizer' => $this->componentSignature($options['token_normalizer'] ?? null),
            'chinese_script_map' => $this->signatureValue($options['chinese_script_map'] ?? []),
            'normalizer' => $this->componentSignature($options['normalizer'] ?? null),
            'snowball_stemmer' => $this->componentSignature($options['snowball_stemmer'] ?? null),
            'polish_stemmer' => $this->componentSignature($options['polish_stemmer'] ?? null),
        ];
        $polishLemmaPackSignature = $this->polishLemmaPackSignature($options);
        if ($polishLemmaPackSignature !== null) {
            $payload['polish_lemma_pack'] = $polishLemmaPackSignature;
        }

        if ($polishMode === 'verified') {
            $payload['polish_verified_stemmer'] = WP_FTS_PolishVerifiedStemmerData::VERSION;
        }

        return 'wp-fts-language-pipeline-v2:' . sha1($this->stableJson($payload));
    }

    /**
     * Build the default Polish stemmer, optionally using a validated lemma pack.
     *
     * Invalid or missing opt-in packs return the conservative suffix stemmer so
     * runtime indexing remains available when resources are absent.
     *
     * @param array<string,mixed> $options
     */
    private function default_polish_stemmer(array $options): WP_FTS_Stemmer
    {
        $packOption = $options['polish_lemma_pack'] ?? $options['polish_lemmatizer_pack'] ?? false;
        $lemmaPack = WP_FTS_PolishMorfologikLemmatizer::from_pack_option($packOption);
        if ($lemmaPack !== null) {
            return $lemmaPack;
        }

        return new WP_FTS_PolishStemmer((string) ($options['polish_stemming'] ?? 'conservative'));
    }

    /**
     * Return the enabled Polish lemma pack signature, if a valid pack is active.
     *
     * @param array<string,mixed> $options
     */
    private function polishLemmaPackSignature(array $options): ?string
    {
        if (!array_key_exists('polish_lemma_pack', $options) && !array_key_exists('polish_lemmatizer_pack', $options)) {
            return null;
        }

        if ($this->polishStemmer instanceof WP_FTS_PolishMorfologikLemmatizer) {
            return $this->polishStemmer->index_signature();
        }

        return null;
    }

    /**
     * @param mixed $stemmers
     * @return array<string,mixed>
     */
    private function stemmersByLanguageSignature(mixed $stemmers): array
    {
        if (!is_array($stemmers)) {
            return [];
        }

        $result = [];
        foreach ($stemmers as $language => $stemmer) {
            $lang = $this->canonicalize_language((string) $language);
            if ($lang === 'und') {
                continue;
            }

            $result[$lang] = $this->componentSignature($stemmer);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Return a deterministic descriptor for a signature component.
     */
    private function componentSignature(mixed $component): mixed
    {
        if ($component === null || $component === false) {
            return null;
        }

        if (is_callable($component)) {
            return $this->callableSignature($component);
        }

        if (is_object($component)) {
            if (is_callable([$component, 'index_signature'])) {
                try {
                    $signature = $component->index_signature();
                    if (is_scalar($signature) && trim((string) $signature) !== '') {
                        return (string) $signature;
                    }
                } catch (Throwable) {
                    // Fall through to the class-level descriptor.
                }
            }

            return 'object:' . get_debug_type($component);
        }

        return $this->signatureValue($component);
    }

    /**
     * Return a deterministic descriptor for a callback.
     */
    private function callableSignature(callable $callback): string
    {
        try {
            if (is_string($callback)) {
                return 'function:' . strtolower($callback);
            }

            if (is_array($callback) && count($callback) === 2) {
                $target = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];
                return 'method:' . $target . '::' . (string) $callback[1];
            }

            if ($callback instanceof Closure) {
                $reflection = new ReflectionFunction($callback);
                return sprintf(
                    'closure:%s:%d-%d',
                    $reflection->getFileName() ?: 'internal',
                    $reflection->getStartLine(),
                    $reflection->getEndLine()
                );
            }

            if (is_object($callback)) {
                return 'invokable:' . get_class($callback);
            }
        } catch (Throwable) {
            return 'callable:' . get_debug_type($callback);
        }

        return 'callable:' . get_debug_type($callback);
    }

    /**
     * Normalize arrays and scalar values before JSON encoding a signature.
     */
    private function signatureValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_callable($value)) {
            return $this->callableSignature($value);
        }

        if (is_object($value)) {
            return 'object:' . get_debug_type($value);
        }

        if (!is_array($value)) {
            return get_debug_type($value);
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = $this->signatureValue($item);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Encode a sanitized signature payload.
     */
    private function stableJson(mixed $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return serialize($payload);
        }
    }
}
