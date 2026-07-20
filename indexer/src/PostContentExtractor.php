<?php
declare(strict_types=1);

/**
 * Extracts searchable WordPress post fields for the relational indexer.
 *
 * This class deliberately separates extraction from indexing: site owners can
 * filter the field list, choose custom fields through options, and tune field
 * boosts without changing the relational storage format. Indexing reads the
 * persisted post source; it never renders blocks, shortcodes, or post content.
 */
final class WP_FTS_PostContentExtractor
{
    public const CUSTOM_FIELDS_OPTION = 'wp_fts_index_custom_fields';
    public const MAX_SELECTED_CUSTOM_FIELD_KEYS = 32;
    public const MAX_CUSTOM_FIELD_KEY_BYTES = 191;
    public const MAX_INDEX_FIELDS = 32;
    public const MAX_INDEX_FIELD_NAME_BYTES = 191;
    private const MAX_STRUCTURED_VALUE_DEPTH = 16;
    private const MAX_STRUCTURED_VALUE_NODES = 2048;
    private const MAX_STRUCTURED_TEXT_BYTES = 262144;
    private const MAX_STRUCTURED_MAP_KEYS = 32;
    private const MAX_FIELD_BOOSTS = 32;

    /** @var array<string,float> */
    private array $defaultFieldBoosts = [
        'title' => 5.0,
        'content' => 1.0,
        'excerpt' => 2.0,
        'terms' => 2.0,
        'custom_fields' => 1.0,
    ];

    /**
     * Build weighted fields and bounded post-content snippet text.
     *
     * `$post` may be a `WP_Post`, a `$wpdb->posts` row, or a test fixture object
     * with equivalent properties. Snippet text comes only from saved post
     * content; title, excerpt, taxonomy, and custom-field matches must not be
     * presented as the post body.
     *
     * @param object $post WordPress post object or compatible row.
     * @param array<string,mixed> $opts Extraction options:
     *        `custom_field_keys` and `field_boosts`.
     * @return array{
     *   fields:array<int,array{name:string,text:string,html?:string,boost:float}>,
     *   snippet_text:string
     * }
     */
    public function extract(object $post, array $opts = []): array
    {
        $this->assert_option_keys($opts);
        $postProperties = get_object_vars($post);
        foreach (['post_title', 'post_content', 'post_excerpt'] as $property) {
            if (!array_key_exists($property, $postProperties) || !is_string($postProperties[$property])) {
                throw new InvalidArgumentException(
                    "Post objects must provide {$property} as a native string."
                );
            }
        }
        foreach (['terms', 'custom_fields'] as $property) {
            if (!array_key_exists($property, $postProperties) || !is_array($postProperties[$property])) {
                throw new InvalidArgumentException(
                    "Post objects must provide {$property} as an authoritative array."
                );
            }
        }
        $title = $postProperties['post_title'];
        $content = $postProperties['post_content'];
        $excerpt = $postProperties['post_excerpt'];
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

        $terms = $this->extract_terms($post, $opts);
        $termText = $this->structured_text($terms);
        if ($termText !== '') {
            $fields[] = $this->field('terms', $termText, $fieldBoosts['terms'] ?? 2.0);
        }

        $customFields = $this->extract_custom_fields($post, $opts);
        $customFieldText = $this->structured_text($customFields);
        if ($customFieldText !== '') {
            $fields[] = $this->field('custom_fields', $customFieldText, $fieldBoosts['custom_fields'] ?? 1.0);
        }

        $fields = $this->normalize_fields($this->apply_filter('wp_fts_post_index_fields', $fields, $post, $opts), $fieldBoosts);
        return [
            'fields' => $fields,
            'snippet_text' => $this->limit_text(
                $contentText,
                WP_FTS_Set_Oriented_Search_Storage::MAX_SNIPPET_SOURCE_BYTES
            ),
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
            'boost' => $boost,
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
        if (array_key_exists('field_boosts', $opts)) {
            $boosts = array_replace(
                $boosts,
                $this->normalize_field_boost_map($opts['field_boosts'], 'FTS field boosts')
            );
        }

        $boosts = $this->normalize_field_boost_map($boosts, 'FTS field boosts');
        $boosts = $this->apply_filter('wp_fts_post_field_boosts', $boosts, $post, $opts);
        return $this->normalize_field_boost_map($boosts, 'wp_fts_post_field_boosts');
    }

    /** @return array<string,float> */
    private function normalize_field_boost_map(mixed $boosts, string $source): array
    {
        if (!is_array($boosts)) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'field_boost_shape',
                "{$source} must be an array."
            );
        }
        if (count($boosts) > self::MAX_FIELD_BOOSTS) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'field_boosts',
                "{$source} contains more than 32 entries."
            );
        }

        $normalized = [];
        foreach ($boosts as $field => $boost) {
            if (!is_string($field) || trim($field) === '' || trim($field) !== $field) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'field_boost_shape',
                    "{$source} keys must be unpadded non-empty strings."
                );
            }
            if (strlen($field) > self::MAX_INDEX_FIELD_NAME_BYTES) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'field_boost_name_bytes',
                    "{$source} contains a key longer than 191 bytes."
                );
            }
            if ((!is_int($boost) && !is_float($boost))
                || !is_finite((float) $boost)
                || floor((float) $boost) !== (float) $boost
                || $boost < 1
                || $boost > 100
            ) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'field_boost_shape',
                    "{$source} values must be whole numbers from 1 through 100."
                );
            }
            $normalized[$field] = (float) $boost;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * Validate filtered field rows and drop only rows with empty content.
     *
     * @param mixed $fields
     * @param array<string,float> $fieldBoosts
     * @return array<int,array{name:string,text:string,html?:string,boost:float}>
     */
    private function normalize_fields(mixed $fields, array $fieldBoosts): array
    {
        if (!is_array($fields) || !array_is_list($fields)) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'index_field_shape',
                'wp_fts_post_index_fields must return a list of field rows.'
            );
        }
        if (count($fields) > self::MAX_INDEX_FIELDS) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'index_fields',
                'An FTS document contains more than 32 index fields.'
            );
        }

        $normalized = [];
        $sourceBytes = 0;
        foreach ($fields as $field) {
            if (!is_array($field)) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'index_field_shape',
                    'Each wp_fts_post_index_fields row must be an array.'
                );
            }

            foreach (array_keys($field) as $key) {
                if (!is_string($key) || !in_array($key, ['name', 'text', 'html', 'boost'], true)) {
                    throw new WP_FTS_Analysis_Limit_Exceeded(
                        'index_field_shape',
                        'wp_fts_post_index_fields rows support only name, text, html, and boost.'
                    );
                }
            }
            if (!array_key_exists('name', $field) || !array_key_exists('text', $field)) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'index_field_shape',
                    'Each wp_fts_post_index_fields row requires name and text.'
                );
            }

            $rawName = $field['name'];
            $rawText = $field['text'];
            $rawHtml = array_key_exists('html', $field) ? $field['html'] : null;
            if (
                !is_string($rawName)
                || !is_string($rawText)
                || (array_key_exists('html', $field) && !is_string($rawHtml))
            ) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'index_field_shape',
                    'wp_fts_post_index_fields names, text, and optional HTML must be strings.'
                );
            }

            if (strlen($rawName) > self::MAX_INDEX_FIELD_NAME_BYTES) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'index_field_name_bytes',
                    'An FTS index field name exceeds the 191-byte limit.'
                );
            }
            $sourceBytes += strlen($rawText) + ($rawHtml === null ? 0 : strlen($rawHtml));
            WP_FTS_Analysis_Limits::assert_document_source_bytes($sourceBytes);

            $name = trim($rawName);
            if ($name === '' || $name !== $rawName) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'index_field_shape',
                    'wp_fts_post_index_fields names must be unpadded and non-empty.'
                );
            }
            $boost = array_key_exists('boost', $field) ? $field['boost'] : ($fieldBoosts[$name] ?? 1.0);
            if (
                (!is_int($boost) && !is_float($boost))
                || !is_finite((float) $boost)
                || floor((float) $boost) !== (float) $boost
                || $boost < 1
                || $boost > 100
            ) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'index_field_shape',
                    'wp_fts_post_index_fields boosts must be whole numbers from 1 through 100.'
                );
            }

            $text = $this->plain_text($rawText);
            $html = $rawHtml;
            if ($text === '' && trim((string) $html) === '') {
                continue;
            }

            $row = [
                'name' => $name,
                'text' => $text,
                'boost' => (float) $boost,
            ];
            if ($html !== null && trim($html) !== '') {
                $row['html'] = $html;
            }
            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * Extract taxonomy term labels from the caller's authoritative snapshot.
     *
     * @return array<string,string[]>
     */
    private function extract_terms(object $post, array $opts): array
    {
        $terms = $this->apply_filter('wp_fts_post_terms', $post->terms, $post, $opts);

        return $this->normalize_filtered_string_lists($terms, 'wp_fts_post_terms');
    }

    /**
     * Extract explicitly selected custom fields.
     *
     * @return array<string,string[]>
     */
    private function extract_custom_fields(object $post, array $opts): array
    {
        $keys = $this->selected_custom_field_keys($post, $opts);
        if ($keys === []) {
            return [];
        }

        $fields = [];
        foreach ($keys as $key) {
            // The attached map is authoritative, including missing keys.
            $fields[$key] = array_key_exists($key, $post->custom_fields)
                ? $post->custom_fields[$key]
                : [];
        }

        return $this->normalize_filtered_string_lists(
            $this->apply_filter('wp_fts_post_custom_field_values', $fields, $post, $opts),
            'wp_fts_post_custom_field_values'
        );
    }

    /**
     * Resolve selected custom field keys from explicit or attached input.
     *
     * @return string[]
     */
    public function selected_custom_field_keys(object $post, array $opts = []): array
    {
        $this->assert_option_keys($opts);
        $keys = array_key_exists('custom_field_keys', $opts)
            ? $opts['custom_field_keys']
            : (is_array($post->custom_fields ?? null) ? array_keys($post->custom_fields) : []);
        $keys = $this->apply_filter('wp_fts_post_custom_fields', $keys, $post, $opts);

        return $this->normalize_selected_custom_field_keys($keys);
    }

    /**
     * Canonicalize the stored custom-field selection without running filters.
     *
     * @return string[]
     */
    public function normalize_selected_custom_field_keys(mixed $keys): array
    {
        if (!is_array($keys) || !array_is_list($keys)) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'custom_field_key_shape',
                'The FTS custom-field key selection must be a list of strings.'
            );
        }
        if (count($keys) > self::MAX_SELECTED_CUSTOM_FIELD_KEYS) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'custom_field_keys',
                'An FTS document selects more than 32 custom-field keys.'
            );
        }

        $normalized = [];
        foreach ($keys as $key) {
            if (!is_string($key) || trim($key) === '' || trim($key) !== $key) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'custom_field_key_shape',
                    'Every FTS custom-field key must be an unpadded non-empty string.'
                );
            }
            if (strlen($key) > self::MAX_CUSTOM_FIELD_KEY_BYTES) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'custom_field_key_bytes',
                    'An FTS custom-field key exceeds the 191-byte limit.'
                );
            }
            $normalized[$key] = true;
        }

        $result = array_keys($normalized);
        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * Flatten one safely decoded database meta value without object access.
     *
     * The worker calls this immediately after `unserialize()` with classes
     * disabled. Arrays are bounded by the same depth/node/text limits as normal
     * extraction; incomplete or caller-supplied objects contribute no text.
     *
     * @return string[]
     */
    public function flatten_preloaded_meta_value(mixed $value): array
    {
        return $this->list_text_values($value, false);
    }

    /**
     * Apply a WordPress filter when available.
     *
     * @param mixed $value
     * @return mixed
     */
    private function apply_filter(string $hook, mixed $value, object $post, array $opts): mixed
    {
        if (function_exists('apply_filters')) {
            return apply_filters($hook, $value, $post, $opts);
        }

        return $value;
    }

    /** @param array<string,mixed> $opts */
    private function assert_option_keys(array $opts): void
    {
        foreach (array_keys($opts) as $key) {
            if (!is_string($key) || !in_array($key, ['custom_field_keys', 'field_boosts'], true)) {
                throw new InvalidArgumentException('FTS extraction options support only custom_field_keys and field_boosts.');
            }
        }
    }

    /**
     * Flatten nested scalar/object values into plain text strings.
     *
     * @param mixed $values
     * @return string[]
     */
    private function list_text_values(mixed $values, bool $allowObjects = true): array
    {
        $result = [];
        $nodes = 0;
        $sourceBytes = 0;
        $textBytes = 0;
        $this->collect_text_values($values, 0, $nodes, $sourceBytes, $textBytes, $result, $allowObjects);

        return array_values(array_unique($result));
    }

    /** Require an exact map of unpadded keys to lists of searchable strings. */
    private function normalize_filtered_string_lists(mixed $lists, string $hook): array
    {
        if (!is_array($lists)) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'structured_map_shape',
                "{$hook} must return a map of string lists."
            );
        }
        if (count($lists) > self::MAX_STRUCTURED_MAP_KEYS) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'structured_map_keys',
                'An FTS structured map contains more than 32 keys.'
            );
        }

        $normalized = [];
        $nodes = 0;
        $sourceBytes = 0;
        $textBytes = 0;
        foreach ($lists as $key => $values) {
            if (!is_string($key) || $key === '' || trim($key) !== $key) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'structured_map_shape',
                    "{$hook} keys must be unpadded non-empty strings."
                );
            }
            if (strlen($key) > self::MAX_CUSTOM_FIELD_KEY_BYTES) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'structured_key_bytes',
                    'An FTS structured-map key exceeds the 191-byte limit.'
                );
            }
            if (++$nodes > self::MAX_STRUCTURED_VALUE_NODES) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'structured_value_nodes',
                    'An FTS structured field exceeds the 2,048-node limit.'
                );
            }
            $sourceBytes += strlen($key);
            $textBytes += strlen($key);
            if ($sourceBytes > self::MAX_STRUCTURED_TEXT_BYTES || $textBytes > self::MAX_STRUCTURED_TEXT_BYTES) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'structured_text_bytes',
                    'An FTS structured field exceeds the 256 KiB text limit.'
                );
            }
            if (!is_array($values) || !array_is_list($values)) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'structured_map_shape',
                    "{$hook} values must be lists of strings."
                );
            }
            $items = [];
            foreach ($values as $value) {
                if (!is_string($value) || trim($value) === '') {
                    throw new WP_FTS_Analysis_Limit_Exceeded(
                        'structured_map_shape',
                        "{$hook} values must be non-blank strings."
                    );
                }
                if (++$nodes > self::MAX_STRUCTURED_VALUE_NODES) {
                    throw new WP_FTS_Analysis_Limit_Exceeded(
                        'structured_value_nodes',
                        'An FTS structured field exceeds the 2,048-node limit.'
                    );
                }
                $sourceBytes += strlen($value);
                if ($sourceBytes > self::MAX_STRUCTURED_TEXT_BYTES) {
                    throw new WP_FTS_Analysis_Limit_Exceeded(
                        'structured_source_bytes',
                        'An FTS structured field exceeds the 256 KiB source-text limit.'
                    );
                }
                $text = $this->plain_text($value);
                if ($text === '') {
                    throw new WP_FTS_Analysis_Limit_Exceeded(
                        'structured_map_shape',
                        "{$hook} values must contain searchable text."
                    );
                }
                $textBytes += strlen($text);
                if ($textBytes > self::MAX_STRUCTURED_TEXT_BYTES) {
                    throw new WP_FTS_Analysis_Limit_Exceeded(
                        'structured_text_bytes',
                        'An FTS structured field exceeds the 256 KiB extracted-text limit.'
                    );
                }
                $items[] = $text;
            }
            $items = array_values(array_unique($items));
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
     * Traverse structured field values without recursive amplification or magic access.
     *
     * @param string[] $result
     */
    private function collect_text_values(
        mixed $value,
        int $depth,
        int &$nodes,
        int &$sourceBytes,
        int &$textBytes,
        array &$result,
        bool $allowObjects = true
    ): void
    {
        if (++$nodes > self::MAX_STRUCTURED_VALUE_NODES) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'structured_value_nodes',
                'An FTS structured field exceeds the 2,048-node limit.'
            );
        }
        if (is_array($value)) {
            if ($depth >= self::MAX_STRUCTURED_VALUE_DEPTH) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'structured_value_depth',
                    'An FTS structured field exceeds the 16-level nesting limit.'
                );
            }
            foreach ($value as $child) {
                $this->collect_text_values($child, $depth + 1, $nodes, $sourceBytes, $textBytes, $result, $allowObjects);
            }

            return;
        }
        if (is_object($value)) {
            if (!$allowObjects) {
                return;
            }
            // `get_object_vars()` reads declared public data without invoking
            // `__get()`. Never execute behavior supplied by serialized meta.
            $properties = get_object_vars($value);
            $value = $properties['name'] ?? $properties['slug'] ?? $properties['value'] ?? '';
        }
        if (!is_scalar($value)) {
            return;
        }

        $value = (string) $value;
        $sourceBytes += strlen($value);
        if ($sourceBytes > self::MAX_STRUCTURED_TEXT_BYTES) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'structured_source_bytes',
                'An FTS structured field exceeds the 256 KiB source-text limit.'
            );
        }
        $text = $this->plain_text($value);
        if ($text === '') {
            return;
        }
        $textBytes += strlen($text);
        if ($textBytes > self::MAX_STRUCTURED_TEXT_BYTES) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'structured_text_bytes',
                'An FTS structured field exceeds the 256 KiB extracted-text limit.'
            );
        }
        $result[] = $text;
    }

    /**
     * Join nested text values into one searchable field string.
     *
     * @param mixed $value
     */
    private function structured_text(mixed $value): string
    {
        return $this->plain_text(implode(' ', $this->list_text_values($value)));
    }

    /**
     * Strip markup, decode entities, and collapse whitespace.
     */
    private function plain_text(string $text): string
    {
        if ($text === '') {
            return '';
        }
        WP_FTS_Analysis_Limits::assert_source_bytes($text);
        // Extraction precedes Indexer preparation. The shared syntax pass must
        // therefore happen here, before visible_text() builds parser state for
        // post content, filtered fields, or structured field values.
        WP_FTS_Html_Text_Stream::assert_analysis_markup_limits($text);

        return WP_FTS_Html_Text_Stream::visible_text($text);
    }

    /**
     * Bound stored text to keep snippets useful without storing whole sites.
     */
    private function limit_text(string $text, int $limit): string
    {
        return rtrim(WP_FTS_Utf8::truncate_bytes($text, $limit));
    }
}
