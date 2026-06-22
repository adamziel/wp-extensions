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
function wp_fts_lifecycle_contract_inspection_payload(string $phase): array
{
    $zeroCounts = [
        'fts_terms' => 0,
        'fts_postings' => 0,
        'fts_docs' => 0,
        'fts_doc_lengths' => 0,
        'fts_docmeta' => 0,
        'fts_meta' => 0,
    ];
    $indexedCounts = [
        'fts_terms' => 3,
        'fts_postings' => 4,
        'fts_docs' => 1,
        'fts_doc_lengths' => 1,
        'fts_docmeta' => 1,
        'fts_meta' => 1,
    ];
    $counts = in_array($phase, ['after_indexing', 'before_deactivation', 'after_deactivation', 'after_uninstall'], true)
        ? $indexedCounts
        : $zeroCounts;
    $optionExists = $phase !== 'after_uninstall';
    $queueCount = in_array($phase, ['before_deactivation', 'after_deactivation'], true) ? 1 : 0;

    $options = [];
    foreach ([
        'wp_fts_schema_version',
        'wp_fts_pending_index_post_ids',
        'wp_fts_sandbox_demo_post_ids',
        'wp_fts_analyzer_options',
        'wp_fts_settings',
        'wp_fts_indexing_lock',
        'wp_fts_index_health',
        'wp_fts_activation_redirect',
    ] as $option) {
        $options[$option] = [
            'exists' => $option === 'wp_fts_pending_index_post_ids' ? ($optionExists || $queueCount > 0) : $optionExists,
            'queue_count' => $option === 'wp_fts_pending_index_post_ids' ? $queueCount : 0,
            'schema_version' => $option === 'wp_fts_schema_version' && $optionExists ? 1 : 0,
        ];
    }

    $tables = [];
    foreach (array_keys($zeroCounts) as $suffix) {
        $tables[$suffix] = [
            'name' => 'wp_' . $suffix,
            'exists' => true,
        ];
    }

    return [
        'phase' => $phase,
        'is_multisite' => false,
        'table_prefix' => 'wp_',
        'fts_tables' => $tables,
        'fts_row_counts' => $counts,
        'options' => $options,
        'cron' => [
            'hook' => 'wp_fts_process_index_queue',
            'scheduled' => in_array($phase, ['after_activation', 'after_repair', 'after_indexing', 'before_deactivation'], true),
            'next_run_at' => in_array($phase, ['after_activation', 'after_repair', 'after_indexing', 'before_deactivation'], true) ? '2026-06-22T12:00:00Z' : '',
        ],
        'plugin' => [
            'basename' => 'indexer/indexer.php',
            'installed' => $phase !== 'after_uninstall',
            'active' => !in_array($phase, ['before_activation', 'after_deactivation', 'after_uninstall'], true),
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

test_case('quality disposable lifecycle smoke builds bounded lifecycle WP-CLI command sequence', function (): void {
    $tmp = wp_fts_lifecycle_contract_temp_dir();
    $commands = [];
    $createdPostIds = [101, 202, 303];
    try {
        $wpRoot = wp_fts_lifecycle_contract_wp_root($tmp, true);
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
                if (str_contains($joined, "\nfts\nstatus")) {
                    return ['exit' => 0, 'stdout' => wp_fts_lifecycle_contract_json([
                        'schema_status' => 'current',
                        'schema_version' => 1,
                        'expected_schema_version' => 1,
                        'pending_queue_count' => 0,
                    ]), 'stderr' => ''];
                }
                if (str_contains($joined, "\nfts\nrepair")) {
                    return ['exit' => 0, 'stdout' => wp_fts_lifecycle_contract_json([
                        'schema_status' => 'current',
                        'schema_version' => 1,
                        'expected_schema_version' => 1,
                    ]), 'stderr' => ''];
                }
                if (str_contains($joined, "\nfts\nprocess_batch")) {
                    return ['exit' => 0, 'stdout' => wp_fts_lifecycle_contract_json([
                        'processed' => 1,
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
                if (str_contains($joined, "\npost\ndelete")) {
                    return ['exit' => 0, 'stdout' => 'Deleted.', 'stderr' => ''];
                }
                if (str_contains($joined, "\neval\n")) {
                    if (str_contains($joined, 'dropped_table_suffix')) {
                        return ['exit' => 0, 'stdout' => wp_fts_lifecycle_contract_json([
                            'dropped_table_suffix' => 'fts_meta',
                            'table_exists_after_drop' => false,
                        ]), 'stderr' => ''];
                    }
                    foreach (['before_activation', 'after_activation', 'after_repair', 'after_indexing', 'before_deactivation', 'after_deactivation', 'after_uninstall'] as $phase) {
                        if (str_contains($joined, "\$phase = '{$phase}'")) {
                            return ['exit' => 0, 'stdout' => wp_fts_lifecycle_contract_json(wp_fts_lifecycle_contract_inspection_payload($phase)), 'stderr' => ''];
                        }
                    }
                }

                return ['exit' => 1, 'stdout' => '', 'stderr' => 'Unexpected command: ' . $joined];
            },
            [
                WP_FTS_DisposableLifecycleSmokeRunner::WP_PATH_ENV => $wpRoot,
                WP_FTS_DisposableLifecycleSmokeRunner::ALLOW_ENV => '1',
                WP_FTS_DisposableLifecycleSmokeRunner::WP_CLI_ENV => 'custom-wp',
            ]
        );
        $result = $runner->run();

        wp_fts_lifecycle_contract_same('passed', $result['status'], 'fake disposable lifecycle command sequence should pass');
        wp_fts_lifecycle_contract_true($commands !== [], 'fake WP-CLI should record commands');
        foreach ($commands as $command) {
            wp_fts_lifecycle_contract_same('custom-wp', $command[0], 'WP_FTS_WP_CLI should override the wp binary');
            wp_fts_lifecycle_contract_true(in_array('--path=' . $wpRoot, $command, true), 'each WP-CLI command should include --path');
        }

        foreach ([['plugin', 'activate'], ['fts', 'status'], ['fts', 'repair'], ['fts', 'process_batch'], ['plugin', 'deactivate'], ['plugin', 'uninstall']] as [$first, $second]) {
            wp_fts_lifecycle_contract_true(
                is_array(wp_fts_lifecycle_contract_find_command($commands, $first, $second)),
                "lifecycle smoke should run wp {$first} {$second}"
            );
        }
        foreach (['status', 'repair', 'process_batch'] as $subcommand) {
            $command = wp_fts_lifecycle_contract_find_command($commands, 'fts', $subcommand);
            wp_fts_lifecycle_contract_true(is_array($command), "lifecycle smoke should run wp fts {$subcommand}");
            wp_fts_lifecycle_contract_true(in_array('--format=json', $command, true), "wp fts {$subcommand} should request JSON evidence");
        }
        $batch = wp_fts_lifecycle_contract_find_command($commands, 'fts', 'process_batch');
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
        wp_fts_lifecycle_contract_contains('uninstall_clears_operational_options_and_queue_state', $encodedReport, 'report should record uninstall option cleanup evidence');
        wp_fts_lifecycle_contract_contains('public_submission_artifacts_created', $encodedReport, 'report should record no public-submission artifact creation');
        wp_fts_lifecycle_contract_contains('not_run', $encodedReport, 'report should include explicit multisite not-run boundary');
        wp_fts_lifecycle_contract_contains('Multisite lifecycle proof', $encodedReport, 'multisite boundary should explain the missing proof lane');
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
        'MARIADB_DATABASE: wpfts_lifecycle_smoke',
        'MARIADB_USER: wpfts_lifecycle_smoke',
        'MARIADB_PASSWORD: wpfts_lifecycle_smoke_dev_only',
        'MARIADB_ROOT_PASSWORD: wpfts_lifecycle_smoke_root_dev_only',
        'WORDPRESS_DB_PASSWORD: wpfts_lifecycle_smoke_dev_only',
        '${PROOF_ROOT}/plugin:/smoke-src:ro',
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
        '--report-file="${LIFECYCLE_REPORT_CONTAINER_FILE}"',
        'Inner lifecycle smoke reported status',
        'WP_FTS_LIFECYCLE_SMOKE_ALLOW=1',
        'smoke-disposable-wordpress-lifecycle.php',
        'Multisite lifecycle sub-scenario not run',
        'PASS: Docker disposable lifecycle smoke completed.',
    ] as $needle) {
        wp_fts_lifecycle_contract_contains($needle, $script, "Docker lifecycle wrapper should contain {$needle}");
    }

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
        'uninstall clears plugin-owned operational options',
        'retains the `fts_*` tables',
        'not public-submission readiness',
        'Multisite lifecycle proof is explicitly not run',
    ] as $needle) {
        wp_fts_lifecycle_contract_contains($needle, $testingDocs, "testing docs should mention {$needle}");
    }

    foreach ([
        'Disposable lifecycle evidence',
        'deactivation clears scheduled queue processing while retaining `fts_*` data',
        'uninstall clears plugin-owned operational options and pending queue state while retaining `fts_*` tables/data',
        'Multisite lifecycle proof is explicitly not run',
    ] as $needle) {
        wp_fts_lifecycle_contract_contains($needle, $operationsDocs, "operations docs should mention {$needle}");
    }

    foreach ([
        '--run-docker-lifecycle-smokes',
        'direct-install/operator lifecycle evidence',
        'does not build a public-submission artifact',
        'Multisite lifecycle proof is explicitly not run',
    ] as $needle) {
        wp_fts_lifecycle_contract_contains($needle, $releaseDocs, "release evidence docs should mention {$needle}");
    }
});
