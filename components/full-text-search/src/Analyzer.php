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
    private const MAX_ANCESTOR_BOOST = 100.0;
    private const CONSTRUCTOR_OPTION_KEYS = [
        'skip_ancestors',
        'boosts',
        'stopwords',
        'min_term_len',
        'max_term_bytes',
        'fold_diacritics',
        'default_lang',
        'document_lang',
        'query_lang',
        'enable_stemming',
        'lemma_packs_by_lang',
        'language_pipeline',
        'stemmer',
        'stemmers_by_lang',
        'cjk_tokenizer',
        'segmenter_packs_by_lang',
        'token_normalizer',
        'chinese_script_map',
        'stopwords_by_lang',
        'document_language_resolver',
        'query_language_resolver',
        'query_term_language_resolver',
        'auto_detect_language',
        'html_processor_factory',
    ];
    private const LANGUAGE_PIPELINE_OPTION_KEYS = [
        'min_term_len',
        'max_term_bytes',
        'fold_diacritics',
        'enable_stemming',
        'lemma_packs_by_lang',
        'stemmer',
        'stemmers_by_lang',
        'cjk_tokenizer',
        'segmenter_packs_by_lang',
        'token_normalizer',
        'chinese_script_map',
    ];
    private const DOCUMENT_OPTION_KEYS = [
        'document_lang',
        'post_id',
        '_default_document_lang',
        '_include_document_surface',
        '_max_document_occurrences',
    ];
    private const QUERY_OPTION_KEYS = [
        'query_lang',
        '_default_query_lang',
        '_force_query_lang',
        '_include_query_surface',
        '_max_query_occurrences',
    ];
    private const DOCUMENT_FIELD_KEYS = ['name', 'text', 'html', 'boost'];
    private const MAX_DOCUMENT_FIELDS = 32;
    private const MAX_FIELD_NAME_BYTES = 191;

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

    /** @var (callable(string,array<string,mixed>,string):?string)|null */
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
     *   resolver receiving `($token, $options, $defaultLang)`.
     * - `auto_detect_language`: fill language gaps with deterministic script
     *   and compact lexical signals. Explicit language options, HTML language
     *   attributes, and multilingual-plugin metadata still win.
     * - `cjk_tokenizer`: optional segmenter for one CJK script run; the
     *   built-in n-gram tokenizer remains the fallback.
     * - `segmenter_packs_by_lang`: optional bundled tokenizer packs such as the
     *   Jieba-backed Chinese adapter. An absent or false entry uses the built-in
     *   n-gram tokenizer; a configured pack must load successfully.
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
     *   document_lang?:string,
     *   query_lang?:string,
     *   enable_stemming?:bool,
     *   lemma_packs_by_lang?:array<string,string|false>,
     *   language_pipeline?:WP_FTS_LanguagePipeline,
     *   stemmer?:WP_FTS_Stemmer|callable|null,
     *   stemmers_by_lang?:array<string,WP_FTS_Stemmer|callable|null>,
     *   cjk_tokenizer?:callable|null,
     *   segmenter_packs_by_lang?:array<string,bool>,
     *   token_normalizer?:callable|null,
     *   chinese_script_map?:array<string,array<string,string>>,
     *   stopwords_by_lang?:array<string,string[]>,
     *   document_language_resolver?:callable|null,
     *   query_language_resolver?:callable|null,
     *   query_term_language_resolver?:(callable(string,array<string,mixed>,string):?string)|null,
     *   auto_detect_language?:bool,
     *   html_processor_factory?:callable|null
     * } $options
     */
    public function __construct(array $options = [])
    {
        WP_FTS_Analyzer_Config_Limits::assert_analyzer_options($options, 'Analyzer options');
        self::assertConstructorOptions($options);
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
            $this->boosts[strtoupper($tag)] = (float) $boost;
        }

        $this->htmlProcessorFactory = $options['html_processor_factory'] ?? null;
        $this->documentLanguageResolver = $options['document_language_resolver'] ?? null;
        $this->queryLanguageResolver = $options['query_language_resolver'] ?? null;
        $this->queryTermLanguageResolver = $options['query_term_language_resolver'] ?? null;
        $this->autoDetectLanguage = $options['auto_detect_language'] ?? true;
        $this->languageDetector = $this->autoDetectLanguage
            ? new WP_FTS_LanguageDetector()
            : null;
        if (array_key_exists('language_pipeline', $options)) {
            $this->languagePipeline = $options['language_pipeline'];
        } else {
            $this->languagePipeline = new WP_FTS_LanguagePipeline([
                'min_term_len' => $options['min_term_len'] ?? 2,
                'max_term_bytes' => $options['max_term_bytes'] ?? 255,
                'fold_diacritics' => $options['fold_diacritics'] ?? true,
                'enable_stemming' => $options['enable_stemming'] ?? true,
                'lemma_packs_by_lang' => $options['lemma_packs_by_lang'] ?? [],
                'stemmer' => $options['stemmer'] ?? null,
                'stemmers_by_lang' => $options['stemmers_by_lang'] ?? [],
                'cjk_tokenizer' => $options['cjk_tokenizer'] ?? null,
                'segmenter_packs_by_lang' => $options['segmenter_packs_by_lang'] ?? [],
                'token_normalizer' => $options['token_normalizer'] ?? null,
                'chinese_script_map' => $options['chinese_script_map'] ?? [],
            ]);
        }

        $this->defaultLanguage = array_key_exists('default_lang', $options)
            ? $this->requiredConstructorLanguage($options['default_lang'])
            : 'en';
        $this->documentLanguage = array_key_exists('document_lang', $options)
            ? $this->requiredConstructorLanguage($options['document_lang'])
            : null;
        $this->queryLanguage = array_key_exists('query_lang', $options)
            ? $this->requiredConstructorLanguage($options['query_lang'])
            : null;

        $this->stopwords = [];
        $this->stopwordsByLang = [];
        $stopwordSegments = [];
        $stopwordTargets = [];
        foreach (($options['stopwords'] ?? []) as $word) {
            $stopwordSegments[] = [
                'text' => $word,
                'language' => $this->defaultLanguage,
            ];
            $stopwordTargets[] = null;
        }
        foreach (($options['stopwords_by_lang'] ?? []) as $lang => $words) {
            $canonical = $this->requiredConstructorLanguage($lang);

            foreach ($words as $word) {
                $stopwordSegments[] = [
                    'text' => $word,
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
     * @param array{document_lang?:string,post_id?:int,_default_document_lang?:string,_include_document_surface?:bool,_max_document_occurrences?:int} $options
     * @return array<int,array{term:string,weight:float,lang:string,position?:int,rank?:int,source?:string}>
     *         Occurrences in document order. `weight` is the strongest boost
     *         inherited from ancestor tags, and `lang` is the term language.
     */
    public function analyze_content(string $html, array $options = []): array
    {
        $this->assertDocumentOptions($options);
        WP_FTS_Analysis_Limits::assert_source_bytes($html);
        WP_FTS_Html_Text_Stream::assert_analysis_markup_limits($html);
        $maxOccurrences = $this->documentOccurrenceLimit($options);
        $includeSurface = $options['_include_document_surface'] ?? false;
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
                if ($term['term'] === '' || $this->isStopword($term['term'], $term['lang'])) {
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
     * Analyze text that callers already extracted from document content.
     *
     * This preserves the document-language option semantics of
     * `analyze_content()` while skipping HTML segmentation for field values that
     * are known to be plain text, such as titles, excerpts, taxonomy labels, and
     * custom-field values.
     *
     * @param array{document_lang?:string,post_id?:int,_default_document_lang?:string,_include_document_surface?:bool,_max_document_occurrences?:int} $options
     * @return array<int,array{term:string,weight:float,lang:string,position?:int,rank?:int,source?:string}>
     */
    public function analyze_plain_content(string $text, array $options = []): array
    {
        $this->assertDocumentOptions($options);
        WP_FTS_Analysis_Limits::assert_source_bytes($text);
        $maxOccurrences = $this->documentOccurrenceLimit($options);
        $includeSurface = $options['_include_document_surface'] ?? false;
        $this->assertLexicalWordBudget($text, $maxOccurrences);
        $resolution = $this->resolveDocumentLanguage($options);
        $lang = $resolution['language'];
        if ($this->shouldAutoDetectDocumentLanguage($resolution['authoritative'])) {
            $lang = $this->detectSegmentLanguage($text, $lang);
        }
        $nextPosition = 0;
        $tokens = [];

        foreach ($this->renumberAnalyzedPositions(
            $this->analyzeText($text, $lang, $includeSurface, $maxOccurrences),
            $nextPosition
        ) as $term) {
            if ($term['term'] === '' || $this->isStopword($term['term'], $term['lang'])) {
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
     * @param array{document_lang?:string,post_id?:int,_default_document_lang?:string,_include_document_surface?:bool,_max_document_occurrences?:int} $options
     * @return array<int,array<int,array{term:string,weight:float,lang:string,position?:int,rank?:int,source?:string}>>
     */
    public function analyze_document_fields(array $fields, array $options = []): array
    {
        $this->assertDocumentOptions($options);
        if (!array_is_list($fields)) {
            throw new InvalidArgumentException('FTS document fields must be a list.');
        }
        if (count($fields) > self::MAX_DOCUMENT_FIELDS) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'index_fields',
                'FTS document analysis accepts at most 32 fields.'
            );
        }

        $baseOptions = $options;
        $maxOccurrences = $this->documentOccurrenceLimit($baseOptions);
        $includeSurface = $baseOptions['_include_document_surface'] ?? false;
        $segments = [];
        $segmentFields = [];
        $segmentWeights = [];
        $sourceBytes = 0;
        $lexicalWords = 0;
        $fieldSources = [];
        foreach ($fields as $fieldIndex => $field) {
            $this->assertDocumentField($field);
            $source = array_key_exists('html', $field)
                ? $field['html']
                : $field['text'];
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
            if (array_key_exists('html', $field)) {
                WP_FTS_Html_Text_Stream::assert_analysis_markup_limits($source);
            }
        }

        // Only collect segment arrays after every aggregate source/word/markup
        // preflight succeeds, so a late invalid field cannot leave an almost
        // maximum document resident before rejection.
        foreach ($fields as $fieldIndex => $field) {
            $fieldOptions = $baseOptions;
            $fieldOptions['field_name'] = $field['name'];
            $source = $fieldSources[$fieldIndex];
            if (array_key_exists('html', $field)) {
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

            $resolution = $this->resolveDocumentLanguage($fieldOptions);
            $lang = $resolution['language'];
            if ($this->shouldAutoDetectDocumentLanguage($resolution['authoritative'])) {
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
                if ($term['term'] === '' || $this->isStopword($term['term'], $term['lang'])) {
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
     * Use this for callers that need normalized query terms without occurrence
     * metadata. Use `analyze_query_occurrences()` when language and position
     * rows are required.
     *
     * @param array{query_lang?:string,_default_query_lang?:string,_force_query_lang?:bool,_include_query_surface?:bool,_max_query_occurrences?:int} $options
     * @return string[]
     */
    public function analyze_query(string $query, array $options = []): array
    {
        return array_map(
            static fn(array $occurrence): string => $occurrence['term'],
            $this->analyze_query_occurrences($query, $options)
        );
    }

    /**
     * Analyze query text and preserve each token's resolved language.
     *
     * Searcher uses this to decide the language partition before namespacing
     * query terms.
     *
     * @param array{query_lang?:string,_default_query_lang?:string,_force_query_lang?:bool,_include_query_surface?:bool,_max_query_occurrences?:int} $options
     * @return array<int,array{term:string,lang:string,position?:int,rank?:int,source?:string,surface?:string,normalized_surface?:string}>
     */
    public function analyze_query_occurrences(string $query, array $options = []): array
    {
        $this->assertQueryOptions($options);
        $maxOccurrences = $options['_max_query_occurrences'] ?? null;
        $resolution = $this->resolveQueryLanguage($options);
        $includeSurface = $options['_include_query_surface'] ?? false;
        $terms = [];
        $nextPosition = 0;

        $segments = $this->queryTextSegments(
            $query,
            $resolution['language'],
            $resolution['authoritative'],
            $options
        );
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
        return $options['_max_document_occurrences']
            ?? WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES;
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
     * Untagged queries use the resolved query language.
     * Inline tags such as `pl:zamek` or `en-US:"color search"` scope only the
     * tagged term or quoted phrase. A resolver callback can deterministically
     * assign languages to otherwise untagged tokens.
     *
     * @param array{query_lang?:string,_default_query_lang?:string,_force_query_lang?:bool,_include_query_surface?:bool,_max_query_occurrences?:int} $options
     * @return array<int,array{text:string,lang:string}>
     */
    private function queryTextSegments(
        string $query,
        string $defaultLang,
        bool $languageAuthoritative,
        array $options
    ): array
    {
        $query = WP_FTS_Utf8::repair_word_boundaries($query);
        $segments = [];
        $offset = 0;
        $forceQueryLang = $options['_force_query_lang'] ?? false;
        $pattern = '/(^|[\s,;]+)([A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8}){0,3}):("[^"]+"|\'[^\']+\'|[^\s,;]+)/u';
        $matched = preg_match_all($pattern, $query, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($matched === false) {
            throw new RuntimeException('Unicode query-scope tokenization failed.');
        }
        if ($matched === 0) {
            $this->appendUntaggedQuerySegments(
                $segments,
                $query,
                $defaultLang,
                $languageAuthoritative,
                $options
            );
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
                    $languageAuthoritative,
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
                    $languageAuthoritative,
                    $options
                );
            }

            $offset = $fullStart + strlen($fullText);
        }

        if ($offset < strlen($query)) {
            $this->appendUntaggedQuerySegments(
                $segments,
                substr($query, $offset),
                $defaultLang,
                $languageAuthoritative,
                $options
            );
        }

        return $segments;
    }

    /**
     * Add untagged query text using the same span-level detection as documents.
     *
     * A custom per-token resolver still gets first refusal for each token. When
     * it has no answer, the whole untagged query span supplies the fallback
     * language so weak single-token signals do not drift back to the default
     * partition and break AND recall.
     *
     * @param array<int,array{text:string,lang:string}> $segments
     * @param array{query_lang?:string,_default_query_lang?:string,_force_query_lang?:bool,_include_query_surface?:bool,_max_query_occurrences?:int} $options
     */
    private function appendUntaggedQuerySegments(
        array &$segments,
        string $text,
        string $defaultLang,
        bool $languageAuthoritative,
        array $options
    ): void {
        if (trim($text) === '') {
            return;
        }

        $spanLang = $this->detectQuerySpanLanguage($text, $defaultLang, $languageAuthoritative);
        if ($this->queryTermLanguageResolver === null || ($options['_force_query_lang'] ?? false)) {
            $segments[] = ['text' => $text, 'lang' => $spanLang];
            return;
        }

        foreach ($this->queryRawTokens($text) as $token) {
            $resolved = ($this->queryTermLanguageResolver)($token, $options, $defaultLang);
            $lang = $this->resolvedCallbackLanguage($resolved, 'query-term language resolver') ?? $spanLang;
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
        $text = WP_FTS_Utf8::repair_word_boundaries($text);
        $matches = [];
        if (preg_match_all('/[\p{L}\p{M}\p{N}_]+/u', $text, $matches) === false) {
            throw new RuntimeException('Unicode query tokenization failed.');
        }

        return array_values(array_filter(
            $matches[0] ?? [],
            static fn(string $token): bool => $token !== ''
        ));
    }

    /** Detect language for a whole untagged query span. */
    private function detectQuerySpanLanguage(
        string $text,
        string $defaultLang,
        bool $languageAuthoritative
    ): string
    {
        if (!$this->shouldAutoDetectQueryLanguage($languageAuthoritative) || $this->languageDetector === null) {
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
     * When no factory is configured, the fallback parser keeps enough stack
     * state to make skip, boost, and language-scope decisions deterministic.
     *
     * @param array{document_lang?:string,post_id?:int,_default_document_lang?:string,_include_document_surface?:bool,_max_document_occurrences?:int,field_name?:string} $options
     * @return array<int,array{text:string,weight:float,lang:string,explicit_lang?:bool,detect_group?:int}>
     */
    private function extractHtmlSegments(string $html, array $options): array
    {
        $resolution = $this->resolveDocumentLanguage($options);
        $documentLang = $resolution['language'];
        $autoDetect = $this->shouldAutoDetectDocumentLanguage($resolution['authoritative']);
        // Inline ancestry is represented as a request-local persistent trie.
        // Segments retain one integer node ID instead of copying the complete
        // ancestor path into every text run. This keeps deeply nested markup
        // linear in source tokens plus element depth rather than their product.
        $inlinePathParents = [0 => 0];
        $inlinePathDepths = [0 => 0];
        $inlinePathChildren = [];

        if ($this->htmlProcessorFactory !== null) {
            $processor = $this->createProcessor($html);
            $segments = $this->extractWithProcessor(
                $processor,
                $documentLang,
                $inlinePathParents,
                $inlinePathDepths,
                $inlinePathChildren
            );
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
     * @param string $html HTML document or fragment.
     * @return object Processor implementing the complete event-stream contract.
     */
    private function createProcessor(string $html): object
    {
        $processor = ($this->htmlProcessorFactory)($html);

        // get_current_depth() was added to WP_HTML_Processor in WordPress 6.6.
        // The remaining methods form the event-stream contract used below;
        // accepting less would reintroduce a second parser model.
        foreach ([
            'next_token',
            'get_current_depth',
            'get_token_type',
            'get_tag',
            'is_tag_closer',
            'expects_closer',
            'get_modifiable_text',
            'get_attribute',
        ] as $method) {
            if (
                !is_object($processor)
                || !method_exists($processor, $method)
                || !is_callable([$processor, $method])
            ) {
                throw new UnexpectedValueException(
                    'Analyzer html_processor_factory must return an object implementing the complete processor contract.'
                );
            }
        }

        return $processor;
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
        object $processor,
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

        while (true) {
            $hasToken = $processor->next_token();
            if (!is_bool($hasToken)) {
                throw new UnexpectedValueException(
                    'Analyzer HTML processor next_token() must return a boolean.'
                );
            }
            if (!$hasToken) {
                break;
            }

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
            if (!is_string($tokenType) || $tokenType === '' || trim($tokenType) !== $tokenType) {
                throw new UnexpectedValueException(
                    'Analyzer HTML processor get_token_type() must return an unpadded nonempty string after next_token().'
                );
            }
            $tokenTypeBytes = strlen($tokenType);
            if ($tokenTypeBytes > self::HTML_PROCESSOR_TOKEN_TYPE_BYTES) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'html_processor_token_type_bytes',
                    'FTS document HTML processor returned an oversized token type.'
                );
            }
            $this->chargeProcessorOutputBytes($processorControlBytes, $tokenTypeBytes);
            $providerDepth = $processor->get_current_depth();
            if (!is_int($providerDepth)) {
                throw new UnexpectedValueException(
                    'Analyzer HTML processor get_current_depth() must return an integer.'
                );
            }
            if (
                $providerDepth < 0
                || $providerDepth > WP_FTS_Analysis_Limits::MAX_HTML_ELEMENT_DEPTH + self::HTML_PROCESSOR_DEPTH_OVERHEAD
            ) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'html_element_depth',
                    'FTS document HTML processor exceeds its bounded structural depth.'
                );
            }

            $isCloser = false;
            if ($tokenType === '#tag') {
                $isCloser = $processor->is_tag_closer();
                if (!is_bool($isCloser)) {
                    throw new UnexpectedValueException(
                        'Analyzer HTML processor is_tag_closer() must return a boolean.'
                    );
                }
            }
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

                $isBoundary = $this->isTextGroupBoundaryTag($tag);
                if ($isBoundary) {
                    $this->retireProcessorTextGroup($states, $parentDepth, $rootTextGroup);
                }
                // Atomic tags include HTML void elements, SCRIPT/STYLE/TITLE,
                // and self-closing foreign content. WP_HTML_Processor emits no
                // later pop event for them, so retaining a row here would leak
                // skip, boost, or language state into the following sibling.
                $expectsCloser = $processor->expects_closer();
                if (!is_bool($expectsCloser)) {
                    throw new UnexpectedValueException(
                        'Analyzer HTML processor expects_closer() must return a boolean for a tag token.'
                    );
                }
                if (!$expectsCloser) {
                    continue;
                }

                $declaredLang = $this->processorLangAttribute($processor, $processorOutputBytes);
                $inlinePathId = $this->internInlinePath(
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
                        || isset($this->skipAncestors[$tag]),
                    'weight' => max(
                        (float) $parent['weight'],
                        (float) ($this->boosts[$tag] ?? 1.0)
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
            if (!is_string($text)) {
                throw new UnexpectedValueException(
                    'Analyzer HTML processor get_modifiable_text() must return a string.'
                );
            }
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

        if ($offset >= $length || !self::isFallbackTagNameStart($tag[$offset])) {
            return null;
        }

        $start = $offset;
        while ($offset < $length && self::isFallbackTagNameByte($tag[$offset])) {
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

    private static function isFallbackTagNameStart(string $byte): bool
    {
        $ord = ord($byte);

        return ($ord >= 65 && $ord <= 90) || ($ord >= 97 && $ord <= 122);
    }

    private static function isFallbackTagNameByte(string $byte): bool
    {
        $ord = ord($byte);

        return self::isFallbackTagNameStart($byte)
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
     * other. Aligning persistent trie nodes by depth preserves prefix-sequence
     * semantics without materializing either ancestor array.
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
     * Precedence is per-call `document_lang`, constructor `document_lang`,
     * custom resolver, per-call fallback language, then analyzer default.
     *
     * @param array{document_lang?:string,post_id?:int,_default_document_lang?:string,_include_document_surface?:bool,_max_document_occurrences?:int,field_name?:string} $options
     * @return array{language:string,authoritative:bool}
     */
    private function resolveDocumentLanguage(array $options): array
    {
        if (array_key_exists('document_lang', $options)) {
            return [
                'language' => WP_FTS_TermNamespace::parse_language_tag($options['document_lang']),
                'authoritative' => true,
            ];
        }
        if ($this->documentLanguage !== null) {
            return ['language' => $this->documentLanguage, 'authoritative' => true];
        }

        $resolved = $this->callLanguageResolver(
            $this->documentLanguageResolver,
            $options,
            'document language resolver'
        );
        if ($resolved !== null) {
            return ['language' => $resolved, 'authoritative' => true];
        }
        if (array_key_exists('_default_document_lang', $options)) {
            return [
                'language' => WP_FTS_TermNamespace::parse_language_tag($options['_default_document_lang']),
                'authoritative' => false,
            ];
        }

        return ['language' => $this->defaultLanguage, 'authoritative' => false];
    }

    /**
     * Resolve the language used for query analysis.
     *
     * Precedence is per-call `query_lang`, constructor `query_lang`, custom
     * resolver, per-call fallback language, then analyzer default.
     *
     * @param array{query_lang?:string,_default_query_lang?:string,_force_query_lang?:bool,_include_query_surface?:bool,_max_query_occurrences?:int} $options
     * @return array{language:string,authoritative:bool}
     */
    private function resolveQueryLanguage(array $options): array
    {
        if (array_key_exists('query_lang', $options)) {
            return [
                'language' => WP_FTS_TermNamespace::parse_language_tag($options['query_lang']),
                'authoritative' => true,
            ];
        }
        if ($this->queryLanguage !== null) {
            return ['language' => $this->queryLanguage, 'authoritative' => true];
        }

        $resolved = $this->callLanguageResolver(
            $this->queryLanguageResolver,
            $options,
            'query language resolver'
        );
        if ($resolved !== null) {
            return ['language' => $resolved, 'authoritative' => true];
        }
        $forced = $options['_force_query_lang'] ?? false;
        if (array_key_exists('_default_query_lang', $options)) {
            return [
                'language' => WP_FTS_TermNamespace::parse_language_tag($options['_default_query_lang']),
                'authoritative' => $forced,
            ];
        }

        return ['language' => $this->defaultLanguage, 'authoritative' => $forced];
    }

    /**
     * Canonicalize a mixed language candidate or reject it.
     *
     * Values like `en_US.UTF-8` become `en-US`; empty, non-scalar, `C`, and
     * `POSIX` candidates are ignored so they do not become searchable language
     * partitions.
     *
     * @param mixed $language Candidate from trusted HTML, locale, or processor
     *        input.
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

    /** Call one configured resolver and validate its exact output contract. */
    private function callLanguageResolver(?callable $resolver, array $options, string $label): ?string
    {
        if ($resolver === null) {
            return null;
        }

        return $this->resolvedCallbackLanguage($resolver($options), $label);
    }

    /** Require null or one native, unpadded language tag from a resolver. */
    private function resolvedCallbackLanguage(mixed $resolved, string $label): ?string
    {
        if ($resolved === null) {
            return null;
        }
        if (!is_string($resolved)) {
            throw new UnexpectedValueException("Analyzer {$label} must return a language string or null.");
        }

        try {
            return WP_FTS_TermNamespace::parse_language_tag($resolved);
        } catch (InvalidArgumentException $error) {
            throw new UnexpectedValueException(
                "Analyzer {$label} returned an invalid language tag.",
                0,
                $error
            );
        }
    }

    /**
     * Decide whether untagged document text may be language-detected.
     *
     * Explicit caller language and constructor/resolver languages remain
     * authoritative. Site locale and analyzer default are fallbacks, so detector
     * signals are allowed to beat them for untagged content.
     */
    private function shouldAutoDetectDocumentLanguage(bool $languageAuthoritative): bool
    {
        return $this->autoDetectLanguage
            && $this->languageDetector !== null
            && !$languageAuthoritative;
    }

    /**
     * Decide whether untagged query text may be language-detected.
     *
     */
    private function shouldAutoDetectQueryLanguage(bool $languageAuthoritative): bool
    {
        return $this->autoDetectLanguage
            && $this->languageDetector !== null
            && !$languageAuthoritative;
    }

    /**
     * Return the current processor tag name when available.
     */
    private function processorCurrentTag(object $processor, int &$processorOutputBytes): string
    {
        $tag = $processor->get_tag();
        if (
            !is_string($tag)
            || $tag === ''
            || trim($tag) !== $tag
            || !self::isHtmlElementName($tag)
            || strtoupper($tag) !== $tag
        ) {
            throw new UnexpectedValueException(
                'Analyzer HTML processor get_tag() must return a canonical uppercase HTML element name for a tag token.'
            );
        }

        $tagBytes = strlen($tag);
        WP_FTS_Analysis_Limits::assert_html_tag_bytes($tagBytes);
        $this->chargeProcessorOutputBytes($processorOutputBytes, $tagBytes);

        return $tag;
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
     * @param object $processor WordPress HTML processor or compatible test double.
     * @return string|null Canonical language when the current tag declares one.
     */
    private function processorLangAttribute(object $processor, int &$processorOutputBytes): ?string
    {
        foreach (['lang', 'xml:lang'] as $attribute) {
            $value = $processor->get_attribute($attribute);
            if ($value === null || $value === true) {
                continue;
            }
            if (!is_string($value)) {
                throw new UnexpectedValueException(
                    'Analyzer HTML processor get_attribute() must return a string, true, or null.'
                );
            }

            $valueBytes = strlen($value);
            WP_FTS_Analysis_Limits::assert_html_language_attribute_bytes($valueBytes);
            $this->chargeProcessorOutputBytes($processorOutputBytes, $valueBytes);
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

    /** Validate the exact option contract shared by document-analysis APIs. */
    private function assertDocumentOptions(array $options): void
    {
        $this->assertOptionKeys($options, self::DOCUMENT_OPTION_KEYS, 'document analysis');
        foreach (['document_lang', '_default_document_lang'] as $key) {
            if (array_key_exists($key, $options)) {
                WP_FTS_TermNamespace::parse_language_tag($options[$key]);
            }
        }
        if (
            array_key_exists('post_id', $options)
            && (!is_int($options['post_id']) || $options['post_id'] <= 0)
        ) {
            throw new InvalidArgumentException('Analyzer document post_id must be a positive integer.');
        }
        if (
            array_key_exists('_include_document_surface', $options)
            && !is_bool($options['_include_document_surface'])
        ) {
            throw new InvalidArgumentException('Analyzer _include_document_surface must be a boolean.');
        }
        $this->assertOccurrenceLimit($options, '_max_document_occurrences');
    }

    /** Validate the exact option contract shared by query-analysis APIs. */
    private function assertQueryOptions(array $options): void
    {
        $this->assertOptionKeys($options, self::QUERY_OPTION_KEYS, 'query analysis');
        foreach (['query_lang', '_default_query_lang'] as $key) {
            if (array_key_exists($key, $options)) {
                WP_FTS_TermNamespace::parse_language_tag($options[$key]);
            }
        }
        foreach (['_force_query_lang', '_include_query_surface'] as $key) {
            if (array_key_exists($key, $options) && !is_bool($options[$key])) {
                throw new InvalidArgumentException("Analyzer {$key} must be a boolean.");
            }
        }
        $this->assertOccurrenceLimit($options, '_max_query_occurrences');
    }

    /** @param string[] $allowedKeys */
    private function assertOptionKeys(array $options, array $allowedKeys, string $surface): void
    {
        foreach (array_keys($options) as $key) {
            if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
                throw new InvalidArgumentException("Analyzer {$surface} options contain an unsupported field.");
            }
        }
    }

    /** Require one optional occurrence ceiling to be a bounded native integer. */
    private function assertOccurrenceLimit(array $options, string $key): void
    {
        if (!array_key_exists($key, $options)) {
            return;
        }

        $limit = $options[$key];
        if (
            !is_int($limit)
            || $limit < 0
            || $limit > WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES
        ) {
            throw new InvalidArgumentException(
                "Analyzer {$key} must be an integer from zero through "
                . WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES
                . '.'
            );
        }
    }

    /** Validate one field already normalized by the indexer. */
    private function assertDocumentField(mixed $field): void
    {
        if (!is_array($field)) {
            throw new InvalidArgumentException('FTS document fields must be arrays.');
        }
        foreach (array_keys($field) as $key) {
            if (!is_string($key) || !in_array($key, self::DOCUMENT_FIELD_KEYS, true)) {
                throw new InvalidArgumentException('FTS document fields contain an unsupported field.');
            }
        }
        if (!array_key_exists('name', $field) || !array_key_exists('text', $field) || !array_key_exists('boost', $field)) {
            throw new InvalidArgumentException('FTS document fields require name, text, and boost.');
        }
        if (
            !is_string($field['name'])
            || $field['name'] === ''
            || trim($field['name']) !== $field['name']
            || strlen($field['name']) > self::MAX_FIELD_NAME_BYTES
        ) {
            throw new InvalidArgumentException('FTS document field names must be unpadded nonempty strings of at most 191 bytes.');
        }
        if (!is_string($field['text'])) {
            throw new InvalidArgumentException('FTS document field text must be a string.');
        }
        if (array_key_exists('html', $field) && !is_string($field['html'])) {
            throw new InvalidArgumentException('FTS document field html must be a string.');
        }

        $boost = $field['boost'];
        if (
            !is_float($boost)
            || !is_finite($boost)
            || floor($boost) !== $boost
            || $boost < 1.0
            || $boost > self::MAX_ANCESTOR_BOOST
        ) {
            throw new InvalidArgumentException('FTS normalized document field boosts must be whole floats from 1 through 100.');
        }
    }

    /** Reject misspelled, coercible, or ignored constructor options. */
    private static function assertConstructorOptions(array $options): void
    {
        foreach (array_keys($options) as $key) {
            if (!is_string($key) || !in_array($key, self::CONSTRUCTOR_OPTION_KEYS, true)) {
                throw new InvalidArgumentException('Unknown analyzer constructor option: ' . (string) $key);
            }
        }

        if (array_key_exists('skip_ancestors', $options)) {
            self::assertStringList($options['skip_ancestors'], 'Analyzer option skip_ancestors');
            $skipAncestors = [];
            foreach ($options['skip_ancestors'] as $tag) {
                self::assertConfiguredElementName($tag, 'Analyzer skipped ancestor');
                $canonical = strtoupper($tag);
                if (isset($skipAncestors[$canonical])) {
                    throw new InvalidArgumentException(
                        "Analyzer option skip_ancestors contains duplicate element {$canonical}."
                    );
                }
                $skipAncestors[$canonical] = true;
            }
        }
        if (array_key_exists('stopwords', $options)) {
            self::assertStringList($options['stopwords'], 'Analyzer option stopwords');
        }

        if (array_key_exists('boosts', $options)) {
            if (!is_array($options['boosts'])) {
                throw new InvalidArgumentException('Analyzer option boosts must be an element-name map.');
            }
            $boostNames = [];
            foreach ($options['boosts'] as $tag => $boost) {
                self::assertConfiguredElementName($tag, 'Analyzer boost');
                $canonical = strtoupper($tag);
                if (isset($boostNames[$canonical])) {
                    throw new InvalidArgumentException(
                        "Analyzer option boosts contains duplicate element {$canonical}."
                    );
                }
                $boostNames[$canonical] = true;
                if ((!is_int($boost) && !is_float($boost)) || !is_finite((float) $boost)) {
                    throw new InvalidArgumentException("Analyzer boost {$tag} must be a finite number.");
                }
                if ($boost <= 0 || $boost > self::MAX_ANCESTOR_BOOST) {
                    throw new InvalidArgumentException(
                        "Analyzer boost {$tag} must be greater than zero and at most " . self::MAX_ANCESTOR_BOOST . '.'
                    );
                }
            }
        }

        foreach (['default_lang', 'document_lang', 'query_lang'] as $key) {
            if (!array_key_exists($key, $options)) {
                continue;
            }
            WP_FTS_TermNamespace::parse_language_tag($options[$key]);
        }

        if (array_key_exists('auto_detect_language', $options) && !is_bool($options['auto_detect_language'])) {
            throw new InvalidArgumentException('Analyzer option auto_detect_language must be a boolean.');
        }

        foreach ([
            'document_language_resolver',
            'query_language_resolver',
            'query_term_language_resolver',
            'html_processor_factory',
        ] as $key) {
            if (
                array_key_exists($key, $options)
                && $options[$key] !== null
                && !is_callable($options[$key])
            ) {
                throw new InvalidArgumentException("Analyzer option {$key} must be callable or null.");
            }
        }

        if (
            array_key_exists('language_pipeline', $options)
            && !$options['language_pipeline'] instanceof WP_FTS_LanguagePipeline
        ) {
            throw new InvalidArgumentException('Analyzer option language_pipeline must be a language pipeline.');
        }
        if (array_key_exists('language_pipeline', $options)) {
            foreach (self::LANGUAGE_PIPELINE_OPTION_KEYS as $key) {
                if (array_key_exists($key, $options)) {
                    throw new InvalidArgumentException(
                        "Analyzer option {$key} cannot be combined with language_pipeline."
                    );
                }
            }
        }

        if (array_key_exists('stopwords_by_lang', $options)) {
            if (!is_array($options['stopwords_by_lang'])) {
                throw new InvalidArgumentException('Analyzer option stopwords_by_lang must be a language map.');
            }
            $canonicalLanguages = [];
            foreach ($options['stopwords_by_lang'] as $language => $stopwords) {
                $canonical = WP_FTS_TermNamespace::parse_language_tag($language);
                if (isset($canonicalLanguages[$canonical])) {
                    throw new InvalidArgumentException(
                        "Analyzer option stopwords_by_lang contains duplicate canonical language {$canonical}."
                    );
                }
                $canonicalLanguages[$canonical] = true;
                self::assertStringList($stopwords, "Analyzer stopwords for {$language}");
            }
        }
    }

    /** Assert a bounded option is a list of non-empty native strings. */
    private static function assertStringList(mixed $value, string $label): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException("{$label} must be a list of strings.");
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '' || trim($item) !== $item) {
                throw new InvalidArgumentException("{$label} must contain only unpadded non-empty strings.");
            }
        }
    }

    /** Require one element name accepted by the fallback HTML tokenizer. */
    private static function assertConfiguredElementName(mixed $name, string $label): void
    {
        if (!is_string($name) || $name === '' || trim($name) !== $name) {
            throw new InvalidArgumentException("{$label} names must be unpadded non-empty strings.");
        }
        if (!self::isHtmlElementName($name)) {
            throw new InvalidArgumentException("{$label} names must use the HTML element-name grammar.");
        }
    }

    /** Check the ASCII element-name grammar shared with fallback tokenization. */
    private static function isHtmlElementName(string $name): bool
    {
        if ($name === '' || !self::isFallbackTagNameStart($name[0])) {
            return false;
        }
        for ($offset = 1, $length = strlen($name); $offset < $length; $offset++) {
            if (!self::isFallbackTagNameByte($name[$offset])) {
                return false;
            }
        }

        return true;
    }

    /** Canonicalize one already type-checked constructor language or fail. */
    private function requiredConstructorLanguage(string $language): string
    {
        return WP_FTS_TermNamespace::parse_language_tag($language);
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
            'detector' => $this->languageDetector === null ? null : $this->objectSignature($this->languageDetector),
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
            $signature = $object->index_signature();
            if (
                !is_string($signature)
                || $signature === ''
                || trim($signature) !== $signature
                || strlen($signature) > WP_FTS_Analyzer_Config_Limits::MAX_OPTION_SCALAR_BYTES
            ) {
                throw new UnexpectedValueException(
                    'Analyzer extension index_signature() must return an unpadded nonempty bounded string.'
                );
            }

            return $signature;
        }

        return get_debug_type($object);
    }

    /**
     * Encode a sanitized signature payload.
     */
    private function stableJson(mixed $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

}
