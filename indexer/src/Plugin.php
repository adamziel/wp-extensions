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
    public const SEARCH_PERFORMANCE_BUDGET_FILTER = 'wp_fts_search_performance_budget';
    public const SEARCH_REPLACEMENT_PRIORITY = 999;
    public const SEARCH_FINAL_OWNERSHIP_OBSERVER_PRIORITY = PHP_INT_MAX;
    public const LANGUAGE_META_KEY = '_wp_fts_index_language';
    public const DEFAULT_BATCH_SIZE = 25;
    public const DEFAULT_CRON_INDEX_BATCH_SIZE = 20;
    public const DEFAULT_MANUAL_INDEX_BATCH_SIZE = 100;
    public const MAX_SEARCH_LIMIT = 50;
    private const DEFAULT_CRON_INDEX_TIME_BUDGET = 10.0;
    private const DEFAULT_MANUAL_INDEX_TIME_BUDGET = 20.0;
    private const DEFAULT_SEARCH_TOTAL_BUDGET_MS = 100.0;
    private const DEFAULT_SEARCH_STORAGE_BUDGET_MS = 50.0;
    private const DEFAULT_INDEX_MEMORY_MARGIN_BYTES = 16777216;
    private const DEFAULT_INDEX_LOCK_TTL = 300;
    private const MAX_INDEX_LOCK_DIAGNOSTIC_SECONDS = 2592000;
    private const MAX_CRON_INDEX_BATCH_SIZE = 500;
    private const MAX_MANUAL_INDEX_BATCH_SIZE = 1000;
    private const MAX_INDEX_TIME_BUDGET = 300.0;
    private const MAX_SEARCH_PERFORMANCE_BUDGET_MS = 60000.0;
    private const MAX_INDEX_MEMORY_MARGIN_BYTES = 536870912;
    private const MAX_INDEX_FAILURE_TITLE_BYTES = 120;
    private const MAX_INDEX_FAILURE_ERROR_BYTES = 240;
    private const MAX_INDEX_DIAGNOSTIC_TEXT_BYTES = 160;
    private const MAX_INDEX_DIAGNOSTIC_ERROR_CLASS_BYTES = 120;
    private const FAILURE_RECOVERY_SCHEMA = 'wp-fts-failure-recovery-v1';
    private const FAILURE_RECOVERY_MAX_ITEMS = 20;
    private const FAILURE_RECOVERY_RECENT_ITEMS = 10;
    private const FAILURE_RECOVERY_MAX_JSON_BYTES = 8192;
    private const FAILURE_RECOVERY_QUARANTINE_AFTER = 3;
    private const FAILURE_RECOVERY_BASE_BACKOFF_SECONDS = 300;
    private const FAILURE_RECOVERY_MAX_BACKOFF_SECONDS = 3600;
    private const SUPPORT_SNAPSHOT_SCHEMA = 'wp-fts-support-snapshot-v1';
    private const SUPPORT_SNAPSHOT_MAX_JSON_BYTES = 32768;
    private const SUPPORT_SNAPSHOT_MAX_DEPTH = 6;
    private const SUPPORT_SNAPSHOT_MAX_LIST_ITEMS = 12;
    private const SUPPORT_SNAPSHOT_MAX_ASSOC_ITEMS = 80;
    private const SUPPORT_SNAPSHOT_PLUGIN_NAME = 'Language FTS';
    private const SUPPORT_SNAPSHOT_PLUGIN_VERSION = '0.1.9';
    private const INDEX_PROFILE_SCHEMA = 'wp-fts-index-profile-v1';
    private const INDEX_PROFILE_INDEXER_SIGNATURE = 'wp-fts-indexer-v2';
    private const RANKING_TUNING_SCHEMA = 'wp-fts-ranking-tuning-v1';
    private const STALE_DEBT_REASON_LABELS = [
        'analyzer_options_changed' => 'Analyzer options changed',
        'field_boosts_changed' => 'Field ranking weights changed',
        'indexed_scope_changed' => 'Indexed content scope changed',
        'index_profile_changed' => 'Index profile changed',
    ];
    private const ADMIN_NONCE_ACTION = 'wp_fts_sandbox_admin_action';
    private const ADMIN_NONCE_FIELD = 'wp_fts_sandbox_nonce';
    private const ADMIN_ACTION_FIELD = 'wp_fts_sandbox_action';
    private const ADMIN_CLEANUP_LEGACY_DEMO_ACTION = 'cleanup_legacy_demo_posts';
    private const LEGACY_DEMO_CREATION_ACTIONS = ['refresh_demo', 'index_demo'];
    private const ADMIN_HEALTH_NONCE_ACTION = 'wp_fts_health_admin_action';
    private const ADMIN_HEALTH_NONCE_FIELD = 'wp_fts_health_nonce';
    private const ADMIN_HEALTH_ACTION_FIELD = 'wp_fts_health_action';
    private const ADMIN_HEALTH_MANUAL_BATCH_ACTION = 'index_next_batch';
    private const ADMIN_HEALTH_REPAIR_SCHEMA_ACTION = 'repair_schema';
    private const ADMIN_HEALTH_SCHEDULE_QUEUE_ACTION = 'schedule_queue';
    private const ADMIN_HEALTH_SUPPORT_SNAPSHOT_ACTION = 'support_snapshot';
    private const ADMIN_ANALYZER_NONCE_ACTION = 'wp_fts_analyzer_packs_admin_action';
    private const ADMIN_ANALYZER_NONCE_FIELD = 'wp_fts_analyzer_packs_nonce';
    private const ADMIN_ANALYZER_ACTION_FIELD = 'wp_fts_analyzer_packs_action';
    private const ADMIN_ANALYZER_SAVE_BUNDLED_ACTION = 'save_bundled_runtime_packs';
    private const ADMIN_ANALYZER_LANGUAGE_FIELD = 'wp_fts_bundled_runtime_lemma_packs';
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
    private const SANDBOX_MATCH_EXPLANATION_TERMS_LIMIT = 6;
    private const SANDBOX_MATCH_EXPLANATION_FIELDS_LIMIT = 6;
    private const SANDBOX_INDEXED_POSTS_PER_PAGE = 10;
    private const SETTINGS_SNIPPET_MIN = 40;
    private const SETTINGS_SNIPPET_MAX = 500;
    private const PREFIX_MIN_LENGTH_MIN = 2;
    private const PREFIX_MIN_LENGTH_MAX = 12;
    private const PREFIX_MIN_LENGTH_DEFAULT = 4;
    private const PREFIX_MAX_TERMS_MIN = 1;
    private const PREFIX_MAX_TERMS_MAX = 256;
    private const PREFIX_MAX_TERMS_DEFAULT = 64;
    private const FIELD_BOOST_MIN = 0.01;
    private const FIELD_BOOST_MAX = 100.0;
    private const RECENCY_BOOST_STRENGTH_MIN = 0.0;
    private const RECENCY_BOOST_STRENGTH_DEFAULT = 0.25;
    private const RECENCY_BOOST_STRENGTH_MAX = 2.0;
    private const RECENCY_BOOST_HALF_LIFE_MIN = 1.0;
    private const RECENCY_BOOST_HALF_LIFE_MAX = 3650.0;
    private const RECENCY_BOOST_HALF_LIFE_DEFAULT = 30.0;
    private const FIELD_BOOST_DEFAULTS = [
        'title' => 5.0,
        'content' => 1.0,
        'excerpt' => 2.0,
        'terms' => 1.5,
        'custom_fields' => 1.0,
        'rendered' => 1.0,
    ];
    private const FIELD_BOOST_LABELS = [
        'title' => [
            'label' => 'Title',
            'description' => 'Matches in the post title.',
        ],
        'content' => [
            'label' => 'Main content',
            'description' => 'Matches in the main saved post content.',
        ],
        'excerpt' => [
            'label' => 'Excerpt',
            'description' => 'Matches in the saved post excerpt.',
        ],
        'terms' => [
            'label' => 'Taxonomy terms',
            'description' => 'Matches in categories, tags, and other taxonomy term names.',
        ],
        'custom_fields' => [
            'label' => 'Selected custom fields',
            'description' => 'Matches in custom fields selected for indexing.',
        ],
        'rendered' => [
            'label' => 'Rendered-only content',
            'description' => 'Matches in block-rendered output that is not already in the saved content.',
        ],
    ];
    private const SEARCH_PROVIDER_COMPATIBILITY_PREFER_FTS = 'prefer_fts';
    private const SEARCH_PROVIDER_COMPATIBILITY_RESPECT_EXISTING = 'respect_existing';
    private const KNOWN_SEARCH_PROVIDER_FAMILIES = [
        'jetpack' => [
            'label' => 'Jetpack Search / Jetpack',
            'plugin_basenames' => [
                'jetpack/jetpack.php',
                'jetpack-search/jetpack-search.php',
            ],
            'classes' => [
                'Jetpack',
                'Jetpack_Search',
                'Automattic\\Jetpack\\Search\\Search',
            ],
            'functions' => [
                'jetpack_search_supported',
            ],
            'option_signals' => [
                [
                    'option' => 'jetpack_active_modules',
                    'contains' => 'search',
                ],
            ],
        ],
        'searchwp' => [
            'label' => 'SearchWP',
            'plugin_basenames' => [
                'searchwp/index.php',
                'searchwp/searchwp.php',
            ],
            'classes' => [
                'SearchWP',
                'SWP_Query',
            ],
            'functions' => [
                'searchwp',
            ],
            'option_signals' => [],
        ],
        'relevanssi' => [
            'label' => 'Relevanssi',
            'plugin_basenames' => [
                'relevanssi/relevanssi.php',
                'relevanssi-premium/relevanssi.php',
                'relevanssi-premium/relevanssi-premium.php',
            ],
            'classes' => [
                'Relevanssi_Search',
            ],
            'functions' => [
                'relevanssi_do_query',
            ],
            'option_signals' => [],
        ],
        'elasticpress' => [
            'label' => 'ElasticPress',
            'plugin_basenames' => [
                'elasticpress/elasticpress.php',
            ],
            'classes' => [
                'ElasticPress',
                'ElasticPress\\Plugin',
            ],
            'functions' => [
                'ep_is_feature_active',
            ],
            'option_signals' => [],
        ],
    ];
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
        'prefix_min_length' => self::PREFIX_MIN_LENGTH_DEFAULT,
        'prefix_max_terms' => self::PREFIX_MAX_TERMS_DEFAULT,
        'result_limit' => 10,
        'language_fallback' => true,
        'field_boosts' => self::FIELD_BOOST_DEFAULTS,
        'recency_boost_strength' => 0.0,
        'recency_boost_half_life_days' => self::RECENCY_BOOST_HALF_LIFE_DEFAULT,
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
    private const DEBUG_MAX_SQL_QUERIES = 8;
    private const DEBUG_MAX_ASSOC_ITEMS = 16;
    private const DEBUG_MAX_TIMING_PHASES = 16;
    private const DEBUG_SEARCH_HOOK = 'posts_pre_query';
    private const DEBUG_SEARCH_FINAL_OWNERSHIP_QUERY_VAR = 'wp_fts_search_final_ownership_trace_id';
    private const DEBUG_MAX_HOOK_CALLBACKS = self::DEBUG_MAX_LIST_ITEMS;
    private const ANALYZER_PACK_STATUS_MATRIX_MAX_ROWS = 64;
    private const FTS_TABLE_SUFFIXES = [
        'fts_terms',
        'fts_postings',
        'fts_docs',
        'fts_doc_lengths',
        'fts_docmeta',
        'fts_meta',
    ];

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
     * @var array<int,array{available:bool,start_index:int,reason:string}>
     */
    private static array $debug_sql_query_starts = [];

    /**
     * @var array<int,array<string,mixed>>
     */
    private static array $search_final_ownership_state = [];

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
     * Same-request flag set only after the Health support snapshot action
     * passes capability and nonce checks.
     */
    private static bool $admin_health_support_snapshot_visible = false;

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
        self::$debug_sql_query_starts = [];
        self::$search_final_ownership_state = [];
        self::$admin_health_support_snapshot_visible = false;
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
        add_action('wp_initialize_site', [self::class, 'handle_site_initialization'], 10, 2);
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
            add_filter('posts_pre_query', [self::class, 'observe_final_search_posts'], self::SEARCH_FINAL_OWNERSHIP_OBSERVER_PRIORITY, 2);
            add_filter('found_posts', [self::class, 'filter_frontend_search_found_posts'], self::SEARCH_REPLACEMENT_PRIORITY, 2);
            add_filter('found_posts', [self::class, 'filter_admin_post_search_found_posts'], self::SEARCH_REPLACEMENT_PRIORITY, 2);
            add_filter('get_the_excerpt', [self::class, 'frontend_search_excerpt'], 10, 2);
            add_filter('the_excerpt', [self::class, 'frontend_search_excerpt'], 10, 1);
            add_filter('the_content', [self::class, 'frontend_search_content'], 20, 1);
            add_filter('the_title', [self::class, 'frontend_search_title'], 10, 2);
            add_filter('render_block', [self::class, 'frontend_search_render_block'], 10, 3);
            add_filter('debug_bar_panels', [self::class, 'register_debug_bar_panel'], 10, 1);
            add_filter('wpmu_drop_tables', [self::class, 'filter_site_deletion_tables'], 10, 2);
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
     * Provision FTS schema for a newly initialized multisite blog without indexing content.
     *
     * @param mixed $site WordPress passes a WP_Site object; tests or older
     *        integration layers may pass a scalar blog id.
     */
    public static function handle_site_initialization(mixed $site, mixed $args = []): void
    {
        $site_id = self::site_id_from_value($site);
        if ($site_id <= 0 || !function_exists('switch_to_blog') || !function_exists('restore_current_blog')) {
            return;
        }

        if (!switch_to_blog($site_id)) {
            return;
        }

        try {
            self::upgrade_schema();
            self::schedule_queue_processor();
        } finally {
            restore_current_blog();
        }
    }

    /**
     * Tell WordPress site deletion which per-site FTS tables belong to a blog.
     *
     * WordPress owns the actual deletion; this filter only contributes table
     * names for the target prefix and preserves/de-duplicates existing entries.
     *
     * @param string[] $tables
     * @return string[]
     */
    public static function filter_site_deletion_tables(array $tables, mixed $site): array
    {
        $site_id = self::site_id_from_value($site);
        if ($site_id <= 0) {
            return self::unique_table_names($tables);
        }

        $prefix = self::site_table_prefix($site_id);
        if ($prefix === '') {
            return self::unique_table_names($tables);
        }

        return self::unique_table_names(array_merge($tables, self::fts_table_names($prefix)));
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
        if (self::uninstall_multisite_options()) {
            return;
        }

        self::uninstall_current_site_options();
    }

    /**
     * @return string[]
     */
    private static function uninstall_option_names(): array
    {
        return [
            self::SCHEMA_VERSION_OPTION,
            self::QUEUE_OPTION,
            self::SANDBOX_DEMO_POSTS_OPTION,
            self::ANALYZER_OPTIONS_OPTION,
            self::SETTINGS_OPTION,
            self::INDEX_LOCK_OPTION,
            self::INDEX_HEALTH_OPTION,
            self::ACTIVATION_REDIRECT_OPTION,
        ];
    }

    /**
     * Clear current-site operational state while retaining indexed data.
     */
    private static function uninstall_current_site_options(): void
    {
        self::clear_scheduled_queue_processor();

        foreach (self::uninstall_option_names() as $option_name) {
            self::delete_option($option_name);
        }
    }

    /**
     * Clear operational state across multisite blogs when the required APIs exist.
     */
    private static function uninstall_multisite_options(): bool
    {
        if (
            !function_exists('is_multisite')
            || !is_multisite()
            || !function_exists('get_sites')
            || !function_exists('switch_to_blog')
            || !function_exists('restore_current_blog')
        ) {
            return false;
        }

        $sites = get_sites([
            'fields' => 'ids',
            'number' => 0,
        ]);
        if (!is_array($sites)) {
            return false;
        }

        $site_ids = [];
        foreach ($sites as $site) {
            $site_id = self::site_id_from_value($site);
            if ($site_id > 0) {
                $site_ids[$site_id] = $site_id;
            }
        }

        if ($site_ids === []) {
            return false;
        }

        $cleaned = false;
        foreach ($site_ids as $site_id) {
            if (!switch_to_blog($site_id)) {
                continue;
            }

            try {
                self::uninstall_current_site_options();
                $cleaned = true;
            } finally {
                restore_current_blog();
            }
        }

        return $cleaned;
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
            self::queue_post($post_id);
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

            self::queue_post($post_id);
            return;
        }

        if ($old_status !== $new_status) {
            self::tombstone_post($post_id);
            self::remove_from_queue([$post_id]);
            self::clear_failed_item_recovery_metadata([$post_id]);
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
        self::remove_from_queue([$post_id]);
        self::clear_failed_item_recovery_metadata([$post_id]);
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
            if (self::failure_recovery_post_blocked($post_id)) {
                continue;
            }

            $post = self::post_object($post_id);
            if ($post !== null && self::is_indexable_post($post)) {
                self::index_post($post, [], self::runtime_analyzer());
                self::clear_failed_item_recovery_metadata([$post_id]);
            } else {
                self::tombstone_post($post_id);
                self::clear_failed_item_recovery_metadata([$post_id]);
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
     * Run a direct index writer under the shared indexing lock.
     *
     * WP-CLI reindex/delete/optimize do not flow through the bounded queue
     * processor, but they still mutate the same index tables. This helper gives
     * those writers the same lock and bounded diagnostics used by cron/manual
     * batches so operators can see when a writer was skipped instead of
     * overlapping another writer.
     *
     * @param callable():mixed $writer
     * @param array<string,mixed> $opts Optional diagnostics hints.
     * @return array{acquired:bool,result:mixed,summary:array<string,mixed>}
     */
    public static function run_index_writer_with_lock(string $source, callable $writer, array $opts = []): array
    {
        $started = microtime(true);
        $source = self::index_writer_source($source);
        $summary = self::default_index_batch_summary('manual', self::index_writer_batch_size($opts));
        $summary['trigger'] = 'manual';
        self::initialize_index_batch_summary($summary, ['source' => $source], $started);

        $token = self::acquire_index_lock($source);
        if ($token === null) {
            $summary['skipped_locked'] = true;
            $summary['has_more'] = true;
            $summary['lock_prevented_work'] = true;
            self::remember_index_batch_stop($summary, 'lock_active');
            self::finalize_index_batch_summary($summary, $started);
            if (!array_key_exists('record_skip', $opts) || (bool) $opts['record_skip']) {
                self::update_index_health_state($summary);
            }

            return [
                'acquired' => false,
                'result' => null,
                'summary' => $summary,
            ];
        }

        $result = null;
        $thrown = null;
        try {
            $result = $writer();
            $summary['processed'] = self::index_writer_processed_count($result, $opts);
        } catch (Throwable $e) {
            $thrown = $e;
            self::remember_index_batch_exception_in_summary($summary, $e);
        } finally {
            self::release_index_lock($token);
            self::finalize_index_batch_summary($summary, $started);
            self::update_index_health_state($summary);
        }

        if ($thrown !== null) {
            throw $thrown;
        }

        return [
            'acquired' => true,
            'result' => $result,
            'summary' => $summary,
        ];
    }

    /**
     * Return compact indexing health state for the later admin dashboard.
     *
     * @return array<string,mixed>
     */
    public static function search_health(): array
    {
        $state = self::index_health_state();
        $state = array_replace($state, self::index_debt_state($state));
        $pending_queue_count = count(self::pending_queue());
        $stale_remaining_count = self::count_stale_debt_remaining_content($state);
        $has_more = false;
        if ($pending_queue_count > 0) {
            $has_more = true;
        } elseif (self::has_eligible_unindexed_content()) {
            $has_more = true;
        } elseif (!empty($state['stale_debt_active'])) {
            $has_more = true;
        }

        $state['pending_queue_count'] = $pending_queue_count;
        $state['stale_debt_remaining_count'] = $stale_remaining_count;
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
        $remaining_count = max(0, $eligible_count - $indexed_count);
        $automatic_remaining_count = self::has_eligible_unindexed_content() ? $remaining_count : 0;
        $queue_processor_schedule = self::queue_processor_schedule_status($health, $automatic_remaining_count);
        $cron_runner = self::cron_runner_status($queue_processor_schedule);
        $last_indexed_post_id = max(0, (int) ($health['last_indexed_post_id'] ?? 0));
        $last_indexed_title = is_scalar($health['last_indexed_post_title'] ?? null)
            ? (string) $health['last_indexed_post_title']
            : '';
        $stale_debt_reasons = self::sanitize_stale_debt_reasons($health['stale_debt_reasons'] ?? []);
        $settings = self::settings();

        return [
            'schema_status' => $schema['status'],
            'schema_version' => $schema['stored_version'],
            'expected_schema_version' => $schema['expected_version'],
            'storage_backend' => self::index_storage_backend_label(),
            'index_profile_hash' => is_scalar($health['index_profile_hash'] ?? null) ? (string) $health['index_profile_hash'] : '',
            'accepted_index_profile_hash' => is_scalar($health['accepted_index_profile_hash'] ?? null) ? (string) $health['accepted_index_profile_hash'] : '',
            'stale_debt_active' => (bool) ($health['stale_debt_active'] ?? false),
            'stale_debt_reasons' => $stale_debt_reasons,
            'stale_debt_created_at' => is_scalar($health['stale_debt_created_at'] ?? null) ? (string) $health['stale_debt_created_at'] : '',
            'stale_debt_updated_at' => is_scalar($health['stale_debt_updated_at'] ?? null) ? (string) $health['stale_debt_updated_at'] : '',
            'stale_debt_processing_profile_hash' => is_scalar($health['stale_debt_processing_profile_hash'] ?? null) ? (string) $health['stale_debt_processing_profile_hash'] : '',
            'stale_debt_cursor_post_id' => max(0, (int) ($health['stale_debt_cursor_post_id'] ?? 0)),
            'stale_debt_processed_count' => max(0, (int) ($health['stale_debt_processed_count'] ?? 0)),
            'stale_debt_remaining_count' => max(0, (int) ($health['stale_debt_remaining_count'] ?? 0)),
            'pending_queue_count' => max(0, (int) ($health['pending_queue_count'] ?? 0)),
            'queue_processor_schedule' => $queue_processor_schedule,
            'cron_runner' => $cron_runner,
            'ranking_tuning' => self::operator_ranking_tuning_status($settings, $health, $stale_debt_reasons),
            'search_provider_compatibility' => self::operator_search_provider_compatibility_status(),
            'language_pack_status' => self::operator_language_pack_status(),
            'failure_recovery' => self::failure_recovery_status(),
            'lock_state' => $lock['state'],
            'lock_active' => (bool) $lock['active'],
            'lock_mode' => $lock['mode'],
            'lock_started_at' => $lock['started_at'],
            'lock_expires_at' => $lock['expires_at'],
            'lock_age_seconds' => $lock['age_seconds'],
            'lock_expires_in_seconds' => $lock['expires_in_seconds'],
            'lock_expired_seconds' => $lock['expired_seconds'],
            'lock_advice' => $lock['advice'],
            'lock' => $lock,
            'has_more' => (bool) ($health['has_more'] ?? false),
            'last_mode' => is_scalar($health['last_mode'] ?? null) ? (string) $health['last_mode'] : '',
            'last_run_at' => is_scalar($health['last_run_at'] ?? null) ? (string) $health['last_run_at'] : '',
            'last_batch_processed' => max(0, (int) ($health['last_batch_processed'] ?? 0)),
            'last_batch_queue_processed' => max(0, (int) ($health['last_batch_queue_processed'] ?? 0)),
            'last_batch_backfill_processed' => max(0, (int) ($health['last_batch_backfill_processed'] ?? 0)),
            'last_batch_stale_processed' => max(0, (int) ($health['last_batch_stale_processed'] ?? 0)),
            'last_skipped_locked' => (bool) ($health['last_skipped_locked'] ?? false),
            'last_stopped_by_budget' => (bool) ($health['last_stopped_by_budget'] ?? false),
            'last_batch_failures' => max(0, (int) ($health['last_batch_failures'] ?? 0)),
            'last_failed_post' => self::failed_post_label($health),
            'last_failed_post_id' => max(0, (int) ($health['last_failed_post_id'] ?? 0)),
            'last_failed_post_title' => is_scalar($health['last_failed_post_title'] ?? null) ? (string) $health['last_failed_post_title'] : '',
            'last_failed_at' => is_scalar($health['last_failed_at'] ?? null) ? (string) $health['last_failed_at'] : '',
            'last_error' => is_scalar($health['last_error'] ?? null) ? (string) $health['last_error'] : '',
            'last_indexed_post' => $last_indexed_post_id > 0
                ? trim($last_indexed_title . ' (ID ' . $last_indexed_post_id . ')')
                : '',
            'last_indexed_post_id' => $last_indexed_post_id,
            'last_indexed_post_title' => $last_indexed_title,
            'last_indexed_at' => is_scalar($health['last_indexed_at'] ?? null) ? (string) $health['last_indexed_at'] : '',
            'eligible_count' => $eligible_count,
            'indexed_count' => $indexed_count,
            'remaining_count' => $remaining_count,
            'latest_batch_diagnostics' => self::latest_index_batch_diagnostics_from_health($health),
        ];
    }

    /**
     * Return bounded failed-item recovery state without mutating queues or index data.
     *
     * @return array<string,mixed>
     */
    public static function failure_recovery_status(int $limit = self::FAILURE_RECOVERY_RECENT_ITEMS, int $post_id = 0): array
    {
        $limit = self::clamp_int($limit, 1, self::FAILURE_RECOVERY_MAX_ITEMS);
        $post_id = max(0, $post_id);
        $records = self::failure_recovery_records_for_display(self::index_health_state()['failure_history'] ?? []);
        if ($post_id > 0) {
            $records = array_values(array_filter(
                $records,
                static fn(array $record): bool => (int) ($record['post_id'] ?? 0) === $post_id
            ));
        }

        $summary = self::failure_recovery_record_summary($records);
        $summary['schema'] = self::FAILURE_RECOVERY_SCHEMA;
        $summary['history_limit'] = self::FAILURE_RECOVERY_MAX_ITEMS;
        $summary['bytes_limit'] = self::FAILURE_RECOVERY_MAX_JSON_BYTES;
        $summary['quarantine_after_attempts'] = self::FAILURE_RECOVERY_QUARANTINE_AFTER;
        $summary['recent_items'] = self::bound_failure_recovery_status_items(array_slice($records, 0, $limit), $summary);
        $summary['advice'] = self::failure_recovery_advice($summary);

        return $summary;
    }

    /**
     * Mark failed items retryable and enqueue them for a later bounded processor pass.
     *
     * @return array<string,mixed>
     */
    public static function retry_failed_item_recovery(int $post_id = 0, int $limit = 1): array
    {
        $selection = self::select_failure_recovery_records_for_action($post_id, $limit);
        if ($selection === []) {
            return self::failure_recovery_action_result('retry', 'no_match', [], 0, 'No matching failed item recovery record was found.');
        }

        $state = self::index_health_state();
        $records = self::failure_recovery_records_by_post_id($state['failure_history'] ?? []);
        $updated = [];
        foreach ($selection as $record) {
            $id = max(0, (int) ($record['post_id'] ?? 0));
            if ($id <= 0 || !isset($records[$id])) {
                continue;
            }

            $records[$id]['status'] = 'retryable';
            $records[$id]['next_retry_at'] = '';
            $updated[] = $records[$id];
        }

        if ($updated === []) {
            return self::failure_recovery_action_result('retry', 'no_match', [], 0, 'No matching failed item recovery record was found.');
        }

        $state['failure_history'] = self::bound_failure_recovery_records(array_values($records));
        self::set_option(self::INDEX_HEALTH_OPTION, $state);

        $queued = 0;
        foreach ($updated as $record) {
            $id = max(0, (int) ($record['post_id'] ?? 0));
            if ($id <= 0) {
                continue;
            }
            self::queue_post($id);
            $queued++;
        }

        return self::failure_recovery_action_result('retry', 'retryable', $updated, $queued, 'Selected failed items were marked retryable and queued for a later bounded indexing pass.');
    }

    /**
     * Clear only failed-item recovery metadata for selected records.
     *
     * @return array<string,mixed>
     */
    public static function clear_failed_item_recovery(int $post_id = 0, int $limit = 1): array
    {
        $selection = self::select_failure_recovery_records_for_action($post_id, $limit);
        if ($selection === []) {
            return self::failure_recovery_action_result('clear', 'no_match', [], 0, 'No matching failed item recovery record was found.');
        }

        $ids = [];
        foreach ($selection as $record) {
            $id = max(0, (int) ($record['post_id'] ?? 0));
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        self::clear_failed_item_recovery_metadata($ids);

        return self::failure_recovery_action_result('clear', 'cleared', $selection, 0, 'Selected failed item recovery metadata was cleared. WordPress posts and indexed rows were not modified.');
    }

    /**
     * Return the effective ranking/search tuning settings without touching
     * analyzer, searcher, schema repair, queues, or index data.
     *
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $health
     * @param string[] $stale_debt_reasons
     * @return array<string,mixed>
     */
    private static function operator_ranking_tuning_status(array $settings, array $health, array $stale_debt_reasons): array
    {
        $field_boosts = self::settings_field_boosts($settings['field_boosts'] ?? []);
        $recency_strength = self::sanitize_recency_boost_strength($settings['recency_boost_strength'] ?? 0.0);
        $recency_half_life = self::sanitize_recency_boost_half_life($settings['recency_boost_half_life_days'] ?? self::RECENCY_BOOST_HALF_LIFE_DEFAULT);
        $stale_debt_active = !empty($health['stale_debt_active']);

        return [
            'schema' => self::RANKING_TUNING_SCHEMA,
            'match_mode' => is_scalar($settings['match_mode'] ?? null) ? (string) $settings['match_mode'] : self::DEFAULT_SETTINGS['match_mode'],
            'prefix_matching' => !empty($settings['prefix_matching']),
            'prefix_min_length' => self::sanitize_prefix_min_length($settings['prefix_min_length'] ?? self::PREFIX_MIN_LENGTH_DEFAULT),
            'prefix_max_terms' => self::sanitize_prefix_max_terms($settings['prefix_max_terms'] ?? self::PREFIX_MAX_TERMS_DEFAULT),
            'field_boosts' => $field_boosts,
            'field_boost_summary' => self::field_boost_summary($field_boosts),
            'recency_boost' => [
                'enabled' => $recency_strength > 0.0,
                'strength' => $recency_strength,
                'half_life_days' => $recency_half_life,
                'summary' => self::recency_boost_summary([
                    'recency_boost_strength' => $recency_strength,
                    'recency_boost_half_life_days' => $recency_half_life,
                ]),
            ],
            'language_fallback_enabled' => !empty($settings['language_fallback']),
            'indexed_post_types' => self::operator_indexed_post_types($settings),
            'result_limit' => self::clamp_int($settings['result_limit'] ?? self::DEFAULT_SETTINGS['result_limit'], 1, self::MAX_SEARCH_LIMIT),
            'snippet_length' => self::clamp_int($settings['snippet_length'] ?? self::DEFAULT_SETTINGS['snippet_length'], self::SETTINGS_SNIPPET_MIN, self::SETTINGS_SNIPPET_MAX),
            'highlight_enabled' => !empty($settings['highlight']),
            'frontend_replacement_enabled' => !empty($settings['replace_frontend_search']),
            'admin_posts_replacement_enabled' => !empty($settings['replace_admin_post_search']),
            'search_provider_compatibility' => self::sanitize_search_provider_compatibility(
                $settings['search_provider_compatibility'] ?? null,
                self::SEARCH_PROVIDER_COMPATIBILITY_PREFER_FTS
            ),
            'index_time_settings' => [
                'indexed_post_types',
                'field_boosts',
            ],
            'query_time_settings' => [
                'match_mode',
                'prefix_matching',
                'prefix_min_length',
                'prefix_max_terms',
                'recency_boost',
                'language_fallback',
                'result_limit',
                'snippet_length',
                'highlight',
                'frontend_replacement',
                'admin_posts_replacement',
                'search_provider_compatibility',
            ],
            'stale_debt_active' => $stale_debt_active,
            'stale_debt_reasons' => $stale_debt_reasons,
            'advice' => self::operator_ranking_tuning_advice($stale_debt_active),
        ];
    }

    /**
     * @param array<string,mixed> $settings
     * @return string[]
     */
    private static function operator_indexed_post_types(array $settings): array
    {
        $allowed = array_fill_keys(self::settings_post_type_choices(), true);
        $post_types = [];
        foreach (is_array($settings['index_post_types'] ?? null) ? $settings['index_post_types'] : [] as $post_type) {
            if (!is_scalar($post_type)) {
                continue;
            }
            $post_type = self::sanitize_key((string) $post_type);
            if ($post_type !== '' && isset($allowed[$post_type])) {
                $post_types[$post_type] = true;
            }
            if (count($post_types) >= self::DEBUG_MAX_LIST_ITEMS) {
                break;
            }
        }

        return array_keys($post_types);
    }

    private static function operator_ranking_tuning_advice(bool $stale_debt_active): string
    {
        if ($stale_debt_active) {
            return 'Stale reindex debt is active; index-time ranking settings may not be fully reflected until bounded reindexing catches up. This status block is read-only and does not process or schedule work.';
        }

        return 'Read-only ranking tuning visibility only; this status block does not run searches, process indexing work, schedule queues, repair schema, or write options.';
    }

    /**
     * Build a bounded, redacted, read-only support artifact for admin handoff.
     *
     * This deliberately reuses existing operator diagnostics and does not run
     * searches, process indexing work, schedule cron, repair schema, write
     * options, or call external providers.
     *
     * @return array<string,mixed>
     */
    public static function support_snapshot(): array
    {
        $operator = self::support_snapshot_redact_value(self::operator_status());
        $snapshot = [
            'schema' => self::SUPPORT_SNAPSHOT_SCHEMA,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'context' => self::support_snapshot_context($operator),
            'operator_status' => $operator,
            'provider_compatibility' => self::support_snapshot_array_section($operator['search_provider_compatibility'] ?? []),
            'language_pack_status' => self::support_snapshot_array_section($operator['language_pack_status'] ?? []),
            'queue_cron_indexing' => self::support_snapshot_queue_cron_indexing($operator),
            'latest_batch_diagnostics' => self::support_snapshot_array_section($operator['latest_batch_diagnostics'] ?? []),
            'current_request_diagnostics' => self::support_snapshot_current_request_diagnostics(),
            'advice' => self::support_snapshot_advice($operator),
            'boundaries' => [
                'read_only' => true,
                'proof_or_certification' => 'not_run',
                'provider_api_calls' => 'not_run',
                'indexing_or_schema_changes' => 'not_run',
                'redaction' => 'local paths, plugin basenames, SQL, tokens, provider payloads, and secret-looking values are omitted or redacted where detected.',
            ],
        ];

        return self::support_snapshot_redact_value($snapshot);
    }

    public static function support_snapshot_json(): string
    {
        $snapshot = self::support_snapshot();
        $json = self::json_encode_support_snapshot($snapshot);
        if (strlen($json) <= self::SUPPORT_SNAPSHOT_MAX_JSON_BYTES) {
            return $json;
        }

        $snapshot['current_request_diagnostics'] = [
            'omitted' => true,
            'reason' => 'The request diagnostics section was omitted to keep the support snapshot bounded.',
        ];
        $snapshot['truncated'] = true;
        $json = self::json_encode_support_snapshot($snapshot);
        if (strlen($json) <= self::SUPPORT_SNAPSHOT_MAX_JSON_BYTES) {
            return $json;
        }

        $snapshot['operator_status'] = [
            'omitted' => true,
            'reason' => 'The full operator status section was omitted to keep the support snapshot bounded.',
        ];
        $snapshot['truncated'] = true;

        return self::json_encode_support_snapshot($snapshot);
    }

    /**
     * @param array<string,mixed> $operator
     * @return array<string,mixed>
     */
    private static function support_snapshot_context(array $operator): array
    {
        $schema = self::schema_status();

        return [
            'plugin' => [
                'name' => self::support_snapshot_plugin_header('Plugin Name', self::SUPPORT_SNAPSHOT_PLUGIN_NAME),
                'version' => self::support_snapshot_plugin_header('Version', self::SUPPORT_SNAPSHOT_PLUGIN_VERSION),
                'source' => 'wordpress-plugin',
            ],
            'runtime' => [
                'php_version' => PHP_VERSION,
                'wordpress_version' => self::support_snapshot_wordpress_version(),
                'site_language' => self::site_language(),
                'storage_backend' => is_scalar($operator['storage_backend'] ?? null) ? (string) $operator['storage_backend'] : '',
            ],
            'schema' => [
                'status' => (string) $schema['status'],
                'stored_version' => max(0, (int) $schema['stored_version']),
                'expected_version' => max(0, (int) $schema['expected_version']),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $operator
     * @return array<string,mixed>
     */
    private static function support_snapshot_queue_cron_indexing(array $operator): array
    {
        return [
            'pending_queue_count' => max(0, (int) ($operator['pending_queue_count'] ?? 0)),
            'remaining_count' => max(0, (int) ($operator['remaining_count'] ?? 0)),
            'has_more' => !empty($operator['has_more']),
            'queue_processor_schedule' => self::support_snapshot_array_section($operator['queue_processor_schedule'] ?? []),
            'cron_runner' => self::support_snapshot_array_section($operator['cron_runner'] ?? []),
            'lock' => self::support_snapshot_array_section($operator['lock'] ?? []),
            'failure_recovery' => self::support_snapshot_array_section($operator['failure_recovery'] ?? []),
            'stale_debt' => [
                'active' => !empty($operator['stale_debt_active']),
                'reasons' => self::support_snapshot_redact_value($operator['stale_debt_reasons'] ?? []),
                'remaining_count' => max(0, (int) ($operator['stale_debt_remaining_count'] ?? 0)),
                'processed_count' => max(0, (int) ($operator['stale_debt_processed_count'] ?? 0)),
            ],
            'latest_batch' => [
                'mode' => is_scalar($operator['last_mode'] ?? null) ? (string) $operator['last_mode'] : '',
                'last_run_at' => is_scalar($operator['last_run_at'] ?? null) ? (string) $operator['last_run_at'] : '',
                'processed' => max(0, (int) ($operator['last_batch_processed'] ?? 0)),
                'queue_processed' => max(0, (int) ($operator['last_batch_queue_processed'] ?? 0)),
                'backfill_processed' => max(0, (int) ($operator['last_batch_backfill_processed'] ?? 0)),
                'stale_processed' => max(0, (int) ($operator['last_batch_stale_processed'] ?? 0)),
                'failures' => max(0, (int) ($operator['last_batch_failures'] ?? 0)),
                'stopped_by_budget' => !empty($operator['last_stopped_by_budget']),
                'skipped_locked' => !empty($operator['last_skipped_locked']),
            ],
        ];
    }

    /**
     * @return array<int,mixed>
     */
    private static function support_snapshot_current_request_diagnostics(): array
    {
        $rows = [];
        foreach (self::debug_traces() as $trace) {
            $rows[] = self::support_snapshot_redact_value($trace);
            if (count($rows) >= self::SUPPORT_SNAPSHOT_MAX_LIST_ITEMS) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $operator
     * @return string[]
     */
    private static function support_snapshot_advice(array $operator): array
    {
        $advice = [];
        if (($operator['schema_status'] ?? '') !== 'current') {
            $advice[] = 'Review the Health schema controls before running indexing work.';
        }
        if (!empty($operator['lock_active']) && is_scalar($operator['lock_advice'] ?? null)) {
            $advice[] = (string) $operator['lock_advice'];
        }

        $schedule = is_array($operator['queue_processor_schedule'] ?? null) ? $operator['queue_processor_schedule'] : [];
        if (is_scalar($schedule['advice'] ?? null) && (string) $schedule['advice'] !== '') {
            $advice[] = (string) $schedule['advice'];
        }

        $runner = is_array($operator['cron_runner'] ?? null) ? $operator['cron_runner'] : [];
        if (is_scalar($runner['advice'] ?? null) && (string) $runner['advice'] !== '') {
            $advice[] = (string) $runner['advice'];
        }

        if (!empty($operator['stale_debt_active'])) {
            $advice[] = 'Stale index debt is active; continue bounded Health indexing batches or let WP-Cron process the queue.';
        } elseif (max(0, (int) ($operator['remaining_count'] ?? 0)) > 0 || max(0, (int) ($operator['pending_queue_count'] ?? 0)) > 0) {
            $advice[] = 'Indexing work remains; continue bounded Health indexing batches or verify the queue processor is running.';
        }

        if (max(0, (int) ($operator['last_batch_failures'] ?? 0)) > 0) {
            $advice[] = 'The latest batch recorded failures; review the redacted latest batch diagnostics before retrying.';
        }
        $failure_recovery = is_array($operator['failure_recovery'] ?? null) ? $operator['failure_recovery'] : [];
        if (max(0, (int) ($failure_recovery['quarantined_count'] ?? 0)) > 0) {
            $advice[] = 'Some failed indexing items are quarantined; inspect `wp fts failed-items --format=json` and use an explicit retry or clear command after investigation.';
        }

        $provider = is_array($operator['search_provider_compatibility'] ?? null) ? $operator['search_provider_compatibility'] : [];
        if (is_scalar($provider['recommendation'] ?? null) && (string) $provider['recommendation'] !== '') {
            $advice[] = (string) $provider['recommendation'];
        }

        $language = is_array($operator['language_pack_status'] ?? null) ? $operator['language_pack_status'] : [];
        if (is_scalar($language['recommendation'] ?? null) && (string) $language['recommendation'] !== '') {
            $advice[] = (string) $language['recommendation'];
        }

        if ($advice === []) {
            $advice[] = 'No immediate action is indicated by the current read-only Health diagnostics.';
        }

        $bounded = [];
        foreach ($advice as $item) {
            $item = self::support_snapshot_redact_string($item, 240);
            if ($item === '' || in_array($item, $bounded, true)) {
                continue;
            }
            $bounded[] = $item;
            if (count($bounded) >= self::SUPPORT_SNAPSHOT_MAX_LIST_ITEMS) {
                break;
            }
        }

        return $bounded;
    }

    /**
     * @return array<string,mixed>
     */
    private static function support_snapshot_array_section(mixed $value): array
    {
        $value = self::support_snapshot_redact_value($value);

        return is_array($value) ? $value : [];
    }

    private static function support_snapshot_redact_value(mixed $value, int $depth = 0, string $key = ''): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return self::support_snapshot_redact_string((string) $value, self::SUPPORT_SNAPSHOT_MAX_JSON_BYTES, $key);
        }
        if (!is_array($value)) {
            return self::debug_truncate_text(get_debug_type($value), 80);
        }
        if ($depth >= self::SUPPORT_SNAPSHOT_MAX_DEPTH) {
            return '[truncated]';
        }

        $is_list = self::debug_is_list($value);
        $limit = $is_list ? self::SUPPORT_SNAPSHOT_MAX_LIST_ITEMS : self::SUPPORT_SNAPSHOT_MAX_ASSOC_ITEMS;
        $redacted = [];
        foreach ($value as $item_key => $item) {
            if (count($redacted) >= $limit) {
                $redacted[$is_list ? count($redacted) : '_truncated'] = true;
                break;
            }
            if ($is_list) {
                $redacted[] = self::support_snapshot_redact_value($item, $depth + 1);
                continue;
            }
            if (!is_scalar($item_key)) {
                continue;
            }
            $safe_key = self::support_snapshot_redact_key((string) $item_key);
            $redacted[$safe_key] = self::support_snapshot_redact_value($item, $depth + 1, $safe_key);
        }

        return $redacted;
    }

    private static function support_snapshot_redact_key(string $key): string
    {
        $key = self::debug_truncate_text($key, 80);
        $key = preg_replace('#[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\.php#i', '[plugin]', $key) ?? $key;

        return $key;
    }

    private static function support_snapshot_redact_string(string $value, int $max_bytes = 240, string $key = ''): string
    {
        $key_lc = strtolower($key);
        if ($key_lc !== '' && preg_match('/(?:token|secret|password|passwd|credential|api[_-]?key|authorization|cookie|nonce)/i', $key_lc) === 1) {
            return '[redacted]';
        }

        $value = self::sanitize_index_failure_text($value, $max_bytes);
        $value = preg_replace('#(?:^|[\s({\[])(?:/[^/\s]+){2,}(?:/[^/\s),;\]}]+)?#', ' [path]', $value) ?? $value;
        $value = preg_replace('#[A-Za-z]:\\\\(?:[^\\\\\s]+\\\\)*[^\\\\\s]+#', '[path]', $value) ?? $value;
        $value = preg_replace('#[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\.php#i', '[plugin]', $value) ?? $value;
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', $value) ?? $value;
        $value = preg_replace('/\b(?:token|secret|password|passwd|credential|api[_-]?key|authorization|cookie)\s*[:=]\s*[^\s,;&]+/i', '[redacted]', $value) ?? $value;
        $value = preg_replace('/\b(?:sk_live|sk_test|xox[baprs]-|AKIA)[A-Za-z0-9._-]+/i', '[redacted]', $value) ?? $value;
        $value = preg_replace('/\bdo-not-expose[A-Za-z0-9._-]*\b/i', '[redacted]', $value) ?? $value;
        $value = self::debug_truncate_text($value, min($max_bytes, self::SUPPORT_SNAPSHOT_MAX_JSON_BYTES));

        return $value;
    }

    private static function support_snapshot_plugin_header(string $header, string $fallback): string
    {
        $path = dirname(__DIR__) . '/indexer.php';
        $source = is_file($path) ? file_get_contents($path, false, null, 0, 8192) : false;
        if (!is_string($source) || $source === '') {
            return $fallback;
        }

        $quoted = preg_quote($header, '/');
        if (preg_match('/^\s*\*\s*' . $quoted . ':\s*(.+)$/mi', $source, $matches) !== 1) {
            return $fallback;
        }

        $value = self::debug_truncate_text((string) $matches[1], 120);

        return $value !== '' ? $value : $fallback;
    }

    private static function support_snapshot_wordpress_version(): string
    {
        if (function_exists('get_bloginfo')) {
            $version = self::debug_truncate_text((string) get_bloginfo('version'), 40);
            if ($version !== '') {
                return $version;
            }
        }

        $version = $GLOBALS['wp_version'] ?? '';

        return is_scalar($version) ? self::debug_truncate_text((string) $version, 40) : '';
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private static function json_encode_support_snapshot(array $snapshot): string
    {
        try {
            $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $json = false;
        }

        return is_string($json) && $json !== '' ? $json : '{"schema":"' . self::SUPPORT_SNAPSHOT_SCHEMA . '","error":"Could not encode support snapshot."}';
    }

    /**
     * Return a bounded, read-only search-provider compatibility summary for
     * operator surfaces. This intentionally uses the existing safe provider
     * advisory and does not inspect callbacks, invoke provider APIs, run
     * searches, or expose plugin basenames.
     *
     * @return array{
     *   mode:string,
     *   mode_label:string,
     *   mode_debug_value:string,
     *   public_site_replacement:string,
     *   public_site_replacement_enabled:bool,
     *   admin_posts_replacement:string,
     *   admin_posts_replacement_enabled:bool,
     *   known_provider_count:int,
     *   known_provider_names:string[],
     *   known_provider_summary:string,
     *   advisory:string,
     *   recommendation:string,
     *   detection_note:string
     * }
     */
    private static function operator_search_provider_compatibility_status(): array
    {
        $settings = self::settings();
        $mode = self::sanitize_search_provider_compatibility(
            $settings['search_provider_compatibility'] ?? null,
            self::SEARCH_PROVIDER_COMPATIBILITY_PREFER_FTS
        );
        $advisory = self::known_search_provider_advisory(array_replace($settings, [
            'search_provider_compatibility' => $mode,
        ]));
        $provider_names = is_array($advisory['provider_names'] ?? null)
            ? self::debug_normalize_list($advisory['provider_names'])
            : [];
        $summary = is_scalar($advisory['summary'] ?? null)
            ? self::debug_truncate_text((string) $advisory['summary'], self::DEBUG_MAX_TEXT_BYTES)
            : self::known_search_provider_summary($provider_names);
        $recommendation = is_scalar($advisory['recommendation'] ?? null)
            ? self::debug_truncate_text((string) $advisory['recommendation'], 240)
            : '';
        $detection_note = is_scalar($advisory['detection_note'] ?? null)
            ? self::debug_truncate_text((string) $advisory['detection_note'], 240)
            : '';

        return [
            'mode' => $mode,
            'mode_label' => self::search_provider_compatibility_label($mode),
            'mode_debug_value' => self::search_provider_compatibility_debug_value($mode),
            'public_site_replacement' => !empty($settings['replace_frontend_search']) ? 'enabled' : 'disabled',
            'public_site_replacement_enabled' => !empty($settings['replace_frontend_search']),
            'admin_posts_replacement' => !empty($settings['replace_admin_post_search']) ? 'enabled' : 'disabled',
            'admin_posts_replacement_enabled' => !empty($settings['replace_admin_post_search']),
            'known_provider_count' => max(0, (int) ($advisory['detected_count'] ?? count($provider_names))),
            'known_provider_names' => $provider_names,
            'known_provider_summary' => $summary,
            'advisory' => trim($summary . ($recommendation !== '' ? '. ' . $recommendation : '')),
            'recommendation' => $recommendation,
            'detection_note' => $detection_note,
        ];
    }

    /**
     * Return bounded, read-only runtime analyzer-pack status for operators.
     *
     * @return array<string,mixed>
     */
    private static function operator_language_pack_status(): array
    {
        $settings = self::settings();
        $site_language = WP_FTS_TermNamespace::canonicalize_lang(self::site_language(), WP_FTS_TermNamespace::DEFAULT_LANG);
        $top_language_config = self::top_language_pack_config_by_language();
        $runtime_support = self::language_support_details($site_language, false);
        $runtime_support_label = self::analyzer_pack_status_matrix_support_label($site_language, $runtime_support, $top_language_config);
        $runtime_support_status = self::operator_language_pack_support_status($runtime_support_label, $runtime_support);
        $raw_matched_language = trim((string) ($runtime_support['matched_language'] ?? ''));
        $matched_language = $raw_matched_language !== ''
            ? WP_FTS_TermNamespace::canonicalize_lang($raw_matched_language, WP_FTS_TermNamespace::DEFAULT_LANG)
            : '';
        $fallback_languages = self::site_fallback_languages();
        $fallback_language_labels = self::operator_language_pack_language_labels($fallback_languages);
        $runtime_statuses = self::runtime_analyzer_pack_statuses();
        $active_statuses = [];
        foreach ($runtime_statuses as $status) {
            if (($status['status'] ?? '') === 'active') {
                $active_statuses[] = $status;
            }
        }
        $active_runtime_packs = self::operator_language_pack_active_runtime_pack_summaries($active_statuses);
        $control_manifests = self::bundled_runtime_lemma_pack_control_manifests();
        $gzip_available = WP_FTS_AnalyzerPackValidator::gzip_available();
        $matrix_rows = self::analyzer_pack_status_matrix_rows();
        $site_action = isset($matrix_rows[0]['action']) && is_scalar($matrix_rows[0]['action'])
            ? (string) $matrix_rows[0]['action']
            : '';
        $recommendation = self::operator_language_pack_recommendation(
            $runtime_support_label,
            $site_action,
            $gzip_available,
            count($control_manifests)
        );

        return [
            'site_language' => $site_language,
            'site_language_label' => self::sandbox_language_display($site_language),
            'runtime_support_status' => $runtime_support_status,
            'runtime_support_label' => $runtime_support_label,
            'runtime_support_full' => (bool) ($runtime_support['full'] ?? false),
            'runtime_support_reason' => self::debug_truncate_text((string) ($runtime_support['reason'] ?? ''), 240),
            'runtime_support' => [
                'status' => $runtime_support_status,
                'label' => $runtime_support_label,
                'full' => (bool) ($runtime_support['full'] ?? false),
                'reason' => self::debug_truncate_text((string) ($runtime_support['reason'] ?? ''), 240),
                'matched_language' => $matched_language,
                'matched_language_label' => $matched_language !== '' ? self::sandbox_language_display($matched_language) : '',
            ],
            'matched_runtime_language' => $matched_language,
            'matched_runtime_language_label' => $matched_language !== '' ? self::sandbox_language_display($matched_language) : '',
            'language_fallback_enabled' => !empty($settings['language_fallback']),
            'fallback_languages' => $fallback_languages,
            'fallback_language_labels' => $fallback_language_labels,
            'fallback_summary' => self::operator_language_pack_fallback_summary($fallback_language_labels, !empty($settings['language_fallback'])),
            'gzip_available' => $gzip_available,
            'gzip_status' => $gzip_available ? 'available' : 'missing',
            'runtime_pack_availability' => self::operator_language_pack_runtime_availability_summary($gzip_available, count($control_manifests)),
            'bundled_runtime_pack_count' => count($control_manifests),
            'active_runtime_pack_count' => count($active_statuses),
            'active_runtime_packs' => $active_runtime_packs,
            'active_runtime_languages' => self::operator_language_pack_active_runtime_language_summaries($active_runtime_packs),
            'unsupported_language_summaries' => self::operator_language_pack_issue_summaries($matrix_rows),
            'recommendation' => $recommendation,
            'status_note' => 'Read-only advisory status. It does not install analyzer packs, change analyzer options, create content, run indexing, or reindex existing content.',
        ];
    }

    /**
     * @param array{label:string,full:bool,reason:string,matched_language:string} $support
     */
    private static function operator_language_pack_support_status(string $support_label, array $support): string
    {
        if (!empty($support['full'])) {
            return 'full';
        }
        if ($support_label === 'Tokenizer-only support' || ($support['label'] ?? '') === 'Tokenizer pack') {
            return 'tokenizer';
        }
        if (($support['label'] ?? '') === 'Fixture morphology') {
            return 'fixture';
        }

        return 'fallback';
    }

    /**
     * @param string[] $languages
     * @return string[]
     */
    private static function operator_language_pack_language_labels(array $languages): array
    {
        $labels = [];
        foreach ($languages as $language) {
            if (!is_scalar($language)) {
                continue;
            }
            $language = WP_FTS_TermNamespace::canonicalize_lang((string) $language, WP_FTS_TermNamespace::DEFAULT_LANG);
            if ($language === '') {
                continue;
            }
            $labels[] = self::sandbox_language_display($language);
            if (count($labels) >= self::DEBUG_MAX_LIST_ITEMS) {
                break;
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param string[] $fallback_language_labels
     */
    private static function operator_language_pack_fallback_summary(array $fallback_language_labels, bool $fallback_enabled): string
    {
        if ($fallback_language_labels === []) {
            return $fallback_enabled ? 'Enabled; no fallback languages detected.' : 'Disabled; no fallback languages detected.';
        }

        return ($fallback_enabled ? 'Enabled' : 'Disabled') . ': ' . implode(', ', $fallback_language_labels);
    }

    private static function operator_language_pack_runtime_availability_summary(bool $gzip_available, int $bundled_pack_count): string
    {
        if ($bundled_pack_count <= 0) {
            return 'No bundled opt-in runtime analyzer packs were found in this plugin install.';
        }
        if (!$gzip_available) {
            return 'PHP gzip stream support is unavailable; bundled gzip-sharded runtime packs cannot be enabled until zlib/gzip support is installed.';
        }

        return 'PHP gzip stream support is available; bundled gzip-sharded runtime packs can be enabled from the analyzer-pack controls or configured externally.';
    }

    /**
     * @param array<int,array{language:string,kind:string,status:string,pack_id:string,fixture_only:bool,reason:string}> $active_statuses
     * @return array<int,array{language:string,language_label:string,kind:string,pack_id:string,fixture_only:bool,scope:string,summary:string}>
     */
    private static function operator_language_pack_active_runtime_pack_summaries(array $active_statuses): array
    {
        $summaries = [];
        foreach ($active_statuses as $status) {
            $language = WP_FTS_TermNamespace::canonicalize_lang((string) ($status['language'] ?? ''), WP_FTS_TermNamespace::DEFAULT_LANG);
            if ($language === '') {
                continue;
            }
            $kind = self::debug_truncate_text((string) ($status['kind'] ?? 'pack'), 40);
            $pack_id = self::debug_truncate_text((string) ($status['pack_id'] ?? ''), 80);
            $fixture_only = !empty($status['fixture_only']);
            $scope = $fixture_only ? 'fixture' : 'full local pack';
            $summary = self::sandbox_language_display($language) . ' ' . $kind . ' - ' . $scope;
            if ($pack_id !== '') {
                $summary .= ' (' . $pack_id . ')';
            }

            $summaries[] = [
                'language' => $language,
                'language_label' => self::sandbox_language_display($language),
                'kind' => $kind,
                'pack_id' => $pack_id,
                'fixture_only' => $fixture_only,
                'scope' => $scope,
                'summary' => self::debug_truncate_text($summary, 160),
            ];
            if (count($summaries) >= self::DEBUG_MAX_LIST_ITEMS) {
                break;
            }
        }

        return $summaries;
    }

    /**
     * @param array<int,array{language:string,language_label:string,kind:string,pack_id:string,fixture_only:bool,scope:string,summary:string}> $active_runtime_packs
     * @return string[]
     */
    private static function operator_language_pack_active_runtime_language_summaries(array $active_runtime_packs): array
    {
        $summaries = [];
        foreach ($active_runtime_packs as $pack) {
            $summary = is_scalar($pack['summary'] ?? null) ? (string) $pack['summary'] : '';
            if ($summary !== '') {
                $summaries[] = self::debug_truncate_text($summary, 160);
            }
        }

        return $summaries;
    }

    /**
     * @param array<int,array{language_label:string,runtime_support:string,runtime_pack:string,sandbox_support:string,requirements:string,action:string}> $matrix_rows
     * @return array<int,array{language_label:string,runtime_support:string,runtime_pack:string,requirements:string,action:string}>
     */
    private static function operator_language_pack_issue_summaries(array $matrix_rows): array
    {
        $site_summary = [];
        $license_summaries = [];
        $other_summaries = [];
        foreach ($matrix_rows as $index => $row) {
            if (!self::operator_language_pack_matrix_row_needs_attention($row)) {
                continue;
            }

            $summary = [
                'language_label' => self::debug_truncate_text((string) ($row['language_label'] ?? ''), 80),
                'runtime_support' => self::debug_truncate_text((string) ($row['runtime_support'] ?? ''), 80),
                'runtime_pack' => self::debug_truncate_text((string) ($row['runtime_pack'] ?? ''), 180),
                'requirements' => self::debug_truncate_text((string) ($row['requirements'] ?? ''), 180),
                'action' => self::debug_truncate_text((string) ($row['action'] ?? ''), 180),
            ];
            if ($index === 0) {
                $site_summary[] = $summary;
                continue;
            }

            $row_text = strtolower($summary['runtime_pack'] . ' ' . $summary['requirements'] . ' ' . $summary['action']);
            if (str_contains($row_text, 'license')) {
                $license_summaries[] = $summary;
                continue;
            }

            $other_summaries[] = $summary;
        }

        return array_slice(array_merge($site_summary, $license_summaries, $other_summaries), 0, self::DEBUG_MAX_LIST_ITEMS);
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function operator_language_pack_matrix_row_needs_attention(array $row): bool
    {
        $runtime_support = (string) ($row['runtime_support'] ?? '');
        if ($runtime_support !== 'Full morphology') {
            return true;
        }

        $summary = strtolower(
            (string) ($row['runtime_pack'] ?? '') . ' ' .
            (string) ($row['requirements'] ?? '') . ' ' .
            (string) ($row['action'] ?? '')
        );
        foreach (['license', 'unsupported', 'missing', 'fallback', 'blocked', 'disabled', 'ignored', 'fixture', 'tokenizer'] as $needle) {
            if (str_contains($summary, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function operator_language_pack_recommendation(string $runtime_support_label, string $site_action, bool $gzip_available, int $bundled_pack_count): string
    {
        $site_action = self::debug_truncate_text($site_action, 200);
        if ($site_action !== '') {
            return $site_action . ' Status is advisory only; run a reindex after analyzer-pack changes.';
        }
        if ($bundled_pack_count > 0 && !$gzip_available) {
            return 'Install or enable PHP zlib/gzip support before using bundled gzip analyzer packs, or configure an external pack and reindex existing content.';
        }
        if ($runtime_support_label === 'Full morphology') {
            return 'Runtime morphology is available for the site language. Reindex existing content after analyzer-pack changes.';
        }

        return 'Configure an external analyzer pack, or accept conservative fallback. Reindex existing content after analyzer-pack changes.';
    }

    /**
     * Return read-only WP-Cron schedule state for the bounded queue processor.
     *
     * @param array<string,mixed> $health
     * @return array{hook:string,scheduled:bool,status:string,next_run_at:string,next_run_delay_seconds:?int,pending_work:bool,advice:string}
     */
    private static function queue_processor_schedule_status(array $health, ?int $remaining_count = null): array
    {
        $pending_work = self::queue_processor_pending_work($health, $remaining_count);
        $base = [
            'hook' => self::CRON_HOOK,
            'scheduled' => false,
            'status' => 'unavailable',
            'next_run_at' => '',
            'next_run_delay_seconds' => null,
            'pending_work' => $pending_work,
            'advice' => 'WP-Cron schedule helpers are unavailable in this context.',
        ];

        if (!function_exists('wp_next_scheduled')) {
            return $base;
        }

        $next = wp_next_scheduled(self::CRON_HOOK);
        $timestamp = self::queue_processor_schedule_timestamp($next);
        if ($timestamp !== null) {
            $base['scheduled'] = true;
            $base['status'] = 'scheduled';
            $base['next_run_at'] = gmdate('Y-m-d\TH:i:s\Z', $timestamp);
            $base['next_run_delay_seconds'] = max(0, $timestamp - time());
            $base['advice'] = 'WP-Cron has a queue processor event scheduled.';

            return $base;
        }

        if ($pending_work) {
            $base['status'] = 'missing';
            $base['advice'] = 'Pending indexing work exists but no WP-Cron queue processor event is scheduled. Use the Health queue processor controls or `wp fts schedule-queue` to restore the background event; `wp fts process-batch --batch_size=100 --time_budget=20` remains a one-pass manual fallback while cron is investigated.';

            return $base;
        }

        $base['status'] = 'not_needed';
        $base['advice'] = 'No pending indexing work is detected, so no queue processor event is needed.';

        return $base;
    }

    /**
     * Schedule a future queue processor run from an explicit operator action.
     *
     * This path is intentionally separate from lifecycle/post-save scheduling:
     * it proves pending work from bounded status inputs and never indexes
     * content, repairs schema, clears hooks, or mutates unrelated options.
     *
     * @return array{status:string,scheduled_now:bool,hook:string,next_run_at:string,next_run_delay_seconds:?int,pending_work:bool,message:string}
     */
    public static function schedule_queue_processor_for_operator(): array
    {
        try {
            $schedule = self::current_queue_processor_schedule_status();

            if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
                return self::queue_processor_schedule_action_result(
                    'unavailable',
                    false,
                    $schedule,
                    'WP-Cron scheduling helpers are unavailable in this context.'
                );
            }

            if (self::queue_processor_schedule_timestamp(wp_next_scheduled(self::CRON_HOOK)) !== null) {
                $schedule = self::current_queue_processor_schedule_status();

                return self::queue_processor_schedule_action_result(
                    'already_scheduled',
                    false,
                    $schedule,
                    'Queue processor event is already scheduled.'
                );
            }

            if (empty($schedule['pending_work'])) {
                return self::queue_processor_schedule_action_result(
                    'not_needed',
                    false,
                    $schedule,
                    'No pending indexing work was detected, so no queue processor event was scheduled.'
                );
            }

            $timestamp = time() + 60;
            $scheduled = wp_schedule_single_event($timestamp, self::CRON_HOOK);
            if (function_exists('is_wp_error') && is_wp_error($scheduled)) {
                $message = is_object($scheduled) && is_callable([$scheduled, 'get_error_message'])
                    ? (string) $scheduled->get_error_message()
                    : 'WordPress returned an error.';

                return self::queue_processor_schedule_action_result(
                    'failed',
                    false,
                    $schedule,
                    'Could not schedule the queue processor event: ' . self::bounded_operator_schedule_message($message)
                );
            }

            if ($scheduled !== true) {
                return self::queue_processor_schedule_action_result(
                    'failed',
                    false,
                    $schedule,
                    'Could not schedule the queue processor event.'
                );
            }

            $next = self::queue_processor_schedule_timestamp(wp_next_scheduled(self::CRON_HOOK)) ?? $timestamp;
            $schedule['scheduled'] = true;
            $schedule['status'] = 'scheduled';
            $schedule['next_run_at'] = gmdate('Y-m-d\TH:i:s\Z', $next);
            $schedule['next_run_delay_seconds'] = max(0, $next - time());

            return self::queue_processor_schedule_action_result(
                'scheduled',
                true,
                $schedule,
                'Queue processor event scheduled. WP-Cron will run it in the background; no content was indexed in this request.'
            );
        } catch (Throwable $e) {
            return self::queue_processor_schedule_action_result(
                'failed',
                false,
                [
                    'hook' => self::CRON_HOOK,
                    'next_run_at' => '',
                    'next_run_delay_seconds' => null,
                    'pending_work' => false,
                ],
                'Could not inspect or schedule the queue processor event: ' . self::bounded_admin_error_message($e)
            );
        }
    }

    /**
     * Return the current queue processor schedule status using the same bounded
     * inputs as operator status and the Health tab.
     *
     * @return array<string,mixed>
     */
    private static function current_queue_processor_schedule_status(): array
    {
        $health = self::search_health();
        $counts = self::search_health_counts();

        return self::queue_processor_schedule_status($health, $counts['remaining']);
    }

    /**
     * @param array<string,mixed> $schedule
     * @return array{status:string,scheduled_now:bool,hook:string,next_run_at:string,next_run_delay_seconds:?int,pending_work:bool,message:string}
     */
    private static function queue_processor_schedule_action_result(string $status, bool $scheduled_now, array $schedule, string $message): array
    {
        $next_run_delay = null;
        if (isset($schedule['next_run_delay_seconds']) && is_numeric($schedule['next_run_delay_seconds'])) {
            $next_run_delay = max(0, (int) $schedule['next_run_delay_seconds']);
        }

        return [
            'status' => $status,
            'scheduled_now' => $scheduled_now,
            'hook' => self::CRON_HOOK,
            'next_run_at' => is_scalar($schedule['next_run_at'] ?? null) ? trim((string) $schedule['next_run_at']) : '',
            'next_run_delay_seconds' => $next_run_delay,
            'pending_work' => !empty($schedule['pending_work']),
            'message' => self::bounded_operator_schedule_message($message),
        ];
    }

    private static function bounded_operator_schedule_message(string $message): string
    {
        $message = preg_replace('/#\d+\s+.*$/s', '', $message) ?? $message;
        $message = preg_replace('/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|TRUNCATE|REPLACE)\b.*$/s', '$1 statement', $message) ?? $message;
        $message = self::debug_truncate_text(self::sanitize_text($message), self::MAX_INDEX_FAILURE_ERROR_BYTES);

        return $message !== '' ? $message : 'No schedule detail available.';
    }

    /**
     * Return read-only guidance for whether traffic-triggered WP-Cron can run.
     *
     * @param array<string,mixed> $queue_processor_schedule
     * @return array{status:string,wp_cron_disabled:bool,alternate_wp_cron:bool,pending_work:bool,advice:string}
     */
    private static function cron_runner_status(array $queue_processor_schedule): array
    {
        $pending_work = !empty($queue_processor_schedule['pending_work']);
        $wp_cron_disabled = defined('DISABLE_WP_CRON') && (bool) DISABLE_WP_CRON;
        $alternate_wp_cron = defined('ALTERNATE_WP_CRON') && (bool) ALTERNATE_WP_CRON;

        if ($wp_cron_disabled) {
            return [
                'status' => 'external_required',
                'wp_cron_disabled' => true,
                'alternate_wp_cron' => $alternate_wp_cron,
                'pending_work' => $pending_work,
                'advice' => $pending_work
                    ? 'DISABLE_WP_CRON is enabled and pending indexing work exists. A scheduled queue event alone is not enough; configure a host/system cron trigger for wp-cron.php or run a bounded fallback such as `wp fts process-batch --batch_size=100 --time_budget=20` until cron is fixed.'
                    : 'DISABLE_WP_CRON is enabled, so normal site traffic will not start WP-Cron. No pending indexing work is detected; keep a host/system cron trigger for wp-cron.php in place for future queue work.',
            ];
        }

        if (!function_exists('wp_next_scheduled')) {
            return [
                'status' => 'unknown',
                'wp_cron_disabled' => false,
                'alternate_wp_cron' => $alternate_wp_cron,
                'pending_work' => $pending_work,
                'advice' => $pending_work
                    ? 'WP-Cron helpers are unavailable in this context, so the runner mode cannot be confirmed. Pending indexing work exists; verify a host/system cron trigger for wp-cron.php or run `wp fts process-batch --batch_size=100 --time_budget=20` as a bounded fallback.'
                    : 'WP-Cron helpers are unavailable in this context, so the runner mode cannot be confirmed. No pending indexing work is detected.',
            ];
        }

        $advice = $pending_work
            ? 'WP-Cron is traffic-triggered in this environment. Pending indexing work can run when WordPress receives traffic; if the site is low-traffic or batches stall, use a host/system cron trigger for wp-cron.php or `wp fts process-batch --batch_size=100 --time_budget=20` as a bounded fallback.'
            : 'WP-Cron is traffic-triggered and no pending indexing work is detected.';
        if ($alternate_wp_cron) {
            $advice .= ' ALTERNATE_WP_CRON is enabled.';
        }

        return [
            'status' => 'traffic_triggered',
            'wp_cron_disabled' => false,
            'alternate_wp_cron' => $alternate_wp_cron,
            'pending_work' => $pending_work,
            'advice' => $advice,
        ];
    }

    /**
     * @param array<string,mixed> $health
     */
    private static function queue_processor_pending_work(array $health, ?int $remaining_count = null): bool
    {
        return max(0, (int) ($health['pending_queue_count'] ?? 0)) > 0
            || max(0, (int) ($health['stale_debt_remaining_count'] ?? 0)) > 0
            || max(0, (int) ($remaining_count ?? 0)) > 0
            || !empty($health['stale_debt_active'])
            || !empty($health['has_more']);
    }

    private static function queue_processor_schedule_timestamp(mixed $value): ?int
    {
        if (!is_scalar($value) || !is_numeric($value)) {
            return null;
        }

        $timestamp = (int) $value;

        return $timestamp > 0 ? $timestamp : null;
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
     * Clear derived FTS index data and runtime indexing state.
     *
     * WordPress posts, plugin settings, analyzer options, and schema version are
     * intentionally preserved so operators can repopulate with reindex/batches.
     *
     * @return array<string,mixed>
     */
    public static function reset_index(): array
    {
        self::maybe_upgrade_schema();
        $storage = self::storage(false);
        if (!$storage instanceof WP_FTS_Resettable_Storage) {
            throw new RuntimeException('Configured FTS storage does not support index reset.');
        }

        $queue_before = count(self::pending_queue());
        $health_before = self::index_health_state();
        $counts = $storage->reset_index();

        self::set_option(self::QUEUE_OPTION, []);
        self::clear_scheduled_queue_processor();
        self::reset_index_health_state();

        $schema = self::schema_status();

        return [
            'status' => 'reset',
            'reset' => true,
            'schema_status' => $schema['status'],
            'schema_version' => $schema['stored_version'],
            'expected_schema_version' => $schema['expected_version'],
            'storage_backend' => self::index_storage_backend_label(),
            'postings_deleted' => max(0, (int) ($counts['postings_deleted'] ?? 0)),
            'terms_deleted' => max(0, (int) ($counts['terms_deleted'] ?? 0)),
            'docs_deleted' => max(0, (int) ($counts['docs_deleted'] ?? 0)),
            'doc_lengths_deleted' => max(0, (int) ($counts['doc_lengths_deleted'] ?? 0)),
            'doc_metadata_deleted' => max(0, (int) ($counts['doc_metadata_deleted'] ?? 0)),
            'collection_metadata_deleted' => max(0, (int) ($counts['collection_metadata_deleted'] ?? 0)),
            'pending_queue_cleared' => $queue_before,
            'stale_debt_cleared' => (bool) ($health_before['stale_debt_active'] ?? false),
            'last_batch_failures_cleared' => max(0, (int) ($health_before['last_batch_failures'] ?? 0)),
            'failure_recovery_records_cleared' => count(self::sanitize_failure_recovery_records($health_before['failure_history'] ?? [])),
            'wordpress_posts_deleted' => 0,
            'settings_preserved' => true,
            'analyzer_options_preserved' => true,
        ];
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
        foreach (['WP_FTS_DEBUG', 'WP_DEBUG'] as $constant) {
            if (defined($constant) && self::truthy_admin_value(constant($constant))) {
                return true;
            }
        }

        return false;
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
            $evicted_id = array_key_first(self::$debug_traces);
            if ($evicted_id === null) {
                break;
            }
            unset(self::$debug_traces[$evicted_id], self::$debug_sql_query_starts[$evicted_id]);
            self::debug_forget_search_final_ownership_trace((int) $evicted_id);
        }

        $id = self::$debug_next_trace_id++;
        $sql_capture = self::debug_sql_query_capture_start();
        self::$debug_sql_query_starts[$id] = $sql_capture;
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
            'search_hook_pipeline' => [],
            'search_final_ownership' => self::debug_search_final_ownership_unavailable('Final posts_pre_query ownership has not been observed yet.'),
            'search_explain' => [],
            'performance_budget' => [],
            'sql_queries' => self::debug_sql_query_initial_summary($sql_capture),
            'notes' => [],
        ], self::debug_normalize_trace_extra($extra));

        return $id;
    }

    private static function debug_forget_search_final_ownership_trace(int $trace_id): void
    {
        if ($trace_id <= 0 || self::$search_final_ownership_state === []) {
            return;
        }

        foreach (self::$search_final_ownership_state as $query_key => $state) {
            $state_trace_id = is_array($state) && is_numeric($state['trace_id'] ?? null)
                ? (int) $state['trace_id']
                : 0;
            if ($state_trace_id === $trace_id) {
                unset(self::$search_final_ownership_state[$query_key]);
            }
        }
    }

    private static function debug_forget_search_final_ownership_query(int $query_key, mixed $query = null): void
    {
        if ($query_key > 0) {
            unset(self::$search_final_ownership_state[$query_key]);
        }

        if (is_object($query)) {
            self::set_query_var($query, self::DEBUG_SEARCH_FINAL_OWNERSHIP_QUERY_VAR, 0);
        }
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

        self::debug_set_sql_query_summary($trace_id);
        self::$debug_traces[$trace_id]['status'] = self::debug_truncate_text($status, 40);
        if ($reason !== '') {
            self::$debug_traces[$trace_id]['bailout_reason'] = self::debug_truncate_text($reason);
        }
        self::debug_set_performance_budget_summary($trace_id);
    }

    /**
     * @return array{available:bool,start_index:int,reason:string}
     */
    private static function debug_sql_query_capture_start(): array
    {
        $snapshot = self::debug_sql_query_snapshot();
        if (!$snapshot['available']) {
            return [
                'available' => false,
                'start_index' => 0,
                'reason' => $snapshot['reason'],
            ];
        }

        return [
            'available' => true,
            'start_index' => count($snapshot['queries']),
            'reason' => '',
        ];
    }

    /**
     * @param array{available:bool,start_index:int,reason:string} $capture
     * @return array<string,mixed>
     */
    private static function debug_sql_query_initial_summary(array $capture): array
    {
        return [
            'available' => (bool) $capture['available'],
            'finished' => false,
            'captured_count' => 0,
            'shown_count' => 0,
            'total_time_ms' => null,
            'entries' => [],
            'more' => false,
            'reason' => !empty($capture['available'])
                ? 'SQL query capture is pending until this trace finishes.'
                : self::debug_truncate_text((string) $capture['reason']),
        ];
    }

    private static function debug_set_sql_query_summary(int $trace_id): void
    {
        if (!isset(self::$debug_traces[$trace_id])) {
            return;
        }

        $existing = is_array(self::$debug_traces[$trace_id]['sql_queries'] ?? null)
            ? self::$debug_traces[$trace_id]['sql_queries']
            : [];
        if (!empty($existing['finished'])) {
            return;
        }

        $capture = self::$debug_sql_query_starts[$trace_id] ?? [
            'available' => false,
            'start_index' => 0,
            'reason' => 'SQL query capture start state is unavailable.',
        ];
        unset(self::$debug_sql_query_starts[$trace_id]);

        self::$debug_traces[$trace_id]['sql_queries'] = self::debug_sql_query_finish_summary($capture);
    }

    /**
     * @param array{available:bool,start_index:int,reason:string} $capture
     * @return array<string,mixed>
     */
    private static function debug_sql_query_finish_summary(array $capture): array
    {
        if (empty($capture['available'])) {
            return [
                'available' => false,
                'finished' => true,
                'captured_count' => 0,
                'shown_count' => 0,
                'total_time_ms' => null,
                'entries' => [],
                'more' => false,
                'reason' => self::debug_truncate_text((string) ($capture['reason'] ?? 'SQL query capture is unavailable.')),
            ];
        }

        $snapshot = self::debug_sql_query_snapshot();
        if (!$snapshot['available']) {
            return [
                'available' => false,
                'finished' => true,
                'captured_count' => 0,
                'shown_count' => 0,
                'total_time_ms' => null,
                'entries' => [],
                'more' => false,
                'reason' => self::debug_truncate_text('SQL query capture became unavailable before trace finish: ' . $snapshot['reason']),
            ];
        }

        $start_index = max(0, (int) $capture['start_index']);
        $queries = array_slice($snapshot['queries'], min($start_index, count($snapshot['queries'])));
        $captured_count = count($queries);
        $entries = [];
        $total_time_ms = 0.0;
        $has_timing = false;

        foreach ($queries as $query_entry) {
            $entry = self::debug_sql_query_entry($query_entry);
            if ($entry === null) {
                continue;
            }

            if ($entry['time_ms'] !== null) {
                $has_timing = true;
                $total_time_ms += $entry['time_ms'];
            }

            if (count($entries) >= self::DEBUG_MAX_SQL_QUERIES) {
                continue;
            }

            $summary = self::debug_sql_query_entry_summary($entry['sql']);
            if ($summary === '') {
                continue;
            }

            $row = ['summary' => $summary];
            if ($entry['time_ms'] !== null) {
                $row['time_ms'] = round($entry['time_ms'], 3);
            }
            $entries[] = $row;
        }

        $reason = '';
        if ($captured_count === 0) {
            $reason = 'No SQL queries were captured during this trace.';
        } elseif ($entries === []) {
            $reason = 'Captured SQL query entries were not in a compatible format.';
        }

        return [
            'available' => true,
            'finished' => true,
            'captured_count' => $captured_count,
            'shown_count' => count($entries),
            'total_time_ms' => $has_timing ? round($total_time_ms, 3) : null,
            'entries' => $entries,
            'more' => count($entries) < $captured_count,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{available:bool,queries:array<int,mixed>,reason:string}
     */
    private static function debug_sql_query_snapshot(): array
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            return [
                'available' => false,
                'queries' => [],
                'reason' => '$wpdb is unavailable.',
            ];
        }

        try {
            $queries = $wpdb->queries ?? null;
        } catch (Throwable $e) {
            return [
                'available' => false,
                'queries' => [],
                'reason' => '$wpdb->queries could not be read: ' . $e->getMessage(),
            ];
        }

        if (!is_array($queries)) {
            return [
                'available' => false,
                'queries' => [],
                'reason' => '$wpdb->queries is unavailable; enable SAVEQUERIES or provide a compatible debug database object.',
            ];
        }

        return [
            'available' => true,
            'queries' => array_values($queries),
            'reason' => '',
        ];
    }

    /**
     * @return array{sql:string,time_ms:?float}|null
     */
    private static function debug_sql_query_entry(mixed $entry): ?array
    {
        $sql = null;
        $elapsed = null;

        if (is_string($entry)) {
            $sql = $entry;
        } elseif (is_array($entry)) {
            foreach ([0, 'query', 'sql'] as $key) {
                if (isset($entry[$key]) && is_scalar($entry[$key])) {
                    $sql = (string) $entry[$key];
                    break;
                }
            }
            foreach ([1, 'elapsed', 'time', 'duration'] as $key) {
                if (isset($entry[$key]) && is_numeric($entry[$key])) {
                    $elapsed = (float) $entry[$key];
                    break;
                }
            }
        } elseif (is_object($entry)) {
            foreach (['query', 'sql'] as $property) {
                if (isset($entry->{$property}) && is_scalar($entry->{$property})) {
                    $sql = (string) $entry->{$property};
                    break;
                }
            }
            foreach (['elapsed', 'time', 'duration'] as $property) {
                if (isset($entry->{$property}) && is_numeric($entry->{$property})) {
                    $elapsed = (float) $entry->{$property};
                    break;
                }
            }
        }

        $sql = is_string($sql) ? trim($sql) : '';
        if ($sql === '') {
            return null;
        }

        return [
            'sql' => $sql,
            'time_ms' => $elapsed !== null ? max(0.0, $elapsed * 1000.0) : null,
        ];
    }

    private static function debug_sql_query_entry_summary(string $sql): string
    {
        $redacted = self::debug_redact_sql($sql);
        if ($redacted === '') {
            return '';
        }

        $verb = preg_match('/^\s*([a-z]+)/i', $redacted, $matches) === 1 ? strtoupper($matches[1]) : '';
        $tables = self::debug_sql_tables($redacted);
        $prefix = trim($verb . ($tables !== [] ? ' ' . implode('|', $tables) : ''));

        return self::debug_truncate_text(($prefix !== '' ? $prefix . ': ' : '') . $redacted, 240);
    }

    private static function debug_redact_sql(string $sql): string
    {
        $sql = WP_FTS_Utf8::repair($sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '/*?*/', $sql) ?? $sql;
        $sql = preg_replace('/--[^\r\n]*/', '-- ?', $sql) ?? $sql;
        $sql = preg_replace('/#[^\r\n]*/', '# ?', $sql) ?? $sql;
        $sql = preg_replace('/(?:_binary|N|X)?\'(?:\'\'|\\\\.|[^\'\\\\])*\'/i', '?', $sql) ?? $sql;
        $sql = preg_replace('/"(?:\"\"|\\\\.|[^"\\\\])*"/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b0x[0-9a-f]+\b/i', '?', $sql) ?? $sql;
        $sql = preg_replace('/(?<![A-Za-z0-9_])[-+]?(?:\d+\.\d+|\d+)(?![A-Za-z0-9_])/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
        $sql = preg_replace('/\(\s*\?(?:\s*,\s*\?){8,}\s*\)/', '(?, ...)', $sql) ?? $sql;

        return self::debug_truncate_text($sql, 240);
    }

    /**
     * @return string[]
     */
    private static function debug_sql_tables(string $sql): array
    {
        if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+(?:IF\s+NOT\s+EXISTS\s+)?((?:`[^`]+`|[A-Za-z0-9_$]+)(?:\s*\.\s*(?:`[^`]+`|[A-Za-z0-9_$]+))?)/i', $sql, $matches) !== 1) {
            return [];
        }

        $tables = [];
        foreach ($matches[1] as $raw_table) {
            $table = preg_replace('/\s+/', '', (string) $raw_table) ?? (string) $raw_table;
            $table = str_replace('`', '', $table);
            if (str_contains($table, '.')) {
                $parts = explode('.', $table);
                $table = (string) end($parts);
            }
            $table = self::debug_truncate_text($table, 80);
            if ($table !== '') {
                $tables[$table] = true;
            }
            if (count($tables) >= self::DEBUG_MAX_LIST_ITEMS) {
                break;
            }
        }

        return array_keys($tables);
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
        if (is_array(self::$debug_traces[$trace_id]['performance_budget'] ?? null) && self::$debug_traces[$trace_id]['performance_budget'] !== []) {
            self::debug_set_performance_budget_summary($trace_id);
        }
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
     * Remember the bounded value signature that Language FTS expects the late
     * posts_pre_query observer to see for this query.
     */
    private static function debug_remember_search_final_ownership(mixed $query, int $trace_id, string $origin, mixed $expected_posts): void
    {
        if ($trace_id <= 0 || !isset(self::$debug_traces[$trace_id])) {
            return;
        }

        $query_key = self::query_object_key($query);
        if ($query_key <= 0) {
            self::$debug_traces[$trace_id]['search_final_ownership'] = self::debug_search_final_ownership_unavailable(
                'Final ownership cannot be observed because the query object is unavailable.'
            );
            return;
        }

        $expected_signature = self::debug_search_result_signature($expected_posts);
        $origin = self::debug_truncate_text($origin, 80);
        self::$search_final_ownership_state[$query_key] = [
            'trace_id' => $trace_id,
            'origin' => $origin,
            'expected_signature' => $expected_signature,
        ];
        self::set_query_var($query, self::DEBUG_SEARCH_FINAL_OWNERSHIP_QUERY_VAR, $trace_id);
        self::$debug_traces[$trace_id]['search_final_ownership'] = self::debug_search_final_ownership_pending($origin, $expected_signature);
    }

    /**
     * @return array<string,mixed>
     */
    private static function debug_search_final_ownership_pending(string $origin, array $expected_signature): array
    {
        return self::debug_search_final_ownership_row(
            'unavailable',
            'unknown',
            false,
            $origin,
            $expected_signature,
            null,
            'Final posts_pre_query observer has not run yet.'
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function debug_search_final_ownership_unavailable(string $reason, bool $observed = false): array
    {
        return self::debug_search_final_ownership_row(
            'unavailable',
            'unknown',
            $observed,
            'unknown',
            null,
            null,
            $reason
        );
    }

    /**
     * @param array<string,mixed>|null $expected_signature
     * @param array<string,mixed>|null $final_signature
     * @return array<string,mixed>
     */
    private static function debug_search_final_ownership_row(
        string $status,
        string $owner,
        bool $observed,
        string $origin,
        ?array $expected_signature,
        ?array $final_signature,
        string $reason
    ): array {
        $row = [
            'status' => self::debug_truncate_text($status, 80),
            'owner' => self::debug_truncate_text($owner, 80),
            'observed' => $observed,
            'origin' => self::debug_truncate_text($origin, 80),
            'reason' => self::debug_truncate_text($reason, 240),
        ];

        if ($expected_signature !== null) {
            $row['expected_kind'] = (string) ($expected_signature['kind'] ?? 'unknown');
            $row['expected_count'] = max(0, (int) ($expected_signature['count'] ?? 0));
            $row['expected_post_ids'] = self::debug_post_id_sample($expected_signature['post_ids'] ?? []);
            $row['expected_hash'] = self::debug_hash_prefix((string) ($expected_signature['hash'] ?? ''));
        }

        if ($final_signature !== null) {
            $row['final_kind'] = (string) ($final_signature['kind'] ?? 'unknown');
            $row['final_count'] = max(0, (int) ($final_signature['count'] ?? 0));
            $row['final_post_ids'] = self::debug_post_id_sample($final_signature['post_ids'] ?? []);
            $row['final_hash'] = self::debug_hash_prefix((string) ($final_signature['hash'] ?? ''));
        }

        return $row;
    }

    /**
     * @return int[]
     */
    private static function debug_post_id_sample(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }

        $sample = [];
        foreach ($ids as $id) {
            if (!is_numeric($id)) {
                continue;
            }
            $post_id = max(0, (int) $id);
            if ($post_id > 0) {
                $sample[] = $post_id;
            }
            if (count($sample) >= self::DEBUG_MAX_LIST_ITEMS) {
                break;
            }
        }

        return $sample;
    }

    private static function debug_hash_prefix(string $hash): string
    {
        return preg_match('/^[a-f0-9]{8,}$/i', $hash) === 1 ? substr(strtolower($hash), 0, 16) : '';
    }

    /**
     * @return array{kind:string,count:int,post_ids:int[],hash:string,comparable:bool,reason:string}
     */
    private static function debug_search_result_signature(mixed $posts): array
    {
        if ($posts === null) {
            return [
                'kind' => 'null',
                'count' => 0,
                'post_ids' => [],
                'hash' => sha1('null'),
                'comparable' => true,
                'reason' => '',
            ];
        }

        if (!is_array($posts)) {
            return [
                'kind' => self::debug_truncate_text(get_debug_type($posts), 80),
                'count' => is_countable($posts) ? max(0, count($posts)) : 1,
                'post_ids' => [],
                'hash' => sha1('unavailable:' . get_debug_type($posts)),
                'comparable' => false,
                'reason' => 'posts_pre_query returned a non-array, non-null value that cannot be safely compared.',
            ];
        }

        $parts = ['array', 'count:' . count($posts)];
        $post_ids = [];
        $comparable = true;
        foreach (array_values($posts) as $item) {
            $post_id = self::debug_search_result_post_id($item);
            if ($post_id > 0) {
                $post_ids[] = $post_id;
                $parts[] = 'id:' . $post_id;
            } else {
                $parts[] = 'id:0';
            }

            if (is_object($item)) {
                $parts[] = 'object:' . spl_object_id($item);
            } elseif (is_array($item)) {
                $parts[] = 'array:' . count($item);
                if ($post_id <= 0) {
                    $comparable = false;
                }
            } elseif (is_scalar($item)) {
                $parts[] = 'scalar:' . get_debug_type($item);
                if ($post_id <= 0) {
                    $comparable = false;
                }
            } else {
                $parts[] = 'type:' . get_debug_type($item);
                $comparable = false;
            }
        }

        return [
            'kind' => 'array',
            'count' => count($posts),
            'post_ids' => array_slice($post_ids, 0, self::DEBUG_MAX_LIST_ITEMS),
            'hash' => sha1(implode('|', $parts)),
            'comparable' => $comparable,
            'reason' => $comparable ? '' : 'posts_pre_query result items were not all identifiable by post ID or object identity.',
        ];
    }

    private static function debug_search_result_post_id(mixed $item): int
    {
        if (is_array($item)) {
            foreach (['ID', 'id', 'post_id', 'doc_id'] as $key) {
                if (isset($item[$key]) && is_numeric($item[$key])) {
                    return max(0, (int) $item[$key]);
                }
            }

            return 0;
        }

        return self::post_id_from_value($item);
    }

    /**
     * Read-only late posts_pre_query observer used only for diagnostics.
     */
    public static function observe_final_search_posts(mixed $posts, mixed $query): mixed
    {
        self::debug_observe_search_final_ownership($posts, $query);

        return $posts;
    }

    private static function debug_observe_search_final_ownership(mixed $posts, mixed $query): void
    {
        $query_key = self::query_object_key($query);
        if ($query_key <= 0) {
            return;
        }

        $state = self::$search_final_ownership_state[$query_key] ?? null;
        if (!is_array($state)) {
            $trace_id = self::query_var($query, self::DEBUG_SEARCH_FINAL_OWNERSHIP_QUERY_VAR, 0);
            $trace_id = is_numeric($trace_id) ? (int) $trace_id : 0;
            if ($trace_id > 0 && isset(self::$debug_traces[$trace_id])) {
                self::$debug_traces[$trace_id]['search_final_ownership'] = self::debug_search_final_ownership_unavailable(
                    'Final observer ran, but the request-local ownership trace state is unavailable.',
                    true
                );
            }
            return;
        }

        $trace_id = is_numeric($state['trace_id'] ?? null) ? (int) $state['trace_id'] : 0;
        if ($trace_id <= 0 || !isset(self::$debug_traces[$trace_id])) {
            self::debug_forget_search_final_ownership_query($query_key, $query);
            return;
        }

        $expected_signature = is_array($state['expected_signature'] ?? null)
            ? $state['expected_signature']
            : self::debug_search_result_signature(null);
        $final_signature = self::debug_search_result_signature($posts);
        $origin = is_scalar($state['origin'] ?? null) ? (string) $state['origin'] : 'unknown';
        self::$debug_traces[$trace_id]['search_final_ownership'] = self::debug_search_final_ownership_observed(
            $origin,
            $expected_signature,
            $final_signature
        );
        self::debug_forget_search_final_ownership_query($query_key, $query);
    }

    /**
     * @param array<string,mixed> $expected_signature
     * @param array<string,mixed> $final_signature
     * @return array<string,mixed>
     */
    private static function debug_search_final_ownership_observed(string $origin, array $expected_signature, array $final_signature): array
    {
        if (empty($expected_signature['comparable']) || empty($final_signature['comparable'])) {
            $reason = (string) ($expected_signature['reason'] ?? '');
            if ($reason === '') {
                $reason = (string) ($final_signature['reason'] ?? 'Final result could not be safely compared.');
            }

            return self::debug_search_final_ownership_row(
                'unavailable',
                'unknown',
                true,
                $origin,
                $expected_signature,
                $final_signature,
                $reason
            );
        }

        $matches = (string) ($expected_signature['hash'] ?? '') !== ''
            && (string) ($expected_signature['hash'] ?? '') === (string) ($final_signature['hash'] ?? '');
        if (!$matches) {
            $changed_respected_provider = $origin === 'earlier_provider_respected';
            $status = $changed_respected_provider
                ? 'later_provider_changed_respected_provider'
                : 'later_provider_changed_fts';
            $reason = $changed_respected_provider
                ? 'A later posts_pre_query callback changed the provider result after compatibility mode stood down.'
                : 'A later posts_pre_query callback changed the final result after Language FTS recorded its trace.';

            return self::debug_search_final_ownership_row(
                $status,
                'later_provider',
                true,
                $origin,
                $expected_signature,
                $final_signature,
                $reason
            );
        }

        if ($origin === 'earlier_provider_respected') {
            return self::debug_search_final_ownership_row(
                'earlier_provider_respected',
                'earlier_provider',
                true,
                $origin,
                $expected_signature,
                $final_signature,
                'Compatibility mode respected an earlier non-null provider result through the final observer.'
            );
        }

        if ($origin === 'language_fts_from_null') {
            return self::debug_search_final_ownership_row(
                'language_fts_replaced_null',
                'language_fts',
                true,
                $origin,
                $expected_signature,
                $final_signature,
                'Language FTS replaced the original null posts_pre_query path and survived the final observer.'
            );
        }

        return self::debug_search_final_ownership_row(
            'language_fts_survived',
            'language_fts',
            true,
            $origin,
            $expected_signature,
            $final_signature,
            'Language FTS output survived later posts_pre_query callbacks.'
        );
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
            'incoming_provider_results' => 0,
            'prior_provider_responses_replaced' => 0,
            'snippets_reused' => 0,
            'snippet_reuse_misses' => 0,
            'snippets_generated' => 0,
            'title_snippets_generated' => 0,
            'highlight_replacements' => 0,
            'render_block_visits' => 0,
            'render_block_replacements' => 0,
        ];
    }

    /**
     * Summarize registered posts_pre_query callbacks without invoking them.
     *
     * @return array<string,mixed>
     */
    private static function debug_search_hook_pipeline(): array
    {
        $summary = [
            'hook' => self::DEBUG_SEARCH_HOOK,
            'fts_priority' => self::SEARCH_REPLACEMENT_PRIORITY,
            'total_callbacks' => 0,
            'shown_count' => 0,
            'counts' => [
                'before' => 0,
                'same_priority' => 0,
                'after' => 0,
                'unknown' => 0,
            ],
            'callbacks' => [],
            'more' => false,
            'reason' => '',
        ];

        try {
            global $wp_filter;
            $hook_state = isset($wp_filter) && is_array($wp_filter)
                ? ($wp_filter[self::DEBUG_SEARCH_HOOK] ?? null)
                : null;
        } catch (Throwable $e) {
            $summary['reason'] = 'Hook state could not be read.';

            return $summary;
        }

        $callbacks_by_priority = self::debug_hook_callbacks_by_priority($hook_state);
        if ($callbacks_by_priority === null) {
            $summary['reason'] = 'No compatible posts_pre_query hook state is available.';

            return $summary;
        }

        $buckets = [];
        $order = 0;
        foreach ($callbacks_by_priority as $priority => $bucket) {
            $buckets[] = [
                'priority' => self::debug_hook_priority_value($priority),
                'raw_priority' => $priority,
                'bucket' => $bucket,
                'order' => $order++,
            ];
        }
        usort($buckets, static function (array $left, array $right): int {
            $left_priority = $left['priority'];
            $right_priority = $right['priority'];
            if ($left_priority === null && $right_priority === null) {
                return $left['order'] <=> $right['order'];
            }
            if ($left_priority === null) {
                return 1;
            }
            if ($right_priority === null) {
                return -1;
            }
            if ($left_priority === $right_priority) {
                return $left['order'] <=> $right['order'];
            }

            return $left_priority <=> $right_priority;
        });

        foreach ($buckets as $bucket_info) {
            $priority = is_int($bucket_info['priority']) ? $bucket_info['priority'] : null;
            $relation = self::debug_hook_priority_relation($priority);
            $entries = self::debug_hook_bucket_entries($bucket_info['bucket']);
            foreach ($entries as $entry) {
                $summary['total_callbacks']++;
                $summary['counts'][$relation] = max(0, (int) ($summary['counts'][$relation] ?? 0)) + 1;
                if (count($summary['callbacks']) >= self::DEBUG_MAX_HOOK_CALLBACKS) {
                    continue;
                }

                $summary['callbacks'][] = [
                    'priority' => $priority ?? 'unknown',
                    'relation' => $relation,
                    'label' => self::debug_hook_callback_label(self::debug_hook_callback_from_entry($entry)),
                ];
            }
        }

        $summary['shown_count'] = count($summary['callbacks']);
        $summary['more'] = $summary['shown_count'] < $summary['total_callbacks'];
        if ($summary['total_callbacks'] === 0) {
            $summary['reason'] = 'No posts_pre_query callbacks are registered.';
        }

        return $summary;
    }

    /**
     * @return array<int|string,mixed>|null
     */
    private static function debug_hook_callbacks_by_priority(mixed $hook_state): ?array
    {
        if ($hook_state === null) {
            return [];
        }
        if (is_array($hook_state)) {
            return $hook_state;
        }
        if (!is_object($hook_state)) {
            return null;
        }

        try {
            $callbacks = $hook_state->callbacks ?? null;
        } catch (Throwable $e) {
            return null;
        }

        return is_array($callbacks) ? $callbacks : null;
    }

    private static function debug_hook_priority_value(mixed $priority): ?int
    {
        if (is_int($priority)) {
            return $priority;
        }
        if (is_string($priority) && preg_match('/^-?[0-9]+$/', $priority) === 1) {
            return (int) $priority;
        }

        return null;
    }

    private static function debug_hook_priority_relation(?int $priority): string
    {
        if ($priority === null) {
            return 'unknown';
        }
        if ($priority < self::SEARCH_REPLACEMENT_PRIORITY) {
            return 'before';
        }
        if ($priority === self::SEARCH_REPLACEMENT_PRIORITY) {
            return 'same_priority';
        }

        return 'after';
    }

    /**
     * @return mixed[]
     */
    private static function debug_hook_bucket_entries(mixed $bucket): array
    {
        if (!is_array($bucket)) {
            return [$bucket];
        }

        return $bucket === [] ? [] : array_values($bucket);
    }

    private static function debug_hook_callback_from_entry(mixed $entry): mixed
    {
        if (is_array($entry) && array_key_exists('function', $entry)) {
            return $entry['function'];
        }

        return $entry;
    }

    private static function debug_hook_callback_label(mixed $callback): string
    {
        if ($callback instanceof Closure) {
            return 'closure';
        }

        if (is_string($callback)) {
            return self::debug_hook_string_callback_label($callback);
        }

        if (is_array($callback)) {
            $parts = array_values($callback);
            if (count($parts) !== 2 || !is_scalar($parts[1])) {
                return 'unknown';
            }

            $method = self::debug_hook_method_label((string) $parts[1]);
            if ($method === '') {
                return 'unknown';
            }

            if (is_object($parts[0])) {
                $class = self::debug_hook_class_label(get_class($parts[0]));

                return $class !== '' ? 'method: ' . $class . '::' . $method : 'unknown';
            }

            if (is_string($parts[0])) {
                $class = self::debug_hook_class_label($parts[0]);

                return $class !== '' ? 'static: ' . $class . '::' . $method : 'unknown';
            }

            return 'unknown';
        }

        if (is_object($callback)) {
            $class = self::debug_hook_class_label(get_class($callback));
            if ($class === '') {
                return 'unknown';
            }

            return method_exists($callback, '__invoke') ? 'method: ' . $class . '::__invoke' : 'object: ' . $class;
        }

        return 'unknown';
    }

    private static function debug_hook_string_callback_label(string $callback): string
    {
        $callback = self::debug_truncate_text($callback, 120);
        if ($callback === '') {
            return 'unknown';
        }

        if (str_contains($callback, '::')) {
            $parts = explode('::', $callback, 2);
            $class = self::debug_hook_class_label($parts[0] ?? '');
            $method = self::debug_hook_method_label($parts[1] ?? '');

            return $class !== '' && $method !== '' ? 'static: ' . $class . '::' . $method : 'unknown';
        }

        $function = self::debug_hook_symbol_label($callback);

        return $function !== '' ? 'function: ' . $function : 'unknown';
    }

    private static function debug_hook_class_label(string $class): string
    {
        $class = self::debug_truncate_text($class, 120);
        if ($class === '') {
            return '';
        }
        if (str_contains($class, 'class@anonymous')) {
            return 'anonymous class';
        }

        return self::debug_hook_symbol_label($class);
    }

    private static function debug_hook_method_label(string $method): string
    {
        $method = self::debug_truncate_text($method, 80);

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method) === 1 ? $method : '';
    }

    private static function debug_hook_symbol_label(string $symbol): string
    {
        $symbol = self::debug_truncate_text($symbol, 120);
        if (
            $symbol === ''
            || str_contains($symbol, "\0")
            || str_contains($symbol, '/')
            || preg_match('/^[A-Za-z]:\\\\/', $symbol) === 1
        ) {
            return '';
        }

        return preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $symbol) === 1
            ? ltrim($symbol, '\\')
            : '';
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function debug_normalize_trace_extra(array $extra): array
    {
        $allowed = [];
        $structured_keys = [
            'search_explain' => true,
            'search_hook_pipeline' => true,
            'search_final_ownership' => true,
        ];
        foreach (['query_lang', 'fallback_languages', 'settings', 'counts', 'timings_ms', 'analyzer_pack_status', 'search_hook_pipeline', 'search_final_ownership', 'search_explain', 'notes'] as $key) {
            if (array_key_exists($key, $extra)) {
                $allowed[$key] = is_array($extra[$key]) && isset($structured_keys[$key])
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
        $known_provider_advisory = self::known_search_provider_advisory($settings);
        $summary = [
            'public_site_search' => !empty($settings['replace_frontend_search']) ? 'enabled' : 'disabled',
            'admin_posts_search' => !empty($settings['replace_admin_post_search']) ? 'enabled' : 'disabled',
            'provider_compatibility' => self::search_provider_compatibility_debug_value((string) ($settings['search_provider_compatibility'] ?? self::SEARCH_PROVIDER_COMPATIBILITY_PREFER_FTS)),
            'known_search_providers' => self::known_search_provider_debug_summary($known_provider_advisory),
            'known_search_provider_count' => max(0, (int) ($known_provider_advisory['detected_count'] ?? 0)),
            'match_mode' => (string) ($settings['match_mode'] ?? 'OR'),
            'prefix_matching' => !empty($settings['prefix_matching']) ? 'enabled' : 'disabled',
            'prefix_min_length' => self::sanitize_prefix_min_length($settings['prefix_min_length'] ?? self::PREFIX_MIN_LENGTH_DEFAULT),
            'prefix_max_terms' => self::sanitize_prefix_max_terms($settings['prefix_max_terms'] ?? self::PREFIX_MAX_TERMS_DEFAULT),
            'highlight' => !empty($settings['highlight']) ? 'enabled' : 'disabled',
            'snippet_length' => (int) ($settings['snippet_length'] ?? self::FRONTEND_SNIPPET_LENGTH),
            'result_limit' => (int) ($settings['result_limit'] ?? 10),
            'language_fallback' => !empty($settings['language_fallback']) ? 'enabled' : 'disabled',
            'field_boosts' => self::field_boost_summary($settings['field_boosts'] ?? []),
            'recency_boost' => self::recency_boost_summary($settings),
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

    private static function debug_set_performance_budget_summary(int $trace_id): void
    {
        if (!isset(self::$debug_traces[$trace_id])) {
            return;
        }

        self::$debug_traces[$trace_id]['performance_budget'] = self::debug_performance_budget_from_trace(self::$debug_traces[$trace_id]);
    }

    /**
     * @param array<string,mixed> $trace
     * @return array<string,mixed>
     */
    private static function debug_performance_budget_from_trace(array $trace): array
    {
        $timings = is_array($trace['timings_ms'] ?? null) ? $trace['timings_ms'] : [];
        $total_elapsed = self::debug_timing_value_ms($timings['total'] ?? null);
        $storage_elapsed = self::debug_timing_value_ms($timings['storage/search'] ?? null);
        $budgets = self::debug_search_performance_budgets($trace);
        $total_budget = $budgets['total_ms'];
        $storage_budget = $budgets['storage_search_ms'];

        $evaluated = [];
        $exceeded = [];
        $missing = [];
        if ($total_budget > 0.0) {
            if ($total_elapsed === null) {
                $missing[] = 'total';
            } else {
                $evaluated[] = self::debug_budget_phase_comparison('total', $total_elapsed, $total_budget);
                if ($total_elapsed > $total_budget) {
                    $exceeded[] = 'total';
                }
            }
        }
        if ($storage_budget > 0.0) {
            if ($storage_elapsed === null) {
                $missing[] = 'storage/search';
            } else {
                $evaluated[] = self::debug_budget_phase_comparison('storage/search', $storage_elapsed, $storage_budget);
                if ($storage_elapsed > $storage_budget) {
                    $exceeded[] = 'storage/search';
                }
            }
        }

        if ($total_budget <= 0.0 && $storage_budget <= 0.0) {
            $status = 'disabled';
            $explanation = 'Search performance budgets are disabled; set a positive total or storage/search budget to evaluate request traces.';
        } elseif ($missing !== []) {
            $status = 'unavailable';
            $explanation = 'Search performance budget unavailable because enabled budget phase timing is missing: ' . implode(', ', $missing) . '.';
        } elseif ($evaluated === []) {
            $status = 'unavailable';
            $explanation = 'Search performance budget unavailable because this trace has no total or storage/search timing data.';
        } elseif ($exceeded !== []) {
            $status = 'over_budget';
            $explanation = 'Search exceeded performance budget: ' . implode('; ', $evaluated) . '.';
        } else {
            $status = 'within_budget';
            $explanation = 'Search stayed within performance budget: ' . implode('; ', $evaluated) . '.';
        }

        return [
            'status' => $status,
            'total_elapsed_ms' => $total_elapsed,
            'total_budget_ms' => $total_budget,
            'storage_search_elapsed_ms' => $storage_elapsed,
            'storage_search_budget_ms' => $storage_budget,
            'exceeded_phases' => array_slice($exceeded, 0, self::DEBUG_MAX_LIST_ITEMS),
            'explanation' => self::debug_truncate_text($explanation, 240),
        ];
    }

    /**
     * @param array<string,mixed> $trace
     * @return array{total_ms:float,storage_search_ms:float}
     */
    private static function debug_search_performance_budgets(array $trace): array
    {
        $defaults = [
            'total_ms' => self::configured_search_performance_budget_ms(
                'WP_FTS_SEARCH_TOTAL_BUDGET_MS',
                self::DEFAULT_SEARCH_TOTAL_BUDGET_MS
            ),
            'storage_search_ms' => self::configured_search_performance_budget_ms(
                'WP_FTS_SEARCH_STORAGE_BUDGET_MS',
                self::DEFAULT_SEARCH_STORAGE_BUDGET_MS
            ),
        ];
        $filtered = $defaults;
        if (function_exists('apply_filters')) {
            $candidate = apply_filters(self::SEARCH_PERFORMANCE_BUDGET_FILTER, $defaults, $trace);
            if (is_array($candidate)) {
                $filtered = $candidate;
            }
        }

        return [
            'total_ms' => self::sanitize_search_performance_budget_ms($filtered['total_ms'] ?? $defaults['total_ms'], $defaults['total_ms']),
            'storage_search_ms' => self::sanitize_search_performance_budget_ms($filtered['storage_search_ms'] ?? $defaults['storage_search_ms'], $defaults['storage_search_ms']),
        ];
    }

    private static function configured_search_performance_budget_ms(string $constant, float $default): float
    {
        return self::sanitize_search_performance_budget_ms(defined($constant) ? constant($constant) : $default, $default);
    }

    private static function sanitize_search_performance_budget_ms(mixed $value, float $default): float
    {
        if (!is_numeric($value)) {
            $value = $default;
        }

        $budget = (float) $value;
        if ($budget !== $budget) {
            $budget = $default;
        }
        if ($budget <= 0.0) {
            return 0.0;
        }

        return round(self::clamp_float($budget, 0.0, self::MAX_SEARCH_PERFORMANCE_BUDGET_MS), 3);
    }

    private static function debug_timing_value_ms(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        return round(max(0.0, (float) $value), 3);
    }

    private static function debug_budget_phase_comparison(string $phase, float $elapsed, float $budget): string
    {
        return self::debug_truncate_text($phase, 80) . ' ' . number_format($elapsed, 3, '.', '') . 'ms '
            . ($elapsed > $budget ? '> ' : '<= ')
            . number_format($budget, 3, '.', '') . 'ms';
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

    private static function render_debug_diagnostics_panel(string $heading, bool $include_indexing_batch = true): void
    {
        echo '<div class="wp-fts-debug-diagnostics">';
        echo '<h3>' . self::esc_html($heading) . '</h3>';
        if ($include_indexing_batch) {
            self::render_debug_indexing_batch_diagnostics();
        }
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
            self::render_debug_row('Search hook pipeline', self::debug_search_hook_pipeline_summary($trace['search_hook_pipeline'] ?? []));
            self::render_debug_row('Search final ownership', self::debug_search_final_ownership_summary($trace['search_final_ownership'] ?? []));
            $search_explain = is_array($trace['search_explain'] ?? null) ? $trace['search_explain'] : [];
            self::render_debug_row('Storage backend', self::debug_assoc_summary($search_explain['storage'] ?? []));
            self::render_debug_row('Query plan', self::debug_query_plan_summary($search_explain['query_plan'] ?? []));
            self::render_debug_row('Fast mode', self::debug_assoc_summary($search_explain['fast_mode'] ?? []));
            self::render_debug_row('Scoring', self::debug_assoc_summary($search_explain['scoring'] ?? []));
            self::render_debug_row('Recency boost', self::debug_assoc_summary($search_explain['recency_boost'] ?? []));
            self::render_debug_row('Result matches', self::debug_result_matches_summary($search_explain['results'] ?? []));
            self::render_debug_row('Field matches', self::debug_field_matches_summary($search_explain['results'] ?? []));
            self::render_debug_row('Counts', self::debug_assoc_summary($trace['counts'] ?? []));
            self::render_debug_row('Timings', self::debug_timing_summary($trace['timings_ms'] ?? []));
            $performance_budget = is_array($trace['performance_budget'] ?? null)
                ? $trace['performance_budget']
                : self::debug_performance_budget_from_trace($trace);
            self::render_debug_row('Performance budget', self::debug_performance_budget_summary($performance_budget));
            self::render_debug_row('SQL queries', self::debug_sql_queries_summary($trace['sql_queries'] ?? []));
            self::render_debug_row('Analyzer packs', self::debug_pack_status_summary($trace['analyzer_pack_status'] ?? []));
            self::render_debug_row('Notes', self::debug_list_summary($trace['notes'] ?? []));
            echo '</tbody></table>';
            echo '</details>';
        }
        echo '</div>';
    }

    private static function render_debug_indexing_batch_diagnostics(): void
    {
        $diagnostics = self::latest_index_batch_diagnostics_from_health(self::index_health_state());
        if ($diagnostics === []) {
            return;
        }

        echo '<h4>Latest indexing batch</h4>';
        echo '<table class="widefat striped wp-fts-debug-table"><tbody>';
        self::render_debug_row('Trigger', self::index_batch_trigger_summary($diagnostics));
        self::render_debug_row('Timing', self::index_batch_timing_summary($diagnostics));
        self::render_debug_row('Queue', self::index_batch_queue_summary($diagnostics));
        self::render_debug_row('Backfill', self::index_batch_backfill_summary($diagnostics));
        self::render_debug_row('Stale debt', self::index_batch_stale_debt_summary($diagnostics));
        self::render_debug_row('Lock', self::index_batch_lock_summary($diagnostics));
        self::render_debug_row('Schema and storage', self::index_batch_schema_storage_summary($diagnostics));
        self::render_debug_row('Retry or reschedule', self::index_batch_reschedule_summary($diagnostics));
        self::render_debug_row('Stop reason', self::index_batch_stop_summary($diagnostics));
        self::render_debug_row('Error', self::index_batch_error_summary($diagnostics));
        echo '</tbody></table>';
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

    private static function debug_performance_budget_summary(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        $status = self::debug_scalar_summary($value['status'] ?? '');
        if ($status !== '') {
            $parts[] = 'status=' . $status;
        }
        $parts[] = 'total=' . self::debug_performance_budget_phase_summary(
            $value['total_elapsed_ms'] ?? null,
            $value['total_budget_ms'] ?? null
        );
        $parts[] = 'storage/search=' . self::debug_performance_budget_phase_summary(
            $value['storage_search_elapsed_ms'] ?? null,
            $value['storage_search_budget_ms'] ?? null
        );

        $exceeded = self::debug_list_summary($value['exceeded_phases'] ?? []);
        if ($exceeded !== '') {
            $parts[] = 'exceeded=' . $exceeded;
        }

        $explanation = self::debug_scalar_summary($value['explanation'] ?? '');

        return self::debug_truncate_text(implode(', ', $parts) . ($explanation !== '' ? '; ' . $explanation : ''), 800);
    }

    private static function debug_performance_budget_phase_summary(mixed $elapsed, mixed $budget): string
    {
        $elapsed_summary = is_numeric($elapsed) ? number_format((float) $elapsed, 3, '.', '') . 'ms' : 'unavailable';
        $budget_summary = is_numeric($budget) && (float) $budget > 0.0
            ? number_format((float) $budget, 3, '.', '') . 'ms'
            : 'disabled';

        return $elapsed_summary . '/' . $budget_summary;
    }

    private static function debug_sql_queries_summary(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        if (empty($value['available'])) {
            $reason = self::debug_scalar_summary($value['reason'] ?? '');

            return self::debug_truncate_text('capture unavailable' . ($reason !== '' ? ': ' . $reason : ''), 800);
        }

        $captured = max(0, (int) ($value['captured_count'] ?? 0));
        $shown = max(0, (int) ($value['shown_count'] ?? 0));
        $parts = ['captured=' . $captured];
        if ($shown !== $captured) {
            $parts[] = 'shown=' . $shown;
        }
        if (isset($value['total_time_ms']) && is_numeric($value['total_time_ms'])) {
            $parts[] = 'total_time=' . number_format((float) $value['total_time_ms'], 3, '.', '') . 'ms';
        }

        $entries = [];
        if (isset($value['entries']) && is_array($value['entries'])) {
            foreach ($value['entries'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $summary = self::debug_scalar_summary($entry['summary'] ?? '');
                if ($summary === '') {
                    continue;
                }
                if (isset($entry['time_ms']) && is_numeric($entry['time_ms'])) {
                    $summary .= ' (' . number_format((float) $entry['time_ms'], 3, '.', '') . 'ms)';
                }
                $entries[] = $summary;
                if (count($entries) >= self::DEBUG_MAX_SQL_QUERIES) {
                    break;
                }
            }
        }

        $body = implode('; ', $entries);
        if (!empty($value['more']) && $body !== '') {
            $body .= '; ...';
        }
        if ($body === '') {
            $body = self::debug_scalar_summary($value['reason'] ?? '');
        }

        return self::debug_truncate_text(implode(', ', $parts) . ($body !== '' ? '; ' . $body : ''), 800);
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

    private static function debug_search_hook_pipeline_summary(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $priority = self::debug_scalar_summary($value['fts_priority'] ?? '');
        $counts = is_array($value['counts'] ?? null) ? $value['counts'] : [];
        $parts = [];
        if ($priority !== '') {
            $parts[] = 'fts_priority=' . $priority;
        }
        foreach (['before', 'same_priority', 'after', 'unknown'] as $relation) {
            if (array_key_exists($relation, $counts)) {
                $parts[] = $relation . '=' . max(0, (int) $counts[$relation]);
            }
        }

        $callbacks = [];
        if (isset($value['callbacks']) && is_array($value['callbacks'])) {
            foreach ($value['callbacks'] as $callback) {
                if (!is_array($callback)) {
                    continue;
                }
                $callback_priority = self::debug_scalar_summary($callback['priority'] ?? '');
                $relation = self::debug_scalar_summary($callback['relation'] ?? '');
                $label = self::debug_scalar_summary($callback['label'] ?? '');
                $callbacks[] = trim(
                    ($callback_priority !== '' ? 'p' . $callback_priority . ' ' : '')
                    . ($relation !== '' ? $relation . ' ' : '')
                    . ($label !== '' ? $label : 'unknown')
                );
                if (count($callbacks) >= self::DEBUG_MAX_HOOK_CALLBACKS) {
                    break;
                }
            }
        }

        if ($callbacks !== []) {
            $parts[] = 'callbacks=' . implode(' | ', $callbacks) . (!empty($value['more']) ? ' | ...' : '');
        } elseif (!empty($value['more'])) {
            $parts[] = 'callbacks=...';
        }

        $reason = self::debug_scalar_summary($value['reason'] ?? '');
        if ($reason !== '') {
            $parts[] = 'reason=' . $reason;
        }

        return self::debug_truncate_text(implode(', ', $parts), 800);
    }

    private static function debug_search_final_ownership_summary(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        foreach (['status', 'owner', 'origin'] as $key) {
            $summary = self::debug_scalar_summary($value[$key] ?? '');
            if ($summary !== '') {
                $parts[] = $key . '=' . $summary;
            }
        }

        if (array_key_exists('observed', $value)) {
            $parts[] = 'observed=' . (!empty($value['observed']) ? 'true' : 'false');
        }

        foreach (['expected_count', 'final_count'] as $key) {
            if (array_key_exists($key, $value)) {
                $parts[] = $key . '=' . max(0, (int) $value[$key]);
            }
        }

        foreach (['expected_post_ids', 'final_post_ids'] as $key) {
            $ids = self::debug_list_summary($value[$key] ?? []);
            if ($ids !== '') {
                $parts[] = $key . '=' . $ids;
            }
        }

        foreach (['expected_hash', 'final_hash'] as $key) {
            $hash = self::debug_scalar_summary($value[$key] ?? '');
            if ($hash !== '') {
                $parts[] = $key . '=' . $hash;
            }
        }

        $reason = self::debug_scalar_summary($value['reason'] ?? '');
        if ($reason !== '') {
            $parts[] = 'reason=' . $reason;
        }

        return self::debug_truncate_text(implode(', ', $parts), 800);
    }

    private static function debug_query_plan_summary(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        foreach (['match_mode', 'logical_group_count', 'prefix_matching', 'prefix_min_length', 'prefix_max_terms', 'prefix_added_terms'] as $key) {
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

    private static function debug_field_matches_summary(mixed $value): string
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
            $fields = [];
            if (isset($row['field_matches']) && is_array($row['field_matches'])) {
                foreach ($row['field_matches'] as $field) {
                    if (!is_array($field)) {
                        continue;
                    }

                    $terms = [];
                    if (isset($field['terms']) && is_array($field['terms'])) {
                        foreach ($field['terms'] as $term) {
                            if (is_array($term)) {
                                $terms[] = self::debug_explain_term_summary($term);
                            }
                            if (count($terms) >= 2) {
                                break;
                            }
                        }
                    }

                    $field_name = self::debug_scalar_summary($field['field'] ?? '');
                    $parts = [];
                    foreach (['weight', 'match_count', 'weighted_match_count', 'score_subtotal'] as $key) {
                        if (array_key_exists($key, $field)) {
                            $parts[] = $key . '=' . self::debug_scalar_summary($field[$key]);
                        }
                    }
                    if ($terms !== []) {
                        $parts[] = 'terms=' . implode(' | ', $terms) . (!empty($field['terms_more']) ? ' ...' : '');
                    }

                    $fields[] = ($field_name !== '' ? $field_name : '?') . '(' . implode(', ', $parts) . ')';
                    if (count($fields) >= self::DEBUG_MAX_LIST_ITEMS) {
                        break;
                    }
                }
            }

            $rows[] = 'doc ' . ($doc !== '' ? $doc : '?') . '=' . ($fields !== [] ? implode(' ; ', $fields) : '-')
                . (!empty($row['field_matches_more']) ? ' ; ...' : '');
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
                'explain' => ['required' => false],
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
            'sanitize_callback' => [self::class, 'sanitize_settings_for_save'],
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
        self::$admin_health_support_snapshot_visible = false;
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
        foreach (self::handle_admin_analyzer_post_action() as $message) {
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

        $action = self::health_post_action();
        if (!self::can_manage_admin_sandbox()) {
            return [['error', self::health_post_action_permission_message($action)]];
        }

        if (!self::verify_health_nonce()) {
            return [['error', self::health_post_action_nonce_message($action)]];
        }

        if ($action === self::ADMIN_HEALTH_REPAIR_SCHEMA_ACTION) {
            try {
                return [self::schema_repair_notice(self::repair_schema())];
            } catch (Throwable $e) {
                return [['error', 'Could not repair schema tables: ' . self::bounded_admin_error_message($e)]];
            }
        }

        if ($action === self::ADMIN_HEALTH_MANUAL_BATCH_ACTION) {
            try {
                return [self::manual_index_batch_notice(self::process_manual_index_batch(['source' => 'admin-health']))];
            } catch (Throwable $e) {
                return [['error', 'Could not index the next batch: ' . self::bounded_admin_error_message($e)]];
            }
        }

        if ($action === self::ADMIN_HEALTH_SCHEDULE_QUEUE_ACTION) {
            return [self::queue_processor_schedule_notice(self::schedule_queue_processor_for_operator())];
        }

        if ($action === self::ADMIN_HEALTH_SUPPORT_SNAPSHOT_ACTION) {
            self::$admin_health_support_snapshot_visible = true;
            return [['info', 'Support snapshot generated below. No indexing, schema repair, queue scheduling, searches, or provider API calls were run.']];
        }

        return [['error', 'Unsupported Health action. No changes were made.']];
    }

    private static function health_post_action_permission_message(string $action): string
    {
        return match ($action) {
            self::ADMIN_HEALTH_REPAIR_SCHEMA_ACTION => 'You do not have permission to repair schema tables.',
            self::ADMIN_HEALTH_SCHEDULE_QUEUE_ACTION => 'You do not have permission to schedule the queue processor.',
            self::ADMIN_HEALTH_SUPPORT_SNAPSHOT_ACTION => 'You do not have permission to generate the support snapshot.',
            default => 'You do not have permission to index content.',
        };
    }

    private static function health_post_action_nonce_message(string $action): string
    {
        return match ($action) {
            self::ADMIN_HEALTH_REPAIR_SCHEMA_ACTION => 'The schema repair action could not be verified. Reload the page and try again.',
            self::ADMIN_HEALTH_SCHEDULE_QUEUE_ACTION => 'The queue schedule action could not be verified. Reload the page and try again.',
            self::ADMIN_HEALTH_SUPPORT_SNAPSHOT_ACTION => 'The support snapshot action could not be verified. Reload the page and try again.',
            default => 'The indexing action could not be verified. Reload the page and try again.',
        };
    }

    /**
     * @param array{status:string,stored_version:int,expected_version:int} $schema
     * @return array{0:string,1:string}
     */
    private static function schema_repair_notice(array $schema): array
    {
        return [
            'success',
            sprintf(
                'Schema tables repaired. Current schema version: %d.',
                max(0, (int) ($schema['stored_version'] ?? 0))
            ),
        ];
    }

    private static function bounded_admin_error_message(Throwable $e): string
    {
        $message = $e->getMessage();
        $message = preg_replace('/#\d+\s+.*$/s', '', $message) ?? $message;
        $message = preg_replace('/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|TRUNCATE|REPLACE)\b.*$/s', '$1 statement', $message) ?? $message;
        $message = self::debug_truncate_text(self::sanitize_text($message), self::MAX_INDEX_FAILURE_ERROR_BYTES);

        return $message !== '' ? $message : 'Unknown error.';
    }

    /**
     * @param array<string,mixed> $summary
     * @return array{0:string,1:string}
     */
    private static function manual_index_batch_notice(array $summary): array
    {
        $processed = max(0, (int) ($summary['processed'] ?? 0));
        $failures = max(0, (int) ($summary['last_batch_failures'] ?? 0));

        if (!empty($summary['skipped_locked'])) {
            return ['info', 'Another indexing batch is already running. No overlapping batch was started; try again shortly.'];
        }

        if ($failures > 0) {
            return [
                'warning',
                sprintf(
                    'Indexed %d %s. %d %s failed and %s recorded; indexing continued where possible. Fix the issue, then run another batch or a scoped reindex.',
                    $processed,
                    self::item_count_label($processed),
                    $failures,
                    self::item_count_label($failures),
                    $failures === 1 ? 'was' : 'were'
                ),
            ];
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

    /**
     * @param array<string,mixed> $result
     * @return array{0:string,1:string}
     */
    private static function queue_processor_schedule_notice(array $result): array
    {
        $status = is_scalar($result['status'] ?? null) ? (string) $result['status'] : '';
        $message = is_scalar($result['message'] ?? null) ? trim((string) $result['message']) : '';
        $type = match ($status) {
            'scheduled' => 'success',
            'failed', 'unavailable' => 'error',
            default => 'info',
        };

        return [$type, $message !== '' ? $message : 'No schedule detail available.'];
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

    /**
     * @return array<int,array{0:string,1:string}>
     */
    private static function handle_admin_analyzer_post_action(): array
    {
        if (!self::analyzer_post_action_submitted()) {
            return [];
        }

        if (!self::can_manage_admin_sandbox()) {
            return [['error', 'You do not have permission to manage analyzer packs.']];
        }

        if (!self::verify_analyzer_nonce()) {
            return [['error', 'The analyzer-pack action could not be verified. Reload the page and try again.']];
        }

        if (self::analyzer_post_action() !== self::ADMIN_ANALYZER_SAVE_BUNDLED_ACTION) {
            return [['error', 'Unsupported analyzer-pack action. No changes were made.']];
        }

        if (!WP_FTS_AnalyzerPackValidator::gzip_available()) {
            return [['warning', 'Bundled UniMorph packs need PHP gzip stream support and were not changed on this server.']];
        }

        $manifests = self::bundled_runtime_lemma_pack_control_manifests();
        if ($manifests === []) {
            return [['info', 'No bundled runtime lemma packs were found. Analyzer options were not changed.']];
        }

        $previousProfile = self::current_index_profile();
        self::save_bundled_runtime_lemma_pack_selection(
            self::selected_bundled_runtime_lemma_pack_languages($manifests),
            $manifests
        );
        $currentProfile = self::current_index_profile();
        $reasons = self::index_profile_change_reasons($previousProfile, $currentProfile);
        if ($reasons !== []) {
            self::mark_stale_index_debt($reasons, $previousProfile, $currentProfile);
        }

        return [['success', 'Bundled analyzer pack settings saved. Reindex existing content for analyzer changes to affect already-indexed posts.']];
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
        echo '.wp-fts-support-snapshot{max-width:980px;}';
        echo '.wp-fts-support-snapshot textarea{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;min-height:360px;white-space:pre;}';
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
        $known_provider_advisory = self::known_search_provider_advisory($settings);
        $health = self::search_health();
        $schema = self::schema_status();
        $lock = self::index_lock_status();
        try {
            $counts = self::search_health_counts();
        } catch (Throwable $e) {
            $counts = self::empty_search_health_counts();
            self::render_sandbox_notice('error', 'Could not read index counts: ' . $e->getMessage());
        }
        $queue_processor_schedule = self::queue_processor_schedule_status($health, $counts['remaining']);
        $cron_runner = self::cron_runner_status($queue_processor_schedule);

        echo '<h2>Search health</h2>';
        echo '<p class="wp-fts-health-copy">The plugin builds the search index in small batches so large sites stay responsive. WP-Cron continues indexing a small amount in the background. Use the button below to index the next larger batch now; large sites may need several batches, and that is intentional.</p>';

        echo '<h3>Status summary</h3>';
        echo '<table class="widefat striped wp-fts-health-table"><tbody>';
        self::render_health_status_row('Schema status', self::schema_status_label((string) $schema['status']));
        self::render_health_status_row('Stored schema version', (string) max(0, (int) $schema['stored_version']));
        self::render_health_status_row('Expected schema version', (string) max(0, (int) $schema['expected_version']));
        self::render_health_status_row('Indexing lock', self::lock_state_summary($lock));
        self::render_health_status_row('Lock mode', self::lock_mode_summary($lock));
        self::render_health_status_row('Lock started', self::lock_time_summary($lock['started_at'] ?? ''));
        self::render_health_status_row('Lock expires', self::lock_time_summary($lock['expires_at'] ?? ''));
        self::render_health_status_row('Lock age', self::lock_seconds_summary($lock['age_seconds'] ?? null));
        self::render_health_status_row('Lock timing', self::lock_timing_summary($lock));
        self::render_health_status_row('Lock advice', self::lock_advice_summary($lock));
        self::render_health_status_row('Public site search', !empty($settings['replace_frontend_search']) ? 'Enabled' : 'Disabled');
        self::render_health_status_row('wp-admin Posts search', !empty($settings['replace_admin_post_search']) ? 'Enabled' : 'Disabled');
        self::render_health_status_row('Search provider compatibility', self::search_provider_compatibility_label((string) $settings['search_provider_compatibility']));
        self::render_health_status_row('Known search providers', (string) $known_provider_advisory['summary']);
        self::render_health_status_row('Field ranking weights', self::field_boost_summary($settings['field_boosts'] ?? []));
        self::render_health_status_row('Recency ranking boost', self::recency_boost_summary($settings));
        self::render_health_status_row('Indexed post types', self::health_post_type_summary($settings['index_post_types']));
        self::render_health_status_row('Eligible content', (string) $counts['total_eligible']);
        self::render_health_status_row('Indexed', (string) $counts['indexed']);
        self::render_health_status_row('Waiting in the update queue', (string) $counts['pending']);
        self::render_health_status_row('Remaining to index', (string) $counts['remaining']);
        echo '</tbody></table>';

        echo '<h3>Queue processor schedule</h3>';
        echo '<table class="widefat striped wp-fts-health-table"><tbody>';
        self::render_health_status_row('Queue processor hook', is_scalar($queue_processor_schedule['hook'] ?? null) ? (string) $queue_processor_schedule['hook'] : '');
        self::render_health_status_row('Queue processor scheduled', !empty($queue_processor_schedule['scheduled']) ? 'Yes' : 'No');
        self::render_health_status_row('Queue processor status', self::queue_processor_schedule_status_label($queue_processor_schedule));
        self::render_health_status_row('Next queue run', self::queue_processor_schedule_next_run_summary($queue_processor_schedule));
        self::render_health_status_row('Next queue run delay', self::queue_processor_schedule_delay_summary($queue_processor_schedule));
        self::render_health_status_row('Queue processor advice', self::queue_processor_schedule_advice_summary($queue_processor_schedule));
        echo '</tbody></table>';

        echo '<h3>Cron runner</h3>';
        echo '<table class="widefat striped wp-fts-health-table"><tbody>';
        self::render_health_status_row('WP-Cron runner', self::cron_runner_status_label($cron_runner));
        self::render_health_status_row('DISABLE_WP_CRON', !empty($cron_runner['wp_cron_disabled']) ? 'Yes' : 'No');
        self::render_health_status_row('ALTERNATE_WP_CRON', !empty($cron_runner['alternate_wp_cron']) ? 'Yes' : 'No');
        self::render_health_status_row('Cron runner pending work', !empty($cron_runner['pending_work']) ? 'Yes' : 'No');
        self::render_health_status_row('Cron runner advice', self::cron_runner_advice_summary($cron_runner));
        echo '</tbody></table>';

        if ((string) ($queue_processor_schedule['status'] ?? '') === 'missing') {
            echo '<h3>Queue processor controls</h3>';
            echo '<p class="wp-fts-health-copy">Schedule a future WP-Cron queue processor run. This does not index content in the current request.</p>';
            echo '<form method="post" action="' . self::esc_url(self::admin_page_url(self::ADMIN_HEALTH_TAB)) . '">';
            self::render_health_nonce_field();
            echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_HEALTH_ACTION_FIELD) . '" value="' . self::esc_attr(self::ADMIN_HEALTH_SCHEDULE_QUEUE_ACTION) . '">';
            echo '<p><button type="submit" class="button">Schedule queue processor</button></p>';
            echo '</form>';
        }

        echo '<h3>Reindex debt</h3>';
        echo '<table class="widefat striped wp-fts-health-table"><tbody>';
        self::render_health_status_row('Stale index debt', self::stale_debt_status_summary($health));
        self::render_health_status_row('Debt reasons', self::stale_debt_reason_summary($health));
        self::render_health_status_row('Debt progress', self::stale_debt_progress_summary($health));
        self::render_health_status_row('Debt processing profile', self::index_profile_hash_summary($health['stale_debt_processing_profile_hash'] ?? ''));
        self::render_health_status_row('Current index profile', self::index_profile_hash_summary($health['index_profile_hash'] ?? ''));
        self::render_health_status_row('Last accepted index profile', self::index_profile_hash_summary($health['accepted_index_profile_hash'] ?? ''));
        self::render_health_status_row('Debt marked', self::lock_time_summary($health['stale_debt_created_at'] ?? ''));
        self::render_health_status_row('Debt updated', self::lock_time_summary($health['stale_debt_updated_at'] ?? ''));
        echo '</tbody></table>';

        $failure_recovery = self::failure_recovery_status();
        echo '<h3>Failure recovery</h3>';
        echo '<table class="widefat striped wp-fts-health-table"><tbody>';
        self::render_health_status_row('Failed item history', self::failure_recovery_count_summary($failure_recovery));
        self::render_health_status_row('Recent failed items', self::failure_recovery_recent_items_summary($failure_recovery));
        self::render_health_status_row('Oldest failure', self::lock_time_summary($failure_recovery['oldest_failed_at'] ?? ''));
        self::render_health_status_row('Newest failure', self::lock_time_summary($failure_recovery['newest_failed_at'] ?? ''));
        self::render_health_status_row('Recovery advice', self::sanitize_index_failure_text($failure_recovery['advice'] ?? '', self::MAX_INDEX_FAILURE_ERROR_BYTES, false));
        echo '</tbody></table>';

        echo '<h3>Latest batch</h3>';
        echo '<table class="widefat striped wp-fts-health-table"><tbody>';
        self::render_health_status_row('Last indexed content', self::last_indexed_content_summary($health));
        self::render_health_status_row('Last batch', self::last_batch_summary($health));
        self::render_health_status_row('Last batch processed', self::last_batch_processed_summary($health));
        self::render_health_status_row('Batch status', self::last_batch_status_summary($health));
        self::render_health_status_row('Last indexing failure', self::last_indexing_failure_summary($health));
        self::render_latest_index_batch_diagnostics_rows($health);
        echo '</tbody></table>';

        if (!class_exists('Debug_Bar_Panel') && self::can_view_debug_diagnostics()) {
            self::render_debug_diagnostics_panel('Request diagnostics', false);
        }

        self::render_health_support_snapshot_controls();

        echo '<h3>Schema controls</h3>';
        echo '<p class="wp-fts-health-copy">Repair FTS tables and the stored schema version without indexing content.</p>';
        echo '<form method="post" action="' . self::esc_url(self::admin_page_url(self::ADMIN_HEALTH_TAB)) . '">';
        self::render_health_nonce_field();
        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_HEALTH_ACTION_FIELD) . '" value="' . self::esc_attr(self::ADMIN_HEALTH_REPAIR_SCHEMA_ACTION) . '">';
        echo '<p><button type="submit" class="button">Repair schema tables</button></p>';
        echo '</form>';

        echo '<h3>Indexing controls</h3>';
        echo '<p class="wp-fts-health-copy">Run one safe indexing pass now. You can use it again until Remaining to index reaches 0.</p>';
        echo '<form method="post" action="' . self::esc_url(self::admin_page_url(self::ADMIN_HEALTH_TAB)) . '">';
        self::render_health_nonce_field();
        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_HEALTH_ACTION_FIELD) . '" value="' . self::esc_attr(self::ADMIN_HEALTH_MANUAL_BATCH_ACTION) . '">';
        echo '<p><button type="submit" class="button button-primary">Index the next batch now</button></p>';
        echo '</form>';
    }

    private static function render_health_support_snapshot_controls(): void
    {
        echo '<h3>Support snapshot</h3>';
        echo '<p class="wp-fts-health-copy">Generate a bounded, redacted JSON snapshot for support handoff. This is read-only and does not run searches, indexing, schema repair, queue scheduling, or provider API calls.</p>';
        echo '<form method="post" action="' . self::esc_url(self::admin_page_url(self::ADMIN_HEALTH_TAB)) . '">';
        self::render_health_nonce_field();
        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_HEALTH_ACTION_FIELD) . '" value="' . self::esc_attr(self::ADMIN_HEALTH_SUPPORT_SNAPSHOT_ACTION) . '">';
        echo '<p><button type="submit" class="button">Generate support snapshot</button></p>';
        echo '</form>';

        if (!self::$admin_health_support_snapshot_visible) {
            return;
        }

        echo '<div class="wp-fts-support-snapshot">';
        echo '<label for="wp-fts-support-snapshot-json" class="screen-reader-text">Support snapshot JSON</label>';
        echo '<textarea id="wp-fts-support-snapshot-json" class="large-text code" rows="20" readonly="readonly" spellcheck="false">';
        echo self::esc_textarea(self::support_snapshot_json());
        echo '</textarea>';
        echo '</div>';
    }

    private static function render_health_status_row(string $label, string $value): void
    {
        echo '<tr><th scope="row">' . self::esc_html($label) . '</th><td>' . self::esc_html($value) . '</td></tr>';
    }

    /**
     * @param array<string,mixed> $health
     */
    private static function render_latest_index_batch_diagnostics_rows(array $health): void
    {
        $diagnostics = self::latest_index_batch_diagnostics_from_health($health);
        if ($diagnostics === []) {
            return;
        }

        self::render_health_status_row('Batch trigger', self::index_batch_trigger_summary($diagnostics));
        self::render_health_status_row('Batch timing', self::index_batch_timing_summary($diagnostics));
        self::render_health_status_row('Batch queue state', self::index_batch_queue_summary($diagnostics));
        self::render_health_status_row('Batch backfill state', self::index_batch_backfill_summary($diagnostics));
        self::render_health_status_row('Batch stale debt state', self::index_batch_stale_debt_summary($diagnostics));
        self::render_health_status_row('Batch lock state', self::index_batch_lock_summary($diagnostics));
        self::render_health_status_row('Batch schema and storage', self::index_batch_schema_storage_summary($diagnostics));
        self::render_health_status_row('Batch retry or reschedule', self::index_batch_reschedule_summary($diagnostics));
        self::render_health_status_row('Batch stop reason', self::index_batch_stop_summary($diagnostics));
        self::render_health_status_row('Batch error', self::index_batch_error_summary($diagnostics));
    }

    private static function schema_status_label(string $status): string
    {
        return match ($status) {
            'current' => 'Current',
            'missing' => 'Missing',
            'stale' => 'Stale',
            default => 'Unknown',
        };
    }

    /**
     * @param array<string,mixed> $schedule
     */
    private static function queue_processor_schedule_status_label(array $schedule): string
    {
        $status = is_scalar($schedule['status'] ?? null) ? (string) $schedule['status'] : '';

        return match ($status) {
            'scheduled' => 'Scheduled',
            'missing' => 'Missing',
            'not_needed' => 'Not needed',
            'unavailable' => 'Unavailable',
            default => 'Unknown',
        };
    }

    /**
     * @param array<string,mixed> $schedule
     */
    private static function queue_processor_schedule_next_run_summary(array $schedule): string
    {
        $next_run = is_scalar($schedule['next_run_at'] ?? null) ? trim((string) $schedule['next_run_at']) : '';
        if ($next_run !== '') {
            return $next_run;
        }

        return self::queue_processor_schedule_status_label($schedule) === 'Unavailable' ? 'Unavailable' : 'Not scheduled';
    }

    /**
     * @param array<string,mixed> $schedule
     */
    private static function queue_processor_schedule_delay_summary(array $schedule): string
    {
        if (isset($schedule['next_run_delay_seconds']) && is_numeric($schedule['next_run_delay_seconds'])) {
            return max(0, (int) $schedule['next_run_delay_seconds']) . ' seconds';
        }

        return self::queue_processor_schedule_status_label($schedule) === 'Unavailable' ? 'Unavailable' : 'Not scheduled';
    }

    /**
     * @param array<string,mixed> $schedule
     */
    private static function queue_processor_schedule_advice_summary(array $schedule): string
    {
        $advice = is_scalar($schedule['advice'] ?? null) ? trim((string) $schedule['advice']) : '';

        return $advice !== '' ? $advice : 'No schedule advice available.';
    }

    /**
     * @param array<string,mixed> $runner
     */
    private static function cron_runner_status_label(array $runner): string
    {
        $status = is_scalar($runner['status'] ?? null) ? (string) $runner['status'] : '';

        return match ($status) {
            'traffic_triggered' => 'Traffic-triggered',
            'external_required' => 'External cron required',
            'unknown' => 'Unknown',
            default => 'Unknown',
        };
    }

    /**
     * @param array<string,mixed> $runner
     */
    private static function cron_runner_advice_summary(array $runner): string
    {
        $advice = is_scalar($runner['advice'] ?? null) ? trim((string) $runner['advice']) : '';

        return $advice !== '' ? $advice : 'No cron runner advice available.';
    }

    /**
     * @param array<string,mixed> $lock
     */
    private static function lock_state_summary(array $lock): string
    {
        $state = is_scalar($lock['state'] ?? null) ? (string) $lock['state'] : '';

        return match ($state) {
            'none' => 'None',
            'active' => 'Active',
            'expired' => 'Expired',
            default => 'Unknown',
        };
    }

    /**
     * @param array<string,mixed> $lock
     */
    private static function lock_mode_summary(array $lock): string
    {
        $mode = is_scalar($lock['mode'] ?? null) ? (string) $lock['mode'] : '';
        if ($mode === '') {
            return 'None';
        }

        return match ($mode) {
            'cron' => 'WP-Cron',
            'manual' => 'Manual',
            default => self::debug_truncate_text($mode, 40),
        };
    }

    private static function lock_time_summary(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' ? self::debug_truncate_text($value, 32) . ' UTC' : 'Not recorded';
    }

    private static function lock_seconds_summary(mixed $value): string
    {
        if (!is_int($value)) {
            return 'Not recorded';
        }

        return max(0, $value) . ' seconds';
    }

    /**
     * @param array<string,mixed> $lock
     */
    private static function lock_timing_summary(array $lock): string
    {
        $state = is_scalar($lock['state'] ?? null) ? (string) $lock['state'] : '';
        if ($state === 'active') {
            return is_int($lock['expires_in_seconds'] ?? null)
                ? 'Expires in ' . max(0, (int) $lock['expires_in_seconds']) . ' seconds'
                : 'Expiry not recorded';
        }
        if ($state === 'expired') {
            return is_int($lock['expired_seconds'] ?? null)
                ? 'Expired ' . max(0, (int) $lock['expired_seconds']) . ' seconds ago'
                : 'Expired; expiry time not recorded';
        }

        return 'No active lock timing';
    }

    /**
     * @param array<string,mixed> $lock
     */
    private static function lock_advice_summary(array $lock): string
    {
        $advice = is_scalar($lock['advice'] ?? null) ? trim((string) $lock['advice']) : '';

        return $advice !== '' ? $advice : 'No index writer lock advice available.';
    }

    /**
     * @param array<string,mixed> $health
     */
    private static function stale_debt_status_summary(array $health): string
    {
        return !empty($health['stale_debt_active']) ? 'Active - reindex existing content' : 'None recorded';
    }

    /**
     * @param array<string,mixed> $health
     */
    private static function stale_debt_reason_summary(array $health): string
    {
        $labels = [];
        foreach (self::sanitize_stale_debt_reasons($health['stale_debt_reasons'] ?? []) as $reason) {
            $labels[] = self::STALE_DEBT_REASON_LABELS[$reason] ?? $reason;
        }

        return $labels === [] ? 'None recorded' : implode(', ', $labels);
    }

    /**
     * @param array<string,mixed> $health
     */
    private static function stale_debt_progress_summary(array $health): string
    {
        if (empty($health['stale_debt_active'])) {
            return 'No stale reindex work is active.';
        }

        $cursor = max(0, (int) ($health['stale_debt_cursor_post_id'] ?? 0));
        $processed = max(0, (int) ($health['stale_debt_processed_count'] ?? 0));
        $remaining = max(0, (int) ($health['stale_debt_remaining_count'] ?? 0));

        return sprintf(
            'Cursor ID %d; %d indexed %s processed in this sweep; %d indexed %s remain.',
            $cursor,
            $processed,
            self::item_count_label($processed),
            $remaining,
            self::item_count_label($remaining)
        );
    }

    private static function index_profile_hash_summary(mixed $value): string
    {
        $hash = self::sanitize_index_profile_hash($value);

        return $hash !== '' ? substr($hash, 0, 12) : 'Not recorded';
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
     * @param mixed $boosts
     * @return array<string,float>
     */
    private static function settings_field_boosts(mixed $boosts): array
    {
        return self::sanitize_field_boosts($boosts);
    }

    private static function field_boost_summary(mixed $boosts): string
    {
        $parts = [];
        foreach (self::settings_field_boosts($boosts) as $field => $boost) {
            $parts[] = $field . '=' . self::format_field_boost($boost);
        }

        return implode(', ', $parts);
    }

    private static function format_field_boost(float $boost): string
    {
        $formatted = rtrim(rtrim(number_format($boost, 2, '.', ''), '0'), '.');

        return $formatted !== '' ? $formatted : '0';
    }

    /**
     * @param array<string,mixed> $settings
     */
    private static function recency_boost_summary(array $settings): string
    {
        $strength = self::sanitize_recency_boost_strength($settings['recency_boost_strength'] ?? 0.0);
        $half_life = self::sanitize_recency_boost_half_life($settings['recency_boost_half_life_days'] ?? self::RECENCY_BOOST_HALF_LIFE_DEFAULT);
        if ($strength <= 0.0) {
            return 'Disabled';
        }

        return sprintf(
            'Enabled, strength %s, half-life %s days',
            self::format_field_boost($strength),
            self::format_field_boost($half_life)
        );
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
            '%d total (%d waiting updates, %d remaining content, %d stale reindexes, %d failed)',
            max(0, (int) ($health['last_batch_processed'] ?? 0)),
            max(0, (int) ($health['last_batch_queue_processed'] ?? 0)),
            max(0, (int) ($health['last_batch_backfill_processed'] ?? 0)),
            max(0, (int) ($health['last_batch_stale_processed'] ?? 0)),
            max(0, (int) ($health['last_batch_failures'] ?? 0))
        );
    }

    /**
     * @param array<string,mixed> $health
     */
    private static function last_indexing_failure_summary(array $health): string
    {
        $post_label = self::failed_post_label($health);
        $error = is_scalar($health['last_error'] ?? null) ? trim((string) $health['last_error']) : '';
        $failed_at = is_scalar($health['last_failed_at'] ?? null) ? trim((string) $health['last_failed_at']) : '';
        $failures = max(0, (int) ($health['last_batch_failures'] ?? 0));

        if ($post_label === '' && $error === '' && $failed_at === '') {
            return 'No indexing failures recorded.';
        }

        $parts = [];
        $parts[] = $failures > 0
            ? sprintf('%d %s failed in the latest batch', $failures, self::item_count_label($failures))
            : 'Most recent failure';
        if ($post_label !== '') {
            $parts[] = $post_label;
        }
        if ($failed_at !== '') {
            $parts[] = $failed_at . ' UTC';
        }
        if ($error !== '') {
            $parts[] = $error;
        }

        return implode('; ', $parts);
    }

    /**
     * @param array<string,mixed> $recovery
     */
    private static function failure_recovery_count_summary(array $recovery): string
    {
        $total = max(0, (int) ($recovery['total_count'] ?? 0));
        if ($total <= 0) {
            return 'No failed item recovery records.';
        }

        return sprintf(
            '%d tracked (%d retryable, %d waiting, %d quarantined)',
            $total,
            max(0, (int) ($recovery['retryable_count'] ?? 0)),
            max(0, (int) ($recovery['backoff_count'] ?? 0)),
            max(0, (int) ($recovery['quarantined_count'] ?? 0))
        );
    }

    /**
     * @param array<string,mixed> $recovery
     */
    private static function failure_recovery_recent_items_summary(array $recovery): string
    {
        $items = is_array($recovery['recent_items'] ?? null) ? $recovery['recent_items'] : [];
        if ($items === []) {
            return 'No recent failed items.';
        }

        $parts = [];
        foreach (array_slice($items, 0, 5) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = self::sanitize_index_diagnostic_text($item['label'] ?? '', 180, false);
            $status = self::sanitize_failure_recovery_status($item['status'] ?? '') ?: 'retryable';
            if ($label !== '') {
                $parts[] = $label . ' [' . $status . ']';
            }
        }

        return $parts !== []
            ? self::sanitize_index_diagnostic_text(implode('; ', $parts), 600, false)
            : 'No recent failed items.';
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

    /**
     * @param array<string,mixed> $health
     * @return array<string,mixed>
     */
    private static function latest_index_batch_diagnostics_from_health(array $health): array
    {
        return self::sanitize_index_batch_diagnostics($health['latest_batch_diagnostics'] ?? []);
    }

    /**
     * @param array<string,mixed> $diagnostics
     */
    private static function index_batch_trigger_summary(array $diagnostics): string
    {
        $trigger = self::index_batch_mode_label((string) ($diagnostics['trigger'] ?? ''));
        $source = self::index_batch_source_label((string) ($diagnostics['source'] ?? ''));
        $status = self::index_batch_status_label((string) ($diagnostics['status'] ?? ''));

        $parts = [];
        if ($trigger !== '') {
            $parts[] = $trigger;
        }
        if ($source !== '') {
            $parts[] = 'source ' . $source;
        }
        if ($status !== '') {
            $parts[] = 'status ' . $status;
        }

        return $parts !== [] ? implode('; ', $parts) : 'Not recorded';
    }

    /**
     * @param array<string,mixed> $diagnostics
     */
    private static function index_batch_timing_summary(array $diagnostics): string
    {
        $started = is_scalar($diagnostics['started_at'] ?? null) ? trim((string) $diagnostics['started_at']) : '';
        $finished = is_scalar($diagnostics['finished_at'] ?? null) ? trim((string) $diagnostics['finished_at']) : '';
        $elapsed = is_numeric($diagnostics['elapsed_ms'] ?? null) ? (float) $diagnostics['elapsed_ms'] : 0.0;

        $parts = [];
        if ($started !== '') {
            $parts[] = 'started ' . self::debug_truncate_text($started, 32) . ' UTC';
        }
        if ($finished !== '') {
            $parts[] = 'finished ' . self::debug_truncate_text($finished, 32) . ' UTC';
        }
        $parts[] = 'elapsed ' . number_format(max(0.0, $elapsed), 3, '.', '') . ' ms';

        return implode('; ', $parts);
    }

    /**
     * @param array<string,mixed> $diagnostics
     */
    private static function index_batch_queue_summary(array $diagnostics): string
    {
        return sprintf(
            'before %d, after %d, processed %d',
            max(0, (int) ($diagnostics['queue_before'] ?? 0)),
            max(0, (int) ($diagnostics['queue_after'] ?? 0)),
            max(0, (int) ($diagnostics['queue_processed'] ?? 0))
        );
    }

    /**
     * @param array<string,mixed> $diagnostics
     */
    private static function index_batch_backfill_summary(array $diagnostics): string
    {
        return sprintf(
            'scanned %d, selected %d, processed %d',
            max(0, (int) ($diagnostics['backfill_scanned'] ?? 0)),
            max(0, (int) ($diagnostics['backfill_queued'] ?? 0)),
            max(0, (int) ($diagnostics['backfill_processed'] ?? 0))
        );
    }

    /**
     * @param array<string,mixed> $diagnostics
     */
    private static function index_batch_stale_debt_summary(array $diagnostics): string
    {
        $parts = [];
        $parts[] = sprintf(
            'scanned %d, selected %d, processed %d',
            max(0, (int) ($diagnostics['stale_scanned'] ?? 0)),
            max(0, (int) ($diagnostics['stale_queued'] ?? 0)),
            max(0, (int) ($diagnostics['stale_processed'] ?? 0))
        );
        $parts[] = sprintf(
            'cursor %d to %d',
            max(0, (int) ($diagnostics['stale_cursor_before'] ?? 0)),
            max(0, (int) ($diagnostics['stale_cursor_after'] ?? 0))
        );
        if (!empty($diagnostics['stale_completed'])) {
            $parts[] = 'completed';
        }
        if (!empty($diagnostics['stale_profile_changed'])) {
            $parts[] = 'profile changed';
        }

        return implode('; ', $parts);
    }

    /**
     * @param array<string,mixed> $diagnostics
     */
    private static function index_batch_lock_summary(array $diagnostics): string
    {
        $start = self::index_batch_lock_status_label($diagnostics['lock_at_start'] ?? []);
        $end = self::index_batch_lock_status_label($diagnostics['lock_at_end'] ?? []);
        $prevented = !empty($diagnostics['lock_prevented_work']) ? 'yes' : 'no';

        return 'start ' . $start . '; end ' . $end . '; prevented work ' . $prevented;
    }

    /**
     * @param mixed $lock
     */
    private static function index_batch_lock_status_label(mixed $lock): string
    {
        if (!is_array($lock)) {
            return 'not recorded';
        }

        $state = is_scalar($lock['state'] ?? null) ? (string) $lock['state'] : '';
        $mode = self::index_batch_mode_label(is_scalar($lock['mode'] ?? null) ? (string) $lock['mode'] : '');
        $started = is_scalar($lock['started_at'] ?? null) ? trim((string) $lock['started_at']) : '';
        $expires = is_scalar($lock['expires_at'] ?? null) ? trim((string) $lock['expires_at']) : '';
        $label = match ($state) {
            'active' => 'active',
            'expired' => 'expired',
            'none' => 'inactive',
            default => $state !== '' ? self::debug_truncate_text($state, 40) : 'not recorded',
        };
        $parts = [$label];
        if ($mode !== '') {
            $parts[] = $mode;
        }
        if ($started !== '') {
            $parts[] = 'started ' . self::debug_truncate_text($started, 32) . ' UTC';
        }
        if ($expires !== '') {
            $parts[] = 'expires ' . self::debug_truncate_text($expires, 32) . ' UTC';
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string,mixed> $diagnostics
     */
    private static function index_batch_schema_storage_summary(array $diagnostics): string
    {
        $status = self::schema_status_label((string) ($diagnostics['schema_status'] ?? ''));
        $stored = max(0, (int) ($diagnostics['schema_version'] ?? 0));
        $expected = max(0, (int) ($diagnostics['expected_schema_version'] ?? 0));
        $storage = self::debug_truncate_text((string) ($diagnostics['storage_backend'] ?? ''), 80);

        return sprintf(
            '%s (%d/%d); storage %s',
            $status,
            $stored,
            $expected,
            $storage !== '' ? $storage : 'not recorded'
        );
    }

    /**
     * @param array<string,mixed> $diagnostics
     */
    private static function index_batch_reschedule_summary(array $diagnostics): string
    {
        $decision = (string) ($diagnostics['reschedule_decision'] ?? '');

        return match ($decision) {
            'scheduled' => 'Scheduled another WP-Cron run.',
            'scheduled_after_lock_skip' => 'Scheduled another WP-Cron run after lock contention.',
            'not_needed' => 'No follow-up run needed.',
            'not_applicable_manual' => 'Not applicable to manual batches.',
            default => $decision !== '' ? self::debug_truncate_text($decision, 80) : 'Not recorded',
        };
    }

    /**
     * @param array<string,mixed> $diagnostics
     */
    private static function index_batch_stop_summary(array $diagnostics): string
    {
        $reason = (string) ($diagnostics['stop_reason'] ?? '');

        return match ($reason) {
            '' => 'No stop reason recorded.',
            'batch_cap' => 'Stopped at the batch limit.',
            'time_budget' => 'Stopped at the time budget.',
            'memory_budget' => 'Stopped before the memory limit.',
            'callback_budget' => 'Stopped by the caller budget check.',
            'lock_active' => 'Skipped because another batch held the lock.',
            default => self::debug_truncate_text($reason, 80),
        };
    }

    /**
     * @param array<string,mixed> $diagnostics
     */
    private static function index_batch_error_summary(array $diagnostics): string
    {
        $failures = max(0, (int) ($diagnostics['failures'] ?? 0));
        $class = is_scalar($diagnostics['error_class'] ?? null) ? trim((string) $diagnostics['error_class']) : '';
        $message = is_scalar($diagnostics['error_message'] ?? null) ? trim((string) $diagnostics['error_message']) : '';
        $post_id = max(0, (int) ($diagnostics['last_failed_post_id'] ?? 0));
        $title = is_scalar($diagnostics['last_failed_post_title'] ?? null) ? trim((string) $diagnostics['last_failed_post_title']) : '';

        if ($failures <= 0 && $class === '' && $message === '') {
            return 'No batch error recorded.';
        }

        $parts = [];
        if ($failures > 0) {
            $parts[] = sprintf('%d %s failed', $failures, self::item_count_label($failures));
        }
        if ($post_id > 0) {
            $parts[] = trim(($title !== '' ? $title : '(untitled)') . ' (ID ' . $post_id . ')');
        }
        if ($class !== '') {
            $parts[] = $class;
        }
        if ($message !== '') {
            $parts[] = $message;
        }

        return implode('; ', $parts);
    }

    private static function index_batch_mode_label(string $mode): string
    {
        return match ($mode) {
            'cron' => 'WP-Cron',
            'manual' => 'Manual batch',
            default => $mode !== '' ? self::debug_truncate_text($mode, 60) : '',
        };
    }

    private static function index_batch_source_label(string $source): string
    {
        return match ($source) {
            'admin-health' => 'Health tab',
            'wp-cli' => 'WP-CLI',
            'cron' => 'WP-Cron',
            'manual' => 'manual caller',
            default => $source !== '' ? self::debug_truncate_text($source, 60) : '',
        };
    }

    private static function index_batch_status_label(string $status): string
    {
        return match ($status) {
            'success' => 'success',
            'partial_failure' => 'partial failure',
            'failed' => 'failed',
            'skipped_locked' => 'skipped by lock',
            default => $status !== '' ? self::debug_truncate_text($status, 60) : '',
        };
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
        self::render_settings_prefix_threshold_rows($settings);

        echo '<tr><th scope="row"><label for="wp-fts-settings-result-limit">Results per page</label></th><td>';
        echo '<input id="wp-fts-settings-result-limit" type="number" min="1" max="' . self::esc_attr((string) self::MAX_SEARCH_LIMIT) . '" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[result_limit]" value="' . self::esc_attr((string) $settings['result_limit']) . '">';
        echo '<p class="description">Controls how many results are shown on one page or search view when this default is used.</p>';
        echo '</td></tr>';

        self::render_settings_checkbox_row('highlight', 'Highlight matches in search result excerpts', $settings['highlight'], 'Highlights matching words in generated excerpts so readers can see why each result matched.');
        echo '</tbody></table>';

        self::render_settings_section_heading('Ranking weights', 'Higher numbers make matches in that field count more strongly. Changed weights affect content when it is reindexed, because weights are stored in the index.');
        echo '<table class="form-table" role="presentation"><tbody>';
        self::render_settings_field_boost_rows($settings);
        self::render_settings_recency_boost_rows($settings);
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

    /**
     * @param array<string,mixed> $settings
     */
    private static function render_settings_field_boost_rows(array $settings): void
    {
        $boosts = self::settings_field_boosts($settings['field_boosts'] ?? []);
        foreach (self::FIELD_BOOST_LABELS as $field => $copy) {
            $id = 'wp-fts-settings-field-boost-' . self::sanitize_key($field);
            echo '<tr><th scope="row"><label for="' . self::esc_attr($id) . '">' . self::esc_html($copy['label']) . '</label></th><td>';
            echo '<input id="' . self::esc_attr($id) . '" type="number" min="' . self::esc_attr((string) self::FIELD_BOOST_MIN) . '" max="' . self::esc_attr((string) self::FIELD_BOOST_MAX) . '" step="0.01" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[field_boosts][' . self::esc_attr($field) . ']" value="' . self::esc_attr(self::format_field_boost((float) ($boosts[$field] ?? self::FIELD_BOOST_DEFAULTS[$field]))) . '">';
            echo '<p class="description">' . self::esc_html($copy['description']) . '</p>';
            echo '</td></tr>';
        }
    }

    /**
     * @param array<string,mixed> $settings
     */
    private static function render_settings_prefix_threshold_rows(array $settings): void
    {
        $min_length = self::sanitize_prefix_min_length($settings['prefix_min_length'] ?? self::PREFIX_MIN_LENGTH_DEFAULT);
        $max_terms = self::sanitize_prefix_max_terms($settings['prefix_max_terms'] ?? self::PREFIX_MAX_TERMS_DEFAULT);

        echo '<tr><th scope="row"><label for="wp-fts-settings-prefix-min-length">Shortest word beginning</label></th><td>';
        echo '<input id="wp-fts-settings-prefix-min-length" type="number" min="' . self::esc_attr((string) self::PREFIX_MIN_LENGTH_MIN) . '" max="' . self::esc_attr((string) self::PREFIX_MIN_LENGTH_MAX) . '" step="1" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[prefix_min_length]" value="' . self::esc_attr((string) $min_length) . '">';
        echo '<p class="description">Shorter values make word-beginning matches broader, but they can be slower and add noisier alternatives.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wp-fts-settings-prefix-max-terms">Word-beginning alternatives</label></th><td>';
        echo '<input id="wp-fts-settings-prefix-max-terms" type="number" min="' . self::esc_attr((string) self::PREFIX_MAX_TERMS_MIN) . '" max="' . self::esc_attr((string) self::PREFIX_MAX_TERMS_MAX) . '" step="1" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[prefix_max_terms]" value="' . self::esc_attr((string) $max_terms) . '">';
        echo '<p class="description">Limits how many stored terms a broad word beginning can add, bounding search cost while exact and lemma matches still rank first.</p>';
        echo '</td></tr>';
    }

    /**
     * @param array<string,mixed> $settings
     */
    private static function render_settings_recency_boost_rows(array $settings): void
    {
        $strength = self::sanitize_recency_boost_strength($settings['recency_boost_strength'] ?? 0.0);
        $half_life = self::sanitize_recency_boost_half_life($settings['recency_boost_half_life_days'] ?? self::RECENCY_BOOST_HALF_LIFE_DEFAULT);

        echo '<tr><th scope="row"><label for="wp-fts-settings-recency-boost-strength">Recent post boost</label></th><td>';
        echo '<input id="wp-fts-settings-recency-boost-strength" type="number" min="' . self::esc_attr((string) self::RECENCY_BOOST_STRENGTH_MIN) . '" max="' . self::esc_attr((string) self::RECENCY_BOOST_STRENGTH_MAX) . '" step="0.01" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[recency_boost_strength]" value="' . self::esc_attr(self::format_field_boost($strength)) . '">';
        echo '<p class="description">Set to 0 to disable. Values above 0 give newer posts a small ranking lift using indexed GMT post dates, without rebuilding the index.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wp-fts-settings-recency-boost-half-life">Recent post half-life</label></th><td>';
        echo '<input id="wp-fts-settings-recency-boost-half-life" type="number" min="' . self::esc_attr((string) self::RECENCY_BOOST_HALF_LIFE_MIN) . '" max="' . self::esc_attr((string) self::RECENCY_BOOST_HALF_LIFE_MAX) . '" step="1" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[recency_boost_half_life_days]" value="' . self::esc_attr(self::format_field_boost($half_life)) . '">';
        echo '<p class="description">Controls how quickly the lift fades as indexed post dates get older. Changing this only affects query-time ranking.</p>';
        echo '</td></tr>';
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
                    'description' => 'Useful when another search plugin or theme filter should stay in charge on the same search surfaces.',
                ],
            ]
        );
        self::render_settings_known_search_provider_advisory_row($settings);
    }

    /**
     * @param array<string,mixed> $settings
     */
    private static function render_settings_known_search_provider_advisory_row(array $settings): void
    {
        $advisory = self::known_search_provider_advisory($settings);

        echo '<tr><th scope="row">Known search providers</th><td>';
        echo '<p>' . self::esc_html((string) $advisory['summary']) . '</p>';
        echo '<p class="description">Current mode: ' . self::esc_html((string) $advisory['mode_label']) . '. ' . self::esc_html((string) $advisory['recommendation']) . '</p>';
        echo '<p class="description">' . self::esc_html((string) $advisory['detection_note']) . '</p>';
        echo '</td></tr>';
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
        self::render_analyzer_pack_status_matrix();
        self::render_bundled_runtime_lemma_pack_controls();
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
     *   date_before:string,
     *   recency_boost_strength:float,
     *   recency_boost_half_life_days:float
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
     *   date_before:string,
     *   recency_boost_strength:float,
     *   recency_boost_half_life_days:float
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
            'recency_boost_strength' => self::sanitize_recency_boost_strength($settings['recency_boost_strength'] ?? 0.0),
            'recency_boost_half_life_days' => self::sanitize_recency_boost_half_life($settings['recency_boost_half_life_days'] ?? self::RECENCY_BOOST_HALF_LIFE_DEFAULT),
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
            'prefix_min_length' => self::sanitize_prefix_min_length($value['prefix_min_length'] ?? $defaults['prefix_min_length']),
            'prefix_max_terms' => self::sanitize_prefix_max_terms($value['prefix_max_terms'] ?? $defaults['prefix_max_terms']),
            'result_limit' => self::clamp_int($value['result_limit'] ?? $defaults['result_limit'], 1, self::MAX_SEARCH_LIMIT),
            'language_fallback' => array_key_exists('language_fallback', $value) ? self::truthy_admin_value($value['language_fallback']) : $defaults['language_fallback'],
            'field_boosts' => self::sanitize_field_boosts($value['field_boosts'] ?? []),
            'recency_boost_strength' => self::sanitize_recency_boost_strength($value['recency_boost_strength'] ?? ($value['recency_boost'] ?? $defaults['recency_boost_strength'])),
            'recency_boost_half_life_days' => self::sanitize_recency_boost_half_life($value['recency_boost_half_life_days'] ?? $defaults['recency_boost_half_life_days']),
        ];
    }

    /**
     * Sanitize Settings API saves and mark stale index debt only for verified admin saves.
     *
     * Direct callers should use sanitize_settings() when they only need a pure
     * normalized value.
     *
     * @param mixed $value Raw option value from Settings API.
     * @return array<string,mixed>
     */
    public static function sanitize_settings_for_save(mixed $value): array
    {
        $previousSettings = self::settings();
        $previousProfile = self::current_index_profile($previousSettings);
        $sanitized = self::sanitize_settings($value);

        if (self::settings_save_request_can_mark_index_debt()) {
            $currentProfile = self::current_index_profile($sanitized);
            $reasons = self::index_profile_change_reasons($previousProfile, $currentProfile);
            if ($reasons !== []) {
                self::mark_stale_index_debt($reasons, $previousProfile, $currentProfile);
            }
        }

        return $sanitized;
    }

    private static function settings_save_request_can_mark_index_debt(): bool
    {
        if (!function_exists('current_user_can') || !current_user_can(self::ADMIN_CAPABILITY)) {
            return false;
        }

        if (!isset($_POST) || !is_array($_POST)) {
            return false;
        }

        $optionPage = self::request_text_value($_POST, 'option_page', 80);
        $action = self::request_text_value($_POST, 'action', 40);
        if ($optionPage !== self::SETTINGS_GROUP || $action !== 'update') {
            return false;
        }

        if (!function_exists('wp_verify_nonce')) {
            return false;
        }

        $nonce = self::request_text_value($_POST, '_wpnonce', 200);

        return $nonce !== '' && wp_verify_nonce($nonce, self::SETTINGS_GROUP . '-options') !== false;
    }

    /**
     * @param mixed $value
     * @return array<string,float>
     */
    private static function sanitize_field_boosts(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $boosts = [];
        foreach (self::FIELD_BOOST_DEFAULTS as $field => $default) {
            $boosts[$field] = self::sanitize_field_boost_value($value[$field] ?? null, $default);
        }

        return $boosts;
    }

    private static function sanitize_field_boost_value(mixed $value, float $default): float
    {
        if (!is_scalar($value) || !is_numeric($value)) {
            return $default;
        }

        $boost = (float) $value;
        if (!is_finite($boost) || $boost <= 0.0) {
            return $default;
        }

        return self::clamp_float($boost, self::FIELD_BOOST_MIN, self::FIELD_BOOST_MAX);
    }

    public static function sanitize_prefix_min_length(mixed $value): int
    {
        return self::sanitize_prefix_threshold(
            $value,
            self::PREFIX_MIN_LENGTH_DEFAULT,
            self::PREFIX_MIN_LENGTH_MIN,
            self::PREFIX_MIN_LENGTH_MAX
        );
    }

    public static function sanitize_prefix_max_terms(mixed $value): int
    {
        return self::sanitize_prefix_threshold(
            $value,
            self::PREFIX_MAX_TERMS_DEFAULT,
            self::PREFIX_MAX_TERMS_MIN,
            self::PREFIX_MAX_TERMS_MAX
        );
    }

    private static function sanitize_prefix_threshold(mixed $value, int $default, int $min, int $max): int
    {
        if (!is_scalar($value) || !is_numeric($value)) {
            return $default;
        }

        return self::clamp_int($value, $min, $max);
    }

    private static function sanitize_recency_boost_strength(mixed $value): float
    {
        if (is_bool($value)) {
            return $value ? self::RECENCY_BOOST_STRENGTH_DEFAULT : 0.0;
        }
        if (!is_scalar($value) || !is_numeric($value)) {
            return 0.0;
        }

        $strength = (float) $value;
        if (!is_finite($strength) || $strength <= 0.0) {
            return 0.0;
        }

        return self::clamp_float($strength, self::RECENCY_BOOST_STRENGTH_MIN, self::RECENCY_BOOST_STRENGTH_MAX);
    }

    private static function sanitize_recency_boost_half_life(mixed $value): float
    {
        if (!is_scalar($value) || !is_numeric($value)) {
            return self::RECENCY_BOOST_HALF_LIFE_DEFAULT;
        }

        $days = (float) $value;
        if (!is_finite($days) || $days <= 0.0) {
            return self::RECENCY_BOOST_HALF_LIFE_DEFAULT;
        }

        return self::clamp_float($days, self::RECENCY_BOOST_HALF_LIFE_MIN, self::RECENCY_BOOST_HALF_LIFE_MAX);
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
     * Return a bounded, read-only advisory for common third-party search providers.
     *
     * @param array<string,mixed>|null $settings
     * @return array{
     *   mode:string,
     *   mode_label:string,
     *   providers:array<int,array{key:string,label:string,signals:string[]}>,
     *   provider_names:string[],
     *   detected_count:int,
     *   summary:string,
     *   recommendation:string,
     *   detection_note:string
     * }
     */
    private static function known_search_provider_advisory(?array $settings = null): array
    {
        $settings ??= self::settings();
        $mode = self::sanitize_search_provider_compatibility(
            $settings['search_provider_compatibility'] ?? null,
            self::SEARCH_PROVIDER_COMPATIBILITY_PREFER_FTS
        );
        $providers = self::detect_known_search_providers();
        $provider_names = self::known_search_provider_names($providers);

        return [
            'mode' => $mode,
            'mode_label' => self::search_provider_compatibility_label($mode),
            'providers' => $providers,
            'provider_names' => $provider_names,
            'detected_count' => count($providers),
            'summary' => self::known_search_provider_summary($provider_names),
            'recommendation' => self::known_search_provider_recommendation($mode, $provider_names),
            'detection_note' => 'This advisory uses safe activation, option, class, and function signals only; it is not an end-to-end integration certification.',
        ];
    }

    /**
     * @return array<int,array{key:string,label:string,signals:string[]}>
     */
    private static function detect_known_search_providers(): array
    {
        $active_plugins = array_fill_keys(self::known_search_provider_active_plugin_basenames(), true);
        $network_plugins = array_fill_keys(self::known_search_provider_network_active_plugin_basenames(), true);
        $providers = [];

        foreach (self::KNOWN_SEARCH_PROVIDER_FAMILIES as $key => $family) {
            $signals = [];
            foreach (is_array($family['plugin_basenames'] ?? null) ? $family['plugin_basenames'] : [] as $basename) {
                $basename = self::normalize_plugin_basename($basename);
                if ($basename === '') {
                    continue;
                }
                if (isset($active_plugins[$basename])) {
                    $signals['active_plugin'] = true;
                }
                if (isset($network_plugins[$basename])) {
                    $signals['network_active_plugin'] = true;
                }
            }

            foreach (is_array($family['classes'] ?? null) ? $family['classes'] : [] as $class) {
                if (is_scalar($class) && class_exists((string) $class, false)) {
                    $signals['class_exists'] = true;
                }
            }

            foreach (is_array($family['functions'] ?? null) ? $family['functions'] : [] as $function) {
                if (is_scalar($function) && function_exists((string) $function)) {
                    $signals['function_exists'] = true;
                }
            }

            foreach (is_array($family['option_signals'] ?? null) ? $family['option_signals'] : [] as $signal) {
                if (is_array($signal) && self::known_search_provider_option_signal_matches($signal)) {
                    $signals['option'] = true;
                }
            }

            if ($signals === []) {
                continue;
            }

            $label = is_scalar($family['label'] ?? null) ? (string) $family['label'] : (string) $key;
            $providers[] = [
                'key' => (string) $key,
                'label' => self::debug_truncate_text($label, 80),
                'signals' => array_keys($signals),
            ];
        }

        return $providers;
    }

    /**
     * @param array<int,array{key:string,label:string,signals:string[]}> $providers
     * @return string[]
     */
    private static function known_search_provider_names(array $providers): array
    {
        $names = [];
        foreach ($providers as $provider) {
            $label = is_scalar($provider['label'] ?? null) ? trim((string) $provider['label']) : '';
            if ($label !== '') {
                $names[] = self::debug_truncate_text($label, 80);
            }
            if (count($names) >= self::DEBUG_MAX_LIST_ITEMS) {
                break;
            }
        }

        return $names;
    }

    /**
     * @param string[] $provider_names
     */
    private static function known_search_provider_summary(array $provider_names): string
    {
        return $provider_names === []
            ? 'No known search provider detected'
            : implode(', ', $provider_names);
    }

    /**
     * @param string[] $provider_names
     */
    private static function known_search_provider_recommendation(string $mode, array $provider_names): string
    {
        if ($provider_names === []) {
            return 'No compatibility action is recommended from this advisory.';
        }

        if ($mode === self::SEARCH_PROVIDER_COMPATIBILITY_RESPECT_EXISTING) {
            return 'Keep another search provider\'s results is appropriate when the detected provider should stay in charge; use Prefer Language FTS only when Language FTS should override earlier provider results.';
        }

        return 'Prefer Language FTS is appropriate when Language FTS should own eligible searches; switch to Keep another search provider\'s results if the detected provider should answer first.';
    }

    /**
     * @param array<string,mixed> $advisory
     */
    private static function known_search_provider_debug_summary(array $advisory): string
    {
        $provider_names = is_array($advisory['provider_names'] ?? null) ? $advisory['provider_names'] : [];
        $summary = self::known_search_provider_summary(self::debug_normalize_list($provider_names));

        return $summary === 'No known search provider detected' ? 'none' : $summary;
    }

    /**
     * @return string[]
     */
    private static function known_search_provider_active_plugin_basenames(): array
    {
        return self::normalize_plugin_basename_list(self::get_option('active_plugins', []));
    }

    /**
     * @return string[]
     */
    private static function known_search_provider_network_active_plugin_basenames(): array
    {
        $basenames = [];

        if (function_exists('get_site_option')) {
            $basenames = array_merge($basenames, self::normalize_plugin_basename_list(get_site_option('active_sitewide_plugins', [])));
        }

        if (function_exists('get_network_option')) {
            $basenames = array_merge($basenames, self::normalize_plugin_basename_list(get_network_option(null, 'active_sitewide_plugins', [])));
        }

        if (function_exists('is_plugin_active_for_network')) {
            foreach (self::known_search_provider_configured_basenames() as $basename) {
                try {
                    if (is_plugin_active_for_network($basename)) {
                        $basenames[] = $basename;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return array_values(array_unique($basenames));
    }

    /**
     * @return string[]
     */
    private static function known_search_provider_configured_basenames(): array
    {
        $basenames = [];
        foreach (self::KNOWN_SEARCH_PROVIDER_FAMILIES as $family) {
            foreach (is_array($family['plugin_basenames'] ?? null) ? $family['plugin_basenames'] : [] as $basename) {
                $basename = self::normalize_plugin_basename($basename);
                if ($basename !== '') {
                    $basenames[] = $basename;
                }
            }
        }

        return array_values(array_unique($basenames));
    }

    /**
     * @param array<string,mixed> $signal
     */
    private static function known_search_provider_option_signal_matches(array $signal): bool
    {
        $option = is_scalar($signal['option'] ?? null) ? self::sanitize_key((string) $signal['option']) : '';
        if ($option === '') {
            return false;
        }

        $value = self::get_option($option, null);
        if ($value === null || $value === false || $value === '') {
            return false;
        }

        if (isset($signal['contains']) && is_scalar($signal['contains'])) {
            return self::known_search_provider_value_contains($value, (string) $signal['contains']);
        }

        return true;
    }

    private static function known_search_provider_value_contains(mixed $value, string $needle, int $depth = 0): bool
    {
        $needle = strtolower(trim($needle));
        if ($needle === '' || $depth > 3) {
            return false;
        }

        if (is_scalar($value)) {
            return strtolower(trim((string) $value)) === $needle;
        }

        if (!is_array($value)) {
            return false;
        }

        $checked = 0;
        foreach ($value as $key => $item) {
            if (is_scalar($key) && strtolower(trim((string) $key)) === $needle) {
                return true;
            }
            if (self::known_search_provider_value_contains($item, $needle, $depth + 1)) {
                return true;
            }
            $checked++;
            if ($checked >= self::DEBUG_MAX_ASSOC_ITEMS) {
                break;
            }
        }

        return false;
    }

    /**
     * @param mixed $raw
     * @return string[]
     */
    private static function normalize_plugin_basename_list(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $basenames = [];
        foreach ($raw as $key => $value) {
            $candidates = [];
            if (is_scalar($value)) {
                $candidates[] = (string) $value;
            }
            if (is_scalar($key)) {
                $candidates[] = (string) $key;
            }

            foreach ($candidates as $candidate) {
                $basename = self::normalize_plugin_basename($candidate);
                if ($basename !== '') {
                    $basenames[] = $basename;
                }
            }
            if (count($basenames) >= 64) {
                break;
            }
        }

        return array_values(array_unique($basenames));
    }

    private static function normalize_plugin_basename(mixed $basename): string
    {
        if (!is_scalar($basename)) {
            return '';
        }

        $basename = strtolower(trim(str_replace('\\', '/', (string) $basename)));
        $basename = preg_replace('#/+#', '/', $basename) ?? $basename;
        $basename = ltrim($basename, '/');
        if ($basename === '' || strlen($basename) > 160 || !str_contains($basename, '.php')) {
            return '';
        }

        return $basename;
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
     *   prefix_min_length:int,
     *   prefix_max_terms:int,
     *   result_limit:int,
     *   language_fallback:bool,
     *   field_boosts:array<string,float>,
     *   recency_boost_strength:float,
     *   recency_boost_half_life_days:float
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
     * Build the deterministic profile for plugin-owned index-time inputs.
     *
     * @param array<string,mixed>|null $settings Optional sanitized settings snapshot.
     * @return array<string,mixed>
     */
    private static function current_index_profile(?array $settings = null): array
    {
        $settings ??= self::settings();
        $profile = [
            'schema' => self::INDEX_PROFILE_SCHEMA,
            'indexer_signature' => self::INDEX_PROFILE_INDEXER_SIGNATURE,
            'analyzer_signature' => self::runtime_analyzer_index_signature(),
            'runtime_analyzer_options' => self::stored_runtime_analyzer_profile_options(),
            'field_boosts' => self::settings_field_boosts($settings['field_boosts'] ?? []),
            'indexed_scope' => self::index_profile_scope($settings),
        ];
        $profile['hash'] = self::index_profile_hash($profile);

        return $profile;
    }

    private static function runtime_analyzer_index_signature(): string
    {
        try {
            $signature = self::runtime_analyzer()->index_signature();
            if (is_scalar($signature) && trim((string) $signature) !== '') {
                return (string) $signature;
            }
        } catch (Throwable) {
            // Fall through to a conservative class-level signature.
        }

        return 'analyzer:' . WP_FTS_Analyzer::class;
    }

    /**
     * @return array<string,mixed>
     */
    private static function stored_runtime_analyzer_profile_options(): array
    {
        return self::sanitize_runtime_analyzer_options(
            self::raw_analyzer_options_before_filter(
                self::bundled_runtime_lemma_packs_by_lang(),
                self::bundled_runtime_segmenter_packs_by_lang()
            )
        );
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{post_types:string[],frontend_post_statuses:string[],admin_post_statuses:string[]}
     */
    private static function index_profile_scope(array $settings): array
    {
        $postTypes = self::sanitize_post_type_list($settings['index_post_types'] ?? [], self::settings_post_type_choices());
        sort($postTypes, SORT_STRING);

        return [
            'post_types' => $postTypes,
            'frontend_post_statuses' => self::FRONTEND_SEARCH_POST_STATUSES,
            'admin_post_statuses' => self::ADMIN_POST_SEARCH_POST_STATUSES,
        ];
    }

    /**
     * @param array<string,mixed> $profile
     */
    private static function index_profile_hash(array $profile): string
    {
        $hashInput = $profile;
        unset($hashInput['hash']);
        $json = json_encode(self::normalize_index_profile_hash_value($hashInput), JSON_UNESCAPED_SLASHES);

        return sha1(is_string($json) ? $json : serialize($hashInput));
    }

    private static function normalize_index_profile_hash_value(mixed $value): mixed
    {
        if (!is_array($value)) {
            if (is_bool($value) || $value === null || is_int($value) || is_float($value) || is_string($value)) {
                return $value;
            }

            return is_scalar($value) ? (string) $value : get_debug_type($value);
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[is_int($key) ? $key : (string) $key] = self::normalize_index_profile_hash_value($item);
        }
        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<string,mixed> $previousProfile
     * @param array<string,mixed> $currentProfile
     * @return string[]
     */
    private static function index_profile_change_reasons(array $previousProfile, array $currentProfile): array
    {
        if (($previousProfile['hash'] ?? '') === ($currentProfile['hash'] ?? '')) {
            return [];
        }

        $reasons = [];
        if (($previousProfile['runtime_analyzer_options'] ?? []) !== ($currentProfile['runtime_analyzer_options'] ?? [])
            || ($previousProfile['analyzer_signature'] ?? '') !== ($currentProfile['analyzer_signature'] ?? '')
        ) {
            $reasons[] = 'analyzer_options_changed';
        }
        if (($previousProfile['field_boosts'] ?? []) !== ($currentProfile['field_boosts'] ?? [])) {
            $reasons[] = 'field_boosts_changed';
        }
        if (($previousProfile['indexed_scope'] ?? []) !== ($currentProfile['indexed_scope'] ?? [])) {
            $reasons[] = 'indexed_scope_changed';
        }
        if ($reasons === []) {
            $reasons[] = 'index_profile_changed';
        }

        return self::sanitize_stale_debt_reasons($reasons);
    }

    /**
     * @param string[] $reasons
     * @param array<string,mixed> $previousProfile
     * @param array<string,mixed> $currentProfile
     */
    private static function mark_stale_index_debt(array $reasons, array $previousProfile, array $currentProfile): void
    {
        $reasons = self::sanitize_stale_debt_reasons($reasons);
        if ($reasons === []) {
            return;
        }

        $state = self::index_health_state();
        $wasActive = !empty($state['stale_debt_active']);
        $now = self::current_gmt_datetime();
        $existingReasons = $wasActive ? self::sanitize_stale_debt_reasons($state['stale_debt_reasons'] ?? []) : [];
        $state['stale_debt_active'] = true;
        $state['stale_debt_reasons'] = self::sanitize_stale_debt_reasons(array_merge($existingReasons, $reasons));
        $state['index_profile_hash'] = self::sanitize_index_profile_hash($currentProfile['hash'] ?? self::index_profile_hash($currentProfile));
        $state['stale_debt_processing_profile_hash'] = '';
        $state['stale_debt_cursor_post_id'] = 0;
        $state['stale_debt_processed_count'] = 0;
        $state['stale_debt_remaining_count'] = 0;
        if (!$wasActive || self::sanitize_index_profile_hash($state['accepted_index_profile_hash'] ?? '') === '') {
            $state['accepted_index_profile_hash'] = self::sanitize_index_profile_hash($previousProfile['hash'] ?? self::index_profile_hash($previousProfile));
        }
        $state['stale_debt_created_at'] = $wasActive && is_scalar($state['stale_debt_created_at'] ?? null) && (string) $state['stale_debt_created_at'] !== ''
            ? (string) $state['stale_debt_created_at']
            : $now;
        $state['stale_debt_updated_at'] = $now;

        self::set_option(self::INDEX_HEALTH_OPTION, $state);
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
        if (!array_key_exists('field_boosts', $options)) {
            $options['field_boosts'] = self::settings_field_boosts(self::settings()['field_boosts'] ?? []);
        }

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

        return in_array(
            $action,
            [
                self::ADMIN_HEALTH_MANUAL_BATCH_ACTION,
                self::ADMIN_HEALTH_REPAIR_SCHEMA_ACTION,
                self::ADMIN_HEALTH_SCHEDULE_QUEUE_ACTION,
                self::ADMIN_HEALTH_SUPPORT_SNAPSHOT_ACTION,
            ],
            true
        ) ? $action : '';
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

    private static function analyzer_post_action(): string
    {
        $action = self::sanitize_key(self::request_text_value($_POST, self::ADMIN_ANALYZER_ACTION_FIELD, 60));

        return $action === self::ADMIN_ANALYZER_SAVE_BUNDLED_ACTION ? $action : '';
    }

    private static function analyzer_post_action_submitted(): bool
    {
        return self::request_text_value($_POST, self::ADMIN_ANALYZER_ACTION_FIELD, 60) !== '';
    }

    private static function verify_analyzer_nonce(): bool
    {
        $nonce = self::request_text_value($_POST, self::ADMIN_ANALYZER_NONCE_FIELD, 200);
        if ($nonce === '' || !function_exists('wp_verify_nonce')) {
            return false;
        }

        return wp_verify_nonce($nonce, self::ADMIN_ANALYZER_NONCE_ACTION) !== false;
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
     * @return array{terms:array<int,array<string,mixed>>,terms_more:bool,fields:array<int,array<string,mixed>>,fields_more:bool,matched_languages:string[]}
     */
    private static function empty_sandbox_match_explanation(): array
    {
        return [
            'terms' => [],
            'terms_more' => false,
            'fields' => [],
            'fields_more' => false,
            'matched_languages' => [],
        ];
    }

    /**
     * @param mixed $value
     * @return array<int,array<string,mixed>>
     */
    private static function sandbox_explain_results_by_doc(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $doc_id = max(0, (int) ($row['doc_id'] ?? 0));
            if ($doc_id <= 0) {
                continue;
            }
            $rows[$doc_id] = self::sandbox_match_explanation_from_explain_row($row);
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{terms:array<int,array<string,mixed>>,terms_more:bool,fields:array<int,array<string,mixed>>,fields_more:bool,matched_languages:string[]}
     */
    private static function sandbox_match_explanation_from_explain_row(array $row): array
    {
        $explanation = self::empty_sandbox_match_explanation();
        $matches = is_array($row['matches'] ?? null) ? $row['matches'] : [];
        $field_matches = is_array($row['field_matches'] ?? null) ? $row['field_matches'] : [];
        $explanation['terms'] = self::sandbox_match_explanation_terms(
            $matches,
            self::SANDBOX_MATCH_EXPLANATION_TERMS_LIMIT
        );
        $explanation['terms_more'] = !empty($row['matches_more']) || count($matches) > self::SANDBOX_MATCH_EXPLANATION_TERMS_LIMIT;
        $explanation['fields'] = self::sandbox_match_explanation_fields(
            $field_matches
        );
        $explanation['fields_more'] = !empty($row['field_matches_more']) || count($field_matches) > self::SANDBOX_MATCH_EXPLANATION_FIELDS_LIMIT;

        if (isset($row['matched_languages']) && is_array($row['matched_languages'])) {
            $languages = [];
            foreach ($row['matched_languages'] as $language) {
                if (!is_scalar($language)) {
                    continue;
                }
                $language = WP_FTS_TermNamespace::canonicalize_lang((string) $language);
                if ($language !== '') {
                    $languages[$language] = true;
                }
                if (count($languages) >= self::SANDBOX_MATCH_EXPLANATION_TERMS_LIMIT) {
                    break;
                }
            }
            $explanation['matched_languages'] = array_keys($languages);
        }

        return $explanation;
    }

    /**
     * @param array<int,mixed> $terms
     * @return array<int,array<string,mixed>>
     */
    private static function sandbox_match_explanation_terms(array $terms, int $limit): array
    {
        $rows = [];
        foreach ($terms as $term) {
            if (!is_array($term)) {
                continue;
            }
            $rows[] = self::sandbox_match_explanation_term($term);
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param array<int,mixed> $fields
     * @return array<int,array<string,mixed>>
     */
    private static function sandbox_match_explanation_fields(array $fields): array
    {
        $rows = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $field_key = self::sanitize_key(self::sandbox_explain_text($field['field'] ?? '', 80));
            $label = self::sandbox_field_family_label($field_key);
            $rows[] = [
                'field' => $field_key,
                'label' => $label !== '' ? $label : self::sandbox_explain_text($field['field'] ?? 'Field', 80),
                'weight' => self::sandbox_explain_float($field['weight'] ?? 0.0, 6),
                'match_count' => max(0, (int) ($field['match_count'] ?? 0)),
                'weighted_match_count' => self::sandbox_explain_float($field['weighted_match_count'] ?? 0.0, 6),
                'score_subtotal' => self::sandbox_explain_float($field['score_subtotal'] ?? 0.0, 12),
                'score_subtotal_approximate' => !empty($field['score_subtotal_approximate']),
                'terms' => self::sandbox_match_explanation_terms(
                    is_array($field['terms'] ?? null) ? $field['terms'] : [],
                    self::SANDBOX_MATCH_EXPLANATION_TERMS_LIMIT
                ),
                'terms_more' => !empty($field['terms_more']),
            ];
            if (count($rows) >= self::SANDBOX_MATCH_EXPLANATION_FIELDS_LIMIT) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $term
     * @return array<string,mixed>
     */
    private static function sandbox_match_explanation_term(array $term): array
    {
        $key = self::sandbox_explain_text($term['key'] ?? '', 120);
        $surface = self::sandbox_explain_text($term['surface'] ?? '', 120);
        $analyzed = self::sandbox_explain_text($term['term'] ?? '', 120);
        $language = WP_FTS_TermNamespace::canonicalize_lang(self::sandbox_explain_text($term['lang'] ?? '', 20));
        $rank_class = self::sanitize_key(self::sandbox_explain_text($term['rank_class'] ?? '', 40));

        $text = $analyzed !== '' ? $analyzed : $key;
        if ($surface !== '' && $analyzed !== '' && $surface !== $analyzed) {
            $text = $surface . ' -> ' . $analyzed;
        } elseif ($surface !== '') {
            $text = $surface;
        }

        return [
            'key' => $key,
            'term' => $analyzed,
            'surface' => $surface,
            'lang' => $language,
            'rank_class' => $rank_class,
            'label' => trim(($language !== '' ? $language . ':' : '') . $text . ($rank_class !== '' ? ' ' . $rank_class : '')),
        ];
    }

    private static function sandbox_explain_text(mixed $value, int $max_bytes = 120): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return self::debug_truncate_text(self::sanitize_text((string) $value), $max_bytes);
    }

    private static function sandbox_explain_float(mixed $value, int $precision): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }

        return round(max(0.0, (float) $value), max(0, min(12, $precision)));
    }

    private static function sandbox_field_family_label(string $field): string
    {
        if (isset(self::FIELD_BOOST_LABELS[$field]['label'])) {
            return (string) self::FIELD_BOOST_LABELS[$field]['label'];
        }

        return $field !== '' ? ucwords(str_replace(['_', '-'], ' ', $field)) : '';
    }

    /**
     * @param array<string,mixed> $controls
     * @return array{requested_lang:string,query_lang:string,total:int,results:array<int,array<string,mixed>>}
     */
    private static function sandbox_search_results(string $query, string $selected_language, array $controls = [], bool $include_snippets = false, bool $include_explanations = false): array
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
            'explain' => $include_explanations || $trace_id > 0,
            'explain_result_matches' => $include_explanations || $include_snippets,
        ] + self::searcher_prefix_threshold_options($settings, $controls) + self::searcher_recency_boost_options($controls + $settings);
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
                $payload_explain = is_array($payload['explain'] ?? null) ? $payload['explain'] : [];
                $explain_rows_by_doc = $include_explanations
                    ? self::sandbox_explain_results_by_doc($payload_explain['results'] ?? null)
                    : [];
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
                    $visible_row = self::sandbox_result_row($row, $storage, $post_id);
                    if ($include_explanations) {
                        $visible_row['match_explanation'] = $explain_rows_by_doc[$post_id] ?? self::empty_sandbox_match_explanation();
                    }
                    $visible[] = $visible_row;
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
     * @param string[] $selectedLanguages
     * @param array<string,string> $bundledManifests
     * @return array<string,mixed> Stored analyzer option value after the bounded merge.
     */
    private static function save_bundled_runtime_lemma_pack_selection(array $selectedLanguages, array $bundledManifests): array
    {
        $stored = self::get_option(self::ANALYZER_OPTIONS_OPTION, []);
        $options = is_array($stored) ? $stored : [];
        $selected = array_fill_keys($selectedLanguages, true);
        $filterControlled = self::filter_controlled_runtime_lemma_pack_languages();

        foreach ($bundledManifests as $language => $manifestPath) {
            if (isset($filterControlled[$language]) || self::stored_runtime_lemma_pack_has_custom_value($options, $language, $manifestPath)) {
                continue;
            }

            if (isset($selected[$language])) {
                if (!isset($options['lemmatizer_packs_by_lang']) || !is_array($options['lemmatizer_packs_by_lang'])) {
                    $options['lemmatizer_packs_by_lang'] = [];
                }
                $options['lemmatizer_packs_by_lang'] = self::set_language_pack_map_entry(
                    $options['lemmatizer_packs_by_lang'],
                    $language,
                    $manifestPath,
                    true
                );
                continue;
            }

            $options = self::remove_exact_bundled_runtime_lemma_pack_entry($options, $language, $manifestPath);
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
        $options = self::raw_analyzer_options_before_filter($bundled_lemma_packs, $bundled_segmenter_packs);

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
     * Read bundled defaults and the stored WordPress option without applying
     * the higher-precedence analyzer-options filter.
     *
     * @param array<string,bool|string> $bundled_lemma_packs
     * @param array<string,bool|string> $bundled_segmenter_packs
     * @return array<string,mixed>
     */
    private static function raw_analyzer_options_before_filter(array $bundled_lemma_packs, array $bundled_segmenter_packs): array
    {
        $options = [
            'lemmatizer_packs_by_lang' => $bundled_lemma_packs,
            'segmenter_packs_by_lang' => $bundled_segmenter_packs,
        ];

        $stored = self::get_option(self::ANALYZER_OPTIONS_OPTION, []);
        if (is_array($stored)) {
            $options = self::merge_runtime_analyzer_options($options, $stored);
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

    /**
     * @return array<string,string>
     */
    private static function bundled_runtime_lemma_pack_control_manifests(): array
    {
        $manifests = WP_FTS_AnalyzerPackValidator::bundled_unimorph_top_language_pack_manifests();
        foreach ($manifests as $language => $manifestPath) {
            if (!is_string($manifestPath) || !is_file($manifestPath)) {
                unset($manifests[$language]);
            }
        }

        ksort($manifests, SORT_STRING);

        return $manifests;
    }

    /**
     * @param array<string,string> $allowedManifests
     * @return string[]
     */
    private static function selected_bundled_runtime_lemma_pack_languages(array $allowedManifests): array
    {
        return self::request_list_value(
            $_POST,
            self::ADMIN_ANALYZER_LANGUAGE_FIELD,
            array_keys($allowedManifests),
            []
        );
    }

    /**
     * @return array<string,bool>
     */
    private static function filter_controlled_runtime_lemma_pack_languages(): array
    {
        $beforeFilter = self::raw_analyzer_options_before_filter(
            self::bundled_runtime_lemma_packs_by_lang(),
            self::bundled_runtime_segmenter_packs_by_lang()
        );
        $afterFilter = self::raw_runtime_analyzer_options();
        $beforePacks = self::runtime_lemma_pack_options_by_language($beforeFilter);
        $afterPacks = self::runtime_lemma_pack_options_by_language($afterFilter);
        $controlled = [];

        foreach ($afterPacks as $language => $option) {
            if (!array_key_exists($language, $beforePacks) || $beforePacks[$language] !== $option) {
                $controlled[$language] = true;
            }
        }

        return $controlled;
    }

    private static function stored_runtime_lemma_pack_has_custom_value(array $storedOptions, string $language, string $bundledManifestPath): bool
    {
        foreach (self::stored_runtime_lemma_pack_entries_for_language($storedOptions, $language) as $entry) {
            if (!self::lemma_pack_option_points_to_manifest($entry, $bundledManifestPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $storedOptions
     * @return mixed[]
     */
    private static function stored_runtime_lemma_pack_entries_for_language(array $storedOptions, string $language): array
    {
        $language = WP_FTS_TermNamespace::canonicalize_lang($language);
        $entries = [];
        foreach (['lemmatizer_packs_by_lang', 'lemma_packs_by_lang'] as $key) {
            if (!isset($storedOptions[$key]) || !is_array($storedOptions[$key])) {
                continue;
            }

            foreach ($storedOptions[$key] as $entryLanguage => $option) {
                if (!is_scalar($entryLanguage) || trim((string) $entryLanguage) === '') {
                    continue;
                }
                if (WP_FTS_TermNamespace::canonicalize_lang((string) $entryLanguage) === $language) {
                    $entries[] = $option;
                }
            }
        }

        return $entries;
    }

    private static function lemma_pack_option_points_to_manifest(mixed $option, string $manifestPath): bool
    {
        if (is_string($option)) {
            $path = trim($option);
            return ($path !== '' ? $path : '') === $manifestPath;
        }

        if (is_array($option)) {
            foreach (['manifest', 'manifest_path', 'path'] as $key) {
                if (!isset($option[$key]) || !is_scalar($option[$key])) {
                    continue;
                }

                $path = trim((string) $option[$key]);
                if ($path !== '') {
                    return $path === $manifestPath;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function remove_exact_bundled_runtime_lemma_pack_entry(array $options, string $language, string $manifestPath): array
    {
        $language = WP_FTS_TermNamespace::canonicalize_lang($language);
        foreach (['lemmatizer_packs_by_lang', 'lemma_packs_by_lang'] as $key) {
            if (!isset($options[$key]) || !is_array($options[$key])) {
                continue;
            }

            foreach ($options[$key] as $entryLanguage => $option) {
                if (!is_scalar($entryLanguage) || WP_FTS_TermNamespace::canonicalize_lang((string) $entryLanguage) !== $language) {
                    continue;
                }

                if (self::lemma_pack_option_points_to_manifest($option, $manifestPath)) {
                    unset($options[$key][$entryLanguage]);
                }
            }

            if ($options[$key] === []) {
                unset($options[$key]);
            }
        }

        return $options;
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

    private static function render_analyzer_pack_status_matrix(): void
    {
        $rows = self::analyzer_pack_status_matrix_rows();

        echo '<h3>Analyzer pack status matrix</h3>';
        echo '<p>Current WordPress site language: <strong>' . self::esc_html(self::sandbox_language_display(self::site_language())) . '</strong>.</p>';
        echo '<table class="widefat striped wp-fts-analyzer-pack-status-matrix">';
        echo '<thead><tr><th scope="col">Language</th><th scope="col">Runtime support</th><th scope="col">Runtime pack/status</th><th scope="col">Sandbox support</th><th scope="col">Server/runtime requirements</th><th scope="col">Action</th></tr></thead>';
        echo '<tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . self::esc_html($row['language_label']) . '</td>';
            echo '<td>' . self::esc_html($row['runtime_support']) . '</td>';
            echo '<td>' . self::esc_html($row['runtime_pack']) . '</td>';
            echo '<td>' . self::esc_html($row['sandbox_support']) . '</td>';
            echo '<td>' . self::esc_html($row['requirements']) . '</td>';
            echo '<td>' . self::esc_html($row['action']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * @return array<int,array{language_label:string,runtime_support:string,runtime_pack:string,sandbox_support:string,requirements:string,action:string}>
     */
    private static function analyzer_pack_status_matrix_rows(): array
    {
        $siteLanguage = WP_FTS_TermNamespace::canonicalize_lang(self::site_language(), WP_FTS_TermNamespace::DEFAULT_LANG);
        $runtimeStatuses = self::runtime_analyzer_pack_statuses();
        $sandboxStatuses = self::sandbox_demo_analyzer_pack_statuses();
        $manifests = self::bundled_runtime_lemma_pack_control_manifests();
        $controlRows = self::bundled_runtime_lemma_pack_control_rows($manifests);
        $topLanguageConfig = self::top_language_pack_config_by_language();
        $gzipAvailable = WP_FTS_AnalyzerPackValidator::gzip_available();
        $rows = [];

        foreach (self::analyzer_pack_status_matrix_languages($siteLanguage, $runtimeStatuses, $sandboxStatuses, $manifests, $topLanguageConfig) as $language) {
            $controlRow = self::analyzer_pack_status_matrix_control_row_for_language($language, $controlRows);
            $runtimeSupport = self::language_support_details($language, false);
            $runtimeSupportLabel = self::analyzer_pack_status_matrix_support_label($language, $runtimeSupport, $topLanguageConfig);
            $sandboxSupportLabel = self::analyzer_pack_status_matrix_support_label($language, self::language_support_details($language, true), $topLanguageConfig);
            $matchingStatuses = self::analyzer_pack_status_matrix_matching_statuses($runtimeStatuses, $language);
            $languageConfig = self::analyzer_pack_status_matrix_language_config($language, $topLanguageConfig);

            $rows[] = [
                'language_label' => self::sandbox_language_display($language) . ($language === $siteLanguage ? ' - current site language' : ''),
                'runtime_support' => $runtimeSupportLabel,
                'runtime_pack' => self::analyzer_pack_status_matrix_runtime_pack_summary($language, $matchingStatuses, $controlRow, $languageConfig, $gzipAvailable),
                'sandbox_support' => $sandboxSupportLabel === $runtimeSupportLabel
                    ? $sandboxSupportLabel . ' - same as runtime'
                    : $sandboxSupportLabel . ' - differs from runtime',
                'requirements' => self::analyzer_pack_status_matrix_requirements($runtimeSupportLabel, $controlRow, $languageConfig, $gzipAvailable),
                'action' => self::analyzer_pack_status_matrix_action($runtimeSupportLabel, $controlRow, $languageConfig, $gzipAvailable),
            ];
        }

        return $rows;
    }

    /**
     * @param array<int,array{language:string,kind:string,status:string,pack_id:string,fixture_only:bool,reason:string}> $runtimeStatuses
     * @param array<int,array{language:string,kind:string,status:string,pack_id:string,fixture_only:bool,reason:string}> $sandboxStatuses
     * @param array<string,string> $manifests
     * @param array<string,array<string,mixed>> $topLanguageConfig
     * @return string[]
     */
    private static function analyzer_pack_status_matrix_languages(string $siteLanguage, array $runtimeStatuses, array $sandboxStatuses, array $manifests, array $topLanguageConfig): array
    {
        $bounded = [];
        $addLanguage = static function (mixed $language) use (&$bounded): void {
            if (count($bounded) >= self::ANALYZER_PACK_STATUS_MATRIX_MAX_ROWS || !is_scalar($language)) {
                return;
            }

            $language = WP_FTS_TermNamespace::canonicalize_lang((string) $language, WP_FTS_TermNamespace::DEFAULT_LANG);
            if ($language === '' || isset($bounded[$language])) {
                return;
            }

            $bounded[$language] = true;
        };

        $addLanguage($siteLanguage);
        foreach (array_keys($topLanguageConfig) as $language) {
            $addLanguage($language);
        }
        foreach (array_keys($manifests) as $language) {
            $addLanguage($language);
        }
        foreach (array_merge($runtimeStatuses, $sandboxStatuses) as $status) {
            $addLanguage($status['language'] ?? '');
        }
        foreach (array_keys(self::filter_controlled_runtime_lemma_pack_languages()) as $language) {
            $addLanguage($language);
        }

        return array_keys($bounded);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function top_language_pack_config_by_language(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $cache = [];
        $path = dirname(__DIR__) . '/config/top-language-lemma-packs.json';
        $json = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($json)) {
            return $cache;
        }

        $decoded = json_decode($json, true);
        $entries = is_array($decoded) && isset($decoded['languages']) && is_array($decoded['languages'])
            ? $decoded['languages']
            : [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_scalar($entry['language'] ?? null)) {
                continue;
            }
            $language = WP_FTS_TermNamespace::canonicalize_lang((string) $entry['language'], WP_FTS_TermNamespace::DEFAULT_LANG);
            if ($language === '') {
                continue;
            }
            $cache[$language] = $entry;
        }

        return $cache;
    }

    /**
     * @param array<string,array<string,mixed>> $topLanguageConfig
     * @return array<string,mixed>
     */
    private static function analyzer_pack_status_matrix_language_config(string $language, array $topLanguageConfig): array
    {
        $language = WP_FTS_TermNamespace::canonicalize_lang($language, WP_FTS_TermNamespace::DEFAULT_LANG);
        $base = self::base_language($language);

        return $topLanguageConfig[$language] ?? $topLanguageConfig[$base] ?? [];
    }

    /**
     * @param array{label:string,full:bool,reason:string,matched_language:string} $support
     * @param array<string,array<string,mixed>> $topLanguageConfig
     */
    private static function analyzer_pack_status_matrix_support_label(string $language, array $support, array $topLanguageConfig): string
    {
        if ($support['label'] === 'Tokenizer pack') {
            return 'Tokenizer-only support';
        }

        $languageConfig = self::analyzer_pack_status_matrix_language_config($language, $topLanguageConfig);
        if (!$support['full'] && (string) ($languageConfig['support_kind'] ?? '') === 'tokenizer') {
            return 'Tokenizer-only support';
        }

        return $support['label'];
    }

    /**
     * @param array<int,array{language:string,pack_id:string,enabled:bool,editable:bool,status:string}> $controlRows
     * @return array{language:string,pack_id:string,enabled:bool,editable:bool,status:string}|null
     */
    private static function analyzer_pack_status_matrix_control_row_for_language(string $language, array $controlRows): ?array
    {
        $language = WP_FTS_TermNamespace::canonicalize_lang($language, WP_FTS_TermNamespace::DEFAULT_LANG);
        $base = self::base_language($language);
        $baseRow = null;

        foreach ($controlRows as $row) {
            $rowLanguage = WP_FTS_TermNamespace::canonicalize_lang((string) ($row['language'] ?? ''), WP_FTS_TermNamespace::DEFAULT_LANG);
            if ($rowLanguage === $language) {
                return $row;
            }
            if ($base !== '' && $rowLanguage === $base) {
                $baseRow = $row;
            }
        }

        return $baseRow;
    }

    /**
     * @param array<int,array{language:string,kind:string,status:string,pack_id:string,fixture_only:bool,reason:string}> $statuses
     * @return array<int,array{language:string,kind:string,status:string,pack_id:string,fixture_only:bool,reason:string}>
     */
    private static function analyzer_pack_status_matrix_matching_statuses(array $statuses, string $language): array
    {
        $language = WP_FTS_TermNamespace::canonicalize_lang($language, WP_FTS_TermNamespace::DEFAULT_LANG);
        $base = self::base_language($language);
        $matches = [];

        foreach ($statuses as $status) {
            $statusLanguage = WP_FTS_TermNamespace::canonicalize_lang((string) ($status['language'] ?? ''), WP_FTS_TermNamespace::DEFAULT_LANG);
            if ($statusLanguage === $language || ($base !== '' && self::base_language($statusLanguage) === $base)) {
                $matches[] = $status;
            }
        }

        return $matches;
    }

    /**
     * @param array<int,array{language:string,kind:string,status:string,pack_id:string,fixture_only:bool,reason:string}> $statuses
     * @param array{language:string,pack_id:string,enabled:bool,editable:bool,status:string}|null $controlRow
     * @param array<string,mixed> $languageConfig
     */
    private static function analyzer_pack_status_matrix_runtime_pack_summary(string $language, array $statuses, ?array $controlRow, array $languageConfig, bool $gzipAvailable): string
    {
        if ($statuses !== []) {
            $summaries = [];
            foreach ($statuses as $status) {
                $summaries[] = self::analyzer_pack_status_matrix_status_summary($status);
            }
            if ($controlRow !== null) {
                $summaries[] = $controlRow['status'] . ' Bundled pack: ' . $controlRow['pack_id'] . '.';
            }

            return implode(' ', $summaries);
        }

        if ((string) ($languageConfig['support_kind'] ?? '') === 'license_blocked') {
            return 'License-blocked; no bundled runtime pack is offered.';
        }

        if ($controlRow !== null) {
            if (!$gzipAvailable) {
                return 'Bundled pack available but blocked by missing PHP gzip support: ' . $controlRow['pack_id'] . '.';
            }

            return $controlRow['status'] . ' Bundled pack: ' . $controlRow['pack_id'] . '.';
        }

        if ((string) ($languageConfig['support_kind'] ?? '') === 'tokenizer') {
            return 'Tokenizer-only language; no runtime lemma pack is configured.';
        }

        return $languageConfig === []
            ? 'Unsupported; no runtime analyzer pack is configured.'
            : 'Missing; no runtime analyzer pack is configured.';
    }

    /**
     * @param array{language:string,kind:string,status:string,pack_id:string,fixture_only:bool,reason:string} $status
     */
    private static function analyzer_pack_status_matrix_status_summary(array $status): string
    {
        $kind = (string) ($status['kind'] ?? 'pack');
        $state = (string) ($status['status'] ?? 'unknown');
        $packId = (string) ($status['pack_id'] ?? '');
        if ($state === 'active') {
            $scope = !empty($status['fixture_only']) ? 'fixture' : 'full local pack';
            $pack = $packId !== '' ? ' Pack: ' . $packId . '.' : '';

            return 'Active ' . $scope . ' ' . $kind . '.' . $pack;
        }

        $reason = trim((string) ($status['reason'] ?? ''));
        $suffix = $reason !== '' ? ' ' . $reason : '';

        return ucfirst($state) . ' ' . $kind . '.' . $suffix;
    }

    /**
     * @param array{language:string,pack_id:string,enabled:bool,editable:bool,status:string}|null $controlRow
     * @param array<string,mixed> $languageConfig
     */
    private static function analyzer_pack_status_matrix_requirements(string $runtimeSupportLabel, ?array $controlRow, array $languageConfig, bool $gzipAvailable): string
    {
        if ((string) ($languageConfig['support_kind'] ?? '') === 'license_blocked') {
            return 'Bundled pack redistribution is blocked by missing license evidence.';
        }
        if ($controlRow !== null && empty($controlRow['editable'])) {
            return 'Managed outside this UI; verify external pack files remain readable.';
        }
        if ($controlRow !== null && !$gzipAvailable) {
            return 'PHP gzip stream support is required before bundled gzip packs can be enabled.';
        }
        if ($controlRow !== null && !empty($controlRow['editable'])) {
            return 'PHP gzip stream support is available for bundled packs.';
        }
        if ($runtimeSupportLabel === 'Fixture morphology') {
            return 'Fixture coverage only; full production morphology needs a source-backed pack.';
        }
        if ($runtimeSupportLabel === 'Tokenizer-only support') {
            return 'No morphology pack is active; tokenizer-only matching remains available.';
        }

        return 'No bundled runtime pack is available for this language.';
    }

    /**
     * @param array{language:string,pack_id:string,enabled:bool,editable:bool,status:string}|null $controlRow
     * @param array<string,mixed> $languageConfig
     */
    private static function analyzer_pack_status_matrix_action(string $runtimeSupportLabel, ?array $controlRow, array $languageConfig, bool $gzipAvailable): string
    {
        if ((string) ($languageConfig['support_kind'] ?? '') === 'license_blocked') {
            return 'Configure an external pack with usable license evidence, or accept fallback.';
        }
        if ($controlRow !== null && empty($controlRow['editable'])) {
            return 'Keep the external configuration, or change it outside this UI and reindex existing content.';
        }
        if ($controlRow !== null && !$gzipAvailable) {
            return 'Install or enable PHP zlib/gzip support, then enable the bundled pack and reindex existing content.';
        }
        if ($controlRow !== null && !empty($controlRow['enabled'])) {
            return 'Reindex existing content after enabling or changing analyzer packs.';
        }
        if ($controlRow !== null) {
            return 'Enable the bundled pack, save, then reindex existing content.';
        }
        if ($runtimeSupportLabel === 'Full morphology') {
            return 'Reindex existing content after analyzer changes.';
        }
        if ($runtimeSupportLabel === 'Fixture morphology') {
            return 'Configure a source-backed external pack for full morphology, or accept fixture coverage.';
        }
        if ($runtimeSupportLabel === 'Tokenizer-only support') {
            return 'Accept tokenizer-only support, or configure an external tokenizer or lemma pack.';
        }

        return 'Configure an external pack, or accept conservative fallback.';
    }

    private static function render_bundled_runtime_lemma_pack_controls(): void
    {
        echo '<h3>Bundled runtime lemma packs</h3>';
        echo '<p>Enable bundled lemma packs for real site searches. Bundled packs affect real site searches after the content is reindexed. Custom pack paths can still be configured with the <code>' . self::esc_html(self::ANALYZER_OPTIONS_OPTION) . '</code> option or filter. This page does not install external data or create sample content.</p>';

        if (!WP_FTS_AnalyzerPackValidator::gzip_available()) {
            echo '<p class="description">Bundled UniMorph packs are gzip-compressed, but this PHP runtime does not provide gzip stream support. They cannot be enabled for real site searches on this server.</p>';
            return;
        }

        $manifests = self::bundled_runtime_lemma_pack_control_manifests();
        if ($manifests === []) {
            echo '<p>No bundled source-backed runtime lemma packs were found in this plugin install.</p>';
            return;
        }

        $rows = self::bundled_runtime_lemma_pack_control_rows($manifests);
        echo '<form method="post" action="' . self::esc_url(self::admin_page_url(self::ADMIN_ANALYZER_TAB)) . '">';
        self::render_analyzer_nonce_field();
        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_ANALYZER_ACTION_FIELD) . '" value="' . self::esc_attr(self::ADMIN_ANALYZER_SAVE_BUNDLED_ACTION) . '">';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th scope="col">Enable</th><th scope="col">Language</th><th scope="col">Bundled pack</th><th scope="col">Configuration</th></tr></thead>';
        echo '<tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>';
            if ($row['editable']) {
                echo '<label>';
                echo '<input type="checkbox" name="' . self::esc_attr(self::ADMIN_ANALYZER_LANGUAGE_FIELD) . '[]" value="' . self::esc_attr($row['language']) . '"' . ($row['enabled'] ? ' checked="checked"' : '') . '> ';
                echo self::esc_html($row['enabled'] ? 'Enabled' : 'Enable');
                echo '</label>';
            } else {
                echo self::esc_html('External');
            }
            echo '</td>';
            echo '<td>' . self::esc_html(self::sandbox_language_display($row['language'])) . '</td>';
            echo '<td><code>' . self::esc_html($row['pack_id']) . '</code></td>';
            echo '<td>' . self::esc_html($row['status']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary">Save bundled pack choices</button></p>';
        echo '</form>';
    }

    /**
     * @param array<string,string> $manifests
     * @return array<int,array{language:string,pack_id:string,enabled:bool,editable:bool,status:string}>
     */
    private static function bundled_runtime_lemma_pack_control_rows(array $manifests): array
    {
        $stored = self::get_option(self::ANALYZER_OPTIONS_OPTION, []);
        $storedOptions = is_array($stored) ? $stored : [];
        $beforeFilter = self::raw_analyzer_options_before_filter(
            self::bundled_runtime_lemma_packs_by_lang(),
            self::bundled_runtime_segmenter_packs_by_lang()
        );
        $beforePacks = self::runtime_lemma_pack_options_by_language($beforeFilter);
        $filterControlled = self::filter_controlled_runtime_lemma_pack_languages();
        $rows = [];

        foreach ($manifests as $language => $manifestPath) {
            $packId = basename(dirname($manifestPath));
            $customStored = self::stored_runtime_lemma_pack_has_custom_value($storedOptions, $language, $manifestPath);
            $enabled = isset($beforePacks[$language]) && self::lemma_pack_option_points_to_manifest($beforePacks[$language], $manifestPath);
            $editable = !$customStored && !isset($filterControlled[$language]);
            if (isset($filterControlled[$language])) {
                $status = 'Configured outside this UI by the analyzer options filter.';
            } elseif ($customStored) {
                $status = 'Configured outside this UI by the stored analyzer option.';
            } elseif ($enabled) {
                $status = 'Enabled from the bundled manifest.';
            } else {
                $status = 'Available to enable.';
            }

            $rows[] = [
                'language' => $language,
                'pack_id' => $packId,
                'enabled' => $enabled,
                'editable' => $editable,
                'status' => $status,
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['language'], (string) $b['language']));

        return $rows;
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
        echo '<thead><tr><th scope="col">Post ID</th><th scope="col">Title</th><th scope="col">Score</th><th scope="col">Language</th><th scope="col">Search result excerpt</th><th scope="col">Why matched</th>';
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
            echo '<td class="wp-fts-sandbox-detail-cell wp-fts-sandbox-explanation-cell wp-fts-sandbox-detail-pending" data-wp-fts-detail="explanation" data-post-id="' . self::esc_attr((string) $post_id) . '">';
            echo '<span class="spinner is-active" aria-hidden="true"></span> <span class="description">Loading why matched...</span>';
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
    var explanationCells = Array.prototype.slice.call(table.querySelectorAll('[data-wp-fts-detail="explanation"]'));
    var termCells = Array.prototype.slice.call(table.querySelectorAll('[data-wp-fts-detail="terms"]'));
    var detailCells = snippetCells.concat(explanationCells, termCells);
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

    function scalarText(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value);
    }

    function numericText(value) {
        var number = Number(value);
        if (!Number.isFinite(number)) {
            return '';
        }
        return Number.isInteger(number) ? String(number) : String(Math.round(number * 1000000) / 1000000);
    }

    function termLabel(term) {
        if (!term || typeof term !== 'object') {
            return '';
        }
        return scalarText(term.label || term.term || term.surface || term.key);
    }

    function renderExplanation(cell, explanation) {
        cell.classList.remove('wp-fts-sandbox-detail-pending');
        cell.classList.add('wp-fts-sandbox-match-explanation');
        cell.textContent = '';

        if (!explanation || typeof explanation !== 'object') {
            setCellMessage(cell, 'Could not load match explanation.', 'wp-fts-sandbox-detail-error');
            return;
        }

        var terms = Array.isArray(explanation.terms) ? explanation.terms : [];
        var fields = Array.isArray(explanation.fields) ? explanation.fields : [];
        if (terms.length === 0 && fields.length === 0) {
            var empty = document.createElement('span');
            empty.className = 'description';
            empty.textContent = 'No match details available.';
            cell.appendChild(empty);
            return;
        }

        if (terms.length > 0) {
            var termLine = document.createElement('div');
            var termLabels = terms.map(termLabel).filter(function(label) {
                return label !== '';
            });
            termLine.textContent = 'Matched terms: ' + termLabels.join(', ') + (explanation.terms_more ? ', ...' : '');
            cell.appendChild(termLine);
        }

        fields.forEach(function(field) {
            if (!field || typeof field !== 'object') {
                return;
            }

            var parts = [];
            var label = scalarText(field.label || field.field || 'Field');
            var weight = numericText(field.weight);
            var hits = numericText(field.match_count);
            var weightedHits = numericText(field.weighted_match_count);
            var score = numericText(field.score_subtotal);
            if (weight !== '') {
                parts.push('weight ' + weight);
            }
            if (hits !== '') {
                parts.push('hits ' + hits);
            }
            if (weightedHits !== '') {
                parts.push('weighted hits ' + weightedHits);
            }
            if (score !== '') {
                parts.push((field.score_subtotal_approximate ? 'approx. score ' : 'score ') + score);
            }

            var fieldLine = document.createElement('div');
            fieldLine.textContent = label + (parts.length > 0 ? ': ' + parts.join(', ') : '');
            cell.appendChild(fieldLine);

            var fieldTerms = Array.isArray(field.terms) ? field.terms : [];
            if (fieldTerms.length > 0) {
                var fieldTermLabels = fieldTerms.map(termLabel).filter(function(term) {
                    return term !== '';
                });
                if (fieldTermLabels.length > 0) {
                    var fieldTermLine = document.createElement('div');
                    fieldTermLine.className = 'description';
                    fieldTermLine.textContent = 'Field terms: ' + fieldTermLabels.join(', ') + (field.terms_more ? ', ...' : '');
                    cell.appendChild(fieldTermLine);
                }
            }
        });

        if (explanation.fields_more) {
            var more = document.createElement('div');
            more.className = 'description';
            more.textContent = 'More matching fields omitted.';
            cell.appendChild(more);
        }
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

        explanationCells.forEach(function(cell) {
            var postId = cell.getAttribute('data-post-id') || '';
            var row = rows[postId] || null;
            if (!row) {
                setCellMessage(cell, 'Could not load match explanation.', 'wp-fts-sandbox-detail-error');
                return;
            }
            renderExplanation(cell, row.match_explanation || null);
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
            'warning' => 'notice-warning',
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
        $results = self::sandbox_search_results($query, $selected_language, $controls, true, true);

        foreach ($results['results'] as $row) {
            $post_id = max(0, (int) ($row['post_id'] ?? 0));
            if ($post_id <= 0 || !isset($requested[$post_id])) {
                continue;
            }

            $detail = [
                'snippet_html' => self::sanitize_frontend_snippet_html((string) ($row['snippet'] ?? '')),
                'match_explanation' => is_array($row['match_explanation'] ?? null)
                    ? $row['match_explanation']
                    : self::empty_sandbox_match_explanation(),
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

    private static function render_analyzer_nonce_field(): void
    {
        $nonce = self::create_admin_nonce(self::ADMIN_ANALYZER_NONCE_ACTION);

        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_ANALYZER_NONCE_FIELD) . '" value="' . self::esc_attr($nonce) . '">';
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

        if (self::rest_explain_requested($request) && self::current_user_can_search_explain()) {
            return self::search_visible_payload($query, $search_args, true);
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
        return self::search_visible_payload($query, $opts, false)['results'];
    }

    /**
     * Search the index and include operator-only bounded explain diagnostics.
     *
     * Callers without the operator capability receive the normal visible rows
     * plus a small unavailable marker, never the internal explain payload.
     *
     * @return array{results:array<int,array<string,mixed>>,explain?:array<string,mixed>,explain_available?:bool,explain_unavailable_reason?:string}
     */
    public static function search_with_explain(string $query, array $opts = []): array
    {
        if (!self::current_user_can_search_explain()) {
            return [
                'results' => self::search($query, $opts),
                'explain_available' => false,
                'explain_unavailable_reason' => 'not_authorized',
            ];
        }

        return self::search_visible_payload($query, $opts, true);
    }

    /**
     * Search the index and return visible rows, optionally with filtered explain.
     *
     * @param array<string,mixed> $opts
     * @return array{results:array<int,array<string,mixed>>,explain?:array<string,mixed>}
     */
    private static function search_visible_payload(string $query, array $opts = [], bool $include_explain = false): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['results' => []];
        }

        $limit = self::clamp_int($opts['limit'] ?? 10, 1, self::MAX_SEARCH_LIMIT);
        $mode = strtoupper((string) ($opts['mode'] ?? 'OR'));
        $settings = self::settings();
        $search_options = [
            'mode' => $mode,
            'limit' => $limit,
            'prefix_matching' => self::search_prefix_matching_value($opts, $settings),
        ] + self::searcher_prefix_threshold_options($settings, $opts) + self::searcher_recency_boost_options($settings);
        if (isset($opts['lang']) && is_scalar($opts['lang']) && trim((string) $opts['lang']) !== '') {
            $search_options['lang'] = (string) $opts['lang'];
        }
        if ($include_explain) {
            $search_options['include_total'] = true;
            $search_options['explain'] = true;
            $search_options['explain_result_matches'] = true;
        }

        $searcher = new WP_FTS_Searcher(self::storage(false), self::runtime_analyzer());
        $visible = [];
        $explain = [];
        $explain_rows_by_doc = [];
        $offset = 0;
        $batch_limit = self::visibility_refill_batch_limit($limit);
        while (count($visible) < $limit && $offset < self::VISIBILITY_REFILL_MAX_SCAN) {
            $search_options['limit'] = min($batch_limit, self::VISIBILITY_REFILL_MAX_SCAN - $offset);
            $search_options['offset'] = $offset;
            $payload = $searcher->search($query, $search_options);
            if ($include_explain) {
                $rows = is_array($payload['results'] ?? null) ? $payload['results'] : [];
                if (is_array($payload['explain'] ?? null)) {
                    $explain = $payload['explain'];
                    foreach (self::search_explain_results_by_doc($payload['explain']['results'] ?? null) as $doc_id => $row) {
                        $explain_rows_by_doc[$doc_id] = $row;
                    }
                }
            } else {
                $rows = $payload;
            }
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
                $visible = $filtered;
            }
        }

        $result = ['results' => $visible];
        if ($include_explain) {
            $result['explain'] = self::filter_search_explain_for_results($explain, $visible, $explain_rows_by_doc);
        }

        return $result;
    }

    /**
     * @param mixed $rows
     * @return array<int,array<string,mixed>>
     */
    private static function search_explain_results_by_doc(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $by_doc = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $doc_id = max(0, (int) ($row['doc_id'] ?? 0));
            if ($doc_id > 0) {
                $by_doc[$doc_id] = $row;
            }
        }

        return $by_doc;
    }

    /**
     * Keep per-result explain rows aligned to visible returned rows only.
     *
     * @param array<string,mixed> $explain
     * @param array<int,array<string,mixed>> $results
     * @param array<int,array<string,mixed>> $explain_rows_by_doc
     * @return array<string,mixed>
     */
    private static function filter_search_explain_for_results(array $explain, array $results, array $explain_rows_by_doc): array
    {
        $filtered_rows = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $doc_id = max(0, (int) ($result['doc_id'] ?? 0));
            if ($doc_id <= 0 || !self::can_read_post_result($doc_id) || !isset($explain_rows_by_doc[$doc_id])) {
                continue;
            }

            $filtered_rows[] = $explain_rows_by_doc[$doc_id];
        }

        $explain['results'] = $filtered_rows;

        return $explain;
    }

    private static function current_user_can_search_explain(): bool
    {
        return function_exists('current_user_can') && current_user_can(self::ADMIN_CAPABILITY);
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
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $overrides
     * @return array{prefix_min_length:int,prefix_max_terms:int}
     */
    private static function searcher_prefix_threshold_options(array $settings, array $overrides = []): array
    {
        return [
            'prefix_min_length' => self::sanitize_prefix_min_length(
                array_key_exists('prefix_min_length', $overrides)
                    ? $overrides['prefix_min_length']
                    : ($settings['prefix_min_length'] ?? self::PREFIX_MIN_LENGTH_DEFAULT)
            ),
            'prefix_max_terms' => self::sanitize_prefix_max_terms(
                array_key_exists('prefix_max_terms', $overrides)
                    ? $overrides['prefix_max_terms']
                    : ($settings['prefix_max_terms'] ?? self::PREFIX_MAX_TERMS_DEFAULT)
            ),
        ];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,float>
     */
    private static function searcher_recency_boost_options(array $settings): array
    {
        $strength = self::sanitize_recency_boost_strength($settings['recency_boost_strength'] ?? 0.0);
        if ($strength <= 0.0) {
            return [];
        }

        return [
            'recency_boost_strength' => $strength,
            'recency_boost_half_life_days' => self::sanitize_recency_boost_half_life($settings['recency_boost_half_life_days'] ?? self::RECENCY_BOOST_HALF_LIFE_DEFAULT),
        ];
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
                    self::debug_effective_settings(self::settings()),
                    ['search_hook_pipeline' => self::debug_search_hook_pipeline()]
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
                self::debug_effective_settings(self::settings()),
                ['search_hook_pipeline' => self::debug_search_hook_pipeline()]
            );
            return $posts;
        }

        $settings = self::settings();
        if (self::should_preserve_prior_search_provider_result($posts, $settings)) {
            $trace_id = self::debug_record_prior_search_provider_stand_down(
                'frontend search',
                $search_query,
                $settings,
                $posts
            );
            self::debug_remember_search_final_ownership($query, $trace_id, 'earlier_provider_respected', $posts);
            return $posts;
        }

        $trace_id = self::debug_start_trace('frontend search', $search_query, self::debug_effective_settings($settings), [
            'search_hook_pipeline' => self::debug_search_hook_pipeline(),
        ]);
        self::debug_record_prior_search_provider_replacement($trace_id, $posts);
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
        self::debug_remember_search_final_ownership(
            $query,
            $trace_id,
            $posts === null ? 'language_fts_from_null' : 'language_fts_replaced_prior_provider',
            $result['posts']
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
                    self::debug_effective_settings(self::settings()),
                    ['search_hook_pipeline' => self::debug_search_hook_pipeline()]
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
                self::debug_effective_settings(self::settings()),
                ['search_hook_pipeline' => self::debug_search_hook_pipeline()]
            );
            return $posts;
        }

        $settings = self::settings();
        if (self::should_preserve_prior_search_provider_result($posts, $settings)) {
            $trace_id = self::debug_record_prior_search_provider_stand_down(
                'admin post search',
                $search_query,
                $settings,
                $posts
            );
            self::debug_remember_search_final_ownership($query, $trace_id, 'earlier_provider_respected', $posts);
            return $posts;
        }

        $trace_id = self::debug_start_trace('admin post search', $search_query, self::debug_effective_settings($settings), [
            'search_hook_pipeline' => self::debug_search_hook_pipeline(),
        ]);
        self::debug_record_prior_search_provider_replacement($trace_id, $posts);
        $result = self::admin_post_search_result_page($query, $search_query, $trace_id, $settings);
        self::store_admin_post_search_query_state(
            $query,
            $result['total'],
            $result['limit'],
            $result['query_lang'],
            $trace_id
        );
        self::debug_remember_search_final_ownership(
            $query,
            $trace_id,
            $posts === null ? 'language_fts_from_null' : 'language_fts_replaced_prior_provider',
            $result['posts']
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

    /**
     * @param array<string,mixed> $settings
     */
    private static function debug_record_prior_search_provider_stand_down(string $context, string $search_query, array $settings, mixed $posts): int
    {
        $trace_id = self::debug_start_trace($context, $search_query, self::debug_effective_settings($settings), [
            'search_hook_pipeline' => self::debug_search_hook_pipeline(),
        ]);
        if ($trace_id <= 0) {
            return 0;
        }

        $incoming_count = self::prior_search_provider_result_count($posts);
        self::debug_add_count($trace_id, 'incoming_provider_results', $incoming_count);
        self::debug_add_notes($trace_id, [
            'Compatibility mode kept an earlier non-null posts_pre_query result from another search provider.',
            'Incoming provider result count: ' . $incoming_count . '.',
        ]);
        self::debug_finish_trace($trace_id, 'bailed', self::prior_search_provider_result_bailout_reason());

        return $trace_id;
    }

    private static function debug_record_prior_search_provider_replacement(int $trace_id, mixed $posts): void
    {
        if ($trace_id <= 0 || $posts === null) {
            return;
        }

        $incoming_count = self::prior_search_provider_result_count($posts);
        self::debug_add_count($trace_id, 'incoming_provider_results', $incoming_count);
        self::debug_add_count($trace_id, 'prior_provider_responses_replaced');
        self::debug_add_notes($trace_id, [
            'FTS replaced an earlier non-null posts_pre_query result from another search provider.',
            'Incoming provider result count: ' . $incoming_count . '.',
        ]);
    }

    private static function prior_search_provider_result_count(mixed $posts): int
    {
        if (is_countable($posts)) {
            return max(0, count($posts));
        }

        return 1;
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

        if (self::search_query_has_unsupported_page_size($query)) {
            return true;
        }

        if (self::frontend_search_has_unsupported_ordering($query)) {
            return true;
        }

        if (self::frontend_search_has_unsupported_post_types($query)) {
            return true;
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

        if (self::search_query_has_unsupported_page_size($query)) {
            return true;
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
            return self::admin_post_search_has_unsupported_order($query, ['DESC']);
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
            'fields',
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
            'no_found_rows',
            'nopaging',
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

    private static function search_query_has_unsupported_page_size(mixed $query): bool
    {
        $postsPerPage = self::query_var($query, 'posts_per_page', null);

        return is_numeric($postsPerPage) && (int) $postsPerPage <= 0;
    }

    /**
     * Preserve FTS score ordering only when the query asks for normal relevance.
     */
    private static function frontend_search_has_unsupported_ordering(mixed $query): bool
    {
        $orderby = self::query_var($query, 'orderby', null);
        if (self::constraint_value_present($orderby)) {
            if (!is_scalar($orderby) || strtolower(trim((string) $orderby)) !== 'relevance') {
                return true;
            }
        }

        $order = self::query_var($query, 'order', null);
        if (!self::constraint_value_present($order)) {
            return false;
        }

        return !is_scalar($order) || strtoupper(trim((string) $order)) !== 'DESC';
    }

    /**
     * Replace only queries whose complete core post-type scope is indexed.
     */
    private static function frontend_search_has_unsupported_post_types(mixed $query): bool
    {
        $requested = self::query_var($query, 'post_type', null);
        if ($requested === null || $requested === '' || $requested === 'any') {
            $expected = self::public_searchable_post_types();
        } else {
            $expected = self::normalize_string_list($requested);
        }

        $supported = self::frontend_query_post_types($query);
        sort($expected, SORT_STRING);
        sort($supported, SORT_STRING);

        return $expected !== $supported;
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
            self::debug_add_timing($trace_id, 'total', $trace_started);
            self::debug_finish_trace($trace_id, 'bailed', 'Unsupported query shape: no searchable post types or statuses are available.');
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
        $build_frontend_previews = $visibility_context === 'frontend';
        $reuse_search_result_snippets = $build_frontend_previews && !empty($settings['highlight']);
        $prep_started = microtime(true);
        $searcher = new WP_FTS_Searcher(self::storage(false), self::runtime_analyzer());
        $search_options = [
            'mode' => $settings['match_mode'],
            'limit' => self::visibility_refill_batch_limit(max(1, $limit)),
            'offset' => 0,
            'include_total' => true,
            'include_metadata' => true,
            'include_snippets' => $reuse_search_result_snippets,
            'highlight' => $settings['highlight'],
            'snippet_length' => $settings['snippet_length'],
            'prefix_matching' => $settings['prefix_matching'],
            'post_type' => $post_types,
            'post_status' => $post_statuses,
            'explain' => $trace_id > 0,
        ] + self::searcher_prefix_threshold_options($settings) + self::searcher_recency_boost_options($settings);
        $fallback_languages = [];
        if ($settings['language_fallback']) {
            $search_options['language_fallback'] = true;
            $fallback_languages = self::site_fallback_languages();
            $search_options['fallback_languages'] = $fallback_languages;
        }
        $explicit_language = self::query_var($query, 'wp_fts_lang', null);
        $explicit_snippet_language = '';
        $search_languages = [];
        if (is_scalar($explicit_language) && trim((string) $explicit_language) !== '') {
            $explicit_snippet_language = WP_FTS_TermNamespace::canonicalize_lang((string) $explicit_language);
            $search_options['lang'] = $explicit_snippet_language;
            $search_options['query_lang'] = $explicit_snippet_language;
        } else {
            $search_languages = self::frontend_auto_search_languages($search_query);
            if ($search_languages !== []) {
                $search_options['languages'] = $search_languages;
            }
        }
        if ($reuse_search_result_snippets) {
            $search_options['snippet_languages'] = self::frontend_bounded_snippet_languages($explicit_snippet_language);
        }
        self::debug_add_timing($trace_id, 'analyzer/query preparation', $prep_started);

        $posts = [];
        $snippets = [];
        $titles = [];
        $visible_total = 0;
        $search_offset = 0;
        $query_lang = $explicit_snippet_language;
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
                if ($build_frontend_previews) {
                    $document_languages = self::frontend_result_languages($post_id);
                    $document_lang = $document_languages[0] ?? '';
                    $result_lang = $document_lang !== '' ? $document_lang : $query_lang;
                    $snippet_languages = self::frontend_bounded_snippet_languages($explicit_snippet_language, $query_lang, $result_lang, ...$document_languages);
                    $reuse_started = microtime(true);
                    $snippet = self::frontend_reusable_content_snippet($row['snippet'] ?? null, $post);
                    self::debug_add_timing($trace_id, 'snippet reuse', $reuse_started);
                    if ($snippet !== '') {
                        self::debug_add_count($trace_id, 'snippets_reused');
                    } else {
                        if (isset($row['snippet']) && is_scalar($row['snippet']) && trim((string) $row['snippet']) !== '') {
                            self::debug_add_count($trace_id, 'snippet_reuse_misses');
                        }
                        $snippet_started = microtime(true);
                        $snippet = self::frontend_content_preview_snippet($searcher, $post, $search_query, $query_lang, $result_lang, $snippet_languages);
                        self::debug_add_timing($trace_id, 'snippet generation', $snippet_started);
                        if ($snippet !== '') {
                            self::debug_add_count($trace_id, 'snippets_generated');
                        }
                    }
                    if ($snippet !== '') {
                        $snippets[$post_id] = $snippet;
                    }

                    $title_started = microtime(true);
                    $title = self::frontend_title_snippet($searcher, $post, $search_query, $query_lang, $result_lang, $snippet_languages);
                    self::debug_add_timing($trace_id, 'title highlighting', $title_started);
                    if ($title !== '') {
                        $titles[$post_id] = $title;
                        self::debug_add_count($trace_id, 'title_snippets_generated');
                    }
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

    private static function frontend_reusable_content_snippet(mixed $raw_snippet, object $post): string
    {
        if (!is_scalar($raw_snippet) || trim((string) $raw_snippet) === '') {
            return '';
        }

        $snippet = self::sanitize_frontend_snippet_html((string) $raw_snippet);
        if ($snippet === '' || !self::frontend_snippet_matches_post_content($snippet, $post)) {
            return '';
        }

        return $snippet;
    }

    private static function frontend_snippet_matches_post_content(string $snippet, object $post): bool
    {
        $content = isset($post->post_content) && is_scalar($post->post_content)
            ? (string) $post->post_content
            : '';
        if (trim($content) === '') {
            return false;
        }

        $needle = self::frontend_normalized_visible_snippet_text($snippet);
        if ($needle === '') {
            return false;
        }

        $haystack = self::frontend_normalized_visible_snippet_text($content);

        return $haystack !== '' && str_contains($haystack, $needle);
    }

    private static function frontend_normalized_visible_snippet_text(string $html): string
    {
        $text = WP_FTS_Html_Text_Stream::visible_text($html);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = preg_replace('/^\.\.\.\s*/', '', $text) ?? $text;
        $text = preg_replace('/\s*\.\.\.$/', '', $text) ?? $text;

        return trim($text);
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
        $settings = self::settings();
        $options = [
            'highlight' => $settings['highlight'],
            'snippet_length' => $length,
            'prefix_matching' => $settings['prefix_matching'],
        ] + self::searcher_prefix_threshold_options($settings);
        if ($settings['language_fallback']) {
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
    private static function frontend_bounded_snippet_languages(string ...$languages): array
    {
        $bounded = [];
        foreach ($languages as $language) {
            $language = WP_FTS_TermNamespace::canonicalize_lang($language);
            if ($language !== '') {
                $bounded[$language] = true;
            }
        }

        return array_keys($bounded);
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

    /**
     * @return string[]
     */
    private static function frontend_result_languages(int $post_id): array
    {
        try {
            $doc = self::storage(false)->get_doc($post_id);
        } catch (Throwable) {
            return [];
        }

        $languages = [];
        if (is_array($doc['lang_lengths'] ?? null)) {
            foreach ($doc['lang_lengths'] as $language => $length) {
                if (!is_numeric($length) || (int) $length <= 0) {
                    continue;
                }
                $language = WP_FTS_TermNamespace::canonicalize_lang((string) $language);
                if ($language !== '') {
                    $languages[$language] = true;
                }
            }
        }

        foreach ([$doc['primary_lang'] ?? null, $doc['lang'] ?? null] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                $language = WP_FTS_TermNamespace::canonicalize_lang((string) $candidate);
                if ($language !== '') {
                    $languages[$language] = true;
                }
            }
        }

        return array_keys($languages);
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

    private static function site_id_from_value(mixed $site): int
    {
        if (is_object($site)) {
            if (isset($site->blog_id) && is_numeric($site->blog_id)) {
                return max(0, (int) $site->blog_id);
            }

            if (isset($site->id) && is_numeric($site->id)) {
                return max(0, (int) $site->id);
            }
        }

        if (is_numeric($site)) {
            return max(0, (int) $site);
        }

        return 0;
    }

    private static function site_table_prefix(int $site_id): string
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            return '';
        }

        if (method_exists($wpdb, 'get_blog_prefix')) {
            $prefix = $wpdb->get_blog_prefix($site_id);
            if (is_scalar($prefix) && (string) $prefix !== '') {
                return (string) $prefix;
            }
        }

        $base_prefix = isset($wpdb->base_prefix) && is_scalar($wpdb->base_prefix)
            ? (string) $wpdb->base_prefix
            : (isset($wpdb->prefix) && is_scalar($wpdb->prefix) ? (string) $wpdb->prefix : '');
        if ($base_prefix === '') {
            return '';
        }

        return $site_id <= 1 ? $base_prefix : $base_prefix . $site_id . '_';
    }

    /**
     * @return string[]
     */
    private static function fts_table_names(string $prefix): array
    {
        $tables = [];
        foreach (self::FTS_TABLE_SUFFIXES as $suffix) {
            $tables[] = $prefix . $suffix;
        }

        return $tables;
    }

    /**
     * @param array<int,mixed> $tables
     * @return string[]
     */
    private static function unique_table_names(array $tables): array
    {
        $unique = [];
        $seen = [];
        foreach ($tables as $table) {
            if (!is_scalar($table)) {
                continue;
            }

            $table = (string) $table;
            if ($table === '' || isset($seen[$table])) {
                continue;
            }

            $seen[$table] = true;
            $unique[] = $table;
        }

        return $unique;
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
     * Remove finished IDs from the latest queue state without losing later saves.
     *
     * The queue is stored in an option, so processing cannot claim rows
     * atomically. Re-reading here preserves IDs enqueued after the initial
     * snapshot while still dropping the batch this worker finished.
     *
     * @param int[] $processed Successfully processed or recorded-failed IDs.
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
        $started = microtime(true);
        $summary = self::default_index_batch_summary($mode, $batch_size);
        self::initialize_index_batch_summary($summary, $opts, $started);

        $token = self::acquire_index_lock($mode);
        if ($token === null) {
            $summary['skipped_locked'] = true;
            $summary['has_more'] = true;
            $summary['lock_prevented_work'] = true;
            self::remember_index_batch_stop($summary, 'lock_active');
            self::finalize_index_batch_summary($summary, $started);
            self::update_index_health_state($summary);
            if ($mode === 'cron') {
                self::schedule_queue_processor();
            }

            return $summary;
        }

        $thrown = null;
        try {
            $budget = self::index_resource_budget($mode, $opts);
            $analyzer = self::runtime_analyzer();
            $block_backoff = $mode === 'cron';
            $failed_queue_ids = self::process_queue_for_index_batch($batch_size, $budget, $summary, $analyzer, $block_backoff);

            $remaining_capacity = self::remaining_index_batch_capacity($batch_size, $summary);
            $stop_reason = self::index_resource_budget_stop_reason($budget, (int) $summary['processed']);
            if ($remaining_capacity > 0 && $stop_reason === '') {
                self::process_backfill_for_index_batch($remaining_capacity, $budget, $summary, $analyzer, $failed_queue_ids, $block_backoff);
            } elseif ($remaining_capacity > 0) {
                self::remember_index_batch_stop($summary, $stop_reason);
            }

            $remaining_capacity = self::remaining_index_batch_capacity($batch_size, $summary);
            $stop_reason = self::index_resource_budget_stop_reason($budget, (int) $summary['processed']);
            if ($remaining_capacity > 0 && $stop_reason === '') {
                self::process_stale_debt_for_index_batch($remaining_capacity, $budget, $summary, $analyzer, $block_backoff);
            } elseif (self::stale_index_debt_active()) {
                $summary['has_more'] = true;
                if ($remaining_capacity > 0 && $stop_reason !== '') {
                    self::remember_index_batch_stop($summary, $stop_reason);
                }
            }

            if (
                $mode === 'cron'
                && empty($summary['has_more'])
                && self::index_resource_budget_stop_reason($budget, (int) $summary['processed']) === ''
                && self::has_eligible_unindexed_content()
            ) {
                $summary['has_more'] = true;
            }

            if (self::pending_queue() !== []) {
                $summary['has_more'] = true;
            }
        } catch (Throwable $e) {
            $thrown = $e;
            self::remember_index_batch_exception_in_summary($summary, $e);
        } finally {
            self::release_index_lock($token);
            self::finalize_index_batch_summary($summary, $started);
            self::update_index_health_state($summary);
        }

        if ($mode === 'cron' && !empty($summary['has_more'])) {
            self::schedule_queue_processor();
        }

        if ($thrown !== null) {
            throw $thrown;
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
            'stale_processed' => 0,
            'has_more' => false,
            'skipped_locked' => false,
            'stopped_by_budget' => false,
            'last_indexed_post_id' => 0,
            'last_indexed_post_title' => '',
            'last_indexed_at' => '',
            'last_batch_failures' => 0,
            'last_failed_post_id' => 0,
            'last_failed_post_title' => '',
            'last_failed_at' => '',
            'last_error' => '',
            'last_error_class' => '',
            'last_error_message' => '',
            'trigger' => $mode,
            'source' => '',
            'status' => 'started',
            'started_at' => '',
            'finished_at' => '',
            'elapsed_ms' => 0.0,
            'queue_before' => 0,
            'queue_after' => 0,
            'backfill_scanned' => 0,
            'backfill_queued' => 0,
            'stale_scanned' => 0,
            'stale_queued' => 0,
            'stale_debt_cursor_before' => 0,
            'stale_debt_cursor_after' => 0,
            'stale_debt_processed_before' => 0,
            'stale_debt_processed_after' => 0,
            'stale_debt_processing_profile_hash' => '',
            'stale_debt_completed' => false,
            'stale_debt_profile_changed' => false,
            'lock_before' => [],
            'lock_after' => [],
            'lock_prevented_work' => false,
            'schema_status' => '',
            'schema_version' => 0,
            'expected_schema_version' => 0,
            'storage_backend' => '',
            'error_class' => '',
            'error_message' => '',
            'reschedule_decision' => '',
            'stop_reason' => '',
            'failure_records' => [],
            'resolved_failure_post_ids' => [],
            'failure_recovery_skipped' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $opts
     * @param array<string,mixed> $summary
     */
    private static function initialize_index_batch_summary(array &$summary, array $opts, float $started): void
    {
        $schema = self::schema_status();
        $summary['source'] = self::index_batch_source($summary['mode'] ?? 'manual', $opts);
        $summary['started_at'] = self::current_gmt_datetime();
        $summary['queue_before'] = count(self::pending_queue());
        $summary['lock_before'] = self::index_lock_status();
        $summary['schema_status'] = (string) $schema['status'];
        $summary['schema_version'] = max(0, (int) $schema['stored_version']);
        $summary['expected_schema_version'] = max(0, (int) $schema['expected_version']);
        $summary['storage_backend'] = self::index_storage_backend_label();
        $summary['elapsed_ms'] = max(0.0, (microtime(true) - $started) * 1000.0);
    }

    /**
     * @param array<string,mixed> $opts
     */
    private static function index_batch_source(mixed $mode, array $opts): string
    {
        if (isset($opts['source']) && is_scalar($opts['source'])) {
            $source = self::sanitize_key((string) $opts['source']);
            if ($source !== '') {
                return self::debug_truncate_text($source, 60);
            }
        }

        if ($mode === 'cron') {
            return 'cron';
        }

        return self::is_cli_request() ? 'wp-cli' : 'manual';
    }

    private static function index_writer_source(string $source): string
    {
        $source = self::sanitize_key($source);

        return $source !== '' ? self::debug_truncate_text($source, 40) : 'manual';
    }

    /**
     * @param array<string,mixed> $opts
     */
    private static function index_writer_batch_size(array $opts): int
    {
        if (isset($opts['batch_size']) && is_numeric($opts['batch_size'])) {
            return max(0, (int) $opts['batch_size']);
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $opts
     */
    private static function index_writer_processed_count(mixed $result, array $opts): int
    {
        if (isset($opts['processed']) && is_numeric($opts['processed'])) {
            return max(0, (int) $opts['processed']);
        }
        if (is_array($result) && isset($result['processed']) && is_numeric($result['processed'])) {
            return max(0, (int) $result['processed']);
        }
        if (is_bool($result)) {
            return $result ? 1 : 0;
        }
        if (is_int($result) || is_float($result) || (is_scalar($result) && is_numeric($result))) {
            return max(0, (int) $result);
        }

        return 0;
    }

    private static function index_storage_backend_label(): string
    {
        return 'mysql';
    }

    /**
     * @param array<string,mixed> $budget
     * @param array<string,mixed> $summary
     * @return int[] Failed queue IDs recorded and intentionally skipped for the rest of this batch.
     */
    private static function process_queue_for_index_batch(int $limit, array $budget, array &$summary, WP_FTS_Analyzer $analyzer, bool $block_backoff = true): array
    {
        $queue = self::pending_queue();
        if ($queue === [] || $limit <= 0) {
            return [];
        }

        $claimed = array_slice($queue, 0, $limit);
        $remaining = array_slice($queue, count($claimed));
        $processed_ids = [];
        $failed_ids = [];
        $skipped_ids = [];
        $index = 0;

        for ($index = 0, $count = count($claimed); $index < $count; $index++) {
            $stop_reason = self::index_resource_budget_stop_reason($budget, (int) $summary['processed']);
            if ($stop_reason !== '') {
                self::remember_index_batch_stop($summary, $stop_reason);
                break;
            }

            $post_id = (int) $claimed[$index];
            if (self::failure_recovery_post_blocked($post_id, null, $block_backoff)) {
                $skipped_ids[] = $post_id;
                $summary['failure_recovery_skipped'] = max(0, (int) ($summary['failure_recovery_skipped'] ?? 0)) + 1;
                continue;
            }

            $post = self::post_object($post_id);
            try {
                if ($post !== null && self::is_indexable_post($post)) {
                    self::index_post($post, [], $analyzer);
                    self::remember_indexed_post_in_summary($summary, $post);
                } else {
                    self::tombstone_post($post_id);
                    self::remember_resolved_failure_post_in_summary($summary, $post_id);
                }

                $processed_ids[] = $post_id;
                $summary['processed'] = (int) $summary['processed'] + 1;
                $summary['queue_processed'] = (int) $summary['queue_processed'] + 1;
            } catch (Throwable $e) {
                $failed_ids[] = $post_id;
                self::remember_index_failure_in_summary($summary, $post_id, $post, $e);
            }
        }

        $unprocessed_claimed = array_slice($claimed, $index);
        $queue = self::finish_queue_batch(array_merge($processed_ids, $failed_ids, $skipped_ids), array_merge($unprocessed_claimed, $remaining));
        if ($queue !== []) {
            $summary['has_more'] = true;
        }

        return array_merge($failed_ids, $skipped_ids);
    }

    /**
     * @param array<string,mixed> $budget
     * @param array<string,mixed> $summary
     * @param int[] $skip_post_ids
     */
    private static function process_backfill_for_index_batch(int $limit, array $budget, array &$summary, WP_FTS_Analyzer $analyzer, array $skip_post_ids = [], bool $block_backoff = true): void
    {
        if ($limit <= 0) {
            return;
        }

        $skip = [];
        foreach ($skip_post_ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0) {
                $skip[$post_id] = true;
            }
        }
        $blocked_for_recovery = array_fill_keys(self::failure_recovery_blocked_post_ids(null, $block_backoff), true);
        foreach (array_keys($blocked_for_recovery) as $post_id) {
            $skip[$post_id] = true;
        }

        $rows = self::select_eligible_unindexed_posts($limit + count($skip) + 1);
        $summary['backfill_scanned'] = (int) ($summary['backfill_scanned'] ?? 0) + count($rows);
        if ($skip !== []) {
            $filtered = [];
            foreach ($rows as $post) {
                $post_id = (int) ($post->ID ?? 0);
                if (isset($skip[$post_id])) {
                    if (isset($blocked_for_recovery[$post_id])) {
                        $summary['failure_recovery_skipped'] = max(0, (int) ($summary['failure_recovery_skipped'] ?? 0)) + 1;
                    }
                    continue;
                }
                $filtered[] = $post;
            }
            $rows = $filtered;
        }
        if ($rows === []) {
            return;
        }

        $work = array_slice($rows, 0, $limit);
        $summary['backfill_queued'] = (int) ($summary['backfill_queued'] ?? 0) + count($work);
        $processed_rows = 0;
        foreach ($work as $post) {
            $stop_reason = self::index_resource_budget_stop_reason($budget, (int) $summary['processed']);
            if ($stop_reason !== '') {
                self::remember_index_batch_stop($summary, $stop_reason);
                break;
            }

            $post_id = isset($post->ID) ? (int) $post->ID : 0;
            try {
                if (self::is_indexable_post($post)) {
                    self::index_post($post, [], $analyzer);
                    self::remember_indexed_post_in_summary($summary, $post);
                } elseif ($post_id > 0) {
                    self::tombstone_post($post_id);
                    self::remember_resolved_failure_post_in_summary($summary, $post_id);
                }

                $processed_rows++;
                $summary['processed'] = (int) $summary['processed'] + 1;
                $summary['backfill_processed'] = (int) $summary['backfill_processed'] + 1;
            } catch (Throwable $e) {
                self::remember_index_failure_in_summary($summary, $post_id, $post, $e);
            }
        }

        if (count($rows) > $processed_rows) {
            $summary['has_more'] = true;
        }
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function remaining_index_batch_capacity(int $batch_size, array $summary): int
    {
        return max(
            0,
            $batch_size
                - max(0, (int) ($summary['processed'] ?? 0))
                - max(0, (int) ($summary['last_batch_failures'] ?? 0))
        );
    }

    /**
     * @param array<string,mixed> $budget
     * @param array<string,mixed> $summary
     */
    private static function process_stale_debt_for_index_batch(int $limit, array $budget, array &$summary, WP_FTS_Analyzer $analyzer, bool $block_backoff = true): void
    {
        if ($limit <= 0) {
            return;
        }

        $state = self::index_health_state();
        if (empty($state['stale_debt_active'])) {
            return;
        }

        $profile = self::current_index_profile();
        $profile_hash = self::sanitize_index_profile_hash($profile['hash'] ?? self::index_profile_hash($profile));
        if ($profile_hash === '') {
            $summary['has_more'] = true;
            return;
        }

        $cursor = self::stale_debt_processing_cursor($state, $profile_hash);
        $processed_before = self::stale_debt_processing_count($state, $profile_hash);
        $summary['stale_debt_cursor_before'] = $cursor;
        $summary['stale_debt_cursor_after'] = $cursor;
        $summary['stale_debt_processed_before'] = $processed_before;
        $summary['stale_debt_processed_after'] = $processed_before;
        $summary['stale_debt_processing_profile_hash'] = $profile_hash;

        $blocked_post_ids = self::failure_recovery_blocked_post_ids(null, $block_backoff);
        $blocked = array_fill_keys($blocked_post_ids, true);
        $rows = self::select_stale_debt_posts_after_cursor($cursor, $limit + count($blocked_post_ids) + 1);
        $summary['stale_scanned'] = max(0, (int) ($summary['stale_scanned'] ?? 0)) + count($rows);
        if ($rows === []) {
            self::remember_stale_debt_completion($summary, $profile_hash);
            return;
        }

        $work = [];
        $blocked_rows = 0;
        foreach ($rows as $row) {
            $post_id = isset($row->ID) ? (int) $row->ID : 0;
            if ($post_id > 0 && isset($blocked[$post_id])) {
                $blocked_rows++;
                $last_cursor = max($cursor, $post_id);
                $summary['failure_recovery_skipped'] = max(0, (int) ($summary['failure_recovery_skipped'] ?? 0)) + 1;
                continue;
            }

            $work[] = $row;
            if (count($work) >= $limit) {
                break;
            }
        }

        $summary['stale_queued'] = max(0, (int) ($summary['stale_queued'] ?? 0)) + count($work);
        $processed_rows = 0;
        $last_cursor ??= $cursor;

        foreach ($work as $post) {
            $stop_reason = self::index_resource_budget_stop_reason($budget, (int) $summary['processed']);
            if ($stop_reason !== '') {
                self::remember_index_batch_stop($summary, $stop_reason);
                break;
            }

            $post_id = isset($post->ID) ? (int) $post->ID : 0;
            try {
                if (self::is_indexable_post($post)) {
                    self::index_post($post, [], $analyzer);
                    self::remember_indexed_post_in_summary($summary, $post);
                } elseif ($post_id > 0) {
                    self::tombstone_post($post_id);
                    self::remember_resolved_failure_post_in_summary($summary, $post_id);
                }

                if ($post_id > 0) {
                    $last_cursor = max($last_cursor, $post_id);
                }
                $processed_rows++;
                $summary['processed'] = (int) $summary['processed'] + 1;
                $summary['stale_processed'] = (int) $summary['stale_processed'] + 1;
            } catch (Throwable $e) {
                self::remember_index_failure_in_summary($summary, $post_id, $post, $e);
                self::remember_index_batch_stop($summary, 'stale_debt_failure');
                break;
            }
        }

        $summary['stale_debt_cursor_after'] = $last_cursor;
        $summary['stale_debt_processed_after'] = $processed_before + max(0, (int) ($summary['stale_processed'] ?? 0));

        if (count($rows) > ($processed_rows + $blocked_rows)) {
            $summary['has_more'] = true;
        }

        if (
            empty($summary['has_more'])
            && max(0, (int) ($summary['last_batch_failures'] ?? 0)) === 0
            && self::current_index_profile_hash() === $profile_hash
            && self::select_stale_debt_posts_after_cursor($last_cursor, 1) === []
        ) {
            self::remember_stale_debt_completion($summary, $profile_hash);
        } elseif (self::current_index_profile_hash() !== $profile_hash) {
            $summary['stale_debt_profile_changed'] = true;
            $summary['has_more'] = true;
        }
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function remember_stale_debt_completion(array &$summary, string $profile_hash): void
    {
        if (self::current_index_profile_hash() !== $profile_hash) {
            $summary['stale_debt_profile_changed'] = true;
            $summary['has_more'] = true;
            return;
        }

        if (max(0, (int) ($summary['last_batch_failures'] ?? 0)) > 0 || !empty($summary['stopped_by_budget'])) {
            $summary['has_more'] = true;
            return;
        }

        $summary['stale_debt_completed'] = true;
        $summary['stale_debt_processing_profile_hash'] = $profile_hash;
    }

    private static function current_index_profile_hash(): string
    {
        $profile = self::current_index_profile();

        return self::sanitize_index_profile_hash($profile['hash'] ?? self::index_profile_hash($profile));
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
     * @return object[]
     */
    private static function select_stale_debt_posts_after_cursor(int $cursor, int $limit): array
    {
        global $wpdb;

        $limit = max(1, $limit);
        $cursor = max(0, $cursor);
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
        $args[] = $cursor;
        $args[] = $limit;

        $sql = $wpdb->prepare(
            "SELECT p.ID, p.post_content, p.post_title, p.post_excerpt, p.post_type, p.post_status, p.post_password, p.post_date_gmt, p.post_date
FROM {$posts_table} p
INNER JOIN {$docs_table} d ON d.doc_id = p.ID AND d.is_deleted = 0
WHERE p.post_password = ''
  AND (" . implode(' OR ', $clauses) . ")
  AND p.ID > %d
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

    /**
     * @param array<string,mixed> $state
     */
    private static function count_stale_debt_remaining_content(array $state): int
    {
        if (empty($state['stale_debt_active'])) {
            return 0;
        }

        return self::count_indexed_eligible_content_after_cursor(
            self::stale_debt_processing_cursor($state, self::current_index_profile_hash())
        );
    }

    private static function count_indexed_eligible_content_after_cursor(int $cursor): int
    {
        global $wpdb;

        $cursor = max(0, $cursor);
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
        $args[] = $cursor;

        return self::prepared_count(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
FROM {$posts_table} p
INNER JOIN {$docs_table} d ON d.doc_id = p.ID AND d.is_deleted = 0
WHERE p.post_password = ''
  AND (" . implode(' OR ', $clauses) . ")
  AND p.ID > %d",
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
        $blocked = array_fill_keys(self::failure_recovery_blocked_post_ids(), true);
        $rows = self::select_eligible_unindexed_posts(1 + count($blocked));
        foreach ($rows as $row) {
            $post_id = isset($row->ID) ? (int) $row->ID : 0;
            if ($post_id > 0 && !isset($blocked[$post_id])) {
                return true;
            }
        }

        return false;
    }

    private static function stale_index_debt_active(): bool
    {
        return !empty(self::index_health_state()['stale_debt_active']);
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function stale_debt_processing_cursor(array $state, string $current_profile_hash): int
    {
        if (
            empty($state['stale_debt_active'])
            || $current_profile_hash === ''
            || self::sanitize_index_profile_hash($state['stale_debt_processing_profile_hash'] ?? '') !== $current_profile_hash
        ) {
            return 0;
        }

        return max(0, (int) ($state['stale_debt_cursor_post_id'] ?? 0));
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function stale_debt_processing_count(array $state, string $current_profile_hash): int
    {
        if (
            empty($state['stale_debt_active'])
            || $current_profile_hash === ''
            || self::sanitize_index_profile_hash($state['stale_debt_processing_profile_hash'] ?? '') !== $current_profile_hash
        ) {
            return 0;
        }

        return max(0, (int) ($state['stale_debt_processed_count'] ?? 0));
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
        self::remember_resolved_failure_post_in_summary($summary, $post_id);
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function remember_resolved_failure_post_in_summary(array &$summary, int $post_id): void
    {
        if ($post_id <= 0) {
            return;
        }

        if (!isset($summary['resolved_failure_post_ids']) || !is_array($summary['resolved_failure_post_ids'])) {
            $summary['resolved_failure_post_ids'] = [];
        }
        $summary['resolved_failure_post_ids'][] = $post_id;
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function remember_index_failure_in_summary(array &$summary, int $post_id, ?object $post, Throwable $error): void
    {
        $summary['last_batch_failures'] = max(0, (int) ($summary['last_batch_failures'] ?? 0)) + 1;
        $summary['last_failed_post_id'] = max(0, $post_id);
        $summary['last_failed_post_title'] = self::failure_post_title($post_id, $post);
        $summary['last_failed_at'] = self::current_gmt_datetime();
        $summary['last_error'] = self::index_failure_error_summary($error);
        $summary['last_error_class'] = self::sanitize_index_diagnostic_text(get_class($error), self::MAX_INDEX_DIAGNOSTIC_ERROR_CLASS_BYTES, false);
        $summary['last_error_message'] = self::sanitize_index_failure_text($error->getMessage(), self::MAX_INDEX_FAILURE_ERROR_BYTES);
        $summary['error_class'] = $summary['last_error_class'];
        $summary['error_message'] = $summary['last_error_message'];
        if ($post_id > 0) {
            if (!isset($summary['failure_records']) || !is_array($summary['failure_records'])) {
                $summary['failure_records'] = [];
            }
            $summary['failure_records'][] = self::failure_recovery_event_from_failure($summary, $post_id, $post, $error);
        }
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function remember_index_batch_exception_in_summary(array &$summary, Throwable $error): void
    {
        $summary['status'] = 'failed';
        $summary['has_more'] = true;
        $summary['error_class'] = self::sanitize_index_diagnostic_text(get_class($error), self::MAX_INDEX_DIAGNOSTIC_ERROR_CLASS_BYTES, false);
        $summary['error_message'] = self::sanitize_index_failure_text($error->getMessage(), self::MAX_INDEX_FAILURE_ERROR_BYTES);
        $summary['last_error'] = self::index_failure_error_summary($error);
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function remember_index_batch_stop(array &$summary, string $reason): void
    {
        $reason = self::sanitize_index_diagnostic_text($reason, 80, false);
        if ($reason === '') {
            return;
        }

        if (in_array($reason, ['callback_budget', 'time_budget', 'memory_budget'], true)) {
            $summary['stopped_by_budget'] = true;
        }

        if (empty($summary['stop_reason'])) {
            $summary['stop_reason'] = $reason;
        }
        $summary['has_more'] = true;
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function finalize_index_batch_summary(array &$summary, float $started): void
    {
        $summary['finished_at'] = self::current_gmt_datetime();
        $summary['elapsed_ms'] = max(0.0, (microtime(true) - $started) * 1000.0);
        $summary['queue_after'] = count(self::pending_queue());
        $summary['lock_after'] = self::index_lock_status();

        if (
            empty($summary['stop_reason'])
            && !empty($summary['has_more'])
            && max(0, (int) ($summary['processed'] ?? 0)) + max(0, (int) ($summary['last_batch_failures'] ?? 0)) >= max(1, (int) ($summary['batch_size'] ?? 1))
        ) {
            $summary['stop_reason'] = 'batch_cap';
        }

        if (empty($summary['status']) || $summary['status'] === 'started') {
            if (!empty($summary['skipped_locked'])) {
                $summary['status'] = 'skipped_locked';
            } elseif (max(0, (int) ($summary['last_batch_failures'] ?? 0)) > 0) {
                $summary['status'] = max(0, (int) ($summary['processed'] ?? 0)) > 0 ? 'partial_failure' : 'failed';
            } elseif (!empty($summary['error_class']) || !empty($summary['error_message'])) {
                $summary['status'] = 'failed';
            } else {
                $summary['status'] = 'success';
            }
        }

        $summary['reschedule_decision'] = self::index_batch_reschedule_decision($summary);
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function index_batch_reschedule_decision(array $summary): string
    {
        $mode = is_scalar($summary['mode'] ?? null) ? (string) $summary['mode'] : '';
        if ($mode !== 'cron') {
            return 'not_applicable_manual';
        }

        if (empty($summary['has_more'])) {
            return 'not_needed';
        }

        return !empty($summary['skipped_locked']) ? 'scheduled_after_lock_skip' : 'scheduled';
    }

    private static function failure_post_title(int $post_id, ?object $post): string
    {
        $title = $post !== null && isset($post->post_title) && is_scalar($post->post_title)
            ? (string) $post->post_title
            : '';

        if ($title === '' && $post_id > 0) {
            try {
                $title = self::post_title($post_id);
            } catch (Throwable $e) {
                $title = '';
            }
        }

        return self::sanitize_index_failure_text($title, self::MAX_INDEX_FAILURE_TITLE_BYTES, false);
    }

    private static function index_failure_error_summary(Throwable $error): string
    {
        $message = self::sanitize_index_failure_text(
            get_class($error) . ': ' . $error->getMessage(),
            self::MAX_INDEX_FAILURE_ERROR_BYTES
        );

        return $message !== '' ? $message : 'Indexing failed for this post.';
    }

    private static function sanitize_index_failure_text(mixed $value, int $max_bytes, bool $redact_sql = true): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $text = (string) $value;
        $text = preg_replace('/#\d+\s+.*$/s', '', $text);
        if ($redact_sql) {
            $text = preg_replace('/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|TRUNCATE|REPLACE)\b.*$/is', '$1 statement', (string) $text);
        }
        $text = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', (string) $text);
        $text = preg_replace('/\b(?:token|secret|password|passwd|credential|api[_-]?key|authorization|cookie)\s*[:=]\s*[^\s,;&]+/i', '[redacted]', (string) $text);
        $text = preg_replace('/\b(?:sk_live|sk_test|xox[baprs]-|AKIA)[A-Za-z0-9._-]+/i', '[redacted]', (string) $text);
        $text = preg_replace('/\s+/', ' ', self::sanitize_text((string) $text));

        return WP_FTS_Utf8::truncate_bytes(trim((string) $text), max(0, $max_bytes));
    }

    private static function sanitize_index_profile_hash(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $hash = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{40}$/', $hash) === 1 ? $hash : '';
    }

    /**
     * @return string[]
     */
    private static function sanitize_stale_debt_reasons(mixed $value): array
    {
        $items = is_array($value) ? $value : [];
        $reasons = [];
        foreach ($items as $reason) {
            if (!is_scalar($reason)) {
                continue;
            }

            $reason = self::sanitize_key((string) $reason);
            if (!array_key_exists($reason, self::STALE_DEBT_REASON_LABELS)) {
                continue;
            }

            $reasons[$reason] = true;
        }

        return array_keys($reasons);
    }

    private static function sanitize_index_timestamp(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return self::sanitize_index_failure_text($value, 32, false);
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private static function failure_recovery_event_from_failure(array $summary, int $post_id, ?object $post, Throwable $error): array
    {
        $failed_at = is_scalar($summary['last_failed_at'] ?? null) && (string) $summary['last_failed_at'] !== ''
            ? (string) $summary['last_failed_at']
            : self::current_gmt_datetime();

        return [
            'post_id' => max(0, $post_id),
            'title' => self::failure_post_title($post_id, $post),
            'failed_at' => self::sanitize_index_timestamp($failed_at),
            'mode' => self::sanitize_index_diagnostic_text($summary['mode'] ?? '', 40, false),
            'source' => self::sanitize_index_diagnostic_text($summary['source'] ?? '', 60, false),
            'error_class' => self::sanitize_index_diagnostic_text(get_class($error), self::MAX_INDEX_DIAGNOSTIC_ERROR_CLASS_BYTES, false),
            'error_message' => self::sanitize_index_failure_text($error->getMessage(), self::MAX_INDEX_FAILURE_ERROR_BYTES),
            'error_summary' => self::index_failure_error_summary($error),
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $summary
     */
    private static function apply_failure_recovery_summary(array &$state, array $summary): void
    {
        $records = self::failure_recovery_records_by_post_id($state['failure_history'] ?? []);

        $resolved = [];
        if (isset($summary['resolved_failure_post_ids']) && is_array($summary['resolved_failure_post_ids'])) {
            foreach ($summary['resolved_failure_post_ids'] as $post_id) {
                $post_id = (int) $post_id;
                if ($post_id > 0) {
                    $resolved[$post_id] = true;
                }
            }
        }
        foreach (array_keys($resolved) as $post_id) {
            unset($records[$post_id]);
        }

        $events = isset($summary['failure_records']) && is_array($summary['failure_records'])
            ? $summary['failure_records']
            : [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $post_id = max(0, (int) ($event['post_id'] ?? 0));
            if ($post_id <= 0) {
                continue;
            }

            $existing = $records[$post_id] ?? [];
            $failed_at = self::sanitize_index_timestamp($event['failed_at'] ?? self::current_gmt_datetime());
            if ($failed_at === '') {
                $failed_at = self::current_gmt_datetime();
            }

            $failure_count = max(0, (int) ($existing['failure_count'] ?? 0)) + 1;
            $status = $failure_count >= self::FAILURE_RECOVERY_QUARANTINE_AFTER ? 'quarantined' : 'backoff';
            $title = self::sanitize_index_failure_text($event['title'] ?? ($existing['title'] ?? ''), self::MAX_INDEX_FAILURE_TITLE_BYTES, false);

            $records[$post_id] = [
                'post_id' => $post_id,
                'title' => $title,
                'label' => self::failure_recovery_item_label($post_id, $title),
                'status' => $status,
                'failure_count' => $failure_count,
                'attempt_count' => max(0, (int) ($existing['attempt_count'] ?? 0)) + 1,
                'first_failed_at' => self::sanitize_index_timestamp($existing['first_failed_at'] ?? '') ?: $failed_at,
                'last_failed_at' => $failed_at,
                'next_retry_at' => $status === 'backoff' ? self::failure_recovery_next_retry_at($failure_count) : '',
                'mode' => self::sanitize_index_diagnostic_text($event['mode'] ?? '', 40, false),
                'source' => self::sanitize_index_diagnostic_text($event['source'] ?? '', 60, false),
                'error_class' => self::sanitize_index_diagnostic_text($event['error_class'] ?? '', self::MAX_INDEX_DIAGNOSTIC_ERROR_CLASS_BYTES, false),
                'error_message' => self::sanitize_index_failure_text($event['error_message'] ?? '', self::MAX_INDEX_FAILURE_ERROR_BYTES),
                'error_summary' => self::sanitize_index_failure_text($event['error_summary'] ?? '', self::MAX_INDEX_FAILURE_ERROR_BYTES),
            ];
        }

        $state['failure_history'] = self::bound_failure_recovery_records(array_values($records));
    }

    /**
     * @return int[]
     */
    private static function failure_recovery_blocked_post_ids(?int $now = null, bool $include_backoff = true): array
    {
        $ids = [];
        foreach (self::index_health_state()['failure_history'] ?? [] as $record) {
            if (!is_array($record)) {
                continue;
            }

            $status = self::failure_recovery_effective_status($record, $now);
            if ($status === 'quarantined' || ($include_backoff && $status === 'backoff')) {
                $post_id = max(0, (int) ($record['post_id'] ?? 0));
                if ($post_id > 0) {
                    $ids[] = $post_id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private static function failure_recovery_post_blocked(int $post_id, ?int $now = null, bool $include_backoff = true): bool
    {
        if ($post_id <= 0) {
            return false;
        }

        foreach (self::failure_recovery_blocked_post_ids($now, $include_backoff) as $blocked_post_id) {
            if ($blocked_post_id === $post_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function sanitize_failure_recovery_records(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : [];
        $records = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $post_id = max(0, (int) ($item['post_id'] ?? 0));
            if ($post_id <= 0) {
                continue;
            }

            $title = self::sanitize_index_failure_text($item['title'] ?? '', self::MAX_INDEX_FAILURE_TITLE_BYTES, false);
            $failure_count = max(0, (int) ($item['failure_count'] ?? 0));
            $attempt_count = max($failure_count, (int) ($item['attempt_count'] ?? $failure_count));
            $status = self::sanitize_failure_recovery_status($item['status'] ?? '');
            if ($status === '') {
                $status = $failure_count >= self::FAILURE_RECOVERY_QUARANTINE_AFTER ? 'quarantined' : 'retryable';
            }

            $records[] = [
                'post_id' => $post_id,
                'title' => $title,
                'label' => self::failure_recovery_item_label($post_id, $title),
                'status' => $status,
                'failure_count' => $failure_count,
                'attempt_count' => max(0, $attempt_count),
                'first_failed_at' => self::sanitize_index_timestamp($item['first_failed_at'] ?? ''),
                'last_failed_at' => self::sanitize_index_timestamp($item['last_failed_at'] ?? ''),
                'next_retry_at' => self::sanitize_index_timestamp($item['next_retry_at'] ?? ''),
                'mode' => self::sanitize_index_diagnostic_text($item['mode'] ?? '', 40, false),
                'source' => self::sanitize_index_diagnostic_text($item['source'] ?? '', 60, false),
                'error_class' => self::sanitize_index_diagnostic_text($item['error_class'] ?? '', self::MAX_INDEX_DIAGNOSTIC_ERROR_CLASS_BYTES, false),
                'error_message' => self::sanitize_index_failure_text($item['error_message'] ?? '', self::MAX_INDEX_FAILURE_ERROR_BYTES),
                'error_summary' => self::sanitize_index_failure_text($item['error_summary'] ?? '', self::MAX_INDEX_FAILURE_ERROR_BYTES),
            ];
        }

        return self::bound_failure_recovery_records($records);
    }

    /**
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    private static function bound_failure_recovery_records(array $records): array
    {
        $by_post = [];
        foreach ($records as $record) {
            $post_id = max(0, (int) ($record['post_id'] ?? 0));
            if ($post_id <= 0) {
                continue;
            }
            $by_post[$post_id] = self::sanitize_failure_recovery_record($record);
        }

        $bounded = array_values($by_post);
        usort($bounded, static function (array $a, array $b): int {
            $last = strcmp((string) ($b['last_failed_at'] ?? ''), (string) ($a['last_failed_at'] ?? ''));
            if ($last !== 0) {
                return $last;
            }

            return (int) ($b['post_id'] ?? 0) <=> (int) ($a['post_id'] ?? 0);
        });
        $bounded = array_slice($bounded, 0, self::FAILURE_RECOVERY_MAX_ITEMS);

        while ($bounded !== []) {
            $json = function_exists('wp_json_encode')
                ? wp_json_encode($bounded, JSON_UNESCAPED_SLASHES)
                : json_encode($bounded, JSON_UNESCAPED_SLASHES);
            if (is_string($json) && strlen($json) <= self::FAILURE_RECOVERY_MAX_JSON_BYTES) {
                break;
            }
            array_pop($bounded);
        }

        return $bounded;
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private static function sanitize_failure_recovery_record(array $record): array
    {
        $post_id = max(0, (int) ($record['post_id'] ?? 0));
        $title = self::sanitize_index_failure_text($record['title'] ?? '', self::MAX_INDEX_FAILURE_TITLE_BYTES, false);

        return [
            'post_id' => $post_id,
            'title' => $title,
            'label' => self::failure_recovery_item_label($post_id, $title),
            'status' => self::sanitize_failure_recovery_status($record['status'] ?? '') ?: 'retryable',
            'failure_count' => max(0, (int) ($record['failure_count'] ?? 0)),
            'attempt_count' => max(0, (int) ($record['attempt_count'] ?? 0)),
            'first_failed_at' => self::sanitize_index_timestamp($record['first_failed_at'] ?? ''),
            'last_failed_at' => self::sanitize_index_timestamp($record['last_failed_at'] ?? ''),
            'next_retry_at' => self::sanitize_index_timestamp($record['next_retry_at'] ?? ''),
            'mode' => self::sanitize_index_diagnostic_text($record['mode'] ?? '', 40, false),
            'source' => self::sanitize_index_diagnostic_text($record['source'] ?? '', 60, false),
            'error_class' => self::sanitize_index_diagnostic_text($record['error_class'] ?? '', self::MAX_INDEX_DIAGNOSTIC_ERROR_CLASS_BYTES, false),
            'error_message' => self::sanitize_index_failure_text($record['error_message'] ?? '', self::MAX_INDEX_FAILURE_ERROR_BYTES),
            'error_summary' => self::sanitize_index_failure_text($record['error_summary'] ?? '', self::MAX_INDEX_FAILURE_ERROR_BYTES),
        ];
    }

    private static function sanitize_failure_recovery_status(mixed $value): string
    {
        $status = is_scalar($value) ? self::sanitize_key((string) $value) : '';

        return in_array($status, ['retryable', 'backoff', 'quarantined'], true) ? $status : '';
    }

    private static function failure_recovery_item_label(int $post_id, string $title): string
    {
        if ($post_id <= 0) {
            return '';
        }

        $label = trim(($title !== '' ? $title : '(untitled)') . ' (ID ' . $post_id . ')');

        return self::sanitize_index_diagnostic_text($label, 180, false);
    }

    private static function failure_recovery_next_retry_at(int $failure_count): string
    {
        $exponent = max(0, min(10, $failure_count - 1));
        $delay = min(self::FAILURE_RECOVERY_MAX_BACKOFF_SECONDS, self::FAILURE_RECOVERY_BASE_BACKOFF_SECONDS * (2 ** $exponent));

        return gmdate('Y-m-d H:i:s', time() + $delay);
    }

    /**
     * @param array<string,mixed> $record
     */
    private static function failure_recovery_effective_status(array $record, ?int $now = null): string
    {
        $status = self::sanitize_failure_recovery_status($record['status'] ?? '');
        if ($status === 'retryable') {
            return 'retryable';
        }
        if ($status === 'quarantined') {
            return 'quarantined';
        }
        if ($status === 'backoff') {
            $retry_at = self::failure_recovery_retry_timestamp($record['next_retry_at'] ?? '');
            if ($retry_at !== null && $retry_at > ($now ?? time())) {
                return 'backoff';
            }

            return 'retryable';
        }

        return max(0, (int) ($record['failure_count'] ?? 0)) >= self::FAILURE_RECOVERY_QUARANTINE_AFTER
            ? 'quarantined'
            : 'retryable';
    }

    private static function failure_recovery_retry_timestamp(mixed $value): ?int
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        $timestamp = strtotime((string) $value . ' UTC');

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function failure_recovery_records_for_display(mixed $raw): array
    {
        $now = time();
        $records = [];
        foreach (self::sanitize_failure_recovery_records($raw) as $record) {
            $record['status'] = self::failure_recovery_effective_status($record, $now);
            $retry_at = self::failure_recovery_retry_timestamp($record['next_retry_at'] ?? '');
            $record['retry_after_seconds'] = $record['status'] === 'backoff' && $retry_at !== null
                ? max(0, $retry_at - $now)
                : null;
            $records[] = $record;
        }

        return $records;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function failure_recovery_records_by_post_id(mixed $raw): array
    {
        $records = [];
        foreach (self::sanitize_failure_recovery_records($raw) as $record) {
            $post_id = max(0, (int) ($record['post_id'] ?? 0));
            if ($post_id > 0) {
                $records[$post_id] = $record;
            }
        }

        return $records;
    }

    /**
     * @param array<int,array<string,mixed>> $records
     * @return array<string,mixed>
     */
    private static function failure_recovery_record_summary(array $records): array
    {
        $retryable = 0;
        $backoff = 0;
        $quarantined = 0;
        $oldest = '';
        $newest = '';
        foreach ($records as $record) {
            $status = self::sanitize_failure_recovery_status($record['status'] ?? '');
            if ($status === 'retryable') {
                $retryable++;
            } elseif ($status === 'backoff') {
                $backoff++;
            } elseif ($status === 'quarantined') {
                $quarantined++;
            }

            $first = self::sanitize_index_timestamp($record['first_failed_at'] ?? '');
            $last = self::sanitize_index_timestamp($record['last_failed_at'] ?? '');
            if ($first !== '' && ($oldest === '' || strcmp($first, $oldest) < 0)) {
                $oldest = $first;
            }
            if ($last !== '' && ($newest === '' || strcmp($last, $newest) > 0)) {
                $newest = $last;
            }
        }

        return [
            'total_count' => count($records),
            'retryable_count' => $retryable,
            'backoff_count' => $backoff,
            'quarantined_count' => $quarantined,
            'oldest_failed_at' => $oldest,
            'newest_failed_at' => $newest,
        ];
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function failure_recovery_advice(array $summary): string
    {
        if (max(0, (int) ($summary['quarantined_count'] ?? 0)) > 0) {
            return 'Quarantined failed items require explicit operator retry or clearing. Automatic queue, backfill, and stale-debt passes skip them so unrelated indexing can continue.';
        }
        if (max(0, (int) ($summary['backoff_count'] ?? 0)) > 0) {
            return 'Some failed items are in backoff and will not be retried automatically until their next retry time. Use WP-CLI retry only after the underlying issue is fixed.';
        }
        if (max(0, (int) ($summary['retryable_count'] ?? 0)) > 0) {
            return 'Failed items are retryable. Use `wp fts retry-failed-item <post_id>` or let bounded automatic indexing retry eligible items.';
        }
        return 'No failed item recovery records are active.';
    }

    /**
     * Keep the public status payload under the same byte budget advertised for
     * operator automation while preserving newest-first ordering.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<string,mixed> $summary
     * @return array<int,array<string,mixed>>
     */
    private static function bound_failure_recovery_status_items(array $items, array $summary): array
    {
        $bounded = array_values($items);
        while ($bounded !== []) {
            $probe = $summary;
            $probe['recent_items'] = $bounded;
            $probe['advice'] = self::failure_recovery_advice($probe);
            $json = function_exists('wp_json_encode')
                ? wp_json_encode($probe, JSON_UNESCAPED_SLASHES)
                : json_encode($probe, JSON_UNESCAPED_SLASHES);
            if (is_string($json) && strlen($json) <= self::FAILURE_RECOVERY_MAX_JSON_BYTES) {
                break;
            }
            array_pop($bounded);
        }

        return $bounded;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function select_failure_recovery_records_for_action(int $post_id, int $limit): array
    {
        $limit = self::clamp_int($limit, 1, self::FAILURE_RECOVERY_MAX_ITEMS);
        $post_id = max(0, $post_id);
        $records = self::failure_recovery_records_for_display(self::index_health_state()['failure_history'] ?? []);
        if ($post_id > 0) {
            foreach ($records as $record) {
                if ((int) ($record['post_id'] ?? 0) === $post_id) {
                    return [$record];
                }
            }

            return [];
        }

        return array_slice($records, 0, $limit);
    }

    /**
     * @param int[] $post_ids
     */
    private static function clear_failed_item_recovery_metadata(array $post_ids): void
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

        $state = self::index_health_state();
        $records = [];
        $changed = false;
        foreach (self::sanitize_failure_recovery_records($state['failure_history'] ?? []) as $record) {
            $post_id = max(0, (int) ($record['post_id'] ?? 0));
            if ($post_id > 0 && isset($remove[$post_id])) {
                $changed = true;
                continue;
            }
            $records[] = $record;
        }

        if (!$changed) {
            return;
        }

        $state['failure_history'] = self::bound_failure_recovery_records($records);
        self::set_option(self::INDEX_HEALTH_OPTION, $state);
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private static function failure_recovery_action_result(string $action, string $status, array $items, int $queued, string $message): array
    {
        $display_items = self::failure_recovery_records_for_display($items);

        return [
            'schema' => self::FAILURE_RECOVERY_SCHEMA,
            'action' => self::sanitize_index_diagnostic_text($action, 40, false),
            'status' => self::sanitize_index_diagnostic_text($status, 40, false),
            'matched_count' => count($display_items),
            'updated_count' => $status === 'no_match' ? 0 : count($display_items),
            'queued_count' => max(0, $queued),
            'items' => $display_items,
            'pending_queue_count' => count(self::pending_queue()),
            'message' => self::sanitize_index_failure_text($message, self::MAX_INDEX_FAILURE_ERROR_BYTES, false),
        ];
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
        return self::index_resource_budget_stop_reason($budget, $processed) !== '';
    }

    /**
     * @param array<string,mixed> $budget
     */
    private static function index_resource_budget_stop_reason(array $budget, int $processed): string
    {
        if (is_callable($budget['callback'] ?? null) && (bool) call_user_func($budget['callback'], $processed)) {
            return 'callback_budget';
        }

        if (isset($budget['deadline']) && is_float($budget['deadline']) && microtime(true) >= $budget['deadline']) {
            return 'time_budget';
        }

        $memory_limit = isset($budget['memory_limit']) ? (int) $budget['memory_limit'] : 0;
        if ($memory_limit > 0) {
            $memory_margin = isset($budget['memory_margin']) ? max(0, (int) $budget['memory_margin']) : 0;
            if (memory_get_usage(true) + $memory_margin >= $memory_limit) {
                return 'memory_budget';
            }
        }

        return '';
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
     * @return array{state:string,active:bool,mode:string,started_at:string,expires_at:string,age_seconds:?int,expires_in_seconds:?int,expired_seconds:?int,advice:string}
     */
    private static function index_lock_status(): array
    {
        $payload = self::get_option(self::INDEX_LOCK_OPTION, null);
        if (!is_array($payload)) {
            return self::empty_index_lock_status();
        }

        $now = time();
        $active = self::lock_payload_active($payload, $now);
        $started = self::lock_timestamp_value($payload['started_at'] ?? null);
        $expires = self::lock_timestamp_value($payload['expires_at'] ?? null);
        $age = $started !== null ? self::bounded_lock_diagnostic_seconds(max(0, $now - $started)) : null;
        $expires_in = $active && $expires !== null ? self::bounded_lock_diagnostic_seconds(max(0, $expires - $now)) : null;
        $expired_seconds = (!$active && $expires !== null) ? self::bounded_lock_diagnostic_seconds(max(0, $now - $expires)) : null;
        $state = $active ? 'active' : 'expired';

        return [
            'state' => $state,
            'active' => $active,
            'mode' => self::sanitize_index_lock_mode($payload['mode'] ?? null),
            'started_at' => self::lock_timestamp_display($payload['started_at'] ?? null),
            'expires_at' => self::lock_timestamp_display($payload['expires_at'] ?? null),
            'age_seconds' => $age,
            'expires_in_seconds' => $expires_in,
            'expired_seconds' => $expired_seconds,
            'advice' => self::index_lock_advice($state),
        ];
    }

    /**
     * @return array{state:string,active:bool,mode:string,started_at:string,expires_at:string,age_seconds:?int,expires_in_seconds:?int,expired_seconds:?int,advice:string}
     */
    private static function empty_index_lock_status(): array
    {
        return [
            'state' => 'none',
            'active' => false,
            'mode' => '',
            'started_at' => '',
            'expires_at' => '',
            'age_seconds' => null,
            'expires_in_seconds' => null,
            'expired_seconds' => null,
            'advice' => self::index_lock_advice('none'),
        ];
    }

    private static function index_lock_advice(string $state): string
    {
        return match ($state) {
            'active' => 'Another index writer is running; retry shortly and check `wp fts status` for current lock details.',
            'expired' => 'A stale index writer lock remains; the next indexing writer will replace it automatically. Recurring expired locks indicate interrupted or fatal indexing jobs.',
            default => 'No index writer lock is currently held.',
        };
    }

    private static function lock_payload_active(mixed $payload, int $now): bool
    {
        return is_array($payload)
            && isset($payload['expires_at'])
            && is_scalar($payload['expires_at'])
            && (int) $payload['expires_at'] > $now;
    }

    private static function lock_timestamp_value(mixed $value): ?int
    {
        if (!is_scalar($value) || !is_numeric($value)) {
            return null;
        }

        $timestamp = (int) $value;

        return $timestamp > 0 ? $timestamp : null;
    }

    private static function lock_timestamp_display(mixed $value): string
    {
        $timestamp = self::lock_timestamp_value($value);
        if ($timestamp === null) {
            return '';
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private static function bounded_lock_diagnostic_seconds(int $seconds): int
    {
        return min(max(0, $seconds), self::MAX_INDEX_LOCK_DIAGNOSTIC_SECONDS);
    }

    private static function sanitize_index_lock_mode(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return self::debug_truncate_text(self::sanitize_key((string) $value), 40);
    }

    /**
     * @param array<string,mixed>|null $state
     * @return array<string,mixed>
     */
    private static function index_debt_state(?array $state = null): array
    {
        $state ??= self::index_health_state();
        $profile = self::current_index_profile();
        $currentHash = self::sanitize_index_profile_hash($profile['hash'] ?? self::index_profile_hash($profile));

        return [
            'index_profile_hash' => $currentHash,
            'accepted_index_profile_hash' => self::sanitize_index_profile_hash($state['accepted_index_profile_hash'] ?? ''),
            'stale_debt_active' => (bool) ($state['stale_debt_active'] ?? false),
            'stale_debt_reasons' => self::sanitize_stale_debt_reasons($state['stale_debt_reasons'] ?? []),
            'stale_debt_created_at' => self::sanitize_index_timestamp($state['stale_debt_created_at'] ?? ''),
            'stale_debt_updated_at' => self::sanitize_index_timestamp($state['stale_debt_updated_at'] ?? ''),
            'stale_debt_processing_profile_hash' => self::sanitize_index_profile_hash($state['stale_debt_processing_profile_hash'] ?? ''),
            'stale_debt_cursor_post_id' => max(0, (int) ($state['stale_debt_cursor_post_id'] ?? 0)),
            'stale_debt_processed_count' => max(0, (int) ($state['stale_debt_processed_count'] ?? 0)),
            'stale_debt_remaining_count' => max(0, (int) ($state['stale_debt_remaining_count'] ?? 0)),
        ];
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
        $state['last_batch_stale_processed'] = max(0, (int) $state['last_batch_stale_processed']);
        $state['last_indexed_post_id'] = max(0, (int) $state['last_indexed_post_id']);
        $state['last_indexed_post_title'] = is_scalar($state['last_indexed_post_title']) ? (string) $state['last_indexed_post_title'] : '';
        $state['last_indexed_at'] = is_scalar($state['last_indexed_at']) ? (string) $state['last_indexed_at'] : '';
        $state['last_batch_failures'] = max(0, (int) $state['last_batch_failures']);
        $state['last_failed_post_id'] = max(0, (int) $state['last_failed_post_id']);
        $state['last_failed_post_title'] = self::sanitize_index_failure_text($state['last_failed_post_title'], self::MAX_INDEX_FAILURE_TITLE_BYTES, false);
        $state['last_failed_at'] = self::sanitize_index_failure_text($state['last_failed_at'], 32, false);
        $state['last_error'] = self::sanitize_index_failure_text($state['last_error'], self::MAX_INDEX_FAILURE_ERROR_BYTES);
        $state['last_run_at'] = is_scalar($state['last_run_at']) ? (string) $state['last_run_at'] : '';
        $state['last_mode'] = is_scalar($state['last_mode']) ? (string) $state['last_mode'] : '';
        $state['has_more'] = (bool) $state['has_more'];
        $state['last_skipped_locked'] = (bool) $state['last_skipped_locked'];
        $state['last_stopped_by_budget'] = (bool) $state['last_stopped_by_budget'];
        $state['latest_batch_diagnostics'] = self::sanitize_index_batch_diagnostics($state['latest_batch_diagnostics'] ?? []);
        $state['index_profile_hash'] = self::sanitize_index_profile_hash($state['index_profile_hash'] ?? '');
        $state['accepted_index_profile_hash'] = self::sanitize_index_profile_hash($state['accepted_index_profile_hash'] ?? '');
        $state['stale_debt_active'] = (bool) $state['stale_debt_active'];
        $state['stale_debt_reasons'] = self::sanitize_stale_debt_reasons($state['stale_debt_reasons'] ?? []);
        $state['stale_debt_created_at'] = self::sanitize_index_timestamp($state['stale_debt_created_at'] ?? '');
        $state['stale_debt_updated_at'] = self::sanitize_index_timestamp($state['stale_debt_updated_at'] ?? '');
        $state['stale_debt_processing_profile_hash'] = self::sanitize_index_profile_hash($state['stale_debt_processing_profile_hash'] ?? '');
        $state['stale_debt_cursor_post_id'] = max(0, (int) ($state['stale_debt_cursor_post_id'] ?? 0));
        $state['stale_debt_processed_count'] = max(0, (int) ($state['stale_debt_processed_count'] ?? 0));
        $state['stale_debt_remaining_count'] = max(0, (int) ($state['stale_debt_remaining_count'] ?? 0));
        $state['failure_history'] = self::sanitize_failure_recovery_records($state['failure_history'] ?? []);

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
            'last_batch_stale_processed' => 0,
            'has_more' => false,
            'last_indexed_post_id' => 0,
            'last_indexed_post_title' => '',
            'last_indexed_at' => '',
            'last_batch_failures' => 0,
            'last_failed_post_id' => 0,
            'last_failed_post_title' => '',
            'last_failed_at' => '',
            'last_error' => '',
            'last_skipped_locked' => false,
            'last_stopped_by_budget' => false,
            'last_mode' => '',
            'last_run_at' => '',
            'latest_batch_diagnostics' => [],
            'index_profile_hash' => '',
            'accepted_index_profile_hash' => '',
            'stale_debt_active' => false,
            'stale_debt_reasons' => [],
            'stale_debt_created_at' => '',
            'stale_debt_updated_at' => '',
            'stale_debt_processing_profile_hash' => '',
            'stale_debt_cursor_post_id' => 0,
            'stale_debt_processed_count' => 0,
            'stale_debt_remaining_count' => 0,
            'failure_history' => [],
        ];
    }

    private static function reset_index_health_state(): void
    {
        $state = self::default_index_health_state();
        $profile = self::current_index_profile();
        $current_profile_hash = self::sanitize_index_profile_hash($profile['hash'] ?? self::index_profile_hash($profile));
        $state['index_profile_hash'] = $current_profile_hash;
        $state['accepted_index_profile_hash'] = $current_profile_hash;

        self::set_option(self::INDEX_HEALTH_OPTION, $state);
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
        $state['last_batch_stale_processed'] = max(0, (int) ($summary['stale_processed'] ?? 0));
        $state['has_more'] = (bool) ($summary['has_more'] ?? false);
        $state['last_skipped_locked'] = (bool) ($summary['skipped_locked'] ?? false);
        $state['last_stopped_by_budget'] = (bool) ($summary['stopped_by_budget'] ?? false);
        $state['last_mode'] = is_scalar($summary['mode'] ?? null) ? (string) $summary['mode'] : '';
        $state['last_run_at'] = self::current_gmt_datetime();

        $failures = max(0, (int) ($summary['last_batch_failures'] ?? 0));
        if ($failures > 0) {
            $state['last_batch_failures'] = $failures;
            $state['last_failed_post_id'] = max(0, (int) ($summary['last_failed_post_id'] ?? 0));
            $state['last_failed_post_title'] = self::sanitize_index_failure_text($summary['last_failed_post_title'] ?? '', self::MAX_INDEX_FAILURE_TITLE_BYTES, false);
            $state['last_failed_at'] = self::sanitize_index_failure_text($summary['last_failed_at'] ?? '', 32, false);
            $state['last_error'] = self::sanitize_index_failure_text($summary['last_error'] ?? '', self::MAX_INDEX_FAILURE_ERROR_BYTES);
        } elseif (empty($summary['skipped_locked'])) {
            $state['last_batch_failures'] = 0;
            if (empty($summary['stopped_by_budget'])) {
                $state['last_failed_post_id'] = 0;
                $state['last_failed_post_title'] = '';
                $state['last_failed_at'] = '';
                $state['last_error'] = '';
            }
        }

        if ((int) ($summary['last_indexed_post_id'] ?? 0) > 0) {
            $state['last_indexed_post_id'] = (int) $summary['last_indexed_post_id'];
            $state['last_indexed_post_title'] = is_scalar($summary['last_indexed_post_title'] ?? null) ? (string) $summary['last_indexed_post_title'] : '';
            $state['last_indexed_at'] = is_scalar($summary['last_indexed_at'] ?? null) ? (string) $summary['last_indexed_at'] : self::current_gmt_datetime();
        }

        $state['latest_batch_diagnostics'] = self::index_batch_diagnostics_from_summary($summary);
        $profile = self::current_index_profile();
        $current_profile_hash = self::sanitize_index_profile_hash($profile['hash'] ?? self::index_profile_hash($profile));
        $state['index_profile_hash'] = $current_profile_hash;
        if (
            empty($state['stale_debt_active'])
            && self::index_batch_fully_accepts_current_profile($summary)
        ) {
            $state['accepted_index_profile_hash'] = $state['index_profile_hash'];
        }
        if (!empty($state['stale_debt_active'])) {
            self::update_stale_debt_health_state($state, $summary, $current_profile_hash);
        }
        self::apply_failure_recovery_summary($state, $summary);

        self::set_option(self::INDEX_HEALTH_OPTION, $state);
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $summary
     */
    private static function update_stale_debt_health_state(array &$state, array $summary, string $current_profile_hash): void
    {
        if ($current_profile_hash === '') {
            $state['stale_debt_remaining_count'] = 0;
            return;
        }

        $summary_profile_hash = self::sanitize_index_profile_hash($summary['stale_debt_processing_profile_hash'] ?? '');
        $profile_changed = !empty($summary['stale_debt_profile_changed'])
            || ($summary_profile_hash !== '' && $summary_profile_hash !== $current_profile_hash);

        if ($profile_changed) {
            self::restart_stale_debt_health_progress($state, $current_profile_hash);
            return;
        }

        if (
            !empty($summary['stale_debt_completed'])
            && $summary_profile_hash === $current_profile_hash
            && self::index_batch_fully_accepts_current_profile($summary)
        ) {
            self::clear_stale_debt_health_state($state, $current_profile_hash);
            return;
        }

        if ($summary_profile_hash === $current_profile_hash) {
            $state['stale_debt_processing_profile_hash'] = $current_profile_hash;
            $state['stale_debt_cursor_post_id'] = max(0, (int) ($summary['stale_debt_cursor_after'] ?? 0));
            $state['stale_debt_processed_count'] = max(0, (int) ($summary['stale_debt_processed_after'] ?? 0));
            if (max(0, (int) ($summary['stale_processed'] ?? 0)) > 0) {
                $state['stale_debt_updated_at'] = self::current_gmt_datetime();
            }
        } elseif (
            self::sanitize_index_profile_hash($state['stale_debt_processing_profile_hash'] ?? '') !== ''
            && self::sanitize_index_profile_hash($state['stale_debt_processing_profile_hash'] ?? '') !== $current_profile_hash
        ) {
            self::restart_stale_debt_health_progress($state, $current_profile_hash);
            return;
        }

        $state['stale_debt_remaining_count'] = self::count_stale_debt_remaining_content($state);
        if ($state['stale_debt_remaining_count'] > 0 || !empty($summary['has_more'])) {
            $state['has_more'] = true;
        }
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function restart_stale_debt_health_progress(array &$state, string $current_profile_hash): void
    {
        $state['stale_debt_processing_profile_hash'] = $current_profile_hash;
        $state['stale_debt_cursor_post_id'] = 0;
        $state['stale_debt_processed_count'] = 0;
        $state['stale_debt_remaining_count'] = self::count_stale_debt_remaining_content($state);
        $state['stale_debt_updated_at'] = self::current_gmt_datetime();
        $state['has_more'] = true;
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function clear_stale_debt_health_state(array &$state, string $current_profile_hash): void
    {
        $state['stale_debt_active'] = false;
        $state['stale_debt_reasons'] = [];
        $state['stale_debt_created_at'] = '';
        $state['stale_debt_updated_at'] = '';
        $state['stale_debt_processing_profile_hash'] = '';
        $state['stale_debt_cursor_post_id'] = 0;
        $state['stale_debt_processed_count'] = 0;
        $state['stale_debt_remaining_count'] = 0;
        $state['accepted_index_profile_hash'] = $current_profile_hash;
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function index_batch_fully_accepts_current_profile(array $summary): bool
    {
        return empty($summary['has_more'])
            && empty($summary['skipped_locked'])
            && empty($summary['stopped_by_budget'])
            && max(0, (int) ($summary['last_batch_failures'] ?? 0)) === 0
            && in_array((string) ($summary['status'] ?? 'success'), ['success', ''], true);
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private static function index_batch_diagnostics_from_summary(array $summary): array
    {
        return self::sanitize_index_batch_diagnostics([
            'schema' => 'wp-fts-index-batch-diagnostics-v1',
            'trigger' => $summary['trigger'] ?? $summary['mode'] ?? '',
            'source' => $summary['source'] ?? '',
            'status' => $summary['status'] ?? '',
            'started_at' => $summary['started_at'] ?? '',
            'finished_at' => $summary['finished_at'] ?? '',
            'elapsed_ms' => $summary['elapsed_ms'] ?? 0.0,
            'batch_limit' => $summary['batch_size'] ?? 0,
            'processed' => $summary['processed'] ?? 0,
            'queue_processed' => $summary['queue_processed'] ?? 0,
            'backfill_processed' => $summary['backfill_processed'] ?? 0,
            'stale_processed' => $summary['stale_processed'] ?? 0,
            'queue_before' => $summary['queue_before'] ?? 0,
            'queue_after' => $summary['queue_after'] ?? 0,
            'backfill_scanned' => $summary['backfill_scanned'] ?? 0,
            'backfill_queued' => $summary['backfill_queued'] ?? 0,
            'stale_scanned' => $summary['stale_scanned'] ?? 0,
            'stale_queued' => $summary['stale_queued'] ?? 0,
            'stale_cursor_before' => $summary['stale_debt_cursor_before'] ?? 0,
            'stale_cursor_after' => $summary['stale_debt_cursor_after'] ?? 0,
            'stale_completed' => $summary['stale_debt_completed'] ?? false,
            'stale_profile_changed' => $summary['stale_debt_profile_changed'] ?? false,
            'failures' => $summary['last_batch_failures'] ?? 0,
            'has_more' => $summary['has_more'] ?? false,
            'skipped_locked' => $summary['skipped_locked'] ?? false,
            'stopped_by_budget' => $summary['stopped_by_budget'] ?? false,
            'lock_prevented_work' => $summary['lock_prevented_work'] ?? false,
            'lock_at_start' => $summary['lock_before'] ?? [],
            'lock_at_end' => $summary['lock_after'] ?? [],
            'schema_status' => $summary['schema_status'] ?? '',
            'schema_version' => $summary['schema_version'] ?? 0,
            'expected_schema_version' => $summary['expected_schema_version'] ?? 0,
            'storage_backend' => $summary['storage_backend'] ?? '',
            'error_class' => $summary['error_class'] ?? $summary['last_error_class'] ?? '',
            'error_message' => $summary['error_message'] ?? $summary['last_error_message'] ?? '',
            'last_failed_post_id' => $summary['last_failed_post_id'] ?? 0,
            'last_failed_post_title' => $summary['last_failed_post_title'] ?? '',
            'last_failed_at' => $summary['last_failed_at'] ?? '',
            'reschedule_decision' => $summary['reschedule_decision'] ?? '',
            'stop_reason' => $summary['stop_reason'] ?? '',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private static function sanitize_index_batch_diagnostics(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $has_signal = false;
        foreach (['schema', 'trigger', 'status', 'started_at', 'finished_at'] as $key) {
            if (array_key_exists($key, $raw) && is_scalar($raw[$key]) && trim((string) $raw[$key]) !== '') {
                $has_signal = true;
                break;
            }
        }
        if (!$has_signal) {
            return [];
        }

        return [
            'schema' => self::sanitize_index_diagnostic_text($raw['schema'] ?? '', 80, false),
            'trigger' => self::sanitize_index_diagnostic_text($raw['trigger'] ?? '', 60, false),
            'source' => self::sanitize_index_diagnostic_text($raw['source'] ?? '', 60, false),
            'status' => self::sanitize_index_diagnostic_text($raw['status'] ?? '', 60, false),
            'started_at' => self::sanitize_index_diagnostic_text($raw['started_at'] ?? '', 32, false),
            'finished_at' => self::sanitize_index_diagnostic_text($raw['finished_at'] ?? '', 32, false),
            'elapsed_ms' => round(self::clamp_float((float) ($raw['elapsed_ms'] ?? 0.0), 0.0, 86400000.0), 3),
            'batch_limit' => max(0, (int) ($raw['batch_limit'] ?? 0)),
            'processed' => max(0, (int) ($raw['processed'] ?? 0)),
            'queue_processed' => max(0, (int) ($raw['queue_processed'] ?? 0)),
            'backfill_processed' => max(0, (int) ($raw['backfill_processed'] ?? 0)),
            'stale_processed' => max(0, (int) ($raw['stale_processed'] ?? 0)),
            'queue_before' => max(0, (int) ($raw['queue_before'] ?? 0)),
            'queue_after' => max(0, (int) ($raw['queue_after'] ?? 0)),
            'backfill_scanned' => max(0, (int) ($raw['backfill_scanned'] ?? 0)),
            'backfill_queued' => max(0, (int) ($raw['backfill_queued'] ?? 0)),
            'stale_scanned' => max(0, (int) ($raw['stale_scanned'] ?? 0)),
            'stale_queued' => max(0, (int) ($raw['stale_queued'] ?? 0)),
            'stale_cursor_before' => max(0, (int) ($raw['stale_cursor_before'] ?? 0)),
            'stale_cursor_after' => max(0, (int) ($raw['stale_cursor_after'] ?? 0)),
            'stale_completed' => (bool) ($raw['stale_completed'] ?? false),
            'stale_profile_changed' => (bool) ($raw['stale_profile_changed'] ?? false),
            'failures' => max(0, (int) ($raw['failures'] ?? 0)),
            'has_more' => (bool) ($raw['has_more'] ?? false),
            'skipped_locked' => (bool) ($raw['skipped_locked'] ?? false),
            'stopped_by_budget' => (bool) ($raw['stopped_by_budget'] ?? false),
            'lock_prevented_work' => (bool) ($raw['lock_prevented_work'] ?? false),
            'lock_at_start' => self::sanitize_index_lock_diagnostics($raw['lock_at_start'] ?? []),
            'lock_at_end' => self::sanitize_index_lock_diagnostics($raw['lock_at_end'] ?? []),
            'schema_status' => self::sanitize_index_diagnostic_text($raw['schema_status'] ?? '', 40, false),
            'schema_version' => max(0, (int) ($raw['schema_version'] ?? 0)),
            'expected_schema_version' => max(0, (int) ($raw['expected_schema_version'] ?? 0)),
            'storage_backend' => self::sanitize_index_diagnostic_text($raw['storage_backend'] ?? '', 80, false),
            'error_class' => self::sanitize_index_diagnostic_text($raw['error_class'] ?? '', self::MAX_INDEX_DIAGNOSTIC_ERROR_CLASS_BYTES, false),
            'error_message' => self::sanitize_index_diagnostic_text($raw['error_message'] ?? '', self::MAX_INDEX_FAILURE_ERROR_BYTES),
            'last_failed_post_id' => max(0, (int) ($raw['last_failed_post_id'] ?? 0)),
            'last_failed_post_title' => self::sanitize_index_diagnostic_text($raw['last_failed_post_title'] ?? '', self::MAX_INDEX_FAILURE_TITLE_BYTES, false),
            'last_failed_at' => self::sanitize_index_diagnostic_text($raw['last_failed_at'] ?? '', 32, false),
            'reschedule_decision' => self::sanitize_index_diagnostic_text($raw['reschedule_decision'] ?? '', 80, false),
            'stop_reason' => self::sanitize_index_diagnostic_text($raw['stop_reason'] ?? '', 80, false),
        ];
    }

    /**
     * @return array{state:string,active:bool,mode:string,started_at:string,expires_at:string,age_seconds:?int,expires_in_seconds:?int,expired_seconds:?int,advice:string}
     */
    private static function sanitize_index_lock_diagnostics(mixed $raw): array
    {
        if (!is_array($raw)) {
            return self::empty_index_lock_status();
        }
        $state = self::sanitize_index_lock_state($raw['state'] ?? '');
        $active = (bool) ($raw['active'] ?? false);
        if ($state === 'active') {
            $active = true;
        } elseif ($state === 'none' || $state === 'expired') {
            $active = false;
        }

        return [
            'state' => $state,
            'active' => $active,
            'mode' => self::sanitize_index_lock_mode($raw['mode'] ?? ''),
            'started_at' => self::sanitize_index_diagnostic_text($raw['started_at'] ?? '', 32, false),
            'expires_at' => self::sanitize_index_diagnostic_text($raw['expires_at'] ?? '', 32, false),
            'age_seconds' => self::sanitize_lock_seconds_value($raw['age_seconds'] ?? null),
            'expires_in_seconds' => self::sanitize_lock_seconds_value($raw['expires_in_seconds'] ?? null),
            'expired_seconds' => self::sanitize_lock_seconds_value($raw['expired_seconds'] ?? null),
            'advice' => self::sanitize_index_diagnostic_text(
                is_scalar($raw['advice'] ?? null) && trim((string) $raw['advice']) !== ''
                    ? (string) $raw['advice']
                    : self::index_lock_advice($state),
                240,
                false
            ),
        ];
    }

    private static function sanitize_index_lock_state(mixed $value): string
    {
        $state = is_scalar($value) ? self::sanitize_key((string) $value) : '';

        return in_array($state, ['none', 'active', 'expired'], true) ? $state : 'none';
    }

    private static function sanitize_lock_seconds_value(mixed $value): ?int
    {
        if (!is_int($value) && (!is_scalar($value) || !is_numeric($value))) {
            return null;
        }

        return self::bounded_lock_diagnostic_seconds(max(0, (int) $value));
    }

    private static function sanitize_index_diagnostic_text(mixed $value, int $max_bytes = self::MAX_INDEX_DIAGNOSTIC_TEXT_BYTES, bool $redact_sql = true): string
    {
        return self::sanitize_index_failure_text($value, $max_bytes, $redact_sql);
    }

    /**
     * @param array<string,mixed> $health
     */
    private static function failed_post_label(array $health): string
    {
        $post_id = max(0, (int) ($health['last_failed_post_id'] ?? 0));
        if ($post_id <= 0) {
            return '';
        }

        $title = is_scalar($health['last_failed_post_title'] ?? null) ? trim((string) $health['last_failed_post_title']) : '';

        return trim(($title !== '' ? $title : '(untitled)') . ' (ID ' . $post_id . ')');
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

    private static function esc_textarea(string $value): string
    {
        if (function_exists('esc_textarea')) {
            return (string) esc_textarea($value);
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
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

    private static function rest_explain_requested(mixed $request): bool
    {
        return self::truthy_admin_value(self::request_param($request, 'explain', false));
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
