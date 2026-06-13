<?php
declare(strict_types=1);

$wp_fts_tslr_direct = !function_exists('test_case');
if ($wp_fts_tslr_direct) {
    require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

    final class WP_FTS_TSLR_TestFailure extends RuntimeException
    {
    }

    $GLOBALS['wp_fts_tslr_tests'] = [];
    $GLOBALS['wp_fts_tslr_check_count'] = 0;

    function test_case(string $name, callable $fn): void
    {
        $GLOBALS['wp_fts_tslr_tests'][] = ['name' => $name, 'fn' => $fn];
    }

    function record_check(?string $label = null, int $count = 1): void
    {
        if ($count < 1) {
            throw new WP_FTS_TSLR_TestFailure('record_check() count must be at least 1.');
        }

        $GLOBALS['wp_fts_tslr_check_count'] += $count;
    }

    function assert_true(bool $condition, string $message): void
    {
        record_check($message);
        if (!$condition) {
            throw new WP_FTS_TSLR_TestFailure($message);
        }
    }

    function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        record_check($message);
        if ($expected !== $actual) {
            throw new WP_FTS_TSLR_TestFailure($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
        }
    }
}

/**
 * @return int[]
 */
function wp_fts_tslr_result_ids(array $rows): array
{
    return array_values(array_map('intval', array_column($rows, 'doc_id')));
}

function wp_fts_tslr_polish_pack(): string|false
{
    $manifest = dirname(__DIR__, 2) . '/resources/analyzer-packs/pl-morfologik-polimorf-fixture/manifest.json';

    return is_file($manifest) ? $manifest : false;
}

/**
 * @return array<int,array{id:string,lang:string,target_id:int,bait_id:int,target:string,query:string,bait_lang:string,bait:string}>
 */
function wp_fts_tslr_cases(): array
{
    return [
        [
            'id' => 'en-running-run',
            'lang' => 'en',
            'target_id' => 1001,
            'bait_id' => 2001,
            'target' => 'Running steadily builds the search index.',
            'query' => 'run',
            'bait_lang' => 'es',
            'bait' => 'run',
        ],
        [
            'id' => 'zh-cjk-bigram',
            'lang' => 'zh',
            'target_id' => 1002,
            'bait_id' => 2002,
            'target' => '搜索索引语言',
            'query' => '搜索',
            'bait_lang' => 'en',
            'bait' => '搜索',
        ],
        [
            'id' => 'hi-native-script',
            'lang' => 'hi',
            'target_id' => 1003,
            'bait_id' => 2003,
            'target' => 'यह हिंदी खोज के लिए स्पष्ट पाठ है',
            'query' => 'हिंदी खोज',
            'bait_lang' => 'en',
            'bait' => 'हिंदी खोज',
        ],
        [
            'id' => 'es-buscar-buscando',
            'lang' => 'es',
            'target_id' => 1004,
            'bait_id' => 2004,
            'target' => 'Estamos buscando datos claros para el indice.',
            'query' => 'buscar',
            'bait_lang' => 'en',
            'bait' => 'buscar buscando',
        ],
        [
            'id' => 'ar-unvocalized-vocalized',
            'lang' => 'ar',
            'target_id' => 1005,
            'bait_id' => 2005,
            'target' => 'اَلْبَحْثُ العربي واضح',
            'query' => 'البحث',
            'bait_lang' => 'fa',
            'bait' => 'فارسی جستجو البحث',
        ],
        [
            'id' => 'fr-infinitive-inflected',
            'lang' => 'fr',
            'target_id' => 1006,
            'bait_id' => 2006,
            'target' => 'Les enfants mangent rapidement.',
            'query' => 'manger',
            'bait_lang' => 'en',
            'bait' => 'manger mangent',
        ],
        [
            'id' => 'bn-native-script',
            'lang' => 'bn',
            'target_id' => 1007,
            'bait_id' => 2007,
            'target' => 'এই বাংলা অনুসন্ধান এবং সূচি পাঠ',
            'query' => 'বাংলা অনুসন্ধান',
            'bait_lang' => 'en',
            'bait' => 'বাংলা অনুসন্ধান',
        ],
        [
            'id' => 'pt-infinitive-inflected',
            'lang' => 'pt',
            'target_id' => 1008,
            'bait_id' => 2008,
            'target' => 'Estamos pesquisando dados claros.',
            'query' => 'pesquisar',
            'bait_lang' => 'en',
            'bait' => 'pesquisar pesquisando',
        ],
        [
            'id' => 'id-affix-baseline',
            'lang' => 'id',
            'target_id' => 1009,
            'bait_id' => 2009,
            'target' => 'Kami sedang mencari data pencarian.',
            'query' => 'cari',
            'bait_lang' => 'en',
            'bait' => 'mencari pencarian cari',
        ],
        [
            'id' => 'ur-unvocalized-vocalized',
            'lang' => 'ur',
            'target_id' => 1010,
            'bait_id' => 2010,
            'target' => 'اُردُو تَلاش واضِح متن ہے',
            'query' => 'اردو تلاش',
            'bait_lang' => 'fa',
            'bait' => 'فارسی جستجو تلاش',
        ],
        [
            'id' => 'pl-pack-backed-baseline',
            'lang' => 'pl',
            'target_id' => 1011,
            'bait_id' => 2011,
            'target' => 'Kierujemy katalog w zamkach.',
            'query' => 'kierować zamek',
            'bait_lang' => 'en',
            'bait' => 'kierować zamek kierujemy zamkach',
        ],
    ];
}

test_case('quality top spoken language relevance covers baseline retrieval and bait isolation', function (): void {
    $seen = [];
    foreach (wp_fts_tslr_cases() as $case) {
        $seen[$case['lang']] = true;
        $analyzer = new WP_FTS_Analyzer([
            'default_lang' => 'en',
            'polish_lemma_pack' => wp_fts_tslr_polish_pack(),
        ]);
        $storage = new WP_FTS_Storage_InMemory();
        $indexer = new WP_FTS_Indexer($storage, $analyzer);
        $searcher = new WP_FTS_Searcher($storage, $analyzer);

        $indexer->index_document($case['target_id'], '<article><p>' . $case['target'] . '</p></article>', ['lang' => $case['lang']]);
        $indexer->index_document($case['bait_id'], '<article><p>' . $case['bait'] . '</p></article>', ['lang' => $case['bait_lang']]);

        $results = $searcher->search($case['query'], [
            'lang' => $case['lang'],
            'mode' => 'AND',
            'limit' => 5,
        ]);
        $ids = wp_fts_tslr_result_ids($results);

        assert_true($ids !== [], "{$case['id']} should return at least one result");
        assert_same($case['target_id'], $ids[0] ?? null, "{$case['id']} target document should be top result");
        assert_true(!in_array($case['bait_id'], $ids, true), "{$case['id']} cross-language bait should not be returned");
    }

    foreach (['en', 'zh', 'hi', 'es', 'ar', 'fr', 'bn', 'pt', 'id', 'ur', 'pl'] as $lang) {
        assert_true(isset($seen[$lang]), "top spoken language relevance should cover {$lang}");
    }

    record_check('top spoken language relevance scenarios', count($seen));
});

test_case('quality top spoken language relevance keeps Urdu routing clear of Persian-like bait', function (): void {
    $detector = new WP_FTS_LanguageDetector();

    assert_same('ur', $detector->detect_text('یہ اردو تلاش اور فہرست ہے'), 'clear Urdu text should still route to Urdu');
    assert_same(null, $detector->detect_text('فارسی جستجو'), 'Persian-like bait should not auto-route to Urdu');
});

if ($wp_fts_tslr_direct) {
    $failures = 0;
    $start = microtime(true);
    foreach ($GLOBALS['wp_fts_tslr_tests'] as $test) {
        try {
            ($test['fn'])();
            fwrite(STDOUT, "[PASS] {$test['name']}\n");
        } catch (Throwable $e) {
            $failures++;
            fwrite(STDERR, "[FAIL] {$test['name']}\n{$e->getMessage()}\n{$e->getTraceAsString()}\n");
        }
    }

    $duration = number_format(microtime(true) - $start, 3);
    $count = count($GLOBALS['wp_fts_tslr_tests']);
    $passed = $count - $failures;
    $checks = (int) $GLOBALS['wp_fts_tslr_check_count'];
    $summary = "{$passed}/{$count} top spoken language relevance tests passed; failures={$failures}; checks/scenarios={$checks}; duration={$duration}s\n";
    if ($failures > 0) {
        fwrite(STDERR, $summary);
        exit(1);
    }

    fwrite(STDOUT, $summary);
}
