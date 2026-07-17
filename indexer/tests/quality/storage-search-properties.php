<?php
declare(strict_types=1);

if (!function_exists('test_case')) {
    define('WP_FTS_QSSP_DIRECT_RUN', true);
    require_once __DIR__ . '/../../src/bootstrap.php';

    final class WP_FTS_TestFailure extends RuntimeException
    {
    }

    /**
     * @var array<int,array{name:string,fn:callable}>
     */
    $tests = [];
    $wp_fts_check_count = 0;

    function test_case(string $name, callable $fn): void
    {
        global $tests;
        $tests[] = ['name' => $name, 'fn' => $fn];
    }

    function record_check(?string $label = null, int $count = 1): void
    {
        if ($count < 1) {
            throw new WP_FTS_TestFailure('record_check() count must be at least 1.');
        }

        global $wp_fts_check_count;
        $wp_fts_check_count += $count;
    }

    function assert_true(bool $condition, string $message): void
    {
        record_check($message);
        if (!$condition) {
            throw new WP_FTS_TestFailure($message);
        }
    }

    function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        record_check($message);
        if ($expected !== $actual) {
            throw new WP_FTS_TestFailure($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
        }
    }

    function assert_float_near(float $expected, float $actual, string $message, float $epsilon = 1e-6): void
    {
        record_check($message);
        $scale = max(1.0, abs($expected), abs($actual));
        if (abs($expected - $actual) / $scale > $epsilon) {
            throw new WP_FTS_TestFailure($message . "\nExpected: {$expected}\nActual: {$actual}");
        }
    }

    function assert_contains(string $needle, string $haystack, string $message): void
    {
        record_check($message);
        if (!str_contains($haystack, $needle)) {
            throw new WP_FTS_TestFailure($message . "\nMissing: " . var_export($needle, true) . "\nIn: " . $haystack);
        }
    }

    function test_bm25_score(int $tf, int $docLen, int $docCount, int $docFreq, float $avgDocLen, float $k1 = 1.2, float $b = 0.75): float
    {
        $idf = log(1.0 + (($docCount - $docFreq + 0.5) / ($docFreq + 0.5)));
        $normalizer = $tf + $k1 * (1.0 - $b + $b * ($docLen / max(1.0, $avgDocLen)));

        return $idf * (($tf * ($k1 + 1.0)) / $normalizer);
    }

    function assert_search_results_equal(array $expected, array $actual, string $message): void
    {
        assert_same(count($expected), count($actual), $message . ' result count');
        foreach ($expected as $i => $expectedRow) {
            assert_same($expectedRow['doc_id'], $actual[$i]['doc_id'], $message . " doc_id at {$i}");
            assert_float_near($expectedRow['score'], $actual[$i]['score'], $message . " score at {$i}");
        }
    }

    function temp_index_path(string $suffix): string
    {
        return sys_get_temp_dir() . '/wp_fts_' . getmypid() . '_' . $suffix . '_' . bin2hex(random_bytes(4)) . '.json';
    }

    /**
     * @return array<string,callable():WP_FTS_Storage>
     */
    function storage_factories(string $suffix): array
    {
        return [
            'memory' => static fn(): WP_FTS_Storage => new WP_FTS_Storage_InMemory(),
            'file' => static fn(): WP_FTS_Storage => new WP_FTS_Storage_File(temp_index_path($suffix)),
        ];
    }

    function cleanup_storage(WP_FTS_Storage $storage): void
    {
        if (!$storage instanceof WP_FTS_Storage_File) {
            return;
        }

        $ref = new ReflectionClass($storage);
        $prop = $ref->getProperty('path');
        $prop->setAccessible(true);
        $path = (string) $prop->getValue($storage);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @return array{terms:array<string,array{df:int,postings:array<int,int>}>,docs:array<int,array<string,mixed>>,doc_meta:array<int,array<string,mixed>>,meta:array<string,array{doc_count:int,len_sum:int}>}
     */
    function storage_snapshot(WP_FTS_Storage $storage): array
    {
        $terms = [];
        foreach ($storage->all_terms() as $term) {
            $row = $storage->get_terms([$term])[$term];
            $terms[$term] = [
                'df' => $row['df'],
                'postings' => WP_FTS_PostingsCodec::decode($row['postings']),
            ];
        }
        ksort($terms, SORT_STRING);

        $docs = [];
        foreach ($storage->all_doc_ids(true) as $docId) {
            $docs[$docId] = $storage->get_doc($docId);
        }
        ksort($docs, SORT_NUMERIC);

        return [
            'terms' => $terms,
            'docs' => $docs,
            'doc_meta' => WP_FTS_StorageCompat::get_doc_metadata($storage, array_keys($docs)),
            'meta' => storage_meta_snapshot($storage, $docs),
        ];
    }

    /**
     * @param array<int,array<string,mixed>>|null $docs
     * @return array<string,array{doc_count:int,len_sum:int}>
     */
    function storage_meta_snapshot(WP_FTS_Storage $storage, ?array $docs = null): array
    {
        $docs ??= [];
        if ($docs === []) {
            foreach ($storage->all_doc_ids(true) as $docId) {
                $doc = $storage->get_doc($docId);
                if ($doc !== null) {
                    $docs[$docId] = $doc;
                }
            }
        }

        $langs = [];
        foreach ($docs as $doc) {
            foreach (($doc['lang_lengths'] ?? []) as $lang => $_) {
                $langs[(string) $lang] = true;
            }
        }
        ksort($langs, SORT_STRING);

        $meta = ['*' => $storage->get_meta()];
        foreach (array_keys($langs) as $lang) {
            $meta[$lang] = $storage->get_meta($lang);
        }
        ksort($meta, SORT_STRING);

        return $meta;
    }

    function wp_fts_qssp_run_registered_tests_and_exit(): void
    {
        global $tests, $wp_fts_check_count;

        $failures = 0;
        $start = microtime(true);
        foreach ($tests as $test) {
            try {
                ($test['fn'])();
                fwrite(STDOUT, "[PASS] {$test['name']}\n");
            } catch (Throwable $e) {
                $failures++;
                fwrite(STDERR, "[FAIL] {$test['name']}\n{$e->getMessage()}\n{$e->getTraceAsString()}\n");
            }
        }

        $duration = number_format(microtime(true) - $start, 3);
        $count = count($tests);
        $passed = $count - $failures;
        $summary = "{$passed}/{$count} named tests passed; failures={$failures}; checks/scenarios={$wp_fts_check_count}; duration={$duration}s\n";
        if ($failures > 0) {
            fwrite(STDERR, $summary);
            exit(1);
        }

        fwrite(STDOUT, $summary);
        exit(0);
    }
}

/**
 * @return string[]
 */
function qssp_languages(): array
{
    return ['en', 'pl', 'de', 'fr', 'tr', 'es', 'nl', 'zh-Hans'];
}

/**
 * @return array<string,string[]>
 */
function qssp_vocab(): array
{
    return [
        'en' => ['shared', 'alpha', 'bridge', 'river', 'castle', 'color', 'harbor'],
        'pl' => ['shared', 'zamek', 'wroclaw', 'lodz', 'rzeka', 'kolor', 'most'],
        'de' => ['shared', 'strasse', 'fluss', 'berg', 'farbe', 'hafen', 'bruecke'],
        'fr' => ['shared', 'ecole', 'cafe', 'riviere', 'pont', 'chateau', 'couleur'],
        'tr' => ['shared', 'istanbul', 'isparta', 'isik', 'renk', 'kopru', 'nehir'],
        'es' => ['shared', 'castillo', 'rio', 'puente', 'color', 'puerto', 'escuela'],
        'nl' => ['shared', 'kasteel', 'rivier', 'brug', 'kleur', 'haven', 'school'],
        'zh-Hans' => ['shared', 'hanzi', 'sousuo', 'yinjan', 'chengshi', 'qiao', 'yanse'],
    ];
}

/**
 * @return string[]
 */
function qssp_all_model_languages(array $docs): array
{
    $langs = [];
    foreach ($docs as $doc) {
        foreach ($doc['lang_lengths'] as $lang => $_) {
            $langs[$lang] = true;
        }
    }

    $result = array_keys($langs);
    sort($result, SORT_STRING);

    return $result;
}

/**
 * @return array{doc_count:int,len_sum:int}
 */
function qssp_expected_meta(array $docs, ?string $lang = null): array
{
    $docCount = 0;
    $lenSum = 0;
    foreach ($docs as $doc) {
        if ($doc['deleted']) {
            continue;
        }

        if ($lang === null) {
            $docCount++;
            $lenSum += $doc['doc_len'];
            continue;
        }

        $length = $doc['lang_lengths'][$lang] ?? 0;
        if ($length > 0) {
            $docCount++;
            $lenSum += $length;
        }
    }

    return ['doc_count' => $docCount, 'len_sum' => $lenSum];
}

/**
 * @return int[]
 */
function qssp_expected_doc_ids(array $docs, bool $includeDeleted): array
{
    $ids = [];
    foreach ($docs as $docId => $doc) {
        if ($includeDeleted || !$doc['deleted']) {
            $ids[] = (int) $docId;
        }
    }
    sort($ids, SORT_NUMERIC);

    return $ids;
}

/**
 * @return array<int,int>
 */
function qssp_expected_doc_lengths(array $docs, array $docIds, ?string $lang): array
{
    $lengths = [];
    foreach (array_unique(array_map('intval', $docIds)) as $docId) {
        if (!isset($docs[$docId]) || $docs[$docId]['deleted']) {
            continue;
        }

        $length = $lang === null
            ? $docs[$docId]['doc_len']
            : ($docs[$docId]['lang_lengths'][$lang] ?? null);
        if ($length !== null) {
            $lengths[$docId] = $length;
        }
    }
    ksort($lengths, SORT_NUMERIC);

    return $lengths;
}

/**
 * @param array{docs:array<int,array<string,mixed>>,terms:array<string,array<int,int>>} $model
 */
function qssp_assert_storage_matches_model(WP_FTS_Storage $storage, array $model, string $label): void
{
    $docs = $model['docs'];
    assert_same(qssp_expected_doc_ids($docs, false), $storage->all_doc_ids(), "{$label} active doc ids");
    assert_same(qssp_expected_doc_ids($docs, true), $storage->all_doc_ids(true), "{$label} all doc ids");
    assert_same(qssp_expected_meta($docs), $storage->get_meta(), "{$label} aggregate meta");

    $allDocIds = array_keys($docs);
    foreach (qssp_all_model_languages($docs) as $lang) {
        assert_same(qssp_expected_meta($docs, $lang), $storage->get_meta($lang), "{$label} {$lang} meta");
        assert_same(qssp_expected_doc_lengths($docs, $allDocIds, $lang), $storage->get_doc_lengths($allDocIds, $lang), "{$label} {$lang} doc lengths");
    }

    foreach ($docs as $docId => $expectedDoc) {
        assert_same($expectedDoc, $storage->get_doc((int) $docId), "{$label} doc {$docId} metadata");
    }
    assert_same(qssp_expected_doc_lengths($docs, $allDocIds, null), $storage->get_doc_lengths($allDocIds), "{$label} aggregate doc lengths");

    $expectedTerms = array_keys($model['terms']);
    sort($expectedTerms, SORT_STRING);
    assert_same($expectedTerms, $storage->all_terms(), "{$label} all terms");
    foreach ($model['terms'] as $term => $expectedPostings) {
        $row = $storage->get_terms([$term])[$term] ?? null;
        assert_true($row !== null, "{$label} term {$term} should exist");
        assert_same(count($expectedPostings), $row['df'], "{$label} term {$term} df");
        assert_same($expectedPostings, WP_FTS_PostingsCodec::decode($row['postings']), "{$label} term {$term} postings");
    }
}

/**
 * @param array{docs:array<int,array<string,mixed>>,terms:array<string,array<int,int>>} $model
 */
function qssp_delete_model_doc(array &$model, int $docId): void
{
    if (isset($model['docs'][$docId])) {
        $model['docs'][$docId]['deleted'] = true;
    }
}

/**
 * @param array{docs:array<int,array<string,mixed>>,terms:array<string,array<int,int>>} $model
 */
function qssp_optimize_model(array &$model): void
{
    $deleted = [];
    foreach ($model['docs'] as $docId => $doc) {
        if ($doc['deleted']) {
            $deleted[(int) $docId] = true;
        }
    }

    foreach ($model['terms'] as $term => $postings) {
        foreach ($deleted as $docId => $_) {
            unset($postings[$docId]);
        }
        if ($postings === []) {
            unset($model['terms'][$term]);
            continue;
        }
        ksort($postings, SORT_NUMERIC);
        $model['terms'][$term] = $postings;
    }

    foreach ($deleted as $docId => $_) {
        unset($model['docs'][$docId]);
    }
}

function qssp_cleanup_storage_list(WP_FTS_Storage ...$storages): void
{
    foreach ($storages as $storage) {
        cleanup_storage($storage);
    }
}

/**
 * @param array<int,array{html:string,lang:string}> $documents
 */
function qssp_index_documents(WP_FTS_Storage $storage, WP_FTS_Analyzer $analyzer, array $documents): WP_FTS_Indexer
{
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    foreach ($documents as $docId => $document) {
        $indexer->index_document((int) $docId, $document['html'], ['lang' => $document['lang']]);
    }

    return $indexer;
}

/**
 * @return array<string,int>
 */
function qssp_term_frequencies_for_lang(WP_FTS_Analyzer $analyzer, string $html, string $documentLang, string $queryLang): array
{
    $queryLang = WP_FTS_TermNamespace::canonicalize_lang($queryLang);
    $weights = [];
    foreach ($analyzer->analyze_content($html, ['lang' => $documentLang]) as $occurrence) {
        $term = (string) $occurrence['term'];
        $lang = WP_FTS_TermNamespace::canonicalize_lang((string) ($occurrence['lang'] ?? $documentLang), $documentLang);
        $split = WP_FTS_TermNamespace::split_term($term);
        if ($split !== null) {
            $term = $split['term'];
            $lang = $split['lang'];
        }
        if ($lang !== $queryLang || $term === '') {
            continue;
        }
        $weights[$term] = ($weights[$term] ?? 0.0) + (float) ($occurrence['weight'] ?? 1.0);
    }

    $frequencies = [];
    foreach ($weights as $term => $weight) {
        $frequencies[$term] = max(1, (int) round($weight));
    }
    ksort($frequencies, SORT_STRING);

    return $frequencies;
}

/**
 * @return string[]
 */
function qssp_query_terms_for_lang(WP_FTS_Analyzer $analyzer, string $query, string $lang): array
{
    $queryLang = WP_FTS_TermNamespace::canonicalize_lang($lang);
    $terms = [];
    foreach ($analyzer->analyze_query_occurrences($query, ['lang' => $queryLang]) as $occurrence) {
        $term = (string) $occurrence['term'];
        $termLang = WP_FTS_TermNamespace::canonicalize_lang((string) ($occurrence['lang'] ?? $queryLang), $queryLang);
        $split = WP_FTS_TermNamespace::split_term($term);
        if ($split !== null) {
            $term = $split['term'];
            $termLang = $split['lang'];
        }
        if ($termLang === $queryLang && $term !== '') {
            $terms[$term] = true;
        }
    }

    $result = array_keys($terms);
    sort($result, SORT_STRING);

    return $result;
}

/**
 * @param array<int,array{html:string,lang:string}> $documents
 * @return array<int,array{doc_id:int,score:float}>
 */
function qssp_language_oracle_search(array $documents, WP_FTS_Analyzer $analyzer, string $query, string $lang, string $mode, int $limit): array
{
    $queryTerms = qssp_query_terms_for_lang($analyzer, $query, $lang);
    if ($queryTerms === []) {
        return [];
    }

    $docFrequencies = [];
    $docLengths = [];
    $dfs = array_fill_keys($queryTerms, 0);
    foreach ($documents as $docId => $document) {
        $frequencies = qssp_term_frequencies_for_lang($analyzer, $document['html'], $document['lang'], $lang);
        if ($frequencies === []) {
            continue;
        }
        $docFrequencies[(int) $docId] = $frequencies;
        $docLengths[(int) $docId] = array_sum($frequencies);
        foreach ($queryTerms as $term) {
            if (isset($frequencies[$term])) {
                $dfs[$term]++;
            }
        }
    }

    $docCount = count($docLengths);
    if ($docCount === 0) {
        return [];
    }

    $avgDocLen = array_sum($docLengths) / $docCount;
    $mode = strtoupper($mode);
    $results = [];
    foreach ($docFrequencies as $docId => $frequencies) {
        $matched = array_values(array_intersect($queryTerms, array_keys($frequencies)));
        if ($matched === [] || ($mode === 'AND' && count($matched) < count($queryTerms))) {
            continue;
        }

        $score = 0.0;
        foreach ($matched as $term) {
            $score += test_bm25_score($frequencies[$term], $docLengths[$docId], $docCount, $dfs[$term], $avgDocLen);
        }
        if ($score > 0.0) {
            $results[] = ['doc_id' => (int) $docId, 'score' => $score];
        }
    }

    usort($results, static function (array $a, array $b): int {
        $scoreOrder = $b['score'] <=> $a['score'];
        return $scoreOrder !== 0 ? $scoreOrder : ($a['doc_id'] <=> $b['doc_id']);
    });

    return array_slice($results, 0, max(1, $limit));
}

function qssp_generated_html(int $seed, int $docId, int $revision, string $primaryLang): string
{
    $languages = qssp_languages();
    $vocab = qssp_vocab();
    mt_srand($seed + $docId * 1009 + $revision * 9173);
    $parts = ["<article><p>revision{$revision}</p>"];
    for ($i = 0; $i < 14; $i++) {
        $lang = mt_rand(0, 4) === 0 ? $languages[mt_rand(0, count($languages) - 1)] : $primaryLang;
        $words = $vocab[$lang];
        $word = $words[mt_rand(0, count($words) - 1)];
        $tagChoice = mt_rand(0, 7);
        $text = $lang === $primaryLang ? $word : "<span lang=\"{$lang}\">{$word}</span>";
        if ($tagChoice === 0) {
            $parts[] = "<h1>{$text}</h1>";
        } elseif ($tagChoice === 1) {
            $parts[] = "<strong>{$text}</strong>";
        } elseif ($tagChoice === 2) {
            $parts[] = "<nav>{$text}</nav>";
        } elseif ($tagChoice === 3) {
            $parts[] = "<script>{$word}</script>";
        } else {
            $parts[] = "<p>{$text}</p>";
        }
    }
    $parts[] = '</article>';

    return implode('', $parts);
}

/**
 * @return array{html:string,lang:string}
 */
function qssp_generated_document(int $seed, int $docId, int $revision): array
{
    $languages = qssp_languages();
    mt_srand($seed + $docId * 97 + $revision * 193);
    $lang = $languages[mt_rand(0, count($languages) - 1)];

    return [
        'html' => qssp_generated_html($seed, $docId, $revision, $lang),
        'lang' => $lang,
    ];
}

test_case('quality storage backends stay in parity across language partitions, tombstones, and optimize', function (): void {
    $languages = qssp_languages();
    $memory = new WP_FTS_Storage_InMemory();
    $file = new WP_FTS_Storage_File(temp_index_path('qssp_partition_parity'));
    $storages = ['memory' => $memory, 'file' => $file];
    $model = ['docs' => [], 'terms' => []];
    $docId = 100;

    foreach ($languages as $i => $lang) {
        for ($variant = 0; $variant < 3; $variant++) {
            $docId++;
            $secondary = $languages[($i + $variant + 1) % count($languages)];
            $lengths = [$lang => $variant + 2];
            if ($variant !== 1) {
                $lengths[$secondary] = $i + 1;
            }
            ksort($lengths, SORT_STRING);
            $doc = [
                'primary_lang' => $lang,
                'lang_lengths' => $lengths,
                'doc_len' => array_sum($lengths),
                'content_hash' => "seed-045-{$lang}-{$variant}",
                'deleted' => false,
            ];
            foreach ($storages as $storage) {
                $storage->put_doc($docId, $lang, $lengths, $doc['content_hash']);
            }
            $model['docs'][$docId] = $doc;

            $shared = WP_FTS_TermNamespace::namespace_term($lang, 'shared');
            $unique = WP_FTS_TermNamespace::namespace_term($lang, "u{$variant}");
            $model['terms'][$shared][$docId] = $variant + 1;
            $model['terms'][$unique][$docId] = $variant + 2;
        }
    }

    foreach ($model['terms'] as $term => $postings) {
        ksort($postings, SORT_NUMERIC);
        $model['terms'][$term] = $postings;
        foreach ($storages as $storage) {
            $storage->put_term($term, count($postings), WP_FTS_PostingsCodec::encode($postings));
        }
    }
    ksort($model['docs'], SORT_NUMERIC);
    ksort($model['terms'], SORT_STRING);

    foreach ($storages as $name => $storage) {
        qssp_assert_storage_matches_model($storage, $model, "{$name} initial seed 04501");
    }
    assert_same(storage_snapshot($memory), storage_snapshot($file), 'memory and file snapshots should match after initial generated partition load');

    $deletedIds = [102, 105, 108, 111, 114, 117, 120, 123];
    foreach ($deletedIds as $deletedId) {
        foreach ($storages as $storage) {
            $storage->delete_doc($deletedId);
        }
        qssp_delete_model_doc($model, $deletedId);
    }

    foreach ($storages as $name => $storage) {
        qssp_assert_storage_matches_model($storage, $model, "{$name} tombstone seed 04501");
        $searcher = new WP_FTS_Searcher($storage, new WP_FTS_Analyzer());
        foreach ($languages as $lang) {
            $resultIds = array_column($searcher->search('shared', ['lang' => $lang, 'limit' => 20]), 'doc_id');
            foreach ($deletedIds as $deletedId) {
                assert_true(!in_array($deletedId, $resultIds, true), "{$name} {$lang} search should hide tombstoned doc {$deletedId}");
            }
        }
    }
    assert_same(storage_snapshot($memory), storage_snapshot($file), 'memory and file snapshots should match with tombstones retained');

    foreach ($storages as $storage) {
        $storage->optimize();
    }
    qssp_optimize_model($model);

    foreach ($storages as $name => $storage) {
        qssp_assert_storage_matches_model($storage, $model, "{$name} optimized seed 04501");
    }
    assert_same(storage_snapshot($memory), storage_snapshot($file), 'memory and file snapshots should match after optimize purges tombstones');

    qssp_cleanup_storage_list($memory, $file);
});

test_case('quality legacy storage calls and version-one file migration remain compatible', function (): void {
    foreach (storage_factories('qssp_legacy_calls') as $name => $factory) {
        $storage = $factory();
        $storage->put_doc(7, 5, 'legacy-hash-7');
        $storage->put_doc(8, 'pl', ['pl' => 4, 'en' => 2, 'de' => 0], 'aware-hash-8');
        $storage->add_meta(1, 5);
        $storage->add_meta('pl', 1, 4);

        assert_same([
            'primary_lang' => '',
            'lang_lengths' => ['' => 5],
            'doc_len' => 5,
            'content_hash' => 'legacy-hash-7',
            'deleted' => false,
        ], $storage->get_doc(7), "{$name} legacy put_doc signature");
        assert_same([7 => 5], $storage->get_doc_lengths([7, 8], ''), "{$name} legacy doc length lookup");
        assert_same([8 => 4], $storage->get_doc_lengths([7, 8], 'pl'), "{$name} language-aware doc length lookup");
        assert_same(['doc_count' => 1, 'len_sum' => 5], $storage->get_meta(''), "{$name} legacy meta partition");
        assert_same(['doc_count' => 1, 'len_sum' => 4], $storage->get_meta('pl'), "{$name} language-aware meta partition");
        cleanup_storage($storage);
    }

    $path = temp_index_path('qssp_v1_migration');
    $legacyTerm = WP_FTS_TermNamespace::namespace_term('en', 'legacy');
    file_put_contents($path, json_encode([
        'version' => 1,
        'terms' => [
            $legacyTerm => [
                'df' => 2,
                'postings' => base64_encode(WP_FTS_PostingsCodec::encode([41 => 2, 42 => 1])),
            ],
        ],
        'docs' => [
            '41' => ['doc_len' => 4, 'content_hash' => 'h41', 'deleted' => false],
            '42' => ['doc_len' => 3, 'content_hash' => 'h42', 'deleted' => true],
            '43' => ['doc_len' => 5, 'content_hash' => 'h43', 'deleted' => false],
        ],
        'meta' => ['doc_count' => 2, 'len_sum' => 9],
    ], JSON_THROW_ON_ERROR));

    $storage = new WP_FTS_Storage_File($path);
    assert_same([41 => 4, 43 => 5], $storage->get_doc_lengths([41, 42, 43], ''), 'v1 migrated active lengths should live in the unspecified partition');
    assert_same(['doc_count' => 2, 'len_sum' => 9], $storage->get_meta(''), 'v1 migrated meta should be derived from active docs');
    assert_same([41 => 2, 42 => 1], WP_FTS_PostingsCodec::decode($storage->get_terms([$legacyTerm])[$legacyTerm]['postings']), 'v1 postings should decode before optimize');

    $storage->put_doc(44, 'pl', ['pl' => 2, 'en' => 1], 'h44');
    $storage->put_term(WP_FTS_TermNamespace::namespace_term('pl', 'legacy'), 1, WP_FTS_PostingsCodec::encode([44 => 3]));
    $storage->flush();
    $reloaded = new WP_FTS_Storage_File($path);
    assert_same(storage_snapshot($storage), storage_snapshot($reloaded), 'revisioned state should persist exactly after migration and new language records');

    $reloaded->delete_doc(41);
    $reloaded->optimize();
    assert_same([], $reloaded->get_terms([$legacyTerm]), 'optimize after reload should purge legacy postings that only referenced tombstones');
    assert_same([43, 44], $reloaded->all_doc_ids(true), 'optimize after reload should purge v1 tombstone rows');
    assert_same(['doc_count' => 1, 'len_sum' => 5], $reloaded->get_meta(''), 'optimized legacy partition should retain only active legacy docs');

    cleanup_storage($storage);
});

test_case('quality reindex deltas update language, term distribution, hashes, and delete-then-add paths', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    foreach (storage_factories('qssp_reindex') as $name => $factory) {
        $storage = $factory();
        $indexer = new WP_FTS_Indexer($storage, $analyzer);
        $searcher = new WP_FTS_Searcher($storage, $analyzer);

        assert_true($indexer->index_document(77, '<p>shared alpha alpha</p>', ['lang' => 'en']), "{$name} first index should change");
        $initial = storage_snapshot($storage);
        assert_true(!$indexer->index_document(77, '<p>shared alpha alpha</p>', ['lang' => 'en']), "{$name} same hash should skip");
        assert_same($initial, storage_snapshot($storage), "{$name} same hash skip should not mutate storage");
        assert_same([77], array_column($searcher->search('shared', ['lang' => 'en']), 'doc_id'), "{$name} initial English search");

        assert_true($indexer->index_document(77, '<p>shared alpha alpha</p>', ['lang' => 'pl']), "{$name} language-only reindex should change content hash");
        assert_same([], $searcher->search('shared', ['lang' => 'en']), "{$name} old language postings should be removed");
        assert_same([77], array_column($searcher->search('shared', ['lang' => 'pl']), 'doc_id'), "{$name} new language postings should be searchable");
        assert_same(['doc_count' => 0, 'len_sum' => 0], $storage->get_meta('en'), "{$name} old language meta should be decremented");
        assert_same(['doc_count' => 1, 'len_sum' => 3], $storage->get_meta('pl'), "{$name} new language meta should be incremented");

        assert_true($indexer->index_document(77, '<h1>shared</h1><p>beta beta gamma</p>', ['lang' => 'pl']), "{$name} term distribution reindex should change");
        assert_same([77 => 4], WP_FTS_PostingsCodec::decode($storage->get_terms([WP_FTS_TermNamespace::namespace_term('pl', 'shared')])[WP_FTS_TermNamespace::namespace_term('pl', 'shared')]['postings']), "{$name} boosted shared tf after reindex");
        assert_same([77 => 2], WP_FTS_PostingsCodec::decode($storage->get_terms([WP_FTS_TermNamespace::namespace_term('pl', 'beta')])[WP_FTS_TermNamespace::namespace_term('pl', 'beta')]['postings']), "{$name} repeated beta tf after reindex");
        assert_same(['doc_count' => 1, 'len_sum' => 7], $storage->get_meta('pl'), "{$name} reindexed doc length should use rounded weighted term frequencies");

        assert_true($indexer->delete_document(77), "{$name} delete should tombstone active doc");
        assert_same([], $searcher->search('shared', ['lang' => 'pl']), "{$name} tombstoned doc should be hidden from search");
        assert_same(['doc_count' => 0, 'len_sum' => 0], $storage->get_meta('pl'), "{$name} tombstone should not leak stats");

        assert_true($indexer->index_document(77, '<p>shared neu</p>', ['lang' => 'de']), "{$name} delete then re-add should index active doc");
        assert_same([], $searcher->search('shared', ['lang' => 'pl']), "{$name} re-add should not revive old Polish terms");
        assert_same([77], array_column($searcher->search('shared', ['lang' => 'de']), 'doc_id'), "{$name} re-added German doc should be searchable");
        assert_same(['doc_count' => 1, 'len_sum' => 2], $storage->get_meta('de'), "{$name} re-added German meta");

        $indexer->optimize();
        assert_same([77], $storage->all_doc_ids(true), "{$name} optimize should leave only re-added active doc");
        cleanup_storage($storage);
    }
});

test_case('quality per-language BM25 stats and boolean search properties are stable', function (): void {
    $analyzer = new WP_FTS_Analyzer([
        'stopwords_by_lang' => ['en' => ['the', 'and']],
    ]);
    $documents = [
        1 => ['lang' => 'en', 'html' => '<p>tie shared alpha</p>'],
        2 => ['lang' => 'en', 'html' => '<p>tie shared alpha</p>'],
        3 => ['lang' => 'en', 'html' => '<p>shared alpha alpha beta rareterm</p>'],
        4 => ['lang' => 'en', 'html' => '<p>shared beta</p>'],
        5 => ['lang' => 'pl', 'html' => '<p>tie shared alpha zamek</p>'],
        6 => ['lang' => 'de', 'html' => '<p>tie shared alpha strasse</p>'],
    ];
    $storage = new WP_FTS_Storage_InMemory();
    qssp_index_documents($storage, $analyzer, $documents);
    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    assert_same(['doc_count' => 4, 'len_sum' => 13], $storage->get_meta('en'), 'English BM25 partition stats');
    assert_same(['doc_count' => 1, 'len_sum' => 4], $storage->get_meta('pl'), 'Polish BM25 partition stats');
    assert_same(['doc_count' => 1, 'len_sum' => 4], $storage->get_meta('de'), 'German BM25 partition stats');
    assert_same(4, $storage->get_terms([WP_FTS_TermNamespace::namespace_term('en', 'share')])[WP_FTS_TermNamespace::namespace_term('en', 'share')]['df'], 'English shared df should stay language-local');
    assert_same(1, $storage->get_terms([WP_FTS_TermNamespace::namespace_term('pl', 'shared')])[WP_FTS_TermNamespace::namespace_term('pl', 'shared')]['df'], 'Polish shared df should stay language-local');
    assert_same([], $storage->get_terms(['shared']), 'raw shared term should never be stored unnamespaced');

    assert_search_results_equal(
        $searcher->search('shared', ['lang' => 'en', 'limit' => 10]),
        $searcher->search('shared shared', ['lang' => 'en', 'limit' => 10]),
        'duplicate query terms should be deduplicated'
    );
    assert_same([1, 2], array_column($searcher->search('tie', ['lang' => 'en', 'limit' => 10]), 'doc_id'), 'equal scores should tie-break by ascending doc id');
    assert_same([4, 3], array_column($searcher->search('shared beta', ['lang' => 'en', 'mode' => 'AND', 'limit' => 10]), 'doc_id'), 'AND should require both known terms and keep BM25 ordering');
    assert_same([], $searcher->search('shared missing', ['lang' => 'en', 'mode' => 'AND']), 'AND with unknown terms should be empty');
    assert_same(4, count($searcher->search('shared missing', ['lang' => 'en', 'mode' => 'OR', 'limit' => 10])), 'OR should keep docs matching known terms');
    assert_same(
        array_slice($searcher->search('shared', ['lang' => 'en', 'limit' => 10]), 0, 2),
        $searcher->search('shared', ['lang' => 'en', 'limit' => 2]),
        'limit should return the top prefix only'
    );
    assert_same([], $searcher->search('', ['lang' => 'en']), 'empty query should return no results');
    assert_same([], $searcher->search('the and', ['lang' => 'en']), 'stopword-only query should return no results');
    assert_same([], $searcher->search('shared', ['lang' => 'fr']), 'unpopulated language partition should not match same normalized term');

    $rare = $searcher->search('rareterm', ['lang' => 'en', 'limit' => 10]);
    assert_same(3, $rare[0]['doc_id'] ?? null, 'rareterm should match the only containing English doc');
    assert_float_near(test_bm25_score(1, 5, 4, 1, 13 / 4), $rare[0]['score'], 'rareterm BM25 should use English partition stats');

    try {
        $searcher->search('shared', ['lang' => 'en', 'mode' => 'XOR']);
        assert_true(false, 'invalid search mode should throw');
    } catch (InvalidArgumentException $e) {
        assert_contains('OR or AND', $e->getMessage(), 'invalid mode exception should describe accepted modes');
    }
});

test_case('quality indexed search matches a language-aware brute-force oracle over generated corpora', function (): void {
    $seeds = [45011, 45012, 45013, 45014, 45015];
    $languages = qssp_languages();
    $vocab = qssp_vocab();
    $analyzer = new WP_FTS_Analyzer();

    foreach ($seeds as $seed) {
        mt_srand($seed);
        $documents = [];
        for ($docId = 1; $docId <= 18; $docId++) {
            $revision = mt_rand(1, 6);
            $documents[$docId] = qssp_generated_document($seed, $docId, $revision);
        }

        $storage = new WP_FTS_Storage_InMemory();
        qssp_index_documents($storage, $analyzer, $documents);
        $searcher = new WP_FTS_Searcher($storage, $analyzer);

        for ($q = 0; $q < 18; $q++) {
            $lang = $languages[($q + $seed) % count($languages)];
            $words = $vocab[$lang];
            $queryParts = [$words[($q + 1) % count($words)]];
            if ($q % 3 === 0) {
                $queryParts[] = $words[($q + 4) % count($words)];
            }
            if ($q % 7 === 0) {
                $queryParts[] = 'absentterm';
            }
            $query = implode(' ', $queryParts);
            $mode = $q % 4 === 0 ? 'AND' : 'OR';
            $limit = 3 + ($q % 5);

            assert_search_results_equal(
                qssp_language_oracle_search($documents, $analyzer, $query, $lang, $mode, $limit),
                $searcher->search($query, ['lang' => $lang, 'mode' => $mode, 'limit' => $limit]),
                "seed {$seed} generated oracle {$mode} {$lang} {$query}"
            );
        }
    }
});

test_case('quality randomized incremental indexing converges with full rebuild for memory and file storage', function (): void {
    $seeds = [45101, 45102, 45103];
    $languages = qssp_languages();
    $vocab = qssp_vocab();
    $analyzer = new WP_FTS_Analyzer();

    foreach ($seeds as $seed) {
        foreach (storage_factories("qssp_converge_{$seed}") as $backend => $factory) {
            mt_srand($seed);
            $incremental = $factory();
            $indexer = new WP_FTS_Indexer($incremental, $analyzer);
            $active = [];
            $revisions = [];

            for ($step = 0; $step < 54; $step++) {
                $docId = 1 + mt_rand(0, 9);
                $operation = mt_rand(0, 9);
                if ($operation <= 5) {
                    $revisions[$docId] = ($revisions[$docId] ?? 0) + 1;
                    $document = qssp_generated_document($seed + $step, $docId, $revisions[$docId]);
                    assert_true(
                        $indexer->index_document($docId, $document['html'], ['lang' => $document['lang']]),
                        "seed {$seed} {$backend} step {$step} reindex should change state"
                    );
                    $active[$docId] = $document;
                } elseif ($operation <= 7) {
                    $wasActive = isset($active[$docId]);
                    assert_same($wasActive, $indexer->delete_document($docId), "seed {$seed} {$backend} step {$step} delete delta");
                    unset($active[$docId]);
                } elseif (isset($active[$docId])) {
                    $before = storage_snapshot($incremental);
                    assert_true(
                        !$indexer->index_document($docId, $active[$docId]['html'], ['lang' => $active[$docId]['lang']]),
                        "seed {$seed} {$backend} step {$step} same hash should skip"
                    );
                    assert_same($before, storage_snapshot($incremental), "seed {$seed} {$backend} step {$step} same hash snapshot");
                } else {
                    assert_true(!$indexer->delete_document($docId), "seed {$seed} {$backend} step {$step} deleting missing doc should be a no-op");
                }
            }

            for ($docId = 1; $docId <= 4; $docId++) {
                if (!isset($active[$docId])) {
                    $revisions[$docId] = ($revisions[$docId] ?? 0) + 1;
                    $document = qssp_generated_document($seed + 999, $docId, $revisions[$docId]);
                    $indexer->index_document($docId, $document['html'], ['lang' => $document['lang']]);
                    $active[$docId] = $document;
                }
            }
            ksort($active, SORT_NUMERIC);

            $indexer->optimize();
            $full = $factory();
            qssp_index_documents($full, $analyzer, $active)->optimize();
            assert_same(storage_snapshot($full), storage_snapshot($incremental), "seed {$seed} {$backend} incremental snapshot should match full rebuild");

            $incrementalSearcher = new WP_FTS_Searcher($incremental, $analyzer);
            $fullSearcher = new WP_FTS_Searcher($full, $analyzer);
            for ($i = 0; $i < 12; $i++) {
                $lang = $languages[($seed + $i) % count($languages)];
                $words = $vocab[$lang];
                $query = $words[$i % count($words)] . ($i % 4 === 0 ? ' absentterm' : '');
                $mode = $i % 5 === 0 ? 'AND' : 'OR';
                assert_search_results_equal(
                    $fullSearcher->search($query, ['lang' => $lang, 'mode' => $mode, 'limit' => 8]),
                    $incrementalSearcher->search($query, ['lang' => $lang, 'mode' => $mode, 'limit' => 8]),
                    "seed {$seed} {$backend} converged search {$mode} {$lang} {$query}"
                );
            }

            cleanup_storage($incremental);
            cleanup_storage($full);
        }
    }
});

if (defined('WP_FTS_QSSP_DIRECT_RUN') && WP_FTS_QSSP_DIRECT_RUN) {
    wp_fts_qssp_run_registered_tests_and_exit();
}
