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

test_case('quality real integration commands are documented and composer-addressable', function (): void {
    $root = dirname(__DIR__, 2);
    $readme = (string) file_get_contents($root . '/tests/README.md');
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);

    assert_true(is_array($composer), 'composer.json should decode for integration command checks');
    assert_same(
        'php tests/integration/real-wordpress-mysql.php',
        $composer['scripts']['test:integration:real'] ?? null,
        'composer should expose the real WordPress/MySQL integration harness'
    );
    assert_same(
        'php tests/integration/concurrent-indexing.php',
        $composer['scripts']['test:integration:concurrent'] ?? null,
        'composer should expose the concurrent indexing diagnostic'
    );
    assert_contains('WP_FTS_WP_PATH=/path/to/wordpress', $readme, 'README should document the real integration command');
    assert_contains('WP_FTS_CONCURRENT_WORKERS=4', $readme, 'README should document the concurrent indexing command');
});
