<?php
declare(strict_types=1);

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
    ] as $pattern) {
        wp_fts_release_packaging_contract_contains(
            $pattern,
            $distignore,
            ".distignore should exclude {$pattern} from release package staging"
        );
    }

    foreach ([
        'dependency-internal test and coverage fixtures under `vendor/`',
        'vendor/wp-php-toolkit/full-text-search/tests/',
        'find "$BUILD/indexer/vendor"',
        "-path '*/tests'",
        "-path '*/coverage'",
        "-x 'indexer/vendor/bin/*'",
        "-x 'indexer/vendor/*/*/test/*'",
        "-x 'indexer/vendor/*/*/tests/*'",
        "-x 'indexer/vendor/*/*/Tests/*'",
        "-x 'indexer/vendor/*/*/coverage/*'",
        "-x 'indexer/vendor/wp-php-toolkit/full-text-search/tests/*'",
        'dependency-internal vendor tests such as',
        'indexer/vendor/wp-php-toolkit/full-text-search/tests/*',
    ] as $needle) {
        wp_fts_release_packaging_contract_contains(
            $needle,
            $docs,
            "release packaging docs should mention {$needle}"
        );
    }

    $vendorTestsPosition = strpos($docs, "-x 'indexer/vendor/wp-php-toolkit/full-text-search/tests/*'");
    $zipCommandPosition = strpos($docs, 'zip -r wp-fts-indexer.zip indexer');
    wp_fts_release_packaging_contract_true(
        is_int($zipCommandPosition) && is_int($vendorTestsPosition) && $zipCommandPosition < $vendorTestsPosition,
        'release ZIP command should explicitly exclude wp-php-toolkit/full-text-search tests'
    );
}

if (function_exists('test_case')) {
    test_case('quality release packaging excludes dependency-internal vendor tests', function (): void {
        wp_fts_release_packaging_contract_run();
    });
} elseif (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    wp_fts_release_packaging_contract_run();
    fwrite(STDOUT, "OK: release packaging contract excludes dependency-internal vendor tests.\n");
}
