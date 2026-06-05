<?php
declare(strict_types=1);

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

    public function canonicalize_language(string $language): string
    {
        return $this->normalizer->canonicalize_language($language);
    }

    public function base_language(string $language): string
    {
        return $this->normalizer->base_language($language);
    }

    public function namespace_term(string $language, string $term): string
    {
        return $this->canonicalize_language($language) . "\x1e" . $term;
    }

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
     * @return string[]
     */
    private function utf8_chars(string $text): array
    {
        if (!preg_match_all('/./us', $text, $matches)) {
            return [];
        }

        return $matches[0];
    }

    private function is_cjk_char(string $char): bool
    {
        return (bool) preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $char);
    }

    private function stem_for_language(string $term, string $language): string
    {
        $base = $this->base_language($language);
        if ($base === 'pl') {
            return $this->polishStemmer->stem($term, $language);
        }

        return $this->snowballStemmer->stem($term, $language);
    }

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
