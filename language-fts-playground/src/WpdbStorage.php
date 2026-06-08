<?php
declare(strict_types=1);

/**
 * Portable WordPress database storage for the PHP-only FTS demo.
 *
 * The tables store documents and term frequencies only. They intentionally do
 * not declare FULLTEXT indexes, SQLite FTS virtual tables, MATCH expressions, or
 * engine-specific DDL; the searcher reads these rows and ranks in PHP.
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
            'tf INTEGER NOT NULL' .
            ')'
        );
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
        array $term_frequencies
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
                ],
                ['%s', '%s', '%d', '%d']
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
