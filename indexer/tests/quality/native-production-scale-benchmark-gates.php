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
        'memory_delta_bytes',
    ] as $metric) {
        assert_true(array_key_exists($metric, $metrics), "production-scale benchmark should report {$metric}");
    }

    foreach ($result['gates'] as $gate) {
        assert_true((bool) $gate['passed'], 'production-scale benchmark gate should pass: ' . (string) $gate['metric']);
    }
    foreach ($result['query_checks'] as $query) {
        assert_true((bool) $query['passed'], 'production-scale benchmark query should pass: ' . (string) $query['id']);
    }
    foreach ($result['result_windows'] as $window) {
        assert_true((bool) $window['passed'], 'production-scale benchmark hydrated result window should pass: ' . (string) $window['id']);
    }

    assert_same($profiles['pr-safe']['documents'], (int) $metrics['indexed_documents'], 'PR-safe benchmark should index the configured document count');
    assert_true((int) $metrics['hydrated_result_rows'] >= 24, 'PR-safe benchmark should hydrate bounded result windows');
    assert_true((int) $metrics['multi_token_checks_passed'] >= 2, 'PR-safe benchmark should include multi-token search gates');
    assert_true((int) $metrics['folding_checks_passed'] >= 1, 'PR-safe benchmark should include a folding search gate');

    record_check('production-scale benchmark gates', count($result['gates']));
});
