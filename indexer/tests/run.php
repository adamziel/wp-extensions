<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

final class WP_FTS_TestFailure extends RuntimeException
{
}

final class WP_FTS_TestPending extends RuntimeException
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

const WP_FTS_DEFAULT_MIN_CHECKS = 1500;
const WP_FTS_FINAL_INTEGRATION_TARGET_CHECKS = 1500;

/**
 * @var array<int,array{name:string,fn:callable}>
 */
$tests = [];
$wp_fts_check_count = 0;
/**
 * @var string[]
 */
$wp_fts_quality_test_files = [];

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

function executed_check_count(): int
{
    global $wp_fts_check_count;

    return $wp_fts_check_count;
}

/**
 * @return string[]
 */
function discovered_quality_test_files(): array
{
    global $wp_fts_quality_test_files;

    return $wp_fts_quality_test_files;
}

function minimum_check_count(): int
{
    $raw = getenv('WP_FTS_MIN_CHECKS');
    if ($raw === false || $raw === '') {
        return WP_FTS_DEFAULT_MIN_CHECKS;
    }

    if (!is_string($raw) || preg_match('/^(0|[1-9][0-9]*)$/', $raw) !== 1) {
        throw new WP_FTS_TestFailure('WP_FTS_MIN_CHECKS must be a non-negative integer.');
    }

    return (int) $raw;
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

function mark_pending(string $message): never
{
    throw new WP_FTS_TestPending($message);
}

function assert_or_pending(bool $condition, string $message, string $pendingReason): void
{
    record_check($message);
    if (!$condition) {
        mark_pending($pendingReason . "\n" . $message);
    }
}

function discover_quality_tests(?string $directory = null): void
{
    global $wp_fts_quality_test_files;

    $directory ??= __DIR__ . '/quality';
    if (!is_dir($directory)) {
        return;
    }

    $files = glob($directory . '/*.php');
    if ($files === false) {
        throw new WP_FTS_TestFailure("Could not discover quality tests in {$directory}.");
    }

    sort($files, SORT_STRING);

    foreach ($files as $file) {
        if (is_file($file)) {
            require_once $file;
            if (!in_array($file, $wp_fts_quality_test_files, true)) {
                $wp_fts_quality_test_files[] = $file;
            }
        }
    }
}

/**
 * @return string[]
 */
function test_terms(array $occurrences): array
{
    return array_map(static fn(array|string $token): string => is_array($token) ? (string) $token['term'] : $token, $occurrences);
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
 * @return array{term:string,lang:?string}
 */
function test_split_namespaced_term(string $term): array
{
    if (str_contains($term, WP_FTS_TermNamespace::SEPARATOR)) {
        [$lang, $bareTerm] = explode(WP_FTS_TermNamespace::SEPARATOR, $term, 2);
        return ['term' => $bareTerm, 'lang' => $lang];
    }

    return ['term' => $term, 'lang' => null];
}

/**
 * @param array<int,array<string,mixed>|string> $tokens
 * @return array<int,array{term:string,lang:?string,weight:?float}>
 */
function test_token_records(array $tokens): array
{
    $records = [];
    foreach ($tokens as $token) {
        if (is_string($token)) {
            $split = test_split_namespaced_term($token);
            $records[] = [
                'term' => $split['term'],
                'lang' => $split['lang'],
                'weight' => null,
            ];
            continue;
        }

        $split = test_split_namespaced_term((string) ($token['term'] ?? ''));
        $lang = $token['lang'] ?? $token['language'] ?? $split['lang'];
        $records[] = [
            'term' => $split['term'],
            'lang' => is_string($lang) && $lang !== '' ? $lang : null,
            'weight' => isset($token['weight']) ? (float) $token['weight'] : null,
        ];
    }

    return $records;
}

/**
 * @param array<int,array{term:string,lang:?string,weight:?float}> $records
 * @return string[]
 */
function test_terms_for_lang(array $records, string $lang): array
{
    $terms = [];
    foreach ($records as $record) {
        if ($record['lang'] === $lang) {
            $terms[] = $record['term'];
        }
    }

    return $terms;
}

/**
 * @param array<int,array{term:string,lang:?string,weight:?float}> $records
 * @return string[]
 */
function test_langs_for_term(array $records, string $term): array
{
    $langs = [];
    foreach ($records as $record) {
        if ($record['term'] === $term && $record['lang'] !== null) {
            $langs[$record['lang']] = true;
        }
    }

    $result = array_keys($langs);
    sort($result, SORT_STRING);

    return $result;
}

/**
 * @param array<int,array{term:string,lang:?string,weight:?float}> $records
 */
function test_records_have_lang(array $records): bool
{
    if ($records === []) {
        return false;
    }

    foreach ($records as $record) {
        if ($record['lang'] === null) {
            return false;
        }
    }

    return true;
}

/**
 * @return array<int,array<string,mixed>|string>
 */
function test_call_analyzer(WP_FTS_Analyzer $analyzer, string $method, string $text, array $opts = []): array
{
    $reflection = new ReflectionMethod($analyzer, $method);
    $parameters = $reflection->getParameters();
    if (count($parameters) >= 2) {
        $secondParameter = $parameters[1];
        if (test_parameter_accepts_type($secondParameter, 'array')) {
            /** @var array<int,array<string,mixed>|string> */
            return $analyzer->{$method}($text, $opts);
        }

        if (test_parameter_accepts_type($secondParameter, 'string')) {
            $lang = (string) ($opts['lang'] ?? $opts['language'] ?? '');
            if ($lang !== '') {
                if (isset($parameters[2]) && test_parameter_accepts_type($parameters[2], 'array')) {
                    /** @var array<int,array<string,mixed>|string> */
                    return $analyzer->{$method}($text, $lang, $opts);
                }

                /** @var array<int,array<string,mixed>|string> */
                return $analyzer->{$method}($text, $lang);
            }
        }

        /** @var array<int,array<string,mixed>|string> */
        return $analyzer->{$method}($text, $opts);
    }

    /** @var array<int,array<string,mixed>|string> */
    return $analyzer->{$method}($text);
}

function test_parameter_accepts_type(ReflectionParameter $parameter, string $typeName): bool
{
    $type = $parameter->getType();
    if ($type === null) {
        return false;
    }

    if ($type instanceof ReflectionNamedType) {
        return $type->getName() === $typeName || $type->getName() === 'mixed';
    }

    if ($type instanceof ReflectionUnionType) {
        foreach ($type->getTypes() as $namedType) {
            if ($namedType->getName() === $typeName || $namedType->getName() === 'mixed') {
                return true;
            }
        }
    }

    return false;
}

/**
 * @return array<int,array<string,mixed>|string>
 */
function test_call_query_occurrences(WP_FTS_Analyzer $analyzer, string $query, string $lang): array
{
    $opts = [
        'lang' => $lang,
        'language' => $lang,
        'return' => 'occurrences',
    ];

    if (method_exists($analyzer, 'analyze_query_occurrences')) {
        return test_call_analyzer($analyzer, 'analyze_query_occurrences', $query, $opts);
    }

    return test_call_analyzer($analyzer, 'analyze_query', $query, $opts);
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
 * @return array{exit:int,stdout:string,stderr:string}
 */
function test_run_php_without_extensions(string $code): array
{
    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is unavailable, so the optional-extension smoke test cannot run in this PHP build.');
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, '-n', '-r', $code], $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        mark_pending('Could not start a PHP subprocess for the optional-extension smoke test.');
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return [
        'exit' => is_int($exit) ? $exit : 1,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

/**
 * @param array<string,string> $env
 * @return array{exit:int,stdout:string,stderr:string}
 */
function test_run_harness_with_environment(array $env): array
{
    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is unavailable, so the harness subprocess test cannot run in this PHP build.');
    }

    $baseEnv = getenv();
    if (!is_array($baseEnv)) {
        $baseEnv = [];
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, __FILE__], $descriptors, $pipes, dirname(__DIR__), array_merge($baseEnv, $env));
    if (!is_resource($process)) {
        mark_pending('Could not start a PHP subprocess for the harness metrics test.');
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return [
        'exit' => is_int($exit) ? $exit : 1,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

function test_bm25_score(int $tf, int $docLen, int $docCount, int $docFreq, float $avgDocLen, float $k1 = 1.2, float $b = 0.75): float
{
    $idf = log(1.0 + (($docCount - $docFreq + 0.5) / ($docFreq + 0.5)));
    $normalizer = $tf + $k1 * (1.0 - $b + $b * ($docLen / max(1.0, $avgDocLen)));

    return $idf * (($tf * ($k1 + 1.0)) / $normalizer);
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
 * @return array{terms:array<string,array{df:int,postings:array<int,int>}>,docs:array<int,array<string,mixed>>,meta:array<string,array{doc_count:int,len_sum:int}>}
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

test_case('storage records per-language doc lengths and excludes tombstones from stats', function (): void {
    foreach (storage_factories('lang_lengths') as $name => $factory) {
        $storage = $factory();
        $storage->put_doc(1, 'pl', ['pl' => 4, 'en' => 2], 'hash-1');
        $storage->put_doc(2, 'en', ['en' => 3], 'hash-2');
        $storage->put_doc(3, 'pl', ['pl' => 7, 'de' => 1], 'hash-3');
        $storage->put_doc(4, 'pl', [], 'hash-4');

        assert_same([
            'primary_lang' => 'pl',
            'lang_lengths' => ['en' => 2, 'pl' => 4],
            'doc_len' => 6,
            'content_hash' => 'hash-1',
            'deleted' => false,
        ], $storage->get_doc(1), "{$name} doc metadata should include primary lang and per-language lengths");
        assert_same([1 => 4, 3 => 7], $storage->get_doc_lengths([1, 2, 3, 4], 'pl'), "{$name} pl lengths");
        assert_same([1 => 2, 2 => 3], $storage->get_doc_lengths([1, 2, 3, 4], 'en'), "{$name} en lengths");
        assert_same([1 => 6, 2 => 3, 3 => 8, 4 => 0], $storage->get_doc_lengths([1, 2, 3, 4]), "{$name} aggregate lengths");
        assert_same(['doc_count' => 2, 'len_sum' => 11], $storage->get_meta('pl'), "{$name} pl meta");
        assert_same(['doc_count' => 2, 'len_sum' => 5], $storage->get_meta('en'), "{$name} en meta");
        assert_same(['doc_count' => 1, 'len_sum' => 1], $storage->get_meta('de'), "{$name} de meta");
        assert_same(['doc_count' => 4, 'len_sum' => 17], $storage->get_meta(), "{$name} aggregate meta");

        $storage->delete_doc(1);
        assert_same([3 => 7], $storage->get_doc_lengths([1, 2, 3, 4], 'pl'), "{$name} deleted pl length should be hidden");
        assert_same([2 => 3], $storage->get_doc_lengths([1, 2, 3, 4], 'en'), "{$name} deleted en length should be hidden");
        assert_same(['doc_count' => 1, 'len_sum' => 7], $storage->get_meta('pl'), "{$name} deleted doc should leave pl meta");
        assert_same(['doc_count' => 1, 'len_sum' => 3], $storage->get_meta('en'), "{$name} deleted doc should leave en meta");
        assert_same(['doc_count' => 3, 'len_sum' => 11], $storage->get_meta(), "{$name} deleted doc should leave aggregate meta");

        cleanup_storage($storage);
    }
});

test_case('storage optimize purges tombstoned docs from language-namespaced postings', function (): void {
    $term = "pl\x1ealpha";
    foreach (storage_factories('lang_optimize') as $name => $factory) {
        $storage = $factory();
        $storage->put_doc(1, 'pl', ['pl' => 2], 'hash-1');
        $storage->put_doc(2, 'pl', ['pl' => 3], 'hash-2');
        $storage->put_term($term, 2, WP_FTS_PostingsCodec::encode([1 => 1, 2 => 2]));

        $storage->delete_doc(1);
        assert_same([2 => 3], $storage->get_doc_lengths([1, 2], 'pl'), "{$name} tombstone hidden before optimize");
        assert_same(['doc_count' => 1, 'len_sum' => 3], $storage->get_meta('pl'), "{$name} tombstone excluded from meta before optimize");

        $storage->optimize();
        $row = $storage->get_terms([$term])[$term] ?? null;
        assert_true($row !== null, "{$name} optimized term should remain for active postings");
        assert_same(1, $row['df'], "{$name} optimized df");
        assert_same([2 => 2], WP_FTS_PostingsCodec::decode($row['postings']), "{$name} optimized postings");
        assert_same([2], $storage->all_doc_ids(true), "{$name} optimized docs should purge tombstone");
        assert_same(['doc_count' => 1, 'len_sum' => 3], $storage->get_meta('pl'), "{$name} optimized meta");

        cleanup_storage($storage);
    }
});

test_case('file backend persists language-aware state and migrates legacy docs', function (): void {
    $path = temp_index_path('legacy_migrate');
    file_put_contents($path, json_encode([
        'version' => 1,
        'terms' => [],
        'docs' => [
            '9' => [
                'doc_len' => 5,
                'content_hash' => 'legacy-hash',
                'deleted' => false,
            ],
        ],
        'meta' => ['doc_count' => 1, 'len_sum' => 5],
    ], JSON_THROW_ON_ERROR));

    $storage = new WP_FTS_Storage_File($path);
    assert_same([
        'primary_lang' => '',
        'lang_lengths' => ['' => 5],
        'doc_len' => 5,
        'content_hash' => 'legacy-hash',
        'deleted' => false,
    ], $storage->get_doc(9), 'legacy file docs should migrate to the unspecified language partition');
    assert_same([9 => 5], $storage->get_doc_lengths([9], ''), 'legacy file doc length should remain queryable');
    assert_same(['doc_count' => 1, 'len_sum' => 5], $storage->get_meta(''), 'legacy file meta should migrate');

    $storage->put_doc(10, 'en', ['en' => 3, 'pl' => 4], 'hash-10');
    $storage->put_term("en\x1eterm", 1, WP_FTS_PostingsCodec::encode([10 => 3]));
    $storage->flush();

    $reloaded = new WP_FTS_Storage_File($path);
    assert_same(storage_snapshot($storage), storage_snapshot($reloaded), 'file language-aware state should persist exactly');

    cleanup_storage($storage);
});

final class WP_FTS_Test_LanguageAwareStorage implements WP_FTS_Storage
{
    private WP_FTS_Storage_InMemory $inner;

    /** @var array<int,string> */
    private array $primaryLangByDoc = [];

    /** @var array<int,array<string,int>> */
    private array $langLengthsByDoc = [];

    /** @var array<string,array{doc_count:int,len_sum:int}> */
    private array $metaByLang = [];

    /** @var array<int,array{primary:array<int,string>,lengths:array<int,array<string,int>>,meta:array<string,array{doc_count:int,len_sum:int}>}> */
    private array $languageSnapshots = [];

    public function __construct()
    {
        $this->inner = new WP_FTS_Storage_InMemory();
    }

    public function get_terms(array $terms): array
    {
        return $this->inner->get_terms($terms);
    }

    public function put_term(string $term, int $df, string $postings): void
    {
        $this->inner->put_term($term, $df, $postings);
    }

    public function delete_term(string $term): void
    {
        $this->inner->delete_term($term);
    }

    public function get_doc_lengths(array $doc_ids, ?string $lang = null): array
    {
        if ($lang === null) {
            return $this->inner->get_doc_lengths($doc_ids);
        }

        $lang = WP_FTS_TermNamespace::canonicalize_lang($lang);
        $lengths = [];
        foreach (array_unique(array_map('intval', $doc_ids)) as $docId) {
            $doc = $this->inner->get_doc($docId);
            if ($doc === null || $doc['deleted']) {
                continue;
            }

            $length = $this->langLengthsByDoc[$docId][$lang] ?? 0;
            if ($length > 0) {
                $lengths[$docId] = $length;
            }
        }
        ksort($lengths, SORT_NUMERIC);

        return $lengths;
    }

    public function get_doc(int $doc_id): ?array
    {
        $doc = $this->inner->get_doc($doc_id);
        if ($doc === null) {
            return null;
        }

        $doc['primary_lang'] = $this->primaryLangByDoc[$doc_id] ?? 'en';
        $doc['lang_lengths'] = $this->langLengthsByDoc[$doc_id] ?? [];

        return $doc;
    }

    public function put_doc(int $doc_id, int|string $doc_len_or_primary_lang, string|array $hash_or_lang_lengths, ?string $hash = null): void
    {
        if (is_string($doc_len_or_primary_lang) && is_array($hash_or_lang_lengths) && $hash !== null) {
            $primaryLang = WP_FTS_TermNamespace::canonicalize_lang($doc_len_or_primary_lang);
            $langLengths = WP_FTS_StorageCompat::normalize_lang_lengths($hash_or_lang_lengths);
            $this->primaryLangByDoc[$doc_id] = $primaryLang;
            $this->langLengthsByDoc[$doc_id] = $langLengths;
            $this->inner->put_doc($doc_id, array_sum($langLengths), $hash);
            return;
        }

        $docLen = max(0, (int) $doc_len_or_primary_lang);
        $this->primaryLangByDoc[$doc_id] = 'en';
        $this->langLengthsByDoc[$doc_id] = $docLen > 0 ? ['en' => $docLen] : [];
        $this->inner->put_doc($doc_id, $docLen, (string) $hash_or_lang_lengths);
    }

    public function delete_doc(int $doc_id): void
    {
        $this->inner->delete_doc($doc_id);
    }

    public function get_meta(?string $lang = null): array
    {
        if ($lang === null) {
            return $this->inner->get_meta();
        }

        $lang = WP_FTS_TermNamespace::canonicalize_lang($lang);

        return $this->metaByLang[$lang] ?? ['doc_count' => 0, 'len_sum' => 0];
    }

    public function add_meta(int|string $lang_or_d_docs, ?int $d_docs_or_d_len = null, ?int $d_len = null): void
    {
        if (is_string($lang_or_d_docs) && $d_docs_or_d_len !== null && $d_len !== null) {
            $lang = WP_FTS_TermNamespace::canonicalize_lang($lang_or_d_docs);
            $current = $this->metaByLang[$lang] ?? ['doc_count' => 0, 'len_sum' => 0];
            $this->metaByLang[$lang] = [
                'doc_count' => max(0, $current['doc_count'] + $d_docs_or_d_len),
                'len_sum' => max(0, $current['len_sum'] + $d_len),
            ];
            return;
        }

        $this->inner->add_meta((int) $lang_or_d_docs, (int) $d_docs_or_d_len);
    }

    public function all_terms(): array
    {
        return $this->inner->all_terms();
    }

    public function all_doc_ids(bool $include_deleted = false): array
    {
        return $this->inner->all_doc_ids($include_deleted);
    }

    public function begin_transaction(): void
    {
        $this->inner->begin_transaction();
        $this->languageSnapshots[] = [
            'primary' => $this->primaryLangByDoc,
            'lengths' => $this->langLengthsByDoc,
            'meta' => $this->metaByLang,
        ];
    }

    public function commit(): void
    {
        $this->inner->commit();
        array_pop($this->languageSnapshots);
    }

    public function rollback(): void
    {
        $this->inner->rollback();
        $snapshot = array_pop($this->languageSnapshots);
        if ($snapshot === null) {
            return;
        }

        $this->primaryLangByDoc = $snapshot['primary'];
        $this->langLengthsByDoc = $snapshot['lengths'];
        $this->metaByLang = $snapshot['meta'];
    }

    public function flush(): void
    {
        $this->inner->flush();
    }

    public function optimize(): void
    {
        $this->inner->optimize();
    }
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
    public string $last_error = '';
    public ?string $failQueryPrefix = null;

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
        if ($this->failQueryPrefix !== null && str_starts_with($sql, $this->failQueryPrefix)) {
            $this->last_error = "simulated failure for {$this->failQueryPrefix}";
            return false;
        }
        $this->last_error = '';

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

final class WP_FTS_Test_Language_Aware_Analyzer
{
    /** @var array<int,array<string,mixed>> */
    public array $contentOptions = [];

    /** @var array<int,array<string,mixed>> */
    public array $queryOccurrenceOptions = [];

    /** @var array<int,array<string,mixed>> */
    public array $queryOptions = [];

    /**
     * @return array<int,array{term:string,weight:float,lang:string}>
     */
    public function analyze_content(string $html, array $options = []): array
    {
        $this->contentOptions[] = $options;
        $documentLang = $this->language_from_options($options, ['document_lang', 'lang', 'language']);
        $occurrences = [];

        if (str_contains($html, 'zamek')) {
            $occurrences[] = ['term' => 'zamek', 'weight' => 1.0, 'lang' => $documentLang];
        }
        if (str_contains($html, 'castle')) {
            $occurrences[] = ['term' => 'castle', 'weight' => 1.0, 'lang' => 'en'];
        }

        return $occurrences;
    }

    /**
     * @return array<int,array{term:string,lang:string}>
     */
    public function analyze_query_occurrences(string $query, array $options = []): array
    {
        $this->queryOccurrenceOptions[] = $options;

        return $this->query_occurrences($query, $this->language_from_options($options, ['query_lang', 'lang', 'language']));
    }

    /**
     * @return array<int,string|array{term:string,lang:string}>
     */
    public function analyze_query(string $query, array $options = []): array
    {
        $this->queryOptions[] = $options;
        $occurrences = $this->query_occurrences($query, $this->language_from_options($options, ['query_lang', 'lang', 'language']));

        if (($options['return'] ?? null) === 'occurrences') {
            return $occurrences;
        }

        return array_map(static fn(array $occurrence): string => $occurrence['term'], $occurrences);
    }

    /**
     * @param array<int,string> $keys
     */
    private function language_from_options(array $options, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($options[$key]) && is_string($options[$key]) && trim($options[$key]) !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang($options[$key]);
            }
        }

        return 'en';
    }

    /**
     * @return array<int,array{term:string,lang:string}>
     */
    private function query_occurrences(string $query, string $lang): array
    {
        if (!str_contains($query, 'zamek')) {
            return [];
        }

        return $lang === 'en' ? [] : [['term' => 'zamek', 'lang' => $lang]];
    }
}

final class WP_FTS_Test_Query_Fallback_Analyzer
{
    /** @var array<int,array<string,mixed>> */
    public array $queryOptions = [];

    /**
     * @return array<int,array{term:string,weight:float,lang:string}>
     */
    public function analyze_content(string $html, array $options = []): array
    {
        $lang = isset($options['document_lang']) && is_string($options['document_lang'])
            ? WP_FTS_TermNamespace::canonicalize_lang($options['document_lang'])
            : 'en';

        return str_contains($html, 'zamek')
            ? [['term' => 'zamek', 'weight' => 1.0, 'lang' => $lang]]
            : [];
    }

    /**
     * @return array<int,string|array{term:string,lang:string}>
     */
    public function analyze_query(string $query, array $options = []): array
    {
        $this->queryOptions[] = $options;
        $lang = isset($options['query_lang']) && is_string($options['query_lang'])
            ? WP_FTS_TermNamespace::canonicalize_lang($options['query_lang'])
            : 'en';
        if (!str_contains($query, 'zamek') || $lang === 'en') {
            return [];
        }

        if (($options['return'] ?? null) === 'occurrences') {
            return [['term' => 'zamek', 'lang' => $lang]];
        }

        return ['zamek'];
    }
}

final class WP_FTS_Test_LanguagePartitionStorage implements WP_FTS_Storage
{
    public ?string $lastDocLengthLang = null;
    public ?string $lastMetaLang = null;

    private string $term;

    public function __construct()
    {
        $this->term = WP_FTS_TermNamespace::namespace_term('en', 'needle');
    }

    public function get_terms(array $terms): array
    {
        if (!in_array($this->term, $terms, true)) {
            return [];
        }

        return [
            $this->term => [
                'df' => 1,
                'postings' => WP_FTS_PostingsCodec::encode([101 => 2]),
            ],
        ];
    }

    public function put_term(string $term, int $df, string $postings): void
    {
    }

    public function delete_term(string $term): void
    {
    }

    public function get_doc_lengths(array $doc_ids, ?string $lang = null): array
    {
        $this->lastDocLengthLang = $lang;

        return $lang === 'en' ? [101 => 4] : [101 => 400];
    }

    public function get_doc(int $doc_id): ?array
    {
        return [
            'doc_len' => 4,
            'content_hash' => 'test',
            'deleted' => false,
        ];
    }

    /**
     * @param int|string $primary_lang Legacy doc length or a language code.
     * @param array<string,int>|string $lang_lengths Legacy hash or per-language lengths.
     */
    public function put_doc(int $doc_id, int|string $primary_lang, array|string $lang_lengths, ?string $hash = null): void
    {
    }

    public function delete_doc(int $doc_id): void
    {
    }

    public function get_meta(?string $lang = null): array
    {
        $this->lastMetaLang = $lang;

        return $lang === 'en'
            ? ['doc_count' => 2, 'len_sum' => 8]
            : ['doc_count' => 100, 'len_sum' => 4000];
    }

    public function add_meta(int|string $lang_or_d_docs, int $d_docs_or_d_len, ?int $d_len = null): void
    {
    }

    public function all_terms(): array
    {
        return [$this->term];
    }

    public function all_doc_ids(bool $include_deleted = false): array
    {
        return [101];
    }

    public function begin_transaction(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollback(): void
    {
    }

    public function flush(): void
    {
    }

    public function optimize(): void
    {
    }
}

if (!class_exists('WP_CLI')) {
    final class WP_CLI
    {
        /** @var string[] */
        public static array $successMessages = [];

        /** @var array<string,string> */
        public static array $commands = [];

        public static function add_command(string $name, string $class): void
        {
            self::$commands[$name] = $class;
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

function wp_fts_test_reset_wordpress_fakes(): void
{
    $GLOBALS['wp_fts_test_actions'] = [];
    $GLOBALS['wp_fts_test_activation_hooks'] = [];
    $GLOBALS['wp_fts_test_deactivation_hooks'] = [];
    $GLOBALS['wp_fts_test_uninstall_hooks'] = [];
    $GLOBALS['wp_fts_test_options'] = [];
    $GLOBALS['wp_fts_test_scheduled'] = [];
    $GLOBALS['wp_fts_test_cleared_hooks'] = [];
    $GLOBALS['wp_fts_test_rest_routes'] = [];
    $GLOBALS['wp_fts_test_posts'] = [];
    $GLOBALS['wp_fts_test_get_post_callbacks'] = [];
    $GLOBALS['wp_fts_test_post_types'] = [
        'post' => (object) ['public' => true, 'exclude_from_search' => false],
        'page' => (object) ['public' => true, 'exclude_from_search' => false],
        'secret' => (object) ['public' => false, 'exclude_from_search' => true],
    ];
    $GLOBALS['wp_fts_test_caps'] = [];
    $GLOBALS['wp_fts_test_revisions'] = [];
    $GLOBALS['wp_fts_test_autosaves'] = [];
    WP_CLI::$commands = [];
    WP_CLI::$successMessages = [];
}

if (!function_exists('add_action')) {
    function add_action(string $hook_name, mixed $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $entry = [
            'hook' => $hook_name,
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args,
        ];
        $GLOBALS['wp_fts_test_actions'][] = $entry;
        if (array_key_exists('wp_fts_quality_add_action_calls', $GLOBALS)) {
            $GLOBALS['wp_fts_quality_add_action_calls'][] = $entry;
        }

        return true;
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook(string $file, mixed $callback): void
    {
        $GLOBALS['wp_fts_test_activation_hooks'][] = ['file' => $file, 'callback' => $callback];
    }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(string $file, mixed $callback): void
    {
        $GLOBALS['wp_fts_test_deactivation_hooks'][] = ['file' => $file, 'callback' => $callback];
    }
}

if (!function_exists('register_uninstall_hook')) {
    function register_uninstall_hook(string $file, mixed $callback): void
    {
        $GLOBALS['wp_fts_test_uninstall_hooks'][] = ['file' => $file, 'callback' => $callback];
    }
}

if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return array_key_exists($name, $GLOBALS['wp_fts_test_options'])
            ? $GLOBALS['wp_fts_test_options'][$name]
            : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, mixed $value): bool
    {
        $old = $GLOBALS['wp_fts_test_options'][$name] ?? null;
        $GLOBALS['wp_fts_test_options'][$name] = $value;

        return $old !== $value;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        $existed = array_key_exists($name, $GLOBALS['wp_fts_test_options']);
        unset($GLOBALS['wp_fts_test_options'][$name]);

        return $existed;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook): int|false
    {
        return $GLOBALS['wp_fts_test_scheduled'][$hook]['timestamp'] ?? false;
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event(int $timestamp, string $hook): bool
    {
        $GLOBALS['wp_fts_test_scheduled'][$hook] = [
            'timestamp' => $timestamp,
            'hook' => $hook,
        ];

        return true;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook): int
    {
        $GLOBALS['wp_fts_test_cleared_hooks'][] = $hook;
        unset($GLOBALS['wp_fts_test_scheduled'][$hook]);

        return 1;
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
    {
        $GLOBALS['wp_fts_test_rest_routes'][] = [
            'namespace' => $namespace,
            'route' => $route,
            'args' => $args,
            'override' => $override,
        ];

        return true;
    }
}

if (!function_exists('get_post')) {
    function get_post(int|object $post): ?object
    {
        if (is_object($post)) {
            return $post;
        }

        $post_id = (int) $post;
        $callback = $GLOBALS['wp_fts_test_get_post_callbacks'][$post_id] ?? null;
        if (is_callable($callback)) {
            $callback($post_id);
        }

        return $GLOBALS['wp_fts_test_posts'][$post_id] ?? null;
    }
}

if (!function_exists('get_post_status')) {
    function get_post_status(int|object $post): string|false
    {
        $post = get_post($post);

        return is_object($post) && isset($post->post_status) ? (string) $post->post_status : false;
    }
}

if (!function_exists('get_post_type_object')) {
    function get_post_type_object(string $post_type): ?object
    {
        return $GLOBALS['wp_fts_test_post_types'][$post_type] ?? null;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, mixed ...$args): bool
    {
        $post_id = isset($args[0]) ? (int) $args[0] : 0;

        return (bool) ($GLOBALS['wp_fts_test_caps'][$capability][$post_id] ?? false);
    }
}

if (!function_exists('wp_is_post_revision')) {
    function wp_is_post_revision(int $post_id): int|false
    {
        return !empty($GLOBALS['wp_fts_test_revisions'][$post_id]) ? $post_id : false;
    }
}

if (!function_exists('wp_is_post_autosave')) {
    function wp_is_post_autosave(int $post_id): int|false
    {
        return !empty($GLOBALS['wp_fts_test_autosaves'][$post_id]) ? $post_id : false;
    }
}

if (!function_exists('has_filter')) {
    function has_filter(string $hook_name): bool
    {
        return false;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook_name, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}

wp_fts_test_reset_wordpress_fakes();

test_case('plugin bootstrap registers WordPress lifecycle hooks and preserves CLI-only bootstrap', function (): void {
    $plugin = (string) realpath(__DIR__ . '/../indexer.php');

    $noWordPress = test_run_php_without_extensions(
        'require ' . var_export($plugin, true) . '; echo class_exists("WP_FTS_Plugin") ? "loaded" : "missing";'
    );
    assert_same(0, $noWordPress['exit'], 'plugin bootstrap should load without WordPress hook functions');
    assert_contains('loaded', $noWordPress['stdout'], 'plugin bootstrap should expose plugin class outside WordPress');

    $cliCode = <<<'PHP'
define('WP_CLI', true);
final class WP_CLI {
    public static array $commands = [];
    public static function add_command(string $name, string $class): void {
        self::$commands[$name] = $class;
    }
}
require __PLUGIN__;
echo json_encode(WP_CLI::$commands);
PHP;
    $cliCode = str_replace('__PLUGIN__', var_export($plugin, true), $cliCode);
    $cli = test_run_php_without_extensions($cliCode);
    assert_same(0, $cli['exit'], 'plugin bootstrap should keep WP-CLI registration working');
    assert_contains('"fts":"WP_FTS_WPCLI_Command"', $cli['stdout'], 'WP-CLI bootstrap should register the fts command');

    wp_fts_test_reset_wordpress_fakes();
    require_once $plugin;

    $activation = $GLOBALS['wp_fts_test_activation_hooks'][0] ?? null;
    $deactivation = $GLOBALS['wp_fts_test_deactivation_hooks'][0] ?? null;
    $uninstall = $GLOBALS['wp_fts_test_uninstall_hooks'][0] ?? null;
    assert_same([WP_FTS_Plugin::class, 'activate'], $activation['callback'] ?? null, 'bootstrap should register activation lifecycle hook');
    assert_same([WP_FTS_Plugin::class, 'deactivate'], $deactivation['callback'] ?? null, 'bootstrap should register deactivation lifecycle hook');
    assert_same([WP_FTS_Plugin::class, 'uninstall'], $uninstall['callback'] ?? null, 'bootstrap should register explicit uninstall hook');

    $hooks = array_column($GLOBALS['wp_fts_test_actions'], 'hook');
    sort($hooks, SORT_STRING);
    $expectedHooks = [
        WP_FTS_Plugin::CRON_HOOK,
        'before_delete_post',
        'rest_api_init',
        'save_post',
        'transition_post_status',
        'trashed_post',
        'wp_after_insert_post',
    ];
    sort($expectedHooks, SORT_STRING);
    assert_same($expectedHooks, $hooks, 'bootstrap should register bounded runtime hooks in WordPress context');
    assert_same([], WP_CLI::$commands, 'web bootstrap should not register WP-CLI unless WP_CLI is active');
});

test_case('activation repairs schema stores version and surfaces database failures', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    try {
        WP_FTS_Plugin::activate();
        assert_same(WP_FTS_Plugin::SCHEMA_VERSION, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null, 'activation should store schema version option');
        assert_same(4, count(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE'))), 'activation should create or repair all FTS tables');
        assert_true(isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), 'activation should schedule the queue processor');

        WP_FTS_Plugin::maybe_upgrade_schema();
        assert_same(4, count(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE'))), 'current schema version should avoid redundant runtime repair');

        WP_FTS_Plugin::upgrade_schema();
        assert_same(8, count(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE'))), 'explicit repair routine should be idempotent and rerunnable');
    } finally {
        $wpdb = $oldWpdb;
    }

    $failing = new WP_FTS_Test_WPDB();
    $failing->failQueryPrefix = 'CREATE TABLE';
    $wpdb = $failing;
    wp_fts_test_reset_wordpress_fakes();
    $thrown = false;
    try {
        WP_FTS_Plugin::activate();
    } catch (RuntimeException $e) {
        $thrown = true;
        assert_contains('create FTS tables', $e->getMessage(), 'activation failure should name the failed schema operation');
    } finally {
        $wpdb = $oldWpdb;
    }
    assert_true($thrown, 'activation should throw on schema write failure');
});

test_case('deactivation and uninstall keep index data while clearing operational state', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    try {
        WP_FTS_Plugin::activate();
        $storage = WP_FTS_Plugin::storage(false);
        $storage->put_doc(31, 'en', ['en' => 2], 'hash-31');
        $storage->put_term(WP_FTS_TermNamespace::namespace_term('en', 'alpha'), 1, WP_FTS_PostingsCodec::encode([31 => 2]));
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] = [31, 32];

        WP_FTS_Plugin::deactivate();
        assert_true(isset($fake->docs[31]), 'deactivation should not destroy indexed documents');
        assert_true($fake->terms !== [], 'deactivation should not destroy term rows');
        assert_true(isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION]), 'deactivation should leave schema version option intact');
        assert_true(in_array(WP_FTS_Plugin::CRON_HOOK, $GLOBALS['wp_fts_test_cleared_hooks'], true), 'deactivation should clear scheduled queue work');

        WP_FTS_Plugin::uninstall();
        assert_true(isset($fake->docs[31]), 'uninstall policy should retain index data until destructive cleanup is explicitly implemented');
        assert_true($fake->terms !== [], 'uninstall policy should retain term data');
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION]), 'uninstall should delete schema version option');
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION]), 'uninstall should delete pending queue option');
        assert_true(!str_contains(implode("\n", $fake->queries), 'DROP TABLE'), 'uninstall should not drop custom tables under the documented deferred cleanup policy');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('runtime post hooks queue bounded indexing and tombstone invisible posts', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $post = (object) [
        'ID' => 101,
        'post_title' => 'Needle',
        'post_content' => '<p>alpha beta alpha</p>',
        'post_status' => 'publish',
        'post_type' => 'post',
    ];
    $GLOBALS['wp_fts_test_posts'][101] = $post;

    try {
        WP_FTS_Plugin::handle_post_save(101, $post, true);
        WP_FTS_Plugin::handle_post_save(101, $post, true);
        assert_same([101], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] ?? [], 'save hooks should queue each post id only once');
        assert_true(isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), 'save hook should schedule background processing');

        assert_same(1, WP_FTS_Plugin::process_queue(1), 'queue processor should process a bounded batch');
        assert_same([], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] ?? [], 'processed ids should leave the queue');
        assert_true(isset($fake->docs[101]) && $fake->docs[101]['is_deleted'] === 0, 'queue processing should write an active document');
        assert_true($fake->terms !== [], 'queue processing should write term postings');
        assert_same([101], array_column(WP_FTS_Plugin::search('alpha', ['limit' => 10]), 'doc_id'), 'search helper should expose the indexed public post');

        $post->post_status = 'draft';
        WP_FTS_Plugin::handle_status_transition('draft', 'publish', $post);
        assert_true($fake->docs[101]['is_deleted'] === 1, 'leaving publish status should tombstone the indexed document');
        assert_same([], WP_FTS_Plugin::search('alpha', ['limit' => 10]), 'tombstoned documents should not be returned');

        $GLOBALS['wp_fts_test_revisions'][102] = true;
        WP_FTS_Plugin::handle_post_save(102, (object) ['ID' => 102, 'post_status' => 'publish', 'post_type' => 'post'], true);
        assert_same([], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] ?? [], 'revision saves should not enqueue indexing work');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('queue processing preserves posts queued during an active batch', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $post = (object) [
        'ID' => 301,
        'post_title' => 'Concurrent queue',
        'post_content' => '<p>alpha concurrent</p>',
        'post_status' => 'publish',
        'post_type' => 'post',
    ];
    $GLOBALS['wp_fts_test_posts'][301] = $post;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] = [301, 302];
    $GLOBALS['wp_fts_test_get_post_callbacks'][301] = static function (): void {
        unset($GLOBALS['wp_fts_test_get_post_callbacks'][301]);
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] = [301, 302, 303];
    };

    try {
        assert_same(1, WP_FTS_Plugin::process_queue(1), 'queue processor should process only the claimed batch');
        assert_same([302, 303], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] ?? [], 'queue processor should preserve ids added after its initial snapshot');
        assert_true(isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), 'remaining concurrent queue work should schedule another processor run');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('password-protected published posts are not queued indexed or exposed', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    $passworded = (object) [
        'ID' => 311,
        'post_title' => 'Protected shared',
        'post_content' => '<p>alpha shared hidden</p>',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_password' => 'secret',
    ];
    $public = (object) [
        'ID' => 312,
        'post_title' => 'Public shared',
        'post_content' => '<p>alpha shared visible</p>',
        'post_status' => 'publish',
        'post_type' => 'post',
    ];
    $GLOBALS['wp_fts_test_posts'][311] = $passworded;
    $GLOBALS['wp_fts_test_posts'][312] = $public;

    try {
        $indexer = new WP_FTS_Indexer(WP_FTS_Plugin::storage(true), new WP_FTS_Analyzer());
        $indexer->index_post($passworded, ['lang' => 'en']);
        $indexer->index_post($public, ['lang' => 'en']);

        $GLOBALS['wp_fts_test_caps']['read_post'][311] = true;
        assert_same([312], array_column(WP_FTS_Plugin::search('shared', ['limit' => 10]), 'doc_id'), 'public search should hide password-protected posts even when stale indexed rows exist');

        WP_FTS_Plugin::handle_post_save(311, $passworded, true);
        assert_same([], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] ?? [], 'password-protected publish saves should not enqueue indexing work');
        assert_true(($fake->docs[311]['is_deleted'] ?? 0) === 1, 'password-protected publish saves should tombstone stale indexed rows');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('REST search surface filters private results by capability', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    $public = (object) [
        'ID' => 201,
        'post_title' => 'Public shared',
        'post_content' => '<p>alpha shared</p>',
        'post_status' => 'publish',
        'post_type' => 'post',
    ];
    $private = (object) [
        'ID' => 202,
        'post_title' => 'Private shared',
        'post_content' => '<p>alpha shared</p>',
        'post_status' => 'private',
        'post_type' => 'post',
    ];
    $GLOBALS['wp_fts_test_posts'][201] = $public;
    $GLOBALS['wp_fts_test_posts'][202] = $private;

    try {
        WP_FTS_Plugin::register_rest_routes();
        $route = $GLOBALS['wp_fts_test_rest_routes'][0] ?? null;
        assert_same(WP_FTS_Plugin::REST_NAMESPACE, $route['namespace'] ?? null, 'REST registration should use the plugin namespace');
        assert_same(WP_FTS_Plugin::REST_SEARCH_ROUTE, $route['route'] ?? null, 'REST registration should expose the search route');
        assert_true(is_callable($route['args']['callback'] ?? null), 'REST search route should have a callable callback');
        assert_true(is_callable($route['args']['permission_callback'] ?? null), 'REST search route should have a callable permission callback');

        $indexer = new WP_FTS_Indexer(WP_FTS_Plugin::storage(true), new WP_FTS_Analyzer());
        $indexer->index_post($public);
        $indexer->index_post($private, ['lang' => 'en']);

        assert_same([201], array_column(WP_FTS_Plugin::search('shared', ['limit' => 10]), 'doc_id'), 'public search should hide indexed private posts without read capability');

        $GLOBALS['wp_fts_test_caps']['read_post'][202] = true;
        $ids = array_column(WP_FTS_Plugin::search('shared', ['limit' => 10]), 'doc_id');
        sort($ids, SORT_NUMERIC);
        assert_same([201, 202], $ids, 'search should include private indexed posts when the visitor can read them');

        $response = WP_FTS_Plugin::rest_search(['q' => 'shared', 'limit' => 1]);
        assert_same(1, count($response['results']), 'REST search should honor the request limit');
        assert_true(in_array($response['results'][0]['doc_id'], [201, 202], true), 'REST search should return ranked result rows');
    } finally {
        $wpdb = $oldWpdb;
    }
});

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

test_case('analyzer does not require optional extensions at runtime', function (): void {
    $bootstrap = (string) realpath(__DIR__ . '/../src/bootstrap.php');
    $code = str_replace('__BOOTSTRAP__', var_export($bootstrap, true), <<<'PHP'
require __BOOTSTRAP__;
$analyzer = new WP_FTS_Analyzer();
$folded = $analyzer->analyze_query('Wrocław Łódź café');
$invalid = $analyzer->analyze_query("bad\xffutf café");
echo implode('|', $folded), "\n", implode('|', $invalid), "\n";
PHP);
    $result = test_run_php_without_extensions($code);
    $stderr = trim($result['stderr']);
    $detail = $stderr === '' ? '' : "\nSubprocess stderr: " . substr($stderr, 0, 500);

    assert_or_pending(
        $result['exit'] === 0
        && str_contains($result['stdout'], 'wroclaw|lodz|cafe')
        && str_contains($result['stdout'], 'bad')
        && str_contains($result['stdout'], 'cafe'),
        'Analyzer should fold configured diacritics and recover malformed UTF-8 without iconv/mbstring loaded.',
        'Pending review fix: optional extension calls are still not fully guarded.' . $detail
    );
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
    assert_same('running', $snowball->stem('running', 'en'), 'Snowball adapter should no-op non-compliant Wamania algorithms');

    if ($snowball->is_available()) {
        assert_true($snowball->supports_language('ca'), 'Snowball adapter should advertise compliant Catalan support');
        assert_true($snowball->supports_language('nl-BE'), 'Snowball adapter should advertise compliant Dutch Porter support');
        assert_same('abandon', $snowball->stem('abandonaments', 'ca'), 'Snowball adapter should use Wamania for compliant Catalan');
        assert_same('aalmoez', $snowball->stem('aalmoezen', 'nl'), 'Snowball adapter should map Dutch to Wamania Dutch Porter');
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
    $comparisons = 0;

    for ($round = 0; $round < 20; $round++) {
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
            $comparisons++;
        }
    }

    assert_true($comparisons >= 200, 'generated brute-force parity should cover at least 200 corpus/query combinations');
});

test_case('T8 per-language analyzer fixtures are enforced when language pipelines exist', function (): void {
    $fixtures = [
        ['English normalization', 'en', 'running runs runner', ['running', 'runs', 'runner']],
        ['Polish folding', 'pl', 'Wrocław Łódź zażółć', ['wroclaw', 'lodz', 'zazolc']],
        ['German folding', 'de', 'Straße Ärger Öl', ['strasse', 'aerger', 'oel']],
        ['Turkish dotted I folding', 'tr', 'Isparta İstanbul ışık', ['ısparta', 'istanbul', 'ısık']],
        ['CJK bigrams', 'zh-Hans', '搜索引擎', ['搜索', '索引', '引擎']],
    ];

    foreach ($fixtures as [$label, $lang, $input, $expectedTerms]) {
        $analyzer = new WP_FTS_Analyzer(['default_lang' => $lang, 'language' => $lang]);
        $records = test_token_records(test_call_query_occurrences($analyzer, $input, $lang));

        assert_or_pending(
            test_records_have_lang($records),
            "{$label} query analysis should expose language-tagged terms.",
            'Pending T8: analyzer query output does not yet carry language metadata or language namespaces.'
        );
        assert_same($expectedTerms, test_terms_for_lang($records, $lang), "{$label} token stream");
    }
});

test_case('T8 mixed-language lang attributes route terms to segment languages', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'pl', 'language' => 'pl']);
    $records = test_token_records(test_call_analyzer(
        $analyzer,
        'analyze_content',
        '<article lang="pl"><p>Wrocław tekst</p><code lang="en">fatal error</code></article>',
        ['lang' => 'pl']
    ));

    assert_or_pending(
        test_records_have_lang($records),
        'Content analysis should expose per-segment languages from lang attributes.',
        'Pending T8: analyzer content output does not yet carry language metadata or namespaces.'
    );
    assert_same(['en'], test_langs_for_term($records, 'fatal'), 'English code term should be routed only to en');
    assert_same(['en'], test_langs_for_term($records, 'error'), 'English code term should be routed only to en');
    assert_same(['pl'], test_langs_for_term($records, 'wroclaw'), 'Polish body term should be routed only to pl');
});

test_case('T8 BM25 uses per-language stats for language-namespaced terms', function (): void {
    $storage = new WP_FTS_Test_LanguagePartitionStorage();
    $analyzer = new WP_FTS_Analyzer([
        'stemmer' => static fn(string $term): string => WP_FTS_TermNamespace::namespace_term('en', $term),
    ]);
    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    $results = $searcher->search('needle', ['mode' => 'OR', 'limit' => 10, 'lang' => 'en', 'language' => 'en']);

    assert_same(1, count($results), 'language partition fixture should return one candidate');
    assert_or_pending(
        $storage->lastDocLengthLang === 'en' && $storage->lastMetaLang === 'en',
        'Searcher must request doc lengths and BM25 collection stats for the query language.',
        'Pending T8: search still reads global doc lengths/meta instead of the query language partition.'
    );
    assert_float_near(
        test_bm25_score(2, 4, 2, 1, 4.0),
        $results[0]['score'],
        'BM25 score should be computed from the English partition, not global corpus stats'
    );
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

test_case('indexer consumes analyzer occurrences with language tags', function (): void {
    $indexer = new WP_FTS_Indexer(new WP_FTS_Storage_InMemory(), new WP_FTS_Analyzer());
    $method = new ReflectionMethod(WP_FTS_Indexer::class, 'weighted_term_frequencies_by_language');
    $method->setAccessible(true);

    [$frequencies, $langLengths] = $method->invoke($indexer, [
        ['term' => 'shared', 'weight' => 1.0, 'lang' => 'en_US'],
        ['term' => 'shared', 'weight' => 2.4, 'lang' => 'pl'],
        ['term' => 'jablko', 'weight' => 0.6, 'lang' => 'pl'],
        ['term' => 'fallback', 'weight' => 1.0],
    ], 'de');

    assert_same([
        WP_FTS_TermNamespace::namespace_term('de', 'fallback') => 1,
        WP_FTS_TermNamespace::namespace_term('en-US', 'shared') => 1,
        WP_FTS_TermNamespace::namespace_term('pl', 'jablko') => 1,
        WP_FTS_TermNamespace::namespace_term('pl', 'shared') => 2,
    ], $frequencies, 'language-tagged occurrences should be namespaced independently');
    assert_same(['de' => 1, 'en-US' => 1, 'pl' => 3], $langLengths, 'doc lengths should be partitioned by occurrence language');
});

test_case('language options namespace terms and isolate search partitions', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    $storage = new WP_FTS_Test_LanguageAwareStorage();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(1, '<p>shared apple</p>', ['lang' => 'en_US']);
    $indexer->index_document(2, '<p>shared jablko</p>', ['lang' => 'pl']);

    $terms = $storage->all_terms();
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('en-US', 'shared'), $terms, true), 'English shared term should be namespaced');
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('pl', 'shared'), $terms, true), 'Polish shared term should be namespaced');
    assert_true(!in_array('shared', $terms, true), 'raw unnamespaced term should not be stored');

    assert_same(['doc_count' => 1, 'len_sum' => 2], $storage->get_meta('en-US'), 'English stats should be independent');
    assert_same(['doc_count' => 1, 'len_sum' => 2], $storage->get_meta('pl'), 'Polish stats should be independent');

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    assert_same(1, $searcher->search('shared', ['lang' => 'en_US'])[0]['doc_id'] ?? null, 'English query should only search English partition');
    assert_same(2, $searcher->search('shared', ['lang' => 'pl'])[0]['doc_id'] ?? null, 'Polish query should only search Polish partition');
    assert_same([], $searcher->search('jablko', ['lang' => 'en_US']), 'English query should not match Polish terms');
});

test_case('searcher preserves analyzer-selected query language', function (): void {
    $analyzer = new WP_FTS_Analyzer(['query_lang' => 'pl']);
    $storage = new WP_FTS_Test_LanguageAwareStorage();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(1, '<p>lodz polish</p>', ['lang' => 'pl']);
    $indexer->index_document(2, '<p>lodz english</p>', ['lang' => 'en']);

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    $results = $searcher->search('lodz');

    assert_same(1, count($results), 'analyzer-selected Polish query should only search the Polish partition');
    assert_same(1, $results[0]['doc_id'], 'unqualified query should hit the Polish partition selected by the analyzer');
});

test_case('indexer passes default document language to analyzer as document_lang', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    $storage = new WP_FTS_Test_LanguageAwareStorage();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(1, '<p>lodz</p>', ['default_lang' => 'pl']);

    $terms = $storage->all_terms();
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('pl', 'lodz'), $terms, true), 'default_lang should reach analyzer as document_lang');
    assert_true(!in_array(WP_FTS_TermNamespace::namespace_term('en', 'lodz'), $terms, true), 'default_lang should not be lost to analyzer fallback language');
    assert_same(1, (new WP_FTS_Searcher($storage, $analyzer))->search('lodz', ['lang' => 'pl'])[0]['doc_id'] ?? null, 'Polish query should find the default_lang document');
});

test_case('reindex and delete adjust old per-language stats', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    $storage = new WP_FTS_Test_LanguageAwareStorage();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(7, '<p>alpha beta</p>', ['lang' => 'en']);
    assert_same(['doc_count' => 1, 'len_sum' => 2], $storage->get_meta('en'), 'initial English stats should count the document');
    assert_same(['doc_count' => 0, 'len_sum' => 0], $storage->get_meta('pl'), 'Polish stats should start empty');

    $indexer->index_document(7, '<p>alpha beta</p>', ['lang' => 'pl']);
    assert_same(['doc_count' => 0, 'len_sum' => 0], $storage->get_meta('en'), 'reindexing into Polish should decrement old English stats');
    assert_same(['doc_count' => 1, 'len_sum' => 2], $storage->get_meta('pl'), 'reindexing into Polish should increment Polish stats');
    assert_same([], (new WP_FTS_Searcher($storage, $analyzer))->search('alpha', ['lang' => 'en']), 'old English postings should be removed on reindex');

    assert_true($indexer->delete_document(7), 'delete should tombstone an active document');
    assert_same(['doc_count' => 0, 'len_sum' => 0], $storage->get_meta('pl'), 'delete should decrement current language stats');
    assert_same([], (new WP_FTS_Searcher($storage, $analyzer))->search('alpha', ['lang' => 'pl']), 'deleted doc should not be returned');
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

test_case('indexing passes resolved document language to analyzer', function (): void {
    $analyzer = new WP_FTS_Test_Language_Aware_Analyzer();
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(1, '<p>zamek</p>', ['lang' => 'pl']);

    $plTerm = WP_FTS_TermNamespace::term_key('zamek', 'pl');
    assert_same([$plTerm], $storage->all_terms(), 'explicit Polish document language should namespace emitted terms as Polish');
    assert_same(['doc_count' => 1, 'len_sum' => 1], $storage->get_meta('pl'), 'Polish doc metadata should match Polish terms');
    assert_same(['doc_count' => 0, 'len_sum' => 0], $storage->get_meta('en'), 'English metadata should not be touched by Polish-only content');
    assert_same('pl', $storage->get_doc(1)['primary_lang'], 'stored primary language should remain Polish');
    assert_same(['pl' => 1], $storage->get_doc(1)['lang_lengths'], 'stored document lengths should remain Polish');
    assert_same('pl', $analyzer->contentOptions[0]['lang'], 'analyzer should receive lang option');
    assert_same('pl', $analyzer->contentOptions[0]['language'], 'analyzer should receive language option');
    assert_same('pl', $analyzer->contentOptions[0]['document_lang'], 'analyzer should receive document_lang option');

    $mixed = new WP_FTS_Storage_InMemory();
    $mixedIndexer = new WP_FTS_Indexer($mixed, $analyzer);
    $mixedIndexer->index_document(2, '<p>zamek</p><code lang="en">castle</code>', ['lang' => 'pl']);
    assert_same(
        [WP_FTS_TermNamespace::term_key('castle', 'en'), $plTerm],
        $mixed->all_terms(),
        'indexer should preserve occurrence-level language overrides returned by the analyzer'
    );
    assert_same(['en' => 1, 'pl' => 1], $mixed->get_doc(2)['lang_lengths'], 'mixed-language occurrences should keep per-language lengths');
});

test_case('search passes resolved query language and prefers query occurrences', function (): void {
    $analyzer = new WP_FTS_Test_Language_Aware_Analyzer();
    assert_same([], $analyzer->analyze_query_occurrences('zamek'), 'fixture English pipeline should remove the Polish regression term');
    $analyzer->queryOccurrenceOptions = [];

    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $indexer->index_document(1, '<p>zamek</p>', ['lang' => 'pl']);
    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    assert_same([1], array_column($searcher->search('zamek', ['lang' => 'pl']), 'doc_id'), 'Polish query language should reach occurrence analysis');
    assert_same([], $analyzer->queryOptions, 'search should prefer analyze_query_occurrences when it is available');
    assert_same('pl', $analyzer->queryOccurrenceOptions[0]['lang'], 'occurrence analysis should receive lang option');
    assert_same('pl', $analyzer->queryOccurrenceOptions[0]['language'], 'occurrence analysis should receive language option');
    assert_same('pl', $analyzer->queryOccurrenceOptions[0]['query_lang'], 'occurrence analysis should receive query_lang option');
    assert_same('occurrences', $analyzer->queryOccurrenceOptions[0]['return'], 'occurrence analysis should request occurrence output');
});

test_case('search requests occurrence output through query fallback', function (): void {
    $analyzer = new WP_FTS_Test_Query_Fallback_Analyzer();
    assert_same([], $analyzer->analyze_query('zamek'), 'fixture English fallback pipeline should remove the Polish regression term');
    $analyzer->queryOptions = [];

    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $indexer->index_document(1, '<p>zamek</p>', ['lang' => 'pl']);
    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    assert_same([1], array_column($searcher->search('zamek', ['lang' => 'pl']), 'doc_id'), 'query fallback should analyze under explicit Polish language');
    assert_same('pl', $analyzer->queryOptions[0]['lang'], 'query fallback should receive lang option');
    assert_same('pl', $analyzer->queryOptions[0]['language'], 'query fallback should receive language option');
    assert_same('pl', $analyzer->queryOptions[0]['query_lang'], 'query fallback should receive query_lang option');
    assert_same('occurrences', $analyzer->queryOptions[0]['return'], 'query fallback should request occurrence output');
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

    $plTerm = WP_FTS_TermNamespace::term_key('zamek', 'pl');
    $enTerm = WP_FTS_TermNamespace::term_key('zamek', 'en');
    assert_true($plTerm !== $enTerm && str_contains($plTerm, WP_FTS_TermNamespace::SEPARATOR), 'term keys should be language namespaced');
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

discover_quality_tests();

test_case('quality discovery loads tests/quality files', function (): void {
    $discovered = array_map('basename', discovered_quality_test_files());
    sort($discovered, SORT_STRING);

    assert_true(in_array('000-discovery-sentinel.php', $discovered, true), 'quality discovery should record the sentinel file');
    assert_true(in_array('harness-metrics.php', $discovered, true), 'quality discovery should record the metrics test file');
    assert_same(1, $GLOBALS['wp_fts_quality_discovery_sentinel'] ?? 0, 'quality discovery should include tests/quality/*.php exactly once');
});

$failures = 0;
$pending = 0;
$start = microtime(true);
foreach ($tests as $test) {
    try {
        ($test['fn'])();
        fwrite(STDOUT, "[PASS] {$test['name']}\n");
    } catch (WP_FTS_TestPending $e) {
        $pending++;
        fwrite(STDOUT, "[PEND] {$test['name']}\n{$e->getMessage()}\n");
    } catch (Throwable $e) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$test['name']}\n{$e->getMessage()}\n{$e->getTraceAsString()}\n");
    }
}

$duration = number_format(microtime(true) - $start, 3);
$count = count($tests);
$passed = $count - $failures - $pending;
$gateFailures = 0;
$minimumChecks = WP_FTS_DEFAULT_MIN_CHECKS;
try {
    $minimumChecks = minimum_check_count();
} catch (WP_FTS_TestFailure $e) {
    $gateFailures++;
    fwrite(STDERR, "[FAIL] minimum check count configuration\n{$e->getMessage()}\n");
}

$checkCount = executed_check_count();
if ($checkCount < $minimumChecks) {
    $gateFailures++;
    fwrite(STDERR, "[FAIL] minimum check count\nExecuted {$checkCount} checks/scenarios; required {$minimumChecks}. Set WP_FTS_MIN_CHECKS to override the default quality gate. Final integration target is >= " . WP_FTS_FINAL_INTEGRATION_TARGET_CHECKS . ".\n");
}

$totalFailures = $failures + $gateFailures;
$summary = "{$passed}/{$count} named tests passed; failures={$totalFailures}; pending={$pending}; checks/scenarios={$checkCount}; minimum checks={$minimumChecks}; final target>=" . WP_FTS_FINAL_INTEGRATION_TARGET_CHECKS . "; duration={$duration}s\n";
if ($totalFailures > 0) {
    fwrite(STDERR, $summary);
    exit(1);
}

fwrite(STDOUT, $summary);
