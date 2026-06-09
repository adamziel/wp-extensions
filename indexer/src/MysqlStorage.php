<?php
declare(strict_types=1);

/**
 * WordPress MySQL storage backend for the full-text index.
 *
 * Terms keep document frequency only. Individual `(term, doc_id, tf)` postings
 * live in a separate row table so concurrent writers can update different
 * documents without overwriting whole term blobs. Documents keep a tombstone
 * flag, and per-language lengths live in a separate table so BM25 can score
 * inside one language partition without mixing collection statistics.
 */
final class WP_FTS_Storage_Mysql implements WP_FTS_Row_Postings_Storage, WP_FTS_DocumentMetadataStorage
{
    private object $wpdb;
    private string $termsTable;
    private string $postingsTable;
    private string $docsTable;
    private string $docLengthsTable;
    private string $docMetaTable;
    private string $metaTable;
    private ?bool $sqliteRuntime = null;

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
        $this->postingsTable = $prefix . 'fts_postings';
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
     * statements are executed. `DEFAULT CHARSET=binary` keeps term keys
     * byte-stable.
     */
    public function create_tables(): void
    {
        $charset = 'DEFAULT CHARSET=binary';
        $sql = [
            "CREATE TABLE {$this->termsTable} (
term varbinary(255) NOT NULL,
doc_freq int unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (term)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC {$charset};",
            "CREATE TABLE {$this->postingsTable} (
term varbinary(255) NOT NULL,
doc_id bigint unsigned NOT NULL,
tf int unsigned NOT NULL,
PRIMARY KEY  (term,doc_id),
KEY doc_id (doc_id)
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
            $this->query($statement, 'create FTS tables');
        }
    }

    /**
     * Return existing term rows for the requested keys in the legacy blob shape.
     *
     * MySQL stores postings as rows, but the public storage contract still
     * exposes encoded blobs for compatibility with file, memory, and older
     * callers. Only postings for the requested terms are read.
     *
     * @param string[] $terms Stored term keys.
     * @return array<string,array{df:int,postings:string}> Rows keyed by term.
     */
    public function get_terms(array $terms): array
    {
        $terms = $this->normalize_terms($terms);
        if ($terms === []) {
            return [];
        }

        $docFreqs = $this->get_doc_freqs($terms);
        $postingsByTerm = $this->get_postings($terms);

        $result = [];
        foreach ($terms as $term) {
            if (!isset($postingsByTerm[$term])) {
                continue;
            }

            $result[$term] = [
                'df' => $docFreqs[$term] ?? count($postingsByTerm[$term]),
                'postings' => WP_FTS_PostingsCodec::encode($postingsByTerm[$term]),
            ];
        }

        return $result;
    }

    /**
     * Fetch row postings for requested term keys only.
     *
     * @param string[] $terms Stored term keys.
     * @return array<string,array<int,int>> term => doc_id => weighted tf
     */
    public function get_postings(array $terms): array
    {
        $terms = $this->normalize_terms($terms);
        if ($terms === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($terms), '%s'));
        $sql = $this->wpdb->prepare(
            "SELECT term, doc_id, tf FROM {$this->postingsTable}
WHERE term IN ({$placeholders})
ORDER BY term ASC, doc_id ASC",
            ...$terms
        );
        $rows = $this->get_results($sql, 'read FTS row postings');

        $postingsByTerm = $this->postings_from_rows($rows ?: [], $terms);
        $missing = array_diff_key(array_fill_keys($terms, true), $postingsByTerm);
        if ($missing !== [] && $this->is_sqlite_runtime()) {
            $fallbackRows = $this->get_results(
                "SELECT term, doc_id, tf FROM {$this->postingsTable} ORDER BY term ASC, doc_id ASC",
                'read FTS SQLite fallback row postings'
            );
            foreach ($this->postings_from_rows($fallbackRows, array_keys($missing)) as $term => $postings) {
                $postingsByTerm[$term] = $postings;
            }
        }

        ksort($postingsByTerm, SORT_STRING);

        return $postingsByTerm;
    }

    /**
     * Replace one document's postings with row deletes and row upserts.
     *
     * Document-frequency changes are applied as atomic deltas, so two writers
     * adding different documents for the same term increment the shared term row
     * instead of racing through a whole-blob overwrite.
     *
     * @param array<string,int> $term_frequencies Stored term key => weighted tf.
     */
    public function replace_doc_postings(int $doc_id, array $term_frequencies): void
    {
        $termFrequencies = $this->normalize_term_frequencies($term_frequencies);
        $oldTerms = array_fill_keys($this->terms_for_doc($doc_id), true);
        $newTerms = array_fill_keys(array_keys($termFrequencies), true);

        $this->query($this->wpdb->prepare(
            "DELETE FROM {$this->postingsTable} WHERE doc_id = %d",
            $doc_id
        ), 'delete FTS document postings');

        foreach ($termFrequencies as $term => $tf) {
            $this->insert_posting($term, $doc_id, $tf);
        }

        $deltas = [];
        foreach ($oldTerms as $term => $_) {
            if (!isset($newTerms[$term])) {
                $deltas[$term] = ($deltas[$term] ?? 0) - 1;
            }
        }
        foreach ($newTerms as $term => $_) {
            if (!isset($oldTerms[$term])) {
                $deltas[$term] = ($deltas[$term] ?? 0) + 1;
            }
        }
        $this->adjust_doc_freqs($deltas);
    }

    /**
     * Insert, replace, or remove one term row through the row-postings table.
     *
     * This method preserves the legacy storage contract by decoding the supplied
     * blob into rows. The indexer bypasses it for MySQL through
     * `replace_doc_postings()`, so normal indexing never reads and rewrites a
     * whole MySQL term blob.
     *
     * @throws InvalidArgumentException If the namespaced term exceeds 255 bytes.
     */
    public function put_term(string $term, int $df, string $postings): void
    {
        $this->assert_term_key_fits($term);

        if ($df <= 0) {
            $this->delete_term($term);
            return;
        }

        $decoded = WP_FTS_PostingsCodec::decode($postings);
        $this->query($this->wpdb->prepare(
            "DELETE FROM {$this->postingsTable} WHERE term = %s",
            $term
        ), 'replace FTS term postings');

        foreach ($decoded as $docId => $tf) {
            $this->insert_posting($term, (int) $docId, (int) $tf);
        }
        $this->set_doc_freq($term, count($decoded));
    }

    /**
     * Delete one term row and all of its posting rows by stored key.
     */
    public function delete_term(string $term): void
    {
        $this->query($this->wpdb->prepare(
            "DELETE FROM {$this->postingsTable} WHERE term = %s",
            $term
        ), 'delete FTS term postings');
        $this->query($this->wpdb->prepare(
            "DELETE FROM {$this->termsTable} WHERE term = %s",
            $term
        ), 'delete FTS term');
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
        $rows = $this->get_results($sql, 'read FTS document lengths');

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
        $row = $this->get_row($this->wpdb->prepare(
            "SELECT doc_id, lang, doc_len, content_hash, is_deleted FROM {$this->docsTable} WHERE doc_id = %d",
            $doc_id
        ), 'read FTS document');

        if (!$row) {
            return null;
        }

        $primaryLang = WP_FTS_TermNamespace::canonicalize_lang((string) ($row->lang ?? WP_FTS_TermNamespace::DEFAULT_LANG));
        $langLengths = [];
        $lengthRows = $this->get_results($this->wpdb->prepare(
            "SELECT lang, doc_len FROM {$this->docLengthsTable} WHERE doc_id = %d ORDER BY lang ASC",
            $doc_id
        ), 'read FTS document language lengths');
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
        $this->query($sql, 'write FTS document');

        $this->query($this->wpdb->prepare(
            "DELETE FROM {$this->docLengthsTable} WHERE doc_id = %d",
            $doc_id
        ), 'replace FTS document lengths');
        foreach ($langLengths as $lang => $docLen) {
            $this->query($this->wpdb->prepare(
                "INSERT INTO {$this->docLengthsTable} (doc_id, lang, doc_len)
VALUES (%d, %s, %d)
ON DUPLICATE KEY UPDATE doc_len = VALUES(doc_len)",
                $doc_id,
                $lang,
                $docLen
            ), 'write FTS document length');
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
        $this->query($sql, 'tombstone FTS document');
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
            $rows = $this->get_results("SELECT k, COALESCE(SUM(v), 0) AS v FROM {$this->metaTable} GROUP BY k", 'read FTS aggregate metadata');
        } else {
            $rows = $this->get_results($this->wpdb->prepare(
                "SELECT k, v FROM {$this->metaTable} WHERE lang = %s",
                WP_FTS_TermNamespace::canonicalize_lang($lang)
            ), 'read FTS language metadata');
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
            $this->query($sql, 'write FTS metadata');
        }
    }

    /**
     * List all stored term keys in sorted order.
     *
     * @return string[]
     */
    public function all_terms(): array
    {
        $terms = array_map('strval', $this->get_col("SELECT term FROM {$this->termsTable} ORDER BY term ASC", 'list FTS terms'));
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
        $ids = array_map('intval', $this->get_col("SELECT doc_id FROM {$this->docsTable}{$where} ORDER BY doc_id ASC", 'list FTS documents'));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * Start a database transaction.
     */
    public function begin_transaction(): void
    {
        $this->query('START TRANSACTION', 'start FTS transaction');
    }

    /**
     * Commit the current database transaction.
     */
    public function commit(): void
    {
        $this->query('COMMIT', 'commit FTS transaction');
    }

    /**
     * Roll back the current database transaction.
     */
    public function rollback(): void
    {
        $this->query('ROLLBACK', 'roll back FTS transaction');
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
     * Deleted ids are removed with row deletes from the postings table, term
     * document frequencies are decremented atomically, document/length
     * tombstones are purged, and metadata is rebuilt from active per-language
     * length rows.
     */
    public function optimize(): void
    {
        $deletedIds = array_map('intval', $this->get_col(
            "SELECT doc_id FROM {$this->docsTable} WHERE is_deleted = 1",
            'list FTS tombstones'
        ));
        if ($deletedIds !== []) {
            $placeholders = implode(',', array_fill(0, count($deletedIds), '%d'));
            $termCounts = $this->posting_term_counts_for_docs($deletedIds);
            $this->query($this->wpdb->prepare(
                "DELETE FROM {$this->postingsTable} WHERE doc_id IN ({$placeholders})",
                ...$deletedIds
            ), 'purge FTS tombstone postings');
            $deltas = [];
            foreach ($termCounts as $term => $count) {
                $deltas[$term] = -$count;
            }
            $this->adjust_doc_freqs($deltas);

            $this->query($this->wpdb->prepare(
                "DELETE FROM {$this->docLengthsTable} WHERE doc_id IN ({$placeholders})",
                ...$deletedIds
            ), 'purge FTS tombstone lengths');
            $this->query($this->wpdb->prepare(
                "DELETE FROM {$this->docMetaTable} WHERE doc_id IN ({$placeholders})",
                ...$deletedIds
            ), 'purge FTS tombstone metadata');
            $this->query($this->wpdb->prepare(
                "DELETE FROM {$this->docsTable} WHERE doc_id IN ({$placeholders})",
                ...$deletedIds
            ), 'purge FTS tombstone documents');
        }

        $this->query("DELETE FROM {$this->metaTable}", 'rebuild FTS metadata');
        $rows = $this->get_results(
            "SELECT dl.lang, COUNT(*) AS doc_count, COALESCE(SUM(dl.doc_len), 0) AS len_sum
FROM {$this->docLengthsTable} dl
INNER JOIN {$this->docsTable} d ON d.doc_id = dl.doc_id
WHERE d.is_deleted = 0 AND dl.doc_len > 0
GROUP BY dl.lang",
            'read FTS metadata rebuild rows'
        );
        foreach ($rows ?: [] as $row) {
            $this->add_meta((string) $row->lang, (int) $row->doc_count, (int) $row->len_sum);
        }
    }

    /**
     * Normalize a requested term list without validating length.
     *
     * Read paths accept arbitrary keys so callers can ask for missing or legacy
     * terms without raising storage errors.
     *
     * @param string[] $terms
     * @return string[]
     */
    private function normalize_terms(array $terms): array
    {
        $terms = array_values(array_unique(array_map('strval', $terms)));
        sort($terms, SORT_STRING);

        return $terms;
    }

    /**
     * Normalize and validate term frequencies before row writes.
     *
     * @param array<string,int> $termFrequencies
     * @return array<string,int>
     */
    private function normalize_term_frequencies(array $termFrequencies): array
    {
        $normalized = [];
        foreach ($termFrequencies as $term => $tf) {
            $term = (string) $term;
            $tf = (int) $tf;
            if ($term === '' || $tf <= 0) {
                continue;
            }

            $this->assert_term_key_fits($term);
            $normalized[$term] = max(1, $tf);
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * Reject terms that cannot fit the MySQL `varbinary(255)` key.
     */
    private function assert_term_key_fits(string $term): void
    {
        if (strlen($term) > WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES) {
            throw new InvalidArgumentException('Namespaced term exceeds the MySQL term key byte limit.');
        }
    }

    /**
     * Read term keys currently posted by one document id.
     *
     * @return string[]
     */
    private function terms_for_doc(int $docId): array
    {
        $rows = $this->get_results($this->wpdb->prepare(
            "SELECT term FROM {$this->postingsTable} WHERE doc_id = %d",
            $docId
        ), 'read FTS document posting terms');

        $terms = [];
        foreach ($rows ?: [] as $row) {
            $terms[] = (string) $row->term;
        }
        $terms = array_values(array_unique($terms));
        sort($terms, SORT_STRING);

        return $terms;
    }

    /**
     * Count posting rows by term for a document-id set.
     *
     * @param int[] $docIds
     * @return array<string,int>
     */
    private function posting_term_counts_for_docs(array $docIds): array
    {
        $docIds = array_values(array_unique(array_map('intval', $docIds)));
        if ($docIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($docIds), '%d'));
        $rows = $this->get_results($this->wpdb->prepare(
            "SELECT term, COUNT(*) AS c FROM {$this->postingsTable}
WHERE doc_id IN ({$placeholders})
GROUP BY term",
            ...$docIds
        ), 'count FTS tombstone posting terms');

        $counts = [];
        foreach ($rows ?: [] as $row) {
            $counts[(string) $row->term] = max(0, (int) $row->c);
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * Fetch stored document frequencies for requested terms.
     *
     * @param string[] $terms
     * @return array<string,int>
     */
    private function get_doc_freqs(array $terms): array
    {
        $terms = $this->normalize_terms($terms);
        if ($terms === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($terms), '%s'));
        $rows = $this->get_results($this->wpdb->prepare(
            "SELECT term, doc_freq FROM {$this->termsTable} WHERE term IN ({$placeholders})",
            ...$terms
        ), 'read FTS document frequencies');

        $docFreqs = $this->doc_freqs_from_rows($rows ?: [], $terms);
        $missing = array_diff_key(array_fill_keys($terms, true), $docFreqs);
        if ($missing !== [] && $this->is_sqlite_runtime()) {
            $fallbackRows = $this->get_results(
                "SELECT term, doc_freq FROM {$this->termsTable} ORDER BY term ASC",
                'read FTS SQLite fallback document frequencies'
            );
            foreach ($this->doc_freqs_from_rows($fallbackRows, array_keys($missing)) as $term => $docFreq) {
                $docFreqs[$term] = $docFreq;
            }
        }

        return $docFreqs;
    }

    /**
     * Convert posting rows into a term-keyed map, optionally filtering in PHP.
     *
     * The PHP filter is used only for SQLite-backed Playground runtimes where
     * the MySQL compatibility layer can miss prepared comparisons against term
     * keys containing the namespace separator byte.
     *
     * @param object[] $rows
     * @param string[] $terms
     * @return array<string,array<int,int>>
     */
    private function postings_from_rows(array $rows, array $terms): array
    {
        $lookup = array_fill_keys($terms, true);
        $postingsByTerm = [];
        foreach ($rows as $row) {
            $term = (string) ($row->term ?? '');
            if ($term === '' || ($lookup !== [] && !isset($lookup[$term]))) {
                continue;
            }

            $docId = (int) ($row->doc_id ?? 0);
            if ($docId <= 0) {
                continue;
            }

            $postingsByTerm[$term][$docId] = max(1, (int) ($row->tf ?? 1));
        }

        foreach ($postingsByTerm as &$postings) {
            ksort($postings, SORT_NUMERIC);
        }
        unset($postings);

        return $postingsByTerm;
    }

    /**
     * Convert document-frequency rows into a term-keyed map.
     *
     * @param object[] $rows
     * @param string[] $terms
     * @return array<string,int>
     */
    private function doc_freqs_from_rows(array $rows, array $terms): array
    {
        $lookup = array_fill_keys($terms, true);
        $docFreqs = [];
        foreach ($rows as $row) {
            $term = (string) ($row->term ?? '');
            if ($term === '' || ($lookup !== [] && !isset($lookup[$term]))) {
                continue;
            }

            $docFreqs[$term] = max(0, (int) ($row->doc_freq ?? 0));
        }
        ksort($docFreqs, SORT_STRING);

        return $docFreqs;
    }

    /**
     * Detect WordPress' SQLite integration without issuing SQLite-only SQL.
     */
    private function is_sqlite_runtime(): bool
    {
        if ($this->sqliteRuntime !== null) {
            return $this->sqliteRuntime;
        }

        $signals = [get_class($this->wpdb)];
        if (isset($this->wpdb->dbh) && is_object($this->wpdb->dbh)) {
            $signals[] = get_class($this->wpdb->dbh);
        }

        foreach (['SQLITE_MAIN_FILE', 'SQLITE_PLUGIN', 'SQLITE_DB_DROPIN_VERSION', 'DB_ENGINE'] as $constant) {
            if (defined($constant)) {
                $signals[] = (string) constant($constant);
            }
        }

        foreach ($signals as $signal) {
            if (stripos($signal, 'sqlite') !== false) {
                return $this->sqliteRuntime = true;
            }
        }

        return $this->sqliteRuntime = false;
    }

    /**
     * Insert or update one row posting.
     */
    private function insert_posting(string $term, int $docId, int $tf): void
    {
        $this->assert_term_key_fits($term);
        $sql = $this->wpdb->prepare(
            "INSERT INTO {$this->postingsTable} (term, doc_id, tf)
VALUES (%s, %d, %d)
ON DUPLICATE KEY UPDATE tf = VALUES(tf)",
            $term,
            $docId,
            max(1, $tf)
        );
        $this->query($sql, 'write FTS row posting');
    }

    /**
     * Set one term's document frequency exactly.
     */
    private function set_doc_freq(string $term, int $docFreq): void
    {
        if ($docFreq <= 0) {
            $this->delete_term($term);
            return;
        }

        $this->assert_term_key_fits($term);
        $sql = $this->wpdb->prepare(
            "INSERT INTO {$this->termsTable} (term, doc_freq)
VALUES (%s, %d)
ON DUPLICATE KEY UPDATE doc_freq = VALUES(doc_freq)",
            $term,
            $docFreq
        );
        $this->query($sql, 'write FTS document frequency');
    }

    /**
     * Apply document-frequency deltas without overwriting concurrent writers.
     *
     * Positive deltas use an atomic upsert. Negative deltas subtract from the
     * existing counter and remove rows that reach zero.
     *
     * @param array<string,int> $deltas term => signed document-frequency delta.
     */
    private function adjust_doc_freqs(array $deltas): void
    {
        ksort($deltas, SORT_STRING);
        foreach ($deltas as $term => $delta) {
            $term = (string) $term;
            $delta = (int) $delta;
            if ($term === '' || $delta === 0) {
                continue;
            }

            $this->assert_term_key_fits($term);
            if ($delta > 0) {
                $this->query($this->wpdb->prepare(
                    "INSERT INTO {$this->termsTable} (term, doc_freq)
VALUES (%s, %d)
ON DUPLICATE KEY UPDATE doc_freq = doc_freq + VALUES(doc_freq)",
                    $term,
                    $delta
                ), 'increment FTS document frequency');
                continue;
            }

            $decrement = abs($delta);
            $this->query($this->wpdb->prepare(
                "UPDATE {$this->termsTable}
SET doc_freq = GREATEST(0, doc_freq - %d)
WHERE term = %s",
                $decrement,
                $term
            ), 'decrement FTS document frequency');
            $this->query($this->wpdb->prepare(
                "DELETE FROM {$this->termsTable} WHERE term = %s AND doc_freq = 0",
                $term
            ), 'delete empty FTS document frequency');
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
            $this->assert_no_database_error('run dbDelta for FTS tables');
        }

        return true;
    }

    /**
     * Run a write statement and throw when WordPress reports a database error.
     */
    private function query(mixed $statement, string $context): void
    {
        $result = $this->wpdb->query($statement);
        if ($result === false) {
            throw $this->database_exception($context);
        }

        $this->assert_no_database_error($context);
    }

    /**
     * Run a result query with explicit database error visibility.
     *
     * @return object[]
     */
    private function get_results(mixed $statement, string $context): array
    {
        $rows = $this->wpdb->get_results($statement);
        $this->assert_no_database_error($context);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Run a single-row query with explicit database error visibility.
     */
    private function get_row(mixed $statement, string $context): ?object
    {
        $row = $this->wpdb->get_row($statement);
        $this->assert_no_database_error($context);

        return is_object($row) ? $row : null;
    }

    /**
     * Run a column query with explicit database error visibility.
     *
     * @return array<int,mixed>
     */
    private function get_col(string $sql, string $context): array
    {
        $values = $this->wpdb->get_col($sql);
        $this->assert_no_database_error($context);

        return is_array($values) ? $values : [];
    }

    /**
     * Throw when `$wpdb->last_error` contains a failed operation detail.
     */
    private function assert_no_database_error(string $context): void
    {
        if (isset($this->wpdb->last_error) && trim((string) $this->wpdb->last_error) !== '') {
            throw $this->database_exception($context);
        }
    }

    /**
     * Build a context-rich exception for failed MySQL operations.
     */
    private function database_exception(string $context): RuntimeException
    {
        $error = isset($this->wpdb->last_error) ? trim((string) $this->wpdb->last_error) : '';
        $suffix = $error !== '' ? ": {$error}" : '.';

        return new RuntimeException("Failed to {$context}{$suffix}");
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
