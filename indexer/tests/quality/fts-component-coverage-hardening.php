<?php
declare(strict_types=1);

/**
 * @return string[]
 */
function qfch_terms(array $occurrences): array
{
    $terms = [];
    foreach ($occurrences as $occurrence) {
        if (is_array($occurrence)) {
            $terms[] = (string) ($occurrence['term'] ?? '');
        }
    }
    sort($terms, SORT_STRING);

    return $terms;
}

/**
 * @return array<string,string>
 */
function qfch_lang_by_term(array $occurrences): array
{
    $langs = [];
    foreach ($occurrences as $occurrence) {
        if (is_array($occurrence)) {
            $langs[(string) ($occurrence['term'] ?? '')] = (string) ($occurrence['lang'] ?? '');
        }
    }
    ksort($langs, SORT_STRING);

    return $langs;
}

test_case('quality component generated HTML extraction and analyzer hardening matrix', function (): void {
    $analyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'enable_stemming' => false,
        'default_lang' => 'en',
    ]);
    $roots = ['article', 'section', 'main', 'div'];
    $inlineTags = ['em', 'strong', 'span', 'b'];
    $languages = ['en', 'pl', 'de', 'fr', 'es', 'nl', 'tr', 'zh-Hans'];
    $targets = [
        'alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf', 'hotel',
        'india', 'juliet', 'kilo', 'lima', 'mango', 'november', 'oscar', 'papa',
        'quartz', 'river', 'sierra', 'tango', 'umbra', 'violet', 'whiskey', 'xray',
        'yellow', 'zephyr', 'anchor', 'bridge', 'copper', 'dragon', 'ember', 'forest',
    ];
    $secondary = [
        'castle', 'library', 'harbor', 'market', 'garden', 'planet', 'signal', 'ticket',
        'window', 'silver', 'orange', 'purple', 'circle', 'ladder', 'magnet', 'notion',
    ];

    for ($i = 0; $i < 640; $i++) {
        $target = $targets[$i % count($targets)];
        $split = 2 + ($i % (strlen($target) - 3));
        $left = substr($target, 0, $split);
        $right = substr($target, $split);
        $tail = $secondary[($i * 7) % count($secondary)];
        $root = $roots[$i % count($roots)];
        $tag = $inlineTags[intdiv($i, count($roots)) % count($inlineTags)];
        $lang = $languages[$i % count($languages)];
        $hidden = 'hidden' . $target;
        $nav = 'nav' . $tail;
        $html = "<{$root}><h1>{$left}<{$tag}>{$right}</{$tag}></h1><p lang=\"{$lang}\">{$tail} &amp; entity</p><script>{$hidden}</script><style>{$hidden}</style><nav>{$nav}</nav></{$root}>";

        $visible = WP_FTS_Html_Text_Stream::visible_text($html);
        assert_true(!str_contains($visible, $hidden), "component HTML case {$i} should remove script/style text from visible stream");

        $occurrences = $analyzer->analyze_content($html, ['lang' => 'en']);
        $terms = qfch_terms($occurrences);
        $langByTerm = qfch_lang_by_term($occurrences);
        assert_true(in_array($target, $terms, true), "component HTML case {$i} should join inline split word {$target}");
        assert_true(in_array($tail, $terms, true), "component HTML case {$i} should retain visible language-scoped word {$tail}");
        assert_true(!in_array($hidden, $terms, true), "component HTML case {$i} should not index script/style token {$hidden}");
        assert_true(!in_array($nav, $terms, true), "component HTML case {$i} should not index navigation token {$nav}");
        assert_same(WP_FTS_TermNamespace::canonicalize_lang($lang), $langByTerm[$tail] ?? null, "component HTML case {$i} should preserve scoped language for {$tail}");
    }
});

test_case('quality component generated search policies match deterministic metadata oracle', function (): void {
    $analyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'enable_stemming' => false,
        'default_lang' => 'en',
    ]);
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $topics = ['atlas', 'beacon', 'cipher', 'delta', 'ember', 'fable', 'garden', 'harbor', 'island', 'jungle', 'kernel', 'lantern'];
    $model = [];

    for ($docId = 1; $docId <= 180; $docId++) {
        $topic = $topics[$docId % count($topics)];
        $status = $docId % 7 === 0 ? 'draft' : 'publish';
        $type = $docId % 5 === 0 ? 'page' : 'post';
        $date = sprintf('2026-02-%02d 00:00:00', 1 + ($docId % 20));
        $html = "<article><h1>{$topic} common</h1><p>doc{$docId} facet{$docId} stable</p></article>";
        $model[$docId] = [
            'topic' => $topic,
            'status' => $status,
            'type' => $type,
            'date' => $date,
        ];

        assert_true($indexer->index_document($docId, $html, [
            'lang' => 'en',
            'metadata' => [
                'post_id' => $docId,
                'post_type' => $type,
                'post_status' => $status,
                'post_date_gmt' => $date,
                'title' => "Generated {$docId}",
                'search_html' => $html,
            ],
        ]), "component generated doc {$docId} should index");
    }

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    foreach ($topics as $topic) {
        $expectedPosts = [];
        foreach ($model as $docId => $row) {
            if ($row['topic'] === $topic && $row['type'] === 'post' && $row['status'] === 'publish') {
                $expectedPosts[] = $docId;
            }
        }
        sort($expectedPosts, SORT_NUMERIC);

        $payload = $searcher->search($topic, [
            'lang' => 'en',
            'limit' => 50,
            'include_total' => true,
            'include_metadata' => true,
            'post_type' => ['post'],
            'post_status' => ['publish'],
        ]);
        $actualPosts = array_column($payload['results'], 'doc_id');
        sort($actualPosts, SORT_NUMERIC);
        assert_same($expectedPosts, $actualPosts, "component metadata-filtered search for {$topic} should match oracle ids");
        assert_same(count($expectedPosts), $payload['total'], "component include_total for {$topic} should count metadata-filtered rows");
        foreach ($payload['results'] as $row) {
            assert_same('post', $row['post_type'] ?? null, "component metadata enrichment for {$topic} should expose post type");
            assert_same('publish', $row['post_status'] ?? null, "component metadata enrichment for {$topic} should expose post status");
        }

        $exact = $searcher->search($topic, ['lang' => 'en', 'limit' => 20, 'exact' => true]);
        $fast = $searcher->search($topic, ['lang' => 'en', 'limit' => 20, 'fast_top_k' => true, 'candidate_cap' => 80]);
        assert_same(array_column($exact, 'doc_id'), array_column($fast, 'doc_id'), "component explicit fast top-k for {$topic} should match exact ordering with safe cap");
    }
});

test_case('quality component field-specific explain diagnostics are weighted bounded and opt-in', function (): void {
    $analyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'enable_stemming' => false,
        'default_lang' => 'en',
    ]);
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    assert_true($indexer->index_document_fields(9101, [
        ['name' => 'title', 'text' => 'titlealpha sharedfield', 'boost' => 5.0],
        ['name' => 'content', 'text' => 'contentbeta sharedfield', 'boost' => 1.0],
        ['name' => 'excerpt', 'text' => 'excerptgamma sharedfield', 'boost' => 2.0],
        ['name' => 'rendered', 'text' => 'rendereddelta sharedfield', 'boost' => 1.5],
        ['name' => 'custom_fields', 'text' => 'customomega sharedfield', 'boost' => 3.0],
    ], [
        'lang' => 'en',
        'metadata' => [
            'post_id' => 9101,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_date_gmt' => '2026-04-01 00:00:00',
            'title' => 'Field explain quality fixture',
        ],
    ]), 'quality field explain multi-field fixture should index');
    assert_true($indexer->index_document_fields(9102, [
        ['name' => 'title', 'text' => 'contentbeta swapped title', 'boost' => 5.0],
        ['name' => 'content', 'text' => 'titlealpha swapped content', 'boost' => 1.0],
    ], [
        'lang' => 'en',
        'metadata' => [
            'post_id' => 9102,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_date_gmt' => '2026-04-02 00:00:00',
            'title' => 'Field explain swapped quality fixture',
        ],
    ]), 'quality field explain swapped-field fixture should index');

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    $plainRows = $searcher->search('titlealpha contentbeta', ['lang' => 'en', 'limit' => 10]);
    assert_true(!array_key_exists('field_matches', $plainRows[0] ?? []), 'plain field search should not expose diagnostics');
    assert_same([], $searcher->search('rendereddeltas', ['lang' => 'en', 'mode' => 'AND']), 'field explain should not introduce hard-coded morphology');

    $payload = $searcher->search('titlealpha contentbeta excerptgamma rendereddelta customomega', [
        'lang' => 'en',
        'limit' => 5,
        'include_total' => true,
        'explain' => true,
    ]);
    assert_true(!array_key_exists('field_matches', $payload['results'][0] ?? []), 'public result rows should stay compatible when explain is requested');

    $byDoc = [];
    foreach ($payload['explain']['results'] ?? [] as $row) {
        $byDoc[(int) ($row['doc_id'] ?? 0)] = $row;
    }
    assert_true(isset($byDoc[9101], $byDoc[9102]), 'field explain should include both returned documents');
    $fieldMatches = is_array($byDoc[9101]['field_matches'] ?? null) ? $byDoc[9101]['field_matches'] : [];
    $fieldNames = array_column($fieldMatches, 'field');
    foreach (['title', 'content', 'excerpt', 'rendered', 'custom_fields'] as $fieldName) {
        assert_true(in_array($fieldName, $fieldNames, true), "quality field explain should include {$fieldName}");
    }

    $titleMatch = [];
    foreach ($fieldMatches as $fieldMatch) {
        if (($fieldMatch['field'] ?? null) === 'title') {
            $titleMatch = $fieldMatch;
            break;
        }
    }
    assert_same(5.0, $titleMatch['weight'] ?? null, 'quality field explain should expose title weight');
    assert_true((int) ($titleMatch['match_count'] ?? 0) > 0, 'quality field explain should count title matches');
    assert_true((float) ($titleMatch['weighted_match_count'] ?? 0.0) >= 5.0, 'quality field explain should expose weighted title matches');
    assert_true((float) ($titleMatch['score_subtotal'] ?? 0.0) > 0.0, 'quality field explain should expose score subtotal');
    assert_same(true, $titleMatch['score_subtotal_approximate'] ?? null, 'quality field explain should mark score subtotal as approximate');
    assert_same('titlealpha', $titleMatch['terms'][0]['term'] ?? null, 'quality field explain should list analyzed title term');

    $swappedNames = array_column($byDoc[9102]['field_matches'] ?? [], 'field');
    assert_true(in_array('title', $swappedNames, true) && in_array('content', $swappedNames, true), 'quality field explain should report different matching fields per document');

    $manyFields = [];
    $manyTerms = [];
    for ($field = 0; $field < 12; $field++) {
        $fieldTerms = [];
        for ($term = 0; $term < 8; $term++) {
            $value = "qualityfield{$field}term{$term}";
            $fieldTerms[] = $value;
            $manyTerms[] = $value;
        }
        $manyFields[] = ['name' => "quality_field_{$field}", 'text' => implode(' ', $fieldTerms), 'boost' => 1.0 + $field];
    }
    assert_true($indexer->index_document_fields(9103, $manyFields, [
        'lang' => 'en',
        'metadata' => [
            'post_id' => 9103,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_date_gmt' => '2026-04-03 00:00:00',
            'title' => 'Bounded field explain quality fixture',
        ],
    ]), 'quality field explain bounded fixture should index');
    $bounded = $searcher->search(implode(' ', $manyTerms), [
        'lang' => 'en',
        'limit' => 1,
        'include_total' => true,
        'explain' => true,
    ]);
    $boundedFields = $bounded['explain']['results'][0]['field_matches'] ?? [];
    assert_true(count($boundedFields) <= 6, 'quality field explain should cap field entries');
    assert_same(true, $bounded['explain']['results'][0]['field_matches_more'] ?? null, 'quality field explain should flag omitted fields');
    assert_true(count($boundedFields[0]['terms'] ?? []) <= 6, 'quality field explain should cap terms per field');
    assert_same(true, $boundedFields[0]['terms_more'] ?? null, 'quality field explain should flag omitted field terms');
});

test_case('quality component snippets preserve visible marks without exposing hidden HTML', function (): void {
    $analyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'enable_stemming' => false,
        'default_lang' => 'en',
    ]);
    $searcher = new WP_FTS_Searcher(new WP_FTS_Storage_InMemory(), $analyzer);
    $queries = ['alpha', 'bravo', 'charlie', 'delta', 'ember', 'forest', 'garden', 'harbor'];

    for ($i = 0; $i < 360; $i++) {
        $query = $queries[$i % count($queries)];
        $html = "<article><p>Lead {$query} <strong>visible{$i}</strong> tail</p><script>{$query} scriptsecret{$i}</script><style>.x{content:\"{$query}\"}</style></article>";
        $snippet = $searcher->snippet_for_text($html, $query, [
            'lang' => 'en',
            'highlight' => true,
            'snippet_length' => 80,
        ]);

        assert_true(str_contains($snippet, '<mark>') || str_contains($snippet, '<mark '), "component snippet {$i} should mark the visible query");
        assert_true(str_contains(WP_FTS_Html_Text_Stream::visible_text($snippet), $query), "component snippet {$i} should retain visible query text");
        assert_true(!str_contains($snippet, 'scriptsecret'), "component snippet {$i} should not expose script text");
        assert_true(!str_contains(strtolower($snippet), '<script'), "component snippet {$i} should not return script tags");
    }
});
