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
        'Cafe ecole ignored navword',
        ['cafe', 'ecole'],
        ['navword'],
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
wp_fts_component_hardening_same(array_column($exact, 'doc_id'), array_column($fast, 'doc_id'), 'fast top-k with a safe cap should preserve ordering on compact corpus');

$snippet = $searcher->snippet_for_text(
    '<p>Intro al<strong>pha</strong> target</p><script>alpha hidden</script>',
    'alpha',
    ['lang' => 'en', 'highlight' => true, 'snippet_length' => 60]
);
wp_fts_component_hardening_check(str_contains($snippet, '<mark>'), 'HTML snippet should mark visible matches');
wp_fts_component_hardening_check(!str_contains($snippet, 'hidden'), 'HTML snippet should not expose hidden script text');

return $wp_fts_component_hardening_checks;
