<?php
declare(strict_types=1);

namespace WP_CLI\Utils {
    if (!function_exists(__NAMESPACE__ . '\format_items')) {
        /**
         * @param array<int,array<string,mixed>> $items
         * @param string[] $fields
         */
        function format_items(string $format, array $items, array $fields): void
        {
            $GLOBALS['wp_fts_quality_cli_format_items'][] = [
                'format' => $format,
                'items' => $items,
                'fields' => $fields,
            ];
        }
    }
}

namespace {
    if (!function_exists('add_action')) {
        function add_action(string $hook_name, mixed $callback, int $priority = 10, int $accepted_args = 1): bool
        {
            $GLOBALS['wp_fts_quality_add_action_calls'][] = [
                'hook' => $hook_name,
                'callback' => $callback,
                'priority' => $priority,
                'accepted_args' => $accepted_args,
            ];

            return true;
        }
    }

    /**
     * @return array<int,array{sql:string,args:array<int,mixed>}>
     */
    function wp_fts_quality_prepared_like(WP_FTS_Test_WPDB $wpdb, string $prefix): array
    {
        return array_values(array_filter(
            $wpdb->prepared,
            static fn(array $entry): bool => str_starts_with($entry['sql'], $prefix)
        ));
    }

    /**
     * @return array{sql:string,args:array<int,mixed>}
     */
    function wp_fts_quality_last_prepared_like(WP_FTS_Test_WPDB $wpdb, string $prefix): array
    {
        $entries = wp_fts_quality_prepared_like($wpdb, $prefix);
        assert_true($entries !== [], "expected prepared SQL beginning with {$prefix}");

        return $entries[count($entries) - 1];
    }

    function wp_fts_quality_reset_cli(): void
    {
        if (class_exists('WP_CLI')) {
            WP_CLI::$successMessages = [];
            WP_CLI::$warningMessages = [];
            WP_CLI::$commands = [];
        }

        $GLOBALS['wp_fts_quality_cli_format_items'] = [];
    }

    /** Establish the schema-ready precondition shared by isolated reindex cases. */
    function wp_fts_quality_prepare_reindex(): void
    {
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
        WP_FTS_Plugin::reset_request_caches();
    }

    /** @return array<int,array<string,mixed>> */
    function wp_fts_quality_reindex_scope_rows(WP_FTS_Test_WPDB $wpdb): array
    {
        return array_values(array_filter(
            $wpdb->queue,
            static fn(array $row): bool => (string) ($row['kind'] ?? '') === 'scope'
        ));
    }

    /** @return array<string,mixed> */
    function wp_fts_quality_reindex_scope_payload(WP_FTS_Test_WPDB $wpdb): array
    {
        $rows = wp_fts_quality_reindex_scope_rows($wpdb);
        assert_same(1, count($rows), 'reindex should coalesce to exactly one durable scope row');
        $decoded = json_decode((string) ($rows[0]['payload'] ?? ''), true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /** Swap the global database adapter for one case and restore it even on failure. */
    function wp_fts_quality_with_wpdb(object $wpdb, callable $callback): mixed
    {
        $hadWpdb = array_key_exists('wpdb', $GLOBALS);
        $oldWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $wpdb;

        try {
            return $callback($wpdb);
        } finally {
            if ($hadWpdb) {
                $GLOBALS['wpdb'] = $oldWpdb;
            } else {
                unset($GLOBALS['wpdb']);
            }
        }
    }

    /**
     * @return array{lang:string,doc_len:int,content_hash:?string,is_deleted:int}
     */
    function wp_fts_quality_doc_row(string $lang, int $length, ?string $hash = null, int $deleted = 0): array
    {
        return [
            'lang' => $lang,
            'doc_len' => $length,
            'content_hash' => $hash ?? sha1($lang . ':' . $length),
            'is_deleted' => $deleted,
        ];
    }

    /** Mirror the production bounded term-frequency impact in fixture expectations. */
    function wp_fts_quality_impact(int $weightedTf): int
    {
        $tf = max(1, $weightedTf);
        return max(1, min(65535, (int) round(4096.0 * ((2.2 * $tf) / (1.2 + $tf)))));
    }

    /**
     * Read one fake-MySQL term directly from the test adapter's relational
     * state. Relational storage deliberately has no posting-list read API.
     *
     * @return array{df:int,postings:array<int,int>}|null
     */
    function wp_fts_quality_fake_mysql_term_state(WP_FTS_Test_WPDB $wpdb, string $termKey, int $rowCap = 1000): ?array
    {
        if (!isset($wpdb->ftsTerms[$termKey])) {
            return null;
        }

        $postings = array_map('intval', $wpdb->postings[$termKey] ?? []);
        if (count($postings) > $rowCap) {
            throw new WP_FTS_TestFailure("Test-only term inspection exceeded its {$rowCap}-row bound.");
        }
        ksort($postings, SORT_NUMERIC);

        return [
            'df' => (int) ($wpdb->ftsTerms[$termKey]['doc_freq'] ?? 0),
            'postings' => $postings,
        ];
    }

    /** @return string[] */
    function wp_fts_quality_fake_mysql_term_keys(WP_FTS_Test_WPDB $wpdb, int $rowCap = 2000): array
    {
        $terms = array_map('strval', array_keys($wpdb->ftsTerms));
        if (count($terms) > $rowCap) {
            throw new WP_FTS_TestFailure("Test-only dictionary inspection exceeded its {$rowCap}-row bound.");
        }
        sort($terms, SORT_STRING);

        return $terms;
    }

    /** @return int[] */
    function wp_fts_quality_fake_mysql_document_ids(WP_FTS_Test_WPDB $wpdb, int $rowCap = 2000): array
    {
        $ids = array_map('intval', array_keys($wpdb->docs));
        if (count($ids) > $rowCap) {
            throw new WP_FTS_TestFailure("Test-only document inspection exceeded its {$rowCap}-row bound.");
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * Inspect one SQLite fixture term with an exact identity predicate and a
     * hard row limit, without reopening the production posting-list API.
     *
     * @return array{df:int,postings:array<int,int>}|null
     */
    function wp_fts_quality_sqlite_term_state(WP_FTS_Relational_Regression_SQLite_WPDB $wpdb, string $termKey, int $rowCap = 1000): ?array
    {
        $identity = WP_FTS_TermNamespace::split_term($termKey);
        $statement = $wpdb->dbh->prepare(
            'SELECT t.doc_freq,p.post_id,p.impact '
            . 'FROM wp_fts_terms t '
            . 'LEFT JOIN wp_fts_postings p ON p.term_id=t.term_id '
            . 'WHERE t.lang=? AND t.kind=0 AND t.term=? '
            . 'ORDER BY p.post_id LIMIT ?'
        );
        $statement->bindValue(1, (string) $identity['lang'], PDO::PARAM_LOB);
        $statement->bindValue(2, (string) $identity['term'], PDO::PARAM_LOB);
        $statement->bindValue(3, $rowCap + 1, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }
        if (count($rows) > $rowCap) {
            throw new WP_FTS_TestFailure("Test-only SQLite term inspection exceeded its {$rowCap}-row bound.");
        }

        $postings = [];
        foreach ($rows as $row) {
            if ($row['post_id'] !== null) {
                $postings[(int) $row['post_id']] = (int) $row['impact'];
            }
        }

        return ['df' => (int) $rows[0]['doc_freq'], 'postings' => $postings];
    }

    /** @return string[] */
    function wp_fts_quality_sqlite_term_keys(WP_FTS_Relational_Regression_SQLite_WPDB $wpdb, int $rowCap = 2000): array
    {
        $statement = $wpdb->dbh->prepare('SELECT lang,term FROM wp_fts_terms ORDER BY lang,term LIMIT ?');
        $statement->bindValue(1, $rowCap + 1, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > $rowCap) {
            throw new WP_FTS_TestFailure("Test-only SQLite dictionary inspection exceeded its {$rowCap}-row bound.");
        }

        return array_map(
            static fn(array $row): string => WP_FTS_TermNamespace::namespace_term((string) $row['lang'], (string) $row['term']),
            $rows
        );
    }

    test_case('quality relational MySQL-dialect schema declares compact contracts', function (): void {
        $wpdb = new WP_FTS_Test_WPDB();
        $storage = new WP_FTS_Relational_Storage($wpdb);
        $storage->create_tables();

        $schemaSql = implode("\n\n", $wpdb->queries);
        $schemaNeedles = [
            'CREATE TABLE wp_fts_terms',
            'term_id bigint unsigned NOT NULL AUTO_INCREMENT',
            'lang varbinary(32) NOT NULL',
            'kind tinyint unsigned NOT NULL DEFAULT 0',
            'term varbinary(255) NOT NULL',
            'doc_freq int unsigned NOT NULL DEFAULT 0',
            'PRIMARY KEY  (term_id)',
            'UNIQUE KEY term_identity (lang,kind,term)',
            'ROW_FORMAT=DYNAMIC DEFAULT CHARSET=binary',
            'CREATE TABLE wp_fts_postings',
            'term_id bigint unsigned NOT NULL',
            'post_id bigint unsigned NOT NULL',
            'impact smallint unsigned NOT NULL',
            'PRIMARY KEY  (term_id,post_id)',
            'KEY post_term_impact (post_id,term_id,impact)',
            'CREATE TABLE wp_fts_documents',
            "primary_lang varbinary(32) NOT NULL DEFAULT 'und'",
            'content_hash varbinary(40) NOT NULL',
            'snippet_text mediumtext NOT NULL',
            'indexed_at bigint unsigned NOT NULL DEFAULT 0',
            'PRIMARY KEY  (post_id)',
            'CREATE TABLE wp_fts_work',
            'job_key varbinary(191) NOT NULL',
            "kind varchar(16) NOT NULL",
            'generation bigint unsigned NOT NULL DEFAULT 1',
            "state varchar(12) NOT NULL DEFAULT 'pending'",
            "claim_token varchar(64) NOT NULL DEFAULT ''",
            "scope_subject_type varchar(24) NOT NULL DEFAULT ''",
            'scope_subject_id bigint unsigned NOT NULL DEFAULT 0',
            'payload longtext NULL',
            'PRIMARY KEY  (job_key)',
            'KEY ready (kind,state,available_at,post_id,job_key)',
            'KEY recoverable (kind,state,claim_expires_at,available_at,post_id,job_key)',
            'KEY scope_subject (kind,scope_coverage,scope_subject_type,scope_subject_id)',
            'KEY dirty (post_id,kind)',
        ];

        assert_same(4, count(array_filter($wpdb->queries, static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE'))), 'schema should create exactly four compact relational FTS tables');
        foreach ($schemaNeedles as $needle) {
            assert_contains($needle, $schemaSql, "schema should contain {$needle}");
        }

        foreach (['postings longblob', 'FULLTEXT', 'ENGINE=MyISAM', 'utf8mb4_general_ci'] as $forbidden) {
            assert_true(!str_contains(strtoupper($schemaSql), strtoupper($forbidden)), "schema should not contain {$forbidden}");
        }

        $customWpdb = new WP_FTS_Test_WPDB();
        $custom = new WP_FTS_Relational_Storage($customWpdb, 'custom_');
        $custom->create_tables();
        $customSql = implode("\n", $customWpdb->queries);
        foreach (['custom_fts_terms', 'custom_fts_postings', 'custom_fts_documents', 'custom_fts_work'] as $table) {
            assert_contains($table, $customSql, "custom prefix schema should create {$table}");
        }
    });

    test_case('quality relational MySQL-dialect writer preserves binary namespaces without a hash identity', function (): void {
        $wpdb = new WP_FTS_Test_WPDB();
        $wpdb->recordReadQueries = true;
        $storage = new WP_FTS_Relational_Storage($wpdb);
        $fixtures = [
            ['en', 'shared', 11, 2],
            ['pl', 'shared', 12, 3],
            ['pt-BR', 'organizar', 13, 4],
            ['sr-Cyrl-RS', 'grad', 14, 1],
            ['zh-Hant', 'search', 15, 5],
            ['es-419', 'color', 16, 2],
            ['de-DE', 'strasse', 17, 7],
            ['tr-TR', 'istanbul', 18, 2],
        ];
        $documents = [];
        foreach ($fixtures as [$language, $term, $postId, $frequency]) {
            $documents[] = [
                'doc_id' => $postId,
                'primary_lang' => $language,
                'content_hash' => sha1($language . ':' . $postId),
                'snippet_text' => '',
                'term_frequencies' => [WP_FTS_TermNamespace::namespace_term($language, $term) => $frequency],
                'surface_frequencies' => [],
            ];
        }

        $result = $storage->replace_prepared_documents($documents);
        assert_same(count($fixtures), $result['postings'] ?? null, 'the bounded batch should persist one lexical posting for each document');
        assert_same(count($fixtures), $result['terms'] ?? null, 'language-partitioned identities should remain distinct without a parallel hash column');

        $termUpserts = array_values(array_filter(
            $wpdb->queries,
            static fn(mixed $query): bool => is_string($query) && str_starts_with($query, 'INSERT INTO wp_fts_terms')
        ));
        assert_same(1, count($termUpserts), 'the bounded identities should share one dictionary VALUES write');
        assert_contains('(lang,kind,term,doc_freq)', $termUpserts[0], 'dictionary rows should use the unique composite identity directly');
        assert_contains('doc_freq = doc_freq + VALUES(doc_freq)', $termUpserts[0], 'dictionary writes should merge only bounded document-frequency deltas');

        foreach ($fixtures as [$language, $term, $postId, $frequency]) {
            $key = WP_FTS_TermNamespace::namespace_term($language, $term);
            $row = wp_fts_quality_fake_mysql_term_state($wpdb, $key);
            assert_true($row !== null, "{$language} term should remain addressable by its complete binary identity");
            assert_same(1, $row['df'], "{$language} dictionary frequency should count its one document");
            assert_same([$postId => wp_fts_quality_impact($frequency)], $row['postings'], "{$language} posting should retain its quantized impact");
        }

        $plKey = WP_FTS_TermNamespace::namespace_term('pl', 'shared');
        $enKey = WP_FTS_TermNamespace::namespace_term('en', 'shared');
        assert_true($plKey !== $enKey, 'same token bytes in different languages must remain separate identities');
        assert_same(
            [$enKey, $plKey],
            array_values(array_intersect(wp_fts_quality_fake_mysql_term_keys($wpdb), [$enKey, $plKey])),
            'the composite dictionary identity should retain both language partitions'
        );

        $maxKey = WP_FTS_TermNamespace::namespace_term('en', str_repeat('x', 255));
        $storage->replace_prepared_documents([[
            'doc_id' => 99,
            'primary_lang' => 'en',
            'content_hash' => sha1('99'),
            'snippet_text' => '',
            'term_frequencies' => [$maxKey => 1],
            'surface_frequencies' => [],
        ]]);
        assert_true(wp_fts_quality_fake_mysql_term_state($wpdb, $maxKey) !== null, 'a full 255-byte lexical key should fit the composite dictionary index');

        $queriesBefore = count($wpdb->queries);
        $rejection = null;
        try {
            $storage->replace_prepared_documents([[
                'doc_id' => 100,
                'primary_lang' => 'en',
                'content_hash' => sha1('100'),
                'snippet_text' => '',
                'term_frequencies' => [WP_FTS_TermNamespace::namespace_term('en', str_repeat('x', 256)) => 1],
                'surface_frequencies' => [],
            ]]);
        } catch (WP_FTS_Prepared_Document_Rejected $error) {
            $rejection = $error;
        }
        assert_true($rejection instanceof WP_FTS_Prepared_Document_Rejected, 'an over-width lexical key should be a typed poison-document rejection');
        assert_same('invalid_term_identity', $rejection?->reason_code, 'the over-width identity should expose the stable writer reason');
        assert_same($queriesBefore, count($wpdb->queries), 'an over-width identity must reject before SQL');
    });

    test_case('quality relational MySQL-dialect replacement retains shared rows and exact document frequencies', function (): void {
        $wpdb = new WP_FTS_Test_WPDB();
        $storage = new WP_FTS_Relational_Storage($wpdb);
        $shared = WP_FTS_TermNamespace::namespace_term('en', 'shared');
        $unique = WP_FTS_TermNamespace::namespace_term('en', 'unique');

        $storage->replace_prepared_documents([
            [
                'doc_id' => 1001,
                'primary_lang' => 'en',
                'content_hash' => sha1('1001-shared'),
                'snippet_text' => '',
                'term_frequencies' => [$shared => 2],
                'surface_frequencies' => [],
            ],
            [
                'doc_id' => 1002,
                'primary_lang' => 'en',
                'content_hash' => sha1('1002-shared'),
                'snippet_text' => '',
                'term_frequencies' => [$shared => 3],
                'surface_frequencies' => [],
            ],
        ]);
        assert_same([
            1001 => wp_fts_quality_impact(2),
            1002 => wp_fts_quality_impact(3),
        ], wp_fts_quality_fake_mysql_term_state($wpdb, $shared)['postings'] ?? null, 'one dictionary row should retain both document postings');
        assert_same(2, wp_fts_quality_fake_mysql_term_state($wpdb, $shared)['df'] ?? null, 'shared document frequency should count distinct documents');

        $storage->replace_prepared_documents([[
            'doc_id' => 1001,
            'primary_lang' => 'en',
            'content_hash' => sha1('1001-unique'),
            'snippet_text' => '',
            'term_frequencies' => [$unique => 4],
            'surface_frequencies' => [],
        ]]);
        assert_same([1002 => wp_fts_quality_impact(3)], wp_fts_quality_fake_mysql_term_state($wpdb, $shared)['postings'] ?? null, 'replacing one document must retain the other shared posting');
        assert_same(1, wp_fts_quality_fake_mysql_term_state($wpdb, $shared)['df'] ?? null, 'shared frequency must decrement exactly once');
        assert_same([1001 => wp_fts_quality_impact(4)], wp_fts_quality_fake_mysql_term_state($wpdb, $unique)['postings'] ?? null, 'the replacement identity should receive the moved document');

        $allSql = implode("\n", array_map(
            static fn(mixed $query): string => is_array($query) ? (string) ($query[0] ?? '') : (string) $query,
            $wpdb->queries
        ));
        assert_true(!str_contains($allSql, 'postings longblob'), 'posting rows must never collapse back into a PHP blob write');
    });

    test_case_with_pdo_sqlite_fixture('quality relational SQLite fixture preserves typed surface rows', function (): void {
        $wpdb = new WP_FTS_Relational_Regression_SQLite_WPDB();
        wp_fts_relational_regression_create_schema($wpdb);
        $storage = new WP_FTS_Relational_Storage($wpdb);
        $lexical = WP_FTS_TermNamespace::namespace_term('pl', 'wroclaw');
        $surface = WP_FTS_TermNamespace::namespace_term('pl', 'wrocław');
        $storage->replace_prepared_documents([[
            'doc_id' => 401,
            'primary_lang' => 'pl',
            'content_hash' => sha1('401'),
            'snippet_text' => '',
            'term_frequencies' => [$lexical => 2],
            'surface_frequencies' => [$surface => 2],
        ]]);

        assert_same(
            ['df' => 1, 'postings' => [401 => wp_fts_quality_impact(2)]],
            wp_fts_quality_sqlite_term_state($wpdb, $lexical),
            'bounded fixture inspection should read the exact lexical identity'
        );
        assert_same(2, (int) $wpdb->dbh->query('SELECT COUNT(*) FROM wp_fts_terms')->fetchColumn(), 'one token should persist exactly one lexical and one normalized-surface dictionary row');
        assert_same([0, 1], array_map('intval', $wpdb->dbh->query('SELECT kind FROM wp_fts_terms ORDER BY kind')->fetchAll(PDO::FETCH_COLUMN)), 'typed identities must remain distinct without materializing every proper prefix');

        $queriesBefore = count($wpdb->queries);
        foreach (['get_doc', 'get_doc_metadata', 'terms_for_doc', 'get_terms', 'get_postings', 'all_terms', 'all_doc_ids'] as $method) {
            assert_true(!method_exists($storage, $method), "relational storage should not expose {$method}");
        }
        assert_same($queriesBefore, count($wpdb->queries), 'capability inspection must not execute SQL');
    });

    test_case('quality relational documents retain bounded identity without component statistics', function (): void {
        $wpdb = new WP_FTS_Test_WPDB();
        $storage = new WP_FTS_Relational_Storage($wpdb);

        $storage->replace_prepared_documents([[
            'doc_id' => 101,
            'primary_lang' => 'und',
            'content_hash' => sha1('unspecified'),
            'snippet_text' => '',
            'term_frequencies' => [],
            'surface_frequencies' => [],
        ]]);
        assert_same('und', $wpdb->docs[101]['primary_lang'] ?? null, 'the bounded writer should preserve the unspecified partition');
        assert_same(sha1('unspecified'), $wpdb->docs[101]['content_hash'] ?? null, 'the bounded writer should persist the exact source fingerprint');

        $docs = [
            [201, 'pl-PL', sha1('pl'), 'pl-PL'],
            [202, 'pt-BR', sha1('pt'), 'pt-BR'],
            [203, 'sr-Cyrl-RS', sha1('sr'), 'sr-Cyrl-RS'],
        ];

        foreach ($docs as [$docId, $primary, $hash, $expectedPrimary]) {
            $storage->replace_prepared_documents([[
                'doc_id' => $docId,
                'primary_lang' => $primary,
                'content_hash' => $hash,
                'snippet_text' => '',
                'term_frequencies' => [],
                'surface_frequencies' => [],
            ]]);
            assert_same($expectedPrimary, $wpdb->docs[$docId]['primary_lang'] ?? null, "{$docId} primary language should retain its canonical value");
            assert_same($hash, $wpdb->docs[$docId]['content_hash'] ?? null, "{$docId} content hash should round trip");
        }

        $putSql = wp_fts_quality_last_prepared_like($wpdb, 'INSERT INTO wp_fts_documents');
        assert_contains('ON DUPLICATE KEY UPDATE primary_lang=VALUES(primary_lang),content_hash=VALUES(content_hash)', $putSql['sql'], 'the bounded writer should upsert the current identity row');
        assert_true(!str_contains($putSql['sql'], 'doc_len'), 'the bounded writer should not write a document length');
        foreach (['get_doc', 'get_doc_metadata', 'terms_for_doc', 'get_doc_lengths', 'get_meta', 'add_meta'] as $method) {
            assert_true(!method_exists($storage, $method), "relational storage should not expose {$method}");
        }

        $storage->replace_prepared_documents([], [201]);
        assert_true(!isset($wpdb->docs[201]), 'physical deletion should not retain a tombstone document');
        assert_same([101, 202, 203], wp_fts_quality_fake_mysql_document_ids($wpdb), 'bounded relational inspection should contain only physical document rows');
    });

    test_case('quality relational MySQL-dialect optimize prunes one bounded empty-term page without rebuilding dictionary frequencies', function (): void {
        $wpdb = new WP_FTS_Test_WPDB();
        $storage = new WP_FTS_Relational_Storage($wpdb);
        $plAlpha = WP_FTS_TermNamespace::namespace_term('pl', 'alpha');
        $plBeta = WP_FTS_TermNamespace::namespace_term('pl', 'beta');
        $enAlpha = WP_FTS_TermNamespace::namespace_term('en', 'alpha');

        $storage->replace_prepared_documents([
            ['doc_id' => 301, 'primary_lang' => 'pl', 'content_hash' => sha1('301'), 'snippet_text' => '', 'term_frequencies' => [$plAlpha => 2, $plBeta => 1], 'surface_frequencies' => []],
            ['doc_id' => 302, 'primary_lang' => 'pl', 'content_hash' => sha1('302'), 'snippet_text' => '', 'term_frequencies' => [$plAlpha => 1, $enAlpha => 1], 'surface_frequencies' => []],
            ['doc_id' => 303, 'primary_lang' => 'en', 'content_hash' => sha1('303'), 'snippet_text' => '', 'term_frequencies' => [$enAlpha => 3], 'surface_frequencies' => []],
        ]);
        $plAlphaImpacts = wp_fts_quality_fake_mysql_term_state($wpdb, $plAlpha)['postings'] ?? [];
        $enAlphaImpacts = wp_fts_quality_fake_mysql_term_state($wpdb, $enAlpha)['postings'] ?? [];
        $storage->replace_prepared_documents([], [301]);

        assert_true(!isset($wpdb->ftsTerms[$plBeta]), 'bounded replacement should retire a dictionary row whose final posting was deleted');

        for ($offset = 1; $offset <= WP_FTS_Relational_Storage::MAX_EMPTY_TERM_CLEANUP + 1; $offset++) {
            $empty = WP_FTS_TermNamespace::namespace_term('en', 'empty-maintenance-row-' . $offset);
            $wpdb->ftsTerms[$empty] = ['doc_freq' => 0];
        }
        $wpdb->ftsTerms[$enAlpha]['doc_freq'] = 999;

        $wpdb->prepared = [];
        $wpdb->queries = [];
        $epochBefore = $wpdb->searchEpoch;
        $storage->optimize();

        assert_same([302, 303], wp_fts_quality_fake_mysql_document_ids($wpdb), 'optimize should leave only the physical active document rows');
        assert_true(!isset($wpdb->docs[301]), 'bounded replacement should already have removed the physical document before optimize');
        assert_same([302 => $plAlphaImpacts[302]], wp_fts_quality_fake_mysql_term_state($wpdb, $plAlpha)['postings'] ?? null, 'optimize should remove deleted postings while preserving the surviving quantized impact');
        assert_true(!isset($wpdb->ftsTerms[$plBeta]), 'optimize should delete terms left with no postings');
        assert_true(!isset($wpdb->postings[$plBeta]), 'optimize should delete postings rows left with no active docs');
        assert_same($enAlphaImpacts, wp_fts_quality_fake_mysql_term_state($wpdb, $enAlpha)['postings'] ?? null, 'optimize should preserve active quantized impacts in other languages');
        assert_same(999, $wpdb->ftsTerms[$enAlpha]['doc_freq'] ?? null, 'optimize must not rescan all postings to repair dictionary frequencies');
        $remainingEmptyTerms = array_filter(
            $wpdb->ftsTerms,
            static fn(array $row): bool => (int) ($row['doc_freq'] ?? -1) === 0
        );
        assert_same(1, count($remainingEmptyTerms), 'one optimize pass should remove exactly one 1000-row empty-term page');
        assert_same(1, count($wpdb->queries), 'optimize should execute only one bounded maintenance statement');
        assert_contains('bounded_empty_terms', $wpdb->queries[0] ?? '', 'optimize should use the indexed bounded empty-term selector');
        assert_contains('ORDER BY term_id', $wpdb->queries[0] ?? '', 'optimize should make empty-term cleanup deterministic');
        assert_contains('LIMIT 1000', $wpdb->queries[0] ?? '', 'optimize should enforce the hard empty-term page ceiling in SQL');
        $optimizeSql = implode("\n", $wpdb->queries);
        assert_true(!str_contains($optimizeSql, 'UPDATE wp_fts_terms'), 'optimize must never issue a vocabulary-wide frequency rebuild');
        assert_true(!str_contains($optimizeSql, 'wp_fts_postings') && !str_contains($optimizeSql, 'COUNT('), 'optimize must not correlate or count posting rows to recompute frequencies');
        assert_true(!str_contains($optimizeSql, 'START TRANSACTION') && !str_contains($optimizeSql, 'COMMIT'), 'zero-frequency cleanup should not add a transaction wrapper around one atomic delete');
        assert_true(!str_contains($optimizeSql, 'meta:search-epoch'), 'zero-frequency cleanup should not publish a cursor epoch that cannot change results');
        assert_same($epochBefore, $wpdb->searchEpoch, 'removing rows that cannot match must not invalidate search cursors');
    });

    test_case('quality relational MySQL-dialect optimize leaves valid rows intact when empty-term cleanup fails', function (): void {
        $wpdb = new WP_FTS_Test_WPDB();
        $storage = new WP_FTS_Relational_Storage($wpdb);
        $term = WP_FTS_TermNamespace::namespace_term('en', 'rollback');

        $storage->replace_prepared_documents([
            ['doc_id' => 311, 'primary_lang' => 'en', 'content_hash' => sha1('311'), 'snippet_text' => '', 'term_frequencies' => [$term => 1], 'surface_frequencies' => []],
            ['doc_id' => 312, 'primary_lang' => 'en', 'content_hash' => sha1('312'), 'snippet_text' => '', 'term_frequencies' => [$term => 2], 'surface_frequencies' => []],
        ]);
        $storage->replace_prepared_documents([], [311]);
        $empty = WP_FTS_TermNamespace::namespace_term('en', 'rollback-empty');
        $wpdb->ftsTerms[$empty] = ['doc_freq' => 0];
        $wpdb->ftsTerms[$term]['doc_freq'] = 999;
        $before = [
            'postings' => $wpdb->postings,
            'docs' => $wpdb->docs,
            'terms' => $wpdb->ftsTerms,
        ];
        $wpdb->prepared = [];
        $wpdb->queries = [];
        $epochBefore = $wpdb->searchEpoch;
        $wpdb->failQueryPrefix = 'DELETE /* wp_fts:cleanup-empty-terms */';

        $thrown = false;
        try {
            $storage->optimize();
        } catch (RuntimeException $e) {
            $thrown = str_contains($e->getMessage(), 'remove bounded empty FTS terms');
        }

        assert_true($thrown, 'optimize should surface a failed empty-term cleanup');
        assert_same($before['postings'], $wpdb->postings, 'failed cleanup should not mutate valid postings');
        assert_same($before['docs'], $wpdb->docs, 'failed cleanup should not mutate active documents');
        assert_same($before['terms'], $wpdb->ftsTerms, 'failed cleanup should roll back the repaired frequency and every dictionary mutation');
        assert_same(999, $wpdb->ftsTerms[$term]['doc_freq'] ?? null, 'failed cleanup should retain the pre-transaction frequency for a complete retry');
        assert_true(isset($wpdb->ftsTerms[$empty]), 'failed cleanup should leave the empty dictionary row available for a later retry');
        assert_same([
            "DELETE /* wp_fts:cleanup-empty-terms */ cleanup_target\nFROM (\n    SELECT bounded_empty_terms.term_id\n    FROM (\n        SELECT term_id\n        FROM wp_fts_terms FORCE INDEX (empty_terms)\n        WHERE doc_freq = 0\n        ORDER BY term_id\n        LIMIT 1000\n    ) bounded_empty_terms\n) cleanup_driver\nSTRAIGHT_JOIN wp_fts_terms cleanup_target\n        ON cleanup_target.term_id = cleanup_driver.term_id",
        ], $wpdb->queries, 'failed optimize should surface its one bounded statement without publishing an epoch');
        assert_same($epochBefore, $wpdb->searchEpoch, 'failed optimize should not advance the visible search epoch');
    });

    test_case('quality relational mutation guard fences transaction commit after ownership loss', function (): void {
        $wpdb = new WP_FTS_Test_WPDB();
        $owned = true;
        $storage = new WP_FTS_Relational_Storage(
            $wpdb,
            null,
            static function () use (&$owned): void {
                if (!$owned) {
                    throw new RuntimeException('writer ownership lost');
                }
            }
        );

        $storage->begin_transaction();
        $storage->replace_prepared_documents([[
            'doc_id' => 321,
            'primary_lang' => 'en',
            'content_hash' => sha1('321'),
            'snippet_text' => '',
            'term_frequencies' => [],
            'surface_frequencies' => [],
        ]]);
        $owned = false;
        $lost = false;
        try {
            $storage->commit();
        } catch (RuntimeException $e) {
            $lost = $e->getMessage() === 'writer ownership lost';
            $storage->rollback();
        }

        assert_true($lost, 'transaction commit should be fenced after writer ownership loss');
        assert_true(!isset($wpdb->docs[321]), 'rolled-back fenced transaction should not publish document state');
        assert_true(in_array('ROLLBACK', $wpdb->queries, true), 'fenced transaction should remain rollback-safe after the guard rejects commit');
        assert_true(!in_array('COMMIT', $wpdb->queries, true), 'fenced transaction should never issue COMMIT after ownership loss');
    });

    test_case_with_pdo_sqlite_fixture('quality trusted language canonicalization stays separate from strict CLI tags', function (): void {
        $cases = [
            [' pl_PL ', 'pl-PL'],
            ['PT_br', 'pt-BR'],
            ['sr_Cyrl_RS', 'sr-Cyrl-RS'],
            ['zh-hant', 'zh-Hant'],
            ['es_419', 'es-419'],
            ['fr--CA', 'fr-CA'],
            ['x-private', 'x-private'],
            ['!!!', 'en'],
            ['', 'en'],
        ];

        foreach ($cases as [$input, $expected]) {
            assert_same($expected, WP_FTS_TermNamespace::canonicalize_lang($input, 'en'), "{$input} should canonicalize through TermNamespace");

            $wpdb = new WP_FTS_Relational_Regression_SQLite_WPDB();
            wp_fts_relational_regression_create_schema($wpdb);
            $storage = new WP_FTS_Relational_Storage($wpdb);
            $storage->replace_prepared_documents([[
                'doc_id' => 400,
                'primary_lang' => WP_FTS_TermNamespace::canonicalize_lang($input, 'und'),
                'content_hash' => sha1($input),
                'snippet_text' => '',
                'term_frequencies' => [],
                'surface_frequencies' => [],
            ]]);
            $storageExpected = WP_FTS_TermNamespace::canonicalize_lang($input, 'und');
            $storedPrimary = $wpdb->dbh->query('SELECT primary_lang FROM wp_fts_documents WHERE post_id = 400')->fetchColumn();
            assert_same($storageExpected, $storedPrimary, "{$input} should canonicalize the bounded writer's primary language without inventing an English partition");

            $fake = new WP_FTS_Test_WPDB();
            wp_fts_test_reset_wordpress_fakes();
            wp_fts_quality_reset_cli();
            wp_fts_quality_prepare_reindex();
            $strictLanguage = null;
            try {
                $strictLanguage = WP_FTS_TermNamespace::parse_language_tag($input);
            } catch (InvalidArgumentException) {
                // Trusted storage normalization remains deliberately lenient;
                // a supplied CLI tag must satisfy the public grammar instead.
            }
            if ($strictLanguage === null) {
                $error = null;
                try {
                    wp_fts_quality_with_wpdb($fake, static function () use ($input): void {
                        (new WP_FTS_WPCLI_Command())->reindex([], ['lang' => $input]);
                    });
                } catch (InvalidArgumentException $caught) {
                    $error = $caught;
                }
                assert_true($error instanceof InvalidArgumentException, "{$input} should be rejected as a malformed supplied CLI language");
                assert_same([], $fake->queries, "{$input} should create no durable scope after language rejection");
                assert_same([], $GLOBALS['wp_fts_test_schedule_calls'], "{$input} should schedule no worker after language rejection");
                continue;
            }

            $raw = wp_fts_quality_with_wpdb($fake, static function () use ($input): string {
                return wp_fts_test_capture_cli(static function () use ($input): void {
                    (new WP_FTS_WPCLI_Command())->reindex([], [
                        'lang' => $input,
                        'limit' => '1',
                        'format' => 'json',
                    ]);
                });
            });

            $cliExpected = $strictLanguage;
            $scope = wp_fts_quality_reindex_scope_payload($fake);
            assert_same(
                ['document_lang' => $cliExpected],
                $scope['index_options'] ?? null,
                "{$input} should canonicalize the durable scope language payload"
            );
            $result = json_decode(trim($raw), true, flags: JSON_THROW_ON_ERROR);
            assert_same($cliExpected, $result['language'] ?? null, "{$input} CLI result should report the canonical language partition");
            assert_same('queued', $result['status'] ?? null, "{$input} should report queued work rather than synchronous completion");
            assert_same([], $fake->docs, "{$input} reindex invocation must not analyze or publish a source document");
            assert_same(1, count($fake->queries), "{$input} reindex invocation should execute only its scope UPSERT");
            assert_same(1, count($GLOBALS['wp_fts_test_schedule_calls']), "{$input} reindex invocation should schedule one background worker event");
            assert_same([], WP_CLI::$successMessages, "{$input} asynchronous reindex should not emit a second success channel");
        }
    });

    test_case('quality wp cli reindex defaults only when language and filters are absent', function (): void {
        $fake = new WP_FTS_Test_WPDB();
        wp_fts_test_reset_wordpress_fakes();
        wp_fts_quality_reset_cli();
        wp_fts_quality_prepare_reindex();
        $raw = wp_fts_quality_with_wpdb($fake, static function (): string {
            return wp_fts_test_capture_cli(static function (): void {
                (new WP_FTS_WPCLI_Command())->reindex([], ['format' => 'json']);
            });
        });
        $result = json_decode(trim($raw), true, flags: JSON_THROW_ON_ERROR);
        assert_same(['draft', 'future', 'pending', 'private', 'publish'], $result['post_status'] ?? null, 'an absent reindex status filter should use the documented set');
        assert_same(['post'], $result['post_type'] ?? null, 'an absent reindex post type should use post');
        assert_same('', $result['language'] ?? null, 'an absent reindex language should preserve automatic language resolution');
        $scope = wp_fts_quality_reindex_scope_payload($fake);
        assert_same(false, array_key_exists('index_options', $scope), 'an absent reindex language should add no forced language option');

        foreach (['post_status', 'post_type', 'lang'] as $key) {
            $values = [null, false, 1, 1.0, [], new stdClass(), '', '   '];
            if ($key !== 'lang') {
                array_push($values, ',', 'value,', ',value', 'value,,other');
            } else {
                array_push($values, ' en', 'en ');
            }
            foreach ($values as $value) {
                $rejectedFake = new WP_FTS_Test_WPDB();
                wp_fts_test_reset_wordpress_fakes();
                wp_fts_quality_reset_cli();
                wp_fts_quality_prepare_reindex();
                $error = null;
                try {
                    wp_fts_quality_with_wpdb($rejectedFake, static function () use ($key, $value): void {
                        (new WP_FTS_WPCLI_Command())->reindex([], [$key => $value]);
                    });
                } catch (InvalidArgumentException $caught) {
                    $error = $caught;
                }
                assert_true($error instanceof InvalidArgumentException, "a supplied malformed reindex {$key} must not use its absent-value default");
                assert_same([], $rejectedFake->queries, "a malformed reindex {$key} should create no durable work");
                assert_same([], $GLOBALS['wp_fts_test_schedule_calls'], "a malformed reindex {$key} should schedule no worker");
            }
        }
    });

    test_case('quality plugin reindex entry points reject non-exact IDs filters and options before SQL', function (): void {
        $assertRejected = static function (callable $operation, string $message): void {
            $fake = new WP_FTS_Test_WPDB();
            wp_fts_test_reset_wordpress_fakes();
            wp_fts_quality_prepare_reindex();
            $error = null;
            try {
                wp_fts_quality_with_wpdb($fake, $operation);
            } catch (InvalidArgumentException $caught) {
                $error = $caught;
            }
            assert_true($error instanceof InvalidArgumentException, $message);
            assert_same([], $fake->queries, $message . ' before SQL');
            assert_same([], $GLOBALS['wp_fts_test_schedule_calls'], $message . ' before scheduling work');
        };

        foreach ([
            [1 => 1],
            ['post' => 1],
            array_fill(0, WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS + 1, 1),
            [0],
            [-1],
            ['1'],
            [1.0],
            [true],
            [false],
            [null],
            [[]],
            [new stdClass()],
        ] as $postIds) {
            $assertRejected(
                static fn(): int => WP_FTS_Plugin::enqueue_posts_for_reindex($postIds),
                'direct reindex IDs should be a bounded list of positive native integers'
            );
        }

        foreach ([
            ['lang' => 'en'],
            ['default_lang' => 'en'],
            ['unknown' => true],
            [0 => 'document_lang'],
        ] as $indexOptions) {
            $assertRejected(
                static fn(): int => WP_FTS_Plugin::enqueue_posts_for_reindex([1], $indexOptions),
                'direct reindex should accept only the document_lang option'
            );
        }
        foreach ([null, false, true, 1, 1.0, [], new stdClass(), '', '   ', ' en', 'en ', str_repeat('l', 65)] as $language) {
            $assertRejected(
                static fn(): int => WP_FTS_Plugin::enqueue_posts_for_reindex([1], ['document_lang' => $language]),
                'direct reindex document_lang should be an unpadded nonempty native string'
            );
        }

        $baseScope = [
            'post_status' => ['publish'],
            'post_type' => ['post'],
        ];
        foreach ([
            ['post_status' => ['publish']],
            ['post_type' => ['post']],
            $baseScope + ['lang' => 'en'],
            $baseScope + ['unknown' => true],
            $baseScope + [0 => 'post_status'],
        ] as $scopeOptions) {
            $assertRejected(
                static fn(): string => WP_FTS_Plugin::enqueue_reindex_scope($scopeOptions),
                'filtered reindex should require both filters and accept only its exact option keys'
            );
        }

        foreach (['post_status', 'post_type'] as $filter) {
            foreach ([
                null,
                'publish',
                [],
                [1 => 'publish'],
                array_fill(0, 33, 'publish'),
                [null],
                [false],
                [1],
                [1.0],
                [[]],
                [new stdClass()],
                [''],
                ['   '],
                [' publish'],
                ['publish '],
                [str_repeat('p', 65)],
            ] as $value) {
                $scopeOptions = $baseScope;
                $scopeOptions[$filter] = $value;
                $assertRejected(
                    static fn(): string => WP_FTS_Plugin::enqueue_reindex_scope($scopeOptions),
                    "filtered reindex {$filter} should be a bounded nonempty list of unpadded native strings"
                );
            }
        }

        foreach ([null, false, true, -1, 0.0, 1.0, '0', '1', [], new stdClass()] as $limit) {
            $scopeOptions = $baseScope + ['limit' => $limit];
            $assertRejected(
                static fn(): string => WP_FTS_Plugin::enqueue_reindex_scope($scopeOptions),
                'filtered reindex limit should be a nonnegative native integer'
            );
        }
        foreach ([null, false, true, 1, 1.0, [], new stdClass(), '', '   ', ' en', 'en ', str_repeat('l', 65)] as $language) {
            $scopeOptions = $baseScope + ['document_lang' => $language];
            $assertRejected(
                static fn(): string => WP_FTS_Plugin::enqueue_reindex_scope($scopeOptions),
                'filtered reindex document_lang should be an unpadded nonempty native string'
            );
        }
    });

    test_case('quality wp cli reindex queues one constant-cost scope for a 100000-post source shape', function (): void {
        $fake = new WP_FTS_Test_WPDB();
        $fake->postRows = array_fill(0, 100000, (object) [
            'ID' => 99999,
            'post_title' => 'Must never be materialized',
            'post_content' => str_repeat('source-body-must-never-be-read ', 100),
            'post_status' => 'publish',
            'post_type' => 'post',
        ]);
        $fake->recordReadQueries = true;
        wp_fts_test_reset_wordpress_fakes();
        wp_fts_quality_reset_cli();
        wp_fts_quality_prepare_reindex();

        $raw = wp_fts_quality_with_wpdb($fake, static function (): string {
            return wp_fts_test_capture_cli(static function (): void {
                (new WP_FTS_WPCLI_Command())->reindex([], [
                    'post_status' => 'publish, private, publish',
                    'post_type' => 'post,page,post',
                    'lang' => 'pt_BR',
                    'limit' => '100000',
                    'format' => 'json',
                ]);
            });
        });

        assert_same(100000, count($fake->postRows), 'worst-case fixture should expose a 100,000-row canonical source shape');
        $message = 'Queued one durable filtered reindex scope. WP-Cron will process it in bounded batches; '
            . 'use `wp fts status` to monitor progress or `wp fts process-batch --batch_size=100 --time_budget=20` '
            . 'for one bounded manual pass.';
        $result = json_decode(trim($raw), true, flags: JSON_THROW_ON_ERROR);
        assert_same([
            'status' => 'queued',
            'post_status' => ['private', 'publish'],
            'post_type' => ['page', 'post'],
            'language' => 'pt-BR',
            'requested_limit' => 100000,
            'has_more' => true,
            'message' => $message,
        ], $result, 'JSON output should expose only the normalized asynchronous reindex contract');
        assert_same([
            'reason' => 'wp_cli_reindex',
            'post_status' => ['private', 'publish'],
            'post_type' => ['page', 'post'],
            'remaining_limit' => 100000,
            'index_options' => ['document_lang' => 'pt-BR'],
        ], wp_fts_quality_reindex_scope_payload($fake), 'the one scope should retain every normalized selection constraint');

        $scopeUpserts = wp_fts_quality_prepared_like($fake, 'INSERT INTO wp_fts_work');
        assert_same(1, count($scopeUpserts), '100,000 possible source rows should still issue exactly one prepared scope UPSERT');
        assert_contains("(%s, 'scope', 0, 1", $scopeUpserts[0]['sql'], 'the only write should be one constant-size scope row');
        assert_true(!str_contains($scopeUpserts[0]['sql'], "(%s, 'post', %d"), 'the command must not directly materialize any post queue rows');
        assert_same(1, count($fake->queries), '100,000 possible source rows should execute exactly one database statement');
        assert_true(!str_contains(implode("\n", $fake->queries), 'SELECT p.ID'), 'the command must execute zero source-ID selector statements');
        assert_true(!str_contains(implode("\n", $fake->queries), 'post_content'), 'the command must execute zero source-content statements');
        assert_same([], $fake->docs, 'the command must perform zero foreground document writes');
        assert_same(1, count(wp_fts_quality_reindex_scope_rows($fake)), 'the command should create exactly one durable scope row');
        assert_same(1, count($GLOBALS['wp_fts_test_schedule_calls']), 'the command should schedule exactly one WP-Cron worker event');
        assert_same(WP_FTS_Plugin::CRON_HOOK, $GLOBALS['wp_fts_test_schedule_calls'][0]['hook'] ?? null, 'the event should target the bounded queue processor');
        assert_same([], WP_CLI::$successMessages, 'structured output should be the command\'s only success channel');

        $tableFake = new WP_FTS_Test_WPDB();
        wp_fts_test_reset_wordpress_fakes();
        wp_fts_quality_reset_cli();
        wp_fts_quality_prepare_reindex();
        $table = wp_fts_quality_with_wpdb($tableFake, static function (): string {
            return wp_fts_test_capture_cli(static function (): void {
                (new WP_FTS_WPCLI_Command())->reindex([], [
                    'post_status' => 'publish',
                    'post_type' => 'post',
                    'format' => 'table',
                ]);
            });
        });
        assert_same(
            ['field', 'status', 'post_status', 'post_type', 'language', 'requested_limit', 'has_more', 'message'],
            array_map(
                static fn(string $line): string => explode("\t", $line, 2)[0],
                explode("\n", trim($table))
            ),
            'table output should expose a header and the same seven stable fields as JSON'
        );
        assert_contains("status\tqueued", $table, 'table output should report queued status without a completion claim');
        assert_same(1, count($tableFake->queries), 'table formatting must not add database work');
    });

    test_case('quality wp cli reindex rejects oversized filters before splitting or querying', function (): void {
        $scenarios = [
            ['value' => str_repeat('x', 4097), 'message' => 'at most 4,096 bytes'],
            ['value' => implode(',', array_map(static fn(int $index): string => 'status-' . $index, range(1, 33))), 'message' => 'at most 32 values'],
            ['value' => str_repeat('y', 65), 'message' => 'at most 64 bytes'],
        ];

        foreach ($scenarios as $scenario) {
            $fake = new WP_FTS_Test_WPDB();
            wp_fts_test_reset_wordpress_fakes();
            wp_fts_quality_reset_cli();
            wp_fts_quality_prepare_reindex();
            $error = null;
            try {
                wp_fts_quality_with_wpdb($fake, static function () use ($scenario): void {
                    (new WP_FTS_WPCLI_Command())->reindex([], [
                        'post_status' => $scenario['value'],
                        'post_type' => 'post',
                    ]);
                });
            } catch (InvalidArgumentException $caught) {
                $error = $caught;
            }

            assert_true($error instanceof InvalidArgumentException, 'an oversized reindex filter should fail before durable scope creation');
            assert_contains($scenario['message'], $error?->getMessage() ?? '', 'the filter rejection should identify the violated hard bound');
            assert_same([], $fake->prepared, 'rejected reindex filters should prepare zero SQL statements');
            assert_same([], $fake->queries, 'rejected reindex filters should execute zero SQL statements');
            assert_same([], $fake->queue, 'rejected reindex filters should create no durable work');
            assert_same([], $GLOBALS['wp_fts_test_schedule_calls'], 'rejected reindex filters should schedule no work');
        }
    });

        test_case('quality wp cli reindex coalesces while an existing writer lease remains untouched', function (): void {
        $fake = new WP_FTS_Test_WPDB();
        wp_fts_test_reset_wordpress_fakes();
        wp_fts_quality_reset_cli();
        wp_fts_quality_prepare_reindex();
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION] = [
            'token' => 'active-worker-token',
            'mode' => 'cron',
            'started_at' => time(),
            'expires_at' => time() + 300,
        ];

        $outputs = wp_fts_quality_with_wpdb($fake, static function (): array {
            $outputs = [];
            for ($pass = 0; $pass < 2; $pass++) {
                $outputs[] = wp_fts_test_capture_cli(static function (): void {
                    (new WP_FTS_WPCLI_Command())->reindex([], [
                        'post_status' => 'publish,draft',
                        'post_type' => 'post',
                        'limit' => '2',
                        'format' => 'json',
                    ]);
                });
            }

            return $outputs;
        });

        foreach ($outputs as $raw) {
            $result = json_decode(trim($raw), true, flags: JSON_THROW_ON_ERROR);
            assert_same('queued', $result['status'] ?? null, 'queue acceptance should not claim that background work completed');
            assert_same('', $result['language'] ?? null, 'automatic-language scope output should use an empty language field');
            assert_same(true, $result['has_more'] ?? null, 'the immediate result should explicitly report pending background work');
        }
        assert_same([
            'reason' => 'wp_cli_reindex',
            'post_status' => ['draft', 'publish'],
            'post_type' => ['post'],
            'remaining_limit' => 2,
        ], wp_fts_quality_reindex_scope_payload($fake), 'automatic-language scopes should omit a forced analyzer partition');
        $scopeRows = wp_fts_quality_reindex_scope_rows($fake);
        assert_same(2, (int) ($scopeRows[0]['generation'] ?? 0), 'identical invocations should coalesce by incrementing one scope generation');
        assert_same(2, count($fake->prepared), 'two invocations should execute one scope UPSERT apiece');
        assert_same(2, count($fake->queries), 'coalescing should need no read-before-write or source query');
        assert_same(1, count($GLOBALS['wp_fts_test_schedule_calls']), 'an already pending cron event should remain one scheduled event across coalesced invocations');
        assert_same([], $fake->docs, 'queue acceptance should do no document analysis or writes');
        assert_same('active-worker-token', $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION]['token'] ?? null, 'asynchronous scope enqueue should never disturb the active writer lease');
        assert_same([], $GLOBALS['wp_fts_test_added_options'], 'scope enqueue should not attempt to acquire a writer lease');
        assert_same([], $GLOBALS['wp_fts_test_deleted_options'], 'scope enqueue should not delete a writer lease');
        assert_same([], WP_CLI::$successMessages, 'coalesced asynchronous output should remain structured-only');

        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WPCLICommand.php');
        assert_true(!str_contains($source, 'private function drain_reindex_work'), 'the CLI command should have no synchronous drain loop');
        assert_true(!str_contains($source, 'private function reindex_posts'), 'the CLI command should have no hidden synchronous reindex wrapper');
        assert_true(!str_contains($source, "'source' => 'wp-cli-reindex'"), 'the reindex command should never invoke a worker pass internally');
    });

    test_case('quality wp cli search delegates cursor pages through the plugin facade', function (): void {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WPCLICommand.php');

        assert_contains('WP_FTS_Plugin::search_page($query, $searchOptions)', $source, 'ordinary CLI search should use the visibility and readiness facade');
        assert_contains('WP_FTS_Plugin::search_with_explain($query, $searchOptions)', $source, 'CLI explain and diagnose should use the operator facade');
        assert_true(!str_contains($source, 'new WP_FTS_Searcher'), 'no CLI search path should instantiate a searcher directly');
        assert_contains('WP_FTS_Plugin::MAX_SEARCH_LIMIT', $source, 'CLI pages should clamp to the public facade limit before search work');
    });

    test_case_with_pdo_sqlite_fixture('quality wp cli search forwards bounded filters and formats cursor pages', function (): void {
        [$fake] = wp_fts_relational_regression_search_fixture();
        wp_fts_relational_regression_add_post($fake, 901, '2026-04-01 00:00:00', 'post', 'publish');
        wp_fts_relational_regression_add_post($fake, 902, '2026-04-02 00:00:00', 'page', 'draft');
        wp_fts_relational_regression_add_post($fake, 903, '2026-04-03 00:00:00', 'post', 'publish');
        $fake->execute("UPDATE wp_posts SET post_title='Zamek Published' WHERE ID=901", []);
        $fake->execute("UPDATE wp_posts SET post_title='Zamek Draft' WHERE ID=902", []);
        $fake->execute("UPDATE wp_posts SET post_title='Castle Published' WHERE ID=903", []);
        $fake->execute("UPDATE wp_fts_documents SET primary_lang='pl-PL', snippet_text='Zamek published snippet source' WHERE post_id=901", []);
        $fake->execute("UPDATE wp_fts_documents SET primary_lang='pl-PL', snippet_text='Zamek draft snippet source' WHERE post_id=902", []);
        $fake->execute("UPDATE wp_fts_documents SET primary_lang='en', snippet_text='Castle published snippet source' WHERE post_id=903", []);
        wp_fts_relational_regression_add_term($fake, 'zamek', [901 => 3.0, 902 => 1.0], 'pl-PL');
        wp_fts_relational_regression_add_term($fake, 'castl', [903 => 2.0], 'en');

        wp_fts_test_reset_wordpress_fakes();
        wp_fts_quality_reset_cli();
        wp_fts_quality_with_wpdb($fake, static function (): void {
            wp_fts_test_mark_search_takeover_ready();
            foreach ([
                WP_FTS_Plugin::READINESS_INCARNATION_OPTION,
                WP_FTS_Plugin::SEARCH_READY_INCARNATION_OPTION,
            ] as $optionName) {
                $fake = $GLOBALS['wpdb'];
                $fake->execute(
                    'UPDATE wp_options SET option_value=? WHERE option_name=?',
                    [maybe_serialize($GLOBALS['wp_fts_test_options'][$optionName]), $optionName]
                );
            }
            $command = new WP_FTS_WPCLI_Command();
            $command->search(['zamek'], [
                'lang' => 'pl_PL',
                'limit' => '1',
                'mode' => 'OR',
                'post_status' => 'publish',
                'post_type' => 'post',
                'snippet' => true,
            ]);
        });

        $formats = $GLOBALS['wp_fts_quality_cli_format_items'] ?? [];
        assert_same(1, count($formats), 'search should format one result table');
        assert_same('table', $formats[0]['format'], 'search should request table output');
        assert_same(['doc_id', 'score', 'post_id', 'post_type', 'post_status', 'post_date_gmt', 'title', 'has_more', 'next_cursor', 'previous_cursor', 'snippet'], $formats[0]['fields'], 'search should expose product metadata and cursor-page fields');
        assert_same([901], array_column($formats[0]['items'], 'doc_id'), 'search filters should keep the published Polish post');
        assert_same('no', $formats[0]['items'][0]['has_more'], 'a one-result filtered corpus should not expose another page');
        assert_same('Zamek Published', $formats[0]['items'][0]['title'], 'search rows should include stored title metadata');
        assert_contains('Zamek published', $formats[0]['items'][0]['snippet'], 'search rows should include snippets when requested');

        wp_fts_quality_reset_cli();
        wp_fts_quality_with_wpdb($fake, static function (): void {
            $command = new WP_FTS_WPCLI_Command();
            $command->search(['castle'], ['lang' => 'en', 'limit' => '5', 'mode' => 'AND']);
        });
        $formats = $GLOBALS['wp_fts_quality_cli_format_items'] ?? [];
        assert_same([903], array_column($formats[0]['items'], 'doc_id'), 'search should use --lang alias for English partition');

        $thrown = false;
        wp_fts_quality_with_wpdb($fake, static function () use (&$thrown): void {
            try {
                $command = new WP_FTS_WPCLI_Command();
                $command->search(['zamek'], ['lang' => 'pl', 'mode' => 'XOR']);
            } catch (InvalidArgumentException) {
                $thrown = true;
            }
        });
        assert_true($thrown, 'invalid search mode should be rejected');

        wp_fts_quality_reset_cli();
        wp_fts_quality_with_wpdb($fake, static function (): void {
            $command = new WP_FTS_WPCLI_Command();
            $command->search(['missing'], ['lang' => 'pl']);
        });
        $formats = $GLOBALS['wp_fts_quality_cli_format_items'] ?? [];
        assert_same([], $formats[0]['items'], 'missing search terms should format an empty result set');
    });

    test_case('quality wp cli publishes documented hyphenated subcommands', function (): void {
        $subcommands = [
            'failed_items' => 'failed-items',
            'retry_failed_item' => 'retry-failed-item',
            'clear_failed_item' => 'clear-failed-item',
            'schedule_queue' => 'schedule-queue',
            'reset_index' => 'reset-index',
            'process_batch' => 'process-batch',
            'import_lemma_pack' => 'import-lemma-pack',
            'import_conllu_lemma_pack' => 'import-conllu-lemma-pack',
            'import_unimorph_lemma_pack' => 'import-unimorph-lemma-pack',
        ];

        foreach ($subcommands as $method => $subcommand) {
            $comment = (new ReflectionMethod(WP_FTS_WPCLI_Command::class, $method))->getDocComment();
            assert_true(is_string($comment), "{$method} should retain a WP-CLI command docblock");
            $tags = array_map(
                static fn(string $line): string => ltrim($line, " \t*"),
                explode("\n", (string) $comment)
            );
            assert_true(in_array("@subcommand {$subcommand}", $tags, true), "{$method} should publish the documented {$subcommand} command");
        }

        $resetComment = (string) (new ReflectionMethod(WP_FTS_WPCLI_Command::class, 'reset_index'))->getDocComment();
        assert_contains('* [--yes]', $resetComment, 'reset-index should declare --yes with valid WP-CLI optional-flag syntax while enforcing confirmation at runtime');
    });

    test_case('quality wp cli register is explicit and plugin runtime hooks are WordPress scoped', function (): void {
        $GLOBALS['wp_fts_quality_add_action_calls'] = [];
        wp_fts_quality_reset_cli();

        require_once dirname(__DIR__, 2) . '/indexer.php';
        $actionsBeforeExplicitRegistration = count($GLOBALS['wp_fts_quality_add_action_calls']);
        $filtersBeforeExplicitRegistration = count($GLOBALS['wp_fts_test_filter_registrations'] ?? []);
        WP_FTS_Plugin::register_hooks();
        $registeredActions = array_slice($GLOBALS['wp_fts_quality_add_action_calls'], $actionsBeforeExplicitRegistration);
        $registeredFilters = array_slice($GLOBALS['wp_fts_test_filter_registrations'] ?? [], $filtersBeforeExplicitRegistration);
        $hooks = array_column($registeredActions, 'hook');
        sort($hooks, SORT_STRING);
        $expectedHooks = [
            WP_FTS_Plugin::CRON_HOOK,
            WP_FTS_Plugin::SCHEMA_REPAIR_CRON_HOOK,
            WP_FTS_Plugin::SCHEMA_SITE_CRON_HOOK,
            WP_FTS_Plugin::SITE_SCHEMA_RETRY_CRON_HOOK,
            'add_meta_boxes',
            'add_post_meta',
            'add_term_relationship',
            'added_post_meta',
            'admin_init',
            'admin_init',
            'admin_menu',
            'before_delete_post',
            'delete_post_meta',
            'delete_term',
            'delete_term_relationships',
            'deleted_post',
            'deleted_post_meta',
            'deleted_term_relationships',
            'edit_terms',
            'edited_term',
            'init',
            'init',
            'loop_end',
            'loop_start',
            'pre_delete_term',
            'pre_post_update',
            'pre_get_posts',
            'pre_get_posts',
            'rest_api_init',
            'restrict_manage_posts',
            'save_post',
            'set_object_terms',
            'shutdown',
            'shutdown',
            'shutdown',
            'switch_blog',
            'update_post_meta',
            'updated_post_meta',
            'wp_initialize_site',
            'wp_after_insert_post',
            'wp_ajax_wp_fts_sandbox_result_details',
        ];
        sort($expectedHooks, SORT_STRING);
        assert_same($expectedHooks, $hooks, 'plugin runtime hooks should be registered through WordPress add_action');
        $shutdownActions = array_values(array_filter(
            $registeredActions,
            static fn(array $action): bool => ($action['hook'] ?? '') === 'shutdown'
        ));
        assert_same(
            [
                [WP_FTS_Plugin::class, 'flush_relationship_mutations'],
                [WP_FTS_Plugin::class, 'flush_post_meta_mutations'],
                [WP_FTS_Plugin::class, 'flush_foreground_bulk_mutations'],
            ],
            array_column($shutdownActions, 'callback'),
            'shutdown should promote request-coalesced boundaries before the final bulk handoff'
        );
        assert_same(
            [PHP_INT_MAX - 2, PHP_INT_MAX - 1, PHP_INT_MAX],
            array_column($shutdownActions, 'priority'),
            'request-end handoffs should preserve their lifecycle order at the final shutdown priorities'
        );

        $actionSignatures = [];
        foreach ($registeredActions as $action) {
            $callback = $action['callback'] ?? null;
            $callbackName = is_array($callback)
                ? (string) ($callback[0] ?? '') . '::' . (string) ($callback[1] ?? '')
                : (is_string($callback) ? $callback : get_debug_type($callback));
            $signature = implode('|', [
                (string) ($action['hook'] ?? ''),
                $callbackName,
                (string) ($action['priority'] ?? ''),
                (string) ($action['accepted_args'] ?? ''),
            ]);
            assert_true(!isset($actionSignatures[$signature]), "runtime hook registration must not duplicate {$signature}");
            $actionSignatures[$signature] = true;
        }

        $searchActionPriorities = [];
        foreach ($registeredActions as $action) {
            $callback = $action['callback'] ?? null;
            $method = is_array($callback) ? ($callback[1] ?? null) : null;
            if (($action['hook'] ?? null) === 'pre_get_posts' && is_string($method)) {
                $searchActionPriorities[$method] = $action['priority'] ?? null;
            }
        }
        ksort($searchActionPriorities, SORT_STRING);
        assert_same([
            'prepare_admin_post_search_query' => WP_FTS_Plugin::SEARCH_REPLACEMENT_PRIORITY,
            'prepare_frontend_search_query' => WP_FTS_Plugin::SEARCH_REPLACEMENT_PRIORITY,
        ], $searchActionPriorities, 'search pre_get_posts hooks should use the late replacement priority');

        $searchFilterPriorities = [];
        foreach ($registeredFilters as $filter) {
            $callback = $filter['callback'] ?? null;
            $method = is_array($callback) ? ($callback[1] ?? null) : null;
            if (is_string($method) && in_array($method, [
                'filter_admin_post_search_found_posts',
                'filter_frontend_search_found_posts',
                'replace_admin_post_search_posts',
                'replace_frontend_search_posts',
            ], true)) {
                $searchFilterPriorities[$method] = [
                    'hook' => $filter['hook'] ?? null,
                    'priority' => $filter['priority'] ?? null,
                    'accepted_args' => $filter['accepted_args'] ?? null,
                ];
            }
        }
        ksort($searchFilterPriorities, SORT_STRING);
        assert_same([
            'filter_admin_post_search_found_posts' => ['hook' => 'found_posts', 'priority' => WP_FTS_Plugin::SEARCH_REPLACEMENT_PRIORITY, 'accepted_args' => 2],
            'filter_frontend_search_found_posts' => ['hook' => 'found_posts', 'priority' => WP_FTS_Plugin::SEARCH_REPLACEMENT_PRIORITY, 'accepted_args' => 2],
            'replace_admin_post_search_posts' => ['hook' => 'posts_pre_query', 'priority' => WP_FTS_Plugin::SEARCH_REPLACEMENT_PRIORITY, 'accepted_args' => 2],
            'replace_frontend_search_posts' => ['hook' => 'posts_pre_query', 'priority' => WP_FTS_Plugin::SEARCH_REPLACEMENT_PRIORITY, 'accepted_args' => 2],
        ], $searchFilterPriorities, 'search replacement filters should use the late replacement priority');

        $actionCountBeforeCliRegistration = count($GLOBALS['wp_fts_quality_add_action_calls']);
        WP_FTS_WPCLI_Command::register();
        assert_same(['fts' => WP_FTS_WPCLI_Command::class], WP_CLI::$commands, 'explicit WP-CLI registration should keep the command control plane');
        assert_same($actionCountBeforeCliRegistration, count($GLOBALS['wp_fts_quality_add_action_calls']), 'explicit WP-CLI registration should not add indexing hooks');
    });
}
