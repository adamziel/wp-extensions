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
    public const DEFAULT_BATCH_SIZE = 25;
    public const MAX_SEARCH_LIMIT = 50;
    private const ADMIN_NONCE_ACTION = 'wp_fts_sandbox_admin_action';
    private const ADMIN_NONCE_FIELD = 'wp_fts_sandbox_nonce';
    private const ADMIN_ACTION_FIELD = 'wp_fts_sandbox_action';
    private const ADMIN_QUERY_FIELD = 'wp_fts_sandbox_query';
    private const ADMIN_SEARCH_FIELD = 'wp_fts_sandbox_search';
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
            return;
        }

        self::queue_post($post_id);
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
            self::queue_post($post_id);
            return;
        }

        if ($old_status !== $new_status) {
            self::tombstone_post($post_id);
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
                self::index_post($post);
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

        $search_submitted = self::sandbox_search_submitted();
        $query = self::sandbox_search_query();
        if ($query === '' && !$search_submitted) {
            $query = 'run';
        }

        $results = [];
        if ($search_submitted) {
            if ($query === '') {
                $messages[] = ['error', 'Enter a search query before running the sandbox search.'];
            } else {
                try {
                    $results = self::sandbox_search_results($query);
                    $messages[] = ['info', sprintf('Search returned %d result(s).', count($results))];
                } catch (Throwable $e) {
                    $messages[] = ['error', 'Could not run the sandbox search: ' . $e->getMessage()];
                }
            }
        }

        self::render_admin_sandbox_page($messages, $demo_post_ids, $query, $search_submitted, $results);
    }

    /**
     * Current admin user gate for all sandbox rendering and actions.
     */
    private static function can_manage_admin_sandbox(): bool
    {
        return function_exists('current_user_can') && current_user_can(self::ADMIN_CAPABILITY);
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
        foreach ($raw as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0) {
                $ids[$post_id] = true;
            }
        }

        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);

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
                'post_title' => 'FTS Sandbox: Running Notes',
                'post_name' => 'wp-fts-sandbox-running-notes',
                'post_content' => '<p>The athlete is running every morning before a short recovery run while the team tracks route notes and training progress.</p>',
                'post_excerpt' => 'English stemming demo for runners and running.',
                'post_status' => 'publish',
                'post_type' => 'post',
            ],
            [
                'post_title' => 'FTS Sandbox: Indexing Checklist',
                'post_name' => 'wp-fts-sandbox-indexing-checklist',
                'post_content' => '<p>Refresh the demo posts, build the index, and compare ranked search scores in wp-admin.</p>',
                'post_excerpt' => 'Operational demo content for the browser sandbox.',
                'post_status' => 'publish',
                'post_type' => 'post',
            ],
            [
                'post_title' => 'FTS Sandbox: WordPress Search Signals',
                'post_name' => 'wp-fts-sandbox-wordpress-search-signals',
                'post_content' => '<p>Titles, excerpts, and post content all contribute terms to the pure PHP full-text search index.</p>',
                'post_excerpt' => 'A compact document for comparing search result scores.',
                'post_status' => 'publish',
                'post_type' => 'post',
            ],
        ];
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
        foreach ($post_ids as $post_id) {
            $post = self::post_object($post_id);
            if ($post !== null && self::is_indexable_post($post)) {
                self::index_post($post);
                $processed++;
                continue;
            }

            self::tombstone_post($post_id);
        }

        return [
            'processed' => $processed,
            'post_ids' => $post_ids,
        ];
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
     * @return array<int,array{post_id:int,title:string,score:float}>
     */
    private static function sandbox_search_results(string $query): array
    {
        $results = [];
        foreach (self::search($query, ['lang' => 'en', 'limit' => 10]) as $row) {
            $post_id = (int) $row['doc_id'];
            $results[] = [
                'post_id' => $post_id,
                'title' => self::post_title($post_id),
                'score' => (float) $row['score'],
            ];
        }

        return $results;
    }

    /**
     * Render the wp-admin sandbox surface.
     *
     * @param array<int,array{0:string,1:string}> $messages
     * @param int[] $demo_post_ids
     * @param array<int,array{post_id:int,title:string,score:float}> $results
     */
    private static function render_admin_sandbox_page(array $messages, array $demo_post_ids, string $query, bool $search_submitted, array $results): void
    {
        echo '<div class="wrap">';
        echo '<h1>Pure PHP FTS Sandbox</h1>';

        foreach ($messages as $message) {
            self::render_sandbox_notice($message[0], $message[1]);
        }

        echo '<h2>Demo content</h2>';
        echo '<p>';
        echo $demo_post_ids === []
            ? 'No demo posts have been created yet.'
            : 'Demo post IDs: ' . self::esc_html(implode(', ', $demo_post_ids)) . '.';
        echo '</p>';

        echo '<form method="post" action="' . self::esc_url(self::admin_page_url()) . '" style="display:inline-block;margin-right:8px;">';
        self::render_sandbox_nonce_field();
        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_ACTION_FIELD) . '" value="refresh_demo">';
        echo '<button type="submit" class="button">Create or refresh demo posts</button>';
        echo '</form>';

        echo '<form method="post" action="' . self::esc_url(self::admin_page_url()) . '" style="display:inline-block;">';
        self::render_sandbox_nonce_field();
        echo '<input type="hidden" name="' . self::esc_attr(self::ADMIN_ACTION_FIELD) . '" value="index_demo">';
        echo '<button type="submit" class="button button-primary">Build demo index</button>';
        echo '</form>';

        echo '<h2>Search</h2>';
        echo '<p>Suggested English stemming query: <code>run</code></p>';
        echo '<form method="get" action="' . self::esc_url(self::admin_tools_url()) . '">';
        echo '<input type="hidden" name="page" value="' . self::esc_attr(self::ADMIN_PAGE_SLUG) . '">';
        echo '<label for="wp-fts-sandbox-query">Query</label> ';
        echo '<input id="wp-fts-sandbox-query" type="search" class="regular-text" name="' . self::esc_attr(self::ADMIN_QUERY_FIELD) . '" value="' . self::esc_attr($query) . '"> ';
        echo '<button type="submit" class="button button-primary" name="' . self::esc_attr(self::ADMIN_SEARCH_FIELD) . '" value="1">Search</button>';
        echo '</form>';

        if ($search_submitted) {
            self::render_sandbox_results($results);
        }

        echo '</div>';
    }

    /**
     * @param array<int,array{post_id:int,title:string,score:float}> $results
     */
    private static function render_sandbox_results(array $results): void
    {
        echo '<h2>Results</h2>';
        if ($results === []) {
            echo '<p>No results matched the current index.</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th scope="col">Post ID</th><th scope="col">Title</th><th scope="col">Score</th></tr></thead>';
        echo '<tbody>';
        foreach ($results as $row) {
            echo '<tr>';
            echo '<td>' . self::esc_html((string) $row['post_id']) . '</td>';
            echo '<td>' . self::esc_html($row['title']) . '</td>';
            echo '<td>' . self::esc_html(number_format($row['score'], 6, '.', '')) . '</td>';
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

        $searcher = new WP_FTS_Searcher(self::storage(false), new WP_FTS_Analyzer());
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
     */
    private static function index_post(object $post): void
    {
        self::maybe_upgrade_schema();
        (new WP_FTS_Indexer(self::storage(false), new WP_FTS_Analyzer()))->index_post($post);
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
