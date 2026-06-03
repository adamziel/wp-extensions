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

    /** @var callable|null */
    private $stemmer;

    /** @var callable|null */
    private $htmlProcessorFactory;

    private int $minTermLen;
    private int $maxTermBytes;
    private bool $foldDiacritics;
    private string $defaultDocumentLang;
    private string $defaultQueryLang;

    /**
     * @param array{
     *   skip_ancestors?:string[],
     *   boosts?:array<string,float|int>,
     *   stopwords?:string[],
     *   min_term_len?:int,
     *   max_term_bytes?:int,
     *   fold_diacritics?:bool,
     *   lang?:string,
     *   language?:string,
     *   document_lang?:string,
     *   query_lang?:string,
     *   locale?:string,
     *   stemmer?:callable|null,
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
        $this->defaultDocumentLang = WP_FTS_TermNamespace::language_from_options(
            $options,
            'en',
            ['lang', 'language', 'document_lang', 'locale']
        ) ?? 'en';
        $this->defaultQueryLang = WP_FTS_TermNamespace::language_from_options(
            $options,
            $this->defaultDocumentLang,
            ['query_lang', 'lang', 'language', 'locale']
        ) ?? $this->defaultDocumentLang;
        $this->stemmer = $options['stemmer'] ?? null;
        $this->htmlProcessorFactory = $options['html_processor_factory'] ?? null;

        $this->stopwords = [];
        foreach (($options['stopwords'] ?? []) as $word) {
            $normalized = $this->normalizeToken((string) $word);
            if ($normalized !== null) {
                $this->stopwords[$normalized] = true;
            }
        }
    }

    /**
     * Analyze HTML content and return weighted token occurrences in source order.
     *
     * @return array<int,array{term:string,weight:float,lang:string}>
     */
    public function analyze_content(string $html, array $opts = []): array
    {
        $tokens = [];
        $lang = $this->resolveDocumentLanguage($opts);
        foreach ($this->extractHtmlSegments($html) as $segment) {
            foreach ($this->tokenizeText($segment['text']) as $term) {
                $tokens[] = [
                    'term' => $term,
                    'weight' => $segment['weight'],
                    'lang' => $lang,
                ];
            }
        }

        return $tokens;
    }

    /**
     * Query analysis intentionally skips only the HTML extraction stage. It
     * stays a plain-term compatibility shim unless occurrence output is
     * requested.
     *
     * @return string[]|array<int,array{term:string,lang:string}>
     */
    public function analyze_query(string $query, array $opts = []): array
    {
        if ($this->queryRequestsOccurrences($opts)) {
            return $this->analyze_query_occurrences($query, $opts);
        }

        return $this->tokenizeText($query);
    }

    /**
     * @return array<int,array{term:string,lang:string}>
     */
    public function analyze_query_occurrences(string $query, array $opts = []): array
    {
        $lang = $this->resolveQueryLanguage($opts);
        $occurrences = [];
        foreach ($this->tokenizeText($query) as $term) {
            $occurrences[] = [
                'term' => $term,
                'lang' => $lang,
            ];
        }

        return $occurrences;
    }

    /**
     * @param array<int,array{term:string,weight:float,lang?:string}> $occurrences
     * @return array<string,int>
     */
    public function weighted_term_frequencies(array $occurrences): array
    {
        $weights = [];
        foreach ($occurrences as $occurrence) {
            $term = $occurrence['term'];
            $weights[$term] = ($weights[$term] ?? 0.0) + (float) $occurrence['weight'];
        }

        $frequencies = [];
        foreach ($weights as $term => $weight) {
            $frequencies[$term] = max(1, (int) round($weight));
        }
        ksort($frequencies, SORT_STRING);

        return $frequencies;
    }

    /**
     * @return array<int,array{text:string,weight:float}>
     */
    private function extractHtmlSegments(string $html): array
    {
        if ($this->htmlProcessorFactory !== null || class_exists('WP_HTML_Processor')) {
            $processor = $this->createProcessor($html);
            if ($processor === null) {
                $plain = $this->stripAllTags($html);
                return trim($plain) === '' ? [] : [['text' => $plain, 'weight' => 1.0]];
            }

            return $this->extractWithProcessor($processor);
        }

        return $this->extractWithFallbackParser($html);
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
     * @return array<int,array{text:string,weight:float}>
     */
    private function extractWithProcessor(mixed $processor): array
    {
        $segments = [];

        while ($processor->next_token()) {
            if ($processor->get_token_type() !== '#text') {
                continue;
            }

            $breadcrumbs = $processor->get_breadcrumbs() ?? [];
            $breadcrumbs = array_map(
                static fn($tag): string => strtoupper((string) $tag),
                $breadcrumbs
            );
            if ($this->hasSkippedAncestor($breadcrumbs)) {
                continue;
            }

            $text = (string) $processor->get_modifiable_text();
            if (trim($text) === '') {
                continue;
            }

            $segments[] = [
                'text' => html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'weight' => $this->boostForAncestors($breadcrumbs),
            ];
        }

        return $segments;
    }

    /**
     * Test and non-WordPress fallback parser. It is deliberately small, but keeps a
     * tag stack so skip and boost decisions follow the same ancestor-based model.
     *
     * @return array<int,array{text:string,weight:float}>
     */
    private function extractWithFallbackParser(string $html): array
    {
        $parts = preg_split(
            '/(<!--.*?-->|<!\[CDATA\[.*?\]\]>|<[^>]+>)/s',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );
        if ($parts === false) {
            $plain = $this->stripAllTags($html);
            return trim($plain) === '' ? [] : [['text' => $plain, 'weight' => 1.0]];
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
                        $tag = array_pop($stack);
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
                        $stack[] = $opening;
                    }
                }
                continue;
            }

            if ($this->hasSkippedAncestor($stack)) {
                continue;
            }

            $text = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (trim($text) === '') {
                continue;
            }

            $segments[] = [
                'text' => $text,
                'weight' => $this->boostForAncestors($stack),
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
    private function tokenizeText(string $text): array
    {
        $matches = [];
        if (@preg_match_all('/[\p{L}\p{N}_]+/u', $text, $matches) === false) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            preg_match_all('/[\p{L}\p{N}_]+/u', $text, $matches);
        }

        $terms = [];
        foreach ($matches[0] ?? [] as $rawToken) {
            $term = $this->normalizeToken($rawToken);
            if ($term === null || isset($this->stopwords[$term])) {
                continue;
            }

            $terms[] = $term;
        }

        return $terms;
    }

    private function normalizeToken(string $token): ?string
    {
        $token = function_exists('mb_strtolower')
            ? mb_strtolower($token, 'UTF-8')
            : strtolower($token);

        if ($this->foldDiacritics) {
            $token = $this->foldDiacritics($token);
        }

        if ($this->stemmer !== null) {
            $token = (string) ($this->stemmer)($token);
        }

        $length = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);
        if ($length < $this->minTermLen || strlen($token) > $this->maxTermBytes) {
            return null;
        }

        return $token;
    }

    private function resolveDocumentLanguage(array $opts): string
    {
        return WP_FTS_TermNamespace::language_from_options(
            $opts,
            $this->defaultDocumentLang,
            ['lang', 'language', 'document_lang', 'locale']
        ) ?? $this->defaultDocumentLang;
    }

    private function resolveQueryLanguage(array $opts): string
    {
        return WP_FTS_TermNamespace::language_from_options(
            $opts,
            $this->defaultQueryLang,
            ['query_lang', 'lang', 'language', 'locale']
        ) ?? $this->defaultQueryLang;
    }

    private function queryRequestsOccurrences(array $opts): bool
    {
        foreach (['return', 'format'] as $key) {
            if (isset($opts[$key]) && strtolower(trim((string) $opts[$key])) === 'occurrences') {
                return true;
            }
        }

        return false;
    }

    private function foldDiacritics(string $text): string
    {
        if (!preg_match('/[^\x00-\x7F]/', $text)) {
            return $text;
        }

        $text = strtr($text, [
            'ą' => 'a',
            'ć' => 'c',
            'ę' => 'e',
            'ł' => 'l',
            'ń' => 'n',
            'ó' => 'o',
            'ś' => 's',
            'ź' => 'z',
            'ż' => 'z',
            'Ą' => 'A',
            'Ć' => 'C',
            'Ę' => 'E',
            'Ł' => 'L',
            'Ń' => 'N',
            'Ó' => 'O',
            'Ś' => 'S',
            'Ź' => 'Z',
            'Ż' => 'Z',
        ]);

        if (!preg_match('/[^\x00-\x7F]/', $text)) {
            return $text;
        }

        if (class_exists('Transliterator')) {
            $converted = transliterator_transliterate(
                'NFD; [:Nonspacing Mark:] Remove; NFC; Any-Latin; Latin-ASCII',
                $text
            );
            if (is_string($converted)) {
                return $converted;
            }
        }

        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        return is_string($converted) ? $converted : $text;
    }

    private function stripAllTags(string $html): string
    {
        if (function_exists('wp_strip_all_tags')) {
            return (string) wp_strip_all_tags($html);
        }

        $html = preg_replace('/<(script|style|noscript|template)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
