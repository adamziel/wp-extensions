<?php
declare(strict_types=1);

/**
 * WordPress MySQL storage backend for the full-text index.
 *
 * Terms are stored in a binary-key table, documents keep a tombstone flag, and
 * per-language lengths live in a separate table so BM25 can score inside one
 * language partition without mixing collection statistics.
 */
final class WP_FTS_Storage_Mysql implements WP_FTS_Storage, WP_FTS_DocumentMetadataStorage
{
    private object $wpdb;
    private string $termsTable;
    private string $docsTable;
    private string $docLengthsTable;
    private string $docMetaTable;
    private string $metaTable;

    /**
     * Bind the backend to a WordPress database connection and table prefix.
     *
     * @param object $wpdb WordPress `$wpdb`-compatible object.
     * @param string|null $prefix Optional table prefix; defaults to
     *        `$wpdb->prefix`.
     */
    public function __construct(object $wpdb, ?string $prefix = null)
    {
        $this->wpdb = $wpdb;
        $prefix = $prefix ?? (string) ($wpdb->prefix ?? '');
        $this->termsTable = $prefix . 'fts_terms';
        $this->docsTable = $prefix . 'fts_docs';
        $this->docLengthsTable = $prefix . 'fts_doc_lengths';
        $this->docMetaTable = $prefix . 'fts_docmeta';
        $this->metaTable = $prefix . 'fts_meta';
    }

    /**
     * Create or update the index tables.
     *
     * `dbDelta()` is used when available so WordPress installations can evolve
     * schemas in place. Outside WordPress or without dbDelta, the raw CREATE
     * statements are executed. `DEFAULT CHARSET=binary` keeps term keys and
     * postings byte-stable.
     */
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
            "CREATE TABLE {$this->docMetaTable} (
doc_id bigint unsigned NOT NULL,
post_id bigint unsigned NOT NULL DEFAULT 0,
post_type varchar(32) NOT NULL DEFAULT '',
post_status varchar(20) NOT NULL DEFAULT '',
post_date_gmt varchar(19) NOT NULL DEFAULT '',
title text NULL,
excerpt text NULL,
search_text mediumtext NULL,
data longtext NULL,
PRIMARY KEY  (doc_id),
KEY post_id (post_id),
KEY post_type_status_date (post_type,post_status,post_date_gmt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
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

    /**
     * Return existing term rows for the requested keys.
     *
     * @param string[] $terms Stored term keys.
     * @return array<string,array{df:int,postings:string}> Rows keyed by term.
     */
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

    /**
     * Insert, replace, or remove one term row.
     *
     * MySQL stores term keys in `varbinary(255)`, so namespaced keys longer than
     * that are rejected before the database write.
     *
     * @throws InvalidArgumentException If the namespaced term exceeds 255 bytes.
     */
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

    /**
     * Delete one term row by its stored key.
     */
    public function delete_term(string $term): void
    {
        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->termsTable} WHERE term = %s",
            $term
        ));
    }

    /**
     * Return active document lengths, optionally for one language partition.
     *
     * Aggregate reads come from the document table. Language-specific reads join
     * the per-language lengths table with active documents to exclude tombstones.
     *
     * @param int[] $doc_ids Document ids to inspect.
     * @return array<int,int> Positive lengths keyed by document id.
     */
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

    /**
     * Fetch one document metadata row and its per-language lengths.
     *
     * Older rows without length-table entries fall back to the aggregate
     * document length under the primary language.
     *
     * @return array{doc_len:int,lang:string,primary_lang:string,lang_lengths:array<string,int>,content_hash:?string,deleted:bool}|null
     */
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

    /**
     * Store document metadata in either language-aware or legacy shape.
     *
     * New calls replace all per-language length rows for the document. Legacy
     * calls store aggregate length under the default language partition.
     */
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

    /**
     * Store bounded WordPress result metadata for filters, snippets, and CLI.
     *
     * Structured fields are preserved in JSON while common filters get indexed
     * scalar columns. The extractor bounds `search_text`; storage normalizes
     * again to keep direct callers predictable.
     *
     * @param array<string,mixed> $metadata
     * @throws JsonException If metadata cannot be JSON encoded.
     */
    public function put_doc_metadata(int $doc_id, array $metadata): void
    {
        $metadata = WP_FTS_StorageCompat::normalize_doc_metadata($metadata);
        $data = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $sql = $this->wpdb->prepare(
            "INSERT INTO {$this->docMetaTable} (doc_id, post_id, post_type, post_status, post_date_gmt, title, excerpt, search_text, data)
VALUES (%d, %d, %s, %s, %s, %s, %s, %s, %s)
ON DUPLICATE KEY UPDATE post_id = VALUES(post_id), post_type = VALUES(post_type), post_status = VALUES(post_status), post_date_gmt = VALUES(post_date_gmt), title = VALUES(title), excerpt = VALUES(excerpt), search_text = VALUES(search_text), data = VALUES(data)",
            $doc_id,
            (int) $metadata['post_id'],
            (string) $metadata['post_type'],
            (string) $metadata['post_status'],
            (string) $metadata['post_date_gmt'],
            (string) $metadata['title'],
            (string) $metadata['excerpt'],
            (string) $metadata['search_text'],
            $data
        );
        $this->wpdb->query($sql);
    }

    /**
     * Fetch metadata for active documents.
     *
     * @param int[] $doc_ids
     * @return array<int,array<string,mixed>>
     */
    public function get_doc_metadata(array $doc_ids): array
    {
        $doc_ids = array_values(array_unique(array_map('intval', $doc_ids)));
        if ($doc_ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($doc_ids), '%d'));
        $sql = $this->wpdb->prepare(
            "SELECT m.doc_id, m.post_id, m.post_type, m.post_status, m.post_date_gmt, m.title, m.excerpt, m.search_text, m.data
FROM {$this->docMetaTable} m
INNER JOIN {$this->docsTable} d ON d.doc_id = m.doc_id
WHERE d.is_deleted = 0 AND m.doc_id IN ({$placeholders})
ORDER BY m.doc_id ASC",
            ...$doc_ids
        );

        $metadata = [];
        foreach ($this->wpdb->get_results($sql) ?: [] as $row) {
            $decoded = [];
            if (isset($row->data) && is_string($row->data) && trim($row->data) !== '') {
                try {
                    $json = json_decode($row->data, true, flags: JSON_THROW_ON_ERROR);
                    if (is_array($json)) {
                        $decoded = $json;
                    }
                } catch (JsonException) {
                    $decoded = [];
                }
            }

            $decoded['post_id'] = (int) ($row->post_id ?? ($decoded['post_id'] ?? 0));
            $decoded['post_type'] = (string) ($row->post_type ?? ($decoded['post_type'] ?? ''));
            $decoded['post_status'] = (string) ($row->post_status ?? ($decoded['post_status'] ?? ''));
            $decoded['post_date_gmt'] = (string) ($row->post_date_gmt ?? ($decoded['post_date_gmt'] ?? ''));
            $decoded['title'] = (string) ($row->title ?? ($decoded['title'] ?? ''));
            $decoded['excerpt'] = (string) ($row->excerpt ?? ($decoded['excerpt'] ?? ''));
            $decoded['search_text'] = (string) ($row->search_text ?? ($decoded['search_text'] ?? ''));
            $metadata[(int) $row->doc_id] = WP_FTS_StorageCompat::normalize_doc_metadata($decoded);
        }
        ksort($metadata, SORT_NUMERIC);

        return $metadata;
    }

    /**
     * Mark a document as deleted without immediately rewriting postings.
     *
     * Unknown ids create tombstones so repeated deletes stay idempotent until
     * `optimize()` compacts tables.
     */
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

    /**
     * Return aggregate or language-specific collection metadata.
     *
     * Aggregate reads sum all language rows by key. Language-specific reads use
     * the canonical language partition.
     *
     * @return array{doc_count:int,len_sum:int}
     */
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

    /**
     * Add signed metadata deltas with zero-clamped storage totals.
     *
     * Supports both `($lang, $d_docs, $d_len)` and legacy `($d_docs, $d_len)`.
     */
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

    /**
     * List all stored term keys in sorted order.
     *
     * @return string[]
     */
    public function all_terms(): array
    {
        $terms = array_map('strval', $this->wpdb->get_col("SELECT term FROM {$this->termsTable} ORDER BY term ASC") ?: []);
        sort($terms, SORT_STRING);

        return $terms;
    }

    /**
     * List document ids, optionally including tombstones.
     *
     * @return int[]
     */
    public function all_doc_ids(bool $include_deleted = false): array
    {
        $where = $include_deleted ? '' : ' WHERE is_deleted = 0';
        $ids = array_map('intval', $this->wpdb->get_col("SELECT doc_id FROM {$this->docsTable}{$where} ORDER BY doc_id ASC") ?: []);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * Start a database transaction.
     */
    public function begin_transaction(): void
    {
        $this->wpdb->query('START TRANSACTION');
    }

    /**
     * Commit the current database transaction.
     */
    public function commit(): void
    {
        $this->wpdb->query('COMMIT');
    }

    /**
     * Roll back the current database transaction.
     */
    public function rollback(): void
    {
        $this->wpdb->query('ROLLBACK');
    }

    /**
     * No-op for MySQL because writes are sent immediately.
     */
    public function flush(): void
    {
    }

    /**
     * Compact tombstoned documents and rebuild collection metadata.
     *
     * Deleted ids are removed from every posting list, empty terms are deleted,
     * document/length tombstones are purged, and metadata is rebuilt from active
     * per-language length rows.
     */
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
                "DELETE FROM {$this->docMetaTable} WHERE doc_id IN ({$placeholders})",
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
     * Run WordPress dbDelta for CREATE statements when available.
     *
     * @param string[] $sql CREATE TABLE statements.
     * @return bool True when dbDelta was loaded and invoked.
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
     * Normalize `put_doc()` overloads into primary language, lengths, and hash.
     *
     * @param int|string $doc_len_or_primary_lang
     * @param string|array<string,int> $hash_or_lang_lengths
     * @return array{0:string,1:array<string,int>,2:string}
     * @throws InvalidArgumentException If the language-aware shape is incomplete.
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
     * Normalize `add_meta()` overloads into language, doc delta, and length delta.
     *
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
