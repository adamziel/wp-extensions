<?php
declare(strict_types=1);

/**
 * WordPress plugin lifecycle, runtime indexing hooks, queue processing, and REST search.
 *
 * The standalone index/search classes remain framework-neutral; this class is
 * the narrow WordPress adapter that wires them to activation hooks, post events,
 * WP-Cron, options, visibility checks, and REST registration.
 */
final class WP_FTS_Index_Writer_Ownership_Lost extends RuntimeException
{
}

/** Durable work remains, but WordPress could not persist its next callback. */
final class WP_FTS_Index_Successor_Schedule_Failed extends RuntimeException
{
    public readonly string $reason_code;

    public function __construct(?Throwable $previous = null)
    {
        $this->reason_code = 'successor_schedule_failed';
        parent::__construct(
            'Full-text indexing could not schedule the next queue processor event. The work remains queued; restore WP-Cron, then use the Full-Text Search Health screen or run `wp fts schedule-queue`.',
            0,
            $previous
        );
    }
}

final class WP_FTS_Search_Unavailable extends RuntimeException
{
}

final class WP_FTS_Plugin
{
    public const SCHEMA_VERSION = 9;
    public const SCHEMA_VERSION_OPTION = 'wp_fts_schema_version';
    public const QUEUE_OPTION = 'wp_fts_pending_index_post_ids';
    public const CRON_HOOK = 'wp_fts_process_index_queue';
    public const SCHEMA_UPGRADE_CRON_HOOK = 'wp_fts_upgrade_schema';
    public const SCHEMA_SITE_CRON_HOOK = 'wp_fts_provision_site_schema';
    public const INDEX_LOCK_OPTION = 'wp_fts_indexing_lock';
    public const UNINSTALL_FENCE_OPTION = 'wp_fts_uninstall_fence';
    public const UNINSTALL_FENCE_VALUE = '1';
    public const INDEX_HEALTH_OPTION = 'wp_fts_index_health';
    public const READINESS_INCARNATION_OPTION = 'wp_fts_readiness_incarnation';
    public const SEARCH_READY_INCARNATION_OPTION = 'wp_fts_search_ready_incarnation';
    public const SCOPE_INDEX_OWNERSHIP_OPTION = 'wp_fts_scope_index_ownership';
    public const REST_NAMESPACE = 'wp-fts/v1';
    public const REST_SEARCH_ROUTE = '/search';
    public const ADMIN_PAGE_SLUG = 'wp-fts-settings';
    public const ADMIN_CAPABILITY = 'manage_options';
    public const SETTINGS_OPTION = 'wp_fts_settings';
    public const ACTIVATION_REDIRECT_OPTION = 'wp_fts_activation_redirect';
    public const SANDBOX_DEMO_POSTS_OPTION = 'wp_fts_sandbox_demo_post_ids';
    public const ANALYZER_OPTIONS_OPTION = 'wp_fts_analyzer_options';
    public const ANALYZER_OPTIONS_FILTER = 'wp_fts_analyzer_options';
    public const POST_INDEX_OPTIONS_FILTER = 'wp_fts_post_index_options';
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
    public const MAX_SEARCH_LIMIT = WP_FTS_Set_Oriented_Search_Storage::MAX_PAGE_SIZE;
    private const REST_MAX_QUERY_TERMS = 12;
    private const MAX_SEARCH_QUERY_BYTES = 4096;
    private const MAX_SEARCH_MODE_BYTES = 8;
    private const MAX_SEARCH_LANGUAGE_BYTES = 64;
    private const MAX_SEARCH_CURSOR_BYTES = 2048;
    private const MAX_SEARCH_SWITCH_BYTES = 16;
    private const MAX_SEARCH_NUMERIC_BYTES = 64;
    private const MAX_SEARCH_SCOPE_VALUES = 32;
    private const MAX_FILTER_SCOPE_LANES = 32;
    private const MAX_SEARCH_SCOPE_VALUE_BYTES = 64;
    private const MAX_SEARCH_SCOPE_BYTES = 4096;
    private const MAX_PUBLIC_SEARCH_OPTIONS = 32;
    private const MAX_SEARCH_OPTION_KEY_BYTES = 64;
    private const MAX_QUERY_CONSTRAINT_DEPTH = 16;
    private const MAX_QUERY_CONSTRAINT_NODES = 64;
    private const UNSUPPORTED_SCOPE_SENTINEL = "\0wp_fts_scope_overflow";
    private const DEFAULT_SEARCH_TOTAL_BUDGET_MS = 100.0;
    private const DEFAULT_SEARCH_STORAGE_BUDGET_MS = 50.0;
    private const DEFAULT_INDEX_LOCK_TTL = 300;
    /** The measured writer transaction must retain twice its five-second SLA. */
    private const MIN_INDEX_TRANSACTION_LEASE_SECONDS = 10;
    private const SYSTEMIC_WORKER_BACKOFF_SECONDS = WP_FTS_Index_Queue::BASE_BACKOFF_SECONDS;
    private const MUTATION_FENCE_SECONDS = 300;
    private const MAX_FOREGROUND_MUTATION_TARGETS = WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS;
    private const GLOBAL_RECONCILIATION_SCOPE_KEY = WP_FTS_Index_Queue::GLOBAL_CORPUS_SCOPE_KEY;
    // A network cron invocation provisions one blog. Per-site DDL therefore
    // cannot multiply into hundreds of statements inside one worker callback.
    private const SCHEMA_SITE_BATCH_SIZE = 1;
    private const UNINSTALL_SITE_BATCH_SIZE = 100;
    private const MAX_INDEX_LOCK_DIAGNOSTIC_SECONDS = 2592000;
    private const MAX_CRON_INDEX_BATCH_SIZE = 500;
    private const MAX_MANUAL_INDEX_BATCH_SIZE = 1000;
    private const MAX_SEARCH_PERFORMANCE_BUDGET_MS = 60000.0;
    private const MAX_INDEX_FAILURE_TITLE_BYTES = 120;
    private const MAX_INDEX_FAILURE_ERROR_BYTES = 240;
    private const MAX_INDEX_DIAGNOSTIC_TEXT_BYTES = 160;
    private const MAX_INDEX_DIAGNOSTIC_ERROR_CLASS_BYTES = 120;
    private const MAX_INDEX_BATCH_SOURCE_BYTES = WP_FTS_Index_Queue::MAX_SOURCE_SNAPSHOT_BYTES;
    private const MAX_INDEX_BATCH_CUSTOM_FIELD_KEY_BYTES = 262144;
    private const MAX_INDEX_DEPENDENCY_ROWS_PER_DOCUMENT = 512;
    private const MAX_INDEX_BATCH_DEPENDENCY_ROWS = 2048;
    private const MAX_INDEX_BATCH_SELECTED_DEPENDENCIES = 512;
    private const MAX_INDEX_DEPENDENCY_VALUE_BYTES = 262144;
    private const MAX_INDEX_DEPENDENCY_SQL_BYTES = 32768;
    private const MAX_INDEX_DEPENDENCY_SQL_SCAFFOLD_BYTES = 8192;
    private const MAX_INDEX_DEPENDENCY_QUERY_BRANCHES = 5;
    private const MAX_INDEX_DEPENDENCY_VALUE_QUERY_BRANCHES = 40;
    private const MAX_SERIALIZED_META_DEPTH = 16;
    private const PRELOADED_POST_LANGUAGE_OPTION = 'wp_fts_preloaded_post_language';
    private const NETWORK_ACTIVATION_TOKEN_OPTION = 'wp_fts_network_activation_token';
    private const NETWORK_LIFECYCLE_LOCK_OPTION = 'wp_fts_network_lifecycle_lock';
    private const INITIAL_INDEX_STATUS_PENDING = 'pending';
    private const INITIAL_INDEX_STATUS_READY = 'ready';
    private const FAILURE_RECOVERY_SCHEMA = 'wp-fts-failure-recovery-v1';
    private const FAILURE_RECOVERY_MAX_ITEMS = 20;
    private const FAILURE_RECOVERY_RECENT_ITEMS = 10;
    private const FAILURE_RECOVERY_MAX_JSON_BYTES = 8192;
    private const FAILURE_RECOVERY_BASE_BACKOFF_SECONDS = WP_FTS_Index_Queue::BASE_BACKOFF_SECONDS;
    private const FAILURE_RECOVERY_MAX_BACKOFF_SECONDS = WP_FTS_Index_Queue::MAX_BACKOFF_SECONDS;
    private const SUPPORT_SNAPSHOT_SCHEMA = 'wp-fts-support-snapshot-v1';
    private const SUPPORT_SNAPSHOT_MAX_JSON_BYTES = 32768;
    private const SUPPORT_SNAPSHOT_MAX_DEPTH = 6;
    private const SUPPORT_SNAPSHOT_MAX_LIST_ITEMS = 12;
    private const SUPPORT_SNAPSHOT_MAX_ASSOC_ITEMS = 80;
    private const SUPPORT_SNAPSHOT_PLUGIN_NAME = 'Language FTS';
    private const SUPPORT_SNAPSHOT_PLUGIN_VERSION = '0.1.9';
    private const INDEX_PROFILE_SCHEMA = 'wp-fts-index-profile-v1';
    private const INDEX_PROFILE_INDEXER_SIGNATURE = 'wp-fts-indexer-v6';
    private const RANKING_TUNING_SCHEMA = 'wp-fts-ranking-tuning-v1';
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
    private const ADMIN_POSTS_CURSOR_FIELD = 'wp_fts_sandbox_posts_cursor';
    private const ADMIN_POSTS_CURSOR_DIRECTION_FIELD = 'wp_fts_sandbox_posts_cursor_direction';
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
    private const ADMIN_POST_TYPE_FIELD = 'wp_fts_sandbox_post_type';
    private const ADMIN_POST_STATUS_FIELD = 'wp_fts_sandbox_post_status';
    private const ADMIN_DATE_AFTER_FIELD = 'wp_fts_sandbox_date_after';
    private const ADMIN_DATE_BEFORE_FIELD = 'wp_fts_sandbox_date_before';
    private const ADMIN_DETAILS_NONCE_ACTION = 'wp_fts_sandbox_result_details';
    private const ADMIN_DETAILS_NONCE_FIELD = 'wp_fts_sandbox_details_nonce';
    private const ADMIN_DETAILS_POST_IDS_FIELD = 'wp_fts_sandbox_post_ids';
    private const ADMIN_AJAX_SANDBOX_DETAILS_ACTION = 'wp_fts_sandbox_result_details';
    private const ADMIN_DETAILS_ID_LIST_MAX_BYTES = 2048;
    private const ADMIN_DETAILS_ID_MAX_BYTES = 20;
    private const SETTINGS_GROUP = 'wp_fts_settings';
    private const POST_LANGUAGE_FIELD = 'wp_fts_post_language';
    private const POST_LANGUAGE_NONCE_ACTION = 'wp_fts_post_language';
    private const POST_LANGUAGE_NONCE_FIELD = 'wp_fts_post_language_nonce';
    private const SANDBOX_INDEXED_TERMS_LIMIT = 24;
    private const SANDBOX_INDEXED_POSTS_PER_PAGE = 10;
    private const SETTINGS_SNIPPET_MIN = 40;
    private const SETTINGS_SNIPPET_MAX = 500;
    private const PREFIX_MIN_LENGTH_MIN = 2;
    private const PREFIX_MIN_LENGTH_MAX = 12;
    private const PREFIX_MIN_LENGTH_DEFAULT = 4;
    private const FIELD_BOOST_MIN = 1.0;
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
        'terms' => 2.0,
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
        'rest_api_enabled' => false,
        'rest_prefix_matching' => false,
        'result_limit' => 10,
        'field_boosts' => self::FIELD_BOOST_DEFAULTS,
        'recency_boost_strength' => 0.0,
        'recency_boost_half_life_days' => self::RECENCY_BOOST_HALF_LIFE_DEFAULT,
    ];
    private const FRONTEND_SNIPPET_LENGTH = 180;
    private const FRONTEND_SEARCH_POST_STATUSES = ['publish'];
    private const ADMIN_POST_SEARCH_POST_STATUSES = ['publish', 'draft', 'pending', 'future', 'private'];
    private const DEBUG_MAX_TRACES = 8;
    private const DEBUG_MAX_TEXT_BYTES = 160;
    private const DEBUG_MAX_LIST_ITEMS = 8;
    private const DEBUG_MAX_SQL_QUERIES = 8;
    private const DEBUG_MAX_SQL_SUMMARY_BYTES = 240;
    private const DEBUG_MAX_ASSOC_ITEMS = 16;
    private const DEBUG_MAX_TIMING_PHASES = 16;
    private const DEBUG_SEARCH_HOOK = 'posts_pre_query';
    private const DEBUG_SEARCH_FINAL_OWNERSHIP_QUERY_VAR = 'wp_fts_search_final_ownership_trace_id';
    private const DEBUG_MAX_HOOK_CALLBACKS = self::DEBUG_MAX_LIST_ITEMS;
    private const ANALYZER_PACK_STATUS_MATRIX_MAX_ROWS = 64;
    private const FTS_TABLE_SUFFIXES = [
        'fts_terms',
        'fts_postings',
        'fts_documents',
        'fts_work',
    ];

    /**
     * @var array<int,array{total:int,max_pages:int,query_lang:string,query_text:string,snippets:array<int,string>,titles:array<int,string>,has_more:bool,next_cursor:?string,previous_cursor:?string,total_relation:string,trace_id:int}>
     */
    private static array $front_end_search_query_state = [];

    /**
     * @var array<int,array{total:int,max_pages:int,query_lang:string,has_more:bool,next_cursor:?string,previous_cursor:?string,total_relation:string,trace_id:int}>
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
     * Request-local capability for the lease currently fencing index writes.
     */
    private static ?string $active_index_writer_token = null;

    /** Site prefix that owns the request-local writer capability. */
    private static ?string $active_index_writer_prefix = null;

    /** @var array{token:string,mode:string,started_at:int,heartbeat_at:int,expires_at:int,renewals:int}|null */
    private static ?array $active_network_lifecycle_lock = null;

    /** @var array<int,bool> */
    private static array $relationship_pre_mutations = [];

    /** @var array<int,bool> */
    private static array $relationship_post_mutations = [];

    /** @var array<int,bool> Direct relationship deletes awaiting request-end promotion. */
    private static array $relationship_shutdown_mutations = [];

    /** @var array<int,bool> Posts with a successful metadata SQL action this request. */
    private static array $post_meta_committed_posts = [];

    /** @var array<int,bool> Posts whose first metadata pre-SQL action installed a fence. */
    private static array $post_meta_fenced_posts = [];

    /** @var array<string,string> */
    private static array $post_meta_global_mutations = [];

    /** @var array<string,array{token:string,depth:int,expires_at:int}> Pre-boundary tokens and nesting. */
    private static array $mutation_fence_tokens = [];

    /** @var array<string,bool> Bounded request-local post/scope mutation identities. */
    private static array $foreground_mutation_targets = [];

    /** @var array<int,bool> Exact posts retained for one request-end handoff. */
    private static array $foreground_mutation_posts = [];

    /** @var array<string,int> Completed same-target boundaries before bulk mode. */
    private static array $foreground_mutation_repeat_boundaries = [];

    /** @var array<string,string> Direct scope keys addressable by their hashed identity. */
    private static array $foreground_direct_scope_keys = [];

    /** @var array<string,string> Request tokens retained through ready scope promotion. */
    private static array $foreground_direct_scope_tokens = [];

    /** @var array<string,int> Unresolved taxonomy pre-hooks bound to the corpus scope. */
    private static array $taxonomy_term_global_pre_boundaries = [];

    private static bool $foreground_mutation_has_scope = false;

    /**
     * @var array{scope_key:string,token:string,expires_at:int,incarnation:string,profile_hash:string,overflow:bool,requires_corpus:bool,pending_marked:bool}|null
     */
    private static ?array $foreground_bulk_mutation_scope = null;

    /** @var array{queue:WP_FTS_Index_Queue,guard:array<string,mixed>}|null */
    private static ?array $foreground_owner_guard = null;

    /** Acquire at most one request-lifetime filesystem guard per attempt. */
    private static bool $foreground_owner_guard_attempted = false;

    /** Bring a worker forward while the final shared guard is still held. */
    private static bool $foreground_owner_guard_has_ready_work = false;

    /** Avoid repeating exceptional health writes in one PHP request. */
    private static bool $foreground_owner_guard_failure_latched = false;

    /** Site prefix owning every foreground identity retained below. */
    private static ?string $foreground_mutation_prefix = null;

    /** Prevent a failed global-fence attempt from being retried for every hook. */
    private static bool $foreground_bulk_activation_attempted = false;

    /** Stop foreground FTS I/O after its first persistence failure this request. */
    private static bool $foreground_queue_writes_disabled = false;

    /** @var array<string,bool> Site prefixes whose tables were dropped in this request. */
    private static array $foreground_queue_blocked_prefixes = [];

    /**
     * @var array<int,array{language:string,kind:string,status:string,pack_id:string,fixture_only:bool,reason:string}>|null
     */
    private static ?array $runtime_analyzer_pack_statuses_cache = null;

    /**
     * @var array<int,array{language:string,kind:string,status:string,pack_id:string,fixture_only:bool,reason:string}>|null
     */
    private static ?array $sandbox_demo_analyzer_pack_statuses_cache = null;

    /** Runtime indexing and search share one validated analyzer per request. */
    private static ?WP_FTS_Analyzer $runtime_analyzer_cache = null;

    /**
     * @var array<string,array<string,mixed>>
     */
    private static array $search_takeover_status_cache = [];

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
        self::release_foreground_owner_guard();
        self::clear_site_analyzer_caches();
        self::$search_takeover_status_cache = [];
        self::$debug_traces = [];
        self::$debug_next_trace_id = 1;
        self::$debug_sql_query_starts = [];
        self::$search_final_ownership_state = [];
        self::$admin_health_support_snapshot_visible = false;
        self::$relationship_pre_mutations = [];
        self::$relationship_post_mutations = [];
        self::$relationship_shutdown_mutations = [];
        self::$post_meta_committed_posts = [];
        self::$post_meta_fenced_posts = [];
        self::$post_meta_global_mutations = [];
        self::$mutation_fence_tokens = [];
        self::$foreground_mutation_targets = [];
        self::$foreground_mutation_posts = [];
        self::$foreground_mutation_repeat_boundaries = [];
        self::$foreground_direct_scope_keys = [];
        self::$foreground_direct_scope_tokens = [];
        self::$taxonomy_term_global_pre_boundaries = [];
        self::$foreground_mutation_has_scope = false;
        self::$foreground_bulk_mutation_scope = null;
        self::$foreground_mutation_prefix = null;
        self::$foreground_bulk_activation_attempted = false;
        self::$foreground_owner_guard_failure_latched = false;
        self::$foreground_queue_writes_disabled = false;
    }

    /** Drop site-scoped analyzer state whenever WordPress switches or restores a blog. */
    public static function handle_blog_switch(mixed $new_blog_id = null, mixed $previous_blog_id = null, mixed $context = null): void
    {
        self::abandon_foreground_mutations();
        // A writer lease is a per-site option capability. Never let a token
        // acquired for the old prefix authorize writes after WordPress changes
        // `$wpdb->prefix`; the old lease expires normally if the callback that
        // switched blogs cannot restore and finish the batch.
        self::$active_index_writer_token = null;
        self::$active_index_writer_prefix = null;
        self::clear_site_analyzer_caches();
    }

    private static function clear_site_analyzer_caches(): void
    {
        self::$runtime_analyzer_pack_statuses_cache = null;
        self::$sandbox_demo_analyzer_pack_statuses_cache = null;
        self::$runtime_analyzer_cache = null;
        self::$language_support_details_cache = [];
    }

    /** Abandon old-site request state without ever handing it to the new site. */
    private static function abandon_foreground_mutations(): void
    {
        // `switch_blog` has already changed the active option namespace. The
        // old site's durable fence and recovery latch remain authoritative;
        // never enqueue its ready-work event in the newly selected site's cron
        // store.
        self::release_foreground_owner_guard(false);
        self::$relationship_pre_mutations = [];
        self::$relationship_post_mutations = [];
        self::$relationship_shutdown_mutations = [];
        self::$post_meta_committed_posts = [];
        self::$post_meta_fenced_posts = [];
        self::$post_meta_global_mutations = [];
        self::$mutation_fence_tokens = [];
        self::$foreground_mutation_targets = [];
        self::$foreground_mutation_posts = [];
        self::$foreground_mutation_repeat_boundaries = [];
        self::$foreground_direct_scope_keys = [];
        self::$foreground_direct_scope_tokens = [];
        self::$taxonomy_term_global_pre_boundaries = [];
        self::$foreground_mutation_has_scope = false;
        self::$foreground_bulk_mutation_scope = null;
        self::$foreground_mutation_prefix = null;
        self::$foreground_bulk_activation_attempted = false;
        self::$foreground_owner_guard_failure_latched = false;
        self::$foreground_queue_writes_disabled = false;
    }

    /**
     * Register runtime hooks when WordPress hook APIs are available.
     */
    public static function register_hooks(): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        // WordPress does not rerun activation hooks when an already-active
        // plugin is updated, so schema migrations also need a runtime entry.
        add_action('init', [self::class, 'maybe_upgrade_schema'], 1, 0);
        add_action('pre_post_update', [self::class, 'handle_post_pre_update'], PHP_INT_MAX, 2);
        add_action('wp_after_insert_post', [self::class, 'handle_post_save'], 10, 4);
        add_action('before_delete_post', [self::class, 'handle_post_pre_delete'], PHP_INT_MAX, 2);
        add_action('deleted_post', [self::class, 'handle_post_delete'], 10, 2);
        add_action('add_term_relationship', [self::class, 'handle_term_relationship_pre_change'], PHP_INT_MAX, 3);
        add_action('delete_term_relationships', [self::class, 'handle_term_relationship_pre_change'], PHP_INT_MAX, 3);
        add_action('set_object_terms', [self::class, 'handle_term_relationship_change'], 10, 6);
        add_action('deleted_term_relationships', [self::class, 'handle_term_relationship_change'], 10, 3);
        // Run after ordinary plugin shutdown callbacks so mutations they emit
        // still join the one bounded request-end handoff.
        add_action('shutdown', [self::class, 'flush_relationship_mutations'], PHP_INT_MAX - 2, 0);
        add_action('shutdown', [self::class, 'flush_post_meta_mutations'], PHP_INT_MAX - 1, 0);
        add_action('shutdown', [self::class, 'flush_foreground_bulk_mutations'], PHP_INT_MAX, 0);
        add_action('edit_terms', [self::class, 'handle_taxonomy_term_pre_edit'], PHP_INT_MAX, 3);
        add_action('edited_term', [self::class, 'handle_taxonomy_term_edit'], 10, 4);
        add_action('pre_delete_term', [self::class, 'handle_taxonomy_term_pre_delete'], PHP_INT_MAX, 2);
        add_action('delete_term', [self::class, 'handle_taxonomy_term_delete'], 10, 5);
        add_action('added_post_meta', [self::class, 'handle_post_meta_change'], 10, 4);
        add_action('updated_post_meta', [self::class, 'handle_post_meta_change'], 10, 4);
        add_action('deleted_post_meta', [self::class, 'handle_post_meta_change'], 10, 4);
        add_action('add_post_meta', [self::class, 'handle_post_meta_pre_add'], PHP_INT_MAX, 3);
        add_action('update_post_meta', [self::class, 'handle_post_meta_pre_update'], PHP_INT_MAX, 4);
        add_action('delete_post_meta', [self::class, 'handle_post_meta_pre_delete'], PHP_INT_MAX, 4);
        add_action('wp_initialize_site', [self::class, 'handle_site_initialization'], 10, 2);
        add_action('init', [self::class, 'maybe_schedule_initial_index_readiness'], 10, 0);
        add_action(self::CRON_HOOK, [self::class, 'process_scheduled_indexing'], 10, 0);
        add_action(self::SCHEMA_UPGRADE_CRON_HOOK, [self::class, 'run_scheduled_schema_upgrade'], 10, 0);
        add_action(self::SCHEMA_SITE_CRON_HOOK, [self::class, 'handle_scheduled_site_schema'], 10, 2);
        add_action('rest_api_init', [self::class, 'register_rest_routes'], 10, 0);
        add_action('admin_menu', [self::class, 'register_admin_menu'], 10, 0);
        add_action('admin_init', [self::class, 'maybe_redirect_after_activation'], 1, 0);
        add_action('admin_init', [self::class, 'register_settings'], 10, 0);
        add_action('wp_ajax_' . self::ADMIN_AJAX_SANDBOX_DETAILS_ACTION, [self::class, 'handle_sandbox_result_details_ajax'], 10, 0);
        add_action('add_meta_boxes', [self::class, 'register_language_meta_box'], 10, 0);
        add_action('save_post', [self::class, 'save_post_language_override'], 5, 3);
        add_action('switch_blog', [self::class, 'handle_blog_switch'], 10, 3);
        add_action('pre_get_posts', [self::class, 'prepare_frontend_search_query'], self::SEARCH_REPLACEMENT_PRIORITY, 1);
        add_action('pre_get_posts', [self::class, 'prepare_admin_post_search_query'], self::SEARCH_REPLACEMENT_PRIORITY, 1);
        add_action('restrict_manage_posts', [self::class, 'render_admin_search_cursor_navigation'], 99, 0);

        if (function_exists('add_filter')) {
            add_filter('query_vars', [self::class, 'register_search_query_vars'], 10, 1);
            add_filter('get_pagenum_link', [self::class, 'filter_frontend_search_pagenum_link'], 10, 2);
            add_filter('paginate_links', [self::class, 'filter_frontend_search_paginate_link'], 10, 1);
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
        $lifecycle_token = self::acquire_network_lifecycle_lock('activation');
        if ($lifecycle_token === null) {
            throw new RuntimeException('Could not activate FTS while a network lifecycle operation is active.');
        }
        try {
            $locked = self::run_index_writer_with_lock(
            'activation',
            static function () use ($network_wide, $lifecycle_token): string {
                self::heartbeat_network_lifecycle_lock($lifecycle_token, true);
                // Only an explicit activation may cross the durable uninstall
                // boundary. Clear it while holding the same lease that guards
                // schema creation, so uninstall cannot race between the two.
                $restoreUninstallFence = self::uninstall_fence_active();
                self::clear_uninstall_fence();
                try {
                    self::mark_initial_index_pending();
                    self::upgrade_schema();
                    self::enqueue_corpus_scope(self::index_queue(false), ['reason' => 'activation']);
                    self::migration_phase('reconciliation_enqueued');
                    unset(self::$foreground_queue_blocked_prefixes[self::current_database_prefix()]);
                    self::abandon_foreground_mutations();
                    // Publish the network capability before releasing the same
                    // writer lease that excludes uninstall. A token created
                    // afterward could resurrect a completed uninstall chain.
                    return $network_wide ? self::begin_network_activation() : '';
                } catch (Throwable $error) {
                    if ($restoreUninstallFence) {
                        self::persist_uninstall_fence();
                    }
                    throw $error;
                }
            },
            [
                'batch_size' => 1,
                'record_health' => false,
                'record_skip' => false,
            ]
            );
            if (empty($locked['acquired'])) {
                throw new RuntimeException('Could not activate FTS while another index writer owns the active lease.');
            }

            if ($network_wide) {
                $network_activation_token = is_string($locked['result'] ?? null)
                    ? (string) $locked['result']
                    : '';
                if (preg_match('/^[a-f0-9]{32}$/D', $network_activation_token) !== 1) {
                    throw new RuntimeException('Could not establish the FTS network activation capability.');
                }
                self::schedule_existing_network_site_schema($network_activation_token);
            }
            self::schedule_queue_processor();
            self::maybe_set_activation_redirect_flag($network_wide);
        } finally {
            self::release_network_lifecycle_lock($lifecycle_token);
        }
    }

    /**
     * Mark existing documents stale when runtime index behavior changes.
     *
     * This runs after init-time analyzer filter registrations and before a
     * scheduled batch can accept a new profile without sweeping old rows.
     */
    public static function detect_index_profile_drift(): void
    {
        $currentProfile = self::current_index_profile();
        $currentHash = self::sanitize_index_profile_hash($currentProfile['hash'] ?? '');
        if ($currentHash === '') {
            return;
        }

        $expected_state = self::get_option(self::INDEX_HEALTH_OPTION, []);
        $state = self::sanitize_index_health_state($expected_state);
        $acceptedHash = self::sanitize_index_profile_hash($state['accepted_index_profile_hash'] ?? '');
        if ($acceptedHash === '') {
            if (self::uninstall_fence_active()) {
                return;
            }
            if (($state['initial_index_status'] ?? '') === self::INITIAL_INDEX_STATUS_READY) {
                // A ready index with missing acceptance provenance is not an
                // empty installation. Treat the profile as unknown and rebuild;
                // otherwise deleting one option can bless stale analyzer output.
                $incarnation = self::mark_initial_index_pending(true, $currentHash);
                self::enqueue_scope_reconciliation('index-profile', [
                    'reason' => 'accepted_index_profile_missing',
                    'from' => '',
                    'to' => $currentHash,
                    'profile_hash' => $currentHash,
                ], true, '', 0, $incarnation);
                return;
            }

            $incarnation = self::readiness_incarnation();
            if (
                $incarnation !== ''
                && self::sanitize_index_profile_hash($state['index_profile_hash'] ?? '') === $currentHash
            ) {
                if (self::readiness_completion_matches($state)) {
                    self::schedule_schema_provisioning(1);
                    return;
                }
                if (self::index_queue(false)->corpus_scope_matches(
                    self::GLOBAL_RECONCILIATION_SCOPE_KEY,
                    $incarnation,
                    $currentHash
                )) {
                    return;
                }
            }
            $incarnation = self::mark_initial_index_pending(true, $currentHash);
            self::enqueue_scope_reconciliation('index-profile', [
                'reason' => 'pending_index_profile_unbound',
                'from' => '',
                'to' => $currentHash,
                'profile_hash' => $currentHash,
            ], true, '', 0, $incarnation);
            return;
        }

        if ($acceptedHash === $currentHash) {
            return;
        }

        if (self::sanitize_index_profile_hash($state['index_profile_hash'] ?? '') === $currentHash) {
            $incarnation = self::readiness_incarnation();
            if ($incarnation !== '') {
                if (self::readiness_completion_matches($state)) {
                    self::schedule_schema_provisioning(1);
                    return;
                }
                if (self::index_queue(false)->corpus_scope_matches(
                    self::GLOBAL_RECONCILIATION_SCOPE_KEY,
                    $incarnation,
                    $currentHash
                )) {
                    return;
                }
            }
        }

        if (self::uninstall_fence_active()) {
            return;
        }
        $incarnation = self::mark_initial_index_pending(true, $currentHash);
        self::enqueue_scope_reconciliation('index-profile', [
            'reason' => 'index_profile_changed',
            'from' => $acceptedHash,
            'to' => $currentHash,
            'profile_hash' => $currentHash,
        ], true, '', 0, $incarnation);
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
        try {
            self::provision_site_schema($site_id);
        } catch (Throwable) {
            // wp_initialize_site is part of the canonical site-creation path;
            // an optional derived index must never make that core operation
            // fail. Scheduled network provisioning retains strict retry and
            // error propagation, while storage(true) remains the lazy repair
            // boundary for a new site that could not be switched here.
        }
    }

    public static function handle_scheduled_site_schema(int $after_site_id, string $network_activation_token = ''): void
    {
        $after_site_id = max(0, $after_site_id);
        if (
            $network_activation_token !== ''
            && !self::network_activation_token_is_current($network_activation_token)
        ) {
            // Uninstall removes the network token before dropping any site.
            // A preloaded activation event therefore cannot clear a newer
            // uninstall fence after its originating activation became stale.
            return;
        }
        try {
            $sites = self::network_schema_site_ids_after($after_site_id);
        } catch (Throwable $error) {
            self::schedule_network_schema_batch($after_site_id, $network_activation_token);
            throw $error;
        }

        $current_site_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        $failure = null;
        foreach ($sites as $site) {
            $site_id = self::site_id_from_value($site);
            if ($site_id <= 0 || $site_id === $current_site_id) {
                continue;
            }

            try {
                self::provision_site_schema($site_id, $network_activation_token);
            } catch (Throwable $error) {
                $failure ??= $error;
            }
        }

        if ($failure !== null) {
            // Do not advance past a site that failed provisioning. Replaying
            // the same bounded keyset page is idempotent for sites that already
            // succeeded and guarantees the failed site is not skipped forever.
            self::schedule_network_schema_batch($after_site_id, $network_activation_token);
            throw $failure;
        }
        if (count($sites) === self::SCHEMA_SITE_BATCH_SIZE) {
            if (!self::schedule_network_schema_batch($sites[count($sites) - 1], $network_activation_token)) {
                throw new RuntimeException('Could not schedule the next bounded FTS network schema batch.');
            }
            return;
        }
        if ($network_activation_token !== '') {
            self::clear_network_activation_token_if_current($network_activation_token);
        }
    }

    /**
     * Read one stable keyset page from the multisite site table.
     *
     * @return int[]
     */
    private static function network_schema_site_ids_after(int $after_site_id): array
    {
        global $wpdb;

        if (
            !isset($wpdb)
            || !is_object($wpdb)
            || !isset($wpdb->blogs)
            || !is_scalar($wpdb->blogs)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'get_col')
        ) {
            throw new RuntimeException('Could not query multisite schema targets.');
        }

        $table = (string) $wpdb->blogs;
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            throw new RuntimeException('Could not query multisite schema targets.');
        }

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT blog_id FROM `{$table}` WHERE blog_id > %d ORDER BY blog_id ASC LIMIT %d",
            $after_site_id,
            self::SCHEMA_SITE_BATCH_SIZE
        ));
        if (!is_array($rows) || (isset($wpdb->last_error) && (string) $wpdb->last_error !== '')) {
            throw new RuntimeException('Could not query multisite schema targets.');
        }

        $sites = [];
        foreach ($rows as $row) {
            $site_id = self::site_id_from_value($row);
            if ($site_id > $after_site_id) {
                $sites[$site_id] = true;
            }
        }
        $sites = array_keys($sites);
        sort($sites, SORT_NUMERIC);

        return array_slice($sites, 0, self::SCHEMA_SITE_BATCH_SIZE);
    }

    private static function provision_site_schema(int $site_id, string $network_activation_token = ''): void
    {
        if ($site_id <= 0 || !function_exists('switch_to_blog') || !function_exists('restore_current_blog')) {
            return;
        }
        $lifecycle_token = self::acquire_network_lifecycle_lock(
            $network_activation_token === '' ? 'site-provision' : 'network-provision'
        );
        if ($lifecycle_token === null) {
            throw new RuntimeException("Could not provision FTS schema for blog {$site_id} while a network lifecycle operation is active.");
        }

        try {
            self::heartbeat_network_lifecycle_lock($lifecycle_token, true);
            if (!switch_to_blog($site_id)) {
                throw new RuntimeException("Could not switch to blog {$site_id} for FTS schema provisioning.");
            }

            try {
                $locked = self::run_index_writer_with_lock(
                $network_activation_token === '' ? 'site-schema-provision' : 'network-activation-provision',
                static function () use ($network_activation_token): void {
                    if ($network_activation_token !== '') {
                        if (!self::network_activation_token_is_current($network_activation_token)) {
                            throw new RuntimeException('The network activation schema request is stale.');
                        }
                        $restoreUninstallFence = self::uninstall_fence_active();
                        self::clear_uninstall_fence();
                    } else {
                        $restoreUninstallFence = false;
                    }
                    try {
                        self::mark_initial_index_pending();
                        self::upgrade_schema();
                        self::enqueue_corpus_scope(self::index_queue(false), ['reason' => 'site_provisioning']);
                        self::migration_phase('reconciliation_enqueued');
                        if ($network_activation_token !== '') {
                            unset(self::$foreground_queue_blocked_prefixes[self::current_database_prefix()]);
                            self::abandon_foreground_mutations();
                        }
                        self::schedule_queue_processor();
                    } catch (Throwable $error) {
                        if ($restoreUninstallFence) {
                            self::persist_uninstall_fence();
                        }
                        throw $error;
                    }
                },
                [
                    'batch_size' => 1,
                    'record_health' => false,
                    'record_skip' => false,
                ]
                );
                if (empty($locked['acquired'])) {
                    if (($locked['summary']['stop_reason'] ?? '') === 'uninstall_fenced') {
                        throw new RuntimeException("FTS schema provisioning for blog {$site_id} is blocked by its uninstall fence.");
                    }
                    throw new RuntimeException("FTS schema provisioning for blog {$site_id} skipped because its index-writer lease is active.");
                }
            } finally {
                restore_current_blog();
            }
        } finally {
            self::release_network_lifecycle_lock($lifecycle_token);
        }
    }

    /**
     * Network activation starts one last-seen-site-ID repair chain. Each cron event
     * provisions exactly one discovered site and schedules only its
     * successor, so a large network cannot enqueue an unbounded event storm.
     * New sites continue through wp_initialize_site, and storage(true) remains
     * a lazy repair boundary if WP-Cron is unavailable.
     */
    private static function schedule_existing_network_site_schema(string $network_activation_token): void
    {
        if (
            !function_exists('is_multisite')
            || !is_multisite()
        ) {
            return;
        }

        if (!self::schedule_network_schema_batch(0, $network_activation_token)) {
            throw new RuntimeException('Could not schedule FTS network schema provisioning.');
        }
    }

    private static function schedule_network_schema_batch(int $offset, string $network_activation_token = ''): bool
    {
        if (!function_exists('wp_schedule_single_event')) {
            return false;
        }

        $args = [max(0, $offset)];
        if ($network_activation_token !== '') {
            $args[] = $network_activation_token;
        }
        if (function_exists('wp_next_scheduled') && wp_next_scheduled(self::SCHEMA_SITE_CRON_HOOK, $args)) {
            return true;
        }
        if (self::uninstall_fence_active()) {
            return false;
        }

        return wp_schedule_single_event(time() + 60, self::SCHEMA_SITE_CRON_HOOK, $args) === true;
    }

    /** Acquire one crash-recoverable lease shared by every site in the network. */
    private static function acquire_network_lifecycle_lock(string $mode): ?string
    {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return '';
        }
        if (self::$active_network_lifecycle_lock !== null) {
            return null;
        }

        $now = time();
        $ttl = self::configured_int_constant(
            'WP_FTS_INDEX_LOCK_TTL',
            self::DEFAULT_INDEX_LOCK_TTL,
            30,
            3600
        );
        $payload = [
            'token' => bin2hex(random_bytes(16)),
            'mode' => substr($mode, 0, 40),
            'started_at' => $now,
            'heartbeat_at' => $now,
            'expires_at' => $now + $ttl,
            'renewals' => 0,
        ];
        $serialized = self::serialize_network_lifecycle_lock($payload);
        if (self::insert_network_lifecycle_lock($serialized)) {
            self::$active_network_lifecycle_lock = $payload;
            return $payload['token'];
        }

        $existing = self::network_lifecycle_lock_row();
        if ($existing !== null && self::network_lifecycle_lock_active($existing['payload'], $now)) {
            return null;
        }
        if ($existing !== null && !self::delete_network_lifecycle_lock_row($existing['serialized'])) {
            return null;
        }
        if (!self::insert_network_lifecycle_lock($serialized)) {
            return null;
        }

        self::$active_network_lifecycle_lock = $payload;
        return $payload['token'];
    }

    /** Renew exact ownership before crossing another site's destructive boundary. */
    private static function heartbeat_network_lifecycle_lock(string $token, bool $force = false): void
    {
        if ($token === '') {
            return;
        }
        $current = self::$active_network_lifecycle_lock;
        if (
            !is_array($current)
            || !is_string($current['token'] ?? null)
            || !hash_equals($token, (string) $current['token'])
        ) {
            throw new RuntimeException('FTS network lifecycle ownership was lost.');
        }
        $now = time();
        if ((int) ($current['expires_at'] ?? 0) <= $now) {
            throw new RuntimeException('FTS network lifecycle ownership expired.');
        }
        $ttl = self::configured_int_constant(
            'WP_FTS_INDEX_LOCK_TTL',
            self::DEFAULT_INDEX_LOCK_TTL,
            30,
            3600
        );
        if (!$force && (int) $current['expires_at'] - $now > max(5, min(60, intdiv($ttl, 3)))) {
            return;
        }

        $renewed = $current;
        $renewed['heartbeat_at'] = $now;
        $renewed['expires_at'] = $now + $ttl;
        $renewed['renewals'] = max(0, (int) ($current['renewals'] ?? 0)) + 1;
        if (!self::compare_and_swap_network_lifecycle_lock($current, $renewed)) {
            self::$active_network_lifecycle_lock = null;
            throw new RuntimeException('FTS network lifecycle ownership changed during renewal.');
        }
        self::$active_network_lifecycle_lock = $renewed;
    }

    /** Retire only this request's exact network lease. */
    private static function release_network_lifecycle_lock(string $token): void
    {
        if ($token === '') {
            return;
        }
        $current = self::$active_network_lifecycle_lock;
        self::$active_network_lifecycle_lock = null;
        if (
            !is_array($current)
            || !is_string($current['token'] ?? null)
            || !hash_equals($token, (string) $current['token'])
        ) {
            return;
        }
        self::delete_network_lifecycle_lock_row(self::serialize_network_lifecycle_lock($current));
    }

    /** @return array{serialized:string,payload:array<string,mixed>}|null */
    private static function network_lifecycle_lock_row(): ?array
    {
        global $wpdb;

        $table = self::network_lifecycle_lock_table();
        if (!method_exists($wpdb, 'get_var')) {
            throw new RuntimeException('WordPress cannot read the FTS network lifecycle lease.');
        }
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1",
            self::NETWORK_LIFECYCLE_LOCK_OPTION
        ));
        if (isset($wpdb->last_error) && trim((string) $wpdb->last_error) !== '') {
            throw new RuntimeException('Could not read the FTS network lifecycle lease.');
        }
        if (!is_string($raw)) {
            return null;
        }
        $payload = function_exists('maybe_unserialize')
            ? maybe_unserialize($raw)
            : @unserialize($raw, ['allowed_classes' => false]);

        return [
            'serialized' => $raw,
            'payload' => is_array($payload) ? $payload : [],
        ];
    }

    /** @param array<string,mixed> $payload */
    private static function network_lifecycle_lock_active(array $payload, int $now): bool
    {
        $token = is_string($payload['token'] ?? null) ? (string) $payload['token'] : '';

        return preg_match('/^[a-f0-9]{32}$/D', $token) === 1
            && (int) ($payload['expires_at'] ?? 0) > $now;
    }

    private static function insert_network_lifecycle_lock(string $serialized): bool
    {
        global $wpdb;

        $table = self::network_lifecycle_lock_table();
        $insert = self::database_adapter_is_sqlite($wpdb) ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $result = $wpdb->query($wpdb->prepare(
            "{$insert} INTO {$table} (option_name,option_value,autoload) VALUES (%s,%s,%s)",
            self::NETWORK_LIFECYCLE_LOCK_OPTION,
            $serialized,
            'no'
        ));
        if ($result === false || (isset($wpdb->last_error) && trim((string) $wpdb->last_error) !== '')) {
            throw new RuntimeException('Could not acquire the FTS network lifecycle lease.');
        }

        return (int) $result === 1;
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $replacement */
    private static function compare_and_swap_network_lifecycle_lock(array $expected, array $replacement): bool
    {
        global $wpdb;

        $table = self::network_lifecycle_lock_table();
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            self::serialize_network_lifecycle_lock($replacement),
            self::NETWORK_LIFECYCLE_LOCK_OPTION,
            self::serialize_network_lifecycle_lock($expected)
        ));
        if ($result === false || (isset($wpdb->last_error) && trim((string) $wpdb->last_error) !== '')) {
            throw new RuntimeException('Could not renew the FTS network lifecycle lease.');
        }

        return (int) $result === 1;
    }

    private static function delete_network_lifecycle_lock_row(string $serialized): bool
    {
        global $wpdb;

        $table = self::network_lifecycle_lock_table();
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE option_name = %s AND option_value = %s",
            self::NETWORK_LIFECYCLE_LOCK_OPTION,
            $serialized
        ));
        if ($result === false || (isset($wpdb->last_error) && trim((string) $wpdb->last_error) !== '')) {
            throw new RuntimeException('Could not retire the FTS network lifecycle lease.');
        }

        return (int) $result === 1;
    }

    /** @param array<string,mixed> $payload */
    private static function serialize_network_lifecycle_lock(array $payload): string
    {
        $serialized = function_exists('maybe_serialize') ? maybe_serialize($payload) : serialize($payload);
        if (!is_string($serialized) || strlen($serialized) > 2048) {
            throw new RuntimeException('Invalid FTS network lifecycle lease payload.');
        }

        return $serialized;
    }

    private static function network_lifecycle_lock_table(): string
    {
        global $wpdb;

        if (
            !isset($wpdb)
            || !is_object($wpdb)
            || !isset($wpdb->base_prefix)
            || !is_scalar($wpdb->base_prefix)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'query')
        ) {
            throw new RuntimeException('WordPress database is unavailable for the FTS network lifecycle lease.');
        }
        $table = (string) $wpdb->base_prefix . 'options';
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            throw new RuntimeException('Invalid WordPress network lifecycle option table.');
        }

        return $table;
    }

    /** Authorize only the bounded site-provision chain from this activation. */
    private static function begin_network_activation(): string
    {
        if (!function_exists('update_site_option') || !function_exists('get_site_option')) {
            throw new RuntimeException('WordPress network options are unavailable for FTS network activation.');
        }

        $token = bin2hex(random_bytes(16));
        update_site_option(self::NETWORK_ACTIVATION_TOKEN_OPTION, $token);
        $stored = get_site_option(self::NETWORK_ACTIVATION_TOKEN_OPTION, null);
        if (!is_string($stored) || !hash_equals($token, $stored)) {
            throw new RuntimeException('Could not persist the FTS network activation capability.');
        }

        return $token;
    }

    private static function network_activation_token_is_current(string $token): bool
    {
        if ($token === '' || !function_exists('get_site_option')) {
            return false;
        }
        $stored = get_site_option(self::NETWORK_ACTIVATION_TOKEN_OPTION, null);

        return is_string($stored) && hash_equals($stored, $token);
    }

    private static function clear_network_activation_token(): void
    {
        if (function_exists('delete_site_option')) {
            delete_site_option(self::NETWORK_ACTIVATION_TOKEN_OPTION);
        }
    }

    /** Clear a completed activation capability only when this event still owns it. */
    private static function clear_network_activation_token_if_current(string $token): bool
    {
        global $wpdb;

        if ($token === '' || !self::network_activation_token_is_current($token)) {
            return false;
        }
        if (
            !isset($wpdb)
            || !is_object($wpdb)
            || !isset($wpdb->sitemeta)
            || !is_scalar($wpdb->sitemeta)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'query')
        ) {
            return false;
        }
        $table = (string) $wpdb->sitemeta;
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            return false;
        }
        $network_id = function_exists('get_current_network_id')
            ? max(1, (int) get_current_network_id())
            : 1;
        $stored = function_exists('maybe_serialize') ? maybe_serialize($token) : $token;
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$table}` WHERE site_id = %d AND meta_key = %s AND meta_value = %s LIMIT 1",
            $network_id,
            self::NETWORK_ACTIVATION_TOKEN_OPTION,
            $stored
        ));
        if ($deleted === false || (isset($wpdb->last_error) && (string) $wpdb->last_error !== '')) {
            throw new RuntimeException('Could not retire the completed FTS network activation capability.');
        }
        if ($deleted > 0 && function_exists('wp_cache_delete')) {
            wp_cache_delete($network_id . ':' . self::NETWORK_ACTIVATION_TOKEN_OPTION, 'site-options');
        }

        return $deleted > 0;
    }

    /**
     * Migrate legacy installs and keep their one-shot readiness work scheduled.
     * A dropped WP-Cron event must not leave search replacement pending forever.
     */
    public static function maybe_schedule_initial_index_readiness(): void
    {
        $raw = self::get_option(self::INDEX_HEALTH_OPTION, null);
        $state = is_array($raw) ? self::index_health_state() : self::default_index_health_state();
        $status = self::sanitize_initial_index_status($state['initial_index_status'] ?? '');
        $maintenance_latched = !empty($state['search_runtime_failure_latched'])
            || !empty($state['foreground_owner_guard_blocked'])
            || self::sanitize_index_failure_text(
                $state['schema_upgrade_error'] ?? '',
                self::MAX_INDEX_FAILURE_ERROR_BYTES
            ) !== '';
        $readiness_invalid = !self::readiness_completion_matches($state);
        $desired_incarnation = self::readiness_incarnation();
        $ready_incarnation = self::search_ready_incarnation();
        $search_capability_current = $desired_incarnation !== ''
            && $ready_incarnation !== ''
            && hash_equals($desired_incarnation, $ready_incarnation);
        $global_fence_active = !empty($state['global_visibility_fence_active']);
        $ready_without_maintenance =
            $status === self::INITIAL_INDEX_STATUS_READY
            && !$maintenance_latched
            && !$readiness_invalid
            && !$global_fence_active
            && $search_capability_current;
        if ($ready_without_maintenance) {
            return;
        }

        // Network activation is exceptional maintenance, not a normal ready
        // request dependency. A pending site's first request can recover a
        // lost network cron event; a ready site must not cold-read a normally
        // absent non-autoloaded network option on every request.
        $network_token = '';
        if (function_exists('get_site_option')) {
            $stored_network_token = get_site_option(self::NETWORK_ACTIVATION_TOKEN_OPTION, '');
            $network_token = is_string($stored_network_token)
                && preg_match('/^[a-f0-9]{32}$/D', $stored_network_token) === 1
                ? $stored_network_token
                : '';
        }

        $releaseForegroundGuard = false;
        try {
            $queue = self::scoped_foreground_lifecycle_checked_index_queue($releaseForegroundGuard);
        } catch (Throwable) {
            // Init is only a watchdog. The guard/schema helper has already
            // latched or scheduled the specific recovery it could prove safe.
            return;
        }

        try {
            // The normal ready request returns above without touching SQL.
            // Every remaining path is about to enqueue or schedule durable
            // work, so the shared capability remains live through each effect.
            if ($network_token !== '') {
                self::schedule_network_schema_batch(0, $network_token);
            }
            $legacy_health = !is_array($raw) || !array_key_exists('initial_index_status', $raw);
            if (
                $legacy_health
                ||
                ($status === self::INITIAL_INDEX_STATUS_READY && $readiness_invalid)
                || ($status === self::INITIAL_INDEX_STATUS_PENDING && self::readiness_incarnation() === '')
            ) {
                // Reuse the current incarnation when a foreground failure already
                // rotated it. Init is a watchdog, not another failure transition.
                $profile_hash = self::current_index_profile_hash();
                $incarnation = self::mark_initial_index_pending(false, $profile_hash);
                $queue->enqueue_scope(
                    self::GLOBAL_RECONCILIATION_SCOPE_KEY,
                    [
                        'reason' => $legacy_health ? 'legacy_readiness_migration' : 'readiness_provenance_repair',
                        'profile_hash' => $profile_hash,
                    ],
                    null,
                    WP_FTS_Index_Queue::SCOPE_COVERAGE_CORPUS,
                    '',
                    0,
                    $incarnation
                );
            }
            self::schedule_queue_processor();
            self::schedule_schema_provisioning(60);
        } finally {
            if ($releaseForegroundGuard) {
                self::release_foreground_owner_guard(false);
            }
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
        self::clear_scheduled_schema_provisioning();
    }

    /**
     * Uninstall removes every plugin-owned table and operational option.
     *
     * Deactivation is the reversible operation and retains the derived index.
     * Uninstall is the explicit data-removal boundary, including bounded pages
     * of multisite blogs and recoverable legacy relational tables. One scalar
     * lifecycle fence remains so a preloaded request cannot recreate tables
     * after the destructive boundary; explicit activation removes that fence.
     */
    public static function uninstall(): void
    {
        $lifecycle_token = self::acquire_network_lifecycle_lock('uninstall');
        if ($lifecycle_token === null) {
            throw new RuntimeException('Could not uninstall FTS while a network lifecycle operation is active.');
        }
        try {
            // Revoke preloaded schema jobs before discovering blogs. The
            // network lease prevents activation from republishing a successor
            // until every destructive site boundary and final revocation end.
            self::clear_network_activation_token();
            if (self::uninstall_multisite_options()) {
                self::heartbeat_network_lifecycle_lock($lifecycle_token, true);
                self::clear_network_activation_token();
                return;
            }

            self::uninstall_current_site_options();
            self::heartbeat_network_lifecycle_lock($lifecycle_token, true);
            self::clear_network_activation_token();
        } finally {
            self::release_network_lifecycle_lock($lifecycle_token);
        }
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
            self::READINESS_INCARNATION_OPTION,
            self::SEARCH_READY_INCARNATION_OPTION,
            self::ACTIVATION_REDIRECT_OPTION,
            self::SCOPE_INDEX_OWNERSHIP_OPTION,
        ];
    }

    /** Remove current-site tables, schedules, and operational options. */
    private static function uninstall_current_site_options(): void
    {
        global $wpdb;
        if (self::$active_network_lifecycle_lock !== null) {
            self::heartbeat_network_lifecycle_lock(
                (string) self::$active_network_lifecycle_lock['token'],
                true
            );
        }
        if (!isset($wpdb) || !is_object($wpdb)) {
            throw new RuntimeException('WordPress database is unavailable for FTS uninstall cleanup.');
        }
        $prefix = (string) ($wpdb->prefix ?? '');
        // This request's own queued mutations are about to be destroyed. Drop
        // its shared capability without publishing a successor, then exclude
        // every other foreground request before the first lifecycle write.
        self::release_foreground_owner_guard(false);
        $guardQueue = new WP_FTS_Index_Queue($wpdb, $prefix);
        $exclusiveGuard = $guardQueue->acquire_exclusive_foreground_owner_guard();
        try {
            $tables = [];
            $suffixes = array_merge(
                self::FTS_TABLE_SUFFIXES,
                array_keys(self::legacy_relational_table_suffixes()),
                array_values(self::legacy_relational_table_suffixes())
            );
            foreach (array_values(array_unique($suffixes)) as $suffix) {
                $tables[] = self::migration_identifier($prefix . $suffix);
            }
            $storage = new WP_FTS_Storage_Mysql(
                $wpdb,
                $prefix,
                static function (): void {
                    self::assert_index_writer_ownership();
                }
            );
            foreach ($storage->reset_generation_table_names() as $table) {
                $tables[] = self::migration_identifier($table);
            }
            $option_names = self::uninstall_option_names();
            $locked = self::run_index_writer_with_lock(
                'uninstall',
                static function () use ($tables, $option_names, $storage, $prefix): void {
                    // Persist the post-uninstall state before the first DROP while
                    // the shared writer lease excludes schema repair. Retain this
                    // exact scalar even on partial DROP failure; uninstall retry is
                    // allowed through the fence and explicit activation repairs it.
                    self::persist_uninstall_fence();
                    // The exclusive foreground guard serializes this DROP with
                    // every canonical queue writer. The request-local latch also
                    // rejects callbacks that run later in this uninstall request.
                    self::$foreground_queue_blocked_prefixes[$prefix] = true;
                    // Resolve ownership only after the writer lease. A schema
                    // upgrade may have installed and recorded these indexes while
                    // uninstall was waiting to acquire that same lease.
                    $storage->drop_owned_scope_keyset_indexes(self::scope_index_ownership_keys());
                    self::migration_query('DROP TABLE IF EXISTS ' . implode(', ', $tables));
                    self::clear_scheduled_queue_processor();
                    self::clear_scheduled_schema_provisioning();
                    foreach ($option_names as $option_name) {
                        if ($option_name !== self::INDEX_LOCK_OPTION) {
                            self::delete_option($option_name);
                        }
                    }
                },
                [
                    'batch_size' => 1,
                    'record_health' => false,
                    'record_skip' => false,
                ]
            );
            if (empty($locked['acquired'])) {
                throw new RuntimeException('Could not remove FTS data because another index writer owns the active lease; retry uninstall after it finishes.');
            }
        } finally {
            $guardQueue->release_exclusive_foreground_owner_guard($exclusiveGuard);
        }
    }

    /** Install the only option intentionally retained after uninstall. */
    private static function persist_uninstall_fence(): void
    {
        if (!self::uninstall_fence_active()) {
            $added = function_exists('add_option')
                ? add_option(self::UNINSTALL_FENCE_OPTION, self::UNINSTALL_FENCE_VALUE, '', 'no')
                : false;
            if (!$added) {
                self::set_option(self::UNINSTALL_FENCE_OPTION, self::UNINSTALL_FENCE_VALUE);
            }
        } elseif (self::get_option(self::UNINSTALL_FENCE_OPTION, null) !== self::UNINSTALL_FENCE_VALUE) {
            self::set_option(self::UNINSTALL_FENCE_OPTION, self::UNINSTALL_FENCE_VALUE);
        }

        if (!self::uninstall_fence_active()) {
            throw new RuntimeException('Could not persist the FTS uninstall fence.');
        }
    }

    /** Explicit activation is the sole lifecycle that removes this boundary. */
    private static function clear_uninstall_fence(): void
    {
        if (!self::uninstall_fence_active()) {
            return;
        }
        self::delete_option(self::UNINSTALL_FENCE_OPTION);
        if (self::uninstall_fence_active()) {
            throw new RuntimeException('Could not clear the FTS uninstall fence during activation.');
        }
    }

    /**
     * Read the fence from the option table when possible instead of trusting a
     * request-local `notoptions` cache populated before another process ran
     * uninstall. Canonical hot paths first take the shared file capability and
     * pay this probe only when schema absence makes completed uninstall
     * ambiguous. Direct operator/watchdog writers already paid the probe before
     * this change and keep it while holding that capability through scheduling.
     */
    private static function uninstall_fence_active(): bool
    {
        $database_state = self::uninstall_fence_database_state();
        if ($database_state !== null) {
            return $database_state;
        }

        return self::get_option(self::UNINSTALL_FENCE_OPTION, null) !== null;
    }

    /** @return bool|null Null when a direct native option-table probe is unavailable. */
    private static function uninstall_fence_database_state(): ?bool
    {
        global $wpdb;

        if (
            !isset($wpdb)
            || !is_object($wpdb)
            || !isset($wpdb->options)
            || !is_scalar($wpdb->options)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'get_var')
            || self::database_adapter_is_sqlite($wpdb)
        ) {
            return null;
        }

        $table = (string) $wpdb->options;
        if ($table === '' || preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            return true;
        }
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1",
            self::UNINSTALL_FENCE_OPTION
        ));
        if (isset($wpdb->last_error) && trim((string) $wpdb->last_error) !== '') {
            return true;
        }

        return $value !== null;
    }

    /**
     * Remove plugin data across bounded multisite discovery pages.
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

        $original_site_id = function_exists('get_current_blog_id') ? max(0, (int) get_current_blog_id()) : 0;
        $original_attempted = false;
        $cleaned = false;
        $failed_count = 0;
        $first_failed_site_id = 0;
        $offset = 0;
        do {
            $sites = get_sites([
                'fields' => 'ids',
                'number' => self::UNINSTALL_SITE_BATCH_SIZE,
                'offset' => $offset,
                'orderby' => 'id',
                'order' => 'ASC',
            ]);
            if (!is_array($sites)) {
                throw new RuntimeException('Could not enumerate multisite blogs for FTS uninstall cleanup.');
            }
            $page_count = count($sites);
            $offset += $page_count;

            $page_ids = [];
            foreach ($sites as $site) {
                $site_id = self::site_id_from_value($site);
                if ($site_id > 0) {
                    $page_ids[$site_id] = true;
                }
            }
            foreach (array_keys($page_ids) as $site_id) {
                if ($site_id === $original_site_id) {
                    $original_attempted = true;
                    try {
                        self::uninstall_current_site_options();
                        $cleaned = true;
                    } catch (Throwable $error) {
                        $failed_count++;
                        $first_failed_site_id = $first_failed_site_id > 0 ? $first_failed_site_id : $site_id;
                    }
                    continue;
                }
                if (!switch_to_blog($site_id)) {
                    $failed_count++;
                    $first_failed_site_id = $first_failed_site_id > 0 ? $first_failed_site_id : $site_id;
                    continue;
                }

                try {
                    self::uninstall_current_site_options();
                    $cleaned = true;
                } catch (Throwable $error) {
                    $failed_count++;
                    $first_failed_site_id = $first_failed_site_id > 0 ? $first_failed_site_id : $site_id;
                } finally {
                    restore_current_blog();
                }
            }
        } while ($page_count === self::UNINSTALL_SITE_BATCH_SIZE);

        if (!$original_attempted) {
            try {
                self::uninstall_current_site_options();
                $cleaned = true;
            } catch (Throwable $error) {
                $failed_count++;
                $first_failed_site_id = $first_failed_site_id > 0 ? $first_failed_site_id : $original_site_id;
            }
        }
        if ($failed_count > 0) {
            throw new RuntimeException(
                "Could not remove FTS data from {$failed_count} multisite blog(s); first failed blog ID: {$first_failed_site_id}."
            );
        }

        return $cleaned;
    }

    /**
     * Idempotently create or repair tables and store the current schema version.
     */
    public static function upgrade_schema(): void
    {
        if (self::$active_index_writer_token !== null) {
            self::upgrade_schema_under_lock();
            return;
        }

        $locked = self::run_index_writer_with_lock(
            'schema-upgrade',
            static function (): void {
                self::upgrade_schema_under_lock();
            },
            [
                'batch_size' => 1,
                'record_health' => false,
                'record_skip' => false,
            ]
        );
        if (empty($locked['acquired'])) {
            if (($locked['summary']['stop_reason'] ?? '') === 'uninstall_fenced') {
                throw new RuntimeException('FTS schema creation is blocked by the durable uninstall fence; activate the plugin explicitly to reinstall it.');
            }
            throw new RuntimeException('Could not repair the FTS schema while another index writer owns the active lease.');
        }
    }

    /** Create or repair physical schema only while the current request owns the writer lease. */
    private static function upgrade_schema_under_lock(): void
    {
        self::assert_index_writer_ownership();
        if (self::uninstall_fence_active()) {
            throw new RuntimeException('FTS schema creation is blocked by the durable uninstall fence.');
        }
        // Schema repair is a mutation boundary, so readiness cached before
        // physical damage was discovered cannot authorize the rebuilt index.
        self::$search_takeover_status_cache = [];
        $storage = self::mysql_storage();
        $stored_version = self::schema_version_from_option(self::get_option(self::SCHEMA_VERSION_OPTION, null));
        if ($stored_version > self::SCHEMA_VERSION) {
            throw new RuntimeException("The installed FTS schema version {$stored_version} is newer than this plugin supports.");
        }
        $physical_before = $storage->verify_schema();
        // Version 9 changes both dictionary identity and indexed content. Any
        // version advance or physical repair therefore requires a complete
        // fail-closed corpus reconciliation; an additive migration must never
        // publish an older lexical generation under the current profile.
        $requires_initial_index_recheck = $stored_version < self::SCHEMA_VERSION
            || empty($physical_before['valid']);
        if ($requires_initial_index_recheck) {
            // Publish the fail-closed incarnation before any migration can
            // recreate the work table or expose a partially repaired schema.
            // The bound corpus row is installed below before the repaired
            // schema is published as current.
            self::mark_initial_index_pending();
        }

        if (
            $stored_version < self::SCHEMA_VERSION
            && empty($physical_before['valid'])
            && self::pre_v4_relational_schema_exists()
        ) {
            // The logical option alone is not authoritative enough to destroy
            // data: it may be missing or malformed while a recoverable pre-v4
            // index still exists. A physically valid v4 generation, however,
            // cannot also be that legacy layout, so do not spend another
            // table-by-table discovery pass on ordinary metadata upgrades.
            // Rename a detected legacy generation before any v4 creator is
            // allowed to replace incompatible tables.
            self::migrate_relational_schema_v4($storage);
        } else {
            for ($version = $stored_version + 1; $version <= self::SCHEMA_VERSION; $version++) {
                self::run_schema_migration($storage, $version);
            }
        }
        if (empty($storage->verify_schema()['valid'])) {
            // Explicit repair remains idempotent even when the version is current.
            $storage->create_tables();
        }

        // Selective scope expansion is allowed to use only these direct
        // keysets. Install them during explicit maintenance, never lazily in a
        // request or worker, and persist ownership before the first core-table
        // DDL so an interrupted install remains uninstallable.
        self::ensure_scope_keyset_indexes($storage);

        $physical = $storage->verify_schema();
        if (empty($physical['valid'])) {
            throw new RuntimeException('FTS schema verification failed: ' . self::schema_verification_failure_summary($physical));
        }

        self::ensure_request_options_autoloaded();
        self::migrate_legacy_queue_option(self::index_queue(false));
        if ($requires_initial_index_recheck) {
            self::enqueue_corpus_scope(self::index_queue(false), [
                'reason' => empty($physical_before['valid']) ? 'schema_repair' : 'schema_upgrade',
            ]);
            self::migration_phase('reconciliation_enqueued');
            self::schedule_queue_processor();
        }
        // Logical publication is deliberately last. Every preceding failure
        // leaves readiness pending and either the old schema version or a
        // durable corpus fence visible to all search paths.
        self::assert_index_writer_ownership();
        self::set_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION);
    }

    /** Detect a recoverable relational index even when its version option is lost. */
    private static function pre_v4_relational_schema_exists(): bool
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return false;
        }

        $prefix = (string) ($wpdb->prefix ?? '');
        foreach (self::legacy_relational_table_suffixes() as $source_suffix => $target_suffix) {
            // A failpoint may fire after any individual rename. Seeing either
            // side of any legacy mapping must keep the next run on the resumable
            // v4 migration path instead of replaying version-1 table creation.
            if (self::migration_table_exists($prefix . $target_suffix)) {
                return true;
            }
            $source = $prefix . $source_suffix;
            if (!self::migration_table_exists($source)) {
                continue;
            }
            if (
                in_array($source_suffix, ['fts_terms', 'fts_postings'], true)
                && self::migration_table_has_column($source, 'term_id')
            ) {
                continue;
            }

            return true;
        }

        return false;
    }

    private static function run_schema_migration(WP_FTS_Storage_Mysql $storage, int $version): bool
    {
        if ($version === 1) {
            $storage->create_tables();
            return true;
        }

        if ($version === 2) {
            // Version 2 formalizes the complete six-table row-postings contract.
            // Existing version-1 installs already have most or all of this DDL,
            // so only repair when physical inspection finds a gap.
            if (empty($storage->verify_schema()['valid'])) {
                $storage->create_tables();
                return true;
            }
            return false;
        }

        if ($version === 3) {
            // Version 3 adds the generation-aware durable indexing queue.
            if (empty($storage->verify_schema()['valid'])) {
                $storage->create_tables();
                return true;
            }
            return false;
        }

        if ($version === 4) {
            return self::migrate_relational_schema_v4($storage);
        }

        if ($version === 5) {
            if (empty($storage->verify_schema()['valid'])) {
                $storage->create_tables();
            }
            global $wpdb;
            $work = self::migration_identifier((string) ($wpdb->prefix ?? '') . 'fts_work');
            self::migration_query(
                "UPDATE {$work}
SET scope_coverage = CASE
    WHEN scope_subject_type = 'term_taxonomy' AND scope_subject_id > 0 THEN 'targeted'
    ELSE 'filtered'
END
WHERE kind = 'scope' AND scope_coverage = ''"
            );
            return true;
        }

        if ($version === 6) {
            $storage->ensure_recoverable_work_index();
            return true;
        }

        if ($version === 7) {
            // A stored v6 option does not prove its recoverable work index
            // survived manual DDL or an interrupted installation. Repair that
            // one additive index in place; generic table repair would discard
            // queued generations and the search epoch it protects.
            $storage->ensure_recoverable_work_index();
            // The request-option invariant is published after physical schema
            // verification so failure cannot advance the logical version.
            return true;
        }

        if ($version === 8) {
            // The supporting core-table keysets are installed after generic
            // FTS table repair, where every migration path (including a
            // resumed pre-v4 rename) reaches the same ownership boundary.
            return true;
        }

        if ($version === 9) {
            // V9 removes the hash side index and replaces materialized proper
            // prefixes with one normalized surface identity per document term.
            // A hash-bearing physical generation is incompatible and must be
            // replaced. A five-column pre-release generation can be reused,
            // but the already-published corpus fence still keeps its old
            // content rows unavailable until complete reconciliation.
            if (empty($storage->verify_schema()['valid'])) {
                $storage->create_tables();
            }
            return true;
        }

        throw new RuntimeException("No FTS schema migration is registered for version {$version}.");
    }

    /** Persist ownership intent, then install both selective scope indexes. */
    private static function ensure_scope_keyset_indexes(WP_FTS_Storage_Mysql $storage): void
    {
        $missing = $storage->scope_keyset_indexes_requiring_creation();
        if ($missing !== []) {
            $owned = array_fill_keys(self::scope_index_ownership_keys(), true);
            foreach ($missing as $key) {
                if (in_array($key, ['targeted', 'filtered'], true)) {
                    $owned[$key] = true;
                }
            }
            $keys = array_keys($owned);
            sort($keys, SORT_STRING);
            self::set_nonautoloaded_option(self::SCOPE_INDEX_OWNERSHIP_OPTION, $keys);
        }
        $storage->ensure_scope_keyset_indexes();
    }

    /** @return string[] */
    private static function scope_index_ownership_keys(): array
    {
        $raw = self::get_option(self::SCOPE_INDEX_OWNERSHIP_OPTION, []);
        if (!is_array($raw) || count($raw) > 2) {
            return [];
        }
        $keys = [];
        foreach ($raw as $key) {
            if (is_string($key) && in_array($key, ['targeted', 'filtered'], true)) {
                $keys[$key] = true;
            }
        }
        $keys = array_keys($keys);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** Keep normal search state in WordPress's one bounded alloptions preload. */
    private static function ensure_request_options_autoloaded(): void
    {
        global $wpdb;

        if (
            !isset($wpdb)
            || !is_object($wpdb)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'query')
            || !function_exists('maybe_serialize')
        ) {
            throw new RuntimeException('WordPress options storage is unavailable for the FTS request-state migration.');
        }
        $table = (string) ($wpdb->options ?? ((string) ($wpdb->prefix ?? '') . 'options'));
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            throw new RuntimeException('Invalid WordPress options table during the FTS request-state migration.');
        }
        $defaults = [
            // An empty stored override preserves future product defaults.
            self::SETTINGS_OPTION => [],
            self::ANALYZER_OPTIONS_OPTION => [],
            WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION => [],
        ];
        $defaultRows = [];
        $defaultArgs = [];
        foreach ($defaults as $name => $default) {
            $defaultRows[] = "(%s,%s,'yes')";
            array_push($defaultArgs, $name, maybe_serialize($default));
        }
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$table}` (option_name,option_value,autoload) VALUES " . implode(',', $defaultRows),
            ...$defaultArgs
        ));
        if ($inserted === false || (isset($wpdb->last_error) && (string) $wpdb->last_error !== '')) {
            throw new RuntimeException('Could not initialize the autoloaded FTS request options.');
        }
        $names = [
            self::SCHEMA_VERSION_OPTION,
            self::INDEX_HEALTH_OPTION,
            self::READINESS_INCARNATION_OPTION,
            self::SEARCH_READY_INCARNATION_OPTION,
            self::SETTINGS_OPTION,
            self::ANALYZER_OPTIONS_OPTION,
            WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION,
        ];
        $placeholders = implode(',', array_fill(0, count($names), '%s'));
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET autoload = 'yes' WHERE option_name IN ({$placeholders}) AND autoload <> 'yes'",
            ...$names
        ));
        if ($updated === false || (isset($wpdb->last_error) && (string) $wpdb->last_error !== '')) {
            throw new RuntimeException('Could not autoload the FTS request-state options.');
        }
        if (function_exists('wp_cache_delete')) {
            foreach ($names as $name) {
                wp_cache_delete($name, 'options');
            }
            wp_cache_delete('alloptions', 'options');
        }
    }

    /**
     * Move the incompatible seven-table index aside and create the v4 index.
     *
     * Every rename is idempotent and the logical schema version is written only
     * after physical v4 verification. Interrupted upgrades therefore resume
     * from table presence without ever mixing old and new retrieval paths. The
     * legacy derived tables remain available for operator recovery until the v4
     * corpus reaches ready state, at which point background cleanup removes them.
     */
    private static function migrate_relational_schema_v4(WP_FTS_Storage_Mysql $storage): bool
    {
        $v4AlreadyValid = !empty($storage->verify_schema()['valid']);

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            throw new RuntimeException('Pure PHP FTS requires the WordPress database for schema migration.');
        }
        $prefix = (string) ($wpdb->prefix ?? '');
        $renamed = false;
        foreach (self::legacy_relational_table_suffixes() as $source_suffix => $target_suffix) {
            $source = $prefix . $source_suffix;
            $target = $prefix . $target_suffix;
            if (!self::migration_table_exists($source) || self::migration_table_exists($target)) {
                continue;
            }
            if ($source_suffix === 'fts_terms' && self::migration_table_has_column($source, 'term_id')) {
                continue;
            }
            if ($source_suffix === 'fts_postings' && self::migration_table_has_column($source, 'term_id')) {
                continue;
            }

            self::migration_query('RENAME TABLE ' . self::migration_identifier($source) . ' TO ' . self::migration_identifier($target));
            $renamed = true;
            self::migration_phase('legacy_renamed_' . $source_suffix);
        }
        if ($renamed) {
            self::migration_phase('legacy_renamed');
        }

        if (!$v4AlreadyValid) {
            $storage->create_tables();
        }
        if (empty($storage->verify_schema()['valid'])) {
            throw new RuntimeException('The relational FTS schema could not be verified after migration.');
        }
        if (!$v4AlreadyValid) {
            self::migration_phase('v4_created');
        }

        return $renamed || !$v4AlreadyValid;
    }

    /** @return array<string,string> Original suffix => recoverable renamed suffix. */
    private static function legacy_relational_table_suffixes(): array
    {
        return [
            'fts_terms' => 'fts_legacy_terms',
            'fts_postings' => 'fts_legacy_postings',
            'fts_docs' => 'fts_legacy_docs',
            'fts_doc_lengths' => 'fts_legacy_doc_lengths',
            'fts_docmeta' => 'fts_legacy_docmeta',
            'fts_meta' => 'fts_legacy_meta',
            'fts_queue' => 'fts_legacy_queue',
        ];
    }

    private static function migration_table_exists(string $table): bool
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if (isset($wpdb->last_error) && trim((string) $wpdb->last_error) !== '') {
            throw new RuntimeException('Could not inspect an FTS migration table: ' . trim((string) $wpdb->last_error));
        }

        return is_scalar($value) && (string) $value === $table;
    }

    private static function migration_table_has_column(string $table, string $column): bool
    {
        global $wpdb;
        $rows = $wpdb->get_col('SHOW COLUMNS FROM ' . self::migration_identifier($table));
        if (!is_array($rows) || (isset($wpdb->last_error) && trim((string) $wpdb->last_error) !== '')) {
            throw new RuntimeException('Could not inspect FTS migration columns.');
        }

        return in_array($column, array_map('strval', $rows), true);
    }

    private static function migration_query(string $sql): void
    {
        global $wpdb;
        if ($wpdb->query($sql) === false || (isset($wpdb->last_error) && trim((string) $wpdb->last_error) !== '')) {
            throw new RuntimeException('Could not advance the FTS schema migration: ' . trim((string) ($wpdb->last_error ?? '')));
        }
    }

    /** Expose deterministic migration boundaries for destructive resume tests. */
    private static function migration_phase(string $phase): void
    {
        if (function_exists('do_action')) {
            do_action('wp_fts_schema_migration_phase', $phase);
        }
    }

    /** Remove every renamed v3 derived table only after v4 is fully ready. */
    private static function cleanup_legacy_relational_tables(): void
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            throw new RuntimeException('WordPress database is unavailable for FTS migration cleanup.');
        }
        $prefix = (string) ($wpdb->prefix ?? '');
        $tables = [];
        foreach (self::legacy_relational_table_suffixes() as $source_suffix => $target_suffix) {
            $tables[] = self::migration_identifier($prefix . $target_suffix);
            if (!in_array($source_suffix, ['fts_terms', 'fts_postings'], true)) {
                // A failure after the target already existed can leave an
                // original old-only table beside its recoverable renamed copy.
                $tables[] = self::migration_identifier($prefix . $source_suffix);
            }
        }
        self::migration_query('DROP TABLE IF EXISTS ' . implode(', ', array_values(array_unique($tables))));
    }

    private static function migration_identifier(string $identifier): string
    {
        if ($identifier === '' || strlen($identifier) > 64 || preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('Invalid FTS migration table identifier.');
        }

        return '`' . $identifier . '`';
    }

    /**
     * @param array{missing_tables?:mixed,missing_columns?:mixed,unexpected_columns?:mixed,invalid_columns?:mixed,missing_indexes?:mixed,unexpected_indexes?:mixed,invalid_engines?:mixed} $physical
     */
    private static function schema_verification_failure_summary(array $physical): string
    {
        $parts = [];
        foreach (['missing_tables', 'missing_columns', 'unexpected_columns', 'invalid_columns', 'missing_indexes', 'unexpected_indexes', 'invalid_engines'] as $key) {
            $values = is_array($physical[$key] ?? null) ? $physical[$key] : [];
            $values = array_values(array_filter(array_map(
                static fn(mixed $value): string => is_scalar($value) ? (string) $value : '',
                $values
            )));
            if ($values !== []) {
                $parts[] = $key . '=' . implode(',', array_slice($values, 0, 12));
            }
        }

        return $parts !== [] ? implode('; ', $parts) : 'unknown physical schema mismatch';
    }

    /**
     * Repair schema only when the stored version is missing or stale.
     */
    public static function maybe_upgrade_schema(): void
    {
        if (self::option_matches_schema_version(self::get_option(self::SCHEMA_VERSION_OPTION, null))) {
            return;
        }

        // Visitor, save, and search requests never inspect or mutate physical
        // schema. Upgrades are activation/CLI/background maintenance work; until
        // that work completes every search surface stays on canonical WordPress.
        self::schedule_schema_provisioning();
    }

    /**
     * Run schema migration only from the dedicated maintenance event.
     *
     * A failed migration leaves the saved version stale, so every search
     * surface remains on WordPress and the event is retried without throwing
     * into visitor traffic.
     */
    public static function run_scheduled_schema_upgrade(): void
    {
        $token = null;
        try {
            $blocked_reason = null;
            $token = self::acquire_index_lock('maintenance', $blocked_reason);
            if ($token === null) {
                if ($blocked_reason === 'uninstall_fenced') {
                    return;
                }
                self::schedule_schema_provisioning(60);
                return;
            }
            self::$active_index_writer_token = $token;
            self::$active_index_writer_prefix = self::current_database_prefix();
            self::upgrade_schema();
            self::storage(false)->cleanup_empty_terms();
            self::clear_stale_global_visibility_fence_signal();
            if (self::finalize_initial_index_readiness_in_maintenance()) {
                self::clear_verified_search_runtime_failure();
                return;
            }

            $health = self::index_health_state();
            if (
                self::sanitize_initial_index_status($health['initial_index_status'] ?? '') !== self::INITIAL_INDEX_STATUS_READY
                || !self::readiness_completion_matches($health)
                || !empty($health['global_visibility_fence_active'])
            ) {
                $queue = self::index_queue(false);
                if (!$queue->has_work() && !self::readiness_completion_matches($health)) {
                    // An interrupted maintenance event may have created v4
                    // without leaving either a completed reconciliation or a
                    // durable scope. Reassert exactly that missing generation.
                    self::mark_initial_index_pending(false);
                    self::enqueue_corpus_scope($queue, ['reason' => 'schema_upgrade_resume']);
                    self::migration_phase('reconciliation_enqueued');
                }
                self::schedule_queue_processor();
                self::schedule_schema_provisioning(300);
            }
        } catch (Throwable $error) {
            self::remember_schema_upgrade_failure($error);
            self::schedule_schema_provisioning(300);
        } finally {
            if ($token !== null) {
                try {
                    self::release_index_lock($token);
                } finally {
                    self::$active_index_writer_token = null;
                    self::$active_index_writer_prefix = null;
                }
            }
        }
    }

    private static function schedule_schema_provisioning(int $delay_seconds = 10): bool
    {
        if (!function_exists('wp_schedule_single_event')) {
            return false;
        }
        if (function_exists('wp_next_scheduled') && wp_next_scheduled(self::SCHEMA_UPGRADE_CRON_HOOK)) {
            return true;
        }
        if (self::uninstall_fence_active()) {
            return false;
        }

        return wp_schedule_single_event(time() + max(1, $delay_seconds), self::SCHEMA_UPGRADE_CRON_HOOK) === true;
    }

    /**
     * Retire only a stale diagnostic global-fence signal.
     *
     * The indexed work row is the authorization source of truth. A process may
     * die after deleting its random sentinel and before updating wp_options;
     * that crash must not turn an already-durable exact-post handoff into a
     * full-corpus rebuild. The raw-option CAS also avoids overwriting a newer
     * health transition observed after the bounded work-table probe.
     */
    private static function clear_stale_global_visibility_fence_signal(): bool
    {
        $expected = self::get_option(self::INDEX_HEALTH_OPTION, []);
        $state = self::sanitize_index_health_state($expected);
        if (empty($state['global_visibility_fence_active'])) {
            return true;
        }
        if (self::index_queue(false)->global_visibility_scope_exists()) {
            return false;
        }

        $state['global_visibility_fence_active'] = false;
        return self::compare_and_swap_index_health($expected, $state);
    }

    private static function remember_schema_upgrade_failure(Throwable $error): void
    {
        self::clear_search_ready_incarnation();
        $state = self::index_health_state();
        $state['status'] = 'unhealthy';
        $state['schema_upgrade_error'] = self::sanitize_index_failure_text(
            get_class($error) . ': ' . $error->getMessage(),
            self::MAX_INDEX_FAILURE_ERROR_BYTES
        );
        self::set_option(self::INDEX_HEALTH_OPTION, $state);
    }

    /**
     * Build the production backend, optionally scheduling stale-schema repair.
     *
     * Reads are public. Every mutation validates the plugin's shared writer
     * lease before opening a transaction; programmatic content changes should
     * enqueue canonical post IDs through invalidate_post_content_dependencies().
     */
    public static function storage(bool $ensure_schema = false): WP_FTS_Storage_Mysql
    {
        if ($ensure_schema) {
            self::maybe_upgrade_schema();
        }

        return self::mysql_storage();
    }

    /**
     * Record one canonical post mutation as durable direct work.
     *
     * Canonical WordPress visibility is checked by search SQL, so foreground
     * saves never need to analyze content, inspect schema, or tombstone rows.
     *
     * @param array<string,mixed>|mixed $data Canonical post fields supplied by the hook.
     */
    public static function handle_post_pre_update(int $post_id, mixed $data = []): void
    {
        if (!self::is_normal_post_id($post_id, is_object($data) ? $data : null) || !self::settings()['auto_index']) {
            return;
        }

        // This durable row crosses the dirty boundary before wp_posts changes.
        // Its watchdog is deliberately not claimable while the canonical
        // update is open. The post-commit hook advances it to ready now. If the
        // guarded request dies first, it becomes recoverable after five
        // minutes. Guard acquisition failure stops before this queue write.
        self::fence_post_mutation($post_id);
    }

    /** Promote one durable post generation after WordPress commits the save. */
    public static function handle_post_save(int $post_id, mixed $post = null, mixed ...$unused): void
    {
        if (!self::is_normal_post_id($post_id, is_object($post) ? $post : null) || !self::settings()['auto_index']) {
            return;
        }

        self::queue_post($post_id);
    }

    /** Preserve the former public transition callback without registering a duplicate hook. */
    public static function handle_status_transition(string $new_status, string $old_status, mixed $post): void
    {
        if (!is_object($post) || !isset($post->ID)) {
            return;
        }
        $post_id = (int) $post->ID;
        if (!self::is_normal_post_id($post_id, $post)) {
            return;
        }
        if (self::is_indexable_post($post)) {
            self::handle_post_save($post_id, $post);
            return;
        }
        if ($new_status !== $old_status) {
            // Canonical visibility already excludes the new status; retain the
            // old callback's asynchronous physical cleanup even when automatic
            // indexing is disabled.
            self::queue_post($post_id);
        }
    }

    /** Hide derived state before physical deletion without making it claimable. */
    public static function handle_post_pre_delete(int $post_id, mixed $post = null): void
    {
        if (!self::is_normal_post_id($post_id, is_object($post) ? $post : null)) {
            return;
        }

        self::fence_post_mutation($post_id);
    }

    /** Advance physical deletion work after the canonical row is gone. */
    public static function handle_post_delete(int $post_id, mixed $post = null): void
    {
        if (!self::is_normal_post_id($post_id, is_object($post) ? $post : null)) {
            return;
        }

        self::queue_post($post_id);
    }

    /**
     * Hide an old taxonomy projection before WordPress mutates relationships.
     */
    public static function handle_term_relationship_pre_change(int $object_id, mixed ...$unused): void
    {
        if (!self::is_normal_post_id($object_id) || !self::settings()['auto_index']) {
            return;
        }
        if (!empty(self::$relationship_pre_mutations[$object_id])) {
            self::refresh_post_mutation_fence($object_id);
            return;
        }

        if (!self::fence_post_mutation($object_id)) {
            return;
        }
        if (self::$foreground_bulk_mutation_scope !== null) {
            return;
        }
        self::$relationship_pre_mutations[$object_id] = true;
        self::$relationship_post_mutations[$object_id] = false;
    }

    /**
     * Fence the committed taxonomy projection once per relationship operation.
     */
    public static function handle_term_relationship_change(int $object_id, mixed ...$unused): void
    {
        if (!self::is_normal_post_id($object_id) || !self::settings()['auto_index']) {
            return;
        }
        if (self::$foreground_bulk_mutation_scope !== null) {
            self::queue_post($object_id);
            return;
        }
        $hook = self::current_relationship_hook();
        if ($hook === 'deleted_term_relationships') {
            if (self::foreground_mutation_target_is_retained('post:' . $object_id)) {
                self::$relationship_shutdown_mutations[$object_id] = true;
            } else {
                // A blog switch inside the canonical operation deliberately
                // abandons the old site's request token. The post-SQL hook is
                // still authoritative and must leave a successor even if the
                // old watchdog already recovered before WordPress returned.
                self::queue_post($object_id);
            }
            return;
        }
        if (!empty(self::$relationship_post_mutations[$object_id])) {
            return;
        }
        if ($hook === 'set_object_terms' && self::relationship_hook_nesting_depth() > 1) {
            return;
        }

        self::queue_post($object_id);
        if (self::$foreground_bulk_mutation_scope !== null) {
            return;
        }
        if (self::foreground_mutation_target_is_retained('post:' . $object_id)) {
            self::$relationship_post_mutations[$object_id] = true;
        }
        unset(self::$relationship_pre_mutations[$object_id], self::$relationship_shutdown_mutations[$object_id]);
    }

    /** Promote direct relationship deletes that had no outer set_object_terms hook. */
    public static function flush_relationship_mutations(): void
    {
        foreach (array_keys(self::$relationship_shutdown_mutations) as $object_id) {
            $object_id = (int) $object_id;
            if ($object_id > 0 && empty(self::$relationship_post_mutations[$object_id])) {
                self::queue_post($object_id);
                self::$relationship_post_mutations[$object_id] = true;
            }
            unset(self::$relationship_pre_mutations[$object_id], self::$relationship_shutdown_mutations[$object_id]);
        }
    }

    /**
     * Install a targeted dirty scope before a term label can change.
     *
     * `wp_update_term()` has already primed the term cache at this hook. If an
     * adapter cannot expose its term-taxonomy id, one coalesced corpus scope is
     * the only fail-closed watchdog; the normal post hook retains that same
     * authority even if WordPress exposes the id only after the update.
     */
    public static function handle_taxonomy_term_pre_edit(int $term_id, string $taxonomy, mixed $args = []): void
    {
        if ($term_id <= 0 || $taxonomy === '' || !self::settings()['auto_index']) {
            return;
        }

        if (self::foreground_corpus_fence_active()) {
            return;
        }

        $tt_id = is_array($args) ? max(0, (int) ($args['term_taxonomy_id'] ?? 0)) : 0;
        if ($tt_id <= 0 && function_exists('get_term')) {
            $term = get_term($term_id, $taxonomy);
            if (is_object($term)) {
                $tt_id = max(0, (int) ($term->term_taxonomy_id ?? 0));
            }
        }
        $payload = [
            'reason' => 'taxonomy_term_editing',
            'taxonomy' => $taxonomy,
            'term_id' => $term_id,
        ];
        if ($tt_id > 0) {
            $payload['term_taxonomy_id'] = $tt_id;
        }
        $boundary_key = 'taxonomy:' . $taxonomy . ':' . $term_id;
        if ($tt_id <= 0) {
            $depth = self::$taxonomy_term_global_pre_boundaries[$boundary_key] ?? 0;
            self::$taxonomy_term_global_pre_boundaries[$boundary_key] = $depth < PHP_INT_MAX
                ? $depth + 1
                : PHP_INT_MAX;
        }
        self::fence_scope_reconciliation(
            $boundary_key,
            $payload,
            $tt_id <= 0,
            $tt_id > 0 ? 'term_taxonomy' : '',
            $tt_id
        );
    }

    /** Queue targeted taxonomy reconciliation, with a corpus fallback when no taxonomy id exists. */
    public static function handle_taxonomy_term_edit(int $term_id, int $tt_id, string $taxonomy, mixed $args = []): void
    {
        if ($term_id <= 0 || $taxonomy === '' || !self::settings()['auto_index']) {
            return;
        }
        if (self::foreground_corpus_fence_active()) {
            return;
        }

        $boundary_key = 'taxonomy:' . $taxonomy . ':' . $term_id;
        $global_pre_depth = self::$taxonomy_term_global_pre_boundaries[$boundary_key] ?? 0;
        if ($global_pre_depth > 1) {
            self::$taxonomy_term_global_pre_boundaries[$boundary_key] = $global_pre_depth - 1;
        } else {
            unset(self::$taxonomy_term_global_pre_boundaries[$boundary_key]);
        }
        $global = $global_pre_depth > 0 || $tt_id <= 0;
        self::enqueue_scope_reconciliation($boundary_key, [
            'reason' => 'taxonomy_term_edited',
            'taxonomy' => $taxonomy,
            'term_id' => $term_id,
            'term_taxonomy_id' => $tt_id,
        ], $global, !$global && $tt_id > 0 ? 'term_taxonomy' : '', $global ? 0 : $tt_id);
    }

    /** Fail closed with one corpus scope before WordPress removes relations. */
    public static function handle_taxonomy_term_pre_delete(int $term_id, string $taxonomy): void
    {
        if ($term_id <= 0 || $taxonomy === '' || !self::settings()['auto_index']) {
            return;
        }

        // Relationship rows disappear before a background worker can safely
        // enumerate them. One global scope keeps the old index ineligible and
        // lets keyset reconciliation discover both live posts and stale derived
        // rows without a 50k-row foreground INSERT ... SELECT.
        self::fence_scope_reconciliation(
            'taxonomy-delete:' . $taxonomy . ':' . $term_id,
            [
                'reason' => 'taxonomy_term_deleted',
                'taxonomy' => $taxonomy,
                'term_id' => $term_id,
            ],
            true
        );
    }

    /**
     * Queue one bounded corpus reconciliation after term deletion.
     */
    public static function handle_taxonomy_term_delete(
        int $term_id,
        int $tt_id,
        string $taxonomy,
        mixed $deleted_term,
        mixed $object_ids
    ): void {
        if ($term_id > 0 && $taxonomy !== '' && self::settings()['auto_index']) {
            // The pre-delete scope may have been completely consumed while
            // WordPress was deleting canonical rows. Advance the same global
            // key after commit so stale taxonomy text cannot reappear.
            self::enqueue_scope_reconciliation('taxonomy-delete:' . $taxonomy . ':' . $term_id, [
                'reason' => 'taxonomy_term_deleted',
                'taxonomy' => $taxonomy,
                'term_id' => $term_id,
            ]);
        }
    }

    /**
     * Queue a post only when metadata consumed by the extractor changed.
     */
    public static function handle_post_meta_change(
        mixed $meta_id,
        int $post_id,
        string $meta_key,
        mixed $meta_value = null
    ): void {
        if (self::foreground_corpus_fence_active()) {
            return;
        }
        if (isset(self::$post_meta_global_mutations[$meta_key])) {
            if (self::$post_meta_global_mutations[$meta_key] === 'pre') {
                // The matching post-SQL action promotes the one global scope;
                // any adapter fan-out after that must perform zero SQL.
                self::enqueue_scope_reconciliation('post-meta-delete-all:' . $meta_key, [
                    'reason' => 'selected_post_meta_deleted_globally',
                    'meta_key' => $meta_key,
                ]);
                self::$post_meta_global_mutations[$meta_key] = 'post';
            }
            return;
        }
        if (
            $post_id <= 0
            && self::settings()['auto_index']
            && self::post_meta_key_may_affect_index($meta_key)
        ) {
            // State can be absent when canonical code switched blogs between
            // the pre- and post-SQL hooks. Publish the global successor rather
            // than losing a delete-all mutation after its old fence recovered.
            self::enqueue_scope_reconciliation('post-meta-delete-all:' . $meta_key, [
                'reason' => 'selected_post_meta_deleted_globally',
                'meta_key' => $meta_key,
            ]);
            self::$post_meta_global_mutations[$meta_key] = 'post';
            return;
        }
        if (!self::post_meta_change_requires_reindex($post_id, $meta_key)) {
            return;
        }

        if (isset(self::$post_meta_fenced_posts[$post_id])) {
            // Keep the first boundary locked through request shutdown. A
            // WooCommerce-style save may issue hundreds of separate metadata
            // writes; their post-SQL actions must not each add two FTS queries.
            self::$post_meta_committed_posts[$post_id] = true;
            return;
        }

        // Core emits a pre-SQL metadata action before this callback. Retain a
        // one-query fallback for integrations that invoke only the public
        // post-SQL action, and coalesce any physical-row fan-out by post id.
        if (empty(self::$post_meta_committed_posts[$post_id])) {
            self::queue_post($post_id);
            if (self::foreground_mutation_target_is_retained('post:' . $post_id)) {
                self::$post_meta_committed_posts[$post_id] = true;
            }
        }
    }

    /** Cross the dirty boundary only after add_metadata passes every no-op check. */
    public static function handle_post_meta_pre_add(
        int $post_id,
        string $meta_key,
        mixed $meta_value = null
    ): void {
        self::begin_post_meta_mutation($post_id, $meta_key);
    }

    /** Cross the dirty boundary immediately before WordPress updates metadata SQL. */
    public static function handle_post_meta_pre_update(
        mixed $meta_id,
        int $post_id,
        string $meta_key,
        mixed $meta_value = null
    ): void {
        self::begin_post_meta_mutation($post_id, $meta_key);
    }

    /** Cross direct or delete-all dirty state immediately before metadata SQL. */
    public static function handle_post_meta_pre_delete(
        mixed $meta_ids,
        int $post_id,
        string $meta_key,
        mixed $meta_value = null
    ): void {
        if (self::foreground_corpus_fence_active()) {
            return;
        }
        if (
            $post_id <= 0
            && self::settings()['auto_index']
            && self::post_meta_key_may_affect_index($meta_key)
        ) {
            if ((self::$post_meta_global_mutations[$meta_key] ?? '') === 'pre') {
                return;
            }
            self::$post_meta_global_mutations[$meta_key] = 'pre';
            self::fence_scope_reconciliation(
                'post-meta-delete-all:' . $meta_key,
                [
                    'reason' => 'selected_post_meta_deleted_globally',
                    'meta_key' => $meta_key,
                ],
                true
            );
            return;
        }

        self::begin_post_meta_mutation($post_id, $meta_key);
    }

    private static function begin_post_meta_mutation(int $post_id, string $meta_key): void
    {
        if (self::foreground_corpus_fence_active()) {
            return;
        }
        if (!self::post_meta_change_requires_reindex($post_id, $meta_key)) {
            return;
        }

        // A later ordinary mutation is a new boundary even if this request
        // previously completed delete-all for the same selected key.
        unset(self::$post_meta_global_mutations[$meta_key]);
        if (isset(self::$post_meta_fenced_posts[$post_id])) {
            self::refresh_post_mutation_fence($post_id);
            return;
        }

        if (!self::fence_post_mutation($post_id)) {
            return;
        }
        self::$post_meta_fenced_posts[$post_id] = true;
        unset(self::$post_meta_committed_posts[$post_id]);
    }

    /** Promote at most one selected-metadata boundary per post and request. */
    public static function flush_post_meta_mutations(): void
    {
        foreach (array_keys(self::$post_meta_fenced_posts) as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0 && !empty(self::$post_meta_committed_posts[$post_id])) {
                self::queue_post($post_id, true);
            }
            unset(self::$post_meta_fenced_posts[$post_id], self::$post_meta_committed_posts[$post_id]);
        }
    }

    /**
     * Atomically hand off a request-global fence after bounded hook fan-out.
     *
     * Exact post-only requests create ready post rows and delete the global
     * sentinel. Scope fan-out or the 1,001st retained target instead transfers
     * into the one canonical corpus reconciliation. A failure leaves the
     * durable global fence in place for guarded watchdog recovery.
     */
    public static function flush_foreground_bulk_mutations(): void
    {
        if (self::$foreground_queue_writes_disabled) {
            // Any already-durable fences remain fail-closed and recover after
            // their guarded abandonment deadline. Capability failures stop
            // before adding another ownerless generation.
            self::release_foreground_owner_guard();
            return;
        }
        $bulk = self::$foreground_bulk_mutation_scope;
        if ($bulk === null) {
            self::$foreground_mutation_targets = [];
            self::$foreground_mutation_posts = [];
            self::$foreground_mutation_repeat_boundaries = [];
            self::$foreground_direct_scope_keys = [];
            self::$foreground_direct_scope_tokens = [];
            self::$taxonomy_term_global_pre_boundaries = [];
            self::$foreground_mutation_has_scope = false;
            if (self::$mutation_fence_tokens === []) {
                self::$foreground_mutation_prefix = null;
                self::$foreground_bulk_activation_attempted = false;
            }
            self::release_foreground_owner_guard();
            return;
        }
        if (self::foreground_queue_blocked_for_current_prefix()) {
            self::$foreground_queue_writes_disabled = true;
            self::release_foreground_owner_guard();
            return;
        }

        $ownedPostTokens = [];
        foreach (array_keys(self::$foreground_mutation_posts) as $post_id) {
            $post_id = (int) $post_id;
            $fence = self::$mutation_fence_tokens['post:' . $post_id] ?? null;
            if ($post_id > 0 && is_array($fence) && ($fence['token'] ?? '') !== '') {
                $ownedPostTokens[$post_id] = (string) $fence['token'];
            }
        }
        $ownedScopeTokens = [];
        foreach (self::$foreground_direct_scope_keys as $identity => $scopeKey) {
            // Promotion leaves this marker on the exact ready generation. A
            // worker claim or concurrent enqueue replaces it, so shutdown can
            // discard only work that the canonical corpus row truly subsumes.
            $ownedScopeTokens[$scopeKey] = self::$foreground_direct_scope_tokens[$identity] ?? '';
        }
        $requiresCorpus = !empty($bulk['requires_corpus']) || !empty($bulk['overflow']);
        $postIds = $requiresCorpus
            ? array_keys($ownedPostTokens)
            : array_keys(self::$foreground_mutation_posts);
        try {
            $scope_incarnation = self::sanitize_readiness_incarnation($bulk['incarnation'] ?? '');
            $profile_hash = self::sanitize_index_profile_hash($bulk['profile_hash'] ?? '');
            if ($requiresCorpus) {
                // The request sentinel may predate a newer canonical corpus
                // fence. Bind its successor to the current health authority;
                // the queue preserves matching protected payload/ownership while
                // still advancing the desired generation.
                $current_incarnation = self::readiness_incarnation();
                if ($current_incarnation !== '') {
                    $scope_incarnation = $current_incarnation;
                }
                $profile_hash = self::current_index_profile_hash();
            }
            self::foreground_index_queue()->handoff_foreground_mutation_scope(
                (string) $bulk['scope_key'],
                (string) $bulk['token'],
                $postIds,
                $ownedPostTokens,
                $ownedScopeTokens,
                $requiresCorpus,
                [
                    'reason' => $requiresCorpus
                        ? 'foreground_bulk_corpus_reconciliation'
                        : 'foreground_bulk_post_handoff',
                    'overflow' => !empty($bulk['overflow']),
                    'scope_fanout' => !empty($bulk['requires_corpus']),
                    'profile_hash' => $requiresCorpus ? $profile_hash : '',
                ],
                scope_incarnation: $requiresCorpus ? $scope_incarnation : ''
            );
        } catch (Throwable $error) {
            self::disable_foreground_queue_writes($error);
            self::release_foreground_owner_guard();
            return;
        }

        foreach (array_keys($ownedPostTokens) as $post_id) {
            unset(self::$mutation_fence_tokens['post:' . (int) $post_id]);
        }
        foreach (array_keys($ownedScopeTokens) as $scopeKey) {
            unset(self::$mutation_fence_tokens['scope:' . hash('sha256', $scopeKey)]);
        }
        unset(self::$mutation_fence_tokens['scope:' . hash('sha256', (string) $bulk['scope_key'])]);
        self::$foreground_mutation_targets = [];
        self::$foreground_mutation_posts = [];
        self::$foreground_mutation_repeat_boundaries = [];
        self::$foreground_direct_scope_keys = [];
        self::$foreground_direct_scope_tokens = [];
        self::$taxonomy_term_global_pre_boundaries = [];
        self::$foreground_mutation_has_scope = false;
        self::$foreground_bulk_mutation_scope = null;
        self::$foreground_mutation_prefix = null;
        self::$foreground_bulk_activation_attempted = false;
        try {
            self::schedule_queue_processor(1, true);
        } finally {
            // Handoff/delete is durable before the worker can observe the
            // request's shared file guard as free.
            self::release_foreground_owner_guard();
        }
    }

    /**
     * Explicitly invalidate host posts whose rendered output depends on external state.
     *
     * Dynamic blocks can depend on other posts, options, users, or remote data.
     * Callers that opt into rendering must call this method when those dependencies
     * change. Repeated invalidations advance the durable queue generation.
     *
     * @param int|int[] $post_ids
     * @return int Number of unique indexable posts enqueued.
     */
    public static function invalidate_post_content_dependencies(int|array $post_ids): int
    {
        $post_ids = is_array($post_ids) ? $post_ids : [$post_ids];
        if (count($post_ids) > WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS) {
            throw new InvalidArgumentException('FTS invalidation accepts at most 1,000 post ids.');
        }

        return self::queue_posts($post_ids);
    }

    private static function post_meta_change_requires_reindex(int $post_id, string $meta_key): bool
    {
        return self::is_normal_post_id($post_id)
            && self::settings()['auto_index']
            && self::post_meta_key_may_affect_index($meta_key, $post_id);
    }

    private static function post_meta_key_may_affect_index(string $meta_key, int $post_id = 0): bool
    {
        if ($meta_key === '') {
            return false;
        }
        if ($meta_key === self::LANGUAGE_META_KEY) {
            return true;
        }

        $filtered_selection = function_exists('has_filter')
            && (
                has_filter('wp_fts_post_custom_fields') !== false
                || has_filter(self::POST_INDEX_OPTIONS_FILTER) !== false
            );
        if ($filtered_selection) {
            // Metadata hooks run after the mutation. A filter-driven selection
            // can stop returning a key as a consequence of its deletion, so
            // the current selection cannot prove the old indexed value was
            // unrelated.
            return true;
        }

        $configured = self::get_option(WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION, []);
        try {
            $selected_keys = (new WP_FTS_PostContentExtractor())->selected_custom_field_keys(
                (object) ['ID' => max(0, $post_id)],
                ['custom_fields' => $configured]
            );
        } catch (Throwable) {
            // Malformed selection remains worker-visible poison. Foreground
            // canonical writes fail closed by retaining one dirty generation.
            return true;
        }

        return in_array($meta_key, $selected_keys, true);
    }

    /**
     * Process a bounded batch of queued post ids.
     *
     * @return int Number of queued ids processed.
     */
    public static function process_queue(int $batch_size = self::DEFAULT_BATCH_SIZE): int
    {
        $summary = self::process_indexing_batch('manual', [
            'batch_size' => max(1, $batch_size),
            'source' => 'queue',
        ]);

        return max(0, (int) ($summary['queue_processed'] ?? 0));
    }

    /**
     * WP-Cron entry point for bounded queue and backfill indexing work.
     *
     * @return array<string,mixed> Small batch summary suitable for logs/tests.
     */
    public static function process_scheduled_indexing(): array
    {
        $summary = self::process_indexing_batch('cron');
        if (!empty($summary['successor_schedule_failed'])) {
            // This invocation has completed every cleanup it owns and
            // preserved every queued generation. Throwing here makes
            // WP-Cron's failed handoff observable without retrying inside the
            // same callback.
            throw new WP_FTS_Index_Successor_Schedule_Failed();
        }

        return $summary;
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
     * Queue a bounded WP-CLI selection for the shared set-oriented worker.
     *
     * @param int[] $post_ids
     * @param array<string,mixed> $index_options
     */
    public static function enqueue_posts_for_reindex(array $post_ids, array $index_options = []): int
    {
        $releaseForegroundGuard = false;
        $queue = self::scoped_foreground_lifecycle_checked_index_queue($releaseForegroundGuard);
        try {
            $payload = [];
            $language = WP_FTS_TermNamespace::language_from_options(
                $index_options,
                null,
                ['lang', 'language', 'primary_lang', 'document_lang']
            );
            if ($language !== null) {
                $payload['index_options'] = ['lang' => $language, 'document_lang' => $language];
            }
            $queued = $queue->enqueue_many($post_ids, null, $payload);
            if ($queued > 0) {
                self::schedule_queue_processor();
            }

            return $queued;
        } finally {
            if ($releaseForegroundGuard) {
                self::release_foreground_owner_guard(false);
            }
        }
    }

    /**
     * Queue one durable, keyset-expanded WP-CLI reindex selection.
     *
     * The selection lives in one bounded scope row. Workers materialize only
     * their next page, so a large site never receives one queue row per matching
     * post before indexing starts.
     *
     * @param array{post_status:array<int,string>,post_type:array<int,string>,limit?:int,lang?:string} $options
     * @return string Durable hashed scope job key.
     */
    public static function enqueue_reindex_scope(array $options): string
    {
        $releaseForegroundGuard = false;
        $queue = self::scoped_foreground_lifecycle_checked_index_queue($releaseForegroundGuard);
        try {
            $filters = [];
            foreach (['post_status', 'post_type'] as $name) {
                $raw = $options[$name] ?? [];
                if (!is_array($raw) || $raw === [] || count($raw) > self::MAX_SEARCH_SCOPE_VALUES) {
                    throw new InvalidArgumentException("{$name} must contain between 1 and " . self::MAX_SEARCH_SCOPE_VALUES . ' values.');
                }
                $values = [];
                foreach ($raw as $value) {
                    if (!is_scalar($value) || strlen((string) $value) > self::MAX_SEARCH_SCOPE_VALUE_BYTES) {
                        throw new InvalidArgumentException("{$name} values may contain at most " . self::MAX_SEARCH_SCOPE_VALUE_BYTES . ' bytes.');
                    }
                    $value = trim((string) $value);
                    if ($value !== '') {
                        $values[$value] = true;
                    }
                }
                if ($values === []) {
                    throw new InvalidArgumentException("{$name} must contain at least one non-empty value.");
                }
                $filters[$name] = array_keys($values);
                sort($filters[$name], SORT_STRING);
            }
            $filter_lanes = count($filters['post_status']) * count($filters['post_type']);
            if ($filter_lanes > self::MAX_FILTER_SCOPE_LANES) {
                throw new InvalidArgumentException(
                    'A filtered reindex may select at most ' . self::MAX_FILTER_SCOPE_LANES
                    . ' distinct post-type/status combinations; split a broader selection into separate commands.'
                );
            }

            $payload = [
                'reason' => 'wp_cli_reindex',
                'post_status' => $filters['post_status'],
                'post_type' => $filters['post_type'],
            ];
            $limit = max(0, (int) ($options['limit'] ?? 0));
            if ($limit > 0) {
                $payload['remaining_limit'] = $limit;
            }
            $language = WP_FTS_TermNamespace::language_from_options(
                $options,
                null,
                ['lang', 'language', 'primary_lang', 'document_lang']
            );
            if ($language !== null) {
                $payload['index_options'] = ['lang' => $language, 'document_lang' => $language];
            }

            $identity = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $scope_key = 'wp-cli-reindex:' . hash('sha256', $identity);
            $queue->enqueue_scope(
                $scope_key,
                $payload,
                null,
                WP_FTS_Index_Queue::SCOPE_COVERAGE_FILTERED
            );
            self::schedule_queue_processor();

            return 'scope:' . hash('sha256', $scope_key);
        } finally {
            if ($releaseForegroundGuard) {
                self::release_foreground_owner_guard(false);
            }
        }
    }

    /**
     * Reconcile a CLI delete request with canonical WordPress post state.
     *
     * @return array{status:string,queued:int,post_id:int}
     */
    public static function reconcile_cli_delete(int $post_id): array
    {
        if ($post_id <= 0) {
            return ['status' => 'invalid', 'queued' => 0, 'post_id' => 0];
        }
        $post = self::post_object($post_id);
        if ($post !== null && self::is_indexable_post($post)) {
            return ['status' => 'rejected_eligible', 'queued' => 0, 'post_id' => $post_id];
        }

        $queued = self::enqueue_posts_for_reindex([$post_id]);

        return [
            'status' => $post === null ? 'queued_missing' : 'queued_ineligible',
            'queued' => $queued,
            'post_id' => $post_id,
        ];
    }

    /**
     * Run a direct index writer under the shared indexing lock.
     *
     * Schema repair, reset, and bounded dictionary maintenance can mutate the
     * same index tables outside a queue claim. This helper gives those writers
     * the same lock and bounded diagnostics used by cron/manual batches so
     * operators see a skipped writer instead of overlapping mutations. CLI
     * reindex and delete requests use durable scope/post work instead.
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

        $blocked_reason = null;
        $token = self::acquire_index_lock($source, $blocked_reason);
        if ($token === null) {
            $summary['skipped_locked'] = true;
            $summary['lock_prevented_work'] = true;
            if ($blocked_reason === 'uninstall_fenced') {
                $summary['has_more'] = false;
                self::remember_index_batch_stop($summary, 'uninstall_fenced');
            } else {
                $summary['lock_before'] = self::index_lock_status();
                $summary['has_more'] = true;
                self::remember_index_batch_stop($summary, 'lock_active');
            }
            self::finalize_index_batch_summary($summary, $started);
            if (
                $blocked_reason !== 'uninstall_fenced'
                && (!array_key_exists('record_skip', $opts) || (bool) $opts['record_skip'])
            ) {
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
        self::$active_index_writer_token = $token;
        self::$active_index_writer_prefix = self::current_database_prefix();
        try {
            self::assert_index_writer_ownership();
            $result = $writer();
            self::assert_index_writer_ownership();
            $summary['processed'] = self::index_writer_processed_count($result, $opts);
        } catch (Throwable $e) {
            $thrown = $e;
            self::remember_index_batch_exception_in_summary($summary, $e);
            if ($e instanceof WP_FTS_Index_Writer_Ownership_Lost) {
                self::remember_index_batch_stop($summary, 'ownership_lost');
            }
        } finally {
            try {
                if (self::$active_index_writer_token === $token) {
                    self::release_index_lock($token);
                }
            } finally {
                self::$active_index_writer_token = null;
                self::$active_index_writer_prefix = null;
                self::finalize_index_batch_summary($summary, $started);
                if (!array_key_exists('record_health', $opts) || (bool) $opts['record_health']) {
                    self::update_index_health_state($summary);
                }
            }
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
        $state = array_replace($state, self::index_profile_state($state));
        $work = self::durable_work_status();
        $pending_queue_count = $work['post_count'] + $work['scope_count'];
        $pending_queue_count_relation = $work['counts_capped'] ? 'at_least' : 'exact';
        if (!self::option_matches_schema_version(self::get_option(self::SCHEMA_VERSION_OPTION, null))) {
            // Report only presence. Exact historical cardinality would require
            // deserializing the retired, formerly unbounded option array.
            $pending_queue_count = self::legacy_queue_option_exists() ? 1 : 0;
            $pending_queue_count_relation = $pending_queue_count > 0 ? 'at_least' : 'exact';
        }

        $state['pending_queue_count'] = $pending_queue_count;
        $state['pending_queue_count_relation'] = $pending_queue_count_relation;
        $state['pending_post_work_count'] = $work['post_count'];
        $state['pending_post_work_count_relation'] = $work['post_count_relation'];
        $state['pending_scope_work_count'] = $work['scope_count'];
        $state['pending_scope_work_count_relation'] = $work['scope_count_relation'];
        $state['reconciliation_cursor_post_id'] = $work['scope_cursor_post_id'];
        $state['profile_reconciliation_pending'] = self::profile_reconciliation_pending($state);
        $initial_index_pending = self::sanitize_initial_index_status($state['initial_index_status'] ?? '') !== self::INITIAL_INDEX_STATUS_READY;
        $state['reconciliation_active'] = $work['scope_count'] > 0
            || $state['profile_reconciliation_pending']
            || ($initial_index_pending && $pending_queue_count > 0);
        $state['has_more'] = $pending_queue_count > 0;
        $state['lock_active'] = self::index_lock_active();

        return $state;
    }

    /**
     * Return read-only lifecycle state for operator surfaces.
     *
     * @return array<string,mixed>
     */
    public static function operator_status(bool $verify_physical_schema = false): array
    {
        $schema = $verify_physical_schema ? self::schema_status() : self::stored_schema_status();
        $takeover = self::search_takeover_status(false);
        $health = self::search_health();
        $lock = self::index_lock_status();
        $queue_processor_schedule = self::queue_processor_schedule_status($health);
        $cron_runner = self::cron_runner_status($queue_processor_schedule);
        $last_indexed_post_id = max(0, (int) ($health['last_indexed_post_id'] ?? 0));
        $last_indexed_title = is_scalar($health['last_indexed_post_title'] ?? null)
            ? (string) $health['last_indexed_post_title']
            : '';
        $settings = self::settings();

        return [
            'schema_status' => $schema['status'],
            'schema_version' => $schema['stored_version'],
            'expected_schema_version' => $schema['expected_version'],
            'schema_verification' => $verify_physical_schema ? 'physical' : 'stored',
            'storage_backend' => self::index_storage_backend_label(),
            'search_takeover_ready' => (bool) $takeover['ready'],
            'search_takeover_reason' => (string) $takeover['reason'],
            'foreground_owner_guard_blocked' => (bool) ($health['foreground_owner_guard_blocked'] ?? false),
            'initial_index_status' => (string) $takeover['initial_index_status'],
            'initial_index_started_at' => (string) $takeover['initial_index_started_at'],
            'initial_index_completed_at' => (string) $takeover['initial_index_completed_at'],
            'physical_schema_checked' => $verify_physical_schema,
            'physical_schema_usable' => $verify_physical_schema && !empty($schema['physical']['valid']),
            'index_profile_hash' => is_scalar($health['index_profile_hash'] ?? null) ? (string) $health['index_profile_hash'] : '',
            'accepted_index_profile_hash' => is_scalar($health['accepted_index_profile_hash'] ?? null) ? (string) $health['accepted_index_profile_hash'] : '',
            'reconciliation_active' => (bool) ($health['reconciliation_active'] ?? false),
            'profile_reconciliation_pending' => (bool) ($health['profile_reconciliation_pending'] ?? false),
            'pending_queue_count' => max(0, (int) ($health['pending_queue_count'] ?? 0)),
            'pending_queue_count_relation' => self::bounded_count_relation($health['pending_queue_count_relation'] ?? ''),
            'pending_post_work_count' => max(0, (int) ($health['pending_post_work_count'] ?? 0)),
            'pending_post_work_count_relation' => self::bounded_count_relation($health['pending_post_work_count_relation'] ?? ''),
            'pending_scope_work_count' => max(0, (int) ($health['pending_scope_work_count'] ?? 0)),
            'pending_scope_work_count_relation' => self::bounded_count_relation($health['pending_scope_work_count_relation'] ?? ''),
            'reconciliation_cursor_post_id' => isset($health['reconciliation_cursor_post_id'])
                ? max(0, (int) $health['reconciliation_cursor_post_id'])
                : null,
            'queue_processor_schedule' => $queue_processor_schedule,
            'cron_runner' => $cron_runner,
            'ranking_tuning' => self::operator_ranking_tuning_status($settings, $health),
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
            'counts_exact' => false,
            'eligible_count' => null,
            'indexed_count' => null,
            'remaining_count' => null,
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
        $summary['automatic_retry'] = true;
        $summary['max_backoff_seconds'] = self::FAILURE_RECOVERY_MAX_BACKOFF_SECONDS;
        $summary['permanent_rejections_require_content_change'] = true;
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

        $ids = [];
        foreach ($updated as $record) {
            $id = max(0, (int) ($record['post_id'] ?? 0));
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $releaseForegroundGuard = false;
        $queue = self::scoped_foreground_lifecycle_checked_index_queue($releaseForegroundGuard);
        try {
            $queued = $queue->retry_many($ids);
            if ($queued > 0) {
                self::schedule_queue_processor(60, true);
            }

            $state['failure_history'] = self::bound_failure_recovery_records(array_values($records));
            self::set_option(self::INDEX_HEALTH_OPTION, $state);

            return self::failure_recovery_action_result('retry', 'retryable', $updated, $queued, 'Selected failed items were marked retryable and queued for a later bounded indexing pass.');
        } finally {
            if ($releaseForegroundGuard) {
                self::release_foreground_owner_guard(false);
            }
        }
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
     * @return array<string,mixed>
     */
    private static function operator_ranking_tuning_status(array $settings, array $health): array
    {
        $field_boosts = self::settings_field_boosts($settings['field_boosts'] ?? []);
        $recency_strength = self::sanitize_recency_boost_strength($settings['recency_boost_strength'] ?? 0.0);
        $recency_half_life = self::sanitize_recency_boost_half_life($settings['recency_boost_half_life_days'] ?? self::RECENCY_BOOST_HALF_LIFE_DEFAULT);
        $reconciliation_active = !empty($health['reconciliation_active']);

        return [
            'schema' => self::RANKING_TUNING_SCHEMA,
            'match_mode' => is_scalar($settings['match_mode'] ?? null) ? (string) $settings['match_mode'] : self::DEFAULT_SETTINGS['match_mode'],
            'prefix_matching' => !empty($settings['prefix_matching']),
            'prefix_min_length' => self::sanitize_prefix_min_length($settings['prefix_min_length'] ?? self::PREFIX_MIN_LENGTH_DEFAULT),
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
                'recency_boost',
                'result_limit',
                'snippet_length',
                'highlight',
                'frontend_replacement',
                'admin_posts_replacement',
                'search_provider_compatibility',
            ],
            'reconciliation_active' => $reconciliation_active,
            'profile_reconciliation_pending' => !empty($health['profile_reconciliation_pending']),
            'advice' => self::operator_ranking_tuning_advice($reconciliation_active),
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

    private static function operator_ranking_tuning_advice(bool $reconciliation_active): string
    {
        if ($reconciliation_active) {
            return 'Durable index reconciliation is active; index-time ranking settings may not be fully reflected until queued scope and post work finishes. This status block is read-only and does not process or schedule work.';
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
        $operator = self::support_snapshot_redact_value(self::operator_status(true));
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
                'status' => is_scalar($operator['schema_status'] ?? null) ? (string) $operator['schema_status'] : 'unknown',
                'stored_version' => max(0, (int) ($operator['schema_version'] ?? 0)),
                'expected_version' => max(0, (int) ($operator['expected_schema_version'] ?? 0)),
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
            'pending_queue_count_relation' => self::bounded_count_relation($operator['pending_queue_count_relation'] ?? ''),
            'remaining_count' => null,
            'has_more' => !empty($operator['has_more']),
            'queue_processor_schedule' => self::support_snapshot_array_section($operator['queue_processor_schedule'] ?? []),
            'cron_runner' => self::support_snapshot_array_section($operator['cron_runner'] ?? []),
            'lock' => self::support_snapshot_array_section($operator['lock'] ?? []),
            'failure_recovery' => self::support_snapshot_array_section($operator['failure_recovery'] ?? []),
            'reconciliation' => [
                'active' => !empty($operator['reconciliation_active']),
                'profile_pending' => !empty($operator['profile_reconciliation_pending']),
                'post_work_count' => max(0, (int) ($operator['pending_post_work_count'] ?? 0)),
                'post_work_count_relation' => self::bounded_count_relation($operator['pending_post_work_count_relation'] ?? ''),
                'scope_work_count' => max(0, (int) ($operator['pending_scope_work_count'] ?? 0)),
                'scope_work_count_relation' => self::bounded_count_relation($operator['pending_scope_work_count_relation'] ?? ''),
                'cursor_post_id' => isset($operator['reconciliation_cursor_post_id'])
                    ? max(0, (int) $operator['reconciliation_cursor_post_id'])
                    : null,
            ],
            'latest_batch' => [
                'mode' => is_scalar($operator['last_mode'] ?? null) ? (string) $operator['last_mode'] : '',
                'last_run_at' => is_scalar($operator['last_run_at'] ?? null) ? (string) $operator['last_run_at'] : '',
                'processed' => max(0, (int) ($operator['last_batch_processed'] ?? 0)),
                'queue_processed' => max(0, (int) ($operator['last_batch_queue_processed'] ?? 0)),
                'backfill_processed' => max(0, (int) ($operator['last_batch_backfill_processed'] ?? 0)),
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

        if (!empty($operator['reconciliation_active'])) {
            $advice[] = 'Durable index reconciliation is active; continue bounded Health indexing batches or let WP-Cron process the queue.';
        } elseif (max(0, (int) ($operator['remaining_count'] ?? 0)) > 0 || max(0, (int) ($operator['pending_queue_count'] ?? 0)) > 0) {
            $advice[] = 'Indexing work remains; continue bounded Health indexing batches or verify the queue processor is running.';
        }

        if (max(0, (int) ($operator['last_batch_failures'] ?? 0)) > 0) {
            $advice[] = 'The latest batch recorded failures; review the redacted latest batch diagnostics before retrying.';
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
        $site_language = WP_FTS_TermNamespace::canonicalize_lang(self::site_language(), WP_FTS_TermNamespace::DEFAULT_LANG);
        $top_language_config = self::top_language_pack_config_by_language();
        $runtime_support = self::language_support_details($site_language, false);
        $runtime_support_label = self::analyzer_pack_status_matrix_support_label($site_language, $runtime_support, $top_language_config);
        $runtime_support_status = self::operator_language_pack_support_status($runtime_support_label, $runtime_support);
        $raw_matched_language = trim((string) ($runtime_support['matched_language'] ?? ''));
        $matched_language = $raw_matched_language !== ''
            ? WP_FTS_TermNamespace::canonicalize_lang($raw_matched_language, WP_FTS_TermNamespace::DEFAULT_LANG)
            : '';
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
            if (self::uninstall_fence_active()) {
                return self::queue_processor_schedule_action_result(
                    'uninstall_fenced',
                    false,
                    [],
                    'The plugin is uninstalled; explicit activation is required before queue scheduling can resume.'
                );
            }
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

        return self::queue_processor_schedule_status($health);
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
            || max(0, (int) ($remaining_count ?? 0)) > 0
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
     * @return array{status:string,stored_version:int,expected_version:int,skipped_locked:bool,lock_active:bool}
     */
    public static function repair_schema(): array
    {
        $locked = self::run_index_writer_with_lock(
            'schema-repair',
            static function (): array {
                self::upgrade_schema();

                return self::schema_status();
            },
            [
                'batch_size' => 1,
                'record_health' => false,
                'record_skip' => false,
            ]
        );
        if (empty($locked['acquired'])) {
            return array_merge(self::stored_schema_status(), [
                'skipped_locked' => true,
                'lock_active' => true,
            ]);
        }

        $schema = is_array($locked['result'] ?? null) ? $locked['result'] : self::stored_schema_status();
        $schema['skipped_locked'] = false;
        $schema['lock_active'] = false;

        return $schema;
    }

    /**
     * Clear derived FTS index data and runtime indexing state.
     *
     * WordPress posts, plugin settings, analyzer options, and schema version are
     * preserved. One complete scope repopulates the new generation in bounded
     * background batches without an operator having to reconstruct coverage.
     *
     * @return array<string,mixed>
     */
    public static function reset_index(): array
    {
        self::assert_index_writer_ownership();
        $health_before = self::index_health_state();
        // Disable takeover before the first DDL statement. If reset is
        // interrupted, canonical WordPress search remains authoritative until
        // an operator retries reset or completes a fresh reconciliation.
        self::mark_initial_index_pending();
        self::maybe_upgrade_schema();
        $storage = self::storage(false);
        if (!$storage instanceof WP_FTS_Resettable_Storage) {
            throw new RuntimeException('Configured FTS storage does not support index reset.');
        }
        $counts = $storage->reset_index();

        self::delete_option(self::QUEUE_OPTION);
        self::clear_scheduled_queue_processor();
        self::reset_index_health_state();
        self::enqueue_corpus_scope(self::index_queue(false), ['reason' => 'reset']);
        self::migration_phase('reconciliation_enqueued');
        if (!self::schedule_queue_processor()) {
            throw new RuntimeException('The reset reconciliation scope is durable, but its queue processor could not be scheduled.');
        }

        $schema = self::stored_schema_status();

        return [
            'status' => 'reset',
            'reset' => true,
            'reset_strategy' => is_scalar($counts['reset_strategy'] ?? null)
                ? (string) $counts['reset_strategy']
                : 'unknown',
            'reconciliation_queued' => true,
            'counts_exact' => false,
            'schema_status' => $schema['status'],
            'schema_version' => $schema['stored_version'],
            'expected_schema_version' => $schema['expected_version'],
            'storage_backend' => self::index_storage_backend_label(),
            'postings_deleted' => null,
            'terms_deleted' => null,
            'docs_deleted' => null,
            'doc_lengths_deleted' => null,
            'doc_metadata_deleted' => null,
            'collection_metadata_deleted' => null,
            'pending_queue_cleared' => null,
            'last_batch_failures_cleared' => max(0, (int) ($health_before['last_batch_failures'] ?? 0)),
            'failure_recovery_records_cleared' => count(self::sanitize_failure_recovery_records($health_before['failure_history'] ?? [])),
            'wordpress_posts_deleted' => 0,
            'settings_preserved' => true,
            'analyzer_options_preserved' => true,
            'message' => 'Published an empty FTS generation and queued one complete background reconciliation scope.',
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
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb)) {
            $storage = self::mysql_storage();
            $physical = $storage->verify_schema_and_scope_keyset_indexes();
        } else {
            $physical = [
                'valid' => false,
                'available' => false,
                'missing_tables' => [],
                'missing_columns' => [],
                'unexpected_columns' => [],
                'invalid_columns' => [],
                'missing_indexes' => [],
                'unexpected_indexes' => [],
                'fts_tables_valid' => false,
                'scope_keyset_indexes' => ['valid' => false, 'missing' => [], 'error' => 'Database unavailable.'],
            ];
        }
        $status = 'stale';
        if (self::option_matches_schema_version($raw) && !empty($physical['valid'])) {
            $status = 'current';
        } elseif (self::option_matches_schema_version($raw) && isset($physical['available']) && !$physical['available']) {
            $status = 'unavailable';
        } elseif (self::option_matches_schema_version($raw)) {
            $status = 'damaged';
        } elseif ($raw === null || $raw === false || $raw === '') {
            $status = 'missing';
        }
        return [
            'status' => $status,
            'stored_version' => $stored_version,
            'expected_version' => self::SCHEMA_VERSION,
            'physical' => $physical,
        ];
    }

    /**
     * Return the stored schema contract without physical table inspection.
     *
     * Ordinary Health and status views use this option-only path. Missing-table
     * reads already fail closed and schedule maintenance; repeated SHOW probes
     * belong only to explicit diagnose and repair operations.
     *
     * @return array{status:string,stored_version:int,expected_version:int,physical:array<string,mixed>}
     */
    private static function stored_schema_status(): array
    {
        $raw = self::get_option(self::SCHEMA_VERSION_OPTION, null);
        $stored_version = self::schema_version_from_option($raw);
        $status = self::option_matches_schema_version($raw)
            ? 'current'
            : (($raw === null || $raw === false || $raw === '') ? 'missing' : 'stale');

        return [
            'status' => $status,
            'stored_version' => $stored_version,
            'expected_version' => self::SCHEMA_VERSION,
            'physical' => [],
        ];
    }

    /**
     * Report whether FTS may replace a WordPress search query in this request.
     *
     * Normal readiness is deliberately logical and option-only. Physical
     * verification belongs to activation, repair, Health, and migration; a
     * missing/damaged table encountered during search trips the existing
     * fail-closed boundary and marks FTS unhealthy without adding dozens of
     * schema statements to every ordinary request.
     *
     * @param bool $detect_profile_drift Verify the current analyzer profile
     *                                   before an actual search. Read-only
     *                                   operator surfaces pass false so merely
     *                                   viewing status cannot enqueue work.
     * @return array{ready:bool,reason:string,ready_incarnation:string,initial_index_status:string,initial_index_started_at:string,initial_index_completed_at:string,schema_status:string,physical_schema_checked:bool,physical_schema_usable:bool}
     */
    public static function search_takeover_status(bool $detect_profile_drift = true): array
    {
        $actual_search = $detect_profile_drift;
        $cache_key = self::search_takeover_cache_key()
            . ($detect_profile_drift ? ':profile-verified' : ':read-only');
        if (isset(self::$search_takeover_status_cache[$cache_key])) {
            return self::$search_takeover_status_cache[$cache_key];
        }

        try {
            $schema_option = self::get_option(self::SCHEMA_VERSION_OPTION, null);
            $schema_current = self::option_matches_schema_version($schema_option);
            $desired_incarnation = self::readiness_incarnation();
            $ready_incarnation = self::search_ready_incarnation();
            $ready_profile = self::search_ready_profile_hash();
            $health = self::index_health_state();
            $initial_status = self::sanitize_initial_index_status($health['initial_index_status'] ?? '');
            $target_profile = self::sanitize_index_profile_hash($health['index_profile_hash'] ?? '');
            $accepted_profile = self::sanitize_index_profile_hash($health['accepted_index_profile_hash'] ?? '');
            $completed_profile = self::sanitize_index_profile_hash(
                $health['reconciliation_scope_completed_profile_hash'] ?? ''
            );
            $status = [
                'ready' => false,
                'reason' => 'schema_not_current',
                'ready_incarnation' => '',
                'ready_profile_hash' => '',
                'initial_index_status' => $initial_status,
                'initial_index_started_at' => self::sanitize_index_timestamp($health['initial_index_started_at'] ?? ''),
                'initial_index_completed_at' => self::sanitize_index_timestamp($health['initial_index_completed_at'] ?? ''),
                'schema_status' => $schema_current ? 'current' : 'stale',
                'physical_schema_checked' => false,
                'physical_schema_usable' => false,
            ];

            if (!$schema_current) {
                self::$search_takeover_status_cache[$cache_key] = $status;
                return $status;
            }

            if ($detect_profile_drift) {
                // A real search validates the live analyzer before judging an
                // older capability tuple. Otherwise legacy/malformed profile
                // provenance could return early and never enqueue its rebuild.
                self::detect_index_profile_drift();
                $detect_profile_drift = false;
                $health = self::index_health_state();
                $initial_status = self::sanitize_initial_index_status($health['initial_index_status'] ?? '');
                $status['initial_index_status'] = $initial_status;
                $status['initial_index_started_at'] = self::sanitize_index_timestamp($health['initial_index_started_at'] ?? '');
                $status['initial_index_completed_at'] = self::sanitize_index_timestamp($health['initial_index_completed_at'] ?? '');
                $desired_incarnation = self::readiness_incarnation();
                $ready_incarnation = self::search_ready_incarnation();
                $ready_profile = self::search_ready_profile_hash();
                $target_profile = self::sanitize_index_profile_hash($health['index_profile_hash'] ?? '');
                $accepted_profile = self::sanitize_index_profile_hash($health['accepted_index_profile_hash'] ?? '');
                $completed_profile = self::sanitize_index_profile_hash(
                    $health['reconciliation_scope_completed_profile_hash'] ?? ''
                );
            }

            if (
                $desired_incarnation === ''
                || $ready_incarnation === ''
                || $ready_profile === ''
                || !hash_equals($desired_incarnation, $ready_incarnation)
                || $initial_status !== self::INITIAL_INDEX_STATUS_READY
                || $target_profile === ''
                || $accepted_profile === ''
                || $completed_profile === ''
                || !empty($health['foreground_owner_guard_blocked'])
                || !hash_equals($ready_profile, $target_profile)
                || !hash_equals($ready_profile, $accepted_profile)
                || !hash_equals($ready_profile, $completed_profile)
            ) {
                if (
                    $actual_search
                    && $desired_incarnation !== ''
                    && $initial_status === self::INITIAL_INDEX_STATUS_READY
                    && $target_profile !== ''
                    && $accepted_profile !== ''
                    && $completed_profile !== ''
                    && hash_equals($target_profile, $accepted_profile)
                    && hash_equals($target_profile, $completed_profile)
                    && self::readiness_completion_matches($health)
                    && empty($health['global_visibility_fence_active'])
                    && empty($health['search_runtime_failure_latched'])
                    && empty($health['foreground_owner_guard_blocked'])
                    && ($health['status'] ?? '') !== 'unhealthy'
                ) {
                    // Recovery for the only crash window after health CAS and
                    // before capability publication. A real search restores a
                    // lost one-shot verifier; read-only status stays inert.
                    self::schedule_schema_provisioning(1);
                }
                $status['reason'] = (
                    ($health['status'] ?? '') === 'unhealthy'
                    || !empty($health['search_runtime_failure_latched'])
                    || !empty($health['foreground_owner_guard_blocked'])
                )
                    ? 'index_reconciling_or_unhealthy'
                    : ($initial_status !== self::INITIAL_INDEX_STATUS_READY
                        ? 'initial_index_pending'
                        : ($ready_incarnation === ''
                            ? 'search_ready_capability_missing'
                            : 'search_ready_capability_stale'));
                self::$search_takeover_status_cache[$cache_key] = $status;
                return $status;
            }

            $status['ready'] = true;
            $status['reason'] = 'ready';
            $status['ready_incarnation'] = $ready_incarnation;
            $status['ready_profile_hash'] = $ready_profile;
            self::$search_takeover_status_cache[$cache_key] = $status;

            return $status;
        } catch (Throwable) {
            $status = [
                'ready' => false,
                'reason' => 'readiness_check_failed',
                'ready_incarnation' => '',
                'ready_profile_hash' => '',
                'initial_index_status' => self::INITIAL_INDEX_STATUS_PENDING,
                'initial_index_started_at' => '',
                'initial_index_completed_at' => '',
                'schema_status' => 'unknown',
                'physical_schema_checked' => false,
                'physical_schema_usable' => false,
            ];
            self::$search_takeover_status_cache[$cache_key] = $status;

            return $status;
        }
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
            'timing_relation' => 'unavailable',
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
                'timing_relation' => 'unavailable',
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
                'timing_relation' => 'unavailable',
                'entries' => [],
                'more' => false,
                'reason' => self::debug_truncate_text('SQL query capture became unavailable before trace finish: ' . $snapshot['reason']),
            ];
        }

        $start_index = max(0, (int) $capture['start_index']);
        $queries = $snapshot['queries'];
        $captured_count = max(0, count($queries) - min($start_index, count($queries)));
        $entries = [];
        $total_time_ms = 0.0;
        $has_timing = false;

        $inspected_count = min($captured_count, self::DEBUG_MAX_SQL_QUERIES);
        for ($offset = 0; $offset < $inspected_count; $offset++) {
            $query_index = $start_index + $offset;
            // WordPress records SAVEQUERIES as a zero-based list. Fixed direct
            // offsets avoid walking an arbitrarily noisy request log merely to
            // render this plugin's bounded debug window.
            if (!array_key_exists($query_index, $queries)) {
                continue;
            }
            $query_entry = $queries[$query_index];
            $entry = self::debug_sql_query_entry($query_entry);
            if ($entry === null) {
                continue;
            }

            if ($entry['time_ms'] !== null) {
                $has_timing = true;
                $total_time_ms += $entry['time_ms'];
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
            'timing_relation' => $captured_count > $inspected_count ? 'partial' : 'complete',
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
            'queries' => $queries,
            'reason' => '',
        ];
    }

    /**
     * @return array{sql:string,time_ms:?float}|null
     */
    private static function debug_sql_query_entry(mixed $entry): ?array
    {
        $sql = null;

        if (is_string($entry)) {
            $sql = $entry;
        } elseif (is_array($entry)) {
            foreach ([0, 'query', 'sql'] as $key) {
                if (isset($entry[$key]) && is_scalar($entry[$key])) {
                    $sql = (string) $entry[$key];
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
        }

        if (!is_string($sql) || $sql === '' || strspn($sql, " \t\r\n\f\v") === strlen($sql)) {
            return null;
        }

        return [
            'sql' => $sql,
            'time_ms' => self::debug_sql_query_time_ms($entry),
        ];
    }

    private static function debug_sql_query_time_ms(mixed $entry): ?float
    {
        if (is_array($entry)) {
            foreach ([1, 'elapsed', 'time', 'duration'] as $key) {
                if (isset($entry[$key]) && is_numeric($entry[$key])) {
                    return max(0.0, (float) $entry[$key] * 1000.0);
                }
            }
        } elseif (is_object($entry)) {
            foreach (['elapsed', 'time', 'duration'] as $property) {
                if (isset($entry->{$property}) && is_numeric($entry->{$property})) {
                    return max(0.0, (float) $entry->{$property} * 1000.0);
                }
            }
        }

        return null;
    }

    private static function debug_sql_query_entry_summary(string $sql): string
    {
        $scan = self::debug_scan_sql_summary($sql);
        $redacted = self::debug_truncate_text(
            (string) $scan['preview'],
            self::DEBUG_MAX_SQL_SUMMARY_BYTES
        );
        if ($redacted === '') {
            return '';
        }

        $verb = (string) $scan['verb'];
        $tables = is_array($scan['tables']) ? $scan['tables'] : [];
        $prefix = trim($verb . ($tables !== [] ? ' ' . implode('|', $tables) : ''));

        return self::debug_truncate_text(
            ($prefix !== '' ? $prefix . ': ' : '') . $redacted,
            self::DEBUG_MAX_SQL_SUMMARY_BYTES
        );
    }

    /**
     * Read one SQL statement without materializing token or match arrays.
     *
     * Query diagnostics need only a redacted preview, the leading verb, and a
     * bounded set of relation names. The byte-wise lexer skips quoted values
     * and comments while continuing past the preview boundary, so a long
     * literal cannot hide later JOIN targets or allocate another copy of the
     * statement.
     *
     * @return array{preview:string,verb:string,tables:string[]}
     */
    private static function debug_scan_sql_summary(string $sql): array
    {
        $preview = '';
        $preview_limit = self::DEBUG_MAX_SQL_SUMMARY_BYTES + 1;
        $pending_space = false;
        $verb = '';
        $tables = [];
        $expect_relation = '';
        $table_if_state = 0;
        $candidate = null;
        $qualification_pending = false;
        $relation_list_active = false;
        $word_characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_$';

        $append_preview = static function (string $fragment) use (&$preview, &$pending_space, $preview_limit): void {
            if ($fragment === '' || strlen($preview) >= $preview_limit) {
                $pending_space = false;
                return;
            }
            if ($pending_space && $preview !== '') {
                $fragment = ' ' . $fragment;
            }
            $pending_space = false;
            $remaining = $preview_limit - strlen($preview);
            $preview .= strlen($fragment) <= $remaining
                ? $fragment
                : substr($fragment, 0, $remaining);
        };

        $consume_token = static function (string $kind, string $value) use (
            &$verb,
            &$tables,
            &$expect_relation,
            &$table_if_state,
            &$candidate,
            &$qualification_pending,
            &$relation_list_active
        ): void {
            while (true) {
                $is_identifier = $kind === 'word' || $kind === 'identifier';
                $upper = $kind === 'word' ? strtoupper($value) : '';
                if ($verb === '' && $kind === 'word') {
                    $verb = self::debug_truncate_text($upper, 24);
                }

                if ($candidate !== null) {
                    if ($kind === 'symbol' && $value === '.') {
                        $qualification_pending = true;
                        return;
                    }
                    if ($qualification_pending && $is_identifier) {
                        $candidate = $value;
                        $qualification_pending = false;
                        return;
                    }

                    $table = self::debug_truncate_text((string) $candidate, 80);
                    if ($table !== '' && count($tables) < self::DEBUG_MAX_LIST_ITEMS) {
                        $tables[$table] = true;
                    }
                    $candidate = null;
                    $qualification_pending = false;
                    // The token that terminated the candidate may itself be a
                    // JOIN/FROM keyword, so process it once more from idle.
                    continue;
                }

                if ($expect_relation !== '') {
                    if ($expect_relation === 'TABLE') {
                        if ($table_if_state === 0 && $kind === 'word' && $upper === 'IF') {
                            $table_if_state = 1;
                            return;
                        }
                        if ($table_if_state === 1 && $kind === 'word' && $upper === 'NOT') {
                            $table_if_state = 2;
                            return;
                        }
                        if (
                            ($table_if_state === 1 || $table_if_state === 2)
                            && $kind === 'word'
                            && $upper === 'EXISTS'
                        ) {
                            $table_if_state = 3;
                            return;
                        }
                        if ($table_if_state === 1 || $table_if_state === 2) {
                            $expect_relation = '';
                            $table_if_state = 0;
                            continue;
                        }
                    }
                    if (
                        $expect_relation === 'UPDATE'
                        && $kind === 'word'
                        && in_array($upper, ['LOW_PRIORITY', 'IGNORE'], true)
                    ) {
                        return;
                    }
                    if ($is_identifier) {
                        $candidate = $value;
                        $expect_relation = '';
                        $table_if_state = 0;
                        return;
                    }
                    $expect_relation = '';
                    $table_if_state = 0;
                    continue;
                }

                if (
                    $kind === 'word'
                    && in_array($upper, ['FROM', 'JOIN', 'STRAIGHT_JOIN', 'INTO', 'UPDATE', 'TABLE'], true)
                ) {
                    $expect_relation = $upper;
                    $table_if_state = 0;
                    $relation_list_active = true;
                    return;
                }
                if ($kind === 'symbol' && $value === ',' && $relation_list_active) {
                    $expect_relation = 'LIST';
                    return;
                }
                if (
                    ($kind === 'symbol' && $value === '(')
                    || ($kind === 'word' && in_array($upper, ['WHERE', 'ON', 'SET', 'VALUES', 'GROUP', 'ORDER', 'HAVING', 'LIMIT', 'RETURNING'], true))
                ) {
                    $relation_list_active = false;
                }
                return;
            }
        };

        $length = strlen($sql);
        for ($offset = 0; $offset < $length;) {
            $space_bytes = strspn($sql, " \t\r\n\f\v", $offset);
            if ($space_bytes > 0) {
                $pending_space = $preview !== '';
                $offset += $space_bytes;
                continue;
            }

            if ($sql[$offset] === '/' && $offset + 1 < $length && $sql[$offset + 1] === '*') {
                $append_preview('/*?*/');
                $end = strpos($sql, '*/', $offset + 2);
                $offset = $end === false ? $length : $end + 2;
                continue;
            }
            if ($sql[$offset] === '#') {
                $append_preview('# ?');
                $end = strcspn($sql, "\r\n", $offset + 1);
                $offset = min($length, $offset + 1 + $end);
                continue;
            }
            if (
                $sql[$offset] === '-'
                && $offset + 1 < $length
                && $sql[$offset + 1] === '-'
                && (
                    $offset + 2 >= $length
                    || ord($sql[$offset + 2]) <= 32
                )
            ) {
                $append_preview('-- ?');
                $end = strcspn($sql, "\r\n", $offset + 2);
                $offset = min($length, $offset + 2 + $end);
                continue;
            }

            if (
                $sql[$offset] === '"'
                && ($expect_relation !== '' || $qualification_pending)
            ) {
                $identifier = '';
                $preview_identifier = '"';
                $offset++;
                while ($offset < $length) {
                    if ($sql[$offset] === '"') {
                        if ($offset + 1 < $length && $sql[$offset + 1] === '"') {
                            if (strlen($identifier) < 81) {
                                $identifier .= '"';
                            }
                            if (strlen($preview_identifier) < $preview_limit) {
                                $preview_identifier .= '""';
                            }
                            $offset += 2;
                            continue;
                        }
                        if (strlen($preview_identifier) < $preview_limit) {
                            $preview_identifier .= '"';
                        }
                        $offset++;
                        break;
                    }
                    if (strlen($identifier) < 81) {
                        $identifier .= $sql[$offset];
                    }
                    if (strlen($preview_identifier) < $preview_limit) {
                        $preview_identifier .= $sql[$offset];
                    }
                    $offset++;
                }
                $append_preview($preview_identifier);
                $consume_token('identifier', $identifier);
                continue;
            }

            if (
                $sql[$offset] === '['
                && ($expect_relation !== '' || $qualification_pending)
            ) {
                $identifier = '';
                $preview_identifier = '[';
                $offset++;
                while ($offset < $length) {
                    if ($sql[$offset] === ']') {
                        if ($offset + 1 < $length && $sql[$offset + 1] === ']') {
                            if (strlen($identifier) < 81) {
                                $identifier .= ']';
                            }
                            if (strlen($preview_identifier) < $preview_limit) {
                                $preview_identifier .= ']]';
                            }
                            $offset += 2;
                            continue;
                        }
                        if (strlen($preview_identifier) < $preview_limit) {
                            $preview_identifier .= ']';
                        }
                        $offset++;
                        break;
                    }
                    if (strlen($identifier) < 81) {
                        $identifier .= $sql[$offset];
                    }
                    if (strlen($preview_identifier) < $preview_limit) {
                        $preview_identifier .= $sql[$offset];
                    }
                    $offset++;
                }
                $append_preview($preview_identifier);
                $consume_token('identifier', $identifier);
                continue;
            }

            if ($sql[$offset] === "'" || $sql[$offset] === '"') {
                $quote = $sql[$offset];
                $append_preview('?');
                $offset++;
                while ($offset < $length) {
                    if ($sql[$offset] === '\\') {
                        $offset = min($length, $offset + 2);
                        continue;
                    }
                    if ($sql[$offset] === $quote) {
                        if ($offset + 1 < $length && $sql[$offset + 1] === $quote) {
                            $offset += 2;
                            continue;
                        }
                        $offset++;
                        break;
                    }
                    $offset++;
                }
                continue;
            }

            if ($sql[$offset] === '`') {
                $identifier = '';
                $preview_identifier = '`';
                $offset++;
                while ($offset < $length) {
                    if ($sql[$offset] === '`') {
                        if ($offset + 1 < $length && $sql[$offset + 1] === '`') {
                            if (strlen($identifier) < 81) {
                                $identifier .= '`';
                            }
                            if (strlen($preview_identifier) < $preview_limit) {
                                $preview_identifier .= '``';
                            }
                            $offset += 2;
                            continue;
                        }
                        if (strlen($preview_identifier) < $preview_limit) {
                            $preview_identifier .= '`';
                        }
                        $offset++;
                        break;
                    }
                    if (strlen($identifier) < 81) {
                        $identifier .= $sql[$offset];
                    }
                    if (strlen($preview_identifier) < $preview_limit) {
                        $preview_identifier .= $sql[$offset];
                    }
                    $offset++;
                }
                $append_preview($preview_identifier);
                $consume_token('identifier', $identifier);
                continue;
            }

            $word_bytes = strspn($sql, $word_characters, $offset);
            if ($word_bytes > 0) {
                $bounded_word = substr($sql, $offset, min($word_bytes, 242));
                $upper = $word_bytes <= 16 ? strtoupper($bounded_word) : '';
                $next_offset = $offset + $word_bytes;
                if (
                    in_array($upper, ['_BINARY', 'B', 'N', 'X'], true)
                    && $next_offset < $length
                    && $sql[$next_offset] === "'"
                ) {
                    $append_preview('?');
                    $offset = $next_offset + 1;
                    while ($offset < $length) {
                        if ($sql[$offset] === '\\') {
                            $offset = min($length, $offset + 2);
                            continue;
                        }
                        if ($sql[$offset] === "'") {
                            if ($offset + 1 < $length && $sql[$offset + 1] === "'") {
                                $offset += 2;
                                continue;
                            }
                            $offset++;
                            break;
                        }
                        $offset++;
                    }
                    continue;
                }
                $starts_with_digit = $bounded_word !== ''
                    && $bounded_word[0] >= '0'
                    && $bounded_word[0] <= '9';
                $append_preview($starts_with_digit ? '?' : $bounded_word);
                if (!$starts_with_digit) {
                    $consume_token('word', $word_bytes <= 81 ? $bounded_word : substr($bounded_word, 0, 81));
                }
                $offset = $next_offset;
                continue;
            }

            $append_preview($sql[$offset]);
            $consume_token('symbol', $sql[$offset]);
            $offset++;
        }

        if ($candidate !== null) {
            $table = self::debug_truncate_text((string) $candidate, 80);
            if ($table !== '' && count($tables) < self::DEBUG_MAX_LIST_ITEMS) {
                $tables[$table] = true;
            }
        }

        return [
            'preview' => $preview,
            'verb' => $verb,
            'tables' => array_keys($tables),
        ];
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

    private static function debug_set_query_language(int $trace_id, string $query_lang): void
    {
        if (!isset(self::$debug_traces[$trace_id])) {
            return;
        }

        $query_lang = WP_FTS_TermNamespace::canonicalize_lang($query_lang);
        self::$debug_traces[$trace_id]['query_lang'] = $query_lang;
        // Strict analyzer-pack status hashes every configured shard. That work
        // belongs on explicit operator/health surfaces, never on a search just
        // because the visitor enabled WP_DEBUG or can administer the site.
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
            'ranked_page_rows' => 0,
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
        foreach (['query_lang', 'settings', 'counts', 'timings_ms', 'analyzer_pack_status', 'search_hook_pipeline', 'search_final_ownership', 'search_explain', 'notes'] as $key) {
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
        $summary = [
            'public_site_search' => !empty($settings['replace_frontend_search']) ? 'enabled' : 'disabled',
            'admin_posts_search' => !empty($settings['replace_admin_post_search']) ? 'enabled' : 'disabled',
            'provider_compatibility' => self::search_provider_compatibility_debug_value((string) ($settings['search_provider_compatibility'] ?? self::SEARCH_PROVIDER_COMPATIBILITY_PREFER_FTS)),
            'match_mode' => (string) ($settings['match_mode'] ?? 'OR'),
            'prefix_matching' => !empty($settings['prefix_matching']) ? 'enabled' : 'disabled',
            'prefix_min_length' => self::sanitize_prefix_min_length($settings['prefix_min_length'] ?? self::PREFIX_MIN_LENGTH_DEFAULT),
            'highlight' => !empty($settings['highlight']) ? 'enabled' : 'disabled',
            'snippet_length' => (int) ($settings['snippet_length'] ?? self::FRONTEND_SNIPPET_LENGTH),
            'result_limit' => (int) ($settings['result_limit'] ?? 10),
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
            if (is_scalar($trace['bailout_reason'] ?? null) && (string) $trace['bailout_reason'] !== '') {
                self::render_debug_row('Bailout reason', (string) $trace['bailout_reason']);
            }
            self::render_debug_row('Settings', self::debug_assoc_summary($trace['settings'] ?? []));
            self::render_debug_row('Search hook pipeline', self::debug_search_hook_pipeline_summary($trace['search_hook_pipeline'] ?? []));
            self::render_debug_row('Search final ownership', self::debug_search_final_ownership_summary($trace['search_final_ownership'] ?? []));
            $search_explain = is_array($trace['search_explain'] ?? null) ? $trace['search_explain'] : [];
            self::render_debug_row('Storage backend', self::debug_scalar_summary($search_explain['storage'] ?? ''));
            self::render_debug_row('Query plan', self::debug_query_plan_summary($search_explain));
            self::render_debug_row('Recency boost', self::debug_assoc_summary($search_explain['recency_boost'] ?? []));
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
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

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
            $timing_label = ($value['timing_relation'] ?? '') === 'partial' ? 'shown_time' : 'total_time';
            $parts[] = $timing_label . '=' . number_format((float) $value['total_time_ms'], 3, '.', '') . 'ms';
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
        foreach (['logical_group_count', 'resolved_alternatives', 'anchor_group', 'prefix_range', 'prefix_strategy', 'query_statements', 'interactive_total', 'canonical_page_bytes'] as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }
            $summary = $value[$key] === null ? 'none' : self::debug_scalar_summary($value[$key]);
            if ($summary !== '') {
                $parts[] = $key . '=' . $summary;
            }
        }

        return self::debug_truncate_text(implode(', ', $parts), 800);
    }

    /**
     * Register the public REST search endpoint only after explicit opt-in.
     */
    public static function register_rest_routes(): void
    {
        if (!function_exists('register_rest_route') || empty(self::settings()['rest_api_enabled'])) {
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
                'cursor' => ['required' => false],
                'direction' => ['required' => false],
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
                $post_ids
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
     * @param array{status:string,stored_version:int,expected_version:int,skipped_locked?:bool,lock_active?:bool} $schema
     * @return array{0:string,1:string}
     */
    private static function schema_repair_notice(array $schema): array
    {
        if (!empty($schema['skipped_locked'])) {
            return [
                'warning',
                'Another index writer is already running; no schema repair was performed. Retry after the active writer finishes.',
            ];
        }

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

        if ($processed > 0 && !empty($summary['cleanup_pending'])) {
            return [
                'success',
                sprintf(
                    'Indexed %d %s. One bounded dictionary cleanup pass remains and has been scheduled.',
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
        $pendingIncarnation = '';
        try {
            $candidateOptions = self::save_bundled_runtime_lemma_pack_selection(
                self::selected_bundled_runtime_lemma_pack_languages($manifests),
                $manifests,
                false
            );
            $storedOptions = self::get_option(self::ANALYZER_OPTIONS_OPTION, []);
            if ($candidateOptions != (is_array($storedOptions) ? $storedOptions : [])) {
                $previousHash = self::sanitize_index_profile_hash($previousProfile['hash'] ?? '');
                $pendingIncarnation = self::mark_initial_index_pending(true, $previousHash);
                self::set_option(self::ANALYZER_OPTIONS_OPTION, $candidateOptions);
            }
        } catch (Throwable) {
            if ($pendingIncarnation !== '') {
                try {
                    $previousHash = self::sanitize_index_profile_hash($previousProfile['hash'] ?? '');
                    self::enqueue_scope_reconciliation('index-profile', [
                        'reason' => 'analyzer_option_write_recovery',
                        'profile_hash' => $previousHash,
                    ], true, '', 0, $pendingIncarnation);
                } catch (Throwable) {
                    self::schedule_schema_provisioning(1);
                }
            }
            return [['error', 'Analyzer pack verification failed. Settings were not changed.']];
        }
        $currentProfile = self::current_index_profile();
        $reasons = self::index_profile_change_reasons($previousProfile, $currentProfile);
        if ($reasons !== []) {
            self::enqueue_index_profile_reconciliation($reasons, $previousProfile, $currentProfile);
        } elseif ($pendingIncarnation !== '') {
            $currentHash = self::sanitize_index_profile_hash($currentProfile['hash'] ?? '');
            self::enqueue_scope_reconciliation('index-profile', [
                'reason' => 'analyzer_options_verified',
                'profile_hash' => $currentHash,
            ], true, '', 0, $pendingIncarnation);
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
        foreach (self::legacy_sandbox_demo_query_posts() as $post) {
            $post_id = isset($post->ID) ? (int) $post->ID : 0;
            if ($post_id <= 0 || isset($candidates[$post_id]) || !self::is_legacy_sandbox_demo_cleanup_target($post)) {
                continue;
            }
            $candidates[$post_id] = $post;
        }

        ksort($candidates, SORT_NUMERIC);

        return $candidates;
    }

    /**
     * @return object[]
     */
    private static function legacy_sandbox_demo_query_posts(): array
    {
        if (!function_exists('get_posts')) {
            return [];
        }

        $signatures = self::legacy_sandbox_demo_post_signatures();
        $slugs = array_map(static fn(array $signature): string => $signature['slug'], $signatures);
        $limit = count($signatures);
        $posts = get_posts([
            'post_type' => 'any',
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'numberposts' => $limit,
            'post_name__in' => $slugs,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'suppress_filters' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);
        if (!is_array($posts)) {
            return [];
        }

        return array_values(array_filter(array_slice($posts, 0, $limit), 'is_object'));
    }

    private static function is_legacy_sandbox_demo_cleanup_target(object $post): bool
    {
        if (self::post_status_from_object($post) === 'trash') {
            return false;
        }

        $title = isset($post->post_title) && is_scalar($post->post_title) ? (string) $post->post_title : '';
        $slug = isset($post->post_name) && is_scalar($post->post_name) ? (string) $post->post_name : '';
        foreach (self::legacy_sandbox_demo_post_signatures() as $signature) {
            if ($title === $signature['title'] && $slug === $signature['slug']) {
                return true;
            }
        }

        return false;
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

            // wp_trash_post() runs the normal canonical post lifecycle. Its
            // post-save hook publishes one durable dirty generation; doing a
            // second direct index delete here would duplicate work and bypass
            // the bounded replacement writer.
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
        $schema = self::stored_schema_status();
        $takeover = self::search_takeover_status(false);
        $lock = self::index_lock_status();
        $pending_count = max(0, (int) ($health['pending_queue_count'] ?? 0));
        $remaining_summary = !empty($health['reconciliation_active'])
            ? 'Durable reconciliation active'
            : ($pending_count > 0
                ? 'Durable work is pending'
                : (!empty($takeover['ready']) ? 'No durable work pending' : 'Not scanned in normal Health view'));
        $queue_processor_schedule = self::queue_processor_schedule_status($health);
        $cron_runner = self::cron_runner_status($queue_processor_schedule);
        $work_status = [
            'post_count' => max(0, (int) ($health['pending_post_work_count'] ?? 0)),
            'post_count_relation' => self::bounded_count_relation($health['pending_post_work_count_relation'] ?? ''),
            'scope_count' => max(0, (int) ($health['pending_scope_work_count'] ?? 0)),
            'scope_count_relation' => self::bounded_count_relation($health['pending_scope_work_count_relation'] ?? ''),
            'scope_cursor_post_id' => isset($health['reconciliation_cursor_post_id'])
                ? max(0, (int) $health['reconciliation_cursor_post_id'])
                : null,
        ];

        echo '<h2>Search health</h2>';
        echo '<p class="wp-fts-health-copy">The plugin builds the search index in small batches so large sites stay responsive. WP-Cron continues indexing a small amount in the background. Use the button below to index the next larger batch now; large sites may need several batches, and that is intentional.</p>';

        echo '<h3>Status summary</h3>';
        echo '<table class="widefat striped wp-fts-health-table"><tbody>';
        self::render_health_status_row('Schema status', self::schema_status_label((string) $schema['status']));
        self::render_health_status_row('Stored schema version', (string) max(0, (int) $schema['stored_version']));
        self::render_health_status_row('Expected schema version', (string) max(0, (int) $schema['expected_version']));
        self::render_health_status_row('Search replacement readiness', self::search_takeover_status_label($takeover));
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
        self::render_health_status_row('Eligible content', 'Not scanned in normal Health view');
        self::render_health_status_row('Indexed', 'Not scanned in normal Health view');
        self::render_health_status_row(
            'Waiting in the update queue',
            self::bounded_count_summary($pending_count, $health['pending_queue_count_relation'] ?? '')
        );
        self::render_health_status_row('Remaining to index', $remaining_summary);
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

        echo '<h3>Durable reconciliation</h3>';
        echo '<table class="widefat striped wp-fts-health-table"><tbody>';
        self::render_health_status_row(
            'Pending scope jobs',
            self::bounded_count_summary($work_status['scope_count'], $work_status['scope_count_relation'])
        );
        self::render_health_status_row(
            'Scope cursor',
            $work_status['scope_cursor_post_id'] === null
                ? 'Unknown (scope count is capped)'
                : 'Post ID ' . $work_status['scope_cursor_post_id']
        );
        self::render_health_status_row(
            'Pending post generations',
            self::bounded_count_summary($work_status['post_count'], $work_status['post_count_relation'])
        );
        self::render_health_status_row('Reconciliation active', !empty($health['reconciliation_active']) ? 'Yes' : 'No');
        self::render_health_status_row('Profile reconciliation pending', !empty($health['profile_reconciliation_pending']) ? 'Yes' : 'No');
        self::render_health_status_row('Current index profile', self::index_profile_hash_summary($health['index_profile_hash'] ?? ''));
        self::render_health_status_row('Last accepted index profile', self::index_profile_hash_summary($health['accepted_index_profile_hash'] ?? ''));
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
        echo '<p class="wp-fts-health-copy">Run one safe indexing pass now. You can use it again while pending updates or durable reconciliation are shown above.</p>';
        echo '<form method="post" action="' . self::esc_url(self::admin_page_url(self::ADMIN_HEALTH_TAB)) . '">';
        self::render_health_nonce_field();
        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_HEALTH_ACTION_FIELD) . '" value="' . self::esc_attr(self::ADMIN_HEALTH_MANUAL_BATCH_ACTION) . '">';
        echo '<p><button type="submit" class="button button-primary">Index the next batch now</button></p>';
        echo '</form>';
    }

    /** Format a bounded diagnostic count without presenting a lower bound as exact. */
    private static function bounded_count_summary(int $count, mixed $relation): string
    {
        $count = max(0, $count);

        return self::bounded_count_relation($relation) === 'at_least'
            ? 'At least ' . $count
            : (string) $count;
    }

    private static function render_health_support_snapshot_controls(): void
    {
        echo '<h3>Support snapshot</h3>';
        echo '<p class="wp-fts-health-copy">Generate a bounded, redacted JSON snapshot for support handoff. This explicit diagnostic checks physical schema without scanning the content corpus. It is read-only and does not run searches, indexing, schema repair, queue scheduling, or provider API calls.</p>';
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
            'damaged' => 'Damaged',
            'unavailable' => 'Unavailable',
            'missing' => 'Missing',
            'stale' => 'Stale',
            default => 'Unknown',
        };
    }

    /**
     * @param array<string,mixed> $status
     */
    private static function search_takeover_status_label(array $status): string
    {
        return match ((string) ($status['reason'] ?? '')) {
            'ready' => 'Ready; FTS may replace configured WordPress searches',
            'initial_index_pending' => 'Waiting for the initial index; WordPress search remains active',
            'schema_not_current' => 'Waiting for current schema; WordPress search remains active',
            'physical_schema_unusable' => 'Index tables are unavailable; WordPress search remains active',
            default => 'Readiness check unavailable; WordPress search remains active',
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
            '%d total (%d waiting updates, %d remaining content, %d failed)',
            max(0, (int) ($health['last_batch_processed'] ?? 0)),
            max(0, (int) ($health['last_batch_queue_processed'] ?? 0)),
            max(0, (int) ($health['last_batch_backfill_processed'] ?? 0)),
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
            '%d tracked (%d retryable, %d waiting, %d rejected)',
            $total,
            max(0, (int) ($recovery['retryable_count'] ?? 0)),
            max(0, (int) ($recovery['backoff_count'] ?? 0)),
            max(0, (int) ($recovery['rejected_count'] ?? 0))
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
            'scheduled_at_availability' => 'Scheduled WP-Cron when deferred work becomes available.',
            'scheduled_after_lock_skip' => 'Scheduled another WP-Cron run after lock contention.',
            'successor_schedule_failed' => 'WordPress could not schedule the required follow-up; durable work remains queued.',
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
            'successor_schedule_failed' => 'Stopped because WordPress could not persist the required follow-up event.',
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

        self::render_settings_section_heading('Public REST search', 'The anonymous search endpoint is absent unless an operator deliberately enables it. It uses the same fixed-shape relational search as the other plugin surfaces.');
        echo '<table class="form-table" role="presentation"><tbody>';
        self::render_settings_single_checkbox_row(
            'rest_api_enabled',
            'REST endpoint',
            'Register the public wp-fts/v1/search endpoint',
            !empty($settings['rest_api_enabled']),
            'Leave this off unless a separate client needs anonymous REST search. Apply traffic rate limits and response caching at the host or CDN; the plugin does not add database-backed hot-path counters or caches.'
        );
        self::render_settings_single_checkbox_row(
            'rest_prefix_matching',
            'REST word beginnings',
            'Allow final-word prefix matching on the REST endpoint',
            !empty($settings['rest_prefix_matching']),
            'This stays off independently of normal site search because one prefix can cover many stored terms. REST clients cannot turn it on per request; one complete indexed range remains inside SQL.'
        );
        echo '</tbody></table>';

        self::render_settings_section_heading('Ranking weights', 'Whole numbers from 1 to 100 are supported. Higher numbers make matches in that field count more strongly. Changed weights affect content when it is reindexed, because weights are stored in the index.');
        echo '<table class="form-table" role="presentation"><tbody>';
        self::render_settings_field_boost_rows($settings);
        self::render_settings_recency_boost_rows($settings);
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
            echo '<input id="' . self::esc_attr($id) . '" type="number" min="' . self::esc_attr((string) self::FIELD_BOOST_MIN) . '" max="' . self::esc_attr((string) self::FIELD_BOOST_MAX) . '" step="1" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[field_boosts][' . self::esc_attr($field) . ']" value="' . self::esc_attr(self::format_field_boost((float) ($boosts[$field] ?? self::FIELD_BOOST_DEFAULTS[$field]))) . '">';
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
        echo '<tr><th scope="row"><label for="wp-fts-settings-prefix-min-length">Shortest word beginning</label></th><td>';
        echo '<input id="wp-fts-settings-prefix-min-length" type="number" min="' . self::esc_attr((string) self::PREFIX_MIN_LENGTH_MIN) . '" max="' . self::esc_attr((string) self::PREFIX_MIN_LENGTH_MAX) . '" step="1" name="' . self::esc_attr(self::SETTINGS_OPTION) . '[prefix_min_length]" value="' . self::esc_attr((string) $min_length) . '">';
        echo '<p class="description">Shorter values make word-beginning matches broader, but they can be slower and add noisier alternatives.</p>';
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
            $state['controls']
        );

        if ($state['search_submitted']) {
            self::render_sandbox_results(
                $state['results'],
                $state['query'],
                $state['selected_language'],
                $state['controls']
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
            'rest_api_enabled' => array_key_exists('rest_api_enabled', $value) ? self::truthy_admin_value($value['rest_api_enabled']) : $defaults['rest_api_enabled'],
            'rest_prefix_matching' => array_key_exists('rest_prefix_matching', $value) ? self::truthy_admin_value($value['rest_prefix_matching']) : $defaults['rest_prefix_matching'],
            'result_limit' => self::clamp_int($value['result_limit'] ?? $defaults['result_limit'], 1, self::MAX_SEARCH_LIMIT),
            'field_boosts' => self::sanitize_field_boosts($value['field_boosts'] ?? []),
            'recency_boost_strength' => self::sanitize_recency_boost_strength($value['recency_boost_strength'] ?? ($value['recency_boost'] ?? $defaults['recency_boost_strength'])),
            'recency_boost_half_life_days' => self::sanitize_recency_boost_half_life($value['recency_boost_half_life_days'] ?? $defaults['recency_boost_half_life_days']),
        ];
    }

    /**
     * Sanitize Settings API saves and enqueue profile reconciliation only for verified admin saves.
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
                self::enqueue_index_profile_reconciliation($reasons, $previousProfile, $currentProfile);
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

        return (float) round(self::clamp_float($boost, self::FIELD_BOOST_MIN, self::FIELD_BOOST_MAX));
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

    /** Validate the removed expansion setting for source-compatible callers only. */
    public static function sanitize_prefix_max_terms(mixed $value): int
    {
        return self::sanitize_prefix_threshold($value, 64, 1, 256);
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

            // An option left behind by an inactive plugin is not an active
            // search provider. Probe family-specific options only after an
            // activation or loaded-runtime signal identifies that family;
            // this also avoids one absent-option query on every fresh status
            // request when Jetpack is not installed.
            if ($signals !== []) {
                foreach (is_array($family['option_signals'] ?? null) ? $family['option_signals'] : [] as $signal) {
                    if (is_array($signal) && self::known_search_provider_option_signal_matches($signal)) {
                        $signals['option'] = true;
                    }
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

        // Network-active plugins cannot exist on a single-site installation.
        // Calling get_site_option() there turns an otherwise in-memory provider
        // advisory into an unnecessary primary-key read of wp_sitemeta/options.
        if (!function_exists('is_multisite') || !is_multisite()) {
            return [];
        }

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
     *   rest_api_enabled:bool,
     *   rest_prefix_matching:bool,
     *   result_limit:int,
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
        $choices = [];
        foreach (self::DEFAULT_SETTINGS['index_post_types'] as $post_type) {
            if (self::is_public_searchable_post_type($post_type)) {
                $choices[$post_type] = true;
            }
        }
        foreach (self::public_searchable_post_types() as $post_type) {
            $choices[$post_type] = true;
            if (count($choices) >= self::MAX_SEARCH_SCOPE_VALUES) {
                break;
            }
        }
        $choices = array_keys($choices);
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
            'custom_field_keys' => self::configured_custom_field_keys_for_profile(),
            'indexed_scope' => self::index_profile_scope($settings),
        ];
        $profile['hash'] = self::index_profile_hash($profile);

        return $profile;
    }

    /** @return string[] */
    private static function configured_custom_field_keys_for_profile(): array
    {
        $configured = self::get_option(WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION, []);
        try {
            return (new WP_FTS_PostContentExtractor())->normalize_selected_custom_field_keys($configured);
        } catch (Throwable) {
            // Keep profile computation bounded and deterministic. The worker
            // still surfaces the malformed selection as document-level poison.
            return ['__wp_fts_invalid_custom_field_selection__'];
        }
    }

    private static function runtime_analyzer_index_signature(): string
    {
        try {
            $signature = self::runtime_analyzer()->index_signature();
            if (is_scalar($signature) && trim((string) $signature) !== '') {
                return (string) $signature;
            }
        } catch (WP_FTS_Analyzer_Config_Limit_Exceeded $error) {
            throw $error;
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
        if (($previousProfile['custom_field_keys'] ?? []) !== ($currentProfile['custom_field_keys'] ?? [])) {
            $reasons[] = 'custom_field_selection_changed';
        }
        if (($previousProfile['indexed_scope'] ?? []) !== ($currentProfile['indexed_scope'] ?? [])) {
            $reasons[] = 'indexed_scope_changed';
        }
        if ($reasons === []) {
            $reasons[] = 'index_profile_changed';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param string[] $reasons
     * @param array<string,mixed> $previousProfile
     * @param array<string,mixed> $currentProfile
     */
    private static function enqueue_index_profile_reconciliation(array $reasons, array $previousProfile, array $currentProfile): void
    {
        $reasons = array_values(array_unique(array_filter(
            $reasons,
            static fn(mixed $reason): bool => is_string($reason) && $reason !== ''
        )));
        if ($reasons === []) {
            return;
        }

        $target_profile_hash = self::sanitize_index_profile_hash(
            $currentProfile['hash'] ?? self::index_profile_hash($currentProfile)
        );
        // Revoke publication before the caller can expose the new option
        // value. The scope may briefly wait for Settings API persistence, but
        // no concurrent search can observe H2 configuration with H1 rows.
        $incarnation = self::mark_initial_index_pending(true, $target_profile_hash);
        $state = self::index_health_state();
        $state['index_profile_hash'] = $target_profile_hash;
        if (self::sanitize_index_profile_hash($state['accepted_index_profile_hash'] ?? '') === '') {
            $state['accepted_index_profile_hash'] = self::sanitize_index_profile_hash($previousProfile['hash'] ?? self::index_profile_hash($previousProfile));
        }

        self::set_option(self::INDEX_HEALTH_OPTION, $state);
        self::enqueue_scope_reconciliation('index-profile', [
            'reason' => 'index_profile_changed',
            'reasons' => $reasons,
            'from' => self::sanitize_index_profile_hash($previousProfile['hash'] ?? ''),
            'to' => $target_profile_hash,
            'profile_hash' => $target_profile_hash,
        ], true, '', 0, $incarnation);
    }

    /**
     * @param mixed $value
     * @param string[] $allowed
     * @return string[]
     */
    private static function sanitize_post_type_list(mixed $value, array $allowed): array
    {
        $allowed_map = [];
        $allowed_count = 0;
        foreach ($allowed as $item) {
            if (++$allowed_count > self::MAX_SEARCH_SCOPE_VALUES) {
                break;
            }
            if (!is_scalar($item) || strlen((string) $item) > self::MAX_SEARCH_SCOPE_VALUE_BYTES) {
                continue;
            }
            $item = self::sanitize_key((string) $item);
            if ($item !== '') {
                $allowed_map[$item] = true;
            }
            if (count($allowed_map) >= self::MAX_SEARCH_SCOPE_VALUES) {
                break;
            }
        }
        $post_types = [];
        $raw_count = 0;
        foreach (is_array($value) ? $value : [$value] as $item) {
            if (++$raw_count > self::MAX_SEARCH_SCOPE_VALUES) {
                break;
            }
            if (!is_scalar($item) || strlen((string) $item) > self::MAX_SEARCH_SCOPE_VALUE_BYTES) {
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

    private static function post_language_override(int $post_id, ?object $post = null): ?string
    {
        if ($post !== null && property_exists($post, 'fts_language_override')) {
            $raw = is_scalar($post->fts_language_override) ? (string) $post->fts_language_override : '';
            $language = self::sanitize_post_language_override($raw);

            return $language !== '' ? $language : null;
        }
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
        $post_language_preloaded = property_exists($post, 'fts_language_override')
            && property_exists($post, 'fts_integration_language');
        $site_language = self::site_language();
        $options['default_lang'] ??= $site_language;
        if (!array_key_exists('field_boosts', $options)) {
            $options['field_boosts'] = self::settings_field_boosts(self::settings()['field_boosts'] ?? []);
        }
        $options['render_blocks'] ??= false;

        if (
            $post_language_preloaded
            && WP_FTS_TermNamespace::language_from_options($options, null, ['lang', 'language', 'primary_lang', 'document_lang']) === null
        ) {
            $metadata_language = self::wordpress_post_language($post);
            if ($metadata_language !== null) {
                $options['lang'] = $metadata_language;
                $options['document_lang'] = $metadata_language;
            }
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters(self::POST_INDEX_OPTIONS_FILTER, $options, $post);
            if (is_array($filtered)) {
                $options = $filtered;
            }
        }

        // The batch dependency snapshot is authoritative for this generation,
        // including an absent language override. Preserve that fact through
        // analyzer callbacks so they cannot restore one get_post_meta() query
        // per document after the set-oriented preload.
        if ($post_language_preloaded) {
            $options[self::PRELOADED_POST_LANGUAGE_OPTION] = true;
        }

        return $options;
    }

    /**
     * Resolve deliberate per-post language metadata from WordPress integrations.
     */
    private static function wordpress_post_language(object $post): ?string
    {
        $has_preloaded_override = property_exists($post, 'fts_language_override');
        $has_preloaded_integration = property_exists($post, 'fts_integration_language');
        if (!$has_preloaded_override || !$has_preloaded_integration) {
            return null;
        }

        $override = self::post_language_override(0, $post);
        if ($override !== null) {
            return WP_FTS_TermNamespace::canonicalize_lang($override);
        }

        $integration = is_scalar($post->fts_integration_language)
            ? trim((string) $post->fts_integration_language)
            : '';
        if ($integration !== '') {
            return WP_FTS_TermNamespace::canonicalize_lang($integration);
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
            'total_relation' => 'unknown',
            'has_more' => false,
            'next_cursor' => null,
            'previous_cursor' => null,
            'results' => [],
        ];
    }

    /**
     * @param array<string,mixed> $controls
     * @return array{requested_lang:string,query_lang:string,total:int,results:array<int,array<string,mixed>>}
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
        $search_options = [
            'mode' => $mode,
            'limit' => $limit,
            'include_metadata' => true,
            'include_snippets' => $include_snippets,
            'highlight' => (bool) ($controls['highlight'] ?? $settings['highlight']),
            'prefix_matching' => (bool) ($controls['prefix_matching'] ?? $settings['prefix_matching']),
            'snippet_length' => self::clamp_int(
                $controls['snippet_length'] ?? $settings['snippet_length'],
                self::SETTINGS_SNIPPET_MIN,
                self::SETTINGS_SNIPPET_MAX
            ),
        ] + self::searcher_prefix_threshold_options($settings, $controls);
        foreach (['post_types', 'post_statuses'] as $key) {
            if (isset($controls[$key]) && is_array($controls[$key]) && $controls[$key] !== []) {
                $search_options[$key] = $controls[$key];
            }
        }
        foreach (['date_after', 'date_before'] as $date_key) {
            if (isset($controls[$date_key]) && is_scalar($controls[$date_key]) && trim((string) $controls[$date_key]) !== '') {
                $search_options[$date_key] = (string) $controls[$date_key];
            }
        }
        if ($selected_language === 'site') {
            $search_options['lang'] = self::site_language();
        } elseif ($selected_language !== 'auto') {
            $search_options['lang'] = $selected_language;
        }

        $search_started = microtime(true);
        $payload = $trace_id > 0
            ? self::search_with_explain($query, $search_options)
            : self::search_page($query, $search_options);
        self::debug_add_timing($trace_id, 'storage/search', $search_started);
        self::debug_set_search_explain($trace_id, $payload['explain'] ?? null);

        $results = [];
        foreach (is_array($payload['results'] ?? null) ? $payload['results'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $post_id = max(0, (int) ($row['doc_id'] ?? $row['post_id'] ?? 0));
            if ($post_id <= 0) {
                continue;
            }
            $result = self::sandbox_result_row($row, $post_id, (string) ($payload['query_lang'] ?? ''));
            $results[] = $result;
        }

        $query_language = self::sandbox_resolved_query_language(
            $selected_language,
            is_scalar($payload['query_lang'] ?? null) ? (string) $payload['query_lang'] : '',
            $results
        );
        $has_more = !empty($payload['has_more']);
        self::debug_set_counts($trace_id, [
            'search_batches' => 1,
            'ranked_page_rows' => count($results),
            'result_ids_returned' => count($results),
            'visible_results' => count($results),
        ]);
        self::debug_set_query_language($trace_id, $query_language !== 'auto' ? $query_language : '');
        self::debug_add_notes($trace_id, [
            'FTS sandbox search used the same one-pass relational backend as production.',
            'The interactive total is intentionally unknown.',
        ]);
        self::debug_add_timing($trace_id, 'total', $trace_started);
        self::debug_finish_trace($trace_id, 'ran');

        return [
            'requested_lang' => $selected_language,
            'query_lang' => $query_language,
            'total' => count($results) + ($has_more ? 1 : 0),
            'total_relation' => 'unknown',
            'has_more' => $has_more,
            'next_cursor' => is_scalar($payload['next_cursor'] ?? null) ? (string) $payload['next_cursor'] : null,
            'previous_cursor' => is_scalar($payload['previous_cursor'] ?? null) ? (string) $payload['previous_cursor'] : null,
            'results' => $results,
        ];
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
            self::sanitize_runtime_analyzer_options(self::raw_sandbox_demo_analyzer_options(), true)
        );
    }

    /**
     * Use the same analyzer for runtime indexing and product searches.
     */
    public static function runtime_analyzer(): WP_FTS_Analyzer
    {
        return self::$runtime_analyzer_cache ??= new WP_FTS_Analyzer(self::runtime_analyzer_options());
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
        if (strlen($language) > WP_FTS_Analyzer_Config_Limits::MAX_LANGUAGE_BYTES) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded('language_bytes', 'Runtime lemma-pack language exceeds 64 bytes.');
        }
        WP_FTS_Analyzer_Config_Limits::assert_path($manifestPath, 'Runtime lemma-pack manifest path');
        $language = WP_FTS_TermNamespace::canonicalize_lang($language);
        $manifestPath = trim($manifestPath);
        if ($language === '' || $manifestPath === '') {
            throw new InvalidArgumentException('Runtime lemma pack option requires a language and manifest path.');
        }
        self::assert_runtime_lemma_pack_can_enable($language, $manifestPath);

        $stored = self::get_option(self::ANALYZER_OPTIONS_OPTION, []);
        $options = is_array($stored) ? $stored : [];
        WP_FTS_Analyzer_Config_Limits::assert_analyzer_options($options, 'Stored WordPress analyzer options');

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

        if ($options == (is_array($stored) ? $stored : [])) {
            return $options;
        }

        $previousProfile = self::current_index_profile();
        $schemaCurrent = self::option_matches_schema_version(
            self::get_option(self::SCHEMA_VERSION_OPTION, null)
        );
        $pendingIncarnation = '';
        if ($schemaCurrent) {
            $pendingIncarnation = self::mark_initial_index_pending(
                true,
                self::sanitize_index_profile_hash($previousProfile['hash'] ?? '')
            );
        }
        try {
            self::set_option(self::ANALYZER_OPTIONS_OPTION, $options);
        } catch (Throwable $error) {
            if ($pendingIncarnation !== '') {
                self::schedule_schema_provisioning(1);
            }
            throw $error;
        }
        if ($schemaCurrent) {
            $currentProfile = self::current_index_profile();
            $reasons = self::index_profile_change_reasons($previousProfile, $currentProfile);
            if ($reasons !== []) {
                self::enqueue_index_profile_reconciliation($reasons, $previousProfile, $currentProfile);
            } else {
                self::enqueue_scope_reconciliation('index-profile', [
                    'reason' => 'runtime_lemma_pack_option_verified',
                    'profile_hash' => self::sanitize_index_profile_hash($currentProfile['hash'] ?? ''),
                ], true, '', 0, $pendingIncarnation);
            }
        }

        return $options;
    }

    /**
     * @param string[] $selectedLanguages
     * @param array<string,string> $bundledManifests
     * @return array<string,mixed> Stored analyzer option value after the bounded merge.
     */
    private static function save_bundled_runtime_lemma_pack_selection(
        array $selectedLanguages,
        array $bundledManifests,
        bool $persist = true
    ): array
    {
        WP_FTS_Analyzer_Config_Limits::assert_language_map($bundledManifests, 'Bundled runtime lemma packs');
        if (count($selectedLanguages) > WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_LANGUAGES) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded('configured_languages', 'Bundled runtime selection exceeds 32 languages.');
        }
        $stored = self::get_option(self::ANALYZER_OPTIONS_OPTION, []);
        $options = is_array($stored) ? $stored : [];
        WP_FTS_Analyzer_Config_Limits::assert_analyzer_options($options, 'Stored WordPress analyzer options');
        $selected = array_fill_keys($selectedLanguages, true);
        $filterControlled = self::filter_controlled_runtime_lemma_pack_languages();

        foreach ($bundledManifests as $language => $manifestPath) {
            if (isset($filterControlled[$language]) || self::stored_runtime_lemma_pack_has_custom_value($options, $language, $manifestPath)) {
                continue;
            }

            if (isset($selected[$language])) {
                self::assert_runtime_lemma_pack_can_enable($language, $manifestPath);
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

        if ($persist) {
            self::set_option(self::ANALYZER_OPTIONS_OPTION, $options);
        }

        return $options;
    }

    /**
     * Fully stream and functionally validate a pack before persisting it as an
     * enabled runtime analyzer. Failed verification leaves options untouched.
     */
    private static function assert_runtime_lemma_pack_can_enable(string $language, string $manifestPath): void
    {
        $validation = (new WP_FTS_AnalyzerPackValidator())->validate($manifestPath, false);
        $actualLanguage = WP_FTS_TermNamespace::canonicalize_lang((string) $validation['manifest']['language']);
        if (self::base_language($actualLanguage) !== self::base_language($language)) {
            throw new RuntimeException('Analyzer pack language does not match the requested runtime language.');
        }

        // Construction exercises the exact metadata and lookup reader used by
        // normal indexing/search after the full streamed verification above.
        WP_FTS_LanguageLemmaPack::from_manifest_file($manifestPath, null, $language);
    }

    /**
     * Report configured runtime lemma packs for admin diagnostics.
     *
     * @return array<int,array{language:string,kind:string,status:string,pack_id:string,fixture_only:bool,reason:string}>
     */
    public static function runtime_analyzer_pack_statuses(): array
    {
        if (self::$runtime_analyzer_pack_statuses_cache === null) {
            self::$runtime_analyzer_pack_statuses_cache = self::analyzer_pack_statuses(
                self::raw_runtime_analyzer_options(),
                false
            );
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
            self::$sandbox_demo_analyzer_pack_statuses_cache = self::analyzer_pack_statuses(
                self::raw_sandbox_demo_analyzer_options(),
                true
            );
        }

        return self::$sandbox_demo_analyzer_pack_statuses_cache;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<int,array{language:string,kind:string,status:string,pack_id:string,fixture_only:bool,reason:string}>
     */
    private static function analyzer_pack_statuses(array $options, bool $allow_fixture_segmenters): array
    {
        WP_FTS_Analyzer_Config_Limits::assert_analyzer_options($options, 'WordPress analyzer options');
        $statuses = [];
        $runtimeFiles = 0;
        $lookupBlocks = 0;
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

            $manifestPath = WP_FTS_LanguageLemmaPack::manifest_path_from_option(
                $option,
                self::default_lemma_pack_manifest_for_language($language)
            );
            if ($manifestPath === null) {
                $statuses[] = [
                    'language' => $language,
                    'kind' => 'lemmatizer',
                    'status' => 'not-active',
                    'pack_id' => '',
                    'fixture_only' => false,
                    'reason' => 'No runtime manifest could be resolved for this configured pack.',
                ];
                continue;
            }
            if (!is_file($manifestPath)) {
                $statuses[] = [
                    'language' => $language,
                    'kind' => 'lemmatizer',
                    'status' => 'not-active',
                    'pack_id' => '',
                    'fixture_only' => false,
                    'reason' => 'Configured pack manifest is missing and is not active.',
                ];
                continue;
            }

            try {
                $validation = (new WP_FTS_AnalyzerPackValidator())->validate_metadata($manifestPath, true);
                $actualLanguage = WP_FTS_TermNamespace::canonicalize_lang((string) $validation['manifest']['language']);
                if (self::base_language($actualLanguage) !== self::base_language($language)) {
                    throw new RuntimeException('Analyzer pack language does not match requested language.');
                }
            } catch (WP_FTS_Analyzer_Config_Limit_Exceeded $error) {
                throw $error;
            } catch (Throwable $e) {
                $message = strtolower($e->getMessage());
                $notActive = str_contains($message, 'does not match requested language')
                    || (str_contains($message, 'zlib') && str_contains($message, 'support'));
                $statuses[] = [
                    'language' => $language,
                    'kind' => 'lemmatizer',
                    'status' => $notActive ? 'not-active' : 'corrupt',
                    'pack_id' => '',
                    'fixture_only' => false,
                    'reason' => $notActive
                        ? 'Configured pack is language-mismatched or lacks its optional runtime dependency and is not active.'
                        : 'Configured pack failed strict runtime integrity verification and is not active.',
                ];
                continue;
            }

            $runtimeFiles += count($validation['runtime_files']);
            foreach ($validation['runtime_files'] as $file) {
                $lookupBlocks += isset($file['lookup']['blocks']) && is_array($file['lookup']['blocks'])
                    ? count($file['lookup']['blocks'])
                    : 0;
            }
            if ($runtimeFiles > WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_RUNTIME_FILES
                || $lookupBlocks > WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_LOOKUP_BLOCKS
            ) {
                throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                    'configured_pack_metadata',
                    'Configured lemma packs exceed the 128-file or 4,096-block metadata limit.'
                );
            }

            $statuses[] = [
                'language' => $language,
                'kind' => 'lemmatizer',
                'status' => 'active',
                'pack_id' => (string) $validation['manifest']['pack_id'],
                'fixture_only' => (bool) $validation['manifest']['fixture_only'],
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
            if (!$allow_fixture_segmenters && $option !== true) {
                $statuses[] = [
                    'language' => $language,
                    'kind' => 'tokenizer',
                    'status' => 'not-active',
                    'pack_id' => '',
                    'fixture_only' => is_array($option) && !empty($option['fixture_only']),
                    'reason' => 'WordPress runtime accepts only the packaged, attested Jieba dictionary; source-only custom dictionaries are fixture-only and were not read.',
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
                WP_FTS_Analyzer_Config_Limits::assert_analyzer_options($filtered, 'Filtered WordPress analyzer options');
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
            WP_FTS_Analyzer_Config_Limits::assert_analyzer_options($stored, 'Stored WordPress analyzer options');
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
    private static function sanitize_runtime_analyzer_options(array $options, bool $allow_fixture_segmenters = false): array
    {
        $lemmaPacks = self::runtime_lemma_pack_options_by_language($options);
        $segmenterPacks = self::runtime_segmenter_pack_options_by_language($options);
        if (!$allow_fixture_segmenters) {
            $segmenterPacks = array_filter(
                $segmenterPacks,
                static fn(mixed $option): bool => $option === true || self::lemma_pack_option_is_disabled($option)
            );
        }
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
        WP_FTS_Analyzer_Config_Limits::assert_analyzer_options($base, 'Base WordPress analyzer options');
        WP_FTS_Analyzer_Config_Limits::assert_analyzer_options($override, 'WordPress analyzer option override');
        $override = self::normalize_runtime_analyzer_option_layer($override);

        foreach ($override as $key => $value) {
            if (
                in_array($key, ['lemma_packs_by_lang', 'lemmatizer_packs_by_lang', 'segmenter_packs_by_lang', 'cjk_segmenter_packs_by_lang', 'cjk_tokenizer_packs_by_lang', 'tokenizer_packs_by_lang'], true)
                && is_array($value)
            ) {
                $current = isset($base[$key]) && is_array($base[$key]) ? $base[$key] : [];
                $base[$key] = WP_FTS_Analyzer_Config_Limits::merge_language_maps(
                    [$current, $value],
                    "WordPress analyzer {$key}"
                );
                continue;
            }

            if (in_array($key, ['polish_lemma_pack', 'polish_lemmatizer_pack'], true)) {
                $base[$key] = $value;
            }
        }
        WP_FTS_Analyzer_Config_Limits::assert_analyzer_options($base, 'Merged WordPress analyzer options');

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
        WP_FTS_Analyzer_Config_Limits::assert_analyzer_options($base, 'Base filtered WordPress analyzer options');
        WP_FTS_Analyzer_Config_Limits::assert_analyzer_options($filtered, 'Filtered WordPress analyzer options');
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
        WP_FTS_Analyzer_Config_Limits::assert_analyzer_options($options, 'WordPress analyzer option layer');
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
        foreach ($normalized as $key => $map) {
            if (is_array($map)) {
                WP_FTS_Analyzer_Config_Limits::assert_language_map($map, "WordPress analyzer {$key}");
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
        WP_FTS_Analyzer_Config_Limits::assert_language_map($packs, 'WordPress analyzer language map');
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
        WP_FTS_Analyzer_Config_Limits::assert_language_map($packs, 'Stored WordPress analyzer language map');
        WP_FTS_Analyzer_Config_Limits::assert_path($manifestPath, 'Runtime lemma-pack manifest path');
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
        WP_FTS_Analyzer_Config_Limits::assert_language_map($packs, 'Stored WordPress analyzer language map');

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
        WP_FTS_Analyzer_Config_Limits::assert_analyzer_options($options, 'WordPress analyzer options');
        $maps = [];
        if (isset($options['lemmatizer_packs_by_lang']) && is_array($options['lemmatizer_packs_by_lang'])) {
            $maps[] = $options['lemmatizer_packs_by_lang'];
        }
        if (isset($options['lemma_packs_by_lang']) && is_array($options['lemma_packs_by_lang'])) {
            $maps[] = $options['lemma_packs_by_lang'];
        }
        $packs = WP_FTS_Analyzer_Config_Limits::merge_language_maps($maps, 'WordPress lemma packs');
        if (
            !array_key_exists('pl', $packs)
            && (array_key_exists('polish_lemma_pack', $options) || array_key_exists('polish_lemmatizer_pack', $options))
        ) {
            $packs['pl'] = $options['polish_lemma_pack'] ?? $options['polish_lemmatizer_pack'] ?? false;
            WP_FTS_Analyzer_Config_Limits::assert_language_map($packs, 'WordPress lemma packs');
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
        WP_FTS_Analyzer_Config_Limits::assert_analyzer_options($options, 'WordPress analyzer options');
        $maps = [];
        foreach (['tokenizer_packs_by_lang', 'cjk_tokenizer_packs_by_lang', 'cjk_segmenter_packs_by_lang', 'segmenter_packs_by_lang'] as $key) {
            if (isset($options[$key]) && is_array($options[$key])) {
                $maps[] = $options[$key];
            }
        }
        $packs = WP_FTS_Analyzer_Config_Limits::merge_language_maps($maps, 'WordPress segmenter packs');

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
            $packs = WP_FTS_Analyzer_Config_Limits::merge_language_maps(
                [$packs, WP_FTS_AnalyzerPackValidator::bundled_unimorph_top_language_pack_manifests()],
                'Bundled sandbox lemma packs'
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
     *   post_types:string[],
     *   post_statuses:string[],
     *   date_after:string,
     *   date_before:string
     * } $controls
     */
    private static function render_sandbox_search_form(string $query, string $selected_language, array $controls): void
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

        echo '<fieldset><legend class="wp-fts-sandbox-option-label">Word beginnings</legend>';
        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_PREFIX_MATCHING_FIELD) . '" value="0">';
        echo '<label><input type="checkbox" name="' . self::esc_attr(self::ADMIN_PREFIX_MATCHING_FIELD) . '" value="1"' . ($controls['prefix_matching'] ? ' checked="checked"' : '') . '> On</label>';
        echo '<p class="description">Also matches indexed terms that start with the searched word.</p>';
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
     * @param array<string,mixed> $page
     */
    private static function render_sandbox_indexed_posts_table(array $page, string $query, string $selected_language, bool $search_submitted, bool $show_indexed_terms): void
    {
        if (($page['rows'] ?? []) === []) {
            echo '<p>No indexed posts are available yet.</p>';
            return;
        }

        echo '<p>Showing one bounded page of indexed posts. Exact totals are intentionally not calculated.</p>';
        if ($show_indexed_terms) {
            echo '<p><a class="button" href="' . self::esc_url(self::sandbox_indexed_posts_page_url($page['page'], $query, $selected_language, $search_submitted, false, (int) ($page['input_cursor'] ?? 0), (string) ($page['cursor_direction'] ?? 'after'))) . '">Hide indexed terms</a></p>';
        } else {
            echo '<p><a class="button" href="' . self::esc_url(self::sandbox_indexed_posts_page_url($page['page'], $query, $selected_language, $search_submitted, true, (int) ($page['input_cursor'] ?? 0), (string) ($page['cursor_direction'] ?? 'after'))) . '">Show indexed terms</a> <span class="description">Loads stored terms for the visible rows.</span></p>';
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th scope="col">Post ID</th><th scope="col">Title</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Language</th><th scope="col">Indexed terms</th><th scope="col">Content preview</th></tr></thead>';
        echo '<tbody>';
        foreach ($page['rows'] as $row) {
            echo '<tr>';
            echo '<td>' . self::esc_html((string) $row['post_id']) . '</td>';
            echo '<td>' . self::esc_html($row['title']) . '</td>';
            echo '<td><code>' . self::esc_html($row['post_type']) . '</code></td>';
            echo '<td><code>' . self::esc_html($row['post_status']) . '</code></td>';
            echo '<td>' . self::esc_html($row['language']) . '</td>';
            echo '<td>';
            self::render_sandbox_indexed_terms($row['indexed_terms'], $row['indexed_terms_more'], $show_indexed_terms);
            echo '</td>';
            echo '<td>' . self::esc_html($row['preview']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        if (empty($page['previous_cursor']) && empty($page['next_cursor'])) {
            return;
        }

        echo '<p class="tablenav-pages">';
        echo '<span class="displaying-num">Page ' . self::esc_html((string) $page['page']) . '</span> ';
        if (!empty($page['previous_cursor'])) {
            echo '<a class="button" href="' . self::esc_url(self::sandbox_indexed_posts_page_url($page['page'] - 1, $query, $selected_language, $search_submitted, $show_indexed_terms, (int) $page['previous_cursor'], 'before')) . '">Previous</a> ';
        }
        if (!empty($page['next_cursor'])) {
            echo '<a class="button" href="' . self::esc_url(self::sandbox_indexed_posts_page_url($page['page'] + 1, $query, $selected_language, $search_submitted, $show_indexed_terms, (int) $page['next_cursor'], 'after')) . '">Next</a>';
        }
        echo '</p>';
    }

    /**
     * @param array{requested_lang:string,query_lang:string,total:int,results:array<int,array{post_id:int,title:string,score:float,language:string,snippet:string}>} $results
     */
    private static function render_sandbox_results(array $results, string $query, string $selected_language, array $controls): void
    {
        echo '<h2>Results</h2>';
        echo '<p>Requested query language: <code>' . self::esc_html($results['requested_lang']) . '</code>. ';
        echo 'Resolved query language: <code>' . self::esc_html($results['query_lang'] !== '' ? $results['query_lang'] : 'unknown') . '</code>.</p>';
        if ($results['results'] === []) {
            echo '<p>No results matched the current index.</p>';
            return;
        }
        echo '<p>Exact totals are intentionally not calculated.';
        if (!empty($results['has_more'])) {
            echo ' More results are available after this page.';
        }
        echo '</p>';

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
        echo ' data-post-types="' . self::esc_attr(implode(',', array_map('strval', $controls['post_types'] ?? []))) . '"';
        echo ' data-post-statuses="' . self::esc_attr(implode(',', array_map('strval', $controls['post_statuses'] ?? []))) . '"';
        echo ' data-date-after="' . self::esc_attr((string) ($controls['date_after'] ?? '')) . '"';
        echo ' data-date-before="' . self::esc_attr((string) ($controls['date_before'] ?? '')) . '"';
        echo '>';
        echo '<thead><tr><th scope="col">Post ID</th><th scope="col">Title</th><th scope="col">Score</th><th scope="col">Language</th><th scope="col">Search result excerpt</th></tr></thead>';
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

    var cells = Array.prototype.slice.call(table.querySelectorAll('[data-wp-fts-detail="snippet"]'));
    if (cells.length === 0) {
        return;
    }

    var postIds = cells.map(function(cell) {
        return cell.getAttribute('data-post-id') || '';
    }).filter(function(postId, index, all) {
        return postId !== '' && all.indexOf(postId) === index;
    });
    if (postIds.length === 0) {
        return;
    }

    function append(formData, field, attribute) {
        formData.append(field, table.getAttribute(attribute) || '');
    }

    function fail(message) {
        cells.forEach(function(cell) {
            cell.classList.remove('wp-fts-sandbox-detail-pending');
            cell.classList.add('wp-fts-sandbox-detail-error');
            cell.textContent = message;
        });
    }

    var formData = new FormData();
    append(formData, 'action', 'data-action');
    append(formData, 'wp_fts_sandbox_details_nonce', 'data-nonce');
    append(formData, 'wp_fts_sandbox_query', 'data-query');
    append(formData, 'wp_fts_sandbox_lang', 'data-lang');
    append(formData, 'wp_fts_sandbox_mode', 'data-mode');
    append(formData, 'wp_fts_sandbox_limit', 'data-limit');
    append(formData, 'wp_fts_sandbox_snippet_length', 'data-snippet-length');
    append(formData, 'wp_fts_sandbox_highlight', 'data-highlight');
    append(formData, 'wp_fts_sandbox_prefix_matching', 'data-prefix-matching');
    append(formData, 'wp_fts_sandbox_date_after', 'data-date-after');
    append(formData, 'wp_fts_sandbox_date_before', 'data-date-before');
    formData.append('wp_fts_sandbox_search', '1');
    formData.append('wp_fts_sandbox_post_ids', postIds.join(','));
    ['post-types', 'post-statuses'].forEach(function(attribute) {
        var field = attribute === 'post-types' ? 'wp_fts_sandbox_post_type[]' : 'wp_fts_sandbox_post_status[]';
        (table.getAttribute('data-' + attribute) || '').split(',').filter(Boolean).forEach(function(value) {
            formData.append(field, value);
        });
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

        cells.forEach(function(cell) {
            var row = payload.data.rows[cell.getAttribute('data-post-id') || ''] || null;
            cell.classList.remove('wp-fts-sandbox-detail-pending');
            if (!row) {
                cell.classList.add('wp-fts-sandbox-detail-error');
                cell.textContent = 'Could not load excerpt.';
                return;
            }
            cell.innerHTML = row.snippet_html || '<span class="description">No excerpt available.</span>';
        });
    }).catch(function() {
        fail('Could not load excerpt.');
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

    private static function sandbox_indexed_posts_cursor(): int
    {
        $raw = self::request_text_value($_GET, self::ADMIN_POSTS_CURSOR_FIELD, 20);

        return $raw !== '' && ctype_digit($raw) ? max(0, (int) $raw) : 0;
    }

    private static function sandbox_indexed_posts_cursor_direction(): string
    {
        return self::request_text_value($_GET, self::ADMIN_POSTS_CURSOR_DIRECTION_FIELD, 8) === 'before'
            ? 'before'
            : 'after';
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
     * @return array<string,mixed>
     */
    private static function empty_sandbox_indexed_posts_page(int $page = 1): array
    {
        return [
            'page' => max(1, $page),
            'per_page' => self::SANDBOX_INDEXED_POSTS_PER_PAGE,
            'total' => null,
            'total_pages' => null,
            'has_more' => false,
            'next_cursor' => null,
            'previous_cursor' => null,
            'input_cursor' => 0,
            'cursor_direction' => 'after',
            'rows' => [],
        ];
    }

    /**
     * Read the current indexed-post list from storage state, not the demo option.
     *
     * @return array<string,mixed>
     */
    private static function sandbox_indexed_posts_page(int $page, bool $show_indexed_terms = false): array
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !is_callable([$wpdb, 'prepare']) || !is_callable([$wpdb, 'get_results'])) {
            return self::empty_sandbox_indexed_posts_page($page);
        }
        $storage = self::storage(false);
        $per_page = self::SANDBOX_INDEXED_POSTS_PER_PAGE;
        $cursor = self::sandbox_indexed_posts_cursor();
        $direction = $cursor > 0 ? self::sandbox_indexed_posts_cursor_direction() : 'after';
        $operator = $direction === 'before' ? '<' : '>';
        $order = $direction === 'before' ? 'DESC' : 'ASC';
        $documents_table = (string) ($wpdb->prefix ?? '') . 'fts_documents';
        $posts_table = (string) ($wpdb->posts ?? ((string) ($wpdb->prefix ?? '') . 'posts'));
        $where = $cursor > 0 ? "WHERE d.post_id {$operator} %d" : '';
        $args = $cursor > 0 ? [$cursor, $per_page + 1] : [$per_page + 1];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.post_id, d.primary_lang, SUBSTR(d.snippet_text, 1, 5000) AS snippet_text,
                    p.post_title, p.post_excerpt, p.post_type, p.post_status
FROM {$documents_table} d
LEFT JOIN {$posts_table} p ON p.ID = d.post_id
{$where}
ORDER BY d.post_id {$order}
LIMIT %d",
            ...$args
        ));
        self::assert_worker_database_result($rows, 'read bounded FTS indexed-post diagnostics');
        $rows = is_array($rows) ? $rows : [];
        $has_more = count($rows) > $per_page;
        $rows = array_slice($rows, 0, $per_page);
        if ($direction === 'before') {
            $rows = array_reverse($rows);
        }
        $page_ids = array_values(array_filter(array_map(
            static fn(object $row): int => max(0, (int) ($row->post_id ?? 0)),
            $rows
        )));
        $terms_by_post = $show_indexed_terms && method_exists($storage, 'terms_for_docs')
            ? $storage->terms_for_docs($page_ids, self::SANDBOX_INDEXED_TERMS_LIMIT + 1)
            : [];

        $result_rows = [];
        foreach ($rows as $row) {
            $post_id = max(0, (int) ($row->post_id ?? 0));
            if ($post_id <= 0) {
                continue;
            }
            $lang = WP_FTS_TermNamespace::canonicalize_lang((string) ($row->primary_lang ?? 'und'), 'und');
            $metadata = [
                'title' => (string) ($row->post_title ?? ''),
                'excerpt' => (string) ($row->post_excerpt ?? ''),
                'post_type' => (string) ($row->post_type ?? ''),
                'post_status' => (string) ($row->post_status ?? ''),
                'primary_lang' => $lang,
                'search_text' => (string) ($row->snippet_text ?? ''),
            ];
            $doc = ['primary_lang' => $lang, 'lang_lengths' => []];
            $result_rows[] = self::sandbox_indexed_post_row(
                $post_id,
                $metadata,
                $doc,
                is_array($terms_by_post[$post_id] ?? null) ? $terms_by_post[$post_id] : []
            );
        }

        $first_id = $page_ids[0] ?? 0;
        $last_id = $page_ids !== [] ? $page_ids[count($page_ids) - 1] : 0;
        $previous_cursor = $direction === 'before'
            ? ($has_more ? $first_id : null)
            : ($cursor > 0 ? $first_id : null);
        $next_cursor = $direction === 'before'
            ? ($cursor > 0 ? $last_id : null)
            : ($has_more ? $last_id : null);

        return [
            'page' => max(1, $page),
            'per_page' => $per_page,
            'total' => null,
            'total_pages' => null,
            'has_more' => $has_more,
            'next_cursor' => $next_cursor,
            'previous_cursor' => $previous_cursor,
            'input_cursor' => $cursor,
            'cursor_direction' => $direction,
            'rows' => $result_rows,
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $doc
     * @param string[] $indexed_terms
     * @return array{post_id:int,title:string,post_type:string,post_status:string,language:string,indexed_terms:string[],indexed_terms_more:bool,preview:string}
     */
    private static function sandbox_indexed_post_row(int $post_id, array $metadata, array $doc, array $indexed_terms): array
    {
        $post_type = is_scalar($metadata['post_type'] ?? null) ? (string) $metadata['post_type'] : '';
        $post_status = is_scalar($metadata['post_status'] ?? null) ? (string) $metadata['post_status'] : '';
        $lengths = WP_FTS_StorageCompat::doc_lang_lengths($doc, self::sandbox_indexed_language($metadata, $doc, 'en'));
        $preview = (string) ($metadata['search_text'] ?? $metadata['excerpt'] ?? '');
        $title = is_scalar($metadata['title'] ?? null) ? trim((string) $metadata['title']) : '';

        return [
            'post_id' => $post_id,
            'title' => $title !== '' ? $title : '(untitled)',
            'post_type' => $post_type !== '' ? $post_type : 'unknown',
            'post_status' => $post_status !== '' ? $post_status : 'unknown',
            'language' => self::sandbox_indexed_post_language_display($metadata, $doc, $lengths),
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

    private static function base_language(string $language): string
    {
        $language = WP_FTS_TermNamespace::canonicalize_lang($language, WP_FTS_TermNamespace::DEFAULT_LANG);
        $parts = explode('-', $language);

        return strtolower((string) ($parts[0] ?? $language));
    }

    private static function sandbox_indexed_posts_page_url(
        int $page,
        string $query,
        string $selected_language,
        bool $search_submitted,
        bool $show_indexed_terms = false,
        int $cursor = 0,
        string $direction = 'after'
    ): string
    {
        $params = [
            'page' => self::ADMIN_PAGE_SLUG,
            self::ADMIN_TAB_FIELD => self::ADMIN_INDEXED_TAB,
            self::ADMIN_POSTS_PAGE_FIELD => (string) max(1, $page),
        ];

        if ($show_indexed_terms) {
            $params[self::ADMIN_SHOW_INDEXED_TERMS_FIELD] = '1';
        }
        if ($cursor > 0) {
            $params[self::ADMIN_POSTS_CURSOR_FIELD] = (string) $cursor;
            $params[self::ADMIN_POSTS_CURSOR_DIRECTION_FIELD] = $direction === 'before' ? 'before' : 'after';
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
    private static function sandbox_result_details(string $query, string $selected_language, array $controls, array $post_ids): array
    {
        $requested = array_fill_keys($post_ids, true);
        $details = [];
        // The lazy detail request is itself one complete search page. Keep it
        // to plan/rank/hydrate; per-result explanations and indexed-term probes
        // would either be fictional with aggregate postings or become a fourth
        // statement.
        $results = self::sandbox_search_results($query, $selected_language, $controls, true);

        foreach ($results['results'] as $row) {
            $post_id = max(0, (int) ($row['post_id'] ?? 0));
            if ($post_id <= 0 || !isset($requested[$post_id])) {
                continue;
            }

            $detail = [
                'snippet_html' => self::sanitize_frontend_snippet_html((string) ($row['snippet'] ?? '')),
            ];

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
    private static function sandbox_result_row(array $row, int $post_id, string $query_language = ''): array
    {
        $language = '';
        foreach ([$row['language'] ?? null, $row['primary_lang'] ?? null, $row['lang'] ?? null, $query_language] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                $language = WP_FTS_TermNamespace::canonicalize_lang((string) $candidate);
                break;
            }
        }

        $title = is_scalar($row['title'] ?? null) && trim((string) $row['title']) !== ''
            ? (string) $row['title']
            : '(untitled)';
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
     * The route is public only after the operator enables it.
     */
    public static function rest_search_permission(mixed ...$unused): bool
    {
        return !empty(self::settings()['rest_api_enabled']);
    }

    /**
     * REST callback returning filtered ranked result rows.
     *
     * @param mixed $request WordPress REST request or request-like array/object.
     * @return array{results:array<int,array{doc_id:int,score:float}>}|object|array<string,mixed>
     */
    public static function rest_search(mixed $request): array|object
    {
        try {
            return self::rest_search_response($request);
        } catch (Throwable $error) {
            self::debug_record_search_boundary_failure(
                'REST search',
                $error,
                'The REST endpoint returned a sanitized 503 response after the FTS failure.'
            );

            return self::rest_error(
                'wp_fts_search_unavailable',
                'Search is temporarily unavailable.',
                503
            );
        }
    }

    /**
     * @return array{results:array<int,array{doc_id:int,score:float}>}|object|array<string,mixed>
     */
    private static function rest_search_response(mixed $request): array|object
    {
        $settings = self::settings();
        if (empty($settings['rest_api_enabled'])) {
            return self::rest_error(
                'wp_fts_rest_search_disabled',
                'REST search is not enabled for this site.',
                404
            );
        }

        try {
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
                'mode' => $mode,
                'limit' => self::rest_limit($request),
                'prefix_matching' => !empty($settings['rest_prefix_matching']),
                'max_query_terms' => self::REST_MAX_QUERY_TERMS,
            ];
            $language = self::rest_language($request);
            if ($language !== null) {
                $search_args['lang'] = $language;
            }
            $cursor = self::rest_cursor($request);
            $direction = self::rest_cursor_direction($request);
            if ($cursor !== null) {
                $search_args['cursor'] = $cursor;
                $search_args['direction'] = $direction;
            }

            if (self::rest_explain_requested($request) && self::current_user_can_search_explain()) {
                return self::search_visible_payload($query, $search_args, true);
            }

            return self::search_page($query, $search_args);
        } catch (WP_FTS_Search_Budget_Exceeded $e) {
            $clientBudgets = [
                'query bytes',
                'logical query groups',
                'analyzer occurrences',
                'query alternatives per group',
                'query alternatives',
                'analyzed terms',
                'generated SQL',
            ];
            $clientBudget = in_array($e->budget(), $clientBudgets, true);
            return self::rest_error(
                $clientBudget ? 'wp_fts_query_too_complex' : 'wp_fts_search_budget_exceeded',
                $clientBudget
                    ? 'REST search query exceeds the public complexity limit.'
                    : 'REST search could not execute within the fixed relational search limits.',
                $clientBudget ? 400 : 503,
                ['budget' => $e->budget()]
            );
        } catch (InvalidArgumentException $error) {
            return self::rest_error(
                'wp_fts_invalid_search_request',
                self::sanitize_index_failure_text($error->getMessage(), 240),
                400
            );
        } catch (WP_FTS_Search_Unavailable) {
            return self::rest_error(
                'wp_fts_search_unavailable',
                'Search is temporarily unavailable.',
                503
            );
        }
    }

    /**
     * Search the index and return only posts visible to the current visitor.
     *
     * @return array<int,array{doc_id:int,score:float}>
     */
    public static function search(string $query, array $opts = []): array
    {
        return self::search_page($query, $opts)['results'];
    }

    /**
     * Search the index and include operator-only bounded explain diagnostics.
     *
     * Callers without the operator capability receive the normal visible rows
     * plus a small unavailable marker, never the internal explain payload.
     *
     * @return array{results:array<int,array<string,mixed>>,has_more:bool,next_cursor:?string,previous_cursor:?string,total:null,total_relation:string,query_lang:string,explain?:array<string,mixed>,explain_available?:bool,explain_unavailable_reason?:string}
     */
    public static function search_with_explain(string $query, array $opts = []): array
    {
        if (!self::current_user_can_search_explain()) {
            $page = self::search_page($query, $opts);
            $page['explain_available'] = false;
            $page['explain_unavailable_reason'] = 'not_authorized';

            return $page;
        }

        return self::search_visible_payload($query, $opts, true);
    }

    /**
     * Search one exact, visibility-authorized cursor page.
     *
     * @return array{results:array<int,array<string,mixed>>,has_more:bool,next_cursor:?string,previous_cursor:?string,total:null,total_relation:string,query_lang:string}
     */
    public static function search_page(string $query, array $opts = []): array
    {
        return self::search_visible_payload($query, $opts, false);
    }

    /**
     * Search the index and return visible rows, optionally with the flat,
     * bounded relational-storage explain.
     *
     * @param array<string,mixed> $opts
     * @return array{results:array<int,array<string,mixed>>,has_more:bool,next_cursor:?string,previous_cursor:?string,total:null,total_relation:string,query_lang:string,explain?:array<string,mixed>}
     */
    private static function search_visible_payload(
        string $query,
        array $opts = [],
        bool $include_explain = false,
        bool $include_internal_post_rows = false
    ): array
    {
        $opts = self::normalize_public_search_options($opts);
        if (strlen($query) > self::MAX_SEARCH_QUERY_BYTES) {
            throw new WP_FTS_Search_Budget_Exceeded('query bytes');
        }
        $query = trim($query);
        if ($query === '') {
            if (isset($opts['cursor'])) {
                throw new InvalidArgumentException('Search cursor cannot be used with an empty query.');
            }
            return [
                'results' => [],
                'has_more' => false,
                'next_cursor' => null,
                'previous_cursor' => null,
                'total' => null,
                'total_relation' => 'unknown',
                'query_lang' => '',
            ];
        }
        $settings = self::settings();
        $opts = self::authorized_search_scope($opts, $settings);
        if (!empty($opts['_empty_search_scope'])) {
            self::assert_search_cursor_authenticity($opts);
            return [
                'results' => [],
                'has_more' => false,
                'next_cursor' => null,
                'previous_cursor' => null,
                'total' => null,
                'total_relation' => 'unknown',
                'query_lang' => '',
            ];
        }
        $readiness = self::search_takeover_status();
        if (empty($readiness['ready'])) {
            throw new WP_FTS_Search_Unavailable('Full-text search is unavailable: ' . (string) ($readiness['reason'] ?? 'not_ready'));
        }

        $limit = (int) ($opts['limit'] ?? 10);
        $mode = (string) ($opts['mode'] ?? 'OR');
        $search_options = [
            'mode' => $mode,
            'limit' => $limit,
            'prefix_matching' => self::search_prefix_matching_value($opts, $settings),
            '_search_ready_incarnation' => (string) $readiness['ready_incarnation'],
            '_search_ready_profile_hash' => (string) $readiness['ready_profile_hash'],
        ] + self::searcher_prefix_threshold_options($settings, $opts) + self::searcher_recency_boost_options($settings);
        if (array_key_exists('lang', $opts) && $opts['lang'] !== null) {
            if (trim((string) $opts['lang']) !== '') {
                $search_options['lang'] = (string) $opts['lang'];
            }
        }
        foreach ([
            'max_query_terms',
            'request_budget_guard',
            'cursor',
            'after_cursor',
            'before_cursor',
            'direction',
            'post_type',
            'post_types',
            'post_status',
            'post_statuses',
            'date_after',
            'date_before',
            'include_metadata',
            'include_snippets',
            'highlight',
            'snippet_length',
            'recency_boost',
            'recency_boost_strength',
            'recency_boost_half_life_days',
            'now_gmt',
        ] as $key) {
            if (array_key_exists($key, $opts)) {
                $search_options[$key] = $opts[$key];
            }
        }
        if ($include_explain) {
            $search_options['explain'] = true;
        }
        if ($include_internal_post_rows) {
            $search_options['_include_canonical_post_rows'] = true;
        }

        $searcher = WP_FTS_Searcher::for_set_oriented_storage(self::storage(false), self::runtime_analyzer());
        try {
            self::invoke_search_budget_guard($search_options);
            $payload = $searcher->search($query, $search_options);
            self::invoke_search_budget_guard($search_options);
        } catch (WP_FTS_Search_Budget_Exceeded|InvalidArgumentException|WP_FTS_Search_Unavailable $error) {
            throw $error;
        } catch (Throwable $error) {
            self::latch_search_runtime_failure($error);
            throw new WP_FTS_Search_Unavailable('Full-text search failed closed and scheduled repair.', 0, $error);
        }
        $rows = is_array($payload['results'] ?? null)
            ? $payload['results']
            : (is_array($payload) ? $payload : []);

        $explain = [];
        if ($include_explain && is_array($payload['explain'] ?? null)) {
            $explain = $payload['explain'];
        }

        $visible = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_numeric($row['doc_id'] ?? null) || !is_numeric($row['score'] ?? null)) {
                continue;
            }

            $doc_id = (int) $row['doc_id'];
            if ($doc_id <= 0) {
                continue;
            }

            $row['doc_id'] = $doc_id;
            $row['score'] = (float) $row['score'];
            if (!$include_internal_post_rows) {
                unset($row['_canonical_post_row']);
            }
            $visible[] = $row;
            if (count($visible) >= $limit) {
                break;
            }
        }

        if (function_exists('apply_filters')) {
            $filter_visible = [];
            foreach ($visible as $row) {
                unset($row['_canonical_post_row']);
                $filter_visible[] = $row;
            }
            $filtered = apply_filters('wp_fts_search_results', $filter_visible, $query, $opts);
            if (is_array($filtered) && count($filtered) === count($visible)) {
                $decorated = [];
                foreach (array_values($filtered) as $index => $row) {
                    $authorized = $visible[$index];
                    if (
                        !is_array($row)
                        || !is_numeric($row['doc_id'] ?? null)
                        || (int) $row['doc_id'] !== (int) $authorized['doc_id']
                    ) {
                        $decorated = [];
                        break;
                    }
                    $internal_post_row = $authorized['_canonical_post_row'] ?? null;
                    $row['doc_id'] = $authorized['doc_id'];
                    $row['score'] = $authorized['score'];
                    if ($include_internal_post_rows && $internal_post_row !== null) {
                        $row['_canonical_post_row'] = $internal_post_row;
                    } else {
                        unset($row['_canonical_post_row']);
                    }
                    $decorated[] = $row;
                }
                if (count($decorated) === count($visible)) {
                    $visible = $decorated;
                }
            }
            self::invoke_search_budget_guard($search_options);
        }

        $result = [
            'results' => $visible,
            'has_more' => !empty($payload['has_more']),
            'next_cursor' => isset($payload['next_cursor']) && is_scalar($payload['next_cursor'])
                ? (string) $payload['next_cursor']
                : null,
            'previous_cursor' => isset($payload['previous_cursor']) && is_scalar($payload['previous_cursor'])
                ? (string) $payload['previous_cursor']
                : null,
            'total' => null,
            'total_relation' => 'unknown',
            'query_lang' => isset($payload['query_lang']) && is_scalar($payload['query_lang'])
                ? (string) $payload['query_lang']
                : '',
        ];
        if ($include_explain) {
            $result['explain'] = $explain;
        }
        self::invoke_search_budget_guard($search_options);

        return $result;
    }

    /** Disable takeover after an unexpected read failure and request verification. */
    private static function latch_search_runtime_failure(
        Throwable $error,
        bool $foreground_owner_guard_blocked = false
    ): void
    {
        try {
            self::clear_search_ready_incarnation();
        } catch (Throwable) {
            // The independent health capability below must still be attempted.
        }
        try {
            $state = self::index_health_state();
            $state['status'] = 'unhealthy';
            $state['search_runtime_failure_latched'] = true;
            if ($foreground_owner_guard_blocked) {
                // No timeout or later-free file can prove that a request which
                // acquired no guard has exited. Only an operator-authorized
                // reset may retire this fail-closed capability.
                $state['foreground_owner_guard_blocked'] = true;
            }
            $state['last_error'] = self::sanitize_index_failure_text(
                get_class($error) . ': ' . $error->getMessage(),
                self::MAX_INDEX_FAILURE_ERROR_BYTES
            );
            self::set_option(self::INDEX_HEALTH_OPTION, $state);
        } catch (Throwable) {
            // Scheduling below is still attempted when health persistence fails.
        }
        self::$search_takeover_status_cache = [];
        self::schedule_schema_provisioning();
    }

    /** Clear only a transient read latch after bounded maintenance verification. */
    private static function clear_verified_search_runtime_failure(): void
    {
        $state = self::index_health_state();
        if (
            self::sanitize_initial_index_status($state['initial_index_status'] ?? '') !== self::INITIAL_INDEX_STATUS_READY
            || !empty($state['foreground_owner_guard_blocked'])
            || self::profile_reconciliation_pending($state)
            || !self::readiness_completion_matches($state)
            || !empty($state['global_visibility_fence_active'])
        ) {
            return;
        }
        $work = self::durable_work_status();
        if ($work['scope_count'] > 0) {
            return;
        }

        $state['status'] = 'ready';
        $state['search_runtime_failure_latched'] = false;
        $state['schema_upgrade_error'] = '';
        $state['last_error'] = '';
        self::set_option(self::INDEX_HEALTH_OPTION, $state);
        self::$search_takeover_status_cache = [];
    }

    /**
     * Reject unsupported public knobs and canonicalize the aliases the PHP
     * facade deliberately supports before readiness, analysis, or SQL.
     *
     * @param array<mixed,mixed> $opts
     * @return array<string,mixed>
     */
    private static function normalize_public_search_options(array $opts): array
    {
        if (count($opts) > self::MAX_PUBLIC_SEARCH_OPTIONS) {
            throw new InvalidArgumentException('WordPress search accepts at most 32 options.');
        }
        $allowed = array_fill_keys([
            'mode',
            'offset',
            'limit',
            'lang',
            'cursor',
            'after_cursor',
            'before_cursor',
            'direction',
            'prefix_matching',
            'prefix',
            'prefix_min_length',
            'max_query_terms',
            'request_budget_guard',
            'post_type',
            'post_types',
            'post_status',
            'post_statuses',
            'date_after',
            'date_before',
            'include_metadata',
            'include_snippets',
            'snippets',
            'highlight',
            'snippet_length',
            'recency_boost',
            'freshness_boost',
            'recency_boost_strength',
            'freshness_boost_strength',
            'recency_boost_half_life_days',
            'freshness_boost_half_life_days',
            'recency_boost_window_days',
            'now_gmt',
            'recency_now',
        ], true);
        foreach ($opts as $key => $_value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('WordPress search option keys must be strings.');
            }
            if (strlen($key) > self::MAX_SEARCH_OPTION_KEY_BYTES) {
                throw new InvalidArgumentException('WordPress search option keys may contain at most 64 bytes.');
            }
            if (!isset($allowed[$key])) {
                throw new InvalidArgumentException("Relational WordPress search does not support {$key}.");
            }
        }

        $normalized = $opts;
        if (array_key_exists('mode', $normalized)) {
            if (!is_string($normalized['mode']) || strlen($normalized['mode']) > self::MAX_SEARCH_MODE_BYTES) {
                throw new InvalidArgumentException('Search mode must be a string of at most 8 bytes.');
            }
            $normalized['mode'] = strtoupper($normalized['mode']);
            if (!in_array($normalized['mode'], ['OR', 'AND'], true)) {
                throw new InvalidArgumentException('Search mode must be OR or AND.');
            }
        }
        foreach ([
            'offset' => [0, 0],
            'limit' => [1, self::MAX_SEARCH_LIMIT],
            'max_query_terms' => [1, self::REST_MAX_QUERY_TERMS],
            'prefix_min_length' => [self::PREFIX_MIN_LENGTH_MIN, self::PREFIX_MIN_LENGTH_MAX],
            'snippet_length' => [1, self::SETTINGS_SNIPPET_MAX],
        ] as $key => [$minimum, $maximum]) {
            if (array_key_exists($key, $normalized)) {
                $normalized[$key] = self::strict_search_integer($normalized[$key], $key, $minimum, $maximum);
            }
        }
        if (array_key_exists('lang', $normalized)) {
            if (!is_string($normalized['lang']) || strlen($normalized['lang']) > self::MAX_SEARCH_LANGUAGE_BYTES) {
                throw new InvalidArgumentException('Search language must be a string of at most 64 bytes.');
            }
        }

        $cursorKey = null;
        foreach (['cursor', 'after_cursor', 'before_cursor'] as $key) {
            if (!array_key_exists($key, $normalized)) {
                continue;
            }
            if ($cursorKey !== null) {
                throw new InvalidArgumentException('Pass only one of cursor, after_cursor, or before_cursor.');
            }
            if (
                !is_string($normalized[$key])
                || trim($normalized[$key]) === ''
                || strlen($normalized[$key]) > self::MAX_SEARCH_CURSOR_BYTES
            ) {
                throw new InvalidArgumentException('Search cursors must be nonempty strings of at most 2,048 bytes.');
            }
            $cursorKey = $key;
        }
        $direction = null;
        if (array_key_exists('direction', $normalized)) {
            if (!is_string($normalized['direction']) || !in_array($normalized['direction'], ['after', 'before'], true)) {
                throw new InvalidArgumentException('Search cursor direction must be exactly after or before.');
            }
            $direction = $normalized['direction'];
            if ($cursorKey === null) {
                throw new InvalidArgumentException('Search cursor direction requires a nonempty cursor.');
            }
        }
        if ($cursorKey !== null) {
            $inferredDirection = $cursorKey === 'before_cursor' ? 'before' : 'after';
            if ($cursorKey !== 'cursor' && $direction !== null && $direction !== $inferredDirection) {
                throw new InvalidArgumentException("{$cursorKey} conflicts with the requested cursor direction.");
            }
            $normalized['cursor'] = $normalized[$cursorKey];
            if ($cursorKey !== 'cursor' || $direction !== null) {
                $normalized['direction'] = $direction ?? $inferredDirection;
            }
        }
        unset($normalized['after_cursor'], $normalized['before_cursor']);

        foreach (['include_metadata', 'highlight'] as $key) {
            if (array_key_exists($key, $normalized)) {
                $normalized[$key] = self::strict_search_switch($normalized[$key], $key);
            }
        }
        foreach ([
            ['prefix_matching', 'prefix', 'prefix matching'],
            ['include_snippets', 'snippets', 'snippet inclusion'],
        ] as [$primary, $alias, $label]) {
            $primarySet = array_key_exists($primary, $normalized);
            $aliasSet = array_key_exists($alias, $normalized);
            $primaryValue = $primarySet ? self::strict_search_switch($normalized[$primary], $primary) : null;
            $aliasValue = $aliasSet ? self::strict_search_switch($normalized[$alias], $alias) : null;
            if ($primarySet && $aliasSet && $primaryValue !== $aliasValue) {
                throw new InvalidArgumentException("Search {$label} aliases must agree.");
            }
            if ($primarySet || $aliasSet) {
                $normalized[$primary] = $primarySet ? $primaryValue : $aliasValue;
            }
            unset($normalized[$alias]);
        }

        foreach ([['post_type', 'post_types'], ['post_status', 'post_statuses']] as [$singular, $plural]) {
            if (array_key_exists($singular, $normalized) && array_key_exists($plural, $normalized)) {
                throw new InvalidArgumentException("Pass only one of {$singular} or {$plural}.");
            }
            if (array_key_exists($singular, $normalized) || array_key_exists($plural, $normalized)) {
                $normalized[$plural] = self::search_scope_values(
                    array_key_exists($plural, $normalized) ? $normalized[$plural] : $normalized[$singular]
                );
            }
            unset($normalized[$singular]);
        }

        foreach (['date_after', 'date_before'] as $key) {
            if (array_key_exists($key, $normalized)) {
                self::strict_search_gmt_timestamp($normalized[$key], $key);
            }
        }
        if (array_key_exists('request_budget_guard', $normalized) && !is_callable($normalized['request_budget_guard'])) {
            throw new InvalidArgumentException('Search request_budget_guard must be callable.');
        }

        $toggleValues = [];
        foreach (['recency_boost', 'freshness_boost'] as $key) {
            if (array_key_exists($key, $normalized)) {
                $toggleValues[$key] = self::strict_search_recency_toggle($normalized[$key], $key);
            }
        }
        if (count(array_unique($toggleValues, SORT_REGULAR)) > 1) {
            throw new InvalidArgumentException('Search recency boost aliases must agree.');
        }
        if ($toggleValues !== []) {
            $normalized['recency_boost'] = (float) reset($toggleValues);
        }
        unset($normalized['freshness_boost']);

        $strengthValues = [];
        foreach (['recency_boost_strength', 'freshness_boost_strength'] as $key) {
            if (array_key_exists($key, $normalized)) {
                $strengthValues[$key] = self::strict_search_float(
                    $normalized[$key],
                    $key,
                    self::RECENCY_BOOST_STRENGTH_MIN,
                    self::RECENCY_BOOST_STRENGTH_MAX
                );
            }
        }
        if (count(array_unique($strengthValues, SORT_REGULAR)) > 1) {
            throw new InvalidArgumentException('Search recency boost strength aliases must agree.');
        }
        if ($strengthValues !== []) {
            $normalized['recency_boost_strength'] = (float) reset($strengthValues);
        }
        unset($normalized['freshness_boost_strength']);

        $halfLifeValues = [];
        foreach (['recency_boost_half_life_days', 'freshness_boost_half_life_days', 'recency_boost_window_days'] as $key) {
            if (array_key_exists($key, $normalized)) {
                $halfLifeValues[$key] = self::strict_search_float(
                    $normalized[$key],
                    $key,
                    self::RECENCY_BOOST_HALF_LIFE_MIN,
                    self::RECENCY_BOOST_HALF_LIFE_MAX
                );
            }
        }
        if (count(array_unique($halfLifeValues, SORT_REGULAR)) > 1) {
            throw new InvalidArgumentException('Search recency half-life aliases must agree.');
        }
        if ($halfLifeValues !== []) {
            $normalized['recency_boost_half_life_days'] = (float) reset($halfLifeValues);
        }
        unset($normalized['freshness_boost_half_life_days'], $normalized['recency_boost_window_days']);

        $clockValues = [];
        foreach (['now_gmt', 'recency_now'] as $key) {
            if (array_key_exists($key, $normalized)) {
                $clockValues[$key] = self::strict_search_gmt_timestamp($normalized[$key], $key);
            }
        }
        if (count(array_unique($clockValues, SORT_NUMERIC)) > 1) {
            throw new InvalidArgumentException('Search recency clock aliases must agree.');
        }
        if (array_key_exists('recency_now', $normalized) && !array_key_exists('now_gmt', $normalized)) {
            $normalized['now_gmt'] = $normalized['recency_now'];
        }
        unset($normalized['recency_now']);

        return $normalized;
    }

    /** Accept only the explicit boolean spellings used by the relational contract. */
    private static function strict_search_switch(mixed $value, string $key): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value) && strlen($value) <= self::MAX_SEARCH_SWITCH_BYTES) {
            $value = strtolower($value);
            if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        throw new InvalidArgumentException("Search {$key} must be a boolean switch.");
    }

    /** Parse an exact non-negative integer without permissive PHP numeric casts. */
    private static function strict_search_integer(mixed $value, string $key, int $minimum, int $maximum): int
    {
        if (is_string($value)) {
            if (
                $value === ''
                || strlen($value) > self::MAX_SEARCH_NUMERIC_BYTES
                || !ctype_digit($value)
                || (strlen($value) > 1 && $value[0] === '0')
            ) {
                throw new InvalidArgumentException("Search {$key} must be an integer from {$minimum} through {$maximum}.");
            }
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("Search {$key} must be an integer from {$minimum} through {$maximum}.");
        }

        return $value;
    }

    /** Parse one finite unsigned decimal without exponent or padded spellings. */
    private static function strict_search_float(mixed $value, string $key, float $minimum, float $maximum): float
    {
        if (is_string($value)) {
            $parts = strlen($value) <= self::MAX_SEARCH_NUMERIC_BYTES ? explode('.', $value) : [];
            $valid = ($parts !== [] && count($parts) <= 2)
                && $parts[0] !== ''
                && ctype_digit($parts[0])
                && (strlen($parts[0]) === 1 || $parts[0][0] !== '0')
                && (count($parts) === 1 || ($parts[1] !== '' && ctype_digit($parts[1])));
            if (!$valid) {
                throw new InvalidArgumentException("Search {$key} must be a finite number from {$minimum} through {$maximum}.");
            }
            $value = (float) $value;
        }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            throw new InvalidArgumentException("Search {$key} must be a finite number from {$minimum} through {$maximum}.");
        }
        $value = (float) $value;
        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("Search {$key} must be a finite number from {$minimum} through {$maximum}.");
        }

        return $value;
    }

    /** Recency toggles accept explicit switches or a strength in the documented range. */
    private static function strict_search_recency_toggle(mixed $value, string $key): float
    {
        if (is_bool($value)) {
            return $value ? self::RECENCY_BOOST_STRENGTH_DEFAULT : 0.0;
        }
        if (is_string($value)) {
            $lower = strtolower($value);
            if (in_array($lower, ['true', 'yes', 'on'], true)) {
                return self::RECENCY_BOOST_STRENGTH_DEFAULT;
            }
            if (in_array($lower, ['false', 'no', 'off'], true)) {
                return 0.0;
            }
        }

        return self::strict_search_float(
            $value,
            $key,
            self::RECENCY_BOOST_STRENGTH_MIN,
            self::RECENCY_BOOST_STRENGTH_MAX
        );
    }

    /** Validate one exact UTC date/datetime and return its timestamp for alias comparison. */
    private static function strict_search_gmt_timestamp(mixed $value, string $key): int
    {
        if (
            !is_string($value)
            || $value === ''
            || trim($value) !== $value
            || strlen($value) > self::MAX_SEARCH_SCOPE_VALUE_BYTES
        ) {
            throw new InvalidArgumentException("Search {$key} must be a valid UTC date or datetime of at most 64 bytes.");
        }
        $timezone = new DateTimeZone('UTC');
        foreach (['!Y-m-d H:i:s', '!Y-m-d\TH:i:s', '!Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            $warnings = is_array($errors) ? (int) ($errors['warning_count'] ?? 0) : 0;
            $errorCount = is_array($errors) ? (int) ($errors['error_count'] ?? 0) : 0;
            if ($date instanceof DateTimeImmutable && $warnings === 0 && $errorCount === 0) {
                return $date->getTimestamp();
            }
        }

        throw new InvalidArgumentException("Search {$key} must be a valid UTC date or datetime of at most 64 bytes.");
    }

    /** Verify an otherwise-empty page's opaque cursor without issuing SQL. */
    private static function assert_search_cursor_authenticity(array $opts): void
    {
        if (!isset($opts['cursor'])) {
            return;
        }
        self::storage(false)->assert_search_cursor_authenticity((string) $opts['cursor']);
    }

    /**
     * Compile the caller's requested scope into SQL-safe WordPress visibility.
     *
     * The public PHP facade is itself a security boundary: accepting arbitrary
     * status/type arrays here would let any plugin or REST adapter retrieve
     * private rows before a capability check. Public calls therefore default to
     * published configured types. Operator-only statuses require the registered
     * capability for every requested post type (or an explicit WP-CLI process),
     * because the relational query does not perform a second author/capability
     * filter after ranking.
     *
     * @param array<string,mixed> $opts
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private static function authorized_search_scope(array $opts, array $settings): array
    {
        $configuredTypes = self::sanitize_post_type_list(
            $settings['index_post_types'] ?? [],
            self::settings_post_type_choices()
        );
        $requestedTypes = self::search_scope_values($opts['post_types'] ?? $opts['post_type'] ?? []);
        if ($requestedTypes === []) {
            $requestedTypes = $configuredTypes;
        }
        $configuredMap = array_fill_keys($configuredTypes, true);
        foreach ($requestedTypes as $postType) {
            if (!isset($configuredMap[$postType])) {
                throw new InvalidArgumentException('Search post types must be enabled public searchable types.');
            }
        }
        $statuses = self::search_scope_values($opts['post_statuses'] ?? $opts['post_status'] ?? []);
        if ($statuses === []) {
            $statuses = self::FRONTEND_SEARCH_POST_STATUSES;
        }
        foreach ($statuses as $status) {
            if (!in_array($status, self::ADMIN_POST_SEARCH_POST_STATUSES, true)) {
                throw new InvalidArgumentException('Search post status is not supported.');
            }
        }
        if ($requestedTypes === []) {
            $opts['_empty_search_scope'] = true;
            return $opts;
        }

        if (!self::current_user_can_search_post_type_statuses($requestedTypes, $statuses, true)) {
            throw new InvalidArgumentException('The current user cannot search non-public posts.');
        }

        unset($opts['post_type'], $opts['post_status']);
        $opts['post_types'] = $requestedTypes;
        $opts['post_statuses'] = $statuses;

        return $opts;
    }

    /**
     * Check the registered capability mapping for every requested post type.
     *
     * WordPress permits custom post types to use capability names unrelated to
     * the built-in `post` caps. Both the public search boundary and wp-admin
     * takeover must use this same check or one can authorize a scope the other
     * rejects.
     *
     * @param string[] $post_types
     * @param string[] $statuses
     */
    private static function current_user_can_search_post_type_statuses(
        array $post_types,
        array $statuses,
        bool $allow_wp_cli
    ): bool {
        $needs_edit_others = array_intersect($statuses, ['draft', 'pending', 'future']) !== [];
        $needs_edit_published = in_array('future', $statuses, true);
        $needs_private = in_array('private', $statuses, true);
        if (!$needs_edit_others && !$needs_edit_published && !$needs_private) {
            return true;
        }
        if ($allow_wp_cli && defined('WP_CLI') && (bool) constant('WP_CLI')) {
            return true;
        }
        if (!function_exists('current_user_can') || !function_exists('get_post_type_object')) {
            return false;
        }

        foreach ($post_types as $post_type) {
            $post_type_object = get_post_type_object($post_type);
            $capabilities = is_object($post_type_object) && is_object($post_type_object->cap ?? null)
                ? $post_type_object->cap
                : null;
            if ($capabilities === null) {
                return false;
            }
            if ($needs_edit_others) {
                $capability = is_scalar($capabilities->edit_others_posts ?? null)
                    ? trim((string) $capabilities->edit_others_posts)
                    : '';
                if ($capability === '' || !current_user_can($capability)) {
                    return false;
                }
            }
            if ($needs_edit_published) {
                $capability = is_scalar($capabilities->edit_published_posts ?? null)
                    ? trim((string) $capabilities->edit_published_posts)
                    : '';
                if ($capability === '' || !current_user_can($capability)) {
                    return false;
                }
            }
            if ($needs_private) {
                $capability = is_scalar($capabilities->read_private_posts ?? null)
                    ? trim((string) $capabilities->read_private_posts)
                    : '';
                if ($capability === '' || !current_user_can($capability)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return string[] */
    private static function search_scope_values(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        if (count($items) > self::MAX_SEARCH_SCOPE_VALUES) {
            throw new InvalidArgumentException('Search accepts at most 32 values per scope.');
        }

        $values = [];
        $input_bytes = 0;
        foreach ($items as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException('Search scope values must be strings.');
            }

            $input_bytes += strlen($item);
            if ($input_bytes > self::MAX_SEARCH_SCOPE_BYTES) {
                throw new InvalidArgumentException('Search scope values may contain at most 4,096 bytes.');
            }
            foreach (explode(',', $item) as $part) {
                $part = trim($part);
                if ($part === '' || strlen($part) > self::MAX_SEARCH_SCOPE_VALUE_BYTES) {
                    throw new InvalidArgumentException('Search scope values must be nonempty and contain at most 64 bytes each.');
                }
                $part = self::sanitize_key($part);
                if ($part === '') {
                    throw new InvalidArgumentException('Search scope values must contain a valid WordPress key.');
                }
                $values[$part] = true;
                if (count($values) > self::MAX_SEARCH_SCOPE_VALUES) {
                    throw new InvalidArgumentException('Search accepts at most 32 values per scope.');
                }
            }
        }
        $values = array_keys($values);
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * @param array<string,mixed> $options
     */
    private static function invoke_search_budget_guard(array $options): void
    {
        $guard = $options['request_budget_guard'] ?? null;
        if (is_callable($guard) && $guard() === false) {
            throw new WP_FTS_Search_Budget_Exceeded('request circuit breaker');
        }
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
     * @return array{prefix_min_length:int}
     */
    private static function searcher_prefix_threshold_options(array $settings, array $overrides = []): array
    {
        return [
            'prefix_min_length' => self::sanitize_prefix_min_length(
                array_key_exists('prefix_min_length', $overrides)
                    ? $overrides['prefix_min_length']
                    : ($settings['prefix_min_length'] ?? self::PREFIX_MIN_LENGTH_DEFAULT)
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
     * Register the bounded cursor inputs used by FTS-owned adjacent pages.
     *
     * @param mixed $query_vars
     * @return string[]
     */
    public static function register_search_query_vars(mixed $query_vars): array
    {
        $vars = is_array($query_vars) ? $query_vars : [];
        foreach (['wp_fts_cursor', 'wp_fts_cursor_direction', 'wp_fts_lang'] as $key) {
            if (!in_array($key, $vars, true)) {
                $vars[] = $key;
            }
        }

        return $vars;
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
        try {
            return self::run_frontend_search_replacement($posts, $query);
        } catch (Throwable $error) {
            self::clear_frontend_search_replacement_state($query);
            self::debug_record_search_boundary_failure(
                'frontend search',
                $error,
                'The enabled FTS replacement failed closed without running core LIKE search.'
            );

            return self::frontend_search_replacement_owns_shape($query)
                ? self::frontend_search_fail_closed($posts, $query, 'runtime_failure')
                : $posts;
        }
    }

    private static function run_frontend_search_replacement(mixed $posts, mixed $query): mixed
    {
        if (!self::should_replace_frontend_search($query)) {
            if (self::frontend_search_replacement_owns_shape($query)) {
                $settings = self::settings();
                if (self::should_preserve_prior_search_provider_result($posts, $settings)) {
                    return $posts;
                }

                return self::frontend_search_fail_closed($posts, $query, 'unavailable_or_unbounded_page');
            }
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
            $result['has_more'],
            $result['next_cursor'],
            $result['previous_cursor'],
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
     * Attach the opaque cursor to WordPress' adjacent search-page URLs.
     *
     * The cursor is authoritative and only immediately adjacent pages are
     * mapped. A conventional 999999999 template sentinel is preserved so a
     * theme can feed that URL into paginate_links(); the final-link filter
     * below validates every concrete anchor produced from the template.
     */
    public static function filter_frontend_search_pagenum_link(mixed $link, mixed $pagenum): mixed
    {
        global $wp_query;

        if (!is_string($link) || !is_numeric($pagenum) || !is_object($wp_query)) {
            return $link;
        }
        $query_key = self::query_object_key($wp_query);
        $state = $query_key > 0 ? (self::$front_end_search_query_state[$query_key] ?? null) : null;
        if (!is_array($state)) {
            return $link;
        }
        $current = max(1, (int) self::query_var($wp_query, 'paged', self::query_var($wp_query, 'page', 1)));
        $target = max(1, (int) $pagenum);
        if ($target === 999999999) {
            return $link;
        }
        if ($target === $current + 1) {
            $cursor = is_scalar($state['next_cursor'] ?? null) ? (string) $state['next_cursor'] : '';
            $direction = 'after';
        } elseif ($target === $current - 1) {
            $cursor = is_scalar($state['previous_cursor'] ?? null) ? (string) $state['previous_cursor'] : '';
            $direction = 'before';
        } else {
            return '#wp-fts-adjacent-cursor-only';
        }
        if ($cursor === '') {
            return '#wp-fts-adjacent-cursor-only';
        }

        return self::frontend_search_cursor_link($link, $cursor, $direction);
    }

    /**
     * Disable arbitrary numeric links emitted by paginate_links().
     *
     * Core may include page 1/end-size links on a deep cursor page even though
     * max_num_pages is only a lower bound. Only the immediately adjacent URLs
     * receive an authoritative cursor; every other anchor remains local and
     * cannot trigger an FTS-owned numeric-offset request.
     */
    public static function filter_frontend_search_paginate_link(mixed $link): mixed
    {
        global $wp_query;

        if (!is_string($link) || !is_object($wp_query)) {
            return $link;
        }
        $query_key = self::query_object_key($wp_query);
        $state = $query_key > 0 ? (self::$front_end_search_query_state[$query_key] ?? null) : null;
        if (!is_array($state)) {
            return $link;
        }

        $current = max(1, (int) self::query_var($wp_query, 'paged', self::query_var($wp_query, 'page', 1)));
        $target = self::frontend_search_page_from_link($link);
        if ($target === $current + 1) {
            $cursor = is_scalar($state['next_cursor'] ?? null) ? (string) $state['next_cursor'] : '';
            $direction = 'after';
        } elseif ($target === $current - 1) {
            $cursor = is_scalar($state['previous_cursor'] ?? null) ? (string) $state['previous_cursor'] : '';
            $direction = 'before';
        } else {
            return '#wp-fts-adjacent-cursor-only';
        }
        if ($cursor === '') {
            return '#wp-fts-adjacent-cursor-only';
        }

        return self::frontend_search_cursor_link($link, $cursor, $direction);
    }

    private static function frontend_search_page_from_link(string $link): int
    {
        $parts = parse_url(html_entity_decode($link, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (!is_array($parts)) {
            return 1;
        }
        $query = [];
        if (is_string($parts['query'] ?? null)) {
            parse_str($parts['query'], $query);
        }
        foreach (['paged', 'page'] as $key) {
            $value = $query[$key] ?? null;
            if (is_scalar($value) && strlen((string) $value) <= 20) {
                $page = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (is_int($page)) {
                    return $page;
                }
            }
        }

        $paginationBase = 'page';
        $rewrite = $GLOBALS['wp_rewrite'] ?? null;
        if (is_object($rewrite) && is_scalar($rewrite->pagination_base ?? null)) {
            $candidate = trim((string) $rewrite->pagination_base, '/');
            if ($candidate !== '') {
                $paginationBase = $candidate;
            }
        }
        $segments = explode('/', trim((string) ($parts['path'] ?? ''), '/'));
        $segmentCount = count($segments);
        for ($offset = 0; $offset + 1 < $segmentCount; $offset++) {
            if (rawurldecode($segments[$offset]) !== $paginationBase) {
                continue;
            }
            $candidate = rawurldecode($segments[$offset + 1]);
            if (strlen($candidate) > 20) {
                return 1;
            }
            $page = filter_var($candidate, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            return is_int($page) ? $page : 1;
        }

        // Core's first-page URL omits the pagination segment/query argument.
        return 1;
    }

    private static function frontend_search_cursor_link(string $link, string $cursor, string $direction): string
    {
        if (function_exists('add_query_arg')) {
            return add_query_arg([
                'wp_fts_cursor' => $cursor,
                'wp_fts_cursor_direction' => $direction,
            ], $link);
        }

        $separator = str_contains($link, '?') ? '&' : '?';
        return $link . $separator . http_build_query([
            'wp_fts_cursor' => $cursor,
            'wp_fts_cursor_direction' => $direction,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Render bounded adjacent navigation instead of wp-admin numeric offsets. */
    public static function render_admin_search_cursor_navigation(): void
    {
        global $wp_query;

        if (!is_object($wp_query)) {
            return;
        }
        $query_key = self::query_object_key($wp_query);
        $state = $query_key > 0 ? (self::$admin_post_search_query_state[$query_key] ?? null) : null;
        if (!is_array($state)) {
            return;
        }
        $page = max(1, (int) self::query_var($wp_query, 'paged', 1));
        $links = [];
        $previous = is_scalar($state['previous_cursor'] ?? null) ? (string) $state['previous_cursor'] : '';
        $next = is_scalar($state['next_cursor'] ?? null) ? (string) $state['next_cursor'] : '';
        if ($previous !== '') {
            $links[] = '<a class="button" href="' . self::esc_url(self::admin_search_cursor_url($previous, 'before', max(1, $page - 1))) . '">Previous FTS page</a>';
        }
        if ($next !== '') {
            $links[] = '<a class="button" href="' . self::esc_url(self::admin_search_cursor_url($next, 'after', $page + 1)) . '">Next FTS page</a>';
        }
        if ($links !== []) {
            echo '<span class="wp-fts-admin-cursor-navigation">' . implode(' ', $links) . '</span>';
        }
    }

    private static function admin_search_cursor_url(string $cursor, string $direction, int $page): string
    {
        $base = isset($_SERVER['REQUEST_URI']) && is_scalar($_SERVER['REQUEST_URI'])
            ? (string) $_SERVER['REQUEST_URI']
            : 'edit.php';
        $args = [
            'paged' => max(1, $page),
            'wp_fts_cursor' => $cursor,
            'wp_fts_cursor_direction' => $direction === 'before' ? 'before' : 'after',
        ];
        if (function_exists('add_query_arg')) {
            return (string) add_query_arg($args, $base);
        }

        $separator = str_contains($base, '?') ? '&' : '?';
        return $base . $separator . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
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
        try {
            return self::run_admin_post_search_replacement($posts, $query);
        } catch (Throwable $error) {
            self::clear_admin_post_search_replacement_state($query);
            self::debug_record_search_boundary_failure(
                'admin post search',
                $error,
                'The enabled FTS replacement failed closed without running core LIKE search.'
            );

            return self::admin_post_search_replacement_owns_shape($query)
                ? self::admin_post_search_fail_closed($posts, $query, 'runtime_failure')
                : $posts;
        }
    }

    private static function run_admin_post_search_replacement(mixed $posts, mixed $query): mixed
    {
        if (!self::should_replace_admin_post_search($query)) {
            if (self::admin_post_search_replacement_owns_shape($query)) {
                $settings = self::settings();
                if (self::should_preserve_prior_search_provider_result($posts, $settings)) {
                    return $posts;
                }

                return self::admin_post_search_fail_closed($posts, $query, 'unavailable_or_unbounded_page');
            }
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
            $result['has_more'],
            $result['next_cursor'],
            $result['previous_cursor'],
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

    private static function clear_frontend_search_replacement_state(mixed $query): void
    {
        try {
            $query_key = self::query_object_key($query);
            if ($query_key > 0) {
                unset(self::$front_end_search_query_state[$query_key]);
                self::debug_forget_search_final_ownership_query($query_key, $query);
            }
        } catch (Throwable) {
            // Cleanup must not replace the search boundary's chosen fallback.
        }
    }

    private static function clear_admin_post_search_replacement_state(mixed $query): void
    {
        try {
            $query_key = self::query_object_key($query);
            if ($query_key > 0) {
                unset(self::$admin_post_search_query_state[$query_key]);
                self::debug_forget_search_final_ownership_query($query_key, $query);
            }
        } catch (Throwable) {
            // Cleanup must not replace the search boundary's chosen fallback.
        }
    }

    private static function frontend_search_fail_closed(mixed $posts, mixed $query, string $reason): mixed
    {
        $settings = self::settings();
        if (self::should_preserve_prior_search_provider_result($posts, $settings)) {
            return $posts;
        }

        self::store_frontend_search_query_state($query, 0, 1, '', [], [], false, null, null);
        self::set_query_var($query, 'wp_fts_search_unavailable', $reason);

        return [];
    }

    private static function admin_post_search_fail_closed(mixed $posts, mixed $query, string $reason): mixed
    {
        $settings = self::settings();
        if (self::should_preserve_prior_search_provider_result($posts, $settings)) {
            return $posts;
        }

        self::store_admin_post_search_query_state($query, 0, 1, '', false, null, null);
        self::set_query_var($query, 'wp_fts_search_unavailable', $reason);

        return [];
    }

    private static function debug_record_search_boundary_failure(string $context, Throwable $error, string $outcome): void
    {
        try {
            $reason = self::sanitize_index_failure_text(
                get_class($error) . ': ' . $error->getMessage(),
                self::MAX_INDEX_DIAGNOSTIC_TEXT_BYTES
            );
            $reason = $reason !== '' ? 'FTS search failed: ' . $reason : 'FTS search failed.';

            $trace_id = 0;
            foreach (array_reverse(array_keys(self::$debug_traces)) as $candidate_id) {
                $trace = self::$debug_traces[$candidate_id] ?? [];
                if (($trace['context'] ?? '') === $context && ($trace['status'] ?? '') === 'started') {
                    $trace_id = (int) $candidate_id;
                    break;
                }
            }
            if ($trace_id <= 0) {
                $trace_id = self::debug_start_trace($context);
            }
            if ($trace_id > 0) {
                self::debug_add_notes($trace_id, [$outcome]);
                self::debug_finish_trace($trace_id, 'failed', $reason);
            }
        } catch (Throwable) {
            // Diagnostics must never replace the original fail-safe response.
        }
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

        return self::frontend_search_replacement_enabled($query)
            && !empty(self::search_takeover_status()['ready']);
    }

    private static function frontend_search_replacement_enabled(mixed $query): bool
    {
        $replace = self::settings()['replace_frontend_search'];
        if (function_exists('apply_filters')) {
            $replace = apply_filters(self::FRONTEND_SEARCH_REPLACEMENT_FILTER, $replace, $query);
        }

        if (is_bool($replace)) {
            $enabled = $replace;
        } elseif (is_scalar($replace)) {
            $enabled = !in_array(strtolower(trim((string) $replace)), ['', '0', 'false', 'no', 'off'], true);
        } else {
            $enabled = (bool) $replace;
        }

        return $enabled;
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

        return self::admin_post_search_replacement_enabled($query)
            && !empty(self::search_takeover_status()['ready']);
    }

    private static function admin_post_search_replacement_enabled(mixed $query): bool
    {
        $replace = self::settings()['replace_admin_post_search'];
        if (function_exists('apply_filters')) {
            $replace = apply_filters(self::ADMIN_POST_SEARCH_REPLACEMENT_FILTER, $replace, $query);
        }

        if (is_bool($replace)) {
            $enabled = $replace;
        } elseif (is_scalar($replace)) {
            $enabled = !in_array(strtolower(trim((string) $replace)), ['', '0', 'false', 'no', 'off'], true);
        } else {
            $enabled = (bool) $replace;
        }

        return $enabled;
    }

    /**
     * Whether enabled FTS owns this main search boundary.
     *
     * Unsupported constraints cannot fall through to WordPress's unindexed
     * LIKE/OFFSET path. The supported subset runs FTS; every other owned shape
     * fails closed. Only an explicit suppress_filters opt-out leaves core in
     * control.
     */
    private static function frontend_search_replacement_owns_shape(mixed $query): bool
    {
        return is_object($query)
            && !self::is_admin_request()
            && !self::is_rest_request()
            && !self::is_cron_request()
            && self::query_is_search($query)
            && self::query_is_main($query)
            && !self::query_var_truthy($query, 'suppress_filters')
            && self::frontend_search_replacement_enabled($query);
    }

    private static function admin_post_search_replacement_owns_shape(mixed $query): bool
    {
        return is_object($query)
            && self::is_admin_request()
            && !self::is_rest_request()
            && !self::is_cron_request()
            && self::is_admin_post_list_screen()
            && self::query_var($query, 'page', '') !== self::ADMIN_PAGE_SLUG
            && self::query_is_search($query)
            && self::query_is_main($query)
            && !self::query_var_truthy($query, 'suppress_filters')
            && self::admin_post_search_replacement_enabled($query);
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
            return 'Unsupported query shape: the enabled FTS boundary cannot represent these constraints and will fail closed.';
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

        $takeover = self::search_takeover_status();
        if (empty($takeover['ready'])) {
            return 'Search replacement is unavailable until initial indexing and schema readiness checks complete.';
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
            return 'Unsupported query shape: the enabled FTS boundary cannot represent these constraints and will fail closed.';
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

        $takeover = self::search_takeover_status();
        if (empty($takeover['ready'])) {
            return 'Search replacement is unavailable until initial indexing and schema readiness checks complete.';
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

        if (is_string($value) && strlen($value) > self::MAX_SEARCH_QUERY_BYTES) {
            // Preserve the original value without allocating a trimmed copy.
            // The shared search boundary raises the typed complexity error, and
            // the WordPress replacement catches it to fail closed without LIKE.
            return $value;
        }
        $value = (string) $value;
        if (strlen($value) > self::MAX_SEARCH_QUERY_BYTES) {
            return $value;
        }

        return trim($value);
    }

    private static function frontend_search_query_has_unsupported_constraints(mixed $query): bool
    {
        return self::search_query_has_unsupported_pagination($query)
            || self::search_query_has_unsupported_page_size($query)
            || self::frontend_search_query_has_unsupported_nonpagination_constraints($query);
    }

    private static function frontend_search_query_has_unsupported_nonpagination_constraints(mixed $query): bool
    {
        foreach (self::frontend_search_unsupported_constraint_vars() as $key) {
            if (self::query_var_has_constraint($query, $key)) {
                return true;
            }
        }

        if (self::search_query_has_unsupported_fields($query)) {
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

    /**
     * Numeric/deep offsets cannot be translated into bounded relational work.
     */
    private static function search_query_has_unsupported_pagination(mixed $query): bool
    {
        $offset = self::query_var($query, 'offset', null);
        if (is_string($offset) && strlen($offset) > self::MAX_SEARCH_NUMERIC_BYTES) {
            return true;
        }
        if (is_numeric($offset) && (int) $offset > 0) {
            return true;
        }

        $paged = self::query_var($query, 'paged', self::query_var($query, 'page', 1));
        if (is_string($paged) && strlen($paged) > self::MAX_SEARCH_NUMERIC_BYTES) {
            return true;
        }
        $page = is_numeric($paged) ? max(1, (int) $paged) : 1;
        $cursor = self::query_var($query, 'wp_fts_cursor', null);

        if ($page <= 1) {
            return false;
        }
        if (!is_scalar($cursor)) {
            return true;
        }
        if (strlen((string) $cursor) > self::MAX_SEARCH_CURSOR_BYTES) {
            // Keep the query owned by FTS so the replacement can reject the
            // cursor without falling back to an unbounded core LIKE search.
            return false;
        }

        return trim((string) $cursor) === '';
    }

    private static function admin_post_search_query_has_unsupported_constraints(mixed $query): bool
    {
        return self::search_query_has_unsupported_pagination($query)
            || self::search_query_has_unsupported_page_size($query)
            || self::admin_post_search_query_has_unsupported_nonpagination_constraints($query);
    }

    private static function admin_post_search_query_has_unsupported_nonpagination_constraints(mixed $query): bool
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

        if (self::search_query_has_unsupported_fields($query)) {
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
        if (self::constraint_value_present($perm) && (!is_scalar($perm) || trim((string) $perm) !== 'readable')) {
            return true;
        }

        $statuses = self::admin_post_search_statuses($query);
        return !self::current_user_can_search_post_type_statuses(
            self::admin_post_search_post_types($query),
            $statuses,
            false
        );
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

    private static function search_query_has_unsupported_fields(mixed $query): bool
    {
        $fields = self::query_var($query, 'fields', null);
        if (!self::constraint_value_present($fields)) {
            return false;
        }

        return !is_scalar($fields)
            || strlen((string) $fields) > self::MAX_SEARCH_SCOPE_VALUE_BYTES
            || trim((string) $fields) !== 'all';
    }

    private static function search_query_has_unsupported_page_size(mixed $query): bool
    {
        $postsPerPage = self::query_var($query, 'posts_per_page', null);
        if (is_string($postsPerPage) && strlen($postsPerPage) > self::MAX_SEARCH_NUMERIC_BYTES) {
            return true;
        }
        if (!is_numeric($postsPerPage)) {
            return false;
        }

        $postsPerPage = (int) $postsPerPage;

        return $postsPerPage <= 0 || $postsPerPage > self::MAX_SEARCH_LIMIT;
    }

    /**
     * Preserve FTS score ordering only when the query asks for normal relevance.
     */
    private static function frontend_search_has_unsupported_ordering(mixed $query): bool
    {
        $orderby = self::query_var($query, 'orderby', null);
        if (self::constraint_value_present($orderby)) {
            if (
                !is_scalar($orderby)
                || strlen((string) $orderby) > self::MAX_SEARCH_SCOPE_VALUE_BYTES
                || strtolower(trim((string) $orderby)) !== 'relevance'
            ) {
                return true;
            }
        }

        $order = self::query_var($query, 'order', null);
        if (!self::constraint_value_present($order)) {
            return false;
        }

        return !is_scalar($order)
            || strlen((string) $order) > self::MAX_SEARCH_SCOPE_VALUE_BYTES
            || strtoupper(trim((string) $order)) !== 'DESC';
    }

    /**
     * Replace only queries whose complete core post-type scope is indexed.
     */
    private static function frontend_search_has_unsupported_post_types(mixed $query): bool
    {
        $requested = self::query_var($query, 'post_type', null);
        if ($requested === null || $requested === '' || $requested === 'any') {
            $expected = self::wordpress_any_search_post_types();
        } else {
            $expected = self::normalize_string_list($requested);
        }

        $supported = self::frontend_query_post_types($query);
        sort($expected, SORT_STRING);
        sort($supported, SORT_STRING);

        return $expected !== $supported;
    }

    /**
     * Return the complete post-type scope WordPress assigns to post_type=any.
     *
     * @return string[]
     */
    private static function wordpress_any_search_post_types(): array
    {
        if (!function_exists('get_post_types')) {
            return self::public_searchable_post_types();
        }

        $raw = get_post_types(['exclude_from_search' => false], 'names');
        if (!is_array($raw)) {
            return self::public_searchable_post_types();
        }

        $types = [];
        $examined = 0;
        foreach ($raw as $key => $value) {
            if (++$examined > self::MAX_SEARCH_SCOPE_VALUES) {
                $types[self::UNSUPPORTED_SCOPE_SENTINEL] = true;
                break;
            }
            $raw_type = is_scalar($value) ? (string) $value : (is_scalar($key) ? (string) $key : '');
            if (strlen($raw_type) > self::MAX_SEARCH_SCOPE_VALUE_BYTES) {
                $types[self::UNSUPPORTED_SCOPE_SENTINEL] = true;
                break;
            }
            $type = trim($raw_type);
            if ($type !== '') {
                $types[$type] = true;
            }
        }

        return array_keys($types);
    }

    private static function query_var_has_constraint(mixed $query, string $key): bool
    {
        return self::constraint_value_present(self::query_var($query, $key, null));
    }

    private static function constraint_value_present(mixed $value): bool
    {
        $remaining = self::MAX_QUERY_CONSTRAINT_NODES;

        return self::bounded_constraint_value_present($value, 0, $remaining);
    }

    private static function bounded_constraint_value_present(mixed $value, int $depth, int &$remaining): bool
    {
        if ($depth > self::MAX_QUERY_CONSTRAINT_DEPTH || --$remaining < 0) {
            return true;
        }
        if ($value === null || $value === false) {
            return false;
        }

        if (is_array($value)) {
            if (count($value) > self::MAX_QUERY_CONSTRAINT_NODES) {
                return true;
            }
            foreach ($value as $item) {
                if (self::bounded_constraint_value_present($item, $depth + 1, $remaining)) {
                    return true;
                }
            }

            return false;
        }

        if (is_string($value)) {
            if (strlen($value) > self::MAX_SEARCH_SCOPE_VALUE_BYTES) {
                return true;
            }
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
        $limit = min(self::MAX_SEARCH_LIMIT, self::frontend_query_limit($query));
        $cursor_options = [];
        $cursor = self::query_var($query, 'wp_fts_cursor', null);
        if ($cursor !== null) {
            $cursor_options = self::normalize_public_search_options([
                'cursor' => $cursor,
                'direction' => self::search_cursor_direction(
                    self::query_var($query, 'wp_fts_cursor_direction', 'after')
                ),
            ]);
        }
        if ($post_types === [] || $post_statuses === []) {
            self::assert_search_cursor_authenticity($cursor_options);
            self::debug_add_timing($trace_id, 'total', $trace_started);
            self::debug_finish_trace($trace_id, 'bailed', 'Unsupported query shape: no searchable post types or statuses are available.');
            return [
                'posts' => [],
                'snippets' => [],
                'titles' => [],
                'total' => 0,
                'limit' => $limit,
                'query_lang' => '',
                'has_more' => false,
                'next_cursor' => null,
                'previous_cursor' => null,
            ];
        }

        $settings ??= self::settings();
        $build_frontend_previews = $visibility_context === 'frontend';
        $search_options = [
            'mode' => $settings['match_mode'],
            'limit' => $limit,
            'include_metadata' => true,
            'include_snippets' => $build_frontend_previews,
            'highlight' => $build_frontend_previews && !empty($settings['highlight']),
            'snippet_length' => $settings['snippet_length'],
            'prefix_matching' => $settings['prefix_matching'],
            'post_types' => $post_types,
            'post_statuses' => $post_statuses,
        ] + self::searcher_prefix_threshold_options($settings) + $cursor_options;
        $explicit_language = self::query_var($query, 'wp_fts_lang', null);
        if (is_scalar($explicit_language)) {
            $raw_language = (string) $explicit_language;
            if (strlen($raw_language) > self::MAX_SEARCH_LANGUAGE_BYTES) {
                $search_options['lang'] = $raw_language;
            } elseif (trim($raw_language) !== '') {
                $search_options['lang'] = WP_FTS_TermNamespace::canonicalize_lang($raw_language);
            }
        }
        $search_started = microtime(true);
        $payload = self::search_visible_payload($search_query, $search_options, $trace_id > 0, true);
        self::debug_add_timing($trace_id, 'storage/search', $search_started);
        self::debug_set_search_explain($trace_id, $payload['explain'] ?? null);
        $rows = is_array($payload['results'] ?? null) ? $payload['results'] : [];
        self::debug_add_count($trace_id, 'search_batches');
        self::debug_add_count($trace_id, 'ranked_page_rows', count($rows));

        $ids = [];
        $rows_by_id = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $post_id = max(0, (int) ($row['doc_id'] ?? $row['post_id'] ?? 0));
            if ($post_id <= 0 || isset($rows_by_id[$post_id])) {
                continue;
            }
            $ids[] = $post_id;
            $rows_by_id[$post_id] = $row;
        }

        $hydrate_started = microtime(true);
        $posts = self::hydrate_search_posts($ids, $rows_by_id);
        self::debug_add_timing($trace_id, 'page hydration', $hydrate_started);
        $snippets = [];
        $titles = [];
        foreach ($posts as $post) {
            $post_id = self::post_id_from_value($post);
            $snippet = $rows_by_id[$post_id]['snippet'] ?? null;
            if ($build_frontend_previews && is_scalar($snippet) && trim((string) $snippet) !== '') {
                $snippets[$post_id] = self::sanitize_frontend_snippet_html((string) $snippet);
                self::debug_add_count($trace_id, 'snippets_reused');
            }
            $highlighted_title = $rows_by_id[$post_id]['highlighted_title'] ?? null;
            if ($build_frontend_previews && is_scalar($highlighted_title) && trim((string) $highlighted_title) !== '') {
                $titles[$post_id] = self::sanitize_frontend_snippet_html((string) $highlighted_title);
            }
        }

        $query_lang = is_scalar($payload['query_lang'] ?? null)
            ? WP_FTS_TermNamespace::canonicalize_lang((string) $payload['query_lang'])
            : '';
        $has_more = !empty($payload['has_more']);
        $lower_bound = count($posts) + ($has_more ? 1 : 0);
        self::debug_set_counts($trace_id, [
            'result_ids_returned' => count($posts),
            'visible_results' => count($posts),
        ]);
        self::debug_set_query_language($trace_id, $query_lang);
        self::debug_add_notes($trace_id, [
            $visibility_context === 'admin'
                ? 'FTS replacement ran one set-oriented page for wp-admin post search.'
                : 'FTS replacement ran one set-oriented page for frontend search.',
            'The interactive total is intentionally unknown; found_posts is a lower bound for adjacent navigation.',
        ]);
        self::debug_add_timing($trace_id, 'total', $trace_started);
        self::debug_finish_trace($trace_id, 'ran');

        return [
            'posts' => $posts,
            'snippets' => $snippets,
            'titles' => $titles,
            'total' => $lower_bound,
            'limit' => $limit,
            'query_lang' => $query_lang,
            'has_more' => $has_more,
            'next_cursor' => is_scalar($payload['next_cursor'] ?? null) ? (string) $payload['next_cursor'] : null,
            'previous_cursor' => is_scalar($payload['previous_cursor'] ?? null) ? (string) $payload['previous_cursor'] : null,
        ];
    }

    /**
     * Build WP_Post objects from the canonical rows already carried by the
     * bounded storage hydration statement.
     *
     * @param int[] $post_ids
     * @param array<int,array<string,mixed>> $rows_by_id
     * @return object[]
     */
    private static function hydrate_search_posts(array $post_ids, array $rows_by_id): array
    {
        $posts = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            $canonical_row = $rows_by_id[$post_id]['_canonical_post_row'] ?? null;
            if ($post_id <= 0 || !is_array($canonical_row)) {
                continue;
            }
            $row = (object) $canonical_row;
            if ((int) ($row->ID ?? 0) !== $post_id) {
                continue;
            }
            $posts[] = class_exists('WP_Post') ? new WP_Post($row) : $row;
        }

        return $posts;
    }

    private static function search_cursor_direction(mixed $value): string
    {
        if (!is_scalar($value) || strlen((string) $value) > self::MAX_SEARCH_MODE_BYTES) {
            return 'after';
        }
        $direction = strtolower(trim((string) $value));

        return $direction === 'before' ? 'before' : 'after';
    }

    /**
     * @param array<int,string> $snippets
     * @param array<int,string> $titles
     */
    private static function store_frontend_search_query_state(
        mixed $query,
        int $total,
        int $limit,
        string $query_lang,
        array $snippets,
        array $titles,
        bool $has_more,
        ?string $next_cursor,
        ?string $previous_cursor,
        int $trace_id = 0
    ): void
    {
        $current_page = max(1, (int) self::query_var($query, 'paged', self::query_var($query, 'page', 1)));
        $total = $total > 0 ? (($current_page - 1) * max(1, $limit)) + $total : 0;
        // `has_more` describes the direction that produced this page. After a
        // reverse query reaches page one it is false, but next_cursor still
        // authoritatively returns to page two. Derive the forward navigation
        // boundary from that cursor instead of stranding the restored page.
        $has_forward_page = is_string($next_cursor) && $next_cursor !== '';
        if ($total > 0 && $has_forward_page) {
            $total = max($total, ($current_page * max(1, $limit)) + 1);
        }
        $max_pages = $total > 0 ? $current_page + ($has_forward_page ? 1 : 0) : 0;
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
                    'has_more' => $has_more,
                    'next_cursor' => $next_cursor,
                    'previous_cursor' => $previous_cursor,
                    'total_relation' => 'unknown',
                    'trace_id' => $trace_id,
                ],
            ];
        }

        // WP_Query requires an integer here. This is only a cursor-page lower
        // bound; wp_fts_total_relation explicitly prevents consumers from
        // treating it as an exhaustive match count.
        self::set_query_property($query, 'found_posts', $total);
        self::set_query_property($query, 'max_num_pages', $max_pages);
        self::set_query_var($query, 'wp_fts_query_lang', $query_lang);
        self::set_query_var($query, 'wp_fts_found_posts', $total);
        self::set_query_var($query, 'wp_fts_total_relation', 'unknown');
        self::set_query_var($query, 'wp_fts_next_cursor', $next_cursor);
        self::set_query_var($query, 'wp_fts_previous_cursor', $previous_cursor);
    }

    private static function store_admin_post_search_query_state(
        mixed $query,
        int $total,
        int $limit,
        string $query_lang,
        bool $has_more,
        ?string $next_cursor,
        ?string $previous_cursor,
        int $trace_id = 0
    ): void
    {
        $current_page = max(1, (int) self::query_var($query, 'paged', 1));
        $total = $total > 0 ? (($current_page - 1) * max(1, $limit)) + $total : 0;
        // wp-admin's native paginator emits arbitrary numeric offsets. Suppress
        // it and render only the cursor-backed adjacent links above.
        $max_pages = $total > 0 ? 1 : 0;
        $query_key = self::query_object_key($query);
        if ($query_key > 0) {
            self::$admin_post_search_query_state = [
                $query_key => [
                    'total' => $total,
                    'max_pages' => $max_pages,
                    'query_lang' => $query_lang,
                    'has_more' => $has_more,
                    'next_cursor' => $next_cursor,
                    'previous_cursor' => $previous_cursor,
                    'total_relation' => 'unknown',
                    'trace_id' => $trace_id,
                ],
            ];
        }

        // WP_Query requires an integer here. This is only a cursor-page lower
        // bound; wp_fts_total_relation explicitly prevents consumers from
        // treating it as an exhaustive match count.
        self::set_query_property($query, 'found_posts', $total);
        self::set_query_property($query, 'max_num_pages', $max_pages);
        self::set_query_var($query, 'wp_fts_query_lang', $query_lang);
        self::set_query_var($query, 'wp_fts_found_posts', $total);
        self::set_query_var($query, 'wp_fts_total_relation', 'unknown');
        self::set_query_var($query, 'wp_fts_next_cursor', $next_cursor);
        self::set_query_var($query, 'wp_fts_previous_cursor', $previous_cursor);
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
                $examined = 0;
                foreach ($raw as $key => $value) {
                    if (++$examined > self::MAX_SEARCH_SCOPE_VALUES) {
                        break;
                    }
                    $type = is_scalar($value) ? (string) $value : (is_scalar($key) ? (string) $key : '');
                    if (
                        $type !== ''
                        && strlen($type) <= self::MAX_SEARCH_SCOPE_VALUE_BYTES
                        && self::is_public_searchable_post_type($type)
                    ) {
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
     * @return string[]
     */
    private static function normalize_string_list(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        if (count($items) > self::MAX_SEARCH_SCOPE_VALUES) {
            return [self::UNSUPPORTED_SCOPE_SENTINEL];
        }
        $result = [];
        $input_bytes = 0;
        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = (string) $item;
            $input_bytes += strlen($item);
            if ($input_bytes > self::MAX_SEARCH_SCOPE_BYTES) {
                return [self::UNSUPPORTED_SCOPE_SENTINEL];
            }
            foreach (explode(',', $item) as $part) {
                if (strlen($part) > self::MAX_SEARCH_SCOPE_VALUE_BYTES) {
                    return [self::UNSUPPORTED_SCOPE_SENTINEL];
                }
                $part = trim($part);
                if ($part !== '') {
                    $result[$part] = true;
                    if (count($result) > self::MAX_SEARCH_SCOPE_VALUES) {
                        return [self::UNSUPPORTED_SCOPE_SENTINEL];
                    }
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

    /** Persist one post mutation before its foreground hook returns. */
    private static function queue_post(int $post_id, bool $release_metadata_fence = false): void
    {
        $identity = 'post:' . $post_id;
        $fence = self::$mutation_fence_tokens[$identity] ?? null;
        self::remember_foreground_mutation_target(
            $identity,
            $post_id,
            false,
            $fence === null && self::foreground_mutation_target_is_retained($identity)
        );
        if (self::$foreground_bulk_mutation_scope !== null) {
            // The request-global fence already hides every old projection. The
            // shutdown handoff records this retained id without per-hook SQL.
            return;
        }
        if ($fence === null) {
            self::queue_posts([$post_id]);
            return;
        }
        if (!$release_metadata_fence && isset(self::$post_meta_fenced_posts[$post_id])) {
            // Metadata pins the shared post fence until shutdown so any number
            // of sequential metadata API calls costs one pre/post SQL pair.
            // A nested non-metadata lifecycle still consumes its own depth.
            if ($fence['depth'] > 1) {
                self::$mutation_fence_tokens[$identity]['depth']--;
            }
            return;
        }
        if ($fence['depth'] > 1) {
            self::$mutation_fence_tokens[$identity]['depth']--;
            return;
        }

        if (self::persist_foreground_post_mutation_promotion($post_id, $fence['token'])) {
            self::remember_foreground_ready_work();
            unset(self::$mutation_fence_tokens[$identity]);
            if ((int) ($fence['expires_at'] ?? 0) <= time()) {
                // A worker that met the finite deadline while this request was
                // still alive may have moved its watchdog five minutes out.
                self::schedule_queue_processor(1, true);
            }
        }
    }

    /** Install or renew one durable dirty generation before canonical SQL. */
    private static function fence_post_mutation(int $post_id): bool
    {
        if (self::$foreground_queue_writes_disabled) {
            return false;
        }
        $identity = 'post:' . $post_id;
        if (!self::refresh_post_mutation_fence($post_id)) {
            return false;
        }
        if (isset(self::$mutation_fence_tokens[$identity])) {
            self::$mutation_fence_tokens[$identity]['depth']++;
            return self::foreground_mutation_target_is_retained($identity);
        }

        $retained = self::remember_foreground_mutation_target(
            $identity,
            $post_id,
            false,
            self::foreground_mutation_target_is_retained($identity)
        );
        if (self::$foreground_queue_writes_disabled) {
            return false;
        }
        if (self::$foreground_bulk_mutation_scope !== null) {
            return $retained;
        }

        try {
            $queue = self::foreground_index_queue();
            $mutation_token = self::foreground_mutation_token($queue);
            $available_at = self::mutation_fence_available_at();
            $queue->fence_post(
                $post_id,
                $mutation_token,
                $available_at
            );
            self::$mutation_fence_tokens[$identity] = [
                'token' => $mutation_token,
                'depth' => 1,
                'expires_at' => $available_at,
            ];
            self::schedule_queue_processor(1, true);
        } catch (Throwable $error) {
            self::disable_foreground_queue_writes($error);
        }

        return $retained;
    }

    /** Renew an abandoned request token without changing same-request nesting. */
    private static function refresh_post_mutation_fence(int $post_id): bool
    {
        if (!self::refresh_expired_foreground_bulk_fence()) {
            return false;
        }
        if (self::$foreground_bulk_mutation_scope !== null) {
            return true;
        }
        $identity = 'post:' . $post_id;
        $current = self::$mutation_fence_tokens[$identity] ?? null;
        if (!is_array($current) || (int) ($current['expires_at'] ?? 0) > time()) {
            return true;
        }
        if (self::$foreground_queue_writes_disabled) {
            return false;
        }

        // The request-wide file guard remains authoritative after the finite
        // watchdog deadline, so an arbitrarily long exact mutation needs no
        // heartbeat, global escalation, or additional database statement.
        if (self::foreground_owner_guard_is_current()) {
            return true;
        }

        // Re-enter the bulk boundary so the lost capability disables further
        // queue writes. It must never replace the finite guarded row with an
        // ownerless generation.
        self::$foreground_owner_guard_attempted = false;
        return self::activate_foreground_bulk_mutation_scope();
    }

    /**
     * Retain a bounded request mutation frontier and install its global fence.
     *
     * The first two same-target lifecycles keep cheap targeted boundaries. A
     * second distinct target, or a third lifecycle for the same target,
     * crosses one request-unique global boundary before further canonical SQL.
     * From that point all hooks are in-memory only until shutdown.
     */
    private static function remember_foreground_mutation_target(
        string $identity,
        ?int $post_id,
        bool $scope,
        bool $force_bulk
    ): bool {
        $prefix = self::current_database_prefix();
        if (
            self::$foreground_mutation_prefix !== null
            && !hash_equals(self::$foreground_mutation_prefix, $prefix)
        ) {
            self::abandon_foreground_mutations();
        }
        self::$foreground_mutation_prefix = $prefix;

        $known = isset(self::$foreground_mutation_targets[$identity]);
        if ($scope) {
            self::$foreground_mutation_has_scope = true;
        }

        if ($known && $force_bulk && self::$foreground_bulk_mutation_scope === null) {
            $repeats = (self::$foreground_mutation_repeat_boundaries[$identity] ?? 0) + 1;
            self::$foreground_mutation_repeat_boundaries[$identity] = min(2, $repeats);
            $force_bulk = $repeats >= 2;
        }
        if (
            self::$foreground_bulk_mutation_scope === null
            && (($known && $force_bulk) || (!$known && self::$foreground_mutation_targets !== []))
        ) {
            self::activate_foreground_bulk_mutation_scope();
        }

        if (self::$foreground_bulk_mutation_scope !== null && $scope) {
            self::require_foreground_corpus_reconciliation();
        }
        if ($known) {
            return true;
        }
        if (count(self::$foreground_mutation_targets) >= self::MAX_FOREGROUND_MUTATION_TARGETS) {
            if (self::$foreground_bulk_mutation_scope !== null) {
                self::$foreground_bulk_mutation_scope['overflow'] = true;
                self::require_foreground_corpus_reconciliation();
            }
            return false;
        }

        self::$foreground_mutation_targets[$identity] = true;
        if ($post_id !== null && $post_id > 0) {
            self::$foreground_mutation_posts[$post_id] = true;
        }

        return true;
    }

    private static function foreground_mutation_target_is_retained(string $identity): bool
    {
        return isset(self::$foreground_mutation_targets[$identity]);
    }

    /** Install the one request-global visibility fence without recursing hooks. */
    private static function activate_foreground_bulk_mutation_scope(): bool
    {
        if (self::$foreground_bulk_mutation_scope !== null) {
            return true;
        }
        if (
            self::$foreground_queue_writes_disabled
            || self::$foreground_bulk_activation_attempted
        ) {
            return false;
        }
        self::$foreground_bulk_activation_attempted = true;

        try {
            $queue = self::foreground_index_queue();
            $scope_key = 'foreground-bulk:' . bin2hex(random_bytes(16));
            $mutation_token = self::foreground_mutation_token($queue);
            $available_at = self::mutation_fence_available_at();
            $queue->fence_scope(
                $scope_key,
                $mutation_token,
                ['reason' => 'foreground_bulk_mutation'],
                $available_at,
                WP_FTS_Index_Queue::SCOPE_COVERAGE_GLOBAL
            );
        } catch (Throwable $error) {
            self::disable_foreground_queue_writes($error);
            return false;
        }

        $identity = 'scope:' . hash('sha256', $scope_key);
        self::$mutation_fence_tokens[$identity] = [
            'token' => $mutation_token,
            'depth' => 1,
            'expires_at' => $available_at,
        ];
        self::$foreground_bulk_mutation_scope = [
            'scope_key' => $scope_key,
            'token' => $mutation_token,
            'expires_at' => $available_at,
            'overflow' => false,
            'requires_corpus' => self::$foreground_mutation_has_scope,
            'pending_marked' => false,
            'incarnation' => '',
            'profile_hash' => '',
        ];

        if (self::$foreground_mutation_has_scope) {
            self::require_foreground_corpus_reconciliation();
        }
        self::schedule_queue_processor(1, true);

        return true;
    }

    /** Preserve one finite global boundary across a long-running request. */
    private static function refresh_expired_foreground_bulk_fence(): bool
    {
        $bulk = self::$foreground_bulk_mutation_scope;
        if ($bulk === null) {
            return true;
        }
        if (self::$foreground_queue_writes_disabled) {
            return false;
        }

        if (self::foreground_owner_guard_is_current()) {
            // The worker checks the request-wide shared file guard after the
            // finite deadline. No heartbeat or re-fence query is needed.
            return true;
        }
        if ((int) ($bulk['expires_at'] ?? 0) > time()) {
            return true;
        }

        self::$foreground_owner_guard_attempted = false;
        try {
            $scope_key = (string) ($bulk['scope_key'] ?? '');
            $queue = self::foreground_index_queue();
            $mutation_token = self::foreground_mutation_token($queue);
            $available_at = self::mutation_fence_available_at();
            $queue->fence_scope(
                $scope_key,
                $mutation_token,
                ['reason' => 'foreground_bulk_mutation_owner_reconnected'],
                $available_at,
                WP_FTS_Index_Queue::SCOPE_COVERAGE_GLOBAL
            );
            $identity = 'scope:' . hash('sha256', $scope_key);
            self::$mutation_fence_tokens[$identity] = [
                'token' => $mutation_token,
                'depth' => max(1, (int) (self::$mutation_fence_tokens[$identity]['depth'] ?? 1)),
                'expires_at' => $available_at,
            ];
            self::$foreground_bulk_mutation_scope['token'] = $mutation_token;
            self::$foreground_bulk_mutation_scope['expires_at'] = $available_at;
            self::schedule_queue_processor(1, true);
            return true;
        } catch (Throwable $error) {
            self::disable_foreground_queue_writes($error);
            return false;
        }
    }

    /** Mark a finite fence as auto-recoverable only while its shared guard is held. */
    private static function foreground_mutation_token(WP_FTS_Index_Queue $queue): string
    {
        $token = bin2hex(random_bytes(16));
        if (!self::ensure_foreground_owner_guard($queue)) {
            throw new RuntimeException('The FTS foreground owner guard is unavailable.');
        }

        return 'guard:' . $token;
    }

    /** Acquire the one site-scoped shared guard before canonical mutation. */
    private static function ensure_foreground_owner_guard(WP_FTS_Index_Queue $queue): bool
    {
        $hadOwner = self::$foreground_owner_guard !== null;
        if (self::foreground_owner_guard_is_current()) {
            return true;
        }
        if ($hadOwner) {
            // A replaced inode invalidates the capability for this request. Do
            // not reacquire the replacement and mark a later boundary guarded:
            // a worker may already have recovered work through that inode.
            self::release_foreground_owner_guard(false);
            self::$foreground_owner_guard_attempted = true;
            return false;
        }
        if (self::$foreground_owner_guard_attempted) {
            return false;
        }
        self::$foreground_owner_guard_attempted = true;

        try {
            self::$foreground_owner_guard = [
                'queue' => $queue,
                'guard' => $queue->acquire_foreground_owner_guard(),
            ];
            return true;
        } catch (WP_FTS_Foreground_Owner_Guard_Busy $error) {
            // Lifecycle cleanup owns the exclusive side. The caller must stop
            // before any queue, option, or cron write and let uninstall finish.
            throw $error;
        } catch (Throwable $error) {
            // Search must use WordPress core until the path is repaired and a
            // quiesced operator reset starts a fresh generation.
            self::latch_foreground_owner_guard_unavailable($error);
            return false;
        }
    }

    private static function foreground_owner_guard_is_current(): bool
    {
        $owner = self::$foreground_owner_guard;
        $queue = $owner['queue'] ?? null;
        $guard = $owner['guard'] ?? null;
        if (!$queue instanceof WP_FTS_Index_Queue || !is_array($guard)) {
            return false;
        }

        try {
            $current = $queue->foreground_owner_guard_is_current($guard);
            if (!$current) {
                self::latch_foreground_owner_guard_unavailable(
                    new RuntimeException('The FTS foreground owner guard inode changed after acquisition.')
                );
            }
            return $current;
        } catch (Throwable $error) {
            self::latch_foreground_owner_guard_unavailable($error);
            return false;
        }
    }

    /** Ask guard release to publish a successor before dropping its capability. */
    private static function remember_foreground_ready_work(): void
    {
        if (self::foreground_owner_guard_is_current()) {
            self::$foreground_owner_guard_has_ready_work = true;
        }
    }

    /** Disable takeover when the shared owner-guard filesystem is unavailable. */
    private static function latch_foreground_owner_guard_unavailable(?Throwable $cause = null): void
    {
        if (self::$foreground_owner_guard_failure_latched) {
            return;
        }
        self::$foreground_owner_guard_failure_latched = true;
        $message = 'The shared FTS foreground owner guard is unavailable.';
        if ($cause !== null && $cause->getMessage() !== '') {
            $message .= ' ' . $cause->getMessage();
        }

        try {
            self::latch_search_runtime_failure(new RuntimeException($message, 0, $cause), true);
        } catch (Throwable) {
            // Canonical WordPress writes must outlive unavailable diagnostics.
            // Queue mutation has already stopped before this failure path.
        }
    }

    private static function release_foreground_owner_guard(bool $schedule_ready_work = true): void
    {
        $owner = self::$foreground_owner_guard;
        $scheduleReadyWork = $schedule_ready_work
            && $owner !== null
            && self::$foreground_owner_guard_has_ready_work;
        try {
            if ($scheduleReadyWork) {
                // The ready row and its successor are both durable before the
                // shared capability is released. A later uninstall acquires
                // the exclusive side and clears the event after both writes.
                self::schedule_queue_processor(1, true);
            }
        } catch (Throwable) {
            // The durable ready generation remains recoverable by the existing
            // watchdog even when cron scheduling is unavailable.
        } finally {
            self::$foreground_owner_guard = null;
            self::$foreground_owner_guard_attempted = false;
            self::$foreground_owner_guard_has_ready_work = false;
            try {
                self::release_foreground_owner_guard_record($owner);
            } catch (Throwable) {
                // Process exit closes the descriptor.
            }
        }
    }

    /** @param array<string,mixed>|null $owner */
    private static function release_foreground_owner_guard_record(?array $owner): void
    {
        $queue = $owner['queue'] ?? null;
        $guard = $owner['guard'] ?? null;
        if (!$queue instanceof WP_FTS_Index_Queue || !is_array($guard)) {
            return;
        }
        try {
            $queue->release_foreground_owner_guard($guard);
        } catch (Throwable) {
            // Process exit closes the descriptor. The finite durable deadline
            // remains the recovery authority for any other cleanup failure.
        }
    }

    /** Escalate an active exact-post handoff to one corpus reconciliation. */
    private static function require_foreground_corpus_reconciliation(): void
    {
        if (self::$foreground_bulk_mutation_scope === null) {
            return;
        }
        self::$foreground_bulk_mutation_scope['requires_corpus'] = true;
        // The one global fence now subsumes every per-key pre/post marker.
        // Clearing them prevents direct hook fan-out from retaining one entry
        // per metadata key or taxonomy term for the rest of the request.
        self::$post_meta_global_mutations = [];
        self::$taxonomy_term_global_pre_boundaries = [];
        if (self::$foreground_bulk_mutation_scope['pending_marked']) {
            return;
        }
        self::$foreground_bulk_mutation_scope['pending_marked'] = true;
        try {
            $profile_hash = self::current_index_profile_hash();
            self::$foreground_bulk_mutation_scope['profile_hash'] = $profile_hash;
            self::$foreground_bulk_mutation_scope['incarnation'] = self::mark_initial_index_pending(
                true,
                $profile_hash
            );
        } catch (Throwable $error) {
            self::remember_foreground_queue_failure($error);
        }
    }

    /**
     * Persist exactly the post ids supplied by one caller before it returns.
     *
     * Foreground hooks call this with one id, so each canonical mutation pays
     * one indexed UPSERT and a killed request cannot lose an in-memory suffix.
     * Callers that already own a bounded batch retain one set-oriented UPSERT.
     *
     * @param array<int,mixed> $post_ids
     * @return int Number of unique positive ids durably enqueued.
     */
    private static function queue_posts(array $post_ids, ?int $available_at = null): int
    {
        if (count($post_ids) > WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS) {
            throw new InvalidArgumentException('FTS invalidation accepts at most 1,000 post ids.');
        }
        $accepted = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0) {
                $accepted[$post_id] = true;
            }
        }

        if ($accepted === []) {
            return 0;
        }

        $ids = array_keys($accepted);
        return self::persist_foreground_post_mutations($ids, $available_at) ? count($ids) : 0;
    }

    /** Persist a direct foreground generation without failing the WordPress write. */
    private static function persist_foreground_post_mutations(array $post_ids, ?int $available_at = null): bool
    {
        if (self::$foreground_queue_writes_disabled) {
            return false;
        }
        try {
            self::foreground_index_queue()->enqueue_many($post_ids, $available_at);
            self::schedule_queue_processor(
                $available_at === null ? 60 : max(1, $available_at - time()),
                true
            );
            return true;
        } catch (Throwable $error) {
            self::disable_foreground_queue_writes($error);
            return false;
        }
    }

    /** Promote the exact foreground generation installed by its pre hook. */
    private static function persist_foreground_post_mutation_promotion(int $post_id, string $mutation_token): bool
    {
        if (self::$foreground_queue_writes_disabled) {
            return false;
        }
        try {
            self::foreground_index_queue()->promote_post($post_id, $mutation_token);
            return true;
        } catch (Throwable $error) {
            self::disable_foreground_queue_writes($error);
            return false;
        }
    }

    private static function mutation_fence_available_at(): int
    {
        return time() + self::MUTATION_FENCE_SECONDS;
    }

    /** Count active relationship lifecycle hooks to distinguish nesting from fan-out. */
    private static function relationship_hook_nesting_depth(): int
    {
        global $wp_current_filter;

        if (!isset($wp_current_filter) || !is_array($wp_current_filter)) {
            return 0;
        }
        $relationship_hooks = [
            'add_term_relationship' => true,
            'delete_term_relationships' => true,
            'set_object_terms' => true,
            'deleted_term_relationships' => true,
        ];
        $depth = 0;
        foreach ($wp_current_filter as $hook) {
            if (is_scalar($hook) && isset($relationship_hooks[(string) $hook])) {
                $depth++;
            }
        }

        return $depth;
    }

    private static function current_relationship_hook(): string
    {
        global $wp_current_filter;

        if (!isset($wp_current_filter) || !is_array($wp_current_filter) || $wp_current_filter === []) {
            return '';
        }
        $hook = end($wp_current_filter);

        return is_scalar($hook) ? (string) $hook : '';
    }

    /** Resolve the current durable queue only after the logical schema is ready. */
    private static function foreground_index_queue(): WP_FTS_Index_Queue
    {
        $queue = self::foreground_capability_queue();
        if (!self::option_matches_schema_version(self::get_option(self::SCHEMA_VERSION_OPTION, null))) {
            // Schema absence is exceptional, so its indexed fence probe does
            // not tax a normal canonical write. It prevents a request that
            // resumes after completed uninstall from recreating cron work.
            if (self::uninstall_fence_active()) {
                self::$foreground_queue_blocked_prefixes[self::current_database_prefix()] = true;
                throw new RuntimeException('FTS foreground queue writes are blocked by the uninstall fence.');
            }
            self::schedule_schema_provisioning();
            throw new RuntimeException('FTS schema maintenance is pending.');
        }

        return $queue;
    }

    /** Acquire a direct/operator queue only after checking the durable fence. */
    private static function foreground_lifecycle_checked_index_queue(): WP_FTS_Index_Queue
    {
        $queue = self::foreground_capability_queue();
        if (self::uninstall_fence_active()) {
            self::$foreground_queue_blocked_prefixes[self::current_database_prefix()] = true;
            throw new RuntimeException('FTS reindexing is blocked until the plugin is explicitly activated again.');
        }
        if (!self::option_matches_schema_version(self::get_option(self::SCHEMA_VERSION_OPTION, null))) {
            self::schedule_schema_provisioning();
            throw new RuntimeException('FTS schema maintenance must complete before reindexing.');
        }

        return $queue;
    }

    /**
     * Hold lifecycle exclusion only for one direct/operator operation.
     *
     * Canonical pre/post boundaries retain the same guard until shutdown, but
     * a complete CLI or watchdog write can release it after its event is
     * durable so a same-process manual worker is not mistaken for a rival.
     */
    private static function scoped_foreground_lifecycle_checked_index_queue(
        bool &$release_when_done
    ): WP_FTS_Index_Queue {
        $release_when_done = false;
        $hadOwner = self::$foreground_owner_guard !== null;
        try {
            $queue = self::foreground_lifecycle_checked_index_queue();
        } catch (Throwable $error) {
            if (!$hadOwner && self::$foreground_owner_guard !== null) {
                self::release_foreground_owner_guard(false);
            }
            throw $error;
        }
        $release_when_done = !$hadOwner;

        return $queue;
    }

    /** Acquire the shared side before any direct queue, option, or cron write. */
    private static function foreground_capability_queue(): WP_FTS_Index_Queue
    {
        if (self::foreground_queue_blocked_for_current_prefix()) {
            self::$foreground_queue_writes_disabled = true;
            throw new RuntimeException('FTS foreground queue writes are blocked by the uninstall fence.');
        }
        $queue = self::index_queue(false);
        if (!self::ensure_foreground_owner_guard($queue)) {
            throw new RuntimeException('The FTS foreground owner guard is unavailable.');
        }

        return $queue;
    }

    private static function foreground_queue_blocked_for_current_prefix(): bool
    {
        return isset(self::$foreground_queue_blocked_prefixes[self::current_database_prefix()]);
    }

    /**
     * Persist one reconciliation without enumerating affected posts.
     *
     * @param array<string,mixed> $payload
     */
    private static function enqueue_scope_reconciliation(
        string $scope_key,
        array $payload,
        bool $global = true,
        string $scope_subject_type = '',
        int $scope_subject_id = 0,
        string $scope_incarnation = ''
    ): void
    {
        self::persist_scope_reconciliation(
            $scope_key,
            $payload,
            $global,
            false,
            $scope_subject_type,
            $scope_subject_id,
            $scope_incarnation
        );
    }

    /** Cross a scope's dirty boundary before WordPress mutates canonical rows. */
    private static function fence_scope_reconciliation(
        string $scope_key,
        array $payload,
        bool $global = true,
        string $scope_subject_type = '',
        int $scope_subject_id = 0
    ): void {
        self::persist_scope_reconciliation(
            $scope_key,
            $payload,
            $global,
            true,
            $scope_subject_type,
            $scope_subject_id
        );
    }

    /**
     * Persist either side of one scope mutation boundary.
     *
     * @param array<string,mixed> $payload
     */
    private static function persist_scope_reconciliation(
        string $scope_key,
        array $payload,
        bool $global,
        bool $pre_boundary,
        string $scope_subject_type,
        int $scope_subject_id,
        string $scope_incarnation = ''
    ): void {
        if (self::$foreground_queue_writes_disabled) {
            return;
        }
        if ($pre_boundary && !self::refresh_expired_foreground_bulk_fence()) {
            return;
        }
        if ($global) {
            // Every global reason covers the same corpus. A stable identity
            // coalesces sequential requests into one generation instead of
            // accumulating one full-corpus scan per random event key.
            $scope_key = self::GLOBAL_RECONCILIATION_SCOPE_KEY;
        }
        $identity = 'scope:' . hash('sha256', $scope_key);
        $expired_depth = 0;
        if ($pre_boundary && isset(self::$mutation_fence_tokens[$identity])) {
            $current_fence = self::$mutation_fence_tokens[$identity];
            if (
                self::$foreground_bulk_mutation_scope !== null
                || (int) ($current_fence['expires_at'] ?? 0) > time()
            ) {
                self::$mutation_fence_tokens[$identity]['depth']++;
                return;
            }
            $expired_depth = max(1, (int) ($current_fence['depth'] ?? 1));
        }
        $fence = self::$mutation_fence_tokens[$identity] ?? null;
        $post_boundary_has_fence = !$pre_boundary && is_array($fence);
        $wasRetained = self::foreground_mutation_target_is_retained($identity);
        self::remember_foreground_mutation_target(
            $identity,
            null,
            true,
            $fence === null && $wasRetained
        );
        if (self::$foreground_queue_writes_disabled) {
            return;
        }
        if (self::$foreground_bulk_mutation_scope !== null) {
            // One request-global scope now covers this and every later
            // canonical scope mutation. Shutdown either deletes it after an
            // exact post handoff or promotes one corpus reconciliation.
            return;
        }
        $durableScopeKey = self::$foreground_direct_scope_keys[$identity] ?? $scope_key;

        try {
            $queue = self::foreground_index_queue();
            $scope_coverage = $global
                ? WP_FTS_Index_Queue::SCOPE_COVERAGE_CORPUS
                : WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED;
            if ($global) {
                $target_profile_hash = self::sanitize_index_profile_hash($payload['profile_hash'] ?? '');
                if ($target_profile_hash === '') {
                    $target_profile_hash = self::current_index_profile_hash();
                }
                $payload['profile_hash'] = $target_profile_hash;
                $scope_incarnation = self::sanitize_readiness_incarnation($scope_incarnation);
                if ($scope_incarnation === '') {
                    // The post side of an existing pre-fence may be stale: a
                    // concurrent corpus enqueue can already own a newer
                    // readiness incarnation and durable generation. Reuse the
                    // current authority so the stale promoter cannot strand a
                    // freshly rotated health token with no matching scope.
                    $scope_incarnation = self::mark_initial_index_pending(
                        !$post_boundary_has_fence,
                        $target_profile_hash
                    );
                } else {
                    self::mark_initial_index_pending(false, $target_profile_hash);
                }
            } else {
                $scope_incarnation = '';
            }
            if ($pre_boundary) {
                $mutation_token = self::foreground_mutation_token($queue);
                $available_at = self::mutation_fence_available_at();
                $queue->fence_scope(
                    $scope_key,
                    $mutation_token,
                    $payload,
                    $available_at,
                    $scope_coverage,
                    $scope_subject_type,
                    $scope_subject_id,
                    $scope_incarnation
                );
                self::$mutation_fence_tokens[$identity] = [
                    'token' => $mutation_token,
                    'depth' => $expired_depth + 1,
                    'expires_at' => $available_at,
                ];
                self::$foreground_direct_scope_keys[$identity] = $scope_key;
                self::$foreground_direct_scope_tokens[$identity] = $mutation_token;
                $schedule_after = 1;
            } else {
                $fence = self::$mutation_fence_tokens[$identity] ?? null;
                if ($fence === null) {
                    $queue->enqueue_scope(
                        $scope_key,
                        $payload,
                        null,
                        $scope_coverage,
                        $scope_subject_type,
                        $scope_subject_id,
                        $scope_incarnation
                    );
                    $schedule_after = 60;
                } elseif ($fence['depth'] > 1) {
                    self::$mutation_fence_tokens[$identity]['depth']--;
                    return;
                } else {
                    $queue->promote_scope(
                        $durableScopeKey,
                        $fence['token'],
                        $payload,
                        null,
                        $scope_coverage,
                        $scope_subject_type,
                        $scope_subject_id,
                        $scope_incarnation
                    );
                    self::remember_foreground_ready_work();
                    unset(self::$mutation_fence_tokens[$identity]);
                    // The pre-boundary already installed a one-second
                    // watchdog unless a live request outlasted its finite
                    // deadline and the worker moved that watchdog forward.
                    $schedule_after = (int) ($fence['expires_at'] ?? 0) <= time() ? 1 : null;
                }
            }
            if ($global) {
                $state = self::index_health_state();
                $state['status'] = 'reconciling';
                self::set_option(self::INDEX_HEALTH_OPTION, $state);
            }
            if ($schedule_after !== null) {
                self::schedule_queue_processor($schedule_after, true);
            }
        } catch (Throwable $error) {
            self::disable_foreground_queue_writes($error);
        }
    }

    /** Latch the first foreground persistence failure for the rest of the request. */
    private static function disable_foreground_queue_writes(Throwable $error): void
    {
        if (self::$foreground_queue_writes_disabled) {
            return;
        }
        self::$foreground_queue_writes_disabled = true;
        if (
            $error instanceof WP_FTS_Foreground_Owner_Guard_Busy
            || self::$foreground_owner_guard_failure_latched
        ) {
            return;
        }
        if (self::foreground_queue_blocked_for_current_prefix()) {
            return;
        }
        // A request preloaded before uninstall may reach this point only after
        // its work-table DML lost the metadata-lock race to DROP. Pay one
        // indexed option probe on that exceptional path so it cannot recreate
        // health/options or schedule schema repair after uninstall.
        if (self::uninstall_fence_active()) {
            return;
        }
        self::remember_foreground_queue_failure($error);
        self::schedule_schema_provisioning(300);
    }

    private static function remember_foreground_queue_failure(Throwable $error): void
    {
        $incarnation = '';
        $target_profile_hash = '';
        try {
            $target_profile_hash = self::current_index_profile_hash();
        } catch (Throwable) {
            // The recovery generation remains unpublishable until maintenance
            // can compute and bind a complete analyzer profile.
        }
        try {
            self::clear_search_ready_incarnation();
            $incarnation = self::rotate_readiness_incarnation();
        } catch (Throwable) {
            // Bind the recovery scope to an unpublishable token. The durable
            // corpus row still suppresses stale results, and maintenance must
            // persist a fresh incarnation before readiness can advance.
            try {
                $incarnation = bin2hex(random_bytes(16));
            } catch (Throwable) {
                $incarnation = str_repeat('0', 32);
            }
        }

        try {
            $state = self::index_health_state();
            $state['initial_index_status'] = self::INITIAL_INDEX_STATUS_PENDING;
            $state['status'] = 'unhealthy';
            $state['initial_index_completed_at'] = '';
            $state['reconciliation_scope_completed_at'] = '';
            $state['reconciliation_scope_completed_incarnation'] = '';
            $state['reconciliation_scope_completed_profile_hash'] = '';
            if ($target_profile_hash !== '') {
                $state['index_profile_hash'] = $target_profile_hash;
            }
            $state['global_visibility_fence_active'] = true;
            $state['last_error'] = self::sanitize_index_failure_text(
                get_class($error) . ': ' . $error->getMessage(),
                self::MAX_INDEX_FAILURE_ERROR_BYTES
            );
            self::set_option(self::INDEX_HEALTH_OPTION, $state);
            self::$search_takeover_status_cache = [];
        } catch (Throwable) {
            // Canonical WordPress writes must outlive unavailable FTS state.
        }

        try {
            self::index_queue(false)->enqueue_scope(
                self::GLOBAL_RECONCILIATION_SCOPE_KEY,
                [
                    'reason' => 'foreground_queue_failure',
                    'profile_hash' => $target_profile_hash,
                ],
                null,
                WP_FTS_Index_Queue::SCOPE_COVERAGE_CORPUS,
                '',
                0,
                $incarnation
            );
            self::schedule_queue_processor(1);
        } catch (Throwable) {
            // The schema watchdog reasserts the corpus generation after the
            // work table becomes writable again.
        }
    }

    /** Count durable post and scope work without loading the rows. */
    private static function pending_queue_count(): int
    {
        if (!self::option_matches_schema_version(self::get_option(self::SCHEMA_VERSION_OPTION, null))) {
            return self::legacy_queue_option_exists() ? 1 : 0;
        }

        return self::index_queue(false)->count();
    }

    /** @return array{post_count:int,scope_count:int,scope_cursor_post_id:?int,post_count_relation:string,scope_count_relation:string,counts_capped:bool} */
    private static function durable_work_status(): array
    {
        $empty = [
            'post_count' => 0,
            'scope_count' => 0,
            'scope_cursor_post_id' => 0,
            'post_count_relation' => 'exact',
            'scope_count_relation' => 'exact',
            'counts_capped' => false,
        ];
        try {
            if (!self::option_matches_schema_version(self::get_option(self::SCHEMA_VERSION_OPTION, null))) {
                return $empty;
            }

            return self::index_queue(false)->status();
        } catch (Throwable) {
            return $empty;
        }
    }

    /** Normalize lower-bound queue counts for JSON, CLI, and admin surfaces. */
    private static function bounded_count_relation(mixed $value): string
    {
        return is_scalar($value) && (string) $value === 'exact' ? 'exact' : 'at_least';
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
        $deadline = self::index_batch_deadline($opts, $started);

        $blocked_reason = null;
        $recovered_stale_lease = false;
        $token = self::acquire_index_lock($mode, $blocked_reason, $recovered_stale_lease);
        if ($token === null) {
            $summary['skipped_locked'] = true;
            $summary['lock_prevented_work'] = true;
            if ($blocked_reason === 'uninstall_fenced') {
                $summary['has_more'] = false;
                self::remember_index_batch_stop($summary, 'uninstall_fenced');
            } else {
                $summary['lock_before'] = self::index_lock_status();
                $summary['has_more'] = true;
                self::remember_index_batch_stop($summary, 'lock_active');
            }
            self::finalize_index_batch_summary($summary, $started);
            $blocked_error = null;
            try {
                if ($blocked_reason !== 'uninstall_fenced') {
                    self::update_index_health_state($summary);
                }
            } catch (Throwable $error) {
                $blocked_error = $error;
                self::remember_index_batch_exception_in_summary($summary, $error);
                self::remember_index_batch_systemic_backoff($summary, 'health_state_unavailable');
            } finally {
                // A diagnostics failure must not strand a contended queue. The
                // successor is the worker's last bounded responsibility, and
                // a false handoff must remain visible to the cron caller.
                if ($mode === 'cron' && $blocked_reason !== 'uninstall_fenced') {
                    if (!self::schedule_index_batch_successor($summary)) {
                        self::remember_index_batch_successor_schedule_failure($summary);
                    }
                }
            }
            if ($blocked_error !== null) {
                throw $blocked_error;
            }

            return $summary;
        }

        $thrown = null;
        $retain_writer_lease = false;
        self::$active_index_writer_token = $token;
        self::$active_index_writer_prefix = self::current_database_prefix();
        try {
            self::assert_index_writer_ownership();
            if ($recovered_stale_lease) {
                // Stale takeover is its own cheap control phase. A fatal or
                // ambiguous predecessor can otherwise add the extra CAS
                // takeover statements to the next maximum writer transaction.
                self::remember_index_batch_stop($summary, 'stale_writer_lease_recovered');
            } else {
                if (!self::option_matches_schema_version(self::get_option(self::SCHEMA_VERSION_OPTION, null))) {
                    self::schedule_schema_provisioning();
                    self::remember_index_batch_systemic_backoff($summary, 'schema_maintenance');
                    throw new RuntimeException('FTS schema maintenance must complete before indexing.');
                }

                self::process_relational_work_batch(
                    min(100, max(1, $batch_size)),
                    $summary,
                    $deadline
                );
            }
        } catch (Throwable $e) {
            $thrown = $e;
            $retain_writer_lease = !empty($summary['_writer_transaction_attempted']);
            self::remember_index_batch_exception_in_summary($summary, $e);
            if ($e instanceof WP_FTS_Index_Writer_Ownership_Lost) {
                self::remember_index_batch_stop($summary, 'ownership_lost');
            } elseif (empty($summary['stop_reason'])) {
                // An unclassified worker exception usually means the durable
                // work or derived tables could not complete an operation.
                // Ask the bounded maintenance worker to verify the physical
                // schema, but never turn the queue callback into a one-second
                // PHP/SQL retry loop while that verification is pending.
                self::schedule_schema_provisioning();
                self::remember_index_batch_stop($summary, 'worker_storage_unavailable');
            }
            self::remember_index_batch_systemic_backoff($summary, (string) ($summary['stop_reason'] ?? 'worker_unavailable'));
        } finally {
            self::finalize_index_batch_summary($summary, $started);
            if (self::index_batch_requires_health_write($summary)) {
                try {
                    self::update_index_health_state($summary);
                } catch (Throwable $health_error) {
                    // Diagnostics persistence is not allowed to bypass the
                    // systemic retry scheduler or mask the worker failure
                    // that made diagnostics necessary in the first place.
                    self::schedule_schema_provisioning();
                    if ($thrown === null) {
                        $thrown = $health_error;
                    }
                    self::remember_index_batch_exception_in_summary($summary, $health_error);
                    self::remember_index_batch_systemic_backoff($summary, 'health_state_unavailable');
                    self::finalize_index_batch_summary($summary, $started);
                }
            }

            if (!empty($summary['scope_completed_global'])) {
                self::schedule_schema_provisioning(1);
            }
            $scheduleSuccessor = $mode === 'cron'
                || !empty($summary['has_more'])
                || !empty($summary['cleanup_pending']);
            if ($scheduleSuccessor && self::$active_index_writer_token === $token) {
                try {
                    // Uninstall must acquire this same live lease. Scheduling
                    // before releasing it therefore proves the uninstall fence
                    // absent without adding a separate option-table probe, and
                    // a later uninstall will clear the event under the lease.
                    if (!self::index_writer_has_transaction_window()) {
                        throw new WP_FTS_Index_Writer_Ownership_Lost(
                            'FTS index writer lease is too near expiry to schedule without a fence probe.'
                        );
                    }
                    if (!self::schedule_index_batch_successor($summary, true)) {
                        self::remember_index_batch_successor_schedule_failure($summary);
                    }
                } catch (Throwable $schedule_error) {
                    // A lost or nearly expired lease cannot prove uninstall
                    // exclusion. Preserve that as a visible handoff failure;
                    // never schedule after releasing the shared lease.
                    self::remember_index_batch_successor_schedule_failure($summary, $schedule_error);
                }
            }
            try {
                if (self::$active_index_writer_token === $token && !$retain_writer_lease) {
                    self::release_index_lock($token);
                }
            } catch (Throwable $release_error) {
                if ($thrown === null) {
                    $thrown = $release_error;
                }
                self::remember_index_batch_exception_in_summary($summary, $release_error);
                self::remember_index_batch_systemic_backoff($summary, 'writer_release_unavailable');
                self::schedule_schema_provisioning();
            } finally {
                self::$active_index_writer_token = null;
                self::$active_index_writer_prefix = null;
            }
        }

        unset($summary['_writer_transaction_attempted']);
        if ($thrown !== null) {
            throw $thrown;
        }

        return $summary;
    }

    /** Schedule the one exact successor and report whether its handoff is durable. */
    private static function schedule_index_batch_successor(array $summary, bool $writer_lease_owned = false): bool
    {
        try {
            if (!empty($summary['wait_for_next_available']) && is_numeric($summary['next_available_at'] ?? null)) {
                return self::schedule_queue_processor(
                    max(1, (int) $summary['next_available_at'] - time()),
                    $writer_lease_owned
                );
            }
            if (!empty($summary['has_more']) || !empty($summary['cleanup_pending'])) {
                return self::schedule_queue_processor(1, $writer_lease_owned);
            }

            return self::schedule_next_available_queue_processor($writer_lease_owned);
        } catch (Throwable) {
            // No exception may bypass a worker's already-completed durable
            // state transition. The caller consumes the false result before
            // releasing any writer lease it owns.
            return false;
        }
    }

    /**
     * Advance either one scope page or one direct post batch.
     *
     * Scope work and direct work share one atomic claim, but never append both
     * mutations to the same invocation. One durable bit alternates collisions:
     * a post turn yields the scope, and a scope turn releases the posts. Every
     * operation stays set-oriented and inside the fixed worker statement set.
     *
     * @param array<string,mixed> $summary
     */
    private static function process_relational_work_batch(
        int $limit,
        array &$summary,
        ?float $deadline = null
    ): void
    {
        $queue = self::index_queue(false);
        try {
            $work = $queue->claim_batch(
                $limit,
                null,
                WP_FTS_Index_Queue::DEFAULT_LEASE_SECONDS,
                self::MAX_INDEX_BATCH_SOURCE_BYTES
            );
        } catch (Throwable $error) {
            // A second liveness probe can fail after the claim UPDATE. Even if
            // the compensating re-fence then hits a database error, the guard
            // failure must still revoke takeover before worker error handling
            // propagates the original exception.
            if ($queue->foreground_owner_guard_probe_state() === 'unavailable') {
                self::latch_foreground_owner_guard_unavailable();
            }
            throw $error;
        }
        if ($queue->foreground_owner_guard_probe_state() === 'unavailable') {
            self::latch_foreground_owner_guard_unavailable();
        }
        if ($work === []) {
            $cleaned = self::storage(false)->cleanup_empty_terms();
            $summary['empty_terms_cleaned'] = max(0, (int) ($summary['empty_terms_cleaned'] ?? 0))
                + max(0, $cleaned);
            if ($cleaned >= WP_FTS_Storage_Mysql::MAX_EMPTY_TERM_CLEANUP) {
                // A full page proves cleanup debt may remain. Continue one
                // bounded DELETE per invocation without polling the queue.
                $summary['cleanup_pending'] = true;
                $summary['has_more'] = true;
                return;
            }

            // Future retries and active leases remain visible to manual
            // callers, but cron sleeps until their exact next claimable time
            // instead of polling through bounded backoff every second.
            $summary['next_available_at'] = $queue->next_available_at();
            if ($queue->foreground_owner_guard_probe_state() === 'unavailable') {
                self::latch_foreground_owner_guard_unavailable();
            }
            $summary['has_more'] = $summary['next_available_at'] !== null;
            $summary['wait_for_next_available'] = $summary['has_more'];
            return;
        }
        try {
            $scope = null;
            $scopeYield = null;
            $claims = [];
            foreach ($work as $claim) {
                if (($claim['kind'] ?? '') === 'scope' && $scope === null) {
                    $scope = $claim;
                } elseif (($claim['kind'] ?? '') === 'post') {
                    $claims[] = $claim;
                }
            }
            $scopeCoverage = is_array($scope) && is_scalar($scope['scope_coverage'] ?? null)
                ? (string) $scope['scope_coverage']
                : '';
            $scopeProfile = is_array($scope)
                ? self::sanitize_index_profile_hash($scope['payload']['profile_hash'] ?? '')
                : '';
            $postProfiles = [];
            foreach ($claims as $claim) {
                $profile = self::sanitize_index_profile_hash($claim['payload']['profile_hash'] ?? '');
                if ($profile !== '') {
                    $postProfiles[$profile] = true;
                }
            }
            if (
                $scopeCoverage === WP_FTS_Index_Queue::SCOPE_COVERAGE_CORPUS
                || $postProfiles !== []
            ) {
                $currentProfile = self::current_index_profile_hash();
                $boundProfiles = $postProfiles;
                if ($scopeCoverage === WP_FTS_Index_Queue::SCOPE_COVERAGE_CORPUS) {
                    $boundProfiles[$scopeProfile] = true;
                }
                unset($boundProfiles['']);
                $profilesMatch = $scopeCoverage !== WP_FTS_Index_Queue::SCOPE_COVERAGE_CORPUS
                    || $scopeProfile !== '';
                $profilesMatch = $profilesMatch
                    && count($boundProfiles) === 1
                    && isset($boundProfiles[$currentProfile]);
                if (!$profilesMatch) {
                    $desiredProfile = self::sanitize_index_profile_hash(
                        self::index_health_state()['index_profile_hash'] ?? ''
                    );
                    $waitingForOptionPublication = count($boundProfiles) === 1
                        && $desiredProfile !== ''
                        && isset($boundProfiles[$desiredProfile])
                        && !hash_equals($desiredProfile, $currentProfile);
                    if ($scope !== null) {
                        $waitingForOptionPublication
                            ? $queue->fail_scope($scope)
                            : $queue->release_scope($scope);
                    }
                    if ($claims !== []) {
                        $waitingForOptionPublication
                            ? $queue->fail_many($claims)
                            : $queue->release_many($claims);
                    }
                    if (!$waitingForOptionPublication) {
                        $incarnation = self::mark_initial_index_pending(true, $currentProfile);
                        $queue->enqueue_scope(
                            self::GLOBAL_RECONCILIATION_SCOPE_KEY,
                            [
                                'reason' => 'index_profile_changed_during_reconciliation',
                                'profile_hash' => $currentProfile,
                            ],
                            null,
                            WP_FTS_Index_Queue::SCOPE_COVERAGE_CORPUS,
                            '',
                            0,
                            $incarnation
                        );
                    }
                    $summary['has_more'] = true;
                    self::remember_index_batch_stop(
                        $summary,
                        $waitingForOptionPublication ? 'profile_option_pending' : 'profile_reconciliation_restarted'
                    );
                    return;
                }
            }
            if ($scope !== null && $deadline !== null && microtime(true) >= $deadline) {
                self::remember_index_batch_stop($summary, 'time_budget');
                try {
                    $queue->release_scope($scope);
                } finally {
                    if ($claims !== []) {
                        $queue->release_many($claims);
                    }
                }
                return;
            }
            if (
                $scope !== null
                && $scopeCoverage === WP_FTS_Index_Queue::SCOPE_COVERAGE_GLOBAL
            ) {
                // Request-unique global rows are crash sentinels, not corpus
                // identities. Replace them before expansion so any number of
                // abandoned requests produces one eventual keyset scan.
                self::enqueue_corpus_scope($queue, [
                    'reason' => 'abandoned_global_scope_recovery',
                ]);
                $summary['scope_job_key'] = is_scalar($scope['job_key'] ?? null)
                    ? (string) $scope['job_key']
                    : '';
                $summary['scope_reason'] = is_scalar($scope['payload']['reason'] ?? null)
                    ? (string) $scope['payload']['reason']
                    : '';
                $summary['scope_coverage'] = $scopeCoverage;
                $summary['global_visibility_fence_completed'] = $queue->discard_replaced_scope($scope);
                $summary['scope_completed'] = $summary['global_visibility_fence_completed'];
                $summary['has_more'] = true;
                $scope = null;
                if ($claims !== []) {
                    // Normalize the request-global fence promptly, but do not
                    // append document replacement to that two-statement
                    // handoff. Returning the post generations to ready work
                    // keeps the complete worker well below its fixed ceiling.
                    $summary['deferred'] = max(0, (int) ($summary['deferred'] ?? 0)) + count($claims);
                    $queue->release_many($claims);
                    return;
                }
            }
            if ($scope !== null && $claims !== []) {
                $scopeTurn = is_scalar($scope['last_error_code'] ?? null)
                    && (string) $scope['last_error_code'] === WP_FTS_Index_Queue::SCOPE_EXPANSION_TURN_CODE;
                if ($scopeTurn) {
                    // The previous collision drained direct work and reserved
                    // this turn. Return newly co-claimed posts in one set write
                    // before advancing the keyset cursor, even if writes keep
                    // arriving continuously.
                    $summary['deferred'] = max(0, (int) ($summary['deferred'] ?? 0)) + count($claims);
                    $queue->release_many($claims);
                    $claims = [];
                } else {
                    // A maximum changed-document batch already consumes the
                    // 20-statement worker allowance. The direct phase owns the
                    // pending yield so it can coalesce that state change with
                    // any deferred post suffix. With no suffix it remains one
                    // indexed CAS, and either shape persists the next scope
                    // turn against continuous direct writes.
                    $scopeYield = $scope;
                    $scope = null;
                }
                $summary['has_more'] = true;
            }
            if ($scope !== null) {
                $summary['scope_job_key'] = is_scalar($scope['job_key'] ?? null) ? (string) $scope['job_key'] : '';
                try {
                    $scope_page = self::scope_candidate_post_ids_after(
                        max(0, (int) ($scope['cursor_post_id'] ?? 0)),
                        $limit,
                        is_scalar($scope['scope_coverage'] ?? null) ? (string) $scope['scope_coverage'] : '',
                        is_scalar($scope['scope_subject_type'] ?? null) ? (string) $scope['scope_subject_type'] : '',
                        max(0, (int) ($scope['scope_subject_id'] ?? 0)),
                        is_array($scope['payload'] ?? null) ? $scope['payload'] : []
                    );
                    $scope_ids = $scope_page['post_ids'];
                    $summary['backfill_scanned'] = $scope_page['scanned_count'];
                    if ($scope_page['exhausted']) {
                        $coverage = is_scalar($scope['scope_coverage'] ?? null)
                            ? (string) $scope['scope_coverage']
                            : '';
                        if (
                            $coverage === WP_FTS_Index_Queue::SCOPE_COVERAGE_CORPUS
                            && !self::persist_corpus_scope_completion($scope, $scopeProfile)
                        ) {
                            // Completion provenance must become durable while
                            // the exact scope authority still exists. A lost
                            // health CAS leaves the scope and any simultaneously
                            // claimed posts ready for a bounded retry.
                            $queue->release_scope($scope);
                            if ($claims !== []) {
                                $queue->release_many($claims);
                            }
                            $summary['has_more'] = true;
                            self::remember_index_batch_stop($summary, 'scope_completion_cas_lost');
                            return;
                        }
                        $summary['scope_completed'] = $queue->acknowledge_scope($scope);
                        if (empty($summary['scope_completed'])) {
                            $summary['has_more'] = true;
                        }
                        $reason = is_scalar($scope['payload']['reason'] ?? null)
                            ? (string) $scope['payload']['reason']
                            : '';
                        $summary['scope_reason'] = $reason;
                        $summary['scope_coverage'] = $coverage;
                        $summary['scope_completed_global'] = !empty($summary['scope_completed'])
                            && $coverage === WP_FTS_Index_Queue::SCOPE_COVERAGE_CORPUS;
                        $summary['scope_completed_incarnation'] = !empty($summary['scope_completed_global'])
                            ? self::sanitize_readiness_incarnation($scope['scope_incarnation'] ?? '')
                            : '';
                        $summary['scope_completed_profile_hash'] = !empty($summary['scope_completed_global'])
                            ? $scopeProfile
                            : '';
                        $summary['global_visibility_fence_completed'] = !empty($summary['scope_completed'])
                            && $coverage === WP_FTS_Index_Queue::SCOPE_COVERAGE_GLOBAL;
                    } else {
                        $scope_payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
                        $next_scope_payload = null;
                        if (array_key_exists('remaining_limit', $scope_payload)) {
                            $next_scope_payload = $scope_payload;
                            $next_scope_payload['remaining_limit'] = max(
                                0,
                                (int) $scope_payload['remaining_limit'] - count($scope_ids)
                            );
                        }
                        $post_payload = [];
                        if (is_array($scope_payload['index_options'] ?? null)) {
                            $post_payload['index_options'] = $scope_payload['index_options'];
                        }
                        if ($scopeProfile !== '') {
                            $post_payload['profile_hash'] = $scopeProfile;
                        }
                        if ($queue->commit_scope_page(
                            $scope,
                            $scope_ids,
                            $scope_page['cursor_post_id'],
                            null,
                            $post_payload,
                            $next_scope_payload
                        )) {
                            $summary['backfill_queued'] = count($scope_ids);
                        }
                        $summary['has_more'] = true;
                    }
                } catch (Throwable $error) {
                    $summary['has_more'] = true;
                    try {
                        $queue->fail_scope($scope);
                    } catch (Throwable) {
                        // Preserve the scope expansion failure reported below.
                    }
                    if ($claims !== []) {
                        try {
                            $queue->release_many($claims);
                        } catch (Throwable) {
                            // Preserve the scope expansion failure reported below.
                        }
                    }
                    throw $error;
                }
            }

            if ($claims !== []) {
                try {
                    // Construct the request-shared analyzer only when document
                    // work exists; scope and dictionary-only passes need none.
                    $analyzer = self::runtime_analyzer();
                } catch (Throwable $error) {
                    // Analyzer construction is a shared dependency failure,
                    // not a per-document outcome. Move every claimed
                    // generation into the queue's capped durable backoff
                    // instead of releasing it immediately for a one-second
                    // cron hot loop.
                    self::remember_index_batch_systemic_backoff($summary, 'analyzer_unavailable');
                    try {
                        $queue->fail_many($claims);
                    } catch (Throwable) {
                        // A broken work table can prevent durable backoff. The
                        // outer systemic delay still protects cron, while the
                        // maintenance worker verifies or repairs the schema.
                        self::schedule_schema_provisioning();
                    }
                    if ($scopeYield !== null) {
                        try {
                            $queue->yield_scope_to_posts($scopeYield);
                        } catch (Throwable) {
                            // Preserve the analyzer dependency failure below.
                        }
                    }
                    $failed_post_ids = [];
                    foreach ($claims as $claim) {
                        $post_id = max(0, (int) ($claim['post_id'] ?? 0));
                        if ($post_id > 0) {
                            $failed_post_ids[$post_id] = true;
                        }
                    }
                    $summary['attempted'] = max(0, (int) ($summary['attempted'] ?? 0)) + count($failed_post_ids);
                    $summary['retryable_failures'] = max(0, (int) ($summary['retryable_failures'] ?? 0))
                        + count($failed_post_ids);
                    throw $error;
                }
                try {
                    self::process_prepared_claim_batch(
                        $claims,
                        $summary,
                        $analyzer,
                        $queue,
                        $deadline,
                        $scopeYield
                    );
                } catch (Throwable $error) {
                    if ($scopeYield !== null) {
                        try {
                            $queue->yield_scope_to_posts($scopeYield);
                        } catch (Throwable) {
                            // Preserve the direct-phase failure below. A live
                            // marker CAS may already have settled this scope.
                        }
                    }
                    throw $error;
                }
            }
            if (count($claims) >= $limit) {
                $summary['has_more'] = true;
            } elseif ($scope === null && empty($summary['has_more'])) {
                // A short successful direct batch exhausted the claimable
                // frontier observed under this writer lease. Pay the same one
                // bounded cleanup page an empty claim would pay, without
                // scheduling an otherwise empty follow-up worker invocation.
                $cleaned = self::storage(false)->cleanup_empty_terms();
                $summary['empty_terms_cleaned'] = max(0, (int) ($summary['empty_terms_cleaned'] ?? 0))
                    + max(0, $cleaned);
                if ($cleaned >= WP_FTS_Storage_Mysql::MAX_EMPTY_TERM_CLEANUP) {
                    $summary['cleanup_pending'] = true;
                    $summary['has_more'] = true;
                }
            }
        } finally {
            // Claim ownership is entirely the durable generation/token CAS.
        }
    }

    /** Persist exact corpus completion before deleting its durable authority. */
    private static function persist_corpus_scope_completion(array $scope, string $profile_hash): bool
    {
        $incarnation = self::sanitize_readiness_incarnation($scope['scope_incarnation'] ?? '');
        $profile_hash = self::sanitize_index_profile_hash($profile_hash);
        $expected = self::get_option(self::INDEX_HEALTH_OPTION, []);
        $state = self::sanitize_index_health_state($expected);
        $current_incarnation = self::readiness_incarnation();
        $target_profile = self::sanitize_index_profile_hash($state['index_profile_hash'] ?? '');
        if (
            $incarnation === ''
            || $profile_hash === ''
            || $current_incarnation === ''
            || !hash_equals($incarnation, $current_incarnation)
            || $target_profile === ''
            || !hash_equals($profile_hash, $target_profile)
        ) {
            return false;
        }
        if (
            self::sanitize_index_timestamp($state['reconciliation_scope_completed_at'] ?? '') !== ''
            && self::readiness_completion_matches($state)
        ) {
            return true;
        }

        $state['reconciliation_scope_completed_at'] = self::current_gmt_datetime();
        $state['reconciliation_scope_completed_incarnation'] = $incarnation;
        $state['reconciliation_scope_completed_profile_hash'] = $profile_hash;

        return self::compare_and_swap_index_health($expected, $state);
    }

    /**
     * Apply one claimed generation batch without per-document database calls.
     *
     * @param array<int,array<string,mixed>> $claims
     * @param array<string,mixed> $summary
     */
    private static function process_prepared_claim_batch(
        array $claims,
        array &$summary,
        WP_FTS_Analyzer $analyzer,
        WP_FTS_Index_Queue $queue,
        ?float $deadline = null,
        ?array &$scope_yield = null
    ): void {
        // A stale lease and its successor may both be present in a prepared
        // caller fixture. Analyze and write each current canonical post once.
        $claims_by_post_id = [];
        $document_claims = [];
        foreach ($claims as $claim) {
            $post_id = max(0, (int) ($claim['post_id'] ?? 0));
            if (!isset($claims_by_post_id[$post_id])) {
                $claims_by_post_id[$post_id] = [];
                $document_claims[] = $claim;
            }
            $claims_by_post_id[$post_id][] = $claim;
        }

        // A direct turn may own the durable scope-alternation marker. Settle
        // that marker with the same bounded UPDATE that returns any post
        // suffix, rather than making two independent control writes additive.
        $release_claims = static function (array $release_claims) use ($queue, &$scope_yield): void {
            if ($scope_yield !== null) {
                if ($release_claims === []) {
                    $queue->yield_scope_to_posts($scope_yield);
                } else {
                    $queue->yield_scope_and_release_posts($scope_yield, $release_claims);
                }
                $scope_yield = null;
                return;
            }
            if ($release_claims !== []) {
                $queue->release_many($release_claims);
            }
        };

        if ($deadline !== null && microtime(true) >= $deadline) {
            $release_claims($claims);
            $summary['deferred'] = max(0, (int) ($summary['deferred'] ?? 0)) + count($document_claims);
            self::remember_index_batch_stop($summary, 'time_budget');
            return;
        }

        $post_ids = array_values(array_map(static fn(array $claim): int => (int) ($claim['post_id'] ?? 0), $document_claims));
        $source_measurements = [];
        $source_snapshots = [];
        $index_options_by_post_id = [];
        $claim_measurements_complete = true;
        foreach ($document_claims as $claim) {
            $post_id = max(0, (int) ($claim['post_id'] ?? 0));
            if (
                !array_key_exists('source_exists', $claim)
                || !array_key_exists('source_bytes', $claim)
                || !array_key_exists('canonical_bytes', $claim)
            ) {
                $claim_measurements_complete = false;
            }
            if ($post_id > 0) {
                $source_measurements[$post_id] = [
                    'exists' => !empty($claim['source_exists']),
                    'bytes' => max(0, (int) ($claim['source_bytes'] ?? 0)),
                    'canonical_bytes' => max(0, (int) ($claim['canonical_bytes'] ?? 0)),
                ];
                if (isset($claim['source_snapshot']) && is_object($claim['source_snapshot'])) {
                    $source_snapshots[$post_id] = $claim['source_snapshot'];
                }
                $payload = is_array($claim['payload'] ?? null) ? $claim['payload'] : [];
                $index_options_by_post_id[$post_id] = is_array($payload['index_options'] ?? null)
                    ? $payload['index_options']
                    : [];
            }
        }
        if (!$claim_measurements_complete) {
            $source_measurements = [];
        }
        try {
            $posts = self::load_posts_for_indexing(
                $post_ids,
                $source_measurements,
                $source_snapshots,
                $index_options_by_post_id
            );
        } catch (Throwable $error) {
            // A shared source/dependency read fails before per-document error
            // isolation begins. Durable capped backoff prevents an unavailable
            // source table from turning the cron event into a hot retry loop.
            $queue->fail_many($claims);
            $release_claims([]);
            $summary['attempted'] = max(0, (int) ($summary['attempted'] ?? 0)) + count($document_claims);
            $summary['retryable_failures'] = max(0, (int) ($summary['retryable_failures'] ?? 0))
                + count($document_claims);
            $summary['has_more'] = true;
            throw $error;
        }
        $storage = self::storage(false);
        $existing_hashes = [];
        foreach ($posts as $post_id => $post) {
            if (isset($post->fts_existing_hash) && is_scalar($post->fts_existing_hash)) {
                $existing_hashes[$post_id] = (string) $post->fts_existing_hash;
            }
        }
        $indexer = new WP_FTS_Indexer($storage, $analyzer, new WP_FTS_PostContentExtractor());

        $prepared = [];
        $delete_ids = [];
        $successful_claims = [];
        $failed_claims = [];
        $deferred_claims = [];
        $posts_by_id = [];
        $excluded_post_ids = [];
        $indexed_post_ids = [];
        $unchanged_post_ids = [];
        $deleted_post_ids = [];
        $permanently_rejected_post_ids = [];
        $prepared_postings = 0;
        $prepared_terms = [];
        foreach ($document_claims as $claim_index => $claim) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                foreach (array_slice($document_claims, $claim_index) as $deferred_document_claim) {
                    $deferred_post_id = max(0, (int) ($deferred_document_claim['post_id'] ?? 0));
                    array_push(
                        $deferred_claims,
                        ...($claims_by_post_id[$deferred_post_id] ?? [$deferred_document_claim])
                    );
                }
                self::remember_index_batch_stop($summary, 'time_budget');
                break;
            }
            try {
                self::assert_index_writer_ownership();
            } catch (WP_FTS_Index_Writer_Ownership_Lost $error) {
                // No derived write has started while the batch is still being
                // prepared. Release every claimed generation immediately so
                // the successor writer does not have to wait for lease expiry.
                $release_claims($claims);
                $summary['has_more'] = true;
                throw $error;
            }
            $post_id = max(0, (int) ($claim['post_id'] ?? 0));
            $post_claims = $claims_by_post_id[$post_id] ?? [$claim];
            $post = $posts[$post_id] ?? null;

            if (is_object($post) && !empty($post->fts_index_deferred)) {
                array_push($deferred_claims, ...$post_claims);
                $summary['has_more'] = true;
                continue;
            }
            if (is_object($post) && is_array($post->fts_index_rejection ?? null)) {
                $reason = is_scalar($post->fts_index_rejection['reason_code'] ?? null)
                    ? (string) $post->fts_index_rejection['reason_code']
                    : 'analysis_limit';
                $message = is_scalar($post->fts_index_rejection['message'] ?? null)
                    ? (string) $post->fts_index_rejection['message']
                    : 'The FTS document exceeds a supported analysis limit.';
                $error = new WP_FTS_Analysis_Limit_Exceeded($reason, $message);
                $delete_ids[$post_id] = $post_id;
                array_push($successful_claims, ...$post_claims);
                $posts_by_id[$post_id] = $post;
                $excluded_post_ids[$post_id] = true;
                $permanently_rejected_post_ids[$post_id] = true;
                self::remember_index_failure_in_summary($summary, $post_id, $post, $error);
                continue;
            }

            try {
                if ($post !== null && self::is_indexable_post($post)) {
                    $payload = is_array($claim['payload'] ?? null) ? $claim['payload'] : [];
                    $index_options = is_array($payload['index_options'] ?? null)
                        ? $payload['index_options']
                        : [];
                    $source = $indexer->prepare_post_source(
                        $post,
                        self::prepare_post_index_options($post, $index_options)
                    );
                    $source_hash = (string) ($source['content_hash'] ?? '');
                    if (
                        isset($existing_hashes[$post_id])
                        && $source_hash !== ''
                        && hash_equals((string) $existing_hashes[$post_id], $source_hash)
                    ) {
                        $unchanged_post_ids[$post_id] = true;
                    } else {
                        $summary['analyzed'] = max(0, (int) ($summary['analyzed'] ?? 0)) + 1;
                        $document = $indexer->prepare_post_from_source($source);
                        $frequencies = is_array($document['term_frequencies'] ?? null)
                            ? $document['term_frequencies']
                            : [];
                        $surface_frequencies = is_array($document['surface_frequencies'] ?? null)
                            ? $document['surface_frequencies']
                            : [];
                        $document_postings = count($frequencies) + count($surface_frequencies);
                        if (count($frequencies) > WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_TERMS) {
                            throw new WP_FTS_Prepared_Document_Rejected(
                                $post_id,
                                'term_limit',
                                "Prepared FTS document {$post_id} exceeds the " . WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_TERMS . '-term writer contract.'
                            );
                        }
                        if (count($surface_frequencies) > WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES) {
                            throw new WP_FTS_Prepared_Document_Rejected(
                                $post_id,
                                'surface_limit',
                                "Prepared FTS document {$post_id} exceeds the " . WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES . '-surface writer contract.'
                            );
                        }
                        if ($document_postings > WP_FTS_Storage_Mysql::MAX_DOCUMENT_POSTINGS) {
                            throw new WP_FTS_Prepared_Document_Rejected(
                                $post_id,
                                'posting_limit',
                                "Prepared FTS document {$post_id} exceeds the " . WP_FTS_Storage_Mysql::MAX_DOCUMENT_POSTINGS . '-posting writer contract.'
                            );
                        }

                        $new_terms = [];
                        foreach (array_keys($frequencies) as $term) {
                            $identity = "0\0" . $term;
                            if (!isset($prepared_terms[$identity])) {
                                $new_terms[$identity] = true;
                            }
                        }
                        foreach (array_keys($surface_frequencies) as $term) {
                            $identity = "1\0" . $term;
                            if (!isset($prepared_terms[$identity])) {
                                $new_terms[$identity] = true;
                            }
                        }
                        if (
                            $prepared_postings + $document_postings > WP_FTS_Storage_Mysql::MAX_BATCH_POSTINGS
                            || count($prepared_terms) + count($new_terms) > WP_FTS_Storage_Mysql::MAX_BATCH_TERMS
                        ) {
                            foreach (array_slice($document_claims, $claim_index) as $deferred_document_claim) {
                                $deferred_post_id = max(0, (int) ($deferred_document_claim['post_id'] ?? 0));
                                array_push(
                                    $deferred_claims,
                                    ...($claims_by_post_id[$deferred_post_id] ?? [$deferred_document_claim])
                                );
                            }
                            self::remember_index_batch_stop($summary, 'batch_cap');
                            unset($document, $frequencies, $surface_frequencies);
                            break;
                        }

                        $prepared[] = $document;
                        $indexed_post_ids[$post_id] = true;
                        $prepared_postings += $document_postings;
                        foreach ($new_terms as $term => $_present) {
                            $prepared_terms[$term] = true;
                        }
                    }
                } else {
                    $delete_ids[$post_id] = $post_id;
                    $deleted_post_ids[$post_id] = true;
                }
                array_push($successful_claims, ...$post_claims);
                $posts_by_id[$post_id] = $post;
            } catch (Throwable $error) {
                if ($error instanceof WP_FTS_Index_Writer_Ownership_Lost) {
                    throw $error;
                }
                if ($error instanceof WP_FTS_Analysis_Limit_Exceeded || $error instanceof WP_FTS_Prepared_Document_Rejected) {
                    $delete_ids[$post_id] = $post_id;
                    array_push($successful_claims, ...$post_claims);
                    $posts_by_id[$post_id] = $post;
                    $excluded_post_ids[$post_id] = true;
                    $permanently_rejected_post_ids[$post_id] = true;
                    self::remember_index_failure_in_summary($summary, $post_id, $post, $error);
                    continue;
                }
                array_push($failed_claims, ...$post_claims);
                self::remember_index_failure_in_summary($summary, $post_id, $post, $error);
                // A retryable shared extension failure is a complete outcome
                // phase for this invocation. Do not spend the remaining PHP
                // budget analyzing documents that must be returned unchanged.
                foreach (array_slice($document_claims, $claim_index + 1) as $deferred_document_claim) {
                    $deferred_post_id = max(0, (int) ($deferred_document_claim['post_id'] ?? 0));
                    array_push(
                        $deferred_claims,
                        ...($claims_by_post_id[$deferred_post_id] ?? [$deferred_document_claim])
                    );
                }
                break;
            }
        }

        // Validate every storage boundary in one pure-PHP pass before the sole
        // old-posting frontier read. Otherwise a batch of independently poison
        // analyzer/filter outputs could reject one document per retry and turn
        // one worker invocation into one SELECT per claimed post.
        $partition = $storage->partition_prepared_documents($prepared);
        $prepared = $partition['documents'];
        foreach ($partition['rejections'] as $error) {
            $rejected_post_id = $error->post_id;
            $owns_claim = false;
            foreach ($successful_claims as $claim) {
                if ((int) ($claim['post_id'] ?? 0) === $rejected_post_id) {
                    $owns_claim = true;
                    break;
                }
            }
            if ($rejected_post_id <= 0 || !$owns_claim) {
                throw $error;
            }
            $delete_ids[$rejected_post_id] = $rejected_post_id;
            $excluded_post_ids[$rejected_post_id] = true;
            unset($indexed_post_ids[$rejected_post_id]);
            $permanently_rejected_post_ids[$rejected_post_id] = true;
            self::remember_index_failure_in_summary(
                $summary,
                $rejected_post_id,
                $posts_by_id[$rejected_post_id] ?? null,
                $error
            );
        }
        $transport_deferred_post_ids = array_fill_keys(
            array_map('intval', $partition['deferred_post_ids'] ?? []),
            true
        );
        if ($transport_deferred_post_ids !== []) {
            $mapped_transport_deferred_post_ids = [];
            foreach ($successful_claims as $claim_index => $claim) {
                $post_id = max(0, (int) ($claim['post_id'] ?? 0));
                if (!isset($transport_deferred_post_ids[$post_id])) {
                    continue;
                }
                $deferred_claims[] = $claim;
                unset($successful_claims[$claim_index], $indexed_post_ids[$post_id]);
                $mapped_transport_deferred_post_ids[$post_id] = true;
            }
            $successful_claims = array_values($successful_claims);
            if (count($mapped_transport_deferred_post_ids) !== count($transport_deferred_post_ids)) {
                throw new RuntimeException(
                    'Could not map every bounded SQLite transport document back to its owned queue claim.'
                );
            }
            self::remember_index_batch_stop($summary, 'sqlite_transport_cap');
        }

        if ($failed_claims !== []) {
            // A retryable callback/analyzer failure is its own bounded phase.
            // Mixing failure settlement, successful replacement, permanent
            // rejection, and source deferral made independent one-statement
            // branches additive in the same worker. Settle every retryable
            // generation together and return all other claims in one write;
            // their immediate successor retains the original queue order.
            $failed_post_ids = [];
            foreach ($failed_claims as $claim) {
                $post_id = max(0, (int) ($claim['post_id'] ?? 0));
                if ($post_id > 0) {
                    $failed_post_ids[$post_id] = true;
                }
            }
            $released_claims = [...$successful_claims, ...$deferred_claims];
            $released_post_ids = [];
            foreach ($released_claims as $claim) {
                $post_id = max(0, (int) ($claim['post_id'] ?? 0));
                if ($post_id > 0) {
                    $released_post_ids[$post_id] = true;
                }
            }
            $queue->fail_many($failed_claims);
            $release_claims($released_claims);
            self::retain_index_failure_summary_for_posts($summary, array_keys($failed_post_ids));
            $summary['attempted'] = max(0, (int) ($summary['attempted'] ?? 0)) + count($failed_post_ids);
            $summary['retryable_failures'] = max(0, (int) ($summary['retryable_failures'] ?? 0))
                + count($failed_post_ids);
            $summary['deferred'] = max(0, (int) ($summary['deferred'] ?? 0)) + count($released_post_ids);
            self::remember_index_batch_stop($summary, 'failure_phase');
            return;
        }

        if ($permanently_rejected_post_ids !== []) {
            // Permanent rejections may delete stale derived rows, but they do
            // not share a transaction with valid replacements. This keeps the
            // rejection/history phase independent of the maximum dictionary
            // writer while still acknowledging every bounded poison batch in
            // one set operation.
            $rejected_claims = [];
            $normal_claims = [];
            foreach ($successful_claims as $claim) {
                $post_id = max(0, (int) ($claim['post_id'] ?? 0));
                if (isset($permanently_rejected_post_ids[$post_id])) {
                    $rejected_claims[] = $claim;
                } else {
                    $normal_claims[] = $claim;
                }
            }
            array_push($deferred_claims, ...$normal_claims);
            $successful_claims = $rejected_claims;
            $prepared = [];
            $delete_ids = array_intersect_key($delete_ids, $permanently_rejected_post_ids);
            $indexed_post_ids = [];
            $unchanged_post_ids = [];
            $deleted_post_ids = [];
            $excluded_post_ids = $permanently_rejected_post_ids;
        }

        try {
            if ($prepared !== [] || $delete_ids !== []) {
                $new_posting_counts = [];
                foreach ($prepared as $document) {
                    $post_id = max(0, (int) ($document['doc_id'] ?? $document['post_id'] ?? 0));
                    if ($post_id > 0) {
                        $new_posting_counts[$post_id] = count($document['term_frequencies'] ?? [])
                            + count($document['surface_frequencies'] ?? []);
                    }
                }
                foreach (array_keys($delete_ids) as $post_id) {
                    $new_posting_counts[(int) $post_id] = 0;
                }
                // Measure only documents that actually need replacement.
                // Unchanged and source-deferred lower ids cannot consume the
                // old-posting frontier or starve a later changed document.
                // Earlier PHP bounds make a writer split an invariant failure,
                // not a reason to repeat this frontier query in the same run.
                $replacement_plan = $storage->plan_prepared_replacement($new_posting_counts);
                if ($replacement_plan->deferred_post_ids !== []) {
                    $deferred_post_ids = array_fill_keys($replacement_plan->deferred_post_ids, true);
                    $prepared = array_values(array_filter(
                        $prepared,
                        static fn(array $document): bool => !isset($deferred_post_ids[
                            (int) ($document['doc_id'] ?? $document['post_id'] ?? 0)
                        ])
                    ));
                    foreach ($replacement_plan->deferred_post_ids as $post_id) {
                        unset($delete_ids[$post_id]);
                    }

                    $mapped_deferred_post_ids = [];
                    foreach ($successful_claims as $claim_index => $claim) {
                        $post_id = max(0, (int) ($claim['post_id'] ?? 0));
                        if (!isset($deferred_post_ids[$post_id])) {
                            continue;
                        }
                        $deferred_claims[] = $claim;
                        unset($successful_claims[$claim_index]);
                        unset(
                            $indexed_post_ids[$post_id],
                            $deleted_post_ids[$post_id],
                            $excluded_post_ids[$post_id],
                            $permanently_rejected_post_ids[$post_id]
                        );
                        $mapped_deferred_post_ids[$post_id] = true;
                    }
                    $successful_claims = array_values($successful_claims);
                    if (count($mapped_deferred_post_ids) !== count($deferred_post_ids)) {
                        throw new RuntimeException(
                            'Could not map every bounded FTS posting frontier document back to its owned queue claim.'
                        );
                    }
                    self::remember_index_batch_stop($summary, 'posting_mutation_cap');
                }

                if (self::supports_atomic_worker_acknowledgement()) {
                    // Never add a conditional renewal query to a maximum
                    // writer. If analysis consumed the lease reserve, return
                    // the exact claims before BEGIN; the fresh successor gets
                    // a complete window for the measured five-second
                    // transaction instead of crossing an expiry mid-commit.
                    if (!self::index_writer_has_transaction_window()) {
                        $release_claims($claims);
                        self::retain_index_failure_summary_for_posts($summary, []);
                        $summary['deferred'] = max(0, (int) ($summary['deferred'] ?? 0))
                            + count($document_claims);
                        self::remember_index_batch_stop($summary, 'writer_lease_window');
                        return;
                    }
                    $summary['_writer_transaction_attempted'] = true;
                    $storage->begin_transaction();
                }
                $storage->replace_prepared_documents(
                    $prepared,
                    array_values($delete_ids),
                    $replacement_plan
                );
            }
            // Replacement deletes zero-frequency terms only from this batch's
            // measured old-posting frontier. Historical debt belongs to the
            // queue-empty/explicit maintenance pass; charging every changed
            // batch another dictionary DELETE would add server load without
            // strengthening the generation acknowledgement below.
            if (
                $successful_claims !== []
                && !$storage->has_active_transaction()
                && self::supports_atomic_worker_acknowledgement()
                && !self::index_writer_has_transaction_window()
            ) {
                $release_claims($claims);
                self::retain_index_failure_summary_for_posts($summary, []);
                $summary['deferred'] = max(0, (int) ($summary['deferred'] ?? 0))
                    + count($document_claims);
                self::remember_index_batch_stop($summary, 'writer_lease_window');
                return;
            }
            $release_claims($deferred_claims);
            if ($successful_claims !== [] && self::supports_atomic_worker_acknowledgement()) {
                $summary['_writer_transaction_attempted'] = true;
            }
            $acknowledgement = self::acknowledge_claims_under_index_lock($successful_claims, $storage);
            if ($acknowledgement === null) {
                if ($storage->has_active_transaction()) {
                    throw new RuntimeException('Atomic FTS worker publication lost its exact acknowledgement path.');
                }
                $acknowledgement = $queue->acknowledge_many($successful_claims);
            }
            $acknowledged_claims = max(0, (int) ($acknowledgement['acknowledged'] ?? 0));
            $superseded_claims = max(0, (int) ($acknowledgement['superseded'] ?? 0));
            if ($superseded_claims === 0) {
                $reported_post_ids = [];
                foreach ($successful_claims as $claim) {
                    $post_id = max(0, (int) ($claim['post_id'] ?? 0));
                    if (isset($reported_post_ids[$post_id]) || isset($excluded_post_ids[$post_id])) {
                        continue;
                    }
                    $reported_post_ids[$post_id] = true;
                    if (isset($claim['last_error_code']) && (string) $claim['last_error_code'] !== '') {
                        $summary['resolved_failure_records'] = true;
                    }
                    $post = $posts_by_id[$post_id] ?? null;
                    if (isset($indexed_post_ids[$post_id]) && $post !== null) {
                        self::remember_indexed_post_in_summary($summary, $post);
                    } else {
                        self::remember_resolved_failure_post_in_summary($summary, $post_id);
                    }
                }
            }
        } catch (Throwable $error) {
            if ($storage->has_active_transaction()) {
                try {
                    $storage->rollback();
                } catch (Throwable) {
                    // Preserve the publication, ownership, or storage failure.
                }
            }
            if ($error instanceof WP_FTS_Index_Writer_Ownership_Lost) {
                // Once storage work may have started, do not append a recovery
                // write after an ownership or connection failure. The exact
                // 300-second claim lease is already the durable retry record.
                // It expires at the same time as the systemic successor.
                $scope_yield = null;
                $summary['deferred'] = max(0, (int) ($summary['deferred'] ?? 0)) + count($document_claims);
                $summary['has_more'] = true;
                throw $error;
            }
            $retry_claims = [...$failed_claims, ...$successful_claims];
            // A transaction error can be ambiguous to the client: COMMIT may
            // have succeeded before the connection reported failure. Any
            // post-error fail/release query is therefore both unnecessary and
            // unsafe. If COMMIT rolled back, the exact claims remain leased
            // until the systemic 300-second retry; if it committed, their
            // atomic acknowledgement already removed them.
            $scope_yield = null;
            $retry_post_ids = [];
            foreach ($retry_claims as $retry_claim) {
                $retry_post_ids[max(0, (int) ($retry_claim['post_id'] ?? 0))] = true;
            }
            $deferred_post_ids = [];
            foreach ($deferred_claims as $deferred_claim) {
                $deferred_post_ids[max(0, (int) ($deferred_claim['post_id'] ?? 0))] = true;
            }
            $summary['attempted'] = max(0, (int) ($summary['attempted'] ?? 0)) + count($retry_post_ids);
            $summary['retryable_failures'] = max(0, (int) ($summary['retryable_failures'] ?? 0))
                + count($retry_post_ids);
            $summary['deferred'] = max(0, (int) ($summary['deferred'] ?? 0)) + count($deferred_post_ids);
            $summary['has_more'] = true;
            throw $error;
        }

        $committed = $acknowledged_claims ?? 0;
        $superseded = $superseded_claims ?? 0;
        $successful_post_ids = [];
        foreach ($successful_claims as $claim) {
            $successful_post_ids[max(0, (int) ($claim['post_id'] ?? 0))] = true;
        }
        $failed_post_ids = [];
        foreach ($failed_claims as $claim) {
            $failed_post_ids[max(0, (int) ($claim['post_id'] ?? 0))] = true;
        }
        $deferred_post_ids = [];
        foreach ($deferred_claims as $claim) {
            $deferred_post_ids[max(0, (int) ($claim['post_id'] ?? 0))] = true;
        }
        $indexed = count($indexed_post_ids);
        $unchanged = count($unchanged_post_ids);
        $deleted = count($deleted_post_ids);
        $permanently_rejected = count($permanently_rejected_post_ids);
        $retryable_failures = count($failed_post_ids);
        $deferred = count($deferred_post_ids);
        $attempted = count($successful_post_ids) + $retryable_failures;
        $summary['attempted'] = max(0, (int) ($summary['attempted'] ?? 0)) + $attempted;
        // `processed` remains the public compatibility name for documents
        // actually indexed. Queue acknowledgements and every non-index result
        // are reported separately instead of calling a rejection successful.
        $summary['processed'] = max(0, (int) ($summary['processed'] ?? 0)) + $indexed;
        $summary['committed'] = max(0, (int) ($summary['committed'] ?? 0)) + $committed;
        $summary['superseded'] = max(0, (int) ($summary['superseded'] ?? 0)) + $superseded;
        $summary['indexed'] = max(0, (int) ($summary['indexed'] ?? 0)) + $indexed;
        $summary['queue_processed'] = max(0, (int) ($summary['queue_processed'] ?? 0)) + $committed;
        $summary['unchanged'] = max(0, (int) ($summary['unchanged'] ?? 0)) + $unchanged;
        $summary['deleted'] = max(0, (int) ($summary['deleted'] ?? 0)) + $deleted;
        $summary['permanently_rejected'] = max(0, (int) ($summary['permanently_rejected'] ?? 0))
            + $permanently_rejected;
        $summary['retryable_failures'] = max(0, (int) ($summary['retryable_failures'] ?? 0))
            + $retryable_failures;
        $summary['deferred'] = max(0, (int) ($summary['deferred'] ?? 0)) + $deferred;
        $summary['backfill_processed'] = max(0, (int) ($summary['backfill_processed'] ?? 0))
            + min($committed, max(0, (int) ($summary['backfill_queued'] ?? 0)));
        if ($permanently_rejected_post_ids !== []) {
            self::retain_index_failure_summary_for_posts(
                $summary,
                array_map('intval', array_keys($permanently_rejected_post_ids))
            );
        }
    }

    /**
     * Read a stable keyset of current eligible posts plus retained derived rows.
     *
     * Including retained document IDs ensures a scope reconciliation physically
     * deletes rows whose canonical post was removed or became ineligible.
     *
     * Targeted work is one direct `(term_taxonomy_id, object_id)` keyset.
     * Filtered CLI work merges at most 32 exact `(post_type, post_status, ID)`
     * keysets in one statement. Only a corpus reconciliation deliberately
     * advances raw primary-key pages from posts and retained documents, making
     * its total work proportional to the corpus it must reconcile. Concurrent
     * canonical changes remain covered by their pre-mutation post fences.
     *
     * @return array{post_ids:int[],cursor_post_id:int,scanned_count:int,exhausted:bool}
     */
    private static function scope_candidate_post_ids_after(
        int $cursor,
        int $limit,
        string $scope_coverage,
        string $scope_subject_type,
        int $scope_subject_id,
        array $payload = []
    ): array
    {
        global $wpdb;

        if (
            !isset($wpdb)
            || !is_object($wpdb)
            || !is_callable([$wpdb, 'prepare'])
            || !is_callable([$wpdb, 'get_results'])
        ) {
            throw new RuntimeException('WordPress posts storage is unavailable for FTS scope expansion.');
        }
        $posts_table = (string) ($wpdb->posts ?? ((string) ($wpdb->prefix ?? '') . 'posts'));
        $documents_table = (string) ($wpdb->prefix ?? '') . 'fts_documents';
        $limit = max(1, $limit);
        if ($scope_coverage === WP_FTS_Index_Queue::SCOPE_COVERAGE_FILTERED) {
            $remaining = null;
            if (array_key_exists('remaining_limit', $payload)) {
                $remaining = max(0, (int) $payload['remaining_limit']);
                if ($remaining === 0) {
                    return [
                        'post_ids' => [],
                        'cursor_post_id' => $cursor,
                        'scanned_count' => 0,
                        'exhausted' => true,
                    ];
                }
            }
            $filters = [];
            foreach (['post_status', 'post_type'] as $name) {
                $raw = $payload[$name] ?? [];
                if (!is_array($raw) || $raw === [] || count($raw) > self::MAX_SEARCH_SCOPE_VALUES) {
                    throw new RuntimeException("Invalid durable WP-CLI {$name} scope filter.");
                }
                $values = [];
                foreach ($raw as $value) {
                    if (!is_scalar($value) || strlen((string) $value) > self::MAX_SEARCH_SCOPE_VALUE_BYTES) {
                        throw new RuntimeException("Invalid durable WP-CLI {$name} scope filter.");
                    }
                    $value = trim((string) $value);
                    if ($value !== '') {
                        $values[$value] = true;
                    }
                }
                if ($values === []) {
                    throw new RuntimeException("Invalid durable WP-CLI {$name} scope filter.");
                }
                $filters[$name] = array_keys($values);
                sort($filters[$name], SORT_STRING);
            }
            $lane_count = count($filters['post_status']) * count($filters['post_type']);
            if ($lane_count > self::MAX_FILTER_SCOPE_LANES) {
                throw new RuntimeException(
                    'A durable filtered reindex exceeds the '
                    . self::MAX_FILTER_SCOPE_LANES . '-lane query contract.'
                );
            }
            $page_limit = $remaining === null ? $limit : min($limit, $remaining);
            // One narrow named-index read keeps post-publication DDL damage
            // fail-closed. Together with the selector, every selective page
            // remains a fixed two-statement workflow on every supported
            // database.
            $index_hint = self::mysql_storage()->validated_filtered_scope_index_hint();
            $sqlite = self::database_adapter_is_sqlite($wpdb);
            $branches = [];
            $args = [];
            $lane = 0;
            foreach ($filters['post_type'] as $post_type) {
                foreach ($filters['post_status'] as $post_status) {
                    $lane++;
                    $alias = 'filtered_lane_' . $lane;
                    $lane_sql = "SELECT p.ID AS post_id, 1 AS should_process
FROM {$posts_table} p{$index_hint}
WHERE p.post_type = %s AND p.post_status = %s AND p.ID > %d
ORDER BY p.ID ASC
LIMIT %d";
                    $branches[] = $sqlite
                        ? "SELECT {$alias}.post_id, {$alias}.should_process
FROM (
    {$lane_sql}
) {$alias}"
                        : "({$lane_sql})";
                    array_push($args, $post_type, $post_status, $cursor, $page_limit);
                }
            }
            $args[] = $page_limit;
            $rows = $wpdb->get_results($wpdb->prepare(
                "/* wp_fts:filtered-scope-page */
SELECT filtered_candidates.post_id, MAX(filtered_candidates.should_process) AS should_process
FROM (" . implode("\nUNION ALL\n", $branches) . ") filtered_candidates
GROUP BY filtered_candidates.post_id
ORDER BY filtered_candidates.post_id ASC
LIMIT %d",
                ...$args
            ));
            self::assert_worker_database_result($rows, 'expand durable WP-CLI reindex scope');

            return self::scope_page_from_rows(
                is_array($rows) ? $rows : [],
                $cursor,
                $remaining
            );
        }

        $term_taxonomy_id = max(0, $scope_subject_id);
        if (
            $scope_coverage === WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED
            && $scope_subject_type === 'term_taxonomy'
            && $term_taxonomy_id > 0
        ) {
            $relationships = (string) ($wpdb->term_relationships ?? ((string) ($wpdb->prefix ?? '') . 'term_relationships'));
            $index_hint = self::mysql_storage()->validated_targeted_scope_index_hint();
            $rows = $wpdb->get_results($wpdb->prepare(
                "/* wp_fts:targeted-scope-page */
SELECT scope_rel.object_id AS post_id, 1 AS should_process
FROM {$relationships} scope_rel{$index_hint}
WHERE scope_rel.term_taxonomy_id = %d AND scope_rel.object_id > %d
ORDER BY scope_rel.object_id ASC
LIMIT %d",
                $term_taxonomy_id,
                $cursor,
                $limit
            ));
            self::assert_worker_database_result($rows, 'expand targeted FTS taxonomy scope');

            return self::scope_page_from_rows(is_array($rows) ? $rows : [], $cursor);
        }

        $post_types = self::configured_backfill_post_types();
        $branches = [];
        $args = [];
        $primary_hint = self::database_adapter_is_sqlite($wpdb) ? '' : ' FORCE INDEX (PRIMARY)';
        if ($post_types !== []) {
            [$clauses, $clause_args] = self::eligible_content_clauses_and_args('p', $post_types);
            $branches[] = "SELECT p.ID AS post_id,
       CASE WHEN p.post_password = ''
                  AND (" . implode(' OR ', $clauses) . ")
            THEN 1 ELSE 0 END AS should_process
FROM (
    SELECT raw_posts.ID, raw_posts.post_password, raw_posts.post_status, raw_posts.post_type
    FROM {$posts_table} raw_posts{$primary_hint}
    WHERE raw_posts.ID > %d
    ORDER BY raw_posts.ID ASC
    LIMIT %d
) p";
            array_push($args, ...$clause_args);
            array_push($args, $cursor, $limit);
        }
        // Retained rows must still be enumerated when the configured canonical
        // scope is empty so disabling every post type physically removes the
        // old index instead of leaving it searchable forever.
        $branches[] = "SELECT d.post_id, 1 AS should_process
FROM (
    SELECT raw_documents.post_id
    FROM {$documents_table} raw_documents{$primary_hint}
    WHERE raw_documents.post_id > %d
    ORDER BY raw_documents.post_id ASC
    LIMIT %d
) d";
        array_push($args, $cursor, $limit, $limit);
        $statement = $wpdb->prepare(
            "/* wp_fts:corpus-scope-page */
SELECT candidates.post_id, MAX(candidates.should_process) AS should_process
FROM (" . implode("\nUNION ALL\n", $branches) . ") candidates
GROUP BY candidates.post_id
ORDER BY candidates.post_id ASC
LIMIT %d",
            ...$args
        );
        $rows = $wpdb->get_results($statement);
        self::assert_worker_database_result($rows, 'expand FTS reconciliation scope');

        return self::scope_page_from_rows(is_array($rows) ? $rows : [], $cursor);
    }

    /**
     * Advance across every raw candidate while queueing only matching rows.
     *
     * @param array<int,mixed> $rows
     * @return array{post_ids:int[],cursor_post_id:int,scanned_count:int,exhausted:bool}
     */
    private static function scope_page_from_rows(array $rows, int $cursor, ?int $process_limit = null): array
    {
        $scanned = [];
        $post_ids = [];
        foreach ($rows as $row) {
            $post_id = is_object($row)
                ? max(0, (int) ($row->post_id ?? 0))
                : (is_array($row) ? max(0, (int) ($row['post_id'] ?? 0)) : 0);
            if ($post_id <= $cursor) {
                continue;
            }
            $scanned[$post_id] = true;
            $should_process = is_object($row)
                ? !empty($row->should_process)
                : (is_array($row) && !empty($row['should_process']));
            if (
                $should_process
                && !isset($post_ids[$post_id])
                && ($process_limit === null || count($post_ids) < $process_limit)
            ) {
                $post_ids[$post_id] = true;
            }
        }
        $scanned_ids = array_map('intval', array_keys($scanned));
        sort($scanned_ids, SORT_NUMERIC);
        $post_ids = array_map('intval', array_keys($post_ids));
        sort($post_ids, SORT_NUMERIC);

        return [
            'post_ids' => $post_ids,
            'cursor_post_id' => $scanned_ids === [] ? $cursor : max($scanned_ids),
            'scanned_count' => count($scanned_ids),
            'exhausted' => $scanned_ids === [],
        ];
    }

    /**
     * Preload posts, taxonomy labels, selected metadata, and language overrides.
     *
     * @param int[] $post_ids
     * @param array<int,array{exists:bool,bytes:int,canonical_bytes:int}> $source_measurements
     * @param array<int,object> $source_snapshots
     * @param array<int,array<string,mixed>> $index_options_by_post_id
     * @return array<int,object>
     */
    private static function load_posts_for_indexing(
        array $post_ids,
        array $source_measurements = [],
        array $source_snapshots = [],
        array $index_options_by_post_id = []
    ): array {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $post_ids), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        if (!isset($wpdb) || !is_object($wpdb) || !is_callable([$wpdb, 'prepare']) || !is_callable([$wpdb, 'get_results'])) {
            throw new RuntimeException('WordPress source storage is unavailable for FTS indexing.');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $posts_table = (string) ($wpdb->posts ?? ((string) ($wpdb->prefix ?? '') . 'posts'));
        $documents_table = (string) ($wpdb->prefix ?? '') . 'fts_documents';
        $source_bytes_sql = self::post_source_bytes_sql('p');
        $canonical_bytes_sql = self::canonical_post_bytes_sql('p');
        $measurements = [];
        if ($source_measurements !== []) {
            foreach ($ids as $post_id) {
                $measurement = $source_measurements[$post_id] ?? null;
                if (is_array($measurement) && !empty($measurement['exists'])) {
                    $measurements[$post_id] = [
                        'source_bytes' => self::nonnegative_database_integer($measurement['bytes'] ?? 0),
                        'canonical_bytes' => self::nonnegative_database_integer($measurement['canonical_bytes'] ?? 0),
                    ];
                }
            }
        } else {
            // Compatibility callers that did not claim through claim_batch()
            // retain the standalone preflight. Production workers receive the
            // same measurement from the indexed claim-confirmation read.
            $measurement_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, {$source_bytes_sql} AS fts_post_source_bytes,
       {$canonical_bytes_sql} AS fts_canonical_post_bytes
FROM {$posts_table} p
WHERE p.ID IN ({$placeholders})",
                ...$ids
            ));
            self::assert_worker_database_result($measurement_rows, 'measure FTS source posts');

            foreach (is_array($measurement_rows) ? $measurement_rows : [] as $row) {
                $post_id = max(0, (int) ($row->ID ?? 0));
                if ($post_id > 0) {
                    $measurements[$post_id] = [
                        'source_bytes' => self::nonnegative_database_integer($row->fts_post_source_bytes ?? 0),
                        'canonical_bytes' => self::nonnegative_database_integer($row->fts_canonical_post_bytes ?? 0),
                    ];
                }
            }
        }

        $posts = [];
        $source_budgets = [];
        $batch_source_bytes = 0;
        $source_budget_exhausted = false;
        foreach ($ids as $post_id) {
            if (!array_key_exists($post_id, $measurements)) {
                // A missing canonical row is deliberately omitted. The caller
                // converts that owned generation into a derived-row deletion.
                continue;
            }
            $source_bytes = $measurements[$post_id]['source_bytes'];
            $canonical_bytes = $measurements[$post_id]['canonical_bytes'];
            if ($canonical_bytes > WP_FTS_Storage_Mysql::MAX_CANONICAL_POST_BYTES) {
                $posts[$post_id] = self::rejected_index_source(
                    $post_id,
                    'canonical_post_bytes',
                    'The canonical WordPress post row exceeds the bounded FTS result transport.'
                );
                continue;
            }
            if ($source_bytes > WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES) {
                $posts[$post_id] = self::rejected_index_source(
                    $post_id,
                    'source_bytes',
                    'Saved FTS post fields exceed the 2 MiB document-source limit.'
                );
                continue;
            }
            if ($source_budget_exhausted || $batch_source_bytes + $source_bytes > self::MAX_INDEX_BATCH_SOURCE_BYTES) {
                $source_budget_exhausted = true;
                $posts[$post_id] = self::deferred_index_source($post_id);
                continue;
            }

            $batch_source_bytes += $source_bytes;
            $source_budgets[$post_id] = $source_bytes;
        }

        if ($source_budgets !== []) {
            $rows = [];
            $snapshot_complete = true;
            foreach ($source_budgets as $post_id => $source_budget) {
                $snapshot = $source_snapshots[$post_id] ?? null;
                if (
                    !is_object($snapshot)
                    || (int) ($snapshot->ID ?? 0) !== $post_id
                    || !property_exists($snapshot, 'fts_post_source_bytes')
                    || self::nonnegative_database_integer($snapshot->fts_post_source_bytes ?? -1) !== $source_budget
                    || !property_exists($snapshot, 'fts_canonical_post_bytes')
                    || self::nonnegative_database_integer($snapshot->fts_canonical_post_bytes ?? -1)
                        !== $measurements[$post_id]['canonical_bytes']
                ) {
                    $snapshot_complete = false;
                    break;
                }
                $rows[] = $snapshot;
            }

            if (!$snapshot_complete) {
                $budget_branches = [];
                $budget_args = [];
                foreach ($source_budgets as $post_id => $source_budget) {
                    $budget_branches[] = 'SELECT %d AS post_id, %d AS source_budget, %d AS canonical_budget';
                    array_push($budget_args, $post_id, $source_budget, $measurements[$post_id]['canonical_bytes']);
                }
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT p.ID,
       CASE WHEN {$source_bytes_sql} <= requested.source_budget THEN p.post_title ELSE '' END AS post_title,
       CASE WHEN {$source_bytes_sql} <= requested.source_budget THEN p.post_content ELSE '' END AS post_content,
       CASE WHEN {$source_bytes_sql} <= requested.source_budget THEN p.post_excerpt ELSE '' END AS post_excerpt,
       p.post_type, p.post_status, p.post_date_gmt, p.post_password,
       ({$source_bytes_sql} > requested.source_budget) AS fts_source_changed,
       ({$canonical_bytes_sql} > requested.canonical_budget) AS fts_canonical_changed,
       {$source_bytes_sql} AS fts_post_source_bytes,
       {$canonical_bytes_sql} AS fts_canonical_post_bytes,
       d.content_hash AS fts_existing_hash
FROM {$posts_table} p
INNER JOIN (
    " . implode("\n    UNION ALL\n    ", $budget_branches) . "
) requested ON requested.post_id = p.ID
LEFT JOIN {$documents_table} d ON d.post_id = p.ID
ORDER BY p.ID ASC",
                    ...$budget_args
                ));
                self::assert_worker_database_result($rows, 'load FTS source posts');
            }

            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_object($row) || (int) ($row->ID ?? 0) <= 0) {
                    continue;
                }
                $post_id = (int) $row->ID;
                if (!isset($source_budgets[$post_id])) {
                    continue;
                }
                if (!empty($row->fts_source_changed) || !empty($row->fts_canonical_changed)) {
                    // The source grew after the small length read. The
                    // conditional projection withheld all three source LOBs;
                    // canonical growth is also deferred before publication.
                    $posts[$post_id] = self::deferred_index_source($post_id);
                    continue;
                }

                $row->terms = [];
                $row->custom_fields = [];
                $row->fts_language_override = '';
                $posts[$post_id] = $row;
            }
        }

        if ($posts === []) {
            return [];
        }

        foreach ($posts as $post) {
            $post->fts_integration_language = '';
        }
        self::preload_index_dependencies($posts, $index_options_by_post_id);

        // Claims are handled in durable queue order, regardless of the source
        // engine's row order.
        $ordered = [];
        foreach ($ids as $post_id) {
            if (isset($posts[$post_id])) {
                $ordered[$post_id] = $posts[$post_id];
            }
        }

        return $ordered;
    }

    private static function polylang_language_from_row(object $row): string
    {
        $description = is_scalar($row->language_description ?? null)
            ? (string) $row->language_description
            : '';
        if ($description !== '') {
            try {
                $details = self::decode_preloaded_meta_value($description);
                if (is_array($details) && is_scalar($details['locale'] ?? null) && trim((string) $details['locale']) !== '') {
                    return WP_FTS_TermNamespace::canonicalize_lang((string) $details['locale']);
                }
            } catch (WP_FTS_Analysis_Limit_Exceeded) {
                // The bounded language slug below remains an authoritative fallback.
            }
        }
        $slug = is_scalar($row->language_slug ?? null) ? trim((string) $row->language_slug) : '';

        return $slug !== '' ? WP_FTS_TermNamespace::canonicalize_lang($slug) : '';
    }

    /**
     * @param array<int,object> $posts
     * @param array<int,array<string,mixed>> $index_options_by_post_id
     */
    private static function preload_index_dependencies(array &$posts, array $index_options_by_post_id = []): void
    {
        global $wpdb;

        foreach ($posts as $post) {
            if (!property_exists($post, 'fts_integration_language')) {
                $post->fts_integration_language = '';
            }
        }
        $extractor = new WP_FTS_PostContentExtractor();
        $configured = self::get_option(WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION, []);
        $meta_keys_by_post = [];
        foreach ($posts as $post_id => $post) {
            if (!empty($post->fts_index_deferred) || isset($post->fts_index_rejection)) {
                continue;
            }
            try {
                $index_options = $index_options_by_post_id[(int) $post_id] ?? [];
                if (
                    !array_key_exists('custom_fields', $index_options)
                    && !array_key_exists('custom_field_keys', $index_options)
                ) {
                    $index_options['custom_fields'] = $configured;
                }
                $index_options = self::prepare_post_index_options($post, $index_options);
                $custom_keys = $extractor->selected_custom_field_keys($post, $index_options);
            } catch (WP_FTS_Analysis_Limit_Exceeded $error) {
                $posts[$post_id] = self::rejected_index_source(
                    (int) $post_id,
                    $error->reason_code,
                    $error->getMessage()
                );
                continue;
            }

            $keys = [self::LANGUAGE_META_KEY => true];
            foreach ($custom_keys as $key) {
                $keys[$key] = true;
                $post->custom_fields[$key] = [];
            }
            $meta_keys_by_post[(int) $post_id] = array_keys($keys);
        }
        if ($meta_keys_by_post === []) {
            return;
        }

        // Prepared statements carry only a bounded prefix of post/key pairs.
        // A document keeps its full 32-key capability; overflow is deferred at
        // document boundaries instead of truncating the selected field set.
        $key_bytes = 0;
        $key_budget_exhausted = false;
        foreach ($posts as $post_id => $post) {
            if (!isset($meta_keys_by_post[$post_id])) {
                continue;
            }
            $post_key_bytes = array_sum(array_map('strlen', $meta_keys_by_post[$post_id]));
            if ($key_budget_exhausted || $key_bytes + $post_key_bytes > self::MAX_INDEX_BATCH_CUSTOM_FIELD_KEY_BYTES) {
                $key_budget_exhausted = true;
                $posts[$post_id] = self::deferred_index_source($post_id);
                unset($meta_keys_by_post[$post_id]);
                continue;
            }
            $key_bytes += $post_key_bytes;
        }
        if ($meta_keys_by_post === []) {
            return;
        }

        $dependency_snapshot = self::load_bounded_index_dependencies($posts, $meta_keys_by_post);
        $measurements = $dependency_snapshot['measurements'];
        $accepted_ids = [];
        $batch_bytes = 0;
        $batch_rows = 0;
        $batch_selected_rows = 0;
        $batch_budget_exhausted = false;
        foreach ($posts as $post_id => $post) {
            if (!isset($meta_keys_by_post[$post_id])) {
                continue;
            }
            $measurement = $measurements[$post_id] ?? self::empty_dependency_measurement();
            if ($measurement['rows'] > self::MAX_INDEX_DEPENDENCY_ROWS_PER_DOCUMENT) {
                $rejection = self::dependency_rejection(0, $measurement);
                $posts[$post_id] = self::rejected_index_source(
                    $post_id,
                    $rejection['reason_code'],
                    $rejection['message']
                );
                continue;
            }
            if (isset($dependency_snapshot['incomplete_post_ids'][$post_id])) {
                $batch_budget_exhausted = true;
                $posts[$post_id] = self::deferred_index_source($post_id);
                continue;
            }
            $source_bytes = self::nonnegative_database_integer($post->fts_post_source_bytes ?? 0);
            $rejection = self::dependency_rejection($source_bytes, $measurement);
            if ($rejection !== null) {
                $posts[$post_id] = self::rejected_index_source($post_id, $rejection['reason_code'], $rejection['message']);
                continue;
            }
            if (
                $batch_budget_exhausted
                || $batch_bytes + $source_bytes + $measurement['bytes'] > self::MAX_INDEX_BATCH_SOURCE_BYTES
                || $batch_rows + $measurement['rows'] > self::MAX_INDEX_BATCH_DEPENDENCY_ROWS
                || $batch_selected_rows + $measurement['selected_rows'] > self::MAX_INDEX_BATCH_SELECTED_DEPENDENCIES
            ) {
                $batch_budget_exhausted = true;
                $posts[$post_id] = self::deferred_index_source($post_id);
                continue;
            }

            $batch_bytes += $source_bytes + $measurement['bytes'];
            $batch_rows += $measurement['rows'];
            $batch_selected_rows += $measurement['selected_rows'];
            $accepted_ids[] = $post_id;
        }
        if ($accepted_ids === [] || $batch_rows === 0) {
            return;
        }

        $accepted_id_set = array_fill_keys($accepted_ids, true);
        $measurement_rows = array_values(array_filter(
            $dependency_snapshot['rows'],
            static fn(object $row): bool => isset($accepted_id_set[(int) ($row->post_id ?? 0)])
        ));
        if (count($measurement_rows) > $batch_rows) {
            // A complete per-post sentinel makes every accepted dependency
            // prefix self-consistent. Any disagreement remains bounded and
            // defers the participating set before any values are loaded.
            foreach ($accepted_ids as $post_id) {
                $posts[$post_id] = self::deferred_index_source($post_id);
            }

            return;
        }

        $value_snapshot = self::load_bounded_index_dependency_values($measurement_rows);
        foreach ($value_snapshot['incomplete_post_ids'] as $post_id => $_) {
            if (isset($accepted_id_set[$post_id])) {
                $posts[$post_id] = self::deferred_index_source($post_id);
                unset($accepted_id_set[$post_id]);
            }
        }

        foreach ($value_snapshot['rows'] as $row) {
            $post_id = max(0, (int) ($row->post_id ?? 0));
            if (!isset($accepted_id_set[$post_id])) {
                continue;
            }
            $key = (string) $row->item_key;
            $value = $row->item_value ?? '';
            if ((string) ($row->source_kind ?? '') === 'term') {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $posts[$post_id]->terms[$key][] = (string) $value;
                }
                continue;
            }
            try {
                $value = self::decode_preloaded_meta_value($value);
                $text_values = $extractor->flatten_preloaded_meta_value($value);
                if ($key === self::LANGUAGE_META_KEY) {
                    $posts[$post_id]->fts_language_override = is_scalar($value) ? (string) $value : '';
                } else {
                    foreach ($text_values as $text_value) {
                        $posts[$post_id]->custom_fields[$key][] = $text_value;
                    }
                }
            } catch (WP_FTS_Analysis_Limit_Exceeded $error) {
                $posts[$post_id] = self::rejected_index_source(
                    $post_id,
                    $error->reason_code,
                    $error->getMessage()
                );
                unset($accepted_id_set[$post_id]);
            } finally {
                // Never retain the decoded graph on the post object or across
                // dependency rows. Only the bounded scalar projection survives.
                unset($value, $text_values);
            }
        }
    }

    /**
     * Decode one WordPress meta value without constructing application objects.
     *
     * Invalid serialized-looking strings retain normal `maybe_unserialize()`
     * behavior and remain searchable as raw text. A valid graph is bounded by
     * depth here and by node/text limits in the immediate extractor call.
     */
    private static function decode_preloaded_meta_value(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $encoded = trim($value);
        if (!self::looks_like_serialized_meta_value($encoded)) {
            return $value;
        }

        $warning = '';
        $depth_exceeded = false;
        set_error_handler(static function (int $severity, string $message) use (&$warning, &$depth_exceeded): bool {
            $warning = $message;
            if (str_contains(strtolower($message), 'maximum depth')) {
                $depth_exceeded = true;
            }

            return true;
        });
        try {
            $decoded = unserialize($encoded, [
                'allowed_classes' => false,
                'max_depth' => self::MAX_SERIALIZED_META_DEPTH,
            ]);
        } finally {
            restore_error_handler();
        }

        if ($depth_exceeded) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'structured_value_depth',
                'An FTS structured field exceeds the 16-level nesting limit.'
            );
        }
        if ($warning !== '') {

            return $value;
        }
        if ($decoded === false && $encoded !== 'b:0;') {
            return $value;
        }

        return $decoded;
    }

    /** Narrow lexical gate before handing a candidate to PHP's parser. */
    private static function looks_like_serialized_meta_value(string $value): bool
    {
        if ($value === 'N;') {
            return true;
        }
        $length = strlen($value);
        if ($length < 4 || $value[1] !== ':') {
            return false;
        }
        if (!str_contains('aObisdCREr', $value[0])) {
            return false;
        }

        $last = $value[$length - 1];

        return $last === ';' || $last === '}';
    }

    private static function post_source_bytes_sql(string $alias): string
    {
        return "OCTET_LENGTH(COALESCE({$alias}.post_title, ''))"
            . " + OCTET_LENGTH(COALESCE({$alias}.post_content, ''))"
            . " + OCTET_LENGTH(COALESCE({$alias}.post_excerpt, ''))";
    }

    private static function canonical_post_bytes_sql(string $alias): string
    {
        return implode(' + ', array_map(
            static fn(string $column): string => "OCTET_LENGTH(COALESCE({$alias}.{$column}, ''))",
            WP_FTS_Storage_Mysql::CANONICAL_POST_COLUMNS
        ));
    }

    /**
     * Load a dependency prefix without scanning an unbounded post fanout.
     *
     * The measurement is one statement with fixed set-oriented arms for
     * taxonomy rows, metadata rows, optional bounded Polylang/WPML assignments,
     * and requested-post sentinels. Each dependency source
     * arm scans the native post-id index in deterministic order and stops after
     * 2,049 rows for the whole batch. PHP combines the two bounded streams and
     * retains at most 513 rows per post, so row 513 proves permanent overflow.
     * When a source arm reaches its limit, posts at and after that arm's numeric
     * frontier are incomplete and retry; earlier posts remain independently
     * complete. This trades batch breadth for a hard amount of database work.
     *
     * Metadata lengths are evaluated only for the bounded union of selected
     * keys. PHP still applies each post's exact key set, because filters may
     * select different keys for different posts. Values are deliberately absent
     * from this first statement. The second statement loads only accepted
     * identities with per-value power-of-two caps whose sum is at most twice the
     * measured batch bytes.
     *
     * @param array<int,object> $posts
     * @param array<int,string[]> $meta_keys_by_post
     * @return array{measurements:array<int,array{rows:int,selected_rows:int,bytes:int,max_value_bytes:int}>,rows:object[],incomplete_post_ids:array<int,bool>,overflow:bool}
     */
    private static function load_bounded_index_dependencies(array $posts, array $meta_keys_by_post): array
    {
        global $wpdb;

        [$requested, $excluded] = self::bounded_index_dependency_request($meta_keys_by_post);
        $prepared = null;
        $source_kinds = [];
        while ($requested !== []) {
            [$relation_sql, $relation_args, $source_kinds] = self::index_dependency_bounded_relation($requested);
            $sql = "SELECT bounded.post_order, bounded.row_order, bounded.source_kind,
       /* wp_fts:dependency_measurement */
       bounded.post_id, bounded.item_key, bounded.source_id,
       bounded.item_value_bytes, bounded.source_order, bounded.is_selected,
       bounded.item_value
FROM ({$relation_sql}) bounded";
            $prepared = $wpdb->prepare($sql, ...$relation_args);
            if (self::prepared_dependency_sql_bytes($prepared, $sql, $relation_args) <= self::MAX_INDEX_DEPENDENCY_SQL_BYTES) {
                break;
            }

            // A single document always fits: 32 keys of at most 191 bytes plus
            // three fixed arms are well below 32 KiB. Remove only a suffix so
            // the next invocation makes progress in the same queue order.
            $post_id = array_key_last($requested);
            if ($post_id === null || count($requested) === 1) {
                throw new RuntimeException('A bounded FTS dependency statement exceeds 32 KiB.');
            }
            $excluded[(int) $post_id] = true;
            unset($requested[$post_id]);
        }
        if ($requested === [] || $prepared === null) {
            throw new RuntimeException('FTS dependency read requires at least one source post.');
        }

        $result = $wpdb->get_results($prepared);
        self::assert_worker_database_result($result, 'load bounded FTS taxonomy and metadata rows');
        $result = is_array($result) ? $result : [];

        $measurements = [];
        foreach ($meta_keys_by_post as $post_id => $_keys) {
            $measurements[(int) $post_id] = self::empty_dependency_measurement();
        }
        $requested_order = [];
        foreach (array_keys($requested) as $post_order => $post_id) {
            $requested_order[(int) $post_id] = (int) $post_order;
        }

        $sentinels = [];
        $source_counts = array_fill_keys($source_kinds, 0);
        $source_frontiers = array_fill_keys($source_kinds, 0);
        $rows_by_post = [];
        $polylang_assigned = [];
        foreach ($result as $row) {
            $post_id = max(0, (int) ($row->post_id ?? 0));
            if ($post_id <= 0 || !isset($requested_order[$post_id])) {
                continue;
            }
            $kind = (string) ($row->source_kind ?? '');
            if ($kind === 'complete') {
                $sentinels[$post_id] = true;
                continue;
            }
            if (!array_key_exists($kind, $source_counts)) {
                continue;
            }

            $source_counts[$kind]++;
            $source_limit = $kind === 'wpml'
                ? WP_FTS_Index_Queue::MAX_CLAIM_POSTS + 1
                : self::MAX_INDEX_BATCH_DEPENDENCY_ROWS + 1;
            if ($source_counts[$kind] > $source_limit) {
                throw new RuntimeException('A bounded FTS dependency source exceeded its SQL row limit.');
            }
            $source_frontiers[$kind] = max($source_frontiers[$kind], $post_id);
            if ($kind === 'polylang') {
                if (isset($polylang_assigned[$post_id])) {
                    continue;
                }
                $language = self::polylang_language_from_row((object) [
                    'language_slug' => $row->item_key ?? '',
                    'language_description' => $row->item_value ?? '',
                ]);
                if ($language !== '') {
                    // Polylang wins regardless of UNION result order, matching
                    // the established integration precedence.
                    $posts[$post_id]->fts_integration_language = $language;
                    $polylang_assigned[$post_id] = true;
                }
                continue;
            }
            if ($kind === 'wpml') {
                $language = is_scalar($row->item_value ?? null) ? trim((string) $row->item_value) : '';
                if ($language !== '' && $posts[$post_id]->fts_integration_language === '') {
                    $posts[$post_id]->fts_integration_language = WP_FTS_TermNamespace::canonicalize_lang($language);
                }
                continue;
            }
            $row->post_order = $requested_order[$post_id];
            $rows_by_post[$post_id][] = $row;
        }

        $incomplete = $excluded;
        $rows = [];
        foreach ($requested as $post_id => $selected_keys) {
            $post_id = (int) $post_id;
            $complete = isset($sentinels[$post_id]);
            foreach ($source_kinds as $kind) {
                $source_limit = $kind === 'wpml'
                    ? WP_FTS_Index_Queue::MAX_CLAIM_POSTS + 1
                    : self::MAX_INDEX_BATCH_DEPENDENCY_ROWS + 1;
                if (
                    $source_counts[$kind] >= $source_limit
                    && $post_id >= $source_frontiers[$kind]
                ) {
                    $complete = false;
                }
            }
            if (!$complete) {
                $incomplete[$post_id] = true;
            }

            $post_rows = $rows_by_post[$post_id] ?? [];
            usort($post_rows, static function (object $left, object $right): int {
                return strcmp((string) ($left->source_kind ?? ''), (string) ($right->source_kind ?? ''))
                    ?: self::nonnegative_database_integer($left->source_order ?? 0)
                        <=> self::nonnegative_database_integer($right->source_order ?? 0);
            });
            $post_rows = array_slice($post_rows, 0, self::MAX_INDEX_DEPENDENCY_ROWS_PER_DOCUMENT + 1);
            foreach ($post_rows as $row) {
                $key = is_scalar($row->item_key ?? null) ? (string) $row->item_key : '';
                $selected = (string) ($row->source_kind ?? '') === 'term'
                    || in_array($key, $selected_keys, true);
                $row->is_selected = $selected ? 1 : 0;
                $value_bytes = $selected
                    ? self::nonnegative_database_integer($row->item_value_bytes ?? 0)
                    : 0;
                $measurements[$post_id]['rows']++;
                if ($selected) {
                    $measurements[$post_id]['selected_rows']++;
                    $measurements[$post_id]['bytes'] += strlen($key) + $value_bytes;
                }
                $measurements[$post_id]['max_value_bytes'] = max(
                    $measurements[$post_id]['max_value_bytes'],
                    $value_bytes
                );
                $rows[] = $row;
            }
        }

        $source_overflow = false;
        foreach ($source_counts as $kind => $count) {
            $source_limit = $kind === 'wpml'
                ? WP_FTS_Index_Queue::MAX_CLAIM_POSTS + 1
                : self::MAX_INDEX_BATCH_DEPENDENCY_ROWS + 1;
            if ($count >= $source_limit) {
                $source_overflow = true;
                break;
            }
        }

        return [
            'measurements' => $measurements,
            'rows' => $rows,
            'incomplete_post_ids' => $incomplete,
            'overflow' => $excluded !== [] || $source_overflow,
        ];
    }

    /**
     * Load accepted dependency values without letting concurrent LOB growth
     * defeat the batch byte budget established by the measurement statement.
     *
     * Source identities are grouped by the next power-of-two above their
     * measured length. Each SQL arm projects at most that bucket size. The
     * complete result is therefore bounded by less than twice the accepted
     * measurement, even if every underlying value grows between statements.
     * Changed or missing rows defer their whole post generation.
     *
     * @param object[] $measurement_rows
     * @return array{rows:object[],incomplete_post_ids:array<int,bool>}
     */
    private static function load_bounded_index_dependency_values(array $measurement_rows): array
    {
        global $wpdb;

        $expected = [];
        $groups = [];
        foreach ($measurement_rows as $row) {
            $kind = (string) ($row->source_kind ?? '');
            $post_id = max(0, (int) ($row->post_id ?? 0));
            $source_id = max(0, (int) ($row->source_id ?? 0));
            if (
                $post_id <= 0
                || $source_id <= 0
                || !in_array($kind, ['term', 'meta'], true)
                || ($kind === 'meta' && empty($row->is_selected))
            ) {
                continue;
            }

            $bytes = self::nonnegative_database_integer($row->item_value_bytes ?? 0);
            $bucket = self::dependency_value_bucket($bytes);
            $identity = $kind . ':' . $source_id;
            $expected[$identity][] = [
                'post_id' => $post_id,
                'item_key' => (string) ($row->item_key ?? ''),
                'item_value_bytes' => $bytes,
                'item_value_bucket' => $bucket,
                'source_order' => self::nonnegative_database_integer($row->source_order ?? 0),
            ];
            $groups[$kind][$bucket][$source_id] = true;
        }
        if ($expected === []) {
            return ['rows' => [], 'incomplete_post_ids' => []];
        }

        $is_sqlite = self::database_adapter_is_sqlite($wpdb);
        $branches = [];
        foreach ($groups['term'] ?? [] as $bucket => $ids) {
            $id_sql = implode(',', array_map('intval', array_keys($ids)));
            $value_sql = $is_sqlite
                ? "SUBSTR(CAST(t.name AS BLOB), 1, {$bucket})"
                : "LEFT(CAST(t.name AS BINARY), {$bucket})";
            $branches[] = "SELECT 'term' AS source_kind, /* wp_fts:dependency_values */ tt.term_taxonomy_id AS source_id,
       tt.taxonomy AS item_key, {$value_sql} AS item_value,
       OCTET_LENGTH(t.name) AS item_value_bytes
FROM {$wpdb->term_taxonomy} tt
JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
WHERE tt.term_taxonomy_id IN ({$id_sql})";
        }
        foreach ($groups['meta'] ?? [] as $bucket => $ids) {
            $id_sql = implode(',', array_map('intval', array_keys($ids)));
            $value_sql = $is_sqlite
                ? "SUBSTR(CAST(pm.meta_value AS BLOB), 1, {$bucket})"
                : "LEFT(CAST(pm.meta_value AS BINARY), {$bucket})";
            $branches[] = "SELECT 'meta' AS source_kind, /* wp_fts:dependency_values */ pm.meta_id AS source_id,
       pm.meta_key AS item_key, {$value_sql} AS item_value,
       OCTET_LENGTH(pm.meta_value) AS item_value_bytes
FROM {$wpdb->postmeta} pm
WHERE pm.meta_id IN ({$id_sql})";
        }
        if ($branches === []) {
            return ['rows' => [], 'incomplete_post_ids' => []];
        }
        if (count($branches) > self::MAX_INDEX_DEPENDENCY_VALUE_QUERY_BRANCHES) {
            throw new RuntimeException('A bounded FTS dependency-value statement has too many branches.');
        }

        $sql = implode("\nUNION ALL\n", $branches);
        if (strlen($sql) > self::MAX_INDEX_DEPENDENCY_SQL_BYTES) {
            throw new RuntimeException('A bounded FTS dependency-value statement exceeds 32 KiB.');
        }
        $result = $wpdb->get_results($sql);
        self::assert_worker_database_result($result, 'load bounded FTS taxonomy and metadata values');
        $actual = [];
        foreach (is_array($result) ? $result : [] as $row) {
            $kind = (string) ($row->source_kind ?? '');
            $source_id = max(0, (int) ($row->source_id ?? 0));
            if ($source_id > 0) {
                $actual[$kind . ':' . $source_id] = $row;
            }
        }

        $rows = [];
        $incomplete = [];
        foreach ($expected as $identity => $copies) {
            $source = $actual[$identity] ?? null;
            foreach ($copies as $copy) {
                $post_id = $copy['post_id'];
                if (
                    !is_object($source)
                    || (string) ($source->item_key ?? '') !== $copy['item_key']
                    || self::nonnegative_database_integer($source->item_value_bytes ?? 0) !== $copy['item_value_bytes']
                    || strlen((string) ($source->item_value ?? '')) !== $copy['item_value_bytes']
                    || strlen((string) ($source->item_value ?? '')) > $copy['item_value_bucket']
                ) {
                    $incomplete[$post_id] = true;
                    continue;
                }

                $rows[] = (object) [
                    'source_kind' => (string) $source->source_kind,
                    'post_id' => $post_id,
                    'item_key' => $copy['item_key'],
                    'item_value' => $source->item_value ?? '',
                    'item_value_bytes' => $copy['item_value_bytes'],
                    'source_order' => $copy['source_order'],
                ];
            }
        }

        return ['rows' => $rows, 'incomplete_post_ids' => $incomplete];
    }

    private static function dependency_value_bucket(int $bytes): int
    {
        if ($bytes <= 0) {
            return 0;
        }

        $bucket = 1;
        while ($bucket < $bytes && $bucket < self::MAX_INDEX_DEPENDENCY_VALUE_BYTES) {
            $bucket *= 2;
        }

        return min(self::MAX_INDEX_DEPENDENCY_VALUE_BYTES, $bucket);
    }

    /**
     * Keep even the prepared measurement below its packet-size contract.
     *
     * Every ID occurs in the two indexed arms and the sentinel arm. Selected
     * keys occur once. Reserving 8 KiB for fixed SQL and counting twice every
     * key byte covers MySQL string escaping without first constructing an
     * oversized statement. A suffix is deferred so at least one post always
     * advances in queue order.
     *
     * @param array<int,string[]> $meta_keys_by_post
     * @return array{0:array<int,string[]>,1:array<int,bool>}
     */
    private static function bounded_index_dependency_request(array $meta_keys_by_post): array
    {
        $requested = [];
        $excluded = [];
        $selected_keys = [];
        $estimated_bytes = self::MAX_INDEX_DEPENDENCY_SQL_SCAFFOLD_BYTES;
        $exhausted = false;
        foreach ($meta_keys_by_post as $post_id => $keys) {
            $post_id = max(0, (int) $post_id);
            $post_bytes = 3 * (strlen((string) $post_id) + 1);
            $new_keys = [];
            foreach ($keys as $key) {
                $key = (string) $key;
                if (!isset($selected_keys[$key])) {
                    $new_keys[$key] = true;
                }
            }
            foreach (array_keys($new_keys) as $key) {
                $post_bytes += (2 * strlen($key)) + 3;
            }

            if (
                $post_id <= 0
                || $exhausted
                || ($requested !== [] && $estimated_bytes + $post_bytes > self::MAX_INDEX_DEPENDENCY_SQL_BYTES)
            ) {
                $exhausted = true;
                if ($post_id > 0) {
                    $excluded[$post_id] = true;
                }
                continue;
            }

            $requested[$post_id] = $keys;
            $estimated_bytes += $post_bytes;
            foreach (array_keys($new_keys) as $key) {
                $selected_keys[$key] = true;
            }
        }

        return [$requested, $excluded];
    }

    /**
     * Build a fixed-branch relation over the bounded requested-post ID set.
     *
     * `IN (...)` is the constant requested-post relation here. Unlike one
     * derived query per post, both source arms use their native leading
     * post-id index and have one batch-wide row stop. The third arm emits one
     * sentinel from the primary-key post lookup. MySQL 5.7 and MariaDB need no
     * CTE, lateral join, window function, temporary table, or OFFSET.
     *
     * @param array<int,string[]> $meta_keys_by_post
     * @return array{0:string,1:array<int,int|string>,2:string[]}
     */
    private static function index_dependency_bounded_relation(array $meta_keys_by_post): array
    {
        global $wpdb;

        $post_ids = array_values(array_unique(array_filter(
            array_map('intval', array_keys($meta_keys_by_post)),
            static fn(int $post_id): bool => $post_id > 0
        )));
        sort($post_ids, SORT_NUMERIC);
        if ($post_ids === []) {
            throw new RuntimeException('FTS dependency read requires at least one source post.');
        }
        $post_placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $source_limit = self::MAX_INDEX_BATCH_DEPENDENCY_ROWS + 1;
        $branches = [];
        $args = [];
        $source_kinds = [];

        if (
            isset($wpdb->term_relationships, $wpdb->term_taxonomy, $wpdb->terms)
            && is_scalar($wpdb->term_relationships)
            && is_scalar($wpdb->term_taxonomy)
            && is_scalar($wpdb->terms)
        ) {
            $relationship_index_hint = self::database_adapter_is_sqlite($wpdb)
                ? ''
                : ' FORCE INDEX (PRIMARY)';
            $branches[] = "SELECT * FROM (
    SELECT tr.object_id AS post_order, 0 AS row_order, 'term' AS source_kind,
           tr.object_id AS post_id, tt.taxonomy AS item_key,
           tt.term_taxonomy_id AS source_id, OCTET_LENGTH(t.name) AS item_value_bytes,
           tt.term_taxonomy_id AS source_order, 1 AS is_selected, '' AS item_value
    FROM {$wpdb->term_relationships} tr{$relationship_index_hint}
    LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
    LEFT JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
    WHERE tr.object_id IN ({$post_placeholders})
    ORDER BY tr.object_id
    LIMIT {$source_limit}
) bounded_terms";
            array_push($args, ...$post_ids);
            $source_kinds[] = 'term';
        }

        $language_source_limit = WP_FTS_Index_Queue::MAX_CLAIM_POSTS + 1;
        $polylang_active = function_exists('pll_get_post_language')
            && (defined('POLYLANG_VERSION') || function_exists('PLL') || isset($GLOBALS['polylang']));
        if (
            $polylang_active
            && isset($wpdb->term_relationships, $wpdb->term_taxonomy, $wpdb->terms)
            && is_scalar($wpdb->term_relationships)
            && is_scalar($wpdb->term_taxonomy)
            && is_scalar($wpdb->terms)
        ) {
            $branches[] = "SELECT * FROM (
    SELECT tr.object_id AS post_order, 0 AS row_order, 'polylang' AS source_kind,
           /* wp_fts:polylang-languages */ tr.object_id AS post_id,
           CASE WHEN tt.taxonomy = %s THEN t.slug ELSE '' END AS item_key,
           tt.term_taxonomy_id AS source_id,
           0 AS item_value_bytes, tt.term_taxonomy_id AS source_order, 0 AS is_selected,
           CASE WHEN tt.taxonomy = %s AND OCTET_LENGTH(tt.description) <= 4096
                THEN tt.description ELSE '' END AS item_value
    FROM (
        SELECT raw_language_rel.object_id, raw_language_rel.term_taxonomy_id
        FROM {$wpdb->term_relationships} raw_language_rel{$relationship_index_hint}
        WHERE raw_language_rel.object_id IN ({$post_placeholders})
        ORDER BY raw_language_rel.object_id ASC, raw_language_rel.term_taxonomy_id ASC
        LIMIT {$source_limit}
    ) tr
    LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
    LEFT JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
    ORDER BY tr.object_id ASC, tr.term_taxonomy_id ASC
) bounded_polylang";
            array_push($args, 'language', 'language', ...$post_ids);
            $source_kinds[] = 'polylang';
        }

        if (isset($wpdb->postmeta) && is_scalar($wpdb->postmeta)) {
            // MariaDB otherwise chooses a full scan plus filesort when the
            // requested posts own most rows. Ordering only by the leading
            // `post_id` key is sufficient for the completion frontier, so
            // forcing that index makes LIMIT stop the actual source scan.
            $postmeta_index_hint = self::database_adapter_is_sqlite($wpdb)
                ? ''
                : ' FORCE INDEX (post_id)';
            $selected_keys = [];
            foreach ($meta_keys_by_post as $keys) {
                foreach ($keys as $key) {
                    $selected_keys[(string) $key] = true;
                }
            }
            $selected_keys = array_keys($selected_keys);
            sort($selected_keys, SORT_STRING);
            $selected_value_bytes_sql = $selected_keys === []
                ? '0'
                : 'CASE WHEN pm.meta_key IN (' . implode(',', array_fill(0, count($selected_keys), '%s'))
                    . ') THEN OCTET_LENGTH(pm.meta_value) ELSE 0 END';
            $branches[] = "SELECT * FROM (
    SELECT pm.post_id AS post_order, 0 AS row_order, 'meta' AS source_kind,
           pm.post_id, pm.meta_key AS item_key, pm.meta_id AS source_id,
           {$selected_value_bytes_sql} AS item_value_bytes,
           pm.meta_id AS source_order, 0 AS is_selected, '' AS item_value
    FROM {$wpdb->postmeta} pm{$postmeta_index_hint}
    WHERE pm.post_id IN ({$post_placeholders})
    ORDER BY pm.post_id
    LIMIT {$source_limit}
) bounded_meta";
            array_push($args, ...$selected_keys, ...$post_ids);
            $source_kinds[] = 'meta';
        }

        $wpml_active = function_exists('has_filter')
            && has_filter('wpml_post_language_details')
            && (defined('ICL_SITEPRESS_VERSION') || isset($GLOBALS['sitepress']));
        if ($wpml_active) {
            $translations = (string) ($wpdb->prefix ?? '') . 'icl_translations';
            $wpml_post_index_hint = self::database_adapter_is_sqlite($wpdb)
                ? ''
                : ' FORCE INDEX (PRIMARY)';
            $wpml_translation_index_hint = self::database_adapter_is_sqlite($wpdb)
                ? ''
                : ' FORCE INDEX (el_type_id)';
            $wpml_join = self::database_adapter_is_sqlite($wpdb) ? 'INNER JOIN' : 'STRAIGHT_JOIN';
            $wpml_element_type = self::database_adapter_is_sqlite($wpdb)
                ? "('post_' || wpml_post.post_type)"
                : "CONCAT('post_', wpml_post.post_type)";
            $branches[] = "SELECT * FROM (
    SELECT wpml_post.ID AS post_order, 0 AS row_order, 'wpml' AS source_kind,
           /* wp_fts:wpml-languages */ wpml_post.ID AS post_id,
           '' AS item_key, wpml_translation.translation_id AS source_id,
           0 AS item_value_bytes, wpml_translation.translation_id AS source_order, 0 AS is_selected,
           CASE WHEN OCTET_LENGTH(wpml_translation.language_code) <= 64 THEN wpml_translation.language_code ELSE '' END AS item_value
    FROM {$wpdb->posts} wpml_post{$wpml_post_index_hint}
    {$wpml_join} {$translations} wpml_translation{$wpml_translation_index_hint}
      ON wpml_translation.element_type = {$wpml_element_type}
     AND wpml_translation.element_id = wpml_post.ID
    WHERE wpml_post.ID IN ({$post_placeholders})
    ORDER BY wpml_post.ID ASC
    LIMIT {$language_source_limit}
) bounded_wpml";
            array_push($args, ...$post_ids);
            $source_kinds[] = 'wpml';
        }

        if (!isset($wpdb->posts) || !is_scalar($wpdb->posts)) {
            throw new RuntimeException('FTS dependency read requires the WordPress posts table.');
        }
        $branches[] = "SELECT p.ID AS post_order, 1 AS row_order, 'complete' AS source_kind,
       p.ID AS post_id, '' AS item_key, 0 AS source_id,
       0 AS item_value_bytes, 0 AS source_order, 0 AS is_selected, '' AS item_value
FROM {$wpdb->posts} p
WHERE p.ID IN ({$post_placeholders})";
        array_push($args, ...$post_ids);

        if (count($branches) > self::MAX_INDEX_DEPENDENCY_QUERY_BRANCHES) {
            throw new RuntimeException('A bounded FTS dependency statement has too many branches.');
        }

        return [implode("\nUNION ALL\n", $branches), $args, $source_kinds];
    }

    /** @param array<int,int|string> $args */
    private static function prepared_dependency_sql_bytes(mixed $prepared, string $template, array $args): int
    {
        if (is_string($prepared)) {
            return strlen($prepared);
        }

        // Test adapters retain the template and arguments separately. Count a
        // conservative MySQL rendering so the same 32 KiB invariant is tested.
        $bytes = strlen($template);
        foreach ($args as $arg) {
            $rendered = is_int($arg)
                ? (string) $arg
                : "'" . addslashes((string) $arg) . "'";
            $bytes += max(0, strlen($rendered) - 2);
        }

        return $bytes;
    }

    /** @return array{rows:int,selected_rows:int,bytes:int,max_value_bytes:int} */
    private static function empty_dependency_measurement(): array
    {
        return ['rows' => 0, 'selected_rows' => 0, 'bytes' => 0, 'max_value_bytes' => 0];
    }

    /**
     * @param array{rows:int,selected_rows:int,bytes:int,max_value_bytes:int} $measurement
     * @return null|array{reason_code:string,message:string}
     */
    private static function dependency_rejection(int $source_bytes, array $measurement): ?array
    {
        if ($measurement['rows'] > self::MAX_INDEX_DEPENDENCY_ROWS_PER_DOCUMENT) {
            return [
                'reason_code' => 'dependency_rows',
                'message' => 'An FTS document has more than 512 total taxonomy and wp_postmeta rows, including unselected meta keys.',
            ];
        }
        if ($measurement['max_value_bytes'] > self::MAX_INDEX_DEPENDENCY_VALUE_BYTES) {
            return [
                'reason_code' => 'dependency_value_bytes',
                'message' => 'An FTS taxonomy or metadata value exceeds 256 KiB.',
            ];
        }
        if ($source_bytes + $measurement['bytes'] > WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES) {
            return [
                'reason_code' => 'document_source_bytes',
                'message' => 'Saved post fields and FTS dependency rows exceed 2 MiB in total.',
            ];
        }

        return null;
    }

    private static function deferred_index_source(int $post_id): object
    {
        return (object) [
            'ID' => $post_id,
            'fts_index_deferred' => true,
        ];
    }

    private static function rejected_index_source(int $post_id, string $reason_code, string $message): object
    {
        return (object) [
            'ID' => $post_id,
            'fts_index_rejection' => [
                'reason_code' => $reason_code,
                'message' => $message,
            ],
        ];
    }

    private static function nonnegative_database_integer(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private static function assert_worker_database_result(mixed $result, string $context): void
    {
        global $wpdb;

        if ($result === false || (isset($wpdb->last_error) && trim((string) $wpdb->last_error) !== '')) {
            $error = isset($wpdb->last_error) ? trim((string) $wpdb->last_error) : '';
            throw new RuntimeException("Failed to {$context}" . ($error !== '' ? ": {$error}" : '.'));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function default_index_batch_summary(string $mode, int $batch_size): array
    {
        return [
            'mode' => $mode,
            'batch_size' => $batch_size,
            'attempted' => 0,
            'processed' => 0,
            'committed' => 0,
            'superseded' => 0,
            'indexed' => 0,
            'analyzed' => 0,
            'queue_processed' => 0,
            'unchanged' => 0,
            'deleted' => 0,
            'permanently_rejected' => 0,
            'retryable_failures' => 0,
            'deferred' => 0,
            'empty_terms_cleaned' => 0,
            'cleanup_pending' => false,
            'backfill_processed' => 0,
            'has_more' => false,
            'wait_for_next_available' => false,
            'next_available_at' => null,
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
            'scope_completed' => false,
            'scope_completed_global' => false,
            'lock_before' => [],
            'lock_after' => [],
            'lock_prevented_work' => false,
            'schema_status' => '',
            'schema_version' => 0,
            'expected_schema_version' => 0,
            'storage_backend' => '',
            'error_class' => '',
            'error_message' => '',
            'successor_schedule_failed' => false,
            'reschedule_decision' => '',
            'stop_reason' => '',
            'failure_records' => [],
            'resolved_failure_post_ids' => [],
            'resolved_failure_records' => false,
            'failure_recovery_skipped' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $opts
     * @param array<string,mixed> $summary
     */
    private static function initialize_index_batch_summary(array &$summary, array $opts, float $started): void
    {
        $stored_schema_version = self::schema_version_from_option(self::get_option(self::SCHEMA_VERSION_OPTION, null));
        $summary['source'] = self::index_batch_source($summary['mode'] ?? 'manual', $opts);
        $summary['started_at'] = self::current_gmt_datetime();
        // Queue depth belongs to explicit operator diagnostics. Counting the
        // whole durable work table before every production batch adds load but
        // does not affect correctness or scheduling.
        $summary['queue_before'] = null;
        // Lease diagnostics are populated only on contention. Reading the
        // options table before and after every successful worker would add two
        // statements that cannot affect the batch.
        $summary['lock_before'] = [];
        $summary['schema_status'] = $stored_schema_version === self::SCHEMA_VERSION ? 'current' : 'maintenance_pending';
        $summary['schema_version'] = $stored_schema_version;
        $summary['expected_schema_version'] = self::SCHEMA_VERSION;
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

    /** Return the canonical hash for the analyzer/index settings in force now. */
    private static function current_index_profile_hash(): string
    {
        $profile = self::current_index_profile();

        return self::sanitize_index_profile_hash($profile['hash'] ?? self::index_profile_hash($profile));
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

        $type_placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        $status_placeholders = implode(',', array_fill(0, count(self::ADMIN_POST_SEARCH_POST_STATUSES), '%s'));
        $clauses[] = "({$alias}.post_type IN ({$type_placeholders}) AND {$alias}.post_status IN ({$status_placeholders}))";
        array_push($args, ...$post_types, ...self::ADMIN_POST_SEARCH_POST_STATUSES);

        return [$clauses, $args];
    }

    /** @return string[] */
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
        $summary['last_failed_post_title'] = self::failure_post_title($post);
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
     * Keep diagnostics aligned with the failure phase actually made durable.
     *
     * @param int[] $post_ids
     */
    private static function retain_index_failure_summary_for_posts(array &$summary, array $post_ids): void
    {
        $allowed = [];
        foreach ($post_ids as $post_id) {
            $post_id = max(0, (int) $post_id);
            if ($post_id > 0) {
                $allowed[$post_id] = true;
            }
        }
        $events = [];
        foreach (is_array($summary['failure_records'] ?? null) ? $summary['failure_records'] : [] as $event) {
            $post_id = is_array($event) ? max(0, (int) ($event['post_id'] ?? 0)) : 0;
            if ($post_id > 0 && isset($allowed[$post_id])) {
                $events[] = $event;
            }
        }
        $summary['failure_records'] = $events;
        $summary['last_batch_failures'] = count($events);
        $last = $events === [] ? null : $events[array_key_last($events)];
        if (!is_array($last)) {
            $summary['last_failed_post_id'] = 0;
            $summary['last_failed_post_title'] = '';
            $summary['last_failed_at'] = '';
            $summary['last_error'] = '';
            $summary['last_error_class'] = '';
            $summary['last_error_message'] = '';
            $summary['error_class'] = '';
            $summary['error_message'] = '';
            return;
        }

        $summary['last_failed_post_id'] = max(0, (int) ($last['post_id'] ?? 0));
        $summary['last_failed_post_title'] = is_scalar($last['title'] ?? null) ? (string) $last['title'] : '';
        $summary['last_failed_at'] = is_scalar($last['failed_at'] ?? null) ? (string) $last['failed_at'] : '';
        $summary['last_error'] = is_scalar($last['error_summary'] ?? null) ? (string) $last['error_summary'] : '';
        $summary['last_error_class'] = is_scalar($last['error_class'] ?? null) ? (string) $last['error_class'] : '';
        $summary['last_error_message'] = is_scalar($last['error_message'] ?? null) ? (string) $last['error_message'] : '';
        $summary['error_class'] = $summary['last_error_class'];
        $summary['error_message'] = $summary['last_error_message'];
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
     * Keep every uncaught worker dependency failure off the one-second path.
     *
     * The queue owns per-generation exponential backoff when it is available.
     * This batch-level floor also covers failures before a claim exists, or a
     * damaged work table that cannot persist its own retry timestamp.
     *
     * @param array<string,mixed> $summary
     */
    private static function remember_index_batch_systemic_backoff(array &$summary, string $reason): void
    {
        self::remember_index_batch_stop($summary, $reason);
        $summary['wait_for_next_available'] = true;
        $not_before = time() + self::SYSTEMIC_WORKER_BACKOFF_SECONDS;
        $current = is_numeric($summary['next_available_at'] ?? null)
            ? (int) $summary['next_available_at']
            : 0;
        $summary['next_available_at'] = max($current, $not_before);
    }

    /** Record a failed worker handoff without retrying or writing diagnostics. */
    private static function remember_index_batch_successor_schedule_failure(
        array &$summary,
        ?Throwable $previous = null
    ): void {
        $error = new WP_FTS_Index_Successor_Schedule_Failed(previous: $previous);
        self::remember_index_batch_exception_in_summary($summary, $error);
        $summary['successor_schedule_failed'] = true;
        $summary['stop_reason'] = $error->reason_code;
        $summary['reschedule_decision'] = $error->reason_code;
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function finalize_index_batch_summary(array &$summary, float $started): void
    {
        $summary['finished_at'] = self::current_gmt_datetime();
        $summary['elapsed_ms'] = max(0.0, (microtime(true) - $started) * 1000.0);
        $summary['queue_after'] = null;
        $summary['lock_after'] = [];

        if (
            empty($summary['stop_reason'])
            && !empty($summary['has_more'])
            && max(0, (int) ($summary['attempted'] ?? 0)) >= max(1, (int) ($summary['batch_size'] ?? 1))
        ) {
            $summary['stop_reason'] = 'batch_cap';
        }

        if (empty($summary['status']) || $summary['status'] === 'started') {
            if (!empty($summary['skipped_locked'])) {
                $summary['status'] = 'skipped_locked';
            } elseif (max(0, (int) ($summary['last_batch_failures'] ?? 0)) > 0) {
                $summary['status'] = max(0, (int) ($summary['committed'] ?? 0))
                    > max(0, (int) ($summary['permanently_rejected'] ?? 0))
                    ? 'partial_failure'
                    : 'failed';
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
        if (!empty($summary['successor_schedule_failed'])) {
            return 'successor_schedule_failed';
        }

        $mode = is_scalar($summary['mode'] ?? null) ? (string) $summary['mode'] : '';
        if ($mode !== 'cron') {
            return 'not_applicable_manual';
        }

        if (empty($summary['has_more'])) {
            return 'not_needed';
        }

        if (!empty($summary['wait_for_next_available'])) {
            return 'scheduled_at_availability';
        }

        return !empty($summary['skipped_locked']) ? 'scheduled_after_lock_skip' : 'scheduled';
    }

    private static function failure_post_title(?object $post): string
    {
        $title = $post !== null && isset($post->post_title) && is_scalar($post->post_title)
            ? (string) $post->post_title
            : '';

        // Oversized-source sentinels intentionally do not load any canonical
        // LOB. Looking the title up here would turn a 100-document poison batch
        // into one hidden query per post, so diagnostics keep the stable ID and
        // leave the optional title blank.

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
            'title' => self::failure_post_title($post),
            'failed_at' => self::sanitize_index_timestamp($failed_at),
            'mode' => self::sanitize_index_diagnostic_text($summary['mode'] ?? '', 40, false),
            'source' => self::sanitize_index_diagnostic_text($summary['source'] ?? '', 60, false),
            'error_class' => self::sanitize_index_diagnostic_text(get_class($error), self::MAX_INDEX_DIAGNOSTIC_ERROR_CLASS_BYTES, false),
            'error_message' => self::sanitize_index_failure_text($error->getMessage(), self::MAX_INDEX_FAILURE_ERROR_BYTES),
            'error_summary' => self::index_failure_error_summary($error),
            'status' => $error instanceof WP_FTS_Analysis_Limit_Exceeded || $error instanceof WP_FTS_Prepared_Document_Rejected
                ? 'rejected'
                : 'backoff',
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
            $status = self::sanitize_failure_recovery_status($event['status'] ?? '') ?: 'backoff';
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
                $status = 'retryable';
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

        // Version-1 records may contain the old terminal label. The durable
        // queue now retries those generations automatically, so expose them as
        // retryable instead of preserving a false operator-only state.
        if ($status === 'quarantined') {
            return 'retryable';
        }

        return in_array($status, ['retryable', 'backoff', 'rejected'], true) ? $status : '';
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
        if ($status === 'rejected') {
            return 'rejected';
        }
        if ($status === 'backoff') {
            $retry_at = self::failure_recovery_retry_timestamp($record['next_retry_at'] ?? '');
            if ($retry_at !== null && $retry_at > ($now ?? time())) {
                return 'backoff';
            }

            return 'retryable';
        }

        return 'retryable';
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
        $rejected = 0;
        $oldest = '';
        $newest = '';
        foreach ($records as $record) {
            $status = self::sanitize_failure_recovery_status($record['status'] ?? '');
            if ($status === 'retryable') {
                $retryable++;
            } elseif ($status === 'backoff') {
                $backoff++;
            } elseif ($status === 'rejected') {
                $rejected++;
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
            'rejected_count' => $rejected,
            'oldest_failed_at' => $oldest,
            'newest_failed_at' => $newest,
        ];
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function failure_recovery_advice(array $summary): string
    {
        if (max(0, (int) ($summary['rejected_count'] ?? 0)) > 0) {
            return 'Some documents were removed from the derived index after crossing a permanent safety boundary. Change the canonical content before explicitly retrying them.';
        }
        if (max(0, (int) ($summary['backoff_count'] ?? 0)) > 0) {
            return 'Some failed items are in capped backoff and will retry automatically at their next retry time. Use WP-CLI retry only after the underlying issue is fixed.';
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
            'pending_queue_count' => self::pending_queue_count(),
            'message' => self::sanitize_index_failure_text($message, self::MAX_INDEX_FAILURE_ERROR_BYTES, false),
        ];
    }

    /**
     * @param array<string,mixed> $opts
     */
    private static function index_batch_deadline(array $opts, float $started): ?float
    {
        if (!isset($opts['time_budget']) || !is_numeric($opts['time_budget'])) {
            return null;
        }
        $seconds = (float) $opts['time_budget'];

        return is_finite($seconds) && $seconds > 0.0 ? $started + $seconds : null;
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

    private static function acquire_index_lock(
        string $mode,
        ?string &$blocked_reason = null,
        ?bool &$recovered_stale_lease = null
    ): ?string
    {
        $blocked_reason = null;
        $recovered_stale_lease = false;
        $may_cross_uninstall_fence = self::writer_mode_may_cross_uninstall_fence($mode);
        $now = time();
        $ttl = self::configured_int_constant('WP_FTS_INDEX_LOCK_TTL', self::DEFAULT_INDEX_LOCK_TTL, 30, 3600);
        $token = bin2hex(random_bytes(12));
        $payload = [
            'token' => $token,
            'mode' => $mode,
            'started_at' => $now,
            'heartbeat_at' => $now,
            'expires_at' => $now + $ttl,
            'renewals' => 0,
        ];

        if (function_exists('add_option')) {
            // The uncontended path is one atomic INSERT. Reading first would
            // add a statement to every worker merely to learn that no row
            // exists. Only a failed insert pays to inspect and replace an
            // expired or malformed predecessor.
            $inserted = self::insert_index_lock_in_database($payload, $may_cross_uninstall_fence);
            if ($inserted === null && !$may_cross_uninstall_fence && self::uninstall_fence_active()) {
                $blocked_reason = 'uninstall_fenced';
                return null;
            }
            if ($inserted === true || ($inserted === null && add_option(self::INDEX_LOCK_OPTION, $payload, '', 'no'))) {
                return $token;
            }
            $existing = self::get_option(self::INDEX_LOCK_OPTION, null);
            if ($existing === null && !$may_cross_uninstall_fence && self::uninstall_fence_active()) {
                $blocked_reason = 'uninstall_fenced';
                return null;
            }
            if (self::lock_payload_active($existing, $now)) {
                return null;
            }
            if ($existing !== null && !self::compare_and_delete_index_lock($existing)) {
                return null;
            }

            $inserted = self::insert_index_lock_in_database($payload, $may_cross_uninstall_fence);
            if ($inserted === null) {
                $inserted = add_option(self::INDEX_LOCK_OPTION, $payload, '', 'no');
            }

            if ($inserted) {
                $recovered_stale_lease = true;
                return $token;
            }
            if (!$may_cross_uninstall_fence && self::uninstall_fence_active()) {
                // An uninstall can begin after the stale row was deleted but
                // before the fenced replacement INSERT. Classify that failed
                // CAS without adding a statement to either successful path.
                $blocked_reason = 'uninstall_fenced';
            }

            return null;
        }

        if (!$may_cross_uninstall_fence && self::uninstall_fence_active()) {
            $blocked_reason = 'uninstall_fenced';
            return null;
        }
        $existing = self::get_option(self::INDEX_LOCK_OPTION, null);
        if (self::lock_payload_active($existing, $now)) {
            return null;
        }
        if ($existing !== null && !self::compare_and_delete_index_lock($existing)) {
            return null;
        }
        self::set_option(self::INDEX_LOCK_OPTION, $payload);
        $stored = self::get_option(self::INDEX_LOCK_OPTION, null);
        if (is_array($stored) && ($stored['token'] ?? null) === $token) {
            $recovered_stale_lease = $existing !== null;
            return $token;
        }

        return null;
    }

    private static function writer_mode_may_cross_uninstall_fence(string $mode): bool
    {
        return in_array($mode, ['uninstall', 'activation', 'network-activation-provision'], true);
    }

    /**
     * Insert an uncontended lease without add_option()'s preceding existence read.
     *
     * @param array<string,mixed> $payload
     * @return bool|null Null when the native WordPress option table is unavailable.
     */
    private static function insert_index_lock_in_database(
        array $payload,
        bool $may_cross_uninstall_fence
    ): ?bool
    {
        global $wpdb;

        if (
            !isset($wpdb)
            || !is_object($wpdb)
            || !isset($wpdb->options)
            || !is_scalar($wpdb->options)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'query')
            || !function_exists('maybe_serialize')
            || self::database_adapter_is_sqlite($wpdb)
        ) {
            return null;
        }

        $table = (string) $wpdb->options;
        $statement = $may_cross_uninstall_fence
            ? $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (option_name,option_value,autoload) VALUES (%s,%s,%s)",
                self::INDEX_LOCK_OPTION,
                maybe_serialize($payload),
                'no'
            )
            : $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (option_name,option_value,autoload)
SELECT %s,%s,%s
WHERE NOT EXISTS (
    SELECT 1 FROM {$table} uninstall_fence
    WHERE uninstall_fence.option_name = %s
)",
                self::INDEX_LOCK_OPTION,
                maybe_serialize($payload),
                'no',
                self::UNINSTALL_FENCE_OPTION
            );
        $result = $wpdb->query($statement);
        if ($result === false) {
            throw new RuntimeException('Could not acquire the FTS index writer lease.');
        }
        if ((int) $result !== 1) {
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete(self::INDEX_LOCK_OPTION, 'options');
                wp_cache_delete('notoptions', 'options');
            }

            return false;
        }

        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('notoptions', 'options');
        }
        if (function_exists('wp_cache_set')) {
            wp_cache_set(self::INDEX_LOCK_OPTION, $payload, 'options');
        }

        return true;
    }

    /**
     * Renew the active writer capability once at the bounded transaction boundary.
     *
     * @throws WP_FTS_Index_Writer_Ownership_Lost When another writer replaced
     *         the lease or its expiry passed before renewal.
     */
    public static function heartbeat_index_writer(bool $force = false): void
    {
        $token = self::$active_index_writer_token;
        $current = self::assert_index_writer_ownership();
        if ($token === null) {
            throw new WP_FTS_Index_Writer_Ownership_Lost('FTS index writer ownership was lost before lease renewal.');
        }

        $now = time();
        $ttl = self::configured_int_constant('WP_FTS_INDEX_LOCK_TTL', self::DEFAULT_INDEX_LOCK_TTL, 30, 3600);
        $renew_before = max(5, min(60, intdiv($ttl, 3)));
        if (!$force && (int) ($current['expires_at'] ?? 0) - $now > $renew_before) {
            return;
        }

        $renewed = $current;
        $renewed['heartbeat_at'] = $now;
        $renewed['expires_at'] = $now + $ttl;
        $renewed['renewals'] = max(0, (int) ($current['renewals'] ?? 0)) + 1;

        if (!self::compare_and_swap_index_lock($current, $renewed)) {
            throw new WP_FTS_Index_Writer_Ownership_Lost('FTS index writer ownership changed during lease renewal.');
        }

        self::assert_index_writer_ownership();
    }

    private static function release_index_lock(string $token): void
    {
        $lock = self::get_option(self::INDEX_LOCK_OPTION, null);
        if (is_array($lock) && ($lock['token'] ?? null) === $token) {
            self::compare_and_delete_index_lock($lock);
        }
    }

    /**
     * Acknowledge exact generations while retaining the exact writer lease.
     *
     * Work membership and the cursor epoch change in one transactional-table
     * commit. The outer worker retains that lease for its optional bounded
     * dictionary cleanup and retires it in the common finally block. This is
     * safe when a legacy site's wp_options table is MyISAM: a crash can leave a
     * short-lived diagnostic lease, but cannot publish work without its
     * matching epoch or erase a successor's lease.
     *
     * @param array<int,array<string,mixed>> $claims
     */
    private static function acknowledge_claims_under_index_lock(
        array $claims,
        ?WP_FTS_Storage_Mysql $storage = null
    ): ?array
    {
        global $wpdb;

        if (
            $claims === []
            || self::$active_index_writer_token === null
            || !isset($wpdb)
            || !is_object($wpdb)
            || !isset($wpdb->options)
            || !is_scalar($wpdb->options)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'query')
            || !function_exists('maybe_serialize')
            || !self::supports_atomic_worker_acknowledgement()
        ) {
            return null;
        }

        $lock = self::get_option(self::INDEX_LOCK_OPTION, null);
        $token = self::$active_index_writer_token;
        if (
            !is_array($lock)
            || !is_scalar($lock['token'] ?? null)
            || !hash_equals($token, (string) $lock['token'])
        ) {
            throw new WP_FTS_Index_Writer_Ownership_Lost('FTS index writer ownership changed before queue acknowledgement.');
        }

        $identity_rows = [];
        $args = [];
        $seen = [];
        foreach ($claims as $claim) {
            $post_id = max(0, (int) ($claim['post_id'] ?? 0));
            $job_key = is_scalar($claim['job_key'] ?? null) ? (string) $claim['job_key'] : '';
            $generation = max(0, (int) ($claim['generation'] ?? 0));
            $claim_token = is_scalar($claim['token'] ?? null) ? (string) $claim['token'] : '';
            if (
                $post_id <= 0
                || !WP_FTS_Index_Queue::is_post_job_key($job_key, $post_id)
                || $generation <= 0
                || $claim_token === ''
                || strlen($claim_token) > 64
                || isset($seen[$job_key])
            ) {
                continue;
            }
            $seen[$job_key] = true;
            $identity_rows[] = $identity_rows === []
                ? 'SELECT %s AS job_key, %s AS claim_token, %d AS claimed_generation, %d AS generation'
                : 'SELECT %s, %s, %d, %d';
            array_push($args, $job_key, $claim_token, $generation, $generation);
        }
        if ($identity_rows === []) {
            return null;
        }

        $work_table = (string) ($wpdb->prefix ?? '') . 'fts_work';
        $claim_count = count($identity_rows);
        $driver = "SELECT bounded_claims.*
FROM (" . implode("\nUNION ALL\n", $identity_rows) . ") bounded_claims
LIMIT {$claim_count}";
        $statement = $wpdb->prepare(
            "DELETE /* wp_fts:atomic-worker-ack */ work_row
FROM ({$driver}) claim_driver
STRAIGHT_JOIN {$work_table} work_row
        ON work_row.job_key = claim_driver.job_key
       AND work_row.claim_token = claim_driver.claim_token
       AND work_row.claimed_generation = claim_driver.claimed_generation
       AND work_row.generation = claim_driver.generation",
            ...$args
        );
        $transaction_started = false;
        $uses_storage_transaction = $storage !== null && $storage->has_active_transaction();
        try {
            self::assert_index_writer_ownership();
            if ($uses_storage_transaction) {
                $storage->advance_epoch_before_capability_retirement();
                $transaction_started = true;
            } else {
                $started = $wpdb->query('START TRANSACTION');
                if ($started === false || (isset($wpdb->last_error) && trim((string) $wpdb->last_error) !== '')) {
                    throw new RuntimeException('Could not start the atomic FTS acknowledgement transaction.');
                }
                $transaction_started = true;
                // Even an all-unchanged batch changes search visibility when
                // its dirty rows disappear. Advance the cursor epoch in this
                // same transaction before publishing that membership change.
                self::index_queue(false)->advance_search_epoch();
            }
            $result = $wpdb->query($statement);
            if ($result === false || (isset($wpdb->last_error) && trim((string) $wpdb->last_error) !== '')) {
                throw new RuntimeException('Could not atomically acknowledge the FTS batch.');
            }
            if ($uses_storage_transaction) {
                // The exact option lease remains owned through COMMIT. This is
                // safe even when a legacy wp_options table is nontransactional.
                $storage->commit();
            } else {
                $committed = $wpdb->query('COMMIT');
                if ($committed === false || (isset($wpdb->last_error) && trim((string) $wpdb->last_error) !== '')) {
                    throw new RuntimeException('Could not commit the atomic FTS acknowledgement transaction.');
                }
            }
            $transaction_started = false;
        } catch (Throwable $error) {
            if ($transaction_started) {
                try {
                    if ($uses_storage_transaction && $storage->has_active_transaction()) {
                        $storage->rollback();
                    } else {
                        $wpdb->query('ROLLBACK');
                    }
                } catch (Throwable) {
                    // Preserve the acknowledgement or ownership failure.
                }
            }
            throw $error;
        }

        $acknowledged = min($claim_count, max(0, (int) $result));

        return [
            'acknowledged' => $acknowledged,
            'superseded' => $claim_count - $acknowledged,
        ];
    }

    private static function supports_atomic_worker_acknowledgement(): bool
    {
        global $wpdb;

        return isset($wpdb)
            && is_object($wpdb)
            && isset($wpdb->options)
            && is_scalar($wpdb->options)
            && method_exists($wpdb, 'prepare')
            && method_exists($wpdb, 'query')
            && function_exists('maybe_serialize')
            && !self::database_adapter_is_sqlite($wpdb);
    }

    private static function database_adapter_is_sqlite(object $wpdb): bool
    {
        $signals = [get_class($wpdb)];
        if (isset($wpdb->dbh) && is_object($wpdb->dbh)) {
            $signals[] = get_class($wpdb->dbh);
        }
        foreach (['SQLITE_MAIN_FILE', 'SQLITE_PLUGIN', 'SQLITE_DB_DROPIN_VERSION', 'DB_ENGINE'] as $constant) {
            if (defined($constant)) {
                $signals[] = (string) constant($constant);
            }
        }

        foreach ($signals as $signal) {
            if (stripos($signal, 'sqlite') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fence every storage mutation and commit with the current lease token.
     *
     * @return array<string,mixed> The currently owned lease payload.
     */
    private static function assert_index_writer_ownership(): array
    {
        $token = self::$active_index_writer_token;
        $prefix = self::$active_index_writer_prefix;
        $lock = self::get_option(self::INDEX_LOCK_OPTION, null);
        if (
            $token === null
            || $prefix === null
            || !hash_equals($prefix, self::current_database_prefix())
            || !is_array($lock)
            || !hash_equals($token, is_scalar($lock['token'] ?? null) ? (string) $lock['token'] : '')
            || !self::lock_payload_active($lock, time())
        ) {
            throw new WP_FTS_Index_Writer_Ownership_Lost('FTS index writer ownership was lost; the stale writer aborted.');
        }

        return $lock;
    }

    /** Reserve the complete measured transaction window without another SQL renewal. */
    private static function index_writer_has_transaction_window(): bool
    {
        $lock = self::assert_index_writer_ownership();

        return (int) ($lock['expires_at'] ?? 0) - time() >= self::MIN_INDEX_TRANSACTION_LEASE_SECONDS;
    }

    private static function current_database_prefix(): string
    {
        global $wpdb;

        return isset($wpdb) && is_object($wpdb) && is_scalar($wpdb->prefix ?? null)
            ? (string) $wpdb->prefix
            : '';
    }

    /**
     * Atomically replace the exact lease payload when the database option table
     * is available. Test and non-WordPress adapters use a single-process
     * checked option update.
     *
     * @param array<string,mixed> $expected
     * @param array<string,mixed> $replacement
     */
    private static function compare_and_swap_index_lock(array $expected, array $replacement): bool
    {
        $database_result = self::compare_and_swap_index_lock_in_database($expected, $replacement, false);
        if ($database_result !== null) {
            return $database_result;
        }

        if (self::get_option(self::INDEX_LOCK_OPTION, null) !== $expected) {
            return false;
        }
        self::set_option(self::INDEX_LOCK_OPTION, $replacement);
        $stored = self::get_option(self::INDEX_LOCK_OPTION, null);

        return is_array($stored) && ($stored['token'] ?? null) === ($replacement['token'] ?? null);
    }

    /**
     * Delete only the exact lease payload observed by the releasing/taking-over
     * writer so an expired owner cannot delete its successor's lock.
     *
     * @param mixed $expected Exact structured or malformed value observed by the contender.
     */
    private static function compare_and_delete_index_lock(mixed $expected): bool
    {
        $database_result = self::compare_and_swap_index_lock_in_database($expected, [], true);
        if ($database_result !== null) {
            return $database_result;
        }

        if (self::get_option(self::INDEX_LOCK_OPTION, null) !== $expected) {
            return false;
        }
        self::delete_option(self::INDEX_LOCK_OPTION);

        return self::get_option(self::INDEX_LOCK_OPTION, null) === null;
    }

    /**
     * @param mixed $expected Exact structured or malformed value observed by the contender.
     * @param array<string,mixed> $replacement
     * @return bool|null Null when the WordPress option table is unavailable.
     */
    private static function compare_and_swap_index_lock_in_database(mixed $expected, array $replacement, bool $delete): ?bool
    {
        global $wpdb;

        if (
            !isset($wpdb)
            || !is_object($wpdb)
            || !isset($wpdb->options)
            || !is_scalar($wpdb->options)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'query')
            || !function_exists('maybe_serialize')
        ) {
            return null;
        }

        $table = (string) $wpdb->options;
        $expected_value = maybe_serialize($expected);
        $statement = $delete
            ? $wpdb->prepare(
                "DELETE FROM {$table} WHERE option_name = %s AND option_value = %s",
                self::INDEX_LOCK_OPTION,
                $expected_value
            )
            : $wpdb->prepare(
                "UPDATE {$table} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                maybe_serialize($replacement),
                self::INDEX_LOCK_OPTION,
                $expected_value
            );
        $result = $wpdb->query($statement);
        if ($result === false) {
            throw new RuntimeException('Could not compare and update the FTS index writer lease.');
        }
        if ((int) $result === 1 && !$delete && function_exists('wp_cache_set')) {
            // The exact compare-and-swap just published this payload. Keep the
            // option cache authoritative so the immediately following lease
            // assertion does not pay a redundant primary-key read.
            wp_cache_set(self::INDEX_LOCK_OPTION, $replacement, 'options');
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete('notoptions', 'options');
            }
        } elseif (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::INDEX_LOCK_OPTION, 'options');
        }

        return (int) $result === 1;
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
    private static function index_profile_state(?array $state = null): array
    {
        $state ??= self::index_health_state();
        $profile = self::current_index_profile();
        $currentHash = self::sanitize_index_profile_hash($profile['hash'] ?? self::index_profile_hash($profile));

        return [
            'index_profile_hash' => $currentHash,
            'accepted_index_profile_hash' => self::sanitize_index_profile_hash($state['accepted_index_profile_hash'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $state */
    private static function profile_reconciliation_pending(array $state): bool
    {
        $current = self::sanitize_index_profile_hash($state['index_profile_hash'] ?? '');
        $accepted = self::sanitize_index_profile_hash($state['accepted_index_profile_hash'] ?? '');

        return $current !== '' && $accepted !== '' && $current !== $accepted;
    }

    private static function mark_initial_index_pending(
        bool $rotate_incarnation = true,
        string $target_profile_hash = ''
    ): string
    {
        // Revoke the only logical search-publication capability before changing
        // the desired incarnation, health diagnostics, queue, or physical
        // schema. A failure below can therefore leave work incomplete, but it
        // cannot leave an older verified generation searchable.
        self::clear_search_ready_incarnation();
        $incarnation = $rotate_incarnation
            ? self::rotate_readiness_incarnation()
            : self::readiness_incarnation();
        if ($incarnation === '') {
            $incarnation = self::rotate_readiness_incarnation();
        }
        $state = self::index_health_state();
        $state['initial_index_status'] = self::INITIAL_INDEX_STATUS_PENDING;
        $state['initial_index_started_at'] = self::current_gmt_datetime();
        $state['initial_index_completed_at'] = '';
        $state['reconciliation_scope_completed_at'] = '';
        $state['reconciliation_scope_completed_incarnation'] = '';
        $state['reconciliation_scope_completed_profile_hash'] = '';
        $target_profile_hash = self::sanitize_index_profile_hash($target_profile_hash);
        if ($target_profile_hash !== '') {
            $state['index_profile_hash'] = $target_profile_hash;
        }

        self::set_option(self::INDEX_HEALTH_OPTION, $state);

        return $incarnation;
    }

    private static function rotate_readiness_incarnation(): string
    {
        $incarnation = bin2hex(random_bytes(16));
        self::set_option(self::READINESS_INCARNATION_OPTION, $incarnation);
        $stored = self::readiness_incarnation();
        if ($stored === '' || !hash_equals($incarnation, $stored)) {
            throw new RuntimeException('Could not persist the FTS readiness incarnation.');
        }

        return $incarnation;
    }

    private static function readiness_incarnation(): string
    {
        return self::sanitize_readiness_incarnation(
            self::get_option(self::READINESS_INCARNATION_OPTION, '')
        );
    }

    private static function search_ready_incarnation(): string
    {
        return self::search_ready_capability()['incarnation'];
    }

    private static function search_ready_profile_hash(): string
    {
        return self::search_ready_capability()['profile_hash'];
    }

    /** @return array{incarnation:string,profile_hash:string} */
    private static function search_ready_capability(): array
    {
        $value = self::get_option(self::SEARCH_READY_INCARNATION_OPTION, '');
        if (!is_array($value)) {
            return ['incarnation' => '', 'profile_hash' => ''];
        }

        return [
            'incarnation' => self::sanitize_readiness_incarnation($value['incarnation'] ?? ''),
            'profile_hash' => self::sanitize_index_profile_hash($value['profile_hash'] ?? ''),
        ];
    }

    private static function clear_search_ready_incarnation(): void
    {
        self::set_option(self::SEARCH_READY_INCARNATION_OPTION, '');
        if (self::search_ready_incarnation() !== '') {
            throw new RuntimeException('Could not revoke the FTS search-ready capability.');
        }
    }

    private static function publish_search_ready_incarnation(string $incarnation, string $profile_hash): bool
    {
        $incarnation = self::sanitize_readiness_incarnation($incarnation);
        $profile_hash = self::sanitize_index_profile_hash($profile_hash);
        if ($incarnation === '' || $profile_hash === '') {
            throw new RuntimeException('Could not publish an invalid FTS search-ready capability.');
        }
        $expected = self::get_option(self::SEARCH_READY_INCARNATION_OPTION, '');
        $replacement = [
            'incarnation' => $incarnation,
            'profile_hash' => $profile_hash,
        ];
        $state = self::index_health_state();
        if (
            !hash_equals($incarnation, self::readiness_incarnation())
            || !hash_equals($profile_hash, self::sanitize_index_profile_hash($state['index_profile_hash'] ?? ''))
            || !hash_equals($profile_hash, self::sanitize_index_profile_hash($state['accepted_index_profile_hash'] ?? ''))
            || !empty($state['foreground_owner_guard_blocked'])
            || !self::compare_and_swap_search_ready_capability($expected, $replacement)
        ) {
            return false;
        }
        $stored = self::search_ready_incarnation();
        $stored_profile = self::search_ready_profile_hash();
        if (
            $stored === ''
            || $stored_profile === ''
            || !hash_equals($incarnation, $stored)
            || !hash_equals($profile_hash, $stored_profile)
        ) {
            throw new RuntimeException('Could not publish the FTS search-ready capability.');
        }
        // A newer configuration may rotate the desired generation immediately
        // after the CAS. Remove only this stale pair; never erase a successor's
        // already-published capability.
        if (
            !hash_equals($incarnation, self::readiness_incarnation())
            || !hash_equals($profile_hash, self::sanitize_index_profile_hash(
                self::index_health_state()['index_profile_hash'] ?? ''
            ))
        ) {
            self::compare_and_swap_search_ready_capability($replacement, '');
            return false;
        }

        return true;
    }

    private static function sanitize_readiness_incarnation(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{32}$/D', $value) === 1 ? $value : '';
    }

    private static function readiness_completion_matches(array $state): bool
    {
        $current = self::readiness_incarnation();
        $completed = is_scalar($state['reconciliation_scope_completed_incarnation'] ?? null)
            ? strtolower(trim((string) $state['reconciliation_scope_completed_incarnation']))
            : '';
        $target_profile = self::sanitize_index_profile_hash($state['index_profile_hash'] ?? '');
        $completed_profile = self::sanitize_index_profile_hash(
            $state['reconciliation_scope_completed_profile_hash'] ?? ''
        );

        return $current !== ''
            && preg_match('/^[a-f0-9]{32}$/D', $completed) === 1
            && hash_equals($current, $completed)
            && $target_profile !== ''
            && $completed_profile !== ''
            && hash_equals($target_profile, $completed_profile);
    }

    /**
     * Promote readiness only inside the dedicated maintenance lease.
     *
     * Readiness is fail-closed: a failed probe leaves the current generation
     * pending so a later successful maintenance batch can retry publication.
     */
    private static function finalize_initial_index_readiness_in_maintenance(): bool
    {
        $expected_state = self::get_option(self::INDEX_HEALTH_OPTION, []);
        $state = self::sanitize_index_health_state($expected_state);
        if (!empty($state['foreground_owner_guard_blocked'])) {
            return false;
        }
        $current_incarnation = self::readiness_incarnation();
        $published_incarnation = self::search_ready_incarnation();
        $published_profile = self::search_ready_profile_hash();
        $completed_profile = self::sanitize_index_profile_hash(
            $state['reconciliation_scope_completed_profile_hash'] ?? ''
        );
        $current_profile = self::current_index_profile_hash();
        if (
            self::sanitize_initial_index_status($state['initial_index_status'] ?? '') === self::INITIAL_INDEX_STATUS_READY
            && self::readiness_completion_matches($state)
            && empty($state['global_visibility_fence_active'])
            && $current_incarnation !== ''
            && $published_incarnation !== ''
            && $published_profile !== ''
            && hash_equals($current_incarnation, $published_incarnation)
            && hash_equals($completed_profile, $published_profile)
            && hash_equals($completed_profile, $current_profile)
        ) {
            return true;
        }
        if (
            self::sanitize_index_timestamp($state['reconciliation_scope_completed_at'] ?? '') === ''
            || !self::readiness_completion_matches($state)
        ) {
            return false;
        }
        if (!self::option_matches_schema_version(self::get_option(self::SCHEMA_VERSION_OPTION, null))) {
            return false;
        }
        $queue = self::index_queue(false);
        if ($queue->has_work()) {
            return false;
        }
        if ($completed_profile === '' || !hash_equals($completed_profile, $current_profile)) {
            $incarnation = self::mark_initial_index_pending(true, $current_profile);
            $queue->enqueue_scope(
                self::GLOBAL_RECONCILIATION_SCOPE_KEY,
                [
                    'reason' => 'index_profile_changed_before_readiness_publication',
                    'profile_hash' => $current_profile,
                ],
                null,
                WP_FTS_Index_Queue::SCOPE_COVERAGE_CORPUS,
                '',
                0,
                $incarnation
            );
            self::schedule_queue_processor(1);
            return false;
        }
        $physical = self::storage(false)->verify_schema();
        if (empty($physical['valid'])) {
            throw new RuntimeException('FTS readiness finalization found an invalid physical schema.');
        }

        self::migration_phase('ready_verified');
        self::cleanup_legacy_relational_tables();
        self::migration_phase('legacy_cleaned');
        $state['initial_index_status'] = self::INITIAL_INDEX_STATUS_READY;
        $state['status'] = 'ready';
        $state['initial_index_started_at'] = self::sanitize_index_timestamp($state['initial_index_started_at'] ?? '') ?: self::current_gmt_datetime();
        $state['initial_index_completed_at'] = self::current_gmt_datetime();
        $state['index_profile_hash'] = $completed_profile;
        $state['accepted_index_profile_hash'] = $completed_profile;
        if (
            !hash_equals($current_incarnation, self::readiness_incarnation())
            || !hash_equals($completed_profile, self::current_index_profile_hash())
            || $queue->has_work()
            || !self::compare_and_swap_index_health($expected_state, $state)
        ) {
            return false;
        }
        // Publication is deliberately the final durable write. Health is
        // diagnostic; only this exact profile/incarnation pair authorizes a
        // search plan to proceed.
        if (!self::publish_search_ready_incarnation($current_incarnation, $completed_profile)) {
            return false;
        }
        self::$search_takeover_status_cache = [];

        return true;
    }

    private static function search_takeover_cache_key(): string
    {
        global $wpdb;

        $prefix = isset($wpdb) && is_object($wpdb) ? (string) ($wpdb->prefix ?? '') : '';
        $site_id = function_exists('get_current_blog_id') ? max(0, (int) get_current_blog_id()) : 0;

        return $site_id . ':' . $prefix;
    }

    private static function sanitize_initial_index_status(mixed $value): string
    {
        $status = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return $status === self::INITIAL_INDEX_STATUS_READY
            ? self::INITIAL_INDEX_STATUS_READY
            : self::INITIAL_INDEX_STATUS_PENDING;
    }

    /**
     * @return array<string,mixed>
     */
    private static function index_health_state(): array
    {
        return self::sanitize_index_health_state(
            self::get_option(self::INDEX_HEALTH_OPTION, [])
        );
    }

    /** @return array<string,mixed> */
    private static function sanitize_index_health_state(mixed $raw): array
    {
        if (!is_array($raw)) {
            return self::default_index_health_state();
        }

        $defaults = self::default_index_health_state();
        $state = array_replace($defaults, array_intersect_key($raw, $defaults));
        $state['status'] = is_scalar($state['status']) ? self::sanitize_key((string) $state['status']) : '';
        $state['schema_upgrade_error'] = self::sanitize_index_failure_text($state['schema_upgrade_error'], self::MAX_INDEX_FAILURE_ERROR_BYTES);
        $state['search_runtime_failure_latched'] = (bool) $state['search_runtime_failure_latched'];
        $state['foreground_owner_guard_blocked'] = (bool) $state['foreground_owner_guard_blocked'];
        $state['global_visibility_fence_active'] = (bool) $state['global_visibility_fence_active'];
        $state['last_batch_processed'] = max(0, (int) $state['last_batch_processed']);
        $state['last_batch_queue_processed'] = max(0, (int) $state['last_batch_queue_processed']);
        $state['last_batch_backfill_processed'] = max(0, (int) $state['last_batch_backfill_processed']);
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
        $state['failure_history'] = self::sanitize_failure_recovery_records($state['failure_history'] ?? []);
        $state['initial_index_status'] = self::sanitize_initial_index_status($state['initial_index_status'] ?? '');
        $state['initial_index_started_at'] = self::sanitize_index_timestamp($state['initial_index_started_at'] ?? '');
        $state['initial_index_completed_at'] = self::sanitize_index_timestamp($state['initial_index_completed_at'] ?? '');
        $state['reconciliation_scope_completed_at'] = self::sanitize_index_timestamp($state['reconciliation_scope_completed_at'] ?? '');
        $state['reconciliation_scope_completed_incarnation'] = self::sanitize_readiness_incarnation(
            $state['reconciliation_scope_completed_incarnation'] ?? ''
        );
        $state['reconciliation_scope_completed_profile_hash'] = self::sanitize_index_profile_hash(
            $state['reconciliation_scope_completed_profile_hash'] ?? ''
        );

        return $state;
    }

    /**
     * @return array<string,mixed>
     */
    private static function default_index_health_state(): array
    {
        return [
            'status' => '',
            'schema_upgrade_error' => '',
            'search_runtime_failure_latched' => false,
            'foreground_owner_guard_blocked' => false,
            'global_visibility_fence_active' => false,
            'last_batch_processed' => 0,
            'last_batch_queue_processed' => 0,
            'last_batch_backfill_processed' => 0,
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
            'failure_history' => [],
            'initial_index_status' => self::INITIAL_INDEX_STATUS_PENDING,
            'initial_index_started_at' => '',
            'initial_index_completed_at' => '',
            'reconciliation_scope_completed_at' => '',
            'reconciliation_scope_completed_incarnation' => '',
            'reconciliation_scope_completed_profile_hash' => '',
        ];
    }

    private static function reset_index_health_state(): void
    {
        $state = self::default_index_health_state();
        $profile = self::current_index_profile();
        $current_profile_hash = self::sanitize_index_profile_hash($profile['hash'] ?? self::index_profile_hash($profile));
        $state['index_profile_hash'] = $current_profile_hash;
        $state['accepted_index_profile_hash'] = $current_profile_hash;
        $state['initial_index_started_at'] = self::current_gmt_datetime();

        self::set_option(self::INDEX_HEALTH_OPTION, $state, false);
    }

    /** Publish health only if no newer readiness/configuration writer replaced it. */
    private static function compare_and_swap_index_health(mixed $expected, array $replacement): bool
    {
        global $wpdb;

        if (
            isset($wpdb)
            && is_object($wpdb)
            && isset($wpdb->options)
            && is_scalar($wpdb->options)
            && method_exists($wpdb, 'prepare')
            && method_exists($wpdb, 'query')
            && function_exists('maybe_serialize')
        ) {
            $table = (string) $wpdb->options;
            $serialized_expected = maybe_serialize($expected);
            $serialized_replacement = maybe_serialize($replacement);
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET option_value = %s, autoload = 'yes' WHERE option_name = %s AND option_value = %s",
                $serialized_replacement,
                self::INDEX_HEALTH_OPTION,
                $serialized_expected
            ));
            if ($result === false) {
                throw new RuntimeException('Could not compare and publish the FTS readiness health state.');
            }
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete(self::INDEX_HEALTH_OPTION, 'options');
                wp_cache_delete('alloptions', 'options');
            }
            self::$search_takeover_status_cache = [];

            if ((int) $result === 1) {
                return true;
            }
            if ($serialized_expected !== $serialized_replacement) {
                return false;
            }

            // MySQL reports zero affected rows when a matching CAS writes the
            // value and autoload state already present. Re-read only that
            // exact no-op case: a concurrent owner-guard latch must still make
            // the CAS fail, while an idempotent health transition succeeds
            // instead of retrying the same UPDATE five times.
            return maybe_serialize(self::get_option(self::INDEX_HEALTH_OPTION, null))
                === $serialized_replacement;
        }

        if (self::get_option(self::INDEX_HEALTH_OPTION, null) !== $expected) {
            return false;
        }
        self::set_option(self::INDEX_HEALTH_OPTION, $replacement, false);

        return self::get_option(self::INDEX_HEALTH_OPTION, null) == $replacement;
    }

    /** Replace only the exact combined profile/incarnation capability observed. */
    private static function compare_and_swap_search_ready_capability(
        mixed $expected,
        mixed $replacement
    ): bool {
        global $wpdb;

        if (
            isset($wpdb)
            && is_object($wpdb)
            && isset($wpdb->options)
            && is_scalar($wpdb->options)
            && method_exists($wpdb, 'prepare')
            && method_exists($wpdb, 'query')
            && function_exists('maybe_serialize')
        ) {
            $table = (string) $wpdb->options;
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET option_value = %s, autoload = 'yes' WHERE option_name = %s AND option_value = %s",
                maybe_serialize($replacement),
                self::SEARCH_READY_INCARNATION_OPTION,
                maybe_serialize($expected)
            ));
            if ($result === false) {
                throw new RuntimeException('Could not compare and publish the FTS search-ready capability.');
            }
            $published = (int) $result === 1;
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete(self::SEARCH_READY_INCARNATION_OPTION, 'options');
                wp_cache_delete('alloptions', 'options');
            }
            if (
                !$published
                && $expected === ''
                && is_array($replacement)
                && $replacement !== []
                && function_exists('add_option')
            ) {
                // UPDATE cannot distinguish an absent row from a lost CAS.
                // add_option is the atomic insert-if-absent primitive backed
                // by wp_options' unique option_name key. A concurrent publisher
                // wins cleanly; this stale publisher never overwrites it.
                $published = add_option(
                    self::SEARCH_READY_INCARNATION_OPTION,
                    $replacement,
                    '',
                    'yes'
                );
            }
            self::$search_takeover_status_cache = [];

            return $published;
        }

        $observed = self::get_option(self::SEARCH_READY_INCARNATION_OPTION, null);
        if (
            $observed === null
            && $expected === ''
            && is_array($replacement)
            && $replacement !== []
            && function_exists('add_option')
        ) {
            return add_option(self::SEARCH_READY_INCARNATION_OPTION, $replacement, '', 'yes');
        }
        if ($observed !== $expected) {
            return false;
        }
        self::set_option(self::SEARCH_READY_INCARNATION_OPTION, $replacement);

        return self::get_option(self::SEARCH_READY_INCARNATION_OPTION, null) == $replacement;
    }

    /** Persist only state transitions that affect readiness or diagnostics. */
    private static function index_batch_requires_health_write(array $summary): bool
    {
        $writer_transaction_error = !empty($summary['_writer_transaction_attempted'])
            && (!empty($summary['error_class']) || !empty($summary['error_message']));

        return (!empty($summary['scope_completed']) && ($summary['scope_reason'] ?? '') !== 'wp_cli_reindex')
            || !empty($summary['global_visibility_fence_completed'])
            || !empty($summary['skipped_locked'])
            || !empty($summary['stopped_by_budget'])
            // Successful queue acknowledgement is authoritative. Clearing an
            // old diagnostic option is not allowed to append a query after a
            // maximum writer transaction; the returned summary still reports
            // the resolution to manual callers.
            || (!empty($summary['resolved_failure_records']) && empty($summary['_writer_transaction_attempted']))
            || max(0, (int) ($summary['last_batch_failures'] ?? 0)) > 0
            // A failed/ambiguous derived transaction leaves exact claims
            // leased for the systemic successor. Do not make an optional
            // diagnostic option write part of that maximum recovery path.
            || (!$writer_transaction_error && (!empty($summary['error_class']) || !empty($summary['error_message'])));
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function update_index_health_state(array $summary): void
    {
        $expected_state = self::get_option(self::INDEX_HEALTH_OPTION, []);
        $state = self::sanitize_index_health_state($expected_state);
        if (!empty($summary['scope_completed_global'])) {
            $completed_incarnation = self::sanitize_readiness_incarnation(
                $summary['scope_completed_incarnation'] ?? ''
            );
            $completed_profile = self::sanitize_index_profile_hash(
                $summary['scope_completed_profile_hash'] ?? ''
            );
            $current_incarnation = self::readiness_incarnation();
            $target_profile = self::sanitize_index_profile_hash(
                $state['index_profile_hash'] ?? ''
            );
            if (
                $completed_incarnation === ''
                || $completed_profile === ''
                || $current_incarnation === ''
                || !hash_equals($current_incarnation, $completed_incarnation)
                || $target_profile === ''
                || !hash_equals($target_profile, $completed_profile)
            ) {
                // A stale corpus worker may finish after a foreground failure
                // has rotated readiness. It must not overwrite the newer
                // incarnation's pending or completed health state.
                return;
            }
        }
        $state['last_batch_processed'] = max(0, (int) ($summary['processed'] ?? 0));
        $state['last_batch_queue_processed'] = max(0, (int) ($summary['queue_processed'] ?? 0));
        $state['last_batch_backfill_processed'] = max(0, (int) ($summary['backfill_processed'] ?? 0));
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
                if (empty($state['search_runtime_failure_latched'])) {
                    $state['last_error'] = '';
                }
            }
        }

        if ((int) ($summary['last_indexed_post_id'] ?? 0) > 0) {
            $state['last_indexed_post_id'] = (int) $summary['last_indexed_post_id'];
            $state['last_indexed_post_title'] = is_scalar($summary['last_indexed_post_title'] ?? null) ? (string) $summary['last_indexed_post_title'] : '';
            $state['last_indexed_at'] = is_scalar($summary['last_indexed_at'] ?? null) ? (string) $summary['last_indexed_at'] : self::current_gmt_datetime();
        }

        $state['latest_batch_diagnostics'] = self::index_batch_diagnostics_from_summary($summary);
        if (!empty($summary['scope_completed_global'])) {
            $completed_incarnation = self::sanitize_readiness_incarnation(
                $summary['scope_completed_incarnation'] ?? ''
            );
            $completed_profile = self::sanitize_index_profile_hash(
                $summary['scope_completed_profile_hash'] ?? ''
            );
            $current_incarnation = self::readiness_incarnation();
            $target_profile = self::sanitize_index_profile_hash($state['index_profile_hash'] ?? '');
            if (
                $completed_incarnation !== ''
                && $completed_profile !== ''
                && $current_incarnation !== ''
                && hash_equals($current_incarnation, $completed_incarnation)
                && $target_profile !== ''
                && hash_equals($target_profile, $completed_profile)
            ) {
                $state['reconciliation_scope_completed_at'] = self::current_gmt_datetime();
                $state['reconciliation_scope_completed_incarnation'] = $completed_incarnation;
                $state['reconciliation_scope_completed_profile_hash'] = $completed_profile;
                $state['global_visibility_fence_active'] = false;
            }
        }
        if (!empty($summary['global_visibility_fence_completed'])) {
            $state['global_visibility_fence_active'] = false;
        }
        self::apply_failure_recovery_summary($state, $summary);

        if (!self::compare_and_swap_index_health($expected_state, $state)) {
            // A newer readiness/configuration transition owns the option. Its
            // state is authoritative; this completed batch must not overwrite
            // it with a stale diagnostic snapshot.
            return;
        }
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
            'attempted' => $summary['attempted'] ?? 0,
            'processed' => $summary['processed'] ?? 0,
            'committed' => $summary['committed'] ?? 0,
            'superseded' => $summary['superseded'] ?? 0,
            'indexed' => $summary['indexed'] ?? 0,
            'analyzed' => $summary['analyzed'] ?? 0,
            'queue_processed' => $summary['queue_processed'] ?? 0,
            'unchanged' => $summary['unchanged'] ?? 0,
            'deleted' => $summary['deleted'] ?? 0,
            'permanently_rejected' => $summary['permanently_rejected'] ?? 0,
            'retryable_failures' => $summary['retryable_failures'] ?? 0,
            'deferred' => $summary['deferred'] ?? 0,
            'empty_terms_cleaned' => $summary['empty_terms_cleaned'] ?? 0,
            'cleanup_pending' => $summary['cleanup_pending'] ?? false,
            'backfill_processed' => $summary['backfill_processed'] ?? 0,
            'queue_before' => $summary['queue_before'] ?? 0,
            'queue_after' => $summary['queue_after'] ?? 0,
            'backfill_scanned' => $summary['backfill_scanned'] ?? 0,
            'backfill_queued' => $summary['backfill_queued'] ?? 0,
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
            'successor_schedule_failed' => $summary['successor_schedule_failed'] ?? false,
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
            'attempted' => max(0, (int) ($raw['attempted'] ?? 0)),
            'processed' => max(0, (int) ($raw['processed'] ?? 0)),
            'committed' => max(0, (int) ($raw['committed'] ?? 0)),
            'superseded' => max(0, (int) ($raw['superseded'] ?? 0)),
            'indexed' => max(0, (int) ($raw['indexed'] ?? 0)),
            'analyzed' => max(0, (int) ($raw['analyzed'] ?? 0)),
            'queue_processed' => max(0, (int) ($raw['queue_processed'] ?? 0)),
            'unchanged' => max(0, (int) ($raw['unchanged'] ?? 0)),
            'deleted' => max(0, (int) ($raw['deleted'] ?? 0)),
            'permanently_rejected' => max(0, (int) ($raw['permanently_rejected'] ?? 0)),
            'retryable_failures' => max(0, (int) ($raw['retryable_failures'] ?? 0)),
            'deferred' => max(0, (int) ($raw['deferred'] ?? 0)),
            'empty_terms_cleaned' => max(0, (int) ($raw['empty_terms_cleaned'] ?? 0)),
            'cleanup_pending' => (bool) ($raw['cleanup_pending'] ?? false),
            'backfill_processed' => max(0, (int) ($raw['backfill_processed'] ?? 0)),
            'queue_before' => max(0, (int) ($raw['queue_before'] ?? 0)),
            'queue_after' => max(0, (int) ($raw['queue_after'] ?? 0)),
            'backfill_scanned' => max(0, (int) ($raw['backfill_scanned'] ?? 0)),
            'backfill_queued' => max(0, (int) ($raw['backfill_queued'] ?? 0)),
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
            'successor_schedule_failed' => (bool) ($raw['successor_schedule_failed'] ?? false),
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

    private static function clamp_float(float $value, float $min, float $max): float
    {
        return min($max, max($min, $value));
    }

    private static function current_gmt_datetime(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Build the durable queue against the active WordPress database connection.
     */
    private static function index_queue(bool $ensure_schema = false): WP_FTS_Index_Queue
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            throw new RuntimeException('Pure PHP FTS requires the WordPress $wpdb global.');
        }

        if ($ensure_schema) {
            self::maybe_upgrade_schema();
        }

        return new WP_FTS_Index_Queue($wpdb);
    }

    /** @param array<string,mixed> $payload */
    private static function enqueue_corpus_scope(
        WP_FTS_Index_Queue $queue,
        array $payload,
        ?int $now = null
    ): void {
        $target_profile_hash = self::sanitize_index_profile_hash($payload['profile_hash'] ?? '');
        if ($target_profile_hash === '') {
            $target_profile_hash = self::current_index_profile_hash();
        }
        $payload['profile_hash'] = $target_profile_hash;
        $incarnation = self::readiness_incarnation();
        if ($incarnation === '') {
            $incarnation = self::mark_initial_index_pending(true, $target_profile_hash);
        } else {
            self::mark_initial_index_pending(false, $target_profile_hash);
        }
        $queue->coalesce_corpus_successor(
            $payload,
            $now,
            $incarnation
        );
    }

    /**
     * Replace the unbounded legacy option queue with one corpus reconciliation.
     *
     * Never read the option value: WordPress would deserialize its historically
     * unbounded array before this method could impose a limit. One indexed
     * existence probe followed by one deterministic corpus scope covers every
     * legacy dirty ID and coalesces with the schema-wide reconciliation.
     */
    private static function migrate_legacy_queue_option(WP_FTS_Index_Queue $queue): void
    {
        if (!self::legacy_queue_option_exists()) {
            return;
        }

        self::enqueue_corpus_scope($queue, [
            'reason' => 'legacy_option_queue_migration',
        ]);
        self::delete_option(self::QUEUE_OPTION);
    }

    /** Probe the legacy option's primary-key row without loading its value. */
    private static function legacy_queue_option_exists(): bool
    {
        global $wpdb;

        if (
            !isset($wpdb)
            || !is_object($wpdb)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'get_var')
        ) {
            // A schema upgrade without a native database adapter must stay
            // fail-closed. Enqueuing one coalesced corpus scope is safe; reading
            // the unbounded option through get_option() is not.
            return true;
        }

        $table = isset($wpdb->options) && is_scalar($wpdb->options)
            ? (string) $wpdb->options
            : (string) ($wpdb->prefix ?? '') . 'options';
        if ($table === '' || preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            return true;
        }

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE option_name = %s LIMIT 1",
            self::QUEUE_OPTION
        ));
        if (isset($wpdb->last_error) && trim((string) $wpdb->last_error) !== '') {
            throw new RuntimeException('Could not inspect the retired FTS option queue.');
        }

        return $exists !== null;
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

        $mutation_guard = static function (): void {
            // Mutation count must not depend on wall-clock runtime. The exact
            // claim and cursor epoch retire together inside the publication
            // transaction, while the outer worker retains its serialized lease
            // through COMMIT and successor scheduling. If that reserved lease
            // expires first, abort instead of renewing from statement loops.
            self::assert_index_writer_ownership();
        };

        return new WP_FTS_Storage_Mysql($wpdb, null, $mutation_guard);
    }

    /**
     * Ignore revisions/autosaves and invalid ids.
     */
    private static function is_normal_post_id(int $post_id, ?object $post = null): bool
    {
        if ($post_id <= 0) {
            return false;
        }

        if ($post !== null && isset($post->post_type) && (string) $post->post_type === 'revision') {
            return false;
        }

        if (self::$foreground_bulk_mutation_scope !== null) {
            // Its durable global fence already makes every old projection
            // ineligible. Conservatively retaining a positive id is cheaper
            // than one cold-cache revision lookup per hook fan-out.
            return true;
        }

        if ($post !== null) {
            return true;
        }

        if (function_exists('wp_is_post_revision') && wp_is_post_revision($post_id)) {
            return false;
        }

        if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($post_id)) {
            return false;
        }

        return true;
    }

    /** Whether one request-global fence already covers every canonical row. */
    private static function foreground_corpus_fence_active(): bool
    {
        return self::$foreground_bulk_mutation_scope !== null
            && !empty(self::$foreground_bulk_mutation_scope['requires_corpus']);
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

        $type = self::post_type_from_object($post);
        if (!self::is_configured_index_post_type($type)) {
            return false;
        }

        $status = self::post_status_from_object($post);
        if (!in_array($status, self::ADMIN_POST_SEARCH_POST_STATUSES, true)) {
            return false;
        }

        return true;
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

    /**
     * Schedule one bounded background queue run when WP-Cron is available.
     * Foreground and worker callers already hold the shared owner guard or the
     * writer lease that excludes uninstall, so the hot path needs no separate
     * option-table probe.
     */
    private static function schedule_queue_processor(
        int $delay_seconds = 60,
        bool $uninstall_excluded = false
    ): bool {
        if (!function_exists('wp_schedule_single_event')) {
            return false;
        }

        $timestamp = time() + max(1, $delay_seconds);
        if (function_exists('wp_next_scheduled')) {
            $scheduled = wp_next_scheduled(self::CRON_HOOK);
            if ($scheduled !== false) {
                if ((int) $scheduled <= $timestamp) {
                    return true;
                }
                if (!$uninstall_excluded && self::uninstall_fence_active()) {
                    return false;
                }
                // Replace the later singleton in one cron-option write. Core's
                // clear-then-schedule API performs two writes (and a third to
                // restore on failure), which made scheduling complexity add to
                // an otherwise fixed maximum worker transaction.
                return self::replace_queue_processor_cron_event($timestamp);
            } else {
                if (!$uninstall_excluded && self::uninstall_fence_active()) {
                    return false;
                }
            }
        } elseif (!$uninstall_excluded && self::uninstall_fence_active()) {
            return false;
        }

        return wp_schedule_single_event($timestamp, self::CRON_HOOK) === true;
    }

    /** Move this plugin's singleton event with one WordPress cron-option write. */
    private static function replace_queue_processor_cron_event(int $timestamp): bool
    {
        if (!function_exists('_get_cron_array') || !function_exists('_set_cron_array')) {
            // Older/custom cron implementations may not expose Core's array
            // helpers. Retaining a valid later watchdog is safer and cheaper
            // than a non-atomic clear-then-add fallback.
            return true;
        }
        $event = (object) [
            'hook' => self::CRON_HOOK,
            'timestamp' => $timestamp,
            'schedule' => false,
            'args' => [],
        ];
        if (function_exists('apply_filters')) {
            $pre = apply_filters('pre_schedule_event', null, $event, false);
            if ($pre !== null) {
                // An external cron provider owns the result. It may have
                // persisted the event elsewhere, so retain Core's existing
                // watchdog rather than editing the local cron option.
                return $pre !== false
                    && !(function_exists('is_wp_error') && is_wp_error($pre));
            }
            $event = apply_filters('schedule_event', $event);
        }
        if (
            !is_object($event)
            || !isset($event->timestamp, $event->hook, $event->args)
            || !property_exists($event, 'schedule')
            || !is_int($event->timestamp)
            || $event->timestamp < 1
            || !is_string($event->hook)
            || $event->hook === ''
            || strlen($event->hook) > 1024
            || $event->schedule !== false
            || !is_array($event->args)
        ) {
            return false;
        }
        try {
            $filteredArgs = serialize($event->args);
        } catch (Throwable) {
            return false;
        }
        if (strlen($filteredArgs) > 1048576) {
            return false;
        }

        $crons = _get_cron_array();
        if (!is_array($crons)) {
            return false;
        }
        $originalKey = md5(serialize([]));
        foreach ($crons as $scheduled_at => &$hooks) {
            if (!is_array($hooks) || !is_array($hooks[self::CRON_HOOK] ?? null)) {
                continue;
            }
            unset($hooks[self::CRON_HOOK][$originalKey]);
            if ($hooks[self::CRON_HOOK] === []) {
                unset($hooks[self::CRON_HOOK]);
            }
            if ($hooks === []) {
                unset($crons[$scheduled_at]);
            }
        }
        unset($hooks);

        $key = md5($filteredArgs);
        $crons[$event->timestamp][$event->hook][$key] = [
            'schedule' => false,
            'args' => $event->args,
        ];
        uksort($crons, 'strnatcasecmp');
        $result = _set_cron_array($crons, true);

        return $result === true;
    }

    /** Keep future retries/expired request guards from losing their watchdog. */
    private static function schedule_next_available_queue_processor(bool $writer_lease_owned = false): bool
    {
        try {
            $queue = self::index_queue(false);
            $next = $queue->next_available_at();
            if ($queue->foreground_owner_guard_probe_state() === 'unavailable') {
                self::latch_foreground_owner_guard_unavailable();
            }
        } catch (Throwable $error) {
            self::remember_foreground_queue_failure($error);
            return false;
        }
        if ($next === PHP_INT_MAX) {
            // No current writer emits this value. Refuse a malformed legacy
            // deadline instead of overflowing WordPress's cron timestamp.
            return false;
        }
        if ($next === null) {
            // An empty queue needs no event; absence is a successful handoff.
            return true;
        }

        return self::schedule_queue_processor(max(1, $next - time()), $writer_lease_owned);
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

    private static function clear_scheduled_schema_provisioning(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::SCHEMA_UPGRADE_CRON_HOOK);
            wp_clear_scheduled_hook(self::SCHEMA_SITE_CRON_HOOK);
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
    private static function set_option(
        string $name,
        mixed $value,
        bool $preserve_owner_guard_latch = true
    ): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        if (
            $name === self::INDEX_HEALTH_OPTION
            && $preserve_owner_guard_latch
            && is_array($value)
        ) {
            // This bit represents operator-only work whose owner can never be
            // inferred from a later-free file. Serialize every health write
            // against the latest option value so a stale diagnostics writer
            // cannot erase it after the foreground failure was persisted.
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $expected = self::get_option($name, null);
                $replacement = $value;
                $current = self::sanitize_index_health_state($expected);
                if (!empty($current['foreground_owner_guard_blocked'])) {
                    $replacement['foreground_owner_guard_blocked'] = true;
                    $replacement['search_runtime_failure_latched'] = true;
                    $replacement['status'] = 'unhealthy';
                }
                if ($expected === null) {
                    self::set_option($name, $replacement, false);
                    if (self::get_option($name, null) == $replacement) {
                        return;
                    }
                    continue;
                }
                if (self::compare_and_swap_index_health($expected, $replacement)) {
                    return;
                }
            }
            throw new RuntimeException("Could not update {$name} without losing the owner-guard latch.");
        }

        // Search readiness consumes these bounded values on every normal
        // request. Keeping them in alloptions avoids cold primary-key reads on
        // hosts without a persistent object cache.
        $request_readiness_option = in_array($name, [
            self::SCHEMA_VERSION_OPTION,
            self::INDEX_HEALTH_OPTION,
            self::READINESS_INCARNATION_OPTION,
            self::SEARCH_READY_INCARNATION_OPTION,
            self::SETTINGS_OPTION,
            self::ANALYZER_OPTIONS_OPTION,
            WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION,
        ], true);
        $updated = $request_readiness_option
            ? update_option($name, $value, true)
            : update_option($name, $value);
        if (!$updated && self::get_option($name, null) != $value) {
            throw new RuntimeException("Could not update {$name}.");
        }

        if ($name === self::ANALYZER_OPTIONS_OPTION) {
            self::reset_request_caches();
        } elseif (in_array($name, [
            self::INDEX_HEALTH_OPTION,
            self::SCHEMA_VERSION_OPTION,
            self::READINESS_INCARNATION_OPTION,
            self::SEARCH_READY_INCARNATION_OPTION,
        ], true)) {
            self::$search_takeover_status_cache = [];
        }
    }

    /** Persist maintenance-only state without adding another hot option read. */
    private static function set_nonautoloaded_option(string $name, mixed $value): void
    {
        if (!function_exists('update_option')) {
            throw new RuntimeException("WordPress cannot persist {$name}.");
        }
        $updated = update_option($name, $value, false);
        if (!$updated && self::get_option($name, null) != $value) {
            throw new RuntimeException("Could not update {$name}.");
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
        $allowed_map = [];
        $allowed_count = 0;
        foreach ($allowed as $allowed_item) {
            if (++$allowed_count > self::MAX_SEARCH_SCOPE_VALUES) {
                break;
            }
            if (!is_scalar($allowed_item) || strlen((string) $allowed_item) > self::MAX_SEARCH_SCOPE_VALUE_BYTES) {
                continue;
            }
            $allowed_item = self::sanitize_key((string) $allowed_item);
            if ($allowed_item !== '') {
                $allowed_map[$allowed_item] = true;
            }
            if (count($allowed_map) >= self::MAX_SEARCH_SCOPE_VALUES) {
                break;
            }
        }
        $selected = [];
        $raw_count = 0;
        foreach (is_array($value) ? $value : [$value] as $item) {
            if (++$raw_count > self::MAX_SEARCH_SCOPE_VALUES) {
                break;
            }
            if (!is_scalar($item) || strlen((string) $item) > self::MAX_SEARCH_SCOPE_VALUE_BYTES) {
                continue;
            }
            $item = self::sanitize_key(self::unslash_scalar($item));
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
        $max = max(1, $max);
        if (is_array($value)) {
            // Count before unslashing or inspecting any element. An attacker
            // must not turn a 50-row details request into a recursive walk over
            // an arbitrarily large PHP input tree.
            if (count($value) > $max) {
                return [];
            }
            $raw_items = [];
            foreach ($value as $item) {
                if (!is_scalar($item) || strlen((string) $item) > self::ADMIN_DETAILS_ID_MAX_BYTES) {
                    return [];
                }
                $raw_items[] = function_exists('wp_unslash') ? wp_unslash((string) $item) : (string) $item;
            }
        } else {
            if (!is_scalar($value) || strlen((string) $value) > self::ADMIN_DETAILS_ID_LIST_MAX_BYTES) {
                return [];
            }
            $value = function_exists('wp_unslash') ? wp_unslash((string) $value) : (string) $value;
            $raw_items = preg_split('/[,\s]+/', $value);
        }

        $items = [];
        $max_integer = (string) PHP_INT_MAX;
        foreach (is_array($raw_items) ? $raw_items : [] as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = trim((string) $item);
            if (strlen($item) > self::ADMIN_DETAILS_ID_MAX_BYTES) {
                return [];
            }
            if (preg_match('/^[1-9][0-9]*$/', $item) !== 1) {
                continue;
            }
            if (
                strlen($item) > strlen($max_integer)
                || (strlen($item) === strlen($max_integer) && strcmp($item, $max_integer) > 0)
            ) {
                return [];
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

    private static function bounded_unslash_scalar(mixed $value, int $maxLength): string
    {
        $bounded = self::truncate_request_text((string) $value, $maxLength);

        return self::truncate_request_text(self::unslash_scalar($bounded), $maxLength);
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
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException("REST search {$key} must be a scalar value.");
            }
            if (is_scalar($value)) {
                $rawQuery = self::bounded_unslash_scalar($value, 200);
                $query = self::truncate_request_text(self::sanitize_text($rawQuery), 200);
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

        $mode = strtoupper(trim(self::bounded_unslash_scalar($mode, 8)));
        return in_array($mode, ['OR', 'AND'], true) ? $mode : null;
    }

    /**
     * Bound REST language input before analyzer canonicalization.
     */
    private static function rest_language(mixed $request): ?string
    {
        $language = self::request_param($request, 'lang', null);
        if ($language === null) {
            return null;
        }
        if (!is_scalar($language)) {
            throw new InvalidArgumentException('REST search lang must be a scalar value.');
        }

        $language = self::truncate_request_text(self::sanitize_text(
            self::bounded_unslash_scalar($language, 40)
        ), 40);

        return $language !== '' ? $language : null;
    }

    private static function rest_limit(mixed $request): int
    {
        $limit = self::request_param($request, 'limit', 10);
        if (!is_scalar($limit)) {
            throw new InvalidArgumentException('REST search limit must be a bounded numeric scalar.');
        }
        if (is_string($limit) && strlen($limit) > self::MAX_SEARCH_NUMERIC_BYTES) {
            throw new InvalidArgumentException('REST search limit must be a bounded numeric scalar.');
        }
        $limit = self::bounded_unslash_scalar($limit, self::MAX_SEARCH_NUMERIC_BYTES);
        if (!is_numeric($limit)) {
            throw new InvalidArgumentException('REST search limit must be numeric.');
        }

        return self::clamp_int($limit, 1, self::MAX_SEARCH_LIMIT);
    }

    private static function rest_cursor(mixed $request): ?string
    {
        $value = self::request_param($request, 'cursor', null);
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('REST search cursor must be a string.');
        }
        if (strlen($value) > self::MAX_SEARCH_CURSOR_BYTES) {
            throw new InvalidArgumentException('REST search cursor may contain at most 2,048 bytes.');
        }
        $cursor = trim(self::bounded_unslash_scalar($value, self::MAX_SEARCH_CURSOR_BYTES));

        return $cursor !== '' ? $cursor : null;
    }

    private static function rest_cursor_direction(mixed $request): string
    {
        $value = self::request_param($request, 'direction', 'after');
        if (!is_string($value) || strlen($value) > self::MAX_SEARCH_MODE_BYTES) {
            throw new InvalidArgumentException('REST search direction must be after or before.');
        }
        $direction = strtolower(trim(self::bounded_unslash_scalar($value, self::MAX_SEARCH_MODE_BYTES)));
        if (!in_array($direction, ['after', 'before'], true)) {
            throw new InvalidArgumentException('REST search direction must be after or before.');
        }

        return $direction;
    }

    private static function rest_explain_requested(mixed $request): bool
    {
        $value = self::request_param($request, 'explain', false);
        if (!is_scalar($value) || (is_string($value) && strlen($value) > self::MAX_SEARCH_SWITCH_BYTES)) {
            throw new InvalidArgumentException('REST search explain must be a bounded scalar value.');
        }
        $value = self::bounded_unslash_scalar($value, self::MAX_SEARCH_SWITCH_BYTES);

        return self::truthy_admin_value($value);
    }

    /**
     * Build a WordPress-style REST error with a test-friendly fallback shape.
     *
     * @param array<string,mixed> $data
     */
    private static function rest_error(string $code, string $message, int $status, array $data = []): object|array
    {
        $data = ['status' => $status] + $data;
        if (class_exists('WP_Error')) {
            return new WP_Error($code, $message, $data);
        }

        return [
            'code' => $code,
            'message' => $message,
            'data' => $data,
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

}
