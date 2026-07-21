<?php
declare(strict_types=1);

/**
 * Converts raw visible text into normalized, optionally stemmed index terms.
 *
 * The pipeline owns tokenization, Unicode normalization, CJK segmentation,
 * and optional stemming. Analyzer classes use it for both documents and
 * queries so both sides apply the same lexical contract.
 */
final class WP_FTS_LanguagePipeline
{
    private const CJK_MAX_NGRAM_LENGTH = 4;
    private const MAX_CACHED_ANALYSES = 512;
    private const MAX_CACHED_LANGUAGE_BYTES = 64;
    private const MAX_CACHED_RAW_TOKEN_BYTES = 255;
    private const CONSTRUCTOR_OPTION_KEYS = [
        'lemma_packs_by_lang',
        'stemmer',
        'stemmers_by_lang',
        'cjk_tokenizer',
        'segmenter_packs_by_lang',
        'token_normalizer',
        'chinese_script_map',
        'enable_stemming',
        'min_term_len',
        'max_term_bytes',
        'fold_diacritics',
    ];

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
    private bool $cjkTokenizerIsPackRouter = false;
    private bool $enableStemming;
    private int $minTermLen;
    private int $maxTermBytes;
    private string $indexSignature;
    /** @var array<string,array<int,array{term:string,rank:int,source:string}>> */
    private array $analysisCache = [];

    /**
     * Configure the token analysis pipeline.
     *
     * Use `stemmer` for a custom stemmer; callables accept `($term, $language)`.
     * Use `stemmers_by_lang` for verified language-specific custom stemmers.
     * `cjk_tokenizer` receives the run, canonical language, and output ceiling,
     * and returns a non-empty iterable of unpadded token strings.
     *
     * @param array{
     *   lemma_packs_by_lang?:array<string,string|false>,
     *   stemmer?:WP_FTS_Stemmer|callable|null,
     *   stemmers_by_lang?:array<string,WP_FTS_Stemmer|callable|null>,
     *   cjk_tokenizer?:callable|null,
     *   segmenter_packs_by_lang?:array<string,bool>,
     *   token_normalizer?:callable|null,
     *   chinese_script_map?:array<string,array<string,string>>,
     *   enable_stemming?:bool,
     *   min_term_len?:int,
     *   max_term_bytes?:int,
     *   fold_diacritics?:bool
     * } $options
     */
    public function __construct(array $options = [])
    {
        WP_FTS_Analyzer_Config_Limits::assert_analyzer_options($options, 'Language pipeline options');
        self::assertConstructorOptions($options);

        $this->normalizer = new WP_FTS_Normalizer([
            'fold_diacritics' => $options['fold_diacritics'] ?? true,
            'token_normalizer' => $options['token_normalizer'] ?? null,
            'chinese_script_map' => $options['chinese_script_map'] ?? [],
        ]);
        $this->snowballStemmer = new WP_FTS_SnowballStemmer();
        $this->baselineStemmer = new WP_FTS_BaselineLanguageStemmer();
        $this->polishStemmer = new WP_FTS_PolishStemmer();
        $this->customStemmer = $this->normalize_custom_stemmer($options['stemmer'] ?? null);
        $this->customStemmersByLanguage = $this->normalize_custom_stemmers_by_language(
            $options['stemmers_by_lang'] ?? []
        );
        $this->lemmaPacksByLanguage = $this->normalize_lemma_packs_by_language(
            $this->lemma_pack_options_by_language($options)
        );
        $tokenizer = $options['cjk_tokenizer'] ?? null;
        if ($tokenizer === null) {
            $tokenizer = $this->segmenter_pack_tokenizer_for_options($options);
            $this->cjkTokenizerIsPackRouter = $tokenizer !== null;
        }
        $this->cjkTokenizer = $tokenizer;
        $this->enableStemming = $options['enable_stemming'] ?? true;
        $this->minTermLen = $options['min_term_len'] ?? 2;
        $this->maxTermBytes = $options['max_term_bytes'] ?? 255;
        $this->indexSignature = $this->buildIndexSignature($options, $tokenizer);
    }

    /**
     * Analyze text and return only term strings.
     *
     * Use this for consumers that do not need per-term language metadata.
     * Document/search code generally uses `analyze_detailed()`.
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
     * Terms remain unprefixed; the relational indexer owns dictionary-key
     * namespacing after weighting.
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
        $batches = $this->analyze_detailed_batch([[
            'text' => $text,
            'language' => $language,
            'include_surface' => $includeSurface,
        ]], $maxTerms);

        return $batches[0] ?? [];
    }

    /**
     * Analyze several resolved text segments as one bounded lookup batch.
     *
     * Tokenization and output remain segment-local and ordered. Dictionary
     * surfaces are collected across the entire call first, allowing one lemma
     * pack to group all distinct surfaces by shard and sidecar block. This is
     * the request boundary used by HTML and multi-field document analysis.
     *
     * @param array<int,array{text:string,language:string,include_surface?:bool}> $segments
     * @return array<int,array<int,array{term:string,lang:string,position?:int,rank?:int,source?:string,surface?:string,normalized_surface?:string}>>
     */
    public function analyze_detailed_batch(array $segments, ?int $maxTerms = null): array
    {
        $stream = $this->analyze_detailed_batch_stream($segments, $maxTerms);
        unset($segments);
        $batches = iterator_to_array($stream, false);

        return $batches;
    }

    /**
     * Yield analyzed segments after one request-wide dictionary prefetch.
     *
     * Preparation still sees every segment before lookup, so a lemma sidecar is
     * opened at most once for the complete batch. Yielding one completed segment
     * at a time prevents callers that assemble weighted occurrences from also
     * retaining a second complete analyzed-segment tree.
     *
     * @param array<int,array{text:string,language:string,include_surface?:bool}> $segments
     * @return iterable<int,array<int,array{term:string,lang:string,position?:int,rank?:int,source?:string,surface?:string,normalized_surface?:string}>>
     */
    public function analyze_detailed_batch_stream(array $segments, ?int $maxTerms = null): iterable
    {
        if (!array_is_list($segments)) {
            throw new InvalidArgumentException('FTS analysis segments must be a list.');
        }
        if (count($segments) > WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'occurrences',
                'FTS analysis segment count exceeds the 20,000-occurrence limit.'
            );
        }
        if (
            $maxTerms !== null
            && ($maxTerms < 0 || $maxTerms > WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES)
        ) {
            throw new InvalidArgumentException('FTS analysis maxTerms must be between 0 and 20,000.');
        }
        $maxTerms ??= WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES;

        $prepared = [];
        $totalRawTokens = 0;
        $distinctSurfaces = [];
        $normalizedTerms = [];
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                throw new InvalidArgumentException('Each FTS analysis segment must be an array.');
            }
            foreach (array_keys($segment) as $key) {
                if (!is_string($key) || !in_array($key, ['text', 'language', 'include_surface'], true)) {
                    throw new InvalidArgumentException('FTS analysis segments contain an unsupported field.');
                }
            }
            if (!array_key_exists('text', $segment) || !is_string($segment['text'])) {
                throw new InvalidArgumentException('FTS analysis segment text must be a string.');
            }
            if (
                !array_key_exists('language', $segment)
                || !is_string($segment['language'])
            ) {
                throw new InvalidArgumentException('FTS analysis segment language must be a string.');
            }
            self::assertLanguageKey($segment['language'], 'FTS analysis segment language');
            if (array_key_exists('include_surface', $segment) && !is_bool($segment['include_surface'])) {
                throw new InvalidArgumentException('FTS analysis segment include_surface must be a boolean.');
            }

            $language = $this->canonicalize_language($segment['language']);
            $rawTokens = [];
            foreach ($this->tokenize(
                $segment['text'],
                $language,
                $maxTerms
            ) as $rawToken) {
                $totalRawTokens++;
                if ($totalRawTokens > $maxTerms) {
                    throw new WP_FTS_Analysis_Limit_Exceeded(
                        'occurrences',
                        "FTS analysis exceeds its {$maxTerms}-occurrence limit."
                    );
                }
                $normalizationIdentity = "\0" . $language . "\0"
                    . ($rawToken['is_cjk'] ? '1' : '0') . "\0" . $rawToken['text'];
                if (!array_key_exists($normalizationIdentity, $normalizedTerms)) {
                    $normalizedTerms[$normalizationIdentity] = $this->normalizer->normalize_token(
                        $rawToken['text'],
                        $language
                    );
                    WP_FTS_Analysis_Limits::assert_lexical_run_bytes(
                        strlen($normalizedTerms[$normalizationIdentity])
                    );
                }
                $rawToken['normalized'] = $normalizedTerms[$normalizationIdentity];
                $rawTokens[] = $rawToken;
                if (!$rawToken['is_cjk']) {
                    $normalizedSurface = $rawToken['normalized'];
                    $distinctSurfaces["\0" . $language . "\0" . $normalizedSurface] = true;
                    if (count($distinctSurfaces) > WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES) {
                        throw new WP_FTS_Analysis_Limit_Exceeded(
                            'distinct_surfaces',
                            'FTS analysis exceeds the 4,096-distinct-surface limit.'
                        );
                    }
                }
            }
            $prepared[] = [
                'language' => $language,
                'include_surface' => $segment['include_surface'] ?? false,
                'raw_tokens' => $rawTokens,
            ];
        }
        unset($segments, $distinctSurfaces, $normalizedTerms);
        $prefetchedLemmaAnalyses = $this->prefetch_lemma_analyses($prepared, $maxTerms);

        $totalTerms = 0;
        foreach ($prepared as $segmentIndex => $segment) {
            $language = $segment['language'];
            $includeSurface = $segment['include_surface'];
            $terms = [];
            foreach ($segment['raw_tokens'] as $rawToken) {
                $normalizedSurface = $includeSurface
                    ? $rawToken['normalized']
                    : '';
                $analyses = $this->analyze_raw_token(
                    $rawToken['text'],
                    $language,
                    $rawToken['is_cjk'],
                    $prefetchedLemmaAnalyses,
                    $rawToken['normalized']
                );
                if ($analyses === []) {
                    // A lexical run can be wider than the exact dictionary key but
                    // still has representable prefixes. Preserve one surface-only
                    // occurrence so indexing does not silently lose that prefix
                    // capability merely because no stemmer shortened the token.
                    if ($normalizedSurface !== '') {
                        if ($totalTerms >= $maxTerms) {
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
                        $totalTerms++;
                    }
                    continue;
                }

                $position = count($terms);
                $isMultiAnalysis = count($analyses) > 1;
                foreach ($analyses as $analysis) {
                    if ($totalTerms >= $maxTerms) {
                        throw new WP_FTS_Analysis_Limit_Exceeded(
                            'occurrences',
                            "FTS analysis exceeds its {$maxTerms}-occurrence limit."
                        );
                    }
                    $term = (string) $analysis['term'];
                    $row = [
                        'term' => $term,
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
                    $totalTerms++;
                }
            }
            unset($prepared[$segmentIndex], $segment);
            yield $segmentIndex => $terms;
        }
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
     * Return the language-pipeline behavior signature for stale-document checks.
     */
    public function index_signature(): string
    {
        return $this->indexSignature;
    }

    /**
     * Expose one configured lemma pack's bounded-I/O diagnostics for acceptance
     * tests and operational troubleshooting.
     *
     * @return array{digest:array{files_hashed:int,bytes_hashed:int},lookup:array<string,mixed>}|null
     */
    public function lemma_pack_diagnostics(string $language): ?array
    {
        $pack = $this->lemma_pack_for_language($language);
        if ($pack === null) {
            return null;
        }

        return [
            'digest' => $pack->digest_attestation_stats(),
            'lookup' => $pack->last_lookup_stats(),
        ];
    }

    /**
     * Normalize one raw token and return all valid analysis candidates.
     *
     * Dictionary lemma packs may return multiple pack-backed candidates for one
     * source token. Custom stemmers and non-pack language paths remain one
     * analysis.
     *
     * @return array<int,array{term:string,rank:int,source:string}>
     */
    private function analyze_raw_token(
        string $rawToken,
        string $language,
        bool $isCjk = false,
        array $prefetchedLemmaAnalyses = [],
        ?string $normalizedTerm = null
    ): array
    {
        $language = $this->canonicalize_language($language);
        $cacheKey = strlen($language) <= self::MAX_CACHED_LANGUAGE_BYTES
            && strlen($rawToken) <= self::MAX_CACHED_RAW_TOKEN_BYTES
            ? $language . "\0" . ($isCjk ? '1' : '0') . "\0" . $rawToken
            : null;
        if ($cacheKey !== null && array_key_exists($cacheKey, $this->analysisCache)) {
            return $this->analysisCache[$cacheKey];
        }

        $term = $normalizedTerm ?? $this->normalizer->normalize_token($rawToken, $language);
        $analyses = null;

        if ($isCjk) {
            // CJK n-grams are already lexical units. Stemming and Latin
            // minimum-length pruning would damage recall for single characters.
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
                    $prefetchKey = $this->lemma_prefetch_key($language, $term);
                    $analyses = $prefetchedLemmaAnalyses[$prefetchKey]
                        ?? $lemmaPack->analyze($term, $language);
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
            if (
                !is_array($analysis)
                || array_keys($analysis) !== ['term', 'rank', 'source']
                || !is_string($analysis['term'])
                || $analysis['term'] === ''
                || trim($analysis['term']) !== $analysis['term']
                || !is_int($analysis['rank'])
                || $analysis['rank'] < 0
                || !is_string($analysis['source'])
                || $analysis['source'] === ''
                || trim($analysis['source']) !== $analysis['source']
                || strlen($analysis['source']) > 256
            ) {
                throw new UnexpectedValueException(
                    'Language analysis rows must contain exactly an unpadded term, nonnegative rank, and bounded unpadded source.'
                );
            }
            $candidate = $analysis['term'];
            // A stemmer may rewrite one bounded lexical run, not amplify it
            // into unbounded text. Check bytes before character-length work.
            WP_FTS_Analysis_Limits::assert_lexical_run_bytes(strlen($candidate));
            if (isset($seen[$candidate]) || !$this->term_passes_length_filters($candidate, $isCjk)) {
                continue;
            }

            $seen[$candidate] = true;
            $valid[] = [
                'term' => $candidate,
                'rank' => $analysis['rank'],
                'source' => $analysis['source'],
            ];
        }

        return $this->cache_analysis($cacheKey, $valid);
    }

    /**
     * Resolve all dictionary surfaces before ordered analysis emits results.
     *
     * @param array<int,array{language:string,include_surface:bool,raw_tokens:array<int,array{text:string,is_cjk:bool}>}> $segments
     * @return array<string,array<int,array{term:string,rank:int,source:string}>>
     */
    private function prefetch_lemma_analyses(array $segments, int $maxAnalyses): array
    {
        if (!$this->enableStemming) {
            return [];
        }

        $groups = [];
        foreach ($segments as $segment) {
            $language = $segment['language'];
            if (
                $this->custom_stemmer_for_language($language) !== null
                || $this->customStemmer !== null
            ) {
                continue;
            }
            $lemmaPack = $this->lemma_pack_for_language($language);
            if ($lemmaPack === null) {
                continue;
            }
            $groupKey = $lemmaPack->base_language_code() . "\0" . $lemmaPack->index_signature();
            $groups[$groupKey]['pack'] = $lemmaPack;
            $groups[$groupKey]['language'] = $lemmaPack->base_language_code();
            foreach ($segment['raw_tokens'] as $rawToken) {
                if ($rawToken['is_cjk']) {
                    continue;
                }
                $term = $rawToken['normalized'];
                $termIdentity = "\0" . $term;
                $groups[$groupKey]['terms'][$termIdentity] = $term;
                $groups[$groupKey]['languages'][$termIdentity][$language] = true;
            }
        }

        $prefetched = [];
        $remainingAnalyses = $maxAnalyses;
        foreach ($groups as $group) {
            $language = $group['language'];
            $analysesByTerm = $group['pack']->analyze_many_for_pipeline(
                array_values($group['terms'] ?? []),
                $language,
                $remainingAnalyses,
                fn(string $candidate, string $_surface): bool => $this->term_passes_length_filters($candidate, false),
                fn(string $term, int $lemmaCount): bool => $lemmaCount > 1
                    && !$this->term_meets_min_length($term, false)
            );
            foreach ($analysesByTerm as $term => $analyses) {
                $term = (string) $term;
                foreach (array_keys($group['languages']["\0" . $term] ?? []) as $fullLanguage) {
                    $prefetched[$this->lemma_prefetch_key((string) $fullLanguage, $term)] = $analyses;
                }
                $remainingAnalyses -= count($analyses);
            }
        }

        return $prefetched;
    }

    /** Bind a prefetched result to its full storage language and normalized term. */
    private function lemma_prefetch_key(string $language, string $term): string
    {
        return $language . "\0" . $term;
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
     * non-CJK runs so a Latin+CJK+Latin word run does not become one token.
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
        if (preg_match('//u', $text) !== 1) {
            throw new RuntimeException('Language analysis received invalid normalized Unicode text.');
        }
        while ($offset < $length) {
            $matched = preg_match(
                '/[\p{L}\p{M}\p{N}_]+/u',
                $text,
                $match,
                PREG_OFFSET_CAPTURE,
                $offset
            );
            if ($matched === false) {
                throw new RuntimeException('Language analysis could not tokenize normalized Unicode text.');
            }
            if ($matched === 0) {
                return;
            }

            $rawToken = (string) $match[0][0];
            $offset = (int) $match[0][1] + strlen($rawToken);
            if ($rawToken === '') {
                continue;
            }
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
            if ($matched === false) {
                throw new RuntimeException('Unicode script-run tokenization failed.');
            }
            if ($matched === 0) {
                throw new RuntimeException('Unicode script-run tokenization made no progress.');
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
            $canonicalLanguage = $this->canonicalize_language($language);
            // The analyzer must observe the first excess item, but a producer
            // must not build the rest of a maximum-size query before rejection.
            $tokens = ($this->cjkTokenizer)($run, $canonicalLanguage, $maxTerms + 1);
            $emitted = false;
            foreach ($this->validated_tokenizer_tokens(
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
            if (!$this->cjkTokenizerIsPackRouter) {
                throw new UnexpectedValueException('A CJK tokenizer must yield at least one token string.');
            }
        }

        yield from $this->fallback_cjk_tokens($run);
    }

    /**
     * Validate custom CJK tokenizer output while consuming it incrementally.
     *
     * @param mixed $tokens User segmenter result.
     * @return iterable<int,string>
     */
    private function validated_tokenizer_tokens(
        mixed $tokens,
        int $maxTerms,
        int &$inspectedTokenizerYields
    ): iterable
    {
        if (!is_iterable($tokens)) {
            throw new UnexpectedValueException('A CJK tokenizer must return a non-empty iterable of token strings.');
        }

        foreach ($tokens as $token) {
            // Count source yields before validating their shape so an infinite
            // extension generator cannot evade the occurrence limit.
            if (++$inspectedTokenizerYields > $maxTerms) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'occurrences',
                    "FTS analysis exceeds its {$maxTerms}-occurrence limit."
                );
            }
            if (!is_string($token)) {
                throw new UnexpectedValueException('A CJK tokenizer must yield only token strings.');
            }

            WP_FTS_Analysis_Limits::assert_lexical_run_bytes(strlen($token));
            if ($token === '' || trim($token) !== $token) {
                throw new UnexpectedValueException('A CJK tokenizer must yield unpadded non-empty token strings.');
            }
            yield $token;
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

            // Emit every suffix ending at this code point. This produces the
            // complete n-gram set and frequencies with a four-code-point
            // rolling window.
            $windowCount = count($window);
            for ($length = 1; $length <= $windowCount; $length++) {
                yield implode('', array_slice($window, $windowCount - $length));
            }
        }
    }

    /**
     * Stream UTF-8 characters as individual strings.
     *
     * @param string $text UTF-8 text.
     * @return iterable<int,string>
     */
    private function utf8_char_stream(string $text): iterable
    {
        $offset = 0;
        $length = strlen($text);
        while ($offset < $length) {
            $matched = preg_match('/./usA', $text, $match, PREG_OFFSET_CAPTURE, $offset);
            if ($matched === false) {
                throw new RuntimeException('Unicode character tokenization failed.');
            }
            if ($matched === 0) {
                throw new RuntimeException('Unicode character tokenization made no progress.');
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

    /** Reject misspelled, coercible, or out-of-range constructor options. */
    private static function assertConstructorOptions(array $options): void
    {
        foreach (array_keys($options) as $key) {
            if (!is_string($key) || !in_array($key, self::CONSTRUCTOR_OPTION_KEYS, true)) {
                throw new InvalidArgumentException('Unknown language pipeline constructor option: ' . (string) $key);
            }
        }

        foreach (['enable_stemming', 'fold_diacritics'] as $key) {
            if (array_key_exists($key, $options) && !is_bool($options[$key])) {
                throw new InvalidArgumentException("Language pipeline option {$key} must be a boolean.");
            }
        }
        foreach (['min_term_len', 'max_term_bytes'] as $key) {
            if (!array_key_exists($key, $options)) {
                continue;
            }
            if (
                !is_int($options[$key])
                || $options[$key] < 1
                || $options[$key] > WP_FTS_Analysis_Limits::MAX_LEXICAL_RUN_BYTES
            ) {
                throw new InvalidArgumentException(
                    "Language pipeline option {$key} must be an integer from 1 through "
                    . WP_FTS_Analysis_Limits::MAX_LEXICAL_RUN_BYTES . '.'
                );
            }
        }

        if (array_key_exists('stemmer', $options)) {
            self::assertStemmerOption($options['stemmer'], 'Language pipeline option stemmer');
        }
        if (array_key_exists('stemmers_by_lang', $options)) {
            if (!is_array($options['stemmers_by_lang'])) {
                throw new InvalidArgumentException('Language pipeline option stemmers_by_lang must be a language map.');
            }
            foreach ($options['stemmers_by_lang'] as $language => $stemmer) {
                self::assertLanguageKey($language, 'Language pipeline stemmer language');
                self::assertStemmerOption($stemmer, "Language pipeline stemmer for {$language}");
            }
        }

        foreach (['cjk_tokenizer', 'token_normalizer'] as $key) {
            if (
                array_key_exists($key, $options)
                && $options[$key] !== null
                && !is_callable($options[$key])
            ) {
                throw new InvalidArgumentException("Language pipeline option {$key} must be callable or null.");
            }
        }

        if (array_key_exists('lemma_packs_by_lang', $options)) {
            if (!is_array($options['lemma_packs_by_lang'])) {
                throw new InvalidArgumentException('Language pipeline option lemma_packs_by_lang must be a language map.');
            }
            foreach ($options['lemma_packs_by_lang'] as $language => $pack) {
                self::assertLanguageKey($language, 'Language pipeline lemma-pack language');
                if ($pack !== false && !is_string($pack)) {
                    throw new InvalidArgumentException(
                        "Language pipeline lemma pack for {$language} must be a manifest path or false."
                    );
                }
                if (is_string($pack)) {
                    if (trim($pack) === '') {
                        throw new InvalidArgumentException(
                            "Language pipeline lemma pack for {$language} must use a non-empty manifest path."
                        );
                    }
                    WP_FTS_Analyzer_Config_Limits::assert_path($pack, "Language pipeline lemma pack for {$language}");
                }
            }
        }

        if (array_key_exists('segmenter_packs_by_lang', $options)) {
            if (!is_array($options['segmenter_packs_by_lang'])) {
                throw new InvalidArgumentException('Language pipeline option segmenter_packs_by_lang must be a language map.');
            }
            foreach ($options['segmenter_packs_by_lang'] as $language => $pack) {
                self::assertLanguageKey($language, 'Language pipeline segmenter-pack language');
                if (!is_bool($pack)) {
                    throw new InvalidArgumentException(
                        "Language pipeline segmenter pack for {$language} must be a boolean."
                    );
                }
            }
        }

        if (array_key_exists('chinese_script_map', $options)) {
            if (!is_array($options['chinese_script_map'])) {
                throw new InvalidArgumentException('Language pipeline option chinese_script_map must be a language map.');
            }
            foreach ($options['chinese_script_map'] as $language => $map) {
                self::assertLanguageKey($language, 'Chinese script-map language');
                if (!is_array($map)) {
                    throw new InvalidArgumentException("Chinese script map for {$language} must be a string map.");
                }
                foreach ($map as $source => $replacement) {
                    if (!is_string($source) || $source === '' || !is_string($replacement)) {
                        throw new InvalidArgumentException(
                            "Chinese script map for {$language} must contain only non-empty string keys and string values."
                        );
                    }
                }
            }
        }
    }

    /** Assert one configured language map key before canonicalization. */
    private static function assertLanguageKey(mixed $language, string $label): void
    {
        try {
            WP_FTS_TermNamespace::parse_language_tag($language);
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException("{$label} is not a valid language tag.", 0, $error);
        }
    }

    /** Assert one custom stemmer extension value. */
    private static function assertStemmerOption(mixed $stemmer, string $label): void
    {
        if ($stemmer !== null && !$stemmer instanceof WP_FTS_Stemmer && !is_callable($stemmer)) {
            throw new InvalidArgumentException("{$label} must implement WP_FTS_Stemmer, be callable, or be null.");
        }
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
        if ($stemmer === null) {
            return null;
        }
        if ($stemmer instanceof WP_FTS_Stemmer) {
            return $stemmer;
        }

        if (is_callable($stemmer)) {
            return new WP_FTS_CallbackStemmer($stemmer);
        }

        throw new InvalidArgumentException('Custom stemmer must implement WP_FTS_Stemmer, be callable, or be null.');
    }

    /**
     * Normalize a language-to-stemmer map.
     *
     * @param mixed $stemmers
     * @return array<string,WP_FTS_Stemmer>
     */
    private function normalize_custom_stemmers_by_language(array $stemmers): array
    {
        $normalized = [];
        $seenLanguages = [];
        foreach ($stemmers as $language => $stemmer) {
            $canonicalLanguage = $this->canonicalize_language($language);
            if (isset($seenLanguages[$canonicalLanguage])) {
                throw new InvalidArgumentException("Language pipeline stemmer map contains duplicate language {$canonicalLanguage}.");
            }
            $seenLanguages[$canonicalLanguage] = true;
            $stemmer = $this->normalize_custom_stemmer($stemmer);
            if ($stemmer !== null) {
                $normalized[$canonicalLanguage] = $stemmer;
            }
        }

        return $normalized;
    }

    /** @return array<string,mixed> */
    private function lemma_pack_options_by_language(array $options): array
    {
        $map = $options['lemma_packs_by_lang'] ?? [];
        WP_FTS_Analyzer_Config_Limits::assert_language_map($map, 'Language pipeline lemma packs');
        $packs = [];
        foreach ($map as $language => $option) {
            $canonicalLanguage = $this->canonicalize_language($language);
            if (array_key_exists($canonicalLanguage, $packs)) {
                throw new InvalidArgumentException("Language pipeline lemma-pack map contains duplicate language {$canonicalLanguage}.");
            }
            $packs[$canonicalLanguage] = $option;
        }

        return $packs;
    }

    /**
     * Normalize a language-to-lemma-pack map.
     *
     * @param array<string,mixed> $packs Canonical effective language map.
     * @return array<string,WP_FTS_LanguageLemmaPack>
     */
    private function normalize_lemma_packs_by_language(array $packs): array
    {
        $normalized = [];
        $admission = new WP_FTS_ConfiguredLemmaPackAdmission();
        $descriptors = [];
        foreach ($packs as $canonicalLanguage => $option) {
            $manifestPath = WP_FTS_LanguageLemmaPack::manifest_path_from_option($option);
            if ($manifestPath === null) {
                continue;
            }
            $descriptor = $admission->preflight_manifest($manifestPath, $canonicalLanguage);
            if ($descriptor['language_matches'] !== true) {
                throw new RuntimeException("Configured lemma-pack language does not match {$canonicalLanguage}.");
            }
            $descriptors[] = [$canonicalLanguage, $descriptor['manifest_path']];
        }

        $packsByManifest = [];
        foreach ($descriptors as [$canonicalLanguage, $realManifestPath]) {
            $manifestIdentity = $realManifestPath;
            $reusedPack = array_key_exists($manifestIdentity, $packsByManifest);
            $pack = $reusedPack
                ? $packsByManifest[$manifestIdentity]
                : WP_FTS_LanguageLemmaPack::from_pack_option(
                    $realManifestPath,
                    $canonicalLanguage,
                    $admission
                );
            if (!$reusedPack) {
                $packsByManifest[$manifestIdentity] = $pack;
            }
            $normalized[$canonicalLanguage] = $pack;
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
     * Build a CJK tokenizer from bundled segmenter pack options.
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
            if ($option === false) {
                continue;
            }
            $canonicalLanguage = $this->canonicalize_language($language);
            $baseLanguage = $this->base_language($canonicalLanguage);
            if (isset($configuredBaseLanguages[$baseLanguage])) {
                throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                    'duplicate_segmenter_language',
                    'Only one segmenter pack may be configured per base language.'
                );
            }
            $configuredBaseLanguages[$baseLanguage] = true;

            $segmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option($option, $canonicalLanguage);
            if (!$segmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
                throw new LogicException('Enabled segmenter configuration must construct its pinned runtime.');
            }
            $segmenters[$baseLanguage] = $segmenter;
        }

        if ($segmenters === []) {
            return null;
        }
        if (count($segmenters) === 1) {
            return reset($segmenters) ?: null;
        }

        ksort($segmenters, SORT_STRING);

        return function (string $run, string $language, ?int $maxTokens = null) use ($segmenters): array {
            $base = $this->base_language($language);
            $segmenter = $segmenters[$base] ?? null;

            return $segmenter instanceof WP_FTS_ChineseJiebaSegmenter
                ? $segmenter($run, $language, $maxTokens)
                : [];
        };
    }

    /** @return array<string,mixed> */
    private function segmenter_pack_options_by_language(array $options): array
    {
        $map = $options['segmenter_packs_by_lang'] ?? [];
        WP_FTS_Analyzer_Config_Limits::assert_language_map($map, 'Language pipeline segmenter packs');
        $packs = [];
        foreach ($map as $language => $option) {
            $canonicalLanguage = $this->canonicalize_language($language);
            if (array_key_exists($canonicalLanguage, $packs)) {
                throw new InvalidArgumentException(
                    "Language pipeline segmenter-pack map contains duplicate language {$canonicalLanguage}."
                );
            }
            $packs[$canonicalLanguage] = $option;
        }

        return $packs;
    }

    /**
     * Build a stable signature for tokenization, normalization, and stemming.
     *
     * @param array<string,mixed> $options Constructor options.
     */
    private function buildIndexSignature(array $options, mixed $tokenizer): string
    {
        $payload = [
            'contract' => 'wp-fts-language-pipeline',
            'version' => 20,
            'cjk_max_ngram_length' => self::CJK_MAX_NGRAM_LENGTH,
            'min_term_len' => $this->minTermLen,
            'max_term_bytes' => $this->maxTermBytes,
            'fold_diacritics' => $options['fold_diacritics'] ?? true,
            'unicode_normalizer' => $this->normalizer->index_signature(),
            'enable_stemming' => $this->enableStemming,
            'stemmer' => $this->componentSignature($options['stemmer'] ?? null),
            'stemmers_by_lang' => $this->stemmersByLanguageSignature($options['stemmers_by_lang'] ?? []),
            'cjk_tokenizer' => $this->componentSignature($tokenizer),
            'token_normalizer' => $this->componentSignature($options['token_normalizer'] ?? null),
            'chinese_script_map' => $this->signatureValue($options['chinese_script_map'] ?? []),
            'snowball_stemmer' => $this->componentSignature($this->snowballStemmer),
            'baseline_stemmer' => $this->componentSignature($this->baselineStemmer),
            'polish_stemmer' => $this->componentSignature($this->polishStemmer),
        ];
        $lemmaPackSignatures = $this->lemmaPacksByLanguageSignature();
        if ($lemmaPackSignatures !== []) {
            $payload['lemma_packs_by_lang'] = $lemmaPackSignatures;
        }
        return 'wp-fts-language-pipeline-v20:' . sha1($this->stableJson($payload));
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
            $lang = $this->canonicalize_language($language);
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

        if ($component instanceof Closure || is_string($component) || is_array($component)) {
            if (!is_callable($component)) {
                return $this->signatureValue($component);
            }
            return $this->callableSignature($component);
        }

        if (is_object($component)) {
            return $this->explicitObjectSignature($component);
        }

        if (is_callable($component)) {
            return $this->callableSignature($component);
        }

        return $this->signatureValue($component);
    }

    /**
     * Return a deterministic descriptor for a callback.
     */
    private function callableSignature(callable $callback, bool $includeCapturedState = true): string
    {
        if (is_string($callback)) {
            return 'function:' . strtolower($callback);
        }

        if (is_array($callback) && count($callback) === 2) {
            $target = is_object($callback[0])
                ? $this->explicitObjectSignature($callback[0])
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
            return 'invokable:' . $this->explicitObjectSignature($callback);
        }

        return 'callable:' . get_debug_type($callback);
    }

    /**
     * Return an object's explicit index signature when it provides one.
     */
    private function explicitObjectSignature(object $component): string
    {
        if (!is_callable([$component, 'index_signature'])) {
            throw new LogicException(
                'Injected analyzer objects must provide index_signature().'
            );
        }

        $signature = $component->index_signature();
        if (
            !is_string($signature)
            || $signature === ''
            || trim($signature) !== $signature
            || strlen($signature) > 256
        ) {
            throw new UnexpectedValueException(
                'Injected analyzer object index_signature() must return an unpadded non-empty string of at most 256 bytes.'
            );
        }

        return $signature;
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
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
