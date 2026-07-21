<?php
declare(strict_types=1);

test_case('quality relational relevance gold locks morphology ranking and visibility', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $wpdb = new WP_FTS_Test_WPDB();
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = array_replace(
        WP_FTS_Plugin::default_settings(),
        [
            'rest_api_enabled' => true,
            'rest_prefix_matching' => false,
        ]
    );

    try {
        $suite = wp_fts_rrg_load_suite(__DIR__ . '/../fixtures/relevance/relational-core.json');
        wp_fts_rrg_assert_composition($suite);

        $documentsById = [];
        $idByNumeric = [];
        $storage = wp_fts_test_unleased_storage();
        $analyzer = WP_FTS_Plugin::runtime_analyzer();
        foreach ($suite['documents'] as $document) {
            $fixtureId = (string) $document['id'];
            $numericId = (int) $document['numeric_id'];
            if (isset($documentsById[$fixtureId]) || isset($idByNumeric[$numericId])) {
                throw new UnexpectedValueException('Relational relevance fixture document IDs must be unique.');
            }

            $documentsById[$fixtureId] = $document;
            $idByNumeric[$numericId] = $fixtureId;
            $post = wp_fts_rrg_post($document);
            $GLOBALS['wp_fts_test_posts'][$numericId] = $post;
            wp_fts_test_replace_post(
                $storage,
                $post,
                ['document_lang' => (string) $document['language']],
                $analyzer
            );
        }
        wp_fts_test_mark_search_takeover_ready();

        $rankings = [];
        foreach ($suite['queries'] as $query) {
            $response = WP_FTS_Plugin::rest_search([
                'q' => (string) $query['query'],
                'lang' => (string) $query['language'],
                'mode' => (string) $query['mode'],
                'limit' => (int) $query['limit'],
            ]);
            assert_true(is_array($response), 'relational relevance REST query should return an array: ' . (string) $query['id']);
            assert_true(is_array($response['results'] ?? null), 'relational relevance REST query should return result rows: ' . (string) $query['id']);

            $actualIds = wp_fts_rrg_fixture_ids($response['results'], $idByNumeric);
            assert_same(
                $query['expected_ranked_ids'],
                $actualIds,
                'relational relevance ranking should match the committed order: ' . (string) $query['id']
            );
            $rankings[(string) $query['id']] = $actualIds;
        }

        $metrics = wp_fts_rrg_metrics($suite['queries'], $rankings, (int) $suite['top_k']);
        foreach (['recall_at_5', 'precision_at_5', 'mrr', 'ndcg_at_5'] as $metric) {
            assert_float_near(
                (float) $suite['expected_metrics'][$metric],
                (float) $metrics[$metric],
                'relational relevance metric should match the committed floor: ' . $metric
            );
        }
        foreach (['cross_language_false_positives', 'hidden_false_positives'] as $metric) {
            assert_same(
                (int) $suite['expected_metrics'][$metric],
                (int) $metrics[$metric],
                'relational relevance isolation metric should remain zero: ' . $metric
            );
        }

        record_check('relational relevance committed query rows', count($rankings));
    } finally {
        $wpdb = $oldWpdb;
    }
});

/** @return array<string,mixed> */
function wp_fts_rrg_load_suite(string $path): array
{
    $json = file_get_contents($path);
    if (!is_string($json)) {
        throw new RuntimeException('Could not read the relational relevance fixture.');
    }
    $suite = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($suite)) {
        throw new UnexpectedValueException('Relational relevance fixture root must be an object.');
    }
    foreach (['schema', 'top_k', 'expected_metrics', 'documents', 'queries'] as $key) {
        if (!array_key_exists($key, $suite)) {
            throw new UnexpectedValueException('Relational relevance fixture is missing ' . $key . '.');
        }
    }
    if (!is_array($suite['expected_metrics']) || !is_array($suite['documents']) || !is_array($suite['queries'])) {
        throw new UnexpectedValueException('Relational relevance fixture collections must be arrays.');
    }

    return $suite;
}

/** @param array<string,mixed> $suite */
function wp_fts_rrg_assert_composition(array $suite): void
{
    assert_same('wp-fts-relational-relevance-gold-v1', $suite['schema'], 'relational relevance fixture should use the current schema');
    assert_same(5, $suite['top_k'], 'relational relevance fixture should keep the fixed metric cutoff');
    assert_same(14, count($suite['documents']), 'relational relevance fixture should contain exactly 14 documents');
    assert_same(4, count($suite['queries']), 'relational relevance fixture should contain exactly four queries');

    $documentFamilies = [];
    foreach ($suite['documents'] as $document) {
        if (!is_array($document)) {
            throw new UnexpectedValueException('Relational relevance documents must be objects.');
        }
        foreach (['id', 'numeric_id', 'language', 'family', 'visibility', 'title', 'content'] as $key) {
            if (!array_key_exists($key, $document)) {
                throw new UnexpectedValueException('Relational relevance document is missing ' . $key . '.');
            }
        }
        $documentFamilies[(string) $document['family']] = true;
    }

    $queryFamilies = [];
    $crossLanguageBaits = [];
    $hiddenBaits = [];
    foreach ($suite['queries'] as $query) {
        if (!is_array($query)) {
            throw new UnexpectedValueException('Relational relevance queries must be objects.');
        }
        foreach (['id', 'family', 'query', 'language', 'mode', 'limit', 'judgments', 'expected_ranked_ids'] as $key) {
            if (!array_key_exists($key, $query)) {
                throw new UnexpectedValueException('Relational relevance query is missing ' . $key . '.');
            }
        }
        if (!is_array($query['judgments']) || !is_array($query['expected_ranked_ids'])) {
            throw new UnexpectedValueException('Relational relevance query judgments and rankings must be arrays.');
        }
        $queryFamilies[(string) $query['family']] = true;
        foreach ($query['cross_language_irrelevant'] ?? [] as $fixtureId) {
            $crossLanguageBaits[(string) $fixtureId] = true;
        }
        foreach ($query['hidden_irrelevant'] ?? [] as $fixtureId) {
            $hiddenBaits[(string) $fixtureId] = true;
        }
    }

    $expectedFamilies = [
        'german-folding',
        'polish-morphology',
        'wordpress-field-ranking',
        'wordpress-visibility',
    ];
    $actualDocumentFamilies = array_keys($documentFamilies);
    $actualQueryFamilies = array_keys($queryFamilies);
    sort($actualDocumentFamilies, SORT_STRING);
    sort($actualQueryFamilies, SORT_STRING);
    assert_same($expectedFamilies, $actualDocumentFamilies, 'relational relevance documents should cover exactly four current families');
    assert_same($expectedFamilies, $actualQueryFamilies, 'relational relevance queries should cover exactly four current families');
    assert_same(2, count($crossLanguageBaits), 'relational relevance fixture should contain exactly two cross-language baits');
    assert_same(4, count($hiddenBaits), 'relational relevance fixture should contain exactly four hidden visibility baits');
}

/** @param array<string,mixed> $document */
function wp_fts_rrg_post(array $document): object
{
    $visibility = (string) $document['visibility'];

    return (object) [
        'ID' => (int) $document['numeric_id'],
        'post_title' => (string) $document['title'],
        'post_content' => (string) $document['content'],
        'post_excerpt' => (string) ($document['excerpt'] ?? ''),
        'post_status' => (string) ($document['post_status'] ?? (in_array($visibility, ['private', 'private_readable'], true) ? 'private' : 'publish')),
        'post_type' => (string) ($document['post_type'] ?? ($visibility === 'excluded_type' ? 'secret' : 'post')),
        'post_password' => (string) ($document['post_password'] ?? ($visibility === 'password' ? 'secret' : '')),
        'post_date_gmt' => '2026-06-10 00:00:00',
    ];
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @param array<int,string> $idByNumeric
 * @return string[]
 */
function wp_fts_rrg_fixture_ids(array $rows, array $idByNumeric): array
{
    $fixtureIds = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !is_int($row['doc_id'] ?? null) || !isset($idByNumeric[$row['doc_id']])) {
            throw new UnexpectedValueException('Relational relevance search returned an unknown document row.');
        }
        $fixtureIds[] = $idByNumeric[$row['doc_id']];
    }

    return $fixtureIds;
}

/**
 * @param array<int,array<string,mixed>> $queries
 * @param array<string,string[]> $rankings
 * @return array{recall_at_5:float,precision_at_5:float,mrr:float,ndcg_at_5:float,cross_language_false_positives:int,hidden_false_positives:int}
 */
function wp_fts_rrg_metrics(array $queries, array $rankings, int $topK): array
{
    $recall = 0.0;
    $precision = 0.0;
    $reciprocalRank = 0.0;
    $ndcg = 0.0;
    $crossLanguageFalsePositives = 0;
    $hiddenFalsePositives = 0;

    foreach ($queries as $query) {
        $actual = array_slice($rankings[(string) $query['id']], 0, $topK);
        $judgments = $query['judgments'];
        $relevantIds = array_keys($judgments);
        $retrievedRelevant = array_values(array_intersect($actual, $relevantIds));
        $recall += count($retrievedRelevant) / count($relevantIds);
        $precision += count($retrievedRelevant) / $topK;

        foreach ($actual as $rank => $fixtureId) {
            if (array_key_exists($fixtureId, $judgments)) {
                $reciprocalRank += 1.0 / ($rank + 1);
                break;
            }
        }

        $dcg = wp_fts_rrg_dcg($actual, $judgments, $topK);
        $idealIds = $relevantIds;
        usort($idealIds, static fn(string $left, string $right): int => (int) $judgments[$right] <=> (int) $judgments[$left]);
        $idealDcg = wp_fts_rrg_dcg($idealIds, $judgments, $topK);
        $ndcg += $idealDcg > 0.0 ? $dcg / $idealDcg : 1.0;

        $crossLanguageFalsePositives += count(array_intersect($actual, $query['cross_language_irrelevant'] ?? []));
        $hiddenFalsePositives += count(array_intersect($actual, $query['hidden_irrelevant'] ?? []));
    }

    $queryCount = count($queries);
    return [
        'recall_at_5' => $recall / $queryCount,
        'precision_at_5' => $precision / $queryCount,
        'mrr' => $reciprocalRank / $queryCount,
        'ndcg_at_5' => $ndcg / $queryCount,
        'cross_language_false_positives' => $crossLanguageFalsePositives,
        'hidden_false_positives' => $hiddenFalsePositives,
    ];
}

/** @param string[] $rankedIds @param array<string,int> $judgments */
function wp_fts_rrg_dcg(array $rankedIds, array $judgments, int $topK): float
{
    $dcg = 0.0;
    foreach (array_slice($rankedIds, 0, $topK) as $rank => $fixtureId) {
        $grade = (int) ($judgments[$fixtureId] ?? 0);
        $dcg += ((2 ** $grade) - 1) / log($rank + 2, 2);
    }

    return $dcg;
}
