<?php
declare(strict_types=1);

/**
 * Admin Health support snapshot quality contracts.
 *
 * Direct execution re-enters the shared harness with a focused filter. Normal
 * tests/run.php discovery registers these tests alongside the rest of the suite.
 */

function wp_fts_support_snapshot_contract_direct(): int
{
    if (!function_exists('proc_open')) {
        fwrite(STDOUT, "SKIP: proc_open() is unavailable, so the focused support snapshot contract cannot launch tests/run.php.\n");
        return 0;
    }

    $root = dirname(__DIR__, 2);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $baseEnv = getenv();
    if (!is_array($baseEnv)) {
        $baseEnv = [];
    }

    $command = [PHP_BINARY];
    if (php_ini_loaded_file() === false) {
        $command[] = '-n';
    }
    $command[] = $root . '/tests/run.php';

    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        $root,
        array_merge($baseEnv, [
            'WP_FTS_TEST_FILTER' => 'admin health support snapshot',
            'WP_FTS_MIN_CHECKS' => '0',
        ])
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "FAIL: Could not launch the focused support snapshot contract.\n");
        return 1;
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    if ($stdout !== '') {
        fwrite(STDOUT, $stdout);
    }
    if ($stderr !== '') {
        fwrite(STDERR, $stderr);
    }

    return is_int($exit) ? $exit : 1;
}

if (!function_exists('test_case')) {
    exit(wp_fts_support_snapshot_contract_direct());
}

function wp_fts_support_snapshot_seed_state(WP_FTS_Test_WPDB $fake): void
{
    global $wpdb;

    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_bloginfo']['version'] = '6.9-test';
    $GLOBALS['wp_fts_test_bloginfo']['language'] = 'en-US';
    $fake->postRows = [
        wp_fts_test_backfill_post(901, 'post', 'publish', 'Support Snapshot Indexed'),
        wp_fts_test_backfill_post(902, 'post', 'publish', 'Support Snapshot Queued'),
    ];
    $fake->docs[901] = [
        'lang' => 'en',
        'doc_len' => 4,
        'content_hash' => 'support-snapshot-hash',
        'is_deleted' => 0,
    ];
    $GLOBALS['wp_fts_test_posts'][902] = (object) [
        'ID' => 902,
        'post_title' => "Queued Support SELECT * FROM wp_users\n#0 stack trace",
        'post_content' => 'Queued support hidden content should not be indexed.',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-22 00:00:00',
    ];
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = array_replace(
        WP_FTS_Plugin::default_settings(),
        [
            'replace_frontend_search' => false,
            'replace_admin_post_search' => true,
            'search_provider_compatibility' => 'respect_existing',
        ]
    );
    $GLOBALS['wp_fts_test_options']['active_plugins'] = [
        'searchwp/index.php',
        'private-search-provider/secret-basename.php',
    ];
    $GLOBALS['wp_fts_test_options']['jetpack_active_modules'] = [
        'search',
        'raw-provider-payload-must-not-render',
    ];
    wp_fts_test_seed_queue($fake, [902]);
    $GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK] = [
        'timestamp' => time() + 120,
        'hook' => WP_FTS_Plugin::CRON_HOOK,
    ];
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION] = [
        'token' => 'do-not-expose-support-token',
        'mode' => 'manual',
        'started_at' => time() - 10,
        'expires_at' => time() + 290,
    ];
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] = [
        'last_batch_processed' => 3,
        'last_batch_queue_processed' => 1,
        'last_batch_backfill_processed' => 2,
        'last_batch_stale_processed' => 0,
        'has_more' => true,
        'last_indexed_post_id' => 901,
        'last_indexed_post_title' => 'Support Snapshot Indexed',
        'last_indexed_at' => '2026-06-22 10:00:00',
        'last_batch_failures' => 1,
        'last_failed_post_id' => 902,
        'last_failed_post_title' => '<b>Support Snapshot Failed</b>',
        'last_failed_at' => '2026-06-22 10:01:00',
        'last_error' => "RuntimeException: Failed to index SELECT * FROM wp_users WHERE token='secret'\n#0 stack trace",
        'last_skipped_locked' => false,
        'last_stopped_by_budget' => true,
        'last_mode' => 'manual',
        'last_run_at' => '2026-06-22 10:01:00',
        'latest_batch_diagnostics' => [
            'schema' => 'wp-fts-index-batch-diagnostics-v1',
            'trigger' => 'manual',
            'source' => 'admin-health',
            'status' => 'partial_failure',
            'started_at' => '2026-06-22 10:00:00',
            'finished_at' => '2026-06-22 10:01:00',
            'elapsed_ms' => 17.25,
            'batch_limit' => 5,
            'processed' => 3,
            'queue_processed' => 1,
            'backfill_processed' => 2,
            'queue_before' => 2,
            'queue_after' => 1,
            'failures' => 1,
            'error_class' => 'RuntimeException',
            'error_message' => "Failed to index SELECT * FROM wp_users WHERE token='secret'\n#0 stack trace",
            'last_failed_post_id' => 902,
            'last_failed_post_title' => '<b>Support Snapshot Failed</b>',
            'last_failed_at' => '2026-06-22 10:01:00',
            'reschedule_decision' => 'not_applicable_manual',
            'stop_reason' => 'batch_cap',
            'lock_at_start' => ['state' => 'active', 'active' => true, 'mode' => 'manual', 'token' => 'do-not-expose-start-token'],
            'lock_at_end' => ['state' => 'active', 'active' => true, 'mode' => 'manual', 'token' => 'do-not-expose-end-token'],
            'schema_status' => 'current',
            'schema_version' => WP_FTS_Plugin::SCHEMA_VERSION,
            'expected_schema_version' => WP_FTS_Plugin::SCHEMA_VERSION,
            'storage_backend' => 'mysql',
        ],
    ];

    $trace = [
        'context' => 'support snapshot request',
        'status' => 'ran',
        'search_text' => 'Bearer abc123 do-not-expose-request-token',
        'settings' => [
            'api_key' => 'sk_live_supportsecret',
            'path' => '/home/claude/indexer/indexer.php',
            'plugin' => 'searchwp/index.php',
            'secret_value' => 'super-secret-support-value',
        ],
        'sql_queries' => [
            "SELECT * FROM wp_users WHERE user_pass='secret'",
        ],
    ];
    $property = new ReflectionProperty(WP_FTS_Plugin::class, 'debug_traces');
    $property->setAccessible(true);
    $property->setValue(null, [$trace]);
}

function wp_fts_support_snapshot_decode(string $json): array
{
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    return is_array($payload) ? $payload : [];
}

function wp_fts_support_snapshot_textarea_json(string $html): string
{
    if (preg_match('/<textarea[^>]+id="wp-fts-support-snapshot-json"[^>]*>(.*?)<\/textarea>/s', $html, $matches) !== 1) {
        return '';
    }

    return html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * @param string[] $forbidden
 */
function wp_fts_support_snapshot_assert_redacted(string $json, array $forbidden, string $context): void
{
    foreach ($forbidden as $needle) {
        assert_true(!str_contains($json, $needle), "{$context} should not expose {$needle}");
    }
}

test_case('admin health support snapshot schema is bounded redacted and read-only', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    try {
        wp_fts_support_snapshot_seed_state($fake);
        $optionsBefore = $GLOBALS['wp_fts_test_options'];
        $scheduledBefore = $GLOBALS['wp_fts_test_scheduled'];
        $docsBefore = $fake->docs;
        $termsBefore = $fake->terms;

        $json = WP_FTS_Plugin::support_snapshot_json();
        $payload = wp_fts_support_snapshot_decode($json);

        assert_true(strlen($json) <= 32768, 'admin health support snapshot JSON should stay within the bounded size contract');
        assert_same('wp-fts-support-snapshot-v1', $payload['schema'] ?? null, 'admin health support snapshot should expose a stable schema id');
        assert_true(is_array($payload['context'] ?? null), 'admin health support snapshot should include plugin and runtime context');
        assert_same('Language FTS', $payload['context']['plugin']['name'] ?? null, 'admin health support snapshot should include plugin name without shelling out');
        assert_same('0.1.9', $payload['context']['plugin']['version'] ?? null, 'admin health support snapshot should include plugin version without shelling out');
        assert_same('6.9-test', $payload['context']['runtime']['wordpress_version'] ?? null, 'admin health support snapshot should include WordPress version when available');
        assert_true(is_array($payload['operator_status'] ?? null), 'admin health support snapshot should include operator status');
        assert_true(is_array($payload['provider_compatibility'] ?? null), 'admin health support snapshot should include provider compatibility');
        assert_true(is_array($payload['language_pack_status'] ?? null), 'admin health support snapshot should include language pack status');
        assert_true(is_array($payload['queue_cron_indexing'] ?? null), 'admin health support snapshot should include queue cron indexing context');
        assert_true(is_array($payload['latest_batch_diagnostics'] ?? null), 'admin health support snapshot should include latest batch diagnostics');
        assert_true(is_array($payload['current_request_diagnostics'] ?? null), 'admin health support snapshot should include current request diagnostics when present');
        assert_true(is_array($payload['advice'] ?? null) && $payload['advice'] !== [], 'admin health support snapshot should include concise next-action advice');
        assert_same(true, $payload['boundaries']['read_only'] ?? null, 'admin health support snapshot should declare the read-only boundary');
        assert_same('not_run', $payload['boundaries']['proof_or_certification'] ?? null, 'admin health support snapshot should not claim host certification');
        assert_same('scheduled', $payload['queue_cron_indexing']['queue_processor_schedule']['status'] ?? null, 'admin health support snapshot should reuse queue schedule diagnostics');
        assert_same('partial_failure', $payload['latest_batch_diagnostics']['status'] ?? null, 'admin health support snapshot should reuse latest batch diagnostics');
        assert_contains('SELECT statement', $json, 'admin health support snapshot should retain redacted SQL context');
        wp_fts_support_snapshot_assert_redacted($json, [
            '/home/claude',
            'indexer/indexer.php',
            'searchwp/index.php',
            'secret-basename.php',
            'SELECT * FROM',
            'wp_users',
            '#0',
            'do-not-expose',
            'sk_live_supportsecret',
            'super-secret-support-value',
            'raw-provider-payload-must-not-render',
            'Bearer abc123',
        ], 'admin health support snapshot');
        assert_same($optionsBefore, $GLOBALS['wp_fts_test_options'], 'admin health support snapshot should not mutate options, queue state, health state, or lock state');
        assert_same($scheduledBefore, $GLOBALS['wp_fts_test_scheduled'], 'admin health support snapshot should not schedule or clear cron events');
        assert_same([], $GLOBALS['wp_fts_test_schedule_calls'], 'admin health support snapshot should not call the scheduler');
        assert_same([], $GLOBALS['wp_fts_test_cleared_hooks'], 'admin health support snapshot should not clear scheduled hooks');
        assert_same([], $GLOBALS['wp_fts_test_added_options'], 'admin health support snapshot should not add options');
        assert_same([], $GLOBALS['wp_fts_test_updated_options'], 'admin health support snapshot should not update options');
        assert_same([], $GLOBALS['wp_fts_test_deleted_options'], 'admin health support snapshot should not delete options');
        assert_same($docsBefore, $fake->docs, 'admin health support snapshot should not index content');
        assert_same($termsBefore, $fake->terms, 'admin health support snapshot should not write terms');
        assert_same(0, count(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE'))), 'admin health support snapshot should not repair schema');
    } finally {
        $wpdb = $oldWpdb;
        wp_fts_test_reset_wordpress_fakes();
    }
});

test_case('admin health support snapshot POST requires capability and nonce before rendering JSON', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();

    try {
        wp_fts_support_snapshot_seed_state($fake);
        $docsBefore = $fake->docs;
        $termsBefore = $fake->terms;

        $_GET = ['page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG];
        $_POST = [
            'wp_fts_health_action' => 'support_snapshot',
            'wp_fts_health_nonce' => wp_create_nonce('wp_fts_health_admin_action'),
        ];
        $unauthorizedHtml = wp_fts_test_capture_admin_settings_tab(null);
        assert_contains('You do not have permission to manage Full-Text Search settings.', $unauthorizedHtml, 'unauthorized support snapshot POST should stop at the page capability gate');
        assert_true(!str_contains($unauthorizedHtml, 'wp-fts-support-snapshot-json'), 'unauthorized support snapshot POST should not render JSON');

        $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
        $_POST = [
            'wp_fts_health_action' => 'support_snapshot',
            'wp_fts_health_nonce' => 'not-a-valid-nonce',
        ];
        $invalidNonceHtml = wp_fts_test_capture_admin_settings_tab(null);
        assert_contains('The support snapshot action could not be verified', $invalidNonceHtml, 'invalid support snapshot nonce should show an error');
        assert_true(!str_contains($invalidNonceHtml, 'wp-fts-support-snapshot-json'), 'invalid support snapshot nonce should not render JSON');

        assert_same([], $GLOBALS['wp_fts_test_schedule_calls'], 'rejected support snapshot POSTs should not schedule cron');
        assert_same([], $GLOBALS['wp_fts_test_added_options'], 'rejected support snapshot POSTs should not add options');
        assert_same([], $GLOBALS['wp_fts_test_updated_options'], 'rejected support snapshot POSTs should not update options');
        assert_same([], $GLOBALS['wp_fts_test_deleted_options'], 'rejected support snapshot POSTs should not delete options');
        assert_same($docsBefore, $fake->docs, 'rejected support snapshot POSTs should not index content');
        assert_same($termsBefore, $fake->terms, 'rejected support snapshot POSTs should not write terms');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
        wp_fts_test_reset_wordpress_fakes();
    }
});

test_case('admin health support snapshot POST exposes copyable JSON without mutating state', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();

    try {
        wp_fts_support_snapshot_seed_state($fake);
        $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
        $optionsBefore = $GLOBALS['wp_fts_test_options'];
        $scheduledBefore = $GLOBALS['wp_fts_test_scheduled'];
        $docsBefore = $fake->docs;
        $termsBefore = $fake->terms;

        $_GET = ['page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG];
        $_POST = [
            'wp_fts_health_action' => 'support_snapshot',
            'wp_fts_health_nonce' => wp_create_nonce('wp_fts_health_admin_action'),
        ];
        $html = wp_fts_test_capture_admin_settings_tab(null);

        assert_contains('<h3>Support snapshot</h3>', $html, 'Health UI should expose the support snapshot section');
        assert_contains('Generate support snapshot', $html, 'Health UI should expose a support snapshot affordance');
        assert_contains('wp-fts-support-snapshot-json', $html, 'valid support snapshot POST should render a readonly textarea');
        assert_contains('readonly="readonly"', $html, 'support snapshot textarea should be readonly');
        assert_contains('wp-fts-support-snapshot-v1', $html, 'support snapshot textarea should contain the JSON schema id');
        assert_contains('No indexing, schema repair, queue scheduling, searches, or provider API calls were run.', $html, 'support snapshot success notice should state the read-only boundary');
        $snapshotJson = wp_fts_support_snapshot_textarea_json($html);
        assert_true($snapshotJson !== '', 'valid support snapshot POST should put JSON in the textarea');
        wp_fts_support_snapshot_decode($snapshotJson);
        wp_fts_support_snapshot_assert_redacted($snapshotJson, [
            'searchwp/index.php',
            'SELECT * FROM',
            'do-not-expose',
            '/home/claude',
            'sk_live_supportsecret',
            'super-secret-support-value',
            'Bearer abc123',
        ], 'support snapshot textarea');
        assert_same($optionsBefore, $GLOBALS['wp_fts_test_options'], 'valid support snapshot POST should not mutate plugin options');
        assert_same($scheduledBefore, $GLOBALS['wp_fts_test_scheduled'], 'valid support snapshot POST should not mutate cron schedule state');
        assert_same([], $GLOBALS['wp_fts_test_schedule_calls'], 'valid support snapshot POST should not call the scheduler');
        assert_same([], $GLOBALS['wp_fts_test_cleared_hooks'], 'valid support snapshot POST should not clear scheduled hooks');
        assert_same($docsBefore, $fake->docs, 'valid support snapshot POST should not index content');
        assert_same($termsBefore, $fake->terms, 'valid support snapshot POST should not write terms');
        assert_same(0, count(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE'))), 'valid support snapshot POST should not repair schema');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
        wp_fts_test_reset_wordpress_fakes();
    }
});
