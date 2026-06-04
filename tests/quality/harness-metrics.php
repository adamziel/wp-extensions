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
}
