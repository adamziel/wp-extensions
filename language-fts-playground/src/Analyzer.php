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
        if (trim($html) === '') {
            return '';
        }

        if (class_exists(DOMDocument::class)) {
            return $this->extract_searchable_text_with_dom($html);
        }

        if (class_exists('WP_HTML_Processor')) {
            return $this->extract_searchable_text_with_wp_processor($html);
        }

        return $this->extract_searchable_text_without_dom($html);
    }

    /**
     * @return string[]
     */
    public function analyze_query(string $query, string $language): array
    {
        return array_values(array_unique($this->analyze_text($query, $language)));
    }

    /**
     * @return string[]
     */
    public function analyze_text(string $text, string $language): array
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

        $terms = [];
        foreach ($matches[0] as $token) {
            $term = $this->normalize_term($token, $language);
            if ($term === '' || $this->is_stopword($term, $language)) {
                continue;
            }

            foreach ($this->term_keys($term, $language) as $key) {
                if ($key === '' || strlen($key) > 255) {
                    continue;
                }

                $terms[] = $key;
            }
        }

        return $terms;
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

    private function extract_searchable_text_with_dom(string $html): string
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
        $parts = [];
        $this->collect_visible_text($root, $parts);

        return $this->normalize_plain_text(implode(' ', $parts));
    }

    /**
     * @param string[] $parts
     */
    private function collect_visible_text(DOMNode $node, array &$parts): void
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            $parts[] = (string) $node->nodeValue;
            return;
        }

        if ($node->nodeType === XML_COMMENT_NODE) {
            return;
        }

        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if (isset($this->skipped_elements[$tag])) {
                return;
            }

            if ($tag === 'img' && $node->hasAttribute('alt')) {
                $parts[] = $node->getAttribute('alt');
            }
        }

        foreach ($node->childNodes as $child) {
            $this->collect_visible_text($child, $parts);
        }
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

    private function extract_searchable_text_with_wp_processor(string $html): string
    {
        try {
            $processor = method_exists('WP_HTML_Processor', 'create_fragment')
                ? WP_HTML_Processor::create_fragment($html)
                : null;
        } catch (Throwable) {
            $processor = null;
        }

        if (!is_object($processor) || !method_exists($processor, 'next_token')) {
            return $this->extract_searchable_text_without_dom($html);
        }

        $parts = [];
        while ($processor->next_token()) {
            $breadcrumbs = method_exists($processor, 'get_breadcrumbs') ? (array) ($processor->get_breadcrumbs() ?? []) : [];
            $breadcrumbs = array_map(static fn($tag): string => strtolower((string) $tag), $breadcrumbs);
            if ($this->has_skipped_breadcrumb($breadcrumbs)) {
                continue;
            }

            $token_type = method_exists($processor, 'get_token_type') ? $processor->get_token_type() : null;
            if ($token_type === '#text' && method_exists($processor, 'get_modifiable_text')) {
                $parts[] = (string) $processor->get_modifiable_text();
                continue;
            }

            $tag = method_exists($processor, 'get_tag') ? strtolower((string) $processor->get_tag()) : '';
            if ($tag === 'img' && method_exists($processor, 'get_attribute')) {
                $alt = $processor->get_attribute('alt');
                if (is_scalar($alt)) {
                    $parts[] = (string) $alt;
                }
            }
        }

        return $this->normalize_plain_text(implode(' ', $parts));
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

    private function extract_searchable_text_without_dom(string $html): string
    {
        // Fallback for unusual PHP builds. Normal Playground/PHP test runs use DOM.
        $text = preg_replace('/<(script|style|template|noscript|svg|math)\b[^>]*>.*?<\/\1>/is', ' ', $html);
        $text = preg_replace('/<!--.*?-->/s', ' ', is_string($text) ? $text : $html);
        $text = is_string($text) ? $text : $html;

        $parts = [];
        $alt_matches = [];
        if (preg_match_all('/<img\b[^>]*\salt\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/iu', $text, $alt_matches, PREG_SET_ORDER)) {
            foreach ($alt_matches as $match) {
                $parts[] = $match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : ($match[3] ?? ''));
            }
        }

        array_unshift($parts, strip_tags($text));

        return $this->normalize_plain_text(implode(' ', $parts));
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
