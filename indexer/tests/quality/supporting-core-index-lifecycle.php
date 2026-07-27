<?php
declare(strict_types=1);

/** @return array{WP_FTS_Test_WPDB,array<string,mixed>} */
function wp_fts_supporting_core_index_lifecycle_fixture(bool $removeIndexes = false): array
{
    global $wpdb;

    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_mark_search_takeover_ready();
    if ($removeIndexes) {
        unset(
            $fake->schemaIndexes['wp_term_relationships'][WP_FTS_Relational_Storage::TARGETED_SCOPE_INDEX_NAME],
            $fake->schemaIndexes['wp_posts'][WP_FTS_Relational_Storage::FILTERED_SCOPE_INDEX_NAME],
            $fake->schemaIndexes['wp_posts'][WP_FTS_Relational_Storage::VISIBILITY_INDEX_NAME]
        );
    }

    return [$fake, $GLOBALS['wp_fts_test_options']];
}

/** @return string[] */
function wp_fts_supporting_core_index_create_queries(WP_FTS_Test_WPDB $fake): array
{
    return array_values(array_filter(
        $fake->queries,
        static fn(mixed $sql): bool => is_string($sql)
            && str_starts_with($sql, 'CREATE INDEX `wp_fts_')
            && (str_contains($sql, '`wp_posts`') || str_contains($sql, '`wp_term_relationships`'))
    ));
}

test_case('quality schema repair reuses exact supporting core indexes and uninstall removes them', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    [$fake] = wp_fts_supporting_core_index_lifecycle_fixture();
    $fake->queries = [];
    try {
        WP_FTS_Plugin::create_or_repair_schema();
        assert_same([], wp_fts_supporting_core_index_create_queries($fake), 'exact namespaced indexes should be reused without duplicate DDL');

        $fake->queries = [];
        WP_FTS_Plugin::uninstall();
        assert_true(!isset($fake->schemaIndexes['wp_term_relationships'][WP_FTS_Relational_Storage::TARGETED_SCOPE_INDEX_NAME]), 'uninstall must remove the exact namespaced relationship index');
        assert_true(!isset($fake->schemaIndexes['wp_posts'][WP_FTS_Relational_Storage::FILTERED_SCOPE_INDEX_NAME]), 'uninstall must remove the exact namespaced posts index');
        assert_true(!isset($fake->schemaIndexes['wp_posts'][WP_FTS_Relational_Storage::VISIBILITY_INDEX_NAME]), 'uninstall must remove the exact namespaced visibility index');
        assert_same(3, count(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'DROP INDEX '))), 'uninstall must issue exactly three exact-definition DROP INDEX statements');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality schema creation installs exact supporting core indexes and uninstall removes them', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    [$fake] = wp_fts_supporting_core_index_lifecycle_fixture(true);
    $fake->queries = [];
    try {
        WP_FTS_Plugin::create_or_repair_schema();
        assert_same(3, count(wp_fts_supporting_core_index_create_queries($fake)), 'a fresh installation should use exactly three core-table CREATE INDEX statements');
        assert_same(['term_taxonomy_id', 'object_id'], $fake->schemaIndexes['wp_term_relationships'][WP_FTS_Relational_Storage::TARGETED_SCOPE_INDEX_NAME] ?? null, 'targeted DDL must install the exact ordered columns');
        assert_same(['post_type', 'post_status', 'ID'], $fake->schemaIndexes['wp_posts'][WP_FTS_Relational_Storage::FILTERED_SCOPE_INDEX_NAME] ?? null, 'filtered DDL must install the exact ordered columns');
        assert_same(['ID', 'post_type', 'post_status', 'post_password', 'post_date_gmt'], $fake->schemaIndexes['wp_posts'][WP_FTS_Relational_Storage::VISIBILITY_INDEX_NAME] ?? null, 'visibility DDL must install the exact covering columns');

        $fake->queries = [];
        WP_FTS_Plugin::uninstall();
        assert_true(!isset($fake->schemaIndexes['wp_term_relationships'][WP_FTS_Relational_Storage::TARGETED_SCOPE_INDEX_NAME]), 'uninstall must remove the exact plugin relationship index');
        assert_true(!isset($fake->schemaIndexes['wp_posts'][WP_FTS_Relational_Storage::FILTERED_SCOPE_INDEX_NAME]), 'uninstall must remove the exact plugin posts index');
        assert_true(!isset($fake->schemaIndexes['wp_posts'][WP_FTS_Relational_Storage::VISIBILITY_INDEX_NAME]), 'uninstall must remove the exact plugin visibility index');
        assert_same(3, count(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'DROP INDEX '))), 'uninstall must issue exactly three DROP INDEX statements');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality uninstall stops before destructive cleanup when supporting-index metadata is unavailable', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    [$fake] = wp_fts_supporting_core_index_lifecycle_fixture();
    $fake->queries = [];
    $fake->failReadQueryPrefix = '/* wp_fts:physical-schema-snapshot */';
    try {
        $failure = null;
        try {
            WP_FTS_Plugin::uninstall();
        } catch (RuntimeException $error) {
            $failure = $error;
        }

        assert_true(
            $failure instanceof RuntimeException && str_contains($failure->getMessage(), 'exact FTS core-index cleanup'),
            'uninstall must report an unavailable physical ownership check'
        );
        assert_same(
            0,
            count(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'DROP INDEX ') || str_starts_with($sql, 'DROP TABLE '))),
            'uninstall must not destroy indexes or tables when it cannot distinguish exact plugin definitions'
        );
        assert_true(isset($fake->schemaIndexes['wp_term_relationships'][WP_FTS_Relational_Storage::TARGETED_SCOPE_INDEX_NAME]), 'the exact relationship index must remain for an uninstall retry');
        assert_true(isset($fake->schemaIndexes['wp_posts'][WP_FTS_Relational_Storage::FILTERED_SCOPE_INDEX_NAME]), 'the exact posts index must remain for an uninstall retry');
        assert_true(isset($fake->schemaIndexes['wp_posts'][WP_FTS_Relational_Storage::VISIBILITY_INDEX_NAME]), 'the exact visibility index must remain for an uninstall retry');
        assert_true(isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::UNINSTALL_FENCE_OPTION]), 'the uninstall fence must remain published after bounded cleanup stops');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality schema repair rejects a same-name supporting-index collision and uninstall leaves it untouched', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    [$fake] = wp_fts_supporting_core_index_lifecycle_fixture();
    $fake->schemaIndexes['wp_term_relationships'][WP_FTS_Relational_Storage::TARGETED_SCOPE_INDEX_NAME] = ['object_id', 'term_taxonomy_id'];
    $fake->queries = [];
    try {
        $failure = null;
        try {
            WP_FTS_Plugin::create_or_repair_schema();
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        assert_true($failure instanceof RuntimeException && str_contains($failure->getMessage(), 'conflicts'), 'a same-name/different-order index must fail closed with a bounded conflict');
        assert_same([], wp_fts_supporting_core_index_create_queries($fake), 'collision detection must happen before any supporting CREATE INDEX');

        $fake->prepared = [];
        $runtimeFailure = null;
        try {
            (new WP_FTS_Relational_Storage($fake))->validated_targeted_scope_index_hint();
        } catch (RuntimeException $error) {
            $runtimeFailure = $error;
        }
        assert_true($runtimeFailure instanceof RuntimeException && str_contains($runtimeFailure->getMessage(), 'conflicts'), 'the runtime scope boundary must reject the same wrong-order definition');
        $runtimeChecks = array_values(array_filter(
            $fake->prepared,
            static fn(array $entry): bool => str_starts_with((string) ($entry['sql'] ?? ''), 'SHOW INDEX FROM `wp_term_relationships` WHERE Key_name = %s')
        ));
        assert_same(1, count($runtimeChecks), 'MySQL/MariaDB scope work should verify its one named keyset with one narrow metadata statement');
        assert_same([WP_FTS_Relational_Storage::TARGETED_SCOPE_INDEX_NAME], $runtimeChecks[0]['args'] ?? null, 'the runtime metadata probe must bind only the exact plugin index name');

        $fake->queries = [];
        WP_FTS_Plugin::uninstall();
        assert_same(['object_id', 'term_taxonomy_id'], $fake->schemaIndexes['wp_term_relationships'][WP_FTS_Relational_Storage::TARGETED_SCOPE_INDEX_NAME] ?? null, 'uninstall must leave a conflicting namespaced definition untouched');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality schema repair preflights every supporting-index collision before DDL', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    [$fake] = wp_fts_supporting_core_index_lifecycle_fixture(true);
    $fake->schemaIndexes['wp_posts'][WP_FTS_Relational_Storage::VISIBILITY_INDEX_NAME] = [
        'post_type',
        'post_status',
        'ID',
    ];
    $fake->queries = [];
    try {
        $failure = null;
        try {
            WP_FTS_Plugin::create_or_repair_schema();
        } catch (RuntimeException $error) {
            $failure = $error;
        }

        assert_true(
            $failure instanceof RuntimeException && str_contains($failure->getMessage(), 'conflicts'),
            'a collision in the final supporting contract must fail closed'
        );
        assert_same(
            [],
            wp_fts_supporting_core_index_create_queries($fake),
            'preflight must detect a later collision before creating any earlier missing index'
        );
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality schema creation resumes an interrupted three-index install without duplicate DDL', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    [$fake] = wp_fts_supporting_core_index_lifecycle_fixture(true);
    $fake->failQueryNeedle = 'CREATE INDEX `wp_fts_';
    $fake->failQueryNeedleOccurrence = 3;
    try {
        $failure = null;
        try {
            WP_FTS_Plugin::create_or_repair_schema();
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        assert_true($failure instanceof RuntimeException, 'the fixture must interrupt the third supporting CREATE INDEX');
        assert_true(isset($fake->schemaIndexes['wp_term_relationships'][WP_FTS_Relational_Storage::TARGETED_SCOPE_INDEX_NAME]), 'the completed first index should remain available for idempotent resume');
        assert_true(isset($fake->schemaIndexes['wp_posts'][WP_FTS_Relational_Storage::FILTERED_SCOPE_INDEX_NAME]), 'the completed second index should remain available for idempotent resume');
        assert_true(!isset($fake->schemaIndexes['wp_posts'][WP_FTS_Relational_Storage::VISIBILITY_INDEX_NAME]), 'the failed third index must remain absent');

        $fake->failQueryNeedle = null;
        $fake->failQueryNeedleOccurrence = 0;
        $fake->failQueryNeedleMatches = 0;
        $fake->queries = [];
        WP_FTS_Plugin::create_or_repair_schema();
        assert_same(1, count(wp_fts_supporting_core_index_create_queries($fake)), 'resume must create only the still-missing visibility index');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality schema creation stops after first DDL when its writer lease is stolen', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    [$fake] = wp_fts_supporting_core_index_lifecycle_fixture(true);
    $creates = 0;
    $fake->queryObserver = static function (string $sql) use (&$creates): void {
        if (!str_starts_with($sql, 'CREATE INDEX `wp_fts_') || ++$creates !== 1) {
            return;
        }
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION] = [
            'token' => 'successor-writer-token',
            'mode' => 'uninstall',
            'started_at' => time(),
            'heartbeat_at' => time(),
            'expires_at' => time() + 300,
            'renewals' => 0,
        ];
    };
    try {
        $failure = null;
        try {
            WP_FTS_Plugin::create_or_repair_schema();
        } catch (WP_FTS_Index_Writer_Ownership_Lost $error) {
            $failure = $error;
        }
        assert_true($failure instanceof WP_FTS_Index_Writer_Ownership_Lost, 'a stale repair writer must observe the stolen lease immediately after long DDL');
        assert_same(1, $creates, 'lease loss after first CREATE must prevent later core-table DDL');
        assert_true(isset($fake->schemaIndexes['wp_term_relationships'][WP_FTS_Relational_Storage::TARGETED_SCOPE_INDEX_NAME]), 'the first completed DDL remains exact and recoverable');
        assert_true(!isset($fake->schemaIndexes['wp_posts'][WP_FTS_Relational_Storage::FILTERED_SCOPE_INDEX_NAME]), 'no second DDL may cross the stolen lease');
        assert_true(!isset($fake->schemaIndexes['wp_posts'][WP_FTS_Relational_Storage::VISIBILITY_INDEX_NAME]), 'no third DDL may cross the stolen lease');
    } finally {
        $fake->queryObserver = null;
        $wpdb = $oldWpdb;
    }
});

test_case_with_pdo_sqlite_fixture('quality SQLite supporting index names are complete, nonpartial, and unique per multisite table', function (): void {
    $wpdb = new WP_FTS_Relational_Regression_SQLite_WPDB();
    foreach (['wp_posts', 'wp_2_posts'] as $table) {
        $wpdb->query("CREATE TABLE {$table} (ID INTEGER PRIMARY KEY, post_type TEXT NOT NULL, post_status TEXT NOT NULL)");
    }
    foreach (['wp_term_relationships', 'wp_2_term_relationships'] as $table) {
        $wpdb->query("CREATE TABLE {$table} (object_id INTEGER NOT NULL, term_taxonomy_id INTEGER NOT NULL, PRIMARY KEY(object_id,term_taxonomy_id))");
    }
    $main = new WP_FTS_Relational_Storage($wpdb, 'wp_');
    $site = new WP_FTS_Relational_Storage($wpdb, 'wp_2_');
    $main->ensure_supporting_core_indexes();
    $site->ensure_supporting_core_indexes();
    assert_same(true, $main->verify_schema_and_supporting_core_indexes()['supporting_core_indexes']['valid'] ?? null, 'main-site SQLite supporting indexes should verify exactly');
    assert_same(true, $site->verify_schema_and_supporting_core_indexes()['supporting_core_indexes']['valid'] ?? null, 'subsite SQLite supporting indexes should verify exactly');

    $names = [];
    foreach (['wp_posts', 'wp_2_posts', 'wp_term_relationships', 'wp_2_term_relationships'] as $table) {
        foreach ($wpdb->dbh->query("PRAGMA index_list(\"{$table}\")")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) ($row['name'] ?? '');
            if (str_starts_with($name, 'wp_fts_')) {
                $names[] = $name;
                assert_same(0, (int) ($row['partial'] ?? 1), "{$table} supporting keyset must be complete, never partial");
            }
        }
    }
    assert_same(4, count(array_unique($names)), 'SQLite database-wide names must not collide across two site prefixes');

    $targetHint = $main->validated_targeted_scope_index_hint();
    preg_match('/`([^`]+)`/', $targetHint, $match);
    $targetName = (string) ($match[1] ?? '');
    $wpdb->query("DROP INDEX `{$targetName}`");
    $wpdb->query("CREATE INDEX `{$targetName}` ON wp_term_relationships(term_taxonomy_id,object_id) WHERE object_id > 10");
    $verification = $main->verify_schema_and_supporting_core_indexes()['supporting_core_indexes'];
    assert_same(false, $verification['valid'] ?? null, 'a same-column partial SQLite index must fail the complete keyset contract');
    assert_contains('conflicts', (string) ($verification['error'] ?? ''), 'partial-index rejection should identify a definition collision');
});
