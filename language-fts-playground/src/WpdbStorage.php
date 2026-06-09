<?php
declare(strict_types=1);

/**
 * Portable WordPress database storage for the PHP-only FTS demo.
 *
 * The tables store documents, field-aware term frequencies, and JSON term
 * positions only. They intentionally do not declare FULLTEXT indexes, SQLite
 * FTS virtual tables, MATCH expressions, or engine-specific DDL; the searcher
 * reads these rows and ranks in PHP.
 */
final class Language_FTS_Playground_Wpdb_Storage implements Language_FTS_Playground_Storage_Interface
{
    private object $wpdb;
    private string $documents_table;
    private string $postings_table;

    public function __construct(object $wpdb, string|null $prefix = null)
    {
        $this->wpdb = $wpdb;
        $prefix = $prefix ?? (string) ($wpdb->prefix ?? 'wp_');
        $this->documents_table = $this->table_name($prefix . 'language_fts_documents');
        $this->postings_table = $this->table_name($prefix . 'language_fts_postings');
    }

    public function install(): void
    {
        $this->query(
            "CREATE TABLE IF NOT EXISTS {$this->documents_table} (" .
            'post_id INTEGER NOT NULL, ' .
            'language TEXT NOT NULL, ' .
            'title TEXT NOT NULL, ' .
            'status TEXT NOT NULL, ' .
            'document_length INTEGER NOT NULL, ' .
            'field_texts TEXT NOT NULL, ' .
            'field_metadata TEXT NOT NULL, ' .
            'updated_at TEXT NOT NULL' .
            ')'
        );

        $this->query(
            "CREATE TABLE IF NOT EXISTS {$this->postings_table} (" .
            'language TEXT NOT NULL, ' .
            'term TEXT NOT NULL, ' .
            'post_id INTEGER NOT NULL, ' .
            'field VARCHAR(32) NOT NULL, ' .
            'tf INTEGER NOT NULL, ' .
            'positions TEXT NOT NULL' .
            ')'
        );

        $this->ensure_schema_columns();
    }

    public function clear(): void
    {
        $this->query("DELETE FROM {$this->documents_table}");
        $this->query("DELETE FROM {$this->postings_table}");
    }

    public function replace_document(
        int $post_id,
        string $language,
        string $title,
        string $status,
        int $document_length,
        array $field_term_frequencies,
        array $field_texts,
        array $term_positions
    ): void {
        $this->replace_document_partitions(
            $post_id,
            [
                [
                    'language' => $language,
                    'title' => $title,
                    'status' => $status,
                    'document_length' => $document_length,
                    'field_term_frequencies' => $field_term_frequencies,
                    'field_texts' => $field_texts,
                    'term_positions' => $term_positions,
                ],
            ]
        );
    }

    public function replace_document_partitions(int $post_id, array $partitions): void
    {
        $this->delete_document($post_id);

        foreach ($partitions as $partition) {
            $language = (string) ($partition['language'] ?? '');
            $field_texts = (array) ($partition['field_texts'] ?? []);
            $field_keys = array_values(array_unique(array_merge(
                array_map('strval', array_keys($field_texts)),
                array_map('strval', array_keys((array) ($partition['field_term_frequencies'] ?? [])))
            )));
            $inserted = $this->wpdb->insert(
                $this->documents_table,
                [
                    'post_id' => $post_id,
                    'language' => $language,
                    'title' => (string) ($partition['title'] ?? ''),
                    'status' => (string) ($partition['status'] ?? ''),
                    'document_length' => max(0, (int) ($partition['document_length'] ?? 0)),
                    'field_texts' => $this->encode_json_object($field_texts),
                    'field_metadata' => $this->encode_field_metadata($language, $field_keys, (array) ($partition['field_metadata'] ?? [])),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ],
                ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
            );
            if ($inserted === false) {
                $this->throw_last_error('Could not insert indexed document.');
            }

            $term_positions = (array) ($partition['term_positions'] ?? []);
            foreach ((array) ($partition['field_term_frequencies'] ?? []) as $field => $term_frequencies) {
                $field = $this->normalize_field((string) $field);
                foreach ((array) $term_frequencies as $term => $tf) {
                    $term = (string) $term;
                    $tf = max(1, (int) $tf);
                    $inserted = $this->wpdb->insert(
                        $this->postings_table,
                        [
                            'language' => $language,
                            'term' => $term,
                            'post_id' => $post_id,
                            'field' => $field,
                            'tf' => $tf,
                            'positions' => $this->encode_positions((array) ($term_positions[$term] ?? [])),
                        ],
                        ['%s', '%s', '%d', '%s', '%d', '%s']
                    );
                    if ($inserted === false) {
                        $this->throw_last_error('Could not insert posting.');
                    }
                }
            }
        }
    }

    public function delete_document(int $post_id): void
    {
        $this->query($this->wpdb->prepare("DELETE FROM {$this->documents_table} WHERE post_id = %d", $post_id));
        $this->query($this->wpdb->prepare("DELETE FROM {$this->postings_table} WHERE post_id = %d", $post_id));
    }

    public function fetch_postings(string $language, array $terms): array
    {
        $terms = $this->normalize_term_list($terms);
        if ($terms === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($terms), '%s'));
        $sql = "SELECT term, post_id, field, tf FROM {$this->postings_table} WHERE language = %s AND term IN ({$placeholders})";
        $rows = $this->result_rows(
            $this->wpdb->get_results(
                $this->wpdb->prepare($sql, array_merge([$language], $terms)),
                $this->array_a()
            ),
            'Could not fetch postings.'
        );

        $postings = [];
        foreach ($rows as $row) {
            $term = (string) ($row['term'] ?? '');
            if ($term === '') {
                continue;
            }
            $post_id = (int) ($row['post_id'] ?? 0);
            if ($post_id <= 0) {
                continue;
            }

            $field = $this->normalize_field((string) ($row['field'] ?? 'content'));
            $postings[$term][$post_id][$field] = ($postings[$term][$post_id][$field] ?? 0) + max(1, (int) ($row['tf'] ?? 0));
        }

        return $postings;
    }

    public function fetch_positions(string $language, array $terms, array $post_ids): array
    {
        $terms = $this->normalize_term_list($terms);
        $post_ids = $this->normalize_post_id_list($post_ids);
        if ($terms === [] || $post_ids === []) {
            return [];
        }

        $term_placeholders = implode(',', array_fill(0, count($terms), '%s'));
        $post_placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $sql = "SELECT term, post_id, positions FROM {$this->postings_table} WHERE language = %s AND term IN ({$term_placeholders}) AND post_id IN ({$post_placeholders})";
        $rows = $this->result_rows(
            $this->wpdb->get_results(
                $this->wpdb->prepare($sql, array_merge([$language], $terms, $post_ids)),
                $this->array_a()
            ),
            'Could not fetch positions.'
        );

        $positions = [];
        foreach ($rows as $row) {
            $term = (string) ($row['term'] ?? '');
            $post_id = (int) ($row['post_id'] ?? 0);
            if ($term === '' || $post_id <= 0) {
                continue;
            }

            foreach ($this->decode_positions((string) ($row['positions'] ?? '[]')) as $position) {
                $positions[$term][$post_id][(int) $position] = (int) $position;
            }
        }

        foreach ($positions as $term => $post_positions) {
            foreach ($post_positions as $post_id => $position_lookup) {
                $positions[$term][$post_id] = array_values($position_lookup);
                sort($positions[$term][$post_id], SORT_NUMERIC);
            }
        }

        return $positions;
    }

    public function fetch_candidate_terms(string $language, string $term, int $max_distance, int $limit): array
    {
        $length = strlen($term);
        $max_distance = max(0, $max_distance);
        $min_length = max(1, $length - $max_distance);
        $max_length = $length + $max_distance;
        $limit = max(1, min(500, $limit));

        $sql = "SELECT DISTINCT term FROM {$this->postings_table} WHERE language = %s AND LENGTH(term) BETWEEN %d AND %d ORDER BY term ASC LIMIT %d";
        $rows = $this->result_rows(
            $this->wpdb->get_results(
                $this->wpdb->prepare($sql, [$language, $min_length, $max_length, $limit]),
                $this->array_a()
            ),
            'Could not fetch fuzzy candidate terms.'
        );

        $terms = [];
        foreach ($rows as $row) {
            $candidate = trim((string) ($row['term'] ?? ''));
            if ($candidate !== '') {
                $terms[] = $candidate;
            }
        }

        return array_values(array_unique($terms));
    }

    public function fetch_document_lengths(string $language, array $post_ids): array
    {
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids), static fn(int $id): bool => $id > 0)));
        if ($post_ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $sql = "SELECT post_id, document_length FROM {$this->documents_table} WHERE language = %s AND post_id IN ({$placeholders})";
        $rows = $this->result_rows(
            $this->wpdb->get_results(
                $this->wpdb->prepare($sql, array_merge([$language], $post_ids)),
                $this->array_a()
            ),
            'Could not fetch document lengths.'
        );

        $lengths = [];
        foreach ($rows as $row) {
            $post_id = (int) ($row['post_id'] ?? 0);
            if ($post_id > 0) {
                $lengths[$post_id] = max(1, (int) ($row['document_length'] ?? 0));
            }
        }

        return $lengths;
    }

    public function fetch_document_fields(string $language, array $post_ids): array
    {
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids), static fn(int $id): bool => $id > 0)));
        if ($post_ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $sql = "SELECT post_id, field_texts FROM {$this->documents_table} WHERE language = %s AND post_id IN ({$placeholders})";
        $rows = $this->result_rows(
            $this->wpdb->get_results(
                $this->wpdb->prepare($sql, array_merge([$language], $post_ids)),
                $this->array_a()
            ),
            'Could not fetch document fields.'
        );

        $documents = [];
        foreach ($rows as $row) {
            $post_id = (int) ($row['post_id'] ?? 0);
            if ($post_id > 0) {
                $documents[$post_id] = $this->decode_json_object((string) ($row['field_texts'] ?? ''));
            }
        }

        return $documents;
    }

    public function fetch_document_field_metadata(string $language, array $post_ids): array
    {
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids), static fn(int $id): bool => $id > 0)));
        if ($post_ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $sql = "SELECT post_id, field_metadata FROM {$this->documents_table} WHERE language = %s AND post_id IN ({$placeholders})";
        $rows = $this->result_rows(
            $this->wpdb->get_results(
                $this->wpdb->prepare($sql, array_merge([$language], $post_ids)),
                $this->array_a()
            ),
            'Could not fetch document field metadata.'
        );

        $documents = [];
        foreach ($rows as $row) {
            $post_id = (int) ($row['post_id'] ?? 0);
            if ($post_id > 0) {
                $documents[$post_id] = $this->decode_field_metadata((string) ($row['field_metadata'] ?? ''));
            }
        }

        return $documents;
    }

    public function document_count(string $language): int
    {
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT COUNT(*) FROM {$this->documents_table} WHERE language = %s", $language)
        );
        if ($count === null && $this->last_error() !== '') {
            $this->throw_last_error('Could not count indexed documents.');
        }

        return max(0, (int) $count);
    }

    public function all_documents(): array
    {
        $rows = $this->result_rows(
            $this->wpdb->get_results(
                "SELECT post_id, language, title, status, document_length, updated_at FROM {$this->documents_table} ORDER BY language ASC, post_id ASC",
                $this->array_a()
            ),
            'Could not fetch indexed documents.'
        );

        $documents = [];
        foreach ($rows as $row) {
            $documents[] = [
                'post_id' => (int) ($row['post_id'] ?? 0),
                'language' => (string) ($row['language'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'document_length' => max(0, (int) ($row['document_length'] ?? 0)),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $documents;
    }

    public function documents_table(): string
    {
        return $this->documents_table;
    }

    public function postings_table(): string
    {
        return $this->postings_table;
    }

    private function ensure_schema_columns(): void
    {
        if (!$this->column_exists($this->documents_table, 'field_texts')) {
            $this->query("ALTER TABLE {$this->documents_table} ADD COLUMN field_texts TEXT");
        }

        if (!$this->column_exists($this->documents_table, 'field_metadata')) {
            $this->query("ALTER TABLE {$this->documents_table} ADD COLUMN field_metadata TEXT NOT NULL DEFAULT '{}'");
        }

        if (!$this->column_exists($this->postings_table, 'field')) {
            $this->query("ALTER TABLE {$this->postings_table} ADD COLUMN field VARCHAR(32) NOT NULL DEFAULT 'content'");
        }

        if (!$this->column_exists($this->postings_table, 'positions')) {
            $this->query("ALTER TABLE {$this->postings_table} ADD COLUMN positions TEXT");
        }
    }

    private function column_exists(string $table, string $column): bool
    {
        $this->wpdb->get_results("SELECT * FROM {$table} WHERE 1 = 0", $this->array_a());
        if (method_exists($this->wpdb, 'get_col_info')) {
            $columns = $this->wpdb->get_col_info('name');
            if (is_array($columns) && $columns !== []) {
                return in_array($column, array_map('strval', $columns), true);
            }
        }

        $sqlite_rows = $this->wpdb->get_results("PRAGMA table_info({$table})", $this->array_a());
        foreach (is_array($sqlite_rows) ? $sqlite_rows : [] as $row) {
            if ((string) ($row['name'] ?? '') === $column) {
                return true;
            }
        }

        $mysql_rows = $this->wpdb->get_results("SHOW COLUMNS FROM {$table}", $this->array_a());
        foreach (is_array($mysql_rows) ? $mysql_rows : [] as $row) {
            if ((string) ($row['Field'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }

    private function table_name(string $candidate): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '', $candidate) ?: 'wp_language_fts_storage';
    }

    /**
     * @param array<string,string> $value
     */
    private function encode_json_object(array $value): string
    {
        return $this->encode_json_value($value);
    }

    /**
     * @param array<string,mixed> $value
     */
    private function encode_json_value(array $value): string
    {
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);

        return is_string($encoded) ? $encoded : '{}';
    }

    /**
     * @return array<string,string>
     */
    private function decode_json_object(string $value): array
    {
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        $object = [];
        foreach ($decoded as $field => $text) {
            if (is_scalar($text)) {
                $object[$this->normalize_field((string) $field)] = (string) $text;
            }
        }

        return $object;
    }

    /**
     * @param string[] $field_keys
     * @param array<string,mixed> $field_metadata
     */
    private function encode_field_metadata(string $language, array $field_keys, array $field_metadata): string
    {
        return $this->encode_json_value($this->normalize_field_metadata($language, $field_keys, $field_metadata));
    }

    /**
     * @return array<string,array{language:string,language_provenance:string}>
     */
    private function decode_field_metadata(string $value): array
    {
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        $metadata = [];
        foreach ($decoded as $field => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $field_language = trim((string) ($entry['language'] ?? ''));
            $provenance = trim((string) ($entry['language_provenance'] ?? ''));
            if ($field_language === '' && $provenance === '') {
                continue;
            }

            $metadata[$this->normalize_field((string) $field)] = [
                'language' => $field_language,
                'language_provenance' => $provenance !== '' ? $provenance : 'fallback',
            ];
        }

        return $metadata;
    }

    /**
     * @param string[] $field_keys
     * @param array<string,mixed> $field_metadata
     * @return array<string,array{language:string,language_provenance:string}>
     */
    private function normalize_field_metadata(string $language, array $field_keys, array $field_metadata): array
    {
        $metadata = [];
        foreach (array_unique(array_merge($field_keys, array_map('strval', array_keys($field_metadata)))) as $field) {
            $field = $this->normalize_field((string) $field);
            $entry = $field_metadata[$field] ?? [];
            $entry = is_array($entry) ? $entry : [];
            $field_language = trim((string) ($entry['language'] ?? $language));
            $provenance = trim((string) ($entry['language_provenance'] ?? 'fallback'));

            $metadata[$field] = [
                'language' => $field_language !== '' ? $field_language : $language,
                'language_provenance' => $provenance !== '' ? $provenance : 'fallback',
            ];
        }

        return $metadata;
    }

    private function normalize_field(string $field): string
    {
        return in_array($field, ['title', 'excerpt', 'content', 'alt'], true) ? $field : 'content';
    }

    /**
     * @param string[] $terms
     * @return string[]
     */
    private function normalize_term_list(array $terms): array
    {
        $normalized = [];
        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term !== '') {
                $normalized[$term] = true;
            }
        }

        return array_keys($normalized);
    }

    /**
     * @param int[] $post_ids
     * @return int[]
     */
    private function normalize_post_id_list(array $post_ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $post_ids), static fn(int $id): bool => $id > 0)));
    }

    /**
     * @param int[] $positions
     */
    private function encode_positions(array $positions): string
    {
        $positions = array_values(array_map('intval', $positions));
        $json = json_encode($positions);

        return is_string($json) ? $json : '[]';
    }

    /**
     * @return int[]
     */
    private function decode_positions(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_map('intval', $decoded));
    }

    private function query(string $sql): void
    {
        if ($this->wpdb->query($sql) === false) {
            $this->throw_last_error('Database query failed.');
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function result_rows(mixed $rows, string $message): array
    {
        if (is_array($rows)) {
            return $rows;
        }

        if ($this->last_error() !== '') {
            $this->throw_last_error($message);
        }

        return [];
    }

    private function throw_last_error(string $message): never
    {
        $last_error = $this->last_error();
        throw new RuntimeException($last_error !== '' ? $message . ' ' . $last_error : $message);
    }

    private function last_error(): string
    {
        return isset($this->wpdb->last_error) ? (string) $this->wpdb->last_error : '';
    }

    private function array_a(): string
    {
        return defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
    }
}
