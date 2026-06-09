<?php
declare(strict_types=1);

if (!function_exists('pll_get_post_language')) {
    function pll_get_post_language(int $post_id, string $field = 'locale'): string|false
    {
        $language = $GLOBALS['wp_fts_ldgf_polylang_post_languages'][$post_id] ?? null;

        return is_string($language) && $language !== '' ? $language : false;
    }
}

if (!function_exists('pll_current_language')) {
    function pll_current_language(string $field = 'locale'): string|false
    {
        $language = $GLOBALS['wp_fts_ldgf_polylang_current_language'] ?? null;

        return is_string($language) && $language !== '' ? $language : false;
    }
}

function wp_fts_ldgf_reset_multilingual_globals(): void
{
    $GLOBALS['wp_fts_ldgf_polylang_post_languages'] = [];
    $GLOBALS['wp_fts_ldgf_polylang_current_language'] = null;
    $GLOBALS['wp_fts_test_filters']['wpml_post_language_details'] = null;
    $GLOBALS['wp_fts_test_filters']['wpml_current_language'] = null;
}

/**
 * @return int[]
 */
function wp_fts_ldgf_result_ids(array $rows, bool $sort = false): array
{
    $ids = array_values(array_map('intval', array_column($rows, 'doc_id')));
    if ($sort) {
        sort($ids, SORT_NUMERIC);
    }

    return $ids;
}

test_case('quality language detection gold fixtures keep untagged document and query span parity', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
    $cases = [
        [
            'label' => 'Polish connector span',
            'text' => 'oraz jest',
            'lang' => 'pl',
            'terms' => ['oraz', 'jest'],
        ],
        [
            'label' => 'German strong span with weak connector',
            'text' => 'Führung und Straße',
            'lang' => 'de',
            'terms' => ['fuehrung', 'und', 'strasse'],
        ],
        [
            'label' => 'Japanese mixed kana and kanji non-space text',
            'text' => '検索できます',
            'lang' => 'ja',
            'terms' => ['検索', 'でき', 'ます'],
        ],
        [
            'label' => 'Chinese Han-only non-space text',
            'text' => '搜索引擎',
            'lang' => 'zh',
            'terms' => ['搜索', '索引', '引擎'],
        ],
    ];

    foreach ($cases as $case) {
        record_check('language detection gold parity fixture: ' . $case['label']);
        $contentLangs = test_lang_by_term($analyzer->analyze_content('<p>' . $case['text'] . '</p>'));
        $queryLangs = test_lang_by_term($analyzer->analyze_query_occurrences($case['text']));

        foreach ($case['terms'] as $term) {
            assert_same($case['lang'], $contentLangs[$term] ?? null, "{$case['label']} content term {$term}");
            assert_same($case['lang'], $queryLangs[$term] ?? null, "{$case['label']} query term {$term}");
        }
    }
});

test_case('quality language detection gold fixtures keep accented English loanwords on fallback language', function (): void {
    $detector = new WP_FTS_LanguageDetector();
    assert_same(null, $detector->detect_text('Beyonce Beyonce Beyoncé résumé cafe'), 'French-accented English names and loanwords should not detect as French');
    assert_same(null, $detector->detect_text('jalapeño piñata party'), 'Spanish-accented English loanwords should not detect as Spanish');

    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
    $loanwordLangs = test_lang_by_term($analyzer->analyze_content('<p>Beyoncé résumé cafe jalapeño piñata party</p>'));
    foreach (['beyonce', 'resume', 'cafe', 'jalapeno', 'pinata', 'party'] as $term) {
        assert_same('en', $loanwordLangs[$term] ?? null, "English loanword content term {$term}");
    }

    $queryLangs = test_lang_by_term($analyzer->analyze_query_occurrences('Beyoncé résumé jalapeño piñata'));
    foreach (['beyonce', 'resume', 'jalapeno', 'pinata'] as $term) {
        assert_same('en', $queryLangs[$term] ?? null, "English loanword query term {$term}");
    }
});

test_case('quality language detection gold fixtures honor explicit and multilingual-plugin metadata', function (): void {
    wp_fts_ldgf_reset_multilingual_globals();
    $GLOBALS['wp_fts_ldgf_polylang_post_languages'][10] = 'pl_PL';
    $GLOBALS['wp_fts_test_filters']['wpml_post_language_details'] = static function (mixed $value, int $postId): mixed {
        return $postId === 20 ? ['locale' => 'fr_FR'] : $value;
    };

    try {
        $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);

        $polylang = test_lang_by_term($analyzer->analyze_content('<p>oraz jest</p>', ['post_id' => 10]));
        assert_same('pl-PL', $polylang['oraz'] ?? null, 'Polylang post locale should resolve untagged document language');
        assert_same('pl-PL', $polylang['jest'] ?? null, 'Polylang post locale should apply to the whole untagged span');

        $explicitOption = test_lang_by_term($analyzer->analyze_content('<p>oraz jest</p>', [
            'post_id' => 10,
            'lang' => 'en',
        ]));
        assert_same('en', $explicitOption['oraz'] ?? null, 'explicit document lang should override Polylang metadata');

        $explicitSegments = test_lang_by_term($analyzer->analyze_content(
            '<article><p lang="en">oraz jest</p><p xml:lang="de">Führung und Straße</p></article>',
            ['post_id' => 10]
        ));
        assert_same('en', $explicitSegments['oraz'] ?? null, 'HTML lang should override Polylang metadata');
        assert_same('de', $explicitSegments['fuehrung'] ?? null, 'xml:lang should override Polylang metadata');
        assert_same('de', $explicitSegments['und'] ?? null, 'weak connector should inherit explicit xml:lang');

        $wpml = test_lang_by_term($analyzer->analyze_content('<p>Führung und Straße</p>', ['post_id' => 20]));
        assert_same('fr-FR', $wpml['fuhrung'] ?? null, 'WPML post locale should resolve untagged document language');
        assert_same('fr-FR', $wpml['strasse'] ?? null, 'WPML post locale should beat detector evidence from the text');
        assert_same(null, $wpml['fuehrung'] ?? null, 'WPML metadata should prevent German-specific normalization for this fixture');

        $GLOBALS['wp_fts_ldgf_polylang_current_language'] = 'pl_PL';
        $currentQuery = test_lang_by_term($analyzer->analyze_query_occurrences('oraz jest'));
        assert_same('pl-PL', $currentQuery['oraz'] ?? null, 'Polylang current language should resolve untagged query language');

        $queryOverride = test_lang_by_term($analyzer->analyze_query_occurrences('oraz jest', ['query_lang' => 'en']));
        assert_same('en', $queryOverride['oraz'] ?? null, 'explicit query_lang should override Polylang current language');

        $GLOBALS['wp_fts_ldgf_polylang_current_language'] = null;
        $GLOBALS['wp_fts_test_filters']['wpml_current_language'] = static fn(mixed $value): string => 'tr_TR';
        $wpmlQuery = test_lang_by_term($analyzer->analyze_query_occurrences('İstanbul Isparta'));
        assert_same('tr-TR', $wpmlQuery['istanbul'] ?? null, 'WPML current language should resolve query language when Polylang is absent');
        assert_same('tr-TR', $wpmlQuery['ısparta'] ?? null, 'WPML current language should preserve Turkish normalization');
    } finally {
        wp_fts_ldgf_reset_multilingual_globals();
    }
});

test_case('quality language detection gold fixtures keep custom resolvers authoritative', function (): void {
    $documentAnalyzer = new WP_FTS_Analyzer([
        'default_lang' => 'en',
        'document_language_resolver' => static fn(array $options): ?string => ($options['post_id'] ?? 0) === 30 ? 'fr_FR' : null,
    ]);
    $documentLangs = test_lang_by_term($documentAnalyzer->analyze_content('<p>Führung und Straße</p>', ['post_id' => 30]));
    assert_same('fr-FR', $documentLangs['fuhrung'] ?? null, 'custom document resolver should beat strong detector evidence');
    assert_same('fr-FR', $documentLangs['strasse'] ?? null, 'custom document resolver should apply to the full untagged segment');

    $queryAnalyzer = new WP_FTS_Analyzer([
        'default_lang' => 'en',
        'query_language_resolver' => static fn(array $options): string => 'nl_NL',
        'query_term_language_resolver' => static function (string $token): ?string {
            return $token === 'zamek' ? 'pl' : null;
        },
    ]);
    $queryLangs = test_lang_by_term($queryAnalyzer->analyze_query_occurrences('zamek bridge'));
    assert_same('pl', $queryLangs['zamek'] ?? null, 'query term resolver should override query resolver for selected tokens');
    assert_same('nl-NL', $queryLangs['bridge'] ?? null, 'unresolved query tokens should inherit the custom query resolver language');
});

test_case('quality language detection gold fixtures preserve OR and AND language routing differences', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(1, '<p>Führung und Straße</p>');
    $indexer->index_document(2, '<p>Führung</p>', ['lang' => 'de']);
    $indexer->index_document(3, '<p>Straße</p>', ['lang' => 'de']);
    $indexer->index_document(4, '<p>Führung Straße</p>', ['lang' => 'en']);

    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    assert_same(
        [1, 2, 3],
        wp_fts_ldgf_result_ids($searcher->search('Führung Straße', ['mode' => 'OR', 'limit' => 10]), true),
        'OR should return all detected German documents matching either term'
    );
    assert_same(
        [1],
        wp_fts_ldgf_result_ids($searcher->search('Führung Straße', ['mode' => 'AND', 'limit' => 10])),
        'AND should require both detected German terms in one document'
    );
    assert_same(
        [1],
        wp_fts_ldgf_result_ids($searcher->search('Führung und Straße', ['mode' => 'AND', 'limit' => 10])),
        'AND should keep weak connector words inside the detected German span'
    );
    assert_same(
        [4],
        wp_fts_ldgf_result_ids($searcher->search('Führung Straße', ['lang' => 'en', 'mode' => 'AND', 'limit' => 10])),
        'explicit English AND query should isolate the English override partition'
    );
});
