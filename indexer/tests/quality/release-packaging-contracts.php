<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools/build-release-zip.php';
require_once __DIR__ . '/../../tools/build-language-pack-bundle.php';
require_once __DIR__ . '/../../tools/build-language-pack-release-manifest.php';

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

/**
 * @param list<array<string,mixed>> $rows
 * @return array<string,mixed>
 */
function wp_fts_release_packaging_contract_row_by_prefix(array $rows, string $prefix): array
{
    foreach ($rows as $row) {
        if (str_starts_with((string) $row['pack_id'], $prefix)) {
            return $row;
        }
    }

    throw new RuntimeException("Could not find analyzer pack row with prefix {$prefix}");
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
        'tools',
        'tools/**',
        'playground',
        'playground/**',
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
        'language-fts/vendor/wamania/php-stemmer/.gitignore',
        'vendor/wp-php-toolkit/full-text-search/tests/',
        'vendor/bin',
        '`test`, `tests`, `Tests`, and `coverage`',
        'prohibited dotfiles',
        'installable release ZIP',
        'WordPress.org or SVN submission',
        'dependency-internal vendor tests such as',
        'language-fts/vendor/wp-php-toolkit/full-text-search/tests/*',
        'Composer auth files such as',
        'indexer/auth.json',
        'indexer/.composer/auth.json',
        '`language-fts/` package root',
        'Development tools and Playground harnesses',
        'language-fts-core.zip',
        'language-fts-full.zip',
        'language-fts-extended-language-packs.zip',
        'language-fts-extended-language-packs.manifest.json',
        'language-fts-extended-language-packs.manifest.json.sig',
        'language-fts-release-evidence.json',
        'CC BY-SA UniMorph runtime packs',
        'upstream-license-not-declared',
        'BSD-2-Clause Polish PoliMorf runtime pack',
        'php indexer/tools/build-language-pack-bundle.php',
        'php indexer/tools/build-language-pack-release-manifest.php',
        'LANGUAGE_FTS_LANGUAGE_PACK_MANIFEST_SIGNING_KEY',
        'Ed25519',
        'SHA-256 hash before extraction',
        '--profile=github-full',
        'not a WordPress.org submission',
    ] as $needle) {
        wp_fts_release_packaging_contract_contains(
            $needle,
            $docs,
            "release packaging docs should mention {$needle}"
        );
    }

    $vendorTestsPosition = strpos($docs, 'language-fts/vendor/wp-php-toolkit/full-text-search/tests/*');
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
        wp_fts_release_packaging_contract_write_fixture($stage . '/tools/build-release-zip.php');
        wp_fts_release_packaging_contract_write_fixture($stage . '/auth.json', '{"github-oauth":{"example.test":"dummy"}}');
        wp_fts_release_packaging_contract_write_fixture($stage . '/.composer/auth.json', '{"http-basic":{"example.test":{"username":"dummy","password":"dummy"}}}');
        wp_fts_release_packaging_contract_write_fixture($stage . '/.gitignore');
        wp_fts_release_packaging_contract_write_fixture($stage . '/.distignore');

        $before = WP_FTS_ReleasePackageBuilder::find_prohibited_package_paths($stage);
        wp_fts_release_packaging_contract_true(
            in_array('language-fts/auth.json', $before, true),
            'release verifier should detect root Composer auth files before pruning'
        );
        wp_fts_release_packaging_contract_true(
            in_array('language-fts/.composer', $before, true),
            'release verifier should detect Composer auth home directories before pruning'
        );
        wp_fts_release_packaging_contract_true(
            in_array('language-fts/vendor/wamania/php-stemmer/.gitignore', $before, true),
            'release verifier should detect nested Composer dependency .gitignore before pruning'
        );
        wp_fts_release_packaging_contract_true(
            in_array('language-fts/vendor/wamania/php-stemmer/.distignore', $before, true),
            'release verifier should detect nested Composer dependency .distignore before pruning'
        );
        wp_fts_release_packaging_contract_true(
            in_array('language-fts/vendor/wp-php-toolkit/full-text-search/tests', $before, true),
            'release verifier should detect dependency-internal tests before pruning'
        );

        $removed = WP_FTS_ReleasePackageBuilder::prune_staged_package($stage);
        $after = WP_FTS_ReleasePackageBuilder::find_prohibited_package_paths($stage);
        wp_fts_release_packaging_contract_same([], $after, 'release prune should remove all prohibited staged package paths');
        wp_fts_release_packaging_contract_true(
            in_array('language-fts/vendor/wamania/php-stemmer/.gitignore', $removed, true),
            'release prune should report removed nested dependency .gitignore'
        );
        wp_fts_release_packaging_contract_true(
            in_array('language-fts/vendor/wamania/php-stemmer/.distignore', $removed, true),
            'release prune should report removed nested dependency .distignore'
        );
        wp_fts_release_packaging_contract_true(
            in_array('language-fts/auth.json', $removed, true),
            'release prune should report removed root Composer auth files'
        );
        wp_fts_release_packaging_contract_true(
            in_array('language-fts/.composer', $removed, true),
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
        wp_fts_release_packaging_contract_true(
            !file_exists($stage . '/tools/build-release-zip.php'),
            'release prune should remove build tools'
        );
        wp_fts_release_packaging_contract_true(
            !file_exists($stage . '/playground/indexer-preview.zip'),
            'release prune should remove Playground files'
        );
    } finally {
        wp_fts_release_packaging_contract_remove_tree($tmp);
    }
}

function wp_fts_release_packaging_contract_release_channel_policy_run(): void
{
    $root = dirname(__DIR__, 2);
    $rows = WP_FTS_ReleaseChannelPolicy::analyzer_pack_rows($root);
    wp_fts_release_packaging_contract_true($rows !== [], 'release channel policy should find committed analyzer-pack manifests');

    $english = wp_fts_release_packaging_contract_row_by_prefix($rows, 'en-unimorph-');
    wp_fts_release_packaging_contract_same('CC-BY-SA-3.0', $english['license_spdx'], 'English UniMorph pack should remain classified as CC BY-SA');
    wp_fts_release_packaging_contract_true(
        !WP_FTS_ReleaseChannelPolicy::profile_allows_row(WP_FTS_ReleaseChannelPolicy::PROFILE_CORE, $english),
        'core release profile should exclude CC BY-SA UniMorph packs'
    );
    wp_fts_release_packaging_contract_true(
        !WP_FTS_ReleaseChannelPolicy::profile_allows_row(WP_FTS_ReleaseChannelPolicy::PROFILE_WPORG_COMPATIBLE, $english),
        'WP.org-compatible release profile should exclude CC BY-SA UniMorph packs'
    );
    wp_fts_release_packaging_contract_true(
        WP_FTS_ReleaseChannelPolicy::profile_allows_row(WP_FTS_ReleaseChannelPolicy::PROFILE_GITHUB_FULL, $english),
        'GitHub full release profile should include CC BY-SA UniMorph packs'
    );
    wp_fts_release_packaging_contract_true(
        WP_FTS_ReleaseChannelPolicy::profile_allows_row(WP_FTS_ReleaseChannelPolicy::PROFILE_EXTENDED_LANGUAGE_PACKS, $english),
        'extended language-pack bundle should include CC BY-SA UniMorph packs'
    );

    $polish = wp_fts_release_packaging_contract_row_by_prefix($rows, 'pl-polimorf-');
    wp_fts_release_packaging_contract_same('BSD-2-Clause', $polish['license_spdx'], 'Polish PoliMorf runtime pack should remain BSD-2-Clause');
    wp_fts_release_packaging_contract_true(
        !WP_FTS_ReleaseChannelPolicy::profile_allows_row(WP_FTS_ReleaseChannelPolicy::PROFILE_CORE, $polish),
        'core release profile should exclude bundled analyzer-pack runtime data'
    );
    wp_fts_release_packaging_contract_true(
        !WP_FTS_ReleaseChannelPolicy::profile_allows_row(WP_FTS_ReleaseChannelPolicy::PROFILE_WPORG_COMPATIBLE, $polish),
        'WP.org-compatible release profile should exclude bundled analyzer-pack runtime data'
    );
    wp_fts_release_packaging_contract_true(
        WP_FTS_ReleaseChannelPolicy::profile_allows_row(WP_FTS_ReleaseChannelPolicy::PROFILE_GITHUB_FULL, $polish),
        'GitHub full release profile should allow the BSD-2-Clause Polish pack'
    );
    wp_fts_release_packaging_contract_true(
        WP_FTS_ReleaseChannelPolicy::profile_allows_row(WP_FTS_ReleaseChannelPolicy::PROFILE_EXTENDED_LANGUAGE_PACKS, $polish),
        'extended language-pack bundle should include the BSD-2-Clause Polish pack'
    );

    $unknown = wp_fts_release_packaging_contract_row_by_prefix($rows, 'te-unimorph-');
    wp_fts_release_packaging_contract_same('upstream-license-not-declared', $unknown['license_spdx'], 'Telugu pack should remain license-blocked until upstream license evidence exists');
    foreach (WP_FTS_ReleaseChannelPolicy::profiles() as $profile) {
        wp_fts_release_packaging_contract_true(
            !WP_FTS_ReleaseChannelPolicy::profile_allows_row($profile, $unknown),
            "unknown-license analyzer packs should be excluded from {$profile}"
        );
    }

    $ccBySaRows = array_values(array_filter($rows, static fn(array $row): bool => str_starts_with((string) $row['license_spdx'], 'CC-BY-SA-')));
    wp_fts_release_packaging_contract_true($ccBySaRows !== [], 'release policy should classify at least one CC BY-SA analyzer pack');
    foreach ($ccBySaRows as $row) {
        wp_fts_release_packaging_contract_true(
            !WP_FTS_ReleaseChannelPolicy::profile_allows_row(WP_FTS_ReleaseChannelPolicy::PROFILE_CORE, $row),
            "core profile should exclude CC BY-SA pack {$row['pack_id']}"
        );
        wp_fts_release_packaging_contract_true(
            WP_FTS_ReleaseChannelPolicy::profile_allows_row(WP_FTS_ReleaseChannelPolicy::PROFILE_EXTENDED_LANGUAGE_PACKS, $row),
            "extended bundle should include CC BY-SA pack {$row['pack_id']}"
        );
    }

    foreach ($rows as $row) {
        if ($row['license_class'] !== 'fixture-only') {
            continue;
        }
        foreach (WP_FTS_ReleaseChannelPolicy::profiles() as $profile) {
            wp_fts_release_packaging_contract_true(
                !WP_FTS_ReleaseChannelPolicy::profile_allows_row($profile, $row),
                "fixture-only analyzer pack {$row['pack_id']} should be excluded from {$profile}"
            );
        }
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

function wp_fts_release_packaging_contract_manifest_signature_run(bool $allowPending = false): void
{
    if (
        !function_exists('sodium_crypto_sign_keypair')
        || !function_exists('sodium_crypto_sign_secretkey')
        || !function_exists('sodium_crypto_sign_publickey')
        || !function_exists('sodium_crypto_sign_verify_detached')
    ) {
        if ($allowPending && function_exists('mark_pending')) {
            mark_pending('The PHP sodium extension is required to test signed release manifests.');
        }
        throw new RuntimeException('The PHP sodium extension is required to test signed release manifests.');
    }

    $tmp = wp_fts_release_packaging_contract_temp_dir();
    $envName = 'WP_FTS_TEST_LANGUAGE_PACK_MANIFEST_SIGNING_KEY';
    try {
        $zipPath = $tmp . '/' . WP_FTS_LanguagePackReleaseManifestBuilder::ASSET_NAME;
        wp_fts_release_packaging_contract_write_fixture($zipPath, "zip fixture\n");

        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);
        putenv($envName . '=' . base64_encode($secretKey));

        $result = (new WP_FTS_LanguagePackReleaseManifestBuilder())->build([
            'zip' => $zipPath,
            'asset_url' => 'https://github.com/adamziel/wp-extensions/releases/download/language-fts-v0.1.10/' . WP_FTS_LanguagePackReleaseManifestBuilder::ASSET_NAME,
            'version' => 'language-fts-v0.1.10',
            'output' => $tmp . '/' . WP_FTS_LanguagePackReleaseManifestBuilder::MANIFEST_NAME,
            'signature_output' => $tmp . '/' . WP_FTS_LanguagePackReleaseManifestBuilder::SIGNATURE_NAME,
            'signing_key_env' => $envName,
        ]);

        $manifestJson = (string) file_get_contents((string) $result['manifest_path']);
        $signature = base64_decode(trim((string) file_get_contents((string) $result['signature_path'])), true);
        $manifest = json_decode($manifestJson, true);

        wp_fts_release_packaging_contract_true(is_array($manifest), 'release manifest builder should write JSON');
        wp_fts_release_packaging_contract_same(WP_FTS_LanguagePackReleaseManifestBuilder::SCHEMA, $manifest['schema'] ?? null, 'release manifest builder should write the expected schema');
        wp_fts_release_packaging_contract_same(WP_FTS_LanguagePackReleaseManifestBuilder::ASSET_NAME, $manifest['asset']['name'] ?? null, 'release manifest builder should record the expected ZIP asset');
        wp_fts_release_packaging_contract_same(hash_file('sha256', $zipPath), $manifest['asset']['sha256'] ?? null, 'release manifest builder should record the ZIP SHA-256 hash');
        wp_fts_release_packaging_contract_same(filesize($zipPath), $manifest['asset']['bytes'] ?? null, 'release manifest builder should record the ZIP byte size');
        wp_fts_release_packaging_contract_true(is_string($signature) && sodium_crypto_sign_verify_detached($signature, $manifestJson, $publicKey), 'release manifest builder should write a verifiable Ed25519 signature');

        foreach ([
            'bad release version' => [
                'version' => 'latest',
                'asset_url' => 'https://github.com/adamziel/wp-extensions/releases/download/latest/' . WP_FTS_LanguagePackReleaseManifestBuilder::ASSET_NAME,
            ],
            'mismatched release URL' => [
                'version' => 'language-fts-v0.1.10',
                'asset_url' => 'https://github.com/adamziel/wp-extensions/releases/download/language-fts-v0.1.11/' . WP_FTS_LanguagePackReleaseManifestBuilder::ASSET_NAME,
            ],
        ] as $case => $override) {
            try {
                (new WP_FTS_LanguagePackReleaseManifestBuilder())->build([
                    'zip' => $zipPath,
                    'asset_url' => $override['asset_url'],
                    'version' => $override['version'],
                    'output' => $tmp . "/{$case}.json",
                    'signature_output' => $tmp . "/{$case}.json.sig",
                    'signing_key_env' => $envName,
                ]);
                wp_fts_release_packaging_contract_true(false, "release manifest builder should reject {$case}");
            } catch (InvalidArgumentException $e) {
                wp_fts_release_packaging_contract_true($e->getMessage() !== '', "release manifest builder should reject {$case} with a bounded message");
            }
        }
    } finally {
        putenv($envName);
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
    test_case('quality release packaging enforces mixed-license channel policy', function (): void {
        wp_fts_release_packaging_contract_release_channel_policy_run();
    });
    test_case('quality release packaging signs extended language-pack manifest', function (): void {
        wp_fts_release_packaging_contract_manifest_signature_run(true);
    });
} elseif (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    wp_fts_release_packaging_contract_run();
    wp_fts_release_packaging_contract_prune_run();
    wp_fts_release_packaging_contract_composer_env_run();
    wp_fts_release_packaging_contract_release_channel_policy_run();
    wp_fts_release_packaging_contract_manifest_signature_run();
    fwrite(STDOUT, "OK: release packaging contract prunes dependency dotfiles, auth files, vendor tests, and mixed-license channel violations.\n");
}
