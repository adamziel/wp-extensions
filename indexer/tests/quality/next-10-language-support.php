<?php
declare(strict_types=1);

$wp_fts_n10_direct = !function_exists('test_case');
if ($wp_fts_n10_direct) {
    require_once dirname(__DIR__) . '/bootstrap.php';

    final class WP_FTS_N10_TestFailure extends RuntimeException
    {
    }

    $GLOBALS['wp_fts_n10_tests'] = [];
    $GLOBALS['wp_fts_n10_check_count'] = 0;

    function test_case(string $name, callable $fn): void
    {
        $GLOBALS['wp_fts_n10_tests'][] = ['name' => $name, 'fn' => $fn];
    }

    function record_check(?string $label = null, int $count = 1): void
    {
        if ($count < 1) {
            throw new WP_FTS_N10_TestFailure('record_check() count must be at least 1.');
        }

        $GLOBALS['wp_fts_n10_check_count'] += $count;
    }

    function assert_true(bool $condition, string $message): void
    {
        record_check($message);
        if (!$condition) {
            throw new WP_FTS_N10_TestFailure($message);
        }
    }

    function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        record_check($message);
        if ($expected !== $actual) {
            throw new WP_FTS_N10_TestFailure($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
        }
    }

    function assert_contains(string $needle, string $haystack, string $message): void
    {
        record_check($message);
        if (!str_contains($haystack, $needle)) {
            throw new WP_FTS_N10_TestFailure($message . "\nMissing: " . var_export($needle, true));
        }
    }
}

/**
 * @return array<string,string>
 */
function wp_fts_n10_pack_manifests(): array
{
    $root = dirname(__DIR__, 2);

    return [
        'de' => $root . '/resources/analyzer-packs/de-unimorph-deu-d226d2112d34/manifest.json',
        'fa' => $root . '/resources/analyzer-packs/fa-unimorph-fas-b0e4f832b6ff/manifest.json',
        'it' => $root . '/resources/analyzer-packs/it-unimorph-ita-fa2cc6ce1736/manifest.json',
        'nl' => $root . '/resources/analyzer-packs/nl-unimorph-nld-7654cbfbb815/manifest.json',
        'ru' => $root . '/resources/analyzer-packs/ru-unimorph-rus-50dcabfd0a04/manifest.json',
        'te' => $root . '/resources/analyzer-packs/te-unimorph-tel-551f60f5f434/manifest.json',
        'tr' => $root . '/resources/analyzer-packs/tr-unimorph-tur-6c179ace7d2f/manifest.json',
        'uk' => $root . '/resources/analyzer-packs/uk-unimorph-ukr-d7d0284e926b/manifest.json',
    ];
}

/**
 * @return array<string,string>
 */
function wp_fts_n10_support_kinds(): array
{
    return [
        'ru' => 'lemma_pack',
        'de' => 'lemma_pack',
        'ja' => 'tokenizer',
        'ko' => 'tokenizer',
        'te' => 'lemma_pack',
        'tr' => 'lemma_pack',
        'it' => 'lemma_pack',
        'fa' => 'lemma_pack',
        'uk' => 'lemma_pack',
        'nl' => 'lemma_pack',
    ];
}

function wp_fts_n10_analyzer(): WP_FTS_Analyzer
{
    return new WP_FTS_Analyzer([
        'default_lang' => 'en',
        'lemma_packs_by_lang' => wp_fts_n10_pack_manifests(),
    ]);
}

/**
 * @return int[]
 */
function wp_fts_n10_result_ids(array $rows): array
{
    return array_values(array_map('intval', array_column($rows, 'doc_id')));
}

/**
 * @return array<int,array{lang:string,text:string}>
 */
function wp_fts_n10_detection_cases(): array
{
    return [
        ['lang' => 'ru', 'text' => 'русский поиск и индекс'],
        ['lang' => 'de', 'text' => 'Führung und Straße Suche'],
        ['lang' => 'ja', 'text' => '検索できます'],
        ['lang' => 'ko', 'text' => '한국어 검색 색인'],
        ['lang' => 'te', 'text' => 'తెలుగు శోధన సూచిక'],
        ['lang' => 'tr', 'text' => 'İstanbul arama dizin veri'],
        ['lang' => 'it', 'text' => 'la ricerca italiana con dati'],
        ['lang' => 'fa', 'text' => 'گزارش فارسی جستجو فهرست'],
        ['lang' => 'uk', 'text' => 'українська мова пошук індекс'],
        ['lang' => 'nl', 'text' => 'de zoeken voor het index'],
    ];
}

/**
 * @return array<int,array{id:string,lang:string,support:string,target:string,query:string,bait_lang:string,bait:string}>
 */
function wp_fts_n10_search_cases(): array
{
    return [
        ['id' => 'ru-unimorph-iskal', 'lang' => 'ru', 'support' => 'lemma_pack', 'target' => 'Он искал документы.', 'query' => 'искать', 'bait_lang' => 'uk', 'bait' => 'искал искать'],
        ['id' => 'de-unimorph-suchten', 'lang' => 'de', 'support' => 'lemma_pack', 'target' => 'Wir suchten wichtige Hinweise.', 'query' => 'suchen', 'bait_lang' => 'en', 'bait' => 'suchten suchen'],
        ['id' => 'ja-cjk-ngram', 'lang' => 'ja', 'support' => 'tokenizer', 'target' => '検索できます', 'query' => '検索', 'bait_lang' => 'zh', 'bait' => '検索できます'],
        ['id' => 'ko-hangul-ngram', 'lang' => 'ko', 'support' => 'tokenizer', 'target' => '검색합니다', 'query' => '검색', 'bait_lang' => 'zh', 'bait' => '검색합니다'],
        ['id' => 'te-unimorph-andincharu', 'lang' => 'te', 'support' => 'lemma_pack', 'target' => 'వారు అందించారు', 'query' => 'అందించు', 'bait_lang' => 'en', 'bait' => 'అందించారు అందించు'],
        ['id' => 'tr-unimorph-aradilar', 'lang' => 'tr', 'support' => 'lemma_pack', 'target' => 'Belgeleri aradılar.', 'query' => 'aramak', 'bait_lang' => 'en', 'bait' => 'aradılar aramak'],
        ['id' => 'it-unimorph-cercando', 'lang' => 'it', 'support' => 'lemma_pack', 'target' => 'Stiamo cercando documenti.', 'query' => 'cercare', 'bait_lang' => 'en', 'bait' => 'cercando cercare'],
        ['id' => 'fa-unimorph-istadand', 'lang' => 'fa', 'support' => 'lemma_pack', 'target' => 'آنها ایستادند', 'query' => 'ایستادن', 'bait_lang' => 'ar', 'bait' => 'ایستادند ایستادن'],
        ['id' => 'uk-unimorph-abazhura', 'lang' => 'uk', 'support' => 'lemma_pack', 'target' => 'Колір абажура змінився.', 'query' => 'абажур', 'bait_lang' => 'ru', 'bait' => 'абажура абажур'],
        ['id' => 'nl-unimorph-aanbaden', 'lang' => 'nl', 'support' => 'lemma_pack', 'target' => 'Zij aanbaden het voorbeeld.', 'query' => 'aanbidden', 'bait_lang' => 'en', 'bait' => 'aanbaden aanbidden'],
    ];
}

test_case('quality next 10 language support routes detector evidence for every added language', function (): void {
    $detector = new WP_FTS_LanguageDetector();
    $analyzer = wp_fts_n10_analyzer();

    foreach (wp_fts_n10_detection_cases() as $case) {
        assert_same($case['lang'], $detector->detect_text($case['text']), "{$case['lang']} detector fixture");
        $queryLangs = [];
        foreach ($analyzer->analyze_query_occurrences($case['text']) as $occurrence) {
            $queryLangs[(string) ($occurrence['lang'] ?? '')] = true;
        }
        unset($queryLangs['']);
        assert_same([$case['lang']], array_keys($queryLangs), "{$case['lang']} query routing should use one language partition");
    }
});

test_case('quality next 10 language support retrieves variants and isolates bait partitions', function (): void {
    $gzipAvailable = WP_FTS_AnalyzerPackValidator::gzip_available();
    foreach (wp_fts_n10_search_cases() as $offset => $case) {
        if ($case['support'] === 'lemma_pack' && !$gzipAvailable) {
            assert_true(is_file(wp_fts_n10_pack_manifests()[$case['lang']] ?? ''), "{$case['id']} compressed pack manifest should still be present without zlib");
            continue;
        }

        $analyzer = wp_fts_n10_analyzer();
        $storage = new WP_FTS_Storage_InMemory();
        $indexer = new WP_FTS_Indexer($storage, $analyzer);
        $searcher = new WP_FTS_Searcher($storage, $analyzer);
        $targetId = 7000 + $offset;
        $baitId = 8000 + $offset;

        $indexer->index_document($targetId, '<article><p>' . $case['target'] . '</p></article>', ['lang' => $case['lang']]);
        $indexer->index_document($baitId, '<article><p>' . $case['bait'] . '</p></article>', ['lang' => $case['bait_lang']]);

        $results = $searcher->search($case['query'], [
            'query_lang' => $case['lang'],
            'mode' => 'AND',
            'limit' => 5,
        ]);
        $ids = wp_fts_n10_result_ids($results);

        assert_true($ids !== [], "{$case['id']} should return a result through {$case['support']}");
        assert_same($targetId, $ids[0] ?? null, "{$case['id']} target should be top result");
        assert_true(!in_array($baitId, $ids, true), "{$case['id']} bait partition should not match");
    }

    record_check('next 10 retrieval scenarios', count(wp_fts_n10_search_cases()));
});

test_case('quality next 10 language support registry, packs, admin labels, and docs agree', function (): void {
    $root = dirname(__DIR__, 2);
    $registry = json_decode((string) file_get_contents($root . '/config/top-language-lemma-packs.json'), true);
    assert_true(is_array($registry), 'top-language registry should decode');

    $nextEntries = [];
    foreach (($registry['languages'] ?? []) as $entry) {
        if (($entry['role'] ?? '') === 'next-10' && is_scalar($entry['language'] ?? null)) {
            $nextEntries[(string) $entry['language']] = $entry;
        }
    }

    $expectedKinds = wp_fts_n10_support_kinds();
    assert_same(10, count($nextEntries), 'registry should claim exactly 10 next-language entries');
    foreach ($expectedKinds as $language => $kind) {
        assert_true(isset($nextEntries[$language]), "registry should include {$language}");
        assert_same($kind, $nextEntries[$language]['support_kind'] ?? null, "registry support_kind for {$language}");
    }

    foreach (wp_fts_n10_pack_manifests() as $language => $manifestPath) {
        assert_true(is_file($manifestPath), "{$language} manifest should exist");
    }

    $bundled = WP_FTS_AnalyzerPackValidator::bundled_unimorph_top_language_pack_manifests();
    foreach (array_keys(wp_fts_n10_pack_manifests()) as $language) {
        assert_true(isset($bundled[$language]), "{$language} should be discoverable as a bundled UniMorph pack");
    }
    foreach (['ja', 'ko'] as $language) {
        assert_true(!isset($bundled[$language]), "{$language} should not claim a runtime lemma pack");
    }

    $method = new ReflectionMethod(WP_FTS_Plugin::class, 'sandbox_language_labels');
    $method->setAccessible(true);
    $labels = $method->invoke(null);
    foreach (array_keys($expectedKinds) as $language) {
        assert_true(isset($labels[$language]), "admin sandbox selector should include {$language}");
    }

    $docs = [
        'README.md' => (string) file_get_contents($root . '/README.md'),
        'docs/configuration.md' => (string) file_get_contents($root . '/docs/configuration.md'),
        'docs/limitations.md' => (string) file_get_contents($root . '/docs/limitations.md'),
    ];
    foreach ($docs as $path => $contents) {
        foreach (['Russian (`ru`)', 'German (`de`)', 'Japanese (`ja`)', 'Korean (`ko`)', 'Telugu (`te`)', 'Turkish (`tr`)', 'Italian (`it`)', 'Persian (`fa`)', 'Ukrainian (`uk`)', 'Dutch (`nl`)'] as $claim) {
            assert_contains($claim, $contents, "{$path} should document {$claim}");
        }
    }
});

test_case('quality next 10 language support records source submodules without raw source vendoring', function (): void {
    $repoRoot = dirname(__DIR__, 3);
    $gitmodules = (string) file_get_contents($repoRoot . '/.gitmodules');
    foreach (['rus', 'deu', 'jpn', 'kor', 'tel', 'tur', 'ita', 'fas', 'ukr', 'nld'] as $source) {
        assert_contains("indexer/resources/sources/unimorph/{$source}", $gitmodules, "UniMorph {$source} source should be a submodule");
    }

    foreach (['pnb', 'jav', 'vie', 'mar', 'tam'] as $source) {
        assert_true(!str_contains($gitmodules, "indexer/resources/sources/unimorph/{$source}"), "unsupported replacement source {$source} should not be committed as a submodule");
    }
});

if ($wp_fts_n10_direct) {
    $failures = 0;
    $start = microtime(true);
    foreach ($GLOBALS['wp_fts_n10_tests'] as $test) {
        try {
            ($test['fn'])();
            fwrite(STDOUT, "[PASS] {$test['name']}\n");
        } catch (Throwable $e) {
            $failures++;
            fwrite(STDERR, "[FAIL] {$test['name']}\n{$e->getMessage()}\n{$e->getTraceAsString()}\n");
        }
    }

    $duration = number_format(microtime(true) - $start, 3);
    $count = count($GLOBALS['wp_fts_n10_tests']);
    $passed = $count - $failures;
    $checks = (int) $GLOBALS['wp_fts_n10_check_count'];
    $summary = "{$passed}/{$count} next 10 language support tests passed; failures={$failures}; checks/scenarios={$checks}; duration={$duration}s\n";
    if ($failures > 0) {
        fwrite(STDERR, $summary);
        exit(1);
    }

    fwrite(STDOUT, $summary);
}
