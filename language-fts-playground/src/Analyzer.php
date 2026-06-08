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
            if ($term === '') {
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
            foreach ($this->polish_suffix_keys($term) as $key) {
                $keys[] = $key;
            }
        } elseif ($language === 'en') {
            foreach ($this->english_suffix_keys($term) as $key) {
                $keys[] = $key;
            }
        } elseif ($language === 'de') {
            foreach ($this->german_suffix_keys($term) as $key) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Adds demo-sized English suffix keys after lowercasing.
     *
     * This is not a stemmer. It covers long regular forms used by the demo
     * such as search/searching/searched/searches and story/stories while
     * avoiding plain -s trimming and doubled-consonant guesses such as runn/run.
     *
     * @return string[]
     */
    private function english_suffix_keys(string $term): array
    {
        if (strlen($term) < 5 || preg_match('/^[a-z]+$/', $term) !== 1) {
            return [];
        }

        $keys = [];
        if (str_ends_with($term, 'ies')) {
            $key = substr($term, 0, -3) . 'y';
            if (strlen($key) >= 5) {
                $keys[] = $key;
            }

            return array_values(array_unique($keys));
        }

        if (str_ends_with($term, 'ing')) {
            $key = substr($term, 0, -3);
            if (strlen($key) >= 4 && !$this->ends_with_doubled_consonant($key)) {
                $keys[] = $key;
            }
        }

        foreach (['ed', 'es'] as $suffix) {
            if (!str_ends_with($term, $suffix)) {
                continue;
            }

            $key = substr($term, 0, -strlen($suffix));
            if (strlen($key) >= 4) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Adds demo-sized German suffix keys after umlaut/ß folding.
     *
     * This is intentionally conservative. It covers common long adjective,
     * plural, and safe final-n forms used by the demo without broad stemming
     * for short function words.
     *
     * @return string[]
     */
    private function german_suffix_keys(string $term): array
    {
        if (strlen($term) < 6 || preg_match('/^[a-z]+$/', $term) !== 1) {
            return [];
        }

        $keys = [];
        foreach (['en', 'er', 'em', 'es'] as $suffix) {
            if (!str_ends_with($term, $suffix)) {
                continue;
            }

            $key = substr($term, 0, -strlen($suffix));
            if (strlen($key) >= 5) {
                $keys[] = $key;
            }
        }

        if (str_ends_with($term, 'sche')) {
            $key = substr($term, 0, -1);
            if (strlen($key) >= 5) {
                $keys[] = $key;
            }
        }

        if (str_ends_with($term, 'en') && !str_ends_with($term, 'ungen')) {
            $key = substr($term, 0, -1);
            if (strlen($key) >= 5 && str_ends_with($key, 'e')) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Adds demo-sized Polish inflection keys after diacritic folding.
     *
     * This is intentionally not a full stemmer. It only trims common long-word
     * endings so pairs such as polska/polskiej, partycja/partycji, and
     * wyszukiwanie/wyszukiwania share a key while short words remain exact.
     *
     * @return string[]
     */
    private function polish_suffix_keys(string $term): array
    {
        if (strlen($term) < 6 || preg_match('/^[a-z]+$/', $term) !== 1) {
            return [];
        }

        $suffixes = [
            'iego',
            'iej',
            'iem',
            'imi',
            'ami',
            'ach',
            'ych',
            'ich',
            'ego',
            'emu',
            'ym',
            'im',
            'om',
            'ow',
            'ii',
            'ia',
            'ie',
            'iu',
            'a',
            'i',
            'e',
            'y',
            'u',
            'o',
        ];

        $keys = [];
        foreach ($suffixes as $suffix) {
            if (substr($term, -strlen($suffix)) !== $suffix) {
                continue;
            }

            $key = substr($term, 0, -strlen($suffix));
            if (strlen($key) >= 5) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    private function ends_with_doubled_consonant(string $term): bool
    {
        if (strlen($term) < 2) {
            return false;
        }

        $last = substr($term, -1);
        if ($last !== substr($term, -2, 1)) {
            return false;
        }

        return str_contains('bcdfghjklmnpqrstvwxyz', $last);
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
