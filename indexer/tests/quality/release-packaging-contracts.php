<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools/build-release-zip.php';
require_once __DIR__ . '/../../tools/check-release-readiness.php';

/**
 * The public preview is pinned to the v0.1.12 core asset and the digest in that
 * release's SHA256SUMS.txt. Update both only after its activation smoke passes.
 */
const WP_FTS_RELEASE_PACKAGE_URL = 'https://github.com/adamziel/wp-extensions/releases/download/language-fts-v0.1.12/language-fts-core.zip';
const WP_FTS_RELEASE_PACKAGE_SHA256 = '4a7baff284b74d7fc72d071f589730269748c66bb82f68b0ce426739f57bdc7f';

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

/** @return string[] */
function wp_fts_release_packaging_contract_shipped_tool_paths(): array
{
    return [
        'tools/import-lemma-tsv-pack.php',
        'tools/import-conllu-lemma-pack.php',
        'tools/import-unimorph-lemma-pack.php',
        'tools/import-polish-polimorf-lemmatizer.php',
        'tools/validate-analyzer-pack.php',
        'tools/audit-top-language-lemma-packs.php',
        'tools/build-lemma-pack-lookup-index.php',
        'tools/build-polish-polimorf-external-pack.php',
        'tools/lemma-source-import-limits.php',
        'tools/lemma-chunk-merge.php',
    ];
}

/** @return string[] */
function wp_fts_release_packaging_contract_private_array_constant(string $class, string $name): array
{
    $constant = (new ReflectionClass($class))->getReflectionConstant($name);
    if (!$constant instanceof ReflectionClassConstant) {
        throw new RuntimeException("Missing {$class}::{$name} package-policy constant.");
    }

    $value = $constant->getValue();
    if (!is_array($value) || array_filter($value, 'is_string') !== $value) {
        throw new RuntimeException("{$class}::{$name} must be a string array.");
    }

    return array_values($value);
}

/** @return string[] */
function wp_fts_release_packaging_contract_distignore_shipped_tool_paths(string $distignore): array
{
    $paths = [];
    foreach (explode("\n", $distignore) as $line) {
        $line = trim($line);
        if (!str_starts_with($line, '+ /tools/')) {
            continue;
        }

        $path = substr($line, 3);
        if ($path === 'tools/' || str_ends_with($path, '/')) {
            continue;
        }
        $paths[] = $path;
    }

    return $paths;
}

/** Lock the documented exclusion policy to the builder's public entry point. */
function wp_fts_release_packaging_contract_run(): void
{
    $root = dirname(__DIR__, 2);
    $docs = (string) file_get_contents($root . '/docs/release-packaging.md');
    $readme = (string) file_get_contents($root . '/README.md');
    $distignore = (string) file_get_contents($root . '/.distignore');

    $shippedTools = wp_fts_release_packaging_contract_shipped_tool_paths();
    wp_fts_release_packaging_contract_same(
        $shippedTools,
        wp_fts_release_packaging_contract_private_array_constant(WP_FTS_ReleasePackageBuilder::class, 'SHIPPED_TOOL_PATHS'),
        'release builder should require exactly the shipped importer and pack-management tool modules'
    );
    wp_fts_release_packaging_contract_same(
        $shippedTools,
        wp_fts_release_packaging_contract_private_array_constant(WP_FTS_ReleaseReadinessChecker::class, 'SHIPPED_TOOL_PATHS'),
        'release readiness should require exactly the shipped importer and pack-management tool modules'
    );
    wp_fts_release_packaging_contract_same(
        $shippedTools,
        wp_fts_release_packaging_contract_distignore_shipped_tool_paths($distignore),
        '.distignore should stage exactly the shipped importer and pack-management tool modules'
    );

    foreach ([
        '+ /tools/',
        '+ /tools/import-lemma-tsv-pack.php',
        '+ /tools/import-conllu-lemma-pack.php',
        '+ /tools/import-unimorph-lemma-pack.php',
        '+ /tools/import-polish-polimorf-lemmatizer.php',
        '+ /tools/validate-analyzer-pack.php',
        '+ /tools/audit-top-language-lemma-packs.php',
        '+ /tools/build-lemma-pack-lookup-index.php',
        '+ /tools/build-polish-polimorf-external-pack.php',
        '+ /tools/lemma-source-import-limits.php',
        '+ /tools/lemma-chunk-merge.php',
        '- /tools/**',
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
        'rejects every staged symbolic link',
        'fresh package-local Composer home and cache',
        '`--no-plugins --no-scripts`',
        'must remain outside both immutable source trees',
        'the ten `tools/` modules that back the shipped WP-CLI import commands',
        'all other `tools/` source-checkout build, test, release, corpus-generation,',
    ] as $needle) {
        wp_fts_release_packaging_contract_contains(
            $needle,
            $docs,
            "release packaging docs should mention {$needle}"
        );
    }

    foreach ([
        'Composer development scripts and every other `tools/` command',
        'require a complete source checkout',
    ] as $needle) {
        wp_fts_release_packaging_contract_contains(
            $needle,
            $readme,
            "release README should identify source-checkout-only tooling: {$needle}"
        );
    }

    $vendorTestsPosition = strpos($docs, 'indexer/vendor/wp-php-toolkit/full-text-search/tests/*');
    $builderPosition = strpos($docs, 'php indexer/tools/build-release-zip.php');
    wp_fts_release_packaging_contract_true(
        is_int($builderPosition) && is_int($vendorTestsPosition) && $builderPosition < $vendorTestsPosition,
        'release ZIP flow should run the builder before documenting vendor test exclusions'
    );
    wp_fts_release_packaging_contract_same(
        ['composer_cache_dir' => '/tmp/offline-composer-cache'],
        WP_FTS_ReleasePackageBuilder::parse_cli_options(['--composer-cache-dir=/tmp/offline-composer-cache']),
        'release builder should expose an explicit offline cache without reading ambient Composer cache state'
    );
}

/** Prove pruning removes development and credential material but keeps runtime code. */
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
        wp_fts_release_packaging_contract_write_fixture($stage . '/vendor/wp-php-toolkit/full-text-search/resources/sources/jieba/jieba/dict.txt');
        wp_fts_release_packaging_contract_write_fixture($stage . '/vendor/example/library/Tests/UnitTest.php');
        wp_fts_release_packaging_contract_write_fixture($stage . '/vendor/example/library/coverage/report.xml');
        wp_fts_release_packaging_contract_write_fixture($stage . '/vendor/bin/phpunit');
        foreach (wp_fts_release_packaging_contract_shipped_tool_paths() as $relativePath) {
            wp_fts_release_packaging_contract_write_fixture($stage . '/' . $relativePath);
        }
        wp_fts_release_packaging_contract_write_fixture($stage . '/tools/smoke-disposable-wordpress-release.php');
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
            in_array('indexer/tools/smoke-disposable-wordpress-release.php', $before, true),
            'release verifier should detect an unlisted source-checkout utility before pruning'
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
        wp_fts_release_packaging_contract_true(
            in_array('indexer/vendor/wp-php-toolkit/full-text-search/resources/sources', $before, true),
            'release verifier should detect raw component source checkouts before pruning'
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
            in_array('indexer/tools/smoke-disposable-wordpress-release.php', $removed, true),
            'release prune should report the removed source-checkout utility'
        );
        wp_fts_release_packaging_contract_true(
            in_array('indexer/vendor/wp-php-toolkit/full-text-search/resources/sources', $removed, true),
            'release prune should report the removed raw component source checkout'
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
            !file_exists($stage . '/tools/smoke-disposable-wordpress-release.php'),
            'release prune should remove unlisted source-checkout utilities'
        );
        foreach (wp_fts_release_packaging_contract_shipped_tool_paths() as $relativePath) {
            wp_fts_release_packaging_contract_true(
                is_file($stage . '/' . $relativePath),
                "release prune should preserve shipped tool module {$relativePath}"
            );
        }
        wp_fts_release_packaging_contract_true(
            !file_exists($stage . '/review-artifacts/evidence.json'),
            'release prune should remove review artifacts'
        );
        wp_fts_release_packaging_contract_true(
            !file_exists($stage . '/vendor/wp-php-toolkit/full-text-search/resources/sources'),
            'release prune should remove raw component source checkouts from vendor'
        );
    } finally {
        wp_fts_release_packaging_contract_remove_tree($tmp);
    }
}

/** Prove nested Composer cannot inherit credentials or shared mutable state. */
function wp_fts_release_packaging_contract_composer_env_run(): void
{
    $tmp = wp_fts_release_packaging_contract_temp_dir();
    try {
        wp_fts_release_packaging_contract_write_fixture(
            $tmp . '/incoming-composer-home/auth.json',
            '{"github-oauth":{"github.com":"must-not-be-read"}}'
        );
        wp_fts_release_packaging_contract_write_fixture($tmp . '/build/composer-home/auth.json', 'stale-local-auth');
        wp_fts_release_packaging_contract_write_fixture($tmp . '/build/composer-cache/stale.zip', 'stale-local-cache');
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
        wp_fts_release_packaging_contract_same($tmp . '/build/composer-home', $env['COMPOSER_HOME'] ?? null, 'nested Composer environment should replace ambient Composer home with a fresh package-local home');
        wp_fts_release_packaging_contract_same($tmp . '/build/composer-cache', $env['COMPOSER_CACHE_DIR'] ?? null, 'nested Composer environment should replace ambient Composer cache with a fresh package-local cache');
        wp_fts_release_packaging_contract_same('1', $env['COMPOSER_DISABLE_NETWORK'] ?? null, 'nested Composer environment should preserve explicit Composer network disablement');
        wp_fts_release_packaging_contract_same('/usr/bin:/bin', $env['PATH'] ?? null, 'nested Composer environment should preserve PATH so the composer binary can be resolved');
        wp_fts_release_packaging_contract_same('C', $env['LANG'] ?? null, 'nested Composer environment should use the deterministic C locale');
        wp_fts_release_packaging_contract_same('C', $env['LC_ALL'] ?? null, 'nested Composer environment should use the deterministic C locale for every category');
        wp_fts_release_packaging_contract_same('UTC', $env['TZ'] ?? null, 'nested Composer environment should use UTC');
        wp_fts_release_packaging_contract_true(!is_file($tmp . '/build/composer-home/auth.json'), 'a reused build directory must not retain package-local Composer auth');
        wp_fts_release_packaging_contract_true(!is_file($tmp . '/build/composer-cache/stale.zip'), 'a reused build directory must not retain its default mutable Composer cache');

        wp_fts_release_packaging_contract_write_fixture($tmp . '/explicit-offline-cache/archive.zip', 'offline-archive');
        $explicitCache = WP_FTS_ReleasePackageBuilder::composer_install_environment(
            ['PATH' => '/usr/bin:/bin'],
            $tmp . '/explicit-build',
            $tmp . '/explicit-offline-cache'
        );
        wp_fts_release_packaging_contract_same($tmp . '/explicit-build/composer-home', $explicitCache['COMPOSER_HOME'] ?? null, 'an explicit offline cache must not re-enable an ambient Composer home');
        wp_fts_release_packaging_contract_same($tmp . '/explicit-offline-cache', $explicitCache['COMPOSER_CACHE_DIR'] ?? null, 'an explicit caller-owned offline cache should remain available without ambient cache discovery');
        wp_fts_release_packaging_contract_true(is_file($tmp . '/explicit-offline-cache/archive.zip'), 'an explicit offline cache should be preserved for a source-archived historical dependency');

        $builder = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/build-release-zip.php');
        foreach (['\'--no-plugins\'', '\'--no-scripts\'', '\'--no-progress\'', '\'--prefer-dist\''] as $flag) {
            wp_fts_release_packaging_contract_contains($flag, $builder, "release Composer command should retain {$flag}");
        }
    } finally {
        wp_fts_release_packaging_contract_remove_tree($tmp);
    }
}

/** Ensure staging rejects links before an outside target can enter the ZIP. */
function wp_fts_release_packaging_contract_symlink_escape_run(): void
{
    $tmp = wp_fts_release_packaging_contract_temp_dir();
    $stage = $tmp . '/stage';
    $archive = $tmp . '/escaped.zip';
    try {
        wp_fts_release_packaging_contract_write_fixture($stage . '/indexer.php', "<?php\n");
        $secret = $tmp . '/outside-credential.txt';
        wp_fts_release_packaging_contract_write_fixture($secret, 'ambient-secret-must-not-package');
        if (!symlink($secret, $stage . '/innocent-runtime.php')) {
            throw new RuntimeException('Could not create the release symlink containment fixture.');
        }

        wp_fts_release_packaging_contract_same(
            ['indexer/innocent-runtime.php'],
            WP_FTS_ReleasePackageBuilder::find_symlink_paths($stage),
            'release boundary should enumerate a benign-named link before reading its target'
        );
        wp_fts_release_packaging_contract_true(
            in_array('indexer/innocent-runtime.php', WP_FTS_ReleasePackageBuilder::find_prohibited_package_paths($stage), true),
            'release boundary should classify every symbolic link as prohibited'
        );

        $error = null;
        try {
            WP_FTS_ReleasePackageBuilder::create_zip_from_stage($stage, $archive);
        } catch (RuntimeException $caught) {
            $error = $caught;
        }
        wp_fts_release_packaging_contract_true($error instanceof RuntimeException, 'ZIP creation must reject rather than follow an external-file symlink');
        wp_fts_release_packaging_contract_contains('Refusing to follow staged symlinks', $error?->getMessage() ?? '', 'symlink rejection should identify the package boundary');
        wp_fts_release_packaging_contract_true(!is_file($archive), 'a rejected external-file symlink must not leave a release archive');
    } finally {
        wp_fts_release_packaging_contract_remove_tree($tmp);
    }
}

/** Ensure repository pointers and local VCS configuration never cross release bounds. */
function wp_fts_release_packaging_contract_vcs_metadata_run(): void
{
    $tmp = wp_fts_release_packaging_contract_temp_dir();
    $stage = $tmp . '/stage';
    $archive = $tmp . '/vcs-metadata.zip';
    try {
        wp_fts_release_packaging_contract_write_fixture($stage . '/indexer.php', "<?php\n");
        wp_fts_release_packaging_contract_write_fixture(
            $stage . '/.git/config',
            "[remote \"origin\"]\n\turl = https://example.invalid/repository.git\n"
        );
        wp_fts_release_packaging_contract_write_fixture(
            $stage . '/vendor/example/library/.git',
            "gitdir: ../../outside-package\n"
        );

        $paths = WP_FTS_ReleasePackageBuilder::find_vcs_metadata_paths($stage);
        wp_fts_release_packaging_contract_true(in_array('indexer/.git', $paths, true), 'release boundary should detect a root Git metadata directory');
        wp_fts_release_packaging_contract_true(in_array('indexer/vendor/example/library/.git', $paths, true), 'release boundary should detect dependency Git pointer files');

        $error = null;
        try {
            WP_FTS_ReleasePackageBuilder::create_zip_from_stage($stage, $archive);
        } catch (RuntimeException $caught) {
            $error = $caught;
        }
        wp_fts_release_packaging_contract_true($error instanceof RuntimeException, 'ZIP creation must reject local VCS metadata before reading package files');
        wp_fts_release_packaging_contract_contains('VCS or credential metadata', $error?->getMessage() ?? '', 'VCS rejection should identify the protected package boundary');
        wp_fts_release_packaging_contract_true(!is_file($archive), 'rejected VCS metadata must not leave a release archive');

        $builder = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/build-release-zip.php');
        wp_fts_release_packaging_contract_contains("'--exclude=.git'", $builder, 'component staging should exclude the submodule Git pointer before Composer runs');
    } finally {
        wp_fts_release_packaging_contract_remove_tree($tmp);
    }
}

/** Reject a cache nested in staging before Composer can mutate package contents. */
function wp_fts_release_packaging_contract_composer_state_overlap_run(): void
{
    $tmp = wp_fts_release_packaging_contract_temp_dir();
    $archive = $tmp . '/overlap.zip';
    try {
        $error = null;
        try {
            (new WP_FTS_ReleasePackageBuilder())->build([
                'build_dir' => $tmp . '/build',
                'output' => $archive,
                'composer_cache_dir' => $tmp . '/build/indexer/innocent-cache',
            ]);
        } catch (RuntimeException $caught) {
            $error = $caught;
        }
        wp_fts_release_packaging_contract_true($error instanceof RuntimeException, 'a Composer cache inside staged source must fail before Composer executes');
        wp_fts_release_packaging_contract_contains('must not overlap package source, staging, or Composer home', (string) $error?->getMessage(), 'overlapping Composer state rejection should explain the package boundary');
        wp_fts_release_packaging_contract_true(!is_file($archive), 'overlapping Composer state must never produce a release archive');
    } finally {
        wp_fts_release_packaging_contract_remove_tree($tmp);
    }
}

/** Prove destructive build paths cannot alias immutable source or Composer state. */
function wp_fts_release_packaging_contract_destructive_path_overlap_run(): void
{
    $tmp = wp_fts_release_packaging_contract_temp_dir();
    $root = $tmp . '/repository';
    $pluginSource = $root . '/indexer';
    $componentSource = $root . '/components/full-text-search';
    $entrypoint = $pluginSource . '/indexer.php';
    try {
        wp_fts_release_packaging_contract_write_fixture($pluginSource . '/.distignore', "tests\n");
        wp_fts_release_packaging_contract_write_fixture($entrypoint, "<?php\n// Immutable source marker.\n");
        wp_fts_release_packaging_contract_write_fixture($componentSource . '/composer.json', "{}\n");
        $entrypointHash = hash_file('sha256', $entrypoint);

        $cases = [
            [
                'options' => ['build_dir' => $root, 'output' => $tmp . '/build-overlap.zip'],
                'message' => 'Release build directory must not overlap immutable package source.',
            ],
            [
                'options' => ['build_dir' => $tmp . '/safe-build', 'output' => $entrypoint],
                'message' => 'Release ZIP path must not overlap package source or mutable Composer state.',
            ],
            [
                'options' => [
                    'build_dir' => $tmp . '/safe-build',
                    'output' => $tmp . '/cache-overlap.zip',
                    'composer_cache_dir' => $pluginSource . '/composer-cache',
                ],
                'message' => 'Composer cache must not overlap package source, staging, or Composer home.',
            ],
        ];
        foreach ($cases as $case) {
            $error = null;
            try {
                (new WP_FTS_ReleasePackageBuilder())->build([
                    'plugin_src' => $pluginSource,
                    'monorepo_root' => $root,
                    ...$case['options'],
                ]);
            } catch (RuntimeException $caught) {
                $error = $caught;
            }
            wp_fts_release_packaging_contract_true($error instanceof RuntimeException, 'destructive release path overlap must fail before staging');
            wp_fts_release_packaging_contract_same($case['message'], $error?->getMessage(), 'release path rejection should identify the violated boundary');
            wp_fts_release_packaging_contract_same($entrypointHash, hash_file('sha256', $entrypoint), 'release path rejection must preserve immutable plugin source bytes');
        }
        wp_fts_release_packaging_contract_true(!is_file($root . '/.wp-fts-release-build.lock'), 'source-overlap rejection must happen before a build lock mutates the source tree');
        wp_fts_release_packaging_contract_true(!is_dir($pluginSource . '/composer-cache'), 'source-overlap rejection must not create Composer state in immutable source');
    } finally {
        wp_fts_release_packaging_contract_remove_tree($tmp);
    }
}

function wp_fts_release_packaging_contract_public_install_run(): void
{
    $pluginRoot = dirname(__DIR__, 2);
    $repoRoot = dirname($pluginRoot);
    $blueprint = wp_fts_release_packaging_contract_blueprint($pluginRoot . '/playground/blueprint.json');
    $steps = (array) ($blueprint['steps'] ?? []);

    wp_fts_release_packaging_contract_same(['writeFile', 'runPHP', 'installPlugin'], array_column($steps, 'step'), 'public Blueprint should verify the download before it installs and activates it');
    wp_fts_release_packaging_contract_same('/tmp/language-fts-core.zip', $steps[0]['path'] ?? null, 'public Blueprint should download to a stable VFS path');
    wp_fts_release_packaging_contract_same('url', $steps[0]['data']['resource'] ?? null, 'public Blueprint should download a release asset');
    wp_fts_release_packaging_contract_same(WP_FTS_RELEASE_PACKAGE_URL, $steps[0]['data']['url'] ?? null, 'public Blueprint should pin the published core release');
    wp_fts_release_packaging_contract_contains(WP_FTS_RELEASE_PACKAGE_SHA256, (string) ($steps[1]['code'] ?? ''), 'public Blueprint should verify the release digest before activation');
    wp_fts_release_packaging_contract_contains("hash_file('sha256'", (string) ($steps[1]['code'] ?? ''), 'public Blueprint should compute the downloaded release SHA-256');
    wp_fts_release_packaging_contract_contains('hash_equals(', (string) ($steps[1]['code'] ?? ''), 'public Blueprint should reject a different release digest');
    wp_fts_release_packaging_contract_same('vfs', $steps[2]['pluginData']['resource'] ?? null, 'public Blueprint should install the verified VFS artifact');
    wp_fts_release_packaging_contract_same('/tmp/language-fts-core.zip', $steps[2]['pluginData']['path'] ?? null, 'public Blueprint should install the file whose digest it verified');
    wp_fts_release_packaging_contract_same(true, $steps[2]['options']['activate'] ?? null, 'public Blueprint should activate the packaged plugin');
    wp_fts_release_packaging_contract_same('indexer', $steps[2]['options']['targetFolderName'] ?? null, 'public Blueprint should preserve the plugin slug');

    $smoke = wp_fts_release_packaging_contract_blueprint($pluginRoot . '/playground/sqlite-smoke-blueprint.json');
    $smokeInstall = $smoke['steps'][0] ?? [];
    wp_fts_release_packaging_contract_same('installPlugin', $smokeInstall['step'] ?? null, 'Playground smoke should install before exercising the plugin');
    wp_fts_release_packaging_contract_same('bundled', $smokeInstall['pluginData']['resource'] ?? null, 'Playground smoke should install a built artifact rather than mount source');
    wp_fts_release_packaging_contract_same('wp-fts-indexer.zip', $smokeInstall['pluginData']['path'] ?? null, 'Playground smoke should install the release builder output');
    wp_fts_release_packaging_contract_same(true, $smokeInstall['options']['activate'] ?? null, 'Playground smoke should exercise release artifact activation');

    $quickstart = wp_fts_release_packaging_contract_markdown_section((string) file_get_contents($pluginRoot . '/README.md'), 'Quickstart');
    wp_fts_release_packaging_contract_contains(WP_FTS_RELEASE_PACKAGE_URL, $quickstart, 'Quickstart should install the same release as Playground');
    wp_fts_release_packaging_contract_contains(WP_FTS_RELEASE_PACKAGE_SHA256, $quickstart, 'Quickstart should verify the same release digest as Playground');
    wp_fts_release_packaging_contract_contains('shasum -a 256 --check', $quickstart, 'Quickstart should stop when the release digest differs');
    wp_fts_release_packaging_contract_contains('--check \\' . "\n" . '  && wp plugin install', $quickstart, 'Quickstart should gate installation on the checksum result');
    wp_fts_release_packaging_contract_contains('wp plugin install', $quickstart, 'Quickstart should install the verified ZIP with WP-CLI');
    wp_fts_release_packaging_contract_true(!str_contains($quickstart, 'rsync '), 'Quickstart should not copy a dependency-incomplete source directory');
    wp_fts_release_packaging_contract_true(!str_contains($quickstart, 'composer install'), 'Quickstart should not run Composer without the path repository');
    wp_fts_release_packaging_contract_contains(WP_FTS_RELEASE_PACKAGE_URL, (string) file_get_contents($repoRoot . '/README.md'), 'repository overview should identify the Playground release artifact');
    wp_fts_release_packaging_contract_contains('self-contained Language FTS core ZIP', (string) file_get_contents($pluginRoot . '/readme.txt'), 'WordPress install instructions should require the packaged runtime');
    wp_fts_release_packaging_contract_true(!file_exists($pluginRoot . '/playground/indexer-preview.zip'), 'obsolete committed preview ZIP should not compete with the release artifact');
}

/**
 * Return one second-level Markdown section without parsing its contents.
 */
function wp_fts_release_packaging_contract_markdown_section(string $markdown, string $heading): string
{
    $section = [];
    $inside = false;
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown)) as $line) {
        if ($line === '## ' . $heading) {
            $inside = true;
            continue;
        }
        if ($inside && str_starts_with($line, '## ')) {
            break;
        }
        if ($inside) {
            $section[] = $line;
        }
    }

    return implode("\n", $section);
}

/**
 * @return array<string,mixed>
 */
function wp_fts_release_packaging_contract_blueprint(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException("Blueprint is not a JSON object: {$path}");
    }

    return $decoded;
}

/** Install the ZIP alone and prove runtime code/data needs no monorepo neighbor. */
function wp_fts_release_packaging_contract_standalone_bootstrap_run(): void
{
    $tmp = wp_fts_release_packaging_contract_temp_dir();
    $zip = null;
    try {
        $result = (new WP_FTS_ReleasePackageBuilder())->build([
            'build_dir' => $tmp . '/build',
            'output' => $tmp . '/wp-fts-indexer.zip',
        ]);
        $zip = new ZipArchive();
        wp_fts_release_packaging_contract_same(true, $zip->open((string) ($result['zip_path'] ?? '')), 'release ZIP should open successfully');
        wp_fts_release_packaging_contract_true($zip->locateName('indexer/indexer.php') !== false, 'release ZIP should contain the plugin entrypoint');
        wp_fts_release_packaging_contract_true($zip->locateName('indexer/src/WPCLICommand.php') !== false, 'release ZIP should contain the runtime WP-CLI command');
        wp_fts_release_packaging_contract_true($zip->locateName('indexer/vendor/autoload.php') !== false, 'release ZIP should contain the Composer autoloader');
        wp_fts_release_packaging_contract_true($zip->locateName('indexer/vendor/wp-php-toolkit/full-text-search/src/bootstrap.php') !== false, 'release ZIP should contain the FTS component runtime');
        wp_fts_release_packaging_contract_true($zip->locateName('indexer/vendor/wp-php-toolkit/full-text-search/src/InMemoryStorage.php') === false, 'release ZIP should not contain an in-memory application backend');
        wp_fts_release_packaging_contract_true($zip->locateName('indexer/vendor/wp-php-toolkit/full-text-search/src/FileStorage.php') === false, 'release ZIP should not contain a file application backend');
        wp_fts_release_packaging_contract_true($zip->locateName('indexer/vendor/wp-php-toolkit/full-text-search/tests/fixtures/InMemoryStorage.php') === false, 'release ZIP should prune the test-only in-memory oracle');
        $jiebaRuntime = 'indexer/vendor/wp-php-toolkit/full-text-search/resources/runtime/jieba/';
        $expectedJiebaRuntime = [
            $jiebaRuntime . 'LICENSE' => ['bytes' => 1075, 'sha256' => '18ba0984839f85853b29fadaf992f7dba8fd0ca0fbeae34de2b8735222dc7a37'],
            $jiebaRuntime . 'dict.idx' => ['bytes' => 329972, 'sha256' => '4c979fd244e59b8343c2e584dbd5ba062deb1f836b8ae9ca2b56b54f130b9046'],
            $jiebaRuntime . 'dict.txt' => ['bytes' => 5071852, 'sha256' => '7197c3211ddd98962b036cdf40324d1ea2bfaa12bd028e68faa70111a88e12a8'],
        ];
        $actualJiebaRuntime = [];
        $rawJiebaSourceEntries = [];
        $toolEntries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (!is_string($name)) {
                continue;
            }
            if ($name === 'indexer/tools' || str_starts_with($name, 'indexer/tools/')) {
                $toolEntries[] = $name;
            }
            if (str_ends_with($name, '/')) {
                continue;
            }
            if (str_contains($name, '/resources/sources/jieba/')) {
                $rawJiebaSourceEntries[] = $name;
            }
            if (!str_starts_with($name, $jiebaRuntime)) {
                continue;
            }
            $contents = $zip->getFromIndex($index);
            if (!is_string($contents)) {
                throw new RuntimeException("Could not read packaged Jieba runtime entry {$name}.");
            }
            $actualJiebaRuntime[$name] = ['bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)];
        }
        ksort($actualJiebaRuntime, SORT_STRING);
        $expectedToolEntries = array_map(
            static fn(string $path): string => 'indexer/' . $path,
            wp_fts_release_packaging_contract_shipped_tool_paths()
        );
        sort($expectedToolEntries, SORT_STRING);
        sort($toolEntries, SORT_STRING);
        wp_fts_release_packaging_contract_same($expectedJiebaRuntime, $actualJiebaRuntime, 'release ZIP should contain exactly the attested Jieba dictionary, lookup index, and MIT license');
        wp_fts_release_packaging_contract_same([], $rawJiebaSourceEntries, 'release ZIP should contain no raw Jieba source checkout files');
        wp_fts_release_packaging_contract_same($expectedToolEntries, $toolEntries, 'release ZIP should contain exactly the ten shipped importer and pack-management tool modules');

        $plugins = $tmp . '/wp-content/plugins';
        wp_fts_release_packaging_contract_true(mkdir($plugins, 0777, true), 'standalone plugins directory should be created');
        wp_fts_release_packaging_contract_true($zip->extractTo($plugins), 'release ZIP should extract into a standalone plugins directory');
        $zip->close();
        $zip = null;
        wp_fts_release_packaging_contract_true(!is_file($tmp . '/wp-content/plugins/components/full-text-search/src/bootstrap.php'), 'standalone proof should not provide the adjacent monorepo component');

        $code = <<<'PHP'
$pluginRoot = $argv[1];
define('ABSPATH', $pluginRoot . '/');
define('WP_CLI', true);
final class WP_CLI {
    public static array $commands = [];
    public static function add_command(string $name, string $command): void {
        self::$commands[$name] = $command;
    }
    public static function success(string $message): void {
        fwrite(STDOUT, "WP_CLI_SUCCESS: {$message}\n");
    }
}
require $pluginRoot . '/indexer.php';
if (
    ! is_file($pluginRoot . '/vendor/autoload.php')
    || ! class_exists('WP_FTS_Analyzer', false)
    || ! class_exists('WP_FTS_Plugin', false)
    || ! class_exists('WP_FTS_WPCLI_Command', false)
    || class_exists('WP_FTS_Storage_InMemory', false)
    || class_exists('WP_FTS_Storage_File', false)
    || (WP_CLI::$commands['fts'] ?? null) !== WP_FTS_WPCLI_Command::class
) {
    exit(1);
}
$expectedSource = realpath($pluginRoot . '/vendor/wp-php-toolkit/full-text-search/resources/runtime/jieba/dict.txt');
$segmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
$lookup = WP_FTS_ChineseJiebaSegmenter::default_lookup_evidence();
$segmented = $segmenter instanceof WP_FTS_ChineseJiebaSegmenter
    ? $segmenter('中华人民共和国', 'zh')
    : [];
if (
    !is_string($expectedSource)
    || realpath(WP_FTS_ChineseJiebaSegmenter::default_source_file()) !== $expectedSource
    || !$segmenter instanceof WP_FTS_ChineseJiebaSegmenter
    || ($lookup['available'] ?? false) !== true
    || (new ReflectionProperty($segmenter, 'sourceHashScanCount'))->getValue($segmenter) !== 0
    || !in_array('中华人民共和国', $segmented, true)
) {
    exit(2);
}
$source = $argv[2];
$output = $argv[3];
(new WP_FTS_WPCLI_Command())->import_lemma_pack([], [
    'source' => $source,
    'language' => 'qaa',
    'pack-id' => 'packaged-command-smoke',
    'version' => '1.0.0',
    'source-name' => 'Packaged command smoke fixture',
    'source-url' => 'urn:wp-fts:test:packaged-command-smoke',
    'license' => 'CC0-1.0',
    'attribution' => 'Project-owned packaged command smoke row.',
    'fixture-only' => true,
    'runtime-compression' => 'none',
    'max-rows-per-file' => 2,
    'chunk-rows' => 2,
    'out' => $output,
]);
if (!is_file($output . '/manifest.json')) {
    exit(3);
}
fwrite(STDOUT, "SELF_CONTAINED_RELEASE_OK\n");
fwrite(STDOUT, "PACKAGED_DEFAULT_JIEBA_OK\n");
fwrite(STDOUT, "PACKAGED_LEMMA_IMPORT_OK\n");
PHP;
        $lemmaSource = $tmp . '/lemma-source.tsv';
        $lemmaOutput = $tmp . '/lemma-pack';
        wp_fts_release_packaging_contract_write_fixture($lemmaSource, "qaaform\tqaalemma\n");
        $process = proc_open(
            [PHP_BINARY, '-r', $code, $plugins . '/indexer', $lemmaSource, $lemmaOutput],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $plugins
        );
        wp_fts_release_packaging_contract_true(is_resource($process), 'standalone proof should launch a fresh PHP process');
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        wp_fts_release_packaging_contract_same(0, proc_close($process), 'packaged plugin should bootstrap without the monorepo component: ' . trim($stderr));
        wp_fts_release_packaging_contract_contains('SELF_CONTAINED_RELEASE_OK', $stdout, 'fresh PHP should load runtime classes from packaged vendor files');
        wp_fts_release_packaging_contract_contains('PACKAGED_DEFAULT_JIEBA_OK', $stdout, 'fresh packaged true/default construction should use the curated dictionary and indexed lookup without a full source hash');
        wp_fts_release_packaging_contract_contains('PACKAGED_LEMMA_IMPORT_OK', $stdout, 'fresh packaged WP-CLI command should execute the shipped lemma importer and write a manifest');
    } finally {
        if ($zip instanceof ZipArchive) {
            $zip->close();
        }
        wp_fts_release_packaging_contract_remove_tree($tmp);
    }
}

/** Hold ZIP bytes stable across mtimes, permissions, sequential runs, and races. */
function wp_fts_release_packaging_contract_deterministic_zip_bytes_run(): void
{
    $tmp = wp_fts_release_packaging_contract_temp_dir();
    $stage = $tmp . '/stage';
    try {
        wp_fts_release_packaging_contract_write_fixture($stage . '/indexer.php', "<?php\n// Stable release fixture.\n");
        wp_fts_release_packaging_contract_write_fixture($stage . '/bin/worker.sh', "#!/bin/sh\nprintf 'stable\\n'\n");
        wp_fts_release_packaging_contract_write_fixture($stage . '/resources/runtime.bin', "\x00\x01\x02stable\xff");
        touch($stage . '/indexer.php', 1700000000);
        touch($stage . '/bin/worker.sh', 1100000000);
        chmod($stage . '/bin/worker.sh', 0755);

        $archives = [
            $tmp . '/sequential-a.zip',
            $tmp . '/sequential-b.zip',
            $tmp . '/concurrent-a.zip',
            $tmp . '/concurrent-b.zip',
        ];
        WP_FTS_ReleasePackageBuilder::create_zip_from_stage($stage, $archives[0]);

        // Source mtimes and ambient umask are not release semantics. Changing
        // only those inputs must not alter the normalized archive bytes.
        touch($stage . '/indexer.php', 1200000000);
        touch($stage . '/bin/worker.sh', 1800000000);
        chmod($stage . '/indexer.php', 0600);
        WP_FTS_ReleasePackageBuilder::create_zip_from_stage($stage, $archives[1]);

        $code = <<<'PHP'
require $argv[1];
WP_FTS_ReleasePackageBuilder::create_zip_from_stage($argv[2], $argv[3]);
PHP;
        $processes = [];
        foreach (array_slice($archives, 2) as $archive) {
            $process = proc_open(
                [PHP_BINARY, '-r', $code, dirname(__DIR__, 2) . '/tools/build-release-zip.php', $stage, $archive],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                dirname(__DIR__, 3)
            );
            wp_fts_release_packaging_contract_true(is_resource($process), 'concurrent deterministic ZIP proof should launch a fresh PHP process');
            fclose($pipes[0]);
            $processes[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
        }
        foreach ($processes as $index => $running) {
            $stdout = (string) stream_get_contents($running['stdout']);
            $stderr = (string) stream_get_contents($running['stderr']);
            fclose($running['stdout']);
            fclose($running['stderr']);
            wp_fts_release_packaging_contract_same(
                0,
                proc_close($running['process']),
                "concurrent deterministic ZIP process {$index} should succeed: " . trim($stdout . "\n" . $stderr)
            );
        }

        $digests = array_map('hash_file', array_fill(0, count($archives), 'sha256'), $archives);
        wp_fts_release_packaging_contract_true(!in_array(false, $digests, true), 'every deterministic ZIP fixture should be hashable');
        wp_fts_release_packaging_contract_same(
            [$digests[0]],
            array_values(array_unique($digests)),
            'sequential and concurrent builds of identical content should be byte-for-byte stable'
        );

        $zip = new ZipArchive();
        wp_fts_release_packaging_contract_same(true, $zip->open($archives[0]), 'deterministic fixture ZIP should open');
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (is_string($name)) {
                $names[] = $name;
            }
        }
        wp_fts_release_packaging_contract_same([
            'indexer/bin/worker.sh',
            'indexer/indexer.php',
            'indexer/resources/runtime.bin',
        ], $names, 'deterministic ZIP entries should retain bytewise path order');
        foreach ($names as $name) {
            $stat = $zip->statName($name);
            wp_fts_release_packaging_contract_same(946684800, $stat['mtime'] ?? null, "{$name} should use the fixed release timestamp");
            $opsys = 0;
            $attributes = 0;
            wp_fts_release_packaging_contract_true(
                $zip->getExternalAttributesName($name, $opsys, $attributes),
                "{$name} should expose normalized external attributes"
            );
            wp_fts_release_packaging_contract_same(ZipArchive::OPSYS_UNIX, $opsys, "{$name} should use Unix archive attributes");
            $expectedMode = str_ends_with($name, '.sh') ? 0100755 : 0100644;
            wp_fts_release_packaging_contract_same($expectedMode, $attributes >> 16, "{$name} should use the declared release mode");
        }
        wp_fts_release_packaging_contract_same(
            "\x00\x01\x02stable\xff",
            $zip->getFromName('indexer/resources/runtime.bin'),
            'metadata normalization must preserve file content bytes'
        );
        $zip->close();
    } finally {
        wp_fts_release_packaging_contract_remove_tree($tmp);
    }
}

/** Compare independent complete builds, not merely archives from one shared stage. */
function wp_fts_release_packaging_contract_full_build_reproducibility_run(): void
{
    $tmp = wp_fts_release_packaging_contract_temp_dir();
    try {
        $builds = [$tmp . '/build-a', $tmp . '/build-b'];
        $archives = [$tmp . '/release-a.zip', $tmp . '/release-b.zip'];
        $results = [];
        foreach ($builds as $index => $build) {
            $results[] = (new WP_FTS_ReleasePackageBuilder())->build([
                'build_dir' => $build,
                'output' => $archives[$index],
            ]);
        }

        $digests = array_map('hash_file', ['sha256', 'sha256'], $archives);
        wp_fts_release_packaging_contract_true(!in_array(false, $digests, true), 'both complete release archives should be hashable');
        wp_fts_release_packaging_contract_same($digests[0], $digests[1], 'two independent complete source builds should be byte-for-byte identical');

        $manifest = static function (string $archive): array {
            $zip = new ZipArchive();
            wp_fts_release_packaging_contract_same(true, $zip->open($archive), 'complete release reproducibility archive should open');
            $entries = [];
            try {
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $name = $zip->getNameIndex($index);
                    $stat = $zip->statIndex($index);
                    if (!is_string($name) || !is_array($stat)) {
                        throw new RuntimeException('Complete release reproducibility archive has a malformed entry.');
                    }
                    $contents = $zip->getFromIndex($index);
                    if (!is_string($contents)) {
                        throw new RuntimeException("Could not read complete release entry {$name}.");
                    }
                    $entries[] = [
                        'name' => $name,
                        'bytes' => strlen($contents),
                        'sha256' => hash('sha256', $contents),
                        'mtime' => $stat['mtime'] ?? null,
                    ];
                }
            } finally {
                $zip->close();
            }

            return $entries;
        };
        $firstManifest = $manifest($archives[0]);
        $secondManifest = $manifest($archives[1]);
        wp_fts_release_packaging_contract_true($firstManifest !== [], 'complete release should contain a non-empty entry manifest');
        wp_fts_release_packaging_contract_same($firstManifest, $secondManifest, 'independent complete builds should have identical name/content/metadata manifests');

        foreach ($results as $index => $result) {
            wp_fts_release_packaging_contract_same($builds[$index] . '/composer-home', $result['composer_home'] ?? null, "complete build {$index} should use its own Composer home");
            wp_fts_release_packaging_contract_same($builds[$index] . '/composer-cache', $result['composer_cache_dir'] ?? null, "complete build {$index} should use its own fresh Composer cache");
            wp_fts_release_packaging_contract_same(false, $result['composer_plugins'] ?? null, "complete build {$index} should disable Composer plugins");
            wp_fts_release_packaging_contract_same(false, $result['composer_scripts'] ?? null, "complete build {$index} should disable Composer scripts");
        }
        wp_fts_release_packaging_contract_true(
            ($results[0]['composer_home'] ?? null) !== ($results[1]['composer_home'] ?? null)
                && ($results[0]['composer_cache_dir'] ?? null) !== ($results[1]['composer_cache_dir'] ?? null),
            'complete builds must not share mutable Composer state'
        );
    } finally {
        wp_fts_release_packaging_contract_remove_tree($tmp);
    }
}

/** Prove a reused vendor tree is replaced from current component source bytes. */
function wp_fts_release_packaging_contract_stale_vendor_run(): void
{
    $tmp = wp_fts_release_packaging_contract_temp_dir();
    $zip = null;
    try {
        $plugin = $tmp . '/indexer';
        $component = $tmp . '/components/full-text-search';
        $staleRuntime = "<?php\nconst WP_FTS_RELEASE_FIXTURE = 'stale';\n";
        $freshRuntime = "<?php\nconst WP_FTS_RELEASE_FIXTURE = 'fresh';\n";
        wp_fts_release_packaging_contract_write_fixture($plugin . '/indexer.php', "<?php\n");
        wp_fts_release_packaging_contract_write_fixture($plugin . '/.distignore', "\n");
        foreach (wp_fts_release_packaging_contract_shipped_tool_paths() as $relativePath) {
            wp_fts_release_packaging_contract_write_fixture($plugin . '/' . $relativePath, "<?php\n");
        }
        wp_fts_release_packaging_contract_write_fixture(
            $plugin . '/composer.json',
            json_encode([
                'name' => 'local/release-stale-vendor-fixture',
                'require' => ['wp-php-toolkit/full-text-search' => '0.1.0'],
                'repositories' => [[
                    'type' => 'path',
                    'url' => '../components/full-text-search',
                    'options' => ['symlink' => false],
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
        wp_fts_release_packaging_contract_write_fixture(
            $component . '/composer.json',
            json_encode([
                'name' => 'wp-php-toolkit/full-text-search',
                'version' => '0.1.0',
                'require' => ['php' => '>=8.1'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
        wp_fts_release_packaging_contract_write_fixture($component . '/src/Runtime.php', $staleRuntime);
        $repositoryComponent = dirname(__DIR__, 3) . '/components/full-text-search';
        wp_fts_release_packaging_contract_true(
            mkdir($component . '/resources/sources/jieba/jieba', 0777, true),
            'stale-vendor fixture should create the exact current Jieba source layout'
        );
        wp_fts_release_packaging_contract_true(
            mkdir($component . '/resources/runtime/jieba', 0777, true),
            'stale-vendor fixture should create the exact current Jieba runtime layout'
        );
        foreach ([
            $repositoryComponent . '/resources/sources/jieba/jieba/dict.txt' => $component . '/resources/sources/jieba/jieba/dict.txt',
            $repositoryComponent . '/resources/sources/jieba/LICENSE' => $component . '/resources/sources/jieba/LICENSE',
            $repositoryComponent . '/resources/runtime/jieba/dict.idx' => $component . '/resources/runtime/jieba/dict.idx',
        ] as $source => $destination) {
            wp_fts_release_packaging_contract_true(
                is_file($source) && copy($source, $destination),
                "stale-vendor fixture should copy the exact pinned runtime source {$source}"
            );
        }

        $composerHome = $tmp . '/fixture-composer-home';
        $composerCache = $tmp . '/fixture-composer-cache';
        wp_fts_release_packaging_contract_true(mkdir($composerHome, 0777, true), 'stale-vendor fixture Composer home should be created');
        wp_fts_release_packaging_contract_true(mkdir($composerCache, 0777, true), 'stale-vendor fixture Composer cache should be created');
        $process = proc_open(
            [
                'composer',
                'install',
                '--no-dev',
                '--no-interaction',
                '--no-plugins',
                '--no-scripts',
                '--no-progress',
                "--working-dir={$plugin}",
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $tmp,
            [
                'COMPOSER_HOME' => $composerHome,
                'COMPOSER_CACHE_DIR' => $composerCache,
                'PATH' => (string) getenv('PATH'),
                'HOME' => $tmp,
            ]
        );
        wp_fts_release_packaging_contract_true(is_resource($process), 'stale-vendor fixture Composer install should launch');
        fclose($pipes[0]);
        $composerStdout = (string) stream_get_contents($pipes[1]);
        $composerStderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        wp_fts_release_packaging_contract_same(
            0,
            proc_close($process),
            'stale-vendor fixture Composer install should succeed: ' . trim($composerStdout . "\n" . $composerStderr)
        );
        wp_fts_release_packaging_contract_same(
            $staleRuntime,
            file_get_contents($plugin . '/vendor/wp-php-toolkit/full-text-search/src/Runtime.php'),
            'fixture must begin with an installed same-version stale runtime'
        );

        // Keep the package definition and lock reference unchanged while the
        // path dependency source advances. This is the exact state in which
        // Composer otherwise considers the copied installed package satisfied.
        wp_fts_release_packaging_contract_write_fixture($component . '/src/Runtime.php', $freshRuntime);
        $result = (new WP_FTS_ReleasePackageBuilder())->build([
            'plugin_src' => $plugin,
            'monorepo_root' => $tmp,
            'build_dir' => $tmp . '/build',
            'output' => $tmp . '/release.zip',
        ]);
        $zip = new ZipArchive();
        wp_fts_release_packaging_contract_same(true, $zip->open((string) $result['zip_path']), 'fresh-vendor fixture ZIP should open');
        wp_fts_release_packaging_contract_same(
            $freshRuntime,
            $zip->getFromName('indexer/vendor/wp-php-toolkit/full-text-search/src/Runtime.php'),
            'release build must replace a copied same-version vendor runtime with the current staged component'
        );
        $zip->close();
        $zip = null;

        $mismatchPlugin = $tmp . '/mismatch/indexer';
        wp_fts_release_packaging_contract_write_fixture(
            $mismatchPlugin . '/vendor/wp-php-toolkit/full-text-search/src/Runtime.php',
            $staleRuntime
        );
        $method = new ReflectionMethod(WP_FTS_ReleasePackageBuilder::class, 'assert_component_runtime_matches_source');
        $method->setAccessible(true);
        $mismatch = null;
        try {
            $method->invoke(null, $component, $mismatchPlugin);
        } catch (RuntimeException $error) {
            $mismatch = $error;
        }
        wp_fts_release_packaging_contract_true(
            $mismatch instanceof RuntimeException,
            'release builder must independently reject an installed component runtime hash mismatch'
        );
        wp_fts_release_packaging_contract_contains(
            'Runtime.php',
            (string) $mismatch?->getMessage(),
            'component runtime mismatch should identify the divergent path'
        );
    } finally {
        if ($zip instanceof ZipArchive) {
            $zip->close();
        }
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
    test_case('quality release packaging never follows staged symbolic links', function (): void {
        wp_fts_release_packaging_contract_symlink_escape_run();
    });
    test_case('quality release packaging rejects local VCS and credential metadata', function (): void {
        wp_fts_release_packaging_contract_vcs_metadata_run();
    });
    test_case('quality release packaging rejects Composer state inside staged source', function (): void {
        wp_fts_release_packaging_contract_composer_state_overlap_run();
    });
    test_case('quality release packaging rejects destructive source and output path overlap', function (): void {
        wp_fts_release_packaging_contract_destructive_path_overlap_run();
    });
    test_case('quality public install paths use a digest-pinned release artifact', function (): void {
        wp_fts_release_packaging_contract_public_install_run();
    });
    // This case builds and extracts a ZIP in-process. The normal PHP and
    // release-artifact lanes provide ZipArchive; php -n covers the remaining
    // packaging contracts without weakening the strict pending-test gate.
    if (class_exists('ZipArchive')) {
        test_case('quality release ZIP bytes are stable across sequential and concurrent creation', function (): void {
            wp_fts_release_packaging_contract_deterministic_zip_bytes_run();
        });
        test_case('quality complete release builds are reproducible with independent Composer state', function (): void {
            wp_fts_release_packaging_contract_full_build_reproducibility_run();
        });
        test_case('quality release build replaces and verifies a stale same-version vendor runtime', function (): void {
            wp_fts_release_packaging_contract_stale_vendor_run();
        });
        test_case('quality release artifact bootstraps outside the monorepo', function (): void {
            wp_fts_release_packaging_contract_standalone_bootstrap_run();
        });
    }
} elseif (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    wp_fts_release_packaging_contract_run();
    wp_fts_release_packaging_contract_prune_run();
    wp_fts_release_packaging_contract_composer_env_run();
    wp_fts_release_packaging_contract_symlink_escape_run();
    wp_fts_release_packaging_contract_vcs_metadata_run();
    wp_fts_release_packaging_contract_composer_state_overlap_run();
    wp_fts_release_packaging_contract_destructive_path_overlap_run();
    wp_fts_release_packaging_contract_public_install_run();
    wp_fts_release_packaging_contract_deterministic_zip_bytes_run();
    wp_fts_release_packaging_contract_full_build_reproducibility_run();
    wp_fts_release_packaging_contract_stale_vendor_run();
    wp_fts_release_packaging_contract_standalone_bootstrap_run();
    fwrite(STDOUT, "OK: release packaging and self-contained install contracts passed.\n");
}
