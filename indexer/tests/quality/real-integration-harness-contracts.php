<?php
declare(strict_types=1);

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
    assert_contains('mkdir -p "${PROOF_ROOT}/components/full-text-search"', $script, 'proof helper should create the copied component path repository next to the temp plugin');
    assert_contains('cd "${COMPONENT_DIR}"', $script, 'proof helper should copy from the monorepo component source');
    assert_contains('cd "${PROOF_ROOT}/components/full-text-search"', $script, 'proof helper should copy the component into the path Composer expects');

    $componentCopy = strpos($script, 'cd "${PROOF_ROOT}/components/full-text-search"');
    $composerInstall = strpos($script, 'composer install --no-interaction --no-dev --optimize-autoloader');
    assert_true(is_int($componentCopy) && is_int($composerInstall) && $componentCopy < $composerInstall, 'proof helper should copy the component path repository before composer install');
});

test_case('quality real mysql harness source tracks current six-table row-postings schema', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__) . '/integration/real-wordpress-mysql.php');
    $proof = (string) file_get_contents(dirname(__DIR__) . '/integration/real-mysql-production-proof.php');

    foreach (['fts_terms', 'fts_postings', 'fts_docs', 'fts_doc_lengths', 'fts_docmeta', 'fts_meta'] as $table) {
        assert_contains($table, $script, "real integration harness should mention {$table}");
        assert_contains($table, $proof, "real MySQL proof should mention {$table}");
    }

    assert_contains('doc_freq', $script, 'real integration harness should assert fts_terms.doc_freq');
    assert_contains('WP_FTS_MYSQL_PROOF_ALLOW_DISPOSABLE', $proof, 'real MySQL proof should require disposable-site opt-in');
    assert_true(!str_contains($script, "assert_column(\$wpdb, \$tables['terms'], 'postings')"), 'real integration harness should not expect a stale fts_terms.postings column');
});
