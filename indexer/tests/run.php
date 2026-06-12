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

function write_synthetic_full_analyzer_pack(string $directory, int $rows, int $shards): string
{
    if ($rows < 1 || $shards < 1) {
        throw new WP_FTS_TestFailure('Synthetic analyzer pack requires positive row and shard counts.');
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
        'pack_id' => 'pl-polimorf-synthetic-full-streaming-fixture',
        'language' => 'pl',
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
            'normalization' => 'WP_FTS_Normalizer pl with fold_diacritics=true',
            'ambiguity_policy' => 'ambiguous_surface_noop',
            'total_rows' => $rows,
            'total_sha256' => hash_final($runtimeDigest),
            'files' => $runtimeFiles,
        ],
        'source' => [
            'name' => 'Synthetic PoliMorf streaming validator fixture',
            'version' => 'test',
            'url' => 'urn:wp-fts:test:synthetic-polimorf-streaming-validator',
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
    public ?object $dbh = null;
    public bool $missPreparedTermLookups = false;

    /** @var array<int,string> */
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
     * @return object[]
     */
    public function get_results(mixed $statement): array
    {
        [$sql, $args] = $this->statement_parts($statement);
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
    $GLOBALS['wp_fts_test_admin_pages'] = [];
    $GLOBALS['wp_fts_test_posts'] = [];
    $GLOBALS['wp_fts_test_next_post_id'] = 1000;
    $GLOBALS['wp_fts_test_get_post_callbacks'] = [];
    $GLOBALS['wp_fts_test_do_blocks'] = [];
    $GLOBALS['wp_fts_test_filters'] = [];
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

if (!function_exists('add_management_page')) {
    function add_management_page(string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $callback = '', mixed ...$unused): string
    {
        $GLOBALS['wp_fts_test_admin_pages'][] = [
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'menu_slug' => $menu_slug,
            'callback' => $callback,
        ];

        return 'tools_page_' . $menu_slug;
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return '/wp-admin/' . ltrim($path, '/');
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
        'admin_menu',
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

test_case('admin menu registration exposes a Tools FTS sandbox page', function (): void {
    wp_fts_test_reset_wordpress_fakes();

    WP_FTS_Plugin::register_admin_menu();
    $page = $GLOBALS['wp_fts_test_admin_pages'][0] ?? null;

    assert_same('FTS Sandbox', $page['page_title'] ?? null, 'admin page should use a clear page title');
    assert_same('FTS Sandbox', $page['menu_title'] ?? null, 'admin page should use a clear Tools menu label');
    assert_same(WP_FTS_Plugin::ADMIN_CAPABILITY, $page['capability'] ?? null, 'admin page should require the configured capability');
    assert_same(WP_FTS_Plugin::ADMIN_PAGE_SLUG, $page['menu_slug'] ?? null, 'admin page should use the stable sandbox slug');
    assert_same([WP_FTS_Plugin::class, 'render_admin_sandbox'], $page['callback'] ?? null, 'admin page should render through the plugin callback');
});

test_case('authorized admin sandbox render includes search form and nonce-protected actions', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $_GET = [];
    $_POST = [];

    try {
        $html = wp_fts_test_capture_admin_sandbox();
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
    }

    assert_contains('Pure PHP FTS Sandbox', $html, 'sandbox page should render for authorized admins');
    assert_contains('name="wp_fts_sandbox_query"', $html, 'sandbox page should include the search query field');
    assert_contains('name="wp_fts_sandbox_lang"', $html, 'sandbox page should include the query language selector');
    assert_contains('value="auto"', $html, 'sandbox language selector should include automatic detection');
    assert_contains('value="en"', $html, 'sandbox language selector should include English');
    assert_contains('value="pl"', $html, 'sandbox language selector should include Polish');
    assert_contains('value="de"', $html, 'sandbox language selector should include German');
    assert_contains('name="wp_fts_sandbox_nonce"', $html, 'sandbox page should include action nonces');
    assert_contains('value="nonce-wp_fts_sandbox_admin_action"', $html, 'sandbox page should render the expected nonce value in the fake harness');
    assert_contains('Suggested English stemming query: <code>run</code>', $html, 'sandbox page should suggest the stemming demo query');
    assert_contains('Suggested Polish lemmatizer queries: <code>wyszukiwanie</code>, <code>wpis</code>, <code>kierować</code>, <code>zamek</code>', $html, 'sandbox page should suggest pack-backed Polish lemmatizer queries');
    assert_contains('<th scope="col">Language</th>', $html, 'sandbox demo table should include a language column');
    assert_contains('<th scope="col">Content preview</th>', $html, 'sandbox demo table should include a content preview column');
    assert_contains('English (en)', $html, 'sandbox demo table should identify the English demo row');
    assert_contains('Polish (pl)', $html, 'sandbox demo table should identify the Polish demo row');
    assert_contains('German (de)', $html, 'sandbox demo table should identify the German demo row');
    assert_contains('The athlete is running', $html, 'sandbox demo table should preview the English demo content');
    assert_contains('W książkach i zamkach wyszukujemy wpisy oraz kierujemy katalog.', $html, 'sandbox demo table should preview the pack-backed Polish demo content');
    assert_contains('Führung und Straße', $html, 'sandbox demo table should preview the German demo content');
});

test_case('unauthorized admin sandbox render is blocked safely', function (): void {
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
    }

    assert_contains('You do not have permission to use the FTS sandbox.', $html, 'sandbox page should show a safe unauthorized message');
    assert_true(!str_contains($html, 'name="wp_fts_sandbox_action"'), 'unauthorized sandbox page should not render mutating action controls');
});

test_case('admin sandbox demo indexing supports requested and detected languages', function (): void {
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
            'wp_fts_sandbox_nonce' => wp_create_nonce('wp_fts_sandbox_admin_action'),
        ];
        $refreshHtml = wp_fts_test_capture_admin_sandbox();
        $demoPostIds = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SANDBOX_DEMO_POSTS_OPTION] ?? [];
        assert_same(3, count($demoPostIds), 'refresh action should create the three demo posts');
        assert_contains('Demo posts are ready', $refreshHtml, 'refresh action should report created demo posts');

        $_POST = [
            'wp_fts_sandbox_action' => 'index_demo',
            'wp_fts_sandbox_nonce' => wp_create_nonce('wp_fts_sandbox_admin_action'),
        ];
        $indexHtml = wp_fts_test_capture_admin_sandbox();
        assert_contains('Processed 3 demo post(s) into the FTS index.', $indexHtml, 'index action should report the processed demo corpus');
        assert_true($fake->terms !== [], 'index action should write FTS terms for the demo corpus');
        $metadata = WP_FTS_Plugin::storage(false)->get_doc_metadata($demoPostIds);
        assert_same('en', $metadata[$demoPostIds[0]]['language'] ?? null, 'index action should forward English demo language metadata');
        assert_same('pl', $metadata[$demoPostIds[1]]['language'] ?? null, 'index action should forward Polish demo language metadata');
        assert_same('de', $metadata[$demoPostIds[2]]['language'] ?? null, 'index action should forward German demo language metadata');
        $polishSearchLemma = WP_FTS_TermNamespace::namespace_term('pl', 'wyszukiwac');
        $polishEntryLemma = WP_FTS_TermNamespace::namespace_term('pl', 'wpis');
        $polishRouteLemma = WP_FTS_TermNamespace::namespace_term('pl', 'kierowac');
        $polishCastleLemma = WP_FTS_TermNamespace::namespace_term('pl', 'zamek');
        assert_true(isset($fake->terms[$polishSearchLemma]), 'sandbox indexing should store the Polish lemmatizer-pack search lemma');
        assert_true(isset($fake->terms[$polishEntryLemma]), 'sandbox indexing should store the Polish lemmatizer-pack entry lemma');
        assert_true(isset($fake->terms[$polishRouteLemma]), 'sandbox indexing should store the Polish lemmatizer-pack routing lemma');
        assert_true(isset($fake->terms[$polishCastleLemma]), 'sandbox indexing should store the Polish lemmatizer-pack castle lemma');
        assert_true(!isset($fake->terms[WP_FTS_TermNamespace::namespace_term('pl', 'wyszukujemy')]), 'sandbox indexing should not store the exact Polish document search surface');
        assert_true(!isset($fake->terms[WP_FTS_TermNamespace::namespace_term('pl', 'wyszukiwanie')]), 'sandbox indexing should not store the exact Polish query search surface');
        assert_true(!isset($fake->terms[WP_FTS_TermNamespace::namespace_term('pl', 'wpisy')]), 'sandbox indexing should not store the exact Polish document entry surface');
        assert_true(!isset($fake->terms[WP_FTS_TermNamespace::namespace_term('pl', 'wpisach')]), 'sandbox indexing should not store the exact Polish query entry surface');
        assert_true(!isset($fake->terms[WP_FTS_TermNamespace::namespace_term('pl', 'kierujemy')]), 'sandbox indexing should not store the exact Polish document routing surface');
        assert_true(!isset($fake->terms[WP_FTS_TermNamespace::namespace_term('pl', 'zamkach')]), 'sandbox indexing should not store the exact Polish document castle surface');
        assert_true(!isset($fake->terms[WP_FTS_TermNamespace::namespace_term('pl', 'wyszuk')]), 'sandbox indexing should not depend on the removed verified-stemmer search family');

        $search = static function (string $query, string $language): string {
            $_POST = [];
            $_GET = [
                'page' => WP_FTS_Plugin::ADMIN_PAGE_SLUG,
                'wp_fts_sandbox_query' => $query,
                'wp_fts_sandbox_lang' => $language,
                'wp_fts_sandbox_search' => '1',
            ];

            return wp_fts_test_capture_admin_sandbox();
        };

        $englishHtml = $search('run', 'en');
        assert_contains('Search returned', $englishHtml, 'English search action should report result count');
        assert_contains('Requested query language: <code>en</code>', $englishHtml, 'explicit English search should report the requested language');
        assert_contains('Resolved query language: <code>en</code>', $englishHtml, 'explicit English search should report the resolved language');
        assert_contains('FTS Sandbox: Running Notes', $englishHtml, 'explicit English search should find the English demo post');

        $polishHtml = $search('zamek', 'pl');
        assert_contains('Requested query language: <code>pl</code>', $polishHtml, 'explicit Polish search should report the requested language');
        assert_contains('Resolved query language: <code>pl</code>', $polishHtml, 'explicit Polish search should report the resolved language');
        assert_contains('FTS Sandbox: Polish Lemmatizer Demo', $polishHtml, 'explicit Polish search should find the Polish demo post through a castle lemma query');

        foreach ([
            'wyszukiwanie' => 'pack-backed Polish lemmatizer should match wyszukiwanie to the wyszukujemy demo post',
            'wpis' => 'pack-backed Polish lemmatizer should match the wpis lemma to the wpisy demo post',
            'wpisach' => 'pack-backed Polish lemmatizer should match an entry form absent from the demo text',
            'kierować' => 'pack-backed Polish lemmatizer should match a routing infinitive to kierujemy',
        ] as $query => $message) {
            $polishMorphologyHtml = $search($query, 'pl');
            assert_contains('Requested query language: <code>pl</code>', $polishMorphologyHtml, 'explicit Polish morphology search should report the requested language for ' . $query);
            assert_contains('Resolved query language: <code>pl</code>', $polishMorphologyHtml, 'explicit Polish morphology search should report the resolved language for ' . $query);
            assert_contains('FTS Sandbox: Polish Lemmatizer Demo', $polishMorphologyHtml, $message);
            if ($query === 'wyszukiwanie') {
                assert_contains('<mark>wyszukujemy</mark>', $polishMorphologyHtml, 'sandbox results should mark the finite document search form for wyszukiwanie');
            } elseif ($query === 'wpis') {
                assert_contains('<mark>wpisy</mark>', $polishMorphologyHtml, 'sandbox results should mark the plural document entry form for wpis');
                assert_true(!str_contains($polishMorphologyHtml, '<mark>wpis</mark>y'), 'sandbox results should not split the Polish entry document form');
            }
        }

        $germanHtml = $search('Fuehrung', 'de');
        assert_contains('Requested query language: <code>de</code>', $germanHtml, 'explicit German search should report the requested language');
        assert_contains('Resolved query language: <code>de</code>', $germanHtml, 'explicit German search should report the resolved language');
        assert_contains('FTS Sandbox: German Fuehrung', $germanHtml, 'explicit German search should find the German demo post');

        $autoHtml = $search('książkach zamkach', 'auto');
        assert_contains('Requested query language: <code>auto</code>', $autoHtml, 'automatic search should report the requested language');
        assert_contains('Resolved query language: <code>pl</code>', $autoHtml, 'automatic non-English search should report the detected language');
        assert_contains('FTS Sandbox: Polish Lemmatizer Demo', $autoHtml, 'automatic Polish search should find the Polish demo post');
        assert_true(!str_contains($autoHtml, 'Resolved query language: <code>en</code>'), 'automatic non-English search should not hard-code English as the resolved language');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $wpdb = $oldWpdb;
    }
});

test_case('admin sandbox demo refresh preserves language order after recreated post', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_caps'][WP_FTS_Plugin::ADMIN_CAPABILITY][0] = true;

    $refreshDemo = static function (): string {
        $_GET = [];
        $_POST = [
            'wp_fts_sandbox_action' => 'refresh_demo',
            'wp_fts_sandbox_nonce' => wp_create_nonce('wp_fts_sandbox_admin_action'),
        ];

        return wp_fts_test_capture_admin_sandbox();
    };
    $renderSandbox = static function (): string {
        $_GET = [];
        $_POST = [];

        return wp_fts_test_capture_admin_sandbox();
    };
    $indexDemo = static function (): string {
        $_GET = [];
        $_POST = [
            'wp_fts_sandbox_action' => 'index_demo',
            'wp_fts_sandbox_nonce' => wp_create_nonce('wp_fts_sandbox_admin_action'),
        ];

        return wp_fts_test_capture_admin_sandbox();
    };
    $assertDemoRows = static function (string $html, array $postIds): void {
        $fragments = [
            sprintf('<td>%d</td><td>FTS Sandbox: Running Notes</td><td>English (en)</td><td>The athlete is running', $postIds[0]),
            sprintf('<td>%d</td><td>FTS Sandbox: Polish Lemmatizer Demo</td><td>Polish (pl)</td><td>W książkach i zamkach wyszukujemy wpisy oraz kierujemy katalog.', $postIds[1]),
            sprintf('<td>%d</td><td>FTS Sandbox: German Fuehrung</td><td>German (de)</td><td>Führung und Straße', $postIds[2]),
        ];
        $lastPosition = -1;
        foreach ($fragments as $fragment) {
            $position = strpos($html, $fragment);
            assert_true($position !== false, 'sandbox demo table should keep title, language, and preview aligned for ' . $fragment);
            $position = is_int($position) ? $position : -1;
            assert_true($position > $lastPosition, 'sandbox demo table should preserve English, Polish, German corpus order');
            $lastPosition = $position;
        }
    };

    try {
        $refreshDemo();
        $initialPostIds = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SANDBOX_DEMO_POSTS_OPTION] ?? [];
        assert_same(3, count($initialPostIds), 'initial refresh should create the three demo posts');

        unset($GLOBALS['wp_fts_test_posts'][$initialPostIds[0]]);

        $secondRefreshHtml = $refreshDemo();
        $refreshedPostIds = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SANDBOX_DEMO_POSTS_OPTION] ?? [];
        assert_same(3, count($refreshedPostIds), 'second refresh should still track the three demo posts');
        assert_contains('Demo posts are ready', $secondRefreshHtml, 'second refresh should report recreated demo posts');
        assert_true($refreshedPostIds[0] !== $initialPostIds[0], 'missing English demo post should be recreated with a new ID');
        assert_same($initialPostIds[1], $refreshedPostIds[1], 'second refresh should keep the existing Polish demo post ID');
        assert_same($initialPostIds[2], $refreshedPostIds[2], 'second refresh should keep the existing German demo post ID');
        assert_true($refreshedPostIds[0] > $refreshedPostIds[1], 'regression setup should store a recreated English ID before older lower IDs');

        $readHtml = $renderSandbox();
        assert_contains('Demo post IDs: ' . implode(', ', $refreshedPostIds) . '.', $readHtml, 'sandbox should display demo IDs in stored corpus order');
        $assertDemoRows($readHtml, $refreshedPostIds);

        $indexHtml = $indexDemo();
        assert_contains('Processed 3 demo post(s) into the FTS index.', $indexHtml, 'index action should process the refreshed demo corpus');
        $metadata = WP_FTS_Plugin::storage(false)->get_doc_metadata($refreshedPostIds);
        assert_same('en', $metadata[$refreshedPostIds[0]]['language'] ?? null, 'index action should align recreated English demo metadata');
        assert_same('pl', $metadata[$refreshedPostIds[1]]['language'] ?? null, 'index action should align existing Polish demo metadata');
        assert_same('de', $metadata[$refreshedPostIds[2]]['language'] ?? null, 'index action should align existing German demo metadata');
        assert_same('en', $fake->docs[$refreshedPostIds[0]]['lang'] ?? null, 'index action should use English options for the recreated demo document');
        assert_same('pl', $fake->docs[$refreshedPostIds[1]]['lang'] ?? null, 'index action should use Polish options for the existing demo document');
        assert_same('de', $fake->docs[$refreshedPostIds[2]]['lang'] ?? null, 'index action should use German options for the existing demo document');
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

    try {
        WP_FTS_Plugin::activate();
        assert_same(WP_FTS_Plugin::SCHEMA_VERSION, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null, 'activation should store schema version option');
        assert_same(6, count(array_filter($fake->queries, static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE'))), 'activation should create or repair all FTS tables');
        assert_true(isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), 'activation should schedule the queue processor');

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
        assert_same([101], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] ?? [], 'save hooks should queue each post id only once');
        assert_true(isset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]), 'save hook should schedule background processing');

        assert_same(1, WP_FTS_Plugin::process_queue(1), 'queue processor should process a bounded batch');
        assert_same([], $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::QUEUE_OPTION] ?? [], 'processed ids should leave the queue');
        assert_true(isset($fake->docs[101]) && $fake->docs[101]['is_deleted'] === 0, 'queue processing should write an active document');
        assert_true($fake->terms !== [], 'queue processing should write term postings');
        assert_same([101], array_column(WP_FTS_Plugin::search('alpha', ['limit' => 10]), 'doc_id'), 'search helper should expose the indexed public post');
        assert_same([101], array_column(WP_FTS_Plugin::search('RuntimeExcerptSignal', ['limit' => 10]), 'doc_id'), 'queued indexing should include extracted excerpts');
        assert_same([101], array_column(WP_FTS_Plugin::search('RuntimeCustomSignal', ['limit' => 10]), 'doc_id'), 'queued indexing should include selected custom fields');
        assert_same([101], array_column(WP_FTS_Plugin::search('RuntimeRenderedSignal', ['limit' => 10]), 'doc_id'), 'queued indexing should include rendered-only block output');
        $filtered = (new WP_FTS_Searcher(WP_FTS_Plugin::storage(false), new WP_FTS_Analyzer()))->search('Needle', [
            'lang' => 'en',
            'include_total' => true,
            'post_status' => 'publish',
        ]);
        assert_same(1, $filtered['total'], 'queued indexing should write metadata usable by status filters');
        assert_contains('RuntimeExcerptSignal', $fake->docMeta[101]['search_text'] ?? '', 'queued metadata should keep excerpt text for snippets');
        assert_contains('RuntimeCustomSignal', $fake->docMeta[101]['search_text'] ?? '', 'queued metadata should keep custom field text for snippets');
        assert_contains('RuntimeRenderedSignal', $fake->docMeta[101]['search_text'] ?? '', 'queued metadata should keep rendered text for snippets');

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

test_case('snowball and polish stemmer adapters are guarded and pluggable', function (): void {
    $snowball = new WP_FTS_SnowballStemmer();
    assert_same('kotami', $snowball->stem('kotami', 'pl'), 'Snowball adapter should no-op unsupported languages');
    assert_true($snowball->supports_language('en-US'), 'Snowball adapter should advertise verified English support');
    assert_true($snowball->is_language_available('en'), 'English Snowball stemmer should be bundled without Wamania');
    assert_contains('Snowball English (Porter2)', $snowball->source_identity('en'), 'English stemmer should expose its variant identity');
    assert_same('run', $snowball->stem('running', 'en'), 'Snowball adapter should use the verified English implementation');

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
    assert_same(['drogi'], $packPipeline->analyze('drogi', 'pl'), 'enabled fixture pack should no-op ambiguous rows');
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
        ['English normalization', 'en', 'running runs runner', ['run', 'run', 'runner']],
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

test_case('CJK tokenizer hooks override bigrams while preserving fallback', function (): void {
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
    assert_same(['中文', '文搜', '搜索'], $fallback->analyze_query('中文搜索', ['lang' => 'zh-Hans']), 'empty custom CJK tokenizer output should fall back to bigrams');
});

test_case('Chinese normalization hooks are explicit and default script behavior is unchanged', function (): void {
    $default = new WP_FTS_Analyzer();
    assert_same(['繁體', '體搜', '搜索'], $default->analyze_query('繁體搜索', ['lang' => 'zh-Hant']), 'default Chinese script handling should not pretend broad conversion');

    $mapped = new WP_FTS_Analyzer([
        'chinese_script_map' => [
            'zh-Hant' => ['體' => '体'],
        ],
    ]);
    assert_same(['繁体', '体搜', '搜索'], $mapped->analyze_query('繁體搜索', ['lang' => 'zh-Hant']), 'explicit Chinese script map should normalize configured characters');

    $hooked = new WP_FTS_Analyzer([
        'token_normalizer' => static fn(string $term, string $lang): string => $lang === 'zh-Hant' ? strtr($term, ['體' => '体']) : $term,
    ]);
    assert_same(['繁体', '体搜', '搜索'], $hooked->analyze_query('繁體搜索', ['lang' => 'zh-Hant']), 'token_normalizer should provide a deterministic script-conversion hook');
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

test_case('prefix and phrase search require explicit extension point', function (): void {
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
    assert_same(['pl-PL' => 7], $fake->docLengths[10], 'CLI reindex should write boosted per-language doc length');
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
