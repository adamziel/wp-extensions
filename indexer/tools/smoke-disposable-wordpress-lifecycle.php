<?php
declare(strict_types=1);

/**
 * Disposable WordPress lifecycle smoke for activation, repair, deactivation,
 * and destructive uninstall boundaries.
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
    public const NETWORK_ACTIVATE_ENV = 'WP_FTS_LIFECYCLE_SMOKE_NETWORK_ACTIVATE';
    public const REINSTALL_ZIP_ENV = 'WP_FTS_LIFECYCLE_SMOKE_REINSTALL_ZIP';

    private const PLUGIN_SLUG = 'indexer';
    private const PLUGIN_BASENAME = 'indexer/indexer.php';
    private const REPORT_SCHEMA = 'wp-fts-disposable-lifecycle-smoke-v1';
    private const OUTPUT_EXCERPT_BYTES = 900;
    private const UNINSTALL_FENCE_OPTION = 'wp_fts_uninstall_fence';
    private const UNINSTALL_FENCE_VALUE = '1';
    private const CURRENT_FTS_TABLE_SUFFIXES = [
        'fts_terms',
        'fts_postings',
        'fts_documents',
        'fts_work',
    ];
    private const LEGACY_FTS_TABLE_SUFFIXES = [
        'fts_docs',
        'fts_doc_lengths',
        'fts_docmeta',
        'fts_meta',
        'fts_queue',
        'fts_legacy_terms',
        'fts_legacy_postings',
        'fts_legacy_docs',
        'fts_legacy_doc_lengths',
        'fts_legacy_docmeta',
        'fts_legacy_meta',
        'fts_legacy_queue',
    ];
    private const UNINSTALL_FTS_TABLE_SUFFIXES = [
        'fts_terms',
        'fts_postings',
        'fts_documents',
        'fts_work',
        'fts_docs',
        'fts_doc_lengths',
        'fts_docmeta',
        'fts_meta',
        'fts_queue',
        'fts_legacy_terms',
        'fts_legacy_postings',
        'fts_legacy_docs',
        'fts_legacy_doc_lengths',
        'fts_legacy_docmeta',
        'fts_legacy_meta',
        'fts_legacy_queue',
    ];
    private const RESET_GENERATION_TABLES = [
        'reset_new_fts_terms' => ['base_suffix' => 'fts_terms', 'role' => 'new'],
        'reset_old_fts_terms' => ['base_suffix' => 'fts_terms', 'role' => 'old'],
        'reset_new_fts_postings' => ['base_suffix' => 'fts_postings', 'role' => 'new'],
        'reset_old_fts_postings' => ['base_suffix' => 'fts_postings', 'role' => 'old'],
        'reset_new_fts_documents' => ['base_suffix' => 'fts_documents', 'role' => 'new'],
        'reset_old_fts_documents' => ['base_suffix' => 'fts_documents', 'role' => 'old'],
        'reset_new_fts_work' => ['base_suffix' => 'fts_work', 'role' => 'new'],
        'reset_old_fts_work' => ['base_suffix' => 'fts_work', 'role' => 'old'],
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
            '  WP_FTS_LIFECYCLE_SMOKE_NETWORK_ACTIVATE=1  (required for multisite)',
            '  WP_FTS_LIFECYCLE_SMOKE_REINSTALL_ZIP=/path/to/indexer.zip',
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
        $reinstallZip = $this->validated_reinstall_zip();
        if ($reinstallZip === null) {
            return $this->result(
                'skipped',
                'Set WP_FTS_LIFECYCLE_SMOKE_REINSTALL_ZIP to the readable source-bound plugin ZIP used for post-uninstall reactivation.',
                $report
            );
        }
        $createdPostIds = [];
        $createdSiteId = 0;
        $subsiteBaseCommand = [];

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
            $isMultisite = !empty($beforeActivation['is_multisite']);
            if ($isMultisite && !$this->network_activation_requested()) {
                throw new RuntimeException(
                    'Multisite lifecycle proof requires WP_FTS_LIFECYCLE_SMOKE_NETWORK_ACTIVATE=1 so activation and deactivation use the network boundary.'
                );
            }

            $this->require_success(
                'activate plugin',
                array_merge(
                    $baseCommand,
                    ['plugin', 'activate', self::PLUGIN_SLUG],
                    $isMultisite ? ['--network'] : []
                ),
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

            $subsiteAfterCreation = null;
            if ($isMultisite) {
                $slug = 'wp-fts-lifecycle-proof-' . substr(hash('sha256', (string) microtime(true)), 0, 10);
                $createdSite = $this->require_success(
                    'create disposable multisite lifecycle subsite',
                    array_merge($baseCommand, [
                        'site',
                        'create',
                        '--slug=' . $slug,
                        '--title=WP FTS Lifecycle Multisite Proof',
                        '--email=admin@example.test',
                        '--porcelain',
                    ]),
                    $report
                );
                $createdSiteId = (int) trim($createdSite['stdout']);
                if ($createdSiteId <= 1) {
                    throw new RuntimeException('Disposable multisite lifecycle subsite creation did not return a non-main blog id.');
                }
                $subsiteBaseCommand = $this->wp_cli_base_command_for_url($wpPath, $this->multisite_subsite_url($slug));
                $subsiteAfterCreation = $this->inspect_site(
                    'multisite_subsite_after_creation',
                    $subsiteBaseCommand,
                    [],
                    $report
                );
                $this->assert_multisite_inspection($subsiteAfterCreation, 'multisite subsite creation');
                $this->assert_all_tables_exist(
                    $subsiteAfterCreation,
                    'network-active subsite initialization should create all current FTS tables'
                );
                if ((string) ($subsiteAfterCreation['table_prefix'] ?? '') === (string) ($afterActivation['table_prefix'] ?? '')) {
                    throw new RuntimeException('Multisite lifecycle subsite did not use a distinct per-site table prefix.');
                }
            }

            $this->require_json_success(
                'drop one disposable FTS table before repair',
                array_merge($baseCommand, ['eval', self::drop_fts_table_eval_code('fts_documents')]),
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
                array_merge($baseCommand, ['fts', 'process-batch', '--batch_size=1', '--time_budget=5', '--format=json']),
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

            $mainLegacySeed = $this->require_json_success(
                'seed disposable current-site legacy FTS tables',
                array_merge($baseCommand, ['eval', self::seed_legacy_tables_eval_code()]),
                $report
            );
            $this->assert_legacy_tables_seeded($mainLegacySeed, 'current-site legacy cleanup fixture');
            $beforeDeactivation = $this->inspect_site('before_deactivation', $baseCommand, $createdPostIds, $report);
            $this->assert_cron_scheduled($beforeDeactivation, 'fixture save should leave scheduled queue processing before deactivation');
            $this->assert_pending_queue($beforeDeactivation, 'fixture save should leave pending queue state before deactivation');
            $this->assert_all_uninstall_tables_exist(
                $beforeDeactivation,
                'current-site uninstall fixture should include every current and recoverable legacy table'
            );

            $subsiteBeforeDeactivation = null;
            $subsiteLegacySeed = null;
            if ($isMultisite) {
                $subsiteLegacySeed = $this->require_json_success(
                    'seed disposable multisite-subsite legacy FTS tables',
                    array_merge($subsiteBaseCommand, ['eval', self::seed_legacy_tables_eval_code()]),
                    $report
                );
                $this->assert_legacy_tables_seeded($subsiteLegacySeed, 'multisite-subsite legacy cleanup fixture');
                $subsiteBeforeDeactivation = $this->inspect_site(
                    'multisite_subsite_before_deactivation',
                    $subsiteBaseCommand,
                    [],
                    $report
                );
                $this->assert_all_uninstall_tables_exist(
                    $subsiteBeforeDeactivation,
                    'multisite-subsite uninstall fixture should include every current and recoverable legacy table'
                );
            }

            $this->require_success(
                'deactivate plugin',
                array_merge(
                    $baseCommand,
                    ['plugin', 'deactivate', self::PLUGIN_SLUG],
                    $isMultisite ? ['--network'] : []
                ),
                $report
            );
            $afterDeactivation = $this->inspect_site('after_deactivation', $baseCommand, $createdPostIds, $report);
            $this->assert_cron_not_scheduled($afterDeactivation, 'deactivation should clear scheduled queue processing');
            $this->assert_fts_rows_not_removed($beforeDeactivation, $afterDeactivation, 'deactivation should not remove FTS table data');
            $this->assert_pending_queue($afterDeactivation, 'deactivation should not erase durable pending queue state');
            $this->assert_all_uninstall_tables_exist($afterDeactivation, 'deactivation should retain current and legacy FTS tables');

            $subsiteAfterDeactivation = null;
            if ($isMultisite) {
                $subsiteAfterDeactivation = $this->inspect_site(
                    'multisite_subsite_after_deactivation',
                    $subsiteBaseCommand,
                    [],
                    $report
                );
                $this->assert_fts_rows_not_removed(
                    $subsiteBeforeDeactivation,
                    $subsiteAfterDeactivation,
                    'network deactivation should not remove multisite-subsite FTS table data'
                );
                $this->assert_all_uninstall_tables_exist(
                    $subsiteAfterDeactivation,
                    'network deactivation should retain multisite-subsite current and legacy FTS tables'
                );
            }

            $this->require_success(
                'uninstall plugin',
                array_merge($baseCommand, ['plugin', 'uninstall', self::PLUGIN_SLUG]),
                $report
            );
            $afterUninstall = $this->inspect_site('after_uninstall', $baseCommand, $createdPostIds, $report);
            $this->assert_cron_not_scheduled($afterUninstall, 'uninstall should leave scheduled queue processing cleared');
            $this->assert_all_uninstall_tables_absent(
                $afterUninstall,
                'uninstall should remove every current and recoverable legacy FTS table'
            );
            $this->assert_operational_options_removed($afterUninstall, 'uninstall should clear plugin operational options');
            $this->assert_uninstall_fence_present($afterUninstall, 'uninstall should retain exactly one bounded lifecycle fence');
            $this->assert_post_count_unchanged($beforeDeactivation, $afterUninstall, 'uninstall should not remove canonical WordPress content');
            foreach ($createdPostIds as $postId) {
                $this->assert_tracked_post_unchanged(
                    $beforeDeactivation,
                    $afterUninstall,
                    $postId,
                    'uninstall should not mutate a lifecycle fixture post'
                );
            }

            $subsiteAfterUninstall = null;
            if ($isMultisite) {
                $subsiteAfterUninstall = $this->inspect_site(
                    'multisite_subsite_after_uninstall',
                    $subsiteBaseCommand,
                    [],
                    $report
                );
                $this->assert_cron_not_scheduled(
                    $subsiteAfterUninstall,
                    'multisite uninstall should clear scheduled queue processing on the subsite'
                );
                $this->assert_all_uninstall_tables_absent(
                    $subsiteAfterUninstall,
                    'multisite uninstall should remove every current and recoverable legacy subsite FTS table'
                );
                $this->assert_operational_options_removed(
                    $subsiteAfterUninstall,
                    'multisite uninstall should clear subsite plugin operational options'
                );
                $this->assert_uninstall_fence_present(
                    $subsiteAfterUninstall,
                    'multisite uninstall should retain the exact bounded subsite lifecycle fence'
                );
            }

            $this->require_success(
                'reinstall plugin after destructive uninstall',
                array_merge($baseCommand, ['plugin', 'install', $reinstallZip, '--force']),
                $report
            );
            $afterReinstallInactive = $this->inspect_site(
                'after_reinstall_inactive',
                $baseCommand,
                $createdPostIds,
                $report
            );
            $this->assert_all_uninstall_tables_absent(
                $afterReinstallInactive,
                'installing files without activation must not recreate FTS tables'
            );
            $this->assert_operational_options_removed(
                $afterReinstallInactive,
                'installing files without activation must not recreate operational options'
            );
            $this->assert_uninstall_fence_present(
                $afterReinstallInactive,
                'installing files without activation must retain the uninstall fence'
            );

            $subsiteAfterReinstallInactive = null;
            if ($isMultisite) {
                $subsiteAfterReinstallInactive = $this->inspect_site(
                    'multisite_subsite_after_reinstall_inactive',
                    $subsiteBaseCommand,
                    [],
                    $report
                );
                $this->assert_all_uninstall_tables_absent(
                    $subsiteAfterReinstallInactive,
                    'multisite file installation without activation must not recreate subsite FTS tables'
                );
                $this->assert_uninstall_fence_present(
                    $subsiteAfterReinstallInactive,
                    'multisite file installation without activation must retain the subsite uninstall fence'
                );
            }

            $this->require_success(
                'reactivate reinstalled plugin',
                array_merge(
                    $baseCommand,
                    ['plugin', 'activate', self::PLUGIN_SLUG],
                    $isMultisite ? ['--network'] : []
                ),
                $report
            );
            $afterReactivation = $this->inspect_site(
                'after_reactivation',
                $baseCommand,
                $createdPostIds,
                $report
            );
            $this->assert_all_tables_exist($afterReactivation, 'explicit reactivation should provision all current FTS tables');
            $this->assert_noncurrent_uninstall_tables_absent($afterReactivation, 'explicit reactivation should not restore legacy or failed-reset FTS tables');
            $this->assert_uninstall_fence_absent($afterReactivation, 'explicit reactivation should clear the main-site uninstall fence');

            $subsiteAfterReactivation = null;
            if ($isMultisite) {
                $this->require_success(
                    'run bounded network reactivation provisioning page',
                    array_merge($baseCommand, ['cron', 'event', 'run', 'wp_fts_provision_site_schema']),
                    $report
                );
                $subsiteAfterReactivation = $this->inspect_site(
                    'multisite_subsite_after_reactivation',
                    $subsiteBaseCommand,
                    [],
                    $report
                );
                $this->assert_all_tables_exist(
                    $subsiteAfterReactivation,
                    'bounded network reactivation provisioning should recreate all subsite current FTS tables'
                );
                $this->assert_noncurrent_uninstall_tables_absent(
                    $subsiteAfterReactivation,
                    'bounded network reactivation provisioning should not restore subsite legacy or failed-reset FTS tables'
                );
                $this->assert_uninstall_fence_absent(
                    $subsiteAfterReactivation,
                    'bounded network reactivation provisioning should clear the subsite uninstall fence'
                );
            }

            $cleanupFailure = $this->cleanup($baseCommand, $createdPostIds, $report, $createdSiteId);
            $createdPostIds = [];
            $createdSiteId = 0;
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
                'after_reinstall_inactive' => $this->compact_payload($afterReinstallInactive),
                'after_reactivation' => $this->compact_payload($afterReactivation),
                'main_legacy_seed' => $this->compact_payload($mainLegacySeed),
            ];
            if ($isMultisite) {
                $report['lifecycle_evidence']['multisite_subsite_after_creation'] = $this->compact_payload($subsiteAfterCreation ?? []);
                $report['lifecycle_evidence']['multisite_subsite_before_deactivation'] = $this->compact_payload($subsiteBeforeDeactivation ?? []);
                $report['lifecycle_evidence']['multisite_subsite_after_deactivation'] = $this->compact_payload($subsiteAfterDeactivation ?? []);
                $report['lifecycle_evidence']['multisite_subsite_after_uninstall'] = $this->compact_payload($subsiteAfterUninstall ?? []);
                $report['lifecycle_evidence']['multisite_subsite_after_reinstall_inactive'] = $this->compact_payload($subsiteAfterReinstallInactive ?? []);
                $report['lifecycle_evidence']['multisite_subsite_after_reactivation'] = $this->compact_payload($subsiteAfterReactivation ?? []);
                $report['lifecycle_evidence']['multisite_subsite_legacy_seed'] = $this->compact_payload($subsiteLegacySeed ?? []);
                $report['multisite_evidence'] = [
                    'status' => 'passed',
                    'activation_scope' => 'network',
                    'site_count' => (int) ($afterUninstall['network_site_count'] ?? 0),
                    'main_blog_id' => max(1, (int) ($afterUninstall['blog_id'] ?? 1)),
                    'main_blog_table_prefix' => (string) ($afterUninstall['table_prefix'] ?? ''),
                    'subsite_blog_id' => $createdSiteId > 0 ? $createdSiteId : (int) ($subsiteAfterUninstall['blog_id'] ?? 0),
                    'subsite_table_prefix' => (string) ($subsiteAfterUninstall['table_prefix'] ?? ''),
                    'all_current_and_legacy_tables_removed' => true,
                    'all_operational_options_removed' => true,
                    'bounded_uninstall_fence_retained' => true,
                    'network_reactivation_cleared_fences_and_reprovisioned' => true,
                ];
            } else {
                $report['multisite_evidence'] = self::multisite_boundary();
            }
            $report['covered_behaviors'] = [
                'activation_creates_schema' => true,
                'repair_recreates_missing_plugin_table' => true,
                'activation_and_repair_do_not_index_existing_content' => true,
                'activation_and_repair_do_not_create_demo_posts' => true,
                'status_and_repair_emit_schema_json' => true,
                'deactivation_clears_scheduled_queue_processing' => true,
                'deactivation_retains_index_data' => true,
                'uninstall_clears_operational_options' => true,
                'uninstall_removes_current_and_legacy_fts_tables' => true,
                'uninstall_retains_exact_bounded_lifecycle_fence' => true,
                'inactive_reinstall_does_not_cross_uninstall_fence' => true,
                'explicit_reactivation_clears_fence_and_reprovisions' => true,
                'multisite_uninstall_removes_all_site_fts_tables' => $isMultisite,
                'multisite_reactivation_clears_all_site_fences_and_reprovisions' => $isMultisite,
                'uninstall_preserves_canonical_wordpress_content' => true,
                'public_submission_artifacts_created' => false,
            ];

            return $this->result('passed', 'Disposable WordPress lifecycle smoke completed.', $report);
        } catch (Throwable $e) {
            $cleanupFailure = $this->cleanup($baseCommand, $createdPostIds, $report, $createdSiteId);
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

    private function validated_reinstall_zip(): ?string
    {
        $raw = trim($this->env_value(self::REINSTALL_ZIP_ENV));
        if ($raw === '') {
            return null;
        }
        $real = realpath($raw);

        return is_string($real) && is_file($real) && is_readable($real) ? $real : null;
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
     * @return array<int,string>
     */
    private function wp_cli_base_command_for_url(string $wpPath, string $url): array
    {
        $command = $this->wp_cli_base_command($wpPath);
        foreach ($command as $index => $argument) {
            if (str_starts_with($argument, '--url=')) {
                $command[$index] = '--url=' . $url;
                return $command;
            }
        }

        $command[] = '--url=' . $url;
        return $command;
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
            throw new RuntimeException('Multisite lifecycle proof requires WP_FTS_WP_URL so WP-CLI can target the disposable subsite.');
        }

        return $rootUrl . '/' . trim($slug, '/') . '/';
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
    private function cleanup(array $baseCommand, array $postIds, array &$report, int $siteId = 0): ?Throwable
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

        if ($siteId > 1) {
            $deleted = $this->run_process(
                'delete disposable multisite lifecycle subsite',
                array_merge($baseCommand, ['site', 'delete', (string) $siteId, '--yes']),
                [],
                $report,
                true
            );
            if ($deleted['exit'] !== 0) {
                $failure ??= new RuntimeException($this->failed_command_message('delete disposable multisite lifecycle subsite', $deleted));
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
        foreach (self::CURRENT_FTS_TABLE_SUFFIXES as $suffix) {
            if (empty($tables[$suffix]['exists'])) {
                throw new RuntimeException($message . " Missing table suffix: {$suffix}.");
            }
        }
    }

    /**
     * @param array<string,mixed> $inspection
     */
    private function assert_all_uninstall_tables_exist(array $inspection, string $message): void
    {
        $tables = is_array($inspection['fts_tables'] ?? null) ? $inspection['fts_tables'] : [];
        foreach ([...self::UNINSTALL_FTS_TABLE_SUFFIXES, ...array_keys(self::RESET_GENERATION_TABLES)] as $key) {
            if (empty($tables[$key]['exists'])) {
                throw new RuntimeException($message . " Missing table: {$key}.");
            }
        }
    }

    /**
     * @param array<string,mixed> $inspection
     */
    private function assert_all_uninstall_tables_absent(array $inspection, string $message): void
    {
        $tables = is_array($inspection['fts_tables'] ?? null) ? $inspection['fts_tables'] : [];
        foreach ([...self::UNINSTALL_FTS_TABLE_SUFFIXES, ...array_keys(self::RESET_GENERATION_TABLES)] as $key) {
            if (!empty($tables[$key]['exists'])) {
                throw new RuntimeException($message . " Table still exists: {$key}.");
            }
        }
    }

    /**
     * @param array<string,mixed> $inspection
     */
    private function assert_noncurrent_uninstall_tables_absent(array $inspection, string $message): void
    {
        $tables = is_array($inspection['fts_tables'] ?? null) ? $inspection['fts_tables'] : [];
        foreach ([...self::LEGACY_FTS_TABLE_SUFFIXES, ...array_keys(self::RESET_GENERATION_TABLES)] as $key) {
            if (!empty($tables[$key]['exists'])) {
                throw new RuntimeException($message . " Table exists: {$key}.");
            }
        }
    }

    /**
     * @param array<string,mixed> $inspection
     */
    private function assert_fts_rows_zero(array $inspection, string $message): void
    {
        $counts = is_array($inspection['fts_row_counts'] ?? null) ? $inspection['fts_row_counts'] : [];
        foreach (['fts_terms', 'fts_postings', 'fts_documents'] as $suffix) {
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
        if ((int) ($counts['fts_documents'] ?? 0) < 1 || (int) ($counts['fts_postings'] ?? 0) < 1) {
            throw new RuntimeException($message . ' Expected indexed document and posting rows.');
        }
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function assert_fts_rows_not_removed(array $before, array $after, string $message): void
    {
        $beforeCounts = is_array($before['fts_row_counts'] ?? null) ? $before['fts_row_counts'] : [];
        $afterCounts = is_array($after['fts_row_counts'] ?? null) ? $after['fts_row_counts'] : [];
        foreach (['fts_terms', 'fts_postings', 'fts_documents'] as $suffix) {
            $beforeCount = (int) ($beforeCounts[$suffix] ?? -1);
            $afterCount = (int) ($afterCounts[$suffix] ?? -2);
            if ($beforeCount < 0 || $afterCount < $beforeCount) {
                throw new RuntimeException($message . " Row count decreased for {$suffix}: {$beforeCount} to {$afterCount}.");
            }
        }
        foreach (self::LEGACY_FTS_TABLE_SUFFIXES as $suffix) {
            $beforeCount = (int) ($beforeCounts[$suffix] ?? -1);
            $afterCount = (int) ($afterCounts[$suffix] ?? -2);
            if ($beforeCount !== $afterCount) {
                throw new RuntimeException($message . " Legacy sentinel count changed for {$suffix}: {$beforeCount} to {$afterCount}.");
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
        if ((int) ($counts['fts_work'] ?? 0) < 1) {
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

    }

    /**
     * @param array<string,mixed> $inspection
     */
    private function assert_uninstall_fence_present(array $inspection, string $message): void
    {
        $options = is_array($inspection['options'] ?? null) ? $inspection['options'] : [];
        $fence = is_array($options[self::UNINSTALL_FENCE_OPTION] ?? null)
            ? $options[self::UNINSTALL_FENCE_OPTION]
            : [];
        $autoload = is_scalar($fence['database_autoload'] ?? null)
            ? strtolower((string) $fence['database_autoload'])
            : '';
        if (
            ($fence['exists'] ?? null) !== true
            || ($fence['value_type'] ?? null) !== 'string'
            || ($fence['scalar_value'] ?? null) !== self::UNINSTALL_FENCE_VALUE
            || (int) ($fence['value_bytes'] ?? -1) !== strlen(self::UNINSTALL_FENCE_VALUE)
            || (int) ($fence['database_rows'] ?? -1) !== 1
            || ($fence['database_value'] ?? null) !== self::UNINSTALL_FENCE_VALUE
            || (int) ($fence['database_value_bytes'] ?? -1) !== strlen(self::UNINSTALL_FENCE_VALUE)
            || !in_array($autoload, ['no', 'off', 'auto-off'], true)
        ) {
            throw new RuntimeException($message . ' Expected one non-autoloaded string option with the exact value "1".');
        }
    }

    /**
     * @param array<string,mixed> $inspection
     */
    private function assert_uninstall_fence_absent(array $inspection, string $message): void
    {
        $options = is_array($inspection['options'] ?? null) ? $inspection['options'] : [];
        $fence = is_array($options[self::UNINSTALL_FENCE_OPTION] ?? null)
            ? $options[self::UNINSTALL_FENCE_OPTION]
            : [];
        if (($fence['exists'] ?? null) !== false || (int) ($fence['database_rows'] ?? -1) !== 0) {
            throw new RuntimeException($message);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function assert_legacy_tables_seeded(array $payload, string $label): void
    {
        $tables = is_array($payload['tables'] ?? null) ? $payload['tables'] : [];
        foreach ([...self::LEGACY_FTS_TABLE_SUFFIXES, ...array_keys(self::RESET_GENERATION_TABLES)] as $key) {
            if (empty($tables[$key]['exists']) || (int) ($tables[$key]['rows'] ?? 0) !== 1) {
                throw new RuntimeException("{$label} did not create exactly one sentinel row in {$key}.");
            }
        }
    }

    /**
     * @param array<string,mixed> $inspection
     */
    private function assert_multisite_inspection(array $inspection, string $label): void
    {
        if (empty($inspection['is_multisite'])) {
            throw new RuntimeException("Expected multisite WordPress context for {$label}.");
        }
        if (
            (int) ($inspection['blog_id'] ?? 0) <= 1
            || (int) ($inspection['network_site_count'] ?? 0) < 2
            || (string) ($inspection['table_prefix'] ?? '') === ''
        ) {
            throw new RuntimeException("Missing network, non-main blog identity, or table-prefix evidence for {$label}.");
        }
    }

    /**
     * @return array<string,string>
     */
    private static function multisite_boundary(): array
    {
        return [
            'status' => 'single_site',
            'reason' => 'The host-provided target is a single-site WordPress installation. The Docker wrapper uses a multisite network and requires runtime network-uninstall evidence.',
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
        $suffixesLiteral = var_export(self::UNINSTALL_FTS_TABLE_SUFFIXES, true);
        $resetGenerationTablesLiteral = var_export(self::RESET_GENERATION_TABLES, true);
        $optionsLiteral = var_export([...self::OPERATIONAL_OPTIONS, self::UNINSTALL_FENCE_OPTION], true);
        $hookLiteral = var_export('wp_fts_process_index_queue', true);
        $pluginBasenameLiteral = var_export(self::PLUGIN_BASENAME, true);
        $fenceOptionLiteral = var_export(self::UNINSTALL_FENCE_OPTION, true);

        return <<<PHP
global \$wpdb;
\$phase = {$phaseLiteral};
\$suffixes = {$suffixesLiteral};
\$reset_generation_tables = {$resetGenerationTablesLiteral};
\$option_names = {$optionsLiteral};
\$tracked_post_ids = {$postIdsLiteral};
\$hook = {$hookLiteral};
\$fence_option = {$fenceOptionLiteral};
	\$plugin_basename = {$pluginBasenameLiteral};
	\$prefix = isset(\$wpdb->prefix) ? (string) \$wpdb->prefix : 'wp_';
	\$blog_id = function_exists('get_current_blog_id') ? max(0, (int) get_current_blog_id()) : 0;
\$posts_table = isset(\$wpdb->posts) ? (string) \$wpdb->posts : \$prefix . 'posts';
\$escape_identifier = static function (string \$identifier): string {
    return '`' . str_replace('`', '``', \$identifier) . '`';
};
\$tables = [];
\$row_counts = [];
foreach (\$suffixes as \$suffix) {
    \$table = \$prefix . \$suffix;
	    \$pattern = method_exists(\$wpdb, 'esc_like') ? \$wpdb->esc_like(\$table) : \$table;
	    \$exists = \$wpdb->get_var(\$wpdb->prepare('SHOW TABLES LIKE %s', \$pattern)) === \$table;
    \$tables[\$suffix] = [
        'name' => \$table,
        'exists' => \$exists,
    ];
    \$row_counts[\$suffix] = \$exists ? (int) \$wpdb->get_var('SELECT COUNT(*) FROM ' . \$escape_identifier(\$table)) : null;
}
foreach (\$reset_generation_tables as \$key => \$spec) {
    \$base_table = \$prefix . (string) \$spec['base_suffix'];
    \$role = (string) \$spec['role'];
    \$reset_suffix = '_r' . (\$role === 'new' ? 'n' : 'o')
        . '_' . substr(hash('sha256', \$base_table . '|' . \$role), 0, 10);
    \$table = substr(\$base_table, 0, 64 - strlen(\$reset_suffix)) . \$reset_suffix;
    \$pattern = method_exists(\$wpdb, 'esc_like') ? \$wpdb->esc_like(\$table) : \$table;
    \$exists = \$wpdb->get_var(\$wpdb->prepare('SHOW TABLES LIKE %s', \$pattern)) === \$table;
    \$tables[\$key] = [
        'name' => \$table,
        'exists' => \$exists,
    ];
    \$row_counts[\$key] = \$exists ? (int) \$wpdb->get_var('SELECT COUNT(*) FROM ' . \$escape_identifier(\$table)) : null;
}
\$options = [];
foreach (\$option_names as \$option_name) {
    \$missing = '__wp_fts_lifecycle_missing__';
    \$value = get_option(\$option_name, \$missing);
    \$entry = [
        'exists' => \$value !== \$missing,
        'value_type' => \$value !== \$missing ? get_debug_type(\$value) : 'missing',
        'scalar_value' => \$value !== \$missing && is_scalar(\$value) ? (string) \$value : null,
        'value_bytes' => \$value !== \$missing && is_scalar(\$value) ? strlen((string) \$value) : null,
    ];
    if (\$option_name === 'wp_fts_pending_index_post_ids') {
        \$entry['queue_count'] = is_array(\$value) ? count(\$value) : 0;
    }
    if (\$option_name === 'wp_fts_schema_version' && \$value !== \$missing) {
        \$entry['schema_version'] = is_scalar(\$value) ? (int) \$value : 0;
    }
    if (\$option_name === \$fence_option) {
        \$option_rows = \$wpdb->get_results(
            \$wpdb->prepare(
                'SELECT option_value,autoload FROM ' . \$escape_identifier((string) \$wpdb->options) . ' WHERE option_name=%s ORDER BY option_id ASC',
                \$fence_option
            ),
            ARRAY_A
        );
        \$option_rows = is_array(\$option_rows) ? array_values(\$option_rows) : [];
        \$entry['database_rows'] = count(\$option_rows);
        \$entry['database_value'] = count(\$option_rows) === 1 ? (string) (\$option_rows[0]['option_value'] ?? '') : null;
        \$entry['database_value_bytes'] = count(\$option_rows) === 1 ? strlen((string) (\$option_rows[0]['option_value'] ?? '')) : null;
        \$entry['database_autoload'] = count(\$option_rows) === 1 ? (string) (\$option_rows[0]['autoload'] ?? '') : null;
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
	    'blog_id' => \$blog_id,
	    'network_site_count' => function_exists('is_multisite') && is_multisite() && function_exists('get_sites') ? (int) get_sites(['count' => true]) : 1,
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
        if (!in_array($suffix, self::CURRENT_FTS_TABLE_SUFFIXES, true)) {
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

    private static function seed_legacy_tables_eval_code(): string
    {
        $suffixesLiteral = var_export(self::LEGACY_FTS_TABLE_SUFFIXES, true);
        $resetGenerationTablesLiteral = var_export(self::RESET_GENERATION_TABLES, true);

        return <<<PHP
global \$wpdb;
\$suffixes = {$suffixesLiteral};
\$reset_generation_tables = {$resetGenerationTablesLiteral};
\$prefix = isset(\$wpdb->prefix) ? (string) \$wpdb->prefix : 'wp_';
\$escape_identifier = static function (string \$identifier): string {
    return '`' . str_replace('`', '``', \$identifier) . '`';
};
\$table_names = [];
foreach (\$suffixes as \$suffix) {
    \$table_names[\$suffix] = \$prefix . \$suffix;
}
foreach (\$reset_generation_tables as \$key => \$spec) {
    \$base_table = \$prefix . (string) \$spec['base_suffix'];
    \$role = (string) \$spec['role'];
    \$reset_suffix = '_r' . (\$role === 'new' ? 'n' : 'o')
        . '_' . substr(hash('sha256', \$base_table . '|' . \$role), 0, 10);
    \$table_names[\$key] = substr(\$base_table, 0, 64 - strlen(\$reset_suffix)) . \$reset_suffix;
}
\$tables = [];
foreach (\$table_names as \$key => \$table) {
    \$escaped = \$escape_identifier(\$table);
    \$wpdb->query('CREATE TABLE IF NOT EXISTS ' . \$escaped . ' (sentinel_id BIGINT UNSIGNED NOT NULL PRIMARY KEY)');
    \$wpdb->query('DELETE FROM ' . \$escaped);
    \$wpdb->query('INSERT INTO ' . \$escaped . ' (sentinel_id) VALUES (1)');
    if (isset(\$wpdb->last_error) && trim((string) \$wpdb->last_error) !== '') {
        throw new RuntimeException('Could not seed disposable uninstall FTS table ' . \$key . ': ' . trim((string) \$wpdb->last_error));
    }
    \$pattern = method_exists(\$wpdb, 'esc_like') ? \$wpdb->esc_like(\$table) : \$table;
    \$exists = \$wpdb->get_var(\$wpdb->prepare('SHOW TABLES LIKE %s', \$pattern)) === \$table;
    \$tables[\$key] = [
        'name' => \$table,
        'exists' => \$exists,
        'rows' => \$exists ? (int) \$wpdb->get_var('SELECT COUNT(*) FROM ' . \$escaped) : null,
    ];
}
echo wp_json_encode([
    'seeded_legacy_table_suffixes' => array_values(\$suffixes),
    'seeded_reset_generation_table_keys' => array_keys(\$reset_generation_tables),
    'tables' => \$tables,
]);
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
