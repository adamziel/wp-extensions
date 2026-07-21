<?php
declare(strict_types=1);

/**
 * Extracts searchable terms from HTML documents and plain-text queries.
 *
 * The analyzer bridges HTML input and the language pipeline. It strips skipped
 * elements, applies simple ancestor boosts, honors `lang` and `xml:lang`
 * scopes, removes stopwords, and returns weighted occurrences that the indexer
 * can namespace per language.
 */
final class WP_FTS_Analyzer
{
    // Fragment processors add implicit HTML and BODY roots, and non-element
    // tokens add one current pseudo-node. The pushed state stack accounts for
    // every source or virtual element below those two roots, so exactly three is
    // the complete difference between its 256 rows and the reported depth.
    private const HTML_PROCESSOR_DEPTH_OVERHEAD = 3;
    private const HTML_PROCESSOR_TOKEN_TYPE_BYTES = 64;

    /** @var array<string,bool> */
    private array $skipAncestors;

    /** @var array<string,float> */
    private array $boosts;

    /** @var array<string,bool> */
    private array $stopwords;

    /** @var array<string,array<string,bool>> */
    private array $stopwordsByLang;

    /** @var callable|null */
    private $htmlProcessorFactory;

    /** @var callable|null */
    private $documentLanguageResolver;

    /** @var callable|null */
    private $queryLanguageResolver;

    /** @var callable|null */
    private $queryTermLanguageResolver;

    private WP_FTS_LanguagePipeline $languagePipeline;
    private ?WP_FTS_LanguageDetector $languageDetector;
    private bool $autoDetectLanguage;
    private string $defaultLanguage;
    private ?string $documentLanguage;
    private ?string $queryLanguage;
    private string $indexSignature;

    /**
     * Configure HTML extraction, language resolution, and token analysis.
     *
     * Common options:
     * - `skip_ancestors`: element names whose text should not be indexed.
     * - `boosts`: element-name to weight map; the largest ancestor boost wins.
     * - `default_lang`, `document_lang`, `query_lang`: language hints used when
     *   content, options, and resolver callbacks do not provide one.
     * - `document_language_resolver` and `query_language_resolver`: callables
     *   receiving the options array and returning a language candidate.
     * - `query_term_language_resolver`: deterministic per-query-token language
     *   resolver. It may accept `($token)`, `($token, $options)`, or
     *   `($token, $options, $defaultLang)`.
     * - `auto_detect_language`: fill language gaps with deterministic script
     *   and compact lexical evidence. Explicit language options, HTML language
     *   attributes, and multilingual-plugin metadata still win.
     * - `cjk_tokenizer`: optional segmenter for one CJK script run; the
     *   built-in n-gram tokenizer remains the fallback.
     * - `segmenter_packs_by_lang`: optional source-backed tokenizer packs such
     *   as the Jieba-backed Chinese adapter. Missing or invalid packs fall back
     *   to the built-in n-gram tokenizer.
     * - `html_processor_factory`: hook that returns a processor-like object for
     *   the given HTML.
     *
     * @param array{
     *   skip_ancestors?:string[],
     *   boosts?:array<string,float|int>,
     *   stopwords?:string[],
     *   min_term_len?:int,
     *   max_term_bytes?:int,
     *   fold_diacritics?:bool,
     *   default_lang?:string,
     *   language?:string,
     *   document_lang?:string,
     *   query_lang?:string,
     *   namespace_terms?:bool,
     *   enable_stemming?:bool,
     *   polish_stemming?:string,
     *   polish_lemma_pack?:bool|string|array<string,mixed>|null,
     *   polish_lemmatizer_pack?:bool|string|array<string,mixed>|null,
     *   lemma_packs_by_lang?:array<string,bool|string|array<string,mixed>|null>,
     *   lemmatizer_packs_by_lang?:array<string,bool|string|array<string,mixed>|null>,
     *   language_pipeline?:WP_FTS_LanguagePipeline,
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
     *   stopwords_by_lang?:array<string,string[]>,
     *   document_language_resolver?:callable|null,
     *   query_language_resolver?:callable|null,
     *   query_term_language_resolver?:callable|null,
     *   term_language_resolver?:callable|null,
     *   language_detector?:WP_FTS_LanguageDetector|null,
     *   auto_detect_language?:bool,
     *   detect_language?:bool,
     *   html_processor_factory?:callable|null
     * } $options
     */
    public function __construct(array $options = [])
    {
        WP_FTS_Analyzer_Config_Limits::assert_analyzer_options($options, 'Analyzer options');
        $skip = $options['skip_ancestors'] ?? [
            'SCRIPT',
            'STYLE',
            'NOSCRIPT',
            'TEMPLATE',
            'SVG',
            'NAV',
            'ASIDE',
            'FOOTER',
            'FORM',
        ];
        $this->skipAncestors = array_fill_keys(array_map(
            static fn(string $tag): string => strtoupper($tag),
            $skip
        ), true);

        $boosts = $options['boosts'] ?? [
            'TITLE' => 5.0,
            'H1' => 4.0,
            'H2' => 3.0,
            'H3' => 2.0,
            'STRONG' => 2.0,
            'EM' => 1.5,
            'B' => 2.0,
        ];
        $this->boosts = [];
        foreach ($boosts as $tag => $boost) {
            $this->boosts[strtoupper((string) $tag)] = (float) $boost;
        }

        $this->htmlProcessorFactory = $options['html_processor_factory'] ?? null;
        $this->documentLanguageResolver = $options['document_language_resolver'] ?? null;
        $this->queryLanguageResolver = $options['query_language_resolver'] ?? null;
        $termResolver = $options['query_term_language_resolver'] ?? $options['term_language_resolver'] ?? null;
        $this->queryTermLanguageResolver = is_callable($termResolver) ? $termResolver : null;
        $this->autoDetectLanguage = (bool) ($options['auto_detect_language'] ?? $options['detect_language'] ?? true);
        $detector = $options['language_detector'] ?? null;
        $this->languageDetector = $this->autoDetectLanguage
            ? ($detector instanceof WP_FTS_LanguageDetector ? $detector : new WP_FTS_LanguageDetector())
            : null;
        $this->languagePipeline = $options['language_pipeline'] ?? new WP_FTS_LanguagePipeline([
            'min_term_len' => (int) ($options['min_term_len'] ?? 2),
            'max_term_bytes' => (int) ($options['max_term_bytes'] ?? 255),
            'fold_diacritics' => (bool) ($options['fold_diacritics'] ?? true),
            'namespace_terms' => (bool) ($options['namespace_terms'] ?? false),
            'enable_stemming' => (bool) ($options['enable_stemming'] ?? true),
            'polish_stemming' => (string) ($options['polish_stemming'] ?? 'conservative'),
            'polish_lemma_pack' => $options['polish_lemma_pack'] ?? $options['polish_lemmatizer_pack'] ?? false,
            'lemma_packs_by_lang' => $options['lemma_packs_by_lang'] ?? [],
            'lemmatizer_packs_by_lang' => $options['lemmatizer_packs_by_lang'] ?? [],
            'stemmer' => $options['stemmer'] ?? null,
            'stemmers_by_lang' => $options['stemmers_by_lang'] ?? $options['stemmers'] ?? [],
            'cjk_tokenizer' => $options['cjk_tokenizer'] ?? $options['cjk_segmenter'] ?? null,
            'segmenter_packs_by_lang' => $options['segmenter_packs_by_lang'] ?? [],
            'cjk_segmenter_packs_by_lang' => $options['cjk_segmenter_packs_by_lang'] ?? [],
            'cjk_tokenizer_packs_by_lang' => $options['cjk_tokenizer_packs_by_lang'] ?? [],
            'tokenizer_packs_by_lang' => $options['tokenizer_packs_by_lang'] ?? [],
            'token_normalizer' => $options['token_normalizer'] ?? null,
            'chinese_script_map' => $options['chinese_script_map'] ?? [],
        ]);

        $this->defaultLanguage = $this->canonicalLanguage($options['default_lang'] ?? $options['language'] ?? null) ?? 'en';
        $this->documentLanguage = $this->canonicalLanguage($options['document_lang'] ?? null);
        $this->queryLanguage = $this->canonicalLanguage($options['query_lang'] ?? null);

        $this->stopwords = [];
        $this->stopwordsByLang = [];
        $stopwordSegments = [];
        $stopwordTargets = [];
        foreach (($options['stopwords'] ?? []) as $word) {
            $stopwordSegments[] = [
                'text' => (string) $word,
                'language' => $this->defaultLanguage,
            ];
            $stopwordTargets[] = null;
        }
        foreach (($options['stopwords_by_lang'] ?? []) as $lang => $words) {
            $canonical = $this->canonicalLanguage((string) $lang);
            if ($canonical === null || !is_array($words)) {
                continue;
            }

            foreach ($words as $word) {
                $stopwordSegments[] = [
                    'text' => (string) $word,
                    'language' => $canonical,
                ];
                $stopwordTargets[] = $canonical;
            }
        }
        foreach ($this->languagePipeline->analyze_detailed_batch($stopwordSegments) as $index => $terms) {
            $target = $stopwordTargets[$index] ?? null;
            foreach ($terms as $term) {
                if ($target === null) {
                    $this->stopwords[$term['term']] = true;
                } else {
                    $this->stopwordsByLang[$target][$term['term']] = true;
                }
            }
        }

        $this->indexSignature = $this->buildIndexSignature();
    }

    /**
     * Analyze HTML content and return weighted token occurrences in source order.
     *
     * Use this for document indexing. `$html` may be a full document or a
     * fragment. Language is resolved from explicit options first, then analyzer
     * defaults, then resolver callbacks. Nested
     * `lang`/`xml:lang` attributes override the document language for their text
     * scope.
     *
     * @param array{lang?:string,language?:string,document_lang?:string,locale?:string,post_id?:int}|string|null $options
     *        Either an options array or a legacy language string.
     * @return array<int,array{term:string,weight:float,lang:string,position?:int,rank?:int,source?:string}>
     *         Occurrences in document order. `weight` is the strongest boost
     *         inherited from ancestor tags, and `lang` is the term language.
     */
    public function analyze_content(string $html, array|string|null $options = []): array
    {
        $options = $this->normalizeLanguageOptions($options, 'document');
        WP_FTS_Analysis_Limits::assert_source_bytes($html);
        WP_FTS_Html_Text_Stream::assert_analysis_markup_limits($html);
        $maxOccurrences = $this->documentOccurrenceLimit($options);
        $includeSurface = $this->truthyOption($options['_include_document_surface'] ?? false);
        $this->assertLexicalWordBudget($html, $maxOccurrences);
        $tokens = [];
        $nextPosition = 0;

        $segments = $this->extractHtmlSegments($html, $options);
        $segmentWeights = array_column($segments, 'weight');
        foreach ($this->analyzeTextBatchStream($segments, $includeSurface, $maxOccurrences) as $index => $terms) {
            $terms = $this->renumberAnalyzedPositions(
                $terms,
                $nextPosition
            );
            foreach ($terms as $term) {
                if ($this->isStopword($term['term'], $term['lang'])) {
                    continue;
                }

                $row = [
                    'term' => $term['term'],
                    'weight' => $segmentWeights[$index],
                    'lang' => $term['lang'],
                ];
                foreach (['position', 'rank', 'source', 'normalized_surface'] as $key) {
                    if (array_key_exists($key, $term)) {
                        $row[$key] = $term[$key];
                    }
                }
                $tokens[] = $row;
            }
        }

        return $tokens;
    }

    /**
     * Analyze HTML content using the legacy method name.
     *
     * Retained for callers from the stemmer lane. Pass the same arguments as
     * `analyze_content()`; a string `$language` is treated as the document
     * language.
     *
     * @param array<string,mixed>|string|null $language
     * @return array<int,array{term:string,weight:float,lang:string,position?:int,rank?:int,source?:string}>
     */
    public function analyze_content_terms(string $html, array|string|null $language = null): array
    {
        return $this->analyze_content($html, $language);
    }

    /**
     * Analyze text that callers already extracted from document content.
     *
     * This preserves the document-language option semantics of
     * `analyze_content()` while skipping HTML segmentation for field values that
     * are known to be plain text, such as titles, excerpts, taxonomy labels, and
     * custom-field values.
     *
     * @param array{lang?:string,language?:string,document_lang?:string,locale?:string,post_id?:int}|string|null $options
     * @return array<int,array{term:string,weight:float,lang:string,position?:int,rank?:int,source?:string}>
     */
    public function analyze_plain_content(string $text, array|string|null $options = []): array
    {
        $options = $this->normalizeLanguageOptions($options, 'document');
        WP_FTS_Analysis_Limits::assert_source_bytes($text);
        $maxOccurrences = $this->documentOccurrenceLimit($options);
        $includeSurface = $this->truthyOption($options['_include_document_surface'] ?? false);
        $this->assertLexicalWordBudget($text, $maxOccurrences);
        $lang = $this->resolveDocumentLanguage($options);
        if ($this->shouldAutoDetectDocumentLanguage($options)) {
            $lang = $this->detectSegmentLanguage($text, $lang);
        }
        $nextPosition = 0;
        $tokens = [];

        foreach ($this->renumberAnalyzedPositions(
            $this->analyzeText($text, $lang, $includeSurface, $maxOccurrences),
            $nextPosition
        ) as $term) {
            if ($this->isStopword($term['term'], $term['lang'])) {
                continue;
            }

            $row = [
                'term' => $term['term'],
                'weight' => 1.0,
                'lang' => $term['lang'],
            ];
            foreach (['position', 'rank', 'source', 'normalized_surface'] as $key) {
                if (array_key_exists($key, $term)) {
                    $row[$key] = $term[$key];
                }
            }
            $tokens[] = $row;
        }

        return $tokens;
    }

    /**
     * Analyze all normalized index fields through one dictionary lookup batch.
     *
     * Indexer uses this production path to prevent field boundaries from
     * multiplying sidecar reads. Each returned element contains the occurrence
     * list for the field at the same input index; HTML boosts and language scopes
     * remain local to their original field.
     *
     * @param array<int,array{name:string,text:string,html?:string,boost:float}> $fields
     * @param array<string,mixed>|string|null $options
     * @return array<int,array<int,array{term:string,weight:float,lang:string,position?:int,rank?:int,source?:string}>>
     */
    public function analyze_document_fields(array $fields, array|string|null $options = []): array
    {
        if (count($fields) > 32) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'index_fields',
                'FTS document analysis accepts at most 32 fields.'
            );
        }

        $baseOptions = $this->normalizeLanguageOptions($options, 'document');
        $maxOccurrences = $this->documentOccurrenceLimit($baseOptions);
        $includeSurface = $this->truthyOption($baseOptions['_include_document_surface'] ?? false);
        $segments = [];
        $segmentFields = [];
        $segmentWeights = [];
        $sourceBytes = 0;
        $lexicalWords = 0;
        $fieldSources = [];
        foreach ($fields as $fieldIndex => $field) {
            if (!is_array($field) || count($field) > 4) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'index_field_shape',
                    'FTS document fields must use the bounded normalized field shape.'
                );
            }
            $source = isset($field['html'])
                ? (string) $field['html']
                : (string) ($field['text'] ?? '');
            $fieldSources[$fieldIndex] = $source;
            $sourceBytes += strlen($source);
            WP_FTS_Analysis_Limits::assert_document_source_bytes($sourceBytes);
            foreach (WP_FTS_Html_Text_Stream::visible_word_stream($source) as $word) {
                WP_FTS_Analysis_Limits::assert_lexical_run_bytes(strlen((string) ($word['text'] ?? '')));
                if (++$lexicalWords > $maxOccurrences) {
                    throw new WP_FTS_Analysis_Limit_Exceeded(
                        'occurrences',
                        'FTS document analysis exceeds the 20,000-occurrence limit.'
                    );
                }
            }
            if (isset($field['html'])) {
                WP_FTS_Html_Text_Stream::assert_analysis_markup_limits($source);
            }
        }

        // Only collect segment arrays after every aggregate source/word/markup
        // preflight succeeds, so a late invalid field cannot leave an almost
        // maximum document resident before rejection.
        foreach ($fields as $fieldIndex => $field) {
            $fieldOptions = $baseOptions;
            $fieldOptions['field_name'] = (string) ($field['name'] ?? '');
            $source = $fieldSources[$fieldIndex];
            if (isset($field['html'])) {
                foreach ($this->extractHtmlSegments($source, $fieldOptions) as $segment) {
                    if (count($segments) >= WP_FTS_Analysis_Limits::MAX_HTML_MARKUP_TOKENS) {
                        throw new WP_FTS_Analysis_Limit_Exceeded(
                            'html_markup_tokens',
                            'FTS document HTML exceeds the aggregate 20,000-segment limit.'
                        );
                    }
                    $segments[] = $segment;
                    $segmentFields[] = $fieldIndex;
                    $segmentWeights[] = $segment['weight'];
                }
                continue;
            }

            $lang = $this->resolveDocumentLanguage($fieldOptions);
            if ($this->shouldAutoDetectDocumentLanguage($fieldOptions)) {
                $lang = $this->detectSegmentLanguage($source, $lang);
            }
            if (count($segments) >= WP_FTS_Analysis_Limits::MAX_HTML_MARKUP_TOKENS) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'html_markup_tokens',
                    'FTS document fields exceed the aggregate 20,000-segment limit.'
                );
            }
            $segments[] = ['text' => $source, 'lang' => $lang];
            $segmentFields[] = $fieldIndex;
            $segmentWeights[] = 1.0;
        }

        $tokensByField = array_fill(0, count($fields), []);
        $nextPositions = array_fill(0, count($fields), 0);
        $accepted = 0;
        foreach ($this->analyzeTextBatchStream($segments, $includeSurface, $maxOccurrences) as $segmentIndex => $terms) {
            $fieldIndex = $segmentFields[$segmentIndex];
            $terms = $this->renumberAnalyzedPositions($terms, $nextPositions[$fieldIndex]);
            foreach ($terms as $term) {
                if ($this->isStopword($term['term'], $term['lang'])) {
                    continue;
                }
                if (++$accepted > $maxOccurrences) {
                    throw new WP_FTS_Analysis_Limit_Exceeded(
                        'occurrences',
                        'FTS document analysis exceeds the 20,000-occurrence limit.'
                    );
                }

                $row = [
                    'term' => $term['term'],
                    'weight' => $segmentWeights[$segmentIndex],
                    'lang' => $term['lang'],
                ];
                foreach (['position', 'rank', 'source', 'normalized_surface'] as $key) {
                    if (array_key_exists($key, $term)) {
                        $row[$key] = $term[$key];
                    }
                }
                $tokensByField[$fieldIndex][] = $row;
            }
        }

        return $tokensByField;
    }

    /**
     * Query analysis intentionally skips only the HTML extraction stage.
     *
     * Use this for user search text. By default it returns plain term strings
     * for legacy callers. Pass `return => occurrences`, `format => occurrences`,
     * `return => tokens`, or `return => objects` to receive `term/lang` rows.
     *
     * @param array{lang?:string,language?:string,query_lang?:string,locale?:string,return?:string,format?:string,_force_query_lang?:bool,_include_query_surface?:bool,include_query_surface?:bool,include_surface?:bool}|string|null $options
     *        Query language hints and optional output format. A legacy string is
     *        treated as `query_lang`.
     * @return string[]|array<int,array{term:string,lang:string,position?:int,rank?:int,source?:string,surface?:string}>
     *         Term strings or occurrence rows, depending on requested format.
     */
    public function analyze_query(string $query, array|string|null $options = []): array
    {
        $options = $this->normalizeLanguageOptions($options, 'query');
        $format = (string) ($options['return'] ?? $options['format'] ?? 'terms');
        if (in_array($format, ['occurrences', 'tokens', 'objects'], true)) {
            return $this->analyze_query_occurrences($query, $options);
        }

        return array_map(
            static fn(array $occurrence): string => $occurrence['term'],
            $this->analyze_query_occurrences($query, $options)
        );
    }

    /**
     * Analyze query text using the legacy structured method name.
     *
     * This always returns occurrence rows, unlike `analyze_query()` which
     * defaults to strings.
     *
     * @param array<string,mixed>|string|null $language
     * @return array<int,array{term:string,lang:string,position?:int,rank?:int,source?:string,surface?:string,normalized_surface?:string}>
     */
    public function analyze_query_terms(string $query, array|string|null $language = null): array
    {
        return $this->analyze_query_occurrences($query, $language);
    }

    /**
     * Analyze query text and preserve each token's resolved language.
     *
     * Searcher uses this to decide the language partition before namespacing
     * query terms.
     *
     * @param array{lang?:string,language?:string,query_lang?:string,locale?:string,_force_query_lang?:bool,_include_query_surface?:bool,include_query_surface?:bool,include_surface?:bool}|string|null $options
     * @return array<int,array{term:string,lang:string,position?:int,rank?:int,source?:string,surface?:string,normalized_surface?:string}>
     */
    public function analyze_query_occurrences(string $query, array|string|null $options = []): array
    {
        $options = $this->normalizeLanguageOptions($options, 'query');
        $maxOccurrences = isset($options['_max_query_occurrences']) && is_numeric($options['_max_query_occurrences'])
            ? max(0, min(WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES, (int) $options['_max_query_occurrences']))
            : null;
        $lang = $this->resolveQueryLanguage($options);
        $includeSurface = $this->truthyOption($options['_include_query_surface'] ?? false)
            || $this->truthyOption($options['include_query_surface'] ?? false)
            || $this->truthyOption($options['include_surface'] ?? false);
        $terms = [];
        $nextPosition = 0;

        $segments = $this->queryTextSegments($query, $lang, $options);
        foreach ($this->analyzeTextBatchStream($segments, $includeSurface, $maxOccurrences) as $analyzedSegment) {
            $segmentTerms = $this->renumberAnalyzedPositions(
                $analyzedSegment,
                $nextPosition
            );
            array_push($terms, ...$this->filterQueryStopwords($segmentTerms, $includeSurface));
            if ($maxOccurrences !== null && count($terms) > $maxOccurrences) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'occurrences',
                    "FTS analysis exceeds its {$maxOccurrences}-occurrence limit."
                );
            }
        }

        return $terms;
    }

    /**
     * Remove query stopwords without losing which raw token was typed last.
     *
     * Set-oriented prefix search must not fall back to an earlier word when the
     * final word is a stopword. When every analysis for one source token is
     * filtered, retain one surface-only row; it cannot become an exact query
     * candidate, but it keeps the final typed surface authoritative. Analyzer
     * alternatives share a position and are filtered as one source token.
     *
     * @param array<int,array<string,mixed>> $terms
     * @return array<int,array<string,mixed>>
     */
    private function filterQueryStopwords(array $terms, bool $includeSurface): array
    {
        $sourceGroups = [];
        foreach ($terms as $index => $term) {
            $sourceKey = isset($term['position']) && is_scalar($term['position'])
                ? 'position:' . (string) $term['position']
                : 'row:' . (string) $index;
            $sourceGroups[$sourceKey][] = $term;
        }

        $filtered = [];
        foreach ($sourceGroups as $sourceTerms) {
            $accepted = [];
            foreach ($sourceTerms as $term) {
                if (!$this->isStopword($term['term'], $term['lang'])) {
                    $accepted[] = $term;
                }
            }
            if ($accepted !== []) {
                array_push($filtered, ...$accepted);
                continue;
            }

            $surface = $sourceTerms[0] ?? null;
            if (
                !$includeSurface
                || !is_array($surface)
                || !is_scalar($surface['normalized_surface'] ?? null)
                || (string) $surface['normalized_surface'] === ''
            ) {
                continue;
            }

            $surface['term'] = '';
            unset($surface['position'], $surface['rank'], $surface['source']);
            $filtered[] = $surface;
        }

        return $filtered;
    }

    /**
     * Reduce weighted occurrences to integer term frequencies.
     *
     * The index stores integer frequencies, so weights are summed per term and
     * rounded with a minimum of 1. Pass `namespace_terms => true` when the caller
     * wants the analyzer-level namespace format `lang . "\\x1e" . term`; the
     * main indexer does its own language-aware reduction instead.
     *
     * @param array<int,array{term:string,weight?:float,lang?:string}> $occurrences
     * @param array{namespace_terms?:bool} $options
     * @return array<string,int>
     */
    public function weighted_term_frequencies(array $occurrences, array $options = []): array
    {
        $namespaceTerms = (bool) ($options['namespace_terms'] ?? false);
        $weights = [];

        foreach ($occurrences as $occurrence) {
            $term = (string) $occurrence['term'];
            if ($namespaceTerms && isset($occurrence['lang'])) {
                $term = self::namespaced_term($term, (string) $occurrence['lang']);
            }
            $weights[$term] = ($weights[$term] ?? 0.0) + (float) ($occurrence['weight'] ?? 1.0);
        }

        $frequencies = [];
        foreach ($weights as $term => $weight) {
            $frequencies[$term] = max(1, (int) round($weight));
        }
        ksort($frequencies, SORT_STRING);

        return $frequencies;
    }

    /**
     * Build a namespaced term in the analyzer's legacy argument order.
     *
     * @param string $term Normalized lexical term.
     * @param string $lang Language partition.
     * @return string Stored key in `lang . "\\x1e" . term` format.
     */
    public static function namespaced_term(string $term, string $lang): string
    {
        return self::canonicalLanguageStatic($lang) . "\x1e" . $term;
    }

    /**
     * Return the analyzer behavior signature used by the indexer content hash.
     *
     * The signature changes when the built-in analyzer defaults or configured
     * language pipeline change, forcing unchanged documents to be rewritten
     * instead of leaving stale postings from an older analyzer contract.
     */
    public function index_signature(): string
    {
        return $this->indexSignature;
    }

    /** Expose configured lemma-pack I/O diagnostics for acceptance checks. */
    public function lemma_pack_diagnostics(string $language): ?array
    {
        return $this->languagePipeline->lemma_pack_diagnostics($language);
    }

    /**
     * Run the configured language pipeline for one resolved text segment.
     *
     * @param string $text Visible text from a document segment or query.
     * @param string $lang Segment language, canonicalized with analyzer
     *        fallbacks.
     * @return array<int,array{term:string,lang:string,position?:int,rank?:int,source?:string,surface?:string}>
     */
    private function analyzeText(
        string $text,
        string $lang,
        bool $includeSurface = false,
        ?int $maxTerms = null
    ): array
    {
        $lang = $this->canonicalLanguage($lang) ?? $this->defaultLanguage;

        return $this->languagePipeline->analyze_detailed($text, $lang, $includeSurface, $maxTerms);
    }

    /**
     * Transfer resolved segments into one streamed lemma lookup batch.
     *
     * The input is cleared before pipeline preparation so callers do not retain
     * both analyzer and pipeline copies of every maximum-depth HTML segment.
     *
     * @param array<int,array{text:string,lang:string}> $segments
     * @return iterable<int,array<int,array{term:string,lang:string,position?:int,rank?:int,source?:string,surface?:string}>>
     */
    private function analyzeTextBatchStream(
        array &$segments,
        bool $includeSurface = false,
        ?int $maxTerms = null
    ): iterable {
        $pipelineSegments = [];
        foreach ($segments as $segment) {
            $pipelineSegments[] = [
                'text' => $segment['text'],
                'language' => $this->canonicalLanguage($segment['lang']) ?? $this->defaultLanguage,
                'include_surface' => $includeSurface,
            ];
        }
        $segments = [];

        return $this->languagePipeline->analyze_detailed_batch_stream($pipelineSegments, $maxTerms);
    }

    /** Resolve the remaining per-document occurrence allowance. */
    private function documentOccurrenceLimit(array $options): int
    {
        $requested = isset($options['_max_document_occurrences']) && is_numeric($options['_max_document_occurrences'])
            ? (int) $options['_max_document_occurrences']
            : WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES;

        return max(0, min(WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES, $requested));
    }

    /**
     * Reject token-dense input before HTML segmentation builds occurrence maps.
     */
    private function assertLexicalWordBudget(string $source, int $limit): void
    {
        $count = 0;
        foreach (WP_FTS_Html_Text_Stream::visible_word_stream($source) as $word) {
            WP_FTS_Analysis_Limits::assert_lexical_run_bytes(strlen((string) ($word['text'] ?? '')));
            if (++$count > $limit) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'occurrences',
                    'FTS document analysis exceeds the 20,000-occurrence limit.'
                );
            }
        }
    }

    /**
     * Convert pipeline-local token positions into analyzer-global positions.
     *
     * The public occurrence shape stays unchanged for normal single-analysis
     * tokens. A `position` key is retained only when a token produced multiple
     * analyses and search needs those rows grouped as alternatives.
     *
     * @param array<int,array<string,mixed>> $terms
     * @return array<int,array<string,mixed>>
     */
    private function renumberAnalyzedPositions(array $terms, int &$nextPosition): array
    {
        $positionCounts = [];
        foreach ($terms as $term) {
            if (isset($term['position']) && is_scalar($term['position'])) {
                $position = (string) $term['position'];
                $positionCounts[$position] = ($positionCounts[$position] ?? 0) + 1;
            }
        }

        $positionMap = [];
        foreach ($terms as &$term) {
            if (!isset($term['position']) || !is_scalar($term['position'])) {
                $nextPosition++;
                continue;
            }

            $localPosition = (string) $term['position'];
            if (!array_key_exists($localPosition, $positionMap)) {
                $positionMap[$localPosition] = $nextPosition++;
            }

            if (($positionCounts[$localPosition] ?? 0) > 1) {
                $term['position'] = $positionMap[$localPosition];
            } else {
                unset($term['position']);
            }
        }
        unset($term);

        return $terms;
    }

    /**
     * Split a query into language-scoped text segments.
     *
     * Untagged queries use the resolved query language exactly as before.
     * Inline tags such as `pl:zamek` or `en-US:"color search"` scope only the
     * tagged term or quoted phrase. A resolver callback can deterministically
     * assign languages to otherwise untagged tokens.
     *
     * @param array<string,mixed> $options
     * @return array<int,array{text:string,lang:string}>
     */
    private function queryTextSegments(string $query, string $defaultLang, array $options): array
    {
        $segments = [];
        $offset = 0;
        $forceQueryLang = (bool) ($options['_force_query_lang'] ?? false);
        $pattern = '/(^|[\s,;]+)([A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8}){0,3}):("[^"]+"|\'[^\']+\'|[^\s,;]+)/u';
        $matched = @preg_match_all($pattern, $query, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($matched === false || $matched === 0) {
            $this->appendUntaggedQuerySegments($segments, $query, $defaultLang, $options);
            return $segments;
        }

        foreach ($matches as $match) {
            $fullText = $match[0][0];
            $fullStart = $match[0][1];
            $prefixLength = strlen($match[1][0]);
            $tagStart = $fullStart + $prefixLength;

            if ($tagStart > $offset) {
                $this->appendUntaggedQuerySegments(
                    $segments,
                    substr($query, $offset, $tagStart - $offset),
                    $defaultLang,
                    $options
                );
            }

            $tagLang = $forceQueryLang ? $defaultLang : $this->canonicalLanguage($match[2][0]);
            $tagText = $this->unquoteTaggedQueryText($match[3][0]);
            if ($tagLang !== null && trim($tagText) !== '') {
                $segments[] = ['text' => $tagText, 'lang' => $tagLang];
            } else {
                $this->appendUntaggedQuerySegments(
                    $segments,
                    substr($query, $tagStart, strlen($fullText) - $prefixLength),
                    $defaultLang,
                    $options
                );
            }

            $offset = $fullStart + strlen($fullText);
        }

        if ($offset < strlen($query)) {
            $this->appendUntaggedQuerySegments($segments, substr($query, $offset), $defaultLang, $options);
        }

        return $segments;
    }

    /**
     * Add untagged query text using the same span-level detection as documents.
     *
     * A custom per-token resolver still gets first refusal for each token. When
     * it has no answer, the whole untagged query span supplies the fallback
     * language so weak single-token evidence does not drift back to the default
     * partition and break AND recall.
     *
     * @param array<int,array{text:string,lang:string}> $segments
     * @param array<string,mixed> $options
     */
    private function appendUntaggedQuerySegments(array &$segments, string $text, string $defaultLang, array $options): void
    {
        if (trim($text) === '') {
            return;
        }

        $spanLang = $this->detectQuerySpanLanguage($text, $defaultLang, $options);
        if ($this->queryTermLanguageResolver === null || (bool) ($options['_force_query_lang'] ?? false)) {
            $segments[] = ['text' => $text, 'lang' => $spanLang];
            return;
        }

        foreach ($this->queryRawTokens($text) as $token) {
            $lang = $this->callQueryTermLanguageResolver($token, $options, $defaultLang)
                ?? $spanLang;
            $segments[] = ['text' => $token, 'lang' => $lang];
        }
    }

    /**
     * Remove surrounding quotes from an inline language-tagged query phrase.
     */
    private function unquoteTaggedQueryText(string $text): string
    {
        $length = strlen($text);
        if ($length >= 2) {
            $first = $text[0];
            $last = $text[$length - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($text, 1, -1);
            }
        }

        return $text;
    }

    /**
     * Tokenize untagged query text for the deterministic language resolver.
     *
     * @return string[]
     */
    private function queryRawTokens(string $text): array
    {
        $matches = [];
        if (@preg_match_all('/[\p{L}\p{M}\p{N}_]+/u', $text, $matches) !== false) {
            return array_values(array_filter(
                $matches[0] ?? [],
                static fn(string $token): bool => $token !== ''
            ));
        }

        $ascii = preg_replace('/[^\x20-\x7E]+/', ' ', $text) ?? '';
        preg_match_all('/[A-Za-z0-9_]+/', $ascii, $matches);

        return $matches[0] ?? [];
    }

    /**
     * Call the optional per-token query language resolver.
     *
     * @param array<string,mixed> $options
     */
    private function callQueryTermLanguageResolver(string $token, array $options, string $defaultLang): ?string
    {
        if ($this->queryTermLanguageResolver === null) {
            return null;
        }

        try {
            $resolver = Closure::fromCallable($this->queryTermLanguageResolver);
            $reflection = new ReflectionFunction($resolver);
            $argc = $reflection->isVariadic() ? 3 : $reflection->getNumberOfParameters();
            if ($argc >= 3) {
                $resolved = $resolver($token, $options, $defaultLang);
            } elseif ($argc === 2) {
                $resolved = $resolver($token, $options);
            } else {
                $resolved = $resolver($token);
            }
        } catch (Throwable) {
            return null;
        }

        return $this->canonicalLanguage($resolved);
    }

    /**
     * Detect language for a whole untagged query span.
     *
     * @param array<string,mixed> $options
     */
    private function detectQuerySpanLanguage(string $text, string $defaultLang, array $options): string
    {
        if (!$this->shouldAutoDetectQueryLanguage($options) || $this->languageDetector === null) {
            return $defaultLang;
        }

        // A detector miss stays on the one configured query partition. Probing
        // every enabled lemma pack here would make analyzer cost grow with the
        // number and size of installed dictionaries.
        return $this->languageDetector->detect_text($text) ?? $defaultLang;
    }

    /**
     * Extract visible text segments and the language/weight for each segment.
     *
     * A caller-provided HTML processor factory may provide browser-like parsing.
     * When it is unavailable or cannot be created, the fallback parser keeps
     * enough stack state to make skip, boost, and language-scope decisions
     * deterministic.
     *
     * @param array{lang?:string,language?:string,document_lang?:string,locale?:string,post_id?:int} $options
     * @return array<int,array{text:string,weight:float,lang:string,explicit_lang?:bool,detect_group?:int}>
     */
    private function extractHtmlSegments(string $html, array $options): array
    {
        $documentLang = $this->resolveDocumentLanguage($options);
        $autoDetect = $this->shouldAutoDetectDocumentLanguage($options);
        // Inline ancestry is represented as a request-local persistent trie.
        // Segments retain one integer node ID instead of copying the complete
        // ancestor path into every text run. This keeps deeply nested markup
        // linear in source tokens plus element depth rather than their product.
        $inlinePathParents = [0 => 0];
        $inlinePathDepths = [0 => 0];
        $inlinePathChildren = [];

        if ($this->htmlProcessorFactory !== null) {
            $processor = $this->createProcessor($html);
            if ($processor === null) {
                $segments = $this->extractWithFallbackParser(
                    $html,
                    $documentLang,
                    $inlinePathParents,
                    $inlinePathDepths,
                    $inlinePathChildren
                );
            } else {
                $segments = $this->extractWithProcessor(
                    $processor,
                    $documentLang,
                    $inlinePathParents,
                    $inlinePathDepths,
                    $inlinePathChildren
                );
            }
        } else {
            $segments = $this->extractWithFallbackParser(
                $html,
                $documentLang,
                $inlinePathParents,
                $inlinePathDepths,
                $inlinePathChildren
            );
        }

        return $this->coalesceInlineLexicalSegments(
            $this->maybeDetectSegmentLanguages($segments, $documentLang, $autoDetect),
            $inlinePathParents,
            $inlinePathDepths
        );
    }

    /**
     * @param array<int,array{text:string,weight:float,lang:string,explicit_lang?:bool,detect_group?:int}> $segments
     * @return array<int,array{text:string,weight:float,lang:string,explicit_lang?:bool,detect_group?:int}>
     */
    private function maybeDetectSegmentLanguages(array $segments, string $documentLang, bool $autoDetect): array
    {
        if (!$autoDetect || $this->languageDetector === null) {
            return $segments;
        }

        $groups = [];
        foreach ($segments as $index => $segment) {
            if (!empty($segment['explicit_lang']) || ($segment['lang'] ?? '') !== $documentLang) {
                continue;
            }

            $group = isset($segment['detect_group']) ? (int) $segment['detect_group'] : $index;
            $groups[$group]['indexes'][] = $index;
            $groups[$group]['text'][] = (string) ($segment['text'] ?? '');
        }

        foreach ($groups as $group) {
            $text = implode('', $group['text'] ?? []);
            if (trim($text) === '') {
                continue;
            }

            $lang = $this->detectSegmentLanguage($text, $documentLang);
            foreach ($group['indexes'] ?? [] as $index) {
                $segments[$index]['lang'] = $lang;
            }
        }

        return $segments;
    }

    /**
     * Detect a segment language, falling back to the resolved document language.
     */
    private function detectSegmentLanguage(string $text, string $documentLang): string
    {
        if ($this->languageDetector === null) {
            return $documentLang;
        }

        return $this->languageDetector->detect_text($text) ?? $documentLang;
    }

    /**
     * Create a caller-provided HTML processor for a full document or fragment.
     *
     * Processor creation is wrapped in a catch block because invalid markup or
     * version differences can throw; callers fall back to parser logic on null.
     *
     * @param string $html HTML document or fragment.
     * @return mixed Processor-like object, or null when creation fails.
     */
    private function createProcessor(string $html): mixed
    {
        try {
            $processor = ($this->htmlProcessorFactory)($html);
        } catch (Throwable) {
            return null;
        }

        // get_current_depth() was added to WP_HTML_Processor in WordPress 6.6.
        // Earlier and partial processor implementations fall back to the local
        // streaming parser rather than forcing a full breadcrumb snapshot on
        // every token. The remaining methods form the event-stream contract
        // used below; accepting less would reintroduce a second parser model.
        foreach ([
            'next_token',
            'get_current_depth',
            'get_token_type',
            'get_tag',
            'is_tag_closer',
            'expects_closer',
            'get_modifiable_text',
        ] as $method) {
            if (!is_object($processor) || !method_exists($processor, $method)) {
                return null;
            }
        }

        return $processor;
    }

    /**
     * Heuristically decide whether input looks like a full HTML document.
     *
     * @param string $html HTML input from the caller.
     * @return bool True when document-level tags or doctype are present.
     */
    private function looksLikeFullDocument(string $html): bool
    {
        return (bool) preg_match('/<(?:!doctype|html|head|title)\b/i', $html);
    }

    /**
     * Extract text with a processor-like object while tracking language scopes.
     *
     * Each opener derives one scalar state row from its parent, and each closer
     * pops that row. WP_HTML_Processor emits virtual close/open events for
     * implicit HTML structure, so language, skip, boost, inline-path, and text-
     * group state remain aligned without requesting or walking breadcrumbs.
     * Every row is pushed and popped once, making extraction linear in provider
     * tokens plus maximum element depth.
     *
     * @return array<int,array{text:string,weight:float,lang:string,explicit_lang?:bool,detect_group?:int}>
     */
    private function extractWithProcessor(
        mixed $processor,
        string $documentLang,
        array &$inlinePathParents,
        array &$inlinePathDepths,
        array &$inlinePathChildren
    ): array
    {
        $segments = [];
        $states = [
            0 => [
                'lang' => $documentLang,
                'explicit_lang' => false,
                'skipped' => false,
                'weight' => 1.0,
                'inline_path_id' => 0,
                'nearest_text_group_depth' => null,
                'detect_group' => null,
            ],
        ];
        $activeDepth = 0;
        $providerBaseDepth = null;
        $textGroupCounter = 0;
        $rootTextGroup = null;
        $processorTokens = 0;
        $processorOutputBytes = 0;
        $processorControlBytes = 0;

        while ($processor->next_token()) {
            // One text run may appear before, between, and after the bounded
            // markup tokens. Count provider tokens independently: a custom
            // processor can otherwise return empty/non-text tokens forever
            // after the source-byte and source-markup checks have passed.
            if (++$processorTokens > (WP_FTS_Analysis_Limits::MAX_HTML_MARKUP_TOKENS * 2) + 1) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'html_markup_tokens',
                    'FTS document HTML processor exceeds its bounded token envelope.'
                );
            }
            $tokenType = $processor->get_token_type();
            $tokenType = is_scalar($tokenType) ? (string) $tokenType : '';
            $tokenTypeBytes = strlen($tokenType);
            if ($tokenTypeBytes > self::HTML_PROCESSOR_TOKEN_TYPE_BYTES) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'html_processor_token_type_bytes',
                    'FTS document HTML processor returned an oversized token type.'
                );
            }
            $this->chargeProcessorOutputBytes($processorControlBytes, $tokenTypeBytes);
            $providerDepth = $processor->get_current_depth();
            if (
                !is_int($providerDepth)
                || $providerDepth < 0
                || $providerDepth > WP_FTS_Analysis_Limits::MAX_HTML_ELEMENT_DEPTH + self::HTML_PROCESSOR_DEPTH_OVERHEAD
            ) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'html_element_depth',
                    'FTS document HTML processor exceeds its bounded structural depth.'
                );
            }

            $isCloser = $tokenType === '#tag' && $processor->is_tag_closer();
            if ($providerBaseDepth === null) {
                // Fragment parsers begin below implicit HTML/BODY roots, while
                // full-document parsers begin at depth zero. The first event
                // identifies that fixed filler prefix without enumerating it.
                $providerBaseDepth = max(0, $providerDepth - ($isCloser ? 0 : 1));
            }
            $relativeDepth = $providerDepth - $providerBaseDepth;
            if ($relativeDepth < 0) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'html_element_depth',
                    'FTS document HTML processor produced an invalid depth transition.'
                );
            }

            if ($tokenType === '#tag') {
                $tag = $this->processorCurrentTag($processor, $processorOutputBytes);
                if ($isCloser) {
                    $this->popProcessorStates($states, $activeDepth, $relativeDepth);
                    continue;
                }

                if ($relativeDepth < 1 || $relativeDepth > WP_FTS_Analysis_Limits::MAX_HTML_ELEMENT_DEPTH) {
                    throw new WP_FTS_Analysis_Limit_Exceeded(
                        'html_element_depth',
                        'FTS document HTML processor exceeds the 256-element state-stack limit.'
                    );
                }
                $parentDepth = $relativeDepth - 1;
                $this->popProcessorStates($states, $activeDepth, $parentDepth);
                $parent = $states[$parentDepth] ?? $states[0];

                $isBoundary = $tag !== null && $this->isTextGroupBoundaryTag($tag);
                if ($isBoundary) {
                    $this->retireProcessorTextGroup($states, $parentDepth, $rootTextGroup);
                }
                // Atomic tags include HTML void elements, SCRIPT/STYLE/TITLE,
                // and self-closing foreign content. WP_HTML_Processor emits no
                // later pop event for them, so retaining a row here would leak
                // skip, boost, or language state into the following sibling.
                if ($processor->expects_closer() !== true) {
                    continue;
                }

                $declaredLang = $this->processorLangAttribute($processor, $processorOutputBytes);
                $inlinePathId = $tag === null
                    ? (int) $parent['inline_path_id']
                    : $this->internInlinePath(
                        (int) $parent['inline_path_id'],
                        $tag,
                        $inlinePathParents,
                        $inlinePathDepths,
                        $inlinePathChildren
                    );
                $detectGroup = null;
                $nearestTextGroupDepth = $parent['nearest_text_group_depth'];
                if ($isBoundary) {
                    $textGroupCounter++;
                    $detectGroup = $textGroupCounter;
                    $nearestTextGroupDepth = $relativeDepth;
                }

                $states[$relativeDepth] = [
                    'lang' => $declaredLang ?? (string) $parent['lang'],
                    'explicit_lang' => $declaredLang !== null
                        ? true
                        : (bool) $parent['explicit_lang'],
                    'skipped' => (bool) $parent['skipped']
                        || ($tag !== null && isset($this->skipAncestors[$tag])),
                    'weight' => max(
                        (float) $parent['weight'],
                        (float) ($tag !== null ? ($this->boosts[$tag] ?? 1.0) : 1.0)
                    ),
                    'inline_path_id' => $inlinePathId,
                    'nearest_text_group_depth' => $nearestTextGroupDepth,
                    'detect_group' => $detectGroup,
                ];
                $activeDepth = $relativeDepth;
                continue;
            }

            if ($tokenType !== '#text') {
                continue;
            }

            // WP_HTML_Processor includes the current #text pseudo-node in its
            // reported depth; test/custom processors commonly report just the
            // active element depth. Both are O(1) event-stream conventions.
            if ($relativeDepth !== $activeDepth && $relativeDepth !== $activeDepth + 1) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'html_element_depth',
                    'FTS document HTML processor produced an invalid text depth transition.'
                );
            }
            $state = $states[$activeDepth] ?? $states[0];
            if ($state['skipped']) {
                continue;
            }

            $text = $processor->get_modifiable_text();
            if (!is_scalar($text)) {
                continue;
            }
            $text = (string) $text;
            $this->chargeProcessorOutputBytes($processorOutputBytes, strlen($text));
            if (trim($text) === '') {
                continue;
            }

            $segments[] = [
                'text' => $text,
                'weight' => (float) $state['weight'],
                'lang' => (string) $state['lang'],
                'explicit_lang' => (bool) $state['explicit_lang'],
                'detect_group' => $this->currentProcessorTextGroup(
                    $states,
                    $activeDepth,
                    $rootTextGroup,
                    $textGroupCounter
                ),
                'inline_path_id' => (int) $state['inline_path_id'],
            ];
        }

        return $segments;
    }

    /** Pop each retained element state once as the processor leaves its scope. */
    private function popProcessorStates(array &$states, int &$activeDepth, int $targetDepth): void
    {
        if ($targetDepth < 0 || $targetDepth > $activeDepth) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'html_element_depth',
                'FTS document HTML processor produced an invalid stack transition.'
            );
        }
        while ($activeDepth > $targetDepth) {
            unset($states[$activeDepth]);
            $activeDepth--;
        }
    }

    /** Retire the parent run before a child block boundary begins. */
    private function retireProcessorTextGroup(array &$states, int $depth, ?int &$rootTextGroup): void
    {
        $nearestDepth = $states[$depth]['nearest_text_group_depth'] ?? null;
        if ($nearestDepth !== null && isset($states[$nearestDepth])) {
            $states[$nearestDepth]['detect_group'] = null;
            return;
        }

        $rootTextGroup = null;
    }

    /** Return or allocate the active block's language-detection group. */
    private function currentProcessorTextGroup(
        array &$states,
        int $depth,
        ?int &$rootTextGroup,
        int &$textGroupCounter
    ): int {
        $nearestDepth = $states[$depth]['nearest_text_group_depth'] ?? null;
        if ($nearestDepth !== null && isset($states[$nearestDepth])) {
            if ($states[$nearestDepth]['detect_group'] === null) {
                $textGroupCounter++;
                $states[$nearestDepth]['detect_group'] = $textGroupCounter;
            }

            return (int) $states[$nearestDepth]['detect_group'];
        }

        if ($rootTextGroup === null) {
            $textGroupCounter++;
            $rootTextGroup = $textGroupCounter;
        }

        return $rootTextGroup;
    }

    /**
     * Test and non-WordPress fallback parser. It is deliberately small, but keeps a
     * tag stack so skip, boost, and lang decisions follow the ancestor model.
     *
     * The parser closes selected optional end tags before pushing a new opener.
     * That mirrors common HTML behavior well enough to keep `<p lang=en>...<p
     * lang=de>...` from treating the second paragraph as nested inside the first.
     *
     * @param string $html HTML document or fragment.
     * @param string $documentLang Fallback language for text outside scoped tags.
     * @return array<int,array{text:string,weight:float,lang:string,explicit_lang?:bool,detect_group?:int}>
     */
    private function extractWithFallbackParser(
        string $html,
        string $documentLang,
        array &$inlinePathParents,
        array &$inlinePathDepths,
        array &$inlinePathChildren
    ): array
    {
        $stack = [];
        $segments = [];
        $textGroupCounter = 0;
        $rootTextGroup = null;
        $voidTags = array_fill_keys([
            'AREA',
            'BASE',
            'BR',
            'COL',
            'EMBED',
            'HR',
            'IMG',
            'INPUT',
            'LINK',
            'META',
            'PARAM',
            'SOURCE',
            'TRACK',
            'WBR',
        ], true);
        $opaqueTags = array_fill_keys([
            'NOSCRIPT',
            'SCRIPT',
            'STYLE',
            'TEMPLATE',
        ], true);

        foreach ($this->fallbackHtmlTokens($html) as $token) {
            $part = $token['raw'];
            if ($part === '') {
                continue;
            }

            if ($token['type'] !== 'text') {
                if ($token['type'] !== 'tag') {
                    continue;
                }

                $tag = $this->fallbackTagDescriptor($part);
                if ($tag === null) {
                    continue;
                }

                if ($tag['closing']) {
                    for ($i = count($stack) - 1; $i >= 0; $i--) {
                        if ($stack[$i]['tag'] === $tag['name']) {
                            array_splice($stack, $i);
                            break;
                        }
                        // Markup-looking text inside these elements cannot close
                        // an ancestor outside their hidden parsing scope.
                        if (isset($opaqueTags[$stack[$i]['tag']])) {
                            break;
                        }
                    }
                    continue;
                }

                $opening = $tag['name'];
                $this->closeFallbackOptionalEndTags($stack, $opening);
                $isAtomic = isset($voidTags[$opening]) || $this->fallbackTagIsSelfClosing($part);
                if ($isAtomic && $this->isTextGroupBoundaryTag($opening)) {
                    $this->retireFallbackCurrentTextGroup($stack, $rootTextGroup);
                }
                if (!$isAtomic) {
                    $parent = $stack === [] ? null : $stack[count($stack) - 1];
                    $isTextGroupBoundary = $this->isTextGroupBoundaryTag($opening);
                    $detectGroup = null;
                    if ($isTextGroupBoundary) {
                        $this->retireFallbackCurrentTextGroup($stack, $rootTextGroup);
                        $textGroupCounter++;
                        $detectGroup = $textGroupCounter;
                    }
                    $declaredLang = $this->tagLangAttribute($part);
                    $inlinePathId = $this->internInlinePath(
                        (int) ($parent['inline_path_id'] ?? 0),
                        $opening,
                        $inlinePathParents,
                        $inlinePathDepths,
                        $inlinePathChildren
                    );
                    $depth = count($stack);
                    $stack[] = [
                        'tag' => $opening,
                        'lang' => $declaredLang ?? ($parent['lang'] ?? $documentLang),
                        'explicit_lang' => $declaredLang !== null
                            ? true
                            : (bool) ($parent['explicit_lang'] ?? false),
                        'skipped' => (bool) ($parent['skipped'] ?? false)
                            || isset($this->skipAncestors[$opening]),
                        'weight' => max(
                            (float) ($parent['weight'] ?? 1.0),
                            (float) ($this->boosts[$opening] ?? 1.0)
                        ),
                        'inline_path_id' => $inlinePathId,
                        'detect_group' => $detectGroup,
                        'text_group_boundary' => $isTextGroupBoundary,
                        'nearest_text_group_depth' => $isTextGroupBoundary
                            ? $depth
                            : ($parent['nearest_text_group_depth'] ?? null),
                    ];
                }
                continue;
            }

            $scope = $stack === [] ? null : $stack[count($stack) - 1];
            if (!empty($scope['skipped'])) {
                continue;
            }

            $text = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (trim($text) === '') {
                continue;
            }
            $segments[] = [
                'text' => $text,
                'weight' => (float) ($scope['weight'] ?? 1.0),
                'lang' => (string) ($scope['lang'] ?? $documentLang),
                'explicit_lang' => (bool) ($scope['explicit_lang'] ?? false),
                'detect_group' => $this->fallbackCurrentTextGroup($stack, $rootTextGroup, $textGroupCounter),
                'inline_path_id' => (int) ($scope['inline_path_id'] ?? 0),
            ];
        }

        return $segments;
    }

    /**
     * Stream fallback HTML into text, tag, and declaration tokens.
     *
     * Tag boundaries are found one byte at a time so `>` inside a quoted
     * attribute cannot end a tag. Invalid `<` sequences stay visible text.
     * SCRIPT data and STYLE raw text use the shared byte-stream boundary logic,
     * preventing tag-looking code from changing this fallback event stream.
     *
     * @return iterable<int,array{type:'text'|'tag'|'declaration',raw:string}>
     */
    private function fallbackHtmlTokens(string $html): iterable
    {
        $length = strlen($html);
        $offset = 0;

        while ($offset < $length) {
            $tagStart = strpos($html, '<', $offset);
            if ($tagStart === false) {
                yield ['type' => 'text', 'raw' => substr($html, $offset)];
                break;
            }

            if ($tagStart > $offset) {
                yield ['type' => 'text', 'raw' => substr($html, $offset, $tagStart - $offset)];
            }

            if (substr($html, $tagStart, 4) === '<!--') {
                $commentEnd = strpos($html, '-->', $tagStart + 4);
                $end = $commentEnd === false ? $length : $commentEnd + 3;
                yield ['type' => 'declaration', 'raw' => substr($html, $tagStart, $end - $tagStart)];
                $offset = $end;
                continue;
            }

            if (substr($html, $tagStart, 9) === '<![CDATA[') {
                $cdataEnd = strpos($html, ']]>', $tagStart + 9);
                $end = $cdataEnd === false ? $length : $cdataEnd + 3;
                yield ['type' => 'declaration', 'raw' => substr($html, $tagStart, $end - $tagStart)];
                $offset = $end;
                continue;
            }

            $tagEnd = $this->fallbackMarkupEndOffset($html, $tagStart + 1);
            if ($tagEnd === null) {
                yield ['type' => 'text', 'raw' => substr($html, $tagStart)];
                break;
            }

            $raw = substr($html, $tagStart, $tagEnd - $tagStart + 1);
            $tag = $this->fallbackTagDescriptor($raw);
            if ($tag !== null) {
                yield ['type' => 'tag', 'raw' => $raw];
                $offset = $tagEnd + 1;
                if (
                    !$tag['closing']
                    && ($tag['name'] === 'SCRIPT' || $tag['name'] === 'STYLE')
                    && !$this->fallbackTagIsSelfClosing($raw)
                ) {
                    $closingTagStart = null;
                    $elementEnd = WP_FTS_Html_Text_Stream::hidden_text_element_end_offset(
                        $html,
                        $offset,
                        $tag['name'],
                        $closingTagStart
                    );
                    $contentEnd = $closingTagStart ?? $elementEnd;
                    if ($contentEnd > $offset) {
                        yield ['type' => 'text', 'raw' => substr($html, $offset, $contentEnd - $offset)];
                    }
                    if ($closingTagStart !== null) {
                        yield [
                            'type' => 'tag',
                            'raw' => substr($html, $closingTagStart, $elementEnd - $closingTagStart),
                        ];
                    }
                    $offset = $elementEnd;
                }
                continue;
            }

            $marker = $raw[1] ?? '';
            if ($marker === '!' || $marker === '?') {
                yield ['type' => 'declaration', 'raw' => $raw];
                $offset = $tagEnd + 1;
                continue;
            }

            yield ['type' => 'text', 'raw' => '<'];
            $offset = $tagStart + 1;
        }
    }

    /**
     * Find a markup-closing `>` while respecting quoted attribute values.
     */
    private function fallbackMarkupEndOffset(string $html, int $offset): ?int
    {
        $quote = null;
        $length = strlen($html);
        for (; $offset < $length; $offset++) {
            $char = $html[$offset];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if ($char === '>') {
                return $offset;
            }
        }

        return null;
    }

    /**
     * Read a fallback tag name and whether the token closes that tag.
     *
     * @return array{name:string,closing:bool,name_end:int}|null
     */
    private function fallbackTagDescriptor(string $tag): ?array
    {
        $length = strlen($tag);
        if ($length < 3 || $tag[0] !== '<') {
            return null;
        }

        $offset = 1;
        while ($offset < $length && $this->isHtmlWhitespace($tag[$offset])) {
            $offset++;
        }

        $closing = $offset < $length && $tag[$offset] === '/';
        if ($closing) {
            $offset++;
            while ($offset < $length && $this->isHtmlWhitespace($tag[$offset])) {
                $offset++;
            }
        }

        if ($offset >= $length || !$this->isFallbackTagNameStart($tag[$offset])) {
            return null;
        }

        $start = $offset;
        while ($offset < $length && $this->isFallbackTagNameByte($tag[$offset])) {
            $offset++;
        }

        return [
            'name' => strtoupper(substr($tag, $start, $offset - $start)),
            'closing' => $closing,
            'name_end' => $offset,
        ];
    }

    private function fallbackTagIsSelfClosing(string $tag): bool
    {
        $offset = strlen($tag) - 2;
        while ($offset >= 0 && $this->isHtmlWhitespace($tag[$offset])) {
            $offset--;
        }

        return $offset >= 0 && $tag[$offset] === '/';
    }

    private function isFallbackTagNameStart(string $byte): bool
    {
        $ord = ord($byte);

        return ($ord >= 65 && $ord <= 90) || ($ord >= 97 && $ord <= 122);
    }

    private function isFallbackTagNameByte(string $byte): bool
    {
        $ord = ord($byte);

        return $this->isFallbackTagNameStart($byte)
            || ($ord >= 48 && $ord <= 57)
            || $byte === ':'
            || $byte === '-';
    }

    /**
     * Merge lexical words split across inline tags after language detection.
     *
     * Text nodes remain separate until this point so HTML language scopes,
     * skipped ancestors, boosts, and block boundaries are still computed from
     * syntax tokens. The merge is lexical: only adjacent word chunks in the same
     * visible-text group are joined, while whitespace, punctuation, and block
     * groups keep their boundary behavior.
     *
     * @param array<int,array{text:string,weight:float,lang:string,explicit_lang?:bool,detect_group?:int}> $segments
     * @return array<int,array{text:string,weight:float,lang:string,explicit_lang?:bool,detect_group?:int}>
     */
    private function coalesceInlineLexicalSegments(
        array $segments,
        array $inlinePathParents,
        array $inlinePathDepths
    ): array
    {
        $coalesced = [];
        $openIndex = null;

        foreach ($segments as $index => $segment) {
            // Release the retained text-node row as soon as its lexical chunks
            // have local ownership. At the boundary this avoids holding two
            // complete segment arrays while coalescing a maximum-size input.
            unset($segments[$index]);
            foreach ($this->lexicalChunks((string) $segment['text']) as $chunk) {
                if (!$chunk['word']) {
                    $openIndex = null;
                    continue;
                }

                $candidate = $segment;
                $candidate['text'] = $chunk['text'];
                if (
                    $openIndex !== null
                    && isset($coalesced[$openIndex])
                    && $this->canMergeLexicalSegments(
                        $coalesced[$openIndex],
                        $candidate,
                        $inlinePathParents,
                        $inlinePathDepths
                    )
                ) {
                    $mergedBytes = strlen($coalesced[$openIndex]['text']) + strlen($chunk['text']);
                    // Check the complete cross-element lexical run before .=
                    // can repeatedly copy an ever-growing string. The same
                    // 4-KiB invariant applies whether markup split the word or
                    // it arrived in one text token.
                    WP_FTS_Analysis_Limits::assert_lexical_run_bytes($mergedBytes);
                    $coalesced[$openIndex]['text'] .= $chunk['text'];
                    $coalesced[$openIndex]['weight'] = max(
                        (float) ($coalesced[$openIndex]['weight'] ?? 1.0),
                        (float) ($candidate['weight'] ?? 1.0)
                    );
                    continue;
                }

                $coalesced[] = $candidate;
                $openIndex = count($coalesced) - 1;
            }
        }

        return $coalesced;
    }

    /**
     * @param array{text:string,weight:float,lang:string,explicit_lang?:bool,detect_group?:int} $left
     * @param array{text:string,weight:float,lang:string,explicit_lang?:bool,detect_group?:int} $right
     */
    private function canMergeLexicalSegments(
        array $left,
        array $right,
        array $inlinePathParents,
        array $inlinePathDepths
    ): bool
    {
        return ($left['lang'] ?? '') === ($right['lang'] ?? '')
            && (bool) ($left['explicit_lang'] ?? false) === (bool) ($right['explicit_lang'] ?? false)
            && ($left['detect_group'] ?? null) === ($right['detect_group'] ?? null)
            && $this->inlinePathsCompatible(
                (int) ($left['inline_path_id'] ?? 0),
                (int) ($right['inline_path_id'] ?? 0),
                $inlinePathParents,
                $inlinePathDepths
            );
    }

    /**
     * Paths are compatible when either tag-name sequence is a prefix of the
     * other. Aligning persistent trie nodes by depth preserves the previous
     * sequence semantics without materializing either ancestor array.
     */
    private function inlinePathsCompatible(
        int $left,
        int $right,
        array $inlinePathParents,
        array $inlinePathDepths
    ): bool {
        if (!isset($inlinePathDepths[$left])) {
            $left = 0;
        }
        if (!isset($inlinePathDepths[$right])) {
            $right = 0;
        }

        $leftDepth = $inlinePathDepths[$left];
        $rightDepth = $inlinePathDepths[$right];
        while ($leftDepth > $rightDepth) {
            $left = $inlinePathParents[$left];
            $leftDepth--;
        }
        while ($rightDepth > $leftDepth) {
            $right = $inlinePathParents[$right];
            $rightDepth--;
        }

        return $left === $right;
    }

    /**
     * @return iterable<int,array{text:string,word:bool}>
     */
    private function lexicalChunks(string $text): iterable
    {
        $text = $this->languagePipeline->normalize_unicode_text($text);
        if ($text === '') {
            return;
        }

        $currentWord = '';
        $currentWordBytes = 0;
        $insideNonWord = false;
        $length = strlen($text);
        for ($offset = 0; $offset < $length;) {
            $charLength = $this->utf8CharacterLength($text, $offset);
            $char = substr($text, $offset, $charLength);
            $offset += $charLength;
            if (!$this->isLexicalWordCharacter($char)) {
                if ($currentWord !== '') {
                    yield ['text' => $currentWord, 'word' => true];
                    $currentWord = '';
                    $currentWordBytes = 0;
                }
                $insideNonWord = true;
                continue;
            }

            if ($insideNonWord) {
                // Callers only need to know that a lexical boundary occurred;
                // retaining a multi-megabyte punctuation run has no value.
                yield ['text' => '', 'word' => false];
                $insideNonWord = false;
            }
            $currentWordBytes += $charLength;
            WP_FTS_Analysis_Limits::assert_lexical_run_bytes($currentWordBytes);
            $currentWord .= $char;
        }

        if ($currentWord !== '') {
            yield ['text' => $currentWord, 'word' => true];
        } elseif ($insideNonWord) {
            yield ['text' => '', 'word' => false];
        }
    }

    /** Read one repaired UTF-8 codepoint without materializing a character array. */
    private function utf8CharacterLength(string $text, int $offset): int
    {
        $byte = ord($text[$offset]);
        if ($byte < 0x80) {
            return 1;
        }
        if (($byte & 0xE0) === 0xC0) {
            return 2;
        }
        if (($byte & 0xF0) === 0xE0) {
            return 3;
        }
        if (($byte & 0xF8) === 0xF0) {
            return 4;
        }

        return 1;
    }

    private function isLexicalWordCharacter(string $char): bool
    {
        return $char !== '' && preg_match('/^[\p{L}\p{M}\p{N}_]$/u', $char) === 1;
    }

    /**
     * Intern one non-boundary tag in the request-local inline-path trie.
     *
     * Defensive custom processor events may expose pseudo names such as
     * `#text`; those are not element boundaries. Block tags likewise do not
     * participate in inline word continuity. Interning tag-name sequences (not
     * element identities) preserves compatibility across same-shaped sibling
     * markup while every segment retains only one integer ID.
     */
    private function internInlinePath(
        int $parentId,
        string $tag,
        array &$inlinePathParents,
        array &$inlinePathDepths,
        array &$inlinePathChildren
    ): int {
        if ($tag === '' || str_starts_with($tag, '#') || $this->isTextGroupBoundaryTag($tag)) {
            return $parentId;
        }

        if (isset($inlinePathChildren[$parentId][$tag])) {
            return $inlinePathChildren[$parentId][$tag];
        }

        // A normal parser creates at most one node per opening markup token. A
        // custom processor can synthesize unrelated tag-event paths, so enforce
        // the same markup-token envelope before retaining a new node.
        WP_FTS_Analysis_Limits::assert_html_markup_tokens(count($inlinePathParents));
        $nodeId = count($inlinePathParents);
        $inlinePathParents[$nodeId] = $parentId;
        $inlinePathDepths[$nodeId] = $inlinePathDepths[$parentId] + 1;
        $inlinePathChildren[$parentId][$tag] = $nodeId;

        return $nodeId;
    }

    /**
     * Resolve the primary document language for HTML extraction.
     *
     * Precedence is explicit caller hints (`lang`, `language`, `document_lang`,
     * `locale`), constructor `document_lang`, custom resolver, per-call
     * `default_lang`, then analyzer default.
     *
     * @param array<string,mixed> $options
     * @return string Canonical document language.
     */
    private function resolveDocumentLanguage(array $options): string
    {
        return $this->firstLanguage([
            $options['lang'] ?? null,
            $options['language'] ?? null,
            $options['document_lang'] ?? null,
            $options['locale'] ?? null,
            $this->documentLanguage,
            $this->callLanguageResolver($this->documentLanguageResolver, $options),
            $options['default_lang'] ?? null,
            $this->defaultLanguage,
        ]) ?? 'en';
    }

    /**
     * Resolve the language used for query analysis.
     *
     * Precedence is explicit query hints, constructor `query_lang`, custom
     * resolver, per-call `default_lang`, then analyzer default.
     *
     * @param array<string,mixed> $options
     * @return string Canonical query language.
     */
    private function resolveQueryLanguage(array $options): string
    {
        return $this->firstLanguage([
            $options['lang'] ?? null,
            $options['language'] ?? null,
            $options['query_lang'] ?? null,
            $options['locale'] ?? null,
            $this->queryLanguage,
            $this->callLanguageResolver($this->queryLanguageResolver, $options),
            $options['default_lang'] ?? null,
            $this->defaultLanguage,
        ]) ?? 'en';
    }

    /**
     * Return the first scalar language candidate that canonicalizes cleanly.
     *
     * @param array<int,mixed> $candidates
     * @return string|null Canonical language, or null when no candidate is valid.
     */
    private function firstLanguage(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $canonical = $this->canonicalLanguage($candidate);
            if ($canonical !== null) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * Canonicalize a mixed language candidate or reject it.
     *
     * Values like `en_US.UTF-8` become `en-US`; empty, non-scalar, `C`, and
     * `POSIX` candidates are ignored so they do not become searchable language
     * partitions.
     *
     * @param mixed $language Candidate from options, HTML, WordPress, or a
     *        resolver callback.
     * @return string|null Canonical language accepted by the pipeline, or null.
     */
    private function canonicalLanguage(mixed $language): ?string
    {
        if (!is_scalar($language)) {
            return null;
        }

        if (strlen((string) $language) > WP_FTS_Analysis_Limits::MAX_HTML_LANGUAGE_ATTRIBUTE_BYTES) {
            return null;
        }

        $language = trim((string) $language);
        if ($language === '') {
            return null;
        }

        $language = html_entity_decode($language, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $language = preg_replace('/[.@].*$/', '', $language) ?? $language;
        $language = str_replace('_', '-', $language);
        $language = preg_replace('/[^A-Za-z0-9-]+/', '-', $language) ?? $language;
        $language = trim($language, '-');
        if ($language === '') {
            return null;
        }

        $parts = array_values(array_filter(explode('-', $language), static fn(string $part): bool => $part !== ''));
        if ($parts === [] || count($parts) > WP_FTS_Analysis_Limits::MAX_LANGUAGE_SUBTAGS) {
            return null;
        }

        $primary = strtolower(array_shift($parts));
        if (!preg_match('/^[a-z]{2,3}$/', $primary) || in_array($primary, ['c', 'posix'], true)) {
            return null;
        }

        $canonical = [$primary];
        foreach ($parts as $part) {
            if (preg_match('/^[A-Za-z]{4}$/', $part)) {
                $canonical[] = ucfirst(strtolower($part));
            } elseif (preg_match('/^(?:[A-Za-z]{2}|\d{3})$/', $part)) {
                $canonical[] = strtoupper($part);
            } else {
                $canonical[] = strtolower($part);
            }
        }

        return $this->languagePipeline->canonicalize_language(implode('-', $canonical));
    }

    /**
     * Canonicalize a language for static namespace helpers.
     *
     * Unlike `canonicalLanguage()`, invalid or empty input returns `und` because
     * static term namespacing has no analyzer instance or default language.
     *
     * @param string $language Language tag or locale.
     * @return string Canonical language or `und`.
     */
    private static function canonicalLanguageStatic(string $language): string
    {
        $language = trim(str_replace('_', '-', $language));
        if ($language === '') {
            return 'und';
        }

        $parts = array_values(array_filter(explode('-', $language), static fn(string $part): bool => $part !== ''));
        if ($parts === []) {
            return 'und';
        }

        $canonical = [];
        foreach ($parts as $index => $part) {
            $part = preg_replace('/[^A-Za-z0-9]/', '', $part) ?? '';
            if ($part === '') {
                continue;
            }

            if ($index === 0) {
                $canonical[] = strtolower($part);
            } elseif (strlen($part) === 4 && self::isAsciiAlpha($part)) {
                $canonical[] = ucfirst(strtolower($part));
            } elseif ((strlen($part) === 2 && self::isAsciiAlpha($part)) || (strlen($part) === 3 && self::isAsciiDigit($part))) {
                $canonical[] = strtoupper($part);
            } else {
                $canonical[] = strtolower($part);
            }
        }

        return $canonical === [] ? 'und' : implode('-', $canonical);
    }

    /**
     * Check whether a language subtag contains only ASCII letters.
     */
    private static function isAsciiAlpha(string $value): bool
    {
        return $value !== '' && preg_match('/^[A-Za-z]+$/', $value) === 1;
    }

    /**
     * Check whether a language subtag contains only ASCII digits.
     */
    private static function isAsciiDigit(string $value): bool
    {
        return $value !== '' && preg_match('/^[0-9]+$/', $value) === 1;
    }

    /**
     * Call an optional language resolver without letting resolver failures leak.
     *
     * Resolver callbacks are extension points, not required infrastructure. A
     * thrown exception or non-scalar result is treated as "no language found".
     *
     * @param array<string,mixed> $options
     * @return string|null Raw scalar language candidate from the resolver.
     */
    private function callLanguageResolver(?callable $resolver, array $options): ?string
    {
        if ($resolver === null) {
            return null;
        }

        try {
            $resolved = $resolver($options);
        } catch (Throwable) {
            return null;
        }

        return is_scalar($resolved) ? (string) $resolved : null;
    }

    /**
     * Decide whether untagged document text may be language-detected.
     *
     * Explicit caller language and constructor/resolver languages remain
     * authoritative. Site locale and analyzer default are fallbacks, so detector
     * evidence is allowed to beat them for untagged content.
     *
     * @param array<string,mixed> $options
     */
    private function shouldAutoDetectDocumentLanguage(array $options): bool
    {
        if (!$this->autoDetectLanguage || $this->languageDetector === null) {
            return false;
        }

        foreach (['lang', 'language', 'document_lang', 'locale'] as $key) {
            if (isset($options[$key]) && $this->canonicalLanguage($options[$key]) !== null) {
                return false;
            }
        }

        if ($this->documentLanguage !== null) {
            return false;
        }

        if ($this->canonicalLanguage($this->callLanguageResolver($this->documentLanguageResolver, $options)) !== null) {
            return false;
        }

        return true;
    }

    /**
     * Decide whether untagged query text may be language-detected.
     *
     * @param array<string,mixed> $options
     */
    private function shouldAutoDetectQueryLanguage(array $options): bool
    {
        if (!$this->autoDetectLanguage || $this->languageDetector === null) {
            return false;
        }

        foreach (['lang', 'language', 'query_lang', 'locale'] as $key) {
            if (isset($options[$key]) && $this->canonicalLanguage($options[$key]) !== null) {
                return false;
            }
        }

        if ($this->queryLanguage !== null) {
            return false;
        }

        if ($this->canonicalLanguage($this->callLanguageResolver($this->queryLanguageResolver, $options)) !== null) {
            return false;
        }

        return true;
    }

    /**
     * Return the current processor tag name when available.
     */
    private function processorCurrentTag(mixed $processor, int &$processorOutputBytes): ?string
    {
        $tag = $processor->get_tag();
        if (!is_scalar($tag)) {
            return null;
        }

        $tag = (string) $tag;
        $tagBytes = strlen($tag);
        WP_FTS_Analysis_Limits::assert_html_tag_bytes($tagBytes);
        $this->chargeProcessorOutputBytes($processorOutputBytes, $tagBytes);

        return trim($tag) === '' ? null : strtoupper($tag);
    }

    /**
     * Decide whether an element starts a new visible-text detection group.
     */
    private function isTextGroupBoundaryTag(string $tag): bool
    {
        static $boundaryTags = [
            'ADDRESS' => true,
            'ARTICLE' => true,
            'ASIDE' => true,
            'BLOCKQUOTE' => true,
            'BODY' => true,
            'BR' => true,
            'DD' => true,
            'DETAILS' => true,
            'DIALOG' => true,
            'DIV' => true,
            'DL' => true,
            'DT' => true,
            'FIELDSET' => true,
            'FIGCAPTION' => true,
            'FIGURE' => true,
            'FOOTER' => true,
            'FORM' => true,
            'H1' => true,
            'H2' => true,
            'H3' => true,
            'H4' => true,
            'H5' => true,
            'H6' => true,
            'HEADER' => true,
            'HGROUP' => true,
            'HR' => true,
            'LI' => true,
            'MAIN' => true,
            'MENU' => true,
            'NAV' => true,
            'OL' => true,
            'OPTION' => true,
            'P' => true,
            'PRE' => true,
            'SECTION' => true,
            'TABLE' => true,
            'TBODY' => true,
            'TD' => true,
            'TFOOT' => true,
            'TH' => true,
            'THEAD' => true,
            'TITLE' => true,
            'TR' => true,
            'UL' => true,
        ];

        return isset($boundaryTags[strtoupper($tag)]);
    }

    /**
     * Read and canonicalize `lang` or `xml:lang` from the current processor tag.
     *
     * @param mixed $processor WordPress HTML processor or compatible test double.
     * @return string|null Canonical language when the current tag declares one.
     */
    private function processorLangAttribute(mixed $processor, int &$processorOutputBytes): ?string
    {
        if (!method_exists($processor, 'get_attribute')) {
            return null;
        }

        foreach (['lang', 'xml:lang'] as $attribute) {
            try {
                $value = $processor->get_attribute($attribute);
            } catch (Throwable) {
                continue;
            }

            if (is_scalar($value)) {
                $valueBytes = strlen((string) $value);
                WP_FTS_Analysis_Limits::assert_html_language_attribute_bytes($valueBytes);
                $this->chargeProcessorOutputBytes($processorOutputBytes, $valueBytes);
            }

            $lang = $this->canonicalLanguage($value);
            if ($lang !== null) {
                return $lang;
            }
        }

        return null;
    }

    /** Charge provider strings before trim(), case folding, or retained copies. */
    private function chargeProcessorOutputBytes(int &$total, int $bytes): void
    {
        $total += $bytes;
        WP_FTS_Analysis_Limits::assert_document_source_bytes($total);
    }

    /**
     * Extract a language attribute from a raw fallback-parser tag.
     *
     * Handles double-quoted, single-quoted, and unquoted values for both `lang`
     * and `xml:lang`.
     *
     * @param string $tag Raw opening tag text.
     * @return string|null Canonical language when present and valid.
     */
    private function tagLangAttribute(string $tag): ?string
    {
        $attributes = $this->fallbackTagAttributes($tag);

        foreach (['lang', 'xml:lang'] as $name) {
            if (!array_key_exists($name, $attributes)) {
                continue;
            }

            $lang = $this->canonicalLanguage(html_entity_decode($attributes[$name], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($lang !== null) {
                return $lang;
            }
        }

        return null;
    }

    /**
     * Tokenize attributes from a raw fallback-parser opening tag.
     *
     * Regexing the whole tag for `lang=` is unsafe because quoted values on
     * unrelated attributes may contain language-looking text. This tokenizer
     * advances over quoted and unquoted values before considering the next
     * attribute name.
     *
     * @return array<string,string> Lowercase attribute names mapped to raw values.
     */
    private function fallbackTagAttributes(string $tag): array
    {
        $descriptor = $this->fallbackTagDescriptor($tag);
        if ($descriptor === null || $descriptor['closing']) {
            return [];
        }

        $attributes = [];
        $length = strlen($tag);
        $offset = $descriptor['name_end'];

        while ($offset < $length) {
            while ($offset < $length && $this->isHtmlWhitespace($tag[$offset])) {
                $offset++;
            }

            if ($offset >= $length || $tag[$offset] === '>' || $tag[$offset] === '/') {
                break;
            }

            if (!preg_match('/[^\s\/=>]+/A', substr($tag, $offset), $nameMatch)) {
                $offset++;
                continue;
            }

            $name = strtolower($nameMatch[0]);
            $offset += strlen($nameMatch[0]);

            while ($offset < $length && $this->isHtmlWhitespace($tag[$offset])) {
                $offset++;
            }

            $value = '';
            if ($offset < $length && $tag[$offset] === '=') {
                $offset++;
                while ($offset < $length && $this->isHtmlWhitespace($tag[$offset])) {
                    $offset++;
                }

                if ($offset < $length && ($tag[$offset] === '"' || $tag[$offset] === "'")) {
                    $quote = $tag[$offset];
                    $offset++;
                    $start = $offset;
                    while ($offset < $length && $tag[$offset] !== $quote) {
                        $offset++;
                    }
                    $value = substr($tag, $start, $offset - $start);
                    if ($offset < $length) {
                        $offset++;
                    }
                } else {
                    $start = $offset;
                    while (
                        $offset < $length
                        && !$this->isHtmlWhitespace($tag[$offset])
                        && $tag[$offset] !== '>'
                        && $tag[$offset] !== '/'
                    ) {
                        $offset++;
                    }
                    $value = substr($tag, $start, $offset - $start);
                }
            }

            $attributes[$name] ??= $value;
        }

        return $attributes;
    }

    /**
     * Check HTML's ASCII whitespace without relying on the optional ctype extension.
     */
    private function isHtmlWhitespace(string $char): bool
    {
        return $char === ' '
            || $char === "\t"
            || $char === "\n"
            || $char === "\r"
            || $char === "\f";
    }

    /**
     * Pop fallback parser scopes closed implicitly by a new opening tag.
     *
     * HTML allows tags such as `p`, `li`, and table cells to close without an
     * explicit end tag. The fallback parser models the common cases so language
     * and boost scopes do not leak across sibling elements.
     *
     * @param array<int,array{tag:string,nearest_text_group_depth:?int}> $stack
     */
    private function closeFallbackOptionalEndTags(array &$stack, string $opening): void
    {
        while ($stack !== []) {
            $top = $stack[count($stack) - 1]['tag'];
            if (!$this->fallbackOptionalEndTagClosesBefore($top, $opening)) {
                return;
            }

            array_pop($stack);
        }
    }

    /**
     * Decide whether `$newTag` implicitly closes `$openTag` in the fallback parser.
     *
     * This is a focused subset of the HTML optional end tag rules, covering the
     * tags most likely to affect visible text and language scope.
     */
    private function fallbackOptionalEndTagClosesBefore(string $openTag, string $newTag): bool
    {
        static $pClosers = [
            'ADDRESS' => true,
            'ARTICLE' => true,
            'ASIDE' => true,
            'BLOCKQUOTE' => true,
            'DETAILS' => true,
            'DIV' => true,
            'DL' => true,
            'FIELDSET' => true,
            'FIGCAPTION' => true,
            'FIGURE' => true,
            'FOOTER' => true,
            'FORM' => true,
            'H1' => true,
            'H2' => true,
            'H3' => true,
            'H4' => true,
            'H5' => true,
            'H6' => true,
            'HEADER' => true,
            'HR' => true,
            'MAIN' => true,
            'MENU' => true,
            'NAV' => true,
            'OL' => true,
            'P' => true,
            'PRE' => true,
            'SECTION' => true,
            'TABLE' => true,
            'UL' => true,
        ];

        return match ($openTag) {
            'P' => isset($pClosers[$newTag]),
            'LI' => $newTag === 'LI',
            'DT', 'DD' => $newTag === 'DT' || $newTag === 'DD',
            'OPTION' => $newTag === 'OPTION',
            'OPTGROUP' => $newTag === 'OPTGROUP',
            'TR' => in_array($newTag, ['TR', 'THEAD', 'TBODY', 'TFOOT'], true),
            'TD', 'TH' => in_array($newTag, ['TD', 'TH', 'TR', 'THEAD', 'TBODY', 'TFOOT'], true),
            'THEAD', 'TBODY', 'TFOOT' => in_array($newTag, ['THEAD', 'TBODY', 'TFOOT'], true),
            default => false,
        };
    }

    /**
     * Close the current fallback inline text run before a child boundary starts.
     *
     * The nearest boundary depth is inherited when an element is pushed, so
     * retirement is constant-time rather than a reverse ancestor scan.
     *
     * @param array<int,array{detect_group:?int,nearest_text_group_depth:?int}> $stack
     */
    private function retireFallbackCurrentTextGroup(array &$stack, ?int &$rootTextGroup): void
    {
        $nearestDepth = $stack === []
            ? null
            : ($stack[count($stack) - 1]['nearest_text_group_depth'] ?? null);
        if ($nearestDepth !== null && isset($stack[$nearestDepth])) {
            $stack[$nearestDepth]['detect_group'] = null;
            return;
        }

        $rootTextGroup = null;
    }

    /**
     * Return or allocate the nearest visible-text detection group in the fallback parser.
     *
     * @param array<int,array{detect_group:?int,nearest_text_group_depth:?int}> $stack
     */
    private function fallbackCurrentTextGroup(array &$stack, ?int &$rootTextGroup, int &$textGroupCounter): int
    {
        $nearestDepth = $stack === []
            ? null
            : ($stack[count($stack) - 1]['nearest_text_group_depth'] ?? null);
        if ($nearestDepth !== null && isset($stack[$nearestDepth])) {
            if ($stack[$nearestDepth]['detect_group'] === null) {
                $textGroupCounter++;
                $stack[$nearestDepth]['detect_group'] = $textGroupCounter;
            }

            return (int) $stack[$nearestDepth]['detect_group'];
        }

        if ($rootTextGroup === null) {
            $textGroupCounter++;
            $rootTextGroup = $textGroupCounter;
        }

        return $rootTextGroup;
    }

    /**
     * Check global and language-specific stopword sets.
     *
     * Language-specific stopwords are checked by full tag first and base
     * language second, so `en-US` can share an `en` list unless a full tag list
     * is configured.
     */
    private function isStopword(string $term, string $lang): bool
    {
        if (isset($this->stopwords[$term])) {
            return true;
        }

        $baseLang = explode('-', $lang, 2)[0];

        return isset($this->stopwordsByLang[$lang][$term]) || isset($this->stopwordsByLang[$baseLang][$term]);
    }

    /**
     * Build a stable signature for document stale-detection.
     */
    private function buildIndexSignature(): string
    {
        $skipAncestors = array_keys($this->skipAncestors);
        sort($skipAncestors, SORT_STRING);
        $boosts = $this->boosts;
        ksort($boosts, SORT_STRING);
        $stopwords = array_keys($this->stopwords);
        sort($stopwords, SORT_STRING);

        $payload = [
            'contract' => 'wp-fts-analyzer',
            'version' => 6,
            'skip_ancestors' => $skipAncestors,
            'boosts' => $boosts,
            'stopwords' => $stopwords,
            'stopwords_by_lang' => $this->sortedStringSetMap($this->stopwordsByLang),
            'default_language' => $this->defaultLanguage,
            'document_language' => $this->documentLanguage,
            'query_language' => $this->queryLanguage,
            'auto_detect_language' => $this->autoDetectLanguage,
            'language_detector' => $this->languageDetector === null ? null : $this->objectSignature($this->languageDetector),
            'language_pipeline' => $this->objectSignature($this->languagePipeline),
            'document_language_resolver' => $this->callableSignature($this->documentLanguageResolver),
            'query_language_resolver' => $this->callableSignature($this->queryLanguageResolver),
            'query_term_language_resolver' => $this->callableSignature($this->queryTermLanguageResolver),
            'html_processor_factory' => $this->callableSignature($this->htmlProcessorFactory),
        ];

        return 'wp-fts-analyzer-v6:' . sha1($this->stableJson($payload));
    }

    /**
     * @param array<string,array<string,bool>> $sets
     * @return array<string,string[]>
     */
    private function sortedStringSetMap(array $sets): array
    {
        ksort($sets, SORT_STRING);
        $result = [];
        foreach ($sets as $key => $set) {
            $items = array_keys($set);
            sort($items, SORT_STRING);
            $result[(string) $key] = $items;
        }

        return $result;
    }

    /**
     * Return a deterministic descriptor for a callback.
     */
    private function callableSignature(mixed $callback, bool $includeCapturedState = true): ?string
    {
        if (!is_callable($callback)) {
            return null;
        }

        try {
            if (is_string($callback)) {
                return 'function:' . strtolower($callback);
            }

            if (is_array($callback) && count($callback) === 2) {
                $target = is_object($callback[0]) ? $this->objectSignature($callback[0]) : (string) $callback[0];
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
                return 'invokable:' . $this->objectSignature($callback);
            }
        } catch (WP_FTS_Analyzer_Config_Limit_Exceeded $error) {
            throw $error;
        } catch (Throwable) {
            return 'callable:' . get_debug_type($callback);
        }

        return 'callable:' . get_debug_type($callback);
    }

    /**
     * Normalize callback state without exposing captured values in signatures.
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
            return $this->objectSignature($value);
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
     * Return a deterministic descriptor for an injected object.
     */
    private function objectSignature(object $object): string
    {
        if (is_callable([$object, 'index_signature'])) {
            try {
                $signature = $object->index_signature();
                if (is_scalar($signature) && trim((string) $signature) !== '') {
                    return (string) $signature;
                }
            } catch (Throwable) {
                // Fall through to the class-level descriptor.
            }
        }

        return get_debug_type($object);
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

    /**
     * Interpret optional analyzer feature flags without treating "false" as on.
     */
    private function truthyOption(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true);
        }

        return false;
    }

    /**
     * Accept legacy language strings as option arrays.
     *
     * Public analyzer methods historically accepted either an options array or a
     * single language string. This helper preserves that contract while ensuring
     * document calls populate `document_lang` and query calls populate
     * `query_lang`.
     *
     * @param array<string,mixed>|string|null $options Public method options.
     * @param string $kind Either `document` or `query`.
     * @return array<string,mixed>
     */
    private function normalizeLanguageOptions(array|string|null $options, string $kind): array
    {
        if (is_array($options)) {
            return $options;
        }

        if (is_string($options) && trim($options) !== '') {
            $key = $kind === 'query' ? 'query_lang' : 'document_lang';
            return [
                'lang' => $options,
                'language' => $options,
                $key => $options,
            ];
        }

        return [];
    }
}
