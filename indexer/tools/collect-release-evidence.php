<?php
declare(strict_types=1);

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
    private const DEFAULT_TIMEOUT_SECONDS = 90;

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

            foreach (['format', 'release-target', 'plugin-src', 'monorepo-root', 'direct-package-dir', 'timeout'] as $name) {
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
                    'multisite_policy' => 'single-site Docker lifecycle proof only; multisite lifecycle proof is explicitly not run by this lane',
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
                'target_policy' => 'direct-install/operator lifecycle evidence only; not public-submission readiness',
                'multisite_policy' => 'single-site Docker lifecycle proof only; multisite lifecycle proof is explicitly not run by this lane',
            ],
            'required' => false,
        ];
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

        return [
            'id' => 'production_scale_benchmark',
            'label' => 'PR-safe production-scale benchmark',
            'status' => $passed ? 'pass' : 'fail',
            'command' => self::display_command($args),
            'exit_code' => $result['exit'],
            'summary' => $passed
                ? sprintf(
                    'Benchmark passed for %d generated documents with %d query checks.',
                    (int) ($metrics['indexed_documents'] ?? 0),
                    (int) ($metrics['query_checks_passed'] ?? 0)
                )
                : 'Benchmark failed: ' . self::sanitize_text(implode('; ', array_map('strval', $failures)), 400),
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
                    'memory_delta_bytes' => $metrics['memory_delta_bytes'] ?? null,
                    'peak_memory_delta_bytes' => $metrics['peak_memory_delta_bytes'] ?? null,
                ]),
                'failure_count' => count($failures),
                'stdout_truncated' => !empty($result['stdout_truncated']),
                'stderr_excerpt' => self::sanitize_text($result['stderr'], self::OUTPUT_EXCERPT_BYTES),
                'stderr_truncated' => !empty($result['stderr_truncated']),
            ],
            'required' => true,
        ];
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
                return $decoded;
            }
            if (($decoded['schema'] ?? null) === 'wp-fts-disposable-lifecycle-wrapper-proof-v1') {
                return [
                    'schema' => $decoded['inner_report_schema'] ?? null,
                    'status' => $decoded['inner_report_status'] ?? null,
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
            return $result['exit'] === 0 ? 'pass' : 'fail';
        }
        if (in_array($reportStatus, ['skipped', 'skip', 'unavailable'], true)) {
            return 'skip';
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
            return 'Docker lifecycle smoke completed with inner lifecycle report status passed.';
        }
        if (in_array($reportStatus, ['skipped', 'skip', 'unavailable'], true)) {
            return 'Inner lifecycle smoke reported status ' . self::sanitize_text($reportStatus, 80) . '; not treated as lifecycle proof.';
        }
        if ($status === 'fail' && $reportStatus === '') {
            return 'Docker lifecycle smoke did not emit a parseable inner lifecycle report with status passed.';
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
            if (str_starts_with($arg, '--plugin-src=') || str_starts_with($arg, '--monorepo-root=')) {
                [$name] = explode('=', $arg, 2);
                $parts[] = $name . '=[path]';
                continue;
            }
            $parts[] = $arg;
        }

        return implode(' ', $parts);
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
