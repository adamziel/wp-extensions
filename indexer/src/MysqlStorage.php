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
final class WP_FTS_Storage_Mysql implements WP_FTS_Row_Postings_Storage, WP_FTS_Capped_Postings_Storage, WP_FTS_DocumentMetadataStorage, WP_FTS_DocumentMetadataFilterStorage, WP_FTS_Document_Terms_Storage, WP_FTS_Prefix_Term_Storage, WP_FTS_Resettable_Storage
{
    /**
     * The largest statement repeats each maximum-length term key once for a
     * CASE expression and once for its IN predicate: at most 51 KiB of raw keys
     * and 300 placeholders. This stays well below normal MySQL packet limits
     * without returning to one round trip per term.
     */
    private const WRITE_CHUNK_ROWS = 100;

    private object $wpdb;
    private string $termsTable;
    private string $postingsTable;
    private string $docsTable;
    private string $docLengthsTable;
    private string $docMetaTable;
    private string $metaTable;
    private string $queueTable;
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
        $this->queueTable = $prefix . 'fts_queue';
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
            "CREATE TABLE {$this->queueTable} (
post_id bigint unsigned NOT NULL,
generation bigint unsigned NOT NULL DEFAULT 1,
available_at bigint unsigned NOT NULL DEFAULT 0,
attempts int unsigned NOT NULL DEFAULT 0,
claim_token varchar(64) NOT NULL DEFAULT '',
claimed_generation bigint unsigned NOT NULL DEFAULT 0,
claim_expires_at bigint unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (post_id),
KEY ready (available_at,claim_expires_at,post_id)
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
     * Inspect the physical table, column, and index contract.
     *
     * The schema version option is only a migration cursor; callers must use
     * this result before treating the index as usable or persisting a version.
     *
     * @return array{valid:bool,available:bool,missing_tables:string[],missing_columns:string[],missing_indexes:string[]}
     */
    public function verify_schema(): array
    {
        $missingTables = [];
        $missingColumns = [];
        $missingIndexes = [];

        foreach ($this->schema_contract() as $table => $contract) {
            $physical = $this->is_sqlite_runtime()
                ? $this->inspect_sqlite_schema($table)
                : $this->inspect_mysql_schema($table);
            if (!$physical['exists']) {
                $missingTables[] = $table;
                continue;
            }

            foreach ($contract['columns'] as $column) {
                if (!in_array($column, $physical['columns'], true)) {
                    $missingColumns[] = $table . '.' . $column;
                }
            }
            foreach ($contract['indexes'] as $index) {
                if (!$this->schema_has_index($physical['indexes'], $index)) {
                    $missingIndexes[] = $table . '(' . implode(',', $index['columns']) . ')';
                }
            }
        }

        sort($missingTables, SORT_STRING);
        sort($missingColumns, SORT_STRING);
        sort($missingIndexes, SORT_STRING);

        return [
            'valid' => $missingTables === [] && $missingColumns === [] && $missingIndexes === [],
            'available' => true,
            'missing_tables' => $missingTables,
            'missing_columns' => $missingColumns,
            'missing_indexes' => $missingIndexes,
        ];
    }

    /**
     * @return array<string,array{columns:string[],indexes:array<int,array{columns:string[],unique:bool}>}>
     */
    private function schema_contract(): array
    {
        return [
            $this->termsTable => [
                'columns' => ['term', 'doc_freq'],
                'indexes' => [
                    ['columns' => ['term'], 'unique' => true],
                ],
            ],
            $this->postingsTable => [
                'columns' => ['term', 'doc_id', 'tf'],
                'indexes' => [
                    ['columns' => ['term', 'doc_id'], 'unique' => true],
                    ['columns' => ['doc_id'], 'unique' => false],
                ],
            ],
            $this->docsTable => [
                'columns' => ['doc_id', 'lang', 'doc_len', 'content_hash', 'is_deleted'],
                'indexes' => [
                    ['columns' => ['doc_id'], 'unique' => true],
                    ['columns' => ['lang'], 'unique' => false],
                    ['columns' => ['is_deleted'], 'unique' => false],
                ],
            ],
            $this->docLengthsTable => [
                'columns' => ['doc_id', 'lang', 'doc_len'],
                'indexes' => [
                    ['columns' => ['doc_id', 'lang'], 'unique' => true],
                    ['columns' => ['lang'], 'unique' => false],
                ],
            ],
            $this->docMetaTable => [
                'columns' => ['doc_id', 'post_id', 'post_type', 'post_status', 'post_date_gmt', 'title', 'excerpt', 'search_text', 'data'],
                'indexes' => [
                    ['columns' => ['doc_id'], 'unique' => true],
                    ['columns' => ['post_id'], 'unique' => false],
                    ['columns' => ['post_type', 'post_status', 'post_date_gmt'], 'unique' => false],
                ],
            ],
            $this->metaTable => [
                'columns' => ['lang', 'k', 'v'],
                'indexes' => [
                    ['columns' => ['lang', 'k'], 'unique' => true],
                ],
            ],
            $this->queueTable => [
                'columns' => ['post_id', 'generation', 'available_at', 'attempts', 'claim_token', 'claimed_generation', 'claim_expires_at'],
                'indexes' => [
                    ['columns' => ['post_id'], 'unique' => true],
                    ['columns' => ['available_at', 'claim_expires_at', 'post_id'], 'unique' => false],
                ],
            ],
        ];
    }

    /**
     * @return array{exists:bool,columns:string[],indexes:array<int,array{columns:string[],unique:bool}>}
     */
    private function inspect_mysql_schema(string $table): array
    {
        $identifier = $this->schema_identifier($table);
        if ($identifier === null) {
            return ['exists' => false, 'columns' => [], 'indexes' => []];
        }

        $columnRows = $this->schema_rows("SHOW COLUMNS FROM {$identifier}");
        if ($columnRows === []) {
            return ['exists' => false, 'columns' => [], 'indexes' => []];
        }

        $columns = [];
        foreach ($columnRows as $row) {
            $column = $this->schema_row_value($row, ['Field', 'field']);
            if ($column !== '') {
                $columns[$column] = true;
            }
        }

        $byName = [];
        foreach ($this->schema_rows("SHOW INDEX FROM {$identifier}") as $row) {
            $name = $this->schema_row_value($row, ['Key_name', 'key_name']);
            $column = $this->schema_row_value($row, ['Column_name', 'column_name']);
            $position = (int) $this->schema_row_value($row, ['Seq_in_index', 'seq_in_index']);
            if ($name !== '' && $column !== '') {
                $byName[$name]['columns'][max(1, $position)] = $column;
                $byName[$name]['unique'] = (int) $this->schema_row_value($row, ['Non_unique', 'non_unique']) === 0;
            }
        }

        return [
            'exists' => $columns !== [],
            'columns' => array_keys($columns),
            'indexes' => $this->ordered_schema_indexes($byName),
        ];
    }

    /**
     * @return array{exists:bool,columns:string[],indexes:array<int,array{columns:string[],unique:bool}>}
     */
    private function inspect_sqlite_schema(string $table): array
    {
        $identifier = $this->sqlite_schema_identifier($table);
        $columnRows = $this->schema_rows("PRAGMA table_info({$identifier})");
        if ($columnRows === []) {
            return ['exists' => false, 'columns' => [], 'indexes' => []];
        }

        $columns = [];
        $primary = [];
        foreach ($columnRows as $row) {
            $column = $this->schema_row_value($row, ['name']);
            $position = (int) $this->schema_row_value($row, ['pk']);
            if ($column !== '') {
                $columns[$column] = true;
                if ($position > 0) {
                    $primary[$position] = $column;
                }
            }
        }

        $indexes = [];
        if ($primary !== []) {
            ksort($primary, SORT_NUMERIC);
            $indexes[] = ['columns' => array_values($primary), 'unique' => true];
        }
        foreach ($this->schema_rows("PRAGMA index_list({$identifier})") as $row) {
            $name = $this->schema_row_value($row, ['name']);
            if ($name === '') {
                continue;
            }

            $indexColumns = [];
            foreach ($this->schema_rows('PRAGMA index_info(' . $this->sqlite_schema_identifier($name) . ')') as $indexRow) {
                $column = $this->schema_row_value($indexRow, ['name']);
                $position = (int) $this->schema_row_value($indexRow, ['seqno']);
                if ($column !== '') {
                    $indexColumns[$position] = $column;
                }
            }
            if ($indexColumns !== []) {
                ksort($indexColumns, SORT_NUMERIC);
                $indexes[] = [
                    'columns' => array_values($indexColumns),
                    'unique' => (int) $this->schema_row_value($row, ['unique']) === 1,
                ];
            }
        }

        return [
            'exists' => $columns !== [],
            'columns' => array_keys($columns),
            'indexes' => $indexes,
        ];
    }

    /**
     * @param array<string,array{columns:array<int,string>,unique:bool}> $byName
     * @return array<int,array{columns:string[],unique:bool}>
     */
    private function ordered_schema_indexes(array $byName): array
    {
        $indexes = [];
        foreach ($byName as $index) {
            $columns = $index['columns'];
            ksort($columns, SORT_NUMERIC);
            $indexes[] = [
                'columns' => array_values($columns),
                'unique' => $index['unique'],
            ];
        }

        return $indexes;
    }

    /**
     * @param array<int,array{columns:string[],unique:bool}> $indexes
     * @param array{columns:string[],unique:bool} $expected
     */
    private function schema_has_index(array $indexes, array $expected): bool
    {
        foreach ($indexes as $index) {
            $columnsMatch = $expected['unique']
                ? $index['columns'] === $expected['columns']
                : array_slice($index['columns'], 0, count($expected['columns'])) === $expected['columns'];
            if ($columnsMatch && $index['unique'] === $expected['unique']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return object[]
     */
    private function schema_rows(string $sql): array
    {
        if (!method_exists($this->wpdb, 'get_results')) {
            return [];
        }

        $rows = $this->wpdb->get_results($sql);

        return is_array($rows) ? array_values(array_filter($rows, static fn(mixed $row): bool => is_object($row) || is_array($row))) : [];
    }

    /**
     * @param string[] $keys
     */
    private function schema_row_value(object|array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);
            if (is_scalar($value)) {
                return (string) $value;
            }
        }

        return '';
    }

    private function schema_identifier(string $identifier): ?string
    {
        return preg_match('/^[A-Za-z0-9_]+$/D', $identifier) === 1 ? '`' . $identifier . '`' : null;
    }

    private function sqlite_schema_identifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
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

        $termValues = $this->term_query_values($terms);
        $termSql = implode(',', $termValues['values']);
        $sql = "SELECT term, doc_id, tf FROM {$this->postingsTable}
WHERE term IN ({$termSql})
ORDER BY term ASC, doc_id ASC";
        if ($termValues['args'] !== []) {
            $sql = $this->wpdb->prepare($sql, ...$termValues['args']);
        }
        $rows = $this->get_results($sql, 'read FTS row postings');

        $postingsByTerm = $this->postings_from_rows($rows ?: [], $terms);
        ksort($postingsByTerm, SORT_STRING);

        return $postingsByTerm;
    }

    /**
     * Fetch a deterministic document-id prefix for each requested term.
     *
     * This powers explicit approximate retrieval without materializing broad
     * posting lists. One bounded query per term keeps SQL portable across MySQL
     * variants and the SQLite-backed Playground runtime.
     *
     * @param string[] $terms Stored term keys.
     * @return array<string,array<int,int>> term => doc_id => weighted tf
     */
    public function get_capped_postings(array $terms, int $candidate_cap): array
    {
        $terms = $this->normalize_terms($terms);
        if ($terms === []) {
            return [];
        }

        $candidate_cap = max(1, (int) $candidate_cap);
        $postingsByTerm = [];
        foreach ($terms as $term) {
            $termValue = $this->term_query_values([$term]);
            $queryArgs = [...$termValue['args'], $candidate_cap];
            $rows = $this->get_results($this->wpdb->prepare(
                "SELECT term, doc_id, tf FROM {$this->postingsTable}
WHERE term = {$termValue['values'][0]}
ORDER BY doc_id ASC
LIMIT %d",
                ...$queryArgs
            ), 'read capped FTS row postings');
            foreach ($this->postings_from_rows($rows ?: [], [$term]) as $rowTerm => $postings) {
                $postingsByTerm[$rowTerm] = array_slice($postings, 0, $candidate_cap, true);
            }
        }

        ksort($postingsByTerm, SORT_STRING);

        return $postingsByTerm;
    }

    /**
     * Return stored term keys with the supplied namespaced prefix.
     *
     * The normal path uses an indexed binary range over the term primary key.
     * Prefixes with no byte successor use a bounded lower-bound query followed
     * by PHP prefix filtering, still avoiding an unbounded term-table scan.
     *
     * @return string[]
     */
    public function terms_with_prefix(string $prefix, int $limit): array
    {
        $limit = max(1, (int) $limit);
        if ($prefix === '' || strlen($prefix) > WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES) {
            return [];
        }

        $upperBound = $this->binary_successor($prefix);
        if ($upperBound !== null) {
            $termValues = $this->term_query_values([$prefix, $upperBound]);
            [$lowerValue, $upperValue] = $termValues['values'];
            $queryArgs = [...$termValues['args'], $limit];
            $rows = $this->get_results($this->wpdb->prepare(
                "SELECT term FROM {$this->termsTable}
WHERE term >= {$lowerValue} AND term < {$upperValue}
ORDER BY term ASC
LIMIT %d",
                ...$queryArgs
            ), 'read FTS prefix terms');

            return $this->prefix_terms_from_rows($rows ?: [], $prefix, $limit);
        }

        $termValue = $this->term_query_values([$prefix]);
        $queryArgs = [...$termValue['args'], $limit];
        $rows = $this->get_results($this->wpdb->prepare(
            "SELECT term FROM {$this->termsTable}
WHERE term >= {$termValue['values'][0]}
ORDER BY term ASC
LIMIT %d",
            ...$queryArgs
        ), 'read FTS lower-bound prefix terms');

        return $this->prefix_terms_from_rows($rows ?: [], $prefix, $limit);
    }

    /**
     * Replace one document's postings with one delete and chunked row upserts.
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

        $this->insert_document_postings($doc_id, $termFrequencies);

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

        $this->insert_term_postings($term, $decoded);
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
     * @throws RuntimeException If the database rejects the metadata write.
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
        $this->query($sql, 'write FTS document metadata');
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
        foreach ($this->get_results($sql, 'read FTS document metadata') as $row) {
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
     * Return active candidate ids whose indexed scalar metadata matches filters.
     *
     * @param int[] $doc_ids
     * @param string[] $post_types
     * @param string[] $post_statuses
     * @return int[]
     */
    public function filter_doc_ids_by_metadata(
        array $doc_ids,
        array $post_types = [],
        array $post_statuses = [],
        ?string $date_after = null,
        ?string $date_before = null
    ): array {
        $doc_ids = array_values(array_unique(array_filter(array_map('intval', $doc_ids), static fn(int $id): bool => $id >= 0)));
        if ($doc_ids === []) {
            return [];
        }

        $where = ['d.is_deleted = 0'];
        $args = [];

        $docPlaceholders = implode(',', array_fill(0, count($doc_ids), '%d'));
        $where[] = "m.doc_id IN ({$docPlaceholders})";
        array_push($args, ...$doc_ids);

        $post_types = WP_FTS_StorageCompat::normalize_metadata_filter_values($post_types);
        if ($post_types !== []) {
            $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
            $where[] = "m.post_type IN ({$placeholders})";
            array_push($args, ...$post_types);
        }

        $post_statuses = WP_FTS_StorageCompat::normalize_metadata_filter_values($post_statuses);
        if ($post_statuses !== []) {
            $placeholders = implode(',', array_fill(0, count($post_statuses), '%s'));
            $where[] = "m.post_status IN ({$placeholders})";
            array_push($args, ...$post_statuses);
        }

        $date_after = WP_FTS_StorageCompat::normalize_metadata_filter_date($date_after, false);
        $date_before = WP_FTS_StorageCompat::normalize_metadata_filter_date($date_before, true);
        if ($date_after !== null || $date_before !== null) {
            $where[] = "m.post_date_gmt <> ''";
        }
        if ($date_after !== null) {
            $where[] = 'm.post_date_gmt >= %s';
            $args[] = $date_after;
        }
        if ($date_before !== null) {
            $where[] = 'm.post_date_gmt <= %s';
            $args[] = $date_before;
        }

        $sql = $this->wpdb->prepare(
            "SELECT m.doc_id
FROM {$this->docMetaTable} m
INNER JOIN {$this->docsTable} d ON d.doc_id = m.doc_id
WHERE " . implode(' AND ', $where) . '
ORDER BY m.doc_id ASC',
            ...$args
        );

        $ids = [];
        foreach ($this->get_results($sql, 'filter FTS document metadata') as $row) {
            $ids[] = (int) ($row->doc_id ?? 0);
        }
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id >= 0)));
        sort($ids, SORT_NUMERIC);

        return $ids;
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
     * Clear all FTS data rows without dropping or recreating tables.
     *
     * @return array<string,int>
     */
    public function reset_index(): array
    {
        $this->begin_transaction();
        try {
            $counts = [
                'postings_deleted' => $this->delete_all_from_table($this->postingsTable, 'clear FTS postings'),
                'terms_deleted' => $this->delete_all_from_table($this->termsTable, 'clear FTS terms'),
                'doc_lengths_deleted' => $this->delete_all_from_table($this->docLengthsTable, 'clear FTS document lengths'),
                'doc_metadata_deleted' => $this->delete_all_from_table($this->docMetaTable, 'clear FTS document metadata'),
                'docs_deleted' => $this->delete_all_from_table($this->docsTable, 'clear FTS documents'),
                'collection_metadata_deleted' => $this->delete_all_from_table($this->metaTable, 'clear FTS collection metadata'),
            ];
            $this->commit();
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }

        return $counts;
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
    public function terms_for_doc(int $doc_id): array
    {
        $rows = $this->get_results($this->wpdb->prepare(
            "SELECT term FROM {$this->postingsTable} WHERE doc_id = %d",
            $doc_id
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

        $termValues = $this->term_query_values($terms);
        $termSql = implode(',', $termValues['values']);
        $sql = "SELECT term, doc_freq FROM {$this->termsTable} WHERE term IN ({$termSql})";
        if ($termValues['args'] !== []) {
            $sql = $this->wpdb->prepare($sql, ...$termValues['args']);
        }
        $rows = $this->get_results($sql, 'read FTS document frequencies');

        return $this->doc_freqs_from_rows($rows ?: [], $terms);
    }

    /**
     * Return SQL values and prepared arguments for binary term keys.
     *
     * WordPress' SQLite integration stores MySQL `VARBINARY` values as BLOBs,
     * while a normal `%s` comparison is a TEXT value. MySQL `X'...'` literals
     * remain SQLite BLOB literals and keep the term primary key usable
     * for exact and range lookups. Hex encoding makes the SQL interpolation
     * data-only. Native MySQL keeps the existing prepared `%s` query shape.
     *
     * @param string[] $terms
     * @return array{values:string[],args:string[]}
     */
    private function term_query_values(array $terms): array
    {
        if (!$this->is_sqlite_runtime()) {
            return [
                'values' => array_fill(0, count($terms), '%s'),
                'args' => $terms,
            ];
        }

        $literals = [];
        foreach ($terms as $term) {
            $literals[] = "X'" . bin2hex($term) . "'";
        }

        return [
            'values' => $literals,
            'args' => [],
        ];
    }

    /**
     * Convert posting rows into a term-keyed map for the requested term keys.
     *
     * Keep the requested-key check even though SQL is bounded to those keys so
     * a database adapter cannot introduce an unrelated posting row.
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
     * @param object[] $rows
     * @return string[]
     */
    private function prefix_terms_from_rows(array $rows, string $prefix, int $limit): array
    {
        $limit = max(1, (int) $limit);
        $terms = [];
        foreach ($rows as $row) {
            $term = (string) ($row->term ?? '');
            if ($term === '' || !str_starts_with($term, $prefix)) {
                continue;
            }

            $terms[] = $term;
            if (count($terms) >= $limit) {
                break;
            }
        }
        $terms = array_values(array_unique($terms));
        sort($terms, SORT_STRING);

        return array_slice($terms, 0, $limit);
    }

    /**
     * Return the exclusive upper bound for all strings with a binary prefix.
     */
    private function binary_successor(string $value): ?string
    {
        for ($i = strlen($value) - 1; $i >= 0; $i--) {
            $byte = ord($value[$i]);
            if ($byte < 0xFF) {
                return substr($value, 0, $i) . chr($byte + 1);
            }
        }

        return null;
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
     * Insert one document's term frequencies without copying the entire input.
     *
     * @param array<string,int> $termFrequencies
     */
    private function insert_document_postings(int $docId, array $termFrequencies): void
    {
        $chunk = [];
        foreach ($termFrequencies as $term => $tf) {
            $chunk[] = [(string) $term, $docId, (int) $tf];
            if (count($chunk) === self::WRITE_CHUNK_ROWS) {
                $this->insert_posting_chunk($chunk);
                $chunk = [];
            }
        }
        $this->insert_posting_chunk($chunk);
    }

    /**
     * Insert one compatibility term blob's decoded posting rows without copying
     * the entire decoded map.
     *
     * @param array<int,int> $postings
     */
    private function insert_term_postings(string $term, array $postings): void
    {
        $chunk = [];
        foreach ($postings as $docId => $tf) {
            $chunk[] = [$term, (int) $docId, (int) $tf];
            if (count($chunk) === self::WRITE_CHUNK_ROWS) {
                $this->insert_posting_chunk($chunk);
                $chunk = [];
            }
        }
        $this->insert_posting_chunk($chunk);
    }

    /**
     * Insert or update one packet-bounded posting chunk.
     *
     * The SQLite compatibility contract covers the existing one-row MySQL
     * upsert, so that runtime retains it instead of requiring new translation
     * support for the multi-row form.
     *
     * @param array<int,array{0:string,1:int,2:int}> $rows
     */
    private function insert_posting_chunk(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        if ($this->is_sqlite_runtime()) {
            foreach ($rows as [$term, $docId, $tf]) {
                $this->insert_posting($term, $docId, $tf);
            }
            return;
        }

        $values = [];
        $args = [];
        foreach ($rows as [$term, $docId, $tf]) {
            $this->assert_term_key_fits($term);
            $values[] = '(%s, %d, %d)';
            array_push($args, $term, $docId, max(1, $tf));
        }

        $this->query($this->wpdb->prepare(
            "INSERT INTO {$this->postingsTable} (term, doc_id, tf)
VALUES " . implode(",\n", $values) . "
ON DUPLICATE KEY UPDATE tf = VALUES(tf)",
            ...$args
        ), 'write FTS row postings');
    }

    /**
     * Insert the one-row upsert understood by WordPress' SQLite integration.
     */
    private function insert_posting(string $term, int $docId, int $tf): void
    {
        $this->assert_term_key_fits($term);
        $this->query($this->wpdb->prepare(
            "INSERT INTO {$this->postingsTable} (term, doc_id, tf)
VALUES (%s, %d, %d)
ON DUPLICATE KEY UPDATE tf = VALUES(tf)",
            $term,
            $docId,
            max(1, $tf)
        ), 'write FTS row posting');
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
        $normalized = [];
        foreach ($deltas as $term => $delta) {
            $term = (string) $term;
            $delta = (int) $delta;
            if ($term === '' || $delta === 0) {
                continue;
            }

            $this->assert_term_key_fits($term);
            $normalized[$term] = $delta;
        }
        if ($normalized === []) {
            return;
        }

        if ($this->is_sqlite_runtime()) {
            $this->adjust_doc_freqs_individually($normalized);
            return;
        }

        $increments = [];
        $decrements = [];
        foreach ($normalized as $term => $delta) {
            if ($delta > 0) {
                $increments[$term] = $delta;
            } else {
                $decrements[$term] = abs($delta);
            }
        }

        $this->increment_doc_freqs($increments);
        $this->decrement_doc_freqs($decrements);
    }

    /**
     * Preserve the one-term statements translated by WordPress' SQLite layer.
     *
     * @param array<string,int> $deltas
     */
    private function adjust_doc_freqs_individually(array $deltas): void
    {
        foreach ($deltas as $term => $delta) {
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
     * Apply positive document-frequency deltas with one upsert per chunk.
     *
     * @param array<string,int> $increments
     */
    private function increment_doc_freqs(array $increments): void
    {
        foreach (array_chunk($increments, self::WRITE_CHUNK_ROWS, true) as $chunk) {
            $values = [];
            $args = [];
            foreach ($chunk as $term => $increment) {
                $values[] = '(%s, %d)';
                array_push($args, $term, $increment);
            }

            $this->query($this->wpdb->prepare(
                "INSERT INTO {$this->termsTable} (term, doc_freq)
VALUES " . implode(",\n", $values) . "
ON DUPLICATE KEY UPDATE doc_freq = doc_freq + VALUES(doc_freq)",
                ...$args
            ), 'increment FTS document frequencies');
        }
    }

    /**
     * Apply negative document-frequency deltas with one update and cleanup per
     * chunk. Repeating term arguments keeps both CASE and IN comparisons fully
     * prepared while staying under the chunk's 300-placeholder bound.
     *
     * @param array<string,int> $decrements
     */
    private function decrement_doc_freqs(array $decrements): void
    {
        foreach (array_chunk($decrements, self::WRITE_CHUNK_ROWS, true) as $chunk) {
            $cases = [];
            $args = [];
            foreach ($chunk as $term => $decrement) {
                $cases[] = 'WHEN %s THEN %d';
                array_push($args, $term, $decrement);
            }

            $terms = array_keys($chunk);
            $placeholders = implode(',', array_fill(0, count($terms), '%s'));
            array_push($args, ...$terms);
            $this->query($this->wpdb->prepare(
                "UPDATE {$this->termsTable}
SET doc_freq = GREATEST(0, CAST(doc_freq AS SIGNED) - CASE term
    " . implode("\n    ", $cases) . "
    ELSE 0
END)
WHERE term IN ({$placeholders})",
                ...$args
            ), 'decrement FTS document frequencies');
            $this->query($this->wpdb->prepare(
                "DELETE FROM {$this->termsTable}
WHERE term IN ({$placeholders}) AND doc_freq = 0",
                ...$terms
            ), 'delete empty FTS document frequencies');
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
     * Delete every row from one plugin-owned FTS table and return affected rows.
     */
    private function delete_all_from_table(string $table, string $context): int
    {
        $result = $this->wpdb->query("DELETE FROM {$table}");
        if ($result === false) {
            throw $this->database_exception($context);
        }

        $this->assert_no_database_error($context);

        return is_int($result) ? max(0, $result) : 0;
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
