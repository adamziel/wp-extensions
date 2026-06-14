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
    public const ADMIN_PAGE_SLUG = 'wp-fts-sandbox';
    public const ADMIN_CAPABILITY = 'manage_options';
    public const SANDBOX_DEMO_POSTS_OPTION = 'wp_fts_sandbox_demo_post_ids';
    public const ANALYZER_OPTIONS_OPTION = 'wp_fts_analyzer_options';
    public const ANALYZER_OPTIONS_FILTER = 'wp_fts_analyzer_options';
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
    private const POST_LANGUAGE_FIELD = 'wp_fts_post_language';
    private const POST_LANGUAGE_NONCE_ACTION = 'wp_fts_post_language';
    private const POST_LANGUAGE_NONCE_FIELD = 'wp_fts_post_language_nonce';
    private const SANDBOX_INDEXED_POSTS_PER_PAGE = 10;
    private const VISIBILITY_REFILL_MIN_BATCH = 10;
    private const VISIBILITY_REFILL_MULTIPLIER = 4;
    private const VISIBILITY_REFILL_MAX_SCAN = 250;

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
        add_action('add_meta_boxes', [self::class, 'register_language_meta_box'], 10, 0);
        add_action('save_post', [self::class, 'save_post_language_override'], 5, 3);
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
     * Non-indexable saved posts are tombstoned immediately so unpublished,
     * private, trashed, or deleted content cannot linger in search results.
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

        if ($post !== null && self::index_sandbox_demo_post_if_known($post_id, $post)) {
            return;
        }

        if ($post !== null) {
            self::index_post($post, [], self::runtime_analyzer());
            self::remove_from_queue([$post_id]);
        }
    }

    /**
     * Keep indexed state aligned when a post becomes public or leaves visibility.
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
     * Register a small wp-admin sandbox under Tools.
     */
    public static function register_admin_menu(): void
    {
        if (!function_exists('add_management_page')) {
            return;
        }

        add_management_page(
            'FTS Sandbox',
            'FTS Sandbox',
            self::ADMIN_CAPABILITY,
            self::ADMIN_PAGE_SLUG,
            [self::class, 'render_admin_sandbox']
        );
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
        foreach (self::sandbox_language_labels() as $language => $label) {
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
     * Render the admin-only FTS sandbox page.
     */
    public static function render_admin_sandbox(): void
    {
        if (!self::can_manage_admin_sandbox()) {
            echo '<div class="wrap">';
            echo '<h1>FTS Sandbox</h1>';
            self::render_sandbox_notice('error', 'You do not have permission to use the FTS sandbox.');
            echo '</div>';
            return;
        }

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
                        sprintf('Processed %d demo post(s) into the FTS index.', $indexed['processed']),
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
        if ($query === '' && !$search_submitted) {
            $query = 'run';
        }

        $results = self::empty_sandbox_search_results($selected_language);
        if ($search_submitted) {
            if ($query === '') {
                $messages[] = ['error', 'Enter a search query before running the sandbox search.'];
            } else {
                try {
                    $results = self::sandbox_search_results($query, $selected_language);
                    $messages[] = ['info', sprintf('Search returned %d result(s).', count($results['results']))];
                } catch (Throwable $e) {
                    $messages[] = ['error', 'Could not run the sandbox search: ' . $e->getMessage()];
                }
            }
        }

        try {
            $indexed_posts = self::sandbox_indexed_posts_page(self::sandbox_indexed_posts_page_number());
        } catch (Throwable $e) {
            $messages[] = ['error', 'Could not read indexed posts: ' . $e->getMessage()];
            $indexed_posts = self::empty_sandbox_indexed_posts_page(self::sandbox_indexed_posts_page_number());
        }

        self::render_admin_sandbox_page($messages, $indexed_posts, $query, $selected_language, $search_submitted, $results);
    }

    /**
     * Current admin user gate for all sandbox rendering and actions.
     */
    private static function can_manage_admin_sandbox(): bool
    {
        return function_exists('current_user_can') && current_user_can(self::ADMIN_CAPABILITY);
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
                'post_title' => 'FTS Sandbox: English Running',
                'post_name' => 'wp-fts-sandbox-running-notes',
                'lang' => 'en',
                'post_content' => '<p>Running steadily builds the search index while the team studies indexed pages.</p>',
                'post_excerpt' => 'English stemming demo for running and run.',
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
                'post_title' => 'FTS Sandbox: Hindi Plurals',
                'post_name' => 'wp-fts-sandbox-hindi-plurals',
                'lang' => 'hi',
                'post_content' => '<p>किताबें सूचियों में रखी हैं और स्पष्ट पाठ सूचक के लिए उदाहरण है।</p>',
                'post_excerpt' => 'Hindi stemming demo for plural and oblique forms.',
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
                'post_content' => '<p>أأباحتاهم مفيدة للبحث في الفهرس.</p>',
                'post_excerpt' => 'Arabic stemming demo for verified search forms.',
                'post_status' => 'publish',
                'post_type' => 'post',
            ],
            [
                'post_title' => 'FTS Sandbox: French Manger',
                'post_name' => 'wp-fts-sandbox-french-manger',
                'lang' => 'fr',
                'post_content' => '<p>Les enfants mangeaient rapidement dans le guide.</p>',
                'post_excerpt' => 'French stemming demo for manger and mangeaient.',
                'post_status' => 'publish',
                'post_type' => 'post',
            ],
            [
                'post_title' => 'FTS Sandbox: Bengali Suffix Baseline',
                'post_name' => 'wp-fts-sandbox-bengali-suffix-baseline',
                'lang' => 'bn',
                'post_content' => '<p>শব্দগুলো সূচিতে রাখা আছে।</p>',
                'post_excerpt' => 'Bengali deterministic suffix-baseline demo.',
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
                'post_title' => 'FTS Sandbox: Indonesian Cari',
                'post_name' => 'wp-fts-sandbox-indonesian-cari',
                'lang' => 'id',
                'post_content' => '<p>Kami sedang mencari data pencarian dengan catatan jelas.</p>',
                'post_excerpt' => 'Indonesian stemming demo for cari, mencari, and pencarian.',
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
            return sprintf('Demo posts and FTS index are ready (%d post(s) indexed).', $auto_seeded['processed']);
        }

        if ($auto_seeded['created']) {
            return 'Demo posts are ready.';
        }

        return sprintf('Demo FTS index is ready (%d post(s) indexed).', $auto_seeded['processed']);
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

        return array_key_exists($language, self::sandbox_language_labels()) ? $language : 'auto';
    }

    /**
     * @return array<string,string>
     */
    private static function sandbox_language_labels(): array
    {
        return [
            'auto' => 'Automatic',
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
     * @return string[]
     */
    private static function sandbox_auto_search_languages(): array
    {
        return array_values(array_filter(
            array_keys(self::sandbox_language_labels()),
            static fn(string $language): bool => $language !== 'auto'
        ));
    }

    /**
     * @return array<string,string>
     */
    private static function sandbox_demo_query_suggestions(): array
    {
        return [
            'en' => 'run',
            'pl' => 'kierować zamek',
            'zh' => '搜索系统',
            'hi' => 'किताब सूची',
            'es' => 'buscar',
            'ar' => 'اباح بحث',
            'fr' => 'manger',
            'bn' => 'শব্দ সূচি',
            'pt' => 'pesquisar',
            'id' => 'cari',
            'ur' => 'کتاب فہرست',
        ];
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
     * @return array{requested_lang:string,query_lang:string,total:int,results:array<int,array{post_id:int,title:string,score:float,language:string,snippet:string}>}
     */
    private static function sandbox_search_results(string $query, string $selected_language): array
    {
        $limit = 10;
        $storage = self::storage(false);
        $searcher = new WP_FTS_Searcher($storage, self::sandbox_analyzer());
        $search_options = [
            'mode' => 'OR',
            'limit' => $limit,
            'include_total' => true,
            'include_metadata' => true,
            'include_snippets' => true,
            'highlight' => true,
            'snippet_length' => 180,
        ];
        if ($selected_language !== 'auto') {
            $search_options['lang'] = $selected_language;
            $search_options['query_lang'] = $selected_language;
        } else {
            $search_options['languages'] = self::sandbox_auto_search_languages();
        }

        $visible = [];
        $offset = 0;
        $total = 0;
        $query_language = '';
        $batch_limit = self::visibility_refill_batch_limit($limit);
        while (count($visible) < $limit && $offset < self::VISIBILITY_REFILL_MAX_SCAN) {
            $search_options['limit'] = min($batch_limit, self::VISIBILITY_REFILL_MAX_SCAN - $offset);
            $search_options['offset'] = $offset;
            $payload = $searcher->search($query, $search_options);
            $rows = is_array($payload['results'] ?? null) ? $payload['results'] : [];
            $total = is_numeric($payload['total'] ?? null) ? (int) $payload['total'] : $total;
            if (is_scalar($payload['query_lang'] ?? null) && trim((string) $payload['query_lang']) !== '') {
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
                if ($post_id <= 0 || !self::can_read_post_result($post_id)) {
                    continue;
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
        $query_language = self::sandbox_resolved_query_language($selected_language, $query_language, $visible);

        return [
            'requested_lang' => $selected_language,
            'query_lang' => $query_language,
            'total' => $total,
            'results' => $visible,
        ];
    }

    /**
     * Use the runtime analyzer for the admin demo corpus.
     */
    private static function sandbox_analyzer(): WP_FTS_Analyzer
    {
        return self::runtime_analyzer();
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
     * Report configured runtime lemma packs for admin diagnostics.
     *
     * @return array<int,array{language:string,status:string,pack_id:string,fixture_only:bool,reason:string}>
     */
    public static function runtime_analyzer_pack_statuses(): array
    {
        $statuses = [];
        foreach (self::runtime_lemma_pack_options_by_language(self::raw_runtime_analyzer_options()) as $language => $option) {
            if (self::lemma_pack_option_is_disabled($option)) {
                $statuses[] = [
                    'language' => $language,
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
                    'status' => 'ignored',
                    'pack_id' => '',
                    'fixture_only' => false,
                    'reason' => 'Missing, invalid, or language-mismatched manifest.',
                ];
                continue;
            }

            $statuses[] = [
                'language' => $language,
                'status' => 'active',
                'pack_id' => $pack->pack_id(),
                'fixture_only' => $pack->is_fixture_only(),
                'reason' => '',
            ];
        }

        return $statuses;
    }

    /**
     * Read bundled defaults, the WordPress option, and the analyzer options filter.
     *
     * @return array<string,mixed>
     */
    private static function raw_runtime_analyzer_options(): array
    {
        $options = [
            'lemmatizer_packs_by_lang' => self::bundled_runtime_lemma_packs_by_lang(),
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
        $packs = self::runtime_lemma_pack_options_by_language($options);
        if ($packs === []) {
            return [];
        }

        return [
            'lemmatizer_packs_by_lang' => $packs,
        ];
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
                in_array($key, ['lemma_packs_by_lang', 'lemmatizer_packs_by_lang'], true)
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

        foreach (['lemma_packs_by_lang', 'lemmatizer_packs_by_lang'] as $key) {
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

        foreach (['lemmatizer_packs_by_lang', 'lemma_packs_by_lang'] as $key) {
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
     * @return array<string,bool|string>
     */
    private static function bundled_runtime_lemma_packs_by_lang(): array
    {
        return [
            'pl' => self::sandbox_polish_lemmatizer_pack(),
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
     * Render the wp-admin sandbox surface.
     *
     * @param array<int,array{0:string,1:string}> $messages
     * @param array{page:int,per_page:int,total:int,total_pages:int,rows:array<int,array{post_id:int,title:string,post_type:string,post_status:string,language:string,length:int,preview:string}>} $indexed_posts
     * @param array{requested_lang:string,query_lang:string,total:int,results:array<int,array{post_id:int,title:string,score:float,language:string,snippet:string}>} $results
     */
    private static function render_admin_sandbox_page(array $messages, array $indexed_posts, string $query, string $selected_language, bool $search_submitted, array $results): void
    {
        echo '<div class="wrap">';
        echo '<h1>Pure PHP FTS Sandbox</h1>';

        foreach ($messages as $message) {
            self::render_sandbox_notice($message[0], $message[1]);
        }

        self::render_sandbox_analyzer_pack_statuses();

        echo '<h2>Indexed posts</h2>';
        echo '<p>The sandbox prepares demo posts and indexes them automatically. Automatic detection is the default; choose a language on the post edit screen to pin indexing for that post.</p>';
        self::render_sandbox_indexed_posts_table($indexed_posts, $query, $selected_language, $search_submitted);

        echo '<h2>Search</h2>';
        self::render_sandbox_query_suggestions();
        echo '<form method="get" action="' . self::esc_url(self::admin_tools_url()) . '">';
        echo '<input type="hidden" name="page" value="' . self::esc_attr(self::ADMIN_PAGE_SLUG) . '">';
        echo '<label for="wp-fts-sandbox-query">Query</label> ';
        echo '<input id="wp-fts-sandbox-query" type="search" class="regular-text" name="' . self::esc_attr(self::ADMIN_QUERY_FIELD) . '" value="' . self::esc_attr($query) . '"> ';
        echo '<label for="wp-fts-sandbox-lang">Query language</label> ';
        echo '<select id="wp-fts-sandbox-lang" name="' . self::esc_attr(self::ADMIN_LANG_FIELD) . '">';
        foreach (self::sandbox_language_labels() as $language => $label) {
            $selected = $selected_language === $language ? ' selected="selected"' : '';
            echo '<option value="' . self::esc_attr($language) . '"' . $selected . '>' . self::esc_html($label) . '</option>';
        }
        echo '</select> ';
        echo '<button type="submit" class="button button-primary" name="' . self::esc_attr(self::ADMIN_SEARCH_FIELD) . '" value="1">Search</button>';
        echo '</form>';

        if ($search_submitted) {
            self::render_sandbox_results($results);
        }

        echo '</div>';
    }

    private static function render_sandbox_query_suggestions(): void
    {
        echo '<h3>Suggested queries</h3>';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th scope="col">Language</th><th scope="col">Query</th></tr></thead>';
        echo '<tbody>';
        foreach (self::sandbox_demo_query_suggestions() as $language => $suggestion) {
            echo '<tr>';
            echo '<td>' . self::esc_html(self::sandbox_language_display($language)) . '</td>';
            echo '<td><code>' . self::esc_html($suggestion) . '</code></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_sandbox_analyzer_pack_statuses(): void
    {
        $statuses = self::runtime_analyzer_pack_statuses();

        echo '<h2>Analyzer packs</h2>';
        if ($statuses === []) {
            echo '<p>No lemma packs are configured. Languages without an active pack use their built-in stemmer or tokenizer behavior.</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th scope="col">Language</th><th scope="col">Status</th><th scope="col">Pack</th><th scope="col">Scope</th></tr></thead>';
        echo '<tbody>';
        foreach ($statuses as $status) {
            $scope = $status['status'] === 'active'
                ? ($status['fixture_only'] ? 'Fixture' : 'Full local pack')
                : $status['reason'];
            $pack = $status['pack_id'] !== '' ? $status['pack_id'] : '-';
            echo '<tr>';
            echo '<td>' . self::esc_html(self::sandbox_language_display($status['language'])) . '</td>';
            echo '<td>' . self::esc_html(ucfirst($status['status'])) . '</td>';
            echo '<td><code>' . self::esc_html($pack) . '</code></td>';
            echo '<td>' . self::esc_html($scope) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * @param array{page:int,per_page:int,total:int,total_pages:int,rows:array<int,array{post_id:int,title:string,post_type:string,post_status:string,language:string,length:int,preview:string}>} $page
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
        echo '<thead><tr><th scope="col">Post ID</th><th scope="col">Title</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Language</th><th scope="col">Indexed length</th><th scope="col">Content preview</th></tr></thead>';
        echo '<tbody>';
        foreach ($page['rows'] as $row) {
            echo '<tr>';
            echo '<td>' . self::esc_html((string) $row['post_id']) . '</td>';
            echo '<td>' . self::esc_html($row['title']) . '</td>';
            echo '<td><code>' . self::esc_html($row['post_type']) . '</code></td>';
            echo '<td><code>' . self::esc_html($row['post_status']) . '</code></td>';
            echo '<td>' . self::esc_html($row['language']) . '</td>';
            echo '<td>' . self::esc_html((string) $row['length']) . '</td>';
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
        echo '<thead><tr><th scope="col">Post ID</th><th scope="col">Title</th><th scope="col">Score</th><th scope="col">Language</th><th scope="col">Snippet</th></tr></thead>';
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
     * @return array{page:int,per_page:int,total:int,total_pages:int,rows:array<int,array{post_id:int,title:string,post_type:string,post_status:string,language:string,length:int,preview:string}>}
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
     * @return array{page:int,per_page:int,total:int,total_pages:int,rows:array<int,array{post_id:int,title:string,post_type:string,post_status:string,language:string,length:int,preview:string}>}
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
            $rows[] = self::sandbox_indexed_post_row($post_id, $metadata[$post_id] ?? [], $doc);
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
     * @return array{post_id:int,title:string,post_type:string,post_status:string,language:string,length:int,preview:string}
     */
    private static function sandbox_indexed_post_row(int $post_id, array $metadata, array $doc): array
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
            'preview' => self::sanitize_text($preview),
        ];
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
            $display[] = self::sandbox_language_display($language);
        }

        return implode(', ', array_values(array_unique($display)));
    }

    private static function sandbox_language_display(string $language): string
    {
        $language = WP_FTS_TermNamespace::canonicalize_lang($language);
        if ($language === '') {
            return 'unknown';
        }

        $label = self::sandbox_language_labels()[$language] ?? strtoupper($language);

        return sprintf('%s (%s)', $label, $language);
    }

    private static function sandbox_indexed_posts_page_url(int $page, string $query, string $selected_language, bool $search_submitted): string
    {
        $params = [
            'page' => self::ADMIN_PAGE_SLUG,
            self::ADMIN_POSTS_PAGE_FIELD => (string) max(1, $page),
        ];

        if ($search_submitted) {
            if ($query !== '') {
                $params[self::ADMIN_QUERY_FIELD] = $query;
            }
            $params[self::ADMIN_LANG_FIELD] = $selected_language;
            $params[self::ADMIN_SEARCH_FIELD] = '1';
        }

        return self::admin_tools_url() . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
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

    private static function admin_page_url(): string
    {
        return self::admin_tools_url() . '?page=' . rawurlencode(self::ADMIN_PAGE_SLUG);
    }

    private static function admin_tools_url(): string
    {
        return function_exists('admin_url') ? (string) admin_url('tools.php') : 'tools.php';
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
     * Safe defaults: index only published, searchable public post types.
     */
    private static function is_indexable_post(object $post): bool
    {
        $status = isset($post->post_status) ? (string) $post->post_status : '';
        if ($status === '' && isset($post->ID) && function_exists('get_post_status')) {
            $status = (string) get_post_status((int) $post->ID);
        }
        if ($status !== 'publish') {
            return false;
        }

        if (isset($post->post_password) && (string) $post->post_password !== '') {
            return false;
        }

        $type = isset($post->post_type) && is_scalar($post->post_type) ? (string) $post->post_type : 'post';
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
     * Search results expose public searchable posts, or private posts readable by the user.
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

        if (self::is_indexable_post($post)) {
            return true;
        }

        return function_exists('current_user_can') && current_user_can('read_post', $post_id);
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
