<?php
declare(strict_types=1);

/**
 * Task 568 quality checks for the opt-in Polish verified stemmer port slice.
 */

/**
 * @return array<int,array{group:string,stem:string,source:string,term:string,note:string}>
 */
function wp_fts_pvs_fixture_rows(): array
{
    $rows = [];
    foreach (WP_FTS_PolishVerifiedStemmerData::reference_groups() as $group) {
        foreach ($group['forms'] as $form) {
            $rows[] = [
                'group' => $group['id'],
                'stem' => $group['stem'],
                'source' => $form['source'],
                'term' => $form['term'],
                'note' => $group['note'],
            ];
        }
    }

    return $rows;
}

function wp_fts_pvs_normalize_source(string $source): string
{
    static $normalizer = null;
    if (!$normalizer instanceof WP_FTS_Normalizer) {
        $normalizer = new WP_FTS_Normalizer();
    }

    return $normalizer->normalize_token($source, 'pl');
}

test_case('quality polish verified stemmer fixture manifest is compact and source-shaped', function (): void {
    $manifest = WP_FTS_PolishVerifiedStemmerData::manifest();
    $groups = WP_FTS_PolishVerifiedStemmerData::reference_groups();
    $stemMap = WP_FTS_PolishVerifiedStemmerData::stem_map();
    $protected = WP_FTS_PolishVerifiedStemmerData::protected_term_map();

    assert_same(WP_FTS_PolishVerifiedStemmerData::VERSION, $manifest['version'] ?? null, 'manifest should expose the fixture version');
    assert_contains('not a full', strtolower($manifest['boundary'] ?? ''), 'manifest should identify dictionary and lemmatizer boundary');
    assert_true(count($groups) >= 6, 'verified stemmer should carry multiple Polish fixture groups');
    assert_true(count($stemMap) < 100, 'verified stemmer slice should stay compact and avoid a dictionary dump');

    foreach ($groups as $group) {
        assert_true((string) $group['id'] !== '', 'fixture group id should be present');
        assert_true((string) $group['stem'] !== '', "fixture group {$group['id']} should have a stem");
        assert_true(count($group['forms']) >= 5, "fixture group {$group['id']} should include enough inflection rows");
        foreach ($group['forms'] as $form) {
            assert_same($form['term'], wp_fts_pvs_normalize_source($form['source']), "fixture source should normalize through WP_FTS_Normalizer for {$form['source']}");
            assert_same($group['stem'], $stemMap[$form['term']] ?? null, "fixture term {$form['term']} should map to {$group['stem']}");
            assert_true(!isset($protected[$form['term']]), "fixture term {$form['term']} should not also be protected");
        }
    }
});

test_case('quality polish verified stemmer improves mapped rows over suffix baseline', function (): void {
    $verified = new WP_FTS_PolishStemmer('verified');
    $baseline = new WP_FTS_PolishStemmer('conservative');
    $improvements = 0;

    foreach (wp_fts_pvs_fixture_rows() as $row) {
        assert_same($row['stem'], $verified->stem($row['term'], 'pl-PL'), "verified stem for {$row['source']}");
        assert_same($row['term'], $verified->stem($row['term'], 'en'), "non-Polish language should not stem {$row['term']}");
        if ($baseline->stem($row['term'], 'pl') !== $row['stem']) {
            $improvements++;
        }
    }

    assert_true($improvements >= 25, "verified fixture rows should improve suffix-only baseline; saw {$improvements}");
});

test_case('quality polish verified stemmer protects ambiguous rows and falls back conservatively', function (): void {
    $verified = new WP_FTS_PolishStemmer('verified');
    $normalizer = new WP_FTS_Normalizer();

    foreach (WP_FTS_PolishVerifiedStemmerData::protected_rows() as $row) {
        assert_same($row['term'], $normalizer->normalize_token($row['source'], 'pl'), "protected source should normalize for {$row['source']}");
        assert_same($row['stem'], $verified->stem($row['term'], 'pl'), "protected row should remain unchanged for {$row['source']}");
        assert_true((string) $row['reason'] !== '', "protected row {$row['source']} should document why it is a no-op");
    }

    foreach (WP_FTS_PolishVerifiedStemmerData::fallback_rows() as $row) {
        assert_same($row['term'], $normalizer->normalize_token($row['source'], 'pl'), "fallback source should normalize for {$row['source']}");
        assert_same($row['stem'], $verified->stem($row['term'], 'pl'), "unknown row should use conservative fallback for {$row['source']}");
        assert_true((string) $row['reason'] !== '', "fallback row {$row['source']} should document why it is safe");
    }
});

test_case('quality polish verified stemmer keeps normalizer-owned diacritic behavior', function (): void {
    $folded = new WP_FTS_LanguagePipeline([
        'polish_stemming' => 'verified',
    ]);
    assert_same(
        ['samochod', 'ksiazk', 'kobiet', 'miast', 'dobr', 'czyt', 'szuk'],
        $folded->analyze('Samochody książkę kobietą mieście dobrymi czytanie szukają', 'pl'),
        'verified Polish mode should run after normalizer folding'
    );

    $unfolded = new WP_FTS_LanguagePipeline([
        'polish_stemming' => 'verified',
        'fold_diacritics' => false,
    ]);
    assert_same(['książkę'], $unfolded->analyze('KSIĄŻKĘ', 'pl'), 'stemmer should not fold Polish diacritics when the normalizer is configured not to fold');
});

test_case('quality polish verified stemmer preserves analyzer document/query parity', function (): void {
    $analyzer = new WP_FTS_Analyzer([
        'default_lang' => 'pl',
        'polish_stemming' => 'verified',
        'auto_detect_language' => false,
    ]);

    $content = $analyzer->analyze_content(
        '<article lang="pl"><p>Samochody książkę kobietą mieście.</p></article>',
        ['document_lang' => 'pl']
    );
    $query = $analyzer->analyze_query('samochodem książki kobiety miasta', ['query_lang' => 'pl']);

    assert_same(['samochod', 'ksiazk', 'kobiet', 'miast'], test_terms($content), 'document forms should stem through analyzer');
    assert_same(test_terms($content), $query, 'alternate query forms should share verified stems with document forms');
    foreach ($content as $occurrence) {
        assert_same('pl', $occurrence['lang'], 'document occurrence should remain in the Polish partition');
    }
});

test_case('quality polish verified stemmer improves indexed recall when explicitly enabled', function (): void {
    $analyzer = new WP_FTS_Analyzer([
        'default_lang' => 'pl',
        'polish_stemming' => 'verified',
        'auto_detect_language' => false,
    ]);
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(501, '<p>Samochody książkę mieście czytanie</p>', ['lang' => 'pl']);
    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    assert_same(
        [501],
        array_column($searcher->search('samochodem książki miasta czytają', ['query_lang' => 'pl', 'mode' => 'AND']), 'doc_id'),
        'verified Polish stems should make alternate inflections match in AND search'
    );
});
