<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/cranfield-relevance-gate.php';

test_case('quality Cranfield importer builds native relevance suite from source-shaped fixtures', function (): void {
    $suite = WP_FTS_Cranfield_Relevance_Gate::build_suite_from_dir(WP_FTS_Cranfield_Relevance_Gate::fixture_dir());

    assert_same(WP_FTS_Cranfield_Relevance_Gate::SUITE_SCHEMA, $suite['schema'], 'Cranfield importer should emit the native relevance suite schema');
    assert_same(3, count($suite['documents']), 'synthetic Cranfield fixture should import all documents');
    assert_same(3, count($suite['queries']), 'synthetic Cranfield fixture should import all queries');
    assert_same('cranfield-1', $suite['documents'][0]['id'], 'document ids should be namespaced for fixture compatibility');
    assert_same('cranfield-q-1', $suite['queries'][0]['id'], 'query ids should be namespaced for fixture compatibility');
    assert_same(2, $suite['queries'][0]['judgments']['cranfield-1'] ?? null, 'three-column qrels should become graded judgments');
    assert_same(1, $suite['queries'][0]['judgments']['cranfield-3'] ?? null, 'four-column qrels should become graded judgments');
    assert_same('title', $suite['documents'][0]['fields'][0]['name'], 'Cranfield .T title should become a weighted title field');
    assert_same('content', $suite['documents'][0]['fields'][2]['name'], 'Cranfield .W text should become content when .A is present and .B is absent');
});

test_case('quality Cranfield importer omits fully empty official records and matching qrels', function (): void {
    $suite = WP_FTS_Cranfield_Relevance_Gate::build_suite_from_dir(WP_FTS_Cranfield_Relevance_Gate::empty_records_fixture_dir());

    assert_same(1, count($suite['documents']), 'empty-record fixture should import only the non-empty document');
    assert_same('cranfield-1', $suite['documents'][0]['id'], 'non-empty document should keep the expected namespaced id');
    assert_same(2, (int) ($suite['omitted_documents']['count'] ?? -1), 'fully empty records should be reported as omitted');
    assert_same(['471', '995'], $suite['omitted_documents']['ids'] ?? [], 'omitted document ids should be deterministic');
    assert_same(2, count($suite['queries']), 'queries should still import when some judgments target omitted documents');
    assert_same('cranfield-q-4', $suite['queries'][1]['id'], 'official ordinal qrels should map to non-contiguous query record ids');
    assert_same(['cranfield-1' => 2], $suite['queries'][0]['judgments'], 'qrels for omitted judged documents should be dropped from mixed judged queries');
    assert_same([], $suite['queries'][1]['judgments'], 'queries judged only against omitted documents should become unjudged');

    $result = WP_FTS_Cranfield_Relevance_Gate::run(WP_FTS_Cranfield_Relevance_Gate::empty_records_fixture_dir(), [
        'max_ndcg_delta' => 0.000001,
        'max_map_delta' => 0.000001,
        'max_precision_at_5_delta' => 0.000001,
    ]);

    assert_true((bool) $result['passed'], 'empty-record fixture gate should still produce relevance results: ' . WP_FTS_Cranfield_Relevance_Gate::format_text($result));
    assert_same(1, (int) $result['documents']['count'], 'empty-record fixture gate should index only non-empty documents');
    assert_same(1, (int) $result['queries']['judged_count'], 'empty-record fixture gate should score the remaining judged query');
    assert_same('cranfield-q-1', $result['query_results'][0]['id'] ?? '', 'remaining judged query should be reported');
});

test_case('quality Cranfield importer still rejects partially populated documents without W text', function (): void {
    $dir = WP_FTS_Cranfield_Relevance_Gate::empty_records_fixture_dir();
    try {
        WP_FTS_Cranfield_Relevance_Gate::build_suite(
            $dir . '/cran.malformed.no-w',
            $dir . '/cran.qry',
            $dir . '/qrels.text'
        );
    } catch (RuntimeException $e) {
        assert_contains('Cranfield document 2 has no .W content.', $e->getMessage(), 'partially populated no-W document should remain malformed');
        return;
    }

    assert_true(false, 'partially populated no-W document should throw');
});

test_case('quality Cranfield metrics compute nDCG@10 MAP and P@5', function (): void {
    $judgments = [
        'doc-a' => 3,
        'doc-b' => 2,
        'doc-c' => 1,
    ];
    $metrics = WP_FTS_Cranfield_Relevance_Gate::metrics_for_ranked_ids(['doc-b', 'doc-x', 'doc-a', 'doc-c'], $judgments, 10);

    assert_float_near(0.6, (float) $metrics['precision_at_5'], 'Cranfield P@5 should count relevant hits over five ranks');
    assert_float_near(1.0, (float) $metrics['recall_at_10'], 'Cranfield recall@10 should count all relevant hits in the top ten');
    assert_float_near(0.8254499218587349, (float) $metrics['ndcg_at_10'], 'Cranfield nDCG@10 should use graded judgments and log discounting');
    assert_float_near(((1 / 1) + (2 / 3) + (3 / 4)) / 3, (float) $metrics['map'], 'Cranfield MAP should average precision at relevant ranks');

    $aggregate = WP_FTS_Cranfield_Relevance_Gate::aggregate_metrics([$metrics, $metrics]);
    assert_float_near((float) $metrics['ndcg_at_10'], $aggregate['ndcg_at_10'], 'Cranfield aggregate metrics should average per-query nDCG');
});

test_case('quality Cranfield mini gate compares native search with local BM25 reference', function (): void {
    $result = WP_FTS_Cranfield_Relevance_Gate::run(WP_FTS_Cranfield_Relevance_Gate::fixture_dir(), [
        'max_ndcg_delta' => 0.000001,
        'max_map_delta' => 0.000001,
        'max_precision_at_5_delta' => 0.000001,
    ]);

    assert_same(WP_FTS_Cranfield_Relevance_Gate::SCHEMA, $result['schema'], 'Cranfield gate should report its schema');
    assert_true((bool) $result['passed'], 'synthetic Cranfield gate should pass: ' . WP_FTS_Cranfield_Relevance_Gate::format_text($result));
    assert_same(3, (int) $result['documents']['count'], 'synthetic Cranfield gate should index all fixture documents');
    assert_same(3, (int) $result['queries']['judged_count'], 'synthetic Cranfield gate should score all judged fixture queries');
    assert_true(isset($result['metrics']['native']['ndcg_at_10']), 'Cranfield gate should report native nDCG@10');
    assert_true(isset($result['metrics']['reference']['map']), 'Cranfield gate should report reference MAP');
    assert_true((float) $result['metrics']['deltas']['ndcg_at_10'] <= 0.000001, 'synthetic Cranfield native/reference nDCG delta should stay exact');
});

test_case('quality full Cranfield relevance gate is pending without operator data', function (): void {
    $dir = getenv('WP_FTS_CRANFIELD_DIR');
    if (!is_string($dir) || trim($dir) === '') {
        $result = WP_FTS_Cranfield_Relevance_Gate::run(null);
        assert_same('pending', $result['status'], 'full Cranfield gate should report pending when no local corpus path is configured');
        assert_contains('WP_FTS_CRANFIELD_DIR', WP_FTS_Cranfield_Relevance_Gate::format_text($result), 'pending full Cranfield report should name the required env var');
        mark_pending((string) $result['reason']);
    }

    $result = WP_FTS_Cranfield_Relevance_Gate::run($dir);
    assert_true((bool) $result['passed'], 'full operator-provided Cranfield gate should pass when configured: ' . WP_FTS_Cranfield_Relevance_Gate::format_text($result));
});
