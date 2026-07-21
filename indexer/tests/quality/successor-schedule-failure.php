<?php
declare(strict_types=1);

test_case('successor schedule failure surfaces only after cron commits and releases its lease', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_seed_backfill_posts($fake, 25);
    for ($postId = 1; $postId <= WP_FTS_Plugin::DEFAULT_CRON_INDEX_BATCH_SIZE; $postId++) {
        $GLOBALS['wp_fts_test_posts'][$postId] = wp_fts_test_backfill_post($postId);
    }
    wp_fts_test_seed_queue($fake, range(1, 25));
    $GLOBALS['wp_fts_test_schedule_succeeds'] = false;

    $error = null;
    try {
        WP_FTS_Plugin::process_scheduled_indexing();
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_true($error instanceof WP_FTS_Index_Successor_Schedule_Failed, 'a failed cron handoff should raise its specific typed error');
    assert_same('successor_schedule_failed', $error instanceof WP_FTS_Index_Successor_Schedule_Failed ? $error->reason_code : null, 'the cron handoff error should retain its stable reason code');
    assert_contains('work remains queued', $error?->getMessage() ?? '', 'the cron error should explain that committed work remains durable');
    assert_contains('wp fts schedule-queue', $error?->getMessage() ?? '', 'the cron error should name the bounded operator recovery command');
    assert_same(WP_FTS_Plugin::DEFAULT_CRON_INDEX_BATCH_SIZE, count($fake->docs), 'the cron callback should commit its complete bounded batch before surfacing schedule failure');
    assert_same(range(21, 25), wp_fts_test_queue_ids($fake), 'the exact unprocessed suffix should remain durable after schedule failure');
    assert_same(1, count($GLOBALS['wp_fts_test_schedule_calls']), 'the worker should make exactly one bounded successor attempt');
    assert_true(!isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), 'a false WordPress scheduling result must not manufacture an event');
    assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION]), 'schedule failure must still release the exact writer lease');
    assert_true(count($fake->queries) <= 20, 'the complete failed-handoff cron invocation must stay inside the 20-statement ceiling');
});

test_case('manual successor schedule failure reports a future retry without polling', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_posts'][126] = wp_fts_test_backfill_post(126, 'post', 'publish', 'Failed future successor');
    $queue = new WP_FTS_Index_Queue($fake);
    $queue->enqueue_many([126]);
    $claim = $queue->claim_batch(1)[0] ?? null;
    assert_true(is_array($claim), 'future schedule-failure fixture should own one generation');
    $failed = is_array($claim) ? $queue->fail_many([$claim]) : 0;
    assert_same(1, $failed, 'future schedule-failure fixture should enter durable retry state');
    $retryAt = (int) ($fake->queue[126]['available_at'] ?? 0);
    $fake->queries = [];
    $GLOBALS['wp_fts_test_schedule_calls'] = [];
    $GLOBALS['wp_fts_test_scheduled'] = [];
    $GLOBALS['wp_fts_test_schedule_succeeds'] = false;

    try {
        $summary = WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 10]);
        $statementCount = count($fake->queries);
        $status = WP_FTS_Plugin::operator_status();
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(0, $summary['indexed'] ?? null, 'manual indexing must not reclaim a future retry early');
    assert_same(true, $summary['wait_for_next_available'] ?? null, 'manual failure should preserve the exact future-work classification');
    assert_same($retryAt, $summary['next_available_at'] ?? null, 'manual failure should preserve the exact retry timestamp');
    assert_same(true, $summary['successor_schedule_failed'] ?? null, 'manual callers should receive an explicit schedule-failure flag');
    assert_same('successor_schedule_failed', $summary['stop_reason'] ?? null, 'manual callers should receive the stable handoff stop reason');
    assert_same('successor_schedule_failed', $summary['reschedule_decision'] ?? null, 'manual callers must not be told the failed retry was scheduled');
    assert_same('failed', $summary['status'] ?? null, 'manual schedule failure should not retain a success status');
    assert_same(WP_FTS_Index_Successor_Schedule_Failed::class, $summary['error_class'] ?? null, 'manual failure should expose the typed handoff error class');
    assert_same('retry', $fake->queue[126]['state'] ?? null, 'future retry state must remain durable after the failed handoff');
    assert_same($retryAt, $fake->queue[126]['available_at'] ?? null, 'schedule failure must not rewrite durable backoff timing');
    assert_same(1, count($GLOBALS['wp_fts_test_schedule_calls']), 'future-only work should make exactly one schedule attempt');
    assert_true(!isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), 'failed future scheduling must leave the event exactly absent');
    assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION]), 'manual schedule failure must still release the exact writer lease');
    assert_true($statementCount <= 20, 'the complete failed-handoff manual invocation must stay inside the 20-statement ceiling');
    assert_same('missing', $status['queue_processor_schedule']['status'] ?? null, 'read-only operator status should identify the missing event after failure');
    assert_same(true, $status['queue_processor_schedule']['pending_work'] ?? null, 'operator recovery should retain the pending-work context');
});

test_case('contended cron successor schedule failure preserves the active owner and queued work', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_seed_queue($fake, [701]);
    $activeLock = [
        'token' => 'manual-running',
        'mode' => 'manual',
        'started_at' => time(),
        'expires_at' => time() + 300,
    ];
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION] = $activeLock;
    $GLOBALS['wp_fts_test_schedule_succeeds'] = false;

    $error = null;
    try {
        WP_FTS_Plugin::process_scheduled_indexing();
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_true($error instanceof WP_FTS_Index_Successor_Schedule_Failed, 'a contended cron callback should surface its failed handoff');
    assert_same('successor_schedule_failed', $error instanceof WP_FTS_Index_Successor_Schedule_Failed ? $error->reason_code : null, 'the contended cron error should retain its stable reason code');
    assert_contains('work remains queued', $error?->getMessage() ?? '', 'the contended cron error should explain that durable work was preserved');
    assert_contains('wp fts schedule-queue', $error?->getMessage() ?? '', 'the contended cron error should name the bounded operator recovery command');
    assert_same([701], wp_fts_test_queue_ids($fake), 'lock contention plus schedule failure must preserve the exact queued generation');
    assert_same([], $fake->docs, 'a contended callback must not mutate index rows');
    assert_same(1, count($GLOBALS['wp_fts_test_schedule_calls']), 'a contended callback should make exactly one bounded successor attempt');
    assert_true(!isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), 'failed contended scheduling must leave the event exactly absent');
    assert_same($activeLock, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION] ?? null, 'the callback must not release or rewrite another worker\'s active lease');
    assert_true(count($fake->queries) <= 20, 'the complete contended failed-handoff invocation must stay inside the 20-statement ceiling');
});

test_case('empty cron successor needs no schedule even when WordPress scheduling is unavailable', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_seed_queue($fake, []);
    $GLOBALS['wp_fts_test_schedule_succeeds'] = false;

    try {
        $summary = WP_FTS_Plugin::process_scheduled_indexing();
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(false, $summary['has_more'] ?? null, 'an empty worker should report no durable work');
    assert_same(false, $summary['successor_schedule_failed'] ?? null, 'no-row next-available is a successful no-schedule handoff');
    assert_same('not_needed', $summary['reschedule_decision'] ?? null, 'empty cron should retain the exact not-needed decision');
    assert_same([], $GLOBALS['wp_fts_test_schedule_calls'], 'empty cron must not call an unavailable scheduler');
    assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION]), 'empty cron should release its writer lease');
    assert_true(count($fake->queries) <= 20, 'empty no-schedule cron should stay inside the complete statement ceiling');
});
