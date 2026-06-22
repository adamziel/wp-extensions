<?php
declare(strict_types=1);

require_once __DIR__ . '/../production-scale-benchmark.php';

test_case('quality native production-scale benchmark gates pass pure-PHP generated evidence only', function (): void {
    $profiles = WP_FTS_Production_Scale_Benchmark::profiles();
    assert_true(isset($profiles['pr-safe'], $profiles['expanded']), 'production-scale benchmark should expose PR-safe and expanded corpus sizes');
    assert_true($profiles['expanded']['documents'] > $profiles['pr-safe']['documents'], 'expanded production-scale profile should be larger than PR-safe');

    $result = WP_FTS_Production_Scale_Benchmark::run();
    assert_true((bool) $result['passed'], 'production-scale generated benchmark gates should pass' . "\n" . WP_FTS_Production_Scale_Benchmark::format_text($result));
    assert_contains('pure-PHP generated corpus evidence only', (string) $result['evidence'], 'benchmark evidence note should identify generated pure-PHP scope');
    assert_contains('not live MySQL proof', (string) $result['evidence'], 'benchmark evidence note should not claim live MySQL proof');
    assert_contains('not production traffic proof', (string) $result['evidence'], 'benchmark evidence note should not claim production traffic proof');

    $metrics = $result['metrics'];
    foreach ([
        'indexed_documents',
        'raw_token_occurrences',
        'weighted_token_instances',
        'unique_terms',
        'posting_rows',
        'materialized_rows',
        'hydrated_result_rows',
        'index_duration_ms',
        'query_check_total_duration_ms',
        'query_check_max_duration_ms',
        'result_window_total_duration_ms',
        'result_window_max_duration_ms',
        'search_read_total_duration_ms',
        'memory_delta_bytes',
    ] as $metric) {
        assert_true(array_key_exists($metric, $metrics), "production-scale benchmark should report {$metric}");
        if (str_ends_with($metric, '_duration_ms')) {
            assert_true((int) $metrics[$metric] >= 0, "production-scale benchmark duration should be nonnegative for {$metric}");
        }
    }

    $performanceGateCount = 0;
    foreach ($result['gates'] as $gate) {
        assert_true((bool) $gate['passed'], 'production-scale benchmark gate should pass: ' . (string) $gate['metric']);
        assert_true(in_array((string) ($gate['category'] ?? ''), ['structural', 'performance'], true), 'production-scale benchmark gate should classify structural or performance evidence');
        if (($gate['category'] ?? '') === 'performance') {
            $performanceGateCount++;
        }
    }
    assert_true($performanceGateCount >= 4, 'production-scale benchmark should include explicit performance budget gates');

    foreach ($result['query_checks'] as $query) {
        assert_true((bool) $query['passed'], 'production-scale benchmark query should pass: ' . (string) $query['id']);
        assert_true(array_key_exists('duration_ms', $query), 'production-scale benchmark query checks should report bounded timing evidence');
        assert_true((int) $query['duration_ms'] >= 0, 'production-scale benchmark query check durations should be nonnegative');
    }
    foreach ($result['result_windows'] as $window) {
        assert_true((bool) $window['passed'], 'production-scale benchmark hydrated result window should pass: ' . (string) $window['id']);
        assert_true(array_key_exists('duration_ms', $window), 'production-scale benchmark result windows should report bounded timing evidence');
        assert_true((int) $window['duration_ms'] >= 0, 'production-scale benchmark result window durations should be nonnegative');
    }

    $performanceBudget = is_array($result['performance_budget'] ?? null) ? $result['performance_budget'] : [];
    $budgetMetrics = is_array($performanceBudget['metrics'] ?? null) ? $performanceBudget['metrics'] : [];
    $budgetCounts = is_array($performanceBudget['gate_counts'] ?? null) ? $performanceBudget['gate_counts'] : [];
    assert_true(array_key_exists('search_read_total_duration_ms', $budgetMetrics), 'production-scale benchmark should summarize search/read timing in performance budget evidence');
    assert_true((int) ($budgetCounts['pass'] ?? 0) >= 4, 'production-scale benchmark should summarize passed performance budget gates');
    assert_same(0, (int) ($budgetCounts['fail'] ?? -1), 'production-scale benchmark should have no default performance budget failures');
    assert_same([], $performanceBudget['failed_gates'] ?? null, 'production-scale benchmark should list no failed performance gates by default');

    assert_same($profiles['pr-safe']['documents'], (int) $metrics['indexed_documents'], 'PR-safe benchmark should index the configured document count');
    assert_true((int) $metrics['hydrated_result_rows'] >= 24, 'PR-safe benchmark should hydrate bounded result windows');
    assert_true((int) $metrics['multi_token_checks_passed'] >= 2, 'PR-safe benchmark should include multi-token search gates');
    assert_true((int) $metrics['folding_checks_passed'] >= 1, 'PR-safe benchmark should include a folding search gate');

    record_check('production-scale benchmark gates', count($result['gates']));
});
