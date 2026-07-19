<?php
declare(strict_types=1);

test_case('explicit MySQL physical diagnostics use one bounded six-table snapshot', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $storage = new WP_FTS_Storage_Mysql($fake);
    $fake->num_queries = 0;
    $fake->prepared = [];

    $verification = $storage->verify_schema_and_scope_keyset_indexes();

    assert_same(true, $verification['valid'] ?? null, 'the complete fake physical contract should pass the combined verifier');
    assert_same(true, $verification['fts_tables_valid'] ?? null, 'combined verification should retain the FTS-table result separately');
    assert_same(true, $verification['scope_keyset_indexes']['valid'] ?? null, 'combined verification should include both selective core-table indexes');
    assert_same(2, $fake->num_queries, 'a cold MySQL physical diagnostic should use one capability read and one schema snapshot');
    $snapshots = array_values(array_filter(
        $fake->prepared,
        static fn(array $entry): bool => str_starts_with((string) ($entry['sql'] ?? ''), '/* wp_fts:physical-schema-snapshot */')
    ));
    assert_same(1, count($snapshots), 'all six physical objects should share one prepared information_schema snapshot');
    foreach (['wp_fts_terms', 'wp_fts_postings', 'wp_fts_documents', 'wp_fts_work', 'wp_posts', 'wp_term_relationships'] as $table) {
        assert_true(in_array($table, $snapshots[0]['args'] ?? [], true), "the combined physical snapshot should bind {$table}");
    }
});

test_case('set-oriented MySQL snapshot preserves every physical damage check', function (): void {
    $damageCases = [
        'column type' => static function (WP_FTS_Test_WPDB $fake): void {
            $fake->schemaColumnDefinitions['wp_fts_terms']['term'] = ['Type' => 'varchar(255)'];
        },
        'index order' => static function (WP_FTS_Test_WPDB $fake): void {
            $fake->schemaIndexes['wp_fts_postings']['post_term_impact'] = ['term_id', 'post_id', 'impact'];
        },
        'index uniqueness' => static function (WP_FTS_Test_WPDB $fake): void {
            $fake->schemaUniqueIndexes['wp_fts_terms']['term_identity'] = false;
        },
        'index visibility' => static function (WP_FTS_Test_WPDB $fake): void {
            $fake->schemaInvisibleIndexes['wp_fts_work']['ready'] = true;
        },
        'table engine' => static function (WP_FTS_Test_WPDB $fake): void {
            $fake->schemaEngines['wp_fts_documents'] = 'MyISAM';
        },
        'scope index order' => static function (WP_FTS_Test_WPDB $fake): void {
            $fake->schemaIndexes['wp_term_relationships'][WP_FTS_Storage_Mysql::TARGETED_SCOPE_INDEX_NAME] = ['object_id', 'term_taxonomy_id'];
        },
    ];

    foreach ($damageCases as $name => $damage) {
        $fake = new WP_FTS_Test_WPDB();
        $damage($fake);
        $fake->num_queries = 0;
        $verification = (new WP_FTS_Storage_Mysql($fake))->verify_schema_and_scope_keyset_indexes();
        assert_same(false, $verification['valid'] ?? null, "{$name} damage should fail the exact combined contract");
        assert_same(2, $fake->num_queries, "{$name} detection should remain two fixed metadata statements");
    }
});

test_case('failed physical metadata reads report unavailable instead of missing tables', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->failReadQueryPrefix = '/* wp_fts:physical-schema-snapshot */';
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_mark_search_takeover_ready();
    WP_FTS_Plugin::reset_request_caches();
    try {
        $verification = (new WP_FTS_Storage_Mysql($fake))->verify_schema_and_scope_keyset_indexes();
        assert_same(false, $verification['valid'] ?? null, 'a denied metadata snapshot must fail closed');
        assert_same(false, $verification['available'] ?? null, 'a denied metadata snapshot must be explicitly unavailable');
        assert_same([], $verification['missing_tables'] ?? null, 'an unavailable snapshot must not invent missing physical tables');
        assert_contains('unavailable', (string) ($verification['scope_keyset_indexes']['error'] ?? ''), 'scope verification should distinguish unavailable metadata from a missing index');

        WP_FTS_Plugin::reset_request_caches();
        $status = WP_FTS_Plugin::schema_status();
        assert_same('unavailable', $status['status'] ?? null, 'current logical schema plus denied metadata should retain the public unavailable state');
    } finally {
        $wpdb = $oldWpdb;
        WP_FTS_Plugin::reset_request_caches();
    }
});

test_case('failed MySQL capability metadata reads fail closed before the snapshot', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $fake->failReadQueryPrefix = '/* wp_fts:physical-schema-capabilities */';
    $verification = (new WP_FTS_Storage_Mysql($fake))->verify_schema_and_scope_keyset_indexes();

    assert_same(false, $verification['valid'] ?? null, 'denied capability metadata must not silently skip visibility or expression checks');
    assert_same(false, $verification['available'] ?? null, 'denied capability metadata must make physical verification unavailable');
    assert_same([], $verification['missing_tables'] ?? null, 'a denied capability read must not invent missing physical tables');
    assert_same(1, $fake->num_queries, 'a denied capability read should fail closed without issuing the dependent snapshot');
});

test_case('operator support and diagnose stay inside fixed total statement ceilings', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    try {
        $storage = wp_fts_test_unleased_storage();
        $analyzer = WP_FTS_Plugin::runtime_analyzer();
        foreach ([1 => 'CLI diagnostics needle', 2 => 'CLI reference needle'] as $postId => $title) {
            wp_fts_test_replace_document_fields($storage, $analyzer, $postId, [[
                'name' => 'content',
                'text' => strtolower($title),
                'boost' => 1.0,
            ]], [
                'lang' => 'en',
                'metadata' => [
                    'post_id' => $postId,
                    'post_type' => $postId === 1 ? 'post' : 'page',
                    'post_status' => 'publish',
                    'post_date_gmt' => '2026-06-' . (17 + $postId) . ' 00:00:00',
                    'title' => $title,
                    'search_text' => strtolower($title),
                ],
            ]);
        }
        wp_fts_test_mark_search_takeover_ready();
        $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
        wp_fts_test_prepare_cli_diagnose_operator_context($fake);

        WP_FTS_Plugin::reset_request_caches();
        $fake->num_queries = 0;
        $operator = WP_FTS_Plugin::operator_status(true);
        assert_same(true, $operator['physical_schema_usable'] ?? null, 'physical operator status should remain usable');
        assert_same(3, $fake->num_queries, 'physical operator status should use two metadata reads and one bounded work-status read');

        WP_FTS_Plugin::reset_request_caches();
        $fake->num_queries = 0;
        $support = WP_FTS_Plugin::support_snapshot();
        assert_same('wp-fts-support-snapshot-v1', $support['schema'] ?? null, 'support snapshot should remain available after consolidated verification');
        assert_same(3, $fake->num_queries, 'a cold support snapshot should use exactly three plugin database statements');

        WP_FTS_Plugin::reset_request_caches();
        $fake->num_queries = 0;
        wp_fts_test_capture_cli(static function (): void {
            (new WP_FTS_WPCLI_Command())->diagnose(['needle'], [
                'lang' => 'en',
                'limit' => '1',
                'format' => 'json',
            ]);
        });
        assert_same(6, $fake->num_queries, 'healthy diagnose plus hydrated search should use exactly six plugin database statements');
    } finally {
        $wpdb = $oldWpdb;
        WP_FTS_Plugin::reset_request_caches();
    }
});

test_case_with_pdo_sqlite_fixture('SQLite physical diagnostics inspect six tables in one portable statement', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    $storage = new WP_FTS_Storage_Mysql($wpdb);
    $wpdb->queries = [];

    $verification = $storage->verify_schema_and_scope_keyset_indexes();
    assert_same(true, $verification['valid'] ?? null, 'the complete SQLite Playground contract should pass the combined verifier');
    $snapshots = array_values(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_starts_with($sql, '/* wp_fts:physical-schema-snapshot */')
    ));
    assert_same(1, count($snapshots), 'SQLite should correlate sqlite_schema and table-valued PRAGMAs in one statement');

    assert_true($wpdb->query('CREATE INDEX wp_fts_unexpected_damage ON wp_fts_postings(impact)') !== false, 'the SQLite fixture should add one unexpected physical index');
    $wpdb->queries = [];
    $damaged = $storage->verify_schema_and_scope_keyset_indexes();
    assert_same(false, $damaged['valid'] ?? null, 'the consolidated SQLite snapshot must still reject an extra physical index');
    assert_true(in_array('wp_fts_postings.<unexpected>(<uninspected>)', $damaged['unexpected_indexes'] ?? [], true), 'SQLite should report one bounded unexpected-index sentinel');
    assert_same(1, count(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_starts_with($sql, '/* wp_fts:physical-schema-snapshot */')
    )), 'damaged SQLite verification should remain one statement');
});
