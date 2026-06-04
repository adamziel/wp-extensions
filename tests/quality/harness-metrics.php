<?php
declare(strict_types=1);

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
}
