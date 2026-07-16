<?php
declare(strict_types=1);

/**
 * Disposable WordPress lifecycle smoke for activation, repair, deactivation,
 * and uninstall retention boundaries.
 *
 * This command is intentionally skip-first. It performs no WordPress writes
 * until WP_FTS_LIFECYCLE_SMOKE_ALLOW=1 is set and the target WordPress root is
 * explicitly marked as disposable.
 */
final class WP_FTS_DisposableLifecycleSmokeRunner
{
    public const ALLOW_ENV = 'WP_FTS_LIFECYCLE_SMOKE_ALLOW';
    public const CONFIRM_PATH_ENV = 'WP_FTS_LIFECYCLE_SMOKE_CONFIRM_PATH';
    public const MARKER_FILE = '.wp-fts-lifecycle-smoke';
    public const WP_CLI_ENV = 'WP_FTS_WP_CLI';
    public const WP_PATH_ENV = 'WP_FTS_WP_PATH';
    public const WP_URL_ENV = 'WP_FTS_WP_URL';

    private const PLUGIN_SLUG = 'indexer';
    private const PLUGIN_BASENAME = 'indexer/indexer.php';
    private const REPORT_SCHEMA = 'wp-fts-disposable-lifecycle-smoke-v1';
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
            $prefix = '--report-file=';
            if (str_starts_with($arg, $prefix)) {
                $reportFile = trim(substr($arg, strlen($prefix)));
                if ($reportFile === '') {
                    throw new InvalidArgumentException('--report-file requires a path.');
                }
                $options['report_file'] = $reportFile;
                continue;
            }

            throw new InvalidArgumentException("Unknown option: {$arg}");
        }

        return $options;
    }

    public static function usage(): string
    {
        return implode("\n", [
            'Usage: php indexer/tools/smoke-disposable-wordpress-lifecycle.php',
            '',
            'Required environment for WordPress writes:',
            '  WP_FTS_WP_PATH=/path/to/disposable-wordpress',
            '  WP_FTS_LIFECYCLE_SMOKE_ALLOW=1',
            '  touch /path/to/disposable-wordpress/' . self::MARKER_FILE,
            '',
            'Options:',
            '  --report-file=PATH  Write the structured lifecycle report to PATH for wrapper verification.',
            '',
            'The target site must already have the indexer plugin source installed but inactive.',
            'Use tools/run-disposable-lifecycle-smoke.sh to create that disposable Docker site automatically.',
            '',
        ]);
    }

    /**
     * @return array{exit:int,status:string,message:string,report:array<string,mixed>}
     */
    public function run(): array
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
                'Set WP_FTS_LIFECYCLE_SMOKE_ALLOW=1 only for a disposable, non-production WordPress site.',
                $report
            );
        }

        if (!$this->is_explicitly_disposable_path($wpPath)) {
            return $this->result(
                'skipped',
                'Refusing to write: create ' . self::MARKER_FILE . ' in WP_FTS_WP_PATH or set WP_FTS_LIFECYCLE_SMOKE_CONFIRM_PATH to that exact root.',
                $report
            );
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

            $pluginStatus = $this->run_process(
                'verify plugin source is installed',
                array_merge($baseCommand, ['plugin', 'status', self::PLUGIN_SLUG]),
                [],
                $report
            );
            if ($pluginStatus['exit'] !== 0) {
                return $this->result(
                    'skipped',
                    'Install the indexer plugin source into the disposable WordPress root before running the lifecycle smoke.',
                    $report
                );
            }

            $active = $this->run_process(
                'verify plugin starts inactive',
                array_merge($baseCommand, ['plugin', 'is-active', self::PLUGIN_SLUG]),
                [],
                $report
            );
            if ($active['exit'] === 0) {
                return $this->result(
                    'skipped',
                    'Lifecycle smoke requires an inactive plugin source so activation evidence starts from a clean disposable site.',
                    $report
                );
            }

            $preExistingPostId = $this->create_post(
                $baseCommand,
                'WP FTS lifecycle pre-existing content',
                'wp fts lifecycle pre-existing content must not be indexed during activation or repair',
                $report
            );
            $createdPostIds[] = $preExistingPostId;

            $beforeActivation = $this->inspect_site('before_activation', $baseCommand, $createdPostIds, $report);

            $this->require_success(
                'activate plugin',
                array_merge($baseCommand, ['plugin', 'activate', self::PLUGIN_SLUG]),
                $report
            );

            $statusAfterActivation = $this->require_json_success(
                'status after activation',
                array_merge($baseCommand, ['fts', 'status', '--format=json']),
                $report
            );
            $afterActivation = $this->inspect_site('after_activation', $baseCommand, $createdPostIds, $report);
            $this->assert_schema_current($statusAfterActivation, 'status after activation');
            $this->assert_all_tables_exist($afterActivation, 'activation should create all FTS tables');
            $this->assert_fts_rows_zero($afterActivation, 'activation should not index pre-existing content');
            $this->assert_tracked_post_unchanged($beforeActivation, $afterActivation, $preExistingPostId, 'activation should not mutate pre-existing content');
            $this->assert_post_count_unchanged($beforeActivation, $afterActivation, 'activation should not create demo posts');

            $this->require_json_success(
                'drop one disposable FTS table before repair',
                array_merge($baseCommand, ['eval', self::drop_fts_table_eval_code('fts_meta')]),
                $report
            );
            $repair = $this->require_json_success(
                'repair schema',
                array_merge($baseCommand, ['fts', 'repair', '--format=json']),
                $report
            );
            $statusAfterRepair = $this->require_json_success(
                'status after repair',
                array_merge($baseCommand, ['fts', 'status', '--format=json']),
                $report
            );
            $afterRepair = $this->inspect_site('after_repair', $baseCommand, $createdPostIds, $report);
            $this->assert_schema_current($repair, 'repair schema');
            $this->assert_schema_current($statusAfterRepair, 'status after repair');
            $this->assert_all_tables_exist($afterRepair, 'repair should recreate missing FTS tables');
            $this->assert_fts_rows_zero($afterRepair, 'repair should not index pre-existing content');
            $this->assert_tracked_post_unchanged($beforeActivation, $afterRepair, $preExistingPostId, 'repair should not mutate pre-existing content');
            $this->assert_post_count_unchanged($beforeActivation, $afterRepair, 'repair should not create demo posts');

            $indexedPostId = $this->create_post(
                $baseCommand,
                'WP FTS lifecycle indexed fixture',
                'wp fts lifecycle indexed fixture retention proof',
                $report
            );
            $createdPostIds[] = $indexedPostId;
            $indexing = $this->require_json_success(
                'process one queued lifecycle fixture',
                array_merge($baseCommand, ['fts', 'process_batch', '--batch_size=1', '--time_budget=5', '--format=json']),
                $report
            );
            if ((int) ($indexing['processed'] ?? 0) < 1) {
                throw new RuntimeException('Lifecycle fixture indexing batch did not process a row.');
            }
            $afterIndexing = $this->inspect_site('after_indexing', $baseCommand, $createdPostIds, $report);
            $this->assert_retained_index_data_exists($afterIndexing, 'indexing should create data for retention proof');

            $queuedPostId = $this->create_post(
                $baseCommand,
                'WP FTS lifecycle queued fixture',
                'wp fts lifecycle queued fixture remains pending until uninstall cleanup',
                $report
            );
            $createdPostIds[] = $queuedPostId;
            $beforeDeactivation = $this->inspect_site('before_deactivation', $baseCommand, $createdPostIds, $report);
            $this->assert_cron_scheduled($beforeDeactivation, 'fixture save should leave scheduled queue processing before deactivation');
            $this->assert_pending_queue($beforeDeactivation, 'fixture save should leave pending queue state before deactivation');

            $this->require_success(
                'deactivate plugin',
                array_merge($baseCommand, ['plugin', 'deactivate', self::PLUGIN_SLUG]),
                $report
            );
            $afterDeactivation = $this->inspect_site('after_deactivation', $baseCommand, $createdPostIds, $report);
            $this->assert_cron_not_scheduled($afterDeactivation, 'deactivation should clear scheduled queue processing');
            $this->assert_fts_row_counts_same($beforeDeactivation, $afterDeactivation, 'deactivation should retain FTS table data');
            $this->assert_pending_queue($afterDeactivation, 'deactivation should not erase durable pending queue state');

            $this->require_success(
                'uninstall plugin',
                array_merge($baseCommand, ['plugin', 'uninstall', self::PLUGIN_SLUG]),
                $report
            );
            $afterUninstall = $this->inspect_site('after_uninstall', $baseCommand, $createdPostIds, $report);
            $this->assert_cron_not_scheduled($afterUninstall, 'uninstall should leave scheduled queue processing cleared');
            $this->assert_all_tables_exist($afterUninstall, 'uninstall should retain FTS tables');
            $this->assert_fts_row_counts_same($beforeDeactivation, $afterUninstall, 'uninstall should retain FTS table data');
            $this->assert_operational_options_removed($afterUninstall, 'uninstall should clear plugin operational options and queue state');

            $cleanupFailure = $this->cleanup($baseCommand, $createdPostIds, $report);
            $createdPostIds = [];
            if ($cleanupFailure !== null) {
                throw $cleanupFailure;
            }

            $report['lifecycle_evidence'] = [
                'activation_status' => $this->compact_payload($statusAfterActivation),
                'repair_status' => $this->compact_payload($repair),
                'status_after_repair' => $this->compact_payload($statusAfterRepair),
                'indexing_batch' => $this->compact_payload($indexing),
                'before_activation' => $this->compact_payload($beforeActivation),
                'after_activation' => $this->compact_payload($afterActivation),
                'after_repair' => $this->compact_payload($afterRepair),
                'before_deactivation' => $this->compact_payload($beforeDeactivation),
                'after_deactivation' => $this->compact_payload($afterDeactivation),
                'after_uninstall' => $this->compact_payload($afterUninstall),
            ];
            $report['covered_behaviors'] = [
                'activation_creates_schema' => true,
                'repair_recreates_missing_plugin_table' => true,
                'activation_and_repair_do_not_index_existing_content' => true,
                'activation_and_repair_do_not_create_demo_posts' => true,
                'status_and_repair_emit_schema_json' => true,
                'deactivation_clears_scheduled_queue_processing' => true,
                'deactivation_retains_index_data' => true,
                'uninstall_clears_operational_options_and_queue_state' => true,
                'uninstall_retains_index_tables_and_data' => true,
                'public_submission_artifacts_created' => false,
            ];

            return $this->result('passed', 'Disposable WordPress lifecycle smoke completed.', $report);
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
     * @param array<int,string> $baseCommand
     * @param array<string,mixed> $report
     */
    private function create_post(array $baseCommand, string $title, string $content, array &$report): int
    {
        $created = $this->require_success(
            'create disposable lifecycle post fixture',
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
            throw new RuntimeException('Disposable lifecycle post fixture creation did not return a positive post id.');
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
            "inspect lifecycle state {$phase}",
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
                'delete disposable lifecycle post fixture',
                array_merge($baseCommand, ['post', 'delete', (string) $postId, '--force']),
                [],
                $report,
                true
            );
            if ($deleted['exit'] !== 0) {
                $failure ??= new RuntimeException($this->failed_command_message('delete disposable lifecycle post fixture', $deleted));
            }
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
     * @param array<string,mixed> $inspection
     */
    private function assert_all_tables_exist(array $inspection, string $message): void
    {
        $tables = is_array($inspection['fts_tables'] ?? null) ? $inspection['fts_tables'] : [];
        foreach (self::FTS_TABLE_SUFFIXES as $suffix) {
            if (empty($tables[$suffix]['exists'])) {
                throw new RuntimeException($message . " Missing table suffix: {$suffix}.");
            }
        }
    }

    /**
     * @param array<string,mixed> $inspection
     */
    private function assert_fts_rows_zero(array $inspection, string $message): void
    {
        $counts = is_array($inspection['fts_row_counts'] ?? null) ? $inspection['fts_row_counts'] : [];
        foreach (self::FTS_TABLE_SUFFIXES as $suffix) {
            if ((int) ($counts[$suffix] ?? 0) !== 0) {
                throw new RuntimeException($message . " Table {$suffix} has rows.");
            }
        }
    }

    /**
     * @param array<string,mixed> $inspection
     */
    private function assert_retained_index_data_exists(array $inspection, string $message): void
    {
        $counts = is_array($inspection['fts_row_counts'] ?? null) ? $inspection['fts_row_counts'] : [];
        if ((int) ($counts['fts_docs'] ?? 0) < 1 || (int) ($counts['fts_postings'] ?? 0) < 1) {
            throw new RuntimeException($message . ' Expected indexed document and posting rows.');
        }
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function assert_fts_row_counts_same(array $before, array $after, string $message): void
    {
        $beforeCounts = is_array($before['fts_row_counts'] ?? null) ? $before['fts_row_counts'] : [];
        $afterCounts = is_array($after['fts_row_counts'] ?? null) ? $after['fts_row_counts'] : [];
        foreach (self::FTS_TABLE_SUFFIXES as $suffix) {
            if ($suffix === 'fts_queue') {
                continue;
            }
            if ((int) ($beforeCounts[$suffix] ?? -1) !== (int) ($afterCounts[$suffix] ?? -2)) {
                throw new RuntimeException($message . " Row count changed for {$suffix}.");
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
    private function assert_post_count_unchanged(array $before, array $after, string $message): void
    {
        if ((int) ($before['content']['post_page_count'] ?? -1) !== (int) ($after['content']['post_page_count'] ?? -2)) {
            throw new RuntimeException($message . ' WordPress post/page count changed.');
        }
    }

    /**
     * @param array<string,mixed> $inspection
     */
    private function assert_cron_scheduled(array $inspection, string $message): void
    {
        if (empty($inspection['cron']['scheduled'])) {
            throw new RuntimeException($message);
        }
    }

    /**
     * @param array<string,mixed> $inspection
     */
    private function assert_cron_not_scheduled(array $inspection, string $message): void
    {
        if (!empty($inspection['cron']['scheduled'])) {
            throw new RuntimeException($message);
        }
    }

    /**
     * @param array<string,mixed> $inspection
     */
    private function assert_pending_queue(array $inspection, string $message): void
    {
        $counts = is_array($inspection['fts_row_counts'] ?? null) ? $inspection['fts_row_counts'] : [];
        if ((int) ($counts['fts_queue'] ?? 0) < 1) {
            throw new RuntimeException($message);
        }
    }

    /**
     * @param array<string,mixed> $inspection
     */
    private function assert_operational_options_removed(array $inspection, string $message): void
    {
        $options = is_array($inspection['options'] ?? null) ? $inspection['options'] : [];
        foreach (self::OPERATIONAL_OPTIONS as $option) {
            if (!empty($options[$option]['exists'])) {
                throw new RuntimeException($message . " Option still exists: {$option}.");
            }
        }

        $counts = is_array($inspection['fts_row_counts'] ?? null) ? $inspection['fts_row_counts'] : [];
        if ((int) ($counts['fts_queue'] ?? 0) !== 0) {
            throw new RuntimeException($message . ' Durable queue rows remain.');
        }
    }

    /**
     * @return array<string,string>
     */
    private static function multisite_boundary(): array
    {
        return [
            'status' => 'not_run',
            'reason' => 'The current Docker lifecycle wrapper installs a single-site disposable WordPress root. Multisite lifecycle proof is intentionally recorded as a not-run boundary until a dedicated disposable multisite topology is added.',
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
        $pluginBasenameLiteral = var_export(self::PLUGIN_BASENAME, true);

        return <<<PHP
global \$wpdb;
\$phase = {$phaseLiteral};
\$suffixes = {$suffixesLiteral};
\$option_names = {$optionsLiteral};
\$tracked_post_ids = {$postIdsLiteral};
\$hook = {$hookLiteral};
\$plugin_basename = {$pluginBasenameLiteral};
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
    \$missing = '__wp_fts_lifecycle_missing__';
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
if (!function_exists('is_plugin_active') && defined('ABSPATH')) {
    \$plugin_file = ABSPATH . 'wp-admin/includes/plugin.php';
    if (is_file(\$plugin_file)) {
        require_once \$plugin_file;
    }
}
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
    'plugin' => [
        'basename' => \$plugin_basename,
        'installed' => defined('WP_PLUGIN_DIR') ? is_dir(WP_PLUGIN_DIR . '/indexer') : null,
        'active' => function_exists('is_plugin_active') ? (bool) is_plugin_active(\$plugin_basename) : null,
    ],
    'content' => [
        'post_page_count' => (int) \$wpdb->get_var("SELECT COUNT(*) FROM " . \$escape_identifier(\$posts_table) . " WHERE post_type IN ('post','page') AND post_status NOT IN ('auto-draft','inherit')"),
    ],
    'tracked_posts' => \$tracked,
];
echo wp_json_encode(\$payload);
PHP;
    }

    private static function drop_fts_table_eval_code(string $suffix): string
    {
        if (!in_array($suffix, self::FTS_TABLE_SUFFIXES, true)) {
            throw new InvalidArgumentException('Unknown FTS table suffix.');
        }

        $suffixLiteral = var_export($suffix, true);

        return <<<PHP
global \$wpdb;
\$suffix = {$suffixLiteral};
\$prefix = isset(\$wpdb->prefix) ? (string) \$wpdb->prefix : 'wp_';
\$table = \$prefix . \$suffix;
\$escaped = '`' . str_replace('`', '``', \$table) . '`';
\$wpdb->query('DROP TABLE IF EXISTS ' . \$escaped);
echo wp_json_encode(['dropped_table_suffix' => \$suffix, 'table_exists_after_drop' => \$wpdb->get_var(\$wpdb->prepare('SHOW TABLES LIKE %s', \$table)) === \$table]);
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
            } elseif (str_contains($arg, 'global $wpdb;') || str_contains($arg, 'DROP TABLE')) {
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
function wp_fts_disposable_lifecycle_smoke_write_cli_result(array $result): void
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
function wp_fts_disposable_lifecycle_smoke_write_report_file(array $result, string $path): void
{
    $json = json_encode($result['report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Could not encode lifecycle smoke report file.');
    }

    if (file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException('Could not write lifecycle smoke report file.');
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $options = WP_FTS_DisposableLifecycleSmokeRunner::parse_cli_options(array_slice($argv, 1));
        if (!empty($options['help'])) {
            fwrite(STDOUT, WP_FTS_DisposableLifecycleSmokeRunner::usage());
            exit(0);
        }

        $result = (new WP_FTS_DisposableLifecycleSmokeRunner())->run();
        if (is_string($options['report_file'] ?? null)) {
            wp_fts_disposable_lifecycle_smoke_write_report_file($result, $options['report_file']);
        }
        wp_fts_disposable_lifecycle_smoke_write_cli_result($result);
        exit($result['exit']);
    } catch (Throwable $e) {
        fwrite(STDERR, 'FAIL: ' . WP_FTS_DisposableLifecycleSmokeRunner::sanitize_output($e->getMessage()) . "\n");
        exit(1);
    }
}
