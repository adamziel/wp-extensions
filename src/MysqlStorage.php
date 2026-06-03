<?php
declare(strict_types=1);

final class WP_FTS_Storage_Mysql implements WP_FTS_Storage
{
    private object $wpdb;
    private string $termsTable;
    private string $docsTable;
    private string $docLengthsTable;
    private string $metaTable;

    public function __construct(object $wpdb, ?string $prefix = null)
    {
        $this->wpdb = $wpdb;
        $prefix = $prefix ?? (string) ($wpdb->prefix ?? '');
        $this->termsTable = $prefix . 'fts_terms';
        $this->docsTable = $prefix . 'fts_docs';
        $this->docLengthsTable = $prefix . 'fts_doc_lengths';
        $this->metaTable = $prefix . 'fts_meta';
    }

    public function create_tables(): void
    {
        $charset = 'DEFAULT CHARSET=binary';
        $sql = [
            "CREATE TABLE {$this->termsTable} (
term varbinary(255) NOT NULL,
doc_freq int unsigned NOT NULL,
postings longblob NOT NULL,
PRIMARY KEY  (term)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC {$charset};",
            "CREATE TABLE {$this->docsTable} (
doc_id bigint unsigned NOT NULL,
lang varchar(16) NOT NULL DEFAULT 'und',
doc_len int unsigned NOT NULL DEFAULT 0,
content_hash char(40) NULL,
is_deleted tinyint(1) NOT NULL DEFAULT 0,
PRIMARY KEY  (doc_id),
KEY lang (lang),
KEY is_deleted (is_deleted)
) ENGINE=InnoDB {$charset};",
            "CREATE TABLE {$this->docLengthsTable} (
doc_id bigint unsigned NOT NULL,
lang varchar(16) NOT NULL,
doc_len int unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (doc_id,lang),
KEY lang (lang)
) ENGINE=InnoDB {$charset};",
            "CREATE TABLE {$this->metaTable} (
lang varchar(16) NOT NULL,
k varchar(64) NOT NULL,
v bigint NOT NULL,
PRIMARY KEY  (lang,k)
) ENGINE=InnoDB {$charset};",
        ];

        if ($this->run_db_delta($sql)) {
            return;
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
        if (strlen($term) > WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES) {
            throw new InvalidArgumentException('Namespaced term exceeds the MySQL term key byte limit.');
        }

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
        $doc_ids = array_values(array_unique(array_map('intval', $doc_ids)));
        if ($doc_ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($doc_ids), '%d'));
        if ($lang === null) {
            $sql = $this->wpdb->prepare(
                "SELECT doc_id, doc_len FROM {$this->docsTable}
WHERE is_deleted = 0 AND doc_id IN ({$placeholders})",
                ...$doc_ids
            );
        } else {
            $args = array_merge([WP_FTS_TermNamespace::canonicalize_lang($lang)], $doc_ids);
            $sql = $this->wpdb->prepare(
                "SELECT dl.doc_id, dl.doc_len FROM {$this->docLengthsTable} dl
INNER JOIN {$this->docsTable} d ON d.doc_id = dl.doc_id
WHERE d.is_deleted = 0 AND dl.lang = %s AND dl.doc_id IN ({$placeholders})",
                ...$args
            );
        }
        $rows = $this->wpdb->get_results($sql);

        $lengths = [];
        foreach ($rows ?: [] as $row) {
            $length = (int) $row->doc_len;
            if ($length > 0) {
                $lengths[(int) $row->doc_id] = $length;
            }
        }
        ksort($lengths, SORT_NUMERIC);

        return $lengths;
    }

    public function get_doc(int $doc_id): ?array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT doc_id, lang, doc_len, content_hash, is_deleted FROM {$this->docsTable} WHERE doc_id = %d",
            $doc_id
        ));

        if (!$row) {
            return null;
        }

        $primaryLang = WP_FTS_TermNamespace::canonicalize_lang((string) ($row->lang ?? WP_FTS_TermNamespace::DEFAULT_LANG));
        $langLengths = [];
        $lengthRows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT lang, doc_len FROM {$this->docLengthsTable} WHERE doc_id = %d ORDER BY lang ASC",
            $doc_id
        ));
        foreach ($lengthRows ?: [] as $lengthRow) {
            $length = (int) $lengthRow->doc_len;
            if ($length > 0) {
                $langLengths[WP_FTS_TermNamespace::canonicalize_lang((string) $lengthRow->lang)] = $length;
            }
        }
        if ($langLengths === [] && (int) $row->doc_len > 0) {
            $langLengths[$primaryLang] = (int) $row->doc_len;
        }
        ksort($langLengths, SORT_STRING);

        return [
            'doc_len' => array_sum($langLengths),
            'lang' => $primaryLang,
            'primary_lang' => $primaryLang,
            'lang_lengths' => $langLengths,
            'content_hash' => $row->content_hash !== null ? (string) $row->content_hash : null,
            'deleted' => (bool) $row->is_deleted,
        ];
    }

    public function put_doc(int $doc_id, int|string $doc_len_or_primary_lang, string|array $hash_or_lang_lengths, ?string $hash = null): void
    {
        [$primaryLang, $langLengths, $contentHash] = $this->normalize_doc_payload(
            $doc_len_or_primary_lang,
            $hash_or_lang_lengths,
            $hash
        );

        $sql = $this->wpdb->prepare(
            "INSERT INTO {$this->docsTable} (doc_id, lang, doc_len, content_hash, is_deleted)
VALUES (%d, %s, %d, %s, 0)
ON DUPLICATE KEY UPDATE lang = VALUES(lang), doc_len = VALUES(doc_len), content_hash = VALUES(content_hash), is_deleted = 0",
            $doc_id,
            $primaryLang,
            array_sum($langLengths),
            $contentHash
        );
        $this->wpdb->query($sql);

        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->docLengthsTable} WHERE doc_id = %d",
            $doc_id
        ));
        foreach ($langLengths as $lang => $docLen) {
            $this->wpdb->query($this->wpdb->prepare(
                "INSERT INTO {$this->docLengthsTable} (doc_id, lang, doc_len)
VALUES (%d, %s, %d)
ON DUPLICATE KEY UPDATE doc_len = VALUES(doc_len)",
                $doc_id,
                $lang,
                $docLen
            ));
        }
    }

    public function delete_doc(int $doc_id): void
    {
        $sql = $this->wpdb->prepare(
            "INSERT INTO {$this->docsTable} (doc_id, lang, doc_len, content_hash, is_deleted)
VALUES (%d, %s, 0, NULL, 1)
ON DUPLICATE KEY UPDATE is_deleted = 1",
            $doc_id,
            WP_FTS_TermNamespace::DEFAULT_LANG
        );
        $this->wpdb->query($sql);
    }

    public function get_meta(?string $lang = null): array
    {
        if ($lang === null) {
            $rows = $this->wpdb->get_results("SELECT k, COALESCE(SUM(v), 0) AS v FROM {$this->metaTable} GROUP BY k");
        } else {
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT k, v FROM {$this->metaTable} WHERE lang = %s",
                WP_FTS_TermNamespace::canonicalize_lang($lang)
            ));
        }

        $meta = ['doc_count' => 0, 'len_sum' => 0];
        foreach ($rows ?: [] as $row) {
            if ($row->k === 'doc_count' || $row->k === 'len_sum') {
                $meta[$row->k] = max(0, (int) $row->v);
            }
        }

        return $meta;
    }

    public function add_meta(int|string $lang_or_d_docs, int $d_docs_or_d_len, ?int $d_len = null): void
    {
        [$lang, $dDocs, $lenDelta] = $this->normalize_meta_delta($lang_or_d_docs, $d_docs_or_d_len, $d_len);
        foreach (['doc_count' => $dDocs, 'len_sum' => $lenDelta] as $key => $delta) {
            $sql = $this->wpdb->prepare(
                "INSERT INTO {$this->metaTable} (lang, k, v)
VALUES (%s, %s, %d)
ON DUPLICATE KEY UPDATE v = GREATEST(0, v + %d)",
                $lang,
                $key,
                max(0, $delta),
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
                "DELETE FROM {$this->docLengthsTable} WHERE doc_id IN ({$placeholders})",
                ...$deletedIds
            ));
            $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$this->docsTable} WHERE doc_id IN ({$placeholders})",
                ...$deletedIds
            ));
        }

        $this->wpdb->query("DELETE FROM {$this->metaTable}");
        $rows = $this->wpdb->get_results(
            "SELECT dl.lang, COUNT(*) AS doc_count, COALESCE(SUM(dl.doc_len), 0) AS len_sum
FROM {$this->docLengthsTable} dl
INNER JOIN {$this->docsTable} d ON d.doc_id = dl.doc_id
WHERE d.is_deleted = 0 AND dl.doc_len > 0
GROUP BY dl.lang"
        );
        foreach ($rows ?: [] as $row) {
            $this->add_meta((string) $row->lang, (int) $row->doc_count, (int) $row->len_sum);
        }
    }

    /**
     * @param string[] $sql
     */
    private function run_db_delta(array $sql): bool
    {
        if (!function_exists('dbDelta') && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        if (!function_exists('dbDelta')) {
            return false;
        }

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        return true;
    }

    /**
     * @param int|string $doc_len_or_primary_lang
     * @param string|array<string,int> $hash_or_lang_lengths
     * @return array{0:string,1:array<string,int>,2:string}
     */
    private function normalize_doc_payload(int|string $doc_len_or_primary_lang, string|array $hash_or_lang_lengths, ?string $hash): array
    {
        if (is_int($doc_len_or_primary_lang)) {
            $length = max(0, $doc_len_or_primary_lang);
            return [
                WP_FTS_TermNamespace::DEFAULT_LANG,
                $length > 0 ? [WP_FTS_TermNamespace::DEFAULT_LANG => $length] : [],
                (string) $hash_or_lang_lengths,
            ];
        }

        if (!is_array($hash_or_lang_lengths) || $hash === null) {
            throw new InvalidArgumentException('put_doc language form requires language lengths and content hash.');
        }

        return [
            WP_FTS_TermNamespace::canonicalize_lang($doc_len_or_primary_lang),
            WP_FTS_TermNamespace::normalize_lengths($hash_or_lang_lengths),
            $hash,
        ];
    }

    /**
     * @return array{0:string,1:int,2:int}
     */
    private function normalize_meta_delta(int|string $lang_or_d_docs, int $d_docs_or_d_len, ?int $d_len): array
    {
        if ($d_len === null) {
            return [WP_FTS_TermNamespace::DEFAULT_LANG, (int) $lang_or_d_docs, $d_docs_or_d_len];
        }

        return [WP_FTS_TermNamespace::canonicalize_lang((string) $lang_or_d_docs), $d_docs_or_d_len, $d_len];
    }
}
