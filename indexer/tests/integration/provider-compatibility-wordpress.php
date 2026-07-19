<?php
declare(strict_types=1);

/**
 * Optional live WordPress provider-compatibility smoke.
 *
 * The default shell entry point is skip-first and performs no writes unless a
 * disposable WordPress root is configured and explicitly approved.
 */

const WP_FTS_PROVIDER_COMPATIBILITY_REPORT_SCHEMA = 'wp-fts-provider-interference-matrix-smoke-v1';
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

    $eval = 'try { require ' . var_export(__FILE__, true)
        . '; exit(wp_fts_provider_compatibility_wordpress_main()); } catch (Throwable $e) { fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n"); exit(1); }';
    $result = wp_fts_provider_compatibility_wordpress_process(
        array_merge($baseCommand, ['eval', $eval]),
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
    $oldOptions = wp_fts_provider_compatibility_wordpress_snapshot_options([
        WP_FTS_Plugin::SETTINGS_OPTION,
        'active_plugins',
        'jetpack_active_modules',
    ]);
    $postId = 0;

    try {
        WP_FTS_Plugin::repair_schema();
        $postId = wp_fts_provider_compatibility_wordpress_create_fixture_post();
        WP_FTS_Plugin::handle_post_save($postId, get_post($postId), true);
        WP_FTS_Plugin::process_queue(10);

        $matrix = wp_fts_provider_compatibility_wordpress_run_matrix($postId);
        $failed = array_values(array_filter(
            $matrix['scenarios'],
            static fn(array $scenario): bool => ($scenario['passed'] ?? false) !== true
        ));
        if ($failed !== []) {
            $failedSummaries = array_map(
                static function (array $scenario): string {
                    $trace = is_array($scenario['trace'] ?? null) ? $scenario['trace'] : [];
                    $advisory = is_array($scenario['provider_advisory'] ?? null) ? $scenario['provider_advisory'] : [];
                    $ownership = is_array($scenario['final_ownership'] ?? null) ? $scenario['final_ownership'] : [];
                    $labels = is_array($advisory['provider_family_labels'] ?? null)
                        ? implode('|', wp_fts_provider_compatibility_wordpress_bounded_string_list($advisory['provider_family_labels']))
                        : '';

                    return (is_string($scenario['scenario_id'] ?? null) ? $scenario['scenario_id'] : 'unknown')
                        . '[trace=' . (is_scalar($trace['status'] ?? null) ? (string) $trace['status'] : '')
                        . ',owner=' . (is_scalar($ownership['owner'] ?? null) ? (string) $ownership['owner'] : '')
                        . ',status=' . (is_scalar($ownership['status'] ?? null) ? (string) $ownership['status'] : '')
                        . ',known=' . $labels . ']';
                },
                $failed
            );
            throw new RuntimeException('Provider interference matrix failed: ' . implode(', ', $failedSummaries));
        }

        $evidence = [
            'schema' => WP_FTS_PROVIDER_COMPATIBILITY_REPORT_SCHEMA,
            'status' => 'passed',
            'certification_boundary' => 'repo-owned provider-family simulations; not live third-party plugin certification',
            'provider_interference_matrix' => $matrix,
            'safety' => [
                'skip_first' => true,
                'requires_wp_path' => WP_FTS_PROVIDER_COMPATIBILITY_WP_PATH_ENV,
                'requires_allow' => WP_FTS_PROVIDER_COMPATIBILITY_ALLOW_ENV . '=1',
                'requires_marker_or_confirm_path' => WP_FTS_PROVIDER_COMPATIBILITY_MARKER_FILE,
                'external_plugin_downloads' => false,
                'third_party_service_calls' => false,
                'raw_provider_payloads_in_report' => false,
            ],
        ];

        echo "PASS: provider compatibility WordPress smoke completed\n";
        echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } finally {
        if ($postId > 0 && function_exists('wp_delete_post')) {
            wp_delete_post($postId, true);
        }
        wp_fts_provider_compatibility_wordpress_restore_options($oldOptions);
        WP_FTS_Plugin::reset_request_caches();
    }
}

function wp_fts_provider_compatibility_wordpress_create_fixture_post(): int
{
    $postId = wp_insert_post([
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_title' => 'Provider compatibility smoke fixture',
        'post_content' => '<p>providercertmatrixcustom providercertmatrixsearchwp providercertmatrixrelevanssi providercertmatrixadvisory deterministic smoke text.</p>',
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
 * @return array<int,array<string,mixed>>
 */
function wp_fts_provider_compatibility_wordpress_matrix_definitions(): array
{
    return [
        [
            'scenario_id' => 'theme_custom_earlier_respect_existing',
            'simulated_provider_family_labels' => ['Theme/custom'],
            'simulated_signal_labels' => ['theme posts_pre_query callback', 'custom posts_pre_query callback'],
            'compatibility_mode' => 'respect_existing',
            'needle' => 'providercertmatrixcustom',
        ],
        [
            'scenario_id' => 'searchwp_shaped_earlier_prefer_fts',
            'simulated_provider_family_labels' => ['SearchWP'],
            'simulated_signal_labels' => ['repo-owned SearchWP-shaped posts_pre_query callback'],
            'compatibility_mode' => 'prefer_fts',
            'needle' => 'providercertmatrixsearchwp',
        ],
        [
            'scenario_id' => 'relevanssi_shaped_later_provider',
            'simulated_provider_family_labels' => ['Relevanssi'],
            'simulated_signal_labels' => ['repo-owned Relevanssi-shaped later posts_pre_query callback'],
            'compatibility_mode' => 'prefer_fts',
            'needle' => 'providercertmatrixrelevanssi',
        ],
        [
            'scenario_id' => 'jetpack_elasticpress_advisory_signals',
            'simulated_provider_family_labels' => ['Jetpack Search / Jetpack', 'ElasticPress'],
            'simulated_signal_labels' => ['bounded active plugin signal', 'Jetpack active module option signal'],
            'compatibility_mode' => 'prefer_fts',
            'needle' => 'providercertmatrixadvisory',
        ],
    ];
}

/**
 * @return array{scenario_count:int,scenarios:array<int,array<string,mixed>>}
 */
function wp_fts_provider_compatibility_wordpress_run_matrix(int $fixturePostId): array
{
    $scenarios = [];
    foreach (wp_fts_provider_compatibility_wordpress_matrix_definitions() as $definition) {
        $scenarioId = (string) $definition['scenario_id'];
        if ($scenarioId === 'theme_custom_earlier_respect_existing') {
            $GLOBALS['wp_fts_provider_compatibility_theme_custom_posts'] = [
                (object) ['ID' => 700001, 'post_title' => 'raw custom provider title .env'],
                (object) ['ID' => 700002, 'post_title' => 'raw custom provider title BEGIN PRIVATE KEY'],
            ];
            $scenarios[] = wp_fts_provider_compatibility_wordpress_scenario(
                $definition,
                null,
                'wp_fts_provider_compatibility_theme_custom_earlier_provider',
                static fn(mixed $posts, mixed $query = null): mixed => $posts
            );
            unset($GLOBALS['wp_fts_provider_compatibility_theme_custom_posts']);
            continue;
        }

        if ($scenarioId === 'searchwp_shaped_earlier_prefer_fts') {
            WP_FTS_Provider_Compatibility_SearchWP_Shaped_Callback::$posts = [
                (object) ['ID' => 700003, 'post_title' => 'SearchWP private provider title'],
                (object) ['ID' => 700005, 'post_title' => 'SearchWP raw provider payload title'],
            ];
            $scenarios[] = wp_fts_provider_compatibility_wordpress_scenario(
                $definition,
                null,
                [WP_FTS_Provider_Compatibility_SearchWP_Shaped_Callback::class, 'posts_pre_query'],
                static fn(mixed $posts, mixed $query = null): mixed => $posts
            );
            WP_FTS_Provider_Compatibility_SearchWP_Shaped_Callback::$posts = [];
            continue;
        }

        if ($scenarioId === 'relevanssi_shaped_later_provider') {
            WP_FTS_Provider_Compatibility_Relevanssi_Shaped_Callback::$posts = [
                (object) ['ID' => 700004, 'post_title' => 'Relevanssi private provider title'],
                (object) ['ID' => 700006, 'post_title' => 'Relevanssi raw provider payload title'],
            ];
            $scenarios[] = wp_fts_provider_compatibility_wordpress_scenario(
                $definition,
                null,
                null,
                [WP_FTS_Provider_Compatibility_Relevanssi_Shaped_Callback::class, 'posts_pre_query']
            );
            WP_FTS_Provider_Compatibility_Relevanssi_Shaped_Callback::$posts = [];
            continue;
        }

        if ($scenarioId === 'jetpack_elasticpress_advisory_signals') {
            $scenarios[] = wp_fts_provider_compatibility_wordpress_scenario(
                $definition,
                'wp_fts_provider_compatibility_wordpress_apply_advisory_signal_simulation',
                null,
                static fn(mixed $posts, mixed $query = null): mixed => $posts
            );
            continue;
        }

        $scenarios[] = [
            'scenario_id' => $scenarioId,
            'passed' => false,
            'failure' => 'unknown scenario',
        ];
    }

    return [
        'scenario_count' => count($scenarios),
        'scenarios' => $scenarios,
    ];
}

/**
 * @param array<string,mixed> $definition
 * @return array<string,mixed>
 */
function wp_fts_provider_compatibility_wordpress_scenario(
    array $definition,
    ?callable $configureSignals,
    ?callable $earlierProvider,
    callable $laterProvider
): array
{
    $mode = (string) ($definition['compatibility_mode'] ?? 'prefer_fts');
    $needle = (string) ($definition['needle'] ?? '');
    $oldHooks = wp_fts_provider_compatibility_wordpress_snapshot_hooks(['posts_pre_query', 'wp_fts_debug_enabled']);
    $settings = WP_FTS_Plugin::default_settings();
    $settings['search_provider_compatibility'] = $mode;
    update_option(WP_FTS_Plugin::SETTINGS_OPTION, $settings, false);
    WP_FTS_Plugin::reset_request_caches();

    try {
        if (function_exists('remove_all_filters')) {
            remove_all_filters('posts_pre_query');
            remove_all_filters('wp_fts_debug_enabled');
        }
        if ($configureSignals !== null) {
            $configureSignals();
        }
        // Provider discovery is an explicit operator action. Keep it outside
        // the hot trace so this evidence cannot normalize per-search option
        // and network-option reads as part of diagnostics.
        $providerAdvisory = (string) ($definition['scenario_id'] ?? '') === 'jetpack_elasticpress_advisory_signals'
            ? wp_fts_provider_compatibility_wordpress_explicit_provider_advisory()
            : [];
        add_filter('wp_fts_debug_enabled', static fn(mixed $enabled, string $context): bool => true, 10, 2);
        if ($earlierProvider !== null) {
            add_filter('posts_pre_query', $earlierProvider, 20, 2);
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

        return wp_fts_provider_compatibility_wordpress_build_scenario_evidence(
            $definition,
            $posts,
            is_array($trace) ? $trace : [],
            $providerAdvisory
        );
    } finally {
        wp_fts_provider_compatibility_wordpress_restore_hooks($oldHooks);
    }
}

/**
 * @param array<string,mixed> $definition
 * @param array<string,mixed> $trace
 * @param array<string,mixed> $providerAdvisory
 * @return array<string,mixed>
 */
function wp_fts_provider_compatibility_wordpress_build_scenario_evidence(
    array $definition,
    mixed $posts,
    array $trace,
    array $providerAdvisory = []
): array
{
    $counts = is_array($trace['counts'] ?? null) ? $trace['counts'] : [];
    $ownership = is_array($trace['search_final_ownership'] ?? null) ? $trace['search_final_ownership'] : [];
    $settings = is_array($trace['settings'] ?? null) ? $trace['settings'] : [];
    $providerNames = wp_fts_provider_compatibility_wordpress_bounded_string_list(
        is_array($providerAdvisory['provider_names'] ?? null) ? $providerAdvisory['provider_names'] : []
    );
    $hotTraceProviderDiscoveryPresent = array_key_exists('known_search_providers', $settings)
        || array_key_exists('known_search_provider_count', $settings);

    $evidence = [
        'scenario_id' => is_string($definition['scenario_id'] ?? null) ? $definition['scenario_id'] : 'unknown',
        'simulated_provider_family_labels' => wp_fts_provider_compatibility_wordpress_bounded_string_list($definition['simulated_provider_family_labels'] ?? []),
        'simulated_signal_labels' => wp_fts_provider_compatibility_wordpress_bounded_string_list($definition['simulated_signal_labels'] ?? []),
        'compatibility_mode' => is_string($definition['compatibility_mode'] ?? null) ? $definition['compatibility_mode'] : '',
        'result_ids' => wp_fts_provider_compatibility_wordpress_bounded_int_list(wp_fts_provider_compatibility_wordpress_post_ids($posts)),
        'trace' => [
            'status' => is_scalar($trace['status'] ?? null) ? (string) $trace['status'] : '',
            'incoming_provider_results' => (int) ($counts['incoming_provider_results'] ?? 0),
            'prior_provider_responses_replaced' => (int) ($counts['prior_provider_responses_replaced'] ?? 0),
            'known_provider_discovery_present' => $hotTraceProviderDiscoveryPresent,
        ],
        'provider_advisory' => [
            'performed' => $providerAdvisory !== [],
            'source' => $providerAdvisory !== [] ? 'explicit_operator_advisory' : 'not_run',
            'provider_family_labels' => $providerNames,
            'provider_family_count' => count($providerNames),
        ],
        'final_ownership' => [
            'status' => is_scalar($ownership['status'] ?? null) ? (string) $ownership['status'] : '',
            'owner' => is_scalar($ownership['owner'] ?? null) ? (string) $ownership['owner'] : '',
            'origin' => is_scalar($ownership['origin'] ?? null) ? (string) $ownership['origin'] : '',
            'expected_count' => (int) ($ownership['expected_count'] ?? 0),
            'final_count' => (int) ($ownership['final_count'] ?? 0),
            'expected_post_ids' => wp_fts_provider_compatibility_wordpress_bounded_int_list($ownership['expected_post_ids'] ?? []),
            'final_post_ids' => wp_fts_provider_compatibility_wordpress_bounded_int_list($ownership['final_post_ids'] ?? []),
            'expected_hash' => is_scalar($ownership['expected_hash'] ?? null) ? (string) $ownership['expected_hash'] : '',
            'final_hash' => is_scalar($ownership['final_hash'] ?? null) ? (string) $ownership['final_hash'] : '',
        ],
    ];
    $evidence['redaction_passed'] = wp_fts_provider_compatibility_wordpress_evidence_redaction_passed($evidence);
    $evidence['passed'] = $evidence['redaction_passed'] && wp_fts_provider_compatibility_wordpress_scenario_passed($evidence);

    return $evidence;
}

/**
 * @param array<string,mixed> $evidence
 */
function wp_fts_provider_compatibility_wordpress_scenario_passed(array $evidence): bool
{
    $scenarioId = (string) ($evidence['scenario_id'] ?? '');
    $trace = is_array($evidence['trace'] ?? null) ? $evidence['trace'] : [];
    $ownership = is_array($evidence['final_ownership'] ?? null) ? $evidence['final_ownership'] : [];
    $providerAdvisory = is_array($evidence['provider_advisory'] ?? null) ? $evidence['provider_advisory'] : [];
    $resultIds = is_array($evidence['result_ids'] ?? null) ? array_values($evidence['result_ids']) : [];

    if (($trace['known_provider_discovery_present'] ?? true) !== false) {
        return false;
    }

    $expectsAdvisory = $scenarioId === 'jetpack_elasticpress_advisory_signals';
    if (($providerAdvisory['performed'] ?? false) !== $expectsAdvisory) {
        return false;
    }

    if ($scenarioId === 'theme_custom_earlier_respect_existing') {
        return $evidence['compatibility_mode'] === 'respect_existing'
            && $trace['status'] === 'bailed'
            && (int) ($trace['incoming_provider_results'] ?? 0) === 2
            && (int) ($trace['prior_provider_responses_replaced'] ?? -1) === 0
            && $ownership['status'] === 'earlier_provider_respected'
            && $ownership['owner'] === 'earlier_provider'
            && $resultIds === [700001, 700002];
    }

    if ($scenarioId === 'searchwp_shaped_earlier_prefer_fts') {
        return $evidence['compatibility_mode'] === 'prefer_fts'
            && $trace['status'] === 'ran'
            && (int) ($trace['incoming_provider_results'] ?? 0) === 2
            && (int) ($trace['prior_provider_responses_replaced'] ?? 0) === 1
            && $ownership['status'] === 'language_fts_survived'
            && $ownership['owner'] === 'language_fts'
            && $ownership['origin'] === 'language_fts_replaced_prior_provider'
            && $resultIds !== []
            && $resultIds !== [700003, 700005];
    }

    if ($scenarioId === 'relevanssi_shaped_later_provider') {
        return $evidence['compatibility_mode'] === 'prefer_fts'
            && $trace['status'] === 'ran'
            && $ownership['status'] === 'later_provider_changed_fts'
            && $ownership['owner'] === 'later_provider'
            && $resultIds === [700004, 700006];
    }

    if ($scenarioId === 'jetpack_elasticpress_advisory_signals') {
        $knownLabels = is_array($providerAdvisory['provider_family_labels'] ?? null)
            ? $providerAdvisory['provider_family_labels']
            : [];

        return $evidence['compatibility_mode'] === 'prefer_fts'
            && $trace['status'] === 'ran'
            && ($providerAdvisory['source'] ?? '') === 'explicit_operator_advisory'
            && in_array('Jetpack Search / Jetpack', $knownLabels, true)
            && in_array('ElasticPress', $knownLabels, true)
            && (int) ($providerAdvisory['provider_family_count'] ?? 0) === 2
            && in_array($ownership['status'] ?? null, ['language_fts_survived', 'language_fts_replaced_null'], true)
            && $ownership['owner'] === 'language_fts'
            && $resultIds !== [];
    }

    return false;
}

/** @return array<string,mixed> */
function wp_fts_provider_compatibility_wordpress_explicit_provider_advisory(): array
{
    $method = new ReflectionMethod(WP_FTS_Plugin::class, 'known_search_provider_advisory');
    $method->setAccessible(true);
    $advisory = $method->invoke(null);

    return is_array($advisory) ? $advisory : [];
}

/**
 * @param mixed $values
 * @return string[]
 */
function wp_fts_provider_compatibility_wordpress_bounded_string_list(mixed $values): array
{
    if (!is_array($values)) {
        return [];
    }

    $bounded = [];
    foreach ($values as $value) {
        if (!is_scalar($value)) {
            continue;
        }
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $bounded[] = substr($value, 0, 80);
        if (count($bounded) >= 8) {
            break;
        }
    }

    return array_values(array_unique($bounded));
}

/**
 * @param mixed $values
 * @return int[]
 */
function wp_fts_provider_compatibility_wordpress_bounded_int_list(mixed $values): array
{
    if (!is_array($values)) {
        return [];
    }

    $bounded = [];
    foreach ($values as $value) {
        if (is_numeric($value)) {
            $bounded[] = (int) $value;
        }
        if (count($bounded) >= 10) {
            break;
        }
    }

    return $bounded;
}

/**
 * @param array<string,mixed> $evidence
 */
function wp_fts_provider_compatibility_wordpress_evidence_redaction_passed(array $evidence): bool
{
    $json = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return false;
    }

    foreach (wp_fts_provider_compatibility_wordpress_forbidden_report_fragments() as $forbidden) {
        if ($forbidden !== '' && str_contains($json, $forbidden)) {
            return false;
        }
    }

    return true;
}

/**
 * @return string[]
 */
function wp_fts_provider_compatibility_wordpress_forbidden_report_fragments(): array
{
    return [
        'jetpack/jetpack.php',
        'elasticpress/elasticpress.php',
        'secret-basename.php',
        'raw-provider-option-payload-must-not-leak',
        'provider_payload',
        'private provider title',
        'raw provider payload',
        '.env',
        '~/.ssh',
        'BEGIN PRIVATE KEY',
        'BEGIN RSA PRIVATE KEY',
    ];
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

/**
 * @param string[] $optionNames
 * @return array<string,mixed>
 */
function wp_fts_provider_compatibility_wordpress_snapshot_options(array $optionNames): array
{
    $snapshots = [];
    if (!function_exists('get_option')) {
        return $snapshots;
    }

    foreach ($optionNames as $optionName) {
        $snapshots[$optionName] = get_option($optionName, null);
    }

    return $snapshots;
}

/**
 * @param array<string,mixed> $snapshots
 */
function wp_fts_provider_compatibility_wordpress_restore_options(array $snapshots): void
{
    if (!function_exists('delete_option') || !function_exists('update_option')) {
        return;
    }

    foreach ($snapshots as $optionName => $value) {
        if ($value === null || $value === false) {
            delete_option($optionName);
            continue;
        }
        update_option($optionName, $value, false);
    }
}

function wp_fts_provider_compatibility_wordpress_apply_advisory_signal_simulation(): void
{
    update_option('active_plugins', [
        'jetpack/jetpack.php',
        'elasticpress/elasticpress.php',
        'private-search-provider/secret-basename.php',
    ], false);
    update_option('jetpack_active_modules', [
        'search',
        'raw-provider-option-payload-must-not-leak',
    ], false);
}

/**
 * @param string[] $hookNames
 * @return array<string,array{exists:bool,value:mixed}>
 */
function wp_fts_provider_compatibility_wordpress_snapshot_hooks(array $hookNames): array
{
    $snapshots = [];
    foreach ($hookNames as $hookName) {
        $exists = isset($GLOBALS['wp_filter']) && is_array($GLOBALS['wp_filter']) && array_key_exists($hookName, $GLOBALS['wp_filter']);
        $snapshots[$hookName] = [
            'exists' => $exists,
            'value' => $exists ? $GLOBALS['wp_filter'][$hookName] : null,
        ];
    }

    return $snapshots;
}

/**
 * @param array<string,array{exists:bool,value:mixed}> $snapshots
 */
function wp_fts_provider_compatibility_wordpress_restore_hooks(array $snapshots): void
{
    if (!isset($GLOBALS['wp_filter']) || !is_array($GLOBALS['wp_filter'])) {
        $GLOBALS['wp_filter'] = [];
    }

    foreach ($snapshots as $hookName => $snapshot) {
        if (($snapshot['exists'] ?? false) === true) {
            $GLOBALS['wp_filter'][$hookName] = $snapshot['value'] ?? null;
            continue;
        }
        unset($GLOBALS['wp_filter'][$hookName]);
    }
}

function wp_fts_provider_compatibility_theme_custom_earlier_provider(mixed $posts, mixed $query = null): array
{
    $providerPosts = $GLOBALS['wp_fts_provider_compatibility_theme_custom_posts'] ?? [];

    return is_array($providerPosts) ? $providerPosts : [];
}

if (!class_exists('WP_FTS_Provider_Compatibility_SearchWP_Shaped_Callback')) {
    final class WP_FTS_Provider_Compatibility_SearchWP_Shaped_Callback
    {
        /** @var array<int,object> */
        public static array $posts = [];

        public static function posts_pre_query(mixed $posts, mixed $query = null): array
        {
            return self::$posts;
        }
    }
}

if (!class_exists('WP_FTS_Provider_Compatibility_Relevanssi_Shaped_Callback')) {
    final class WP_FTS_Provider_Compatibility_Relevanssi_Shaped_Callback
    {
        /** @var array<int,object> */
        public static array $posts = [];

        public static function posts_pre_query(mixed $posts, mixed $query = null): array
        {
            return self::$posts;
        }
    }
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
