<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

final class WP_FTS_TestFailure extends RuntimeException
{
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

function assert_contains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new WP_FTS_TestFailure($message . "\nMissing: " . var_export($needle, true) . "\nIn: " . $haystack);
    }
}

/**
 * @return string[]
 */
function test_terms(array $occurrences): array
{
    return array_map(static fn(array $token): string => $token['term'], $occurrences);
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

final class WP_FTS_Test_Prepared_SQL
{
    /**
     * @param array<int,mixed> $args
     */
    public function __construct(
        public string $sql,
        public array $args,
    ) {
    }

    public function __toString(): string
    {
        return $this->sql;
    }
}

final class WP_FTS_Test_WPDB
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';

    /** @var array<int,string> */
    public array $queries = [];

    /** @var array<int,array{sql:string,args:array<int,mixed>}> */
    public array $prepared = [];

    /** @var array<string,array{doc_freq:int,postings:string}> */
    public array $terms = [];

    /** @var array<int,array{lang:string,doc_len:int,content_hash:?string,is_deleted:int}> */
    public array $docs = [];

    /** @var array<int,array<string,int>> */
    public array $docLengths = [];

    /** @var array<string,array<string,int>> */
    public array $meta = [];

    /** @var array<int,object> */
    public array $postRows = [];

    public function prepare(string $sql, mixed ...$args): WP_FTS_Test_Prepared_SQL
    {
        $this->prepared[] = ['sql' => $sql, 'args' => $args];

        return new WP_FTS_Test_Prepared_SQL($sql, $args);
    }

    public function query(mixed $statement): int|bool
    {
        [$sql, $args] = $this->statement_parts($statement);
        $this->queries[] = $sql;

        if (str_starts_with($sql, 'CREATE TABLE') || in_array($sql, ['START TRANSACTION', 'COMMIT', 'ROLLBACK'], true)) {
            return true;
        }

        if (str_starts_with($sql, 'INSERT INTO wp_fts_terms')) {
            $term = (string) $args[0];
            $this->terms[$term] = [
                'doc_freq' => (int) $args[1],
                'postings' => (string) $args[2],
            ];
            ksort($this->terms, SORT_STRING);
            return 1;
        }

        if (str_starts_with($sql, 'DELETE FROM wp_fts_terms WHERE term = %s')) {
            unset($this->terms[(string) $args[0]]);
            return 1;
        }

        if (str_starts_with($sql, 'INSERT INTO wp_fts_docs') && count($args) === 4) {
            $docId = (int) $args[0];
            $this->docs[$docId] = [
                'lang' => (string) $args[1],
                'doc_len' => (int) $args[2],
                'content_hash' => (string) $args[3],
                'is_deleted' => 0,
            ];
            ksort($this->docs, SORT_NUMERIC);
            return 1;
        }

        if (str_starts_with($sql, 'INSERT INTO wp_fts_docs') && count($args) === 2) {
            $docId = (int) $args[0];
            $this->docs[$docId] ??= [
                'lang' => (string) $args[1],
                'doc_len' => 0,
                'content_hash' => null,
                'is_deleted' => 0,
            ];
            $this->docs[$docId]['is_deleted'] = 1;
            return 1;
        }

        if (str_starts_with($sql, 'DELETE FROM wp_fts_doc_lengths WHERE doc_id = %d')) {
            unset($this->docLengths[(int) $args[0]]);
            return 1;
        }

        if (str_starts_with($sql, 'DELETE FROM wp_fts_doc_lengths WHERE doc_id IN')) {
            foreach ($args as $docId) {
                unset($this->docLengths[(int) $docId]);
            }
            return 1;
        }

        if (str_starts_with($sql, 'INSERT INTO wp_fts_doc_lengths')) {
            $docId = (int) $args[0];
            $this->docLengths[$docId][(string) $args[1]] = (int) $args[2];
            ksort($this->docLengths[$docId], SORT_STRING);
            return 1;
        }

        if (str_starts_with($sql, 'INSERT INTO wp_fts_meta')) {
            $lang = (string) $args[0];
            $key = (string) $args[1];
            $delta = (int) $args[3];
            $this->meta[$lang][$key] = max(0, ($this->meta[$lang][$key] ?? 0) + $delta);
            ksort($this->meta[$lang], SORT_STRING);
            ksort($this->meta, SORT_STRING);
            return 1;
        }

        if ($sql === 'DELETE FROM wp_fts_meta') {
            $this->meta = [];
            return 1;
        }

        if (str_starts_with($sql, 'DELETE FROM wp_fts_docs WHERE doc_id IN')) {
            foreach ($args as $docId) {
                unset($this->docs[(int) $docId]);
            }
            return 1;
        }

        return true;
    }

    /**
     * @return object[]
     */
    public function get_results(mixed $statement): array
    {
        [$sql, $args] = $this->statement_parts($statement);

        if (str_starts_with($sql, 'SELECT term, doc_freq, postings FROM wp_fts_terms')) {
            $rows = [];
            foreach ($args as $term) {
                $term = (string) $term;
                if (isset($this->terms[$term])) {
                    $rows[] = (object) [
                        'term' => $term,
                        'doc_freq' => $this->terms[$term]['doc_freq'],
                        'postings' => $this->terms[$term]['postings'],
                    ];
                }
            }
            return $rows;
        }

        if (str_starts_with($sql, 'SELECT dl.doc_id, dl.doc_len FROM wp_fts_doc_lengths')) {
            $lang = (string) $args[0];
            $ids = array_map('intval', array_slice($args, 1));
            $rows = [];
            foreach ($ids as $docId) {
                if (($this->docs[$docId]['is_deleted'] ?? 1) === 0 && isset($this->docLengths[$docId][$lang])) {
                    $rows[] = (object) ['doc_id' => $docId, 'doc_len' => $this->docLengths[$docId][$lang]];
                }
            }
            return $rows;
        }

        if (str_starts_with($sql, 'SELECT doc_id, doc_len FROM wp_fts_docs')) {
            $rows = [];
            foreach (array_map('intval', $args) as $docId) {
                if (($this->docs[$docId]['is_deleted'] ?? 1) === 0) {
                    $rows[] = (object) ['doc_id' => $docId, 'doc_len' => $this->docs[$docId]['doc_len']];
                }
            }
            return $rows;
        }

        if (str_starts_with($sql, 'SELECT lang, doc_len FROM wp_fts_doc_lengths')) {
            $rows = [];
            foreach ($this->docLengths[(int) $args[0]] ?? [] as $lang => $docLen) {
                $rows[] = (object) ['lang' => $lang, 'doc_len' => $docLen];
            }
            return $rows;
        }

        if (str_starts_with($sql, 'SELECT k, v FROM wp_fts_meta WHERE lang = %s')) {
            $rows = [];
            foreach ($this->meta[(string) $args[0]] ?? [] as $key => $value) {
                $rows[] = (object) ['k' => $key, 'v' => $value];
            }
            return $rows;
        }

        if (str_starts_with($sql, 'SELECT k, COALESCE(SUM(v), 0) AS v FROM wp_fts_meta')) {
            $aggregate = [];
            foreach ($this->meta as $row) {
                foreach ($row as $key => $value) {
                    $aggregate[$key] = ($aggregate[$key] ?? 0) + $value;
                }
            }
            $rows = [];
            foreach ($aggregate as $key => $value) {
                $rows[] = (object) ['k' => $key, 'v' => $value];
            }
            return $rows;
        }

        if (str_starts_with($sql, 'SELECT ID, post_content, post_title')) {
            $last = (int) $args[count($args) - 2];
            $limit = (int) $args[count($args) - 1];
            $rows = array_values(array_filter(
                $this->postRows,
                static fn(object $row): bool => (int) $row->ID > $last
            ));
            usort($rows, static fn(object $a, object $b): int => (int) $a->ID <=> (int) $b->ID);
            return array_slice($rows, 0, $limit);
        }

        if (str_starts_with($sql, 'SELECT dl.lang, COUNT(*) AS doc_count')) {
            $aggregate = [];
            foreach ($this->docLengths as $docId => $lengths) {
                if (($this->docs[$docId]['is_deleted'] ?? 1) !== 0) {
                    continue;
                }
                foreach ($lengths as $lang => $docLen) {
                    if ($docLen <= 0) {
                        continue;
                    }
                    $aggregate[$lang] ??= ['doc_count' => 0, 'len_sum' => 0];
                    $aggregate[$lang]['doc_count']++;
                    $aggregate[$lang]['len_sum'] += $docLen;
                }
            }

            $rows = [];
            foreach ($aggregate as $lang => $row) {
                $rows[] = (object) ['lang' => $lang, 'doc_count' => $row['doc_count'], 'len_sum' => $row['len_sum']];
            }
            return $rows;
        }

        return [];
    }

    public function get_row(mixed $statement): ?object
    {
        [$sql, $args] = $this->statement_parts($statement);
        if (str_starts_with($sql, 'SELECT doc_id, lang, doc_len, content_hash, is_deleted FROM wp_fts_docs')) {
            $docId = (int) $args[0];
            if (!isset($this->docs[$docId])) {
                return null;
            }

            return (object) array_merge(['doc_id' => $docId], $this->docs[$docId]);
        }

        return null;
    }

    /**
     * @return array<int,int|string>
     */
    public function get_col(string $sql): array
    {
        if (str_starts_with($sql, 'SELECT term FROM wp_fts_terms')) {
            return array_keys($this->terms);
        }

        if (str_starts_with($sql, 'SELECT doc_id FROM wp_fts_docs WHERE is_deleted = 1')) {
            return array_keys(array_filter(
                $this->docs,
                static fn(array $doc): bool => $doc['is_deleted'] === 1
            ));
        }

        if (str_starts_with($sql, 'SELECT doc_id FROM wp_fts_docs')) {
            $includeDeleted = !str_contains($sql, 'WHERE is_deleted = 0');
            return array_keys(array_filter(
                $this->docs,
                static fn(array $doc): bool => $includeDeleted || $doc['is_deleted'] === 0
            ));
        }

        return [];
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private function statement_parts(mixed $statement): array
    {
        if ($statement instanceof WP_FTS_Test_Prepared_SQL) {
            return [$statement->sql, $statement->args];
        }

        return [(string) $statement, []];
    }
}

if (!class_exists('WP_CLI')) {
    final class WP_CLI
    {
        /** @var string[] */
        public static array $successMessages = [];

        public static function add_command(string $name, string $class): void
        {
        }

        public static function success(string $message): void
        {
            self::$successMessages[] = $message;
        }

        public static function warning(string $message): void
        {
        }
    }
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

test_case('language partitions isolate same normalized terms', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(1, '<p>zamek wspolny</p>', ['lang' => 'pl_PL']);
    $indexer->index_document(2, '<p>zamek wspolny</p>', ['lang' => 'en-US']);
    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    assert_same(1, $storage->get_meta('pl-PL')['doc_count'], 'Polish partition should count one doc');
    assert_same(1, $storage->get_meta('en-US')['doc_count'], 'English partition should count one doc');
    assert_same(2, $storage->get_meta()['doc_count'], 'global meta should aggregate language partitions');
    assert_same([1 => 2], $storage->get_doc_lengths([1, 2], 'pl-PL'), 'Polish lengths should exclude English doc');
    assert_same([2 => 2], $storage->get_doc_lengths([1, 2], 'en-US'), 'English lengths should exclude Polish doc');
    $polishResults = $searcher->search('zamek', ['lang' => 'pl-PL']);
    assert_same(1, count($polishResults), 'Polish query should return one doc');
    assert_same(1, $polishResults[0]['doc_id'], 'Polish query should return only Polish doc');
    assert_same(2, $searcher->search('zamek', ['lang' => 'en-US'])[0]['doc_id'], 'English query should return only English doc');
    assert_same([], $searcher->search('zamek', ['lang' => 'de']), 'unpopulated language partition should not match');

    assert_true($indexer->index_document(1, '<p>zamek wspolny</p>', ['lang' => 'en-US']), 'changing only document language should rewrite the index');
    assert_same([], $searcher->search('zamek', ['lang' => 'pl-PL']), 'old language postings should be removed after language change');
    assert_same([1, 2], array_column($searcher->search('zamek', ['lang' => 'en-US', 'limit' => 10]), 'doc_id'), 'new language should contain both English docs');
});

test_case('mysql storage emits language-aware binary schema and stores per-language docs', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $storage = new WP_FTS_Storage_Mysql($wpdb);
    $storage->create_tables();

    assert_same(4, count(array_filter($wpdb->queries, static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE'))), 'schema should create four tables');
    $schemaSql = implode("\n", $wpdb->queries);
    assert_contains('term varbinary(255) NOT NULL', $schemaSql, 'terms table should use exact binary term keys');
    assert_contains('CREATE TABLE wp_fts_doc_lengths', $schemaSql, 'schema should include doc-language lengths table');
    assert_contains('PRIMARY KEY  (doc_id,lang)', $schemaSql, 'doc lengths should be keyed by doc and language');
    assert_contains('PRIMARY KEY  (lang,k)', $schemaSql, 'meta should be keyed by language and key');
    assert_true(!str_contains(strtolower($schemaSql), 'fulltext'), 'schema must not use MySQL FULLTEXT');

    $plTerm = WP_FTS_Language::term_key('zamek', 'pl');
    $enTerm = WP_FTS_Language::term_key('zamek', 'en');
    assert_true($plTerm !== $enTerm && str_contains($plTerm, WP_FTS_Language::TERM_SEPARATOR), 'term keys should be language namespaced');
    $storage->put_term($plTerm, 1, WP_FTS_PostingsCodec::encode([7 => 2]));
    $storage->put_term($enTerm, 1, WP_FTS_PostingsCodec::encode([8 => 1]));
    assert_same([$enTerm, $plTerm], $storage->all_terms(), 'binary namespaced terms should remain separate rows');

    $storage->put_doc(7, 'pl_PL', ['pl_PL' => 4, 'en' => 2], 'abc123');
    $doc = $storage->get_doc(7);
    assert_same('pl-PL', $doc['primary_lang'], 'document primary language should be canonicalized');
    assert_same(['en' => 2, 'pl-PL' => 4], $doc['lang_lengths'], 'document should keep per-language lengths');
    assert_same([7 => 4], $storage->get_doc_lengths([7], 'pl_PL'), 'language length lookup should use doc-length table');
    assert_same([7 => 2], $storage->get_doc_lengths([7], 'en'), 'secondary language length should be queryable');

    $storage->add_meta('pl_PL', 1, 4);
    $storage->add_meta('en', 1, 2);
    assert_same(['doc_count' => 1, 'len_sum' => 4], $storage->get_meta('pl-PL'), 'language meta should be partitioned');
    assert_same(['doc_count' => 2, 'len_sum' => 6], $storage->get_meta(), 'global meta should aggregate partitions');
});

test_case('wp cli reindex accepts language source filters and limit', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->postRows = [
        (object) ['ID' => 10, 'post_title' => 'Pierwszy', 'post_content' => '<p>zamek alfa</p>'],
        (object) ['ID' => 11, 'post_title' => 'Drugi', 'post_content' => '<p>zamek beta</p>'],
    ];
    $wpdb = $fake;
    WP_CLI::$successMessages = [];

    try {
        $command = new WP_FTS_WPCLI_Command();
        $command->reindex([], [
            'post_status' => 'publish,draft',
            'post_type' => 'post,page',
            'lang' => 'pl_PL',
            'limit' => '1',
            'batch_size' => '25',
        ]);
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(['Indexed 1 posts in pl-PL.'], WP_CLI::$successMessages, 'CLI should report canonical language and limited count');
    assert_same([10], array_keys($fake->docs), 'CLI limit should restrict indexed posts');
    assert_same('pl-PL', $fake->docs[10]['lang'], 'CLI language option should reach MySQL docs');
    assert_same(['pl-PL' => 7], $fake->docLengths[10], 'CLI reindex should write boosted per-language doc length');

    $postSelect = null;
    foreach ($fake->prepared as $prepared) {
        if (str_starts_with($prepared['sql'], 'SELECT ID, post_content, post_title')) {
            $postSelect = $prepared;
            break;
        }
    }
    assert_true($postSelect !== null, 'CLI reindex should prepare a batched posts query');
    assert_same(['publish', 'draft', 'post', 'page', 0, 1], $postSelect['args'], 'CLI source filters and remaining limit should be prepared');
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
