<?php
declare(strict_types=1);

final class WP_FTS_Storage_Mysql implements WP_FTS_Storage
{
    private object $wpdb;
    private string $termsTable;
    private string $docsTable;
    private string $metaTable;

    public function __construct(object $wpdb, ?string $prefix = null)
    {
        $this->wpdb = $wpdb;
        $prefix = $prefix ?? (string) ($wpdb->prefix ?? '');
        $this->termsTable = $prefix . 'fts_terms';
        $this->docsTable = $prefix . 'fts_docs';
        $this->metaTable = $prefix . 'fts_meta';
    }

    public function create_tables(): void
    {
        $charset = 'DEFAULT CHARSET=binary';
        if (method_exists($this->wpdb, 'get_charset_collate')) {
            $charset = 'DEFAULT CHARSET=binary';
        }

        $sql = [
            "CREATE TABLE {$this->termsTable} (
term varbinary(255) NOT NULL,
doc_freq int unsigned NOT NULL,
postings longblob NOT NULL,
PRIMARY KEY  (term)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC {$charset};",
            "CREATE TABLE {$this->docsTable} (
doc_id bigint unsigned NOT NULL,
doc_len int unsigned NOT NULL,
content_hash char(40) NULL,
is_deleted tinyint(1) NOT NULL DEFAULT 0,
PRIMARY KEY  (doc_id)
) ENGINE=InnoDB {$charset};",
            "CREATE TABLE {$this->metaTable} (
k varchar(64) NOT NULL,
v bigint NOT NULL,
PRIMARY KEY  (k)
) ENGINE=InnoDB {$charset};",
        ];

        if (function_exists('dbDelta')) {
            foreach ($sql as $statement) {
                dbDelta($statement);
            }
            return;
        }

        if (defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            if (function_exists('dbDelta')) {
                foreach ($sql as $statement) {
                    dbDelta($statement);
                }
                return;
            }
        }

        foreach ($sql as $statement) {
            $this->wpdb->query($statement);
        }
    }

    public function get_terms(array $terms): array
    {
        $terms = array_values(array_unique(array_map('strval', $terms)));
        if ($terms === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($terms), '%s'));
        $sql = $this->wpdb->prepare(
            "SELECT term, doc_freq, postings FROM {$this->termsTable} WHERE term IN ({$placeholders})",
            ...$terms
        );
        $rows = $this->wpdb->get_results($sql);

        $result = [];
        foreach ($rows ?: [] as $row) {
            $term = (string) $row->term;
            $result[$term] = [
                'df' => (int) $row->doc_freq,
                'postings' => (string) $row->postings,
            ];
        }

        return $result;
    }

    public function put_term(string $term, int $df, string $postings): void
    {
        if ($df <= 0) {
            $this->delete_term($term);
            return;
        }

        $sql = $this->wpdb->prepare(
            "INSERT INTO {$this->termsTable} (term, doc_freq, postings)
VALUES (%s, %d, %s)
ON DUPLICATE KEY UPDATE doc_freq = VALUES(doc_freq), postings = VALUES(postings)",
            $term,
            $df,
            $postings
        );
        $this->wpdb->query($sql);
    }

    public function delete_term(string $term): void
    {
        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->termsTable} WHERE term = %s",
            $term
        ));
    }

    public function get_doc_lengths(array $doc_ids, ?string $lang = null): array
    {
        $this->assert_legacy_language_partition($lang);
        $doc_ids = array_values(array_unique(array_map('intval', $doc_ids)));
        if ($doc_ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($doc_ids), '%d'));
        $sql = $this->wpdb->prepare(
            "SELECT doc_id, doc_len FROM {$this->docsTable}
WHERE is_deleted = 0 AND doc_id IN ({$placeholders})",
            ...$doc_ids
        );
        $rows = $this->wpdb->get_results($sql);

        $lengths = [];
        foreach ($rows ?: [] as $row) {
            $lengths[(int) $row->doc_id] = (int) $row->doc_len;
        }
        ksort($lengths, SORT_NUMERIC);

        return $lengths;
    }

    public function get_doc(int $doc_id): ?array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT doc_len, content_hash, is_deleted FROM {$this->docsTable} WHERE doc_id = %d",
            $doc_id
        ));

        if (!$row) {
            return null;
        }

        return [
            'primary_lang' => '',
            'lang_lengths' => (int) $row->doc_len > 0 ? ['' => (int) $row->doc_len] : [],
            'doc_len' => (int) $row->doc_len,
            'content_hash' => $row->content_hash !== null ? (string) $row->content_hash : null,
            'deleted' => (bool) $row->is_deleted,
        ];
    }

    public function put_doc(int $doc_id, string|int $primary_lang, array|string $lang_lengths, ?string $hash = null): void
    {
        [$normalizedLang, $normalizedLengths, $contentHash] = $this->normalize_put_doc_args(
            $primary_lang,
            $lang_lengths,
            $hash
        );
        $this->assert_legacy_language_partition($normalizedLang);
        foreach (array_keys($normalizedLengths) as $lang) {
            $this->assert_legacy_language_partition($lang);
        }

        $sql = $this->wpdb->prepare(
            "INSERT INTO {$this->docsTable} (doc_id, doc_len, content_hash, is_deleted)
VALUES (%d, %d, %s, 0)
ON DUPLICATE KEY UPDATE doc_len = VALUES(doc_len), content_hash = VALUES(content_hash), is_deleted = 0",
            $doc_id,
            array_sum($normalizedLengths),
            $contentHash
        );
        $this->wpdb->query($sql);
    }

    public function delete_doc(int $doc_id): void
    {
        $sql = $this->wpdb->prepare(
            "INSERT INTO {$this->docsTable} (doc_id, doc_len, content_hash, is_deleted)
VALUES (%d, 0, NULL, 1)
ON DUPLICATE KEY UPDATE is_deleted = 1",
            $doc_id
        );
        $this->wpdb->query($sql);
    }

    public function get_meta(?string $lang = null): array
    {
        $this->assert_legacy_language_partition($lang);
        $rows = $this->wpdb->get_results("SELECT k, v FROM {$this->metaTable}");
        $meta = ['doc_count' => 0, 'len_sum' => 0];
        foreach ($rows ?: [] as $row) {
            if ($row->k === 'doc_count' || $row->k === 'len_sum') {
                $meta[$row->k] = max(0, (int) $row->v);
            }
        }

        return $meta;
    }

    public function add_meta(string|int $lang, int $d_docs, ?int $d_len = null): void
    {
        [$normalizedLang, $docDelta, $lenDelta] = $this->normalize_meta_args($lang, $d_docs, $d_len);
        $this->assert_legacy_language_partition($normalizedLang);

        foreach (['doc_count' => $docDelta, 'len_sum' => $lenDelta] as $key => $delta) {
            $sql = $this->wpdb->prepare(
                "INSERT INTO {$this->metaTable} (k, v)
VALUES (%s, %d)
ON DUPLICATE KEY UPDATE v = GREATEST(0, v + VALUES(v))",
                $key,
                $delta
            );
            $this->wpdb->query($sql);
        }
    }

    public function all_terms(): array
    {
        $terms = array_map('strval', $this->wpdb->get_col("SELECT term FROM {$this->termsTable} ORDER BY term ASC") ?: []);
        sort($terms, SORT_STRING);

        return $terms;
    }

    public function all_doc_ids(bool $include_deleted = false): array
    {
        $where = $include_deleted ? '' : ' WHERE is_deleted = 0';
        $ids = array_map('intval', $this->wpdb->get_col("SELECT doc_id FROM {$this->docsTable}{$where} ORDER BY doc_id ASC") ?: []);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    public function begin_transaction(): void
    {
        $this->wpdb->query('START TRANSACTION');
    }

    public function commit(): void
    {
        $this->wpdb->query('COMMIT');
    }

    public function rollback(): void
    {
        $this->wpdb->query('ROLLBACK');
    }

    public function flush(): void
    {
    }

    public function optimize(): void
    {
        $deletedIds = array_map('intval', $this->wpdb->get_col(
            "SELECT doc_id FROM {$this->docsTable} WHERE is_deleted = 1"
        ) ?: []);
        if ($deletedIds !== []) {
            $deleted = array_fill_keys($deletedIds, true);
            foreach ($this->get_terms($this->all_terms()) as $term => $row) {
                $postings = WP_FTS_PostingsCodec::decode($row['postings']);
                foreach ($deleted as $docId => $_) {
                    unset($postings[$docId]);
                }
                $this->put_term($term, count($postings), WP_FTS_PostingsCodec::encode($postings));
            }
            $placeholders = implode(',', array_fill(0, count($deletedIds), '%d'));
            $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$this->docsTable} WHERE doc_id IN ({$placeholders})",
                ...$deletedIds
            ));
        }

        $row = $this->wpdb->get_row("SELECT COUNT(*) AS c, COALESCE(SUM(doc_len), 0) AS s FROM {$this->docsTable} WHERE is_deleted = 0");
        $docCount = (int) ($row->c ?? 0);
        $lenSum = (int) ($row->s ?? 0);
        $this->wpdb->query("DELETE FROM {$this->metaTable} WHERE k IN ('doc_count', 'len_sum')");
        $this->add_meta($docCount, $lenSum);
    }

    /**
     * Lane 5 owns the MySQL schema migration to fts_docs.lang, per-language doc
     * lengths, and fts_meta(lang, k). Until then, this backend only supports the
     * legacy aggregate partition.
     */
    private function assert_legacy_language_partition(?string $lang): void
    {
        if ($lang !== null && trim($lang) !== '') {
            throw new RuntimeException('MySQL language-aware storage requires the Lane 5 schema update.');
        }
    }

    /**
     * @return array{string,array<string,int>,string}
     */
    private function normalize_put_doc_args(string|int $primary_lang, array|string $lang_lengths, ?string $hash): array
    {
        if (is_int($primary_lang) && is_string($lang_lengths) && $hash === null) {
            return ['', $this->normalize_lang_lengths(['' => $primary_lang]), $lang_lengths];
        }

        if (!is_string($primary_lang) || !is_array($lang_lengths) || $hash === null) {
            throw new InvalidArgumentException('put_doc expects ($doc_id, $primary_lang, $lang_lengths, $hash).');
        }

        return [$this->normalize_lang($primary_lang), $this->normalize_lang_lengths($lang_lengths), $hash];
    }

    /**
     * @param array<string,int> $lang_lengths
     * @return array<string,int>
     */
    private function normalize_lang_lengths(array $lang_lengths): array
    {
        $normalized = [];
        foreach ($lang_lengths as $lang => $length) {
            $length = max(0, (int) $length);
            if ($length <= 0) {
                continue;
            }
            $normalized[$this->normalize_lang((string) $lang)] = $length;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function normalize_lang(string $lang): string
    {
        return trim($lang);
    }

    /**
     * @return array{string,int,int}
     */
    private function normalize_meta_args(string|int $lang, int $d_docs, ?int $d_len): array
    {
        if (is_int($lang) && $d_len === null) {
            return ['', $lang, $d_docs];
        }

        if (!is_string($lang) || $d_len === null) {
            throw new InvalidArgumentException('add_meta expects ($lang, $d_docs, $d_len).');
        }

        return [$this->normalize_lang($lang), $d_docs, $d_len];
    }
}
