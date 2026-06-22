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
    private const READINESS_BUILD_DIR_PREFIX = 'wp-fts-indexer-release-readiness-';
    private const PUBLIC_SUBMISSION_EVIDENCE_PATH = 'docs/public-submission-readiness.json';

    private const PUBLIC_README_REQUIRED_FIELDS = [
        'Contributors',
        'Tags',
        'Requires at least',
        'Tested up to',
        'Requires PHP',
        'Stable tag',
        'License',
        'License URI',
    ];

    private const PUBLIC_README_REQUIRED_SECTIONS = [
        'Description',
        'Installation',
        'FAQ',
        'Changelog',
    ];

    private const PUBLIC_SUBMISSION_REQUIRED_EVIDENCE_CHECKS = [
        'readme',
        'license',
        'assets',
        'public_submission_authority',
    ];

    private const PUBLIC_SUBMISSION_REQUIRED_PNG_ASSETS = [
        'assets/banner-772x250.png' => [
            'kind' => 'banner',
            'width' => 772,
            'height' => 250,
        ],
        'assets/icon-128x128.png' => [
            'kind' => 'icon',
            'width' => 128,
            'height' => 128,
        ],
    ];

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";
    private const PNG_MAX_ANCILLARY_BYTES = 262144;
    private const PNG_MIN_IDAT_BYTES_LIMIT = 262144;
    private const PNG_MAX_BYTES_PER_PIXEL = 8;

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
        if (!isset($options['build_dir']) && !isset($options['output'])) {
            $buildOptions['build_dir'] = self::default_readiness_build_dir($pluginSource, $monorepoRoot);
        }
        foreach (['build_dir', 'output'] as $key) {
            if (isset($options[$key])) {
                $buildOptions[$key] = (string) $options[$key];
            }
        }

        $buildAndValidate = function () use ($buildOptions, $sourceMetadata, &$checks, &$blockers): void {
            $lockedBuildOptions = $buildOptions;
            $lockedBuildOptions['skip_build_lock'] = true;

            /** @var array{build_dir:string,zip_path:string,sha256:string,removed_paths:string[],prohibited_paths:string[]} $build */
            $build = (new WP_FTS_ReleasePackageBuilder())->build($lockedBuildOptions);
            $this->record($checks, $blockers, 'direct_package_build', 'pass', 'Direct-install ZIP builder completed.', [
                'zip_path' => self::display_path($build['zip_path']),
                'sha256' => $build['sha256'],
                'removed_paths_count' => count($build['removed_paths']),
            ]);

            $stagePlugin = $build['build_dir'] . '/indexer';
            $this->check_direct_package_directory($stagePlugin, $sourceMetadata, $checks, $blockers);
            $this->check_release_zip($build['zip_path'], $checks, $blockers);
        };

        try {
            if (isset($buildOptions['build_dir'])) {
                WP_FTS_ReleasePackageBuilder::with_build_lock((string) $buildOptions['build_dir'], $buildAndValidate);
            } else {
                $buildAndValidate();
            }
        } catch (Throwable $e) {
            $this->record($checks, $blockers, 'direct_package_build', 'fail', 'Direct-install ZIP builder failed: ' . $e->getMessage());
            return;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     * @param array<int,array<string,mixed>> $blockers
     */
    private function check_public_submission(string $pluginSource, array &$checks, array &$blockers): void
    {
        $sourceMetadata = $this->check_plugin_metadata($pluginSource, $checks, $blockers, 'public_source');

        $this->check_public_readme_txt($pluginSource, $sourceMetadata['version'], $checks, $blockers);
        $this->check_public_license_file($pluginSource, $checks, $blockers);
        $this->check_public_assets($pluginSource, $checks, $blockers);

        $composer = $this->read_json_blocker($pluginSource . '/composer.json', 'composer metadata', 'composer_public_license', $checks, $blockers);
        $license = is_array($composer) ? ($composer['license'] ?? null) : null;
        $publicLicenseOk = is_array($composer) && self::composer_license_is_public_ready($license);
        $this->record(
            $checks,
            $blockers,
            'composer_public_license',
            $publicLicenseOk ? 'pass' : 'fail',
            $publicLicenseOk
                ? 'Composer license metadata declares a GPL-compatible public redistribution license.'
                : 'Composer license metadata is missing, proprietary, unresolved, or not GPL-compatible for public redistribution.',
            ['license' => $license]
        );

        $this->check_public_submission_docs($pluginSource, $checks, $blockers);
        $this->check_public_submission_evidence($pluginSource, $checks, $blockers);
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     * @param array<int,array<string,mixed>> $blockers
     */
    private function check_public_readme_txt(
        string $pluginSource,
        ?string $sourceVersion,
        array &$checks,
        array &$blockers
    ): void {
        $readmePath = $pluginSource . '/readme.txt';
        if (!is_file($readmePath)) {
            $this->record($checks, $blockers, 'package_readme_txt', 'fail', 'Missing package-level readme.txt with public marketplace metadata.');
            return;
        }

        $readme = self::read_text_file($readmePath, 'public readme');
        $fields = self::parse_readme_fields($readme);
        $missingFields = [];
        foreach (self::PUBLIC_README_REQUIRED_FIELDS as $field) {
            $key = strtolower($field);
            if (!isset($fields[$key]) || trim($fields[$key]) === '') {
                $missingFields[] = $field;
            }
        }

        $missingSections = [];
        foreach (self::PUBLIC_README_REQUIRED_SECTIONS as $section) {
            if (!self::readme_section_has_reviewable_content($readme, $section)) {
                $missingSections[] = $section;
            }
        }

        $stableTag = isset($fields['stable tag']) ? trim($fields['stable tag']) : null;
        $stableMatchesSource = $sourceVersion !== null && $stableTag === $sourceVersion;
        $licenseIdentifier = $fields['license'] ?? null;
        $licenseOk = self::public_license_identifier_is_gpl_compatible($licenseIdentifier);
        $hasPluginHeading = preg_match('/^===\s+.+\s+===\s*$/m', $readme) === 1;
        $readmeOk = $hasPluginHeading
            && $missingFields === []
            && $missingSections === []
            && $stableMatchesSource
            && $licenseOk;

        $details = [
            'stable_tag' => $stableTag,
            'source_version' => $sourceVersion,
            'license' => $licenseIdentifier,
        ];
        if (!$hasPluginHeading) {
            $details['missing_heading'] = true;
        }
        if ($missingFields !== []) {
            $details['missing_fields'] = $missingFields;
        }
        if ($missingSections !== []) {
            $details['missing_sections'] = $missingSections;
        }

        $this->record(
            $checks,
            $blockers,
            'package_readme_txt',
            $readmeOk ? 'pass' : 'fail',
            $readmeOk
                ? 'Package-level readme.txt has complete WordPress.org-style metadata and reviewable sections.'
                : 'Package-level readme.txt is missing complete WordPress.org-style metadata, matching Stable tag, GPL-compatible license metadata, or reviewable sections.',
            $details
        );
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     * @param array<int,array<string,mixed>> $blockers
     */
    private function check_public_license_file(string $pluginSource, array &$checks, array &$blockers): void
    {
        $licensePath = $this->find_license_file($pluginSource);
        if ($licensePath === null) {
            $this->record($checks, $blockers, 'package_license_file', 'fail', 'Missing package-level license file for public redistribution review.');
            return;
        }

        $licenseText = self::read_text_file($licensePath, 'public license file');
        $licenseOk = self::public_license_text_is_gpl_compatible($licenseText)
            && !self::contains_placeholder_marker($licenseText);
        $this->record(
            $checks,
            $blockers,
            'package_license_file',
            $licenseOk ? 'pass' : 'fail',
            $licenseOk
                ? 'Package-level license file contains GPL-compatible public redistribution terms.'
                : 'Package-level license file does not contain reviewable GPL-compatible public redistribution terms.',
            ['path' => basename($licensePath)]
        );
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     * @param array<int,array<string,mixed>> $blockers
     */
    private function check_public_assets(string $pluginSource, array &$checks, array &$blockers): void
    {
        $assetFiles = $this->public_asset_files($pluginSource);
        $recognized = [];
        $invalid = [];
        $missingKinds = [];

        foreach (self::PUBLIC_SUBMISSION_REQUIRED_PNG_ASSETS as $relativePath => $expected) {
            $path = $pluginSource . '/' . $relativePath;
            if (!is_file($path)) {
                $missingKinds[] = $expected['kind'];
                continue;
            }

            $validation = self::validate_required_public_png_asset($path, $expected);
            if (($validation['ok'] ?? false) !== true) {
                $failure = ['path' => $relativePath];
                foreach ($validation as $key => $value) {
                    if ($key !== 'ok') {
                        $failure[$key] = $value;
                    }
                }
                $invalid[] = $failure;
                continue;
            }

            $recognized[] = $relativePath;
        }

        sort($recognized, SORT_STRING);
        usort(
            $invalid,
            static fn(array $a, array $b): int => strcmp((string) ($a['path'] ?? ''), (string) ($b['path'] ?? ''))
        );
        sort($missingKinds, SORT_STRING);

        $assetsOk = $missingKinds === [] && $invalid === [];
        $details = [
            'asset_file_count' => count($assetFiles),
            'recognized_asset_files' => $recognized,
        ];
        if ($missingKinds !== []) {
            $details['missing_required_asset_kinds'] = $missingKinds;
        }
        if ($invalid !== []) {
            $details['invalid_asset_files'] = $invalid;
        }

        $this->record(
            $checks,
            $blockers,
            'package_public_assets',
            $assetsOk ? 'pass' : 'fail',
            $assetsOk
                ? 'Public-submission asset directory contains valid WordPress.org-style PNG banner and icon assets.'
                : 'Public-submission assets must include non-placeholder PNG banner and icon image files with exact WordPress.org dimensions.',
            $details
        );
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     * @param array<int,array<string,mixed>> $blockers
     */
    private function check_public_submission_docs(string $pluginSource, array &$checks, array &$blockers): void
    {
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
     */
    private function check_public_submission_evidence(string $pluginSource, array &$checks, array &$blockers): void
    {
        $evidencePath = $pluginSource . '/' . self::PUBLIC_SUBMISSION_EVIDENCE_PATH;
        if (!is_file($evidencePath)) {
            $this->record(
                $checks,
                $blockers,
                'public_submission_authority_evidence',
                'fail',
                'Missing completed public-submission authority evidence file.',
                ['expected_path' => self::PUBLIC_SUBMISSION_EVIDENCE_PATH]
            );
            return;
        }

        $evidence = $this->read_json_blocker($evidencePath, 'public-submission authority evidence', 'public_submission_authority_evidence', $checks, $blockers);
        if ($evidence === null) {
            return;
        }

        $target = isset($evidence['target']) && is_scalar($evidence['target']) ? strtolower(trim((string) $evidence['target'])) : '';
        $approver = isset($evidence['approver']) && is_scalar($evidence['approver']) ? trim((string) $evidence['approver']) : '';
        $reviewedAt = isset($evidence['reviewed_at']) && is_scalar($evidence['reviewed_at']) ? trim((string) $evidence['reviewed_at']) : '';
        $evidenceChecks = isset($evidence['checks']) && is_array($evidence['checks']) ? $evidence['checks'] : [];
        $missingEvidenceChecks = [];
        foreach (self::PUBLIC_SUBMISSION_REQUIRED_EVIDENCE_CHECKS as $check) {
            if (($evidenceChecks[$check] ?? null) !== true) {
                $missingEvidenceChecks[] = $check;
            }
        }

        $targetOk = in_array($target, ['wordpress.org', 'wordpress.org-plugin-directory', 'public-submission'], true);
        $approverOk = $approver !== '' && !self::contains_placeholder_marker($approver);
        $reviewedAtOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewedAt) === 1;
        $evidenceOk = ($evidence['status'] ?? null) === 'approved'
            && $targetOk
            && $approverOk
            && $reviewedAtOk
            && $missingEvidenceChecks === [];

        $details = [
            'path' => self::PUBLIC_SUBMISSION_EVIDENCE_PATH,
            'status' => $evidence['status'] ?? null,
            'target' => $target,
            'reviewed_at' => $reviewedAt,
        ];
        if ($missingEvidenceChecks !== []) {
            $details['missing_or_unapproved_checks'] = $missingEvidenceChecks;
        }

        $this->record(
            $checks,
            $blockers,
            'public_submission_authority_evidence',
            $evidenceOk ? 'pass' : 'fail',
            $evidenceOk
                ? 'Public-submission authority evidence records approved readme, license, asset, and submission checks.'
                : 'Public-submission authority evidence is missing approval status, target, approver, review date, or required approved checks.',
            $details
        );
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

    /**
     * @param array{kind:string,width:int,height:int} $expected
     * @return array<string,mixed>
     */
    private static function validate_required_public_png_asset(string $path, array $expected): array
    {
        $metadata = self::read_public_png_metadata($path, $expected);
        if (($metadata['ok'] ?? false) !== true) {
            return $metadata;
        }

        $actualWidth = (int) $metadata['width'];
        $actualHeight = (int) $metadata['height'];
        $details = [
            'width' => $actualWidth,
            'height' => $actualHeight,
            'expected_width' => $expected['width'],
            'expected_height' => $expected['height'],
        ];

        if ($actualWidth <= 1 || $actualHeight <= 1) {
            return $details + [
                'ok' => false,
                'reason' => 'trivial_dimensions',
            ];
        }

        if ($actualWidth !== $expected['width'] || $actualHeight !== $expected['height']) {
            return $details + [
                'ok' => false,
                'reason' => 'wrong_dimensions',
            ];
        }

        $originality = self::public_png_originality_result($path, $metadata);
        if (($originality['ok'] ?? false) !== true) {
            return $details + [
                'ok' => false,
                'reason' => (string) ($originality['reason'] ?? 'png_originality_unverified'),
            ];
        }

        if (($originality['single_color'] ?? null) === true) {
            return $details + [
                'ok' => false,
                'reason' => 'blank_single_color',
            ];
        }

        return $details + ['ok' => true];
    }

    /**
     * @return array<string,mixed>
     */
    private static function read_public_png_metadata(string $path, array $expected): array
    {
        $limits = self::public_png_limits($expected);
        clearstatcache(true, $path);
        $fileSize = filesize($path);
        if (!is_int($fileSize) || $fileSize < 33) {
            return [
                'ok' => false,
                'reason' => 'malformed_png',
            ];
        }
        if ($fileSize > $limits['file_bytes']) {
            return [
                'ok' => false,
                'reason' => 'oversized_payload',
            ];
        }

        $bytes = file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) < 33) {
            return [
                'ok' => false,
                'reason' => 'malformed_png',
            ];
        }

        if (!str_starts_with($bytes, self::PNG_SIGNATURE)) {
            return [
                'ok' => false,
                'reason' => 'malformed_png',
            ];
        }

        $length = strlen($bytes);
        $offset = strlen(self::PNG_SIGNATURE);
        $width = null;
        $height = null;
        $bitDepth = null;
        $colorType = null;
        $idat = '';
        $totalIdatBytes = 0;
        $paletteColorCount = null;
        $sawIend = false;
        $chunkIndex = 0;

        while ($offset + 12 <= $length) {
            $chunkLengthData = substr($bytes, $offset, 4);
            $chunkLength = unpack('Nlength', $chunkLengthData)['length'];
            $chunkType = substr($bytes, $offset + 4, 4);
            $dataOffset = $offset + 8;
            $nextOffset = $dataOffset + $chunkLength + 4;
            if (!is_int($chunkLength) || $chunkLength < 0 || $nextOffset > $length || !preg_match('/^[A-Za-z]{4}$/', $chunkType)) {
                return [
                    'ok' => false,
                    'reason' => 'malformed_png',
                ];
            }
            if ($chunkIndex === 0 && $chunkType !== 'IHDR') {
                return [
                    'ok' => false,
                    'reason' => 'malformed_png',
                ];
            }

            $chunkData = substr($bytes, $dataOffset, $chunkLength);
            $actualCrc = unpack('Ncrc', substr($bytes, $dataOffset + $chunkLength, 4))['crc'];
            if ($actualCrc !== self::png_crc32($chunkType . $chunkData)) {
                return [
                    'ok' => false,
                    'reason' => 'checksum_mismatch',
                    'chunk_type' => $chunkType,
                ];
            }

            if ($chunkType === 'IHDR') {
                if ($chunkIndex !== 0 || $chunkLength !== 13) {
                    return [
                        'ok' => false,
                        'reason' => 'malformed_png',
                    ];
                }
                $header = unpack('Nwidth/Nheight/Cbit_depth/Ccolor_type/Ccompression/Cfilter/Cinterlace', $chunkData);
                $width = (int) $header['width'];
                $height = (int) $header['height'];
                $bitDepth = (int) $header['bit_depth'];
                $colorType = (int) $header['color_type'];
                if ($width <= 0 || $height <= 0 || $header['compression'] !== 0 || $header['filter'] !== 0 || $header['interlace'] !== 0) {
                    return [
                        'ok' => false,
                        'reason' => 'malformed_png',
                    ];
                }
            } elseif ($chunkType === 'PLTE') {
                if ($chunkLength === 0 || $chunkLength % 3 !== 0 || $chunkLength > 768) {
                    return [
                        'ok' => false,
                        'reason' => 'malformed_png',
                    ];
                }
                $paletteColorCount = intdiv($chunkLength, 3);
            } elseif ($chunkType === 'IDAT') {
                $totalIdatBytes += $chunkLength;
                if ($totalIdatBytes > $limits['idat_bytes']) {
                    return [
                        'ok' => false,
                        'reason' => 'oversized_payload',
                    ];
                }
                $idat .= $chunkData;
            } elseif ($chunkType === 'IEND') {
                if ($chunkLength !== 0) {
                    return [
                        'ok' => false,
                        'reason' => 'malformed_png',
                    ];
                }
                $sawIend = true;
                $offset = $nextOffset;
                break;
            }

            $offset = $nextOffset;
            $chunkIndex++;
        }

        if ($width === null || $height === null || $bitDepth === null || $colorType === null || $idat === '' || !$sawIend || $offset !== $length) {
            return [
                'ok' => false,
                'reason' => 'malformed_png',
            ];
        }

        return [
            'ok' => true,
            'width' => $width,
            'height' => $height,
            'bit_depth' => $bitDepth,
            'color_type' => $colorType,
            'idat' => $idat,
            'max_decoded_bytes' => $limits['decoded_bytes'],
            'palette_color_count' => $paletteColorCount,
        ];
    }

    /**
     * @param array{kind:string,width:int,height:int} $expected
     * @return array{file_bytes:int,idat_bytes:int,decoded_bytes:int}
     */
    private static function public_png_limits(array $expected): array
    {
        $decodedBytes = $expected['height'] * (1 + ($expected['width'] * self::PNG_MAX_BYTES_PER_PIXEL));
        $idatBytes = max(
            self::PNG_MIN_IDAT_BYTES_LIMIT,
            $decodedBytes + intdiv($decodedBytes, 8) + 65536
        );

        return [
            'file_bytes' => $idatBytes + self::PNG_MAX_ANCILLARY_BYTES + 128,
            'idat_bytes' => $idatBytes,
            'decoded_bytes' => $decodedBytes,
        ];
    }

    private static function png_crc32(string $bytes): int
    {
        $crc = crc32($bytes);
        if ($crc < 0) {
            $crc += 4294967296;
        }

        return $crc;
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array{ok:bool,single_color?:bool,reason?:string}
     */
    private static function public_png_originality_result(string $path, array $metadata): array
    {
        if (($metadata['width'] ?? 0) <= 1 || ($metadata['height'] ?? 0) <= 1) {
            return [
                'ok' => true,
                'single_color' => true,
            ];
        }

        if (($metadata['color_type'] ?? null) === 3 && ($metadata['palette_color_count'] ?? 2) <= 1) {
            return [
                'ok' => true,
                'single_color' => true,
            ];
        }

        $expectedDecodedBytes = self::public_png_decoded_byte_length(
            (int) $metadata['width'],
            (int) $metadata['height'],
            (int) $metadata['bit_depth'],
            (int) $metadata['color_type']
        );
        $decodeFailureReason = null;
        if ($expectedDecodedBytes !== null && $expectedDecodedBytes <= (int) ($metadata['max_decoded_bytes'] ?? 0)) {
            $decoded = self::decode_public_png_image_data((string) ($metadata['idat'] ?? ''), $expectedDecodedBytes);
            if (($decoded['ok'] ?? false) === true) {
                $decodedBytes = (string) ($decoded['bytes'] ?? '');
                if (strlen($decodedBytes) !== $expectedDecodedBytes) {
                    return [
                        'ok' => false,
                        'reason' => 'malformed_png',
                    ];
                }

                $singleColor = self::decoded_public_png_is_single_color(
                    $decodedBytes,
                    (int) $metadata['width'],
                    (int) $metadata['height'],
                    (int) $metadata['bit_depth'],
                    (int) $metadata['color_type']
                );
                if ($singleColor === null) {
                    return [
                        'ok' => false,
                        'reason' => 'malformed_png',
                    ];
                }

                return [
                    'ok' => true,
                    'single_color' => $singleColor,
                ];
            }

            $decodeFailureReason = (string) ($decoded['reason'] ?? 'malformed_png');
            if (function_exists('gzuncompress') || in_array($decodeFailureReason, ['checksum_mismatch', 'oversized_payload'], true)) {
                return [
                    'ok' => false,
                    'reason' => $decodeFailureReason,
                ];
            }
        }

        $gdResult = self::public_png_gd_is_single_color($path);
        if ($gdResult !== null) {
            return [
                'ok' => true,
                'single_color' => $gdResult,
            ];
        }

        return [
            'ok' => false,
            'reason' => $decodeFailureReason ?? 'png_originality_unverified',
        ];
    }

    private static function public_png_gd_is_single_color(string $path): ?bool
    {
        if (!function_exists('imagecreatefrompng') || !function_exists('imagesx') || !function_exists('imagesy') || !function_exists('imagecolorat')) {
            return null;
        }

        $image = @imagecreatefrompng($path);
        if (!is_object($image) && !is_resource($image)) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= 0 || $height <= 0) {
            if (function_exists('imagedestroy')) {
                imagedestroy($image);
            }
            return null;
        }

        $first = imagecolorat($image, 0, 0);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if (imagecolorat($image, $x, $y) !== $first) {
                    if (function_exists('imagedestroy')) {
                        imagedestroy($image);
                    }
                    return false;
                }
            }
        }

        if (function_exists('imagedestroy')) {
            imagedestroy($image);
        }

        return true;
    }

    /**
     * @return array{ok:bool,bytes?:string,reason?:string}
     */
    private static function decode_public_png_image_data(string $idat, int $maxDecodedBytes): array
    {
        if ($idat === '' || $maxDecodedBytes <= 0) {
            return [
                'ok' => false,
                'reason' => 'malformed_png',
            ];
        }

        if (function_exists('gzuncompress')) {
            $decoded = @gzuncompress($idat, $maxDecodedBytes + 1);
            if (is_string($decoded)) {
                if (strlen($decoded) > $maxDecodedBytes) {
                    return [
                        'ok' => false,
                        'reason' => 'oversized_payload',
                    ];
                }

                return [
                    'ok' => true,
                    'bytes' => $decoded,
                ];
            }
        }

        return self::decode_zlib_stored_blocks($idat, $maxDecodedBytes);
    }

    /**
     * @return array{ok:bool,bytes?:string,reason?:string}
     */
    private static function decode_zlib_stored_blocks(string $stream, int $maxDecodedBytes): array
    {
        if (strlen($stream) < 6) {
            return [
                'ok' => false,
                'reason' => 'malformed_png',
            ];
        }

        $cmf = ord($stream[0]);
        $flg = ord($stream[1]);
        if (($cmf & 0x0f) !== 8 || ($cmf >> 4) > 7 || (($cmf * 256 + $flg) % 31) !== 0 || ($flg & 0x20) !== 0) {
            return [
                'ok' => false,
                'reason' => 'malformed_png',
            ];
        }

        $deflate = substr($stream, 2, -4);
        $expectedAdler = unpack('Nadler', substr($stream, -4))['adler'];
        $offset = 0;
        $length = strlen($deflate);
        $decoded = '';
        $sawFinal = false;

        while ($offset < $length) {
            $header = ord($deflate[$offset]);
            $offset++;
            $isFinal = ($header & 1) === 1;
            $blockType = ($header >> 1) & 3;
            if ($blockType !== 0 || $offset + 4 > $length) {
                return [
                    'ok' => false,
                    'reason' => 'malformed_png',
                ];
            }

            $block = unpack('vlength/vnlength', substr($deflate, $offset, 4));
            $offset += 4;
            $blockLength = (int) $block['length'];
            $blockNLength = (int) $block['nlength'];
            if ((($blockLength ^ 0xffff) & 0xffff) !== $blockNLength || $offset + $blockLength > $length) {
                return [
                    'ok' => false,
                    'reason' => 'malformed_png',
                ];
            }
            if (strlen($decoded) + $blockLength > $maxDecodedBytes) {
                return [
                    'ok' => false,
                    'reason' => 'oversized_payload',
                ];
            }

            $decoded .= substr($deflate, $offset, $blockLength);
            $offset += $blockLength;
            if ($isFinal) {
                $sawFinal = true;
                break;
            }
        }

        if (!$sawFinal || $offset !== $length) {
            return [
                'ok' => false,
                'reason' => 'malformed_png',
            ];
        }
        if (self::adler32($decoded) !== $expectedAdler) {
            return [
                'ok' => false,
                'reason' => 'checksum_mismatch',
            ];
        }

        return [
            'ok' => true,
            'bytes' => $decoded,
        ];
    }

    private static function adler32(string $bytes): int
    {
        $a = 1;
        $b = 0;
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $a = ($a + ord($bytes[$i])) % 65521;
            $b = ($b + $a) % 65521;
        }

        return (($b << 16) | $a) & 0xffffffff;
    }

    private static function public_png_decoded_byte_length(int $width, int $height, int $bitDepth, int $colorType): ?int
    {
        $channels = match ($colorType) {
            0, 3 => 1,
            2 => 3,
            4 => 2,
            6 => 4,
            default => null,
        };
        if ($channels === null || !in_array($bitDepth, [8, 16], true)) {
            return null;
        }

        return $height * (1 + ($width * $channels * intdiv($bitDepth, 8)));
    }

    private static function decoded_public_png_is_single_color(
        string $decoded,
        int $width,
        int $height,
        int $bitDepth,
        int $colorType
    ): ?bool {
        $channels = match ($colorType) {
            0, 3 => 1,
            2 => 3,
            4 => 2,
            6 => 4,
            default => null,
        };
        if ($channels === null || !in_array($bitDepth, [8, 16], true)) {
            return null;
        }

        $bitsPerPixel = $channels * $bitDepth;
        $bytesPerPixel = intdiv($bitsPerPixel, 8);
        $scanlineLength = $width * $bytesPerPixel;
        $filterByteWidth = max(1, $bytesPerPixel);
        $offset = 0;
        $previous = str_repeat("\0", $scanlineLength);
        $firstPixel = null;

        for ($y = 0; $y < $height; $y++) {
            if ($offset + 1 + $scanlineLength > strlen($decoded)) {
                return null;
            }

            $filter = ord($decoded[$offset]);
            $offset++;
            $raw = substr($decoded, $offset, $scanlineLength);
            $offset += $scanlineLength;
            $scanline = self::unfilter_png_scanline($filter, $raw, $previous, $filterByteWidth);
            if ($scanline === null) {
                return null;
            }

            for ($x = 0; $x < $width; $x++) {
                $pixel = substr($scanline, $x * $bytesPerPixel, $bytesPerPixel);
                if ($firstPixel === null) {
                    $firstPixel = $pixel;
                    continue;
                }
                if ($pixel !== $firstPixel) {
                    return false;
                }
            }

            $previous = $scanline;
        }

        return true;
    }

    private static function unfilter_png_scanline(int $filter, string $raw, string $previous, int $bytesPerPixel): ?string
    {
        if ($filter === 0) {
            return $raw;
        }

        $length = strlen($raw);
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $x = ord($raw[$i]);
            $left = $i >= $bytesPerPixel ? ord($out[$i - $bytesPerPixel]) : 0;
            $up = ord($previous[$i] ?? "\0");
            $upLeft = $i >= $bytesPerPixel ? ord($previous[$i - $bytesPerPixel] ?? "\0") : 0;

            $value = match ($filter) {
                1 => $x + $left,
                2 => $x + $up,
                3 => $x + intdiv($left + $up, 2),
                4 => $x + self::png_paeth_predictor($left, $up, $upLeft),
                default => null,
            };
            if ($value === null) {
                return null;
            }
            $out .= chr($value & 0xff);
        }

        return $out;
    }

    private static function png_paeth_predictor(int $left, int $up, int $upLeft): int
    {
        $estimate = $left + $up - $upLeft;
        $leftDistance = abs($estimate - $left);
        $upDistance = abs($estimate - $up);
        $upLeftDistance = abs($estimate - $upLeft);

        if ($leftDistance <= $upDistance && $leftDistance <= $upLeftDistance) {
            return $left;
        }
        if ($upDistance <= $upLeftDistance) {
            return $up;
        }

        return $upLeft;
    }

    /**
     * @return array<string,string>
     */
    private static function parse_readme_fields(string $readme): array
    {
        $fields = [];
        if (preg_match_all('/^([A-Za-z][A-Za-z ]+):\s*(.+)$/m', $readme, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $fields[strtolower(trim($match[1]))] = trim($match[2]);
            }
        }

        return $fields;
    }

    private static function readme_section_has_reviewable_content(string $readme, string $section): bool
    {
        $pattern = '/^==\s*' . preg_quote($section, '/') . '\s*==\s*$'
            . '(.*?)'
            . '(?=^==\s*.+?\s*==\s*$|\z)/ims';
        if (preg_match($pattern, $readme, $matches) !== 1) {
            return false;
        }

        $contents = trim(strip_tags((string) $matches[1]));
        if (self::contains_placeholder_marker($contents)) {
            return false;
        }

        return strlen($contents) >= 20;
    }

    private static function public_asset_kind(string $relativePath): ?string
    {
        $name = strtolower(basename(str_replace('\\', '/', $relativePath)));
        if (preg_match('/^banner-(?:772x250|1544x500)\.(?:png|jpe?g)$/', $name) === 1) {
            return 'banner';
        }
        if (preg_match('/^icon-(?:128x128|256x256)\.(?:png|jpe?g)$/', $name) === 1 || $name === 'icon.svg') {
            return 'icon';
        }
        if (preg_match('/^screenshot-[1-9][0-9]*\.(?:png|jpe?g)$/', $name) === 1) {
            return 'screenshot';
        }

        return null;
    }

    private static function public_asset_has_valid_signature(string $path, string $relativePath): bool
    {
        $bytes = file_get_contents($path, false, null, 0, 512);
        if (!is_string($bytes) || $bytes === '') {
            return false;
        }

        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if ($extension === 'png') {
            return str_starts_with($bytes, "\x89PNG\r\n\x1a\n");
        }
        if ($extension === 'jpg' || $extension === 'jpeg') {
            return str_starts_with($bytes, "\xff\xd8\xff");
        }
        if ($extension === 'svg') {
            return preg_match('/<svg(?:\s|>)/i', ltrim($bytes)) === 1;
        }

        return false;
    }

    private static function public_license_identifier_is_gpl_compatible(mixed $license): bool
    {
        if (!is_scalar($license)) {
            return false;
        }

        $normalized = strtolower(trim((string) $license));
        if ($normalized === '' || self::contains_placeholder_marker($normalized)) {
            return false;
        }

        return in_array($normalized, [
            'gpl-2.0',
            'gpl-2.0+',
            'gpl-2.0-only',
            'gpl-2.0-or-later',
            'gpl-3.0',
            'gpl-3.0+',
            'gpl-3.0-only',
            'gpl-3.0-or-later',
            'gplv2',
            'gplv2 or later',
            'gplv3',
            'gplv3 or later',
        ], true);
    }

    private static function public_license_text_is_gpl_compatible(string $licenseText): bool
    {
        return preg_match(
            '/GNU GENERAL PUBLIC LICENSE|GPL-2\.0(?:-or-later|\+)?|GPL-3\.0(?:-or-later|\+)?|GPLv?2(?:\.0)?\s+or\s+later/i',
            $licenseText
        ) === 1;
    }

    private static function contains_placeholder_marker(string $text): bool
    {
        $normalized = strtolower(trim($text));
        if ($normalized === '') {
            return true;
        }

        foreach (['placeholder', 'todo', 'tbd', 'pending', 'unresolved', 'lorem ipsum'] as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
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

        $hasCompatibleLicense = false;
        foreach ($normalized as $item) {
            if ($item === '' || $item === 'proprietary' || str_contains($item, 'pending') || str_contains($item, 'unresolved')) {
                return false;
            }
            if (self::public_license_identifier_is_gpl_compatible($item)) {
                $hasCompatibleLicense = true;
            }
        }

        return $hasCompatibleLicense;
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

    private static function default_readiness_build_dir(string $pluginSource, string $monorepoRoot): string
    {
        $fingerprint = substr(hash('sha256', $pluginSource . "\0" . $monorepoRoot), 0, 16);

        return sys_get_temp_dir() . '/' . self::READINESS_BUILD_DIR_PREFIX . $fingerprint;
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
