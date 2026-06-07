<?php
declare(strict_types=1);

/**
 * Extracts searchable WordPress post fields into weighted index fields.
 *
 * This class deliberately separates extraction from indexing: site owners can
 * filter the field list, choose custom fields through options, and tune field
 * boosts without changing the postings storage format. Rendered block content
 * is included when WordPress can render it; shortcode rendering is opt-in
 * because shortcode callbacks can be arbitrary application code.
 */
final class WP_FTS_PostContentExtractor
{
    /** @var array<string,float> */
    private array $defaultFieldBoosts = [
        'title' => 5.0,
        'content' => 1.0,
        'excerpt' => 2.0,
        'terms' => 1.5,
        'custom_fields' => 1.0,
        'rendered' => 1.0,
    ];

    /**
     * Build weighted fields and product metadata for a WordPress post-like row.
     *
     * `$post` may be a `WP_Post`, a `$wpdb->posts` row, or a test fixture object
     * with equivalent properties. The returned `search_text` metadata is plain
     * text and bounded by `metadata_text_limit` to avoid unbounded storage growth.
     *
     * @param object $post WordPress post object or compatible row.
     * @param array<string,mixed> $opts Extraction options:
     *        `custom_fields`/`custom_field_keys`, `field_boosts`,
     *        `render_blocks`, `render_shortcodes`, `render_content_callback`,
     *        `metadata_text_limit`, and `filters`.
     * @return array{
     *   fields:array<int,array{name:string,text:string,html?:string,boost:float}>,
     *   metadata:array<string,mixed>,
     *   field_boosts:array<string,float>
     * }
     */
    public function extract(object $post, array $opts = []): array
    {
        $postId = $this->post_id($post);
        $postType = $this->post_prop($post, 'post_type');
        $title = $this->post_prop($post, 'post_title');
        $content = $this->post_prop($post, 'post_content');
        $excerpt = $this->post_prop($post, 'post_excerpt');
        $fieldBoosts = $this->field_boosts($post, $opts);

        $fields = [];
        if ($title !== '') {
            $fields[] = $this->field('title', $title, $fieldBoosts['title'] ?? 5.0);
        }
        $contentText = '';
        if ($content !== '') {
            $contentText = $this->plain_text($content);
            $fields[] = $this->field('content', $contentText, $fieldBoosts['content'] ?? 1.0, $content);
        }
        if ($excerpt !== '') {
            $fields[] = $this->field('excerpt', $excerpt, $fieldBoosts['excerpt'] ?? 2.0);
        }

        $rendered = $this->render_content($content, $post, $opts);
        if ($rendered !== '' && $rendered !== $content) {
            $renderedText = $this->plain_text($rendered);
            $renderedDeltaText = $this->rendered_delta_text($contentText, $renderedText);
            if ($renderedDeltaText !== '') {
                $fields[] = $this->field('rendered', $renderedDeltaText, $fieldBoosts['rendered'] ?? 1.0);
            }
        }

        $terms = $this->extract_terms($postId, $postType, $post, $opts);
        $termText = $this->structured_text($terms);
        if ($termText !== '') {
            $fields[] = $this->field('terms', $termText, $fieldBoosts['terms'] ?? 1.5);
        }

        $customFields = $this->extract_custom_fields($postId, $post, $opts);
        $customFieldText = $this->structured_text($customFields);
        if ($customFieldText !== '') {
            $fields[] = $this->field('custom_fields', $customFieldText, $fieldBoosts['custom_fields'] ?? 1.0);
        }

        $fields = $this->normalize_fields($this->apply_filter('wp_fts_post_index_fields', $fields, $post, $opts), $fieldBoosts);
        $searchText = $this->limit_text($this->structured_text(array_column($fields, 'text')), (int) ($opts['metadata_text_limit'] ?? 20000));

        $metadata = [
            'post_id' => $postId,
            'post_type' => $postType,
            'post_status' => $this->post_prop($post, 'post_status'),
            'post_date_gmt' => $this->post_prop($post, 'post_date_gmt') ?: $this->post_prop($post, 'post_date'),
            'title' => $this->plain_text($title),
            'excerpt' => $this->plain_text($excerpt),
            'search_text' => $searchText,
            'terms' => $terms,
            'custom_fields' => $customFields,
            'field_boosts' => $fieldBoosts,
        ];
        $metadata = $this->normalize_metadata($this->apply_filter('wp_fts_post_index_metadata', $metadata, $post, $opts));

        return [
            'fields' => $fields,
            'metadata' => $metadata,
            'field_boosts' => $fieldBoosts,
        ];
    }

    /**
     * Normalize a raw field row.
     *
     * @return array{name:string,text:string,html?:string,boost:float}
     */
    private function field(string $name, string $text, float $boost, ?string $html = null): array
    {
        $field = [
            'name' => $name,
            'text' => $this->plain_text($text),
            'boost' => $this->positive_boost($boost),
        ];
        if ($html !== null && trim($html) !== '') {
            $field['html'] = $html;
        }

        return $field;
    }

    /**
     * Resolve field boosts from defaults, options, and WordPress filters.
     *
     * @return array<string,float>
     */
    private function field_boosts(object $post, array $opts): array
    {
        $boosts = $this->defaultFieldBoosts;
        foreach (($opts['field_boosts'] ?? []) as $field => $boost) {
            if (is_scalar($field) && is_numeric($boost)) {
                $boosts[(string) $field] = $this->positive_boost((float) $boost);
            }
        }

        $boosts = $this->apply_filter('wp_fts_post_field_boosts', $boosts, $post, $opts);
        $normalized = [];
        foreach (is_array($boosts) ? $boosts : [] as $field => $boost) {
            if (is_scalar($field) && is_numeric($boost)) {
                $normalized[(string) $field] = $this->positive_boost((float) $boost);
            }
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * Clamp boosts to a useful positive range.
     */
    private function positive_boost(float $boost): float
    {
        if ($boost <= 0.0) {
            return 1.0;
        }

        return min(100.0, $boost);
    }

    /**
     * Normalize filtered field rows and drop empty entries.
     *
     * @param mixed $fields
     * @param array<string,float> $fieldBoosts
     * @return array<int,array{name:string,text:string,html?:string,boost:float}>
     */
    private function normalize_fields(mixed $fields, array $fieldBoosts): array
    {
        $normalized = [];
        foreach (is_array($fields) ? $fields : [] as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = trim((string) ($field['name'] ?? 'content'));
            $text = $this->plain_text((string) ($field['text'] ?? ($field['html'] ?? '')));
            $html = isset($field['html']) ? (string) $field['html'] : null;
            if ($name === '' || ($text === '' && trim((string) $html) === '')) {
                continue;
            }

            $row = [
                'name' => $name,
                'text' => $text,
                'boost' => $this->positive_boost((float) ($field['boost'] ?? ($fieldBoosts[$name] ?? 1.0))),
            ];
            if ($html !== null && trim($html) !== '') {
                $row['html'] = $html;
            }
            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * Extract taxonomy term labels through WordPress APIs or fixture properties.
     *
     * @return array<string,string[]>
     */
    private function extract_terms(int $postId, string $postType, object $post, array $opts): array
    {
        $terms = [];
        if (isset($post->terms) && is_array($post->terms)) {
            foreach ($post->terms as $taxonomy => $values) {
                $terms[(string) $taxonomy] = $this->list_text_values($values);
            }
        }

        if ($postId > 0 && function_exists('get_object_taxonomies') && function_exists('wp_get_object_terms')) {
            $taxonomies = get_object_taxonomies($postType !== '' ? $postType : 'post', 'names');
            if (is_array($taxonomies) && $taxonomies !== []) {
                $objects = wp_get_object_terms($postId, $taxonomies, ['fields' => 'all']);
                if (is_array($objects)) {
                    foreach ($objects as $term) {
                        $taxonomy = is_object($term) ? (string) ($term->taxonomy ?? 'term') : 'term';
                        $label = is_object($term) ? (string) ($term->name ?? ($term->slug ?? '')) : (string) $term;
                        if (trim($label) !== '') {
                            $terms[$taxonomy][] = $this->plain_text($label);
                        }
                    }
                }
            }
        }

        $terms = $this->apply_filter('wp_fts_post_terms', $terms, $post, $opts);

        return $this->normalize_string_lists($terms);
    }

    /**
     * Extract explicitly selected custom fields.
     *
     * @return array<string,string[]>
     */
    private function extract_custom_fields(int $postId, object $post, array $opts): array
    {
        $keys = $this->selected_custom_field_keys($post, $opts);
        if ($keys === []) {
            return [];
        }

        $fields = [];
        foreach ($keys as $key) {
            $values = [];
            if (isset($post->custom_fields) && is_array($post->custom_fields) && array_key_exists($key, $post->custom_fields)) {
                $values = $this->list_text_values($post->custom_fields[$key]);
            } elseif ($postId > 0 && function_exists('get_post_meta')) {
                $values = $this->list_text_values(get_post_meta($postId, $key, false));
            }

            if ($values !== []) {
                $fields[$key] = $values;
            }
        }

        return $this->normalize_string_lists($this->apply_filter('wp_fts_post_custom_field_values', $fields, $post, $opts));
    }

    /**
     * Resolve selected custom field keys from options, WordPress options, filters.
     *
     * @return string[]
     */
    private function selected_custom_field_keys(object $post, array $opts): array
    {
        $keys = $opts['custom_fields'] ?? $opts['custom_field_keys'] ?? [];
        if ($keys === [] && function_exists('get_option')) {
            $keys = get_option('wp_fts_index_custom_fields', []);
        }
        $keys = $this->apply_filter('wp_fts_post_custom_fields', $keys, $post, $opts);

        $normalized = [];
        foreach ($this->list_text_values($keys) as $key) {
            foreach (explode(',', $key) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $normalized[$part] = true;
                }
            }
        }

        $result = array_keys($normalized);
        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * Render block content by default and shortcodes only when explicitly asked.
     */
    private function render_content(string $content, object $post, array $opts): string
    {
        if ($content === '') {
            return '';
        }

        if (isset($opts['render_content_callback']) && is_callable($opts['render_content_callback'])) {
            $rendered = ($opts['render_content_callback'])($content, $post, $opts);
            return is_scalar($rendered) ? (string) $rendered : '';
        }

        $rendered = $content;
        if (($opts['render_blocks'] ?? true) && function_exists('do_blocks')) {
            $rendered = (string) do_blocks($rendered);
        }
        if (($opts['render_shortcodes'] ?? false)) {
            if (function_exists('apply_shortcodes')) {
                $rendered = (string) apply_shortcodes($rendered);
            } elseif (function_exists('do_shortcode')) {
                $rendered = (string) do_shortcode($rendered);
            }
        }

        return $rendered;
    }

    /**
     * Apply an option-local callback and then a WordPress filter when available.
     *
     * @param mixed $value
     * @return mixed
     */
    private function apply_filter(string $hook, mixed $value, object $post, array $opts): mixed
    {
        $filters = $opts['filters'] ?? [];
        if (is_array($filters) && isset($filters[$hook]) && is_callable($filters[$hook])) {
            $value = $filters[$hook]($value, $post, $opts);
        }

        if (function_exists('apply_filters')) {
            return apply_filters($hook, $value, $post, $opts);
        }

        return $value;
    }

    /**
     * Normalize storage metadata into scalar fields plus structured extras.
     *
     * @param mixed $metadata
     * @return array<string,mixed>
     */
    private function normalize_metadata(mixed $metadata): array
    {
        if (!is_array($metadata)) {
            return [];
        }

        $metadata['post_id'] = max(0, (int) ($metadata['post_id'] ?? 0));
        foreach (['post_type', 'post_status', 'post_date_gmt', 'title', 'excerpt', 'search_text'] as $key) {
            $metadata[$key] = $this->plain_text((string) ($metadata[$key] ?? ''));
        }
        $metadata['terms'] = $this->normalize_string_lists($metadata['terms'] ?? []);
        $metadata['custom_fields'] = $this->normalize_string_lists($metadata['custom_fields'] ?? []);

        $boosts = [];
        foreach (($metadata['field_boosts'] ?? []) as $field => $boost) {
            if (is_scalar($field) && is_numeric($boost)) {
                $boosts[(string) $field] = $this->positive_boost((float) $boost);
            }
        }
        ksort($boosts, SORT_STRING);
        $metadata['field_boosts'] = $boosts;

        return $metadata;
    }

    /**
     * Flatten nested scalar/object values into plain text strings.
     *
     * @param mixed $values
     * @return string[]
     */
    private function list_text_values(mixed $values): array
    {
        $result = [];
        foreach (is_array($values) ? $values : [$values] as $value) {
            if (is_object($value)) {
                $value = $value->name ?? $value->slug ?? $value->value ?? '';
            }
            if (is_array($value)) {
                array_push($result, ...$this->list_text_values($value));
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $text = $this->plain_text((string) $value);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Normalize taxonomy/custom-field maps to sorted non-empty string lists.
     *
     * @param mixed $lists
     * @return array<string,string[]>
     */
    private function normalize_string_lists(mixed $lists): array
    {
        $normalized = [];
        foreach (is_array($lists) ? $lists : [] as $key => $values) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $items = $this->list_text_values($values);
            if ($items === []) {
                continue;
            }
            sort($items, SORT_STRING);
            $normalized[$key] = $items;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * Join nested text values into one searchable metadata string.
     *
     * @param mixed $value
     */
    private function structured_text(mixed $value): string
    {
        return $this->plain_text(implode(' ', $this->list_text_values($value)));
    }

    /**
     * Keep rendered-only visible text without re-indexing raw static block text.
     */
    private function rendered_delta_text(string $rawText, string $renderedText): string
    {
        $rawText = $this->plain_text($rawText);
        $renderedText = $this->plain_text($renderedText);
        if ($renderedText === '' || $renderedText === $rawText) {
            return '';
        }
        if ($rawText === '') {
            return $renderedText;
        }
        if (strpos($rawText, $renderedText) !== false) {
            return '';
        }

        $position = strpos($renderedText, $rawText);
        if ($position !== false) {
            return $this->plain_text(
                substr($renderedText, 0, $position) . ' ' . substr($renderedText, $position + strlen($rawText))
            );
        }

        $tokenDelta = $this->remove_token_subsequence_once($renderedText, $rawText);
        if ($tokenDelta === null) {
            $tokenDelta = $this->remove_token_overlap_once($renderedText, $rawText);
        }

        return $tokenDelta ?? $renderedText;
    }

    /**
     * Remove one ordered copy of raw visible tokens from rendered visible tokens.
     */
    private function remove_token_subsequence_once(string $renderedText, string $rawText): ?string
    {
        $renderedTokens = preg_split('/\s+/u', $renderedText, -1, PREG_SPLIT_NO_EMPTY);
        $rawTokens = preg_split('/\s+/u', $rawText, -1, PREG_SPLIT_NO_EMPTY);
        if ($renderedTokens === false || $rawTokens === false || $renderedTokens === [] || $rawTokens === []) {
            return null;
        }

        $matchedIndexes = [];
        $rawIndex = 0;
        foreach ($renderedTokens as $renderedIndex => $token) {
            if ($this->comparison_token($token) !== $this->comparison_token($rawTokens[$rawIndex])) {
                continue;
            }

            $matchedIndexes[$renderedIndex] = true;
            $rawIndex++;
            if ($rawIndex === count($rawTokens)) {
                break;
            }
        }

        if ($rawIndex !== count($rawTokens)) {
            return null;
        }

        $deltaTokens = [];
        foreach ($renderedTokens as $renderedIndex => $token) {
            if (!isset($matchedIndexes[$renderedIndex])) {
                $deltaTokens[] = $token;
            }
        }

        return $this->plain_text(implode(' ', $deltaTokens));
    }

    /**
     * Remove raw-visible token overlaps when rendered text is not a clean superset.
     */
    private function remove_token_overlap_once(string $renderedText, string $rawText): ?string
    {
        $renderedTokens = preg_split('/\s+/u', $renderedText, -1, PREG_SPLIT_NO_EMPTY);
        $rawTokens = preg_split('/\s+/u', $rawText, -1, PREG_SPLIT_NO_EMPTY);
        if ($renderedTokens === false || $rawTokens === false || $renderedTokens === [] || $rawTokens === []) {
            return null;
        }

        $rawCounts = [];
        foreach ($rawTokens as $token) {
            $key = $this->comparison_token($token);
            $rawCounts[$key] = ($rawCounts[$key] ?? 0) + 1;
        }

        $deltaTokens = [];
        $removedAny = false;
        foreach ($renderedTokens as $token) {
            $key = $this->comparison_token($token);
            if (($rawCounts[$key] ?? 0) > 0) {
                $rawCounts[$key]--;
                $removedAny = true;
                continue;
            }

            $deltaTokens[] = $token;
        }

        return $removedAny ? $this->plain_text(implode(' ', $deltaTokens)) : null;
    }

    /**
     * Normalize token text for rendered/raw visible-text comparisons.
     */
    private function comparison_token(string $token): string
    {
        return strtolower(html_entity_decode($token, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Strip markup, decode entities, and collapse whitespace.
     */
    private function plain_text(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = WP_FTS_Utf8::repair($text);
        $text = preg_replace(
            '/<\s*\/?\s*(?:address|article|aside|blockquote|br|caption|dd|div|dl|dt|figcaption|figure|footer|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|table|tbody|td|tfoot|th|thead|tr|ul)\b[^>]*>/i',
            ' ',
            $text
        ) ?? $text;
        if (function_exists('wp_strip_all_tags')) {
            $text = (string) wp_strip_all_tags($text, true);
        } else {
            $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $text) ?? $text;
            $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $text) ?? $text;
            $text = strip_tags($text);
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $text = WP_FTS_Utf8::repair($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Bound stored text to keep snippets useful without storing whole sites.
     */
    private function limit_text(string $text, int $limit): string
    {
        return rtrim(WP_FTS_Utf8::truncate_bytes($text, $limit));
    }

    /**
     * Read a scalar post property.
     */
    private function post_prop(object $post, string $prop): string
    {
        return isset($post->{$prop}) && is_scalar($post->{$prop}) ? (string) $post->{$prop} : '';
    }

    /**
     * Read a non-negative post id.
     */
    private function post_id(object $post): int
    {
        return max(0, (int) ($post->ID ?? $post->id ?? 0));
    }
}
