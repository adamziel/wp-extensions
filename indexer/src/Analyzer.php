<?php
declare(strict_types=1);

/**
 * Extracts searchable terms from HTML documents and plain-text queries.
 *
 * The analyzer is the bridge between WordPress/HTML input and the language
 * pipeline. It strips skipped elements, applies simple ancestor boosts, honors
 * `lang` and `xml:lang` scopes, removes stopwords, and returns weighted
 * occurrences that the indexer can namespace per language.
 */
final class WP_FTS_Analyzer
{
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
     *   content, options, and WordPress integrations do not provide one.
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
     * - `html_processor_factory`: test hook that returns a `WP_HTML_Processor`
     *   compatible object for the given HTML.
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
     *   language_pipeline?:WP_FTS_LanguagePipeline,
     *   stemmer?:WP_FTS_Stemmer|callable|null,
     *   stemmers_by_lang?:array<string,WP_FTS_Stemmer|callable|null>,
     *   stemmers?:array<string,WP_FTS_Stemmer|callable|null>,
     *   cjk_tokenizer?:callable|null,
     *   cjk_segmenter?:callable|null,
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
            'stemmer' => $options['stemmer'] ?? null,
            'stemmers_by_lang' => $options['stemmers_by_lang'] ?? $options['stemmers'] ?? [],
            'cjk_tokenizer' => $options['cjk_tokenizer'] ?? $options['cjk_segmenter'] ?? null,
            'token_normalizer' => $options['token_normalizer'] ?? null,
            'chinese_script_map' => $options['chinese_script_map'] ?? [],
        ]);

        $this->defaultLanguage = $this->canonicalLanguage($options['default_lang'] ?? $options['language'] ?? null) ?? 'en';
        $this->documentLanguage = $this->canonicalLanguage($options['document_lang'] ?? null);
        $this->queryLanguage = $this->canonicalLanguage($options['query_lang'] ?? null);

        $this->stopwords = [];
        foreach (($options['stopwords'] ?? []) as $word) {
            foreach ($this->languagePipeline->analyze_detailed((string) $word, $this->defaultLanguage) as $term) {
                $this->stopwords[$term['term']] = true;
            }
        }

        $this->stopwordsByLang = [];
        foreach (($options['stopwords_by_lang'] ?? []) as $lang => $words) {
            $canonical = $this->canonicalLanguage((string) $lang);
            if ($canonical === null || !is_array($words)) {
                continue;
            }

            foreach ($words as $word) {
                foreach ($this->languagePipeline->analyze_detailed((string) $word, $canonical) as $term) {
                    $this->stopwordsByLang[$canonical][$term['term']] = true;
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
     * defaults, then resolver callbacks and WordPress integrations. Nested
     * `lang`/`xml:lang` attributes override the document language for their text
     * scope.
     *
     * @param array{lang?:string,language?:string,document_lang?:string,locale?:string,post_id?:int}|string|null $options
     *        Either an options array or a legacy language string. `post_id`
     *        allows Polylang/WPML integrations to resolve the document language.
     * @return array<int,array{term:string,weight:float,lang:string}>
     *         Occurrences in document order. `weight` is the strongest boost
     *         inherited from ancestor tags, and `lang` is the term language.
     */
    public function analyze_content(string $html, array|string|null $options = []): array
    {
        $options = $this->normalizeLanguageOptions($options, 'document');
        $tokens = [];

        foreach ($this->extractHtmlSegments($html, $options) as $segment) {
            foreach ($this->analyzeText($segment['text'], $segment['lang']) as $term) {
                if ($this->isStopword($term['term'], $term['lang'])) {
                    continue;
                }

                $tokens[] = [
                    'term' => $term['term'],
                    'weight' => $segment['weight'],
                    'lang' => $term['lang'],
                ];
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
     * @return array<int,array{term:string,weight:float,lang:string}>
     */
    public function analyze_content_terms(string $html, array|string|null $language = null): array
    {
        return $this->analyze_content($html, $language);
    }

    /**
     * Query analysis intentionally skips only the HTML extraction stage.
     *
     * Use this for user search text. By default it returns plain term strings
     * for legacy callers. Pass `return => occurrences`, `format => occurrences`,
     * `return => tokens`, or `return => objects` to receive `term/lang` rows.
     *
     * @param array{lang?:string,language?:string,query_lang?:string,locale?:string,return?:string,format?:string,_force_query_lang?:bool}|string|null $options
     *        Query language hints and optional output format. A legacy string is
     *        treated as `query_lang`.
     * @return string[]|array<int,array{term:string,lang:string}>
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
     * @return array<int,array{term:string,lang:string}>
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
     * @param array{lang?:string,language?:string,query_lang?:string,locale?:string,_force_query_lang?:bool}|string|null $options
     * @return array<int,array{term:string,lang:string}>
     */
    public function analyze_query_occurrences(string $query, array|string|null $options = []): array
    {
        $options = $this->normalizeLanguageOptions($options, 'query');
        $lang = $this->resolveQueryLanguage($options);
        $terms = [];

        foreach ($this->queryTextSegments($query, $lang, $options) as $segment) {
            foreach ($this->analyzeText($segment['text'], $segment['lang']) as $term) {
                if ($this->isStopword($term['term'], $term['lang'])) {
                    continue;
                }

                $terms[] = $term;
            }
        }

        return $terms;
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

    /**
     * Run the configured language pipeline for one resolved text segment.
     *
     * @param string $text Visible text from a document segment or query.
     * @param string $lang Segment language, canonicalized with analyzer
     *        fallbacks.
     * @return array<int,array{term:string,lang:string}>
     */
    private function analyzeText(string $text, string $lang): array
    {
        $lang = $this->canonicalLanguage($lang) ?? $this->defaultLanguage;

        return $this->languagePipeline->analyze_detailed($text, $lang);
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

        return $this->languageDetector->detect_text($text) ?? $defaultLang;
    }

    /**
     * Extract visible text segments and the language/weight for each segment.
     *
     * WordPress's HTML processor is preferred because it understands browser-like
     * parsing. When it is unavailable or cannot be created, the fallback parser
     * keeps enough stack state to make skip, boost, and language-scope decisions
     * deterministic for tests and non-WordPress use.
     *
     * @param array{lang?:string,language?:string,document_lang?:string,locale?:string,post_id?:int} $options
     * @return array<int,array{text:string,weight:float,lang:string,explicit_lang?:bool,detect_group?:int}>
     */
    private function extractHtmlSegments(string $html, array $options): array
    {
        $documentLang = $this->resolveDocumentLanguage($options);
        $autoDetect = $this->shouldAutoDetectDocumentLanguage($options);

        if ($this->htmlProcessorFactory !== null || class_exists('WP_HTML_Processor')) {
            $processor = $this->createProcessor($html);
            if ($processor === null) {
                $plain = $this->stripAllTags($html);
                return trim($plain) === '' ? [] : [[
                    'text' => $plain,
                    'weight' => 1.0,
                    'lang' => $autoDetect ? $this->detectSegmentLanguage($plain, $documentLang) : $documentLang,
                    'explicit_lang' => false,
                    'detect_group' => 0,
                ]];
            }

            return $this->maybeDetectSegmentLanguages($this->extractWithProcessor($processor, $documentLang), $documentLang, $autoDetect);
        }

        return $this->maybeDetectSegmentLanguages($this->extractWithFallbackParser($html, $documentLang), $documentLang, $autoDetect);
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
     * Create a WordPress HTML processor for a full document or fragment.
     *
     * A custom factory is used as-is for tests. Native processor creation is
     * wrapped in a catch block because invalid markup or version differences can
     * throw; callers fall back to text stripping/parser logic on null.
     *
     * @param string $html HTML document or fragment.
     * @return mixed Processor-like object, or null when creation fails.
     */
    private function createProcessor(string $html): mixed
    {
        if ($this->htmlProcessorFactory !== null) {
            return ($this->htmlProcessorFactory)($html);
        }

        try {
            if (
                $this->looksLikeFullDocument($html)
                && method_exists('WP_HTML_Processor', 'create_full_parser')
            ) {
                return WP_HTML_Processor::create_full_parser($html);
            }

            return WP_HTML_Processor::create_fragment($html);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Heuristically decide whether WordPress should use full-document parsing.
     *
     * @param string $html HTML input from the caller.
     * @return bool True when document-level tags or doctype are present.
     */
    private function looksLikeFullDocument(string $html): bool
    {
        return (bool) preg_match('/<(?:!doctype|html|head|title)\b/i', $html);
    }

    /**
     * Extract text with `WP_HTML_Processor` while tracking language scopes.
     *
     * `langByDepth` stores only depths that introduced a language. As the
     * processor moves through breadcrumbs, deeper scopes are pruned so optional
     * end tags and implicit closes do not leak a language into following text.
     *
     * @return array<int,array{text:string,weight:float,lang:string,explicit_lang?:bool,detect_group?:int}>
     */
    private function extractWithProcessor(mixed $processor, string $documentLang): array
    {
        $segments = [];
        $langByDepth = [0 => ['lang' => $documentLang, 'explicit' => false]];
        $textGroupByDepth = [];
        $textGroupBoundaryByDepth = [];
        $textGroupCounter = 0;
        $rootTextGroup = null;

        while ($processor->next_token()) {
            $breadcrumbs = method_exists($processor, 'get_breadcrumbs')
                ? ($processor->get_breadcrumbs() ?? [])
                : [];
            $breadcrumbs = array_map(
                static fn($tag): string => strtoupper((string) $tag),
                $breadcrumbs
            );
            $this->pruneLanguageStack($langByDepth, count($breadcrumbs));
            $this->pruneTextGroupStack($textGroupByDepth, $textGroupBoundaryByDepth, count($breadcrumbs));

            if ($processor->get_token_type() === '#tag') {
                $isCloser = method_exists($processor, 'is_tag_closer') && $processor->is_tag_closer();
                $depth = count($breadcrumbs);
                $tag = $this->processorCurrentTag($processor, $breadcrumbs);
                if ($isCloser) {
                    unset($langByDepth[$depth]);
                    unset($textGroupByDepth[$depth], $textGroupBoundaryByDepth[$depth]);
                    continue;
                }

                unset($langByDepth[$depth]);
                unset($textGroupByDepth[$depth], $textGroupBoundaryByDepth[$depth]);
                $lang = $this->processorLangAttribute($processor);
                if ($lang !== null) {
                    $langByDepth[$depth] = ['lang' => $lang, 'explicit' => true];
                }
                if ($tag !== null && $this->isTextGroupBoundaryTag($tag)) {
                    $this->retireCurrentTextGroup($textGroupByDepth, $textGroupBoundaryByDepth, $rootTextGroup);
                    $textGroupCounter++;
                    $textGroupByDepth[$depth] = $textGroupCounter;
                    $textGroupBoundaryByDepth[$depth] = true;
                }
                continue;
            }

            if ($processor->get_token_type() !== '#text') {
                continue;
            }

            if ($this->hasSkippedAncestor($breadcrumbs)) {
                continue;
            }

            $text = (string) $processor->get_modifiable_text();
            if (trim($text) === '') {
                continue;
            }
            $scope = $this->currentLanguageScope($langByDepth);

            $segments[] = [
                'text' => $text,
                'weight' => $this->boostForAncestors($breadcrumbs),
                'lang' => $scope['lang'],
                'explicit_lang' => $scope['explicit'],
                'detect_group' => $this->currentTextGroup(
                    $textGroupByDepth,
                    $textGroupBoundaryByDepth,
                    $rootTextGroup,
                    $textGroupCounter
                ),
            ];
        }

        return $segments;
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
    private function extractWithFallbackParser(string $html, string $documentLang): array
    {
        $parts = preg_split(
            '/(<!--.*?-->|<!\[CDATA\[.*?\]\]>|<[^>]+>)/s',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );
        if ($parts === false) {
            $plain = $this->stripAllTags($html);
            return trim($plain) === '' ? [] : [[
                'text' => $plain,
                'weight' => 1.0,
                'lang' => $documentLang,
                'explicit_lang' => false,
                'detect_group' => 0,
            ]];
        }

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

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if ($part[0] === '<') {
                if (str_starts_with($part, '<!--') || str_starts_with($part, '<![')) {
                    continue;
                }

                if (preg_match('/^<\s*\/\s*([A-Za-z][A-Za-z0-9:-]*)/s', $part, $m)) {
                    $closing = strtoupper($m[1]);
                    while ($stack !== []) {
                        $entry = array_pop($stack);
                        if ($entry['tag'] === $closing) {
                            break;
                        }
                    }
                    continue;
                }

                if (preg_match('/^<\s*([A-Za-z][A-Za-z0-9:-]*)/s', $part, $m)) {
                    $opening = strtoupper($m[1]);
                    $selfClosing = (bool) preg_match('/\/\s*>$/', $part);
                    $this->closeFallbackOptionalEndTags($stack, $opening);
                    if (!isset($voidTags[$opening]) && !$selfClosing) {
                        $isTextGroupBoundary = $this->isTextGroupBoundaryTag($opening);
                        $detectGroup = null;
                        if ($isTextGroupBoundary) {
                            $this->retireFallbackCurrentTextGroup($stack, $rootTextGroup);
                            $textGroupCounter++;
                            $detectGroup = $textGroupCounter;
                        }
                        $stack[] = [
                            'tag' => $opening,
                            'lang' => $this->tagLangAttribute($part),
                            'detect_group' => $detectGroup,
                            'text_group_boundary' => $isTextGroupBoundary,
                        ];
                    }
                }
                continue;
            }

            $ancestors = array_map(
                static fn(array $entry): string => $entry['tag'],
                $stack
            );
            if ($this->hasSkippedAncestor($ancestors)) {
                continue;
            }

            $text = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (trim($text) === '') {
                continue;
            }
            $scope = $this->fallbackCurrentLanguageScope($stack, $documentLang);

            $segments[] = [
                'text' => $text,
                'weight' => $this->boostForAncestors($ancestors),
                'lang' => $scope['lang'],
                'explicit_lang' => $scope['explicit'],
                'detect_group' => $this->fallbackCurrentTextGroup($stack, $rootTextGroup, $textGroupCounter),
            ];
        }

        return $segments;
    }

    /**
     * Decide whether text under any ancestor tag should be skipped.
     *
     * @param string[] $ancestors
     * @return bool True for script/style/template/navigation and configured
     *         skipped ancestors.
     */
    private function hasSkippedAncestor(array $ancestors): bool
    {
        foreach ($ancestors as $tag) {
            if (isset($this->skipAncestors[strtoupper($tag)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the strongest configured boost inherited from ancestor tags.
     *
     * The analyzer does not multiply boosts; the largest ancestor boost wins so
     * nested headings/strong tags do not explode term frequency.
     *
     * @param string[] $ancestors
     * @return float Weight to attach to the text segment.
     */
    private function boostForAncestors(array $ancestors): float
    {
        $weight = 1.0;
        foreach ($ancestors as $tag) {
            $tag = strtoupper($tag);
            if (isset($this->boosts[$tag])) {
                $weight = max($weight, $this->boosts[$tag]);
            }
        }

        return $weight;
    }

    /**
     * Convert markup to plain text when structured parsing is unavailable.
     *
     * WordPress's `wp_strip_all_tags()` is used when available. The fallback
     * removes non-visible script/style-like blocks before decoding entities.
     *
     * @param string $html HTML document or fragment.
     * @return string Plain text candidate for analysis.
     */
    private function stripAllTags(string $html): string
    {
        if (function_exists('wp_strip_all_tags')) {
            return (string) wp_strip_all_tags($html);
        }

        $html = preg_replace('/<(script|style|noscript|template)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Resolve the primary document language for HTML extraction.
     *
     * Precedence is explicit caller hints (`lang`, `language`, `document_lang`,
     * `locale`), constructor `document_lang`, custom resolver, per-post
     * WordPress integrations, per-call `default_lang`, site language, then
     * analyzer default.
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
            $this->wordpressDocumentLanguage($options),
            $options['default_lang'] ?? null,
            $this->wordpressSiteLanguage(),
            $this->defaultLanguage,
        ]) ?? 'en';
    }

    /**
     * Resolve the language used for query analysis.
     *
     * Precedence is explicit query hints, constructor `query_lang`, custom
     * resolver, current WordPress language, per-call `default_lang`, site
     * language, then analyzer default.
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
            $this->wordpressQueryLanguage(),
            $options['default_lang'] ?? null,
            $this->wordpressSiteLanguage(),
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
        if ($parts === []) {
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

        return $this->canonicalLanguage($this->wordpressDocumentLanguage($options)) === null;
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

        return $this->canonicalLanguage($this->wordpressQueryLanguage()) === null;
    }

    /**
     * Resolve a post's language through common multilingual plugins.
     *
     * Polylang is checked first through `pll_get_post_language($postId,
     * 'locale')`. WPML is checked through the `wpml_post_language_details`
     * filter and accepts either array or object responses.
     *
     * @param array<string,mixed> $options
     * @return string|null Raw language candidate, or null outside those plugins.
     */
    private function wordpressDocumentLanguage(array $options): ?string
    {
        $postId = isset($options['post_id']) ? (int) $options['post_id'] : 0;

        if ($postId > 0 && function_exists('pll_get_post_language')) {
            $language = pll_get_post_language($postId, 'locale');
            if (is_scalar($language) && trim((string) $language) !== '') {
                return (string) $language;
            }
        }

        if ($postId > 0 && function_exists('apply_filters')) {
            $details = apply_filters('wpml_post_language_details', null, $postId);
            if (is_array($details)) {
                return (string) ($details['locale'] ?? $details['language_code'] ?? '');
            }
            if (is_object($details)) {
                return (string) ($details->locale ?? $details->language_code ?? '');
            }
        }

        return null;
    }

    /**
     * Resolve the current query language from multilingual WordPress plugins.
     *
     * @return string|null Current language candidate from Polylang or WPML.
     */
    private function wordpressQueryLanguage(): ?string
    {
        if (function_exists('pll_current_language')) {
            $language = pll_current_language('locale');
            if (is_scalar($language) && trim((string) $language) !== '') {
                return (string) $language;
            }
        }

        if (function_exists('apply_filters')) {
            $language = apply_filters('wpml_current_language', null);
            if (is_scalar($language)) {
                return (string) $language;
            }
        }

        return null;
    }

    /**
     * Resolve the site-level language from WordPress globals.
     *
     * `get_locale()` wins over `get_bloginfo('language')` because it usually
     * carries a more specific region value.
     *
     * @return string|null Site language candidate, or null outside WordPress.
     */
    private function wordpressSiteLanguage(): ?string
    {
        if (function_exists('get_locale')) {
            $locale = get_locale();
            if (is_scalar($locale)) {
                return (string) $locale;
            }
        }

        if (function_exists('get_bloginfo')) {
            $language = get_bloginfo('language');
            if (is_scalar($language)) {
                return (string) $language;
            }
        }

        return null;
    }

    /**
     * Remove language scopes deeper than the processor's current depth.
     *
     * The WordPress processor has already resolved optional/implicit end tags in
     * its breadcrumb stack. Pruning by depth keeps `langByDepth` aligned with
     * that canonical tree.
     *
     * @param array<int,array{lang:string,explicit:bool}> $langByDepth
     */
    private function pruneLanguageStack(array &$langByDepth, int $depth): void
    {
        foreach (array_keys($langByDepth) as $scopeDepth) {
            if ($scopeDepth > $depth) {
                unset($langByDepth[$scopeDepth]);
            }
        }
    }

    /**
     * Remove text-detection groups deeper than the processor's current depth.
     *
     * @param array<int,int|null> $textGroupByDepth
     * @param array<int,bool> $textGroupBoundaryByDepth
     */
    private function pruneTextGroupStack(array &$textGroupByDepth, array &$textGroupBoundaryByDepth, int $depth): void
    {
        foreach (array_keys($textGroupByDepth) as $scopeDepth) {
            if ($scopeDepth > $depth) {
                unset($textGroupByDepth[$scopeDepth]);
            }
        }

        foreach (array_keys($textGroupBoundaryByDepth) as $scopeDepth) {
            if ($scopeDepth > $depth) {
                unset($textGroupBoundaryByDepth[$scopeDepth]);
            }
        }
    }

    /**
     * Close the current inline text run before a child boundary starts.
     *
     * A boundary element such as `p` gets its own detection group. Retiring the
     * parent run here prevents direct sibling text after that boundary from
     * being concatenated with direct text that came before it.
     *
     * @param array<int,int|null> $textGroupByDepth
     * @param array<int,bool> $textGroupBoundaryByDepth
     */
    private function retireCurrentTextGroup(
        array &$textGroupByDepth,
        array $textGroupBoundaryByDepth,
        ?int &$rootTextGroup
    ): void {
        krsort($textGroupBoundaryByDepth, SORT_NUMERIC);

        foreach ($textGroupBoundaryByDepth as $depth => $_active) {
            $textGroupByDepth[(int) $depth] = null;
            return;
        }

        $rootTextGroup = null;
    }

    /**
     * Return the nearest active visible-text detection group.
     *
     * Inline markup inherits the surrounding block group's language context, so
     * weak connector words split into their own text node can still be detected
     * with the phrase around them.
     *
     * @param array<int,int|null> $textGroupByDepth
     * @param array<int,bool> $textGroupBoundaryByDepth
     */
    private function currentTextGroup(
        array &$textGroupByDepth,
        array $textGroupBoundaryByDepth,
        ?int &$rootTextGroup,
        int &$textGroupCounter
    ): int
    {
        krsort($textGroupBoundaryByDepth, SORT_NUMERIC);

        foreach ($textGroupBoundaryByDepth as $depth => $_active) {
            $depth = (int) $depth;
            if (isset($textGroupByDepth[$depth]) && $textGroupByDepth[$depth] !== null) {
                return (int) $textGroupByDepth[$depth];
            }

            $textGroupCounter++;
            $textGroupByDepth[$depth] = $textGroupCounter;
            return $textGroupCounter;
        }

        if ($rootTextGroup === null) {
            $textGroupCounter++;
            $rootTextGroup = $textGroupCounter;
        }

        return $rootTextGroup;
    }

    /**
     * Return the current processor tag name when available.
     *
     * @param string[] $breadcrumbs
     */
    private function processorCurrentTag(mixed $processor, array $breadcrumbs): ?string
    {
        if (method_exists($processor, 'get_tag')) {
            try {
                $tag = $processor->get_tag();
                if (is_scalar($tag) && trim((string) $tag) !== '') {
                    return strtoupper((string) $tag);
                }
            } catch (Throwable) {
                // Fall back to breadcrumbs below.
            }
        }

        if ($breadcrumbs === []) {
            return null;
        }

        $tag = end($breadcrumbs);
        return is_string($tag) && $tag !== '' ? strtoupper($tag) : null;
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
     * Return the nearest active language scope.
     *
     * @param array<int,array{lang:string,explicit:bool}> $langByDepth Map of
     *        processor depth to language. Depth 0 always carries the fallback
     *        document language.
     * @return array{lang:string,explicit:bool} Language from the deepest active
     *         scope and whether it came from an HTML attribute.
     */
    private function currentLanguageScope(array $langByDepth): array
    {
        krsort($langByDepth, SORT_NUMERIC);

        $scope = reset($langByDepth);
        if (is_array($scope) && isset($scope['lang'])) {
            return [
                'lang' => (string) $scope['lang'],
                'explicit' => (bool) ($scope['explicit'] ?? false),
            ];
        }

        return ['lang' => 'en', 'explicit' => false];
    }

    /**
     * Read and canonicalize `lang` or `xml:lang` from the current processor tag.
     *
     * @param mixed $processor WordPress HTML processor or compatible test double.
     * @return string|null Canonical language when the current tag declares one.
     */
    private function processorLangAttribute(mixed $processor): ?string
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

            $lang = $this->canonicalLanguage($value);
            if ($lang !== null) {
                return $lang;
            }
        }

        return null;
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
        if (!preg_match('/^<\s*[A-Za-z][A-Za-z0-9:-]*/', $tag, $m)) {
            return [];
        }

        $attributes = [];
        $length = strlen($tag);
        $offset = strlen($m[0]);

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
     * @param array<int,array{tag:string,lang:?string,detect_group:?int,text_group_boundary?:bool}> $stack
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
     * Return the nearest language scope in the fallback parser stack.
     *
     * @param array<int,array{tag:string,lang:?string,detect_group:?int,text_group_boundary?:bool}> $stack
     * @param string $documentLang Fallback language outside any scoped tag.
     * @return array{lang:string,explicit:bool} Effective language for the
     *         current text node and whether it came from an HTML attribute.
     */
    private function fallbackCurrentLanguageScope(array $stack, string $documentLang): array
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if ($stack[$i]['lang'] !== null) {
                return ['lang' => $stack[$i]['lang'], 'explicit' => true];
            }
        }

        return ['lang' => $documentLang, 'explicit' => false];
    }

    /**
     * Close the current fallback inline text run before a child boundary starts.
     *
     * @param array<int,array{tag:string,lang:?string,detect_group:?int,text_group_boundary?:bool}> $stack
     */
    private function retireFallbackCurrentTextGroup(array &$stack, ?int &$rootTextGroup): void
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if (!empty($stack[$i]['text_group_boundary'])) {
                $stack[$i]['detect_group'] = null;
                return;
            }
        }

        $rootTextGroup = null;
    }

    /**
     * Return or allocate the nearest visible-text detection group in the fallback parser.
     *
     * @param array<int,array{tag:string,lang:?string,detect_group:?int,text_group_boundary?:bool}> $stack
     */
    private function fallbackCurrentTextGroup(array &$stack, ?int &$rootTextGroup, int &$textGroupCounter): int
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if (empty($stack[$i]['text_group_boundary'])) {
                continue;
            }

            if (isset($stack[$i]['detect_group']) && $stack[$i]['detect_group'] !== null) {
                return (int) $stack[$i]['detect_group'];
            }

            $textGroupCounter++;
            $stack[$i]['detect_group'] = $textGroupCounter;
            return $textGroupCounter;
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
            'version' => 4,
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
            'html_processor_available' => class_exists('WP_HTML_Processor'),
        ];

        return 'wp-fts-analyzer-v4:' . sha1($this->stableJson($payload));
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
    private function callableSignature(mixed $callback): ?string
    {
        if (!is_callable($callback)) {
            return null;
        }

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
