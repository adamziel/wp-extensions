<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools/smoke-disposable-wordpress-lifecycle.php';

if (!function_exists('test_case')) {
    $GLOBALS['wp_fts_lifecycle_smoke_contract_direct_failures'] = 0;

    function test_case(string $name, callable $fn): void
    {
        try {
            $fn();
            fwrite(STDOUT, "[PASS] {$name}\n");
        } catch (Throwable $e) {
            $GLOBALS['wp_fts_lifecycle_smoke_contract_direct_failures']++;
            fwrite(STDERR, "[FAIL] {$name}\n{$e->getMessage()}\n");
        }
    }

    register_shutdown_function(static function (): void {
        $failures = (int) ($GLOBALS['wp_fts_lifecycle_smoke_contract_direct_failures'] ?? 0);
        if ($failures > 0) {
            fwrite(STDERR, "Disposable lifecycle smoke contract failures={$failures}\n");
            exit(1);
        }

        fwrite(STDOUT, "OK: disposable lifecycle smoke contracts passed.\n");
    });
}

function wp_fts_lifecycle_contract_contains(string $needle, string $haystack, string $message): void
{
    if (function_exists('assert_contains')) {
        assert_contains($needle, $haystack, $message);
        return;
    }

    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . "\nMissing: " . var_export($needle, true));
    }
}

function wp_fts_lifecycle_contract_not_contains(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message . "\nUnexpected: " . var_export($needle, true));
    }
}

function wp_fts_lifecycle_contract_true(bool $condition, string $message): void
{
    if (function_exists('assert_true')) {
        assert_true($condition, $message);
        return;
    }

    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wp_fts_lifecycle_contract_same(mixed $expected, mixed $actual, string $message): void
{
    if (function_exists('assert_same')) {
        assert_same($expected, $actual, $message);
        return;
    }

    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function wp_fts_lifecycle_contract_pending(string $message): void
{
    if (function_exists('mark_pending')) {
        mark_pending($message);
    }

    throw new RuntimeException($message);
}

function wp_fts_lifecycle_contract_temp_dir(): string
{
    $dir = sys_get_temp_dir() . '/wp_fts_lifecycle_smoke_' . getmypid() . '_' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("Could not create temporary lifecycle smoke fixture directory: {$dir}");
    }

    return $dir;
}

function wp_fts_lifecycle_contract_write_file(string $path, string $contents = "fixture\n"): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException("Could not create fixture directory: {$directory}");
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException("Could not write fixture file: {$path}");
    }
}

function wp_fts_lifecycle_contract_remove_tree(string $directory): void
{
    if (function_exists('remove_directory_tree')) {
        remove_directory_tree($directory);
        return;
    }

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

function wp_fts_lifecycle_contract_wp_root(string $tmp, bool $withMarker = false): string
{
    $root = $tmp . '/wordpress';
    wp_fts_lifecycle_contract_write_file($root . '/wp-load.php', "<?php\n");
    if ($withMarker) {
        wp_fts_lifecycle_contract_write_file($root . '/' . WP_FTS_DisposableLifecycleSmokeRunner::MARKER_FILE, "disposable\n");
    }

    return $root;
}

/**
 * @param array<string,string> $env
 * @param array<int,string> $args
 * @return array{exit:int,stdout:string,stderr:string}
 */
function wp_fts_lifecycle_contract_run_script(array $env, array $args = []): array
{
    if (!function_exists('proc_open')) {
        wp_fts_lifecycle_contract_pending('proc_open() is unavailable, so the disposable lifecycle smoke CLI skip contract cannot launch a subprocess.');
    }

    $baseEnv = getenv();
    if (!is_array($baseEnv)) {
        $baseEnv = [];
    }

    $script = dirname(__DIR__, 2) . '/tools/smoke-disposable-wordpress-lifecycle.php';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(array_merge([PHP_BINARY, $script], $args), $descriptors, $pipes, dirname(__DIR__, 2), array_merge($baseEnv, $env));
    if (!is_resource($process)) {
        wp_fts_lifecycle_contract_pending('Could not start the disposable lifecycle smoke runner subprocess.');
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

function wp_fts_lifecycle_contract_json(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Could not encode lifecycle smoke fixture JSON.');
    }

    return $json;
}

/**
 * @param array<int,array<int,string>> $commands
 */
function wp_fts_lifecycle_contract_find_command(array $commands, string $firstArg, string $secondArg = ''): ?array
{
    foreach ($commands as $command) {
        $joined = implode("\n", $command);
        if (!str_contains($joined, "\n{$firstArg}")) {
            continue;
        }
        if ($secondArg !== '' && !str_contains($joined, "\n{$secondArg}")) {
            continue;
        }

        return $command;
    }

    return null;
}

/**
 * @return array<string,mixed>
 */
function wp_fts_lifecycle_contract_inspection_payload(
    string $phase,
    string $prefix = 'wp_',
    int $blogId = 1
): array
{
    $currentSuffixes = [
        'fts_terms',
        'fts_postings',
        'fts_documents',
        'fts_work',
    ];
    $resetKeys = [
        'reset_new_fts_terms',
        'reset_old_fts_terms',
        'reset_new_fts_postings',
        'reset_old_fts_postings',
        'reset_new_fts_documents',
        'reset_old_fts_documents',
        'reset_new_fts_work',
        'reset_old_fts_work',
    ];
    $counts = array_fill_keys(array_merge($currentSuffixes, $resetKeys), null);
    $uninstalled = in_array($phase, [
        'after_uninstall',
        'multisite_subsite_after_uninstall',
        'after_reinstall_inactive',
        'multisite_subsite_after_reinstall_inactive',
    ], true);
    $currentExists = $phase !== 'before_activation' && !$uninstalled;
    $resetExists = in_array($phase, [
        'before_deactivation',
        'after_deactivation',
        'multisite_subsite_before_deactivation',
        'multisite_subsite_after_deactivation',
    ], true);
    $indexed = in_array($phase, ['after_indexing', 'before_deactivation', 'after_deactivation'], true);
    if ($currentExists) {
        $counts = array_replace($counts, [
            'fts_terms' => $indexed ? 3 : 0,
            'fts_postings' => $indexed ? 4 : 0,
            'fts_documents' => $indexed ? 1 : 0,
            'fts_work' => in_array($phase, ['before_deactivation', 'after_deactivation'], true) ? 1 : 0,
        ]);
    }
    if ($resetExists) {
        foreach ($resetKeys as $key) {
            $counts[$key] = 1;
        }
    }

    $optionExists = $phase !== 'before_activation' && !$uninstalled;

    $options = [];
    foreach ([
        'wp_fts_schema_version',
        'wp_fts_analyzer_options',
        'wp_fts_settings',
        'wp_fts_index_custom_fields',
        'wp_fts_indexing_lock',
        'wp_fts_index_health',
        'wp_fts_readiness_incarnation',
        'wp_fts_search_ready_incarnation',
        'wp_fts_activation_redirect',
        'wp_fts_scope_index_ownership',
    ] as $option) {
        $options[$option] = [
            'exists' => $optionExists,
            'schema_version' => $option === 'wp_fts_schema_version' && $optionExists ? 9 : 0,
        ];
    }
    $fenceExists = in_array($phase, [
        'after_uninstall',
        'multisite_subsite_after_uninstall',
        'after_reinstall_inactive',
        'multisite_subsite_after_reinstall_inactive',
    ], true);
    $options['wp_fts_uninstall_fence'] = [
        'exists' => $fenceExists,
        'value_type' => $fenceExists ? 'string' : 'missing',
        'scalar_value' => $fenceExists ? '1' : null,
        'value_bytes' => $fenceExists ? 1 : null,
        'database_rows' => $fenceExists ? 1 : 0,
        'database_value' => $fenceExists ? '1' : null,
        'database_value_bytes' => $fenceExists ? 1 : null,
        'database_autoload' => $fenceExists ? 'off' : null,
    ];

    $tables = [];
    foreach (array_keys($counts) as $suffix) {
        $exists = in_array($suffix, $currentSuffixes, true) ? $currentExists : $resetExists;
        $tables[$suffix] = [
            'name' => $prefix . $suffix,
            'exists' => $exists,
        ];
    }

    $scheduled = in_array($phase, ['after_activation', 'after_repair', 'after_indexing', 'before_deactivation', 'after_reactivation', 'multisite_subsite_after_reactivation'], true);

    return [
        'phase' => $phase,
        'is_multisite' => true,
        'blog_id' => $blogId,
        'network_site_count' => 2,
        'table_prefix' => $prefix,
        'fts_tables' => $tables,
        'fts_row_counts' => $counts,
        'options' => $options,
        'cron' => [
            'hook' => 'wp_fts_process_index_queue',
            'scheduled' => $scheduled,
            'next_run_at' => $scheduled ? '2026-06-22T12:00:00Z' : '',
        ],
        'plugin' => [
            'basename' => 'indexer/indexer.php',
            'installed' => !in_array($phase, ['after_uninstall', 'multisite_subsite_after_uninstall'], true),
            'active' => !in_array($phase, ['before_activation', 'after_deactivation', 'after_uninstall', 'multisite_subsite_after_deactivation', 'multisite_subsite_after_uninstall', 'after_reinstall_inactive', 'multisite_subsite_after_reinstall_inactive'], true),
        ],
        'content' => [
            'post_page_count' => in_array($phase, ['before_activation', 'after_activation', 'after_repair'], true) ? 2 : 4,
        ],
        'tracked_posts' => [
            101 => [
                'exists' => true,
                'title' => 'WP FTS lifecycle pre-existing content',
                'status' => 'publish',
                'content_hash' => 'pre-existing-content-hash',
            ],
            202 => [
                'exists' => true,
                'title' => 'WP FTS lifecycle indexed fixture',
                'status' => 'publish',
                'content_hash' => 'indexed-fixture-content-hash',
            ],
            303 => [
                'exists' => true,
                'title' => 'WP FTS lifecycle queued fixture',
                'status' => 'publish',
                'content_hash' => 'queued-fixture-content-hash',
            ],
        ],
    ];
}

/** @return array<string,mixed> */
function wp_fts_lifecycle_contract_reset_generation_seed_payload(): array
{
    $tables = [];
    $resetKeys = [
        'reset_new_fts_terms',
        'reset_old_fts_terms',
        'reset_new_fts_postings',
        'reset_old_fts_postings',
        'reset_new_fts_documents',
        'reset_old_fts_documents',
        'reset_new_fts_work',
        'reset_old_fts_work',
    ];
    foreach ($resetKeys as $key) {
        $tables[$key] = ['exists' => true, 'rows' => 1];
    }

    return [
        'seeded_reset_generation_table_keys' => $resetKeys,
        'tables' => $tables,
    ];
}

test_case('quality disposable lifecycle smoke skips clearly without environment', function (): void {
    $result = wp_fts_lifecycle_contract_run_script([
        WP_FTS_DisposableLifecycleSmokeRunner::WP_PATH_ENV => '',
        WP_FTS_DisposableLifecycleSmokeRunner::ALLOW_ENV => '',
        WP_FTS_DisposableLifecycleSmokeRunner::CONFIRM_PATH_ENV => '',
        WP_FTS_DisposableLifecycleSmokeRunner::WP_CLI_ENV => 'wp-fts-contract-missing-wp-cli',
    ]);
    $output = $result['stdout'] . $result['stderr'];

    wp_fts_lifecycle_contract_same(0, $result['exit'], 'disposable lifecycle smoke should exit zero for default skip');
    wp_fts_lifecycle_contract_contains('SKIP:', $output, 'default lifecycle smoke skip should be explicit');
    wp_fts_lifecycle_contract_contains('WP_FTS_WP_PATH', $output, 'default lifecycle smoke skip should name WP_FTS_WP_PATH');
});

test_case('quality disposable lifecycle smoke writes structured report file for skipped preconditions', function (): void {
    $tmp = wp_fts_lifecycle_contract_temp_dir();
    try {
        $reportFile = $tmp . '/lifecycle-report.json';
        $result = wp_fts_lifecycle_contract_run_script(
            [
                WP_FTS_DisposableLifecycleSmokeRunner::WP_PATH_ENV => '',
                WP_FTS_DisposableLifecycleSmokeRunner::ALLOW_ENV => '',
                WP_FTS_DisposableLifecycleSmokeRunner::CONFIRM_PATH_ENV => '',
                WP_FTS_DisposableLifecycleSmokeRunner::WP_CLI_ENV => 'wp-fts-contract-missing-wp-cli',
            ],
            ['--report-file=' . $reportFile]
        );
        $decoded = json_decode((string) file_get_contents($reportFile), true);

        wp_fts_lifecycle_contract_same(0, $result['exit'], 'skipped lifecycle runner should keep skip-first exit zero');
        wp_fts_lifecycle_contract_contains('SKIP:', $result['stdout'] . $result['stderr'], 'skipped lifecycle runner should still emit a human-readable SKIP line');
        wp_fts_lifecycle_contract_true(is_array($decoded), 'lifecycle report file should contain JSON');
        wp_fts_lifecycle_contract_same('wp-fts-disposable-lifecycle-smoke-v1', $decoded['schema'] ?? null, 'lifecycle report file should use the expected schema');
        wp_fts_lifecycle_contract_same('skipped', $decoded['status'] ?? null, 'lifecycle report file should record skipped status');
    } finally {
        wp_fts_lifecycle_contract_remove_tree($tmp);
    }
});

test_case('quality disposable lifecycle smoke skips invalid WordPress path without process launch', function (): void {
    $tmp = wp_fts_lifecycle_contract_temp_dir();
    $commands = [];
    try {
        $runner = new WP_FTS_DisposableLifecycleSmokeRunner(
            function (array $command) use (&$commands): array {
                $commands[] = $command;
                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            },
            [
                WP_FTS_DisposableLifecycleSmokeRunner::WP_PATH_ENV => $tmp . '/not-wordpress',
                WP_FTS_DisposableLifecycleSmokeRunner::ALLOW_ENV => '1',
                WP_FTS_DisposableLifecycleSmokeRunner::CONFIRM_PATH_ENV => $tmp . '/not-wordpress',
            ]
        );
        $result = $runner->run();

        wp_fts_lifecycle_contract_same('skipped', $result['status'], 'invalid WP path should skip');
        wp_fts_lifecycle_contract_contains('WP_FTS_WP_PATH', $result['message'], 'invalid WP path skip should name WP_FTS_WP_PATH');
        wp_fts_lifecycle_contract_same([], $commands, 'invalid WP path should not launch WP-CLI');
    } finally {
        wp_fts_lifecycle_contract_remove_tree($tmp);
    }
});

test_case('quality disposable lifecycle smoke requires write opt-in and marker before process launch', function (): void {
    $tmp = wp_fts_lifecycle_contract_temp_dir();
    $commands = [];
    try {
        $wpRoot = wp_fts_lifecycle_contract_wp_root($tmp);
        $missingOptIn = new WP_FTS_DisposableLifecycleSmokeRunner(
            function (array $command) use (&$commands): array {
                $commands[] = $command;
                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            },
            [
                WP_FTS_DisposableLifecycleSmokeRunner::WP_PATH_ENV => $wpRoot,
                WP_FTS_DisposableLifecycleSmokeRunner::CONFIRM_PATH_ENV => $wpRoot,
                WP_FTS_DisposableLifecycleSmokeRunner::WP_CLI_ENV => 'custom-wp',
            ]
        );
        $result = $missingOptIn->run();
        wp_fts_lifecycle_contract_same('skipped', $result['status'], 'missing write opt-in should skip');
        wp_fts_lifecycle_contract_contains('WP_FTS_LIFECYCLE_SMOKE_ALLOW=1', $result['message'], 'missing opt-in skip should name the write guard');
        wp_fts_lifecycle_contract_same([], $commands, 'missing opt-in should stop before WP-CLI');

        $missingMarker = new WP_FTS_DisposableLifecycleSmokeRunner(
            function (array $command) use (&$commands): array {
                $commands[] = $command;
                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            },
            [
                WP_FTS_DisposableLifecycleSmokeRunner::WP_PATH_ENV => $wpRoot,
                WP_FTS_DisposableLifecycleSmokeRunner::ALLOW_ENV => '1',
                WP_FTS_DisposableLifecycleSmokeRunner::WP_CLI_ENV => 'custom-wp',
            ]
        );
        $result = $missingMarker->run();
        wp_fts_lifecycle_contract_same('skipped', $result['status'], 'missing marker should skip');
        wp_fts_lifecycle_contract_contains(WP_FTS_DisposableLifecycleSmokeRunner::MARKER_FILE, $result['message'], 'missing marker skip should name the marker file');
        wp_fts_lifecycle_contract_same([], $commands, 'missing marker should stop before WP-CLI');
    } finally {
        wp_fts_lifecycle_contract_remove_tree($tmp);
    }
});

test_case('quality disposable lifecycle smoke binds uninstall to current and reset-generation tables', function (): void {
    $expected = [
        'fts_terms',
        'fts_postings',
        'fts_documents',
        'fts_work',
    ];
    $reflection = new ReflectionClass(WP_FTS_DisposableLifecycleSmokeRunner::class);
    wp_fts_lifecycle_contract_same(
        $expected,
        $reflection->getConstant('CURRENT_FTS_TABLE_SUFFIXES'),
        'lifecycle uninstall proof should cover exactly the four current table suffixes'
    );
    $resetTables = $reflection->getConstant('RESET_GENERATION_TABLES');
    wp_fts_lifecycle_contract_same(
        [
            'reset_new_fts_terms',
            'reset_old_fts_terms',
            'reset_new_fts_postings',
            'reset_old_fts_postings',
            'reset_new_fts_documents',
            'reset_old_fts_documents',
            'reset_new_fts_work',
            'reset_old_fts_work',
        ],
        is_array($resetTables) ? array_keys($resetTables) : [],
        'lifecycle uninstall proof should independently inspect all eight deterministic reset generations'
    );

    $inspection = wp_fts_lifecycle_contract_inspection_payload('after_uninstall');
    $inspection['fts_tables']['reset_old_fts_postings']['exists'] = true;
    $method = $reflection->getMethod('assert_all_uninstall_tables_absent');
    $rejected = false;
    try {
        $method->invoke(new WP_FTS_DisposableLifecycleSmokeRunner(), $inspection, 'fixture uninstall');
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'reset_old_fts_postings');
    }
    wp_fts_lifecycle_contract_true($rejected, 'one surviving reset posting generation must fail lifecycle uninstall proof');
});

test_case('quality disposable lifecycle smoke requires the exact bounded uninstall fence shape', function (): void {
    $reflection = new ReflectionClass(WP_FTS_DisposableLifecycleSmokeRunner::class);
    $runner = new WP_FTS_DisposableLifecycleSmokeRunner();
    $present = $reflection->getMethod('assert_uninstall_fence_present');
    $absent = $reflection->getMethod('assert_uninstall_fence_absent');
    $valid = wp_fts_lifecycle_contract_inspection_payload('after_uninstall');
    $present->invoke($runner, $valid, 'valid fixture fence');

    foreach ([
        'value_type' => 'integer',
        'scalar_value' => 'content-bearing-fence',
        'value_bytes' => 1024,
        'database_rows' => 2,
        'database_value' => '0',
        'database_value_bytes' => 2,
        'database_autoload' => 'on',
    ] as $field => $invalidValue) {
        $invalid = $valid;
        $invalid['options']['wp_fts_uninstall_fence'][$field] = $invalidValue;
        $rejected = false;
        try {
            $present->invoke($runner, $invalid, "invalid {$field}");
        } catch (RuntimeException) {
            $rejected = true;
        }
        wp_fts_lifecycle_contract_true($rejected, "uninstall fence proof must reject invalid {$field}");
    }

    $reactivated = wp_fts_lifecycle_contract_inspection_payload('after_reactivation');
    $absent->invoke($runner, $reactivated, 'reactivated fixture fence');
    $reactivated['options']['wp_fts_uninstall_fence']['database_rows'] = 1;
    $rejected = false;
    try {
        $absent->invoke($runner, $reactivated, 'stale reactivation fence');
    } catch (RuntimeException) {
        $rejected = true;
    }
    wp_fts_lifecycle_contract_true($rejected, 'reactivation proof must reject a surviving database fence row');
});

test_case('quality disposable lifecycle smoke builds bounded lifecycle WP-CLI command sequence', function (): void {
    $tmp = wp_fts_lifecycle_contract_temp_dir();
    $commands = [];
    $createdPostIds = [101, 202, 303];
    try {
        $wpRoot = wp_fts_lifecycle_contract_wp_root($tmp, true);
        $reinstallZip = $tmp . '/indexer-lifecycle-reinstall.zip';
        wp_fts_lifecycle_contract_write_file($reinstallZip, 'source-bound lifecycle ZIP fixture');
        $runner = new WP_FTS_DisposableLifecycleSmokeRunner(
            function (array $command) use (&$commands, &$createdPostIds): array {
                $commands[] = $command;
                $joined = implode("\n", $command);
                if (str_contains($joined, "\ncore\nis-installed")) {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }
                if (str_contains($joined, "\nplugin\nstatus\nindexer")) {
                    return ['exit' => 0, 'stdout' => 'Plugin indexer details.', 'stderr' => ''];
                }
                if (str_contains($joined, "\nplugin\nis-active\nindexer")) {
                    return ['exit' => 1, 'stdout' => '', 'stderr' => 'Plugin is inactive.'];
                }
                if (str_contains($joined, "\nplugin\nactivate\nindexer")) {
                    return ['exit' => 0, 'stdout' => 'Plugin activated.', 'stderr' => ''];
                }
                if (str_contains($joined, "\nsite\ncreate")) {
                    return ['exit' => 0, 'stdout' => "2\n", 'stderr' => ''];
                }
                if (str_contains($joined, "\nfts\nstatus")) {
                    return ['exit' => 0, 'stdout' => wp_fts_lifecycle_contract_json([
                        'schema_status' => 'current',
                        'schema_version' => 9,
                        'expected_schema_version' => 9,
                        'pending_queue_count' => 0,
                    ]), 'stderr' => ''];
                }
                if (str_contains($joined, "\nfts\nrepair")) {
                    return ['exit' => 0, 'stdout' => wp_fts_lifecycle_contract_json([
                        'schema_status' => 'current',
                        'schema_version' => 9,
                        'expected_schema_version' => 9,
                    ]), 'stderr' => ''];
                }
                if (str_contains($joined, "\nfts\nprocess-batch")) {
                    return ['exit' => 0, 'stdout' => wp_fts_lifecycle_contract_json([
                        'indexed' => 1,
                        'queue_processed' => 1,
                        'pending_queue_count' => 0,
                    ]), 'stderr' => ''];
                }
                if (str_contains($joined, "\npost\ncreate")) {
                    $postId = array_shift($createdPostIds);
                    return ['exit' => 0, 'stdout' => (string) $postId . "\n", 'stderr' => ''];
                }
                if (str_contains($joined, "\nplugin\ndeactivate\nindexer")) {
                    return ['exit' => 0, 'stdout' => 'Plugin deactivated.', 'stderr' => ''];
                }
                if (str_contains($joined, "\nplugin\nuninstall\nindexer")) {
                    return ['exit' => 0, 'stdout' => 'Uninstalled and deleted indexer.', 'stderr' => ''];
                }
                if (str_contains($joined, "\nplugin\ninstall\n") && str_contains($joined, "\n--force")) {
                    return ['exit' => 0, 'stdout' => 'Installed indexer.', 'stderr' => ''];
                }
                if (str_contains($joined, "\ncron\nevent\nrun\nwp_fts_provision_site_schema")) {
                    return ['exit' => 0, 'stdout' => 'Executed wp_fts_provision_site_schema.', 'stderr' => ''];
                }
                if (str_contains($joined, "\npost\ndelete")) {
                    return ['exit' => 0, 'stdout' => 'Deleted.', 'stderr' => ''];
                }
                if (str_contains($joined, "\nsite\ndelete\n2\n--yes")) {
                    return ['exit' => 0, 'stdout' => 'Success: Site 2 deleted.', 'stderr' => ''];
                }
                if (str_contains($joined, "\neval\n")) {
                    if (str_contains($joined, 'dropped_table_suffix')) {
                        return ['exit' => 0, 'stdout' => wp_fts_lifecycle_contract_json([
                            'dropped_table_suffix' => 'fts_documents',
                            'table_exists_after_drop' => false,
                        ]), 'stderr' => ''];
                    }
                    if (str_contains($joined, 'seeded_reset_generation_table_keys')) {
                        return ['exit' => 0, 'stdout' => wp_fts_lifecycle_contract_json(wp_fts_lifecycle_contract_reset_generation_seed_payload()), 'stderr' => ''];
                    }
                    foreach ([
                        'before_activation',
                        'after_activation',
                        'multisite_subsite_after_creation',
                        'after_repair',
                        'after_indexing',
                        'before_deactivation',
                        'multisite_subsite_before_deactivation',
                        'after_deactivation',
                        'multisite_subsite_after_deactivation',
                        'after_uninstall',
                        'multisite_subsite_after_uninstall',
                        'after_reinstall_inactive',
                        'multisite_subsite_after_reinstall_inactive',
                        'after_reactivation',
                        'multisite_subsite_after_reactivation',
                    ] as $phase) {
                        if (str_contains($joined, "\$phase = '{$phase}'")) {
                            $subsite = str_starts_with($phase, 'multisite_subsite_');
                            return ['exit' => 0, 'stdout' => wp_fts_lifecycle_contract_json(
                                wp_fts_lifecycle_contract_inspection_payload(
                                    $phase,
                                    $subsite ? 'wp_2_' : 'wp_',
                                    $subsite ? 2 : 1
                                )
                            ), 'stderr' => ''];
                        }
                    }
                }

                return ['exit' => 1, 'stdout' => '', 'stderr' => 'Unexpected command: ' . $joined];
            },
            [
                WP_FTS_DisposableLifecycleSmokeRunner::WP_PATH_ENV => $wpRoot,
                WP_FTS_DisposableLifecycleSmokeRunner::ALLOW_ENV => '1',
                WP_FTS_DisposableLifecycleSmokeRunner::WP_CLI_ENV => 'custom-wp',
                WP_FTS_DisposableLifecycleSmokeRunner::WP_URL_ENV => 'http://wordpress',
                WP_FTS_DisposableLifecycleSmokeRunner::NETWORK_ACTIVATE_ENV => '1',
                WP_FTS_DisposableLifecycleSmokeRunner::REINSTALL_ZIP_ENV => $reinstallZip,
            ]
        );
        $result = $runner->run();

        wp_fts_lifecycle_contract_same(
            'passed',
            $result['status'],
            'fake disposable lifecycle command sequence should pass: ' . (string) ($result['message'] ?? '')
        );
        wp_fts_lifecycle_contract_true($commands !== [], 'fake WP-CLI should record commands');
        $canonicalWpRoot = realpath($wpRoot);
        wp_fts_lifecycle_contract_true(is_string($canonicalWpRoot), 'disposable WordPress root should resolve before command assertions');
        foreach ($commands as $command) {
            wp_fts_lifecycle_contract_same('custom-wp', $command[0], 'WP_FTS_WP_CLI should override the wp binary');
            wp_fts_lifecycle_contract_true(in_array('--path=' . $canonicalWpRoot, $command, true), 'each WP-CLI command should include the canonical --path');
        }

        foreach ([['plugin', 'activate'], ['fts', 'status'], ['fts', 'repair'], ['fts', 'process-batch'], ['plugin', 'deactivate'], ['plugin', 'uninstall'], ['plugin', 'install'], ['cron', 'event']] as [$first, $second]) {
            wp_fts_lifecycle_contract_true(
                is_array(wp_fts_lifecycle_contract_find_command($commands, $first, $second)),
                "lifecycle smoke should run wp {$first} {$second}"
            );
        }
        foreach (['status', 'repair', 'process-batch'] as $subcommand) {
            $command = wp_fts_lifecycle_contract_find_command($commands, 'fts', $subcommand);
            wp_fts_lifecycle_contract_true(is_array($command), "lifecycle smoke should run wp fts {$subcommand}");
            wp_fts_lifecycle_contract_true(in_array('--format=json', $command, true), "wp fts {$subcommand} should request JSON evidence");
        }
        $batch = wp_fts_lifecycle_contract_find_command($commands, 'fts', 'process-batch');
        wp_fts_lifecycle_contract_true(is_array($batch), 'lifecycle smoke should run one bounded indexing batch');
        wp_fts_lifecycle_contract_true(in_array('--batch_size=1', $batch, true), 'lifecycle batch should be batch bounded');
        wp_fts_lifecycle_contract_true(in_array('--time_budget=5', $batch, true), 'lifecycle batch should be time bounded');
        wp_fts_lifecycle_contract_true(
            is_array(wp_fts_lifecycle_contract_find_command($commands, 'eval')),
            'lifecycle smoke should inspect database/options through WP-CLI eval in the disposable site'
        );

        $encodedReport = wp_fts_lifecycle_contract_json($result['report']);
        wp_fts_lifecycle_contract_contains('activation_and_repair_do_not_index_existing_content', $encodedReport, 'report should record activation and repair no-index evidence');
        wp_fts_lifecycle_contract_contains('deactivation_clears_scheduled_queue_processing', $encodedReport, 'report should record deactivation cron cleanup evidence');
        wp_fts_lifecycle_contract_contains('uninstall_clears_operational_options', $encodedReport, 'report should record uninstall option cleanup evidence');
        wp_fts_lifecycle_contract_contains('uninstall_removes_current_and_reset_generation_fts_tables', $encodedReport, 'report should record current/reset-generation table cleanup');
        wp_fts_lifecycle_contract_contains('uninstall_retains_exact_bounded_lifecycle_fence', $encodedReport, 'report should record the exact retained lifecycle fence');
        wp_fts_lifecycle_contract_contains('explicit_reactivation_clears_fence_and_reprovisions', $encodedReport, 'report should record explicit reactivation fence clearance and schema repair');
        wp_fts_lifecycle_contract_contains('multisite_uninstall_removes_all_site_fts_tables', $encodedReport, 'report should record multisite destructive cleanup evidence');
        wp_fts_lifecycle_contract_contains('multisite_reactivation_clears_all_site_fences_and_reprovisions', $encodedReport, 'report should record multisite reactivation recovery evidence');
        wp_fts_lifecycle_contract_contains('public_submission_artifacts_created', $encodedReport, 'report should record no public-submission artifact creation');
        wp_fts_lifecycle_contract_same('passed', $result['report']['multisite_evidence']['status'] ?? null, 'report should include real multisite runtime proof');
        wp_fts_lifecycle_contract_same(true, $result['report']['multisite_evidence']['all_current_and_reset_generation_tables_removed'] ?? null, 'report should bind multisite proof to current/reset-generation cleanup');
        wp_fts_lifecycle_contract_same(true, $result['report']['multisite_evidence']['bounded_uninstall_fence_retained'] ?? null, 'report should bind multisite proof to the exact retained fence');
        wp_fts_lifecycle_contract_same(true, $result['report']['multisite_evidence']['network_reactivation_cleared_fences_and_reprovisioned'] ?? null, 'report should bind multisite proof to reactivation recovery');
        wp_fts_lifecycle_contract_same(2, $result['report']['multisite_evidence']['site_count'] ?? null, 'report should record both disposable network sites');

        foreach (['activate', 'deactivate'] as $action) {
            $command = wp_fts_lifecycle_contract_find_command($commands, 'plugin', $action);
            wp_fts_lifecycle_contract_true(is_array($command) && in_array('--network', $command, true), "multisite lifecycle {$action} should use the explicit network boundary");
        }
        $networkActivations = array_values(array_filter($commands, static fn(array $command): bool => in_array('activate', $command, true) && in_array('--network', $command, true)));
        wp_fts_lifecycle_contract_same(2, count($networkActivations), 'lifecycle smoke should prove both initial and post-uninstall network activation');
    } finally {
        wp_fts_lifecycle_contract_remove_tree($tmp);
    }
});

test_case('quality Docker disposable lifecycle wrapper is guarded and disposable-only', function (): void {
    $root = dirname(__DIR__, 2);
    $script = (string) file_get_contents($root . '/tools/run-disposable-lifecycle-smoke.sh');

    foreach ([
        'set -euo pipefail',
        'docker compose version',
        'docker info',
        'SKIP:',
        'mktemp -d /tmp/wp-fts-lifecycle-smoke.',
        'trap cleanup EXIT INT TERM',
        'docker compose -f "${COMPOSE_FILE}" down -v',
        'rm -rf "${PROOF_ROOT}"',
        'require_command zip',
        'build_lifecycle_reinstall_zip()',
        'indexer-lifecycle-reinstall.zip',
        'MARIADB_DATABASE: wpfts_lifecycle_smoke',
        'MARIADB_USER: wpfts_lifecycle_smoke',
        'MARIADB_PASSWORD: wpfts_lifecycle_smoke_dev_only',
        'MARIADB_ROOT_PASSWORD: wpfts_lifecycle_smoke_root_dev_only',
        'WORDPRESS_DB_PASSWORD: wpfts_lifecycle_smoke_dev_only',
        '${PROOF_ROOT}/plugin:/smoke-src:ro',
        '${PROOF_ROOT}/reinstall:/smoke-reinstall:ro',
        'run_source_copy_composer_install()',
        'local composer_home="${PROOF_ROOT}/composer-home"',
        'local composer_cache_dir="${PROOF_ROOT}/composer-cache"',
        'local composer_tmp_dir="${PROOF_ROOT}/composer-tmp"',
        'local -a composer_env=(',
        '        -i',
        '"PATH=${composer_path}"',
        '"TMPDIR=${composer_tmp_dir}"',
        '"COMPOSER_HOME=${composer_home}"',
        '"COMPOSER_CACHE_DIR=${composer_cache_dir}"',
        'env "${composer_env[@]}" composer install --working-dir="${PROOF_ROOT}/plugin" --no-interaction --no-dev --optimize-autoloader',
        "run_step \"Installing source-copy Composer production dependencies\" \\\n    run_source_copy_composer_install",
        'cp -R /smoke-src /var/www/html/wp-content/plugins/indexer',
        'touch /var/www/html/.wp-fts-lifecycle-smoke',
        '${PROOF_ROOT}/reports:/smoke-reports',
        'lifecycle-report.json',
        'lifecycle-output.txt',
        'wp-fts-disposable-lifecycle-wrapper-proof-v1',
        'inner_report_status',
        'multisite_evidence_status',
        'uninstall_table_cleanup',
        'uninstall_fence',
        'network_reactivation',
        '--report-file="${LIFECYCLE_REPORT_CONTAINER_FILE}"',
        'Inner lifecycle smoke reported status',
        'Inner lifecycle smoke reported multisite_evidence.status',
        'Inner lifecycle smoke did not prove current/reset-generation FTS table removal',
        'Inner lifecycle smoke did not prove the exact bounded uninstall fence',
        'Inner lifecycle smoke did not prove network reactivation fence clearance and reprovisioning',
        'core multisite-install',
        'WP_FTS_LIFECYCLE_SMOKE_ALLOW=1',
        'WP_FTS_LIFECYCLE_SMOKE_NETWORK_ACTIVATE=1',
        'WP_FTS_LIFECYCLE_SMOKE_REINSTALL_ZIP=/smoke-reinstall/indexer-lifecycle-reinstall.zip',
        'smoke-disposable-wordpress-lifecycle.php',
        'PASS: Docker disposable lifecycle smoke completed.',
    ] as $needle) {
        wp_fts_lifecycle_contract_contains($needle, $script, "Docker lifecycle wrapper should contain {$needle}");
    }

    foreach ([
        "--exclude='./auth.json'",
        "--exclude='*/auth.json'",
        "--exclude='./.composer'",
        "--exclude='./.composer/**'",
        "--exclude='*/.composer'",
        "--exclude='*/.composer/**'",
    ] as $needle) {
        wp_fts_lifecycle_contract_contains($needle, $script, "Docker lifecycle wrapper source-copy tar should exclude {$needle}");
    }

    wp_fts_lifecycle_contract_same(
        2,
        substr_count($script, 'tar "${SOURCE_COPY_TAR_EXCLUDES[@]}" -cf - .'),
        'Docker lifecycle wrapper should apply the auth-excluding tar boundary to both plugin and component source copies'
    );

    wp_fts_lifecycle_contract_same(
        1,
        substr_count($script, 'composer install --working-dir="${PROOF_ROOT}/plugin" --no-interaction --no-dev --optimize-autoloader'),
        'Docker lifecycle wrapper should keep the source-copy Composer install behind the isolated helper only'
    );

    foreach (['COMPOSER_AUTH=', 'GITHUB_TOKEN=', 'GH_TOKEN=', 'GIT_ASKPASS=', 'SSH_AUTH_SOCK=', 'WP_FTS_SECRET_TOKEN='] as $blockedPrefix) {
        wp_fts_lifecycle_contract_not_contains(
            $blockedPrefix,
            $script,
            "Docker lifecycle wrapper should not pass ambient credential-capable {$blockedPrefix} into Composer"
        );
    }

    foreach ([
        '--env-file',
        'source .env',
        'AWS_',
        'MYSQL_PASSWORD=${',
        'WORDPRESS_DB_PASSWORD=${',
        'MARIADB_PASSWORD=${',
        'build-release-zip.php',
        'wp-fts-indexer.zip',
        '--run-docker-disposable-smokes',
    ] as $needle) {
        wp_fts_lifecycle_contract_not_contains($needle, $script, "Docker lifecycle wrapper should not consume host secrets or create release artifacts through {$needle}");
    }
});

test_case('quality disposable lifecycle smoke docs and composer command are operator-facing', function (): void {
    $root = dirname(__DIR__, 2);
    $testingDocs = (string) file_get_contents($root . '/docs/testing.md');
    $operationsDocs = (string) file_get_contents($root . '/docs/operations.md');
    $releaseDocs = (string) file_get_contents($root . '/docs/release-packaging.md');
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);

    wp_fts_lifecycle_contract_true(is_array($composer), 'composer.json should decode for lifecycle smoke docs check');
    wp_fts_lifecycle_contract_same(
        'php tools/smoke-disposable-wordpress-lifecycle.php',
        $composer['scripts']['test:smoke:lifecycle'] ?? null,
        'composer should expose the disposable lifecycle smoke runner'
    );
    wp_fts_lifecycle_contract_same(
        'tools/run-disposable-lifecycle-smoke.sh',
        $composer['scripts']['test:smoke:lifecycle:docker'] ?? null,
        'composer should expose the Docker lifecycle smoke wrapper'
    );

    foreach ([
        'Docker Disposable Lifecycle Smoke',
        'tools/run-disposable-lifecycle-smoke.sh',
        'composer test:smoke:lifecycle:docker',
        'WP_FTS_LIFECYCLE_SMOKE_ALLOW=1',
        'WP_FTS_WP_PATH=/path/to/wordpress',
        'composer test:smoke:lifecycle',
        '--run-docker-lifecycle-smokes',
        'activation and repair do not index pre-existing content',
        'deactivation clears scheduled queue processing',
        'uninstall removes all plugin-owned current/reset-generation FTS tables',
        'seeds all eight deterministic reset-generation',
        'absent from both site prefixes after uninstall',
        '`multisite_evidence.status=passed`',
        '`wp_fts_uninstall_fence` row containing the exact one-byte string `1`',
        'network-reactivates it and runs one bounded site-provisioning page',
        'Both fences',
        'exactly four current tables must return on each site',
        'not public-submission readiness',
    ] as $needle) {
        wp_fts_lifecycle_contract_contains($needle, $testingDocs, "testing docs should mention {$needle}");
    }

    foreach ([
        'The disposable lifecycle report',
        'deactivation clears scheduled queue processing while retaining `fts_*` data',
        'uninstall removes plugin-owned current/reset-generation tables and operational',
        'deterministic reset-generation table names on both sites',
        'twelve owned tables to be absent from both site prefixes after uninstall',
        'exact non-autoloaded one-byte uninstall fence',
        'requires both fences absent plus exactly four current and zero reset-generation tables',
    ] as $needle) {
        wp_fts_lifecycle_contract_contains($needle, $operationsDocs, "operations docs should mention {$needle}");
    }

    foreach ([
        '--run-docker-lifecycle-smokes',
        'direct-install/operator lifecycle evidence',
        'reversible network deactivation',
        'network uninstall. It seeds all deterministic reset-generation table names',
        'The collector requires passed multisite, table-removal, fence, and',
        'reactivates it and requires the bounded provisioning chain',
        'This lane does not build a public-submission artifact',
    ] as $needle) {
        wp_fts_lifecycle_contract_contains($needle, $releaseDocs, "release evidence docs should mention {$needle}");
    }
});
