<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools/smoke-disposable-wordpress-release.php';

if (!function_exists('test_case')) {
    $GLOBALS['wp_fts_disposable_smoke_contract_direct_failures'] = 0;

    function test_case(string $name, callable $fn): void
    {
        try {
            $fn();
            fwrite(STDOUT, "[PASS] {$name}\n");
        } catch (Throwable $e) {
            $GLOBALS['wp_fts_disposable_smoke_contract_direct_failures']++;
            fwrite(STDERR, "[FAIL] {$name}\n{$e->getMessage()}\n");
        }
    }

    register_shutdown_function(static function (): void {
        $failures = (int) ($GLOBALS['wp_fts_disposable_smoke_contract_direct_failures'] ?? 0);
        if ($failures > 0) {
            fwrite(STDERR, "Disposable release smoke contract failures={$failures}\n");
            exit(1);
        }

        fwrite(STDOUT, "OK: disposable release smoke contracts passed.\n");
    });
}

function wp_fts_disposable_smoke_contract_contains(string $needle, string $haystack, string $message): void
{
    if (function_exists('assert_contains')) {
        assert_contains($needle, $haystack, $message);
        return;
    }

    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message);
    }
}

function wp_fts_disposable_smoke_contract_not_contains(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message . "\nUnexpected: " . var_export($needle, true));
    }
}

function wp_fts_disposable_smoke_contract_true(bool $condition, string $message): void
{
    if (function_exists('assert_true')) {
        assert_true($condition, $message);
        return;
    }

    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wp_fts_disposable_smoke_contract_same(mixed $expected, mixed $actual, string $message): void
{
    if (function_exists('assert_same')) {
        assert_same($expected, $actual, $message);
        return;
    }

    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function wp_fts_disposable_smoke_contract_pending(string $message): void
{
    if (function_exists('mark_pending')) {
        mark_pending($message);
    }

    throw new RuntimeException($message);
}

function wp_fts_disposable_smoke_contract_temp_dir(): string
{
    $dir = sys_get_temp_dir() . '/wp_fts_disposable_smoke_' . getmypid() . '_' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("Could not create temporary disposable smoke fixture directory: {$dir}");
    }

    return $dir;
}

function wp_fts_disposable_smoke_contract_write_file(string $path, string $contents = "fixture\n"): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException("Could not create fixture directory: {$directory}");
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException("Could not write fixture file: {$path}");
    }
}

function wp_fts_disposable_smoke_contract_remove_tree(string $directory): void
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

function wp_fts_disposable_smoke_contract_wp_root(string $tmp, bool $withMarker = false): string
{
    $root = $tmp . '/wordpress';
    wp_fts_disposable_smoke_contract_write_file($root . '/wp-load.php', "<?php\n");
    if ($withMarker) {
        wp_fts_disposable_smoke_contract_write_file($root . '/' . WP_FTS_DisposableReleaseSmokeRunner::MARKER_FILE, "disposable\n");
    }

    return $root;
}

/**
 * @param array<string,string> $env
 * @return array{exit:int,stdout:string,stderr:string}
 */
function wp_fts_disposable_smoke_contract_run_script(array $env): array
{
    if (!function_exists('proc_open')) {
        wp_fts_disposable_smoke_contract_pending('proc_open() is unavailable, so the disposable smoke CLI skip contract cannot launch a subprocess.');
    }

    $baseEnv = getenv();
    if (!is_array($baseEnv)) {
        $baseEnv = [];
    }

    $script = dirname(__DIR__, 2) . '/tools/smoke-disposable-wordpress-release.php';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, $script], $descriptors, $pipes, dirname(__DIR__, 2), array_merge($baseEnv, $env));
    if (!is_resource($process)) {
        wp_fts_disposable_smoke_contract_pending('Could not start the disposable smoke runner subprocess.');
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

function wp_fts_disposable_smoke_contract_json(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Could not encode disposable smoke fixture JSON.');
    }

    return $json;
}

/**
 * @param array<int,array<int,string>> $commands
 */
function wp_fts_disposable_smoke_contract_find_command(array $commands, string $firstArg, string $secondArg = ''): ?array
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

test_case('quality disposable release smoke skips clearly without environment', function (): void {
    $result = wp_fts_disposable_smoke_contract_run_script([
        WP_FTS_DisposableReleaseSmokeRunner::WP_PATH_ENV => '',
        WP_FTS_DisposableReleaseSmokeRunner::ALLOW_ENV => '',
        WP_FTS_DisposableReleaseSmokeRunner::CONFIRM_PATH_ENV => '',
        WP_FTS_DisposableReleaseSmokeRunner::RELEASE_ZIP_ENV => '',
        WP_FTS_DisposableReleaseSmokeRunner::WP_CLI_ENV => 'wp-fts-contract-missing-wp-cli',
    ]);
    $output = $result['stdout'] . $result['stderr'];

    wp_fts_disposable_smoke_contract_same(0, $result['exit'], 'disposable release smoke should exit zero for default skip');
    wp_fts_disposable_smoke_contract_contains('SKIP:', $output, 'default disposable smoke skip should be explicit');
    wp_fts_disposable_smoke_contract_contains('WP_FTS_WP_PATH', $output, 'default disposable smoke skip should name WP_FTS_WP_PATH');
});

test_case('quality disposable release smoke skips invalid WordPress path without process launch', function (): void {
    $tmp = wp_fts_disposable_smoke_contract_temp_dir();
    $commands = [];
    try {
        $runner = new WP_FTS_DisposableReleaseSmokeRunner(
            function (array $command) use (&$commands): array {
                $commands[] = $command;
                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            },
            null,
            null,
            [
                WP_FTS_DisposableReleaseSmokeRunner::WP_PATH_ENV => $tmp . '/not-wordpress',
                WP_FTS_DisposableReleaseSmokeRunner::ALLOW_ENV => '1',
                WP_FTS_DisposableReleaseSmokeRunner::CONFIRM_PATH_ENV => $tmp . '/not-wordpress',
            ]
        );
        $result = $runner->run(['zip' => $tmp . '/release.zip']);

        wp_fts_disposable_smoke_contract_same('skipped', $result['status'], 'invalid WP path should skip');
        wp_fts_disposable_smoke_contract_contains('WP_FTS_WP_PATH', $result['message'], 'invalid WP path skip should name WP_FTS_WP_PATH');
        wp_fts_disposable_smoke_contract_same([], $commands, 'invalid WP path should not launch WP-CLI');
    } finally {
        wp_fts_disposable_smoke_contract_remove_tree($tmp);
    }
});

test_case('quality disposable release smoke requires write opt-in before assembling commands', function (): void {
    $tmp = wp_fts_disposable_smoke_contract_temp_dir();
    $commands = [];
    try {
        $wpRoot = wp_fts_disposable_smoke_contract_wp_root($tmp);
        $runner = new WP_FTS_DisposableReleaseSmokeRunner(
            function (array $command) use (&$commands): array {
                $commands[] = $command;
                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            },
            null,
            null,
            [
                WP_FTS_DisposableReleaseSmokeRunner::WP_PATH_ENV => $wpRoot,
                WP_FTS_DisposableReleaseSmokeRunner::CONFIRM_PATH_ENV => $wpRoot,
                WP_FTS_DisposableReleaseSmokeRunner::WP_CLI_ENV => 'custom-wp',
            ]
        );
        $result = $runner->run(['zip' => $tmp . '/release.zip']);

        wp_fts_disposable_smoke_contract_same('skipped', $result['status'], 'missing write opt-in should skip');
        wp_fts_disposable_smoke_contract_contains('WP_FTS_DISPOSABLE_SMOKE_ALLOW=1', $result['message'], 'missing opt-in skip should name the write guard');
        wp_fts_disposable_smoke_contract_same([], $commands, 'missing opt-in should stop before any WP-CLI command is assembled');
    } finally {
        wp_fts_disposable_smoke_contract_remove_tree($tmp);
    }
});

test_case('quality disposable release smoke builds bounded WP-CLI command sequence', function (): void {
    $tmp = wp_fts_disposable_smoke_contract_temp_dir();
    $commands = [];
    try {
        $wpRoot = wp_fts_disposable_smoke_contract_wp_root($tmp, true);
        $zip = $tmp . '/wp-fts-indexer.zip';
        wp_fts_disposable_smoke_contract_write_file($zip, "zip fixture\n");

        $runner = new WP_FTS_DisposableReleaseSmokeRunner(
            function (array $command) use (&$commands): array {
                $commands[] = $command;
                $joined = implode(' ', $command);
                if (str_contains($joined, 'core is-installed')) {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }
                if (str_contains($joined, 'plugin install')) {
                    return ['exit' => 0, 'stdout' => 'Activated plugin.', 'stderr' => ''];
                }
                if (str_contains($joined, 'fts status')) {
                    return ['exit' => 0, 'stdout' => wp_fts_disposable_smoke_contract_json(['schema_status' => 'ok', 'pending_queue_count' => 0]), 'stderr' => ''];
                }
                if (str_contains($joined, 'fts repair')) {
                    return ['exit' => 0, 'stdout' => wp_fts_disposable_smoke_contract_json(['schema_status' => 'ok', 'schema_version' => 1]), 'stderr' => ''];
                }
                if (str_contains($joined, 'post create')) {
                    return ['exit' => 0, 'stdout' => "123\n", 'stderr' => ''];
                }
                if (str_contains($joined, 'fts process_batch')) {
                    return ['exit' => 0, 'stdout' => wp_fts_disposable_smoke_contract_json(['processed' => 1, 'queue_processed' => 1]), 'stderr' => ''];
                }
                if (str_contains($joined, 'fts search')) {
                    return ['exit' => 0, 'stdout' => wp_fts_disposable_smoke_contract_json([
                        'total' => 1,
                        'results' => [
                            ['doc_id' => 123, 'post_id' => 123, 'title' => 'Fixture'],
                        ],
                    ]), 'stderr' => ''];
                }
                if (str_contains($joined, 'post delete') || str_contains($joined, 'fts delete')) {
                    return ['exit' => 0, 'stdout' => 'Deleted.', 'stderr' => ''];
                }

                return ['exit' => 1, 'stdout' => '', 'stderr' => 'Unexpected command: ' . $joined];
            },
            null,
            null,
            [
                WP_FTS_DisposableReleaseSmokeRunner::WP_PATH_ENV => $wpRoot,
                WP_FTS_DisposableReleaseSmokeRunner::ALLOW_ENV => '1',
                WP_FTS_DisposableReleaseSmokeRunner::WP_CLI_ENV => 'custom-wp',
            ]
        );
        $result = $runner->run(['zip' => $zip]);

        wp_fts_disposable_smoke_contract_same('passed', $result['status'], 'fake disposable smoke command sequence should pass');
        wp_fts_disposable_smoke_contract_true($commands !== [], 'fake WP-CLI should record commands');
        foreach ($commands as $command) {
            wp_fts_disposable_smoke_contract_same('custom-wp', $command[0], 'WP_FTS_WP_CLI should override the wp binary');
            wp_fts_disposable_smoke_contract_true(in_array('--path=' . $wpRoot, $command, true), 'each WP-CLI command should include --path');
        }

        $install = wp_fts_disposable_smoke_contract_find_command($commands, 'plugin', 'install');
        wp_fts_disposable_smoke_contract_true(is_array($install), 'smoke should install the release ZIP');
        wp_fts_disposable_smoke_contract_true(in_array($zip, $install, true), 'plugin install should use the explicit release ZIP');
        wp_fts_disposable_smoke_contract_true(in_array('--force', $install, true), 'plugin install should be explicit about replacing the disposable plugin copy');
        wp_fts_disposable_smoke_contract_true(in_array('--activate', $install, true), 'plugin install should activate the release package');

        foreach (['status', 'repair', 'search'] as $subcommand) {
            $command = wp_fts_disposable_smoke_contract_find_command($commands, 'fts', $subcommand);
            wp_fts_disposable_smoke_contract_true(is_array($command), "smoke should run wp fts {$subcommand}");
            wp_fts_disposable_smoke_contract_true(in_array('--format=json', $command, true), "wp fts {$subcommand} should request JSON evidence");
        }

        $batch = wp_fts_disposable_smoke_contract_find_command($commands, 'fts', 'process_batch');
        wp_fts_disposable_smoke_contract_true(is_array($batch), 'smoke should run one bounded indexing batch');
        wp_fts_disposable_smoke_contract_true(in_array('--batch_size=1', $batch, true), 'process_batch should be batch bounded');
        wp_fts_disposable_smoke_contract_true(in_array('--time_budget=5', $batch, true), 'process_batch should be time bounded');
        wp_fts_disposable_smoke_contract_true(in_array('--format=json', $batch, true), 'process_batch should emit JSON evidence');
    } finally {
        wp_fts_disposable_smoke_contract_remove_tree($tmp);
    }
});

test_case('quality disposable release smoke sanitizes successful JSON evidence', function (): void {
    $tmp = wp_fts_disposable_smoke_contract_temp_dir();
    try {
        $wpRoot = wp_fts_disposable_smoke_contract_wp_root($tmp, true);
        $zip = $tmp . '/wp-fts-indexer.zip';
        wp_fts_disposable_smoke_contract_write_file($zip, "zip fixture\n");
        $rawPasswordValue = 'hunter' . '2';
        $rawPasswordAssignment = 'password=' . $rawPasswordValue;
        $rawBearerToken = 'abc' . '123';
        $rawLocalPath = '/' . 'home' . '/' . 'claude' . '/' . 'private' . '/' . 'path';
        $rawPrivateKey = '-----BEGIN ' . 'PRIVATE ' . 'KEY-----'
            . "\nfixture\n"
            . '-----END ' . 'PRIVATE ' . 'KEY-----';

        $runner = new WP_FTS_DisposableReleaseSmokeRunner(
            function (array $command) use ($rawPasswordValue, $rawPasswordAssignment, $rawBearerToken, $rawLocalPath, $rawPrivateKey): array {
                $joined = implode(' ', $command);
                if (str_contains($joined, 'core is-installed')) {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }
                if (str_contains($joined, 'plugin install')) {
                    return ['exit' => 0, 'stdout' => 'Activated plugin.', 'stderr' => ''];
                }
                if (str_contains($joined, 'fts status')) {
                    return ['exit' => 0, 'stdout' => wp_fts_disposable_smoke_contract_json([
                        'schema_status' => 'ok',
                        'diagnostics' => [
                            'note' => $rawPasswordAssignment . ' Authorization: Bearer ' . $rawBearerToken . ' ' . $rawLocalPath,
                            'pem' => $rawPrivateKey,
                        ],
                        'password' => $rawPasswordValue,
                        $rawLocalPath => 'path key should be sanitized too',
                    ]), 'stderr' => ''];
                }
                if (str_contains($joined, 'fts repair')) {
                    return ['exit' => 0, 'stdout' => wp_fts_disposable_smoke_contract_json(['schema_status' => 'ok', 'schema_version' => 1]), 'stderr' => ''];
                }
                if (str_contains($joined, 'post create')) {
                    return ['exit' => 0, 'stdout' => "123\n", 'stderr' => ''];
                }
                if (str_contains($joined, 'fts process_batch')) {
                    return ['exit' => 0, 'stdout' => wp_fts_disposable_smoke_contract_json([
                        'processed' => 1,
                        'debug' => $rawPasswordAssignment . ' ' . $rawLocalPath,
                    ]), 'stderr' => ''];
                }
                if (str_contains($joined, 'fts search')) {
                    return ['exit' => 0, 'stdout' => wp_fts_disposable_smoke_contract_json([
                        'total' => 1,
                        'results' => [
                            ['doc_id' => 123, 'post_id' => 123, 'title' => 'Fixture'],
                        ],
                    ]), 'stderr' => ''];
                }
                if (str_contains($joined, 'post delete') || str_contains($joined, 'fts delete')) {
                    return ['exit' => 0, 'stdout' => 'Deleted.', 'stderr' => ''];
                }

                return ['exit' => 1, 'stdout' => '', 'stderr' => 'Unexpected command: ' . $joined];
            },
            null,
            null,
            [
                WP_FTS_DisposableReleaseSmokeRunner::WP_PATH_ENV => $wpRoot,
                WP_FTS_DisposableReleaseSmokeRunner::ALLOW_ENV => '1',
                WP_FTS_DisposableReleaseSmokeRunner::WP_CLI_ENV => 'custom-wp',
            ]
        );
        $result = $runner->run(['zip' => $zip]);
        $encodedReport = wp_fts_disposable_smoke_contract_json($result['report']);

        wp_fts_disposable_smoke_contract_same('passed', $result['status'], 'successful fixture should pass');
        wp_fts_disposable_smoke_contract_true(!str_contains($encodedReport, $rawPasswordAssignment), 'successful JSON evidence should redact password assignments');
        wp_fts_disposable_smoke_contract_true(!str_contains($encodedReport, $rawPasswordValue), 'successful JSON evidence should redact sensitive keyed values');
        wp_fts_disposable_smoke_contract_true(!str_contains($encodedReport, $rawBearerToken), 'successful JSON evidence should redact bearer tokens');
        wp_fts_disposable_smoke_contract_true(!str_contains($encodedReport, $rawLocalPath), 'successful JSON evidence should redact local paths');
        wp_fts_disposable_smoke_contract_true(!str_contains($encodedReport, 'BEGIN ' . 'PRIVATE ' . 'KEY'), 'successful JSON evidence should redact private key markers');
        wp_fts_disposable_smoke_contract_contains('schema_status', $encodedReport, 'successful JSON evidence should retain useful status fields');
        wp_fts_disposable_smoke_contract_contains('processed', $encodedReport, 'successful JSON evidence should retain useful indexing fields');
        wp_fts_disposable_smoke_contract_contains('[redacted]', $encodedReport, 'successful JSON evidence should show redaction markers');
        wp_fts_disposable_smoke_contract_contains('[path]', $encodedReport, 'successful JSON evidence should show path redaction markers');
    } finally {
        wp_fts_disposable_smoke_contract_remove_tree($tmp);
    }
});

test_case('quality disposable release smoke sanitizes and bounds failed command output', function (): void {
    $tmp = wp_fts_disposable_smoke_contract_temp_dir();
    try {
        $wpRoot = wp_fts_disposable_smoke_contract_wp_root($tmp, true);
        $zip = $tmp . '/wp-fts-indexer.zip';
        wp_fts_disposable_smoke_contract_write_file($zip, "zip fixture\n");
        $rawPasswordValue = 'hunter' . '2';
        $rawPasswordAssignment = 'password=' . $rawPasswordValue;
        $rawBearerToken = 'abc' . '123';
        $rawLocalPath = '/' . 'home' . '/' . 'claude' . '/' . 'private' . '/' . 'path';
        $failureDetail = $rawPasswordAssignment . ' Authorization: Bearer ' . $rawBearerToken . ' ' . $rawLocalPath . ' failure';

        $runner = new WP_FTS_DisposableReleaseSmokeRunner(
            function (array $command) use ($failureDetail): array {
                $joined = implode(' ', $command);
                if (str_contains($joined, 'core is-installed')) {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }

                return [
                    'exit' => 1,
                    'stdout' => str_repeat('x', 1400),
                    'stderr' => $failureDetail,
                ];
            },
            null,
            null,
            [
                WP_FTS_DisposableReleaseSmokeRunner::WP_PATH_ENV => $wpRoot,
                WP_FTS_DisposableReleaseSmokeRunner::ALLOW_ENV => '1',
                WP_FTS_DisposableReleaseSmokeRunner::WP_CLI_ENV => 'custom-wp',
            ]
        );
        $result = $runner->run(['zip' => $zip]);
        $encodedReport = wp_fts_disposable_smoke_contract_json($result['report']);

        wp_fts_disposable_smoke_contract_same('failed', $result['status'], 'failed WP-CLI command should fail the smoke');
        wp_fts_disposable_smoke_contract_true(!str_contains($result['message'], $rawPasswordValue), 'failure message should redact password values');
        wp_fts_disposable_smoke_contract_true(!str_contains($encodedReport, $rawBearerToken), 'report should redact bearer tokens');
        wp_fts_disposable_smoke_contract_true(!str_contains($encodedReport, $rawLocalPath), 'report should redact local paths');
        wp_fts_disposable_smoke_contract_contains('[redacted]', $encodedReport, 'report should show redaction markers');
        wp_fts_disposable_smoke_contract_contains('[path]', $encodedReport, 'report should show path redaction markers');
        wp_fts_disposable_smoke_contract_contains('[truncated]', $encodedReport, 'report should bound long command output');
    } finally {
        wp_fts_disposable_smoke_contract_remove_tree($tmp);
    }
});

test_case('quality disposable release smoke docs and composer command are operator-facing', function (): void {
    $root = dirname(__DIR__, 2);
    $docs = (string) file_get_contents($root . '/docs/testing.md');
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);

    wp_fts_disposable_smoke_contract_true(is_array($composer), 'composer.json should decode for disposable smoke docs check');
    wp_fts_disposable_smoke_contract_same(
        'php tools/smoke-disposable-wordpress-release.php',
        $composer['scripts']['test:smoke:release'] ?? null,
        'composer should expose the disposable release smoke runner'
    );
    wp_fts_disposable_smoke_contract_same(
        'tools/run-disposable-release-provider-smoke.sh',
        $composer['scripts']['test:smoke:release-provider:docker'] ?? null,
        'composer should expose the Docker release/provider smoke wrapper'
    );

    foreach ([
        'Disposable Release Smoke',
        'WP_FTS_DISPOSABLE_SMOKE_ALLOW=1',
        'WP_FTS_WP_PATH=/path/to/wordpress',
        'WP_FTS_RELEASE_ZIP=/path/to/wp-fts-indexer.zip',
        'composer test:smoke:release',
        'exits with `SKIP:`',
        'does not',
        'normal PHP harness',
        'Docker Disposable Release/Provider Smoke',
        'tools/run-disposable-release-provider-smoke.sh',
        'composer test:smoke:release-provider:docker',
        '--run-docker-disposable-smokes',
        'host-provided WordPress root',
    ] as $needle) {
        wp_fts_disposable_smoke_contract_contains($needle, $docs, "testing docs should mention {$needle}");
    }
});

test_case('quality Docker disposable release/provider wrapper is guarded and disposable-only', function (): void {
    $root = dirname(__DIR__, 2);
    $script = (string) file_get_contents($root . '/tools/run-disposable-release-provider-smoke.sh');

    foreach ([
        'set -euo pipefail',
        'docker compose version',
        'docker info',
        'SKIP:',
        'mktemp -d /tmp/wp-fts-release-provider-smoke.',
        'trap cleanup EXIT INT TERM',
        'docker compose -f "${COMPOSE_FILE}" down -v',
        'rm -rf "${PROOF_ROOT}"',
        'MARIADB_DATABASE: wpfts_release_smoke',
        'MARIADB_USER: wpfts_release_smoke',
        'MARIADB_PASSWORD: wpfts_release_smoke_dev_only',
        'MARIADB_ROOT_PASSWORD: wpfts_release_smoke_root_dev_only',
        'WORDPRESS_DB_PASSWORD: wpfts_release_smoke_dev_only',
        '${PROOF_ROOT}/plugin:/smoke-src:ro',
        '${PROOF_ROOT}/release:/release:ro',
        'tools/build-release-zip.php',
        '/release/wp-fts-indexer.zip',
        'touch /var/www/html/.wp-fts-disposable-smoke /var/www/html/.wp-fts-provider-compatibility-smoke',
        'WP_FTS_DISPOSABLE_SMOKE_ALLOW=1',
        'smoke-disposable-wordpress-release.php',
        'plugin deactivate indexer --path=/var/www/html',
        'WP_FTS_PROVIDER_COMPATIBILITY_ALLOW=1',
        'smoke-search-provider-compatibility.php',
        'PASS: Docker disposable release/provider smoke completed.',
    ] as $needle) {
        wp_fts_disposable_smoke_contract_contains($needle, $script, "Docker wrapper should contain {$needle}");
    }

    foreach ([
        '--env-file',
        'source .env',
        'AWS_',
        'MYSQL_PASSWORD=${',
        'WORDPRESS_DB_PASSWORD=${',
        'MARIADB_PASSWORD=${',
    ] as $needle) {
        wp_fts_disposable_smoke_contract_not_contains($needle, $script, "Docker wrapper should not consume host secret-like configuration {$needle}");
    }

    $releaseSmoke = strpos($script, 'smoke-disposable-wordpress-release.php');
    $deactivate = strpos($script, 'plugin deactivate indexer --path=/var/www/html');
    $providerSmoke = strpos($script, 'smoke-search-provider-compatibility.php');
    wp_fts_disposable_smoke_contract_true(
        is_int($releaseSmoke) && is_int($deactivate) && is_int($providerSmoke)
            && $releaseSmoke < $deactivate && $deactivate < $providerSmoke,
        'Docker wrapper should run the release ZIP smoke, deactivate the installed release, then run provider compatibility smoke'
    );
});
