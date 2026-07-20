<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$wp_fts_strict_search_output_checks = 0;

/** Record one relational search-output assertion. */
$wp_fts_strict_search_output_check = static function (
    bool $condition,
    string $message
) use (&$wp_fts_strict_search_output_checks): void {
    $wp_fts_strict_search_output_checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/** Require one malformed backend page to fail at the searcher boundary. */
$wp_fts_strict_search_output_rejects = static function (
    array $page,
    array $options,
    string $message
) use ($wp_fts_strict_search_output_check): void {
    $analyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'enable_stemming' => false,
        'default_lang' => 'en',
    ]);
    try {
        (new WP_FTS_Searcher(
            new WP_FTS_Test_Set_Oriented_Search_Storage($page),
            $analyzer
        ))->search('needle', ['query_lang' => 'en'] + $options);
    } catch (Throwable $error) {
        $wp_fts_strict_search_output_check($error instanceof LogicException, $message);
        return;
    }

    throw new RuntimeException($message);
};

/** Return one exact base relational page. */
$wp_fts_strict_search_page = static function (array $rows = []): array {
    return [
        'results' => $rows,
        'has_more' => false,
        'next_cursor' => null,
        'previous_cursor' => null,
    ];
};

/** Return the one exact relational diagnostic payload for a one-term query. */
$wp_fts_strict_search_explain = static function (array $changes = []): array {
    return array_replace([
        'storage' => 'set_oriented',
        'logical_group_count' => 1,
        'resolved_alternatives' => 1,
        'anchor_group' => 0,
        'prefix_range' => false,
        'prefix_strategy' => 'none',
        'query_statements' => 2,
        'interactive_total' => 'unknown',
        'recency_boost' => [
            'enabled' => false,
            'strength' => 0.0,
            'half_life_days' => 30.0,
            'scoring_now_gmt' => '',
        ],
        'canonical_page_bytes' => 0,
    ], $changes);
};

$validRow = ['doc_id' => 7, 'score' => 1.5];
$invalidPages = [
    'a missing field' => [
        'results' => [],
        'has_more' => false,
        'next_cursor' => null,
    ],
    'an extra field' => $wp_fts_strict_search_page([]) + ['unknown' => true],
    'reordered fields' => [
        'has_more' => false,
        'results' => [],
        'next_cursor' => null,
        'previous_cursor' => null,
    ],
    'an associative result collection' => [
        'results' => ['first' => $validRow],
        'has_more' => false,
        'next_cursor' => null,
        'previous_cursor' => null,
    ],
    'a non-boolean continuation flag' => [
        'results' => [],
        'has_more' => 0,
        'next_cursor' => null,
        'previous_cursor' => null,
    ],
    'an integer cursor' => [
        'results' => [],
        'has_more' => false,
        'next_cursor' => 1,
        'previous_cursor' => null,
    ],
    'an empty cursor' => [
        'results' => [],
        'has_more' => true,
        'next_cursor' => '',
        'previous_cursor' => null,
    ],
    'a padded cursor' => [
        'results' => [],
        'has_more' => true,
        'next_cursor' => ' opaque ',
        'previous_cursor' => null,
    ],
    'an oversized cursor' => [
        'results' => [],
        'has_more' => true,
        'next_cursor' => str_repeat('c', 2049),
        'previous_cursor' => null,
    ],
    'a missing continuation cursor' => [
        'results' => [],
        'has_more' => true,
        'next_cursor' => null,
        'previous_cursor' => null,
    ],
    'a contradictory continuation cursor' => [
        'results' => [],
        'has_more' => false,
        'next_cursor' => 'opaque',
        'previous_cursor' => null,
    ],
];
foreach ($invalidPages as $label => $page) {
    $wp_fts_strict_search_output_rejects(
        $page,
        [],
        "relational search must reject {$label}"
    );
}

$wp_fts_strict_search_output_rejects(
    $wp_fts_strict_search_page([$validRow, ['doc_id' => 8, 'score' => 1.0]]),
    ['limit' => 1],
    'relational search must reject a backend page above the requested size instead of truncating it'
);

$invalidRows = [
    'a scalar row' => 'row',
    'reordered fields' => ['score' => 1.5, 'doc_id' => 7],
    'a string document ID' => ['doc_id' => '7', 'score' => 1.5],
    'a zero document ID' => ['doc_id' => 0, 'score' => 1.5],
    'an integer score' => ['doc_id' => 7, 'score' => 1],
    'a string score' => ['doc_id' => 7, 'score' => '1.5'],
    'a NAN score' => ['doc_id' => 7, 'score' => NAN],
    'an infinite score' => ['doc_id' => 7, 'score' => INF],
    'an unknown result field' => ['doc_id' => 7, 'score' => 1.5, 'unknown' => true],
];
foreach ($invalidRows as $label => $row) {
    $wp_fts_strict_search_output_rejects(
        $wp_fts_strict_search_page([$row]),
        [],
        "relational search must reject {$label}"
    );
}

$metadataRow = [
    'doc_id' => 7,
    'score' => 1.5,
    'post_id' => 7,
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_date_gmt' => '2026-07-20 00:00:00',
    'title' => 'Needle title',
    'excerpt' => 'Needle excerpt',
    'primary_lang' => 'en',
];
$invalidMetadataRows = [
    'a missing metadata field' => array_diff_key($metadataRow, ['title' => true]),
    'a string post ID' => array_replace($metadataRow, ['post_id' => '7']),
    'a mismatched post ID' => array_replace($metadataRow, ['post_id' => 8]),
    'a scalar metadata coercion' => array_replace($metadataRow, ['title' => 7]),
    'malformed UTF-8 metadata' => array_replace($metadataRow, ['title' => "\xC3\x28"]),
    'an empty post type' => array_replace($metadataRow, ['post_type' => '']),
    'a padded post status' => array_replace($metadataRow, ['post_status' => ' publish']),
    'an oversized post type' => array_replace($metadataRow, ['post_type' => str_repeat('p', 65)]),
    'a malformed UTC post date' => array_replace($metadataRow, ['post_date_gmt' => '2026-02-30 00:00:00']),
    'a noncanonical UTC post date' => array_replace($metadataRow, ['post_date_gmt' => '2026-07-20T00:00:00']),
    'an oversized title' => array_replace($metadataRow, ['title' => str_repeat('t', 20001)]),
    'a padded primary language' => array_replace($metadataRow, ['primary_lang' => ' en']),
    'a noncanonical primary language' => array_replace($metadataRow, ['primary_lang' => 'EN']),
    'a malformed primary language' => array_replace($metadataRow, ['primary_lang' => 'e!n']),
    'a non-string primary language' => array_replace($metadataRow, ['primary_lang' => 7]),
];
foreach ($invalidMetadataRows as $label => $row) {
    $wp_fts_strict_search_output_rejects(
        $wp_fts_strict_search_page([$row]),
        ['include_metadata' => true],
        "relational search must reject {$label}"
    );
}

$metadataBoundary = array_replace($metadataRow, [
    'title' => str_repeat('t', 20000),
    'excerpt' => str_repeat('e', 1450000),
]);
$metadataStorage = new WP_FTS_Test_Set_Oriented_Search_Storage(
    $wp_fts_strict_search_page([$metadataBoundary])
);
$metadataPayload = (new WP_FTS_Searcher(
    $metadataStorage,
    new WP_FTS_Analyzer(['auto_detect_language' => false, 'enable_stemming' => false])
))->search('needle', ['query_lang' => 'en', 'include_metadata' => true]);
$wp_fts_strict_search_output_check(
    ($metadataPayload['results'][0]['title'] ?? null) === $metadataBoundary['title'],
    'relational search must preserve bounded native metadata text without truncating it'
);

$wp_fts_strict_search_output_rejects(
    $wp_fts_strict_search_page([
        array_replace($metadataRow, ['excerpt' => str_repeat('a', 2100000)]),
        array_replace($metadataRow, [
            'doc_id' => 8,
            'post_id' => 8,
            'excerpt' => str_repeat('b', 2100000),
        ]),
    ]),
    ['include_metadata' => true, 'limit' => 2],
    'relational search must reject metadata whose aggregate page exceeds 4 MiB'
);

$snippetRow = $validRow + [
    'snippet_text' => 'Needle source',
    'primary_lang' => 'en',
];
foreach ([
    'a non-string snippet sidecar' => array_replace($snippetRow, ['snippet_text' => 1]),
    'an oversized snippet sidecar' => array_replace($snippetRow, ['snippet_text' => str_repeat('s', 20001)]),
    'a noncanonical snippet language' => array_replace($snippetRow, ['primary_lang' => 'en_US']),
] as $label => $row) {
    $wp_fts_strict_search_output_rejects(
        $wp_fts_strict_search_page([$row]),
        ['include_snippets' => true],
        "relational search must reject {$label}"
    );
}

$canonicalRow = ['ID' => 7, 'post_title' => 'Needle'];
$canonicalInvalidRows = [
    'a non-array canonical post sidecar' => 'post',
    'an empty canonical post sidecar' => [],
    'a string canonical ID' => ['ID' => '7'],
    'a mismatched canonical ID' => ['ID' => 8],
    'a nested canonical field' => ['ID' => 7, 'post_title' => ['Needle']],
    'a padded canonical field name' => ['ID' => 7, ' post_title' => 'Needle'],
    'malformed UTF-8 canonical text' => ['ID' => 7, 'post_title' => "\xC3\x28"],
    'an oversized canonical post sidecar' => ['ID' => 7, 'post_content' => str_repeat('c', 4194305)],
];
$tooManyCanonicalFields = ['ID' => 7];
for ($field = 0; $field < 64; $field++) {
    $tooManyCanonicalFields['field_' . $field] = '';
}
$canonicalInvalidRows['more than 64 canonical fields'] = $tooManyCanonicalFields;
foreach ($canonicalInvalidRows as $label => $canonicalInvalidRow) {
    $wp_fts_strict_search_output_rejects(
        $wp_fts_strict_search_page([$validRow + ['_canonical_post_row' => $canonicalInvalidRow]]),
        ['_include_canonical_post_rows' => true],
        "relational search must reject {$label}"
    );
}

$canonicalStorage = new WP_FTS_Test_Set_Oriented_Search_Storage(
    $wp_fts_strict_search_page([$validRow + ['_canonical_post_row' => $canonicalRow]])
);
$canonicalPayload = (new WP_FTS_Searcher(
    $canonicalStorage,
    new WP_FTS_Analyzer(['auto_detect_language' => false, 'enable_stemming' => false])
))->search('needle', ['query_lang' => 'en', '_include_canonical_post_rows' => true]);
$wp_fts_strict_search_output_check(
    ($canonicalPayload['results'][0]['_canonical_post_row'] ?? null) === $canonicalRow,
    'relational search must preserve one exact bounded canonical post sidecar'
);

$wp_fts_strict_search_output_rejects(
    $wp_fts_strict_search_page([
        $validRow + ['_canonical_post_row' => ['ID' => 7, 'post_content' => str_repeat('a', 2100000)]],
        ['doc_id' => 8, 'score' => 1.0, '_canonical_post_row' => [
            'ID' => 8,
            'post_content' => str_repeat('b', 2100000),
        ]],
    ]),
    ['_include_canonical_post_rows' => true, 'limit' => 2],
    'relational search must reject canonical rows whose aggregate page exceeds 4 MiB'
);

$wp_fts_strict_search_output_rejects(
    $wp_fts_strict_search_page([$validRow, $validRow]),
    ['limit' => 2],
    'relational search must reject duplicate document IDs'
);

$validExplain = $wp_fts_strict_search_explain();
$explainStorage = new WP_FTS_Test_Set_Oriented_Search_Storage(
    $wp_fts_strict_search_page([$validRow]) + ['explain' => $validExplain]
);
$explainPayload = (new WP_FTS_Searcher(
    $explainStorage,
    new WP_FTS_Analyzer(['auto_detect_language' => false, 'enable_stemming' => false])
))->search('needle', ['query_lang' => 'en', 'explain' => true]);
$wp_fts_strict_search_output_check(
    ($explainPayload['explain'] ?? null) === $validExplain,
    'relational search must preserve the exact fixed explain payload'
);

$invalidExplains = [
    'a missing field' => array_diff_key($validExplain, ['anchor_group' => true]),
    'an extra field' => $validExplain + ['results' => []],
    'a different storage path' => array_replace($validExplain, ['storage' => 'fixture']),
    'a mismatched logical group count' => array_replace($validExplain, ['logical_group_count' => 2]),
    'too many resolved alternatives' => array_replace($validExplain, ['resolved_alternatives' => 2]),
    'an out-of-range anchor group' => array_replace($validExplain, ['anchor_group' => 1]),
    'a contradictory prefix strategy' => array_replace($validExplain, ['prefix_strategy' => 'surface_range']),
    'too many query statements' => array_replace($validExplain, ['query_statements' => 4]),
    'a concrete interactive total' => array_replace($validExplain, ['interactive_total' => 1]),
    'a scalar recency map' => array_replace($validExplain, ['recency_boost' => false]),
    'an integer recency strength' => array_replace($validExplain, ['recency_boost' => array_replace(
        $validExplain['recency_boost'],
        ['strength' => 0]
    )]),
    'a disabled recency timestamp' => array_replace($validExplain, ['recency_boost' => array_replace(
        $validExplain['recency_boost'],
        ['scoring_now_gmt' => '2026-07-20 00:00:00']
    )]),
    'oversized canonical page bytes' => array_replace($validExplain, ['canonical_page_bytes' => 4194305]),
];
foreach ($invalidExplains as $label => $explain) {
    $wp_fts_strict_search_output_rejects(
        $wp_fts_strict_search_page([$validRow]) + ['explain' => $explain],
        ['explain' => true],
        "relational search must reject explain data with {$label}"
    );
}

$canonicalExplainRow = $validRow + ['_canonical_post_row' => $canonicalRow];
$canonicalExplain = $wp_fts_strict_search_explain(['canonical_page_bytes' => 26]);
$canonicalExplainPayload = (new WP_FTS_Searcher(
    new WP_FTS_Test_Set_Oriented_Search_Storage(
        $wp_fts_strict_search_page([$canonicalExplainRow]) + ['explain' => $canonicalExplain]
    ),
    new WP_FTS_Analyzer(['auto_detect_language' => false, 'enable_stemming' => false])
))->search('needle', [
    'query_lang' => 'en',
    'explain' => true,
    '_include_canonical_post_rows' => true,
]);
$wp_fts_strict_search_output_check(
    ($canonicalExplainPayload['explain'] ?? null) === $canonicalExplain,
    'relational search must require the exact measured canonical page bytes'
);
$wp_fts_strict_search_output_rejects(
    $wp_fts_strict_search_page([$canonicalExplainRow]) + [
        'explain' => array_replace($canonicalExplain, ['canonical_page_bytes' => 25]),
    ],
    ['explain' => true, '_include_canonical_post_rows' => true],
    'relational search must reject canonical page diagnostics that disagree with the transported rows'
);

$enabledRecency = [
    'enabled' => true,
    'strength' => 1.0,
    'half_life_days' => 14.0,
    'scoring_now_gmt' => '2026-07-20 00:00:00',
];
$enabledExplain = $wp_fts_strict_search_explain(['recency_boost' => $enabledRecency]);
$enabledStorage = new WP_FTS_Test_Set_Oriented_Search_Storage(
    $wp_fts_strict_search_page([$validRow]) + ['explain' => $enabledExplain]
);
$enabledPayload = (new WP_FTS_Searcher(
    $enabledStorage,
    new WP_FTS_Analyzer(['auto_detect_language' => false, 'enable_stemming' => false])
))->search('needle', [
    'query_lang' => 'en',
    'explain' => true,
    'recency_boost_strength' => 1.0,
    'recency_boost_half_life_days' => 14.0,
]);
$wp_fts_strict_search_output_check(
    ($enabledPayload['explain']['recency_boost'] ?? null) === $enabledRecency,
    'relational search must preserve exact enabled recency diagnostics'
);

$emptyExplainStorage = new WP_FTS_Test_Set_Oriented_Search_Storage();
$emptyExplainPayload = (new WP_FTS_Searcher(
    $emptyExplainStorage,
    new WP_FTS_Analyzer(['auto_detect_language' => false, 'enable_stemming' => false])
))->search('', [
    'query_lang' => 'en',
    'explain' => true,
    'recency_boost_strength' => 1.0,
    'recency_boost_half_life_days' => 14.0,
]);
$wp_fts_strict_search_output_check(
    $emptyExplainStorage->call_count === 0
        && ($emptyExplainPayload['explain']['recency_boost'] ?? null) === array_replace(
            $enabledRecency,
            ['scoring_now_gmt' => '']
        ),
    'an empty query plan must return the same exact recency explain map without calling storage'
);

$cursorStorage = new WP_FTS_Test_Set_Oriented_Search_Storage([
    'results' => [$validRow],
    'has_more' => true,
    'next_cursor' => 'opaque-cursor',
    'previous_cursor' => null,
]);
$cursorPayload = (new WP_FTS_Searcher(
    $cursorStorage,
    new WP_FTS_Analyzer(['auto_detect_language' => false, 'enable_stemming' => false])
))->search('needle', ['query_lang' => 'en']);
$wp_fts_strict_search_output_check(
    ($cursorPayload['next_cursor'] ?? null) === 'opaque-cursor'
        && ($cursorPayload['has_more'] ?? null) === true,
    'relational search must preserve an exact coherent continuation state'
);

return $wp_fts_strict_search_output_checks;
