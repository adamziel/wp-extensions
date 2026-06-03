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
    private $htmlProcessorFactory;

    private WP_FTS_LanguagePipeline $languagePipeline;
    private string $defaultLanguage;

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
     *   namespace_terms?:bool,
     *   enable_stemming?:bool,
     *   polish_stemming?:string,
     *   language_pipeline?:WP_FTS_LanguagePipeline,
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

        $this->htmlProcessorFactory = $options['html_processor_factory'] ?? null;
        $this->languagePipeline = $options['language_pipeline'] ?? new WP_FTS_LanguagePipeline([
            'min_term_len' => (int) ($options['min_term_len'] ?? 2),
            'max_term_bytes' => (int) ($options['max_term_bytes'] ?? 255),
            'fold_diacritics' => (bool) ($options['fold_diacritics'] ?? true),
            'namespace_terms' => (bool) ($options['namespace_terms'] ?? false),
            'enable_stemming' => (bool) ($options['enable_stemming'] ?? false),
            'polish_stemming' => (string) ($options['polish_stemming'] ?? 'conservative'),
            'stemmer' => $options['stemmer'] ?? null,
        ]);
        $this->defaultLanguage = $this->languagePipeline->canonicalize_language(
            (string) ($options['default_lang'] ?? $options['language'] ?? 'und')
        );

        $this->stopwords = [];
        foreach (($options['stopwords'] ?? []) as $word) {
            foreach ($this->languagePipeline->analyze((string) $word, $this->defaultLanguage) as $normalized) {
                $this->stopwords[$normalized] = true;
            }
        }
    }

    /**
     * Analyze HTML content and return weighted token occurrences in source order.
     *
     * @return array<int,array{term:string,weight:float,lang:string}>
     */
    public function analyze_content(string $html, ?string $language = null): array
    {
        return $this->analyze_content_terms($html, $language);
    }

    /**
     * Analyze HTML content and return weighted, language-tagged token
     * occurrences in source order.
     *
     * @return array<int,array{term:string,weight:float,lang:string}>
     */
    public function analyze_content_terms(string $html, ?string $language = null): array
    {
        $tokens = [];
        $fallbackLanguage = $this->languagePipeline->canonicalize_language($language ?? $this->defaultLanguage);
        foreach ($this->extractHtmlSegments($html) as $segment) {
            $segmentLanguage = $this->languagePipeline->canonicalize_language($segment['lang'] ?? $fallbackLanguage);
            foreach ($this->languagePipeline->analyze_detailed($segment['text'], $segmentLanguage) as $term) {
                if (isset($this->stopwords[$term['term']])) {
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
     * Query analysis intentionally skips only the HTML extraction stage.
     *
     * @return string[]
     */
    public function analyze_query(string $query, ?string $language = null): array
    {
        return array_map(
            static fn(array $term): string => $term['term'],
            $this->analyze_query_terms($query, $language)
        );
    }

    /**
     * Query analysis intentionally skips only the HTML extraction stage.
     *
     * @return array<int,array{term:string,lang:string}>
     */
    public function analyze_query_terms(string $query, ?string $language = null): array
    {
        $terms = [];
        $language = $this->languagePipeline->canonicalize_language($language ?? $this->defaultLanguage);
        foreach ($this->languagePipeline->analyze_detailed($query, $language) as $term) {
            if (isset($this->stopwords[$term['term']])) {
                continue;
            }

            $terms[] = $term;
        }

        return $terms;
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
     * @return array<int,array{text:string,weight:float,lang?:string}>
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
     * @return array<int,array{text:string,weight:float,lang?:string}>
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
                'text' => $text,
                'weight' => $this->boostForAncestors($breadcrumbs),
            ];
        }

        return $segments;
    }

    /**
     * Test and non-WordPress fallback parser. It is deliberately small, but keeps a
     * tag stack so skip and boost decisions follow the same ancestor-based model.
     *
     * @return array<int,array{text:string,weight:float,lang?:string}>
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

    private function stripAllTags(string $html): string
    {
        if (function_exists('wp_strip_all_tags')) {
            return (string) wp_strip_all_tags($html);
        }

        $html = preg_replace('/<(script|style|noscript|template)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
