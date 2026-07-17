<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$wp_fts_component_hardening_checks = 0;

function wp_fts_component_hardening_check(bool $condition, string $message): void
{
    global $wp_fts_component_hardening_checks;
    $wp_fts_component_hardening_checks++;

    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wp_fts_component_hardening_same(mixed $expected, mixed $actual, string $message): void
{
    wp_fts_component_hardening_check(
        $expected === $actual,
        $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
    );
}

/**
 * @return string[]
 */
function wp_fts_component_hardening_terms(array $occurrences): array
{
    $terms = [];
    foreach ($occurrences as $occurrence) {
        $terms[] = is_array($occurrence) ? (string) $occurrence['term'] : (string) $occurrence;
    }
    sort($terms, SORT_STRING);

    return $terms;
}

$htmlCases = [
    [
        '<article><p>Alpha <em>beta</em></p><script>hidden</script></article>',
        'Alpha beta',
        ['alpha', 'beta'],
        ['hidden'],
    ],
    [
        '<p>split-<strong>word</strong> AT&amp;T</p><style>.secret{}</style>',
        'split-word AT&T',
        ['split', 'word'],
        ['secret'],
    ],
    [
        '<p lang="fr">Cafe&nbsp;ecole</p><nav>ignored navword</nav>',
        'Cafe ecole',
        ['cafe', 'ecole'],
        ['ignored', 'navword'],
    ],
    [
        '<p>broken <span>markup <b>still</p> visible</span>',
        'broken markup still visible',
        ['broken', 'markup', 'still', 'visible'],
        [],
    ],
];

$analyzer = new WP_FTS_Analyzer([
    'auto_detect_language' => false,
    'enable_stemming' => false,
    'default_lang' => 'en',
]);

wp_fts_component_hardening_same('zh-Hans', WP_FTS_TermNamespace::canonicalize_lang('zh-CN'), 'Chinese mainland region should share the Simplified partition');
wp_fts_component_hardening_same('zh-Hant', WP_FTS_TermNamespace::canonicalize_lang('zh_TW'), 'Chinese Taiwan region should share the Traditional partition');
wp_fts_component_hardening_same('zh-Hant', WP_FTS_TermNamespace::canonicalize_lang('zh-Hant-CN'), 'explicit Chinese script should win over region');
wp_fts_component_hardening_same('zh-Hans', (new WP_FTS_Normalizer())->canonicalize_language('zh-CN'), 'analyzer and term namespace should canonicalize Chinese identically');
wp_fts_component_hardening_same(
    'zh-Hans' . WP_FTS_TermNamespace::SEPARATOR . 'portable',
    WP_FTS_TermNamespace::namespace_term('zh-CN', 'portable'),
    'Chinese aliases should build the analyzer partition key'
);

$chineseStorage = new WP_FTS_Storage_InMemory();
$chineseIndexer = new WP_FTS_Indexer($chineseStorage, $analyzer);
$chineseIndexer->index_document(9001, '<p>portable search</p>', ['lang' => 'zh-CN']);
$chineseResults = (new WP_FTS_Searcher($chineseStorage, $analyzer))->search('portable', ['lang' => 'zh-CN']);
wp_fts_component_hardening_same(9001, $chineseResults[0]['doc_id'] ?? null, 'explicit Chinese region should query the partition used while indexing');

foreach ($htmlCases as $index => [$html, $visibleText, $expectedTerms, $absentTerms]) {
    wp_fts_component_hardening_same(
        $visibleText,
        WP_FTS_Html_Text_Stream::visible_text($html),
        "HTML visible text case {$index}"
    );
    $terms = wp_fts_component_hardening_terms($analyzer->analyze_content($html, ['lang' => 'en']));
    foreach ($expectedTerms as $term) {
        wp_fts_component_hardening_check(in_array($term, $terms, true), "HTML analyzer case {$index} should include {$term}");
    }
    foreach ($absentTerms as $term) {
        wp_fts_component_hardening_check(!in_array($term, $terms, true), "HTML analyzer case {$index} should exclude {$term}");
    }
}

$storage = new WP_FTS_Storage_InMemory();
$indexer = new WP_FTS_Indexer($storage, $analyzer);
$documents = [
    1 => ['html' => '<article><h1>alpha common</h1><p>facet-red marker-one</p></article>', 'type' => 'post', 'status' => 'publish', 'date' => '2026-01-01 00:00:00'],
    2 => ['html' => '<article><h1>alpha common</h1><p>facet-blue marker-two</p></article>', 'type' => 'page', 'status' => 'publish', 'date' => '2026-01-02 00:00:00'],
    3 => ['html' => '<article><h1>beta common</h1><p>facet-red marker-three</p></article>', 'type' => 'post', 'status' => 'draft', 'date' => '2026-01-03 00:00:00'],
    4 => ['html' => '<article><h1>gamma rare</h1><p>facet-green marker-four</p></article>', 'type' => 'post', 'status' => 'publish', 'date' => '2026-01-04 00:00:00'],
];

foreach ($documents as $docId => $document) {
    $changed = $indexer->index_document(
        $docId,
        $document['html'],
        [
            'lang' => 'en',
            'metadata' => [
                'post_id' => $docId,
                'post_type' => $document['type'],
                'post_status' => $document['status'],
                'post_date_gmt' => $document['date'],
                'title' => "Document {$docId}",
                'search_html' => $document['html'],
            ],
        ]
    );
    wp_fts_component_hardening_check($changed, "document {$docId} should index");
}

$searcher = new WP_FTS_Searcher($storage, $analyzer);
$alpha = $searcher->search('alpha common', ['mode' => 'AND', 'lang' => 'en', 'limit' => 10]);
wp_fts_component_hardening_same([1, 2], array_column($alpha, 'doc_id'), 'AND search should return both alpha documents');

$pageOne = $searcher->search('common', ['lang' => 'en', 'limit' => 1, 'include_total' => true]);
$pageTwo = $searcher->search('common', ['lang' => 'en', 'limit' => 1, 'offset' => 1, 'include_total' => true]);
wp_fts_component_hardening_same(3, $pageOne['total'], 'include_total should count all matching common docs');
wp_fts_component_hardening_check(($pageOne['results'][0]['doc_id'] ?? 0) !== ($pageTwo['results'][0]['doc_id'] ?? 0), 'pagination should advance between pages');

$filtered = $searcher->search('common', [
    'lang' => 'en',
    'limit' => 10,
    'include_metadata' => true,
    'post_type' => ['post'],
    'post_status' => ['publish'],
    'date_after' => '2026-01-01 00:00:00',
    'date_before' => '2026-01-02 23:59:59',
]);
wp_fts_component_hardening_same([1], array_column($filtered, 'doc_id'), 'metadata filters should keep only visible published posts in range');
wp_fts_component_hardening_same('post', $filtered[0]['post_type'] ?? null, 'metadata enrichment should include post type');

$exact = $searcher->search('common', ['lang' => 'en', 'limit' => 10, 'exact' => true]);
$fast = $searcher->search('common', ['lang' => 'en', 'limit' => 10, 'fast_top_k' => true, 'candidate_cap' => 10]);
wp_fts_component_hardening_same(array_column($exact, 'doc_id'), array_column($fast['results'], 'doc_id'), 'candidate-capped retrieval with a safe cap should preserve ordering on compact corpus');
wp_fts_component_hardening_same(false, $fast['total_is_exact'] ?? null, 'candidate-capped retrieval should mark its total as inexact');
wp_fts_component_hardening_same(true, $fast['results_may_be_incomplete'] ?? null, 'candidate-capped retrieval should expose incomplete-result risk');

$plainTotal = $searcher->search('common', ['lang' => 'en', 'limit' => 2, 'include_total' => true]);
wp_fts_component_hardening_check(!array_key_exists('explain', $plainTotal), 'search explain payload should be absent unless explicitly requested');
$legacyExplainRequest = $searcher->search('common', ['lang' => 'en', 'limit' => 2, 'explain' => true]);
wp_fts_component_hardening_check(!array_key_exists('explain', $legacyExplainRequest), 'explain should not change the legacy list return shape without include_total');

$explainPayload = $searcher->search(
    'alpha beta gamma common rare facet red blue green marker one two three four five six seven eight nine ten',
    ['lang' => 'en', 'limit' => 2, 'include_total' => true, 'explain' => true]
);
wp_fts_component_hardening_check(is_array($explainPayload['explain'] ?? null), 'include_total explain request should include diagnostics payload');
$plan = $explainPayload['explain']['query_plan'] ?? [];
wp_fts_component_hardening_same('OR', $plan['match_mode'] ?? null, 'explain query plan should record match mode');
wp_fts_component_hardening_check((int) ($plan['logical_group_count'] ?? 0) > 0, 'explain query plan should count logical groups');
wp_fts_component_hardening_check(in_array('en', $plan['analyzed_languages'] ?? [], true), 'explain query plan should record analyzed language');
wp_fts_component_hardening_check(count($plan['terms'] ?? []) <= 12, 'explain query terms should be bounded');
wp_fts_component_hardening_same(true, $plan['terms_more'] ?? null, 'explain query plan should report omitted terms');
$scoring = $explainPayload['explain']['scoring'] ?? [];
wp_fts_component_hardening_check((int) ($scoring['candidate_rows_fetched'] ?? 0) > 0, 'explain scoring should record fetched candidate rows');
wp_fts_component_hardening_check((int) ($scoring['candidate_docs_scored'] ?? 0) > 0, 'explain scoring should record scored candidate docs');
wp_fts_component_hardening_same('exact', $scoring['total_accuracy'] ?? null, 'exact explain search should report exact totals');
$resultExplain = $explainPayload['explain']['results'][0] ?? [];
wp_fts_component_hardening_check((int) ($resultExplain['doc_id'] ?? 0) > 0, 'explain should include per-result diagnostics for the returned page');
wp_fts_component_hardening_check(($resultExplain['matches'] ?? []) !== [], 'explain per-result diagnostics should include matched terms');

$fieldStorage = new WP_FTS_Storage_InMemory();
$fieldIndexer = new WP_FTS_Indexer($fieldStorage, $analyzer);
wp_fts_component_hardening_check($fieldIndexer->index_document_fields(501, [
    ['name' => 'title', 'text' => 'titlealpha sharedfield', 'boost' => 5.0],
    ['name' => 'content', 'text' => 'contentbeta sharedfield', 'boost' => 1.0],
    ['name' => 'excerpt', 'text' => 'excerptgamma sharedfield', 'boost' => 2.0],
    ['name' => 'rendered', 'text' => 'rendereddelta sharedfield', 'boost' => 1.5],
    ['name' => 'custom_fields', 'text' => 'customomega sharedfield', 'boost' => 3.0],
], [
    'lang' => 'en',
    'metadata' => [
        'post_id' => 501,
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-02-01 00:00:00',
        'title' => 'Field explain fixture',
    ],
]), 'field explain multi-field fixture should index');
wp_fts_component_hardening_check($fieldIndexer->index_document_fields(502, [
    ['name' => 'title', 'text' => 'contentbeta swapped title', 'boost' => 5.0],
    ['name' => 'content', 'text' => 'titlealpha swapped content', 'boost' => 1.0],
], [
    'lang' => 'en',
    'metadata' => [
        'post_id' => 502,
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-02-02 00:00:00',
        'title' => 'Swapped field explain fixture',
    ],
]), 'field explain swapped-field fixture should index');
$fieldSearcher = new WP_FTS_Searcher($fieldStorage, $analyzer);
$fieldDefault = $fieldSearcher->search('titlealpha contentbeta', ['lang' => 'en', 'limit' => 10]);
wp_fts_component_hardening_check(!array_key_exists('field_matches', $fieldDefault[0] ?? []), 'default search rows should not expose explain field diagnostics');
$fieldExplain = $fieldSearcher->search('titlealpha contentbeta excerptgamma rendereddelta customomega', [
    'lang' => 'en',
    'limit' => 5,
    'include_total' => true,
    'explain' => true,
]);
$fieldResult = [];
foreach (($fieldExplain['explain']['results'] ?? []) as $row) {
    if (($row['doc_id'] ?? null) === 501) {
        $fieldResult = $row;
        break;
    }
}
wp_fts_component_hardening_check($fieldResult !== [], 'field explain should include the multi-field document');
$fieldMatches = $fieldResult['field_matches'] ?? [];
wp_fts_component_hardening_check(is_array($fieldMatches) && $fieldMatches !== [], 'field explain should include per-field matches');
$fieldNames = array_column($fieldMatches, 'field');
foreach (['title', 'content', 'excerpt', 'rendered', 'custom_fields'] as $fieldName) {
    wp_fts_component_hardening_check(in_array($fieldName, $fieldNames, true), "field explain should report {$fieldName} matches");
}
$titleField = [];
foreach ($fieldMatches as $fieldMatch) {
    if (($fieldMatch['field'] ?? null) === 'title') {
        $titleField = $fieldMatch;
        break;
    }
}
wp_fts_component_hardening_same(5.0, $titleField['weight'] ?? null, 'field explain should expose configured title weight');
wp_fts_component_hardening_check((int) ($titleField['match_count'] ?? 0) > 0, 'field explain should count title hits');
wp_fts_component_hardening_check((float) ($titleField['weighted_match_count'] ?? 0.0) >= 5.0, 'field explain should include weighted title hit counts');
wp_fts_component_hardening_check((float) ($titleField['score_subtotal'] ?? 0.0) > 0.0, 'field explain should include a bounded score subtotal');
wp_fts_component_hardening_check(($titleField['terms'][0]['term'] ?? null) === 'titlealpha', 'field explain should list matched analyzed terms');

$swappedResult = [];
foreach (($fieldExplain['explain']['results'] ?? []) as $row) {
    if (($row['doc_id'] ?? null) === 502) {
        $swappedResult = $row;
        break;
    }
}
wp_fts_component_hardening_check($swappedResult !== [], 'field explain should include the swapped-field document');
$swappedFields = array_column($swappedResult['field_matches'] ?? [], 'field');
wp_fts_component_hardening_check(in_array('title', $swappedFields, true) && in_array('content', $swappedFields, true), 'field explain should describe different matching fields on different documents');
wp_fts_component_hardening_same([], $fieldSearcher->search('rendereddeltas', ['lang' => 'en', 'mode' => 'AND']), 'field explain support should not add hard-coded word-family matching');

$manyFields = [];
$manyTerms = [];
for ($i = 0; $i < 12; $i++) {
    $termsForField = [];
    for ($j = 0; $j < 8; $j++) {
        $term = "boundfield{$i}term{$j}";
        $termsForField[] = $term;
        $manyTerms[] = $term;
    }
    $manyFields[] = ['name' => "field_{$i}", 'text' => implode(' ', $termsForField), 'boost' => 1.0 + $i];
}
wp_fts_component_hardening_check($fieldIndexer->index_document_fields(503, $manyFields, [
    'lang' => 'en',
    'metadata' => [
        'post_id' => 503,
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-02-03 00:00:00',
        'title' => 'Bounded field explain fixture',
    ],
]), 'field explain bounded fixture should index');
$boundedExplain = $fieldSearcher->search(implode(' ', $manyTerms), [
    'lang' => 'en',
    'limit' => 1,
    'include_total' => true,
    'explain' => true,
]);
$boundedFields = $boundedExplain['explain']['results'][0]['field_matches'] ?? [];
wp_fts_component_hardening_check(count($boundedFields) <= 6, 'field explain should cap fields per result');
wp_fts_component_hardening_same(true, $boundedExplain['explain']['results'][0]['field_matches_more'] ?? null, 'field explain should flag omitted field rows');
wp_fts_component_hardening_check(count($boundedFields[0]['terms'] ?? []) <= 6, 'field explain should cap matched terms per field');
wp_fts_component_hardening_same(true, $boundedFields[0]['terms_more'] ?? null, 'field explain should flag omitted per-field terms');

$stemAnalyzer = new WP_FTS_Analyzer([
    'auto_detect_language' => false,
    'default_lang' => 'en',
]);
$stemStorage = new WP_FTS_Storage_InMemory();
wp_fts_component_hardening_check(
    (new WP_FTS_Indexer($stemStorage, $stemAnalyzer))->index_document(90, '<p>running</p>', ['lang' => 'en']),
    'stemmed explain fixture should index'
);
$stemExplain = (new WP_FTS_Searcher($stemStorage, $stemAnalyzer))->search('running', [
    'lang' => 'en',
    'limit' => 1,
    'include_total' => true,
    'explain' => true,
]);
$stemPlanTerm = $stemExplain['explain']['query_plan']['terms'][0] ?? [];
wp_fts_component_hardening_same('running', $stemPlanTerm['surface'] ?? null, 'explain query plan should expose the query surface separately from the analyzed term');
wp_fts_component_hardening_same('run', $stemPlanTerm['term'] ?? null, 'explain query plan should expose the analyzed stored term');
wp_fts_component_hardening_same(WP_FTS_TermNamespace::namespace_term('en', 'run'), $stemPlanTerm['key'] ?? null, 'explain query plan should expose the analyzed stored key');
$stemMatch = $stemExplain['explain']['results'][0]['matches'][0] ?? [];
wp_fts_component_hardening_same('running', $stemMatch['surface'] ?? null, 'explain result matches should preserve the query surface');
wp_fts_component_hardening_same('run', $stemMatch['term'] ?? null, 'explain result matches should expose the analyzed stored term');

$prefixExplain = $searcher->search('mark', [
    'lang' => 'en',
    'limit' => 2,
    'include_total' => true,
    'explain' => true,
    'prefix_matching' => true,
    'prefix_min_length' => 4,
    'prefix_max_terms' => 2,
]);
$prefixPlan = $prefixExplain['explain']['query_plan'] ?? [];
wp_fts_component_hardening_same('enabled', $prefixPlan['prefix_matching'] ?? null, 'explain query plan should record prefix matching state');
wp_fts_component_hardening_check((int) ($prefixPlan['prefix_added_terms'] ?? 0) > 0, 'explain query plan should count prefix-added terms');

$fastExplain = $searcher->search('common', [
    'lang' => 'en',
    'limit' => 1,
    'include_total' => true,
    'explain' => true,
    'fast_top_k' => true,
    'candidate_cap' => 2,
]);
$fastMode = $fastExplain['explain']['fast_mode'] ?? [];
wp_fts_component_hardening_same('approximate', $fastMode['mode'] ?? null, 'explain fast mode should record approximate mode');
wp_fts_component_hardening_same('explicit_option', $fastMode['source'] ?? null, 'explain fast mode should record explicit fast source');
wp_fts_component_hardening_same(2, $fastMode['candidate_cap'] ?? null, 'explain fast mode should record the resolved candidate cap');
wp_fts_component_hardening_same('approximate', $fastExplain['explain']['scoring']['total_accuracy'] ?? null, 'fast explain search should report approximate totals');
wp_fts_component_hardening_same('candidate_capped', $fastExplain['retrieval_mode'] ?? null, 'candidate-capped response should identify its retrieval mode');
wp_fts_component_hardening_same(false, $fastExplain['total_is_exact'] ?? null, 'candidate-capped response should not claim an exact total');
wp_fts_component_hardening_same(true, $fastExplain['results_may_be_incomplete'] ?? null, 'candidate-capped response should expose incomplete-result risk');

$snippet = $searcher->snippet_for_text(
    '<p>Intro al<strong>pha</strong> target</p><script>alpha hidden</script>',
    'alpha',
    ['lang' => 'en', 'highlight' => true, 'snippet_length' => 60]
);
wp_fts_component_hardening_check(str_contains($snippet, '<mark>'), 'HTML snippet should mark visible matches');
wp_fts_component_hardening_check(!str_contains($snippet, 'hidden'), 'HTML snippet should not expose hidden script text');

return $wp_fts_component_hardening_checks;
