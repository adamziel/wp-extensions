<?php
declare(strict_types=1);

/**
 * Portable WordPress database storage for the PHP-only FTS demo.
 *
 * The tables store documents, term frequencies, and JSON term positions only.
 * They intentionally do not declare FULLTEXT indexes, SQLite FTS virtual
 * tables, MATCH expressions, or engine-specific DDL; the searcher reads these
 * rows and ranks in PHP.
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
            'updated_at TEXT NOT NULL' .
            ')'
        );

        $this->query(
            "CREATE TABLE IF NOT EXISTS {$this->postings_table} (" .
            'language TEXT NOT NULL, ' .
            'term TEXT NOT NULL, ' .
            'post_id INTEGER NOT NULL, ' .
            'tf INTEGER NOT NULL, ' .
            'positions TEXT NOT NULL' .
            ')'
        );
        $this->ensure_postings_positions_column();
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
        array $term_frequencies,
        array $term_positions
    ): void {
        $this->delete_document($post_id);

        $inserted = $this->wpdb->insert(
            $this->documents_table,
            [
                'post_id' => $post_id,
                'language' => $language,
                'title' => $title,
                'status' => $status,
                'document_length' => max(0, $document_length),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s']
        );
        if ($inserted === false) {
            $this->throw_last_error('Could not insert indexed document.');
        }

        foreach ($term_frequencies as $term => $tf) {
            $tf = max(1, (int) $tf);
            $inserted = $this->wpdb->insert(
                $this->postings_table,
                [
                    'language' => $language,
                    'term' => (string) $term,
                    'post_id' => $post_id,
                    'tf' => $tf,
                    'positions' => $this->encode_positions($term_positions[(string) $term] ?? []),
                ],
                ['%s', '%s', '%d', '%d', '%s']
            );
            if ($inserted === false) {
                $this->throw_last_error('Could not insert posting.');
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
        $sql = "SELECT term, post_id, tf FROM {$this->postings_table} WHERE language = %s AND term IN ({$placeholders})";
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, array_merge([$language], $terms)),
            $this->array_a()
        );

        $postings = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $term = (string) ($row['term'] ?? '');
            if ($term === '') {
                continue;
            }
            $postings[$term][(int) ($row['post_id'] ?? 0)] = max(1, (int) ($row['tf'] ?? 0));
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
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, array_merge([$language], $terms, $post_ids)),
            $this->array_a()
        );

        $positions = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $term = (string) ($row['term'] ?? '');
            $post_id = (int) ($row['post_id'] ?? 0);
            if ($term === '' || $post_id <= 0) {
                continue;
            }

            $positions[$term][$post_id] = $this->decode_positions((string) ($row['positions'] ?? '[]'));
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
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, [$language, $min_length, $max_length, $limit]),
            $this->array_a()
        );

        $terms = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
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
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, array_merge([$language], $post_ids)),
            $this->array_a()
        );

        $lengths = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $post_id = (int) ($row['post_id'] ?? 0);
            if ($post_id > 0) {
                $lengths[$post_id] = max(1, (int) ($row['document_length'] ?? 0));
            }
        }

        return $lengths;
    }

    public function document_count(string $language): int
    {
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT COUNT(*) FROM {$this->documents_table} WHERE language = %s", $language)
        );

        return max(0, (int) $count);
    }

    public function all_documents(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT post_id, language, title, status, document_length, updated_at FROM {$this->documents_table} ORDER BY language ASC, post_id ASC",
            $this->array_a()
        );

        $documents = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
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

    private function table_name(string $candidate): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '', $candidate) ?: 'wp_language_fts_storage';
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

    private function ensure_postings_positions_column(): void
    {
        if ($this->column_exists($this->postings_table, 'positions')) {
            return;
        }

        $altered = $this->wpdb->query("ALTER TABLE {$this->postings_table} ADD COLUMN positions TEXT");
        if ($altered === false && !$this->column_exists($this->postings_table, 'positions')) {
            $this->throw_last_error('Could not add posting positions column.');
        }
    }

    private function column_exists(string $table, string $column): bool
    {
        $rows = $this->wpdb->get_results("PRAGMA table_info({$table})", $this->array_a());
        foreach (is_array($rows) ? $rows : [] as $row) {
            if ((string) ($row['name'] ?? '') === $column) {
                return true;
            }
        }

        $rows = $this->wpdb->get_results("SHOW COLUMNS FROM {$table}", $this->array_a());
        foreach (is_array($rows) ? $rows : [] as $row) {
            if ((string) ($row['Field'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }

    private function query(string $sql): void
    {
        if ($this->wpdb->query($sql) === false) {
            $this->throw_last_error('Database query failed.');
        }
    }

    private function throw_last_error(string $message): never
    {
        $last_error = isset($this->wpdb->last_error) ? (string) $this->wpdb->last_error : '';
        throw new RuntimeException($last_error !== '' ? $message . ' ' . $last_error : $message);
    }

    private function array_a(): string
    {
        return defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
    }
}
