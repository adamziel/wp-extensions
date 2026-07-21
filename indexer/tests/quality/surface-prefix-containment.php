<?php
declare(strict_types=1);

final class WP_FTS_Surface_Test_Analyzer
{
    /** @param array<int,array<string,mixed>> $occurrences */
    public function __construct(private array $occurrences)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function analyze_plain_content(string $text, array $options = []): array
    {
        return $this->occurrences;
    }

    /** @return array<int,array<string,mixed>> */
    public function analyze_content(string $html, array $options = []): array
    {
        return $this->occurrences;
    }

    /** Keep surface-prefix fixture fingerprints stable across analyzer internals. */
    public function index_signature(): string
    {
        return 'surface-test-v1';
    }
}

/** @param array<int,array<string,mixed>> $occurrences @return array<string,mixed> */
function wp_fts_surface_prepare_occurrences(array $occurrences, string $lang = 'en'): array
{
    $storage = new WP_FTS_Storage_Mysql(new WP_FTS_Test_WPDB());
    $indexer = new WP_FTS_Indexer($storage, new WP_FTS_Surface_Test_Analyzer($occurrences));

    return $indexer->prepare_document_fields(1, [['name' => 'content', 'text' => 'source']], [
        'lang' => $lang,
        'metadata' => [],
    ]);
}

/** @return array<string,mixed> */
function wp_fts_surface_prepare_text(string $text, string $lang): array
{
    $storage = new WP_FTS_Storage_Mysql(new WP_FTS_Test_WPDB());
    $analyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'default_lang' => $lang,
    ]);

    return (new WP_FTS_Indexer($storage, $analyzer))->prepare_document_fields(1, [[
        'name' => 'content',
        'text' => $text,
    ]], ['lang' => $lang, 'metadata' => []]);
}

/** @return array<string,int> */
function wp_fts_surface_frequencies(array $prepared): array
{
    $frequencies = $prepared['surface_frequencies'] ?? null;
    if (!is_array($frequencies)) {
        throw new WP_FTS_TestFailure('Relational preparation must expose normalized surface frequencies.');
    }

    return $frequencies;
}

/** @return array<int,array<string,mixed>> */
function wp_fts_surface_distinct_occurrences(int $count, int $surfaceBytes = 0): array
{
    $occurrences = [];
    for ($index = 0; $index < $count; $index++) {
        $identity = str_pad(base_convert((string) $index, 10, 36), 4, '0', STR_PAD_LEFT);
        $surface = 'aa' . $identity;
        if ($surfaceBytes > strlen($surface)) {
            $surface .= str_repeat(chr(ord('a') + ($index % 26)), $surfaceBytes - strlen($surface));
        }
        $occurrences[] = [
            'term' => $surface,
            'normalized_surface' => $surface,
            'lang' => 'en',
            'position' => $index,
            'weight' => 1,
        ];
    }

    return $occurrences;
}

/** Invoke a private storage compiler without executing SQL. */
function wp_fts_surface_storage_method(WP_FTS_Storage_Mysql $storage, string $method, array $arguments): mixed
{
    $reflection = new ReflectionMethod($storage, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($storage, $arguments);
}

test_case('surface rows stay proportional to source tokens instead of token lengths', function (): void {
    $prepared = wp_fts_surface_prepare_occurrences(wp_fts_surface_distinct_occurrences(80, 252));
    $surfaces = wp_fts_surface_frequencies($prepared);
    assert_same(80, count($surfaces), 'eighty 252-byte source tokens must create eighty kind=1 rows, not 20,000 proper-prefix rows');
    assert_same(80, count($prepared['term_frequencies'] ?? []), 'the same fixture must preserve its eighty lexical identities');

    $first = wp_fts_surface_distinct_occurrences(1, 252)[0]['normalized_surface'];
    assert_same(1, $surfaces[WP_FTS_TermNamespace::namespace_term('en', $first)] ?? null, 'kind=1 must include the complete normalized surface');
    assert_true(!isset($surfaces[WP_FTS_TermNamespace::namespace_term('en', substr($first, 0, 2))]), 'indexing one surface must not materialize any of its proper prefixes');

    $eightyOne = wp_fts_surface_prepare_occurrences(wp_fts_surface_distinct_occurrences(81, 252));
    assert_same(81, count(wp_fts_surface_frequencies($eightyOne)), 'an 81st long token must add one row without rejecting the document');

    $repeated = [];
    for ($index = 0; $index < WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES; $index++) {
        $repeated[] = [
            'term' => 'repeat',
            'normalized_surface' => 'repeating',
            'lang' => 'en',
            'weight' => 1,
        ];
    }
    $repeatedSurfaces = wp_fts_surface_frequencies(wp_fts_surface_prepare_occurrences($repeated));
    assert_same(1, count($repeatedSurfaces), '20,000 repeated source occurrences must create exactly one kind=1 surface identity');
    assert_same(20000, $repeatedSurfaces[WP_FTS_TermNamespace::namespace_term('en', 'repeating')] ?? null, 'the one repeated surface row must retain its complete source-token frequency');
});

test_case_with_pdo_sqlite_fixture('a filtered final token cannot turn the previous word into a prefix', function (): void {
    $analyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'default_lang' => 'en',
        'stopwords_by_lang' => ['en' => ['the']],
    ]);
    $occurrences = $analyzer->analyze_query_occurrences('dog the', [
        'query_lang' => 'en',
        '_include_query_surface' => true,
    ]);
    $last = $occurrences[array_key_last($occurrences)] ?? null;
    assert_same('', $last['term'] ?? null, 'a filtered trailing stopword must remain as a non-searchable surface marker');
    assert_same('the', $last['normalized_surface'] ?? null, 'the marker must retain the normalized final typed surface');

    [, $storage] = wp_fts_v4_regression_search_fixture();
    $searcher = WP_FTS_Searcher::for_set_oriented_storage($storage, $analyzer);
    $result = $searcher->search('dog the', [
        'query_lang' => 'en',
        'prefix_matching' => true,
        'prefix_min_length' => 2,
        '_search_ready_incarnation' => wp_fts_v4_regression_ready_incarnation(),
        '_search_ready_profile_hash' => wp_fts_v4_regression_ready_profile_hash(),
    ]);
    assert_same([], $result['results'] ?? null, 'a filtered trailing token must disable only the prefix branch without aborting exact search');

    $snippet = $searcher->snippet_for_text('dogmatic theorem', 'dog the', [
        'query_lang' => 'en',
        'prefix_matching' => true,
        'prefix_min_length' => 2,
        'highlight' => true,
        'snippet_length' => 100,
    ]);
    assert_true(!str_contains($snippet, '<mark>dogmatic</mark>'), 'the prior word must not become the authoritative presentation prefix');
});

test_case('one document admits 4096 lexical and 4096 bounded surface rows', function (): void {
    $prepared = wp_fts_surface_prepare_occurrences(
        wp_fts_surface_distinct_occurrences(WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_TERMS)
    );
    assert_same(4096, count($prepared['term_frequencies'] ?? []), 'the lexical boundary must retain every distinct term');
    assert_same(4096, count(wp_fts_surface_frequencies($prepared)), 'the surface boundary must retain one row for every distinct source token');
    assert_same(
        4096,
        count(array_filter(
            array_keys(wp_fts_surface_frequencies($prepared)),
            static fn(string $surface): bool => str_starts_with((string) (WP_FTS_TermNamespace::split_term($surface)['term'] ?? ''), 'aa')
        )),
        'one document may reach the complete 4,096-surface boundary under a single typed prefix without materializing prefixes'
    );
    assert_same(8192, WP_FTS_Storage_Mysql::MAX_DOCUMENT_POSTINGS, 'the combined per-document posting envelope must be exactly lexical plus surface bounds');
    assert_same(8192, WP_FTS_Storage_Mysql::MAX_BATCH_TERMS, 'one maximum document must fit the batch dictionary bound without a parallel larger surface allowance');
    assert_same(
        WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_TERMS,
        WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES,
        'lexical and source-surface identities must share the same 4,096-row hard bound'
    );
});

test_case('surface rows preserve raw inflections and source-token frequency', function (): void {
    $running = wp_fts_surface_prepare_text('running', 'en');
    assert_same(1, $running['term_frequencies'][WP_FTS_TermNamespace::namespace_term('en', 'run')] ?? null, 'English exact analysis should retain the run stem');
    assert_same(1, wp_fts_surface_frequencies($running)[WP_FTS_TermNamespace::namespace_term('en', 'running')] ?? null, 'prefix runn must remain discoverable through the complete running surface');

    $spanish = wp_fts_surface_prepare_text('buscando', 'es');
    assert_same(1, $spanish['term_frequencies'][WP_FTS_TermNamespace::namespace_term('es', 'busc')] ?? null, 'Spanish exact analysis should retain the busc stem');
    assert_same(1, wp_fts_surface_frequencies($spanish)[WP_FTS_TermNamespace::namespace_term('es', 'buscando')] ?? null, 'prefix buscan must remain discoverable through the raw Spanish surface');

    $alternatives = wp_fts_surface_prepare_occurrences([
        ['term' => 'run', 'normalized_surface' => 'running', 'lang' => 'en', 'position' => 0, 'weight' => 1],
        ['term' => 'running', 'normalized_surface' => 'running', 'lang' => 'en', 'position' => 0, 'weight' => 1],
        ['term' => 'run', 'normalized_surface' => 'running', 'lang' => 'en', 'position' => 1, 'weight' => 1],
        ['term' => 'runner', 'normalized_surface' => 'runner', 'lang' => 'en', 'position' => 2, 'weight' => 3],
    ]);
    $surfaces = wp_fts_surface_frequencies($alternatives);
    assert_same(2, $surfaces[WP_FTS_TermNamespace::namespace_term('en', 'running')] ?? null, 'analyzer alternatives must contribute once per source token while repeated tokens retain TF');
    assert_same(3, $surfaces[WP_FTS_TermNamespace::namespace_term('en', 'runner')] ?? null, 'a shared document surface must retain its aggregate source weight');
});

test_case('surface identities preserve Unicode numeric and long lexical runs', function (): void {
    $unicode = wp_fts_surface_prepare_occurrences([
        ['term' => 'éclair', 'normalized_surface' => 'éclair', 'lang' => 'fr', 'position' => 0],
        ['term' => '火山石', 'normalized_surface' => '火山石', 'lang' => 'fr', 'position' => 1],
        ['term' => '1234', 'normalized_surface' => '1234', 'lang' => 'fr', 'position' => 2],
    ], 'fr');
    assert_same([
        WP_FTS_TermNamespace::namespace_term('fr', '1234'),
        WP_FTS_TermNamespace::namespace_term('fr', 'éclair'),
        WP_FTS_TermNamespace::namespace_term('fr', '火山石'),
    ], array_keys(wp_fts_surface_frequencies($unicode)), 'binary surface identities must retain numeric, accented, and multibyte strings deterministically');

    $longSurface = str_repeat('é', 200);
    $long = wp_fts_surface_prepare_occurrences([
        ['term' => 'short', 'normalized_surface' => $longSurface, 'lang' => 'en', 'position' => 0],
    ]);
    $storedSurface = (string) array_key_first(wp_fts_surface_frequencies($long));
    $split = WP_FTS_TermNamespace::split_term($storedSurface);
    assert_same(252, strlen($split['term'] ?? ''), 'an over-width raw surface with a short lemma must retain every representable UTF-8 prefix');
    assert_same($split['term'] ?? '', WP_FTS_Utf8::repair((string) ($split['term'] ?? '')), 'surface truncation must stop at a UTF-8 boundary');
    assert_same(substr($longSurface, 0, 252), $split['term'] ?? null, 'the stored long surface must be the exact maximum-length binary prefix');

    $productionStorage = new WP_FTS_Storage_Mysql(new WP_FTS_Test_WPDB());
    $productionAnalyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'default_lang' => 'en',
        'stemmer' => static fn(string $term, string $_language): string => 'short',
    ]);
    $productionLong = (new WP_FTS_Indexer($productionStorage, $productionAnalyzer))->prepare_document_fields(2, [[
        'name' => 'content',
        'text' => str_repeat('a', 300),
    ]], ['lang' => 'en', 'metadata' => []]);
    $productionSurface = WP_FTS_TermNamespace::split_term((string) array_key_first(wp_fts_surface_frequencies($productionLong)));
    assert_same('short', WP_FTS_TermNamespace::split_term((string) array_key_first($productionLong['term_frequencies'] ?? []))['term'] ?? null, 'the production analyzer fixture must really shorten its over-width raw token');
    assert_same(str_repeat('a', 252), $productionSurface['term'] ?? null, 'production analyzer surfaces must survive an over-width raw token when its exact lemma is short');

    $surfaceOnly = wp_fts_surface_prepare_text(str_repeat('b', 300), 'en');
    $surfaceOnlyIdentity = WP_FTS_TermNamespace::split_term(
        (string) array_key_first(wp_fts_surface_frequencies($surfaceOnly))
    );
    assert_same([], $surfaceOnly['term_frequencies'] ?? null, 'an over-width token without a shorter lemma must not fabricate an exact lexical identity');
    assert_same(str_repeat('b', 252), $surfaceOnlyIdentity['term'] ?? null, 'an over-width token without an exact term must still retain every representable prefix');
});

test_case('surface SQL cost-selects one bounded AND-prefix driver', function (): void {
    $storage = new WP_FTS_Storage_Mysql(new WP_FTS_Test_WPDB());
    $prefix = ['group_id' => 1, 'lang' => 'en', 'term' => 'runn', 'doc_freq' => 100000];
    $range = wp_fts_surface_storage_method($storage, 'surface_range_sql', [$prefix]);
    $rangeSql = (string) ($range['sql'] ?? '');
    assert_contains('FORCE INDEX (term_identity)', $rangeSql, 'surface lookup must use the `(lang,kind,term)` dictionary index');
    assert_same(1, substr_count($rangeSql, 'pt.term >='), 'surface lookup must contain exactly one inclusive binary lower bound');
    assert_same(1, substr_count($rangeSql, 'pt.term <'), 'surface lookup must contain exactly one exclusive bytewise-successor bound');
    assert_contains('pt.kind = 1', $rangeSql, 'surface lookup must keep kind=1 separate from lexical rows');
    assert_true(!str_contains($rangeSql, 'UNION'), 'one typed prefix must never expand into a dictionary UNION');

    $options = [
        'post_statuses' => ['publish'],
        'post_types' => [],
        'limit' => 11,
        'page_size' => 10,
        'search_ready_incarnation' => str_repeat('a', 32),
        'search_ready_profile_hash' => str_repeat('b', 40),
        '_search_epoch_generation' => 1,
        '_search_epoch_incarnation' => str_repeat('c', 32),
    ];
    $groups = [
        0 => [['term_id' => 10, 'doc_freq' => 1, 'weight' => 1000]],
        1 => [['term_id' => 11, 'doc_freq' => 100000, 'weight' => 10]],
    ];
    $candidateFirst = wp_fts_surface_storage_method($storage, 'build_rank_query', [
        $groups, 2, $prefix, 'AND', $options, null,
    ]);
    $candidateSql = (string) ($candidateFirst['sql'] ?? '');
    assert_same('candidate_first', $candidateFirst['prefix_strategy'] ?? null, 'a 100,000-row prefix must lose to one 8,192-posting candidate upper bound');
    assert_contains('STRAIGHT_JOIN wp_fts_postings po FORCE INDEX (post_term_impact) ON po.post_id = c.post_id AND po.term_id = q.term_id', $candidateSql, 'exact groups must use bounded candidate/key probes');
    assert_contains('STRAIGHT_JOIN wp_fts_postings ppo FORCE INDEX (post_term_impact) ON prefix_candidate.post_id = ppo.post_id', $candidateSql, 'a broad prefix must scan the bounded candidate posting envelope first');
    assert_contains('STRAIGHT_JOIN wp_fts_terms pt FORCE INDEX (PRIMARY) ON pt.term_id = ppo.term_id', $candidateSql, 'candidate postings must classify their term identities by primary key');
    assert_true(!str_contains($candidateSql, 'ppo FORCE INDEX (PRIMARY)'), 'candidate-first SQL must not scan the complete prefix posting range');
    assert_same(1, substr_count($candidateSql, 'pt.term >='), 'candidate-first AND must contain exactly one surface predicate');
    assert_same(2, substr_count($candidateSql, 'SELECT DISTINCT ap.post_id'), 'the exact and prefix arms must each perform one bounded rare-anchor scan');
    assert_contains('MAX(CAST(ppo.impact', $candidateSql, 'matching prefix surfaces must retain one per-group maximum');

    $smallerRange = array_replace($prefix, ['doc_freq' => 8191]);
    $rangeFirst = wp_fts_surface_storage_method($storage, 'build_rank_query', [
        $groups, 2, $smallerRange, 'AND', $options, null,
    ]);
    $rangeFirstSql = (string) ($rangeFirst['sql'] ?? '');
    assert_same('surface_range', $rangeFirst['prefix_strategy'] ?? null, 'an 8,191-row prefix must remain cheaper than one maximum-size candidate envelope');
    assert_contains('STRAIGHT_JOIN wp_fts_postings ppo FORCE INDEX (PRIMARY) ON ppo.term_id = pt.term_id', $rangeFirstSql, 'the smaller prefix range must drive posting-primary ranges from the indexed dictionary range');
    assert_contains('prefix_candidate ON prefix_candidate.post_id = ppo.post_id', $rangeFirstSql, 'range-first SQL must intersect actual matching postings with rare candidates');
    assert_true(!str_contains($rangeFirstSql, 'ppo FORCE INDEX (post_term_impact)'), 'range-first SQL must not scan unrelated candidate postings');
    assert_true(!str_contains($rangeFirstSql, 'pt FORCE INDEX (PRIMARY)'), 'range-first SQL must not classify candidate term ids individually');

    $overflowSafe = wp_fts_surface_storage_method($storage, 'build_rank_query', [
        [
            0 => [['term_id' => 10, 'doc_freq' => PHP_INT_MAX - 1, 'weight' => 1000]],
            1 => [['term_id' => 11, 'doc_freq' => PHP_INT_MAX, 'weight' => 10]],
        ],
        2,
        array_replace($prefix, ['doc_freq' => PHP_INT_MAX]),
        'AND',
        $options,
        null,
    ]);
    assert_same('surface_range', $overflowSafe['prefix_strategy'] ?? null, 'saturated costs must compare without integer multiplication overflow');

    $selectivePrefix = array_replace($prefix, ['doc_freq' => 1]);
    $commonExact = wp_fts_surface_storage_method($storage, 'build_rank_query', [
        [
            0 => [['term_id' => 10, 'doc_freq' => 100000, 'weight' => 10]],
            1 => [],
        ],
        2,
        $selectivePrefix,
        'AND',
        $options,
        null,
    ]);
    $commonExactSql = (string) ($commonExact['sql'] ?? '');
    assert_same(1, $commonExact['anchor_group'] ?? null, 'a selective final prefix must anchor ahead of a corpus-wide exact group');
    assert_contains('STRAIGHT_JOIN wp_fts_postings prefix_posting FORCE INDEX (PRIMARY)', $commonExactSql, 'the selective prefix anchor must stream its matching postings by term id');
    assert_contains('LEFT JOIN wp_fts_postings po FORCE INDEX (post_term_impact)', $commonExactSql, 'the selective prefix candidates must probe remaining exact terms post-first');
    assert_same(1, substr_count($commonExactSql, 'pt.term >='), 'the selective prefix anchor must contain one dictionary range');
    assert_true(!str_contains($commonExactSql, 'SELECT DISTINCT ap.post_id'), 'the selective prefix plan must not materialize the common exact posting list as an anchor');

    $candidateShape = wp_fts_surface_storage_method($storage, 'candidate_sql', [
        "SELECT 10 AS term_id, 0 AS group_id, 1 AS weight\nUNION ALL\nSELECT 11, 0, 1",
        0,
        $options,
        'dedupe_probe',
    ]);
    $candidateShapeSql = (string) ($candidateShape['sql'] ?? '');
    assert_contains('SELECT DISTINCT ap.post_id', $candidateShapeSql, 'exact morphology anchors must deduplicate post ids inside their posting relation');
    assert_true(strpos($candidateShapeSql, 'SELECT DISTINCT ap.post_id') < strpos($candidateShapeSql, 'STRAIGHT_JOIN wp_fts_documents'), 'exact-anchor visibility must run after morphology posting deduplication');
});

test_case('surface planning gates and costs every final-prefix range once', function (): void {
    $storage = new WP_FTS_Storage_Mysql(new WP_FTS_Test_WPDB());
    $prefix = ['group_id' => 1, 'lang' => 'en', 'term' => 'runn'];
    $options = [
        'post_statuses' => ['publish'],
        'post_types' => [],
        'limit' => 11,
        'page_size' => 10,
        'search_ready_incarnation' => str_repeat('a', 32),
        'search_ready_profile_hash' => str_repeat('b', 40),
        '_search_epoch_generation' => 1,
        '_search_epoch_incarnation' => str_repeat('c', 32),
    ];

    $andProbe = wp_fts_surface_storage_method($storage, 'surface_plan_probe_sql', [
        $prefix,
        $options,
        [
            0 => [['key' => WP_FTS_TermNamespace::namespace_term('en', 'mandatory')]],
            1 => [['key' => WP_FTS_TermNamespace::namespace_term('en', 'runn')]],
        ],
        'AND',
    ]);
    $andSql = (string) ($andProbe['sql'] ?? '');
    assert_same(1, substr_count($andSql, ') mandatory_requested'), 'the range gate must check all non-prefix mandatory groups in one constant relation');
    assert_contains('NOT EXISTS (SELECT 1 FROM wp_fts_work scope_control', $andSql, 'reconciliation must close the range gate before vocabulary work');
    assert_contains('surface_epoch.generation > 0', $andSql, 'a missing publication epoch must close the range gate');
    assert_contains('HAVING COUNT(mandatory_term.term_id) = 0', $andSql, 'an impossible exact group must close the gate before the full surface aggregate');
    assert_same(1, substr_count($andSql, 'SUM(surface_identity.doc_freq)'), 'planning must cost the surface range exactly once');
    assert_true(!str_contains($andSql, 'LIMIT 1) surface_identity'), 'the prefix cost must cover every matching surface identity');
    assert_true(strpos($andSql, ') surface_gate') < strpos($andSql, ') surface_identity'), 'the nonmergeable control gate must drive the surface range in enforced order');

    $orProbe = wp_fts_surface_storage_method($storage, 'surface_plan_probe_sql', [$prefix, $options]);
    $orSql = (string) ($orProbe['sql'] ?? '');
    assert_same(1, substr_count($orSql, 'SUM(surface_identity.doc_freq)'), 'OR planning should retain the same one-range cost used by AND planning');
    assert_true(!str_contains($orSql, 'LIMIT 1) surface_identity'), 'the shared plan shape must not publish a partial prefix cost');

    $rank = wp_fts_surface_storage_method($storage, 'build_rank_query', [[
        0 => [['term_id' => 10, 'doc_freq' => 100, 'weight' => 100]],
    ], 1, ['group_id' => 0, 'lang' => 'en', 'term' => 'runn'], 'OR', $options, null]);
    $rankSql = (string) ($rank['sql'] ?? '');
    assert_contains(') rank_gate', $rankSql, 'ranking must repeat the publication/scope controls inside the expensive limited relation');
    assert_contains(
        ") rank_gate\nSTRAIGHT_JOIN (SELECT pt.term_id, pt.doc_freq",
        $rankSql,
        'rank control must drive the nonmergeable surface-posting relation before it can materialize'
    );
});

test_case('surface bounds and cursors are bytewise and v6-specific', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $storage = new WP_FTS_Storage_Mysql($wpdb);
    assert_same('ac', wp_fts_surface_storage_method($storage, 'binary_successor', ["ab\xff"]), 'bytewise successor must carry over a trailing 0xff byte');
    assert_same(null, wp_fts_surface_storage_method($storage, 'binary_successor', ["\xff\xff"]), 'an all-0xff prefix must use an unbounded upper range');

    $componentConstant = (new ReflectionClass(WP_FTS_Indexer::class))->getReflectionConstant('INDEX_SIGNATURE_VERSION');
    assert_true($componentConstant instanceof ReflectionClassConstant, 'surface storage must expose an explicit migration signature');
    assert_same('wp-fts-indexer-v6', $componentConstant->getValue(), 'surface rows are incompatible with the abandoned v5 proper-prefix generation');
    assert_same(false, $storage instanceof WP_FTS_Row_Postings_Writer_Storage, 'MySQL must expose only its bounded prepared-document writer capability');
    assert_same(false, method_exists($storage, 'replace_doc_postings'), 'the obsolete one-document writer must not exist on production storage');
    assert_same(true, $storage->indexes_surface_postings(), 'the production storage must explicitly request normalized analyzer surfaces');

    $maxPrefix = str_repeat('z', 252);
    $descriptor = wp_fts_surface_storage_method($storage, 'search_prefix_descriptor', [[
        'groups' => [[['lang' => 'en']]],
    ], [
        'prefix_matching' => true,
        'prefix_group_index' => 0,
        'prefix_min_length' => 4,
        'prefix_surface' => ['lang' => 'en', 'term' => $maxPrefix],
    ]]);
    assert_same($maxPrefix, $descriptor['term'] ?? null, 'the maximum representable typed prefix must compile without truncation');

    $overWidthOptions = [
        'mode' => 'OR',
        'prefix_matching' => true,
        'prefix_group_index' => 0,
        'prefix_min_length' => 4,
        'prefix_surface' => ['lang' => 'en', 'term' => str_repeat('z', 256)],
        'search_ready_incarnation' => str_repeat('a', 32),
        'search_ready_profile_hash' => str_repeat('b', 40),
    ];
    wp_fts_surface_storage_method($storage, 'assert_search_option_bounds', [$overWidthOptions]);
    $overWidthDescriptor = wp_fts_surface_storage_method($storage, 'search_prefix_descriptor', [[
        'groups' => [[['lang' => 'en']]],
    ], $overWidthOptions]);
    assert_same(null, $overWidthDescriptor, 'an analyzer-bounded but unrepresentable typed surface must disable only prefix matching');

    $cursorPlan = ['groups' => [[['key' => WP_FTS_TermNamespace::namespace_term('en', 'run'), 'rank' => 0]]]];
    $cursorOptions = [
        'post_statuses' => ['publish'],
        'post_types' => [],
        'search_ready_incarnation' => str_repeat('a', 32),
        'search_ready_profile_hash' => str_repeat('b', 40),
    ];
    $firstFingerprint = wp_fts_surface_storage_method($storage, 'search_cursor_fingerprint', [
        $cursorPlan, ['group_id' => 0, 'lang' => 'en', 'term' => 'runn'], 'OR', $cursorOptions, 1, str_repeat('c', 32),
    ]);
    $secondFingerprint = wp_fts_surface_storage_method($storage, 'search_cursor_fingerprint', [
        $cursorPlan, ['group_id' => 0, 'lang' => 'en', 'term' => 'runner'], 'OR', $cursorOptions, 1, str_repeat('c', 32),
    ]);
    assert_true(!hash_equals($firstFingerprint, $secondFingerprint), 'cursor authentication must bind the exact normalized typed surface');
});

test_case('point collection APIs are absent while bounded page diagnostics stay post-first', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $storage = new WP_FTS_Storage_Mysql($wpdb);
    foreach (['get_doc', 'get_doc_metadata', 'terms_for_doc', 'put_term', 'delete_term', 'get_meta', 'all_terms', 'all_doc_ids'] as $method) {
        assert_true(!method_exists($storage, $method), "production storage should not expose {$method}");
    }
    assert_same([], $wpdb->queries, 'capability inspection must not execute SQL');
    assert_same([], $wpdb->prepared, 'capability inspection must not prepare SQL');

    $storage->terms_for_docs([1], 10);
    $diagnosticSql = (string) ($wpdb->prepared[0]['sql'] ?? '');
    assert_contains('wp_fts_postings p FORCE INDEX (post_term_impact)', $diagnosticSql, 'bounded page terms must drive the post-first covering index');
    assert_contains('STRAIGHT_JOIN wp_fts_terms t FORCE INDEX (PRIMARY)', $diagnosticSql, 'bounded page terms must resolve dictionary rows by primary id');

    $contract = wp_fts_surface_storage_method($storage, 'schema_contract', []);
    $termIndexes = array_column($contract['wp_fts_terms']['indexes'] ?? [], 'name');
    assert_true(!in_array('term_hash', $termIndexes, true), 'the unused term-hash secondary index must not amplify every lexical and surface write');
    assert_true(!in_array('term_hash', $contract['wp_fts_terms']['columns'] ?? [], true), 'the unused term-hash payload column must not amplify every lexical and surface write');
});

test_case('bounded batch delete preflights a maximum posting frontier before opening its transaction', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $wpdb->docs[77] = [
        'post_id' => 77,
        'primary_lang' => 'en',
        'content_hash' => 'maximum-frontier',
    ];
    $wpdb->replacementFrontierPostingCounts[77] = WP_FTS_Storage_Mysql::MAX_DOCUMENT_POSTINGS;

    $events = [];
    $wpdb->readQueryObserver = static function (string $sql) use (&$events): void {
        $events[] = 'read:' . $sql;
    };
    $wpdb->queryObserver = static function (string $sql) use (&$events): void {
        $events[] = 'write:' . $sql;
    };

    $storage = new WP_FTS_Storage_Mysql($wpdb);
    $result = $storage->replace_prepared_documents([], [77]);
    assert_same(1, $result['deleted'] ?? null, 'the bounded delete path must accept an old document at the complete 8,192-posting frontier');

    $frontierOffset = null;
    $transactionOffset = null;
    foreach ($events as $offset => $event) {
        if ($frontierOffset === null && str_contains($event, 'wp_fts:replacement-frontier')) {
            $frontierOffset = $offset;
        }
        if ($transactionOffset === null && $event === 'write:START TRANSACTION') {
            $transactionOffset = $offset;
        }
    }
    assert_true(is_int($frontierOffset), 'delete must issue the bounded replacement-frontier preflight');
    assert_true(is_int($transactionOffset), 'delete must still protect its mutations with a transaction');
    assert_true($frontierOffset < $transactionOffset, 'the maximum old-posting frontier must be rejected or accepted before START TRANSACTION');
});
