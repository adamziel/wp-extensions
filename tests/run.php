<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

final class WP_FTS_TestFailure extends RuntimeException
{
}

final class WP_FTS_Fake_HTML_Processor
{
    private int $offset = -1;

    /**
     * @param array<int,array{type:string,breadcrumbs?:string[],text?:string,attrs?:array<string,string>,closing?:bool}> $tokens
     */
    public function __construct(private array $tokens)
    {
    }

    public function next_token(): bool
    {
        $this->offset++;

        return isset($this->tokens[$this->offset]);
    }

    public function get_token_type(): ?string
    {
        return $this->current()['type'] ?? null;
    }

    /**
     * @return string[]|null
     */
    public function get_breadcrumbs(): ?array
    {
        return $this->current()['breadcrumbs'] ?? [];
    }

    public function get_modifiable_text(): string
    {
        return (string) ($this->current()['text'] ?? '');
    }

    public function is_tag_closer(): bool
    {
        return (bool) ($this->current()['closing'] ?? false);
    }

    public function get_attribute(string $name): mixed
    {
        return ($this->current()['attrs'] ?? [])[$name] ?? null;
    }

    /**
     * @return array{type?:string,breadcrumbs?:string[],text?:string,attrs?:array<string,string>,closing?:bool}
     */
    private function current(): array
    {
        return $this->tokens[$this->offset] ?? [];
    }
}

/**
 * @var array<int,array{name:string,fn:callable}>
 */
$tests = [];

function test_case(string $name, callable $fn): void
{
    global $tests;
    $tests[] = ['name' => $name, 'fn' => $fn];
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new WP_FTS_TestFailure($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new WP_FTS_TestFailure($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function assert_float_near(float $expected, float $actual, string $message, float $epsilon = 1e-6): void
{
    $scale = max(1.0, abs($expected), abs($actual));
    if (abs($expected - $actual) / $scale > $epsilon) {
        throw new WP_FTS_TestFailure($message . "\nExpected: {$expected}\nActual: {$actual}");
    }
}

/**
 * @return string[]
 */
function test_terms(array $occurrences): array
{
    return array_map(static fn(array $token): string => $token['term'], $occurrences);
}

function test_normalize_without_mbstring(WP_FTS_Normalizer $normalizer, string $token, string $language): string
{
    $language = $normalizer->canonicalize_language($language);

    $lowercase = new ReflectionMethod($normalizer, 'lowercase_without_mbstring');
    $lowercase->setAccessible(true);
    $fold = new ReflectionMethod($normalizer, 'fold_for_language');
    $fold->setAccessible(true);

    return (string) $fold->invoke(
        $normalizer,
        (string) $lowercase->invoke($normalizer, $token, $language),
        $language
    );
}

/**
 * @return array<string,float>
 */
function test_weight_by_term(array $occurrences): array
{
    $weights = [];
    foreach ($occurrences as $occurrence) {
        $weights[$occurrence['term']] = max($weights[$occurrence['term']] ?? 0.0, (float) $occurrence['weight']);
    }
    ksort($weights, SORT_STRING);

    return $weights;
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

/**
 * @return array<int,array{text:string,weight:float,lang:string}>
 */
function test_fallback_segments(WP_FTS_Analyzer $analyzer, string $html, string $documentLang): array
{
    $method = new ReflectionMethod(WP_FTS_Analyzer::class, 'extractWithFallbackParser');
    $method->setAccessible(true);

    return $method->invoke($analyzer, $html, $documentLang);
}

/**
 * @param array<int,string> $documents
 * @return array<int,array{doc_id:int,score:float}>
 */
function brute_force_search(array $documents, WP_FTS_Analyzer $analyzer, string $query, string $mode = 'OR', int $limit = 50): array
{
    $queryTerms = array_values(array_unique($analyzer->analyze_query($query)));
    if ($queryTerms === []) {
        return [];
    }

    $docTermFrequencies = [];
    $docLengths = [];
    $dfs = array_fill_keys($queryTerms, 0);

    foreach ($documents as $docId => $html) {
        $frequencies = $analyzer->weighted_term_frequencies($analyzer->analyze_content($html));
        $docTermFrequencies[$docId] = $frequencies;
        $docLengths[$docId] = array_sum($frequencies);
        foreach ($queryTerms as $term) {
            if (isset($frequencies[$term])) {
                $dfs[$term]++;
            }
        }
    }

    $docCount = count($documents);
    if ($docCount === 0) {
        return [];
    }

    $avgDocLen = array_sum($docLengths) > 0 ? array_sum($docLengths) / $docCount : 1.0;
    $mode = strtoupper($mode);
    $results = [];

    foreach ($docTermFrequencies as $docId => $frequencies) {
        $matched = array_values(array_intersect($queryTerms, array_keys($frequencies)));
        if ($matched === [] || ($mode === 'AND' && count($matched) < count($queryTerms))) {
            continue;
        }

        $score = 0.0;
        foreach ($matched as $term) {
            $tf = $frequencies[$term];
            $df = $dfs[$term];
            if ($df <= 0) {
                continue;
            }
            $idf = log(1.0 + (($docCount - $df + 0.5) / ($df + 0.5)));
            $normalizer = $tf + 1.2 * (1.0 - 0.75 + 0.75 * ($docLengths[$docId] / max(1.0, $avgDocLen)));
            $score += $idf * (($tf * (1.2 + 1.0)) / $normalizer);
        }

        if ($score > 0.0) {
            $results[] = ['doc_id' => (int) $docId, 'score' => $score];
        }
    }

    usort($results, static function (array $a, array $b): int {
        $scoreOrder = $b['score'] <=> $a['score'];
        return $scoreOrder !== 0 ? $scoreOrder : ($a['doc_id'] <=> $b['doc_id']);
    });

    return array_slice($results, 0, $limit);
}

function assert_search_results_equal(array $expected, array $actual, string $message): void
{
    assert_same(count($expected), count($actual), $message . ' result count');
    foreach ($expected as $i => $expectedRow) {
        assert_same($expectedRow['doc_id'], $actual[$i]['doc_id'], $message . " doc_id at {$i}");
        assert_float_near($expectedRow['score'], $actual[$i]['score'], $message . " score at {$i}");
    }
}

/**
 * @return array{terms:array<string,array{df:int,postings:array<int,int>}>,docs:array<int,array{doc_len:int,content_hash:?string,deleted:bool}>,meta:array{doc_count:int,len_sum:int}}
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
        'meta' => $storage->get_meta(),
    ];
}

/**
 * @param array<int,string> $documents
 */
function build_index(WP_FTS_Storage $storage, WP_FTS_Analyzer $analyzer, array $documents): WP_FTS_Indexer
{
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    foreach ($documents as $docId => $html) {
        $indexer->index_document((int) $docId, $html);
    }

    return $indexer;
}

function temp_index_path(string $suffix): string
{
    return sys_get_temp_dir() . '/wp_fts_' . getmypid() . '_' . $suffix . '_' . bin2hex(random_bytes(4)) . '.json';
}

test_case('analyzer skips unsafe regions and applies boosts', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    $occurrences = $analyzer->analyze_content(
        '<article><h1>Visible Boost</h1><p>Normal visible</p>' .
        '<script>secret_token()</script><style>.hidden{color:red}</style>' .
        '<nav>navword skipword</nav><strong>Bold term</strong><em>Soft term</em></article>'
    );
    $terms = test_terms($occurrences);
    $weights = test_weight_by_term($occurrences);

    assert_true(!in_array('secret_token', $terms, true), 'script bodies must not be indexed');
    assert_true(!in_array('hidden', $terms, true), 'style bodies must not be indexed');
    assert_true(!in_array('navword', $terms, true), 'nav descendants must not be indexed');
    assert_same(4.0, $weights['visible'], 'h1 boost should use max-over-ancestors');
    assert_same(1.0, $weights['normal'], 'paragraph text should use default boost');
    assert_same(2.0, $weights['bold'], 'strong boost should be applied');
    assert_same(1.5, $weights['soft'], 'em boost should be applied');
});

test_case('analyzer folds diacritics and null processor falls back safely', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    assert_same(['wroclaw', 'lodz', 'cafe'], $analyzer->analyze_query('Wrocław Łódź café'), 'diacritics should fold');

    $fallback = new WP_FTS_Analyzer([
        'html_processor_factory' => static fn(string $html): mixed => null,
    ]);
    $terms = test_terms($fallback->analyze_content('<p>Plain <b>text</b></p><script>ignored</script>'));
    assert_same(['plain', 'text'], $terms, 'null WP_HTML_Processor should fall back to stripped plain text');
});

test_case('language normalizer applies dialect and language-specific folding maps', function (): void {
    $normalizer = new WP_FTS_Normalizer();

    assert_same('wroclaw', $normalizer->normalize_token('Wrocław', 'pl_PL'), 'Polish folding should match ASCII queries');
    assert_same('strasse', $normalizer->normalize_token('Straße', 'de-DE'), 'German sharp s should expand');
    assert_same('fuer', $normalizer->normalize_token('für', 'de'), 'German umlaut should use ae/oe/ue-style expansion');
    assert_same('ıgdır', $normalizer->normalize_token('Iğdır', 'tr-TR'), 'Turkish dotless i must not fold to ASCII i');
    assert_same('istanbul', $normalizer->normalize_token('İstanbul', 'tr'), 'Turkish dotted capital I should normalize to i');
    assert_same('zh-Hant', $normalizer->canonicalize_language('zh_TW'), 'Chinese region should canonicalize to script key');

    $pipeline = new WP_FTS_LanguagePipeline();
    assert_same(
        ['color', 'organize', 'organizing'],
        $pipeline->analyze('colour organise organising', 'en-GB'),
        'English dialect spellings should normalize before stemming'
    );
});

test_case('language normalizer fallback lowercases uppercase non-ASCII without mbstring', function (): void {
    $normalizer = new WP_FTS_Normalizer();

    assert_same('zolc', test_normalize_without_mbstring($normalizer, 'ŻÓŁĆ', 'pl'), 'Polish source fallback should fold uppercase diacritics');
    assert_same('aerger', test_normalize_without_mbstring($normalizer, 'ÄRGER', 'de'), 'German source fallback should expand uppercase umlauts');
    assert_same('cig', test_normalize_without_mbstring($normalizer, 'ÇİĞ', 'tr'), 'Turkish source fallback should lowercase and fold uppercase letters');
    assert_same('ecole', test_normalize_without_mbstring($normalizer, 'ÉCOLE', 'fr'), 'Latin source fallback should fold uppercase diacritics');
});

test_case('language pipeline emits deterministic namespaced terms', function (): void {
    $pipeline = new WP_FTS_LanguagePipeline([
        'namespace_terms' => true,
    ]);

    $terms = $pipeline->analyze('Colour Wrocław', 'en-gb');

    assert_same(["en-GB\x1ecolor", "en-GB\x1ewroclaw"], $terms, 'namespaced terms should use canonical language keys');
    assert_same('656e2d47421e636f6c6f72', bin2hex($terms[0]), 'namespace separator should be byte-stable');
});

test_case('custom stemmers preserve callable arity compatibility', function (): void {
    $reverseAnalyzer = new WP_FTS_Analyzer([
        'stemmer' => 'strrev',
    ]);
    assert_same(['ahpla'], $reverseAnalyzer->analyze_query('alpha'), 'internal one-argument callables should keep working');

    $metaphoneAnalyzer = new WP_FTS_Analyzer([
        'stemmer' => 'metaphone',
    ]);
    assert_same(['TSTNK'], $metaphoneAnalyzer->analyze_query('testing'), 'optional non-language parameters should not receive language');

    $languageAware = new WP_FTS_LanguagePipeline([
        'stemmer' => static fn(string $term, string $language): string => $language . ':' . $term,
    ]);
    assert_same(['en-GB:color'], $languageAware->analyze('colour', 'en-GB'), 'two-argument custom stemmers should receive language');

    $variadic = new WP_FTS_LanguagePipeline([
        'stemmer' => static fn(string $term, string ...$args): string => count($args) . ':' . $term,
    ]);
    assert_same(['1:color'], $variadic->analyze('colour', 'en-GB'), 'variadic custom stemmers should receive language');
});

test_case('snowball and polish stemmer adapters are guarded and pluggable', function (): void {
    $snowball = new WP_FTS_SnowballStemmer();
    assert_same('kotami', $snowball->stem('kotami', 'pl'), 'Snowball adapter should no-op unsupported languages');

    if ($snowball->is_available()) {
        assert_same('run', $snowball->stem('running', 'en'), 'Snowball adapter should use wamania when installed');
    }

    $pipeline = new WP_FTS_LanguagePipeline([
        'enable_stemming' => true,
    ]);
    assert_same(['kot'], $pipeline->analyze('kotami', 'pl'), 'Polish conservative suffix strategy should be available');
    assert_same(['wroclaw'], $pipeline->analyze('Wrocławiu', 'pl'), 'Polish fallback should run after folding');
});

test_case('analyzer exposes language-tagged compatibility output', function (): void {
    $analyzer = new WP_FTS_Analyzer([
        'default_lang' => 'en-GB',
        'namespace_terms' => true,
    ]);

    assert_same(["en-GB\x1ecolor"], $analyzer->analyze_query('colour'), 'plain query API should remain a string-term shim');
    assert_same(
        [['term' => "en-GB\x1ecolor", 'lang' => 'en-GB']],
        $analyzer->analyze_query_terms('colour'),
        'structured query terms should include language'
    );

    $content = $analyzer->analyze_content('<p>colour</p>');
    assert_same("en-GB\x1ecolor", $content[0]['term'], 'content terms should be namespaced when requested');
    assert_same('en-GB', $content[0]['lang'], 'content occurrences should carry language');
});

test_case('analyzer carries document and element language tags', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en-US']);
    $occurrences = $analyzer->analyze_content(
        '<article><p>Hello world</p><p lang="pl">Łódź Wrocław</p><p lang=zh-Hant>中文搜索</p></article>'
    );
    $langs = test_lang_by_term($occurrences);

    assert_same('en-US', $langs['hello'], 'untagged text should use resolved document language');
    assert_same('pl', $langs['lodz'], 'quoted lang attribute should override document language');
    assert_same('pl', $langs['wroclaw'], 'nested Polish segment should keep lang');
    assert_same('zh-Hant', $langs['中文'], 'unquoted script lang should be canonicalized');
    assert_same('zh-Hant', $langs['搜索'], 'CJK bigrams should carry segment lang');

    $namespaced = $analyzer->weighted_term_frequencies($occurrences, ['namespace_terms' => true]);
    assert_true(isset($namespaced["pl\x1elodz"]), 'weighted frequencies should optionally namespace by language');
});

test_case('element language scopes end at siblings and restore parent scopes', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);

    $langs = test_lang_by_term($analyzer->analyze_content('<p lang=pl>Łódź<p>Hello'));
    assert_same('pl', $langs['lodz'], 'omitted-close paragraph should keep its own lang');
    assert_same('en', $langs['hello'], 'omitted-close sibling should return to document lang');

    $langs = test_lang_by_term($analyzer->analyze_content('<p lang=pl>Łódź</p><p>Hello</p>'));
    assert_same('en', $langs['hello'], 'explicit-close sibling should return to document lang');

    $langs = test_lang_by_term($analyzer->analyze_content(
        '<section lang=pl>Łódź <span lang=en>Hello</span> Wrocław</section>'
    ));
    assert_same('pl', $langs['lodz'], 'parent lang should apply before nested override');
    assert_same('en', $langs['hello'], 'nested lang should override parent lang');
    assert_same('pl', $langs['wroclaw'], 'parent lang should restore after nested override ends');

    $segments = test_fallback_segments($analyzer, '<ul><li lang=pl>Łódź<li>Hello</ul>', 'en');
    assert_same('pl', $segments[0]['lang'] ?? null, 'fallback optional-end list item should keep its own lang');
    assert_same('en', $segments[1]['lang'] ?? null, 'fallback optional-end sibling should not inherit previous lang');
});

test_case('query analysis exposes language-aware occurrences while preserving term shim', function (): void {
    $analyzer = new WP_FTS_Analyzer();

    assert_same(['lodz', 'cafe'], $analyzer->analyze_query('Łódź café', ['lang' => 'pl']), 'plain query shim should return terms');
    assert_same(
        [
            ['term' => 'lodz', 'lang' => 'pl'],
            ['term' => 'cafe', 'lang' => 'pl'],
        ],
        $analyzer->analyze_query_occurrences('Łódź café', ['lang' => 'pl']),
        'query occurrences should carry explicit language'
    );
    assert_same(
        [
            ['term' => 'lodz', 'lang' => 'pl'],
            ['term' => 'cafe', 'lang' => 'pl'],
        ],
        $analyzer->analyze_query('Łódź café', ['lang' => 'pl', 'return' => 'occurrences']),
        'compat method should expose occurrence format on request'
    );
});

test_case('processor extraction tracks lang without double-decoding text', function (): void {
    $fake = new WP_FTS_Fake_HTML_Processor([
        ['type' => '#tag', 'breadcrumbs' => ['HTML', 'BODY', 'P'], 'attrs' => ['lang' => 'pl']],
        ['type' => '#text', 'breadcrumbs' => ['HTML', 'BODY', 'P'], 'text' => 'Łódź &copy;'],
        ['type' => '#tag', 'breadcrumbs' => ['HTML', 'BODY', 'P']],
        ['type' => '#text', 'breadcrumbs' => ['HTML', 'BODY', 'P'], 'text' => 'Hello'],
        ['type' => '#tag', 'breadcrumbs' => ['HTML', 'BODY', 'P'], 'closing' => true],
        ['type' => '#tag', 'breadcrumbs' => ['HTML', 'BODY', 'DIV']],
        ['type' => '#text', 'breadcrumbs' => ['HTML', 'BODY', 'DIV'], 'text' => 'Plain sibling'],
        ['type' => '#tag', 'breadcrumbs' => ['HTML', 'BODY', 'SCRIPT'], 'text' => 'secret_token'],
    ]);
    $analyzer = new WP_FTS_Analyzer([
        'default_lang' => 'en',
        'html_processor_factory' => static fn(string $html): WP_FTS_Fake_HTML_Processor => $fake,
    ]);

    $occurrences = $analyzer->analyze_content('<p>unused</p>');
    $terms = test_terms($occurrences);
    $langs = test_lang_by_term($occurrences);

    assert_true(in_array('copy', $terms, true), 'processor text must not be entity-decoded a second time');
    assert_true(!in_array('secret_token', $terms, true), 'tag-token modifiable text must not be indexed');
    assert_same('pl', $langs['lodz'], 'processor lang attribute should apply to text descendants');
    assert_same('en', $langs['hello'], 'processor same-depth sibling opener should clear prior element lang');
    assert_same('en', $langs['plain'], 'closed processor lang scope must not leak to sibling tags');
});

test_case('tokenizer handles mixed script runs and CJK min length', function (): void {
    $analyzer = new WP_FTS_Analyzer(['min_term_len' => 3]);

    assert_same(['abc', '東京', 'def'], $analyzer->analyze_query('abc東京def', ['lang' => 'ja']), 'mixed Latin/CJK runs should split by script');
    assert_same(['中文', '文搜', '搜索', '日'], $analyzer->analyze_query('中文搜索 日 x', ['lang' => 'zh-Hans']), 'CJK bigrams and single chars should bypass min length');
});

test_case('analyzer tolerates invalid UTF-8 without optional extensions', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    $terms = $analyzer->analyze_query("bad\xffutf");

    assert_true($terms !== [], 'invalid UTF-8 recovery should not fatal or drop all ASCII text');
});

test_case('index and query analyzers normalize plain text identically', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    mt_srand(1234);
    $words = ['Alpha', 'BETA', 'Wrocław', 'café', 'delta_2', 'x', 'superlong'];
    for ($i = 0; $i < 100; $i++) {
        $parts = [];
        for ($j = 0; $j < 12; $j++) {
            $parts[] = $words[mt_rand(0, count($words) - 1)];
            $parts[] = [' ', '.', ',', "\n", "\t"][mt_rand(0, 4)];
        }
        $text = implode('', $parts);
        assert_same(
            $analyzer->analyze_query($text),
            test_terms($analyzer->analyze_content($text)),
            'plain content and query normalization must match'
        );
    }
});

test_case('postings varint round trips doc-id deltas and weighted tf', function (): void {
    $postings = [1 => 3, 2 => 1, 10 => 255, 1000 => 2, 1000000 => 4096];
    $encoded = WP_FTS_PostingsCodec::encode($postings);
    assert_same($postings, WP_FTS_PostingsCodec::decode($encoded), 'postings should decode to their original map');

    foreach ([0, 1, 127, 128, 255, 16384, 1048576] as $value) {
        $offset = 0;
        $encodedVarint = WP_FTS_PostingsCodec::encode_varint($value);
        assert_same($value, WP_FTS_PostingsCodec::decode_varint($encodedVarint, $offset), "varint {$value} should round trip");
        assert_same(strlen($encodedVarint), $offset, "varint {$value} should consume all bytes");
    }
});

test_case('indexed search matches brute-force oracle on fixed corpus', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    $documents = [
        1 => '<h1>Apple banana</h1><p>cafe</p>',
        2 => '<p>banana carrot carrot</p>',
        3 => '<p>durian apple</p><nav>banana</nav>',
        4 => '<strong>apple carrot</strong>',
    ];
    $storage = new WP_FTS_Storage_InMemory();
    build_index($storage, $analyzer, $documents);
    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    foreach (['apple', 'banana', 'carrot apple', 'cafe', 'missing apple'] as $query) {
        assert_search_results_equal(
            brute_force_search($documents, $analyzer, $query, 'OR'),
            $searcher->search($query, ['mode' => 'OR', 'limit' => 50]),
            "OR query {$query}"
        );
        assert_search_results_equal(
            brute_force_search($documents, $analyzer, $query, 'AND'),
            $searcher->search($query, ['mode' => 'AND', 'limit' => 50]),
            "AND query {$query}"
        );
    }
});

test_case('indexed search matches brute-force oracle on generated corpora', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    $vocabulary = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'wroclaw', 'cafe', 'lodz'];
    mt_srand(5678);

    for ($round = 0; $round < 12; $round++) {
        $documents = [];
        for ($docId = 1; $docId <= 12; $docId++) {
            $html = '';
            for ($i = 0; $i < 20; $i++) {
                $word = $vocabulary[mt_rand(0, count($vocabulary) - 1)];
                $wrapper = mt_rand(0, 7);
                if ($wrapper === 0) {
                    $html .= "<h1>{$word}</h1>";
                } elseif ($wrapper === 1) {
                    $html .= "<strong>{$word}</strong>";
                } elseif ($wrapper === 2) {
                    $html .= "<nav>{$word}</nav>";
                } elseif ($wrapper === 3) {
                    $html .= "<script>{$word}</script>";
                } else {
                    $html .= "<p>{$word}</p>";
                }
            }
            $documents[$docId] = $html;
        }

        $storage = new WP_FTS_Storage_InMemory();
        build_index($storage, $analyzer, $documents);
        $searcher = new WP_FTS_Searcher($storage, $analyzer);

        for ($q = 0; $q < 20; $q++) {
            $queryWords = [];
            for ($i = 0, $n = mt_rand(1, 3); $i < $n; $i++) {
                $queryWords[] = $vocabulary[mt_rand(0, count($vocabulary) - 1)];
            }
            $query = implode(' ', $queryWords);
            $mode = mt_rand(0, 1) === 0 ? 'OR' : 'AND';
            assert_search_results_equal(
                brute_force_search($documents, $analyzer, $query, $mode),
                $searcher->search($query, ['mode' => $mode, 'limit' => 50]),
                "generated round {$round} {$mode} query {$query}"
            );
        }
    }
});

test_case('boolean and empty query edge cases', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    $documents = [
        1 => '<p>alpha beta</p>',
        2 => '<p>alpha gamma</p>',
        3 => '<p>delta</p>',
    ];
    $storage = new WP_FTS_Storage_InMemory();
    build_index($storage, $analyzer, $documents);
    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    assert_same([], $searcher->search('x', ['mode' => 'OR']), 'single-character query is removed by min length');
    assert_same([], $searcher->search('alpha missing', ['mode' => 'AND']), 'AND with an unknown term should return no results');
    assert_true(count($searcher->search('alpha missing', ['mode' => 'OR'])) === 2, 'OR should keep docs matching known terms');
    assert_same([], $searcher->search('', ['mode' => 'OR']), 'empty query should return no results');
});

test_case('hash skip avoids unchanged rewrites', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    assert_true($indexer->index_document(10, '<p>alpha beta</p>'), 'first index should change state');
    $snapshot = storage_snapshot($storage);
    assert_true(!$indexer->index_document(10, '<p>alpha beta</p>'), 'same content should be skipped');
    assert_same($snapshot, storage_snapshot($storage), 'unchanged document should not rewrite storage');
});

test_case('file backend persists and matches in-memory backend', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    $documents = [
        1 => '<h1>alpha beta</h1>',
        2 => '<p>beta gamma</p>',
        3 => '<p>gamma delta</p><footer>alpha</footer>',
    ];

    $memory = new WP_FTS_Storage_InMemory();
    build_index($memory, $analyzer, $documents);
    $memorySearcher = new WP_FTS_Searcher($memory, $analyzer);

    $path = temp_index_path('backend');
    $file = new WP_FTS_Storage_File($path);
    build_index($file, $analyzer, $documents);
    $file->flush();
    $reloadedFile = new WP_FTS_Storage_File($path);
    $fileSearcher = new WP_FTS_Searcher($reloadedFile, $analyzer);

    foreach (['alpha', 'beta gamma', 'delta alpha'] as $query) {
        assert_search_results_equal(
            $memorySearcher->search($query, ['mode' => 'OR', 'limit' => 50]),
            $fileSearcher->search($query, ['mode' => 'OR', 'limit' => 50]),
            "file backend query {$query}"
        );
    }

    if (is_file($path)) {
        unlink($path);
    }
});

test_case('incremental and full rebuild converge for in-memory and file backends', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    $finalDocuments = [
        1 => '<p>alpha delta</p>',
        2 => '<p>gamma delta</p>',
        4 => '<h2>epsilon alpha</h2>',
    ];

    $factories = [
        'memory' => static fn(): WP_FTS_Storage => new WP_FTS_Storage_InMemory(),
        'file' => static fn(): WP_FTS_Storage => new WP_FTS_Storage_File(temp_index_path('converge')),
    ];

    foreach ($factories as $name => $factory) {
        $incremental = $factory();
        $indexer = new WP_FTS_Indexer($incremental, $analyzer);
        $indexer->index_document(1, '<p>alpha beta</p>');
        $indexer->index_document(2, '<p>beta gamma</p>');
        $indexer->index_document(3, '<p>stale only</p>');
        $indexer->index_document(1, $finalDocuments[1]);
        $indexer->delete_document(2);
        $indexer->index_document(2, $finalDocuments[2]);
        $indexer->delete_document(3);
        $indexer->index_document(4, $finalDocuments[4]);
        $indexer->optimize();

        $full = $factory();
        build_index($full, $analyzer, $finalDocuments)->optimize();

        assert_same(storage_snapshot($full), storage_snapshot($incremental), "{$name} incremental state should match full rebuild");

        $incSearcher = new WP_FTS_Searcher($incremental, $analyzer);
        $fullSearcher = new WP_FTS_Searcher($full, $analyzer);
        foreach (['alpha', 'beta', 'delta gamma', 'epsilon alpha'] as $query) {
            assert_search_results_equal(
                $fullSearcher->search($query, ['mode' => 'OR', 'limit' => 50]),
                $incSearcher->search($query, ['mode' => 'OR', 'limit' => 50]),
                "{$name} convergence query {$query}"
            );
        }

        foreach ([$incremental, $full] as $storage) {
            if ($storage instanceof WP_FTS_Storage_File) {
                $ref = new ReflectionClass($storage);
                $prop = $ref->getProperty('path');
                $prop->setAccessible(true);
                $path = (string) $prop->getValue($storage);
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
});

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
if ($failures > 0) {
    fwrite(STDERR, "{$failures}/{$count} tests failed in {$duration}s\n");
    exit(1);
}

fwrite(STDOUT, "{$count}/{$count} tests passed in {$duration}s\n");
