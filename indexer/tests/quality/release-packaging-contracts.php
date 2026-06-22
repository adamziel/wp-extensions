<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools/build-release-zip.php';

function wp_fts_release_packaging_contract_contains(string $needle, string $haystack, string $message): void
{
    if (function_exists('assert_contains')) {
        assert_contains($needle, $haystack, $message);
        return;
    }

    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message);
    }
}

function wp_fts_release_packaging_contract_true(bool $condition, string $message): void
{
    if (function_exists('assert_true')) {
        assert_true($condition, $message);
        return;
    }

    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wp_fts_release_packaging_contract_same(mixed $expected, mixed $actual, string $message): void
{
    if (function_exists('assert_same')) {
        assert_same($expected, $actual, $message);
        return;
    }

    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function wp_fts_release_packaging_contract_temp_dir(): string
{
    $dir = sys_get_temp_dir() . '/wp_fts_release_contract_' . getmypid() . '_' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("Could not create temporary release contract directory: {$dir}");
    }

    return $dir;
}

function wp_fts_release_packaging_contract_remove_tree(string $directory): void
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

function wp_fts_release_packaging_contract_write_fixture(string $path, string $contents = "fixture\n"): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException("Could not create fixture directory: {$directory}");
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException("Could not write fixture file: {$path}");
    }
}

function wp_fts_release_packaging_contract_run(): void
{
    $root = dirname(__DIR__, 2);
    $docs = (string) file_get_contents($root . '/docs/release-packaging.md');
    $distignore = (string) file_get_contents($root . '/.distignore');

    foreach ([
        'vendor/bin',
        'vendor/bin/**',
        'vendor/*/*/test',
        'vendor/*/*/test/**',
        'vendor/*/*/tests',
        'vendor/*/*/tests/**',
        'vendor/*/*/Tests',
        'vendor/*/*/Tests/**',
        'vendor/*/*/coverage',
        'vendor/*/*/coverage/**',
        'auth.json',
        '.composer',
        '.composer/**',
    ] as $pattern) {
        wp_fts_release_packaging_contract_contains(
            $pattern,
            $distignore,
            ".distignore should exclude {$pattern} from release package staging"
        );
    }

    foreach ([
        'dependency-internal test and coverage fixtures under `vendor/`',
        'php indexer/tools/build-release-zip.php',
        'prunes staged dotfiles anywhere in the package before ZIP creation',
        'indexer/vendor/wamania/php-stemmer/.gitignore',
        'vendor/wp-php-toolkit/full-text-search/tests/',
        'vendor/bin',
        '`test`, `tests`, `Tests`, and `coverage`',
        'prohibited dotfiles',
        'direct-install ZIP boundary only',
        'WordPress.org or SVN submission',
        'dependency-internal vendor tests such as',
        'indexer/vendor/wp-php-toolkit/full-text-search/tests/*',
        'Composer auth files such as',
        'indexer/auth.json',
        'indexer/.composer/auth.json',
    ] as $needle) {
        wp_fts_release_packaging_contract_contains(
            $needle,
            $docs,
            "release packaging docs should mention {$needle}"
        );
    }

    $vendorTestsPosition = strpos($docs, 'indexer/vendor/wp-php-toolkit/full-text-search/tests/*');
    $builderPosition = strpos($docs, 'php indexer/tools/build-release-zip.php');
    wp_fts_release_packaging_contract_true(
        is_int($builderPosition) && is_int($vendorTestsPosition) && $builderPosition < $vendorTestsPosition,
        'release ZIP flow should run the builder before documenting vendor test exclusions'
    );
}

function wp_fts_release_packaging_contract_prune_run(): void
{
    $tmp = wp_fts_release_packaging_contract_temp_dir();
    $stage = $tmp . '/indexer';
    try {
        wp_fts_release_packaging_contract_write_fixture($stage . '/indexer.php');
        wp_fts_release_packaging_contract_write_fixture($stage . '/src/Plugin.php');
        wp_fts_release_packaging_contract_write_fixture($stage . '/vendor/wamania/php-stemmer/src/Stemmer.php');
        wp_fts_release_packaging_contract_write_fixture($stage . '/vendor/wamania/php-stemmer/.gitignore');
        wp_fts_release_packaging_contract_write_fixture($stage . '/vendor/wamania/php-stemmer/.distignore');
        wp_fts_release_packaging_contract_write_fixture($stage . '/vendor/wamania/php-stemmer/.gitattributes');
        wp_fts_release_packaging_contract_write_fixture($stage . '/vendor/wp-php-toolkit/full-text-search/tests/smoke.php');
        wp_fts_release_packaging_contract_write_fixture($stage . '/vendor/example/library/Tests/UnitTest.php');
        wp_fts_release_packaging_contract_write_fixture($stage . '/vendor/example/library/coverage/report.xml');
        wp_fts_release_packaging_contract_write_fixture($stage . '/vendor/bin/phpunit');
        wp_fts_release_packaging_contract_write_fixture($stage . '/review-artifacts/evidence.json');
        wp_fts_release_packaging_contract_write_fixture($stage . '/resources/sources/raw-source.txt');
        wp_fts_release_packaging_contract_write_fixture($stage . '/playground/indexer-preview.zip');
        wp_fts_release_packaging_contract_write_fixture($stage . '/auth.json', '{"github-oauth":{"example.test":"dummy"}}');
        wp_fts_release_packaging_contract_write_fixture($stage . '/.composer/auth.json', '{"http-basic":{"example.test":{"username":"dummy","password":"dummy"}}}');
        wp_fts_release_packaging_contract_write_fixture($stage . '/.gitignore');
        wp_fts_release_packaging_contract_write_fixture($stage . '/.distignore');

        $before = WP_FTS_ReleasePackageBuilder::find_prohibited_package_paths($stage);
        wp_fts_release_packaging_contract_true(
            in_array('indexer/auth.json', $before, true),
            'release verifier should detect root Composer auth files before pruning'
        );
        wp_fts_release_packaging_contract_true(
            in_array('indexer/.composer', $before, true),
            'release verifier should detect Composer auth home directories before pruning'
        );
        wp_fts_release_packaging_contract_true(
            in_array('indexer/vendor/wamania/php-stemmer/.gitignore', $before, true),
            'release verifier should detect nested Composer dependency .gitignore before pruning'
        );
        wp_fts_release_packaging_contract_true(
            in_array('indexer/vendor/wamania/php-stemmer/.distignore', $before, true),
            'release verifier should detect nested Composer dependency .distignore before pruning'
        );
        wp_fts_release_packaging_contract_true(
            in_array('indexer/vendor/wp-php-toolkit/full-text-search/tests', $before, true),
            'release verifier should detect dependency-internal tests before pruning'
        );

        $removed = WP_FTS_ReleasePackageBuilder::prune_staged_package($stage);
        $after = WP_FTS_ReleasePackageBuilder::find_prohibited_package_paths($stage);
        wp_fts_release_packaging_contract_same([], $after, 'release prune should remove all prohibited staged package paths');
        wp_fts_release_packaging_contract_true(
            in_array('indexer/vendor/wamania/php-stemmer/.gitignore', $removed, true),
            'release prune should report removed nested dependency .gitignore'
        );
        wp_fts_release_packaging_contract_true(
            in_array('indexer/vendor/wamania/php-stemmer/.distignore', $removed, true),
            'release prune should report removed nested dependency .distignore'
        );
        wp_fts_release_packaging_contract_true(
            in_array('indexer/auth.json', $removed, true),
            'release prune should report removed root Composer auth files'
        );
        wp_fts_release_packaging_contract_true(
            in_array('indexer/.composer', $removed, true),
            'release prune should report removed Composer auth home directories'
        );
        wp_fts_release_packaging_contract_true(
            is_file($stage . '/vendor/wamania/php-stemmer/src/Stemmer.php'),
            'release prune should preserve runtime dependency source files'
        );
        wp_fts_release_packaging_contract_true(
            !file_exists($stage . '/vendor/bin/phpunit'),
            'release prune should remove vendor binaries'
        );
        wp_fts_release_packaging_contract_true(
            !file_exists($stage . '/review-artifacts/evidence.json'),
            'release prune should remove review artifacts'
        );
    } finally {
        wp_fts_release_packaging_contract_remove_tree($tmp);
    }
}

function wp_fts_release_packaging_contract_composer_env_run(): void
{
    $tmp = wp_fts_release_packaging_contract_temp_dir();
    try {
        $env = WP_FTS_ReleasePackageBuilder::composer_install_environment([
            'COMPOSER_AUTH' => 'review-dummy-token',
            'GITHUB_TOKEN' => 'review-dummy-token',
            'GH_TOKEN' => 'review-dummy-token',
            'GIT_ASKPASS' => '/tmp/review-dummy-askpass',
            'SSH_AUTH_SOCK' => '/tmp/review-dummy-ssh-agent.sock',
            'WP_FTS_SECRET_TOKEN' => 'review-dummy-token',
            'HOME' => '/home/review-dummy',
            'PATH' => '/usr/bin:/bin',
            'COMPOSER_HOME' => $tmp . '/incoming-composer-home',
            'COMPOSER_CACHE_DIR' => $tmp . '/incoming-composer-cache',
            'COMPOSER_DISABLE_NETWORK' => '1',
        ], $tmp . '/build');

        foreach (['COMPOSER_AUTH', 'GITHUB_TOKEN', 'GH_TOKEN', 'GIT_ASKPASS', 'SSH_AUTH_SOCK', 'WP_FTS_SECRET_TOKEN', 'HOME'] as $key) {
            wp_fts_release_packaging_contract_true(
                !array_key_exists($key, $env),
                "nested Composer environment should not include ambient {$key}"
            );
        }
        wp_fts_release_packaging_contract_same($tmp . '/incoming-composer-home', $env['COMPOSER_HOME'] ?? null, 'nested Composer environment should preserve explicit isolated Composer home');
        wp_fts_release_packaging_contract_same($tmp . '/incoming-composer-cache', $env['COMPOSER_CACHE_DIR'] ?? null, 'nested Composer environment should preserve explicit isolated Composer cache');
        wp_fts_release_packaging_contract_same('1', $env['COMPOSER_DISABLE_NETWORK'] ?? null, 'nested Composer environment should preserve explicit Composer network disablement');
        wp_fts_release_packaging_contract_same('/usr/bin:/bin', $env['PATH'] ?? null, 'nested Composer environment should preserve PATH so the composer binary can be resolved');
    } finally {
        wp_fts_release_packaging_contract_remove_tree($tmp);
    }
}

if (function_exists('test_case')) {
    test_case('quality release packaging excludes dependency-internal vendor tests', function (): void {
        wp_fts_release_packaging_contract_run();
    });
    test_case('quality release packaging prunes nested dependency dotfiles from staging', function (): void {
        wp_fts_release_packaging_contract_prune_run();
    });
    test_case('quality release packaging scrubs nested Composer environment', function (): void {
        wp_fts_release_packaging_contract_composer_env_run();
    });
} elseif (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    wp_fts_release_packaging_contract_run();
    wp_fts_release_packaging_contract_prune_run();
    wp_fts_release_packaging_contract_composer_env_run();
    fwrite(STDOUT, "OK: release packaging contract prunes dependency dotfiles, auth files, and vendor tests.\n");
}
