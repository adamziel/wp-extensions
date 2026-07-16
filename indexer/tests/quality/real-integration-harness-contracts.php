<?php
declare(strict_types=1);

if (!function_exists('test_case')) {
    final class WP_FTS_RealIntegrationContractPending extends RuntimeException
    {
    }

    $GLOBALS['wp_fts_real_integration_contract_direct_failures'] = 0;
    $GLOBALS['wp_fts_real_integration_contract_direct_pending'] = 0;

    function test_case(string $name, callable $fn): void
    {
        try {
            $fn();
            fwrite(STDOUT, "[PASS] {$name}\n");
        } catch (WP_FTS_RealIntegrationContractPending $e) {
            $GLOBALS['wp_fts_real_integration_contract_direct_pending']++;
            fwrite(STDOUT, "[PEND] {$name}\n{$e->getMessage()}\n");
        } catch (Throwable $e) {
            $GLOBALS['wp_fts_real_integration_contract_direct_failures']++;
            fwrite(STDERR, "[FAIL] {$name}\n{$e->getMessage()}\n");
        }
    }

    function assert_true(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
        }
    }

    function assert_contains(string $needle, string $haystack, string $message): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException($message . "\nMissing: " . var_export($needle, true));
        }
    }

    function mark_pending(string $message): never
    {
        throw new WP_FTS_RealIntegrationContractPending($message);
    }

    register_shutdown_function(static function (): void {
        $failures = (int) ($GLOBALS['wp_fts_real_integration_contract_direct_failures'] ?? 0);
        $pending = (int) ($GLOBALS['wp_fts_real_integration_contract_direct_pending'] ?? 0);
        if ($failures > 0) {
            fwrite(STDERR, "Real integration harness contract failures={$failures}; pending={$pending}\n");
            exit(1);
        }

        fwrite(STDOUT, "OK: real integration harness contracts passed; pending={$pending}.\n");
    });
}

test_case('quality real integration harness skips clearly without WordPress configuration', function (): void {
    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is unavailable, so the real integration skip contract cannot launch a subprocess.');
    }

    $baseEnv = getenv();
    if (!is_array($baseEnv)) {
        $baseEnv = [];
    }

    $script = dirname(__DIR__) . '/integration/real-wordpress-mysql.php';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        [PHP_BINARY, $script],
        $descriptors,
        $pipes,
        dirname(__DIR__, 2),
        array_merge($baseEnv, [
            'WP_FTS_WP_PATH' => '',
            'WP_FTS_WP_CLI' => 'wp-fts-contract-missing-wp-cli',
            'WP_FTS_REAL_INTEGRATION_INSIDE' => '',
        ])
    );
    if (!is_resource($process)) {
        mark_pending('Could not start the real integration harness subprocess.');
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $output = $stdout . $stderr;

    assert_same(0, is_int($exit) ? $exit : 1, 'real integration harness should exit zero when skipping missing WordPress config');
    assert_contains('SKIP:', $output, 'real integration harness skip should be explicit');
    assert_contains('WP_FTS_WP_PATH', $output, 'real integration harness skip should name the required WordPress path variable');
});

test_case('quality real mysql production proof skips clearly without WordPress configuration', function (): void {
    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is unavailable, so the real MySQL proof skip contract cannot launch a subprocess.');
    }

    $baseEnv = getenv();
    if (!is_array($baseEnv)) {
        $baseEnv = [];
    }

    $script = dirname(__DIR__) . '/integration/real-mysql-production-proof.php';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        [PHP_BINARY, $script],
        $descriptors,
        $pipes,
        dirname(__DIR__, 2),
        array_merge($baseEnv, [
            'WP_FTS_WP_PATH' => '',
            'WP_FTS_WP_CLI' => 'wp-fts-contract-missing-wp-cli',
            'WP_FTS_REAL_MYSQL_PROOF_INSIDE' => '',
            'WP_FTS_MYSQL_PROOF_ALLOW_DISPOSABLE' => '',
        ])
    );
    if (!is_resource($process)) {
        mark_pending('Could not start the real MySQL proof subprocess.');
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $output = $stdout . $stderr;

    assert_same(0, is_int($exit) ? $exit : 1, 'real MySQL proof should exit zero when skipping missing WordPress config');
    assert_contains('SKIP:', $output, 'real MySQL proof skip should be explicit');
    assert_contains('WP_FTS_WP_PATH', $output, 'real MySQL proof skip should name the required WordPress path variable');
});

test_case('quality real integration commands are documented and composer-addressable', function (): void {
    $root = dirname(__DIR__, 2);
    $readme = (string) file_get_contents($root . '/tests/README.md');
    $testing = (string) file_get_contents($root . '/docs/testing.md');
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);

    assert_true(is_array($composer), 'composer.json should decode for integration command checks');
    assert_same(
        'php tests/integration/real-wordpress-mysql.php',
        $composer['scripts']['test:integration:real'] ?? null,
        'composer should expose the real WordPress/MySQL integration harness'
    );
    assert_same(
        'php tests/integration/real-mysql-production-proof.php',
        $composer['scripts']['test:integration:mysql-proof'] ?? null,
        'composer should expose the real MySQL production-path proof'
    );
    assert_same(
        'php tests/integration/concurrent-indexing.php',
        $composer['scripts']['test:integration:concurrent'] ?? null,
        'composer should expose the concurrent indexing diagnostic'
    );
    assert_contains('WP_FTS_WP_PATH=/path/to/wordpress', $readme, 'README should document the real integration command');
    assert_contains('WP_FTS_MYSQL_PROOF_ALLOW_DISPOSABLE=1', $readme, 'README should document the guarded proof opt-in');
    assert_contains('tools/run-real-mysql-production-proof.sh', $testing, 'testing docs should document the Docker proof helper');
    assert_contains('WP_FTS_CONCURRENT_WORKERS=4', $readme, 'README should document the concurrent indexing command');
});

test_case('quality real mysql proof helper packages the component path repository before composer install', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/run-real-mysql-production-proof.sh');

    assert_contains('COMPONENT_DIR="${REPO_ROOT}/components/full-text-search"', $script, 'proof helper should locate the split FTS component from the monorepo root');
    assert_contains('copy_proof_tree "${PLUGIN_DIR}" "${PROOF_ROOT}/plugin"', $script, 'proof helper should copy the plugin source tree into proof storage');
    assert_contains('copy_proof_tree "${COMPONENT_DIR}" "${PROOF_ROOT}/components/full-text-search"', $script, 'proof helper should copy the component into the path Composer expects');

    $componentCopy = strpos($script, 'copy_proof_tree "${COMPONENT_DIR}" "${PROOF_ROOT}/components/full-text-search"');
    $composerInstall = strpos($script, "install_proof_composer_dependencies\n");
    assert_true(is_int($componentCopy) && is_int($composerInstall) && $componentCopy < $composerInstall, 'proof helper should copy the component path repository before composer install');
});

test_case('quality real mysql proof helper isolates composer install environment', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/run-real-mysql-production-proof.sh');

    assert_contains('PROOF_HOME="${PROOF_ROOT}/home"', $script, 'proof helper should keep Composer HOME inside proof storage');
    assert_contains('PROOF_TMPDIR="${PROOF_ROOT}/tmp"', $script, 'proof helper should keep Composer temp files inside proof storage');
    assert_contains('PROOF_COMPOSER_HOME="${PROOF_ROOT}/composer/home"', $script, 'proof helper should keep Composer home inside proof storage');
    assert_contains('PROOF_COMPOSER_CACHE_DIR="${PROOF_ROOT}/composer/cache"', $script, 'proof helper should keep Composer cache inside proof storage');
    assert_contains('env -i', $script, 'proof helper should run Composer with an empty inherited environment');
    assert_contains('PATH="${PROOF_SAFE_PATH}"', $script, 'proof helper should pass only a bounded PATH into Composer');
    assert_contains('HOME="${PROOF_HOME}"', $script, 'proof helper should pass proof-local HOME into Composer');
    assert_contains('TMPDIR="${PROOF_TMPDIR}"', $script, 'proof helper should pass proof-local TMPDIR into Composer');
    assert_contains('COMPOSER_HOME="${PROOF_COMPOSER_HOME}"', $script, 'proof helper should pass proof-local COMPOSER_HOME into Composer');
    assert_contains('COMPOSER_CACHE_DIR="${PROOF_COMPOSER_CACHE_DIR}"', $script, 'proof helper should pass proof-local COMPOSER_CACHE_DIR into Composer');

    $isolatedComposerInstallPattern = '/env -i\s+\\\\\s+PATH="\$\{PROOF_SAFE_PATH\}"\s+\\\\\s+HOME="\$\{PROOF_HOME\}"\s+\\\\\s+TMPDIR="\$\{PROOF_TMPDIR\}"\s+\\\\\s+COMPOSER_HOME="\$\{PROOF_COMPOSER_HOME\}"\s+\\\\\s+COMPOSER_CACHE_DIR="\$\{PROOF_COMPOSER_CACHE_DIR\}"\s+\\\\\s+composer install --no-interaction --no-dev --optimize-autoloader/s';
    assert_true(preg_match($isolatedComposerInstallPattern, $script) === 1, 'proof helper should keep composer install directly under the isolated env whitelist');

    foreach (['COMPOSER_AUTH', 'GITHUB_TOKEN', 'GH_TOKEN', 'GIT_ASKPASS', 'SSH_AUTH_SOCK'] as $name) {
        assert_true(preg_match('/\b' . preg_quote($name, '/') . '=/', $script) !== 1, "proof helper should not pass {$name} into Composer");
        assert_true(!str_contains($script, '${' . $name . '}'), "proof helper should not read {$name} from the parent environment");
    }
});

test_case('quality real mysql proof helper excludes composer auth files from copied source trees', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/run-real-mysql-production-proof.sh');

    foreach ([
        "--exclude='./auth.json'",
        "--exclude='*/auth.json'",
        "--exclude='./.composer'",
        "--exclude='./.composer/**'",
        "--exclude='*/.composer'",
        "--exclude='*/.composer/**'",
    ] as $exclude) {
        assert_contains($exclude, $script, "proof helper copy should exclude {$exclude}");
    }

    $pluginCopy = strpos($script, 'copy_proof_tree "${PLUGIN_DIR}" "${PROOF_ROOT}/plugin"');
    $componentCopy = strpos($script, 'copy_proof_tree "${COMPONENT_DIR}" "${PROOF_ROOT}/components/full-text-search"');
    assert_true(is_int($pluginCopy) && is_int($componentCopy), 'proof helper should use the shared auth-excluding copy path for plugin and component trees');
});

test_case('quality real mysql harness source tracks current seven-table row-postings schema', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__) . '/integration/real-wordpress-mysql.php');
    $proof = (string) file_get_contents(dirname(__DIR__) . '/integration/real-mysql-production-proof.php');

    foreach (['fts_terms', 'fts_postings', 'fts_docs', 'fts_doc_lengths', 'fts_docmeta', 'fts_meta', 'fts_queue'] as $table) {
        assert_contains($table, $script, "real integration harness should mention {$table}");
        assert_contains($table, $proof, "real MySQL proof should mention {$table}");
    }

    assert_contains('doc_freq', $script, 'real integration harness should assert fts_terms.doc_freq');
    assert_contains('WP_FTS_MYSQL_PROOF_ALLOW_DISPOSABLE', $proof, 'real MySQL proof should require disposable-site opt-in');
    assert_true(!str_contains($script, "assert_column(\$wpdb, \$tables['terms'], 'postings')"), 'real integration harness should not expect a stale fts_terms.postings column');
});
