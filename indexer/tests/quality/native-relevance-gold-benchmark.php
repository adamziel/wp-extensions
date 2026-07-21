<?php
declare(strict_types=1);

require_once __DIR__ . '/../relevance-benchmark.php';

/**
 * @param array<string,mixed> $result
 */
function wp_fts_nrgb_failure_report(array $result): string
{
    return "\n" . WP_FTS_Relevance_Benchmark::format_text($result);
}

/**
 * @param array<string,mixed> $document
 */
function wp_fts_nrgb_post_from_document(array $document): object
{
    $visibility = (string) ($document['visibility'] ?? 'public');
    $postStatus = (string) ($document['post_status'] ?? ($visibility === 'private' || $visibility === 'private_readable' ? 'private' : 'publish'));
    $postType = (string) ($document['post_type'] ?? ($visibility === 'excluded_type' ? 'secret' : 'post'));
    $postPassword = (string) ($document['post_password'] ?? ($visibility === 'password' ? 'secret' : ''));

    return (object) [
        'ID' => (int) ($document['numeric_id'] ?? 0),
        'post_title' => (string) ($document['title'] ?? $document['id'] ?? ''),
        'post_content' => (string) ($document['content'] ?? $document['html'] ?? ''),
        'post_excerpt' => (string) ($document['excerpt'] ?? ''),
        'post_status' => $postStatus,
        'post_type' => $postType,
        'post_password' => $postPassword,
        'post_date_gmt' => (string) ($document['post_date_gmt'] ?? '2026-06-10 00:00:00'),
    ];
}

/**
 * @param array<int,array<string,mixed>> $documents
 * @return array{by_id:array<string,array<string,mixed>>,id_by_numeric:array<int,string>}
 */
function wp_fts_nrgb_document_maps(array $documents): array
{
    $byId = [];
    $idByNumeric = [];
    foreach ($documents as $document) {
        if (!is_array($document)) {
            continue;
        }
        $fixtureId = (string) ($document['id'] ?? '');
        $numericId = (int) ($document['numeric_id'] ?? 0);
        if ($fixtureId === '' || $numericId <= 0) {
            continue;
        }
        $byId[$fixtureId] = $document;
        $idByNumeric[$numericId] = $fixtureId;
    }

    return ['by_id' => $byId, 'id_by_numeric' => $idByNumeric];
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @param array<int,string> $idByNumeric
 * @return string[]
 */
function wp_fts_nrgb_fixture_ids_from_plugin_rows(array $rows, array $idByNumeric): array
{
    $ids = [];
    foreach ($rows as $row) {
        $docId = (int) ($row['doc_id'] ?? 0);
        if (isset($idByNumeric[$docId])) {
            $ids[] = $idByNumeric[$docId];
        }
    }

    return $ids;
}

test_case('quality native relevance gold benchmark passes committed fixture', function (): void {
    $result = WP_FTS_Relevance_Benchmark::run(WP_FTS_Relevance_Benchmark::default_suite_path());
    assert_true((bool) $result['passed'], 'native relevance benchmark should pass committed fixture' . wp_fts_nrgb_failure_report($result));

    $metrics = $result['metrics'];
    foreach (['recall_at_5', 'precision_at_5', 'mrr', 'ndcg_at_5', 'cross_language_false_positive_count'] as $metric) {
        assert_true(array_key_exists($metric, $metrics), "native relevance benchmark should report {$metric}");
    }

    foreach ($result['thresholds'] as $threshold) {
        assert_true((bool) $threshold['passed'], 'native relevance threshold should pass: ' . (string) $threshold['metric']);
    }

    assert_true((int) $result['documents']['count'] >= 30, 'native relevance fixture should include the expanded 30-document corpus');
    assert_true((int) $result['queries']['retrieval_count'] >= 20, 'native relevance fixture should include at least 20 retrieval queries');
    assert_true((int) $metrics['cross_language_bait_checks'] >= 18, 'native relevance fixture should include expanded cross-language bait checks');
    assert_same(0, (int) $metrics['cross_language_false_positive_count'], 'native relevance fixture should not allow cross-language false positives');
    assert_same(0, (int) $metrics['no_result_expectation_failures'], 'native relevance no-result expectations should pass');
    assert_same(0, (int) $metrics['top_id_expectation_failures'], 'native relevance top-id expectations should pass');

    foreach ($result['composition']['required_families'] as $family) {
        assert_true(in_array($family, $result['composition']['families'], true), "native relevance fixture should include {$family}");
    }
    foreach ($result['query_results'] as $query) {
        assert_same([], $query['failures'], 'native relevance query should have no expectation failures: ' . (string) $query['id']);
    }

    $queryIds = array_column($result['query_results'], 'id');
    foreach ([
        'wp-field-title-excerpt-body-ranking',
        'pl-raportami-default-stem',
        'de-ascii-folded-query',
        'de-detected-umlaut-folding',
        'mixed-field-polish-excerpt',
        'fallback-ambiguous-default-field',
    ] as $queryId) {
        assert_true(in_array($queryId, $queryIds, true), "native relevance fixture should include expanded probe {$queryId}");
    }

    record_check('native relevance benchmark query rows', count($result['query_results']));
});

test_case('quality native relevance REST fixture runs through WordPress search surface', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = array_replace(
        WP_FTS_Plugin::default_settings(),
        ['rest_api_enabled' => true]
    );
    $GLOBALS['wp_fts_test_current_user_id'] = 9;

    try {
        $suite = WP_FTS_Relevance_Benchmark::load_suite(WP_FTS_Relevance_Benchmark::default_suite_path());
        $maps = wp_fts_nrgb_document_maps($suite['documents']);
        $idByNumeric = $maps['id_by_numeric'];
        $documentsById = $maps['by_id'];

        $storage = wp_fts_test_unleased_storage();
        $analyzer = new WP_FTS_Analyzer();
        foreach ($documentsById as $fixtureId => $document) {
            if (($document['family'] ?? '') !== 'rest-visible-hidden-ranking') {
                continue;
            }
            $post = wp_fts_nrgb_post_from_document($document);
            $GLOBALS['wp_fts_test_posts'][$post->ID] = $post;
            $opts = [];
            if (isset($document['language']) && is_scalar($document['language'])) {
                $opts['lang'] = (string) $document['language'];
            }
            wp_fts_test_replace_post($storage, $post, $opts, $analyzer);
        }

        foreach ($suite['queries'] as $query) {
            if (!is_array($query) || ($query['surface'] ?? '') !== 'plugin_rest') {
                continue;
            }

            $GLOBALS['wp_fts_test_caps'] = [];
            foreach (($query['capabilities']['read_post'] ?? []) as $fixtureId) {
                if (is_scalar($fixtureId) && isset($documentsById[(string) $fixtureId])) {
                    $GLOBALS['wp_fts_test_caps']['read_post'][(int) $documentsById[(string) $fixtureId]['numeric_id']] = true;
                }
            }

            $queryParam = !empty($query['rest_alias']) ? 'query' : 'q';
            $request = [
                $queryParam => (string) ($query['query'] ?? ''),
                'mode' => (string) ($query['mode'] ?? 'OR'),
                'limit' => (int) ($query['limit'] ?? 10),
            ];
            if (isset($query['language']) && is_scalar($query['language'])) {
                $request['lang'] = (string) $query['language'];
            }

            $response = WP_FTS_Plugin::rest_search($request);
            assert_true(is_array($response) && isset($response['results']) && is_array($response['results']), 'REST benchmark query should return a result array: ' . (string) $query['id']);
            $actualIds = wp_fts_nrgb_fixture_ids_from_plugin_rows($response['results'], $idByNumeric);
            foreach ($actualIds as $fixtureId) {
                $post = wp_fts_nrgb_post_from_document($documentsById[$fixtureId]);
                assert_same('publish', $post->post_status, 'REST benchmark should return only published canonical rows: ' . (string) $query['id']);
                assert_same('', $post->post_password, 'REST benchmark should exclude password-protected canonical rows: ' . (string) $query['id']);
                assert_true(in_array($post->post_type, ['post', 'page'], true), 'REST benchmark should return only configured public post types: ' . (string) $query['id']);
            }

            $expectedTopIds = isset($query['expect']['top_ids']) && is_array($query['expect']['top_ids'])
                ? array_values(array_map('strval', $query['expect']['top_ids']))
                : [];
            if ($expectedTopIds !== []) {
                assert_same($expectedTopIds, array_slice($actualIds, 0, count($expectedTopIds)), 'REST benchmark top prefix should match fixture expectation: ' . (string) $query['id']);
            }

            foreach (($query['irrelevant'] ?? []) as $fixtureId) {
                if (is_scalar($fixtureId)) {
                    assert_true(!in_array((string) $fixtureId, $actualIds, true), 'REST benchmark should filter hidden fixture id ' . (string) $fixtureId . ' for ' . (string) $query['id']);
                }
            }
            foreach (array_keys($query['judgments'] ?? []) as $fixtureId) {
                $fixtureId = (string) $fixtureId;
                $judgedPost = wp_fts_nrgb_post_from_document($documentsById[$fixtureId]);
                $isPublishedCanonicalRow = $judgedPost->post_status === 'publish'
                    && $judgedPost->post_password === ''
                    && in_array($judgedPost->post_type, ['post', 'page'], true);
                assert_same(
                    $isPublishedCanonicalRow,
                    in_array($fixtureId, $actualIds, true),
                    'REST benchmark judgment should obey the published canonical scope for ' . $fixtureId . ' in ' . (string) $query['id']
                );
            }
        }

        $privateFixtureId = 'rest-readable-private';
        $privateNumericId = (int) $documentsById[$privateFixtureId]['numeric_id'];
        $GLOBALS['wp_fts_test_caps'] = [
            // A per-object grant cannot widen the SQL scope before LIMIT.
            'read_post' => [$privateNumericId => true],
        ];
        $privateScopeRejected = false;
        try {
            WP_FTS_Plugin::search_page('shared refill', [
                'lang' => 'en',
                'mode' => 'AND',
                'limit' => 10,
                'post_type' => 'post',
                'post_status' => 'private',
            ]);
        } catch (InvalidArgumentException) {
            $privateScopeRejected = true;
        }
        assert_true($privateScopeRejected, 'PHP search should reject private SQL scope when only a per-object read grant exists');

        $GLOBALS['wp_fts_test_caps']['read_private_posts'][0] = true;
        $privateResponse = WP_FTS_Plugin::search_page('shared refill', [
            'lang' => 'en',
            'mode' => 'AND',
            'limit' => 10,
            'post_type' => 'post',
            'post_status' => 'private',
        ]);
        $privateIds = wp_fts_nrgb_fixture_ids_from_plugin_rows($privateResponse['results'], $idByNumeric);
        sort($privateIds, SORT_STRING);
        assert_same(
            ['rest-hidden-private', $privateFixtureId],
            $privateIds,
            'explicit type-wide PHP scope should retrieve all matching private fixtures rather than applying per-object grants after LIMIT'
        );
        foreach (['rest-password', 'rest-excluded-type', 'rest-public'] as $fixtureId) {
            assert_true(!in_array($fixtureId, $privateIds, true), 'explicit private PHP scope should exclude fixture id ' . $fixtureId);
        }
    } finally {
        $wpdb = $oldWpdb;
    }
});
