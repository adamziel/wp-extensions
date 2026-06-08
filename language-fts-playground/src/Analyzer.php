<?php
declare(strict_types=1);

/**
 * Extracts visible post text and normalizes query/document terms per language.
 *
 * The analyzer deliberately does not call database search features. It turns
 * WordPress content into ordinary PHP tokens, folds selected language-specific
 * characters, and leaves storage/search strategy to the indexer and searcher.
 */
final class Language_FTS_Playground_Analyzer
{
    /** @var array<string,bool> */
    private array $supported_languages = [
        'en' => true,
        'pl' => true,
        'de' => true,
    ];

    /**
     * Query synonyms are expressed as analyzed keys, not raw UI text.
     *
     * @var array<string,array<int,array{source:string,targets:string[],direction:string,weight:float,provenance:string}>>
     */
    private array $query_synonyms = [
        'pl' => [
            [
                'source' => 'szukan',
                'targets' => ['wyszukiwan', 'wyszukiwani'],
                'direction' => 'query_to_index',
                'weight' => 0.55,
                'provenance' => 'language-fts-playground-polish-demo',
            ],
        ],
    ];

    /** @var array<string,bool> */
    private array $skipped_elements = [
        'script' => true,
        'style' => true,
        'template' => true,
        'noscript' => true,
        'svg' => true,
        'math' => true,
    ];

    /**
     * Stopwords are stored after each language's case and diacritic folding.
     *
     * @var array<string,array<string,bool>>
     */
    private array $stopwords = [
        'en' => [
            'a' => true,
            'an' => true,
            'and' => true,
            'are' => true,
            'as' => true,
            'at' => true,
            'be' => true,
            'been' => true,
            'being' => true,
            'but' => true,
            'by' => true,
            'for' => true,
            'from' => true,
            'has' => true,
            'have' => true,
            'had' => true,
            'he' => true,
            'her' => true,
            'his' => true,
            'i' => true,
            'in' => true,
            'is' => true,
            'it' => true,
            'its' => true,
            'of' => true,
            'on' => true,
            'or' => true,
            'our' => true,
            's' => true,
            'she' => true,
            'that' => true,
            'the' => true,
            'their' => true,
            'them' => true,
            'this' => true,
            'to' => true,
            'was' => true,
            'we' => true,
            'were' => true,
            'with' => true,
            'you' => true,
            'your' => true,
        ],
        'pl' => [
            'a' => true,
            'aby' => true,
            'ale' => true,
            'bo' => true,
            'byc' => true,
            'byl' => true,
            'byla' => true,
            'bylo' => true,
            'czy' => true,
            'dla' => true,
            'do' => true,
            'i' => true,
            'ich' => true,
            'jak' => true,
            'jest' => true,
            'ma' => true,
            'na' => true,
            'nie' => true,
            'o' => true,
            'od' => true,
            'oraz' => true,
            'po' => true,
            'pod' => true,
            'przez' => true,
            'sie' => true,
            'ta' => true,
            'ten' => true,
            'to' => true,
            'w' => true,
            'we' => true,
            'z' => true,
            'za' => true,
            'ze' => true,
        ],
        'de' => [
            'aber' => true,
            'am' => true,
            'an' => true,
            'auf' => true,
            'aus' => true,
            'bei' => true,
            'das' => true,
            'dem' => true,
            'den' => true,
            'der' => true,
            'des' => true,
            'die' => true,
            'ein' => true,
            'eine' => true,
            'einem' => true,
            'einen' => true,
            'einer' => true,
            'eines' => true,
            'er' => true,
            'es' => true,
            'fuer' => true,
            'hat' => true,
            'im' => true,
            'in' => true,
            'ist' => true,
            'mit' => true,
            'nicht' => true,
            'sie' => true,
            'und' => true,
            'von' => true,
            'war' => true,
            'wir' => true,
            'zu' => true,
            'zum' => true,
            'zur' => true,
        ],
    ];

    public function canonical_language(string|null $language): string
    {
        return $this->canonical_language_or_null($language) ?? 'en';
    }

    public function canonical_search_language(string|null $language): string
    {
        $candidate = strtolower(trim((string) $language));
        if ($candidate === 'auto') {
            return 'auto';
        }

        return $this->canonical_language_or_null($language) ?? 'auto';
    }

    /**
     * @return string[]
     */
    public function enabled_languages(): array
    {
        return array_keys($this->supported_languages);
    }

    public function resolve_post_language(object $post): string
    {
        foreach (['language', 'lang', 'post_language', 'post_lang'] as $property) {
            if (isset($post->{$property}) && is_scalar($post->{$property})) {
                $language = $this->canonical_language_or_null((string) $post->{$property});
                if ($language !== null) {
                    return $language;
                }
            }
        }

        $post_id = $this->post_id($post);
        if ($post_id > 0 && function_exists('get_post_meta')) {
            foreach (['_language_fts_language', 'language_fts_language', '_lft_language', 'lang'] as $meta_key) {
                $candidate = get_post_meta($post_id, $meta_key, true);
                if (is_scalar($candidate)) {
                    $language = $this->canonical_language_or_null((string) $candidate);
                    if ($language !== null) {
                        return $language;
                    }
                }
            }
        }

        $content = $this->post_string($post, 'post_content');
        $html_language = $this->first_html_language($content);
        if ($html_language !== null) {
            return $html_language;
        }

        return $this->infer_language_from_text(
            $this->post_string($post, 'post_title') . ' ' . $content . ' ' . $this->post_string($post, 'post_excerpt')
        );
    }

    public function normalize_plain_text(string $text): string
    {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', $decoded);

        return trim(is_string($normalized) ? $normalized : $decoded);
    }

    public function extract_searchable_text(string $html): string
    {
        $fields = $this->extract_searchable_fields($html);

        return $this->normalize_plain_text(trim($fields['content'] . ' ' . $fields['alt']));
    }

    /**
     * @return array{content:string,alt:string}
     */
    public function extract_searchable_fields(string $html): array
    {
        $segments = $this->extract_searchable_field_segments($html);

        return [
            'content' => $this->normalize_plain_text(implode(' ', $segments['content'])),
            'alt' => $this->normalize_plain_text(implode(' ', $segments['alt'])),
        ];
    }

    /**
     * @return array{content:string[],alt:string[]}
     */
    public function extract_searchable_field_segments(string $html): array
    {
        if (trim($html) === '') {
            return ['content' => [], 'alt' => []];
        }

        if (class_exists(DOMDocument::class)) {
            return $this->extract_searchable_field_segments_with_dom($html);
        }

        if (class_exists('WP_HTML_Processor')) {
            return $this->extract_searchable_field_segments_with_wp_processor($html);
        }

        return $this->extract_searchable_field_segments_without_dom($html);
    }

    /**
     * @return string[]
     */
    public function analyze_query(string $query, string $language): array
    {
        return array_values(array_unique($this->analyze_text($query, $language)));
    }

    /**
     * @param string[] $query_keys
     * @return array<string,array<int,array{term:string,weight:float,source:string,direction:string,provenance:string}>>
     */
    public function expand_query_synonyms(array $query_keys, string $language): array
    {
        $language = $this->canonical_language($language);
        $query_lookup = array_fill_keys($this->unique_terms($query_keys), true);
        if ($query_lookup === [] || !isset($this->query_synonyms[$language])) {
            return [];
        }

        $expanded = [];
        foreach ($this->query_synonyms[$language] as $rule) {
            $source = (string) $rule['source'];
            $targets = array_values(array_unique(array_map('strval', $rule['targets'])));
            $direction = (string) $rule['direction'];
            $weight = max(0.0, min(1.0, (float) $rule['weight']));
            $provenance = (string) $rule['provenance'];

            if (($direction === 'query_to_index' || $direction === 'bidirectional') && isset($query_lookup[$source])) {
                foreach ($targets as $target) {
                    if ($target === '' || isset($query_lookup[$target])) {
                        continue;
                    }

                    $expanded[$source][$target] = [
                        'term' => $target,
                        'weight' => $weight,
                        'source' => $source,
                        'direction' => $direction,
                        'provenance' => $provenance,
                    ];
                }
            }

            if ($direction !== 'bidirectional') {
                continue;
            }

            foreach ($targets as $target) {
                if (!isset($query_lookup[$target]) || $source === '' || isset($query_lookup[$source])) {
                    continue;
                }

                $expanded[$target][$source] = [
                    'term' => $source,
                    'weight' => $weight,
                    'source' => $target,
                    'direction' => $direction,
                    'provenance' => $provenance,
                ];
            }
        }

        foreach ($expanded as $source => $targets) {
            $expanded[$source] = array_values($targets);
        }

        return $expanded;
    }

    /**
     * @return string[]
     */
    public function analyze_text(string $text, string $language): array
    {
        return $this->analyze_text_with_positions($text, $language)['terms'];
    }

    /**
     * @return array{terms:string[],positions:array<string,int[]>}
     */
    public function analyze_text_with_positions(string $text, string $language): array
    {
        return $this->analyze_segments_with_positions([$text], $language);
    }

    /**
     * @param string[] $segments
     * @return array{terms:string[],positions:array<string,int[]>}
     */
    public function analyze_segments_with_positions(array $segments, string $language): array
    {
        $language = $this->canonical_language($language);

        $terms = [];
        $positions = [];
        $position = 0;
        $has_previous_tokens = false;

        foreach ($segments as $segment) {
            $token_keys = $this->analyze_text_token_keys((string) $segment, $language);
            if ($token_keys === []) {
                continue;
            }

            if ($has_previous_tokens) {
                $position++;
            }

            foreach ($token_keys as $keys) {
                foreach ($keys as $key) {
                    $terms[] = $key;
                    $positions[$key][] = $position;
                }
                $position++;
                $has_previous_tokens = true;
            }
        }

        return [
            'terms' => $terms,
            'positions' => $positions,
        ];
    }

    /**
     * @return array<int,string[]>
     */
    public function analyze_text_token_keys(string $text, string $language): array
    {
        $language = $this->canonical_language($language);
        $text = $this->normalize_plain_text($text);

        if ($text === '') {
            return [];
        }

        $matches = [];
        $match_count = preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);
        if ($match_count === false || $match_count === 0) {
            return [];
        }

        $token_keys = [];
        foreach ($matches[0] as $token) {
            $term = $this->normalize_term($token, $language);
            if ($term === '' || $this->is_stopword($term, $language)) {
                continue;
            }

            $keys = [];
            foreach ($this->term_keys($term, $language) as $key) {
                if ($key === '' || strlen($key) > 255) {
                    continue;
                }

                $keys[] = $key;
            }

            if ($keys !== []) {
                $token_keys[] = array_values(array_unique($keys));
            }
        }

        return $token_keys;
    }

    public function normalize_term(string $term, string $language): string
    {
        $language = $this->canonical_language($language);
        $term = function_exists('mb_strtolower') ? mb_strtolower($term, 'UTF-8') : strtolower($term);

        if ($language === 'pl') {
            return strtr($term, [
                'ą' => 'a',
                'Ą' => 'a',
                'ć' => 'c',
                'Ć' => 'c',
                'ę' => 'e',
                'Ę' => 'e',
                'ł' => 'l',
                'Ł' => 'l',
                'ń' => 'n',
                'Ń' => 'n',
                'ó' => 'o',
                'Ó' => 'o',
                'ś' => 's',
                'Ś' => 's',
                'ź' => 'z',
                'Ź' => 'z',
                'ż' => 'z',
                'Ż' => 'z',
            ]);
        }

        if ($language === 'de') {
            return strtr($term, [
                'ä' => 'ae',
                'Ä' => 'ae',
                'ö' => 'oe',
                'Ö' => 'oe',
                'ü' => 'ue',
                'Ü' => 'ue',
                'ß' => 'ss',
            ]);
        }

        return $term;
    }

    /**
     * @return string[]
     */
    private function term_keys(string $term, string $language): array
    {
        $keys = [$term];

        if ($language === 'pl') {
            foreach ($this->polish_stem_keys($term) as $key) {
                $keys[] = $key;
            }
        } elseif ($language === 'en') {
            foreach ($this->english_stem_keys($term) as $key) {
                $keys[] = $key;
            }
        } elseif ($language === 'de') {
            foreach ($this->german_stem_keys($term) as $key) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Adds conservative English stem keys after lowercasing.
     *
     * This deliberately stays rule-based and small: common plural/verb endings,
     * y/ies, possessive fallout, and a few high-signal irregular plurals.
     *
     * @return string[]
     */
    private function english_stem_keys(string $term): array
    {
        if (strlen($term) < 3 || preg_match('/^[a-z]+$/', $term) !== 1) {
            return [];
        }

        $keys = [];
        $irregulars = [
            'children' => 'child',
            'feet' => 'foot',
            'geese' => 'goose',
            'men' => 'man',
            'mice' => 'mouse',
            'people' => 'person',
            'teeth' => 'tooth',
            'women' => 'woman',
        ];
        if (isset($irregulars[$term])) {
            $keys[] = $irregulars[$term];
        }

        $exact_only = [
            'analysis' => true,
            'basis' => true,
            'bus' => true,
            'news' => true,
            'series' => true,
            'species' => true,
        ];
        if (isset($exact_only[$term])) {
            return array_values(array_unique($keys));
        }

        if (str_ends_with($term, 'ies')) {
            $key = strlen($term) > 4 ? substr($term, 0, -3) . 'y' : substr($term, 0, -1);
            if (strlen($key) >= 3) {
                $keys[] = $key;
            }
        }

        if (str_ends_with($term, 'ves') && strlen($term) >= 5) {
            $base = substr($term, 0, -3);
            if (str_ends_with($term, 'ives') && strlen($base) >= 2) {
                $keys[] = $base . 'fe';
            } elseif (strlen($base) >= 3) {
                $keys[] = $base . 'f';
            }
        }

        if (str_ends_with($term, 'ing') && strlen($term) >= 6) {
            $key = $this->english_ed_ing_key(substr($term, 0, -3));
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        if (str_ends_with($term, 'ied') && strlen($term) >= 4) {
            $keys[] = strlen($term) > 4 ? substr($term, 0, -3) . 'y' : substr($term, 0, -1);
        } elseif (str_ends_with($term, 'eed') && strlen($term) >= 5) {
            $keys[] = substr($term, 0, -1);
        } elseif (str_ends_with($term, 'ed') && strlen($term) >= 5) {
            $key = $this->english_ed_ing_key(substr($term, 0, -2));
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        if (preg_match('/(?:ches|shes|sses|xes|zes|ses|oes)$/', $term) === 1) {
            $key = substr($term, 0, -2);
            if (strlen($key) >= 3) {
                $keys[] = $key;
            }
        } elseif (
            str_ends_with($term, 's') &&
            strlen($term) >= 4 &&
            !str_ends_with($term, 'ss') &&
            !str_ends_with($term, 'us') &&
            !str_ends_with($term, 'is') &&
            !str_ends_with($term, 'ies')
        ) {
            $key = substr($term, 0, -1);
            if (strlen($key) >= 3) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Adds conservative German stem keys after umlaut/ß folding.
     *
     * Rules cover common adjective endings, noun plurals, and regular verb
     * forms, with short-token guards to avoid collapsing compact nouns.
     *
     * @return string[]
     */
    private function german_stem_keys(string $term): array
    {
        if (strlen($term) < 4 || preg_match('/^[a-z]+$/', $term) !== 1) {
            return [];
        }

        $keys = [];
        $handled_ge_participle = false;
        if (str_starts_with($term, 'ge') && strlen($term) >= 7) {
            $participle = substr($term, 2);
            if (str_ends_with($participle, 't')) {
                $key = substr($participle, 0, -1);
                if (strlen($key) >= 5) {
                    $keys[] = $key;
                    $handled_ge_participle = true;
                }
            }

            if (str_ends_with($participle, 'et')) {
                $key = substr($participle, 0, -2);
                if (strlen($key) >= 5) {
                    $keys[] = $key;
                    $handled_ge_participle = true;
                }
            }
        }

        foreach (
            [
                'ern' => 4,
                'ten' => 5,
                'en' => 4,
                'er' => 4,
                'em' => 5,
                'es' => 5,
                'te' => 5,
                'est' => 5,
                'st' => 5,
                't' => 5,
                'e' => 5,
            ] as $suffix => $minimum_length
        ) {
            if ($handled_ge_participle && in_array($suffix, ['ten', 'te', 'est', 'st', 't'], true)) {
                continue;
            }

            if (!str_ends_with($term, $suffix)) {
                continue;
            }

            $key = substr($term, 0, -strlen($suffix));
            if (strlen($key) >= $minimum_length) {
                $keys[] = $key;
                foreach ($this->german_umlaut_plural_keys($key) as $umlaut_key) {
                    $keys[] = $umlaut_key;
                }
            }
        }

        if (str_ends_with($term, 'n')) {
            $key = substr($term, 0, -1);
            if (strlen($key) >= 5 && str_ends_with($key, 'e')) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Adds conservative Polish stem keys after diacritic folding.
     *
     * The normalizer trims common case/adjective endings. Multi-letter endings
     * may produce three-letter noun stems, but broad final-vowel trimming stays
     * limited to longer stems to avoid noisy short matches.
     *
     * @return string[]
     */
    private function polish_stem_keys(string $term): array
    {
        if (strlen($term) < 4 || preg_match('/^[a-z]+$/', $term) !== 1) {
            return [];
        }

        $rules = [
            'owaniach' => 5,
            'owaniami' => 5,
            'owania' => 5,
            'owanie' => 5,
            'owaniu' => 5,
            'skiego' => 4,
            'skiej' => 4,
            'skich' => 4,
            'skimi' => 4,
            'iego' => 4,
            'ymi' => 4,
            'imi' => 4,
            'ami' => 3,
            'ach' => 3,
            'ych' => 4,
            'ich' => 4,
            'ego' => 4,
            'emu' => 4,
            'iej' => 4,
            'owi' => 3,
            'iem' => 4,
            'ym' => 4,
            'im' => 4,
            'om' => 3,
            'ow' => 3,
            'em' => 3,
            'ej' => 4,
            'ii' => 4,
            'ia' => 4,
            'ie' => 4,
            'iu' => 4,
        ];

        $keys = [];
        foreach ($rules as $suffix => $minimum_length) {
            if (substr($term, -strlen($suffix)) !== $suffix) {
                continue;
            }

            $key = substr($term, 0, -strlen($suffix));
            if (strlen($key) >= $minimum_length) {
                $keys[] = $key;
            }
        }

        foreach (['a', 'i', 'e', 'y', 'u', 'o'] as $suffix) {
            if (!str_ends_with($term, $suffix)) {
                continue;
            }

            $key = substr($term, 0, -1);
            if (strlen($key) >= 5) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    private function is_stopword(string $term, string $language): bool
    {
        return isset($this->stopwords[$language][$term]);
    }

    private function english_ed_ing_key(string $base): ?string
    {
        if (strlen($base) < 3 || !$this->contains_english_vowel($base)) {
            return null;
        }

        if ($this->ends_with_trimmed_english_doubled_consonant($base)) {
            return substr($base, 0, -1);
        }

        if (strlen($base) === 3 && $this->is_english_cvc($base)) {
            return $base . 'e';
        }

        return $base;
    }

    private function contains_english_vowel(string $term): bool
    {
        return preg_match('/[aeiouy]/', $term) === 1;
    }

    private function is_english_cvc(string $term): bool
    {
        if (strlen($term) < 3) {
            return false;
        }

        $last_three = substr($term, -3);
        if (preg_match('/^[^aeiou][aeiou][^aeiouwxy]$/', $last_three) !== 1) {
            return false;
        }

        return preg_match('/^[a-z]+$/', $last_three) === 1;
    }

    private function ends_with_trimmed_english_doubled_consonant(string $term): bool
    {
        if (strlen($term) < 2) {
            return false;
        }

        $last = substr($term, -1);
        if ($last !== substr($term, -2, 1)) {
            return false;
        }

        return str_contains('bcdfghjkmnpqrtvwxyz', $last);
    }

    /**
     * @return string[]
     */
    private function german_umlaut_plural_keys(string $key): array
    {
        if (!str_contains($key, 'aeu')) {
            return [];
        }

        $singular = str_replace('aeu', 'au', $key);
        return strlen($singular) >= 4 ? [$singular] : [];
    }

    /**
     * @param string[] $terms
     * @return string[]
     */
    private function unique_terms(array $terms): array
    {
        $unique = [];
        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term !== '') {
                $unique[$term] = true;
            }
        }

        return array_keys($unique);
    }

    private function canonical_language_or_null(string|null $language): ?string
    {
        $candidate = strtolower(trim((string) $language));
        if ($candidate === '') {
            return null;
        }

        $candidate = str_replace('_', '-', $candidate);
        $primary = explode('-', $candidate, 2)[0];

        return isset($this->supported_languages[$primary]) ? $primary : null;
    }

    private function infer_language_from_text(string $text): string
    {
        if (preg_match('/[ąćęłńóśźż]/iu', $text) === 1) {
            return 'pl';
        }

        if (preg_match('/[äöüß]/iu', $text) === 1) {
            return 'de';
        }

        return 'en';
    }

    /**
     * @return array{content:string[],alt:string[]}
     */
    private function extract_searchable_field_segments_with_dom(string $html): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $flags = 0;
        if (defined('LIBXML_HTML_NOIMPLIED')) {
            $flags |= LIBXML_HTML_NOIMPLIED;
        }
        if (defined('LIBXML_HTML_NODEFDTD')) {
            $flags |= LIBXML_HTML_NODEFDTD;
        }

        $wrapped = '<!DOCTYPE html><html><body><div id="language-fts-playground-root">' . $html . '</div></body></html>';
        $document->loadHTML('<?xml encoding="UTF-8">' . $wrapped, $flags);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('language-fts-playground-root') ?? $document;
        $content_segments = [];
        $alt_segments = [];
        $current_content = '';
        $this->collect_searchable_field_segments($root, $content_segments, $alt_segments, $current_content);
        $this->flush_segment($content_segments, $current_content);

        return [
            'content' => $content_segments,
            'alt' => $alt_segments,
        ];
    }

    /**
     * @param string[] $content_segments
     * @param string[] $alt_segments
     */
    private function collect_searchable_field_segments(DOMNode $node, array &$content_segments, array &$alt_segments, string &$current_content): void
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            $current_content .= ' ' . (string) $node->nodeValue;
            return;
        }

        if ($node->nodeType === XML_COMMENT_NODE) {
            $this->flush_segment($content_segments, $current_content);
            return;
        }

        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if (isset($this->skipped_elements[$tag])) {
                $this->flush_segment($content_segments, $current_content);
                return;
            }

            if ($tag === 'img' && $node->hasAttribute('alt')) {
                $this->flush_segment($content_segments, $current_content);
                $alt = $this->normalize_plain_text($node->getAttribute('alt'));
                if ($alt !== '') {
                    $alt_segments[] = $alt;
                }
                return;
            }
        }

        foreach ($node->childNodes as $child) {
            $this->collect_searchable_field_segments($child, $content_segments, $alt_segments, $current_content);
        }
    }

    /**
     * @param string[] $segments
     */
    private function flush_segment(array &$segments, string &$current): void
    {
        $segment = $this->normalize_plain_text($current);
        if ($segment !== '') {
            $segments[] = $segment;
        }
        $current = '';
    }

    private function first_html_language(string $html): ?string
    {
        if ($html === '' || !class_exists(DOMDocument::class)) {
            return null;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $this->first_html_language_in_node($document);
    }

    private function first_html_language_in_node(DOMNode $node): ?string
    {
        if ($node instanceof DOMElement) {
            foreach (['lang', 'xml:lang'] as $attribute) {
                if ($node->hasAttribute($attribute)) {
                    $language = $this->canonical_language_or_null($node->getAttribute($attribute));
                    if ($language !== null) {
                        return $language;
                    }
                }
            }
        }

        foreach ($node->childNodes as $child) {
            $language = $this->first_html_language_in_node($child);
            if ($language !== null) {
                return $language;
            }
        }

        return null;
    }

    /**
     * @return array{content:string[],alt:string[]}
     */
    private function extract_searchable_field_segments_with_wp_processor(string $html): array
    {
        try {
            $processor = method_exists('WP_HTML_Processor', 'create_fragment')
                ? WP_HTML_Processor::create_fragment($html)
                : null;
        } catch (Throwable) {
            $processor = null;
        }

        if (!is_object($processor) || !method_exists($processor, 'next_token')) {
            return $this->extract_searchable_field_segments_without_dom($html);
        }

        $content_segments = [];
        $alt_segments = [];
        $current_content = '';
        while ($processor->next_token()) {
            $breadcrumbs = method_exists($processor, 'get_breadcrumbs') ? (array) ($processor->get_breadcrumbs() ?? []) : [];
            $breadcrumbs = array_map(static fn($tag): string => strtolower((string) $tag), $breadcrumbs);
            if ($this->has_skipped_breadcrumb($breadcrumbs)) {
                $this->flush_segment($content_segments, $current_content);
                continue;
            }

            $token_type = method_exists($processor, 'get_token_type') ? $processor->get_token_type() : null;
            if ($token_type === '#text' && method_exists($processor, 'get_modifiable_text')) {
                $current_content .= ' ' . (string) $processor->get_modifiable_text();
                continue;
            }

            $tag = method_exists($processor, 'get_tag') ? strtolower((string) $processor->get_tag()) : '';
            if ($tag === 'img' && method_exists($processor, 'get_attribute')) {
                $this->flush_segment($content_segments, $current_content);
                $alt = $processor->get_attribute('alt');
                if (is_scalar($alt)) {
                    $alt = $this->normalize_plain_text((string) $alt);
                    if ($alt !== '') {
                        $alt_segments[] = $alt;
                    }
                }
            }
        }
        $this->flush_segment($content_segments, $current_content);

        return [
            'content' => $content_segments,
            'alt' => $alt_segments,
        ];
    }

    /**
     * @param string[] $breadcrumbs
     */
    private function has_skipped_breadcrumb(array $breadcrumbs): bool
    {
        foreach ($breadcrumbs as $tag) {
            if (isset($this->skipped_elements[$tag])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{content:string[],alt:string[]}
     */
    private function extract_searchable_field_segments_without_dom(string $html): array
    {
        // Fallback for PHP builds without DOM, including the php -n test path.
        $boundary = "\n__LANGUAGE_FTS_PLAYGROUND_BOUNDARY__\n";
        $text = preg_replace('/<(script|style|template|noscript|svg|math)\b[^>]*>.*?<\/\1>/is', $boundary, $html);
        $text = preg_replace('/<!--.*?-->/s', $boundary, is_string($text) ? $text : $html);
        $text = is_string($text) ? $text : $html;

        $content_segments = [];
        $alt_segments = [];
        $chunks = preg_split('/' . preg_quote($boundary, '/') . '/u', $text);
        foreach (is_array($chunks) ? $chunks : [$text] as $chunk) {
            $chunk = (string) $chunk;
            $alt_matches = [];
            if (preg_match_all('/<img\b[^>]*\salt\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))[^>]*>/iu', $chunk, $alt_matches, PREG_SET_ORDER)) {
                foreach ($alt_matches as $match) {
                    $alt = $match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : ($match[3] ?? ''));
                    $alt = $this->normalize_plain_text($alt);
                    if ($alt !== '') {
                        $alt_segments[] = $alt;
                    }
                }
            }

            $content = $this->normalize_plain_text(strip_tags($chunk));
            if ($content !== '') {
                $content_segments[] = $content;
            }
        }

        return [
            'content' => $content_segments,
            'alt' => $alt_segments,
        ];
    }

    private function post_id(object $post): int
    {
        return max(0, (int) ($post->ID ?? $post->id ?? $post->post_id ?? 0));
    }

    private function post_string(object $post, string $property): string
    {
        return isset($post->{$property}) && is_scalar($post->{$property}) ? (string) $post->{$property} : '';
    }
}
