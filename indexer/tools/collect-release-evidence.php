<?php
declare(strict_types=1);

require_once __DIR__ . '/php-source-token-stream.php';

/**
 * Read-only release evidence collector for current-main review.
 *
 * The collector intentionally normalizes existing release, smoke, integration,
 * and benchmark commands into one bounded JSON report. It does not build
 * direct-install artifacts by default and does not enable WordPress writes; any
 * write-enabled lane must still satisfy the existing lane-specific opt-in
 * guards before the delegated tool can mutate a disposable environment.
 */
final class WP_FTS_ReleaseEvidenceCollector
{
    public const SCHEMA = 'wp-fts-release-evidence-bundle-v1';
    public const REAL_INTEGRATION_OPT_IN_ENV = 'WP_FTS_EVIDENCE_RUN_REAL_WORDPRESS_MYSQL';

    private const TARGET_DIRECT_INSTALL = 'direct-install';
    private const TARGET_PUBLIC_SUBMISSION = 'public-submission';
    private const OUTPUT_EXCERPT_BYTES = 1200;
    private const RAW_OUTPUT_CAPTURE_BYTES = 12000;
    private const HISTORICAL_BUILDER_SOURCE_BYTES = 1048576;
    private const DEFAULT_TIMEOUT_SECONDS = 90;
    private const PREVIOUS_PACKAGE_TEMP_PREFIX = 'wp-fts-previous-direct-package-';
    private const PREVIOUS_PACKAGE_ARCHIVE_PATHS = ['indexer', 'components/full-text-search'];
    private const PREVIOUS_PACKAGE_REQUIRED_PATHS = [
        'indexer/tools/build-release-zip.php',
        'indexer/.distignore',
        'indexer/composer.json',
        'indexer/composer.lock',
        'components/full-text-search/composer.json',
    ];

    /** @var callable(array<int,string>, string, int): array{exit:int,stdout:string,stderr:string,timed_out?:bool,stdout_truncated?:bool,stderr_truncated?:bool} */
    private $processRunner;

    /** @var array<string,string> */
    private array $env;

    /**
     * @param callable(array<int,string>, string, int): array{exit:int,stdout:string,stderr:string,timed_out?:bool,stdout_truncated?:bool,stderr_truncated?:bool}|null $processRunner
     * @param array<string,string>|null $env
     */
    public function __construct(?callable $processRunner = null, ?array $env = null)
    {
        $this->processRunner = $processRunner ?? [$this, 'default_process_runner'];
        $this->env = $env ?? self::current_environment();
    }

    /**
     * @param array<int,string> $args
     * @return array<string,mixed>
     */
    public static function parse_cli_options(array $args): array
    {
        $options = [
            'format' => 'json',
            'release_target' => self::TARGET_DIRECT_INSTALL,
            'timeout' => self::DEFAULT_TIMEOUT_SECONDS,
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
            if ($arg === '--run-direct-install-readiness') {
                $options['run_direct_install_readiness'] = true;
                continue;
            }
            if ($arg === '--run-real-wordpress-mysql') {
                $options['run_real_wordpress_mysql'] = true;
                continue;
            }
            if ($arg === '--run-docker-disposable-smokes') {
                $options['run_docker_disposable_smokes'] = true;
                continue;
            }
            if ($arg === '--run-docker-lifecycle-smokes') {
                $options['run_docker_lifecycle_smokes'] = true;
                continue;
            }
            if ($arg === '--run-docker-upgrade-multisite-smoke') {
                $options['run_docker_upgrade_multisite_smoke'] = true;
                continue;
            }

            foreach (['format', 'release-target', 'plugin-src', 'monorepo-root', 'direct-package-dir', 'previous-direct-package', 'previous-direct-package-ref', 'timeout'] as $name) {
                $prefix = "--{$name}=";
                if (str_starts_with($arg, $prefix)) {
                    $key = str_replace('-', '_', $name);
                    $options[$key] = substr($arg, strlen($prefix));
                    continue 2;
                }
            }

            throw new InvalidArgumentException("Unknown option: {$arg}");
        }

        if ((string) $options['format'] !== 'json') {
            throw new InvalidArgumentException('Only --format=json is supported.');
        }
        $options['release_target'] = self::normalize_release_target((string) $options['release_target']);

        $timeout = (int) $options['timeout'];
        if ($timeout < 5 || $timeout > 900) {
            throw new InvalidArgumentException('--timeout must be between 5 and 900 seconds.');
        }
        $options['timeout'] = $timeout;

        return $options;
    }

    public static function usage(): string
    {
        return implode("\n", [
            'Usage: php indexer/tools/collect-release-evidence.php [options]',
            '',
            'Options:',
            '  --format=json                    Output JSON. This is the default.',
            '  --json                           Alias for --format=json.',
            '  --release-target=TARGET          direct-install (default) or public-submission.',
            '  --plugin-src=PATH                Plugin source directory. Defaults to this script parent.',
            '  --monorepo-root=PATH             Monorepo root. Defaults to the plugin source parent.',
            '  --timeout=SECONDS                Per-subprocess timeout. Defaults to 90.',
            '  --direct-package-dir=PATH        Validate an already staged direct-install package.',
            '  --run-direct-install-readiness   Allow direct-install readiness to build/stage artifacts.',
            '  --run-real-wordpress-mysql       Allow the real WordPress/MySQL integration lane.',
            '  --run-docker-disposable-smokes   Run Docker-backed release/provider smokes in a disposable stack.',
            '  --run-docker-lifecycle-smokes    Run Docker-backed lifecycle smokes in a disposable stack.',
            '  --run-docker-upgrade-multisite-smoke',
            '                                  Run Docker-backed upgrade evidence from a previous direct-install package.',
            '  --previous-direct-package=PATH   Previous direct-install ZIP for the upgrade evidence lane.',
            '  --previous-direct-package-ref=REF',
            '                                  Local Git ref/SHA used to build a previous direct-install ZIP in temporary storage.',
            '  -h, --help                       Show this help.',
            '',
        ]);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function collect(array $options = []): array
    {
        $pluginSource = self::existing_directory((string) ($options['plugin_src'] ?? dirname(__DIR__)), 'plugin source');
        $monorepoRoot = self::existing_directory((string) ($options['monorepo_root'] ?? dirname($pluginSource)), 'monorepo root');
        $releaseTarget = self::normalize_release_target((string) ($options['release_target'] ?? self::TARGET_DIRECT_INSTALL));
        $timeout = (int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS);
        $requiredReadinessLane = $releaseTarget === self::TARGET_PUBLIC_SUBMISSION
            ? 'public_submission_readiness'
            : 'direct_install_readiness';

        $lanes = [
            $this->direct_install_readiness_lane(
                $pluginSource,
                $monorepoRoot,
                $options,
                $timeout,
                $releaseTarget === self::TARGET_DIRECT_INSTALL
            ),
            $this->public_submission_readiness_lane(
                $pluginSource,
                $monorepoRoot,
                $timeout,
                $releaseTarget === self::TARGET_PUBLIC_SUBMISSION
            ),
            $this->optional_command_lane(
                'disposable_wordpress_release_smoke',
                'Disposable WordPress release smoke',
                ['tools/smoke-disposable-wordpress-release.php'],
                $pluginSource,
                $timeout
            ),
            $this->optional_command_lane(
                'provider_compatibility_smoke',
                'Provider compatibility WordPress smoke',
                ['tools/smoke-search-provider-compatibility.php'],
                $pluginSource,
                $timeout
            ),
            $this->docker_disposable_smoke_lane($pluginSource, $timeout, $options),
            $this->docker_lifecycle_smoke_lane($pluginSource, $timeout, $options),
            $this->docker_upgrade_multisite_smoke_lane($pluginSource, $timeout, $options),
            $this->real_wordpress_mysql_lane($pluginSource, $timeout, $options),
            $this->optional_command_lane(
                'real_mysql_production_proof',
                'Real MySQL production proof',
                ['tests/integration/real-mysql-production-proof.php'],
                $pluginSource,
                $timeout
            ),
            $this->production_scale_benchmark_lane($pluginSource, $timeout),
        ];

        $counts = self::status_counts($lanes);
        $requiredFailures = self::required_lanes_with_status($lanes, ['fail']);
        $requiredBlocked = self::required_lanes_with_status($lanes, ['blocked', 'skip']);
        $overallStatus = self::overall_status($lanes);

        return [
            'schema' => self::SCHEMA,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'release_target' => $releaseTarget,
            'overall_status' => $overallStatus,
            'summary' => [
                'status_counts' => $counts,
                'required_failure_count' => count($requiredFailures),
                'required_blocked_count' => count($requiredBlocked),
                'required_readiness_lane' => $requiredReadinessLane,
                'lane_count' => count($lanes),
                'target_policy' => $releaseTarget === self::TARGET_DIRECT_INSTALL
                    ? 'Overall status is scoped to direct-install evidence; public-submission readiness remains non-target evidence and is not approved by this bundle.'
                    : 'Overall status is scoped to public-submission evidence and remains blocked until real public-submission artifacts and authority evidence pass.',
            ],
            'source' => $this->source_metadata($pluginSource, $monorepoRoot, $timeout),
            'collector' => [
                'version' => 1,
                'default_artifact_policy' => 'read-only; direct-install build/staging is skipped unless explicitly requested',
                'output_policy' => 'bounded summaries with redaction; raw subprocess output is not emitted',
            ],
            'lanes' => array_map(static function (array $lane): array {
                unset($lane['required']);
                return $lane;
            }, $lanes),
        ];
    }

    /**
     * @param array<string,mixed> $report
     */
    public static function render_json(array $report): string
    {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Could not encode release evidence JSON.');
        }

        return $json . "\n";
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function direct_install_readiness_lane(string $pluginSource, string $monorepoRoot, array $options, int $timeout, bool $required): array
    {
        $args = ['tools/check-release-readiness.php', '--target=direct-install'];
        $explicitPackageDir = trim((string) ($options['direct_package_dir'] ?? ''));
        if ($explicitPackageDir !== '') {
            $args[] = '--package-dir=' . $explicitPackageDir;
        }

        $canRun = !empty($options['run_direct_install_readiness']) || $explicitPackageDir !== '';
        if (!$canRun) {
            return [
                'id' => 'direct_install_readiness',
                'label' => 'Direct-install readiness',
                'status' => $required ? 'blocked' : 'skip',
                'command' => self::display_command($args),
                'summary' => $required
                    ? 'Required for release-target=direct-install but not run because the readiness checker stages/builds release artifacts and requires explicit opt-in.'
                    : 'Not selected for the current release target; direct-install artifact staging remains opt-in.',
                'details' => [
                    'artifact_policy' => 'not_run_by_default',
                    'enable_with' => '--run-direct-install-readiness or --direct-package-dir=PATH',
                    'target_role' => $required ? 'required' : 'non_target',
                ],
                'required' => $required,
            ];
        }

        $lane = $this->run_json_readiness_lane(
            'direct_install_readiness',
            'Direct-install readiness',
            $args,
            $pluginSource,
            $timeout,
            'fail',
            $required
        );
        $lane['details']['artifact_policy'] = $explicitPackageDir !== ''
            ? 'validated_supplied_package_directory'
            : 'explicit_build_or_staging_opt_in';
        $lane['details']['target_role'] = $required ? 'required' : 'non_target';

        return $lane;
    }

    /**
     * @return array<string,mixed>
     */
    private function public_submission_readiness_lane(string $pluginSource, string $monorepoRoot, int $timeout, bool $required): array
    {
        $lane = $this->run_json_readiness_lane(
            'public_submission_readiness',
            'Public-submission readiness',
            ['tools/check-release-readiness.php', '--target=public-submission'],
            $pluginSource,
            $timeout,
            'blocked',
            $required
        );
        $lane['details']['target_role'] = $required ? 'required' : 'non_target';
        $lane['details']['target_policy'] = $required
            ? 'public-submission readiness is the selected release target and is blocking until all public-submission evidence passes'
            : 'public-submission readiness is non-target evidence for this direct-install bundle; it remains blocked if release-target=public-submission is selected';
        if (!$required && ($lane['status'] ?? null) === 'blocked') {
            $lane['summary'] = 'Non-target public-submission readiness remains blocked if selected: '
                . implode(', ', array_slice((array) ($lane['details']['blocker_ids'] ?? []), 0, 12));
        }

        return $lane;
    }

    /**
     * @param array<int,string> $args
     * @return array<string,mixed>
     */
    private function run_json_readiness_lane(
        string $id,
        string $label,
        array $args,
        string $cwd,
        int $timeout,
        string $blockedStatus,
        bool $required
    ): array {
        $result = $this->run_php_script($args, $cwd, $timeout);
        $decoded = $this->decode_json_object($result['stdout']);
        if ($decoded === null) {
            return $this->command_failure_lane(
                $id,
                $label,
                $args,
                $result,
                'Readiness command did not emit parseable JSON.',
                true
            );
        }

        $readinessStatus = (string) ($decoded['status'] ?? 'unknown');
        $blockers = self::blocker_ids($decoded);
        $status = 'fail';
        if ($readinessStatus === 'ready' && $result['exit'] === 0) {
            $status = 'pass';
        } elseif ($readinessStatus === 'blocked') {
            $status = $blockedStatus;
        }

        return [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'command' => self::display_command($args),
            'exit_code' => $result['exit'],
            'summary' => $this->readiness_summary($readinessStatus, $blockers, $decoded),
            'details' => [
                'readiness_status' => $readinessStatus,
                'check_status_counts' => self::check_status_counts($decoded),
                'blocker_ids' => $blockers,
                'stdout_excerpt' => self::sanitize_text($result['stdout'], self::OUTPUT_EXCERPT_BYTES),
                'stderr_excerpt' => self::sanitize_text($result['stderr'], self::OUTPUT_EXCERPT_BYTES),
                'stdout_truncated' => !empty($result['stdout_truncated']),
                'stderr_truncated' => !empty($result['stderr_truncated']),
            ],
            'required' => $required,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function optional_command_lane(string $id, string $label, array $args, string $cwd, int $timeout): array
    {
        $result = $this->run_php_script($args, $cwd, $timeout);
        $status = $this->status_from_command_output($result);

        return [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'command' => self::display_command($args),
            'exit_code' => $result['exit'],
            'summary' => $this->command_summary($status, $result),
            'details' => [
                'stdout_excerpt' => self::sanitize_text($result['stdout'], self::OUTPUT_EXCERPT_BYTES),
                'stderr_excerpt' => self::sanitize_text($result['stderr'], self::OUTPUT_EXCERPT_BYTES),
                'stdout_truncated' => !empty($result['stdout_truncated']),
                'stderr_truncated' => !empty($result['stderr_truncated']),
                'timed_out' => !empty($result['timed_out']),
            ],
            'required' => false,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function docker_disposable_smoke_lane(string $pluginSource, int $timeout, array $options): array
    {
        $args = ['tools/run-disposable-release-provider-smoke.sh'];
        if (empty($options['run_docker_disposable_smokes'])) {
            return [
                'id' => 'docker_disposable_release_provider_smoke',
                'label' => 'Docker disposable release/provider smoke',
                'status' => 'skip',
                'command' => self::display_command($args, ''),
                'summary' => 'Skipped by default because this lane starts disposable WordPress/MariaDB containers and runs write-enabled smokes.',
                'details' => [
                    'artifact_policy' => 'requires_explicit_collector_opt_in',
                    'enable_with' => '--run-docker-disposable-smokes',
                    'target_policy' => 'direct-install and provider smoke evidence only; public-submission readiness remains non-target evidence',
                ],
                'required' => false,
            ];
        }

        $result = $this->run_raw_command($args, $pluginSource, $timeout);
        $status = $this->status_from_command_output($result);

        return [
            'id' => 'docker_disposable_release_provider_smoke',
            'label' => 'Docker disposable release/provider smoke',
            'status' => $status,
            'command' => self::display_command($args, ''),
            'exit_code' => $result['exit'],
            'summary' => $this->command_summary($status, $result),
            'details' => [
                'stdout_excerpt' => self::sanitize_text($result['stdout'], self::OUTPUT_EXCERPT_BYTES),
                'stderr_excerpt' => self::sanitize_text($result['stderr'], self::OUTPUT_EXCERPT_BYTES),
                'stdout_truncated' => !empty($result['stdout_truncated']),
                'stderr_truncated' => !empty($result['stderr_truncated']),
                'timed_out' => !empty($result['timed_out']),
                'target_policy' => 'direct-install and provider smoke evidence only; public-submission readiness remains non-target evidence',
            ],
            'required' => false,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function docker_lifecycle_smoke_lane(string $pluginSource, int $timeout, array $options): array
    {
        $args = ['tools/run-disposable-lifecycle-smoke.sh'];
        if (empty($options['run_docker_lifecycle_smokes'])) {
            return [
                'id' => 'docker_disposable_lifecycle_smoke',
                'label' => 'Docker disposable lifecycle smoke',
                'status' => 'skip',
                'command' => self::display_command($args, ''),
                'summary' => 'Skipped by default because this lane starts disposable WordPress/MariaDB containers and runs lifecycle write probes.',
                'details' => [
                    'artifact_policy' => 'requires_explicit_collector_opt_in',
                    'enable_with' => '--run-docker-lifecycle-smokes',
                    'target_policy' => 'direct-install/operator lifecycle evidence only; not public-submission readiness',
                    'multisite_policy' => 'Docker lifecycle proof uses network activation, a real subsite, exact bounded uninstall fences, and all-site reactivation after current/legacy table removal',
                ],
                'required' => false,
            ];
        }

        $result = $this->run_raw_command($args, $pluginSource, $timeout);
        $lifecycleReport = $this->decode_lifecycle_smoke_report($result['stdout']);
        $status = $this->status_from_lifecycle_command_output($result, $lifecycleReport);

        return [
            'id' => 'docker_disposable_lifecycle_smoke',
            'label' => 'Docker disposable lifecycle smoke',
            'status' => $status,
            'command' => self::display_command($args, ''),
            'exit_code' => $result['exit'],
            'summary' => $this->lifecycle_command_summary($status, $result, $lifecycleReport),
            'details' => [
                'stdout_excerpt' => self::sanitize_text($result['stdout'], self::OUTPUT_EXCERPT_BYTES),
                'stderr_excerpt' => self::sanitize_text($result['stderr'], self::OUTPUT_EXCERPT_BYTES),
                'stdout_truncated' => !empty($result['stdout_truncated']),
                'stderr_truncated' => !empty($result['stderr_truncated']),
                'timed_out' => !empty($result['timed_out']),
                'lifecycle_report_schema' => is_array($lifecycleReport) ? ($lifecycleReport['schema'] ?? null) : null,
                'lifecycle_report_status' => is_array($lifecycleReport) ? ($lifecycleReport['status'] ?? null) : null,
                'multisite_evidence_status' => is_array($lifecycleReport) ? ($lifecycleReport['multisite_evidence_status'] ?? null) : null,
                'uninstall_table_cleanup' => is_array($lifecycleReport) ? ($lifecycleReport['uninstall_table_cleanup'] ?? null) : null,
                'uninstall_fence' => is_array($lifecycleReport) ? ($lifecycleReport['uninstall_fence'] ?? null) : null,
                'network_reactivation' => is_array($lifecycleReport) ? ($lifecycleReport['network_reactivation'] ?? null) : null,
                'target_policy' => 'direct-install/operator lifecycle evidence only; not public-submission readiness',
                'multisite_policy' => 'requires passed network activation, current/legacy table removal, exact uninstall-fence shape, and reactivation reprovisioning on both disposable sites',
            ],
            'required' => false,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function docker_upgrade_multisite_smoke_lane(string $pluginSource, int $timeout, array $options): array
    {
        $previousPackage = trim((string) ($options['previous_direct_package'] ?? ''));
        $previousPackageRef = trim((string) ($options['previous_direct_package_ref'] ?? ''));
        $args = ['tools/run-disposable-upgrade-multisite-smoke.sh'];
        if ($previousPackage !== '') {
            $args[] = '--previous-package=' . $previousPackage;
        }

        if (empty($options['run_docker_upgrade_multisite_smoke'])) {
            return [
                'id' => 'docker_disposable_upgrade_multisite_smoke',
                'label' => 'Docker disposable upgrade/multisite smoke',
                'status' => 'skip',
                'command' => self::display_command($args, ''),
                'summary' => 'Skipped by default because this lane builds a current direct-install ZIP, requires a previous direct-install ZIP, and starts disposable WordPress/MariaDB containers.',
                'details' => [
                    'artifact_policy' => 'requires_explicit_collector_opt_in',
                    'enable_with' => '--run-docker-upgrade-multisite-smoke --previous-direct-package=PATH or --previous-direct-package-ref=REF',
                    'previous_package_policy' => 'required_for_upgrade_proof',
                    'upgrade_evidence_status' => 'not_run',
                    'multisite_evidence_status' => 'not_run',
                    'target_policy' => 'direct-install/operator upgrade evidence only; not public-submission readiness',
                    'multisite_policy' => 'must be a runtime pass or an explicit not_run/skipped boundary; single-site upgrade evidence is not multisite proof',
                ],
                'required' => false,
            ];
        }

        if ($previousPackage !== '' && $previousPackageRef !== '') {
            return $this->upgrade_multisite_generation_failure_lane(
                $args,
                [
                    'status' => 'unavailable',
                    'summary' => 'Both a previous direct-install package path and previous package ref were supplied; choose one previous package source.',
                    'previous_package_policy' => 'ambiguous_previous_package_source',
                    'previous_package_ref' => $previousPackageRef,
                ]
            );
        }

        if ($previousPackage === '' && $previousPackageRef === '') {
            return $this->upgrade_multisite_unavailable_lane(
                $args,
                'No previous direct-install package path or local Git ref was supplied, so no upgrade proof was run.'
            );
        }

        $generatedPackage = null;
        if ($previousPackageRef !== '') {
            $generatedPackage = $this->build_previous_direct_package_from_ref(
                $previousPackageRef,
                dirname($pluginSource),
                $timeout
            );
            if (empty($generatedPackage['ok'])) {
                return $this->upgrade_multisite_generation_failure_lane($args, $generatedPackage);
            }

            $previousReal = (string) $generatedPackage['zip_path'];
        } else {
            $previousReal = realpath($previousPackage);
            if (!is_string($previousReal) || !is_file($previousReal)) {
                return $this->upgrade_multisite_unavailable_lane(
                    $args,
                    'Previous direct-install package path is missing or invalid, so no upgrade proof was run.'
                );
            }
        }

        $args = ['tools/run-disposable-upgrade-multisite-smoke.sh', '--previous-package=' . $previousReal];
        try {
            $result = $this->run_raw_command($args, $pluginSource, $timeout);
            $upgradeReport = $this->decode_upgrade_smoke_report($result['stdout']);
            $status = $this->status_from_upgrade_command_output($result, $upgradeReport);
        } finally {
            if (is_array($generatedPackage) && isset($generatedPackage['cleanup_dir'])) {
                self::remove_previous_package_temp_tree((string) $generatedPackage['cleanup_dir']);
            }
        }

        $previousPackageDetails = [
            'previous_package_policy' => 'validated_supplied_previous_direct_install_zip',
        ];
        if (is_array($generatedPackage)) {
            $previousPackageDetails = [
                'previous_package_policy' => 'generated_from_local_git_ref',
                'previous_package_ref' => self::sanitize_text((string) ($generatedPackage['ref'] ?? $previousPackageRef), 200),
                'previous_package_sha' => self::sanitize_text((string) ($generatedPackage['sha'] ?? ''), 80),
                'previous_package_zip_sha256' => self::sanitize_text((string) ($generatedPackage['sha256'] ?? ''), 80),
                'previous_package_zip_bytes' => (int) ($generatedPackage['bytes'] ?? 0),
                'previous_package_build_status' => 'pass',
                'previous_package_build_tooling' => 'indexer/tools/build-release-zip.php',
            ];
        }

        return [
            'id' => 'docker_disposable_upgrade_multisite_smoke',
            'label' => 'Docker disposable upgrade/multisite smoke',
            'status' => $status,
            'command' => self::display_command($args, ''),
            'exit_code' => $result['exit'],
            'summary' => $this->upgrade_command_summary($status, $result, $upgradeReport),
            'details' => [
                'stdout_excerpt' => self::sanitize_text($result['stdout'], self::OUTPUT_EXCERPT_BYTES),
                'stderr_excerpt' => self::sanitize_text($result['stderr'], self::OUTPUT_EXCERPT_BYTES),
                'stdout_truncated' => !empty($result['stdout_truncated']),
                'stderr_truncated' => !empty($result['stderr_truncated']),
                'timed_out' => !empty($result['timed_out']),
                'upgrade_report_schema' => is_array($upgradeReport) ? ($upgradeReport['schema'] ?? null) : null,
                'upgrade_report_status' => is_array($upgradeReport) ? ($upgradeReport['status'] ?? null) : null,
                'upgrade_evidence_status' => is_array($upgradeReport) ? ($upgradeReport['upgrade_evidence_status'] ?? null) : null,
                'multisite_evidence_status' => is_array($upgradeReport) ? ($upgradeReport['multisite_evidence_status'] ?? null) : null,
                'current_package_policy' => 'built_by_upgrade_wrapper_from_current_checkout',
                'current_package_source_sha' => $this->git_scalar(['rev-parse', 'HEAD'], dirname($pluginSource), min($timeout, 15)),
                'target_policy' => 'direct-install/operator upgrade evidence only; not public-submission readiness',
                'multisite_policy' => 'must be a runtime pass or an explicit not_run/skipped boundary; single-site upgrade evidence is not multisite proof',
            ] + $previousPackageDetails,
            'required' => false,
        ];
    }

    /**
     * @param array<int,string> $args
     * @return array<string,mixed>
     */
    private function upgrade_multisite_unavailable_lane(array $args, string $summary): array
    {
        return [
            'id' => 'docker_disposable_upgrade_multisite_smoke',
            'label' => 'Docker disposable upgrade/multisite smoke',
            'status' => 'unavailable',
            'command' => self::display_command($args, ''),
            'summary' => $summary,
            'details' => [
                'artifact_policy' => 'requires_explicit_collector_opt_in',
                'enable_with' => '--run-docker-upgrade-multisite-smoke --previous-direct-package=PATH or --previous-direct-package-ref=REF',
                'previous_package_policy' => 'missing_or_invalid_previous_package_is_not_upgrade_proof',
                'upgrade_evidence_status' => 'unavailable',
                'multisite_evidence_status' => 'not_run',
                'target_policy' => 'direct-install/operator upgrade evidence only; not public-submission readiness',
                'multisite_policy' => 'must be a runtime pass or an explicit not_run/skipped boundary; single-site upgrade evidence is not multisite proof',
            ],
            'required' => false,
        ];
    }

    /**
     * @param array<int,string> $args
     * @param array<string,mixed> $generation
     * @return array<string,mixed>
     */
    private function upgrade_multisite_generation_failure_lane(array $args, array $generation): array
    {
        $status = (string) ($generation['status'] ?? 'unavailable');
        if (!in_array($status, ['unavailable', 'fail'], true)) {
            $status = 'unavailable';
        }

        $details = [
            'artifact_policy' => 'requires_explicit_collector_opt_in',
            'enable_with' => '--run-docker-upgrade-multisite-smoke --previous-direct-package=PATH or --previous-direct-package-ref=REF',
            'previous_package_policy' => (string) ($generation['previous_package_policy'] ?? 'generated_previous_package_unavailable'),
            'upgrade_evidence_status' => $status === 'fail' ? 'failed' : 'unavailable',
            'multisite_evidence_status' => 'not_run',
            'target_policy' => 'direct-install/operator upgrade evidence only; not public-submission readiness',
            'multisite_policy' => 'must be a runtime pass or an explicit not_run/skipped boundary; single-site upgrade evidence is not multisite proof',
        ];

        foreach ([
            'previous_package_ref',
            'previous_package_sha',
            'previous_package_build_status',
            'previous_package_build_command',
            'previous_package_build_exit_code',
            'stdout_excerpt',
            'stderr_excerpt',
            'timed_out',
        ] as $key) {
            if (array_key_exists($key, $generation)) {
                $details[$key] = self::sanitize_value($generation[$key], $key);
            }
        }

        return [
            'id' => 'docker_disposable_upgrade_multisite_smoke',
            'label' => 'Docker disposable upgrade/multisite smoke',
            'status' => $status,
            'command' => self::display_command($args, ''),
            'summary' => self::sanitize_text((string) ($generation['summary'] ?? 'Previous direct-install package could not be generated from the selected local Git ref.'), 500),
            'details' => $details,
            'required' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function build_previous_direct_package_from_ref(string $ref, string $monorepoRoot, int $timeout): array
    {
        $ref = trim($ref);
        if (!self::is_safe_previous_package_ref($ref)) {
            return [
                'ok' => false,
                'status' => 'unavailable',
                'summary' => 'Previous direct-install package ref is empty or contains unsupported characters; no upgrade proof was run.',
                'previous_package_policy' => 'invalid_previous_package_ref',
                'previous_package_ref' => $ref,
                'previous_package_build_status' => 'unavailable',
            ];
        }

        $currentSha = $this->git_scalar(['rev-parse', 'HEAD'], $monorepoRoot, min($timeout, 15));
        if ($currentSha === '') {
            return [
                'ok' => false,
                'status' => 'unavailable',
                'summary' => 'Current Git HEAD could not be resolved, so the previous direct-install package ref was not used.',
                'previous_package_policy' => 'current_git_head_unavailable',
                'previous_package_ref' => $ref,
                'previous_package_build_status' => 'unavailable',
            ];
        }

        $resolvedSha = $this->git_scalar(['rev-parse', '--verify', '--quiet', '--end-of-options', $ref . '^{commit}'], $monorepoRoot, min($timeout, 15));
        if ($resolvedSha === '') {
            return [
                'ok' => false,
                'status' => 'unavailable',
                'summary' => 'Previous direct-install package ref could not be resolved from local Git history; no network fetch was attempted.',
                'previous_package_policy' => 'unresolved_local_git_ref',
                'previous_package_ref' => $ref,
                'previous_package_build_status' => 'unavailable',
            ];
        }

        if ($resolvedSha === $currentSha) {
            return [
                'ok' => false,
                'status' => 'unavailable',
                'summary' => 'Previous direct-install package ref resolves to the current target commit, so the upgrade proof would be meaningless.',
                'previous_package_policy' => 'previous_ref_matches_current_target',
                'previous_package_ref' => $ref,
                'previous_package_sha' => $resolvedSha,
                'previous_package_build_status' => 'unavailable',
            ];
        }

        foreach (self::PREVIOUS_PACKAGE_REQUIRED_PATHS as $path) {
            $exists = $this->run_git_command(['cat-file', '-e', $resolvedSha . ':' . $path], $monorepoRoot, min($timeout, 15));
            if (($exists['exit'] ?? 1) !== 0) {
                return [
                    'ok' => false,
                    'status' => 'unavailable',
                    'summary' => 'Previous direct-install package ref does not contain the release-build tooling required to create a direct-install ZIP.',
                    'previous_package_policy' => 'previous_ref_missing_release_build_tooling',
                    'previous_package_ref' => $ref,
                    'previous_package_sha' => $resolvedSha,
                    'previous_package_build_status' => 'unavailable',
                ];
            }
        }

        $tree = $this->run_git_command(array_merge(['ls-tree', '-r', '--name-only', $resolvedSha, '--'], self::PREVIOUS_PACKAGE_ARCHIVE_PATHS), $monorepoRoot, min($timeout, 30));
        if (($tree['exit'] ?? 1) !== 0) {
            return $this->previous_package_command_failure(
                'unavailable',
                'Previous direct-install package ref contents could not be listed from local Git history.',
                'previous_ref_tree_unavailable',
                $ref,
                $resolvedSha,
                ['git', 'ls-tree', '-r', '--name-only', '[previous-sha]', '--', 'indexer', 'components/full-text-search'],
                $tree
            );
        }

        $prohibitedPathCount = self::count_prohibited_previous_ref_paths((string) ($tree['stdout'] ?? ''));
        if ($prohibitedPathCount > 0) {
            return [
                'ok' => false,
                'status' => 'unavailable',
                'summary' => 'Previous direct-install package ref contains tracked secret-like paths in the source needed for packaging; no checkout or package build was run.',
                'previous_package_policy' => 'previous_ref_contains_secret_like_paths',
                'previous_package_ref' => $ref,
                'previous_package_sha' => $resolvedSha,
                'previous_package_build_status' => 'unavailable',
            ];
        }

        $tempRoot = self::create_previous_package_temp_dir();
        $success = false;
        try {
            $checkoutRoot = $tempRoot . '/source';
            self::ensure_directory($checkoutRoot);
            self::ensure_directory($tempRoot . '/composer-home');
            $composerCacheDir = self::safe_composer_cache_dir($this->env, $tempRoot);
            self::ensure_directory($composerCacheDir);

            $archivePath = $tempRoot . '/source.tar';
            $archive = $this->run_git_command(
                array_merge(['archive', '--format=tar', '--output=' . $archivePath, $resolvedSha], self::PREVIOUS_PACKAGE_ARCHIVE_PATHS),
                $monorepoRoot,
                min($timeout, 60)
            );
            if (($archive['exit'] ?? 1) !== 0) {
                return $this->previous_package_command_failure(
                    self::command_failure_status($archive),
                    'Previous direct-install package ref could not be archived from local Git history.',
                    'previous_ref_archive_failed',
                    $ref,
                    $resolvedSha,
                    ['git', 'archive', '--format=tar', '--output=[path]', '[previous-sha]', 'indexer', 'components/full-text-search'],
                    $archive
                );
            }

            $extract = $this->run_raw_command(['tar', '-xf', $archivePath, '-C', $checkoutRoot], $monorepoRoot, min($timeout, 60));
            if (($extract['exit'] ?? 1) !== 0) {
                return $this->previous_package_command_failure(
                    self::command_failure_status($extract),
                    'Previous direct-install package source archive could not be extracted in temporary storage.',
                    'previous_ref_archive_extract_failed',
                    $ref,
                    $resolvedSha,
                    ['tar', '-xf', '[path]', '-C', '[path]'],
                    $extract
                );
            }

            $archivedSymlinks = self::find_symbolic_link_paths($checkoutRoot);
            if ($archivedSymlinks !== []) {
                return [
                    'ok' => false,
                    'status' => 'unavailable',
                    'summary' => 'Previous direct-install package source contains symbolic links; no historical builder or Composer process was executed.',
                    'previous_package_policy' => 'previous_ref_contains_symbolic_links',
                    'previous_package_ref' => $ref,
                    'previous_package_sha' => $resolvedSha,
                    'previous_package_build_status' => 'unavailable',
                    'symbolic_link_paths' => $archivedSymlinks,
                ];
            }

            $zipPath = $tempRoot . '/previous-wp-fts-indexer.zip';
            $buildEnv = self::previous_package_build_environment(
                $this->env,
                $tempRoot . '/composer-home',
                $composerCacheDir
            );
            $buildCommand = [
                'env',
                '-i',
                ...self::environment_assignments($buildEnv),
                PHP_BINARY,
                $checkoutRoot . '/indexer/tools/build-release-zip.php',
                '--plugin-src=' . $checkoutRoot . '/indexer',
                '--monorepo-root=' . $checkoutRoot,
                '--build-dir=' . $tempRoot . '/build',
                '--output=' . $zipPath,
            ];
            if (self::release_builder_supports_explicit_composer_cache($checkoutRoot . '/indexer/tools/build-release-zip.php')) {
                $buildCommand[] = '--composer-cache-dir=' . $composerCacheDir;
            }
            $build = $this->run_raw_command($buildCommand, $checkoutRoot . '/indexer', $timeout);
            if (($build['exit'] ?? 1) !== 0) {
                return $this->previous_package_command_failure(
                    self::command_failure_status($build),
                    'Previous direct-install package generation from the selected local Git ref did not complete.',
                    'previous_ref_package_build_failed',
                    $ref,
                    $resolvedSha,
                    $buildCommand,
                    $build
                );
            }

            $zipReal = realpath($zipPath);
            if (!is_string($zipReal) || !is_file($zipReal)) {
                return [
                    'ok' => false,
                    'status' => 'fail',
                    'summary' => 'Previous direct-install package generation completed without producing the expected ZIP.',
                    'previous_package_policy' => 'previous_ref_package_build_missing_zip',
                    'previous_package_ref' => $ref,
                    'previous_package_sha' => $resolvedSha,
                    'previous_package_build_status' => 'fail',
                    'previous_package_build_command' => self::display_generation_command($buildCommand),
                ];
            }

            $sha256 = hash_file('sha256', $zipReal);
            $bytes = filesize($zipReal);
            if (!is_string($sha256) || !is_int($bytes)) {
                return [
                    'ok' => false,
                    'status' => 'fail',
                    'summary' => 'Previous direct-install package ZIP was built but could not be measured for bounded evidence.',
                    'previous_package_policy' => 'previous_ref_package_metadata_failed',
                    'previous_package_ref' => $ref,
                    'previous_package_sha' => $resolvedSha,
                    'previous_package_build_status' => 'fail',
                ];
            }

            $success = true;
            return [
                'ok' => true,
                'ref' => $ref,
                'sha' => $resolvedSha,
                'zip_path' => $zipReal,
                'sha256' => $sha256,
                'bytes' => $bytes,
                'cleanup_dir' => $tempRoot,
            ];
        } finally {
            if (!$success) {
                self::remove_previous_package_temp_tree($tempRoot);
            }
        }
    }

    /**
     * @param array<int,string> $command
     * @param array{exit:int,stdout:string,stderr:string,timed_out?:bool,stdout_truncated?:bool,stderr_truncated?:bool} $result
     * @return array<string,mixed>
     */
    private function previous_package_command_failure(
        string $status,
        string $summary,
        string $policy,
        string $ref,
        string $sha,
        array $command,
        array $result
    ): array {
        return [
            'ok' => false,
            'status' => in_array($status, ['unavailable', 'fail'], true) ? $status : 'fail',
            'summary' => $summary,
            'previous_package_policy' => $policy,
            'previous_package_ref' => $ref,
            'previous_package_sha' => $sha,
            'previous_package_build_status' => in_array($status, ['unavailable', 'fail'], true) ? $status : 'fail',
            'previous_package_build_command' => self::display_generation_command($command),
            'previous_package_build_exit_code' => $result['exit'] ?? null,
            'stdout_excerpt' => self::sanitize_text((string) ($result['stdout'] ?? ''), self::OUTPUT_EXCERPT_BYTES),
            'stderr_excerpt' => self::sanitize_text((string) ($result['stderr'] ?? ''), self::OUTPUT_EXCERPT_BYTES),
            'timed_out' => !empty($result['timed_out']),
        ];
    }

    /**
     * @param array<int,string> $args
     * @return array{exit:int,stdout:string,stderr:string,timed_out?:bool,stdout_truncated?:bool,stderr_truncated?:bool}
     */
    private function run_git_command(array $args, string $cwd, int $timeout): array
    {
        if (!function_exists('proc_open')) {
            return [
                'exit' => 127,
                'stdout' => '',
                'stderr' => 'proc_open() is unavailable; cannot launch git.',
            ];
        }

        return ($this->processRunner)(array_merge(['git'], $args), $cwd, $timeout);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function real_wordpress_mysql_lane(string $pluginSource, int $timeout, array $options): array
    {
        $optedIn = !empty($options['run_real_wordpress_mysql'])
            || (($this->env[self::REAL_INTEGRATION_OPT_IN_ENV] ?? '') === '1');
        $args = ['tests/integration/real-wordpress-mysql.php'];

        if (!$optedIn) {
            return [
                'id' => 'real_wordpress_mysql_integration',
                'label' => 'Real WordPress/MySQL integration proof',
                'status' => 'skip',
                'command' => self::display_command($args),
                'summary' => 'Skipped by default because this lane writes temporary WordPress/MySQL fixtures and has no delegated disposable-site guard.',
                'details' => [
                    'artifact_policy' => 'requires_explicit_collector_opt_in',
                    'enable_with' => '--run-real-wordpress-mysql or ' . self::REAL_INTEGRATION_OPT_IN_ENV . '=1',
                ],
                'required' => false,
            ];
        }

        return $this->optional_command_lane(
            'real_wordpress_mysql_integration',
            'Real WordPress/MySQL integration proof',
            $args,
            $pluginSource,
            $timeout
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function production_scale_benchmark_lane(string $pluginSource, int $timeout): array
    {
        $args = ['tests/production-scale-benchmark.php', '--json'];
        $result = $this->run_php_script($args, $pluginSource, $timeout);
        $decoded = $this->decode_json_object($result['stdout']);
        if ($decoded === null) {
            return $this->command_failure_lane(
                'production_scale_benchmark',
                'PR-safe production-scale benchmark',
                $args,
                $result,
                'Production-scale benchmark did not emit parseable JSON.',
                true
            );
        }

        $passed = $result['exit'] === 0 && ($decoded['passed'] ?? null) === true;
        $profile = is_array($decoded['profile'] ?? null) ? $decoded['profile'] : [];
        $metrics = is_array($decoded['metrics'] ?? null) ? $decoded['metrics'] : [];
        $failures = isset($decoded['failures']) && is_array($decoded['failures']) ? array_values($decoded['failures']) : [];
        $gates = isset($decoded['gates']) && is_array($decoded['gates']) ? array_values($decoded['gates']) : [];
        $failedGateNames = self::benchmark_failed_gate_names($gates);
        $gateCounts = self::benchmark_gate_counts($gates);
        $performanceBudget = self::benchmark_performance_budget($metrics, $gates);
        $passed = $passed && $gates !== [] && $failedGateNames === [];

        return [
            'id' => 'production_scale_benchmark',
            'label' => 'PR-safe production-scale benchmark',
            'status' => $passed ? 'pass' : 'fail',
            'command' => self::display_command($args),
            'exit_code' => $result['exit'],
            'summary' => $passed
                ? sprintf(
                    'Benchmark passed for %d generated documents with %d query checks and %d performance budget gates.',
                    (int) ($metrics['indexed_documents'] ?? 0),
                    (int) ($metrics['query_checks_passed'] ?? 0),
                    (int) (($performanceBudget['gate_counts']['pass'] ?? 0) + ($performanceBudget['gate_counts']['fail'] ?? 0))
                )
                : self::benchmark_failure_summary($failures, $failedGateNames, $gates),
            'details' => [
                'profile' => [
                    'name' => $profile['name'] ?? null,
                    'documents' => $profile['documents'] ?? null,
                ],
                'metrics' => self::sanitize_value([
                    'indexed_documents' => $metrics['indexed_documents'] ?? null,
                    'raw_token_occurrences' => $metrics['raw_token_occurrences'] ?? null,
                    'weighted_token_instances' => $metrics['weighted_token_instances'] ?? null,
                    'unique_terms' => $metrics['unique_terms'] ?? null,
                    'posting_rows' => $metrics['posting_rows'] ?? null,
                    'query_checks_passed' => $metrics['query_checks_passed'] ?? null,
                    'hydrated_result_rows' => $metrics['hydrated_result_rows'] ?? null,
                    'index_duration_ms' => $metrics['index_duration_ms'] ?? null,
                    'query_check_total_duration_ms' => $metrics['query_check_total_duration_ms'] ?? null,
                    'query_check_max_duration_ms' => $metrics['query_check_max_duration_ms'] ?? null,
                    'result_window_total_duration_ms' => $metrics['result_window_total_duration_ms'] ?? null,
                    'result_window_max_duration_ms' => $metrics['result_window_max_duration_ms'] ?? null,
                    'search_read_total_duration_ms' => $metrics['search_read_total_duration_ms'] ?? null,
                    'memory_delta_bytes' => $metrics['memory_delta_bytes'] ?? null,
                    'peak_memory_delta_bytes' => $metrics['peak_memory_delta_bytes'] ?? null,
                ]),
                'gate_count' => count($gates),
                'gate_status_counts' => $gateCounts,
                'gates' => self::benchmark_gate_rows($gates),
                'gates_truncated' => count($gates) > 32,
                'failed_gates' => array_slice($failedGateNames, 0, 16),
                'failed_gates_truncated' => count($failedGateNames) > 16,
                'performance_budget' => self::sanitize_value($performanceBudget),
                'failure_count' => count($failures),
                'stdout_truncated' => !empty($result['stdout_truncated']),
                'stderr_excerpt' => self::sanitize_text($result['stderr'], self::OUTPUT_EXCERPT_BYTES),
                'stderr_truncated' => !empty($result['stderr_truncated']),
            ],
            'required' => true,
        ];
    }

    /**
     * @param array<int,mixed> $gates
     * @return string[]
     */
    private static function benchmark_failed_gate_names(array $gates): array
    {
        $failed = [];
        foreach ($gates as $gate) {
            if (!is_array($gate) || !array_key_exists('passed', $gate) || (bool) $gate['passed']) {
                continue;
            }
            $metric = (string) ($gate['metric'] ?? '');
            if ($metric !== '') {
                $failed[] = $metric;
            }
        }

        return $failed;
    }

    /**
     * @param array<int,mixed> $gates
     * @return array<string,int>
     */
    private static function benchmark_gate_counts(array $gates): array
    {
        $counts = [
            'total' => 0,
            'pass' => 0,
            'fail' => 0,
            'performance_pass' => 0,
            'performance_fail' => 0,
            'structural_pass' => 0,
            'structural_fail' => 0,
        ];

        foreach ($gates as $gate) {
            if (!is_array($gate)) {
                continue;
            }
            $counts['total']++;
            $category = (string) ($gate['category'] ?? 'structural');
            $passed = array_key_exists('passed', $gate) && (bool) $gate['passed'];
            $counts[$passed ? 'pass' : 'fail']++;
            $categoryKey = $category === 'performance' ? 'performance' : 'structural';
            $counts[$categoryKey . '_' . ($passed ? 'pass' : 'fail')]++;
        }

        return $counts;
    }

    /**
     * @param array<string,mixed> $metrics
     * @param array<int,mixed> $gates
     * @return array<string,mixed>
     */
    private static function benchmark_performance_budget(array $metrics, array $gates): array
    {
        $passCount = 0;
        $failCount = 0;
        $failed = [];
        foreach ($gates as $gate) {
            if (!is_array($gate) || (string) ($gate['category'] ?? '') !== 'performance') {
                continue;
            }
            if (!empty($gate['passed'])) {
                $passCount++;
                continue;
            }
            $failCount++;
            $metric = (string) ($gate['metric'] ?? '');
            if ($metric !== '') {
                $failed[] = $metric;
            }
        }

        return [
            'metrics' => [
                'index_duration_ms' => self::numeric_or_null($metrics['index_duration_ms'] ?? null),
                'query_check_total_duration_ms' => self::numeric_or_null($metrics['query_check_total_duration_ms'] ?? null),
                'query_check_max_duration_ms' => self::numeric_or_null($metrics['query_check_max_duration_ms'] ?? null),
                'result_window_total_duration_ms' => self::numeric_or_null($metrics['result_window_total_duration_ms'] ?? null),
                'result_window_max_duration_ms' => self::numeric_or_null($metrics['result_window_max_duration_ms'] ?? null),
                'search_read_total_duration_ms' => self::numeric_or_null($metrics['search_read_total_duration_ms'] ?? null),
            ],
            'gate_counts' => [
                'pass' => $passCount,
                'fail' => $failCount,
            ],
            'failed_gates' => array_slice($failed, 0, 16),
            'failed_gates_truncated' => count($failed) > 16,
        ];
    }

    /**
     * @param array<int,mixed> $gates
     * @return array<int,array<string,mixed>>
     */
    private static function benchmark_gate_rows(array $gates): array
    {
        $rows = [];
        foreach (array_slice($gates, 0, 32) as $gate) {
            if (!is_array($gate)) {
                continue;
            }
            $rows[] = self::sanitize_value([
                'metric' => (string) ($gate['metric'] ?? ''),
                'category' => (string) ($gate['category'] ?? 'structural'),
                'operator' => (string) ($gate['operator'] ?? ''),
                'expected' => self::numeric_or_null($gate['expected'] ?? null),
                'actual' => self::numeric_or_null($gate['actual'] ?? null),
                'passed' => array_key_exists('passed', $gate) ? (bool) $gate['passed'] : false,
            ]);
        }

        return $rows;
    }

    /**
     * @param array<int,mixed> $failures
     * @param string[] $failedGateNames
     * @param array<int,mixed> $gates
     */
    private static function benchmark_failure_summary(array $failures, array $failedGateNames, array $gates): string
    {
        if ($gates === []) {
            return 'Benchmark failed: benchmark JSON did not include gate evidence.';
        }
        if ($failedGateNames !== []) {
            return 'Benchmark failed gates: ' . self::sanitize_text(implode(', ', array_slice($failedGateNames, 0, 12)), 400);
        }

        $message = implode('; ', array_map('strval', $failures));

        return 'Benchmark failed: ' . self::sanitize_text($message !== '' ? $message : 'subprocess did not report passing benchmark evidence', 400);
    }

    /**
     * @return array<string,mixed>
     */
    private function command_failure_lane(
        string $id,
        string $label,
        array $args,
        array $result,
        string $summary,
        bool $required
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'status' => 'fail',
            'command' => self::display_command($args),
            'exit_code' => $result['exit'],
            'summary' => $summary,
            'details' => [
                'stdout_excerpt' => self::sanitize_text((string) ($result['stdout'] ?? ''), self::OUTPUT_EXCERPT_BYTES),
                'stderr_excerpt' => self::sanitize_text((string) ($result['stderr'] ?? ''), self::OUTPUT_EXCERPT_BYTES),
                'stdout_truncated' => !empty($result['stdout_truncated']),
                'stderr_truncated' => !empty($result['stderr_truncated']),
                'timed_out' => !empty($result['timed_out']),
            ],
            'required' => $required,
        ];
    }

    /**
     * @param array<int,string> $scriptAndArgs
     * @return array{exit:int,stdout:string,stderr:string,timed_out?:bool,stdout_truncated?:bool,stderr_truncated?:bool}
     */
    private function run_php_script(array $scriptAndArgs, string $cwd, int $timeout): array
    {
        if (!function_exists('proc_open')) {
            return [
                'exit' => 0,
                'stdout' => 'SKIP: proc_open() is unavailable; cannot launch subprocess.',
                'stderr' => '',
            ];
        }

        $command = array_merge([PHP_BINARY], $scriptAndArgs);

        return ($this->processRunner)($command, $cwd, $timeout);
    }

    /**
     * @param array<int,string> $command
     * @return array{exit:int,stdout:string,stderr:string,timed_out?:bool,stdout_truncated?:bool,stderr_truncated?:bool}
     */
    private function run_raw_command(array $command, string $cwd, int $timeout): array
    {
        if (!function_exists('proc_open')) {
            return [
                'exit' => 0,
                'stdout' => 'SKIP: proc_open() is unavailable; cannot launch subprocess.',
                'stderr' => '',
            ];
        }

        return ($this->processRunner)($command, $cwd, $timeout);
    }

    /**
     * @param array<int,string> $command
     * @return array{exit:int,stdout:string,stderr:string,timed_out?:bool,stdout_truncated?:bool,stderr_truncated?:bool}
     */
    private function default_process_runner(array $command, string $cwd, int $timeout): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $baseEnv = getenv();
        if (!is_array($baseEnv)) {
            $baseEnv = [];
        }

        $process = @proc_open($command, $descriptors, $pipes, $cwd, $baseEnv);
        if (!is_resource($process)) {
            return [
                'exit' => 127,
                'stdout' => '',
                'stderr' => 'Could not launch subprocess.',
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $stdoutTruncated = false;
        $stderrTruncated = false;
        $timedOut = false;
        $observedExitCode = null;
        $started = microtime(true);

        while (true) {
            self::read_available($pipes[1], $stdout, $stdoutTruncated);
            self::read_available($pipes[2], $stderr, $stderrTruncated);

            $status = proc_get_status($process);
            if (!($status['running'] ?? false)) {
                if (isset($status['exitcode']) && is_int($status['exitcode']) && $status['exitcode'] >= 0) {
                    $observedExitCode = $status['exitcode'];
                }
                break;
            }

            if ((microtime(true) - $started) > $timeout) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }

            usleep(50000);
        }

        self::read_available($pipes[1], $stdout, $stdoutTruncated);
        self::read_available($pipes[2], $stderr, $stderrTruncated);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);
        if ($timedOut) {
            $exit = 124;
            $stderr = self::append_bounded($stderr, "Command timed out after {$timeout} seconds.\n", $stderrTruncated);
        } elseif (is_int($observedExitCode)) {
            $exit = $observedExitCode;
        }

        return [
            'exit' => is_int($exit) && $exit >= 0 ? $exit : 1,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timed_out' => $timedOut,
            'stdout_truncated' => $stdoutTruncated,
            'stderr_truncated' => $stderrTruncated,
        ];
    }

    /**
     * @param resource $stream
     */
    private static function read_available(mixed $stream, string &$buffer, bool &$truncated): void
    {
        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            $buffer = self::append_bounded($buffer, $chunk, $truncated);
        }
    }

    private static function append_bounded(string $buffer, string $chunk, bool &$truncated): string
    {
        if (strlen($buffer) >= self::RAW_OUTPUT_CAPTURE_BYTES) {
            $truncated = true;
            return $buffer;
        }

        $remaining = self::RAW_OUTPUT_CAPTURE_BYTES - strlen($buffer);
        if (strlen($chunk) > $remaining) {
            $truncated = true;
            return $buffer . substr($chunk, 0, $remaining);
        }

        return $buffer . $chunk;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decode_json_object(string $output): ?array
    {
        foreach (self::decode_json_objects($output) as $decoded) {
            return $decoded;
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decode_lifecycle_smoke_report(string $output): ?array
    {
        foreach (self::decode_json_objects($output) as $decoded) {
            if (($decoded['schema'] ?? null) === 'wp-fts-disposable-lifecycle-smoke-v1') {
                return [
                    'schema' => $decoded['schema'],
                    'status' => $decoded['status'] ?? null,
                    'multisite_evidence_status' => $decoded['multisite_evidence']['status'] ?? null,
                    'uninstall_table_cleanup' => $decoded['covered_behaviors']['uninstall_removes_current_and_legacy_fts_tables'] ?? null,
                    'uninstall_fence' => $decoded['covered_behaviors']['uninstall_retains_exact_bounded_lifecycle_fence'] ?? null,
                    'network_reactivation' => $decoded['covered_behaviors']['multisite_reactivation_clears_all_site_fences_and_reprovisions'] ?? null,
                ];
            }
            if (($decoded['schema'] ?? null) === 'wp-fts-disposable-lifecycle-wrapper-proof-v1') {
                return [
                    'schema' => $decoded['inner_report_schema'] ?? null,
                    'status' => $decoded['inner_report_status'] ?? null,
                    'multisite_evidence_status' => $decoded['multisite_evidence_status'] ?? null,
                    'uninstall_table_cleanup' => $decoded['uninstall_table_cleanup'] ?? null,
                    'uninstall_fence' => $decoded['uninstall_fence'] ?? null,
                    'network_reactivation' => $decoded['network_reactivation'] ?? null,
                    'wrapper_proof_schema' => $decoded['schema'],
                ];
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decode_upgrade_smoke_report(string $output): ?array
    {
        foreach (self::decode_json_objects($output) as $decoded) {
            if (($decoded['schema'] ?? null) === 'wp-fts-disposable-upgrade-smoke-v1') {
                return [
                    'schema' => $decoded['schema'],
                    'status' => $decoded['status'] ?? null,
                    'upgrade_evidence_status' => $decoded['upgrade_evidence']['status'] ?? ($decoded['status'] ?? null),
                    'multisite_evidence_status' => $decoded['multisite_evidence']['status'] ?? null,
                ];
            }
            if (($decoded['schema'] ?? null) === 'wp-fts-disposable-upgrade-multisite-wrapper-proof-v1') {
                return [
                    'schema' => $decoded['inner_report_schema'] ?? null,
                    'status' => $decoded['inner_report_status'] ?? null,
                    'upgrade_evidence_status' => $decoded['upgrade_evidence_status'] ?? null,
                    'multisite_evidence_status' => $decoded['multisite_evidence_status'] ?? null,
                    'wrapper_proof_schema' => $decoded['schema'],
                ];
            }
        }

        return null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function decode_json_objects(string $output): array
    {
        $objects = [];
        $length = strlen($output);

        for ($start = 0; $start < $length; $start++) {
            if ($output[$start] !== '{') {
                continue;
            }

            $depth = 0;
            $inString = false;
            $escaped = false;
            for ($position = $start; $position < $length; $position++) {
                $char = $output[$position];
                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                        continue;
                    }
                    if ($char === '\\') {
                        $escaped = true;
                        continue;
                    }
                    if ($char === '"') {
                        $inString = false;
                    }
                    continue;
                }

                if ($char === '"') {
                    $inString = true;
                    continue;
                }
                if ($char === '{') {
                    $depth++;
                    continue;
                }
                if ($char !== '}') {
                    continue;
                }

                $depth--;
                if ($depth !== 0) {
                    continue;
                }

                $json = substr($output, $start, $position - $start + 1);
                try {
                    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $start = $position;
                    break;
                }

                if (is_array($decoded)) {
                    $objects[] = $decoded;
                }
                $start = $position;
                break;
            }
        }

        return $objects;
    }

    /**
     * @param array{exit:int,stdout:string,stderr:string,timed_out?:bool} $result
     */
    private function status_from_command_output(array $result): string
    {
        $output = ltrim($result['stdout'] . $result['stderr']);
        if (str_starts_with($output, 'SKIP:')) {
            return 'skip';
        }
        if (!empty($result['timed_out'])) {
            return 'fail';
        }

        return $result['exit'] === 0 ? 'pass' : 'fail';
    }

    /**
     * @param array{exit:int,stdout:string,stderr:string,timed_out?:bool} $result
     * @param array<string,mixed>|null $lifecycleReport
     */
    private function status_from_lifecycle_command_output(array $result, ?array $lifecycleReport): string
    {
        $output = ltrim($result['stdout'] . $result['stderr']);
        if (str_starts_with($output, 'SKIP:')) {
            return 'skip';
        }
        if (!empty($result['timed_out'])) {
            return 'fail';
        }

        $reportStatus = is_array($lifecycleReport) ? (string) ($lifecycleReport['status'] ?? '') : '';
        if ($reportStatus === 'passed') {
            $multisiteStatus = (string) ($lifecycleReport['multisite_evidence_status'] ?? '');
            $cleanupPassed = ($lifecycleReport['uninstall_table_cleanup'] ?? null) === true;
            $fencePassed = ($lifecycleReport['uninstall_fence'] ?? null) === true;
            $reactivationPassed = ($lifecycleReport['network_reactivation'] ?? null) === true;
            return $result['exit'] === 0
                && $multisiteStatus === 'passed'
                && $cleanupPassed
                && $fencePassed
                && $reactivationPassed
                ? 'pass'
                : 'fail';
        }
        if (in_array($reportStatus, ['skipped', 'skip', 'unavailable'], true)) {
            return 'skip';
        }

        return 'fail';
    }

    /**
     * @param array{exit:int,stdout:string,stderr:string,timed_out?:bool} $result
     * @param array<string,mixed>|null $upgradeReport
     */
    private function status_from_upgrade_command_output(array $result, ?array $upgradeReport): string
    {
        $output = ltrim($result['stdout'] . $result['stderr']);
        if (str_starts_with($output, 'SKIP:')) {
            return 'unavailable';
        }
        if (!empty($result['timed_out'])) {
            return 'fail';
        }

        $reportStatus = is_array($upgradeReport) ? (string) ($upgradeReport['status'] ?? '') : '';
        if ($reportStatus === 'passed') {
            $multisiteStatus = is_array($upgradeReport) ? (string) ($upgradeReport['multisite_evidence_status'] ?? '') : '';
            return $result['exit'] === 0 && $multisiteStatus === 'passed' ? 'pass' : 'fail';
        }
        if (in_array($reportStatus, ['skipped', 'skip', 'unavailable', 'not_run'], true)) {
            return 'unavailable';
        }

        return 'fail';
    }

    /**
     * @param array{stdout:string,stderr:string,timed_out?:bool} $result
     */
    private function command_summary(string $status, array $result): string
    {
        $output = trim($result['stdout'] . "\n" . $result['stderr']);
        if ($status === 'skip') {
            return self::sanitize_text(preg_replace('/^SKIP:\s*/', '', $output) ?? $output, 400);
        }
        if ($status === 'pass') {
            return 'Command completed successfully.';
        }
        if (!empty($result['timed_out'])) {
            return 'Command timed out before producing release evidence.';
        }

        return 'Command failed: ' . self::sanitize_text($output, 400);
    }

    /**
     * @param array{stdout:string,stderr:string,timed_out?:bool} $result
     * @param array<string,mixed>|null $lifecycleReport
     */
    private function lifecycle_command_summary(string $status, array $result, ?array $lifecycleReport): string
    {
        $reportStatus = is_array($lifecycleReport) ? (string) ($lifecycleReport['status'] ?? '') : '';
        if ($status === 'pass') {
            return 'Docker lifecycle smoke completed with network uninstall cleanup, exact bounded fences, and all-site reactivation evidence.';
        }
        if (in_array($reportStatus, ['skipped', 'skip', 'unavailable'], true)) {
            return 'Inner lifecycle smoke reported status ' . self::sanitize_text($reportStatus, 80) . '; not treated as lifecycle proof.';
        }
        if ($status === 'fail' && $reportStatus === '') {
            return 'Docker lifecycle smoke did not emit a parseable inner lifecycle report with status passed.';
        }
        if ($status === 'fail' && $reportStatus === 'passed') {
            $multisiteStatus = (string) ($lifecycleReport['multisite_evidence_status'] ?? '');
            $cleanupStatus = ($lifecycleReport['uninstall_table_cleanup'] ?? null) === true ? 'passed' : 'missing';
            $fenceStatus = ($lifecycleReport['uninstall_fence'] ?? null) === true ? 'passed' : 'missing';
            $reactivationStatus = ($lifecycleReport['network_reactivation'] ?? null) === true ? 'passed' : 'missing';
            return 'Docker lifecycle smoke reported lifecycle status passed but multisite evidence status '
                . self::sanitize_text($multisiteStatus !== '' ? $multisiteStatus : 'missing', 80)
                . ', uninstall table cleanup status ' . $cleanupStatus
                . ', uninstall fence status ' . $fenceStatus
                . ', and network reactivation status ' . $reactivationStatus . '.';
        }

        return $this->command_summary($status, $result);
    }

    /**
     * @param array{stdout:string,stderr:string,timed_out?:bool} $result
     * @param array<string,mixed>|null $upgradeReport
     */
    private function upgrade_command_summary(string $status, array $result, ?array $upgradeReport): string
    {
        $output = trim($result['stdout'] . "\n" . $result['stderr']);
        if ($status === 'unavailable' && str_starts_with(ltrim($output), 'SKIP:')) {
            return 'Upgrade/multisite smoke unavailable: '
                . self::sanitize_text(preg_replace('/^SKIP:\s*/', '', ltrim($output)) ?? $output, 400);
        }

        $reportStatus = is_array($upgradeReport) ? (string) ($upgradeReport['status'] ?? '') : '';
        $multisiteStatus = is_array($upgradeReport) ? (string) ($upgradeReport['multisite_evidence_status'] ?? '') : '';
        if ($status === 'pass') {
            return 'Docker upgrade/multisite smoke completed with upgrade and multisite runtime evidence.';
        }
        if (in_array($reportStatus, ['skipped', 'skip', 'unavailable', 'not_run'], true)) {
            return 'Upgrade/multisite smoke reported status ' . self::sanitize_text($reportStatus, 80) . '; not treated as upgrade proof.';
        }
        if ($status === 'fail' && $reportStatus === 'passed' && $multisiteStatus !== 'passed') {
            return 'Docker upgrade/multisite smoke reported upgrade status passed but multisite evidence status '
                . self::sanitize_text($multisiteStatus !== '' ? $multisiteStatus : 'missing', 80)
                . '; not treated as multisite runtime proof.';
        }
        if ($status === 'fail' && $reportStatus === '') {
            return 'Docker upgrade/multisite smoke did not emit a parseable inner upgrade report with status passed.';
        }

        return $this->command_summary($status, $result);
    }

    /**
     * @param array<string,mixed> $report
     * @return string[]
     */
    private static function blocker_ids(array $report): array
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

    /**
     * @param array<string,mixed> $report
     * @return array<string,int>
     */
    private static function check_status_counts(array $report): array
    {
        $counts = [
            'pass' => 0,
            'fail' => 0,
            'skip' => 0,
            'blocked' => 0,
        ];

        foreach (($report['checks'] ?? []) as $check) {
            if (!is_array($check)) {
                continue;
            }
            $status = (string) ($check['status'] ?? '');
            if (!array_key_exists($status, $counts)) {
                $counts[$status] = 0;
            }
            $counts[$status]++;
        }

        return $counts;
    }

    /**
     * @param array<string,mixed> $report
     * @param string[] $blockers
     */
    private function readiness_summary(string $status, array $blockers, array $report): string
    {
        if ($status === 'ready') {
            return 'Readiness target passed.';
        }
        if ($status === 'blocked') {
            return 'Readiness target blocked by: ' . implode(', ', array_slice($blockers, 0, 12));
        }

        return 'Readiness target returned status ' . self::sanitize_text($status, 80) . '.';
    }

    /**
     * @param array<int,array<string,mixed>> $lanes
     * @return array<string,int>
     */
    private static function status_counts(array $lanes): array
    {
        $counts = [
            'pass' => 0,
            'skip' => 0,
            'unavailable' => 0,
            'blocked' => 0,
            'fail' => 0,
        ];

        foreach ($lanes as $lane) {
            $status = (string) ($lane['status'] ?? 'fail');
            if (!array_key_exists($status, $counts)) {
                $counts[$status] = 0;
            }
            $counts[$status]++;
        }

        return $counts;
    }

    private static function normalize_release_target(string $target): string
    {
        $target = strtolower(trim($target));
        if (in_array($target, [self::TARGET_DIRECT_INSTALL, self::TARGET_PUBLIC_SUBMISSION], true)) {
            return $target;
        }

        throw new InvalidArgumentException(
            'Unknown release target: ' . self::sanitize_text($target, 80) . ' (expected direct-install or public-submission).'
        );
    }

    /**
     * @param array<int,array<string,mixed>> $lanes
     */
    private static function overall_status(array $lanes): string
    {
        if (self::required_lanes_with_status($lanes, ['fail']) !== []) {
            return 'fail';
        }
        if (self::required_lanes_with_status($lanes, ['blocked', 'skip']) !== []) {
            return 'blocked';
        }

        return 'pass';
    }

    /**
     * @param array<int,array<string,mixed>> $lanes
     * @param string[] $statuses
     * @return array<int,array<string,mixed>>
     */
    private static function required_lanes_with_status(array $lanes, array $statuses): array
    {
        $matches = [];
        foreach ($lanes as $lane) {
            if (!empty($lane['required']) && in_array((string) ($lane['status'] ?? ''), $statuses, true)) {
                $matches[] = $lane;
            }
        }

        return $matches;
    }

    /**
     * @return array<string,mixed>
     */
    private function source_metadata(string $pluginSource, string $monorepoRoot, int $timeout): array
    {
        $sha = $this->git_scalar(['rev-parse', 'HEAD'], $monorepoRoot, $timeout);
        $branch = $this->git_scalar(['rev-parse', '--abbrev-ref', 'HEAD'], $monorepoRoot, $timeout);
        $status = $this->git_scalar(['status', '--porcelain', '--', self::relative_path($monorepoRoot, $pluginSource)], $monorepoRoot, $timeout);

        return [
            'sha' => $sha !== '' ? $sha : 'unknown',
            'branch' => $branch !== '' ? $branch : 'unknown',
            'dirty' => $status !== '',
            'plugin_path' => self::relative_path($monorepoRoot, $pluginSource),
            'git_available' => $sha !== '',
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function git_scalar(array $args, string $cwd, int $timeout): string
    {
        if (!function_exists('proc_open')) {
            return '';
        }

        $result = ($this->processRunner)(array_merge(['git'], $args), $cwd, min($timeout, 15));
        if (($result['exit'] ?? 1) !== 0) {
            return '';
        }

        return self::sanitize_text(trim((string) ($result['stdout'] ?? '')), 200);
    }

    /**
     * @param array<int,string> $scriptAndArgs
     */
    private static function display_command(array $scriptAndArgs, string $prefix = 'php'): string
    {
        $parts = $prefix === '' ? [] : [$prefix];
        foreach ($scriptAndArgs as $arg) {
            if (str_starts_with($arg, '--package-dir=')) {
                $parts[] = '--package-dir=[path]';
                continue;
            }
            if (str_starts_with($arg, '--previous-package=')) {
                $parts[] = '--previous-package=[path]';
                continue;
            }
            if (str_starts_with($arg, '--plugin-src=') || str_starts_with($arg, '--monorepo-root=')) {
                [$name] = explode('=', $arg, 2);
                $parts[] = $name . '=[path]';
                continue;
            }
            $parts[] = $arg;
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<int,string> $command
     */
    private static function display_generation_command(array $command): string
    {
        $parts = [];
        foreach ($command as $arg) {
            if (str_starts_with($arg, 'COMPOSER_HOME=') || str_starts_with($arg, 'COMPOSER_CACHE_DIR=')) {
                [$name] = explode('=', $arg, 2);
                $parts[] = $name . '=[path]';
                continue;
            }
            if (str_starts_with($arg, '--output=')
                || str_starts_with($arg, '--plugin-src=')
                || str_starts_with($arg, '--monorepo-root=')
                || str_starts_with($arg, '--build-dir=')
                || str_starts_with($arg, '--composer-cache-dir=')
            ) {
                [$name] = explode('=', $arg, 2);
                $parts[] = $name . '=[path]';
                continue;
            }
            if (str_contains($arg, '/')) {
                $parts[] = self::sanitize_text($arg, 200);
                continue;
            }

            $parts[] = self::sanitize_text($arg, 200);
        }

        return implode(' ', $parts);
    }

    private static function is_safe_previous_package_ref(string $ref): bool
    {
        if ($ref === '' || strlen($ref) > 200 || $ref[0] === '-') {
            return false;
        }
        if (str_contains($ref, '..') || str_contains($ref, '//') || str_ends_with($ref, '.')) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9._\/-]+$/', $ref) === 1;
    }

    private static function command_failure_status(array $result): string
    {
        if (!empty($result['timed_out'])) {
            return 'fail';
        }

        $output = strtolower((string) ($result['stdout'] ?? '') . "\n" . (string) ($result['stderr'] ?? ''));
        foreach ([
            'command not found',
            'no such file or directory',
            'is unavailable',
            'proc_open() is unavailable',
            'zip extension is required',
            'network is disabled',
            'composer_disable_network',
            'could not launch',
        ] as $needle) {
            if (str_contains($output, $needle)) {
                return 'unavailable';
            }
        }

        return 'fail';
    }

    private static function count_prohibited_previous_ref_paths(string $paths): int
    {
        $count = 0;
        foreach (preg_split('/\r\n|\r|\n/', $paths) ?: [] as $path) {
            $path = trim(str_replace('\\', '/', $path));
            if ($path === '') {
                continue;
            }

            $basename = basename($path);
            if ($basename === '.env'
                || strtolower($basename) === 'auth.json'
                || str_ends_with(strtolower($basename), '.pem')
                || str_ends_with(strtolower($basename), '.key')
                || str_contains($path, '/.composer/')
                || str_starts_with($path, '.composer/')
                || str_contains($path, '/.ssh/')
                || str_starts_with($path, '.ssh/')
            ) {
                $count++;
            }
        }

        return $count;
    }

    private static function create_previous_package_temp_dir(): string
    {
        $base = rtrim(sys_get_temp_dir(), '/');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $path = $base . '/' . self::PREVIOUS_PACKAGE_TEMP_PREFIX . getmypid() . '-' . bin2hex(random_bytes(4));
            if (mkdir($path, 0777, true)) {
                return $path;
            }
        }

        throw new RuntimeException('Could not create temporary directory for previous direct-install package generation.');
    }

    /**
     * @param array<string,string> $env
     */
    private static function safe_composer_cache_dir(array $env, string $tempRoot): string
    {
        $candidates = [];
        if (($env['COMPOSER_CACHE_DIR'] ?? '') !== '') {
            $candidates[] = (string) $env['COMPOSER_CACHE_DIR'];
        }
        if (($env['XDG_CACHE_HOME'] ?? '') !== '') {
            $candidates[] = rtrim((string) $env['XDG_CACHE_HOME'], '/') . '/composer';
        }
        if (($env['HOME'] ?? '') !== '') {
            $candidates[] = rtrim((string) $env['HOME'], '/') . '/.cache/composer';
        }

        foreach ($candidates as $candidate) {
            $candidate = rtrim($candidate, '/');
            if ($candidate === '' || !is_dir($candidate) || !self::is_safe_local_cache_path($candidate)) {
                continue;
            }

            return $candidate;
        }

        return $tempRoot . '/composer-cache';
    }

    private static function is_safe_local_cache_path(string $path): bool
    {
        $normalized = strtolower(str_replace('\\', '/', $path));
        foreach ([
            '/.aws',
            '/.ssh',
            '/.gnupg',
            '/credentials',
            '/secret',
            '/secrets',
            '/private',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return false;
            }
        }

        $basename = basename($normalized);
        return $basename !== '.env'
            && !str_ends_with($basename, '.pem')
            && !str_ends_with($basename, '.key');
    }

    /**
     * @param array<string,string> $env
     * @return array<string,string>
     */
    private static function previous_package_build_environment(array $env, string $composerHome, string $composerCacheDir): array
    {
        return self::scrub_process_environment($env, [
            'COMPOSER_HOME' => $composerHome,
            'COMPOSER_CACHE_DIR' => $composerCacheDir,
            'COMPOSER_DISABLE_NETWORK' => '1',
        ]);
    }

    /**
     * Older archived builders reject unknown CLI options. Lex the bounded PHP
     * source without requiring ext-tokenizer so those builders keep using their
     * already isolated env cache, while newer builders receive the same cache
     * as an explicit option.
     */
    private static function release_builder_supports_explicit_composer_cache(string $builderPath): bool
    {
        if (!is_file($builderPath)) {
            return false;
        }
        $handle = fopen($builderPath, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $source = stream_get_contents($handle, self::HISTORICAL_BUILDER_SOURCE_BYTES + 1);
        } finally {
            fclose($handle);
        }
        if (!is_string($source) || strlen($source) > self::HISTORICAL_BUILDER_SOURCE_BYTES) {
            return false;
        }

        try {
            foreach (wp_fts_php_source_token_stream($source, self::HISTORICAL_BUILDER_SOURCE_BYTES) as $token) {
                if ($token[0] === 'string_literal' && in_array($token[1], ["'composer-cache-dir'", '"composer-cache-dir"'], true)) {
                    return true;
                }
            }
        } catch (RuntimeException) {
            // An unfamiliar archived builder must not weaken source analysis.
            return false;
        }

        return false;
    }

    /** @return string[] */
    private static function find_symbolic_link_paths(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item->isLink()) {
                continue;
            }
            $paths[] = self::relative_path($root, $item->getPathname());
            if (count($paths) === 50) {
                break;
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @param array<string,string> $env
     * @param array<string,string> $overrides
     * @return array<string,string>
     */
    private static function scrub_process_environment(array $env, array $overrides = []): array
    {
        $safe = [];
        foreach ($env as $key => $value) {
            if (!is_string($key) || !is_scalar($value) || !self::is_safe_process_environment_key($key)) {
                continue;
            }
            $safe[$key] = (string) $value;
        }

        foreach ($overrides as $key => $value) {
            if (!self::is_safe_process_environment_key($key)) {
                continue;
            }
            $safe[$key] = $value;
        }

        ksort($safe, SORT_STRING);

        return $safe;
    }

    private static function is_safe_process_environment_key(string $key): bool
    {
        $upper = strtoupper($key);
        if (str_starts_with($upper, 'GIT_') || str_starts_with($upper, 'SSH_')) {
            return false;
        }
        if (preg_match('/(?:TOKEN|SECRET|PASSWORD|PASS(?:PHRASE)?|CREDENTIAL|AUTH|COOKIE|API[_-]?KEY|ACCESS[_-]?KEY|PRIVATE[_-]?KEY)/i', $key) === 1) {
            return false;
        }

        return in_array($upper, [
            'COMPOSER_HOME',
            'COMPOSER_CACHE_DIR',
            'COMPOSER_DISABLE_NETWORK',
            'PATH',
            'TEMP',
            'TMP',
            'TMPDIR',
            'LANG',
            'LC_ALL',
            'LC_CTYPE',
            'SYSTEMROOT',
            'WINDIR',
            'COMSPEC',
            'PATHEXT',
        ], true);
    }

    /**
     * @param array<string,string> $env
     * @return array<int,string>
     */
    private static function environment_assignments(array $env): array
    {
        $assignments = [];
        foreach ($env as $key => $value) {
            $assignments[] = $key . '=' . $value;
        }

        return $assignments;
    }

    private static function ensure_directory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException("Could not create directory: {$path}");
        }
    }

    private static function remove_previous_package_temp_tree(string $directory): void
    {
        $directory = rtrim($directory, '/');
        if ($directory === '' || !is_dir($directory)) {
            return;
        }

        $basename = basename($directory);
        if (!str_starts_with($basename, self::PREVIOUS_PACKAGE_TEMP_PREFIX)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($directory);
    }

    public static function sanitize_text(string $text, int $maxBytes = self::OUTPUT_EXCERPT_BYTES): string
    {
        $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
        $privateKeyMarker = 'PRIVATE ' . 'KEY';
        $patterns = [
            ('/-----BEGIN [A-Z0-9 ]*' . $privateKeyMarker . '-----.*?-----END [A-Z0-9 ]*' . $privateKeyMarker . '-----/is')
                => '[redacted-private-key]',
            ('/-----BEGIN [A-Z0-9 ]*' . $privateKeyMarker . '-----/i')
                => '[redacted-private-key]',
            ('/-----END [A-Z0-9 ]*' . $privateKeyMarker . '-----/i')
                => '[redacted-private-key]',
            '/(["\'](?:[A-Z0-9_-]*(?:TOKEN|SECRET|PASSWORD|PASS|COOKIE|NONCE|AUTH)[A-Z0-9_-]*|api[_-]?key|access[_-]?key|private[_-]?key)["\']\s*:\s*["\'])[^"\']*(["\'])/i'
                => '$1[redacted]$2',
            '/\b([A-Z0-9_]*(?:TOKEN|SECRET|PASSWORD|PASS|KEY|COOKIE|NONCE|AUTH)[A-Z0-9_]*)\s*=\s*([^\s]+)/i'
                => '$1=[redacted]',
            '/(Authorization:\s*(?:Bearer|Basic)\s+)[^\s]+/i'
                => '$1[redacted]',
            '/(api[_-]?key["\']?\s*[:=]\s*["\']?)[^"\'\s,}]+/i'
                => '$1[redacted]',
            '/\b(?:SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER)\s+[^;\n]{20,};?/i'
                => '[redacted-sql]',
            '#(?<![\w])/(?:home|Users|tmp|var|private|workspace|mnt|opt)/[^\s"\'<>]+#'
                => '[path]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $replaced = preg_replace($pattern, $replacement, $text);
            if (is_string($replaced)) {
                $text = $replaced;
            }
        }

        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        return substr($text, 0, max(0, $maxBytes)) . "\n[truncated]";
    }

    public static function sanitize_value(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $childKey => $childValue) {
                $safeKey = is_string($childKey) ? self::sanitize_text($childKey, 120) : $childKey;
                $safe[$safeKey] = self::sanitize_value($childValue, is_string($childKey) ? $childKey : null);
            }

            return $safe;
        }

        if (is_string($value)) {
            if ($key !== null && self::is_sensitive_key($key)) {
                return '[redacted]';
            }

            return self::sanitize_text($value, self::OUTPUT_EXCERPT_BYTES);
        }

        return $value;
    }

    private static function numeric_or_null(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (!is_numeric($value)) {
            return null;
        }

        $string = (string) $value;

        return str_contains($string, '.') || stripos($string, 'e') !== false
            ? (float) $value
            : (int) $value;
    }

    private static function is_sensitive_key(string $key): bool
    {
        return preg_match(
            '/(?:token|secret|password|passphrase|authorization|auth|cookie|nonce|api[_-]?key|access[_-]?key|private[_-]?key)/i',
            $key
        ) === 1;
    }

    private static function existing_directory(string $path, string $label): string
    {
        $real = realpath($path);
        if (!is_string($real) || !is_dir($real)) {
            throw new InvalidArgumentException("Invalid {$label} directory.");
        }

        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private static function relative_path(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }

        return basename($path);
    }

    /**
     * @return array<string,string>
     */
    private static function current_environment(): array
    {
        $env = getenv();
        if (!is_array($env)) {
            return [];
        }

        $normalized = [];
        foreach ($env as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $options = WP_FTS_ReleaseEvidenceCollector::parse_cli_options(array_slice($argv, 1));
        if (!empty($options['help'])) {
            fwrite(STDOUT, WP_FTS_ReleaseEvidenceCollector::usage());
            exit(0);
        }

        $report = (new WP_FTS_ReleaseEvidenceCollector())->collect($options);
        fwrite(STDOUT, WP_FTS_ReleaseEvidenceCollector::render_json($report));

        exit(($report['overall_status'] ?? null) === 'fail' ? 1 : 0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Release evidence collection failed: ' . WP_FTS_ReleaseEvidenceCollector::sanitize_text($e->getMessage()) . "\n");
        exit(2);
    }
}
