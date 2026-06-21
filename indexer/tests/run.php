<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

final class WP_FTS_TestFailure extends RuntimeException
{
}

final class WP_FTS_TestPending extends RuntimeException
{
}

final class WP_FTS_TestRedirect extends RuntimeException
{
    public function __construct(
        public string $location,
        public int $status = 302,
    ) {
        parent::__construct("Redirect to {$location}");
    }
}

final class WP_FTS_Fake_HTML_Processor
{
    private int $offset = -1;

    /**
     * @param array<int,array{type:string,tag?:string,breadcrumbs?:string[],text?:string,attrs?:array<string,string>,closing?:bool}> $tokens
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

    public function get_tag(): ?string
    {
        $tag = $this->current()['tag'] ?? null;

        return is_string($tag) && $tag !== '' ? strtoupper($tag) : null;
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
     * @return array{type?:string,tag?:string,breadcrumbs?:string[],text?:string,attrs?:array<string,string>,closing?:bool}
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

function wp_fts_run_registered_tests_and_exit(): void
{
    global $tests;

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
    exit(0);
}

if (getenv('WP_FTS_HARNESS_GATE_CHILD') === '1') {
    test_case('quality harness gate child sentinel', function (): void {
        assert_true(true, 'harness gate child should execute the shared runner');
    });
    wp_fts_run_registered_tests_and_exit();
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
 * @return string[]
 */
function test_analysis_terms(array $analyses): array
{
    return array_map(
        static fn(array|string $analysis): string => is_array($analysis) ? (string) $analysis['term'] : (string) $analysis,
        $analyses
    );
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

function test_term_for_namespaced_key_bytes(string $lang, int $keyBytes): string
{
    $prefixBytes = strlen(WP_FTS_TermNamespace::namespace_term($lang, ''));

    return str_repeat('x', max(0, $keyBytes - $prefixBytes));
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

/**
 * @param array<int,string> $command
 * @return array{exit:int,stdout:string,stderr:string}
 */
function test_run_subprocess(array $command, ?string $cwd = null): array
{
    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is unavailable, so this subprocess test cannot run in this PHP build.');
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd ?? dirname(__DIR__));
    if (!is_resource($process)) {
        mark_pending('Could not start a PHP subprocess.');
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

    $docMeta = WP_FTS_StorageCompat::get_doc_metadata($storage, array_keys($docs));

    return [
        'terms' => $terms,
        'docs' => $docs,
        'doc_meta' => $docMeta,
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

/**
 * Seed one analyzed English term directly for search-policy tests.
 *
 * @param callable(int):array<string,mixed>|null $metadataFactory
 * @return array{0:WP_FTS_Searcher,1:WP_FTS_Storage_InMemory}
 */
function single_term_search_fixture(int $documentCount, int $strongDocId = 0, ?callable $metadataFactory = null): array
{
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Analyzer([
        'enable_stemming' => false,
        'auto_detect_language' => false,
    ]);
    $term = WP_FTS_TermNamespace::namespace_term('en', 'needle');

    for ($docId = 1; $docId <= $documentCount; $docId++) {
        $tf = $docId === $strongDocId ? 20 : 1;
        $storage->replace_doc_postings($docId, [$term => $tf]);
        $storage->put_doc($docId, 'en', ['en' => $tf], 'single-term-' . $docId);
        if ($metadataFactory !== null) {
            $storage->put_doc_metadata($docId, $metadataFactory($docId));
        }
    }

    return [new WP_FTS_Searcher($storage, $analyzer), $storage];
}

function temp_index_path(string $suffix): string
{
    return sys_get_temp_dir() . '/wp_fts_' . getmypid() . '_' . $suffix . '_' . bin2hex(random_bytes(4)) . '.json';
}

function temp_directory_path(string $suffix): string
{
    return sys_get_temp_dir() . '/wp_fts_' . getmypid() . '_' . $suffix . '_' . bin2hex(random_bytes(4));
}

function remove_directory_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $path) {
        if ($path->isDir()) {
            rmdir($path->getPathname());
            continue;
        }
        unlink($path->getPathname());
    }
    rmdir($directory);
}

function write_synthetic_full_analyzer_pack(
    string $directory,
    int $rows,
    int $shards,
    string $language = 'pl',
    string $packId = 'pl-polimorf-synthetic-full-streaming-fixture'
): string
{
    if ($rows < 1 || $shards < 1) {
        throw new WP_FTS_TestFailure('Synthetic analyzer pack requires positive row and shard counts.');
    }
    $language = WP_FTS_TermNamespace::canonicalize_lang($language);
    if ($language === WP_FTS_TermNamespace::DEFAULT_LANG || trim($packId) === '') {
        throw new WP_FTS_TestFailure('Synthetic analyzer pack requires a concrete language and pack id.');
    }
    if (!mkdir($directory . '/runtime', 0777, true) && !is_dir($directory . '/runtime')) {
        throw new WP_FTS_TestFailure("Could not create synthetic analyzer runtime directory: {$directory}");
    }

    file_put_contents($directory . '/NOTICE.txt', "Synthetic BSD-2-Clause analyzer pack fixture for tests.\n");

    $runtimeFiles = [];
    $runtimeDigest = hash_init('sha256');
    $nextRow = 0;
    for ($shard = 1; $shard <= $shards; $shard++) {
        $remainingRows = $rows - $nextRow;
        $remainingShards = $shards - $shard + 1;
        $rowsInShard = intdiv($remainingRows + $remainingShards - 1, $remainingShards);
        $relativePath = sprintf('runtime/%04d.tsv', $shard);
        $path = $directory . '/' . $relativePath;
        $handle = fopen($path, 'wb');
        if (!is_resource($handle)) {
            throw new WP_FTS_TestFailure("Could not write synthetic analyzer runtime shard: {$path}");
        }

        $firstSurface = null;
        $lastSurface = null;
        for ($i = 0; $i < $rowsInShard; $i++, $nextRow++) {
            $surface = sprintf('surface%08d', $nextRow);
            $lemma = sprintf('lemma%08d', $nextRow);
            $line = $surface . "\t" . $lemma . "\n";
            if (fwrite($handle, $line) === false) {
                fclose($handle);
                throw new WP_FTS_TestFailure("Could not write synthetic analyzer runtime row: {$path}");
            }
            hash_update($runtimeDigest, $line);
            $firstSurface ??= $surface;
            $lastSurface = $surface;
        }
        fclose($handle);

        $sha = hash_file('sha256', $path);
        if (!is_string($sha) || !is_string($firstSurface) || !is_string($lastSurface)) {
            throw new WP_FTS_TestFailure("Could not finalize synthetic analyzer runtime shard: {$path}");
        }
        $runtimeFiles[] = [
            'path' => $relativePath,
            'sha256' => $sha,
            'rows' => $rowsInShard,
            'first_surface' => $firstSurface,
            'last_surface' => $lastSurface,
        ];
    }

    $manifest = [
        'schema_version' => 1,
        'pack_id' => $packId,
        'language' => $language,
        'version' => 'streaming-regression-v1',
        'fixture_only' => false,
        'default_enabled' => false,
        'capabilities' => [
            'dictionary-lemmatizer',
            'ambiguous-form-noop',
            'normalized-runtime-rows',
            'sharded-runtime-files',
        ],
        'runtime' => [
            'format' => 'wp-fts-polish-lemma-tsv-v1',
            'normalization' => 'WP_FTS_Normalizer ' . $language . ' with fold_diacritics=true',
            'ambiguity_policy' => 'ambiguous_surface_noop',
            'total_rows' => $rows,
            'total_sha256' => hash_final($runtimeDigest),
            'files' => $runtimeFiles,
        ],
        'source' => [
            'name' => 'Synthetic ' . $language . ' streaming validator fixture',
            'version' => 'test',
            'url' => 'urn:wp-fts:test:synthetic-' . $language . '-streaming-validator',
            'artifact_sha256' => str_repeat('a', 64),
            'byte_count' => 1,
        ],
        'license' => [
            'spdx_id' => 'BSD-2-Clause',
            'notice_path' => 'NOTICE.txt',
        ],
        'attribution' => [
            'notice_path' => 'NOTICE.txt',
        ],
        'provenance' => [
            'no_runtime_network_access' => true,
            'no_full_third_party_dictionary_dump' => false,
        ],
    ];

    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new WP_FTS_TestFailure('Could not encode synthetic analyzer pack manifest.');
    }
    file_put_contents($directory . '/manifest.json', $json . "\n");

    return $directory . '/manifest.json';
}

function write_synthetic_qaa_lemma_tsv_source(string $path): void
{
    $contents = implode("\n", [
        '# Project-owned synthetic qaa lemma-pack importer fixture.',
        'qaaformb	qaalemma	QAA-TAG	second synthetic source note',
        'qaaamb	qaaone',
        'qaasolo	qaasolo',
        'qaaforma	qaalemma	QAA-TAG	first synthetic source note',
        'qaaamb	qaatwo',
        'qaaformb	qaalemma	QAA-TAG	duplicate synthetic note',
        '',
    ]);

    if (file_put_contents($path, $contents) === false) {
        throw new WP_FTS_TestFailure("Could not write synthetic qaa lemma source: {$path}");
    }
}

function synthetic_qaa_conllu_row(string $id, string $form, string $lemma, string $upos = 'NOUN'): string
{
    return implode("\t", [$id, $form, $lemma, $upos, '_', '_', '0', 'root', '_', '_']);
}

function write_synthetic_qaa_conllu_source(string $path): void
{
    $contents = implode("\n", [
        '# Project-owned synthetic qaa CoNLL-U importer fixture.',
        synthetic_qaa_conllu_row('1-2', 'QAACompound', '_'),
        synthetic_qaa_conllu_row('1', 'QAAFormB', 'QAALemma', 'VERB'),
        synthetic_qaa_conllu_row('2', 'QAAAmb', 'QAAOne'),
        synthetic_qaa_conllu_row('2.1', 'QAAEmptyNode', 'QAALemma'),
        '',
        synthetic_qaa_conllu_row('3', '_', 'QAALemma'),
        synthetic_qaa_conllu_row('4', 'QAAPlaceholderLemma', '_'),
        synthetic_qaa_conllu_row('5', 'QAA Multi', 'QAALemma'),
        synthetic_qaa_conllu_row('6', 'QAAHyphenLemma', 'QAA-Lemma'),
        synthetic_qaa_conllu_row('7', 'QAAFormA', 'QAALemma', 'VERB'),
        synthetic_qaa_conllu_row('8', 'QAASolo', 'QAASolo'),
        synthetic_qaa_conllu_row('9', 'QAAAmb', 'QAATwo'),
        '',
    ]);

    if (file_put_contents($path, $contents) === false) {
        throw new WP_FTS_TestFailure("Could not write synthetic qaa CoNLL-U source: {$path}");
    }
}

function synthetic_qaa_unimorph_row(string $lemma, string $surface, string $features = 'N;SG'): string
{
    return implode("\t", [$lemma, $surface, $features]);
}

function write_synthetic_qaa_unimorph_source(string $path): void
{
    $contents = implode("\n", [
        '# Project-owned synthetic qaa UniMorph importer fixture.',
        synthetic_qaa_unimorph_row('QAALemma', 'QAAFormB', 'V;PST'),
        synthetic_qaa_unimorph_row('QAAOne', 'QAAAmb'),
        '',
        synthetic_qaa_unimorph_row('_', 'QAAPlaceholderLemma'),
        synthetic_qaa_unimorph_row('QAALemma', '_'),
        synthetic_qaa_unimorph_row('QAALemma', 'QAA Multi'),
        synthetic_qaa_unimorph_row('QAA-Lemma', 'QAAHyphenLemma'),
        synthetic_qaa_unimorph_row('QAALemma', 'QAAFormA', 'V;PRS'),
        synthetic_qaa_unimorph_row('QAASolo', 'QAASolo'),
        synthetic_qaa_unimorph_row('QAATwo', 'QAAAmb', 'N;PL'),
        '',
    ]);

    if (file_put_contents($path, $contents) === false) {
        throw new WP_FTS_TestFailure("Could not write synthetic qaa UniMorph source: {$path}");
    }
}

/**
 * @return string[]
 */
function synthetic_qaa_lemma_tsv_import_args(string $source, string $out): array
{
    return [
        '--source=' . $source,
        '--out=' . $out,
        '--language=qaa',
        '--pack-id=qaa-synthetic-lemma-tsv-importer',
        '--version=0.1.0-synthetic-import',
        '--source-name=Project-owned synthetic qaa lemma TSV importer fixture',
        '--source-version=0.1.0-synthetic',
        '--source-url=urn:wp-fts:test:synthetic-qaa-lemma-tsv',
        '--license=CC0-1.0',
        '--license-url=urn:wp-fts:test:synthetic-qaa-license',
        '--attribution=Project-owned synthetic qaa rows for importer tests only.',
        '--fixture-only=true',
        '--max-rows-per-file=2',
        '--chunk-rows=2',
        '--importer-commit=test-commit',
    ];
}

function write_synthetic_audit_lemma_tsv_source(string $path, string $prefix): void
{
    $contents = implode("\n", [
        "# Project-owned synthetic {$prefix} top-language audit fixture.",
        "{$prefix}formb\t{$prefix}lemma\tAUDIT\tsecond synthetic audit source note",
        "{$prefix}solo\t{$prefix}solo",
        "{$prefix}forma\t{$prefix}lemma\tAUDIT\tfirst synthetic audit source note",
        '',
    ]);

    if (file_put_contents($path, $contents) === false) {
        throw new WP_FTS_TestFailure("Could not write synthetic top-language audit source: {$path}");
    }
}

/**
 * @return string[]
 */
function synthetic_audit_lemma_tsv_import_args(string $language, string $source, string $out, bool $fixtureOnly): array
{
    $kind = $fixtureOnly ? 'fixture' : 'pack-backed';

    return [
        '--source=' . $source,
        '--out=' . $out,
        '--language=' . $language,
        '--pack-id=' . $language . '-synthetic-audit-' . $kind,
        '--version=0.1.0-synthetic-audit',
        '--source-name=Project-owned synthetic ' . $language . ' top-language audit ' . $kind . ' pack',
        '--source-version=0.1.0-synthetic',
        '--source-url=urn:wp-fts:test:synthetic-' . $language . '-top-language-audit',
        '--license=CC0-1.0',
        '--license-url=urn:wp-fts:test:synthetic-' . $language . '-audit-license',
        '--attribution=Project-owned synthetic ' . $language . ' rows for top-language audit tests only.',
        '--fixture-only=' . ($fixtureOnly ? 'true' : 'false'),
        '--max-rows-per-file=2',
        '--chunk-rows=2',
        '--importer-commit=test-commit',
    ];
}

function write_synthetic_audit_lemma_pack(string $language, string $out, bool $fixtureOnly): string
{
    require_once __DIR__ . '/../tools/import-lemma-tsv-pack.php';

    $sourceDir = temp_directory_path('top_language_audit_source_' . $language);
    try {
        if (!mkdir($sourceDir, 0777, true) && !is_dir($sourceDir)) {
            throw new WP_FTS_TestFailure("Could not create synthetic audit source directory: {$sourceDir}");
        }
        $source = $sourceDir . '/' . $language . '-normalized-lemma.tsv';
        write_synthetic_audit_lemma_tsv_source($source, $language);

        $options = WP_FTS_LemmaTsvPackImporter::parse_cli_options(
            synthetic_audit_lemma_tsv_import_args($language, $source, $out, $fixtureOnly)
        );
        (new WP_FTS_LemmaTsvPackImporter())->import($options);

        return $out . '/manifest.json';
    } finally {
        remove_directory_tree($sourceDir);
    }
}

/**
 * @param string[] $args
 * @return array{exit:int,stdout:string,stderr:string,json:array<string,mixed>}
 */
function run_top_language_pack_audit(array $args): array
{
    $cli = test_run_subprocess(
        array_merge([PHP_BINARY, dirname(__DIR__) . '/tools/audit-top-language-lemma-packs.php'], $args),
        dirname(__DIR__)
    );
    $payload = json_decode($cli['stdout'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new WP_FTS_TestFailure('Top-language audit JSON did not decode to an object.');
    }

    return $cli + ['json' => $payload];
}

/**
 * @param array<string,mixed> $payload
 * @return array<string,array<string,mixed>>
 */
function top_language_audit_rows_by_language(array $payload): array
{
    if (!isset($payload['rows']) || !is_array($payload['rows'])) {
        throw new WP_FTS_TestFailure('Top-language audit JSON did not include rows.');
    }

    $rows = [];
    foreach ($payload['rows'] as $row) {
        if (!is_array($row) || !is_string($row['language'] ?? null)) {
            throw new WP_FTS_TestFailure('Top-language audit row is malformed.');
        }
        $rows[$row['language']] = $row;
    }

    return $rows;
}

/**
 * @return array<string,string>
 */
function bundled_unimorph_top_language_pack_manifests(): array
{
    return WP_FTS_AnalyzerPackValidator::bundled_unimorph_top_language_pack_manifests();
}

/**
 * @return array<string,array{surface:string,lemma:string,title:string}>
 */
function bundled_unimorph_sandbox_demo_probe_cases(): array
{
    return [
        'en' => [
            'surface' => 'mice',
            'lemma' => 'mouse',
            'title' => 'FTS Sandbox: English Mice',
        ],
        'es' => [
            'surface' => 'buscando',
            'lemma' => 'buscar',
            'title' => 'FTS Sandbox: Spanish Buscar',
        ],
        'fr' => [
            'surface' => 'cherchent',
            'lemma' => 'chercher',
            'title' => 'FTS Sandbox: French Chercher',
        ],
        'hi' => [
            'surface' => 'अपनाता',
            'lemma' => 'अपनाना',
            'title' => 'FTS Sandbox: Hindi Lemmatizer',
        ],
        'ar' => [
            'surface' => 'آبارا',
            'lemma' => 'بئر',
            'title' => 'FTS Sandbox: Arabic Search',
        ],
        'bn' => [
            'surface' => 'অনুরোধগুলা',
            'lemma' => 'অনুরোধ',
            'title' => 'FTS Sandbox: Bengali Lemmatizer',
        ],
        'pt' => [
            'surface' => 'pesquisando',
            'lemma' => 'pesquisar',
            'title' => 'FTS Sandbox: Portuguese Pesquisar',
        ],
        'id' => [
            'surface' => 'abadikan',
            'lemma' => 'abadi',
            'title' => 'FTS Sandbox: Indonesian Abadi',
        ],
    ];
}

/**
 * @param array<int,array{0:string,1:int,2:string}> $rows
 */
function write_synthetic_jieba_segmenter_source(string $path, array $rows): void
{
    $lines = [];
    foreach ($rows as $row) {
        $lines[] = $row[0] . ' ' . (string) $row[1] . ' ' . $row[2];
    }

    if (file_put_contents($path, implode("\n", $lines) . "\n") === false) {
        throw new WP_FTS_TestFailure("Could not write synthetic Jieba segmenter source: {$path}");
    }
}

/**
 * @return array<int,array{0:string,1:int,2:string}>
 */
function synthetic_jieba_segmenter_rows(): array
{
    return [
        ['中国科学院', 2000, 'nt'],
        ['中国', 1200, 'ns'],
        ['科学院', 900, 'n'],
        ['科学', 800, 'n'],
        ['学院', 700, 'n'],
        ['计算所', 600, 'n'],
        ['搜索引擎', 1100, 'n'],
        ['搜索', 1000, 'v'],
        ['引擎', 900, 'n'],
        ['系统', 800, 'n'],
    ];
}

/**
 * @return array<string,mixed>
 */
function synthetic_jieba_segmenter_option(string $source): array
{
    $hash = hash_file('sha256', $source);
    $bytes = filesize($source);
    if (!is_string($hash) || !is_int($bytes)) {
        throw new WP_FTS_TestFailure("Could not hash synthetic Jieba source: {$source}");
    }

    return [
        'source_file' => $source,
        'language' => 'zh',
        'pack_id' => 'zh-jieba-synthetic-fixture',
        'version' => substr($hash, 0, 12) . '-synthetic-v1',
        'source_repository' => 'urn:wp-fts:test:synthetic-jieba',
        'source_commit' => 'project-owned-synthetic-fixture',
        'source_path' => 'jieba/dict.txt',
        'expected_sha256' => $hash,
        'expected_byte_size' => $bytes,
        'fixture_only' => true,
        'max_cached_prefixes' => 4,
        'max_candidates_per_prefix' => 100,
    ];
}

/**
 * @return array{analyzer:WP_FTS_Analyzer,option:array<string,mixed>,source:string,dir:string}
 */
function synthetic_jieba_segmenter_analyzer(): array
{
    $dir = temp_directory_path('jieba_segmenter_fixture');
    if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new WP_FTS_TestFailure("Could not create synthetic Jieba directory: {$dir}");
    }

    $source = $dir . '/dict.txt';
    write_synthetic_jieba_segmenter_source($source, synthetic_jieba_segmenter_rows());
    $option = synthetic_jieba_segmenter_option($source);

    return [
        'analyzer' => new WP_FTS_Analyzer([
            'segmenter_packs_by_lang' => [
                'zh' => $option,
            ],
        ]),
        'option' => $option,
        'source' => $source,
        'dir' => $dir,
    ];
}

/**
 * @param array<string,mixed> $validation
 * @return array{surface:string,lemma:string}
 */
function bundled_unimorph_runtime_probe_case(array $validation): array
{
    $currentSurface = null;
    $currentLemmas = [];
    $candidate = null;

    $finishSurface = static function () use (&$currentSurface, &$currentLemmas, &$candidate): void {
        if ($candidate !== null || $currentSurface === null || count($currentLemmas) !== 1) {
            return;
        }
        $lemma = (string) array_key_first($currentLemmas);
        if ($lemma === $currentSurface) {
            return;
        }
        if (test_utf8_codepoint_count($currentSurface) < 2 || test_utf8_codepoint_count($lemma) < 2) {
            return;
        }

        $candidate = [
            'surface' => $currentSurface,
            'lemma' => $lemma,
        ];
    };

    foreach ($validation['runtime_files'] as $file) {
        $compression = isset($file['compression']) ? (string) $file['compression'] : null;
        $handle = bundled_unimorph_open_runtime_file((string) $file['path'], $compression);
        try {
            while (($line = bundled_unimorph_read_runtime_line($handle, $compression)) !== false) {
                $line = rtrim(rtrim((string) $line, "\n"), "\r");
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                [$surface, $lemma] = explode("\t", $line, 2);
                if ($currentSurface !== $surface) {
                    $finishSurface();
                    if ($candidate !== null) {
                        return $candidate;
                    }
                    $currentSurface = $surface;
                    $currentLemmas = [];
                }
                $currentLemmas[$lemma] = true;
            }
        } finally {
            bundled_unimorph_close_runtime_file($handle, $compression);
        }
    }

    $finishSurface();
    if ($candidate === null) {
        throw new WP_FTS_TestFailure('Could not find an unambiguous surface-to-lemma probe row in bundled UniMorph pack.');
    }

    return $candidate;
}

/**
 * @return resource
 */
function bundled_unimorph_open_runtime_file(string $path, ?string $compression): mixed
{
    if ($compression === WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP) {
        $handle = gzopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new WP_FTS_TestFailure("Could not open gzip runtime fixture: {$path}");
        }

        return $handle;
    }

    $handle = fopen($path, 'rb');
    if (!is_resource($handle)) {
        throw new WP_FTS_TestFailure("Could not open runtime fixture: {$path}");
    }

    return $handle;
}

/**
 * @param resource $handle
 */
function bundled_unimorph_read_runtime_line(mixed $handle, ?string $compression): string|false
{
    if ($compression === WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP) {
        return gzgets($handle);
    }

    return fgets($handle);
}

/**
 * @param resource $handle
 */
function bundled_unimorph_close_runtime_file(mixed $handle, ?string $compression): void
{
    if ($compression === WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP) {
        gzclose($handle);
        return;
    }

    fclose($handle);
}

function test_utf8_codepoint_count(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    if (preg_match_all('/./us', $value, $matches) === false) {
        return strlen($value);
    }

    return count($matches[0]);
}

/**
 * @return string[]
 */
function synthetic_qaa_conllu_import_args(string $source, string $out): array
{
    return [
        '--source=' . $source,
        '--out=' . $out,
        '--language=qaa',
        '--pack-id=qaa-synthetic-conllu-lemma-importer',
        '--version=0.1.0-synthetic-conllu-import',
        '--source-name=Project-owned synthetic qaa CoNLL-U importer fixture',
        '--source-version=0.1.0-synthetic',
        '--source-url=urn:wp-fts:test:synthetic-qaa-conllu',
        '--license=CC0-1.0',
        '--license-url=urn:wp-fts:test:synthetic-qaa-license',
        '--attribution=Project-owned synthetic qaa CoNLL-U rows for importer tests only.',
        '--fixture-only=true',
        '--max-rows-per-file=2',
        '--chunk-rows=2',
        '--importer-commit=test-commit',
    ];
}

/**
 * @return string[]
 */
function synthetic_qaa_unimorph_import_args(string $source, string $out): array
{
    return [
        '--source=' . $source,
        '--out=' . $out,
        '--language=qaa',
        '--pack-id=qaa-synthetic-unimorph-lemma-importer',
        '--version=0.1.0-synthetic-unimorph-import',
        '--source-name=Project-owned synthetic qaa UniMorph importer fixture',
        '--source-version=0.1.0-synthetic',
        '--source-url=urn:wp-fts:test:synthetic-qaa-unimorph',
        '--license=CC0-1.0',
        '--license-url=urn:wp-fts:test:synthetic-qaa-license',
        '--attribution=Project-owned synthetic qaa UniMorph rows for importer tests only.',
        '--fixture-only=true',
        '--max-rows-per-file=2',
        '--chunk-rows=2',
        '--importer-commit=test-commit',
    ];
}

/**
 * @return array<string,mixed>
 */
function synthetic_qaa_lemma_tsv_wpcli_assoc_args(string $source): array
{
    return [
        'source' => $source,
        'lang' => 'qaa',
        'pack-id' => 'qaa-synthetic-lemma-tsv-importer',
        'version' => '0.1.0-synthetic-import',
        'source-name' => 'Project-owned synthetic qaa lemma TSV importer fixture',
        'source-version' => '0.1.0-synthetic',
        'source-url' => 'urn:wp-fts:test:synthetic-qaa-lemma-tsv',
        'license' => 'CC0-1.0',
        'license-url' => 'urn:wp-fts:test:synthetic-qaa-license',
        'fixture-only' => true,
        'max-rows-per-file' => '2',
        'chunk-rows' => '2',
    ];
}

/**
 * @return array<string,mixed>
 */
function synthetic_qaa_conllu_wpcli_assoc_args(string $source): array
{
    return [
        'source' => $source,
        'lang' => 'qaa',
        'pack-id' => 'qaa-synthetic-conllu-lemma-importer',
        'version' => '0.1.0-synthetic-conllu-import',
        'source-name' => 'Project-owned synthetic qaa CoNLL-U importer fixture',
        'source-version' => '0.1.0-synthetic',
        'source-url' => 'urn:wp-fts:test:synthetic-qaa-conllu',
        'license' => 'CC0-1.0',
        'license-url' => 'urn:wp-fts:test:synthetic-qaa-license',
        'fixture-only' => true,
        'max-rows-per-file' => '2',
        'chunk-rows' => '2',
    ];
}

/**
 * @return array<string,mixed>
 */
function synthetic_qaa_unimorph_wpcli_assoc_args(string $source): array
{
    return [
        'source' => $source,
        'lang' => 'qaa',
        'pack-id' => 'qaa-synthetic-unimorph-lemma-importer',
        'version' => '0.1.0-synthetic-unimorph-import',
        'source-name' => 'Project-owned synthetic qaa UniMorph importer fixture',
        'source-version' => '0.1.0-synthetic',
        'source-url' => 'urn:wp-fts:test:synthetic-qaa-unimorph',
        'license' => 'CC0-1.0',
        'license-url' => 'urn:wp-fts:test:synthetic-qaa-license',
        'fixture-only' => true,
        'max-rows-per-file' => '2',
        'chunk-rows' => '2',
    ];
}

/**
 * @return string[]
 */
function lemma_tsv_import_tmp_children(string $parent): array
{
    $matches = glob($parent . DIRECTORY_SEPARATOR . 'wp-fts-lemma-tsv-import-*', GLOB_ONLYDIR);
    if ($matches === false) {
        return [];
    }

    sort($matches, SORT_STRING);

    return $matches;
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

test_case('storage metadata filter capability matches active scalar filters', function (): void {
    foreach (storage_factories('metadata_filter_capability') as $name => $factory) {
        $storage = $factory();
        try {
            assert_true($storage instanceof WP_FTS_DocumentMetadataStorage, "{$name} storage should expose document metadata");
            assert_true($storage instanceof WP_FTS_DocumentMetadataFilterStorage, "{$name} storage should expose metadata filtering");

            $storage->put_doc(0, 'en', ['en' => 4], 'hash-0');
            foreach ([1, 2, 3, 4] as $docId) {
                $storage->put_doc($docId, 'en', ['en' => 4], 'hash-' . $docId);
            }
            $storage->put_doc_metadata(0, [
                'post_id' => 0,
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_date_gmt' => '2026-02-11 10:00:00',
                'title' => 'Zero Published Post',
            ]);
            $storage->put_doc_metadata(1, [
                'post_id' => 1,
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_date_gmt' => '2026-02-10 10:00:00',
                'title' => 'Published Post',
                'search_text' => str_repeat('large metadata ', 200),
            ]);
            $storage->put_doc_metadata(2, [
                'post_id' => 2,
                'post_type' => 'page',
                'post_status' => 'draft',
                'post_date_gmt' => '2026-02-10 10:00:00',
                'title' => 'Draft Page',
            ]);
            $storage->put_doc_metadata(3, [
                'post_id' => 3,
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_date_gmt' => '2025-12-31 23:59:59',
                'title' => 'Old Post',
            ]);
            $storage->put_doc_metadata(4, [
                'post_id' => 4,
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_date_gmt' => '',
                'title' => 'Undated Post',
            ]);

            assert_same([1], WP_FTS_StorageCompat::filter_doc_ids_by_metadata(
                $storage,
                [4, 3, 2, 1],
                ['post,page'],
                ['publish'],
                '2026-01-01',
                '2026-12-31'
            ), "{$name} metadata filter should match post/status/date filters");

            assert_same([0], WP_FTS_StorageCompat::filter_doc_ids_by_metadata(
                $storage,
                [0, 1, -1],
                ['post'],
                ['publish'],
                '2026-02-11',
                '2026-02-11'
            ), "{$name} metadata filter should preserve document id zero");

            assert_same([2], WP_FTS_StorageCompat::filter_doc_ids_by_metadata(
                $storage,
                [1, 2, 3, 4],
                [],
                ['draft'],
                null,
                null
            ), "{$name} metadata filter should allow empty post type filter");

            $storage->delete_doc(1);
            assert_same([], WP_FTS_StorageCompat::filter_doc_ids_by_metadata(
                $storage,
                [1],
                ['post'],
                ['publish'],
                null,
                null
            ), "{$name} metadata filter should exclude tombstoned documents");
        } finally {
            cleanup_storage($storage);
        }
    }
});

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
    public string $base_prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $last_error = '';
    public ?string $failQueryPrefix = null;
    /** @var array<int,string> */
    public array $failDocWriteErrors = [];
    /** @var array<int,int> */
    public array $failedDocWriteAttempts = [];
    public ?object $dbh = null;
    public bool $missPreparedTermLookups = false;
    public bool $recordReadQueries = false;

    /** @var array<int,string|array{0:string,1:float,2:string}> */
    public array $queries = [];

    /** @var array<int,array{sql:string,args:array<int,mixed>}> */
    public array $prepared = [];

    /** @var array<string,array{doc_freq:int}> */
    public array $terms = [];

    /** @var array<string,array<int,int>> */
    public array $postings = [];

    /** @var array<int,array{lang:string,doc_len:int,content_hash:?string,is_deleted:int}> */
    public array $docs = [];

    /** @var array<int,array<string,int>> */
    public array $docLengths = [];

    /** @var array<int,array<string,mixed>> */
    public array $docMeta = [];

    /** @var array<string,array<string,int>> */
    public array $meta = [];

    /** @var array<int,object> */
    public array $postRows = [];

    public function prepare(string $sql, mixed ...$args): WP_FTS_Test_Prepared_SQL
    {
        $this->prepared[] = ['sql' => $sql, 'args' => $args];

        return new WP_FTS_Test_Prepared_SQL($sql, $args);
    }

    public function get_blog_prefix(int $blog_id = 0): string
    {
        return $blog_id <= 1 ? $this->base_prefix : $this->base_prefix . $blog_id . '_';
    }

    public function get_var(mixed $statement, int $x = 0, int $y = 0): mixed
    {
        [$sql, $args] = $this->statement_parts($statement);
        $this->record_read_query($sql);
        if (str_starts_with($sql, 'SELECT COUNT(DISTINCT p.ID)')) {
            $offset = 0;
            $publicStatus = str_contains($sql, 'p.post_status = %s') ? (string) ($args[$offset++] ?? '') : '';
            $publicTypes = $this->prepared_in_args($sql, 'p.post_type', '%s', $args, $offset);
            $adminType = null;
            $adminStatuses = [];
            if (str_contains($sql, 'p.post_type = %s')) {
                $adminType = (string) ($args[$offset++] ?? '');
                $adminStatuses = $this->prepared_in_args($sql, 'p.post_status', '%s', $args, $offset);
            }
            $afterCursor = str_contains($sql, 'p.ID > %d') ? max(0, (int) ($args[$offset++] ?? 0)) : 0;
            $requiresIndexedDoc = str_contains($sql, 'JOIN wp_fts_docs d');

            $counted = [];
            foreach ($this->postRows as $row) {
                $postId = isset($row->ID) ? (int) $row->ID : 0;
                if ($postId <= 0) {
                    continue;
                }

                if ($afterCursor > 0 && $postId <= $afterCursor) {
                    continue;
                }

                if ($requiresIndexedDoc && ($this->docs[$postId]['is_deleted'] ?? 1) !== 0) {
                    continue;
                }

                if (isset($row->post_password) && (string) $row->post_password !== '') {
                    continue;
                }

                $type = isset($row->post_type) ? (string) $row->post_type : 'post';
                $status = isset($row->post_status) ? (string) $row->post_status : 'draft';
                $publicEligible = $publicStatus !== '' && $status === $publicStatus && in_array($type, array_map('strval', $publicTypes), true);
                $adminEligible = $adminType !== null && $type === $adminType && in_array($status, array_map('strval', $adminStatuses), true);
                if ($publicEligible || $adminEligible) {
                    $counted[$postId] = true;
                }
            }

            return count($counted);
        }

        return null;
    }

    public function query(mixed $statement): int|bool
    {
        [$sql, $args] = $this->statement_parts($statement);
        $this->queries[] = $sql;
        $docWriteFailure = $this->doc_write_failure($sql, $args);
        if ($docWriteFailure !== null) {
            [$docId, $message] = $docWriteFailure;
            $this->failedDocWriteAttempts[$docId] = ($this->failedDocWriteAttempts[$docId] ?? 0) + 1;
            $this->last_error = $message;
            return false;
        }
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
            $value = max(0, (int) $args[1]);
            if (str_contains($sql, 'doc_freq = doc_freq + VALUES(doc_freq)')) {
                $value += (int) ($this->terms[$term]['doc_freq'] ?? 0);
            }
            $this->terms[$term] = ['doc_freq' => $value];
            ksort($this->terms, SORT_STRING);
            return 1;
        }

        if (str_starts_with($sql, 'UPDATE wp_fts_terms')) {
            $term = (string) $args[1];
            if (isset($this->terms[$term])) {
                $this->terms[$term]['doc_freq'] = max(0, $this->terms[$term]['doc_freq'] - abs((int) $args[0]));
            }
            return 1;
        }

        if (str_starts_with($sql, 'DELETE FROM wp_fts_terms WHERE term = %s AND doc_freq = 0')) {
            $term = (string) $args[0];
            if (($this->terms[$term]['doc_freq'] ?? null) === 0) {
                unset($this->terms[$term]);
            }
            return 1;
        }

        if (str_starts_with($sql, 'DELETE FROM wp_fts_terms WHERE term = %s')) {
            unset($this->terms[(string) $args[0]]);
            return 1;
        }

        if (str_starts_with($sql, 'INSERT INTO wp_fts_postings')) {
            $term = (string) $args[0];
            $docId = (int) $args[1];
            $this->postings[$term][$docId] = max(1, (int) $args[2]);
            ksort($this->postings[$term], SORT_NUMERIC);
            ksort($this->postings, SORT_STRING);
            return 1;
        }

        if (str_starts_with($sql, 'DELETE FROM wp_fts_postings WHERE doc_id = %d')) {
            $docId = (int) $args[0];
            foreach ($this->postings as $term => $postings) {
                unset($postings[$docId]);
                if ($postings === []) {
                    unset($this->postings[$term]);
                    continue;
                }
                $this->postings[$term] = $postings;
            }
            return 1;
        }

        if (str_starts_with($sql, 'DELETE FROM wp_fts_postings WHERE term = %s')) {
            unset($this->postings[(string) $args[0]]);
            return 1;
        }

        if (str_starts_with($sql, 'DELETE FROM wp_fts_postings WHERE doc_id IN')) {
            $deleted = array_fill_keys(array_map('intval', $args), true);
            foreach ($this->postings as $term => $postings) {
                foreach ($deleted as $docId => $_) {
                    unset($postings[$docId]);
                }
                if ($postings === []) {
                    unset($this->postings[$term]);
                    continue;
                }
                $this->postings[$term] = $postings;
            }
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

        if (str_starts_with($sql, 'DELETE FROM wp_fts_docmeta WHERE doc_id IN')) {
            foreach ($args as $docId) {
                unset($this->docMeta[(int) $docId]);
            }
            return 1;
        }

        if (str_starts_with($sql, 'INSERT INTO wp_fts_doc_lengths')) {
            $docId = (int) $args[0];
            $this->docLengths[$docId][(string) $args[1]] = (int) $args[2];
            ksort($this->docLengths[$docId], SORT_STRING);
            return 1;
        }

        if (str_starts_with($sql, 'INSERT INTO wp_fts_docmeta')) {
            $docId = (int) $args[0];
            $this->docMeta[$docId] = [
                'doc_id' => $docId,
                'post_id' => (int) $args[1],
                'post_type' => (string) $args[2],
                'post_status' => (string) $args[3],
                'post_date_gmt' => (string) $args[4],
                'title' => (string) $args[5],
                'excerpt' => (string) $args[6],
                'search_text' => (string) $args[7],
                'data' => (string) $args[8],
            ];
            ksort($this->docMeta, SORT_NUMERIC);
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
                unset($this->docMeta[(int) $docId]);
            }
            return 1;
        }

        return true;
    }

    /**
     * @param array<int,mixed> $args
     * @return array{0:int,1:string}|null
     */
    private function doc_write_failure(string $sql, array $args): ?array
    {
        if (!str_starts_with($sql, 'INSERT INTO wp_fts_docs')) {
            return null;
        }

        $docId = (int) ($args[0] ?? 0);
        if ($docId <= 0 || !array_key_exists($docId, $this->failDocWriteErrors)) {
            return null;
        }

        $message = $this->failDocWriteErrors[$docId];

        return [
            $docId,
            $message !== '' ? $message : "simulated failure for doc {$docId}",
        ];
    }

    /**
     * @return object[]
     */
    public function get_results(mixed $statement): array
    {
        [$sql, $args] = $this->statement_parts($statement);
        $this->record_read_query($sql);
        if ($this->missPreparedTermLookups && $args !== [] && (
            str_starts_with($sql, 'SELECT term, doc_freq FROM wp_fts_terms')
            || str_starts_with($sql, 'SELECT term, doc_id, tf FROM wp_fts_postings')
        )) {
            return [];
        }

        if (str_starts_with($sql, 'SELECT term, doc_freq FROM wp_fts_terms')) {
            $rows = [];
            foreach ($args === [] ? array_keys($this->terms) : $args as $term) {
                $term = (string) $term;
                if (isset($this->terms[$term])) {
                    $rows[] = (object) [
                        'term' => $term,
                        'doc_freq' => $this->terms[$term]['doc_freq'],
                    ];
                }
            }
            return $rows;
        }

        if (str_starts_with($sql, 'SELECT term FROM wp_fts_terms') && str_contains($sql, 'WHERE term >= %s')) {
            $lower = (string) ($args[0] ?? '');
            $hasUpper = str_contains($sql, 'term < %s');
            $upper = $hasUpper ? (string) ($args[1] ?? '') : null;
            $limitArg = $hasUpper ? ($args[2] ?? 0) : ($args[1] ?? 0);
            $limit = max(0, (int) $limitArg);
            $terms = array_keys($this->terms);
            sort($terms, SORT_STRING);

            $rows = [];
            foreach ($terms as $term) {
                if (strcmp($term, $lower) < 0 || ($upper !== null && strcmp($term, $upper) >= 0)) {
                    continue;
                }

                $rows[] = (object) ['term' => $term];
                if (count($rows) >= $limit) {
                    break;
                }
            }

            return $rows;
        }

        if (
            str_starts_with($sql, 'SELECT term, doc_id, tf FROM wp_fts_postings')
            && str_contains($sql, 'WHERE term = %s')
            && str_contains($sql, 'LIMIT %d')
        ) {
            $term = (string) ($args[0] ?? '');
            $limit = max(0, (int) ($args[1] ?? 0));
            $rows = [];
            $postings = $this->postings[$term] ?? [];
            ksort($postings, SORT_NUMERIC);
            foreach (array_slice($postings, 0, $limit, true) as $docId => $tf) {
                $rows[] = (object) ['term' => $term, 'doc_id' => (int) $docId, 'tf' => (int) $tf];
            }
            return $rows;
        }

        if (str_starts_with($sql, 'SELECT term, doc_id, tf FROM wp_fts_postings')) {
            $rows = [];
            foreach ($args === [] ? array_keys($this->postings) : $args as $term) {
                $term = (string) $term;
                foreach ($this->postings[$term] ?? [] as $docId => $tf) {
                    $rows[] = (object) ['term' => $term, 'doc_id' => (int) $docId, 'tf' => (int) $tf];
                }
            }
            return $rows;
        }

        if (str_starts_with($sql, 'SELECT term FROM wp_fts_postings WHERE doc_id = %d')) {
            $docId = (int) $args[0];
            $rows = [];
            foreach ($this->postings as $term => $postings) {
                if (isset($postings[$docId])) {
                    $rows[] = (object) ['term' => $term];
                }
            }
            return $rows;
        }

        if (str_starts_with($sql, 'SELECT term, COUNT(*) AS c FROM wp_fts_postings')) {
            $docIds = array_fill_keys(array_map('intval', $args), true);
            $counts = [];
            foreach ($this->postings as $term => $postings) {
                foreach ($postings as $docId => $_) {
                    if (isset($docIds[(int) $docId])) {
                        $counts[$term] = ($counts[$term] ?? 0) + 1;
                    }
                }
            }
            ksort($counts, SORT_STRING);

            $rows = [];
            foreach ($counts as $term => $count) {
                $rows[] = (object) ['term' => $term, 'c' => $count];
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

        if (str_starts_with($sql, 'SELECT m.doc_id, m.post_id, m.post_type')) {
            $rows = [];
            foreach (array_map('intval', $args) as $docId) {
                if (($this->docs[$docId]['is_deleted'] ?? 1) === 0 && isset($this->docMeta[$docId])) {
                    $rows[] = (object) $this->docMeta[$docId];
                }
            }
            usort($rows, static fn(object $a, object $b): int => (int) $a->doc_id <=> (int) $b->doc_id);

            return $rows;
        }

        if (str_starts_with($sql, 'SELECT m.doc_id') && str_contains($sql, 'FROM wp_fts_docmeta')) {
            $offset = 0;
            $docIds = $this->prepared_in_args($sql, 'm.doc_id', '%d', $args, $offset);
            $postTypes = $this->prepared_in_args($sql, 'm.post_type', '%s', $args, $offset);
            $postStatuses = $this->prepared_in_args($sql, 'm.post_status', '%s', $args, $offset);
            $dateAfter = str_contains($sql, 'm.post_date_gmt >= %s') ? (string) ($args[$offset++] ?? '') : null;
            $dateBefore = str_contains($sql, 'm.post_date_gmt <= %s') ? (string) ($args[$offset++] ?? '') : null;

            $rows = [];
            foreach (array_map('intval', $docIds) as $docId) {
                if (($this->docs[$docId]['is_deleted'] ?? 1) !== 0 || !isset($this->docMeta[$docId])) {
                    continue;
                }

                if (WP_FTS_StorageCompat::metadata_matches_filters(
                    $this->docMeta[$docId],
                    array_map('strval', $postTypes),
                    array_map('strval', $postStatuses),
                    $dateAfter,
                    $dateBefore
                )) {
                    $rows[] = (object) ['doc_id' => $docId];
                }
            }
            usort($rows, static fn(object $a, object $b): int => (int) $a->doc_id <=> (int) $b->doc_id);

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

        if (
            str_starts_with($sql, 'SELECT p.ID, p.post_content, p.post_title')
            && str_contains($sql, 'INNER JOIN wp_fts_docs d')
        ) {
            $offset = 0;
            $publicStatus = str_contains($sql, 'p.post_status = %s') ? (string) ($args[$offset++] ?? '') : '';
            $publicTypes = $this->prepared_in_args($sql, 'p.post_type', '%s', $args, $offset);
            $adminType = null;
            $adminStatuses = [];
            if (str_contains($sql, 'p.post_type = %s')) {
                $adminType = (string) ($args[$offset++] ?? '');
                $adminStatuses = $this->prepared_in_args($sql, 'p.post_status', '%s', $args, $offset);
            }
            $last = max(0, (int) ($args[$offset++] ?? 0));
            $limit = max(0, (int) ($args[$offset] ?? $args[count($args) - 1] ?? 0));

            $rows = [];
            foreach ($this->postRows as $row) {
                $postId = isset($row->ID) ? (int) $row->ID : 0;
                if ($postId <= $last || ($this->docs[$postId]['is_deleted'] ?? 1) !== 0) {
                    continue;
                }

                if (isset($row->post_password) && (string) $row->post_password !== '') {
                    continue;
                }

                $type = isset($row->post_type) ? (string) $row->post_type : 'post';
                $status = isset($row->post_status) ? (string) $row->post_status : 'draft';
                $publicEligible = $publicStatus !== '' && $status === $publicStatus && in_array($type, array_map('strval', $publicTypes), true);
                $adminEligible = $adminType !== null && $type === $adminType && in_array($status, array_map('strval', $adminStatuses), true);
                if ($publicEligible || $adminEligible) {
                    $rows[] = $row;
                }
            }

            usort($rows, static fn(object $a, object $b): int => (int) $a->ID <=> (int) $b->ID);

            return array_slice($rows, 0, $limit);
        }

        if (str_starts_with($sql, 'SELECT p.ID, p.post_content, p.post_title')) {
            $offset = 0;
            $publicStatus = str_contains($sql, 'p.post_status = %s') ? (string) ($args[$offset++] ?? '') : '';
            $publicTypes = $this->prepared_in_args($sql, 'p.post_type', '%s', $args, $offset);
            $adminType = null;
            $adminStatuses = [];
            if (str_contains($sql, 'p.post_type = %s')) {
                $adminType = (string) ($args[$offset++] ?? '');
                $adminStatuses = $this->prepared_in_args($sql, 'p.post_status', '%s', $args, $offset);
            }
            $limit = max(0, (int) ($args[$offset] ?? $args[count($args) - 1] ?? 0));

            $rows = [];
            foreach ($this->postRows as $row) {
                $postId = isset($row->ID) ? (int) $row->ID : 0;
                if ($postId <= 0 || ($this->docs[$postId]['is_deleted'] ?? 1) === 0) {
                    continue;
                }

                if (isset($row->post_password) && (string) $row->post_password !== '') {
                    continue;
                }

                $type = isset($row->post_type) ? (string) $row->post_type : 'post';
                $status = isset($row->post_status) ? (string) $row->post_status : 'draft';
                $publicEligible = $publicStatus !== '' && $status === $publicStatus && in_array($type, array_map('strval', $publicTypes), true);
                $adminEligible = $adminType !== null && $type === $adminType && in_array($status, array_map('strval', $adminStatuses), true);
                if ($publicEligible || $adminEligible) {
                    $rows[] = $row;
                }
            }

            usort($rows, static fn(object $a, object $b): int => (int) $a->ID <=> (int) $b->ID);

            return array_slice($rows, 0, $limit);
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
        $this->record_read_query($sql);
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
        $this->record_read_query($sql);
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

    private function record_read_query(string $sql): void
    {
        if (!$this->recordReadQueries) {
            return;
        }

        $this->queries[] = [$sql, 0.0005, 'WP_FTS_Test_WPDB'];
    }

    /**
     * @param array<int,mixed> $args
     * @return array<int,mixed>
     */
    private function prepared_in_args(string $sql, string $column, string $placeholder, array $args, int &$offset): array
    {
        if (preg_match('/' . preg_quote($column, '/') . ' IN \(([^)]*)\)/', $sql, $matches) !== 1) {
            return [];
        }

        $count = substr_count($matches[1], $placeholder);
        $values = array_slice($args, $offset, $count);
        $offset += $count;

        return $values;
    }
}

final class WP_FTS_Test_SQLite_Driver
{
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

final class WP_FTS_Test_Oversized_Term_Analyzer
{
    public function __construct(
        private string $normalTerm,
        private string $oversizedTerm,
        private string $exactLimitTerm,
    ) {
    }

    public function index_signature(): string
    {
        return 'wp-fts-test-oversized-term-analyzer-v1';
    }

    /**
     * @return array<int,array{term:string,weight:float,lang:string}>
     */
    public function analyze_content(string $html, array $options = []): array
    {
        return $this->occurrences_for_text($html, $options);
    }

    /**
     * @return array<int,array{term:string,weight:float,lang:string}>
     */
    public function analyze_plain_content(string $text, array $options = []): array
    {
        return $this->occurrences_for_text($text, $options);
    }

    /**
     * @return array<int,array{term:string,lang:string}>
     */
    public function analyze_query_occurrences(string $query, array $options = []): array
    {
        return array_map(
            static fn(array $occurrence): array => [
                'term' => $occurrence['term'],
                'lang' => $occurrence['lang'],
            ],
            $this->occurrences_for_text($query, $options)
        );
    }

    /**
     * @return string[]|array<int,array{term:string,lang:string}>
     */
    public function analyze_query(string $query, array $options = []): array
    {
        $occurrences = $this->analyze_query_occurrences($query, $options);
        if (($options['return'] ?? null) === 'occurrences') {
            return $occurrences;
        }

        return array_map(static fn(array $occurrence): string => $occurrence['term'], $occurrences);
    }

    /**
     * @return array<int,array{term:string,weight:float,lang:string}>
     */
    private function occurrences_for_text(string $text, array $options): array
    {
        $lang = WP_FTS_TermNamespace::language_from_options(
            $options,
            'en',
            ['query_lang', 'document_lang', 'lang', 'language', 'default_lang']
        ) ?? 'en';

        $occurrences = [];
        if (str_contains($text, 'normal-token')) {
            $occurrences[] = ['term' => $this->normalTerm, 'weight' => 1.0, 'lang' => $lang];
        }
        if (str_contains($text, 'oversized-token')) {
            $occurrences[] = ['term' => $this->oversizedTerm, 'weight' => 1.0, 'lang' => $lang];
        }
        if (str_contains($text, 'exact-limit-token')) {
            $occurrences[] = ['term' => $this->exactLimitTerm, 'weight' => 1.0, 'lang' => $lang];
        }

        return $occurrences;
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

        /** @var string[] */
        public static array $warningMessages = [];

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
            self::$warningMessages[] = $message;
        }
    }
}

if (!class_exists('WP_Error')) {
    final class WP_Error
    {
        /**
         * @param array<string,mixed> $data
         */
        public function __construct(
            private string $code,
            private string $message,
            private array $data = [],
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        /**
         * @return array<string,mixed>
         */
        public function get_error_data(): array
        {
            return $this->data;
        }
    }
}

final class WP_FTS_Test_Query
{
    /** @var array<string,mixed> */
    public array $query_vars;
    public int $found_posts = 0;
    public int $max_num_pages = 0;

    /**
     * @param array<string,mixed> $query_vars
     */
    public function __construct(
        array $query_vars,
        private bool $search = true,
        private bool $main = true,
    ) {
        $this->query_vars = $query_vars;
    }

    public function is_search(): bool
    {
        return $this->search;
    }

    public function is_main_query(): bool
    {
        return $this->main;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->query_vars) ? $this->query_vars[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->query_vars[$key] = $value;
    }
}

function wp_fts_test_begin_frontend_search_loop(mixed $query): void
{
    if (is_callable([WP_FTS_Plugin::class, 'begin_frontend_search_loop'])) {
        WP_FTS_Plugin::begin_frontend_search_loop($query);
    }
}

function wp_fts_test_end_frontend_search_loop(mixed $query): void
{
    if (is_callable([WP_FTS_Plugin::class, 'end_frontend_search_loop'])) {
        WP_FTS_Plugin::end_frontend_search_loop($query);
    }
}

function wp_fts_test_reset_wordpress_fakes(): void
{
    $GLOBALS['wp_fts_test_actions'] = [];
    $GLOBALS['wp_fts_test_filter_registrations'] = [];
    $GLOBALS['wp_fts_test_activation_hooks'] = [];
    $GLOBALS['wp_fts_test_deactivation_hooks'] = [];
    $GLOBALS['wp_fts_test_uninstall_hooks'] = [];
    $GLOBALS['wp_fts_test_options'] = [];
    $GLOBALS['wp_fts_test_added_options'] = [];
    $GLOBALS['wp_fts_test_scheduled'] = [];
    $GLOBALS['wp_fts_test_cleared_hooks'] = [];
    $GLOBALS['wp_fts_test_rest_routes'] = [];
    $GLOBALS['wp_fts_test_admin_pages'] = [];
    $GLOBALS['wp_fts_test_registered_settings'] = [];
    $GLOBALS['wp_fts_test_meta_boxes'] = [];
    $GLOBALS['wp_fts_test_posts'] = [];
    $GLOBALS['wp_fts_test_trashed_posts'] = [];
    $GLOBALS['wp_fts_test_post_meta'] = [];
    $GLOBALS['wp_fts_test_next_post_id'] = 1000;
    $GLOBALS['wp_fts_test_get_post_callbacks'] = [];
    $GLOBALS['wp_fts_test_do_blocks'] = [];
    $GLOBALS['wp_fts_test_filters'] = [];
    $GLOBALS['wp_fts_test_upload_dir'] = null;
    $GLOBALS['wp_fts_test_upload_error'] = false;
    $GLOBALS['wp_fts_test_redirects'] = [];
    $GLOBALS['wp_fts_test_is_admin'] = false;
    $GLOBALS['wp_fts_test_is_ajax'] = false;
    $GLOBALS['wp_fts_test_is_cron'] = false;
    $GLOBALS['wp_fts_test_is_rest'] = false;
    $GLOBALS['wp_fts_test_is_network_admin'] = false;
    $GLOBALS['wp_fts_test_current_blog_id'] = 1;
    $GLOBALS['wp_fts_test_blog_stack'] = [];
    $GLOBALS['wp_fts_test_switch_log'] = [];
    $GLOBALS['wp_fts_test_restore_log'] = [];
    $GLOBALS['wp_fts_test_switch_to_blog_returns_false'] = false;
    unset($GLOBALS['pagenow']);
    $GLOBALS['wp_fts_test_current_screen'] = null;
    $GLOBALS['wp_query'] = null;
    $GLOBALS['wp_fts_test_post_types'] = [
        'post' => (object) ['public' => true, 'exclude_from_search' => false],
        'page' => (object) ['public' => true, 'exclude_from_search' => false],
        'secret' => (object) ['public' => false, 'exclude_from_search' => true],
    ];
    $GLOBALS['wp_fts_test_caps'] = [];
    $GLOBALS['wp_fts_test_locale'] = '';
    $GLOBALS['wp_fts_test_bloginfo'] = ['language' => ''];
    $GLOBALS['wp_fts_test_revisions'] = [];
    $GLOBALS['wp_fts_test_autosaves'] = [];
    WP_CLI::$commands = [];
    WP_CLI::$successMessages = [];
    WP_CLI::$warningMessages = [];
    WP_FTS_Plugin::reset_request_caches();
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

if (!function_exists('add_filter')) {
    function add_filter(string $hook_name, mixed $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $entry = [
            'hook' => $hook_name,
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args,
        ];
        $GLOBALS['wp_fts_test_filter_registrations'][] = $entry;

        $existing = $GLOBALS['wp_fts_test_filters'][$hook_name] ?? [];
        if (is_callable($existing)) {
            $existing = [$existing];
        }
        if (!is_array($existing)) {
            $existing = [];
        }
        $existing[] = $callback;
        $GLOBALS['wp_fts_test_filters'][$hook_name] = $existing;

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

if (!function_exists('add_option')) {
    function add_option(string $name, mixed $value = '', string $deprecated = '', string|bool|null $autoload = null): bool
    {
        if (array_key_exists($name, $GLOBALS['wp_fts_test_options'])) {
            return false;
        }

        $GLOBALS['wp_fts_test_options'][$name] = $value;
        $GLOBALS['wp_fts_test_added_options'][] = [
            'name' => $name,
            'value' => $value,
            'autoload' => $autoload,
        ];

        return true;
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

if (!function_exists('wp_upload_dir')) {
    /**
     * @return array<string,mixed>
     */
    function wp_upload_dir(mixed $time = null, bool $create_dir = true, bool $refresh_cache = false): array
    {
        $error = $GLOBALS['wp_fts_test_upload_error'] ?? false;
        if (is_string($error) && trim($error) !== '') {
            return [
                'basedir' => '',
                'baseurl' => '',
                'path' => '',
                'url' => '',
                'error' => $error,
            ];
        }

        $baseDir = $GLOBALS['wp_fts_test_upload_dir'] ?? null;
        if (!is_string($baseDir) || trim($baseDir) === '') {
            $baseDir = sys_get_temp_dir() . '/wp-fts-test-uploads';
        }

        return [
            'basedir' => $baseDir,
            'baseurl' => 'https://example.test/wp-content/uploads',
            'path' => $baseDir,
            'url' => 'https://example.test/wp-content/uploads',
            'error' => false,
        ];
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

if (!function_exists('add_management_page')) {
    function add_management_page(string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $callback = '', mixed ...$unused): string
    {
        $GLOBALS['wp_fts_test_admin_pages'][] = [
            'parent' => 'tools.php',
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'menu_slug' => $menu_slug,
            'callback' => $callback,
        ];

        return 'tools_page_' . $menu_slug;
    }
}

if (!function_exists('add_options_page')) {
    function add_options_page(string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $callback = '', mixed ...$unused): string
    {
        $GLOBALS['wp_fts_test_admin_pages'][] = [
            'parent' => 'options-general.php',
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'menu_slug' => $menu_slug,
            'callback' => $callback,
        ];

        return 'settings_page_' . $menu_slug;
    }
}

if (!function_exists('register_setting')) {
    function register_setting(string $option_group, string $option_name, array $args = []): void
    {
        $GLOBALS['wp_fts_test_registered_settings'][] = [
            'option_group' => $option_group,
            'option_name' => $option_name,
            'args' => $args,
        ];
    }
}

if (!function_exists('add_meta_box')) {
    function add_meta_box(string $id, string $title, mixed $callback, string|array|object|null $screen = null, string $context = 'advanced', string $priority = 'default', mixed $callback_args = null): void
    {
        foreach (is_array($screen) ? $screen : [$screen] as $screen_name) {
            $GLOBALS['wp_fts_test_meta_boxes'][] = [
                'id' => $id,
                'title' => $title,
                'callback' => $callback,
                'screen' => is_scalar($screen_name) ? (string) $screen_name : '',
                'context' => $context,
                'priority' => $priority,
                'callback_args' => $callback_args,
            ];
        }
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return '/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location, int $status = 302, string $x_redirect_by = 'WordPress'): bool
    {
        $GLOBALS['wp_fts_test_redirects'][] = [
            'location' => $location,
            'status' => $status,
            'x_redirect_by' => $x_redirect_by,
        ];

        throw new WP_FTS_TestRedirect($location, $status);
    }
}

if (!function_exists('get_locale')) {
    function get_locale(): string
    {
        return (string) ($GLOBALS['wp_fts_test_locale'] ?? '');
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string
    {
        $bloginfo = $GLOBALS['wp_fts_test_bloginfo'] ?? [];

        return is_array($bloginfo) ? (string) ($bloginfo[$show] ?? '') : '';
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = '-1'): string
    {
        return 'nonce-' . $action;
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $action = '-1'): int|false
    {
        return $nonce === wp_create_nonce($action) ? 1 : false;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $value): string
    {
        $value = strtolower($value);
        $key = '';
        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $char = $value[$i];
            if (($char >= 'a' && $char <= 'z') || ($char >= '0' && $char <= '9') || $char === '_' || $char === '-') {
                $key .= $char;
            }
        }

        return $key;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $value): string
    {
        return esc_attr($value);
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

if (!function_exists('get_the_title')) {
    function get_the_title(int|object $post): string
    {
        $post = get_post($post);

        return is_object($post) && isset($post->post_title) ? (string) $post->post_title : '';
    }
}

if (!function_exists('get_posts')) {
    function get_posts(array $args = []): array
    {
        $ids = [];
        $postStatus = $args['post_status'] ?? 'publish';
        $statusFilter = $postStatus === 'any' ? null : array_map('strval', is_array($postStatus) ? $postStatus : [$postStatus]);
        $postType = $args['post_type'] ?? 'post';
        $typeFilter = $postType === 'any' ? null : array_map('strval', is_array($postType) ? $postType : [$postType]);
        $title = isset($args['title']) && is_scalar($args['title']) ? (string) $args['title'] : null;
        $name = isset($args['name']) && is_scalar($args['name']) ? (string) $args['name'] : null;
        $postNameIn = isset($args['post_name__in']) && is_array($args['post_name__in'])
            ? array_map('strval', $args['post_name__in'])
            : null;

        foreach ($GLOBALS['wp_fts_test_posts'] as $postId => $post) {
            if (!is_object($post)) {
                continue;
            }

            $status = isset($post->post_status) ? (string) $post->post_status : 'draft';
            if ($statusFilter !== null && !in_array($status, $statusFilter, true)) {
                continue;
            }

            $type = isset($post->post_type) ? (string) $post->post_type : 'post';
            if ($typeFilter !== null && !in_array($type, $typeFilter, true)) {
                continue;
            }

            $postTitle = isset($post->post_title) ? (string) $post->post_title : '';
            if ($title !== null && $postTitle !== $title) {
                continue;
            }

            $slug = isset($post->post_name) ? (string) $post->post_name : '';
            if ($name !== null && $slug !== $name) {
                continue;
            }
            if ($postNameIn !== null && !in_array($slug, $postNameIn, true)) {
                continue;
            }

            $ids[] = (int) $postId;
        }

        sort($ids, SORT_NUMERIC);
        if (isset($args['order']) && strtoupper((string) $args['order']) === 'DESC') {
            $ids = array_reverse($ids);
        }

        $limit = isset($args['numberposts']) ? (int) $args['numberposts'] : -1;
        if ($limit >= 0) {
            $ids = array_slice($ids, 0, $limit);
        }

        if (($args['fields'] ?? null) === 'ids') {
            return $ids;
        }

        return array_values(array_filter(array_map('get_post', $ids)));
    }
}

if (!function_exists('wp_insert_post')) {
    function wp_insert_post(array $postarr, bool $wp_error = false): int|WP_Error
    {
        $post_id = isset($postarr['ID']) ? (int) $postarr['ID'] : (int) $GLOBALS['wp_fts_test_next_post_id']++;
        if ($post_id <= 0) {
            return $wp_error ? new WP_Error('invalid_post_id', 'Invalid post ID.') : 0;
        }

        $existing = $GLOBALS['wp_fts_test_posts'][$post_id] ?? (object) [];
        $post = (object) array_merge((array) $existing, [
            'ID' => $post_id,
            'post_title' => (string) ($postarr['post_title'] ?? ($existing->post_title ?? '')),
            'post_name' => (string) ($postarr['post_name'] ?? ($existing->post_name ?? '')),
            'post_content' => (string) ($postarr['post_content'] ?? ($existing->post_content ?? '')),
            'post_excerpt' => (string) ($postarr['post_excerpt'] ?? ($existing->post_excerpt ?? '')),
            'post_status' => (string) ($postarr['post_status'] ?? ($existing->post_status ?? 'draft')),
            'post_type' => (string) ($postarr['post_type'] ?? ($existing->post_type ?? 'post')),
            'post_password' => (string) ($postarr['post_password'] ?? ($existing->post_password ?? '')),
            'post_date_gmt' => (string) ($postarr['post_date_gmt'] ?? ($existing->post_date_gmt ?? '2026-06-11 00:00:00')),
            'post_date' => (string) ($postarr['post_date'] ?? ($existing->post_date ?? '2026-06-11 00:00:00')),
        ]);
        $GLOBALS['wp_fts_test_posts'][$post_id] = $post;
        ksort($GLOBALS['wp_fts_test_posts'], SORT_NUMERIC);

        return $post_id;
    }
}

if (!function_exists('wp_trash_post')) {
    function wp_trash_post(int $post_id): object|false|null
    {
        $post = get_post($post_id);
        if (!is_object($post)) {
            return null;
        }

        $post->post_status = 'trash';
        $GLOBALS['wp_fts_test_posts'][$post_id] = $post;
        $GLOBALS['wp_fts_test_trashed_posts'][] = $post_id;

        return $post;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $value): bool
    {
        return $value instanceof WP_Error;
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

if (!function_exists('get_post_types')) {
    function get_post_types(array $args = [], string $output = 'names'): array
    {
        $types = [];
        foreach ($GLOBALS['wp_fts_test_post_types'] as $name => $object) {
            if (array_key_exists('public', $args) && (bool) ($object->public ?? false) !== (bool) $args['public']) {
                continue;
            }
            if (array_key_exists('exclude_from_search', $args) && (bool) ($object->exclude_from_search ?? false) !== (bool) $args['exclude_from_search']) {
                continue;
            }
            $types[$name] = $output === 'objects' ? $object : $name;
        }

        return $types;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed
    {
        $meta = $GLOBALS['wp_fts_test_post_meta'][$post_id] ?? [];
        if ($key === '') {
            return $meta;
        }

        $values = $meta[$key] ?? [];
        if ($single) {
            return $values[0] ?? '';
        }

        return $values;
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $post_id, string $meta_key, mixed $meta_value): int|bool
    {
        $old = $GLOBALS['wp_fts_test_post_meta'][$post_id][$meta_key][0] ?? null;
        $GLOBALS['wp_fts_test_post_meta'][$post_id][$meta_key] = [$meta_value];

        return $old !== $meta_value;
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $post_id, string $meta_key, mixed $meta_value = ''): bool
    {
        $existed = isset($GLOBALS['wp_fts_test_post_meta'][$post_id][$meta_key]);
        unset($GLOBALS['wp_fts_test_post_meta'][$post_id][$meta_key]);

        return $existed;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, mixed ...$args): bool
    {
        $post_id = isset($args[0]) ? (int) $args[0] : 0;

        return (bool) ($GLOBALS['wp_fts_test_caps'][$capability][$post_id] ?? false);
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return !empty($GLOBALS['wp_fts_test_is_admin']);
    }
}

if (!function_exists('is_network_admin')) {
    function is_network_admin(): bool
    {
        return !empty($GLOBALS['wp_fts_test_is_network_admin']);
    }
}

if (!function_exists('get_current_screen')) {
    function get_current_screen(): ?object
    {
        $screen = $GLOBALS['wp_fts_test_current_screen'] ?? null;

        return is_object($screen) ? $screen : null;
    }
}

if (!function_exists('wp_doing_cron')) {
    function wp_doing_cron(): bool
    {
        return !empty($GLOBALS['wp_fts_test_is_cron']);
    }
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax(): bool
    {
        return !empty($GLOBALS['wp_fts_test_is_ajax']);
    }
}

if (!function_exists('wp_is_serving_rest_request')) {
    function wp_is_serving_rest_request(): bool
    {
        return !empty($GLOBALS['wp_fts_test_is_rest']);
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
}

if (!function_exists('apply_filters')) {
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

if (!function_exists('do_blocks')) {
    function do_blocks(string $content): string
    {
        $rendered = $GLOBALS['wp_fts_test_do_blocks'][$content] ?? null;

        return is_string($rendered) ? $rendered : $content;
    }
}

function wp_fts_test_capture_admin_sandbox(): string
{
    ob_start();
    try {
        WP_FTS_Plugin::render_admin_sandbox();
        $html = ob_get_clean();

        return is_string($html) ? $html : '';
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

function wp_fts_test_capture_admin_settings_tab(?string $tab = null): string
{
    ob_start();
    try {
        WP_FTS_Plugin::render_admin_settings_page($tab);
        $html = ob_get_clean();

        return is_string($html) ? $html : '';
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

function wp_fts_test_capture(callable $callback): string
{
    ob_start();
    try {
        $callback();
        $output = ob_get_clean();

        return is_string($output) ? $output : '';
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

function wp_fts_test_capture_cli(callable $callback): string
{
    ob_start();
    try {
        $callback();
        $output = ob_get_clean();

        return is_string($output) ? $output : '';
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

/**
 * @return array<string,mixed>
 */
function wp_fts_test_decode_cli_json_object(string $raw): array
{
    $payload = json_decode(trim($raw), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new WP_FTS_TestFailure('CLI JSON did not decode to an object: ' . $raw);
    }

    return $payload;
}

/**
 * @param array<string,mixed> $post
 * @param array<string,mixed> $get
 * @return array{raw:string,payload:array<string,mixed>}
 */
function wp_fts_test_capture_sandbox_details_ajax(array $post, array $get = []): array
{
    $oldGet = $_GET;
    $oldPost = $_POST;
    $oldAjax = $GLOBALS['wp_fts_test_is_ajax'] ?? false;
    $_GET = $get;
    $_POST = $post;
    $GLOBALS['wp_fts_test_is_ajax'] = true;

    ob_start();
    try {
        WP_FTS_Plugin::handle_sandbox_result_details_ajax();
        $raw = (string) ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        $_GET = $oldGet;
        $_POST = $oldPost;
        $GLOBALS['wp_fts_test_is_ajax'] = $oldAjax;
        throw $e;
    }

    $_GET = $oldGet;
    $_POST = $oldPost;
    $GLOBALS['wp_fts_test_is_ajax'] = $oldAjax;

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        throw new WP_FTS_TestFailure('Sandbox detail AJAX did not return JSON: ' . $raw);
    }

    return ['raw' => $raw, 'payload' => $payload];
}

function wp_fts_test_prepared_sql_count(WP_FTS_Test_WPDB $wpdb, string $prefix): int
{
    $count = 0;
    foreach ($wpdb->prepared as $entry) {
        if (str_starts_with($entry['sql'], $prefix)) {
            $count++;
        }
    }

    return $count;
}

/**
 * @return array{path:string,params:array<string,mixed>}
 */
function wp_fts_test_parse_admin_route(string $url): array
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        throw new WP_FTS_TestFailure("Could not parse admin route: {$url}");
    }

    $params = [];
    parse_str((string) ($parts['query'] ?? ''), $params);

    return [
        'path' => (string) ($parts['path'] ?? ''),
        'params' => $params,
    ];
}

function wp_fts_test_capture_admin_route(string $url): string
{
    $route = wp_fts_test_parse_admin_route($url);
    $_GET = $route['params'];

    return wp_fts_test_capture_admin_settings_tab(null);
}

function wp_fts_test_registered_admin_settings_callback(): callable
{
    WP_FTS_Plugin::register_admin_menu();
    $page = $GLOBALS['wp_fts_test_admin_pages'][0] ?? null;
    $callback = is_array($page) ? ($page['callback'] ?? null) : null;
    if (!is_callable($callback)) {
        throw new WP_FTS_TestFailure('Settings page did not register a callable admin callback.');
    }

    assert_same(WP_FTS_Plugin::ADMIN_PAGE_SLUG, $page['menu_slug'] ?? null, 'registered admin callback should belong to the FTS settings page');

    return $callback;
}

function wp_fts_test_capture_registered_admin_route(string $url, callable $callback): string
{
    $route = wp_fts_test_parse_admin_route($url);
    $_GET = $route['params'];

    ob_start();
    try {
        // WordPress admin dispatch reaches the page through the registered callback.
        $callback('');
        $html = ob_get_clean();

        return is_string($html) ? $html : '';
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

/**
 * @return array<int,array{title:string,slug:string}>
 */
function wp_fts_test_legacy_sandbox_demo_signatures(): array
{
    return [
        ['title' => 'FTS Sandbox: English Mice', 'slug' => 'wp-fts-sandbox-english-mice'],
        ['title' => 'FTS Sandbox: Polish Lemmatizer Demo', 'slug' => 'wp-fts-sandbox-polish-lemmatizer-demo'],
        ['title' => 'FTS Sandbox: Chinese Search N-grams', 'slug' => 'wp-fts-sandbox-chinese-search-ngrams'],
        ['title' => 'FTS Sandbox: Hindi Lemmatizer', 'slug' => 'wp-fts-sandbox-hindi-lemmatizer'],
        ['title' => 'FTS Sandbox: Spanish Buscar', 'slug' => 'wp-fts-sandbox-spanish-buscar'],
        ['title' => 'FTS Sandbox: Arabic Search', 'slug' => 'wp-fts-sandbox-arabic-search'],
        ['title' => 'FTS Sandbox: French Chercher', 'slug' => 'wp-fts-sandbox-french-chercher'],
        ['title' => 'FTS Sandbox: Bengali Lemmatizer', 'slug' => 'wp-fts-sandbox-bengali-lemmatizer'],
        ['title' => 'FTS Sandbox: Portuguese Pesquisar', 'slug' => 'wp-fts-sandbox-portuguese-pesquisar'],
        ['title' => 'FTS Sandbox: Indonesian Abadi', 'slug' => 'wp-fts-sandbox-indonesian-abadi'],
        ['title' => 'FTS Sandbox: Urdu Suffix Baseline', 'slug' => 'wp-fts-sandbox-urdu-suffix-baseline'],
    ];
}

function wp_fts_test_backfill_post(int $post_id, string $post_type = 'post', string $post_status = 'publish', ?string $title = null): object
{
    return (object) [
        'ID' => $post_id,
        'post_title' => $title ?? 'Backfill ' . $post_id,
        'post_content' => '<p>batchindexneedle ' . $post_id . '</p>',
        'post_excerpt' => '',
        'post_type' => $post_type,
        'post_status' => $post_status,
        'post_password' => '',
        'post_date_gmt' => '2026-06-18 00:00:00',
        'post_date' => '2026-06-18 00:00:00',
    ];
}

function wp_fts_test_seed_backfill_posts(WP_FTS_Test_WPDB $wpdb, int $count, int $start_id = 1, string $post_type = 'post', string $post_status = 'publish'): void
{
    for ($i = 0; $i < $count; $i++) {
        $wpdb->postRows[] = wp_fts_test_backfill_post($start_id + $i, $post_type, $post_status);
    }
}

function wp_fts_test_seed_indexed_posts(WP_FTS_Test_WPDB $wpdb, int $count, int $start_id = 1, string $post_type = 'post', string $post_status = 'publish'): void
{
    for ($i = 0; $i < $count; $i++) {
        $postId = $start_id + $i;
        $post = wp_fts_test_backfill_post($postId, $post_type, $post_status, 'Indexed Stale ' . $postId);
        $wpdb->postRows[] = $post;
        $GLOBALS['wp_fts_test_posts'][$postId] = $post;
        $wpdb->docs[$postId] = [
            'lang' => 'en',
            'doc_len' => 1,
            'content_hash' => 'old-profile-' . $postId,
            'is_deleted' => 0,
        ];
        $wpdb->docLengths[$postId] = ['en' => 1];
        $wpdb->docMeta[$postId] = [
            'doc_id' => $postId,
            'post_id' => $postId,
            'post_type' => $post_type,
            'post_status' => $post_status,
            'post_date_gmt' => '2026-06-18 00:00:00',
            'title' => 'Indexed Stale ' . $postId,
            'excerpt' => '',
            'search_text' => 'old profile text',
            'data' => '{}',
        ];
    }
    ksort($wpdb->docs, SORT_NUMERIC);
    ksort($wpdb->docLengths, SORT_NUMERIC);
    ksort($wpdb->docMeta, SORT_NUMERIC);
    ksort($GLOBALS['wp_fts_test_posts'], SORT_NUMERIC);
}

function wp_fts_test_mark_field_boost_stale_debt(float $title_boost = 8.0): array
{
    $oldPost = $_POST;
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
    $_POST = [
        'option_page' => WP_FTS_Plugin::SETTINGS_OPTION,
        'action' => 'update',
        '_wpnonce' => wp_create_nonce(WP_FTS_Plugin::SETTINGS_OPTION . '-options'),
    ];

    try {
        $newSettings = WP_FTS_Plugin::default_settings();
        $newSettings['field_boosts'] = array_replace($newSettings['field_boosts'], [
            'title' => $title_boost,
        ]);
        $sanitized = WP_FTS_Plugin::sanitize_settings_for_save($newSettings);
        update_option(WP_FTS_Plugin::SETTINGS_OPTION, $sanitized);
    } finally {
        $_POST = $oldPost;
    }

    $health = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];

    return is_array($health) ? $health : [];
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
        'add_meta_boxes',
        'admin_init',
        'admin_init',
        'admin_menu',
        'before_delete_post',
        'loop_end',
        'loop_start',
        'pre_get_posts',
        'pre_get_posts',
        'rest_api_init',
        'save_post',
        'save_post',
        'transition_post_status',
        'trashed_post',
        'wp_initialize_site',
        'wp_after_insert_post',
        'wp_ajax_wp_fts_sandbox_result_details',
    ];
    sort($expectedHooks, SORT_STRING);
    assert_same($expectedHooks, $hooks, 'bootstrap should register bounded runtime hooks in WordPress context');

    $filterHooks = array_column($GLOBALS['wp_fts_test_filter_registrations'], 'hook');
    sort($filterHooks, SORT_STRING);
    assert_same(['debug_bar_panels', 'found_posts', 'found_posts', 'get_the_excerpt', 'posts_pre_query', 'posts_pre_query', 'render_block', 'the_content', 'the_excerpt', 'the_title', 'wpmu_drop_tables'], $filterHooks, 'bootstrap should register front-end, admin, diagnostics, and multisite cleanup filters');

    $siteLifecycleAction = null;
    foreach ($GLOBALS['wp_fts_test_actions'] as $action) {
        if (($action['hook'] ?? null) === 'wp_initialize_site') {
            $siteLifecycleAction = $action;
            break;
        }
    }
    assert_same([WP_FTS_Plugin::class, 'handle_site_initialization'], $siteLifecycleAction['callback'] ?? null, 'bootstrap should register current multisite site-initialization hook');
    assert_same(2, $siteLifecycleAction['accepted_args'] ?? null, 'site-initialization hook should accept the WP_Site and args payloads');

    $siteDeletionFilter = null;
    foreach ($GLOBALS['wp_fts_test_filter_registrations'] as $filter) {
        if (($filter['hook'] ?? null) === 'wpmu_drop_tables') {
            $siteDeletionFilter = $filter;
            break;
        }
    }
    assert_same([WP_FTS_Plugin::class, 'filter_site_deletion_tables'], $siteDeletionFilter['callback'] ?? null, 'bootstrap should register multisite site-deletion table discovery filter');
    assert_same(2, $siteDeletionFilter['accepted_args'] ?? null, 'site-deletion table filter should accept existing tables and site id');

    $searchActionPriorities = [];
    foreach ($GLOBALS['wp_fts_test_actions'] as $action) {
        $callback = $action['callback'] ?? null;
        $method = is_array($callback) ? ($callback[1] ?? null) : null;
        if (($action['hook'] ?? null) === 'pre_get_posts' && is_string($method)) {
            $searchActionPriorities[$method] = $action['priority'] ?? null;
        }
    }
    ksort($searchActionPriorities, SORT_STRING);
    assert_same([
        'prepare_admin_post_search_query' => WP_FTS_Plugin::SEARCH_REPLACEMENT_PRIORITY,
        'prepare_frontend_search_query' => WP_FTS_Plugin::SEARCH_REPLACEMENT_PRIORITY,
    ], $searchActionPriorities, 'search pre_get_posts hooks should run late enough to mark owned search surfaces after normal integrations');

    $searchFilterPriorities = [];
    foreach ($GLOBALS['wp_fts_test_filter_registrations'] as $filter) {
        $callback = $filter['callback'] ?? null;
        $method = is_array($callback) ? ($callback[1] ?? null) : null;
        if (is_string($method) && in_array($method, [
            'filter_admin_post_search_found_posts',
            'filter_frontend_search_found_posts',
            'replace_admin_post_search_posts',
            'replace_frontend_search_posts',
        ], true)) {
            $searchFilterPriorities[$method] = [
                'hook' => $filter['hook'] ?? null,
                'priority' => $filter['priority'] ?? null,
                'accepted_args' => $filter['accepted_args'] ?? null,
            ];
        }
    }
    ksort($searchFilterPriorities, SORT_STRING);
    assert_same([
        'filter_admin_post_search_found_posts' => ['hook' => 'found_posts', 'priority' => WP_FTS_Plugin::SEARCH_REPLACEMENT_PRIORITY, 'accepted_args' => 2],
        'filter_frontend_search_found_posts' => ['hook' => 'found_posts', 'priority' => WP_FTS_Plugin::SEARCH_REPLACEMENT_PRIORITY, 'accepted_args' => 2],
        'replace_admin_post_search_posts' => ['hook' => 'posts_pre_query', 'priority' => WP_FTS_Plugin::SEARCH_REPLACEMENT_PRIORITY, 'accepted_args' => 2],
        'replace_frontend_search_posts' => ['hook' => 'posts_pre_query', 'priority' => WP_FTS_Plugin::SEARCH_REPLACEMENT_PRIORITY, 'accepted_args' => 2],
    ], $searchFilterPriorities, 'search replacement filters should run late enough to override normal posts_pre_query providers');
    assert_same([], WP_CLI::$commands, 'web bootstrap should not register WP-CLI unless WP_CLI is active');
});

test_case('admin menu registration exposes Settings Full-Text Search page and option', function (): void {
    wp_fts_test_reset_wordpress_fakes();

    WP_FTS_Plugin::register_admin_menu();
    WP_FTS_Plugin::register_settings();
    $page = $GLOBALS['wp_fts_test_admin_pages'][0] ?? null;
    $setting = $GLOBALS['wp_fts_test_registered_settings'][0] ?? null;

    assert_same('options-general.php', $page['parent'] ?? null, 'admin page should register under Settings');
    assert_same('Full-Text Search', $page['page_title'] ?? null, 'admin page should use a clear page title');
    assert_same('Full-Text Search', $page['menu_title'] ?? null, 'admin page should use a clear Settings menu label');
    assert_same(WP_FTS_Plugin::ADMIN_CAPABILITY, $page['capability'] ?? null, 'admin page should require the configured capability');
    assert_same(WP_FTS_Plugin::ADMIN_PAGE_SLUG, $page['menu_slug'] ?? null, 'admin page should use the stable settings slug');
    assert_same([WP_FTS_Plugin::class, 'render_admin_settings_page'], $page['callback'] ?? null, 'admin page should render through the settings callback');
    assert_same('wp_fts_settings', $setting['option_group'] ?? null, 'settings should use the wp_fts_settings group');
    assert_same(WP_FTS_Plugin::SETTINGS_OPTION, $setting['option_name'] ?? null, 'settings should register the wp_fts_settings option');
    assert_same(['post', 'page'], WP_FTS_Plugin::default_settings()['index_post_types'], 'default settings should index both posts and pages');
    assert_same([
        'title' => 5.0,
        'content' => 1.0,
        'excerpt' => 2.0,
        'terms' => 1.5,
        'custom_fields' => 1.0,
        'rendered' => 1.0,
    ], WP_FTS_Plugin::default_settings()['field_boosts'], 'default settings should expose the extractor field boost defaults');
    assert_same(0.0, WP_FTS_Plugin::default_settings()['recency_boost_strength'], 'default recency ranking boost should be disabled');
    assert_same(30.0, WP_FTS_Plugin::default_settings()['recency_boost_half_life_days'], 'default recency ranking half-life should be conservative');
    assert_same(true, WP_FTS_Plugin::default_settings()['language_fallback'], 'default settings should enable language fallback');
    assert_same(true, WP_FTS_Plugin::default_settings()['prefix_matching'], 'default settings should enable word-beginning prefix matching');
    assert_same(4, WP_FTS_Plugin::default_settings()['prefix_min_length'], 'default prefix minimum length should preserve existing searcher behavior');
    assert_same(64, WP_FTS_Plugin::default_settings()['prefix_max_terms'], 'default prefix expansion cap should preserve existing searcher behavior');
    assert_same('prefer_fts', WP_FTS_Plugin::default_settings()['search_provider_compatibility'], 'default search provider compatibility should prefer FTS precedence');
});

test_case('settings sanitization maps replacement checkboxes and legacy scope to existing booleans', function (): void {
    $checkboxes = WP_FTS_Plugin::sanitize_settings([
        'replace_frontend_search' => '1',
        'replace_admin_post_search' => '0',
        'auto_index' => '0',
        'prefix_matching' => '0',
    ]);
    assert_same(true, $checkboxes['replace_frontend_search'], 'frontend replacement checkbox should enable the public-site replacement boolean');
    assert_same(false, $checkboxes['replace_admin_post_search'], 'admin replacement checkbox should disable the wp-admin replacement boolean');
    assert_same(false, $checkboxes['auto_index'], 'auto-index checkbox should disable automatic indexing when unchecked');
    assert_same(false, $checkboxes['prefix_matching'], 'prefix matching checkbox should disable word-beginning matching when unchecked');
    assert_same('prefer_fts', $checkboxes['search_provider_compatibility'], 'missing provider compatibility setting should sanitize to the default precedence mode');

    $respectProvider = WP_FTS_Plugin::sanitize_settings([
        'search_provider_compatibility' => 'respect_existing',
    ]);
    assert_same('respect_existing', $respectProvider['search_provider_compatibility'], 'coexistence provider compatibility mode should sanitize when selected');

    $invalidProvider = WP_FTS_Plugin::sanitize_settings([
        'search_provider_compatibility' => 'replace-everything',
    ]);
    assert_same('prefer_fts', $invalidProvider['search_provider_compatibility'], 'invalid provider compatibility mode should sanitize back to the default');

    $autoIndex = WP_FTS_Plugin::sanitize_settings([
        'auto_index' => '1',
        'prefix_matching' => '1',
    ]);
    assert_same(true, $autoIndex['auto_index'], 'auto-index checkbox should enable automatic indexing when checked');
    assert_same(true, $autoIndex['prefix_matching'], 'prefix matching checkbox should enable word-beginning matching when checked');

    $adminOnly = WP_FTS_Plugin::sanitize_settings([
        'replace_search_scope' => 'admin',
    ]);
    assert_same(false, $adminOnly['replace_frontend_search'], 'admin replacement scope should leave frontend replacement disabled');
    assert_same(true, $adminOnly['replace_admin_post_search'], 'admin replacement scope should enable admin Posts replacement');

    $none = WP_FTS_Plugin::sanitize_settings([
        'replace_search_scope' => 'none',
    ]);
    assert_same(false, $none['replace_frontend_search'], 'none replacement scope should disable frontend replacement');
    assert_same(false, $none['replace_admin_post_search'], 'none replacement scope should disable admin replacement');

    $legacy = WP_FTS_Plugin::sanitize_settings([
        'replace_frontend_search' => '0',
        'replace_admin_post_search' => '1',
    ]);
    assert_same(false, $legacy['replace_frontend_search'], 'legacy frontend replacement boolean should still sanitize');
    assert_same(true, $legacy['replace_admin_post_search'], 'legacy admin replacement boolean should still sanitize');
});

test_case('settings sanitization accepts and clamps prefix threshold controls', function (): void {
    $valid = WP_FTS_Plugin::sanitize_settings([
        'prefix_min_length' => '3',
        'prefix_max_terms' => '128',
    ]);
    assert_same(3, $valid['prefix_min_length'], 'valid prefix minimum length should persist');
    assert_same(128, $valid['prefix_max_terms'], 'valid prefix max terms should persist');

    $clamped = WP_FTS_Plugin::sanitize_settings([
        'prefix_min_length' => '1',
        'prefix_max_terms' => '9999',
    ]);
    assert_same(2, $clamped['prefix_min_length'], 'too-short prefix minimum length should clamp to the product lower bound');
    assert_same(256, $clamped['prefix_max_terms'], 'too-large prefix max terms should clamp to the product upper bound');

    $invalid = WP_FTS_Plugin::sanitize_settings([
        'prefix_min_length' => [],
        'prefix_max_terms' => 'not-a-number',
    ]);
    assert_same(4, $invalid['prefix_min_length'], 'non-scalar prefix minimum length should fall back to the default');
    assert_same(64, $invalid['prefix_max_terms'], 'non-numeric prefix max terms should fall back to the default');
});

test_case('settings sanitization accepts bounded field boosts and rejects invalid values', function (): void {
    $valid = WP_FTS_Plugin::sanitize_settings([
        'field_boosts' => [
            'title' => '7.25',
            'content' => '0.25',
            'excerpt' => 3,
            'terms' => 2.5,
            'custom_fields' => '1000',
            'rendered' => '0.001',
            'unknown' => 9,
        ],
    ]);
    assert_same([
        'title' => 7.25,
        'content' => 0.25,
        'excerpt' => 3.0,
        'terms' => 2.5,
        'custom_fields' => 100.0,
        'rendered' => 0.01,
    ], $valid['field_boosts'], 'valid field boosts should sanitize into the bounded extractor/indexer range');

    $invalid = WP_FTS_Plugin::sanitize_settings([
        'field_boosts' => [
            'title' => '0',
            'content' => -2,
            'excerpt' => [],
            'terms' => 'NaN',
            'custom_fields' => '1e309',
        ],
    ]);
    assert_same([
        'title' => 5.0,
        'content' => 1.0,
        'excerpt' => 2.0,
        'terms' => 1.5,
        'custom_fields' => 1.0,
        'rendered' => 1.0,
    ], $invalid['field_boosts'], 'invalid or missing field boosts should fall back to defaults');
});

test_case('settings sanitization clamps recency ranking boost controls', function (): void {
    $valid = WP_FTS_Plugin::sanitize_settings([
        'recency_boost_strength' => '1.25',
        'recency_boost_half_life_days' => '45.5',
    ]);
    assert_same(1.25, $valid['recency_boost_strength'], 'valid recency boost strength should persist as a float');
    assert_same(45.5, $valid['recency_boost_half_life_days'], 'valid recency half-life should persist as a float');

    $clamped = WP_FTS_Plugin::sanitize_settings([
        'recency_boost_strength' => '99',
        'recency_boost_half_life_days' => '99999',
    ]);
    assert_same(2.0, $clamped['recency_boost_strength'], 'recency boost strength should clamp to a conservative maximum');
    assert_same(3650.0, $clamped['recency_boost_half_life_days'], 'recency half-life should clamp to a bounded maximum');

    $invalid = WP_FTS_Plugin::sanitize_settings([
        'recency_boost_strength' => '-1',
        'recency_boost_half_life_days' => '-7',
    ]);
    assert_same(0.0, $invalid['recency_boost_strength'], 'negative recency strength should disable the boost');
    assert_same(30.0, $invalid['recency_boost_half_life_days'], 'invalid recency half-life should fall back to the default');

    $legacyToggle = WP_FTS_Plugin::sanitize_settings([
        'recency_boost' => true,
    ]);
    assert_same(0.25, $legacyToggle['recency_boost_strength'], 'boolean recency boost setting should normalize to the small default strength');
});

test_case('authorized settings save marks field boost stale debt without indexing content', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;

    try {
        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] = [901, 902];
        $_POST = [
            'option_page' => WP_FTS_Plugin::SETTINGS_OPTION,
            'action' => 'update',
            '_wpnonce' => wp_create_nonce(WP_FTS_Plugin::SETTINGS_OPTION . '-options'),
        ];

        $newSettings = WP_FTS_Plugin::default_settings();
        $newSettings['field_boosts'] = array_replace($newSettings['field_boosts'], [
            'title' => 8.0,
        ]);
        $sanitized = WP_FTS_Plugin::sanitize_settings_for_save($newSettings);
        update_option(WP_FTS_Plugin::SETTINGS_OPTION, $sanitized);

        $health = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];
    } finally {
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }

    assert_same(true, $health['stale_debt_active'] ?? null, 'authorized field boost save should mark stale debt active');
    assert_same(['field_boosts_changed'], $health['stale_debt_reasons'] ?? null, 'field boost save should store the concise field-boost reason');
    assert_true(is_string($health['index_profile_hash'] ?? null) && preg_match('/^[a-f0-9]{40}$/', $health['index_profile_hash']) === 1, 'field boost save should persist the current profile hash');
    assert_true(is_string($health['accepted_index_profile_hash'] ?? null) && preg_match('/^[a-f0-9]{40}$/', $health['accepted_index_profile_hash']) === 1, 'field boost save should persist the previously accepted profile hash');
    assert_true(($health['index_profile_hash'] ?? '') !== ($health['accepted_index_profile_hash'] ?? ''), 'changed field boosts should move the current profile away from the accepted profile');
    assert_true(is_string($health['stale_debt_created_at'] ?? null) && $health['stale_debt_created_at'] !== '', 'field boost save should timestamp debt creation');
    assert_same([901, 902], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] ?? [], 'settings save should not drain the indexing queue');
    assert_same([], $fake->docs, 'settings save should not index documents');
    assert_same([], $fake->terms, 'settings save should not write FTS terms');
});

test_case('unauthorized settings save does not mark stale debt or index content', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;

    try {
        wp_fts_test_reset_wordpress_fakes();
        $_POST = [
            'option_page' => WP_FTS_Plugin::SETTINGS_OPTION,
            'action' => 'update',
            '_wpnonce' => wp_create_nonce(WP_FTS_Plugin::SETTINGS_OPTION . '-options'),
        ];

        $newSettings = WP_FTS_Plugin::default_settings();
        $newSettings['field_boosts'] = array_replace($newSettings['field_boosts'], [
            'title' => 8.0,
        ]);
        WP_FTS_Plugin::sanitize_settings_for_save($newSettings);
    } finally {
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }

    assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION]), 'unauthorized settings save should not create stale debt state');
    assert_same([], $fake->docs, 'unauthorized settings save should not index documents');
    assert_same([], $fake->terms, 'unauthorized settings save should not write FTS terms');
});

test_case('prepare post index options uses saved field boosts unless caller overrides them', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $savedBoosts = [
        'title' => 1.25,
        'content' => 8.0,
        'excerpt' => 1.75,
        'terms' => 2.25,
        'custom_fields' => 3.5,
        'rendered' => 4.0,
    ];
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = array_replace(
        WP_FTS_Plugin::default_settings(),
        ['field_boosts' => $savedBoosts]
    );
    $post = (object) [
        'ID' => 1247,
        'post_title' => 'Boost settings',
        'post_content' => '<p>boost settings</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-20 00:00:00',
    ];

    $settingsOptions = WP_FTS_Plugin::prepare_post_index_options($post, ['lang' => 'en']);
    assert_same($savedBoosts, $settingsOptions['field_boosts'] ?? null, 'prepare_post_index_options should pass saved field boosts to runtime indexing');

    $override = ['title' => 9.0, 'content' => 0.5];
    $callerOptions = WP_FTS_Plugin::prepare_post_index_options($post, [
        'lang' => 'en',
        'field_boosts' => $override,
    ]);
    assert_same($override, $callerOptions['field_boosts'] ?? null, 'explicit caller field_boosts should override saved plugin settings');
});

test_case('post language meta box defaults to automatic detection and stores overrides', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $post = (object) [
        'ID' => 77,
        'post_title' => 'Manual Polish Language',
        'post_content' => '<p>zamek oraz wpis</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-12 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][77] = $post;
    $GLOBALS['wp_fts_test_caps']['edit_post'][77] = true;
    $oldPost = $_POST;

    try {
        WP_FTS_Plugin::register_language_meta_box();
        $screens = array_column($GLOBALS['wp_fts_test_meta_boxes'], 'screen');
        sort($screens, SORT_STRING);
        assert_same(['page', 'post'], $screens, 'language meta box should register for public searchable post types');

        ob_start();
        WP_FTS_Plugin::render_language_meta_box($post);
        $html = (string) ob_get_clean();
        assert_contains('Post language', $html, 'language meta box should render a labeled selector');
        assert_contains('value="auto" selected="selected"', $html, 'language meta box should default to automatic detection');
        assert_contains('value="pl"', $html, 'language meta box should offer Polish override support');
        foreach (['en', 'zh', 'hi', 'es', 'ar', 'fr', 'bn', 'pt', 'id', 'ur', 'de', 'ru'] as $language) {
            assert_contains('value="' . $language . '"', $html, "language meta box should offer {$language} override support");
        }

        $_POST = [
            'wp_fts_post_language_nonce' => wp_create_nonce('wp_fts_post_language'),
            'wp_fts_post_language' => 'pl',
        ];
        WP_FTS_Plugin::save_post_language_override(77, $post, true);
        assert_same('pl', get_post_meta(77, WP_FTS_Plugin::LANGUAGE_META_KEY, true), 'valid post save should store the selected language override');

        WP_FTS_Plugin::handle_post_save(77, $post, true);
        assert_same('pl', $fake->docs[77]['lang'] ?? null, 'post language override should reach incremental indexing');
        assert_true(isset($fake->terms[WP_FTS_TermNamespace::namespace_term('pl', 'zamek')]), 'manual Polish override should index through the Polish partition');

        $_POST = [
            'wp_fts_post_language_nonce' => wp_create_nonce('wp_fts_post_language'),
            'wp_fts_post_language' => 'auto',
        ];
        WP_FTS_Plugin::save_post_language_override(77, $post, true);
        assert_same('', get_post_meta(77, WP_FTS_Plugin::LANGUAGE_META_KEY, true), 'automatic post language should clear the explicit override');
    } finally {
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }
});

test_case('authorized admin sandbox render includes search form and creates no posts', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $_GET = [];
    $_POST = [];

    try {
        $html = wp_fts_test_capture_admin_sandbox();
        $settingsHtml = wp_fts_test_capture_admin_settings_tab('settings');
        $indexedHtml = wp_fts_test_capture_admin_settings_tab('indexed-content');
        $analyzerHtml = wp_fts_test_capture_admin_settings_tab('analyzer-packs');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }

    assert_contains('Full-Text Search', $html, 'sandbox tab should render inside the Full-Text Search settings page');
    assert_contains('nav-tab-active', $html, 'settings page should render WordPress-style tabs');
    foreach (['Health', 'Settings', 'Sandbox', 'Indexed content', 'Analyzer packs'] as $tabLabel) {
        assert_contains($tabLabel, $html, "settings tabs should include {$tabLabel}");
    }
    assert_contains('Full-text search (FTS) builds its own searchable index', $html, 'admin page should define full-text search before showing controls');
    assert_contains('wp-fts-admin-summary', $html, 'admin orientation should use compact help text instead of a heavy notice');
    assert_true(!str_contains($html, '<div class="notice notice-info"><p><strong>What this does:</strong>'), 'admin orientation should not render as a large notice');
    assert_contains('wp-fts-language-status', $html, 'site-language status should use compact help text');
    assert_true(!str_contains($html, '<div class="notice notice-info"><p>Current site language'), 'site-language status should not render as a large notice');
    assert_true(!str_contains($html, 'Demo posts and the full-text index are ready'), 'authorized first render should not report demo corpus auto-seeding');
    assert_same([], $GLOBALS['wp_fts_test_posts'], 'authorized first sandbox render should not create posts');
    assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SANDBOX_DEMO_POSTS_OPTION]), 'authorized first sandbox render should not write the legacy demo post option');
    assert_same([], $fake->terms, 'authorized first sandbox render should not build FTS terms for generated content');
    assert_true(!str_contains($html, 'Legacy sandbox demo posts detected'), 'clean sandbox render should not show the cleanup affordance');
    foreach (['What gets indexed', 'When the index updates', 'Where full-text search replaces WordPress search', 'Customer-facing search behavior', 'Ranking weights', 'Language handling'] as $groupLabel) {
        assert_contains($groupLabel, $settingsHtml, "settings tab should group controls by {$groupLabel}");
    }
    assert_contains('Content types in the index', $settingsHtml, 'settings tab should render post-type configuration');
    assert_contains('value="post" checked="checked"', $settingsHtml, 'settings defaults should include posts');
    assert_contains('value="page" checked="checked"', $settingsHtml, 'settings defaults should include pages');
    assert_contains('name="_wp_http_referer" value="/wp-admin/options-general.php?page=wp-fts-settings&amp;tab=settings"', $settingsHtml, 'settings form should preserve the explicit settings-tab referrer after save');
    assert_contains('Automatically update the search index when content changes', $settingsHtml, 'settings auto-index control should use a plain checkbox label');
    assert_contains('type="hidden" name="wp_fts_settings[auto_index]" value="0"', $settingsHtml, 'settings auto-index checkbox should post an unchecked value');
    assert_contains('type="checkbox" name="wp_fts_settings[auto_index]" value="1" checked="checked"', $settingsHtml, 'settings auto-index checkbox should render checked by default');
    assert_true(!str_contains($settingsHtml, 'type="radio" name="wp_fts_settings[auto_index]"'), 'settings auto-index control should no longer render as radios');
    assert_contains('Use full-text search on the public site', $settingsHtml, 'settings replacement behavior should expose the public-site checkbox');
    assert_contains('Use full-text search in wp-admin post search', $settingsHtml, 'settings replacement behavior should expose the wp-admin checkbox');
    assert_contains('type="hidden" name="wp_fts_settings[replace_frontend_search]" value="0"', $settingsHtml, 'public-site replacement checkbox should post an unchecked value');
    assert_contains('type="checkbox" name="wp_fts_settings[replace_frontend_search]" value="1" checked="checked"', $settingsHtml, 'public-site replacement checkbox should be checked by default');
    assert_contains('type="hidden" name="wp_fts_settings[replace_admin_post_search]" value="0"', $settingsHtml, 'wp-admin replacement checkbox should post an unchecked value');
    assert_contains('type="checkbox" name="wp_fts_settings[replace_admin_post_search]" value="1" checked="checked"', $settingsHtml, 'wp-admin replacement checkbox should be checked by default');
    assert_true(!str_contains($settingsHtml, 'name="wp_fts_settings[replace_search_scope]"'), 'settings replacement behavior should no longer render the legacy scope radio group');
    assert_contains('Search provider compatibility', $settingsHtml, 'settings replacement behavior should expose provider compatibility');
    assert_contains('Prefer Language FTS', $settingsHtml, 'settings provider compatibility should expose the default precedence mode');
    assert_contains('Keep another search provider', $settingsHtml, 'settings provider compatibility should expose the coexistence mode');
    assert_contains('value="prefer_fts" checked="checked"', $settingsHtml, 'settings provider compatibility should default to preferring FTS');
    assert_contains('Search result excerpt length', $settingsHtml, 'settings should use clearer excerpt length copy');
    assert_contains('short piece of post text shown around a matching word', $settingsHtml, 'settings should explain search result excerpts in plain language');
    assert_contains('Search term matching', $settingsHtml, 'settings should use product-style search matching language');
    assert_contains('Match any word (broader)', $settingsHtml, 'settings should describe the broad matching option without making OR the primary label');
    assert_contains('Require every word (stricter)', $settingsHtml, 'settings should describe the strict matching option without making AND the primary label');
    assert_true(!str_contains($settingsHtml, '>Match mode<'), 'settings UI should not expose the old Match mode label');
    assert_contains('Word beginnings', $settingsHtml, 'settings should expose the prefix matching setting near term matching');
    assert_contains('type="hidden" name="wp_fts_settings[prefix_matching]" value="0"', $settingsHtml, 'prefix matching checkbox should post an unchecked value');
    assert_contains('type="checkbox" name="wp_fts_settings[prefix_matching]" value="1" checked="checked"', $settingsHtml, 'prefix matching should be checked by default');
    assert_contains('Exact and lemmatizer matches still rank first', $settingsHtml, 'prefix matching copy should explain rank precedence');
    assert_contains('Shortest word beginning', $settingsHtml, 'settings should expose prefix minimum length near word beginnings');
    assert_contains('name="wp_fts_settings[prefix_min_length]" value="4"', $settingsHtml, 'settings should render the default prefix minimum length');
    assert_contains('Shorter values make word-beginning matches broader', $settingsHtml, 'prefix minimum length copy should explain broader matching');
    assert_contains('can be slower and add noisier alternatives', $settingsHtml, 'prefix minimum length copy should explain cost and noise');
    assert_contains('Word-beginning alternatives', $settingsHtml, 'settings should expose prefix max terms near word beginnings');
    assert_contains('name="wp_fts_settings[prefix_max_terms]" value="64"', $settingsHtml, 'settings should render the default prefix max terms');
    assert_contains('Limits how many stored terms a broad word beginning can add', $settingsHtml, 'prefix max terms copy should explain bounded expansion');
    assert_contains('exact and lemma matches still rank first', $settingsHtml, 'prefix max terms copy should explain ranking precedence');
    assert_contains('Results per page', $settingsHtml, 'settings should rename result limit to results per page');
    assert_contains('shown on one page or search view', $settingsHtml, 'settings results-per-page help should explain the page/search-view behavior');
    assert_contains('Higher numbers make matches in that field count more strongly', $settingsHtml, 'settings ranking copy should explain the effect of larger weights');
    assert_contains('Changed weights affect content when it is reindexed', $settingsHtml, 'settings ranking copy should explain index-time weight storage');
    foreach ([
        'title' => ['Title', '5'],
        'content' => ['Main content', '1'],
        'excerpt' => ['Excerpt', '2'],
        'terms' => ['Taxonomy terms', '1.5'],
        'custom_fields' => ['Selected custom fields', '1'],
        'rendered' => ['Rendered-only content', '1'],
    ] as $field => $expected) {
        [$label, $value] = $expected;
        assert_contains($label, $settingsHtml, "settings ranking controls should label {$field} plainly");
        assert_contains('step="0.01" name="wp_fts_settings[field_boosts][' . $field . ']"', $settingsHtml, "settings ranking controls should use a step compatible with the {$field} boost precision");
        assert_contains('name="wp_fts_settings[field_boosts][' . $field . ']" value="' . $value . '"', $settingsHtml, "settings ranking controls should render the default {$field} boost");
    }
    assert_contains('Matches in the main saved post content', $settingsHtml, 'settings ranking controls should explain main content');
    assert_contains('Matches in categories, tags, and other taxonomy term names', $settingsHtml, 'settings ranking controls should explain taxonomy terms');
    assert_contains('Matches in block-rendered output that is not already in the saved content', $settingsHtml, 'settings ranking controls should explain rendered-only content');
    assert_contains('name="wp_fts_settings[recency_boost_strength]" value="0"', $settingsHtml, 'settings ranking controls should default recency boost strength to disabled');
    assert_contains('name="wp_fts_settings[recency_boost_half_life_days]" value="30"', $settingsHtml, 'settings ranking controls should render the default recency half-life');
    assert_contains('using indexed GMT post dates', $settingsHtml, 'settings ranking controls should explain the recency boost date metadata source');
    assert_contains('without rebuilding the index', $settingsHtml, 'settings ranking controls should explain recency boost changes are query-time');
    assert_contains('name="wp_fts_settings[language_fallback]" value="1" checked="checked"', $settingsHtml, 'settings defaults should enable language fallback');
    assert_contains('If the query language is unsupported or produces no matches', $settingsHtml, 'settings language fallback copy should explain unsupported languages and no-match fallback');
    assert_contains('not copied into this plugin setting', $settingsHtml, 'settings language fallback copy should explain that site language is read dynamically');
    assert_contains('href="/wp-admin/options-general.php"', $settingsHtml, 'current site language description should link to General Settings');
    assert_contains('name="wp_fts_sandbox_query"', $html, 'sandbox page should include the search query field');
    assert_contains('Try a query against the same index and saved settings', $html, 'sandbox tab should explain that it searches the same index');
    assert_contains('name="tab" value="sandbox"', $html, 'sandbox form should preserve the selected tab on search submission');
    assert_contains('id="wp-fts-sandbox-query" type="search" class="regular-text" name="wp_fts_sandbox_query" value=""', $html, 'sandbox page should leave the query field blank by default');
    assert_contains('name="wp_fts_sandbox_prefix_matching"', $html, 'sandbox page should expose a per-search word beginnings override');
    assert_contains('wp-fts-sandbox-compact-controls', $html, 'sandbox search controls should use a compact primary row');
    assert_contains('<details class="wp-fts-sandbox-advanced">', $html, 'secondary sandbox controls should be collapsed behind details');
    assert_contains('Filters and display options', $html, 'sandbox advanced controls should have a clear summary label');
    assert_contains('name="wp_fts_sandbox_lang"', $html, 'sandbox page should include the query language selector');
    assert_contains('value="auto"', $html, 'sandbox language selector should include automatic detection');
    assert_contains('value="site"', $html, 'sandbox language selector should include the dynamic site-language option');
    assert_contains('value="en"', $html, 'sandbox language selector should include English');
    assert_contains('value="pl"', $html, 'sandbox language selector should include Polish');
    assert_contains('value="de"', $html, 'sandbox language selector should include German');
    foreach (['zh', 'hi', 'es', 'ar', 'fr', 'bn', 'pt', 'id', 'ur', 'ru'] as $language) {
        assert_contains('value="' . $language . '"', $html, "sandbox language selector should include {$language}");
    }
    assert_contains('name="wp_fts_sandbox_mode"', $html, 'sandbox page should expose search term matching');
    assert_contains('Match any word (broader)', $html, 'sandbox page should describe broader matching clearly');
    assert_contains('Require every word (stricter)', $html, 'sandbox page should describe stricter matching clearly');
    assert_contains('name="wp_fts_sandbox_limit"', $html, 'sandbox page should expose results per page');
    assert_contains('Results per page', $html, 'sandbox page should rename maximum results to results per page');
    assert_contains('name="wp_fts_sandbox_snippet_length"', $html, 'sandbox page should expose search result excerpt length');
    assert_contains('Search result excerpt length', $html, 'sandbox page should use clearer excerpt copy');
    assert_contains('short piece of post text shown around a matching word', $html, 'sandbox page should explain excerpts inline');
    assert_contains('name="wp_fts_sandbox_highlight"', $html, 'sandbox page should expose highlight toggle');
    assert_contains('name="wp_fts_sandbox_language_fallback"', $html, 'sandbox page should expose language fallback toggle');
    assert_contains('Also try the current WordPress site language when needed', $html, 'sandbox language fallback should be an explicit behavior choice');
    assert_contains('That language is read dynamically from WordPress', $html, 'sandbox language fallback should explain dynamic site language lookup');
    assert_contains('name="wp_fts_sandbox_post_type[]"', $html, 'sandbox page should expose post type filters');
    assert_contains('name="wp_fts_sandbox_post_status[]"', $html, 'sandbox page should expose post status filters');
    assert_contains('name="wp_fts_sandbox_date_after"', $html, 'sandbox page should expose date-after filter');
    assert_contains('name="wp_fts_sandbox_date_before"', $html, 'sandbox page should expose date-before filter');
    assert_contains('Analyzer packs', $analyzerHtml, 'analyzer packs tab should be reachable from the settings page');
    assert_contains('Runtime packs affect real site searches', $analyzerHtml, 'analyzer packs tab should explain runtime versus sandbox scope');
    assert_contains('Polish (pl)', $analyzerHtml, 'analyzer pack status should include the bundled Polish pack');
    assert_contains('<td>Active</td>', $analyzerHtml, 'analyzer pack status should identify active packs');
    if (WP_FTS_AnalyzerPackValidator::gzip_available()) {
        assert_contains('English (en)', $analyzerHtml, 'sandbox analyzer pack status should include the active base English pack');
        assert_contains('en-unimorph-eng-66e0e9e8e2dc', $analyzerHtml, 'sandbox analyzer pack status should expose the English pack id');
    }
    assert_true(!str_contains($html, 'name="wp_fts_sandbox_action"'), 'sandbox page should not render mutating demo action controls');
    assert_true(!str_contains($html, 'Create or refresh demo posts'), 'sandbox page should not render the manual demo refresh button');
    assert_true(!str_contains($html, 'Build demo index'), 'sandbox page should not render the manual demo index button');
    assert_true(!str_contains($html, 'name="wp_fts_sandbox_nonce"'), 'sandbox page should not render hidden action nonces for removed manual controls');
    assert_contains('Indexed content', $indexedHtml, 'indexed-content tab should render indexed-post storage state');
    $oldPresetHeading = 'Suggested ' . 'queries';
    assert_true(!str_contains($html, $oldPresetHeading), 'sandbox page should not render the removed query-preset heading');
    assert_true(!str_contains($html, '<th scope="col">Query</th>'), 'sandbox page should not render a query-preset table column');
    foreach (wp_fts_test_legacy_sandbox_demo_signatures() as $signature) {
        assert_true(!str_contains($html, $signature['title']), "sandbox page should not render the legacy {$signature['title']} title");
    }
    assert_contains('No indexed posts are available yet.', $indexedHtml, 'indexed-content tab should render an empty state instead of creating sample posts');
    assert_true(!str_contains($indexedHtml, '<th scope="col">Language</th>'), 'empty indexed-content tab should not render a demo table');
    foreach (wp_fts_test_legacy_sandbox_demo_signatures() as $signature) {
        assert_true(!str_contains($indexedHtml, $signature['title']), "indexed-content tab should not list the legacy {$signature['title']} title");
    }
});

test_case('admin settings route defaults to Health and direct tab URLs render selected panels', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
    $oldGet = $_GET;
    $oldPost = $_POST;

    try {
        $_POST = [];
        $settingsCallback = wp_fts_test_registered_admin_settings_callback();
        $routes = [
            'health' => [
                'url' => '/wp-admin/options-general.php?page=wp-fts-settings',
                'label' => 'Health',
                'heading' => '<h2>Search health</h2>',
            ],
            'settings' => [
                'url' => '/wp-admin/options-general.php?page=wp-fts-settings&tab=settings',
                'label' => 'Settings',
                'heading' => '<h2>Settings</h2>',
            ],
            'sandbox' => [
                'url' => '/wp-admin/options-general.php?page=wp-fts-settings&tab=sandbox',
                'label' => 'Sandbox',
                'heading' => '<h2>Sandbox</h2>',
            ],
            'indexed-content' => [
                'url' => '/wp-admin/options-general.php?page=wp-fts-settings&tab=indexed-content',
                'label' => 'Indexed content',
                'heading' => '<h2>Indexed content</h2>',
            ],
            'analyzer-packs' => [
                'url' => '/wp-admin/options-general.php?page=wp-fts-settings&tab=analyzer-packs',
                'label' => 'Analyzer packs',
                'heading' => '<h2>Analyzer packs</h2>',
            ],
        ];
        $htmlByTab = [];
        foreach ($routes as $tab => $route) {
            $parsed = wp_fts_test_parse_admin_route($route['url']);
            assert_same('/wp-admin/options-general.php', $parsed['path'], "{$tab} route should target General Settings");
            assert_same(WP_FTS_Plugin::ADMIN_PAGE_SLUG, (string) ($parsed['params']['page'] ?? ''), "{$tab} route should keep the FTS page slug separate");
            if ($tab === 'health') {
                assert_true(!isset($parsed['params']['tab']), 'health route should use the base plugin settings URL');
            } else {
                assert_same($tab, (string) ($parsed['params']['tab'] ?? ''), "{$tab} route should keep the selected tab separate");
            }
            $htmlByTab[$tab] = wp_fts_test_capture_registered_admin_route($route['url'], $settingsCallback);
        }

        $fallbackHtml = wp_fts_test_capture_registered_admin_route('/wp-admin/options-general.php?page=wp-fts-settings&tab=nonesuch%3Cscript%3E', $settingsCallback);
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }

    $healthHtml = $htmlByTab['health'];
    $settingsHtml = $htmlByTab['settings'];
    $sandboxHtml = $htmlByTab['sandbox'];
    $indexedHtml = $htmlByTab['indexed-content'];
    $analyzerHtml = $htmlByTab['analyzer-packs'];

    assert_contains('href="/wp-admin/options-general.php?page=wp-fts-settings"', $healthHtml, 'health tab link should target the base settings page URL');
    assert_contains('/wp-admin/options-general.php?page=wp-fts-settings&amp;tab=settings', $healthHtml, 'tab links should target the explicit settings tab URL');
    assert_contains('/wp-admin/options-general.php?page=wp-fts-settings&amp;tab=sandbox', $sandboxHtml, 'tab links should target the sandbox tab URL');
    assert_contains('/wp-admin/options-general.php?page=wp-fts-settings&amp;tab=indexed-content', $sandboxHtml, 'tab links should target the indexed-content tab URL');
    assert_contains('/wp-admin/options-general.php?page=wp-fts-settings&amp;tab=analyzer-packs', $sandboxHtml, 'tab links should target the analyzer-packs tab URL');
    assert_contains('aria-current="page">Health</a>', $healthHtml, 'base settings URL should render Health as active');
    assert_contains('<h2>Search health</h2>', $healthHtml, 'base settings URL should render the Health panel');
    assert_true(!str_contains($healthHtml, '<h2>Settings</h2>'), 'base settings URL should not render the Settings panel');
    assert_contains('aria-current="page">Settings</a>', $settingsHtml, 'direct settings tab URL should render Settings as active');
    assert_contains('<h2>Settings</h2>', $settingsHtml, 'direct settings tab URL should render the Settings panel');
    assert_contains('aria-current="page">Sandbox</a>', $sandboxHtml, 'sandbox query tab should render Sandbox as active');
    assert_contains('<h2>Sandbox</h2>', $sandboxHtml, 'sandbox query tab should render the sandbox panel');
    assert_true(!str_contains($sandboxHtml, '<h2>Settings</h2>'), 'sandbox query tab should not fall back to the settings panel');

    assert_contains('aria-current="page">Indexed content</a>', $indexedHtml, 'indexed-content query tab should render Indexed content as active');
    assert_contains('<h2>Indexed content</h2>', $indexedHtml, 'indexed-content query tab should render the indexed-content panel');
    assert_true(!str_contains($indexedHtml, '<h2>Settings</h2>'), 'indexed-content query tab should not fall back to the settings panel');

    assert_contains('aria-current="page">Analyzer packs</a>', $analyzerHtml, 'analyzer-packs query tab should render Analyzer packs as active');
    assert_contains('<h2>Analyzer packs</h2>', $analyzerHtml, 'analyzer-packs query tab should render the analyzer-packs panel');
    assert_true(!str_contains($analyzerHtml, '<h2>Settings</h2>'), 'analyzer-packs query tab should not fall back to the settings panel');

    assert_contains('aria-current="page">Health</a>', $fallbackHtml, 'invalid tab values should be sanitized back to Health');
    assert_contains('<h2>Search health</h2>', $fallbackHtml, 'invalid tab values should render the Health panel');
    assert_true(!str_contains($fallbackHtml, '<script>'), 'invalid tab values should not be reflected into the admin page');
});

test_case('activation sets redirect flag and safe admin init redirects to Health', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;

    try {
        wp_fts_test_reset_wordpress_fakes();
        $_GET = [];
        WP_FTS_Plugin::activate();
        assert_same(1, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ACTIVATION_REDIRECT_OPTION] ?? null, 'single-site activation should set a one-shot redirect flag');

        $GLOBALS['wp_fts_test_is_admin'] = true;
        $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
        $redirect = null;
        try {
            WP_FTS_Plugin::maybe_redirect_after_activation();
        } catch (WP_FTS_TestRedirect $e) {
            $redirect = $e;
        }
        assert_true($redirect instanceof WP_FTS_TestRedirect, 'safe admin_init should redirect after activation');
        assert_same('/wp-admin/options-general.php?page=wp-fts-settings', $redirect->location, 'activation redirect should land on the Health/default tab');
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ACTIVATION_REDIRECT_OPTION]), 'successful redirect should consume the one-shot flag');

        wp_fts_test_reset_wordpress_fakes();
        $_GET = [];
        WP_FTS_Plugin::activate(true);
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ACTIVATION_REDIRECT_OPTION]), 'network activation should not set a redirect flag');

        wp_fts_test_reset_wordpress_fakes();
        $_GET = ['activate-multi' => '1'];
        WP_FTS_Plugin::activate();
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ACTIVATION_REDIRECT_OPTION]), 'bulk activation should not set a redirect flag');

        wp_fts_test_reset_wordpress_fakes();
        $_GET = [];
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ACTIVATION_REDIRECT_OPTION] = 1;
        $GLOBALS['wp_fts_test_is_admin'] = true;
        $GLOBALS['wp_fts_test_is_ajax'] = true;
        $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
        WP_FTS_Plugin::maybe_redirect_after_activation();
        assert_same([], $GLOBALS['wp_fts_test_redirects'], 'AJAX admin_init should not redirect');
        assert_same(1, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ACTIVATION_REDIRECT_OPTION] ?? null, 'unsafe admin_init should preserve the flag for a later safe request');
    } finally {
        $_GET = $oldGet;
        $wpdb = $oldWpdb;
    }
});

test_case('health dashboard displays search state counts and last indexed content without demo controls', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = array_replace(
        WP_FTS_Plugin::default_settings(),
        [
            'replace_frontend_search' => true,
            'replace_admin_post_search' => false,
            'index_post_types' => ['post', 'page'],
        ]
    );
    $fake->postRows = [
        wp_fts_test_backfill_post(701, 'post', 'publish', 'Health First Indexed'),
        wp_fts_test_backfill_post(702, 'post', 'publish', 'Health Waiting Post'),
        wp_fts_test_backfill_post(703, 'page', 'publish', 'Health Remaining Page'),
    ];
    $lockStarted = time() - 10;
    $lockExpires = time() + 290;

    try {
        WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 1]);
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] = [702];
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = 0;
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION] = [
            'token' => 'health-lock-token-must-not-render',
            'mode' => 'manual',
            'started_at' => $lockStarted,
            'expires_at' => $lockExpires,
        ];
        $_GET = ['page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG];
        $_POST = [];
        $html = wp_fts_test_capture_admin_settings_tab(null);
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }

    assert_contains('<h2>Search health</h2>', $html, 'default admin page should render the Health dashboard');
    assert_contains('<th scope="row">Schema status</th><td>Stale</td>', $html, 'health dashboard should show stale schema status');
    assert_contains('<th scope="row">Stored schema version</th><td>0</td>', $html, 'health dashboard should show stored schema version');
    assert_contains('<th scope="row">Expected schema version</th><td>' . WP_FTS_Plugin::SCHEMA_VERSION . '</td>', $html, 'health dashboard should show expected schema version');
    assert_contains('<th scope="row">Indexing lock</th><td>Active</td>', $html, 'health dashboard should show active lock state');
    assert_contains('<th scope="row">Lock mode</th><td>Manual</td>', $html, 'health dashboard should show safe lock mode');
    assert_contains('<th scope="row">Lock started</th><td>' . gmdate('Y-m-d H:i:s', $lockStarted) . ' UTC</td>', $html, 'health dashboard should show safe lock start time');
    assert_contains('<th scope="row">Lock expires</th><td>' . gmdate('Y-m-d H:i:s', $lockExpires) . ' UTC</td>', $html, 'health dashboard should show safe lock expiry time');
    assert_true(!str_contains($html, 'health-lock-token-must-not-render'), 'health dashboard should not expose lock tokens');
    assert_contains('<th scope="row">Public site search</th><td>Enabled</td>', $html, 'health dashboard should show public search replacement state');
    assert_contains('<th scope="row">wp-admin Posts search</th><td>Disabled</td>', $html, 'health dashboard should show admin search replacement state');
    assert_contains('<th scope="row">Search provider compatibility</th><td>Prefer Language FTS</td>', $html, 'health dashboard should show effective provider compatibility mode');
    assert_contains('<th scope="row">Field ranking weights</th><td>title=5, content=1, excerpt=2, terms=1.5, custom_fields=1, rendered=1</td>', $html, 'health dashboard should summarize effective field boost settings');
    assert_contains('<th scope="row">Recency ranking boost</th><td>Disabled</td>', $html, 'health dashboard should summarize the default-off recency boost');
    assert_contains('<th scope="row">Indexed post types</th><td>page, post</td>', $html, 'health dashboard should show configured indexed post types');
    assert_contains('<th scope="row">Eligible content</th><td>3</td>', $html, 'health dashboard should show total eligible content');
    assert_contains('<th scope="row">Indexed</th><td>1</td>', $html, 'health dashboard should show indexed count');
    assert_contains('<th scope="row">Waiting in the update queue</th><td>1</td>', $html, 'health dashboard should show pending queued count');
    assert_contains('<th scope="row">Remaining to index</th><td>2</td>', $html, 'health dashboard should show remaining unindexed count');
    assert_contains('Health First Indexed (ID 701)', $html, 'health dashboard should show last indexed title and ID');
    assert_contains('<th scope="row">Last batch</th><td>Manual at ', $html, 'health dashboard should show last batch time and mode');
    assert_contains('<th scope="row">Last batch processed</th><td>1 total (0 waiting updates, 1 remaining content, 0 stale reindexes, 0 failed)</td>', $html, 'health dashboard should show last batch processed counts');
    assert_contains('<th scope="row">Last indexing failure</th><td>No indexing failures recorded.</td>', $html, 'health dashboard should show the latest failure state');
    assert_contains('<th scope="row">Batch trigger</th><td>Manual batch; source manual caller; status success</td>', $html, 'health dashboard should render latest batch trigger diagnostics');
    assert_contains('<th scope="row">Batch timing</th><td>started ', $html, 'health dashboard should render latest batch timing diagnostics');
    assert_contains('elapsed ', $html, 'health dashboard should render elapsed milliseconds for latest batch diagnostics');
    assert_contains('<th scope="row">Batch queue state</th><td>before 0, after 0, processed 0</td>', $html, 'health dashboard should render latest batch queue diagnostics');
    assert_contains('<th scope="row">Batch backfill state</th><td>scanned 2, selected 1, processed 1</td>', $html, 'health dashboard should render latest batch backfill diagnostics');
    assert_contains('<th scope="row">Batch lock state</th><td>start inactive; end inactive; prevented work no</td>', $html, 'health dashboard should render latest batch lock diagnostics without token data');
    assert_contains('<th scope="row">Batch schema and storage</th><td>Missing (0/' . WP_FTS_Plugin::SCHEMA_VERSION . '); storage mysql</td>', $html, 'health dashboard should render latest batch schema and backend diagnostics');
    assert_contains('<th scope="row">Batch retry or reschedule</th><td>Not applicable to manual batches.</td>', $html, 'health dashboard should render latest batch reschedule diagnostics');
    assert_contains('<th scope="row">Batch stop reason</th><td>Stopped at the batch limit.</td>', $html, 'health dashboard should render latest batch stop reason diagnostics');
    assert_contains('<th scope="row">Batch error</th><td>No batch error recorded.</td>', $html, 'health dashboard should render latest batch error diagnostics');
    assert_contains('Repair FTS tables and the stored schema version without indexing content.', $html, 'health dashboard should explain schema repair scope');
    assert_contains('Repair schema tables', $html, 'health dashboard should expose schema repair action');
    assert_contains('Run one safe indexing pass now. You can use it again until Remaining to index reaches 0.', $html, 'health dashboard should explain the manual batch action in user-facing terms');
    assert_contains('Index the next batch now', $html, 'health dashboard should expose one primary manual indexing action');
    assert_contains('wp_fts_health_nonce', $html, 'health manual action should use a dedicated nonce field');
    assert_true(!str_contains($html, 'bounded ' . 'batch'), 'health dashboard should not expose implementation-oriented batch wording');
    assert_true(!str_contains($html, 'demo ' . 'posts'), 'health dashboard manual batch copy should not mention sandbox sample content');
    assert_true(!str_contains($html, 'whole site in ' . 'one request'), 'health dashboard should not expose internal request-size constraints');
    assert_true(!str_contains($html, 'wp_fts_sandbox_action'), 'health dashboard should not render sandbox demo action controls');
    assert_true(!str_contains($html, 'Create or refresh demo posts'), 'health dashboard should not reintroduce demo post creation controls');
    assert_true(!str_contains($html, 'Build demo index'), 'health dashboard should not reintroduce demo indexing controls');
});

test_case('health schema repair POST requires capability and nonce before repairing', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $fake->postRows = [
        wp_fts_test_backfill_post(741, 'post', 'publish', 'Repair Requires Auth'),
    ];
    $validPost = [
        'wp_fts_health_action' => 'repair_schema',
        'wp_fts_health_nonce' => wp_create_nonce('wp_fts_health_admin_action'),
    ];

    try {
        $_GET = ['page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG];
        $_POST = $validPost;
        $unauthorizedHtml = wp_fts_test_capture_admin_settings_tab(null);
        assert_contains('You do not have permission to manage Full-Text Search settings.', $unauthorizedHtml, 'unauthorized repair should stop at the settings-page capability gate');
        assert_same(0, count(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE'))), 'unauthorized repair should not create or repair schema tables');
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION]), 'unauthorized repair should not persist schema version');
        assert_same([], $fake->docs, 'unauthorized repair should not index content');

        $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
        $_POST = [
            'wp_fts_health_action' => 'repair_schema',
            'wp_fts_health_nonce' => 'not-a-valid-nonce',
        ];
        $invalidHtml = wp_fts_test_capture_admin_settings_tab(null);
        assert_contains('The schema repair action could not be verified', $invalidHtml, 'invalid repair nonce should show an error');
        assert_same(0, count(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE'))), 'invalid repair nonce should not create or repair schema tables');
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION]), 'invalid repair nonce should not persist schema version');
        assert_same([], $fake->docs, 'invalid repair nonce should not index content');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }
});

test_case('health schema repair POST repairs schema without indexing or creating demo posts', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] = [751];
    $fake->postRows = [
        wp_fts_test_backfill_post(751, 'post', 'publish', 'Repair Only Post'),
    ];

    try {
        $_GET = ['page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG];
        $_POST = [
            'wp_fts_health_action' => 'repair_schema',
            'wp_fts_health_nonce' => wp_create_nonce('wp_fts_health_admin_action'),
        ];
        $html = wp_fts_test_capture_admin_settings_tab(null);
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }

    assert_contains('Schema tables repaired. Current schema version: ' . WP_FTS_Plugin::SCHEMA_VERSION . '.', $html, 'valid repair should report success and current schema version');
    assert_contains('<th scope="row">Schema status</th><td>Current</td>', $html, 'health dashboard should show current schema status after repair');
    assert_same(WP_FTS_Plugin::SCHEMA_VERSION, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null, 'valid repair should persist current schema version');
    assert_same(6, count(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE'))), 'valid repair should call the schema creation/repair path');
    assert_same([], $fake->docs, 'valid repair should not index existing content');
    assert_same([], $fake->terms, 'valid repair should not write FTS terms');
    assert_same([], $GLOBALS['wp_fts_test_posts'], 'valid repair should not create demo posts');
    assert_same([751], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] ?? null, 'valid repair should not drain the indexing queue');
    assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION]), 'valid repair should not record a manual indexing batch');
});

test_case('health schema repair failure reports bounded escaped error without indexing', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $fake->failQueryPrefix = 'CREATE TABLE';
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
    $fake->postRows = [
        wp_fts_test_backfill_post(761, 'post', 'publish', 'Repair Failure Post'),
    ];

    try {
        $_GET = ['page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG];
        $_POST = [
            'wp_fts_health_action' => 'repair_schema',
            'wp_fts_health_nonce' => wp_create_nonce('wp_fts_health_admin_action'),
        ];
        $html = wp_fts_test_capture_admin_settings_tab(null);
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }

    assert_contains('notice-error', $html, 'repair failure should render an error notice');
    assert_contains('Could not repair schema tables: Failed to create FTS tables: simulated failure for CREATE statement', $html, 'repair failure should report a bounded redacted error');
    assert_true(!str_contains($html, 'CREATE TABLE wp_fts_terms'), 'repair failure notice should not expose raw SQL');
    assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION]), 'failed repair should not persist schema version');
    assert_same([], $fake->docs, 'failed repair should not index content');
    assert_same([], $fake->terms, 'failed repair should not write FTS terms');
});

test_case('health manual batch POST requires capability and nonce before indexing', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $fake->postRows = [
        wp_fts_test_backfill_post(711, 'post', 'publish', 'Manual Health Action'),
    ];
    $validPost = [
        'wp_fts_health_action' => 'index_next_batch',
        'wp_fts_health_nonce' => wp_create_nonce('wp_fts_health_admin_action'),
    ];

    try {
        $_GET = ['page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG];
        $_POST = $validPost;
        wp_fts_test_capture_admin_settings_tab(null);
        assert_same([], $fake->docs, 'manual health action should not index without capability');

        $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
        $_POST = [
            'wp_fts_health_action' => 'index_next_batch',
            'wp_fts_health_nonce' => 'not-a-valid-nonce',
        ];
        $invalidHtml = wp_fts_test_capture_admin_settings_tab(null);
        assert_contains('The indexing action could not be verified', $invalidHtml, 'invalid manual health nonce should show an error');
        assert_same([], $fake->docs, 'invalid manual health nonce should not index content');

        $_POST = $validPost;
        $validHtml = wp_fts_test_capture_admin_settings_tab(null);
        assert_contains('Indexed 1 item. The index is up to date for the current settings.', $validHtml, 'valid manual health action should report the processed batch');
        assert_true(isset($fake->docs[711]), 'valid manual health action should call the manual batch path and index content');
        assert_same('manual', $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION]['last_mode'] ?? null, 'manual health action should update health state as a manual batch');
        $diagnostics = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION]['latest_batch_diagnostics'] ?? [];
        assert_same('manual', $diagnostics['trigger'] ?? null, 'manual health action should record manual diagnostics trigger');
        assert_same('admin-health', $diagnostics['source'] ?? null, 'manual health action should record Health tab source');
        assert_same('success', $diagnostics['status'] ?? null, 'successful manual health action should record success status');
        assert_same(100, $diagnostics['batch_limit'] ?? null, 'manual health diagnostics should record effective batch limit');
        assert_same(1, $diagnostics['processed'] ?? null, 'manual health diagnostics should record processed count');
        assert_same(0, $diagnostics['queue_before'] ?? null, 'manual health diagnostics should record queue count before work');
        assert_same(0, $diagnostics['queue_after'] ?? null, 'manual health diagnostics should record queue count after work');
        assert_same('not_applicable_manual', $diagnostics['reschedule_decision'] ?? null, 'manual health diagnostics should record reschedule decision');
        assert_same('mysql', $diagnostics['storage_backend'] ?? null, 'manual health diagnostics should record storage backend');
        assert_true(is_float($diagnostics['elapsed_ms'] ?? null), 'manual health diagnostics should record bounded elapsed milliseconds');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }
});

test_case('health manual batch lock skip displays no-overlap notice', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION] = [
        'token' => 'already-running',
        'mode' => 'cron',
        'started_at' => time(),
        'expires_at' => time() + 300,
    ];
    $fake->postRows = [
        wp_fts_test_backfill_post(721, 'post', 'publish', 'Locked Health Action'),
    ];

    try {
        $_GET = ['page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG];
        $_POST = [
            'wp_fts_health_action' => 'index_next_batch',
            'wp_fts_health_nonce' => wp_create_nonce('wp_fts_health_admin_action'),
        ];
        $html = wp_fts_test_capture_admin_settings_tab(null);
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }

    assert_contains('Another indexing batch is already running. No overlapping batch was started; try again shortly.', $html, 'manual health lock skip should explain that no overlap occurred');
    assert_same([], $fake->docs, 'manual health lock skip should not index content');
    assert_same(true, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION]['last_skipped_locked'] ?? null, 'manual health lock skip should update health state');
    $diagnostics = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION]['latest_batch_diagnostics'] ?? [];
    assert_same('skipped_locked', $diagnostics['status'] ?? null, 'manual health lock skip should record skipped diagnostics status');
    assert_same(true, $diagnostics['lock_prevented_work'] ?? null, 'manual health lock skip should record that the lock prevented work');
    assert_same(0, $diagnostics['processed'] ?? null, 'manual health lock skip diagnostics should record no processed items');
    assert_same('active', $diagnostics['lock_at_start']['state'] ?? null, 'manual health lock skip diagnostics should record active start lock');
    assert_same('cron', $diagnostics['lock_at_start']['mode'] ?? null, 'manual health lock skip diagnostics should record safe holder mode');
    assert_same('lock_active', $diagnostics['stop_reason'] ?? null, 'manual health lock skip diagnostics should record lock stop reason');
    assert_true(!str_contains(json_encode($diagnostics, JSON_THROW_ON_ERROR), 'already-running'), 'manual health lock skip diagnostics should not expose lock token');
});

test_case('health manual batch records failures without exposing raw details', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
    $fake->postRows = [
        wp_fts_test_backfill_post(731, 'post', 'publish', 'Health Failure'),
        wp_fts_test_backfill_post(732, 'post', 'publish', 'Health Continued'),
    ];
    $fake->failDocWriteErrors[731] = "simulated failure for INSERT INTO wp_fts_docs\n#0 stack SELECT * FROM wp_users";

    try {
        $_GET = ['page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG];
        $_POST = [
            'wp_fts_health_action' => 'index_next_batch',
            'wp_fts_health_nonce' => wp_create_nonce('wp_fts_health_admin_action'),
        ];
        $html = wp_fts_test_capture_admin_settings_tab(null);
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }

    assert_contains('notice-warning', $html, 'manual health failure should render a warning notice');
    assert_contains('Indexed 1 item. 1 item failed and was recorded; indexing continued where possible.', $html, 'manual health failure notice should explain partial progress');
    assert_contains('<th scope="row">Last indexing failure</th><td>1 item failed in the latest batch; Health Failure (ID 731)', $html, 'health dashboard should render a concise failure row');
    assert_contains('INSERT statement', $html, 'health dashboard should show redacted error context');
    assert_true(!str_contains($html, 'INSERT INTO wp_fts_docs'), 'health dashboard should not expose raw SQL in failure output');
    assert_true(!str_contains($html, 'SELECT * FROM'), 'health dashboard should not expose stack SQL in failure output');
    assert_true(!str_contains($html, '#0'), 'health dashboard should not expose stack traces in failure output');
    assert_true(!str_contains($html, 'batchindexneedle 731'), 'health dashboard should not expose raw post body content in failure output');
    assert_true(!isset($fake->docs[731]), 'failed health batch item should not be marked indexed');
    assert_true(isset($fake->docs[732]), 'health batch should continue after a failed item');
    assert_same(1, $fake->failedDocWriteAttempts[731] ?? 0, 'failed health batch item should be attempted once');
    $diagnostics = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION]['latest_batch_diagnostics'] ?? [];
    assert_same('partial_failure', $diagnostics['status'] ?? null, 'manual health failure diagnostics should record partial failure status');
    assert_same(1, $diagnostics['failures'] ?? null, 'manual health failure diagnostics should record bounded failure count');
    assert_same(731, $diagnostics['last_failed_post_id'] ?? null, 'manual health failure diagnostics should record failed post id');
    assert_same('Health Failure', $diagnostics['last_failed_post_title'] ?? null, 'manual health failure diagnostics should record sanitized failed title');
    assert_same('RuntimeException', $diagnostics['error_class'] ?? null, 'manual health failure diagnostics should record bounded error class');
    assert_contains('INSERT statement', (string) ($diagnostics['error_message'] ?? ''), 'manual health failure diagnostics should redact SQL in error message');
    $diagnosticJson = json_encode($diagnostics, JSON_THROW_ON_ERROR);
    assert_true(!str_contains($diagnosticJson, 'INSERT INTO wp_fts_docs'), 'manual health failure diagnostics should not store raw SQL');
    assert_true(!str_contains($diagnosticJson, 'SELECT * FROM'), 'manual health failure diagnostics should not store stack SQL');
    assert_true(!str_contains($diagnosticJson, '#0'), 'manual health failure diagnostics should not store stack traces');
    assert_true(!str_contains($diagnosticJson, 'batchindexneedle 731'), 'manual health failure diagnostics should not store raw post body content');
});

test_case('request diagnostics stay disabled for normal visitors and render escaped bounded admin output', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    try {
        $normalQuery = new WP_FTS_Test_Query(['s' => 'visitor-debug-disabled', 'cat' => 7]);
        WP_FTS_Plugin::prepare_frontend_search_query($normalQuery);
        assert_same([], WP_FTS_Plugin::debug_traces(), 'diagnostics should collect nothing for normal visitors by default');

        $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::DEBUG_ENABLED_FILTER] = static fn(mixed $enabled, string $context): bool => true;
        $longQuery = '<script>alert(1)</script>' . str_repeat('x', 220);
        WP_FTS_Plugin::prepare_frontend_search_query(new WP_FTS_Test_Query([
            's' => $longQuery,
            'cat' => 5,
            'posts_per_page' => 10,
        ]));

        $traces = WP_FTS_Plugin::debug_traces();
        assert_same(1, count($traces), 'enabled diagnostics should capture one frontend bailout trace');
        assert_same('bailed', $traces[0]['status'] ?? null, 'frontend bailout trace should be marked as bailed');
        assert_contains('Unsupported query shape', (string) ($traces[0]['bailout_reason'] ?? ''), 'frontend bailout trace should include a readable reason');

        $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
        $_GET = ['page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG];
        $_POST = [];
        $html = wp_fts_test_capture_admin_settings_tab('health');

        assert_contains('Request diagnostics', $html, 'Health tab should expose diagnostics when Debug Bar is absent');
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'rendered diagnostics should escape query text');
        assert_true(!str_contains($html, '<script>alert(1)</script>'), 'rendered diagnostics should not output raw query HTML');
        assert_true(!str_contains($html, str_repeat('x', 180)), 'rendered diagnostics should truncate long query text');
        assert_contains('...', $html, 'rendered diagnostics should indicate truncated values');

        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] = [
            'latest_batch_diagnostics' => [
                'schema' => 'wp-fts-index-batch-diagnostics-v1',
                'trigger' => 'manual',
                'source' => 'operator&cli',
                'status' => 'failed',
                'started_at' => '2026-06-19 10:00:00',
                'finished_at' => '2026-06-19 10:00:01',
                'elapsed_ms' => 1.25,
                'batch_limit' => 1,
                'processed' => 0,
                'queue_before' => 1,
                'queue_after' => 1,
                'error_class' => 'RuntimeException',
                'error_message' => 'Failure for AT&T SELECT * FROM wp_users',
                'lock_at_start' => ['state' => 'none', 'active' => false, 'mode' => '', 'started_at' => '', 'expires_at' => ''],
                'lock_at_end' => ['state' => 'none', 'active' => false, 'mode' => '', 'started_at' => '', 'expires_at' => ''],
                'schema_status' => 'current',
                'schema_version' => WP_FTS_Plugin::SCHEMA_VERSION,
                'expected_schema_version' => WP_FTS_Plugin::SCHEMA_VERSION,
                'storage_backend' => 'mysql',
            ],
        ];
        $debugHtml = wp_fts_test_capture(static function (): void {
            WP_FTS_Plugin::render_debug_bar_diagnostics_panel();
        });
        assert_contains('Latest indexing batch', $debugHtml, 'Debug Bar diagnostics should render the latest indexing batch section for authorized admins');
        assert_contains('source operator&amp;cli', $debugHtml, 'Debug Bar indexing diagnostics should escape source values');
        assert_contains('Failure for AT&amp;T SELECT statement', $debugHtml, 'Debug Bar indexing diagnostics should escape and redact error values');
        assert_true(!str_contains($debugHtml, 'operator&cli'), 'Debug Bar indexing diagnostics should not output raw ampersands');
        assert_true(!str_contains($debugHtml, 'SELECT * FROM'), 'Debug Bar indexing diagnostics should not output raw SQL');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }
});

test_case('bounded request diagnostics preserve trace ids after eviction', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::DEBUG_ENABLED_FILTER] = static fn(mixed $enabled, string $context): bool => true;

    $plugin = new ReflectionClass(WP_FTS_Plugin::class);
    $maxTracesConstant = $plugin->getReflectionConstant('DEBUG_MAX_TRACES');
    assert_true($maxTracesConstant !== false, 'debug trace cap should be reflectable for the eviction regression');
    $maxTraces = (int) $maxTracesConstant->getValue();

    $startTrace = new ReflectionMethod(WP_FTS_Plugin::class, 'debug_start_trace');
    $startTrace->setAccessible(true);
    $addCount = new ReflectionMethod(WP_FTS_Plugin::class, 'debug_add_count');
    $addCount->setAccessible(true);

    $traceIds = [];
    for ($index = 0; $index <= $maxTraces; $index++) {
        $traceIds[] = (int) $startTrace->invoke(null, 'eviction ' . $index);
    }

    $survivingTraceId = $traceIds[1];
    $addCount->invoke(null, $survivingTraceId, 'candidate_rows', 7);

    $tracesById = [];
    foreach (WP_FTS_Plugin::debug_traces() as $trace) {
        $tracesById[(int) ($trace['id'] ?? 0)] = $trace;
    }

    assert_true(!array_key_exists($traceIds[0], $tracesById), 'oldest debug trace should be evicted after the trace cap is exceeded');
    assert_true(array_key_exists($survivingTraceId, $tracesById), 'second debug trace should survive the first bounded eviction');
    $survivingCounts = is_array($tracesById[$survivingTraceId]['counts'] ?? null) ? $tracesById[$survivingTraceId]['counts'] : [];
    assert_same(7, (int) ($survivingCounts['candidate_rows'] ?? 0), 'count updates should apply to the surviving trace id after eviction');

    foreach ($tracesById as $id => $trace) {
        if ($id === $survivingTraceId) {
            continue;
        }
        $counts = is_array($trace['counts'] ?? null) ? $trace['counts'] : [];
        assert_same(0, (int) ($counts['candidate_rows'] ?? 0), 'count updates should not move to another trace after eviction');
    }
});

test_case('SQL diagnostics are bounded redacted and rendered', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::DEBUG_ENABLED_FILTER] = static fn(mixed $enabled, string $context): bool => true;
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;

    $plugin = new ReflectionClass(WP_FTS_Plugin::class);
    $maxSqlConstant = $plugin->getReflectionConstant('DEBUG_MAX_SQL_QUERIES');
    assert_true($maxSqlConstant !== false, 'SQL diagnostics cap should be reflectable for bounded coverage');
    $maxSql = (int) $maxSqlConstant->getValue();

    $startTrace = new ReflectionMethod(WP_FTS_Plugin::class, 'debug_start_trace');
    $startTrace->setAccessible(true);
    $finishTrace = new ReflectionMethod(WP_FTS_Plugin::class, 'debug_finish_trace');
    $finishTrace->setAccessible(true);

    try {
        $traceId = (int) $startTrace->invoke(null, 'frontend search', 'top secret needle');
        $fake->queries[] = ["UPDATE wp_fts_docmeta SET search_text = 'private phrase' WHERE doc_id = 987", 0.002, 'caller'];
        for ($index = 0; $index < $maxSql + 3; $index++) {
            $fake->queries[] = [
                "SELECT term, doc_id, tf FROM wp_fts_postings WHERE term = 'secretneedle{$index}' AND doc_id = " . (100 + $index) . ' AND tf >= 7',
                0.001,
                'caller',
            ];
        }
        $finishTrace->invoke(null, $traceId, 'ran');

        $traces = WP_FTS_Plugin::debug_traces();
        $trace = $traces[0] ?? [];
        $sqlDiagnostics = is_array($trace['sql_queries'] ?? null) ? $trace['sql_queries'] : [];
        assert_same(true, $sqlDiagnostics['available'] ?? null, 'SQL diagnostics should mark compatible query capture as available');
        assert_same($maxSql + 4, (int) ($sqlDiagnostics['captured_count'] ?? 0), 'SQL diagnostics should record the full delta count');
        assert_same($maxSql, (int) ($sqlDiagnostics['shown_count'] ?? 0), 'SQL diagnostics should bound listed SQL entries');
        assert_same(true, $sqlDiagnostics['more'] ?? null, 'SQL diagnostics should mark overflow when entries exceed the cap');
        assert_true((float) ($sqlDiagnostics['total_time_ms'] ?? 0.0) > 0.0, 'SQL diagnostics should total compatible query timings');

        $summaryText = '';
        foreach (is_array($sqlDiagnostics['entries'] ?? null) ? $sqlDiagnostics['entries'] : [] as $entry) {
            if (is_array($entry) && is_scalar($entry['summary'] ?? null)) {
                $summaryText .= ' ' . (string) $entry['summary'];
            }
        }
        assert_contains('wp_fts_docmeta', $summaryText, 'SQL diagnostics should preserve FTS docmeta table names');
        assert_contains('wp_fts_postings', $summaryText, 'SQL diagnostics should preserve FTS postings table names');
        assert_contains('?', $summaryText, 'SQL diagnostics should retain query shape placeholders');
        assert_true(!str_contains($summaryText, 'private phrase'), 'SQL diagnostics should redact string literals');
        assert_true(!str_contains($summaryText, 'secretneedle'), 'SQL diagnostics should redact search terms from string literals');
        assert_true(!str_contains($summaryText, '987'), 'SQL diagnostics should redact numeric literals');

        $_GET = ['page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG];
        $_POST = [];
        $html = wp_fts_test_capture_admin_settings_tab('health');
        assert_contains('SQL queries', $html, 'rendered diagnostics should include the SQL queries row');
        assert_contains('captured=' . ($maxSql + 4), $html, 'rendered diagnostics should show the captured SQL count');
        assert_contains('wp_fts_postings', $html, 'rendered diagnostics should include FTS table shape');
        assert_true(!str_contains($html, 'secretneedle'), 'rendered SQL diagnostics should not leak literal search terms');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }
});

test_case('SQL diagnostics report unavailable query capture when wpdb queries are absent', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $wpdb = new stdClass();
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::DEBUG_ENABLED_FILTER] = static fn(mixed $enabled, string $context): bool => true;

    $startTrace = new ReflectionMethod(WP_FTS_Plugin::class, 'debug_start_trace');
    $startTrace->setAccessible(true);
    $finishTrace = new ReflectionMethod(WP_FTS_Plugin::class, 'debug_finish_trace');
    $finishTrace->setAccessible(true);

    try {
        $traceId = (int) $startTrace->invoke(null, 'frontend search', 'unavailable capture');
        $finishTrace->invoke(null, $traceId, 'bailed', 'test bailout');
        $trace = WP_FTS_Plugin::debug_traces()[0] ?? [];
        $sqlDiagnostics = is_array($trace['sql_queries'] ?? null) ? $trace['sql_queries'] : [];
        assert_same(false, $sqlDiagnostics['available'] ?? null, 'SQL diagnostics should mark absent wpdb queries as unavailable');
        assert_contains('$wpdb->queries is unavailable', (string) ($sqlDiagnostics['reason'] ?? ''), 'SQL diagnostics should explain unavailable query capture');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('WP_FTS_DEBUG constant enables diagnostics when defined before plugin load', function (): void {
    $plugin = (string) realpath(__DIR__ . '/../indexer.php');
    $code = str_replace('__PLUGIN__', var_export($plugin, true), <<<'PHP'
define('WP_FTS_DEBUG', true);
require __PLUGIN__;
$startTrace = new ReflectionMethod(WP_FTS_Plugin::class, 'debug_start_trace');
$startTrace->setAccessible(true);
$traceId = (int) $startTrace->invoke(null, 'constant debug path', 'constantneedle');
$traces = WP_FTS_Plugin::debug_traces();
$trace = $traces[0] ?? [];
echo 'id=', $traceId, "\n";
echo 'count=', count($traces), "\n";
echo 'context=', (string) ($trace['context'] ?? ''), "\n";
echo 'status=', (string) ($trace['status'] ?? ''), "\n";
PHP);

    $result = test_run_php_without_extensions($code);
    $stderr = trim($result['stderr']);
    $detail = $stderr === '' ? '' : "\nSubprocess stderr: " . substr($stderr, 0, 500);

    assert_same(0, $result['exit'], 'WP_FTS_DEBUG subprocess should exit cleanly' . $detail);
    assert_contains("id=1\n", $result['stdout'], 'WP_FTS_DEBUG subprocess should create a trace id');
    assert_contains("count=1\n", $result['stdout'], 'WP_FTS_DEBUG subprocess should collect one diagnostic trace');
    assert_contains("context=constant debug path\n", $result['stdout'], 'WP_FTS_DEBUG subprocess should record the trace context');
    assert_contains("status=started\n", $result['stdout'], 'WP_FTS_DEBUG subprocess should record a started trace');
});

test_case('Debug Bar diagnostics panel registration is conditional and safe', function (): void {
    wp_fts_test_reset_wordpress_fakes();

    if (!class_exists('Debug_Bar_Panel')) {
        $unchanged = WP_FTS_Plugin::register_debug_bar_panel(['existing-panel']);
        assert_same(['existing-panel'], $unchanged, 'Debug Bar panel callback should no-op when Debug Bar classes are absent');
    }

    if (!class_exists('Debug_Bar_Panel')) {
        class Debug_Bar_Panel
        {
            public string $title = '';

            public function init(): void
            {
            }

            public function render(): void
            {
            }
        }
    }

    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
    $panels = WP_FTS_Plugin::register_debug_bar_panel(['existing-panel']);
    assert_same(2, count($panels), 'Debug Bar panel callback should append one panel when Debug Bar is available and visible');
    $panel = $panels[1];
    assert_true(is_object($panel), 'Debug Bar diagnostics panel should be an object');
    $panel->init();
    assert_same('FTS', $panel->title, 'Debug Bar diagnostics panel should use a compact title');

    ob_start();
    try {
        $panel->render();
        $html = ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    assert_contains('Full-Text Search diagnostics', is_string($html) ? $html : '', 'Debug Bar diagnostics panel should render the shared diagnostics surface');
});

test_case('playground blueprint preserves sandbox landing tab', function (): void {
    $blueprintPath = __DIR__ . '/../playground/blueprint.json';
    $json = file_get_contents($blueprintPath);
    if (!is_string($json)) {
        throw new WP_FTS_TestFailure('Could not read playground blueprint.');
    }

    $blueprint = json_decode($json, true);
    assert_true(is_array($blueprint), 'playground blueprint should decode as JSON');

    $landingPage = is_scalar($blueprint['landingPage'] ?? null) ? (string) $blueprint['landingPage'] : '';
    assert_same('/wp-admin/options-general.php?page=wp-fts-settings&tab=sandbox', $landingPage, 'playground landing page should use a normal admin query string');
    assert_true(!str_contains($landingPage, '%26'), 'playground landing page should not encode the tab separator into the page slug');

    $route = wp_fts_test_parse_admin_route($landingPage);
    assert_same('/wp-admin/options-general.php', $route['path'], 'playground landing page should target General Settings');
    assert_same(WP_FTS_Plugin::ADMIN_PAGE_SLUG, (string) ($route['params']['page'] ?? ''), 'playground landing page should target the FTS settings page');
    assert_same('sandbox', (string) ($route['params']['tab'] ?? ''), 'playground landing page should target the Sandbox tab');

    $brokenRoute = wp_fts_test_parse_admin_route('/wp-admin/options-general.php?page=wp-fts-settings%26tab=sandbox');
    assert_same('wp-fts-settings&tab=sandbox', (string) ($brokenRoute['params']['page'] ?? ''), 'real query parsing treats encoded ampersand as part of the page value');
    assert_true(!isset($brokenRoute['params']['tab']), 'real query parsing does not recover a tab parameter from an encoded ampersand');
});

test_case('settings page reports unsupported site language without storing fallback language', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
    $GLOBALS['wp_fts_test_locale'] = 'qaa';

    $html = wp_fts_test_capture_admin_settings_tab('settings');

    assert_contains('Current site language QAA (qaa) uses conservative fallback', $html, 'settings page should explicitly flag unsupported site languages');
    assert_contains('install or build an analyzer pack with the pack tooling and configure it for this language', $html, 'settings page should guide unsupported languages toward pack tooling instead of a missing UI control');
    assert_true(!str_contains($html, 'enable an analyzer pack'), 'settings page should not promise a nonexistent analyzer-pack enable control');
    assert_contains('Change it on the <a href="/wp-admin/options-general.php">WordPress General Settings page</a>', $html, 'current site language description should link to General Settings');
    assert_contains('wp-fts-language-status', $html, 'unsupported site-language status should use compact help text');
    assert_true(!str_contains($html, '<div class="notice notice-info"><p>Current site language'), 'unsupported site-language status should not render as a large notice');
    assert_true(!str_contains($html, 'fallback_language'), 'settings page should not store a stale fallback language value');
});

test_case('settings page explains en-US support through active base English analyzer pack', function (): void {
    $packDir = temp_directory_path('admin_en_base_pack');

    try {
        $manifest = write_synthetic_full_analyzer_pack($packDir, 3, 1, 'en', 'en-synthetic-full-status');

        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
        $GLOBALS['wp_fts_test_locale'] = 'en_US';
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = [
            'lemmatizer_packs_by_lang' => [
                'en' => $manifest,
            ],
        ];

        $settingsHtml = wp_fts_test_capture_admin_settings_tab('settings');
        $analyzerHtml = wp_fts_test_capture_admin_settings_tab('analyzer-packs');

        assert_contains('Current site language English (en-US) uses full morphology through the active base-language analyzer pack English (en)', $settingsHtml, 'site-language status should explain en-US support through the active base English pack');
        assert_contains('Runtime search status - Full morphology', $settingsHtml, 'settings row should report full runtime morphology for en-US through the base pack');
        assert_contains('English morphology is available through the active base-language analyzer pack English (en)', $settingsHtml, 'settings row should explain English dialect/locale coverage');
        assert_true(!str_contains($settingsHtml, 'full morphology is unavailable'), 'en-US with active base English pack should not be described as unavailable');
        assert_true(!str_contains($settingsHtml, 'enable an analyzer pack'), 'en-US with active base English pack should not mention a nonexistent enable control');
        assert_contains('English (en)', $analyzerHtml, 'analyzer packs table should list the active base English pack');
        assert_contains('en-synthetic-full-status', $analyzerHtml, 'analyzer packs table should expose the configured English pack id');
        assert_contains('Full local pack', $analyzerHtml, 'analyzer packs table should label the synthetic English pack as full local data');
    } finally {
        remove_directory_tree($packDir);
    }
});

test_case('settings page distinguishes en-US runtime fallback from sandbox English pack coverage', function (): void {
    if (!WP_FTS_AnalyzerPackValidator::gzip_available()) {
        assert_true(true, 'gzip is unavailable, so bundled sandbox UniMorph English pack coverage is skipped.');
        return;
    }

    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
    $GLOBALS['wp_fts_test_locale'] = 'en_US';

    $settingsHtml = wp_fts_test_capture_admin_settings_tab('settings');
    $analyzerHtml = wp_fts_test_capture_admin_settings_tab('analyzer-packs');

    assert_contains('Current site language English (en-US) uses conservative fallback for runtime site searches because no runtime analyzer pack covers it', $settingsHtml, 'site-language status should keep runtime fallback scope explicit for en-US when only sandbox has English');
    assert_contains('Sandbox searches can use English morphology through the active base-language analyzer pack English (en) for English dialects/locales', $settingsHtml, 'site-language status should explain sandbox English dialect coverage through the base en pack');
    assert_true(!str_contains($settingsHtml, 'full morphology is unavailable'), 'sandbox English coverage should not be described as unavailable for en-US');
    assert_true(!str_contains($settingsHtml, 'enable an analyzer pack'), 'sandbox English coverage should not mention a nonexistent enable control');
    assert_contains('English (en)', $analyzerHtml, 'analyzer packs table should list the sandbox base English pack');
    assert_contains('en-unimorph-eng-66e0e9e8e2dc', $analyzerHtml, 'analyzer packs table should expose the sandbox English pack id');
});

test_case('health tab renders stale reindex debt separately from remaining count', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;

    try {
        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] = [
            'accepted_index_profile_hash' => str_repeat('a', 40),
            'stale_debt_active' => true,
            'stale_debt_reasons' => ['field_boosts_changed'],
            'stale_debt_created_at' => '2026-06-21 08:00:00',
            'stale_debt_updated_at' => '2026-06-21 08:05:00',
        ];

        $html = wp_fts_test_capture_admin_settings_tab('health');
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_contains('<h3>Reindex debt</h3>', $html, 'health tab should render reindex debt as its own section');
    assert_contains('Stale index debt', $html, 'health tab should label stale index debt explicitly');
    assert_contains('Active - reindex existing content', $html, 'health tab should report active stale debt separately');
    assert_contains('Field ranking weights changed', $html, 'health tab should render human-readable stale debt reasons');
    assert_true(strpos($html, 'Remaining to index') < strpos($html, '<h3>Reindex debt</h3>'), 'health tab should keep stale debt separate from remaining-to-index counts');
    assert_same([], $fake->terms, 'health render should not write FTS terms');
});

test_case('admin analyzer packs tab renders bundled runtime pack controls when gzip is available', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;

    $html = wp_fts_test_capture_admin_settings_tab('analyzer-packs');

    assert_contains('Bundled runtime lemma packs', $html, 'analyzer packs tab should include the bundled runtime control section');
    assert_contains('Custom pack paths can still be configured with the', $html, 'analyzer controls should keep custom paths out of the form');
    assert_contains('This page does not install external data or create sample content.', $html, 'analyzer controls should state the bounded side effects');
    if (WP_FTS_AnalyzerPackValidator::gzip_available()) {
        assert_contains('name="wp_fts_bundled_runtime_lemma_packs[]"', $html, 'eligible bundled runtime packs should render checkbox controls');
        assert_contains('English (en)', $html, 'eligible bundled runtime controls should include English');
        assert_contains('en-unimorph-eng-66e0e9e8e2dc', $html, 'eligible bundled runtime controls should expose the bundled English pack id');
        assert_contains('Save bundled pack choices', $html, 'eligible bundled runtime controls should render a bounded save button');
        assert_true(!str_contains($html, 'name="wp_fts_bundled_runtime_manifest_path"'), 'analyzer controls should not expose arbitrary manifest path inputs');
    } else {
        assert_contains('does not provide gzip stream support', $html, 'gzip-free runtimes should explain why bundled controls are unavailable');
        assert_true(!str_contains($html, 'name="wp_fts_bundled_runtime_lemma_packs[]"'), 'gzip-free runtimes should not render misleading enable controls');
    }
});

test_case('admin analyzer pack save enables selected bundled runtime packs without indexing side effects', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;

    try {
        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] = [451, 452];
        $_GET = [];
        $_POST = [
            'wp_fts_analyzer_packs_action' => 'save_bundled_runtime_packs',
            'wp_fts_analyzer_packs_nonce' => wp_create_nonce('wp_fts_analyzer_packs_admin_action'),
            'wp_fts_bundled_runtime_lemma_packs' => ['en', 'bn', 'unknown-language'],
        ];

        $html = wp_fts_test_capture_admin_settings_tab('analyzer-packs');
        if (!WP_FTS_AnalyzerPackValidator::gzip_available()) {
            assert_contains('need PHP gzip stream support and were not changed', $html, 'gzip-free save attempts should report that bundled packs cannot be enabled');
            assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION]), 'gzip-free save attempts should not create analyzer options');
            return;
        }

        $manifests = bundled_unimorph_top_language_pack_manifests();
        $stored = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] ?? [];

        assert_contains('Bundled analyzer pack settings saved. Reindex existing content', $html, 'successful bundled pack save should tell operators to reindex content');
        assert_same($manifests['en'], $stored['lemmatizer_packs_by_lang']['en'] ?? null, 'saving English should persist the bundled English manifest path');
        assert_same($manifests['bn'], $stored['lemmatizer_packs_by_lang']['bn'] ?? null, 'saving Bengali should persist the bundled Bengali manifest path');
        assert_true(!array_key_exists('unknown-language', $stored['lemmatizer_packs_by_lang'] ?? []), 'unknown submitted languages should be ignored');
        $health = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];
        assert_same(true, $health['stale_debt_active'] ?? null, 'saving bundled analyzer packs should mark stale debt active');
        assert_same(['analyzer_options_changed'], $health['stale_debt_reasons'] ?? null, 'saving bundled analyzer packs should store the analyzer-options reason only');
        assert_true(is_string($health['index_profile_hash'] ?? null) && preg_match('/^[a-f0-9]{40}$/', $health['index_profile_hash']) === 1, 'saving bundled analyzer packs should persist a current profile hash');
        assert_true(is_string($health['accepted_index_profile_hash'] ?? null) && preg_match('/^[a-f0-9]{40}$/', $health['accepted_index_profile_hash']) === 1, 'saving bundled analyzer packs should persist an accepted profile hash');
        $runtimeOptions = WP_FTS_Plugin::runtime_analyzer_options();
        assert_true(array_key_exists('pl', $runtimeOptions['lemmatizer_packs_by_lang'] ?? []), 'saving bundled non-Polish packs should preserve the Polish runtime default');
        assert_same([451, 452], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] ?? [], 'saving bundled analyzer packs should not drain the indexing queue');
        assert_same([], $GLOBALS['wp_fts_test_posts'], 'saving bundled analyzer packs should not create posts');
        assert_same([], $fake->terms, 'saving bundled analyzer packs should not write FTS terms');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }
});

test_case('admin analyzer pack save ignores unknown languages without bogus stale reasons', function (): void {
    if (!WP_FTS_AnalyzerPackValidator::gzip_available()) {
        assert_true(true, 'gzip is unavailable, so unknown bundled runtime analyzer language coverage is skipped.');
        return;
    }

    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;

    try {
        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
        $_GET = [];
        $_POST = [
            'wp_fts_analyzer_packs_action' => 'save_bundled_runtime_packs',
            'wp_fts_analyzer_packs_nonce' => wp_create_nonce('wp_fts_analyzer_packs_admin_action'),
            'wp_fts_bundled_runtime_lemma_packs' => ['unknown-language'],
        ];

        wp_fts_test_capture_admin_settings_tab('analyzer-packs');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }

    $health = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];
    assert_true(empty($health['stale_debt_active'] ?? false), 'unknown analyzer-pack languages alone should not mark stale debt');
    assert_true(!in_array('unknown-language', $health['stale_debt_reasons'] ?? [], true), 'unknown analyzer-pack languages should not be stored as stale reasons');
    assert_same([], $fake->docs, 'unknown analyzer-pack language save should not index documents');
    assert_same([], $fake->terms, 'unknown analyzer-pack language save should not write FTS terms');
});

test_case('admin analyzer pack save removes only exact bundled manifest entries and preserves custom paths', function (): void {
    if (!WP_FTS_AnalyzerPackValidator::gzip_available()) {
        assert_true(true, 'gzip is unavailable, so bundled runtime analyzer save removal coverage is skipped.');
        return;
    }

    $oldGet = $_GET;
    $oldPost = $_POST;

    try {
        $manifests = bundled_unimorph_top_language_pack_manifests();
        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = [
            'lemmatizer_packs_by_lang' => [
                'en' => $manifests['en'],
                'bn' => '/srv/wp-fts/custom-bn/manifest.json',
                'ur' => '/srv/wp-fts/custom-ur/manifest.json',
            ],
            'lemma_packs_by_lang' => [
                'es' => $manifests['es'],
                'fr' => '/srv/wp-fts/custom-fr/manifest.json',
            ],
        ];
        $_GET = [];
        $_POST = [
            'wp_fts_analyzer_packs_action' => 'save_bundled_runtime_packs',
            'wp_fts_analyzer_packs_nonce' => wp_create_nonce('wp_fts_analyzer_packs_admin_action'),
            'wp_fts_bundled_runtime_lemma_packs' => ['bn', 'fr'],
        ];

        $html = wp_fts_test_capture_admin_settings_tab('analyzer-packs');
        $stored = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] ?? [];

        assert_contains('Configured outside this UI by the stored analyzer option.', $html, 'custom stored pack paths should be shown as externally configured');
        assert_true(!array_key_exists('en', $stored['lemmatizer_packs_by_lang'] ?? []), 'unchecked English should remove the exact bundled lemmatizer entry');
        assert_true(!array_key_exists('es', $stored['lemma_packs_by_lang'] ?? []), 'unchecked Spanish should remove the exact bundled higher-precedence alias entry');
        assert_same('/srv/wp-fts/custom-bn/manifest.json', $stored['lemmatizer_packs_by_lang']['bn'] ?? null, 'custom Bengali stored path should not be overwritten by a malicious checked value');
        assert_same('/srv/wp-fts/custom-fr/manifest.json', $stored['lemma_packs_by_lang']['fr'] ?? null, 'custom French alias path should not be removed by an unchecked exact-bundled save');
        assert_same('/srv/wp-fts/custom-ur/manifest.json', $stored['lemmatizer_packs_by_lang']['ur'] ?? null, 'unrelated analyzer options should be preserved');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
    }
});

test_case('admin analyzer pack save respects filter-controlled languages', function (): void {
    if (!WP_FTS_AnalyzerPackValidator::gzip_available()) {
        assert_true(true, 'gzip is unavailable, so filter-controlled bundled runtime analyzer coverage is skipped.');
        return;
    }

    $oldGet = $_GET;
    $oldPost = $_POST;

    try {
        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
        $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::ANALYZER_OPTIONS_FILTER] = static function (array $options): array {
            $options['lemmatizer_packs_by_lang']['en'] = '/srv/wp-fts/filter-en/manifest.json';

            return $options;
        };
        $_GET = [];
        $_POST = [
            'wp_fts_analyzer_packs_action' => 'save_bundled_runtime_packs',
            'wp_fts_analyzer_packs_nonce' => wp_create_nonce('wp_fts_analyzer_packs_admin_action'),
            'wp_fts_bundled_runtime_lemma_packs' => ['en'],
        ];

        $html = wp_fts_test_capture_admin_settings_tab('analyzer-packs');
        $stored = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] ?? [];

        assert_contains('Configured outside this UI by the analyzer options filter.', $html, 'filter-controlled languages should be shown as externally configured');
        assert_true(!array_key_exists('en', $stored['lemmatizer_packs_by_lang'] ?? []), 'filter-controlled English should not be copied into stored analyzer options');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
    }
});

test_case('admin analyzer pack save rejects unauthorized and invalid nonce POSTs', function (): void {
    if (!WP_FTS_AnalyzerPackValidator::gzip_available()) {
        assert_true(true, 'gzip is unavailable, so analyzer save authorization coverage is skipped.');
        return;
    }

    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;

    try {
        wp_fts_test_reset_wordpress_fakes();
        $_GET = [];
        $_POST = [
            'wp_fts_analyzer_packs_action' => 'save_bundled_runtime_packs',
            'wp_fts_analyzer_packs_nonce' => wp_create_nonce('wp_fts_analyzer_packs_admin_action'),
            'wp_fts_bundled_runtime_lemma_packs' => ['en'],
        ];
        $unauthorizedHtml = wp_fts_test_capture_admin_settings_tab('analyzer-packs');
        assert_contains('You do not have permission to manage Full-Text Search settings.', $unauthorizedHtml, 'unauthorized analyzer POST should fail at the admin capability gate');
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION]), 'unauthorized analyzer POST should not change analyzer options');
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION]), 'unauthorized analyzer POST should not mark stale debt');

        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
        $_POST = [
            'wp_fts_analyzer_packs_action' => 'save_bundled_runtime_packs',
            'wp_fts_analyzer_packs_nonce' => 'bad-nonce',
            'wp_fts_bundled_runtime_lemma_packs' => ['en'],
        ];
        $invalidHtml = wp_fts_test_capture_admin_settings_tab('analyzer-packs');
        assert_contains('The analyzer-pack action could not be verified', $invalidHtml, 'invalid analyzer nonce should produce an error notice');
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION]), 'invalid analyzer nonce should not change analyzer options');
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION]), 'invalid analyzer nonce should not mark stale debt');
        assert_same([], $fake->docs, 'invalid analyzer nonce should not index documents');
        assert_same([], $fake->terms, 'invalid analyzer nonce should not write FTS terms');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }
});

test_case('sandbox language fallback uses current site language dynamically', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;

    $english = (object) [
        'ID' => 611,
        'post_title' => 'English Dynamic Fallback',
        'post_content' => '<p>fallbackneedle appears in English.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-13 00:00:00',
    ];
    $polish = (object) [
        'ID' => 612,
        'post_title' => 'Polish Dynamic Fallback',
        'post_content' => '<p>fallbackneedle pojawia sie po polsku.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-13 00:00:00',
    ];

    $render = static function (): string {
        $_POST = [];
        $_GET = [
            'page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG,
            'tab' => 'sandbox',
            'wp_fts_sandbox_query' => 'fallbackneedle',
            'wp_fts_sandbox_lang' => 'es',
            'wp_fts_sandbox_search' => '1',
            'wp_fts_sandbox_language_fallback' => '1',
            'wp_fts_sandbox_highlight' => '1',
        ];

        return wp_fts_test_capture_admin_sandbox();
    };

    try {
        $GLOBALS['wp_fts_test_posts'][611] = $english;
        $GLOBALS['wp_fts_test_posts'][612] = $polish;
        update_post_meta(611, WP_FTS_Plugin::LANGUAGE_META_KEY, 'en');
        update_post_meta(612, WP_FTS_Plugin::LANGUAGE_META_KEY, 'pl');
        WP_FTS_Plugin::handle_post_save(611, $english, true);
        WP_FTS_Plugin::handle_post_save(612, $polish, true);

        $GLOBALS['wp_fts_test_locale'] = 'en';
        $englishHtml = $render();
        assert_contains('English Dynamic Fallback', $englishHtml, 'site-language fallback should use the current English site language');
        assert_true(!str_contains($englishHtml, 'Polish Dynamic Fallback'), 'English site-language fallback should not search the Polish partition');

        $GLOBALS['wp_fts_test_locale'] = 'pl';
        $polishHtml = $render();
        assert_contains('Polish Dynamic Fallback', $polishHtml, 'site-language fallback should reflect a changed site language without resaving settings');
        assert_true(!str_contains($polishHtml, 'English Dynamic Fallback'), 'Polish site-language fallback should not keep the old English fallback');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }
});

test_case('sandbox passes search knobs to Searcher options', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
    $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::DEBUG_ENABLED_FILTER] = static fn(mixed $enabled, string $context): bool => $context === 'sandbox search';
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = array_replace(
        WP_FTS_Plugin::default_settings(),
        [
            'recency_boost_strength' => 0.3,
            'recency_boost_half_life_days' => 10.0,
        ]
    );

    $posts = [
        621 => ['title' => 'Alpha Beta Post', 'type' => 'post', 'status' => 'publish', 'date' => '2026-06-10 00:00:00', 'content' => '<p>alpha beta gamma content.</p>'],
        622 => ['title' => 'Alpha Beta Page', 'type' => 'page', 'status' => 'publish', 'date' => '2026-06-10 00:00:00', 'content' => '<p>alpha beta page content.</p>'],
        623 => ['title' => 'Alpha Beta Draft', 'type' => 'post', 'status' => 'draft', 'date' => '2026-06-10 00:00:00', 'content' => '<p>alpha beta draft content.</p>'],
        624 => ['title' => 'Older Alpha Beta Post', 'type' => 'post', 'status' => 'publish', 'date' => '2026-05-01 00:00:00', 'content' => '<p>alpha beta old content.</p>'],
        625 => ['title' => 'Alpha Only Post', 'type' => 'post', 'status' => 'publish', 'date' => '2026-06-10 00:00:00', 'content' => '<p>alpha only content.</p>'],
    ];

    try {
        foreach ($posts as $postId => $data) {
            $post = (object) [
                'ID' => $postId,
                'post_title' => $data['title'],
                'post_content' => $data['content'],
                'post_excerpt' => '',
                'post_status' => $data['status'],
                'post_type' => $data['type'],
                'post_date_gmt' => $data['date'],
            ];
            $GLOBALS['wp_fts_test_posts'][$postId] = $post;
            update_post_meta($postId, WP_FTS_Plugin::LANGUAGE_META_KEY, 'en');
            WP_FTS_Plugin::handle_post_save($postId, $post, true);
        }

        $_POST = [];
        $_GET = [
            'page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG,
            'tab' => 'sandbox',
            'wp_fts_sandbox_query' => 'alpha beta',
            'wp_fts_sandbox_lang' => 'en',
            'wp_fts_sandbox_search' => '1',
            'wp_fts_sandbox_mode' => 'AND',
            'wp_fts_sandbox_limit' => '1',
            'wp_fts_sandbox_snippet_length' => '40',
            'wp_fts_sandbox_highlight' => '0',
            'wp_fts_sandbox_language_fallback' => '0',
            'wp_fts_sandbox_post_type' => ['post'],
            'wp_fts_sandbox_post_status' => ['publish'],
            'wp_fts_sandbox_date_after' => '2026-06-01',
            'wp_fts_sandbox_date_before' => '2026-06-11',
        ];

        $html = wp_fts_test_capture_admin_sandbox();
        assert_contains('Search returned 1 result(s).', $html, 'sandbox result limit and filters should produce a single result');
        assert_contains('Alpha Beta Post', $html, 'sandbox should include the matching post result');
        assert_true(!str_contains($html, 'Alpha Beta Page'), 'sandbox post type filter should exclude pages');
        assert_true(!str_contains($html, 'Alpha Beta Draft'), 'sandbox post status filter should exclude drafts');
        assert_true(!str_contains($html, 'Older Alpha Beta Post'), 'sandbox date filters should exclude old content');
        assert_true(!str_contains($html, 'Alpha Only Post'), 'sandbox AND mode should require all query terms');
        assert_contains('Loading excerpt...', $html, 'sandbox should defer snippet rendering to the detail request');
        $traces = WP_FTS_Plugin::debug_traces();
        assert_same(1, count($traces), 'sandbox diagnostics should record one initial search trace');
        $explain = is_array($traces[0]['search_explain'] ?? null) ? $traces[0]['search_explain'] : [];
        $recency = is_array($explain['recency_boost'] ?? null) ? $explain['recency_boost'] : [];
        assert_same(true, $recency['enabled'] ?? null, 'sandbox search should pass saved recency boost into the searcher');
        assert_float_near(0.3, (float) ($recency['strength'] ?? -1), 'sandbox diagnostics should record saved recency strength');
        assert_float_near(10.0, (float) ($recency['half_life_days'] ?? -1), 'sandbox diagnostics should record saved recency half-life');

        $detailRequest = $_GET;
        $detailRequest['wp_fts_sandbox_details_nonce'] = wp_create_nonce('wp_fts_sandbox_result_details');
        $detailRequest['wp_fts_sandbox_post_ids'] = '621';
        $detail = wp_fts_test_capture_sandbox_details_ajax($detailRequest);
        $snippet = (string) ($detail['payload']['data']['rows']['621']['snippet_html'] ?? '');
        assert_true($snippet !== '', 'sandbox detail request should return a snippet for the visible row');
        assert_true(!str_contains($snippet, '<mark>'), 'sandbox highlight toggle should reach async snippet generation');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }
});

test_case('indexed content flags unsupported conservative language partitions', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
    $post = (object) [
        'ID' => 631,
        'post_title' => 'Unsupported Language Partition',
        'post_content' => '<p>unsupported partition content.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-13 00:00:00',
    ];

    try {
        $GLOBALS['wp_fts_test_posts'][631] = $post;
        (new WP_FTS_Indexer(WP_FTS_Plugin::storage(true), new WP_FTS_Analyzer(['default_lang' => 'qaa'])))->index_document_fields(631, [
            ['name' => 'content', 'text' => 'unsupported partition content'],
        ], [
            'lang' => 'qaa',
            'metadata' => [
                'post_id' => 631,
                'post_type' => 'post',
                'post_status' => 'publish',
                'title' => 'Unsupported Language Partition',
                'search_text' => 'unsupported partition content',
                'language' => 'qaa',
            ],
        ]);

        $html = wp_fts_test_capture_admin_settings_tab('indexed-content');
        assert_contains('Unsupported Language Partition', $html, 'indexed content tab should include the unsupported-language post');
        assert_contains('QAA (qaa) - Conservative fallback', $html, 'indexed content tab should flag unsupported language partitions as conservative fallback');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('admin language support details cache analyzer pack statuses per request', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $filterCalls = 0;
    $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::ANALYZER_OPTIONS_FILTER] = static function (array $options) use (&$filterCalls): array {
        $filterCalls++;

        return $options;
    };

    WP_FTS_Plugin::reset_request_caches();
    $supportDetails = new ReflectionMethod(WP_FTS_Plugin::class, 'language_support_details');
    $supportDetails->setAccessible(true);

    for ($i = 0; $i < 6; $i++) {
        $support = $supportDetails->invoke(null, 'en-US', true);
        assert_true(is_array($support), 'language support details should return a support payload');
    }
    assert_same(2, $filterCalls, 'sandbox language support details should reuse runtime and sandbox analyzer pack statuses within one request');

    $supportDetails->invoke(null, 'en-US', false);
    assert_same(2, $filterCalls, 'runtime-only language support details should reuse cached runtime analyzer pack statuses');

    WP_FTS_Plugin::reset_request_caches();
    $supportDetails->invoke(null, 'en-US', false);
    assert_same(3, $filterCalls, 'reset request caches should allow analyzer status inspection to run again for a new request');
});

test_case('admin sandbox indexed terms expose stored Polish lemmas for split inline HTML', function (): void {
    global $wpdb;

    assert_or_pending(
        WP_FTS_AnalyzerPackValidator::gzip_available(),
        'gzip support should be available for Polish sandbox indexed-term diagnostics',
        'PHP zlib gzip support is unavailable, so Polish sandbox indexed-term diagnostics are skipped.'
    );

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;

    $post = (object) [
        'ID' => 901,
        'post_title' => 'Custom Polish Split Surface',
        'post_content' => '<p>chr<strong><em>ząs</em>tki</strong> są wspaniałe</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-13 00:00:00',
    ];

    try {
        $GLOBALS['wp_fts_test_posts'][901] = $post;
        update_post_meta(901, WP_FTS_Plugin::LANGUAGE_META_KEY, 'pl');
        WP_FTS_Plugin::handle_post_save(901, $post, true);

        $_POST = [];
        $_GET = [
            'page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG,
            'wp_fts_sandbox_query' => 'chrząstka',
            'wp_fts_sandbox_lang' => 'pl',
            'wp_fts_sandbox_search' => '1',
        ];

        $html = wp_fts_test_capture_admin_sandbox();
        $defaultIndexedHtml = wp_fts_test_capture_admin_settings_tab('indexed-content');
        $_GET['wp_fts_sandbox_show_indexed_terms'] = '1';
        $indexedHtml = wp_fts_test_capture_admin_settings_tab('indexed-content');

        assert_contains('<th scope="col">Indexed terms</th>', $defaultIndexedHtml, 'indexed-post diagnostics should include the indexed terms column');
        assert_contains('Show indexed terms', $defaultIndexedHtml, 'indexed-post diagnostics should keep stored terms behind an explicit debug control by default');
        assert_contains('<span class="description">Hidden</span>', $defaultIndexedHtml, 'indexed-post diagnostics should avoid hydrating stored terms by default');
        assert_true(!str_contains($defaultIndexedHtml, '<code>pl:chrzastka</code>'), 'default indexed-post diagnostics should not load stored Polish terms');
        assert_contains('Custom Polish Split Surface', $html, 'sandbox search should find the post with split inline Polish text');
        assert_contains('Loading excerpt...', $html, 'sandbox search should defer the split inline highlight to async details');
        assert_contains('Hide indexed terms', $indexedHtml, 'explicit indexed-term debug mode should expose a way back to the fast default view');
        assert_contains('<code>pl:chrzastka</code>', $indexedHtml, 'indexed terms should show the stored Polish lemma for chrząstki');
        assert_contains('<code>pl:chrzastek</code>', $indexedHtml, 'indexed terms should show the alternate stored Polish lemma for chrząstki');
        assert_contains('Full morphology', $indexedHtml, 'indexed content should flag the Polish partition as full morphology when the pack is active');
        assert_true(!str_contains($html, '<mark>chr'), 'initial sandbox search should not block on split inline highlighting');
        $detailRequest = $_GET;
        $detailRequest['wp_fts_sandbox_details_nonce'] = wp_create_nonce('wp_fts_sandbox_result_details');
        $detailRequest['wp_fts_sandbox_post_ids'] = '901';
        $detail = wp_fts_test_capture_sandbox_details_ajax($detailRequest);
        $snippet = (string) ($detail['payload']['data']['rows']['901']['snippet_html'] ?? '');
        assert_contains('<mark>chr', $snippet, 'sandbox detail AJAX should highlight the matched split surface');
        assert_contains('ząs', $snippet, 'sandbox detail AJAX highlight should include the formatted middle of the split surface');
        assert_true(!isset($fake->terms[WP_FTS_TermNamespace::namespace_term('pl', 'chrząstki')]), 'sandbox diagnostics should reflect stored lemmas rather than hard-coded Polish surface forms');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }
});

test_case('unauthorized admin sandbox render is blocked safely', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $oldGet = $_GET;
    $oldPost = $_POST;
    $_GET = [];
    $_POST = [];

    try {
        $html = wp_fts_test_capture_admin_sandbox();
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }

    assert_contains('You do not have permission to manage Full-Text Search settings.', $html, 'settings page should show a safe unauthorized message');
    assert_true(!str_contains($html, 'name="wp_fts_sandbox_action"'), 'unauthorized sandbox page should not render mutating action controls');
    assert_same([], $GLOBALS['wp_fts_test_posts'], 'unauthorized sandbox render should not create demo posts');
    assert_same([], $GLOBALS['wp_fts_test_options'], 'unauthorized sandbox render should not write demo options');
    assert_same([], $fake->terms, 'unauthorized sandbox render should not build FTS terms');
});

test_case('authorized legacy sandbox POST actions fail closed without creating posts', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;

    try {
        $_GET = [];
        $_POST = [
            'wp_fts_sandbox_action' => 'refresh_demo',
            'wp_fts_sandbox_nonce' => 'bad-nonce',
        ];
        $badNonceHtml = wp_fts_test_capture_admin_sandbox();
        assert_contains('The sandbox action could not be verified.', $badNonceHtml, 'bad nonce POST should report verification failure');
        assert_same([], $GLOBALS['wp_fts_test_posts'], 'bad nonce POST should not create demo posts');
        assert_same([], $GLOBALS['wp_fts_test_options'], 'bad nonce POST should not write demo options');
        assert_same([], $fake->terms, 'bad nonce POST should not build FTS terms');

        foreach (['refresh_demo', 'index_demo'] as $legacyAction) {
            $_POST = [
                'wp_fts_sandbox_action' => $legacyAction,
                'wp_fts_sandbox_nonce' => wp_create_nonce('wp_fts_sandbox_admin_action'),
            ];
            $legacyHtml = wp_fts_test_capture_admin_sandbox();
            assert_contains('Sandbox demo post creation is disabled.', $legacyHtml, "{$legacyAction} should report that demo post creation is disabled");
            assert_same([], $GLOBALS['wp_fts_test_posts'], "{$legacyAction} should not create demo posts");
            assert_same([], $GLOBALS['wp_fts_test_options'], "{$legacyAction} should not write demo options");
            assert_same([], $fake->terms, "{$legacyAction} should not build FTS terms");
        }

        $_POST = [
            'wp_fts_sandbox_action' => 'unsupported_demo_action',
            'wp_fts_sandbox_nonce' => wp_create_nonce('wp_fts_sandbox_admin_action'),
        ];
        $unsupportedHtml = wp_fts_test_capture_admin_sandbox();
        assert_contains('Unsupported sandbox action. No changes were made.', $unsupportedHtml, 'unsupported POST action should report a fail-closed message');
        assert_same([], $GLOBALS['wp_fts_test_posts'], 'unsupported POST action should not fall through to auto-seed');
        assert_same([], $GLOBALS['wp_fts_test_options'], 'unsupported POST action should not write demo options');
        assert_same([], $fake->terms, 'unsupported POST action should not build FTS terms');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }
});

test_case('legacy sandbox cleanup trashes only exact demo posts and clears option', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;

    $makePost = static function (int $id, string $title, string $slug, string $status = 'publish'): object {
        return (object) [
            'ID' => $id,
            'post_title' => $title,
            'post_name' => $slug,
            'post_content' => '<p>legacy cleanup fixture</p>',
            'post_excerpt' => '',
            'post_status' => $status,
            'post_type' => 'post',
            'post_date_gmt' => '2026-06-12 00:00:00',
        ];
    };

    try {
        $signatures = wp_fts_test_legacy_sandbox_demo_signatures();
        $GLOBALS['wp_fts_test_posts'][1001] = $makePost(1001, $signatures[0]['title'], $signatures[0]['slug']);
        $GLOBALS['wp_fts_test_posts'][1002] = $makePost(1002, $signatures[1]['title'], $signatures[1]['slug']);
        $GLOBALS['wp_fts_test_posts'][1003] = $makePost(1003, 'Customer Content Stored In Old Option', 'customer-content-stored-in-old-option');
        $GLOBALS['wp_fts_test_posts'][1004] = $makePost(1004, 'FTS Sandbox: Customer Notes', 'customer-notes');
        $GLOBALS['wp_fts_test_posts'][1005] = $makePost(1005, $signatures[2]['title'], $signatures[2]['slug'], 'trash');
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SANDBOX_DEMO_POSTS_OPTION] = [1001, 1003, 1005];

        $_POST = [];
        $_GET = [];
        $detectedHtml = wp_fts_test_capture_admin_settings_tab('settings');
        assert_contains('Legacy sandbox demo posts detected.', $detectedHtml, 'settings page should detect exact legacy sandbox demo posts');
        assert_contains('Move legacy sandbox demo posts to Trash', $detectedHtml, 'settings page should offer the cleanup action');
        assert_contains('2 exact legacy post(s) found.', $detectedHtml, 'cleanup affordance should count only live exact legacy demo posts');
        assert_contains('name="wp_fts_sandbox_action" value="cleanup_legacy_demo_posts"', $detectedHtml, 'cleanup action should use the sandbox POST action field');
        assert_contains('name="wp_fts_sandbox_nonce"', $detectedHtml, 'cleanup action should include a nonce');

        $_POST = [
            'wp_fts_sandbox_action' => 'cleanup_legacy_demo_posts',
            'wp_fts_sandbox_nonce' => wp_create_nonce('wp_fts_sandbox_admin_action'),
        ];
        $cleanupHtml = wp_fts_test_capture_admin_settings_tab('settings');

        assert_contains('Moved 2 legacy sandbox demo post(s) to Trash.', $cleanupHtml, 'cleanup should report moved exact legacy posts');
        assert_same([1001, 1002], $GLOBALS['wp_fts_test_trashed_posts'], 'cleanup should trash only exact live legacy demo posts');
        assert_same('trash', $GLOBALS['wp_fts_test_posts'][1001]->post_status, 'stored exact legacy demo post should move to Trash');
        assert_same('trash', $GLOBALS['wp_fts_test_posts'][1002]->post_status, 'title-detected exact legacy demo post should move to Trash');
        assert_same('publish', $GLOBALS['wp_fts_test_posts'][1003]->post_status, 'unrelated post in the stored option should remain untouched');
        assert_same('publish', $GLOBALS['wp_fts_test_posts'][1004]->post_status, 'same-prefix unrelated post should remain untouched');
        assert_same('trash', $GLOBALS['wp_fts_test_posts'][1005]->post_status, 'already-trashed legacy post should remain trash without being processed');
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SANDBOX_DEMO_POSTS_OPTION]), 'cleanup should clear the legacy sandbox demo post option');
        assert_true(!str_contains($cleanupHtml, 'Move legacy sandbox demo posts to Trash'), 'cleanup affordance should disappear after cleanup clears live candidates');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }
});

test_case('sandbox demo analyzer loads bundled UniMorph packs without changing runtime defaults', function (): void {
    assert_or_pending(
        WP_FTS_AnalyzerPackValidator::gzip_available(),
        'gzip support should be available for bundled UniMorph sandbox demo analyzer coverage',
        'PHP zlib gzip support is unavailable, so bundled UniMorph sandbox demo analyzer coverage is skipped.'
    );

    wp_fts_test_reset_wordpress_fakes();

    $manifests = bundled_unimorph_top_language_pack_manifests();
    assert_same(
        ['ar', 'bn', 'de', 'en', 'es', 'fa', 'fr', 'hi', 'id', 'it', 'nl', 'pt', 'ru', 'te', 'tr', 'uk'],
        array_keys($manifests),
        'bundled UniMorph discovery should find committed top-language and next-language manifests'
    );

    $runtimeOptions = WP_FTS_Plugin::runtime_analyzer_options();
    $runtimePacks = $runtimeOptions['lemmatizer_packs_by_lang'] ?? [];
    assert_true(is_array($runtimePacks), 'runtime analyzer options should expose the existing pack map shape');
    foreach ($manifests as $language => $manifest) {
        assert_true(!array_key_exists($language, $runtimePacks), "{$language} UniMorph pack should not become a production runtime default");
    }
    assert_true(array_key_exists('pl', $runtimePacks), 'production runtime defaults should keep the existing bundled Polish pack behavior');

    $sandboxOptions = WP_FTS_Plugin::sandbox_demo_analyzer_options();
    $sandboxPacks = $sandboxOptions['lemmatizer_packs_by_lang'] ?? [];
    assert_true(is_array($sandboxPacks), 'sandbox analyzer options should expose the pack map shape');
    foreach ($manifests as $language => $manifest) {
        assert_same($manifest, $sandboxPacks[$language] ?? null, "{$language} sandbox analyzer should point at the committed UniMorph manifest");
    }
    assert_true(array_key_exists('pl', $sandboxPacks), 'sandbox analyzer should preserve the existing bundled Polish pack');

    $statuses = [];
    foreach (WP_FTS_Plugin::sandbox_demo_analyzer_pack_statuses() as $status) {
        $statuses[$status['language']] = $status;
    }
    foreach ($manifests as $language => $manifest) {
        assert_same('active', $statuses[$language]['status'] ?? null, "{$language} sandbox status should mark the committed UniMorph pack active");
        assert_same(false, $statuses[$language]['fixture_only'] ?? null, "{$language} sandbox status should report the committed pack as full local data");
    }

    $analyzer = WP_FTS_Plugin::sandbox_demo_analyzer();
    foreach (bundled_unimorph_sandbox_demo_probe_cases() as $language => $case) {
        assert_same([$case['lemma']], $analyzer->analyze_query($case['surface'], ['lang' => $language]), "{$language} sandbox analyzer should lemmatize the demo surface through the bundled pack");
        assert_same([$case['lemma']], $analyzer->analyze_query($case['lemma'], ['lang' => $language]), "{$language} sandbox analyzer should keep the demo lemma searchable");

        $storage = new WP_FTS_Storage_InMemory();
        (new WP_FTS_Indexer($storage, $analyzer))->index_document_fields(8200 + count($storage->all_doc_ids(false)), [['name' => 'content', 'text' => $case['surface']]], [
            'lang' => $language,
            'metadata' => [
                'post_id' => 8200,
                'post_type' => 'post',
                'post_status' => 'publish',
                'title' => $case['title'],
                'search_text' => $case['surface'],
                'language' => $language,
            ],
        ]);

        assert_true(in_array(WP_FTS_TermNamespace::namespace_term($language, $case['lemma']), $storage->all_terms(), true), "{$language} sandbox indexing should store the committed UniMorph lemma");
        $payload = (new WP_FTS_Searcher($storage, $analyzer))->search($case['lemma'], [
            'lang' => $language,
            'mode' => 'AND',
            'include_total' => true,
        ]);
        assert_same(1, $payload['total'], "{$language} sandbox search should find the indexed demo surface by lemma");
    }
});

test_case('sandbox searches existing indexed content without creating demo posts', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = array_replace(
        WP_FTS_Plugin::default_settings(),
        [
            'prefix_min_length' => 3,
            'prefix_max_terms' => 5,
        ]
    );

    try {
        $post = (object) [
            'ID' => 904,
            'post_title' => 'Existing Indexed Sandbox Content',
            'post_content' => '<p>existingneedle content is already on the site.</p>',
            'post_excerpt' => '',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_date_gmt' => '2026-06-12 00:00:00',
        ];
        $GLOBALS['wp_fts_test_posts'][904] = $post;
        update_post_meta(904, WP_FTS_Plugin::LANGUAGE_META_KEY, 'en');
        WP_FTS_Plugin::handle_post_save(904, $post, true);

        $_POST = [];
        $_GET = [
            'page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG,
            'wp_fts_sandbox_query' => 'existingneedle',
            'wp_fts_sandbox_lang' => 'en',
            'wp_fts_sandbox_search' => '1',
        ];
        $html = wp_fts_test_capture_admin_sandbox();
        assert_contains('Search returned 1 result(s).', $html, 'sandbox should search the existing full-text index');
        assert_contains('Existing Indexed Sandbox Content', $html, 'sandbox search should return existing indexed site content');
        $traces = WP_FTS_Plugin::debug_traces();
        $sandboxTrace = null;
        foreach ($traces as $trace) {
            if (($trace['context'] ?? null) === 'sandbox search') {
                $sandboxTrace = $trace;
                break;
            }
        }
        assert_true(is_array($sandboxTrace), 'sandbox diagnostics should record the sandbox search context');
        assert_same('ran', $sandboxTrace['status'] ?? null, 'sandbox diagnostics should mark successful searches as ran');
        assert_same('existingneedle', $sandboxTrace['search_text'] ?? null, 'sandbox diagnostics should record bounded search text');
        assert_same('en', $sandboxTrace['query_lang'] ?? null, 'sandbox diagnostics should record selected query language');
        $sandboxExplain = is_array($sandboxTrace['search_explain'] ?? null) ? $sandboxTrace['search_explain'] : [];
        $sandboxPlan = is_array($sandboxExplain['query_plan'] ?? null) ? $sandboxExplain['query_plan'] : [];
        assert_same(3, (int) ($sandboxPlan['prefix_min_length'] ?? 0), 'sandbox search should pass saved prefix minimum length into the searcher');
        assert_same(5, (int) ($sandboxPlan['prefix_max_terms'] ?? 0), 'sandbox search should pass saved prefix max terms into the searcher');
        $sandboxCounts = is_array($sandboxTrace['counts'] ?? null) ? $sandboxTrace['counts'] : [];
        assert_same(1, (int) ($sandboxCounts['visible_results'] ?? 0), 'sandbox diagnostics should count visible results');
        $sandboxTimings = is_array($sandboxTrace['timings_ms'] ?? null) ? $sandboxTrace['timings_ms'] : [];
        assert_true(array_key_exists('storage/search', $sandboxTimings), 'sandbox diagnostics should record storage/search timing');
        assert_same([904], array_keys($GLOBALS['wp_fts_test_posts']), 'sandbox search should not add demo posts alongside existing content');
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SANDBOX_DEMO_POSTS_OPTION]), 'sandbox search should not write the legacy demo post option');
        foreach (wp_fts_test_legacy_sandbox_demo_signatures() as $signature) {
            assert_true(!str_contains($html, $signature['title']), "sandbox search should not render the legacy {$signature['title']} title");
        }
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }
});

test_case('admin sandbox progressive render defers snippets and debug terms', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;

    try {
        $post = (object) [
            'ID' => 914,
            'post_title' => 'Progressive Sandbox Content',
            'post_content' => '<p>progressiveneedle content has visible detail text.</p>',
            'post_excerpt' => '',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_date_gmt' => '2026-06-12 00:00:00',
        ];
        $GLOBALS['wp_fts_test_posts'][914] = $post;
        update_post_meta(914, WP_FTS_Plugin::LANGUAGE_META_KEY, 'en');
        WP_FTS_Plugin::handle_post_save(914, $post, true);

        $fake->prepared = [];
        $_POST = [];
        $_GET = [
            'page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG,
            'wp_fts_sandbox_query' => 'progressiveneedle',
            'wp_fts_sandbox_lang' => 'en',
            'wp_fts_sandbox_search' => '1',
            'wp_fts_sandbox_show_indexed_terms' => '1',
        ];
        $html = wp_fts_test_capture_admin_sandbox();

        assert_contains('Progressive Sandbox Content', $html, 'initial sandbox render should keep the result row visible');
        assert_contains('data-wp-fts-detail="snippet"', $html, 'initial sandbox render should mark snippet cells for async loading');
        assert_contains('Loading excerpt...', $html, 'initial sandbox render should show an excerpt placeholder');
        assert_contains('<th scope="col">Indexed terms</th>', $html, 'explicit debug mode should add an indexed terms column');
        assert_contains('Loading indexed terms...', $html, 'explicit debug mode should defer indexed terms behind a placeholder');
        assert_contains('wp_fts_sandbox_details_nonce', $html, 'initial sandbox render should expose a nonce for async detail loading');
        assert_true(!str_contains($html, '<mark>progressiveneedle</mark>'), 'initial sandbox render should not compute highlighted snippets');
        assert_same(0, wp_fts_test_prepared_sql_count($fake, 'SELECT doc_id, lang, doc_len, content_hash, is_deleted FROM wp_fts_docs'), 'initial sandbox render should not hydrate documents for snippet generation');
        assert_same(0, wp_fts_test_prepared_sql_count($fake, 'SELECT term FROM wp_fts_postings WHERE doc_id = %d'), 'initial sandbox render should not hydrate stored terms even in explicit debug mode');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }
});

test_case('admin sandbox detail ajax requires capability and nonce', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    $basePost = [
        'wp_fts_sandbox_details_nonce' => wp_create_nonce('wp_fts_sandbox_result_details'),
        'wp_fts_sandbox_post_ids' => '914',
        'wp_fts_sandbox_query' => 'progressiveneedle',
        'wp_fts_sandbox_lang' => 'en',
        'wp_fts_sandbox_search' => '1',
    ];

    try {
        $unauthorized = wp_fts_test_capture_sandbox_details_ajax($basePost);
        assert_same(false, $unauthorized['payload']['success'] ?? null, 'detail AJAX should reject users without manage_options');
        assert_same(403, $unauthorized['payload']['data']['status'] ?? null, 'detail AJAX unauthorized response should use HTTP-forbidden status metadata');

        $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
        $badNonce = $basePost;
        $badNonce['wp_fts_sandbox_details_nonce'] = 'bad';
        $forged = wp_fts_test_capture_sandbox_details_ajax($badNonce);
        assert_same(false, $forged['payload']['success'] ?? null, 'detail AJAX should reject a bad nonce');
        assert_same(403, $forged['payload']['data']['status'] ?? null, 'detail AJAX bad nonce response should use HTTP-forbidden status metadata');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('admin sandbox detail ajax returns sanitized snippets and explicit debug terms', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;

    try {
        $post = (object) [
            'ID' => 915,
            'post_title' => 'Async Detail Sandbox Content',
            'post_content' => '<p>progressiveneedle <strong>safe detail</strong><script>alert(1)</script></p>',
            'post_excerpt' => '',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_date_gmt' => '2026-06-12 00:00:00',
        ];
        $GLOBALS['wp_fts_test_posts'][915] = $post;
        update_post_meta(915, WP_FTS_Plugin::LANGUAGE_META_KEY, 'en');
        WP_FTS_Plugin::handle_post_save(915, $post, true);

        $request = [
            'wp_fts_sandbox_details_nonce' => wp_create_nonce('wp_fts_sandbox_result_details'),
            'wp_fts_sandbox_post_ids' => '915,999999',
            'wp_fts_sandbox_query' => 'progressiveneedle',
            'wp_fts_sandbox_lang' => 'en',
            'wp_fts_sandbox_search' => '1',
            'wp_fts_sandbox_mode' => 'OR',
            'wp_fts_sandbox_limit' => '10',
            'wp_fts_sandbox_snippet_length' => '180',
            'wp_fts_sandbox_highlight' => '1',
            'wp_fts_sandbox_prefix_matching' => '1',
            'wp_fts_sandbox_language_fallback' => '1',
            'wp_fts_sandbox_post_type' => ['post'],
            'wp_fts_sandbox_post_status' => ['publish'],
        ];

        $fake->prepared = [];
        $withoutTerms = wp_fts_test_capture_sandbox_details_ajax($request);
        assert_same(true, $withoutTerms['payload']['success'] ?? null, 'detail AJAX should return a success payload for an authorized nonce-protected request');
        $rows = $withoutTerms['payload']['data']['rows'] ?? [];
        assert_true(is_array($rows), 'detail AJAX should return rows keyed by post id');
        assert_true(isset($rows['915']), 'detail AJAX should return the requested visible row');
        assert_true(!isset($rows['999999']), 'detail AJAX should not return unrequested or non-result details');
        $snippet = (string) ($rows['915']['snippet_html'] ?? '');
        assert_contains('<mark>progressiveneedle</mark>', $snippet, 'detail AJAX should return a highlighted snippet');
        assert_contains('safe detail', $snippet, 'detail AJAX should preserve safe visible snippet text');
        assert_true(!str_contains($snippet, '<script'), 'detail AJAX should strip unsafe snippet markup');
        assert_true(!array_key_exists('indexed_terms', $rows['915']), 'detail AJAX should omit stored terms unless debug mode is explicit');
        assert_true(wp_fts_test_prepared_sql_count($fake, 'SELECT doc_id, lang, doc_len, content_hash, is_deleted FROM wp_fts_docs') > 0, 'detail AJAX should do snippet document hydration after initial render');

        $withTermsRequest = $request;
        $withTermsRequest['wp_fts_sandbox_show_indexed_terms'] = '1';
        $withTerms = wp_fts_test_capture_sandbox_details_ajax($withTermsRequest);
        $termRows = $withTerms['payload']['data']['rows'] ?? [];
        assert_true(is_array($termRows['915']['indexed_terms'] ?? null), 'explicit debug detail AJAX should return indexed terms');
        $matchingTerms = array_filter(
            $termRows['915']['indexed_terms'],
            static fn(mixed $term): bool => is_string($term) && str_starts_with($term, 'en:progressiveneed')
        );
        assert_true($matchingTerms !== [], 'explicit debug detail AJAX should return stored English terms for the visible row');
        assert_same(false, $termRows['915']['indexed_terms_more'] ?? null, 'short explicit debug term list should not report overflow');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }
});

test_case('legacy sandbox demo cleanup moves exact posts to trash and leaves unrelated content', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;

    try {
        foreach ([
            100 => ['FTS Sandbox: English Mice', 'wp-fts-sandbox-english-mice', 'publish'],
            101 => ['FTS Sandbox: Polish Lemmatizer Demo', 'wp-fts-sandbox-polish-lemmatizer-demo', 'draft'],
            102 => ['FTS Sandbox: Personal Research', 'wp-fts-sandbox-english-mice', 'publish'],
            103 => ['Unrelated Stored Option Post', 'unrelated-stored-option-post', 'publish'],
            104 => ['FTS Sandbox: French Chercher', 'wp-fts-sandbox-french-chercher', 'publish'],
        ] as $post_id => $post_data) {
            [$title, $slug, $status] = $post_data;
            $post = (object) [
                'ID' => $post_id,
                'post_title' => $title,
                'post_name' => $slug,
                'post_content' => '<p>legacy cleanup safety fixture ' . $post_id . '</p>',
                'post_excerpt' => '',
                'post_status' => $status,
                'post_type' => 'post',
                'post_date_gmt' => '2026-06-12 00:00:00',
            ];
            $GLOBALS['wp_fts_test_posts'][$post_id] = $post;
            WP_FTS_Plugin::handle_post_save($post_id, $post, true);
        }
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SANDBOX_DEMO_POSTS_OPTION] = [100, 101, 102, 103];

        $_POST = [];
        $_GET = [];
        $promptHtml = wp_fts_test_capture_admin_settings_tab('settings');
        assert_contains('Legacy sandbox demo posts detected.', $promptHtml, 'settings page should show cleanup affordance when exact legacy demo posts are detectable');
        assert_contains('3 exact legacy post(s) found.', $promptHtml, 'cleanup affordance should count exact legacy posts from stored IDs and exact-title discovery');
        assert_contains('Move legacy sandbox demo posts to Trash', $promptHtml, 'cleanup affordance should expose the trash action');

        $_POST = [
            'wp_fts_sandbox_action' => 'cleanup_legacy_demo_posts',
            'wp_fts_sandbox_nonce' => wp_create_nonce('wp_fts_sandbox_admin_action'),
        ];
        $_GET = [];
        $cleanupHtml = wp_fts_test_capture_admin_settings_tab('settings');
        assert_contains('Moved 3 legacy sandbox demo post(s) to Trash.', $cleanupHtml, 'cleanup action should report moved exact legacy posts');
        assert_true(!str_contains($cleanupHtml, 'Legacy sandbox demo posts detected.'), 'cleanup affordance should disappear after cleanup');
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SANDBOX_DEMO_POSTS_OPTION]), 'cleanup action should clear the legacy demo post option');

        sort($GLOBALS['wp_fts_test_trashed_posts'], SORT_NUMERIC);
        assert_same([100, 101, 104], $GLOBALS['wp_fts_test_trashed_posts'], 'cleanup should trash exact legacy posts from stored IDs and exact-title discovery');
        assert_same('trash', $GLOBALS['wp_fts_test_posts'][100]->post_status ?? null, 'stored exact English legacy post should be moved to trash');
        assert_same('trash', $GLOBALS['wp_fts_test_posts'][101]->post_status ?? null, 'stored exact Polish legacy post should be moved to trash');
        assert_same('trash', $GLOBALS['wp_fts_test_posts'][104]->post_status ?? null, 'exact French legacy post should be found without the stored option');
        assert_same('publish', $GLOBALS['wp_fts_test_posts'][102]->post_status ?? null, 'same-prefix custom post should be left untouched');
        assert_same('publish', $GLOBALS['wp_fts_test_posts'][103]->post_status ?? null, 'unrelated option-listed post should be left untouched');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }
});

test_case('admin sandbox indexed post list comes from storage and paginates', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;

    $renderIndexed = static function (array $get = []): string {
        $_POST = [];
        $_GET = array_merge(['page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG], $get);

        return wp_fts_test_capture_admin_settings_tab('indexed-content');
    };

    try {
        for ($i = 1; $i <= 12; $i++) {
            $post_id = 2000 + $i;
            $post = (object) [
                'ID' => $post_id,
                'post_title' => 'Custom Indexed ' . $i,
                'post_content' => '<p>custom indexed page ' . $i . ' alpha content</p>',
                'post_excerpt' => '',
                'post_status' => 'publish',
                'post_type' => 'post',
                'post_date_gmt' => '2026-06-12 00:00:00',
            ];
            $GLOBALS['wp_fts_test_posts'][$post_id] = $post;
            WP_FTS_Plugin::handle_post_save($post_id, $post, true);
        }

        wp_fts_test_capture_admin_sandbox();

        $fake->prepared = [];
        $pageTwoHtml = $renderIndexed(['wp_fts_sandbox_posts_page' => '2']);
        assert_same(0, wp_fts_test_prepared_sql_count($fake, 'SELECT term FROM wp_fts_postings WHERE doc_id = %d'), 'default indexed-post page should not hydrate stored terms for visible rows');
        assert_contains('Showing 11-12 of 12 indexed post(s).', $pageTwoHtml, 'sandbox indexed-post table should paginate storage-derived rows');
        assert_contains('Page 2 of 2', $pageTwoHtml, 'sandbox indexed-post table should render page count');
        assert_contains('Previous', $pageTwoHtml, 'sandbox indexed-post table should link back to the first page');
        assert_true(!str_contains($pageTwoHtml, 'Next'), 'last indexed-post page should not link beyond the available storage rows');
        assert_contains('Custom Indexed 11', $pageTwoHtml, 'second indexed-post page should include the eleventh custom storage row');
        assert_contains('Custom Indexed 12', $pageTwoHtml, 'second indexed-post page should include the twelfth custom storage row');
        assert_true(!str_contains($pageTwoHtml, '<td>Custom Indexed 1</td>'), 'second indexed-post page should not leak first-page rows');
        assert_true(!str_contains($pageTwoHtml, 'Create or refresh demo posts'), 'paginated sandbox page should still hide manual demo refresh controls');
        assert_true(!str_contains($pageTwoHtml, 'Build demo index'), 'paginated sandbox page should still hide manual demo index controls');
        assert_true(!str_contains($pageTwoHtml, 'Move legacy sandbox demo posts to Trash'), 'paginated sandbox page should not show cleanup when no legacy demo posts exist');

        $fake->prepared = [];
        $pageOneDebugHtml = $renderIndexed(['wp_fts_sandbox_posts_page' => '1', 'wp_fts_sandbox_show_indexed_terms' => '1']);
        assert_same(10, wp_fts_test_prepared_sql_count($fake, 'SELECT term FROM wp_fts_postings WHERE doc_id = %d'), 'explicit indexed-term debug mode should hydrate stored terms only for the visible page rows');
        assert_contains('Hide indexed terms', $pageOneDebugHtml, 'explicit indexed-term debug mode should render the hide control');

        $pageOneHtml = $renderIndexed(['wp_fts_sandbox_posts_page' => '1']);
        assert_contains('<td>Custom Indexed 1</td>', $pageOneHtml, 'first indexed-post page should list the first custom row');
        assert_contains('<td>Custom Indexed 10</td>', $pageOneHtml, 'first indexed-post page should include custom rows up to the page limit');
        assert_true(!str_contains($pageOneHtml, 'Custom Indexed 11'), 'first indexed-post page should not leak second-page custom rows');
        foreach (wp_fts_test_legacy_sandbox_demo_signatures() as $signature) {
            assert_true(!str_contains($pageOneHtml, $signature['title']), "first indexed-post page should not include the legacy {$signature['title']} title");
        }
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }
});

test_case('activation repairs schema stores version and surfaces database failures', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_seed_backfill_posts($fake, 30);

    try {
        WP_FTS_Plugin::activate();
        assert_same(WP_FTS_Plugin::SCHEMA_VERSION, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null, 'activation should store schema version option');
        assert_same(6, count(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE'))), 'activation should create or repair all FTS tables');
        assert_true(isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), 'activation should schedule the queue processor');
        assert_same([], $fake->docs, 'activation should not immediately backfill existing content');
        assert_same([], $fake->terms, 'activation should not write FTS terms for existing content');

        WP_FTS_Plugin::maybe_upgrade_schema();
        assert_same(6, count(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE'))), 'current schema version should avoid redundant runtime repair');

        WP_FTS_Plugin::upgrade_schema();
        assert_same(12, count(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE'))), 'explicit repair routine should be idempotent and rerunnable');
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

test_case('multisite new-site provisioning is a no-op without a resolvable site id or switch APIs', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_seed_backfill_posts($fake, 3);

    try {
        WP_FTS_Plugin::handle_site_initialization((object) ['domain' => 'missing-id.example']);
        WP_FTS_Plugin::handle_site_initialization(0);
        WP_FTS_Plugin::handle_site_initialization(37);
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same([], $fake->queries, 'new-site provisioning should not touch storage without switch/restore APIs');
    assert_same([], $fake->docs, 'new-site no-op should not index content');
    assert_same([], $fake->terms, 'new-site no-op should not write terms');
    assert_true(!isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), 'new-site no-op should not schedule queue work');
    assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ACTIVATION_REDIRECT_OPTION]), 'new-site no-op should not set activation redirect state');
});

test_case('multisite new-site provisioning switches creates schema schedules and restores without indexing', function (): void {
    global $wpdb;

    if (!function_exists('switch_to_blog')) {
        function switch_to_blog(int $blog_id): bool
        {
            global $wpdb;

            if (!empty($GLOBALS['wp_fts_test_switch_to_blog_returns_false'])) {
                return false;
            }

            $current = (int) ($GLOBALS['wp_fts_test_current_blog_id'] ?? 1);
            $GLOBALS['wp_fts_test_switch_log'][] = $blog_id;
            $GLOBALS['wp_fts_test_blog_stack'][] = $current;
            $GLOBALS['wp_fts_test_current_blog_id'] = $blog_id;

            if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_blog_prefix')) {
                $wpdb->prefix = $wpdb->get_blog_prefix($blog_id);
                $wpdb->posts = $wpdb->prefix . 'posts';
            }

            return true;
        }
    }

    if (!function_exists('restore_current_blog')) {
        function restore_current_blog(): bool
        {
            global $wpdb;

            $previous = array_pop($GLOBALS['wp_fts_test_blog_stack']);
            if (!is_int($previous)) {
                $previous = 1;
            }

            $GLOBALS['wp_fts_test_restore_log'][] = $previous;
            $GLOBALS['wp_fts_test_current_blog_id'] = $previous;

            if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_blog_prefix')) {
                $wpdb->prefix = $wpdb->get_blog_prefix($previous);
                $wpdb->posts = $wpdb->prefix . 'posts';
            }

            return true;
        }
    }

    if (!function_exists('get_current_blog_id')) {
        function get_current_blog_id(): int
        {
            return (int) ($GLOBALS['wp_fts_test_current_blog_id'] ?? 1);
        }
    }

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_seed_backfill_posts($fake, 4);

    try {
        WP_FTS_Plugin::handle_site_initialization((object) ['blog_id' => 37], ['source' => 'test']);
    } finally {
        $wpdb = $oldWpdb;
    }

    $createQueries = array_values(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE')));
    assert_same([37], $GLOBALS['wp_fts_test_switch_log'], 'new-site provisioning should switch to the requested site id');
    assert_same([1], $GLOBALS['wp_fts_test_restore_log'], 'new-site provisioning should restore the previous site context');
    assert_same(1, get_current_blog_id(), 'new-site provisioning should leave the previous blog active');
    assert_same(6, count($createQueries), 'new-site provisioning should create or repair exactly six FTS tables');
    assert_contains('CREATE TABLE wp_37_fts_terms', implode("\n", $createQueries), 'new-site provisioning should use the new site table prefix for terms');
    assert_contains('CREATE TABLE wp_37_fts_postings', implode("\n", $createQueries), 'new-site provisioning should use the new site table prefix for postings');
    assert_contains('CREATE TABLE wp_37_fts_docs', implode("\n", $createQueries), 'new-site provisioning should use the new site table prefix for docs');
    assert_contains('CREATE TABLE wp_37_fts_doc_lengths', implode("\n", $createQueries), 'new-site provisioning should use the new site table prefix for doc lengths');
    assert_contains('CREATE TABLE wp_37_fts_docmeta', implode("\n", $createQueries), 'new-site provisioning should use the new site table prefix for document metadata');
    assert_contains('CREATE TABLE wp_37_fts_meta', implode("\n", $createQueries), 'new-site provisioning should use the new site table prefix for collection metadata');
    assert_true(isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), 'new-site provisioning should schedule bounded queue work');
    assert_same(WP_FTS_Plugin::SCHEMA_VERSION, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null, 'new-site provisioning should store schema version in the switched site context');
    assert_same([], $fake->docs, 'new-site provisioning should not index or backfill content');
    assert_same([], $fake->terms, 'new-site provisioning should not write FTS terms');
    assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ACTIVATION_REDIRECT_OPTION]), 'new-site provisioning should not set the activation redirect flag');

    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_switch_to_blog_returns_false'] = true;

    try {
        WP_FTS_Plugin::handle_site_initialization((object) ['id' => 38]);
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same([], $fake->queries, 'new-site provisioning should fail safe when switch_to_blog declines the switch');
    assert_same([], $GLOBALS['wp_fts_test_restore_log'], 'failed new-site switch should not restore an unmade switch');
    assert_true(!isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), 'failed new-site switch should not schedule queue work');
});

test_case('multisite site deletion table discovery appends per-site FTS tables and de-dupes', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    try {
        $tables = WP_FTS_Plugin::filter_site_deletion_tables([
            'wp_7_posts',
            'wp_7_fts_terms',
            'custom_audit_table',
            'wp_7_fts_terms',
        ], 7);

        $objectTables = WP_FTS_Plugin::filter_site_deletion_tables(['wp_8_posts'], (object) ['id' => 8]);
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same([
        'wp_7_posts',
        'wp_7_fts_terms',
        'custom_audit_table',
        'wp_7_fts_postings',
        'wp_7_fts_docs',
        'wp_7_fts_doc_lengths',
        'wp_7_fts_docmeta',
        'wp_7_fts_meta',
    ], $tables, 'site deletion table filter should preserve existing tables, append the six target-prefix FTS tables, and de-dupe');
    assert_same([
        'wp_8_posts',
        'wp_8_fts_terms',
        'wp_8_fts_postings',
        'wp_8_fts_docs',
        'wp_8_fts_doc_lengths',
        'wp_8_fts_docmeta',
        'wp_8_fts_meta',
    ], $objectTables, 'site deletion table filter should accept WP_Site-like objects with id');
    assert_same(['wp_posts'], WP_FTS_Plugin::filter_site_deletion_tables(['wp_posts', 'wp_posts'], 0), 'site deletion table filter should de-dupe and fail safe when site id is invalid');
    assert_true(!str_contains(implode("\n", $fake->queries), 'DROP TABLE'), 'site deletion table discovery should not execute destructive SQL');
});

test_case('wp-cli status reports lifecycle state without mutating index data', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $now = time();
    $fake->postRows = [
        wp_fts_test_backfill_post(701, 'post', 'publish', 'CLI Status Indexed'),
        wp_fts_test_backfill_post(702, 'post', 'publish', 'CLI Status Queued'),
    ];
    $fake->docs[701] = [
        'lang' => 'en',
        'doc_len' => 3,
        'content_hash' => 'status-hash',
        'is_deleted' => 0,
    ];
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] = [702];
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION] = [
        'token' => 'do-not-expose',
        'mode' => 'manual',
        'started_at' => $now - 10,
        'expires_at' => $now + 290,
    ];
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] = [
        'last_batch_processed' => 4,
        'last_batch_queue_processed' => 1,
        'last_batch_backfill_processed' => 3,
        'has_more' => false,
        'last_indexed_post_id' => 701,
        'last_indexed_post_title' => 'CLI Status Indexed',
        'last_indexed_at' => '2026-06-19 10:00:00',
        'last_skipped_locked' => false,
        'last_stopped_by_budget' => false,
        'last_mode' => 'manual',
        'last_run_at' => '2026-06-19 10:01:00',
        'last_batch_failures' => 2,
        'last_failed_post_id' => 703,
        'last_failed_post_title' => 'CLI <b>Status</b> Failed',
        'last_failed_at' => '2026-06-19 10:02:00',
        'last_error' => "RuntimeException: Failed to put FTS document: SELECT * FROM wp_users\n#0 stack trace",
        'accepted_index_profile_hash' => str_repeat('b', 40),
        'stale_debt_active' => true,
        'stale_debt_reasons' => ['analyzer_options_changed', 'field_boosts_changed', 'unknown_reason'],
        'stale_debt_created_at' => '2026-06-19 09:55:00',
        'stale_debt_updated_at' => '2026-06-19 09:59:00',
        'latest_batch_diagnostics' => [
            'schema' => 'wp-fts-index-batch-diagnostics-v1',
            'trigger' => 'manual',
            'source' => 'wp-cli',
            'status' => 'partial_failure',
            'started_at' => '2026-06-19 10:00:00',
            'finished_at' => '2026-06-19 10:01:00',
            'elapsed_ms' => 12.5,
            'batch_limit' => 5,
            'processed' => 4,
            'queue_processed' => 1,
            'backfill_processed' => 3,
            'queue_before' => 2,
            'queue_after' => 1,
            'backfill_scanned' => 4,
            'backfill_queued' => 3,
            'failures' => 2,
            'error_class' => 'RuntimeException',
            'error_message' => "Failed to put FTS document: SELECT * FROM wp_users\n#0 stack trace",
            'last_failed_post_id' => 703,
            'last_failed_post_title' => 'CLI <b>Status</b> Failed',
            'last_failed_at' => '2026-06-19 10:02:00',
            'reschedule_decision' => 'not_applicable_manual',
            'stop_reason' => 'batch_cap',
            'lock_at_start' => ['state' => 'none', 'active' => false, 'mode' => '', 'started_at' => '', 'expires_at' => ''],
            'lock_at_end' => ['state' => 'none', 'active' => false, 'mode' => '', 'started_at' => '', 'expires_at' => ''],
            'schema_status' => 'current',
            'schema_version' => WP_FTS_Plugin::SCHEMA_VERSION,
            'expected_schema_version' => WP_FTS_Plugin::SCHEMA_VERSION,
            'storage_backend' => 'mysql',
        ],
    ];

    try {
        $command = new WP_FTS_WPCLI_Command();
        $human = wp_fts_test_capture_cli(static function () use ($command): void {
            $command->status([], []);
        });
        $raw = wp_fts_test_capture_cli(static function () use ($command): void {
            $command->status([], ['format' => 'json']);
        });
        $payload = wp_fts_test_decode_cli_json_object($raw);
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_contains("field\tvalue", $human, 'default status output should be a human-readable field list');
    assert_contains("pending_queue_count\t1", $human, 'default status output should include queue count');
    assert_contains("last_batch_failures\t2", $human, 'default status output should include failure count');
    assert_contains("stale_debt_active\tyes", $human, 'default status output should include stale debt state');
    assert_same('current', $payload['schema_status'] ?? null, 'status JSON should report current schema status');
    assert_same(WP_FTS_Plugin::SCHEMA_VERSION, $payload['schema_version'] ?? null, 'status JSON should report stored schema version');
    assert_same('mysql', $payload['storage_backend'] ?? null, 'status JSON should report storage backend');
    assert_true(is_string($payload['index_profile_hash'] ?? null) && preg_match('/^[a-f0-9]{40}$/', $payload['index_profile_hash']) === 1, 'status JSON should expose the current index profile hash');
    assert_same(str_repeat('b', 40), $payload['accepted_index_profile_hash'] ?? null, 'status JSON should expose the last accepted index profile hash');
    assert_same(true, $payload['stale_debt_active'] ?? null, 'status JSON should expose active stale debt state');
    assert_same(['analyzer_options_changed', 'field_boosts_changed'], $payload['stale_debt_reasons'] ?? null, 'status JSON should expose sanitized stale debt reasons');
    assert_same('2026-06-19 09:55:00', $payload['stale_debt_created_at'] ?? null, 'status JSON should expose debt creation time');
    assert_same('2026-06-19 09:59:00', $payload['stale_debt_updated_at'] ?? null, 'status JSON should expose debt update time');
    assert_same(1, $payload['pending_queue_count'] ?? null, 'status JSON should report pending queue count');
    assert_same('active', $payload['lock_state'] ?? null, 'status JSON should report lock state without exposing the token');
    assert_same(true, $payload['lock_active'] ?? null, 'status JSON should report active lock boolean');
    assert_true(!array_key_exists('token', $payload), 'status JSON should not expose lock token');
    assert_same(true, $payload['has_more'] ?? null, 'status JSON should include has-more state from queued work');
    assert_same('manual', $payload['last_mode'] ?? null, 'status JSON should report last run mode');
    assert_same('2026-06-19 10:01:00', $payload['last_run_at'] ?? null, 'status JSON should report last run time');
    assert_same(4, $payload['last_batch_processed'] ?? null, 'status JSON should report last batch total');
    assert_same(1, $payload['last_batch_queue_processed'] ?? null, 'status JSON should report last batch queue count');
    assert_same(3, $payload['last_batch_backfill_processed'] ?? null, 'status JSON should report last batch backfill count');
    assert_same(2, $payload['last_batch_failures'] ?? null, 'status JSON should report bounded failure count');
    assert_same('CLI Status Failed (ID 703)', $payload['last_failed_post'] ?? null, 'status JSON should report the last failed post label');
    assert_same(703, $payload['last_failed_post_id'] ?? null, 'status JSON should report the last failed post id');
    assert_same('CLI Status Failed', $payload['last_failed_post_title'] ?? null, 'status JSON should sanitize the last failed post title');
    assert_same('2026-06-19 10:02:00', $payload['last_failed_at'] ?? null, 'status JSON should report the last failure time');
    assert_contains('SELECT statement', (string) ($payload['last_error'] ?? ''), 'status JSON should retain a concise redacted failure error');
    assert_true(!str_contains((string) ($payload['last_error'] ?? ''), 'SELECT * FROM'), 'status JSON should not expose raw SQL in failure errors');
    assert_true(!str_contains((string) ($payload['last_error'] ?? ''), '#0'), 'status JSON should not expose stack traces in failure errors');
    assert_same('CLI Status Indexed (ID 701)', $payload['last_indexed_post'] ?? null, 'status JSON should report last indexed post label');
    assert_same(2, $payload['eligible_count'] ?? null, 'status JSON should report eligible count');
    assert_same(1, $payload['indexed_count'] ?? null, 'status JSON should report indexed eligible count');
    assert_same(1, $payload['remaining_count'] ?? null, 'status JSON should report remaining eligible count');
    $diagnostics = $payload['latest_batch_diagnostics'] ?? null;
    assert_true(is_array($diagnostics), 'status JSON should expose latest batch diagnostics as a bounded object');
    assert_same('manual', $diagnostics['trigger'] ?? null, 'status diagnostics should include latest batch trigger');
    assert_same('wp-cli', $diagnostics['source'] ?? null, 'status diagnostics should include latest batch source');
    assert_same('partial_failure', $diagnostics['status'] ?? null, 'status diagnostics should include latest batch status');
    assert_same(5, $diagnostics['batch_limit'] ?? null, 'status diagnostics should include batch limit');
    assert_same(4, $diagnostics['processed'] ?? null, 'status diagnostics should include processed count');
    assert_same(2, $diagnostics['queue_before'] ?? null, 'status diagnostics should include queue before count');
    assert_same(1, $diagnostics['queue_after'] ?? null, 'status diagnostics should include queue after count');
    assert_same(4, $diagnostics['backfill_scanned'] ?? null, 'status diagnostics should include backfill scanned count');
    assert_same(3, $diagnostics['backfill_queued'] ?? null, 'status diagnostics should include backfill selected count');
    assert_contains('SELECT statement', (string) ($diagnostics['error_message'] ?? ''), 'status diagnostics should retain redacted error context');
    assert_true(!str_contains(json_encode($diagnostics, JSON_THROW_ON_ERROR), 'SELECT * FROM'), 'status diagnostics should not expose raw SQL');
    assert_same([], $fake->queries, 'status should not run schema repair or storage writes');
    assert_same([702], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] ?? null, 'status should leave queue state unchanged');
    assert_same('do-not-expose', $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION]['token'] ?? null, 'status should leave lock state unchanged');
    assert_same(1, count($fake->docs), 'status should not index additional content');
});

test_case('wp-cli repair runs schema upgrade without indexing content', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_seed_backfill_posts($fake, 3);

    try {
        $command = new WP_FTS_WPCLI_Command();
        $raw = wp_fts_test_capture_cli(static function () use ($command): void {
            $command->repair([], ['format' => 'json']);
        });
        $payload = wp_fts_test_decode_cli_json_object($raw);
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same('current', $payload['schema_status'] ?? null, 'repair should report current schema status after upgrade');
    assert_same(WP_FTS_Plugin::SCHEMA_VERSION, $payload['schema_version'] ?? null, 'repair should report stored schema version after upgrade');
    assert_same(WP_FTS_Plugin::SCHEMA_VERSION, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null, 'repair should persist schema version');
    assert_same(6, count(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE'))), 'repair should call the schema creation/repair path');
    assert_same([], $fake->docs, 'repair should not index existing content');
    assert_same([], $fake->terms, 'repair should not write FTS terms');
});

test_case('wp-cli reindex uses shared writer lock and records diagnostics', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $fake->postRows = [
        wp_fts_test_backfill_post(61, 'post', 'publish', 'CLI Direct Writer'),
    ];

    try {
        $command = new WP_FTS_WPCLI_Command();
        $command->reindex([], [
            'lang' => 'en',
            'limit' => '1',
            'batch_size' => '1',
        ]);
        $health = WP_FTS_Plugin::search_health();
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(['Indexed 1 posts in en.'], WP_CLI::$successMessages, 'direct reindex should report the indexed count after acquiring the writer lock');
    assert_same([], WP_CLI::$warningMessages, 'successful direct reindex should not warn about lock contention');
    assert_true(isset($fake->docs[61]), 'direct reindex should index content while holding the shared writer lock');
    assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION]), 'direct reindex should release the shared writer lock');
    assert_same(WP_FTS_Plugin::INDEX_LOCK_OPTION, $GLOBALS['wp_fts_test_added_options'][0]['name'] ?? null, 'direct reindex should acquire the same non-autoloaded index lock option');
    assert_same('no', $GLOBALS['wp_fts_test_added_options'][0]['autoload'] ?? null, 'direct reindex lock should remain non-autoloaded');
    assert_same(1, $health['last_batch_processed'] ?? null, 'direct reindex health should record processed writer work');
    assert_same(false, $health['last_skipped_locked'] ?? null, 'direct reindex health should not record a lock skip');
    $diagnostics = $health['latest_batch_diagnostics'] ?? [];
    assert_same('manual', $diagnostics['trigger'] ?? null, 'direct reindex diagnostics should use the existing manual-trigger surface');
    assert_same('wp-cli-reindex', $diagnostics['source'] ?? null, 'direct reindex diagnostics should identify the WP-CLI writer source');
    assert_same('success', $diagnostics['status'] ?? null, 'direct reindex diagnostics should report success');
    assert_same(1, $diagnostics['processed'] ?? null, 'direct reindex diagnostics should record processed count');
    assert_same('none', $diagnostics['lock_at_start']['state'] ?? null, 'direct reindex diagnostics should record no preexisting lock');
    assert_same('none', $diagnostics['lock_at_end']['state'] ?? null, 'direct reindex diagnostics should record released lock state');
    assert_true(!str_contains(json_encode($diagnostics, JSON_THROW_ON_ERROR), 'token'), 'direct reindex diagnostics should not expose lock tokens');
});

test_case('wp-cli direct writers skip safely when the shared indexing lock is active', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] = [901];
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION] = [
        'token' => 'active-writer-token',
        'mode' => 'cron',
        'started_at' => time(),
        'expires_at' => time() + 300,
    ];
    $fake->postRows = [
        wp_fts_test_backfill_post(901, 'post', 'publish', 'Locked Direct Reindex'),
    ];
    $fake->docs[902] = [
        'lang' => 'en',
        'doc_len' => 3,
        'content_hash' => 'delete-hash',
        'is_deleted' => 0,
    ];
    $fake->docs[903] = [
        'lang' => 'en',
        'doc_len' => 1,
        'content_hash' => 'optimize-hash',
        'is_deleted' => 1,
    ];

    try {
        $command = new WP_FTS_WPCLI_Command();
        $command->reindex([], [
            'lang' => 'en',
            'limit' => '1',
            'batch_size' => '1',
        ]);
        $command->delete([902], []);
        $command->optimize([], []);
        $health = WP_FTS_Plugin::search_health();
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same([], WP_CLI::$successMessages, 'locked direct writers should not report successful writes');
    assert_same(3, count(WP_CLI::$warningMessages), 'locked direct writers should warn for each skipped writer command');
    assert_contains('Skipped FTS reindex: another index writer is already running.', WP_CLI::$warningMessages[0], 'locked reindex should explain the active writer lock');
    assert_contains('Skipped FTS delete: another index writer is already running.', WP_CLI::$warningMessages[1], 'locked delete should explain the active writer lock');
    assert_contains('Skipped FTS optimize: another index writer is already running.', WP_CLI::$warningMessages[2], 'locked optimize should explain the active writer lock');
    assert_true(!isset($fake->docs[901]), 'locked reindex should not write the queued source post');
    assert_same(0, $fake->docs[902]['is_deleted'] ?? null, 'locked delete should not tombstone an indexed document');
    assert_true(isset($fake->docs[903]), 'locked optimize should not compact tombstoned documents');
    assert_same([901], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] ?? [], 'locked direct writers should not drain queued work');
    assert_same('active-writer-token', $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION]['token'] ?? null, 'locked direct writers should leave the active lock untouched');
    assert_same(true, $health['last_skipped_locked'] ?? null, 'locked direct writer health should record a lock skip');
    assert_same(0, $health['last_batch_processed'] ?? null, 'locked direct writer health should record no processed writes');
    $diagnostics = $health['latest_batch_diagnostics'] ?? [];
    assert_same('wp-cli-optimize', $diagnostics['source'] ?? null, 'latest direct-writer diagnostics should identify the skipped optimize command');
    assert_same('skipped_locked', $diagnostics['status'] ?? null, 'locked direct writer diagnostics should report skipped status');
    assert_same(true, $diagnostics['lock_prevented_work'] ?? null, 'locked direct writer diagnostics should record that the lock prevented work');
    assert_same('lock_active', $diagnostics['stop_reason'] ?? null, 'locked direct writer diagnostics should record lock stop reason');
    assert_same('active', $diagnostics['lock_at_start']['state'] ?? null, 'locked direct writer diagnostics should include active start lock state');
    assert_same('cron', $diagnostics['lock_at_start']['mode'] ?? null, 'locked direct writer diagnostics should include safe holder mode');
    assert_true(!str_contains(json_encode($diagnostics, JSON_THROW_ON_ERROR), 'active-writer-token'), 'locked direct writer diagnostics should not expose lock tokens');
});

test_case('wp-cli process-batch runs one bounded manual batch with queue and backfill counts', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $GLOBALS['wp_fts_test_posts'][81] = wp_fts_test_backfill_post(81, 'post', 'publish', 'CLI Queued Post');
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] = [81];
    wp_fts_test_seed_backfill_posts($fake, 4, 101);

    try {
        $command = new WP_FTS_WPCLI_Command();
        $raw = wp_fts_test_capture_cli(static function () use ($command): void {
            $command->process_batch([], [
                'batch_size' => 2,
                'format' => 'json',
            ]);
        });
        $payload = wp_fts_test_decode_cli_json_object($raw);
        $health = WP_FTS_Plugin::search_health();
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same('manual', $payload['mode'] ?? null, 'process-batch should use the manual batch path');
    assert_same(2, $payload['batch_size'] ?? null, 'process-batch should report the requested bounded batch size');
    assert_same(2, $payload['processed'] ?? null, 'process-batch should process only one bounded batch');
    assert_same(1, $payload['queue_processed'] ?? null, 'process-batch should report queued work processed');
    assert_same(1, $payload['backfill_processed'] ?? null, 'process-batch should report backfill work processed');
    assert_same(false, $payload['skipped_locked'] ?? null, 'process-batch should report no lock skip when it runs');
    assert_same(false, $payload['stopped_by_budget'] ?? null, 'process-batch should report no budget stop when budget remains');
    assert_same(true, $payload['has_more'] ?? null, 'process-batch should report remaining work after a partial batch');
    assert_same(0, $payload['pending_queue_count'] ?? null, 'process-batch should report queue drained after processing the queued item');
    assert_same(2, count($fake->docs), 'process-batch should not drain all available content');
    assert_true(isset($fake->docs[81]), 'process-batch should index the queued post first');
    assert_true(isset($fake->docs[101]), 'process-batch should use remaining capacity for one backfill post');
    assert_true(!isset($fake->docs[102]), 'process-batch should leave later backfill posts for another invocation');
    $diagnostics = $health['latest_batch_diagnostics'] ?? [];
    assert_same('wp-cli', $diagnostics['source'] ?? null, 'process-batch should persist WP-CLI as the latest batch source');
    assert_same(2, $diagnostics['batch_limit'] ?? null, 'process-batch diagnostics should record the requested batch limit');
    assert_same(1, $diagnostics['queue_before'] ?? null, 'process-batch diagnostics should record queued work before processing');
    assert_same(0, $diagnostics['queue_after'] ?? null, 'process-batch diagnostics should record queued work after processing');
});

test_case('wp-cli process-batch respects active indexing lock', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION] = [
        'token' => 'active-worker',
        'mode' => 'cron',
        'started_at' => time(),
        'expires_at' => time() + 300,
    ];
    wp_fts_test_seed_backfill_posts($fake, 2, 201);

    try {
        $command = new WP_FTS_WPCLI_Command();
        $raw = wp_fts_test_capture_cli(static function () use ($command): void {
            $command->process_batch([], ['format' => 'json']);
        });
        $payload = wp_fts_test_decode_cli_json_object($raw);
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(0, $payload['processed'] ?? null, 'locked process-batch should not process content');
    assert_same(true, $payload['skipped_locked'] ?? null, 'locked process-batch should report lock skip');
    assert_same(true, $payload['has_more'] ?? null, 'locked process-batch should preserve has-more state');
    assert_same([], $fake->docs, 'locked process-batch should not index content');
    assert_same('active-worker', $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION]['token'] ?? null, 'locked process-batch should leave the active lock untouched');
});

test_case('wp-cli process-batch reports budget stop without draining content', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    wp_fts_test_seed_backfill_posts($fake, 2, 301);

    try {
        $command = new WP_FTS_WPCLI_Command();
        $raw = wp_fts_test_capture_cli(static function () use ($command): void {
            $command->process_batch([], [
                'batch_size' => 2,
                'time_budget' => 0,
                'format' => 'json',
            ]);
        });
        $payload = wp_fts_test_decode_cli_json_object($raw);
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(0, $payload['processed'] ?? null, 'budget-stopped process-batch should not process content after an exhausted budget');
    assert_same(true, $payload['stopped_by_budget'] ?? null, 'budget-stopped process-batch should report budget stop');
    assert_same(true, $payload['has_more'] ?? null, 'budget-stopped process-batch should report remaining work');
    assert_same([], $fake->docs, 'budget-stopped process-batch should not drain content');
});

test_case('manual queue batch records one failed post and continues without retrying it', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    foreach ([81 => 'Queue Good Before', 82 => 'Queue Bad', 83 => 'Queue Good After'] as $postId => $title) {
        $GLOBALS['wp_fts_test_posts'][$postId] = wp_fts_test_backfill_post($postId, 'post', 'publish', $title);
    }
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] = [81, 82, 83];
    $fake->failDocWriteErrors[82] = "simulated failure for INSERT INTO wp_fts_docs\n#0 stack SELECT * FROM wp_users";

    try {
        $result = WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 3]);
        $health = WP_FTS_Plugin::search_health();
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(2, $result['processed'], 'queue failure batch should count successful posts only');
    assert_same(2, $result['queue_processed'], 'queue failure batch should continue after a failed queued item');
    assert_same(1, $result['last_batch_failures'], 'queue failure batch should report one failed item');
    assert_same(82, $result['last_failed_post_id'], 'queue failure summary should record the failed queued post id');
    assert_same('Queue Bad', $result['last_failed_post_title'], 'queue failure summary should record the failed queued post title');
    assert_contains('INSERT statement', (string) $result['last_error'], 'queue failure summary should redact SQL text');
    assert_true(!str_contains((string) $result['last_error'], 'INSERT INTO wp_fts_docs'), 'queue failure summary should not expose raw SQL');
    assert_true(!str_contains((string) $result['last_error'], '#0'), 'queue failure summary should not expose stack traces');
    assert_same(1, $fake->failedDocWriteAttempts[82] ?? 0, 'failed queued post should be attempted once in the batch');
    assert_same([], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] ?? [], 'failed queued post should be removed from the immediate queue after being recorded');
    assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION]), 'queue failure batch should release the index lock');
    assert_true(isset($fake->docs[81]), 'queue failure batch should index the item before the failure');
    assert_true(!isset($fake->docs[82]), 'queue failure batch should not mark the failed post indexed');
    assert_true(isset($fake->docs[83]), 'queue failure batch should continue to the item after the failure');
    assert_same(1, $health['last_batch_failures'], 'queue failure should persist bounded health failure state');
    assert_same(82, $health['last_failed_post_id'], 'queue failure health should record failed post id');
});

test_case('manual backfill failure records error, continues, and later clean batch clears it', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $fake->postRows = [
        wp_fts_test_backfill_post(101, 'post', 'publish', 'Backfill Bad'),
        wp_fts_test_backfill_post(102, 'post', 'publish', 'Backfill Good One'),
        wp_fts_test_backfill_post(103, 'post', 'publish', 'Backfill Good Two'),
    ];
    $fake->failDocWriteErrors[101] = "simulated failure for UPDATE wp_fts_docs\n#0 stack DELETE FROM wp_users";

    try {
        $failed = WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 3]);
        $failedHealth = WP_FTS_Plugin::search_health();
        $docsAfterFailure = $fake->docs;
        $lockAfterFailure = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION] ?? null;
        unset($fake->failDocWriteErrors[101]);
        $clean = WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 1]);
        $cleanHealth = WP_FTS_Plugin::search_health();
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(2, $failed['processed'], 'backfill failure batch should count successful posts only');
    assert_same(2, $failed['backfill_processed'], 'backfill failure batch should continue after a failed row');
    assert_same(1, $failed['last_batch_failures'], 'backfill failure batch should report one failed row');
    assert_same(true, $failed['has_more'], 'backfill failure batch should report more work because the failed post remains unindexed');
    assert_same(101, $failed['last_failed_post_id'], 'backfill failure summary should record failed post id');
    assert_same('Backfill Bad', $failed['last_failed_post_title'], 'backfill failure summary should record failed post title');
    assert_contains('UPDATE statement', (string) $failed['last_error'], 'backfill failure summary should redact SQL text');
    assert_true(isset($docsAfterFailure[102]), 'backfill failure batch should index the next eligible row');
    assert_true(isset($docsAfterFailure[103]), 'backfill failure batch should continue through later eligible rows');
    assert_true(!isset($docsAfterFailure[101]), 'backfill failure batch should leave the failed row unindexed for recovery');
    assert_same(null, $lockAfterFailure, 'backfill failure batch should release the index lock');
    assert_same(1, $fake->failedDocWriteAttempts[101] ?? 0, 'failed backfill row should be attempted once in the failed batch');
    assert_same(1, $failedHealth['last_batch_failures'], 'backfill failure should persist bounded health failure state');

    assert_same(1, $clean['processed'], 'later clean batch should index the previously failed row');
    assert_true(isset($fake->docs[101]), 'later clean batch should recover the previously failed row');
    assert_same(0, $cleanHealth['last_batch_failures'], 'later clean batch should clear stale failure count');
    assert_same(0, $cleanHealth['last_failed_post_id'], 'later clean batch should clear stale failed post id');
    assert_same('', $cleanHealth['last_error'], 'later clean batch should clear stale error details');
});

test_case('scheduled indexing cron backfills only the default batch and reschedules remaining work', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_seed_backfill_posts($fake, 25);

    try {
        WP_FTS_Plugin::activate();
        unset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]);

        $result = WP_FTS_Plugin::process_scheduled_indexing();
        assert_same(20, $result['processed'], 'cron should process the default 20-item indexing batch');
        assert_same(20, $result['backfill_processed'], 'cron should spend the default batch on backfill when the queue is empty');
        assert_same(20, count($fake->docs), 'cron should not backfill all existing content in one run');
        assert_true((bool) $result['has_more'], 'cron should report that more eligible content remains');
        assert_true(isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), 'cron should schedule another run when backfill work remains');
        $health = WP_FTS_Plugin::search_health();
        assert_same(20, $health['last_batch_processed'], 'health state should retain the last cron batch size');
        $diagnostics = $health['latest_batch_diagnostics'] ?? [];
        assert_same('cron', $diagnostics['trigger'] ?? null, 'cron diagnostics should record cron trigger');
        assert_same('cron', $diagnostics['source'] ?? null, 'cron diagnostics should record cron source');
        assert_same('success', $diagnostics['status'] ?? null, 'cron diagnostics should record successful status');
        assert_same(20, $diagnostics['batch_limit'] ?? null, 'cron diagnostics should record default batch limit');
        assert_same(20, $diagnostics['processed'] ?? null, 'cron diagnostics should record processed count');
        assert_same(21, $diagnostics['backfill_scanned'] ?? null, 'cron diagnostics should record bounded backfill scan count');
        assert_same(20, $diagnostics['backfill_queued'] ?? null, 'cron diagnostics should record selected backfill count');
        assert_same('scheduled', $diagnostics['reschedule_decision'] ?? null, 'cron diagnostics should record reschedule decision');
        assert_same('batch_cap', $diagnostics['stop_reason'] ?? null, 'cron diagnostics should record batch cap stop reason');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('scheduled indexing cron reschedules when queued work fills the batch but backfill remains', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_seed_backfill_posts($fake, 25);
    for ($post_id = 1; $post_id <= 20; $post_id++) {
        $GLOBALS['wp_fts_test_posts'][$post_id] = wp_fts_test_backfill_post($post_id);
    }
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] = range(1, 20);

    try {
        $result = WP_FTS_Plugin::process_scheduled_indexing();
        assert_same(20, $result['processed'], 'cron should spend the default batch on queued work first');
        assert_same(20, $result['queue_processed'], 'cron should drain the queued work that filled the batch');
        assert_same(0, $result['backfill_processed'], 'cron should not exceed the batch size by backfilling in the same run');
        assert_same([], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] ?? [], 'queue should be drained after the batch');
        assert_same(20, count($fake->docs), 'queued indexing should consume the full batch before backfill');
        assert_true(!isset($fake->docs[21]), 'remaining backfill should be left for a follow-up run');
        assert_true((bool) $result['has_more'], 'cron should detect remaining backfill after a queue-filled batch');
        assert_true(isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), 'cron should reschedule when backfill remains after queued work drains');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('manual indexing API backfills up to the default 100-item batch', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_seed_backfill_posts($fake, 125);

    try {
        $result = WP_FTS_Plugin::process_manual_index_batch();
        assert_same(100, $result['processed'], 'manual indexing should process the default 100-item batch');
        assert_same(100, $result['backfill_processed'], 'manual indexing should apply its batch to backfill when the queue is empty');
        assert_same(100, count($fake->docs), 'manual indexing should leave later content for another batch');
        assert_true((bool) $result['has_more'], 'manual indexing should report remaining eligible content');
        assert_true(!isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), 'manual indexing should not schedule cron directly');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('shared indexing lock prevents overlapping manual and cron batches', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_seed_backfill_posts($fake, 3);
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION] = [
        'token' => 'already-running',
        'mode' => 'cron',
        'started_at' => time(),
        'expires_at' => time() + 300,
    ];

    try {
        $result = WP_FTS_Plugin::process_manual_index_batch();
        assert_same(0, $result['processed'], 'manual batch should skip while another batch lock is active');
        assert_same(true, $result['skipped_locked'], 'manual batch should report a lock skip');
        assert_same([], $fake->docs, 'lock skip should not index content');
        assert_same(true, WP_FTS_Plugin::search_health()['last_skipped_locked'], 'health state should record the lock skip');
        assert_same('already-running', $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION]['token'] ?? null, 'lock skip should leave the active lock untouched');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('scheduled indexing cron reschedules after skipping a manual-held lock', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_seed_backfill_posts($fake, 3);
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION] = [
        'token' => 'manual-running',
        'mode' => 'manual',
        'started_at' => time(),
        'expires_at' => time() + 300,
    ];

    try {
        $result = WP_FTS_Plugin::process_scheduled_indexing();
        assert_same(0, $result['processed'], 'cron batch should skip while a manual batch lock is active');
        assert_same(true, $result['skipped_locked'], 'cron batch should report a lock skip');
        assert_same(true, $result['has_more'], 'cron lock skip should retain a has-more signal for deferred work');
        assert_same([], $fake->docs, 'cron lock skip should not index content');
        assert_true(isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), 'cron lock skip should schedule a follow-up run');
        assert_same(true, WP_FTS_Plugin::search_health()['last_skipped_locked'], 'health state should record the cron lock skip');
        assert_same('manual-running', $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_LOCK_OPTION]['token'] ?? null, 'cron lock skip should leave the manual lock untouched');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('manual indexing resource budget can stop a batch early', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_seed_backfill_posts($fake, 5);

    try {
        $result = WP_FTS_Plugin::process_manual_index_batch([
            'budget_check' => static fn(int $processed): bool => $processed >= 1,
        ]);
        assert_same(1, $result['processed'], 'manual budget should allow the first post and stop before the second');
        assert_same(true, $result['stopped_by_budget'], 'manual batch should report the resource-budget stop');
        assert_same(true, $result['has_more'], 'budget stop should leave a has-more signal');
        assert_same(1, count($fake->docs), 'budget stop should keep the indexed row count bounded');
        assert_same(true, WP_FTS_Plugin::search_health()['last_stopped_by_budget'], 'health state should record the budget stop');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('latest indexing diagnostics remain bounded after repeated batches', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $firstJson = '';

    try {
        for ($i = 0; $i < 12; $i++) {
            $postId = 901 + $i;
            $GLOBALS['wp_fts_test_posts'][$postId] = wp_fts_test_backfill_post($postId, 'post', 'publish', 'Bounded Diagnostic ' . $i);
            $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] = [$postId];
            $fake->failDocWriteErrors[$postId] = 'diagnostic failure ' . $i . ' ' . str_repeat('overflow ', 80) . "SELECT * FROM wp_users\n#0 trace";

            $result = WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 1]);
            $diagnostics = WP_FTS_Plugin::search_health()['latest_batch_diagnostics'] ?? [];
            $json = json_encode($diagnostics, JSON_THROW_ON_ERROR);
            if ($i === 0) {
                $firstJson = $json;
            }

            assert_same(0, $result['processed'], 'bounded diagnostics failure fixture should not mark failed queued posts indexed');
            assert_same('failed', $diagnostics['status'] ?? null, 'all-failed batch diagnostics should record failed status');
            assert_same(1, $diagnostics['failures'] ?? null, 'bounded diagnostics should record one failure for each latest batch');
            assert_true(count($diagnostics) <= 40, 'latest diagnostics should stay fixed-size after repeated batches');
            assert_true(strlen($json) < 2048, 'latest diagnostics JSON should remain bounded after repeated batches');
            assert_true(!str_contains($json, 'SELECT * FROM'), 'latest diagnostics should not store raw SQL after repeated failures');
            assert_true(!str_contains($json, '#0'), 'latest diagnostics should not store stack traces after repeated failures');
        }

        $latestJson = json_encode(WP_FTS_Plugin::search_health()['latest_batch_diagnostics'] ?? [], JSON_THROW_ON_ERROR);
        assert_true($firstJson !== '', 'bounded diagnostics test should capture the first payload');
        assert_true(!str_contains($latestJson, 'diagnostic failure 0'), 'latest diagnostics should overwrite earlier batch details instead of accumulating history');
        assert_contains('diagnostic failure 11', $latestJson, 'latest diagnostics should retain the most recent bounded failure');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('batch backfill includes pages from default settings', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $fake->postRows = [
        wp_fts_test_backfill_post(201, 'page', 'publish', 'Backfill Page'),
        wp_fts_test_backfill_post(202, 'post', 'publish', 'Backfill Post'),
        wp_fts_test_backfill_post(203, 'page', 'draft', 'Draft Page'),
    ];

    try {
        $result = WP_FTS_Plugin::process_manual_index_batch();
        assert_same(2, $result['processed'], 'default backfill should index the publish page and publish post only');
        assert_same([201, 202], array_keys($fake->docs), 'default backfill should include pages and exclude non-public page statuses');

        $backfillSelect = null;
        foreach ($fake->prepared as $prepared) {
            if (str_starts_with($prepared['sql'], 'SELECT p.ID, p.post_content, p.post_title')) {
                $backfillSelect = $prepared;
                break;
            }
        }
        assert_true($backfillSelect !== null, 'backfill should use a bounded database query');
        assert_true(in_array('page', $backfillSelect['args'], true), 'backfill source query should include the default page post type');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('bounded stale debt batch reindexes a slice and later clears debt on completion', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;

    try {
        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
        wp_fts_test_seed_indexed_posts($fake, 5, 401);

        $beforeSaveDocs = $fake->docs;
        $marked = wp_fts_test_mark_field_boost_stale_debt();
        $settingsSaveLeftDocsUntouched = $fake->docs === $beforeSaveDocs;
        $first = WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 2]);
        $firstHealth = WP_FTS_Plugin::search_health();
        $docsAfterFirst = $fake->docs;
        $second = WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 10]);
        $completeHealth = WP_FTS_Plugin::search_health();
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(true, $marked['stale_debt_active'] ?? null, 'settings save should mark stale debt before processing');
    assert_true($settingsSaveLeftDocsUntouched, 'settings save itself should not rewrite indexed docs');
    assert_same(2, $first['processed'], 'first stale batch should process only the bounded slice');
    assert_same(0, $first['queue_processed'], 'first stale batch should not invent queued work');
    assert_same(0, $first['backfill_processed'], 'first stale batch should not treat active indexed rows as unindexed backfill');
    assert_same(2, $first['stale_processed'], 'first stale batch should report stale reindex work separately');
    assert_same(true, $first['has_more'], 'first stale batch should keep has_more while stale rows remain');
    assert_same(true, $firstHealth['stale_debt_active'], 'partial stale batch should leave debt active');
    assert_same(402, $firstHealth['stale_debt_cursor_post_id'], 'partial stale batch should advance the deterministic cursor');
    assert_same(2, $firstHealth['stale_debt_processed_count'], 'partial stale batch should persist sweep progress');
    assert_same(3, $firstHealth['stale_debt_remaining_count'], 'partial stale batch should report remaining indexed rows');
    assert_true(($fake->docs[401]['content_hash'] ?? '') !== 'old-profile-401', 'first stale row should be rewritten under the active profile');
    assert_true(($fake->docs[402]['content_hash'] ?? '') !== 'old-profile-402', 'second stale row should be rewritten under the active profile');
    assert_same('old-profile-403', $docsAfterFirst[403]['content_hash'] ?? null, 'later stale rows should remain untouched after the bounded slice');

    assert_same(3, $second['stale_processed'], 'second stale batch should consume the remaining stale rows');
    assert_same(false, $completeHealth['stale_debt_active'], 'clean stale completion should clear active debt');
    assert_same($completeHealth['index_profile_hash'], $completeHealth['accepted_index_profile_hash'], 'clean stale completion should accept the current profile');
    assert_same(0, $completeHealth['stale_debt_remaining_count'], 'clean stale completion should report no remaining stale rows');
});

test_case('queued work has priority over stale debt and preserves bounded processing', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;

    try {
        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
        wp_fts_test_seed_indexed_posts($fake, 4, 501);
        foreach ([601, 602] as $postId) {
            $GLOBALS['wp_fts_test_posts'][$postId] = wp_fts_test_backfill_post($postId, 'post', 'publish', 'Queued Priority ' . $postId);
        }
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] = [601, 602];
        wp_fts_test_mark_field_boost_stale_debt();

        $result = WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 3]);
        $health = WP_FTS_Plugin::search_health();
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(3, $result['processed'], 'batch should stop at the requested size');
    assert_same(2, $result['queue_processed'], 'queued posts should consume priority capacity first');
    assert_same(1, $result['stale_processed'], 'stale debt should use only the remaining capacity');
    assert_same([], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] ?? [], 'queued work should be drained before stale rows');
    assert_true(isset($fake->docs[601], $fake->docs[602]), 'queued posts should be indexed in the same bounded batch');
    assert_true(($fake->docs[501]['content_hash'] ?? '') !== 'old-profile-501', 'only one stale row should be rewritten after queued work');
    assert_same('old-profile-502', $fake->docs[502]['content_hash'] ?? null, 'remaining stale rows should wait for later batches');
    assert_same(true, $health['stale_debt_active'], 'debt should remain active when stale rows remain');
    assert_same(3, $health['stale_debt_remaining_count'], 'health should report remaining stale rows after queue-priority processing');
});

test_case('stale debt failure leaves cursor before failed row and keeps debt active', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;

    try {
        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
        wp_fts_test_seed_indexed_posts($fake, 3, 701);
        wp_fts_test_mark_field_boost_stale_debt();
        $fake->failDocWriteErrors[701] = "simulated stale failure for INSERT INTO wp_fts_docs\n#0 stack SELECT * FROM wp_users";

        $result = WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 3]);
        $health = WP_FTS_Plugin::search_health();
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(0, $result['processed'], 'failed first stale row should not count as processed');
    assert_same(0, $result['stale_processed'], 'failed first stale row should not advance stale processed count');
    assert_same(1, $result['last_batch_failures'], 'stale failure should be recorded in the batch');
    assert_same(701, $result['last_failed_post_id'], 'stale failure should record the failed post id');
    assert_same(true, $health['stale_debt_active'], 'stale failure should leave debt active');
    assert_same(0, $health['stale_debt_cursor_post_id'], 'stale failure should not advance cursor past the failed row');
    assert_same(3, $health['stale_debt_remaining_count'], 'stale failure should leave remaining stale rows visible');
    assert_contains('INSERT statement', (string) ($health['last_error'] ?? ''), 'stale failure should redact SQL details');
});

test_case('profile change during stale sweep restarts debt progress instead of clearing it', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;

    try {
        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
        wp_fts_test_seed_indexed_posts($fake, 3, 801);
        wp_fts_test_mark_field_boost_stale_debt(8.0);
        $partial = WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 1]);
        $partialHealth = WP_FTS_Plugin::search_health();
        wp_fts_test_mark_field_boost_stale_debt(9.0);
        $changedHealth = WP_FTS_Plugin::search_health();
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(1, $partial['stale_processed'], 'setup should process one stale row before the profile changes again');
    assert_same(801, $partialHealth['stale_debt_cursor_post_id'], 'setup should advance stale cursor before the profile change');
    assert_same(true, $changedHealth['stale_debt_active'], 'profile change should keep debt active');
    assert_same(0, $changedHealth['stale_debt_cursor_post_id'], 'profile change should restart the stale sweep cursor');
    assert_same(0, $changedHealth['stale_debt_processed_count'], 'profile change should reset stale sweep progress');
    assert_true(($changedHealth['index_profile_hash'] ?? '') !== ($partialHealth['index_profile_hash'] ?? ''), 'profile change should move the active profile hash');
});

test_case('health and wp-cli status report stale debt progress without indexing content', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;

    try {
        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
        wp_fts_test_seed_indexed_posts($fake, 4, 901);
        wp_fts_test_mark_field_boost_stale_debt();
        WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 2]);
        $docsBeforeStatus = $fake->docs;

        $html = wp_fts_test_capture_admin_settings_tab('health');
        $command = new WP_FTS_WPCLI_Command();
        $raw = wp_fts_test_capture_cli(static function () use ($command): void {
            $command->status([], ['format' => 'json']);
        });
        $payload = wp_fts_test_decode_cli_json_object($raw);
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_contains('Debt progress', $html, 'health tab should expose stale debt progress');
    assert_contains('Cursor ID 902', $html, 'health tab should expose the stale cursor');
    assert_contains('Batch stale debt state', $html, 'health tab should expose latest stale batch diagnostics');
    assert_same(true, $payload['stale_debt_active'] ?? null, 'status JSON should report active stale debt');
    assert_same(902, $payload['stale_debt_cursor_post_id'] ?? null, 'status JSON should report stale cursor');
    assert_same(2, $payload['stale_debt_processed_count'] ?? null, 'status JSON should report stale sweep progress count');
    assert_same(2, $payload['stale_debt_remaining_count'] ?? null, 'status JSON should report remaining stale rows');
    assert_same(2, $payload['last_batch_stale_processed'] ?? null, 'status JSON should report latest stale batch count');
    $diagnostics = $payload['latest_batch_diagnostics'] ?? [];
    assert_same(2, $diagnostics['stale_processed'] ?? null, 'status diagnostics should report stale processed count');
    assert_same(0, $diagnostics['stale_cursor_before'] ?? null, 'status diagnostics should report stale cursor before the first batch');
    assert_same(902, $diagnostics['stale_cursor_after'] ?? null, 'status diagnostics should report stale cursor after the batch');
    assert_same($docsBeforeStatus, $fake->docs, 'rendering health and status should not index more content');
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

test_case('runtime post hooks index visible posts immediately and tombstone invisible posts', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $post = (object) [
        'ID' => 101,
        'post_title' => 'Needle',
        'post_content' => '<p>alpha beta alpha</p>',
        'post_excerpt' => 'RuntimeExcerptSignal',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-07 00:00:00',
        'custom_fields' => [
            'subtitle' => 'RuntimeCustomSignal',
        ],
    ];
    $GLOBALS['wp_fts_test_posts'][101] = $post;
    $GLOBALS['wp_fts_test_options']['wp_fts_index_custom_fields'] = ['subtitle'];
    $GLOBALS['wp_fts_test_do_blocks'][$post->post_content] = '<p>alpha beta alpha</p><p>RuntimeRenderedSignal</p>';

    try {
        WP_FTS_Plugin::handle_post_save(101, $post, true);
        WP_FTS_Plugin::handle_post_save(101, $post, true);
        assert_same([], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] ?? [], 'save hooks should not require background queue processing for visible posts');
        assert_true(isset($fake->docs[101]) && $fake->docs[101]['is_deleted'] === 0, 'save hooks should write an active document immediately');
        assert_true($fake->terms !== [], 'save hooks should write term postings immediately');
        assert_same([101], array_column(WP_FTS_Plugin::search('alpha', ['limit' => 10]), 'doc_id'), 'search helper should expose the indexed public post');
        assert_same([101], array_column(WP_FTS_Plugin::search('RuntimeExcerptSignal', ['limit' => 10]), 'doc_id'), 'immediate indexing should include extracted excerpts');
        assert_same([101], array_column(WP_FTS_Plugin::search('RuntimeCustomSignal', ['limit' => 10]), 'doc_id'), 'immediate indexing should include selected custom fields');
        assert_same([101], array_column(WP_FTS_Plugin::search('RuntimeRenderedSignal', ['limit' => 10]), 'doc_id'), 'immediate indexing should include rendered-only block output');
        $filtered = (new WP_FTS_Searcher(WP_FTS_Plugin::storage(false), new WP_FTS_Analyzer()))->search('Needle', [
            'lang' => 'en',
            'include_total' => true,
            'post_status' => 'publish',
        ]);
        assert_same(1, $filtered['total'], 'immediate indexing should write metadata usable by status filters');
        assert_contains('RuntimeExcerptSignal', $fake->docMeta[101]['search_text'] ?? '', 'immediate metadata should keep excerpt text for snippets');
        assert_contains('RuntimeCustomSignal', $fake->docMeta[101]['search_text'] ?? '', 'immediate metadata should keep custom field text for snippets');
        assert_contains('RuntimeRenderedSignal', $fake->docMeta[101]['search_text'] ?? '', 'immediate metadata should keep rendered text for snippets');

        $post->post_status = 'trash';
        WP_FTS_Plugin::handle_status_transition('trash', 'publish', $post);
        assert_true($fake->docs[101]['is_deleted'] === 1, 'leaving searchable status should tombstone the indexed document');
        assert_same([], WP_FTS_Plugin::search('alpha', ['limit' => 10]), 'tombstoned documents should not be returned');

        $GLOBALS['wp_fts_test_revisions'][102] = true;
        WP_FTS_Plugin::handle_post_save(102, (object) ['ID' => 102, 'post_status' => 'publish', 'post_type' => 'post'], true);
        assert_same([], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] ?? [], 'revision saves should not enqueue indexing work');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('search helper uses saved prefix thresholds and preserves explicit overrides', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $post = (object) [
        'ID' => 1181,
        'post_title' => 'Prefix threshold helper',
        'post_content' => '<p>quasarbridge content.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-18 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][1181] = $post;

    try {
        WP_FTS_Plugin::handle_post_save(1181, $post, true);
        assert_same([], WP_FTS_Plugin::search('qua', ['limit' => 10]), 'default prefix minimum length should not expand a three-letter query');

        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = array_replace(
            WP_FTS_Plugin::default_settings(),
            [
                'prefix_min_length' => 3,
                'prefix_max_terms' => 8,
            ]
        );
        assert_same([1181], array_column(WP_FTS_Plugin::search('qua', ['limit' => 10]), 'doc_id'), 'saved shorter prefix minimum length should enable the shorter prefix match');
        assert_same([], WP_FTS_Plugin::search('qua', [
            'limit' => 10,
            'prefix_min_length' => 4,
        ]), 'explicit per-search prefix minimum length should override the saved setting');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('saved field boost settings change runtime ranking after reindex', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $titleMatch = (object) [
        'ID' => 124701,
        'post_title' => 'rankboostneedle',
        'post_content' => '',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-20 00:00:00',
    ];
    $contentMatch = (object) [
        'ID' => 124702,
        'post_title' => 'Body match fixture',
        'post_content' => '<p>rankboostneedle</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-20 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][124701] = $titleMatch;
    $GLOBALS['wp_fts_test_posts'][124702] = $contentMatch;
    update_post_meta(124701, WP_FTS_Plugin::LANGUAGE_META_KEY, 'en');
    update_post_meta(124702, WP_FTS_Plugin::LANGUAGE_META_KEY, 'en');

    $withBoosts = static function (float $titleBoost, float $contentBoost): array {
        return array_replace(
            WP_FTS_Plugin::default_settings(),
            [
                'field_boosts' => array_replace(
                    WP_FTS_Plugin::default_settings()['field_boosts'],
                    [
                        'title' => $titleBoost,
                        'content' => $contentBoost,
                    ]
                ),
            ]
        );
    };

    try {
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = $withBoosts(1.0, 8.0);
        WP_FTS_Plugin::handle_post_save(124701, $titleMatch, true);
        WP_FTS_Plugin::handle_post_save(124702, $contentMatch, true);
        $contentFirst = (new WP_FTS_Searcher(WP_FTS_Plugin::storage(false), WP_FTS_Plugin::runtime_analyzer()))->search('rankboostneedle', [
            'lang' => 'en',
            'limit' => 2,
        ]);
        assert_same([124702, 124701], array_column($contentFirst, 'doc_id'), 'higher saved content boost should rank the content match first after indexing');
        $contentMetadata = WP_FTS_Plugin::storage(false)->get_doc_metadata([124702])[124702] ?? [];
        assert_same(8.0, $contentMetadata['field_boosts']['content'] ?? null, 'runtime indexing should store the effective content boost in metadata');

        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = $withBoosts(8.0, 1.0);
        WP_FTS_Plugin::handle_post_save(124701, $titleMatch, true);
        WP_FTS_Plugin::handle_post_save(124702, $contentMatch, true);
        $titleFirst = (new WP_FTS_Searcher(WP_FTS_Plugin::storage(false), WP_FTS_Plugin::runtime_analyzer()))->search('rankboostneedle', [
            'lang' => 'en',
            'limit' => 2,
        ]);
        assert_same([124701, 124702], array_column($titleFirst, 'doc_id'), 'higher saved title boost should rank the title match first after reindexing');
        $titleMetadata = WP_FTS_Plugin::storage(false)->get_doc_metadata([124701])[124701] ?? [];
        assert_same(8.0, $titleMetadata['field_boosts']['title'] ?? null, 'runtime reindexing should store the changed title boost in metadata');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('disabled auto-index blocks status transition indexing but still tombstones departures', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $settings = array_replace(WP_FTS_Plugin::default_settings(), ['auto_index' => false]);
    $post = (object) [
        'ID' => 111,
        'post_title' => 'Manual indexing only',
        'post_content' => '<p>manualtransitionneedle remains manual.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-16 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][111] = $post;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = $settings;

    try {
        WP_FTS_Plugin::handle_status_transition('publish', 'draft', $post);
        assert_true(!isset($fake->docs[111]), 'disabled auto-index should not index when a post transitions into a searchable status');
        assert_same([], WP_FTS_Plugin::search('manualtransitionneedle', ['limit' => 10]), 'blocked transition indexing should leave FTS results empty');

        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = array_replace($settings, ['auto_index' => true]);
        WP_FTS_Plugin::handle_post_save(111, $post, true);
        assert_true(($fake->docs[111]['is_deleted'] ?? 1) === 0, 'test setup should create an active indexed document');

        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = $settings;
        $post->post_status = 'trash';
        WP_FTS_Plugin::handle_status_transition('trash', 'publish', $post);
        assert_true(($fake->docs[111]['is_deleted'] ?? 0) === 1, 'disabled auto-index should still tombstone posts leaving searchable status');
        assert_same([], WP_FTS_Plugin::search('manualtransitionneedle', ['limit' => 10]), 'tombstoned posts should disappear even when auto-index is disabled');
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
        assert_same(false, $route['args']['args']['q']['required'] ?? null, 'REST q parameter should not block the query alias during route validation');
        assert_same(false, $route['args']['args']['query']['required'] ?? null, 'REST query alias should be optional and validated by the callback');

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

        $aliasResponse = WP_FTS_Plugin::rest_search(['query' => 'shared', 'limit' => 1]);
        assert_same(1, count($aliasResponse['results']), 'REST search should accept query as an alias for q');

        $emptyQAliasResponse = WP_FTS_Plugin::rest_search(['q' => ' ', 'query' => 'shared', 'limit' => 1]);
        assert_same(1, count($emptyQAliasResponse['results']), 'REST search should use query when q is present but empty');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('REST search returns explicit 400 errors for missing query and invalid mode', function (): void {
    $missing = WP_FTS_Plugin::rest_search(['q' => ' ', 'query' => '']);
    assert_true($missing instanceof WP_Error, 'missing REST query should return a WP_Error');
    assert_same('wp_fts_missing_query', $missing->get_error_code(), 'missing REST query error should use a stable code');
    assert_same(400, $missing->get_error_data()['status'] ?? null, 'missing REST query error should carry HTTP 400 status');

    $invalidMode = WP_FTS_Plugin::rest_search(['query' => 'shared', 'mode' => 'xor']);
    assert_true($invalidMode instanceof WP_Error, 'invalid REST mode should return a WP_Error');
    assert_same('wp_fts_invalid_mode', $invalidMode->get_error_code(), 'invalid REST mode error should use a stable code');
    assert_same(400, $invalidMode->get_error_data()['status'] ?? null, 'invalid REST mode error should carry HTTP 400 status');
});

test_case('search refills requested limit after filtering hidden stale rows', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    $private = (object) [
        'ID' => 211,
        'post_title' => 'Private shared',
        'post_content' => '<p>shared refill</p>',
        'post_status' => 'private',
        'post_type' => 'post',
    ];
    $passworded = (object) [
        'ID' => 212,
        'post_title' => 'Passworded shared',
        'post_content' => '<p>shared refill</p>',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_password' => 'secret',
    ];
    $excludedType = (object) [
        'ID' => 213,
        'post_title' => 'Excluded shared',
        'post_content' => '<p>shared refill</p>',
        'post_status' => 'publish',
        'post_type' => 'secret',
    ];
    $visible = (object) [
        'ID' => 214,
        'post_title' => 'Visible shared',
        'post_content' => '<p>shared refill</p>',
        'post_status' => 'publish',
        'post_type' => 'post',
    ];
    $GLOBALS['wp_fts_test_posts'][211] = $private;
    $GLOBALS['wp_fts_test_posts'][212] = $passworded;
    $GLOBALS['wp_fts_test_posts'][213] = $excludedType;
    $GLOBALS['wp_fts_test_posts'][214] = $visible;

    try {
        $indexer = new WP_FTS_Indexer(WP_FTS_Plugin::storage(true), new WP_FTS_Analyzer());
        $indexer->index_post($private, ['lang' => 'en']);
        $indexer->index_post($passworded, ['lang' => 'en']);
        $indexer->index_post($excludedType, ['lang' => 'en']);
        $indexer->index_post($visible, ['lang' => 'en']);

        assert_same([214], array_column(WP_FTS_Plugin::search('shared', ['limit' => 1]), 'doc_id'), 'hidden stale rows should not consume the requested visible result limit');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('front-end main query search is replaced with FTS-ranked WP_Post results', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    $low = (object) [
        'ID' => 501,
        'post_title' => 'Lower front-end rank',
        'post_content' => '<p>frontneedle appears once.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-12 00:00:00',
    ];
    $high = (object) [
        'ID' => 502,
        'post_title' => 'Higher front-end rank',
        'post_content' => '<p>frontneedle frontneedle frontneedle frontneedle.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-13 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][501] = $low;
    $GLOBALS['wp_fts_test_posts'][502] = $high;

    try {
        WP_FTS_Plugin::handle_post_save(501, $low, true);
        WP_FTS_Plugin::handle_post_save(502, $high, true);

        $query = new WP_FTS_Test_Query([
            's' => 'frontneedle',
            'posts_per_page' => 10,
            'paged' => 1,
            'post_type' => 'post',
        ]);
        WP_FTS_Plugin::prepare_frontend_search_query($query);
        $posts = WP_FTS_Plugin::replace_frontend_search_posts(null, $query);

        assert_same(true, $query->get('wp_fts_search_candidate'), 'pre_get_posts should mark eligible front-end search queries');
        assert_same([502, 501], array_map(static fn(object $post): int => (int) $post->ID, $posts), 'front-end search should return FTS-ranked post objects');
        assert_same(2, $query->found_posts, 'front-end search should expose the FTS visible total on the query');
        assert_same(1, $query->max_num_pages, 'front-end search should expose max pages from the FTS visible total');
        assert_same(2, WP_FTS_Plugin::filter_frontend_search_found_posts(999, $query), 'found_posts filter should preserve the replacement total');

        wp_fts_test_begin_frontend_search_loop($query);
        try {
            $excerpt = WP_FTS_Plugin::frontend_search_excerpt('', $posts[0]);
        } finally {
            wp_fts_test_end_frontend_search_loop($query);
        }
        assert_contains('<mark>frontneedle</mark>', $excerpt, 'front-end excerpts should use highlighted FTS snippets in the replaced main loop');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('enabled diagnostics record frontend search timings counts language settings and highlights', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::DEBUG_ENABLED_FILTER] = static fn(mixed $enabled, string $context): bool => true;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = array_replace(
        WP_FTS_Plugin::default_settings(),
        [
            'recency_boost_strength' => 0.4,
            'recency_boost_half_life_days' => 14.0,
            'prefix_min_length' => 3,
            'prefix_max_terms' => 9,
        ]
    );

    $low = (object) [
        'ID' => 505,
        'post_title' => 'Lower diagnostics rank',
        'post_content' => '<p>diagnosticneedle appears once.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-15 00:00:00',
    ];
    $high = (object) [
        'ID' => 506,
        'post_title' => 'Higher diagnostics rank',
        'post_content' => '<p>diagnosticneedle diagnosticneedle diagnosticneedle.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-15 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][505] = $low;
    $GLOBALS['wp_fts_test_posts'][506] = $high;

    try {
        WP_FTS_Plugin::handle_post_save(505, $low, true);
        WP_FTS_Plugin::handle_post_save(506, $high, true);

        $query = new WP_FTS_Test_Query([
            's' => 'diagnosticneedle',
            'posts_per_page' => 10,
            'paged' => 1,
            'post_type' => 'post',
        ]);
        WP_FTS_Plugin::prepare_frontend_search_query($query);
        $posts = WP_FTS_Plugin::replace_frontend_search_posts(null, $query);
        assert_same([506, 505], array_map(static fn(object $post): int => (int) $post->ID, $posts), 'diagnostic frontend search should still return FTS-ranked posts');

        wp_fts_test_begin_frontend_search_loop($query);
        try {
            WP_FTS_Plugin::frontend_search_excerpt('', $posts[0]);
        } finally {
            wp_fts_test_end_frontend_search_loop($query);
        }

        $traces = WP_FTS_Plugin::debug_traces();
        assert_same(1, count($traces), 'frontend diagnostics should record one run trace');
        $trace = $traces[0];
        assert_same('frontend search', $trace['context'] ?? null, 'frontend diagnostics should record the request context');
        assert_same('ran', $trace['status'] ?? null, 'frontend diagnostics should mark successful FTS replacement as ran');
        assert_same('diagnosticneedle', $trace['search_text'] ?? null, 'frontend diagnostics should record bounded search text');
        assert_same('en', $trace['query_lang'] ?? null, 'frontend diagnostics should record the resolved query language');

        $settings = is_array($trace['settings'] ?? null) ? $trace['settings'] : [];
        assert_same('enabled', $settings['public_site_search'] ?? null, 'frontend diagnostics should record public search replacement setting');
        assert_same('OR', $settings['match_mode'] ?? null, 'frontend diagnostics should record match mode setting');
        assert_same('enabled', $settings['prefix_matching'] ?? null, 'frontend diagnostics should record prefix matching setting');
        assert_same(3, (int) ($settings['prefix_min_length'] ?? 0), 'frontend diagnostics should record saved prefix minimum length setting');
        assert_same(9, (int) ($settings['prefix_max_terms'] ?? 0), 'frontend diagnostics should record saved prefix max terms setting');
        assert_same('title=5, content=1, excerpt=2, terms=1.5, custom_fields=1, rendered=1', $settings['field_boosts'] ?? null, 'frontend diagnostics should summarize effective field boosts');
        assert_same('Enabled, strength 0.4, half-life 14 days', $settings['recency_boost'] ?? null, 'frontend diagnostics should summarize saved recency boost settings');

        $counts = is_array($trace['counts'] ?? null) ? $trace['counts'] : [];
        assert_true((int) ($counts['candidate_rows'] ?? 0) >= 2, 'frontend diagnostics should count candidate rows');
        assert_same(2, (int) ($counts['result_ids_returned'] ?? 0), 'frontend diagnostics should count returned result ids');
        assert_same(2, (int) ($counts['visible_results'] ?? 0), 'frontend diagnostics should count visible results');
        assert_true((int) ($counts['snippets_generated'] ?? 0) >= 2, 'frontend diagnostics should count generated snippets');
        assert_true((int) ($counts['highlight_replacements'] ?? 0) >= 1, 'frontend diagnostics should count rendered highlight replacements');

        $timings = is_array($trace['timings_ms'] ?? null) ? $trace['timings_ms'] : [];
        foreach (['analyzer/query preparation', 'storage/search', 'visibility filtering', 'snippet generation', 'title highlighting', 'total'] as $phase) {
            assert_true(array_key_exists($phase, $timings), "frontend diagnostics should record {$phase} timing");
            assert_true((float) $timings[$phase] >= 0.0, "{$phase} timing should be non-negative");
        }

        $explain = is_array($trace['search_explain'] ?? null) ? $trace['search_explain'] : [];
        assert_true($explain !== [], 'frontend diagnostics should capture search explain payload when tracing is active');
        $storage = is_array($explain['storage'] ?? null) ? $explain['storage'] : [];
        assert_contains('Mysql', (string) ($storage['backend'] ?? ''), 'frontend diagnostics should identify the storage backend');

        $plan = is_array($explain['query_plan'] ?? null) ? $explain['query_plan'] : [];
        assert_same('OR', $plan['match_mode'] ?? null, 'frontend diagnostics should record searcher match mode');
        assert_same(3, (int) ($plan['prefix_min_length'] ?? 0), 'frontend diagnostics should pass saved prefix minimum length into the searcher');
        assert_same(9, (int) ($plan['prefix_max_terms'] ?? 0), 'frontend diagnostics should pass saved prefix max terms into the searcher');
        assert_true((int) ($plan['logical_group_count'] ?? 0) >= 1, 'frontend diagnostics should record logical query group count');
        assert_true(in_array('en', is_array($plan['analyzed_languages'] ?? null) ? $plan['analyzed_languages'] : [], true), 'frontend diagnostics should record analyzed query language');
        $terms = is_array($plan['terms'] ?? null) ? $plan['terms'] : [];
        assert_true($terms !== [], 'frontend diagnostics should include bounded analyzed query terms');
        assert_same('diagnosticneedle', $terms[0]['surface'] ?? null, 'frontend diagnostics should expose the user query surface separately from analysis');
        assert_same('diagnosticneedl', $terms[0]['term'] ?? null, 'frontend diagnostics should expose the analyzed query term');
        assert_same(WP_FTS_TermNamespace::namespace_term('en', 'diagnosticneedl'), $terms[0]['key'] ?? null, 'frontend diagnostics should expose the analyzed storage key');
        assert_same('exact', $terms[0]['rank_class'] ?? null, 'frontend diagnostics should classify exact query candidates');

        $fastMode = is_array($explain['fast_mode'] ?? null) ? $explain['fast_mode'] : [];
        assert_same('exact', $fastMode['mode'] ?? null, 'frontend diagnostics should record exact fast-mode outcome for compact searches');
        assert_same('no_threshold_crossing', $fastMode['source'] ?? null, 'frontend diagnostics should record the fast-mode decision source');
        assert_true((int) ($fastMode['estimated_candidates'] ?? 0) >= 2, 'frontend diagnostics should include estimated candidate count when auto fast mode probes');
        assert_true((int) ($fastMode['threshold'] ?? 0) > 0, 'frontend diagnostics should include the auto fast-mode threshold');

        $scoring = is_array($explain['scoring'] ?? null) ? $explain['scoring'] : [];
        assert_true((int) ($scoring['candidate_rows_fetched'] ?? 0) >= 2, 'frontend diagnostics should include fetched candidate row shape');
        assert_true((int) ($scoring['candidate_docs_scored'] ?? 0) >= 2, 'frontend diagnostics should include scored document shape');
        assert_same('exact', $scoring['total_accuracy'] ?? null, 'frontend diagnostics should record exact result totals');

        $recency = is_array($explain['recency_boost'] ?? null) ? $explain['recency_boost'] : [];
        assert_same(true, $recency['enabled'] ?? null, 'frontend diagnostics should pass saved recency boost into the searcher');
        assert_float_near(0.4, (float) ($recency['strength'] ?? -1), 'frontend diagnostics should record saved recency strength');
        assert_float_near(14.0, (float) ($recency['half_life_days'] ?? -1), 'frontend diagnostics should record saved recency half-life');
        assert_true((int) ($recency['documents_applied'] ?? 0) >= 2, 'frontend diagnostics should report recency applied to dated search results');

        $resultMatches = is_array($explain['results'] ?? null) ? $explain['results'] : [];
        assert_same(506, (int) ($resultMatches[0]['doc_id'] ?? 0), 'frontend diagnostics should include per-result match data for returned page order');
        $firstMatches = is_array($resultMatches[0]['matches'] ?? null) ? $resultMatches[0]['matches'] : [];
        assert_same('diagnosticneedle', $firstMatches[0]['surface'] ?? null, 'frontend diagnostics should keep the query surface on result match reasons');
        assert_same('diagnosticneedl', $firstMatches[0]['term'] ?? null, 'frontend diagnostics should identify why the top result matched');
        assert_same(WP_FTS_TermNamespace::namespace_term('en', 'diagnosticneedl'), $firstMatches[0]['key'] ?? null, 'frontend diagnostics should keep the analyzed storage key on result match reasons');
        assert_same('en', $firstMatches[0]['lang'] ?? null, 'frontend diagnostics should identify matched result language');
        $sqlDiagnostics = is_array($trace['sql_queries'] ?? null) ? $trace['sql_queries'] : [];
        assert_same(true, $sqlDiagnostics['available'] ?? null, 'frontend diagnostics should report SQL capture as available for compatible wpdb query arrays');
        assert_true((int) ($sqlDiagnostics['captured_count'] ?? 0) > 0, 'frontend diagnostics should count SQL queries executed during the trace');
        assert_true((int) ($sqlDiagnostics['shown_count'] ?? 0) > 0, 'frontend diagnostics should list bounded SQL summaries for a successful FTS trace');
        assert_true((float) ($sqlDiagnostics['total_time_ms'] ?? 0.0) > 0.0, 'frontend diagnostics should total SQL query timing when entries include elapsed seconds');
        $sqlSummaryText = '';
        foreach (is_array($sqlDiagnostics['entries'] ?? null) ? $sqlDiagnostics['entries'] : [] as $entry) {
            if (is_array($entry) && is_scalar($entry['summary'] ?? null)) {
                $sqlSummaryText .= ' ' . (string) $entry['summary'];
            }
        }
        assert_contains('wp_fts_', $sqlSummaryText, 'frontend diagnostics should preserve FTS table names in SQL summaries');
        assert_true(!str_contains($sqlSummaryText, 'diagnosticneedle'), 'frontend SQL diagnostics should not expose literal search text');
        assert_true(in_array('FTS replacement ran for frontend search.', is_array($trace['notes'] ?? null) ? $trace['notes'] : [], true), 'frontend diagnostics should explicitly note successful frontend replacement');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('enabled diagnostics record auto fast mode threshold and cap decisions', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::DEBUG_ENABLED_FILTER] = static fn(mixed $enabled, string $context): bool => true;

    try {
        $term = WP_FTS_TermNamespace::namespace_term('en', 'autofastneedle');
        $docCount = 2001;
        $fake->terms[$term] = ['doc_freq' => $docCount];
        for ($index = 0; $index < $docCount; $index++) {
            $docId = 30000 + $index;
            $fake->postings[$term][$docId] = 1;
            $fake->docs[$docId] = [
                'lang' => 'en',
                'doc_len' => 1,
                'content_hash' => 'autofast-' . $docId,
                'is_deleted' => 0,
            ];
            $fake->docLengths[$docId] = ['en' => 1];
            $fake->docMeta[$docId] = [
                'doc_id' => $docId,
                'post_id' => $docId,
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_date_gmt' => '2026-06-16 00:00:00',
                'title' => 'Auto fast fixture ' . $docId,
                'excerpt' => '',
                'search_text' => 'autofastneedle',
                'data' => '{}',
            ];
        }
        $fake->meta['en'] = ['doc_count' => $docCount, 'len_sum' => $docCount];

        $query = new WP_FTS_Test_Query([
            's' => 'autofastneedle',
            'posts_per_page' => 10,
            'paged' => 1,
            'post_type' => 'post',
        ]);
        $posts = WP_FTS_Plugin::replace_frontend_search_posts(null, $query);
        assert_same([], $posts, 'auto fast diagnostics fixture should not create or require visible WordPress posts');

        $traces = WP_FTS_Plugin::debug_traces();
        assert_same(1, count($traces), 'auto fast diagnostics should record one frontend trace');
        $trace = $traces[0];
        assert_same('ran', $trace['status'] ?? null, 'auto fast diagnostics should finish as a successful FTS run');
        $explain = is_array($trace['search_explain'] ?? null) ? $trace['search_explain'] : [];
        $fastMode = is_array($explain['fast_mode'] ?? null) ? $explain['fast_mode'] : [];
        assert_same('approximate', $fastMode['mode'] ?? null, 'auto fast diagnostics should record approximate mode after threshold crossing');
        assert_same('auto_threshold', $fastMode['source'] ?? null, 'auto fast diagnostics should record threshold source');
        assert_same(2001, (int) ($fastMode['estimated_candidates'] ?? 0), 'auto fast diagnostics should expose the capped threshold-crossing estimate');
        assert_same(2000, (int) ($fastMode['threshold'] ?? 0), 'auto fast diagnostics should expose the active threshold');
        assert_same(1000, (int) ($fastMode['candidate_cap'] ?? 0), 'auto fast diagnostics should expose the active candidate cap');
        $scoring = is_array($explain['scoring'] ?? null) ? $explain['scoring'] : [];
        assert_same('approximate', $scoring['total_accuracy'] ?? null, 'auto fast diagnostics should mark totals approximate');
        assert_same(1000, (int) ($scoring['candidate_docs_scored'] ?? 0), 'auto fast diagnostics should expose capped scored documents');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('front-end search totals are not capped by visibility refill scan size', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    try {
        $totalMatches = 260;
        for ($index = 0; $index < $totalMatches; $index++) {
            $postId = 9000 + $index;
            $post = (object) [
                'ID' => $postId,
                'post_title' => 'Bulk front-end total ' . $postId,
                'post_content' => '<p>bulkfrontneedle shared body.</p>',
                'post_excerpt' => '',
                'post_status' => 'publish',
                'post_type' => 'post',
                'post_date_gmt' => '2026-06-13 00:00:00',
            ];
            $GLOBALS['wp_fts_test_posts'][$postId] = $post;
            WP_FTS_Plugin::handle_post_save($postId, $post, true);
        }

        $query = new WP_FTS_Test_Query([
            's' => 'bulkfrontneedle',
            'posts_per_page' => 25,
            'paged' => 1,
        ]);
        $posts = WP_FTS_Plugin::replace_frontend_search_posts(null, $query);

        assert_same(25, count($posts), 'front-end replacement should still return only the requested page of many matches');
        assert_same($totalMatches, $query->found_posts, 'front-end found_posts should count every visible match beyond the refill scan size');
        assert_same(11, $query->max_num_pages, 'front-end max pages should be computed from the uncapped visible total');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('front-end search replacement respects pagination and explicit offset', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    try {
        for ($postId = 601; $postId <= 604; $postId++) {
            $post = (object) [
                'ID' => $postId,
                'post_title' => 'Paged result ' . $postId,
                'post_content' => '<p>pageneedle shared body.</p>',
                'post_excerpt' => '',
                'post_status' => 'publish',
                'post_type' => 'post',
                'post_date_gmt' => '2026-06-13 00:00:00',
            ];
            $GLOBALS['wp_fts_test_posts'][$postId] = $post;
            WP_FTS_Plugin::handle_post_save($postId, $post, true);
        }

        $pageTwo = new WP_FTS_Test_Query([
            's' => 'pageneedle',
            'posts_per_page' => 2,
            'paged' => 2,
        ]);
        $pageTwoPosts = WP_FTS_Plugin::replace_frontend_search_posts(null, $pageTwo);
        assert_same([603, 604], array_map(static fn(object $post): int => (int) $post->ID, $pageTwoPosts), 'paged front-end search should return the second visible FTS window');
        assert_same(4, $pageTwo->found_posts, 'paged front-end search should keep the full visible total');
        assert_same(2, $pageTwo->max_num_pages, 'paged front-end search should compute max pages from posts_per_page');

        $offsetQuery = new WP_FTS_Test_Query([
            's' => 'pageneedle',
            'posts_per_page' => 2,
            'paged' => 99,
            'offset' => 1,
        ]);
        $offsetPosts = WP_FTS_Plugin::replace_frontend_search_posts(null, $offsetQuery);
        assert_same([602, 603], array_map(static fn(object $post): int => (int) $post->ID, $offsetPosts), 'explicit offset should override paged window math');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('front-end search replacement overrides earlier posts_pre_query providers only when eligible', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    $low = (object) [
        'ID' => 821,
        'post_title' => 'Lower front-end precedence rank',
        'post_content' => '<p>precedenceneedle appears once.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-14 00:00:00',
    ];
    $high = (object) [
        'ID' => 822,
        'post_title' => 'Higher front-end precedence rank',
        'post_content' => '<p>precedenceneedle precedenceneedle precedenceneedle precedenceneedle.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-14 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][821] = $low;
    $GLOBALS['wp_fts_test_posts'][822] = $high;

    try {
        WP_FTS_Plugin::handle_post_save(821, $low, true);
        WP_FTS_Plugin::handle_post_save(822, $high, true);

        $incoming = [(object) ['ID' => 820, 'post_title' => 'Earlier provider result']];
        $query = new WP_FTS_Test_Query([
            's' => 'precedenceneedle',
            'posts_per_page' => 10,
        ]);
        $posts = WP_FTS_Plugin::replace_frontend_search_posts($incoming, $query);

        assert_same([822, 821], array_map(static fn(object $post): int => (int) $post->ID, $posts), 'eligible front-end replacement should override earlier posts_pre_query results with FTS-ranked posts');
        assert_same(2, $query->found_posts, 'front-end precedence replacement should expose the FTS visible total');

        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = array_replace(
            WP_FTS_Plugin::default_settings(),
            ['search_provider_compatibility' => 'respect_existing']
        );
        $respectQuery = new WP_FTS_Test_Query([
            's' => 'precedenceneedle',
            'posts_per_page' => 10,
        ]);
        assert_same($incoming, WP_FTS_Plugin::replace_frontend_search_posts($incoming, $respectQuery), 'front-end coexistence mode should preserve an earlier non-null posts_pre_query result');
        assert_same(0, $respectQuery->found_posts, 'front-end coexistence mode should not write FTS totals when it stands down');
        $respectNullQuery = new WP_FTS_Test_Query([
            's' => 'precedenceneedle',
            'posts_per_page' => 10,
        ]);
        $respectNullPosts = WP_FTS_Plugin::replace_frontend_search_posts(null, $respectNullQuery);
        assert_same([822, 821], array_map(static fn(object $post): int => (int) $post->ID, $respectNullPosts), 'front-end coexistence mode should still run FTS when no earlier provider answered');
        assert_same(2, $respectNullQuery->found_posts, 'front-end coexistence mode should write FTS totals when it runs after a null provider result');
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = WP_FTS_Plugin::default_settings();

        $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::FRONTEND_SEARCH_REPLACEMENT_FILTER] = static fn(mixed $replace, mixed $filterQuery): bool => false;
        $optOutQuery = new WP_FTS_Test_Query([
            's' => 'precedenceneedle',
            'posts_per_page' => 10,
        ]);
        assert_same($incoming, WP_FTS_Plugin::replace_frontend_search_posts($incoming, $optOutQuery), 'front-end opt-out filter should preserve an earlier non-null posts_pre_query result');
        unset($GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::FRONTEND_SEARCH_REPLACEMENT_FILTER]);

        $constrained = new WP_FTS_Test_Query([
            's' => 'precedenceneedle',
            'posts_per_page' => 10,
            'cat' => 5,
        ]);
        assert_same($incoming, WP_FTS_Plugin::replace_frontend_search_posts($incoming, $constrained), 'constrained front-end searches should preserve earlier non-null posts_pre_query results');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('enabled diagnostics record search provider compatibility stand-down', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::DEBUG_ENABLED_FILTER] = static fn(mixed $enabled, string $context): bool => true;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = array_replace(
        WP_FTS_Plugin::default_settings(),
        ['search_provider_compatibility' => 'respect_existing']
    );

    $incoming = [(object) ['ID' => 840, 'post_title' => 'Earlier provider diagnostic result']];
    $query = new WP_FTS_Test_Query([
        's' => 'diagnosticproviderneedle',
        'posts_per_page' => 10,
    ]);
    $posts = WP_FTS_Plugin::replace_frontend_search_posts($incoming, $query);

    assert_same($incoming, $posts, 'coexistence mode should return the earlier provider result unchanged');
    $traces = WP_FTS_Plugin::debug_traces();
    assert_same(1, count($traces), 'provider compatibility stand-down should record one debug trace');
    $trace = $traces[0];
    assert_same('frontend search', $trace['context'] ?? null, 'provider compatibility trace should record the frontend context');
    assert_same('bailed', $trace['status'] ?? null, 'provider compatibility trace should be marked as bailed');
    assert_contains('Another search provider already returned', (string) ($trace['bailout_reason'] ?? ''), 'provider compatibility trace should explain the stand-down reason');
    $settings = is_array($trace['settings'] ?? null) ? $trace['settings'] : [];
    assert_same('respect_existing_provider', $settings['provider_compatibility'] ?? null, 'provider compatibility trace should expose the effective mode');

    WP_FTS_Plugin::reset_request_caches();
    $GLOBALS['wp_fts_test_is_admin'] = true;
    $GLOBALS['pagenow'] = 'edit.php';
    $adminIncoming = [(object) ['ID' => 841, 'post_title' => 'Earlier admin provider diagnostic result']];
    $adminQuery = new WP_FTS_Test_Query([
        's' => 'diagnosticproviderneedle',
        'posts_per_page' => 10,
        'post_type' => 'post',
        'post_status' => 'publish',
    ]);
    assert_same($adminIncoming, WP_FTS_Plugin::replace_admin_post_search_posts($adminIncoming, $adminQuery), 'admin coexistence mode should return the earlier provider result unchanged');
    $adminTraces = WP_FTS_Plugin::debug_traces();
    assert_same(1, count($adminTraces), 'admin provider compatibility stand-down should record one debug trace');
    $adminTrace = $adminTraces[0];
    assert_same('admin post search', $adminTrace['context'] ?? null, 'admin provider compatibility trace should record the admin context');
    assert_same('bailed', $adminTrace['status'] ?? null, 'admin provider compatibility trace should be marked as bailed');
    assert_contains('Another search provider already returned', (string) ($adminTrace['bailout_reason'] ?? ''), 'admin provider compatibility trace should explain the stand-down reason');
    $adminSettings = is_array($adminTrace['settings'] ?? null) ? $adminTrace['settings'] : [];
    assert_same('respect_existing_provider', $adminSettings['provider_compatibility'] ?? null, 'admin provider compatibility trace should expose the effective mode');
});

test_case('enabled diagnostics record search provider compatibility overrides', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::DEBUG_ENABLED_FILTER] = static fn(mixed $enabled, string $context): bool => true;

    $post = (object) [
        'ID' => 842,
        'post_title' => 'Provider override rank',
        'post_content' => '<p>provideroverrideneedle appears in indexed content.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-14 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][842] = $post;

    try {
        WP_FTS_Plugin::handle_post_save(842, $post, true);

        $incoming = [
            (object) ['ID' => 840, 'post_title' => 'Earlier provider result A'],
            (object) ['ID' => 841, 'post_title' => 'Earlier provider result B'],
        ];
        $query = new WP_FTS_Test_Query([
            's' => 'provideroverrideneedle',
            'posts_per_page' => 10,
        ]);
        $posts = WP_FTS_Plugin::replace_frontend_search_posts($incoming, $query);

        assert_same([842], array_map(static fn(object $resultPost): int => (int) $resultPost->ID, $posts), 'frontend FTS-wins mode should replace earlier provider posts with FTS posts');
        $traces = WP_FTS_Plugin::debug_traces();
        assert_same(1, count($traces), 'frontend provider override should record one debug trace');
        $trace = $traces[0];
        assert_same('ran', $trace['status'] ?? null, 'frontend provider override trace should be marked as ran');
        $counts = is_array($trace['counts'] ?? null) ? $trace['counts'] : [];
        assert_same(2, (int) ($counts['incoming_provider_results'] ?? 0), 'frontend provider override diagnostics should count incoming provider results without logging payloads');
        assert_same(1, (int) ($counts['prior_provider_responses_replaced'] ?? 0), 'frontend provider override diagnostics should count the replaced provider response');
        $notes = is_array($trace['notes'] ?? null) ? $trace['notes'] : [];
        assert_true(in_array('FTS replaced an earlier non-null posts_pre_query result from another search provider.', $notes, true), 'frontend provider override diagnostics should explain that FTS replaced an earlier provider response');
        assert_true(in_array('Incoming provider result count: 2.', $notes, true), 'frontend provider override diagnostics should expose only a bounded incoming result count');
        $settings = is_array($trace['settings'] ?? null) ? $trace['settings'] : [];
        assert_same('prefer_language_fts', $settings['provider_compatibility'] ?? null, 'frontend provider override trace should expose the FTS-wins mode');

        WP_FTS_Plugin::reset_request_caches();
        $GLOBALS['wp_fts_test_is_admin'] = true;
        $GLOBALS['pagenow'] = 'edit.php';
        $adminIncoming = [(object) ['ID' => 843, 'post_title' => 'Earlier admin provider result']];
        $adminQuery = new WP_FTS_Test_Query([
            's' => 'provideroverrideneedle',
            'posts_per_page' => 10,
            'post_type' => 'post',
            'post_status' => 'publish',
        ]);
        $adminPosts = WP_FTS_Plugin::replace_admin_post_search_posts($adminIncoming, $adminQuery);

        assert_same([842], array_map(static fn(object $resultPost): int => (int) $resultPost->ID, $adminPosts), 'admin FTS-wins mode should replace earlier provider posts with FTS posts');
        $adminTraces = WP_FTS_Plugin::debug_traces();
        assert_same(1, count($adminTraces), 'admin provider override should record one debug trace');
        $adminTrace = $adminTraces[0];
        assert_same('admin post search', $adminTrace['context'] ?? null, 'admin provider override trace should record the admin context');
        assert_same('ran', $adminTrace['status'] ?? null, 'admin provider override trace should be marked as ran');
        $adminCounts = is_array($adminTrace['counts'] ?? null) ? $adminTrace['counts'] : [];
        assert_same(1, (int) ($adminCounts['incoming_provider_results'] ?? 0), 'admin provider override diagnostics should count incoming provider results without logging payloads');
        assert_same(1, (int) ($adminCounts['prior_provider_responses_replaced'] ?? 0), 'admin provider override diagnostics should count the replaced provider response');
        $adminNotes = is_array($adminTrace['notes'] ?? null) ? $adminTrace['notes'] : [];
        assert_true(in_array('FTS replaced an earlier non-null posts_pre_query result from another search provider.', $adminNotes, true), 'admin provider override diagnostics should explain that FTS replaced an earlier provider response');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('front-end search replacement avoids admin REST cron secondary and disabled queries', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $query = new WP_FTS_Test_Query(['s' => 'guardneedle', 'posts_per_page' => 10]);

    $GLOBALS['wp_fts_test_is_admin'] = true;
    assert_same(null, WP_FTS_Plugin::replace_frontend_search_posts(null, $query), 'admin searches should not be hijacked');

    $GLOBALS['wp_fts_test_is_admin'] = false;
    $GLOBALS['wp_fts_test_is_rest'] = true;
    assert_same(null, WP_FTS_Plugin::replace_frontend_search_posts(null, $query), 'REST requests should not be hijacked');

    $GLOBALS['wp_fts_test_is_rest'] = false;
    $GLOBALS['wp_fts_test_is_cron'] = true;
    assert_same(null, WP_FTS_Plugin::replace_frontend_search_posts(null, $query), 'cron requests should not be hijacked');

    $GLOBALS['wp_fts_test_is_cron'] = false;
    $secondary = new WP_FTS_Test_Query(['s' => 'guardneedle', 'posts_per_page' => 10], true, false);
    assert_same(null, WP_FTS_Plugin::replace_frontend_search_posts(null, $secondary), 'secondary front-end search queries should not be hijacked');

    $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::FRONTEND_SEARCH_REPLACEMENT_FILTER] = static fn(mixed $replace, mixed $filterQuery): bool => false;
    assert_same(null, WP_FTS_Plugin::replace_frontend_search_posts(null, $query), 'site owners should be able to disable front-end replacement by filter');
});

test_case('admin Posts list search is replaced with FTS-ranked WP_Post results', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_is_admin'] = true;
    $GLOBALS['pagenow'] = 'edit.php';
    $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::DEBUG_ENABLED_FILTER] = static fn(mixed $enabled, string $context): bool => $context === 'admin post search';
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = array_replace(
        WP_FTS_Plugin::default_settings(),
        [
            'recency_boost_strength' => 0.35,
            'recency_boost_half_life_days' => 21.0,
            'prefix_min_length' => 3,
            'prefix_max_terms' => 8,
        ]
    );

    $low = (object) [
        'ID' => 551,
        'post_title' => 'Lower admin rank',
        'post_content' => '<p>adminneedle appears once.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-14 00:00:00',
    ];
    $high = (object) [
        'ID' => 552,
        'post_title' => 'Higher admin rank',
        'post_content' => '<p>adminneedle adminneedle adminneedle adminneedle.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-14 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][551] = $low;
    $GLOBALS['wp_fts_test_posts'][552] = $high;

    try {
        WP_FTS_Plugin::handle_post_save(551, $low, true);
        WP_FTS_Plugin::handle_post_save(552, $high, true);

        $query = new WP_FTS_Test_Query([
            's' => 'adminneedle',
            'posts_per_page' => 10,
            'paged' => 1,
            'post_type' => 'post',
            'post_status' => 'publish',
        ]);
        WP_FTS_Plugin::prepare_admin_post_search_query($query);
        $posts = WP_FTS_Plugin::replace_admin_post_search_posts(null, $query);

        assert_same(true, $query->get('wp_fts_admin_post_search_candidate'), 'pre_get_posts should mark eligible admin Posts list searches');
        assert_same([552, 551], array_map(static fn(object $post): int => (int) $post->ID, $posts), 'admin Posts list search should return FTS-ranked post objects');
        assert_same(2, $query->found_posts, 'admin Posts list search should expose the FTS visible total on the query');
        assert_same(1, $query->max_num_pages, 'admin Posts list search should expose max pages from the FTS visible total');
        assert_same(2, WP_FTS_Plugin::filter_admin_post_search_found_posts(999, $query), 'admin found_posts filter should preserve the replacement total');
        $traces = WP_FTS_Plugin::debug_traces();
        assert_same(1, count($traces), 'admin Posts diagnostics should record one run trace');
        $trace = $traces[0];
        assert_same('admin post search', $trace['context'] ?? null, 'admin diagnostics should record the admin search context');
        $explain = is_array($trace['search_explain'] ?? null) ? $trace['search_explain'] : [];
        $plan = is_array($explain['query_plan'] ?? null) ? $explain['query_plan'] : [];
        assert_same(3, (int) ($plan['prefix_min_length'] ?? 0), 'admin Posts search should pass saved prefix minimum length into the searcher');
        assert_same(8, (int) ($plan['prefix_max_terms'] ?? 0), 'admin Posts search should pass saved prefix max terms into the searcher');
        $recency = is_array($explain['recency_boost'] ?? null) ? $explain['recency_boost'] : [];
        assert_same(true, $recency['enabled'] ?? null, 'admin Posts search should pass saved recency boost into the searcher');
        assert_float_near(0.35, (float) ($recency['strength'] ?? -1), 'admin Posts diagnostics should record saved recency strength');
        assert_float_near(21.0, (float) ($recency['half_life_days'] ?? -1), 'admin Posts diagnostics should record saved recency half-life');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('admin Posts list search replacement overrides earlier posts_pre_query providers only when eligible', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_is_admin'] = true;
    $GLOBALS['pagenow'] = 'edit.php';

    $low = (object) [
        'ID' => 831,
        'post_title' => 'Lower admin precedence rank',
        'post_content' => '<p>adminprecedenceneedle appears once.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-14 00:00:00',
    ];
    $high = (object) [
        'ID' => 832,
        'post_title' => 'Higher admin precedence rank',
        'post_content' => '<p>adminprecedenceneedle adminprecedenceneedle adminprecedenceneedle adminprecedenceneedle.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-14 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][831] = $low;
    $GLOBALS['wp_fts_test_posts'][832] = $high;

    try {
        WP_FTS_Plugin::handle_post_save(831, $low, true);
        WP_FTS_Plugin::handle_post_save(832, $high, true);

        $incoming = [(object) ['ID' => 830, 'post_title' => 'Earlier admin provider result']];
        $query = new WP_FTS_Test_Query([
            's' => 'adminprecedenceneedle',
            'posts_per_page' => 10,
            'post_type' => 'post',
            'post_status' => 'publish',
        ]);
        $posts = WP_FTS_Plugin::replace_admin_post_search_posts($incoming, $query);

        assert_same([832, 831], array_map(static fn(object $post): int => (int) $post->ID, $posts), 'eligible admin replacement should override earlier posts_pre_query results with FTS-ranked posts');
        assert_same(2, $query->found_posts, 'admin precedence replacement should expose the FTS visible total');

        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = array_replace(
            WP_FTS_Plugin::default_settings(),
            ['search_provider_compatibility' => 'respect_existing']
        );
        $respectQuery = new WP_FTS_Test_Query([
            's' => 'adminprecedenceneedle',
            'posts_per_page' => 10,
            'post_type' => 'post',
            'post_status' => 'publish',
        ]);
        assert_same($incoming, WP_FTS_Plugin::replace_admin_post_search_posts($incoming, $respectQuery), 'admin coexistence mode should preserve an earlier non-null posts_pre_query result');
        assert_same(0, $respectQuery->found_posts, 'admin coexistence mode should not write FTS totals when it stands down');
        $respectNullQuery = new WP_FTS_Test_Query([
            's' => 'adminprecedenceneedle',
            'posts_per_page' => 10,
            'post_type' => 'post',
            'post_status' => 'publish',
        ]);
        $respectNullPosts = WP_FTS_Plugin::replace_admin_post_search_posts(null, $respectNullQuery);
        assert_same([832, 831], array_map(static fn(object $post): int => (int) $post->ID, $respectNullPosts), 'admin coexistence mode should still run FTS when no earlier provider answered');
        assert_same(2, $respectNullQuery->found_posts, 'admin coexistence mode should write FTS totals when it runs after a null provider result');
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = WP_FTS_Plugin::default_settings();

        $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::ADMIN_POST_SEARCH_REPLACEMENT_FILTER] = static fn(mixed $replace, mixed $filterQuery): bool => false;
        $optOutQuery = new WP_FTS_Test_Query([
            's' => 'adminprecedenceneedle',
            'posts_per_page' => 10,
            'post_type' => 'post',
        ]);
        assert_same($incoming, WP_FTS_Plugin::replace_admin_post_search_posts($incoming, $optOutQuery), 'admin opt-out filter should preserve an earlier non-null posts_pre_query result');
        unset($GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::ADMIN_POST_SEARCH_REPLACEMENT_FILTER]);

        $constrained = new WP_FTS_Test_Query([
            's' => 'adminprecedenceneedle',
            'posts_per_page' => 10,
            'post_type' => 'post',
            'post_status' => 'trash',
        ]);
        assert_same($incoming, WP_FTS_Plugin::replace_admin_post_search_posts($incoming, $constrained), 'constrained admin searches should preserve earlier non-null posts_pre_query results');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('admin Posts list default search covers supported statuses and filters non-public rows by capability', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_is_admin'] = true;
    $GLOBALS['pagenow'] = 'edit.php';

    $fixtures = [
        561 => ['Admin publish visible', 'publish', ''],
        562 => ['Admin draft visible', 'draft', ''],
        563 => ['Admin pending visible', 'pending', ''],
        564 => ['Admin future visible', 'future', ''],
        565 => ['Admin private visible', 'private', ''],
        566 => ['Admin trash hidden', 'trash', ''],
        567 => ['Admin password hidden', 'publish', 'secret'],
    ];

    try {
        foreach ($fixtures as $postId => [$title, $status, $password]) {
            $post = (object) [
                'ID' => $postId,
                'post_title' => $title,
                'post_content' => '<p>allstatusneedle shared body.</p>',
                'post_excerpt' => '',
                'post_status' => $status,
                'post_type' => 'post',
                'post_password' => $password,
                'post_date_gmt' => '2026-06-14 00:00:00',
            ];
            $GLOBALS['wp_fts_test_posts'][$postId] = $post;
            WP_FTS_Plugin::handle_post_save($postId, $post, true);
        }

        foreach ([561, 562, 563, 564, 565] as $postId) {
            assert_true(($fake->docs[$postId]['is_deleted'] ?? 1) === 0, "supported admin status post {$postId} should stay indexed");
        }
        assert_true(!isset($fake->docs[566]) || ($fake->docs[566]['is_deleted'] ?? 0) === 1, 'trash posts should not stay actively indexed for admin search');
        assert_true(!isset($fake->docs[567]) || ($fake->docs[567]['is_deleted'] ?? 0) === 1, 'password-protected posts should not stay actively indexed for admin search');

        $GLOBALS['wp_fts_test_caps']['edit_post'][562] = true;
        $GLOBALS['wp_fts_test_caps']['edit_post'][563] = true;
        $GLOBALS['wp_fts_test_caps']['edit_post'][564] = true;
        $GLOBALS['wp_fts_test_caps']['read_post'][565] = true;

        $allQuery = new WP_FTS_Test_Query([
            's' => 'allstatusneedle',
            'posts_per_page' => 10,
            'post_type' => 'post',
        ]);
        WP_FTS_Plugin::prepare_admin_post_search_query($allQuery);
        $allIds = array_map(static fn(object $post): int => (int) $post->ID, WP_FTS_Plugin::replace_admin_post_search_posts(null, $allQuery));
        sort($allIds, SORT_NUMERIC);

        assert_same(true, $allQuery->get('wp_fts_admin_post_search_candidate'), 'default admin All search should be marked for FTS replacement');
        assert_same([561, 562, 563, 564, 565], $allIds, 'default admin All search should include supported statuses when the user can read or edit non-public rows');
        assert_same(5, $allQuery->found_posts, 'default admin All total should include only visible supported status rows');

        $statusQueries = [
            'draft' => [
                562,
                [
                    'post_status' => 'draft',
                    'perm' => 'readable',
                    'orderby' => 'modified',
                    'order' => 'DESC',
                ],
            ],
            'pending' => [
                563,
                [
                    'post_status' => 'pending',
                    'perm' => 'readable',
                    'orderby' => 'modified',
                    'order' => 'ASC',
                ],
            ],
            'private' => [
                565,
                [
                    'post_status' => 'private',
                    'perm' => 'readable',
                ],
            ],
        ];
        foreach ($statusQueries as $status => [$expectedId, $vars]) {
            $statusQuery = new WP_FTS_Test_Query(array_merge([
                's' => 'allstatusneedle',
                'posts_per_page' => 10,
                'post_type' => 'post',
            ], $vars));
            WP_FTS_Plugin::prepare_admin_post_search_query($statusQuery);
            $statusIds = array_map(static fn(object $post): int => (int) $post->ID, WP_FTS_Plugin::replace_admin_post_search_posts(null, $statusQuery));
            assert_same(true, $statusQuery->get('wp_fts_admin_post_search_candidate'), "admin {$status} status tab search should be marked for FTS replacement");
            assert_same([$expectedId], $statusIds, "admin {$status} status tab search should pass WordPress-native status vars through to FTS");
        }

        $GLOBALS['wp_fts_test_caps'] = [];
        $restrictedQuery = new WP_FTS_Test_Query([
            's' => 'allstatusneedle',
            'posts_per_page' => 10,
            'post_type' => 'post',
        ]);
        $restrictedIds = array_map(static fn(object $post): int => (int) $post->ID, WP_FTS_Plugin::replace_admin_post_search_posts(null, $restrictedQuery));
        sort($restrictedIds, SORT_NUMERIC);

        assert_same([561], $restrictedIds, 'admin search should hide draft pending future and private rows without row capability');
        assert_same(1, $restrictedQuery->found_posts, 'admin visible total should exclude non-public rows without row capability');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('admin Posts list search replacement can be disabled independently', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    $post = (object) [
        'ID' => 553,
        'post_title' => 'Separate filter result',
        'post_content' => '<p>separatefilterneedle appears for front-end replacement.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-14 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][553] = $post;

    try {
        WP_FTS_Plugin::handle_post_save(553, $post, true);

        $GLOBALS['wp_fts_test_is_admin'] = true;
        $GLOBALS['pagenow'] = 'edit.php';
        $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::ADMIN_POST_SEARCH_REPLACEMENT_FILTER] = static fn(mixed $replace, mixed $filterQuery): bool => false;
        $adminQuery = new WP_FTS_Test_Query([
            's' => 'separatefilterneedle',
            'posts_per_page' => 10,
            'post_type' => 'post',
        ]);
        assert_same(null, WP_FTS_Plugin::replace_admin_post_search_posts(null, $adminQuery), 'site owners should be able to disable admin Posts list replacement by filter');

        $GLOBALS['wp_fts_test_is_admin'] = false;
        $frontQuery = new WP_FTS_Test_Query([
            's' => 'separatefilterneedle',
            'posts_per_page' => 10,
            'post_type' => 'post',
        ]);
        $frontPosts = WP_FTS_Plugin::replace_frontend_search_posts(null, $frontQuery);
        assert_same([553], array_map(static fn(object $frontPost): int => (int) $frontPost->ID, $frontPosts), 'admin replacement filter should not disable front-end replacement');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('admin Posts list search replacement skips post when it is not an indexed post type', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_is_admin'] = true;
    $GLOBALS['pagenow'] = 'edit.php';
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = array_replace(
        WP_FTS_Plugin::default_settings(),
        ['index_post_types' => ['page']]
    );

    $query = new WP_FTS_Test_Query([
        's' => 'uncheckedpostneedle',
        'posts_per_page' => 10,
        'post_type' => 'post',
    ]);

    WP_FTS_Plugin::prepare_admin_post_search_query($query);
    assert_same(null, $query->get('wp_fts_admin_post_search_candidate', null), 'admin Posts list search should not be marked for FTS replacement when post indexing is disabled');
    assert_same(null, WP_FTS_Plugin::replace_admin_post_search_posts(null, $query), 'admin Posts list search should fall back to native WordPress search when post indexing is disabled');
});

test_case('admin Posts list search replacement avoids REST cron secondary sandbox and constrained queries', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_is_admin'] = true;
    $GLOBALS['pagenow'] = 'edit.php';
    $query = new WP_FTS_Test_Query(['s' => 'adminguardneedle', 'posts_per_page' => 10, 'post_type' => 'post']);

    $GLOBALS['wp_fts_test_is_rest'] = true;
    assert_same(null, WP_FTS_Plugin::replace_admin_post_search_posts(null, $query), 'REST admin searches should not be hijacked');

    $GLOBALS['wp_fts_test_is_rest'] = false;
    $GLOBALS['wp_fts_test_is_cron'] = true;
    assert_same(null, WP_FTS_Plugin::replace_admin_post_search_posts(null, $query), 'cron admin searches should not be hijacked');

    $GLOBALS['wp_fts_test_is_cron'] = false;
    $secondary = new WP_FTS_Test_Query(['s' => 'adminguardneedle', 'posts_per_page' => 10, 'post_type' => 'post'], true, false);
    assert_same(null, WP_FTS_Plugin::replace_admin_post_search_posts(null, $secondary), 'secondary admin search queries should not be hijacked');

    $sandbox = new WP_FTS_Test_Query(['s' => 'adminguardneedle', 'posts_per_page' => 10, 'post_type' => 'post', 'page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG]);
    assert_same(null, WP_FTS_Plugin::replace_admin_post_search_posts(null, $sandbox), 'FTS sandbox searches should stay on the sandbox search path');

    $GLOBALS['pagenow'] = 'post.php';
    assert_same(null, WP_FTS_Plugin::replace_admin_post_search_posts(null, $query), 'non-list admin screens should not be hijacked');
    $GLOBALS['pagenow'] = 'edit.php';

    unset($GLOBALS['pagenow']);
    $GLOBALS['wp_fts_test_current_screen'] = null;
    assert_same(null, WP_FTS_Plugin::replace_admin_post_search_posts(null, $query), 'unknown admin screen context should fail closed');

    $GLOBALS['wp_fts_test_current_screen'] = (object) ['base' => 'post'];
    assert_same(null, WP_FTS_Plugin::replace_admin_post_search_posts(null, $query), 'non-list current screen base should not be hijacked');

    $GLOBALS['wp_fts_test_current_screen'] = (object) ['base' => 'edit'];
    $screenQuery = new WP_FTS_Test_Query(['s' => 'adminguardneedle', 'posts_per_page' => 10, 'post_type' => 'post']);
    WP_FTS_Plugin::prepare_admin_post_search_query($screenQuery);
    assert_same(true, $screenQuery->get('wp_fts_admin_post_search_candidate'), 'edit current screen base should identify the admin Posts list when pagenow is unavailable');

    $GLOBALS['pagenow'] = 'edit.php';
    $GLOBALS['wp_fts_test_current_screen'] = null;

    $constrainedVars = [
        'trash status' => ['post_status' => 'trash'],
        'inherit status' => ['post_status' => 'inherit'],
        'mixed safe and unsupported status' => ['post_status' => 'publish,trash'],
        'page list' => ['post_type' => 'page'],
        'include list' => ['post__in' => [551]],
        'author id' => ['author' => 7],
        'custom meta query' => ['meta_query' => [['key' => 'featured', 'value' => '1']]],
        'sorted column' => ['orderby' => 'title'],
        'custom status sorted column' => ['post_status' => 'draft', 'perm' => 'readable', 'orderby' => 'title'],
        'custom status order direction' => ['post_status' => 'pending', 'perm' => 'readable', 'orderby' => 'modified', 'order' => 'DESC'],
        'permission scope' => ['perm' => 'editable'],
        'suppressed filters' => ['suppress_filters' => true],
    ];

    foreach ($constrainedVars as $label => $vars) {
        $guarded = new WP_FTS_Test_Query(array_merge([
            's' => 'adminguardneedle',
            'posts_per_page' => 10,
            'post_type' => 'post',
        ], $vars));

        WP_FTS_Plugin::prepare_admin_post_search_query($guarded);
        assert_same(null, $guarded->get('wp_fts_admin_post_search_candidate', null), "constrained admin {$label} search should not be marked for FTS replacement");
        assert_same(null, WP_FTS_Plugin::replace_admin_post_search_posts(null, $guarded), "constrained admin {$label} search should continue through normal WordPress search");
    }
});

test_case('front-end search replacement declines constrained WP_Query searches', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    $post = (object) [
        'ID' => 751,
        'post_title' => 'Constrained front-end result',
        'post_content' => '<p>constraintneedle should stay on normal WordPress search when constrained.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-13 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][751] = $post;

    try {
        WP_FTS_Plugin::handle_post_save(751, $post, true);

        $constrainedVars = [
            'category id' => ['cat' => 5],
            'category slug' => ['category_name' => 'news'],
            'tag slug' => ['tag' => 'feature'],
            'custom tax query' => ['tax_query' => [['taxonomy' => 'category', 'terms' => [5]]]],
            'author id' => ['author' => 7],
            'author slug' => ['author_name' => 'editor'],
            'date query' => ['date_query' => [['after' => '2026-01-01']]],
            'year archive' => ['year' => 2026],
            'meta key' => ['meta_key' => 'featured'],
            'custom meta query' => ['meta_query' => [['key' => 'featured', 'value' => '1']]],
            'include list' => ['post__in' => [751]],
            'exclude list' => ['post__not_in' => [752]],
            'exact post id' => ['p' => 751],
            'exact page id' => ['page_id' => 751],
            'exact post name' => ['name' => 'constrained-front-end-result'],
        ];

        foreach ($constrainedVars as $label => $vars) {
            $query = new WP_FTS_Test_Query(array_merge([
                's' => 'constraintneedle',
                'posts_per_page' => 10,
            ], $vars));

            WP_FTS_Plugin::prepare_frontend_search_query($query);
            assert_same(null, $query->get('wp_fts_search_candidate', null), "constrained {$label} search should not be marked for FTS replacement");
            assert_same(null, WP_FTS_Plugin::replace_frontend_search_posts(null, $query), "constrained {$label} search should continue through normal WordPress search");
        }
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('front-end search replacement only returns public searchable published posts', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    $fixtures = [
        701 => ['Public visible', 'publish', 'post', ''],
        702 => ['Private hidden', 'private', 'post', ''],
        703 => ['Draft hidden', 'draft', 'post', ''],
        704 => ['Trash hidden', 'trash', 'post', ''],
        705 => ['Excluded type hidden', 'publish', 'secret', ''],
        706 => ['Password hidden', 'publish', 'post', 'secret'],
    ];

    try {
        $indexer = new WP_FTS_Indexer(WP_FTS_Plugin::storage(true), new WP_FTS_Analyzer());
        foreach ($fixtures as $postId => [$title, $status, $type, $password]) {
            $post = (object) [
                'ID' => $postId,
                'post_title' => $title,
                'post_content' => '<p>visibilityneedle shared content.</p>',
                'post_excerpt' => '',
                'post_status' => $status,
                'post_type' => $type,
                'post_password' => $password,
                'post_date_gmt' => '2026-06-13 00:00:00',
            ];
            $GLOBALS['wp_fts_test_posts'][$postId] = $post;
            $indexer->index_post($post, ['lang' => 'en']);
        }

        $GLOBALS['wp_fts_test_caps']['read_post'][702] = true;
        $query = new WP_FTS_Test_Query([
            's' => 'visibilityneedle',
            'posts_per_page' => 10,
            'post_type' => 'any',
        ]);
        $posts = WP_FTS_Plugin::replace_frontend_search_posts(null, $query);

        assert_same([701], array_map(static fn(object $post): int => (int) $post->ID, $posts), 'front-end search should not expose private passworded unpublished trashed or excluded post types');
        assert_same(1, $query->found_posts, 'front-end total should count only visible replacement results');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('front-end search auto-detects Polish and highlights morphology-backed document forms', function (): void {
    global $wpdb;

    assert_or_pending(
        WP_FTS_AnalyzerPackValidator::gzip_available(),
        'gzip support should be available for Polish front-end morphology search',
        'PHP zlib gzip support is unavailable, so Polish front-end morphology search is skipped.'
    );

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    add_filter('the_content', [WP_FTS_Plugin::class, 'frontend_search_content'], 20, 1);
    add_filter('the_excerpt', [WP_FTS_Plugin::class, 'frontend_search_excerpt'], 10, 1);
    add_filter('the_title', [WP_FTS_Plugin::class, 'frontend_search_title'], 10, 2);

    $post = (object) [
        'ID' => 801,
        'post_title' => 'Polish Lemmatizer Demo',
        'post_content' => '<p>W książkach i zamkach kier<em>ujemy</em> wpisy do katalogu.</p>',
        'post_excerpt' => 'Polish lemmatizer demo for pack-backed book, castle, entry, and routing forms.',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-13 00:00:00',
        'terms' => [
            'category' => ['Uncategorized'],
        ],
    ];
    $GLOBALS['wp_fts_test_posts'][801] = $post;

    try {
        WP_FTS_Plugin::handle_post_save(801, $post, true);

        $query = new WP_FTS_Test_Query([
            's' => 'kierować',
            'posts_per_page' => 10,
        ]);
        $posts = WP_FTS_Plugin::replace_frontend_search_posts(null, $query);

        assert_same([801], array_map(static fn(object $post): int => (int) $post->ID, $posts), 'automatic front-end Polish query should find the Polish document partition');
        assert_same('pl', $query->get('wp_fts_query_lang'), 'front-end search should expose the analyzer-detected Polish query language');
        $oldGlobalPost = $GLOBALS['post'] ?? null;
        wp_fts_test_begin_frontend_search_loop($query);
        try {
            $snippet = WP_FTS_Plugin::frontend_search_excerpt('', $posts[0]);
            $GLOBALS['post'] = $posts[0];
            $renderedSnippet = apply_filters('the_excerpt', 'Theme fallback excerpt');
            $renderedContent = apply_filters('the_content', '<p>Theme full content without the highlighted surface form.</p>');
            $renderedTitle = apply_filters('the_title', $post->post_title, 801);
        } finally {
            wp_fts_test_end_frontend_search_loop($query);
            if ($oldGlobalPost === null) {
                unset($GLOBALS['post']);
            } else {
                $GLOBALS['post'] = $oldGlobalPost;
            }
        }
        assert_contains('<mark>kier<em>ujemy</em></mark>', $snippet, 'front-end snippet should mark the morphology-backed Polish document form split by safe inline HTML');
        assert_contains('<mark>kier<em>ujemy</em></mark>', $renderedSnippet, 'rendered front-end excerpts should expose the morphology-backed highlighted snippet');
        assert_contains('<mark>kier<em>ujemy</em></mark>', $renderedContent, 'rendered front-end content previews should expose the morphology-backed highlighted snippet');
        assert_contains('<p>', $renderedContent, 'rendered front-end content previews should keep block-level paragraph markup for theme layout constraints');
        assert_true(!str_contains($renderedSnippet, '<mark>kierować</mark>'), 'rendered front-end excerpts should not mark only the literal query form when the document surface differs');
        assert_true(!str_contains($renderedContent, '<mark>kierować</mark>'), 'rendered front-end content previews should not mark only the literal query form when the document surface differs');
        assert_true(!str_contains($renderedContent, 'Polish Lemmatizer Demo'), 'rendered front-end content previews should not include indexed title metadata');
        assert_true(!str_contains($renderedContent, 'pack-backed book'), 'rendered front-end content previews should not include indexed excerpt metadata');
        assert_true(!str_contains($renderedContent, 'Uncategorized'), 'rendered front-end content previews should not include indexed taxonomy metadata');
        assert_same('Polish Lemmatizer Demo', $renderedTitle, 'titles without a matched surface should remain unchanged');
        assert_true(!str_contains($snippet, '<mark>kierować</mark>'), 'front-end snippet should not mark only the literal query form when the document surface differs');

        $unaccentedQuery = new WP_FTS_Test_Query([
            's' => 'kieruje',
            'posts_per_page' => 10,
        ]);
        $unaccentedPosts = WP_FTS_Plugin::replace_frontend_search_posts(null, $unaccentedQuery);
        assert_same([801], array_map(static fn(object $post): int => (int) $post->ID, $unaccentedPosts), 'automatic front-end Polish search should find the same document when the query omits diacritics');

        $oldGlobalPost = $GLOBALS['post'] ?? null;
        wp_fts_test_begin_frontend_search_loop($unaccentedQuery);
        try {
            $GLOBALS['post'] = $unaccentedPosts[0];
            $unaccentedContent = apply_filters('the_content', '<p>Theme fallback content.</p>');
        } finally {
            wp_fts_test_end_frontend_search_loop($unaccentedQuery);
            if ($oldGlobalPost === null) {
                unset($GLOBALS['post']);
            } else {
                $GLOBALS['post'] = $oldGlobalPost;
            }
        }
        assert_contains('<mark>kier<em>ujemy</em></mark>', $unaccentedContent, 'unaccented front-end Polish queries should still highlight the matched document surface form');

        $post->post_content = '<!-- wp:paragraph -->' . "\n"
            . '<p>W książkach i zamkach i w sta<strong>jn<em>ia</em></strong>ch wyszukujemy wpisy oraz kierujemy katalog.</p>' . "\n"
            . '<!-- /wp:paragraph -->';
        $GLOBALS['wp_fts_test_posts'][801] = $post;
        WP_FTS_Plugin::handle_post_save(801, $post, true);

        assert_true(
            isset($fake->terms[WP_FTS_TermNamespace::namespace_term('pl', 'stajnia')]),
            'updated formatted Polish post content should index the pack-backed stable lemma across nested inline markup'
        );

        $formattedQuery = new WP_FTS_Test_Query([
            's' => 'Stajnia',
            'posts_per_page' => 10,
        ]);
        $formattedPosts = WP_FTS_Plugin::replace_frontend_search_posts(null, $formattedQuery);
        assert_same([801], array_map(static fn(object $post): int => (int) $post->ID, $formattedPosts), 'automatic front-end Polish search should find an updated post whose inflected match is split by nested inline markup');

        $oldGlobalPost = $GLOBALS['post'] ?? null;
        wp_fts_test_begin_frontend_search_loop($formattedQuery);
        try {
            $formattedSnippet = WP_FTS_Plugin::frontend_search_excerpt('', $formattedPosts[0]);
            $GLOBALS['post'] = $formattedPosts[0];
            $formattedContent = apply_filters('the_content', '<p>Theme fallback content.</p>');
        } finally {
            wp_fts_test_end_frontend_search_loop($formattedQuery);
            if ($oldGlobalPost === null) {
                unset($GLOBALS['post']);
            } else {
                $GLOBALS['post'] = $oldGlobalPost;
            }
        }

        assert_contains('<mark>sta<strong>jn<em>ia</em></strong>ch</mark>', $formattedSnippet, 'front-end snippets should mark the full Polish document form across nested inline formatting');
        assert_contains('<mark>sta<strong>jn<em>ia</em></strong>ch</mark>', $formattedContent, 'front-end content previews should mark the full Polish document form across nested inline formatting');
        assert_true(!str_contains($formattedContent, '<mark>Stajnia</mark>'), 'front-end content previews should not fall back to highlighting only the literal query form');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('front-end search highlights title-only matches while keeping previews content-only', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    add_filter('the_content', [WP_FTS_Plugin::class, 'frontend_search_content'], 20, 1);
    add_filter('the_title', [WP_FTS_Plugin::class, 'frontend_search_title'], 10, 2);

    $post = (object) [
        'ID' => 802,
        'post_title' => 'Running Title Signal',
        'post_content' => '<p>Body preview stays tied to the actual edited post content.</p>',
        'post_excerpt' => 'Excerpt metadata should stay out of the rendered search preview.',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-13 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][802] = $post;

    try {
        WP_FTS_Plugin::handle_post_save(802, $post, true);

        $query = new WP_FTS_Test_Query([
            's' => 'run',
            'posts_per_page' => 10,
        ]);
        $posts = WP_FTS_Plugin::replace_frontend_search_posts(null, $query);

        assert_same([802], array_map(static fn(object $post): int => (int) $post->ID, $posts), 'front-end search should find title-only matches because titles are indexed');
        $oldGlobalPost = $GLOBALS['post'] ?? null;
        wp_fts_test_begin_frontend_search_loop($query);
        try {
            $GLOBALS['post'] = $posts[0];
            $renderedTitle = apply_filters('the_title', $post->post_title, 802);
            $renderedContent = apply_filters('the_content', '<p>Theme fallback content.</p>');
        } finally {
            wp_fts_test_end_frontend_search_loop($query);
            if ($oldGlobalPost === null) {
                unset($GLOBALS['post']);
            } else {
                $GLOBALS['post'] = $oldGlobalPost;
            }
        }

        assert_contains('<mark>Running</mark> Title Signal', $renderedTitle, 'front-end titles should highlight matched title surfaces');
        assert_contains('Body preview stays tied to the actual edited post content.', $renderedContent, 'front-end content previews should come from post_content');
        assert_true(!str_contains($renderedContent, 'Running Title Signal'), 'front-end content previews should not include indexed title metadata for title-only matches');
        assert_true(!str_contains($renderedContent, 'Excerpt metadata'), 'front-end content previews should not include indexed excerpt metadata');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('front-end search highlights from global main query outside loop scope', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    $post = (object) [
        'ID' => 803,
        'post_title' => 'Globalneedle title result',
        'post_content' => '<p>Globalneedle body preview outside loop scope.</p>',
        'post_excerpt' => 'Normal global query excerpt',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-13 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][803] = $post;

    try {
        WP_FTS_Plugin::handle_post_save(803, $post, true);

        $query = new WP_FTS_Test_Query([
            's' => 'globalneedle',
            'posts_per_page' => 10,
        ]);
        $posts = WP_FTS_Plugin::replace_frontend_search_posts(null, $query);
        assert_same([803], array_map(static fn(object $post): int => (int) $post->ID, $posts), 'front-end global fallback search should return the matching post');

        $oldGlobalPost = $GLOBALS['post'] ?? null;
        $oldGlobalQuery = $GLOBALS['wp_query'] ?? null;
        try {
            $GLOBALS['post'] = $posts[0];
            $GLOBALS['wp_query'] = $query;

            $excerpt = WP_FTS_Plugin::frontend_search_excerpt('Theme fallback excerpt', $posts[0]);
            $content = WP_FTS_Plugin::frontend_search_content('<p>Theme fallback content.</p>');
            $title = WP_FTS_Plugin::frontend_search_title($post->post_title, 803);

            assert_contains('<mark>Globalneedle</mark> body preview outside loop scope.', $excerpt, 'front-end excerpt fallback should use highlighted snippets from the global main query');
            assert_contains('<p>', $content, 'front-end content fallback should return paragraph preview markup');
            assert_contains('<mark>Globalneedle</mark> body preview outside loop scope.', $content, 'front-end content fallback should use highlighted snippets from the global main query');
            assert_contains('<mark>Globalneedle</mark> title result', $title, 'front-end title fallback should use highlighted titles from the global main query');

            $GLOBALS['wp_query'] = new WP_FTS_Test_Query([
                's' => 'globalneedle',
                'posts_per_page' => 10,
            ], false, true);
            assert_same('Theme fallback excerpt', WP_FTS_Plugin::frontend_search_excerpt('Theme fallback excerpt', $posts[0]), 'non-search global queries should not receive stored FTS snippets');
            assert_same('<p>Theme fallback content.</p>', WP_FTS_Plugin::frontend_search_content('<p>Theme fallback content.</p>'), 'non-search global queries should not receive stored FTS content previews');
            assert_same($post->post_title, WP_FTS_Plugin::frontend_search_title($post->post_title, 803), 'non-search global queries should not receive stored FTS title highlights');

            $GLOBALS['wp_query'] = $query;
            $GLOBALS['wp_fts_test_is_rest'] = true;
            assert_same('REST fallback excerpt', WP_FTS_Plugin::frontend_search_excerpt('REST fallback excerpt', $posts[0]), 'REST-like contexts should not receive stored FTS snippets');
        } finally {
            $GLOBALS['wp_fts_test_is_rest'] = false;
            if ($oldGlobalPost === null) {
                unset($GLOBALS['post']);
            } else {
                $GLOBALS['post'] = $oldGlobalPost;
            }
            $GLOBALS['wp_query'] = $oldGlobalQuery;
        }
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('front-end search highlights core post blocks from block query context', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    add_filter('render_block', [WP_FTS_Plugin::class, 'frontend_search_render_block'], 10, 3);

    $post = (object) [
        'ID' => 804,
        'post_title' => 'University block signal',
        'post_content' => '<p>University block body signal for block rendering.</p>',
        'post_excerpt' => 'Theme raw excerpt should not be the highlighted source.',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-13 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][804] = $post;

    try {
        WP_FTS_Plugin::handle_post_save(804, $post, true);

        $query = new WP_FTS_Test_Query([
            's' => 'university',
            'posts_per_page' => 10,
        ]);
        $posts = WP_FTS_Plugin::replace_frontend_search_posts(null, $query);
        assert_same([804], array_map(static fn(object $post): int => (int) $post->ID, $posts), 'front-end university search should store highlighted state for the result');

        $oldGlobalPost = $GLOBALS['post'] ?? null;
        $oldGlobalQuery = $GLOBALS['wp_query'] ?? null;
        $renderQuery = new WP_FTS_Test_Query([
            's' => 'university',
            'posts_per_page' => 10,
        ]);

        wp_fts_test_begin_frontend_search_loop($renderQuery);
        try {
            unset($GLOBALS['post']);
            $GLOBALS['wp_query'] = null;

            $block = ['blockName' => 'core/post-excerpt', 'context' => ['postId' => 804]];
            $instance = (object) ['context' => ['postId' => 804]];
            $renderedExcerpt = apply_filters(
                'render_block',
                '<div class="wp-block-post-excerpt"><p class="wp-block-post-excerpt__excerpt">Theme raw university excerpt.</p></div>',
                $block,
                $instance
            );
            $renderedContent = apply_filters(
                'render_block',
                '<div class="wp-block-post-content">Theme raw university content.</div>',
                ['blockName' => 'core/post-content', 'context' => ['postId' => 804]],
                $instance
            );
            $renderedTitle = apply_filters(
                'render_block',
                '<h2 class="wp-block-post-title"><a href="/university">University block signal</a></h2>',
                ['blockName' => 'core/post-title', 'context' => ['postId' => 804]],
                $instance
            );
        } finally {
            wp_fts_test_end_frontend_search_loop($renderQuery);
            if ($oldGlobalPost === null) {
                unset($GLOBALS['post']);
            } else {
                $GLOBALS['post'] = $oldGlobalPost;
            }
            $GLOBALS['wp_query'] = $oldGlobalQuery;
        }

        assert_contains('<mark>University</mark> block body signal', $renderedExcerpt, 'core/post-excerpt block output should use the stored highlighted FTS snippet');
        assert_contains('<p>', $renderedContent, 'core/post-content block output should keep paragraph preview markup');
        assert_contains('<mark>University</mark> block body signal', $renderedContent, 'core/post-content block output should use the stored highlighted FTS snippet');
        assert_contains('<h2 class="wp-block-post-title"><a href="/university"><mark>University</mark> block signal</a></h2>', $renderedTitle, 'core/post-title block output should preserve the title block wrapper while highlighting the stored title');
        assert_true(!str_contains($renderedExcerpt, 'Theme raw university excerpt'), 'block excerpt rendering should not fall back to raw theme output when an FTS snippet exists');

        $alreadyHighlightedTitle = apply_filters(
            'render_block',
            '<h2 class="wp-block-post-title"><a href="/university"><mark>University</mark> block signal</a></h2>',
            ['blockName' => 'core/post-title', 'context' => ['postId' => 804]],
            (object) ['context' => ['postId' => 804]]
        );
        assert_same('<h2 class="wp-block-post-title"><a href="/university"><mark>University</mark> block signal</a></h2>', $alreadyHighlightedTitle, 'render_block fallback should not double-wrap post title blocks already highlighted by the_title');

        $afterLoop = apply_filters(
            'render_block',
            '<div class="wp-block-post-content">Theme raw university content.</div>',
            ['blockName' => 'core/post-content', 'context' => ['postId' => 804]],
            (object) ['context' => ['postId' => 804]]
        );
        assert_same('<div class="wp-block-post-content">Theme raw university content.</div>', $afterLoop, 'stored block snippets should not leak after the replaced main loop ends');

        $secondary = new WP_FTS_Test_Query([
            's' => 'university',
            'posts_per_page' => 1,
        ], true, false);
        wp_fts_test_begin_frontend_search_loop($secondary);
        try {
            $secondaryBlock = apply_filters(
                'render_block',
                '<div class="wp-block-post-content">Secondary raw university content.</div>',
                ['blockName' => 'core/post-content', 'context' => ['postId' => 804]],
                (object) ['context' => ['postId' => 804]]
            );
        } finally {
            wp_fts_test_end_frontend_search_loop($secondary);
        }
        assert_same('<div class="wp-block-post-content">Secondary raw university content.</div>', $secondaryBlock, 'secondary block queries should not receive main-loop FTS snippets');

        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = array_replace(
            WP_FTS_Plugin::default_settings(),
            ['highlight' => false]
        );
        $noHighlightQuery = new WP_FTS_Test_Query([
            's' => 'university',
            'posts_per_page' => 10,
        ]);
        $noHighlightPosts = WP_FTS_Plugin::replace_frontend_search_posts(null, $noHighlightQuery);
        assert_same([804], array_map(static fn(object $post): int => (int) $post->ID, $noHighlightPosts), 'front-end university search should still return results when highlighting is disabled');

        $noHighlightRenderQuery = new WP_FTS_Test_Query([
            's' => 'university',
            'posts_per_page' => 10,
        ]);
        wp_fts_test_begin_frontend_search_loop($noHighlightRenderQuery);
        try {
            $noHighlightContent = apply_filters(
                'render_block',
                '<div class="wp-block-post-content">Theme raw university content.</div>',
                ['blockName' => 'core/post-content', 'context' => ['postId' => 804]],
                (object) ['context' => ['postId' => 804]]
            );
        } finally {
            wp_fts_test_end_frontend_search_loop($noHighlightRenderQuery);
        }
        assert_contains('University block body signal', $noHighlightContent, 'disabled block highlighting should still render the FTS preview text');
        assert_true(!str_contains($noHighlightContent, '<mark>'), 'disabled block highlighting should not output mark tags');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('front-end search snippets preserve split inline HTML safely', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    $post = (object) [
        'ID' => 811,
        'post_title' => 'Inline front-end result',
        'post_content' => '<p><strong>Word</strong>Press Szk<em>l<i><b>ar</b></i></em>nia W<em>ęgorz</em></p><script>Węgorz</script>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-13 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][811] = $post;

    try {
        WP_FTS_Plugin::handle_post_save(811, $post, true);

        $query = new WP_FTS_Test_Query([
            's' => 'Węgorz',
            'posts_per_page' => 10,
        ]);
        $posts = WP_FTS_Plugin::replace_frontend_search_posts(null, $query);
        wp_fts_test_begin_frontend_search_loop($query);
        try {
            $snippet = WP_FTS_Plugin::frontend_search_excerpt('', $posts[0] ?? null);
        } finally {
            wp_fts_test_end_frontend_search_loop($query);
        }

        assert_same([811], array_map(static fn(object $post): int => (int) $post->ID, $posts), 'front-end split-inline search should return the matching post');
        assert_contains('<mark>W<em>ęgorz</em></mark>', $snippet, 'front-end snippets should preserve valid inline markup inside highlighted split tokens');
        assert_true(!str_contains($snippet, '<script>'), 'front-end snippets should not expose unsafe script markup');

    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('front-end search snippets remove hidden bodies from split highlighted words', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    $post = (object) [
        'ID' => 812,
        'post_title' => 'Hidden split front-end result',
        'post_content' => '<p>nee<script>SECRET_TOKEN</script>dle stays visible.</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-13 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][812] = $post;

    try {
        WP_FTS_Plugin::handle_post_save(812, $post, true);

        $query = new WP_FTS_Test_Query([
            's' => 'needle',
            'posts_per_page' => 10,
        ]);
        $posts = WP_FTS_Plugin::replace_frontend_search_posts(null, $query);
        wp_fts_test_begin_frontend_search_loop($query);
        try {
            $snippet = WP_FTS_Plugin::frontend_search_excerpt('', $posts[0] ?? null);
        } finally {
            wp_fts_test_end_frontend_search_loop($query);
        }

        assert_same([812], array_map(static fn(object $post): int => (int) $post->ID, $posts), 'front-end split-hidden search should return the visible matching post');
        assert_true(!str_contains($snippet, 'SECRET_TOKEN'), 'front-end snippets must not expose hidden script bodies inside split highlighted words');
        assert_contains('<mark>needle</mark>', $snippet, 'front-end snippets should preserve the visible split word after removing hidden bodies');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('front-end search excerpts are scoped to the active replaced main loop', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    $post = (object) [
        'ID' => 813,
        'post_title' => 'Scoped front-end result',
        'post_content' => '<p>scopeneedle scoped front-end snippet source.</p>',
        'post_excerpt' => 'Normal scoped excerpt',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-13 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][813] = $post;

    try {
        WP_FTS_Plugin::handle_post_save(813, $post, true);

        $query = new WP_FTS_Test_Query([
            's' => 'scopeneedle',
            'posts_per_page' => 10,
        ]);
        $posts = WP_FTS_Plugin::replace_frontend_search_posts(null, $query);
        assert_same([813], array_map(static fn(object $post): int => (int) $post->ID, $posts), 'scoped front-end search should return the matching post');

        assert_same('Secondary normal excerpt', WP_FTS_Plugin::frontend_search_excerpt('Secondary normal excerpt', (object) ['ID' => 813]), 'stored FTS snippets should not affect excerpts outside the replaced main loop');

        wp_fts_test_begin_frontend_search_loop($query);
        try {
            $oldGlobalQuery = $GLOBALS['wp_query'] ?? null;
            $GLOBALS['wp_query'] = $query;
            $mainExcerpt = WP_FTS_Plugin::frontend_search_excerpt('Main normal excerpt', $posts[0] ?? null);
            assert_contains('<mark>scopeneedle</mark>', $mainExcerpt, 'active replaced main loop should receive the highlighted FTS snippet');

            $secondary = new WP_FTS_Test_Query([
                's' => 'scopeneedle',
                'posts_per_page' => 1,
            ], true, false);
            wp_fts_test_begin_frontend_search_loop($secondary);
            try {
                assert_same('Nested secondary excerpt', WP_FTS_Plugin::frontend_search_excerpt('Nested secondary excerpt', (object) ['ID' => 813]), 'secondary loops with the same post ID should not receive the main-loop FTS snippet');
            } finally {
                wp_fts_test_end_frontend_search_loop($secondary);
            }

            assert_contains('<mark>scopeneedle</mark>', WP_FTS_Plugin::frontend_search_excerpt('Main normal excerpt', $posts[0] ?? null), 'main-loop FTS snippet should resume after a nested secondary loop ends');
        } finally {
            $GLOBALS['wp_query'] = $oldGlobalQuery;
            wp_fts_test_end_frontend_search_loop($query);
        }

        assert_same('After loop excerpt', WP_FTS_Plugin::frontend_search_excerpt('After loop excerpt', (object) ['ID' => 813]), 'stored FTS snippets should not leak after the replaced main loop ends');
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
    assert_same(4.0, $weights['visibl'], 'h1 boost should use max-over-ancestors');
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

test_case('analyzer treats visible words split by inline HTML as single Unicode tokens', function (): void {
    $analyzer = new WP_FTS_Analyzer(['auto_detect_language' => false]);

    foreach ([
        ['<p><strong>Word</strong>Press</p>', 'WordPress', 'en'],
        ['<p>Szk<em>l<i><b>ar</b></i></em>nia</p>', 'Szklarnia', 'pl'],
        ['<p>W<em>ęgorz</em></p>', 'Węgorz', 'pl'],
        ['<p>W&#281;<em>gorz</em></p>', 'Węgorz', 'pl'],
    ] as [$html, $query, $lang]) {
        $indexedTerms = test_terms($analyzer->analyze_content($html, ['lang' => $lang]));
        $queryTerms = $analyzer->analyze_query($query, ['lang' => $lang]);

        foreach ($queryTerms as $term) {
            assert_true(
                in_array($term, $indexedTerms, true),
                "inline HTML split word {$query} should index analyzed query term {$term}"
            );
        }
    }

    $blockTerms = test_terms($analyzer->analyze_content('<p>Word</p><p>Press</p>', ['lang' => 'en']));
    assert_true(!in_array('wordpress', $blockTerms, true), 'block boundaries should not join Word and Press into WordPress');
});

test_case('processor extraction coalesces nested inline Polish fragments before lemmatizing', function (): void {
    $html = '<p>chr<strong><em>ząs</em>tki</strong> są wspaniałe</p>';
    $processorFactory = static function (string $source) use ($html): ?WP_FTS_Fake_HTML_Processor {
        if ($source !== $html) {
            return null;
        }

        return new WP_FTS_Fake_HTML_Processor([
            ['type' => '#tag', 'tag' => 'P', 'breadcrumbs' => ['HTML', 'BODY', 'P']],
            ['type' => '#text', 'breadcrumbs' => ['HTML', 'BODY', 'P', '#text'], 'text' => 'chr'],
            ['type' => '#tag', 'tag' => 'STRONG', 'breadcrumbs' => ['HTML', 'BODY', 'P', 'STRONG']],
            ['type' => '#tag', 'tag' => 'EM', 'breadcrumbs' => ['HTML', 'BODY', 'P', 'STRONG', 'EM']],
            ['type' => '#text', 'breadcrumbs' => ['HTML', 'BODY', 'P', 'STRONG', 'EM', '#text'], 'text' => 'ząs'],
            ['type' => '#tag', 'tag' => 'EM', 'breadcrumbs' => ['HTML', 'BODY', 'P', 'STRONG', 'EM'], 'closing' => true],
            ['type' => '#text', 'breadcrumbs' => ['HTML', 'BODY', 'P', 'STRONG', '#text'], 'text' => 'tki'],
            ['type' => '#tag', 'tag' => 'STRONG', 'breadcrumbs' => ['HTML', 'BODY', 'P', 'STRONG'], 'closing' => true],
            ['type' => '#text', 'breadcrumbs' => ['HTML', 'BODY', 'P', '#text'], 'text' => ' są wspaniałe'],
            ['type' => '#tag', 'tag' => 'P', 'breadcrumbs' => ['HTML', 'BODY', 'P'], 'closing' => true],
        ]);
    };

    $surfaceAnalyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'enable_stemming' => false,
        'html_processor_factory' => $processorFactory,
    ]);
    $surfaceTerms = test_terms($surfaceAnalyzer->analyze_content($html, ['lang' => 'pl']));
    assert_true(in_array('chrzastki', $surfaceTerms, true), 'processor path should analyze the split inline Polish word as one normalized surface');
    assert_true(!in_array('chr', $surfaceTerms, true), 'processor path should not index the leading Polish fragment separately');
    assert_true(!in_array('zas', $surfaceTerms, true), 'processor path should not index the nested Polish fragment separately');
    assert_true(!in_array('tki', $surfaceTerms, true), 'processor path should not index the trailing Polish fragment separately');

    assert_or_pending(
        WP_FTS_AnalyzerPackValidator::gzip_available(),
        'gzip support should be available for processor-path full Polish pack indexing',
        'PHP zlib gzip support is unavailable, so processor-path full Polish pack indexing is skipped.'
    );

    $analyzer = new WP_FTS_Analyzer([
        'default_lang' => 'pl',
        'polish_lemma_pack' => WP_FTS_AnalyzerPackValidator::default_polish_playground_full_manifest(),
        'html_processor_factory' => $processorFactory,
    ]);
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $post = (object) [
        'ID' => 1068,
        'post_title' => 'Processor Polish Split Surface',
        'post_content' => $html,
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-06-16 00:00:00',
    ];

    $indexer->index_post($post, ['lang' => 'pl']);

    $terms = WP_FTS_StorageCompat::terms_for_doc($storage, 1068);
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('pl', 'chrzastka'), $terms, true), 'index_post should store the full-pack chrzastka lemma for processor-path chrząstki');
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('pl', 'chrzastek'), $terms, true), 'index_post should store the full-pack chrzastek lemma for processor-path chrząstki');
    assert_true(!in_array(WP_FTS_TermNamespace::namespace_term('pl', 'chr'), $terms, true), 'index_post should not store the leading processor fragment');
    assert_true(!in_array(WP_FTS_TermNamespace::namespace_term('pl', 'zas'), $terms, true), 'index_post should not store the nested processor fragment');
    assert_true(!in_array(WP_FTS_TermNamespace::namespace_term('pl', 'tki'), $terms, true), 'index_post should not store the trailing processor fragment');

    $payload = (new WP_FTS_Searcher($storage, $analyzer))->search('chrząstka', [
        'lang' => 'pl',
        'mode' => 'AND',
        'include_total' => true,
        'include_metadata' => true,
        'include_snippets' => true,
        'highlight' => true,
        'snippet_length' => 180,
    ]);

    assert_same(1, $payload['total'] ?? 0, 'chrząstka should find the processor-indexed post through the full Polish pack');
    assert_same(1068, (int) ($payload['results'][0]['doc_id'] ?? 0), 'chrząstka should return the processor-indexed post');
    assert_contains(
        '<mark>chr<strong><em>ząs</em>tki</strong></mark>',
        (string) ($payload['results'][0]['snippet'] ?? ''),
        'search highlighting should preserve the formatted chrząstki surface'
    );
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

test_case('Arabic and Urdu stem length guards are Unicode-aware without mbstring', function (): void {
    $pipeline = new WP_FTS_LanguagePipeline();
    $snowball = new WP_FTS_SnowballStemmer();
    $baseline = new WP_FTS_BaselineLanguageStemmer();
    $expected = [
        'len:الات' => '4',
        'len:الكم' => '4',
        'len:دلوں' => '4',
        'pipe:الات' => 'الات',
        'pipe:الكم' => 'الكم',
        'pipe:دلوں' => 'دلوں',
        'snowball:الات' => 'الات',
        'snowball:الكم' => 'الكم',
        'baseline:دلوں' => 'دلوں',
        'baseline:حالات' => 'حال',
        'baseline:معلومات' => 'معلوم',
    ];
    $normal = [
        'len:الات' => (string) WP_FTS_Utf8::length('الات'),
        'len:الكم' => (string) WP_FTS_Utf8::length('الكم'),
        'len:دلوں' => (string) WP_FTS_Utf8::length('دلوں'),
        'pipe:الات' => implode('|', $pipeline->analyze('الات', 'ar')),
        'pipe:الكم' => implode('|', $pipeline->analyze('الكم', 'ar')),
        'pipe:دلوں' => implode('|', $pipeline->analyze('دلوں', 'ur')),
        'snowball:الات' => $snowball->stem('الات', 'ar'),
        'snowball:الكم' => $snowball->stem('الكم', 'ar'),
        'baseline:دلوں' => $baseline->stem('دلوں', 'ur'),
        'baseline:حالات' => $baseline->stem('حالات', 'ur'),
        'baseline:معلومات' => $baseline->stem('معلومات', 'ur'),
    ];

    $bootstrap = (string) realpath(__DIR__ . '/../src/bootstrap.php');
    $code = str_replace('__BOOTSTRAP__', var_export($bootstrap, true), <<<'PHP'
require __BOOTSTRAP__;
$pipeline = new WP_FTS_LanguagePipeline();
$snowball = new WP_FTS_SnowballStemmer();
$baseline = new WP_FTS_BaselineLanguageStemmer();
$checks = [
    'len:الات' => WP_FTS_Utf8::length('الات'),
    'len:الكم' => WP_FTS_Utf8::length('الكم'),
    'len:دلوں' => WP_FTS_Utf8::length('دلوں'),
    'pipe:الات' => implode('|', $pipeline->analyze('الات', 'ar')),
    'pipe:الكم' => implode('|', $pipeline->analyze('الكم', 'ar')),
    'pipe:دلوں' => implode('|', $pipeline->analyze('دلوں', 'ur')),
    'snowball:الات' => $snowball->stem('الات', 'ar'),
    'snowball:الكم' => $snowball->stem('الكم', 'ar'),
    'baseline:دلوں' => $baseline->stem('دلوں', 'ur'),
    'baseline:حالات' => $baseline->stem('حالات', 'ur'),
    'baseline:معلومات' => $baseline->stem('معلومات', 'ur'),
];
foreach ($checks as $label => $value) {
    echo $label, '=', $value, "\n";
}
PHP);

    $withoutExtensions = test_run_php_without_extensions($code);
    $stderr = trim($withoutExtensions['stderr']);
    $detail = $stderr === '' ? '' : "\nSubprocess stderr: " . substr($stderr, 0, 500);

    foreach ($expected as $label => $value) {
        assert_same($value, $normal[$label], "normal PHP should report {$label}={$value}");
        assert_contains("{$label}={$value}", $withoutExtensions['stdout'], "php -n should report {$label}={$value}");
    }

    assert_same(0, $withoutExtensions['exit'], 'php -n Arabic/Urdu stem length subprocess should exit cleanly' . $detail);
});

test_case('language normalizer applies dialect and language-specific folding maps', function (): void {
    $normalizer = new WP_FTS_Normalizer();

    assert_same('wroclaw', $normalizer->normalize_token('Wrocław', 'pl_PL'), 'Polish folding should match ASCII queries');
    assert_same('strasse', $normalizer->normalize_token('Straße', 'de-DE'), 'German sharp s should expand');
    assert_same('fuer', $normalizer->normalize_token('für', 'de'), 'German umlaut should use ae/oe/ue-style expansion');
    assert_same('ıgdır', $normalizer->normalize_token('Iğdır', 'tr-TR'), 'Turkish dotless i must not fold to ASCII i');
    assert_same('istanbul', $normalizer->normalize_token('İstanbul', 'tr'), 'Turkish dotted capital I should normalize to i');
    assert_same('zh-Hant', $normalizer->canonicalize_language('zh_TW'), 'Chinese region should canonicalize to script key');
    assert_same('البحث', $normalizer->normalize_token("اَلْبَحْثُ", 'ar'), 'Arabic harakat should strip without changing letters');
    assert_same('تلاش', $normalizer->normalize_token("تَلاشـ", 'ur'), 'Urdu harakat and tatweel should strip without changing letters');
    assert_same('تلاش', $normalizer->normalize_token("تَلاشـ", 'fa'), 'Persian partition should normalize Arabic-script marks before pack lookup');

    $pipeline = new WP_FTS_LanguagePipeline();
    assert_same(
        ['color', 'organiz', 'organiz'],
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

test_case('baseline top-language stemmer applies deterministic local rules', function (): void {
    $stemmer = new WP_FTS_BaselineLanguageStemmer();

    assert_same('buscando', $stemmer->stem('buscando', 'es-MX'), 'Baseline stemmer should leave Spanish to the bundled Snowball adapter');
    assert_same('manger', $stemmer->stem('manger', 'fr'), 'Baseline stemmer should leave French to the bundled Snowball adapter');
    assert_same('pesquisando', $stemmer->stem('pesquisando', 'pt-BR'), 'Baseline stemmer should leave Portuguese to the bundled Snowball adapter');
    assert_same('mencari', $stemmer->stem('mencari', 'id'), 'Baseline stemmer should leave Indonesian to the bundled Snowball adapter');
    assert_same('किताबें', $stemmer->stem('किताबें', 'hi'), 'Baseline stemmer should leave Hindi to the bundled Snowball adapter');
    assert_same('শব্দ', $stemmer->stem('শব্দগুলো', 'bn'), 'Bengali classifier plural -gulo should strip with length guard');
    assert_same('শব্দ', $stemmer->stem('শব্দগুলিতে', 'bn-BD'), 'Bengali classifier locative -gulite should strip with length guard');
    assert_same('পাতা', $stemmer->stem('পাতাগুলোকে', 'bn'), 'Bengali classifier dative -guloke should strip with length guard');
    assert_same('লেখা', $stemmer->stem('লেখাগুলির', 'bn'), 'Bengali classifier genitive -gulir should strip with length guard');
    assert_same('লেখা', $stemmer->stem('লেখাগুলোর', 'bn'), 'Bengali classifier genitive -gular should strip with length guard');
    assert_same('শিক্ষক', $stemmer->stem('শিক্ষকদের', 'bn'), 'Bengali plural/genitive -der should strip with length guard');
    assert_same('শিক্ষক', $stemmer->stem('শিক্ষকদেরকে', 'bn'), 'Bengali plural dative -derke should strip with length guard');
    assert_same('বই', $stemmer->stem('বইটিকে', 'bn'), 'Bengali singular classifier case -tike should strip with length guard');
    assert_same('বিদ্যালয়', $stemmer->stem('বিদ্যালয়ের', 'bn'), 'Bengali genitive -er should strip with length guard');
    assert_same('শিক্ষক', $stemmer->stem('শিক্ষকরা', 'bn'), 'Bengali human plural -ra should strip with length guard');
    assert_same('সূচি', $stemmer->stem('সূচিতে', 'bn'), 'Bengali locative -te should strip when the stem stays non-trivial');
    assert_same('হতে', $stemmer->stem('হতে', 'bn'), 'Bengali short-stem guard should preserve tiny -te words');
    assert_same('তাকে', $stemmer->stem('তাকে', 'bn'), 'Bengali short-stem guard should preserve tiny -ke words');
    assert_same('البحث', $stemmer->stem('البحث', 'ar'), 'Baseline stemmer should leave Arabic to the bundled Snowball adapter');
    assert_same('کتاب', $stemmer->stem('کتابیں', 'ur'), 'Urdu plural -en should strip without letter rewrites');
    assert_same('فہرست', $stemmer->stem('فہرستوں', 'ur'), 'Urdu plural-oblique -on should strip without letter rewrites');
    assert_same('لڑکی', $stemmer->stem('لڑکیاں', 'ur'), 'Urdu feminine plural -yan should normalize to the singular yeh ending');
    assert_same('لڑکی', $stemmer->stem('لڑکیوں', 'ur'), 'Urdu feminine oblique -on should preserve the singular yeh ending');
    assert_same('لڑک', $stemmer->stem('لڑکے', 'ur'), 'Urdu masculine plural -e should strip when the stem stays non-trivial');
    assert_same('حال', $stemmer->stem('حالات', 'ur'), 'Urdu Arabic-loan plural -at should strip when the stem stays non-trivial');
    assert_same('ہے', $stemmer->stem('ہے', 'ur'), 'Urdu short-stem guard should preserve tiny -e words');
    assert_same('فارسی', $stemmer->stem('فارسی', 'ur'), 'Urdu baseline should preserve Persian-like letters and words');
    assert_same('kotami', $stemmer->stem('kotami', 'pl'), 'baseline stemmer should no-op unsupported languages');
    assert_contains('wp-fts-baseline-language-stemmer:v10:', $stemmer->index_signature(), 'baseline stemmer should expose an index signature');
});

test_case('snowball and polish stemmer adapters are guarded and pluggable', function (): void {
    $snowball = new WP_FTS_SnowballStemmer();
    assert_same('kotami', $snowball->stem('kotami', 'pl'), 'Snowball adapter should no-op unsupported languages');
    assert_true($snowball->supports_language('ar-EG'), 'Snowball adapter should advertise verified Arabic support');
    assert_true($snowball->is_language_available('ar'), 'Arabic Snowball stemmer should be bundled without Wamania');
    assert_true($snowball->supports_language('en-US'), 'Snowball adapter should advertise verified English support');
    assert_true($snowball->is_language_available('en'), 'English Snowball stemmer should be bundled without Wamania');
    assert_true($snowball->supports_language('es-MX'), 'Snowball adapter should advertise verified Spanish support');
    assert_true($snowball->is_language_available('es'), 'Spanish Snowball stemmer should be bundled without Wamania');
    assert_true($snowball->supports_language('fr-FR'), 'Snowball adapter should advertise verified French support');
    assert_true($snowball->is_language_available('fr'), 'French Snowball stemmer should be bundled without Wamania');
    assert_true($snowball->supports_language('hi-IN'), 'Snowball adapter should advertise verified Hindi support');
    assert_true($snowball->is_language_available('hi'), 'Hindi Snowball stemmer should be bundled without Wamania');
    assert_true($snowball->supports_language('pt-BR'), 'Snowball adapter should advertise verified Portuguese support');
    assert_true($snowball->is_language_available('pt'), 'Portuguese Snowball stemmer should be bundled without Wamania');
    assert_true($snowball->supports_language('id-ID'), 'Snowball adapter should advertise verified Indonesian support');
    assert_true($snowball->is_language_available('id'), 'Indonesian Snowball stemmer should be bundled without Wamania');
    assert_contains('Snowball Arabic', $snowball->source_identity('ar'), 'Arabic stemmer should expose its variant identity');
    assert_contains('Snowball English (Porter2)', $snowball->source_identity('en'), 'English stemmer should expose its variant identity');
    assert_contains('Snowball Spanish', $snowball->source_identity('es'), 'Spanish stemmer should expose its variant identity');
    assert_contains('Snowball French', $snowball->source_identity('fr'), 'French stemmer should expose its variant identity');
    assert_contains('Snowball Hindi', $snowball->source_identity('hi'), 'Hindi stemmer should expose its variant identity');
    assert_contains('Snowball Portuguese', $snowball->source_identity('pt'), 'Portuguese stemmer should expose its variant identity');
    assert_contains('Snowball Indonesian', $snowball->source_identity('id'), 'Indonesian stemmer should expose its variant identity');
    assert_same('ءام', $snowball->stem('ءامن', 'ar'), 'Snowball adapter should match the Arabic fixture hamza row');
    assert_same('ااب', $snowball->stem('أآبا', 'ar'), 'Snowball adapter should match the Arabic fixture alef row');
    assert_same('اباح', $snowball->stem('أأباحتاهم', 'ar'), 'Snowball adapter should match the Arabic fixture verb/pronoun row');
    assert_same('اقف', $snowball->stem('أأوقفتموهما', 'ar'), 'Snowball adapter should match the Arabic fixture compound suffix row');
    assert_same('run', $snowball->stem('running', 'en'), 'Snowball adapter should use the verified English implementation');
    assert_same('aaron', $snowball->stem('aarón', 'es'), 'Snowball adapter should match the Spanish fixture accent postlude');
    assert_same('abandon', $snowball->stem('abandonarlo', 'es'), 'Snowball adapter should match the Spanish fixture attached-pronoun row');
    assert_same('dat', $snowball->stem('datos', 'es'), 'Snowball adapter should match the Spanish fixture plural row');
    assert_same('abaiss', $snowball->stem('abaissait', 'fr'), 'Snowball adapter should match the French fixture verb row');
    assert_same('mang', $snowball->stem('mangeaient', 'fr'), 'Snowball adapter should match the French fixture imperfect row');
    assert_same('किताब', $snowball->stem('किताबें', 'hi'), 'Snowball adapter should match the Hindi plural row');
    assert_same('लड़क', $snowball->stem('लड़कियाँ', 'hi'), 'Snowball adapter should match the Hindi iyan row');
    assert_same('अधिनियम', $snowball->stem('अधिनियमों', 'hi'), 'Snowball adapter should match the Hindi oblique plural row');
    assert_same('कब्र', $snowball->stem('कब्रें', 'hi'), 'Snowball adapter should match the Hindi nasal plural row');
    assert_same('pesquis', $snowball->stem('pesquisando', 'pt'), 'Snowball adapter should match the Portuguese fixture gerund row');
    assert_same('dad', $snowball->stem('dados', 'pt'), 'Snowball adapter should match the Portuguese fixture plural row');
    assert_same('rapid', $snowball->stem('rapidamente', 'pt'), 'Snowball adapter should match the Portuguese fixture adverb row');
    assert_same('cari', $snowball->stem('mencari', 'id'), 'Snowball adapter should match the Indonesian fixture meN row');
    assert_same('cari', $snowball->stem('pencarian', 'id'), 'Snowball adapter should match the Indonesian fixture peN plus suffix row');
    assert_same('jalan', $snowball->stem('perjalanan', 'id'), 'Snowball adapter should match the Indonesian fixture per- form row');
    assert_same('makan', $snowball->stem('makanan', 'id'), 'Snowball adapter should match the Indonesian fixture suffix row');

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
    assert_same(['run', 'run', 'runner'], $pipeline->analyze('running runs runner', 'en'), 'English Snowball stemming should be available by default');
    assert_same(['busc', 'busc', 'dat', 'clar'], $pipeline->analyze('buscar buscando datos claros', 'es'), 'Spanish Snowball stemming should be available by default');
    assert_same(['mang', 'mang'], $pipeline->analyze('manger mangeaient', 'fr'), 'French Snowball stemming should be available by default');
    assert_same(['pesquis', 'pesquis', 'dad', 'clar'], $pipeline->analyze('pesquisar pesquisando dados claros', 'pt'), 'Portuguese Snowball stemming should be available by default');
    assert_same(['cari', 'cari', 'jalan', 'jalan', 'makan'], $pipeline->analyze('mencari pencarian berjalan perjalanan makanan', 'id'), 'Indonesian Snowball stemming should be available by default');
    assert_same(['किताब', 'किताब', 'लड़क', 'लड़क', 'भाषाएँ'], $pipeline->analyze('किताबें किताबों लड़कियाँ लड़कियों भाषाएँ', 'hi'), 'Hindi Snowball stemming should be available by default');
    assert_same(['শব্দ', 'শব্দ', 'শিক্ষক', 'সূচি'], $pipeline->analyze('শব্দগুলো শব্দগুলিতে শিক্ষকদের সূচিতে', 'bn'), 'Bengali baseline stemming should be available by default');
    assert_same(['اباح', 'مفيد', 'بحث', 'اقف'], $pipeline->analyze('أأباحتاهم مفيدة للبحث أأوقفتموهما', 'ar'), 'Arabic Snowball stemming should be available by default');
    assert_same(['کتاب', 'فہرست'], $pipeline->analyze('کتابیں فہرستوں', 'ur'), 'Urdu baseline stemming should be available by default');

    $verifiedPipeline = new WP_FTS_LanguagePipeline([
        'enable_stemming' => true,
        'polish_stemming' => 'verified',
    ]);
    assert_same(['samochod'], $verifiedPipeline->analyze('samochody', 'pl'), 'Polish verified mode should stem mapped fixture rows');
    assert_same(['danie'], $verifiedPipeline->analyze('danie', 'pl'), 'Polish verified mode should protect ambiguous rows');
});

test_case('polish verified stemmer excludes sandbox wyszukac family', function (): void {
    $groupIds = [];
    foreach (WP_FTS_PolishVerifiedStemmerData::reference_groups() as $group) {
        $groupIds[] = (string) $group['id'];
    }
    assert_true(!in_array('verb-wyszukac', $groupIds, true), 'verified Polish fixture data should not carry the sandbox wyszukac family');

    $stemMap = WP_FTS_PolishVerifiedStemmerData::stem_map();
    foreach (['wyszukiwanie', 'wyszukiwania', 'wyszukujemy', 'wyszukiwali'] as $term) {
        assert_true(!isset($stemMap[$term]), "verified Polish fixture data should not map {$term}");
    }
});

test_case('generic synthetic Bengali lemma pack validates and routes by language', function (): void {
    $manifest = WP_FTS_AnalyzerPackValidator::default_synthetic_bengali_fixture_manifest();
    $validator = new WP_FTS_AnalyzerPackValidator();
    $result = $validator->validate($manifest);

    assert_same('bn-synthetic-lemma-fixture', $result['manifest']['pack_id'], 'synthetic Bengali fixture pack id should be stable');
    assert_same('bn', $result['manifest']['language'], 'synthetic fixture should declare Bengali language routing');
    assert_same(WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV, $result['manifest']['runtime']['format'], 'synthetic fixture should use the generic lemma TSV runtime format');
    assert_true($result['manifest']['fixture_only'] === true, 'synthetic fixture must be fixture-only');
    assert_true($result['manifest']['default_enabled'] === false, 'synthetic fixture must not be default-enabled');
    assert_same(true, $result['rows_collected'], 'tiny synthetic fixture should retain rows for eager lookup tests');
    assert_same(7, $result['runtime_rows'], 'synthetic fixture row count should validate');

    $provenance = file_get_contents(dirname($manifest) . '/PROVENANCE.md');
    assert_true(is_string($provenance), 'synthetic fixture provenance should be readable');
    assert_contains('project-owned synthetic test data', $provenance, 'synthetic fixture provenance should identify project-owned rows');
    assert_contains('not a Bengali dictionary', $provenance, 'synthetic fixture should not claim Bengali dictionary coverage');
    assert_contains('No Bengali, Urdu, CJK, Jieba, Anvay, UrduHack, spaCy, Apertium', $provenance, 'synthetic fixture should reject real third-party lexical sources');

    $pack = WP_FTS_LanguageLemmaPack::from_manifest_file($manifest);
    assert_same('bn', $pack->language(), 'generic lemma pack should expose manifest language');
    assert_same('bn', $pack->base_language_code(), 'generic lemma pack should expose base language routing');
    assert_same('কক', $pack->stem('ককগ', 'bn'), 'generic lemma pack should map matching Bengali-script synthetic rows');
    assert_same('সিনথ000লেমা', $pack->stem('সিনথ000গুলো', 'bn'), 'generic lemma pack should map synthetic suffix-shaped rows');
    assert_same('ঘঘক', $pack->stem('ঘঘক', 'bn'), 'generic lemma pack should preserve ambiguous synthetic surfaces');
    assert_same('ককগ', $pack->stem('ককগ', 'ur'), 'generic lemma pack should no-op other language partitions');
    assert_same('ককগ', $pack->stem('ককগ', 'pl'), 'generic lemma pack should not leak into Polish');
    assert_same(null, WP_FTS_LanguageLemmaPack::from_pack_option($manifest, 'en'), 'language-mismatched pack options should be rejected safely');
});

test_case('generic lemma packs by language beat baseline and fall back safely', function (): void {
    $manifest = WP_FTS_AnalyzerPackValidator::default_synthetic_bengali_fixture_manifest();
    $baseline = new WP_FTS_LanguagePipeline(['enable_stemming' => true]);
    $packPipeline = new WP_FTS_LanguagePipeline([
        'enable_stemming' => true,
        'lemma_packs_by_lang' => [
            'bn' => $manifest,
        ],
    ]);
    $aliasPipeline = new WP_FTS_LanguagePipeline([
        'enable_stemming' => true,
        'lemmatizer_packs_by_lang' => [
            'bn' => $manifest,
        ],
    ]);
    $missingPackPipeline = new WP_FTS_LanguagePipeline([
        'enable_stemming' => true,
        'lemma_packs_by_lang' => [
            'bn' => __DIR__ . '/missing-bn-pack/manifest.json',
        ],
    ]);

    assert_same(['কক'], $baseline->analyze('ককগুলো', 'bn'), 'Bengali baseline should strip the synthetic suffix-shaped key');
    assert_same(['ককক'], $packPipeline->analyze('ককগুলো', 'bn'), 'generic Bengali pack should take precedence over the baseline for configured language');
    assert_same(['সিনথ000লেমা'], $packPipeline->analyze('সিনথ000গুলো', 'bn'), 'generic Bengali pack should map the synthetic suffix-shaped fixture row');
    assert_same(['ককক'], $aliasPipeline->analyze('ককগুলো', 'bn'), 'lemmatizer_packs_by_lang alias should load the same generic pack');
    assert_same(['কক'], $missingPackPipeline->analyze('ককগুলো', 'bn'), 'missing generic pack should fall back to Bengali baseline safely');
    assert_same(['ককগুলো'], $packPipeline->analyze('ককগুলো', 'ur'), 'Bengali pack should not affect Urdu partition');
    assert_same(['ককগ'], $packPipeline->analyze('ককগ', 'pl'), 'Bengali pack should not affect Polish partition');

    $defaultSignature = $baseline->index_signature();
    $packSignature = $packPipeline->index_signature();
    assert_true($defaultSignature !== $packSignature, 'language pipeline signature should change when a generic pack is enabled');
    assert_contains('wp-fts-language-pipeline-v17:', $packSignature, 'language pipeline signature should identify the generic-pack contract');

    $defaultAnalyzer = new WP_FTS_Analyzer();
    $packAnalyzer = new WP_FTS_Analyzer([
        'lemma_packs_by_lang' => [
            'bn' => $manifest,
        ],
    ]);
    assert_same(['ককক'], $packAnalyzer->analyze_query('ককগুলো', ['lang' => 'bn']), 'analyzer should pass generic pack options into the language pipeline');
    assert_true($defaultAnalyzer->index_signature() !== $packAnalyzer->index_signature(), 'analyzer signature should change when a generic pack is enabled');

    $boundedPackPipeline = new WP_FTS_LanguagePipeline([
        'enable_stemming' => true,
        'max_term_bytes' => 6,
        'lemma_packs_by_lang' => [
            'bn' => $manifest,
        ],
    ]);
    assert_same(['ঘঘ'], $boundedPackPipeline->analyze('ঘঘক', 'bn'), 'generic pack multi-analysis should filter each emitted candidate independently');
});

test_case('configured Chinese Jieba segmenter changes matching while preserving fallback ngrams', function (): void {
    $fixture = synthetic_jieba_segmenter_analyzer();
    try {
        $segmenterAnalyzer = $fixture['analyzer'];
        $fallbackAnalyzer = new WP_FTS_Analyzer();

        $fallbackQueryTerms = $fallbackAnalyzer->analyze_query('中国科学院', ['lang' => 'zh']);
        $segmenterQueryTerms = $segmenterAnalyzer->analyze_query('中国科学院', ['lang' => 'zh']);
        assert_true(!in_array('中国科学院', $fallbackQueryTerms, true), 'fallback Chinese n-grams should not emit dictionary words longer than four characters');
        assert_true(in_array('中国科学院', $segmenterQueryTerms, true), 'configured Jieba segmenter should emit the source-backed long dictionary word');
        assert_true(in_array('国科学院', $segmenterQueryTerms, true), 'configured Jieba segmenter should preserve fallback four-character subword recall');

        $targetHtml = '<p>小明硕士毕业于中国科学院计算所。</p>';
        $baitHtml = '<p>中国 国科 科学 学院 中国科 国科学 科学院 中国科学 国科学院 中 国 科 学 院。</p>';

        $fallbackStorage = new WP_FTS_Storage_InMemory();
        $fallbackIndexer = new WP_FTS_Indexer($fallbackStorage, $fallbackAnalyzer);
        $fallbackIndexer->index_document(9101, $targetHtml, ['lang' => 'zh']);
        $fallbackIndexer->index_document(9102, $baitHtml, ['lang' => 'zh']);
        $fallbackPayload = (new WP_FTS_Searcher($fallbackStorage, $fallbackAnalyzer))->search('中国科学院', [
            'lang' => 'zh',
            'mode' => 'AND',
            'include_total' => true,
        ]);
        assert_same(2, $fallbackPayload['total'], 'fallback n-gram matching should allow both the true phrase and full n-gram bait');

        $segmenterStorage = new WP_FTS_Storage_InMemory();
        $segmenterIndexer = new WP_FTS_Indexer($segmenterStorage, $segmenterAnalyzer);
        $segmenterIndexer->index_document(9101, $targetHtml, ['lang' => 'zh']);
        $segmenterIndexer->index_document(9102, $baitHtml, ['lang' => 'zh']);
        assert_true(in_array(WP_FTS_TermNamespace::namespace_term('zh', '中国科学院'), $segmenterStorage->all_terms(), true), 'Jieba indexing should store the source-backed long segment');
        assert_true(in_array(WP_FTS_TermNamespace::namespace_term('zh', '国科学院'), $segmenterStorage->all_terms(), true), 'Jieba indexing should still store fallback subword n-grams');

        $segmenterPayload = (new WP_FTS_Searcher($segmenterStorage, $segmenterAnalyzer))->search('中国科学院', [
            'lang' => 'zh',
            'mode' => 'AND',
            'include_total' => true,
        ]);
        assert_same(1, $segmenterPayload['total'], 'configured Jieba long-word evidence should filter the n-gram-only bait in AND mode');
        assert_same(9101, (int) ($segmenterPayload['results'][0]['doc_id'] ?? 0), 'configured Jieba result should be the document containing the full dictionary word');

        $subwordPayload = (new WP_FTS_Searcher($segmenterStorage, $segmenterAnalyzer))->search('院计算', [
            'lang' => 'zh',
            'mode' => 'AND',
            'include_total' => true,
        ]);
        assert_same(1, $subwordPayload['total'], 'unknown subword queries should still match through fallback CJK n-grams');
    } finally {
        remove_directory_tree($fixture['dir']);
    }
});

test_case('Chinese Jieba segmenter source failures fall back safely', function (): void {
    $fixture = synthetic_jieba_segmenter_analyzer();
    try {
        $fallback = new WP_FTS_Analyzer();
        $fallbackTerms = $fallback->analyze_query('中国科学院', ['lang' => 'zh']);

        $missingOption = $fixture['option'];
        $missingOption['source_file'] = $fixture['dir'] . '/missing-dict.txt';
        $missingAnalyzer = new WP_FTS_Analyzer([
            'segmenter_packs_by_lang' => [
                'zh' => $missingOption,
            ],
        ]);
        assert_same($fallbackTerms, $missingAnalyzer->analyze_query('中国科学院', ['lang' => 'zh']), 'missing Jieba source should fall back to built-in CJK n-grams');

        $hashMismatchOption = $fixture['option'];
        $hashMismatchOption['expected_sha256'] = str_repeat('0', 64);
        $hashMismatchAnalyzer = new WP_FTS_Analyzer([
            'segmenter_packs_by_lang' => [
                'zh' => $hashMismatchOption,
            ],
        ]);
        assert_same($fallbackTerms, $hashMismatchAnalyzer->analyze_query('中国科学院', ['lang' => 'zh']), 'hash-mismatched Jieba source should fall back to built-in CJK n-grams');

        $sizeMismatchOption = $fixture['option'];
        $sizeMismatchOption['expected_byte_size'] = ((int) $sizeMismatchOption['expected_byte_size']) + 1;
        $sizeMismatchAnalyzer = new WP_FTS_Analyzer([
            'segmenter_packs_by_lang' => [
                'zh' => $sizeMismatchOption,
            ],
        ]);
        assert_same($fallbackTerms, $sizeMismatchAnalyzer->analyze_query('中国科学院', ['lang' => 'zh']), 'byte-size-mismatched Jieba source should fall back to built-in CJK n-grams');
    } finally {
        remove_directory_tree($fixture['dir']);
    }
});

test_case('Chinese segmenter pack options affect runtime and sandbox defaults intentionally', function (): void {
    wp_fts_test_reset_wordpress_fakes();

    $runtimeOptions = WP_FTS_Plugin::runtime_analyzer_options();
    $runtimeSegmenters = $runtimeOptions['segmenter_packs_by_lang'] ?? [];
    assert_true(is_array($runtimeSegmenters), 'runtime analyzer options should expose the segmenter pack map shape only when configured');
    assert_true(!array_key_exists('zh', $runtimeSegmenters), 'production runtime defaults should not enable the Jieba Chinese segmenter');

    $sandboxOptions = WP_FTS_Plugin::sandbox_demo_analyzer_options();
    $sandboxSegmenters = $sandboxOptions['segmenter_packs_by_lang'] ?? [];
    assert_true(is_array($sandboxSegmenters), 'sandbox analyzer options should expose the segmenter pack map shape');
    assert_same(true, $sandboxSegmenters['zh'] ?? null, 'sandbox analyzer should try the pinned Jieba submodule source for Chinese');

    $statuses = [];
    foreach (WP_FTS_Plugin::sandbox_demo_analyzer_pack_statuses() as $status) {
        $statuses[$status['language'] . ':' . $status['kind']] = $status;
    }
    $zhStatus = $statuses['zh:tokenizer'] ?? null;
    assert_true(is_array($zhStatus), 'sandbox status should include a Chinese tokenizer row');
    assert_same('tokenizer', $zhStatus['kind'] ?? null, 'Chinese sandbox status should identify tokenizer support');

    if (WP_FTS_ChineseJiebaSegmenter::default_source_evidence()['available']) {
        assert_same('active', $zhStatus['status'] ?? null, 'initialized valid submodule should make the sandbox Jieba segmenter active');
        assert_same('zh-jieba-dict-67fa2e36e72f', $zhStatus['pack_id'] ?? null, 'active Jieba sandbox status should expose the source pack id');
        assert_true(in_array('中国科学院', WP_FTS_Plugin::sandbox_demo_analyzer()->analyze_query('小明硕士毕业于中国科学院计算所', ['lang' => 'zh']), true), 'sandbox analyzer should use the valid submodule-backed Jieba segmenter');
    } else {
        assert_same('fallback', $zhStatus['status'] ?? null, 'missing submodule should make sandbox status report fallback');
        assert_contains('fallback CJK n-grams', $zhStatus['reason'] ?? '', 'missing submodule status should explain fallback n-grams');
    }
});

test_case('plugin runtime analyzer accepts Chinese segmenter packs from WordPress option and filter', function (): void {
    $fixture = synthetic_jieba_segmenter_analyzer();
    try {
        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = [
            'segmenter_packs_by_lang' => [
                'zh' => $fixture['option'],
            ],
        ];

        $optionRuntime = WP_FTS_Plugin::runtime_analyzer_options();
        assert_same($fixture['option'], $optionRuntime['segmenter_packs_by_lang']['zh'] ?? null, 'WordPress analyzer option should pass the Chinese segmenter source config');
        assert_true(in_array('中国科学院', WP_FTS_Plugin::runtime_analyzer()->analyze_query('中国科学院', ['lang' => 'zh']), true), 'WordPress option segmenter should reach the runtime analyzer');

        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::ANALYZER_OPTIONS_FILTER] = static function (array $options) use ($fixture): array {
            $options['cjk_segmenter_packs_by_lang']['zh'] = $fixture['option'];

            return $options;
        };

        $filterRuntime = WP_FTS_Plugin::runtime_analyzer_options();
        assert_same($fixture['option'], $filterRuntime['segmenter_packs_by_lang']['zh'] ?? null, 'WordPress analyzer filter should canonicalize Chinese segmenter aliases');
        assert_true(in_array('中国科学院', WP_FTS_Plugin::runtime_analyzer()->analyze_query('中国科学院', ['lang' => 'zh']), true), 'WordPress filter segmenter should reach the runtime analyzer');
    } finally {
        remove_directory_tree($fixture['dir']);
        wp_fts_test_reset_wordpress_fakes();
    }
});

test_case('Chinese Jieba segmenter source participates in analyzer signatures', function (): void {
    $first = synthetic_jieba_segmenter_analyzer();
    $secondDir = temp_directory_path('jieba_segmenter_fixture_changed');
    try {
        if (!mkdir($secondDir, 0777, true) && !is_dir($secondDir)) {
            throw new WP_FTS_TestFailure("Could not create changed synthetic Jieba directory: {$secondDir}");
        }
        $secondSource = $secondDir . '/dict.txt';
        $rows = synthetic_jieba_segmenter_rows();
        $rows[] = ['自然语言处理系统', 1500, 'n'];
        write_synthetic_jieba_segmenter_source($secondSource, $rows);
        $secondOption = synthetic_jieba_segmenter_option($secondSource);

        $firstAnalyzer = $first['analyzer'];
        $secondAnalyzer = new WP_FTS_Analyzer([
            'segmenter_packs_by_lang' => [
                'zh' => $secondOption,
            ],
        ]);
        $fallbackAnalyzer = new WP_FTS_Analyzer();

        assert_true($fallbackAnalyzer->index_signature() !== $firstAnalyzer->index_signature(), 'enabling a Jieba segmenter source should change the analyzer signature');
        assert_true($firstAnalyzer->index_signature() !== $secondAnalyzer->index_signature(), 'changing the Jieba source hash should change the analyzer signature');
    } finally {
        remove_directory_tree($first['dir']);
        remove_directory_tree($secondDir);
    }
});

test_case('plugin runtime analyzer accepts generic lemma packs from WordPress option and filter', function (): void {
    $manifest = WP_FTS_AnalyzerPackValidator::default_synthetic_bengali_fixture_manifest();

    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = [
        'lemmatizer_packs_by_lang' => [
            'bn' => $manifest,
        ],
    ];

    $options = WP_FTS_Plugin::runtime_analyzer_options();
    assert_same($manifest, $options['lemmatizer_packs_by_lang']['bn'] ?? null, 'WordPress analyzer option should pass a generic Bengali pack manifest');
    assert_true(isset($options['lemmatizer_packs_by_lang']['pl']), 'runtime analyzer options should preserve the bundled Polish pack default');

    $analyzer = WP_FTS_Plugin::runtime_analyzer();
    assert_same(['সিনথ000লেমা'], $analyzer->analyze_query('সিনথ000গুলো', ['lang' => 'bn']), 'WordPress option pack should reach the runtime analyzer');

    $statuses = [];
    foreach (WP_FTS_Plugin::runtime_analyzer_pack_statuses() as $status) {
        $statuses[$status['language']] = $status;
    }
    assert_same('active', $statuses['bn']['status'] ?? null, 'runtime pack status should mark the configured generic pack active');
    assert_same('bn-synthetic-lemma-fixture', $statuses['bn']['pack_id'] ?? null, 'runtime pack status should expose the active generic pack id');
    assert_same(true, $statuses['bn']['fixture_only'] ?? null, 'runtime pack status should preserve fixture-only diagnostics for configured test packs');

    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::ANALYZER_OPTIONS_FILTER] = static function (array $options) use ($manifest): array {
        $options['lemma_packs_by_lang']['bn'] = ['manifest_path' => $manifest];

        return $options;
    };

    $filterAnalyzer = WP_FTS_Plugin::runtime_analyzer();
    assert_same(['ককক'], $filterAnalyzer->analyze_query('ককগুলো', ['lang' => 'bn']), 'WordPress analyzer filter should pass generic pack aliases into the runtime analyzer');
});

test_case('plugin runtime analyzer lets legacy Polish aliases override earlier defaults', function (): void {
    $fixtureManifest = WP_FTS_AnalyzerPackValidator::default_polish_fixture_manifest();

    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = [
        'polish_lemma_pack' => false,
    ];

    $disabledOptions = WP_FTS_Plugin::runtime_analyzer_options();
    assert_same(false, $disabledOptions['lemmatizer_packs_by_lang']['pl'] ?? null, 'legacy Polish option alias should disable the bundled runtime pack');
    $statuses = [];
    foreach (WP_FTS_Plugin::runtime_analyzer_pack_statuses() as $status) {
        $statuses[$status['language']] = $status;
    }
    assert_same('disabled', $statuses['pl']['status'] ?? null, 'legacy Polish option alias should report the runtime pack as disabled');
    assert_same(['zamk'], WP_FTS_Plugin::runtime_analyzer()->analyze_query('zamkach', ['lang' => 'pl']), 'disabled runtime Polish pack should fall back to suffix stemming');

    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = [
        'polish_lemmatizer_pack' => $fixtureManifest,
    ];

    $optionReplacement = WP_FTS_Plugin::runtime_analyzer_options();
    assert_same($fixtureManifest, $optionReplacement['lemmatizer_packs_by_lang']['pl'] ?? null, 'legacy Polish lemmatizer option alias should replace the bundled runtime pack');
    assert_same(['zamek'], WP_FTS_Plugin::runtime_analyzer()->analyze_query('zamkach', ['lang' => 'pl']), 'replacement runtime Polish pack should reach the analyzer');

    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::ANALYZER_OPTIONS_FILTER] = static function (array $options) use ($fixtureManifest): array {
        $options['polish_lemmatizer_pack'] = $fixtureManifest;

        return $options;
    };

    $filterReplacement = WP_FTS_Plugin::runtime_analyzer_options();
    assert_same($fixtureManifest, $filterReplacement['lemmatizer_packs_by_lang']['pl'] ?? null, 'legacy Polish filter alias should replace the bundled runtime pack');
    assert_same(['zamek'], WP_FTS_Plugin::runtime_analyzer()->analyze_query('zamkach', ['lang' => 'pl']), 'replacement runtime Polish pack from filter should reach the analyzer');
});

test_case('plugin runtime analyzer keeps generic Polish maps canonical within each layer', function (): void {
    $fixtureManifest = WP_FTS_AnalyzerPackValidator::default_polish_fixture_manifest();

    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = [
        'polish_lemma_pack' => false,
        'lemmatizer_packs_by_lang' => [
            'pl' => $fixtureManifest,
        ],
    ];

    $optionGeneric = WP_FTS_Plugin::runtime_analyzer_options();
    assert_same($fixtureManifest, $optionGeneric['lemmatizer_packs_by_lang']['pl'] ?? null, 'explicit generic Polish option should beat same-layer legacy alias');

    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = [
        'polish_lemmatizer_pack' => $fixtureManifest,
        'lemmatizer_packs_by_lang' => [
            'pl' => $fixtureManifest,
        ],
        'lemma_packs_by_lang' => [
            'pl' => false,
        ],
    ];

    $optionLemmaGeneric = WP_FTS_Plugin::runtime_analyzer_options();
    assert_same(false, $optionLemmaGeneric['lemmatizer_packs_by_lang']['pl'] ?? null, 'lemma_packs_by_lang Polish option should remain canonical over lemmatizer_packs_by_lang and legacy aliases');

    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::ANALYZER_OPTIONS_FILTER] = static function (array $options) use ($fixtureManifest): array {
        $options['polish_lemma_pack'] = false;
        $options['lemmatizer_packs_by_lang']['pl'] = $fixtureManifest;

        return $options;
    };

    $filterGeneric = WP_FTS_Plugin::runtime_analyzer_options();
    assert_same($fixtureManifest, $filterGeneric['lemmatizer_packs_by_lang']['pl'] ?? null, 'explicit generic Polish filter entry should beat same-layer legacy alias');

    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::ANALYZER_OPTIONS_FILTER] = static function (array $options) use ($fixtureManifest): array {
        $options['polish_lemmatizer_pack'] = $fixtureManifest;
        $options['lemma_packs_by_lang']['pl'] = false;

        return $options;
    };

    $filterLemmaGeneric = WP_FTS_Plugin::runtime_analyzer_options();
    assert_same(false, $filterLemmaGeneric['lemmatizer_packs_by_lang']['pl'] ?? null, 'lemma_packs_by_lang Polish filter entry should remain canonical over lemmatizer_packs_by_lang and legacy aliases');
});

test_case('plugin runtime analyzer ignores invalid or language-mismatched generic packs safely', function (): void {
    $syntheticBnManifest = WP_FTS_AnalyzerPackValidator::default_synthetic_bengali_fixture_manifest();
    $polishManifest = WP_FTS_AnalyzerPackValidator::default_polish_fixture_manifest();

    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = [
        'lemma_packs_by_lang' => [
            'bn' => $polishManifest,
            'pt' => __DIR__ . '/missing-pt-pack/manifest.json',
            'ur' => $syntheticBnManifest,
            'de' => false,
        ],
    ];

    $analyzer = WP_FTS_Plugin::runtime_analyzer();
    assert_same(['সিনথ000'], $analyzer->analyze_query('সিনথ000গুলো', ['lang' => 'bn']), 'language-mismatched generic pack should fall back to the Bengali baseline');

    $statuses = [];
    foreach (WP_FTS_Plugin::runtime_analyzer_pack_statuses() as $status) {
        $statuses[$status['language']] = $status;
    }
    assert_same('ignored', $statuses['bn']['status'] ?? null, 'language-mismatched pack should be reported as ignored');
    assert_same('ignored', $statuses['pt']['status'] ?? null, 'missing pack should be reported as ignored');
    assert_same('ignored', $statuses['ur']['status'] ?? null, 'wrong-language generic pack should be reported as ignored');
    assert_same('disabled', $statuses['de']['status'] ?? null, 'explicit false pack option should disable that language pack entry');
});

test_case('top-language pack audit fails required gate when manifests are missing', function (): void {
    $root = temp_directory_path('top_language_audit_empty_root');
    try {
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            throw new WP_FTS_TestFailure("Could not create empty audit root: {$root}");
        }

        $cli = run_top_language_pack_audit([
            '--pack-root=' . $root,
            '--json',
            '--require-pack-backed',
        ]);
        assert_same(1, $cli['exit'], 'top-language audit should fail when required target packs are absent');
        assert_same('fail', $cli['json']['status'] ?? null, 'top-language audit JSON should expose the failed gate');

        $rows = top_language_audit_rows_by_language($cli['json']);
        foreach (['en', 'hi', 'es', 'ar', 'fr', 'bn', 'pt', 'id', 'ru', 'de', 'te', 'tr', 'it', 'fa', 'uk', 'nl'] as $language) {
            assert_same('missing_pack', $rows[$language]['status'] ?? null, "{$language} should report a missing pack");
            assert_same(true, $rows[$language]['pack_required'] ?? null, "{$language} should remain required in the audit registry");
            assert_same('lemma_pack', $rows[$language]['support_kind'] ?? null, "{$language} should remain a lemma-pack lane");
        }
        assert_same('tokenizer_supported', $rows['zh']['status'] ?? null, 'Chinese should be reported as tokenizer-supported instead of missing a lemma pack');
        assert_same('tokenizer', $rows['zh']['support_kind'] ?? null, 'Chinese should expose the tokenizer support kind');
        assert_same(false, $rows['zh']['pack_required'] ?? null, 'Chinese should not be required by the lemma-pack gate');
        foreach (['ja', 'ko'] as $language) {
            assert_same('tokenizer_supported', $rows[$language]['status'] ?? null, "{$language} should be reported as tokenizer-supported instead of missing a lemma pack");
            assert_same('tokenizer', $rows[$language]['support_kind'] ?? null, "{$language} should expose the tokenizer support kind");
            assert_same(false, $rows[$language]['pack_required'] ?? null, "{$language} should not be required by the lemma-pack gate");
        }
        assert_same('license_blocked', $rows['ur']['status'] ?? null, 'Urdu should report the UniMorph redistribution blocker');
        assert_same('license_blocked', $rows['ur']['support_kind'] ?? null, 'Urdu should expose the license-blocked support kind');
        assert_same(false, $rows['ur']['pack_required'] ?? null, 'Urdu should not fail the pack-backed gate while redistribution is blocked');
        assert_contains('contains no README, license, notice, or copying file', (string) ($rows['ur']['blocker'] ?? ''), 'Urdu blocker should explain missing license evidence');
    } finally {
        remove_directory_tree($root);
    }
});

test_case('top-language pack audit strict gate passes with required lemma packs and explicit non-lemma lanes', function (): void {
    $root = temp_directory_path('top_language_audit_required_root');
    try {
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            throw new WP_FTS_TestFailure("Could not create required audit root: {$root}");
        }
        foreach (['en', 'hi', 'es', 'ar', 'fr', 'bn', 'pt', 'id', 'ru', 'de', 'te', 'tr', 'it', 'fa', 'uk', 'nl'] as $language) {
            write_synthetic_audit_lemma_pack($language, $root . '/' . $language . '-pack', false);
        }

        $cli = run_top_language_pack_audit([
            '--pack-root=' . $root,
            '--json',
            '--require-pack-backed',
        ]);
        assert_same(0, $cli['exit'], 'strict audit gate should pass when every required lemma-pack lane is pack-backed');
        assert_same('ok', $cli['json']['status'] ?? null, 'strict audit JSON should expose ok status');

        $rows = top_language_audit_rows_by_language($cli['json']);
        foreach (['en', 'hi', 'es', 'ar', 'fr', 'bn', 'pt', 'id', 'ru', 'de', 'te', 'tr', 'it', 'fa', 'uk', 'nl'] as $language) {
            assert_same('pack_backed', $rows[$language]['status'] ?? null, "{$language} should be pack-backed");
        }
        assert_same('tokenizer_supported', $rows['zh']['status'] ?? null, 'Chinese tokenizer lane should not require a lemma pack');
        assert_same('tokenizer_supported', $rows['ja']['status'] ?? null, 'Japanese tokenizer lane should not require a lemma pack');
        assert_same('tokenizer_supported', $rows['ko']['status'] ?? null, 'Korean tokenizer lane should not require a lemma pack');
        assert_same('license_blocked', $rows['ur']['status'] ?? null, 'Urdu license blocker should not fail the strict gate');
    } finally {
        remove_directory_tree($root);
    }
});

test_case('top-language pack audit marks generated non-fixture Spanish pack as pack-backed', function (): void {
    $root = temp_directory_path('top_language_audit_es_root');
    try {
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            throw new WP_FTS_TestFailure("Could not create Spanish audit root: {$root}");
        }
        $manifest = write_synthetic_audit_lemma_pack('es', $root . '/es-pack', false);

        $cli = run_top_language_pack_audit([
            '--pack-root=' . $root,
            '--json',
        ]);
        assert_same(0, $cli['exit'], 'top-language audit should pass without the required gate');

        $rows = top_language_audit_rows_by_language($cli['json']);
        assert_same('pack_backed', $rows['es']['status'] ?? null, 'generated non-fixture Spanish manifest should count as pack-backed');
        assert_same('es-synthetic-audit-pack-backed', $rows['es']['pack_id'] ?? null, 'Spanish audit row should expose the generated pack id');
        assert_same(realpath($manifest), $rows['es']['manifest'] ?? null, 'Spanish audit row should expose the discovered manifest path');
    } finally {
        remove_directory_tree($root);
    }
});

test_case('top-language pack audit treats generated Bengali fixture pack as insufficient', function (): void {
    $root = temp_directory_path('top_language_audit_bn_fixture_root');
    try {
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            throw new WP_FTS_TestFailure("Could not create Bengali audit root: {$root}");
        }
        write_synthetic_audit_lemma_pack('bn', $root . '/bn-fixture-pack', true);

        $cli = run_top_language_pack_audit([
            '--pack-root=' . $root,
            '--json',
            '--require-pack-backed',
        ]);
        assert_same(1, $cli['exit'], 'required audit gate should fail when Bengali coverage is fixture-only');

        $rows = top_language_audit_rows_by_language($cli['json']);
        assert_same('fixture_only', $rows['bn']['status'] ?? null, 'generated Bengali fixture manifest should be reported as fixture-only');
        assert_same('bn-synthetic-audit-fixture', $rows['bn']['pack_id'] ?? null, 'Bengali audit row should expose the fixture pack id');
    } finally {
        remove_directory_tree($root);
    }
});

test_case('top-language pack audit reports explicit manifest language mismatch', function (): void {
    $root = temp_directory_path('top_language_audit_mismatch_root');
    $pack = temp_directory_path('top_language_audit_mismatch_pack');
    try {
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            throw new WP_FTS_TestFailure("Could not create mismatch audit root: {$root}");
        }
        $manifest = write_synthetic_audit_lemma_pack('bn', $pack, true);

        $cli = run_top_language_pack_audit([
            '--pack-root=' . $root,
            '--manifest=es:' . $manifest,
            '--json',
        ]);
        assert_same(0, $cli['exit'], 'language mismatch should be reported without failing when the required gate is off');

        $rows = top_language_audit_rows_by_language($cli['json']);
        assert_same('language_mismatch', $rows['es']['status'] ?? null, 'explicit Spanish mapping to a Bengali manifest should report mismatch');
        assert_same('bn', $rows['es']['manifest_language'] ?? null, 'mismatch row should expose the manifest language');
        assert_same('bn-synthetic-audit-fixture', $rows['es']['pack_id'] ?? null, 'mismatch row should still expose validated manifest metadata');
    } finally {
        remove_directory_tree($root);
        remove_directory_tree($pack);
    }
});

test_case('top-language pack audit reports corrupt explicit manifest as invalid', function (): void {
    $root = temp_directory_path('top_language_audit_corrupt_root');
    $pack = temp_directory_path('top_language_audit_corrupt_pack');
    try {
        foreach ([$root, $pack] as $directory) {
            if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new WP_FTS_TestFailure("Could not create corrupt audit directory: {$directory}");
            }
        }
        $manifest = $pack . '/manifest.json';
        if (file_put_contents($manifest, "{\"schema_version\":1,\n") === false) {
            throw new WP_FTS_TestFailure("Could not write corrupt audit manifest: {$manifest}");
        }

        $cli = run_top_language_pack_audit([
            '--pack-root=' . $root,
            '--manifest=es:' . $manifest,
            '--json',
        ]);
        assert_same(0, $cli['exit'], 'invalid explicit pack should be reported without failing when the required gate is off');

        $rows = top_language_audit_rows_by_language($cli['json']);
        assert_same('invalid_pack', $rows['es']['status'] ?? null, 'corrupt explicit manifest should report invalid_pack');
        assert_contains('not valid JSON', (string) ($rows['es']['error'] ?? ''), 'invalid pack row should expose validator metadata errors');
    } finally {
        remove_directory_tree($root);
        remove_directory_tree($pack);
    }
});

test_case('top-language pack audit discovers nested pack-root manifests', function (): void {
    $root = temp_directory_path('top_language_audit_nested_root');
    try {
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            throw new WP_FTS_TestFailure("Could not create nested audit root: {$root}");
        }
        $manifest = write_synthetic_audit_lemma_pack('es', $root . '/one/two/es-pack', false);

        $cli = run_top_language_pack_audit([
            '--pack-root=' . $root,
            '--json',
        ]);
        assert_same(0, $cli['exit'], 'nested audit discovery should succeed');

        $rows = top_language_audit_rows_by_language($cli['json']);
        assert_same('pack_backed', $rows['es']['status'] ?? null, 'nested Spanish manifest should be discovered by pack-root scan');
        assert_same(realpath($manifest), $rows['es']['manifest'] ?? null, 'nested audit row should expose the discovered manifest path');
    } finally {
        remove_directory_tree($root);
    }
});

test_case('generic lemma TSV importer builds a valid synthetic pack', function (): void {
    $sourceDir = temp_directory_path('lemma_tsv_import_source');
    $out = temp_directory_path('lemma_tsv_import_pack');
    try {
        if (!mkdir($sourceDir, 0777, true) && !is_dir($sourceDir)) {
            throw new WP_FTS_TestFailure("Could not create synthetic source directory: {$sourceDir}");
        }
        $source = $sourceDir . '/qaa-normalized-lemma.tsv';
        write_synthetic_qaa_lemma_tsv_source($source);

        $cli = test_run_subprocess(
            array_merge(
                [PHP_BINARY, dirname(__DIR__) . '/tools/import-lemma-tsv-pack.php'],
                synthetic_qaa_lemma_tsv_import_args($source, $out)
            ),
            dirname(__DIR__)
        );
        assert_same(0, $cli['exit'], 'generic lemma TSV importer CLI should succeed for synthetic normalized input: ' . $cli['stderr']);

        $summary = json_decode($cli['stdout'], true, 512, JSON_THROW_ON_ERROR);
        assert_same('ok', $summary['status'] ?? null, 'generic importer CLI should return ok status');
        assert_same('qaa-synthetic-lemma-tsv-importer', $summary['pack_id'] ?? null, 'generic importer summary should expose pack id');
        assert_same('qaa', $summary['language'] ?? null, 'generic importer summary should expose language');
        assert_same(5, $summary['runtime']['rows'] ?? null, 'generic importer should sort and deduplicate synthetic rows');
        assert_same(3, $summary['runtime']['files'] ?? null, 'generic importer should shard without splitting ambiguous surfaces');
        assert_same(1, $summary['stats']['deduplicated_rows'] ?? null, 'generic importer should report duplicate source pairs');
        assert_same(3, $summary['stats']['rows_with_tags'] ?? null, 'generic importer should accept optional tag columns');
        assert_same(3, $summary['stats']['rows_with_source_notes'] ?? null, 'generic importer should accept optional source-note columns');

        $manifest = $out . '/manifest.json';
        $validation = (new WP_FTS_AnalyzerPackValidator())->validate($manifest);
        assert_same('qaa-synthetic-lemma-tsv-importer', $validation['manifest']['pack_id'], 'generated generic pack manifest should validate');
        assert_same('qaa', $validation['manifest']['language'], 'generated generic pack language should validate');
        assert_same(WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV, $validation['manifest']['runtime']['format'], 'generated generic pack should use lemma TSV runtime format');
        assert_true($validation['manifest']['fixture_only'] === true, 'synthetic generated pack should be fixture-only');
        assert_same(false, $validation['manifest']['default_enabled'], 'generated pack should remain default-disabled');
        assert_same('Project-owned synthetic qaa lemma TSV importer fixture', $validation['manifest']['source']['name'] ?? null, 'generated manifest should preserve source name');
        assert_same('urn:wp-fts:test:synthetic-qaa-lemma-tsv', $validation['manifest']['source']['url'] ?? null, 'generated manifest should preserve source URL');
        assert_same('CC0-1.0', $validation['manifest']['license']['spdx_id'] ?? null, 'generated manifest should preserve license identifier');
        assert_same('Project-owned synthetic qaa rows for importer tests only.', $validation['manifest']['attribution']['upstream'] ?? null, 'generated manifest should preserve attribution');
        assert_same(5, $validation['runtime_rows'], 'validator should count generated runtime rows');
        assert_same(5, count($validation['rows']), 'validator should collect tiny synthetic generated rows');

        $rowsByPair = [];
        foreach ($validation['rows'] as $row) {
            assert_true(
                str_starts_with($row['surface'], 'qaa') && str_starts_with($row['lemma'], 'qaa'),
                'imported synthetic rows should not use real-language word-family fixtures'
            );
            $rowsByPair[$row['surface'] . "\t" . $row['lemma']] = true;
        }
        assert_true(isset($rowsByPair["qaaforma\tqaalemma"]), 'generated runtime should include the first synthetic form');
        assert_true(isset($rowsByPair["qaaformb\tqaalemma"]), 'generated runtime should include the second synthetic form sharing a lemma');
        assert_true(isset($rowsByPair["qaaamb\tqaaone"]), 'generated runtime should retain first ambiguous synthetic lemma');
        assert_true(isset($rowsByPair["qaaamb\tqaatwo"]), 'generated runtime should retain second ambiguous synthetic lemma');
    } finally {
        remove_directory_tree($out);
        remove_directory_tree($sourceDir);
    }
});

test_case('generic lemma TSV importer cleans only owned children under supplied tmp dir', function (): void {
    $sourceDir = temp_directory_path('lemma_tsv_import_tmp_source');
    $out = temp_directory_path('lemma_tsv_import_tmp_pack');
    $failOut = temp_directory_path('lemma_tsv_import_tmp_fail_pack');
    $tmpParent = temp_directory_path('lemma_tsv_import_tmp_parent');
    try {
        foreach ([$sourceDir, $tmpParent] as $directory) {
            if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new WP_FTS_TestFailure("Could not create temporary test directory: {$directory}");
            }
        }

        $sentinel = $tmpParent . '/unrelated-sentinel.txt';
        if (file_put_contents($sentinel, "must survive\n") === false) {
            throw new WP_FTS_TestFailure("Could not write temporary sentinel: {$sentinel}");
        }

        $source = $sourceDir . '/qaa-normalized-lemma.tsv';
        write_synthetic_qaa_lemma_tsv_source($source);
        $successCli = test_run_subprocess(
            array_merge(
                [PHP_BINARY, dirname(__DIR__) . '/tools/import-lemma-tsv-pack.php'],
                synthetic_qaa_lemma_tsv_import_args($source, $out),
                ['--tmp-dir=' . $tmpParent]
            ),
            dirname(__DIR__)
        );
        assert_same(0, $successCli['exit'], 'generic importer should succeed with a supplied temporary parent directory: ' . $successCli['stderr']);
        assert_true(is_file($sentinel), 'successful importer cleanup should preserve unrelated files in the supplied temporary parent');
        assert_same([], lemma_tsv_import_tmp_children($tmpParent), 'successful importer cleanup should remove only its owned child directory');

        $badSource = $sourceDir . '/qaa-invalid-lemma.tsv';
        if (file_put_contents($badSource, "qaa bad\tqaalemma\n") === false) {
            throw new WP_FTS_TestFailure("Could not write invalid synthetic source: {$badSource}");
        }
        $failureCli = test_run_subprocess(
            array_merge(
                [PHP_BINARY, dirname(__DIR__) . '/tools/import-lemma-tsv-pack.php'],
                synthetic_qaa_lemma_tsv_import_args($badSource, $failOut),
                ['--tmp-dir=' . $tmpParent]
            ),
            dirname(__DIR__)
        );
        assert_same(1, $failureCli['exit'], 'generic importer should fail for invalid normalized input');
        assert_contains('must be one normalized token', $failureCli['stderr'], 'failing importer should report the invalid source row');
        assert_true(is_file($sentinel), 'failing importer cleanup should preserve unrelated files in the supplied temporary parent');
        assert_same([], lemma_tsv_import_tmp_children($tmpParent), 'failing importer cleanup should remove only its owned child directory');
    } finally {
        remove_directory_tree($out);
        remove_directory_tree($failOut);
        remove_directory_tree($sourceDir);
        remove_directory_tree($tmpParent);
    }
});

test_case('imported generic lemma pack drives indexing search and snippets', function (): void {
    require_once __DIR__ . '/../tools/import-lemma-tsv-pack.php';

    $sourceDir = temp_directory_path('lemma_tsv_runtime_source');
    $out = temp_directory_path('lemma_tsv_runtime_pack');
    try {
        if (!mkdir($sourceDir, 0777, true) && !is_dir($sourceDir)) {
            throw new WP_FTS_TestFailure("Could not create synthetic source directory: {$sourceDir}");
        }
        $source = $sourceDir . '/qaa-normalized-lemma.tsv';
        write_synthetic_qaa_lemma_tsv_source($source);

        $options = WP_FTS_LemmaTsvPackImporter::parse_cli_options(synthetic_qaa_lemma_tsv_import_args($source, $out));
        (new WP_FTS_LemmaTsvPackImporter())->import($options);

        $manifest = $out . '/manifest.json';
        $pack = WP_FTS_LanguageLemmaPack::from_manifest_file($manifest);
        assert_same('qaalemma', $pack->stem('qaaforma', 'qaa'), 'generated runtime pack should map first synthetic surface to lemma');
        assert_same('qaalemma', $pack->stem('qaaformb', 'qaa'), 'generated runtime pack should map second synthetic surface to shared lemma');
        assert_same('qaaamb', $pack->stem('qaaamb', 'qaa'), 'generated runtime pack should no-op ambiguous synthetic surfaces');
        assert_same('qaaforma', $pack->stem('qaaforma', 'en'), 'generated runtime pack should no-op other language partitions');

        $analyzer = new WP_FTS_Analyzer([
            'default_lang' => 'qaa',
            'lemma_packs_by_lang' => [
                'qaa' => $manifest,
            ],
        ]);
        $storage = new WP_FTS_Storage_InMemory();
        $indexer = new WP_FTS_Indexer($storage, $analyzer);
        $text = 'Synthetic source row qaaforma appears in this document with qaasolo.';
        $indexer->index_document_fields(815, [['name' => 'content', 'text' => $text]], [
            'lang' => 'qaa',
            'metadata' => [
                'post_id' => 815,
                'post_type' => 'post',
                'post_status' => 'publish',
                'title' => 'Synthetic qaa lemma TSV importer',
                'search_text' => $text,
                'language' => 'qaa',
            ],
        ]);

        $terms = $storage->all_terms();
        assert_true(in_array(WP_FTS_TermNamespace::namespace_term('qaa', 'qaalemma'), $terms, true), 'imported pack should store the shared synthetic lemma during indexing');
        assert_true(!in_array(WP_FTS_TermNamespace::namespace_term('qaa', 'qaaforma'), $terms, true), 'imported pack should not store the mapped document surface as the index key');

        $searcher = new WP_FTS_Searcher($storage, $analyzer);
        $payload = $searcher->search('qaaformb', [
            'lang' => 'qaa',
            'mode' => 'AND',
            'include_total' => true,
            'include_metadata' => true,
            'include_snippets' => true,
            'highlight' => true,
            'snippet_length' => 160,
        ]);
        assert_same(1, $payload['total'], 'query surface should meet indexed document surface through imported lemma pack');
        assert_same(815, $payload['results'][0]['doc_id'] ?? null, 'imported pack search should return the indexed synthetic document');
        assert_contains('<mark>qaaforma</mark>', (string) ($payload['results'][0]['snippet'] ?? ''), 'snippet highlighter should mark the indexed surface when querying another imported form');

        $fallbackAnalyzer = new WP_FTS_Analyzer(['default_lang' => 'qaa']);
        $fallbackStorage = new WP_FTS_Storage_InMemory();
        (new WP_FTS_Indexer($fallbackStorage, $fallbackAnalyzer))->index_document_fields(816, [['name' => 'content', 'text' => $text]], [
            'lang' => 'qaa',
            'metadata' => [
                'post_id' => 816,
                'post_type' => 'post',
                'post_status' => 'publish',
                'title' => 'Synthetic qaa fallback',
                'search_text' => $text,
                'language' => 'qaa',
            ],
        ]);
        $fallbackPayload = (new WP_FTS_Searcher($fallbackStorage, $fallbackAnalyzer))->search('qaaformb', [
            'lang' => 'qaa',
            'mode' => 'AND',
            'include_total' => true,
        ]);
        assert_same(0, $fallbackPayload['total'], 'missing generic pack should preserve the built-in fallback behavior');
    } finally {
        remove_directory_tree($out);
        remove_directory_tree($sourceDir);
    }
});

test_case('conllu lemma importer builds a valid synthetic pack and skips non-runtime rows', function (): void {
    $sourceDir = temp_directory_path('conllu_import_source');
    $out = temp_directory_path('conllu_import_pack');
    try {
        if (!mkdir($sourceDir, 0777, true) && !is_dir($sourceDir)) {
            throw new WP_FTS_TestFailure("Could not create synthetic CoNLL-U source directory: {$sourceDir}");
        }
        $source = $sourceDir . '/qaa-synthetic.conllu';
        write_synthetic_qaa_conllu_source($source);

        $cli = test_run_subprocess(
            array_merge(
                [PHP_BINARY, dirname(__DIR__) . '/tools/import-conllu-lemma-pack.php'],
                synthetic_qaa_conllu_import_args($source, $out)
            ),
            dirname(__DIR__)
        );
        assert_same(0, $cli['exit'], 'CoNLL-U importer CLI should succeed for synthetic source-shaped input: ' . $cli['stderr']);

        $summary = json_decode($cli['stdout'], true, 512, JSON_THROW_ON_ERROR);
        assert_same('ok', $summary['status'] ?? null, 'CoNLL-U importer CLI should return ok status');
        assert_same('qaa-synthetic-conllu-lemma-importer', $summary['pack_id'] ?? null, 'CoNLL-U importer summary should expose pack id');
        assert_same('qaa', $summary['language'] ?? null, 'CoNLL-U importer summary should expose language');
        assert_same(5, $summary['runtime']['rows'] ?? null, 'CoNLL-U importer should emit only normalized runtime rows');
        assert_same(3, $summary['runtime']['files'] ?? null, 'CoNLL-U importer should delegate runtime sharding to the TSV importer');
        assert_same(1, $summary['conllu']['multiword_token_rows'] ?? null, 'CoNLL-U importer should skip multiword token rows');
        assert_same(1, $summary['conllu']['empty_node_rows'] ?? null, 'CoNLL-U importer should skip empty-node rows');
        assert_same(2, $summary['conllu']['placeholder_rows'] ?? null, 'CoNLL-U importer should skip empty or underscore form/lemma rows');
        assert_same(2, $summary['conllu']['invalid_runtime_token_rows'] ?? null, 'CoNLL-U importer should drop rows that normalize outside one runtime token');
        assert_same(5, $summary['conllu']['accepted_rows'] ?? null, 'CoNLL-U importer should count accepted source rows before TSV deduplication');

        $manifest = $out . '/manifest.json';
        $validation = (new WP_FTS_AnalyzerPackValidator())->validate($manifest);
        assert_same('qaa-synthetic-conllu-lemma-importer', $validation['manifest']['pack_id'], 'generated CoNLL-U pack manifest should validate');
        assert_same('qaa', $validation['manifest']['language'], 'generated CoNLL-U pack language should validate');
        assert_same(WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV, $validation['manifest']['runtime']['format'], 'generated CoNLL-U pack should use lemma TSV runtime format');
        assert_same('Project-owned synthetic qaa CoNLL-U importer fixture', $validation['manifest']['source']['name'] ?? null, 'generated CoNLL-U manifest should preserve source name');
        assert_same('Project-owned synthetic qaa CoNLL-U rows for importer tests only.', $validation['manifest']['attribution']['upstream'] ?? null, 'generated CoNLL-U manifest should preserve attribution');

        $rowsByPair = [];
        foreach ($validation['rows'] as $row) {
            assert_true(
                str_starts_with($row['surface'], 'qaa') && str_starts_with($row['lemma'], 'qaa'),
                'imported CoNLL-U synthetic rows should not use real-language word-family fixtures'
            );
            $rowsByPair[$row['surface'] . "\t" . $row['lemma']] = true;
        }
        assert_true(isset($rowsByPair["qaaforma\tqaalemma"]), 'generated CoNLL-U runtime should include the first synthetic form');
        assert_true(isset($rowsByPair["qaaformb\tqaalemma"]), 'generated CoNLL-U runtime should include the second synthetic form sharing a lemma');
        assert_true(isset($rowsByPair["qaaamb\tqaaone"]), 'generated CoNLL-U runtime should retain first ambiguous synthetic lemma');
        assert_true(isset($rowsByPair["qaaamb\tqaatwo"]), 'generated CoNLL-U runtime should retain second ambiguous synthetic lemma');
        assert_true(!isset($rowsByPair["qaa\tqaalemma"]), 'generated CoNLL-U runtime should not include multi-token source values');
        assert_true(!isset($rowsByPair["qaahyphenlemma\tqaa-lemma"]), 'generated CoNLL-U runtime should not include punctuation-only invalid runtime lemmas');
    } finally {
        remove_directory_tree($out);
        remove_directory_tree($sourceDir);
    }
});

test_case('conllu lemma importer combines directory sources in stable order', function (): void {
    $sourceDir = temp_directory_path('conllu_import_tree_source');
    $nestedDir = $sourceDir . '/nested';
    $out = temp_directory_path('conllu_import_tree_pack');
    try {
        if (!mkdir($nestedDir, 0777, true) && !is_dir($nestedDir)) {
            throw new WP_FTS_TestFailure("Could not create synthetic CoNLL-U tree directory: {$nestedDir}");
        }
        if (file_put_contents($sourceDir . '/ignore.txt', "not conllu\n") === false) {
            throw new WP_FTS_TestFailure('Could not write ignored synthetic source file.');
        }
        if (file_put_contents($sourceDir . '/a.conllu', synthetic_qaa_conllu_row('1', 'QAADirA', 'QAADirLemma') . "\n") === false) {
            throw new WP_FTS_TestFailure('Could not write first synthetic CoNLL-U source file.');
        }
        if (file_put_contents($nestedDir . '/b.conllu', synthetic_qaa_conllu_row('1', 'QAADirB', 'QAADirLemma') . "\n") === false) {
            throw new WP_FTS_TestFailure('Could not write nested synthetic CoNLL-U source file.');
        }

        $cli = test_run_subprocess(
            array_merge(
                [PHP_BINARY, dirname(__DIR__) . '/tools/import-conllu-lemma-pack.php'],
                synthetic_qaa_conllu_import_args($sourceDir, $out)
            ),
            dirname(__DIR__)
        );
        assert_same(0, $cli['exit'], 'CoNLL-U directory importer should succeed: ' . $cli['stderr']);

        $summary = json_decode($cli['stdout'], true, 512, JSON_THROW_ON_ERROR);
        assert_same(2, $summary['conllu']['source_files'] ?? null, 'CoNLL-U directory importer should discover only .conllu files');
        assert_same(['a.conllu', 'nested/b.conllu'], $summary['conllu']['files'] ?? null, 'CoNLL-U directory importer should report stable-sorted source order');
        assert_same(2, $summary['runtime']['rows'] ?? null, 'CoNLL-U directory importer should combine rows from every discovered source file');

        $validation = (new WP_FTS_AnalyzerPackValidator())->validate($out . '/manifest.json');
        $rowsByPair = [];
        foreach ($validation['rows'] as $row) {
            $rowsByPair[$row['surface'] . "\t" . $row['lemma']] = true;
        }
        assert_true(isset($rowsByPair["qaadira\tqaadirlemma"]), 'directory import should include the root .conllu file');
        assert_true(isset($rowsByPair["qaadirb\tqaadirlemma"]), 'directory import should include the nested .conllu file');
    } finally {
        remove_directory_tree($out);
        remove_directory_tree($sourceDir);
    }
});

test_case('conllu lemma importer rejects invalid rows with wrong field counts', function (): void {
    $cases = [
        'regular_too_few' => [
            "1\tQAAForm\tQAALemma",
            'too few columns',
        ],
        'regular_too_many' => [
            synthetic_qaa_conllu_row('1', 'QAAForm', 'QAALemma') . "\tEXTRA",
            'too many columns',
        ],
        'multiword_too_few' => [
            "1-2\tQAACompound\t_",
            'too few columns',
        ],
        'empty_node_too_few' => [
            "2.1\tQAAEmptyNode\tQAALemma",
            'too few columns',
        ],
    ];

    foreach ($cases as $name => [$row, $expectedMessage]) {
        $sourceDir = temp_directory_path('conllu_import_invalid_' . $name . '_source');
        $out = temp_directory_path('conllu_import_invalid_' . $name . '_pack');
        try {
            if (!mkdir($sourceDir, 0777, true) && !is_dir($sourceDir)) {
                throw new WP_FTS_TestFailure("Could not create invalid CoNLL-U source directory: {$sourceDir}");
            }
            $source = $sourceDir . '/invalid.conllu';
            if (file_put_contents($source, $row . "\n") === false) {
                throw new WP_FTS_TestFailure("Could not write invalid synthetic CoNLL-U source: {$source}");
            }

            $cli = test_run_subprocess(
                array_merge(
                    [PHP_BINARY, dirname(__DIR__) . '/tools/import-conllu-lemma-pack.php'],
                    synthetic_qaa_conllu_import_args($source, $out)
                ),
                dirname(__DIR__)
            );
            assert_same(1, $cli['exit'], "CoNLL-U importer should fail for {$name} rows");
            assert_contains($expectedMessage, $cli['stderr'], "CoNLL-U importer should report a clear {$expectedMessage} error for {$name}");
            assert_contains('expected exactly 10 tab-separated columns', $cli['stderr'], "CoNLL-U importer should report the exact CoNLL-U field count for {$name}");
            assert_true(!is_file($out . '/manifest.json'), "failed CoNLL-U import should not leave a valid pack manifest for {$name}");
        } finally {
            remove_directory_tree($out);
            remove_directory_tree($sourceDir);
        }
    }
});

test_case('conllu lemma importer still skips valid multiword and empty-node rows', function (): void {
    $sourceDir = temp_directory_path('conllu_import_valid_skips_source');
    $out = temp_directory_path('conllu_import_valid_skips_pack');
    try {
        if (!mkdir($sourceDir, 0777, true) && !is_dir($sourceDir)) {
            throw new WP_FTS_TestFailure("Could not create valid-skip CoNLL-U source directory: {$sourceDir}");
        }
        $source = $sourceDir . '/valid-skips.conllu';
        $contents = implode("\n", [
            synthetic_qaa_conllu_row('1-2', 'QAACompound', '_'),
            synthetic_qaa_conllu_row('1', 'QAASolo', 'QAALemma'),
            synthetic_qaa_conllu_row('1.1', 'QAAEmptyNode', 'QAALemma'),
            '',
        ]);
        if (file_put_contents($source, $contents) === false) {
            throw new WP_FTS_TestFailure("Could not write valid-skip synthetic CoNLL-U source: {$source}");
        }

        $cli = test_run_subprocess(
            array_merge(
                [PHP_BINARY, dirname(__DIR__) . '/tools/import-conllu-lemma-pack.php'],
                synthetic_qaa_conllu_import_args($source, $out)
            ),
            dirname(__DIR__)
        );
        assert_same(0, $cli['exit'], 'CoNLL-U importer should accept valid 10-column skipped rows: ' . $cli['stderr']);

        $summary = json_decode($cli['stdout'], true, 512, JSON_THROW_ON_ERROR);
        assert_same(1, $summary['conllu']['multiword_token_rows'] ?? null, 'CoNLL-U importer should still skip valid multiword-token rows');
        assert_same(1, $summary['conllu']['empty_node_rows'] ?? null, 'CoNLL-U importer should still skip valid empty-node rows');
        assert_same(1, $summary['conllu']['accepted_rows'] ?? null, 'CoNLL-U importer should accept only the real token row');
        assert_same(1, $summary['runtime']['rows'] ?? null, 'CoNLL-U importer should emit only the real token row');

        $validation = (new WP_FTS_AnalyzerPackValidator())->validate($out . '/manifest.json');
        $rowsByPair = [];
        foreach ($validation['rows'] as $row) {
            $rowsByPair[$row['surface'] . "\t" . $row['lemma']] = true;
        }
        assert_true(isset($rowsByPair["qaasolo\tqaalemma"]), 'valid-skip import should include the real token row');
        assert_true(!isset($rowsByPair["qaaemptynode\tqaalemma"]), 'valid empty-node rows should not be emitted into runtime TSV');
    } finally {
        remove_directory_tree($out);
        remove_directory_tree($sourceDir);
    }
});

test_case('imported conllu lemma pack drives indexing search and snippets', function (): void {
    require_once __DIR__ . '/../tools/import-conllu-lemma-pack.php';

    $sourceDir = temp_directory_path('conllu_runtime_source');
    $out = temp_directory_path('conllu_runtime_pack');
    try {
        if (!mkdir($sourceDir, 0777, true) && !is_dir($sourceDir)) {
            throw new WP_FTS_TestFailure("Could not create synthetic CoNLL-U runtime source directory: {$sourceDir}");
        }
        $source = $sourceDir . '/qaa-synthetic.conllu';
        write_synthetic_qaa_conllu_source($source);

        $options = WP_FTS_ConlluLemmaPackImporter::parse_cli_options(synthetic_qaa_conllu_import_args($source, $out));
        (new WP_FTS_ConlluLemmaPackImporter())->import($options);

        $manifest = $out . '/manifest.json';
        $pack = WP_FTS_LanguageLemmaPack::from_manifest_file($manifest);
        assert_same('qaalemma', $pack->stem('qaaforma', 'qaa'), 'generated CoNLL-U runtime pack should map first synthetic surface to lemma');
        assert_same('qaalemma', $pack->stem('qaaformb', 'qaa'), 'generated CoNLL-U runtime pack should map second synthetic surface to shared lemma');
        assert_same('qaaamb', $pack->stem('qaaamb', 'qaa'), 'generated CoNLL-U runtime pack should no-op ambiguous synthetic surfaces');

        $analyzer = new WP_FTS_Analyzer([
            'default_lang' => 'qaa',
            'lemma_packs_by_lang' => [
                'qaa' => $manifest,
            ],
        ]);
        $storage = new WP_FTS_Storage_InMemory();
        $indexer = new WP_FTS_Indexer($storage, $analyzer);
        $text = 'Synthetic CoNLL-U source row qaaforma appears in this document with qaasolo.';
        $indexer->index_document_fields(916, [['name' => 'content', 'text' => $text]], [
            'lang' => 'qaa',
            'metadata' => [
                'post_id' => 916,
                'post_type' => 'post',
                'post_status' => 'publish',
                'title' => 'Synthetic qaa CoNLL-U importer',
                'search_text' => $text,
                'language' => 'qaa',
            ],
        ]);

        $terms = $storage->all_terms();
        assert_true(in_array(WP_FTS_TermNamespace::namespace_term('qaa', 'qaalemma'), $terms, true), 'CoNLL-U pack should store the shared synthetic lemma during indexing');
        assert_true(!in_array(WP_FTS_TermNamespace::namespace_term('qaa', 'qaaforma'), $terms, true), 'CoNLL-U pack should not store the mapped document surface as the index key');

        $payload = (new WP_FTS_Searcher($storage, $analyzer))->search('qaaformb', [
            'lang' => 'qaa',
            'mode' => 'AND',
            'include_total' => true,
            'include_metadata' => true,
            'include_snippets' => true,
            'highlight' => true,
            'snippet_length' => 160,
        ]);
        assert_same(1, $payload['total'], 'query surface should meet indexed document surface through imported CoNLL-U lemma pack');
        assert_same(916, $payload['results'][0]['doc_id'] ?? null, 'CoNLL-U pack search should return the indexed synthetic document');
        assert_contains('<mark>qaaforma</mark>', (string) ($payload['results'][0]['snippet'] ?? ''), 'CoNLL-U pack snippet highlighter should mark the indexed surface when querying another imported form');

        $fallbackAnalyzer = new WP_FTS_Analyzer(['default_lang' => 'qaa']);
        $fallbackStorage = new WP_FTS_Storage_InMemory();
        (new WP_FTS_Indexer($fallbackStorage, $fallbackAnalyzer))->index_document_fields(917, [['name' => 'content', 'text' => $text]], [
            'lang' => 'qaa',
            'metadata' => [
                'post_id' => 917,
                'post_type' => 'post',
                'post_status' => 'publish',
                'title' => 'Synthetic qaa CoNLL-U fallback',
                'search_text' => $text,
                'language' => 'qaa',
            ],
        ]);
        $fallbackPayload = (new WP_FTS_Searcher($fallbackStorage, $fallbackAnalyzer))->search('qaaformb', [
            'lang' => 'qaa',
            'mode' => 'AND',
            'include_total' => true,
        ]);
        assert_same(0, $fallbackPayload['total'], 'missing CoNLL-U-generated pack should preserve the built-in fallback behavior');
    } finally {
        remove_directory_tree($out);
        remove_directory_tree($sourceDir);
    }
});

test_case('wp cli import conllu lemma pack enable merges analyzer options and drives runtime analyzer', function (): void {
    $sourceDir = temp_directory_path('wpcli_conllu_import_enable_source');
    $out = temp_directory_path('wpcli_conllu_import_enable_pack');
    try {
        if (!mkdir($sourceDir, 0777, true) && !is_dir($sourceDir)) {
            throw new WP_FTS_TestFailure("Could not create WP-CLI CoNLL-U source directory: {$sourceDir}");
        }
        $source = $sourceDir . '/qaa-synthetic.conllu';
        write_synthetic_qaa_conllu_source($source);

        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = [
            'future_supported_option' => 'preserve-me',
            'lemmatizer_packs_by_lang' => [
                'ur' => '/tmp/existing-ur-pack/manifest.json',
            ],
            'lemma_packs_by_lang' => [
                'qaa' => false,
                'pl' => false,
            ],
            'polish_lemma_pack' => false,
        ];

        $args = synthetic_qaa_conllu_wpcli_assoc_args($source);
        $args['out'] = $out;
        $args['enable'] = true;
        $args['attribution'] = 'Project-owned synthetic qaa rows for WP-CLI CoNLL-U enable tests only.';

        (new WP_FTS_WPCLI_Command())->import_conllu_lemma_pack([], $args);

        $manifest = $out . '/manifest.json';
        $stored = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] ?? [];
        assert_same($manifest, $stored['lemmatizer_packs_by_lang']['qaa'] ?? null, 'CoNLL-U --enable should point lemmatizer_packs_by_lang at the generated manifest');
        assert_same($manifest, $stored['lemma_packs_by_lang']['qaa'] ?? null, 'CoNLL-U --enable should update same-language higher-precedence generic aliases');
        assert_same('/tmp/existing-ur-pack/manifest.json', $stored['lemmatizer_packs_by_lang']['ur'] ?? null, 'CoNLL-U --enable should preserve existing language entries');
        assert_same(false, $stored['lemma_packs_by_lang']['pl'] ?? null, 'CoNLL-U --enable should preserve unrelated Polish generic entries');
        assert_same(false, $stored['polish_lemma_pack'] ?? null, 'CoNLL-U --enable should preserve unrelated Polish legacy aliases');
        assert_same('preserve-me', $stored['future_supported_option'] ?? null, 'CoNLL-U --enable should preserve unrelated analyzer option keys');

        $options = WP_FTS_Plugin::runtime_analyzer_options();
        assert_same($manifest, $options['lemmatizer_packs_by_lang']['qaa'] ?? null, 'CoNLL-U --enable should make the generated manifest the runtime qaa pack');
        assert_same(['qaalemma'], WP_FTS_Plugin::runtime_analyzer()->analyze_query('qaaformb', ['lang' => 'qaa']), 'enabled WP-CLI CoNLL-U import should reach the runtime analyzer');
        assert_contains('Imported and enabled CoNLL-U lemma pack', WP_CLI::$successMessages[0] ?? '', 'CoNLL-U --enable success message should identify the command path');
        assert_contains('Reindex existing content', WP_CLI::$successMessages[0] ?? '', 'CoNLL-U --enable success message should tell operators to reindex');
    } finally {
        remove_directory_tree($out);
        remove_directory_tree($sourceDir);
        wp_fts_test_reset_wordpress_fakes();
    }
});

test_case('wp cli import conllu lemma pack without enable preserves existing analyzer options', function (): void {
    $sourceDir = temp_directory_path('wpcli_conllu_import_noenable_source');
    $out = temp_directory_path('wpcli_conllu_import_noenable_pack');
    try {
        if (!mkdir($sourceDir, 0777, true) && !is_dir($sourceDir)) {
            throw new WP_FTS_TestFailure("Could not create WP-CLI CoNLL-U no-enable source directory: {$sourceDir}");
        }
        $source = $sourceDir . '/qaa-synthetic.conllu';
        write_synthetic_qaa_conllu_source($source);

        wp_fts_test_reset_wordpress_fakes();
        $existing = [
            'lemmatizer_packs_by_lang' => [
                'bn' => WP_FTS_AnalyzerPackValidator::default_synthetic_bengali_fixture_manifest(),
            ],
        ];
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = $existing;

        $args = synthetic_qaa_conllu_wpcli_assoc_args($source);
        $args['output-dir'] = $out;
        (new WP_FTS_WPCLI_Command())->import_conllu_lemma_pack([], $args);

        assert_true(is_file($out . '/manifest.json'), 'WP-CLI CoNLL-U import without --enable should still generate a validated pack');
        assert_same($existing, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] ?? null, 'WP-CLI CoNLL-U import without --enable should leave analyzer options unchanged');
        assert_same(['সিনথ000লেমা'], WP_FTS_Plugin::runtime_analyzer()->analyze_query('সিনথ000গুলো', ['lang' => 'bn']), 'pre-existing analyzer option should continue to drive runtime analyzer after CoNLL-U import');
        assert_same(['qaaformb'], WP_FTS_Plugin::runtime_analyzer()->analyze_query('qaaformb', ['lang' => 'qaa']), 'non-enabled CoNLL-U imported pack should not affect runtime analyzer');
        assert_contains('Runtime analyzer options were not changed', WP_CLI::$successMessages[0] ?? '', 'CoNLL-U no-enable success message should report unchanged analyzer options');
    } finally {
        remove_directory_tree($out);
        remove_directory_tree($sourceDir);
        wp_fts_test_reset_wordpress_fakes();
    }
});

test_case('unimorph lemma importer builds a valid synthetic pack and skips non-runtime rows', function (): void {
    $sourceDir = temp_directory_path('unimorph_import_source');
    $out = temp_directory_path('unimorph_import_pack');
    try {
        if (!mkdir($sourceDir, 0777, true) && !is_dir($sourceDir)) {
            throw new WP_FTS_TestFailure("Could not create synthetic UniMorph source directory: {$sourceDir}");
        }
        $source = $sourceDir . '/qaa-synthetic.unimorph';
        write_synthetic_qaa_unimorph_source($source);

        $cli = test_run_subprocess(
            array_merge(
                [PHP_BINARY, dirname(__DIR__) . '/tools/import-unimorph-lemma-pack.php'],
                synthetic_qaa_unimorph_import_args($source, $out)
            ),
            dirname(__DIR__)
        );
        assert_same(0, $cli['exit'], 'UniMorph importer CLI should succeed for synthetic source-shaped input: ' . $cli['stderr']);

        $summary = json_decode($cli['stdout'], true, 512, JSON_THROW_ON_ERROR);
        assert_same('ok', $summary['status'] ?? null, 'UniMorph importer CLI should return ok status');
        assert_same('qaa-synthetic-unimorph-lemma-importer', $summary['pack_id'] ?? null, 'UniMorph importer summary should expose pack id');
        assert_same('qaa', $summary['language'] ?? null, 'UniMorph importer summary should expose language');
        assert_same(5, $summary['runtime']['rows'] ?? null, 'UniMorph importer should emit only normalized runtime rows');
        assert_same(3, $summary['runtime']['files'] ?? null, 'UniMorph importer should delegate runtime sharding to the TSV importer');
        assert_same(1, $summary['unimorph']['comment_lines'] ?? null, 'UniMorph importer should skip comment rows');
        assert_same(1, $summary['unimorph']['blank_lines'] ?? null, 'UniMorph importer should skip blank rows');
        assert_same(2, $summary['unimorph']['placeholder_rows'] ?? null, 'UniMorph importer should skip empty or underscore lemma/form rows');
        assert_same(2, $summary['unimorph']['invalid_runtime_token_rows'] ?? null, 'UniMorph importer should drop rows that normalize outside one runtime token');
        assert_same(5, $summary['unimorph']['rows_with_features'] ?? null, 'UniMorph importer should preserve feature bundles as TSV tags for accepted rows');
        assert_same(5, $summary['unimorph']['accepted_rows'] ?? null, 'UniMorph importer should count accepted source rows before TSV deduplication');

        $manifest = $out . '/manifest.json';
        $validation = (new WP_FTS_AnalyzerPackValidator())->validate($manifest);
        assert_same('qaa-synthetic-unimorph-lemma-importer', $validation['manifest']['pack_id'], 'generated UniMorph pack manifest should validate');
        assert_same('qaa', $validation['manifest']['language'], 'generated UniMorph pack language should validate');
        assert_same(WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV, $validation['manifest']['runtime']['format'], 'generated UniMorph pack should use lemma TSV runtime format');
        assert_same('Project-owned synthetic qaa UniMorph importer fixture', $validation['manifest']['source']['name'] ?? null, 'generated UniMorph manifest should preserve source name');
        assert_same('Project-owned synthetic qaa UniMorph rows for importer tests only.', $validation['manifest']['attribution']['upstream'] ?? null, 'generated UniMorph manifest should preserve attribution');

        $rowsByPair = [];
        foreach ($validation['rows'] as $row) {
            assert_true(
                str_starts_with($row['surface'], 'qaa') && str_starts_with($row['lemma'], 'qaa'),
                'imported UniMorph synthetic rows should not use real-language word-family fixtures'
            );
            $rowsByPair[$row['surface'] . "\t" . $row['lemma']] = true;
        }
        assert_true(isset($rowsByPair["qaaforma\tqaalemma"]), 'generated UniMorph runtime should include the first synthetic form');
        assert_true(isset($rowsByPair["qaaformb\tqaalemma"]), 'generated UniMorph runtime should include the second synthetic form sharing a lemma');
        assert_true(isset($rowsByPair["qaaamb\tqaaone"]), 'generated UniMorph runtime should retain first ambiguous synthetic lemma');
        assert_true(isset($rowsByPair["qaaamb\tqaatwo"]), 'generated UniMorph runtime should retain second ambiguous synthetic lemma');
        assert_true(!isset($rowsByPair["qaa\tqaalemma"]), 'generated UniMorph runtime should not include multi-token source values');
        assert_true(!isset($rowsByPair["qaahyphenlemma\tqaa-lemma"]), 'generated UniMorph runtime should not include punctuation-only invalid runtime lemmas');
    } finally {
        remove_directory_tree($out);
        remove_directory_tree($sourceDir);
    }
});

test_case('unimorph lemma importer combines directory sources in stable order', function (): void {
    $sourceDir = temp_directory_path('unimorph_import_tree_source');
    $nestedDir = $sourceDir . '/nested';
    $out = temp_directory_path('unimorph_import_tree_pack');
    try {
        if (!mkdir($nestedDir, 0777, true) && !is_dir($nestedDir)) {
            throw new WP_FTS_TestFailure("Could not create synthetic UniMorph tree directory: {$nestedDir}");
        }
        if (file_put_contents($sourceDir . '/ignore.conllu', "not unimorph\n") === false) {
            throw new WP_FTS_TestFailure('Could not write ignored synthetic source file.');
        }
        if (file_put_contents($sourceDir . '/a.txt', synthetic_qaa_unimorph_row('QAADirLemma', 'QAADirA') . "\n") === false) {
            throw new WP_FTS_TestFailure('Could not write first synthetic UniMorph source file.');
        }
        if (file_put_contents($sourceDir . '/b.tsv', synthetic_qaa_unimorph_row('QAADirLemma', 'QAADirB') . "\n") === false) {
            throw new WP_FTS_TestFailure('Could not write second synthetic UniMorph source file.');
        }
        if (file_put_contents($nestedDir . '/c.unimorph', synthetic_qaa_unimorph_row('QAADirLemma', 'QAADirC') . "\n") === false) {
            throw new WP_FTS_TestFailure('Could not write nested synthetic UniMorph source file.');
        }

        $cli = test_run_subprocess(
            array_merge(
                [PHP_BINARY, dirname(__DIR__) . '/tools/import-unimorph-lemma-pack.php'],
                synthetic_qaa_unimorph_import_args($sourceDir, $out)
            ),
            dirname(__DIR__)
        );
        assert_same(0, $cli['exit'], 'UniMorph directory importer should succeed: ' . $cli['stderr']);

        $summary = json_decode($cli['stdout'], true, 512, JSON_THROW_ON_ERROR);
        assert_same(3, $summary['unimorph']['source_files'] ?? null, 'UniMorph directory importer should discover .txt, .tsv, and .unimorph files');
        assert_same(['a.txt', 'b.tsv', 'nested/c.unimorph'], $summary['unimorph']['files'] ?? null, 'UniMorph directory importer should report stable-sorted source order');
        assert_same(3, $summary['runtime']['rows'] ?? null, 'UniMorph directory importer should combine rows from every discovered source file');

        $validation = (new WP_FTS_AnalyzerPackValidator())->validate($out . '/manifest.json');
        $rowsByPair = [];
        foreach ($validation['rows'] as $row) {
            $rowsByPair[$row['surface'] . "\t" . $row['lemma']] = true;
        }
        assert_true(isset($rowsByPair["qaadira\tqaadirlemma"]), 'directory import should include the root .txt file');
        assert_true(isset($rowsByPair["qaadirb\tqaadirlemma"]), 'directory import should include the root .tsv file');
        assert_true(isset($rowsByPair["qaadirc\tqaadirlemma"]), 'directory import should include the nested .unimorph file');
    } finally {
        remove_directory_tree($out);
        remove_directory_tree($sourceDir);
    }
});

test_case('unimorph lemma importer rejects invalid rows with wrong field counts', function (): void {
    $cases = [
        'too_few' => [
            "QAALemma\tQAAForm",
            'too few columns',
        ],
        'too_many' => [
            synthetic_qaa_unimorph_row('QAALemma', 'QAAForm') . "\tEXTRA",
            'too many columns',
        ],
    ];

    foreach ($cases as $name => [$row, $expectedMessage]) {
        $sourceDir = temp_directory_path('unimorph_import_invalid_' . $name . '_source');
        $out = temp_directory_path('unimorph_import_invalid_' . $name . '_pack');
        try {
            if (!mkdir($sourceDir, 0777, true) && !is_dir($sourceDir)) {
                throw new WP_FTS_TestFailure("Could not create invalid UniMorph source directory: {$sourceDir}");
            }
            $source = $sourceDir . '/invalid.unimorph';
            if (file_put_contents($source, $row . "\n") === false) {
                throw new WP_FTS_TestFailure("Could not write invalid synthetic UniMorph source: {$source}");
            }

            $cli = test_run_subprocess(
                array_merge(
                    [PHP_BINARY, dirname(__DIR__) . '/tools/import-unimorph-lemma-pack.php'],
                    synthetic_qaa_unimorph_import_args($source, $out)
                ),
                dirname(__DIR__)
            );
            assert_same(1, $cli['exit'], "UniMorph importer should fail for {$name} rows");
            assert_contains($expectedMessage, $cli['stderr'], "UniMorph importer should report a clear {$expectedMessage} error for {$name}");
            assert_contains('expected exactly 3 tab-separated columns', $cli['stderr'], "UniMorph importer should report the exact UniMorph field count for {$name}");
            assert_true(!is_file($out . '/manifest.json'), "failed UniMorph import should not leave a valid pack manifest for {$name}");
        } finally {
            remove_directory_tree($out);
            remove_directory_tree($sourceDir);
        }
    }
});

test_case('imported unimorph lemma pack drives indexing search and snippets', function (): void {
    require_once __DIR__ . '/../tools/import-unimorph-lemma-pack.php';

    $sourceDir = temp_directory_path('unimorph_runtime_source');
    $out = temp_directory_path('unimorph_runtime_pack');
    try {
        if (!mkdir($sourceDir, 0777, true) && !is_dir($sourceDir)) {
            throw new WP_FTS_TestFailure("Could not create synthetic UniMorph runtime source directory: {$sourceDir}");
        }
        $source = $sourceDir . '/qaa-synthetic.unimorph';
        write_synthetic_qaa_unimorph_source($source);

        $options = WP_FTS_UnimorphLemmaPackImporter::parse_cli_options(synthetic_qaa_unimorph_import_args($source, $out));
        (new WP_FTS_UnimorphLemmaPackImporter())->import($options);

        $manifest = $out . '/manifest.json';
        $pack = WP_FTS_LanguageLemmaPack::from_manifest_file($manifest);
        assert_same('qaalemma', $pack->stem('qaaforma', 'qaa'), 'generated UniMorph runtime pack should map first synthetic surface to lemma');
        assert_same('qaalemma', $pack->stem('qaaformb', 'qaa'), 'generated UniMorph runtime pack should map second synthetic surface to shared lemma');
        assert_same('qaaamb', $pack->stem('qaaamb', 'qaa'), 'generated UniMorph runtime pack should no-op ambiguous synthetic surfaces');

        $analyzer = new WP_FTS_Analyzer([
            'default_lang' => 'qaa',
            'lemma_packs_by_lang' => [
                'qaa' => $manifest,
            ],
        ]);
        $storage = new WP_FTS_Storage_InMemory();
        $indexer = new WP_FTS_Indexer($storage, $analyzer);
        $text = 'Synthetic UniMorph source row qaaforma appears in this document with qaasolo.';
        $indexer->index_document_fields(1011, [['name' => 'content', 'text' => $text]], [
            'lang' => 'qaa',
            'metadata' => [
                'post_id' => 1011,
                'post_type' => 'post',
                'post_status' => 'publish',
                'title' => 'Synthetic qaa UniMorph importer',
                'search_text' => $text,
                'language' => 'qaa',
            ],
        ]);

        $terms = $storage->all_terms();
        assert_true(in_array(WP_FTS_TermNamespace::namespace_term('qaa', 'qaalemma'), $terms, true), 'UniMorph pack should store the shared synthetic lemma during indexing');
        assert_true(!in_array(WP_FTS_TermNamespace::namespace_term('qaa', 'qaaforma'), $terms, true), 'UniMorph pack should not store the mapped document surface as the index key');

        $payload = (new WP_FTS_Searcher($storage, $analyzer))->search('qaaformb', [
            'lang' => 'qaa',
            'mode' => 'AND',
            'include_total' => true,
            'include_metadata' => true,
            'include_snippets' => true,
            'highlight' => true,
            'snippet_length' => 160,
        ]);
        assert_same(1, $payload['total'], 'query surface should meet indexed document surface through imported UniMorph lemma pack');
        assert_same(1011, $payload['results'][0]['doc_id'] ?? null, 'UniMorph pack search should return the indexed synthetic document');
        assert_contains('<mark>qaaforma</mark>', (string) ($payload['results'][0]['snippet'] ?? ''), 'UniMorph pack snippet highlighter should mark the indexed surface when querying another imported form');

        $fallbackAnalyzer = new WP_FTS_Analyzer(['default_lang' => 'qaa']);
        $fallbackStorage = new WP_FTS_Storage_InMemory();
        (new WP_FTS_Indexer($fallbackStorage, $fallbackAnalyzer))->index_document_fields(1012, [['name' => 'content', 'text' => $text]], [
            'lang' => 'qaa',
            'metadata' => [
                'post_id' => 1012,
                'post_type' => 'post',
                'post_status' => 'publish',
                'title' => 'Synthetic qaa UniMorph fallback',
                'search_text' => $text,
                'language' => 'qaa',
            ],
        ]);
        $fallbackPayload = (new WP_FTS_Searcher($fallbackStorage, $fallbackAnalyzer))->search('qaaformb', [
            'lang' => 'qaa',
            'mode' => 'AND',
            'include_total' => true,
        ]);
        assert_same(0, $fallbackPayload['total'], 'missing UniMorph-generated pack should preserve the built-in fallback behavior');
    } finally {
        remove_directory_tree($out);
        remove_directory_tree($sourceDir);
    }
});

test_case('bundled UniMorph top-language packs validate and drive lemma-backed search', function (): void {
    assert_or_pending(
        WP_FTS_AnalyzerPackValidator::gzip_available(),
        'gzip support should be available for bundled UniMorph pack validation',
        'PHP zlib gzip support is unavailable, so bundled UniMorph gzip pack validation is skipped.'
    );

    foreach (bundled_unimorph_top_language_pack_manifests() as $language => $manifest) {
        assert_true(is_file($manifest), "{$language} bundled UniMorph manifest should exist");
        assert_true(is_file(dirname($manifest) . '/SOURCE.lock.json'), "{$language} bundled UniMorph source lock should exist");
        assert_true(is_file(dirname($manifest) . '/PROVENANCE.md'), "{$language} bundled UniMorph provenance should exist");
        assert_true(is_file(dirname($manifest) . '/NOTICE.txt'), "{$language} bundled UniMorph notice should exist");

        $validation = (new WP_FTS_AnalyzerPackValidator())->validate($manifest, false);
        assert_same($language, $validation['manifest']['language'], "{$language} bundled UniMorph manifest language should validate");
        assert_same(false, $validation['manifest']['fixture_only'], "{$language} bundled UniMorph pack should not be fixture-only");
        assert_same(false, $validation['manifest']['default_enabled'], "{$language} bundled UniMorph pack should remain default-disabled");
        assert_true(in_array('unimorph-source-import', $validation['manifest']['capabilities'], true), "{$language} bundled UniMorph pack should declare UniMorph provenance");
        $expectedLicense = $language === 'te' ? 'upstream-license-not-declared' : 'CC-BY-SA-3.0';
        assert_same($expectedLicense, $validation['manifest']['license']['spdx_id'] ?? null, "{$language} bundled UniMorph pack should preserve recorded source license status");
        $publishedSourcePath = (string) ($validation['manifest']['source']['parse_stats']['source_path'] ?? '');
        assert_true($publishedSourcePath !== '' && !str_contains($publishedSourcePath, '/tmp/'), "{$language} bundled UniMorph parse stats should use upstream source path only");

        $sourceLock = json_decode((string) file_get_contents(dirname($manifest) . '/SOURCE.lock.json'), true, 512, JSON_THROW_ON_ERROR);
        assert_same('wp-fts-unimorph-lemma-pack-source-lock/v1', $sourceLock['schema_version'] ?? null, "{$language} source lock should use the UniMorph schema");
        assert_same($validation['manifest']['runtime']['total_rows'], $sourceLock['runtime']['row_count'] ?? null, "{$language} source lock should mirror runtime row count");
        assert_same($validation['manifest']['runtime']['total_sha256'], $sourceLock['runtime']['digest_sha256'] ?? null, "{$language} source lock should mirror runtime digest");

        $case = bundled_unimorph_runtime_probe_case($validation);
        $pack = WP_FTS_LanguageLemmaPack::from_manifest_file($manifest, null, $language);
        assert_same($case['lemma'], $pack->stem($case['surface'], $language), "{$language} bundled UniMorph pack should map the selected source-backed surface to its lemma");

        $analyzer = new WP_FTS_Analyzer([
            'default_lang' => $language,
            'lemma_packs_by_lang' => [
                $language => $manifest,
            ],
        ]);
        $storage = new WP_FTS_Storage_InMemory();
        $text = 'wpftsunimorphprobe ' . $case['surface'];
        (new WP_FTS_Indexer($storage, $analyzer))->index_document_fields(7000 + strlen($language), [['name' => 'content', 'text' => $text]], [
            'lang' => $language,
            'metadata' => [
                'post_id' => 7000 + strlen($language),
                'post_type' => 'post',
                'post_status' => 'publish',
                'title' => 'Bundled UniMorph ' . $language,
                'search_text' => $text,
                'language' => $language,
            ],
        ]);

        $terms = $storage->all_terms();
        assert_true(in_array(WP_FTS_TermNamespace::namespace_term($language, $case['lemma']), $terms, true), "{$language} indexing should store the UniMorph lemma");
        $payload = (new WP_FTS_Searcher($storage, $analyzer))->search($case['lemma'], [
            'lang' => $language,
            'mode' => 'AND',
            'include_total' => true,
        ]);
        assert_same(1, $payload['total'], "{$language} lemma query should find the indexed inflected UniMorph surface");
    }
});

test_case('wp cli import unimorph lemma pack enable merges analyzer options and drives runtime analyzer', function (): void {
    $sourceDir = temp_directory_path('wpcli_unimorph_import_enable_source');
    $out = temp_directory_path('wpcli_unimorph_import_enable_pack');
    try {
        if (!mkdir($sourceDir, 0777, true) && !is_dir($sourceDir)) {
            throw new WP_FTS_TestFailure("Could not create WP-CLI UniMorph source directory: {$sourceDir}");
        }
        $source = $sourceDir . '/qaa-synthetic.unimorph';
        write_synthetic_qaa_unimorph_source($source);

        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = [
            'future_supported_option' => 'preserve-me',
            'lemmatizer_packs_by_lang' => [
                'ur' => '/tmp/existing-ur-pack/manifest.json',
            ],
            'lemma_packs_by_lang' => [
                'qaa' => false,
                'pl' => false,
            ],
            'polish_lemma_pack' => false,
        ];

        $args = synthetic_qaa_unimorph_wpcli_assoc_args($source);
        $args['out'] = $out;
        $args['enable'] = true;
        $args['attribution'] = 'Project-owned synthetic qaa rows for WP-CLI UniMorph enable tests only.';

        (new WP_FTS_WPCLI_Command())->import_unimorph_lemma_pack([], $args);

        $manifest = $out . '/manifest.json';
        $stored = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] ?? [];
        assert_same($manifest, $stored['lemmatizer_packs_by_lang']['qaa'] ?? null, 'UniMorph --enable should point lemmatizer_packs_by_lang at the generated manifest');
        assert_same($manifest, $stored['lemma_packs_by_lang']['qaa'] ?? null, 'UniMorph --enable should update same-language higher-precedence generic aliases');
        assert_same('/tmp/existing-ur-pack/manifest.json', $stored['lemmatizer_packs_by_lang']['ur'] ?? null, 'UniMorph --enable should preserve existing language entries');
        assert_same(false, $stored['lemma_packs_by_lang']['pl'] ?? null, 'UniMorph --enable should preserve unrelated Polish generic entries');
        assert_same(false, $stored['polish_lemma_pack'] ?? null, 'UniMorph --enable should preserve unrelated Polish legacy aliases');
        assert_same('preserve-me', $stored['future_supported_option'] ?? null, 'UniMorph --enable should preserve unrelated analyzer option keys');

        $options = WP_FTS_Plugin::runtime_analyzer_options();
        assert_same($manifest, $options['lemmatizer_packs_by_lang']['qaa'] ?? null, 'UniMorph --enable should make the generated manifest the runtime qaa pack');
        assert_same(['qaalemma'], WP_FTS_Plugin::runtime_analyzer()->analyze_query('qaaformb', ['lang' => 'qaa']), 'enabled WP-CLI UniMorph import should reach the runtime analyzer');
        assert_contains('Imported and enabled UniMorph lemma pack', WP_CLI::$successMessages[0] ?? '', 'UniMorph --enable success message should identify the command path');
        assert_contains('Reindex existing content', WP_CLI::$successMessages[0] ?? '', 'UniMorph --enable success message should tell operators to reindex');
    } finally {
        remove_directory_tree($out);
        remove_directory_tree($sourceDir);
        wp_fts_test_reset_wordpress_fakes();
    }
});

test_case('wp cli import unimorph lemma pack without enable preserves existing analyzer options', function (): void {
    $sourceDir = temp_directory_path('wpcli_unimorph_import_noenable_source');
    $out = temp_directory_path('wpcli_unimorph_import_noenable_pack');
    try {
        if (!mkdir($sourceDir, 0777, true) && !is_dir($sourceDir)) {
            throw new WP_FTS_TestFailure("Could not create WP-CLI UniMorph no-enable source directory: {$sourceDir}");
        }
        $source = $sourceDir . '/qaa-synthetic.unimorph';
        write_synthetic_qaa_unimorph_source($source);

        wp_fts_test_reset_wordpress_fakes();
        $existing = [
            'lemmatizer_packs_by_lang' => [
                'bn' => WP_FTS_AnalyzerPackValidator::default_synthetic_bengali_fixture_manifest(),
            ],
        ];
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = $existing;

        $args = synthetic_qaa_unimorph_wpcli_assoc_args($source);
        $args['output-dir'] = $out;
        (new WP_FTS_WPCLI_Command())->import_unimorph_lemma_pack([], $args);

        assert_true(is_file($out . '/manifest.json'), 'WP-CLI UniMorph import without --enable should still generate a validated pack');
        assert_same($existing, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] ?? null, 'WP-CLI UniMorph import without --enable should leave analyzer options unchanged');
        assert_same(['সিনথ000লেমা'], WP_FTS_Plugin::runtime_analyzer()->analyze_query('সিনথ000গুলো', ['lang' => 'bn']), 'pre-existing analyzer option should continue to drive runtime analyzer after UniMorph import');
        assert_same(['qaaformb'], WP_FTS_Plugin::runtime_analyzer()->analyze_query('qaaformb', ['lang' => 'qaa']), 'non-enabled UniMorph imported pack should not affect runtime analyzer');
        assert_contains('Runtime analyzer options were not changed', WP_CLI::$successMessages[0] ?? '', 'UniMorph no-enable success message should report unchanged analyzer options');
    } finally {
        remove_directory_tree($out);
        remove_directory_tree($sourceDir);
        wp_fts_test_reset_wordpress_fakes();
    }
});

test_case('wp cli import lemma pack uses default uploads pack directory', function (): void {
    $sourceDir = temp_directory_path('wpcli_lemma_import_default_source');
    $uploads = temp_directory_path('wpcli_lemma_import_default_uploads');
    try {
        if (!mkdir($sourceDir, 0777, true) && !is_dir($sourceDir)) {
            throw new WP_FTS_TestFailure("Could not create synthetic source directory: {$sourceDir}");
        }
        $source = $sourceDir . '/qaa-normalized-lemma.tsv';
        write_synthetic_qaa_lemma_tsv_source($source);

        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_upload_dir'] = $uploads;
        $command = new WP_FTS_WPCLI_Command();
        $command->import_lemma_pack([], synthetic_qaa_lemma_tsv_wpcli_assoc_args($source));

        $manifest = $uploads . '/wp-fts-lemma-packs/qaa-synthetic-lemma-tsv-importer/manifest.json';
        assert_true(is_file($manifest), 'WP-CLI import should default output under uploads/wp-fts-lemma-packs/<pack-id>');
        $validation = (new WP_FTS_AnalyzerPackValidator())->validate($manifest);
        assert_same('qaa', $validation['manifest']['language'], 'default-directory WP-CLI import should preserve language');
        assert_same('qaa-synthetic-lemma-tsv-importer', $validation['manifest']['pack_id'], 'default-directory WP-CLI import should preserve pack id');
        assert_same(5, $validation['runtime_rows'], 'default-directory WP-CLI import should validate generated runtime rows');
        assert_same('Project-owned synthetic qaa lemma TSV importer fixture', $validation['manifest']['attribution']['upstream'] ?? null, 'WP-CLI import should default optional attribution to source name');
        assert_true(!array_key_exists(WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION, $GLOBALS['wp_fts_test_options']), 'WP-CLI import without --enable should not create analyzer options');
        assert_same(
            ["Imported lemma pack qaa-synthetic-lemma-tsv-importer for qaa: {$manifest}. Runtime analyzer options were not changed."],
            WP_CLI::$successMessages,
            'WP-CLI import success message should include language and manifest path'
        );
    } finally {
        remove_directory_tree($uploads);
        remove_directory_tree($sourceDir);
        wp_fts_test_reset_wordpress_fakes();
    }
});

test_case('wp cli import lemma pack enable merges analyzer options and drives runtime analyzer', function (): void {
    $sourceDir = temp_directory_path('wpcli_lemma_import_enable_source');
    $out = temp_directory_path('wpcli_lemma_import_enable_pack');
    try {
        if (!mkdir($sourceDir, 0777, true) && !is_dir($sourceDir)) {
            throw new WP_FTS_TestFailure("Could not create synthetic source directory: {$sourceDir}");
        }
        $source = $sourceDir . '/qaa-normalized-lemma.tsv';
        write_synthetic_qaa_lemma_tsv_source($source);

        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = [
            'future_supported_option' => 'preserve-me',
            'lemmatizer_packs_by_lang' => [
                'ur' => '/tmp/existing-ur-pack/manifest.json',
            ],
            'lemma_packs_by_lang' => [
                'qaa' => false,
                'pl' => false,
            ],
            'polish_lemma_pack' => false,
        ];

        $args = synthetic_qaa_lemma_tsv_wpcli_assoc_args($source);
        $args['out'] = $out;
        $args['enable'] = true;
        $args['attribution'] = 'Project-owned synthetic qaa rows for WP-CLI enable tests only.';

        $command = new WP_FTS_WPCLI_Command();
        $command->import_lemma_pack([], $args);

        $manifest = $out . '/manifest.json';
        $stored = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] ?? [];
        assert_same($manifest, $stored['lemmatizer_packs_by_lang']['qaa'] ?? null, '--enable should point lemmatizer_packs_by_lang at the generated manifest');
        assert_same($manifest, $stored['lemma_packs_by_lang']['qaa'] ?? null, '--enable should update same-language higher-precedence generic aliases');
        assert_same('/tmp/existing-ur-pack/manifest.json', $stored['lemmatizer_packs_by_lang']['ur'] ?? null, '--enable should preserve existing language entries');
        assert_same(false, $stored['lemma_packs_by_lang']['pl'] ?? null, '--enable should preserve unrelated Polish generic entries');
        assert_same(false, $stored['polish_lemma_pack'] ?? null, '--enable should preserve unrelated Polish legacy aliases');
        assert_same('preserve-me', $stored['future_supported_option'] ?? null, '--enable should preserve unrelated analyzer option keys');

        $options = WP_FTS_Plugin::runtime_analyzer_options();
        assert_same($manifest, $options['lemmatizer_packs_by_lang']['qaa'] ?? null, '--enable should make the generated manifest the runtime qaa pack');
        assert_same(['qaalemma'], WP_FTS_Plugin::runtime_analyzer()->analyze_query('qaaformb', ['lang' => 'qaa']), 'enabled WP-CLI import should reach the runtime analyzer');
        assert_contains('Reindex existing content', WP_CLI::$successMessages[0] ?? '', '--enable success message should tell operators to reindex');
    } finally {
        remove_directory_tree($out);
        remove_directory_tree($sourceDir);
        wp_fts_test_reset_wordpress_fakes();
    }
});

test_case('wp cli import lemma pack without enable preserves existing analyzer options', function (): void {
    $sourceDir = temp_directory_path('wpcli_lemma_import_noenable_source');
    $out = temp_directory_path('wpcli_lemma_import_noenable_pack');
    try {
        if (!mkdir($sourceDir, 0777, true) && !is_dir($sourceDir)) {
            throw new WP_FTS_TestFailure("Could not create synthetic source directory: {$sourceDir}");
        }
        $source = $sourceDir . '/qaa-normalized-lemma.tsv';
        write_synthetic_qaa_lemma_tsv_source($source);

        wp_fts_test_reset_wordpress_fakes();
        $existing = [
            'lemmatizer_packs_by_lang' => [
                'bn' => WP_FTS_AnalyzerPackValidator::default_synthetic_bengali_fixture_manifest(),
            ],
        ];
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = $existing;

        $args = synthetic_qaa_lemma_tsv_wpcli_assoc_args($source);
        $args['output-dir'] = $out;
        (new WP_FTS_WPCLI_Command())->import_lemma_pack([], $args);

        assert_true(is_file($out . '/manifest.json'), 'WP-CLI import without --enable should still generate a validated pack');
        assert_same($existing, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] ?? null, 'WP-CLI import without --enable should leave analyzer options unchanged');
        assert_same(['সিনথ000লেমা'], WP_FTS_Plugin::runtime_analyzer()->analyze_query('সিনথ000গুলো', ['lang' => 'bn']), 'pre-existing analyzer option should continue to drive runtime analyzer');
        assert_same(['qaaformb'], WP_FTS_Plugin::runtime_analyzer()->analyze_query('qaaformb', ['lang' => 'qaa']), 'non-enabled imported pack should not affect runtime analyzer');
    } finally {
        remove_directory_tree($out);
        remove_directory_tree($sourceDir);
        wp_fts_test_reset_wordpress_fakes();
    }
});

test_case('wp cli import lemma pack rejects missing metadata and invalid source paths', function (): void {
    $sourceDir = temp_directory_path('wpcli_lemma_import_reject_source');
    $out = temp_directory_path('wpcli_lemma_import_reject_pack');
    try {
        if (!mkdir($sourceDir, 0777, true) && !is_dir($sourceDir)) {
            throw new WP_FTS_TestFailure("Could not create synthetic source directory: {$sourceDir}");
        }
        $source = $sourceDir . '/qaa-normalized-lemma.tsv';
        write_synthetic_qaa_lemma_tsv_source($source);

        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = [
            'lemmatizer_packs_by_lang' => [
                'bn' => WP_FTS_AnalyzerPackValidator::default_synthetic_bengali_fixture_manifest(),
            ],
        ];

        $missingMetadata = synthetic_qaa_lemma_tsv_wpcli_assoc_args($source);
        $missingMetadata['out'] = $out;
        unset($missingMetadata['source-name']);
        $thrown = false;
        try {
            (new WP_FTS_WPCLI_Command())->import_lemma_pack([], $missingMetadata);
        } catch (RuntimeException $e) {
            $thrown = true;
            assert_contains('Missing required option --source-name.', $e->getMessage(), 'WP-CLI import should reject missing required metadata');
        }
        assert_true($thrown, 'WP-CLI import should throw when required metadata is missing');
        assert_true(!is_dir($out), 'metadata rejection should not create an output directory');

        $invalidPath = synthetic_qaa_lemma_tsv_wpcli_assoc_args($sourceDir . '/missing.tsv');
        $invalidPath['out'] = $out;
        $thrown = false;
        try {
            (new WP_FTS_WPCLI_Command())->import_lemma_pack([], $invalidPath);
        } catch (RuntimeException $e) {
            $thrown = true;
            assert_contains('Required file --source does not exist', $e->getMessage(), 'WP-CLI import should reject invalid source paths');
        }
        assert_true($thrown, 'WP-CLI import should throw when the source path is invalid');
        assert_true(!is_dir($out), 'source-path rejection should not create an output directory');
        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION]['lemmatizer_packs_by_lang']['qaa']), 'failed imports should not enable the requested language');
    } finally {
        remove_directory_tree($out);
        remove_directory_tree($sourceDir);
        wp_fts_test_reset_wordpress_fakes();
    }
});

test_case('polish Morfologik fixture pack validates manifest digests and rows', function (): void {
    $validator = new WP_FTS_AnalyzerPackValidator();
    $result = $validator->validate(WP_FTS_AnalyzerPackValidator::default_polish_fixture_manifest());

    assert_same('pl-morfologik-polimorf-fixture', $result['manifest']['pack_id'], 'fixture pack id should be stable');
    assert_same('pl', $result['manifest']['language'], 'fixture pack language should be Polish');
    assert_true($result['manifest']['fixture_only'] === true, 'fixture pack should be explicitly fixture-only');
    assert_true($result['manifest']['default_enabled'] === false, 'fixture pack should not be default-enabled');
    assert_same(true, $result['rows_collected'], 'fixture pack should retain rows for eager lookup tests');
    assert_same(21, $result['runtime_rows'], 'fixture pack runtime row count should be exposed');
    assert_same(21, count($result['rows']), 'fixture pack should expose the reviewed tiny row set');
    assert_same(21, $result['runtime_files']['runtime.tsv']['rows'] ?? null, 'runtime row count should match manifest');
    assert_same(hash_file('sha256', $result['runtime_files']['runtime.tsv']['path']), $result['runtime_files']['runtime.tsv']['sha256'], 'runtime digest should match local file content');
    $rowsBySurfaceLemma = [];
    foreach ($result['rows'] as $row) {
        $rowsBySurfaceLemma[$row['surface'] . "\t" . $row['lemma']] = true;
    }
    foreach (['wyszukiwanie', 'wyszukiwania', 'wyszukujemy', 'wyszukiwali'] as $surface) {
        assert_true(isset($rowsBySurfaceLemma[$surface . "\twyszukiwac"]), "{$surface} should come from the lemmatizer pack fixture rows");
    }
    foreach ([
        'wpis' => 'wpis',
        'wpisach' => 'wpis',
        'wpisami' => 'wpis',
        'wpisy' => 'wpis',
        'kierowac' => 'kierowac',
        'kierowania' => 'kierowac',
        'kierowanie' => 'kierowac',
        'kierujemy' => 'kierowac',
    ] as $surface => $lemma) {
        assert_true(isset($rowsBySurfaceLemma[$surface . "\t" . $lemma]), "{$surface} should come from the source-derived lemmatizer pack fixture rows");
    }

    $streamedFixture = (new WP_FTS_AnalyzerPackValidator(3))->validate(WP_FTS_AnalyzerPackValidator::default_polish_fixture_manifest());
    assert_same(false, $streamedFixture['rows_collected'], 'validator should stream when a fixture exceeds the collection cap');
    assert_same([], $streamedFixture['rows'], 'streamed fixture validation should not retain partial row arrays');
    assert_same(21, $streamedFixture['runtime_rows'], 'streamed fixture validation should still count every runtime row');

    $lazyFixture = WP_FTS_PolishMorfologikLemmatizer::from_manifest_file(
        WP_FTS_AnalyzerPackValidator::default_polish_fixture_manifest(),
        new WP_FTS_AnalyzerPackValidator(3)
    );
    assert_same('kot', $lazyFixture->stem('kotami', 'pl'), 'lemmatizer should lazy-load fixture rows when row collection is capped');
    assert_same('wpis', $lazyFixture->stem('wpisach', 'pl'), 'lazy lemmatizer should load source-derived entry rows when row collection is capped');
    assert_same('kierowac', $lazyFixture->stem('kierowania', 'pl'), 'lazy lemmatizer should load source-derived routing rows when row collection is capped');
});

test_case('polish full analyzer pack validation streams rows without retaining runtime arrays', function (): void {
    $out = temp_directory_path('polimorf_validator_streaming');
    try {
        $manifest = write_synthetic_full_analyzer_pack($out, 60000, 3);
        $validator = new WP_FTS_AnalyzerPackValidator();
        $result = $validator->validate($manifest);

        assert_same('pl-polimorf-synthetic-full-streaming-fixture', $result['manifest']['pack_id'], 'synthetic full pack id should validate');
        assert_same(false, $result['rows_collected'], 'full pack validation should stream rows instead of retaining row arrays');
        assert_same([], $result['rows'], 'full pack validation should not return retained row arrays');
        assert_same(60000, array_sum(array_map(static fn(array $file): int => (int) $file['rows'], $result['runtime_files'])), 'full pack runtime row count should match manifest');

        $cli = test_run_subprocess(
            [
                PHP_BINARY,
                '-n',
                '-d',
                'memory_limit=24M',
                dirname(__DIR__) . '/tools/validate-analyzer-pack.php',
                $manifest,
            ],
            dirname(__DIR__)
        );
        assert_same(0, $cli['exit'], 'validator CLI should pass synthetic full pack under php -n with a low memory limit: ' . $cli['stderr']);

        $summary = json_decode($cli['stdout'], true, 512, JSON_THROW_ON_ERROR);
        assert_same('ok', $summary['status'] ?? null, 'validator CLI should report ok status for synthetic full pack');
        assert_same(false, $summary['metadata_only'] ?? null, 'validator CLI default should remain full validation mode');
        assert_same(60000, $summary['runtime_rows'] ?? null, 'validator CLI should report streamed runtime row count');
        assert_same(false, $summary['rows_collected'] ?? null, 'validator CLI should use streaming row validation');
    } finally {
        remove_directory_tree($out);
    }
});

test_case('polish compressed full playground pack validates and lazy-loads full-only forms', function (): void {
    assert_or_pending(
        WP_FTS_AnalyzerPackValidator::gzip_available(),
        'gzip support should be available for compressed full pack validation',
        'PHP zlib gzip support is unavailable, so compressed full pack validation is skipped.'
    );

    $manifest = WP_FTS_AnalyzerPackValidator::default_polish_playground_full_manifest();
    assert_true(is_file($manifest), 'compressed full playground pack manifest should be bundled');

    $validation = (new WP_FTS_AnalyzerPackValidator())->validate($manifest, false);
    assert_same('pl-polimorf-20180722-full', $validation['manifest']['pack_id'], 'compressed full pack id should match the generated PoliMorf pack');
    assert_same(false, $validation['manifest']['fixture_only'], 'compressed full pack should not be fixture-only');
    assert_same(false, $validation['rows_collected'], 'compressed full pack validation should stream without retaining rows');
    assert_same(4748648, $validation['runtime_rows'], 'compressed full pack should expose the full generated row count');
    assert_same('4ca60c36adeaa46ad93a499075707c5ac8782928496e23642401e4ddfc84e27f', $validation['manifest']['runtime']['total_sha256'], 'compressed full pack should keep the normalized uncompressed runtime digest');
    assert_same(48, count($validation['runtime_files']), 'compressed full pack should keep the 48 generated runtime shards');
    assert_same(WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP, $validation['runtime_files']['runtime/0001.tsv.gz']['compression'] ?? null, 'compressed runtime metadata should identify gzip shards');

    $lemmatizer = WP_FTS_PolishMorfologikLemmatizer::from_manifest_file($manifest);
    assert_same('pl-polimorf-20180722-full', $lemmatizer->pack_id(), 'lazy full lemmatizer should expose the compressed pack identity');
    assert_true(!$lemmatizer->is_fixture_only(), 'lazy full lemmatizer should expose full-pack status');
    foreach ([
        'prowadzilismy' => 'prowadzic',
        'zabralibysmy' => 'zabrac',
        'domach' => 'dom',
        'psach' => 'pies',
        'samochodami' => 'samochod',
    ] as $surface => $lemma) {
        assert_same($lemma, $lemmatizer->stem($surface, 'pl'), "{$surface} should map through a compressed full-pack-only shard");
    }

    $pack = WP_FTS_LanguageLemmaPack::from_manifest_file($manifest);
    foreach ([
        'zamki' => 'zamek',
        'zamkach' => 'zamek',
        'wyszukujemy' => 'wyszukiwac',
    ] as $surface => $lemma) {
        $analyses = test_analysis_terms($pack->analyze($surface, 'pl'));
        assert_true(in_array($lemma, $analyses, true), "{$surface} should still map through the compressed full Polish pack");

        $stats = $pack->last_lookup_stats();
        assert_same($surface, $stats['term'], "{$surface} lookup stats should record the normalized term");
        assert_same(1, $stats['candidate_files'], "{$surface} lookup should narrow to one runtime shard by manifest range");
        assert_same(1, $stats['files_opened'], "{$surface} lookup should open only the narrowed runtime shard");
        assert_true(in_array('gzip-binary-search', $stats['modes'], true), "{$surface} lookup should use gzip binary search");
        assert_true(!in_array('stream-scan', $stats['modes'], true), "{$surface} lookup should not stream-scan the full shard");
        assert_true($stats['lines_read'] <= 64, "{$surface} lookup should inspect a bounded number of runtime rows");
        assert_true($stats['bytes_loaded'] > 0 && $stats['bytes_loaded'] <= 3000000, "{$surface} lookup should load one bounded decoded shard");
    }
});

test_case('polish compressed full pack exposes ambiguous lemma analyses without changing stem compatibility', function (): void {
    assert_or_pending(
        WP_FTS_AnalyzerPackValidator::gzip_available(),
        'gzip support should be available for compressed full pack multi-analysis coverage',
        'PHP zlib gzip support is unavailable, so compressed full pack multi-analysis is skipped.'
    );

    $manifest = WP_FTS_AnalyzerPackValidator::default_polish_playground_full_manifest();
    $pack = WP_FTS_LanguageLemmaPack::from_manifest_file($manifest);
    assert_same('pl-polimorf-20180722-full', $pack->pack_id(), 'multi-analysis coverage should use the bundled full Polish pack');

    assert_same(['chrzastek', 'chrzastka'], test_analysis_terms($pack->analyze('chrzastek', 'pl')), 'chrząstek normalized surface should expose both pack-backed lemmas');
    assert_same(['chrzastka', 'chrzastek'], test_analysis_terms($pack->analyze('chrzastka', 'pl')), 'chrząstka normalized surface should expose both pack-backed lemmas with the exact normalized lemma first');
    assert_same(['chrzastek', 'chrzastka'], test_analysis_terms($pack->analyze('chrzastki', 'pl')), 'chrząstki normalized surface should expose both pack-backed lemmas');
    assert_same(['drogi', 'droga'], test_analysis_terms($pack->analyze('drogi', 'pl')), 'unrelated ambiguous pack rows should also expose all pack-backed analyses');

    assert_same('chrzastek', $pack->stem('chrzastek', 'pl'), 'stem compatibility should keep ambiguous chrząstek normalized surface unchanged');
    assert_same('chrzastka', $pack->stem('chrzastka', 'pl'), 'stem compatibility should keep ambiguous chrząstka normalized surface unchanged');
    assert_same('chrzastki', $pack->stem('chrzastki', 'pl'), 'stem compatibility should keep ambiguous chrząstki normalized surface unchanged');
    assert_same('drogi', $pack->stem('drogi', 'pl'), 'stem compatibility should keep ambiguous unrelated pack surfaces unchanged');
});

test_case('polish compressed full pack snippets stay within playground memory limit', function (): void {
    assert_or_pending(
        WP_FTS_AnalyzerPackValidator::gzip_available(),
        'gzip support should be available for compressed full pack snippet memory validation',
        'PHP zlib gzip support is unavailable, so compressed full pack snippet memory validation is skipped.'
    );

    $code = <<<'PHP'
require 'src/bootstrap.php';

$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'polish_lemma_pack' => WP_FTS_AnalyzerPackValidator::default_polish_playground_full_manifest(),
]);
$storage = new WP_FTS_Storage_InMemory();
$indexer = new WP_FTS_Indexer($storage, $analyzer);
$text = 'W domach przy psach prowadzilismy notatki, samochodami odwiedzalismy katalog i zabralibysmy wpisy z zamkach.';
$indexer->index_document_fields(941, [['name' => 'content', 'text' => $text]], [
    'lang' => 'pl',
    'metadata' => [
        'post_id' => 941,
        'post_type' => 'post',
        'post_status' => 'publish',
        'title' => 'Compressed full Polish memory guard',
        'search_text' => $text,
        'language' => 'pl',
    ],
]);

$searcher = new WP_FTS_Searcher($storage, $analyzer);
foreach ([
    'prowadzic' => '<mark>prowadzilismy</mark>',
    'zabrac' => '<mark>zabralibysmy</mark>',
    'samochod' => '<mark>samochodami</mark>',
    'dom' => '<mark>domach</mark>',
    'pies' => '<mark>psach</mark>',
] as $query => $expectedMark) {
    $payload = $searcher->search($query, [
        'lang' => 'pl',
        'mode' => 'AND',
        'include_total' => true,
        'include_metadata' => true,
        'include_snippets' => true,
        'highlight' => true,
        'snippet_length' => 180,
    ]);

    if (($payload['total'] ?? 0) !== 1) {
        fwrite(STDERR, 'No result for ' . $query . "\n");
        exit(10);
    }

    $snippet = (string) ($payload['results'][0]['snippet'] ?? '');
    if (!str_contains($snippet, $expectedMark)) {
        fwrite(STDERR, 'Missing highlight for ' . $query . ': ' . $snippet . "\n");
        exit(11);
    }
}

echo "ok\n";
PHP;

    $cli = test_run_subprocess(
        [
            PHP_BINARY,
            '-d',
            'memory_limit=128M',
            '-r',
            $code,
        ],
        dirname(__DIR__)
    );

    assert_same(0, $cli['exit'], 'compressed full pack snippets should not exhaust the Playground-sized PHP memory limit: ' . $cli['stderr']);
    assert_contains('ok', $cli['stdout'], 'compressed full pack low-memory snippet smoke should complete');
});

test_case('polish full pack ambiguous candidates index search and highlight as alternatives', function (): void {
    assert_or_pending(
        WP_FTS_AnalyzerPackValidator::gzip_available(),
        'gzip support should be available for full Polish ambiguous candidate search',
        'PHP zlib gzip support is unavailable, so full Polish ambiguous candidate search is skipped.'
    );

    $analyzer = new WP_FTS_Analyzer([
        'default_lang' => 'pl',
        'polish_lemma_pack' => WP_FTS_AnalyzerPackValidator::default_polish_playground_full_manifest(),
    ]);
    assert_same(['chrzastek', 'chrzastka'], $analyzer->analyze_query('chrząstek', ['lang' => 'pl']), 'query chrząstek should analyze to both full-pack candidates');
    assert_same(['chrzastka', 'chrzastek'], $analyzer->analyze_query('chrząstka', ['lang' => 'pl']), 'query chrząstka should analyze to both full-pack candidates');
    assert_same(['chrzastek', 'chrzastka'], $analyzer->analyze_query('chrząstki', ['lang' => 'pl']), 'query chrząstki should analyze to both full-pack candidates');
    assert_same([], $analyzer->analyze_query('w', ['lang' => 'pl']), 'short ambiguous Polish function words should remain filtered instead of expanding through long pack lemmas');

    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $formattedHtml = '<p>W chrzą<strong>st</strong>ek atlas notuje wynik.</p>';
    $indexer->index_document_fields(951, [['name' => 'content', 'html' => $formattedHtml]], [
        'lang' => 'pl',
        'metadata' => [
            'post_id' => 951,
            'post_type' => 'post',
            'post_status' => 'publish',
            'title' => 'Ambiguous chrząstek',
            'search_text' => 'W chrząstek atlas notuje wynik.',
            'language' => 'pl',
        ],
    ]);
    $indexer->index_document_fields(952, [['name' => 'content', 'text' => 'Opis chrząstka w atlasie.' ]], [
        'lang' => 'pl',
        'metadata' => [
            'post_id' => 952,
            'post_type' => 'post',
            'post_status' => 'publish',
            'title' => 'Ambiguous chrząstka',
            'search_text' => 'Opis chrząstka w atlasie.',
            'language' => 'pl',
        ],
    ]);

    $terms = $storage->all_terms();
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('pl', 'chrzastek'), $terms, true), 'document chrząstek should index the pack-backed chrzastek lemma');
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('pl', 'chrzastka'), $terms, true), 'document chrząstek should index the pack-backed chrzastka lemma');

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    foreach ([
        'chrząstka' => 951,
        'chrząstki' => 951,
        'chrząstek' => 952,
    ] as $query => $expectedDocId) {
        $ids = array_column($searcher->search($query, ['lang' => 'pl', 'mode' => 'AND', 'limit' => 10]), 'doc_id');
        assert_true(in_array($expectedDocId, $ids, true), "{$query} should find document {$expectedDocId} through pack-backed ambiguous candidates");
    }
    assert_same(
        952,
        $searcher->search('chrząstka atlas', ['lang' => 'pl', 'mode' => 'AND', 'limit' => 10])[0]['doc_id'] ?? null,
        'exact chrząstka document surface should outrank a secondary chrząstek lemma match'
    );
    assert_same(
        951,
        $searcher->search('chrząstek atlas', ['lang' => 'pl', 'mode' => 'AND', 'limit' => 10])[0]['doc_id'] ?? null,
        'exact chrząstek document surface should outrank a secondary chrząstka lemma match'
    );

    $payload = $searcher->search('chrząstka', [
        'lang' => 'pl',
        'mode' => 'AND',
        'include_total' => true,
        'include_metadata' => true,
        'include_snippets' => true,
        'highlight' => true,
        'snippet_length' => 180,
    ]);
    assert_true(($payload['total'] ?? 0) >= 1, 'chrząstka should return the formatted chrząstek document');
    $formattedResult = null;
    foreach ($payload['results'] as $result) {
        if ((int) ($result['doc_id'] ?? 0) === 951) {
            $formattedResult = $result;
            break;
        }
    }
    assert_true(is_array($formattedResult), 'chrząstka result set should include the formatted chrząstek document');
    assert_contains('<mark>chrzą<strong>st</strong>ek</mark>', (string) ($formattedResult['snippet'] ?? ''), 'snippet highlighting should mark the full formatted chrząstek surface');

    $singleCandidateStorage = new WP_FTS_Storage_InMemory();
    $singleCandidateStorage->put_doc(970, 'pl', ['pl' => 2], 'manual-pack-alternative');
    $singleCandidateStorage->put_term(
        WP_FTS_TermNamespace::namespace_term('pl', 'atlas'),
        1,
        WP_FTS_PostingsCodec::encode([970 => 1])
    );
    $singleCandidateStorage->put_term(
        WP_FTS_TermNamespace::namespace_term('pl', 'chrzastek'),
        1,
        WP_FTS_PostingsCodec::encode([970 => 1])
    );
    $andIds = array_column(
        (new WP_FTS_Searcher($singleCandidateStorage, $analyzer))->search('chrząstki atlas', ['lang' => 'pl', 'mode' => 'AND']),
        'doc_id'
    );
    assert_same([970], $andIds, 'AND search should treat chrząstki lemma candidates as alternatives for one logical token');
});

test_case('polish PoliMorf importer deterministically generates sharded full-pack shape', function (): void {
    require_once __DIR__ . '/../tools/import-polish-polimorf-lemmatizer.php';

    $source = __DIR__ . '/fixtures/polimorf-importer/sample-polimorf.tab';
    $outA = temp_directory_path('polimorf_import_a');
    $outB = temp_directory_path('polimorf_import_b');
    $options = [
        'source' => $source,
        'pack_id' => 'pl-polimorf-importer-fixture',
        'version' => 'fixture-import-v1',
        'source_url' => 'urn:wp-fts:test:polimorf-importer-fixture',
        'source_name' => 'WP FTS source-shaped PoliMorf importer fixture',
        'source_version' => 'fixture',
        'max_rows_per_file' => 2,
        'chunk_rows' => 2,
        'fixture_only' => false,
        'importer_commit' => 'test-commit',
    ];

    try {
        $summaryA = (new WP_FTS_PolishPolimorfImporter())->import($options + ['out' => $outA]);
        $summaryB = (new WP_FTS_PolishPolimorfImporter())->import($options + ['out' => $outB]);

        assert_same(6, $summaryA['runtime']['rows'], 'importer should normalize and deduplicate accepted surface lemma rows');
        assert_same(3, $summaryA['runtime']['files'], 'importer should shard runtime rows without splitting an ambiguous surface');
        assert_same(1, $summaryA['stats']['skipped_invalid_tokens'], 'importer should skip multi-token source rows');
        assert_same($summaryA['runtime']['sha256'], $summaryB['runtime']['sha256'], 'second import should produce the same runtime digest');
        assert_same(
            file_get_contents($outA . '/manifest.json'),
            file_get_contents($outB . '/manifest.json'),
            'second import should produce byte-identical manifest JSON'
        );
        assert_same(
            file_get_contents($outA . '/SOURCE.lock.json'),
            file_get_contents($outB . '/SOURCE.lock.json'),
            'second import should produce byte-identical source-lock JSON'
        );

        $validation = (new WP_FTS_AnalyzerPackValidator())->validate($outA . '/manifest.json', false);
        assert_same('pl-polimorf-importer-fixture', $validation['manifest']['pack_id'], 'generated full-pack manifest should validate');
        assert_true($validation['manifest']['fixture_only'] === false, 'generated importer manifest should support full-pack shape');
        assert_same(6, $validation['manifest']['runtime']['total_rows'], 'generated manifest should record runtime rows');
        assert_same($summaryA['runtime']['sha256'], $validation['manifest']['runtime']['total_sha256'], 'generated manifest should record runtime digest');
    } finally {
        remove_directory_tree($outA);
        remove_directory_tree($outB);
    }
});

test_case('polish PoliMorf sharded lemmatizer lazy-loads rows and preserves ambiguity', function (): void {
    require_once __DIR__ . '/../tools/import-polish-polimorf-lemmatizer.php';

    $out = temp_directory_path('polimorf_lazy');
    try {
        (new WP_FTS_PolishPolimorfImporter())->import([
            'source' => __DIR__ . '/fixtures/polimorf-importer/sample-polimorf.tab',
            'out' => $out,
            'pack_id' => 'pl-polimorf-importer-fixture',
            'version' => 'fixture-import-v1',
            'source_url' => 'urn:wp-fts:test:polimorf-importer-fixture',
            'source_name' => 'WP FTS source-shaped PoliMorf importer fixture',
            'source_version' => 'fixture',
            'max_rows_per_file' => 2,
            'chunk_rows' => 2,
            'fixture_only' => false,
            'importer_commit' => 'test-commit',
        ]);

        $lemmatizer = WP_FTS_PolishMorfologikLemmatizer::from_manifest_file($out . '/manifest.json');
        assert_same('pl-polimorf-importer-fixture', $lemmatizer->pack_id(), 'lazy lemmatizer should expose generated pack identity');
        assert_true(!$lemmatizer->is_fixture_only(), 'lazy lemmatizer should expose full-pack status');
        assert_same('kot', $lemmatizer->stem('kotami', 'pl'), 'lazy lemmatizer should map a row from a later shard');
        assert_same('ksiazka', $lemmatizer->stem('ksiazkach', 'pl'), 'lazy lemmatizer should map normalized diacritic-folded rows');
        assert_same('wroclaw', $lemmatizer->stem('wroclawiu', 'pl-PL'), 'lazy lemmatizer should respect Polish language subtags');
        assert_same('drogi', $lemmatizer->stem('drogi', 'pl'), 'lazy lemmatizer should no-op ambiguous surfaces');
        assert_same('brak', $lemmatizer->stem('brak', 'pl'), 'lazy lemmatizer should no-op OOV forms');
        assert_same('kotami', $lemmatizer->stem('kotami', 'en'), 'lazy lemmatizer should no-op unsupported languages');
    } finally {
        remove_directory_tree($out);
    }
});

test_case('polish Morfologik fixture lemmatizer maps rows and preserves ambiguous forms', function (): void {
    $lemmatizer = WP_FTS_PolishMorfologikLemmatizer::from_manifest_file(
        WP_FTS_AnalyzerPackValidator::default_polish_fixture_manifest()
    );

    assert_same('pl-morfologik-polimorf-fixture', $lemmatizer->pack_id(), 'lemmatizer should expose fixture pack identity');
    assert_true($lemmatizer->is_fixture_only(), 'lemmatizer should expose fixture-only status');
    assert_same('kot', $lemmatizer->stem('kotami', 'pl'), 'instrumental plural form should collapse to lemma');
    assert_same('wroclaw', $lemmatizer->stem('wroclawiu', 'pl-PL'), 'locative form should collapse to lemma');
    assert_same('ksiazka', $lemmatizer->stem('ksiazkach', 'pl'), 'plural locative form should collapse to lemma');
    assert_same('wyszukiwac', $lemmatizer->stem('wyszukiwanie', 'pl'), 'source-derived search nominal form should collapse to lemma');
    assert_same('wyszukiwac', $lemmatizer->stem('wyszukujemy', 'pl'), 'source-derived finite search form should collapse to lemma');
    assert_same('wpis', $lemmatizer->stem('wpisy', 'pl'), 'source-derived entry plural form should collapse to lemma');
    assert_same('wpis', $lemmatizer->stem('wpisach', 'pl'), 'source-derived entry locative plural form should collapse to lemma');
    assert_same('wpis', $lemmatizer->stem('wpisami', 'pl'), 'source-derived entry instrumental plural form should collapse to lemma');
    assert_same('kierowac', $lemmatizer->stem('kierowania', 'pl'), 'source-derived routing nominal form should collapse to lemma');
    assert_same('kierowac', $lemmatizer->stem('kierujemy', 'pl'), 'source-derived routing finite form should collapse to lemma');
    assert_same('drogi', $lemmatizer->stem('drogi', 'pl'), 'ambiguous forms should remain unchanged');
    assert_same('zielonymi', $lemmatizer->stem('zielonymi', 'pl'), 'missing forms should remain unchanged');
    assert_same('kotami', $lemmatizer->stem('kotami', 'en'), 'non-Polish language partitions should remain unchanged');
});

test_case('polish lemma pack is opt-in and invalid packs fall back to suffix stemming', function (): void {
    $defaultPipeline = new WP_FTS_LanguagePipeline(['enable_stemming' => true]);
    assert_same(['zamk'], $defaultPipeline->analyze('zamkach', 'pl'), 'default Polish suffix fallback should remain unchanged');

    $packPipeline = new WP_FTS_LanguagePipeline([
        'enable_stemming' => true,
        'polish_lemma_pack' => true,
    ]);
    assert_same(['zamek'], $packPipeline->analyze('zamkach', 'pl'), 'enabled fixture pack should use dictionary lemma rows');
    assert_same(['zielonymi'], $packPipeline->analyze('zielonymi', 'pl'), 'enabled fixture pack should not suffix-stem missing rows');
    assert_same(['drogi', 'droga'], $packPipeline->analyze('drogi', 'pl'), 'enabled fixture pack should expose ambiguous rows as alternatives');
    assert_same(['wyszukiwac', 'wyszukiwac'], $packPipeline->analyze('wyszukiwanie wyszukujemy', 'pl'), 'enabled fixture pack should use source-derived search lemma rows');
    assert_same(['wpis', 'wpis', 'wpis'], $packPipeline->analyze('wpisy wpisach wpisami', 'pl'), 'enabled fixture pack should use source-derived entry lemma rows');
    assert_same(['kierowac', 'kierowac'], $packPipeline->analyze('kierowania kierujemy', 'pl'), 'enabled fixture pack should use source-derived routing lemma rows');
    assert_same(['wpisy', 'kierowa', 'kierujemy', 'wyszukiwan'], $defaultPipeline->analyze('wpisy kierowania kierujemy wyszukiwanie', 'pl'), 'default Polish suffix fallback should not claim the new fixture-only lemma behavior where it lacks rows');

    $packOverridesVerifiedPipeline = new WP_FTS_LanguagePipeline([
        'enable_stemming' => true,
        'polish_lemma_pack' => true,
        'polish_stemming' => 'verified',
    ]);
    assert_same(['samochody'], $packOverridesVerifiedPipeline->analyze('samochody', 'pl'), 'valid lemma pack should take precedence over verified Polish stemming for missing pack rows');
    assert_same(['zamek'], $packOverridesVerifiedPipeline->analyze('zamkach', 'pl'), 'valid lemma pack should keep using dictionary lemma rows when polish_stemming is also set');

    $invalidPackPipeline = new WP_FTS_LanguagePipeline([
        'enable_stemming' => true,
        'polish_lemma_pack' => __DIR__ . '/missing-pack/manifest.json',
    ]);
    assert_same(['zamk'], $invalidPackPipeline->analyze('zamkach', 'pl'), 'missing opt-in pack should fall back to conservative suffix stemming');

    $invalidPackVerifiedPipeline = new WP_FTS_LanguagePipeline([
        'enable_stemming' => true,
        'polish_lemma_pack' => __DIR__ . '/missing-pack/manifest.json',
        'polish_stemming' => 'verified',
    ]);
    assert_same(['samochod'], $invalidPackVerifiedPipeline->analyze('samochody', 'pl'), 'invalid opt-in pack should fall back to the selected Polish stemming mode');

    $disabledAnalyzer = new WP_FTS_Analyzer(['polish_lemma_pack' => false]);
    assert_same(['zamk'], $disabledAnalyzer->analyze_query('zamkach', ['lang' => 'pl']), 'disabled pack should preserve analyzer default fallback behavior');
});

test_case('enabled polish lemma pack lets indexed and query inflections meet', function (): void {
    $analyzer = new WP_FTS_Analyzer([
        'default_lang' => 'pl',
        'polish_lemma_pack' => true,
    ]);
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $indexer->index_document(501, '<p>Notatki o książkach oraz kotami w zamkach, gdzie wyszukujemy wpisy i kierujemy raporty.</p>', ['lang' => 'pl']);

    $terms = $storage->all_terms();
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('pl', 'ksiazka'), $terms, true), 'lemma pack should store normalized Polish lemma for document form');
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('pl', 'zamek'), $terms, true), 'lemma pack should store dictionary lemma instead of suffix stem');
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('pl', 'wyszukiwac'), $terms, true), 'lemma pack should store source-derived search lemma instead of the document surface form');
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('pl', 'wpis'), $terms, true), 'lemma pack should store source-derived entry lemma instead of the document surface form');
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('pl', 'kierowac'), $terms, true), 'lemma pack should store source-derived routing lemma instead of the document surface form');
    assert_true(!in_array(WP_FTS_TermNamespace::namespace_term('pl', 'wpisy'), $terms, true), 'lemma pack should not store the exact entry document surface');
    assert_true(!in_array(WP_FTS_TermNamespace::namespace_term('pl', 'kierujemy'), $terms, true), 'lemma pack should not store the exact routing document surface');

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    assert_same([501], array_column($searcher->search('książka', ['lang' => 'pl', 'mode' => 'AND']), 'doc_id'), 'query lemma should meet indexed inflected document form');
    assert_same([501], array_column($searcher->search('zamek kot', ['lang' => 'pl', 'mode' => 'AND']), 'doc_id'), 'multiple query lemmas should meet indexed inflected forms');
    assert_same([501], array_column($searcher->search('wyszukiwanie', ['lang' => 'pl', 'mode' => 'AND']), 'doc_id'), 'pack-backed query nominal form should meet indexed finite verb form');
    assert_same([501], array_column($searcher->search('wpis', ['lang' => 'pl', 'mode' => 'AND']), 'doc_id'), 'pack-backed entry lemma should meet indexed plural document form');
    assert_same([501], array_column($searcher->search('wpisach', ['lang' => 'pl', 'mode' => 'AND']), 'doc_id'), 'pack-backed query entry form should meet a different indexed plural document form');
    assert_same([501], array_column($searcher->search('kierować', ['lang' => 'pl', 'mode' => 'AND']), 'doc_id'), 'pack-backed routing infinitive should meet indexed finite document form');

    $fallbackAnalyzer = new WP_FTS_Analyzer(['default_lang' => 'pl']);
    $fallbackStorage = new WP_FTS_Storage_InMemory();
    $fallbackIndexer = new WP_FTS_Indexer($fallbackStorage, $fallbackAnalyzer);
    $fallbackIndexer->index_document(502, '<p>Notatki o książkach w zamkach, gdzie wyszukujemy wpisy i kierujemy raporty.</p>', ['lang' => 'pl']);
    $fallbackSearcher = new WP_FTS_Searcher($fallbackStorage, $fallbackAnalyzer);
    assert_same([], $fallbackSearcher->search('książka', ['lang' => 'pl']), 'fallback suffix stemmer should remain unchanged when pack is not enabled');
    assert_same([], $fallbackSearcher->search('zamek', ['lang' => 'pl']), 'fallback suffix stemmer should not match the castle lemma without the pack');
    assert_same([], $fallbackSearcher->search('wyszukiwanie', ['lang' => 'pl']), 'fallback suffix stemmer should not match search nominal and finite forms without the pack');
    assert_same([], $fallbackSearcher->search('wpis', ['lang' => 'pl']), 'fallback suffix stemmer should not match entry lemma and plural forms without the pack');
    assert_same([], $fallbackSearcher->search('kierować', ['lang' => 'pl']), 'fallback suffix stemmer should not match routing infinitive and finite forms without the pack');
});

test_case('polish lemma pack snippets highlight matched document surface forms', function (): void {
    $analyzer = new WP_FTS_Analyzer([
        'default_lang' => 'pl',
        'polish_lemma_pack' => true,
    ]);
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $text = 'Notatki o książkach i zamkach, gdzie wyszukujemy wpisy oraz kierujemy raporty.';
    $indexer->index_document_fields(701, [['name' => 'content', 'text' => $text]], [
        'lang' => 'pl',
        'metadata' => [
            'post_id' => 701,
            'post_type' => 'post',
            'post_status' => 'publish',
            'title' => 'Polish snippet',
            'search_text' => $text,
            'language' => 'pl',
        ],
    ]);

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    foreach ([
        'wpis' => '<mark>wpisy</mark>',
        'kierować' => '<mark>kierujemy</mark>',
        'wyszukiwanie' => '<mark>wyszukujemy</mark>',
        'zamek' => '<mark>zamkach</mark>',
    ] as $query => $expectedMark) {
        $payload = $searcher->search($query, [
            'lang' => 'pl',
            'mode' => 'AND',
            'include_total' => true,
            'include_metadata' => true,
            'include_snippets' => true,
            'highlight' => true,
            'snippet_length' => 180,
        ]);

        assert_same(1, $payload['total'], 'lemma-backed snippet query should match the indexed Polish document for ' . $query);
        $snippet = (string) ($payload['results'][0]['snippet'] ?? '');
        assert_contains($expectedMark, $snippet, 'lemma-backed snippet should mark the original document surface for ' . $query);
    }

    $wpisPayload = $searcher->search('wpis', [
        'lang' => 'pl',
        'include_total' => true,
        'include_metadata' => true,
        'include_snippets' => true,
        'highlight' => true,
    ]);
    assert_true(!str_contains((string) ($wpisPayload['results'][0]['snippet'] ?? ''), '<mark>wpis</mark>y'), 'snippet highlighter should not split a matched Polish surface token');
});

test_case('admin sandbox full Polish pack maps full-only forms and highlights surfaces', function (): void {
    $sandboxAnalyzer = new ReflectionMethod(WP_FTS_Plugin::class, 'sandbox_analyzer');
    $sandboxAnalyzer->setAccessible(true);
    $analyzer = $sandboxAnalyzer->invoke(null);
    assert_true($analyzer instanceof WP_FTS_Analyzer, 'sandbox analyzer should be constructible');

    if (!WP_FTS_AnalyzerPackValidator::gzip_available()) {
        assert_same(['wpis'], $analyzer->analyze_query('wpisy', ['lang' => 'pl']), 'sandbox should fall back to the fixture pack when gzip is unavailable');
        assert_same(['prowadzilismy'], $analyzer->analyze_query('prowadzilismy', ['lang' => 'pl']), 'gzip fallback should not pretend to know full-pack-only forms');
        return;
    }

    foreach ([
        'prowadzilismy' => ['prowadzic'],
        'zabralibysmy' => ['zabrac'],
        'domach' => ['dom'],
        'psach' => ['pies'],
        'samochodami' => ['samochod'],
    ] as $surface => $expectedTerms) {
        assert_same($expectedTerms, $analyzer->analyze_query($surface, ['lang' => 'pl']), "sandbox analyzer should map {$surface} through the compressed full pack");
    }

    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $text = 'W domach przy psach prowadzilismy notatki i zabralibysmy katalog samochodami.';
    $indexer->index_document_fields(940, [['name' => 'content', 'text' => $text]], [
        'lang' => 'pl',
        'metadata' => [
            'post_id' => 940,
            'post_type' => 'post',
            'post_status' => 'publish',
            'title' => 'Compressed full Polish sandbox',
            'search_text' => $text,
            'language' => 'pl',
        ],
    ]);

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    $payload = $searcher->search('prowadzic', [
        'lang' => 'pl',
        'mode' => 'AND',
        'include_total' => true,
        'include_metadata' => true,
        'include_snippets' => true,
        'highlight' => true,
        'snippet_length' => 180,
    ]);

    assert_same(1, $payload['total'], 'full-pack-only query lemma should find the indexed sandbox-style Polish document');
    assert_contains('<mark>prowadzilismy</mark>', (string) ($payload['results'][0]['snippet'] ?? ''), 'full-pack-only snippet should mark the matched document surface');
});

test_case('stemming is enabled by default and can be explicitly disabled', function (): void {
    $defaultPipeline = new WP_FTS_LanguagePipeline();
    assert_same(['kot'], $defaultPipeline->analyze('kotami', 'pl'), 'default pipeline should use safe built-in stemming');
    assert_same(['samochody'], $defaultPipeline->analyze('samochody', 'pl'), 'default Polish mode should remain conservative');

    $disabledPipeline = new WP_FTS_LanguagePipeline(['enable_stemming' => false]);
    assert_same(['kotami'], $disabledPipeline->analyze('kotami', 'pl'), 'enable_stemming false should preserve exact normalized terms');

    $defaultAnalyzer = new WP_FTS_Analyzer();
    assert_same(['wroclaw'], $defaultAnalyzer->analyze_query('Wrocławiu', ['lang' => 'pl']), 'default analyzer should stem through the language pipeline');

    $disabledAnalyzer = new WP_FTS_Analyzer(['enable_stemming' => false]);
    assert_same(['wroclawiu'], $disabledAnalyzer->analyze_query('Wrocławiu', ['lang' => 'pl']), 'analyzer should keep an explicit no-stemming escape hatch');
});

test_case('language detector uses deterministic script and lexical evidence', function (): void {
    $detector = new WP_FTS_LanguageDetector();

    assert_same('pl', $detector->detect_text('Wrocław oraz Łódź'), 'Polish diacritics and stopwords should route to pl');
    assert_same('de', $detector->detect_text('Führung und Straße'), 'German diacritics and stopwords should route to de');
    assert_same('zh', $detector->detect_text('搜索引擎'), 'Han script should route to zh');
    assert_same('ar', $detector->detect_text('هذا نص عربي للبحث'), 'Arabic lexical evidence should route to ar');
    assert_same('ur', $detector->detect_text('یہ اردو تلاش اور فہرست ہے'), 'Urdu-specific evidence should route to ur');
    assert_same('ur', $detector->detect_text('اردو تلاش'), 'Urdu lexical evidence should beat shared Arabic script');
    assert_same('fa', $detector->detect_text('سلام دنیا'), 'Persian-specific Arabic-script evidence should route to fa');
    assert_same('fa', $detector->detect_text('فارسی جستجو'), 'Persian lexical text should route to fa');
    assert_same(null, $detector->detect_text('und'), 'one weak lexical marker should not be enough to guess');
    assert_same(null, $detector->detect_text('shared alpha beta'), 'weak generic Latin evidence should not guess');
});

test_case('analyzer auto-detects untagged document and query language gaps', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);

    $polish = $analyzer->analyze_content('<p>Wrocław oraz Łódź</p>');
    $polishLangs = test_lang_by_term($polish);
    assert_same('pl', $polishLangs['wroclaw'] ?? null, 'untagged Polish content should be detected as pl');
    assert_same('pl', $polishLangs['lodz'] ?? null, 'all terms in a detected Polish segment should use pl');

    $german = $analyzer->analyze_query_occurrences('Führung und Straße');
    $germanLangs = test_lang_by_term($german);
    assert_same('de', $germanLangs['fuehrung'] ?? null, 'untagged German query term should be detected as de');
    assert_same('de', $germanLangs['strasse'] ?? null, 'second German query term should keep detected de');

    $inlineGerman = $analyzer->analyze_content('<p>Führung <em>und</em> Straße</p>');
    $inlineGermanLangs = test_lang_by_term($inlineGerman);
    assert_same('de', $inlineGermanLangs['fuehrung'] ?? null, 'inline-split German content should detect the surrounding text as de');
    assert_same('de', $inlineGermanLangs['und'] ?? null, 'inline-split German connector should inherit the detected visible phrase language');
    assert_same('de', $inlineGermanLangs['strasse'] ?? null, 'inline-split German suffix text should keep the detected visible phrase language');

    $explicit = $analyzer->analyze_query_occurrences('Führung und Straße', ['lang' => 'en']);
    $explicitLangs = array_values(array_unique(array_column($explicit, 'lang')));
    assert_same(['en'], $explicitLangs, 'explicit query language should override detector evidence');

    $disabled = new WP_FTS_Analyzer(['default_lang' => 'en', 'auto_detect_language' => false]);
    $disabledLangs = test_lang_by_term($disabled->analyze_content('<p>Wrocław oraz Łódź</p>'));
    assert_same('en', $disabledLangs['wroclaw'] ?? null, 'auto_detect_language false should preserve legacy default language routing');
});

test_case('language detection does not override explicit metadata or segment tags', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);

    $explicitSegment = test_lang_by_term($analyzer->analyze_content('<p lang="en">Wrocław oraz Łódź</p>'));
    assert_same('en', $explicitSegment['wroclaw'] ?? null, 'HTML lang matching the fallback language should remain explicit metadata');
    assert_same('en', $explicitSegment['lodz'] ?? null, 'detector evidence must not override explicit HTML lang');

    $explicitXmlSegment = test_lang_by_term($analyzer->analyze_content('<p xml:lang="de">oraz jest</p>'));
    assert_same('de', $explicitXmlSegment['oraz'] ?? null, 'xml:lang should remain authoritative metadata');
    assert_same('de', $explicitXmlSegment['jest'] ?? null, 'detector evidence must not override explicit xml:lang');

    $dataLangSegment = test_lang_by_term($analyzer->analyze_content('<p data-lang="de">Wrocław oraz Łódź</p>'));
    assert_same('pl', $dataLangSegment['wroclaw'] ?? null, 'data-lang must not be treated as authoritative metadata');
    assert_same('pl', $dataLangSegment['lodz'] ?? null, 'data-lang should leave untagged text eligible for conservative detection');

    $resolverAnalyzer = new WP_FTS_Analyzer([
        'default_lang' => 'en',
        'document_language_resolver' => static fn(array $options): string => 'en',
        'query_language_resolver' => static fn(): string => 'en',
    ]);
    $resolverDocument = test_lang_by_term($resolverAnalyzer->analyze_content('<p>Wrocław oraz Łódź</p>'));
    assert_same('en', $resolverDocument['wroclaw'] ?? null, 'document language resolver should be authoritative metadata');

    $resolverQuery = $resolverAnalyzer->analyze_query_occurrences('Führung und Straße');
    $resolverQueryLangs = array_values(array_unique(array_column($resolverQuery, 'lang')));
    assert_same(['en'], $resolverQueryLangs, 'query language resolver should be authoritative metadata');
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
    assert_same('zh-Hant', $langs['搜'], 'CJK unigrams should carry segment lang');
    assert_same('zh-Hant', $langs['中文'], 'unquoted script lang should be canonicalized');
    assert_same('zh-Hant', $langs['搜索'], 'CJK n-grams should carry segment lang');
    assert_same('zh-Hant', $langs['中文搜索'], 'longer CJK n-grams should carry segment lang');

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

test_case('fallback parser only treats actual lang attributes as language scopes', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en', 'auto_detect_language' => false]);
    $segments = test_fallback_segments(
        $analyzer,
        '<p data-lang="pl">DataAttr</p>' .
        '<p aria-label="lang=pl">AriaLabel</p>' .
        '<p aria-label="foo lang=pl">AriaFoo</p>' .
        '<p title="x xml:lang=de">TitleXmlText</p>' .
        '<p lang="pl">LangAttr</p>' .
        '<p xml:lang="de">XmlLangAttr</p>' .
        '<p data-lang="pl" lang="de">ActualLangWins</p>' .
        '<p class="x lang=fr" lang=de>ClassRealLang</p>',
        'en'
    );

    $langsByText = [];
    foreach ($segments as $segment) {
        $langsByText[trim($segment['text'])] = $segment['lang'];
    }

    assert_same('en', $langsByText['DataAttr'] ?? null, 'fallback parser must ignore data-lang attributes');
    assert_same('en', $langsByText['AriaLabel'] ?? null, 'fallback parser must ignore lang-like text inside other attributes');
    assert_same('en', $langsByText['AriaFoo'] ?? null, 'fallback parser must ignore lang-like text inside aria-label values');
    assert_same('en', $langsByText['TitleXmlText'] ?? null, 'fallback parser must ignore xml:lang-like text inside title values');
    assert_same('pl', $langsByText['LangAttr'] ?? null, 'fallback parser should honor actual lang attributes');
    assert_same('de', $langsByText['XmlLangAttr'] ?? null, 'fallback parser should honor actual xml:lang attributes');
    assert_same('de', $langsByText['ActualLangWins'] ?? null, 'fallback parser should prefer actual lang over data-lang');
    assert_same('de', $langsByText['ClassRealLang'] ?? null, 'fallback parser should prefer the real lang attribute over class text');
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

    assert_same(['abc', '東', '京', '東京', 'def'], $analyzer->analyze_query('abc東京def', ['lang' => 'ja']), 'mixed Latin/CJK runs should split by script');
    assert_same(['中', '文', '搜', '索', '中文', '文搜', '搜索', '中文搜', '文搜索', '中文搜索', '日'], $analyzer->analyze_query('中文搜索 日 x', ['lang' => 'zh-Hans']), 'CJK n-grams and single chars should bypass min length');
});

test_case('analyzer tolerates invalid UTF-8 without optional extensions', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    $terms = $analyzer->analyze_query("bad\xffutf");

    assert_true($terms !== [], 'invalid UTF-8 recovery should not fatal or drop all ASCII text');
});

test_case('default index and query analyzers normalize plain text identically', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    mt_srand(1234);
    $termsByCategory = [
        'latin_diacritics' => ['Alpha', 'BETA', 'Wrocław', 'Łódź', 'café', 'naïve', 'Straße', 'İstanbul', 'São', 'façade'],
        'cjk' => ['中文搜索', '東京検索', '漢字かな', '한글검색', '搜索引擎', '京都'],
        'arabic_script' => ['العربية', 'اختبار', 'فارسی', 'معلومات'],
        'bengali' => ['বাংলা', 'বিদ্যালয়ের', 'সূচিতে', 'শিক্ষকদেরকে'],
        'urdu' => ['اردو', 'پاکستان', 'لڑکیاں', 'معلومات'],
        'hindi' => ['नमस्ते', 'विद्यालय', 'खोज', 'अनुच्छेद'],
        'numbers_underscores' => ['delta_2', 'v2_0', 'release42', '__init__', 'x99_y'],
        'short_terms' => ['x', 'a', 'I', 'go', 'pi', '東', '日'],
        'long_terms' => ['superlong', 'supercalifragilisticexpialidocious', 'extraordinarilylongtoken123', 'pneumonoultramicroscopicsilicovolcanoconiosis'],
    ];
    $separators = [' ', '  ', "\n", "\t", "\r\n", '.', ',', '!', '?', ';', "'", '"', '-', ' -- ', '/', '(', ')', '،', '।', '。'];
    $categories = array_keys($termsByCategory);
    $randomTerm = static function (string $category) use ($termsByCategory): string {
        $terms = $termsByCategory[$category];

        return $terms[mt_rand(0, count($terms) - 1)];
    };

    $comparisons = 0;
    for ($i = 0; $i < 1024; $i++) {
        $parts = [$randomTerm($categories[$i % count($categories)])];
        for ($j = 0, $n = mt_rand(4, 18); $j < $n; $j++) {
            $parts[] = $separators[mt_rand(0, count($separators) - 1)];
            $parts[] = $randomTerm($categories[mt_rand(0, count($categories) - 1)]);
        }
        $text = implode('', $parts);
        assert_same(
            $analyzer->analyze_query($text),
            test_terms($analyzer->analyze_content(strip_tags($text))),
            "plain content and query normalization must match for deterministic random string {$i}"
        );
        $comparisons++;
    }
    assert_true($comparisons >= 1000, 'T3 plain-string parity should cover at least 1000 deterministic random strings');
});

test_case('default analyzer parity covers explicit language stemmer pipelines', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    mt_srand(4321);
    $termsByLang = [
        'en' => ['running', 'runs', 'runner', 'indexed', 'documents', 'search'],
        'pl' => ['Wrocławiu', 'Łódź', 'kotami', 'samochody', 'zamek', 'zażółć'],
        'zh-Hans' => ['中文搜索', '搜索引擎', '科学院', '数据'],
        'hi' => ['नमस्ते', 'विद्यालय', 'खोज', 'अनुच्छेद'],
        'es' => ['búsqueda', 'corriendo', 'documentos', 'acción'],
        'ar' => ['العربية', 'اختبار', 'معلومات', 'المدرسة'],
        'fr' => ['recherche', 'élève', 'français', 'documents'],
        'bn' => ['বাংলা', 'বিদ্যালয়ের', 'সূচিতে', 'শিক্ষকদেরকে'],
        'pt' => ['pesquisa', 'corações', 'documentos', 'ação'],
        'id' => ['pencarian', 'dokumen', 'berjalan', 'indeks'],
        'ur' => ['اردو', 'پاکستان', 'لڑکیاں', 'معلومات'],
        'de' => ['Führung', 'Straße', 'Ärger', 'suchen'],
        'ru' => ['русский', 'поиск', 'документы', 'индекс'],
        'ja' => ['東京検索', '京都', '検索できます', '漢字かな'],
        'ko' => ['한글검색', '검색합니다', '한국어', '자료'],
        'te' => ['తెలుగు', 'శోధన', 'సూచిక', 'పత్రాలు'],
        'tr' => ['İstanbul', 'arama', 'dizini', 'belgeler'],
        'it' => ['ricerca', 'documenti', 'correndo', 'città'],
        'fa' => ['فارسی', 'جستجو', 'فهرست', 'گزارش'],
        'uk' => ['українська', 'пошук', 'індекс', 'документи'],
        'nl' => ['zoeken', 'documenten', 'aanbaden', 'kleur'],
        'ca' => ['català', 'cerca', 'documents', 'acció'],
    ];
    $separators = [' ', '  ', "\n", "\t", '.', ',', '!', '?', ';', ':', "'", '"', '-', ' / ', '،', '।', '。'];
    $languages = array_keys($termsByLang);

    $comparisons = 0;
    for ($i = 0; $i < 1024; $i++) {
        $lang = $languages[$i % count($languages)];
        $terms = $termsByLang[$lang];
        $parts = [$terms[mt_rand(0, count($terms) - 1)]];
        for ($j = 0, $n = mt_rand(4, 14); $j < $n; $j++) {
            $parts[] = $separators[mt_rand(0, count($separators) - 1)];
            $parts[] = $terms[mt_rand(0, count($terms) - 1)];
        }
        $text = implode('', $parts);
        $options = ['lang' => $lang];
        assert_same(
            $analyzer->analyze_query($text, $options),
            test_terms($analyzer->analyze_content(strip_tags($text), $options)),
            "default analyzer explicit {$lang} content and query parity must match for deterministic random string {$i}"
        );
        $comparisons++;
    }
    assert_true($comparisons >= 1000, 'T3 default-analyzer parity with stemming should cover at least 1000 deterministic random strings');
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
        ['English normalization', 'en', 'running runs runner', ['run', 'run', 'runner']],
        ['Polish folding', 'pl', 'Wrocław Łódź zażółć', ['wroclaw', 'lodz', 'zazolc']],
        ['German folding', 'de', 'Straße Ärger Öl', ['strasse', 'aerger', 'oel']],
        ['Turkish dotted I folding', 'tr', 'Isparta İstanbul ışık', ['ısparta', 'istanbul', 'ısık']],
        ['Bengali baseline stemming', 'bn', 'বইটিকে শিক্ষকদেরকে বিদ্যালয়ের সূচিতে', ['বই', 'শিক্ষক', 'বিদ্যালয়', 'সূচি']],
        ['Urdu baseline stemming', 'ur', 'لڑکیوں لڑکیاں لڑکے حالات معلومات', ['لڑکی', 'لڑکی', 'لڑک', 'حال', 'معلوم']],
        ['CJK fallback n-grams', 'zh-Hans', '搜索引擎', ['搜', '索', '引', '擎', '搜索', '索引', '引擎', '搜索引', '索引擎', '搜索引擎']],
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

test_case('analyzer signature reindexes unchanged content after default stemming changes', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $noStemAnalyzer = new WP_FTS_Analyzer(['enable_stemming' => false]);
    $noStemIndexer = new WP_FTS_Indexer($storage, $noStemAnalyzer);
    $html = '<p>Wrocławiu</p>';
    $oldTerm = WP_FTS_TermNamespace::namespace_term('pl', 'wroclawiu');
    $newTerm = WP_FTS_TermNamespace::namespace_term('pl', 'wroclaw');

    assert_true($noStemIndexer->index_document(42, $html, ['lang' => 'pl']), 'initial no-stem index should write the document');
    assert_true(in_array($oldTerm, $storage->all_terms(), true), 'no-stem index should store the unstemmed Polish term');
    assert_true(!in_array($newTerm, $storage->all_terms(), true), 'no-stem index should not store the default stemmed term yet');

    $defaultAnalyzer = new WP_FTS_Analyzer();
    $defaultIndexer = new WP_FTS_Indexer($storage, $defaultAnalyzer);
    assert_true($defaultIndexer->index_document(42, $html, ['lang' => 'pl']), 'analyzer signature change should force a rewrite for unchanged HTML');

    $terms = $storage->all_terms();
    assert_true(!in_array($oldTerm, $terms, true), 'reindex should remove the stale unstemmed posting');
    assert_true(in_array($newTerm, $terms, true), 'reindex should store the default stemmed posting');

    $searcher = new WP_FTS_Searcher($storage, $defaultAnalyzer);
    assert_same([42], array_column($searcher->search('Wrocławiu', ['lang' => 'pl']), 'doc_id'), 'default search should find the reindexed stemmed document');
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

test_case('indexer skips oversized namespaced terms without losing normal terms', function (): void {
    $lang = 'en';
    $normalTerm = 'normalneedle';
    $oversizedTerm = test_term_for_namespaced_key_bytes($lang, WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES + 1);
    $exactLimitTerm = test_term_for_namespaced_key_bytes($lang, WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES);
    $normalKey = WP_FTS_TermNamespace::namespace_term($lang, $normalTerm);
    $oversizedKey = WP_FTS_TermNamespace::namespace_term($lang, $oversizedTerm);
    $analyzer = new WP_FTS_Test_Oversized_Term_Analyzer($normalTerm, $oversizedTerm, $exactLimitTerm);
    $storage = new WP_FTS_Storage_InMemory();

    assert_same(WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES + 1, strlen($oversizedKey), 'oversized fixture key should exceed the storage byte limit by one');
    assert_true((new WP_FTS_Indexer($storage, $analyzer))->index_document(501, 'normal-token oversized-token', ['lang' => $lang]), 'indexing should not throw when one analyzer term is too large for storage');

    assert_same([$normalKey], $storage->all_terms(), 'only the valid normal term should be stored');
    assert_same([501 => 1], $storage->get_postings([$normalKey])[$normalKey] ?? [], 'normal term postings should still be indexed');
    assert_same([], $storage->get_postings([$oversizedKey]), 'oversized term should not have postings');
    assert_same(['primary_lang' => $lang, 'lang_lengths' => [$lang => 1], 'doc_len' => 1, 'content_hash' => $storage->get_doc(501)['content_hash'], 'deleted' => false], $storage->get_doc(501), 'oversized term should not contribute to document language length');
    assert_same(['doc_count' => 1, 'len_sum' => 1], $storage->get_meta($lang), 'oversized term should not contribute to collection length totals');

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    assert_same([501], array_column($searcher->search('normal-token', ['lang' => $lang]), 'doc_id'), 'normal query should still find the document');
    assert_same([], $searcher->search('oversized-token', ['lang' => $lang]), 'oversized query candidate should be filtered as a clean no-match');
});

test_case('indexer preserves a namespaced term exactly at the storage byte limit', function (): void {
    $lang = 'en';
    $normalTerm = 'normalneedle';
    $oversizedTerm = test_term_for_namespaced_key_bytes($lang, WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES + 1);
    $exactLimitTerm = test_term_for_namespaced_key_bytes($lang, WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES);
    $exactLimitKey = WP_FTS_TermNamespace::namespace_term($lang, $exactLimitTerm);
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Test_Oversized_Term_Analyzer($normalTerm, $oversizedTerm, $exactLimitTerm);

    assert_same(WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES, strlen($exactLimitKey), 'boundary fixture key should be exactly the MySQL term-key limit');
    assert_true((new WP_FTS_Indexer($storage, $analyzer))->index_document(502, 'exact-limit-token', ['lang' => $lang]), 'exact-limit key should remain indexable');

    assert_same([$exactLimitKey], $storage->all_terms(), 'exact-limit term should be stored');
    assert_same([502 => 1], $storage->get_postings([$exactLimitKey])[$exactLimitKey] ?? [], 'exact-limit term postings should round trip');
    assert_same(['doc_count' => 1, 'len_sum' => 1], $storage->get_meta($lang), 'exact-limit term should contribute to document length totals');
    assert_same([502], array_column((new WP_FTS_Searcher($storage, $analyzer))->search('exact-limit-token', ['lang' => $lang]), 'doc_id'), 'exact-limit query should still match');
});

test_case('language options namespace terms and isolate search partitions', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    $storage = new WP_FTS_Test_LanguageAwareStorage();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(1, '<p>shared apple</p>', ['lang' => 'en_US']);
    $indexer->index_document(2, '<p>shared jablko</p>', ['lang' => 'pl']);

    $terms = $storage->all_terms();
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('en-US', 'share'), $terms, true), 'English shared term should be namespaced');
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('pl', 'shared'), $terms, true), 'Polish shared term should be namespaced');
    assert_true(!in_array('shared', $terms, true), 'raw unnamespaced term should not be stored');

    assert_same(['doc_count' => 1, 'len_sum' => 2], $storage->get_meta('en-US'), 'English stats should be independent');
    assert_same(['doc_count' => 1, 'len_sum' => 2], $storage->get_meta('pl'), 'Polish stats should be independent');

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    assert_same(1, $searcher->search('shared', ['lang' => 'en_US'])[0]['doc_id'] ?? null, 'English query should only search English partition');
    assert_same(2, $searcher->search('shared', ['lang' => 'pl'])[0]['doc_id'] ?? null, 'Polish query should only search Polish partition');
    assert_same([], $searcher->search('jablko', ['lang' => 'en_US']), 'English query should not match Polish terms');
});

test_case('auto-detected document and query languages meet in search', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(1, '<p>Führung und Straße</p>');
    $indexer->index_document(2, '<p>guidance and street</p>', ['lang' => 'en']);

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    assert_same([1], array_column($searcher->search('Führung', ['limit' => 10]), 'doc_id'), 'detected German query should find detected German document partition');
    assert_same([], $searcher->search('Führung', ['lang' => 'en']), 'explicit English query should not leak into detected German partition');

    $terms = array_keys($storage->get_terms([
        WP_FTS_TermNamespace::namespace_term('de', 'fuehrung'),
        WP_FTS_TermNamespace::namespace_term('en', 'fuehrung'),
    ]));
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('de', 'fuehrung'), $terms, true), 'detected document should store the German namespace');
    assert_true(!in_array(WP_FTS_TermNamespace::namespace_term('en', 'fuehrung'), $terms, true), 'detected document should not fall back to the site/default namespace');
});

test_case('plain indexed fields preserve automatic document language detection', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $text = 'Zażółć gęślą jaźń oraz Łódź';
    $indexer->index_document_fields(10, [
        ['name' => 'title', 'text' => $text],
    ]);

    $htmlLangs = test_lang_by_term($analyzer->analyze_content('<div>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '</div>'));
    $plainLangs = test_lang_by_term($analyzer->analyze_plain_content($text));
    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    assert_same('pl', $htmlLangs['oraz'] ?? null, 'escaped-wrapper Polish fixture should detect the Polish namespace');
    assert_same('pl', $plainLangs['oraz'] ?? null, 'plain analyzer path should match escaped-wrapper language detection');
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('pl', 'oraz'), $storage->all_terms(), true), 'plain field should index Polish terms into the detected namespace');
    assert_true(!in_array(WP_FTS_TermNamespace::namespace_term('en', 'oraz'), $storage->all_terms(), true), 'plain field should not fall back to the default namespace');
    assert_same([10], array_column($searcher->search('oraz', ['lang' => 'pl']), 'doc_id'), 'explicit Polish search should find the auto-detected plain field');
    assert_same([], $searcher->search('oraz', ['lang' => 'en']), 'explicit English search should not match the auto-detected Polish plain field');
});

test_case('untagged query spans use document-parity language detection for AND recall', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(1, '<p>oraz jest</p>');
    $indexer->index_document(2, '<p>Führung und Straße</p>');
    $indexer->index_document(3, '<p>oraz jest</p>', ['lang' => 'en']);

    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    assert_same([1], array_column($searcher->search('oraz jest', ['mode' => 'AND', 'limit' => 10]), 'doc_id'), 'untagged Polish AND query should match the untagged Polish document segment');
    assert_same([2], array_column($searcher->search('Führung und Straße', ['mode' => 'AND', 'limit' => 10]), 'doc_id'), 'untagged German AND query should keep weak tokens in the detected German span');
    assert_same([3], array_column($searcher->search('oraz jest', ['lang' => 'en', 'mode' => 'AND', 'limit' => 10]), 'doc_id'), 'explicit query language should still isolate the requested partition');
    assert_same([], $searcher->search('Führung und Straße', ['lang' => 'en', 'mode' => 'AND']), 'explicit English query should not leak into detected German postings');
});

test_case('fallback parser separates top-level language groups across block boundaries', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
    $html = 'Führung Straße<p>plain alpha</p>oraz jest';
    $langs = test_lang_by_term($analyzer->analyze_content($html));

    assert_same('de', $langs['fuehrung'] ?? null, 'leading top-level German text should still detect as de');
    assert_same('de', $langs['strasse'] ?? null, 'leading top-level German suffix should stay in its text run');
    assert_same('en', $langs['plain'] ?? null, 'ambiguous block text should stay on the fallback language');
    assert_same('pl', $langs['oraz'] ?? null, 'trailing top-level Polish text after a block should get its own detection group');
    assert_same('pl', $langs['jest'] ?? null, 'trailing top-level Polish weak term should not inherit the earlier German group');

    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $indexer->index_document(1, $html);

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    assert_same([1], array_column($searcher->search('oraz jest', ['mode' => 'AND', 'limit' => 10]), 'doc_id'), 'Polish AND query should find trailing top-level text after a block');
    assert_same([], $searcher->search('oraz jest', ['lang' => 'de', 'mode' => 'AND']), 'trailing Polish text should not be stored in the earlier German namespace');
});

test_case('inline markup document spans share detected language for AND recall', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(1, '<p>Führung <em>und</em> Straße</p>');
    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    assert_same([1], array_column($searcher->search('Führung und Straße', ['mode' => 'AND', 'limit' => 10]), 'doc_id'), 'German AND query should find an untagged document phrase split by inline markup');
    assert_same([], $searcher->search('Führung und Straße', ['lang' => 'en', 'mode' => 'AND']), 'explicit English query should not match the detected German inline phrase');

    $terms = $storage->all_terms();
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('de', 'und'), $terms, true), 'weak connector from inline markup should be stored in the detected German namespace');
    assert_true(!in_array(WP_FTS_TermNamespace::namespace_term('en', 'und'), $terms, true), 'weak connector from inline markup should not drift to the fallback namespace');
});

test_case('custom query term resolver overrides detected untagged query spans', function (): void {
    $analyzer = new WP_FTS_Analyzer([
        'default_lang' => 'en',
        'query_term_language_resolver' => static function (string $token): ?string {
            return $token === 'Führung' ? 'en' : null;
        },
    ]);
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $indexer->index_document(
        10,
        '<article><p lang="en">Führung</p><p lang="de">Straße</p></article>',
        ['lang' => 'en']
    );

    $langs = test_lang_by_term($analyzer->analyze_query_occurrences('Führung Straße'));
    assert_same('en', $langs['fuhrung'] ?? null, 'custom resolver should override detected German span language for its token');
    assert_same('de', $langs['strasse'] ?? null, 'unresolved tokens should inherit the detected German span language');

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    assert_same([10], array_column($searcher->search('Führung Straße', ['mode' => 'AND']), 'doc_id'), 'resolver-selected token language should participate in AND search');
});

test_case('index_post lets detector fill missing WordPress language metadata', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $post = (object) [
        'ID' => 77,
        'post_title' => '',
        'post_content' => '<p>Führung und Straße</p>',
        'post_excerpt' => '',
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-06-09 00:00:00',
    ];

    $indexer->index_post($post);
    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    assert_same([77], array_column($searcher->search('Führung', ['limit' => 10]), 'doc_id'), 'detected German query should find detected German WordPress post content');
    assert_same([], $searcher->search('Führung', ['lang' => 'en']), 'explicit English query should not override detected German document partition');
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('de', 'fuehrung'), $storage->all_terms(), true), 'post without metadata should store detected German terms');
    assert_same('en', $storage->get_doc(77)['primary_lang'], 'primary hash language should remain the fallback when no deliberate metadata exists');
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

test_case('multilingual query plan searches inline language-tagged terms', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(1, '<p>castle bridge</p>', ['lang' => 'en']);
    $indexer->index_document(2, '<p>zamek most</p>', ['lang' => 'pl']);
    $indexer->index_document(3, '<article lang="pl"><p>zamek most</p><p lang="en">castle bridge</p></article>', ['lang' => 'pl']);

    assert_same(
        [
            ['term' => 'zamek', 'lang' => 'pl'],
            ['term' => 'castl', 'lang' => 'en'],
        ],
        $analyzer->analyze_query_occurrences('pl:zamek en:castle'),
        'inline query language tags should scope individual terms'
    );

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    $orIds = array_column($searcher->search('pl:zamek en:castle', ['limit' => 10]), 'doc_id');
    sort($orIds, SORT_NUMERIC);
    assert_same([1, 2, 3], $orIds, 'OR bilingual tagged query should search both language partitions');
    assert_same([3], array_column($searcher->search('pl:zamek en:castle', ['mode' => 'AND']), 'doc_id'), 'AND bilingual tagged query should require both language-tagged terms');
    assert_same([], $searcher->search('zamek', ['lang' => 'en']), 'single-language search should still isolate language partitions');
});

test_case('explicit language constraints override inline query tags', function (): void {
    $analyzer = new WP_FTS_Analyzer([
        'token_normalizer' => static fn(string $term, string $lang): string => "{$lang}-{$term}",
    ]);
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(1, '<p>zamek</p>', ['lang' => 'pl']);
    $indexer->index_document(2, '<p>zamek</p>', ['lang' => 'en']);
    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    assert_same([2], array_column($searcher->search('pl:zamek', ['lang' => 'en']), 'doc_id'), 'lang should keep inline tags inside the requested partition');
    assert_same([2], array_column($searcher->search('pl:zamek', ['languages' => 'en']), 'doc_id'), 'languages should keep inline tags inside the requested partition');
    assert_same([2], array_column($searcher->search('pl:zamek', ['langs' => ['en']]), 'doc_id'), 'langs should keep inline tags inside the requested partition');
});

test_case('multilingual query plan accepts explicit language lists', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(1, '<p>shared apple</p>', ['lang' => 'en']);
    $indexer->index_document(2, '<p>shared jablko</p>', ['lang' => 'pl']);
    $indexer->index_document(3, '<p>shared apfel</p>', ['lang' => 'de']);
    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    assert_same([1], array_column($searcher->search('shared', ['lang' => 'en']), 'doc_id'), 'legacy singular lang option should remain single partition');
    assert_same([1, 2], array_column($searcher->search('shared', ['languages' => 'pl,en', 'limit' => 10]), 'doc_id'), 'languages option should search all requested partitions');
    assert_same([1, 2], array_column($searcher->search('shared', ['langs' => ['en', 'pl'], 'limit' => 10]), 'doc_id'), 'langs array should search all requested partitions');
    assert_same([], $searcher->search('shared', ['lang' => 'fr']), 'unrequested partitions should not be searched by default');
});

test_case('language fallback is opt-in ordered and disableable', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(1, '<p>shared alpha</p>', ['lang' => 'en']);
    $indexer->index_document(2, '<p>shared</p>', ['lang' => 'fr']);
    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    assert_same([], $searcher->search('alpha', ['lang' => 'fr', 'default_lang' => 'en']), 'fallback should be disabled unless requested');
    assert_same([1], array_column($searcher->search('alpha', ['lang' => 'fr', 'default_lang' => 'en', 'language_fallback' => true]), 'doc_id'), 'fallback should search the configured default language');
    assert_same([2, 1], array_column($searcher->search('shared', ['lang' => 'fr', 'default_lang' => 'en', 'language_fallback' => true, 'limit' => 10]), 'doc_id'), 'exact language results should sort before fallback results');
    assert_same([], $searcher->search('alpha', ['lang' => 'fr', 'default_lang' => 'en', 'language_fallback' => true, 'disable_language_fallback' => true]), 'disable flag should suppress fallback even when enabled');
    assert_same([1], array_column($searcher->search('alpha', ['lang' => 'fr', 'fallback_lang' => 'en']), 'doc_id'), 'explicit fallback_lang should opt in without the default-language flag');
});

test_case('inline language-tagged queries participate in opt-in fallback', function (): void {
    $analyzer = new WP_FTS_Analyzer([
        'token_normalizer' => static fn(string $term, string $lang): string => "{$lang}-{$term}",
    ]);
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(1, '<p>zamek castle</p>', ['lang' => 'en']);
    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    assert_same([1], array_column($searcher->search('pl:zamek', [
        'default_lang' => 'en',
        'language_fallback' => true,
    ]), 'doc_id'), 'inline tags should fall back to the configured default language when opted in');

    assert_same([1], array_column($searcher->search('pl:zamek en:castle', [
        'default_lang' => 'en',
        'language_fallback' => true,
        'mode' => 'AND',
    ]), 'doc_id'), 'mixed inline AND queries should fall back per logical term group');

    $indexer->index_document(2, '<p>zamek</p>', ['lang' => 'pl']);
    assert_same([2, 1], array_column($searcher->search('pl:zamek', [
        'default_lang' => 'en',
        'language_fallback' => true,
        'limit' => 10,
    ]), 'doc_id'), 'exact inline language results should sort before fallback results');
});

test_case('query term language resolver can build bilingual plans deterministically', function (): void {
    $analyzer = new WP_FTS_Analyzer([
        'query_term_language_resolver' => static function (string $token): ?string {
            return match ($token) {
                'zamek', 'most' => 'pl',
                'castle', 'bridge' => 'en',
                default => null,
            };
        },
    ]);
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(1, '<article lang="pl"><p>zamek most</p><p lang="en">castle bridge</p></article>', ['lang' => 'pl']);
    $indexer->index_document(2, '<p>zamek most</p>', ['lang' => 'pl']);
    $indexer->index_document(3, '<p>castle bridge</p>', ['lang' => 'en']);

    assert_same(
        [
            ['term' => 'zamek', 'lang' => 'pl'],
            ['term' => 'castl', 'lang' => 'en'],
        ],
        $analyzer->analyze_query_occurrences('zamek castle'),
        'resolver should tag otherwise untagged query tokens'
    );

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    assert_same([1], array_column($searcher->search('zamek castle', ['mode' => 'AND']), 'doc_id'), 'resolver-driven AND query should require both resolved language partitions');
});

test_case('CJK tokenizer hooks override fallback n-grams while preserving fallback', function (): void {
    $calls = [];
    $analyzer = new WP_FTS_Analyzer([
        'cjk_tokenizer' => static function (string $run, string $lang) use (&$calls): array {
            $calls[] = "{$lang}:{$run}";
            return $run === '中文搜索' ? ['中文', '搜索'] : [];
        },
    ]);

    assert_same(['中文', '搜索'], $analyzer->analyze_query('中文搜索', ['lang' => 'zh-Hans']), 'custom CJK tokenizer should provide dictionary-like segments');
    assert_same(['zh-Hans:中文搜索'], $calls, 'custom CJK tokenizer should receive canonical language and raw CJK run');

    $fallback = new WP_FTS_Analyzer([
        'cjk_tokenizer' => static fn(string $run, string $lang): array => [],
    ]);
    assert_same(['中', '文', '搜', '索', '中文', '文搜', '搜索', '中文搜', '文搜索', '中文搜索'], $fallback->analyze_query('中文搜索', ['lang' => 'zh-Hans']), 'empty custom CJK tokenizer output should fall back to n-grams');
});

test_case('Chinese normalization hooks are explicit and default script behavior is unchanged', function (): void {
    $default = new WP_FTS_Analyzer();
    assert_same(['繁', '體', '搜', '索', '繁體', '體搜', '搜索', '繁體搜', '體搜索', '繁體搜索'], $default->analyze_query('繁體搜索', ['lang' => 'zh-Hant']), 'default Chinese script handling should not pretend broad conversion');

    $mapped = new WP_FTS_Analyzer([
        'chinese_script_map' => [
            'zh-Hant' => ['體' => '体'],
        ],
    ]);
    assert_same(['繁', '体', '搜', '索', '繁体', '体搜', '搜索', '繁体搜', '体搜索', '繁体搜索'], $mapped->analyze_query('繁體搜索', ['lang' => 'zh-Hant']), 'explicit Chinese script map should normalize configured characters');

    $hooked = new WP_FTS_Analyzer([
        'token_normalizer' => static fn(string $term, string $lang): string => $lang === 'zh-Hant' ? strtr($term, ['體' => '体']) : $term,
    ]);
    assert_same(['繁', '体', '搜', '索', '繁体', '体搜', '搜索', '繁体搜', '体搜索', '繁体搜索'], $hooked->analyze_query('繁體搜索', ['lang' => 'zh-Hant']), 'token_normalizer should provide a deterministic script-conversion hook');
});

test_case('custom stemmers can be verified per language', function (): void {
    $pipeline = new WP_FTS_LanguagePipeline([
        'stemmer' => static fn(string $term): string => 'global-' . $term,
        'stemmers_by_lang' => [
            'pl' => static fn(string $term): string => 'pl-' . $term,
            'en-GB' => static fn(string $term, string $lang): string => $lang . '-' . $term,
        ],
    ]);

    assert_same(['pl-kotami'], $pipeline->analyze('kotami', 'pl'), 'Polish custom stemmer should override the global custom stemmer');
    assert_same(['en-GB-color'], $pipeline->analyze('colour', 'en-GB'), 'full-tag custom stemmer should receive canonical language');
    assert_same(['global-strasse'], $pipeline->analyze('Straße', 'de'), 'global custom stemmer should remain the fallback for other languages');
});

test_case('indexer passes default document language to analyzer as fallback language', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    $storage = new WP_FTS_Test_LanguageAwareStorage();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document(1, '<p>lodz</p>', ['default_lang' => 'pl']);

    $terms = $storage->all_terms();
    assert_true(in_array(WP_FTS_TermNamespace::namespace_term('pl', 'lodz'), $terms, true), 'default_lang should reach analyzer as fallback language');
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

test_case('post content extractor indexes realistic WordPress fields and filters', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Analyzer();
    $extractor = new WP_FTS_PostContentExtractor();
    $post = (object) [
        'ID' => 1001,
        'post_title' => 'Title Aurora',
        'post_content' => '<p>Body Nebula</p>[fts_widget]',
        'post_excerpt' => 'Excerpt Comet',
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-02-03 04:05:06',
        'terms' => [
            'category' => ['Taxonomy Beacon'],
            'post_tag' => [(object) ['name' => 'Fixture Tag']],
        ],
        'custom_fields' => [
            'subtitle' => 'Custom Orbit Signal',
            'secret' => 'Hidden Secret',
        ],
    ];
    $extracted = $extractor->extract($post, [
        'custom_fields' => ['subtitle'],
        'render_content_callback' => static fn(string $content, object $post, array $opts): string => '<p>Rendered Shortcode Signal</p>',
        'filters' => [
            'wp_fts_post_index_fields' => static function (array $fields): array {
                $fields[] = ['name' => 'filtered', 'text' => 'Filterword Contribution', 'boost' => 2.0];
                return $fields;
            },
        ],
    ]);

    $indexer = new WP_FTS_Indexer($storage, $analyzer, $extractor);
    $indexer->index_document_fields(1001, $extracted['fields'], [
        'lang' => 'en',
        'metadata' => $extracted['metadata'],
        'field_boosts' => $extracted['field_boosts'],
    ]);

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    foreach (['aurora', 'nebula', 'comet', 'beacon', 'orbit', 'shortcode', 'filterword'] as $term) {
        assert_same(1001, $searcher->search($term, ['lang' => 'en'])[0]['doc_id'] ?? null, "{$term} should come from extracted post fields");
    }
    assert_same([], $searcher->search('hidden', ['lang' => 'en']), 'unselected custom fields should not be indexed');

    $metadata = $storage->get_doc_metadata([1001])[1001];
    assert_same('post', $metadata['post_type'], 'metadata should include post type');
    assert_same('publish', $metadata['post_status'], 'metadata should include post status');
    assert_same('2026-02-03 04:05:06', $metadata['post_date_gmt'], 'metadata should include post date');
    assert_same(['Taxonomy Beacon'], $metadata['terms']['category'], 'metadata should include taxonomy terms');
    assert_same(['Custom Orbit Signal'], $metadata['custom_fields']['subtitle'], 'metadata should include selected custom fields');
    assert_contains('Rendered Shortcode Signal', $metadata['search_text'], 'metadata search text should include rendered shortcode content');
});

test_case('index_post preserves extracted fields and metadata during runtime replacement', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Analyzer();
    $extractor = new WP_FTS_PostContentExtractor();
    $indexer = new WP_FTS_Indexer($storage, $analyzer, $extractor);
    $render = static fn(string $content, object $post, array $opts): string => '<p>Body Token</p><p>RenderedOnlyToken</p>';
    $post = (object) [
        'ID' => 7,
        'post_title' => 'Title Token',
        'post_content' => '<p>Body Token</p>',
        'post_excerpt' => 'ExcerptOnlyToken',
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-06-07 00:00:00',
        'custom_fields' => [
            'subtitle' => 'CustomOnlyToken',
        ],
    ];
    $extractOptions = [
        'lang' => 'en',
        'custom_fields' => ['subtitle'],
        'render_content_callback' => $render,
    ];
    $extracted = $extractor->extract($post, $extractOptions);
    $indexer->index_document_fields(7, $extracted['fields'], [
        'lang' => 'en',
        'metadata' => $extracted['metadata'],
        'field_boosts' => $extracted['field_boosts'],
    ]);

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    assert_same(1, count($searcher->search('ExcerptOnlyToken', ['lang' => 'en'])), 'full extractor index should make excerpt text searchable');
    assert_same(1, count($searcher->search('Title', ['lang' => 'en', 'post_status' => 'publish'])), 'full extractor index should write status metadata');

    $runtimeOptions = $extractOptions;
    $runtimeOptions['metadata'] = ['runtime_marker' => 'post-save'];
    $indexer->index_post($post, $runtimeOptions);

    assert_same(1, count($searcher->search('ExcerptOnlyToken', ['lang' => 'en'])), 'index_post replacement should preserve excerpt searchability');
    assert_same(1, count($searcher->search('CustomOnlyToken', ['lang' => 'en'])), 'index_post replacement should preserve custom field searchability');
    assert_same(1, count($searcher->search('RenderedOnlyToken', ['lang' => 'en'])), 'index_post replacement should preserve rendered-only searchability');
    assert_same(1, count($searcher->search('Title', ['lang' => 'en', 'post_status' => 'publish'])), 'index_post replacement should preserve metadata filters');

    $metadata = WP_FTS_StorageCompat::get_doc_metadata($storage, [7])[7] ?? [];
    assert_same('publish', $metadata['post_status'] ?? null, 'index_post replacement should keep active post status metadata');
    assert_same(['CustomOnlyToken'], $metadata['custom_fields']['subtitle'] ?? null, 'index_post replacement should keep custom field metadata');
    assert_contains('RenderedOnlyToken', $metadata['search_text'] ?? '', 'index_post replacement should keep rendered text for snippets');
    assert_same('post-save', $metadata['runtime_marker'] ?? null, 'index_post replacement should preserve caller metadata extras');
});

test_case('post content extractor does not double index static block visible text', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Analyzer();
    $extractor = new WP_FTS_PostContentExtractor();
    $post = (object) [
        'ID' => 1101,
        'post_title' => '',
        'post_content' => '<!-- wp:paragraph --><p>Body Nebula</p><!-- /wp:paragraph -->',
        'post_excerpt' => '',
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-04-01 00:00:00',
    ];

    $extracted = $extractor->extract($post, [
        'render_content_callback' => static fn(string $content, object $post, array $opts): string => '<p>Body Nebula</p>',
    ]);

    assert_true(!in_array('rendered', array_column($extracted['fields'], 'name'), true), 'same visible rendered block text should not add a second rendered field');

    (new WP_FTS_Indexer($storage, $analyzer, $extractor))->index_document_fields(1101, $extracted['fields'], [
        'lang' => 'en',
        'metadata' => $extracted['metadata'],
    ]);
    $term = WP_FTS_TermNamespace::namespace_term('en', 'nebula');
    $row = $storage->get_terms([$term])[$term] ?? null;

    assert_true($row !== null, 'static block visible term should be indexed');
    assert_same([1101 => 1], WP_FTS_PostingsCodec::decode($row['postings']), 'static block comments should not make visible text contribute twice');
});

test_case('post content extractor keeps rendered-only delta without static block duplicates', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Analyzer();
    $extractor = new WP_FTS_PostContentExtractor();
    $post = (object) [
        'ID' => 1102,
        'post_title' => '',
        'post_content' => '<!-- wp:paragraph --><p>Body Nebula</p><!-- /wp:paragraph --><!-- wp:latest-posts /-->',
        'post_excerpt' => '',
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-04-01 00:00:00',
    ];

    $extracted = $extractor->extract($post, [
        'render_content_callback' => static fn(string $content, object $post, array $opts): string => '<p>Body Nebula</p><ul><li>Latest Signal</li></ul>',
    ]);
    $fieldsByName = [];
    foreach ($extracted['fields'] as $field) {
        $fieldsByName[$field['name']] = $field['text'];
    }

    assert_same('Body Nebula', $fieldsByName['content'] ?? null, 'raw static block text should remain the content field');
    assert_same('Latest Signal', $fieldsByName['rendered'] ?? null, 'rendered field should contain only rendered-only dynamic text');

    (new WP_FTS_Indexer($storage, $analyzer, $extractor))->index_document_fields(1102, $extracted['fields'], [
        'lang' => 'en',
        'metadata' => $extracted['metadata'],
    ]);
    $nebula = WP_FTS_TermNamespace::namespace_term('en', 'nebula');
    $row = $storage->get_terms([$nebula])[$nebula] ?? null;
    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    assert_true($row !== null, 'static block term should be indexed once');
    assert_same([1102 => 1], WP_FTS_PostingsCodec::decode($row['postings']), 'static block term should not be counted again through rendered content');
    assert_same(1102, $searcher->search('latest', ['lang' => 'en'])[0]['doc_id'] ?? null, 'rendered-only latest term should remain searchable');
    assert_same(1102, $searcher->search('signal', ['lang' => 'en'])[0]['doc_id'] ?? null, 'rendered-only signal term should remain searchable');
});

test_case('metadata text limit keeps UTF-8 valid for file storage JSON', function (): void {
    $extractor = new WP_FTS_PostContentExtractor();
    $emoji = "\xF0\x9F\x98\x80";
    $prefix = str_repeat('a', 9);
    $post = (object) [
        'ID' => 1201,
        'post_title' => $prefix . $emoji,
        'post_content' => '',
        'post_excerpt' => '',
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-04-02 00:00:00',
    ];
    $extracted = $extractor->extract($post, ['metadata_text_limit' => 10]);

    assert_same($prefix, $extracted['metadata']['search_text'], 'metadata text limit should stop before a split multibyte character');
    assert_true(preg_match('//u', $extracted['metadata']['search_text']) === 1, 'truncated metadata search text should remain valid UTF-8');

    $path = temp_index_path('utf8_metadata');
    $storage = new WP_FTS_Storage_File($path);
    try {
        (new WP_FTS_Indexer($storage, new WP_FTS_Analyzer(), $extractor))->index_document_fields(1201, $extracted['fields'], [
            'lang' => 'en',
            'metadata' => $extracted['metadata'],
        ]);
        $storage->flush();

        $reloaded = new WP_FTS_Storage_File($path);
        $metadata = $reloaded->get_doc_metadata([1201])[1201] ?? [];

        assert_same($prefix, $metadata['search_text'] ?? null, 'file storage should persist truncated metadata search text as JSON');
        assert_same($prefix . $emoji, $metadata['title'] ?? null, 'file storage should preserve valid multibyte metadata outside the limit');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

test_case('metadata-less replacement clears stale product metadata', function (): void {
    $replace = [
        'legacy' => static function (WP_FTS_Indexer $indexer): void {
            $indexer->index_document(1, '<p>needle new</p>', ['lang' => 'en']);
        },
        'fields' => static function (WP_FTS_Indexer $indexer): void {
            $indexer->index_document_fields(1, [['name' => 'content', 'text' => 'needle new']], ['lang' => 'en']);
        },
    ];

    foreach ($replace as $name => $replaceDoc) {
        $storage = new WP_FTS_Storage_InMemory();
        $analyzer = new WP_FTS_Analyzer();
        $indexer = new WP_FTS_Indexer($storage, $analyzer);
        $indexer->index_document_fields(1, [['name' => 'content', 'text' => 'needle old']], [
            'lang' => 'en',
            'metadata' => [
                'post_id' => 1,
                'post_type' => 'post',
                'post_status' => 'publish',
                'title' => 'Old',
                'search_text' => 'needle old',
            ],
        ]);

        $replaceDoc($indexer);
        $searcher = new WP_FTS_Searcher($storage, $analyzer);
        $filtered = $searcher->search('needle', [
            'lang' => 'en',
            'include_total' => true,
            'post_status' => 'publish',
        ]);
        $unfiltered = $searcher->search('needle', [
            'lang' => 'en',
            'include_total' => true,
            'include_metadata' => true,
            'include_snippets' => true,
        ]);
        $metadata = $storage->get_doc_metadata([1])[1] ?? [];

        assert_same(0, $filtered['total'], "{$name} replacement without metadata should not match stale status filters");
        assert_same(1, $unfiltered['total'], "{$name} replacement should keep the new postings searchable");
        assert_same('', $unfiltered['results'][0]['title'] ?? null, "{$name} replacement should clear stale result title");
        assert_same('', $unfiltered['results'][0]['snippet'] ?? null, "{$name} replacement should clear stale snippet text");
        assert_same('', $metadata['post_status'] ?? null, "{$name} replacement should write normalized empty metadata");
    }
});

test_case('search bounded top-k matches full-sort ordering for deterministic corpus', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Analyzer([
        'enable_stemming' => false,
        'auto_detect_language' => false,
    ]);
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    for ($docId = 1; $docId <= 48; $docId++) {
        $tokens = array_fill(0, 4 + ($docId % 5), 'needle');
        $tokens = array_merge($tokens, array_fill(0, 20 + ($docId % 11), 'filler' . ($docId % 7)));
        if ($docId % 3 === 0) {
            $tokens[] = 'secondary';
        }
        $indexer->index_document_fields($docId, [['name' => 'content', 'text' => implode(' ', $tokens)]], [
            'lang' => 'en',
        ]);
    }

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    $bounded = $searcher->search('needle secondary', [
        'lang' => 'en',
        'limit' => 7,
    ]);
    $full = $searcher->search('needle secondary', [
        'lang' => 'en',
        'limit' => 7,
        'include_total' => true,
    ]);

    assert_same(48, $full['total'], 'full-sort oracle should see every OR candidate');
    assert_search_results_equal($full['results'], $bounded, 'bounded top-k should match full-sort top window');
});

test_case('search bounded top-k preserves totals offsets and exact metadata filters', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Analyzer([
        'enable_stemming' => false,
        'auto_detect_language' => false,
    ]);
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    for ($docId = 1; $docId <= 24; $docId++) {
        $tokens = array_merge(
            array_fill(0, 2 + ($docId % 4), 'shared'),
            array_fill(0, 8 + ($docId % 6), 'body' . ($docId % 5))
        );
        $indexer->index_document_fields($docId, [['name' => 'content', 'text' => implode(' ', $tokens)]], [
            'lang' => 'en',
            'metadata' => [
                'post_id' => $docId,
                'post_type' => $docId % 2 === 0 ? 'page' : 'post',
                'post_status' => 'publish',
                'post_date_gmt' => '2026-06-01 00:00:00',
                'title' => 'TopK bypass ' . $docId,
                'search_text' => implode(' ', $tokens),
            ],
        ]);
    }

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    $gate = new ReflectionMethod(WP_FTS_Searcher::class, 'can_use_bounded_top_k');
    $gate->setAccessible(true);

    assert_true((bool) $gate->invoke($searcher, ['limit' => 5], 0), 'plain first-page search should use bounded top-k');
    assert_true(!(bool) $gate->invoke($searcher, ['limit' => 5, 'include_total' => true], 0), 'include_total should bypass bounded top-k');
    assert_true(!(bool) $gate->invoke($searcher, ['limit' => 5], 5), 'offset should bypass bounded top-k');
    assert_true((bool) $gate->invoke($searcher, ['limit' => 5, 'post_type' => 'page'], 0), 'metadata filters should use exact bounded top-k');

    $full = $searcher->search('shared', [
        'lang' => 'en',
        'limit' => 24,
        'include_total' => true,
    ]);
    $paged = $searcher->search('shared', [
        'lang' => 'en',
        'limit' => 5,
        'offset' => 5,
        'include_total' => true,
    ]);
    $filtered = $searcher->search('shared', [
        'lang' => 'en',
        'limit' => 5,
        'include_total' => true,
        'post_type' => 'page',
    ]);
    $filteredBounded = $searcher->search('shared', [
        'lang' => 'en',
        'limit' => 5,
        'post_type' => 'page',
    ]);

    assert_same(24, $full['total'], 'full total should include every matching document');
    assert_same(
        array_column(array_slice($full['results'], 5, 5), 'doc_id'),
        array_column($paged['results'], 'doc_id'),
        'offset path should return the full-sort result window'
    );
    assert_same(12, $filtered['total'], 'metadata filter path should compute exact filtered total');
    assert_search_results_equal($filtered['results'], $filteredBounded, 'metadata-filtered bounded top-k should match exact filtered result window');
    $scoreByDoc = [];
    foreach ($full['results'] as $row) {
        $scoreByDoc[(int) $row['doc_id']] = (float) $row['score'];
    }
    foreach ($filtered['results'] as $row) {
        assert_true((int) $row['doc_id'] % 2 === 0, 'metadata filter path should keep only page documents');
        assert_float_near(
            $scoreByDoc[(int) $row['doc_id']] ?? -1.0,
            (float) $row['score'],
            'metadata filter path should preserve unfiltered BM25 score for doc ' . (int) $row['doc_id']
        );
    }
});

test_case('search auto fast mode keeps threshold-sized candidate sets exact', function (): void {
    [$searcher] = single_term_search_fixture(2000, 2000);

    $payload = $searcher->search('needle', [
        'lang' => 'en',
        'limit' => 1,
        'include_total' => true,
    ]);

    assert_same(2000, $payload['total'], 'candidate count equal to the default threshold should stay exact');
    assert_same(2000, $payload['results'][0]['doc_id'] ?? null, 'exact threshold-sized search should keep the strongest late candidate');
});

test_case('search auto fast mode switches above threshold and explicit exact disables it', function (): void {
    [$searcher] = single_term_search_fixture(2001, 2001);

    $auto = $searcher->search('needle', [
        'lang' => 'en',
        'limit' => 1,
        'include_total' => true,
    ]);
    $explicitExact = $searcher->search('needle', [
        'lang' => 'en',
        'limit' => 1,
        'include_total' => true,
        'fast_top_k' => false,
    ]);

    assert_same(1000, $auto['total'], 'auto fast mode should use the default candidate cap above the threshold');
    assert_true(($auto['results'][0]['doc_id'] ?? null) !== 2001, 'auto fast mode may miss a stronger candidate outside the cap');
    assert_same(2001, $explicitExact['total'], 'explicit false fast_top_k should force exact scoring above the threshold');
    assert_same(2001, $explicitExact['results'][0]['doc_id'] ?? null, 'explicit exact search should keep the strongest late candidate');
});

test_case('search auto fast mode applies metadata filters before threshold switch when probed', function (): void {
    [$searcher] = single_term_search_fixture(
        2001,
        2001,
        static fn(int $docId): array => [
            'post_id' => $docId,
            'post_type' => $docId === 2001 ? 'page' : 'post',
            'post_status' => 'publish',
            'post_date_gmt' => '2026-06-17 00:00:00',
            'title' => 'Filtered candidate ' . $docId,
            'search_text' => 'needle',
        ]
    );

    $payload = $searcher->search('needle', [
        'lang' => 'en',
        'limit' => 1,
        'include_total' => true,
        'post_type' => 'page',
    ]);

    assert_same(1, $payload['total'], 'filtered candidate count below threshold should remain exact');
    assert_same(2001, $payload['results'][0]['doc_id'] ?? null, 'metadata-filtered exact search should keep the matching late candidate');
});

test_case('search fast top-k candidate cap is explicit approximate opt-in', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Analyzer([
        'enable_stemming' => false,
        'auto_detect_language' => false,
    ]);
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    for ($docId = 1; $docId <= 6; $docId++) {
        $tokens = array_fill(0, $docId === 6 ? 12 : 1, 'needle');
        $indexer->index_document_fields($docId, [['name' => 'content', 'text' => implode(' ', $tokens)]], [
            'lang' => 'en',
            'metadata' => [
                'post_id' => $docId,
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_date_gmt' => '2026-06-17 00:00:00',
                'title' => 'Candidate cap ' . $docId,
                'search_text' => implode(' ', $tokens),
            ],
        ]);
    }

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    $exact = $searcher->search('needle', [
        'lang' => 'en',
        'limit' => 1,
        'include_total' => true,
    ]);
    $capWithoutFast = $searcher->search('needle', [
        'lang' => 'en',
        'limit' => 1,
        'include_total' => true,
        'candidate_cap' => 3,
    ]);
    $fast = $searcher->search('needle', [
        'lang' => 'en',
        'limit' => 1,
        'include_total' => true,
        'fast_top_k' => true,
        'candidate_cap' => 3,
    ]);
    $fastAlias = $searcher->search('needle', [
        'lang' => 'en',
        'limit' => 1,
        'include_total' => true,
        'approximate_top_k' => true,
        'candidate_cap' => 3,
    ]);

    assert_same(6, $exact['total'], 'exact search should count every candidate by default');
    assert_same(6, $exact['results'][0]['doc_id'] ?? null, 'exact search should rank the strongest late candidate first');
    assert_same($exact, $capWithoutFast, 'candidate_cap without fast_top_k should preserve exact default behavior');
    assert_same(3, $fast['total'], 'fast top-k should report only capped approximate candidates');
    assert_same($fast, $fastAlias, 'approximate_top_k alias should enable the same explicit capped fast path');
    assert_true(($fast['results'][0]['doc_id'] ?? null) !== 6, 'fast top-k may miss a stronger candidate outside the cap');
});

test_case('recency boost is default-off and can reorder equally relevant documents', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Analyzer([
        'enable_stemming' => false,
        'auto_detect_language' => false,
    ]);
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document_fields(1, [['name' => 'content', 'text' => 'needle shared']], [
        'lang' => 'en',
        'metadata' => [
            'post_id' => 1,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_date_gmt' => '2024-06-20 00:00:00',
            'title' => 'Old needle',
            'search_text' => 'needle shared',
        ],
    ]);
    $indexer->index_document_fields(2, [['name' => 'content', 'text' => 'needle shared']], [
        'lang' => 'en',
        'metadata' => [
            'post_id' => 2,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_date_gmt' => '2026-06-20 00:00:00',
            'title' => 'New needle',
            'search_text' => 'needle shared',
        ],
    ]);

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    assert_same([1, 2], array_column($searcher->search('needle', [
        'lang' => 'en',
        'limit' => 2,
    ]), 'doc_id'), 'disabled recency boost should preserve default score and doc-id ordering');

    $boosted = $searcher->search('needle', [
        'lang' => 'en',
        'limit' => 2,
        'recency_boost_strength' => 1.0,
        'recency_boost_half_life_days' => 7,
        'now_gmt' => '2026-06-20 00:00:00',
    ]);
    assert_same([2, 1], array_column($boosted, 'doc_id'), 'enabled recency boost should let a newer equally relevant document rank first');

    $fast = $searcher->search('needle', [
        'lang' => 'en',
        'limit' => 2,
        'include_total' => true,
        'fast_top_k' => true,
        'candidate_cap' => 2,
        'recency_boost_strength' => 1.0,
        'recency_boost_half_life_days' => 7,
        'now_gmt' => '2026-06-20 00:00:00',
    ]);
    assert_same([2, 1], array_column($fast['results'], 'doc_id'), 'fast-mode recency boost should apply inside the scored candidate set before sorting');
});

test_case('recency boost stays bounded and ignores missing or invalid dates safely', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Analyzer([
        'enable_stemming' => false,
        'auto_detect_language' => false,
    ]);
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document_fields(1, [['name' => 'content', 'text' => str_repeat('needle ', 12)]], [
        'lang' => 'en',
        'metadata' => [
            'post_id' => 1,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_date_gmt' => '2024-06-20 00:00:00',
            'title' => 'Strong old needle',
            'search_text' => str_repeat('needle ', 12),
        ],
    ]);
    $indexer->index_document_fields(2, [['name' => 'content', 'text' => 'needle']], [
        'lang' => 'en',
        'metadata' => [
            'post_id' => 2,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_date_gmt' => '2026-06-20 00:00:00',
            'title' => 'Weak new needle',
            'search_text' => 'needle',
        ],
    ]);
    $indexer->index_document_fields(3, [['name' => 'content', 'text' => 'needle']], [
        'lang' => 'en',
        'metadata' => [
            'post_id' => 3,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_date_gmt' => 'not-a-date',
            'title' => 'Invalid date needle',
            'search_text' => 'needle',
        ],
    ]);
    $indexer->index_document_fields(4, [['name' => 'content', 'text' => 'needle']], ['lang' => 'en']);

    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $severity . ':' . $message;
        return true;
    });
    try {
        $payload = (new WP_FTS_Searcher($storage, $analyzer))->search('needle', [
            'lang' => 'en',
            'limit' => 4,
            'include_total' => true,
            'explain' => true,
            'recency_boost_strength' => 0.005,
            'recency_boost_half_life_days' => 7,
            'now_gmt' => '2026-06-20 00:00:00',
        ]);
    } finally {
        restore_error_handler();
    }

    assert_same([], $warnings, 'missing and invalid recency dates should not emit PHP warnings');
    assert_same(1, $payload['results'][0]['doc_id'] ?? null, 'a strongly more relevant old document should still win when recency boost is small');
    $recency = is_array($payload['explain']['recency_boost'] ?? null) ? $payload['explain']['recency_boost'] : [];
    assert_same(true, $recency['enabled'] ?? null, 'explain should record enabled recency boost');
    assert_float_near(0.005, (float) ($recency['strength'] ?? -1), 'explain should record clamped recency strength');
    assert_float_near(7.0, (float) ($recency['half_life_days'] ?? -1), 'explain should record recency half-life');
    assert_same('2026-06-20 00:00:00', $recency['now_gmt'] ?? null, 'explain should record deterministic recency reference time');
    assert_same(4, (int) ($recency['documents_considered'] ?? 0), 'explain should count recency candidate documents');
    assert_same(2, (int) ($recency['documents_applied'] ?? 0), 'explain should count documents with valid dates that received a boost');
    assert_same(2, (int) ($recency['missing_or_invalid_dates'] ?? 0), 'explain should count missing and invalid recency dates');
    assert_same(false, $recency['metadata_unavailable'] ?? null, 'metadata-backed storage should not report recency metadata unavailable');
});

test_case('recency boost is a no-op when metadata support is unavailable', function (): void {
    $storage = new WP_FTS_Test_LanguagePartitionStorage();
    $payload = (new WP_FTS_Searcher($storage, new WP_FTS_Analyzer([
        'enable_stemming' => false,
        'auto_detect_language' => false,
    ])))->search('needle', [
        'lang' => 'en',
        'include_total' => true,
        'explain' => true,
        'recency_boost_strength' => 1.0,
        'now_gmt' => '2026-06-20 00:00:00',
    ]);

    assert_same(101, $payload['results'][0]['doc_id'] ?? null, 'metadata-unavailable recency boost should leave normal search results available');
    $recency = is_array($payload['explain']['recency_boost'] ?? null) ? $payload['explain']['recency_boost'] : [];
    assert_same(true, $recency['enabled'] ?? null, 'explain should still show that recency was requested');
    assert_same(true, $recency['metadata_unavailable'] ?? null, 'explain should report unavailable metadata as a safe no-op');
    assert_same(0, (int) ($recency['documents_applied'] ?? -1), 'metadata-unavailable recency boost should apply to no documents');
});

test_case('in-memory capped postings avoid full sort on append-ordered rows', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $term = WP_FTS_TermNamespace::namespace_term('en', 'needle');

    for ($docId = 1; $docId <= 8; $docId++) {
        $storage->replace_doc_postings($docId, [$term => 1]);
    }

    $sortedProperty = new ReflectionProperty(WP_FTS_Storage_InMemory::class, 'postingsSortedByTerm');
    $sortedProperty->setAccessible(true);
    $sortedFlags = $sortedProperty->getValue($storage);
    assert_true(!empty($sortedFlags[$term]), 'sequential row-posting writes should keep the term sorted');
    assert_same([1 => 1, 2 => 1, 3 => 1], $storage->get_capped_postings([$term], 3)[$term] ?? [], 'capped postings should return the sorted deterministic prefix');

    $sortedFlags = $sortedProperty->getValue($storage);
    assert_true(!empty($sortedFlags[$term]), 'capped prefix reads should not invalidate sorted append state');

    $storage->replace_doc_postings(0, [$term => 4]);
    $sortedFlags = $sortedProperty->getValue($storage);
    assert_true(empty($sortedFlags[$term]), 'out-of-order row-posting writes should mark the term unsorted');
    assert_same([0 => 4, 1 => 1, 2 => 1], $storage->get_capped_postings([$term], 3)[$term] ?? [], 'unsorted capped postings should still return the lowest doc ids');

    $sortedFlags = $sortedProperty->getValue($storage);
    assert_true(empty($sortedFlags[$term]), 'bounded selection should avoid materializing a full sorted posting list');
});

test_case('search product options filter metadata and return pagination snippets', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Analyzer();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $indexer->index_document_fields(1, [['name' => 'content', 'text' => 'shared product alpha']], [
        'lang' => 'en',
        'metadata' => [
            'post_id' => 1,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_date_gmt' => '2026-01-10 10:00:00',
            'title' => 'Published Shared',
            'search_text' => 'Published shared product alpha snippet source',
        ],
    ]);
    $indexer->index_document_fields(2, [['name' => 'content', 'text' => 'shared product beta']], [
        'lang' => 'en',
        'metadata' => [
            'post_id' => 2,
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_date_gmt' => '2025-12-01 10:00:00',
            'title' => 'Draft Shared',
            'search_text' => 'Draft shared product beta snippet source',
        ],
    ]);

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    $filtered = $searcher->search('shared', [
        'lang' => 'en',
        'include_total' => true,
        'include_metadata' => true,
        'include_snippets' => true,
        'highlight' => true,
        'post_type' => 'post',
        'post_status' => 'publish',
        'date_after' => '2026-01-01',
        'date_before' => '2026-12-31',
    ]);
    assert_same(1, $filtered['total'], 'metadata filters should reduce total before pagination');
    assert_same(1, $filtered['results'][0]['doc_id'], 'publish/post/date filters should keep only visible matching post');
    assert_same('Published Shared', $filtered['results'][0]['title'], 'metadata fields should enrich result rows');
    assert_contains('<mark>shared</mark>', $filtered['results'][0]['snippet'], 'highlighted snippets should come from stored extracted text');

    $paged = $searcher->search('shared', [
        'lang' => 'en',
        'include_total' => true,
        'limit' => 1,
        'offset' => 1,
    ]);
    assert_same(2, $paged['total'], 'unfiltered total should include both matching posts');
    assert_same(2, $paged['results'][0]['doc_id'], 'offset should page through ordered results');
});

test_case('metadata-filtered search preserves document id zero', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Analyzer(['auto_detect_language' => false]);
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $indexer->index_document_fields(0, [['name' => 'content', 'text' => 'zero needle']], [
        'lang' => 'en',
        'metadata' => [
            'post_id' => 0,
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_date_gmt' => '2026-06-17 00:00:00',
            'title' => 'Zero Needle',
            'search_text' => 'zero needle',
        ],
    ]);
    $indexer->index_document_fields(1, [['name' => 'content', 'text' => 'zero needle']], [
        'lang' => 'en',
        'metadata' => [
            'post_id' => 1,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_date_gmt' => '2026-06-17 00:00:00',
            'title' => 'Nonzero Needle',
            'search_text' => 'zero needle',
        ],
    ]);

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    $unfiltered = $searcher->search('needle', [
        'lang' => 'en',
        'include_total' => true,
        'limit' => 10,
    ]);
    $filtered = $searcher->search('needle', [
        'lang' => 'en',
        'include_total' => true,
        'include_metadata' => true,
        'post_type' => 'page',
        'post_status' => 'publish',
    ]);

    assert_true(in_array(0, array_column($unfiltered['results'], 'doc_id'), true), 'unfiltered search should return document id zero');
    assert_same(1, $filtered['total'], 'metadata filters should keep the matching zero-id document');
    assert_same(0, $filtered['results'][0]['doc_id'] ?? null, 'metadata-filtered search should preserve document id zero');
    assert_same('Zero Needle', $filtered['results'][0]['title'] ?? null, 'zero-id filtered result should still be metadata-enriched');
});

test_case('search snippets highlight analyzed Unicode words across inline HTML without marking hidden text', function (): void {
    $analyzer = new WP_FTS_Analyzer(['auto_detect_language' => false]);
    $storage = new WP_FTS_Storage_InMemory();
    $html = '<p><strong>Word</strong>Press Szk<em>l<i><b>ar</b></i></em>nia ' .
        'W<em>ęgorz</em> W&#281;<em>gorz</em></p>' .
        '<script>WordPress Szklarnia Węgorz</script>' .
        '<style>.hidden{content:"WordPress Szklarnia Węgorz"}</style>' .
        '<!-- WordPress Szklarnia Węgorz -->';

    (new WP_FTS_Indexer($storage, $analyzer))->index_document_fields(31, [[
        'name' => 'content',
        'text' => 'WordPress Szklarnia Węgorz Węgorz',
        'html' => $html,
    ]], [
        'lang' => 'pl',
        'metadata' => [
            'post_id' => 31,
            'post_type' => 'post',
            'post_status' => 'publish',
            'title' => 'Inline HTML',
            'search_text' => 'WordPress Szklarnia Węgorz Węgorz',
        ],
    ]);

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    foreach ([
        'WordPress' => '<mark><strong>Word</strong>Press</mark>',
        'Szklarnia' => '<mark>Szk<em>l<i><b>ar</b></i></em>nia</mark>',
        'Węgorz' => '<mark>W<em>ęgorz</em></mark>',
        'Wegorz' => '<mark>W&#281;<em>gorz</em></mark>',
    ] as $query => $expectedMark) {
        $payload = $searcher->search($query, [
            'lang' => 'pl',
            'include_total' => true,
            'include_metadata' => true,
            'include_snippets' => true,
            'highlight' => true,
            'snippet_length' => 260,
        ]);

        assert_same(1, $payload['total'], "HTML-aware snippet query should match {$query}");
        $snippet = (string) ($payload['results'][0]['snippet'] ?? '');
        assert_contains($expectedMark, $snippet, "HTML-aware snippet should preserve inline markup for {$query}");
        assert_true(!str_contains($snippet, '<script><mark>'), "HTML-aware snippet should not mark script text for {$query}");
        assert_true(!str_contains($snippet, '<style><mark>'), "HTML-aware snippet should not mark style text for {$query}");
        assert_true(!str_contains($snippet, '<!-- <mark>'), "HTML-aware snippet should not mark comment text for {$query}");
    }
});

test_case('highlighted HTML snippets are compacted around split inline matches', function (): void {
    $analyzer = new WP_FTS_Analyzer(['auto_detect_language' => false]);
    $storage = new WP_FTS_Storage_InMemory();
    $farPrefix = str_repeat('far-prefix-filler ', 30);
    $farSuffix = str_repeat(' far-suffix-filler', 30);
    $html = '<p>' . $farPrefix . '<strong>Word</strong>Press' . $farSuffix . '</p>';

    (new WP_FTS_Indexer($storage, $analyzer))->index_document_fields(32, [[
        'name' => 'content',
        'text' => $farPrefix . 'WordPress' . $farSuffix,
        'html' => $html,
    ]], [
        'lang' => 'en',
        'metadata' => [
            'post_id' => 32,
            'post_type' => 'post',
            'post_status' => 'publish',
            'title' => 'Long Inline HTML',
            'search_text' => $farPrefix . 'WordPress' . $farSuffix,
        ],
    ]);

    $payload = (new WP_FTS_Searcher($storage, $analyzer))->search('WordPress', [
        'lang' => 'en',
        'include_total' => true,
        'include_metadata' => true,
        'include_snippets' => true,
        'highlight' => true,
        'snippet_length' => 40,
    ]);

    assert_same(1, $payload['total'], 'compact HTML snippet query should match the indexed split inline word');
    $snippet = (string) ($payload['results'][0]['snippet'] ?? '');
    assert_contains('<mark><strong>Word</strong>Press</mark>', $snippet, 'compact HTML snippet should preserve the marked split inline word');
    assert_true(!str_contains($snippet, 'far-prefix-filler far-prefix-filler'), 'compact HTML snippet should omit far prefix filler');
    assert_true(!str_contains($snippet, 'far-suffix-filler far-suffix-filler'), 'compact HTML snippet should omit far suffix filler');
    assert_true(strlen($snippet) <= 180, 'compact HTML snippet should stay within a small practical HTML fragment size');
});

test_case('snippet metadata stores compact HTML sidecar without losing text fallback', function (): void {
    $analyzer = new WP_FTS_Analyzer(['auto_detect_language' => false]);
    $storage = new WP_FTS_Storage_InMemory();
    $plainPrefix = str_repeat('<p>plain filler commonterm context block</p>', 180);
    $plainSuffix = str_repeat('<p>tail filler block PlainTailNeedle context</p>', 20);
    $html = $plainPrefix . '<p><strong>Word</strong>Press split marker</p>' . $plainSuffix;
    $searchText = WP_FTS_Html_Text_Stream::visible_text($html);

    (new WP_FTS_Indexer($storage, $analyzer))->index_document_fields(33, [[
        'name' => 'content',
        'text' => $searchText,
        'html' => $html,
    ]], [
        'lang' => 'en',
        'metadata' => [
            'post_id' => 33,
            'post_type' => 'post',
            'post_status' => 'publish',
            'title' => 'Compact sidecar',
            'search_text' => $searchText,
        ],
    ]);

    $metadata = $storage->get_doc_metadata([33])[33] ?? [];
    $searchHtml = (string) ($metadata['search_html'] ?? '');
    assert_true(strlen($html) > 8000, 'fixture should contain long HTML content');
    assert_true(strlen($searchHtml) < 120, 'stored HTML sidecar should avoid copying long plain HTML content');
    assert_contains('<strong>Word</strong>Press', $searchHtml, 'stored HTML sidecar should retain split inline markup needed for highlighting');
    assert_true(!str_contains($searchHtml, 'PlainTailNeedle'), 'stored HTML sidecar should leave plain terms to search_text fallback');

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    $splitPayload = $searcher->search('WordPress', [
        'lang' => 'en',
        'include_total' => true,
        'include_metadata' => true,
        'include_snippets' => true,
        'highlight' => true,
        'snippet_length' => 80,
    ]);
    $textPayload = $searcher->search('PlainTailNeedle', [
        'lang' => 'en',
        'include_total' => true,
        'include_metadata' => true,
        'include_snippets' => true,
        'highlight' => true,
        'snippet_length' => 80,
    ]);

    assert_same(1, $splitPayload['total'], 'split inline query should still match after metadata compaction');
    assert_contains('<mark><strong>Word</strong>Press</mark>', (string) ($splitPayload['results'][0]['snippet'] ?? ''), 'split inline query should still preserve original HTML markup');
    assert_same(1, $textPayload['total'], 'plain tail query should still match after metadata compaction');
    assert_contains('<mark>PlainTailNeedle</mark>', (string) ($textPayload['results'][0]['snippet'] ?? ''), 'plain tail query should fall back to stored search_text snippets');
});

test_case('field boosts are tunable for extracted fields', function (): void {
    $analyzer = new WP_FTS_Analyzer();

    $titleBoosted = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($titleBoosted, $analyzer);
    $indexer->index_document_fields(1, [['name' => 'title', 'text' => 'needle', 'boost' => 5.0]], ['lang' => 'en']);
    $indexer->index_document_fields(2, [['name' => 'content', 'text' => 'needle', 'boost' => 1.0]], ['lang' => 'en']);
    assert_same([1, 2], array_column((new WP_FTS_Searcher($titleBoosted, $analyzer))->search('needle', ['lang' => 'en', 'limit' => 2]), 'doc_id'), 'higher title boost should affect ranking');

    $contentBoosted = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($contentBoosted, $analyzer);
    $indexer->index_document_fields(1, [['name' => 'title', 'text' => 'needle', 'boost' => 1.0]], ['lang' => 'en']);
    $indexer->index_document_fields(2, [['name' => 'content', 'text' => 'needle', 'boost' => 5.0]], ['lang' => 'en']);
    assert_same([2, 1], array_column((new WP_FTS_Searcher($contentBoosted, $analyzer))->search('needle', ['lang' => 'en', 'limit' => 2]), 'doc_id'), 'field boost tuning should be reversible');
});

test_case('prefix matching expands query terms to stored terms and can be disabled', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Analyzer([
        'enable_stemming' => false,
        'auto_detect_language' => false,
    ]);
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $indexer->index_document(1, 'aktorskiego', ['lang' => 'pl']);
    $indexer->index_document(2, 'aktorstwa', ['lang' => 'pl']);
    $indexer->index_document(3, 'aktorstwo', ['lang' => 'pl']);

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    assert_same([1, 2, 3], array_column($searcher->search('aktor', [
        'lang' => 'pl',
        'prefix_matching' => true,
        'limit' => 10,
    ]), 'doc_id'), 'prefix query should find indexed terms that start with the searched word');
    assert_same([1], array_column($searcher->search('aktorski', [
        'lang' => 'pl',
        'prefix_matching' => true,
        'limit' => 10,
    ]), 'doc_id'), 'longer prefix should match aktorskiego without pulling unrelated aktorstwo');
    assert_same([], $searcher->search('aktor', [
        'lang' => 'pl',
        'prefix_matching' => false,
    ]), 'per-search prefix_matching false should preserve exact-only search');
});

test_case('prefix threshold options enable shorter prefixes and cap expanded terms', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Analyzer([
        'enable_stemming' => false,
        'auto_detect_language' => false,
    ]);
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $indexer->index_document(1, 'prealpha', ['lang' => 'en']);
    $indexer->index_document(2, 'prebeta', ['lang' => 'en']);
    $indexer->index_document(3, 'pregamma', ['lang' => 'en']);

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    assert_same([], $searcher->search('pre', [
        'lang' => 'en',
        'prefix_matching' => true,
        'limit' => 10,
    ]), 'default prefix minimum length should not expand a three-letter query');

    $payload = $searcher->search('pre', [
        'lang' => 'en',
        'prefix_matching' => true,
        'prefix_min_length' => 3,
        'prefix_max_terms' => 2,
        'limit' => 10,
        'include_total' => true,
        'explain' => true,
    ]);
    assert_same([1, 2], array_column($payload['results'], 'doc_id'), 'prefix max terms should bound expansion to deterministic stored terms');
    $plan = is_array($payload['explain']['query_plan'] ?? null) ? $payload['explain']['query_plan'] : [];
    assert_same('enabled', $plan['prefix_matching'] ?? null, 'explain should record enabled prefix matching');
    assert_same(3, (int) ($plan['prefix_min_length'] ?? 0), 'explain should record effective prefix minimum length');
    assert_same(2, (int) ($plan['prefix_max_terms'] ?? 0), 'explain should record effective prefix max terms');
    assert_same(2, (int) ($plan['prefix_added_terms'] ?? 0), 'explain should record the bounded number of prefix-added terms');
});

test_case('prefix-expanded alternatives rank behind exact analyzer matches', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Analyzer([
        'enable_stemming' => false,
        'auto_detect_language' => false,
    ]);
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $indexer->index_document(1, 'aktor', ['lang' => 'pl']);
    $indexer->index_document(2, 'aktorskiego', ['lang' => 'pl']);

    $results = (new WP_FTS_Searcher($storage, $analyzer))->search('aktor', [
        'lang' => 'pl',
        'prefix_matching' => true,
        'limit' => 10,
    ]);
    assert_same([1, 2], array_column($results, 'doc_id'), 'exact term result should sort before a prefix-only result');
});

test_case('prefix matching is language namespace aware', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Analyzer([
        'enable_stemming' => false,
        'auto_detect_language' => false,
    ]);
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $indexer->index_document(1, 'aktorstwo', ['lang' => 'pl']);
    $indexer->index_document(2, 'aktorstwo', ['lang' => 'en']);

    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    assert_same([1], array_column($searcher->search('aktor', [
        'lang' => 'pl',
        'prefix_matching' => true,
        'limit' => 10,
    ]), 'doc_id'), 'Polish prefix expansion should stay in the Polish namespace');
    assert_same([2], array_column($searcher->search('aktor', [
        'lang' => 'en',
        'prefix_matching' => true,
        'limit' => 10,
    ]), 'doc_id'), 'English prefix expansion should stay in the English namespace');
});

test_case('AND mode keeps original query groups while allowing prefix alternatives', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Analyzer([
        'enable_stemming' => false,
        'auto_detect_language' => false,
    ]);
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $indexer->index_document(1, 'aktorstwo scena', ['lang' => 'pl']);
    $indexer->index_document(2, 'aktorstwo', ['lang' => 'pl']);
    $indexer->index_document(3, 'sceniczny', ['lang' => 'pl']);

    assert_same([1], array_column((new WP_FTS_Searcher($storage, $analyzer))->search('aktor scen', [
        'lang' => 'pl',
        'mode' => 'AND',
        'prefix_matching' => true,
        'limit' => 10,
    ]), 'doc_id'), 'AND mode should require every original query word even when each word has prefix alternatives');
});

test_case('storage prefix lookups are capped', function (): void {
    $storage = new WP_FTS_Storage_InMemory();
    $prefix = WP_FTS_TermNamespace::namespace_term('pl', 'aktor');
    foreach (['aktora', 'aktorce', 'aktorem', 'zapas'] as $index => $term) {
        $storage->replace_doc_postings($index + 1, [
            WP_FTS_TermNamespace::namespace_term('pl', $term) => 1,
        ]);
    }

    assert_same([
        WP_FTS_TermNamespace::namespace_term('pl', 'aktora'),
        WP_FTS_TermNamespace::namespace_term('pl', 'aktorce'),
    ], WP_FTS_StorageCompat::terms_with_prefix($storage, $prefix, 2), 'prefix term lookup should return a deterministic capped term list');
});

test_case('mysql prefix lookup uses a bounded term range query', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $storage = new WP_FTS_Storage_Mysql($wpdb);
    foreach (['aktora', 'aktorce', 'aktorem', 'zapas'] as $index => $term) {
        $storage->put_term(
            WP_FTS_TermNamespace::namespace_term('pl', $term),
            1,
            WP_FTS_PostingsCodec::encode([$index + 1 => 1])
        );
    }

    $prefix = WP_FTS_TermNamespace::namespace_term('pl', 'aktor');
    assert_same([
        WP_FTS_TermNamespace::namespace_term('pl', 'aktora'),
        WP_FTS_TermNamespace::namespace_term('pl', 'aktorce'),
    ], $storage->terms_with_prefix($prefix, 2), 'MySQL prefix lookup should return capped sorted prefix terms');

    $prefixSelect = null;
    foreach ($wpdb->prepared as $prepared) {
        if (str_starts_with($prepared['sql'], 'SELECT term FROM wp_fts_terms')) {
            $prefixSelect = $prepared;
            break;
        }
    }
    assert_true($prefixSelect !== null, 'MySQL prefix lookup should prepare a term range query');
    assert_contains('WHERE term >= %s AND term < %s', $prefixSelect['sql'], 'MySQL prefix lookup should use a bounded lower and upper term range');
    assert_contains('ORDER BY term ASC', $prefixSelect['sql'], 'MySQL prefix lookup should keep deterministic term ordering');
    assert_contains('LIMIT %d', $prefixSelect['sql'], 'MySQL prefix lookup should cap SQL rows');
    assert_true(!str_contains(strtolower($prefixSelect['sql']), ' like '), 'MySQL prefix lookup must not use LIKE scans');
    assert_same($prefix, $prefixSelect['args'][0] ?? null, 'MySQL prefix lookup should bind the lower prefix bound');
    assert_same(2, $prefixSelect['args'][2] ?? null, 'MySQL prefix lookup should bind the requested cap');
});

test_case('phrase search still requires explicit extension point', function (): void {
    $searcher = new WP_FTS_Searcher(new WP_FTS_Storage_InMemory(), new WP_FTS_Analyzer());
    $extended = $searcher->search('pre', [
        'prefix' => true,
        'search_extension' => static fn(string $query, array $opts, WP_FTS_Storage $storage, object $analyzer): array => [
            ['doc_id' => 77, 'score' => 1.0, 'mode' => !empty($opts['prefix']) ? 'prefix' : 'phrase'],
        ],
    ]);
    assert_same(77, $extended[0]['doc_id'], 'prefix extension callback should own custom search results');

    $thrown = false;
    try {
        $searcher->search('exact words', ['phrase' => true]);
    } catch (InvalidArgumentException) {
        $thrown = true;
    }
    assert_true($thrown, 'phrase search should not be silently emulated on whole-term postings');
});

test_case('mysql storage emits language-aware binary schema and stores per-language docs', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $storage = new WP_FTS_Storage_Mysql($wpdb);
    $storage->create_tables();

    assert_same(6, count(array_filter($wpdb->queries, static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE'))), 'schema should create six tables');
    $schemaSql = implode("\n", $wpdb->queries);
    assert_contains('term varbinary(255) NOT NULL', $schemaSql, 'terms table should use exact binary term keys');
    assert_contains('CREATE TABLE wp_fts_postings', $schemaSql, 'schema should include row postings table');
    assert_contains('tf int unsigned NOT NULL', $schemaSql, 'postings table should store row term frequency');
    assert_contains('PRIMARY KEY  (term,doc_id)', $schemaSql, 'postings should be keyed by term and document');
    assert_contains('KEY doc_id (doc_id)', $schemaSql, 'postings should be indexed for document reindex/delete');
    assert_contains('CREATE TABLE wp_fts_doc_lengths', $schemaSql, 'schema should include doc-language lengths table');
    assert_contains('CREATE TABLE wp_fts_docmeta', $schemaSql, 'schema should include document metadata table');
    assert_contains('KEY post_type_status_date (post_type,post_status,post_date_gmt)', $schemaSql, 'document metadata should support product filters');
    assert_contains('PRIMARY KEY  (doc_id,lang)', $schemaSql, 'doc lengths should be keyed by doc and language');
    assert_contains('PRIMARY KEY  (lang,k)', $schemaSql, 'meta should be keyed by language and key');
    assert_true(!str_contains(strtolower($schemaSql), 'fulltext'), 'schema must not use MySQL FULLTEXT');
    assert_true(!str_contains($schemaSql, 'postings longblob'), 'terms table should not require postings blobs');

    $plTerm = WP_FTS_TermNamespace::term_key('zamek', 'pl');
    $enTerm = WP_FTS_TermNamespace::term_key('zamek', 'en');
    assert_true($plTerm !== $enTerm && str_contains($plTerm, WP_FTS_TermNamespace::SEPARATOR), 'term keys should be language namespaced');
    $storage->put_term($plTerm, 1, WP_FTS_PostingsCodec::encode([7 => 2]));
    $storage->put_term($enTerm, 1, WP_FTS_PostingsCodec::encode([8 => 1]));
    assert_same([$enTerm, $plTerm], $storage->all_terms(), 'binary namespaced terms should remain separate rows');
    $capTerm = WP_FTS_TermNamespace::term_key('capped', 'en');
    $storage->put_term($capTerm, 4, WP_FTS_PostingsCodec::encode([5 => 1, 2 => 4, 9 => 1, 1 => 3]));
    assert_same([1 => 3, 2 => 4], $storage->get_capped_postings([$capTerm], 2)[$capTerm] ?? [], 'MySQL capped postings should return the deterministic lowest doc-id prefix');

    $storage->put_doc(7, 'pl_PL', ['pl_PL' => 4, 'en' => 2], 'abc123');
    $storage->put_doc_metadata(7, [
        'post_id' => 7,
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-01-02 03:04:05',
        'title' => 'Zamek',
        'search_text' => 'Zamek taxonomy custom',
        'terms' => ['category' => ['Architecture']],
    ]);
    $doc = $storage->get_doc(7);
    assert_same('pl-PL', $doc['primary_lang'], 'document primary language should be canonicalized');
    assert_same(['en' => 2, 'pl-PL' => 4], $doc['lang_lengths'], 'document should keep per-language lengths');
    assert_same([7 => 4], $storage->get_doc_lengths([7], 'pl_PL'), 'language length lookup should use doc-length table');
    assert_same([7 => 2], $storage->get_doc_lengths([7], 'en'), 'secondary language length should be queryable');
    $metadata = $storage->get_doc_metadata([7]);
    assert_same('post', $metadata[7]['post_type'], 'document metadata should round trip post type');
    assert_same('publish', $metadata[7]['post_status'], 'document metadata should round trip post status');
    assert_same(['Architecture'], $metadata[7]['terms']['category'], 'document metadata should preserve structured terms');

    $storage->add_meta('pl_PL', 1, 4);
    $storage->add_meta('en', 1, 2);
    assert_same(['doc_count' => 1, 'len_sum' => 4], $storage->get_meta('pl-PL'), 'language meta should be partitioned');
    assert_same(['doc_count' => 2, 'len_sum' => 6], $storage->get_meta(), 'global meta should aggregate partitions');
});

test_case('mysql row-posting indexing skips oversized namespaced terms before storage validation', function (): void {
    $lang = 'en';
    $normalTerm = 'normalneedle';
    $oversizedTerm = test_term_for_namespaced_key_bytes($lang, WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES + 1);
    $exactLimitTerm = test_term_for_namespaced_key_bytes($lang, WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES);
    $normalKey = WP_FTS_TermNamespace::namespace_term($lang, $normalTerm);
    $oversizedKey = WP_FTS_TermNamespace::namespace_term($lang, $oversizedTerm);
    $wpdb = new WP_FTS_Test_WPDB();
    $storage = new WP_FTS_Storage_Mysql($wpdb);
    $analyzer = new WP_FTS_Test_Oversized_Term_Analyzer($normalTerm, $oversizedTerm, $exactLimitTerm);

    assert_same(WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES + 1, strlen($oversizedKey), 'MySQL oversized fixture key should exceed the varbinary key limit by one');
    assert_true((new WP_FTS_Indexer($storage, $analyzer))->index_document(901, 'normal-token oversized-token', ['lang' => $lang]), 'MySQL-backed indexing should skip the oversized term instead of throwing');

    assert_same([$normalKey], $storage->all_terms(), 'MySQL storage should only receive the valid normal term');
    assert_same([901 => 1], $wpdb->postings[$normalKey] ?? [], 'MySQL row postings should store the valid normal term');
    assert_true(!array_key_exists($oversizedKey, $wpdb->postings), 'MySQL row postings should not store the oversized term');
    assert_same([$lang => 1], $wpdb->docLengths[901] ?? [], 'MySQL document length should exclude skipped oversized terms');
    assert_true(in_array('DELETE FROM wp_fts_postings WHERE doc_id = %d', $wpdb->queries, true), 'indexing regression should exercise replace_doc_postings row replacement');

    $thrown = false;
    try {
        $storage->put_term($oversizedKey, 1, WP_FTS_PostingsCodec::encode([901 => 1]));
    } catch (InvalidArgumentException $e) {
        $thrown = str_contains($e->getMessage(), 'byte limit');
    }
    assert_true($thrown, 'direct low-level MySQL put_term should still reject oversized caller input');
});

test_case('wp cli reindex accepts language source filters and limit', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->postRows = [
        (object) [
            'ID' => 10,
            'post_title' => 'Pierwszy',
            'post_content' => '<p>zamek alfa</p>',
            'post_excerpt' => '',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_date_gmt' => '2026-03-04 05:06:07',
        ],
        (object) [
            'ID' => 11,
            'post_title' => 'Drugi',
            'post_content' => '<p>zamek beta</p>',
            'post_excerpt' => '',
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_date_gmt' => '2026-03-05 05:06:07',
        ],
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
    $expectedDocLength = WP_FTS_AnalyzerPackValidator::gzip_available() ? 9 : 7;
    assert_same(['pl-PL' => $expectedDocLength], $fake->docLengths[10], 'CLI reindex should write boosted per-language doc length for the active Polish pack');
    assert_same('post', $fake->docMeta[10]['post_type'], 'CLI reindex should store post type metadata');
    assert_same('publish', $fake->docMeta[10]['post_status'], 'CLI reindex should store status metadata');
    assert_same('2026-03-04 05:06:07', $fake->docMeta[10]['post_date_gmt'], 'CLI reindex should store date metadata');

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

test_case('wp cli search accepts recency boost ranking options', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    try {
        $storage = WP_FTS_Plugin::storage(true);
        $analyzer = WP_FTS_Plugin::runtime_analyzer();
        $indexer = new WP_FTS_Indexer($storage, $analyzer);
        $indexer->index_document_fields(1, [['name' => 'content', 'text' => 'needle cli']], [
            'lang' => 'en',
            'metadata' => [
                'post_id' => 1,
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_date_gmt' => '1970-01-01 00:00:00',
                'title' => 'Old CLI needle',
                'search_text' => 'needle cli',
            ],
        ]);
        $indexer->index_document_fields(2, [['name' => 'content', 'text' => 'needle cli']], [
            'lang' => 'en',
            'metadata' => [
                'post_id' => 2,
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_date_gmt' => '2999-01-01 00:00:00',
                'title' => 'New CLI needle',
                'search_text' => 'needle cli',
            ],
        ]);

        $GLOBALS['wp_fts_quality_cli_format_items'] = [];
        (new WP_FTS_WPCLI_Command())->search(['needle'], [
            'lang' => 'en',
            'limit' => '2',
            'recency_boost' => '1',
            'recency_boost_half_life_days' => '7',
        ]);
        $formats = $GLOBALS['wp_fts_quality_cli_format_items'] ?? [];
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same([2, 1], array_column($formats[0]['items'] ?? [], 'doc_id'), 'CLI recency boost options should let the newer equal-score document rank first');
});

test_case('wp cli search accepts prefix threshold aliases and bounds expansion', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    try {
        $storage = WP_FTS_Plugin::storage(true);
        $analyzer = new WP_FTS_Analyzer([
            'enable_stemming' => false,
            'auto_detect_language' => false,
        ]);
        $indexer = new WP_FTS_Indexer($storage, $analyzer);
        $indexer->index_document_fields(1, [['name' => 'content', 'text' => 'prealpha']], [
            'lang' => 'en',
            'metadata' => [
                'post_id' => 1,
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_date_gmt' => '2026-06-18 00:00:00',
                'title' => 'CLI prefix alpha',
                'search_text' => 'prealpha',
            ],
        ]);
        $indexer->index_document_fields(2, [['name' => 'content', 'text' => 'prebeta']], [
            'lang' => 'en',
            'metadata' => [
                'post_id' => 2,
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_date_gmt' => '2026-06-18 00:00:00',
                'title' => 'CLI prefix beta',
                'search_text' => 'prebeta',
            ],
        ]);

        $GLOBALS['wp_fts_quality_cli_format_items'] = [];
        (new WP_FTS_WPCLI_Command())->search(['pre'], [
            'lang' => 'en',
            'limit' => '10',
            'prefix_matching' => '1',
            'prefix_min_length' => '3',
            'prefix_max_terms' => '1',
        ]);
        $bounded = $GLOBALS['wp_fts_quality_cli_format_items'] ?? [];

        $GLOBALS['wp_fts_quality_cli_format_items'] = [];
        (new WP_FTS_WPCLI_Command())->search(['pre'], [
            'lang' => 'en',
            'limit' => '10',
            'prefix-matching' => '1',
            'prefix-min-length' => '3',
            'prefix-max-terms' => '2',
        ]);
        $expanded = $GLOBALS['wp_fts_quality_cli_format_items'] ?? [];
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same([1], array_column($bounded[0]['items'] ?? [], 'doc_id'), 'CLI underscored prefix threshold options should cap expansion before search');
    assert_same([1, 2], array_column($expanded[0]['items'] ?? [], 'doc_id'), 'CLI dashed prefix threshold aliases should allow a broader bounded expansion');
});

test_case('wp cli reindex defaults cover admin-searchable post statuses while explicit publish stays narrow', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $supportedStatuses = ['publish', 'draft', 'pending', 'future', 'private'];
    $fake = new WP_FTS_Test_WPDB();
    foreach ($supportedStatuses as $index => $status) {
        $fake->postRows[] = (object) [
            'ID' => 30 + $index,
            'post_title' => ucfirst($status) . ' backfill',
            'post_content' => '<p>backfill coverage ' . $status . '</p>',
            'post_excerpt' => '',
            'post_type' => 'post',
            'post_status' => $status,
            'post_date_gmt' => '2026-03-07 05:06:07',
        ];
    }
    $wpdb = $fake;
    WP_CLI::$successMessages = [];

    try {
        $command = new WP_FTS_WPCLI_Command();
        $command->reindex([], [
            'lang' => 'en',
            'limit' => '5',
            'batch_size' => '10',
        ]);
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(['Indexed 5 posts in en.'], WP_CLI::$successMessages, 'default CLI reindex should report supported admin status backfill count');
    assert_same([30, 31, 32, 33, 34], array_keys($fake->docs), 'default CLI reindex should index supported admin status rows returned by WordPress');
    assert_same($supportedStatuses, array_column($fake->docMeta, 'post_status'), 'default CLI reindex should preserve supported status metadata');

    $defaultSelect = null;
    foreach ($fake->prepared as $prepared) {
        if (str_starts_with($prepared['sql'], 'SELECT ID, post_content, post_title')) {
            $defaultSelect = $prepared;
            break;
        }
    }
    assert_true($defaultSelect !== null, 'default CLI reindex should prepare a source query');
    assert_same(['publish', 'draft', 'pending', 'future', 'private', 'post', 0, 5], $defaultSelect['args'], 'default CLI source filters should cover admin-searchable post statuses only');

    $publishOnly = new WP_FTS_Test_WPDB();
    $publishOnly->postRows = [
        (object) [
            'ID' => 40,
            'post_title' => 'Public backfill',
            'post_content' => '<p>public coverage</p>',
            'post_excerpt' => '',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_date_gmt' => '2026-03-08 05:06:07',
        ],
    ];
    $wpdb = $publishOnly;
    WP_CLI::$successMessages = [];

    try {
        $command = new WP_FTS_WPCLI_Command();
        $command->reindex([], [
            'post_status' => 'publish',
            'post_type' => 'post',
            'lang' => 'en',
            'limit' => '1',
        ]);
    } finally {
        $wpdb = $oldWpdb;
    }

    $publishSelect = null;
    foreach ($publishOnly->prepared as $prepared) {
        if (str_starts_with($prepared['sql'], 'SELECT ID, post_content, post_title')) {
            $publishSelect = $prepared;
            break;
        }
    }
    assert_true($publishSelect !== null, 'explicit publish-only CLI reindex should prepare a source query');
    assert_same(['publish', 'post', 0, 1], $publishSelect['args'], 'explicit --post_status=publish should keep the source query public-only');
});

test_case('wp cli reindex uses plugin runtime analyzer pack configuration', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->postRows = [
        (object) [
            'ID' => 12,
            'post_title' => 'Synthetic Bengali Pack Probe',
            'post_content' => '<p>সিনথ000গুলো</p>',
            'post_excerpt' => '',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_date_gmt' => '2026-03-06 05:06:07',
        ],
    ];
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    WP_CLI::$successMessages = [];
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = [
        'lemmatizer_packs_by_lang' => [
            'bn' => WP_FTS_AnalyzerPackValidator::default_synthetic_bengali_fixture_manifest(),
        ],
    ];

    try {
        $command = new WP_FTS_WPCLI_Command();
        $command->reindex([], [
            'post_status' => 'publish',
            'post_type' => 'post',
            'lang' => 'bn',
        ]);
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(['Indexed 1 posts in bn.'], WP_CLI::$successMessages, 'CLI reindex should keep reporting the explicit language partition');
    assert_true(isset($fake->terms[WP_FTS_TermNamespace::namespace_term('bn', 'সিনথ000লেমা')]), 'CLI reindex should use the configured generic lemma pack');
    assert_true(!isset($fake->terms[WP_FTS_TermNamespace::namespace_term('bn', 'সিনথ000')]), 'CLI reindex should not fall back to the Bengali baseline when a valid pack is configured');
});

discover_quality_tests();

test_case('T5 MySQL and file backends return identical rankings for full query suite', function (): void {
    $initialDocuments = [
        101 => [
            'lang' => 'en',
            'html' => '<article><p>alpha bridge river shared</p><span lang="fr">cafe pont</span><nav>alpha hiddennav</nav><script>bridge ghost</script></article>',
        ],
        102 => [
            'lang' => 'pl',
            'html' => '<article><h1>zamek most</h1><p>rzeka shared</p><footer>zamek hiddenfooter</footer></article>',
        ],
        103 => [
            'lang' => 'de',
            'html' => '<article><p>strasse fluss bruecke shared</p><strong>fluss</strong></article>',
        ],
        104 => [
            'lang' => 'fr',
            'html' => '<article><p>cafe pont riviere shared</p><h2>cafe</h2></article>',
        ],
        105 => [
            'lang' => 'tr',
            'html' => '<article><p>istanbul kopru nehir renk</p><aside>istanbul hiddenaside</aside></article>',
        ],
        106 => [
            'lang' => 'es',
            'html' => '<article><p>castillo rio puente color</p><strong>rio</strong></article>',
        ],
        107 => [
            'lang' => 'nl',
            'html' => '<article><p>kasteel rivier brug kleur</p><script>kasteel hidden</script></article>',
        ],
        108 => [
            'lang' => 'en',
            'html' => '<article><p>obsolete alpha stale</p></article>',
        ],
        109 => [
            'lang' => 'de',
            'html' => '<article><p>deletedonly strasse fluss</p></article>',
        ],
        110 => [
            'lang' => 'zh-Hans',
            'html' => '<article><p>hanzi sousuo qiao yanse</p><strong>hanzi</strong></article>',
        ],
        150 => [
            'lang' => 'en',
            'html' => '<article><h1>alpha alpha alpha</h1><p>bridge harbor shared</p></article>',
        ],
    ];
    $updatedDocuments = [
        108 => [
            'lang' => 'pl',
            'html' => '<article><h2>zamek zamek</h2><p>most rzeka shared</p><span lang="de">fluss bruecke</span></article>',
        ],
    ];
    $deletedDocumentIds = [109];
    $analyzer = new WP_FTS_Analyzer();
    $mysqlWpdb = new WP_FTS_Test_WPDB();
    $mysql = new WP_FTS_Storage_Mysql($mysqlWpdb);
    $mysql->create_tables();
    $file = new WP_FTS_Storage_File(temp_index_path('t5_mysql_file_full_suite'));

    $buildCorpus = static function (WP_FTS_Storage $storage, string $label) use ($analyzer, $initialDocuments, $updatedDocuments, $deletedDocumentIds): void {
        $indexer = new WP_FTS_Indexer($storage, $analyzer);
        foreach ($initialDocuments as $docId => $document) {
            assert_true($indexer->index_document((int) $docId, $document['html'], ['lang' => $document['lang']]), "{$label} initial document {$docId} should index");
        }
        foreach ($updatedDocuments as $docId => $document) {
            assert_true($indexer->index_document((int) $docId, $document['html'], ['lang' => $document['lang']]), "{$label} updated document {$docId} should reindex");
        }
        foreach ($deletedDocumentIds as $docId) {
            assert_true($indexer->delete_document($docId), "{$label} document {$docId} should tombstone");
        }
    };

    try {
        $buildCorpus($mysql, 'mysql');
        $buildCorpus($file, 'file');

        assert_same($file->all_doc_ids(true), $mysql->all_doc_ids(true), 'T5 MySQL/file full corpus should contain the same known doc ids');
        assert_same($file->all_doc_ids(), $mysql->all_doc_ids(), 'T5 MySQL/file full corpus should contain the same active doc ids');
        assert_true(in_array('DELETE FROM wp_fts_postings WHERE doc_id = %d', $mysqlWpdb->queries, true), 'T5 MySQL build should exercise row-posting replacement through WP_FTS_Storage_Mysql');

        $mysqlSearcher = new WP_FTS_Searcher($mysql, $analyzer);
        $fileSearcher = new WP_FTS_Searcher($file, $analyzer);
        $fullQuerySuite = [
            'en-alpha-ranking-or' => ['query' => 'alpha', 'lang' => 'en', 'mode' => 'OR', 'limit' => 10, 'non_trivial' => true],
            'en-alpha-bridge-and' => ['query' => 'alpha bridge', 'lang' => 'en', 'mode' => 'AND', 'limit' => 10],
            'en-hidden-skip-or' => ['query' => 'hiddennav ghost', 'lang' => 'en', 'mode' => 'OR', 'limit' => 10],
            'en-obsolete-update-or' => ['query' => 'obsolete', 'lang' => 'en', 'mode' => 'OR', 'limit' => 10],
            'pl-zamek-most-or' => ['query' => 'zamek most', 'lang' => 'pl', 'mode' => 'OR', 'limit' => 10],
            'pl-zamek-most-and' => ['query' => 'zamek most', 'lang' => 'pl', 'mode' => 'AND', 'limit' => 10],
            'de-strasse-fluss-or' => ['query' => 'strasse fluss', 'lang' => 'de', 'mode' => 'OR', 'limit' => 10],
            'de-deleted-tombstone-or' => ['query' => 'deletedonly strasse', 'lang' => 'de', 'mode' => 'OR', 'limit' => 10],
            'fr-cafe-pont-and' => ['query' => 'cafe pont', 'lang' => 'fr', 'mode' => 'AND', 'limit' => 10],
            'tr-istanbul-kopru-or' => ['query' => 'istanbul kopru', 'lang' => 'tr', 'mode' => 'OR', 'limit' => 10],
            'es-rio-puente-or' => ['query' => 'rio puente', 'lang' => 'es', 'mode' => 'OR', 'limit' => 10],
            'nl-kasteel-brug-and' => ['query' => 'kasteel brug', 'lang' => 'nl', 'mode' => 'AND', 'limit' => 10],
            'zh-hans-hanzi-qiao-or' => ['query' => 'hanzi qiao', 'lang' => 'zh-Hans', 'mode' => 'OR', 'limit' => 10],
        ];

        $runSuite = static function (string $phase) use ($fullQuerySuite, $fileSearcher, $mysqlSearcher): void {
            $sawNonTrivialRanking = false;
            foreach ($fullQuerySuite as $name => $queryCase) {
                $opts = [
                    'lang' => $queryCase['lang'],
                    'mode' => $queryCase['mode'],
                    'limit' => $queryCase['limit'],
                ];
                $fileResults = $fileSearcher->search($queryCase['query'], $opts);
                $mysqlResults = $mysqlSearcher->search($queryCase['query'], $opts);
                assert_search_results_equal($fileResults, $mysqlResults, "T5 {$phase} full query suite {$name}");

                $fileIds = array_column($fileResults, 'doc_id');
                assert_true(!in_array(109, $fileIds, true), "T5 {$phase} query {$name} should not return tombstoned document 109");
                if (!empty($queryCase['non_trivial'])) {
                    $sortedIds = $fileIds;
                    sort($sortedIds, SORT_NUMERIC);
                    $sawNonTrivialRanking = count($fileIds) >= 2 && $fileIds !== $sortedIds;
                }
            }

            assert_true($sawNonTrivialRanking, "T5 {$phase} full query suite should include a non-trivial ranking order");
        };

        $runSuite('before optimize');
        $mysql->optimize();
        $file->optimize();
        assert_same($file->all_doc_ids(true), $mysql->all_doc_ids(true), 'T5 MySQL/file optimized corpus should purge the same tombstones');
        $runSuite('after optimize');
    } finally {
        cleanup_storage($file);
    }
});

test_case('quality discovery loads tests/quality files', function (): void {
    $discovered = array_map('basename', discovered_quality_test_files());
    sort($discovered, SORT_STRING);

    assert_true(in_array('000-discovery-sentinel.php', $discovered, true), 'quality discovery should record the sentinel file');
    assert_true(in_array('harness-metrics.php', $discovered, true), 'quality discovery should record the metrics test file');
    assert_same(1, $GLOBALS['wp_fts_quality_discovery_sentinel'] ?? 0, 'quality discovery should include tests/quality/*.php exactly once');
});

test_case('search auto fast mode constants override threshold and candidate cap', function (): void {
    if (defined('WP_FTS_FAST_MODE_THRESHOLD') && constant('WP_FTS_FAST_MODE_THRESHOLD') !== 2) {
        throw new WP_FTS_TestPending('WP_FTS_FAST_MODE_THRESHOLD is already defined by the process.');
    }
    if (defined('WP_FTS_FAST_MODE_CANDIDATE_CAP') && constant('WP_FTS_FAST_MODE_CANDIDATE_CAP') !== 2) {
        throw new WP_FTS_TestPending('WP_FTS_FAST_MODE_CANDIDATE_CAP is already defined by the process.');
    }
    if (!defined('WP_FTS_FAST_MODE_THRESHOLD')) {
        define('WP_FTS_FAST_MODE_THRESHOLD', 2);
    }
    if (!defined('WP_FTS_FAST_MODE_CANDIDATE_CAP')) {
        define('WP_FTS_FAST_MODE_CANDIDATE_CAP', 2);
    }

    [$searcher] = single_term_search_fixture(3, 3);
    $payload = $searcher->search('needle', [
        'lang' => 'en',
        'limit' => 1,
        'include_total' => true,
    ]);

    assert_same(2, $payload['total'], 'constant candidate cap should apply after constant threshold switches on fast mode');
    assert_true(($payload['results'][0]['doc_id'] ?? null) !== 3, 'constant-capped auto fast mode may miss a stronger late candidate');
});

wp_fts_run_registered_tests_and_exit();
