<?php
declare(strict_types=1);

$wp_fts_harness_metrics_direct = !function_exists('test_case');
if ($wp_fts_harness_metrics_direct) {
    final class WP_FTS_TestFailure extends RuntimeException
    {
    }

    final class WP_FTS_TestPending extends RuntimeException
    {
    }

    $GLOBALS['wp_fts_harness_metrics_tests'] = [];
    $GLOBALS['wp_fts_harness_metrics_check_count'] = 0;

    function test_case(string $name, callable $fn): void
    {
        $GLOBALS['wp_fts_harness_metrics_tests'][] = ['name' => $name, 'fn' => $fn];
    }

    function record_check(?string $label = null, int $count = 1): void
    {
        if ($count < 1) {
            throw new WP_FTS_TestFailure('record_check() count must be at least 1.');
        }

        $GLOBALS['wp_fts_harness_metrics_check_count'] += $count;
    }

    function executed_check_count(): int
    {
        return (int) $GLOBALS['wp_fts_harness_metrics_check_count'];
    }

    function assert_true(bool $condition, string $message): void
    {
        record_check($message);
        if (!$condition) {
            throw new WP_FTS_TestFailure($message);
        }
    }

    function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        record_check($message);
        if ($expected !== $actual) {
            throw new WP_FTS_TestFailure($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
        }
    }

    function assert_contains(string $needle, string $haystack, string $message): void
    {
        record_check($message);
        if (!str_contains($haystack, $needle)) {
            throw new WP_FTS_TestFailure($message . "\nMissing: " . var_export($needle, true) . "\nIn: " . $haystack);
        }
    }

    function mark_pending(string $message): never
    {
        throw new WP_FTS_TestPending($message);
    }

    /**
     * @param array<string,string> $env
     * @return array{exit:int,stdout:string,stderr:string}
     */
    function test_run_harness_with_environment(array $env): array
    {
        if (!function_exists('proc_open')) {
            mark_pending('proc_open() is unavailable, so the harness subprocess test cannot run in this PHP build.');
        }

        $baseEnv = getenv();
        if (!is_array($baseEnv)) {
            $baseEnv = [];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/run.php'], $descriptors, $pipes, dirname(__DIR__, 2), array_merge($baseEnv, $env));
        if (!is_resource($process)) {
            mark_pending('Could not start a PHP subprocess for the harness metrics test.');
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [
            'exit' => is_int($exit) ? $exit : 1,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}

test_case('quality harness counts assertions and generated checks', function (): void {
    $start = executed_check_count();

    assert_true(true, 'assert_true should increment the executed check counter');
    assert_same($start + 1, executed_check_count(), 'assertion count should include the previous assert_true call');

    $loopStart = executed_check_count();
    foreach ([
        'latin punctuation scenario',
        'cjk script scenario',
        'mixed language scenario',
    ] as $label) {
        record_check($label);
    }

    assert_same($loopStart + 3, executed_check_count(), 'record_check should count generated data-driven scenarios');
});

test_case('quality harness supports batched checks and rejects invalid counts', function (): void {
    $batchStart = executed_check_count();
    record_check('five varied generated scenarios', 5);
    $afterBatch = executed_check_count();
    assert_same($batchStart + 5, $afterBatch, 'record_check should add explicit batch counts');

    $invalidStart = executed_check_count();
    $message = '';
    try {
        record_check('invalid zero-count scenario', 0);
    } catch (WP_FTS_TestFailure $e) {
        $message = $e->getMessage();
    }
    $afterInvalid = executed_check_count();

    assert_contains('at least 1', $message, 'record_check should reject non-positive counts');
    assert_same($invalidStart, $afterInvalid, 'invalid record_check calls should not increment the check counter');
});

if (getenv('WP_FTS_HARNESS_GATE_CHILD') !== '1') {
    test_case('quality harness minimum check gate fails when set too high', function (): void {
        $result = test_run_harness_with_environment([
            'WP_FTS_HARNESS_GATE_CHILD' => '1',
            'WP_FTS_MIN_CHECKS' => '999999',
        ]);

        $output = $result['stdout'] . $result['stderr'];
        assert_true($result['exit'] !== 0, 'minimum check gate child process should exit non-zero');
        assert_contains('[FAIL] minimum check count', $output, 'minimum check gate should report a gate failure');
        assert_contains('required 999999', $output, 'minimum check gate should include the configured threshold');
    });

    test_case('quality harness rejects invalid minimum check configuration', function (): void {
        $result = test_run_harness_with_environment([
            'WP_FTS_HARNESS_GATE_CHILD' => '1',
            'WP_FTS_MIN_CHECKS' => 'not-a-number',
        ]);

        $output = $result['stdout'] . $result['stderr'];
        assert_true($result['exit'] !== 0, 'invalid minimum check child process should exit non-zero');
        assert_contains('[FAIL] minimum check count configuration', $output, 'invalid minimum check config should report a configuration failure');
        assert_contains('WP_FTS_MIN_CHECKS must be a non-negative integer', $output, 'invalid minimum check config should include validation guidance');
    });

    test_case('quality harness strict CI gate rejects pending tests', function (): void {
        $result = test_run_harness_with_environment([
            'WP_FTS_HARNESS_GATE_CHILD' => '1',
            'WP_FTS_HARNESS_GATE_CHILD_PENDING' => '1',
            'WP_FTS_FAIL_ON_PENDING' => '1',
            'WP_FTS_MIN_CHECKS' => '0',
            'WP_FTS_TEST_FILTER' => '',
        ]);

        $output = $result['stdout'] . $result['stderr'];
        assert_true($result['exit'] !== 0, 'strict pending gate child process should exit non-zero');
        assert_contains('[FAIL] pending tests', $output, 'strict pending gate should report a gate failure');
        assert_contains('pending=1', $output, 'strict pending gate summary should retain the pending count');
    });

    test_case('quality harness releases completed cyclic fixtures', function (): void {
        $result = test_run_harness_with_environment([
            'WP_FTS_HARNESS_GATE_CHILD' => '1',
            'WP_FTS_HARNESS_GATE_CHILD_RELEASE' => '1',
            'WP_FTS_MIN_CHECKS' => '2',
            'WP_FTS_TEST_FILTER' => '',
        ]);

        $output = $result['stdout'] . $result['stderr'];
        assert_same(0, $result['exit'], "completed cyclic-fixture release child should pass\n{$output}");
        assert_contains('[PASS] quality harness completed callable release sentinel', $output, 'the next test should observe its predecessor cyclic fixture as released');
        assert_contains('2/2 named tests passed', $output, 'the release child should execute both ordered tests');
    });
}

if ($wp_fts_harness_metrics_direct) {
    $failures = 0;
    $pending = 0;
    $start = microtime(true);
    foreach ($GLOBALS['wp_fts_harness_metrics_tests'] as $test) {
        try {
            ($test['fn'])();
            fwrite(STDOUT, "[PASS] {$test['name']}\n");
        } catch (WP_FTS_TestPending $e) {
            $pending++;
            fwrite(STDOUT, "[PEND] {$test['name']}\n{$e->getMessage()}\n");
        } catch (Throwable $e) {
            $failures++;
            fwrite(STDERR, "[FAIL] {$test['name']}\n{$e->getMessage()}\n{$e->getTraceAsString()}\n");
        }
    }

    $duration = number_format(microtime(true) - $start, 3);
    $count = count($GLOBALS['wp_fts_harness_metrics_tests']);
    $passed = $count - $failures - $pending;
    $checks = (int) $GLOBALS['wp_fts_harness_metrics_check_count'];
    $summary = "{$passed}/{$count} quality harness metrics tests passed; failures={$failures}; pending={$pending}; checks/scenarios={$checks}; duration={$duration}s\n";
    if ($failures > 0) {
        fwrite(STDERR, $summary);
        exit(1);
    }

    fwrite(STDOUT, $summary);
}
