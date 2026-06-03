<?php
declare(strict_types=1);

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

    private WP_FTS_LanguagePipeline $languagePipeline;
    private string $defaultLanguage;
    private ?string $documentLanguage;
    private ?string $queryLanguage;

    /**
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
     *   language_pipeline?:WP_FTS_LanguagePipeline,
     *   stemmer?:WP_FTS_Stemmer|callable|null,
     *   stopwords_by_lang?:array<string,string[]>,
     *   document_language_resolver?:callable|null,
     *   query_language_resolver?:callable|null,
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
        $this->languagePipeline = $options['language_pipeline'] ?? new WP_FTS_LanguagePipeline([
            'min_term_len' => (int) ($options['min_term_len'] ?? 2),
            'max_term_bytes' => (int) ($options['max_term_bytes'] ?? 255),
            'fold_diacritics' => (bool) ($options['fold_diacritics'] ?? true),
            'namespace_terms' => (bool) ($options['namespace_terms'] ?? false),
            'enable_stemming' => (bool) ($options['enable_stemming'] ?? false),
            'polish_stemming' => (string) ($options['polish_stemming'] ?? 'conservative'),
            'stemmer' => $options['stemmer'] ?? null,
        ]);

        $this->defaultLanguage = $this->canonicalLanguage($options['default_lang'] ?? $options['language'] ?? null) ?? 'und';
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
    }

    /**
     * Analyze HTML content and return weighted token occurrences in source order.
     *
     * @param array{lang?:string,language?:string,document_lang?:string,locale?:string,post_id?:int}|string|null $options
     * @return array<int,array{term:string,weight:float,lang:string}>
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
     * Legacy structured alias retained for callers from the stemmer lane.
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
     * @param array{lang?:string,language?:string,query_lang?:string,locale?:string,return?:string,format?:string}|string|null $options
     * @return string[]|array<int,array{term:string,lang:string}>
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
     * Legacy structured alias retained for callers from the stemmer lane.
     *
     * @param array<string,mixed>|string|null $language
     * @return array<int,array{term:string,lang:string}>
     */
    public function analyze_query_terms(string $query, array|string|null $language = null): array
    {
        return $this->analyze_query_occurrences($query, $language);
    }

    /**
     * Language-aware query analysis for new call sites.
     *
     * @param array{lang?:string,language?:string,query_lang?:string,locale?:string}|string|null $options
     * @return array<int,array{term:string,lang:string}>
     */
    public function analyze_query_occurrences(string $query, array|string|null $options = []): array
    {
        $options = $this->normalizeLanguageOptions($options, 'query');
        $lang = $this->resolveQueryLanguage($options);
        $terms = [];

        foreach ($this->analyzeText($query, $lang) as $term) {
            if ($this->isStopword($term['term'], $term['lang'])) {
                continue;
            }

            $terms[] = $term;
        }

        return $terms;
    }

    /**
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

    public static function namespaced_term(string $term, string $lang): string
    {
        return self::canonicalLanguageStatic($lang) . "\x1e" . $term;
    }

    /**
     * @return array<int,array{term:string,lang:string}>
     */
    private function analyzeText(string $text, string $lang): array
    {
        $lang = $this->canonicalLanguage($lang) ?? $this->defaultLanguage;

        return $this->languagePipeline->analyze_detailed($text, $lang);
    }

    /**
     * @param array{lang?:string,language?:string,document_lang?:string,locale?:string,post_id?:int} $options
     * @return array<int,array{text:string,weight:float,lang:string}>
     */
    private function extractHtmlSegments(string $html, array $options): array
    {
        $documentLang = $this->resolveDocumentLanguage($options);

        if ($this->htmlProcessorFactory !== null || class_exists('WP_HTML_Processor')) {
            $processor = $this->createProcessor($html);
            if ($processor === null) {
                $plain = $this->stripAllTags($html);
                return trim($plain) === '' ? [] : [[
                    'text' => $plain,
                    'weight' => 1.0,
                    'lang' => $documentLang,
                ]];
            }

            return $this->extractWithProcessor($processor, $documentLang);
        }

        return $this->extractWithFallbackParser($html, $documentLang);
    }

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

    private function looksLikeFullDocument(string $html): bool
    {
        return (bool) preg_match('/<(?:!doctype|html|head|title)\b/i', $html);
    }

    /**
     * @return array<int,array{text:string,weight:float,lang:string}>
     */
    private function extractWithProcessor(mixed $processor, string $documentLang): array
    {
        $segments = [];
        $langByDepth = [0 => $documentLang];

        while ($processor->next_token()) {
            $breadcrumbs = method_exists($processor, 'get_breadcrumbs')
                ? ($processor->get_breadcrumbs() ?? [])
                : [];
            $breadcrumbs = array_map(
                static fn($tag): string => strtoupper((string) $tag),
                $breadcrumbs
            );
            $this->pruneLanguageStack($langByDepth, count($breadcrumbs));

            if ($processor->get_token_type() === '#tag') {
                $isCloser = method_exists($processor, 'is_tag_closer') && $processor->is_tag_closer();
                $depth = count($breadcrumbs);
                if ($isCloser) {
                    unset($langByDepth[$depth]);
                    continue;
                }

                unset($langByDepth[$depth]);
                $lang = $this->processorLangAttribute($processor);
                if ($lang !== null) {
                    $langByDepth[$depth] = $lang;
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

            $segments[] = [
                'text' => $text,
                'weight' => $this->boostForAncestors($breadcrumbs),
                'lang' => $this->currentLanguage($langByDepth),
            ];
        }

        return $segments;
    }

    /**
     * Test and non-WordPress fallback parser. It is deliberately small, but keeps a
     * tag stack so skip, boost, and lang decisions follow the ancestor model.
     *
     * @return array<int,array{text:string,weight:float,lang:string}>
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
            ]];
        }

        $stack = [];
        $segments = [];
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
                        $stack[] = [
                            'tag' => $opening,
                            'lang' => $this->tagLangAttribute($part),
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

            $segments[] = [
                'text' => $text,
                'weight' => $this->boostForAncestors($ancestors),
                'lang' => $this->fallbackCurrentLanguage($stack, $documentLang),
            ];
        }

        return $segments;
    }

    /**
     * @param string[] $ancestors
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
     * @param string[] $ancestors
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

    private function stripAllTags(string $html): string
    {
        if (function_exists('wp_strip_all_tags')) {
            return (string) wp_strip_all_tags($html);
        }

        $html = preg_replace('/<(script|style|noscript|template)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param array<string,mixed> $options
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
            $this->wordpressSiteLanguage(),
            $this->defaultLanguage,
        ]) ?? 'und';
    }

    /**
     * @param array<string,mixed> $options
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
            $this->wordpressSiteLanguage(),
            $this->defaultLanguage,
        ]) ?? 'und';
    }

    /**
     * @param array<int,mixed> $candidates
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
            } elseif (strlen($part) === 4 && ctype_alpha($part)) {
                $canonical[] = ucfirst(strtolower($part));
            } elseif ((strlen($part) === 2 && ctype_alpha($part)) || (strlen($part) === 3 && ctype_digit($part))) {
                $canonical[] = strtoupper($part);
            } else {
                $canonical[] = strtolower($part);
            }
        }

        return $canonical === [] ? 'und' : implode('-', $canonical);
    }

    /**
     * @param array<string,mixed> $options
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
     * @param array<string,mixed> $options
     */
    private function wordpressDocumentLanguage(array $options): ?string
    {
        $postId = isset($options['post_id']) ? (int) $options['post_id'] : 0;

        if ($postId > 0 && function_exists('pll_get_post_language')) {
            $language = pll_get_post_language($postId, 'locale');
            if (is_scalar($language)) {
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

    private function wordpressQueryLanguage(): ?string
    {
        if (function_exists('pll_current_language')) {
            $language = pll_current_language('locale');
            if (is_scalar($language)) {
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
     * @param array<int,string> $langByDepth
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
     * @param array<int,string> $langByDepth
     */
    private function currentLanguage(array $langByDepth): string
    {
        krsort($langByDepth, SORT_NUMERIC);

        return (string) reset($langByDepth);
    }

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

    private function tagLangAttribute(string $tag): ?string
    {
        if (!preg_match('/\b(?:xml:)?lang\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>\/]+))/i', $tag, $m)) {
            return null;
        }

        $doubleQuoted = $m[1] ?? '';
        $singleQuoted = $m[2] ?? '';
        $unquoted = $m[3] ?? '';
        $value = $doubleQuoted !== '' ? $doubleQuoted : ($singleQuoted !== '' ? $singleQuoted : $unquoted);

        return $this->canonicalLanguage(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @param array<int,array{tag:string,lang:?string}> $stack
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
     * @param array<int,array{tag:string,lang:?string}> $stack
     */
    private function fallbackCurrentLanguage(array $stack, string $documentLang): string
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if ($stack[$i]['lang'] !== null) {
                return $stack[$i]['lang'];
            }
        }

        return $documentLang;
    }

    private function isStopword(string $term, string $lang): bool
    {
        if (isset($this->stopwords[$term])) {
            return true;
        }

        $baseLang = explode('-', $lang, 2)[0];

        return isset($this->stopwordsByLang[$lang][$term]) || isset($this->stopwordsByLang[$baseLang][$term]);
    }

    /**
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
