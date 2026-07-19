<?php
declare(strict_types=1);

test_case('generation-aware queue rejects oversized exact batches before SQL without a corpus fallback', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);

    $tooMany = null;
    try {
        $queue->enqueue_many(range(1, WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS + 1));
    } catch (InvalidArgumentException $error) {
        $tooMany = $error;
    }
    assert_true($tooMany instanceof InvalidArgumentException, 'the 1,001st unique post should reject the exact foreground batch');
    assert_contains('1000-post enqueue contract', $tooMany?->getMessage() ?? '', 'post-count rejection should name the hard caller contract');
    assert_same([], $fake->prepared, 'the over-count batch must fail before preparing SQL');
    assert_same([], $fake->queue, 'the over-count batch must not substitute a global reconciliation scope');

    $tooLarge = null;
    try {
        $queue->enqueue_many(
            range(1, WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS),
            time(),
            ['caller_context' => str_repeat('x', 7900)]
        );
    } catch (InvalidArgumentException $error) {
        $tooLarge = $error;
    }
    assert_true($tooLarge instanceof InvalidArgumentException, 'a sub-8KiB payload repeated across 1,000 rows should reject the >1MiB statement estimate');
    assert_contains('one-megabyte enqueue statement contract', $tooLarge?->getMessage() ?? '', 'packet rejection should name the one-megabyte SQL contract');
    assert_same([], $fake->prepared, 'the over-byte batch must fail before preparing SQL');
    assert_same([], $fake->queue, 'the over-byte batch must leave no direct or scope work behind');

    assert_same(
        WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS,
        $queue->enqueue_many(range(1, WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS)),
        'the exact 1,000-post boundary with an empty payload should remain usable'
    );
    assert_same(1, count($fake->prepared), 'the valid 1,000-post boundary should use one set-oriented UPSERT');
    assert_same(WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS, count($fake->queue), 'the valid boundary should persist every exact post and no corpus scope');
});

test_case('generation-aware queue rejects caller-shaped expansion before SQL', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);
    $rejections = [
        'duplicate enqueue input' => static fn() => $queue->enqueue_many(array_fill(0, WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS + 1, 7)),
        'claim limit' => static fn() => $queue->claim_batch(WP_FTS_Index_Queue::MAX_CLAIM_POSTS + 1),
        'acknowledgement predicates' => static fn() => $queue->acknowledge_many(array_fill(0, WP_FTS_Index_Queue::MAX_CLAIM_POSTS + 1, [
            'post_id' => 7,
            'generation' => 1,
            'token' => 'owned',
        ])),
        'object payload' => static fn() => $queue->enqueue_many([7], null, ['unsafe' => new stdClass()]),
    ];
    foreach ($rejections as $label => $operation) {
        $fake->queries = [];
        $fake->prepared = [];
        $thrown = null;
        try {
            $operation();
        } catch (InvalidArgumentException $error) {
            $thrown = $error;
        }
        assert_true($thrown instanceof InvalidArgumentException, "{$label} should reject rather than clamp or expand");
        assert_same([], $fake->queries, "{$label} should reject before executing SQL");
        assert_same([], $fake->prepared, "{$label} should reject before preparing SQL");
    }
});

test_case('all public queue claims reject unsafe lease and source bounds before SQL', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);
    $rejections = [
        'negative source snapshot' => static fn() => $queue->claim_batch(1, 1000, 300, -1),
        'source snapshot boundary plus one' => static fn() => $queue->claim_batch(
            1,
            1000,
            300,
            WP_FTS_Index_Queue::MAX_SOURCE_SNAPSHOT_BYTES + 1
        ),
        'zero batch lease' => static fn() => $queue->claim_batch(1, 1000, 0),
        'oversized direct lease' => static fn() => $queue->claim(
            0,
            1000,
            WP_FTS_Index_Queue::MAX_LEASE_SECONDS + 1
        ),
        'negative scope lease' => static fn() => $queue->claim_scope(1000, -1),
        'batch expiration overflow' => static fn() => $queue->claim_batch(1, PHP_INT_MAX, 1),
        'direct expiration overflow' => static fn() => $queue->claim(1, PHP_INT_MAX, 1),
        'scope expiration overflow' => static fn() => $queue->claim_scope(PHP_INT_MAX, 1),
    ];
    foreach ($rejections as $label => $operation) {
        $fake->queries = [];
        $fake->prepared = [];
        $thrown = null;
        try {
            $operation();
        } catch (InvalidArgumentException $error) {
            $thrown = $error;
        }
        assert_true($thrown instanceof InvalidArgumentException, "{$label} should reject instead of clamping or overflowing");
        assert_same([], $fake->queries, "{$label} should reject before executing SQL");
        assert_same([], $fake->prepared, "{$label} should reject before preparing SQL");
    }

    assert_same(
        [],
        $queue->claim(0, 1000, WP_FTS_Index_Queue::MAX_LEASE_SECONDS),
        'the exact maximum lease boundary should remain valid for a no-op direct claim'
    );
    assert_same([], $fake->queries, 'a zero-size boundary claim should execute no SQL');
    assert_same([], $fake->prepared, 'a zero-size boundary claim should prepare no SQL');
});

test_case('public dependency invalidation rejects an oversized raw caller batch before SQL', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $thrown = null;
    try {
        WP_FTS_Plugin::invalidate_post_content_dependencies(
            array_fill(0, WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS + 1, 9)
        );
    } catch (InvalidArgumentException $error) {
        $thrown = $error;
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_true($thrown instanceof InvalidArgumentException, 'public invalidation should reject 1,001 raw ids even when they are duplicates');
    assert_same([], $fake->queries, 'oversized public invalidation should execute no SQL');
    assert_same([], $fake->prepared, 'oversized public invalidation should prepare no SQL');
});

test_case('generation-aware queue snapshots an under-budget claim and safely falls back above eight MiB', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $ids = range(7601, 7605);
    foreach ($ids as $postId) {
        $post = wp_fts_test_backfill_post($postId, 'post', 'publish', 'S' . $postId);
        $post->post_content = str_repeat('x', WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES - strlen($post->post_title));
        $GLOBALS['wp_fts_test_posts'][$postId] = $post;
    }

    try {
        $queue = new WP_FTS_Index_Queue($fake);
        $queue->enqueue_many($ids, 1000);
        $fake->prepared = [];
        $claims = $queue->claim_batch(
            5,
            1000,
            300,
            WP_FTS_Index_Queue::MAX_SOURCE_SNAPSHOT_BYTES
        );
        assert_same(5, count($claims), 'the aggregate adversary should still claim every exact generation in one batch');
        foreach ($claims as $claim) {
            assert_same(WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES, $claim['source_bytes'] ?? null, 'claim confirmation should retain each exact source measurement');
            assert_true((int) ($claim['canonical_bytes'] ?? 0) >= WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES, 'claim confirmation should also retain the complete canonical transport measurement');
            assert_same(null, $claim['source_snapshot'] ?? null, 'a ten-MiB claim must withhold all source LOBs instead of partially materializing them');
        }

        $confirm = array_values(array_filter(
            $fake->prepared,
            static fn(array $statement): bool => str_starts_with((string) ($statement['sql'] ?? ''), 'SELECT w.job_key, w.kind, w.post_id')
        ));
        assert_same(1, count($confirm), 'claim and aggregate source preflight should share one indexed confirmation statement');
        assert_contains(
            '<= ' . WP_FTS_Index_Queue::MAX_SOURCE_SNAPSHOT_BYTES,
            (string) ($confirm[0]['sql'] ?? ''),
            'claim confirmation should enforce the hard aggregate snapshot cap before projecting LOBs'
        );

        $measurements = [];
        foreach ($claims as $claim) {
            $measurements[(int) $claim['post_id']] = [
                'exists' => !empty($claim['source_exists']),
                'bytes' => (int) ($claim['source_bytes'] ?? 0),
                'canonical_bytes' => (int) ($claim['canonical_bytes'] ?? 0),
            ];
        }
        $load = new ReflectionMethod(WP_FTS_Plugin::class, 'load_posts_for_indexing');
        $posts = $load->invoke(null, $ids, $measurements, []);
        foreach (array_slice($ids, 0, 4) as $postId) {
            assert_same(
                WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES,
                strlen((string) ($posts[$postId]->post_title ?? '')) + strlen((string) ($posts[$postId]->post_content ?? '')),
                "fallback should load exact source {$postId} without truncation"
            );
            assert_true(empty($posts[$postId]->fts_index_deferred), "fallback should admit source {$postId} inside the eight-MiB prefix");
        }
        assert_same(true, $posts[7605]->fts_index_deferred ?? null, 'the first source beyond the aggregate cap should defer as a whole generation');

        $fallbacks = array_values(array_filter(
            $fake->prepared,
            static fn(array $statement): bool => str_starts_with((string) ($statement['sql'] ?? ''), "SELECT p.ID,\n       CASE WHEN")
        ));
        assert_same(1, count($fallbacks), 'an over-budget snapshot should use one bounded conditional source fallback');
        assert_same(4, substr_count((string) ($fallbacks[0]['sql'] ?? ''), ' AS post_id,'), 'fallback SQL should project only the four-document eight-MiB prefix');
    } finally {
        $wpdb = $oldWpdb;
    }
});

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
    assert_true(!$queue->acknowledge($first, 1002), 'enqueue should clear the old lease so its later acknowledgement loses ownership');
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

test_case('generation-aware queue retries failures with bounded backoff and clean explicit generations', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($wpdb);

    $queue->enqueue(44, 1000);
    $first = $queue->claim(1, 1000, 30)[0] ?? null;
    $failure = $queue->fail($first, 1000);
    assert_same('backoff', $failure['status'], 'a failed current generation should enter backoff');
    assert_same(1300, $failure['available_at'], 'the first failure should use the base retry delay');
    assert_same([], $queue->claim(1, 1299, 30), 'a deferred row should not be claimable early');

    $second = $queue->claim(1, 1300, 30)[0] ?? null;
    assert_same(1, $second['attempts'] ?? null, 'an automatic retry should carry the prior failure count');
    assert_same(1, $second['generation'] ?? null, 'automatic retry should retain the desired generation');
    $secondFailure = $queue->fail($second, 1300);
    assert_same(1900, $secondFailure['available_at'], 'the second same-generation failure should double the retry delay');

    $queue->retry(44, 1400);
    $explicit = $queue->claim(1, 1400, 30)[0] ?? null;
    assert_same(0, $explicit['attempts'] ?? null, 'an explicit operator retry should start a clean failure budget');
    assert_same(2, $explicit['generation'] ?? null, 'an explicit operator retry should fence old workers with a new desired generation');
    assert_same(1700, $queue->fail($explicit, 1400)['available_at'] ?? null, 'the clean generation should restart at the base retry delay');
});

test_case('generation-aware queue gives a newer save a clean retry state', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($wpdb);

    $queue->enqueue(45, 1000);
    $old = $queue->claim(1, 1000, 30)[0] ?? null;
    $queue->enqueue(45, 1001);
    $result = $queue->fail($old, 1002);

    assert_same('lost', $result['status'], 'enqueue clears the old lease, so its later failure report no longer owns any row');
    assert_same(2, $wpdb->queue[45]['generation'] ?? null, 'the newer save should remain pending');
    assert_same(0, $wpdb->queue[45]['attempts'] ?? null, 'the newer save should not inherit an older failure count');
    assert_same(1001, $wpdb->queue[45]['available_at'] ?? null, 'the newer save should retain its own immediate availability without failure backoff');
});

test_case('generation-aware queue backs off every claim when the shared dependency preload fails', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $wpdb = new WP_FTS_Test_WPDB();
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $GLOBALS['wp_fts_test_posts'][46] = wp_fts_test_backfill_post(46, 'post', 'publish', 'Shared preload failure');
    wp_fts_test_seed_queue($wpdb, [46]);
    $wpdb->failReadQueryPrefix = 'SELECT bounded.post_order, bounded.row_order, bounded.source_kind,';
    $startedAt = time();

    try {
        $thrown = null;
        try {
            WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 1]);
        } catch (RuntimeException $error) {
            $thrown = $error;
        }
        assert_true($thrown instanceof RuntimeException, 'a shared dependency preload failure should escape the batch boundary');
        assert_same(1, $wpdb->queue[46]['attempts'] ?? null, 'a shared dependency failure should consume one bounded retry attempt');
        assert_same('retry', $wpdb->queue[46]['state'] ?? null, 'the failed preload should return the generation to durable retry state');
        assert_same('', $wpdb->queue[46]['claim_token'] ?? null, 'the failed preload should release worker ownership immediately');
        assert_true(
            ($wpdb->queue[46]['available_at'] ?? 0) >= $startedAt + WP_FTS_Index_Queue::BASE_BACKOFF_SECONDS,
            'the failed preload should wait for bounded backoff instead of forming a hot retry loop'
        );
        assert_same('content_failure', $wpdb->queue[46]['last_error_code'] ?? null, 'shared preload failure should retain a bounded reason code');
        assert_same([], $wpdb->docs, 'a failed shared preload should perform no index write');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('generation-aware queue keeps repeated failures durable and lets a new save reset them', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($wpdb);
    $queue->enqueue(47, 1000);

    $now = 1000;
    $lastDelay = 0;
    $attempts = WP_FTS_Index_Queue::DEAD_AFTER_ATTEMPTS + 3;
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $claim = $queue->claim(1, $now, 30)[0] ?? null;
        assert_true(is_array($claim), "failure attempt {$attempt} should claim the same desired generation");
        $failure = $queue->fail($claim, $now);
        assert_same('backoff', $failure['status'] ?? null, "failure attempt {$attempt} should stay durable instead of becoming terminal");
        assert_same('retry', $wpdb->queue[47]['state'] ?? null, "failure attempt {$attempt} should remain in the retry state");
        $lastDelay = (int) ($failure['available_at'] ?? 0) - $now;
        $now = (int) ($failure['available_at'] ?? 0);
    }
    assert_same($attempts, $wpdb->queue[47]['attempts'] ?? null, 'repeated failures should retain their diagnostic attempt count beyond the former terminal threshold');
    assert_same(WP_FTS_Index_Queue::MAX_BACKOFF_SECONDS, $lastDelay, 'repeated failures should cap delay without dropping the durable generation');

    $queue->enqueue(47, $now + 1);
    assert_same(2, $wpdb->queue[47]['generation'] ?? null, 'a later save should advance beyond the repeatedly failed generation');
    assert_same('ready', $wpdb->queue[47]['state'] ?? null, 'a later save should make corrected content immediately claimable');
    assert_same(0, $wpdb->queue[47]['attempts'] ?? null, 'a later save should receive a clean failure budget');
    $current = $queue->claim(1, $now + 1, 30)[0] ?? null;
    assert_true(is_array($current), 'the corrected desired generation should be claimable');
    assert_true($queue->acknowledge($current), 'the corrected generation should acknowledge normally');
    assert_same(0, $queue->count(), 'successful corrected work should remove the durable row');
});

test_case('generation-aware queue activation coalesces legacy pending work into one schema corpus scope', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $wpdb = new WP_FTS_Test_WPDB();
    foreach (['wp_fts_terms', 'wp_fts_postings', 'wp_fts_documents', 'wp_fts_work'] as $table) {
        unset($wpdb->schemaColumns[$table], $wpdb->schemaIndexes[$table], $wpdb->schemaUniqueIndexes[$table]);
    }
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = 1;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] = [51, '51', 52, 0];

    try {
        WP_FTS_Plugin::activate();
        $createQueries = array_filter(
            $wpdb->queries,
            static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE')
        );
        assert_same(4, count($createQueries), 'schema upgrade should create the compact four-table schema including shared durable work');
        assert_same([], wp_fts_test_queue_ids($wpdb), 'legacy option migration must not turn an unbounded historical array into foreground post rows');
        $scopeRows = array_values(array_filter(
            $wpdb->queue,
            static fn(array $row): bool => (string) ($row['kind'] ?? '') === 'scope'
        ));
        assert_same(1, count($scopeRows), 'legacy migration, schema upgrade, and activation should share one corpus reconciliation row');
        assert_same(
            'scope:' . hash('sha256', WP_FTS_Index_Queue::GLOBAL_CORPUS_SCOPE_KEY),
            $scopeRows[0]['job_key'] ?? null,
            'legacy migration, schema upgrade, and activation must converge on the one canonical corpus identity'
        );
        assert_same(3, $scopeRows[0]['generation'] ?? null, 'each completed migration phase should advance the same fenced reconciliation generation');
        assert_same('activation', json_decode((string) ($scopeRows[0]['payload'] ?? ''), true)['reason'] ?? null, 'the final activation phase should leave truthful current scope context');
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
    $wpdb->failQueryPrefix = 'INSERT INTO wp_fts_work';
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

test_case('generation-aware queue uninstall surfaces destructive table cleanup failures', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $wpdb = new WP_FTS_Test_WPDB();
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    wp_fts_test_seed_queue($wpdb, [54]);
    $wpdb->failQueryPrefix = 'DROP TABLE IF EXISTS ';
    $scheduleCallsBefore = count($GLOBALS['wp_fts_test_schedule_calls']);

    try {
        $thrown = null;
        try {
            WP_FTS_Plugin::uninstall();
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        assert_true($thrown instanceof RuntimeException, 'a migrated install should surface a destructive table cleanup failure');
        assert_same([54], array_keys($wpdb->queue), 'failed table cleanup should leave durable work visible for a retry');
        assert_same(WP_FTS_Plugin::SCHEMA_VERSION, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null, 'failed table cleanup should not delete schema state and report success');
        assert_same(WP_FTS_Plugin::UNINSTALL_FENCE_VALUE, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::UNINSTALL_FENCE_OPTION] ?? null, 'failed table cleanup should retain the exact fail-closed uninstall fence');
        assert_same($scheduleCallsBefore, count($GLOBALS['wp_fts_test_schedule_calls']), 'failed table cleanup should not schedule repair through the retained fence');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('generation-aware queue uninstall preserves pre-version state on table cleanup failure', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $wpdb = new WP_FTS_Test_WPDB();
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = 1;
    wp_fts_test_seed_queue($wpdb, [55]);
    $wpdb->failQueryPrefix = 'DROP TABLE IF EXISTS ';

    try {
        $thrown = null;
        try {
            WP_FTS_Plugin::uninstall();
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        assert_true($thrown instanceof RuntimeException, 'a pre-version install should surface a durable queue cleanup failure');
        assert_same([55], array_keys($wpdb->queue), 'pre-version durable work should remain visible after failed uninstall cleanup');
        assert_same(1, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null, 'failed pre-version cleanup should preserve operational state for a retry');
        assert_same(WP_FTS_Plugin::UNINSTALL_FENCE_VALUE, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::UNINSTALL_FENCE_OPTION] ?? null, 'failed pre-version cleanup should remain fenced until uninstall retry or explicit activation');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('generation-aware queue uninstall uses idempotent table removal for partial installs', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $wpdb = new WP_FTS_Test_WPDB();
    $wpdb->queueTableExists = false;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = 1;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] = [56];

    try {
        WP_FTS_Plugin::uninstall();

        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION]), 'a partial install without a queue table should still remove its schema state');
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION]), 'a partial install without a queue table should still remove its legacy queue option');
        assert_same(WP_FTS_Plugin::UNINSTALL_FENCE_VALUE, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::UNINSTALL_FENCE_OPTION] ?? null, 'partial-install cleanup should retain only its lifecycle fence');
        assert_same([], $wpdb->prepared, 'DROP TABLE IF EXISTS should not require a metadata probe for a partial install');
        assert_same(1, count($wpdb->queries), 'partial-install uninstall should remain one database statement');
        assert_true(str_starts_with($wpdb->queries[0] ?? '', 'DROP TABLE IF EXISTS '), 'partial-install uninstall should use idempotent table removal');
        assert_contains('wp_fts_work', $wpdb->queries[0] ?? '', 'partial-install uninstall should include the durable work table');
    } finally {
        $wpdb = $oldWpdb;
    }
});
