<?php
declare(strict_types=1);

$wp_fts_rigorous_direct = !function_exists('test_case');
if ($wp_fts_rigorous_direct) {
    require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

    final class WP_FTS_Rigorous_TestFailure extends RuntimeException
    {
    }

    final class WP_FTS_Rigorous_TestPending extends RuntimeException
    {
    }

    $GLOBALS['wp_fts_rigorous_tests'] = [];
    $GLOBALS['wp_fts_rigorous_check_count'] = 0;

    function test_case(string $name, callable $fn): void
    {
        $GLOBALS['wp_fts_rigorous_tests'][] = ['name' => $name, 'fn' => $fn];
    }

    function record_check(?string $label = null, int $count = 1): void
    {
        if ($count < 1) {
            throw new WP_FTS_Rigorous_TestFailure('record_check() count must be at least 1.');
        }

        $GLOBALS['wp_fts_rigorous_check_count'] += $count;
    }

    function assert_true(bool $condition, string $message): void
    {
        record_check($message);
        if (!$condition) {
            throw new WP_FTS_Rigorous_TestFailure($message);
        }
    }

    function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        record_check($message);
        if ($expected !== $actual) {
            throw new WP_FTS_Rigorous_TestFailure($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
        }
    }

    function assert_contains(string $needle, string $haystack, string $message): void
    {
        record_check($message);
        if (!str_contains($haystack, $needle)) {
            throw new WP_FTS_Rigorous_TestFailure($message . "\nMissing: " . var_export($needle, true));
        }
    }

    function mark_pending(string $message): never
    {
        throw new WP_FTS_Rigorous_TestPending($message);
    }
}

/**
 * @return array<string,string>
 */
function qrs_next_language_pack_manifests(): array
{
    $root = dirname(__DIR__, 2);

    return [
        'de' => $root . '/resources/analyzer-packs/de-unimorph-deu-d226d2112d34/manifest.json',
        'nl' => $root . '/resources/analyzer-packs/nl-unimorph-nld-7654cbfbb815/manifest.json',
        'tr' => $root . '/resources/analyzer-packs/tr-unimorph-tur-6c179ace7d2f/manifest.json',
        'ru' => $root . '/resources/analyzer-packs/ru-unimorph-rus-50dcabfd0a04/manifest.json',
        'it' => $root . '/resources/analyzer-packs/it-unimorph-ita-fa2cc6ce1736/manifest.json',
        'uk' => $root . '/resources/analyzer-packs/uk-unimorph-ukr-d7d0284e926b/manifest.json',
        'fa' => $root . '/resources/analyzer-packs/fa-unimorph-fas-b0e4f832b6ff/manifest.json',
        'te' => $root . '/resources/analyzer-packs/te-unimorph-tel-551f60f5f434/manifest.json',
    ];
}

/**
 * @return array<string,string>
 */
function qrs_pack_manifests(): array
{
    $root = dirname(__DIR__, 2);

    return qrs_next_language_pack_manifests() + [
        'pl' => $root . '/resources/analyzer-packs/pl-polimorf-20180722-full-playground/manifest.json',
    ];
}

function qrs_require_gzip_packs_available(string $scenario): void
{
    if (WP_FTS_AnalyzerPackValidator::gzip_available()) {
        return;
    }

    foreach (qrs_pack_manifests() as $language => $manifestPath) {
        assert_true(is_file($manifestPath), "{$scenario}: {$language} compressed pack manifest exists without zlib");
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $runtimeFiles = is_array($manifest) ? ($manifest['runtime']['files'] ?? []) : [];
        assert_true(is_array($runtimeFiles) && $runtimeFiles !== [], "{$scenario}: {$language} manifest records compressed runtime files");
        foreach ($runtimeFiles as $file) {
            if (is_array($file) && ($file['compression'] ?? null) === 'gzip' && is_scalar($file['path'] ?? null)) {
                assert_true(is_file(dirname($manifestPath) . '/' . (string) $file['path']), "{$scenario}: {$language} gzip runtime shard exists");
                break;
            }
        }
    }

    mark_pending("{$scenario}: zlib is unavailable under php -n; gzip-backed analyzer packs are present but cannot be exercised in this process.");
}

function qrs_analyzer(bool $withPacks = true): WP_FTS_Analyzer
{
    return new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'default_lang' => 'en',
        'lemmatizer_packs_by_lang' => $withPacks ? qrs_pack_manifests() : [],
    ]);
}

/**
 * @return array{storage:WP_FTS_Storage_InMemory,indexer:WP_FTS_Indexer,searcher:WP_FTS_Searcher,analyzer:WP_FTS_Analyzer}
 */
function qrs_fixture(bool $withPacks = true): array
{
    $analyzer = qrs_analyzer($withPacks);
    $storage = new WP_FTS_Storage_InMemory();

    return [
        'storage' => $storage,
        'indexer' => new WP_FTS_Indexer($storage, $analyzer),
        'searcher' => new WP_FTS_Searcher($storage, $analyzer),
        'analyzer' => $analyzer,
    ];
}

/**
 * @return int[]
 */
function qrs_ids(array $rows): array
{
    return array_values(array_map('intval', array_column($rows, 'doc_id')));
}

/**
 * @return array{surface:string,lemma:string,manifest:string,pack_id:string}
 */
function qrs_pack_surface_lemma_pair(string $language): array
{
    static $cache = [];
    if (isset($cache[$language])) {
        return $cache[$language];
    }

    $manifestPath = qrs_pack_manifests()[$language] ?? null;
    if (!is_string($manifestPath) || !is_file($manifestPath)) {
        throw new RuntimeException("No analyzer pack manifest configured for {$language}.");
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest)) {
        throw new RuntimeException("Could not decode analyzer pack manifest for {$language}.");
    }

    $runtimeFiles = $manifest['runtime']['files'] ?? [];
    if (!is_array($runtimeFiles) || $runtimeFiles === []) {
        throw new RuntimeException("Analyzer pack {$language} has no runtime files.");
    }

    foreach ($runtimeFiles as $file) {
        if (!is_array($file) || !is_scalar($file['path'] ?? null)) {
            continue;
        }

        $compression = is_scalar($file['compression'] ?? null) ? (string) $file['compression'] : '';
        $path = dirname($manifestPath) . '/' . (string) $file['path'];
        $handle = qrs_open_runtime_file($path, $compression);
        if (!is_resource($handle)) {
            continue;
        }

        $currentSurface = null;
        $lemmas = [];
        try {
            while (($line = qrs_read_runtime_line($handle, $compression)) !== false) {
                $line = rtrim((string) $line, "\r\n");
                if ($line === '' || $line[0] === '#') {
                    continue;
                }

                $columns = explode("\t", $line, 3);
                if (count($columns) < 2) {
                    continue;
                }

                $surface = $columns[0];
                $lemma = $columns[1];
                if ($currentSurface !== null && $surface !== $currentSurface) {
                    $candidate = qrs_candidate_pair($language, $manifestPath, (string) ($manifest['pack_id'] ?? ''), $currentSurface, $lemmas);
                    if ($candidate !== null) {
                        return $cache[$language] = $candidate;
                    }
                    $lemmas = [];
                }

                $currentSurface = $surface;
                $lemmas[$lemma] = true;
            }

            if ($currentSurface !== null) {
                $candidate = qrs_candidate_pair($language, $manifestPath, (string) ($manifest['pack_id'] ?? ''), $currentSurface, $lemmas);
                if ($candidate !== null) {
                    return $cache[$language] = $candidate;
                }
            }
        } finally {
            qrs_close_runtime_file($handle, $compression);
        }
    }

    throw new RuntimeException("Could not locate a stable unambiguous surface/lemma pair for {$language}.");
}

/**
 * @param array<string,bool> $lemmas
 * @return array{surface:string,lemma:string,manifest:string,pack_id:string}|null
 */
function qrs_candidate_pair(string $language, string $manifestPath, string $packId, string $surface, array $lemmas): ?array
{
    if (count($lemmas) !== 1) {
        return null;
    }

    $lemma = (string) array_key_first($lemmas);
    if ($surface === $lemma || !qrs_reasonable_runtime_token($surface, 3) || !qrs_reasonable_runtime_token($lemma, 2)) {
        return null;
    }

    $pack = WP_FTS_LanguageLemmaPack::from_manifest_file($manifestPath, null, $language);
    $analysisTerms = array_map(
        static fn(array $row): string => (string) ($row['term'] ?? ''),
        $pack->analyze($surface, $language)
    );
    if (!in_array($lemma, $analysisTerms, true)) {
        return null;
    }

    return [
        'surface' => $surface,
        'lemma' => $lemma,
        'manifest' => $manifestPath,
        'pack_id' => $packId,
    ];
}

function qrs_reasonable_runtime_token(string $token, int $minimumCharacters): bool
{
    if (preg_match('/[0-9_]/u', $token) === 1 || preg_match('/\s/u', $token) === 1) {
        return false;
    }

    $characters = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($characters) && count($characters) >= $minimumCharacters;
}

function qrs_open_runtime_file(string $path, string $compression): mixed
{
    if ($compression === 'gzip') {
        return function_exists('gzopen') ? gzopen($path, 'rb') : false;
    }

    return fopen($path, 'rb');
}

function qrs_read_runtime_line(mixed $handle, string $compression): string|false
{
    return $compression === 'gzip' ? gzgets($handle) : fgets($handle);
}

function qrs_close_runtime_file(mixed $handle, string $compression): void
{
    if ($compression === 'gzip') {
        gzclose($handle);
        return;
    }

    fclose($handle);
}

function qrs_inline_split_word(string $word): string
{
    $characters = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($characters) || count($characters) < 4) {
        return '<strong>' . htmlspecialchars($word, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>';
    }

    $first = implode('', array_slice($characters, 0, 1));
    $middle = implode('', array_slice($characters, 1, -1));
    $last = implode('', array_slice($characters, -1));

    return htmlspecialchars($first, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '<strong><em>'
        . htmlspecialchars($middle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</em></strong>'
        . htmlspecialchars($last, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function qrs_rest_error_code(mixed $error): ?string
{
    if (is_object($error) && is_callable([$error, 'get_error_code'])) {
        return (string) $error->get_error_code();
    }

    return is_array($error) && is_scalar($error['code'] ?? null) ? (string) $error['code'] : null;
}

function qrs_rest_error_status(mixed $error): ?int
{
    if (is_object($error) && is_callable([$error, 'get_error_data'])) {
        $data = $error->get_error_data();
        return is_array($data) && is_numeric($data['status'] ?? null) ? (int) $data['status'] : null;
    }

    return is_array($error) && is_numeric($error['data']['status'] ?? null) ? (int) $error['data']['status'] : null;
}

/**
 * @return array<string,string>
 */
function qrs_language_baits(): array
{
    return [
        'en' => 'de',
        'pl' => 'en',
        'de' => 'en',
        'nl' => 'en',
        'tr' => 'en',
        'ru' => 'uk',
        'it' => 'en',
        'uk' => 'ru',
        'fa' => 'ar',
        'te' => 'en',
        'ja' => 'zh',
        'ko' => 'zh',
    ];
}

/**
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function qrs_metadata(int $docId, string $type, string $status, string $date, string $title, string $html, array $overrides = []): array
{
    return array_replace([
        'post_id' => $docId,
        'post_type' => $type,
        'post_status' => $status,
        'post_date_gmt' => $date,
        'title' => $title,
        'excerpt' => WP_FTS_Html_Text_Stream::visible_text($html),
        'search_html' => $html,
        'search_text' => WP_FTS_Html_Text_Stream::visible_text($html),
    ], $overrides);
}

/**
 * @return array<int,array{lang:string,query:string,surface:string,support:string,pack_id?:string}>
 */
function qrs_small_search_cases(): array
{
    $cases = [
        ['lang' => 'en', 'query' => 'run', 'surface' => 'running', 'support' => 'english-snowball'],
        ['lang' => 'ja', 'query' => '検索', 'surface' => '検索できます', 'support' => 'cjk-ngram'],
        ['lang' => 'ko', 'query' => '검색', 'surface' => '검색합니다', 'support' => 'cjk-ngram'],
    ];

    foreach (qrs_pack_manifests() as $language => $_manifest) {
        $pair = qrs_pack_surface_lemma_pair($language);
        $cases[] = [
            'lang' => $language,
            'query' => $pair['lemma'],
            'surface' => $pair['surface'],
            'support' => 'lemma-pack',
            'pack_id' => $pair['pack_id'],
        ];
    }

    usort($cases, static fn(array $a, array $b): int => strcmp($a['lang'], $b['lang']));

    return $cases;
}

test_case('quality rigorous FTS small corpus exercises pack-backed variants, partitions, filters, totals, and boolean modes', function (): void {
    qrs_require_gzip_packs_available('rigorous small corpus pack-backed search');

    $fixture = qrs_fixture(true);
    $indexer = $fixture['indexer'];
    $searcher = $fixture['searcher'];
    $baits = qrs_language_baits();
    $docId = 1100;

    foreach (qrs_small_search_cases() as $case) {
        $docId++;
        $targetId = $docId;
        $baitId = $docId + 1000;
        $lang = $case['lang'];
        $surface = $case['surface'];
        $query = $case['query'];
        $support = $case['support'];
        $baitLang = $baits[$lang] ?? 'en';
        $html = '<article lang="' . $lang . '"><h1>' . qrs_inline_split_word($surface) . '</h1><p>curated-' . $lang . ' anchor-' . $targetId . '</p><script>' . $query . ' hidden</script></article>';
        $baitHtml = '<article lang="' . $baitLang . '"><p>' . htmlspecialchars($surface . ' ' . $query, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' wrong partition bait</p></article>';

        assert_true($indexer->index_document($targetId, $html, [
            'lang' => $lang,
            'metadata' => qrs_metadata($targetId, $targetId % 2 === 0 ? 'page' : 'post', 'publish', '2026-03-01 00:00:00', "Target {$lang}", $html, ['language' => $lang]),
        ]), "{$lang} {$support} target should index");
        assert_true($indexer->index_document($baitId, $baitHtml, [
            'lang' => $baitLang,
            'metadata' => qrs_metadata($baitId, 'post', 'publish', '2026-03-02 00:00:00', "Bait {$lang}", $baitHtml, ['language' => $baitLang]),
        ]), "{$lang} wrong-language bait should index");

        $results = $searcher->search($query, ['lang' => $lang, 'mode' => 'AND', 'limit' => 5]);
        $ids = qrs_ids($results);
        assert_true($ids !== [], "{$lang} {$support} query should retrieve a real indexed document");
        assert_same($targetId, $ids[0] ?? null, "{$lang} {$support} target should be the top result");
        assert_true(!in_array($baitId, $ids, true), "{$lang} wrong-language bait should not leak into exact partition search");

        $snippetRows = $searcher->search($query, [
            'lang' => $lang,
            'limit' => 1,
            'include_metadata' => true,
            'include_snippets' => true,
            'highlight' => true,
            'snippet_length' => 90,
        ]);
        $snippet = (string) ($snippetRows[0]['snippet'] ?? '');
        assert_contains('<mark>', $snippet, "{$lang} snippet should highlight the matched visible word");
        assert_contains($surface, WP_FTS_Html_Text_Stream::visible_text($snippet), "{$lang} snippet visible text should contain the matched surface form");
        assert_true(!str_contains($snippet, 'hidden'), "{$lang} snippet should not leak script text");
    }

    $andDoc = '<article lang="en"><h1>atlas beacon</h1><p>sharedtopic publish target</p></article>';
    $orDocA = '<article lang="en"><p>atlas sharedtopic</p></article>';
    $orDocB = '<article lang="en"><p>beacon sharedtopic</p></article>';
    $draftDoc = '<article lang="en"><p>sharedtopic draft-only</p></article>';
    $indexer->index_document(3001, $andDoc, ['lang' => 'en', 'metadata' => qrs_metadata(3001, 'post', 'publish', '2026-04-01 00:00:00', 'AND target', $andDoc)]);
    $indexer->index_document(3002, $orDocA, ['lang' => 'en', 'metadata' => qrs_metadata(3002, 'page', 'publish', '2026-04-02 00:00:00', 'OR page', $orDocA)]);
    $indexer->index_document(3003, $orDocB, ['lang' => 'en', 'metadata' => qrs_metadata(3003, 'post', 'publish', '2026-04-03 00:00:00', 'OR post', $orDocB)]);
    $indexer->index_document(3004, $draftDoc, ['lang' => 'en', 'metadata' => qrs_metadata(3004, 'post', 'draft', '2026-04-04 00:00:00', 'Draft', $draftDoc)]);

    assert_same([3001], qrs_ids($searcher->search('atlas beacon', ['lang' => 'en', 'mode' => 'AND', 'limit' => 10])), 'AND search should require every logical term');
    $orIds = qrs_ids($searcher->search('atlas beacon', ['lang' => 'en', 'mode' => 'OR', 'limit' => 10]));
    assert_true(in_array(3001, $orIds, true) && in_array(3002, $orIds, true) && in_array(3003, $orIds, true), 'OR search should include one-term and two-term matches');
    assert_same([], $searcher->search('sharedtopic', ['lang' => 'sv', 'disable_language_fallback' => true]), 'unpopulated wrong language partition should return empty results');
    assert_same([3001], qrs_ids($searcher->search('atlas beacon', ['lang' => 'sv', 'fallback_languages' => ['en'], 'mode' => 'AND', 'limit' => 1])), 'explicit language fallback should recover default-language results');

    $payload = $searcher->search('sharedtopic', [
        'lang' => 'en',
        'limit' => 2,
        'offset' => 1,
        'include_total' => true,
        'include_metadata' => true,
        'post_type' => ['post'],
        'post_status' => ['publish'],
        'date_after' => '2026-04-01',
        'date_before' => '2026-04-03',
    ]);
    assert_same(2, $payload['total'], 'metadata filters should count only published posts in the date window');
    assert_same(1, count($payload['results']), 'pagination should return the requested filtered slice');
    assert_same('post', $payload['results'][0]['post_type'] ?? null, 'metadata enrichment should expose post type');
    assert_same('publish', $payload['results'][0]['post_status'] ?? null, 'metadata enrichment should expose post status');

    record_check('small rigorous corpus language count', count(qrs_small_search_cases()));
});

test_case('quality rigorous FTS hostile HTML lexing and formatted snippets do not index hidden content', function (): void {
    qrs_require_gzip_packs_available('rigorous hostile HTML pack-backed snippet');

    $fixture = qrs_fixture(true);
    $indexer = $fixture['indexer'];
    $searcher = $fixture['searcher'];
    $polishPair = qrs_pack_surface_lemma_pair('pl');
    $italianPair = qrs_pack_surface_lemma_pair('it');
    $html = '<article lang="pl">'
        . '<h1><strong>Word</strong>Press Szk<em>l<i><b>ar</b></i></em>nia W<em>ęgorz</em> chr<strong><em>ząs</em>tki</strong></h1>'
        . '<p>' . qrs_inline_split_word($polishPair['surface']) . ' oraz encje &amp; apostrof redakcji.</p>'
        . '<template>wordpress szklarnia ' . $polishPair['lemma'] . '</template><nav>chrząstki nawigacja</nav><style>.x{content:"węgorz"}</style><script>' . $italianPair['lemma'] . '</script>'
        . '</article>';

    $indexer->index_document(4100, $html, [
        'lang' => 'pl',
        'metadata' => qrs_metadata(4100, 'post', 'publish', '2026-05-01 00:00:00', 'Hostile HTML', $html, ['language' => 'pl']),
    ]);

    assert_same([4100], qrs_ids($searcher->search('wordpress', ['lang' => 'pl', 'limit' => 5])), 'split WordPress token should be searchable as one visible word');
    assert_same([4100], qrs_ids($searcher->search('szklarnia', ['lang' => 'pl', 'limit' => 5])), 'nested split Polish word should be searchable literally');
    assert_same([4100], qrs_ids($searcher->search('węgorz', ['lang' => 'pl', 'limit' => 5])), 'accented split Polish word should be searchable');
    assert_same([4100], qrs_ids($searcher->search('chrząstki', ['lang' => 'pl', 'limit' => 5])), 'deeply formatted Polish word should be searchable');
    assert_same([4100], qrs_ids($searcher->search($polishPair['lemma'], ['lang' => 'pl', 'limit' => 5])), 'Polish full pack should match a runtime-derived surface via its lemma');
    assert_same([], $searcher->search('nawigacja', ['lang' => 'pl']), 'navigation text should not be indexed');
    assert_same([], $searcher->search($italianPair['lemma'], ['lang' => 'pl']), 'script text should not be indexed even when it is a real lemma');

    $snippetRows = $searcher->search($polishPair['lemma'], [
        'lang' => 'pl',
        'limit' => 1,
        'include_metadata' => true,
        'include_snippets' => true,
        'highlight' => true,
        'snippet_length' => 100,
    ]);
    $snippet = (string) ($snippetRows[0]['snippet'] ?? '');
    assert_contains('<mark>', $snippet, 'pack-backed Polish snippet should highlight the visible inflected surface');
    assert_contains($polishPair['surface'], WP_FTS_Html_Text_Stream::visible_text($snippet), 'pack-backed Polish snippet should retain the visible surface text');
    assert_true(!str_contains($snippet, '<script') && !str_contains($snippet, '<template') && !str_contains($snippet, 'nawigacja'), 'snippet should not leak skipped HTML regions');

    $italianHtml = '<article lang="it"><p>Prima ' . qrs_inline_split_word($italianPair['surface']) . ' dopo.</p><script>' . $italianPair['lemma'] . '</script></article>';
    $italianSnippet = $searcher->snippet_for_text($italianHtml, $italianPair['lemma'], [
        'lang' => 'it',
        'highlight' => true,
        'snippet_length' => 80,
    ]);
    assert_contains('<mark>', $italianSnippet, 'direct snippet helper should highlight a split-tag lemma-pack match');
    assert_contains($italianPair['surface'], WP_FTS_Html_Text_Stream::visible_text($italianSnippet), 'direct snippet helper should display the matched Italian surface');
    assert_true(!str_contains($italianSnippet, '<script'), 'direct snippet helper should not return hidden script markup');
});

test_case('quality rigorous FTS lexical punctuation accents entities and script runs use product search semantics', function (): void {
    $fixture = qrs_fixture(false);
    $indexer = $fixture['indexer'];
    $searcher = $fixture['searcher'];
    $englishHtml = '<article lang="en"><p>Editor&rsquo;s co-operate re-entry caf&eacute; AT&amp;T marker.</p></article>';

    $indexer->index_document(4200, $englishHtml, [
        'lang' => 'en',
        'metadata' => qrs_metadata(4200, 'post', 'publish', '2026-05-02 00:00:00', 'Lexical English', $englishHtml),
    ]);
    $indexer->index_document(4201, '<article lang="ja"><p>検索できます</p></article>', ['lang' => 'ja']);
    $indexer->index_document(4202, '<article lang="ko"><p>검색합니다</p></article>', ['lang' => 'ko']);
    $indexer->index_document(4203, '<article lang="fa"><p>گزارش فارسی جستجو فهرست</p></article>', ['lang' => 'fa']);

    assert_same([4200], qrs_ids($searcher->search('editors', ['lang' => 'en', 'mode' => 'AND'])), 'curly apostrophe possessive should remain searchable through English stemming');
    assert_same([4200], qrs_ids($searcher->search('co operate', ['lang' => 'en', 'mode' => 'AND'])), 'hyphenated words should search through their visible component tokens');
    assert_same([4200], qrs_ids($searcher->search('re entry', ['lang' => 'en', 'mode' => 'AND'])), 'hyphenated stemmed components should keep document and query parity');
    assert_same([4200], qrs_ids($searcher->search('cafe', ['lang' => 'en', 'mode' => 'AND'])), 'entity-decoded accented visible text should match folded ASCII queries');
    assert_same([], $searcher->search('amp', ['lang' => 'en', 'mode' => 'AND']), 'HTML entity syntax should not be indexed as visible text');
    assert_same([], $searcher->search('cooperate', ['lang' => 'en', 'mode' => 'AND']), 'hyphenation should not invent an unobserved joined token');
    assert_same([], $searcher->search('reentry', ['lang' => 'en', 'mode' => 'AND']), 'hyphenation should not invent an unobserved joined stem');
    assert_same([4201], qrs_ids($searcher->search('検索', ['lang' => 'ja', 'mode' => 'AND'])), 'Japanese CJK n-grams should retrieve visible text');
    assert_same([4202], qrs_ids($searcher->search('검색', ['lang' => 'ko', 'mode' => 'AND'])), 'Hangul n-grams should retrieve visible text');
    assert_same([4203], qrs_ids($searcher->search('جستجو', ['lang' => 'fa', 'mode' => 'AND'])), 'Arabic-script Persian text should remain searchable without a pack');
});

test_case('quality rigorous FTS indexing lifecycle removes stale terms, language partitions, metadata, and tombstones', function (): void {
    $fixture = qrs_fixture(false);
    $storage = $fixture['storage'];
    $indexer = $fixture['indexer'];
    $searcher = $fixture['searcher'];
    $initialHtml = '<article lang="en"><h1>lifecyclealpha</h1><p>post metadata visible</p></article>';
    $changedHtml = '<article lang="pl"><h1>szklarnia lifecyclebeta</h1><p>page metadata visible</p></article>';

    assert_true($indexer->index_document(5100, $initialHtml, [
        'lang' => 'en',
        'metadata' => qrs_metadata(5100, 'post', 'publish', '2026-05-05 00:00:00', 'Initial lifecycle', $initialHtml),
    ]), 'new lifecycle document should index');
    assert_same([5100], qrs_ids($searcher->search('lifecyclealpha', ['lang' => 'en'])), 'newly indexed English term should be searchable');

    assert_true($indexer->index_document(5100, $changedHtml, [
        'lang' => 'pl',
        'metadata' => qrs_metadata(5100, 'page', 'draft', '2026-05-06 00:00:00', 'Changed lifecycle', $changedHtml, ['language' => 'pl']),
    ]), 'reindexing with changed language and metadata should rewrite postings');
    assert_same([], $searcher->search('lifecyclealpha', ['lang' => 'en']), 'reindex should remove stale English postings');
    assert_same([5100], qrs_ids($searcher->search('lifecyclebeta', ['lang' => 'pl'])), 'reindex should add new Polish postings');
    assert_same([], $searcher->search('lifecyclebeta', ['lang' => 'en']), 'new term should not leak into old language partition');
    assert_same([5100], qrs_ids($searcher->search('lifecyclebeta', ['lang' => 'pl', 'post_type' => ['page'], 'post_status' => ['draft']])), 'reindex should update page draft metadata filters');
    assert_same([], $searcher->search('lifecyclebeta', ['lang' => 'pl', 'post_type' => ['post'], 'post_status' => ['publish']]), 'old post publish metadata should be gone');

    assert_true($indexer->delete_document(5100), 'delete should tombstone the active lifecycle document');
    assert_same([], $searcher->search('lifecyclebeta', ['lang' => 'pl']), 'deleted document should be excluded before optimize');
    $indexer->optimize();
    assert_same([], $searcher->search('lifecyclebeta', ['lang' => 'pl']), 'deleted document should remain excluded after optimize');
    assert_same([], WP_FTS_StorageCompat::get_doc_metadata($storage, [5100]), 'delete should clear product-facing document metadata');
});

/**
 * @return array{count:int,en_gold_ids:int[],medium_anchor_ids:int[]}
 */
function qrs_build_medium_corpus(WP_FTS_Indexer $indexer): array
{
    $languages = ['en', 'pl', 'de', 'nl', 'tr', 'ru', 'it', 'uk', 'fa', 'te', 'ja', 'ko'];
    $languageWords = [
        'en' => 'harbor',
        'pl' => 'szklarnia',
        'de' => 'straße',
        'nl' => 'zoeken',
        'tr' => 'istanbul',
        'ru' => 'поиск',
        'it' => 'ricerca',
        'uk' => 'пошук',
        'fa' => 'جستجو',
        'te' => 'శోధన',
        'ja' => '検索',
        'ko' => '검색',
    ];
    $enGold = [];
    $mediumAnchorIds = [];

    for ($i = 0; $i < 420; $i++) {
        $docId = 7000 + $i;
        $lang = $languages[$i % count($languages)];
        $type = $i % 6 === 0 ? 'page' : 'post';
        $status = $i % 11 === 0 ? 'draft' : 'publish';
        $date = sprintf('2026-06-%02d 00:00:00', 1 + ($i % 24));
        $topic = 'topic' . ($i % 17);
        $padding = str_repeat(' filler' . ($i % 9), 1 + ($i % 6));
        $visibleWord = $languageWords[$lang];
        $body = "{$visibleWord} {$topic}{$padding}";

        if ($lang === 'en' && $i % 36 === 0) {
            $body .= ' mediumanchor rarecontext mediumanchor';
            $enGold[] = $docId;
            $mediumAnchorIds[] = $docId;
        } elseif ($lang === 'en' && $i % 24 === 0) {
            $body .= ' mediumanchor';
            $mediumAnchorIds[] = $docId;
        } elseif ($lang !== 'en' && $i % 20 === 0) {
            $body .= ' mediumanchor rarecontext';
        }

        $html = '<article lang="' . $lang . '"><h1>' . ($i % 36 === 0 ? 'mediumanchor ' : '') . $topic . '</h1><p>' . $body . '</p><script>hiddenmediumsecret rarecontext</script><style>.hidden{content:"mediumanchor"}</style></article>';
        $indexer->index_document($docId, $html, [
            'lang' => $lang,
            'metadata' => qrs_metadata($docId, $type, $status, $date, "Medium {$docId}", $html, ['language' => $lang]),
        ]);
    }

    sort($enGold, SORT_NUMERIC);
    sort($mediumAnchorIds, SORT_NUMERIC);

    return [
        'count' => 420,
        'en_gold_ids' => $enGold,
        'medium_anchor_ids' => $mediumAnchorIds,
    ];
}

test_case('quality rigorous FTS medium corpus verifies ranking, partition isolation, pagination, fast top-k, and hidden text', function (): void {
    $fixture = qrs_fixture(false);
    $indexer = $fixture['indexer'];
    $searcher = $fixture['searcher'];
    $model = qrs_build_medium_corpus($indexer);

    assert_same(420, $model['count'], 'medium corpus should contain the expected deterministic document count');
    $andIds = qrs_ids($searcher->search('mediumanchor rarecontext', ['lang' => 'en', 'mode' => 'AND', 'limit' => 20]));
    assert_same($model['en_gold_ids'], $andIds, 'English medium AND query should recall only English gold documents despite multilingual baits');
    assert_true(($andIds[0] ?? 0) < ($andIds[1] ?? PHP_INT_MAX), 'medium ranking tie-break should remain deterministic by ascending doc id when scores tie');

    $wrongPartition = qrs_ids($searcher->search('mediumanchor rarecontext', ['lang' => 'fa', 'mode' => 'AND', 'limit' => 50]));
    assert_true($wrongPartition !== [], 'medium corpus should include wrong-language bait documents');
    foreach ($wrongPartition as $docId) {
        assert_true(!in_array($docId, $model['en_gold_ids'], true), 'wrong-language medium search should not return English gold documents');
    }

    $exact = $searcher->search('mediumanchor', ['lang' => 'en', 'limit' => 10, 'exact' => true]);
    $fast = $searcher->search('mediumanchor', ['lang' => 'en', 'limit' => 10, 'fast_top_k' => true, 'candidate_cap' => 200]);
    assert_same(qrs_ids($exact), qrs_ids($fast), 'explicit fast top-k should match exact ordering when the cap covers all English candidates');

    $firstPage = $searcher->search('mediumanchor', ['lang' => 'en', 'limit' => 3, 'include_total' => true]);
    $secondPage = $searcher->search('mediumanchor', ['lang' => 'en', 'limit' => 3, 'offset' => 3, 'include_total' => true]);
    assert_same(count($model['medium_anchor_ids']), $firstPage['total'], 'medium include_total should count every English anchor match');
    assert_same(3, count($firstPage['results']), 'first medium page should honor limit');
    assert_true(array_intersect(qrs_ids($firstPage['results']), qrs_ids($secondPage['results'])) === [], 'medium pagination pages should not overlap');

    assert_same([], $searcher->search('hiddenmediumsecret', ['lang' => 'en', 'limit' => 10]), 'hidden medium script/style terms should not be indexed');
    $snippetRows = $searcher->search('mediumanchor', [
        'lang' => 'en',
        'limit' => 1,
        'include_metadata' => true,
        'include_snippets' => true,
        'highlight' => true,
        'snippet_length' => 80,
    ]);
    $snippet = (string) ($snippetRows[0]['snippet'] ?? '');
    assert_contains('<mark>', $snippet, 'medium snippets should highlight the matched anchor');
    assert_true(!str_contains($snippet, 'hiddenmediumsecret') && !str_contains($snippet, '<script'), 'medium snippets should not expose hidden distractors');
});

test_case('quality rigorous FTS plugin boundary sanitizes REST and admin search request shaping', function (): void {
    $missing = WP_FTS_Plugin::rest_search(['q' => '', 'query' => " \t "]);
    assert_same('wp_fts_missing_query', qrs_rest_error_code($missing), 'REST search should reject empty q/query aliases before storage access');
    assert_same(400, qrs_rest_error_status($missing), 'REST missing query should return a 400 status');

    $invalidMode = WP_FTS_Plugin::rest_search(['q' => '<b>atlas</b>', 'mode' => 'XOR']);
    assert_same('wp_fts_invalid_mode', qrs_rest_error_code($invalidMode), 'REST search should reject invalid boolean modes');

    $settings = WP_FTS_Plugin::sanitize_settings([
        'index_post_types' => ['post', 'page', '<script>', 'attachment'],
        'match_mode' => 'AND',
        'result_limit' => '999',
        'snippet_length' => '9999',
        'highlight' => '0',
        'language_fallback' => 'off',
        'replace_search_scope' => 'admin',
    ]);
    assert_same(['page', 'post'], $settings['index_post_types'], 'settings sanitizer should keep only searchable post/page types without WordPress APIs');
    assert_same('AND', $settings['match_mode'], 'settings sanitizer should preserve valid AND mode');
    assert_same(WP_FTS_Plugin::MAX_SEARCH_LIMIT, $settings['result_limit'], 'settings sanitizer should clamp result limit');
    assert_same(false, $settings['highlight'], 'settings sanitizer should parse false-like highlight values');
    assert_same(false, $settings['language_fallback'], 'settings sanitizer should parse false-like language fallback values');
    assert_same(false, $settings['replace_frontend_search'], 'settings sanitizer should honor admin-only replacement scope');
    assert_same(true, $settings['replace_admin_post_search'], 'settings sanitizer should enable admin replacement for admin-only scope');

    $oldGet = $_GET;
    $_GET = [
        'wp_fts_sandbox_mode' => 'xor',
        'wp_fts_sandbox_limit' => '999',
        'wp_fts_sandbox_snippet_length' => '1',
        'wp_fts_sandbox_highlight' => '0',
        'wp_fts_sandbox_language_fallback' => '1',
        'wp_fts_sandbox_post_type' => ['post', 'evil<script>', 'page'],
        'wp_fts_sandbox_post_status' => ['publish', 'trash', 'draft'],
        'wp_fts_sandbox_date_after' => '2026-06-01',
        'wp_fts_sandbox_date_before' => 'not-a-date',
    ];
    try {
        $method = new ReflectionMethod(WP_FTS_Plugin::class, 'sandbox_search_controls');
        $method->setAccessible(true);
        $controls = $method->invoke(null, true);
    } finally {
        $_GET = $oldGet;
    }

    assert_same('OR', $controls['mode'], 'admin sandbox controls should fall back from invalid mode to settings default');
    assert_same(WP_FTS_Plugin::MAX_SEARCH_LIMIT, $controls['limit'], 'admin sandbox controls should clamp result limit');
    assert_same(40, $controls['snippet_length'], 'admin sandbox controls should clamp snippet length to the public minimum');
    assert_same(false, $controls['highlight'], 'admin sandbox controls should parse highlight checkbox value');
    assert_same(true, $controls['language_fallback'], 'admin sandbox controls should parse language fallback radio value');
    assert_same(['page', 'post'], $controls['post_types'], 'admin sandbox controls should keep only allowed post types');
    assert_same(['draft', 'publish'], $controls['post_statuses'], 'admin sandbox controls should keep only allowed statuses');
    assert_same('2026-06-01', $controls['date_after'], 'admin sandbox controls should preserve valid date filters');
    assert_same('', $controls['date_before'], 'admin sandbox controls should drop invalid date filters');
});

if ($wp_fts_rigorous_direct) {
    $failures = 0;
    $pending = 0;
    $start = microtime(true);
    foreach ($GLOBALS['wp_fts_rigorous_tests'] as $test) {
        try {
            ($test['fn'])();
            fwrite(STDOUT, "[PASS] {$test['name']}\n");
        } catch (WP_FTS_Rigorous_TestPending $e) {
            $pending++;
            fwrite(STDOUT, "[PEND] {$test['name']}\n{$e->getMessage()}\n");
        } catch (Throwable $e) {
            $failures++;
            fwrite(STDERR, "[FAIL] {$test['name']}\n{$e->getMessage()}\n{$e->getTraceAsString()}\n");
        }
    }

    $duration = number_format(microtime(true) - $start, 3);
    $count = count($GLOBALS['wp_fts_rigorous_tests']);
    $passed = $count - $failures - $pending;
    $checks = (int) $GLOBALS['wp_fts_rigorous_check_count'];
    $summary = "{$passed}/{$count} rigorous FTS search behavior tests passed; failures={$failures}; pending={$pending}; checks/scenarios={$checks}; duration={$duration}s\n";
    if ($failures > 0) {
        fwrite(STDERR, $summary);
        exit(1);
    }

    fwrite(STDOUT, $summary);
}
