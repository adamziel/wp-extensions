<?php
declare(strict_types=1);

/** @return array{WP_FTS_Test_WPDB,array<string,mixed>} */
function wp_fts_scope_index_lifecycle_fixture(bool $removeIndexes = false): array
{
    global $wpdb;

    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_mark_search_takeover_ready();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = 7;
    unset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCOPE_INDEX_OWNERSHIP_OPTION]);
    if ($removeIndexes) {
        unset(
            $fake->schemaIndexes['wp_term_relationships'][WP_FTS_Storage_Mysql::TARGETED_SCOPE_INDEX_NAME],
            $fake->schemaIndexes['wp_posts'][WP_FTS_Storage_Mysql::FILTERED_SCOPE_INDEX_NAME]
        );
    }

    return [$fake, $GLOBALS['wp_fts_test_options']];
}

/** @return string[] */
function wp_fts_scope_index_create_queries(WP_FTS_Test_WPDB $fake): array
{
    return array_values(array_filter(
        $fake->queries,
        static fn(mixed $sql): bool => is_string($sql)
            && str_starts_with($sql, 'CREATE INDEX `wp_fts_')
            && (str_contains($sql, '`wp_posts`') || str_contains($sql, '`wp_term_relationships`'))
    ));
}

test_case('quality schema migration reuses exact preexisting scope indexes without claiming them', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    [$fake] = wp_fts_scope_index_lifecycle_fixture();
    $fake->queries = [];
    try {
        WP_FTS_Plugin::upgrade_schema();
        assert_same(WP_FTS_Plugin::SCHEMA_VERSION, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null, 'an exact preexisting capability should allow current schema publication');
        assert_same([], wp_fts_scope_index_create_queries($fake), 'exact namespaced indexes should be reused without duplicate DDL');
        assert_same(0, count(array_filter(
            $fake->prepared,
            static fn(array $entry): bool => str_starts_with((string) ($entry['sql'] ?? ''), 'SHOW TABLES LIKE %s')
        )), 'a physically valid v7 generation must not run the pre-v4 table-by-table discovery pass');
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCOPE_INDEX_OWNERSHIP_OPTION]), 'a preexisting exact index must not become plugin-owned merely because it was reused');

        $fake->queries = [];
        WP_FTS_Plugin::uninstall();
        assert_true(isset($fake->schemaIndexes['wp_term_relationships'][WP_FTS_Storage_Mysql::TARGETED_SCOPE_INDEX_NAME]), 'uninstall must retain an unowned preexisting relationship index');
        assert_true(isset($fake->schemaIndexes['wp_posts'][WP_FTS_Storage_Mysql::FILTERED_SCOPE_INDEX_NAME]), 'uninstall must retain an unowned preexisting posts index');
        assert_same(0, count(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'DROP INDEX '))), 'uninstall must issue no DROP INDEX for reused unowned capabilities');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality schema migration records ownership before DDL and exact uninstall removes both created indexes', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    [$fake] = wp_fts_scope_index_lifecycle_fixture(true);
    $fake->queries = [];
    try {
        WP_FTS_Plugin::upgrade_schema();
        assert_same(WP_FTS_Plugin::SCHEMA_VERSION, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null, 'successful supporting DDL should publish the current version last');
        assert_same(['filtered', 'targeted'], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCOPE_INDEX_OWNERSHIP_OPTION] ?? null, 'ownership intent must cover the exact two missing indexes');
        assert_same(2, count(wp_fts_scope_index_create_queries($fake)), 'a fresh scope capability should use exactly two core-table CREATE INDEX statements');
        assert_same(['term_taxonomy_id', 'object_id'], $fake->schemaIndexes['wp_term_relationships'][WP_FTS_Storage_Mysql::TARGETED_SCOPE_INDEX_NAME] ?? null, 'targeted DDL must install the exact ordered columns');
        assert_same(['post_type', 'post_status', 'ID'], $fake->schemaIndexes['wp_posts'][WP_FTS_Storage_Mysql::FILTERED_SCOPE_INDEX_NAME] ?? null, 'filtered DDL must install the exact ordered columns');

        $fake->queries = [];
        WP_FTS_Plugin::uninstall();
        assert_true(!isset($fake->schemaIndexes['wp_term_relationships'][WP_FTS_Storage_Mysql::TARGETED_SCOPE_INDEX_NAME]), 'uninstall must remove the exact plugin-owned relationship index');
        assert_true(!isset($fake->schemaIndexes['wp_posts'][WP_FTS_Storage_Mysql::FILTERED_SCOPE_INDEX_NAME]), 'uninstall must remove the exact plugin-owned posts index');
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCOPE_INDEX_OWNERSHIP_OPTION]), 'uninstall must delete the ownership record after dropping its exact indexes');
        assert_same(2, count(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'DROP INDEX '))), 'uninstall must issue exactly two owned DROP INDEX statements');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality schema migration rejects a same-name index collision before ownership or DDL', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    [$fake] = wp_fts_scope_index_lifecycle_fixture();
    $fake->schemaIndexes['wp_term_relationships'][WP_FTS_Storage_Mysql::TARGETED_SCOPE_INDEX_NAME] = ['object_id', 'term_taxonomy_id'];
    $fake->queries = [];
    try {
        $failure = null;
        try {
            WP_FTS_Plugin::upgrade_schema();
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        assert_true($failure instanceof RuntimeException && str_contains($failure->getMessage(), 'conflicts'), 'a same-name/different-order index must fail closed with a bounded conflict');
        assert_same(7, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null, 'a collision must not advance the stored schema version');
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCOPE_INDEX_OWNERSHIP_OPTION]), 'a preexisting collision must never be claimed as plugin-owned');
        assert_same([], wp_fts_scope_index_create_queries($fake), 'collision detection must happen before either supporting CREATE INDEX');

        $fake->prepared = [];
        $runtimeFailure = null;
        try {
            (new WP_FTS_Storage_Mysql($fake))->validated_targeted_scope_index_hint();
        } catch (RuntimeException $error) {
            $runtimeFailure = $error;
        }
        assert_true($runtimeFailure instanceof RuntimeException && str_contains($runtimeFailure->getMessage(), 'conflicts'), 'the runtime scope boundary must reject the same wrong-order definition');
        $runtimeChecks = array_values(array_filter(
            $fake->prepared,
            static fn(array $entry): bool => str_starts_with((string) ($entry['sql'] ?? ''), 'SHOW INDEX FROM `wp_term_relationships` WHERE Key_name = %s')
        ));
        assert_same(1, count($runtimeChecks), 'MySQL/MariaDB scope work should verify its one named keyset with one narrow metadata statement');
        assert_same([WP_FTS_Storage_Mysql::TARGETED_SCOPE_INDEX_NAME], $runtimeChecks[0]['args'] ?? null, 'the runtime metadata probe must bind only the exact plugin index name');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality schema migration resumes an interrupted two-index install without duplicate DDL', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    [$fake] = wp_fts_scope_index_lifecycle_fixture(true);
    $fake->failQueryNeedle = 'CREATE INDEX `wp_fts_';
    $fake->failQueryNeedleOccurrence = 2;
    try {
        $failure = null;
        try {
            WP_FTS_Plugin::upgrade_schema();
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        assert_true($failure instanceof RuntimeException, 'the fixture must interrupt the second supporting CREATE INDEX');
        assert_same(7, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null, 'partial supporting DDL must leave the old logical version');
        assert_same(['filtered', 'targeted'], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCOPE_INDEX_OWNERSHIP_OPTION] ?? null, 'both ownership intents must be durable before the first DDL');
        assert_true(isset($fake->schemaIndexes['wp_term_relationships'][WP_FTS_Storage_Mysql::TARGETED_SCOPE_INDEX_NAME]), 'the completed first index should remain available for idempotent resume');
        assert_true(!isset($fake->schemaIndexes['wp_posts'][WP_FTS_Storage_Mysql::FILTERED_SCOPE_INDEX_NAME]), 'the failed second index must remain absent');

        $fake->failQueryNeedle = null;
        $fake->failQueryNeedleOccurrence = 0;
        $fake->failQueryNeedleMatches = 0;
        $fake->queries = [];
        WP_FTS_Plugin::upgrade_schema();
        assert_same(WP_FTS_Plugin::SCHEMA_VERSION, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null, 'a resumed install should publish the current schema after both definitions verify');
        assert_same(1, count(wp_fts_scope_index_create_queries($fake)), 'resume must create only the still-missing posts index');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality schema migration stops after first DDL when its writer lease is stolen', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    [$fake] = wp_fts_scope_index_lifecycle_fixture(true);
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
            WP_FTS_Plugin::upgrade_schema();
        } catch (WP_FTS_Index_Writer_Ownership_Lost $error) {
            $failure = $error;
        }
        assert_true($failure instanceof WP_FTS_Index_Writer_Ownership_Lost, 'a stale upgrader must observe the stolen lease immediately after long DDL');
        assert_same(1, $creates, 'lease loss after first CREATE must prevent the second core-table DDL');
        assert_same(7, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null, 'a stale upgrader must not advance the stored schema version');
        assert_true(isset($fake->schemaIndexes['wp_term_relationships'][WP_FTS_Storage_Mysql::TARGETED_SCOPE_INDEX_NAME]), 'the first completed DDL remains owned and recoverable');
        assert_true(!isset($fake->schemaIndexes['wp_posts'][WP_FTS_Storage_Mysql::FILTERED_SCOPE_INDEX_NAME]), 'no second DDL may cross the stolen lease');
    } finally {
        $fake->queryObserver = null;
        $wpdb = $oldWpdb;
    }
});

test_case('quality SQLite scope index names are complete, nonpartial, and unique per multisite table', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    foreach (['wp_posts', 'wp_2_posts'] as $table) {
        $wpdb->query("CREATE TABLE {$table} (ID INTEGER PRIMARY KEY, post_type TEXT NOT NULL, post_status TEXT NOT NULL)");
    }
    foreach (['wp_term_relationships', 'wp_2_term_relationships'] as $table) {
        $wpdb->query("CREATE TABLE {$table} (object_id INTEGER NOT NULL, term_taxonomy_id INTEGER NOT NULL, PRIMARY KEY(object_id,term_taxonomy_id))");
    }
    $main = new WP_FTS_Storage_Mysql($wpdb, 'wp_');
    $site = new WP_FTS_Storage_Mysql($wpdb, 'wp_2_');
    $main->ensure_scope_keyset_indexes();
    $site->ensure_scope_keyset_indexes();
    assert_same(true, $main->verify_scope_keyset_indexes()['valid'] ?? null, 'main-site SQLite supporting indexes should verify exactly');
    assert_same(true, $site->verify_scope_keyset_indexes()['valid'] ?? null, 'subsite SQLite supporting indexes should verify exactly');

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

    $targetHint = $main->targeted_scope_index_hint();
    preg_match('/`([^`]+)`/', $targetHint, $match);
    $targetName = (string) ($match[1] ?? '');
    $wpdb->query("DROP INDEX `{$targetName}`");
    $wpdb->query("CREATE INDEX `{$targetName}` ON wp_term_relationships(term_taxonomy_id,object_id) WHERE object_id > 10");
    $verification = $main->verify_scope_keyset_indexes();
    assert_same(false, $verification['valid'] ?? null, 'a same-column partial SQLite index must fail the complete keyset contract');
    assert_contains('conflicts', (string) ($verification['error'] ?? ''), 'partial-index rejection should identify a definition collision');
});
