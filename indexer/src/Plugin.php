<?php
declare(strict_types=1);

/**
 * WordPress plugin lifecycle, runtime indexing hooks, queue processing, and REST search.
 *
 * The standalone index/search classes remain framework-neutral; this class is
 * the narrow WordPress adapter that wires them to activation hooks, post events,
 * WP-Cron, options, visibility checks, and REST registration.
 */
final class WP_FTS_Plugin
{
    public const SCHEMA_VERSION = 1;
    public const SCHEMA_VERSION_OPTION = 'wp_fts_schema_version';
    public const QUEUE_OPTION = 'wp_fts_pending_index_post_ids';
    public const CRON_HOOK = 'wp_fts_process_index_queue';
    public const INDEX_LOCK_OPTION = 'wp_fts_indexing_lock';
    public const INDEX_HEALTH_OPTION = 'wp_fts_index_health';
    public const REST_NAMESPACE = 'wp-fts/v1';
    public const REST_SEARCH_ROUTE = '/search';
    public const ADMIN_PAGE_SLUG = 'wp-fts-settings';
    public const ADMIN_CAPABILITY = 'manage_options';
    public const SETTINGS_OPTION = 'wp_fts_settings';
    public const ACTIVATION_REDIRECT_OPTION = 'wp_fts_activation_redirect';
    public const SANDBOX_DEMO_POSTS_OPTION = 'wp_fts_sandbox_demo_post_ids';
    public const ANALYZER_OPTIONS_OPTION = 'wp_fts_analyzer_options';
    public const ANALYZER_OPTIONS_FILTER = 'wp_fts_analyzer_options';
    public const FRONTEND_SEARCH_REPLACEMENT_FILTER = 'wp_fts_replace_frontend_search';
    public const ADMIN_POST_SEARCH_REPLACEMENT_FILTER = 'wp_fts_replace_admin_post_search';
    public const DEBUG_ENABLED_FILTER = 'wp_fts_debug_enabled';
    public const SEARCH_REPLACEMENT_PRIORITY = 999;
    public const LANGUAGE_META_KEY = '_wp_fts_index_language';
    public const DEFAULT_BATCH_SIZE = 25;
    public const DEFAULT_CRON_INDEX_BATCH_SIZE = 20;
    public const DEFAULT_MANUAL_INDEX_BATCH_SIZE = 100;
    public const MAX_SEARCH_LIMIT = 50;
    private const DEFAULT_CRON_INDEX_TIME_BUDGET = 10.0;
    private const DEFAULT_MANUAL_INDEX_TIME_BUDGET = 20.0;
    private const DEFAULT_INDEX_MEMORY_MARGIN_BYTES = 16777216;
    private const DEFAULT_INDEX_LOCK_TTL = 300;
    private const MAX_CRON_INDEX_BATCH_SIZE = 500;
    private const MAX_MANUAL_INDEX_BATCH_SIZE = 1000;
    private const MAX_INDEX_TIME_BUDGET = 300.0;
    private const MAX_INDEX_MEMORY_MARGIN_BYTES = 536870912;
    private const ADMIN_NONCE_ACTION = 'wp_fts_sandbox_admin_action';
    private const ADMIN_NONCE_FIELD = 'wp_fts_sandbox_nonce';
    private const ADMIN_ACTION_FIELD = 'wp_fts_sandbox_action';
    private const ADMIN_CLEANUP_LEGACY_DEMO_ACTION = 'cleanup_legacy_demo_posts';
    private const LEGACY_DEMO_CREATION_ACTIONS = ['refresh_demo', 'index_demo'];
    private const ADMIN_HEALTH_NONCE_ACTION = 'wp_fts_health_admin_action';
    private const ADMIN_HEALTH_NONCE_FIELD = 'wp_fts_health_nonce';
    private const ADMIN_HEALTH_ACTION_FIELD = 'wp_fts_health_action';
    private const ADMIN_HEALTH_MANUAL_BATCH_ACTION = 'index_next_batch';
    private const ADMIN_QUERY_FIELD = 'wp_fts_sandbox_query';
    private const ADMIN_LANG_FIELD = 'wp_fts_sandbox_lang';
    private const ADMIN_SEARCH_FIELD = 'wp_fts_sandbox_search';
    private const ADMIN_POSTS_PAGE_FIELD = 'wp_fts_sandbox_posts_page';
    private const ADMIN_SHOW_INDEXED_TERMS_FIELD = 'wp_fts_sandbox_show_indexed_terms';
    private const ADMIN_TAB_FIELD = 'tab';
    private const ADMIN_HEALTH_TAB = 'health';
    private const ADMIN_SETTINGS_TAB = 'settings';
    private const ADMIN_SANDBOX_TAB = 'sandbox';
    private const ADMIN_INDEXED_TAB = 'indexed-content';
    private const ADMIN_ANALYZER_TAB = 'analyzer-packs';
    private const ADMIN_MODE_FIELD = 'wp_fts_sandbox_mode';
    private const ADMIN_LIMIT_FIELD = 'wp_fts_sandbox_limit';
    private const ADMIN_SNIPPET_LENGTH_FIELD = 'wp_fts_sandbox_snippet_length';
    private const ADMIN_HIGHLIGHT_FIELD = 'wp_fts_sandbox_highlight';
    private const ADMIN_PREFIX_MATCHING_FIELD = 'wp_fts_sandbox_prefix_matching';
    private const ADMIN_LANGUAGE_FALLBACK_FIELD = 'wp_fts_sandbox_language_fallback';
    private const ADMIN_POST_TYPE_FIELD = 'wp_fts_sandbox_post_type';
    private const ADMIN_POST_STATUS_FIELD = 'wp_fts_sandbox_post_status';
    private const ADMIN_DATE_AFTER_FIELD = 'wp_fts_sandbox_date_after';
    private const ADMIN_DATE_BEFORE_FIELD = 'wp_fts_sandbox_date_before';
    private const ADMIN_DETAILS_NONCE_ACTION = 'wp_fts_sandbox_result_details';
    private const ADMIN_DETAILS_NONCE_FIELD = 'wp_fts_sandbox_details_nonce';
    private const ADMIN_DETAILS_POST_IDS_FIELD = 'wp_fts_sandbox_post_ids';
    private const ADMIN_AJAX_SANDBOX_DETAILS_ACTION = 'wp_fts_sandbox_result_details';
    private const SETTINGS_GROUP = 'wp_fts_settings';
    private const POST_LANGUAGE_FIELD = 'wp_fts_post_language';
    private const POST_LANGUAGE_NONCE_ACTION = 'wp_fts_post_language';
    private const POST_LANGUAGE_NONCE_FIELD = 'wp_fts_post_language_nonce';
    private const SANDBOX_INDEXED_TERMS_LIMIT = 24;
    private const SANDBOX_INDEXED_POSTS_PER_PAGE = 10;
    private const SETTINGS_SNIPPET_MIN = 40;
    private const SETTINGS_SNIPPET_MAX = 500;
    private const SEARCH_PROVIDER_COMPATIBILITY_PREFER_FTS = 'prefer_fts';
    private const SEARCH_PROVIDER_COMPATIBILITY_RESPECT_EXISTING = 'respect_existing';
    private const DEFAULT_SETTINGS = [
        'index_post_types' => ['post', 'page'],
        'auto_index' => true,
        'replace_frontend_search' => true,
        'replace_admin_post_search' => true,
        'search_provider_compatibility' => 'prefer_fts',
        'highlight' => true,
        'snippet_length' => 180,
        'match_mode' => 'OR',
        'prefix_matching' => true,
        'result_limit' => 10,
        'language_fallback' => true,
    ];
    private const VISIBILITY_REFILL_MIN_BATCH = 10;
    private const VISIBILITY_REFILL_MULTIPLIER = 4;
    private const VISIBILITY_REFILL_MAX_SCAN = 250;
    private const FRONTEND_SNIPPET_LENGTH = 180;
    private const FRONTEND_SEARCH_POST_STATUSES = ['publish'];
    private const ADMIN_POST_SEARCH_POST_STATUSES = ['publish', 'draft', 'pending', 'future', 'private'];
    private const DEBUG_MAX_TRACES = 8;
    private const DEBUG_MAX_TEXT_BYTES = 160;
    private const DEBUG_MAX_LIST_ITEMS = 8;
    private const DEBUG_MAX_ASSOC_ITEMS = 16;
    private const DEBUG_MAX_TIMING_PHASES = 16;

    /**
     * @var array<int,array{total:int,max_pages:int,query_lang:string,query_text:string,snippets:array<int,string>,titles:array<int,string>,trace_id:int}>
     */
    private static array $front_end_search_query_state = [];

    /**
     * @var array<int,array{total:int,max_pages:int,query_lang:string,trace_id:int}>
     */
    private static array $admin_post_search_query_state = [];

    /**
     * @var int[]
     */
    private static array $front_end_search_loop_stack = [];

    private static int $front_end_search_active_query_key = 0;

    /**
     * @var array<int,array<string,mixed>>
     */
    private static array $debug_traces = [];

    private static int $debug_next_trace_id = 1;

    /**
     * @var array<int,array{language:string,kind:string,status:string,pack_id:string,fixture_only:bool,reason:string}>|null
     */
    private static ?array $runtime_analyzer_pack_statuses_cache = null;

    /**
     * @var array<int,array{language:string,kind:string,status:string,pack_id:string,fixture_only:bool,reason:string}>|null
     */
    private static ?array $sandbox_demo_analyzer_pack_statuses_cache = null;

    /**
     * @var array<string,array{label:string,full:bool,reason:string,matched_language:string}>
     */
    private static array $language_support_details_cache = [];

    /**
     * Clear request-scoped caches for test harnesses and same-request option changes.
     */
    public static function reset_request_caches(): void
    {
        self::$runtime_analyzer_pack_statuses_cache = null;
        self::$sandbox_demo_analyzer_pack_statuses_cache = null;
        self::$language_support_details_cache = [];
        self::$debug_traces = [];
        self::$debug_next_trace_id = 1;
    }

    /**
     * Register runtime hooks when WordPress hook APIs are available.
     */
    public static function register_hooks(): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        add_action('wp_after_insert_post', [self::class, 'handle_post_save'], 10, 4);
        add_action('save_post', [self::class, 'handle_post_save'], 10, 3);
        add_action('transition_post_status', [self::class, 'handle_status_transition'], 10, 3);
        add_action('trashed_post', [self::class, 'handle_post_delete'], 10, 1);
        add_action('before_delete_post', [self::class, 'handle_post_delete'], 10, 1);
        add_action(self::CRON_HOOK, [self::class, 'process_scheduled_indexing'], 10, 0);
        add_action('rest_api_init', [self::class, 'register_rest_routes'], 10, 0);
        add_action('admin_menu', [self::class, 'register_admin_menu'], 10, 0);
        add_action('admin_init', [self::class, 'maybe_redirect_after_activation'], 1, 0);
        add_action('admin_init', [self::class, 'register_settings'], 10, 0);
        add_action('wp_ajax_' . self::ADMIN_AJAX_SANDBOX_DETAILS_ACTION, [self::class, 'handle_sandbox_result_details_ajax'], 10, 0);
        add_action('add_meta_boxes', [self::class, 'register_language_meta_box'], 10, 0);
        add_action('save_post', [self::class, 'save_post_language_override'], 5, 3);
        add_action('pre_get_posts', [self::class, 'prepare_frontend_search_query'], self::SEARCH_REPLACEMENT_PRIORITY, 1);
        add_action('pre_get_posts', [self::class, 'prepare_admin_post_search_query'], self::SEARCH_REPLACEMENT_PRIORITY, 1);

        if (function_exists('add_filter')) {
            add_filter('posts_pre_query', [self::class, 'replace_frontend_search_posts'], self::SEARCH_REPLACEMENT_PRIORITY, 2);
            add_filter('posts_pre_query', [self::class, 'replace_admin_post_search_posts'], self::SEARCH_REPLACEMENT_PRIORITY, 2);
            add_filter('found_posts', [self::class, 'filter_frontend_search_found_posts'], self::SEARCH_REPLACEMENT_PRIORITY, 2);
            add_filter('found_posts', [self::class, 'filter_admin_post_search_found_posts'], self::SEARCH_REPLACEMENT_PRIORITY, 2);
            add_filter('get_the_excerpt', [self::class, 'frontend_search_excerpt'], 10, 2);
            add_filter('the_excerpt', [self::class, 'frontend_search_excerpt'], 10, 1);
            add_filter('the_content', [self::class, 'frontend_search_content'], 20, 1);
            add_filter('the_title', [self::class, 'frontend_search_title'], 10, 2);
            add_filter('render_block', [self::class, 'frontend_search_render_block'], 10, 3);
            add_filter('debug_bar_panels', [self::class, 'register_debug_bar_panel'], 10, 1);
        }

        add_action('loop_start', [self::class, 'begin_frontend_search_loop'], 10, 1);
        add_action('loop_end', [self::class, 'end_frontend_search_loop'], 10, 1);
    }

    /**
     * Activation creates or repairs tables and records the schema contract version.
     */
    public static function activate(bool $network_wide = false): void
    {
        self::upgrade_schema();
        self::schedule_queue_processor();
        self::maybe_set_activation_redirect_flag($network_wide);
    }

    /**
     * Redirect a safely activated admin to the Health tab once after install.
     */
    public static function maybe_redirect_after_activation(): void
    {
        if (!self::activation_redirect_flag_enabled(self::get_option(self::ACTIVATION_REDIRECT_OPTION, false))) {
            return;
        }

        if (!self::should_redirect_after_activation()) {
            return;
        }

        self::delete_option(self::ACTIVATION_REDIRECT_OPTION);
        $url = self::admin_page_url(self::ADMIN_HEALTH_TAB);

        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($url);
            exit;
        }

        if (function_exists('wp_redirect')) {
            wp_redirect($url);
            exit;
        }
    }

    private static function maybe_set_activation_redirect_flag(bool $network_wide): void
    {
        if (
            $network_wide
            || self::is_bulk_activation_request()
            || self::is_ajax_request()
            || self::is_rest_request()
            || self::is_cron_request()
            || self::is_cli_request()
        ) {
            return;
        }

        if (function_exists('add_option') && add_option(self::ACTIVATION_REDIRECT_OPTION, 1, '', 'no')) {
            return;
        }

        self::set_option(self::ACTIVATION_REDIRECT_OPTION, 1);
    }

    private static function should_redirect_after_activation(): bool
    {
        if (!self::is_admin_request()) {
            return false;
        }

        if (function_exists('is_network_admin') && is_network_admin()) {
            return false;
        }

        if (
            self::is_bulk_activation_request()
            || self::is_ajax_request()
            || self::is_rest_request()
            || self::is_cron_request()
            || self::is_cli_request()
        ) {
            return false;
        }

        if (!function_exists('current_user_can') || !current_user_can(self::ADMIN_CAPABILITY)) {
            return false;
        }

        if (self::request_text_value($_GET, 'page', 80) === self::ADMIN_PAGE_SLUG) {
            self::delete_option(self::ACTIVATION_REDIRECT_OPTION);
            return false;
        }

        return true;
    }

    private static function activation_redirect_flag_enabled(mixed $flag): bool
    {
        if (is_bool($flag)) {
            return $flag;
        }

        if (is_scalar($flag)) {
            return !in_array(strtolower(trim((string) $flag)), ['', '0', 'false', 'no', 'off'], true);
        }

        return false;
    }

    /**
     * Deactivation intentionally keeps indexed data and only stops background work.
     */
    public static function deactivate(): void
    {
        self::clear_scheduled_queue_processor();
    }

    /**
     * Uninstall cleanup is explicit but conservative.
     *
     * Index tables are retained for now because dropping production search data
     * should be an intentional future policy decision. Operational options and
     * pending background work are removed so a later install can repair schema
     * state cleanly without running stale queue entries.
     */
    public static function uninstall(): void
    {
        self::clear_scheduled_queue_processor();
        self::delete_option(self::SCHEMA_VERSION_OPTION);
        self::delete_option(self::QUEUE_OPTION);
        self::delete_option(self::SANDBOX_DEMO_POSTS_OPTION);
        self::delete_option(self::ANALYZER_OPTIONS_OPTION);
        self::delete_option(self::SETTINGS_OPTION);
        self::delete_option(self::INDEX_LOCK_OPTION);
        self::delete_option(self::INDEX_HEALTH_OPTION);
    }

    /**
     * Idempotently create or repair tables and store the current schema version.
     */
    public static function upgrade_schema(): void
    {
        self::mysql_storage()->create_tables();
        self::set_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION);
    }

    /**
     * Repair schema only when the stored version is missing or stale.
     */
    public static function maybe_upgrade_schema(): void
    {
        if (self::option_matches_schema_version(self::get_option(self::SCHEMA_VERSION_OPTION, null))) {
            return;
        }

        self::upgrade_schema();
    }

    /**
     * Build a MySQL storage backend, optionally ensuring schema first.
     */
    public static function storage(bool $ensure_schema = false): WP_FTS_Storage_Mysql
    {
        if ($ensure_schema) {
            self::maybe_upgrade_schema();
        }

        return self::mysql_storage();
    }

    /**
     * Queue indexable posts after normal WordPress save/insert hooks.
     *
     * Non-indexable saved posts are tombstoned immediately so trash, deleted,
     * password-protected, or unsupported content cannot linger in search results.
     *
     * @param mixed $post WordPress post object when supplied by the hook.
     */
    public static function handle_post_save(int $post_id, mixed $post = null, mixed ...$unused): void
    {
        if (!self::is_normal_post_id($post_id)) {
            return;
        }

        $post = self::post_object($post_id, is_object($post) ? $post : null);
        if ($post !== null && !self::is_indexable_post($post)) {
            self::tombstone_post($post_id);
            self::remove_from_queue([$post_id]);
            return;
        }

        if ($post !== null && !self::settings()['auto_index']) {
            return;
        }

        if ($post !== null) {
            self::index_post($post, [], self::runtime_analyzer());
            self::remove_from_queue([$post_id]);
        }
    }

    /**
     * Keep indexed state aligned when a post enters or leaves searchable status.
     *
     * @param mixed $post WordPress post object passed by transition_post_status.
     */
    public static function handle_status_transition(string $new_status, string $old_status, mixed $post): void
    {
        if (!is_object($post) || !isset($post->ID)) {
            return;
        }

        $post_id = (int) $post->ID;
        if (!self::is_normal_post_id($post_id)) {
            return;
        }

        if (self::is_indexable_post($post)) {
            if (!self::settings()['auto_index']) {
                return;
            }

            self::index_post($post, [], self::runtime_analyzer());
            self::remove_from_queue([$post_id]);
            return;
        }

        if ($old_status !== $new_status) {
            self::tombstone_post($post_id);
            self::remove_from_queue([$post_id]);
        }
    }

    /**
     * Tombstone indexed documents when WordPress trashes or deletes posts.
     */
    public static function handle_post_delete(int $post_id): void
    {
        if (!self::is_normal_post_id($post_id)) {
            return;
        }

        self::tombstone_post($post_id);
    }

    /**
     * Process a bounded batch of queued post ids.
     *
     * @return int Number of queued ids processed.
     */
    public static function process_queue(int $batch_size = self::DEFAULT_BATCH_SIZE): int
    {
        $queue = self::pending_queue();
        if ($queue === []) {
            return 0;
        }

        $batch_size = max(1, $batch_size);
        $batch = array_slice($queue, 0, $batch_size);
        $remaining = array_slice($queue, count($batch));
        $processed = 0;

        foreach ($batch as $post_id) {
            $post = self::post_object($post_id);
            if ($post !== null && self::is_indexable_post($post)) {
                self::index_post($post, [], self::runtime_analyzer());
            } else {
                self::tombstone_post($post_id);
            }
            $processed++;
        }

        $queue = self::finish_queue_batch($batch, $remaining);
        if ($queue !== []) {
            self::schedule_queue_processor();
        }

        return $processed;
    }

    /**
     * WP-Cron entry point for bounded queue and backfill indexing work.
     *
     * @return array<string,mixed> Small batch summary suitable for logs/tests.
     */
    public static function process_scheduled_indexing(): array
    {
        return self::process_indexing_batch('cron');
    }

    /**
     * Manual entry point for the later health UI to advance indexing on demand.
     *
     * @param array<string,mixed> $opts Optional overrides for tests or callers.
     * @return array<string,mixed> Small batch summary suitable for UI feedback.
     */
    public static function process_manual_index_batch(array $opts = []): array
    {
        return self::process_indexing_batch('manual', $opts);
    }

    /**
     * Return compact indexing health state for the later admin dashboard.
     *
     * @return array<string,mixed>
     */
    public static function search_health(): array
    {
        $state = self::index_health_state();
        $pending_queue_count = count(self::pending_queue());
        $has_more = (bool) ($state['has_more'] ?? false);
        if ($pending_queue_count > 0) {
            $has_more = true;
        } elseif (self::has_eligible_unindexed_content()) {
            $has_more = true;
        }

        $state['pending_queue_count'] = $pending_queue_count;
        $state['has_more'] = $has_more;
        $state['lock_active'] = self::index_lock_active();

        return $state;
    }

    /**
     * Return read-only lifecycle state for operator surfaces.
     *
     * @return array<string,mixed>
     */
    public static function operator_status(): array
    {
        $schema = self::schema_status();
        $health = self::search_health();
        $lock = self::index_lock_status();
        $eligible_count = self::count_eligible_content();
        $indexed_count = self::count_indexed_eligible_content();
        $last_indexed_post_id = max(0, (int) ($health['last_indexed_post_id'] ?? 0));
        $last_indexed_title = is_scalar($health['last_indexed_post_title'] ?? null)
            ? (string) $health['last_indexed_post_title']
            : '';

        return [
            'schema_status' => $schema['status'],
            'schema_version' => $schema['stored_version'],
            'expected_schema_version' => $schema['expected_version'],
            'pending_queue_count' => max(0, (int) ($health['pending_queue_count'] ?? 0)),
            'lock_state' => $lock['state'],
            'lock_active' => (bool) $lock['active'],
            'lock_mode' => $lock['mode'],
            'lock_started_at' => $lock['started_at'],
            'lock_expires_at' => $lock['expires_at'],
            'has_more' => (bool) ($health['has_more'] ?? false),
            'last_mode' => is_scalar($health['last_mode'] ?? null) ? (string) $health['last_mode'] : '',
            'last_run_at' => is_scalar($health['last_run_at'] ?? null) ? (string) $health['last_run_at'] : '',
            'last_batch_processed' => max(0, (int) ($health['last_batch_processed'] ?? 0)),
            'last_batch_queue_processed' => max(0, (int) ($health['last_batch_queue_processed'] ?? 0)),
            'last_batch_backfill_processed' => max(0, (int) ($health['last_batch_backfill_processed'] ?? 0)),
            'last_skipped_locked' => (bool) ($health['last_skipped_locked'] ?? false),
            'last_stopped_by_budget' => (bool) ($health['last_stopped_by_budget'] ?? false),
            'last_indexed_post' => $last_indexed_post_id > 0
                ? trim($last_indexed_title . ' (ID ' . $last_indexed_post_id . ')')
                : '',
            'last_indexed_post_id' => $last_indexed_post_id,
            'last_indexed_post_title' => $last_indexed_title,
            'last_indexed_at' => is_scalar($health['last_indexed_at'] ?? null) ? (string) $health['last_indexed_at'] : '',
            'eligible_count' => $eligible_count,
            'indexed_count' => $indexed_count,
            'remaining_count' => max(0, $eligible_count - $indexed_count),
        ];
    }

    /**
     * Idempotently repair schema and return the resulting schema state.
     *
     * @return array{status:string,stored_version:int,expected_version:int}
     */
    public static function repair_schema(): array
    {
        self::upgrade_schema();

        return self::schema_status();
    }

    /**
     * Return schema status without repairing or indexing.
     *
     * @return array{status:string,stored_version:int,expected_version:int}
     */
    public static function schema_status(): array
    {
        $raw = self::get_option(self::SCHEMA_VERSION_OPTION, null);
        $stored_version = self::schema_version_from_option($raw);
        $status = 'stale';
        if (self::option_matches_schema_version($raw)) {
            $status = 'current';
        } elseif ($raw === null || $raw === false || $raw === '') {
            $status = 'missing';
        }

        return [
            'status' => $status,
            'stored_version' => $stored_version,
            'expected_version' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * Return bounded aggregate counts for the admin Health tab.
     *
     * @return array{total_eligible:int,indexed:int,pending:int,remaining:int}
     */
    private static function search_health_counts(): array
    {
        $total = self::count_eligible_content();
        $indexed = min($total, self::count_indexed_eligible_content());
        $pending = count(self::pending_queue());

        return [
            'total_eligible' => $total,
            'indexed' => $indexed,
            'pending' => $pending,
            'remaining' => max(0, $total - $indexed),
        ];
    }

    /**
     * @return array{total_eligible:int,indexed:int,pending:int,remaining:int}
     */
    private static function empty_search_health_counts(): array
    {
        return [
            'total_eligible' => 0,
            'indexed' => 0,
            'pending' => count(self::pending_queue()),
            'remaining' => 0,
        ];
    }

    /**
     * Return the current request's bounded FTS diagnostics.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function debug_traces(): array
    {
        return array_values(self::$debug_traces);
    }

    /**
     * Register an optional Debug Bar panel without requiring Debug Bar at load time.
     *
     * @param array<int,mixed> $panels
     * @return array<int,mixed>
     */
    public static function register_debug_bar_panel(array $panels): array
    {
        if (!class_exists('Debug_Bar_Panel') || !self::can_view_debug_diagnostics()) {
            return $panels;
        }

        $panels[] = new class extends Debug_Bar_Panel {
            public function init(): void
            {
                $this->title = 'FTS';
            }

            public function render(): void
            {
                WP_FTS_Plugin::render_debug_bar_diagnostics_panel();
            }
        };

        return $panels;
    }

    /**
     * Render the same bounded diagnostics used by the Debug Bar panel.
     */
    public static function render_debug_bar_diagnostics_panel(): void
    {
        if (!self::can_view_debug_diagnostics()) {
            return;
        }

        self::render_debug_diagnostics_panel('Full-Text Search diagnostics');
    }

    private static function debug_collection_enabled(string $context = ''): bool
    {
        $enabled = self::debug_constant_enabled();
        if (!$enabled && function_exists('current_user_can') && current_user_can(self::ADMIN_CAPABILITY)) {
            $enabled = true;
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters(self::DEBUG_ENABLED_FILTER, $enabled, $context);
            if (is_bool($filtered)) {
                return $filtered;
            }
            if (is_scalar($filtered)) {
                return self::truthy_admin_value($filtered);
            }

            return (bool) $filtered;
        }

        return $enabled;
    }

    private static function debug_constant_enabled(): bool
    {
        return defined('WP_FTS_DEBUG') && self::truthy_admin_value(constant('WP_FTS_DEBUG'));
    }

    private static function can_view_debug_diagnostics(): bool
    {
        return self::debug_collection_enabled('diagnostics surface');
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $extra
     */
    private static function debug_start_trace(string $context, string $search_text = '', array $settings = [], array $extra = []): int
    {
        if (!self::debug_collection_enabled($context)) {
            return 0;
        }

        while (count(self::$debug_traces) >= self::DEBUG_MAX_TRACES) {
            unset(self::$debug_traces[array_key_first(self::$debug_traces)]);
        }

        $id = self::$debug_next_trace_id++;
        self::$debug_traces[$id] = array_replace([
            'id' => $id,
            'context' => self::debug_truncate_text($context, 80),
            'status' => 'started',
            'bailout_reason' => '',
            'search_text' => self::debug_truncate_text($search_text),
            'query_lang' => '',
            'fallback_languages' => [],
            'settings' => self::debug_normalize_assoc($settings),
            'timings_ms' => [],
            'counts' => self::debug_default_counts(),
            'analyzer_pack_status' => [],
            'search_explain' => [],
            'notes' => [],
        ], self::debug_normalize_trace_extra($extra));

        return $id;
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $extra
     */
    private static function debug_record_bailout(string $context, string $search_text, string $reason, array $settings = [], array $extra = []): void
    {
        $trace_id = self::debug_start_trace($context, $search_text, $settings, $extra);
        if ($trace_id <= 0) {
            return;
        }

        self::debug_finish_trace($trace_id, 'bailed', $reason);
    }

    private static function debug_finish_trace(int $trace_id, string $status = 'ran', string $reason = ''): void
    {
        if (!isset(self::$debug_traces[$trace_id])) {
            return;
        }

        self::$debug_traces[$trace_id]['status'] = self::debug_truncate_text($status, 40);
        if ($reason !== '') {
            self::$debug_traces[$trace_id]['bailout_reason'] = self::debug_truncate_text($reason);
        }
    }

    private static function debug_add_timing(int $trace_id, string $phase, float $started): void
    {
        if (!isset(self::$debug_traces[$trace_id])) {
            return;
        }

        $phase = self::debug_truncate_text($phase, 80);
        if ($phase === '') {
            return;
        }

        $timings = is_array(self::$debug_traces[$trace_id]['timings_ms'] ?? null)
            ? self::$debug_traces[$trace_id]['timings_ms']
            : [];
        if (!array_key_exists($phase, $timings) && count($timings) >= self::DEBUG_MAX_TIMING_PHASES) {
            return;
        }

        $elapsed = max(0.0, (microtime(true) - $started) * 1000.0);
        $timings[$phase] = round((float) ($timings[$phase] ?? 0.0) + $elapsed, 3);
        self::$debug_traces[$trace_id]['timings_ms'] = $timings;
    }

    private static function debug_add_count(int $trace_id, string $key, int $delta = 1): void
    {
        if (!isset(self::$debug_traces[$trace_id]) || $delta === 0) {
            return;
        }

        $counts = is_array(self::$debug_traces[$trace_id]['counts'] ?? null)
            ? self::$debug_traces[$trace_id]['counts']
            : self::debug_default_counts();
        $key = self::debug_truncate_text($key, 80);
        if ($key === '') {
            return;
        }

        $counts[$key] = max(0, (int) ($counts[$key] ?? 0) + $delta);
        self::$debug_traces[$trace_id]['counts'] = $counts;
    }

    /**
     * @param array<string,mixed> $values
     */
    private static function debug_set_counts(int $trace_id, array $values): void
    {
        if (!isset(self::$debug_traces[$trace_id])) {
            return;
        }

        $counts = is_array(self::$debug_traces[$trace_id]['counts'] ?? null)
            ? self::$debug_traces[$trace_id]['counts']
            : self::debug_default_counts();
        foreach ($values as $key => $value) {
            if (!is_scalar($key) || !is_numeric($value)) {
                continue;
            }
            $counts[self::debug_truncate_text((string) $key, 80)] = max(0, (int) $value);
        }

        self::$debug_traces[$trace_id]['counts'] = $counts;
    }

    /**
     * @param string[] $fallback_languages
     */
    private static function debug_set_query_language(int $trace_id, string $query_lang, array $fallback_languages = [], bool $include_sandbox_packs = false): void
    {
        if (!isset(self::$debug_traces[$trace_id])) {
            return;
        }

        $query_lang = WP_FTS_TermNamespace::canonicalize_lang($query_lang);
        self::$debug_traces[$trace_id]['query_lang'] = $query_lang;
        self::$debug_traces[$trace_id]['fallback_languages'] = self::debug_normalize_list($fallback_languages);
        self::$debug_traces[$trace_id]['analyzer_pack_status'] = self::debug_relevant_analyzer_pack_statuses($query_lang, $include_sandbox_packs);
    }

    /**
     * @param string[] $notes
     */
    private static function debug_add_notes(int $trace_id, array $notes): void
    {
        if (!isset(self::$debug_traces[$trace_id])) {
            return;
        }

        $existing = is_array(self::$debug_traces[$trace_id]['notes'] ?? null)
            ? self::$debug_traces[$trace_id]['notes']
            : [];
        foreach ($notes as $note) {
            if (!is_scalar($note)) {
                continue;
            }
            $note = self::debug_truncate_text((string) $note);
            if ($note !== '') {
                $existing[] = $note;
            }
            if (count($existing) >= self::DEBUG_MAX_LIST_ITEMS) {
                break;
            }
        }

        self::$debug_traces[$trace_id]['notes'] = array_slice(array_values(array_unique($existing)), 0, self::DEBUG_MAX_LIST_ITEMS);
    }

    /**
     * @return array<string,int>
     */
    private static function debug_default_counts(): array
    {
        return [
            'search_batches' => 0,
            'candidate_rows' => 0,
            'result_ids_considered' => 0,
            'result_ids_returned' => 0,
            'visible_results' => 0,
            'snippets_generated' => 0,
            'title_snippets_generated' => 0,
            'highlight_replacements' => 0,
            'render_block_visits' => 0,
            'render_block_replacements' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function debug_normalize_trace_extra(array $extra): array
    {
        $allowed = [];
        foreach (['query_lang', 'fallback_languages', 'settings', 'counts', 'timings_ms', 'analyzer_pack_status', 'search_explain', 'notes'] as $key) {
            if (array_key_exists($key, $extra)) {
                $allowed[$key] = is_array($extra[$key]) && $key === 'search_explain'
                    ? self::debug_normalize_structured_value($extra[$key])
                    : (is_array($extra[$key]) ? self::debug_normalize_assoc($extra[$key]) : self::debug_truncate_text((string) $extra[$key]));
            }
        }

        return $allowed;
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function debug_effective_settings(array $settings, array $overrides = []): array
    {
        $summary = [
            'public_site_search' => !empty($settings['replace_frontend_search']) ? 'enabled' : 'disabled',
            'admin_posts_search' => !empty($settings['replace_admin_post_search']) ? 'enabled' : 'disabled',
            'provider_compatibility' => self::search_provider_compatibility_debug_value((string) ($settings['search_provider_compatibility'] ?? self::SEARCH_PROVIDER_COMPATIBILITY_PREFER_FTS)),
            'match_mode' => (string) ($settings['match_mode'] ?? 'OR'),
            'prefix_matching' => !empty($settings['prefix_matching']) ? 'enabled' : 'disabled',
            'highlight' => !empty($settings['highlight']) ? 'enabled' : 'disabled',
            'snippet_length' => (int) ($settings['snippet_length'] ?? self::FRONTEND_SNIPPET_LENGTH),
            'result_limit' => (int) ($settings['result_limit'] ?? 10),
            'language_fallback' => !empty($settings['language_fallback']) ? 'enabled' : 'disabled',
        ];

        foreach ($overrides as $key => $value) {
            if (!is_scalar($key)) {
                continue;
            }
            $summary[(string) $key] = $value;
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function debug_normalize_assoc(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (!is_scalar($key)) {
                continue;
            }
            $normalized[self::debug_truncate_text((string) $key, 80)] = self::debug_normalize_value($value);
            if (count($normalized) >= self::DEBUG_MAX_ASSOC_ITEMS) {
                break;
            }
        }

        return $normalized;
    }

    private static function debug_normalize_value(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return self::debug_truncate_text((string) $value);
        }
        if (is_array($value)) {
            return self::debug_normalize_list($value);
        }

        return self::debug_truncate_text(get_debug_type($value), 80);
    }

    /**
     * @param array<int|string,mixed> $values
     * @return string[]
     */
    private static function debug_normalize_list(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = self::debug_truncate_text((string) $value, 80);
            if ($value !== '') {
                $normalized[$value] = true;
            }
            if (count($normalized) >= self::DEBUG_MAX_LIST_ITEMS) {
                break;
            }
        }

        return array_keys($normalized);
    }

    private static function debug_set_search_explain(int $trace_id, mixed $explain): void
    {
        if (!isset(self::$debug_traces[$trace_id]) || !is_array($explain) || $explain === []) {
            return;
        }

        $existing = is_array(self::$debug_traces[$trace_id]['search_explain'] ?? null)
            ? self::$debug_traces[$trace_id]['search_explain']
            : [];
        if ($existing !== []) {
            return;
        }

        self::$debug_traces[$trace_id]['search_explain'] = self::debug_normalize_structured_value($explain);
    }

    private static function debug_normalize_structured_value(mixed $value, int $depth = 0): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return self::debug_truncate_text((string) $value);
        }
        if (!is_array($value)) {
            return self::debug_truncate_text(get_debug_type($value), 80);
        }
        if ($depth >= 5) {
            return self::debug_list_summary($value);
        }

        $normalized = [];
        $limit = self::debug_is_list($value) ? self::DEBUG_MAX_LIST_ITEMS : self::DEBUG_MAX_ASSOC_ITEMS;
        foreach ($value as $key => $item) {
            if (count($normalized) >= $limit) {
                break;
            }
            if (self::debug_is_list($value)) {
                $normalized[] = self::debug_normalize_structured_value($item, $depth + 1);
                continue;
            }
            if (!is_scalar($key)) {
                continue;
            }
            $normalized[self::debug_truncate_text((string) $key, 80)] = self::debug_normalize_structured_value($item, $depth + 1);
        }

        return $normalized;
    }

    private static function debug_is_list(array $value): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        $index = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $index++) {
                return false;
            }
        }

        return true;
    }

    private static function debug_truncate_text(string $value, int $max_bytes = self::DEBUG_MAX_TEXT_BYTES): string
    {
        $value = trim(str_replace(["\r", "\n", "\t"], ' ', WP_FTS_Utf8::repair($value)));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        if ($max_bytes <= 0 || strlen($value) <= $max_bytes) {
            return $value;
        }

        return rtrim(WP_FTS_Utf8::truncate_bytes($value, max(0, $max_bytes - 3))) . '...';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function debug_relevant_analyzer_pack_statuses(string $language, bool $include_sandbox): array
    {
        $language = WP_FTS_TermNamespace::canonicalize_lang($language);
        $base_language = $language !== '' ? self::base_language($language) : '';
        $statuses = self::runtime_analyzer_pack_statuses();
        if ($include_sandbox) {
            $statuses = array_merge($statuses, self::sandbox_demo_analyzer_pack_statuses());
        }

        $rows = [];
        foreach ($statuses as $status) {
            $status_language = WP_FTS_TermNamespace::canonicalize_lang((string) ($status['language'] ?? ''));
            $matches = $language === ''
                || $status_language === $language
                || ($base_language !== '' && self::base_language($status_language) === $base_language);
            if (!$matches) {
                continue;
            }

            $rows[] = [
                'language' => $status_language,
                'kind' => self::debug_truncate_text((string) ($status['kind'] ?? ''), 40),
                'status' => self::debug_truncate_text((string) ($status['status'] ?? ''), 40),
                'pack_id' => self::debug_truncate_text((string) ($status['pack_id'] ?? ''), 80),
                'scope' => !empty($status['fixture_only']) ? 'fixture' : 'runtime',
                'reason' => self::debug_truncate_text((string) ($status['reason'] ?? ''), 120),
            ];
            if (count($rows) >= self::DEBUG_MAX_LIST_ITEMS) {
                break;
            }
        }

        if ($rows === [] && $language !== '') {
            $support = self::language_support_details($language, $include_sandbox);
            $rows[] = [
                'language' => $language,
                'kind' => 'fallback',
                'status' => $support['label'],
                'pack_id' => '',
                'scope' => 'runtime',
                'reason' => self::debug_truncate_text($support['reason'], 120),
            ];
        }

        return $rows;
    }

    private static function render_debug_diagnostics_panel(string $heading): void
    {
        echo '<div class="wp-fts-debug-diagnostics">';
        echo '<h3>' . self::esc_html($heading) . '</h3>';
        if (self::$debug_traces === []) {
            echo '<p>No FTS diagnostics were collected for this request.</p>';
            echo '</div>';
            return;
        }

        foreach (self::debug_traces() as $trace) {
            $context = is_scalar($trace['context'] ?? null) ? (string) $trace['context'] : 'FTS request';
            $status = is_scalar($trace['status'] ?? null) ? (string) $trace['status'] : 'unknown';
            echo '<details class="wp-fts-debug-trace" open>';
            echo '<summary>' . self::esc_html($context . ' - ' . $status) . '</summary>';
            echo '<table class="widefat striped wp-fts-debug-table"><tbody>';
            self::render_debug_row('Search text', self::debug_scalar_summary($trace['search_text'] ?? ''));
            self::render_debug_row('Query language', self::debug_scalar_summary($trace['query_lang'] ?? ''));
            self::render_debug_row('Fallback languages', self::debug_list_summary($trace['fallback_languages'] ?? []));
            if (is_scalar($trace['bailout_reason'] ?? null) && (string) $trace['bailout_reason'] !== '') {
                self::render_debug_row('Bailout reason', (string) $trace['bailout_reason']);
            }
            self::render_debug_row('Settings', self::debug_assoc_summary($trace['settings'] ?? []));
            $search_explain = is_array($trace['search_explain'] ?? null) ? $trace['search_explain'] : [];
            self::render_debug_row('Storage backend', self::debug_assoc_summary($search_explain['storage'] ?? []));
            self::render_debug_row('Query plan', self::debug_query_plan_summary($search_explain['query_plan'] ?? []));
            self::render_debug_row('Fast mode', self::debug_assoc_summary($search_explain['fast_mode'] ?? []));
            self::render_debug_row('Scoring', self::debug_assoc_summary($search_explain['scoring'] ?? []));
            self::render_debug_row('Result matches', self::debug_result_matches_summary($search_explain['results'] ?? []));
            self::render_debug_row('Counts', self::debug_assoc_summary($trace['counts'] ?? []));
            self::render_debug_row('Timings', self::debug_timing_summary($trace['timings_ms'] ?? []));
            self::render_debug_row('Analyzer packs', self::debug_pack_status_summary($trace['analyzer_pack_status'] ?? []));
            self::render_debug_row('Notes', self::debug_list_summary($trace['notes'] ?? []));
            echo '</tbody></table>';
            echo '</details>';
        }
        echo '</div>';
    }

    private static function render_debug_row(string $label, string $value): void
    {
        echo '<tr><th scope="row">' . self::esc_html($label) . '</th><td>' . self::esc_html($value !== '' ? $value : '-') . '</td></tr>';
    }

    private static function debug_scalar_summary(mixed $value): string
    {
        return is_scalar($value) ? self::debug_truncate_text((string) $value) : '';
    }

    private static function debug_list_summary(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $items = self::debug_normalize_list($value);

        return implode(', ', $items);
    }

    private static function debug_assoc_summary(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        foreach ($value as $key => $item) {
            if (!is_scalar($key)) {
                continue;
            }
            $summary = is_array($item) ? self::debug_list_summary($item) : self::debug_scalar_summary($item);
            $parts[] = self::debug_truncate_text((string) $key, 80) . '=' . ($summary !== '' ? $summary : '-');
            if (count($parts) >= self::DEBUG_MAX_ASSOC_ITEMS) {
                break;
            }
        }

        return self::debug_truncate_text(implode(', ', $parts), 800);
    }

    private static function debug_timing_summary(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        foreach ($value as $phase => $milliseconds) {
            if (!is_scalar($phase) || !is_numeric($milliseconds)) {
                continue;
            }
            $parts[] = self::debug_truncate_text((string) $phase, 80) . '=' . number_format((float) $milliseconds, 3, '.', '') . 'ms';
            if (count($parts) >= self::DEBUG_MAX_TIMING_PHASES) {
                break;
            }
        }

        return self::debug_truncate_text(implode(', ', $parts), 800);
    }

    private static function debug_pack_status_summary(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $language = self::debug_scalar_summary($row['language'] ?? '');
            $kind = self::debug_scalar_summary($row['kind'] ?? '');
            $status = self::debug_scalar_summary($row['status'] ?? '');
            $pack = self::debug_scalar_summary($row['pack_id'] ?? '');
            $reason = self::debug_scalar_summary($row['reason'] ?? '');
            $parts[] = trim($language . ' ' . $kind . ' ' . $status . ($pack !== '' ? ' ' . $pack : '') . ($reason !== '' ? ' (' . $reason . ')' : ''));
            if (count($parts) >= self::DEBUG_MAX_LIST_ITEMS) {
                break;
            }
        }

        return self::debug_truncate_text(implode('; ', $parts), 800);
    }

    private static function debug_query_plan_summary(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        foreach (['match_mode', 'logical_group_count', 'prefix_matching', 'prefix_added_terms'] as $key) {
            if (array_key_exists($key, $value)) {
                $parts[] = $key . '=' . self::debug_scalar_summary($value[$key]);
            }
        }
        if (isset($value['analyzed_languages']) && is_array($value['analyzed_languages'])) {
            $parts[] = 'languages=' . self::debug_list_summary($value['analyzed_languages']);
        }

        $termParts = [];
        if (isset($value['terms']) && is_array($value['terms'])) {
            foreach ($value['terms'] as $term) {
                if (!is_array($term)) {
                    continue;
                }
                $termParts[] = self::debug_explain_term_summary($term);
                if (count($termParts) >= self::DEBUG_MAX_LIST_ITEMS) {
                    break;
                }
            }
        }
        if ($termParts !== []) {
            $parts[] = 'terms=' . implode(' | ', $termParts) . (!empty($value['terms_more']) ? ' ...' : '');
        }

        return self::debug_truncate_text(implode(', ', array_filter($parts, static fn(string $part): bool => $part !== '')), 800);
    }

    private static function debug_result_matches_summary(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $doc = self::debug_scalar_summary($row['doc_id'] ?? '');
            $matches = [];
            if (isset($row['matches']) && is_array($row['matches'])) {
                foreach ($row['matches'] as $match) {
                    if (!is_array($match)) {
                        continue;
                    }
                    $matches[] = self::debug_explain_term_summary($match);
                    if (count($matches) >= self::DEBUG_MAX_LIST_ITEMS) {
                        break;
                    }
                }
            }
            $rows[] = 'doc ' . ($doc !== '' ? $doc : '?') . '=' . ($matches !== [] ? implode(' | ', $matches) : '-');
            if (count($rows) >= self::DEBUG_MAX_LIST_ITEMS) {
                break;
            }
        }

        return self::debug_truncate_text(implode('; ', $rows), 800);
    }

    /**
     * Summarize one explain term as surface->stored-term when analysis changes it.
     *
     * @param array<string,mixed> $term
     */
    private static function debug_explain_term_summary(array $term): string
    {
        $lang = self::debug_scalar_summary($term['lang'] ?? '');
        $surface = self::debug_scalar_summary($term['surface'] ?? '');
        $analyzed = self::debug_scalar_summary($term['term'] ?? '');
        $rank = self::debug_scalar_summary($term['rank_class'] ?? '');

        $text = $analyzed;
        if ($surface !== '' && $analyzed !== '' && $surface !== $analyzed) {
            $text = $surface . '->' . $analyzed;
        } elseif ($surface !== '') {
            $text = $surface;
        }

        return trim($lang . ':' . $text . ($rank !== '' ? ' ' . $rank : ''));
    }

    /**
     * Register the public REST search endpoint.
     */
    public static function register_rest_routes(): void
    {
        if (!function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(self::REST_NAMESPACE, self::REST_SEARCH_ROUTE, [
            'methods' => class_exists('WP_REST_Server') ? WP_REST_Server::READABLE : 'GET',
            'callback' => [self::class, 'rest_search'],
            'permission_callback' => [self::class, 'rest_search_permission'],
            'args' => [
                'q' => ['required' => false],
                'query' => ['required' => false],
                'lang' => ['required' => false],
                'mode' => ['required' => false],
                'limit' => ['required' => false],
            ],
        ]);
    }

    /**
     * Register the primary wp-admin surface under Settings.
     */
    public static function register_admin_menu(): void
    {
        if (!function_exists('add_options_page')) {
            return;
        }

        add_options_page(
            'Full-Text Search',
            'Full-Text Search',
            self::ADMIN_CAPABILITY,
            self::ADMIN_PAGE_SLUG,
            [self::class, 'render_admin_settings_page']
        );
    }

    /**
     * Register the plugin's operator-facing settings option.
     */
    public static function register_settings(): void
    {
        if (!function_exists('register_setting')) {
            return;
        }

        register_setting(self::SETTINGS_GROUP, self::SETTINGS_OPTION, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_settings'],
            'default' => self::default_settings(),
        ]);
    }

    /**
     * Add the optional per-post language override control to searchable post types.
     */
    public static function register_language_meta_box(): void
    {
        if (!function_exists('add_meta_box')) {
            return;
        }

        foreach (self::language_meta_box_post_types() as $post_type) {
            add_meta_box(
                'wp-fts-post-language',
                'FTS Language',
                [self::class, 'render_language_meta_box'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * Render a compact language selector for the post edit screen.
     *
     * @param mixed $post WordPress post object.
     */
    public static function render_language_meta_box(mixed $post): void
    {
        $post_id = is_object($post) && isset($post->ID) ? (int) $post->ID : 0;
        $selected_language = self::post_language_override($post_id) ?? 'auto';
        $nonce = function_exists('wp_create_nonce') ? (string) wp_create_nonce(self::POST_LANGUAGE_NONCE_ACTION) : '';

        echo '<input type="hidden" name="' . self::esc_attr(self::POST_LANGUAGE_NONCE_FIELD) . '" value="' . self::esc_attr($nonce) . '">';
        echo '<p><label for="wp-fts-post-language">Post language</label></p>';
        echo '<select id="wp-fts-post-language" name="' . self::esc_attr(self::POST_LANGUAGE_FIELD) . '" style="width:100%;">';
        foreach (['auto' => 'Automatic'] + self::sandbox_language_labels() as $language => $label) {
            $selected = $selected_language === $language ? ' selected="selected"' : '';
            echo '<option value="' . self::esc_attr($language) . '"' . $selected . '>' . self::esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Automatic detection is the default. Choose a language to pin indexing for this post.</p>';
    }

    /**
     * Persist the optional post language override before the normal save hook indexes the post.
     *
     * @param mixed $post WordPress post object from the save hook.
     */
    public static function save_post_language_override(int $post_id, mixed $post = null, mixed ...$unused): void
    {
        if (!self::is_normal_post_id($post_id)) {
            return;
        }

        if (!array_key_exists(self::POST_LANGUAGE_FIELD, $_POST) && !array_key_exists(self::POST_LANGUAGE_NONCE_FIELD, $_POST)) {
            return;
        }

        if (!self::verify_post_language_nonce()) {
            return;
        }

        if (!function_exists('current_user_can') || !current_user_can('edit_post', $post_id)) {
            return;
        }

        $language = self::sanitize_post_language_override(self::request_text_value($_POST, self::POST_LANGUAGE_FIELD, 20));
        if ($language === '') {
            self::delete_post_language_override($post_id);
            return;
        }

        self::set_post_language_override($post_id, $language);
    }

    /**
     * Render the primary Settings > Full-Text Search admin page.
     */
    public static function render_admin_settings_page(?string $forced_tab = null): void
    {
        if (!self::can_manage_admin_sandbox()) {
            echo '<div class="wrap">';
            echo '<h1>Full-Text Search</h1>';
            self::render_sandbox_notice('error', 'You do not have permission to manage Full-Text Search settings.');
            echo '</div>';
            return;
        }

        $tab = $forced_tab !== null && $forced_tab !== ''
            ? self::sanitize_admin_tab($forced_tab)
            : self::selected_admin_tab();

        echo '<div class="wrap">';
        echo '<h1>Full-Text Search</h1>';
        self::render_admin_compact_styles();
        self::render_admin_orientation();
        self::render_admin_tabs($tab);
        self::render_site_language_status_notice();
        foreach (self::handle_admin_health_post_action() as $message) {
            self::render_sandbox_notice($message[0], $message[1]);
        }
        foreach (self::handle_admin_sandbox_post_action() as $message) {
            self::render_sandbox_notice($message[0], $message[1]);
        }
        self::render_legacy_sandbox_demo_cleanup_affordance($tab);

        if ($tab === self::ADMIN_HEALTH_TAB) {
            self::render_health_tab();
        } elseif ($tab === self::ADMIN_SANDBOX_TAB) {
            self::render_admin_sandbox_tab();
        } elseif ($tab === self::ADMIN_INDEXED_TAB) {
            self::render_indexed_content_tab();
        } elseif ($tab === self::ADMIN_ANALYZER_TAB) {
            self::render_analyzer_packs_tab();
        } else {
            self::render_settings_tab();
        }

        echo '</div>';
    }

    /**
     * Compatibility entry point for the old sandbox callback.
     */
    public static function render_admin_sandbox(): void
    {
        self::render_admin_settings_page(self::ADMIN_SANDBOX_TAB);
    }

    /**
     * Load slow Sandbox result details after the fast result rows are visible.
     */
    public static function handle_sandbox_result_details_ajax(): void
    {
        $source = array_replace($_GET, $_POST);
        if (!self::can_manage_admin_sandbox()) {
            self::send_admin_json_error('You do not have permission to load Sandbox result details.', 403);
            return;
        }

        if (!self::verify_sandbox_details_nonce($source)) {
            self::send_admin_json_error('The Sandbox detail request could not be verified. Reload the page and try again.', 403);
            return;
        }

        $query = self::sandbox_search_query_from_source($source);
        $post_ids = self::request_id_list_value($source, self::ADMIN_DETAILS_POST_IDS_FIELD, self::MAX_SEARCH_LIMIT);
        if ($query === '' || $post_ids === []) {
            self::send_admin_json_error('The Sandbox detail request is missing a query or result row.', 400);
            return;
        }

        try {
            $rows = self::sandbox_result_details(
                $query,
                self::sandbox_selected_language_from_source($source),
                self::sandbox_search_controls_from_source($source, true),
                $post_ids,
                self::sandbox_indexed_terms_debug_enabled_from_source($source)
            );
        } catch (Throwable $e) {
            self::send_admin_json_error('Could not load Sandbox result details.', 500);
            return;
        }

        self::send_admin_json_success(['rows' => $rows]);
    }

    /**
     * Current admin user gate for all sandbox rendering and actions.
     */
    private static function can_manage_admin_sandbox(): bool
    {
        return function_exists('current_user_can') && current_user_can(self::ADMIN_CAPABILITY);
    }

    /**
     * @return array<string,string>
     */
    private static function admin_tabs(): array
    {
        return [
            self::ADMIN_HEALTH_TAB => 'Health',
            self::ADMIN_SETTINGS_TAB => 'Settings',
            self::ADMIN_SANDBOX_TAB => 'Sandbox',
            self::ADMIN_INDEXED_TAB => 'Indexed content',
            self::ADMIN_ANALYZER_TAB => 'Analyzer packs',
        ];
    }

    private static function selected_admin_tab(): string
    {
        return self::sanitize_admin_tab(self::request_text_value($_GET, self::ADMIN_TAB_FIELD, 40));
    }

    private static function sanitize_admin_tab(string $tab): string
    {
        $tab = self::sanitize_key($tab);

        return array_key_exists($tab, self::admin_tabs()) ? $tab : self::ADMIN_HEALTH_TAB;
    }

    private static function render_admin_tabs(string $current_tab): void
    {
        echo '<nav class="nav-tab-wrapper" aria-label="Full-Text Search tabs">';
        foreach (self::admin_tabs() as $tab => $label) {
            $classes = 'nav-tab';
            $aria_current = '';
            if ($tab === $current_tab) {
                $classes .= ' nav-tab-active';
                $aria_current = ' aria-current="page"';
            }
            echo '<a class="' . self::esc_attr($classes) . '" href="' . self::esc_url(self::admin_page_url($tab)) . '"' . $aria_current . '>';
            echo self::esc_html($label);
            echo '</a>';
        }
        echo '</nav>';
    }

    /**
     * @return array<int,array{0:string,1:string}>
     */
    private static function handle_admin_health_post_action(): array
    {
        if (!self::health_post_action_submitted()) {
            return [];
        }

        if (!self::can_manage_admin_sandbox()) {
            return [['error', 'You do not have permission to index content.']];
        }

        if (!self::verify_health_nonce()) {
            return [['error', 'The indexing action could not be verified. Reload the page and try again.']];
        }

        if (self::health_post_action() !== self::ADMIN_HEALTH_MANUAL_BATCH_ACTION) {
            return [['error', 'Unsupported indexing action. No changes were made.']];
        }

        try {
            return [self::manual_index_batch_notice(self::process_manual_index_batch())];
        } catch (Throwable $e) {
            return [['error', 'Could not index the next batch: ' . $e->getMessage()]];
        }
    }

    /**
     * @param array<string,mixed> $summary
     * @return array{0:string,1:string}
     */
    private static function manual_index_batch_notice(array $summary): array
    {
        $processed = max(0, (int) ($summary['processed'] ?? 0));

        if (!empty($summary['skipped_locked'])) {
            return ['info', 'Another indexing batch is already running. No overlapping batch was started; try again shortly.'];
        }

        if (!empty($summary['stopped_by_budget'])) {
            return [
                'info',
                sprintf(
                    'Indexed %d %s, then stopped safely before a resource limit was reached. More content remains.',
                    $processed,
                    self::item_count_label($processed)
                ),
            ];
        }

        if ($processed > 0 && !empty($summary['has_more'])) {
            return [
                'success',
                sprintf(
                    'Indexed %d %s. More content remains; WP-Cron will keep indexing small batches in the background.',
                    $processed,
                    self::item_count_label($processed)
                ),
            ];
        }

        if ($processed > 0) {
            return [
                'success',
                sprintf(
                    'Indexed %d %s. The index is up to date for the current settings.',
                    $processed,
                    self::item_count_label($processed)
                ),
            ];
        }

        if (!empty($summary['has_more'])) {
            return ['info', 'No content was indexed in this batch, but more content may remain. WP-Cron will keep working in the background.'];
        }

        return ['info', 'No new eligible content needed indexing. The index is up to date for the current settings.'];
    }

    private static function item_count_label(int $count): string
    {
        return $count === 1 ? 'item' : 'items';
    }

    /**
     * @return array<int,array{0:string,1:string}>
     */
    private static function handle_admin_sandbox_post_action(): array
    {
        if (!self::sandbox_post_action_submitted()) {
            return [];
        }

        $action = self::sandbox_post_action();
        if (!self::verify_sandbox_nonce()) {
            return [['error', 'The sandbox action could not be verified. Reload the page and try again.']];
        }

        if (in_array($action, self::LEGACY_DEMO_CREATION_ACTIONS, true)) {
            return [['error', 'Sandbox demo post creation is disabled. The sandbox searches existing indexed content and does not create demo posts.']];
        }

        if ($action !== self::ADMIN_CLEANUP_LEGACY_DEMO_ACTION) {
            return [['error', 'Unsupported sandbox action. No changes were made.']];
        }

        try {
            $cleanup = self::move_legacy_sandbox_demo_posts_to_trash();
        } catch (Throwable $e) {
            return [['error', 'Could not clean up legacy sandbox demo posts: ' . $e->getMessage()]];
        }

        if ($cleanup['failed'] > 0) {
            return [[
                'error',
                sprintf(
                    'Moved %d legacy sandbox demo post(s) to Trash, but %d post(s) could not be moved.',
                    $cleanup['moved'],
                    $cleanup['failed']
                ),
            ]];
        }

        if ($cleanup['moved'] > 0) {
            return [['success', sprintf('Moved %d legacy sandbox demo post(s) to Trash.', $cleanup['moved'])]];
        }

        return [['info', 'No legacy sandbox demo posts were found. The stored sandbox demo marker was cleared.']];
    }

    private static function render_legacy_sandbox_demo_cleanup_affordance(string $tab): void
    {
        $candidates = self::legacy_sandbox_demo_cleanup_candidates();
        if ($candidates === []) {
            return;
        }

        echo '<div class="notice notice-warning wp-fts-legacy-sandbox-cleanup">';
        echo '<p><strong>Legacy sandbox demo posts detected.</strong> This version no longer creates demo posts. You can move the old exact FTS Sandbox demo posts to Trash.</p>';
        echo '<form method="post" action="' . self::esc_url(self::admin_page_url($tab)) . '">';
        self::render_sandbox_nonce_field();
        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_ACTION_FIELD) . '" value="' . self::esc_attr(self::ADMIN_CLEANUP_LEGACY_DEMO_ACTION) . '">';
        echo '<p><button type="submit" class="button">Move legacy sandbox demo posts to Trash</button> ';
        echo '<span class="description">' . self::esc_html(sprintf('%d exact legacy post(s) found.', count($candidates))) . '</span></p>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * @return array<int,object>
     */
    private static function legacy_sandbox_demo_cleanup_candidates(): array
    {
        $candidates = [];
        foreach (self::sandbox_demo_post_ids() as $post_id) {
            self::maybe_add_legacy_sandbox_demo_candidate($candidates, $post_id);
        }

        foreach (self::legacy_sandbox_demo_post_signatures() as $signature) {
            foreach (self::legacy_sandbox_demo_query_post_ids($signature) as $post_id) {
                self::maybe_add_legacy_sandbox_demo_candidate($candidates, $post_id);
            }
        }

        ksort($candidates, SORT_NUMERIC);

        return $candidates;
    }

    /**
     * @param array<int,object> $candidates
     */
    private static function maybe_add_legacy_sandbox_demo_candidate(array &$candidates, int $post_id): void
    {
        if ($post_id <= 0 || isset($candidates[$post_id])) {
            return;
        }

        $post = self::post_object($post_id);
        if ($post === null || !self::is_legacy_sandbox_demo_cleanup_target($post)) {
            return;
        }

        $candidates[$post_id] = $post;
    }

    /**
     * @param array{title:string,slug:string} $signature
     * @return int[]
     */
    private static function legacy_sandbox_demo_query_post_ids(array $signature): array
    {
        if (!function_exists('get_posts')) {
            return [];
        }

        $base_args = [
            'post_type' => 'any',
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'numberposts' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'suppress_filters' => true,
        ];
        $ids = [];
        foreach ([
            ['title' => $signature['title']],
            ['name' => $signature['slug']],
            ['post_name__in' => [$signature['slug']]],
        ] as $query_args) {
            $posts = get_posts($query_args + $base_args);
            if (!is_array($posts)) {
                continue;
            }
            foreach ($posts as $post) {
                $post_id = is_object($post) && isset($post->ID) ? (int) $post->ID : (int) $post;
                if ($post_id > 0) {
                    $ids[$post_id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    private static function is_legacy_sandbox_demo_cleanup_target(object $post): bool
    {
        if (self::post_status_from_object($post) === 'trash') {
            return false;
        }

        $title = isset($post->post_title) && is_scalar($post->post_title) ? trim((string) $post->post_title) : '';
        return in_array($title, self::legacy_sandbox_demo_titles(), true);
    }

    /**
     * @return array{moved:int,failed:int}
     */
    private static function move_legacy_sandbox_demo_posts_to_trash(): array
    {
        $moved = 0;
        $failed = 0;
        foreach (self::legacy_sandbox_demo_cleanup_candidates() as $post_id => $post) {
            if (!self::is_legacy_sandbox_demo_cleanup_target($post) || !function_exists('wp_trash_post')) {
                $failed++;
                continue;
            }

            $trashed = wp_trash_post((int) $post_id);
            if ($trashed === false || $trashed === null || self::is_wordpress_error($trashed)) {
                $failed++;
                continue;
            }

            try {
                self::tombstone_post((int) $post_id);
            } catch (Throwable) {
                // WordPress trash hooks also tombstone indexed rows; cleanup should
                // still succeed if storage is unavailable during the admin request.
            }
            $moved++;
        }

        if ($failed === 0) {
            self::delete_option(self::SANDBOX_DEMO_POSTS_OPTION);
        }

        return [
            'moved' => $moved,
            'failed' => $failed,
        ];
    }

    /**
     * @return array<int,array{title:string,slug:string}>
     */
    private static function legacy_sandbox_demo_post_signatures(): array
    {
        return [
            ['title' => 'FTS Sandbox: English Mice', 'slug' => 'wp-fts-sandbox-english-mice'],
            ['title' => 'FTS Sandbox: Polish Lemmatizer Demo', 'slug' => 'wp-fts-sandbox-polish-lemmatizer-demo'],
            ['title' => 'FTS Sandbox: Chinese Search N-grams', 'slug' => 'wp-fts-sandbox-chinese-search-ngrams'],
            ['title' => 'FTS Sandbox: Hindi Lemmatizer', 'slug' => 'wp-fts-sandbox-hindi-lemmatizer'],
            ['title' => 'FTS Sandbox: Spanish Buscar', 'slug' => 'wp-fts-sandbox-spanish-buscar'],
            ['title' => 'FTS Sandbox: Arabic Search', 'slug' => 'wp-fts-sandbox-arabic-search'],
            ['title' => 'FTS Sandbox: French Chercher', 'slug' => 'wp-fts-sandbox-french-chercher'],
            ['title' => 'FTS Sandbox: Bengali Lemmatizer', 'slug' => 'wp-fts-sandbox-bengali-lemmatizer'],
            ['title' => 'FTS Sandbox: Portuguese Pesquisar', 'slug' => 'wp-fts-sandbox-portuguese-pesquisar'],
            ['title' => 'FTS Sandbox: Indonesian Abadi', 'slug' => 'wp-fts-sandbox-indonesian-abadi'],
            ['title' => 'FTS Sandbox: Urdu Suffix Baseline', 'slug' => 'wp-fts-sandbox-urdu-suffix-baseline'],
        ];
    }

    /**
     * @return string[]
     */
    private static function legacy_sandbox_demo_titles(): array
    {
        return array_map(static fn(array $signature): string => $signature['title'], self::legacy_sandbox_demo_post_signatures());
    }

    private static function render_admin_compact_styles(): void
    {
        echo '<style>';
        echo '.wp-fts-admin-summary,.wp-fts-language-status{max-width:980px;margin:6px 0 10px;color:#50575e;}';
        echo '.wp-fts-language-status{margin-top:8px;}';
        echo '.wp-fts-health-copy{max-width:760px;}';
        echo '.wp-fts-health-table{max-width:760px;margin:8px 0 18px;}';
        echo '.wp-fts-health-table th{width:230px;}';
        echo '.wp-fts-sandbox-compact-controls{display:flex;flex-wrap:wrap;gap:12px 16px;align-items:flex-end;margin:8px 0 10px;}';
        echo '.wp-fts-sandbox-field{display:flex;flex-direction:column;gap:4px;margin:0;}';
        echo '.wp-fts-sandbox-field label,.wp-fts-sandbox-option-label{font-weight:600;}';
        echo '.wp-fts-sandbox-field input[type=search]{min-width:280px;}';
        echo '.wp-fts-sandbox-field input[type=number]{width:90px;}';
        echo '.wp-fts-sandbox-advanced{margin:4px 0 12px;max-width:980px;}';
        echo '.wp-fts-sandbox-advanced summary{cursor:pointer;color:#2271b1;}';
        echo '.wp-fts-sandbox-advanced-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px 18px;margin-top:10px;}';
        echo '.wp-fts-sandbox-advanced-grid fieldset{border:0;margin:0;padding:0;}';
        echo '.wp-fts-sandbox-advanced-grid p{margin:.35em 0;}';
        echo '.wp-fts-sandbox-detail-pending .spinner{float:none;margin:0 4px 0 0;vertical-align:middle;}';
        echo '.wp-fts-sandbox-detail-error{color:#8c2e0b;}';
        echo '.wp-fts-sandbox-indexed-terms code{margin-right:4px;}';
        echo '.wp-fts-debug-diagnostics{max-width:980px;margin:18px 0;}';
        echo '.wp-fts-debug-trace{margin:8px 0;}';
        echo '.wp-fts-debug-trace summary{cursor:pointer;font-weight:600;}';
        echo '.wp-fts-debug-table{margin:8px 0 14px;}';
        echo '.wp-fts-debug-table th{width:190px;}';
        echo '@media (max-width:600px){.wp-fts-health-table th{width:auto}.wp-fts-sandbox-compact-controls{display:block}.wp-fts-sandbox-field{margin:0 0 10px}.wp-fts-sandbox-field input[type=search]{min-width:0;width:100%;}}';
        echo '</style>';
    }

    private static function render_admin_orientation(): void
    {
        echo '<p class="description wp-fts-admin-summary"><strong>What this does:</strong> Full-text search (FTS) builds its own searchable index for site content. Analyzer packs add language-specific word-form matching when available.</p>';
    }

    private static function render_health_tab(): void
    {
        $settings = self::settings();
        $health = self::search_health();
        try {
            $counts = self::search_health_counts();
        } catch (Throwable $e) {
            $counts = self::empty_search_health_counts();
            self::render_sandbox_notice('error', 'Could not read index counts: ' . $e->getMessage());
        }

        echo '<h2>Search health</h2>';
        echo '<p class="wp-fts-health-copy">The plugin builds the search index in small batches so large sites stay responsive. WP-Cron continues indexing a small amount in the background. Use the button below to index the next larger batch now; large sites may need several batches, and that is intentional.</p>';

        echo '<h3>Status summary</h3>';
        echo '<table class="widefat striped wp-fts-health-table"><tbody>';
        self::render_health_status_row('Public site search', !empty($settings['replace_frontend_search']) ? 'Enabled' : 'Disabled');
        self::render_health_status_row('wp-admin Posts search', !empty($settings['replace_admin_post_search']) ? 'Enabled' : 'Disabled');
        self::render_health_status_row('Search provider compatibility', self::search_provider_compatibility_label((string) $settings['search_provider_compatibility']));
        self::render_health_status_row('Indexed post types', self::health_post_type_summary($settings['index_post_types']));
        self::render_health_status_row('Eligible content', (string) $counts['total_eligible']);
        self::render_health_status_row('Indexed', (string) $counts['indexed']);
        self::render_health_status_row('Waiting in the update queue', (string) $counts['pending']);
        self::render_health_status_row('Remaining to index', (string) $counts['remaining']);
        echo '</tbody></table>';

        echo '<h3>Latest batch</h3>';
        echo '<table class="widefat striped wp-fts-health-table"><tbody>';
        self::render_health_status_row('Last indexed content', self::last_indexed_content_summary($health));
        self::render_health_status_row('Last batch', self::last_batch_summary($health));
        self::render_health_status_row('Last batch processed', self::last_batch_processed_summary($health));
        self::render_health_status_row('Batch status', self::last_batch_status_summary($health));
        echo '</tbody></table>';

        if (!class_exists('Debug_Bar_Panel') && self::can_view_debug_diagnostics()) {
            self::render_debug_diagnostics_panel('Request diagnostics');
        }

        echo '<h3>Indexing controls</h3>';
        echo '<p class="wp-fts-health-copy">Run one safe indexing pass now. You can use it again until Remaining to index reaches 0.</p>';
        echo '<form method="post" action="' . self::esc_url(self::admin_page_url(self::ADMIN_HEALTH_TAB)) . '">';
        self::render_health_nonce_field();
        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_HEALTH_ACTION_FIELD) . '" value="' . self::esc_attr(self::ADMIN_HEALTH_MANUAL_BATCH_ACTION) . '">';
        echo '<p><button type="submit" class="button button-primary">Index the next batch now</button></p>';
        echo '</form>';
    }

    private static function render_health_status_row(string $label, string $value): void
    {
        echo '<tr><th scope="row">' . self::esc_html($label) . '</th><td>' . self::esc_html($value) . '</td></tr>';
    }

    /**
     * @param string[] $post_types
     */
    private static function health_post_type_summary(array $post_types): string
    {
        $post_types = array_values(array_filter(
            array_map(static fn(mixed $post_type): string => is_scalar($post_type) ? (string) $post_type : '', $post_types),
            static fn(string $post_type): bool => $post_type !== ''
        ));
        sort($post_types, SORT_STRING);

        return $post_types === [] ? 'No post types selected' : implode(', ', $post_types);
    }

    /**
     * @param array<string,mixed> $health
     */
    private static function last_indexed_content_summary(array $health): string
    {
        $post_id = max(0, (int) ($health['last_indexed_post_id'] ?? 0));
        if ($post_id <= 0) {
            return 'No indexed content recorded yet.';
        }

        $title = is_scalar($health['last_indexed_post_title'] ?? null) ? trim((string) $health['last_indexed_post_title']) : '';
        if ($title === '') {
            $title = self::post_title($post_id);
        }

        return sprintf('%s (ID %d)', $title !== '' ? $title : '(untitled)', $post_id);
    }

    /**
     * @param array<string,mixed> $health
     */
    private static function last_batch_summary(array $health): string
    {
        $mode = is_scalar($health['last_mode'] ?? null) ? (string) $health['last_mode'] : '';
        $time = is_scalar($health['last_run_at'] ?? null) ? (string) $health['last_run_at'] : '';
        if ($mode === '' && $time === '') {
            return 'No batch has run yet.';
        }

        $mode_label = $mode === 'cron' ? 'WP-Cron' : ($mode === 'manual' ? 'Manual' : 'Unknown');
        return $time !== '' ? sprintf('%s at %s UTC', $mode_label, $time) : $mode_label;
    }

    /**
     * @param array<string,mixed> $health
     */
    private static function last_batch_processed_summary(array $health): string
    {
        return sprintf(
            '%d total (%d waiting updates, %d remaining content)',
            max(0, (int) ($health['last_batch_processed'] ?? 0)),
            max(0, (int) ($health['last_batch_queue_processed'] ?? 0)),
            max(0, (int) ($health['last_batch_backfill_processed'] ?? 0))
        );
    }

    /**
     * @param array<string,mixed> $health
     */
    private static function last_batch_status_summary(array $health): string
    {
        if (!empty($health['lock_active'])) {
            return 'A batch appears to be running now.';
        }

        if (!empty($health['last_skipped_locked'])) {
            return 'Skipped because another indexing batch was already running. No overlap occurred.';
        }

        if (!empty($health['last_stopped_by_budget'])) {
            return 'Stopped safely because a resource limit was reached; more content remains.';
        }

        if (!empty($health['has_more'])) {
            return 'More content remains. WP-Cron will continue with small background batches.';
        }

        return is_scalar($health['last_run_at'] ?? null) && trim((string) $health['last_run_at']) !== ''
            ? 'Completed without more content reported.'
            : 'Waiting for the first batch.';
    }

    private static function render_settings_tab(): void
    {
        $settings = self::settings();
        $post_types = self::settings_post_type_choices();

        echo '<h2>Settings</h2>';
        echo '<p>Choose what goes into the index, when it updates, and where search results should use the full-text index.</p>';
        echo '<form method="post" action="' . self::esc_url(self::admin_options_url()) . '">';
        if (function_exists('settings_fields')) {
            settings_fields(self::SETTINGS_GROUP);
        } else {
            echo '<input type="hidden" name="option_page" value="' . self::esc_attr(self::SETTINGS_GROUP) . '">';
            echo '<input type="hidden" name="action" value="update">';
            self::render_settings_referer_field(self::ADMIN_SETTINGS_TAB);
        }

        self::render_settings_section_heading('What gets indexed', 'These choices control which public content types can be added to the full-text index and later returned in searches.');
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">Content types in the index</th><td>';
        foreach ($post_types as $post_type) {
            $checked = in_array($post_type, $settings['index_post_types'], true) ? ' checked="checked"' : '';
            echo '<label><input type="checkbox" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[index_post_types][]" value="' . self::esc_attr($post_type) . '"' . $checked . '> ';
            echo '<code>' . self::esc_html($post_type) . '</code></label><br>';
        }
        echo '<p class="description">Unchecked content types are left out of future indexing and are not replaced by the admin Posts search integration.</p>';
        echo '</td></tr>';
        echo '</tbody></table>';

        self::render_settings_section_heading('When the index updates', 'Decide whether edited content should be refreshed automatically or only when you rebuild the index yourself.');
        echo '<table class="form-table" role="presentation"><tbody>';
        self::render_settings_single_checkbox_row(
            'auto_index',
            'Index updates',
            'Automatically update the search index when content changes',
            $settings['auto_index'],
            'When this is disabled, the index is not refreshed automatically when posts or pages are saved, trashed, or deleted.'
        );
        echo '</tbody></table>';

        self::render_settings_section_heading('Where full-text search replaces WordPress search', 'Choose the WordPress search surfaces that should use this plugin instead of the default SQL search.');
        echo '<table class="form-table" role="presentation"><tbody>';
        self::render_settings_replacement_scope_row($settings);
        echo '</tbody></table>';

        self::render_settings_section_heading('Customer-facing search behavior', 'These defaults shape public site search result output and provide the initial Sandbox controls. The wp-admin Posts search box can use the index to find posts, but it keeps the admin list-table display and pagination controls.');
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="wp-fts-settings-snippet-length">Search result excerpt length</label></th><td>';
        echo '<input id="wp-fts-settings-snippet-length" type="number" min="' . self::esc_attr((string) self::SETTINGS_SNIPPET_MIN) . '" max="' . self::esc_attr((string) self::SETTINGS_SNIPPET_MAX) . '" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[snippet_length]" value="' . self::esc_attr((string) $settings['snippet_length']) . '">';
        echo '<p class="description">A search result excerpt is the short piece of post text shown around a matching word. Longer excerpts show more context; shorter excerpts keep result lists compact.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wp-fts-settings-match-mode">Search term matching</label></th><td>';
        echo '<select id="wp-fts-settings-match-mode" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[match_mode]">';
        self::render_option('OR', 'Match any word (broader)', $settings['match_mode']);
        self::render_option('AND', 'Require every word (stricter)', $settings['match_mode']);
        echo '</select>';
        echo '<p class="description">Match any word returns broader results. Require every word narrows results to content that matches all searched words.</p>';
        echo '</td></tr>';

        self::render_settings_checkbox_row(
            'prefix_matching',
            'Word beginnings',
            $settings['prefix_matching'],
            'Also match indexed terms that start with the searched word. Exact and lemmatizer matches still rank first. Turn this off if broad matches are too noisy.'
        );

        echo '<tr><th scope="row"><label for="wp-fts-settings-result-limit">Results per page</label></th><td>';
        echo '<input id="wp-fts-settings-result-limit" type="number" min="1" max="' . self::esc_attr((string) self::MAX_SEARCH_LIMIT) . '" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[result_limit]" value="' . self::esc_attr((string) $settings['result_limit']) . '">';
        echo '<p class="description">Controls how many results are shown on one page or search view when this default is used.</p>';
        echo '</td></tr>';

        self::render_settings_checkbox_row('highlight', 'Highlight matches in search result excerpts', $settings['highlight'], 'Highlights matching words in generated excerpts so readers can see why each result matched.');
        echo '</tbody></table>';

        self::render_settings_section_heading('Language handling', 'Language-aware matching depends on the query language, content language, and the analyzer packs available for this site.');
        echo '<table class="form-table" role="presentation"><tbody>';
        self::render_settings_language_fallback_row($settings);
        echo '</tbody></table>';

        if (function_exists('submit_button')) {
            submit_button('Save Changes');
        } else {
            echo '<p><button type="submit" class="button button-primary">Save Changes</button></p>';
        }
        echo '</form>';
    }

    private static function render_settings_section_heading(string $title, string $description): void
    {
        echo '<h3>' . self::esc_html($title) . '</h3>';
        echo '<p>' . self::esc_html($description) . '</p>';
    }

    private static function render_settings_referer_field(string $tab): void
    {
        echo '<input type="hidden" name="_wp_http_referer" value="' . self::esc_attr(self::admin_page_url($tab)) . '">';
    }

    /**
     * @param array<string,array{label:string,description:string}> $choices
     */
    private static function render_settings_radio_row(string $key, string $label, string $selected, array $choices): void
    {
        echo '<tr><th scope="row">' . self::esc_html($label) . '</th><td><fieldset>';
        echo '<legend class="screen-reader-text">' . self::esc_html($label) . '</legend>';
        foreach ($choices as $value => $choice) {
            $id = 'wp-fts-settings-' . self::sanitize_key($key . '-' . $value);
            $checked = $selected === (string) $value ? ' checked="checked"' : '';
            echo '<p><label for="' . self::esc_attr($id) . '">';
            echo '<input id="' . self::esc_attr($id) . '" type="radio" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[' . self::esc_attr($key) . ']" value="' . self::esc_attr((string) $value) . '"' . $checked . '> ';
            echo self::esc_html($choice['label']);
            echo '</label><br><span class="description">' . self::esc_html($choice['description']) . '</span></p>';
        }
        echo '</fieldset></td></tr>';
    }

    /**
     * @param array<string,mixed> $settings
     */
    private static function render_settings_replacement_scope_row(array $settings): void
    {
        echo '<tr><th scope="row">Search replacement</th><td><fieldset>';
        echo '<legend class="screen-reader-text">Search replacement</legend>';

        echo '<input type="hidden" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[replace_frontend_search]" value="0">';
        echo '<p><label for="wp-fts-settings-replace-frontend-search">';
        echo '<input id="wp-fts-settings-replace-frontend-search" type="checkbox" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[replace_frontend_search]" value="1"' . (!empty($settings['replace_frontend_search']) ? ' checked="checked"' : '') . '> ';
        echo 'Use full-text search on the public site';
        echo '</label><br><span class="description">Visitor-facing searches use the full-text index instead of WordPress default search.</span></p>';

        echo '<input type="hidden" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[replace_admin_post_search]" value="0">';
        echo '<p><label for="wp-fts-settings-replace-admin-post-search">';
        echo '<input id="wp-fts-settings-replace-admin-post-search" type="checkbox" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[replace_admin_post_search]" value="1"' . (!empty($settings['replace_admin_post_search']) ? ' checked="checked"' : '') . '> ';
        echo 'Use full-text search in wp-admin post search';
        echo '</label><br><span class="description">The search box on the wp-admin Posts list uses the index for matching posts.</span></p>';

        echo '<p class="description">These choices only decide where WordPress search is replaced. The public site is what visitors see; wp-admin post search is the Posts list used by editors and administrators.</p>';
        echo '</fieldset></td></tr>';

        self::render_settings_radio_row(
            'search_provider_compatibility',
            'Search provider compatibility',
            (string) $settings['search_provider_compatibility'],
            [
                self::SEARCH_PROVIDER_COMPATIBILITY_PREFER_FTS => [
                    'label' => 'Prefer Language FTS',
                    'description' => 'Eligible searches use Language FTS even when another search provider has already answered.',
                ],
                self::SEARCH_PROVIDER_COMPATIBILITY_RESPECT_EXISTING => [
                    'label' => 'Keep another search provider\'s results when it has already answered',
                    'description' => 'Use this to coexist with Jetpack Search, SearchWP, Relevanssi, or a site-specific search integration on the same search surfaces.',
                ],
            ]
        );
    }

    /**
     * @param array<string,mixed> $settings
     */
    private static function render_settings_language_fallback_row(array $settings): void
    {
        self::render_settings_radio_row(
            'language_fallback',
            'Language fallback',
            !empty($settings['language_fallback']) ? '1' : '0',
            [
                '1' => [
                    'label' => 'Also try the current WordPress site language when needed',
                    'description' => 'If the query language is unsupported or produces no matches, the plugin can also try the current site language. This language is read from WordPress each time, not copied into this plugin setting, and it may broaden results.',
                ],
                '0' => [
                    'label' => 'Use only the detected or selected query language',
                    'description' => 'Use this when trying the site language would make results feel too broad or surprising.',
                ],
            ]
        );

        $language = self::site_language();
        $support = self::language_support_details($language, false);
        echo '<tr><th scope="row">Current site language</th><td>';
        echo '<p>' . self::esc_html(self::sandbox_language_display($language)) . '</p>';
        echo '<p class="description">' . self::esc_html('Runtime search status - ' . $support['label'] . ': ' . $support['reason']) . '</p>';
        echo '<p class="description">This value is read dynamically from WordPress. Change it on the <a href="' . self::esc_url(self::admin_options_general_url()) . '">WordPress General Settings page</a>.</p>';
        echo '</td></tr>';
    }

    private static function render_settings_single_checkbox_row(string $key, string $row_label, string $checkbox_label, bool $enabled, string $description): void
    {
        $id = 'wp-fts-settings-' . self::sanitize_key($key);
        echo '<tr><th scope="row">' . self::esc_html($row_label) . '</th><td>';
        echo '<input type="hidden" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[' . self::esc_attr($key) . ']" value="0">';
        echo '<label for="' . self::esc_attr($id) . '">';
        echo '<input id="' . self::esc_attr($id) . '" type="checkbox" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[' . self::esc_attr($key) . ']" value="1"' . ($enabled ? ' checked="checked"' : '') . '> ';
        echo self::esc_html($checkbox_label);
        echo '</label>';
        echo '<p class="description">' . self::esc_html($description) . '</p>';
        echo '</td></tr>';
    }

    private static function render_settings_checkbox_row(string $key, string $label, bool $enabled, string $description): void
    {
        echo '<tr><th scope="row">' . self::esc_html($label) . '</th><td>';
        echo '<input type="hidden" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[' . self::esc_attr($key) . ']" value="0">';
        echo '<label><input type="checkbox" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[' . self::esc_attr($key) . ']" value="1"' . ($enabled ? ' checked="checked"' : '') . '> Enabled</label>';
        echo '<p class="description">' . self::esc_html($description) . '</p>';
        echo '</td></tr>';
    }

    private static function render_admin_sandbox_tab(): void
    {
        $state = self::admin_sandbox_state(false);
        foreach ($state['messages'] as $message) {
            self::render_sandbox_notice($message[0], $message[1]);
        }

        self::render_sandbox_search_form(
            $state['query'],
            $state['selected_language'],
            $state['controls'],
            $state['search_submitted'],
            $state['show_indexed_terms']
        );

        if ($state['search_submitted']) {
            self::render_sandbox_results(
                $state['results'],
                $state['query'],
                $state['selected_language'],
                $state['controls'],
                $state['show_indexed_terms']
            );
        }
    }

    private static function render_indexed_content_tab(): void
    {
        $messages = [];
        $show_indexed_terms = self::sandbox_indexed_terms_debug_enabled();
        try {
            $indexed_posts = self::sandbox_indexed_posts_page(self::sandbox_indexed_posts_page_number(), $show_indexed_terms);
        } catch (Throwable $e) {
            $messages[] = ['error', 'Could not read indexed posts: ' . $e->getMessage()];
            $indexed_posts = self::empty_sandbox_indexed_posts_page(self::sandbox_indexed_posts_page_number());
        }

        echo '<h2>Indexed content</h2>';
        echo '<p>This view shows what is currently stored in the full-text index. Use it to confirm which posts are searchable, which language partition they are in, and whether matching uses full morphology or a conservative fallback.</p>';
        foreach ($messages as $message) {
            self::render_sandbox_notice($message[0], $message[1]);
        }
        self::render_sandbox_indexed_posts_table(
            $indexed_posts,
            self::sandbox_search_query(),
            self::sandbox_selected_language(),
            self::sandbox_search_submitted(),
            $show_indexed_terms
        );
    }

    private static function render_analyzer_packs_tab(): void
    {
        echo '<h2>Analyzer packs</h2>';
        echo '<p>Analyzer packs add language-specific tokenization or word-form matching. Runtime packs affect real site searches; sandbox packs are bundled so Sandbox searches have realistic language behavior.</p>';
        echo '<h3>Runtime analyzer packs</h3>';
        self::render_analyzer_pack_statuses(self::runtime_analyzer_pack_statuses());
        echo '<h3>Sandbox analyzer packs</h3>';
        self::render_analyzer_pack_statuses(self::sandbox_demo_analyzer_pack_statuses());
    }

    /**
     * @return array{
     *   messages:array<int,array{0:string,1:string}>,
     *   query:string,
     *   selected_language:string,
     *   search_submitted:bool,
     *   controls:array<string,mixed>,
     *   show_indexed_terms:bool,
     *   results:array{requested_lang:string,query_lang:string,total:int,results:array<int,array{post_id:int,title:string,score:float,language:string,snippet:string}>}
     * }
     */
    private static function admin_sandbox_state(bool $include_indexed_posts): array
    {
        $messages = [];

        $search_submitted = self::sandbox_search_submitted();
        $query = self::sandbox_search_query();
        $selected_language = self::sandbox_selected_language();
        $controls = self::sandbox_search_controls($search_submitted);
        $show_indexed_terms = self::sandbox_indexed_terms_debug_enabled();

        $results = self::empty_sandbox_search_results($selected_language);
        if ($search_submitted) {
            if ($query === '') {
                $messages[] = ['error', 'Enter a search query before running the sandbox search.'];
                self::debug_record_bailout(
                    'sandbox search',
                    '',
                    'Empty search query.',
                    self::debug_effective_settings(self::settings(), $controls)
                );
            } else {
                try {
                    $results = self::sandbox_search_results($query, $selected_language, $controls);
                    $messages[] = ['info', sprintf('Search returned %d result(s).', count($results['results']))];
                } catch (Throwable $e) {
                    $messages[] = ['error', 'Could not run the sandbox search: ' . $e->getMessage()];
                }
            }
        }

        return [
            'messages' => $messages,
            'query' => $query,
            'selected_language' => $selected_language,
            'search_submitted' => $search_submitted,
            'controls' => $controls,
            'show_indexed_terms' => $show_indexed_terms,
            'results' => $results,
        ];
    }

    /**
     * @return array{
     *   mode:string,
     *   limit:int,
     *   snippet_length:int,
     *   highlight:bool,
     *   prefix_matching:bool,
     *   language_fallback:bool,
     *   post_types:string[],
     *   post_statuses:string[],
     *   date_after:string,
     *   date_before:string
     * }
     */
    private static function sandbox_search_controls(bool $search_submitted): array
    {
        return self::sandbox_search_controls_from_source($_GET, $search_submitted);
    }

    /**
     * @param array<string,mixed> $source
     * @return array{
     *   mode:string,
     *   limit:int,
     *   snippet_length:int,
     *   highlight:bool,
     *   prefix_matching:bool,
     *   language_fallback:bool,
     *   post_types:string[],
     *   post_statuses:string[],
     *   date_after:string,
     *   date_before:string
     * }
     */
    private static function sandbox_search_controls_from_source(array $source, bool $search_submitted): array
    {
        $settings = self::settings();
        $mode = strtoupper(self::request_text_value($source, self::ADMIN_MODE_FIELD, 10));
        if (!in_array($mode, ['OR', 'AND'], true)) {
            $mode = $settings['match_mode'];
        }

        return [
            'mode' => $mode,
            'limit' => self::clamp_int(self::request_text_value($source, self::ADMIN_LIMIT_FIELD, 8) ?: $settings['result_limit'], 1, self::MAX_SEARCH_LIMIT),
            'snippet_length' => self::clamp_int(self::request_text_value($source, self::ADMIN_SNIPPET_LENGTH_FIELD, 8) ?: $settings['snippet_length'], self::SETTINGS_SNIPPET_MIN, self::SETTINGS_SNIPPET_MAX),
            'highlight' => self::request_bool_value($source, self::ADMIN_HIGHLIGHT_FIELD, $settings['highlight'], $search_submitted),
            'prefix_matching' => self::request_bool_value($source, self::ADMIN_PREFIX_MATCHING_FIELD, $settings['prefix_matching'], $search_submitted),
            'language_fallback' => self::request_bool_value($source, self::ADMIN_LANGUAGE_FALLBACK_FIELD, $settings['language_fallback'], $search_submitted),
            'post_types' => self::request_list_value($source, self::ADMIN_POST_TYPE_FIELD, self::settings_post_type_choices(), $settings['index_post_types']),
            'post_statuses' => self::request_list_value($source, self::ADMIN_POST_STATUS_FIELD, self::sandbox_post_status_choices(), self::sandbox_post_status_choices()),
            'date_after' => self::sanitize_date_filter(self::request_text_value($source, self::ADMIN_DATE_AFTER_FIELD, 20)),
            'date_before' => self::sanitize_date_filter(self::request_text_value($source, self::ADMIN_DATE_BEFORE_FIELD, 20)),
        ];
    }

    /**
     * Sanitize the persisted `wp_fts_settings` option.
     *
     * @param mixed $value Raw option value from Settings API.
     * @return array<string,mixed>
     */
    public static function sanitize_settings(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $defaults = self::default_settings();
        $allowed_post_types = self::settings_post_type_choices();

        $post_types = self::sanitize_post_type_list($value['index_post_types'] ?? [], $allowed_post_types);
        if ($post_types === []) {
            $post_types = $defaults['index_post_types'];
        }

        $mode = strtoupper(is_scalar($value['match_mode'] ?? null) ? (string) $value['match_mode'] : '');
        if (!in_array($mode, ['OR', 'AND'], true)) {
            $mode = $defaults['match_mode'];
        }

        [$replace_frontend_search, $replace_admin_post_search] = self::sanitize_replacement_scope_settings($value, $defaults);
        $search_provider_compatibility = self::sanitize_search_provider_compatibility(
            $value['search_provider_compatibility'] ?? null,
            (string) $defaults['search_provider_compatibility']
        );

        return [
            'index_post_types' => $post_types,
            'auto_index' => array_key_exists('auto_index', $value) ? self::truthy_admin_value($value['auto_index']) : $defaults['auto_index'],
            'replace_frontend_search' => $replace_frontend_search,
            'replace_admin_post_search' => $replace_admin_post_search,
            'search_provider_compatibility' => $search_provider_compatibility,
            'highlight' => array_key_exists('highlight', $value) ? self::truthy_admin_value($value['highlight']) : $defaults['highlight'],
            'snippet_length' => self::clamp_int($value['snippet_length'] ?? $defaults['snippet_length'], self::SETTINGS_SNIPPET_MIN, self::SETTINGS_SNIPPET_MAX),
            'match_mode' => $mode,
            'prefix_matching' => array_key_exists('prefix_matching', $value) ? self::truthy_admin_value($value['prefix_matching']) : $defaults['prefix_matching'],
            'result_limit' => self::clamp_int($value['result_limit'] ?? $defaults['result_limit'], 1, self::MAX_SEARCH_LIMIT),
            'language_fallback' => array_key_exists('language_fallback', $value) ? self::truthy_admin_value($value['language_fallback']) : $defaults['language_fallback'],
        ];
    }

    private static function sanitize_search_provider_compatibility(mixed $value, string $default): string
    {
        $mode = is_scalar($value) ? self::sanitize_key((string) $value) : '';

        return in_array($mode, self::search_provider_compatibility_modes(), true) ? $mode : $default;
    }

    /**
     * @return string[]
     */
    private static function search_provider_compatibility_modes(): array
    {
        return [
            self::SEARCH_PROVIDER_COMPATIBILITY_PREFER_FTS,
            self::SEARCH_PROVIDER_COMPATIBILITY_RESPECT_EXISTING,
        ];
    }

    private static function search_provider_compatibility_label(string $mode): string
    {
        if ($mode === self::SEARCH_PROVIDER_COMPATIBILITY_RESPECT_EXISTING) {
            return 'Keep another search provider\'s results';
        }

        return 'Prefer Language FTS';
    }

    private static function search_provider_compatibility_debug_value(string $mode): string
    {
        if ($mode === self::SEARCH_PROVIDER_COMPATIBILITY_RESPECT_EXISTING) {
            return 'respect_existing_provider';
        }

        return 'prefer_language_fts';
    }

    /**
     * @param array<string,mixed> $value
     * @param array<string,mixed> $defaults
     * @return array{0:bool,1:bool}
     */
    private static function sanitize_replacement_scope_settings(array $value, array $defaults): array
    {
        $scope = is_scalar($value['replace_search_scope'] ?? null)
            ? self::sanitize_key((string) $value['replace_search_scope'])
            : '';

        if ($scope !== '') {
            if ($scope === 'frontend-admin') {
                return [true, true];
            }
            if ($scope === 'frontend') {
                return [true, false];
            }
            if ($scope === 'admin') {
                return [false, true];
            }
            if ($scope === 'none') {
                return [false, false];
            }

            return [
                (bool) ($defaults['replace_frontend_search'] ?? false),
                (bool) ($defaults['replace_admin_post_search'] ?? false),
            ];
        }

        return [
            array_key_exists('replace_frontend_search', $value) ? self::truthy_admin_value($value['replace_frontend_search']) : (bool) $defaults['replace_frontend_search'],
            array_key_exists('replace_admin_post_search', $value) ? self::truthy_admin_value($value['replace_admin_post_search']) : (bool) $defaults['replace_admin_post_search'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function default_settings(): array
    {
        return self::DEFAULT_SETTINGS;
    }

    /**
     * @return array{
     *   index_post_types:string[],
     *   auto_index:bool,
     *   replace_frontend_search:bool,
     *   replace_admin_post_search:bool,
     *   search_provider_compatibility:string,
     *   highlight:bool,
     *   snippet_length:int,
     *   match_mode:string,
     *   prefix_matching:bool,
     *   result_limit:int,
     *   language_fallback:bool
     * }
     */
    private static function settings(): array
    {
        $stored = self::get_option(self::SETTINGS_OPTION, []);
        $settings = self::sanitize_settings($stored);
        $defaults = self::default_settings();

        return array_replace($defaults, $settings);
    }

    /**
     * @return string[]
     */
    private static function settings_post_type_choices(): array
    {
        $choices = self::public_searchable_post_types();
        foreach (self::DEFAULT_SETTINGS['index_post_types'] as $post_type) {
            if (!in_array($post_type, $choices, true) && self::is_public_searchable_post_type($post_type)) {
                $choices[] = $post_type;
            }
        }
        $choices = array_values(array_unique($choices));
        sort($choices, SORT_STRING);

        return $choices;
    }

    /**
     * @param mixed $value
     * @param string[] $allowed
     * @return string[]
     */
    private static function sanitize_post_type_list(mixed $value, array $allowed): array
    {
        $allowed_map = array_fill_keys($allowed, true);
        $post_types = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $post_type = self::sanitize_key((string) $item);
            if ($post_type !== '' && isset($allowed_map[$post_type])) {
                $post_types[$post_type] = true;
            }
        }

        $post_types = array_keys($post_types);
        sort($post_types, SORT_STRING);

        return $post_types;
    }

    private static function truthy_admin_value(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0;
        }
        if (is_scalar($value)) {
            return !in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'no', 'off'], true);
        }

        return false;
    }

    /**
     * @return string[]
     */
    private static function language_meta_box_post_types(): array
    {
        $types = [];
        if (function_exists('get_post_types')) {
            $raw_types = get_post_types(['public' => true], 'names');
            if (is_array($raw_types)) {
                foreach ($raw_types as $key => $value) {
                    $type = is_scalar($value) ? (string) $value : (is_scalar($key) ? (string) $key : '');
                    if ($type !== '') {
                        $types[$type] = true;
                    }
                }
            }
        }

        if ($types === []) {
            $types = ['post' => true, 'page' => true];
        }

        foreach (array_keys($types) as $type) {
            if (function_exists('get_post_type_object')) {
                $post_type = get_post_type_object((string) $type);
                if (!is_object($post_type) || (isset($post_type->public) && !$post_type->public) || (isset($post_type->exclude_from_search) && $post_type->exclude_from_search)) {
                    unset($types[$type]);
                }
            }
        }

        $result = array_keys($types);
        sort($result, SORT_STRING);

        return $result;
    }

    private static function verify_post_language_nonce(): bool
    {
        $nonce = self::request_text_value($_POST, self::POST_LANGUAGE_NONCE_FIELD, 200);
        if ($nonce === '' || !function_exists('wp_verify_nonce')) {
            return false;
        }

        return wp_verify_nonce($nonce, self::POST_LANGUAGE_NONCE_ACTION) !== false;
    }

    private static function post_language_override(int $post_id): ?string
    {
        if ($post_id <= 0 || !function_exists('get_post_meta')) {
            return null;
        }

        $raw = get_post_meta($post_id, self::LANGUAGE_META_KEY, true);
        if (!is_scalar($raw)) {
            return null;
        }

        $language = self::sanitize_post_language_override((string) $raw);

        return $language !== '' ? $language : null;
    }

    private static function set_post_language_override(int $post_id, string $language): void
    {
        if (function_exists('update_post_meta')) {
            update_post_meta($post_id, self::LANGUAGE_META_KEY, $language);
        }
    }

    private static function delete_post_language_override(int $post_id): void
    {
        if (function_exists('delete_post_meta')) {
            delete_post_meta($post_id, self::LANGUAGE_META_KEY);
        }
    }

    private static function sanitize_post_language_override(string $language): string
    {
        $language = self::sanitize_key($language);
        if ($language === '' || $language === 'auto') {
            return '';
        }

        return array_key_exists($language, self::sandbox_language_labels()) ? $language : '';
    }

    /**
     * Prepare WordPress post language/default options before calling the FTS
     * component indexer.
     *
     * @param array<string,mixed> $opts Caller-supplied indexing options.
     * @return array<string,mixed>
     */
    public static function prepare_post_index_options(object $post, array $opts = []): array
    {
        $options = $opts;
        $site_language = self::site_language();
        $options['default_lang'] ??= $site_language;

        if (WP_FTS_TermNamespace::language_from_options($options, null, ['lang', 'language', 'primary_lang', 'document_lang']) === null) {
            $metadata_language = self::wordpress_post_language($post);
            if ($metadata_language !== null) {
                $options['lang'] = $metadata_language;
                $options['document_lang'] = $metadata_language;
            }
        }

        return $options;
    }

    /**
     * Resolve deliberate per-post language metadata from WordPress integrations.
     */
    private static function wordpress_post_language(object $post): ?string
    {
        $post_id = isset($post->ID) ? (int) $post->ID : 0;
        $override = self::post_language_override($post_id);
        if ($override !== null) {
            return WP_FTS_TermNamespace::canonicalize_lang($override);
        }

        if ($post_id > 0 && function_exists('pll_get_post_language')) {
            $language = pll_get_post_language($post_id, 'locale');
            if (is_scalar($language) && trim((string) $language) !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang((string) $language);
            }
        }

        if ($post_id > 0 && function_exists('has_filter') && function_exists('apply_filters') && has_filter('wpml_post_language_details')) {
            $details = apply_filters('wpml_post_language_details', null, $post_id);
            if (is_array($details)) {
                $language = $details['locale'] ?? $details['language_code'] ?? null;
                if (is_scalar($language) && trim((string) $language) !== '') {
                    return WP_FTS_TermNamespace::canonicalize_lang((string) $language);
                }
            }
            if (is_object($details)) {
                $language = $details->locale ?? $details->language_code ?? null;
                if (is_scalar($language) && trim((string) $language) !== '') {
                    return WP_FTS_TermNamespace::canonicalize_lang((string) $language);
                }
            }
        }

        return null;
    }

    /**
     * Resolve document language for analyzer callbacks from post_id options.
     *
     * @param array<string,mixed> $options
     */
    private static function wordpress_document_language_from_options(array $options): ?string
    {
        $post_id = isset($options['post_id']) && is_scalar($options['post_id']) ? (int) $options['post_id'] : 0;

        return $post_id > 0 ? self::wordpress_post_language((object) ['ID' => $post_id]) : null;
    }

    /**
     * Resolve the current WordPress query language from multilingual plugins.
     */
    private static function wordpress_query_language(): ?string
    {
        if (function_exists('pll_current_language')) {
            $language = pll_current_language('locale');
            if (is_scalar($language) && trim((string) $language) !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang((string) $language);
            }
        }

        if (function_exists('apply_filters')) {
            $language = apply_filters('wpml_current_language', null);
            if (is_scalar($language) && trim((string) $language) !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang((string) $language);
            }
        }

        return null;
    }

    /**
     * Parse a nonce-protected POST action for the sandbox forms.
     */
    private static function sandbox_post_action(): string
    {
        $action = self::sanitize_key(self::request_text_value($_POST, self::ADMIN_ACTION_FIELD, 40));

        if ($action === self::ADMIN_CLEANUP_LEGACY_DEMO_ACTION || in_array($action, self::LEGACY_DEMO_CREATION_ACTIONS, true)) {
            return $action;
        }

        return '';
    }

    /**
     * Detect any submitted sandbox POST action, including unsupported values.
     */
    private static function sandbox_post_action_submitted(): bool
    {
        return self::request_text_value($_POST, self::ADMIN_ACTION_FIELD, 40) !== '';
    }

    /**
     * Verify the sandbox nonce without triggering WordPress' default die path.
     */
    private static function verify_sandbox_nonce(): bool
    {
        $nonce = self::request_text_value($_POST, self::ADMIN_NONCE_FIELD, 200);
        if ($nonce === '' || !function_exists('wp_verify_nonce')) {
            return false;
        }

        return wp_verify_nonce($nonce, self::ADMIN_NONCE_ACTION) !== false;
    }

    /**
     * @param array<string,mixed> $source
     */
    private static function verify_sandbox_details_nonce(array $source): bool
    {
        $nonce = self::request_text_value($source, self::ADMIN_DETAILS_NONCE_FIELD, 200);
        if ($nonce === '' || !function_exists('wp_verify_nonce')) {
            return false;
        }

        return wp_verify_nonce($nonce, self::ADMIN_DETAILS_NONCE_ACTION) !== false;
    }

    private static function health_post_action(): string
    {
        $action = self::sanitize_key(self::request_text_value($_POST, self::ADMIN_HEALTH_ACTION_FIELD, 40));

        return $action === self::ADMIN_HEALTH_MANUAL_BATCH_ACTION ? $action : '';
    }

    private static function health_post_action_submitted(): bool
    {
        return self::request_text_value($_POST, self::ADMIN_HEALTH_ACTION_FIELD, 40) !== '';
    }

    private static function verify_health_nonce(): bool
    {
        $nonce = self::request_text_value($_POST, self::ADMIN_HEALTH_NONCE_FIELD, 200);
        if ($nonce === '' || !function_exists('wp_verify_nonce')) {
            return false;
        }

        return wp_verify_nonce($nonce, self::ADMIN_HEALTH_NONCE_ACTION) !== false;
    }

    /**
     * @return int[]
     */
    private static function sandbox_demo_post_ids(): array
    {
        $raw = self::get_option(self::SANDBOX_DEMO_POSTS_OPTION, []);
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        $seen = [];
        foreach ($raw as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0 && !isset($seen[$post_id])) {
                $ids[] = $post_id;
                $seen[$post_id] = true;
            }
        }

        return $ids;
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $doc
     */
    private static function sandbox_indexed_language(array $metadata, array $doc, string $fallback): string
    {
        foreach ([$metadata['language'] ?? null, $metadata['lang'] ?? null, $doc['primary_lang'] ?? null, $doc['lang'] ?? null] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang((string) $candidate, $fallback);
            }
        }

        return WP_FTS_TermNamespace::canonicalize_lang($fallback);
    }

    /**
     * Read the GET search state.
     */
    private static function sandbox_search_submitted(): bool
    {
        return self::request_text_value($_GET, self::ADMIN_SEARCH_FIELD, 20) !== ''
            || self::request_text_value($_GET, self::ADMIN_QUERY_FIELD, 200) !== '';
    }

    /**
     * Read and sanitize the sandbox query.
     */
    private static function sandbox_search_query(): string
    {
        return self::sandbox_search_query_from_source($_GET);
    }

    /**
     * @param array<string,mixed> $source
     */
    private static function sandbox_search_query_from_source(array $source): string
    {
        return self::request_text_value($source, self::ADMIN_QUERY_FIELD, 200);
    }

    /**
     * Read and allowlist the sandbox query language.
     */
    private static function sandbox_selected_language(): string
    {
        return self::sandbox_selected_language_from_source($_GET);
    }

    /**
     * @param array<string,mixed> $source
     */
    private static function sandbox_selected_language_from_source(array $source): string
    {
        $language = self::sanitize_key(self::request_text_value($source, self::ADMIN_LANG_FIELD, 20));

        return array_key_exists($language, self::sandbox_query_language_labels()) ? $language : 'auto';
    }

    /**
     * @return array<string,string>
     */
    private static function sandbox_language_labels(): array
    {
        return [
            'en' => 'English',
            'zh' => 'Chinese (Mandarin)',
            'hi' => 'Hindi',
            'es' => 'Spanish',
            'ar' => 'Arabic',
            'fr' => 'French',
            'bn' => 'Bengali',
            'pt' => 'Portuguese',
            'id' => 'Indonesian',
            'ur' => 'Urdu',
            'pl' => 'Polish',
            'de' => 'German',
            'ru' => 'Russian',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'te' => 'Telugu',
            'tr' => 'Turkish',
            'it' => 'Italian',
            'fa' => 'Persian',
            'uk' => 'Ukrainian',
            'nl' => 'Dutch',
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function sandbox_query_language_labels(): array
    {
        return ['auto' => 'Automatic', 'site' => 'Site language'] + self::sandbox_language_labels();
    }

    /**
     * @return string[]
     */
    private static function sandbox_auto_search_languages(): array
    {
        return array_values(array_filter(
            array_keys(self::sandbox_language_labels()),
            static fn(string $language): bool => $language !== 'auto' && $language !== 'site'
        ));
    }

    /**
     * @param array<int,array{post_id:int,title:string,score:float,language:string,snippet:string}> $results
     */
    private static function sandbox_resolved_query_language(string $selected_language, string $searcher_language, array $results): string
    {
        if ($selected_language !== 'auto') {
            return $searcher_language !== '' ? $searcher_language : $selected_language;
        }

        $result_languages = [];
        foreach ($results as $row) {
            $candidate = trim((string) ($row['language'] ?? ''));
            if ($candidate === '') {
                continue;
            }
            $language = WP_FTS_TermNamespace::canonicalize_lang($candidate);
            if ($language !== '' && array_key_exists($language, self::sandbox_language_labels()) && $language !== 'auto') {
                $result_languages[$language] = true;
            }
        }

        if (count($result_languages) === 1) {
            return (string) array_key_first($result_languages);
        }

        return 'auto';
    }

    /**
     * @return array{requested_lang:string,query_lang:string,total:int,results:array<int,array{post_id:int,title:string,score:float,language:string,snippet:string}>}
     */
    private static function empty_sandbox_search_results(string $selected_language): array
    {
        return [
            'requested_lang' => $selected_language,
            'query_lang' => '',
            'total' => 0,
            'results' => [],
        ];
    }

    /**
     * @param array<string,mixed> $controls
     * @return array{requested_lang:string,query_lang:string,total:int,results:array<int,array{post_id:int,title:string,score:float,language:string,snippet:string}>}
     */
    private static function sandbox_search_results(string $query, string $selected_language, array $controls = [], bool $include_snippets = false): array
    {
        $trace_started = microtime(true);
        $settings = self::settings();
        $limit = self::clamp_int($controls['limit'] ?? $settings['result_limit'], 1, self::MAX_SEARCH_LIMIT);
        $mode = strtoupper((string) ($controls['mode'] ?? $settings['match_mode']));
        if (!in_array($mode, ['OR', 'AND'], true)) {
            $mode = $settings['match_mode'];
        }

        $trace_id = self::debug_start_trace(
            $include_snippets ? 'sandbox result details' : 'sandbox search',
            $query,
            self::debug_effective_settings($settings, $controls)
        );
        $prep_started = microtime(true);
        $storage = self::storage(false);
        $search_options = [
            'mode' => $mode,
            'limit' => $limit,
            'include_total' => true,
            'include_metadata' => true,
            'include_snippets' => $include_snippets,
            'highlight' => (bool) ($controls['highlight'] ?? $settings['highlight']),
            'prefix_matching' => (bool) ($controls['prefix_matching'] ?? $settings['prefix_matching']),
            'snippet_length' => self::clamp_int($controls['snippet_length'] ?? $settings['snippet_length'], self::SETTINGS_SNIPPET_MIN, self::SETTINGS_SNIPPET_MAX),
            'explain' => $trace_id > 0,
            'explain_result_matches' => $include_snippets,
        ];
        foreach (['post_types' => 'post_type', 'post_statuses' => 'post_status'] as $control_key => $search_key) {
            if (isset($controls[$control_key]) && is_array($controls[$control_key]) && $controls[$control_key] !== []) {
                $search_options[$search_key] = array_values(array_filter(
                    array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : '', $controls[$control_key]),
                    static fn(string $value): bool => trim($value) !== ''
                ));
            }
        }
        foreach (['date_after', 'date_before'] as $date_key) {
            if (isset($controls[$date_key]) && is_scalar($controls[$date_key]) && trim((string) $controls[$date_key]) !== '') {
                $search_options[$date_key] = (string) $controls[$date_key];
            }
        }
        if (!empty($controls['language_fallback'])) {
            $search_options['language_fallback'] = true;
            $search_options['fallback_languages'] = self::site_fallback_languages();
        }

        if ($selected_language === 'site') {
            $site_language = self::site_language();
            $search_options['lang'] = $site_language;
            $search_options['query_lang'] = $site_language;
        } elseif ($selected_language !== 'auto') {
            $search_options['lang'] = $selected_language;
            $search_options['query_lang'] = $selected_language;
        } else {
            $search_options['languages'] = self::sandbox_auto_search_languages();
        }
        self::debug_add_timing($trace_id, 'analyzer/query preparation', $prep_started);

        $visible = [];
        $seen_post_ids = [];
        $total = 0;
        $query_language = '';
        $batch_limit = self::visibility_refill_batch_limit($limit);
        foreach ([self::sandbox_analyzer(), self::runtime_analyzer()] as $analyzer) {
            if (count($visible) >= $limit) {
                break;
            }

            $searcher = new WP_FTS_Searcher($storage, $analyzer);
            $offset = 0;
            while (count($visible) < $limit && $offset < self::VISIBILITY_REFILL_MAX_SCAN) {
                $search_options['limit'] = min($batch_limit, self::VISIBILITY_REFILL_MAX_SCAN - $offset);
                $search_options['offset'] = $offset;
                $search_started = microtime(true);
                $payload = $searcher->search($query, $search_options);
                self::debug_add_timing($trace_id, 'storage/search', $search_started);
                self::debug_set_search_explain($trace_id, $payload['explain'] ?? null);
                $rows = is_array($payload['results'] ?? null) ? $payload['results'] : [];
                self::debug_add_count($trace_id, 'search_batches');
                self::debug_add_count($trace_id, 'candidate_rows', count($rows));
                $total = is_numeric($payload['total'] ?? null) ? max($total, (int) $payload['total']) : $total;
                if ($query_language === '' && is_scalar($payload['query_lang'] ?? null) && trim((string) $payload['query_lang']) !== '') {
                    $query_language = (string) $payload['query_lang'];
                }
                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $post_id = (int) ($row['doc_id'] ?? $row['post_id'] ?? 0);
                    if ($post_id <= 0 || isset($seen_post_ids[$post_id])) {
                        continue;
                    }
                    $seen_post_ids[$post_id] = true;
                    self::debug_add_count($trace_id, 'result_ids_considered');
                    $visibility_started = microtime(true);
                    $visible_post = self::can_read_post_result($post_id);
                    self::debug_add_timing($trace_id, 'visibility filtering', $visibility_started);
                    if (!$visible_post) {
                        continue;
                    }
                    if ($include_snippets && isset($row['snippet']) && is_scalar($row['snippet']) && trim((string) $row['snippet']) !== '') {
                        self::debug_add_count($trace_id, 'snippets_generated');
                    }
                    $visible[] = self::sandbox_result_row($row, $storage, $post_id);
                    if (count($visible) >= $limit) {
                        break;
                    }
                }

                if (count($rows) < $search_options['limit']) {
                    break;
                }
                $offset += $search_options['limit'];
            }
        }
        $query_language = self::sandbox_resolved_query_language($selected_language, $query_language, $visible);
        self::debug_set_counts($trace_id, [
            'result_ids_returned' => count($visible),
            'visible_results' => count($visible),
        ]);
        self::debug_set_query_language(
            $trace_id,
            $query_language !== 'auto' ? $query_language : '',
            is_array($search_options['fallback_languages'] ?? null) ? $search_options['fallback_languages'] : [],
            true
        );
        self::debug_add_notes($trace_id, ['FTS sandbox search ran.']);
        self::debug_add_timing($trace_id, 'total', $trace_started);
        self::debug_finish_trace($trace_id, 'ran');

        return [
            'requested_lang' => $selected_language,
            'query_lang' => $query_language,
            'total' => $total,
            'results' => $visible,
        ];
    }

    private static function sandbox_analyzer(): WP_FTS_Analyzer
    {
        return self::sandbox_demo_analyzer();
    }

    /**
     * Build the analyzer used only by the admin/Playground demo corpus.
     */
    public static function sandbox_demo_analyzer(): WP_FTS_Analyzer
    {
        return new WP_FTS_Analyzer(self::sandbox_demo_analyzer_options());
    }

    /**
     * Return demo-only analyzer options with bundled local packs preconfigured.
     *
     * @return array<string,mixed>
     */
    public static function sandbox_demo_analyzer_options(): array
    {
        return self::with_wordpress_analyzer_options(
            self::sanitize_runtime_analyzer_options(self::raw_sandbox_demo_analyzer_options())
        );
    }

    /**
     * Use the same analyzer for runtime indexing and product searches.
     */
    public static function runtime_analyzer(): WP_FTS_Analyzer
    {
        return new WP_FTS_Analyzer(self::runtime_analyzer_options());
    }

    /**
     * Build analyzer options for WordPress runtime indexing, REST, admin, and WP-CLI.
     *
     * @return array<string,mixed>
     */
    public static function runtime_analyzer_options(): array
    {
        return self::with_wordpress_analyzer_options(
            self::sanitize_runtime_analyzer_options(self::raw_runtime_analyzer_options())
        );
    }

    /**
     * Add WordPress-only language and HTML parser integrations to component
     * analyzer options.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function with_wordpress_analyzer_options(array $options): array
    {
        $site_language = self::site_language();
        $options['default_lang'] ??= $site_language;
        $options['document_language_resolver'] ??= static function (array $analyzer_options): ?string {
            return self::wordpress_document_language_from_options($analyzer_options);
        };
        $options['query_language_resolver'] ??= static function (array $analyzer_options): ?string {
            return self::wordpress_query_language();
        };

        if (class_exists('WP_HTML_Processor')) {
            $options['html_processor_factory'] ??= static function (string $html): mixed {
                return self::create_wp_html_processor($html);
            };
        }

        return $options;
    }

    /**
     * Enable a local lemma-pack manifest in the stored runtime analyzer option.
     *
     * Existing analyzer option keys and other language entries are preserved.
     * If the same language is already present in the higher-precedence
     * `lemma_packs_by_lang` alias, that entry is updated too so the new manifest
     * is the effective runtime pack.
     *
     * @return array<string,mixed> Stored analyzer option value after the merge.
     */
    public static function set_runtime_lemma_pack_option(string $language, string $manifestPath): array
    {
        $language = WP_FTS_TermNamespace::canonicalize_lang($language);
        $manifestPath = trim($manifestPath);
        if ($language === '' || $manifestPath === '') {
            throw new InvalidArgumentException('Runtime lemma pack option requires a language and manifest path.');
        }

        $stored = self::get_option(self::ANALYZER_OPTIONS_OPTION, []);
        $options = is_array($stored) ? $stored : [];

        if (!isset($options['lemmatizer_packs_by_lang']) || !is_array($options['lemmatizer_packs_by_lang'])) {
            $options['lemmatizer_packs_by_lang'] = [];
        }
        $options['lemmatizer_packs_by_lang'] = self::set_language_pack_map_entry(
            $options['lemmatizer_packs_by_lang'],
            $language,
            $manifestPath,
            true
        );

        if (isset($options['lemma_packs_by_lang']) && is_array($options['lemma_packs_by_lang'])) {
            $options['lemma_packs_by_lang'] = self::set_language_pack_map_entry(
                $options['lemma_packs_by_lang'],
                $language,
                $manifestPath,
                false
            );
        }

        self::set_option(self::ANALYZER_OPTIONS_OPTION, $options);

        return $options;
    }

    /**
     * Report configured runtime lemma packs for admin diagnostics.
     *
     * @return array<int,array{language:string,kind:string,status:string,pack_id:string,fixture_only:bool,reason:string}>
     */
    public static function runtime_analyzer_pack_statuses(): array
    {
        if (self::$runtime_analyzer_pack_statuses_cache === null) {
            self::$runtime_analyzer_pack_statuses_cache = self::analyzer_pack_statuses(self::raw_runtime_analyzer_options());
        }

        return self::$runtime_analyzer_pack_statuses_cache;
    }

    /**
     * Report configured sandbox/demo lemma packs for admin diagnostics.
     *
     * @return array<int,array{language:string,kind:string,status:string,pack_id:string,fixture_only:bool,reason:string}>
     */
    public static function sandbox_demo_analyzer_pack_statuses(): array
    {
        if (self::$sandbox_demo_analyzer_pack_statuses_cache === null) {
            self::$sandbox_demo_analyzer_pack_statuses_cache = self::analyzer_pack_statuses(self::raw_sandbox_demo_analyzer_options());
        }

        return self::$sandbox_demo_analyzer_pack_statuses_cache;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<int,array{language:string,kind:string,status:string,pack_id:string,fixture_only:bool,reason:string}>
     */
    private static function analyzer_pack_statuses(array $options): array
    {
        $statuses = [];
        foreach (self::runtime_lemma_pack_options_by_language($options) as $language => $option) {
            if (self::lemma_pack_option_is_disabled($option)) {
                $statuses[] = [
                    'language' => $language,
                    'kind' => 'lemmatizer',
                    'status' => 'disabled',
                    'pack_id' => '',
                    'fixture_only' => false,
                    'reason' => 'Disabled by configuration.',
                ];
                continue;
            }

            $pack = WP_FTS_LanguageLemmaPack::from_pack_option(
                $option,
                $language,
                self::default_lemma_pack_manifest_for_language($language)
            );
            if ($pack === null) {
                $statuses[] = [
                    'language' => $language,
                    'kind' => 'lemmatizer',
                    'status' => 'ignored',
                    'pack_id' => '',
                    'fixture_only' => false,
                    'reason' => 'Missing, invalid, or language-mismatched manifest.',
                ];
                continue;
            }

            $statuses[] = [
                'language' => $language,
                'kind' => 'lemmatizer',
                'status' => 'active',
                'pack_id' => $pack->pack_id(),
                'fixture_only' => $pack->is_fixture_only(),
                'reason' => '',
            ];
        }
        foreach (self::runtime_segmenter_pack_options_by_language($options) as $language => $option) {
            if (self::lemma_pack_option_is_disabled($option)) {
                $statuses[] = [
                    'language' => $language,
                    'kind' => 'tokenizer',
                    'status' => 'disabled',
                    'pack_id' => '',
                    'fixture_only' => false,
                    'reason' => 'Disabled by configuration.',
                ];
                continue;
            }

            $pack = WP_FTS_ChineseJiebaSegmenter::from_pack_option($option, $language);
            if ($pack === null) {
                $statuses[] = [
                    'language' => $language,
                    'kind' => 'tokenizer',
                    'status' => 'fallback',
                    'pack_id' => '',
                    'fixture_only' => false,
                    'reason' => 'Jieba dictionary source is missing, invalid, or hash-mismatched; using fallback CJK n-grams.',
                ];
                continue;
            }

            $statuses[] = [
                'language' => $language,
                'kind' => 'tokenizer',
                'status' => 'active',
                'pack_id' => $pack->pack_id(),
                'fixture_only' => $pack->is_fixture_only(),
                'reason' => '',
            ];
        }
        usort(
            $statuses,
            static fn(array $a, array $b): int => strcmp((string) $a['language'] . (string) $a['kind'], (string) $b['language'] . (string) $b['kind'])
        );

        return $statuses;
    }

    /**
     * Read bundled defaults, the WordPress option, and the analyzer options filter.
     *
     * @return array<string,mixed>
     */
    private static function raw_runtime_analyzer_options(): array
    {
        return self::raw_analyzer_options_with_bundled_packs(
            self::bundled_runtime_lemma_packs_by_lang(),
            self::bundled_runtime_segmenter_packs_by_lang()
        );
    }

    /**
     * Read bundled sandbox/demo defaults, the WordPress option, and the analyzer
     * options filter.
     *
     * @return array<string,mixed>
     */
    private static function raw_sandbox_demo_analyzer_options(): array
    {
        return self::raw_analyzer_options_with_bundled_packs(
            self::bundled_sandbox_demo_lemma_packs_by_lang(),
            self::bundled_sandbox_demo_segmenter_packs_by_lang()
        );
    }

    /**
     * @param array<string,bool|string> $bundled_lemma_packs
     * @param array<string,bool|string> $bundled_segmenter_packs
     * @return array<string,mixed>
     */
    private static function raw_analyzer_options_with_bundled_packs(array $bundled_lemma_packs, array $bundled_segmenter_packs): array
    {
        $options = [
            'lemmatizer_packs_by_lang' => $bundled_lemma_packs,
            'segmenter_packs_by_lang' => $bundled_segmenter_packs,
        ];

        $stored = self::get_option(self::ANALYZER_OPTIONS_OPTION, []);
        if (is_array($stored)) {
            $options = self::merge_runtime_analyzer_options($options, $stored);
        }

        if (function_exists('apply_filters')) {
            $base = $options;
            $filtered = apply_filters(self::ANALYZER_OPTIONS_FILTER, $options);
            if (is_array($filtered)) {
                $options = self::merge_runtime_analyzer_options(
                    $base,
                    self::runtime_analyzer_filter_override_layer($base, $filtered)
                );
            }
        }

        return $options;
    }

    /**
     * Keep only the analyzer options supported by the WordPress configuration path.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function sanitize_runtime_analyzer_options(array $options): array
    {
        $lemmaPacks = self::runtime_lemma_pack_options_by_language($options);
        $segmenterPacks = self::runtime_segmenter_pack_options_by_language($options);
        if ($lemmaPacks === [] && $segmenterPacks === []) {
            return [];
        }

        $sanitized = [];
        if ($lemmaPacks !== []) {
            $sanitized['lemmatizer_packs_by_lang'] = $lemmaPacks;
        }
        if ($segmenterPacks !== []) {
            $sanitized['segmenter_packs_by_lang'] = $segmenterPacks;
        }

        return $sanitized;
    }

    /**
     * Merge nested analyzer option maps without inventing support for arbitrary options.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private static function merge_runtime_analyzer_options(array $base, array $override): array
    {
        $override = self::normalize_runtime_analyzer_option_layer($override);

        foreach ($override as $key => $value) {
            if (
                in_array($key, ['lemma_packs_by_lang', 'lemmatizer_packs_by_lang', 'segmenter_packs_by_lang', 'cjk_segmenter_packs_by_lang', 'cjk_tokenizer_packs_by_lang', 'tokenizer_packs_by_lang'], true)
                && is_array($value)
            ) {
                $current = isset($base[$key]) && is_array($base[$key]) ? $base[$key] : [];
                $base[$key] = array_replace($current, $value);
                continue;
            }

            if (in_array($key, ['polish_lemma_pack', 'polish_lemmatizer_pack'], true)) {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Return only analyzer option values changed by a filter callback.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $filtered
     * @return array<string,mixed>
     */
    private static function runtime_analyzer_filter_override_layer(array $base, array $filtered): array
    {
        $override = [];

        foreach (['lemma_packs_by_lang', 'lemmatizer_packs_by_lang', 'segmenter_packs_by_lang', 'cjk_segmenter_packs_by_lang', 'cjk_tokenizer_packs_by_lang', 'tokenizer_packs_by_lang'] as $key) {
            if (!isset($filtered[$key]) || !is_array($filtered[$key])) {
                continue;
            }

            $baseMap = isset($base[$key]) && is_array($base[$key]) ? $base[$key] : [];
            foreach ($filtered[$key] as $language => $option) {
                if (!array_key_exists($language, $baseMap) || $baseMap[$language] !== $option) {
                    $override[$key][$language] = $option;
                }
            }
        }

        foreach (['polish_lemma_pack', 'polish_lemmatizer_pack'] as $key) {
            if (array_key_exists($key, $filtered) && (!array_key_exists($key, $base) || $base[$key] !== $filtered[$key])) {
                $override[$key] = $filtered[$key];
            }
        }

        return $override;
    }

    /**
     * Normalize one precedence layer before it is merged into earlier layers.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function normalize_runtime_analyzer_option_layer(array $options): array
    {
        $normalized = [];

        foreach (['lemmatizer_packs_by_lang', 'lemma_packs_by_lang', 'tokenizer_packs_by_lang', 'cjk_tokenizer_packs_by_lang', 'cjk_segmenter_packs_by_lang', 'segmenter_packs_by_lang'] as $key) {
            if (isset($options[$key]) && is_array($options[$key])) {
                $normalized[$key] = self::normalize_runtime_analyzer_language_map($options[$key]);
            }
        }

        if (!self::runtime_analyzer_layer_has_generic_polish_pack($normalized)) {
            if (array_key_exists('polish_lemmatizer_pack', $options)) {
                $normalized['lemmatizer_packs_by_lang']['pl'] = $options['polish_lemmatizer_pack'];
            }
            if (array_key_exists('polish_lemma_pack', $options)) {
                $normalized['lemma_packs_by_lang']['pl'] = $options['polish_lemma_pack'];
            }
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $packs
     * @return array<string,mixed>
     */
    private static function normalize_runtime_analyzer_language_map(array $packs): array
    {
        $normalized = [];
        foreach ($packs as $language => $option) {
            if (!is_scalar($language) || trim((string) $language) === '') {
                continue;
            }

            $normalized[WP_FTS_TermNamespace::canonicalize_lang((string) $language)] = $option;
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $packs
     * @return array<string,mixed>
     */
    private static function set_language_pack_map_entry(array $packs, string $language, string $manifestPath, bool $addCanonical): array
    {
        $updated = false;
        foreach ($packs as $entryLanguage => $option) {
            if (!is_scalar($entryLanguage) || trim((string) $entryLanguage) === '') {
                continue;
            }
            if (WP_FTS_TermNamespace::canonicalize_lang((string) $entryLanguage) !== $language) {
                continue;
            }

            $packs[$entryLanguage] = $manifestPath;
            $updated = true;
        }

        if ($addCanonical && !$updated) {
            $packs[$language] = $manifestPath;
        }

        return $packs;
    }

    /**
     * @param array<string,mixed> $options
     */
    private static function runtime_analyzer_layer_has_generic_polish_pack(array $options): bool
    {
        foreach (['lemma_packs_by_lang', 'lemmatizer_packs_by_lang'] as $key) {
            if (isset($options[$key]) && is_array($options[$key]) && array_key_exists('pl', $options[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize generic and legacy pack option aliases to a canonical language map.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function runtime_lemma_pack_options_by_language(array $options): array
    {
        $packs = [];
        if (isset($options['lemmatizer_packs_by_lang']) && is_array($options['lemmatizer_packs_by_lang'])) {
            $packs = $options['lemmatizer_packs_by_lang'];
        }
        if (isset($options['lemma_packs_by_lang']) && is_array($options['lemma_packs_by_lang'])) {
            $packs = array_replace($packs, $options['lemma_packs_by_lang']);
        }
        if (
            !array_key_exists('pl', $packs)
            && (array_key_exists('polish_lemma_pack', $options) || array_key_exists('polish_lemmatizer_pack', $options))
        ) {
            $packs['pl'] = $options['polish_lemma_pack'] ?? $options['polish_lemmatizer_pack'] ?? false;
        }

        $normalized = [];
        foreach ($packs as $language => $option) {
            if (!is_scalar($language) || trim((string) $language) === '') {
                continue;
            }

            $language = WP_FTS_TermNamespace::canonicalize_lang((string) $language);
            if (!self::is_supported_lemma_pack_option($option)) {
                continue;
            }

            $normalized[$language] = $option;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * Normalize segmenter pack option aliases to a canonical language map.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function runtime_segmenter_pack_options_by_language(array $options): array
    {
        $packs = [];
        foreach (['tokenizer_packs_by_lang', 'cjk_tokenizer_packs_by_lang', 'cjk_segmenter_packs_by_lang', 'segmenter_packs_by_lang'] as $key) {
            if (isset($options[$key]) && is_array($options[$key])) {
                $packs = array_replace($packs, $options[$key]);
            }
        }

        $normalized = [];
        foreach ($packs as $language => $option) {
            if (!is_scalar($language) || trim((string) $language) === '') {
                continue;
            }

            $language = WP_FTS_TermNamespace::canonicalize_lang((string) $language);
            if (!self::is_supported_lemma_pack_option($option)) {
                continue;
            }

            $normalized[$language] = $option;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @return array<string,bool|string>
     */
    private static function bundled_runtime_lemma_packs_by_lang(): array
    {
        return [
            'pl' => self::sandbox_polish_lemmatizer_pack(),
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private static function bundled_runtime_segmenter_packs_by_lang(): array
    {
        return [];
    }

    /**
     * @return array<string,bool|string>
     */
    private static function bundled_sandbox_demo_lemma_packs_by_lang(): array
    {
        $packs = self::bundled_runtime_lemma_packs_by_lang();
        if (WP_FTS_AnalyzerPackValidator::gzip_available()) {
            $packs = array_replace(
                $packs,
                WP_FTS_AnalyzerPackValidator::bundled_unimorph_top_language_pack_manifests()
            );
        }

        ksort($packs, SORT_STRING);

        return $packs;
    }

    /**
     * @return array<string,bool|string>
     */
    private static function bundled_sandbox_demo_segmenter_packs_by_lang(): array
    {
        return [
            'zh' => true,
        ];
    }

    private static function sandbox_polish_lemmatizer_pack(): bool|string
    {
        $manifestPath = WP_FTS_AnalyzerPackValidator::default_polish_playground_full_manifest();
        if (is_file($manifestPath) && WP_FTS_AnalyzerPackValidator::gzip_available()) {
            return $manifestPath;
        }

        return true;
    }

    private static function default_lemma_pack_manifest_for_language(string $language): ?string
    {
        $parts = explode('-', str_replace('_', '-', $language));
        $base = strtolower((string) ($parts[0] ?? $language));

        return $base === 'pl' ? WP_FTS_AnalyzerPackValidator::default_polish_fixture_manifest() : null;
    }

    private static function is_supported_lemma_pack_option(mixed $option): bool
    {
        return $option === null || is_bool($option) || is_string($option) || is_array($option);
    }

    private static function lemma_pack_option_is_disabled(mixed $option): bool
    {
        if ($option === false || $option === null) {
            return true;
        }

        if (is_string($option)) {
            return in_array(strtolower(trim($option)), ['', '0', 'false', 'no', 'off'], true);
        }

        return false;
    }

    /**
     * @param array{
     *   mode:string,
     *   limit:int,
     *   snippet_length:int,
     *   highlight:bool,
     *   prefix_matching:bool,
     *   language_fallback:bool,
     *   post_types:string[],
     *   post_statuses:string[],
     *   date_after:string,
     *   date_before:string
     * } $controls
     */
    private static function render_sandbox_search_form(string $query, string $selected_language, array $controls, bool $search_submitted, bool $show_indexed_terms): void
    {
        echo '<h2>Sandbox</h2>';
        echo '<p>Try a query against the same index and saved settings this plugin uses elsewhere. Changes here affect only this test search.</p>';
        echo '<form method="get" action="' . self::esc_url(self::admin_options_general_url()) . '">';
        echo '<input type="hidden" name="page" value="' . self::esc_attr(self::ADMIN_PAGE_SLUG) . '">';
        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_TAB_FIELD) . '" value="' . self::esc_attr(self::ADMIN_SANDBOX_TAB) . '">';
        echo '<div class="wp-fts-sandbox-compact-controls">';

        echo '<p class="wp-fts-sandbox-field"><label for="wp-fts-sandbox-query">Query text</label>';
        echo '<input id="wp-fts-sandbox-query" type="search" class="regular-text" name="' . self::esc_attr(self::ADMIN_QUERY_FIELD) . '" value="' . self::esc_attr($query) . '">';
        echo '<span class="description">Type the words a visitor or editor would search for.</span></p>';

        echo '<p class="wp-fts-sandbox-field"><label for="wp-fts-sandbox-lang">Query language</label>';
        echo '<select id="wp-fts-sandbox-lang" name="' . self::esc_attr(self::ADMIN_LANG_FIELD) . '">';
        foreach (self::sandbox_query_language_labels() as $language => $label) {
            $selected = $selected_language === $language ? ' selected="selected"' : '';
            echo '<option value="' . self::esc_attr($language) . '"' . $selected . '>' . self::esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<span class="description">Automatic lets the analyzer infer the query language. Site language: ' . self::esc_html(self::sandbox_language_display(self::site_language())) . '.</span></p>';

        echo '<p class="wp-fts-sandbox-field"><label for="wp-fts-sandbox-mode">Search term matching</label>';
        echo '<select id="wp-fts-sandbox-mode" name="' . self::esc_attr(self::ADMIN_MODE_FIELD) . '">';
        self::render_option('OR', 'Match any word (broader)', $controls['mode']);
        self::render_option('AND', 'Require every word (stricter)', $controls['mode']);
        echo '</select>';
        echo '<span class="description">Match any word is broader. Require every word shows only posts that match all searched words.</span></p>';

        echo '<p class="wp-fts-sandbox-field"><button type="submit" class="button button-primary" name="' . self::esc_attr(self::ADMIN_SEARCH_FIELD) . '" value="1">Search</button></p>';

        echo '</div>';

        echo '<details class="wp-fts-sandbox-advanced">';
        echo '<summary>Filters and display options</summary>';
        echo '<div class="wp-fts-sandbox-advanced-grid">';

        echo '<p class="wp-fts-sandbox-field"><label for="wp-fts-sandbox-limit">Results per page</label>';
        echo '<input id="wp-fts-sandbox-limit" type="number" min="1" max="' . self::esc_attr((string) self::MAX_SEARCH_LIMIT) . '" name="' . self::esc_attr(self::ADMIN_LIMIT_FIELD) . '" value="' . self::esc_attr((string) $controls['limit']) . '">';
        echo '<span class="description">Controls how many results are shown in this Sandbox search view.</span></p>';

        echo '<p class="wp-fts-sandbox-field"><label for="wp-fts-sandbox-snippet-length">Search result excerpt length</label>';
        echo '<input id="wp-fts-sandbox-snippet-length" type="number" min="' . self::esc_attr((string) self::SETTINGS_SNIPPET_MIN) . '" max="' . self::esc_attr((string) self::SETTINGS_SNIPPET_MAX) . '" name="' . self::esc_attr(self::ADMIN_SNIPPET_LENGTH_FIELD) . '" value="' . self::esc_attr((string) $controls['snippet_length']) . '">';
        echo '<span class="description">A search result excerpt is the short piece of post text shown around a matching word.</span></p>';

        echo '<fieldset><legend class="wp-fts-sandbox-option-label">Highlight matches</legend>';
        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_HIGHLIGHT_FIELD) . '" value="0">';
        echo '<label><input type="checkbox" name="' . self::esc_attr(self::ADMIN_HIGHLIGHT_FIELD) . '" value="1"' . ($controls['highlight'] ? ' checked="checked"' : '') . '> On</label>';
        echo '<p class="description">Highlights matching words inside generated excerpts.</p>';
        echo '</fieldset>';

        echo '<fieldset><legend class="wp-fts-sandbox-option-label">Indexed terms</legend>';
        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_SHOW_INDEXED_TERMS_FIELD) . '" value="0">';
        echo '<label><input type="checkbox" name="' . self::esc_attr(self::ADMIN_SHOW_INDEXED_TERMS_FIELD) . '" value="1"' . ($show_indexed_terms ? ' checked="checked"' : '') . '> Show stored indexed terms</label>';
        echo '<p class="description">Loads stored term diagnostics after result rows render.</p>';
        echo '</fieldset>';

        echo '<fieldset><legend class="wp-fts-sandbox-option-label">Word beginnings</legend>';
        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_PREFIX_MATCHING_FIELD) . '" value="0">';
        echo '<label><input type="checkbox" name="' . self::esc_attr(self::ADMIN_PREFIX_MATCHING_FIELD) . '" value="1"' . ($controls['prefix_matching'] ? ' checked="checked"' : '') . '> On</label>';
        echo '<p class="description">Also matches indexed terms that start with the searched word.</p>';
        echo '</fieldset>';

        echo '<fieldset><legend class="wp-fts-sandbox-option-label">Language fallback</legend>';
        echo '<p><label><input type="radio" name="' . self::esc_attr(self::ADMIN_LANGUAGE_FALLBACK_FIELD) . '" value="1"' . ($controls['language_fallback'] ? ' checked="checked"' : '') . '> Also try the current WordPress site language when needed</label></p>';
        echo '<p><label><input type="radio" name="' . self::esc_attr(self::ADMIN_LANGUAGE_FALLBACK_FIELD) . '" value="0"' . (!$controls['language_fallback'] ? ' checked="checked"' : '') . '> Search only the selected query language</label></p>';
        echo '<p class="description">If the selected query language is unsupported or produces no matches, the Sandbox can also try the current site language. That language is read dynamically from WordPress and may broaden the result set.</p>';
        echo '</fieldset>';

        echo '<fieldset><legend class="wp-fts-sandbox-option-label">Post types to include</legend>';
        foreach (self::settings_post_type_choices() as $post_type) {
            $checked = in_array($post_type, $controls['post_types'], true) ? ' checked="checked"' : '';
            echo '<label><input type="checkbox" name="' . self::esc_attr(self::ADMIN_POST_TYPE_FIELD) . '[]" value="' . self::esc_attr($post_type) . '"' . $checked . '> <code>' . self::esc_html($post_type) . '</code></label><br>';
        }
        echo '<p class="description">Narrows results to selected indexed content types.</p>';
        echo '</fieldset>';

        echo '<fieldset><legend class="wp-fts-sandbox-option-label">Post statuses to include</legend>';
        foreach (self::sandbox_post_status_choices() as $status) {
            $checked = in_array($status, $controls['post_statuses'], true) ? ' checked="checked"' : '';
            echo '<label><input type="checkbox" name="' . self::esc_attr(self::ADMIN_POST_STATUS_FIELD) . '[]" value="' . self::esc_attr($status) . '"' . $checked . '> <code>' . self::esc_html($status) . '</code></label><br>';
        }
        echo '<p class="description">Draft, pending, future, and private rows still respect normal WordPress read permissions.</p>';
        echo '</fieldset>';

        echo '<p class="wp-fts-sandbox-field"><label for="wp-fts-sandbox-date-after">Date after</label>';
        echo '<input id="wp-fts-sandbox-date-after" type="date" name="' . self::esc_attr(self::ADMIN_DATE_AFTER_FIELD) . '" value="' . self::esc_attr($controls['date_after']) . '">';
        echo '<span class="description">Shows only posts dated on or after this date.</span></p>';

        echo '<p class="wp-fts-sandbox-field"><label for="wp-fts-sandbox-date-before">Date before</label>';
        echo '<input id="wp-fts-sandbox-date-before" type="date" name="' . self::esc_attr(self::ADMIN_DATE_BEFORE_FIELD) . '" value="' . self::esc_attr($controls['date_before']) . '">';
        echo '<span class="description">Shows only posts dated on or before this date.</span></p>';

        echo '</div>';
        echo '</details>';
        echo '</form>';
    }

    /**
     * @param array<int,array{language:string,kind:string,status:string,pack_id:string,fixture_only:bool,reason:string}> $statuses
     */
    private static function render_analyzer_pack_statuses(array $statuses): void
    {
        if ($statuses === []) {
            echo '<p>No lemma packs are configured. Languages without an active pack use their built-in stemmer or tokenizer behavior.</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th scope="col">Language</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Pack</th><th scope="col">Scope</th></tr></thead>';
        echo '<tbody>';
        foreach ($statuses as $status) {
            $scope = $status['status'] === 'active'
                ? ($status['fixture_only'] ? 'Fixture' : 'Full local pack')
                : $status['reason'];
            $pack = $status['pack_id'] !== '' ? $status['pack_id'] : '-';
            echo '<tr>';
            echo '<td>' . self::esc_html(self::sandbox_language_display($status['language'])) . '</td>';
            echo '<td>' . self::esc_html(ucfirst($status['kind'])) . '</td>';
            echo '<td>' . self::esc_html(ucfirst($status['status'])) . '</td>';
            echo '<td><code>' . self::esc_html($pack) . '</code></td>';
            echo '<td>' . self::esc_html($scope) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * @param array{page:int,per_page:int,total:int,total_pages:int,rows:array<int,array{post_id:int,title:string,post_type:string,post_status:string,language:string,length:int,indexed_terms:string[],indexed_terms_more:bool,preview:string}>} $page
     */
    private static function render_sandbox_indexed_posts_table(array $page, string $query, string $selected_language, bool $search_submitted, bool $show_indexed_terms): void
    {
        if ($page['total'] <= 0) {
            echo '<p>No indexed posts are available yet.</p>';
            return;
        }

        $start = (($page['page'] - 1) * $page['per_page']) + 1;
        $end = min($page['total'], $start + count($page['rows']) - 1);
        echo '<p>Showing ' . self::esc_html((string) $start) . '-' . self::esc_html((string) $end) . ' of ' . self::esc_html((string) $page['total']) . ' indexed post(s).</p>';
        if ($show_indexed_terms) {
            echo '<p><a class="button" href="' . self::esc_url(self::sandbox_indexed_posts_page_url($page['page'], $query, $selected_language, $search_submitted, false)) . '">Hide indexed terms</a></p>';
        } else {
            echo '<p><a class="button" href="' . self::esc_url(self::sandbox_indexed_posts_page_url($page['page'], $query, $selected_language, $search_submitted, true)) . '">Show indexed terms</a> <span class="description">Loads stored terms for the visible rows.</span></p>';
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th scope="col">Post ID</th><th scope="col">Title</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Language</th><th scope="col">Indexed length</th><th scope="col">Indexed terms</th><th scope="col">Content preview</th></tr></thead>';
        echo '<tbody>';
        foreach ($page['rows'] as $row) {
            echo '<tr>';
            echo '<td>' . self::esc_html((string) $row['post_id']) . '</td>';
            echo '<td>' . self::esc_html($row['title']) . '</td>';
            echo '<td><code>' . self::esc_html($row['post_type']) . '</code></td>';
            echo '<td><code>' . self::esc_html($row['post_status']) . '</code></td>';
            echo '<td>' . self::esc_html($row['language']) . '</td>';
            echo '<td>' . self::esc_html((string) $row['length']) . '</td>';
            echo '<td>';
            self::render_sandbox_indexed_terms($row['indexed_terms'], $row['indexed_terms_more'], $show_indexed_terms);
            echo '</td>';
            echo '<td>' . self::esc_html($row['preview']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        if ($page['total_pages'] <= 1) {
            return;
        }

        echo '<p class="tablenav-pages">';
        echo '<span class="displaying-num">Page ' . self::esc_html((string) $page['page']) . ' of ' . self::esc_html((string) $page['total_pages']) . '</span> ';
        if ($page['page'] > 1) {
            echo '<a class="button" href="' . self::esc_url(self::sandbox_indexed_posts_page_url($page['page'] - 1, $query, $selected_language, $search_submitted, $show_indexed_terms)) . '">Previous</a> ';
        }
        if ($page['page'] < $page['total_pages']) {
            echo '<a class="button" href="' . self::esc_url(self::sandbox_indexed_posts_page_url($page['page'] + 1, $query, $selected_language, $search_submitted, $show_indexed_terms)) . '">Next</a>';
        }
        echo '</p>';
    }

    /**
     * @param array{requested_lang:string,query_lang:string,total:int,results:array<int,array{post_id:int,title:string,score:float,language:string,snippet:string}>} $results
     */
    private static function render_sandbox_results(array $results, string $query, string $selected_language, array $controls, bool $show_indexed_terms): void
    {
        echo '<h2>Results</h2>';
        echo '<p>Requested query language: <code>' . self::esc_html($results['requested_lang']) . '</code>. ';
        echo 'Resolved query language: <code>' . self::esc_html($results['query_lang'] !== '' ? $results['query_lang'] : 'unknown') . '</code>.</p>';
        if ($results['results'] === []) {
            echo '<p>No results matched the current index.</p>';
            return;
        }

        echo '<table class="widefat striped wp-fts-sandbox-results" data-wp-fts-sandbox-details="1"';
        echo ' data-ajax-url="' . self::esc_attr(self::admin_ajax_url()) . '"';
        echo ' data-action="' . self::esc_attr(self::ADMIN_AJAX_SANDBOX_DETAILS_ACTION) . '"';
        echo ' data-nonce="' . self::esc_attr(self::create_admin_nonce(self::ADMIN_DETAILS_NONCE_ACTION)) . '"';
        echo ' data-query="' . self::esc_attr($query) . '"';
        echo ' data-lang="' . self::esc_attr($selected_language) . '"';
        echo ' data-mode="' . self::esc_attr((string) $controls['mode']) . '"';
        echo ' data-limit="' . self::esc_attr((string) $controls['limit']) . '"';
        echo ' data-snippet-length="' . self::esc_attr((string) $controls['snippet_length']) . '"';
        echo ' data-highlight="' . self::esc_attr(!empty($controls['highlight']) ? '1' : '0') . '"';
        echo ' data-prefix-matching="' . self::esc_attr(!empty($controls['prefix_matching']) ? '1' : '0') . '"';
        echo ' data-language-fallback="' . self::esc_attr(!empty($controls['language_fallback']) ? '1' : '0') . '"';
        echo ' data-post-types="' . self::esc_attr(implode(',', array_map('strval', $controls['post_types'] ?? []))) . '"';
        echo ' data-post-statuses="' . self::esc_attr(implode(',', array_map('strval', $controls['post_statuses'] ?? []))) . '"';
        echo ' data-date-after="' . self::esc_attr((string) ($controls['date_after'] ?? '')) . '"';
        echo ' data-date-before="' . self::esc_attr((string) ($controls['date_before'] ?? '')) . '"';
        echo ' data-show-indexed-terms="' . self::esc_attr($show_indexed_terms ? '1' : '0') . '">';
        echo '<thead><tr><th scope="col">Post ID</th><th scope="col">Title</th><th scope="col">Score</th><th scope="col">Language</th><th scope="col">Search result excerpt</th>';
        if ($show_indexed_terms) {
            echo '<th scope="col">Indexed terms</th>';
        }
        echo '</tr></thead>';
        echo '<tbody>';
        foreach ($results['results'] as $row) {
            $post_id = max(0, (int) $row['post_id']);
            echo '<tr>';
            echo '<td>' . self::esc_html((string) $post_id) . '</td>';
            echo '<td>' . self::esc_html($row['title']) . '</td>';
            echo '<td>' . self::esc_html(number_format($row['score'], 6, '.', '')) . '</td>';
            echo '<td><code>' . self::esc_html($row['language']) . '</code></td>';
            echo '<td class="wp-fts-sandbox-detail-cell wp-fts-sandbox-snippet-cell wp-fts-sandbox-detail-pending" data-wp-fts-detail="snippet" data-post-id="' . self::esc_attr((string) $post_id) . '">';
            echo '<span class="spinner is-active" aria-hidden="true"></span> <span class="description">Loading excerpt...</span>';
            echo '</td>';
            if ($show_indexed_terms) {
                echo '<td class="wp-fts-sandbox-detail-cell wp-fts-sandbox-terms-cell wp-fts-sandbox-detail-pending" data-wp-fts-detail="terms" data-post-id="' . self::esc_attr((string) $post_id) . '">';
                echo '<span class="spinner is-active" aria-hidden="true"></span> <span class="description">Loading indexed terms...</span>';
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        self::render_sandbox_result_details_script();
    }

    private static function render_sandbox_result_details_script(): void
    {
        echo <<<'JS'
<script>
(function() {
    'use strict';

    var table = document.querySelector('.wp-fts-sandbox-results[data-wp-fts-sandbox-details="1"]');
    if (!table || !window.fetch || !window.FormData) {
        return;
    }

    var snippetCells = Array.prototype.slice.call(table.querySelectorAll('[data-wp-fts-detail="snippet"]'));
    var termCells = Array.prototype.slice.call(table.querySelectorAll('[data-wp-fts-detail="terms"]'));
    var detailCells = snippetCells.concat(termCells);
    if (detailCells.length === 0) {
        return;
    }

    var postIds = [];
    detailCells.forEach(function(cell) {
        var postId = cell.getAttribute('data-post-id') || '';
        if (postId !== '' && postIds.indexOf(postId) === -1) {
            postIds.push(postId);
        }
    });
    if (postIds.length === 0) {
        return;
    }

    function splitData(name) {
        return (table.getAttribute('data-' + name) || '').split(',').filter(function(value) {
            return value !== '';
        });
    }

    function appendValue(formData, field, attr) {
        formData.append(field, table.getAttribute(attr) || '');
    }

    function setCellMessage(cell, message, className) {
        cell.classList.remove('wp-fts-sandbox-detail-pending');
        if (className) {
            cell.classList.add(className);
        }
        cell.textContent = message;
    }

    function fail(message) {
        detailCells.forEach(function(cell) {
            setCellMessage(cell, message, 'wp-fts-sandbox-detail-error');
        });
    }

    var formData = new FormData();
    appendValue(formData, 'action', 'data-action');
    appendValue(formData, 'wp_fts_sandbox_details_nonce', 'data-nonce');
    appendValue(formData, 'wp_fts_sandbox_query', 'data-query');
    appendValue(formData, 'wp_fts_sandbox_lang', 'data-lang');
    appendValue(formData, 'wp_fts_sandbox_mode', 'data-mode');
    appendValue(formData, 'wp_fts_sandbox_limit', 'data-limit');
    appendValue(formData, 'wp_fts_sandbox_snippet_length', 'data-snippet-length');
    appendValue(formData, 'wp_fts_sandbox_highlight', 'data-highlight');
    appendValue(formData, 'wp_fts_sandbox_prefix_matching', 'data-prefix-matching');
    appendValue(formData, 'wp_fts_sandbox_language_fallback', 'data-language-fallback');
    appendValue(formData, 'wp_fts_sandbox_date_after', 'data-date-after');
    appendValue(formData, 'wp_fts_sandbox_date_before', 'data-date-before');
    appendValue(formData, 'wp_fts_sandbox_show_indexed_terms', 'data-show-indexed-terms');
    formData.append('wp_fts_sandbox_search', '1');
    formData.append('wp_fts_sandbox_post_ids', postIds.join(','));
    splitData('post-types').forEach(function(postType) {
        formData.append('wp_fts_sandbox_post_type[]', postType);
    });
    splitData('post-statuses').forEach(function(postStatus) {
        formData.append('wp_fts_sandbox_post_status[]', postStatus);
    });

    fetch(table.getAttribute('data-ajax-url') || '', {
        method: 'POST',
        credentials: 'same-origin',
        body: formData
    }).then(function(response) {
        return response.json();
    }).then(function(payload) {
        if (!payload || payload.success !== true || !payload.data || !payload.data.rows) {
            throw new Error('bad_response');
        }

        var rows = payload.data.rows;
        snippetCells.forEach(function(cell) {
            var postId = cell.getAttribute('data-post-id') || '';
            var row = rows[postId] || null;
            cell.classList.remove('wp-fts-sandbox-detail-pending');
            if (!row) {
                setCellMessage(cell, 'Could not load excerpt.', 'wp-fts-sandbox-detail-error');
                return;
            }
            cell.innerHTML = row.snippet_html || '<span class="description">No excerpt available.</span>';
        });

        termCells.forEach(function(cell) {
            var postId = cell.getAttribute('data-post-id') || '';
            var row = rows[postId] || null;
            cell.classList.remove('wp-fts-sandbox-detail-pending');
            cell.classList.add('wp-fts-sandbox-indexed-terms');
            if (!row || !Array.isArray(row.indexed_terms)) {
                setCellMessage(cell, 'Could not load indexed terms.', 'wp-fts-sandbox-detail-error');
                return;
            }
            cell.textContent = '';
            if (row.indexed_terms.length === 0) {
                var empty = document.createElement('span');
                empty.setAttribute('aria-hidden', 'true');
                empty.textContent = '-';
                cell.appendChild(empty);
                return;
            }
            row.indexed_terms.forEach(function(term) {
                var code = document.createElement('code');
                code.textContent = term;
                cell.appendChild(code);
                cell.appendChild(document.createTextNode(' '));
            });
            if (row.indexed_terms_more) {
                var more = document.createElement('span');
                more.className = 'description';
                more.textContent = '...';
                cell.appendChild(more);
            }
        });
    }).catch(function() {
        fail('Could not load details.');
    });
})();
</script>
JS;
    }

    private static function render_sandbox_notice(string $type, string $message): void
    {
        $classes = [
            'error' => 'notice-error',
            'success' => 'notice-success',
            'info' => 'notice-info',
        ];
        $class = $classes[$type] ?? 'notice-info';

        echo '<div class="notice ' . self::esc_attr($class) . '"><p>' . self::esc_html($message) . '</p></div>';
    }

    private static function sandbox_indexed_posts_page_number(): int
    {
        $raw = self::request_text_value($_GET, self::ADMIN_POSTS_PAGE_FIELD, 12);
        if ($raw === '' || preg_match('/^[1-9][0-9]*$/', $raw) !== 1) {
            return 1;
        }

        return max(1, (int) $raw);
    }

    private static function sandbox_indexed_terms_debug_enabled(): bool
    {
        return self::sandbox_indexed_terms_debug_enabled_from_source($_GET);
    }

    /**
     * @param array<string,mixed> $source
     */
    private static function sandbox_indexed_terms_debug_enabled_from_source(array $source): bool
    {
        return self::request_bool_value($source, self::ADMIN_SHOW_INDEXED_TERMS_FIELD, false, true);
    }

    /**
     * @return array{page:int,per_page:int,total:int,total_pages:int,rows:array<int,array{post_id:int,title:string,post_type:string,post_status:string,language:string,length:int,indexed_terms:string[],indexed_terms_more:bool,preview:string}>}
     */
    private static function empty_sandbox_indexed_posts_page(int $page = 1): array
    {
        return [
            'page' => max(1, $page),
            'per_page' => self::SANDBOX_INDEXED_POSTS_PER_PAGE,
            'total' => 0,
            'total_pages' => 1,
            'rows' => [],
        ];
    }

    /**
     * Read the current indexed-post list from storage state, not the demo option.
     *
     * @return array{page:int,per_page:int,total:int,total_pages:int,rows:array<int,array{post_id:int,title:string,post_type:string,post_status:string,language:string,length:int,indexed_terms:string[],indexed_terms_more:bool,preview:string}>}
     */
    private static function sandbox_indexed_posts_page(int $page, bool $show_indexed_terms = false): array
    {
        $storage = self::storage(false);
        $post_ids = array_values(array_unique(array_filter(
            array_map('intval', $storage->all_doc_ids(false)),
            static fn(int $post_id): bool => $post_id > 0
        )));
        sort($post_ids, SORT_NUMERIC);

        $per_page = self::SANDBOX_INDEXED_POSTS_PER_PAGE;
        $total = count($post_ids);
        $total_pages = max(1, (int) ceil($total / $per_page));
        $page = min(max(1, $page), $total_pages);
        $page_ids = array_slice($post_ids, ($page - 1) * $per_page, $per_page);
        $metadata = WP_FTS_StorageCompat::get_doc_metadata($storage, $page_ids);

        $rows = [];
        foreach ($page_ids as $post_id) {
            $doc = $storage->get_doc($post_id);
            if ($doc === null || (bool) ($doc['deleted'] ?? false)) {
                continue;
            }
            $indexed_terms = $show_indexed_terms
                ? WP_FTS_StorageCompat::terms_for_doc($storage, $post_id, self::SANDBOX_INDEXED_TERMS_LIMIT + 1)
                : [];
            $rows[] = self::sandbox_indexed_post_row($post_id, $metadata[$post_id] ?? [], $doc, $indexed_terms);
        }

        return [
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $total_pages,
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $doc
     * @param string[] $indexed_terms
     * @return array{post_id:int,title:string,post_type:string,post_status:string,language:string,length:int,indexed_terms:string[],indexed_terms_more:bool,preview:string}
     */
    private static function sandbox_indexed_post_row(int $post_id, array $metadata, array $doc, array $indexed_terms): array
    {
        $post = self::post_object($post_id);
        $post_type = self::metadata_or_post_value($metadata, $post, 'post_type');
        $post_status = self::metadata_or_post_value($metadata, $post, 'post_status');
        $lengths = WP_FTS_StorageCompat::doc_lang_lengths($doc, self::sandbox_indexed_language($metadata, $doc, 'en'));
        $preview = (string) ($metadata['search_text'] ?? $metadata['excerpt'] ?? '');
        if ($preview === '' && $post !== null && isset($post->post_content)) {
            $preview = (string) $post->post_content;
        }

        return [
            'post_id' => $post_id,
            'title' => self::metadata_title($metadata, $post_id),
            'post_type' => $post_type !== '' ? $post_type : 'unknown',
            'post_status' => $post_status !== '' ? $post_status : 'unknown',
            'language' => self::sandbox_indexed_post_language_display($metadata, $doc, $lengths),
            'length' => array_sum($lengths),
            'indexed_terms' => self::sandbox_indexed_terms_for_display(array_slice($indexed_terms, 0, self::SANDBOX_INDEXED_TERMS_LIMIT)),
            'indexed_terms_more' => count($indexed_terms) > self::SANDBOX_INDEXED_TERMS_LIMIT,
            'preview' => self::sanitize_text($preview),
        ];
    }

    /**
     * @param string[] $terms
     * @return string[]
     */
    private static function sandbox_indexed_terms_for_display(array $terms): array
    {
        $display = [];
        foreach ($terms as $term) {
            $split = WP_FTS_TermNamespace::split_term($term);
            $label = $split !== null
                ? $split['lang'] . ':' . $split['term']
                : $term;
            $label = trim(str_replace(["\r", "\n", "\t"], ' ', WP_FTS_Utf8::repair($label)));
            if ($label !== '') {
                $display[$label] = true;
            }
        }

        return array_keys($display);
    }

    /**
     * @param string[] $terms
     */
    private static function render_sandbox_indexed_terms(array $terms, bool $has_more, bool $loaded): void
    {
        if (!$loaded) {
            echo '<span class="description">Hidden</span>';
            return;
        }

        if ($terms === []) {
            echo '<span aria-hidden="true">-</span>';
            return;
        }

        foreach ($terms as $term) {
            echo '<code>' . self::esc_html($term) . '</code> ';
        }
        if ($has_more) {
            echo '<span class="description">...</span>';
        }
    }

    /**
     * @param array<string,mixed> $metadata
     * @param object|null $post
     */
    private static function metadata_or_post_value(array $metadata, ?object $post, string $field): string
    {
        if (isset($metadata[$field]) && is_scalar($metadata[$field]) && trim((string) $metadata[$field]) !== '') {
            return (string) $metadata[$field];
        }

        if ($post !== null && isset($post->{$field}) && is_scalar($post->{$field}) && trim((string) $post->{$field}) !== '') {
            return (string) $post->{$field};
        }

        return '';
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private static function metadata_title(array $metadata, int $post_id): string
    {
        if (isset($metadata['title']) && is_scalar($metadata['title']) && trim((string) $metadata['title']) !== '') {
            return (string) $metadata['title'];
        }

        return self::post_title($post_id);
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $doc
     * @param array<string,int> $lengths
     */
    private static function sandbox_indexed_post_language_display(array $metadata, array $doc, array $lengths): string
    {
        $languages = array_keys($lengths);
        if ($languages === []) {
            $languages[] = self::sandbox_indexed_language($metadata, $doc, 'en');
        }

        $display = [];
        foreach ($languages as $language) {
            $support = self::language_support_details($language, true);
            $suffix = '';
            if (!$support['full']) {
                if ($support['label'] === 'Conservative fallback') {
                    $suffix = ' (exact forms only)';
                } elseif ($support['label'] === 'Fixture morphology') {
                    $suffix = ' (limited demo coverage)';
                } elseif ($support['label'] === 'Tokenizer pack') {
                    $suffix = ' (word boundaries only)';
                }
            }
            $display[] = self::sandbox_language_display($language) . ' - ' . $support['label'] . $suffix;
        }

        return implode(', ', array_values(array_unique($display)));
    }

    private static function sandbox_language_display(string $language): string
    {
        $language = WP_FTS_TermNamespace::canonicalize_lang($language);
        if ($language === '') {
            return 'unknown';
        }

        $base = self::base_language($language);
        $label = self::sandbox_language_labels()[$language] ?? self::sandbox_language_labels()[$base] ?? strtoupper($base !== '' ? $base : $language);

        return sprintf('%s (%s)', $label, $language);
    }

    /**
     * @return array{label:string,full:bool,reason:string,matched_language:string}
     */
    private static function language_support_details(string $language, bool $include_sandbox): array
    {
        $language = WP_FTS_TermNamespace::canonicalize_lang($language, WP_FTS_TermNamespace::DEFAULT_LANG);
        $cache_key = $language . '|' . ($include_sandbox ? 'sandbox' : 'runtime');
        if (isset(self::$language_support_details_cache[$cache_key])) {
            return self::$language_support_details_cache[$cache_key];
        }

        $base = self::base_language($language);
        $statuses = self::runtime_analyzer_pack_statuses();
        if ($include_sandbox) {
            $statuses = array_merge($statuses, self::sandbox_demo_analyzer_pack_statuses());
        }

        $fixture = false;
        $tokenizer = false;
        foreach ($statuses as $status) {
            $status_language = WP_FTS_TermNamespace::canonicalize_lang((string) ($status['language'] ?? ''), WP_FTS_TermNamespace::DEFAULT_LANG);
            if ($status_language !== $language && self::base_language($status_language) !== $base) {
                continue;
            }
            if (($status['status'] ?? '') !== 'active') {
                continue;
            }
            if (($status['kind'] ?? '') === 'lemmatizer') {
                if (empty($status['fixture_only'])) {
                    return self::$language_support_details_cache[$cache_key] = [
                        'label' => 'Full morphology',
                        'full' => true,
                        'reason' => self::language_support_reason($language, $status_language, 'full'),
                        'matched_language' => $status_language,
                    ];
                }
                $fixture = true;
            } elseif (($status['kind'] ?? '') === 'tokenizer') {
                $tokenizer = true;
            }
        }

        if ($fixture) {
            return self::$language_support_details_cache[$cache_key] = [
                'label' => 'Fixture morphology',
                'full' => false,
                'reason' => 'Only a fixture-sized analyzer pack is active for this language, so coverage is limited to reviewed test forms.',
                'matched_language' => $language,
            ];
        }
        if ($tokenizer) {
            return self::$language_support_details_cache[$cache_key] = [
                'label' => 'Tokenizer pack',
                'full' => false,
                'reason' => 'A tokenizer pack is active, but full morphology is unavailable.',
                'matched_language' => $language,
            ];
        }

        return self::$language_support_details_cache[$cache_key] = [
            'label' => 'Conservative fallback',
            'full' => false,
            'reason' => 'No active analyzer pack covers this language. Exact-word search and conservative fallback will be used until an analyzer pack is installed or generated with the pack tooling and configured for this language.',
            'matched_language' => '',
        ];
    }

    private static function language_support_reason(string $language, string $matched_language, string $support): string
    {
        if ($support !== 'full') {
            return '';
        }

        if ($matched_language !== '' && $matched_language !== $language) {
            if ($matched_language === 'en' && self::base_language($language) === 'en') {
                return sprintf(
                    'English morphology is available through the active base-language analyzer pack %s, which applies to English dialects/locales such as %s.',
                    self::sandbox_language_display($matched_language),
                    self::sandbox_language_display($language)
                );
            }

            return sprintf(
                'Full morphology is available through the active base-language analyzer pack %s, which applies to %s.',
                self::sandbox_language_display($matched_language),
                self::sandbox_language_display($language)
            );
        }

        return 'An active full analyzer pack is available for this language.';
    }

    private static function render_site_language_status_notice(): void
    {
        $language = self::site_language();
        $runtime_support = self::language_support_details($language, false);
        if ($runtime_support['full']) {
            if (($runtime_support['matched_language'] ?? '') !== '' && $runtime_support['matched_language'] !== WP_FTS_TermNamespace::canonicalize_lang($language, WP_FTS_TermNamespace::DEFAULT_LANG)) {
                echo '<p class="description wp-fts-language-status">' . self::esc_html(
                    sprintf(
                        'Current site language %s uses full morphology through the active base-language analyzer pack %s for this language family.',
                        self::sandbox_language_display($language),
                        self::sandbox_language_display($runtime_support['matched_language'])
                    )
                ) . '</p>';
            }
            return;
        }

        $page_support = self::language_support_details($language, true);
        if ($page_support['full'] && ($page_support['matched_language'] ?? '') !== '') {
            $message = self::base_language($language) === 'en' && $page_support['matched_language'] === 'en'
                ? sprintf(
                    'Current site language %s uses conservative fallback for runtime site searches because no runtime analyzer pack covers it. Sandbox searches can use English morphology through the active base-language analyzer pack %s for English dialects/locales.',
                    self::sandbox_language_display($language),
                    self::sandbox_language_display($page_support['matched_language'])
                )
                : sprintf(
                    'Current site language %s uses conservative fallback for runtime site searches because no runtime analyzer pack covers it. Sandbox searches can use full morphology through the active base-language analyzer pack %s.',
                    self::sandbox_language_display($language),
                    self::sandbox_language_display($page_support['matched_language'])
                );
            echo '<p class="description wp-fts-language-status">' . self::esc_html($message) . '</p>';
            return;
        }

        echo '<p class="description wp-fts-language-status">' . self::esc_html(
            sprintf(
                'Current site language %s uses %s. Exact-word search still works; install or build an analyzer pack with the pack tooling and configure it for this language to match language-specific word forms.',
                self::sandbox_language_display($language),
                strtolower($runtime_support['label'])
            )
        ) . '</p>';
    }

    private static function site_language(): string
    {
        if (function_exists('get_locale')) {
            $locale = get_locale();
            if (is_scalar($locale) && trim((string) $locale) !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang((string) $locale);
            }
        }

        if (function_exists('get_bloginfo')) {
            $language = get_bloginfo('language');
            if (is_scalar($language) && trim((string) $language) !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang((string) $language);
            }
        }

        return WP_FTS_TermNamespace::default_language();
    }

    /**
     * Create a WordPress HTML processor for the framework-neutral analyzer.
     */
    private static function create_wp_html_processor(string $html): mixed
    {
        if (!class_exists('WP_HTML_Processor')) {
            return null;
        }

        try {
            if (
                self::looks_like_full_html_document($html)
                && method_exists('WP_HTML_Processor', 'create_full_parser')
            ) {
                return WP_HTML_Processor::create_full_parser($html);
            }

            return WP_HTML_Processor::create_fragment($html);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Heuristically decide whether WordPress should use full-document parsing.
     */
    private static function looks_like_full_html_document(string $html): bool
    {
        return (bool) preg_match('/<(?:!doctype|html|head|title)\b/i', $html);
    }

    /**
     * @return string[]
     */
    private static function site_fallback_languages(): array
    {
        $site_language = WP_FTS_TermNamespace::canonicalize_lang(self::site_language(), WP_FTS_TermNamespace::DEFAULT_LANG);
        $languages = [$site_language];
        $base_language = self::base_language($site_language);
        if ($base_language !== '' && !in_array($base_language, $languages, true)) {
            $languages[] = $base_language;
        }

        return $languages;
    }

    private static function base_language(string $language): string
    {
        $language = WP_FTS_TermNamespace::canonicalize_lang($language, WP_FTS_TermNamespace::DEFAULT_LANG);
        $parts = explode('-', $language);

        return strtolower((string) ($parts[0] ?? $language));
    }

    private static function sandbox_indexed_posts_page_url(int $page, string $query, string $selected_language, bool $search_submitted, bool $show_indexed_terms = false): string
    {
        $params = [
            'page' => self::ADMIN_PAGE_SLUG,
            self::ADMIN_TAB_FIELD => self::ADMIN_INDEXED_TAB,
            self::ADMIN_POSTS_PAGE_FIELD => (string) max(1, $page),
        ];

        if ($show_indexed_terms) {
            $params[self::ADMIN_SHOW_INDEXED_TERMS_FIELD] = '1';
        }

        if ($search_submitted) {
            if ($query !== '') {
                $params[self::ADMIN_QUERY_FIELD] = $query;
            }
            $params[self::ADMIN_LANG_FIELD] = $selected_language;
            $params[self::ADMIN_SEARCH_FIELD] = '1';
        }

        return self::admin_options_general_url() . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string,mixed> $controls
     * @param int[] $post_ids
     * @return array<string,array<string,mixed>>
     */
    private static function sandbox_result_details(string $query, string $selected_language, array $controls, array $post_ids, bool $include_indexed_terms): array
    {
        $requested = array_fill_keys($post_ids, true);
        $storage = self::storage(false);
        $details = [];
        $results = self::sandbox_search_results($query, $selected_language, $controls, true);

        foreach ($results['results'] as $row) {
            $post_id = max(0, (int) ($row['post_id'] ?? 0));
            if ($post_id <= 0 || !isset($requested[$post_id])) {
                continue;
            }

            $detail = [
                'snippet_html' => self::sanitize_frontend_snippet_html((string) ($row['snippet'] ?? '')),
            ];

            if ($include_indexed_terms) {
                $terms = WP_FTS_StorageCompat::terms_for_doc($storage, $post_id, self::SANDBOX_INDEXED_TERMS_LIMIT + 1);
                $detail['indexed_terms'] = self::sandbox_indexed_terms_for_display(array_slice($terms, 0, self::SANDBOX_INDEXED_TERMS_LIMIT));
                $detail['indexed_terms_more'] = count($terms) > self::SANDBOX_INDEXED_TERMS_LIMIT;
            }

            $details[(string) $post_id] = $detail;
        }

        return $details;
    }

    /**
     * Normalize a raw searcher row for sandbox display.
     *
     * @param array<string,mixed> $row
     * @return array{post_id:int,title:string,score:float,language:string,snippet:string}
     */
    private static function sandbox_result_row(array $row, WP_FTS_Storage $storage, int $post_id): array
    {
        $metadata = WP_FTS_StorageCompat::get_doc_metadata($storage, [$post_id]);
        $meta = $metadata[$post_id] ?? [];
        $language = '';
        foreach ([
            $row['language'] ?? null,
            $meta['language'] ?? null,
            $meta['lang'] ?? null,
        ] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                $language = (string) $candidate;
                break;
            }
        }

        $title = is_scalar($row['title'] ?? null) && trim((string) $row['title']) !== ''
            ? (string) $row['title']
            : self::post_title($post_id);
        $snippet = is_scalar($row['snippet'] ?? null) && trim((string) $row['snippet']) !== ''
            ? (string) $row['snippet']
            : '';

        return [
            'post_id' => $post_id,
            'title' => $title,
            'score' => (float) ($row['score'] ?? 0.0),
            'language' => $language !== '' ? $language : 'unknown',
            'snippet' => $snippet,
        ];
    }

    private static function render_sandbox_nonce_field(): void
    {
        $nonce = self::create_admin_nonce(self::ADMIN_NONCE_ACTION);

        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_NONCE_FIELD) . '" value="' . self::esc_attr($nonce) . '">';
    }

    private static function render_health_nonce_field(): void
    {
        $nonce = self::create_admin_nonce(self::ADMIN_HEALTH_NONCE_ACTION);

        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_HEALTH_NONCE_FIELD) . '" value="' . self::esc_attr($nonce) . '">';
    }

    private static function create_admin_nonce(string $action): string
    {
        return function_exists('wp_create_nonce') ? (string) wp_create_nonce($action) : '';
    }

    private static function admin_page_url(string $tab = self::ADMIN_HEALTH_TAB): string
    {
        $params = ['page' => self::ADMIN_PAGE_SLUG];
        $tab = self::sanitize_admin_tab($tab);
        if ($tab !== self::ADMIN_HEALTH_TAB) {
            $params[self::ADMIN_TAB_FIELD] = $tab;
        }

        return self::admin_options_general_url() . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private static function admin_options_url(): string
    {
        return function_exists('admin_url') ? (string) admin_url('options.php') : 'options.php';
    }

    private static function admin_ajax_url(): string
    {
        return function_exists('admin_url') ? (string) admin_url('admin-ajax.php') : 'admin-ajax.php';
    }

    private static function admin_options_general_url(): string
    {
        return function_exists('admin_url') ? (string) admin_url('options-general.php') : 'options-general.php';
    }

    private static function render_option(string $value, string $label, string $selected_value): void
    {
        $selected = $value === $selected_value ? ' selected="selected"' : '';
        echo '<option value="' . self::esc_attr($value) . '"' . $selected . '>' . self::esc_html($label) . '</option>';
    }

    private static function post_title(int $post_id): string
    {
        if (function_exists('get_the_title')) {
            $title = get_the_title($post_id);
            if (is_scalar($title) && trim((string) $title) !== '') {
                return (string) $title;
            }
        }

        $post = self::post_object($post_id);
        if ($post !== null && isset($post->post_title) && trim((string) $post->post_title) !== '') {
            return (string) $post->post_title;
        }

        return '(untitled)';
    }

    /**
     * Public REST endpoint permission is open; result filtering enforces visibility.
     */
    public static function rest_search_permission(mixed ...$unused): bool
    {
        return true;
    }

    /**
     * REST callback returning filtered ranked result rows.
     *
     * @param mixed $request WordPress REST request or request-like array/object.
     * @return array{results:array<int,array{doc_id:int,score:float}>}|object|array<string,mixed>
     */
    public static function rest_search(mixed $request): array|object
    {
        $query = self::rest_query($request);
        if ($query === '') {
            return self::rest_error(
                'wp_fts_missing_query',
                'REST search requires a non-empty q or query parameter.',
                400
            );
        }

        $mode = self::rest_mode($request);
        if ($mode === null) {
            return self::rest_error(
                'wp_fts_invalid_mode',
                'REST search mode must be OR or AND.',
                400
            );
        }

        $search_args = [
            'lang' => self::request_param($request, 'lang', null),
            'mode' => $mode,
            'limit' => self::request_param($request, 'limit', 10),
        ];
        $prefix_matching = self::request_param($request, 'prefix_matching', null);
        if ($prefix_matching !== null) {
            $search_args['prefix_matching'] = $prefix_matching;
        }

        return [
            'results' => self::search($query, $search_args),
        ];
    }

    /**
     * Search the index and return only posts visible to the current visitor.
     *
     * @return array<int,array{doc_id:int,score:float}>
     */
    public static function search(string $query, array $opts = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = self::clamp_int($opts['limit'] ?? 10, 1, self::MAX_SEARCH_LIMIT);
        $mode = strtoupper((string) ($opts['mode'] ?? 'OR'));
        $settings = self::settings();
        $search_options = [
            'mode' => $mode,
            'limit' => $limit,
            'prefix_matching' => self::search_prefix_matching_value($opts, $settings),
        ];
        if (isset($opts['lang']) && is_scalar($opts['lang']) && trim((string) $opts['lang']) !== '') {
            $search_options['lang'] = (string) $opts['lang'];
        }
        foreach (['prefix_min_length', 'prefix_max_terms'] as $prefix_option) {
            if (array_key_exists($prefix_option, $opts)) {
                $search_options[$prefix_option] = $opts[$prefix_option];
            }
        }

        $searcher = new WP_FTS_Searcher(self::storage(false), self::runtime_analyzer());
        $visible = [];
        $offset = 0;
        $batch_limit = self::visibility_refill_batch_limit($limit);
        while (count($visible) < $limit && $offset < self::VISIBILITY_REFILL_MAX_SCAN) {
            $search_options['limit'] = min($batch_limit, self::VISIBILITY_REFILL_MAX_SCAN - $offset);
            $search_options['offset'] = $offset;
            $rows = $searcher->search($query, $search_options);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $doc_id = (int) $row['doc_id'];
                if (self::can_read_post_result($doc_id)) {
                    $visible[] = [
                        'doc_id' => $doc_id,
                        'score' => (float) $row['score'],
                    ];
                    if (count($visible) >= $limit) {
                        break;
                    }
                }
            }

            if (count($rows) < $search_options['limit']) {
                break;
            }
            $offset += $search_options['limit'];
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('wp_fts_search_results', $visible, $query, $opts);
            if (is_array($filtered)) {
                return $filtered;
            }
        }

        return $visible;
    }

    /**
     * Resolve saved prefix behavior with per-search overrides.
     *
     * @param array<string,mixed> $opts
     * @param array<string,mixed> $settings
     */
    private static function search_prefix_matching_value(array $opts, array $settings): bool
    {
        if (array_key_exists('prefix_matching', $opts)) {
            return self::truthy_admin_value($opts['prefix_matching']);
        }

        if (array_key_exists('prefix', $opts)) {
            return self::truthy_admin_value($opts['prefix']);
        }

        return (bool) ($settings['prefix_matching'] ?? true);
    }

    /**
     * Mark eligible front-end search queries before posts are requested.
     *
     * @param mixed $query WordPress WP_Query-like object.
     */
    public static function prepare_frontend_search_query(mixed $query): void
    {
        if (!self::is_frontend_search_query($query)) {
            if (self::debug_collection_enabled('frontend search')) {
                self::debug_record_bailout(
                    'frontend search',
                    self::frontend_search_query_text($query),
                    self::frontend_search_bailout_reason($query),
                    self::debug_effective_settings(self::settings())
                );
            }
            return;
        }

        self::set_query_var($query, 'wp_fts_search_candidate', true);
    }

    /**
     * Short-circuit the main front-end search query with FTS-ranked posts.
     *
     * @param mixed $posts Incoming posts from WordPress or an earlier short-circuit provider.
     * @param mixed $query WordPress WP_Query-like object.
     * @return mixed Null to leave WordPress alone, or an array of post objects.
     */
    public static function replace_frontend_search_posts(mixed $posts, mixed $query): mixed
    {
        if (!self::should_replace_frontend_search($query)) {
            if (self::debug_collection_enabled('frontend search')) {
                self::debug_record_bailout(
                    'frontend search',
                    self::frontend_search_query_text($query),
                    self::frontend_search_replacement_bailout_reason($query),
                    self::debug_effective_settings(self::settings())
                );
            }
            return $posts;
        }

        $search_query = self::frontend_search_query_text($query);
        if ($search_query === '') {
            self::debug_record_bailout(
                'frontend search',
                '',
                'Empty search query.',
                self::debug_effective_settings(self::settings())
            );
            return $posts;
        }

        $settings = self::settings();
        if (self::should_preserve_prior_search_provider_result($posts, $settings)) {
            self::debug_record_bailout(
                'frontend search',
                $search_query,
                self::prior_search_provider_result_bailout_reason(),
                self::debug_effective_settings($settings)
            );
            return $posts;
        }

        $trace_id = self::debug_start_trace('frontend search', $search_query, self::debug_effective_settings($settings));
        $result = self::frontend_search_result_page($query, $search_query, $trace_id, $settings);
        self::store_frontend_search_query_state(
            $query,
            $result['total'],
            $result['limit'],
            $result['query_lang'],
            $result['snippets'],
            $result['titles'],
            $trace_id
        );

        return $result['posts'];
    }

    /**
     * Preserve FTS total counts for themes and pagination helpers.
     *
     * @param mixed $found_posts WordPress' computed total.
     * @param mixed $query WP_Query-like object.
     */
    public static function filter_frontend_search_found_posts(mixed $found_posts, mixed $query): mixed
    {
        $query_key = self::query_object_key($query);
        if ($query_key > 0 && isset(self::$front_end_search_query_state[$query_key])) {
            return self::$front_end_search_query_state[$query_key]['total'];
        }

        return $found_posts;
    }

    /**
     * Mark eligible wp-admin Posts list searches before posts are requested.
     *
     * @param mixed $query WordPress WP_Query-like object.
     */
    public static function prepare_admin_post_search_query(mixed $query): void
    {
        if (!self::is_admin_post_search_query($query)) {
            if (self::debug_collection_enabled('admin post search')) {
                self::debug_record_bailout(
                    'admin post search',
                    self::frontend_search_query_text($query),
                    self::admin_post_search_bailout_reason($query),
                    self::debug_effective_settings(self::settings())
                );
            }
            return;
        }

        self::set_query_var($query, 'wp_fts_admin_post_search_candidate', true);
    }

    /**
     * Short-circuit the main wp-admin Posts list search with FTS-ranked posts.
     *
     * @param mixed $posts Incoming posts from WordPress or an earlier short-circuit provider.
     * @param mixed $query WordPress WP_Query-like object.
     * @return mixed Null to leave WordPress alone, or an array of post objects.
     */
    public static function replace_admin_post_search_posts(mixed $posts, mixed $query): mixed
    {
        if (!self::should_replace_admin_post_search($query)) {
            if (self::debug_collection_enabled('admin post search')) {
                self::debug_record_bailout(
                    'admin post search',
                    self::frontend_search_query_text($query),
                    self::admin_post_search_replacement_bailout_reason($query),
                    self::debug_effective_settings(self::settings())
                );
            }
            return $posts;
        }

        $search_query = self::frontend_search_query_text($query);
        if ($search_query === '') {
            self::debug_record_bailout(
                'admin post search',
                '',
                'Empty search query.',
                self::debug_effective_settings(self::settings())
            );
            return $posts;
        }

        $settings = self::settings();
        if (self::should_preserve_prior_search_provider_result($posts, $settings)) {
            self::debug_record_bailout(
                'admin post search',
                $search_query,
                self::prior_search_provider_result_bailout_reason(),
                self::debug_effective_settings($settings)
            );
            return $posts;
        }

        $trace_id = self::debug_start_trace('admin post search', $search_query, self::debug_effective_settings($settings));
        $result = self::admin_post_search_result_page($query, $search_query, $trace_id, $settings);
        self::store_admin_post_search_query_state(
            $query,
            $result['total'],
            $result['limit'],
            $result['query_lang'],
            $trace_id
        );

        return $result['posts'];
    }

    /**
     * Preserve FTS total counts for wp-admin list-table pagination.
     *
     * @param mixed $found_posts WordPress' computed total.
     * @param mixed $query WP_Query-like object.
     */
    public static function filter_admin_post_search_found_posts(mixed $found_posts, mixed $query): mixed
    {
        $query_key = self::query_object_key($query);
        if ($query_key > 0 && isset(self::$admin_post_search_query_state[$query_key])) {
            return self::$admin_post_search_query_state[$query_key]['total'];
        }

        return $found_posts;
    }

    /**
     * Track when the replaced main query loop is rendering.
     *
     * @param mixed $query WP_Query-like object passed by loop_start.
     */
    public static function begin_frontend_search_loop(mixed $query): void
    {
        self::$front_end_search_loop_stack[] = self::$front_end_search_active_query_key;

        $query_key = self::query_object_key($query);
        if ($query_key > 0 && isset(self::$front_end_search_query_state[$query_key])) {
            self::$front_end_search_active_query_key = $query_key;
            return;
        }

        self::$front_end_search_active_query_key = self::frontend_search_query_state_key_for_query($query);
    }

    /**
     * Restore the previous loop scope after a loop finishes.
     *
     * @param mixed $query WP_Query-like object passed by loop_end.
     */
    public static function end_frontend_search_loop(mixed $query): void
    {
        $previous = array_pop(self::$front_end_search_loop_stack);
        self::$front_end_search_active_query_key = is_int($previous) ? $previous : 0;
    }

    /**
     * Serve highlighted FTS snippets through normal search result previews.
     *
     * @param mixed $excerpt Existing excerpt.
     * @param mixed $post Current post, when supplied by WordPress.
     */
    public static function frontend_search_excerpt(mixed $excerpt, mixed $post = null): string
    {
        $post_id = self::post_id_from_value($post);
        if ($post_id <= 0 && isset($GLOBALS['post'])) {
            $post_id = self::post_id_from_value($GLOBALS['post']);
        }

        $state = self::frontend_search_query_state_for_post($post_id);
        if ($state !== null && array_key_exists($post_id, $state['snippets'])) {
            self::debug_add_count((int) ($state['trace_id'] ?? 0), 'highlight_replacements');
            return $state['snippets'][$post_id];
        }

        return is_scalar($excerpt) ? (string) $excerpt : '';
    }

    /**
     * Serve highlighted FTS content previews without changing theme layout.
     *
     * Search-result templates that render `the_content` expect block-level
     * markup. Returning a bare text node can bypass block-theme width
     * constraints, so content previews are wrapped like normal paragraph
     * content while preserving the same highlighted snippet.
     *
     * @param mixed $content Existing content.
     */
    public static function frontend_search_content(mixed $content): string
    {
        $post_id = isset($GLOBALS['post']) ? self::post_id_from_value($GLOBALS['post']) : 0;
        $state = self::frontend_search_query_state_for_post($post_id);
        if ($state !== null && array_key_exists($post_id, $state['snippets'])) {
            self::debug_add_count((int) ($state['trace_id'] ?? 0), 'highlight_replacements');
            return self::frontend_content_preview_markup($state['snippets'][$post_id]);
        }

        return is_scalar($content) ? (string) $content : '';
    }

    /**
     * Serve highlighted FTS titles through normal search result title rendering.
     *
     * @param mixed $title Existing title.
     * @param mixed $post_id Current post id, when supplied by WordPress.
     */
    public static function frontend_search_title(mixed $title, mixed $post_id = null): string
    {
        $id = self::post_id_from_value($post_id);
        if ($id <= 0 && isset($GLOBALS['post'])) {
            $id = self::post_id_from_value($GLOBALS['post']);
        }

        $state = self::frontend_search_query_state_for_post($id);
        if ($state !== null && array_key_exists($id, $state['titles'])) {
            self::debug_add_count((int) ($state['trace_id'] ?? 0), 'highlight_replacements');
            return $state['titles'][$id];
        }

        return is_scalar($title) ? (string) $title : '';
    }

    /**
     * Serve stored FTS previews through core post field blocks in block themes.
     *
     * @param mixed $block_content Existing rendered block HTML.
     * @param mixed $block Parsed block array when provided by WordPress.
     * @param mixed $instance WP_Block-like instance when provided by WordPress.
     */
    public static function frontend_search_render_block(mixed $block_content, mixed $block = null, mixed $instance = null): string
    {
        $render_started = microtime(true);
        $content = is_scalar($block_content) ? (string) $block_content : '';
        $block_name = self::rendered_block_name($block, $instance);
        if (!in_array($block_name, ['core/post-content', 'core/post-excerpt', 'core/post-title'], true)) {
            return $content;
        }

        if (str_contains($content, '<mark') || self::is_admin_request() || self::is_rest_request() || self::is_cron_request()) {
            return $content;
        }

        $post_id = self::post_id_from_rendered_block($block, $instance);
        if ($post_id <= 0 && isset($GLOBALS['post'])) {
            $post_id = self::post_id_from_value($GLOBALS['post']);
        }

        $state = self::frontend_search_query_state_for_post($post_id);
        if ($state === null) {
            return $content;
        }
        $trace_id = (int) ($state['trace_id'] ?? 0);
        self::debug_add_count($trace_id, 'render_block_visits');

        if ($block_name === 'core/post-title') {
            if (!array_key_exists($post_id, $state['titles'])) {
                self::debug_add_timing($trace_id, 'block render highlighting', $render_started);
                return $content;
            }

            $rendered = self::frontend_rendered_title_block_markup(
                $content,
                self::raw_post_title($post_id),
                $state['titles'][$post_id]
            );
            if ($rendered !== $content) {
                self::debug_add_count($trace_id, 'render_block_replacements');
                self::debug_add_count($trace_id, 'highlight_replacements');
            }
            self::debug_add_timing($trace_id, 'block render highlighting', $render_started);

            return $rendered;
        }

        if (!array_key_exists($post_id, $state['snippets'])) {
            self::debug_add_timing($trace_id, 'block render highlighting', $render_started);
            return $content;
        }

        $rendered = self::frontend_content_preview_markup($state['snippets'][$post_id]);
        if ($rendered !== $content) {
            self::debug_add_count($trace_id, 'render_block_replacements');
            self::debug_add_count($trace_id, 'highlight_replacements');
        }
        self::debug_add_timing($trace_id, 'block render highlighting', $render_started);

        return $rendered;
    }

    /**
     * Resolve the FTS state that owns a frontend-rendered post.
     *
     * @return array{total:int,max_pages:int,query_lang:string,query_text:string,snippets:array<int,string>,titles:array<int,string>,trace_id:int}|null
     */
    private static function frontend_search_query_state_for_post(int $post_id): ?array
    {
        if ($post_id <= 0) {
            return null;
        }

        $active_key = self::$front_end_search_active_query_key;
        if (self::frontend_search_query_state_contains_post($active_key, $post_id)) {
            return self::$front_end_search_query_state[$active_key];
        }

        if ($active_key > 0 || self::$front_end_search_loop_stack !== []) {
            return null;
        }

        $query = $GLOBALS['wp_query'] ?? null;
        if (!is_object($query) || !self::is_frontend_search_query($query)) {
            return null;
        }

        $query_key = self::query_object_key($query);
        if (self::frontend_search_query_state_contains_post($query_key, $post_id)) {
            return self::$front_end_search_query_state[$query_key];
        }

        $query_text = self::frontend_search_query_text($query);
        foreach (self::$front_end_search_query_state as $state) {
            if (
                self::query_texts_match((string) ($state['query_text'] ?? ''), $query_text)
                && self::frontend_search_query_state_array_contains_post($state, $post_id)
            ) {
                return $state;
            }
        }

        return null;
    }

    private static function frontend_search_query_state_contains_post(int $query_key, int $post_id): bool
    {
        if ($query_key <= 0 || !isset(self::$front_end_search_query_state[$query_key])) {
            return false;
        }

        $state = self::$front_end_search_query_state[$query_key];

        return self::frontend_search_query_state_array_contains_post($state, $post_id);
    }

    /**
     * @param array{snippets:array<int,string>,titles:array<int,string>} $state
     */
    private static function frontend_search_query_state_array_contains_post(array $state, int $post_id): bool
    {
        return array_key_exists($post_id, $state['snippets'])
            || array_key_exists($post_id, $state['titles']);
    }

    private static function frontend_search_query_state_key_for_query(mixed $query): int
    {
        if (!self::is_frontend_search_query($query)) {
            return 0;
        }

        $query_text = self::frontend_search_query_text($query);
        foreach (self::$front_end_search_query_state as $query_key => $state) {
            if (self::query_texts_match((string) ($state['query_text'] ?? ''), $query_text)) {
                return (int) $query_key;
            }
        }

        return 0;
    }

    private static function query_texts_match(string $left, string $right): bool
    {
        $left = strtolower(trim($left));
        $right = strtolower(trim($right));

        return $left !== '' && $left === $right;
    }

    private static function rendered_block_name(mixed $block, mixed $instance = null): string
    {
        foreach ([$block, $instance] as $candidate) {
            if (is_array($candidate)) {
                foreach (['blockName', 'name'] as $key) {
                    if (isset($candidate[$key]) && is_scalar($candidate[$key])) {
                        return (string) $candidate[$key];
                    }
                }
            }

            if (is_object($candidate)) {
                foreach (['name', 'blockName'] as $property) {
                    if (isset($candidate->{$property}) && is_scalar($candidate->{$property})) {
                        return (string) $candidate->{$property};
                    }
                }

                if (isset($candidate->parsed_block) && is_array($candidate->parsed_block)) {
                    foreach (['blockName', 'name'] as $key) {
                        if (isset($candidate->parsed_block[$key]) && is_scalar($candidate->parsed_block[$key])) {
                            return (string) $candidate->parsed_block[$key];
                        }
                    }
                }
            }
        }

        return '';
    }

    private static function post_id_from_rendered_block(mixed $block, mixed $instance = null): int
    {
        foreach ([$block, $instance] as $candidate) {
            $context = self::rendered_block_context($candidate);
            if ($context === []) {
                continue;
            }

            foreach (['postId', 'post_id'] as $key) {
                if (array_key_exists($key, $context)) {
                    $post_id = self::post_id_from_value($context[$key]);
                    if ($post_id > 0) {
                        return $post_id;
                    }
                }
            }
        }

        return 0;
    }

    /**
     * @return array<string,mixed>
     */
    private static function rendered_block_context(mixed $candidate): array
    {
        if (is_array($candidate) && isset($candidate['context']) && is_array($candidate['context'])) {
            return $candidate['context'];
        }

        if (is_object($candidate) && isset($candidate->context) && is_array($candidate->context)) {
            return $candidate->context;
        }

        return [];
    }

    private static function frontend_rendered_title_block_markup(string $block_content, string $original_title, string $highlighted_title): string
    {
        $highlighted_title = trim($highlighted_title);
        if ($highlighted_title === '') {
            return $block_content;
        }

        if (trim($block_content) === '') {
            return $highlighted_title;
        }

        $needles = [];
        if ($original_title !== '') {
            $needles[] = self::esc_html($original_title);
            $needles[] = $original_title;
        }

        foreach (array_unique($needles) as $needle) {
            if ($needle === '') {
                continue;
            }

            $replaced = self::replace_first_rendered_text($block_content, $needle, $highlighted_title);
            if ($replaced !== $block_content) {
                return $replaced;
            }
        }

        return $highlighted_title;
    }

    private static function replace_first_rendered_text(string $html, string $needle, string $replacement): string
    {
        $offset = 0;
        $needle_length = strlen($needle);
        while ($needle_length > 0) {
            $position = strpos($html, $needle, $offset);
            if ($position === false) {
                return $html;
            }

            $prefix = substr($html, 0, $position);
            $last_open = strrpos($prefix, '<');
            $last_close = strrpos($prefix, '>');
            if ($last_close !== false && ($last_open === false || $last_close > $last_open)) {
                return substr($html, 0, $position) . $replacement . substr($html, $position + $needle_length);
            }

            $offset = $position + $needle_length;
        }

        return $html;
    }

    private static function raw_post_title(int $post_id): string
    {
        $post = self::post_object($post_id);
        if ($post !== null && isset($post->post_title) && is_scalar($post->post_title)) {
            return (string) $post->post_title;
        }

        return '';
    }

    private static function frontend_content_preview_markup(string $snippet): string
    {
        $snippet = trim($snippet);
        if ($snippet === '') {
            return '';
        }

        if (preg_match('/^\s*<(?:p|div|section|article|blockquote|pre|ul|ol|li|figure|table|h[1-6])\b/i', $snippet) === 1) {
            return $snippet;
        }

        return '<p>' . $snippet . '</p>';
    }

    private static function should_replace_frontend_search(mixed $query): bool
    {
        if (!self::is_frontend_search_query($query)) {
            return false;
        }

        $replace = self::settings()['replace_frontend_search'];
        if (function_exists('apply_filters')) {
            $replace = apply_filters(self::FRONTEND_SEARCH_REPLACEMENT_FILTER, $replace, $query);
        }

        if (is_bool($replace)) {
            return $replace;
        }

        if (is_scalar($replace)) {
            return !in_array(strtolower(trim((string) $replace)), ['', '0', 'false', 'no', 'off'], true);
        }

        return (bool) $replace;
    }

    /**
     * @param array<string,mixed> $settings
     */
    private static function should_preserve_prior_search_provider_result(mixed $posts, array $settings): bool
    {
        return $posts !== null
            && (string) ($settings['search_provider_compatibility'] ?? self::SEARCH_PROVIDER_COMPATIBILITY_PREFER_FTS) === self::SEARCH_PROVIDER_COMPATIBILITY_RESPECT_EXISTING;
    }

    private static function prior_search_provider_result_bailout_reason(): string
    {
        return 'Another search provider already returned a non-null posts_pre_query result; compatibility mode kept that result.';
    }

    private static function should_replace_admin_post_search(mixed $query): bool
    {
        if (!self::is_admin_post_search_query($query)) {
            return false;
        }

        $replace = self::settings()['replace_admin_post_search'];
        if (function_exists('apply_filters')) {
            $replace = apply_filters(self::ADMIN_POST_SEARCH_REPLACEMENT_FILTER, $replace, $query);
        }

        if (is_bool($replace)) {
            return $replace;
        }

        if (is_scalar($replace)) {
            return !in_array(strtolower(trim((string) $replace)), ['', '0', 'false', 'no', 'off'], true);
        }

        return (bool) $replace;
    }

    private static function frontend_search_bailout_reason(mixed $query): string
    {
        if (!is_object($query)) {
            return 'Unsupported query shape: expected a WP_Query-compatible object.';
        }

        if (self::is_admin_request() || self::is_rest_request() || self::is_cron_request()) {
            return 'Permission/context mismatch: this is not a public frontend search request.';
        }

        if (!self::query_is_search($query)) {
            return 'Non-search request.';
        }

        if (!self::query_is_main($query)) {
            return 'Unsupported query shape: only the main frontend search query is replaced.';
        }

        if (self::query_var_truthy($query, 'suppress_filters')) {
            return 'Unsupported query shape: suppress_filters is enabled.';
        }

        if (self::frontend_search_query_has_unsupported_constraints($query)) {
            return 'Unsupported query shape: the query includes constraints the FTS adapter leaves to WordPress.';
        }

        if (self::frontend_search_query_text($query) === '') {
            return 'Empty search query.';
        }

        return '';
    }

    private static function frontend_search_replacement_bailout_reason(mixed $query): string
    {
        $reason = self::frontend_search_bailout_reason($query);
        if ($reason !== '') {
            return $reason;
        }

        return 'Search replacement is disabled by settings or the wp_fts_replace_frontend_search filter.';
    }

    private static function admin_post_search_bailout_reason(mixed $query): string
    {
        if (!is_object($query)) {
            return 'Unsupported query shape: expected a WP_Query-compatible object.';
        }

        if (!self::is_admin_request() || self::is_rest_request() || self::is_cron_request()) {
            return 'Permission/context mismatch: this is not a wp-admin Posts search request.';
        }

        if (!self::is_admin_post_list_screen()) {
            return 'Permission/context mismatch: the request is not the wp-admin Posts list.';
        }

        if (!self::query_is_search($query)) {
            return 'Non-search request.';
        }

        if (!self::query_is_main($query)) {
            return 'Unsupported query shape: only the main wp-admin Posts list query is replaced.';
        }

        if (self::query_var_truthy($query, 'suppress_filters')) {
            return 'Unsupported query shape: suppress_filters is enabled.';
        }

        if (self::admin_post_search_post_types($query) === []) {
            return 'Unsupported query shape: the requested post type is not indexed for wp-admin Posts search.';
        }

        if (self::query_var($query, 'page', '') === self::ADMIN_PAGE_SLUG) {
            return 'Permission/context mismatch: the plugin admin page search is left to WordPress.';
        }

        if (self::frontend_search_query_has_unsupported_constraints($query)) {
            return 'Unsupported query shape: the query includes constraints the FTS adapter leaves to WordPress.';
        }

        if (self::admin_post_search_has_unsupported_permission_scope($query)) {
            return 'Unsupported query shape: the requested permission scope is not supported.';
        }

        if (self::admin_post_search_has_unsupported_status_view_ordering($query)) {
            return 'Unsupported query shape: the requested status view ordering is not supported.';
        }

        if (
            self::constraint_value_present(self::query_var($query, 'post_status', null))
            && self::admin_post_search_statuses($query) === []
        ) {
            return 'Unsupported query shape: the requested post status is not indexed for wp-admin Posts search.';
        }

        if (self::frontend_search_query_text($query) === '') {
            return 'Empty search query.';
        }

        return '';
    }

    private static function admin_post_search_replacement_bailout_reason(mixed $query): string
    {
        $reason = self::admin_post_search_bailout_reason($query);
        if ($reason !== '') {
            return $reason;
        }

        return 'Search replacement is disabled by settings or the wp_fts_replace_admin_post_search filter.';
    }

    private static function is_frontend_search_query(mixed $query): bool
    {
        if (!is_object($query)) {
            return false;
        }

        if (self::is_admin_request() || self::is_rest_request() || self::is_cron_request()) {
            return false;
        }

        if (!self::query_is_search($query) || !self::query_is_main($query)) {
            return false;
        }

        if (self::query_var_truthy($query, 'suppress_filters')) {
            return false;
        }

        if (self::frontend_search_query_has_unsupported_constraints($query)) {
            return false;
        }

        return self::frontend_search_query_text($query) !== '';
    }

    private static function is_admin_post_search_query(mixed $query): bool
    {
        if (!is_object($query)) {
            return false;
        }

        if (!self::is_admin_request() || self::is_rest_request() || self::is_cron_request()) {
            return false;
        }

        if (!self::is_admin_post_list_screen()) {
            return false;
        }

        if (!self::query_is_search($query) || !self::query_is_main($query)) {
            return false;
        }

        if (self::query_var_truthy($query, 'suppress_filters')) {
            return false;
        }

        if (self::admin_post_search_query_has_unsupported_constraints($query)) {
            return false;
        }

        return self::frontend_search_query_text($query) !== '';
    }

    private static function frontend_search_query_text(mixed $query): string
    {
        $value = self::query_var($query, 's', '');
        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private static function frontend_search_query_has_unsupported_constraints(mixed $query): bool
    {
        foreach (self::frontend_search_unsupported_constraint_vars() as $key) {
            if (self::query_var_has_constraint($query, $key)) {
                return true;
            }
        }

        $post_status = self::query_var($query, 'post_status', null);
        if (self::constraint_value_present($post_status)) {
            $statuses = self::normalize_string_list($post_status);
            if ($statuses !== self::FRONTEND_SEARCH_POST_STATUSES) {
                return true;
            }
        }

        return false;
    }

    private static function admin_post_search_query_has_unsupported_constraints(mixed $query): bool
    {
        if (self::admin_post_search_post_types($query) === []) {
            return true;
        }

        if (self::query_var($query, 'page', '') === self::ADMIN_PAGE_SLUG) {
            return true;
        }

        foreach (self::frontend_search_unsupported_constraint_vars() as $key) {
            if (self::query_var_has_constraint($query, $key)) {
                return true;
            }
        }

        if (self::admin_post_search_has_unsupported_permission_scope($query)) {
            return true;
        }

        if (self::admin_post_search_has_unsupported_status_view_ordering($query)) {
            return true;
        }

        if (
            self::constraint_value_present(self::query_var($query, 'post_status', null))
            && self::admin_post_search_statuses($query) === []
        ) {
            return true;
        }

        return false;
    }

    private static function admin_post_search_has_unsupported_permission_scope(mixed $query): bool
    {
        $perm = self::query_var($query, 'perm', null);
        if (!self::constraint_value_present($perm)) {
            return false;
        }

        return !is_scalar($perm) || trim((string) $perm) !== 'readable';
    }

    /**
     * Allow only the status-tab ordering that wp_edit_posts_query() adds.
     */
    private static function admin_post_search_has_unsupported_status_view_ordering(mixed $query): bool
    {
        $orderby = self::query_var($query, 'orderby', null);
        if (!self::constraint_value_present($orderby)) {
            return false;
        }

        if (!is_scalar($orderby) || trim((string) $orderby) !== 'modified') {
            return true;
        }

        $status = self::admin_post_search_single_requested_status($query);
        if ($status === 'draft') {
            return self::admin_post_search_has_unsupported_order($query, ['DESC']);
        }

        if ($status === 'pending') {
            return self::admin_post_search_has_unsupported_order($query, ['ASC']);
        }

        return true;
    }

    /**
     * @param string[] $allowed
     */
    private static function admin_post_search_has_unsupported_order(mixed $query, array $allowed): bool
    {
        $order = self::query_var($query, 'order', null);
        if (!self::constraint_value_present($order)) {
            return false;
        }

        if (!is_scalar($order)) {
            return true;
        }

        return !in_array(strtoupper(trim((string) $order)), $allowed, true);
    }

    private static function admin_post_search_single_requested_status(mixed $query): string
    {
        $requested = self::query_var($query, 'post_status', null);
        if (!self::constraint_value_present($requested)) {
            return '';
        }

        $statuses = self::normalize_string_list($requested);

        return count($statuses) === 1 ? $statuses[0] : '';
    }

    /**
     * @return string[]
     */
    private static function frontend_search_unsupported_constraint_vars(): array
    {
        return [
            'attachment',
            'attachment_id',
            'author',
            'author__in',
            'author__not_in',
            'author_name',
            'cat',
            'category__and',
            'category__in',
            'category__not_in',
            'category_name',
            'date_query',
            'day',
            'exact',
            'hour',
            'm',
            'meta_compare',
            'meta_key',
            'meta_query',
            'meta_value',
            'meta_value_num',
            'minute',
            'monthnum',
            'name',
            'p',
            'page_id',
            'pagename',
            'post__in',
            'post__not_in',
            'post_mime_type',
            'post_name__in',
            'post_parent',
            'post_parent__in',
            'post_parent__not_in',
            'post_password',
            'sentence',
            'second',
            'search_columns',
            'tag',
            'tag__and',
            'tag__in',
            'tag__not_in',
            'tag_id',
            'tag_slug__and',
            'tag_slug__in',
            'tax_query',
            'taxonomy',
            'term',
            'w',
            'year',
        ];
    }

    private static function query_var_has_constraint(mixed $query, string $key): bool
    {
        return self::constraint_value_present(self::query_var($query, $key, null));
    }

    private static function constraint_value_present(mixed $value): bool
    {
        if ($value === null || $value === false) {
            return false;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::constraint_value_present($item)) {
                    return true;
                }
            }

            return false;
        }

        if (is_string($value)) {
            $value = trim($value);
            return $value !== '' && $value !== '0';
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0;
        }

        return true;
    }

    /**
     * @return array{posts:array<int,object>,snippets:array<int,string>,titles:array<int,string>,total:int,limit:int,query_lang:string}
     */
    private static function frontend_search_result_page(mixed $query, string $search_query, int $trace_id = 0, ?array $settings = null): array
    {
        return self::search_result_page(
            $query,
            $search_query,
            self::frontend_query_post_types($query),
            self::FRONTEND_SEARCH_POST_STATUSES,
            'frontend',
            $trace_id,
            $settings
        );
    }

    /**
     * @return array{posts:array<int,object>,snippets:array<int,string>,titles:array<int,string>,total:int,limit:int,query_lang:string}
     */
    private static function admin_post_search_result_page(mixed $query, string $search_query, int $trace_id = 0, ?array $settings = null): array
    {
        return self::search_result_page(
            $query,
            $search_query,
            self::admin_post_search_post_types($query),
            self::admin_post_search_statuses($query),
            'admin',
            $trace_id,
            $settings
        );
    }

    /**
     * @param string[] $post_types
     * @param string[] $post_statuses
     * @return array{posts:array<int,object>,snippets:array<int,string>,titles:array<int,string>,total:int,limit:int,query_lang:string}
     */
    private static function search_result_page(
        mixed $query,
        string $search_query,
        array $post_types,
        array $post_statuses,
        string $visibility_context,
        int $trace_id = 0,
        ?array $settings = null
    ): array
    {
        $trace_started = microtime(true);
        $limit = self::frontend_query_limit($query);
        $offset = self::frontend_query_offset($query, $limit);
        if ($post_types === [] || $post_statuses === []) {
            self::debug_finish_trace($trace_id, 'bailed', 'Unsupported query shape: no searchable post types or statuses are available.');
            self::debug_add_timing($trace_id, 'total', $trace_started);
            return [
                'posts' => [],
                'snippets' => [],
                'titles' => [],
                'total' => 0,
                'limit' => $limit,
                'query_lang' => '',
            ];
        }

        $settings ??= self::settings();
        $prep_started = microtime(true);
        $searcher = new WP_FTS_Searcher(self::storage(false), self::runtime_analyzer());
        $search_options = [
            'mode' => $settings['match_mode'],
            'limit' => self::visibility_refill_batch_limit(max(1, $limit)),
            'offset' => 0,
            'include_total' => true,
            'include_metadata' => true,
            'include_snippets' => true,
            'highlight' => $settings['highlight'],
            'snippet_length' => $settings['snippet_length'],
            'prefix_matching' => $settings['prefix_matching'],
            'post_type' => $post_types,
            'post_status' => $post_statuses,
            'explain' => $trace_id > 0,
        ];
        $fallback_languages = [];
        if ($settings['language_fallback']) {
            $search_options['language_fallback'] = true;
            $fallback_languages = self::site_fallback_languages();
            $search_options['fallback_languages'] = $fallback_languages;
        }
        $explicit_language = self::query_var($query, 'wp_fts_lang', null);
        $snippet_languages = [];
        if (is_scalar($explicit_language) && trim((string) $explicit_language) !== '') {
            $search_options['lang'] = (string) $explicit_language;
            $search_options['query_lang'] = (string) $explicit_language;
        } else {
            $snippet_languages = self::frontend_auto_search_languages($search_query);
            if ($snippet_languages !== []) {
                $search_options['languages'] = $snippet_languages;
            }
        }
        self::debug_add_timing($trace_id, 'analyzer/query preparation', $prep_started);

        $posts = [];
        $snippets = [];
        $titles = [];
        $visible_total = 0;
        $search_offset = 0;
        $query_lang = '';
        $metadata_total = 0;
        $seen = [];

        while (true) {
            $search_options['offset'] = $search_offset;
            $search_options['limit'] = self::visibility_refill_batch_limit(max(1, $limit));
            if ($search_options['limit'] <= 0) {
                break;
            }

            $search_started = microtime(true);
            $payload = $searcher->search($search_query, $search_options);
            self::debug_add_timing($trace_id, 'storage/search', $search_started);
            self::debug_set_search_explain($trace_id, $payload['explain'] ?? null);
            $rows = is_array($payload['results'] ?? null) ? $payload['results'] : [];
            self::debug_add_count($trace_id, 'search_batches');
            self::debug_add_count($trace_id, 'candidate_rows', count($rows));
            $metadata_total = is_numeric($payload['total'] ?? null) ? (int) $payload['total'] : $metadata_total;
            if (is_scalar($payload['query_lang'] ?? null) && trim((string) $payload['query_lang']) !== '') {
                $query_lang = WP_FTS_TermNamespace::canonicalize_lang((string) $payload['query_lang']);
            }

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $post_id = (int) ($row['post_id'] ?? $row['doc_id'] ?? 0);
                if ($post_id <= 0 || isset($seen[$post_id])) {
                    continue;
                }
                $seen[$post_id] = true;
                self::debug_add_count($trace_id, 'result_ids_considered');

                $visibility_started = microtime(true);
                $visible = $visibility_context === 'admin'
                    ? self::admin_post_result_visible($post_id, $post_types)
                    : self::frontend_post_result_visible($post_id, $post_types);
                self::debug_add_timing($trace_id, 'visibility filtering', $visibility_started);
                if (!$visible) {
                    continue;
                }

                $visible_total++;
                if ($visible_total <= $offset || count($posts) >= $limit) {
                    continue;
                }

                $post = self::post_object($post_id);
                if ($post === null) {
                    continue;
                }

                $posts[] = $post;
                $document_lang = self::frontend_result_language($post_id);
                $result_lang = $document_lang !== '' ? $document_lang : $query_lang;
                $snippet_started = microtime(true);
                $snippet = self::frontend_content_preview_snippet($searcher, $post, $search_query, $query_lang, $result_lang, $snippet_languages);
                self::debug_add_timing($trace_id, 'snippet generation', $snippet_started);
                if ($snippet === '' && isset($row['snippet']) && is_scalar($row['snippet'])) {
                    $snippet = self::sanitize_frontend_snippet_html((string) $row['snippet']);
                }
                if ($snippet !== '') {
                    $snippets[$post_id] = $snippet;
                    self::debug_add_count($trace_id, 'snippets_generated');
                }
                $title_started = microtime(true);
                $title = self::frontend_title_snippet($searcher, $post, $search_query, $query_lang, $result_lang, $snippet_languages);
                self::debug_add_timing($trace_id, 'title highlighting', $title_started);
                if ($title !== '') {
                    $titles[$post_id] = $title;
                    self::debug_add_count($trace_id, 'title_snippets_generated');
                }
            }

            $search_offset += (int) $search_options['limit'];
            if (count($rows) < (int) $search_options['limit'] || ($metadata_total > 0 && $search_offset >= $metadata_total)) {
                break;
            }
        }
        self::debug_set_counts($trace_id, [
            'result_ids_returned' => count($posts),
            'visible_results' => $visible_total,
        ]);
        self::debug_set_query_language($trace_id, $query_lang, $fallback_languages);
        self::debug_add_notes($trace_id, [
            $visibility_context === 'admin'
                ? 'FTS replacement ran for wp-admin post search.'
                : 'FTS replacement ran for frontend search.',
        ]);
        self::debug_add_timing($trace_id, 'total', $trace_started);
        self::debug_finish_trace($trace_id, 'ran');

        return [
            'posts' => $posts,
            'snippets' => $snippets,
            'titles' => $titles,
            'total' => $visible_total,
            'limit' => $limit,
            'query_lang' => $query_lang,
        ];
    }

    /**
     * @param string[] $languages
     */
    private static function frontend_content_preview_snippet(WP_FTS_Searcher $searcher, object $post, string $query, string $query_lang, string $result_lang, array $languages): string
    {
        $content = isset($post->post_content) && is_scalar($post->post_content)
            ? (string) $post->post_content
            : '';
        if (trim($content) === '') {
            return '';
        }

        return self::sanitize_frontend_snippet_html($searcher->snippet_for_text(
            $content,
            $query,
            self::frontend_snippet_options($query_lang, $result_lang, self::settings()['snippet_length'], $languages)
        ));
    }

    /**
     * @param string[] $languages
     */
    private static function frontend_title_snippet(WP_FTS_Searcher $searcher, object $post, string $query, string $query_lang, string $result_lang, array $languages): string
    {
        $title = isset($post->post_title) && is_scalar($post->post_title)
            ? (string) $post->post_title
            : self::post_title(self::post_id_from_value($post));
        if (trim($title) === '') {
            return '';
        }

        return self::sanitize_frontend_snippet_html($searcher->snippet_for_text(
            $title,
            $query,
            self::frontend_snippet_options($query_lang, $result_lang, max(self::settings()['snippet_length'], strlen($title) + 1), $languages)
        ));
    }

    /**
     * @param string[] $languages
     * @return array<string,mixed>
     */
    private static function frontend_snippet_options(string $query_lang, string $result_lang, int $length, array $languages = []): array
    {
        $options = [
            'highlight' => self::settings()['highlight'],
            'snippet_length' => $length,
            'prefix_matching' => self::settings()['prefix_matching'],
        ];
        if (self::settings()['language_fallback']) {
            $options['language_fallback'] = true;
            $options['fallback_languages'] = self::site_fallback_languages();
        }
        $languages = array_values(array_unique(array_filter(
            array_map(static fn(string $language): string => WP_FTS_TermNamespace::canonicalize_lang($language), $languages),
            static fn(string $language): bool => $language !== ''
        )));
        if ($languages !== []) {
            $options['languages'] = $languages;
        }
        if ($query_lang !== '') {
            $options['query_lang'] = $query_lang;
        }
        if ($result_lang !== '') {
            $options['result_lang'] = $result_lang;
        }

        return $options;
    }

    /**
     * @return string[]
     */
    private static function frontend_auto_search_languages(string $query): array
    {
        $languages = [];
        try {
            foreach (self::runtime_analyzer()->analyze_query_occurrences($query, ['return' => 'occurrences']) as $occurrence) {
                if (isset($occurrence['lang']) && is_scalar($occurrence['lang']) && trim((string) $occurrence['lang']) !== '') {
                    $language = WP_FTS_TermNamespace::canonicalize_lang((string) $occurrence['lang']);
                    if ($language !== '') {
                        $languages[$language] = true;
                        break;
                    }
                }
            }
        } catch (Throwable) {
            // Fall through to deterministic defaults.
        }

        $languages[WP_FTS_TermNamespace::DEFAULT_LANG] = true;
        foreach (self::runtime_analyzer_pack_statuses() as $status) {
            if (($status['status'] ?? '') !== 'active') {
                continue;
            }
            $language = WP_FTS_TermNamespace::canonicalize_lang((string) ($status['language'] ?? ''));
            if ($language !== '') {
                $languages[$language] = true;
            }
        }

        return array_keys($languages);
    }

    private static function frontend_result_language(int $post_id): string
    {
        try {
            $doc = self::storage(false)->get_doc($post_id);
        } catch (Throwable) {
            return '';
        }

        foreach ([$doc['primary_lang'] ?? null, $doc['lang'] ?? null] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang((string) $candidate);
            }
        }

        return '';
    }

    /**
     * @param array<int,string> $snippets
     * @param array<int,string> $titles
     */
    private static function store_frontend_search_query_state(mixed $query, int $total, int $limit, string $query_lang, array $snippets, array $titles, int $trace_id = 0): void
    {
        $max_pages = $total > 0 ? (int) ceil($total / max(1, $limit)) : 0;
        $query_key = self::query_object_key($query);
        if ($query_key > 0) {
            self::$front_end_search_query_state = [
                $query_key => [
                    'total' => $total,
                    'max_pages' => $max_pages,
                    'query_lang' => $query_lang,
                    'query_text' => self::frontend_search_query_text($query),
                    'snippets' => $snippets,
                    'titles' => $titles,
                    'trace_id' => $trace_id,
                ],
            ];
        }

        self::set_query_property($query, 'found_posts', $total);
        self::set_query_property($query, 'max_num_pages', $max_pages);
        self::set_query_var($query, 'wp_fts_query_lang', $query_lang);
        self::set_query_var($query, 'wp_fts_found_posts', $total);
    }

    private static function store_admin_post_search_query_state(mixed $query, int $total, int $limit, string $query_lang, int $trace_id = 0): void
    {
        $max_pages = $total > 0 ? (int) ceil($total / max(1, $limit)) : 0;
        $query_key = self::query_object_key($query);
        if ($query_key > 0) {
            self::$admin_post_search_query_state = [
                $query_key => [
                    'total' => $total,
                    'max_pages' => $max_pages,
                    'query_lang' => $query_lang,
                    'trace_id' => $trace_id,
                ],
            ];
        }

        self::set_query_property($query, 'found_posts', $total);
        self::set_query_property($query, 'max_num_pages', $max_pages);
        self::set_query_var($query, 'wp_fts_query_lang', $query_lang);
        self::set_query_var($query, 'wp_fts_found_posts', $total);
    }

    private static function frontend_query_limit(mixed $query): int
    {
        $value = self::query_var($query, 'posts_per_page', self::get_option('posts_per_page', 10));
        $limit = is_numeric($value) ? (int) $value : 10;
        if ($limit === -1) {
            return self::MAX_SEARCH_LIMIT;
        }

        return max(1, $limit);
    }

    private static function frontend_query_offset(mixed $query, int $limit): int
    {
        $offset = self::query_var($query, 'offset', null);
        if (is_numeric($offset)) {
            return max(0, (int) $offset);
        }

        $paged = self::query_var($query, 'paged', self::query_var($query, 'page', 1));
        $page = is_numeric($paged) ? max(1, (int) $paged) : 1;

        return ($page - 1) * max(1, $limit);
    }

    /**
     * @return string[]
     */
    private static function frontend_query_post_types(mixed $query): array
    {
        $configured = array_fill_keys(self::settings()['index_post_types'], true);
        $requested = self::query_var($query, 'post_type', null);
        if ($requested === null || $requested === '' || $requested === 'any') {
            return array_values(array_filter(
                self::public_searchable_post_types(),
                static fn(string $type): bool => isset($configured[$type])
            ));
        }

        $types = [];
        foreach (self::normalize_string_list($requested) as $type) {
            if (isset($configured[$type]) && self::is_public_searchable_post_type($type)) {
                $types[$type] = true;
            }
        }

        return array_keys($types);
    }

    /**
     * @return string[]
     */
    private static function admin_post_search_post_types(mixed $query): array
    {
        $requested = self::query_var($query, 'post_type', 'post');
        if ($requested === null || $requested === '') {
            $requested = 'post';
        }

        $types = self::normalize_string_list($requested);
        if ($types === []) {
            $types = ['post'];
        }

        if ($types !== ['post'] || !self::is_configured_index_post_type('post')) {
            return [];
        }

        return ['post'];
    }

    /**
     * @return string[]
     */
    private static function admin_post_search_statuses(mixed $query): array
    {
        $requested = self::query_var($query, 'post_status', null);
        if (!self::constraint_value_present($requested)) {
            return self::ADMIN_POST_SEARCH_POST_STATUSES;
        }

        $statuses = [];
        foreach (self::normalize_string_list($requested) as $status) {
            if (!in_array($status, self::ADMIN_POST_SEARCH_POST_STATUSES, true)) {
                return [];
            }
            $statuses[$status] = true;
        }

        return array_keys($statuses);
    }

    /**
     * @return string[]
     */
    private static function public_searchable_post_types(): array
    {
        $types = [];
        if (function_exists('get_post_types')) {
            $raw = get_post_types(['public' => true, 'exclude_from_search' => false], 'names');
            if (is_array($raw)) {
                foreach ($raw as $key => $value) {
                    $type = is_scalar($value) ? (string) $value : (is_scalar($key) ? (string) $key : '');
                    if ($type !== '' && self::is_public_searchable_post_type($type)) {
                        $types[$type] = true;
                    }
                }
            }
        }

        if ($types === []) {
            foreach (['post', 'page'] as $type) {
                if (self::is_public_searchable_post_type($type)) {
                    $types[$type] = true;
                }
            }
        }

        return array_keys($types);
    }

    private static function is_public_searchable_post_type(string $type): bool
    {
        $type = trim($type);
        if ($type === '') {
            return false;
        }

        if (!function_exists('get_post_type_object')) {
            return in_array($type, ['post', 'page'], true);
        }

        $post_type = get_post_type_object($type);
        if (!is_object($post_type)) {
            return false;
        }

        if (isset($post_type->public) && !$post_type->public) {
            return false;
        }

        if (isset($post_type->exclude_from_search) && $post_type->exclude_from_search) {
            return false;
        }

        return true;
    }

    /**
     * @param string[] $allowed_post_types
     */
    private static function frontend_post_result_visible(int $post_id, array $allowed_post_types): bool
    {
        $post = self::post_object($post_id);
        if ($post === null || !self::is_public_search_result_post($post)) {
            return false;
        }

        $type = isset($post->post_type) && is_scalar($post->post_type) ? (string) $post->post_type : 'post';

        return in_array($type, $allowed_post_types, true);
    }

    /**
     * @param string[] $allowed_post_types
     */
    private static function admin_post_result_visible(int $post_id, array $allowed_post_types): bool
    {
        $post = self::post_object($post_id);
        if ($post === null || (isset($post->post_password) && (string) $post->post_password !== '')) {
            return false;
        }

        $type = self::post_type_from_object($post);
        if (!in_array($type, $allowed_post_types, true)) {
            return false;
        }

        $status = self::post_status_from_object($post);
        if (!in_array($status, self::ADMIN_POST_SEARCH_POST_STATUSES, true)) {
            return false;
        }

        if ($status === 'publish') {
            return true;
        }

        return self::current_user_can_read_or_edit_post($post_id);
    }

    /**
     * @return string[]
     */
    private static function normalize_string_list(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $result = [];
        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            foreach (explode(',', (string) $item) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $result[$part] = true;
                }
            }
        }

        return array_keys($result);
    }

    private static function query_is_search(mixed $query): bool
    {
        if (is_object($query) && is_callable([$query, 'is_search'])) {
            return (bool) $query->is_search();
        }

        if (is_object($query) && property_exists($query, 'is_search')) {
            return (bool) $query->is_search;
        }

        return self::frontend_search_query_text($query) !== '';
    }

    private static function query_is_main(mixed $query): bool
    {
        if (is_object($query) && is_callable([$query, 'is_main_query'])) {
            return (bool) $query->is_main_query();
        }

        if (is_object($query) && property_exists($query, 'is_main_query')) {
            return (bool) $query->is_main_query;
        }

        return isset($GLOBALS['wp_query']) && $query === $GLOBALS['wp_query'];
    }

    private static function is_admin_post_list_screen(): bool
    {
        if (isset($GLOBALS['pagenow']) && is_scalar($GLOBALS['pagenow']) && trim((string) $GLOBALS['pagenow']) !== '') {
            return (string) $GLOBALS['pagenow'] === 'edit.php';
        }

        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if (is_object($screen) && isset($screen->base) && is_scalar($screen->base)) {
                return (string) $screen->base === 'edit';
            }
        }

        return false;
    }

    private static function query_var(mixed $query, string $key, mixed $default = null): mixed
    {
        if (is_object($query) && is_callable([$query, 'get'])) {
            $value = $query->get($key, $default);
            return $value === null || $value === '' ? $default : $value;
        }

        if (is_object($query) && isset($query->query_vars) && is_array($query->query_vars) && array_key_exists($key, $query->query_vars)) {
            return $query->query_vars[$key];
        }

        if (is_array($query) && array_key_exists($key, $query)) {
            return $query[$key];
        }

        return $default;
    }

    private static function set_query_var(mixed $query, string $key, mixed $value): void
    {
        if (is_object($query) && is_callable([$query, 'set'])) {
            $query->set($key, $value);
            return;
        }

        if (is_object($query) && property_exists($query, 'query_vars') && is_array($query->query_vars)) {
            $query->query_vars[$key] = $value;
        }
    }

    private static function set_query_property(mixed $query, string $property, mixed $value): void
    {
        if (!is_object($query)) {
            return;
        }

        if (property_exists($query, $property) || $query instanceof stdClass) {
            $query->{$property} = $value;
        }
    }

    private static function query_var_truthy(mixed $query, string $key): bool
    {
        $value = self::query_var($query, $key, false);
        if (is_bool($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return !in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'no', 'off'], true);
        }

        return (bool) $value;
    }

    private static function query_object_key(mixed $query): int
    {
        return is_object($query) ? spl_object_id($query) : 0;
    }

    private static function post_id_from_value(mixed $post): int
    {
        if (is_object($post) && isset($post->ID)) {
            return (int) $post->ID;
        }

        if (is_numeric($post)) {
            return (int) $post;
        }

        return 0;
    }

    private static function is_admin_request(): bool
    {
        return function_exists('is_admin') && is_admin();
    }

    private static function is_ajax_request(): bool
    {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return true;
        }

        return function_exists('wp_doing_ajax') && wp_doing_ajax();
    }

    private static function is_rest_request(): bool
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        return function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request();
    }

    private static function is_cron_request(): bool
    {
        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }

        return function_exists('wp_doing_cron') && wp_doing_cron();
    }

    private static function is_cli_request(): bool
    {
        return defined('WP_CLI') && WP_CLI;
    }

    private static function is_bulk_activation_request(): bool
    {
        return self::request_text_value($_GET, 'activate-multi', 20) !== '';
    }

    /**
     * Add a post id to the pending queue without duplicates.
     */
    private static function queue_post(int $post_id): void
    {
        $queue = self::pending_queue();
        if (!in_array($post_id, $queue, true)) {
            $queue[] = $post_id;
            sort($queue, SORT_NUMERIC);
            self::set_option(self::QUEUE_OPTION, $queue);
        }

        self::schedule_queue_processor();
    }

    /**
     * Remove ids that were indexed synchronously from the background queue.
     *
     * @param int[] $post_ids
     */
    private static function remove_from_queue(array $post_ids): void
    {
        $remove = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0) {
                $remove[$post_id] = true;
            }
        }
        if ($remove === []) {
            return;
        }

        $queue = [];
        foreach (self::pending_queue() as $post_id) {
            if (!isset($remove[$post_id])) {
                $queue[] = $post_id;
            }
        }
        self::set_option(self::QUEUE_OPTION, $queue);
    }

    /**
     * Remove processed IDs from the latest queue state without losing later saves.
     *
     * The queue is stored in an option, so processing cannot claim rows
     * atomically. Re-reading here preserves IDs enqueued after the initial
     * snapshot while still dropping the batch this worker finished.
     *
     * @param int[] $processed
     * @param int[] $snapshot_remaining
     * @return int[]
     */
    private static function finish_queue_batch(array $processed, array $snapshot_remaining): array
    {
        $processed_lookup = [];
        foreach ($processed as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0) {
                $processed_lookup[$post_id] = true;
            }
        }

        $next = [];
        foreach (array_merge($snapshot_remaining, self::pending_queue()) as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0 && !isset($processed_lookup[$post_id])) {
                $next[$post_id] = true;
            }
        }

        $queue = array_keys($next);
        sort($queue, SORT_NUMERIC);
        self::set_option(self::QUEUE_OPTION, $queue);

        return $queue;
    }

    /**
     * @return int[]
     */
    private static function pending_queue(): array
    {
        $raw = self::get_option(self::QUEUE_OPTION, []);
        if (!is_array($raw)) {
            return [];
        }

        $queue = [];
        foreach ($raw as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0) {
                $queue[$post_id] = true;
            }
        }

        $ids = array_keys($queue);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * Run one bounded indexing batch across save-hook queue work and backfill.
     *
     * @param array<string,mixed> $opts Optional caller/test overrides.
     * @return array<string,mixed>
     */
    private static function process_indexing_batch(string $mode, array $opts = []): array
    {
        $mode = $mode === 'cron' ? 'cron' : 'manual';
        $batch_size = self::index_batch_size($mode, $opts);
        $summary = self::default_index_batch_summary($mode, $batch_size);

        $token = self::acquire_index_lock($mode);
        if ($token === null) {
            $summary['skipped_locked'] = true;
            $summary['has_more'] = true;
            self::update_index_health_state($summary);
            if ($mode === 'cron') {
                self::schedule_queue_processor();
            }

            return $summary;
        }

        try {
            $budget = self::index_resource_budget($mode, $opts);
            $analyzer = self::runtime_analyzer();
            self::process_queue_for_index_batch($batch_size, $budget, $summary, $analyzer);

            $remaining_capacity = max(0, $batch_size - (int) $summary['processed']);
            if ($remaining_capacity > 0 && !self::index_resource_budget_exhausted($budget, (int) $summary['processed'])) {
                self::process_backfill_for_index_batch($remaining_capacity, $budget, $summary, $analyzer);
            } elseif ($remaining_capacity > 0) {
                $summary['stopped_by_budget'] = true;
                $summary['has_more'] = true;
            }

            if (
                $mode === 'cron'
                && $remaining_capacity === 0
                && empty($summary['has_more'])
                && !self::index_resource_budget_exhausted($budget, (int) $summary['processed'])
                && self::has_eligible_unindexed_content()
            ) {
                $summary['has_more'] = true;
            }

            if (self::pending_queue() !== []) {
                $summary['has_more'] = true;
            }

            self::update_index_health_state($summary);
        } finally {
            self::release_index_lock($token);
        }

        if ($mode === 'cron' && !empty($summary['has_more'])) {
            self::schedule_queue_processor();
        }

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private static function default_index_batch_summary(string $mode, int $batch_size): array
    {
        return [
            'mode' => $mode,
            'batch_size' => $batch_size,
            'processed' => 0,
            'queue_processed' => 0,
            'backfill_processed' => 0,
            'has_more' => false,
            'skipped_locked' => false,
            'stopped_by_budget' => false,
            'last_indexed_post_id' => 0,
            'last_indexed_post_title' => '',
            'last_indexed_at' => '',
        ];
    }

    /**
     * @param array<string,mixed> $budget
     * @param array<string,mixed> $summary
     */
    private static function process_queue_for_index_batch(int $limit, array $budget, array &$summary, WP_FTS_Analyzer $analyzer): void
    {
        $queue = self::pending_queue();
        if ($queue === [] || $limit <= 0) {
            return;
        }

        $claimed = array_slice($queue, 0, $limit);
        $remaining = array_slice($queue, count($claimed));
        $processed_ids = [];
        $index = 0;

        for ($index = 0, $count = count($claimed); $index < $count; $index++) {
            if (self::index_resource_budget_exhausted($budget, (int) $summary['processed'])) {
                $summary['stopped_by_budget'] = true;
                $summary['has_more'] = true;
                break;
            }

            $post_id = (int) $claimed[$index];
            $post = self::post_object($post_id);
            if ($post !== null && self::is_indexable_post($post)) {
                self::index_post($post, [], $analyzer);
                self::remember_indexed_post_in_summary($summary, $post);
            } else {
                self::tombstone_post($post_id);
            }

            $processed_ids[] = $post_id;
            $summary['processed'] = (int) $summary['processed'] + 1;
            $summary['queue_processed'] = (int) $summary['queue_processed'] + 1;
        }

        $unprocessed_claimed = array_slice($claimed, $index);
        $queue = self::finish_queue_batch($processed_ids, array_merge($unprocessed_claimed, $remaining));
        if ($queue !== []) {
            $summary['has_more'] = true;
        }
    }

    /**
     * @param array<string,mixed> $budget
     * @param array<string,mixed> $summary
     */
    private static function process_backfill_for_index_batch(int $limit, array $budget, array &$summary, WP_FTS_Analyzer $analyzer): void
    {
        if ($limit <= 0) {
            return;
        }

        $rows = self::select_eligible_unindexed_posts($limit + 1);
        if ($rows === []) {
            return;
        }

        $work = array_slice($rows, 0, $limit);
        $processed_rows = 0;
        foreach ($work as $post) {
            if (self::index_resource_budget_exhausted($budget, (int) $summary['processed'])) {
                $summary['stopped_by_budget'] = true;
                $summary['has_more'] = true;
                break;
            }

            if (self::is_indexable_post($post)) {
                self::index_post($post, [], $analyzer);
                self::remember_indexed_post_in_summary($summary, $post);
            } elseif (isset($post->ID)) {
                self::tombstone_post((int) $post->ID);
            }

            $processed_rows++;
            $summary['processed'] = (int) $summary['processed'] + 1;
            $summary['backfill_processed'] = (int) $summary['backfill_processed'] + 1;
        }

        if (count($rows) > $processed_rows) {
            $summary['has_more'] = true;
        }
    }

    /**
     * @return object[]
     */
    private static function select_eligible_unindexed_posts(int $limit): array
    {
        global $wpdb;

        $limit = max(1, $limit);
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
            return [];
        }

        $post_types = self::configured_backfill_post_types();
        if ($post_types === []) {
            return [];
        }

        [$clauses, $args] = self::eligible_content_clauses_and_args('p', $post_types);

        $posts_table = isset($wpdb->posts) && is_scalar($wpdb->posts)
            ? (string) $wpdb->posts
            : (string) ($wpdb->prefix ?? '') . 'posts';
        $docs_table = (string) ($wpdb->prefix ?? '') . 'fts_docs';
        $args[] = $limit;

        $sql = $wpdb->prepare(
            "SELECT p.ID, p.post_content, p.post_title, p.post_excerpt, p.post_type, p.post_status, p.post_password, p.post_date_gmt, p.post_date
FROM {$posts_table} p
LEFT JOIN {$docs_table} d ON d.doc_id = p.ID AND d.is_deleted = 0
WHERE d.doc_id IS NULL
  AND p.post_password = ''
  AND (" . implode(' OR ', $clauses) . ")
ORDER BY p.ID ASC
LIMIT %d",
            ...$args
        );

        $rows = $wpdb->get_results($sql);
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, static fn(mixed $row): bool => is_object($row)));
    }

    /**
     * @param string[]|null $post_types
     * @return array{0:string[],1:array<int,mixed>}
     */
    private static function eligible_content_clauses_and_args(string $alias = 'p', ?array $post_types = null): array
    {
        $post_types ??= self::configured_backfill_post_types();
        if ($post_types === []) {
            return [[], []];
        }

        $alias = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) === 1 ? $alias : 'p';
        $clauses = [];
        $args = [];

        $public_placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        $clauses[] = "({$alias}.post_status = %s AND {$alias}.post_type IN ({$public_placeholders}))";
        $args[] = 'publish';
        array_push($args, ...$post_types);

        if (in_array('post', $post_types, true)) {
            $status_placeholders = implode(',', array_fill(0, count(self::ADMIN_POST_SEARCH_POST_STATUSES), '%s'));
            $clauses[] = "({$alias}.post_type = %s AND {$alias}.post_status IN ({$status_placeholders}))";
            $args[] = 'post';
            array_push($args, ...self::ADMIN_POST_SEARCH_POST_STATUSES);
        }

        return [$clauses, $args];
    }

    private static function count_eligible_content(): int
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
            return 0;
        }

        [$clauses, $args] = self::eligible_content_clauses_and_args('p');
        if ($clauses === []) {
            return 0;
        }

        $posts_table = isset($wpdb->posts) && is_scalar($wpdb->posts)
            ? (string) $wpdb->posts
            : (string) ($wpdb->prefix ?? '') . 'posts';

        return self::prepared_count(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
FROM {$posts_table} p
WHERE p.post_password = ''
  AND (" . implode(' OR ', $clauses) . ")",
                ...$args
            )
        );
    }

    private static function count_indexed_eligible_content(): int
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
            return 0;
        }

        [$clauses, $args] = self::eligible_content_clauses_and_args('p');
        if ($clauses === []) {
            return 0;
        }

        $posts_table = isset($wpdb->posts) && is_scalar($wpdb->posts)
            ? (string) $wpdb->posts
            : (string) ($wpdb->prefix ?? '') . 'posts';
        $docs_table = (string) ($wpdb->prefix ?? '') . 'fts_docs';

        return self::prepared_count(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
FROM {$posts_table} p
INNER JOIN {$docs_table} d ON d.doc_id = p.ID AND d.is_deleted = 0
WHERE p.post_password = ''
  AND (" . implode(' OR ', $clauses) . ")",
                ...$args
            )
        );
    }

    private static function prepared_count(mixed $statement): int
    {
        global $wpdb;

        $value = isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var')
            ? $wpdb->get_var($statement)
            : 0;

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private static function has_eligible_unindexed_content(): bool
    {
        return self::select_eligible_unindexed_posts(1) !== [];
    }

    /**
     * @return string[]
     */
    private static function configured_backfill_post_types(): array
    {
        $types = [];
        foreach (self::settings()['index_post_types'] as $post_type) {
            $post_type = is_scalar($post_type) ? (string) $post_type : '';
            if ($post_type !== '' && self::is_public_searchable_post_type($post_type)) {
                $types[$post_type] = true;
            }
        }

        $result = array_keys($types);
        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function remember_indexed_post_in_summary(array &$summary, object $post): void
    {
        $post_id = isset($post->ID) ? (int) $post->ID : 0;
        if ($post_id <= 0) {
            return;
        }

        $summary['last_indexed_post_id'] = $post_id;
        $summary['last_indexed_post_title'] = isset($post->post_title) && is_scalar($post->post_title) ? (string) $post->post_title : '';
        $summary['last_indexed_at'] = self::current_gmt_datetime();
    }

    /**
     * @param array<string,mixed> $opts
     */
    private static function index_batch_size(string $mode, array $opts): int
    {
        if (isset($opts['batch_size']) && is_numeric($opts['batch_size'])) {
            return self::clamp_int((int) $opts['batch_size'], 1, self::MAX_MANUAL_INDEX_BATCH_SIZE);
        }

        if ($mode === 'cron') {
            return self::configured_int_constant(
                'WP_FTS_CRON_INDEX_BATCH_SIZE',
                self::DEFAULT_CRON_INDEX_BATCH_SIZE,
                1,
                self::MAX_CRON_INDEX_BATCH_SIZE
            );
        }

        return self::configured_int_constant(
            'WP_FTS_MANUAL_INDEX_BATCH_SIZE',
            self::DEFAULT_MANUAL_INDEX_BATCH_SIZE,
            1,
            self::MAX_MANUAL_INDEX_BATCH_SIZE
        );
    }

    /**
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private static function index_resource_budget(string $mode, array $opts): array
    {
        $default_time = $mode === 'cron' ? self::DEFAULT_CRON_INDEX_TIME_BUDGET : self::DEFAULT_MANUAL_INDEX_TIME_BUDGET;
        $time_budget = isset($opts['time_budget']) && is_numeric($opts['time_budget'])
            ? self::clamp_float((float) $opts['time_budget'], 0.0, self::MAX_INDEX_TIME_BUDGET)
            : self::configured_float_constant(
                $mode === 'cron' ? 'WP_FTS_CRON_INDEX_TIME_BUDGET' : 'WP_FTS_MANUAL_INDEX_TIME_BUDGET',
                $default_time,
                0.01,
                self::MAX_INDEX_TIME_BUDGET
            );

        return [
            'deadline' => microtime(true) + $time_budget,
            'memory_limit' => self::memory_limit_bytes(),
            'memory_margin' => self::configured_int_constant(
                'WP_FTS_INDEX_MEMORY_MARGIN_BYTES',
                self::DEFAULT_INDEX_MEMORY_MARGIN_BYTES,
                1048576,
                self::MAX_INDEX_MEMORY_MARGIN_BYTES
            ),
            'callback' => is_callable($opts['budget_check'] ?? null) ? $opts['budget_check'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $budget
     */
    private static function index_resource_budget_exhausted(array $budget, int $processed): bool
    {
        if (is_callable($budget['callback'] ?? null) && (bool) call_user_func($budget['callback'], $processed)) {
            return true;
        }

        if (isset($budget['deadline']) && is_float($budget['deadline']) && microtime(true) >= $budget['deadline']) {
            return true;
        }

        $memory_limit = isset($budget['memory_limit']) ? (int) $budget['memory_limit'] : 0;
        if ($memory_limit > 0) {
            $memory_margin = isset($budget['memory_margin']) ? max(0, (int) $budget['memory_margin']) : 0;
            if (memory_get_usage(true) + $memory_margin >= $memory_limit) {
                return true;
            }
        }

        return false;
    }

    private static function acquire_index_lock(string $mode): ?string
    {
        $now = time();
        $existing = self::get_option(self::INDEX_LOCK_OPTION, null);
        if (self::lock_payload_active($existing, $now)) {
            return null;
        }
        if ($existing !== null) {
            self::delete_option(self::INDEX_LOCK_OPTION);
        }

        $ttl = self::configured_int_constant('WP_FTS_INDEX_LOCK_TTL', self::DEFAULT_INDEX_LOCK_TTL, 30, 3600);
        $token = bin2hex(random_bytes(12));
        $payload = [
            'token' => $token,
            'mode' => $mode,
            'started_at' => $now,
            'expires_at' => $now + $ttl,
        ];

        if (function_exists('add_option')) {
            return add_option(self::INDEX_LOCK_OPTION, $payload, '', 'no') ? $token : null;
        }

        self::set_option(self::INDEX_LOCK_OPTION, $payload);
        $stored = self::get_option(self::INDEX_LOCK_OPTION, null);

        return is_array($stored) && ($stored['token'] ?? null) === $token ? $token : null;
    }

    private static function release_index_lock(string $token): void
    {
        $lock = self::get_option(self::INDEX_LOCK_OPTION, null);
        if (is_array($lock) && ($lock['token'] ?? null) === $token) {
            self::delete_option(self::INDEX_LOCK_OPTION);
        }
    }

    private static function index_lock_active(): bool
    {
        return self::lock_payload_active(self::get_option(self::INDEX_LOCK_OPTION, null), time());
    }

    /**
     * Return lock state without exposing the lock token.
     *
     * @return array{state:string,active:bool,mode:string,started_at:string,expires_at:string}
     */
    private static function index_lock_status(): array
    {
        $payload = self::get_option(self::INDEX_LOCK_OPTION, null);
        if (!is_array($payload)) {
            return [
                'state' => 'none',
                'active' => false,
                'mode' => '',
                'started_at' => '',
                'expires_at' => '',
            ];
        }

        $now = time();
        $active = self::lock_payload_active($payload, $now);

        return [
            'state' => $active ? 'active' : 'expired',
            'active' => $active,
            'mode' => is_scalar($payload['mode'] ?? null) ? (string) $payload['mode'] : '',
            'started_at' => self::lock_timestamp_display($payload['started_at'] ?? null),
            'expires_at' => self::lock_timestamp_display($payload['expires_at'] ?? null),
        ];
    }

    private static function lock_payload_active(mixed $payload, int $now): bool
    {
        return is_array($payload)
            && isset($payload['expires_at'])
            && is_scalar($payload['expires_at'])
            && (int) $payload['expires_at'] > $now;
    }

    private static function lock_timestamp_display(mixed $value): string
    {
        if (!is_scalar($value) || !is_numeric($value)) {
            return '';
        }

        $timestamp = (int) $value;
        if ($timestamp <= 0) {
            return '';
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @return array<string,mixed>
     */
    private static function index_health_state(): array
    {
        $raw = self::get_option(self::INDEX_HEALTH_OPTION, []);
        if (!is_array($raw)) {
            return self::default_index_health_state();
        }

        $state = array_replace(self::default_index_health_state(), $raw);
        $state['last_batch_processed'] = max(0, (int) $state['last_batch_processed']);
        $state['last_batch_queue_processed'] = max(0, (int) $state['last_batch_queue_processed']);
        $state['last_batch_backfill_processed'] = max(0, (int) $state['last_batch_backfill_processed']);
        $state['last_indexed_post_id'] = max(0, (int) $state['last_indexed_post_id']);
        $state['last_indexed_post_title'] = is_scalar($state['last_indexed_post_title']) ? (string) $state['last_indexed_post_title'] : '';
        $state['last_indexed_at'] = is_scalar($state['last_indexed_at']) ? (string) $state['last_indexed_at'] : '';
        $state['last_run_at'] = is_scalar($state['last_run_at']) ? (string) $state['last_run_at'] : '';
        $state['last_mode'] = is_scalar($state['last_mode']) ? (string) $state['last_mode'] : '';
        $state['has_more'] = (bool) $state['has_more'];
        $state['last_skipped_locked'] = (bool) $state['last_skipped_locked'];
        $state['last_stopped_by_budget'] = (bool) $state['last_stopped_by_budget'];

        return $state;
    }

    /**
     * @return array<string,mixed>
     */
    private static function default_index_health_state(): array
    {
        return [
            'last_batch_processed' => 0,
            'last_batch_queue_processed' => 0,
            'last_batch_backfill_processed' => 0,
            'has_more' => false,
            'last_indexed_post_id' => 0,
            'last_indexed_post_title' => '',
            'last_indexed_at' => '',
            'last_skipped_locked' => false,
            'last_stopped_by_budget' => false,
            'last_mode' => '',
            'last_run_at' => '',
        ];
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function update_index_health_state(array $summary): void
    {
        $state = self::index_health_state();
        $state['last_batch_processed'] = max(0, (int) ($summary['processed'] ?? 0));
        $state['last_batch_queue_processed'] = max(0, (int) ($summary['queue_processed'] ?? 0));
        $state['last_batch_backfill_processed'] = max(0, (int) ($summary['backfill_processed'] ?? 0));
        $state['has_more'] = (bool) ($summary['has_more'] ?? false);
        $state['last_skipped_locked'] = (bool) ($summary['skipped_locked'] ?? false);
        $state['last_stopped_by_budget'] = (bool) ($summary['stopped_by_budget'] ?? false);
        $state['last_mode'] = is_scalar($summary['mode'] ?? null) ? (string) $summary['mode'] : '';
        $state['last_run_at'] = self::current_gmt_datetime();

        if ((int) ($summary['last_indexed_post_id'] ?? 0) > 0) {
            $state['last_indexed_post_id'] = (int) $summary['last_indexed_post_id'];
            $state['last_indexed_post_title'] = is_scalar($summary['last_indexed_post_title'] ?? null) ? (string) $summary['last_indexed_post_title'] : '';
            $state['last_indexed_at'] = is_scalar($summary['last_indexed_at'] ?? null) ? (string) $summary['last_indexed_at'] : self::current_gmt_datetime();
        }

        self::set_option(self::INDEX_HEALTH_OPTION, $state);
    }

    private static function configured_int_constant(string $name, int $default, int $min, int $max): int
    {
        $value = defined($name) ? constant($name) : $default;
        if (!is_numeric($value)) {
            $value = $default;
        }

        return self::clamp_int((int) $value, $min, $max);
    }

    private static function configured_float_constant(string $name, float $default, float $min, float $max): float
    {
        $value = defined($name) ? constant($name) : $default;
        if (!is_numeric($value)) {
            $value = $default;
        }

        return self::clamp_float((float) $value, $min, $max);
    }

    private static function clamp_float(float $value, float $min, float $max): float
    {
        return min($max, max($min, $value));
    }

    private static function memory_limit_bytes(): int
    {
        $raw = ini_get('memory_limit');
        if (!is_string($raw) || trim($raw) === '' || trim($raw) === '-1') {
            return 0;
        }

        $raw = trim($raw);
        $unit = strtolower(substr($raw, -1));
        $number = is_numeric($unit) ? (float) $raw : (float) substr($raw, 0, -1);
        if ($number <= 0) {
            return 0;
        }

        return match ($unit) {
            'g' => (int) ($number * 1073741824),
            'm' => (int) ($number * 1048576),
            'k' => (int) ($number * 1024),
            default => (int) $number,
        };
    }

    private static function current_gmt_datetime(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Index one WordPress post object.
     *
     * @param array<string,mixed> $opts
     */
    private static function index_post(object $post, array $opts = [], ?WP_FTS_Analyzer $analyzer = null): void
    {
        self::maybe_upgrade_schema();
        (new WP_FTS_Indexer(
            self::storage(false),
            $analyzer ?? self::runtime_analyzer(),
            new WP_FTS_PostContentExtractor()
        ))->index_post($post, self::prepare_post_index_options($post, $opts));
    }

    /**
     * Tombstone one post id if it exists in the index.
     */
    private static function tombstone_post(int $post_id): void
    {
        self::maybe_upgrade_schema();
        (new WP_FTS_Indexer(self::storage(false), new WP_FTS_Analyzer()))->delete_document($post_id);
    }

    /**
     * Build a storage backend against the active WordPress database connection.
     */
    private static function mysql_storage(): WP_FTS_Storage_Mysql
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            throw new RuntimeException('Pure PHP FTS requires the WordPress $wpdb global.');
        }

        return new WP_FTS_Storage_Mysql($wpdb);
    }

    /**
     * Ignore revisions/autosaves and invalid ids.
     */
    private static function is_normal_post_id(int $post_id): bool
    {
        if ($post_id <= 0) {
            return false;
        }

        if (function_exists('wp_is_post_revision') && wp_is_post_revision($post_id)) {
            return false;
        }

        if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($post_id)) {
            return false;
        }

        return true;
    }

    /**
     * Resolve a post object from a hook argument or WordPress.
     */
    private static function post_object(int $post_id, ?object $post = null): ?object
    {
        if ($post !== null) {
            return $post;
        }

        if (!function_exists('get_post')) {
            return null;
        }

        $post = get_post($post_id);

        return is_object($post) ? $post : null;
    }

    /**
     * Index public search rows plus post statuses needed by the admin Posts list.
     */
    private static function is_indexable_post(object $post): bool
    {
        if (isset($post->post_password) && (string) $post->post_password !== '') {
            return false;
        }

        return self::is_public_search_result_post($post) || self::is_admin_indexable_post($post);
    }

    private static function is_public_search_result_post(object $post): bool
    {
        if (self::post_status_from_object($post) !== 'publish') {
            return false;
        }

        if (isset($post->post_password) && (string) $post->post_password !== '') {
            return false;
        }

        $type = self::post_type_from_object($post);

        return self::is_configured_index_post_type($type);
    }

    private static function is_admin_indexable_post(object $post): bool
    {
        if (isset($post->post_password) && (string) $post->post_password !== '') {
            return false;
        }

        if (self::post_type_from_object($post) !== 'post' || !self::is_configured_index_post_type('post')) {
            return false;
        }

        $status = self::post_status_from_object($post);
        if (!in_array($status, self::ADMIN_POST_SEARCH_POST_STATUSES, true)) {
            return false;
        }

        return self::is_public_searchable_post_type('post');
    }

    private static function post_status_from_object(object $post): string
    {
        $status = isset($post->post_status) && is_scalar($post->post_status) ? (string) $post->post_status : '';
        if ($status === '' && isset($post->ID) && function_exists('get_post_status')) {
            $status = (string) get_post_status((int) $post->ID);
        }

        return $status;
    }

    private static function post_type_from_object(object $post): string
    {
        return isset($post->post_type) && is_scalar($post->post_type) ? (string) $post->post_type : 'post';
    }

    private static function is_configured_index_post_type(string $type): bool
    {
        return in_array($type, self::settings()['index_post_types'], true)
            && self::is_public_searchable_post_type($type);
    }

    private static function current_user_can_read_or_edit_post(int $post_id): bool
    {
        if (!function_exists('current_user_can')) {
            return false;
        }

        return current_user_can('read_post', $post_id) || current_user_can('edit_post', $post_id);
    }

    private static function is_readable_non_public_search_result_post(int $post_id, object $post): bool
    {
        if (isset($post->post_password) && (string) $post->post_password !== '') {
            return false;
        }

        $status = self::post_status_from_object($post);
        if (!in_array($status, self::ADMIN_POST_SEARCH_POST_STATUSES, true)) {
            return false;
        }

        if (!self::is_public_searchable_post_type(self::post_type_from_object($post))) {
            return false;
        }

        return self::current_user_can_read_or_edit_post($post_id);
    }

    /**
     * Search results expose public searchable posts, or non-public rows readable by the user.
     */
    private static function can_read_post_result(int $post_id): bool
    {
        $post = self::post_object($post_id);
        if ($post === null) {
            return false;
        }

        if (isset($post->post_password) && (string) $post->post_password !== '') {
            return false;
        }

        if (self::is_public_search_result_post($post)) {
            return true;
        }

        return self::is_readable_non_public_search_result_post($post_id, $post);
    }

    /**
     * Schedule one bounded background queue run when WP-Cron is available.
     */
    private static function schedule_queue_processor(): void
    {
        if (!function_exists('wp_schedule_single_event')) {
            return;
        }

        if (function_exists('wp_next_scheduled') && wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }

        wp_schedule_single_event(time() + 60, self::CRON_HOOK);
    }

    /**
     * Clear pending background runs without touching index data.
     */
    private static function clear_scheduled_queue_processor(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    /**
     * Fetch an option through WordPress when available.
     */
    private static function get_option(string $name, mixed $default): mixed
    {
        return function_exists('get_option') ? get_option($name, $default) : $default;
    }

    /**
     * Set an option and fail when WordPress reports that the value did not persist.
     */
    private static function set_option(string $name, mixed $value): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        $updated = update_option($name, $value);
        if (!$updated && self::get_option($name, null) != $value) {
            throw new RuntimeException("Could not update {$name}.");
        }

        if ($name === self::ANALYZER_OPTIONS_OPTION) {
            self::reset_request_caches();
        }
    }

    /**
     * Compare stored WordPress option values without assuming integer storage.
     */
    private static function option_matches_schema_version(mixed $value): bool
    {
        return is_scalar($value) && (int) $value === self::SCHEMA_VERSION;
    }

    private static function schema_version_from_option(mixed $value): int
    {
        return is_scalar($value) && is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * Delete an option if the WordPress option API is present.
     */
    private static function delete_option(string $name): void
    {
        if (function_exists('delete_option')) {
            delete_option($name);
        }
    }

    /**
     * Read one scalar request field, unslash it, sanitize it as text, and bound it.
     *
     * @param array<string,mixed> $source
     */
    private static function request_text_value(array $source, string $key, int $max_length): string
    {
        if (!array_key_exists($key, $source) || !is_scalar($source[$key])) {
            return '';
        }

        $value = self::unslash_scalar($source[$key]);
        $value = self::sanitize_text($value);
        return self::truncate_request_text($value, $max_length);
    }

    private static function request_bool_value(array $source, string $key, bool $default, bool $submitted): bool
    {
        if (!array_key_exists($key, $source)) {
            return $default;
        }

        return self::truthy_admin_value(self::unslash_scalar($source[$key]));
    }

    /**
     * @param string[] $allowed
     * @param string[] $default
     * @return string[]
     */
    private static function request_list_value(array $source, string $key, array $allowed, array $default): array
    {
        if (!array_key_exists($key, $source)) {
            return $default;
        }

        $value = $source[$key];
        if (function_exists('wp_unslash')) {
            $value = wp_unslash($value);
        }

        $allowed_map = array_fill_keys($allowed, true);
        $selected = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = self::sanitize_key((string) $item);
            if ($item !== '' && isset($allowed_map[$item])) {
                $selected[$item] = true;
            }
        }

        $selected = array_keys($selected);
        sort($selected, SORT_STRING);

        return $selected;
    }

    /**
     * @param array<string,mixed> $source
     * @return int[]
     */
    private static function request_id_list_value(array $source, string $key, int $max): array
    {
        if (!array_key_exists($key, $source)) {
            return [];
        }

        $value = $source[$key];
        if (function_exists('wp_unslash')) {
            $value = wp_unslash($value);
        }

        $raw_items = is_array($value) ? $value : preg_split('/[,\s]+/', (string) $value);
        $items = [];
        foreach (is_array($raw_items) ? $raw_items : [] as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = trim((string) $item);
            if (preg_match('/^[1-9][0-9]*$/', $item) !== 1) {
                continue;
            }
            $items[(int) $item] = true;
            if (count($items) >= $max) {
                break;
            }
        }

        return array_keys($items);
    }

    private static function sanitize_date_filter(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : '';
    }

    /**
     * @return string[]
     */
    private static function sandbox_post_status_choices(): array
    {
        return self::ADMIN_POST_SEARCH_POST_STATUSES;
    }

    private static function unslash_scalar(mixed $value): string
    {
        if (function_exists('wp_unslash')) {
            $unslashed = wp_unslash($value);
            if (is_scalar($unslashed)) {
                return (string) $unslashed;
            }
        }

        $value = (string) $value;

        return is_string($value) ? stripslashes($value) : $value;
    }

    private static function sanitize_text(string $value): string
    {
        if (function_exists('sanitize_text_field')) {
            return (string) sanitize_text_field($value);
        }

        return trim(strip_tags($value));
    }

    private static function truncate_request_text(string $value, int $max_length): string
    {
        if ($max_length <= 0 || strlen($value) <= $max_length) {
            return WP_FTS_Utf8::repair($value);
        }

        return WP_FTS_Utf8::truncate_bytes($value, $max_length);
    }

    private static function sanitize_key(string $value): string
    {
        if (function_exists('sanitize_key')) {
            return (string) sanitize_key($value);
        }

        $value = strtolower($value);
        $key = '';
        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $char = $value[$i];
            if (($char >= 'a' && $char <= 'z') || ($char >= '0' && $char <= '9') || $char === '_' || $char === '-') {
                $key .= $char;
            }
        }

        return $key;
    }

    private static function is_wordpress_error(mixed $value): bool
    {
        if (function_exists('is_wp_error')) {
            return is_wp_error($value);
        }

        return class_exists('WP_Error') && $value instanceof WP_Error;
    }

    private static function wordpress_error_message(mixed $value): string
    {
        if (is_object($value) && is_callable([$value, 'get_error_message'])) {
            $message = (string) $value->get_error_message();
            if ($message !== '') {
                return $message;
            }
        }

        return 'WordPress returned an error.';
    }

    private static function esc_html(string $value): string
    {
        if (function_exists('esc_html')) {
            return (string) esc_html($value);
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function esc_attr(string $value): string
    {
        if (function_exists('esc_attr')) {
            return (string) esc_attr($value);
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function esc_url(string $value): string
    {
        if (function_exists('esc_url')) {
            return (string) esc_url($value);
        }

        return self::esc_attr($value);
    }

    private static function esc_html_preserving_marks(string $value): string
    {
        $open = '@@WP_FTS_MARK_OPEN@@';
        $close = '@@WP_FTS_MARK_CLOSE@@';
        $value = str_replace(['<mark>', '</mark>'], [$open, $close], $value);

        return str_replace([$open, $close], ['<mark>', '</mark>'], self::esc_html($value));
    }

    private static function sanitize_frontend_snippet_html(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = self::remove_hidden_frontend_snippet_bodies($value);
        if ($value === '') {
            return '';
        }

        $allowed = self::frontend_snippet_allowed_html();
        if (function_exists('wp_kses')) {
            return (string) wp_kses($value, $allowed);
        }

        return self::sanitize_inline_snippet_fallback($value, array_keys($allowed));
    }

    private static function remove_hidden_frontend_snippet_bodies(string $html): string
    {
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $hidden_tags = 'script|style|noscript|template|iframe|object|embed|svg|math|canvas';

        do {
            $previous = $html;
            $html = preg_replace('/<\s*(' . $hidden_tags . ')\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? $html;
        } while ($html !== $previous);

        $html = preg_replace('/<\s*(' . $hidden_tags . ')\b[^>]*>.*$/is', '', $html) ?? $html;
        $html = preg_replace('/<\s*\/?\s*(' . $hidden_tags . ')\b[^>]*>/is', '', $html) ?? $html;

        return trim($html);
    }

    /**
     * @return array<string,array<string,string[]>>
     */
    private static function frontend_snippet_allowed_html(): array
    {
        return [
            'a' => [
                'href' => [],
                'rel' => [],
                'title' => [],
            ],
            'abbr' => [
                'title' => [],
            ],
            'b' => [],
            'br' => [],
            'cite' => [],
            'code' => [],
            'del' => [],
            'em' => [],
            'i' => [],
            'ins' => [],
            'kbd' => [],
            'mark' => [],
            's' => [],
            'small' => [],
            'span' => [
                'class' => [],
            ],
            'strong' => [],
            'sub' => [],
            'sup' => [],
            'time' => [
                'datetime' => [],
            ],
        ];
    }

    /**
     * Conservative fallback for the test harness and non-WordPress contexts.
     *
     * WordPress installations use wp_kses() above. Without it, keep only a
     * small inline tag set and drop every attribute instead of trying to parse
     * URL-bearing markup.
     *
     * @param string[] $allowed_tags
     */
    private static function sanitize_inline_snippet_fallback(string $html, array $allowed_tags): string
    {
        $allowed = array_fill_keys(array_map('strtolower', $allowed_tags), true);
        $void = ['br' => true];
        $placeholders = [];
        $index = 0;

        $html = preg_replace_callback(
            '/<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/',
            static function (array $match) use (&$placeholders, &$index, $allowed, $void): string {
                $tag = strtolower((string) $match[2]);
                if (!isset($allowed[$tag])) {
                    return '';
                }

                $closing = (string) $match[1] === '/';
                if ($closing && isset($void[$tag])) {
                    return '';
                }

                $safe = $closing ? '</' . $tag . '>' : '<' . $tag . '>';
                $placeholder = '@@WPFTS_SNIPPET_TAG_' . $index++ . '@@';
                $placeholders[$placeholder] = $safe;

                return $placeholder;
            },
            $html
        );

        $html = is_string($html) ? $html : '';
        $escaped = htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', false);

        return strtr($escaped, $placeholders);
    }

    /**
     * Extract a value from a REST request-like object or array.
     */
    private static function request_param(mixed $request, string $key, mixed $default): mixed
    {
        if (is_object($request) && is_callable([$request, 'get_param'])) {
            $value = $request->get_param($key);
            return $value !== null ? $value : $default;
        }

        if (is_array($request) && array_key_exists($key, $request)) {
            return $request[$key];
        }

        if (is_object($request) && isset($request->{$key})) {
            return $request->{$key};
        }

        return $default;
    }

    /**
     * Resolve the REST query alias without letting an empty `q` mask `query`.
     */
    private static function rest_query(mixed $request): string
    {
        foreach (['q', 'query'] as $key) {
            $value = self::request_param($request, $key, null);
            if (is_scalar($value)) {
                $query = self::truncate_request_text(self::sanitize_text(self::unslash_scalar($value)), 200);
                if ($query !== '') {
                    return $query;
                }
            }
        }

        return '';
    }

    /**
     * Return a canonical REST search mode or null when the caller supplied junk.
     */
    private static function rest_mode(mixed $request): ?string
    {
        $mode = self::request_param($request, 'mode', 'OR');
        if (!is_scalar($mode)) {
            return null;
        }

        $mode = strtoupper(trim((string) $mode));
        return in_array($mode, ['OR', 'AND'], true) ? $mode : null;
    }

    /**
     * Build a WordPress-style REST error with a test-friendly fallback shape.
     */
    private static function rest_error(string $code, string $message, int $status): object|array
    {
        if (class_exists('WP_Error')) {
            return new WP_Error($code, $message, ['status' => $status]);
        }

        return [
            'code' => $code,
            'message' => $message,
            'data' => ['status' => $status],
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function send_admin_json_success(array $data, int $status = 200): void
    {
        if (function_exists('wp_send_json_success')) {
            wp_send_json_success($data, $status);
            return;
        }

        self::send_admin_json(['success' => true, 'data' => $data], $status);
    }

    private static function send_admin_json_error(string $message, int $status): void
    {
        if (function_exists('wp_send_json_error')) {
            wp_send_json_error(['message' => $message, 'status' => $status], $status);
            return;
        }

        self::send_admin_json([
            'success' => false,
            'data' => [
                'message' => self::sanitize_text($message),
                'status' => $status,
            ],
        ], $status);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function send_admin_json(array $payload, int $status): void
    {
        if (function_exists('status_header')) {
            status_header($status);
        }
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8', true, $status);
        }

        $json = function_exists('wp_json_encode')
            ? wp_json_encode($payload)
            : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo is_string($json) ? $json : '{"success":false,"data":{"message":"Could not encode JSON response.","status":500}}';
    }

    /**
     * Clamp numeric request options to a bounded integer range.
     */
    private static function clamp_int(mixed $value, int $min, int $max): int
    {
        $number = is_numeric($value) ? (int) $value : $min;

        return min($max, max($min, $number));
    }

    /**
     * Overfetch enough rows to refill after stale hidden documents are filtered.
     */
    private static function visibility_refill_batch_limit(int $limit): int
    {
        $batch = max(self::VISIBILITY_REFILL_MIN_BATCH, $limit * self::VISIBILITY_REFILL_MULTIPLIER);

        return min(self::VISIBILITY_REFILL_MAX_SCAN, $batch);
    }
}
