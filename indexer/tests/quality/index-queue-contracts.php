<?php
declare(strict_types=1);

/**
 * Durable generation-aware queue contracts.
 *
 * Direct execution re-enters the shared harness with a focused filter. Normal
 * tests/run.php discovery registers these tests alongside the rest of the suite.
 */

function wp_fts_index_queue_contract_direct(): int
{
    if (!function_exists('proc_open')) {
        fwrite(STDOUT, "SKIP: proc_open() is unavailable, so the focused queue contract cannot launch tests/run.php.\n");
        return 0;
    }

    $root = dirname(__DIR__, 2);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $process = proc_open(
        [PHP_BINARY, $root . '/tests/run.php'],
        $descriptors,
        $pipes,
        $root,
        array_merge($environment, [
            'WP_FTS_TEST_FILTER' => 'generation-aware queue',
            'WP_FTS_MIN_CHECKS' => '0',
        ])
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "FAIL: Could not launch the focused queue contract.\n");
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
    exit(wp_fts_index_queue_contract_direct());
}

test_case('generation-aware queue atomically coalesces duplicate post saves', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($wpdb);

    $queue->enqueue(41, 1000);
    $queue->enqueue(41, 1001);

    assert_same(1, $queue->count(), 'duplicate saves should occupy one durable queue row');
    assert_same(2, $wpdb->queue[41]['generation'] ?? null, 'duplicate saves should advance the queued generation');
    assert_same(1001, $wpdb->queue[41]['available_at'] ?? null, 'the latest save should be available immediately');
    assert_true(
        str_contains($wpdb->prepared[0]['sql'] ?? '', 'ON DUPLICATE KEY UPDATE'),
        'enqueue should use one atomic database upsert'
    );
});

test_case('generation-aware queue acknowledgement cannot erase a newer save', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($wpdb);

    $queue->enqueue(42, 1000);
    $first = $queue->claim(1, 1000, 30)[0] ?? null;
    assert_true(is_array($first), 'the first generation should be claimable');

    $queue->enqueue(42, 1001);
    assert_true($queue->acknowledge($first, 1002), 'the old owner should release a superseded generation');
    assert_same(2, $wpdb->queue[42]['generation'] ?? null, 'acknowledging generation one should preserve generation two');
    assert_same('', $wpdb->queue[42]['claim_token'] ?? null, 'the newer generation should be released for another worker');

    $second = $queue->claim(1, 1002, 30)[0] ?? null;
    assert_same(2, $second['generation'] ?? null, 'the next worker should claim the newer generation');
    assert_true($queue->acknowledge($second, 1003), 'the current generation owner should acknowledge its work');
    assert_same(0, $queue->count(), 'the row should disappear only after its latest generation finishes');
});

test_case('generation-aware queue recovers expired claims without accepting stale acknowledgement', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($wpdb);

    $queue->enqueue(43, 1000);
    $stale = $queue->claim(1, 1000, 10)[0] ?? null;
    assert_true(is_array($stale), 'the initial worker should claim the row');
    assert_same([], $queue->claim(1, 1009, 10), 'an active lease should prevent duplicate processing');

    $recovered = $queue->claim(1, 1010, 10)[0] ?? null;
    assert_true(is_array($recovered), 'an expired lease should be recoverable after a worker crash');
    assert_true(($recovered['token'] ?? '') !== ($stale['token'] ?? ''), 'recovery should transfer ownership to a new token');
    assert_true(!$queue->acknowledge($stale, 1011), 'the stale worker should no longer be allowed to acknowledge');
    assert_true($queue->acknowledge($recovered, 1011), 'the recovery worker should retain acknowledgement ownership');
});

test_case('generation-aware queue retries failures with bounded backoff', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($wpdb);

    $queue->enqueue(44, 1000);
    $first = $queue->claim(1, 1000, 30)[0] ?? null;
    $failure = $queue->fail($first, 1000);
    assert_same('backoff', $failure['status'], 'a failed current generation should enter backoff');
    assert_same(1300, $failure['available_at'], 'the first failure should use the base retry delay');
    assert_same([], $queue->claim(1, 1299, 30), 'a deferred row should not be claimable early');

    $queue->retry(44, 1100);
    $second = $queue->claim(1, 1100, 30)[0] ?? null;
    assert_same(1, $second['attempts'] ?? null, 'the retry claim should carry the prior failure count');
    assert_same(1, $second['generation'] ?? null, 'an explicit retry should not masquerade as a new post save');
    $secondFailure = $queue->fail($second, 1100);
    assert_same(1700, $secondFailure['available_at'], 'the second failure should double the retry delay');
});

test_case('generation-aware queue gives a newer save a clean retry state', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($wpdb);

    $queue->enqueue(45, 1000);
    $old = $queue->claim(1, 1000, 30)[0] ?? null;
    $queue->enqueue(45, 1001);
    $result = $queue->fail($old, 1002);

    assert_same('superseded', $result['status'], 'failure of an old generation should defer to the newer save');
    assert_same(2, $wpdb->queue[45]['generation'] ?? null, 'the newer save should remain pending');
    assert_same(0, $wpdb->queue[45]['attempts'] ?? null, 'the newer save should not inherit an older failure count');
    assert_same(1002, $wpdb->queue[45]['available_at'] ?? null, 'the newer save should be available without failure backoff');
});

test_case('generation-aware queue releases ownership when post loading fails', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $wpdb = new WP_FTS_Test_WPDB();
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    wp_fts_test_seed_queue($wpdb, [46]);
    $GLOBALS['wp_fts_test_get_post_callbacks'][46] = static function (): void {
        unset($GLOBALS['wp_fts_test_get_post_callbacks'][46]);
        throw new RuntimeException('post loading failed');
    };
    $startedAt = time();

    try {
        $result = WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 1]);
        assert_same(1, $result['last_batch_failures'] ?? null, 'post loading failures should be recorded as failed queue attempts');
        assert_same(1, $wpdb->queue[46]['attempts'] ?? null, 'the failed claim should advance its durable attempt count');
        assert_same('', $wpdb->queue[46]['claim_token'] ?? null, 'the failed claim should release worker ownership');
        assert_true(($wpdb->queue[46]['available_at'] ?? 0) >= $startedAt + WP_FTS_Index_Queue::BASE_BACKOFF_SECONDS, 'the failed claim should remain queued with backoff');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('generation-aware queue lets a new post save supersede quarantined work', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $wpdb = new WP_FTS_Test_WPDB();
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $post = wp_fts_test_backfill_post(47, 'post', 'publish', 'Quarantine generation');
    $GLOBALS['wp_fts_test_posts'][47] = $post;
    $wpdb->failDocWriteErrors[47] = 'generation failure';
    wp_fts_test_seed_queue($wpdb, [47]);

    try {
        WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 1]);
        WP_FTS_Plugin::retry_failed_item_recovery(47);
        WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 1]);
        WP_FTS_Plugin::retry_failed_item_recovery(47);
        WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 1]);
        assert_same('quarantined', WP_FTS_Plugin::failure_recovery_status(1, 47)['recent_items'][0]['status'] ?? null, 'three failures should quarantine one unchanged generation');
        assert_same([], wp_fts_test_queue_ids($wpdb), 'quarantine should remove the repeatedly failing generation');

        unset($wpdb->failDocWriteErrors[47]);
        $post->post_content = '<p>corrected generation content</p>';
        WP_FTS_Plugin::handle_post_save(47, $post, true);
        $result = WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 1]);
        assert_same(1, $result['queue_processed'] ?? null, 'a later post save should receive a clean queue generation');
        assert_same(0, WP_FTS_Plugin::failure_recovery_status(1, 47)['total_count'] ?? null, 'the new saved generation should clear obsolete quarantine metadata');
        assert_same(0, $wpdb->docs[47]['is_deleted'] ?? null, 'the corrected generation should become indexed');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('generation-aware queue activation migrates legacy pending work before version acknowledgement', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $wpdb = new WP_FTS_Test_WPDB();
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = 1;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] = [51, '51', 52, 0];

    try {
        WP_FTS_Plugin::activate();
        $createQueries = array_filter(
            $wpdb->queries,
            static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE')
        );
        assert_same(7, count($createQueries), 'schema upgrade should create the durable queue with the six index tables');
        assert_same([51, 52], array_keys($wpdb->queue), 'legacy option ids should be coalesced into durable rows');
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION]), 'migration should delete the legacy option after durable import');
        assert_same(WP_FTS_Plugin::SCHEMA_VERSION, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null, 'schema version should advance after queue migration');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('generation-aware queue activation failure preserves its migration source and old schema version', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $wpdb = new WP_FTS_Test_WPDB();
    $wpdb->failQueryPrefix = 'INSERT INTO wp_fts_queue';
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = 1;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] = [53];

    try {
        $thrown = null;
        try {
            WP_FTS_Plugin::activate();
        } catch (RuntimeException $e) {
            $thrown = $e;
        }
        assert_true($thrown instanceof RuntimeException, 'a failed durable import should fail the migration visibly');
        assert_same([53], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] ?? null, 'a failed migration should retain its legacy source');
        assert_same(1, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null, 'a failed migration should not acknowledge the new schema version');
    } finally {
        $wpdb = $oldWpdb;
    }
});
