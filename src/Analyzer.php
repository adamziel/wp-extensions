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
    private $stemmer;

    /** @var array<string,callable> */
    private array $stemmers;

    /** @var callable|null */
    private $htmlProcessorFactory;

    /** @var callable|null */
    private $documentLanguageResolver;

    /** @var callable|null */
    private $queryLanguageResolver;

    private int $minTermLen;
    private int $maxTermBytes;
    private bool $foldDiacritics;
    private string $defaultLang;
    private ?string $documentLang;
    private ?string $queryLang;

    /**
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
     *   stemmer?:callable|null,
     *   stemmers?:array<string,callable>,
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

        $this->minTermLen = max(1, (int) ($options['min_term_len'] ?? 2));
        $this->maxTermBytes = max(1, (int) ($options['max_term_bytes'] ?? 255));
        $this->foldDiacritics = (bool) ($options['fold_diacritics'] ?? true);
        $this->stemmer = $options['stemmer'] ?? null;
        $this->stemmers = [];
        foreach (($options['stemmers'] ?? []) as $lang => $stemmer) {
            if (is_callable($stemmer)) {
                $canonical = $this->canonicalLanguage((string) $lang);
                if ($canonical !== null) {
                    $this->stemmers[$canonical] = $stemmer;
                }
            }
        }
        $this->htmlProcessorFactory = $options['html_processor_factory'] ?? null;
        $this->documentLanguageResolver = $options['document_language_resolver'] ?? null;
        $this->queryLanguageResolver = $options['query_language_resolver'] ?? null;
        $this->defaultLang = $this->canonicalLanguage($options['default_lang'] ?? null) ?? 'en';
        $this->documentLang = $this->canonicalLanguage($options['document_lang'] ?? null);
        $this->queryLang = $this->canonicalLanguage($options['query_lang'] ?? null);

        $this->stopwords = [];
        foreach (($options['stopwords'] ?? []) as $word) {
            $normalized = $this->normalizeToken((string) $word, $this->defaultLang);
            if ($normalized !== null) {
                $this->stopwords[$normalized] = true;
            }
        }

        $this->stopwordsByLang = [];
        foreach (($options['stopwords_by_lang'] ?? []) as $lang => $words) {
            $canonical = $this->canonicalLanguage((string) $lang);
            if ($canonical === null || !is_array($words)) {
                continue;
            }

            foreach ($words as $word) {
                $normalized = $this->normalizeToken((string) $word, $canonical);
                if ($normalized !== null) {
                    $this->stopwordsByLang[$canonical][$normalized] = true;
                }
            }
        }
    }

    /**
     * Analyze HTML content and return weighted token occurrences in source order.
     *
     * @param array{lang?:string,language?:string,document_lang?:string,locale?:string,post_id?:int} $options
     * @return array<int,array{term:string,weight:float,lang:string}>
     */
    public function analyze_content(string $html, array $options = []): array
    {
        $tokens = [];
        foreach ($this->extractHtmlSegments($html, $options) as $segment) {
            foreach ($this->tokenizeText($segment['text'], $segment['lang']) as $term) {
                $tokens[] = [
                    'term' => $term,
                    'weight' => $segment['weight'],
                    'lang' => $segment['lang'],
                ];
            }
        }

        return $tokens;
    }

    /**
     * Query analysis intentionally skips only the HTML extraction stage.
     *
     * @param array{lang?:string,language?:string,query_lang?:string,locale?:string,return?:string,format?:string} $options
     * @return string[]|array<int,array{term:string,lang:string}>
     */
    public function analyze_query(string $query, array $options = []): array
    {
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
     * Language-aware query analysis for new call sites.
     *
     * @param array{lang?:string,language?:string,query_lang?:string,locale?:string} $options
     * @return array<int,array{term:string,lang:string}>
     */
    public function analyze_query_occurrences(string $query, array $options = []): array
    {
        $lang = $this->resolveQueryLanguage($options);
        $occurrences = [];
        foreach ($this->tokenizeText($query, $lang) as $term) {
            $occurrences[] = [
                'term' => $term,
                'lang' => $lang,
            ];
        }

        return $occurrences;
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
        return $lang . "\x1e" . $term;
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
     * tag stack so skip and boost decisions follow the same ancestor-based model.
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
                        $tag = $entry['tag'];
                        if ($tag === $closing) {
                            break;
                        }
                    }
                    continue;
                }

                if (preg_match('/^<\s*([A-Za-z][A-Za-z0-9:-]*)/s', $part, $m)) {
                    $opening = strtoupper($m[1]);
                    $selfClosing = (bool) preg_match('/\/\s*>$/', $part);
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

    /**
     * @return string[]
     */
    private function tokenizeText(string $text, string $lang): array
    {
        $text = $this->ensureUtf8($text);
        $matches = [];
        if (@preg_match_all('/[\p{L}\p{N}_]+/u', $text, $matches) === false) {
            return [];
        }

        $terms = [];
        foreach ($matches[0] ?? [] as $rawToken) {
            foreach ($this->splitScriptRuns($rawToken) as $run) {
                if ($run['script'] === 'cjk') {
                    foreach ($this->cjkTokens($run['text']) as $rawCjkToken) {
                        $term = $this->normalizeToken($rawCjkToken, $lang, true);
                        if ($term === null || $this->isStopword($term, $lang)) {
                            continue;
                        }

                        $terms[] = $term;
                    }
                    continue;
                }

                $term = $this->normalizeToken($run['text'], $lang);
                if ($term === null || $this->isStopword($term, $lang)) {
                    continue;
                }

                $terms[] = $term;
            }
        }

        return $terms;
    }

    private function normalizeToken(string $token, string $lang, bool $isCjk = false): ?string
    {
        $token = $this->lowercase($token);

        if (!$isCjk && $this->foldDiacritics) {
            $token = $this->foldDiacritics($token);
            $token = $this->lowercase($token);
        }

        if (!$isCjk) {
            $token = $this->stemToken($token, $lang);
        }

        $length = $this->utf8Length($token);
        if ((!$isCjk && $length < $this->minTermLen) || strlen($token) > $this->maxTermBytes) {
            return null;
        }

        return $token;
    }

    private function foldDiacritics(string $text): string
    {
        if (!preg_match('/[^\x00-\x7F]/', $text)) {
            return $text;
        }

        $text = strtr($text, [
            'À' => 'A',
            'Á' => 'A',
            'Â' => 'A',
            'Ã' => 'A',
            'Ä' => 'A',
            'Å' => 'A',
            'Æ' => 'AE',
            'Ç' => 'C',
            'È' => 'E',
            'É' => 'E',
            'Ê' => 'E',
            'Ë' => 'E',
            'Ì' => 'I',
            'Í' => 'I',
            'Î' => 'I',
            'Ï' => 'I',
            'Ð' => 'D',
            'Ñ' => 'N',
            'Ò' => 'O',
            'Ó' => 'O',
            'Ô' => 'O',
            'Õ' => 'O',
            'Ö' => 'O',
            'Ø' => 'O',
            'Ù' => 'U',
            'Ú' => 'U',
            'Û' => 'U',
            'Ü' => 'U',
            'Ý' => 'Y',
            'Þ' => 'TH',
            'ß' => 'ss',
            'à' => 'a',
            'á' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'å' => 'a',
            'æ' => 'ae',
            'ç' => 'c',
            'è' => 'e',
            'é' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'ì' => 'i',
            'í' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ð' => 'd',
            'ñ' => 'n',
            'ò' => 'o',
            'ó' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ø' => 'o',
            'ù' => 'u',
            'ú' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ý' => 'y',
            'þ' => 'th',
            'ÿ' => 'y',
            'Ā' => 'A',
            'ā' => 'a',
            'Ă' => 'A',
            'ă' => 'a',
            'ą' => 'a',
            'Ą' => 'A',
            'Ć' => 'C',
            'ć' => 'c',
            'Ĉ' => 'C',
            'ĉ' => 'c',
            'Č' => 'C',
            'č' => 'c',
            'Ď' => 'D',
            'ď' => 'd',
            'Đ' => 'D',
            'đ' => 'd',
            'Ē' => 'E',
            'ē' => 'e',
            'Ė' => 'E',
            'ė' => 'e',
            'Ę' => 'E',
            'ę' => 'e',
            'Ě' => 'E',
            'ě' => 'e',
            'Ğ' => 'G',
            'ğ' => 'g',
            'Ī' => 'I',
            'ī' => 'i',
            'İ' => 'I',
            'ı' => 'i',
            'Ł' => 'L',
            'ł' => 'l',
            'Ń' => 'N',
            'ń' => 'n',
            'Ň' => 'N',
            'ň' => 'n',
            'Ō' => 'O',
            'ō' => 'o',
            'Ő' => 'O',
            'ő' => 'o',
            'Œ' => 'OE',
            'œ' => 'oe',
            'Ř' => 'R',
            'ř' => 'r',
            'Ś' => 'S',
            'ś' => 's',
            'Ş' => 'S',
            'ş' => 's',
            'Š' => 'S',
            'š' => 's',
            'Ť' => 'T',
            'ť' => 't',
            'Ū' => 'U',
            'ū' => 'u',
            'Ů' => 'U',
            'ů' => 'u',
            'Ű' => 'U',
            'ű' => 'u',
            'Ź' => 'Z',
            'ź' => 'z',
            'Ż' => 'Z',
            'ż' => 'z',
            'Ž' => 'Z',
            'ž' => 'z',
        ]);

        if (!preg_match('/[^\x00-\x7F]/', $text)) {
            return $text;
        }

        if (function_exists('transliterator_transliterate')) {
            $converted = transliterator_transliterate(
                'NFD; [:Nonspacing Mark:] Remove; NFC; Any-Latin; Latin-ASCII',
                $text
            );
            if (is_string($converted)) {
                return $converted;
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($converted)) {
                return $converted;
            }
        }

        return $text;
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
     * @param array<string,string> $options
     */
    private function resolveDocumentLanguage(array $options): string
    {
        return $this->firstLanguage([
            $options['lang'] ?? null,
            $options['language'] ?? null,
            $options['document_lang'] ?? null,
            $options['locale'] ?? null,
            $this->documentLang,
            $this->callLanguageResolver($this->documentLanguageResolver, $options),
            $this->wordpressDocumentLanguage($options),
            $this->wordpressSiteLanguage(),
            $this->defaultLang,
        ]) ?? 'en';
    }

    /**
     * @param array<string,string> $options
     */
    private function resolveQueryLanguage(array $options): string
    {
        return $this->firstLanguage([
            $options['lang'] ?? null,
            $options['language'] ?? null,
            $options['query_lang'] ?? null,
            $options['locale'] ?? null,
            $this->queryLang,
            $this->callLanguageResolver($this->queryLanguageResolver, $options),
            $this->wordpressQueryLanguage(),
            $this->wordpressSiteLanguage(),
            $this->defaultLang,
        ]) ?? 'en';
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

        return implode('-', $canonical);
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
    private function fallbackCurrentLanguage(array $stack, string $documentLang): string
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if ($stack[$i]['lang'] !== null) {
                return $stack[$i]['lang'];
            }
        }

        return $documentLang;
    }

    private function ensureUtf8(string $text): string
    {
        if (@preg_match('//u', $text) === 1) {
            return $text;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            if (is_string($converted) && @preg_match('//u', $converted) === 1) {
                return $converted;
            }
        }

        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', ' ', $text) ?? '';
    }

    /**
     * @return array<int,array{text:string,script:string}>
     */
    private function splitScriptRuns(string $token): array
    {
        $chars = $this->utf8Chars($token);
        $runs = [];
        $current = '';
        $currentScript = null;

        foreach ($chars as $char) {
            $script = $this->isCjkChar($char) ? 'cjk' : 'word';
            if ($current !== '' && $script !== $currentScript) {
                $runs[] = ['text' => $current, 'script' => (string) $currentScript];
                $current = '';
            }

            $current .= $char;
            $currentScript = $script;
        }

        if ($current !== '') {
            $runs[] = ['text' => $current, 'script' => (string) $currentScript];
        }

        return $runs;
    }

    /**
     * @return string[]
     */
    private function cjkTokens(string $run): array
    {
        $chars = $this->utf8Chars($run);
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
    private function utf8Chars(string $text): array
    {
        $chars = [];
        if (preg_match_all('/./us', $text, $matches)) {
            $chars = $matches[0];
        }

        return $chars;
    }

    private function isCjkChar(string $char): bool
    {
        return (bool) preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $char);
    }

    private function lowercase(string $text): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($text, 'UTF-8')
            : strtolower($text);
    }

    private function utf8Length(string $text): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }

        return count($this->utf8Chars($text));
    }

    private function stemToken(string $token, string $lang): string
    {
        $baseLang = explode('-', $lang, 2)[0];
        $stemmer = $this->stemmers[$lang] ?? $this->stemmers[$baseLang] ?? $this->stemmer;
        if ($stemmer === null) {
            return $token;
        }

        return (string) $stemmer($token);
    }

    private function isStopword(string $term, string $lang): bool
    {
        if (isset($this->stopwords[$term])) {
            return true;
        }

        $baseLang = explode('-', $lang, 2)[0];

        return isset($this->stopwordsByLang[$lang][$term]) || isset($this->stopwordsByLang[$baseLang][$term]);
    }
}
