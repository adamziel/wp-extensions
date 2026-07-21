<?php
declare(strict_types=1);

$wp_fts_ldgf_direct = !function_exists('test_case');
if ($wp_fts_ldgf_direct) {
    require_once dirname(__DIR__) . '/bootstrap.php';

    final class WP_FTS_LDGF_TestFailure extends RuntimeException
    {
    }

    $GLOBALS['wp_fts_ldgf_tests'] = [];
    $GLOBALS['wp_fts_ldgf_check_count'] = 0;
    $GLOBALS['wp_fts_test_filters'] = [];

    function test_case(string $name, callable $fn): void
    {
        $GLOBALS['wp_fts_ldgf_tests'][] = ['name' => $name, 'fn' => $fn];
    }

    function record_check(?string $label = null, int $count = 1): void
    {
        if ($count < 1) {
            throw new WP_FTS_LDGF_TestFailure('record_check() count must be at least 1.');
        }

        $GLOBALS['wp_fts_ldgf_check_count'] += $count;
    }

    function assert_true(bool $condition, string $message): void
    {
        record_check($message);
        if (!$condition) {
            throw new WP_FTS_LDGF_TestFailure($message);
        }
    }

    function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        record_check($message);
        if ($expected !== $actual) {
            throw new WP_FTS_LDGF_TestFailure($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
        }
    }

    /**
     * @return array<string,string>
     */
    function test_lang_by_term(array $occurrences): array
    {
        $langs = [];
        foreach ($occurrences as $occurrence) {
            $langs[$occurrence['term']] = $occurrence['lang'];
        }
        ksort($langs, SORT_STRING);

        return $langs;
    }

    function has_filter(string $hook_name): bool
    {
        $filter = $GLOBALS['wp_fts_test_filters'][$hook_name] ?? null;
        if (is_callable($filter)) {
            return true;
        }

        if (is_array($filter)) {
            foreach ($filter as $callback) {
                if (is_callable($callback)) {
                    return true;
                }
            }
        }

        return false;
    }

    function apply_filters(string $hook_name, mixed $value, mixed ...$args): mixed
    {
        $filter = $GLOBALS['wp_fts_test_filters'][$hook_name] ?? null;
        if (is_callable($filter)) {
            return $filter($value, ...$args);
        }

        if (is_array($filter)) {
            foreach ($filter as $callback) {
                if (is_callable($callback)) {
                    $value = $callback($value, ...$args);
                }
            }
        }

        return $value;
    }
}

if (!function_exists('pll_get_post_language')) {
    /** Model Polylang language reads and optionally expose per-post SQL fanout. */
    function pll_get_post_language(int $post_id, string $field = 'locale'): string|false
    {
        $GLOBALS['wp_fts_ldgf_polylang_post_language_calls'] = max(
            0,
            (int) ($GLOBALS['wp_fts_ldgf_polylang_post_language_calls'] ?? 0)
        ) + 1;
        if (
            !empty($GLOBALS['wp_fts_ldgf_polylang_query_per_call'])
            && isset($GLOBALS['wpdb'])
            && is_object($GLOBALS['wpdb'])
            && is_callable([$GLOBALS['wpdb'], 'get_var'])
        ) {
            $GLOBALS['wpdb']->get_var('SELECT language FROM wp_polylang_per_post_stub');
        }
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

/** Restore every multilingual-provider fake between independent gold cases. */
function wp_fts_ldgf_reset_multilingual_globals(): void
{
    $GLOBALS['wp_fts_ldgf_polylang_post_languages'] = [];
    $GLOBALS['wp_fts_ldgf_polylang_current_language'] = null;
    $GLOBALS['wp_fts_ldgf_polylang_post_language_calls'] = 0;
    $GLOBALS['wp_fts_ldgf_polylang_query_per_call'] = false;
    $GLOBALS['wp_fts_ldgf_wpml_post_languages'] = [];
    unset($GLOBALS['polylang'], $GLOBALS['sitepress']);
    $GLOBALS['wp_fts_test_filters']['wpml_post_language_details'] = null;
    $GLOBALS['wp_fts_test_filters']['wpml_current_language'] = null;
}

test_case('quality language detection gold fixtures keep untagged document and query span parity', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
    $cases = [
        [
            'label' => 'English search span',
            'text' => 'the search index uses clear language',
            'lang' => 'en',
            'terms' => ['the', 'search', 'index', 'use', 'clear', 'languag'],
        ],
        [
            'label' => 'Chinese Han-only non-space text',
            'text' => '搜索引擎',
            'lang' => 'zh',
            'terms' => ['搜', '索', '引', '擎', '搜索', '索引', '引擎'],
        ],
        [
            'label' => 'Hindi Devanagari search span',
            'text' => 'यह हिंदी खोज के लिए स्पष्ट पाठ है',
            'lang' => 'hi',
            'terms' => ['यह', 'हिंद', 'खोज', 'स्पष्ट', 'पाठ'],
        ],
        [
            'label' => 'Spanish lexical search span',
            'text' => 'la busqueda en espanol usa datos claros',
            'lang' => 'es',
            'terms' => ['busqued', 'espanol', 'dat', 'clar'],
        ],
        [
            'label' => 'Arabic script search span',
            'text' => 'هذا نص عربي للبحث والفهرسة',
            'lang' => 'ar',
            'terms' => ['هذا', 'عرب', 'بحث', 'والفهرس'],
        ],
        [
            'label' => 'French lexical search span',
            'text' => 'la recherche en francais utilise des donnees claires',
            'lang' => 'fr',
            'terms' => ['recherch', 'franc', 'utilis', 'donne', 'clair'],
        ],
        [
            'label' => 'Bengali script search span',
            'text' => 'এই বাংলা অনুসন্ধান এবং সূচি পাঠ',
            'lang' => 'bn',
            'terms' => ['এই', 'বাংলা', 'অনুসন্ধান', 'সূচি', 'পাঠ'],
        ],
        [
            'label' => 'Portuguese lexical search span',
            'text' => 'a pesquisa em portugues usa dados claros',
            'lang' => 'pt',
            'terms' => ['pesquis', 'portugu', 'dad', 'clar'],
        ],
        [
            'label' => 'Indonesian lexical search span',
            'text' => 'pencarian bahasa indonesia dengan data jelas',
            'lang' => 'id',
            'terms' => ['cari', 'bahasa', 'indonesia', 'data', 'jelas'],
        ],
        [
            'label' => 'Urdu script search span',
            'text' => 'یہ اردو تلاش اور فہرست کا متن ہے',
            'lang' => 'ur',
            'terms' => ['یہ', 'اردو', 'تلاش', 'فہرست', 'متن'],
        ],
        [
            'label' => 'Polish connector span',
            'text' => 'oraz jest',
            'lang' => 'pl',
            'terms' => ['oraz', 'jest'],
        ],
        [
            'label' => 'Polish place span with connector',
            'text' => 'Wrocław oraz Łódź',
            'lang' => 'pl',
            'terms' => ['wroclaw', 'oraz', 'lodz'],
        ],
        [
            'label' => 'German strong span with weak connector',
            'text' => 'Führung und Straße',
            'lang' => 'de',
            'terms' => ['fuehrung', 'und', 'strasse'],
        ],
        [
            'label' => 'German multi-connector span',
            'text' => 'Führung mit der Straße',
            'lang' => 'de',
            'terms' => ['fuehrung', 'mit', 'der', 'strasse'],
        ],
        [
            'label' => 'Japanese mixed kana and kanji non-space text',
            'text' => '検索できます',
            'lang' => 'ja',
            'terms' => ['検索', 'でき', 'ます'],
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

test_case('quality language detection gold fixtures align top spoken language identities', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
    $cases = [
        ['lang' => 'en', 'text' => 'the search index uses clear language', 'query' => 'search index clear'],
        ['lang' => 'zh', 'text' => '搜索索引语言', 'query' => '搜索索引'],
        ['lang' => 'hi', 'text' => 'यह हिंदी खोज के लिए स्पष्ट पाठ है', 'query' => 'हिंदी खोज स्पष्ट'],
        ['lang' => 'es', 'text' => 'la busqueda en espanol usa datos claros', 'query' => 'busqueda espanol datos'],
        ['lang' => 'ar', 'text' => 'هذا نص عربي للبحث والفهرسة', 'query' => 'هذا عربي للبحث'],
        ['lang' => 'fr', 'text' => 'la recherche en francais utilise des donnees claires', 'query' => 'recherche francais donnees'],
        ['lang' => 'bn', 'text' => 'এই বাংলা অনুসন্ধান এবং সূচি পাঠ', 'query' => 'বাংলা অনুসন্ধান সূচি'],
        ['lang' => 'pt', 'text' => 'a pesquisa em portugues usa dados claros', 'query' => 'pesquisa portugues dados'],
        ['lang' => 'id', 'text' => 'pencarian bahasa indonesia dengan data jelas', 'query' => 'pencarian indonesia data'],
        ['lang' => 'ur', 'text' => 'یہ اردو تلاش اور فہرست کا متن ہے', 'query' => 'اردو تلاش فہرست'],
    ];

    foreach ($cases as $case) {
        $document = test_lang_by_term($analyzer->analyze_content('<p>' . $case['text'] . '</p>'));
        $query = test_lang_by_term($analyzer->analyze_query_occurrences($case['query']));
        assert_same(
            $query,
            array_intersect_key($document, $query),
            "{$case['lang']} auto-routed document and query terms should share one language identity"
        );
    }
});

test_case('quality language detection gold fixtures preserve multi-token connector identity', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
    foreach ([
        ['lang' => 'pl', 'text' => 'Wrocław oraz Łódź'],
        ['lang' => 'de', 'text' => 'Führung mit der Straße'],
    ] as $case) {
        $document = test_lang_by_term($analyzer->analyze_content('<p>' . $case['text'] . '</p>'));
        $query = test_lang_by_term($analyzer->analyze_query_occurrences($case['text']));
        assert_same($query, array_intersect_key($document, $query), "{$case['lang']} connectors should keep document/query identity");
        $foreign = test_lang_by_term($analyzer->analyze_query_occurrences(
            $case['text'],
            ['query_lang' => $case['lang'] === 'pl' ? 'de' : 'pl']
        ));
        foreach ($foreign as $term => $language) {
            assert_true(
                ($document[$term] ?? null) !== $language,
                "an explicit foreign language should not share {$case['lang']} identity for {$term}"
            );
        }
    }
});

test_case('quality language detection gold fixtures keep accented English loanwords on fallback language', function (): void {
    $detector = new WP_FTS_LanguageDetector();
    assert_same('pl', $detector->detect_text('Zażółć gęślą jaźń oraz Łódź'), 'Polish diacritic-heavy text should route to Polish');
    assert_same('de', $detector->detect_text('über Straße Öl Führung'), 'German diacritic-heavy text should route to German');
    assert_same(null, $detector->detect_text('Beyonce Beyonce Beyoncé résumé cafe'), 'French-accented English names and loanwords should not detect as French');
    assert_same(null, $detector->detect_text('jalapeño piñata party'), 'Spanish-accented English loanwords should not detect as Spanish');
    assert_same(null, $detector->detect_text('Crème brûlée café résumé'), 'French culinary loanwords should stay below detector threshold');
    assert_same(null, $detector->detect_text('naïve façade touché'), 'French-accented English loanwords should stay below detector threshold');

    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
    $polishLangs = test_lang_by_term($analyzer->analyze_content('<p>Zażółć gęślą jaźń oraz Łódź</p>'));
    foreach (['zazolc', 'gesla', 'jazn', 'oraz', 'lodz'] as $term) {
        assert_same('pl', $polishLangs[$term] ?? null, "Polish diacritic-heavy content term {$term}");
    }

    $germanLangs = test_lang_by_term($analyzer->analyze_query_occurrences('über Straße Öl Führung'));
    foreach (['ueber', 'strasse', 'oel', 'fuehrung'] as $term) {
        assert_same('de', $germanLangs[$term] ?? null, "German diacritic-heavy query term {$term}");
    }

    $loanwordLangs = test_lang_by_term($analyzer->analyze_content('<p>Beyoncé résumé cafe jalapeño piñata party</p>'));
    foreach (['beyonc', 'resum', 'cafe', 'jalapeno', 'pinata', 'parti'] as $term) {
        assert_same('en', $loanwordLangs[$term] ?? null, "English loanword content term {$term}");
    }

    $queryLangs = test_lang_by_term($analyzer->analyze_query_occurrences('Beyoncé résumé jalapeño piñata naïve façade touché'));
    foreach (['beyonc', 'resum', 'jalapeno', 'pinata', 'naiv', 'facad', 'touch'] as $term) {
        assert_same('en', $queryLangs[$term] ?? null, "English loanword query term {$term}");
    }
});

test_case('quality language detection gold fixtures document unsupported and conservative boundaries', function (): void {
    $detector = new WP_FTS_LanguageDetector();
    foreach ([
        'Αθήνα και Θεσσαλονίκη' => 'unsupported Greek script should not be guessed',
        'שלום עולם' => 'unsupported Hebrew script should not be guessed',
        'ราคา search' => 'unsupported Thai plus Latin should not be guessed',
        'the and der die' => 'tied English and German lexical evidence should not pick a winner',
        'oraz jest und ist' => 'tied Polish and German lexical evidence should not pick a winner',
        'pesquisa pencarian' => 'tied Portuguese and Indonesian lexical evidence should not pick a winner',
    ] as $text => $message) {
        assert_same(null, $detector->detect_text($text), $message);
    }

    assert_same('ar', $detector->detect_text('هذا نص عربي للبحث'), 'clear Arabic script text should route to Arabic');
    assert_same('fa', $detector->detect_text('فارسی جستجو'), 'clear Persian lexical text should route to Persian');
    assert_same('fa', $detector->detect_text('سلام دنیا'), 'Persian-specific yeh should route shared Arabic-script text to Persian');
    assert_same('ur', $detector->detect_text('یہ اردو تلاش اور فہرست ہے'), 'Urdu letters should route Arabic-script text to Urdu');
    assert_same('ur', $detector->detect_text('اردو تلاش'), 'Urdu lexical evidence should route shared Arabic-script text to Urdu');

    assert_same(
        'zh',
        $detector->detect_text('東京 search'),
        'Han-only mixed Latin text should follow deterministic Han routing, not infer Japanese without kana evidence'
    );

    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
    $fallbackCases = [
        [
            'label' => 'unsupported Greek query',
            'text' => 'Αθήνα και Θεσσαλονίκη',
            'minimum_terms' => 3,
        ],
        [
            'label' => 'unsupported Thai plus Latin query',
            'text' => 'ราคา search',
            'minimum_terms' => 2,
        ],
        [
            'label' => 'tied English and German lexical query',
            'text' => 'the and der die',
            'minimum_terms' => 4,
        ],
        [
            'label' => 'tied Polish and German lexical query',
            'text' => 'oraz jest und ist',
            'minimum_terms' => 4,
        ],
    ];

    foreach ($fallbackCases as $case) {
        $queryOccurrences = $analyzer->analyze_query_occurrences($case['text']);
        $contentOccurrences = $analyzer->analyze_content('<p>' . $case['text'] . '</p>');

        assert_true(count($queryOccurrences) >= $case['minimum_terms'], "{$case['label']} should emit query fallback terms");
        assert_true(count($contentOccurrences) >= $case['minimum_terms'], "{$case['label']} should emit document fallback terms");

        foreach ($queryOccurrences as $occurrence) {
            assert_same('en', $occurrence['lang'] ?? null, "{$case['label']} query occurrence should use default language");
        }
        foreach ($contentOccurrences as $occurrence) {
            assert_same('en', $occurrence['lang'] ?? null, "{$case['label']} content occurrence should use default language");
        }
    }

    $hanMixedLangs = test_lang_by_term($analyzer->analyze_query_occurrences('東京 search'));
    assert_same('zh', $hanMixedLangs['東京'] ?? null, 'Han-only mixed query should route Han term to deterministic zh partition');
    assert_same('zh', $hanMixedLangs['search'] ?? null, 'Han-only mixed query should keep adjacent Latin term in the detected span partition');

    $persianLangs = test_lang_by_term($analyzer->analyze_query_occurrences('فارسی جستجو'));
    assert_same('fa', $persianLangs['فارسی'] ?? null, 'Persian lexical query should route to fa');
    assert_same('fa', $persianLangs['جستجو'] ?? null, 'Persian search term should route to fa');
});

test_case('quality language detection gold fixtures keep inline markup weak connector parity', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
    $contentLangs = test_lang_by_term($analyzer->analyze_content('<p>Führung <em>und</em> Straße</p>'));
    $queryLangs = test_lang_by_term($analyzer->analyze_query_occurrences('Führung und Straße'));

    foreach (['fuehrung', 'und', 'strasse'] as $term) {
        assert_same('de', $contentLangs[$term] ?? null, "inline German content term {$term}");
        assert_same('de', $queryLangs[$term] ?? null, "inline German query term {$term}");
    }
});

test_case('quality language detection gold fixtures isolate top-level text across block boundaries', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
    $html = 'Führung Straße<p>plain alpha</p>oraz jest';
    $contentLangs = test_lang_by_term($analyzer->analyze_content($html));

    assert_same('de', $contentLangs['fuehrung'] ?? null, 'leading top-level German term should route to de');
    assert_same('de', $contentLangs['strasse'] ?? null, 'leading top-level German suffix should route to de');
    assert_same('en', $contentLangs['plain'] ?? null, 'intervening ambiguous block text should remain fallback language');
    assert_same('pl', $contentLangs['oraz'] ?? null, 'trailing top-level Polish term should route to pl after a block boundary');
    assert_same('pl', $contentLangs['jest'] ?? null, 'trailing weak Polish term should not inherit German from earlier top-level text');

    assert_same(
        ['jest' => 'pl', 'oraz' => 'pl'],
        test_lang_by_term($analyzer->analyze_query_occurrences('oraz jest')),
        'the trailing Polish span should match the query analyzer partition'
    );
});

test_case('quality language detection gold fixtures honor explicit and preloaded multilingual metadata', function (): void {
    wp_fts_ldgf_reset_multilingual_globals();
    $GLOBALS['wp_fts_ldgf_polylang_post_languages'][10] = 'pl_PL';
    $GLOBALS['wp_fts_test_filters']['wpml_post_language_details'] = static function (mixed $value, int $postId): mixed {
        return $postId === 20 ? ['locale' => 'fr_FR'] : $value;
    };

    try {
        $analyzer_options = WP_FTS_Plugin::runtime_analyzer_options();
        $analyzer_options['lemma_packs_by_lang'] = [];
        $analyzer = new WP_FTS_Analyzer($analyzer_options);
        assert_true(!isset($analyzer_options['document_language_resolver']), 'runtime analyzer options must not install a provider-backed document resolver');
        assert_true(!isset($analyzer_options['query_language_resolver']), 'runtime analyzer options must not install a provider-backed query resolver');

        $polylangPost = (object) [
            'ID' => 10,
            'fts_language_override' => '',
            'fts_integration_language' => 'pl_PL',
        ];
        $polylangOptions = WP_FTS_Plugin::prepare_post_index_options($polylangPost);
        $polylang = test_lang_by_term($analyzer->analyze_content('<p>oraz jest</p>', [
            'document_lang' => $polylangOptions['document_lang'],
        ]));
        assert_same('pl-PL', $polylang['oraz'] ?? null, 'the preloaded Polylang locale should resolve untagged document language');
        assert_same('pl-PL', $polylang['jest'] ?? null, 'the preloaded Polylang locale should apply to the whole untagged span');

        $explicitOption = test_lang_by_term($analyzer->analyze_content('<p>oraz jest</p>', [
            'document_lang' => 'en',
        ]));
        assert_same('en', $explicitOption['oraz'] ?? null, 'an explicit document language should remain authoritative');

        $explicitSegments = test_lang_by_term($analyzer->analyze_content(
            '<article><p lang="en">oraz jest</p><p xml:lang="de">Führung und Straße</p></article>',
            ['document_lang' => $polylangOptions['document_lang']]
        ));
        assert_same('en', $explicitSegments['oraz'] ?? null, 'HTML lang should override Polylang metadata');
        assert_same('de', $explicitSegments['fuehrung'] ?? null, 'xml:lang should override Polylang metadata');
        assert_same('de', $explicitSegments['und'] ?? null, 'weak connector should inherit explicit xml:lang');

        $dataLang = test_lang_by_term($analyzer->analyze_content('<p data-lang="de">Wrocław oraz Łódź</p>'));
        assert_same('pl', $dataLang['wroclaw'] ?? null, 'data-lang should not override detector evidence');
        assert_same('pl', $dataLang['lodz'] ?? null, 'data-lang should not be treated as explicit language metadata');

        $arabicHtmlOverride = test_lang_by_term($analyzer->analyze_content('<p lang="en">هذا نص عربي للبحث</p>'));
        assert_same('en', $arabicHtmlOverride['هذا'] ?? null, 'explicit HTML lang should beat Arabic detector evidence');
        assert_same('en', $arabicHtmlOverride['عربي'] ?? null, 'explicit HTML lang should route Arabic-script content to the requested partition');

        $urduHtmlOverride = test_lang_by_term($analyzer->analyze_content('<p lang="ar">یہ اردو تلاش ہے</p>'));
        assert_same('ar', $urduHtmlOverride['اردو'] ?? null, 'explicit HTML lang should beat Urdu-specific detector evidence');
        assert_same('ar', $urduHtmlOverride['تلاش'] ?? null, 'explicit HTML lang should keep Urdu-script content in the requested partition');

        $strongEvidenceExplicitContent = test_lang_by_term($analyzer->analyze_content('<p lang="pl_PL">Führung und Straße</p>'));
        assert_same('pl-PL', $strongEvidenceExplicitContent['fuhrung'] ?? null, 'explicit HTML lang should beat strong German document evidence');
        assert_same('pl-PL', $strongEvidenceExplicitContent['und'] ?? null, 'explicit HTML lang should keep connector in override partition');
        assert_same('pl-PL', $strongEvidenceExplicitContent['strasse'] ?? null, 'explicit HTML lang should apply to the full strong-evidence document span');

        $wpmlPost = (object) [
            'ID' => 20,
            'fts_language_override' => '',
            'fts_integration_language' => 'fr_FR',
        ];
        $wpmlOptions = WP_FTS_Plugin::prepare_post_index_options($wpmlPost);
        $wpml = test_lang_by_term($analyzer->analyze_content('<p>Führung und Straße</p>', [
            'document_lang' => $wpmlOptions['document_lang'],
        ]));
        assert_same('fr-FR', $wpml['fuhrung'] ?? null, 'the preloaded WPML locale should resolve untagged document language');
        assert_same('fr-FR', $wpml['strass'] ?? null, 'the preloaded WPML locale should beat detector evidence from the text');
        assert_same(null, $wpml['fuehrung'] ?? null, 'preloaded WPML metadata should prevent German-specific normalization for this fixture');

        $GLOBALS['wp_fts_ldgf_polylang_current_language'] = 'pl_PL';
        $currentQuery = test_lang_by_term($analyzer->analyze_query_occurrences('alpha beta'));
        assert_same('en', $currentQuery['alpha'] ?? null, 'the runtime query analyzer should use detection/site default instead of Polylang request state');

        $queryOverride = test_lang_by_term($analyzer->analyze_query_occurrences('oraz jest', ['query_lang' => 'en']));
        assert_same('en', $queryOverride['oraz'] ?? null, 'explicit query_lang should override Polylang current language');

        $arabicQueryOverride = test_lang_by_term($analyzer->analyze_query_occurrences('هذا نص عربي للبحث', ['query_lang' => 'en']));
        assert_same('en', $arabicQueryOverride['هذا'] ?? null, 'explicit query_lang should beat Arabic query detector evidence');
        assert_same('en', $arabicQueryOverride['عربي'] ?? null, 'explicit query_lang should route Arabic query terms to the requested partition');

        $strongEvidenceQueryOverride = test_lang_by_term($analyzer->analyze_query_occurrences('Führung und Straße', ['query_lang' => 'pl_PL']));
        assert_same('pl-PL', $strongEvidenceQueryOverride['fuhrung'] ?? null, 'explicit query_lang should beat strong German query evidence');
        assert_same('pl-PL', $strongEvidenceQueryOverride['und'] ?? null, 'explicit query_lang should keep connector in override partition');
        assert_same('pl-PL', $strongEvidenceQueryOverride['strasse'] ?? null, 'explicit query_lang should apply to the full strong-evidence query span');

        $GLOBALS['wp_fts_ldgf_polylang_current_language'] = null;
        $wpmlCurrentCalls = 0;
        $GLOBALS['wp_fts_test_filters']['wpml_current_language'] = static function (mixed $value) use (&$wpmlCurrentCalls): string {
            $wpmlCurrentCalls++;
            return 'tr_TR';
        };
        $wpmlQuery = test_lang_by_term($analyzer->analyze_query_occurrences('alpha beta'));
        assert_same('en', $wpmlQuery['alpha'] ?? null, 'the runtime query analyzer should use detection/site default instead of WPML request state');
        assert_same(0, $wpmlCurrentCalls, 'runtime query analysis must not invoke the WPML current-language filter');
        assert_same(0, $GLOBALS['wp_fts_ldgf_polylang_post_language_calls'] ?? null, 'runtime document analysis must not invoke the Polylang per-post API');
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
    assert_same('fr-FR', $documentLangs['strass'] ?? null, 'custom document resolver should apply to the full untagged segment');

    $queryAnalyzer = new WP_FTS_Analyzer([
        'default_lang' => 'en',
        'query_language_resolver' => static fn(array $options): string => 'nl_NL',
        'query_term_language_resolver' => static function (string $token, array $_options, string $_defaultLang): ?string {
            return $token === 'zamek' ? 'pl' : null;
        },
    ]);
    $queryLangs = test_lang_by_term($queryAnalyzer->analyze_query_occurrences('zamek bridge'));
    assert_same('pl', $queryLangs['zamek'] ?? null, 'query term resolver should override query resolver for selected tokens');
    $bridgeTerm = array_key_exists('bridg', $queryLangs) ? 'bridg' : 'bridge';
    assert_same('nl-NL', $queryLangs[$bridgeTerm] ?? null, 'unresolved query tokens should inherit the custom query resolver language');
});

if ($wp_fts_ldgf_direct) {
    $failures = 0;
    $start = microtime(true);
    foreach ($GLOBALS['wp_fts_ldgf_tests'] as $test) {
        try {
            ($test['fn'])();
            fwrite(STDOUT, "[PASS] {$test['name']}\n");
        } catch (Throwable $e) {
            $failures++;
            fwrite(STDERR, "[FAIL] {$test['name']}\n{$e->getMessage()}\n{$e->getTraceAsString()}\n");
        }
    }

    $duration = number_format(microtime(true) - $start, 3);
    $count = count($GLOBALS['wp_fts_ldgf_tests']);
    $passed = $count - $failures;
    $checks = (int) $GLOBALS['wp_fts_ldgf_check_count'];
    $summary = "{$passed}/{$count} language detection gold fixture tests passed; failures={$failures}; checks/scenarios={$checks}; duration={$duration}s\n";
    if ($failures > 0) {
        fwrite(STDERR, $summary);
        exit(1);
    }

    fwrite(STDOUT, $summary);
}
