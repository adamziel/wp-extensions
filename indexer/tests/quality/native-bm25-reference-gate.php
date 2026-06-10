<?php
declare(strict_types=1);

require_once __DIR__ . '/../bm25-reference-gate.php';

/**
 * @param array<string,mixed> $result
 */
function wp_fts_bm25_reference_gate_failure_report(array $result): string
{
    return "\n" . WP_FTS_BM25_Reference_Gate::format_text($result);
}

test_case('quality native BM25 reference gate matches deterministic oracle', function (): void {
    $result = WP_FTS_BM25_Reference_Gate::run();

    assert_same(WP_FTS_BM25_Reference_Gate::SCHEMA, $result['schema'], 'native BM25 reference gate should report its schema');
    assert_true((bool) $result['passed'], 'native BM25 reference gate should pass' . wp_fts_bm25_reference_gate_failure_report($result));
    assert_true((bool) $result['postings']['match'], 'native BM25 reference gate postings should match weighted fixture frequencies');
    assert_true((float) $result['max_delta'] <= WP_FTS_BM25_Reference_Gate::EPSILON, 'native BM25 reference gate max delta should stay within tolerance');
    assert_same(4, (int) $result['corpus']['doc_count'], 'native BM25 reference gate should cover four fixture documents');
    assert_same([], $result['optional_dependencies'], 'native BM25 reference gate should not require optional dependencies');
    assert_same([], $result['failures'], 'native BM25 reference gate should not report failures');

    foreach ($result['queries'] as $query) {
        assert_same($query['expected_top_ids'], $query['oracle_top_ids'], 'BM25 oracle OR expected order should stay fixed: ' . (string) $query['id']);
        assert_same($query['oracle_top_ids'], $query['native_top_ids'], 'BM25 native OR order should match oracle: ' . (string) $query['id']);
        assert_true((float) $query['max_delta'] <= WP_FTS_BM25_Reference_Gate::EPSILON, 'BM25 native OR score delta should match oracle: ' . (string) $query['id']);
        assert_same($query['expected_and_top_ids'], $query['and_oracle_top_ids'], 'BM25 oracle AND expected order should stay fixed: ' . (string) $query['id']);
        assert_same($query['and_oracle_top_ids'], $query['and_native_top_ids'], 'BM25 native AND order should match oracle: ' . (string) $query['id']);
        assert_true((float) $query['and_max_delta'] <= WP_FTS_BM25_Reference_Gate::EPSILON, 'BM25 native AND score delta should match oracle: ' . (string) $query['id']);
    }

    record_check('native BM25 reference gate query comparisons', count($result['queries']));
});

test_case('quality native BM25 reference gate emits reproducible JSON', function (): void {
    $result = WP_FTS_BM25_Reference_Gate::run();
    $json = WP_FTS_BM25_Reference_Gate::to_json($result);
    $decoded = json_decode($json, true);

    assert_true(is_array($decoded), 'native BM25 reference gate JSON should decode');
    assert_same(WP_FTS_BM25_Reference_Gate::SCHEMA, $decoded['schema'] ?? null, 'native BM25 reference gate JSON should include schema');
    assert_same(true, $decoded['passed'] ?? null, 'native BM25 reference gate JSON should include pass status');
    assert_true(str_contains($json, '"oracle_top_ids"'), 'native BM25 reference gate JSON should include oracle rankings');
    assert_true(str_contains($json, '"native_top_ids"'), 'native BM25 reference gate JSON should include native rankings');
    assert_true(str_contains($json, '"max_delta"'), 'native BM25 reference gate JSON should include score deltas');
});
