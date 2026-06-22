<?php
declare(strict_types=1);

/**
 * Optional live WordPress provider-compatibility smoke.
 *
 * The default shell entry point is skip-first and performs no writes unless a
 * disposable WordPress root is configured and explicitly approved.
 */

const WP_FTS_PROVIDER_COMPATIBILITY_REPORT_SCHEMA = 'wp-fts-provider-compatibility-smoke-v1';
const WP_FTS_PROVIDER_COMPATIBILITY_ALLOW_ENV = 'WP_FTS_PROVIDER_COMPATIBILITY_ALLOW';
const WP_FTS_PROVIDER_COMPATIBILITY_CONFIRM_PATH_ENV = 'WP_FTS_PROVIDER_COMPATIBILITY_CONFIRM_PATH';
const WP_FTS_PROVIDER_COMPATIBILITY_INSIDE_ENV = 'WP_FTS_PROVIDER_COMPATIBILITY_INSIDE';
const WP_FTS_PROVIDER_COMPATIBILITY_MARKER_FILE = '.wp-fts-provider-compatibility-smoke';
const WP_FTS_PROVIDER_COMPATIBILITY_WP_CLI_ENV = 'WP_FTS_WP_CLI';
const WP_FTS_PROVIDER_COMPATIBILITY_WP_PATH_ENV = 'WP_FTS_WP_PATH';

try {
    if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
        exit(wp_fts_provider_compatibility_wordpress_main());
    }
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    exit(1);
}

function wp_fts_provider_compatibility_wordpress_main(): int
{
    if (getenv(WP_FTS_PROVIDER_COMPATIBILITY_INSIDE_ENV) === '1') {
        wp_fts_provider_compatibility_wordpress_inside();
        return 0;
    }

    return wp_fts_provider_compatibility_wordpress_shell();
}

function wp_fts_provider_compatibility_wordpress_shell(): int
{
    if (!function_exists('proc_open')) {
        return wp_fts_provider_compatibility_wordpress_skip('proc_open() is unavailable; cannot launch WP-CLI.');
    }

    $wpPath = trim((string) getenv(WP_FTS_PROVIDER_COMPATIBILITY_WP_PATH_ENV));
    if ($wpPath === '') {
        return wp_fts_provider_compatibility_wordpress_skip('Set WP_FTS_WP_PATH to an installed disposable WordPress root to run provider compatibility smoke checks.');
    }

    if (!is_dir($wpPath)) {
        return wp_fts_provider_compatibility_wordpress_skip("WP_FTS_WP_PATH does not exist or is not a directory: {$wpPath}");
    }

    if (getenv(WP_FTS_PROVIDER_COMPATIBILITY_ALLOW_ENV) !== '1') {
        return wp_fts_provider_compatibility_wordpress_skip('Set WP_FTS_PROVIDER_COMPATIBILITY_ALLOW=1 only for a disposable, non-production WordPress site.');
    }

    if (!wp_fts_provider_compatibility_wordpress_disposable_confirmed($wpPath)) {
        return wp_fts_provider_compatibility_wordpress_skip(
            'Refusing to write: create ' . WP_FTS_PROVIDER_COMPATIBILITY_MARKER_FILE
            . ' in WP_FTS_WP_PATH or set WP_FTS_PROVIDER_COMPATIBILITY_CONFIRM_PATH to that exact root.'
        );
    }

    $baseCommand = wp_fts_provider_compatibility_wordpress_wp_cli_base_command($wpPath);
    $installed = wp_fts_provider_compatibility_wordpress_process(array_merge($baseCommand, ['core', 'is-installed']));
    if ($installed['exit'] !== 0) {
        $detail = trim($installed['stderr'] . "\n" . $installed['stdout']);
        return wp_fts_provider_compatibility_wordpress_skip(
            'WP-CLI is unavailable or WordPress is not installed at WP_FTS_WP_PATH.'
            . ($detail !== '' ? " Detail: {$detail}" : '')
        );
    }

    $result = wp_fts_provider_compatibility_wordpress_process(
        array_merge($baseCommand, ['eval', 'require ' . var_export(__FILE__, true) . ';']),
        [WP_FTS_PROVIDER_COMPATIBILITY_INSIDE_ENV => '1']
    );

    echo $result['stdout'];
    if ($result['stderr'] !== '') {
        fwrite(STDERR, $result['stderr']);
    }

    return $result['exit'];
}

function wp_fts_provider_compatibility_wordpress_inside(): void
{
    require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

    if (!function_exists('wp_insert_post') || !function_exists('add_filter') || !function_exists('apply_filters')) {
        throw new RuntimeException('WordPress did not load the expected post and hook APIs.');
    }

    WP_FTS_Plugin::register_hooks();
    $oldSettings = function_exists('get_option') ? get_option(WP_FTS_Plugin::SETTINGS_OPTION, null) : null;
    $postId = 0;

    try {
        WP_FTS_Plugin::repair_schema();
        $postId = wp_fts_provider_compatibility_wordpress_create_fixture_post();
        WP_FTS_Plugin::handle_post_save($postId, get_post($postId), true);
        WP_FTS_Plugin::process_queue(10);

        $evidence = [
            'schema' => WP_FTS_PROVIDER_COMPATIBILITY_REPORT_SCHEMA,
            'status' => 'passed',
            'generic_earlier_provider' => wp_fts_provider_compatibility_wordpress_scenario(
                'respect_existing',
                'providercertliveearlier',
                [(object) ['ID' => 700001], (object) ['ID' => 700002]],
                static fn(mixed $posts, mixed $query = null): mixed => $posts
            ),
            'generic_prefer_fts' => wp_fts_provider_compatibility_wordpress_scenario(
                'prefer_fts',
                'providercertlivefixture',
                [(object) ['ID' => 700003]],
                static fn(mixed $posts, mixed $query = null): mixed => $posts
            ),
            'generic_later_provider' => wp_fts_provider_compatibility_wordpress_scenario(
                'prefer_fts',
                'providercertlivefixture',
                null,
                static fn(mixed $posts, mixed $query = null): array => [(object) ['ID' => 700004]]
            ),
        ];

        echo "PASS: provider compatibility WordPress smoke completed\n";
        echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } finally {
        if ($postId > 0 && function_exists('wp_delete_post')) {
            wp_delete_post($postId, true);
        }
        if (function_exists('delete_option') && function_exists('update_option')) {
            if ($oldSettings === null || $oldSettings === false) {
                delete_option(WP_FTS_Plugin::SETTINGS_OPTION);
            } else {
                update_option(WP_FTS_Plugin::SETTINGS_OPTION, $oldSettings, false);
            }
        }
        WP_FTS_Plugin::reset_request_caches();
    }
}

function wp_fts_provider_compatibility_wordpress_create_fixture_post(): int
{
    $postId = wp_insert_post([
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_title' => 'Provider compatibility smoke fixture',
        'post_content' => '<p>providercertlivefixture providercertliveearlier deterministic smoke text.</p>',
    ], true);

    if (is_wp_error($postId)) {
        throw new RuntimeException('Could not create provider compatibility fixture post: ' . $postId->get_error_message());
    }

    $postId = (int) $postId;
    if ($postId <= 0) {
        throw new RuntimeException('Could not create provider compatibility fixture post.');
    }

    if (function_exists('update_post_meta')) {
        update_post_meta($postId, WP_FTS_Plugin::LANGUAGE_META_KEY, 'en');
    }

    return $postId;
}

/**
 * @param array<int,object>|null $earlierResult
 * @return array<string,mixed>
 */
function wp_fts_provider_compatibility_wordpress_scenario(string $mode, string $needle, ?array $earlierResult, callable $laterProvider): array
{
    $oldHookExists = isset($GLOBALS['wp_filter']) && is_array($GLOBALS['wp_filter']) && array_key_exists('posts_pre_query', $GLOBALS['wp_filter']);
    $oldHook = $oldHookExists ? $GLOBALS['wp_filter']['posts_pre_query'] : null;
    $settings = WP_FTS_Plugin::default_settings();
    $settings['search_provider_compatibility'] = $mode;
    update_option(WP_FTS_Plugin::SETTINGS_OPTION, $settings, false);
    WP_FTS_Plugin::reset_request_caches();

    try {
        if (function_exists('remove_all_filters')) {
            remove_all_filters('posts_pre_query');
        }
        add_filter('wp_fts_debug_enabled', static fn(mixed $enabled, string $context): bool => true, 10, 2);
        if ($earlierResult !== null) {
            add_filter('posts_pre_query', static fn(mixed $posts, mixed $query = null): array => $earlierResult, 20, 2);
        }
        add_filter('posts_pre_query', [WP_FTS_Plugin::class, 'replace_frontend_search_posts'], WP_FTS_Plugin::SEARCH_REPLACEMENT_PRIORITY, 2);
        add_filter('posts_pre_query', $laterProvider, 1200, 2);
        add_filter('posts_pre_query', [WP_FTS_Plugin::class, 'observe_final_search_posts'], WP_FTS_Plugin::SEARCH_FINAL_OWNERSHIP_OBSERVER_PRIORITY, 2);

        $query = new WP_FTS_Provider_Compatibility_WP_Query([
            's' => $needle,
            'posts_per_page' => 10,
        ]);
        $posts = apply_filters('posts_pre_query', null, $query);
        $trace = WP_FTS_Plugin::debug_traces()[0] ?? [];
        $ownership = is_array($trace['search_final_ownership'] ?? null) ? $trace['search_final_ownership'] : [];

        return [
            'mode' => $mode,
            'result_ids' => wp_fts_provider_compatibility_wordpress_post_ids($posts),
            'trace_status' => is_scalar($trace['status'] ?? null) ? (string) $trace['status'] : '',
            'incoming_provider_results' => (int) (($trace['counts']['incoming_provider_results'] ?? 0)),
            'prior_provider_responses_replaced' => (int) (($trace['counts']['prior_provider_responses_replaced'] ?? 0)),
            'final_ownership_status' => is_scalar($ownership['status'] ?? null) ? (string) $ownership['status'] : '',
            'final_owner' => is_scalar($ownership['owner'] ?? null) ? (string) $ownership['owner'] : '',
            'final_post_ids' => is_array($ownership['final_post_ids'] ?? null) ? array_values($ownership['final_post_ids']) : [],
            'known_provider_summary' => is_scalar($trace['settings']['known_search_providers'] ?? null) ? (string) $trace['settings']['known_search_providers'] : '',
        ];
    } finally {
        if ($oldHookExists) {
            $GLOBALS['wp_filter']['posts_pre_query'] = $oldHook;
        } elseif (isset($GLOBALS['wp_filter']) && is_array($GLOBALS['wp_filter'])) {
            unset($GLOBALS['wp_filter']['posts_pre_query']);
        }
    }
}

/**
 * @return int[]
 */
function wp_fts_provider_compatibility_wordpress_post_ids(mixed $posts): array
{
    if (!is_array($posts)) {
        return [];
    }

    $ids = [];
    foreach ($posts as $post) {
        if (is_object($post) && isset($post->ID) && is_numeric($post->ID)) {
            $ids[] = (int) $post->ID;
        }
    }

    return $ids;
}

function wp_fts_provider_compatibility_wordpress_disposable_confirmed(string $wpPath): bool
{
    $realPath = realpath($wpPath);
    if (!is_string($realPath) || $realPath === '') {
        return false;
    }

    $confirmPath = trim((string) getenv(WP_FTS_PROVIDER_COMPATIBILITY_CONFIRM_PATH_ENV));
    if ($confirmPath !== '') {
        $realConfirm = realpath($confirmPath);
        if (is_string($realConfirm) && $realConfirm === $realPath) {
            return true;
        }
    }

    return is_file($realPath . DIRECTORY_SEPARATOR . WP_FTS_PROVIDER_COMPATIBILITY_MARKER_FILE);
}

/**
 * @return array<int,string>
 */
function wp_fts_provider_compatibility_wordpress_wp_cli_base_command(string $wpPath): array
{
    $wpCli = trim((string) getenv(WP_FTS_PROVIDER_COMPATIBILITY_WP_CLI_ENV));
    if ($wpCli === '') {
        $wpCli = 'wp';
    }

    return [$wpCli, '--path=' . $wpPath];
}

/**
 * @param array<int,string> $command
 * @param array<string,string> $extraEnv
 * @return array{exit:int,stdout:string,stderr:string}
 */
function wp_fts_provider_compatibility_wordpress_process(array $command, array $extraEnv = []): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $baseEnv = getenv();
    if (!is_array($baseEnv)) {
        $baseEnv = [];
    }

    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2), array_merge($baseEnv, $extraEnv));
    if (!is_resource($process)) {
        return ['exit' => 1, 'stdout' => '', 'stderr' => 'Could not launch subprocess.'];
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

function wp_fts_provider_compatibility_wordpress_skip(string $message): int
{
    echo "SKIP: {$message}\n";

    return 0;
}

if (!class_exists('WP_FTS_Provider_Compatibility_WP_Query')) {
    final class WP_FTS_Provider_Compatibility_WP_Query
    {
        /** @var array<string,mixed> */
        public array $query_vars;
        public int $found_posts = 0;
        public int $max_num_pages = 0;

        /**
         * @param array<string,mixed> $query_vars
         */
        public function __construct(array $query_vars)
        {
            $this->query_vars = $query_vars;
        }

        public function is_search(): bool
        {
            return true;
        }

        public function is_main_query(): bool
        {
            return true;
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return array_key_exists($key, $this->query_vars) ? $this->query_vars[$key] : $default;
        }

        public function set(string $key, mixed $value): void
        {
            $this->query_vars[$key] = $value;
        }
    }
}
