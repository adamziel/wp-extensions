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
    private const MAX_SNIPPET_TEXT_BYTES = 20000;

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
     *        `custom_field_keys`, `field_boosts`, and `filters`.
     * @return array{
     *   fields:array<int,array{name:string,text:string,html?:string,boost:float}>,
     *   snippet_text:string
     * }
     */
    public function extract(object $post, array $opts = []): array
    {
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
            'snippet_text' => $this->limit_text($contentText, self::MAX_SNIPPET_TEXT_BYTES),
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
        $optionBoosts = $opts['field_boosts'] ?? [];
        if (!is_array($optionBoosts)) {
            $optionBoosts = [];
        }
        if (count($optionBoosts) > self::MAX_FIELD_BOOSTS) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'field_boosts',
                'An FTS document contains more than 32 field boosts.'
            );
        }
        foreach ($optionBoosts as $field => $boost) {
            $field = (string) $field;
            if (strlen($field) > self::MAX_INDEX_FIELD_NAME_BYTES) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'field_boost_name_bytes',
                    'An FTS field-boost name exceeds the 191-byte limit.'
                );
            }
            if (is_string($boost) && strlen($boost) > 32) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'field_boost_value_bytes',
                    'An FTS field-boost value exceeds the 32-byte limit.'
                );
            }
            if (is_scalar($field) && is_numeric($boost)) {
                $boosts[$field] = $this->positive_boost((float) $boost);
                if (count($boosts) > self::MAX_FIELD_BOOSTS) {
                    throw new WP_FTS_Analysis_Limit_Exceeded(
                        'field_boosts',
                        'An FTS document contains more than 32 field boosts.'
                    );
                }
            }
        }

        $boosts = $this->apply_filter('wp_fts_post_field_boosts', $boosts, $post, $opts);
        if (is_array($boosts) && count($boosts) > self::MAX_FIELD_BOOSTS) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'field_boosts',
                'An FTS document contains more than 32 filtered field boosts.'
            );
        }
        $normalized = [];
        foreach (is_array($boosts) ? $boosts : [] as $field => $boost) {
            $field = (string) $field;
            if (strlen($field) > self::MAX_INDEX_FIELD_NAME_BYTES) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'field_boost_name_bytes',
                    'An FTS filtered field-boost name exceeds the 191-byte limit.'
                );
            }
            if (is_string($boost) && strlen($boost) > 32) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'field_boost_value_bytes',
                    'An FTS filtered field-boost value exceeds the 32-byte limit.'
                );
            }
            if (is_scalar($field) && is_numeric($boost)) {
                $normalized[$field] = $this->positive_boost((float) $boost);
            }
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * Match the integer precision stored in posting frequencies.
     */
    private function positive_boost(float $boost): float
    {
        if ($boost <= 0.0) {
            return 1.0;
        }

        return (float) max(1, round(min(100.0, $boost)));
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
        if (!is_array($fields)) {
            return [];
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
                continue;
            }

            $rawName = $field['name'] ?? 'content';
            $rawText = $field['text'] ?? ($field['html'] ?? '');
            $rawHtml = $field['html'] ?? null;
            if (!is_scalar($rawName) || !is_scalar($rawText) || ($rawHtml !== null && !is_scalar($rawHtml))) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'index_field_shape',
                    'FTS index field names and sources must be scalar.'
                );
            }

            $rawName = (string) $rawName;
            if (strlen($rawName) > self::MAX_INDEX_FIELD_NAME_BYTES) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'index_field_name_bytes',
                    'An FTS index field name exceeds the 191-byte limit.'
                );
            }
            $rawText = (string) $rawText;
            $rawHtml = $rawHtml !== null ? (string) $rawHtml : null;
            $sourceBytes += strlen($rawText) + ($rawHtml === null ? 0 : strlen($rawHtml));
            WP_FTS_Analysis_Limits::assert_document_source_bytes($sourceBytes);

            $name = trim($rawName);
            $text = $this->plain_text($rawText);
            $html = $rawHtml;
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
     * Extract taxonomy term labels from the caller's authoritative snapshot.
     *
     * @return array<string,string[]>
     */
    private function extract_terms(object $post, array $opts): array
    {
        $terms = [];
        if (isset($post->terms) && is_array($post->terms)) {
            foreach ($post->terms as $taxonomy => $values) {
                $terms[(string) $taxonomy] = $this->list_text_values($values);
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
    private function extract_custom_fields(object $post, array $opts): array
    {
        $keys = $this->selected_custom_field_keys($post, $opts);
        if ($keys === []) {
            return [];
        }

        $fields = [];
        foreach ($keys as $key) {
            $values = [];
            if (isset($post->custom_fields) && is_array($post->custom_fields)) {
                // The attached map is authoritative, including missing keys.
                $values = array_key_exists($key, $post->custom_fields)
                    ? $this->list_text_values($post->custom_fields[$key])
                    : [];
            }

            if ($values !== []) {
                $fields[$key] = $values;
            }
        }

        return $this->normalize_string_lists($this->apply_filter('wp_fts_post_custom_field_values', $fields, $post, $opts));
    }

    /**
     * Resolve selected custom field keys from explicit or attached input.
     *
     * @return string[]
     */
    public function selected_custom_field_keys(object $post, array $opts = []): array
    {
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

        $normalized = [];
        foreach ($this->selected_custom_field_key_values($keys) as $key) {
            foreach (explode(',', $key) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if (strlen($part) > self::MAX_CUSTOM_FIELD_KEY_BYTES) {
                    throw new WP_FTS_Analysis_Limit_Exceeded(
                        'custom_field_key_bytes',
                        'An FTS custom-field key exceeds the 191-byte limit.'
                    );
                }
                $normalized[$part] = true;
                if (count($normalized) > self::MAX_SELECTED_CUSTOM_FIELD_KEYS) {
                    throw new WP_FTS_Analysis_Limit_Exceeded(
                        'custom_field_keys',
                        'An FTS document selects more than 32 custom-field keys.'
                    );
                }
            }
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
     * Flatten the option/filter result without first copying an unbounded list.
     *
     * @return Generator<int,string>
     */
    private function selected_custom_field_key_values(mixed $values): Generator
    {
        $nodes = 0;
        $sourceBytes = 0;

        yield from $this->selected_custom_field_key_values_with_budget($values, 0, $nodes, $sourceBytes);
    }

    /**
     * @return Generator<int,string>
     */
    private function selected_custom_field_key_values_with_budget(
        mixed $values,
        int $depth,
        int &$nodes,
        int &$sourceBytes
    ): Generator {
        if (++$nodes > self::MAX_STRUCTURED_VALUE_NODES) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'custom_field_key_nodes',
                'The FTS custom-field key selection exceeds the 2,048-node limit.'
            );
        }
        if (is_array($values)) {
            if ($depth >= self::MAX_STRUCTURED_VALUE_DEPTH) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'custom_field_key_depth',
                    'The FTS custom-field key selection exceeds the 16-level nesting limit.'
                );
            }
            foreach ($values as $value) {
                yield from $this->selected_custom_field_key_values_with_budget(
                    $value,
                    $depth + 1,
                    $nodes,
                    $sourceBytes
                );
            }

            return;
        }

        $value = $values;
        if (is_object($value)) {
            if ($depth >= self::MAX_STRUCTURED_VALUE_DEPTH) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'custom_field_key_depth',
                    'The FTS custom-field key selection exceeds the 16-level nesting limit.'
                );
            }
            // Read declared public data only. Option/filter objects must not
            // execute `__get()` merely because indexing inspects their shape.
            $properties = get_object_vars($value);
            $value = $properties['name'] ?? $properties['slug'] ?? $properties['value'] ?? '';
            if (is_array($value) || is_object($value)) {
                yield from $this->selected_custom_field_key_values_with_budget(
                    $value,
                    $depth + 1,
                    $nodes,
                    $sourceBytes
                );

                return;
            }
        }
        if (!is_scalar($value)) {
            return;
        }

        $value = (string) $value;
        $sourceBytes += strlen($value);
        $listBytes = self::MAX_SELECTED_CUSTOM_FIELD_KEYS * self::MAX_CUSTOM_FIELD_KEY_BYTES
            + self::MAX_SELECTED_CUSTOM_FIELD_KEYS - 1;
        if ($sourceBytes > $listBytes) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'custom_field_key_source_bytes',
                'The FTS custom-field key selection exceeds its bounded input envelope.'
            );
        }

        $key = trim($value);
        // A valid comma-delimited list cannot exceed this envelope. Check
        // before explode() so hostile option data cannot allocate a huge
        // temporary array merely to discover that it is invalid.
        if (strlen($key) > $listBytes) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'custom_field_keys',
                'The FTS custom-field key selection exceeds its bounded input envelope.'
            );
        }
        if ($key !== '') {
            yield $key;
        }
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

    /**
     * Normalize taxonomy/custom-field maps to sorted non-empty string lists.
     *
     * @param mixed $lists
     * @return array<string,string[]>
     */
    private function normalize_string_lists(mixed $lists): array
    {
        $nodes = 0;
        $sourceBytes = 0;
        $textBytes = 0;

        return $this->normalize_string_lists_with_budget($lists, $nodes, $sourceBytes, $textBytes);
    }

    /**
     * @return array<string,string[]>
     */
    private function normalize_string_lists_with_budget(
        mixed $lists,
        int &$nodes,
        int &$sourceBytes,
        int &$textBytes
    ): array {
        if (!is_array($lists)) {
            return [];
        }
        if (count($lists) > self::MAX_STRUCTURED_MAP_KEYS) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'structured_map_keys',
                'An FTS structured map contains more than 32 keys.'
            );
        }

        $normalized = [];
        foreach ($lists as $key => $values) {
            $key = (string) $key;
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
            $key = trim($key);
            if ($key === '') {
                continue;
            }
            $items = [];
            $this->collect_text_values($values, 0, $nodes, $sourceBytes, $textBytes, $items);
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

    /**
     * Read a scalar post property.
     */
    private function post_prop(object $post, string $prop): string
    {
        return isset($post->{$prop}) && is_scalar($post->{$prop}) ? (string) $post->{$prop} : '';
    }

}
