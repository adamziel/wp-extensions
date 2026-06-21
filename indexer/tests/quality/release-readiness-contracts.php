<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools/check-release-readiness.php';

function wp_fts_release_readiness_contract_true(bool $condition, string $message): void
{
    if (function_exists('assert_true')) {
        assert_true($condition, $message);
        return;
    }

    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wp_fts_release_readiness_contract_same(mixed $expected, mixed $actual, string $message): void
{
    if (function_exists('assert_same')) {
        assert_same($expected, $actual, $message);
        return;
    }

    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function wp_fts_release_readiness_contract_contains(string $needle, string $haystack, string $message): void
{
    if (function_exists('assert_contains')) {
        assert_contains($needle, $haystack, $message);
        return;
    }

    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message);
    }
}

function wp_fts_release_readiness_contract_temp_dir(): string
{
    $dir = sys_get_temp_dir() . '/wp_fts_release_readiness_' . getmypid() . '_' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("Could not create release-readiness fixture directory: {$dir}");
    }

    return $dir;
}

function wp_fts_release_readiness_contract_remove_tree(string $directory): void
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

function wp_fts_release_readiness_contract_pending(string $message): void
{
    if (function_exists('mark_pending')) {
        mark_pending($message);
    }

    throw new RuntimeException($message);
}

function wp_fts_release_readiness_contract_write_file(string $path, string $contents = "fixture\n"): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException("Could not create fixture directory: {$directory}");
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException("Could not write fixture file: {$path}");
    }
}

/**
 * @param array<string,mixed> $data
 */
function wp_fts_release_readiness_contract_write_json(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException("Could not encode fixture JSON: {$path}");
    }
    wp_fts_release_readiness_contract_write_file($path, $json . "\n");
}

/**
 * @param string[] $command
 * @return array{exit:int,stdout:string,stderr:string}
 */
function wp_fts_release_readiness_contract_run_command(array $command, string $cwd): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start command: ' . implode(' ', $command));
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

/**
 * @param array{version?:string,license?:string,composer_version?:string,readme?:bool,license_file?:bool,public_assets?:bool,public_docs_ready?:bool,public_evidence?:bool} $options
 */
function wp_fts_release_readiness_contract_source_fixture(string $tmp, array $options = []): string
{
    $version = (string) ($options['version'] ?? '1.2.3');
    $license = (string) ($options['license'] ?? 'proprietary');
    $source = $tmp . '/source';

    wp_fts_release_readiness_contract_write_file($source . '/indexer.php', "<?php\n/**\n * Plugin Name: Pure PHP FTS Indexer\n * Version: {$version}\n * Requires PHP: 8.1\n */\n");
    wp_fts_release_readiness_contract_write_file($source . '/README.md', "# Pure PHP FTS Indexer\n");
    wp_fts_release_readiness_contract_write_json($source . '/composer.json', array_filter([
        'name' => 'local/wp-pure-php-fts',
        'description' => 'Fixture release-readiness plugin metadata.',
        'type' => 'wordpress-plugin',
        'license' => $license,
        'version' => $options['composer_version'] ?? null,
        'require' => [
            'php' => '>=8.1',
            'wp-php-toolkit/full-text-search' => '^0.1',
        ],
    ], static fn(mixed $value): bool => $value !== null));
    wp_fts_release_readiness_contract_write_file($source . '/tools/build-release-zip.php', "<?php\n// Fixture builder presence marker.\n");

    if (($options['readme'] ?? false) === true) {
        wp_fts_release_readiness_contract_write_file(
            $source . '/readme.txt',
            implode("\n", [
                '=== Pure PHP FTS Indexer ===',
                'Contributors: fixture-maintainer',
                'Tags: search, full text search, indexing',
                'Requires at least: 6.5',
                'Tested up to: 6.9',
                'Requires PHP: 8.1',
                "Stable tag: {$version}",
                'License: GPL-2.0-or-later',
                'License URI: https://www.gnu.org/licenses/gpl-2.0.html',
                '',
                '== Description ==',
                'Fixture public submission readme content with reviewable details for search indexing.',
                '',
                '== Installation ==',
                'Upload the plugin directory, activate it, and run a small indexing smoke check.',
                '',
                '== FAQ ==',
                '= Does this fixture include public metadata? =',
                'Yes. The fixture carries enough public metadata to exercise the readiness gate.',
                '',
                '== Changelog ==',
                "= {$version} =",
                'Initial public-submission fixture release.',
                '',
            ])
        );
    }

    if (($options['license_file'] ?? false) === true) {
        wp_fts_release_readiness_contract_write_file($source . '/LICENSE', "GNU GENERAL PUBLIC LICENSE\nVersion 2, June 1991\nFixture redistribution terms for gate coverage.\n");
    }

    if (($options['public_assets'] ?? false) === true) {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
        wp_fts_release_readiness_contract_write_file($source . '/assets/banner-772x250.png', $png);
        wp_fts_release_readiness_contract_write_file($source . '/assets/icon-128x128.png', $png);
    }

    $docs = ($options['public_docs_ready'] ?? false) === true
        ? "Public-submission artifacts have been reviewed and approved for this fixture.\n"
        : "This package is a direct-install ZIP boundary only and is not public-submission-ready.\n";
    wp_fts_release_readiness_contract_write_file($source . '/docs/release-packaging.md', $docs);

    if (($options['public_evidence'] ?? false) === true) {
        wp_fts_release_readiness_contract_write_json($source . '/docs/public-submission-readiness.json', [
            'status' => 'approved',
            'target' => 'wordpress.org-plugin-directory',
            'approver' => 'Fixture Reviewer',
            'reviewed_at' => '2026-06-21',
            'checks' => [
                'readme' => true,
                'license' => true,
                'assets' => true,
                'public_submission_authority' => true,
            ],
        ]);
    }

    return $source;
}

function wp_fts_release_readiness_contract_package_fixture(string $tmp, string $version = '1.2.3'): string
{
    $package = $tmp . '/indexer';
    wp_fts_release_readiness_contract_write_file($package . '/indexer.php', "<?php\n/**\n * Plugin Name: Pure PHP FTS Indexer\n * Version: {$version}\n * Requires PHP: 8.1\n */\n");
    wp_fts_release_readiness_contract_write_json($package . '/composer.json', [
        'name' => 'local/wp-pure-php-fts',
        'type' => 'wordpress-plugin',
        'license' => 'proprietary',
        'require' => [
            'php' => '>=8.1',
            'wp-php-toolkit/full-text-search' => '^0.1',
        ],
    ]);
    wp_fts_release_readiness_contract_write_json($package . '/composer.lock', [
        'packages' => [
            ['name' => 'wp-php-toolkit/full-text-search', 'version' => '0.1.0'],
            ['name' => 'wamania/php-stemmer', 'version' => 'v3.0.1'],
        ],
        'packages-dev' => [],
    ]);
    wp_fts_release_readiness_contract_write_file($package . '/README.md', "# Pure PHP FTS Indexer\n");
    wp_fts_release_readiness_contract_write_file($package . '/src/bootstrap.php', "<?php\n");
    wp_fts_release_readiness_contract_write_file($package . '/src/Plugin.php', "<?php\n");
    wp_fts_release_readiness_contract_write_file($package . '/tools/build-release-zip.php', "<?php\n");
    wp_fts_release_readiness_contract_write_file($package . '/vendor/autoload.php', "<?php\n");
    wp_fts_release_readiness_contract_write_file($package . '/vendor/wp-php-toolkit/full-text-search/src/bootstrap.php', "<?php\n");

    return $package;
}

/**
 * @param array<string,mixed> $report
 * @return string[]
 */
function wp_fts_release_readiness_contract_blocker_ids(array $report): array
{
    $ids = [];
    foreach (($report['blockers'] ?? []) as $blocker) {
        if (is_array($blocker) && is_string($blocker['id'] ?? null)) {
            $ids[] = $blocker['id'];
        }
    }
    sort($ids, SORT_STRING);

    return $ids;
}

function wp_fts_release_readiness_contract_has_check(array $report, string $id, string $status): bool
{
    foreach (($report['checks'] ?? []) as $check) {
        if (is_array($check) && ($check['id'] ?? null) === $id && ($check['status'] ?? null) === $status) {
            return true;
        }
    }

    return false;
}

function wp_fts_release_readiness_contract_direct_ready(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $source = wp_fts_release_readiness_contract_source_fixture($tmp);
        $package = wp_fts_release_readiness_contract_package_fixture($tmp);
        $report = (new WP_FTS_ReleaseReadinessChecker())->check([
            'target' => 'direct-install',
            'plugin_src' => $source,
            'monorepo_root' => $tmp,
            'package_dir' => $package,
        ]);

        wp_fts_release_readiness_contract_same('ready', $report['status'] ?? null, 'staged direct-install package should be ready');
        wp_fts_release_readiness_contract_same([], $report['blockers'] ?? null, 'ready direct-install package should not report blockers');
        wp_fts_release_readiness_contract_true(
            wp_fts_release_readiness_contract_has_check($report, 'direct_required_runtime_files', 'pass'),
            'direct-install readiness should validate required runtime files'
        );
        wp_fts_release_readiness_contract_true(
            wp_fts_release_readiness_contract_has_check($report, 'direct_package_prohibited_paths', 'pass'),
            'direct-install readiness should validate the package exclusion boundary'
        );
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

function wp_fts_release_readiness_contract_current_public_blocked(): void
{
    $root = dirname(__DIR__, 2);
    $report = (new WP_FTS_ReleaseReadinessChecker())->check([
        'target' => 'public-submission',
        'plugin_src' => $root,
        'monorepo_root' => dirname($root),
    ]);
    $ids = wp_fts_release_readiness_contract_blocker_ids($report);

    wp_fts_release_readiness_contract_same('blocked', $report['status'] ?? null, 'current package should not pass public-submission readiness');
    foreach (['composer_public_license', 'docs_public_submission_blocker', 'package_license_file', 'package_public_assets', 'package_readme_txt', 'public_submission_authority_evidence'] as $id) {
        wp_fts_release_readiness_contract_true(in_array($id, $ids, true), "current package should report public-submission blocker {$id}");
    }
}

function wp_fts_release_readiness_contract_public_readme_and_license_blockers(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $missingReadme = wp_fts_release_readiness_contract_source_fixture($tmp . '/missing-readme', [
            'license' => 'GPL-2.0-or-later',
            'license_file' => true,
            'public_assets' => true,
            'public_docs_ready' => true,
        ]);
        $readmeReport = (new WP_FTS_ReleaseReadinessChecker())->check([
            'target' => 'public-submission',
            'plugin_src' => $missingReadme,
            'monorepo_root' => dirname($missingReadme),
        ]);
        wp_fts_release_readiness_contract_true(
            in_array('package_readme_txt', wp_fts_release_readiness_contract_blocker_ids($readmeReport), true),
            'public-submission readiness should detect missing readme.txt'
        );

        $missingLicense = wp_fts_release_readiness_contract_source_fixture($tmp . '/missing-license', [
            'license' => 'GPL-2.0-or-later',
            'readme' => true,
            'public_assets' => true,
            'public_docs_ready' => true,
        ]);
        $licenseReport = (new WP_FTS_ReleaseReadinessChecker())->check([
            'target' => 'public-submission',
            'plugin_src' => $missingLicense,
            'monorepo_root' => dirname($missingLicense),
        ]);
        wp_fts_release_readiness_contract_true(
            in_array('package_license_file', wp_fts_release_readiness_contract_blocker_ids($licenseReport), true),
            'public-submission readiness should detect missing package-level license file'
        );

        $proprietary = wp_fts_release_readiness_contract_source_fixture($tmp . '/proprietary-license', [
            'license' => 'proprietary',
            'readme' => true,
            'license_file' => true,
            'public_assets' => true,
            'public_docs_ready' => true,
        ]);
        $proprietaryReport = (new WP_FTS_ReleaseReadinessChecker())->check([
            'target' => 'public-submission',
            'plugin_src' => $proprietary,
            'monorepo_root' => dirname($proprietary),
        ]);
        wp_fts_release_readiness_contract_true(
            in_array('composer_public_license', wp_fts_release_readiness_contract_blocker_ids($proprietaryReport), true),
            'public-submission readiness should detect proprietary composer license policy'
        );
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

function wp_fts_release_readiness_contract_public_placeholder_artifacts_blocked(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $source = $tmp . '/source';
        wp_fts_release_readiness_contract_write_file($source . '/indexer.php', "<?php\n/**\n * Plugin Name: Pure PHP FTS Indexer\n * Version: 9.9.9\n */\n");
        wp_fts_release_readiness_contract_write_json($source . '/composer.json', [
            'name' => 'local/wp-pure-php-fts',
            'type' => 'wordpress-plugin',
            'license' => 'GPL-2.0-or-later',
            'require' => [
                'php' => '>=8.1',
                'wp-php-toolkit/full-text-search' => '^0.1',
            ],
        ]);
        wp_fts_release_readiness_contract_write_file($source . '/readme.txt', "=== Pure PHP FTS Indexer ===\nStable tag: 9.9.9\n");
        wp_fts_release_readiness_contract_write_file($source . '/LICENSE', "placeholder license text\n");
        wp_fts_release_readiness_contract_write_file($source . '/assets/not-a-wordpress-org-asset.txt', "placeholder asset\n");
        wp_fts_release_readiness_contract_write_file($source . '/docs/release-packaging.md', "Public submission placeholders are present.\n");

        $report = (new WP_FTS_ReleaseReadinessChecker())->check([
            'target' => 'public-submission',
            'plugin_src' => $source,
            'monorepo_root' => $tmp,
        ]);
        $ids = wp_fts_release_readiness_contract_blocker_ids($report);

        wp_fts_release_readiness_contract_same('blocked', $report['status'] ?? null, 'placeholder public-submission artifacts must not pass readiness');
        foreach (['package_license_file', 'package_public_assets', 'package_readme_txt', 'public_submission_authority_evidence'] as $id) {
            wp_fts_release_readiness_contract_true(in_array($id, $ids, true), "placeholder public-submission fixture should report blocker {$id}");
        }
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

function wp_fts_release_readiness_contract_public_complete_fixture_ready(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $source = wp_fts_release_readiness_contract_source_fixture($tmp, [
            'license' => 'GPL-2.0-or-later',
            'readme' => true,
            'license_file' => true,
            'public_assets' => true,
            'public_docs_ready' => true,
            'public_evidence' => true,
        ]);
        $report = (new WP_FTS_ReleaseReadinessChecker())->check([
            'target' => 'public-submission',
            'plugin_src' => $source,
            'monorepo_root' => dirname($source),
        ]);

        wp_fts_release_readiness_contract_same('ready', $report['status'] ?? null, 'complete public-submission evidence fixture should pass readiness');
        wp_fts_release_readiness_contract_true(
            wp_fts_release_readiness_contract_has_check($report, 'public_submission_authority_evidence', 'pass'),
            'complete public-submission fixture should validate authority evidence'
        );
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

function wp_fts_release_readiness_contract_prohibited_package_paths(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $source = wp_fts_release_readiness_contract_source_fixture($tmp);
        $package = wp_fts_release_readiness_contract_package_fixture($tmp);
        wp_fts_release_readiness_contract_write_file($package . '/tests/smoke.php', "<?php\n");
        wp_fts_release_readiness_contract_write_file($package . '/vendor/bin/phpunit', "#!/usr/bin/env php\n");
        wp_fts_release_readiness_contract_write_file($package . '/vendor/example/library/coverage/report.xml', "<xml />\n");
        wp_fts_release_readiness_contract_write_file($package . '/playground/indexer-preview.zip', "zip fixture\n");
        wp_fts_release_readiness_contract_write_file($package . '/cache/object-cache.bin', "cache fixture\n");
        wp_fts_release_readiness_contract_write_file($package . '/.gitignore', "*\n");

        $report = (new WP_FTS_ReleaseReadinessChecker())->check([
            'target' => 'direct-install',
            'plugin_src' => $source,
            'monorepo_root' => $tmp,
            'package_dir' => $package,
        ]);
        $ids = wp_fts_release_readiness_contract_blocker_ids($report);

        wp_fts_release_readiness_contract_same('blocked', $report['status'] ?? null, 'prohibited staged package paths should block direct-install readiness');
        wp_fts_release_readiness_contract_true(in_array('direct_package_prohibited_paths', $ids, true), 'prohibited paths should report the package boundary blocker');
        $json = WP_FTS_ReleaseReadinessChecker::render_json($report);
        foreach (['indexer/tests', 'indexer/vendor/bin', 'indexer/vendor/example/library/coverage', 'indexer/playground/indexer-preview.zip'] as $path) {
            wp_fts_release_readiness_contract_contains($path, $json, "prohibited path report should include {$path}");
        }
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

function wp_fts_release_readiness_contract_version_mismatch(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $source = wp_fts_release_readiness_contract_source_fixture($tmp, ['version' => '1.2.3']);
        $package = wp_fts_release_readiness_contract_package_fixture($tmp, '1.2.4');
        $report = (new WP_FTS_ReleaseReadinessChecker())->check([
            'target' => 'direct-install',
            'plugin_src' => $source,
            'monorepo_root' => $tmp,
            'package_dir' => $package,
        ]);

        wp_fts_release_readiness_contract_same('blocked', $report['status'] ?? null, 'package/source version mismatch should block direct-install readiness');
        wp_fts_release_readiness_contract_true(
            in_array('version_metadata_mismatch', wp_fts_release_readiness_contract_blocker_ids($report), true),
            'version mismatch should use the stable version_metadata_mismatch blocker id'
        );
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

function wp_fts_release_readiness_contract_default_direct_cli_output_is_deterministic(): void
{
    if (!class_exists('ZipArchive')) {
        wp_fts_release_readiness_contract_pending('ZipArchive is unavailable; default direct-install CLI ZIP build is covered in the normal PHP lane.');
    }

    $root = dirname(__DIR__, 2);
    $monorepoRoot = dirname($root);
    $command = [PHP_BINARY, 'indexer/tools/check-release-readiness.php', '--target=direct-install'];

    $first = wp_fts_release_readiness_contract_run_command($command, $monorepoRoot);
    $second = wp_fts_release_readiness_contract_run_command($command, $monorepoRoot);

    wp_fts_release_readiness_contract_same(0, $first['exit'], 'first default direct-install readiness CLI run should pass');
    wp_fts_release_readiness_contract_same('', $first['stderr'], 'first default direct-install readiness CLI run should not emit stderr');
    wp_fts_release_readiness_contract_same(0, $second['exit'], 'second default direct-install readiness CLI run should pass');
    wp_fts_release_readiness_contract_same('', $second['stderr'], 'second default direct-install readiness CLI run should not emit stderr');
    wp_fts_release_readiness_contract_same($first['stdout'], $second['stdout'], 'default direct-install readiness CLI JSON output should be deterministic across unchanged runs');
}

function wp_fts_release_readiness_contract_deterministic_output_and_docs(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $source = wp_fts_release_readiness_contract_source_fixture($tmp);
        $package = wp_fts_release_readiness_contract_package_fixture($tmp);
        $options = [
            'target' => 'direct-install',
            'plugin_src' => $source,
            'monorepo_root' => $tmp,
            'package_dir' => $package,
        ];
        $checker = new WP_FTS_ReleaseReadinessChecker();
        $first = WP_FTS_ReleaseReadinessChecker::render_json($checker->check($options));
        $second = WP_FTS_ReleaseReadinessChecker::render_json($checker->check($options));
        wp_fts_release_readiness_contract_same($first, $second, 'release-readiness JSON output should be deterministic for an unchanged package');
        wp_fts_release_readiness_contract_contains('Release readiness target: direct-install', WP_FTS_ReleaseReadinessChecker::render_text($checker->check($options)), 'text output should name the checked target');

        $root = dirname(__DIR__, 2);
        $releaseDocs = (string) file_get_contents($root . '/docs/release-packaging.md');
        $testingDocs = (string) file_get_contents($root . '/docs/testing.md');
        foreach (['check-release-readiness.php', '--target=direct-install', '--target=public-submission'] as $needle) {
            wp_fts_release_readiness_contract_contains($needle, $releaseDocs . "\n" . $testingDocs, "release docs should document {$needle}");
        }
        wp_fts_release_readiness_contract_contains('not the same as WordPress.org/SVN', $releaseDocs, 'release docs should distinguish direct-install readiness from public submission');
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

if (function_exists('test_case')) {
    test_case('quality release readiness accepts a staged direct-install package', function (): void {
        wp_fts_release_readiness_contract_direct_ready();
    });
    test_case('quality release readiness blocks current public submission state', function (): void {
        wp_fts_release_readiness_contract_current_public_blocked();
    });
    test_case('quality release readiness reports public metadata and license blockers', function (): void {
        wp_fts_release_readiness_contract_public_readme_and_license_blockers();
    });
    test_case('quality release readiness blocks placeholder public-submission artifacts', function (): void {
        wp_fts_release_readiness_contract_public_placeholder_artifacts_blocked();
    });
    test_case('quality release readiness accepts complete public-submission evidence fixtures', function (): void {
        wp_fts_release_readiness_contract_public_complete_fixture_ready();
    });
    test_case('quality release readiness detects prohibited direct package paths', function (): void {
        wp_fts_release_readiness_contract_prohibited_package_paths();
    });
    test_case('quality release readiness detects version mismatches', function (): void {
        wp_fts_release_readiness_contract_version_mismatch();
    });
    test_case('quality release readiness default direct-install CLI output is deterministic', function (): void {
        wp_fts_release_readiness_contract_default_direct_cli_output_is_deterministic();
    });
    test_case('quality release readiness output and docs are deterministic', function (): void {
        wp_fts_release_readiness_contract_deterministic_output_and_docs();
    });
} elseif (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    wp_fts_release_readiness_contract_direct_ready();
    wp_fts_release_readiness_contract_current_public_blocked();
    wp_fts_release_readiness_contract_public_readme_and_license_blockers();
    wp_fts_release_readiness_contract_public_placeholder_artifacts_blocked();
    wp_fts_release_readiness_contract_public_complete_fixture_ready();
    wp_fts_release_readiness_contract_prohibited_package_paths();
    wp_fts_release_readiness_contract_version_mismatch();
    wp_fts_release_readiness_contract_default_direct_cli_output_is_deterministic();
    wp_fts_release_readiness_contract_deterministic_output_and_docs();
    fwrite(STDOUT, "OK: release readiness contracts distinguish direct-install and public-submission gates.\n");
}
