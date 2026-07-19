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
    private const CJK_MAX_NGRAM_LENGTH = 4;
    private const MAX_CACHED_ANALYSES = 512;
    private const MAX_CACHED_LANGUAGE_BYTES = 64;
    private const MAX_CACHED_RAW_TOKEN_BYTES = 255;

    private WP_FTS_Normalizer $normalizer;
    private WP_FTS_SnowballStemmer $snowballStemmer;
    private WP_FTS_BaselineLanguageStemmer $baselineStemmer;
    private WP_FTS_Stemmer $polishStemmer;
    private ?WP_FTS_Stemmer $customStemmer;
    /** @var array<string,WP_FTS_Stemmer> */
    private array $customStemmersByLanguage;
    /** @var array<string,WP_FTS_LanguageLemmaPack> */
    private array $lemmaPacksByLanguage;
    /** @var callable|null */
    private $cjkTokenizer;
    private bool $enableStemming;
    private bool $namespaceTerms;
    private int $minTermLen;
    private int $maxTermBytes;
    private string $indexSignature;
    /** @var array<string,array<int,array{term:string,rank:int,source:string}>> */
    private array $analysisCache = [];

    /**
     * Configure the token analysis pipeline.
     *
     * Use `normalizer`, `snowball_stemmer`, or `polish_stemmer` to inject test
     * doubles. Use `stemmer` for a custom stemmer; callables may accept either
     * `($term)` or `($term, $language)`. Use `stemmers_by_lang` for verified
     * language-specific custom stemmers. `cjk_tokenizer` may return dictionary
     * segments for one CJK run; the built-in n-gram path remains the fallback.
     * `namespace_terms` is normally false for the high-level indexer, which
     * namespaces after weighting.
     *
     * @param array{
     *   normalizer?:WP_FTS_Normalizer,
     *   snowball_stemmer?:WP_FTS_SnowballStemmer,
     *   baseline_stemmer?:WP_FTS_BaselineLanguageStemmer,
     *   polish_stemmer?:WP_FTS_Stemmer,
     *   polish_lemma_pack?:bool|string|array<string,mixed>|null,
     *   polish_lemmatizer_pack?:bool|string|array<string,mixed>|null,
     *   lemma_packs_by_lang?:array<string,bool|string|array<string,mixed>|null>,
     *   lemmatizer_packs_by_lang?:array<string,bool|string|array<string,mixed>|null>,
     *   stemmer?:WP_FTS_Stemmer|callable|null,
     *   stemmers_by_lang?:array<string,WP_FTS_Stemmer|callable|null>,
     *   stemmers?:array<string,WP_FTS_Stemmer|callable|null>,
     *   cjk_tokenizer?:callable|null,
     *   cjk_segmenter?:callable|null,
     *   segmenter_packs_by_lang?:array<string,bool|string|array<string,mixed>|null>,
     *   cjk_segmenter_packs_by_lang?:array<string,bool|string|array<string,mixed>|null>,
     *   cjk_tokenizer_packs_by_lang?:array<string,bool|string|array<string,mixed>|null>,
     *   tokenizer_packs_by_lang?:array<string,bool|string|array<string,mixed>|null>,
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
        WP_FTS_Analyzer_Config_Limits::assert_analyzer_options($options, 'Language pipeline options');

        $this->normalizer = $options['normalizer'] ?? new WP_FTS_Normalizer([
            'fold_diacritics' => (bool) ($options['fold_diacritics'] ?? true),
            'token_normalizer' => $options['token_normalizer'] ?? null,
            'chinese_script_map' => $options['chinese_script_map'] ?? [],
        ]);
        $this->snowballStemmer = $options['snowball_stemmer'] ?? new WP_FTS_SnowballStemmer();
        $this->baselineStemmer = $options['baseline_stemmer'] ?? new WP_FTS_BaselineLanguageStemmer();
        $configuredPolishStemmer = $options['polish_stemmer'] ?? null;
        $this->polishStemmer = $configuredPolishStemmer instanceof WP_FTS_Stemmer
            ? $configuredPolishStemmer
            : $this->default_polish_stemmer($options);
        $this->customStemmer = $this->normalize_custom_stemmer($options['stemmer'] ?? null);
        $this->customStemmersByLanguage = $this->normalize_custom_stemmers_by_language(
            $options['stemmers_by_lang'] ?? $options['stemmers'] ?? []
        );
        $this->lemmaPacksByLanguage = $this->normalize_lemma_packs_by_language(
            $this->lemma_pack_options_by_language($options)
        );
        $tokenizer = $options['cjk_tokenizer'] ?? $options['cjk_segmenter'] ?? null;
        if (!is_callable($tokenizer)) {
            $tokenizer = $this->segmenter_pack_tokenizer_for_options($options);
        }
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
     * @return array<int,array{term:string,lang:string,position?:int,rank?:int,source?:string,surface?:string,normalized_surface?:string}>
     */
    public function analyze_detailed(
        string $text,
        string $language,
        bool $includeSurface = false,
        ?int $maxTerms = null
    ): array
    {
        $language = $this->canonicalize_language($language);
        $terms = [];
        $maxTerms = $maxTerms === null
            ? WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES
            : max(0, min(WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES, $maxTerms));

        foreach ($this->tokenize($text, $language, $maxTerms) as $rawToken) {
            $normalizedSurface = $includeSurface
                ? $this->normalizer->normalize_token($rawToken['text'], $language)
                : '';
            $analyses = $this->analyze_raw_token($rawToken['text'], $language, $rawToken['is_cjk']);
            if ($analyses === []) {
                // A lexical run can be wider than the exact dictionary key but
                // still has representable prefixes. Preserve one surface-only
                // occurrence so indexing does not silently lose that prefix
                // capability merely because no stemmer shortened the token.
                if ($normalizedSurface !== '') {
                    if (count($terms) >= $maxTerms) {
                        throw new WP_FTS_Analysis_Limit_Exceeded(
                            'occurrences',
                            "FTS analysis exceeds its {$maxTerms}-occurrence limit."
                        );
                    }
                    $terms[] = [
                        'term' => '',
                        'lang' => $language,
                        'surface' => $rawToken['text'],
                        'normalized_surface' => $normalizedSurface,
                    ];
                }
                continue;
            }

            $position = count($terms);
            $isMultiAnalysis = count($analyses) > 1;
            foreach ($analyses as $analysis) {
                if (count($terms) >= $maxTerms) {
                    throw new WP_FTS_Analysis_Limit_Exceeded(
                        'occurrences',
                        "FTS analysis exceeds its {$maxTerms}-occurrence limit."
                    );
                }
                $term = (string) $analysis['term'];
                $row = [
                    'term' => $this->namespaceTerms ? $this->namespace_term($language, $term) : $term,
                    'lang' => $language,
                ];
                if ($includeSurface) {
                    $row['surface'] = $rawToken['text'];
                    // Prefix search expands what the visitor typed, not an
                    // arbitrary lemma emitted for that token. Keep the raw
                    // surface for explain output and carry its storage-normalized
                    // identity separately for relational prefix materialization.
                    $row['normalized_surface'] = $normalizedSurface;
                }
                if ($isMultiAnalysis) {
                    $row['position'] = $position;
                    $row['rank'] = (int) ($analysis['rank'] ?? 0);
                    $row['source'] = (string) ($analysis['source'] ?? 'analyzer');
                }
                $terms[] = $row;
            }
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
     * Normalize arbitrary source text before lexical boundaries are selected.
     */
    public function normalize_unicode_text(string $text): string
    {
        return $this->normalizer->normalize_unicode($text);
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
     * tokenizer already emits single characters and bounded n-grams as lexical
     * units.
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
        // A stemmer is allowed to rewrite one bounded lexical run, not amplify
        // it into unbounded text. Check bytes before character-length work.
        WP_FTS_Analysis_Limits::assert_lexical_run_bytes(strlen($term));

        if (!$this->term_passes_length_filters($term, $isCjk)) {
            return null;
        }

        return $term;
    }

    /**
     * Normalize one raw token and return all valid analysis candidates.
     *
     * This mirrors `normalize_raw_token()` for legacy callers, but lets
     * dictionary lemma packs return multiple pack-backed candidates for one
     * source token. Custom stemmers and non-pack language paths remain
     * single-analysis.
     *
     * @return array<int,array{term:string,rank:int,source:string}>
     */
    private function analyze_raw_token(string $rawToken, string $language, bool $isCjk = false): array
    {
        $language = $this->canonicalize_language($language);
        $cacheKey = strlen($language) <= self::MAX_CACHED_LANGUAGE_BYTES
            && strlen($rawToken) <= self::MAX_CACHED_RAW_TOKEN_BYTES
            ? $language . "\0" . ($isCjk ? '1' : '0') . "\0" . $rawToken
            : null;
        if ($cacheKey !== null && array_key_exists($cacheKey, $this->analysisCache)) {
            return $this->analysisCache[$cacheKey];
        }

        $term = $this->normalizer->normalize_token($rawToken, $language);
        $analyses = null;

        if ($isCjk) {
            $analyses = [['term' => $term, 'rank' => 0, 'source' => 'normalized']];
        } else {
            $customStemmer = $this->custom_stemmer_for_language($language);
            if ($customStemmer !== null) {
                $analyses = [['term' => $customStemmer->stem($term, $language), 'rank' => 0, 'source' => 'stemmer']];
            } elseif ($this->customStemmer !== null) {
                $analyses = [['term' => $this->customStemmer->stem($term, $language), 'rank' => 0, 'source' => 'stemmer']];
            } elseif ($this->enableStemming) {
                $lemmaPack = $this->lemma_pack_for_language($language);
                if ($lemmaPack !== null) {
                    $analyses = $lemmaPack->analyze($term, $language);
                    if (count($analyses) > 1 && !$this->term_meets_min_length($term, $isCjk)) {
                        return $this->cache_analysis($cacheKey, []);
                    }
                } else {
                    $analyses = [['term' => $this->stem_for_language($term, $language), 'rank' => 0, 'source' => 'stemmer']];
                }
            }
        }

        $analyses ??= [['term' => $term, 'rank' => 0, 'source' => 'normalized']];

        $valid = [];
        $seen = [];
        foreach ($analyses as $analysis) {
            $candidate = $analysis['term'] ?? '';
            if (!is_scalar($candidate)) {
                continue;
            }
            $candidate = (string) $candidate;
            WP_FTS_Analysis_Limits::assert_lexical_run_bytes(strlen($candidate));
            $candidate = trim($candidate);
            if ($candidate === '' || isset($seen[$candidate]) || !$this->term_passes_length_filters($candidate, $isCjk)) {
                continue;
            }

            $seen[$candidate] = true;
            $valid[] = [
                'term' => $candidate,
                'rank' => max(0, (int) ($analysis['rank'] ?? 0)),
                'source' => (string) ($analysis['source'] ?? 'analyzer'),
            ];
        }

        return $this->cache_analysis($cacheKey, $valid);
    }

    /**
     * Cache deterministic token analysis within one analyzer request.
     *
     * Documents commonly repeat the same words thousands of times. Re-running
     * Unicode normalization, stemming, and dictionary lookup for every
     * occurrence adds work without changing the result. The entry, language,
     * and raw-token limits keep this request-local optimization bounded for
     * hostile input; insertion order supplies FIFO eviction without a second
     * growing queue.
     *
     * @param array<int,array{term:string,rank:int,source:string}> $analysis
     * @return array<int,array{term:string,rank:int,source:string}>
     */
    private function cache_analysis(?string $cacheKey, array $analysis): array
    {
        if ($cacheKey === null) {
            return $analysis;
        }

        while (count($this->analysisCache) >= self::MAX_CACHED_ANALYSES) {
            $oldest = array_key_first($this->analysisCache);
            if (!is_string($oldest)) {
                break;
            }
            unset($this->analysisCache[$oldest]);
        }
        $this->analysisCache[$cacheKey] = $analysis;

        return $analysis;
    }

    /**
     * Apply the shared post-analysis length and byte filters.
     */
    private function term_passes_length_filters(string $term, bool $isCjk): bool
    {
        return strlen($term) <= $this->maxTermBytes && $this->term_meets_min_length($term, $isCjk);
    }

    /**
     * Check the character-length side of the shared post-analysis filters.
     */
    private function term_meets_min_length(string $term, bool $isCjk): bool
    {
        return $isCjk || WP_FTS_Utf8::length($term) >= $this->minTermLen;
    }

    /**
     * Split text into raw tokens and CJK lexical units.
     *
     * The Unicode path first collects mixed-script word runs, then splits CJK and
     * non-CJK runs so a Latin+CJK+Latin word run does not become one token. When
     * Unicode regex support is unavailable, the fallback keeps ASCII tokens only.
     *
     * @param string $text Plain visible text.
     * @return iterable<int,array{text:string,is_cjk:bool}>
     */
    private function tokenize(string $text, string $language, int $maxTerms): iterable
    {
        $text = $this->normalizer->normalize_unicode($text);
        $offset = 0;
        $length = strlen($text);
        $inspectedTokenizerYields = 0;
        if (preg_match('//u', $text) === 1) {
            while ($offset < $length) {
                $matched = preg_match(
                    '/[\p{L}\p{M}\p{N}_]+/u',
                    $text,
                    $match,
                    PREG_OFFSET_CAPTURE,
                    $offset
                );
                if ($matched !== 1) {
                    return;
                }

                $rawToken = (string) $match[0][0];
                $offset = (int) $match[0][1] + strlen($rawToken);
                if ($rawToken !== '') {
                    foreach ($this->split_script_runs($rawToken) as $run) {
                        if ($run['is_cjk']) {
                            foreach ($this->cjk_tokens(
                                $run['text'],
                                $language,
                                $maxTerms,
                                $inspectedTokenizerYields
                            ) as $cjkToken) {
                                yield ['text' => $cjkToken, 'is_cjk' => true];
                            }
                            continue;
                        }

                        yield ['text' => $run['text'], 'is_cjk' => false];
                    }
                }
            }
            return;
        }

        // Invalid UTF-8 cannot use the Unicode expression. Scan only ASCII
        // lexical runs without first copying the whole source or collecting
        // every match into an array.
        $offset = 0;
        while ($offset < $length && preg_match('/[A-Za-z0-9_]+/', $text, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $token = (string) $match[0][0];
            $offset = (int) $match[0][1] + strlen($token);
            WP_FTS_Analysis_Limits::assert_lexical_run_bytes(strlen($token));
            yield ['text' => $token, 'is_cjk' => false];
        }
    }

    /**
     * Split a token whenever it crosses between CJK and non-CJK scripts.
     *
     * @param string $token Raw token from the Unicode tokenizer.
     * @return iterable<int,array{text:string,is_cjk:bool}>
     */
    private function split_script_runs(string $token): iterable
    {
        $offset = 0;
        $length = strlen($token);
        while ($offset < $length) {
            $matched = preg_match(
                '/(?:[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]+|[^\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]+)/uA',
                $token,
                $match,
                PREG_OFFSET_CAPTURE,
                $offset
            );
            if ($matched !== 1) {
                return;
            }

            $run = (string) $match[0][0];
            $offset += strlen($run);
            $isCjk = $this->is_cjk_char($run);
            // Apply the same pre-normalization envelope to every script. In
            // particular, a CJK extension tokenizer must not receive a 2-MiB
            // run and allocate its complete character/candidate product before
            // the downstream occurrence limit can inspect its first result.
            WP_FTS_Analysis_Limits::assert_lexical_run_bytes(strlen($run));
            yield ['text' => $run, 'is_cjk' => $isCjk];
        }
    }

    /**
     * Build CJK tokens from a single CJK script run.
     *
     * Single-character runs are kept as-is. Longer runs emit character
     * unigrams plus overlapping n-grams up to a small bounded length, which
     * gives query-time matching more specific fallback evidence without
     * requiring a dictionary segmenter.
     *
     * @param string $run CJK-only text run.
     * @return iterable<int,string>
     */
    private function cjk_tokens(
        string $run,
        string $language,
        int $maxTerms,
        int &$inspectedTokenizerYields
    ): iterable
    {
        if ($this->cjkTokenizer !== null) {
            try {
                $tokens = ($this->cjkTokenizer)($run, $this->canonicalize_language($language));
                $emitted = false;
                foreach ($this->normalize_tokenizer_result(
                    $tokens,
                    $maxTerms,
                    $inspectedTokenizerYields
                ) as $token) {
                    WP_FTS_Analysis_Limits::assert_lexical_run_bytes(strlen($token));
                    $emitted = true;
                    yield $token;
                }
                if ($emitted) {
                    return;
                }
            } catch (WP_FTS_Analysis_Limit_Exceeded $error) {
                throw $error;
            } catch (Throwable) {
                // Segmenters are optional extension points; fall through to the
                // deterministic built-in n-gram tokenizer on failures.
            }
        }

        yield from $this->fallback_cjk_tokens($run);
    }

    /**
     * Normalize custom CJK tokenizer output to non-empty token strings.
     *
     * @param mixed $tokens User segmenter result.
     * @return iterable<int,string>
     */
    private function normalize_tokenizer_result(
        mixed $tokens,
        int $maxTerms,
        int &$inspectedTokenizerYields
    ): iterable
    {
        if (!is_iterable($tokens)) {
            return;
        }

        foreach ($tokens as $token) {
            // Count source yields before validating their shape. Counting only
            // accepted strings lets an infinite extension generator evade the
            // analyzer occurrence limit forever by yielding null, empty text,
            // or arrays without a text/term field.
            if (++$inspectedTokenizerYields > $maxTerms) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'occurrences',
                    "FTS analysis exceeds its {$maxTerms}-occurrence limit."
                );
            }
            if (is_array($token)) {
                $token = $token['text'] ?? $token['term'] ?? null;
            }
            if (!is_scalar($token)) {
                continue;
            }

            $token = (string) $token;
            WP_FTS_Analysis_Limits::assert_lexical_run_bytes(strlen($token));
            $token = trim($token);
            if ($token !== '') {
                yield $token;
            }
        }
    }

    /**
     * Built-in CJK fallback tokenizer using overlapping n-grams up to a bounded
     * max length.
     *
     * @param string $run CJK-only text run.
     * @return iterable<int,string>
     */
    private function fallback_cjk_tokens(string $run): iterable
    {
        $window = [];
        foreach ($this->utf8_char_stream($run) as $char) {
            $window[] = $char;
            if (count($window) > self::CJK_MAX_NGRAM_LENGTH) {
                array_shift($window);
            }

            // Emit every suffix ending at this code point. The set and term
            // frequencies are identical to the former length-major loops, but
            // memory is now a four-code-point rolling window.
            $windowCount = count($window);
            for ($length = 1; $length <= $windowCount; $length++) {
                yield implode('', array_slice($window, $windowCount - $length));
            }
        }
    }

    /**
     * Stream UTF-8 characters as individual strings.
     *
     * Invalid UTF-8 ends the stream so callers can safely drop the bad suffix.
     *
     * @param string $text UTF-8 text.
     * @return iterable<int,string>
     */
    private function utf8_char_stream(string $text): iterable
    {
        $offset = 0;
        $length = strlen($text);
        while ($offset < $length) {
            if (preg_match('/./usA', $text, $match, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                return;
            }

            $char = (string) $match[0][0];
            $offset += strlen($char);
            yield $char;
        }
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
     * Polish keeps its analyzer-pack/conservative precedence. Bengali and Urdu
     * use the local baseline stemmer. Arabic, English, Spanish, French, Hindi,
     * Portuguese, and Indonesian route through bundled Snowball adapters. Other
     * enabled languages go through the Snowball adapter, which returns the
     * original term when the language is unsupported.
     */
    private function stem_for_language(string $term, string $language): string
    {
        $base = $this->base_language($language);
        $lemmaPack = $this->lemma_pack_for_language($language);
        if ($lemmaPack !== null) {
            return $lemmaPack->stem($term, $language);
        }

        if ($base === 'pl') {
            return $this->polishStemmer->stem($term, $language);
        }

        if (in_array($base, ['bn', 'ur'], true)) {
            return $this->baselineStemmer->stem($term, $language);
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
     * Merge generic lemma-pack option aliases. `lemma_packs_by_lang` wins when
     * both aliases provide the same language key.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function lemma_pack_options_by_language(array $options): array
    {
        $maps = [];
        if (isset($options['lemmatizer_packs_by_lang']) && is_array($options['lemmatizer_packs_by_lang'])) {
            $maps[] = $options['lemmatizer_packs_by_lang'];
        }
        if (isset($options['lemma_packs_by_lang']) && is_array($options['lemma_packs_by_lang'])) {
            $maps[] = $options['lemma_packs_by_lang'];
        }
        $packs = WP_FTS_Analyzer_Config_Limits::merge_language_maps($maps, 'Language pipeline lemma packs');
        if (
            !array_key_exists('pl', $packs)
            && (array_key_exists('polish_lemma_pack', $options) || array_key_exists('polish_lemmatizer_pack', $options))
        ) {
            $packs['pl'] = $options['polish_lemma_pack'] ?? $options['polish_lemmatizer_pack'] ?? false;
            WP_FTS_Analyzer_Config_Limits::assert_language_map($packs, 'Language pipeline lemma packs');
        }

        return $packs;
    }

    /**
     * Normalize a language-to-lemma-pack map.
     *
     * @param mixed $packs
     * @return array<string,WP_FTS_LanguageLemmaPack>
     */
    private function normalize_lemma_packs_by_language(mixed $packs): array
    {
        if (!is_array($packs)) {
            return [];
        }

        $normalized = [];
        $runtimeFiles = 0;
        $lookupBlocks = 0;
        foreach ($packs as $language => $option) {
            $canonicalLanguage = $this->canonicalize_language((string) $language);
            if ($canonicalLanguage === 'und') {
                continue;
            }

            $defaultManifest = $this->base_language($canonicalLanguage) === 'pl'
                ? WP_FTS_AnalyzerPackValidator::default_polish_fixture_manifest()
                : null;
            $pack = WP_FTS_LanguageLemmaPack::from_pack_option($option, $canonicalLanguage, $defaultManifest);
            if ($pack !== null) {
                $runtimeFiles += $pack->runtime_file_count();
                $lookupBlocks += $pack->lookup_block_count();
                if ($runtimeFiles > WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_RUNTIME_FILES
                    || $lookupBlocks > WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_LOOKUP_BLOCKS
                ) {
                    throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                        'configured_pack_metadata',
                        'Configured lemma packs exceed the 128-file or 4,096-block metadata limit.'
                    );
                }
                $normalized[$canonicalLanguage] = $pack;
            }
        }
        ksort($normalized, SORT_STRING);

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
     * Return an enabled lemma pack for a full language tag or its base language.
     */
    private function lemma_pack_for_language(string $language): ?WP_FTS_LanguageLemmaPack
    {
        $language = $this->canonicalize_language($language);

        return $this->lemmaPacksByLanguage[$language]
            ?? $this->lemmaPacksByLanguage[$this->base_language($language)]
            ?? null;
    }

    /**
     * Build a CJK tokenizer from local source-backed segmenter pack options.
     *
     * @param array<string,mixed> $options
     */
    private function segmenter_pack_tokenizer_for_options(array $options): ?callable
    {
        $packs = $this->segmenter_pack_options_by_language($options);
        if ($packs === []) {
            return null;
        }

        $segmenters = [];
        $configuredBaseLanguages = [];
        foreach ($packs as $language => $option) {
            $canonicalLanguage = $this->canonicalize_language((string) $language);
            if ($canonicalLanguage === 'und') {
                continue;
            }
            $baseLanguage = $this->base_language($canonicalLanguage);
            if (isset($configuredBaseLanguages[$baseLanguage])) {
                throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                    'duplicate_segmenter_language',
                    'Only one segmenter pack may be configured per base language.'
                );
            }
            $configuredBaseLanguages[$baseLanguage] = true;

            $segmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option($option, $canonicalLanguage);
            if ($segmenter !== null) {
                $segmenters[$baseLanguage] = $segmenter;
            }
        }

        if ($segmenters === []) {
            return null;
        }
        if (count($segmenters) === 1) {
            return reset($segmenters) ?: null;
        }

        ksort($segmenters, SORT_STRING);

        return function (string $run, string $language) use ($segmenters): array {
            $base = $this->base_language($language);
            $segmenter = $segmenters[$base] ?? null;

            return $segmenter instanceof WP_FTS_ChineseJiebaSegmenter ? $segmenter($run, $language) : [];
        };
    }

    /**
     * Merge segmenter-pack option aliases. `segmenter_packs_by_lang` wins when
     * aliases provide the same language key.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function segmenter_pack_options_by_language(array $options): array
    {
        $maps = [];
        foreach (['tokenizer_packs_by_lang', 'cjk_tokenizer_packs_by_lang', 'cjk_segmenter_packs_by_lang', 'segmenter_packs_by_lang'] as $key) {
            if (isset($options[$key]) && is_array($options[$key])) {
                $maps[] = $options[$key];
            }
        }

        return WP_FTS_Analyzer_Config_Limits::merge_language_maps($maps, 'Language pipeline segmenter packs');
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
            'version' => 20,
            'cjk_max_ngram_length' => self::CJK_MAX_NGRAM_LENGTH,
            'min_term_len' => $this->minTermLen,
            'max_term_bytes' => $this->maxTermBytes,
            'fold_diacritics' => (bool) ($options['fold_diacritics'] ?? true),
            'unicode_normalizer' => $this->normalizer->index_signature(),
            'namespace_terms' => $this->namespaceTerms,
            'enable_stemming' => $this->enableStemming,
            'polish_stemming' => $polishMode,
            'stemmer' => $this->componentSignature($options['stemmer'] ?? null),
            'stemmers_by_lang' => $this->stemmersByLanguageSignature($options['stemmers_by_lang'] ?? $options['stemmers'] ?? []),
            'cjk_tokenizer' => $this->componentSignature($tokenizer),
            'token_normalizer' => $this->componentSignature($options['token_normalizer'] ?? null),
            'chinese_script_map' => $this->signatureValue($options['chinese_script_map'] ?? []),
            'normalizer' => $this->componentSignature($options['normalizer'] ?? null),
            'snowball_stemmer' => $this->componentSignature($options['snowball_stemmer'] ?? $this->snowballStemmer),
            'baseline_stemmer' => $this->componentSignature($options['baseline_stemmer'] ?? $this->baselineStemmer),
            'polish_stemmer' => $this->componentSignature($options['polish_stemmer'] ?? null),
        ];
        $lemmaPackSignatures = $this->lemmaPacksByLanguageSignature();
        if ($lemmaPackSignatures !== []) {
            $payload['lemma_packs_by_lang'] = $lemmaPackSignatures;
        }
        if ($polishMode === 'verified') {
            $payload['polish_verified_stemmer'] = WP_FTS_PolishVerifiedStemmerData::VERSION;
        }

        return 'wp-fts-language-pipeline-v20:' . sha1($this->stableJson($payload));
    }

    /**
     * Build the default Polish stemmer.
     *
     * Lemma packs are resolved through the generic language-pack map so the
     * selected Polish mode remains the fallback for invalid or missing packs.
     *
     * @param array<string,mixed> $options
     */
    private function default_polish_stemmer(array $options): WP_FTS_Stemmer
    {
        return new WP_FTS_PolishStemmer((string) ($options['polish_stemming'] ?? 'conservative'));
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
     * @return array<string,string>
     */
    private function lemmaPacksByLanguageSignature(): array
    {
        $result = [];
        foreach ($this->lemmaPacksByLanguage as $language => $pack) {
            $result[$language] = $pack->index_signature();
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

        if (is_object($component)) {
            $signature = $this->explicitObjectSignature($component);
            if ($signature !== null) {
                return $signature;
            }
        }

        if (is_callable($component)) {
            return $this->callableSignature($component);
        }

        if (is_object($component)) {
            return 'object:' . get_debug_type($component);
        }

        return $this->signatureValue($component);
    }

    /**
     * Return a deterministic descriptor for a callback.
     */
    private function callableSignature(callable $callback, bool $includeCapturedState = true): string
    {
        try {
            if (is_string($callback)) {
                return 'function:' . strtolower($callback);
            }

            if (is_array($callback) && count($callback) === 2) {
                $target = is_object($callback[0])
                    ? ($this->explicitObjectSignature($callback[0]) ?? 'object:' . get_debug_type($callback[0]))
                    : (string) $callback[0];
                return 'method:' . $target . '::' . (string) $callback[1];
            }

            if ($callback instanceof Closure) {
                $reflection = new ReflectionFunction($callback);
                $capturedState = '';
                if ($includeCapturedState) {
                    $variables = $reflection->getStaticVariables();
                    WP_FTS_Analyzer_Config_Limits::assert_option_graph($variables, 'Analyzer callback captured state');
                    $capturedState = ':' . sha1($this->stableJson($this->signatureValue($variables)));
                }
                return sprintf(
                    'closure:%s:%d-%d%s',
                    $reflection->getFileName() ?: 'internal',
                    $reflection->getStartLine(),
                    $reflection->getEndLine(),
                    $capturedState
                );
            }

            if (is_object($callback)) {
                $target = $this->explicitObjectSignature($callback) ?? 'object:' . get_debug_type($callback);
                return 'invokable:' . $target;
            }
        } catch (WP_FTS_Analyzer_Config_Limit_Exceeded $error) {
            throw $error;
        } catch (Throwable) {
            return 'callable:' . get_debug_type($callback);
        }

        return 'callable:' . get_debug_type($callback);
    }

    /**
     * Return an object's explicit index signature when it provides one.
     */
    private function explicitObjectSignature(object $component): ?string
    {
        if (!is_callable([$component, 'index_signature'])) {
            return null;
        }

        try {
            $signature = $component->index_signature();
            if (is_scalar($signature) && trim((string) $signature) !== '') {
                return (string) $signature;
            }
        } catch (Throwable) {
            // Fall through to the callable or class-level descriptor.
        }

        return null;
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
            return $this->callableSignature($value, false);
        }

        if (is_object($value)) {
            return $this->componentSignature($value);
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
