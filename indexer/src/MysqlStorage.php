<?php
declare(strict_types=1);

/**
 * A prepared document that can never satisfy the relational writer contract.
 *
 * The post id and stable reason code let a queue owner fail only the poison
 * generation instead of immediately retrying an opaque whole-batch failure.
 */
final class WP_FTS_Prepared_Document_Rejected extends InvalidArgumentException
{
    /** Carry the poison document identity separately from its diagnostic text. */
    public function __construct(
        public readonly int $post_id,
        public readonly string $reason_code,
        string $message
    ) {
        parent::__construct($message);
    }
}

/** A valid prepared batch that must be retried as two smaller claim batches. */
final class WP_FTS_Prepared_Batch_Split_Required extends InvalidArgumentException
{
    /** Describe the deterministic split point that keeps both halves bounded. */
    public function __construct(
        public readonly int $document_count,
        public readonly int $posting_count,
        public readonly int $split_after_documents,
        public readonly int $posting_limit,
        public readonly int $term_count = 0,
        public readonly int $term_limit = 0,
        public readonly string $limit_kind = 'postings'
    ) {
        $detail = match ($limit_kind) {
            'terms' => "{$term_count} distinct terms",
            'sqlite_transport' => 'SQLite dictionary transport above 4 MiB',
            default => "{$posting_count} postings",
        };
        $limit = match ($limit_kind) {
            'terms' => "{$term_limit} distinct terms",
            'sqlite_transport' => 'one dictionary write and one identity read per transaction',
            default => "{$posting_limit} postings",
        };
        parent::__construct(
            "Prepared FTS batch has {$detail} across {$document_count} documents; "
            . "split after {$split_after_documents} documents to keep each writer transaction at or below {$limit}."
        );
    }
}

/**
 * One measured, complete prefix of a relational posting replacement.
 *
 * Counts are carried from the post-first preflight into the transaction so a
 * normal worker never repeats the old-posting scan after it defers the suffix.
 */
final class WP_FTS_Prepared_Replacement_Plan
{
    /**
     * @param array<int,int> $new_posting_counts
     * @param array<int,int> $old_posting_counts
     * @param int[] $admitted_post_ids
     * @param int[] $deferred_post_ids
     */
    public function __construct(
        public readonly array $new_posting_counts,
        public readonly array $old_posting_counts,
        public readonly array $admitted_post_ids,
        public readonly array $deferred_post_ids,
        public readonly int $scanned_old_postings,
        public readonly int $posting_mutations
    ) {
    }
}

/**
 * Four-table relational inverted index for WordPress MySQL/MariaDB.
 *
 * The request path never returns complete posting lists to PHP. Query analysis
 * is resolved with one dictionary read, a second set-oriented statement
 * performs candidate discovery, visibility filtering, and ranking, and an
 * optional third statement hydrates only the returned page.
 * Bounded primitive methods remain as compatibility adapters for diagnostics
 * and one-document callers. Collection-wide and posting-list reads fail closed
 * because production search must use `search_page()`.
 */
final class WP_FTS_Storage_Mysql implements WP_FTS_Set_Oriented_Search_Storage, WP_FTS_DocumentMetadataStorage, WP_FTS_Resettable_Storage
{
    private const LEXICAL_KIND = 0;
    private const SURFACE_KIND = 1;
    private const MAX_WRITE_SQL_BYTES = 4194304;
    private const WRITE_CHUNK_TARGET_BYTES = 3145728;
    // The MySQL/MariaDB ASCII-base64 path keeps all 8,192 maximum-width
    // identities below the 4 MiB contract without passing binary text through
    // wpdb. SQLite has a separately preflighted hexadecimal transport prefix.
    private const MAX_TERM_RESOLUTION_IDENTITIES = 8192;
    public const MAX_BATCH_DOCUMENTS = 100;
    // One document has at most 4,096 lexical and 4,096 normalized-surface
    // postings. A replacement may briefly own both old and new rows, so its
    // measured transaction frontier is twice that document envelope.
    public const MAX_DOCUMENT_POSTINGS = 8192;
    public const MAX_BATCH_POSTINGS = 50000;
    public const MAX_BATCH_TERMS = 8192;
    public const MAX_EMPTY_TERM_CLEANUP = 1000;
    public const TARGETED_SCOPE_INDEX_NAME = 'wp_fts_term_object';
    public const FILTERED_SCOPE_INDEX_NAME = 'wp_fts_type_status_id';
    private const MAX_SEARCH_SQL_BYTES = 32768;
    private const MAX_SNIPPET_BYTES = 20000;
    // Five thousand UTF-8 characters are at most 20 KiB under utf8mb4.
    private const MAX_METADATA_TEXT_CHARACTERS = 5000;
    private const MAX_CURSOR_BYTES = 2048;
    private const MAX_MODE_BYTES = 8;
    private const MAX_TERM_IDENTITY_INPUT_BYTES = 288;
    private const MAX_LANGUAGE_INPUT_BYTES = 64;
    private const MAX_FILTER_VALUES = 32;
    private const MAX_FILTER_VALUE_BYTES = 64;
    private const MAX_NUMERIC_INPUT_BYTES = 64;
    private const MAX_SWITCH_INPUT_BYTES = 16;
    private const MAX_SEARCH_OPTION_KEYS = 64;
    private const MAX_SEARCH_OPTION_NODES = 256;
    private const MAX_SEARCH_OPTION_BYTES = 32768;
    // `$wpdb`, the hydration row, the transport array, and `WP_Post` share most
    // string buffers through copy-on-write, but they still add per-row objects
    // and arrays. Keeping canonical row data below 4 MiB leaves a conservative
    // margin inside the 16 MiB search-allocation gate on a 128 MiB PHP worker.
    private const CANONICAL_ROW_OVERHEAD_BYTES = 4096;
    public const MAX_CANONICAL_POST_BYTES = 4190208;
    private const MAX_CANONICAL_PAGE_BYTES = self::MAX_CANONICAL_POST_BYTES + self::CANONICAL_ROW_OVERHEAD_BYTES;
    private const RARITY_SCALE = 1000000;
    private const PRIMARY_WEIGHT = 1000;
    private const SECONDARY_WEIGHT = 800;
    private const PREFIX_WEIGHT = 600;
    private const MAX_POSTING_IMPACT = 65535;
    private const MAX_RECENCY_BOOST_STRENGTH = 2.0;
    // The largest integral rank is maximum impact * maximum rarity weight *
    // logical groups * (1 + maximum recency strength). Keep it decimal text so
    // this bound and cursor boundaries remain exact on 32-bit PHP.
    private const MAX_CURSOR_SCORE = '2359260000000';
    public const CANONICAL_POST_COLUMNS = [
        'ID',
        'post_author',
        'post_date',
        'post_date_gmt',
        'post_content',
        'post_title',
        'post_excerpt',
        'post_status',
        'comment_status',
        'ping_status',
        'post_password',
        'post_name',
        'to_ping',
        'pinged',
        'post_modified',
        'post_modified_gmt',
        'post_content_filtered',
        'post_parent',
        'guid',
        'menu_order',
        'post_type',
        'post_mime_type',
        'comment_count',
    ];

    private object $wpdb;
    private string $termsTable;
    private string $postingsTable;
    private string $documentsTable;
    private string $workTable;
    private string $postsTable;
    private string $termRelationshipsTable;
    private string $optionsTable;
    private ?bool $sqliteRuntime = null;
    /** @var array<string,bool>|null */
    private ?array $mysqlStatisticsColumns = null;
    private string $mysqlStatisticsInspectionError = '';
    private ?Closure $mutationGuard;
    private bool $transactionActive = false;
    private bool $transactionMutated = false;
    private bool $transactionEpochAdvanced = false;
    /** @var WeakMap<WP_FTS_Prepared_Replacement_Plan,array{new:array<int,int>,old:array<int,int>,mutations:int}> */
    private WeakMap $issuedReplacementPlans;

    /**
     * @param object $wpdb WordPress `$wpdb`-compatible connection.
     * @param string|null $prefix Optional table prefix; defaults to
     *        `$wpdb->prefix` and its canonical WordPress table properties.
     * @param callable():void|null $mutationGuard Writer-lease assertion run
     *        before index writes and transaction publication.
     */
    public function __construct(object $wpdb, ?string $prefix = null, ?callable $mutationGuard = null)
    {
        $this->wpdb = $wpdb;
        $prefixWasExplicit = $prefix !== null;
        $prefix = $prefix ?? (string) ($wpdb->prefix ?? '');
        $this->termsTable = $prefix . 'fts_terms';
        $this->postingsTable = $prefix . 'fts_postings';
        $this->documentsTable = $prefix . 'fts_documents';
        $this->workTable = $prefix . 'fts_work';
        $this->postsTable = !$prefixWasExplicit && isset($wpdb->posts) && is_string($wpdb->posts)
            ? $wpdb->posts
            : $prefix . 'posts';
        $this->termRelationshipsTable = !$prefixWasExplicit && isset($wpdb->term_relationships) && is_string($wpdb->term_relationships)
            ? $wpdb->term_relationships
            : $prefix . 'term_relationships';
        $this->optionsTable = $prefix . 'options';
        $this->mutationGuard = $mutationGuard !== null ? Closure::fromCallable($mutationGuard) : null;
        $this->issuedReplacementPlans = new WeakMap();
    }

    /** Tell the framework-neutral indexer to emit normalized surface rows. */
    public function indexes_surface_postings(): bool
    {
        return true;
    }

    /**
     * Create the complete relational physical schema.
     *
     * Incompatible derived generations are removed before WordPress `dbDelta()`
     * creates missing tables and indexes. Outside WordPress, the same raw CREATE
     * statements run directly. Binary charsets keep dictionary identities
     * byte-stable on MySQL/MariaDB.
     */
    public function create_tables(): void
    {
        $this->guard_mutation();
        $this->drop_incompatible_derived_tables();
        $binary = 'DEFAULT CHARSET=binary';
        $sql = [
            "CREATE TABLE {$this->termsTable} (
term_id bigint unsigned NOT NULL AUTO_INCREMENT,
lang varbinary(32) NOT NULL,
kind tinyint unsigned NOT NULL DEFAULT 0,
term varbinary(255) NOT NULL,
doc_freq int unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (term_id),
UNIQUE KEY term_identity (lang,kind,term),
KEY empty_terms (doc_freq)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC {$binary};",
            "CREATE TABLE {$this->postingsTable} (
term_id bigint unsigned NOT NULL,
post_id bigint unsigned NOT NULL,
impact smallint unsigned NOT NULL,
PRIMARY KEY  (term_id,post_id),
KEY post_term_impact (post_id,term_id,impact)
) ENGINE=InnoDB {$binary};",
            "CREATE TABLE {$this->documentsTable} (
post_id bigint unsigned NOT NULL,
primary_lang varbinary(32) NOT NULL DEFAULT 'und',
content_hash varbinary(64) NULL,
snippet_text mediumtext NULL,
indexed_at bigint unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            "CREATE TABLE {$this->workTable} (
job_key varbinary(191) NOT NULL,
kind varchar(16) NOT NULL,
post_id bigint unsigned NOT NULL DEFAULT 0,
generation bigint unsigned NOT NULL DEFAULT 1,
state varchar(12) NOT NULL DEFAULT 'pending',
available_at bigint unsigned NOT NULL DEFAULT 0,
attempts int unsigned NOT NULL DEFAULT 0,
claim_token varchar(64) NOT NULL DEFAULT '',
claimed_generation bigint unsigned NOT NULL DEFAULT 0,
claim_expires_at bigint unsigned NOT NULL DEFAULT 0,
cursor_post_id bigint unsigned NOT NULL DEFAULT 0,
scope_coverage varchar(12) NOT NULL DEFAULT '',
scope_incarnation varbinary(32) NOT NULL DEFAULT '',
scope_subject_type varchar(24) NOT NULL DEFAULT '',
scope_subject_id bigint unsigned NOT NULL DEFAULT 0,
payload longtext NULL,
last_error_code varchar(64) NOT NULL DEFAULT '',
last_error_at bigint unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (job_key),
KEY ready (kind,state,available_at,post_id,job_key),
KEY recoverable (kind,state,claim_expires_at,available_at,post_id,job_key),
KEY claim_token (claim_token,post_id),
KEY kind_job (kind,job_key),
KEY scope_subject (kind,scope_coverage,scope_subject_type,scope_subject_id),
KEY dirty (post_id,kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        ];

        if ($this->run_db_delta($sql)) {
            return;
        }
        foreach ($sql as $statement) {
            $this->query($statement, 'create relational FTS tables');
        }
    }

    /** Add the lease-expiry index without replacing existing queued work. */
    public function ensure_recoverable_work_index(): void
    {
        $this->guard_mutation();
        $expected = [
            'name' => $this->is_sqlite_runtime() ? $this->workTable . '_recoverable' : 'recoverable',
            'columns' => ['kind', 'state', 'claim_expires_at', 'available_at', 'post_id', 'job_key'],
            'unique' => false,
        ];
        $physical = $this->is_sqlite_runtime()
            ? $this->inspect_sqlite_schema($this->workTable, [$expected])
            : $this->inspect_mysql_schema($this->workTable);
        if (!empty($physical['exists']) && $this->schema_has_index($physical['indexes'] ?? [], $expected)) {
            return;
        }
        // This additive migration owns only the exact missing-index case.
        // Missing columns, a missing table, or a conflicting named index are
        // incompatible schema damage; leave those states to create_tables(),
        // which can replace the work table and enqueue corpus reconciliation.
        if (empty($physical['exists'])) {
            return;
        }
        foreach ($expected['columns'] as $column) {
            if (!in_array($column, $physical['columns'] ?? [], true)) {
                return;
            }
        }
        if ($this->named_schema_index($physical['indexes'] ?? [], $expected['name']) !== null) {
            return;
        }
        $work = $this->required_schema_identifier($this->workTable);
        if ($this->is_sqlite_runtime()) {
            $name = $this->required_schema_identifier($this->workTable . '_recoverable');
            $this->query(
                "CREATE INDEX {$name} ON {$work}(kind,state,claim_expires_at,available_at,post_id,job_key)",
                'add recoverable FTS work index'
            );
            return;
        }

        $this->query(
            "CREATE INDEX recoverable ON {$work}(kind,state,claim_expires_at,available_at,post_id,job_key)",
            'add recoverable FTS work index'
        );
    }

    /**
     * Return the supporting core-table indexes this site still needs to create.
     *
     * Callers persist ownership before issuing DDL. An exact namespaced index
     * that predates this plugin is reused but deliberately not claimed, while
     * a same-name/different-definition collision fails closed.
     *
     * @return string[] Stable contract keys, never database identifiers.
     */
    public function scope_keyset_indexes_requiring_creation(): array
    {
        $contracts = $this->scope_keyset_index_contracts();
        $requests = [];
        foreach ($contracts as $contract) {
            $requests[$contract['table']] = [
                'indexes' => [$contract],
                'inspect_all_indexes' => false,
            ];
        }

        return $this->scope_keyset_indexes_requiring_creation_from_physical(
            $contracts,
            $this->inspect_schema_snapshot($requests)
        );
    }

    /**
     * @param array<string,array{key:string,table:string,name:string,columns:string[],unique:bool}> $contracts
     * @param array<string,array<string,mixed>> $physical
     * @return string[]
     */
    private function scope_keyset_indexes_requiring_creation_from_physical(array $contracts, array $physical): array
    {
        $missing = [];
        foreach ($contracts as $key => $contract) {
            $tablePhysical = $physical[$contract['table']] ?? $this->empty_physical_schema();
            $this->assert_scope_keyset_table_physical($contract, $tablePhysical);
            $named = $this->named_schema_index($tablePhysical['indexes'] ?? [], $contract['name']);
            if ($named === null) {
                $missing[] = $key;
                continue;
            }
            if (!$this->schema_index_matches_exactly($named, $contract)) {
                throw new RuntimeException(
                    "The {$contract['table']} index {$contract['name']} conflicts with the FTS keyset contract."
                );
            }
        }

        return $missing;
    }

    /** @return array{valid:bool,missing:string[],error:string} */
    public function verify_scope_keyset_indexes(): array
    {
        try {
            $missing = $this->scope_keyset_indexes_requiring_creation();
        } catch (Throwable $error) {
            return [
                'valid' => false,
                'missing' => [],
                'error' => substr($error->getMessage(), 0, 240),
            ];
        }

        return [
            'valid' => $missing === [],
            'missing' => $missing,
            'error' => '',
        ];
    }

    /** Install the two indexes that make selective scope pages direct keysets. */
    public function ensure_scope_keyset_indexes(): void
    {
        foreach ($this->scope_keyset_index_contracts() as $contract) {
            $physical = $this->inspect_scope_keyset_table($contract);
            $named = $this->named_schema_index($physical['indexes'] ?? [], $contract['name']);
            if ($named !== null) {
                if (!$this->schema_index_matches_exactly($named, $contract)) {
                    throw new RuntimeException(
                        "The {$contract['table']} index {$contract['name']} conflicts with the FTS keyset contract."
                    );
                }
                continue;
            }

            $table = $this->required_schema_identifier($contract['table']);
            $name = $this->required_schema_identifier($contract['name']);
            $columns = implode(',', array_map(
                fn(string $column): string => $this->required_schema_identifier($column),
                $contract['columns']
            ));
            $this->guard_mutation();
            $this->query(
                "CREATE INDEX {$name} ON {$table}({$columns})",
                "add {$contract['key']} FTS scope keyset index"
            );

            $installed = $this->inspect_scope_keyset_table($contract);
            $actual = $this->named_schema_index($installed['indexes'] ?? [], $contract['name']);
            if ($actual === null || !$this->schema_index_matches_exactly($actual, $contract)) {
                throw new RuntimeException(
                    "The {$contract['table']} index {$contract['name']} was not installed with the required FTS definition."
                );
            }
            // Core-table DDL can outlive a low-end host's writer lease. Stop
            // before a second index or logical publication if a successor
            // writer acquired the lifecycle boundary while CREATE ran.
            $this->guard_mutation();
        }
    }

    /**
     * Remove only exact indexes whose ownership was durably recorded pre-DDL.
     *
     * @param string[] $ownedKeys Stable keys read from the bounded ownership option.
     */
    public function drop_owned_scope_keyset_indexes(array $ownedKeys): void
    {
        $owned = array_fill_keys(array_filter($ownedKeys, 'is_string'), true);
        foreach ($this->scope_keyset_index_contracts() as $key => $contract) {
            if (!isset($owned[$key])) {
                continue;
            }
            $physical = $this->is_sqlite_runtime()
                ? $this->inspect_sqlite_schema($contract['table'], [$contract])
                : $this->inspect_mysql_schema($contract['table']);
            if (empty($physical['exists'])) {
                continue;
            }
            $actual = $this->named_schema_index($physical['indexes'] ?? [], $contract['name']);
            if ($actual === null || !$this->schema_index_matches_exactly($actual, $contract)) {
                // A missing or changed definition is no longer safe to treat as
                // this plugin's object. Never drop a merely similar core index.
                continue;
            }
            $name = $this->required_schema_identifier($contract['name']);
            if ($this->is_sqlite_runtime()) {
                $this->guard_mutation();
                $this->query("DROP INDEX {$name}", "remove {$key} FTS scope keyset index");
                $this->guard_mutation();
                continue;
            }
            $table = $this->required_schema_identifier($contract['table']);
            $this->guard_mutation();
            $this->query("DROP INDEX {$name} ON {$table}", "remove {$key} FTS scope keyset index");
            $this->guard_mutation();
        }
    }

    /** SQL suffix that makes a missing targeted-scope capability fail closed. */
    public function targeted_scope_index_hint(): string
    {
        return $this->scope_keyset_index_hint('targeted');
    }

    /** SQL suffix that makes a missing filtered-scope capability fail closed. */
    public function filtered_scope_index_hint(): string
    {
        return $this->scope_keyset_index_hint('filtered');
    }

    /** Verify the physical targeted keyset immediately before scope SQL. */
    public function validated_targeted_scope_index_hint(): string
    {
        return $this->validated_scope_keyset_index_hint('targeted');
    }

    /** Verify the physical filtered keyset immediately before scope SQL. */
    public function validated_filtered_scope_index_hint(): string
    {
        return $this->validated_scope_keyset_index_hint('filtered');
    }

    /**
     * @return array<string,array{key:string,table:string,name:string,columns:string[],unique:bool}>
     */
    private function scope_keyset_index_contracts(): array
    {
        $contracts = [
            'targeted' => [
                'key' => 'targeted',
                'table' => $this->termRelationshipsTable,
                'name' => self::TARGETED_SCOPE_INDEX_NAME,
                'columns' => ['term_taxonomy_id', 'object_id'],
                'unique' => false,
            ],
            'filtered' => [
                'key' => 'filtered',
                'table' => $this->postsTable,
                'name' => self::FILTERED_SCOPE_INDEX_NAME,
                'columns' => ['post_type', 'post_status', 'ID'],
                'unique' => false,
            ],
        ];
        if (!$this->is_sqlite_runtime()) {
            return $contracts;
        }
        // SQLite index names share one database-wide namespace, including all
        // multisite prefixes. A short table-derived digest avoids cross-blog
        // collisions without putting an unbounded prefix into an identifier.
        foreach ($contracts as &$contract) {
            $contract['name'] = 'wp_fts_' . substr(
                hash('sha256', $contract['table'] . '|' . $contract['name']),
                0,
                24
            );
        }
        unset($contract);

        return $contracts;
    }

    /** @param array{key:string,table:string,name:string,columns:string[],unique:bool} $contract */
    private function inspect_scope_keyset_table(array $contract): array
    {
        $physical = $this->is_sqlite_runtime()
            ? $this->inspect_sqlite_schema($contract['table'], [$contract])
            : $this->inspect_mysql_schema($contract['table']);
        $this->assert_scope_keyset_table_physical($contract, $physical);

        return $physical;
    }

    /** @param array{key:string,table:string,name:string,columns:string[],unique:bool} $contract */
    private function assert_scope_keyset_table_physical(array $contract, array $physical): void
    {
        if (!empty($physical['inspection_error'])) {
            throw new RuntimeException(
                "The {$contract['table']} metadata required by FTS scope expansion is unavailable."
            );
        }
        if (empty($physical['exists'])) {
            throw new RuntimeException("The {$contract['table']} table required by FTS scope expansion is missing.");
        }
        foreach ($contract['columns'] as $column) {
            if (!in_array($column, $physical['columns'] ?? [], true)) {
                throw new RuntimeException(
                    "The {$contract['table']}.{$column} column required by FTS scope expansion is missing."
                );
            }
        }
    }

    /** Force the contract index so a scope page cannot degrade to a table scan. */
    private function scope_keyset_index_hint(string $key): string
    {
        $contract = $this->scope_keyset_index_contracts()[$key] ?? null;
        if ($contract === null) {
            throw new LogicException('Unknown FTS scope keyset index.');
        }
        $name = $this->required_schema_identifier($contract['name']);

        return $this->is_sqlite_runtime()
            ? " INDEXED BY {$name}"
            : " FORCE INDEX ({$name})";
    }

    /** Fail closed if post-publication DDL changed a forced keyset definition. */
    private function validated_scope_keyset_index_hint(string $key): string
    {
        $contract = $this->scope_keyset_index_contracts()[$key] ?? null;
        if ($contract === null) {
            throw new LogicException('Unknown FTS scope keyset index.');
        }
        $actual = $this->is_sqlite_runtime()
            ? $this->inspect_named_sqlite_index($contract['table'], $contract['name'])
            : $this->inspect_named_mysql_index($contract['table'], $contract['name']);
        if ($actual === null || !$this->schema_index_matches_exactly($actual, $contract)) {
            throw new RuntimeException(
                "The {$contract['table']} index {$contract['name']} is unavailable or conflicts with the FTS keyset contract."
            );
        }

        return $this->scope_keyset_index_hint($key);
    }

    /** @param array<int,array<string,mixed>> $indexes */
    private function named_schema_index(array $indexes, string $name): ?array
    {
        foreach ($indexes as $index) {
            if (isset($index['name']) && strcasecmp((string) $index['name'], $name) === 0) {
                return $index;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $actual @param array<string,mixed> $expected */
    private function schema_index_matches_exactly(array $actual, array $expected): bool
    {
        return ($actual['columns'] ?? []) === $expected['columns']
            && (bool) ($actual['unique'] ?? false) === (bool) $expected['unique']
            && ($actual['usable'] ?? true);
    }

    /**
     * Replace incompatible pre-v4 derived tables instead of asking dbDelta to
     * mutate primary-key identity in place. Terms, postings, and documents are
     * one reproducible search generation: retaining any member after a peer is
     * lost or replaced can attach old postings to reused term ids and leaves
     * deleted document ids outside reconciliation. Work is independent so a
     * damaged queue can be rebuilt without discarding a coherent search index.
     */
    private function drop_incompatible_derived_tables(): void
    {
        $contracts = $this->schema_contract();
        $physical = $this->inspect_schema_snapshot(
            $this->schema_contract_inspection_requests($contracts)
        );

        $searchTables = [$this->termsTable, $this->postingsTable, $this->documentsTable];
        $existingSearchTables = 0;
        $searchGenerationMatches = true;
        foreach ($searchTables as $table) {
            if (!$physical[$table]['exists']) {
                continue;
            }
            $existingSearchTables++;
            if (!$this->physical_schema_matches_contract($table, $physical[$table], $contracts[$table])) {
                $searchGenerationMatches = false;
            }
        }
        if ($existingSearchTables > 0 && ($existingSearchTables !== count($searchTables) || !$searchGenerationMatches)) {
            $this->drop_existing_schema_tables($searchTables, $physical, 'replace incoherent FTS search generation');
        }

        if (
            $physical[$this->workTable]['exists']
            && !$this->physical_schema_matches_contract(
                $this->workTable,
                $physical[$this->workTable],
                $contracts[$this->workTable]
            )
        ) {
            $this->drop_existing_schema_tables(
                [$this->workTable],
                $physical,
                'replace incompatible FTS work table'
            );
        }
    }

    /** @param string[] $tables @param array<string,array<string,mixed>> $physical */
    private function drop_existing_schema_tables(array $tables, array $physical, string $context): void
    {
        foreach ($tables as $table) {
            if (empty($physical[$table]['exists'])) {
                continue;
            }
            $identifier = $this->schema_identifier($table);
            if ($identifier === null) {
                throw new RuntimeException('Invalid FTS table identifier during v4 migration.');
            }
            $this->query("DROP TABLE {$identifier}", $context);
        }
    }

    /** Return true only for the exact four-table contract dbDelta should retain. */
    private function physical_schema_matches_contract(string $table, array $physical, array $contract): bool
    {
        if (
            $physical['columns'] !== $contract['columns']
            || $this->schema_column_definition_mismatches($table, $physical, $contract) !== []
            || count($physical['indexes']) !== count($contract['indexes'])
            || (!$this->is_sqlite_runtime() && ($physical['engine'] ?? '') !== $contract['engine'])
        ) {
            return false;
        }
        foreach ($contract['indexes'] as $expected) {
            if (!$this->schema_has_index($physical['indexes'], $expected)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Inspect the exact physical table, column, index, and engine contract.
     *
     * The schema version option is only a migration cursor; callers must use
     * this result before treating the index as physically usable or persisting
     * a completed version.
     *
     * @return array{valid:bool,available:bool,missing_tables:string[],missing_columns:string[],unexpected_columns:string[],invalid_columns:string[],missing_indexes:string[],unexpected_indexes:string[],invalid_engines:string[]}
     */
    public function verify_schema(): array
    {
        $contracts = $this->schema_contract();

        return $this->verify_schema_from_physical(
            $contracts,
            $this->inspect_schema_snapshot($this->schema_contract_inspection_requests($contracts))
        );
    }

    /**
     * Inspect the four FTS tables and both selective core-table indexes in one
     * physical snapshot. Explicit diagnostics use this combined boundary so
     * table count cannot multiply metadata statements.
     *
     * @return array<string,mixed>
     */
    public function verify_schema_and_scope_keyset_indexes(): array
    {
        $contracts = $this->schema_contract();
        $scopeContracts = $this->scope_keyset_index_contracts();
        $requests = $this->schema_contract_inspection_requests($contracts);
        foreach ($scopeContracts as $contract) {
            $requests[$contract['table']] = [
                'indexes' => [$contract],
                'inspect_all_indexes' => false,
            ];
        }
        $snapshot = $this->inspect_schema_snapshot($requests);
        $verification = $this->verify_schema_from_physical($contracts, $snapshot);
        $verification['fts_tables_valid'] = !empty($verification['valid']);
        try {
            $missing = $this->scope_keyset_indexes_requiring_creation_from_physical(
                $scopeContracts,
                $snapshot
            );
            $scopeVerification = [
                'valid' => $missing === [],
                'missing' => $missing,
                'error' => '',
            ];
        } catch (Throwable $error) {
            $scopeVerification = [
                'valid' => false,
                'missing' => [],
                'error' => substr($error->getMessage(), 0, 240),
            ];
        }
        $verification['scope_keyset_indexes'] = $scopeVerification;
        $verification['valid'] = $verification['fts_tables_valid']
            && !empty($scopeVerification['valid']);

        return $verification;
    }

    /**
     * @param array<string,array<string,mixed>> $contracts
     * @param array<string,array<string,mixed>> $physicalByTable
     * @return array{valid:bool,available:bool,missing_tables:string[],missing_columns:string[],unexpected_columns:string[],invalid_columns:string[],missing_indexes:string[],unexpected_indexes:string[],invalid_engines:string[]}
     */
    private function verify_schema_from_physical(array $contracts, array $physicalByTable): array
    {
        $available = true;
        $missingTables = [];
        $missingColumns = [];
        $unexpectedColumns = [];
        $invalidColumns = [];
        $missingIndexes = [];
        $unexpectedIndexes = [];
        $invalidEngines = [];
        foreach ($contracts as $table => $contract) {
            $physical = $physicalByTable[$table] ?? $this->empty_physical_schema();
            if (!empty($physical['inspection_error'])) {
                $available = false;
                continue;
            }
            if (!$physical['exists']) {
                $missingTables[] = $table;
                continue;
            }
            foreach ($contract['columns'] as $column) {
                if (!in_array($column, $physical['columns'], true)) {
                    $missingColumns[] = $table . '.' . $column;
                }
            }
            foreach (array_diff($physical['columns'], $contract['columns']) as $column) {
                $unexpectedColumns[] = $table . '.' . $column;
            }
            array_push($invalidColumns, ...$this->schema_column_definition_mismatches($table, $physical, $contract));
            foreach ($contract['indexes'] as $expected) {
                if (!$this->schema_has_index($physical['indexes'], $expected)) {
                    $missingIndexes[] = $table . '.' . $expected['name'] . '(' . implode(',', $expected['columns']) . ')';
                }
            }
            foreach ($physical['indexes'] as $actual) {
                if (!$this->schema_index_is_expected($actual, $contract['indexes'])) {
                    $unexpectedIndexes[] = $table . '.' . ($actual['name'] ?? '?')
                        . '(' . implode(',', $actual['columns']) . ')';
                }
            }
            if ($this->is_sqlite_runtime() && count($physical['indexes']) > count($contract['indexes'])) {
                // SQLite accepts several historical name variants for one
                // definition. Two valid aliases are still two physical
                // indexes and must not disappear through shape-only matching.
                $unexpectedIndexes[] = $table . '.<duplicate-or-extra-index>';
            }
            if (!$this->is_sqlite_runtime() && ($physical['engine'] ?? '') !== $contract['engine']) {
                $engine = (string) ($physical['engine'] ?? '');
                $invalidEngines[] = $table . '(' . ($engine !== '' ? $engine : 'unknown') . ')';
            }
        }
        sort($missingTables, SORT_STRING);
        sort($missingColumns, SORT_STRING);
        sort($unexpectedColumns, SORT_STRING);
        sort($invalidColumns, SORT_STRING);
        sort($missingIndexes, SORT_STRING);
        sort($unexpectedIndexes, SORT_STRING);
        sort($invalidEngines, SORT_STRING);

        return [
            'valid' => $available && $missingTables === [] && $missingColumns === [] && $unexpectedColumns === [] && $invalidColumns === [] && $missingIndexes === [] && $unexpectedIndexes === [] && $invalidEngines === [],
            'available' => $available,
            'missing_tables' => $missingTables,
            'missing_columns' => $missingColumns,
            'unexpected_columns' => $unexpectedColumns,
            'invalid_columns' => $invalidColumns,
            'missing_indexes' => $missingIndexes,
            'unexpected_indexes' => $unexpectedIndexes,
            'invalid_engines' => $invalidEngines,
        ];
    }

    /** @return array<string,array{engine:string,columns:string[],indexes:array<int,array{columns:string[],unique:bool}>}> */
    private function schema_contract(): array
    {
        return [
            $this->termsTable => [
                'engine' => 'innodb',
                'columns' => ['term_id', 'lang', 'kind', 'term', 'doc_freq'],
                'mysql_definitions' => [
                    'term_id' => ['type' => 'bigint unsigned', 'nullable' => false, 'auto_increment' => true],
                    'lang' => ['type' => 'varbinary(32)', 'nullable' => false],
                    'kind' => ['type' => 'tinyint unsigned', 'nullable' => false],
                    'term' => ['type' => 'varbinary(255)', 'nullable' => false],
                    'doc_freq' => ['type' => 'int unsigned', 'nullable' => false],
                ],
                'indexes' => [
                    ['name' => 'PRIMARY', 'columns' => ['term_id'], 'unique' => true],
                    ['name' => 'term_identity', 'columns' => ['lang', 'kind', 'term'], 'unique' => true],
                    ['name' => 'empty_terms', 'columns' => ['doc_freq'], 'unique' => false],
                ],
            ],
            $this->postingsTable => [
                'engine' => 'innodb',
                'columns' => ['term_id', 'post_id', 'impact'],
                'mysql_definitions' => [
                    'term_id' => ['type' => 'bigint unsigned', 'nullable' => false],
                    'post_id' => ['type' => 'bigint unsigned', 'nullable' => false],
                    'impact' => ['type' => 'smallint unsigned', 'nullable' => false],
                ],
                'indexes' => [
                    ['name' => 'PRIMARY', 'columns' => ['term_id', 'post_id'], 'unique' => true],
                    ['name' => 'post_term_impact', 'columns' => ['post_id', 'term_id', 'impact'], 'unique' => false],
                ],
            ],
            $this->documentsTable => [
                'engine' => 'innodb',
                'columns' => ['post_id', 'primary_lang', 'content_hash', 'snippet_text', 'indexed_at'],
                'mysql_definitions' => [
                    'post_id' => ['type' => 'bigint unsigned', 'nullable' => false],
                    'primary_lang' => ['type' => 'varbinary(32)', 'nullable' => false],
                    'content_hash' => ['type' => 'varbinary(64)', 'nullable' => true],
                    'snippet_text' => ['type' => 'mediumtext', 'nullable' => true],
                    'indexed_at' => ['type' => 'bigint unsigned', 'nullable' => false],
                ],
                'indexes' => [['name' => 'PRIMARY', 'columns' => ['post_id'], 'unique' => true]],
            ],
            $this->workTable => [
                'engine' => 'innodb',
                'columns' => ['job_key', 'kind', 'post_id', 'generation', 'state', 'available_at', 'attempts', 'claim_token', 'claimed_generation', 'claim_expires_at', 'cursor_post_id', 'scope_coverage', 'scope_incarnation', 'scope_subject_type', 'scope_subject_id', 'payload', 'last_error_code', 'last_error_at'],
                'mysql_definitions' => [
                    'job_key' => ['type' => 'varbinary(191)', 'nullable' => false],
                    'kind' => ['type' => 'varchar(16)', 'nullable' => false],
                    'post_id' => ['type' => 'bigint unsigned', 'nullable' => false],
                    'generation' => ['type' => 'bigint unsigned', 'nullable' => false],
                    'state' => ['type' => 'varchar(12)', 'nullable' => false],
                    'available_at' => ['type' => 'bigint unsigned', 'nullable' => false],
                    'attempts' => ['type' => 'int unsigned', 'nullable' => false],
                    'claim_token' => ['type' => 'varchar(64)', 'nullable' => false],
                    'claimed_generation' => ['type' => 'bigint unsigned', 'nullable' => false],
                    'claim_expires_at' => ['type' => 'bigint unsigned', 'nullable' => false],
                    'cursor_post_id' => ['type' => 'bigint unsigned', 'nullable' => false],
                    'scope_coverage' => ['type' => 'varchar(12)', 'nullable' => false],
                    'scope_incarnation' => ['type' => 'varbinary(32)', 'nullable' => false],
                    'scope_subject_type' => ['type' => 'varchar(24)', 'nullable' => false],
                    'scope_subject_id' => ['type' => 'bigint unsigned', 'nullable' => false],
                    'payload' => ['type' => 'longtext', 'nullable' => true],
                    'last_error_code' => ['type' => 'varchar(64)', 'nullable' => false],
                    'last_error_at' => ['type' => 'bigint unsigned', 'nullable' => false],
                ],
                'indexes' => [
                    ['name' => 'PRIMARY', 'columns' => ['job_key'], 'unique' => true],
                    ['name' => 'ready', 'columns' => ['kind', 'state', 'available_at', 'post_id', 'job_key'], 'unique' => false],
                    ['name' => 'recoverable', 'columns' => ['kind', 'state', 'claim_expires_at', 'available_at', 'post_id', 'job_key'], 'unique' => false],
                    ['name' => 'claim_token', 'columns' => ['claim_token', 'post_id'], 'unique' => false],
                    ['name' => 'kind_job', 'columns' => ['kind', 'job_key'], 'unique' => false],
                    ['name' => 'scope_subject', 'columns' => ['kind', 'scope_coverage', 'scope_subject_type', 'scope_subject_id'], 'unique' => false],
                    ['name' => 'dirty', 'columns' => ['post_id', 'kind'], 'unique' => false],
                ],
            ],
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $contracts
     * @return array<string,array{indexes:array<int,array<string,mixed>>,inspect_all_indexes:bool}>
     */
    private function schema_contract_inspection_requests(array $contracts): array
    {
        $requests = [];
        foreach ($contracts as $table => $contract) {
            $requests[$table] = [
                'indexes' => is_array($contract['indexes'] ?? null) ? $contract['indexes'] : [],
                'inspect_all_indexes' => true,
            ];
        }

        return $requests;
    }

    /**
     * Read every requested table in one SQLite statement or two fixed MySQL
     * statements. The first MySQL read discovers optional STATISTICS columns;
     * the second returns tables, columns, and indexes as one bounded row set.
     *
     * @param array<string,array{indexes:array<int,array<string,mixed>>,inspect_all_indexes:bool}> $requests
     * @return array<string,array<string,mixed>>
     */
    private function inspect_schema_snapshot(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        return $this->is_sqlite_runtime()
            ? $this->inspect_sqlite_schemas($requests)
            : $this->inspect_mysql_schemas($requests);
    }

    /** @return array{exists:bool,engine:string,columns:string[],definitions:array<string,array{type:string,nullable:bool,extra:string}>,indexes:array<int,array{name:string,columns:string[],unique:bool}>} */
    private function inspect_mysql_schema(string $table): array
    {
        $snapshot = $this->inspect_mysql_schemas([
            $table => ['indexes' => [], 'inspect_all_indexes' => true],
        ]);

        return $snapshot[$table] ?? $this->empty_physical_schema();
    }

    /**
     * @param array<string,array{indexes:array<int,array<string,mixed>>,inspect_all_indexes:bool}> $requests
     * @return array<string,array<string,mixed>>
     */
    private function inspect_mysql_schemas(array $requests): array
    {
        $physical = [];
        $canonicalTables = [];
        foreach ($requests as $table => $_request) {
            if ($this->schema_identifier($table) === null) {
                continue;
            }
            $physical[$table] = $this->empty_physical_schema();
            $canonicalTables[strtolower($table)] = $table;
        }
        if ($physical === []) {
            return $physical;
        }

        $statisticsColumns = $this->mysql_statistics_columns();
        if ($this->mysqlStatisticsInspectionError !== '') {
            foreach ($physical as &$tablePhysical) {
                $tablePhysical['inspection_error'] = $this->mysqlStatisticsInspectionError;
            }
            unset($tablePhysical);

            return $physical;
        }
        $visibleProjection = !empty($statisticsColumns['IS_VISIBLE'])
            ? 's.IS_VISIBLE'
            : "''";
        $ignoredProjection = !empty($statisticsColumns['IGNORED'])
            ? 's.IGNORED'
            : "''";
        $expressionProjection = !empty($statisticsColumns['EXPRESSION'])
            ? 's.EXPRESSION'
            : "''";
        $tables = array_keys($physical);
        $tablePlaceholders = implode(',', array_fill(0, count($tables), '%s'));
        $indexPredicates = [];
        $indexArgs = [];
        foreach ($requests as $table => $request) {
            if (!isset($physical[$table])) {
                continue;
            }
            if (!empty($request['inspect_all_indexes'])) {
                $indexPredicates[] = 's.TABLE_NAME = %s';
                $indexArgs[] = $table;
                continue;
            }
            $names = [];
            foreach ($request['indexes'] as $index) {
                $name = is_scalar($index['name'] ?? null) ? (string) $index['name'] : '';
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
            if ($names === []) {
                continue;
            }
            $indexPredicates[] = '(s.TABLE_NAME = %s AND s.INDEX_NAME IN ('
                . implode(',', array_fill(0, count($names), '%s')) . '))';
            $indexArgs[] = $table;
            array_push($indexArgs, ...array_keys($names));
        }
        $indexPredicate = $indexPredicates === [] ? '0' : implode(' OR ', $indexPredicates);
        $sql = "/* wp_fts:physical-schema-snapshot */
SELECT 'table' AS row_kind, t.TABLE_NAME AS table_name, t.ENGINE AS engine,
       NULL AS column_name, NULL AS column_type, NULL AS is_nullable,
       NULL AS column_extra, NULL AS ordinal_position,
       NULL AS index_name, NULL AS index_column, NULL AS index_position,
       NULL AS non_unique, NULL AS sub_part, NULL AS index_type,
       NULL AS index_visible, NULL AS index_ignored, NULL AS index_expression
FROM information_schema.TABLES t
WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME IN ({$tablePlaceholders})
UNION ALL
SELECT 'column', c.TABLE_NAME, NULL,
       c.COLUMN_NAME, c.COLUMN_TYPE, c.IS_NULLABLE, c.EXTRA, c.ORDINAL_POSITION,
       NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL
FROM information_schema.COLUMNS c
WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME IN ({$tablePlaceholders})
UNION ALL
SELECT 'index', s.TABLE_NAME, NULL,
       NULL, NULL, NULL, NULL, NULL,
       s.INDEX_NAME, s.COLUMN_NAME, s.SEQ_IN_INDEX,
       s.NON_UNIQUE, s.SUB_PART, s.INDEX_TYPE,
       {$visibleProjection}, {$ignoredProjection}, {$expressionProjection}
FROM information_schema.STATISTICS s
WHERE s.TABLE_SCHEMA = DATABASE() AND ({$indexPredicate})
ORDER BY table_name, row_kind, ordinal_position, index_name, index_position";
        $rows = $this->schema_rows($this->wpdb->prepare(
            $sql,
            ...array_merge($tables, $tables, $indexArgs)
        ));
        $inspectionError = isset($this->wpdb->last_error) && is_scalar($this->wpdb->last_error)
            ? trim((string) $this->wpdb->last_error)
            : '';
        if ($inspectionError !== '') {
            foreach ($physical as &$tablePhysical) {
                $tablePhysical['inspection_error'] = substr($inspectionError, 0, 240);
            }
            unset($tablePhysical);

            return $physical;
        }
        $columnsByTable = [];
        $indexesByTable = [];
        foreach ($rows as $row) {
            $reportedTable = $this->schema_row_value($row, ['table_name', 'TABLE_NAME']);
            $table = $canonicalTables[strtolower($reportedTable)] ?? null;
            if ($table === null) {
                continue;
            }
            $kind = $this->schema_row_value($row, ['row_kind']);
            if ($kind === 'table') {
                $physical[$table]['exists'] = true;
                $physical[$table]['engine'] = strtolower($this->schema_row_value($row, ['engine', 'ENGINE']));
                continue;
            }
            if ($kind === 'column') {
                $name = $this->schema_row_value($row, ['column_name', 'COLUMN_NAME']);
                if ($name === '') {
                    continue;
                }
                $position = max(1, (int) $this->schema_row_value($row, ['ordinal_position', 'ORDINAL_POSITION']));
                $columnsByTable[$table][$position] = $name;
                $physical[$table]['definitions'][$name] = [
                    'type' => $this->normalize_mysql_column_type($this->schema_row_value($row, ['column_type', 'COLUMN_TYPE'])),
                    'nullable' => strtoupper($this->schema_row_value($row, ['is_nullable', 'IS_NULLABLE'])) === 'YES',
                    'extra' => strtolower($this->schema_row_value($row, ['column_extra', 'EXTRA'])),
                ];
                continue;
            }
            if ($kind !== 'index') {
                continue;
            }
            $name = $this->schema_row_value($row, ['index_name', 'INDEX_NAME']);
            if ($name === '') {
                continue;
            }
            $column = $this->schema_row_value($row, ['index_column', 'COLUMN_NAME']);
            $expression = $this->schema_row_value($row, ['index_expression', 'EXPRESSION']);
            $subPart = max(0, (int) $this->schema_row_value($row, ['sub_part', 'SUB_PART']));
            if ($column === '') {
                $column = $expression !== '' ? '<expression>' : '<unknown>';
            } elseif ($subPart > 0) {
                $column .= '(' . $subPart . ')';
            }
            $position = max(1, (int) $this->schema_row_value($row, ['index_position', 'SEQ_IN_INDEX']));
            $indexType = strtoupper($this->schema_row_value($row, ['index_type', 'INDEX_TYPE']));
            $visible = strtoupper($this->schema_row_value($row, ['index_visible', 'IS_VISIBLE', 'Visible']));
            $ignored = strtoupper($this->schema_row_value($row, ['index_ignored', 'IGNORED', 'Ignored']));
            $indexesByTable[$table][$name]['name'] = $name;
            $indexesByTable[$table][$name]['columns'][$position] = $column;
            $indexesByTable[$table][$name]['unique'] = (int) $this->schema_row_value($row, ['non_unique', 'NON_UNIQUE']) === 0;
            $indexesByTable[$table][$name]['usable'] = ($indexesByTable[$table][$name]['usable'] ?? true)
                && ($indexType === '' || $indexType === 'BTREE')
                && $visible !== 'NO'
                && $ignored !== 'YES';
        }
        foreach ($physical as $table => &$tablePhysical) {
            $columns = $columnsByTable[$table] ?? [];
            ksort($columns, SORT_NUMERIC);
            $tablePhysical['columns'] = array_values($columns);
            $tablePhysical['indexes'] = $this->ordered_schema_indexes($indexesByTable[$table] ?? []);
        }
        unset($tablePhysical);

        return $physical;
    }

    /** @return array<string,bool> */
    private function mysql_statistics_columns(): array
    {
        if ($this->mysqlStatisticsColumns !== null) {
            return $this->mysqlStatisticsColumns;
        }
        $columns = [];
        $rows = $this->schema_rows(
            "/* wp_fts:physical-schema-capabilities */
SELECT COLUMN_NAME AS column_name
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'information_schema'
  AND TABLE_NAME = 'STATISTICS'
  AND COLUMN_NAME IN ('IS_VISIBLE','IGNORED','EXPRESSION')"
        );
        $inspectionError = isset($this->wpdb->last_error) && is_scalar($this->wpdb->last_error)
            ? trim((string) $this->wpdb->last_error)
            : '';
        if ($inspectionError !== '') {
            $this->mysqlStatisticsInspectionError = substr($inspectionError, 0, 240);

            return $this->mysqlStatisticsColumns = [];
        }
        foreach ($rows as $row) {
            $name = strtoupper($this->schema_row_value($row, ['column_name', 'COLUMN_NAME']));
            if (in_array($name, ['IS_VISIBLE', 'IGNORED', 'EXPRESSION'], true)) {
                $columns[$name] = true;
            }
        }

        return $this->mysqlStatisticsColumns = $columns;
    }

    /** Read one exact MySQL/MariaDB index definition in one metadata query. */
    private function inspect_named_mysql_index(string $table, string $name): ?array
    {
        $identifier = $this->required_schema_identifier($table);
        $rows = $this->schema_rows($this->wpdb->prepare(
            "SHOW INDEX FROM {$identifier} WHERE Key_name = %s",
            $name
        ));
        $this->assert_no_database_error('verify an FTS scope keyset index');

        return $this->named_schema_index($this->mysql_schema_indexes_from_rows($rows), $name);
    }

    /** @param object[]|array<int,array<string,mixed>> $rows */
    private function mysql_schema_indexes_from_rows(array $rows): array
    {
        $byName = [];
        foreach ($rows as $row) {
            $name = $this->schema_row_value($row, ['Key_name', 'key_name']);
            $column = $this->schema_row_value($row, ['Column_name', 'column_name']);
            if ($name === '') {
                continue;
            }
            $position = (int) $this->schema_row_value($row, ['Seq_in_index', 'seq_in_index']);
            $subPart = max(0, (int) $this->schema_row_value($row, ['Sub_part', 'sub_part']));
            if ($column === '') {
                $column = $this->schema_row_value($row, ['Expression', 'expression']) !== ''
                    ? '<expression>'
                    : '<unknown>';
            } elseif ($subPart > 0) {
                $column .= '(' . $subPart . ')';
            }
            $indexType = strtoupper($this->schema_row_value($row, ['Index_type', 'index_type']));
            $visible = strtoupper($this->schema_row_value($row, ['Visible', 'visible']));
            $ignored = strtoupper($this->schema_row_value($row, ['Ignored', 'ignored']));
            $byName[$name]['name'] = $name;
            $byName[$name]['columns'][$position] = $column;
            $byName[$name]['unique'] = (int) $this->schema_row_value($row, ['Non_unique', 'non_unique']) === 0;
            $byName[$name]['usable'] = ($byName[$name]['usable'] ?? true)
                && ($indexType === '' || $indexType === 'BTREE')
                && $visible !== 'NO'
                && $ignored !== 'YES';
        }

        return $this->ordered_schema_indexes($byName);
    }

    /**
     * Inspect only the fixed indexes relevant to the caller's schema contract.
     *
     * @param array<int,array{name:string,columns:string[],unique:bool}> $expectedIndexes
     * @return array{exists:bool,columns:string[],definitions:array<string,array{type:string,nullable:bool,extra:string}>,indexes:array<int,array{name:string,columns:string[],unique:bool,usable?:bool}>}
     */
    private function inspect_sqlite_schema(string $table, array $expectedIndexes): array
    {
        $snapshot = $this->inspect_sqlite_schemas([
            $table => ['indexes' => $expectedIndexes, 'inspect_all_indexes' => true],
        ]);

        return $snapshot[$table] ?? $this->empty_physical_schema();
    }

    /**
     * SQLite exposes PRAGMAs as table-valued functions. Correlating them with
     * a fixed wanted-table relation keeps the complete diagnostic to one
     * statement without parsing CREATE SQL or walking indexes in PHP.
     *
     * @param array<string,array{indexes:array<int,array<string,mixed>>,inspect_all_indexes:bool}> $requests
     * @return array<string,array<string,mixed>>
     */
    private function inspect_sqlite_schemas(array $requests): array
    {
        $physical = [];
        $wantedRows = [];
        $expectedNameRows = [];
        foreach ($requests as $table => $request) {
            if ($this->schema_identifier($table) === null) {
                continue;
            }
            $physical[$table] = $this->empty_physical_schema();
            $autoLimit = 0;
            foreach ($request['indexes'] as $expected) {
                if (
                    strcasecmp((string) ($expected['name'] ?? ''), 'PRIMARY') !== 0
                    && !empty($expected['unique'])
                ) {
                    $autoLimit++;
                }
            }
            $wantedRows[] = '(' . implode(',', [
                $this->sqlite_schema_literal($table),
                !empty($request['inspect_all_indexes']) ? '1' : '0',
                (string) $autoLimit,
            ]) . ')';
            foreach ($this->sqlite_expected_index_names($table, $request['indexes']) as $name) {
                $expectedNameRows[] = '(' . $this->sqlite_schema_literal($table)
                    . ',' . $this->sqlite_schema_literal($name) . ')';
            }
        }
        if ($physical === []) {
            return $physical;
        }
        $expectedNames = $expectedNameRows === []
            ? 'SELECT NULL AS table_name, NULL AS index_name WHERE 0'
            : 'VALUES ' . implode(',', $expectedNameRows);
        $rows = $this->schema_rows(
            "/* wp_fts:physical-schema-snapshot */
WITH wanted(table_name, detect_unexpected, auto_limit) AS (
    VALUES " . implode(',', $wantedRows) . "
), expected_names(table_name, index_name) AS (
    {$expectedNames}
), listed_indexes AS (
    SELECT w.table_name, w.detect_unexpected, w.auto_limit,
           il.name AS index_name, il.\"unique\" AS is_unique,
           il.origin, il.partial,
           ROW_NUMBER() OVER (
               PARTITION BY w.table_name, il.origin ORDER BY il.name
           ) AS origin_position
    FROM wanted w
    JOIN pragma_index_list(w.table_name) il
    WHERE il.origin <> 'pk'
), candidate_indexes AS (
    SELECT li.*
    FROM listed_indexes li
    WHERE EXISTS (
        SELECT 1 FROM expected_names expected
        WHERE expected.table_name = li.table_name
          AND expected.index_name = li.index_name
    ) OR (li.origin = 'u' AND li.origin_position <= li.auto_limit)
), candidate_rows AS (
    SELECT candidate.table_name, candidate.index_name,
           candidate.is_unique, candidate.partial,
           info.seqno AS index_position,
           COALESCE(info.name, '<expression-or-unknown>') AS index_column
    FROM candidate_indexes candidate
    LEFT JOIN pragma_index_info(candidate.index_name) info
), unexpected_rows AS (
    SELECT listed.table_name, '<unexpected>' AS index_name,
           0 AS is_unique, 0 AS partial, -1 AS index_position,
           '<uninspected>' AS index_column
    FROM listed_indexes listed
    WHERE listed.detect_unexpected = 1
      AND NOT EXISTS (
          SELECT 1 FROM expected_names expected
          WHERE expected.table_name = listed.table_name
            AND expected.index_name = listed.index_name
      )
      AND NOT (
          listed.origin = 'u'
          AND listed.origin_position <= listed.auto_limit
      )
    GROUP BY listed.table_name
)
SELECT 'table' AS row_kind, wanted.table_name,
       CASE WHEN schema.name IS NULL THEN 0 ELSE 1 END AS table_exists,
       NULL AS column_name, NULL AS ordinal_position, NULL AS primary_position,
       NULL AS index_name, NULL AS is_unique, NULL AS is_partial,
       NULL AS index_position, NULL AS index_column
FROM wanted
LEFT JOIN sqlite_schema schema
  ON schema.type = 'table' AND schema.name = wanted.table_name
UNION ALL
SELECT 'column', wanted.table_name, 1,
       info.name, info.cid, info.pk,
       NULL, NULL, NULL, NULL, NULL
FROM wanted
JOIN pragma_table_info(wanted.table_name) info
UNION ALL
SELECT 'index', table_name, 1,
       NULL, NULL, NULL,
       index_name, is_unique, partial, index_position, index_column
FROM candidate_rows
UNION ALL
SELECT 'index', table_name, 1,
       NULL, NULL, NULL,
       index_name, is_unique, partial, index_position, index_column
FROM unexpected_rows
ORDER BY table_name, row_kind, ordinal_position, index_name, index_position"
        );
        $inspectionError = isset($this->wpdb->last_error) && is_scalar($this->wpdb->last_error)
            ? trim((string) $this->wpdb->last_error)
            : '';
        if ($inspectionError !== '') {
            foreach ($physical as &$tablePhysical) {
                $tablePhysical['inspection_error'] = substr($inspectionError, 0, 240);
            }
            unset($tablePhysical);

            return $physical;
        }
        $canonicalTables = [];
        foreach (array_keys($physical) as $table) {
            $canonicalTables[strtolower($table)] = $table;
        }
        $columnsByTable = [];
        $primaryByTable = [];
        $indexesByTable = [];
        foreach ($rows as $row) {
            $reportedTable = $this->schema_row_value($row, ['table_name']);
            $table = $canonicalTables[strtolower($reportedTable)] ?? null;
            if ($table === null) {
                continue;
            }
            $kind = $this->schema_row_value($row, ['row_kind']);
            if ($kind === 'table') {
                $physical[$table]['exists'] = (int) $this->schema_row_value($row, ['table_exists']) === 1;
                continue;
            }
            if ($kind === 'column') {
                $name = $this->schema_row_value($row, ['column_name']);
                if ($name === '') {
                    continue;
                }
                $position = max(0, (int) $this->schema_row_value($row, ['ordinal_position']));
                $columnsByTable[$table][$position] = $name;
                $primaryPosition = max(0, (int) $this->schema_row_value($row, ['primary_position']));
                if ($primaryPosition > 0) {
                    $primaryByTable[$table][$primaryPosition] = $name;
                }
                continue;
            }
            if ($kind !== 'index') {
                continue;
            }
            $name = $this->schema_row_value($row, ['index_name']);
            $column = $this->schema_row_value($row, ['index_column']);
            if ($name === '' || $column === '') {
                continue;
            }
            $position = (int) $this->schema_row_value($row, ['index_position']);
            $indexesByTable[$table][$name]['name'] = $name;
            $indexesByTable[$table][$name]['columns'][$position] = $column;
            $indexesByTable[$table][$name]['unique'] = (int) $this->schema_row_value($row, ['is_unique']) === 1;
            $indexesByTable[$table][$name]['usable'] = (int) $this->schema_row_value($row, ['is_partial']) === 0;
        }
        foreach ($physical as $table => &$tablePhysical) {
            $columns = $columnsByTable[$table] ?? [];
            ksort($columns, SORT_NUMERIC);
            $tablePhysical['columns'] = array_values($columns);
            $primary = $primaryByTable[$table] ?? [];
            $indexes = [];
            if ($primary !== []) {
                ksort($primary, SORT_NUMERIC);
                $indexes[] = [
                    'name' => 'PRIMARY',
                    'columns' => array_values($primary),
                    'unique' => true,
                    'usable' => true,
                ];
            }
            array_push($indexes, ...$this->ordered_schema_indexes($indexesByTable[$table] ?? []));
            $tablePhysical['indexes'] = $indexes;
        }
        unset($tablePhysical);

        return $physical;
    }

    /** @return array{exists:bool,engine:string,columns:string[],definitions:array<string,array<string,mixed>>,indexes:array<int,array<string,mixed>>,inspection_error:string} */
    private function empty_physical_schema(): array
    {
        return [
            'exists' => false,
            'engine' => '',
            'columns' => [],
            'definitions' => [],
            'indexes' => [],
            'inspection_error' => '',
        ];
    }

    /** @param array<int,array{name:string}> $expectedIndexes @return string[] */
    private function sqlite_expected_index_names(string $table, array $expectedIndexes): array
    {
        $names = [];
        $namespaceOffset = strpos($table, 'fts_');
        $namespace = $namespaceOffset === false ? '' : substr($table, 0, $namespaceOffset + 4);
        foreach ($expectedIndexes as $expected) {
            $name = (string) ($expected['name'] ?? '');
            if ($name === '' || strcasecmp($name, 'PRIMARY') === 0) {
                continue;
            }
            foreach ([$name, $table . '_' . $name, $namespace . $name] as $candidate) {
                if ($candidate !== '' && preg_match('/^[A-Za-z0-9_]+$/D', $candidate) === 1) {
                    $names[$candidate] = true;
                }
            }
        }

        return array_keys($names);
    }

    /** Quote a trusted schema name as a SQLite pragma table-valued argument. */
    private function sqlite_schema_literal(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /** Read one exact SQLite index definition without walking peer indexes. */
    private function inspect_named_sqlite_index(string $table, string $name): ?array
    {
        $tableLiteral = $this->sqlite_schema_literal($table);
        $nameLiteral = $this->sqlite_schema_literal($name);
        $rows = $this->schema_rows(
            "SELECT il.name AS index_name, il.\"unique\" AS is_unique,
                    il.partial AS is_partial, ii.seqno AS column_position,
                    ii.name AS column_name
             FROM pragma_index_list({$tableLiteral}) il
             LEFT JOIN pragma_index_info(il.name) ii
             WHERE il.name = {$nameLiteral}
             ORDER BY ii.seqno"
        );
        $this->assert_no_database_error('verify an FTS scope keyset index');
        if ($rows === []) {
            return null;
        }

        $columns = [];
        foreach ($rows as $row) {
            $column = $this->schema_row_value($row, ['column_name']);
            if ($column !== '') {
                $columns[(int) $this->schema_row_value($row, ['column_position'])] = $column;
            }
        }
        ksort($columns, SORT_NUMERIC);

        return [
            'name' => $name,
            'columns' => array_values($columns),
            'unique' => (int) $this->schema_row_value($rows[0], ['is_unique']) === 1,
            'usable' => (int) $this->schema_row_value($rows[0], ['is_partial']) === 0,
        ];
    }

    /** @param array<string,array{name:string,columns:array<int,string>,unique:bool,usable?:bool}> $byName */
    private function ordered_schema_indexes(array $byName): array
    {
        $indexes = [];
        foreach ($byName as $index) {
            ksort($index['columns'], SORT_NUMERIC);
            $indexes[] = [
                'name' => $index['name'],
                'columns' => array_values($index['columns']),
                'unique' => $index['unique'],
                'usable' => $index['usable'] ?? true,
            ];
        }
        return $indexes;
    }

    /** Check whether inspected schema exposes one exact usable index contract. */
    private function schema_has_index(array $indexes, array $expected): bool
    {
        foreach ($indexes as $index) {
            if (
                $index['columns'] === $expected['columns']
                && $index['unique'] === $expected['unique']
                && ($index['usable'] ?? true)
                && ($this->is_sqlite_runtime() || ($index['name'] ?? '') === $expected['name'])
            ) {
                return true;
            }
        }
        return false;
    }

    /** Match an inspected index against the complete allowlist for its table. */
    private function schema_index_is_expected(array $actual, array $expectedIndexes): bool
    {
        foreach ($expectedIndexes as $expected) {
            if (
                ($actual['columns'] ?? []) === $expected['columns']
                && (bool) ($actual['unique'] ?? false) === $expected['unique']
                && ($actual['usable'] ?? true)
                && ($this->is_sqlite_runtime() || ($actual['name'] ?? '') === $expected['name'])
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    private function schema_column_definition_mismatches(string $table, array $physical, array $contract): array
    {
        if ($this->is_sqlite_runtime()) {
            return [];
        }
        $mismatches = [];
        foreach ($contract['mysql_definitions'] ?? [] as $column => $expected) {
            $actual = $physical['definitions'][$column] ?? null;
            if ($actual === null) {
                continue;
            }
            $autoIncrement = str_contains((string) ($actual['extra'] ?? ''), 'auto_increment');
            if (
                ($actual['type'] ?? '') !== $expected['type']
                || (bool) ($actual['nullable'] ?? false) !== $expected['nullable']
                || $autoIncrement !== (bool) ($expected['auto_increment'] ?? false)
            ) {
                $mismatches[] = $table . '.' . $column;
            }
        }

        return $mismatches;
    }

    /** Remove version-dependent integer display widths before schema comparison. */
    private function normalize_mysql_column_type(string $type): string
    {
        $type = strtolower(trim($type));
        $type = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', $type) ?? $type;

        return preg_replace('/\s+/', ' ', $type) ?? $type;
    }

    /** @return array<string,array{df:int,postings:string}> */
    public function get_terms(array $terms): array
    {
        $this->reject_legacy_unbounded_operation();
    }

    /** Legacy point mutations are incompatible with the bounded relational writer. */
    public function replace_doc_postings(int $doc_id, array $term_frequencies): void
    {
        throw new LogicException('Set-oriented storage mutations must use the bounded batch writer.');
    }

    /** Complete posting-list mutations are not a production storage capability. */
    public function put_term(string $term, int $df, string $postings): void
    {
        $this->reject_legacy_unbounded_operation();
    }

    /** Reject legacy point deletion before it can bypass dictionary invariants. */
    public function delete_term(string $term): void
    {
        $this->reject_legacy_unbounded_operation();
    }

    /** @return array<string,array<int,int>> */
    public function get_postings(array $terms): array
    {
        $this->reject_legacy_unbounded_operation();
    }

    /** @return array<string,array<int,int>> */
    public function get_capped_postings(array $terms, int $candidate_cap): array
    {
        $this->reject_legacy_unbounded_operation();
    }

    /** @return array<string,array<int,int>> */
    public function get_budgeted_postings(array $terms, ?int $candidate_cap, int $row_cap): array
    {
        $this->reject_legacy_unbounded_operation();
    }

    /**
     * Production MySQL never materializes posting lists, whole dictionaries,
     * whole document-id sets, or prefix expansions in PHP.
     *
     * These methods remain only because the component compatibility interfaces
     * are shared with the in-memory and file fixtures. Failing here is safer
     * than making a diagnostic or third-party caller an accidental unbounded
     * production search path.
     */
    private function reject_legacy_unbounded_operation(): never
    {
        throw new BadMethodCallException(
            'The production MySQL backend exposes collections and mutations only through bounded set-oriented operations.'
        );
    }

    /**
     * Partition every per-document poison value before replacement planning.
     *
     * This is a pure-PHP pass: one invalid document cannot trigger another
     * old-posting frontier query for each remaining document in the batch.
     * Aggregate posting/term limits still belong to the batch writer below.
     *
     * @param array<int,array<string,mixed>> $docs
     * @return array{documents:array<int,array<string,mixed>>,rejections:WP_FTS_Prepared_Document_Rejected[],deferred_post_ids:int[]}
     */
    public function partition_prepared_documents(array $docs): array
    {
        if (count($docs) > self::MAX_BATCH_DOCUMENTS) {
            throw new InvalidArgumentException(
                'Prepared FTS batch exceeds the ' . self::MAX_BATCH_DOCUMENTS . '-document validation contract.'
            );
        }
        $documents = [];
        $rejections = [];
        $acceptedPostIds = [];
        foreach ($docs as $offset => $doc) {
            try {
                $normalized = $this->normalize_prepared_document($doc, $offset);
                $postId = $normalized['post_id'];
                if (isset($acceptedPostIds[$postId])) {
                    throw new WP_FTS_Prepared_Document_Rejected(
                        $postId,
                        'duplicate_post_id',
                        "Prepared FTS batch contains post {$postId} more than once."
                    );
                }
                $acceptedPostIds[$postId] = true;
                $documents[] = $normalized;
            } catch (WP_FTS_Prepared_Document_Rejected $error) {
                $rejections[] = $error;
            }
        }

        $deferredPostIds = [];
        if ($this->is_sqlite_runtime() && $documents !== []) {
            $transport = $this->sqlite_prepared_transport_prefix($documents);
            $acceptedDocuments = $transport['accepted_documents'];
            if ($acceptedDocuments === 0) {
                $rejected = array_shift($documents);
                $postId = max(0, (int) ($rejected['post_id'] ?? 0));
                $rejections[] = new WP_FTS_Prepared_Document_Rejected(
                    $postId,
                    'sqlite_transport_limit',
                    "Prepared FTS document {$postId} cannot fit one SQLite dictionary write and identity read inside the 4 MiB statement contract."
                );
                $deferredPostIds = array_map(
                    static fn(array $document): int => max(0, (int) ($document['post_id'] ?? 0)),
                    $documents
                );
                $documents = [];
            } elseif ($acceptedDocuments < count($documents)) {
                $deferredPostIds = array_map(
                    static fn(array $document): int => max(0, (int) ($document['post_id'] ?? 0)),
                    array_slice($documents, $acceptedDocuments)
                );
                $documents = array_slice($documents, 0, $acceptedDocuments);
            }
        }

        return [
            'documents' => $documents,
            'rejections' => $rejections,
            'deferred_post_ids' => $deferredPostIds,
        ];
    }

    /**
     * Atomically replace a bounded, already-analyzed document batch.
     *
     * The caller must split larger batches; rejecting an oversized batch keeps
     * packet size and the number of data statements deterministic.
     *
     * @param array<int,array<string,mixed>> $docs
     * @param int[] $delete_ids
     * @return array{replaced:int,deleted:int,terms:int,postings:int,old_postings:int,posting_mutations:int}
     */
    public function replace_prepared_documents(
        array $docs,
        array $delete_ids = [],
        ?WP_FTS_Prepared_Replacement_Plan $replacement_plan = null
    ): array
    {
        if (count($docs) > self::MAX_BATCH_DOCUMENTS) {
            throw new InvalidArgumentException(
                'Prepared FTS batch exceeds the ' . self::MAX_BATCH_DOCUMENTS . '-document writer contract; split the batch before retrying.'
            );
        }
        if (count($delete_ids) > self::MAX_BATCH_DOCUMENTS) {
            throw new InvalidArgumentException(
                'Prepared FTS batch accepts at most ' . self::MAX_BATCH_DOCUMENTS . ' raw document deletions; split the batch before retrying.'
            );
        }

        $prepared = [];
        $totalPostings = 0;
        $batchTerms = [];
        foreach ($docs as $offset => $doc) {
            $normalized = $this->normalize_prepared_document($doc, $offset);
            $postId = $normalized['post_id'];
            if (isset($prepared[$postId])) {
                throw new WP_FTS_Prepared_Document_Rejected(
                    $postId,
                    'duplicate_post_id',
                    "Prepared FTS batch contains post {$postId} more than once."
                );
            }

            $frequencyMaps = [
                self::LEXICAL_KIND => $normalized['term_frequencies'],
                self::SURFACE_KIND => $normalized['surface_frequencies'],
            ];
            $projectedPostings = $totalPostings
                + count($normalized['term_frequencies'])
                + count($normalized['surface_frequencies']);
            $newTerms = [];
            foreach ($frequencyMaps as $kind => $frequencies) {
                foreach (array_keys($frequencies) as $key) {
                    $storageKey = $kind . "\0" . $key;
                    if (!isset($batchTerms[$storageKey])) {
                        $newTerms[$storageKey] = true;
                    }
                }
            }
            $projectedTerms = count($batchTerms) + count($newTerms);
            if ($projectedPostings > self::MAX_BATCH_POSTINGS || $projectedTerms > self::MAX_BATCH_TERMS) {
                $limitKind = $projectedTerms > self::MAX_BATCH_TERMS ? 'terms' : 'postings';
                throw new WP_FTS_Prepared_Batch_Split_Required(
                    count($docs),
                    $projectedPostings,
                    count($prepared),
                    self::MAX_BATCH_POSTINGS,
                    $projectedTerms,
                    self::MAX_BATCH_TERMS,
                    $limitKind
                );
            }
            $totalPostings = $projectedPostings;
            foreach ($newTerms as $key => $_present) {
                $batchTerms[$key] = true;
            }
            $prepared[$postId] = $normalized;
        }
        if ($this->is_sqlite_runtime() && $prepared !== []) {
            $transport = $this->sqlite_prepared_transport_prefix(array_values($prepared));
            $acceptedDocuments = $transport['accepted_documents'];
            if ($acceptedDocuments === 0) {
                $postId = (int) array_key_first($prepared);
                throw new WP_FTS_Prepared_Document_Rejected(
                    $postId,
                    'sqlite_transport_limit',
                    "Prepared FTS document {$postId} cannot fit one SQLite dictionary write and identity read inside the 4 MiB statement contract."
                );
            }
            if ($acceptedDocuments < count($prepared)) {
                throw new WP_FTS_Prepared_Batch_Split_Required(
                    count($prepared),
                    $totalPostings,
                    $acceptedDocuments,
                    self::MAX_BATCH_POSTINGS,
                    count($batchTerms),
                    self::MAX_BATCH_TERMS,
                    'sqlite_transport'
                );
            }
        }
        // Validation is complete. Keeping this maximum-width key set alive
        // would duplicate the identity map built below throughout every SQL
        // render and the transaction without contributing to publication.
        unset($batchTerms, $transport);
        $deleteIds = [];
        foreach ($delete_ids as $offset => $id) {
            if (!is_scalar($id) || (is_string($id) && strlen($id) > self::MAX_NUMERIC_INPUT_BYTES)) {
                throw new WP_FTS_Prepared_Document_Rejected(
                    0,
                    'invalid_delete_id',
                    "Prepared FTS deletion at batch offset {$offset} must be a bounded positive integer post id."
                );
            }
            $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($id)) {
                throw new WP_FTS_Prepared_Document_Rejected(
                    0,
                    'invalid_delete_id',
                    "Prepared FTS deletion at batch offset {$offset} must be a positive integer post id."
                );
            }
            if (!isset($prepared[$id])) {
                $deleteIds[$id] = true;
            }
        }
        $affectedPosts = [...array_keys($prepared), ...array_keys($deleteIds)];
        if (count($affectedPosts) > self::MAX_BATCH_DOCUMENTS) {
            throw new InvalidArgumentException(
                'Prepared FTS batch exceeds the ' . self::MAX_BATCH_DOCUMENTS . '-document replace/delete contract; split the batch before retrying.'
            );
        }
        if ($affectedPosts === []) {
            return [
                'replaced' => 0,
                'deleted' => 0,
                'terms' => 0,
                'postings' => 0,
                'old_postings' => 0,
                'posting_mutations' => 0,
            ];
        }

        $newPostingCounts = [];
        foreach ($prepared as $postId => $doc) {
            $newPostingCounts[$postId] = count($doc['term_frequencies']) + count($doc['surface_frequencies']);
        }
        foreach (array_keys($deleteIds) as $postId) {
            $newPostingCounts[$postId] = 0;
        }
        ksort($newPostingCounts, SORT_NUMERIC);

        $planWasProvided = $replacement_plan !== null;
        $replacement_plan ??= $this->plan_prepared_replacement($newPostingCounts);
        if (!$planWasProvided && $replacement_plan->deferred_post_ids !== []) {
            throw new WP_FTS_Prepared_Batch_Split_Required(
                count($newPostingCounts),
                self::MAX_BATCH_POSTINGS + 1,
                count($replacement_plan->admitted_post_ids),
                self::MAX_BATCH_POSTINGS
            );
        }
        $issuedPlan = $this->issuedReplacementPlans[$replacement_plan] ?? null;
        if (
            $issuedPlan === null
            || $replacement_plan->new_posting_counts !== $newPostingCounts
            || $replacement_plan->admitted_post_ids !== array_keys($newPostingCounts)
            || $replacement_plan->old_posting_counts !== $issuedPlan['old']
            || $replacement_plan->new_posting_counts !== $issuedPlan['new']
            || $replacement_plan->posting_mutations !== $issuedPlan['mutations']
        ) {
            throw new InvalidArgumentException(
                'Prepared FTS replacement plans must be measured by this storage instance for the exact document prefix.'
            );
        }
        unset($this->issuedReplacementPlans[$replacement_plan]);

        [$identities, $newDocumentCounts] = $this->prepared_term_identities(array_values($prepared));
        $expectedPostingRows = array_sum($replacement_plan->new_posting_counts);

        $ownsTransaction = !$this->transactionActive;
        if ($ownsTransaction) {
            $this->begin_transaction();
        } else {
            $this->guard_mutation();
        }
        try {
            // Keep new-identity UPSERT and old-frequency decrement separate.
            // In particular, never INSERT ... SELECT from the dictionary back
            // into itself: MariaDB can probe and lock fragmented identity
            // leaves far beyond the measured posting frontier on that shape.
            $this->insert_term_identities($identities, $newDocumentCounts);
            $postsWithOldPostings = array_keys(array_filter(
                $replacement_plan->old_posting_counts,
                static fn(int $count): bool => $count > 0
            ));
            if ($postsWithOldPostings !== []) {
                $this->decrement_doc_freq_for_posts($postsWithOldPostings);
            }
            $retiredPosts = array_values(array_unique([
                ...$postsWithOldPostings,
                ...array_keys($deleteIds),
            ]));
            if ($retiredPosts !== []) {
                // One bounded, post-first DELETE removes the measured old
                // postings, their now-empty dictionary rows, and deletion-only
                // projections. A fresh prepared document has no retirement work.
                $this->delete_replaced_index_rows($retiredPosts, array_keys($deleteIds));
            }
            if ($expectedPostingRows > 0) {
                // Build each large statement only after the previous one has
                // executed and its local renderer graph has been released.
                // A database adapter may retain its last SQL string, so keeping
                // another 8,192-tuple PHP row graph beside it would defeat the
                // low-memory host contract.
                // The compact literal relation keeps the complete maximum in
                // one indexed read even when every identity has maximum width.
                $termIds = [];
                foreach (array_chunk($identities, self::MAX_TERM_RESOLUTION_IDENTITIES, true) as $identityChunk) {
                    [$termResolutionStatement, $keysByOrdinal] = $this->prepare_posting_replacement(
                        $identityChunk
                    );
                    foreach ($this->resolve_prepared_term_ordinals($termResolutionStatement, $keysByOrdinal) as $key => $termId) {
                        $termIds[$key] = $termId;
                    }
                    unset($termResolutionStatement, $keysByOrdinal);
                }
                $postingStatement = $this->resolved_posting_insert(
                    array_values($prepared),
                    $termIds,
                    $expectedPostingRows
                );
                $affectedPostingRows = $this->query($postingStatement, 'insert resolved FTS posting rows');
                if ($affectedPostingRows !== $expectedPostingRows) {
                    throw new RuntimeException(
                        "The FTS posting INSERT wrote {$affectedPostingRows} of {$expectedPostingRows} expected rows."
                    );
                }
            }
            $this->upsert_documents(array_values($prepared));
            // Commit publishes score/membership changes and their cursor
            // boundary together. A page planned before this transaction may
            // finish, but its cursor cannot be replayed against the new state.
            $this->transactionMutated = true;
            if ($ownsTransaction) {
                $this->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                $this->rollback();
            }
            throw $e;
        }

        return [
            'replaced' => count($prepared),
            'deleted' => count($deleteIds),
            'terms' => count($identities),
            'postings' => $expectedPostingRows,
            'old_postings' => array_sum($replacement_plan->old_posting_counts),
            'posting_mutations' => $replacement_plan->posting_mutations,
        ];
    }

    /** @return array{post_id:int,primary_lang:string,content_hash:string,snippet_text:string,term_frequencies:array<string,int>,surface_frequencies:array<string,int>} */
    private function normalize_prepared_document(mixed $doc, int|string $offset): array
    {
        if (!is_array($doc)) {
            throw new WP_FTS_Prepared_Document_Rejected(
                0,
                'invalid_shape',
                "Prepared FTS document at batch offset {$offset} must be an array."
            );
        }
        $rawPostId = $doc['doc_id'] ?? $doc['post_id'] ?? null;
        if (!is_scalar($rawPostId) || (is_string($rawPostId) && strlen($rawPostId) > self::MAX_NUMERIC_INPUT_BYTES)) {
            throw new WP_FTS_Prepared_Document_Rejected(
                0,
                'invalid_post_id',
                "Prepared FTS document at batch offset {$offset} must have a bounded positive integer post id."
            );
        }
        $postId = filter_var($rawPostId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($postId)) {
            throw new WP_FTS_Prepared_Document_Rejected(
                0,
                'invalid_post_id',
                "Prepared FTS document at batch offset {$offset} must have a positive integer post id."
            );
        }
        if (isset($doc['term_frequencies']) && !is_array($doc['term_frequencies'])) {
            throw new WP_FTS_Prepared_Document_Rejected(
                $postId,
                'invalid_term_frequencies',
                "Prepared FTS document {$postId} has a non-array term frequency map."
            );
        }
        if (isset($doc['surface_frequencies']) && !is_array($doc['surface_frequencies'])) {
            throw new WP_FTS_Prepared_Document_Rejected(
                $postId,
                'invalid_surface_frequencies',
                "Prepared FTS document {$postId} has a non-array surface frequency map."
            );
        }
        if (count($doc['term_frequencies'] ?? []) > WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_TERMS) {
            throw new WP_FTS_Prepared_Document_Rejected(
                $postId,
                'term_limit',
                "Prepared FTS document {$postId} exceeds the " . WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_TERMS . '-term writer contract.'
            );
        }
        if (count($doc['surface_frequencies'] ?? []) > WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES) {
            throw new WP_FTS_Prepared_Document_Rejected(
                $postId,
                'surface_limit',
                "Prepared FTS document {$postId} exceeds the " . WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES . '-surface writer contract.'
            );
        }
        if (count($doc['term_frequencies'] ?? []) + count($doc['surface_frequencies'] ?? []) > self::MAX_DOCUMENT_POSTINGS) {
            throw new WP_FTS_Prepared_Document_Rejected(
                $postId,
                'posting_limit',
                "Prepared FTS document {$postId} exceeds the " . self::MAX_DOCUMENT_POSTINGS . '-posting writer contract.'
            );
        }
        foreach ([...array_values($doc['term_frequencies'] ?? []), ...array_values($doc['surface_frequencies'] ?? [])] as $tf) {
            if (is_string($tf) && strlen($tf) > self::MAX_NUMERIC_INPUT_BYTES) {
                throw new WP_FTS_Prepared_Document_Rejected(
                    $postId,
                    'invalid_term_frequency',
                    "Prepared FTS document {$postId} has an overlong term frequency."
                );
            }
            if (!is_int($tf) && !(is_string($tf) && filter_var($tf, FILTER_VALIDATE_INT) !== false)) {
                throw new WP_FTS_Prepared_Document_Rejected(
                    $postId,
                    'invalid_term_frequency',
                    "Prepared FTS document {$postId} has a non-integer term frequency."
                );
            }
        }
        try {
            $frequencies = $this->normalize_term_frequencies($doc['term_frequencies'] ?? []);
            $surfaceFrequencies = $this->normalize_surface_frequencies($doc['surface_frequencies'] ?? []);
        } catch (InvalidArgumentException) {
            throw new WP_FTS_Prepared_Document_Rejected(
                $postId,
                'invalid_term_identity',
                "Prepared FTS document {$postId} contains an invalid term identity."
            );
        }
        if (count($frequencies) + count($surfaceFrequencies) > self::MAX_DOCUMENT_POSTINGS) {
            throw new WP_FTS_Prepared_Document_Rejected(
                $postId,
                'posting_limit',
                "Prepared FTS document {$postId} exceeds the " . self::MAX_DOCUMENT_POSTINGS . '-posting writer contract.'
            );
        }
        if (isset($doc['metadata']) && $doc['metadata'] !== null && !is_array($doc['metadata'])) {
            throw new WP_FTS_Prepared_Document_Rejected(
                $postId,
                'invalid_metadata',
                "Prepared FTS document {$postId} has invalid metadata."
            );
        }
        $metadata = is_array($doc['metadata'] ?? null) ? $doc['metadata'] : [];
        if (
            (isset($metadata['search_text']) && !is_scalar($metadata['search_text']))
            || (isset($metadata['content_search_text']) && !is_scalar($metadata['content_search_text']))
            || (isset($doc['snippet_text']) && !is_scalar($doc['snippet_text']))
        ) {
            throw new WP_FTS_Prepared_Document_Rejected(
                $postId,
                'invalid_snippet',
                "Prepared FTS document {$postId} has a non-scalar snippet source."
            );
        }
        if (isset($doc['primary_lang']) && !is_scalar($doc['primary_lang'])) {
            throw new WP_FTS_Prepared_Document_Rejected(
                $postId,
                'invalid_language',
                "Prepared FTS document {$postId} has a non-scalar primary language."
            );
        }
        if (isset($doc['content_hash']) && !is_scalar($doc['content_hash'])) {
            throw new WP_FTS_Prepared_Document_Rejected(
                $postId,
                'invalid_content_hash',
                "Prepared FTS document {$postId} has a non-scalar content hash."
            );
        }
        $rawPrimaryLang = (string) ($doc['primary_lang'] ?? 'und');
        if (strlen($rawPrimaryLang) > self::MAX_LANGUAGE_INPUT_BYTES) {
            throw new WP_FTS_Prepared_Document_Rejected(
                $postId,
                'invalid_language',
                "Prepared FTS document {$postId} has a primary language input longer than 64 bytes."
            );
        }
        $primaryLang = WP_FTS_TermNamespace::canonicalize_lang($rawPrimaryLang, 'und');
        if (strlen($primaryLang) > 32) {
            throw new WP_FTS_Prepared_Document_Rejected(
                $postId,
                'invalid_language',
                "Prepared FTS document {$postId} has a primary language longer than 32 bytes."
            );
        }
        $snippetSource = $metadata['content_search_text']
            ?? $metadata['search_text']
            ?? $doc['snippet_text']
            ?? '';
        $snippet = is_scalar($snippetSource) ? (string) $snippetSource : '';
        if (strlen($snippet) > WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES) {
            throw new WP_FTS_Prepared_Document_Rejected(
                $postId,
                'invalid_snippet',
                "Prepared FTS document {$postId} has a snippet source longer than 2 MiB."
            );
        }

        return [
            'post_id' => $postId,
            'primary_lang' => $primaryLang,
            'content_hash' => is_scalar($doc['content_hash'] ?? null) ? substr((string) $doc['content_hash'], 0, 64) : '',
            // Canonical post fields remain in wp_posts. Persist only the
            // bounded snippet needed to hydrate one result page.
            'snippet_text' => WP_FTS_Utf8::truncate_bytes(
                substr($snippet, 0, self::MAX_SNIPPET_BYTES),
                self::MAX_SNIPPET_BYTES
            ),
            'term_frequencies' => $frequencies,
            'surface_frequencies' => $surfaceFrequencies,
        ];
    }

    /**
     * Measure one complete ascending document prefix before opening a write transaction.
     *
     * The inner LIMIT reads at most one row beyond the transaction posting
     * budget from the post-first covering index. The outer GROUP BY returns at
     * most one count per requested document, not 20,001 posting rows to PHP.
     * If the limit cuts through a document, that frontier and every later id
     * are deferred; only counts for the provably complete prefix are admitted.
     *
     * @param array<int,int> $new_posting_counts Post id => number of new rows;
     *        use zero for a deletion.
     */
    public function plan_prepared_replacement(array $new_posting_counts): WP_FTS_Prepared_Replacement_Plan
    {
        if (count($new_posting_counts) > self::MAX_BATCH_DOCUMENTS) {
            throw new InvalidArgumentException(
                'Prepared FTS replacement planning accepts at most ' . self::MAX_BATCH_DOCUMENTS . ' documents.'
            );
        }
        $normalized = [];
        foreach ($new_posting_counts as $rawPostId => $rawCount) {
            if (
                !is_scalar($rawPostId)
                || (is_string($rawPostId) && strlen($rawPostId) > self::MAX_NUMERIC_INPUT_BYTES)
                || !is_scalar($rawCount)
                || (is_string($rawCount) && strlen($rawCount) > self::MAX_NUMERIC_INPUT_BYTES)
            ) {
                throw new InvalidArgumentException('Prepared FTS replacement counts must use bounded integer ids and values.');
            }
            $postId = filter_var($rawPostId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $postingCount = filter_var($rawCount, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => self::MAX_BATCH_TERMS],
            ]);
            if (!is_int($postId) || !is_int($postingCount) || isset($normalized[$postId])) {
                throw new InvalidArgumentException(
                    'Prepared FTS replacement counts require unique positive post ids and at most '
                    . self::MAX_BATCH_TERMS . ' new postings per document.'
                );
            }
            $normalized[$postId] = $postingCount;
        }
        ksort($normalized, SORT_NUMERIC);
        if ($normalized === []) {
            return new WP_FTS_Prepared_Replacement_Plan([], [], [], [], 0, 0);
        }

        $this->guard_mutation();
        $postIds = array_keys($normalized);
        $scanLimit = self::MAX_BATCH_POSTINGS + 1;
        $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
        $indexHint = $this->is_sqlite_runtime() ? '' : ' FORCE INDEX (post_term_impact)';
        $rows = $this->get_results($this->wpdb->prepare(
            "/* wp_fts:replacement-frontier */
	SELECT capped.post_id, COUNT(*) AS posting_count
	FROM (
	    SELECT old_posting.post_id
    FROM {$this->postingsTable} old_posting{$indexHint}
    WHERE old_posting.post_id IN ({$placeholders})
    ORDER BY old_posting.post_id ASC, old_posting.term_id ASC
    LIMIT {$scanLimit}
	) AS capped
	GROUP BY capped.post_id
	ORDER BY capped.post_id ASC",
            ...$postIds
        ), 'measure bounded old FTS posting frontier');

        $oldPostingCounts = [];
        $scannedOldPostings = 0;
        foreach ($rows as $row) {
            $postId = max(0, (int) ($row->post_id ?? 0));
            $postingCount = max(0, (int) ($row->posting_count ?? 0));
            if (
                !isset($normalized[$postId])
                || isset($oldPostingCounts[$postId])
                || $postingCount <= 0
                || $postingCount > $scanLimit
                || $scannedOldPostings > $scanLimit - $postingCount
            ) {
                throw new RuntimeException('The bounded old FTS posting frontier returned an invalid aggregate.');
            }
            $oldPostingCounts[$postId] = $postingCount;
            $scannedOldPostings += $postingCount;
        }

        $frontierPostId = $scannedOldPostings === $scanLimit
            ? max(array_keys($oldPostingCounts))
            : null;
        $admittedPostIds = [];
        $admittedNewCounts = [];
        $admittedOldCounts = [];
        $postingMutations = 0;
        foreach ($normalized as $postId => $newPostingCount) {
            if ($frontierPostId !== null && $postId >= $frontierPostId) {
                break;
            }
            $oldPostingCount = $oldPostingCounts[$postId] ?? 0;
            if ($postingMutations + $oldPostingCount + $newPostingCount > self::MAX_BATCH_POSTINGS) {
                break;
            }
            $admittedPostIds[] = $postId;
            $admittedNewCounts[$postId] = $newPostingCount;
            $admittedOldCounts[$postId] = $oldPostingCount;
            $postingMutations += $oldPostingCount + $newPostingCount;
        }
        if ($admittedPostIds === []) {
            $firstPostId = (int) array_key_first($normalized);
            throw new RuntimeException(
                "Existing postings for FTS document {$firstPostId} cannot fit the bounded replacement transaction."
            );
        }
        $admitted = array_fill_keys($admittedPostIds, true);
        $deferredPostIds = array_values(array_filter(
            $postIds,
            static fn(int $postId): bool => !isset($admitted[$postId])
        ));
        $plan = new WP_FTS_Prepared_Replacement_Plan(
            $admittedNewCounts,
            $admittedOldCounts,
            $admittedPostIds,
            $deferredPostIds,
            $scannedOldPostings,
            $postingMutations
        );
        $this->issuedReplacementPlans[$plan] = [
            'new' => $admittedNewCounts,
            'old' => $admittedOldCounts,
            'mutations' => $postingMutations,
        ];

        return $plan;
    }

    /** Verify only a signed cursor envelope when no searchable scope exists. */
    public function assert_search_cursor_authenticity(string $cursor): void
    {
        // Empty-scope WordPress adapters have no query plan to fingerprint,
        // but must still reject forged cursor envelopes before their 0-SQL
        // return. A nonempty search performs the full query/epoch check below.
        $this->assert_cursor_input_bounds($cursor);
        $this->decode_cursor_payload($cursor);
    }

    /**
     * Resolve and execute one exact set-oriented page.
     *
     * Exact dictionary alternatives are resolved in one statement. An AND query
     * with an impossible mandatory non-prefix group returns immediately. Every
     * other query executes one ranking statement and returns at most K+1 rows.
     *
     * @param array<int,array<int,array<string,mixed>>> $groups
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function search_page(array $groups, array $options): array
    {
        $this->assert_search_option_bounds($options);
        $plan = $this->normalize_search_plan($groups, $options);
        if ($plan['groups'] === []) {
            if (isset($options['cursor']) && trim((string) $options['cursor']) !== '') {
                throw new InvalidArgumentException('Search cursor cannot be used with an empty query plan.');
            }
            return $this->empty_search_page((string) ($options['query_lang'] ?? 'und'));
        }

        $prefix = $this->search_prefix_descriptor($plan, $options);
        $mode = strtoupper((string) ($options['mode'] ?? 'OR'));
        // This is the only dictionary planning statement. It joins the bounded
        // requested lexical identities and sums document frequency over one
        // gated indexed surface range without reading posting rows.
        [$resolved, $surfaceAvailable, $surfaceDocFreq, $searchEpoch, $searchEpochIncarnation, $reconciliationScopeActive] = $this->resolve_search_plan_terms(
            array_keys($plan['keys']),
            $prefix,
            $options,
            $plan['groups'],
            $mode
        );
        $options['_search_epoch_generation'] = $searchEpoch;
        $options['_search_epoch_incarnation'] = $searchEpochIncarnation;
        $cursorFingerprint = $this->search_cursor_fingerprint(
            $plan,
            $prefix,
            $mode,
            $options,
            $searchEpoch,
            $searchEpochIncarnation
        );
        $cursor = $this->decode_cursor(
            isset($options['cursor']) && is_scalar($options['cursor']) ? (string) $options['cursor'] : null,
            $cursorFingerprint
        );
        if ($reconciliationScopeActive) {
            throw new WP_FTS_Search_Unavailable('Full-text search is unavailable while reconciliation is pending.');
        }
        $recencyStrength = is_numeric($options['recency_boost_strength'] ?? null)
            ? max(0.0, min(self::MAX_RECENCY_BOOST_STRENGTH, (float) $options['recency_boost_strength']))
            : 0.0;
        if ($recencyStrength > 0.0 && $cursor !== null && (string) ($cursor['now_gmt'] ?? '') === '') {
            throw new InvalidArgumentException('Recency search cursor is missing its scoring epoch.');
        }
        $resolvedGroups = [];
        foreach ($plan['groups'] as $groupId => $alternatives) {
            $resolvedGroups[$groupId] = [];
            foreach ($alternatives as $alternative) {
                $key = $alternative['key'];
                if (!isset($resolved[$key])) {
                    continue;
                }
                $row = $resolved[$key];
                $rank = max(0, (int) $alternative['rank']);
                $tier = $rank === 0 ? self::PRIMARY_WEIGHT : self::SECONDARY_WEIGHT;
                $resolvedGroups[$groupId][] = [
                    'term_id' => (int) $row['term_id'],
                    'doc_freq' => max(1, (int) $row['doc_freq']),
                    'weight' => max(1, intdiv(self::rarity_weight((int) $row['doc_freq']) * $tier, 1000)),
                ];
            }
        }
        $resolvedAlternativeCount = array_sum(array_map('count', $resolvedGroups));

        if ($mode === 'AND') {
            foreach (array_keys($plan['groups']) as $groupId) {
                if (($resolvedGroups[$groupId] ?? []) === [] && ($prefix === null || $prefix['group_id'] !== $groupId)) {
                    return $this->empty_search_page((string) ($options['query_lang'] ?? 'und'));
                }
                if (
                    ($resolvedGroups[$groupId] ?? []) === []
                    && $prefix !== null
                    && $prefix['group_id'] === $groupId
                    && !$surfaceAvailable
                ) {
                    return $this->empty_search_page((string) ($options['query_lang'] ?? 'und'));
                }
            }
        }
        if ($resolvedAlternativeCount === 0 && ($prefix === null || !$surfaceAvailable)) {
            return $this->empty_search_page((string) ($options['query_lang'] ?? 'und'));
        }

        // Keep the typed prefix in the cursor fingerprint above, but do not
        // carry an empty surface arm into ranking. The planning probe has
        // already proved this range has no dictionary row, so exact matches
        // can use the cheaper term-id path without a post-first classifier.
        $effectivePrefix = $surfaceAvailable ? $prefix : null;
        if ($effectivePrefix !== null) {
            $effectivePrefix['doc_freq'] = $surfaceDocFreq;
        }
        $rankQuery = $this->build_rank_query(
            $resolvedGroups,
            count($plan['groups']),
            $effectivePrefix,
            $mode,
            $options,
            $cursor
        );
        if (strlen($rankQuery['sql']) > self::MAX_SEARCH_SQL_BYTES) {
            $this->throw_search_plan_limit('generated SQL');
        }
        $statement = $rankQuery['args'] === []
            ? $rankQuery['sql']
            : $this->wpdb->prepare($rankQuery['sql'], ...$rankQuery['args']);
        $rows = $this->get_results($statement, 'execute set-oriented FTS page');
        if ($rows === [] || (int) ($rows[0]->snapshot_ready ?? 0) !== 1) {
            throw new WP_FTS_Search_Unavailable('Full-text search publication changed while ranking the page.');
        }
        // The outer snapshot relation always returns one row. A NULL doc_id is
        // the control-only row for a valid zero-hit page, not a search result.
        $rows = array_values(array_filter(
            $rows,
            static fn(object $row): bool => max(0, (int) ($row->doc_id ?? 0)) > 0
        ));
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, (int) ($options['page_size'] ?? 10)));
        $includeCanonicalPostRow = !empty($options['include_canonical_post_row']);
        $includeMetadata = !empty($options['include_metadata']);
        $includeSnippets = !empty($options['include_snippets']);
        if ($includeCanonicalPostRow || $includeMetadata) {
            $pageWindow = $this->canonical_page_window($rows, $pageSize);
            $visibleRows = $pageWindow['rows'];
            $firstScannedRow = $pageWindow['first_scanned'];
            $lastScannedRow = $pageWindow['last_scanned'];
            $hasMore = $pageWindow['has_uninspected'] || count($rows) > $pageSize;
        } else {
            $visibleRows = array_slice($rows, 0, $pageSize);
            $firstScannedRow = $visibleRows[0] ?? null;
            $lastScannedRow = $visibleRows === [] ? null : $visibleRows[count($visibleRows) - 1];
            $hasMore = count($rows) > $pageSize;
        }
        $reverse = ($rankQuery['reverse'] ?? false) === true;
        if ($reverse) {
            // A reverse query is ordered from the cursor outwards. Remove the
            // farthest lookahead row before restoring canonical descending
            // order, otherwise the nearest row is dropped from every page.
            $visibleRows = array_reverse($visibleRows);
        }
        $hydrated = ($includeMetadata || $includeSnippets || $includeCanonicalPostRow)
            ? $this->hydrate_search_rows(
                $visibleRows,
                $includeMetadata,
                $includeSnippets,
                $includeCanonicalPostRow,
                $options
            )
            : [];
        $results = [];
        foreach ($visibleRows as $row) {
            $docId = (int) $row->doc_id;
            $detail = $hydrated[$docId] ?? null;
            if (($includeMetadata || $includeSnippets || $includeCanonicalPostRow) && !is_object($detail)) {
                throw new WP_FTS_Search_Unavailable('Full-text search visibility changed during page hydration.');
            }
            $result = [
                'doc_id' => $docId,
                'score' => (float) $row->score,
            ];
            if ($includeMetadata && is_object($detail)) {
                $result += [
                    'post_id' => $docId,
                    'post_type' => (string) ($detail->post_type ?? ''),
                    'post_status' => (string) ($detail->post_status ?? ''),
                    'post_date_gmt' => (string) ($detail->post_date_gmt ?? ''),
                    'title' => (string) ($detail->post_title ?? ''),
                    'excerpt' => (string) ($detail->post_excerpt ?? ''),
                    'primary_lang' => (string) ($detail->primary_lang ?? 'und'),
                ];
            }
            if ($includeSnippets && is_object($detail)) {
                $result['snippet_text'] = WP_FTS_Utf8::truncate_bytes(
                    (string) ($detail->snippet_text ?? ''),
                    self::MAX_SNIPPET_BYTES
                );
                $result['primary_lang'] = (string) ($detail->primary_lang ?? 'und');
            }
            if ($includeCanonicalPostRow && is_object($detail)) {
                $postRow = [];
                foreach (self::CANONICAL_POST_COLUMNS as $column) {
                    $alias = 'canonical_post_' . $column;
                    $postRow[$column] = $detail->{$alias} ?? '';
                }
                $result['_canonical_post_row'] = $postRow;
            }
            $results[] = $result;
        }

        $hasInputCursor = $cursor !== null;
        $scoringNow = is_scalar($rankQuery['scoring_now_gmt'] ?? null)
            ? (string) $rankQuery['scoring_now_gmt']
            : '';
        $nextCursor = null;
        $previousCursor = null;
        if ($firstScannedRow !== null && $lastScannedRow !== null) {
            if ($reverse) {
                $previousCursor = $hasMore
                    ? $this->encode_cursor($lastScannedRow, $cursorFingerprint, $scoringNow)
                    : null;
                $nextCursor = $hasInputCursor
                    ? $this->encode_cursor($firstScannedRow, $cursorFingerprint, $scoringNow)
                    : null;
            } else {
                $nextCursor = $hasMore
                    ? $this->encode_cursor($lastScannedRow, $cursorFingerprint, $scoringNow)
                    : null;
                $previousCursor = $hasInputCursor
                    ? $this->encode_cursor($firstScannedRow, $cursorFingerprint, $scoringNow)
                    : null;
            }
        }

        $payload = [
            'results' => $results,
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
            'previous_cursor' => $previousCursor,
            'query_lang' => WP_FTS_TermNamespace::canonicalize_lang((string) ($options['query_lang'] ?? 'und'), 'und'),
        ];
        if (!empty($options['explain'])) {
            $recencyStrength = is_numeric($options['recency_boost_strength'] ?? null)
                ? max(0.0, min(self::MAX_RECENCY_BOOST_STRENGTH, (float) $options['recency_boost_strength']))
                : 0.0;
            $recencyHalfLife = is_numeric($options['recency_boost_half_life_days'] ?? null)
                ? max(1.0, min(3650.0, (float) $options['recency_boost_half_life_days']))
                : 30.0;
            $payload['explain'] = [
                'storage' => 'set_oriented_v6',
                'logical_group_count' => count($plan['groups']),
                'resolved_alternatives' => $resolvedAlternativeCount,
                'anchor_group' => $rankQuery['anchor_group'],
                'prefix_range' => $effectivePrefix !== null,
                'prefix_strategy' => (string) ($rankQuery['prefix_strategy'] ?? 'none'),
                'query_statements' => ($includeMetadata || $includeSnippets || $includeCanonicalPostRow) ? 3 : 2,
                'interactive_total' => 'unknown',
                'recency_boost' => [
                    'enabled' => $recencyStrength > 0.0,
                    'strength' => $recencyStrength,
                    'half_life_days' => $recencyHalfLife,
                    'scoring_now_gmt' => $scoringNow,
                ],
                'canonical_page_bytes' => $includeCanonicalPostRow
                    ? array_sum(array_map(static fn(object $row): int => max(0, (int) ($row->canonical_post_bytes ?? 0)), $visibleRows))
                    : 0,
            ];
        }
        return $payload;
    }

    /**
     * Keep a WordPress hydration page ordered and bounded without truncating a row.
     *
     * A large canonical or metadata result therefore produces a shorter cursor
     * page, not a partial row. Every uninspected result remains reachable from
     * the next cursor and hydration stays the third and final plugin-owned
     * statement.
     *
     * @param object[] $rows Ranked rows in cursor order, including lookahead.
     * The cursor boundary follows every inspected row, including an oversized
     * legacy row that cannot be returned. That makes a finite K+1 window
     * traversable instead of repeatedly returning the same empty page.
     *
     * @return array{rows:object[],first_scanned:?object,last_scanned:?object,has_uninspected:bool}
     */
    private function canonical_page_window(array $rows, int $pageSize): array
    {
        $page = [];
        $bytes = 0;
        $firstScanned = null;
        $lastScanned = null;
        $inspected = 0;
        foreach ($rows as $row) {
            if (count($page) >= $pageSize) {
                break;
            }
            $rowBytes = max(0, (int) ($row->canonical_post_bytes ?? 0));
            // Workers reject oversized canonical rows before publishing them.
            // Keep the transport check as defense in depth for an old index or
            // a canonical mutation that has not reached its queue hook yet.
            if ($rowBytes > self::MAX_CANONICAL_POST_BYTES) {
                $firstScanned ??= $row;
                $lastScanned = $row;
                $inspected++;
                continue;
            }
            $nextBytes = $bytes + $rowBytes + self::CANONICAL_ROW_OVERHEAD_BYTES;
            if ($page !== [] && $nextBytes > self::MAX_CANONICAL_PAGE_BYTES) {
                break;
            }
            $firstScanned ??= $row;
            $lastScanned = $row;
            $bytes = $nextBytes;
            $page[] = $row;
            $inspected++;
        }

        return [
            'rows' => $page,
            'first_scanned' => $firstScanned,
            'last_scanned' => $lastScanned,
            'has_uninspected' => $inspected < count($rows),
        ];
    }

    /**
     * Hydrate only the accepted K-row page after K+1 ranking established cursors.
     *
     * @param object[] $rows
     * @return array<int,object>
     */
    private function hydrate_search_rows(
        array $rows,
        bool $includeMetadata,
        bool $includeSnippets,
        bool $includeCanonicalPostRow,
        array $options
    ): array
    {
        $ids = $this->normalize_doc_ids(array_map(static fn(object $row): int => (int) ($row->doc_id ?? 0), $rows));
        if ($ids === []) {
            return [];
        }
        $select = ['rank_ids.post_id AS doc_id', 'd_h.primary_lang'];
        if ($includeSnippets) {
            // Bound the physical MEDIUMTEXT projection before wpdb allocates
            // it. SUBSTR counts utf8mb4 characters on MySQL and SQLite, so it
            // cannot split a valid multibyte sequence at the 20 KiB envelope.
            $select[] = 'SUBSTR(d_h.snippet_text, 1, ' . self::MAX_METADATA_TEXT_CHARACTERS . ') AS snippet_text';
        }
        if ($includeMetadata) {
            array_push($select, 'wp_h.post_type', 'wp_h.post_status', 'wp_h.post_date_gmt', 'wp_h.post_title', 'wp_h.post_excerpt');
        }
        if ($includeCanonicalPostRow) {
            foreach (self::CANONICAL_POST_COLUMNS as $column) {
                $select[] = "wp_h.{$column} AS canonical_post_{$column}";
            }
        }
        $idRows = implode(' UNION ALL ', array_fill(0, count($ids), 'SELECT %d AS post_id'));
        $visible = $this->visibility_sql('rank_ids.post_id', 'h', $options);
        $control = $this->search_snapshot_control_sql($options);
        $detailSql = 'SELECT ' . implode(',', $select)
            . " FROM ({$idRows}) rank_ids {$visible['joins']} WHERE {$visible['where']}";
        $details = $this->get_results($this->wpdb->prepare(
            "/* wp_fts:hydrate */
SELECT hydrated.*, snapshot.snapshot_ready
FROM (SELECT CASE WHEN {$control['sql']} THEN 1 ELSE 0 END AS snapshot_ready) snapshot
LEFT JOIN ({$detailSql}) hydrated ON snapshot.snapshot_ready = 1",
            ...[...$control['args'], ...$ids, ...$visible['args']]
        ), 'hydrate bounded FTS result page');
        if ($details === [] || (int) ($details[0]->snapshot_ready ?? 0) !== 1) {
            throw new WP_FTS_Search_Unavailable('Full-text search publication changed while hydrating the page.');
        }
        $byId = [];
        foreach ($details as $detail) {
            $docId = max(0, (int) ($detail->doc_id ?? 0));
            if ($docId > 0) {
                $byId[$docId] = $detail;
            }
        }

        return $byId;
    }

    /** @return array{groups:array<int,array<int,array{key:string,lang:string,term:string,rank:int}>>,keys:array<string,bool>} */
    private function normalize_search_plan(array $groups, array $options): array
    {
        if (count($groups) > self::MAX_QUERY_GROUPS) {
            $this->throw_search_plan_limit('logical groups');
        }
        $normalized = [];
        $keys = [];
        $alternatives = 0;
        foreach ($groups as $group) {
            if (!is_array($group)) {
                throw new InvalidArgumentException('Each FTS logical query group must be an array.');
            }
            $byKey = [];
            $inputAlternatives = 0;
            foreach ($group as $candidate) {
                $inputAlternatives++;
                if ($inputAlternatives > self::MAX_ALTERNATIVES_PER_GROUP) {
                    $this->throw_search_plan_limit('input alternatives per logical group');
                }
                if (!is_array($candidate)) {
                    throw new InvalidArgumentException('Each FTS query alternative must be an array.');
                }
                $key = isset($candidate['key']) && is_scalar($candidate['key']) ? (string) $candidate['key'] : '';
                if ($key === '' || trim($key) === '') {
                    throw new InvalidArgumentException('Each FTS query alternative requires a nonempty term key.');
                }
                $identity = $this->term_identity($key);
                if (array_key_exists('rank', $candidate)) {
                    $rawRank = $candidate['rank'];
                    if (
                        (!is_int($rawRank) && (!is_string($rawRank) || preg_match('/^(0|[1-9][0-9]*)$/D', $rawRank) !== 1))
                        || (is_string($rawRank) && strlen($rawRank) > self::MAX_NUMERIC_INPUT_BYTES)
                        || (int) $rawRank < 0
                        || (int) $rawRank > self::MAX_QUERY_ALTERNATIVES
                    ) {
                        throw new InvalidArgumentException('FTS query alternative ranks must be bounded nonnegative integers.');
                    }
                }
                $rank = max(0, (int) ($candidate['rank'] ?? 0));
                if (!isset($byKey[$key]) || $rank < $byKey[$key]['rank']) {
                    $byKey[$key] = [
                        'key' => $key,
                        'lang' => $identity['lang'],
                        'term' => $identity['term'],
                        'rank' => $rank,
                    ];
                }
            }
            if ($byKey === []) {
                throw new InvalidArgumentException('FTS logical query groups must not be empty.');
            }
            if (count($byKey) > self::MAX_ALTERNATIVES_PER_GROUP) {
                $this->throw_search_plan_limit('alternatives per logical group');
            }
            $alternatives += count($byKey);
            if ($alternatives > self::MAX_QUERY_ALTERNATIVES) {
                $this->throw_search_plan_limit('analyzed alternatives');
            }
            foreach ($byKey as $key => $_candidate) {
                $keys[$key] = true;
            }
            $normalized[] = array_values($byKey);
        }
        return ['groups' => $normalized, 'keys' => $keys];
    }

    /** Reject direct-storage input before it can enlarge a plan or binding. */
    private function assert_search_option_bounds(array $options): void
    {
        foreach (['fast_top_k', 'approximate_top_k', 'exact_top_k', 'exact', 'candidate_cap', 'max_candidates'] as $unsupported) {
            if (array_key_exists($unsupported, $options)) {
                throw new InvalidArgumentException("Relational FTS storage does not support {$unsupported}.");
            }
        }
        $allowed = array_fill_keys([
            'mode', 'page_size', 'limit', 'cursor', 'direction', 'query_lang',
            'prefix_matching', 'prefix_group_index', 'prefix_min_length', 'prefix_surface',
            'post_types', 'post_statuses', 'date_after', 'date_before',
            'include_metadata', 'include_snippets', 'include_canonical_post_row',
            'highlight', 'snippet_length', 'explain', 'recency_boost_strength',
            'recency_boost_half_life_days', 'now_gmt', 'search_ready_incarnation',
            'search_ready_profile_hash',
        ], true);
        foreach ($options as $key => $_value) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidArgumentException('Relational FTS storage received an unsupported search option.');
            }
        }
        if (count($options) > self::MAX_SEARCH_OPTION_KEYS) {
            throw new InvalidArgumentException('FTS storage search options may contain at most 64 keys.');
        }
        $nodes = 0;
        $bytes = 0;
        $stack = [[$options, 0]];
        while ($stack !== []) {
            [$map, $depth] = array_pop($stack);
            if ($depth > 8 || count($map) > self::MAX_SEARCH_OPTION_NODES) {
                throw new InvalidArgumentException('FTS storage search options exceed the bounded graph shape.');
            }
            foreach ($map as $key => $value) {
                if (++$nodes > self::MAX_SEARCH_OPTION_NODES) {
                    throw new InvalidArgumentException('FTS storage search options exceed the 256-node limit.');
                }
                if (is_string($key)) {
                    if (strlen($key) > 191) {
                        throw new InvalidArgumentException('FTS storage search option keys may contain at most 191 bytes.');
                    }
                    $bytes += strlen($key);
                }
                if (is_string($value)) {
                    $bytes += strlen($value);
                } elseif (is_array($value)) {
                    $stack[] = [$value, $depth + 1];
                }
                if ($bytes > self::MAX_SEARCH_OPTION_BYTES) {
                    throw new InvalidArgumentException('FTS storage search options exceed the 32 KiB source limit.');
                }
            }
        }

        $rawMode = $options['mode'] ?? 'OR';
        if (!is_scalar($rawMode) || strlen((string) $rawMode) > self::MAX_MODE_BYTES) {
            throw new InvalidArgumentException('FTS search mode may contain at most 8 bytes.');
        }
        if (!in_array(strtoupper((string) $rawMode), ['OR', 'AND'], true)) {
            throw new InvalidArgumentException('FTS search mode must be OR or AND.');
        }
        $rawDirection = $options['direction'] ?? 'after';
        if (!is_scalar($rawDirection) || strlen((string) $rawDirection) > self::MAX_MODE_BYTES) {
            throw new InvalidArgumentException('FTS cursor direction may contain at most 8 bytes.');
        }
        if (!in_array(strtolower((string) $rawDirection), ['after', 'before'], true)) {
            throw new InvalidArgumentException('FTS cursor direction must be after or before.');
        }
        if (array_key_exists('cursor', $options) && $options['cursor'] !== null) {
            $this->assert_cursor_input_bounds($options['cursor']);
        }
        foreach (['query_lang', 'date_after', 'date_before', 'now_gmt'] as $key) {
            if (!array_key_exists($key, $options) || $options[$key] === null) {
                continue;
            }
            if (!is_scalar($options[$key]) || strlen((string) $options[$key]) > self::MAX_FILTER_VALUE_BYTES) {
                throw new InvalidArgumentException("FTS {$key} values may contain at most 64 bytes.");
            }
        }
        foreach (['prefix_matching', 'include_canonical_post_row', 'include_metadata', 'include_snippets', 'highlight', 'explain'] as $key) {
            if (!array_key_exists($key, $options) || $options[$key] === null) {
                continue;
            }
            if (!is_bool($options[$key])) {
                throw new InvalidArgumentException("FTS {$key} switches must be booleans.");
            }
        }
        $integerBounds = [
            'page_size' => [1, self::MAX_PAGE_SIZE],
            'limit' => [1, self::MAX_PAGE_SIZE + 1],
            'prefix_group_index' => [0, self::MAX_QUERY_GROUPS - 1],
            'prefix_min_length' => [2, 255],
            'snippet_length' => [1, 500],
        ];
        foreach ($integerBounds as $key => [$minimum, $maximum]) {
            if (!array_key_exists($key, $options) || $options[$key] === null) {
                continue;
            }
            $value = $options[$key];
            if (
                (!is_int($value) && (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1))
                || (is_string($value) && strlen($value) > self::MAX_NUMERIC_INPUT_BYTES)
                || (int) $value < $minimum
                || (int) $value > $maximum
            ) {
                throw new InvalidArgumentException("FTS {$key} must be an integer from {$minimum} through {$maximum}.");
            }
        }
        $floatBounds = [
            'recency_boost_strength' => [0.0, self::MAX_RECENCY_BOOST_STRENGTH],
            'recency_boost_half_life_days' => [1.0, 3650.0],
        ];
        foreach ($floatBounds as $key => [$minimum, $maximum]) {
            if (!array_key_exists($key, $options) || $options[$key] === null) {
                continue;
            }
            $value = $options[$key];
            $numeric = (is_int($value) || is_float($value))
                || (is_string($value) && strlen($value) <= self::MAX_NUMERIC_INPUT_BYTES && is_numeric($value));
            $number = $numeric ? (float) $value : NAN;
            if (!$numeric || !is_finite($number) || $number < $minimum || $number > $maximum) {
                throw new InvalidArgumentException("FTS {$key} must be a finite number from {$minimum} through {$maximum}.");
            }
        }
        if (array_key_exists('prefix_surface', $options) && $options['prefix_surface'] !== null) {
            $surface = $options['prefix_surface'];
            if (!is_array($surface) || count($surface) > 2) {
                throw new InvalidArgumentException('FTS prefix surfaces must contain one language and one term.');
            }
            foreach (['lang' => self::MAX_LANGUAGE_INPUT_BYTES, 'term' => WP_FTS_Analysis_Limits::MAX_LEXICAL_RUN_BYTES] as $key => $maxBytes) {
                if (!is_scalar($surface[$key] ?? null) || strlen((string) $surface[$key]) > $maxBytes) {
                    throw new InvalidArgumentException('FTS prefix-surface values exceed the bounded analyzer source contract.');
                }
            }
        }

        // Normalization also validates raw list cardinality and each bound
        // value. Calling it here keeps malformed direct-storage requests from
        // reaching cursor JSON or SQL preparation.
        foreach (['post_types', 'post_statuses'] as $key) {
            if (array_key_exists($key, $options) && $options[$key] === null) {
                throw new InvalidArgumentException("FTS {$key} filters must not be null.");
            }
            if (array_key_exists($key, $options) && !is_array($options[$key])) {
                throw new InvalidArgumentException("FTS {$key} filters must be arrays.");
            }
            $this->normalize_filter_values($options[$key] ?? []);
        }
        $readyIncarnation = $options['search_ready_incarnation'] ?? null;
        $readyProfile = $options['search_ready_profile_hash'] ?? null;
        if (!is_string($readyIncarnation) || preg_match('/^[a-f0-9]{32}$/D', $readyIncarnation) !== 1) {
            throw new InvalidArgumentException('FTS storage requires an exact search-ready incarnation.');
        }
        if (!is_string($readyProfile) || preg_match('/^[a-f0-9]{40}$/D', $readyProfile) !== 1) {
            throw new InvalidArgumentException('FTS storage requires an exact search-ready profile hash.');
        }
    }

    /**
     * Compile the final typed surface into one binary dictionary range.
     *
     * Analyzer lemmas remain exact alternatives inside their logical group.
     * Choosing the group's lowest-rank lemma here would make an inflected
     * final word expand an unrelated canonical prefix.
     *
     * @return array{group_id:int,lang:string,term:string}|null
     */
    private function search_prefix_descriptor(array $plan, array $options): ?array
    {
        if (empty($options['prefix_matching']) || $plan['groups'] === []) {
            return null;
        }
        $groupId = max(0, min(count($plan['groups']) - 1, (int) ($options['prefix_group_index'] ?? count($plan['groups']) - 1)));
        $surface = $options['prefix_surface'] ?? null;
        if (!is_array($surface) || !is_scalar($surface['lang'] ?? null) || !is_scalar($surface['term'] ?? null)) {
            throw new InvalidArgumentException('FTS prefix matching requires the final normalized typed surface.');
        }
        $surfaceLang = WP_FTS_TermNamespace::canonicalize_lang((string) $surface['lang'], 'und');
        $surfaceTerm = (string) $surface['term'];
        if (
            $surfaceTerm === ''
            || strlen($surfaceTerm) > 255
            || !WP_FTS_TermNamespace::term_key_fits($surfaceTerm, $surfaceLang)
        ) {
            // Exact analyzed alternatives can still match. A longer prefix has
            // no representable surface identity and must not abort that path.
            return null;
        }
        $identity = $this->term_identity(WP_FTS_TermNamespace::namespace_term(
            $surfaceLang,
            $surfaceTerm
        ));
        $groupHasLanguage = false;
        foreach ($plan['groups'][$groupId] ?? [] as $candidate) {
            if (hash_equals($identity['lang'], (string) ($candidate['lang'] ?? ''))) {
                $groupHasLanguage = true;
                break;
            }
        }
        if (!$groupHasLanguage) {
            throw new InvalidArgumentException('FTS prefix surface language must match its logical query group.');
        }
        $minimum = max(2, (int) ($options['prefix_min_length'] ?? 4));
        if (WP_FTS_Utf8::length($identity['term']) < $minimum) {
            return null;
        }
        return [
            'group_id' => $groupId,
            'lang' => $identity['lang'],
            'term' => $identity['term'],
        ];
    }

    /** @return array{sql:string,args:array<int,mixed>,reverse:bool,anchor_group:?int,prefix_strategy:string,scoring_now_gmt:string} */
    private function build_rank_query(
        array $groups,
        int $groupCount,
        ?array $prefix,
        string $mode,
        array $options,
        ?array $cursor
    ): array
    {
        $orderedJoin = $this->is_sqlite_runtime() ? 'JOIN' : 'STRAIGHT_JOIN';
        $orderedCrossJoin = $this->is_sqlite_runtime() ? 'CROSS JOIN' : 'STRAIGHT_JOIN';
        $orderedCrossPredicate = $this->is_sqlite_runtime() ? '' : ' ON 1=1';
        $rankControl = $this->search_snapshot_control_sql($options);
        $rankGateSql = "(SELECT 1 AS rank_ready WHERE {$rankControl['sql']} LIMIT 1) rank_gate";
        $rankGateJoin = $this->is_sqlite_runtime() ? 'CROSS JOIN' : 'STRAIGHT_JOIN';
        $rankGatePredicate = $this->is_sqlite_runtime() ? '' : ' ON rank_gate.rank_ready = 1';
        $qRows = [];
        $qRowsByGroup = [];
        foreach ($groups as $groupId => $alternatives) {
            foreach ($alternatives as $alternative) {
                $row = 'SELECT ' . (int) $alternative['term_id'] . ' AS term_id, '
                    . (int) $groupId . ' AS group_id, ' . (int) $alternative['weight'] . ' AS weight';
                $qRows[] = $row;
                $qRowsByGroup[$groupId][] = $row;
            }
        }
        $qSql = implode("\nUNION ALL\n", $qRows);
        $prefixGroup = $prefix['group_id'] ?? null;
        $prefixStrategy = $prefix !== null ? 'surface_range' : 'none';
        $anchorGroup = null;
        $groupDocFreqUpperBounds = [];
        if ($mode === 'AND' && $groupCount > 1) {
            $costs = [];
            foreach ($groups as $groupId => $alternatives) {
                // Alternative document frequencies are a dictionary-only upper
                // bound. It can overestimate overlapping morphology by at most
                // the fixed alternative cap, but never scans a broad posting
                // union merely to choose the rare AND anchor.
                $groupCost = 0;
                foreach ($alternatives as $alternative) {
                    $docFreq = max(0, (int) ($alternative['doc_freq'] ?? 0));
                    $groupCost = $groupCost > PHP_INT_MAX - $docFreq
                        ? PHP_INT_MAX
                        : $groupCost + $docFreq;
                }
                $groupDocFreqUpperBounds[$groupId] = $groupCost;
                $costs[$groupId] = $groupCost;
                if ($prefixGroup !== null && $groupId === $prefixGroup) {
                    $prefixDocFreq = max(0, (int) ($prefix['doc_freq'] ?? 0));
                    $costs[$groupId] = $costs[$groupId] > PHP_INT_MAX - $prefixDocFreq
                        ? PHP_INT_MAX
                        : $costs[$groupId] + $prefixDocFreq;
                }
            }
            if ($costs !== []) {
                asort($costs, SORT_NUMERIC);
                $anchorGroup = (int) array_key_first($costs);
            }
        }

        $args = [];
        $rawParts = [];
        if ($anchorGroup !== null) {
            if ($prefix !== null && $anchorGroup === $prefixGroup) {
                $candidate = $this->prefix_candidate_sql(
                    $qSql,
                    !empty($groups[$anchorGroup]),
                    $anchorGroup,
                    $prefix,
                    $options
                );
                $probeRows = [
                    'SELECT 0 AS term_id, ' . $anchorGroup . ' AS group_id, 0 AS weight',
                ];
                foreach ($qRowsByGroup as $groupId => $groupRows) {
                    if ((int) $groupId !== $anchorGroup) {
                        array_push($probeRows, ...$groupRows);
                    }
                }
                $probeSql = implode("\nUNION ALL\n", $probeRows);
                $indexHint = $this->is_sqlite_runtime() ? '' : ' FORCE INDEX (post_term_impact)';
                $rawParts[] = "SELECT c.post_id, q.group_id,
       MAX(CASE WHEN q.term_id = 0 THEN c.group_score ELSE po.impact * q.weight END) AS group_score
FROM ({$candidate['sql']}) c
{$orderedCrossJoin} ({$probeSql}) q{$orderedCrossPredicate}
LEFT JOIN {$this->postingsTable} po{$indexHint}
  ON q.term_id <> 0 AND po.post_id = c.post_id AND po.term_id = q.term_id
WHERE q.term_id = 0 OR po.term_id IS NOT NULL
GROUP BY c.post_id, q.group_id";
                array_push($args, ...$candidate['args']);
            } else {
                $candidate = $this->candidate_sql($qSql, $anchorGroup, $options, 'exact_anchor');
                $indexHint = $this->is_sqlite_runtime() ? '' : ' FORCE INDEX (post_term_impact)';
                if ($prefix !== null) {
                    // Exact groups use primary-key probes inside the rare anchor.
                    $rawParts[] = "SELECT c.post_id, q.group_id, MAX(po.impact * q.weight) AS group_score
FROM ({$candidate['sql']}) c
{$orderedCrossJoin} ({$qSql}) q{$orderedCrossPredicate}
{$orderedJoin} {$this->postingsTable} po{$indexHint} ON po.post_id = c.post_id AND po.term_id = q.term_id
GROUP BY c.post_id, q.group_id";
                    array_push($args, ...$candidate['args']);

                    $prefixCandidate = $this->candidate_sql($qSql, $anchorGroup, $options, 'prefix_anchor');
                    $prefixIntegerType = $this->is_sqlite_runtime() ? 'INTEGER' : 'SIGNED';
                    $prefixScore = "CAST(ppo.impact * CAST(" . self::RARITY_SCALE
                        . " / CASE WHEN pt.doc_freq < 1 THEN 1 ELSE pt.doc_freq END AS {$prefixIntegerType}) * "
                        . self::PREFIX_WEIGHT . " / 1000 AS {$prefixIntegerType})";
                    $prefixPostingRows = max(0, (int) ($prefix['doc_freq'] ?? 0));
                    $anchorDocFreqUpper = max(0, (int) ($groupDocFreqUpperBounds[$anchorGroup] ?? 0));
                    $useCandidateFirst = $prefixPostingRows > 0
                        && $anchorDocFreqUpper > 0
                        && intdiv($prefixPostingRows - 1, self::MAX_DOCUMENT_POSTINGS) >= $anchorDocFreqUpper;
                    if ($useCandidateFirst) {
                        // A broad prefix after a rare exact anchor must not read
                        // its complete posting range merely to discard every
                        // non-candidate row. Scan each candidate's bounded posting
                        // envelope, then classify term identities by primary key.
                        $prefixStrategy = 'candidate_first';
                        $surfacePredicate = $this->surface_range_predicate($prefix, 'pt');
                        $prefixPostingIndexHint = $this->is_sqlite_runtime()
                            ? ''
                            : ' FORCE INDEX (post_term_impact)';
                        $prefixTermIndexHint = $this->is_sqlite_runtime() ? '' : ' FORCE INDEX (PRIMARY)';
                        $rawParts[] = "SELECT prefix_candidate.post_id, " . (int) $prefix['group_id'] . " AS group_id,
MAX({$prefixScore}) AS group_score
FROM ({$prefixCandidate['sql']}) prefix_candidate
{$orderedJoin} {$this->postingsTable} ppo{$prefixPostingIndexHint} ON prefix_candidate.post_id = ppo.post_id
{$orderedJoin} {$this->termsTable} pt{$prefixTermIndexHint} ON pt.term_id = ppo.term_id
WHERE {$surfacePredicate['sql']}
GROUP BY prefix_candidate.post_id";
                        array_push($args, ...$prefixCandidate['args'], ...$surfacePredicate['args']);
                    } else {
                        // A genuinely smaller surface range remains the cheaper
                        // exact intersection, including ties at the upper bound.
                        $surfaceRange = $this->surface_range_sql($prefix);
                        $prefixPostingIndexHint = $this->is_sqlite_runtime() ? '' : ' FORCE INDEX (PRIMARY)';
                        $rawParts[] = "SELECT prefix_candidate.post_id, " . (int) $prefix['group_id'] . " AS group_id,
MAX({$prefixScore}) AS group_score
FROM ({$surfaceRange['sql']}) pt
{$orderedJoin} {$this->postingsTable} ppo{$prefixPostingIndexHint} ON ppo.term_id = pt.term_id
{$orderedJoin} ({$prefixCandidate['sql']}) prefix_candidate ON prefix_candidate.post_id = ppo.post_id
GROUP BY prefix_candidate.post_id";
                        array_push($args, ...$surfaceRange['args'], ...$prefixCandidate['args']);
                    }
                } else {
                    $rawParts[] = "SELECT c.post_id, q.group_id, MAX(po.impact * q.weight) AS group_score
FROM ({$candidate['sql']}) c
{$orderedCrossJoin} ({$qSql}) q{$orderedCrossPredicate}
{$orderedJoin} {$this->postingsTable} po{$indexHint} ON po.post_id = c.post_id AND po.term_id = q.term_id
GROUP BY c.post_id, q.group_id";
                    array_push($args, ...$candidate['args']);
                }
            }
        } else {
            if ($qSql !== '') {
                $rawParts[] = "SELECT po.post_id, q.group_id, po.impact * q.weight AS group_score
FROM {$rankGateSql}
{$rankGateJoin} ({$qSql}) q{$rankGatePredicate}
{$orderedJoin} {$this->postingsTable} po ON po.term_id = q.term_id";
                array_push($args, ...$rankControl['args']);
            }
            if ($prefix !== null) {
                $surfaceRange = $this->surface_range_sql($prefix);
                $prefixIntegerType = $this->is_sqlite_runtime() ? 'INTEGER' : 'SIGNED';
                $prefixScore = "CAST(ppo.impact * CAST(" . self::RARITY_SCALE
                    . " / CASE WHEN pt.doc_freq < 1 THEN 1 ELSE pt.doc_freq END AS {$prefixIntegerType}) * "
                    . self::PREFIX_WEIGHT . " / 1000 AS {$prefixIntegerType})";
                array_push($args, ...$rankControl['args'], ...$surfaceRange['args']);
                $rawParts[] = "SELECT ppo.post_id, " . (int) $prefix['group_id'] . " AS group_id,
{$prefixScore} AS group_score
FROM {$rankGateSql}
{$rankGateJoin} ({$surfaceRange['sql']}) pt{$rankGatePredicate}
{$orderedJoin} {$this->postingsTable} ppo ON ppo.term_id = pt.term_id";
            }
        }
        if ($rawParts === []) {
            return ['sql' => 'SELECT 0 WHERE 1=0', 'args' => [], 'reverse' => false, 'anchor_group' => $anchorGroup, 'prefix_strategy' => $prefixStrategy, 'scoring_now_gmt' => ''];
        }
        $having = $mode === 'AND' ? 'HAVING COUNT(*) = ' . $groupCount : '';
        $rawSql = implode("\nUNION ALL\n", $rawParts);
        $rankedSql = "SELECT grouped.post_id, SUM(grouped.group_score) AS score
FROM (
    SELECT raw.post_id, raw.group_id, MAX(raw.group_score) AS group_score
    FROM ({$rawSql}) raw
    GROUP BY raw.post_id, raw.group_id
) grouped
GROUP BY grouped.post_id
{$having}";
        $limit = max(1, min(51, (int) ($options['limit'] ?? 11)));
        if ($anchorGroup === null) {
            // Broad OR and single-group searches compact postings to one row
            // per document before paying WordPress visibility once. Applying
            // the same joins inside every exact/prefix arm multiplies work by
            // both query alternatives and posting rows without changing the
            // visible result set.
            $visible = $this->visibility_sql('ranked.post_id', 'f', $options);
            $visibilityJoins = $visible['joins'];
            $visibilityWhere = $visible['where'];
            array_push($args, ...$visible['args']);
        } else {
            // A multi-group AND already applied complete visibility to its
            // rare anchor before any post-first probes. The ranking statement
            // has one snapshot, so repeating every anti-join here is redundant;
            // only the canonical date row is still needed for ordering.
            $visibilityJoins = "{$orderedJoin} {$this->postsTable} wp_f ON wp_f.ID = ranked.post_id";
            $visibilityWhere = '1=1';
        }
        $recencyStrength = is_numeric($options['recency_boost_strength'] ?? null)
            ? max(0.0, min(self::MAX_RECENCY_BOOST_STRENGTH, (float) $options['recency_boost_strength']))
            : 0.0;
        $cursorNow = is_scalar($cursor['now_gmt'] ?? null) ? (string) $cursor['now_gmt'] : '';
        $scoringNow = $recencyStrength > 0.0
            ? ($cursorNow !== '' ? $cursorNow : $this->normalize_scoring_now($options['now_gmt'] ?? null))
            : '';
        $direction = strtolower((string) ($options['direction'] ?? 'after'));
        $reverse = $cursor !== null && $direction === 'before';
        $cursorWhere = '';
        if ($cursor !== null) {
            $operator = $reverse ? '>' : '<';
            $cursorIntegerType = $this->is_sqlite_runtime() ? 'INTEGER' : 'SIGNED';
            $scoreBoundary = "CAST(%s AS {$cursorIntegerType})";
            $cursorWhere = "AND (scored.score {$operator} {$scoreBoundary}
 OR (scored.score = {$scoreBoundary} AND scored.post_date_gmt {$operator} %s)
 OR (scored.score = {$scoreBoundary} AND scored.post_date_gmt = %s AND scored.post_id {$operator} %d))";
            array_push($args, $cursor['score'], $cursor['score'], $cursor['date'], $cursor['score'], $cursor['date'], $cursor['post_id']);
        }
        $order = $reverse ? 'ASC' : 'DESC';
        $args[] = $limit;
        $scoreOptions = $options;
        if ($scoringNow !== '') {
            $scoreOptions['now_gmt'] = $scoringNow;
        }
        $scoreExpression = $this->recency_score_expression($scoreOptions, 'wp_f');
        $limitedSql = "SELECT scored.post_id AS doc_id, scored.score, scored.post_date_gmt
FROM (
    SELECT ranked.post_id, {$scoreExpression} AS score, wp_f.post_date_gmt
    FROM ({$rankedSql}) ranked
    {$visibilityJoins}
    WHERE {$visibilityWhere}
) scored
WHERE 1=1 {$cursorWhere}
ORDER BY scored.score {$order}, scored.post_date_gmt {$order}, scored.post_id {$order}
LIMIT %d";
        $control = $this->search_snapshot_control_sql($options);
        $snapshotSql = "(SELECT CASE WHEN {$control['sql']} THEN 1 ELSE 0 END AS snapshot_ready) snapshot";
        $includeCanonicalPostRow = !empty($options['include_canonical_post_row']);
        $includeMetadata = !empty($options['include_metadata']);
        if ($includeCanonicalPostRow || $includeMetadata) {
            // Keep hydration length reads outside the visibility/ranking/filesort
            // relation. The LIMIT makes this derived page non-mergeable, so at
            // most K+1 WordPress rows are touched to enforce the PHP transport
            // bound even for a 100k-hit OR query. Canonical WP_Post transport
            // counts every selected column; metadata-only callers count exactly
            // the five columns their third statement will return.
            $lengthFunction = $this->is_sqlite_runtime()
                ? static fn(string $column): string => "LENGTH(CAST(wp_size.{$column} AS BLOB))"
                : static fn(string $column): string => "OCTET_LENGTH(wp_size.{$column})";
            $measuredColumns = $includeCanonicalPostRow
                ? self::CANONICAL_POST_COLUMNS
                : ['post_type', 'post_status', 'post_date_gmt', 'post_title', 'post_excerpt'];
            $canonicalBytes = implode(' + ', array_map(
                static fn(string $column): string => 'COALESCE(' . $lengthFunction($column) . ',0)',
                $measuredColumns
            ));
            $sql = "/* wp_fts:rank */
SELECT limited.doc_id, limited.score, limited.post_date_gmt,
       ({$canonicalBytes}) AS canonical_post_bytes,
       snapshot.snapshot_ready
FROM {$snapshotSql}
LEFT JOIN ({$limitedSql}) limited ON snapshot.snapshot_ready = 1
LEFT JOIN {$this->postsTable} wp_size ON wp_size.ID = limited.doc_id
ORDER BY CASE WHEN limited.doc_id IS NULL THEN 1 ELSE 0 END,
         limited.score {$order}, limited.post_date_gmt {$order}, limited.doc_id {$order}";
        } else {
            $sql = "/* wp_fts:rank */
SELECT limited.doc_id, limited.score, limited.post_date_gmt,
       snapshot.snapshot_ready
FROM {$snapshotSql}
LEFT JOIN ({$limitedSql}) limited ON snapshot.snapshot_ready = 1
ORDER BY CASE WHEN limited.doc_id IS NULL THEN 1 ELSE 0 END,
         limited.score {$order}, limited.post_date_gmt {$order}, limited.doc_id {$order}";
        }
        return [
            'sql' => $sql,
            // The snapshot relation appears before the limited result relation
            // in SQL, so its primary-key bindings must precede ranking inputs.
            'args' => [...$control['args'], ...$args],
            'reverse' => $reverse,
            'anchor_group' => $anchorGroup,
            'prefix_strategy' => $prefixStrategy,
            'scoring_now_gmt' => $scoringNow,
        ];
    }

    /** @return array{sql:string,args:array<int,mixed>} */
    private function prefix_candidate_sql(
        string $qSql,
        bool $hasExactAlternatives,
        int $anchorGroup,
        array $prefix,
        array $options
    ): array {
        $orderedJoin = $this->is_sqlite_runtime() ? 'JOIN' : 'STRAIGHT_JOIN';
        $gateJoin = $this->is_sqlite_runtime() ? 'CROSS JOIN' : 'STRAIGHT_JOIN';
        $gatePredicate = $this->is_sqlite_runtime() ? '' : ' ON prefix_gate.rank_ready = 1';
        $rawParts = [];
        $args = [];

        if ($hasExactAlternatives) {
            $control = $this->search_snapshot_control_sql($options);
            $gate = "(SELECT 1 AS rank_ready WHERE {$control['sql']} LIMIT 1) prefix_gate";
            $postingHint = $this->is_sqlite_runtime() ? '' : ' FORCE INDEX (PRIMARY)';
            $rawParts[] = "SELECT exact_posting.post_id,
       exact_posting.impact * exact_query.weight AS group_score
FROM {$gate}
{$gateJoin} ({$qSql}) exact_query{$gatePredicate}
{$orderedJoin} {$this->postingsTable} exact_posting{$postingHint}
  ON exact_posting.term_id = exact_query.term_id
WHERE exact_query.group_id = {$anchorGroup}";
            array_push($args, ...$control['args']);
        }

        $control = $this->search_snapshot_control_sql($options);
        $gate = "(SELECT 1 AS rank_ready WHERE {$control['sql']} LIMIT 1) prefix_gate";
        $surfaceRange = $this->surface_range_sql($prefix);
        $postingHint = $this->is_sqlite_runtime() ? '' : ' FORCE INDEX (PRIMARY)';
        $integerType = $this->is_sqlite_runtime() ? 'INTEGER' : 'SIGNED';
        $prefixScore = "CAST(prefix_posting.impact * CAST(" . self::RARITY_SCALE
            . " / CASE WHEN prefix_term.doc_freq < 1 THEN 1 ELSE prefix_term.doc_freq END AS {$integerType}) * "
            . self::PREFIX_WEIGHT . " / 1000 AS {$integerType})";
        $rawParts[] = "SELECT prefix_posting.post_id, {$prefixScore} AS group_score
FROM {$gate}
{$gateJoin} ({$surfaceRange['sql']}) prefix_term{$gatePredicate}
{$orderedJoin} {$this->postingsTable} prefix_posting{$postingHint}
  ON prefix_posting.term_id = prefix_term.term_id";
        array_push($args, ...$control['args'], ...$surfaceRange['args']);

        $visible = $this->visibility_sql('anchor_posts.post_id', 'surface_anchor', $options);
        return [
            'sql' => "SELECT anchor_posts.post_id, MAX(anchor_posts.group_score) AS group_score
FROM (" . implode("\nUNION ALL\n", $rawParts) . ") anchor_posts
{$visible['joins']}
WHERE {$visible['where']}
GROUP BY anchor_posts.post_id",
            'args' => [...$args, ...$visible['args']],
        ];
    }

    /** @return array{sql:string,args:array<int,mixed>} */
    private function candidate_sql(
        string $qSql,
        int $anchorGroup,
        array $options,
        string $visibilitySuffix
    ): array
    {
        $visible = $this->visibility_sql('anchor_posts.post_id', $visibilitySuffix, $options);
        $orderedJoin = $this->is_sqlite_runtime() ? 'JOIN' : 'STRAIGHT_JOIN';
        $gateJoin = $this->is_sqlite_runtime() ? 'CROSS JOIN' : 'STRAIGHT_JOIN';
        $gatePredicate = $this->is_sqlite_runtime() ? '' : ' ON rank_gate.rank_ready = 1';
        $control = $this->search_snapshot_control_sql($options);
        $gate = "(SELECT 1 AS rank_ready WHERE {$control['sql']} LIMIT 1) rank_gate";
        return [
            'sql' => "SELECT anchor_posts.post_id
FROM (
    SELECT DISTINCT ap.post_id
FROM {$gate}
{$gateJoin} ({$qSql}) aq{$gatePredicate}
{$orderedJoin} {$this->postingsTable} ap ON ap.term_id = aq.term_id
    WHERE aq.group_id = {$anchorGroup}
) anchor_posts
{$visible['joins']}
WHERE {$visible['where']}",
            'args' => [...$control['args'], ...$visible['args']],
        ];
    }

    /** @return array{joins:string,where:string,args:array<int,mixed>} */
    private function visibility_sql(string $postIdExpression, string $suffix, array $options): array
    {
        $post = 'wp_' . $suffix;
        $doc = 'd_' . $suffix;
        $dirty = 'dirty_' . $suffix;
        $orderedJoin = $this->is_sqlite_runtime() ? 'JOIN' : 'STRAIGHT_JOIN';
        // Visibility runs once per ranked candidate. Any scope reconciliation
        // makes planning fail closed, so this hot path needs only the direct
        // post-generation probe; it must never walk taxonomy relationships for
        // every broad-query candidate.
        $dirtyIndexHint = $this->is_sqlite_runtime() ? '' : ' FORCE INDEX (dirty)';
        $joins = "{$orderedJoin} {$this->documentsTable} {$doc} ON {$doc}.post_id = {$postIdExpression}
{$orderedJoin} {$this->postsTable} {$post} ON {$post}.ID = {$postIdExpression}
LEFT JOIN {$this->workTable} {$dirty}{$dirtyIndexHint} ON {$dirty}.kind = 'post' AND {$dirty}.post_id = {$postIdExpression}";
        $where = [
            "{$dirty}.job_key IS NULL",
            "{$post}.post_password = ''",
        ];
        $args = [];
        $statuses = $this->normalize_filter_values($options['post_statuses'] ?? []);
        if ($statuses === []) {
            $statuses = ['publish'];
        }
        $where[] = "{$post}.post_status IN (" . implode(',', array_fill(0, count($statuses), '%s')) . ')';
        array_push($args, ...$statuses);
        $types = $this->normalize_filter_values($options['post_types'] ?? []);
        if ($types !== []) {
            $where[] = "{$post}.post_type IN (" . implode(',', array_fill(0, count($types), '%s')) . ')';
            array_push($args, ...$types);
        }
        if (is_scalar($options['date_after'] ?? null) && (string) $options['date_after'] !== '') {
            $where[] = "{$post}.post_date_gmt >= %s";
            $args[] = (string) $options['date_after'];
        }
        if (is_scalar($options['date_before'] ?? null) && (string) $options['date_before'] !== '') {
            $where[] = "{$post}.post_date_gmt <= %s";
            $args[] = (string) $options['date_before'];
        }
        return ['joins' => $joins, 'where' => implode(' AND ', $where), 'args' => $args];
    }

    /** Compile one `(lang, kind, term)` index range for normalized surfaces. */
    private function surface_range_sql(array $prefix): array
    {
        $range = $this->surface_range_predicate($prefix, 'pt');
        $indexHint = $this->is_sqlite_runtime() ? '' : ' FORCE INDEX (term_identity)';
        return [
            'sql' => "SELECT pt.term_id, pt.doc_freq
FROM {$this->termsTable} pt{$indexHint}
WHERE {$range['sql']}",
            'args' => $range['args'],
        ];
    }

    /** @return array{sql:string,args:array<int,mixed>} */
    private function surface_range_predicate(array $prefix, string $alias): array
    {
        $lang = $this->binary_sql_value((string) $prefix['lang']);
        $lower = $this->binary_sql_value((string) $prefix['term']);
        $upperValue = $this->binary_successor((string) $prefix['term']);
        $clauses = [
            "{$alias}.lang = {$lang['sql']}",
            "{$alias}.kind = " . self::SURFACE_KIND,
            "{$alias}.term >= {$lower['sql']}",
        ];
        $args = [...$lang['args'], ...$lower['args']];
        if ($upperValue !== null) {
            $upper = $this->binary_sql_value($upperValue);
            $clauses[] = "{$alias}.term < {$upper['sql']}";
            array_push($args, ...$upper['args']);
        }

        return ['sql' => implode(' AND ', $clauses), 'args' => $args];
    }

    /** Return the exclusive bytewise upper bound for one binary prefix. */
    private function binary_successor(string $prefix): ?string
    {
        for ($offset = strlen($prefix) - 1; $offset >= 0; $offset--) {
            $byte = ord($prefix[$offset]);
            if ($byte < 0xff) {
                return substr($prefix, 0, $offset) . chr($byte + 1);
            }
        }

        return null;
    }

    /**
     * Apply the optional product recency boost inside the one ranking query.
     *
     * Scores remain integral so cursor comparisons use the exact value MySQL
     * ordered. All interpolated values are locally clamped numbers; query input
     * never becomes SQL text.
     */
    private function recency_score_expression(array $options, string $postAlias): string
    {
        $strength = is_numeric($options['recency_boost_strength'] ?? null)
            ? (float) $options['recency_boost_strength']
            : 0.0;
        $strength = max(0.0, min(self::MAX_RECENCY_BOOST_STRENGTH, $strength));
        if ($strength <= 0.0) {
            return 'ranked.score';
        }

        $halfLifeDays = is_numeric($options['recency_boost_half_life_days'] ?? null)
            ? (float) $options['recency_boost_half_life_days']
            : 30.0;
        $halfLifeSeconds = max(86400, min(315360000, (int) round($halfLifeDays * 86400.0)));
        $nowGmt = $this->normalize_scoring_now($options['now_gmt'] ?? null);
        $nowLiteral = "'{$nowGmt}'";
        $strengthPartsPerMillion = (int) round($strength * 1000000.0);
        $ageSeconds = $this->is_sqlite_runtime()
            ? "MAX(0, CAST(strftime('%s', {$nowLiteral}) AS INTEGER) - CAST(strftime('%s', {$postAlias}.post_date_gmt) AS INTEGER))"
            : "GREATEST(0, TIMESTAMPDIFF(SECOND, {$postAlias}.post_date_gmt, {$nowLiteral}))";
        $integerType = $this->is_sqlite_runtime() ? 'INTEGER' : 'SIGNED';

        // The rational decay has an exact half-life, needs no optional SQL math
        // extension, and is identical on MySQL, MariaDB, and SQLite.
        return "COALESCE(CAST(ROUND(ranked.score * (1000000 + {$strengthPartsPerMillion} * {$halfLifeSeconds} "
            . "/ ({$halfLifeSeconds} + {$ageSeconds})) / 1000000) AS {$integerType}), ranked.score)";
    }

    /** Pin a bounded UTC scoring instant so every cursor page orders identically. */
    private function normalize_scoring_now(mixed $value): string
    {
        $timestamp = is_scalar($value) && strlen((string) $value) <= 64
            ? strtotime((string) $value . ' UTC')
            : false;

        return gmdate('Y-m-d H:i:s', $timestamp === false ? time() : max(0, $timestamp));
    }

    /** @return array<int,int> */
    public function get_doc_lengths(array $doc_ids, ?string $lang = null): array
    {
        // The production relational backend scores from posting impacts. It
        // deliberately has no document-length source for the legacy PHP BM25
        // path; that path remains available only on File/InMemory fixtures.
        return [];
    }

    /** Return the minimal compatibility shape without reviving length scoring. */
    public function get_doc(int $doc_id): ?array
    {
        $row = $this->get_row($this->wpdb->prepare(
            "SELECT post_id, primary_lang, content_hash FROM {$this->documentsTable} WHERE post_id = %d",
            $doc_id
        ), 'read FTS document');
        if ($row === null) {
            return null;
        }
        $lang = WP_FTS_TermNamespace::canonicalize_lang((string) ($row->primary_lang ?? 'und'), 'und');
        return [
            'doc_len' => 0,
            'lang' => $lang,
            'primary_lang' => $lang,
            'lang_lengths' => [],
            'content_hash' => $row->content_hash !== null ? (string) $row->content_hash : null,
            'deleted' => false,
        ];
    }

    /**
     * Read existing source fingerprints for a worker batch in one statement.
     *
     * @param int[] $doc_ids
     * @return array<int,string>
     */
    public function document_hashes(array $doc_ids): array
    {
        $ids = $this->normalize_bounded_doc_ids(
            $doc_ids,
            self::MAX_BATCH_DOCUMENTS,
            'FTS document fingerprint reads'
        );
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $this->get_results($this->wpdb->prepare(
            "SELECT post_id, content_hash FROM {$this->documentsTable} WHERE post_id IN ({$placeholders})",
            ...$ids
        ), 'read FTS document fingerprints');
        $hashes = [];
        foreach ($rows as $row) {
            $postId = max(0, (int) ($row->post_id ?? 0));
            if ($postId > 0 && is_scalar($row->content_hash ?? null)) {
                $hashes[$postId] = (string) $row->content_hash;
            }
        }

        return $hashes;
    }

    /** Legacy point mutations are incompatible with the bounded relational writer. */
    public function put_doc(int $doc_id, int|string $doc_len_or_primary_lang, string|array $hash_or_lang_lengths, ?string $hash = null): void
    {
        throw new LogicException('Set-oriented storage mutations must use the bounded batch writer.');
    }

    /** Legacy point mutations are incompatible with the bounded relational writer. */
    public function put_doc_metadata(int $doc_id, array $metadata): void
    {
        throw new LogicException('Set-oriented storage mutations must use the bounded batch writer.');
    }

    /** @return array<int,array<string,mixed>> */
    public function get_doc_metadata(array $doc_ids): array
    {
        $ids = $this->normalize_bounded_doc_ids(
            $doc_ids,
            self::MAX_BATCH_DOCUMENTS,
            'FTS compatibility metadata reads'
        );
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $this->get_results($this->wpdb->prepare(
            "SELECT d.post_id, d.primary_lang,
                    SUBSTR(d.snippet_text, 1, " . self::MAX_METADATA_TEXT_CHARACTERS . ") AS snippet_text,
                    SUBSTR(p.post_title, 1, " . self::MAX_METADATA_TEXT_CHARACTERS . ") AS post_title,
                    SUBSTR(p.post_excerpt, 1, " . self::MAX_METADATA_TEXT_CHARACTERS . ") AS post_excerpt,
                    p.post_type, p.post_status, p.post_date_gmt
             FROM {$this->documentsTable} d
             LEFT JOIN {$this->postsTable} p ON p.ID = d.post_id
             WHERE d.post_id IN ({$placeholders})",
            ...$ids
        ), 'read FTS document metadata');
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->post_id] = WP_FTS_StorageCompat::normalize_doc_metadata([
                'title' => (string) ($row->post_title ?? ''),
                'excerpt' => (string) ($row->post_excerpt ?? ''),
                'post_type' => (string) ($row->post_type ?? ''),
                'post_status' => (string) ($row->post_status ?? ''),
                'post_date_gmt' => (string) ($row->post_date_gmt ?? ''),
                'primary_lang' => (string) ($row->primary_lang ?? 'und'),
                'search_text' => (string) ($row->snippet_text ?? ''),
            ]);
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    /** @return int[] */
    public function filter_doc_ids_by_metadata(
        array $doc_ids,
        array $post_types = [],
        array $post_statuses = [],
        ?string $date_after = null,
        ?string $date_before = null
    ): array {
        $this->reject_legacy_unbounded_operation();
    }

    /** Legacy point mutations are incompatible with the bounded relational writer. */
    public function delete_doc(int $doc_id): void
    {
        throw new LogicException('Set-oriented storage mutations must use the bounded batch writer.');
    }

    /** @return array{doc_count:int,len_sum:int} */
    public function get_meta(?string $lang = null): array
    {
        $this->reject_legacy_unbounded_operation();
    }

    /** Collection statistics are derived from the documents table in v4. */
    public function add_meta(int|string $lang_or_d_docs, int $d_docs_or_d_len, ?int $d_len = null): void
    {
    }

    /** @return string[] */
    public function all_terms(): array
    {
        $this->reject_legacy_unbounded_operation();
    }

    /** @return int[] */
    public function all_doc_ids(bool $include_deleted = false): array
    {
        $this->reject_legacy_unbounded_operation();
    }

    /** @return string[] */
    public function terms_for_doc(int $doc_id): array
    {
        $rows = $this->get_results($this->wpdb->prepare(
            "SELECT t.lang, t.term
FROM {$this->postingsTable} p" . ($this->is_sqlite_runtime() ? '' : ' FORCE INDEX (post_term_impact)') . "
" . ($this->is_sqlite_runtime() ? 'JOIN' : 'STRAIGHT_JOIN') . " {$this->termsTable} t" . ($this->is_sqlite_runtime() ? '' : ' FORCE INDEX (PRIMARY)') . " ON t.term_id = p.term_id
WHERE p.post_id = %d AND t.kind = " . self::LEXICAL_KIND . "
ORDER BY t.lang, t.term
LIMIT " . (WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_TERMS + 1),
            $doc_id
        ), 'read FTS document terms');
        if (count($rows) > WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_TERMS) {
            throw new RuntimeException('An indexed document exceeds the bounded term-preview contract.');
        }
        $terms = [];
        foreach ($rows as $row) {
            $terms[] = WP_FTS_TermNamespace::namespace_term((string) $row->lang, (string) $row->term);
        }
        return $terms;
    }

    /**
     * Read a bounded term preview for a whole result page in one statement.
     *
     * @param int[] $doc_ids
     * @return array<int,string[]>
     */
    public function terms_for_docs(array $doc_ids, int $per_doc_limit): array
    {
        $ids = $this->normalize_bounded_doc_ids(
            $doc_ids,
            WP_FTS_Set_Oriented_Search_Storage::MAX_PAGE_SIZE,
            'FTS result-page term previews'
        );
        $perDocLimit = max(1, min(256, $per_doc_limit));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        if ($this->is_sqlite_runtime()) {
            $statement = $this->wpdb->prepare(
                "SELECT ranked.post_id, ranked.lang, ranked.term
FROM (
    SELECT p.post_id, t.lang, t.term,
           ROW_NUMBER() OVER (PARTITION BY p.post_id ORDER BY t.lang, t.term) AS term_rank
    FROM {$this->postingsTable} p
    JOIN {$this->termsTable} t ON t.term_id = p.term_id
    WHERE p.post_id IN ({$placeholders}) AND t.kind = " . self::LEXICAL_KIND . "
) ranked
WHERE ranked.term_rank <= %d
ORDER BY ranked.post_id, ranked.lang, ranked.term",
                ...[...$ids, $perDocLimit]
            );
        } else {
            // MySQL 5.7 has no window functions. Keep each document's lexical
            // limit inside its own UNION branch: unlike session-variable row
            // numbers, this does not depend on the optimizer preserving a
            // derived table's ORDER BY during expression evaluation.
            $branches = [];
            $args = [];
            foreach ($ids as $postId) {
                $branches[] = "(SELECT p.post_id, t.lang, t.term
FROM {$this->postingsTable} p FORCE INDEX (post_term_impact)
STRAIGHT_JOIN {$this->termsTable} t FORCE INDEX (PRIMARY) ON t.term_id = p.term_id
WHERE p.post_id = %d AND t.kind = " . self::LEXICAL_KIND . "
ORDER BY t.lang, t.term
LIMIT %d)";
                array_push($args, $postId, $perDocLimit);
            }
            $statement = $this->wpdb->prepare(
                "SELECT bounded.post_id, bounded.lang, bounded.term
FROM (\n" . implode("\nUNION ALL\n", $branches) . "\n) bounded
ORDER BY bounded.post_id, bounded.lang, bounded.term",
                ...$args
            );
        }
        $rows = $this->get_results($statement, 'read bounded FTS document terms');
        $terms = [];
        foreach ($rows as $row) {
            $postId = max(0, (int) ($row->post_id ?? 0));
            if ($postId <= 0) {
                continue;
            }
            $terms[$postId][] = WP_FTS_TermNamespace::namespace_term(
                (string) ($row->lang ?? ''),
                (string) ($row->term ?? '')
            );
        }

        return $terms;
    }

    /** @return string[] */
    public function terms_with_prefix(string $prefix, int $limit): array
    {
        $this->reject_legacy_unbounded_operation();
    }

    /** Start the single writer transaction after enforcing mutation ownership. */
    public function begin_transaction(): void
    {
        $this->guard_mutation();
        if ($this->transactionActive) {
            throw new LogicException('Nested FTS storage transactions are not supported.');
        }
        $this->query('START TRANSACTION', 'start FTS transaction');
        $this->transactionActive = true;
        $this->transactionMutated = false;
        $this->transactionEpochAdvanced = false;
    }

    /** Advance the cursor epoch once, then commit and clear local transaction state. */
    public function commit(): void
    {
        $this->guard_mutation();
        try {
            if ($this->transactionActive && $this->transactionMutated && !$this->transactionEpochAdvanced) {
                $this->advance_search_epoch();
                $this->transactionEpochAdvanced = true;
            }
            $this->query('COMMIT', 'commit FTS transaction');
        } finally {
            $this->transactionActive = false;
            $this->transactionMutated = false;
            $this->transactionEpochAdvanced = false;
        }
    }

    /** Expose transaction ownership to the queue's atomic retirement path. */
    public function has_active_transaction(): bool
    {
        return $this->transactionActive;
    }

    /** Publish the cursor boundary immediately before the final lease retirement. */
    public function advance_epoch_before_capability_retirement(): void
    {
        if (!$this->transactionActive || !$this->transactionMutated || $this->transactionEpochAdvanced) {
            throw new LogicException('FTS worker publication requires one active mutated transaction.');
        }
        $this->advance_search_epoch();
        $this->transactionEpochAdvanced = true;
    }

    /** Roll back database work and always clear local transaction state. */
    public function rollback(): void
    {
        try {
            $this->query('ROLLBACK', 'roll back FTS transaction');
        } finally {
            $this->transactionActive = false;
            $this->transactionMutated = false;
            $this->transactionEpochAdvanced = false;
        }
    }

    /** No-op for MySQL because writes are sent immediately. */
    public function flush(): void
    {
    }

    /**
     * Publish a fresh empty four-table generation without corpus-size deletes.
     *
     * WordPress content is untouched. The caller schedules bounded corpus
     * reconciliation after this storage-level reset.
     *
     * @return array<string,mixed>
     */
    public function reset_index(): array
    {
        $this->guard_mutation();
        if ($this->transactionActive) {
            throw new LogicException('FTS index reset cannot run inside another storage transaction.');
        }

        $epoch = $this->current_search_epoch_for_reset();
        if ($epoch >= PHP_INT_MAX) {
            throw new RuntimeException('FTS search epoch is exhausted; reset cannot preserve cursor monotonicity.');
        }
        $nextEpoch = $epoch + 1;

        if ($this->is_sqlite_runtime()) {
            $this->reset_sqlite_generation($nextEpoch);
            $strategy = 'sqlite_transactional_schema_rebuild';
        } else {
            $this->reset_mysql_generation($nextEpoch);
            $strategy = 'mysql_atomic_table_swap';
        }

        // DDL reset deliberately avoids COUNT(*) and row-by-row deletion. Exact
        // removed-row counts would reintroduce corpus work merely for cosmetics.
        return [
            'reset_strategy' => $strategy,
            'counts_exact' => false,
            'postings_deleted' => null,
            'docs_deleted' => null,
            'terms_deleted' => null,
            'pending_queue_cleared' => null,
            'search_epoch' => $nextEpoch,
        ];
    }

    /** Read the cursor generation that the replacement schema must surpass. */
    private function current_search_epoch_for_reset(): int
    {
        $value = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT generation FROM {$this->workTable} WHERE job_key = %s AND kind = 'meta' LIMIT 1",
            WP_FTS_Index_Queue::SEARCH_EPOCH_JOB_KEY
        ));
        $this->assert_no_database_error('read FTS search epoch before reset');

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /** Publish four empty MySQL tables through one atomic generation swap. */
    private function reset_mysql_generation(int $nextEpoch): void
    {
        $current = [$this->termsTable, $this->postingsTable, $this->documentsTable, $this->workTable];
        $staging = [];
        $retired = [];
        foreach ($current as $table) {
            $staging[$table] = $this->reset_generation_table_name($table, 'new');
            $retired[$table] = $this->reset_generation_table_name($table, 'old');
        }

        $stale = [];
        foreach ([$staging, $retired] as $generation) {
            foreach ($current as $table) {
                $stale[] = $this->required_schema_identifier($generation[$table]);
            }
        }
        $this->query('DROP TABLE IF EXISTS ' . implode(', ', $stale), 'remove stale FTS reset generations');

        foreach ($current as $table) {
            $this->query(
                'CREATE TABLE ' . $this->required_schema_identifier($staging[$table])
                . ' LIKE ' . $this->required_schema_identifier($table),
                'create empty FTS reset generation'
            );
        }
        $this->seed_search_epoch($staging[$this->workTable], $nextEpoch);

        // The publication point is one metadata-only atomic rename. Recheck the
        // writer lease immediately before it, not only before staging work.
        $this->guard_mutation();
        $renames = [];
        foreach ($current as $table) {
            $renames[] = $this->required_schema_identifier($table)
                . ' TO ' . $this->required_schema_identifier($retired[$table]);
            $renames[] = $this->required_schema_identifier($staging[$table])
                . ' TO ' . $this->required_schema_identifier($table);
        }
        $this->query('RENAME TABLE ' . implode(', ', $renames), 'publish empty FTS reset generation');

        // A failed retirement is visible and leaves readiness pending even
        // though the fresh generation is already safely published.
        $this->guard_mutation();
        $this->query(
            'DROP TABLE IF EXISTS ' . implode(', ', array_map(
                fn(string $table): string => $this->required_schema_identifier($retired[$table]),
                $current
            )),
            'retire previous FTS reset generation'
        );
    }

    /** Rebuild all four SQLite tables inside one rollback-safe transaction. */
    private function reset_sqlite_generation(int $nextEpoch): void
    {
        $this->query('START TRANSACTION', 'start SQLite FTS reset transaction');
        try {
            foreach ([$this->postingsTable, $this->documentsTable, $this->termsTable, $this->workTable] as $table) {
                $this->query(
                    'DROP TABLE IF EXISTS ' . $this->required_schema_identifier($table),
                    'drop SQLite FTS reset table'
                );
            }
            foreach ($this->sqlite_schema_creation_statements() as $statement) {
                $this->query($statement, 'create SQLite FTS reset schema');
            }
            $this->seed_search_epoch($this->workTable, $nextEpoch);
            $this->guard_mutation();
            $this->query('COMMIT', 'commit SQLite FTS reset transaction');
        } catch (Throwable $error) {
            try {
                $this->query('ROLLBACK', 'roll back failed SQLite FTS reset');
            } catch (Throwable) {
                // Preserve the reset failure.
            }
            throw $error;
        }
    }

    /** Seed the singleton cursor epoch before a new generation becomes visible. */
    private function seed_search_epoch(string $workTable, int $generation): void
    {
        $this->query($this->wpdb->prepare(
            'INSERT INTO ' . $this->required_schema_identifier($workTable) . "
    (job_key, kind, post_id, generation, state, available_at, attempts, claim_token, claimed_generation, claim_expires_at, cursor_post_id, scope_subject_type, scope_subject_id, payload, last_error_code, last_error_at)
VALUES (%s, 'meta', 0, %d, 'meta', 0, 0, '', 0, 0, 0, '', 0, %s, '', 0)",
            WP_FTS_Index_Queue::SEARCH_EPOCH_JOB_KEY,
            $generation,
            bin2hex(random_bytes(16))
        ), 'seed FTS search epoch after reset');
    }

    /** Derive deterministic bounded staging names without schema discovery. */
    private function reset_generation_table_name(string $table, string $role): string
    {
        $suffix = '_r' . ($role === 'new' ? 'n' : 'o')
            . '_' . substr(hash('sha256', $table . '|' . $role), 0, 10);
        $name = substr($table, 0, 64 - strlen($suffix)) . $suffix;
        if (strlen($name) > 64 || $this->schema_identifier($name) === null) {
            throw new RuntimeException('Could not derive a safe FTS reset table identifier.');
        }

        return $name;
    }

    /**
     * Return every deterministic staging/retired table owned by reset.
     *
     * A failed reset can leave either generation behind. Uninstall consumes
     * this exact list instead of discovering tables through an unbounded
     * information-schema scan or duplicating the naming algorithm.
     *
     * @return string[]
     */
    public function reset_generation_table_names(): array
    {
        $names = [];
        foreach ([$this->termsTable, $this->postingsTable, $this->documentsTable, $this->workTable] as $table) {
            $names[] = $this->reset_generation_table_name($table, 'new');
            $names[] = $this->reset_generation_table_name($table, 'old');
        }

        return $names;
    }

    /** Convert a validated table name to SQL or fail closed. */
    private function required_schema_identifier(string $table): string
    {
        $identifier = $this->schema_identifier($table);
        if ($identifier === null) {
            throw new RuntimeException('Invalid FTS reset table identifier.');
        }

        return $identifier;
    }

    /** @return string[] */
    private function sqlite_schema_creation_statements(): array
    {
        $terms = $this->required_schema_identifier($this->termsTable);
        $postings = $this->required_schema_identifier($this->postingsTable);
        $documents = $this->required_schema_identifier($this->documentsTable);
        $work = $this->required_schema_identifier($this->workTable);
        $index = fn(string $table, string $suffix): string => $this->required_schema_identifier($table . '_' . $suffix);

        return [
            "CREATE TABLE {$terms} (term_id INTEGER PRIMARY KEY AUTOINCREMENT, lang BLOB NOT NULL, kind INTEGER NOT NULL DEFAULT 0, term BLOB NOT NULL, doc_freq INTEGER NOT NULL DEFAULT 0)",
            'CREATE UNIQUE INDEX ' . $index($this->termsTable, 'term_identity') . " ON {$terms}(lang,kind,term)",
            'CREATE INDEX ' . $index($this->termsTable, 'empty_terms') . " ON {$terms}(doc_freq)",
            "CREATE TABLE {$postings} (term_id INTEGER NOT NULL, post_id INTEGER NOT NULL, impact INTEGER NOT NULL, PRIMARY KEY(term_id,post_id))",
            'CREATE INDEX ' . $index($this->postingsTable, 'post_term_impact') . " ON {$postings}(post_id,term_id,impact)",
            "CREATE TABLE {$documents} (post_id INTEGER PRIMARY KEY, primary_lang BLOB NOT NULL DEFAULT 'und', content_hash BLOB NULL, snippet_text TEXT NULL, indexed_at INTEGER NOT NULL DEFAULT 0)",
            "CREATE TABLE {$work} (job_key BLOB PRIMARY KEY, kind TEXT NOT NULL, post_id INTEGER NOT NULL DEFAULT 0, generation INTEGER NOT NULL DEFAULT 1, state TEXT NOT NULL DEFAULT 'pending', available_at INTEGER NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0, claim_token TEXT NOT NULL DEFAULT '', claimed_generation INTEGER NOT NULL DEFAULT 0, claim_expires_at INTEGER NOT NULL DEFAULT 0, cursor_post_id INTEGER NOT NULL DEFAULT 0, scope_coverage TEXT NOT NULL DEFAULT '', scope_incarnation BLOB NOT NULL DEFAULT '', scope_subject_type TEXT NOT NULL DEFAULT '', scope_subject_id INTEGER NOT NULL DEFAULT 0, payload TEXT NULL, last_error_code TEXT NOT NULL DEFAULT '', last_error_at INTEGER NOT NULL DEFAULT 0)",
            'CREATE INDEX ' . $index($this->workTable, 'ready') . " ON {$work}(kind,state,available_at,post_id,job_key)",
            'CREATE INDEX ' . $index($this->workTable, 'recoverable') . " ON {$work}(kind,state,claim_expires_at,available_at,post_id,job_key)",
            'CREATE INDEX ' . $index($this->workTable, 'claim_token') . " ON {$work}(claim_token,post_id)",
            'CREATE INDEX ' . $index($this->workTable, 'kind_job') . " ON {$work}(kind,job_key)",
            'CREATE INDEX ' . $index($this->workTable, 'scope_subject') . " ON {$work}(kind,scope_coverage,scope_subject_type,scope_subject_id)",
            'CREATE INDEX ' . $index($this->workTable, 'dirty') . " ON {$work}(post_id,kind)",
        ];
    }

    /** Perform one bounded dictionary-maintenance page, never a corpus sweep. */
    public function optimize(): void
    {
        // Document frequencies are a transactional writer invariant. Removing
        // a zero-frequency row changes neither membership nor score, so this
        // bounded maintenance delete needs no cursor epoch or transaction.
        $this->cleanup_empty_terms();
    }

    /** Remove one indexed zero-frequency dictionary page during maintenance. */
    public function cleanup_empty_terms(): int
    {
        $this->guard_mutation();

        // A caller repeats only when the full page was removed, so vocabulary
        // churn cannot create either an unbounded transaction or permanent
        // stale surface-identity debt. SQLite has no multi-table DELETE; its bounded IN
        // form still performs exact primary-key deletes.
        if ($this->is_sqlite_runtime()) {
            $sql = "DELETE FROM {$this->termsTable}
WHERE term_id IN (
    SELECT bounded_empty_terms.term_id
    FROM (
        SELECT term_id
        FROM {$this->termsTable}
        WHERE doc_freq = 0
        ORDER BY term_id
        LIMIT " . self::MAX_EMPTY_TERM_CLEANUP . "
    ) bounded_empty_terms
)";
        } else {
            // MariaDB scans the whole mutable target for `DELETE ... WHERE key
            // IN (derived LIMIT)`. Drive from the non-mergeable 1,000-row
            // selector instead and force each target lookup through PRIMARY.
            $sql = "DELETE /* wp_fts:cleanup-empty-terms */ cleanup_target
FROM (
    SELECT bounded_empty_terms.term_id
    FROM (
        SELECT term_id
        FROM {$this->termsTable} FORCE INDEX (empty_terms)
        WHERE doc_freq = 0
        ORDER BY term_id
        LIMIT " . self::MAX_EMPTY_TERM_CLEANUP . "
    ) bounded_empty_terms
) cleanup_driver
STRAIGHT_JOIN {$this->termsTable} cleanup_target
        ON cleanup_target.term_id = cleanup_driver.term_id";
        }

        return $this->query($sql, 'remove bounded empty FTS terms');
    }

    /** @return array{lang:string,kind:int,term:string,key:string,storage_key:string} */
    private function term_identity(string $key, int $kind = self::LEXICAL_KIND): array
    {
        if (strlen($key) > self::MAX_TERM_IDENTITY_INPUT_BYTES) {
            throw new InvalidArgumentException('FTS term identity exceeds the v4 lexical key contract.');
        }
        $split = WP_FTS_TermNamespace::split_term($key);
        $lang = $split === null ? 'und' : $split['lang'];
        $term = $split === null ? $key : $split['term'];
        $lang = WP_FTS_TermNamespace::canonicalize_lang($lang, 'und');
        if ($term === '' || strlen($term) > 255 || strlen($lang) > 32) {
            throw new InvalidArgumentException('FTS term identity exceeds the v4 lexical key contract.');
        }
        $canonicalKey = WP_FTS_TermNamespace::namespace_term($lang, $term);
        if (!in_array($kind, [self::LEXICAL_KIND, self::SURFACE_KIND], true)) {
            throw new InvalidArgumentException('FTS term identity has an unsupported kind.');
        }
        return [
            'lang' => $lang,
            'kind' => $kind,
            'term' => $term,
            'key' => $canonicalKey,
            'storage_key' => $kind . "\0" . $canonicalKey,
        ];
    }

    /**
     * Measure one monotone SQLite prefix without rendering a multi-megabyte SQL
     * string for every document. Each posting identity is visited once; exact
     * row renderers shared with execution keep the byte proof from drifting.
     *
     * @param array<int,array<string,mixed>> $documents
     * @return array{accepted_documents:int,dictionary_bytes:int,resolution_bytes:int,identity_rows:int,identity_visits:int}
     */
    private function sqlite_prepared_transport_prefix(array $documents): array
    {
        $dictionaryBytes = strlen($this->sqlite_dictionary_increment_prefix())
            + strlen($this->sqlite_dictionary_increment_suffix());
        $emptyRelation = $this->sqlite_identity_relation_prefix() . $this->sqlite_identity_relation_suffix();
        $resolutionBytes = strlen($this->prepared_term_resolution_sql($emptyRelation));
        // One count map is enough both to deduplicate identities and adjust the
        // exact rendered row width. Retaining a second full identity graph here
        // needlessly competes with the caller's maximum-width document maps.
        $documentCounts = [];
        $identityRows = 0;
        $identityVisits = 0;
        $acceptedDocuments = 0;

        foreach ($documents as $document) {
            foreach ([
                self::LEXICAL_KIND => ($document['term_frequencies'] ?? []),
                self::SURFACE_KIND => ($document['surface_frequencies'] ?? []),
            ] as $kind => $frequencies) {
                foreach (array_keys($frequencies) as $key) {
                    $identityVisits++;
                    $identity = $this->term_identity((string) $key, $kind);
                    $storageKey = $identity['storage_key'];
                    if (!isset($documentCounts[$storageKey])) {
                        $documentCounts[$storageKey] = 1;
                        $identityRows++;
                        $separatorBytes = $identityRows === 1 ? 0 : 1;
                        $dictionaryBytes += $separatorBytes
                            + strlen($this->sqlite_dictionary_increment_row($identity, 1));
                        $resolutionBytes += $separatorBytes
                            + strlen($this->sqlite_identity_relation_row($identity, $identityRows));
                        continue;
                    }

                    $oldCount = $documentCounts[$storageKey];
                    $newCount = $oldCount + 1;
                    $documentCounts[$storageKey] = $newCount;
                    $dictionaryBytes += strlen((string) $newCount) - strlen((string) $oldCount);
                }
            }

            if ($dictionaryBytes > self::MAX_WRITE_SQL_BYTES || $resolutionBytes > self::MAX_WRITE_SQL_BYTES) {
                break;
            }
            $acceptedDocuments++;
        }

        return [
            'accepted_documents' => $acceptedDocuments,
            'dictionary_bytes' => $dictionaryBytes,
            'resolution_bytes' => $resolutionBytes,
            'identity_rows' => $identityRows,
            'identity_visits' => $identityVisits,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $documents
     * @return array{0:array<string,array{lang:string,kind:int,term:string}>,1:array<string,int>}
     */
    private function prepared_term_identities(array $documents): array
    {
        $identities = [];
        $documentCounts = [];
        foreach ($documents as $document) {
            foreach ([
                self::LEXICAL_KIND => ($document['term_frequencies'] ?? []),
                self::SURFACE_KIND => ($document['surface_frequencies'] ?? []),
            ] as $kind => $frequencies) {
                foreach (array_keys($frequencies) as $key) {
                    $identity = $this->term_identity((string) $key, $kind);
                    $storageKey = $identity['storage_key'];
                    if (!isset($identities[$storageKey])) {
                        // The map key already carries the storage identity. Do
                        // not retain duplicate canonical/storage-key strings in
                        // every maximum-width identity row.
                        $identities[$storageKey] = [
                            'lang' => $identity['lang'],
                            'kind' => $identity['kind'],
                            'term' => $identity['term'],
                        ];
                    }
                    $documentCounts[$storageKey] = ($documentCounts[$storageKey] ?? 0) + 1;
                }
            }
        }

        return [$identities, $documentCounts];
    }

    /**
     * Render the one SQLite dictionary UPSERT proven by the pure-PHP preflight.
     *
     * @param array<string,array{lang:string,kind:int,term:string}> $identities
     * @param array<string,int> $documentCounts
     */
    private function sqlite_term_identity_increment_statement(array $identities, array $documentCounts): string
    {
        // Append directly so the final near-4-MiB statement does not coexist
        // with an array containing another complete copy of every rendered row.
        $statement = $this->sqlite_dictionary_increment_prefix();
        $row = 0;
        foreach ($identities as $storageKey => $identity) {
            $statement .= ($row++ === 0 ? '' : ',') . $this->sqlite_dictionary_increment_row(
                $identity,
                max(0, (int) ($documentCounts[$storageKey] ?? 0))
            );
        }
        $statement .= $this->sqlite_dictionary_increment_suffix();

        return $statement;
    }

    /** Start the single SQLite dictionary-increment statement. */
    private function sqlite_dictionary_increment_prefix(): string
    {
        return "INSERT INTO {$this->termsTable} (lang,kind,term,doc_freq)\n"
            . "/* wp_fts:dictionary-increment */\nVALUES ";
    }

    /** Close the SQLite statement with its conflict-safe DF increment. */
    private function sqlite_dictionary_increment_suffix(): string
    {
        return "\nON CONFLICT(lang,kind,term) DO UPDATE SET doc_freq = doc_freq + excluded.doc_freq";
    }

    /** @param array{lang:string,kind:int,term:string} $identity */
    private function sqlite_dictionary_increment_row(array $identity, int $documentCount): string
    {
        return '(' . $this->binary_sql_literal($identity['lang'])
            . ',' . (int) $identity['kind']
            . ',' . $this->binary_sql_literal($identity['term'])
            . ',' . max(0, $documentCount) . ')';
    }

    /** Start the bounded VALUES relation used to resolve SQLite term ids. */
    private function sqlite_identity_relation_prefix(): string
    {
        return 'SELECT column1 AS term_ordinal, column2 AS lang,'
            . ' column3 AS kind, column4 AS term FROM (VALUES ';
    }

    /** Close the bounded SQLite identity relation. */
    private function sqlite_identity_relation_suffix(): string
    {
        return ')';
    }

    /** @param array{lang:string,kind:int,term:string} $identity */
    private function sqlite_identity_relation_row(array $identity, int $ordinal): string
    {
        return '(' . $ordinal
            . ',' . $this->binary_sql_literal($identity['lang'])
            . ',' . (int) $identity['kind']
            . ',' . $this->binary_sql_literal($identity['term']) . ')';
    }

    /** Wrap one bounded identity relation in the exact dictionary-id lookup. */
    private function prepared_term_resolution_sql(string $identityRelation): string
    {
        return $this->prepared_term_resolution_prefix()
            . $identityRelation
            . $this->prepared_term_resolution_suffix();
    }

    /** Start the portable prepared-term resolution statement. */
    private function prepared_term_resolution_prefix(): string
    {
        return "/* wp_fts:resolve-prepared-terms */
SELECT requested.term_ordinal, stored_term.term_id
FROM (";
    }

    /** Join the requested identities to the unique dictionary key. */
    private function prepared_term_resolution_suffix(): string
    {
        return ") requested
INNER JOIN {$this->termsTable} stored_term" . ($this->is_sqlite_runtime() ? '' : ' FORCE INDEX (term_identity)') . "
  ON stored_term.lang = requested.lang
 AND stored_term.kind = requested.kind
 AND stored_term.term = requested.term
ORDER BY requested.term_ordinal";
    }

    /**
     * Compile the bounded dictionary-id read after the dictionary UPSERT.
     *
     * Keeping 8,192 numeric posting tuples out of both wpdb::prepare() and a
     * duplicate PHP row graph avoids their split/escape/array copies on
     * low-memory hosts. The exact identity read maps local ordinals to ids; the
     * caller then streams validated integers from the prepared documents into
     * one plain VALUES statement.
     *
     * @param array<string,array{lang:string,kind:int,term:string}> $identities
     * @return array{0:mixed,1:array<int,string>}
     */
    private function prepare_posting_replacement(array $identities): array
    {
        if ($identities === []) {
            return [null, []];
        }

        $keysByOrdinal = [];
        $ordinal = 0;
        foreach ($identities as $key => $_identity) {
            $keysByOrdinal[++$ordinal] = $key;
        }
        if (count($identities) > self::MAX_TERM_RESOLUTION_IDENTITIES) {
            throw new LogicException('The prepared FTS identity read crossed its validated statement bound.');
        }

        if ($this->is_sqlite_runtime()) {
            $sql = $this->sqlite_prepared_term_resolution_sql($identities);
            $args = [];
        } else {
            $identityRelation = $this->term_identity_ordinal_relation($identities);
            $sql = $this->prepared_term_resolution_sql($identityRelation['sql']);
            $args = $identityRelation['args'];
        }
        $statement = $args === [] ? $sql : $this->wpdb->prepare($sql, ...$args);
        $bytes = $this->prepared_statement_bytes($statement);
        if ($bytes > self::MAX_WRITE_SQL_BYTES) {
            throw new LengthException(
                "The prepared FTS dictionary-id SELECT exceeds the 4 MiB SQL statement contract ({$bytes} bytes)."
            );
        }

        return [$statement, $keysByOrdinal];
    }

    /** @param array<string,array{lang:string,kind:int,term:string}> $identities */
    private function sqlite_prepared_term_resolution_sql(array $identities): string
    {
        // Start with the complete SELECT prefix so the maximum-width relation
        // is never copied into a second, wrapped SQL string.
        $sql = $this->prepared_term_resolution_prefix();
        $sql .= $this->sqlite_identity_relation_prefix();
        $row = 0;
        foreach ($identities as $identity) {
            $row++;
            $sql .= ($row === 1 ? '' : ',')
                . $this->sqlite_identity_relation_row($identity, $row);
        }
        $sql .= $this->sqlite_identity_relation_suffix();
        $sql .= $this->prepared_term_resolution_suffix();

        return $sql;
    }

    /** @param array<int,string> $keysByOrdinal @return array<string,int> */
    private function resolve_prepared_term_ordinals(mixed $statement, array $keysByOrdinal): array
    {
        $rows = $this->get_results($statement, 'resolve prepared FTS dictionary ids');
        $resolved = [];
        foreach ($rows as $row) {
            $ordinal = max(0, (int) ($row->term_ordinal ?? 0));
            $termId = max(0, (int) ($row->term_id ?? 0));
            $key = $keysByOrdinal[$ordinal] ?? '';
            if ($key === '' || $termId <= 0 || isset($resolved[$key])) {
                throw new RuntimeException('The prepared FTS dictionary-id read returned an invalid ordinal.');
            }
            $resolved[$key] = $termId;
        }
        if (count($resolved) !== count($keysByOrdinal)) {
            throw new RuntimeException(
                'The FTS writer resolved ' . count($resolved) . ' of '
                . count($keysByOrdinal) . ' inserted dictionary identities.'
            );
        }

        return $resolved;
    }

    /**
     * @param array<int,array<string,mixed>> $documents
     * @param array<string,int> $termIds
     */
    private function resolved_posting_insert(array $documents, array $termIds, int $expectedRows): string
    {
        $sql = "INSERT INTO {$this->postingsTable} (term_id,post_id,impact)
/* wp_fts:posting-replacement */
VALUES ";
        $rowCount = 0;
        foreach ($documents as $document) {
            $postId = max(0, (int) ($document['post_id'] ?? 0));
            foreach ([
                self::LEXICAL_KIND => ($document['term_frequencies'] ?? []),
                self::SURFACE_KIND => ($document['surface_frequencies'] ?? []),
            ] as $kind => $frequencies) {
                foreach ($frequencies as $key => $frequency) {
                    $termId = $termIds[$kind . "\0" . $key] ?? 0;
                    $impact = $this->impact((int) $frequency);
                    if ($termId <= 0 || $postId <= 0 || $impact <= 0 || $impact > self::MAX_POSTING_IMPACT) {
                        throw new RuntimeException('The resolved FTS posting relation contains an invalid numeric tuple.');
                    }
                    $sql .= ($rowCount === 0 ? '' : ',') . '(' . $termId . ',' . $postId . ',' . $impact . ')';
                    $rowCount++;
                }
            }
        }
        if ($rowCount !== $expectedRows || $rowCount <= 0 || $rowCount > self::MAX_BATCH_POSTINGS) {
            throw new LogicException('The resolved FTS posting relation crossed its validated batch bounds.');
        }
        $bytes = strlen($sql);
        if ($bytes > self::MAX_WRITE_SQL_BYTES) {
            throw new LengthException(
                "The resolved FTS posting INSERT exceeds the 4 MiB SQL statement contract ({$bytes} bytes)."
            );
        }

        return $sql;
    }

    /**
     * @param array<string,array{lang:string,kind:int,term:string}> $identities
     * @return array{sql:string,args:array<int,mixed>}
     */
    private function term_identity_ordinal_relation(array $identities): array
    {
        $chunks = [];
        $args = [];
        $ordinal = 0;
        foreach (array_chunk($identities, 100, true) as $chunkIndex => $chunk) {
            $rows = [];
            foreach ($chunk as $identity) {
                $ordinal++;
                $lang = $this->binary_sql_literal($identity['lang']);
                $term = $this->binary_sql_literal($identity['term']);
                if ($rows === []) {
                    $rows[] = 'SELECT ' . $ordinal . ' AS term_ordinal, '
                        . $lang . ' AS lang, '
                        . (int) $identity['kind'] . ' AS kind, ' . $term . ' AS term';
                } else {
                    // MySQL 5.7 derives UNION column names from the first arm.
                    $rows[] = 'SELECT ' . $ordinal . ', '
                        . $lang . ', ' . (int) $identity['kind'] . ', ' . $term;
                }
            }
            // A flat thousands-arm UNION overruns the default MySQL 5.7 thread
            // stack. Keep both the leaf and outer relations at a few hundred
            // arms by grouping constants into fixed 100-row derived tables.
            $chunks[] = "SELECT term_ordinal, lang, kind, term\nFROM ("
                . implode("\nUNION ALL\n", $rows) . ") identity_chunk_{$chunkIndex}";
        }

        return ['sql' => implode("\nUNION ALL\n", $chunks), 'args' => $args];
    }

    /**
     * Delete one measured old-posting frontier and its now-unused rows.
     *
     * A direct three-target join lets MariaDB drive the DELETE through the
     * dictionary's `empty_terms` index, scanning and locking unrelated empty
     * terms. The double-derived driver instead materializes at most 50,000
     * measured postings plus one NULL-posting row for each of at most 100
     * documents. Its 50,100 LIMIT is both the proven row maximum and the
     * optimizer barrier that prevents the driver from being merged. Every
     * delete target is then reached by primary key, so dictionary size cannot
     * change the hot transaction's work.
     *
     * @param int[] $affectedPosts
     * @param int[] $deleteDocumentIds
     */
    private function delete_replaced_index_rows(array $affectedPosts, array $deleteDocumentIds): void
    {
        $postIds = $this->normalize_doc_ids($affectedPosts);
        if ($postIds === []) {
            return;
        }
        $deleteIds = array_fill_keys($this->normalize_doc_ids($deleteDocumentIds), true);
        if ($this->is_sqlite_runtime()) {
            $this->delete_empty_terms_for_posts($postIds);
            $this->delete_postings_for_posts($postIds);
            if ($deleteIds !== []) {
                $placeholders = implode(',', array_fill(0, count($deleteIds), '%d'));
                $this->query($this->wpdb->prepare(
                    "DELETE FROM {$this->documentsTable} WHERE post_id IN ({$placeholders})",
                    ...array_keys($deleteIds)
                ), 'delete FTS documents');
            }
            return;
        }

        $driver = [];
        $args = [];
        foreach ($postIds as $postId) {
            $driver[] = 'SELECT %d AS post_id, %d AS delete_document';
            array_push($args, $postId, isset($deleteIds[$postId]) ? 1 : 0);
        }
        $driverLimit = self::MAX_BATCH_POSTINGS + self::MAX_BATCH_DOCUMENTS;
        $sql = "DELETE old_posting, retired_term, retired_document
/* wp_fts:bounded-index-delete */
FROM (
    SELECT bounded_rows.post_id, bounded_rows.delete_document, bounded_rows.term_id
    FROM (
        SELECT affected.post_id, affected.delete_document, candidate_posting.term_id
        FROM (" . implode("\nUNION ALL\n", $driver) . ") affected
        LEFT JOIN {$this->postingsTable} candidate_posting FORCE INDEX (post_term_impact)
          ON candidate_posting.post_id = affected.post_id
    ) bounded_rows
    LIMIT {$driverLimit}
) delete_driver
LEFT JOIN {$this->postingsTable} old_posting FORCE INDEX (PRIMARY)
  ON old_posting.term_id = delete_driver.term_id AND old_posting.post_id = delete_driver.post_id
LEFT JOIN {$this->termsTable} retired_term FORCE INDEX (PRIMARY)
  ON retired_term.term_id = delete_driver.term_id AND retired_term.doc_freq = 0
LEFT JOIN {$this->documentsTable} retired_document FORCE INDEX (PRIMARY)
  ON retired_document.post_id = delete_driver.post_id AND delete_driver.delete_document = 1";
        $this->query(
            $this->wpdb->prepare($sql, ...$args),
            'delete replaced FTS index rows'
        );
    }

    /** @param int[] $postIds */
    private function delete_empty_terms_for_posts(array $postIds): void
    {
        $ids = $this->normalize_doc_ids($postIds);
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $this->query($this->wpdb->prepare(
            "DELETE FROM {$this->termsTable}
WHERE doc_freq = 0
  AND term_id IN (
      SELECT retired_posting.term_id
      FROM {$this->postingsTable} AS retired_posting
      WHERE retired_posting.post_id IN ({$placeholders})
  )",
            ...$ids
        ), 'delete replaced FTS empty terms');
    }

    /**
     * Subtract the bounded old-posting counts from dictionary DFs.
     *
     * MySQL/MariaDB starts from the post-first covering index, groups only the
     * affected posting rows, then primary-key joins those term ids. There is no
     * `OR`, correlated dictionary probe, or constant arm per term.
     *
     * @param int[] $postIds
     */
    private function decrement_doc_freq_for_posts(array $postIds): void
    {
        $ids = $this->normalize_doc_ids($postIds);
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        if ($this->is_sqlite_runtime()) {
            $sql = "UPDATE {$this->termsTable} AS t
/* wp_fts:dictionary-decrement */
SET doc_freq = MAX(0, CAST(t.doc_freq AS INTEGER) - (
    SELECT COUNT(*) FROM {$this->postingsTable} changed_count
    WHERE changed_count.term_id = t.term_id
      AND changed_count.post_id IN ({$placeholders})
))
WHERE t.term_id IN (
    SELECT changed_filter.term_id FROM {$this->postingsTable} changed_filter
    WHERE changed_filter.post_id IN ({$placeholders})
    GROUP BY changed_filter.term_id
)";
            $args = [...$ids, ...$ids];
        } else {
            $sql = "UPDATE (
    SELECT changed.term_id, COUNT(*) AS document_delta
    FROM {$this->postingsTable} changed FORCE INDEX (post_term_impact)
    WHERE changed.post_id IN ({$placeholders})
    GROUP BY changed.term_id
) affected
STRAIGHT_JOIN {$this->termsTable} AS t FORCE INDEX (PRIMARY)
  ON affected.term_id = t.term_id
/* wp_fts:dictionary-decrement */
SET t.doc_freq = GREATEST(0, CAST(t.doc_freq AS SIGNED) - affected.document_delta)";
            $args = $ids;
        }
        $this->query($this->wpdb->prepare($sql, ...$args), 'decrement replaced FTS document frequencies');
    }

    /** @param int[] $postIds */
    private function delete_postings_for_posts(array $postIds): void
    {
        $ids = $this->normalize_doc_ids($postIds);
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $this->query($this->wpdb->prepare(
            "DELETE FROM {$this->postingsTable} WHERE post_id IN ({$placeholders})",
            ...$ids
        ), 'delete replaced FTS postings');
    }

    /** Advance the singleton cursor epoch inside the caller's write transaction. */
    private function advance_search_epoch(): void
    {
        $this->query($this->wpdb->prepare(
            "INSERT INTO {$this->workTable}
/* wp_fts:search-epoch-advance */
    (job_key, kind, post_id, generation, state, available_at, attempts, claim_token, claimed_generation, claim_expires_at, cursor_post_id, scope_subject_type, scope_subject_id, payload, last_error_code, last_error_at)
VALUES (%s, 'meta', 0, 1, 'meta', 0, 0, '', 0, 0, 0, '', 0, %s, '', 0)
ON DUPLICATE KEY UPDATE
    generation = generation + 1,
    kind = 'meta', post_id = 0, state = 'meta', available_at = 0,
    attempts = 0, claim_token = '', claimed_generation = 0,
    claim_expires_at = 0, cursor_post_id = 0,
    scope_subject_type = '', scope_subject_id = 0,
    last_error_code = '', last_error_at = 0",
            WP_FTS_Index_Queue::SEARCH_EPOCH_JOB_KEY,
            bin2hex(random_bytes(16))
        ), 'advance FTS search epoch');
    }

    /**
     * Resolve exact lexical terms and cost the final surface completion range.
     *
     * The same statement reads the singleton mutation epoch by primary key.
     * Cursors therefore fail closed after any membership or ordering change
     * without adding another search round trip.
     *
     * @return array{0:array<string,array<string,mixed>>,1:bool,2:int,3:int,4:string,5:bool}
     */
    private function resolve_search_plan_terms(
        array $keys,
        ?array $prefix,
        array $options,
        array $groups,
        string $mode
    ): array
    {
        $identities = [];
        foreach ($keys as $key) {
            $identity = $this->term_identity((string) $key);
            $identities[$identity['key']] = $identity;
        }

        $requestedRows = [];
        $requestedArgs = [];
        foreach ($identities as $identity) {
            $lang = $this->binary_sql_value($identity['lang']);
            $term = $this->binary_sql_value($identity['term']);
            $requestedRows[] = "SELECT {$lang['sql']} AS lang, " . self::LEXICAL_KIND
                . " AS kind, {$term['sql']} AS term";
            array_push($requestedArgs, ...$lang['args'], ...$term['args']);
        }
        $epochKey = ['sql' => '%s', 'args' => [WP_FTS_Index_Queue::SEARCH_EPOCH_JOB_KEY]];
        $epochSql = "COALESCE((SELECT search_epoch.generation
FROM {$this->workTable} search_epoch
WHERE search_epoch.job_key = {$epochKey['sql']} AND search_epoch.kind = 'meta'), 0)";
        $epochIncarnationSql = "COALESCE((SELECT search_epoch.payload
FROM {$this->workTable} search_epoch
WHERE search_epoch.job_key = {$epochKey['sql']} AND search_epoch.kind = 'meta'), '')";
        // Return scope state separately so search_page() can authenticate a
        // supplied cursor before its plan-only unavailable response. Rank and
        // hydration retain the full control and close a scope-enqueue race.
        $control = $this->search_snapshot_control_sql($options, false);
        $scopeIndexHint = $this->is_sqlite_runtime() ? '' : ' FORCE INDEX (scope_subject)';
        $reconciliationScopeSql = "EXISTS (SELECT 1
FROM {$this->workTable} reconciliation_scope{$scopeIndexHint}
WHERE reconciliation_scope.kind = 'scope'
LIMIT 1)";
        $planRowsSql = $requestedRows === []
            ? "SELECT 'control' AS row_kind, 0 AS term_id,
       '' AS lang, 0 AS kind, '' AS term, 0 AS doc_freq,
       0 AS surface_available, 0 AS surface_doc_freq"
            : "SELECT 'exact' AS row_kind, exact_term.term_id,
       requested_terms.lang, requested_terms.kind, requested_terms.term,
       exact_term.doc_freq,
       0 AS surface_available, 0 AS surface_doc_freq
FROM (" . implode("\nUNION ALL\n", $requestedRows) . ") requested_terms
LEFT JOIN {$this->termsTable} exact_term" . ($this->is_sqlite_runtime() ? '' : ' FORCE INDEX (term_identity)') . "
  ON exact_term.lang = requested_terms.lang
 AND exact_term.kind = requested_terms.kind
 AND exact_term.term = requested_terms.term";
        $planArgs = $requestedArgs;
        if ($prefix !== null) {
            $surfaceProbe = $this->surface_plan_probe_sql($prefix, $options, $groups, $mode);
            $planRowsSql .= "
UNION ALL
SELECT 'surface_available' AS row_kind, 0 AS term_id, '' AS lang,
       0 AS kind, '' AS term, 0 AS doc_freq,
       surface_probe.surface_available, surface_probe.surface_doc_freq
FROM ({$surfaceProbe['sql']}) surface_probe";
            array_push($planArgs, ...$surfaceProbe['args']);
        }
        $snapshotSql = "SELECT {$epochSql} AS search_epoch,
       {$epochIncarnationSql} AS search_epoch_incarnation,
       CASE WHEN {$control['sql']} THEN 1 ELSE 0 END AS capability_ready,
       {$reconciliationScopeSql} AS reconciliation_scope_active";
        $sql = "/* wp_fts:plan */
SELECT plan_rows.*, snapshot.search_epoch, snapshot.search_epoch_incarnation,
       snapshot.capability_ready, snapshot.reconciliation_scope_active
FROM ({$planRowsSql}) plan_rows
CROSS JOIN ({$snapshotSql}) snapshot";
        $args = [
            ...$planArgs,
            ...$epochKey['args'],
            ...$epochKey['args'],
            ...$control['args'],
        ];
        $rows = $this->get_results(
            $args === [] ? $sql : $this->wpdb->prepare($sql, ...$args),
            'resolve FTS search plan'
        );

        $resolved = [];
        $surfaceAvailable = false;
        $surfaceDocFreq = 0;
        $searchEpoch = 0;
        $searchEpochIncarnation = '';
        $capabilityReady = true;
        $reconciliationScopeActive = false;
        foreach ($rows as $row) {
            $searchEpoch = max($searchEpoch, max(0, (int) ($row->search_epoch ?? 0)));
            $candidateEpochIncarnation = is_scalar($row->search_epoch_incarnation ?? null)
                ? (string) $row->search_epoch_incarnation
                : '';
            if ($searchEpochIncarnation === '') {
                $searchEpochIncarnation = $candidateEpochIncarnation;
            } elseif (!hash_equals($searchEpochIncarnation, $candidateEpochIncarnation)) {
                $capabilityReady = false;
            }
            $capabilityReady = $capabilityReady && (int) ($row->capability_ready ?? 0) === 1;
            $reconciliationScopeActive = $reconciliationScopeActive
                || (int) ($row->reconciliation_scope_active ?? 0) === 1;
            if ((string) ($row->row_kind ?? '') === 'surface_available') {
                $surfaceAvailable = (int) ($row->surface_available ?? 0) === 1;
                $surfaceDocFreq = max(0, (int) ($row->surface_doc_freq ?? 0));
                continue;
            }
            if (max(0, (int) ($row->term_id ?? 0)) === 0) {
                continue;
            }
            $key = WP_FTS_TermNamespace::namespace_term((string) ($row->lang ?? 'und'), (string) ($row->term ?? ''));
            $expected = $identities[$key] ?? null;
            if (
                $expected === null
                || (int) ($row->kind ?? -1) !== self::LEXICAL_KIND
            ) {
                continue;
            }
            $resolved[$key] = [
                'term_id' => (int) $row->term_id,
                'doc_freq' => max(0, (int) $row->doc_freq),
                'lang' => $expected['lang'],
                'term' => $expected['term'],
            ];
        }

        if (
            !$capabilityReady
            || $searchEpoch <= 0
            || preg_match('/^[a-f0-9]{32}$/D', $searchEpochIncarnation) !== 1
        ) {
            throw new WP_FTS_Search_Unavailable('Full-text search publication changed during planning.');
        }

        return [$resolved, $surfaceAvailable, $surfaceDocFreq, $searchEpoch, $searchEpochIncarnation, $reconciliationScopeActive];
    }

    /**
     * Cost one surface completion range without reading its postings.
     *
     * The ordered gate is deliberately before the range. Revoked readiness,
     * reconciliation, or a missing epoch therefore produces zero without
     * walking the surface vocabulary. Exact rows in the same plan statement
     * let PHP compare this dictionary posting-row upper bound with every exact
     * logical group before the ranking statement chooses its driving relation.
     *
     * @return array{sql:string,args:array<int,mixed>}
     */
    private function surface_plan_probe_sql(
        array $prefix,
        array $options,
        array $groups = [],
        string $mode = 'OR'
    ): array
    {
        $range = $this->surface_range_sql($prefix);
        $control = $this->search_snapshot_control_sql($options);
        $epochKey = WP_FTS_Index_Queue::SEARCH_EPOCH_JOB_KEY;
        $epochIndexHint = $this->is_sqlite_runtime() ? '' : ' FORCE INDEX (PRIMARY)';
        $gateClauses = [$control['sql']];
        $gateArgs = $control['args'];
        if (strtoupper($mode) === 'AND' && count($groups) > 1) {
            $mandatoryRows = [];
            $mandatoryArgs = [];
            foreach ($groups as $groupId => $alternatives) {
                if ((int) $groupId === (int) ($prefix['group_id'] ?? -1)) {
                    continue;
                }
                foreach ($alternatives as $alternative) {
                    $identity = $this->term_identity((string) ($alternative['key'] ?? ''));
                    $lang = $this->binary_sql_value($identity['lang']);
                    $term = $this->binary_sql_value($identity['term']);
                    $mandatoryRows[] = 'SELECT ' . (int) $groupId . " AS group_id, {$lang['sql']} AS lang, "
                        . self::LEXICAL_KIND . " AS kind, {$term['sql']} AS term";
                    array_push($mandatoryArgs, ...$lang['args'], ...$term['args']);
                }
            }
            if ($mandatoryRows !== []) {
                $indexHint = $this->is_sqlite_runtime() ? '' : ' FORCE INDEX (term_identity)';
                $gateClauses[] = "NOT EXISTS (
    SELECT 1
    FROM (
        SELECT mandatory_requested.group_id
        FROM (" . implode("\nUNION ALL\n", $mandatoryRows) . ") mandatory_requested
        LEFT JOIN {$this->termsTable} mandatory_term{$indexHint}
          ON mandatory_term.lang = mandatory_requested.lang
         AND mandatory_term.kind = mandatory_requested.kind
         AND mandatory_term.term = mandatory_requested.term
        GROUP BY mandatory_requested.group_id
        HAVING COUNT(mandatory_term.term_id) = 0
    ) impossible_group
)";
                array_push($gateArgs, ...$mandatoryArgs);
            }
        }
        $gateClauses[] = "EXISTS (SELECT 1 FROM {$this->workTable} surface_epoch{$epochIndexHint}
            WHERE surface_epoch.job_key = %s AND surface_epoch.kind = 'meta'
              AND surface_epoch.generation > 0 AND surface_epoch.payload <> '')";
        $gateArgs[] = $epochKey;
        $gateWhere = implode("\nAND ", $gateClauses);
        $gateSql = "SELECT 1 AS range_ready WHERE {$gateWhere} LIMIT 1";

        $gateJoin = $this->is_sqlite_runtime() ? 'CROSS JOIN' : 'STRAIGHT_JOIN';
        $gatePredicate = $this->is_sqlite_runtime() ? '' : ' ON surface_gate.range_ready = 1';
        $sql = "SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END AS surface_available,
       COALESCE(SUM(surface_identity.doc_freq), 0) AS surface_doc_freq
FROM ({$gateSql}) surface_gate
{$gateJoin} ({$range['sql']}) surface_identity{$gatePredicate}";

        return ['sql' => $sql, 'args' => [...$gateArgs, ...$range['args']]];
    }

    /**
     * Build one primary-key control sentinel for the exact published snapshot.
     *
     * @return array{sql:string,args:array<int,mixed>}
     */
    private function search_snapshot_control_sql(array $options, bool $requireNoReconciliationScope = true): array
    {
        $incarnation = (string) ($options['search_ready_incarnation'] ?? '');
        $profile = (string) ($options['search_ready_profile_hash'] ?? '');
        $capability = serialize([
            'incarnation' => $incarnation,
            'profile_hash' => $profile,
        ]);
        $clauses = [
            "EXISTS (SELECT 1 FROM {$this->optionsTable} schema_option
                WHERE schema_option.option_name = %s AND schema_option.option_value = %s)",
            "EXISTS (SELECT 1 FROM {$this->optionsTable} desired_option
                WHERE desired_option.option_name = %s AND desired_option.option_value = %s)",
            "EXISTS (SELECT 1 FROM {$this->optionsTable} ready_option
                WHERE ready_option.option_name = %s AND ready_option.option_value = %s)",
        ];
        if ($requireNoReconciliationScope) {
            $clauses[] = "NOT EXISTS (SELECT 1 FROM {$this->workTable} scope_control"
                . ($this->is_sqlite_runtime() ? '' : ' FORCE INDEX (scope_subject)') . "
                WHERE scope_control.kind = 'scope'
                LIMIT 1)";
        }
        $args = [
            WP_FTS_Plugin::SCHEMA_VERSION_OPTION,
            (string) WP_FTS_Plugin::SCHEMA_VERSION,
            WP_FTS_Plugin::READINESS_INCARNATION_OPTION,
            $incarnation,
            WP_FTS_Plugin::SEARCH_READY_INCARNATION_OPTION,
            $capability,
        ];
        if (isset($options['_search_epoch_generation'], $options['_search_epoch_incarnation'])) {
            $clauses[] = "EXISTS (SELECT 1 FROM {$this->workTable} epoch_control
                WHERE epoch_control.job_key = %s AND epoch_control.kind = 'meta'
                  AND epoch_control.generation = %d AND epoch_control.payload = %s)";
            array_push(
                $args,
                WP_FTS_Index_Queue::SEARCH_EPOCH_JOB_KEY,
                max(0, (int) $options['_search_epoch_generation']),
                (string) $options['_search_epoch_incarnation']
            );
        }

        return ['sql' => implode("\nAND ", $clauses), 'args' => $args];
    }

    /**
     * @param array<string,array{lang:string,kind:int,term:string}> $identities
     * @param array<string,int> $documentCounts
     */
    private function insert_term_identities(array $identities, array $documentCounts = []): void
    {
        if ($identities === []) {
            return;
        }
        if (!$this->is_sqlite_runtime()) {
            if (count($identities) > self::MAX_TERM_RESOLUTION_IDENTITIES) {
                throw new LogicException('The prepared FTS dictionary increment crossed its validated identity bound.');
            }

            // These are already-normalized internal bytes, not caller SQL.
            // Base64 makes the maximum 8,192-row UPSERT data-independent: a
            // quote-heavy valid term cannot make wpdb escaping split one
            // logical dictionary update into a variable number of queries.
            $statement = "INSERT INTO {$this->termsTable} (lang,kind,term,doc_freq)\n"
                . "/* wp_fts:dictionary-increment */\nVALUES ";
            $row = 0;
            foreach ($identities as $storageKey => $identity) {
                $statement .= ($row++ === 0 ? '' : ',')
                    . '(' . $this->binary_sql_literal($identity['lang'])
                    . ',' . (int) $identity['kind']
                    . ',' . $this->binary_sql_literal($identity['term'])
                    . ',' . max(0, (int) ($documentCounts[$storageKey] ?? 0)) . ')';
            }
            $statement .= "\nON DUPLICATE KEY UPDATE doc_freq = doc_freq + VALUES(doc_freq)";
            $bytes = strlen($statement);
            if ($bytes > self::MAX_WRITE_SQL_BYTES) {
                throw new LengthException(
                    "The prepared FTS dictionary increment exceeds the 4 MiB SQL statement contract ({$bytes} bytes)."
                );
            }
            $this->query($statement, 'insert FTS dictionary terms');
            return;
        }

        // SQLite distinguishes TEXT and BLOB comparison operands. The v4
        // dictionary columns have BLOB affinity there, so compile lexical
        // bytes as binary literals. The preflight has already proved this
        // complete relation fits; never turn one logical dictionary update
        // into a data-dependent number of writes.
        $statement = $this->sqlite_term_identity_increment_statement($identities, $documentCounts);
        if ($this->prepared_statement_bytes($statement) > self::MAX_WRITE_SQL_BYTES) {
            throw new LogicException('The accepted SQLite dictionary increment crossed its preflighted statement bound.');
        }
        $this->query($statement, 'insert FTS dictionary terms');
    }

    /** @param array<int,array<string,mixed>> $documents */
    private function upsert_documents(array $documents): void
    {
        if ($documents === []) {
            return;
        }
        $now = time();
        $this->write_bounded_rows(
            array_values($documents),
            fn(array $doc): int => 96
                + $this->prepared_string_argument_bytes($doc['primary_lang'])
                + $this->prepared_string_argument_bytes($doc['content_hash'])
                + (4 * (int) ceil(strlen($doc['snippet_text']) / 3)),
            function (array $chunk) use ($now): mixed {
                $values = [];
                $args = [];
                foreach ($chunk as $doc) {
                    $snippet = $this->text_sql_value($doc['snippet_text']);
                    $values[] = "(%d,%s,%s,{$snippet['sql']},%d)";
                    array_push($args, $doc['post_id'], $doc['primary_lang'], $doc['content_hash']);
                    array_push($args, ...$snippet['args']);
                    $args[] = $now;
                }
                return $this->wpdb->prepare(
                    "INSERT INTO {$this->documentsTable} (post_id,primary_lang,content_hash,snippet_text,indexed_at) VALUES " . implode(',', $values) . "
ON DUPLICATE KEY UPDATE primary_lang=VALUES(primary_lang),content_hash=VALUES(content_hash),snippet_text=VALUES(snippet_text),indexed_at=VALUES(indexed_at)",
                    ...$args
                );
            },
            'upsert FTS documents'
        );
    }

    /**
     * Prepare and execute variable-width rows without relying on max_allowed_packet.
     *
     * The 3 MiB planning target leaves headroom for SQL syntax and database
     * escaping. The prepared statement is then measured and split again before
     * execution, so no mutation can cross the hard 4 MiB contract.
     *
     * @param array<int,mixed> $rows
     * @param callable(mixed):int $estimateRowBytes
     * @param callable(array<int,mixed>):mixed $prepareChunk
     */
    private function write_bounded_rows(
        array $rows,
        callable $estimateRowBytes,
        callable $prepareChunk,
        string $context
    ): void {
        $chunk = [];
        $estimatedBytes = 512;
        foreach ($rows as $row) {
            $rowBytes = max(1, (int) $estimateRowBytes($row));
            if ($chunk !== [] && $estimatedBytes + $rowBytes > self::WRITE_CHUNK_TARGET_BYTES) {
                $this->execute_bounded_write_chunk($chunk, $prepareChunk, $context);
                $chunk = [];
                $estimatedBytes = 512;
            }
            $chunk[] = $row;
            $estimatedBytes += $rowBytes;
        }
        if ($chunk !== []) {
            $this->execute_bounded_write_chunk($chunk, $prepareChunk, $context);
        }
    }

    /**
     * @param array<int,mixed> $rows
     * @param callable(array<int,mixed>):mixed $prepareChunk
     */
    private function execute_bounded_write_chunk(array $rows, callable $prepareChunk, string $context): void
    {
        $statement = $prepareChunk($rows);
        if ($this->prepared_statement_bytes($statement) <= self::MAX_WRITE_SQL_BYTES) {
            $this->query($statement, $context);
            return;
        }
        if (count($rows) === 1) {
            throw new LengthException("{$context} cannot fit one row inside the 4 MiB SQL statement contract.");
        }

        $middle = intdiv(count($rows), 2);
        $this->execute_bounded_write_chunk(array_slice($rows, 0, $middle), $prepareChunk, $context);
        $this->execute_bounded_write_chunk(array_slice($rows, $middle), $prepareChunk, $context);
    }

    /** Measure both rendered SQL and deferred driver arguments before execution. */
    private function prepared_statement_bytes(mixed $statement): int
    {
        if (is_string($statement)) {
            if (is_callable([$this->wpdb, 'remove_placeholder_escape'])) {
                $unescaped = $this->wpdb->remove_placeholder_escape($statement);
                if (is_string($unescaped)) {
                    $statement = $unescaped;
                }
            }
            return strlen($statement);
        }
        if (is_object($statement) && isset($statement->sql) && is_string($statement->sql)) {
            $bytes = strlen($statement->sql);
            if (isset($statement->args) && is_array($statement->args)) {
                foreach ($statement->args as $argument) {
                    $bytes += is_string($argument)
                        ? $this->prepared_string_argument_bytes($argument)
                        : strlen((string) $argument) + 4;
                }
            }
            return $bytes;
        }

        throw new RuntimeException('The WordPress database driver did not return a measurable SQL statement.');
    }

    /** Conservatively include SQL escaping growth for one string argument. */
    private function prepared_string_argument_bytes(string $value): int
    {
        $bytes = strlen($value) + 2;
        foreach (["\0", "\n", "\r", "\\", "'", '"', "\x1a"] as $escapedByte) {
            $bytes += substr_count($value, $escapedByte);
        }
        return $bytes;
    }

    /** @return array<string,int> */
    private function normalize_term_frequencies(array $frequencies): array
    {
        return $this->normalize_frequency_map($frequencies, self::LEXICAL_KIND);
    }

    /** @return array<string,int> */
    private function normalize_surface_frequencies(array $frequencies): array
    {
        return $this->normalize_frequency_map($frequencies, self::SURFACE_KIND);
    }

    /**
     * Normalize and validate lexical or surface frequencies before row writes.
     *
     * @return array<string,int>
     */
    private function normalize_frequency_map(array $frequencies, int $kind): array
    {
        $normalized = [];
        foreach ($frequencies as $key => $tf) {
            $identity = $this->term_identity((string) $key, $kind);
            $tf = (int) $tf;
            if ($tf > 0) {
                $normalized[$identity['key']] = max($normalized[$identity['key']] ?? 0, $tf);
            }
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    /** Quantize weighted term frequency into the posting's self-contained score. */
    private function impact(int $weightedTf): int
    {
        $tf = max(1, $weightedTf);
        // Quantized BM25-style TF saturation without document-length coupling.
        return max(1, min(self::MAX_POSTING_IMPACT, (int) round(4096.0 * ((2.2 * $tf) / (1.2 + $tf)))));
    }

    /** Convert document frequency to the bounded integer query multiplier. */
    private static function rarity_weight(int $docFreq): int
    {
        return max(1, intdiv(self::RARITY_SCALE, max(1, $docFreq)));
    }

    /** @return string[] */
    private function normalize_filter_values(mixed $values): array
    {
        $values = is_array($values) ? $values : [$values];
        if (count($values) > self::MAX_FILTER_VALUES) {
            throw new InvalidArgumentException('FTS filters accept at most 32 values.');
        }
        $normalized = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                throw new InvalidArgumentException('Each FTS filter value must be scalar.');
            }
            $value = (string) $value;
            if (strlen($value) > self::MAX_FILTER_VALUE_BYTES) {
                throw new InvalidArgumentException('Each FTS filter value may contain at most 64 bytes.');
            }
            $value = trim($value);
            if ($value === '') {
                throw new InvalidArgumentException('FTS filter values must not be blank.');
            }
            $normalized[$value] = true;
        }
        $normalized = array_keys($normalized);
        sort($normalized, SORT_STRING);
        return $normalized;
    }

    /** @return int[] */
    private function normalize_doc_ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    }

    /** @return int[] */
    private function normalize_bounded_doc_ids(array $ids, int $limit, string $context): array
    {
        if (count($ids) > $limit) {
            throw new InvalidArgumentException("{$context} accept at most {$limit} raw document ids.");
        }
        foreach ($ids as $id) {
            if (is_string($id) && strlen($id) > self::MAX_NUMERIC_INPUT_BYTES) {
                throw new InvalidArgumentException("{$context} require bounded document-id scalars.");
            }
        }
        $ids = $this->normalize_doc_ids($ids);
        if (count($ids) > $limit) {
            throw new InvalidArgumentException("{$context} accept at most {$limit} document ids.");
        }

        return $ids;
    }

    /** @return array{sql:string,args:string[]} */
    private function binary_sql_value(string $value): array
    {
        if ($this->is_sqlite_runtime()) {
            return ['sql' => "X'" . bin2hex($value) . "'", 'args' => []];
        }
        // wpdb treats prepared strings as connection text and can reject raw
        // binary hashes before the database sees them. Bind ASCII hex instead.
        return ['sql' => 'UNHEX(%s)', 'args' => [bin2hex($value)]];
    }

    /** Render only internally encoded bytes, never caller SQL text. */
    private function binary_sql_literal(string $value): string
    {
        if ($this->is_sqlite_runtime()) {
            return "X'" . bin2hex($value) . "'";
        }

        return "FROM_BASE64('" . base64_encode($value) . "')";
    }

    /** @return array{sql:string,args:string[]} */
    private function text_sql_value(string $value): array
    {
        if ($this->is_sqlite_runtime()) {
            return ['sql' => "CAST(X'" . bin2hex($value) . "' AS TEXT)", 'args' => []];
        }
        // Base64 prevents wpdb from expanding a percent-heavy snippet into
        // megabytes of temporary placeholder sentinels before query execution.
        return ['sql' => 'FROM_BASE64(%s)', 'args' => [base64_encode($value)]];
    }

    /**
     * Bind cursors to every input that changes membership, scoring, or order.
     * A valid cursor from another query must fail rather than silently skip
     * unrelated rows.
     */
    private function search_cursor_fingerprint(
        array $plan,
        ?array $prefix,
        string $mode,
        array $options,
        int $searchEpoch = 0,
        string $searchEpochIncarnation = ''
    ): string
    {
        $groups = [];
        foreach ($plan['groups'] as $group) {
            $normalized = [];
            foreach ($group as $candidate) {
                $normalized[] = [
                    'key' => (string) ($candidate['key'] ?? ''),
                    'rank' => max(0, (int) ($candidate['rank'] ?? 0)),
                ];
            }
            usort($normalized, static fn(array $a, array $b): int => strcmp($a['key'], $b['key']) ?: ($a['rank'] <=> $b['rank']));
            $groups[] = $normalized;
        }

        $statuses = $this->normalize_filter_values($options['post_statuses'] ?? []);
        if ($statuses === []) {
            $statuses = ['publish'];
        }
        $recencyStrength = is_numeric($options['recency_boost_strength'] ?? null)
            ? max(0.0, min(self::MAX_RECENCY_BOOST_STRENGTH, (float) $options['recency_boost_strength']))
            : 0.0;
        $input = [
            'v' => 6,
            // WordPress salts are network-wide. Bind a cursor to this blog's
            // physical index so an otherwise identical multisite query cannot
            // replay a valid boundary against another site's result order.
            'index_namespace' => $this->termsTable,
            'search_epoch' => max(0, $searchEpoch),
            'search_epoch_incarnation' => $searchEpochIncarnation,
            'search_ready_incarnation' => (string) ($options['search_ready_incarnation'] ?? ''),
            'search_ready_profile_hash' => (string) ($options['search_ready_profile_hash'] ?? ''),
            'groups' => $groups,
            // Prefix identities are binary dictionary bytes. Encode them
            // explicitly so cursor creation cannot fail on valid Unicode and
            // otherwise identical searches cannot replay another prefix's
            // ranking boundary.
            'prefix' => $prefix === null ? null : [
                'group_id' => (int) ($prefix['group_id'] ?? 0),
                'lang_hex' => bin2hex((string) ($prefix['lang'] ?? '')),
                'term_hex' => bin2hex((string) ($prefix['term'] ?? '')),
            ],
            'mode' => $mode,
            'post_types' => $this->normalize_filter_values($options['post_types'] ?? []),
            'post_statuses' => $statuses,
            'date_after' => is_scalar($options['date_after'] ?? null) ? (string) $options['date_after'] : null,
            'date_before' => is_scalar($options['date_before'] ?? null) ? (string) $options['date_before'] : null,
            'recency_strength' => $recencyStrength,
        ];
        if ($recencyStrength > 0.0) {
            $input['recency_half_life'] = is_numeric($options['recency_boost_half_life_days'] ?? null)
                ? max(1.0, min(3650.0, (float) $options['recency_boost_half_life_days']))
                : 30.0;
        }

        return hash('sha256', json_encode($input, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return array{score:string,date:string,post_id:int,now_gmt:string}|null */
    private function decode_cursor(?string $cursor, string $fingerprint): ?array
    {
        if ($cursor === null || trim($cursor) === '') {
            return null;
        }
        $data = $this->decode_cursor_payload($cursor);
        if (!is_string($data['q']) || !hash_equals($fingerprint, $data['q'])) {
            throw new InvalidArgumentException('Search cursor does not belong to this query.');
        }
        $nowGmt = isset($data['n']) && is_string($data['n'])
            ? $this->normalize_scoring_now($data['n'])
            : '';
        // V6 cursors encoded small scores as JSON integers. Continue to accept
        // those while requiring all new and large boundaries to be exact text.
        if (!is_string($data['s']) && !is_int($data['s'])) {
            throw new InvalidArgumentException('Invalid FTS cursor score.');
        }
        return [
            'score' => $this->normalize_cursor_score($data['s']),
            'date' => (string) $data['d'],
            'post_id' => (int) $data['i'],
            'now_gmt' => $nowGmt,
        ];
    }

    /** Reject malformed or oversized cursors before base64 and JSON decoding. */
    private function assert_cursor_input_bounds(mixed $cursor): void
    {
        if (
            !is_string($cursor)
            || $cursor === ''
            || strlen($cursor) > self::MAX_CURSOR_BYTES
            || trim($cursor) === ''
        ) {
            throw new InvalidArgumentException('FTS cursors must be nonempty strings of at most 2,048 bytes.');
        }
    }

    /** @return array<string,mixed> */
    private function decode_cursor_payload(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!is_string($decoded) || !str_contains($decoded, '.')) {
            throw new InvalidArgumentException('Invalid FTS cursor.');
        }
        [$json, $signature] = explode('.', $decoded, 2);
        if (!hash_equals(hash_hmac('sha256', $json, $this->cursor_secret()), $signature)) {
            throw new InvalidArgumentException('Invalid FTS cursor signature.');
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['s'], $data['d'], $data['i'], $data['q'])) {
            throw new InvalidArgumentException('Invalid FTS cursor payload.');
        }

        return $data;
    }

    /** Sign the exact final ordering tuple for the next search-after page. */
    private function encode_cursor(object $row, string $fingerprint, string $scoringNow): string
    {
        $data = [
            's' => $this->normalize_cursor_score($row->score ?? null),
            'd' => (string) ($row->post_date_gmt ?? ''),
            'i' => (int) $row->doc_id,
            'q' => $fingerprint,
        ];
        if ($scoringNow !== '') {
            $data['n'] = $this->normalize_scoring_now($scoringNow);
        }
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $payload = $json . '.' . hash_hmac('sha256', $json, $this->cursor_secret());
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /** Preserve the integral SQL score as exact decimal text across drivers. */
    private function normalize_cursor_score(mixed $score): string
    {
        if (is_int($score)) {
            $score = (string) $score;
        } elseif (is_float($score)) {
            // SQLite and some wpdb drivers expose an integral SQL score as a
            // float. Every permitted score is below 2^53, so this conversion
            // is exact after rejecting fractional or non-finite values.
            if (!is_finite($score) || $score < 0.0 || floor($score) !== $score) {
                throw new InvalidArgumentException('Invalid FTS cursor score.');
            }
            $score = sprintf('%.0F', $score);
        }
        if (!is_string($score) || preg_match('/^(0|[1-9][0-9]*)$/D', $score) !== 1) {
            throw new InvalidArgumentException('Invalid FTS cursor score.');
        }
        $maximumLength = strlen(self::MAX_CURSOR_SCORE);
        if (
            strlen($score) > $maximumLength
            || (strlen($score) === $maximumLength && strcmp($score, self::MAX_CURSOR_SCORE) > 0)
        ) {
            throw new InvalidArgumentException('Invalid FTS cursor score.');
        }

        return $score;
    }

    /** Derive the site-bound key that prevents client-forged ordering tuples. */
    private function cursor_secret(): string
    {
        if (function_exists('wp_salt')) {
            return (string) wp_salt('auth');
        }
        if (defined('AUTH_SALT') && (string) constant('AUTH_SALT') !== '') {
            return (string) constant('AUTH_SALT');
        }
        return hash('sha256', __FILE__ . '|' . $this->termsTable);
    }

    /** @return array<string,mixed> */
    private function empty_search_page(string $lang): array
    {
        return [
            'results' => [],
            'has_more' => false,
            'next_cursor' => null,
            'previous_cursor' => null,
            'query_lang' => WP_FTS_TermNamespace::canonicalize_lang($lang, 'und'),
        ];
    }

    /** Report a structural plan overflow without falling back to legacy search. */
    private function throw_search_plan_limit(string $name): never
    {
        if (class_exists('WP_FTS_Search_Budget_Exceeded')) {
            throw new WP_FTS_Search_Budget_Exceeded($name);
        }
        throw new InvalidArgumentException("FTS search exceeded its {$name} limit.");
    }

    /** Detect WordPress' SQLite integration without issuing SQLite-only SQL. */
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
     * Run WordPress `dbDelta()` when available; otherwise request raw CREATEs.
     *
     * @param string[] $sql CREATE TABLE statements.
     * @return bool True when dbDelta was loaded and invoked.
     */
    private function run_db_delta(array $sql): bool
    {
        if (!function_exists('dbDelta') && defined('ABSPATH')) {
            $upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
            if (is_file($upgrade)) {
                require_once $upgrade;
            }
        }
        if (!function_exists('dbDelta')) {
            return false;
        }
        dbDelta($sql);
        $this->assert_no_database_error('create or update FTS tables');
        return true;
    }

    /** Refuse a write when the plugin writer no longer owns its lease. */
    private function guard_mutation(): void
    {
        if ($this->mutationGuard !== null) {
            ($this->mutationGuard)();
        }
    }

    /** Execute one bounded write with explicit database-error visibility. */
    private function query(mixed $statement, string $context): int
    {
        $bytes = $this->prepared_statement_bytes($statement);
        if ($bytes > self::MAX_WRITE_SQL_BYTES) {
            throw new LengthException("{$context} exceeds the 4 MiB SQL statement contract ({$bytes} bytes).");
        }
        $result = $this->wpdb->query($statement);
        if ($result === false) {
            throw $this->database_exception($context);
        }
        $this->assert_no_database_error($context);
        return is_int($result) ? max(0, $result) : 0;
    }

    /** @return object[] */
    private function get_results(mixed $statement, string $context): array
    {
        $rows = $this->wpdb->get_results($statement);
        $this->assert_no_database_error($context);
        if (!is_array($rows)) {
            return [];
        }
        foreach ($rows as $row) {
            if (!is_object($row)) {
                return array_values(array_filter($rows, 'is_object'));
            }
        }

        // wpdb's normal OBJECT result is already a list of objects. Returning
        // it directly avoids copying the complete maximum-width resolver page.
        return array_is_list($rows) ? $rows : array_values($rows);
    }

    /** Runs a single-row query with explicit database error visibility. */
    private function get_row(mixed $statement, string $context): ?object
    {
        $row = $this->wpdb->get_row($statement);
        $this->assert_no_database_error($context);
        return is_object($row) ? $row : null;
    }

    /** @return array<int,mixed> */
    private function get_col(mixed $statement, string $context): array
    {
        $values = $this->wpdb->get_col($statement);
        $this->assert_no_database_error($context);
        return is_array($values) ? $values : [];
    }

    /** @return object[]|array<int,array<string,mixed>> */
    private function schema_rows(mixed $sql): array
    {
        if (!method_exists($this->wpdb, 'get_results')) {
            return [];
        }
        $rows = $this->wpdb->get_results($sql);
        return is_array($rows)
            ? array_values(array_filter($rows, static fn(mixed $row): bool => is_object($row) || is_array($row)))
            : [];
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

    /** Throws when `$wpdb->last_error` contains a failed operation detail. */
    private function assert_no_database_error(string $context): void
    {
        if (isset($this->wpdb->last_error) && trim((string) $this->wpdb->last_error) !== '') {
            throw $this->database_exception($context);
        }
    }

    /** Preserve the database action and adapter error in one actionable exception. */
    private function database_exception(string $context): RuntimeException
    {
        $error = isset($this->wpdb->last_error) ? trim((string) $this->wpdb->last_error) : '';
        return new RuntimeException('Failed to ' . $context . ($error !== '' ? ': ' . $error : '.'));
    }
}
