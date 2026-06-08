<?php
declare(strict_types=1);

final class Language_FTS_Playground_Plugin
{
    private const SCHEMA_OPTION = 'language_fts_playground_schema_version';
    private const ANALYZER_OPTION = 'language_fts_playground_analyzer_version';
    private const QUEUE_OPTION = 'language_fts_playground_index_queue';
    private const STATUS_OPTION = 'language_fts_playground_index_status';
    private const REBUILD_REQUIRED_OPTION = 'language_fts_playground_rebuild_required';
    private const CRON_HOOK = 'language_fts_playground_process_queue';
    private const DEFAULT_PUBLIC_POST_TYPES = ['post', 'page'];

    private static ?Language_FTS_Playground_Storage_Interface $storage = null;
    private static ?Language_FTS_Playground_Analyzer $analyzer = null;

    public static function register_hooks(): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        add_action('plugins_loaded', [self::class, 'ensure_schema']);
        add_action(self::CRON_HOOK, [self::class, 'process_index_queue']);
        add_action('admin_menu', [self::class, 'register_admin_page']);
        add_action('save_post', [self::class, 'index_saved_post'], 20, 3);
        add_action('before_delete_post', [self::class, 'delete_post']);
        add_action('transition_post_status', [self::class, 'transition_post_status'], 20, 3);
        add_action('admin_post_language_fts_playground_seed', [self::class, 'handle_seed_action']);
        add_action('admin_post_language_fts_playground_rebuild', [self::class, 'handle_rebuild_action']);
        add_action('admin_post_language_fts_playground_process_queue', [self::class, 'handle_process_queue_action']);
        add_action('admin_post_language_fts_playground_clear_index', [self::class, 'handle_clear_action']);
    }

    public static function activate(): void
    {
        self::ensure_schema();
        Language_FTS_Playground_Demo::seed_posts();
        self::rebuild_index();
    }

    public static function deactivate(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }

        self::$storage = null;
        self::$analyzer = null;
    }

    public static function ensure_schema(): void
    {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }

        $stored_schema = (string) get_option(self::SCHEMA_OPTION, '');
        $stored_analyzer = (string) get_option(self::ANALYZER_OPTION, '');
        $schema_changed = $stored_schema !== self::schema_version();
        $analyzer_changed = $stored_analyzer !== self::analyzer_version();

        if (!$schema_changed && !$analyzer_changed) {
            return;
        }

        try {
            self::storage()->install();
            self::update_option_value(self::SCHEMA_OPTION, self::schema_version());
            self::update_option_value(self::ANALYZER_OPTION, self::analyzer_version());
            self::set_rebuild_required(true);
            self::record_status(__('Index schema or analyzer changed; rebuild required.', 'language-fts-playground'));
        } catch (Throwable $throwable) {
            self::record_error(__('Could not install or upgrade the Language FTS index schema.', 'language-fts-playground'), $throwable);
        }
    }

    public static function storage(): Language_FTS_Playground_Storage_Interface
    {
        if (self::$storage === null) {
            global $wpdb;
            if (!is_object($wpdb)) {
                throw new RuntimeException('Language FTS Playground requires the WordPress database object.');
            }
            self::$storage = new Language_FTS_Playground_Wpdb_Storage($wpdb);
        }

        return self::$storage;
    }

    public static function analyzer(): Language_FTS_Playground_Analyzer
    {
        if (self::$analyzer === null) {
            self::$analyzer = new Language_FTS_Playground_Analyzer();
        }

        return self::$analyzer;
    }

    public static function indexer(): Language_FTS_Playground_Indexer
    {
        return new Language_FTS_Playground_Indexer(self::storage(), self::analyzer());
    }

    public static function searcher(): Language_FTS_Playground_Searcher
    {
        return new Language_FTS_Playground_Searcher(self::storage(), self::analyzer());
    }

    public static function rebuild_index(): void
    {
        try {
            self::queue_rebuild(true);
            self::process_index_queue(self::batch_size());
        } catch (Throwable $throwable) {
            self::record_error(__('Could not queue an index rebuild.', 'language-fts-playground'), $throwable);
        }
    }

    public static function queue_rebuild(bool $clear_index = true): int
    {
        if (!function_exists('get_posts')) {
            return 0;
        }

        if ($clear_index) {
            self::storage()->clear();
        }

        self::write_queue([]);
        $post_ids = self::public_published_post_ids();
        self::enqueue_posts($post_ids);
        self::set_rebuild_required(false);
        self::record_status(
            sprintf(
                /* translators: %d: number of posts queued for indexing */
                __('Rebuild queued %d published public posts.', 'language-fts-playground'),
                count($post_ids)
            ),
            null,
            ['queued_count' => count($post_ids)]
        );

        return count($post_ids);
    }

    public static function index_saved_post(int $post_id, object $post, bool $update): void
    {
        unset($update);

        if (self::is_revision_or_autosave($post_id)) {
            return;
        }

        try {
            if (!self::is_indexable_post($post)) {
                self::dequeue_posts([$post_id]);
                self::storage()->delete_document($post_id);
                self::record_status(__('Removed a non-public post from the Language FTS index.', 'language-fts-playground'));
                return;
            }

            self::enqueue_posts([$post_id]);
            self::record_status(__('Queued a changed post for Language FTS indexing.', 'language-fts-playground'));
        } catch (Throwable $throwable) {
            self::record_error(__('Could not update the Language FTS queue for a saved post.', 'language-fts-playground'), $throwable);
        }
    }

    public static function transition_post_status(string $new_status, string $old_status, object $post): void
    {
        unset($old_status);
        $post_id = self::post_id($post);
        if ($post_id <= 0 || self::is_revision_or_autosave($post_id)) {
            return;
        }

        try {
            if ($new_status === 'publish' && self::is_indexable_post($post)) {
                self::enqueue_posts([$post_id]);
                self::record_status(__('Queued a published post for Language FTS indexing.', 'language-fts-playground'));
                return;
            }

            self::dequeue_posts([$post_id]);
            self::storage()->delete_document($post_id);
            self::record_status(__('Removed a non-public post from the Language FTS index.', 'language-fts-playground'));
        } catch (Throwable $throwable) {
            self::record_error(__('Could not update the Language FTS index for a post status change.', 'language-fts-playground'), $throwable);
        }
    }

    public static function delete_post(int $post_id): void
    {
        try {
            self::dequeue_posts([$post_id]);
            self::storage()->delete_document($post_id);
            self::record_status(__('Removed a deleted post from the Language FTS index.', 'language-fts-playground'));
        } catch (Throwable $throwable) {
            self::record_error(__('Could not remove a deleted post from the Language FTS index.', 'language-fts-playground'), $throwable);
        }
    }

    /**
     * @return array{processed:int,indexed:int,deleted:int,failed:int,remaining:int,last_error:string|null}
     */
    public static function process_index_queue(int $limit = 0): array
    {
        $limit = $limit > 0 ? $limit : self::batch_size();
        $queue = self::read_queue();
        $batch = array_slice($queue, 0, max(1, $limit), true);
        $completed = [];
        $result = [
            'processed' => 0,
            'indexed' => 0,
            'deleted' => 0,
            'failed' => 0,
            'remaining' => count($queue),
            'last_error' => null,
        ];

        foreach ($batch as $post_id => $token) {
            try {
                $post = function_exists('get_post') ? get_post($post_id) : null;
                if (!is_object($post) || !self::is_indexable_post($post)) {
                    self::storage()->delete_document((int) $post_id);
                    $result['deleted']++;
                    $result['processed']++;
                    $completed[(int) $post_id] = (string) $token;
                    continue;
                }

                self::indexer()->index_post($post);
                $result['indexed']++;
                $result['processed']++;
                $completed[(int) $post_id] = (string) $token;
            } catch (Throwable $throwable) {
                $result['failed']++;
                $result['last_error'] = $throwable->getMessage();
                self::record_error(__('Could not process a queued Language FTS post.', 'language-fts-playground'), $throwable);
            }
        }

        if ($completed !== []) {
            self::complete_queue_items($completed);
        }

        $result['remaining'] = self::queued_count();
        if ($result['remaining'] > 0) {
            self::schedule_queue_processing();
        }

        if ($result['failed'] === 0) {
            self::record_status(
                sprintf(
                    /* translators: %d: number of queue items processed */
                    __('Processed %d Language FTS queue items.', 'language-fts-playground'),
                    $result['processed']
                ),
                null,
                [
                    'last_result' => $result,
                    'queued_count' => $result['remaining'],
                ]
            );
        }

        return $result;
    }

    public static function clear_index(): void
    {
        self::storage()->clear();
        self::write_queue([]);
        self::set_rebuild_required(false);
        self::record_status(__('Language FTS index and queue were cleared.', 'language-fts-playground'), null, ['queued_count' => 0]);
    }

    /**
     * @param int[] $post_ids
     */
    public static function enqueue_posts(array $post_ids): void
    {
        $post_ids = self::normalize_post_ids($post_ids);
        if ($post_ids === []) {
            return;
        }

        $queued_at = sprintf('%.6f', microtime(true));
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $queue = self::read_queue();
            foreach ($post_ids as $post_id) {
                $queue[$post_id] = $queued_at;
            }

            self::write_queue($queue);
            $confirmed = self::read_queue();
            if (self::queue_contains_ids($confirmed, $post_ids)) {
                self::schedule_queue_processing();
                return;
            }
        }

        self::record_status(__('Could not confirm every Language FTS queue item after writing the option-backed queue.', 'language-fts-playground'));
        self::schedule_queue_processing();
    }

    public static function queued_count(): int
    {
        return count(self::read_queue());
    }

    /**
     * @return array<string,mixed>
     */
    public static function index_status(): array
    {
        if (!function_exists('get_option')) {
            return [];
        }

        $status = get_option(self::STATUS_OPTION, []);

        return is_array($status) ? $status : [];
    }

    public static function register_admin_page(): void
    {
        add_management_page(
            'Language FTS Playground',
            'Language FTS',
            'manage_options',
            'language-fts-playground',
            [self::class, 'render_admin_page']
        );
    }

    public static function handle_seed_action(): void
    {
        self::verify_admin_action('language_fts_playground_seed');
        Language_FTS_Playground_Demo::seed_posts();
        self::rebuild_index();
        self::redirect_admin_page('seeded');
    }

    public static function handle_rebuild_action(): void
    {
        self::verify_admin_action('language_fts_playground_rebuild');
        self::rebuild_index();
        self::redirect_admin_page('rebuilt');
    }

    public static function handle_process_queue_action(): void
    {
        self::verify_admin_action('language_fts_playground_process_queue');
        self::process_index_queue(self::batch_size());
        self::redirect_admin_page('processed');
    }

    public static function handle_clear_action(): void
    {
        self::verify_admin_action('language_fts_playground_clear_index');
        try {
            self::clear_index();
        } catch (Throwable $throwable) {
            self::record_error(__('Could not clear the Language FTS index.', 'language-fts-playground'), $throwable);
            self::redirect_admin_page('error');
        }
        self::redirect_admin_page('cleared');
    }

    public static function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'language-fts-playground'));
        }

        $query = isset($_GET['lft_query']) ? sanitize_text_field(wp_unslash((string) $_GET['lft_query'])) : 'orchard';
        $language = self::analyzer()->canonical_language(
            isset($_GET['lft_language']) ? sanitize_text_field(wp_unslash((string) $_GET['lft_language'])) : 'en'
        );
        $runtime_errors = [];
        $results = [];
        $documents = [];

        if ($query !== '') {
            try {
                $results = self::searcher()->search($query, $language, 10);
            } catch (Throwable $throwable) {
                $runtime_errors[] = $throwable->getMessage();
                self::record_error(__('Could not read the Language FTS search index.', 'language-fts-playground'), $throwable);
            }
        }

        try {
            $documents = self::storage()->all_documents();
        } catch (Throwable $throwable) {
            $runtime_errors[] = $throwable->getMessage();
            self::record_error(__('Could not read indexed Language FTS documents.', 'language-fts-playground'), $throwable);
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Language FTS Playground', 'language-fts-playground') . '</h1>';
        self::render_notice($runtime_errors);
        self::render_actions();
        self::render_index_status($documents, self::index_status());
        self::render_search_form($query, $language);
        self::render_sample_searches();
        self::render_results($results, $query, $language);
        self::render_documents($documents);
        echo '</div>';
    }

    /**
     * @param string[] $runtime_errors
     */
    private static function render_notice(array $runtime_errors = []): void
    {
        $status = isset($_GET['lft_status']) ? sanitize_key((string) $_GET['lft_status']) : '';
        $messages = [
            'seeded' => __('Demo posts were seeded and queued for indexing.', 'language-fts-playground'),
            'rebuilt' => __('The index rebuild was queued and one batch was processed.', 'language-fts-playground'),
            'processed' => __('One index queue batch was processed.', 'language-fts-playground'),
            'cleared' => __('The Language FTS index and queue were cleared.', 'language-fts-playground'),
            'error' => __('The last Language FTS operation reported an error. Review the status panel below.', 'language-fts-playground'),
        ];

        if (isset($messages[$status])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$status]) . '</p></div>';
        }

        if (self::rebuild_required()) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('The analyzer or schema changed. Rebuild the Language FTS index to refresh existing posts.', 'language-fts-playground') . '</p></div>';
        }

        foreach (array_unique(array_filter($runtime_errors)) as $error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }
    }

    private static function render_actions(): void
    {
        $seed_url = wp_nonce_url(
            admin_url('admin-post.php?action=language_fts_playground_seed'),
            'language_fts_playground_seed'
        );
        $rebuild_url = wp_nonce_url(
            admin_url('admin-post.php?action=language_fts_playground_rebuild'),
            'language_fts_playground_rebuild'
        );
        $process_url = wp_nonce_url(
            admin_url('admin-post.php?action=language_fts_playground_process_queue'),
            'language_fts_playground_process_queue'
        );
        $clear_url = wp_nonce_url(
            admin_url('admin-post.php?action=language_fts_playground_clear_index'),
            'language_fts_playground_clear_index'
        );

        echo '<p>';
        echo '<a class="button button-primary" href="' . esc_url($seed_url) . '">' . esc_html__('Seed demo posts', 'language-fts-playground') . '</a> ';
        echo '<a class="button" href="' . esc_url($rebuild_url) . '">' . esc_html__('Rebuild index', 'language-fts-playground') . '</a> ';
        echo '<a class="button" href="' . esc_url($process_url) . '">' . esc_html__('Process queue', 'language-fts-playground') . '</a> ';
        echo '<a class="button" href="' . esc_url($clear_url) . '">' . esc_html__('Clear index', 'language-fts-playground') . '</a>';
        echo '</p>';
    }

    /**
     * @param array<int,array{post_id:int,language:string,title:string,status:string,document_length:int,updated_at:string}> $documents
     * @param array<string,mixed> $status
     */
    private static function render_index_status(array $documents, array $status): void
    {
        $language_counts = self::document_counts_from_documents($documents);
        $last_status = isset($status['last_status']) && is_scalar($status['last_status'])
            ? (string) $status['last_status']
            : __('No queue activity has been recorded yet.', 'language-fts-playground');
        $last_error = isset($status['last_error']) && is_scalar($status['last_error'])
            ? (string) $status['last_error']
            : '';

        echo '<h2>' . esc_html__('Index status', 'language-fts-playground') . '</h2>';
        echo '<table class="widefat striped" style="max-width:720px;"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Indexed documents', 'language-fts-playground') . '</th><td>' . esc_html((string) count($documents)) . ' <span class="description">(' . esc_html($language_counts) . ')</span></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Queued posts', 'language-fts-playground') . '</th><td>' . esc_html((string) self::queued_count()) . '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Rebuild required', 'language-fts-playground') . '</th><td>' . esc_html(self::rebuild_required() ? __('Yes', 'language-fts-playground') : __('No', 'language-fts-playground')) . '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Last status', 'language-fts-playground') . '</th><td>' . esc_html($last_status) . '</td></tr>';
        if ($last_error !== '') {
            echo '<tr><th scope="row">' . esc_html__('Last error', 'language-fts-playground') . '</th><td>' . esc_html($last_error) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_search_form(string $query, string $language): void
    {
        echo '<form method="get" action="' . esc_url(admin_url('tools.php')) . '" style="margin:1em 0;">';
        echo '<input type="hidden" name="page" value="language-fts-playground" />';
        echo '<label for="lft-query" class="screen-reader-text">' . esc_html__('Query', 'language-fts-playground') . '</label>';
        echo '<input id="lft-query" type="search" name="lft_query" value="' . esc_attr($query) . '" class="regular-text" />';
        echo ' <label for="lft-language" class="screen-reader-text">' . esc_html__('Language', 'language-fts-playground') . '</label>';
        echo '<select id="lft-language" name="lft_language">';
        foreach (['en' => 'English', 'pl' => 'Polish', 'de' => 'German'] as $code => $label) {
            echo '<option value="' . esc_attr($code) . '"' . selected($language, $code, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> ';
        submit_button(__('Search', 'language-fts-playground'), 'secondary', '', false);
        echo '</form>';
    }

    private static function render_sample_searches(): void
    {
        echo '<p>';
        foreach (Language_FTS_Playground_Demo::sample_searches() as $sample) {
            $url = add_query_arg(
                [
                    'page' => 'language-fts-playground',
                    'lft_query' => $sample['query'],
                    'lft_language' => $sample['language'],
                ],
                admin_url('tools.php')
            );
            echo '<a class="button" style="margin:0 0.35em 0.35em 0;" href="' . esc_url($url) . '">' . esc_html($sample['label']) . '</a>';
        }
        echo '</p>';
    }

    /**
     * @param array<int,array{post_id:int,score:float,matched_terms:string[]}> $results
     */
    private static function render_results(array $results, string $query, string $language): void
    {
        echo '<h2>' . esc_html__('Search results', 'language-fts-playground') . '</h2>';
        if ($query === '') {
            echo '<p>' . esc_html__('Enter a query to search.', 'language-fts-playground') . '</p>';
            return;
        }

        if ($results === []) {
            echo '<p>' . esc_html(sprintf(__('No matches for "%1$s" in %2$s.', 'language-fts-playground'), $query, $language)) . '</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Post', 'language-fts-playground') . '</th>';
        echo '<th>' . esc_html__('Score', 'language-fts-playground') . '</th>';
        echo '<th>' . esc_html__('Matched terms', 'language-fts-playground') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($results as $result) {
            $post = get_post($result['post_id']);
            $title = $post ? get_the_title($post) : '#' . $result['post_id'];
            $edit_link = get_edit_post_link($result['post_id']);
            echo '<tr>';
            echo '<td>';
            if ($edit_link) {
                echo '<a href="' . esc_url($edit_link) . '">' . esc_html($title) . '</a>';
            } else {
                echo esc_html($title);
            }
            echo '</td>';
            echo '<td>' . esc_html(number_format_i18n($result['score'], 6)) . '</td>';
            echo '<td>' . esc_html(implode(', ', $result['matched_terms'])) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * @param array<int,array{post_id:int,language:string,title:string,status:string,document_length:int,updated_at:string}> $documents
     */
    private static function render_documents(array $documents): void
    {
        echo '<h2>' . esc_html__('Indexed posts', 'language-fts-playground') . '</h2>';
        if ($documents === []) {
            echo '<p>' . esc_html__('No indexed posts yet.', 'language-fts-playground') . '</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Post', 'language-fts-playground') . '</th>';
        echo '<th>' . esc_html__('Language', 'language-fts-playground') . '</th>';
        echo '<th>' . esc_html__('Terms', 'language-fts-playground') . '</th>';
        echo '<th>' . esc_html__('Updated', 'language-fts-playground') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($documents as $document) {
            $edit_link = get_edit_post_link($document['post_id']);
            echo '<tr>';
            echo '<td>';
            if ($edit_link) {
                echo '<a href="' . esc_url($edit_link) . '">' . esc_html($document['title']) . '</a>';
            } else {
                echo esc_html($document['title']);
            }
            echo '</td>';
            echo '<td><code>' . esc_html($document['language']) . '</code></td>';
            echo '<td>' . esc_html((string) $document['document_length']) . '</td>';
            echo '<td>' . esc_html($document['updated_at']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * @return int[]
     */
    private static function public_published_post_ids(): array
    {
        $posts = get_posts([
            'post_type' => self::public_post_types(),
            'post_status' => 'publish',
            'numberposts' => -1,
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
        ]);

        $post_ids = [];
        foreach (is_array($posts) ? $posts : [] as $post_or_id) {
            $post_id = is_object($post_or_id) ? self::post_id($post_or_id) : (int) $post_or_id;
            if ($post_id <= 0) {
                continue;
            }

            $post = function_exists('get_post') ? get_post($post_id) : $post_or_id;
            if (is_object($post) && !self::is_indexable_post($post)) {
                continue;
            }

            $post_ids[$post_id] = $post_id;
        }

        return array_values($post_ids);
    }

    /**
     * @return string[]
     */
    private static function public_post_types(): array
    {
        if (function_exists('get_post_types')) {
            $post_types = get_post_types(['public' => true], 'names');
            if (is_array($post_types) && $post_types !== []) {
                return array_values(array_map('strval', $post_types));
            }
        }

        return self::DEFAULT_PUBLIC_POST_TYPES;
    }

    private static function is_indexable_post(object $post): bool
    {
        $post_id = self::post_id($post);
        if ($post_id <= 0 || self::is_revision_or_autosave($post_id)) {
            return false;
        }

        if (self::post_string($post, 'post_status') !== 'publish') {
            return false;
        }

        if (self::post_string($post, 'post_password') !== '') {
            return false;
        }

        $post_type = self::post_string($post, 'post_type') ?: (function_exists('get_post_type') ? (string) get_post_type($post) : 'post');
        if ($post_type === '') {
            $post_type = 'post';
        }

        if (function_exists('get_post_type_object')) {
            $post_type_object = get_post_type_object($post_type);
            if (is_object($post_type_object)) {
                return !empty($post_type_object->public) || !empty($post_type_object->publicly_queryable);
            }
        }

        return in_array($post_type, self::DEFAULT_PUBLIC_POST_TYPES, true);
    }

    private static function is_revision_or_autosave(int $post_id): bool
    {
        if (function_exists('wp_is_post_revision') && wp_is_post_revision($post_id)) {
            return true;
        }

        return function_exists('wp_is_post_autosave') && (bool) wp_is_post_autosave($post_id);
    }

    private static function post_id(object $post): int
    {
        return max(0, (int) ($post->ID ?? $post->id ?? $post->post_id ?? 0));
    }

    private static function post_string(object $post, string $property): string
    {
        return isset($post->{$property}) && is_scalar($post->{$property}) ? (string) $post->{$property} : '';
    }

    /**
     * @return array<int,string>
     */
    private static function read_queue(): array
    {
        if (!function_exists('get_option')) {
            return [];
        }

        $raw_queue = get_option(self::QUEUE_OPTION, []);
        if (!is_array($raw_queue)) {
            return [];
        }

        $queue = [];
        foreach ($raw_queue as $key => $value) {
            $post_id = ((is_int($key) || ctype_digit((string) $key)) && (int) $key > 0)
                ? (int) $key
                : (int) $value;
            if ($post_id <= 0) {
                continue;
            }

            $token = is_scalar($value) ? (string) $value : '1';
            $queue[$post_id] = $token !== '' ? $token : '1';
        }

        ksort($queue, SORT_NUMERIC);

        return $queue;
    }

    /**
     * @param array<int,string> $queue
     */
    private static function write_queue(array $queue): void
    {
        $normalized = [];
        foreach ($queue as $post_id => $token) {
            $post_id = (int) $post_id;
            if ($post_id > 0) {
                $normalized[$post_id] = (string) $token;
            }
        }
        ksort($normalized, SORT_NUMERIC);
        self::update_option_value(self::QUEUE_OPTION, $normalized);
    }

    /**
     * @param int[] $post_ids
     */
    private static function dequeue_posts(array $post_ids): void
    {
        $post_ids = self::normalize_post_ids($post_ids);
        if ($post_ids === []) {
            return;
        }

        $queue = self::read_queue();
        foreach ($post_ids as $post_id) {
            unset($queue[$post_id]);
        }
        self::write_queue($queue);
    }

    /**
     * @param array<int,string> $completed
     */
    private static function complete_queue_items(array $completed): void
    {
        $queue = self::read_queue();
        foreach ($completed as $post_id => $token) {
            if (isset($queue[$post_id]) && (string) $queue[$post_id] === (string) $token) {
                unset($queue[$post_id]);
            }
        }
        self::write_queue($queue);
    }

    /**
     * @param int[] $post_ids
     * @return int[]
     */
    private static function normalize_post_ids(array $post_ids): array
    {
        $normalized = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0) {
                $normalized[$post_id] = $post_id;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param array<int,string> $queue
     * @param int[] $post_ids
     */
    private static function queue_contains_ids(array $queue, array $post_ids): bool
    {
        foreach ($post_ids as $post_id) {
            if (!array_key_exists((int) $post_id, $queue)) {
                return false;
            }
        }

        return true;
    }

    private static function schedule_queue_processing(): void
    {
        if (!function_exists('wp_schedule_single_event')) {
            return;
        }

        if (function_exists('wp_next_scheduled') && wp_next_scheduled(self::CRON_HOOK) !== false) {
            return;
        }

        wp_schedule_single_event(time() + 60, self::CRON_HOOK);
    }

    private static function set_rebuild_required(bool $required): void
    {
        self::update_option_value(self::REBUILD_REQUIRED_OPTION, $required);
    }

    private static function rebuild_required(): bool
    {
        return function_exists('get_option') && (bool) get_option(self::REBUILD_REQUIRED_OPTION, false);
    }

    /**
     * @param array<int,array{post_id:int,language:string,title:string,status:string,document_length:int,updated_at:string}> $documents
     */
    private static function document_counts_from_documents(array $documents): string
    {
        $counts = ['en' => 0, 'pl' => 0, 'de' => 0];
        foreach ($documents as $document) {
            $language = (string) ($document['language'] ?? '');
            if (!array_key_exists($language, $counts)) {
                $counts[$language] = 0;
            }
            $counts[$language]++;
        }

        $parts = [];
        foreach ($counts as $language => $count) {
            $parts[] = $language . ': ' . $count;
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string,mixed> $extra
     */
    private static function record_status(string $message, string|null $last_error = null, array $extra = []): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        $status = self::index_status();
        $status['last_status'] = $message;
        $status['updated_at'] = gmdate('Y-m-d H:i:s');
        $status['queued_count'] = self::queued_count();
        if ($last_error !== null) {
            $status['last_error'] = $last_error;
        } elseif (!array_key_exists('last_error', $status)) {
            $status['last_error'] = '';
        }

        foreach ($extra as $key => $value) {
            $status[(string) $key] = $value;
        }

        self::update_option_value(self::STATUS_OPTION, $status);
    }

    private static function record_error(string $message, Throwable $throwable): void
    {
        $details = trim($message . ' ' . $throwable->getMessage());
        self::record_status($message, $details);
    }

    private static function verify_admin_action(string $nonce_action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to run this action.', 'language-fts-playground'));
        }
        check_admin_referer($nonce_action);
    }

    private static function redirect_admin_page(string $status): void
    {
        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => 'language-fts-playground',
                    'lft_status' => $status,
                ],
                admin_url('tools.php')
            )
        );
        exit;
    }

    private static function update_option_value(string $name, mixed $value): void
    {
        if (function_exists('update_option')) {
            update_option($name, $value, false);
        }
    }

    private static function schema_version(): string
    {
        return defined('LANGUAGE_FTS_PLAYGROUND_SCHEMA_VERSION') ? (string) LANGUAGE_FTS_PLAYGROUND_SCHEMA_VERSION : '1';
    }

    private static function analyzer_version(): string
    {
        return defined('LANGUAGE_FTS_PLAYGROUND_ANALYZER_VERSION') ? (string) LANGUAGE_FTS_PLAYGROUND_ANALYZER_VERSION : '1';
    }

    private static function batch_size(): int
    {
        $batch_size = defined('LANGUAGE_FTS_PLAYGROUND_QUEUE_BATCH_SIZE') ? (int) LANGUAGE_FTS_PLAYGROUND_QUEUE_BATCH_SIZE : 25;

        return max(1, $batch_size);
    }
}
