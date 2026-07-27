<?php
declare(strict_types=1);

/**
 * Lifecycle exclusion and one-write cron replacement contracts.
 *
 * Direct execution re-enters the shared harness with a focused filter. Normal
 * tests/run.php discovery registers these tests alongside the full suite.
 */
function wp_fts_lifecycle_scheduler_contract_direct(): int
{
    if (!function_exists('proc_open')) {
        fwrite(STDOUT, "SKIP: proc_open() is unavailable, so the lifecycle scheduler contract cannot launch tests/run.php.\n");
        return 0;
    }

    $root = dirname(__DIR__, 2);
    $process = proc_open(
        [PHP_BINARY, $root . '/tests/run.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        array_merge(is_array(getenv()) ? getenv() : [], [
            'WP_FTS_TEST_FILTER' => 'lifecycle scheduler capability',
            'WP_FTS_MIN_CHECKS' => '0',
        ])
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "FAIL: Could not launch the lifecycle scheduler contract.\n");
        return 1;
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    fwrite(STDOUT, $stdout);
    fwrite(STDERR, $stderr);

    return is_int($exit) ? $exit : 1;
}

if (!function_exists('test_case')) {
    exit(wp_fts_lifecycle_scheduler_contract_direct());
}

/** Invoke the private single-write replacement without queue side effects. */
function wp_fts_lifecycle_scheduler_replace(int $timestamp): bool
{
    $method = new ReflectionMethod(WP_FTS_Plugin::class, 'replace_queue_processor_cron_event');
    $method->setAccessible(true);

    return $method->invoke(null, $timestamp) === true;
}

/**
 * Inject one separately preloaded direct writer after DROP while uninstall's
 * exclusive capability and writer lease are both still live.
 */
function wp_fts_lifecycle_scheduler_assert_writer_blocked(
    string $label,
    callable $writer,
    ?callable $prepare = null,
    bool $writerCatchesBusy = false
): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    static $sequence = 0;
    $sequence++;
    $fake = new WP_FTS_Test_WPDB();
    $fake->prefix = 'wp_cap_writer_' . getmypid() . '_' . $sequence . '_';
    $fake->posts = $fake->prefix . 'posts';
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $options = wp_fts_test_uninstall_operational_option_names();
    $GLOBALS['wp_fts_test_options'] = wp_fts_test_uninstall_seeded_options($options, $sequence);
    unset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION]);
    if ($prepare !== null) {
        $prepare();
    }
    $GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK] = [
        'timestamp' => time() + 300,
        'hook' => WP_FTS_Plugin::CRON_HOOK,
        'args' => [],
    ];
    $observed = [];
    $GLOBALS['wp_fts_test_before_delete_option'] = static function (string $optionName) use (
        $fake,
        $writer,
        &$observed
    ): void {
        if ($observed !== []) {
            return;
        }
        $property = new ReflectionProperty(WP_FTS_Plugin::class, 'foreground_queue_blocked_prefixes');
        $blocked = $property->getValue();
        unset($blocked[$fake->prefix]);
        $property->setValue(null, $blocked);
        $queriesBefore = count($fake->queries);
        $schedulesBefore = count($GLOBALS['wp_fts_test_schedule_calls']);
        $addsBefore = count($GLOBALS['wp_fts_test_added_options']);
        $updatesBefore = count($GLOBALS['wp_fts_test_updated_options']);
        $error = null;
        try {
            $writer();
        } catch (Throwable $caught) {
            $error = $caught;
        }
        $observed = [
            'option' => $optionName,
            'queries' => array_slice($fake->queries, $queriesBefore),
            'schedules' => count($GLOBALS['wp_fts_test_schedule_calls']) - $schedulesBefore,
            'adds' => count($GLOBALS['wp_fts_test_added_options']) - $addsBefore,
            'updates' => count($GLOBALS['wp_fts_test_updated_options']) - $updatesBefore,
            'error' => $error,
        ];
        $blocked[$fake->prefix] = true;
        $property->setValue(null, $blocked);
    };

    try {
        WP_FTS_Plugin::uninstall();
        assert_same(WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION, $observed['option'] ?? null, "{$label}: injection must run after DROP and cron clearing");
        assert_same([], $observed['queries'] ?? null, "{$label}: an uninstall-owned capability must permit zero later SQL");
        assert_same(0, $observed['schedules'] ?? null, "{$label}: an uninstall-owned capability must permit zero later cron events");
        assert_same(0, $observed['adds'] ?? null, "{$label}: a blocked writer must add zero options");
        assert_same(0, $observed['updates'] ?? null, "{$label}: a blocked writer must update zero options");
        if ($writerCatchesBusy) {
            assert_same(null, $observed['error'] ?? null, "{$label}: its watchdog API should absorb lifecycle contention");
        } else {
            assert_true(
                ($observed['error'] ?? null) instanceof WP_FTS_Foreground_Owner_Guard_Busy,
                "{$label}: its public mutation API must explicitly report lifecycle contention"
            );
        }
        assert_true(!isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), "{$label}: uninstall must leave no stale queue event");
        assert_same([], $fake->queue, "{$label}: uninstall must leave no stale queue generation");
    } finally {
        unset($GLOBALS['wp_fts_test_before_delete_option']);
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
}

/**
 * Tokenize named Plugin methods and list every durable queue mutation call.
 *
 * @return array{calls:array<string,array<int,string>>,source:array<string,string>}
 */
function wp_fts_lifecycle_scheduler_queue_mutation_map(): array
{
    $pluginSource = file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');
    if (!is_string($pluginSource)) {
        throw new RuntimeException('Could not read Plugin.php for the queue capability contract.');
    }
    $methods = [];
    foreach (wp_fts_php_source_function_stream($pluginSource) as $method) {
        $methods[$method['name']] = $method['tokens'];
    }

    $mutationNames = array_fill_keys([
        'enqueue',
        'enqueue_many',
        'enqueue_scope',
        'fence_post',
        'fence_scope',
        'promote_post',
        'promote_scope',
        'handoff_foreground_mutation_scope',
        'coalesce_corpus_successor',
        'retry_many',
        'claim_batch',
        'fail_scope',
        'fail_many',
        'release_scope',
        'release_many',
        'discard_replaced_scope',
        'yield_scope_and_release_posts',
        'acknowledge_scope',
        'acknowledge_many',
        // These helpers are capabilities only when every caller is classified.
        'enqueue_corpus_scope',
    ], true);
    $callsByMethod = [];
    $sourceByMethod = [];
    foreach ($methods as $methodName => $body) {
        $sourceByMethod[$methodName] = implode('', array_map(
            static fn(array $token): string => $token[1],
            $body
        ));
        $calls = [];
        $bodyCount = count($body);
        for ($index = 0; $index < $bodyCount; $index++) {
            if (
                !in_array($body[$index][0], ['object_operator', 'double_colon'], true)
            ) {
                continue;
            }
            for ($cursor = $index + 1; $cursor < $bodyCount; $cursor++) {
                $candidate = $body[$cursor];
                if (
                    wp_fts_php_source_token_is_trivia($candidate)
                ) {
                    continue;
                }
                if (
                    $candidate[0] === 'identifier'
                    && isset($mutationNames[$candidate[1]])
                    && !in_array($candidate[1], $calls, true)
                ) {
                    $calls[] = $candidate[1];
                }
                break;
            }
        }
        if ($calls !== []) {
            $callsByMethod[$methodName] = $calls;
        }
    }

    return ['calls' => $callsByMethod, 'source' => $sourceByMethod];
}

test_case('lifecycle scheduler capability exclusive guard rejects both interleaving orders within 50ms', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $prefix = 'wp_cap_' . getmypid() . '_';
    $queue = new WP_FTS_Index_Queue($fake, $prefix);
    $shared = $queue->acquire_foreground_owner_guard();
    $started = microtime(true);
    try {
        $busy = null;
        try {
            $queue->acquire_exclusive_foreground_owner_guard();
        } catch (Throwable $error) {
            $busy = $error;
        }
        assert_true($busy instanceof WP_FTS_Foreground_Owner_Guard_Busy, 'an active foreground request must make uninstall retry before destruction');
        assert_true(microtime(true) - $started < 0.05, 'same-process lifecycle contention must fail without sleeping through the 50ms cross-process bound');
    } finally {
        $queue->release_foreground_owner_guard($shared);
    }

    $exclusive = $queue->acquire_exclusive_foreground_owner_guard();
    $started = microtime(true);
    try {
        $busy = null;
        try {
            $queue->acquire_foreground_owner_guard();
        } catch (Throwable $error) {
            $busy = $error;
        }
        assert_true($busy instanceof WP_FTS_Foreground_Owner_Guard_Busy, 'an uninstall in progress must reject a newly arriving foreground writer');
        assert_true(microtime(true) - $started < 0.05, 'same-process foreground contention must fail without waiting past the bounded guard interval');
    } finally {
        $queue->release_exclusive_foreground_owner_guard($exclusive);
    }

    $shared = $queue->acquire_foreground_owner_guard();
    $queue->release_foreground_owner_guard($shared);
    assert_same([], $fake->queries, 'file capability interleavings must add zero database statements');
});

test_case('lifecycle scheduler capability active foreground owner makes uninstall perform zero destructive work', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $prefix = 'wp_cap_busy_' . getmypid() . '_';
    $fake->prefix = $prefix;
    $fake->posts = $prefix . 'posts';
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $optionsBefore = $GLOBALS['wp_fts_test_options'];
    $guardQueue = new WP_FTS_Index_Queue($fake, $prefix);
    $shared = $guardQueue->acquire_foreground_owner_guard();

    try {
        $error = null;
        try {
            WP_FTS_Plugin::uninstall();
        } catch (Throwable $caught) {
            $error = $caught;
        }
        assert_true($error instanceof WP_FTS_Foreground_Owner_Guard_Busy, 'uninstall must return a retryable contention error while foreground work owns the site');
        assert_same([], $fake->queries, 'contended uninstall must issue zero database statements');
        assert_same([], $GLOBALS['wp_fts_test_added_options'], 'contended uninstall must not acquire its writer lease or persist its fence');
        assert_same([], $GLOBALS['wp_fts_test_updated_options'], 'contended uninstall must not rewrite health or lifecycle state');
        assert_same([], $GLOBALS['wp_fts_test_deleted_options'], 'contended uninstall must delete no operational option');
        assert_same([], $GLOBALS['wp_fts_test_cleared_hooks'], 'contended uninstall must clear no worker event');
        assert_same($optionsBefore, $GLOBALS['wp_fts_test_options'], 'contended uninstall must preserve the complete site state');
    } finally {
        $guardQueue->release_foreground_owner_guard($shared);
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});

test_case('lifecycle scheduler capability foreground callback inside uninstall cannot recreate work or cron', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $options = wp_fts_test_uninstall_operational_option_names();
    $GLOBALS['wp_fts_test_options'] = wp_fts_test_uninstall_seeded_options($options, 1);
    unset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION]);
    $GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK] = [
        'timestamp' => time() + 300,
        'hook' => WP_FTS_Plugin::CRON_HOOK,
        'args' => [],
    ];
    $observed = [];
    $GLOBALS['wp_fts_test_before_delete_option'] = static function (string $optionName) use ($fake, &$observed): void {
        if ($observed !== []) {
            return;
        }
        // Model a separately preloaded PHP request: it does not share the
        // uninstall request's in-memory prefix latch, only the file guard.
        $property = new ReflectionProperty(WP_FTS_Plugin::class, 'foreground_queue_blocked_prefixes');
        $blocked = $property->getValue();
        unset($blocked[$fake->prefix]);
        $property->setValue(null, $blocked);
        $queriesBefore = count($fake->queries);
        $schedulesBefore = count($GLOBALS['wp_fts_test_schedule_calls']);
        $updatesBefore = count($GLOBALS['wp_fts_test_updated_options']);
        WP_FTS_Plugin::handle_post_save(88001, (object) [
            'ID' => 88001,
            'post_type' => 'post',
            'post_status' => 'publish',
        ], true, null);
        $newQueries = array_slice($fake->queries, $queriesBefore);
        $observed = [
            'option' => $optionName,
            'queries' => $newQueries,
            'schedules' => count($GLOBALS['wp_fts_test_schedule_calls']) - $schedulesBefore,
            'updates' => count($GLOBALS['wp_fts_test_updated_options']) - $updatesBefore,
        ];
        $blocked[$fake->prefix] = true;
        $property->setValue(null, $blocked);
    };

    try {
        WP_FTS_Plugin::uninstall();
        assert_same(WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION, $observed['option'] ?? null, 'the adversarial foreground callback must run after DROP while uninstall still owns both capabilities');
        assert_same([], $observed['queries'] ?? null, 'the callback must execute zero queue or diagnostic SQL while uninstall owns the exclusive guard');
        assert_same(0, $observed['schedules'] ?? null, 'the callback must schedule zero queue or schema events while uninstall owns the exclusive guard');
        assert_same(0, $observed['updates'] ?? null, 'the callback must recreate zero health options while uninstall owns the exclusive guard');
        assert_true(!isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), 'uninstall must leave no stale foreground worker event');
        assert_true(!isset($fake->queue[88001]), 'uninstall must leave no stale foreground queue generation');
    } finally {
        unset($GLOBALS['wp_fts_test_before_delete_option']);
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});

test_case('lifecycle scheduler capability readiness watchdog cannot write after uninstall', function (): void {
    wp_fts_lifecycle_scheduler_assert_writer_blocked(
        'readiness watchdog',
        static fn(): mixed => WP_FTS_Plugin::maybe_schedule_initial_index_readiness(),
        static function (): void {
            $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] = [
                'last_run_at' => '2026-07-18 00:00:00',
            ];
        },
        true
    );
});

test_case('lifecycle scheduler capability direct reindex batch cannot write after uninstall', function (): void {
    wp_fts_lifecycle_scheduler_assert_writer_blocked(
        'direct reindex batch',
        static fn(): int => WP_FTS_Plugin::enqueue_posts_for_reindex([88101], ['document_lang' => 'en'])
    );
});

test_case('lifecycle scheduler capability filtered reindex scope cannot write after uninstall', function (): void {
    wp_fts_lifecycle_scheduler_assert_writer_blocked(
        'filtered reindex scope',
        static fn(): string => WP_FTS_Plugin::enqueue_reindex_scope([
            'post_status' => ['publish'],
            'post_type' => ['post'],
        ])
    );
});

test_case('lifecycle scheduler capability failed-item retry cannot write after uninstall', function (): void {
    wp_fts_lifecycle_scheduler_assert_writer_blocked(
        'failed-item retry',
        static fn(): array => WP_FTS_Plugin::retry_failed_item_recovery(88201, 1),
        static function (): void {
            $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] = [
                'failure_history' => [[
                    'post_id' => 88201,
                    'title' => 'Retry target',
                    'status' => 'backoff',
                    'failure_count' => 1,
                    'attempt_count' => 1,
                    'last_failed_at' => '2026-07-18 00:00:00',
                    'next_retry_at' => '2026-07-19 00:00:00',
                ]],
            ];
        }
    );
});

test_case('lifecycle scheduler capability direct writers retain their normal statement counts', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $cases = [
        'readiness watchdog' => [3, 0, static function (): void {
            $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] = [
                'last_run_at' => '2026-07-18 00:00:00',
            ];
            WP_FTS_Plugin::maybe_schedule_initial_index_readiness();
        }],
        'direct reindex batch' => [3, 1, static function (): void {
            WP_FTS_Plugin::enqueue_posts_for_reindex([88301], ['document_lang' => 'en']);
        }],
        'filtered reindex scope' => [3, 1, static function (): void {
            WP_FTS_Plugin::enqueue_reindex_scope([
                'post_status' => ['publish'],
                'post_type' => ['post'],
            ]);
        }],
        'failed-item retry' => [4, 1, static function (): void {
            $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION]['failure_history'] = [[
                'post_id' => 88302,
                'title' => 'Retry target',
                'status' => 'backoff',
                'failure_count' => 1,
                'attempt_count' => 1,
                'last_failed_at' => '2026-07-18 00:00:00',
                'next_retry_at' => '2026-07-19 00:00:00',
            ]];
            WP_FTS_Plugin::retry_failed_item_recovery(88302, 1);
        }],
    ];

    try {
        foreach ($cases as $label => [$expectedStatements, $expectedQueueWrites, $writer]) {
            $fake = new WP_FTS_Test_WPDB();
            $fake->options = 'wp_options';
            $fake->recordReadQueries = true;
            $wpdb = $fake;
            wp_fts_test_reset_wordpress_fakes();
            $fake->queries = [];
            $fake->prepared = [];
            $writer();
            assert_same($expectedStatements, count($fake->queries), "{$label}: shared lifecycle exclusion must add zero normal-path statements");
            assert_same($expectedQueueWrites, count(array_filter(
                $fake->queries,
                static fn(mixed $sql): bool => str_starts_with(
                    is_array($sql) ? (string) ($sql[0] ?? '') : (string) $sql,
                    'INSERT INTO wp_fts_work'
                )
            )), "{$label}: normal operation must retain its set-oriented queue statement count");
            assert_same(
                null,
                (new ReflectionProperty(WP_FTS_Plugin::class, 'foreground_owner_guard'))->getValue(),
                "{$label}: a complete direct operation must release only the scoped guard it acquired"
            );
            WP_FTS_Plugin::reset_request_caches();
        }
    } finally {
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});

test_case('lifecycle scheduler capability tokenized queue writer allowlist cannot drift', function (): void {
    $inspection = wp_fts_lifecycle_scheduler_queue_mutation_map();
    $expected = [
        'maybe_schedule_initial_index_readiness' => ['enqueue_scope'],
        'create_or_repair_schema_under_lock' => ['enqueue_corpus_scope'],
        'run_scheduled_schema_repair' => ['enqueue_corpus_scope'],
        'flush_foreground_bulk_mutations' => ['handoff_foreground_mutation_scope'],
        'enqueue_posts_for_reindex' => ['enqueue_many'],
        'enqueue_reindex_scope' => ['enqueue_scope'],
        'retry_failed_item_recovery' => ['retry_many'],
        'reset_index' => ['enqueue_corpus_scope'],
        'fence_post_mutation' => ['fence_post'],
        'activate_foreground_bulk_mutation_scope' => ['fence_scope'],
        'refresh_expired_foreground_bulk_fence' => ['fence_scope'],
        'persist_foreground_post_mutations' => ['enqueue_many'],
        'persist_foreground_post_mutation_promotion' => ['promote_post'],
        'persist_scope_reconciliation' => ['fence_scope', 'enqueue_scope', 'promote_scope'],
        'remember_foreground_queue_failure' => ['enqueue_scope'],
        'process_relational_work_batch' => [
            'claim_batch',
            'fail_scope',
            'release_scope',
            'fail_many',
            'release_many',
            'enqueue_scope',
            'enqueue_corpus_scope',
            'discard_replaced_scope',
            'acknowledge_scope',
        ],
        'process_prepared_claim_batch' => [
            'yield_scope_and_release_posts',
            'release_many',
            'fail_many',
            'acknowledge_many',
        ],
        'finalize_initial_index_readiness_in_maintenance' => ['enqueue_scope'],
        'enqueue_corpus_scope' => ['coalesce_corpus_successor'],
    ];
    assert_same($expected, $inspection['calls'], 'every new direct queue mutation or helper caller must receive an explicit lifecycle-capability review');

    foreach ([
        'maybe_schedule_initial_index_readiness',
        'enqueue_posts_for_reindex',
        'enqueue_reindex_scope',
        'retry_failed_item_recovery',
    ] as $methodName) {
        assert_contains(
            'scoped_foreground_lifecycle_checked_index_queue',
            $inspection['source'][$methodName] ?? '',
            "{$methodName} must hold the shared capability and durable fence check through its complete operation"
        );
    }
    foreach ([
        'flush_foreground_bulk_mutations',
        'fence_post_mutation',
        'activate_foreground_bulk_mutation_scope',
        'refresh_expired_foreground_bulk_fence',
        'persist_foreground_post_mutations',
        'persist_foreground_post_mutation_promotion',
        'persist_scope_reconciliation',
    ] as $methodName) {
        assert_true(
            str_contains($inspection['source'][$methodName] ?? '', 'foreground_index_queue')
                || str_contains($inspection['source'][$methodName] ?? '', 'foreground_mutation_token'),
            "{$methodName} must acquire or revalidate the canonical shared capability before queue mutation"
        );
    }
    assert_contains('run_index_writer_with_lock', $inspection['source']['activate'] ?? '', 'activation queue writes must remain inside the lifecycle/writer boundary');
    assert_contains('run_index_writer_with_lock', $inspection['source']['provision_site_schema'] ?? '', 'site provisioning queue writes must remain inside the lifecycle/writer boundary');
    assert_contains('assert_index_writer_ownership', $inspection['source']['create_or_repair_schema_under_lock'] ?? '', 'schema repair queue writes must assert the live writer lease');
    assert_contains('acquire_index_lock', $inspection['source']['run_scheduled_schema_repair'] ?? '', 'scheduled schema queue writes must acquire the live writer lease');
    assert_contains('assert_index_writer_ownership', $inspection['source']['reset_index'] ?? '', 'reset queue writes must assert the live writer lease');
    assert_contains('assert_index_writer_ownership', $inspection['source']['process_indexing_batch'] ?? '', 'worker queue mutations must begin behind a live writer lease');
});

test_case('lifecycle scheduler capability replacement honors filters and preserves nonempty siblings in one write', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $oldTimestamp = time() + 600;
    $siblingArgs = [17, 'tenant'];
    $GLOBALS['wp_fts_test_scheduled'] = [
        WP_FTS_Plugin::CRON_HOOK => [
            'timestamp' => $oldTimestamp,
            'hook' => WP_FTS_Plugin::CRON_HOOK,
            'args' => [],
        ],
        WP_FTS_Plugin::CRON_HOOK . '|' . serialize($siblingArgs) => [
            'timestamp' => $oldTimestamp + 1,
            'hook' => WP_FTS_Plugin::CRON_HOOK,
            'args' => $siblingArgs,
        ],
    ];
    $filteredTimestamp = time() + 7;
    $seen = [];
    $GLOBALS['wp_fts_test_filters']['pre_schedule_event'] = static function (mixed $pre, object $event, bool $wpError) use (&$seen): mixed {
        $seen[] = ['pre', $event->hook, $event->args, $wpError];
        return $pre;
    };
    $GLOBALS['wp_fts_test_filters']['schedule_event'] = static function (object $event) use (&$seen, $filteredTimestamp): object {
        $seen[] = ['event', $event->hook, $event->args];
        $event->timestamp = $filteredTimestamp;
        $event->args = ['external-provider'];
        return $event;
    };

    assert_true(wp_fts_lifecycle_scheduler_replace(time() + 1), 'a valid filtered replacement should persist');
    assert_same(1, $GLOBALS['wp_fts_test_cron_array_reads'], 'replacement must read Core cron exactly once');
    assert_same(1, $GLOBALS['wp_fts_test_cron_write_count'], 'replacement must write Core cron exactly once');
    assert_same([], $GLOBALS['wp_fts_test_schedule_calls'], 'direct one-write replacement must not call Core scheduling recursively');
    assert_same([
        ['pre', WP_FTS_Plugin::CRON_HOOK, [], false],
        ['event', WP_FTS_Plugin::CRON_HOOK, []],
    ], $seen, 'replacement must expose the same single-event shape and wp_error=false to both Core filters');
    assert_same(
        $oldTimestamp + 1,
        $GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK . '|' . serialize($siblingArgs)]['timestamp'] ?? null,
        'replacement must preserve a same-hook event with nonempty arguments'
    );
    assert_same(
        $filteredTimestamp,
        $GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK . '|' . serialize(['external-provider'])]['timestamp'] ?? null,
        'replacement must insert the event shape returned by schedule_event'
    );
    assert_true(!isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), 'replacement must remove only the original empty-args singleton');
});

test_case('lifecycle scheduler capability external pre-scheduler leaves the Core watchdog untouched', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $oldTimestamp = time() + 600;
    $original = [
        WP_FTS_Plugin::CRON_HOOK => [
            'timestamp' => $oldTimestamp,
            'hook' => WP_FTS_Plugin::CRON_HOOK,
            'args' => [],
        ],
    ];
    $GLOBALS['wp_fts_test_scheduled'] = $original;
    $scheduleFilterCalled = false;
    $GLOBALS['wp_fts_test_filters']['pre_schedule_event'] = static fn(): bool => true;
    $GLOBALS['wp_fts_test_filters']['schedule_event'] = static function (mixed $event) use (&$scheduleFilterCalled): mixed {
        $scheduleFilterCalled = true;
        return $event;
    };

    assert_true(wp_fts_lifecycle_scheduler_replace(time() + 1), 'an external pre-scheduler success should be accepted');
    assert_same(false, $scheduleFilterCalled, 'pre_schedule_event short-circuit must skip schedule_event');
    assert_same(0, $GLOBALS['wp_fts_test_cron_array_reads'], 'external scheduling must not read the Core cron option');
    assert_same(0, $GLOBALS['wp_fts_test_cron_write_count'], 'external scheduling must not write the Core cron option');
    assert_same($original, $GLOBALS['wp_fts_test_scheduled'], 'external scheduling must retain the existing Core watchdog as a fallback');
});

test_case('lifecycle scheduler capability malformed filter cannot delete the existing watchdog', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $original = [
        WP_FTS_Plugin::CRON_HOOK => [
            'timestamp' => time() + 600,
            'hook' => WP_FTS_Plugin::CRON_HOOK,
            'args' => [],
        ],
    ];
    $GLOBALS['wp_fts_test_scheduled'] = $original;
    $GLOBALS['wp_fts_test_filters']['schedule_event'] = static fn(): bool => false;

    assert_same(false, wp_fts_lifecycle_scheduler_replace(time() + 1), 'a rejected schedule_event must report failure');
    assert_same(0, $GLOBALS['wp_fts_test_cron_array_reads'], 'a rejected filtered event must not read the Core cron option');
    assert_same(0, $GLOBALS['wp_fts_test_cron_write_count'], 'a rejected filtered event must not write the Core cron option');
    assert_same($original, $GLOBALS['wp_fts_test_scheduled'], 'a rejected filtered event must leave the existing watchdog untouched');
});

test_case('lifecycle scheduler capability stale lease race reports the uninstall fence only on failed takeover', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->options = 'wp_options';
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION] = [
        'token' => 'expired-before-uninstall',
        'mode' => 'cron',
        'started_at' => time() - 600,
        'heartbeat_at' => time() - 600,
        'expires_at' => time() - 300,
        'renewals' => 0,
    ];
    $insertAttempts = 0;
    $fake->queryObserver = static function (string $sql) use (&$insertAttempts): void {
        if (
            !str_starts_with($sql, 'INSERT INTO wp_options (option_name,option_value,autoload)')
            || !str_contains($sql, 'ON DUPLICATE KEY UPDATE option_name = option_name')
        ) {
            return;
        }
        $insertAttempts++;
        if ($insertAttempts === 2) {
            $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::UNINSTALL_FENCE_OPTION] = WP_FTS_Plugin::UNINSTALL_FENCE_VALUE;
        }
    };
    $method = new ReflectionMethod(WP_FTS_Plugin::class, 'acquire_index_lock');
    $method->setAccessible(true);
    $blockedReason = null;
    $recovered = false;
    $arguments = ['cron', &$blockedReason, &$recovered];

    try {
        $token = $method->invokeArgs(null, $arguments);
        assert_same(null, $token, 'the fenced second INSERT must not acquire a writer lease');
        assert_same('uninstall_fenced', $blockedReason, 'the failed stale takeover must classify the intervening uninstall fence');
        assert_same(false, $recovered, 'the failed stale takeover must not advertise lease recovery');
        assert_same(2, $insertAttempts, 'the adversarial branch must reach exactly the normal INSERT and stale replacement INSERT');
    } finally {
        $fake->queryObserver = null;
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});
