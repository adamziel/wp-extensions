<?php
declare(strict_types=1);

test_case_with_pdo_sqlite_fixture('current-v9 dropped scope keyset schedules maintenance, repairs, and resumes the scope', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    wp_fts_v4_regression_add_source_post($wpdb, 42, '<p>repaired exact target</p>', '');
    $wpdb->execute(
        'INSERT INTO wp_term_relationships (object_id,term_taxonomy_id) VALUES (?,?)',
        [42, 777]
    );

    $storage = new WP_FTS_Storage_Mysql($wpdb);
    assert_same(true, $storage->verify_schema()['valid'] ?? null, 'the fixture should isolate support-index damage from the FTS tables');
    $hint = $storage->targeted_scope_index_hint();
    preg_match('/INDEXED BY `([^`]+)`/', $hint, $match);
    $targetedIndex = (string) ($match[1] ?? '');
    assert_true($targetedIndex !== '', 'the SQLite repair fixture should resolve its exact targeted keyset name');
    assert_true($wpdb->query("DROP INDEX `{$targetedIndex}`") !== false, 'the fixture should drop the current-v9 targeted keyset');

    wp_fts_test_reset_wordpress_fakes();
    WP_FTS_Plugin::reset_request_caches();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCOPE_INDEX_OWNERSHIP_OPTION] = ['filtered', 'targeted'];
    $queue = new WP_FTS_Index_Queue($wpdb);
    $queue->enqueue_scope(
        'current-v9-dropped-targeted-keyset',
        ['reason' => 'scope-keyset-repair-regression'],
        null,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED,
        'term_taxonomy',
        777
    );
    $wpdb->queries = [];
    $workerFailure = null;

    try {
        wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_manual_index_batch([
            'batch_size' => 100,
            'source' => 'scope-keyset-repair-regression',
        ]));
    } catch (RuntimeException $error) {
        $workerFailure = $error;
    }

    assert_true(
        $workerFailure instanceof RuntimeException
            && str_contains($workerFailure->getMessage(), 'unavailable or conflicts'),
        'a missing forced keyset should fail the scope instead of silently scanning another index'
    );
    $maintenanceSchedules = array_values(array_filter(
        $GLOBALS['wp_fts_test_schedule_calls'],
        static fn(array $call): bool => ($call['hook'] ?? '') === WP_FTS_Plugin::SCHEMA_REPAIR_CRON_HOOK
    ));
    assert_same(1, count($maintenanceSchedules), 'the failed current-v9 scope should schedule exactly one schema-maintenance event');
    assert_same(
        'retry',
        (string) $wpdb->dbh->query("SELECT state FROM wp_fts_work WHERE kind = 'scope'")->fetchColumn(),
        'the exact failed scope generation should remain durably retryable while maintenance runs'
    );
    assert_same(
        0,
        (int) $wpdb->dbh->query("SELECT cursor_post_id FROM wp_fts_work WHERE kind = 'scope'")->fetchColumn(),
        'a failed missing-index read must not advance the durable scope cursor'
    );

    $wpdb->queries = [];
    wp_fts_quality_with_wpdb($wpdb, static function (): void {
        WP_FTS_Plugin::run_scheduled_schema_repair();
    });
    $verification = $storage->verify_scope_keyset_indexes();
    assert_same(true, $verification['valid'] ?? null, 'scheduled maintenance should recreate the missing owned keyset at current v9');
    assert_same(
        WP_FTS_Plugin::SCHEMA_VERSION,
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null,
        'an additive support-index repair should retain the current logical schema version'
    );
    assert_same(
        'ready',
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION]['initial_index_status'] ?? null,
        'repairing an additive scope keyset must not invalidate the published search generation'
    );
    assert_same(
        0,
        (int) $wpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_work WHERE kind = 'scope' AND scope_coverage = 'corpus'")->fetchColumn(),
        'an additive keyset repair should not manufacture a corpus reconciliation'
    );
    $targetedCreates = array_values(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_starts_with($sql, 'CREATE INDEX')
            && str_contains($sql, 'term_relationships')
    ));
    assert_same(1, count($targetedCreates), 'repair should issue one targeted CREATE INDEX and no repeated DDL');

    // The first failure intentionally used queue backoff. Make that same
    // generation due now, as the passage of its bounded retry delay would.
    assert_true($wpdb->query(
        "UPDATE wp_fts_work
SET state = 'ready', available_at = 0
WHERE kind = 'scope'"
    ) !== false, 'the fixture should advance the retained scope to its due retry');
    $wpdb->queries = [];
    $resumed = wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_manual_index_batch([
        'batch_size' => 100,
        'source' => 'scope-keyset-repair-regression',
    ]));

    assert_same(1, $resumed['backfill_scanned'] ?? null, 'the repaired targeted keyset should resume with its one exact row');
    assert_same(1, $resumed['backfill_queued'] ?? null, 'the repaired scope should publish its exact target as direct work');
    assert_same(
        42,
        (int) $wpdb->dbh->query("SELECT cursor_post_id FROM wp_fts_work WHERE kind = 'scope'")->fetchColumn(),
        'the retained scope should advance only after its repaired keyset succeeds'
    );
    assert_same(
        [42],
        array_map('intval', $wpdb->dbh->query("SELECT post_id FROM wp_fts_work WHERE kind = 'post'")->fetchAll(PDO::FETCH_COLUMN)),
        'repair must resume the original scope without replacing or broadening its target set'
    );
});

test_case_with_pdo_sqlite_fixture('current-v9 malformed scope keyset fails before selective SQL and schedules maintenance', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    wp_fts_v4_regression_add_source_post($wpdb, 52, '<p>malformed keyset target</p>', '');
    $wpdb->execute(
        'INSERT INTO wp_term_relationships (object_id,term_taxonomy_id) VALUES (?,?)',
        [52, 888]
    );
    $storage = new WP_FTS_Storage_Mysql($wpdb);
    preg_match('/INDEXED BY `([^`]+)`/', $storage->targeted_scope_index_hint(), $match);
    $targetedIndex = (string) ($match[1] ?? '');
    assert_true($targetedIndex !== '', 'the malformed-keyset fixture should resolve its targeted SQLite index');
    assert_true($wpdb->query("DROP INDEX `{$targetedIndex}`") !== false, 'the fixture should remove the valid targeted definition');
    assert_true($wpdb->query(
        "CREATE INDEX `{$targetedIndex}` ON wp_term_relationships(object_id,term_taxonomy_id)"
    ) !== false, 'the fixture should install the same name with the scan-amplifying column order');

    wp_fts_test_reset_wordpress_fakes();
    WP_FTS_Plugin::reset_request_caches();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCOPE_INDEX_OWNERSHIP_OPTION] = ['filtered', 'targeted'];
    (new WP_FTS_Index_Queue($wpdb))->enqueue_scope(
        'current-v9-malformed-targeted-keyset',
        ['reason' => 'scope-keyset-malformed-regression'],
        null,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED,
        'term_taxonomy',
        888
    );
    $wpdb->queries = [];
    $workerFailure = null;
    try {
        wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_manual_index_batch([
            'batch_size' => 100,
            'source' => 'scope-keyset-malformed-regression',
        ]));
    } catch (RuntimeException $error) {
        $workerFailure = $error;
    }

    assert_true(
        $workerFailure instanceof RuntimeException && str_contains($workerFailure->getMessage(), 'conflicts'),
        'a same-name wrong-order keyset must fail closed before the database can obey its FORCE/INDEXED hint'
    );
    assert_same(0, count(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_starts_with($sql, '/* wp_fts:targeted-scope-page */')
    )), 'malformed physical drift must be rejected before any potentially amplifying targeted selector');
    assert_same(1, count(array_filter(
        $GLOBALS['wp_fts_test_schedule_calls'],
        static fn(array $call): bool => ($call['hook'] ?? '') === WP_FTS_Plugin::SCHEMA_REPAIR_CRON_HOOK
    )), 'the worker outer boundary should schedule one explicit maintenance attempt for malformed drift');
    assert_same(
        0,
        (int) $wpdb->dbh->query("SELECT cursor_post_id FROM wp_fts_work WHERE kind = 'scope'")->fetchColumn(),
        'malformed drift must leave the exact scope cursor unchanged'
    );
});

test_case_with_pdo_sqlite_fixture('current-v9 malformed filtered keyset fails in one narrow SQLite metadata read', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    $storage = new WP_FTS_Storage_Mysql($wpdb);
    preg_match('/INDEXED BY `([^`]+)`/', $storage->filtered_scope_index_hint(), $match);
    $filteredIndex = (string) ($match[1] ?? '');
    assert_true($filteredIndex !== '', 'the malformed-keyset fixture should resolve its filtered SQLite index');
    assert_true($wpdb->query("DROP INDEX `{$filteredIndex}`") !== false, 'the fixture should remove the valid filtered definition');
    assert_true($wpdb->query(
        "CREATE INDEX `{$filteredIndex}` ON wp_posts(ID,post_type,post_status)"
    ) !== false, 'the fixture should install the same name with a raw-ID-leading definition');
    $wpdb->queries = [];

    $failure = null;
    try {
        $storage->validated_filtered_scope_index_hint();
    } catch (RuntimeException $error) {
        $failure = $error;
    }

    assert_true($failure instanceof RuntimeException && str_contains($failure->getMessage(), 'conflicts'), 'a same-name malformed filtered keyset must fail its exact definition check');
    assert_same(1, count(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_contains($sql, "FROM pragma_index_list('wp_posts')")
            && str_contains($sql, 'LEFT JOIN pragma_index_info(il.name)')
    )), 'the SQLite scope boundary should verify one named index in one set-oriented metadata read');
});
