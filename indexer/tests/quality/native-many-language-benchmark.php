<?php
declare(strict_types=1);

require_once __DIR__ . '/../native-many-language-benchmark.php';

test_case('quality native many-language generated benchmark passes', function (): void {
    $result = WP_FTS_Native_Many_Language_Benchmark::run();
    assert_true((bool) $result['passed'], 'native many-language benchmark should pass' . "\n" . WP_FTS_Native_Many_Language_Benchmark::format_text($result));
    assert_same(16, (int) $result['language_count'], 'native many-language benchmark should generate sixteen synthetic language partitions');
    assert_true((int) $result['document_count'] >= 24, 'native many-language benchmark should seed route targets and bait documents');

    $metrics = $result['metrics'];
    assert_same(5, (int) $metrics['selected_partition_count_max'], 'native many-language benchmark should exercise the five-partition cap');
    assert_true((int) $metrics['preflight_scenario_count'] >= 4, 'native many-language benchmark should exercise bounded preflight fallback scenarios');
    assert_true((int) $metrics['fetch_postings_calls_total'] > 0, 'native many-language benchmark should fetch postings through native search');
    assert_true((int) $metrics['postings_rows_materialized_total'] > 0, 'native many-language benchmark should materialize postings rows');
    assert_true((int) $metrics['term_language_hit_rows_fetched_total'] > 0, 'native many-language benchmark should count preflight term-language checks');

    $scenarioIds = [];
    foreach ($result['scenarios'] as $scenario) {
        $scenarioIds[(string) $scenario['id']] = $scenario;
        assert_true((bool) $scenario['passed'], 'native many-language benchmark scenario should pass: ' . (string) $scenario['id']);
        assert_true((int) $scenario['counters']['selected_partition_count'] <= 5, 'scenario selected partitions should stay capped: ' . (string) $scenario['id']);
        assert_same(0, (int) $scenario['counters']['field_metadata_rows_fetched'], 'scenario should not fetch field metadata: ' . (string) $scenario['id']);
    }

    foreach ([
        'exact-profile-route',
        'morphology-profile-route',
        'single-token-synonym-route',
        'no-evidence-over-cap-fallback',
        'ambiguous-evidence-over-cap-fallback',
        'runtime-all-hit-cap',
        'false-positive-short-form-guard',
    ] as $scenarioId) {
        assert_true(isset($scenarioIds[$scenarioId]), 'native many-language benchmark should include scenario ' . $scenarioId);
    }

    assert_same(
        ['qaa-cp00-exact'],
        $scenarioIds['exact-profile-route']['selected_partitions'],
        'exact evidence should route to the generated exact partition only'
    );
    assert_true(
        in_array('qaa-cp10-noevidence-target', $scenarioIds['no-evidence-over-cap-fallback']['selected_partitions'], true),
        'no-evidence fallback should preflight-select the over-cap target partition'
    );
    assert_true(
        in_array('qaa-cp08-amb-target', $scenarioIds['ambiguous-evidence-over-cap-fallback']['selected_partitions'], true),
        'ambiguous fallback should preflight-select the over-cap ambiguous target partition'
    );
    assert_same(
        [],
        $scenarioIds['false-positive-short-form-guard']['top_ids'],
        'short-form guard should not produce false-positive search results'
    );

    record_check('native many-language benchmark scenario rows', count($result['scenarios']));
});
