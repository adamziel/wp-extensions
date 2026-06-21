<?php
declare(strict_types=1);

/**
 * Deterministic release-readiness gate for the Pure PHP FTS Indexer plugin.
 *
 * Direct-install readiness proves the project can produce and validate the
 * direct ZIP package boundary. Public-submission readiness is intentionally a
 * separate target because marketplace/SVN distribution needs additional
 * metadata, license, and policy evidence.
 */
final class WP_FTS_ReleaseReadinessChecker
{
    private const TARGET_DIRECT_INSTALL = 'direct-install';
    private const TARGET_PUBLIC_SUBMISSION = 'public-submission';

    private const REQUIRED_PACKAGE_PATHS = [
        'indexer.php',
        'composer.json',
        'composer.lock',
        'README.md',
        'src/bootstrap.php',
        'src/Plugin.php',
        'tools/build-release-zip.php',
        'vendor/autoload.php',
        'vendor/wp-php-toolkit/full-text-search/src/bootstrap.php',
    ];

    private const PROHIBITED_PACKAGE_PREFIXES = [
        '.cao',
        '.distignore',
        '.git',
        '.gitignore',
        'goal.md',
        'playground/indexer-preview.zip',
        'resources/sources',
        'review-artifacts',
        'tests',
        'vendor/bin',
    ];

    private const VENDOR_DEVELOPMENT_DIRS = ['test', 'tests', 'Tests', 'coverage'];
    private const LOCAL_ARTIFACT_DIRS = ['cache', 'log', 'logs', 'temp', 'tmp'];
    private const LOCAL_ARTIFACT_EXTENSIONS = ['.db', '.dump', '.log', '.sqlite', '.sqlite3', '.sql', '.tmp', '.zip'];

    /**
     * @param array<int,string> $args
     * @return array<string,mixed>
     */
    public static function parse_cli_options(array $args): array
    {
        $options = [
            'target' => self::TARGET_DIRECT_INSTALL,
            'format' => 'json',
        ];

        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;
                continue;
            }
            if ($arg === '--json') {
                $options['format'] = 'json';
                continue;
            }
            if ($arg === '--text') {
                $options['format'] = 'text';
                continue;
            }

            foreach (['target', 'format', 'plugin-src', 'monorepo-root', 'build-dir', 'output', 'package-dir'] as $name) {
                $prefix = "--{$name}=";
                if (str_starts_with($arg, $prefix)) {
                    $key = str_replace('-', '_', $name);
                    $options[$key] = substr($arg, strlen($prefix));
                    continue 2;
                }
            }

            throw new InvalidArgumentException("Unknown option: {$arg}");
        }

        return $options;
    }

    public static function usage(): string
    {
        return implode("\n", [
            'Usage: php indexer/tools/check-release-readiness.php [options]',
            '',
            'Options:',
            '  --target=direct-install|public-submission',
            '  --format=json|text       Output format. Defaults to json.',
            '  --json                   Alias for --format=json.',
            '  --text                   Alias for --format=text.',
            '  --plugin-src=PATH        Plugin source directory. Defaults to this script parent.',
            '  --monorepo-root=PATH     Monorepo root. Defaults to the plugin source parent.',
            '  --build-dir=PATH         Direct-install build directory.',
            '  --output=PATH            Direct-install ZIP output path.',
            '  --package-dir=PATH       Validate an already staged indexer package directory.',
            '  -h, --help               Show this help.',
            '',
        ]);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function check(array $options = []): array
    {
        $target = (string) ($options['target'] ?? self::TARGET_DIRECT_INSTALL);
        if (!in_array($target, [self::TARGET_DIRECT_INSTALL, self::TARGET_PUBLIC_SUBMISSION], true)) {
            throw new InvalidArgumentException("Unknown release-readiness target: {$target}");
        }

        $pluginSource = self::existing_directory((string) ($options['plugin_src'] ?? dirname(__DIR__)), 'plugin source');
        $monorepoRoot = self::existing_directory((string) ($options['monorepo_root'] ?? dirname($pluginSource)), 'monorepo root');

        $checks = [];
        $blockers = [];

        if ($target === self::TARGET_DIRECT_INSTALL) {
            $this->check_direct_install($pluginSource, $monorepoRoot, $options, $checks, $blockers);
        } else {
            $this->check_public_submission($pluginSource, $checks, $blockers);
        }

        return [
            'tool' => 'wp-fts-release-readiness',
            'target' => $target,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'checks' => $checks,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param array<string,mixed> $report
     */
    public static function render_json(array $report): string
    {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Could not encode release-readiness report.');
        }

        return $json . "\n";
    }

    /**
     * @param array<string,mixed> $report
     */
    public static function render_text(array $report): string
    {
        $lines = [
            'Release readiness target: ' . (string) ($report['target'] ?? ''),
            'Status: ' . strtoupper((string) ($report['status'] ?? 'unknown')),
            'Checks:',
        ];

        foreach (($report['checks'] ?? []) as $check) {
            if (!is_array($check)) {
                continue;
            }
            $lines[] = sprintf(
                '- [%s] %s: %s',
                (string) ($check['status'] ?? 'unknown'),
                (string) ($check['id'] ?? 'unknown'),
                (string) ($check['message'] ?? '')
            );
        }

        $blockers = $report['blockers'] ?? [];
        if (is_array($blockers) && $blockers !== []) {
            $lines[] = 'Blockers:';
            foreach ($blockers as $blocker) {
                if (!is_array($blocker)) {
                    continue;
                }
                $lines[] = sprintf(
                    '- %s: %s',
                    (string) ($blocker['id'] ?? 'unknown'),
                    (string) ($blocker['message'] ?? '')
                );
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string,mixed> $options
     * @param array<int,array<string,mixed>> $checks
     * @param array<int,array<string,mixed>> $blockers
     */
    private function check_direct_install(
        string $pluginSource,
        string $monorepoRoot,
        array $options,
        array &$checks,
        array &$blockers
    ): void {
        $sourceMetadata = $this->check_plugin_metadata($pluginSource, $checks, $blockers, 'source');

        $builderPath = $pluginSource . '/tools/build-release-zip.php';
        $packageDir = isset($options['package_dir'])
            ? self::existing_directory((string) $options['package_dir'], 'staged package')
            : null;

        if (!is_file($builderPath)) {
            $this->record($checks, $blockers, 'direct_builder_script', 'fail', 'Direct-install ZIP builder is missing.', [
                'expected_path' => self::display_path($builderPath),
            ]);
            return;
        }

        if ($packageDir !== null) {
            $this->record($checks, $blockers, 'direct_builder_script', 'pass', 'Direct-install ZIP builder script exists; validating supplied package directory.');
            $this->check_direct_package_directory($packageDir, $sourceMetadata, $checks, $blockers);
            return;
        }

        require_once $builderPath;
        if (!class_exists('WP_FTS_ReleasePackageBuilder')) {
            $this->record($checks, $blockers, 'direct_builder_class', 'fail', 'Direct-install ZIP builder class is not loadable.');
            return;
        }
        $this->record($checks, $blockers, 'direct_builder_class', 'pass', 'Direct-install ZIP builder class is loadable.');

        $buildOptions = [
            'plugin_src' => $pluginSource,
            'monorepo_root' => $monorepoRoot,
        ];
        foreach (['build_dir', 'output'] as $key) {
            if (isset($options[$key])) {
                $buildOptions[$key] = (string) $options[$key];
            }
        }

        try {
            /** @var array{build_dir:string,zip_path:string,sha256:string,removed_paths:string[],prohibited_paths:string[]} $build */
            $build = (new WP_FTS_ReleasePackageBuilder())->build($buildOptions);
            $this->record($checks, $blockers, 'direct_package_build', 'pass', 'Direct-install ZIP builder completed.', [
                'zip_path' => self::display_path($build['zip_path']),
                'sha256' => $build['sha256'],
                'removed_paths_count' => count($build['removed_paths']),
            ]);
        } catch (Throwable $e) {
            $this->record($checks, $blockers, 'direct_package_build', 'fail', 'Direct-install ZIP builder failed: ' . $e->getMessage());
            return;
        }

        $stagePlugin = $build['build_dir'] . '/indexer';
        $this->check_direct_package_directory($stagePlugin, $sourceMetadata, $checks, $blockers);
        $this->check_release_zip($build['zip_path'], $checks, $blockers);
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     * @param array<int,array<string,mixed>> $blockers
     */
    private function check_public_submission(string $pluginSource, array &$checks, array &$blockers): void
    {
        $this->check_plugin_metadata($pluginSource, $checks, $blockers, 'public_source');

        $readmePath = $pluginSource . '/readme.txt';
        if (!is_file($readmePath)) {
            $this->record($checks, $blockers, 'package_readme_txt', 'fail', 'Missing package-level readme.txt with public marketplace metadata.');
        } else {
            $readme = self::read_text_file($readmePath, 'public readme');
            $hasPluginHeading = preg_match('/^===\s+.+\s+===\s*$/m', $readme) === 1;
            $hasStableTag = preg_match('/^Stable tag:\s*\S+/mi', $readme) === 1;
            $this->record(
                $checks,
                $blockers,
                'package_readme_txt',
                $hasPluginHeading && $hasStableTag ? 'pass' : 'fail',
                $hasPluginHeading && $hasStableTag
                    ? 'Package-level readme.txt has required public metadata markers.'
                    : 'Package-level readme.txt is missing WordPress.org-style heading or Stable tag metadata.'
            );
        }

        $licensePath = $this->find_license_file($pluginSource);
        $this->record(
            $checks,
            $blockers,
            'package_license_file',
            $licensePath === null ? 'fail' : 'pass',
            $licensePath === null
                ? 'Missing package-level license file for public redistribution review.'
                : 'Package-level license file exists.',
            $licensePath === null ? [] : ['path' => basename($licensePath)]
        );

        $assetFiles = $this->public_asset_files($pluginSource);
        $this->record(
            $checks,
            $blockers,
            'package_public_assets',
            $assetFiles === [] ? 'fail' : 'pass',
            $assetFiles === []
                ? 'Missing public-submission assets or documented asset approval evidence.'
                : 'Public-submission asset directory contains reviewable assets.',
            $assetFiles === [] ? [] : ['asset_file_count' => count($assetFiles)]
        );

        $composer = $this->read_json_blocker($pluginSource . '/composer.json', 'composer metadata', 'composer_public_license', $checks, $blockers);
        $license = is_array($composer) ? ($composer['license'] ?? null) : null;
        $publicLicenseOk = is_array($composer) && self::composer_license_is_public_ready($license);
        $this->record(
            $checks,
            $blockers,
            'composer_public_license',
            $publicLicenseOk ? 'pass' : 'fail',
            $publicLicenseOk
                ? 'Composer license metadata is not proprietary or unresolved.'
                : 'Composer license metadata is proprietary, missing, or unresolved for public redistribution.',
            ['license' => $license]
        );

        $releaseDocs = $pluginSource . '/docs/release-packaging.md';
        if (is_file($releaseDocs)) {
            $docs = self::read_text_file($releaseDocs, 'release packaging docs');
            $stillDirectOnly = str_contains($docs, 'direct-install ZIP boundary only')
                || str_contains($docs, 'does not make the plugin ready for WordPress.org')
                || str_contains($docs, 'not public-submission-ready');
            $this->record(
                $checks,
                $blockers,
                'docs_public_submission_blocker',
                $stillDirectOnly ? 'fail' : 'pass',
                $stillDirectOnly
                    ? 'Release docs still state that the package is direct-install only, not public-submission ready.'
                    : 'Release docs no longer advertise a direct-install-only public-submission blocker.'
            );
        } else {
            $this->record($checks, $blockers, 'docs_public_submission_blocker', 'fail', 'Release packaging docs are missing.');
        }
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     * @param array<int,array<string,mixed>> $blockers
     * @return array{version:?string,composer_version:?string}
     */
    private function check_plugin_metadata(string $pluginDir, array &$checks, array &$blockers, string $scope): array
    {
        $headerPath = $pluginDir . '/indexer.php';
        $headers = is_file($headerPath) ? $this->parse_plugin_headers($headerPath) : [];
        $version = isset($headers['Version']) ? trim((string) $headers['Version']) : null;
        $pluginName = isset($headers['Plugin Name']) ? trim((string) $headers['Plugin Name']) : '';

        $headerOk = $pluginName !== '' && $version !== null && preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1;
        $this->record(
            $checks,
            $blockers,
            "{$scope}_plugin_header",
            $headerOk ? 'pass' : 'fail',
            $headerOk
                ? 'Plugin header name and version are present and parseable.'
                : 'Plugin header name or version is missing or not parseable.',
            $headerOk ? ['plugin_name' => $pluginName, 'version' => $version] : []
        );

        $composer = $this->read_json_blocker($pluginDir . '/composer.json', 'composer metadata', "{$scope}_composer_metadata", $checks, $blockers);
        if ($composer === null) {
            return [
                'version' => $version,
                'composer_version' => null,
            ];
        }

        $composerVersion = isset($composer['version']) && is_scalar($composer['version'])
            ? trim((string) $composer['version'])
            : null;
        $require = isset($composer['require']) && is_array($composer['require']) ? $composer['require'] : [];
        $composerOk = ($composer['type'] ?? null) === 'wordpress-plugin'
            && is_string($composer['name'] ?? null)
            && ($composer['name'] ?? '') !== ''
            && isset($require['php'])
            && isset($require['wp-php-toolkit/full-text-search']);
        $this->record(
            $checks,
            $blockers,
            "{$scope}_composer_metadata",
            $composerOk ? 'pass' : 'fail',
            $composerOk
                ? 'Composer metadata is parseable and declares the direct-install runtime dependency contract.'
                : 'Composer metadata is missing required plugin type, package name, PHP requirement, or FTS component dependency.',
            [
                'name' => $composer['name'] ?? null,
                'type' => $composer['type'] ?? null,
                'license' => $composer['license'] ?? null,
            ]
        );

        $versionsMatch = $version !== null
            && ($composerVersion === null || $composerVersion === $version);
        $this->record(
            $checks,
            $blockers,
            "{$scope}_version_metadata",
            $versionsMatch ? 'pass' : 'fail',
            $versionsMatch
                ? 'Plugin header version is the package release authority and composer version does not conflict.'
                : 'Plugin header version conflicts with composer package version.',
            [
                'plugin_header_version' => $version,
                'composer_version' => $composerVersion,
            ],
            $versionsMatch ? null : 'version_metadata_mismatch'
        );

        return [
            'version' => $version,
            'composer_version' => $composerVersion,
        ];
    }

    /**
     * @param array{version:?string,composer_version:?string} $sourceMetadata
     * @param array<int,array<string,mixed>> $checks
     * @param array<int,array<string,mixed>> $blockers
     */
    private function check_direct_package_directory(
        string $packageDir,
        array $sourceMetadata,
        array &$checks,
        array &$blockers
    ): void {
        $packageDir = self::existing_directory($packageDir, 'direct-install package');
        $rootOk = basename($packageDir) === 'indexer';
        $this->record(
            $checks,
            $blockers,
            'direct_package_root',
            $rootOk ? 'pass' : 'fail',
            $rootOk ? 'Package root directory is indexer/.' : 'Package root directory must be indexer/.',
            ['root' => basename($packageDir)]
        );

        $missing = [];
        foreach (self::REQUIRED_PACKAGE_PATHS as $relativePath) {
            if (!file_exists($packageDir . '/' . $relativePath)) {
                $missing[] = 'indexer/' . $relativePath;
            }
        }
        sort($missing, SORT_STRING);
        $this->record(
            $checks,
            $blockers,
            'direct_required_runtime_files',
            $missing === [] ? 'pass' : 'fail',
            $missing === []
                ? 'Required runtime files and production dependency entrypoints are present.'
                : 'Direct-install package is missing required runtime files or production dependencies.',
            $missing === [] ? [] : ['missing_paths' => $missing]
        );

        $packageHeaders = $this->parse_plugin_headers($packageDir . '/indexer.php');
        $packageVersion = isset($packageHeaders['Version']) ? trim((string) $packageHeaders['Version']) : null;
        $packageVersionOk = $sourceMetadata['version'] === null
            || $packageVersion === $sourceMetadata['version'];
        $this->record(
            $checks,
            $blockers,
            'direct_package_version',
            $packageVersionOk ? 'pass' : 'fail',
            $packageVersionOk
                ? 'Staged package plugin header version matches the source release version.'
                : 'Staged package plugin header version does not match the source release version.',
            [
                'source_version' => $sourceMetadata['version'],
                'package_version' => $packageVersion,
            ],
            $packageVersionOk ? null : 'version_metadata_mismatch'
        );

        $composerLock = $this->read_json_blocker($packageDir . '/composer.lock', 'composer lock', 'direct_composer_lock', $checks, $blockers);
        if ($composerLock === null) {
            return;
        }

        $lockedPackages = isset($composerLock['packages']) && is_array($composerLock['packages'])
            ? $composerLock['packages']
            : [];
        $lockedNames = [];
        foreach ($lockedPackages as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $lockedNames[] = $package['name'];
            }
        }
        sort($lockedNames, SORT_STRING);
        $dependencyOk = in_array('wp-php-toolkit/full-text-search', $lockedNames, true)
            && is_dir($packageDir . '/vendor/wp-php-toolkit/full-text-search')
            && is_file($packageDir . '/vendor/autoload.php');
        $this->record(
            $checks,
            $blockers,
            'direct_production_dependencies',
            $dependencyOk ? 'pass' : 'fail',
            $dependencyOk
                ? 'Production Composer dependencies required by the plugin are present in the package.'
                : 'Production Composer dependencies required by the plugin are missing from the package.',
            [
                'locked_runtime_dependency_present' => in_array('wp-php-toolkit/full-text-search', $lockedNames, true),
            ]
        );

        $scan = $this->scan_package_paths($packageDir);
        $prohibitedOk = $scan['paths'] === [] && $scan['sensitive_path_count'] === 0;
        $details = [];
        if ($scan['paths'] !== []) {
            $details['prohibited_paths'] = $scan['paths'];
        }
        if ($scan['sensitive_path_count'] > 0) {
            $details['sensitive_path_count'] = $scan['sensitive_path_count'];
        }
        $this->record(
            $checks,
            $blockers,
            'direct_package_prohibited_paths',
            $prohibitedOk ? 'pass' : 'fail',
            $prohibitedOk
                ? 'No prohibited release artifacts or local-only paths are present in the package.'
                : 'Package contains prohibited release artifacts, local-only paths, or redacted secret-like paths.',
            $details
        );
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     * @param array<int,array<string,mixed>> $blockers
     */
    private function check_release_zip(string $zipPath, array &$checks, array &$blockers): void
    {
        if (!is_file($zipPath)) {
            $this->record($checks, $blockers, 'direct_zip_file', 'fail', 'Release ZIP was not created.');
            return;
        }
        if (!class_exists('ZipArchive')) {
            $this->record($checks, $blockers, 'direct_zip_file', 'fail', 'ZipArchive is unavailable, so the release ZIP cannot be inspected.');
            return;
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            $this->record($checks, $blockers, 'direct_zip_file', 'fail', "Release ZIP cannot be opened; ZipArchive error {$opened}.");
            return;
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name)) {
                $names[] = $name;
            }
        }
        $zip->close();
        sort($names, SORT_STRING);

        $wrongRoot = [];
        foreach ($names as $name) {
            if (!str_starts_with($name, 'indexer/')) {
                $wrongRoot[] = $name;
            }
        }

        $requiredMissing = [];
        foreach (self::REQUIRED_PACKAGE_PATHS as $relativePath) {
            if (!in_array('indexer/' . $relativePath, $names, true)) {
                $requiredMissing[] = 'indexer/' . $relativePath;
            }
        }

        $zipOk = $wrongRoot === [] && $requiredMissing === [] && str_ends_with($zipPath, '.zip');
        $this->record(
            $checks,
            $blockers,
            'direct_zip_boundary',
            $zipOk ? 'pass' : 'fail',
            $zipOk
                ? 'Release ZIP is rooted at indexer/ and includes required runtime entrypoints.'
                : 'Release ZIP root, filename, or runtime entrypoint contract is invalid.',
            [
                'file_count' => count($names),
                'wrong_root_count' => count($wrongRoot),
                'missing_required_paths' => $requiredMissing,
            ]
        );
    }

    /**
     * @return array{paths:string[],sensitive_path_count:int}
     */
    private function scan_package_paths(string $packageDir): array
    {
        $paths = [];
        $sensitivePathCount = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packageDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = self::relative_path($packageDir, $item->getPathname());
            if ($this->is_sensitive_path($relativePath)) {
                $sensitivePathCount++;
                continue;
            }
            if (!$this->is_prohibited_package_path($relativePath, $item->isDir())) {
                continue;
            }
            $paths[] = 'indexer/' . $relativePath;
        }

        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return [
            'paths' => $paths,
            'sensitive_path_count' => $sensitivePathCount,
        ];
    }

    private function is_prohibited_package_path(string $relativePath, bool $isDir): bool
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $basename = basename($relativePath);
        if ($basename !== '' && $basename[0] === '.') {
            return true;
        }

        foreach (self::PROHIBITED_PACKAGE_PREFIXES as $blocked) {
            if ($relativePath === $blocked || str_starts_with($relativePath, $blocked . '/')) {
                return true;
            }
        }

        if (str_starts_with($relativePath, 'vendor/')) {
            foreach (explode('/', $relativePath) as $part) {
                if (in_array($part, self::VENDOR_DEVELOPMENT_DIRS, true)) {
                    return true;
                }
            }
        }

        foreach (explode('/', strtolower($relativePath)) as $part) {
            if (in_array($part, self::LOCAL_ARTIFACT_DIRS, true)) {
                return true;
            }
        }

        if (!$isDir) {
            $lower = strtolower($relativePath);
            foreach (self::LOCAL_ARTIFACT_EXTENSIONS as $extension) {
                if (str_ends_with($lower, $extension)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function is_sensitive_path(string $relativePath): bool
    {
        $basename = strtolower(basename(str_replace('\\', '/', $relativePath)));

        return $basename === ('.' . 'env') || str_ends_with($basename, '.' . 'pem');
    }

    /**
     * @return array<string,string>
     */
    private function parse_plugin_headers(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path, false, null, 0, 8192);
        if (!is_string($contents)) {
            throw new RuntimeException("Could not read plugin header from {$path}.");
        }

        $headers = [];
        foreach (['Plugin Name', 'Version', 'Requires PHP'] as $header) {
            $pattern = '/^[ \t\/*#@]*' . preg_quote($header, '/') . ':\s*(.+)$/mi';
            if (preg_match($pattern, $contents, $matches) === 1) {
                $headers[$header] = trim((string) $matches[1]);
            }
        }

        return $headers;
    }

    private function find_license_file(string $pluginSource): ?string
    {
        foreach (['LICENSE', 'LICENSE.txt', 'license.txt', 'COPYING'] as $name) {
            $path = $pluginSource . '/' . $name;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function public_asset_files(string $pluginSource): array
    {
        $assetDir = $pluginSource . '/assets';
        if (!is_dir($assetDir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($assetDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $files[] = self::relative_path($pluginSource, $item->getPathname());
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    private static function composer_license_is_public_ready(mixed $license): bool
    {
        $licenses = is_array($license) ? $license : [$license];
        $normalized = [];
        foreach ($licenses as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $normalized[] = strtolower(trim((string) $item));
        }

        if ($normalized === []) {
            return false;
        }

        foreach ($normalized as $item) {
            if ($item === '' || $item === 'proprietary' || str_contains($item, 'pending') || str_contains($item, 'unresolved')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     * @param array<int,array<string,mixed>> $blockers
     * @return array<string,mixed>|null
     */
    private function read_json_blocker(
        string $path,
        string $label,
        string $checkId,
        array &$checks,
        array &$blockers
    ): ?array {
        try {
            return self::read_json_file($path, $label);
        } catch (Throwable $e) {
            $this->record($checks, $blockers, $checkId, 'fail', ucfirst($label) . ' is missing or invalid: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function read_json_file(string $path, string $label): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("Missing {$label}: {$path}");
        }
        $contents = self::read_text_file($path, $label);
        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid {$label} JSON: {$e->getMessage()}");
        }
        if (!is_array($data)) {
            throw new RuntimeException("Invalid {$label}: expected JSON object.");
        }

        return $data;
    }

    private static function read_text_file(string $path, string $label): string
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException("Could not read {$label}: {$path}");
        }

        return $contents;
    }

    private static function existing_directory(string $path, string $label): string
    {
        $real = realpath($path);
        if (!is_string($real) || !is_dir($real)) {
            throw new RuntimeException("Missing {$label} directory: {$path}");
        }

        return rtrim($real, '/');
    }

    private static function relative_path(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }

        return ltrim($path, '/');
    }

    private static function display_path(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     * @param array<int,array<string,mixed>> $blockers
     * @param array<string,mixed> $details
     */
    private function record(
        array &$checks,
        array &$blockers,
        string $id,
        string $status,
        string $message,
        array $details = [],
        ?string $blockerId = null
    ): void {
        $check = [
            'id' => $id,
            'status' => $status,
            'message' => $message,
        ];
        if ($details !== []) {
            $check['details'] = $details;
        }
        $checks[] = $check;

        if ($status !== 'fail') {
            return;
        }

        $blocker = [
            'id' => $blockerId ?? $id,
            'message' => $message,
        ];
        if ($details !== []) {
            $blocker['details'] = $details;
        }
        $blockers[] = $blocker;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $options = WP_FTS_ReleaseReadinessChecker::parse_cli_options(array_slice($argv, 1));
        if (!empty($options['help'])) {
            fwrite(STDOUT, WP_FTS_ReleaseReadinessChecker::usage());
            exit(0);
        }

        $format = (string) ($options['format'] ?? 'json');
        if (!in_array($format, ['json', 'text'], true)) {
            throw new InvalidArgumentException("Unknown output format: {$format}");
        }

        $report = (new WP_FTS_ReleaseReadinessChecker())->check($options);
        fwrite(
            STDOUT,
            $format === 'text'
                ? WP_FTS_ReleaseReadinessChecker::render_text($report)
                : WP_FTS_ReleaseReadinessChecker::render_json($report)
        );
        exit(($report['status'] ?? null) === 'ready' ? 0 : 1);
    } catch (Throwable $e) {
        fwrite(STDERR, "Release readiness check failed: {$e->getMessage()}\n");
        exit(2);
    }
}
