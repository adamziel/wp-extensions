<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools/smoke-disposable-wordpress-upgrade.php';

if (!function_exists('test_case')) {
    $GLOBALS['wp_fts_upgrade_smoke_contract_direct_failures'] = 0;

    function test_case(string $name, callable $fn): void
    {
        try {
            $fn();
            fwrite(STDOUT, "[PASS] {$name}\n");
        } catch (Throwable $e) {
            $GLOBALS['wp_fts_upgrade_smoke_contract_direct_failures']++;
            fwrite(STDERR, "[FAIL] {$name}\n{$e->getMessage()}\n");
        }
    }

    register_shutdown_function(static function (): void {
        $failures = (int) ($GLOBALS['wp_fts_upgrade_smoke_contract_direct_failures'] ?? 0);
        if ($failures > 0) {
            fwrite(STDERR, "Upgrade/multisite release evidence contract failures={$failures}\n");
            exit(1);
        }

        fwrite(STDOUT, "OK: upgrade/multisite release evidence contracts passed.\n");
    });
}

function wp_fts_upgrade_contract_true(bool $condition, string $message): void
{
    if (function_exists('assert_true')) {
        assert_true($condition, $message);
        return;
    }

    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wp_fts_upgrade_contract_same(mixed $expected, mixed $actual, string $message): void
{
    if (function_exists('assert_same')) {
        assert_same($expected, $actual, $message);
        return;
    }

    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function wp_fts_upgrade_contract_contains(string $needle, string $haystack, string $message): void
{
    if (function_exists('assert_contains')) {
        assert_contains($needle, $haystack, $message);
        return;
    }

    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . "\nMissing: " . var_export($needle, true));
    }
}

function wp_fts_upgrade_contract_not_contains(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message . "\nUnexpected: " . var_export($needle, true));
    }
}

function wp_fts_upgrade_contract_pending(string $message): void
{
    if (function_exists('mark_pending')) {
        mark_pending($message);
    }

    throw new RuntimeException($message);
}

function wp_fts_upgrade_contract_temp_dir(): string
{
    $dir = sys_get_temp_dir() . '/wp_fts_upgrade_smoke_' . getmypid() . '_' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("Could not create temporary upgrade smoke fixture directory: {$dir}");
    }

    return $dir;
}

function wp_fts_upgrade_contract_write_file(string $path, string $contents = "fixture\n"): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException("Could not create fixture directory: {$directory}");
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException("Could not write fixture file: {$path}");
    }
}

function wp_fts_upgrade_contract_remove_tree(string $directory): void
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

function wp_fts_upgrade_contract_wp_root(string $tmp, bool $withMarker = false): string
{
    $root = $tmp . '/wordpress';
    wp_fts_upgrade_contract_write_file($root . '/wp-load.php', "<?php\n");
    if ($withMarker) {
        wp_fts_upgrade_contract_write_file($root . '/' . WP_FTS_DisposableUpgradeSmokeRunner::MARKER_FILE, "disposable\n");
    }

    return $root;
}

/**
 * @param array<string,string> $env
 * @param array<int,string> $args
 * @return array{exit:int,stdout:string,stderr:string}
 */
function wp_fts_upgrade_contract_run_script(array $env, array $args = []): array
{
    if (!function_exists('proc_open')) {
        wp_fts_upgrade_contract_pending('proc_open() is unavailable, so the disposable upgrade smoke CLI skip contract cannot launch a subprocess.');
    }

    $baseEnv = getenv();
    if (!is_array($baseEnv)) {
        $baseEnv = [];
    }

    $script = dirname(__DIR__, 2) . '/tools/smoke-disposable-wordpress-upgrade.php';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(array_merge([PHP_BINARY, $script], $args), $descriptors, $pipes, dirname(__DIR__, 2), array_merge($baseEnv, $env));
    if (!is_resource($process)) {
        wp_fts_upgrade_contract_pending('Could not start the disposable upgrade smoke runner subprocess.');
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

function wp_fts_upgrade_contract_json(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Could not encode upgrade smoke fixture JSON.');
    }

    return $json;
}

/**
 * @param array<int,array<int,string>> $commands
 */
function wp_fts_upgrade_contract_find_command(array $commands, string $firstArg, string $secondArg = ''): ?array
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
function wp_fts_upgrade_contract_status_payload(int $pending = 0): array
{
    return [
        'schema_status' => 'current',
        'schema_version' => 1,
        'expected_schema_version' => 1,
        'pending_queue_count' => $pending,
    ];
}

/**
 * @return array<string,mixed>
 */
function wp_fts_upgrade_contract_inspection_payload(string $phase): array
{
    $tables = [];
    $counts = [];
    foreach ([
        'fts_terms',
        'fts_postings',
        'fts_docs',
        'fts_doc_lengths',
        'fts_docmeta',
        'fts_meta',
    ] as $suffix) {
        $tables[$suffix] = [
            'name' => 'wp_' . $suffix,
            'exists' => $phase !== 'before_previous_activation',
        ];
        $counts[$suffix] = in_array($phase, ['before_current_upgrade', 'after_current_upgrade', 'after_repeated_repair'], true) ? 1 : 0;
    }

    $tracked = [
        101 => [
            'exists' => true,
            'title' => 'WP FTS upgrade guard content fixture',
            'status' => 'publish',
            'content_hash' => 'guard-content-hash',
        ],
        202 => [
            'exists' => true,
            'title' => 'WP FTS upgrade indexed fixture',
            'status' => 'publish',
            'content_hash' => 'indexed-content-hash',
        ],
    ];

    return [
        'phase' => $phase,
        'is_multisite' => false,
        'table_prefix' => 'wp_',
        'fts_tables' => $tables,
        'fts_row_counts' => $counts,
        'options' => [
            'wp_fts_schema_version' => ['exists' => $phase !== 'before_previous_activation', 'schema_version' => 1],
            'wp_fts_pending_index_post_ids' => ['exists' => true, 'queue_count' => $phase === 'after_repeated_repair' ? 1 : 0],
        ],
        'cron' => [
            'hook' => 'wp_fts_process_index_queue',
            'scheduled' => $phase !== 'before_previous_activation',
            'next_run_at' => '2026-06-22T12:00:00Z',
        ],
        'content' => [
            'post_page_count' => in_array($phase, ['before_current_upgrade', 'after_current_upgrade', 'after_repeated_repair'], true) ? 2 : 1,
        ],
        'tracked_posts' => $tracked,
    ];
}

test_case('quality disposable upgrade smoke skips clearly without environment', function (): void {
    $result = wp_fts_upgrade_contract_run_script([
        WP_FTS_DisposableUpgradeSmokeRunner::WP_PATH_ENV => '',
        WP_FTS_DisposableUpgradeSmokeRunner::ALLOW_ENV => '',
        WP_FTS_DisposableUpgradeSmokeRunner::CONFIRM_PATH_ENV => '',
        WP_FTS_DisposableUpgradeSmokeRunner::WP_CLI_ENV => 'wp-fts-contract-missing-wp-cli',
        WP_FTS_DisposableUpgradeSmokeRunner::PREVIOUS_ZIP_ENV => '',
        WP_FTS_DisposableUpgradeSmokeRunner::CURRENT_ZIP_ENV => '',
    ]);
    $output = $result['stdout'] . $result['stderr'];

    wp_fts_upgrade_contract_same(0, $result['exit'], 'disposable upgrade smoke should exit zero for default skip');
    wp_fts_upgrade_contract_contains('SKIP:', $output, 'default upgrade smoke skip should be explicit');
    wp_fts_upgrade_contract_contains('WP_FTS_WP_PATH', $output, 'default upgrade smoke skip should name WP_FTS_WP_PATH');
});

test_case('quality disposable upgrade smoke writes structured report file for skipped preconditions', function (): void {
    $tmp = wp_fts_upgrade_contract_temp_dir();
    try {
        $reportFile = $tmp . '/upgrade-report.json';
        $result = wp_fts_upgrade_contract_run_script(
            [
                WP_FTS_DisposableUpgradeSmokeRunner::WP_PATH_ENV => '',
                WP_FTS_DisposableUpgradeSmokeRunner::ALLOW_ENV => '',
                WP_FTS_DisposableUpgradeSmokeRunner::CONFIRM_PATH_ENV => '',
                WP_FTS_DisposableUpgradeSmokeRunner::WP_CLI_ENV => 'wp-fts-contract-missing-wp-cli',
            ],
            ['--report-file=' . $reportFile]
        );
        $decoded = json_decode((string) file_get_contents($reportFile), true);

        wp_fts_upgrade_contract_same(0, $result['exit'], 'skipped upgrade runner should keep skip-first exit zero');
        wp_fts_upgrade_contract_contains('SKIP:', $result['stdout'] . $result['stderr'], 'skipped upgrade runner should still emit a human-readable SKIP line');
        wp_fts_upgrade_contract_true(is_array($decoded), 'upgrade report file should contain JSON');
        wp_fts_upgrade_contract_same('wp-fts-disposable-upgrade-smoke-v1', $decoded['schema'] ?? null, 'upgrade report file should use the expected schema');
        wp_fts_upgrade_contract_same('skipped', $decoded['status'] ?? null, 'upgrade report file should record skipped status');
        wp_fts_upgrade_contract_same('not_run', $decoded['multisite_evidence']['status'] ?? null, 'upgrade report should record explicit multisite boundary');
    } finally {
        wp_fts_upgrade_contract_remove_tree($tmp);
    }
});

test_case('quality disposable upgrade smoke requires write opt-in marker and package inputs before process launch', function (): void {
    $tmp = wp_fts_upgrade_contract_temp_dir();
    $commands = [];
    try {
        $wpRoot = wp_fts_upgrade_contract_wp_root($tmp);
        $previousZip = $tmp . '/previous.zip';
        $currentZip = $tmp . '/current.zip';
        wp_fts_upgrade_contract_write_file($previousZip, 'previous');
        wp_fts_upgrade_contract_write_file($currentZip, 'current');

        $missingOptIn = new WP_FTS_DisposableUpgradeSmokeRunner(
            function (array $command) use (&$commands): array {
                $commands[] = $command;
                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            },
            [
                WP_FTS_DisposableUpgradeSmokeRunner::WP_PATH_ENV => $wpRoot,
                WP_FTS_DisposableUpgradeSmokeRunner::CONFIRM_PATH_ENV => $wpRoot,
                WP_FTS_DisposableUpgradeSmokeRunner::PREVIOUS_ZIP_ENV => $previousZip,
                WP_FTS_DisposableUpgradeSmokeRunner::CURRENT_ZIP_ENV => $currentZip,
            ]
        );
        $result = $missingOptIn->run();
        wp_fts_upgrade_contract_same('skipped', $result['status'], 'missing write opt-in should skip');
        wp_fts_upgrade_contract_contains('WP_FTS_UPGRADE_SMOKE_ALLOW=1', $result['message'], 'missing opt-in skip should name the write guard');
        wp_fts_upgrade_contract_same([], $commands, 'missing opt-in should stop before WP-CLI');

        $missingMarker = new WP_FTS_DisposableUpgradeSmokeRunner(
            function (array $command) use (&$commands): array {
                $commands[] = $command;
                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            },
            [
                WP_FTS_DisposableUpgradeSmokeRunner::WP_PATH_ENV => $wpRoot,
                WP_FTS_DisposableUpgradeSmokeRunner::ALLOW_ENV => '1',
                WP_FTS_DisposableUpgradeSmokeRunner::PREVIOUS_ZIP_ENV => $previousZip,
                WP_FTS_DisposableUpgradeSmokeRunner::CURRENT_ZIP_ENV => $currentZip,
            ]
        );
        $result = $missingMarker->run();
        wp_fts_upgrade_contract_same('skipped', $result['status'], 'missing marker should skip');
        wp_fts_upgrade_contract_contains(WP_FTS_DisposableUpgradeSmokeRunner::MARKER_FILE, $result['message'], 'missing marker skip should name the marker file');
        wp_fts_upgrade_contract_same([], $commands, 'missing marker should stop before WP-CLI');

        wp_fts_upgrade_contract_write_file($wpRoot . '/' . WP_FTS_DisposableUpgradeSmokeRunner::MARKER_FILE, 'disposable');
        $missingPrevious = new WP_FTS_DisposableUpgradeSmokeRunner(
            function (array $command) use (&$commands): array {
                $commands[] = $command;
                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            },
            [
                WP_FTS_DisposableUpgradeSmokeRunner::WP_PATH_ENV => $wpRoot,
                WP_FTS_DisposableUpgradeSmokeRunner::ALLOW_ENV => '1',
                WP_FTS_DisposableUpgradeSmokeRunner::CURRENT_ZIP_ENV => $currentZip,
            ]
        );
        $result = $missingPrevious->run();
        wp_fts_upgrade_contract_same('skipped', $result['status'], 'missing previous ZIP should skip');
        wp_fts_upgrade_contract_contains('Previous direct-install ZIP is unavailable', $result['message'], 'missing previous ZIP skip should be explicit');
        wp_fts_upgrade_contract_same([], $commands, 'missing previous ZIP should stop before WP-CLI');
    } finally {
        wp_fts_upgrade_contract_remove_tree($tmp);
    }
});

test_case('quality disposable upgrade smoke builds bounded upgrade WP-CLI command sequence', function (): void {
    $tmp = wp_fts_upgrade_contract_temp_dir();
    $commands = [];
    $createdPostIds = [101, 202, 303];
    try {
        $wpRoot = wp_fts_upgrade_contract_wp_root($tmp, true);
        $previousZip = $tmp . '/previous.zip';
        $currentZip = $tmp . '/current.zip';
        wp_fts_upgrade_contract_write_file($previousZip, 'previous');
        wp_fts_upgrade_contract_write_file($currentZip, 'current');

        $runner = new WP_FTS_DisposableUpgradeSmokeRunner(
            function (array $command) use (&$commands, &$createdPostIds, $previousZip, $currentZip): array {
                $commands[] = $command;
                $joined = implode("\n", $command);
                if (str_contains($joined, "\ncore\nis-installed")) {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }
                if (str_contains($joined, "\npost\ncreate")) {
                    $postId = array_shift($createdPostIds);
                    return ['exit' => 0, 'stdout' => (string) $postId . "\n", 'stderr' => ''];
                }
                if (str_contains($joined, "\nplugin\ninstall\n{$previousZip}")) {
                    return ['exit' => 0, 'stdout' => 'Previous plugin installed.', 'stderr' => ''];
                }
                if (str_contains($joined, "\nplugin\ninstall\n{$currentZip}")) {
                    return ['exit' => 0, 'stdout' => 'Current plugin installed.', 'stderr' => ''];
                }
                if (str_contains($joined, "\nfts\nstatus")) {
                    return ['exit' => 0, 'stdout' => wp_fts_upgrade_contract_json(wp_fts_upgrade_contract_status_payload(str_contains($joined, 'before upgraded queue') ? 1 : 0)), 'stderr' => ''];
                }
                if (str_contains($joined, "\nfts\nrepair")) {
                    return ['exit' => 0, 'stdout' => wp_fts_upgrade_contract_json(wp_fts_upgrade_contract_status_payload()), 'stderr' => ''];
                }
                if (str_contains($joined, "\nfts\nprocess_batch")) {
                    return ['exit' => 0, 'stdout' => wp_fts_upgrade_contract_json([
                        'processed' => 1,
                        'queue_processed' => 1,
                        'pending_queue_count' => 0,
                    ]), 'stderr' => ''];
                }
                if (str_contains($joined, "\nfts\nsearch")) {
                    return ['exit' => 0, 'stdout' => wp_fts_upgrade_contract_json([
                        'total' => 1,
                        'results' => [
                            ['post_id' => 202, 'doc_id' => 202, 'title' => 'WP FTS upgrade indexed fixture'],
                        ],
                    ]), 'stderr' => ''];
                }
                if (str_contains($joined, "\npost\ndelete")) {
                    return ['exit' => 0, 'stdout' => 'Deleted.', 'stderr' => ''];
                }
                if (str_contains($joined, "\nfts\ndelete")) {
                    return ['exit' => 0, 'stdout' => 'Deleted document.', 'stderr' => ''];
                }
                if (str_contains($joined, "\neval\n")) {
                    foreach (['before_previous_activation', 'after_previous_activation', 'before_current_upgrade', 'after_current_upgrade', 'after_repeated_repair'] as $phase) {
                        if (str_contains($joined, "\$phase = '{$phase}'")) {
                            return ['exit' => 0, 'stdout' => wp_fts_upgrade_contract_json(wp_fts_upgrade_contract_inspection_payload($phase)), 'stderr' => ''];
                        }
                    }
                }

                return ['exit' => 1, 'stdout' => '', 'stderr' => 'Unexpected command: ' . $joined];
            },
            [
                WP_FTS_DisposableUpgradeSmokeRunner::WP_PATH_ENV => $wpRoot,
                WP_FTS_DisposableUpgradeSmokeRunner::ALLOW_ENV => '1',
                WP_FTS_DisposableUpgradeSmokeRunner::WP_CLI_ENV => 'custom-wp',
                WP_FTS_DisposableUpgradeSmokeRunner::PREVIOUS_ZIP_ENV => $previousZip,
                WP_FTS_DisposableUpgradeSmokeRunner::CURRENT_ZIP_ENV => $currentZip,
            ]
        );
        $result = $runner->run();

        wp_fts_upgrade_contract_same('passed', $result['status'], 'fake disposable upgrade command sequence should pass');
        wp_fts_upgrade_contract_true($commands !== [], 'fake WP-CLI should record commands');
        foreach ($commands as $command) {
            wp_fts_upgrade_contract_same('custom-wp', $command[0], 'WP_FTS_WP_CLI should override the wp binary');
            wp_fts_upgrade_contract_true(in_array('--path=' . $wpRoot, $command, true), 'each WP-CLI command should include --path');
        }
        foreach ([['plugin', 'install'], ['fts', 'status'], ['fts', 'repair'], ['fts', 'search'], ['fts', 'process_batch']] as [$first, $second]) {
            wp_fts_upgrade_contract_true(
                is_array(wp_fts_upgrade_contract_find_command($commands, $first, $second)),
                "upgrade smoke should run wp {$first} {$second}"
            );
        }
        $repairs = 0;
        foreach ($commands as $command) {
            if (str_contains(implode("\n", $command), "\nfts\nrepair")) {
                $repairs++;
                wp_fts_upgrade_contract_true(in_array('--format=json', $command, true), 'wp fts repair should request JSON evidence');
            }
        }
        wp_fts_upgrade_contract_same(2, $repairs, 'upgrade smoke should prove repair idempotence with two repairs');

        $encodedReport = wp_fts_upgrade_contract_json($result['report']);
        foreach ([
            'current_package_upgrade_from_previous_package',
            'repair_idempotence_after_upgrade',
            'search_continuity_for_fixture_content',
            'queue_health_after_upgrade',
            'activation_upgrade_repair_content_mutation_bounded_to_fixtures',
            'public_submission_artifacts_created',
            'not_run',
        ] as $needle) {
            wp_fts_upgrade_contract_contains($needle, $encodedReport, "upgrade report should contain {$needle}");
        }
    } finally {
        wp_fts_upgrade_contract_remove_tree($tmp);
    }
});

test_case('quality Docker disposable upgrade/multisite wrapper is guarded and disposable-only', function (): void {
    $root = dirname(__DIR__, 2);
    $script = (string) file_get_contents($root . '/tools/run-disposable-upgrade-multisite-smoke.sh');

    foreach ([
        'set -euo pipefail',
        'docker compose version',
        'docker info',
        'SKIP:',
        '--previous-package=',
        'mktemp -d /tmp/wp-fts-upgrade-multisite-smoke.',
        'trap cleanup EXIT INT TERM',
        'docker compose -f "${COMPOSE_FILE}" down -v',
        'rm -rf "${PROOF_ROOT}"',
        'cp "${PREVIOUS_PACKAGE}" "${PROOF_ROOT}/release/previous-wp-fts-indexer.zip"',
        'current-wp-fts-indexer.zip',
        'MARIADB_DATABASE: wpfts_upgrade_smoke',
        'MARIADB_USER: wpfts_upgrade_smoke',
        'MARIADB_PASSWORD: wpfts_upgrade_smoke_dev_only',
        'MARIADB_ROOT_PASSWORD: wpfts_upgrade_smoke_root_dev_only',
        'WORDPRESS_DB_PASSWORD: wpfts_upgrade_smoke_dev_only',
        '${PROOF_ROOT}/plugin:/smoke-src:ro',
        '${PROOF_ROOT}/release:/release:ro',
        '${PROOF_ROOT}/reports:/smoke-reports',
        'composer install --working-dir="${PROOF_ROOT}/plugin" --no-interaction --no-dev --optimize-autoloader',
        'touch /var/www/html/.wp-fts-upgrade-smoke',
        'upgrade-report.json',
        'upgrade-output.txt',
        'wp-fts-disposable-upgrade-multisite-wrapper-proof-v1',
        'inner_report_status',
        'upgrade_evidence_status',
        'multisite_evidence_status',
        'WP_FTS_UPGRADE_SMOKE_ALLOW=1',
        'WP_FTS_PREVIOUS_RELEASE_ZIP=/release/previous-wp-fts-indexer.zip',
        'WP_FTS_CURRENT_RELEASE_ZIP=/release/current-wp-fts-indexer.zip',
        'smoke-disposable-wordpress-upgrade.php',
        'Multisite runtime sub-scenario not run',
        'PASS: Docker disposable upgrade/multisite smoke completed.',
    ] as $needle) {
        wp_fts_upgrade_contract_contains($needle, $script, "Docker upgrade wrapper should contain {$needle}");
    }

    foreach ([
        '--env-file',
        'source .env',
        'AWS_',
        'MYSQL_PASSWORD=${',
        'WORDPRESS_DB_PASSWORD=${',
        'MARIADB_PASSWORD=${',
        '~/.ssh',
        '.pem" "$',
        'github/main',
        'git push',
        'git tag',
    ] as $needle) {
        wp_fts_upgrade_contract_not_contains($needle, $script, "Docker upgrade wrapper should not consume host secrets or mutate release refs through {$needle}");
    }
});

test_case('quality upgrade/multisite docs and composer command are operator-facing', function (): void {
    $root = dirname(__DIR__, 2);
    $testingDocs = (string) file_get_contents($root . '/docs/testing.md');
    $operationsDocs = (string) file_get_contents($root . '/docs/operations.md');
    $releaseDocs = (string) file_get_contents($root . '/docs/release-packaging.md');
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);

    wp_fts_upgrade_contract_true(is_array($composer), 'composer.json should decode for upgrade/multisite docs check');
    wp_fts_upgrade_contract_same(
        'tools/run-disposable-upgrade-multisite-smoke.sh',
        $composer['scripts']['test:smoke:upgrade-multisite:docker'] ?? null,
        'composer should expose the Docker upgrade/multisite smoke wrapper'
    );

    foreach ([
        'Docker Disposable Upgrade/Multisite Smoke',
        'tools/run-disposable-upgrade-multisite-smoke.sh --previous-package=/path/to/previous-wp-fts-indexer.zip',
        'composer test:smoke:upgrade-multisite:docker -- --previous-package=/path/to/previous-wp-fts-indexer.zip',
        '--run-docker-upgrade-multisite-smoke',
        '--previous-direct-package=/path/to/previous-wp-fts-indexer.zip',
        '--previous-direct-package-ref=PREVIOUS_LOCAL_REF_OR_SHA',
        'A missing previous package or previous local ref is reported as `unavailable`',
        'isolated Composer home/auth',
        'network access disabled',
        'Multisite runtime proof is not claimed by this lane',
    ] as $needle) {
        wp_fts_upgrade_contract_contains($needle, $testingDocs, "testing docs should mention {$needle}");
    }

    foreach ([
        'Upgrade release evidence',
        'previous direct-install package',
        '--previous-direct-package-ref=REF',
        'isolated Composer home/auth',
        'network access disabled',
        'repair idempotence after upgrade',
        'queue health after upgrade',
        'Multisite runtime proof is not claimed',
    ] as $needle) {
        wp_fts_upgrade_contract_contains($needle, $operationsDocs, "operations docs should mention {$needle}");
    }

    foreach ([
        '--run-docker-upgrade-multisite-smoke',
        '--previous-direct-package=/path/to/previous-wp-fts-indexer.zip',
        '--previous-direct-package-ref=PREVIOUS_LOCAL_REF_OR_SHA',
        'direct-install/operator upgrade evidence',
        'Missing or invalid previous packages/refs are `unavailable`',
        'access disabled',
        'public-submission readiness',
    ] as $needle) {
        wp_fts_upgrade_contract_contains($needle, $releaseDocs, "release evidence docs should mention {$needle}");
    }
});
