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
final class WP_FTS_Storage_Mysql implements WP_FTS_Row_Postings_Storage
{
    private object $wpdb;
    private string $termsTable;
    private string $postingsTable;
    private string $docsTable;
    private string $docLengthsTable;
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
        $this->postingsTable = $prefix . 'fts_postings';
        $this->docsTable = $prefix . 'fts_docs';
        $this->docLengthsTable = $prefix . 'fts_doc_lengths';
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
        $rows = $this->wpdb->get_results($sql);

        $postingsByTerm = [];
        foreach ($rows ?: [] as $row) {
            $term = (string) $row->term;
            $docId = (int) $row->doc_id;
            $tf = max(1, (int) $row->tf);
            $postingsByTerm[$term][$docId] = $tf;
        }
        foreach ($postingsByTerm as &$postings) {
            ksort($postings, SORT_NUMERIC);
        }
        unset($postings);
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

        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->postingsTable} WHERE doc_id = %d",
            $doc_id
        ));

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
        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->postingsTable} WHERE term = %s",
            $term
        ));

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
        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->postingsTable} WHERE term = %s",
            $term
        ));
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
     * Deleted ids are removed with row deletes from the postings table, term
     * document frequencies are decremented atomically, document/length
     * tombstones are purged, and metadata is rebuilt from active per-language
     * length rows.
     */
    public function optimize(): void
    {
        $deletedIds = array_map('intval', $this->wpdb->get_col(
            "SELECT doc_id FROM {$this->docsTable} WHERE is_deleted = 1"
        ) ?: []);
        if ($deletedIds !== []) {
            $placeholders = implode(',', array_fill(0, count($deletedIds), '%d'));
            $termCounts = $this->posting_term_counts_for_docs($deletedIds);
            $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$this->postingsTable} WHERE doc_id IN ({$placeholders})",
                ...$deletedIds
            ));
            $deltas = [];
            foreach ($termCounts as $term => $count) {
                $deltas[$term] = -$count;
            }
            $this->adjust_doc_freqs($deltas);

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
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT term FROM {$this->postingsTable} WHERE doc_id = %d",
            $docId
        ));

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
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT term, COUNT(*) AS c FROM {$this->postingsTable}
WHERE doc_id IN ({$placeholders})
GROUP BY term",
            ...$docIds
        ));

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
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT term, doc_freq FROM {$this->termsTable} WHERE term IN ({$placeholders})",
            ...$terms
        ));

        $docFreqs = [];
        foreach ($rows ?: [] as $row) {
            $docFreqs[(string) $row->term] = max(0, (int) $row->doc_freq);
        }

        return $docFreqs;
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
        $this->wpdb->query($sql);
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
        $this->wpdb->query($sql);
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
                $this->wpdb->query($this->wpdb->prepare(
                    "INSERT INTO {$this->termsTable} (term, doc_freq)
VALUES (%s, %d)
ON DUPLICATE KEY UPDATE doc_freq = doc_freq + VALUES(doc_freq)",
                    $term,
                    $delta
                ));
                continue;
            }

            $decrement = abs($delta);
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->termsTable}
SET doc_freq = GREATEST(0, doc_freq - %d)
WHERE term = %s",
                $decrement,
                $term
            ));
            $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$this->termsTable} WHERE term = %s AND doc_freq = 0",
                $term
            ));
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
