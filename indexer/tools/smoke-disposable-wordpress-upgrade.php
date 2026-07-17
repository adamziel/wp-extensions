<?php
declare(strict_types=1);

/**
 * Disposable upgrade smoke for a previous direct-install ZIP upgraded to the
 * current direct-install ZIP.
 *
 * This command is intentionally skip-first. It performs no WordPress writes
 * until WP_FTS_UPGRADE_SMOKE_ALLOW=1 is set and the target WordPress root is
 * explicitly marked as disposable.
 */
final class WP_FTS_DisposableUpgradeSmokeRunner
{
    public const ALLOW_ENV = 'WP_FTS_UPGRADE_SMOKE_ALLOW';
    public const CONFIRM_PATH_ENV = 'WP_FTS_UPGRADE_SMOKE_CONFIRM_PATH';
    public const CURRENT_ZIP_ENV = 'WP_FTS_CURRENT_RELEASE_ZIP';
    public const MARKER_FILE = '.wp-fts-upgrade-smoke';
    public const NETWORK_ACTIVATE_ENV = 'WP_FTS_UPGRADE_SMOKE_NETWORK_ACTIVATE';
    public const PREVIOUS_ZIP_ENV = 'WP_FTS_PREVIOUS_RELEASE_ZIP';
    public const WP_CLI_ENV = 'WP_FTS_WP_CLI';
    public const WP_PATH_ENV = 'WP_FTS_WP_PATH';
    public const WP_URL_ENV = 'WP_FTS_WP_URL';

    private const PLUGIN_SLUG = 'indexer';
    private const REPORT_SCHEMA = 'wp-fts-disposable-upgrade-smoke-v1';
    private const OUTPUT_EXCERPT_BYTES = 900;
    private const FTS_TABLE_SUFFIXES = [
        'fts_terms',
        'fts_postings',
        'fts_docs',
        'fts_doc_lengths',
        'fts_docmeta',
        'fts_meta',
        'fts_queue',
    ];
    private const OPERATIONAL_OPTIONS = [
        'wp_fts_schema_version',
        'wp_fts_pending_index_post_ids',
        'wp_fts_sandbox_demo_post_ids',
        'wp_fts_analyzer_options',
        'wp_fts_settings',
        'wp_fts_indexing_lock',
        'wp_fts_index_health',
        'wp_fts_activation_redirect',
    ];

    /** @var callable(array<int,string>, array<string,string>): array{exit:int,stdout:string,stderr:string} */
    private $processRunner;

    /** @var array<string,string> */
    private array $env;

    private bool $usesDefaultProcessRunner;

    /**
     * @param callable(array<int,string>, array<string,string>): array{exit:int,stdout:string,stderr:string}|null $processRunner
     * @param array<string,string>|null $env
     */
    public function __construct(?callable $processRunner = null, ?array $env = null)
    {
        $this->usesDefaultProcessRunner = $processRunner === null;
        $this->processRunner = $processRunner ?? [$this, 'default_process_runner'];
        $this->env = $env ?? self::current_environment();
    }

    /**
     * @param array<int,string> $args
     * @return array<string,mixed>
     */
    public static function parse_cli_options(array $args): array
    {
        $options = [];
        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;
                continue;
            }

            foreach (['previous-zip', 'current-zip', 'report-file'] as $name) {
                $prefix = "--{$name}=";
                if (str_starts_with($arg, $prefix)) {
                    $options[str_replace('-', '_', $name)] = substr($arg, strlen($prefix));
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
            'Usage: php indexer/tools/smoke-disposable-wordpress-upgrade.php [options]',
            '',
            'Required environment for WordPress writes:',
            '  WP_FTS_WP_PATH=/path/to/disposable-wordpress',
            '  WP_FTS_UPGRADE_SMOKE_ALLOW=1',
            '  touch /path/to/disposable-wordpress/' . self::MARKER_FILE,
            '',
            'Required upgrade inputs:',
            '  --previous-zip=PATH or ' . self::PREVIOUS_ZIP_ENV . '=PATH',
            '  --current-zip=PATH or ' . self::CURRENT_ZIP_ENV . '=PATH',
            '',
            'Options:',
            '  --report-file=PATH  Write the structured upgrade report to PATH for wrapper verification.',
            '  -h, --help          Show this help.',
            '',
            'Use tools/run-disposable-upgrade-multisite-smoke.sh to create the disposable Docker site automatically.',
            '',
        ]);
    }

    /**
     * @param array<string,mixed> $options
     * @return array{exit:int,status:string,message:string,report:array<string,mixed>}
     */
    public function run(array $options = []): array
    {
        $report = [
            'schema' => self::REPORT_SCHEMA,
            'status' => 'running',
            'commands' => [],
            'cleanup' => [],
            'multisite_evidence' => self::multisite_boundary(),
        ];

        if ($this->usesDefaultProcessRunner && !function_exists('proc_open')) {
            return $this->result('skipped', 'proc_open() is unavailable; cannot launch WP-CLI.', $report);
        }

        $wpPath = $this->validated_wp_path();
        if ($wpPath === null) {
            return $this->result(
                'skipped',
                'Set WP_FTS_WP_PATH to an installed disposable WordPress root with wp-load.php.',
                $report
            );
        }

        if ($this->env_value(self::ALLOW_ENV) !== '1') {
            return $this->result(
                'skipped',
                'Set WP_FTS_UPGRADE_SMOKE_ALLOW=1 only for a disposable, non-production WordPress site.',
                $report
            );
        }

        if (!$this->is_explicitly_disposable_path($wpPath)) {
            return $this->result(
                'skipped',
                'Refusing to write: create ' . self::MARKER_FILE . ' in WP_FTS_WP_PATH or set WP_FTS_UPGRADE_SMOKE_CONFIRM_PATH to that exact root.',
                $report
            );
        }

        $previousZip = $this->release_zip_path($options, 'previous_zip', self::PREVIOUS_ZIP_ENV, 'previous direct-install ZIP');
        if ($previousZip === null) {
            return $this->result('skipped', 'Previous direct-install ZIP is unavailable; no upgrade proof was run.', $report);
        }

        $currentZip = $this->release_zip_path($options, 'current_zip', self::CURRENT_ZIP_ENV, 'current direct-install ZIP');
        if ($currentZip === null) {
            return $this->result('skipped', 'Current direct-install ZIP is unavailable; no upgrade proof was run.', $report);
        }

        $baseCommand = $this->wp_cli_base_command($wpPath);
        $createdPostIds = [];

        try {
            $installed = $this->run_process('core is-installed', array_merge($baseCommand, ['core', 'is-installed']), [], $report);
            if ($installed['exit'] !== 0) {
                $detail = self::sanitize_output(trim($installed['stderr'] . "\n" . $installed['stdout']));
                return $this->result(
                    'skipped',
                    'WP-CLI is unavailable or WordPress is not installed at WP_FTS_WP_PATH.'
                        . ($detail !== '' ? " Detail: {$detail}" : ''),
                    $report
                );
            }

            $report['previous_release_zip_sha256'] = hash_file('sha256', $previousZip) ?: null;
            $report['current_release_zip_sha256'] = hash_file('sha256', $currentZip) ?: null;
            $token = $this->token();
            $report['token'] = $token;
            $report['plugin_activation_scope'] = $this->plugin_activation_scope();

            $guardPostId = $this->create_post(
                $baseCommand,
                'WP FTS upgrade guard content ' . $token,
                'wp fts upgrade guard content must not be changed by activation upgrade or repair ' . $token,
                $report
            );
            $createdPostIds[] = $guardPostId;
            $beforePreviousActivation = $this->inspect_site('before_previous_activation', $baseCommand, $createdPostIds, $report);

            $this->require_success(
                'install and activate previous direct-install ZIP',
                array_merge($baseCommand, ['plugin', 'install', $previousZip, '--force', $this->plugin_activation_flag()]),
                $report
            );
            $statusAfterPreviousActivation = $this->require_json_success(
                'status after previous activation',
                array_merge($baseCommand, ['fts', 'status', '--format=json']),
                $report
            );
            $afterPreviousActivation = $this->inspect_site('after_previous_activation', $baseCommand, $createdPostIds, $report);
            $this->assert_schema_current($statusAfterPreviousActivation, 'status after previous activation');
            $this->assert_all_tables_exist($afterPreviousActivation, 'previous activation should create its FTS tables', false);
            $this->assert_tracked_post_unchanged($beforePreviousActivation, $afterPreviousActivation, $guardPostId, 'previous activation should not mutate existing guard content');
            $this->assert_post_count_delta($beforePreviousActivation, $afterPreviousActivation, 0, 'previous activation should not create content');

            $indexedPostId = $this->create_post(
                $baseCommand,
                'WP FTS upgrade indexed fixture ' . $token,
                'wp fts upgrade indexed fixture search continuity ' . $token,
                $report
            );
            $createdPostIds[] = $indexedPostId;
            $previousIndexing = $this->require_json_success(
                'process previous-package fixture batch',
                array_merge($baseCommand, ['fts', 'process_batch', '--batch_size=1', '--time_budget=5', '--format=json']),
                $report
            );
            $this->assert_processed_at_least_one($previousIndexing, 'previous-package fixture indexing');
            $previousSearch = $this->require_json_success(
                'search previous-package fixture token',
                array_merge($baseCommand, ['fts', 'search', $token, '--post_type=post', '--post_status=publish', '--limit=5', '--format=json']),
                $report
            );
            if (!$this->search_payload_contains_post($previousSearch, $indexedPostId)) {
                throw new RuntimeException('Previous-package search JSON did not include the disposable upgrade fixture post.');
            }
            $statusBeforeUpgrade = $this->require_json_success(
                'status before current upgrade',
                array_merge($baseCommand, ['fts', 'status', '--format=json']),
                $report
            );
            $beforeUpgrade = $this->inspect_site('before_current_upgrade', $baseCommand, $createdPostIds, $report);

            $this->require_success(
                'install current direct-install ZIP over previous package',
                array_merge($baseCommand, ['plugin', 'install', $currentZip, '--force', $this->plugin_activation_flag()]),
                $report
            );
            $statusAfterUpgrade = $this->require_json_success(
                'status after current upgrade',
                array_merge($baseCommand, ['fts', 'status', '--format=json']),
                $report
            );
            $afterUpgrade = $this->inspect_site('after_current_upgrade', $baseCommand, $createdPostIds, $report);
            $this->assert_schema_current($statusAfterUpgrade, 'status after current upgrade');
            $this->assert_all_tables_exist($afterUpgrade, 'current upgrade should retain or repair all FTS tables');
            $this->assert_tracked_post_unchanged($beforePreviousActivation, $afterUpgrade, $guardPostId, 'current upgrade should not mutate guard content');
            $this->assert_tracked_post_unchanged($beforeUpgrade, $afterUpgrade, $indexedPostId, 'current upgrade should not mutate indexed fixture content');
            $this->assert_post_count_delta($beforeUpgrade, $afterUpgrade, 0, 'current upgrade should not create content');

            $repairFirst = $this->require_json_success(
                'repair schema after upgrade',
                array_merge($baseCommand, ['fts', 'repair', '--format=json']),
                $report
            );
            $repairSecond = $this->require_json_success(
                'repeat repair schema after upgrade',
                array_merge($baseCommand, ['fts', 'repair', '--format=json']),
                $report
            );
            $statusAfterRepair = $this->require_json_success(
                'status after repeated repair',
                array_merge($baseCommand, ['fts', 'status', '--format=json']),
                $report
            );
            $afterRepair = $this->inspect_site('after_repeated_repair', $baseCommand, $createdPostIds, $report);
            $this->assert_schema_current($repairFirst, 'first repair after upgrade');
            $this->assert_schema_current($repairSecond, 'second repair after upgrade');
            $this->assert_schema_current($statusAfterRepair, 'status after repeated repair');
            $this->assert_same_schema_contract($repairFirst, $repairSecond, 'repeated repair should be idempotent');
            $this->assert_tracked_post_unchanged($beforePreviousActivation, $afterRepair, $guardPostId, 'repair after upgrade should not mutate guard content');
            $this->assert_tracked_post_unchanged($beforeUpgrade, $afterRepair, $indexedPostId, 'repair after upgrade should not mutate indexed fixture content');
            $this->assert_post_count_delta($afterUpgrade, $afterRepair, 0, 'repair after upgrade should not create content');

            $searchAfterUpgrade = $this->require_json_success(
                'search upgraded fixture token',
                array_merge($baseCommand, ['fts', 'search', $token, '--post_type=post', '--post_status=publish', '--limit=5', '--format=json']),
                $report
            );
            if (!$this->search_payload_contains_post($searchAfterUpgrade, $indexedPostId)) {
                throw new RuntimeException('Current-package search JSON did not include the disposable upgrade fixture post.');
            }

            $queuePostId = $this->create_post(
                $baseCommand,
                'WP FTS upgrade queue fixture ' . $token,
                'wp fts upgrade queue fixture health after upgrade ' . $token,
                $report
            );
            $createdPostIds[] = $queuePostId;
            $statusBeforeQueueProcess = $this->require_json_success(
                'status before upgraded queue process',
                array_merge($baseCommand, ['fts', 'status', '--format=json']),
                $report
            );
            $queueIndexing = $this->require_json_success(
                'process upgraded queue fixture batch',
                array_merge($baseCommand, ['fts', 'process_batch', '--batch_size=1', '--time_budget=5', '--format=json']),
                $report
            );
            $this->assert_processed_at_least_one($queueIndexing, 'upgraded queue fixture indexing');
            $statusAfterQueueProcess = $this->require_json_success(
                'status after upgraded queue process',
                array_merge($baseCommand, ['fts', 'status', '--format=json']),
                $report
            );

            $cleanupFailure = $this->cleanup($baseCommand, $createdPostIds, $report);
            $createdPostIds = [];
            if ($cleanupFailure !== null) {
                throw $cleanupFailure;
            }

            $multisiteEvidence = $this->run_multisite_runtime_proof($wpPath, $baseCommand, $token, $afterRepair, $report);

            $report['upgrade_evidence'] = [
                'status' => 'passed',
                'previous_activation_status' => $this->compact_payload($statusAfterPreviousActivation),
                'previous_indexing' => $this->compact_payload($previousIndexing),
                'status_before_upgrade' => $this->compact_payload($statusBeforeUpgrade),
                'status_after_upgrade' => $this->compact_payload($statusAfterUpgrade),
                'repair_first' => $this->compact_payload($repairFirst),
                'repair_second' => $this->compact_payload($repairSecond),
                'status_after_repair' => $this->compact_payload($statusAfterRepair),
            ];
            $report['search_evidence'] = [
                'previous_package_result_count' => $this->search_result_count($previousSearch),
                'current_package_result_count' => $this->search_result_count($searchAfterUpgrade),
                'matched_post_id' => $indexedPostId,
            ];
            $report['queue_evidence'] = [
                'pending_before_process' => max(0, (int) ($statusBeforeQueueProcess['pending_queue_count'] ?? 0)),
                'queue_processed' => max(0, (int) ($queueIndexing['queue_processed'] ?? $queueIndexing['processed'] ?? 0)),
                'pending_after_process' => max(0, (int) ($statusAfterQueueProcess['pending_queue_count'] ?? 0)),
            ];
            $report['content_mutation_evidence'] = [
                'guard_post_id' => $guardPostId,
                'indexed_fixture_post_id' => $indexedPostId,
                'queue_fixture_post_id' => $queuePostId,
                'upgrade_created_content_count' => 0,
                'repair_created_content_count' => 0,
                'non_fixture_content_mutated' => false,
            ];
            $report['multisite_evidence'] = $multisiteEvidence;
            $report['covered_behaviors'] = [
                'previous_direct_install_package_required' => true,
                'current_package_upgrade_from_previous_package' => true,
                'schema_version_status_after_upgrade' => true,
                'repair_idempotence_after_upgrade' => true,
                'search_continuity_for_fixture_content' => true,
                'queue_health_after_upgrade' => true,
                'activation_upgrade_repair_content_mutation_bounded_to_fixtures' => true,
                'cleanup_fixture_content' => true,
                'public_submission_artifacts_created' => false,
                'multisite_runtime_proof' => ($multisiteEvidence['status'] ?? '') === 'passed',
            ];

            return $this->result('passed', 'Disposable WordPress upgrade smoke completed.', $report);
        } catch (Throwable $e) {
            $cleanupFailure = $this->cleanup($baseCommand, $createdPostIds, $report);
            if ($cleanupFailure !== null) {
                $report['cleanup_error'] = self::sanitize_output($cleanupFailure->getMessage());
            }

            return $this->result('failed', self::sanitize_output($e->getMessage()), $report);
        }
    }

    /**
     * @param array<string,mixed> $report
     * @return array{exit:int,status:string,message:string,report:array<string,mixed>}
     */
    private function result(string $status, string $message, array $report): array
    {
        $report['status'] = $status;
        $report['message'] = $message;

        return [
            'exit' => $status === 'failed' ? 1 : 0,
            'status' => $status,
            'message' => $message,
            'report' => $report,
        ];
    }

    private function validated_wp_path(): ?string
    {
        $raw = trim($this->env_value(self::WP_PATH_ENV));
        if ($raw === '') {
            return null;
        }

        $real = realpath($raw);
        if (!is_string($real) || !is_dir($real) || !is_file($real . '/wp-load.php')) {
            return null;
        }

        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private function is_explicitly_disposable_path(string $wpPath): bool
    {
        if (is_file($wpPath . DIRECTORY_SEPARATOR . self::MARKER_FILE)) {
            return true;
        }

        $confirmed = trim($this->env_value(self::CONFIRM_PATH_ENV));
        if ($confirmed === '') {
            return false;
        }

        $real = realpath($confirmed);

        return is_string($real) && rtrim($real, DIRECTORY_SEPARATOR) === $wpPath;
    }

    /**
     * @param array<string,mixed> $options
     */
    private function release_zip_path(array $options, string $optionKey, string $envKey, string $label): ?string
    {
        $raw = trim((string) ($options[$optionKey] ?? $this->env_value($envKey)));
        if ($raw === '') {
            return null;
        }

        $real = realpath($raw);
        if (!is_string($real) || !is_file($real) || strtolower(substr($real, -4)) !== '.zip') {
            return null;
        }

        if (!is_readable($real)) {
            throw new RuntimeException("The {$label} is not readable.");
        }

        return $real;
    }

    /**
     * @return array<int,string>
     */
    private function wp_cli_base_command(string $wpPath): array
    {
        $wpCli = trim($this->env_value(self::WP_CLI_ENV));
        if ($wpCli === '') {
            $wpCli = 'wp';
        }

        $command = [$wpCli, '--path=' . $wpPath];
        $url = trim($this->env_value(self::WP_URL_ENV));
        if ($url !== '') {
            $command[] = '--url=' . $url;
        }

        return $command;
    }

    /**
     * @return array<int,string>
     */
    private function wp_cli_base_command_for_url(string $wpPath, string $url): array
    {
        $wpCli = trim($this->env_value(self::WP_CLI_ENV));
        if ($wpCli === '') {
            $wpCli = 'wp';
        }

        return [$wpCli, '--path=' . $wpPath, '--url=' . $url];
    }

    /**
     * @param array<int,string> $baseCommand
     * @param array<string,mixed> $report
     */
    private function create_post(array $baseCommand, string $title, string $content, array &$report): int
    {
        $created = $this->require_success(
            'create disposable upgrade post fixture',
            array_merge($baseCommand, [
                'post',
                'create',
                '--post_type=post',
                '--post_status=publish',
                '--post_title=' . $title,
                '--post_content=<p lang="en">' . $content . '</p>',
                '--porcelain',
            ]),
            $report
        );
        $postId = (int) trim($created['stdout']);
        if ($postId <= 0) {
            throw new RuntimeException('Disposable upgrade post fixture creation did not return a positive post id.');
        }

        return $postId;
    }

    /**
     * @param array<int,string> $baseCommand
     * @param int[] $postIds
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function inspect_site(string $phase, array $baseCommand, array $postIds, array &$report): array
    {
        return $this->require_json_success(
            "inspect upgrade state {$phase}",
            array_merge($baseCommand, ['eval', self::inspection_eval_code($phase, $postIds)]),
            $report
        );
    }

    /**
     * @param array<int,string> $command
     * @param array<string,mixed> $report
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function require_success(string $label, array $command, array &$report): array
    {
        $result = $this->run_process($label, $command, [], $report);
        if ($result['exit'] !== 0) {
            throw new RuntimeException($this->failed_command_message($label, $result));
        }

        return $result;
    }

    /**
     * @param array<int,string> $command
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function require_json_success(string $label, array $command, array &$report): array
    {
        $result = $this->require_success($label, $command, $report);
        $json = trim($result['stdout']);
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("Command {$label} did not return valid JSON: " . self::sanitize_output($json));
        }

        if (!is_array($decoded)) {
            throw new RuntimeException("Command {$label} returned non-object JSON.");
        }

        return $decoded;
    }

    /**
     * @param array<int,string> $command
     * @param array<string,string> $env
     * @param array<string,mixed> $report
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function run_process(string $label, array $command, array $env, array &$report, bool $cleanup = false): array
    {
        $result = ($this->processRunner)($command, $env);
        $normalized = [
            'exit' => max(0, (int) ($result['exit'] ?? 1)),
            'stdout' => is_string($result['stdout'] ?? null) ? $result['stdout'] : '',
            'stderr' => is_string($result['stderr'] ?? null) ? $result['stderr'] : '',
        ];

        $report[$cleanup ? 'cleanup' : 'commands'][] = [
            'label' => $label,
            'exit' => $normalized['exit'],
            'command' => $this->sanitized_command_string($command),
            'stdout_excerpt' => self::sanitize_output($normalized['stdout'], self::OUTPUT_EXCERPT_BYTES),
            'stderr_excerpt' => self::sanitize_output($normalized['stderr'], self::OUTPUT_EXCERPT_BYTES),
        ];

        return $normalized;
    }

    /**
     * @param array<int,string> $baseCommand
     * @param int[] $postIds
     * @param array<string,mixed> $report
     */
    private function cleanup(array $baseCommand, array $postIds, array &$report): ?Throwable
    {
        $failure = null;
        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds), static fn(int $id): bool => $id > 0)));
        rsort($postIds, SORT_NUMERIC);

        foreach ($postIds as $postId) {
            $deleted = $this->run_process(
                'delete disposable upgrade post fixture',
                array_merge($baseCommand, ['post', 'delete', (string) $postId, '--force']),
                [],
                $report,
                true
            );
            if ($deleted['exit'] !== 0) {
                $failure ??= new RuntimeException($this->failed_command_message('delete disposable upgrade post fixture', $deleted));
            }

            $this->run_process(
                'tombstone disposable upgrade fixture document',
                array_merge($baseCommand, ['fts', 'delete', (string) $postId]),
                [],
                $report,
                true
            );
        }

        return $failure;
    }

    /**
     * @param array{exit:int,stdout:string,stderr:string} $result
     */
    private function failed_command_message(string $label, array $result): string
    {
        $detail = trim($result['stderr'] . "\n" . $result['stdout']);

        return "Command failed during {$label} with exit {$result['exit']}."
            . ($detail !== '' ? ' Detail: ' . self::sanitize_output($detail) : '');
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function assert_schema_current(array $payload, string $label): void
    {
        $schemaStatus = (string) ($payload['schema_status'] ?? $payload['status'] ?? '');
        $schemaVersion = (int) ($payload['schema_version'] ?? $payload['stored_version'] ?? 0);
        $expectedVersion = (int) ($payload['expected_schema_version'] ?? $payload['expected_version'] ?? 0);
        if (!in_array($schemaStatus, ['current', 'ok'], true) || $schemaVersion < 1 || ($expectedVersion > 0 && $schemaVersion !== $expectedVersion)) {
            throw new RuntimeException("Unexpected schema state for {$label}.");
        }
    }

    /**
     * @param array<string,mixed> $first
     * @param array<string,mixed> $second
     */
    private function assert_same_schema_contract(array $first, array $second, string $message): void
    {
        foreach ([['schema_status', 'status'], ['schema_version', 'stored_version'], ['expected_schema_version', 'expected_version']] as $keys) {
            $firstValue = $first[$keys[0]] ?? $first[$keys[1]] ?? null;
            $secondValue = $second[$keys[0]] ?? $second[$keys[1]] ?? null;
            if ($firstValue !== $secondValue) {
                throw new RuntimeException($message . ' Schema field changed: ' . $keys[0] . '.');
            }
        }
    }

    /**
     * @param array<string,mixed> $inspection
     */
    private function assert_all_tables_exist(array $inspection, string $message, bool $requireQueue = true): void
    {
        $tables = is_array($inspection['fts_tables'] ?? null) ? $inspection['fts_tables'] : [];
        foreach (self::FTS_TABLE_SUFFIXES as $suffix) {
            if ($suffix === 'fts_queue' && !$requireQueue) {
                continue;
            }
            if (empty($tables[$suffix]['exists'])) {
                throw new RuntimeException($message . " Missing table suffix: {$suffix}.");
            }
        }
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function assert_tracked_post_unchanged(array $before, array $after, int $postId, string $message): void
    {
        $beforePost = $before['tracked_posts'][$postId] ?? null;
        $afterPost = $after['tracked_posts'][$postId] ?? null;
        if (!is_array($beforePost) || !is_array($afterPost)) {
            throw new RuntimeException($message . ' Missing tracked post evidence.');
        }

        foreach (['exists', 'title', 'status', 'content_hash'] as $key) {
            if (($beforePost[$key] ?? null) !== ($afterPost[$key] ?? null)) {
                throw new RuntimeException($message . " Tracked post {$key} changed.");
            }
        }
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function assert_post_count_delta(array $before, array $after, int $expectedDelta, string $message): void
    {
        $beforeCount = (int) ($before['content']['post_page_count'] ?? -1);
        $afterCount = (int) ($after['content']['post_page_count'] ?? -1);
        if ($beforeCount < 0 || $afterCount < 0 || ($afterCount - $beforeCount) !== $expectedDelta) {
            throw new RuntimeException($message . ' Unexpected post/page count delta.');
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function assert_processed_at_least_one(array $payload, string $label): void
    {
        if ((int) ($payload['processed'] ?? 0) < 1 && (int) ($payload['queue_processed'] ?? 0) < 1) {
            throw new RuntimeException($label . ' did not process a queued row.');
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function search_payload_contains_post(array $payload, int $postId): bool
    {
        $results = $payload['results'] ?? (array_is_list($payload) ? $payload : []);
        if (!is_array($results)) {
            return false;
        }

        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach (['post_id', 'doc_id', 'ID'] as $field) {
                if ((int) ($row[$field] ?? 0) === $postId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function search_result_count(array $payload): int
    {
        $results = $payload['results'] ?? (array_is_list($payload) ? $payload : []);

        return is_array($results) ? count($results) : 0;
    }

    /**
     * @param array<int,string> $networkBaseCommand
     * @param array<string,mixed> $mainInspection
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function run_multisite_runtime_proof(
        string $wpPath,
        array $networkBaseCommand,
        string $token,
        array $mainInspection,
        array &$report
    ): array {
        if (empty($mainInspection['is_multisite'])) {
            return self::multisite_boundary();
        }

        if (!$this->network_activation_requested()) {
            throw new RuntimeException('Multisite runtime proof requires WP_FTS_UPGRADE_SMOKE_NETWORK_ACTIVATE=1 so the disposable network activation path is explicit.');
        }

        $slug = 'wp-fts-ms-proof-' . substr($token, -8);
        $subsiteUrl = $this->multisite_subsite_url($slug);
        $createdPostIds = [];

        $created = $this->require_success(
            'create disposable multisite proof subsite',
            array_merge($networkBaseCommand, [
                'site',
                'create',
                '--slug=' . $slug,
                '--title=WP FTS Multisite Proof ' . $token,
                '--email=admin@example.test',
                '--porcelain',
            ]),
            $report
        );
        $blogId = (int) trim($created['stdout']);
        if ($blogId <= 1) {
            throw new RuntimeException('Disposable multisite proof subsite creation did not return a non-main blog id.');
        }

        $subsiteBaseCommand = $this->wp_cli_base_command_for_url($wpPath, $subsiteUrl);

        try {
            $afterCreation = $this->inspect_site('multisite_subsite_after_creation', $subsiteBaseCommand, [], $report);
            $this->assert_multisite_inspection($afterCreation, 'multisite subsite creation');
            $mainPrefix = (string) ($mainInspection['table_prefix'] ?? '');
            $subsitePrefix = (string) ($afterCreation['table_prefix'] ?? '');
            if ($mainPrefix === '' || $subsitePrefix === '' || $mainPrefix === $subsitePrefix) {
                throw new RuntimeException('Multisite subsite proof did not switch to a distinct per-site table prefix.');
            }
            $this->assert_all_tables_exist($afterCreation, 'multisite subsite initialization should create all FTS tables');

            $statusBeforeRepair = $this->require_json_success(
                'multisite subsite status before repair',
                array_merge($subsiteBaseCommand, ['fts', 'status', '--format=json']),
                $report
            );
            $repair = $this->require_json_success(
                'multisite subsite repair schema',
                array_merge($subsiteBaseCommand, ['fts', 'repair', '--format=json']),
                $report
            );
            $statusAfterRepair = $this->require_json_success(
                'multisite subsite status after repair',
                array_merge($subsiteBaseCommand, ['fts', 'status', '--format=json']),
                $report
            );
            $afterRepair = $this->inspect_site('multisite_subsite_after_repair', $subsiteBaseCommand, [], $report);
            $this->assert_schema_current($statusBeforeRepair, 'multisite subsite status before repair');
            $this->assert_schema_current($repair, 'multisite subsite repair');
            $this->assert_schema_current($statusAfterRepair, 'multisite subsite status after repair');
            $this->assert_multisite_inspection($afterRepair, 'multisite subsite repair');
            $this->assert_all_tables_exist($afterRepair, 'multisite subsite repair should retain all FTS tables');

            $indexedPostId = $this->create_post(
                $subsiteBaseCommand,
                'WP FTS multisite indexed fixture ' . $token,
                'wp fts multisite indexed fixture search continuity ' . $token,
                $report
            );
            $createdPostIds[] = $indexedPostId;
            $indexing = $this->require_json_success(
                'process multisite subsite fixture batch',
                array_merge($subsiteBaseCommand, ['fts', 'process_batch', '--batch_size=1', '--time_budget=5', '--format=json']),
                $report
            );
            $this->assert_processed_at_least_one($indexing, 'multisite subsite fixture indexing');
            $search = $this->require_json_success(
                'search multisite subsite fixture token',
                array_merge($subsiteBaseCommand, ['fts', 'search', $token, '--post_type=post', '--post_status=publish', '--limit=5', '--format=json']),
                $report
            );
            if (!$this->search_payload_contains_post($search, $indexedPostId)) {
                throw new RuntimeException('Multisite subsite search JSON did not include the disposable fixture post.');
            }

            $queuePostId = $this->create_post(
                $subsiteBaseCommand,
                'WP FTS multisite queue fixture ' . $token,
                'wp fts multisite queue fixture health ' . $token,
                $report
            );
            $createdPostIds[] = $queuePostId;
            $statusBeforeQueueProcess = $this->require_json_success(
                'multisite subsite status before queue process',
                array_merge($subsiteBaseCommand, ['fts', 'status', '--format=json']),
                $report
            );
            $queueIndexing = $this->require_json_success(
                'process multisite subsite queue batch',
                array_merge($subsiteBaseCommand, ['fts', 'process_batch', '--batch_size=1', '--time_budget=5', '--format=json']),
                $report
            );
            $this->assert_processed_at_least_one($queueIndexing, 'multisite subsite queue indexing');
            $statusAfterQueueProcess = $this->require_json_success(
                'multisite subsite status after queue process',
                array_merge($subsiteBaseCommand, ['fts', 'status', '--format=json']),
                $report
            );

            $deletionFilter = $this->require_json_success(
                'verify multisite subsite deletion table filter',
                array_merge($networkBaseCommand, ['eval', self::multisite_deletion_filter_eval_code($blogId)]),
                $report
            );
            $this->assert_multisite_deletion_filter($deletionFilter);

            $cleanupFailure = $this->cleanup($subsiteBaseCommand, $createdPostIds, $report);
            $createdPostIds = [];
            if ($cleanupFailure !== null) {
                throw $cleanupFailure;
            }

            return [
                'status' => 'passed',
                'activation_scope' => 'network',
                'main_blog_table_prefix' => $mainPrefix,
                'subsite_blog_id' => $blogId,
                'subsite_table_prefix' => $subsitePrefix,
                'subsite_tables' => $this->compact_payload($afterCreation['fts_tables'] ?? []),
                'status_before_repair' => $this->compact_payload($statusBeforeRepair),
                'repair' => $this->compact_payload($repair),
                'status_after_repair' => $this->compact_payload($statusAfterRepair),
                'search_result_count' => $this->search_result_count($search),
                'matched_post_id' => $indexedPostId,
                'queue' => [
                    'pending_before_process' => max(0, (int) ($statusBeforeQueueProcess['pending_queue_count'] ?? 0)),
                    'queue_processed' => max(0, (int) ($queueIndexing['queue_processed'] ?? $queueIndexing['processed'] ?? 0)),
                    'pending_after_process' => max(0, (int) ($statusAfterQueueProcess['pending_queue_count'] ?? 0)),
                ],
                'deletion_table_filter' => $this->compact_payload($deletionFilter),
                'cleanup_fixture_content' => true,
            ];
        } catch (Throwable $e) {
            $cleanupFailure = $this->cleanup($subsiteBaseCommand, $createdPostIds, $report);
            if ($cleanupFailure !== null) {
                $report['cleanup_error'] = self::sanitize_output($cleanupFailure->getMessage());
            }

            throw $e;
        }
    }

    private function plugin_activation_scope(): string
    {
        return $this->network_activation_requested() ? 'network' : 'site';
    }

    private function plugin_activation_flag(): string
    {
        return $this->network_activation_requested() ? '--activate-network' : '--activate';
    }

    private function network_activation_requested(): bool
    {
        return in_array(
            strtolower(trim($this->env_value(self::NETWORK_ACTIVATE_ENV))),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    private function multisite_subsite_url(string $slug): string
    {
        $rootUrl = rtrim(trim($this->env_value(self::WP_URL_ENV)), '/');
        if ($rootUrl === '') {
            throw new RuntimeException('Multisite runtime proof requires WP_FTS_WP_URL so WP-CLI can target the disposable subsite.');
        }

        return $rootUrl . '/' . trim($slug, '/') . '/';
    }

    /**
     * @param array<string,mixed> $inspection
     */
    private function assert_multisite_inspection(array $inspection, string $label): void
    {
        if (empty($inspection['is_multisite'])) {
            throw new RuntimeException("Expected multisite WordPress context for {$label}.");
        }

        if ((string) ($inspection['table_prefix'] ?? '') === '') {
            throw new RuntimeException("Missing table-prefix evidence for {$label}.");
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function assert_multisite_deletion_filter(array $payload): void
    {
        if (empty($payload['site_found']) || (string) ($payload['target_prefix'] ?? '') === '') {
            throw new RuntimeException('Multisite deletion-table filter proof did not resolve the target site prefix.');
        }

        if (empty($payload['seed_tables_preserved']) || empty($payload['all_expected_fts_tables_present'])) {
            throw new RuntimeException('Multisite deletion-table filter did not preserve seed tables and contribute all expected FTS tables.');
        }
    }

    /**
     * @return array<string,string>
     */
    private static function multisite_boundary(): array
    {
        return [
            'status' => 'not_run',
            'reason' => 'The current upgrade wrapper installs a single-site disposable WordPress root. Multisite upgrade lifecycle proof is intentionally recorded as a not-run boundary until a dedicated disposable multisite topology is added.',
        ];
    }

    /**
     * @param int[] $postIds
     */
    private static function inspection_eval_code(string $phase, array $postIds): string
    {
        $phaseLiteral = var_export($phase, true);
        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds), static fn(int $id): bool => $id > 0)));
        $postIdsLiteral = var_export($postIds, true);
        $suffixesLiteral = var_export(self::FTS_TABLE_SUFFIXES, true);
        $optionsLiteral = var_export(self::OPERATIONAL_OPTIONS, true);
        $hookLiteral = var_export('wp_fts_process_index_queue', true);

        return <<<PHP
global \$wpdb;
\$phase = {$phaseLiteral};
\$suffixes = {$suffixesLiteral};
\$option_names = {$optionsLiteral};
\$tracked_post_ids = {$postIdsLiteral};
\$hook = {$hookLiteral};
\$prefix = isset(\$wpdb->prefix) ? (string) \$wpdb->prefix : 'wp_';
\$posts_table = isset(\$wpdb->posts) ? (string) \$wpdb->posts : \$prefix . 'posts';
\$escape_identifier = static function (string \$identifier): string {
    return '`' . str_replace('`', '``', \$identifier) . '`';
};
\$tables = [];
\$row_counts = [];
foreach (\$suffixes as \$suffix) {
    \$table = \$prefix . \$suffix;
    \$exists = \$wpdb->get_var(\$wpdb->prepare('SHOW TABLES LIKE %s', \$table)) === \$table;
    \$tables[\$suffix] = [
        'name' => \$table,
        'exists' => \$exists,
    ];
    \$row_counts[\$suffix] = \$exists ? (int) \$wpdb->get_var('SELECT COUNT(*) FROM ' . \$escape_identifier(\$table)) : null;
}
\$options = [];
foreach (\$option_names as \$option_name) {
    \$missing = '__wp_fts_upgrade_missing__';
    \$value = get_option(\$option_name, \$missing);
    \$entry = [
        'exists' => \$value !== \$missing,
    ];
    if (\$option_name === 'wp_fts_pending_index_post_ids') {
        \$entry['queue_count'] = is_array(\$value) ? count(\$value) : 0;
    }
    if (\$option_name === 'wp_fts_schema_version' && \$value !== \$missing) {
        \$entry['schema_version'] = is_scalar(\$value) ? (int) \$value : 0;
    }
    \$options[\$option_name] = \$entry;
}
\$tracked = [];
foreach (\$tracked_post_ids as \$post_id) {
    \$post = get_post((int) \$post_id);
    \$tracked[(int) \$post_id] = [
        'exists' => \$post !== null,
        'title' => \$post ? (string) \$post->post_title : '',
        'status' => \$post ? (string) \$post->post_status : '',
        'content_hash' => \$post ? sha1((string) \$post->post_content) : '',
    ];
}
\$scheduled = function_exists('wp_next_scheduled') ? wp_next_scheduled(\$hook) : false;
\$payload = [
    'phase' => \$phase,
    'is_multisite' => function_exists('is_multisite') ? (bool) is_multisite() : false,
    'table_prefix' => \$prefix,
    'fts_tables' => \$tables,
    'fts_row_counts' => \$row_counts,
    'options' => \$options,
    'cron' => [
        'hook' => \$hook,
        'scheduled' => is_numeric(\$scheduled) && (int) \$scheduled > 0,
        'next_run_at' => is_numeric(\$scheduled) && (int) \$scheduled > 0 ? gmdate('Y-m-d\\TH:i:s\\Z', (int) \$scheduled) : '',
    ],
    'content' => [
        'post_page_count' => (int) \$wpdb->get_var("SELECT COUNT(*) FROM " . \$escape_identifier(\$posts_table) . " WHERE post_type IN ('post','page') AND post_status NOT IN ('auto-draft','inherit')"),
    ],
    'tracked_posts' => \$tracked,
];
echo wp_json_encode(\$payload);
PHP;
    }

    private static function multisite_deletion_filter_eval_code(int $blogId): string
    {
        $blogId = max(0, $blogId);
        $suffixesLiteral = var_export(self::FTS_TABLE_SUFFIXES, true);

        return <<<PHP
global \$wpdb;
\$blog_id = {$blogId};
\$suffixes = {$suffixesLiteral};
\$site = function_exists('get_site') ? get_site(\$blog_id) : null;
\$prefix = isset(\$wpdb) && method_exists(\$wpdb, 'get_blog_prefix') ? (string) \$wpdb->get_blog_prefix(\$blog_id) : '';
\$seed = \$prefix !== '' ? [\$prefix . 'posts', \$prefix . 'options'] : [];
\$tables = (is_object(\$site) && function_exists('apply_filters')) ? apply_filters('wpmu_drop_tables', \$seed, \$site) : \$seed;
if (!is_array(\$tables)) {
    \$tables = [];
}
\$tables = array_values(array_unique(array_map('strval', \$tables)));
\$expected = [];
foreach (\$suffixes as \$suffix) {
    \$expected[] = \$prefix . \$suffix;
}
\$present = array_values(array_intersect(\$expected, \$tables));
\$payload = [
    'blog_id' => \$blog_id,
    'site_found' => is_object(\$site),
    'target_prefix' => \$prefix,
    'seed_tables_preserved' => count(array_intersect(\$seed, \$tables)) === count(\$seed),
    'expected_fts_tables' => \$expected,
    'contributed_fts_tables' => \$present,
    'all_expected_fts_tables_present' => count(\$expected) > 0 && count(\$present) === count(\$expected),
    'unique_table_count' => count(\$tables),
];
echo wp_json_encode(\$payload);
PHP;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function compact_payload(array $payload): array
    {
        $safePayload = self::sanitize_evidence_value($payload);
        if (!is_array($safePayload)) {
            $safePayload = ['value' => $safePayload];
        }

        $encoded = json_encode($safePayload, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return [
                'truncated' => true,
                'excerpt' => '[unencodable payload]',
            ];
        }

        if (strlen($encoded) <= 1400) {
            return $safePayload;
        }

        return [
            'truncated' => true,
            'excerpt' => self::sanitize_output($encoded, 1400),
        ];
    }

    private static function sanitize_evidence_value(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $childKey => $childValue) {
                $safeKey = is_string($childKey) ? self::sanitize_output($childKey, 200) : $childKey;
                $safe[$safeKey] = self::sanitize_evidence_value(
                    $childValue,
                    is_string($childKey) ? $childKey : null
                );
            }

            return $safe;
        }

        if (is_string($value)) {
            if ($key !== null && self::is_sensitive_evidence_key($key)) {
                return '[redacted]';
            }

            return self::sanitize_output($value, 1200);
        }

        return $value;
    }

    private static function is_sensitive_evidence_key(string $key): bool
    {
        return preg_match(
            '/(?:token|secret|password|passphrase|authorization|auth|cookie|nonce|api[_-]?key|access[_-]?key|private[_-]?key)/i',
            $key
        ) === 1;
    }

    private function token(): string
    {
        return 'wpftsupgrade' . substr(hash('sha256', getmypid() . ':' . microtime(true) . ':' . random_int(1, PHP_INT_MAX)), 0, 12);
    }

    private function env_value(string $key): string
    {
        return (string) ($this->env[$key] ?? '');
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

    /**
     * @param array<int,string> $command
     * @param array<string,string> $env
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function default_process_runner(array $command, array $env): array
    {
        if (!function_exists('proc_open')) {
            return [
                'exit' => 127,
                'stdout' => '',
                'stderr' => 'proc_open() is unavailable.',
            ];
        }

        $baseEnv = getenv();
        if (!is_array($baseEnv)) {
            $baseEnv = [];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, dirname(__DIR__), array_merge($baseEnv, $env));
        if (!is_resource($process)) {
            return [
                'exit' => 127,
                'stdout' => '',
                'stderr' => 'Could not start process: ' . $this->sanitized_command_string($command),
            ];
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
     * @param array<int,string> $command
     */
    private function sanitized_command_string(array $command): string
    {
        $parts = [];
        foreach ($command as $arg) {
            $redacted = $arg;
            if (str_starts_with($arg, '--path=')) {
                $redacted = '--path=[wp-path]';
            } elseif (str_starts_with($arg, '--url=')) {
                $redacted = '--url=[wp-url]';
            } elseif ($arg !== '' && str_starts_with($arg, '/') && str_ends_with(strtolower($arg), '.zip')) {
                $redacted = '[release-zip]';
            } elseif (str_contains($arg, 'global $wpdb;')) {
                $redacted = '[eval-code]';
            } elseif (str_starts_with($arg, '/')) {
                $redacted = '[path]';
            }
            $parts[] = escapeshellarg($redacted);
        }

        return implode(' ', $parts);
    }

    public static function sanitize_output(string $text, int $maxBytes = self::OUTPUT_EXCERPT_BYTES): string
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
}

/**
 * @param array{exit:int,status:string,message:string,report:array<string,mixed>} $result
 */
function wp_fts_disposable_upgrade_smoke_write_cli_result(array $result): void
{
    if ($result['status'] === 'skipped') {
        fwrite(STDOUT, "SKIP: {$result['message']}\n");
        return;
    }

    $json = json_encode($result['report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $json = is_string($json) ? $json : '{}';
    if ($result['status'] === 'failed') {
        fwrite(STDERR, "FAIL: {$result['message']}\n{$json}\n");
        return;
    }

    fwrite(STDOUT, $json . "\nPASS: {$result['message']}\n");
}

/**
 * @param array{exit:int,status:string,message:string,report:array<string,mixed>} $result
 */
function wp_fts_disposable_upgrade_smoke_write_report_file(array $result, string $path): void
{
    $json = json_encode($result['report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Could not encode upgrade smoke report file.');
    }

    if (file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException('Could not write upgrade smoke report file.');
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $options = WP_FTS_DisposableUpgradeSmokeRunner::parse_cli_options(array_slice($argv, 1));
        if (!empty($options['help'])) {
            fwrite(STDOUT, WP_FTS_DisposableUpgradeSmokeRunner::usage());
            exit(0);
        }

        $result = (new WP_FTS_DisposableUpgradeSmokeRunner())->run($options);
        if (is_string($options['report_file'] ?? null)) {
            wp_fts_disposable_upgrade_smoke_write_report_file($result, $options['report_file']);
        }
        wp_fts_disposable_upgrade_smoke_write_cli_result($result);
        exit($result['exit']);
    } catch (Throwable $e) {
        fwrite(STDERR, 'FAIL: ' . WP_FTS_DisposableUpgradeSmokeRunner::sanitize_output($e->getMessage()) . "\n");
        exit(1);
    }
}
