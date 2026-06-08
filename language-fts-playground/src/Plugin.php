<?php
declare(strict_types=1);

final class Language_FTS_Playground_Plugin
{
    private static ?Language_FTS_Playground_Wpdb_Storage $storage = null;
    private static ?Language_FTS_Playground_Analyzer $analyzer = null;

    public static function register_hooks(): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        add_action('plugins_loaded', [self::class, 'ensure_schema']);
        add_action('admin_menu', [self::class, 'register_admin_page']);
        add_action('save_post', [self::class, 'index_saved_post'], 20, 3);
        add_action('before_delete_post', [self::class, 'delete_post']);
        add_action('transition_post_status', [self::class, 'transition_post_status'], 20, 3);
        add_action('admin_post_language_fts_playground_seed', [self::class, 'handle_seed_action']);
        add_action('admin_post_language_fts_playground_rebuild', [self::class, 'handle_rebuild_action']);
    }

    public static function activate(): void
    {
        self::storage()->install();
        Language_FTS_Playground_Demo::seed_posts();
        self::rebuild_index();

        if (function_exists('update_option')) {
            update_option('language_fts_playground_schema_version', LANGUAGE_FTS_PLAYGROUND_VERSION);
        }
    }

    public static function deactivate(): void
    {
        self::$storage = null;
        self::$analyzer = null;
    }

    public static function ensure_schema(): void
    {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }

        if ((string) get_option('language_fts_playground_schema_version', '') !== LANGUAGE_FTS_PLAYGROUND_VERSION) {
            self::storage()->install();
            update_option('language_fts_playground_schema_version', LANGUAGE_FTS_PLAYGROUND_VERSION);
            self::rebuild_index();
        }
    }

    public static function storage(): Language_FTS_Playground_Wpdb_Storage
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
        if (!function_exists('get_posts')) {
            return;
        }

        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        self::indexer()->rebuild(is_array($posts) ? $posts : []);
    }

    public static function index_saved_post(int $post_id, object $post, bool $update): void
    {
        unset($update);
        if (function_exists('wp_is_post_revision') && wp_is_post_revision($post_id)) {
            return;
        }
        if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($post_id)) {
            return;
        }

        self::indexer()->index_post($post);
    }

    public static function transition_post_status(string $new_status, string $old_status, object $post): void
    {
        unset($old_status);
        if ($new_status === 'publish') {
            self::indexer()->index_post($post);
            return;
        }

        $post_id = isset($post->ID) ? (int) $post->ID : 0;
        if ($post_id > 0) {
            self::storage()->delete_document($post_id);
        }
    }

    public static function delete_post(int $post_id): void
    {
        self::storage()->delete_document($post_id);
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

    public static function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'language-fts-playground'));
        }

        $query = isset($_GET['lft_query']) ? sanitize_text_field(wp_unslash((string) $_GET['lft_query'])) : 'orchard';
        $language = self::analyzer()->canonical_language(
            isset($_GET['lft_language']) ? sanitize_text_field(wp_unslash((string) $_GET['lft_language'])) : 'en'
        );
        $results = $query !== '' ? self::searcher()->search($query, $language, 10) : [];
        $documents = self::storage()->all_documents();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Language FTS Playground', 'language-fts-playground') . '</h1>';
        self::render_notice();
        self::render_actions();
        self::render_search_form($query, $language);
        self::render_sample_searches();
        self::render_results($results, $query, $language);
        self::render_documents($documents);
        echo '</div>';
    }

    private static function render_notice(): void
    {
        $status = isset($_GET['lft_status']) ? sanitize_key((string) $_GET['lft_status']) : '';
        if ($status === '') {
            return;
        }

        $message = $status === 'seeded'
            ? __('Demo posts were seeded and indexed.', 'language-fts-playground')
            : __('The index was rebuilt.', 'language-fts-playground');

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
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

        echo '<p>';
        echo '<a class="button button-primary" href="' . esc_url($seed_url) . '">' . esc_html__('Seed demo posts', 'language-fts-playground') . '</a> ';
        echo '<a class="button" href="' . esc_url($rebuild_url) . '">' . esc_html__('Rebuild index', 'language-fts-playground') . '</a>';
        echo '</p>';
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
}
