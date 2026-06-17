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
    public const REST_NAMESPACE = 'wp-fts/v1';
    public const REST_SEARCH_ROUTE = '/search';
    public const ADMIN_PAGE_SLUG = 'wp-fts-settings';
    public const ADMIN_CAPABILITY = 'manage_options';
    public const SETTINGS_OPTION = 'wp_fts_settings';
    public const SANDBOX_DEMO_POSTS_OPTION = 'wp_fts_sandbox_demo_post_ids';
    public const ANALYZER_OPTIONS_OPTION = 'wp_fts_analyzer_options';
    public const ANALYZER_OPTIONS_FILTER = 'wp_fts_analyzer_options';
    public const FRONTEND_SEARCH_REPLACEMENT_FILTER = 'wp_fts_replace_frontend_search';
    public const ADMIN_POST_SEARCH_REPLACEMENT_FILTER = 'wp_fts_replace_admin_post_search';
    public const LANGUAGE_META_KEY = '_wp_fts_index_language';
    public const DEFAULT_BATCH_SIZE = 25;
    public const MAX_SEARCH_LIMIT = 50;
    private const ADMIN_NONCE_ACTION = 'wp_fts_sandbox_admin_action';
    private const ADMIN_NONCE_FIELD = 'wp_fts_sandbox_nonce';
    private const ADMIN_ACTION_FIELD = 'wp_fts_sandbox_action';
    private const ADMIN_QUERY_FIELD = 'wp_fts_sandbox_query';
    private const ADMIN_LANG_FIELD = 'wp_fts_sandbox_lang';
    private const ADMIN_SEARCH_FIELD = 'wp_fts_sandbox_search';
    private const ADMIN_POSTS_PAGE_FIELD = 'wp_fts_sandbox_posts_page';
    private const ADMIN_TAB_FIELD = 'tab';
    private const ADMIN_SETTINGS_TAB = 'settings';
    private const ADMIN_SANDBOX_TAB = 'sandbox';
    private const ADMIN_INDEXED_TAB = 'indexed-content';
    private const ADMIN_ANALYZER_TAB = 'analyzer-packs';
    private const ADMIN_MODE_FIELD = 'wp_fts_sandbox_mode';
    private const ADMIN_LIMIT_FIELD = 'wp_fts_sandbox_limit';
    private const ADMIN_SNIPPET_LENGTH_FIELD = 'wp_fts_sandbox_snippet_length';
    private const ADMIN_HIGHLIGHT_FIELD = 'wp_fts_sandbox_highlight';
    private const ADMIN_LANGUAGE_FALLBACK_FIELD = 'wp_fts_sandbox_language_fallback';
    private const ADMIN_POST_TYPE_FIELD = 'wp_fts_sandbox_post_type';
    private const ADMIN_POST_STATUS_FIELD = 'wp_fts_sandbox_post_status';
    private const ADMIN_DATE_AFTER_FIELD = 'wp_fts_sandbox_date_after';
    private const ADMIN_DATE_BEFORE_FIELD = 'wp_fts_sandbox_date_before';
    private const SETTINGS_GROUP = 'wp_fts_settings';
    private const POST_LANGUAGE_FIELD = 'wp_fts_post_language';
    private const POST_LANGUAGE_NONCE_ACTION = 'wp_fts_post_language';
    private const POST_LANGUAGE_NONCE_FIELD = 'wp_fts_post_language_nonce';
    private const SANDBOX_INDEXED_TERMS_LIMIT = 24;
    private const SANDBOX_INDEXED_POSTS_PER_PAGE = 10;
    private const SETTINGS_SNIPPET_MIN = 40;
    private const SETTINGS_SNIPPET_MAX = 500;
    private const DEFAULT_SETTINGS = [
        'index_post_types' => ['post', 'page'],
        'auto_index' => true,
        'replace_frontend_search' => true,
        'replace_admin_post_search' => true,
        'highlight' => true,
        'snippet_length' => 180,
        'match_mode' => 'OR',
        'result_limit' => 10,
        'language_fallback' => true,
    ];
    private const VISIBILITY_REFILL_MIN_BATCH = 10;
    private const VISIBILITY_REFILL_MULTIPLIER = 4;
    private const VISIBILITY_REFILL_MAX_SCAN = 250;
    private const FRONTEND_SNIPPET_LENGTH = 180;
    private const FRONTEND_SEARCH_POST_STATUSES = ['publish'];
    private const ADMIN_POST_SEARCH_POST_STATUSES = ['publish', 'draft', 'pending', 'future', 'private'];

    /**
     * @var array<int,array{total:int,max_pages:int,query_lang:string,snippets:array<int,string>,titles:array<int,string>}>
     */
    private static array $front_end_search_query_state = [];

    /**
     * @var array<int,array{total:int,max_pages:int,query_lang:string}>
     */
    private static array $admin_post_search_query_state = [];

    /**
     * @var int[]
     */
    private static array $front_end_search_loop_stack = [];

    private static int $front_end_search_active_query_key = 0;

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
        add_action(self::CRON_HOOK, [self::class, 'process_queue'], 10, 0);
        add_action('rest_api_init', [self::class, 'register_rest_routes'], 10, 0);
        add_action('admin_menu', [self::class, 'register_admin_menu'], 10, 0);
        add_action('admin_init', [self::class, 'register_settings'], 10, 0);
        add_action('add_meta_boxes', [self::class, 'register_language_meta_box'], 10, 0);
        add_action('save_post', [self::class, 'save_post_language_override'], 5, 3);
        add_action('pre_get_posts', [self::class, 'prepare_frontend_search_query'], 10, 1);
        add_action('pre_get_posts', [self::class, 'prepare_admin_post_search_query'], 10, 1);

        if (function_exists('add_filter')) {
            add_filter('posts_pre_query', [self::class, 'replace_frontend_search_posts'], 10, 2);
            add_filter('posts_pre_query', [self::class, 'replace_admin_post_search_posts'], 10, 2);
            add_filter('found_posts', [self::class, 'filter_frontend_search_found_posts'], 10, 2);
            add_filter('found_posts', [self::class, 'filter_admin_post_search_found_posts'], 10, 2);
            add_filter('get_the_excerpt', [self::class, 'frontend_search_excerpt'], 10, 2);
            add_filter('the_excerpt', [self::class, 'frontend_search_excerpt'], 10, 1);
            add_filter('the_content', [self::class, 'frontend_search_content'], 20, 1);
            add_filter('the_title', [self::class, 'frontend_search_title'], 10, 2);
        }

        add_action('loop_start', [self::class, 'begin_frontend_search_loop'], 10, 1);
        add_action('loop_end', [self::class, 'end_frontend_search_loop'], 10, 1);
    }

    /**
     * Activation creates or repairs tables and records the schema contract version.
     */
    public static function activate(): void
    {
        self::upgrade_schema();
        self::schedule_queue_processor();
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

        if ($post !== null && self::index_sandbox_demo_post_if_known($post_id, $post)) {
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

            if (self::index_sandbox_demo_post_if_known($post_id, $post)) {
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
        self::render_admin_orientation();
        self::render_admin_tabs($tab);
        self::render_site_language_status_notice();

        if ($tab === self::ADMIN_SANDBOX_TAB) {
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

        return array_key_exists($tab, self::admin_tabs()) ? $tab : self::ADMIN_SETTINGS_TAB;
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

    private static function render_admin_orientation(): void
    {
        echo '<div class="notice notice-info">';
        echo '<p><strong>What this does:</strong> Full-text search (FTS) builds its own searchable index for your content. When analyzer packs are available, searches can understand language-specific word forms instead of matching only the exact words typed. You can also choose whether this index replaces WordPress search on the public site and in the admin Posts screen.</p>';
        echo '</div>';
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

        self::render_settings_section_heading('Customer-facing search behavior', 'These defaults shape public site search result output and seed the Sandbox controls. The wp-admin Posts search box can use the index to find posts, but it keeps the admin list-table display and pagination controls.');
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
        echo '<p class="description">' . self::esc_html($support['label'] . ': ' . $support['reason']) . '</p>';
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
            $state['search_submitted']
        );

        if ($state['search_submitted']) {
            self::render_sandbox_results($state['results']);
        }
    }

    private static function render_indexed_content_tab(): void
    {
        $messages = [];
        try {
            $indexed_posts = self::sandbox_indexed_posts_page(self::sandbox_indexed_posts_page_number());
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
            self::sandbox_search_submitted()
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
     *   results:array{requested_lang:string,query_lang:string,total:int,results:array<int,array{post_id:int,title:string,score:float,language:string,snippet:string}>}
     * }
     */
    private static function admin_sandbox_state(bool $include_indexed_posts): array
    {
        $messages = [];
        $demo_post_ids = self::sandbox_demo_post_ids();
        $post_action_submitted = self::sandbox_post_action_submitted();
        $action = self::sandbox_post_action();

        if ($action !== '') {
            if (!self::verify_sandbox_nonce()) {
                $messages[] = ['error', 'The sandbox action could not be verified. Reload the page and try again.'];
            } elseif ($action === 'refresh_demo') {
                try {
                    $demo_post_ids = self::create_or_refresh_sandbox_demo_posts();
                    $messages[] = ['success', sprintf('Demo posts are ready: %s.', implode(', ', $demo_post_ids))];
                } catch (Throwable $e) {
                    $messages[] = ['error', 'Could not refresh demo posts: ' . $e->getMessage()];
                }
            } elseif ($action === 'index_demo') {
                try {
                    $indexed = self::index_sandbox_demo_posts();
                    $demo_post_ids = $indexed['post_ids'];
                    $messages[] = [
                        'success',
                        sprintf('Processed %d demo post(s) into the full-text index.', $indexed['processed']),
                    ];
                } catch (Throwable $e) {
                    $messages[] = ['error', 'Could not build the demo index: ' . $e->getMessage()];
                }
            }
        }
        if ($action === '' && !$post_action_submitted) {
            try {
                $auto_seeded = self::maybe_auto_seed_sandbox_demo($demo_post_ids);
                $demo_post_ids = $auto_seeded['post_ids'];
                if ($auto_seeded['created'] || $auto_seeded['indexed']) {
                    $messages[] = ['success', self::sandbox_auto_seed_message($auto_seeded)];
                }
            } catch (Throwable $e) {
                $messages[] = ['error', 'Could not prepare the demo sandbox automatically: ' . $e->getMessage()];
            }
        }

        $search_submitted = self::sandbox_search_submitted();
        $query = self::sandbox_search_query();
        $selected_language = self::sandbox_selected_language();
        $controls = self::sandbox_search_controls($search_submitted);

        $results = self::empty_sandbox_search_results($selected_language);
        if ($search_submitted) {
            if ($query === '') {
                $messages[] = ['error', 'Enter a search query before running the sandbox search.'];
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
            'results' => $results,
        ];
    }

    /**
     * @return array{
     *   mode:string,
     *   limit:int,
     *   snippet_length:int,
     *   highlight:bool,
     *   language_fallback:bool,
     *   post_types:string[],
     *   post_statuses:string[],
     *   date_after:string,
     *   date_before:string
     * }
     */
    private static function sandbox_search_controls(bool $search_submitted): array
    {
        $settings = self::settings();
        $mode = strtoupper(self::request_text_value($_GET, self::ADMIN_MODE_FIELD, 10));
        if (!in_array($mode, ['OR', 'AND'], true)) {
            $mode = $settings['match_mode'];
        }

        return [
            'mode' => $mode,
            'limit' => self::clamp_int(self::request_text_value($_GET, self::ADMIN_LIMIT_FIELD, 8) ?: $settings['result_limit'], 1, self::MAX_SEARCH_LIMIT),
            'snippet_length' => self::clamp_int(self::request_text_value($_GET, self::ADMIN_SNIPPET_LENGTH_FIELD, 8) ?: $settings['snippet_length'], self::SETTINGS_SNIPPET_MIN, self::SETTINGS_SNIPPET_MAX),
            'highlight' => self::request_bool_value($_GET, self::ADMIN_HIGHLIGHT_FIELD, $settings['highlight'], $search_submitted),
            'language_fallback' => self::request_bool_value($_GET, self::ADMIN_LANGUAGE_FALLBACK_FIELD, $settings['language_fallback'], $search_submitted),
            'post_types' => self::request_list_value($_GET, self::ADMIN_POST_TYPE_FIELD, self::settings_post_type_choices(), $settings['index_post_types']),
            'post_statuses' => self::request_list_value($_GET, self::ADMIN_POST_STATUS_FIELD, self::sandbox_post_status_choices(), self::sandbox_post_status_choices()),
            'date_after' => self::sanitize_date_filter(self::request_text_value($_GET, self::ADMIN_DATE_AFTER_FIELD, 20)),
            'date_before' => self::sanitize_date_filter(self::request_text_value($_GET, self::ADMIN_DATE_BEFORE_FIELD, 20)),
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

        return [
            'index_post_types' => $post_types,
            'auto_index' => array_key_exists('auto_index', $value) ? self::truthy_admin_value($value['auto_index']) : $defaults['auto_index'],
            'replace_frontend_search' => $replace_frontend_search,
            'replace_admin_post_search' => $replace_admin_post_search,
            'highlight' => array_key_exists('highlight', $value) ? self::truthy_admin_value($value['highlight']) : $defaults['highlight'],
            'snippet_length' => self::clamp_int($value['snippet_length'] ?? $defaults['snippet_length'], self::SETTINGS_SNIPPET_MIN, self::SETTINGS_SNIPPET_MAX),
            'match_mode' => $mode,
            'result_limit' => self::clamp_int($value['result_limit'] ?? $defaults['result_limit'], 1, self::MAX_SEARCH_LIMIT),
            'language_fallback' => array_key_exists('language_fallback', $value) ? self::truthy_admin_value($value['language_fallback']) : $defaults['language_fallback'],
        ];
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
     *   highlight:bool,
     *   snippet_length:int,
     *   match_mode:string,
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
     * Parse a nonce-protected POST action for the sandbox forms.
     */
    private static function sandbox_post_action(): string
    {
        $action = self::sanitize_key(self::request_text_value($_POST, self::ADMIN_ACTION_FIELD, 40));

        return in_array($action, ['refresh_demo', 'index_demo'], true) ? $action : '';
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
     * Create or update the small demo corpus used by Playground.
     *
     * @return int[]
     */
    private static function create_or_refresh_sandbox_demo_posts(): array
    {
        if (!function_exists('wp_insert_post')) {
            throw new RuntimeException('WordPress post APIs are unavailable.');
        }

        $existing_ids = self::sandbox_demo_post_ids();
        $post_ids = [];
        foreach (self::sandbox_demo_posts() as $offset => $post_data) {
            $existing_id = $existing_ids[$offset] ?? 0;
            if ($existing_id > 0 && self::post_object($existing_id) !== null) {
                $post_data['ID'] = $existing_id;
            }
            unset($post_data['lang']);

            $result = wp_insert_post($post_data, true);
            if (self::is_wordpress_error($result)) {
                throw new RuntimeException(self::wordpress_error_message($result));
            }

            $post_id = (int) $result;
            if ($post_id <= 0) {
                throw new RuntimeException('WordPress did not return a valid post ID.');
            }
            $post_ids[] = $post_id;
        }

        self::set_option(self::SANDBOX_DEMO_POSTS_OPTION, $post_ids);

        return $post_ids;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function sandbox_demo_posts(): array
    {
        return [
            [
                'post_title' => 'FTS Sandbox: English Mice',
                'post_name' => 'wp-fts-sandbox-english-mice',
                'lang' => 'en',
                'post_content' => '<p>Mice study indexed pages while building the search index.</p>',
                'post_excerpt' => 'English UniMorph demo for mice and mouse.',
                'post_status' => 'publish',
                'post_type' => 'post',
            ],
            [
                'post_title' => 'FTS Sandbox: Polish Lemmatizer Demo',
                'post_name' => 'wp-fts-sandbox-polish-lemmatizer-demo',
                'lang' => 'pl',
                'post_content' => '<p>W książkach i zamkach wyszukujemy wpisy oraz kierujemy katalog.</p>',
                'post_excerpt' => 'Polish lemmatizer demo for pack-backed book, castle, entry, and routing forms.',
                'post_status' => 'publish',
                'post_type' => 'post',
            ],
            [
                'post_title' => 'FTS Sandbox: Chinese Search N-grams',
                'post_name' => 'wp-fts-sandbox-chinese-search-ngrams',
                'lang' => 'zh',
                'post_content' => '<p>搜索系统质量指标支持语言搜索。</p>',
                'post_excerpt' => 'Chinese CJK n-gram demo for search-system text.',
                'post_status' => 'publish',
                'post_type' => 'post',
            ],
            [
                'post_title' => 'FTS Sandbox: Hindi Lemmatizer',
                'post_name' => 'wp-fts-sandbox-hindi-lemmatizer',
                'lang' => 'hi',
                'post_content' => '<p>संपादक नया तरीका अपनाता है और स्पष्ट पाठ सूचक के लिए उदाहरण रखता है।</p>',
                'post_excerpt' => 'Hindi UniMorph demo for अपनाता and अपनाना.',
                'post_status' => 'publish',
                'post_type' => 'post',
            ],
            [
                'post_title' => 'FTS Sandbox: Spanish Buscar',
                'post_name' => 'wp-fts-sandbox-spanish-buscar',
                'lang' => 'es',
                'post_content' => '<p>Estamos buscando datos claros para el indice.</p>',
                'post_excerpt' => 'Spanish stemming demo for buscar and buscando.',
                'post_status' => 'publish',
                'post_type' => 'post',
            ],
            [
                'post_title' => 'FTS Sandbox: Arabic Search',
                'post_name' => 'wp-fts-sandbox-arabic-search',
                'lang' => 'ar',
                'post_content' => '<p>آبارا مفيدة في الفهرس ومثال البحث.</p>',
                'post_excerpt' => 'Arabic UniMorph demo for آبارا and بئر.',
                'post_status' => 'publish',
                'post_type' => 'post',
            ],
            [
                'post_title' => 'FTS Sandbox: French Chercher',
                'post_name' => 'wp-fts-sandbox-french-chercher',
                'lang' => 'fr',
                'post_content' => '<p>Les equipes cherchent rapidement dans le guide.</p>',
                'post_excerpt' => 'French UniMorph demo for cherchent and chercher.',
                'post_status' => 'publish',
                'post_type' => 'post',
            ],
            [
                'post_title' => 'FTS Sandbox: Bengali Lemmatizer',
                'post_name' => 'wp-fts-sandbox-bengali-lemmatizer',
                'lang' => 'bn',
                'post_content' => '<p>অনুরোধগুলা সূচিতে রাখা আছে।</p>',
                'post_excerpt' => 'Bengali UniMorph demo for অনুরোধগুলা and অনুরোধ.',
                'post_status' => 'publish',
                'post_type' => 'post',
            ],
            [
                'post_title' => 'FTS Sandbox: Portuguese Pesquisar',
                'post_name' => 'wp-fts-sandbox-portuguese-pesquisar',
                'lang' => 'pt',
                'post_content' => '<p>Estamos pesquisando dados claros para a pesquisa.</p>',
                'post_excerpt' => 'Portuguese stemming demo for pesquisar and pesquisando.',
                'post_status' => 'publish',
                'post_type' => 'post',
            ],
            [
                'post_title' => 'FTS Sandbox: Indonesian Abadi',
                'post_name' => 'wp-fts-sandbox-indonesian-abadi',
                'lang' => 'id',
                'post_content' => '<p>Kami abadikan catatan pencarian dengan data jelas.</p>',
                'post_excerpt' => 'Indonesian UniMorph demo for abadikan and abadi.',
                'post_status' => 'publish',
                'post_type' => 'post',
            ],
            [
                'post_title' => 'FTS Sandbox: Urdu Suffix Baseline',
                'post_name' => 'wp-fts-sandbox-urdu-suffix-baseline',
                'lang' => 'ur',
                'post_content' => '<p>کتابیں فہرستوں میں موجود ہیں۔</p>',
                'post_excerpt' => 'Urdu deterministic suffix-baseline demo.',
                'post_status' => 'publish',
                'post_type' => 'post',
            ],
        ];
    }

    /**
     * Return the language configured for a demo corpus row.
     */
    private static function sandbox_demo_language(int $offset): string
    {
        $post = self::sandbox_demo_posts()[$offset] ?? null;
        $language = is_array($post) && is_scalar($post['lang'] ?? null) ? (string) $post['lang'] : 'en';

        return array_key_exists($language, self::sandbox_language_labels()) && $language !== 'auto' ? $language : 'en';
    }

    /**
     * Build the FTS index for the current demo corpus without WP-CLI.
     *
     * @return array{processed:int,post_ids:int[]}
     */
    private static function index_sandbox_demo_posts(): array
    {
        $post_ids = self::sandbox_demo_post_ids();
        if ($post_ids === []) {
            $post_ids = self::create_or_refresh_sandbox_demo_posts();
        }

        $processed = 0;
        foreach ($post_ids as $offset => $post_id) {
            $post = self::post_object($post_id);
            if ($post !== null && self::is_indexable_post($post)) {
                $language = self::sandbox_demo_language((int) $offset);
                self::index_post($post, [
                    'lang' => $language,
                    'document_lang' => $language,
                    'metadata' => ['language' => $language],
                ], self::sandbox_analyzer());
                self::remove_from_queue([$post_id]);
                $processed++;
                continue;
            }

            self::tombstone_post($post_id);
            self::remove_from_queue([$post_id]);
        }

        return [
            'processed' => $processed,
            'post_ids' => $post_ids,
        ];
    }

    /**
     * Ensure the authorized sandbox starts with a usable demo corpus and index.
     *
     * @param int[] $demo_post_ids
     * @return array{post_ids:int[],created:bool,indexed:bool,processed:int}
     */
    private static function maybe_auto_seed_sandbox_demo(array $demo_post_ids): array
    {
        $created = false;
        $indexed = false;
        $processed = 0;

        if (!self::sandbox_demo_posts_are_available($demo_post_ids)) {
            $demo_post_ids = self::create_or_refresh_sandbox_demo_posts();
            $created = true;
        }

        if (!self::sandbox_demo_index_is_current($demo_post_ids)) {
            $index_result = self::index_sandbox_demo_posts();
            $demo_post_ids = $index_result['post_ids'];
            $processed = $index_result['processed'];
            $indexed = true;
        }

        return [
            'post_ids' => $demo_post_ids,
            'created' => $created,
            'indexed' => $indexed,
            'processed' => $processed,
        ];
    }

    /**
     * @param array{post_ids:int[],created:bool,indexed:bool,processed:int} $auto_seeded
     */
    private static function sandbox_auto_seed_message(array $auto_seeded): string
    {
        if ($auto_seeded['created'] && $auto_seeded['indexed']) {
            return sprintf('Demo posts and the full-text index are ready (%d post(s) indexed).', $auto_seeded['processed']);
        }

        if ($auto_seeded['created']) {
            return 'Demo posts are ready.';
        }

        return sprintf('Demo full-text index is ready (%d post(s) indexed).', $auto_seeded['processed']);
    }

    /**
     * @param int[] $post_ids
     */
    private static function sandbox_demo_posts_are_available(array $post_ids): bool
    {
        if (count($post_ids) !== count(self::sandbox_demo_posts())) {
            return false;
        }

        foreach ($post_ids as $post_id) {
            if ((int) $post_id <= 0 || self::post_object((int) $post_id) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param int[] $post_ids
     */
    private static function sandbox_demo_index_is_current(array $post_ids): bool
    {
        if (!self::sandbox_demo_posts_are_available($post_ids)) {
            return false;
        }

        try {
            $storage = self::storage(false);
            $metadata = WP_FTS_StorageCompat::get_doc_metadata($storage, $post_ids);
            foreach ($post_ids as $offset => $post_id) {
                $post_id = (int) $post_id;
                $expected_language = self::sandbox_demo_language((int) $offset);
                $doc = $storage->get_doc($post_id);
                if ($doc === null || (bool) ($doc['deleted'] ?? false)) {
                    return false;
                }

                $lengths = WP_FTS_StorageCompat::doc_lang_lengths($doc, $expected_language);
                if (($lengths[$expected_language] ?? 0) <= 0) {
                    return false;
                }

                if (!isset($metadata[$post_id]) || self::sandbox_indexed_language($metadata[$post_id], $doc, $expected_language) !== $expected_language) {
                    return false;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return true;
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

    private static function index_sandbox_demo_post_if_known(int $post_id, object $post): bool
    {
        $demo_post_ids = self::sandbox_demo_post_ids();
        $offset = array_search($post_id, $demo_post_ids, true);
        if ($offset === false) {
            return false;
        }

        $language = self::sandbox_demo_language((int) $offset);
        self::index_post($post, [
            'lang' => $language,
            'document_lang' => $language,
            'metadata' => ['language' => $language],
        ], self::sandbox_analyzer());
        self::remove_from_queue([$post_id]);

        return true;
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
        return self::request_text_value($_GET, self::ADMIN_QUERY_FIELD, 200);
    }

    /**
     * Read and allowlist the sandbox query language.
     */
    private static function sandbox_selected_language(): string
    {
        $language = self::sanitize_key(self::request_text_value($_GET, self::ADMIN_LANG_FIELD, 20));

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
    private static function sandbox_search_results(string $query, string $selected_language, array $controls = []): array
    {
        $settings = self::settings();
        $limit = self::clamp_int($controls['limit'] ?? $settings['result_limit'], 1, self::MAX_SEARCH_LIMIT);
        $mode = strtoupper((string) ($controls['mode'] ?? $settings['match_mode']));
        if (!in_array($mode, ['OR', 'AND'], true)) {
            $mode = $settings['match_mode'];
        }

        $storage = self::storage(false);
        $search_options = [
            'mode' => $mode,
            'limit' => $limit,
            'include_total' => true,
            'include_metadata' => true,
            'include_snippets' => true,
            'highlight' => (bool) ($controls['highlight'] ?? $settings['highlight']),
            'snippet_length' => self::clamp_int($controls['snippet_length'] ?? $settings['snippet_length'], self::SETTINGS_SNIPPET_MIN, self::SETTINGS_SNIPPET_MAX),
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
                $payload = $searcher->search($query, $search_options);
                $rows = is_array($payload['results'] ?? null) ? $payload['results'] : [];
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
                    if ($post_id <= 0 || isset($seen_post_ids[$post_id]) || !self::can_read_post_result($post_id)) {
                        continue;
                    }
                    $seen_post_ids[$post_id] = true;
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
        return self::sanitize_runtime_analyzer_options(self::raw_sandbox_demo_analyzer_options());
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
        return self::sanitize_runtime_analyzer_options(self::raw_runtime_analyzer_options());
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
        return self::analyzer_pack_statuses(self::raw_runtime_analyzer_options());
    }

    /**
     * Report configured sandbox/demo lemma packs for admin diagnostics.
     *
     * @return array<int,array{language:string,kind:string,status:string,pack_id:string,fixture_only:bool,reason:string}>
     */
    public static function sandbox_demo_analyzer_pack_statuses(): array
    {
        return self::analyzer_pack_statuses(self::raw_sandbox_demo_analyzer_options());
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
     *   language_fallback:bool,
     *   post_types:string[],
     *   post_statuses:string[],
     *   date_after:string,
     *   date_before:string
     * } $controls
     */
    private static function render_sandbox_search_form(string $query, string $selected_language, array $controls, bool $search_submitted): void
    {
        echo '<h2>Sandbox</h2>';
        echo '<p>Try a query against the same index and saved settings this plugin uses elsewhere. Changes here affect only this test search.</p>';
        echo '<form method="get" action="' . self::esc_url(self::admin_options_general_url()) . '">';
        echo '<input type="hidden" name="page" value="' . self::esc_attr(self::ADMIN_PAGE_SLUG) . '">';
        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_TAB_FIELD) . '" value="' . self::esc_attr(self::ADMIN_SANDBOX_TAB) . '">';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="wp-fts-sandbox-query">Query text</label></th><td>';
        echo '<input id="wp-fts-sandbox-query" type="search" class="regular-text" name="' . self::esc_attr(self::ADMIN_QUERY_FIELD) . '" value="' . self::esc_attr($query) . '">';
        echo '<p class="description">Type the words a visitor or editor would search for.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wp-fts-sandbox-lang">Query language</label></th><td>';
        echo '<select id="wp-fts-sandbox-lang" name="' . self::esc_attr(self::ADMIN_LANG_FIELD) . '">';
        foreach (self::sandbox_query_language_labels() as $language => $label) {
            $selected = $selected_language === $language ? ' selected="selected"' : '';
            echo '<option value="' . self::esc_attr($language) . '"' . $selected . '>' . self::esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Automatic lets the analyzer infer the query language. Site language uses the current WordPress language: ' . self::esc_html(self::sandbox_language_display(self::site_language())) . '.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wp-fts-sandbox-mode">Search term matching</label></th><td>';
        echo '<select id="wp-fts-sandbox-mode" name="' . self::esc_attr(self::ADMIN_MODE_FIELD) . '">';
        self::render_option('OR', 'Match any word (broader)', $controls['mode']);
        self::render_option('AND', 'Require every word (stricter)', $controls['mode']);
        echo '</select>';
        echo '<p class="description">Match any word is broader. Require every word shows only posts that match all searched words.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wp-fts-sandbox-limit">Results per page</label></th><td>';
        echo '<input id="wp-fts-sandbox-limit" type="number" min="1" max="' . self::esc_attr((string) self::MAX_SEARCH_LIMIT) . '" name="' . self::esc_attr(self::ADMIN_LIMIT_FIELD) . '" value="' . self::esc_attr((string) $controls['limit']) . '">';
        echo '<p class="description">Controls how many results are shown in this Sandbox search view.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wp-fts-sandbox-snippet-length">Search result excerpt length</label></th><td>';
        echo '<input id="wp-fts-sandbox-snippet-length" type="number" min="' . self::esc_attr((string) self::SETTINGS_SNIPPET_MIN) . '" max="' . self::esc_attr((string) self::SETTINGS_SNIPPET_MAX) . '" name="' . self::esc_attr(self::ADMIN_SNIPPET_LENGTH_FIELD) . '" value="' . self::esc_attr((string) $controls['snippet_length']) . '">';
        echo '<p class="description">A search result excerpt is the short piece of post text shown around a matching word. Longer excerpts show more surrounding text; shorter excerpts make result tables easier to scan.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Highlight matches</th><td>';
        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_HIGHLIGHT_FIELD) . '" value="0">';
        echo '<label><input type="checkbox" name="' . self::esc_attr(self::ADMIN_HIGHLIGHT_FIELD) . '" value="1"' . ($controls['highlight'] ? ' checked="checked"' : '') . '> On</label>';
        echo '<p class="description">Highlights matching words inside generated excerpts.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Language fallback</th><td>';
        echo '<fieldset><legend class="screen-reader-text">Language fallback</legend>';
        echo '<p><label><input type="radio" name="' . self::esc_attr(self::ADMIN_LANGUAGE_FALLBACK_FIELD) . '" value="1"' . ($controls['language_fallback'] ? ' checked="checked"' : '') . '> Also try the current WordPress site language when needed</label></p>';
        echo '<p><label><input type="radio" name="' . self::esc_attr(self::ADMIN_LANGUAGE_FALLBACK_FIELD) . '" value="0"' . (!$controls['language_fallback'] ? ' checked="checked"' : '') . '> Search only the selected query language</label></p>';
        echo '<p class="description">If the selected query language is unsupported or produces no matches, the Sandbox can also try the current site language. That language is read dynamically from WordPress and may broaden the result set.</p>';
        echo '</fieldset>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Post types to include</th><td>';
        foreach (self::settings_post_type_choices() as $post_type) {
            $checked = in_array($post_type, $controls['post_types'], true) ? ' checked="checked"' : '';
            echo '<label><input type="checkbox" name="' . self::esc_attr(self::ADMIN_POST_TYPE_FIELD) . '[]" value="' . self::esc_attr($post_type) . '"' . $checked . '> <code>' . self::esc_html($post_type) . '</code></label><br>';
        }
        echo '<p class="description">Narrows results to selected indexed content types.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Post statuses to include</th><td>';
        foreach (self::sandbox_post_status_choices() as $status) {
            $checked = in_array($status, $controls['post_statuses'], true) ? ' checked="checked"' : '';
            echo '<label><input type="checkbox" name="' . self::esc_attr(self::ADMIN_POST_STATUS_FIELD) . '[]" value="' . self::esc_attr($status) . '"' . $checked . '> <code>' . self::esc_html($status) . '</code></label><br>';
        }
        echo '<p class="description">Draft, pending, future, and private rows still respect normal WordPress read permissions.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wp-fts-sandbox-date-after">Date after</label></th><td>';
        echo '<input id="wp-fts-sandbox-date-after" type="date" name="' . self::esc_attr(self::ADMIN_DATE_AFTER_FIELD) . '" value="' . self::esc_attr($controls['date_after']) . '">';
        echo '<p class="description">Shows only posts dated on or after this date.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wp-fts-sandbox-date-before">Date before</label></th><td>';
        echo '<input id="wp-fts-sandbox-date-before" type="date" name="' . self::esc_attr(self::ADMIN_DATE_BEFORE_FIELD) . '" value="' . self::esc_attr($controls['date_before']) . '">';
        echo '<p class="description">Shows only posts dated on or before this date.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary" name="' . self::esc_attr(self::ADMIN_SEARCH_FIELD) . '" value="1">Search</button></p>';
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
    private static function render_sandbox_indexed_posts_table(array $page, string $query, string $selected_language, bool $search_submitted): void
    {
        if ($page['total'] <= 0) {
            echo '<p>No indexed posts are available yet.</p>';
            return;
        }

        $start = (($page['page'] - 1) * $page['per_page']) + 1;
        $end = min($page['total'], $start + count($page['rows']) - 1);
        echo '<p>Showing ' . self::esc_html((string) $start) . '-' . self::esc_html((string) $end) . ' of ' . self::esc_html((string) $page['total']) . ' indexed post(s).</p>';

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
            self::render_sandbox_indexed_terms($row['indexed_terms'], $row['indexed_terms_more']);
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
            echo '<a class="button" href="' . self::esc_url(self::sandbox_indexed_posts_page_url($page['page'] - 1, $query, $selected_language, $search_submitted)) . '">Previous</a> ';
        }
        if ($page['page'] < $page['total_pages']) {
            echo '<a class="button" href="' . self::esc_url(self::sandbox_indexed_posts_page_url($page['page'] + 1, $query, $selected_language, $search_submitted)) . '">Next</a>';
        }
        echo '</p>';
    }

    /**
     * @param array{requested_lang:string,query_lang:string,total:int,results:array<int,array{post_id:int,title:string,score:float,language:string,snippet:string}>} $results
     */
    private static function render_sandbox_results(array $results): void
    {
        echo '<h2>Results</h2>';
        echo '<p>Requested query language: <code>' . self::esc_html($results['requested_lang']) . '</code>. ';
        echo 'Resolved query language: <code>' . self::esc_html($results['query_lang'] !== '' ? $results['query_lang'] : 'unknown') . '</code>.</p>';
        if ($results['results'] === []) {
            echo '<p>No results matched the current index.</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th scope="col">Post ID</th><th scope="col">Title</th><th scope="col">Score</th><th scope="col">Language</th><th scope="col">Search result excerpt</th></tr></thead>';
        echo '<tbody>';
        foreach ($results['results'] as $row) {
            echo '<tr>';
            echo '<td>' . self::esc_html((string) $row['post_id']) . '</td>';
            echo '<td>' . self::esc_html($row['title']) . '</td>';
            echo '<td>' . self::esc_html(number_format($row['score'], 6, '.', '')) . '</td>';
            echo '<td><code>' . self::esc_html($row['language']) . '</code></td>';
            echo '<td>' . self::esc_html_preserving_marks($row['snippet']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
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
    private static function sandbox_indexed_posts_page(int $page): array
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
            $indexed_terms = WP_FTS_StorageCompat::terms_for_doc($storage, $post_id, self::SANDBOX_INDEXED_TERMS_LIMIT + 1);
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
    private static function render_sandbox_indexed_terms(array $terms, bool $has_more): void
    {
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
     * @return array{label:string,full:bool,reason:string}
     */
    private static function language_support_details(string $language, bool $include_sandbox): array
    {
        $language = WP_FTS_TermNamespace::canonicalize_lang($language, WP_FTS_TermNamespace::DEFAULT_LANG);
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
                    return [
                        'label' => 'Full morphology',
                        'full' => true,
                        'reason' => 'An active full analyzer pack is available.',
                    ];
                }
                $fixture = true;
            } elseif (($status['kind'] ?? '') === 'tokenizer') {
                $tokenizer = true;
            }
        }

        if ($fixture) {
            return [
                'label' => 'Fixture morphology',
                'full' => false,
                'reason' => 'Only a fixture-sized analyzer pack is active.',
            ];
        }
        if ($tokenizer) {
            return [
                'label' => 'Tokenizer pack',
                'full' => false,
                'reason' => 'A tokenizer pack is active, but full morphology is unavailable.',
            ];
        }

        return [
            'label' => 'Conservative fallback',
            'full' => false,
            'reason' => 'Full morphology is unavailable for this language.',
        ];
    }

    private static function render_site_language_status_notice(): void
    {
        $language = self::site_language();
        $support = self::language_support_details($language, false);
        if ($support['full']) {
            return;
        }

        self::render_sandbox_notice(
            'info',
            sprintf(
                'Current site language %s uses %s because full morphology is unavailable. Exact-word search still works; enable an analyzer pack for this language to match language-specific word forms.',
                self::sandbox_language_display($language),
                strtolower($support['label'])
            )
        );
    }

    private static function site_language(): string
    {
        return WP_FTS_TermNamespace::default_language();
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

    private static function sandbox_indexed_posts_page_url(int $page, string $query, string $selected_language, bool $search_submitted): string
    {
        $params = [
            'page' => self::ADMIN_PAGE_SLUG,
            self::ADMIN_TAB_FIELD => self::ADMIN_INDEXED_TAB,
            self::ADMIN_POSTS_PAGE_FIELD => (string) max(1, $page),
        ];

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
     * Normalize a raw searcher row for sandbox display.
     *
     * @param array<string,mixed> $row
     * @return array{post_id:int,title:string,score:float,language:string,snippet:string}
     */
    private static function sandbox_result_row(array $row, WP_FTS_Storage $storage, int $post_id): array
    {
        $metadata = WP_FTS_StorageCompat::get_doc_metadata($storage, [$post_id]);
        $meta = $metadata[$post_id] ?? [];
        $doc = $storage->get_doc($post_id);
        $language = '';
        foreach ([
            $row['language'] ?? null,
            $meta['language'] ?? null,
            $meta['lang'] ?? null,
            $doc['primary_lang'] ?? null,
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
            : (string) ($meta['search_text'] ?? $meta['excerpt'] ?? '');

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
        $nonce = function_exists('wp_create_nonce') ? (string) wp_create_nonce(self::ADMIN_NONCE_ACTION) : '';

        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_NONCE_FIELD) . '" value="' . self::esc_attr($nonce) . '">';
    }

    private static function admin_page_url(string $tab = self::ADMIN_SETTINGS_TAB): string
    {
        $params = ['page' => self::ADMIN_PAGE_SLUG];
        $tab = self::sanitize_admin_tab($tab);
        if ($tab !== self::ADMIN_SETTINGS_TAB) {
            $params[self::ADMIN_TAB_FIELD] = $tab;
        }

        return self::admin_options_general_url() . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private static function admin_options_url(): string
    {
        return function_exists('admin_url') ? (string) admin_url('options.php') : 'options.php';
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

        return [
            'results' => self::search($query, [
                'lang' => self::request_param($request, 'lang', null),
                'mode' => $mode,
                'limit' => self::request_param($request, 'limit', 10),
            ]),
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
        $search_options = [
            'mode' => $mode,
            'limit' => $limit,
        ];
        if (isset($opts['lang']) && is_scalar($opts['lang']) && trim((string) $opts['lang']) !== '') {
            $search_options['lang'] = (string) $opts['lang'];
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
     * Mark eligible front-end search queries before posts are requested.
     *
     * @param mixed $query WordPress WP_Query-like object.
     */
    public static function prepare_frontend_search_query(mixed $query): void
    {
        if (!self::is_frontend_search_query($query)) {
            return;
        }

        self::set_query_var($query, 'wp_fts_search_candidate', true);
    }

    /**
     * Short-circuit the main front-end search query with FTS-ranked posts.
     *
     * @param mixed $posts Null when WordPress has not already short-circuited the query.
     * @param mixed $query WordPress WP_Query-like object.
     * @return mixed Null to leave WordPress alone, or an array of post objects.
     */
    public static function replace_frontend_search_posts(mixed $posts, mixed $query): mixed
    {
        if ($posts !== null || !self::should_replace_frontend_search($query)) {
            return $posts;
        }

        $search_query = self::frontend_search_query_text($query);
        if ($search_query === '') {
            return $posts;
        }

        $result = self::frontend_search_result_page($query, $search_query);
        self::store_frontend_search_query_state(
            $query,
            $result['total'],
            $result['limit'],
            $result['query_lang'],
            $result['snippets'],
            $result['titles']
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
            return;
        }

        self::set_query_var($query, 'wp_fts_admin_post_search_candidate', true);
    }

    /**
     * Short-circuit the main wp-admin Posts list search with FTS-ranked posts.
     *
     * @param mixed $posts Null when WordPress has not already short-circuited the query.
     * @param mixed $query WordPress WP_Query-like object.
     * @return mixed Null to leave WordPress alone, or an array of post objects.
     */
    public static function replace_admin_post_search_posts(mixed $posts, mixed $query): mixed
    {
        if ($posts !== null || !self::should_replace_admin_post_search($query)) {
            return $posts;
        }

        $search_query = self::frontend_search_query_text($query);
        if ($search_query === '') {
            return $posts;
        }

        $result = self::admin_post_search_result_page($query, $search_query);
        self::store_admin_post_search_query_state(
            $query,
            $result['total'],
            $result['limit'],
            $result['query_lang']
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
        self::$front_end_search_active_query_key = $query_key > 0 && isset(self::$front_end_search_query_state[$query_key])
            ? $query_key
            : 0;
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

        $query_key = self::$front_end_search_active_query_key;
        if (
            $post_id > 0
            && $query_key > 0
            && isset(self::$front_end_search_query_state[$query_key]['snippets'][$post_id])
        ) {
            return self::$front_end_search_query_state[$query_key]['snippets'][$post_id];
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
        $query_key = self::$front_end_search_active_query_key;
        if (
            $post_id > 0
            && $query_key > 0
            && isset(self::$front_end_search_query_state[$query_key]['snippets'][$post_id])
        ) {
            return self::frontend_content_preview_markup(self::$front_end_search_query_state[$query_key]['snippets'][$post_id]);
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

        $query_key = self::$front_end_search_active_query_key;
        if (
            $id > 0
            && $query_key > 0
            && isset(self::$front_end_search_query_state[$query_key]['titles'][$id])
        ) {
            return self::$front_end_search_query_state[$query_key]['titles'][$id];
        }

        return is_scalar($title) ? (string) $title : '';
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
    private static function frontend_search_result_page(mixed $query, string $search_query): array
    {
        return self::search_result_page(
            $query,
            $search_query,
            self::frontend_query_post_types($query),
            self::FRONTEND_SEARCH_POST_STATUSES,
            'frontend'
        );
    }

    /**
     * @return array{posts:array<int,object>,snippets:array<int,string>,titles:array<int,string>,total:int,limit:int,query_lang:string}
     */
    private static function admin_post_search_result_page(mixed $query, string $search_query): array
    {
        return self::search_result_page(
            $query,
            $search_query,
            self::admin_post_search_post_types($query),
            self::admin_post_search_statuses($query),
            'admin'
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
        string $visibility_context
    ): array
    {
        $limit = self::frontend_query_limit($query);
        $offset = self::frontend_query_offset($query, $limit);
        if ($post_types === [] || $post_statuses === []) {
            return [
                'posts' => [],
                'snippets' => [],
                'titles' => [],
                'total' => 0,
                'limit' => $limit,
                'query_lang' => '',
            ];
        }

        $settings = self::settings();
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
            'post_type' => $post_types,
            'post_status' => $post_statuses,
        ];
        if ($settings['language_fallback']) {
            $search_options['language_fallback'] = true;
            $search_options['fallback_languages'] = self::site_fallback_languages();
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

            $payload = $searcher->search($search_query, $search_options);
            $rows = is_array($payload['results'] ?? null) ? $payload['results'] : [];
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

                $visible = $visibility_context === 'admin'
                    ? self::admin_post_result_visible($post_id, $post_types)
                    : self::frontend_post_result_visible($post_id, $post_types);
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
                $snippet = self::frontend_content_preview_snippet($searcher, $post, $search_query, $query_lang, $result_lang, $snippet_languages);
                if ($snippet === '' && isset($row['snippet']) && is_scalar($row['snippet'])) {
                    $snippet = self::sanitize_frontend_snippet_html((string) $row['snippet']);
                }
                if ($snippet !== '') {
                    $snippets[$post_id] = $snippet;
                }
                $title = self::frontend_title_snippet($searcher, $post, $search_query, $query_lang, $result_lang, $snippet_languages);
                if ($title !== '') {
                    $titles[$post_id] = $title;
                }
            }

            $search_offset += (int) $search_options['limit'];
            if (count($rows) < (int) $search_options['limit'] || ($metadata_total > 0 && $search_offset >= $metadata_total)) {
                break;
            }
        }

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
    private static function store_frontend_search_query_state(mixed $query, int $total, int $limit, string $query_lang, array $snippets, array $titles): void
    {
        $max_pages = $total > 0 ? (int) ceil($total / max(1, $limit)) : 0;
        $query_key = self::query_object_key($query);
        if ($query_key > 0) {
            self::$front_end_search_query_state = [
                $query_key => [
                    'total' => $total,
                    'max_pages' => $max_pages,
                    'query_lang' => $query_lang,
                    'snippets' => $snippets,
                    'titles' => $titles,
                ],
            ];
        }

        self::set_query_property($query, 'found_posts', $total);
        self::set_query_property($query, 'max_num_pages', $max_pages);
        self::set_query_var($query, 'wp_fts_query_lang', $query_lang);
        self::set_query_var($query, 'wp_fts_found_posts', $total);
    }

    private static function store_admin_post_search_query_state(mixed $query, int $total, int $limit, string $query_lang): void
    {
        $max_pages = $total > 0 ? (int) ceil($total / max(1, $limit)) : 0;
        $query_key = self::query_object_key($query);
        if ($query_key > 0) {
            self::$admin_post_search_query_state = [
                $query_key => [
                    'total' => $total,
                    'max_pages' => $max_pages,
                    'query_lang' => $query_lang,
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
     * Index one WordPress post object.
     *
     * @param array<string,mixed> $opts
     */
    private static function index_post(object $post, array $opts = [], ?WP_FTS_Analyzer $analyzer = null): void
    {
        self::maybe_upgrade_schema();
        (new WP_FTS_Indexer(self::storage(false), $analyzer ?? self::runtime_analyzer()))->index_post($post, $opts);
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
    }

    /**
     * Compare stored WordPress option values without assuming integer storage.
     */
    private static function option_matches_schema_version(mixed $value): bool
    {
        return is_scalar($value) && (int) $value === self::SCHEMA_VERSION;
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
        if (strlen($value) > $max_length) {
            return substr($value, 0, $max_length);
        }

        return $value;
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
                $query = trim((string) $value);
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
