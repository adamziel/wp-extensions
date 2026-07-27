<?php
declare(strict_types=1);

test_case('late worker commit failure stays recoverable inside the complete statement ceiling', function (): void {
    global $wpdb;

    foreach ([false => 'rejected', true => 'applied-but-reported-failed'] as $commitApplied => $label) {
        $scenario = wp_fts_test_run_late_commit_ceiling_scenario((bool) $commitApplied);
        /** @var WP_FTS_Test_WPDB $fake */
        $fake = $scenario['fake'];
        $failure = $scenario['failure'];
        $queries = array_map(
            static fn(mixed $statement): string => is_array($statement)
                ? (string) ($statement[0] ?? '')
                : (string) $statement,
            $fake->queries
        );

        assert_true(
            $failure instanceof RuntimeException,
            "the {$label} COMMIT error must escape the worker explicitly"
        );
        assert_contains(
            'commit',
            strtolower($failure->getMessage()),
            "the worker must preserve the {$label} publication failure"
        );
        assert_same(
            19,
            count($queries),
            "the direct {$label} COMMIT path must retain its exact nineteen-statement protocol"
        );
        assert_same(
            1,
            $scenario['cron_write_count'],
            "the {$label} outcome must persist its one successor with one cron-option write"
        );
        assert_true(
            count($queries) + (int) $scenario['cron_write_count'] <= 20,
            "the complete {$label} COMMIT path, including cron persistence, must remain inside the twenty-statement ceiling"
        );
        assert_same(1, count(array_filter(
            $queries,
            static fn(string $sql): bool => strtoupper(trim($sql)) === 'COMMIT'
        )), "the {$label} publication must attempt COMMIT exactly once");
        assert_same(1, count(array_filter(
            $queries,
            static fn(string $sql): bool => strtoupper(trim($sql)) === 'ROLLBACK'
        )), "the {$label} publication error must make one best-effort rollback attempt");

        foreach (['wp_fts:fail-batch', 'wp_fts:release-batch', 'wp_fts:scope-yield-to-posts'] as $recoveryTag) {
            assert_same(0, count(array_filter(
                $queries,
                static fn(string $sql): bool => str_contains($sql, $recoveryTag)
            )), "the ambiguous {$label} outcome must not append {$recoveryTag} recovery SQL");
        }
        assert_same(0, count(array_filter(
            $queries,
            static fn(string $sql): bool => str_starts_with($sql, 'UPDATE wp_options SET option_value')
        )), "the maximum {$label} recovery path must defer its optional health write");
        $writerLease = $scenario['options_after'][WP_FTS_Plugin::INDEX_LOCK_OPTION] ?? null;
        assert_true(
            is_array($writerLease) && is_string($writerLease['token'] ?? null) && $writerLease['token'] !== '',
            "the ambiguous {$label} outcome must retain its exact writer option lease"
        );
        $successor = $scenario['scheduled_after'][WP_FTS_Plugin::CRON_HOOK] ?? null;
        assert_true(is_array($successor), "the {$label} outcome must retain one queue watchdog");
        assert_true(
            (int) ($successor['timestamp'] ?? 0) >= (int) $scenario['finished_at']
                + WP_FTS_Index_Queue::DEFAULT_LEASE_SECONDS - 1,
            "the {$label} watchdog must respect the systemic retry delay"
        );
        assert_true(
            (int) ($successor['timestamp'] ?? 0) >= (int) ($writerLease['expires_at'] ?? PHP_INT_MAX),
            "the {$label} watchdog must not run before the retained writer lease expires"
        );

        $beforeKeys = array_keys($scenario['work_before']);
        $afterKeys = array_keys($fake->queue);
        sort($beforeKeys, SORT_STRING);
        sort($afterKeys, SORT_STRING);
        if (!$commitApplied) {
            assert_same(
                'pre-commit-hash',
                $fake->docs[1]['content_hash'] ?? null,
                'a rejected COMMIT must not leak the replacement document generation'
            );
            assert_same(
                $scenario['epoch_before'],
                $fake->searchEpoch,
                'a rejected COMMIT must not publish its cursor epoch'
            );
            assert_same(
                $beforeKeys,
                $afterKeys,
                'a rejected COMMIT must retain every exact post, scope, and epoch work identity'
            );
            $latestClaimExpiry = 0;
            foreach ($scenario['work_before'] as $jobKey => $before) {
                if (!in_array((string) ($before['kind'] ?? ''), ['post', 'scope'], true)) {
                    continue;
                }
                $after = $fake->queue[$jobKey] ?? null;
                assert_true(is_array($after), "a rejected COMMIT must retain {$jobKey}");
                assert_same('leased', $after['state'] ?? null, "a rejected COMMIT must leave {$jobKey} leased");
                assert_same(
                    $before['generation'] ?? null,
                    $after['generation'] ?? null,
                    "a rejected COMMIT must preserve the generation for {$jobKey}"
                );
                assert_same(
                    $after['generation'] ?? null,
                    $after['claimed_generation'] ?? null,
                    "a rejected COMMIT must preserve the exact claimed generation for {$jobKey}"
                );
                assert_true(
                    is_string($after['claim_token'] ?? null) && $after['claim_token'] !== '',
                    "a rejected COMMIT must retain the recovery lease token for {$jobKey}"
                );
                assert_same(
                    $before['attempts'] ?? null,
                    $after['attempts'] ?? null,
                    "ambiguous storage recovery must not misclassify {$jobKey} as a content failure"
                );
                $latestClaimExpiry = max($latestClaimExpiry, (int) ($after['claim_expires_at'] ?? 0));
            }
            assert_true(
                (int) ($successor['timestamp'] ?? 0) >= $latestClaimExpiry,
                'the rejected-COMMIT watchdog must not run before the retained exact leases expire'
            );

            $options =& wp_fts_test_option_store();
            $options[WP_FTS_Plugin::INDEX_LOCK_OPTION]['expires_at'] = time() - 1;
            foreach ($fake->queue as &$row) {
                if (in_array((string) ($row['kind'] ?? ''), ['post', 'scope'], true)) {
                    $row['claim_expires_at'] = time() - 1;
                }
            }
            unset($row);
            // WP-Cron removes a due singleton before invoking its callback.
            unset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]);
            $fake->queries = [];
            $fake->prepared = [];
            $cronWritesBeforeTakeover = (int) $GLOBALS['wp_fts_test_cron_write_count'];
            $oldWpdb = $wpdb ?? null;
            $wpdb = $fake;
            try {
                $takeover = WP_FTS_Plugin::process_manual_index_batch([
                    'batch_size' => 100,
                    'source' => 'late-commit-stale-lease-takeover',
                ]);
            } finally {
                $wpdb = $oldWpdb;
            }
            $takeoverQueries = array_map(
                static fn(mixed $statement): string => is_array($statement)
                    ? (string) ($statement[0] ?? '')
                    : (string) $statement,
                $fake->queries
            );
            assert_same(
                'stale_writer_lease_recovered',
                $takeover['stop_reason'] ?? null,
                'the first successor must classify stale option takeover as its own control phase'
            );
            assert_same(0, $takeover['indexed'] ?? null, 'stale option takeover must not compose with document work');
            assert_true(
                count($takeoverQueries) <= 5,
                'stale option takeover must use only one bounded option read plus acquire, exact replacement, and release SQL'
            );
            assert_same(2, count(array_filter(
                $takeoverQueries,
                static fn(string $sql): bool => str_starts_with($sql, 'INSERT IGNORE INTO wp_options')
            )), 'stale option takeover must attempt the contended and replacement lease INSERTs exactly once each');
            assert_same(2, count(array_filter(
                $takeoverQueries,
                static fn(string $sql): bool => str_starts_with($sql, 'DELETE FROM wp_options')
            )), 'stale option takeover must exactly retire the predecessor and its standalone replacement');
            assert_same(
                1,
                (int) $GLOBALS['wp_fts_test_cron_write_count'] - $cronWritesBeforeTakeover,
                'stale option takeover must move its one successor with one cron-option write'
            );
            foreach ($takeoverQueries as $sql) {
                assert_true(
                    str_contains($sql, 'wp_options')
                        && !str_starts_with($sql, 'UPDATE ')
                        && !str_contains($sql, 'fts_work')
                        && !str_contains($sql, 'wp_posts')
                        && !str_contains($sql, 'fts_terms')
                        && !str_contains($sql, 'START TRANSACTION'),
                    'stale option takeover must not claim, load, or mutate derived work'
                );
            }
            assert_true(
                !array_key_exists(WP_FTS_Plugin::INDEX_LOCK_OPTION, wp_fts_test_option_store()),
                'the standalone takeover phase must release its newly acquired writer lease'
            );

            // Model WordPress consuming the due singleton before invoking the
            // next callback. The ordinary successor can now acquire in one
            // INSERT and process the expired exact claims without takeover SQL.
            unset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]);
            $fake->queries = [];
            $fake->prepared = [];
            $cronWritesBeforeOrdinary = (int) $GLOBALS['wp_fts_test_cron_write_count'];
            $oldWpdb = $wpdb ?? null;
            $wpdb = $fake;
            try {
                $ordinary = WP_FTS_Plugin::process_manual_index_batch([
                    'batch_size' => 100,
                    'source' => 'late-commit-ordinary-successor',
                ]);
            } finally {
                $wpdb = $oldWpdb;
            }
            $ordinaryQueries = array_map(
                static fn(mixed $statement): string => is_array($statement)
                    ? (string) ($statement[0] ?? '')
                    : (string) $statement,
                $fake->queries
            );
            assert_same(1, $ordinary['indexed'] ?? null, 'the invocation after standalone takeover must resume document work');
            assert_true(
                isset($ordinaryQueries[0], $ordinaryQueries[1])
                    && str_starts_with($ordinaryQueries[0], 'INSERT IGNORE INTO wp_options')
                    && str_contains($ordinaryQueries[1], '/* wp_fts:claim-batch */'),
                'the ordinary successor must acquire once and proceed directly to its bounded claim'
            );
            assert_same(1, count(array_filter(
                $ordinaryQueries,
                static fn(string $sql): bool => str_starts_with($sql, 'INSERT IGNORE INTO wp_options')
            )), 'the ordinary successor must perform one uncontended lease acquisition');
            assert_true(
                count($ordinaryQueries)
                    + (int) $GLOBALS['wp_fts_test_cron_write_count']
                    - $cronWritesBeforeOrdinary <= 20,
                'the resumed maximum writer plus its cron persistence must remain inside twenty statements'
            );
            continue;
        }

        assert_true(
            ($fake->docs[1]['content_hash'] ?? 'pre-commit-hash') !== 'pre-commit-hash',
            'an applied COMMIT whose response failed must retain its replacement document generation'
        );
        assert_true(
            $fake->searchEpoch > (int) $scenario['epoch_before'],
            'an applied COMMIT whose response failed must retain its cursor epoch publication'
        );
        assert_true(
            !isset($fake->queue[1]),
            'an applied COMMIT whose response failed must retain its exact successful acknowledgement'
        );
        for ($postId = 2; $postId <= 6; $postId++) {
            $jobKey = 'post:' . $postId;
            $before = $scenario['work_before'][$postId] ?? null;
            $after = $fake->queue[$postId] ?? null;
            assert_true(is_array($before) && is_array($after), "the applied outcome must retain deferred {$jobKey}");
            assert_same('ready', $after['state'] ?? null, "the applied outcome must publish deferred {$jobKey} ready");
            assert_same('', $after['claim_token'] ?? null, "the applied outcome must retire the lease for {$jobKey}");
            assert_same(
                $before['generation'] ?? null,
                $after['generation'] ?? null,
                "the applied outcome must preserve the deferred generation for {$jobKey}"
            );
        }
        $scopeJobKey = '';
        foreach ($scenario['work_before'] as $jobKey => $before) {
            if (($before['kind'] ?? '') === 'scope') {
                $scopeJobKey = (string) $jobKey;
                break;
            }
        }
        $settledScope = $fake->queue[$scopeJobKey] ?? null;
        assert_true(is_array($settledScope), 'the applied outcome must retain its alternation scope');
        assert_same('ready', $settledScope['state'] ?? null, 'the applied outcome must publish the scope ready');
        assert_same('', $settledScope['claim_token'] ?? null, 'the applied outcome must retire the scope lease');
        assert_same(
            WP_FTS_Index_Queue::SCOPE_EXPANSION_TURN_CODE,
            $settledScope['last_error_code'] ?? null,
            'the applied outcome must retain the transactional scope-turn publication'
        );
    }
});

/**
 * Exercise both client-visible outcomes of the same ambiguous COMMIT error.
 *
 * @return array{
 *   fake:WP_FTS_Test_WPDB,
 *   failure:?Throwable,
 *   work_before:array<string,array<string,mixed>>,
 *   epoch_before:int,
 *   options_after:array<string,mixed>,
 *   scheduled_after:array<string,array<string,mixed>>,
 *   cron_write_count:int,
 *   finished_at:int
 * }
 */
function wp_fts_test_run_late_commit_ceiling_scenario(bool $commitApplied): array
{
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->options = 'wp_options';
    $fake->recordReadQueries = true;
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    $tokens = [];
    for ($index = 0; $index < WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_TERMS; $index++) {
        $tokens[] = 'commitfailure' . str_pad((string) $index, 4, '0', STR_PAD_LEFT);
    }
    $maximum = wp_fts_test_backfill_post(1);
    $maximum->post_title = '';
    $maximum->post_content = '<p>' . implode(' ', $tokens) . '</p>';
    $posts = [$maximum];
    $visible = '<p>commitfailuredependency</p>';
    $open = '<!--';
    $close = '-->';
    $contentBytes = 1900000;
    $nearLimit = $visible . $open
        . str_repeat('x', $contentBytes - strlen($visible . $open . $close))
        . $close;
    for ($postId = 2; $postId <= 6; $postId++) {
        $post = wp_fts_test_backfill_post($postId);
        $post->post_title = '';
        $post->post_content = $nearLimit;
        $posts[] = $post;
    }
    $fake->postRows = $posts;
    foreach ($posts as $post) {
        $GLOBALS['wp_fts_test_posts'][(int) $post->ID] = $post;
    }
    $GLOBALS['wp_fts_test_post_meta'][2]['selected_signal'] = ['dependencyword'];
    $fake->docs[1] = wp_fts_test_document_row(
        1,
        'en',
        'pre-commit-hash',
        'pre-commit snippet'
    );
    $fake->existingPostingFrontierCounts[1] = WP_FTS_Relational_Storage::MAX_DOCUMENT_POSTINGS;

    $queue = new WP_FTS_Index_Queue($fake);
    $queue->enqueue_many(range(1, 6), null, [
        'index_options' => [
            'document_lang' => 'en',
            'custom_field_keys' => ['selected_signal'],
        ],
    ]);
    wp_fts_test_seed_scope($fake, 'late-commit-statement-ceiling');
    $workBefore = $fake->queue;
    $epochBefore = $fake->searchEpoch;
    $fake->queries = [];
    $fake->prepared = [];
    if ($commitApplied) {
        $fake->failCommitAfterApply = true;
    } else {
        $fake->failQueryPrefix = 'COMMIT';
    }
    $failure = null;
    try {
        WP_FTS_Plugin::process_manual_index_batch([
            'batch_size' => 100,
            'source' => 'late-commit-statement-ceiling',
        ]);
    } catch (Throwable $error) {
        $failure = $error;
    } finally {
        $fake->failQueryPrefix = null;
        $fake->failCommitAfterApply = false;
        $optionsAfter = wp_fts_test_option_store();
        $scheduledAfter = $GLOBALS['wp_fts_test_scheduled'];
        $cronWriteCount = (int) $GLOBALS['wp_fts_test_cron_write_count'];
        $finishedAt = time();
        $wpdb = $oldWpdb;
    }

    return [
        'fake' => $fake,
        'failure' => $failure,
        'work_before' => $workBefore,
        'epoch_before' => $epochBefore,
        'options_after' => $optionsAfter,
        'scheduled_after' => $scheduledAfter,
        'cron_write_count' => $cronWriteCount,
        'finished_at' => $finishedAt,
    ];
}
