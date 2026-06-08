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
    private const QUERY_LANGUAGE_SIGNAL_WEIGHT = 8.0;
    private const QUERY_LANGUAGE_LEXEME_FORM_WEIGHT = 3.0;
    private const QUERY_LANGUAGE_CANONICAL_KEY_WEIGHT = 2.0;
    private const QUERY_LANGUAGE_SYNONYM_SOURCE_WEIGHT = 2.5;
    private const QUERY_LANGUAGE_STOPWORD_WEIGHT = 0.75;

    /** @var array<string,bool> */
    private array $skipped_elements = [
        'script' => true,
        'style' => true,
        'template' => true,
        'noscript' => true,
        'svg' => true,
        'math' => true,
    ];

    private Language_FTS_Playground_Lexical_Profile_Repository $profiles;

    public function __construct(Language_FTS_Playground_Lexical_Profile_Repository|null $profiles = null)
    {
        $this->profiles = $profiles ?? new Language_FTS_Playground_Lexical_Profile_Repository();
    }

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
        return $this->profiles->language_ids();
    }

    public function language_label(string $language): string
    {
        $language = $this->canonical_language($language);

        return $this->profiles->language_label($language);
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

    /**
     * Rank enabled language partitions that have profile-backed query evidence.
     *
     * Query routing uses the same compact runtime profile maps as analysis:
     * language signal regexes, stopwords, lexeme observed forms, canonical
     * keys, and synonym/synset source keys. Loading those maps is acceptable at
     * query time because profiles are compact local PHP/TSV runtime packs, are
     * parsed lazily, and are cached by the repository for the analyzer
     * instance. Pack metadata and source import formats stay out of this path.
     *
     * @return array<int,array{language:string,score:float,reasons:array{language_signals:string[],lexeme_forms:string[],canonical_keys:string[],synonym_sources:string[],stopwords:string[]}}>
     */
    public function rank_query_languages(string $query, int $limit = 0): array
    {
        $query = $this->normalize_plain_text($query);
        if ($query === '') {
            return [];
        }

        $tokens = $this->query_language_tokens($query);
        $candidates = [];
        foreach ($this->enabled_languages() as $language) {
            $score = 0.0;
            $reasons = [
                'language_signals' => [],
                'lexeme_forms' => [],
                'canonical_keys' => [],
                'synonym_sources' => [],
                'stopwords' => [],
            ];

            foreach ($this->profiles->language_signals($language) as $pattern) {
                if (preg_match($pattern, $query) === 1) {
                    $score += self::QUERY_LANGUAGE_SIGNAL_WEIGHT;
                    $this->add_query_language_reason($reasons, 'language_signals', $pattern);
                }
            }

            if ($tokens !== []) {
                $evidence = $this->profiles->query_language_evidence($language);
                $seen_terms = [];
                foreach ($tokens as $token) {
                    $term = $this->normalize_term($token, $language);
                    if ($term === '' || strlen($term) > 255 || isset($seen_terms[$term])) {
                        continue;
                    }
                    $seen_terms[$term] = true;

                    if (isset($evidence['stopwords'][$term]) && $this->add_query_language_reason($reasons, 'stopwords', $term)) {
                        $score += self::QUERY_LANGUAGE_STOPWORD_WEIGHT;
                    }

                    if (isset($evidence['lexeme_forms'][$term]) && $this->add_query_language_reason($reasons, 'lexeme_forms', $term)) {
                        $score += self::QUERY_LANGUAGE_LEXEME_FORM_WEIGHT;
                    }

                    if (isset($evidence['canonical_keys'][$term]) && $this->add_query_language_reason($reasons, 'canonical_keys', $term)) {
                        $score += self::QUERY_LANGUAGE_CANONICAL_KEY_WEIGHT;
                    }

                    if (isset($evidence['synonym_sources'][$term]) && $this->add_query_language_reason($reasons, 'synonym_sources', $term)) {
                        $score += self::QUERY_LANGUAGE_SYNONYM_SOURCE_WEIGHT;
                    }

                    foreach ($evidence['lexemes'][$term] ?? [] as $canonical) {
                        $canonical = (string) $canonical;
                        if ($canonical === '') {
                            continue;
                        }

                        if ($this->add_query_language_reason($reasons, 'canonical_keys', $canonical)) {
                            $score += self::QUERY_LANGUAGE_CANONICAL_KEY_WEIGHT;
                        }

                        if (isset($evidence['synonym_sources'][$canonical]) && $this->add_query_language_reason($reasons, 'synonym_sources', $canonical)) {
                            $score += self::QUERY_LANGUAGE_SYNONYM_SOURCE_WEIGHT;
                        }
                    }
                }
            }

            if ($score > 0.0) {
                $candidates[] = [
                    'language' => $language,
                    'score' => $score,
                    'reasons' => $reasons,
                ];
            }
        }

        usort(
            $candidates,
            static fn(array $a, array $b): int => ($b['score'] <=> $a['score'])
                ?: strcmp((string) $a['language'], (string) $b['language'])
        );

        return $limit > 0 ? array_slice($candidates, 0, $limit) : $candidates;
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
        if ($query_lookup === []) {
            return [];
        }

        $synonyms = $this->profiles->profile($language)['synonyms'];
        $expanded = [];
        foreach (array_keys($query_lookup) as $source) {
            foreach ($synonyms[$source] ?? [] as $expansion) {
                $target = (string) $expansion['term'];
                if ($target === '' || isset($query_lookup[$target])) {
                    continue;
                }

                $expanded[$source][$target] = [
                    'term' => $target,
                    'weight' => (float) $expansion['weight'],
                    'source' => $source,
                    'direction' => (string) $expansion['direction'],
                    'provenance' => (string) $expansion['provenance'],
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
        $folds = $this->profiles->profile($language)['folds'];

        return $folds === [] ? $term : strtr($term, $folds);
    }

    /**
     * @return string[]
     */
    private function term_keys(string $term, string $language): array
    {
        $keys = [$term];
        foreach ($this->profiles->profile($language)['lexemes'][$term] ?? [] as $key) {
            $keys[] = $key;
        }

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
        return isset($this->profiles->profile($language)['stopwords'][$term]);
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

        return $this->profiles->has_language($primary) ? $primary : null;
    }

    private function infer_language_from_text(string $text): string
    {
        foreach ($this->profiles->language_ids() as $language) {
            foreach ($this->profiles->language_signals($language) as $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    return $language;
                }
            }
        }

        return 'en';
    }

    /**
     * @return string[]
     */
    private function query_language_tokens(string $query): array
    {
        $matches = [];
        $match_count = preg_match_all('/[\p{L}\p{N}]+/u', $query, $matches);
        if ($match_count === false || $match_count === 0) {
            return [];
        }

        $tokens = [];
        foreach ($matches[0] as $token) {
            $token = (string) $token;
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }

    /**
     * @param array{language_signals:string[],lexeme_forms:string[],canonical_keys:string[],synonym_sources:string[],stopwords:string[]} $reasons
     * @param 'language_signals'|'lexeme_forms'|'canonical_keys'|'synonym_sources'|'stopwords' $type
     */
    private function add_query_language_reason(array &$reasons, string $type, string $value): bool
    {
        if (in_array($value, $reasons[$type], true)) {
            return false;
        }

        $reasons[$type][] = $value;

        return true;
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
