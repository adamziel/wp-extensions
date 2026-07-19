<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    require '/wordpress/wp-load.php';
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

const WP_FTS_PLAYGROUND_CLI_POST_IDS_OPTION = 'wp_fts_playground_cli_post_ids';
const WP_FTS_PLAYGROUND_CLI_QUERY = 'playgroundcliword';

/**
 * Fail the Playground smoke with context visible in CLI logs.
 *
 * @param array<string,mixed> $context
 */
function wp_fts_playground_fail(string $message, array $context = []): void
{
    throw new RuntimeException($message . ($context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES)));
}

/**
 * Assert a smoke condition.
 *
 * @param array<string,mixed> $context
 */
function wp_fts_playground_assert(bool $condition, string $message, array $context = []): void
{
    if (!$condition) {
        wp_fts_playground_fail($message, $context);
    }
}

/**
 * Gather SQLite evidence from the Playground WordPress runtime.
 *
 * @return array<string,mixed>
 */
function wp_fts_playground_sqlite_evidence(): array
{
    global $wpdb;

    $evidence = [
        'wpdb_class' => is_object($wpdb) ? get_class($wpdb) : gettype($wpdb),
        'dbh_class' => isset($wpdb->dbh) && is_object($wpdb->dbh) ? get_class($wpdb->dbh) : gettype($wpdb->dbh ?? null),
        'signals' => [],
    ];

    foreach (['SQLITE_MAIN_FILE', 'SQLITE_PLUGIN', 'SQLITE_DB_DROPIN_VERSION', 'DB_ENGINE'] as $constant) {
        if (defined($constant)) {
            $evidence['signals'][$constant] = (string) constant($constant);
        }
    }

    if (is_object($wpdb) && method_exists($wpdb, 'db_server_info')) {
        $evidence['signals']['db_server_info'] = (string) $wpdb->db_server_info();
    }

    if (function_exists('get_mu_plugins')) {
        foreach (array_keys(get_mu_plugins()) as $plugin) {
            if (stripos((string) $plugin, 'sqlite') !== false) {
                $evidence['signals']['mu_plugin'] = (string) $plugin;
            }
        }
    }

    $encoded = json_encode($evidence, JSON_UNESCAPED_SLASHES);
    $hasSqliteSignal = stripos((string) $encoded, 'sqlite') !== false;
    wp_fts_playground_assert($hasSqliteSignal, 'Playground runtime did not expose SQLite evidence', $evidence);

    return $evidence;
}

/**
 * Insert a post into the Playground WordPress runtime.
 *
 * @param array<string,mixed> $postArgs
 */
function wp_fts_playground_insert_post(string $title, string $content, array $postArgs = []): int
{
    $postId = wp_insert_post(array_merge([
        'post_title' => $title,
        'post_content' => $content,
        'post_status' => 'publish',
        'post_type' => 'post',
    ], $postArgs), true);
    wp_fts_playground_assert(!is_wp_error($postId) && (int) $postId > 0, 'Could not insert smoke post');

    return (int) $postId;
}

/**
 * Insert a post and index it through the plugin path.
 *
 * @param array<string,mixed> $indexOptions
 * @param array<string,mixed> $postArgs
 */
function wp_fts_playground_index_post(WP_FTS_Indexer $indexer, string $title, string $content, array $indexOptions = [], array $postArgs = []): int
{
    $postId = wp_fts_playground_insert_post($title, $content, $postArgs);
    $post = get_post($postId);
    wp_fts_playground_assert($post instanceof WP_Post, 'Could not load inserted smoke post', ['post_id' => $postId]);
    $post->terms = [];
    $post->custom_fields = [];
    $post->fts_language_override = '';
    $post->fts_integration_language = '';
    $prepared = $indexer->prepare_post(
        $post,
        WP_FTS_Plugin::prepare_post_index_options($post, $indexOptions)
    );
    WP_FTS_Plugin::storage(false)->replace_prepared_documents([$prepared]);

    return $postId;
}

/**
 * Assert that a search returns exactly the expected document IDs.
 *
 * @param array<string,mixed> $options
 * @param int[] $expected
 * @param array<string,mixed> $debug
 */
function wp_fts_playground_assert_search(WP_FTS_Searcher $searcher, string $query, array $options, array $expected, string $label, array $debug = []): void
{
    $actual = array_map('intval', array_column($searcher->search($query, $options), 'doc_id'));
    sort($actual, SORT_NUMERIC);
    sort($expected, SORT_NUMERIC);

    wp_fts_playground_assert($actual === $expected, $label, [
        'query' => $query,
        'options' => $options,
        'expected' => $expected,
        'actual' => $actual,
    ] + $debug);
}

/**
 * Ensure the mounted plugin is active and its runtime classes are loaded.
 */
function wp_fts_playground_activate_plugin(): void
{
    $pluginFile = WP_PLUGIN_DIR . '/indexer/indexer.php';
    wp_fts_playground_assert(is_file($pluginFile), 'Mounted indexer plugin was not found', ['plugin_file' => $pluginFile]);

    if (!is_plugin_active('indexer/indexer.php')) {
        $activation = activate_plugin('indexer/indexer.php');
        wp_fts_playground_assert(!is_wp_error($activation), 'Could not activate indexer plugin', [
            'error' => is_wp_error($activation) ? $activation->get_error_message() : null,
        ]);
    }

    wp_fts_playground_assert(class_exists('WP_FTS_Plugin'), 'Indexer plugin classes were not loaded after activation');
}

/**
 * Register the plugin REST route once for direct REST dispatch assertions.
 */
function wp_fts_playground_register_rest_route(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    $settings = get_option(WP_FTS_Plugin::SETTINGS_OPTION, []);
    $settings = is_array($settings) ? $settings : [];
    $settings['rest_api_enabled'] = true;
    update_option(WP_FTS_Plugin::SETTINGS_OPTION, $settings, false);

    if (function_exists('do_action')) {
        do_action('rest_api_init');
        return;
    }

    WP_FTS_Plugin::register_rest_routes();
}

/**
 * Execute the public REST search route with the supplied request parameters.
 *
 * @param array<string,mixed> $params
 * @return array{status:int,data:mixed}
 */
function wp_fts_playground_rest_request(array $params): array
{
    wp_fts_playground_register_rest_route();

    if (class_exists('WP_REST_Request') && function_exists('rest_do_request')) {
        $request = new WP_REST_Request('GET', '/' . WP_FTS_Plugin::REST_NAMESPACE . WP_FTS_Plugin::REST_SEARCH_ROUTE);
        foreach ($params as $key => $value) {
            $request->set_param((string) $key, $value);
        }

        $response = rest_do_request($request);
        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return [
                'status' => (int) ($response->get_error_data()['status'] ?? 500),
                'data' => [
                    'code' => $response->get_error_code(),
                    'message' => $response->get_error_message(),
                    'data' => $response->get_error_data(),
                ],
            ];
        }

        return [
            'status' => is_object($response) && method_exists($response, 'get_status') ? (int) $response->get_status() : 200,
            'data' => is_object($response) && method_exists($response, 'get_data') ? $response->get_data() : null,
        ];
    }

    $result = WP_FTS_Plugin::rest_search($params);
    if ($result instanceof WP_Error) {
        return [
            'status' => (int) ($result->get_error_data()['status'] ?? 500),
            'data' => [
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
                'data' => $result->get_error_data(),
            ],
        ];
    }

    return [
        'status' => 200,
        'data' => $result,
    ];
}

/**
 * Assert REST search result IDs for a successful request.
 *
 * @param array<string,mixed> $params
 * @param int[] $expected
 */
function wp_fts_playground_assert_rest_ids(array $params, array $expected, string $label): void
{
    $response = wp_fts_playground_rest_request($params);
    wp_fts_playground_assert($response['status'] === 200, $label . ' should return HTTP 200', $response);
    wp_fts_playground_assert(is_array($response['data']) && isset($response['data']['results']) && is_array($response['data']['results']), $label . ' should return result rows', $response);

    $actual = array_map('intval', array_column($response['data']['results'], 'doc_id'));
    sort($actual, SORT_NUMERIC);
    sort($expected, SORT_NUMERIC);

    wp_fts_playground_assert($actual === $expected, $label, [
        'params' => $params,
        'expected' => $expected,
        'actual' => $actual,
        'response' => $response,
    ]);
}

/**
 * Assert a stable REST error code and status.
 *
 * @param array<string,mixed> $params
 */
function wp_fts_playground_assert_rest_error(array $params, string $expectedCode, int $expectedStatus, string $label): void
{
    $response = wp_fts_playground_rest_request($params);
    $code = is_array($response['data']) ? (string) ($response['data']['code'] ?? '') : '';

    wp_fts_playground_assert($response['status'] === $expectedStatus && $code === $expectedCode, $label, [
        'params' => $params,
        'expected_status' => $expectedStatus,
        'expected_code' => $expectedCode,
        'actual_status' => $response['status'],
        'actual_code' => $code,
        'response' => $response,
    ]);
}

/**
 * Probe public REST query aliases, validation errors, and pre-limit visibility.
 *
 * @return array<string,mixed>
 */
function wp_fts_playground_rest_smoke(WP_FTS_Indexer $indexer): array
{
    $qId = wp_fts_playground_index_post($indexer, 'REST q probe', '<p>restsurfacealpha</p>', ['lang' => 'en']);
    $queryId = wp_fts_playground_index_post($indexer, 'REST query probe', '<p>restsurfacebeta</p>', ['lang' => 'en']);

    wp_fts_playground_assert_rest_ids(['q' => 'restsurfacealpha', 'lang' => 'en', 'limit' => 5], [$qId], 'REST q parameter should search the public route');
    wp_fts_playground_assert_rest_ids(['query' => 'restsurfacebeta', 'lang' => 'en', 'limit' => 5], [$queryId], 'REST query alias should search the public route');
    wp_fts_playground_assert_rest_error(['query' => 'restsurfacealpha', 'mode' => 'xor'], 'wp_fts_invalid_mode', 400, 'REST invalid mode should be rejected');
    wp_fts_playground_assert_rest_error(['q' => ' ', 'query' => ''], 'wp_fts_missing_query', 400, 'REST missing query should be rejected');

    $hiddenOne = wp_fts_playground_index_post($indexer, 'REST hidden one', '<p>refillvisibleword</p>', ['lang' => 'en'], ['post_password' => 'secret-one']);
    $hiddenTwo = wp_fts_playground_index_post($indexer, 'REST hidden two', '<p>refillvisibleword</p>', ['lang' => 'en'], ['post_password' => 'secret-two']);
    $visibleOne = wp_fts_playground_index_post($indexer, 'REST visible one', '<p>refillvisibleword</p>', ['lang' => 'en']);
    $visibleTwo = wp_fts_playground_index_post($indexer, 'REST visible two', '<p>refillvisibleword</p>', ['lang' => 'en']);

    wp_fts_playground_assert_rest_ids(
        ['q' => 'refillvisibleword', 'lang' => 'en', 'limit' => 2],
        [$visibleOne, $visibleTwo],
        'REST search should apply canonical visibility before ranking and LIMIT'
    );

    return [
        'q_param' => $qId,
        'query_alias' => $queryId,
        'invalid_mode' => 'wp_fts_invalid_mode',
        'missing_query' => 'wp_fts_missing_query',
        'visibility_before_limit' => [
            'hidden_passworded' => [$hiddenOne, $hiddenTwo],
            'visible' => [$visibleOne, $visibleTwo],
        ],
    ];
}

/**
 * Insert posts that the later WP-CLI reindex scope and worker passes must index.
 *
 * @return int[]
 */
function wp_fts_playground_prepare_wpcli_fixture_posts(): array
{
    $ids = [
        wp_fts_playground_insert_post('WP-CLI fixture one', '<p>' . WP_FTS_PLAYGROUND_CLI_QUERY . ' first</p>'),
        wp_fts_playground_insert_post('WP-CLI fixture two', '<p>' . WP_FTS_PLAYGROUND_CLI_QUERY . ' second</p>'),
    ];
    update_option(WP_FTS_PLAYGROUND_CLI_POST_IDS_OPTION, $ids);

    return $ids;
}

/**
 * Assert that the preceding `wp fts reindex` scope and bounded worker passes indexed the fixture posts.
 */
function wp_fts_playground_assert_wpcli_reindex_effect(): void
{
    wp_fts_playground_activate_plugin();
    $ids = array_map('intval', (array) get_option(WP_FTS_PLAYGROUND_CLI_POST_IDS_OPTION, []));
    sort($ids, SORT_NUMERIC);
    wp_fts_playground_assert(count($ids) === 2, 'WP-CLI fixture post IDs were not persisted', ['ids' => $ids]);

    $searcher = new WP_FTS_Searcher(WP_FTS_Plugin::storage(true), new WP_FTS_Analyzer(['default_lang' => 'en']));
    $actual = array_map('intval', array_column($searcher->search(WP_FTS_PLAYGROUND_CLI_QUERY, ['lang' => 'en', 'limit' => 10]), 'doc_id'));
    sort($actual, SORT_NUMERIC);

    wp_fts_playground_assert($actual === $ids, 'WP-CLI reindex should make fixture posts searchable in SQLite', [
        'query' => WP_FTS_PLAYGROUND_CLI_QUERY,
        'expected' => $ids,
        'actual' => $actual,
    ]);

    $summary = 'WP_FTS_PLAYGROUND_WPCLI_SMOKE ' . json_encode([
        'query' => WP_FTS_PLAYGROUND_CLI_QUERY,
        'wpcli_reindex_effect' => $ids,
        'wpcli_search_command' => 'wp fts search ' . WP_FTS_PLAYGROUND_CLI_QUERY . ' --lang=en --limit=5 --post_status=publish --post_type=post --snippet',
    ], JSON_UNESCAPED_SLASHES);

    error_log($summary);
    echo $summary . PHP_EOL;
}

/**
 * Run the setup, SQLite, indexer, searcher, REST, and fixture-prep smoke.
 */
function wp_fts_playground_run_setup_smoke(): void
{
    wp_fts_playground_activate_plugin();

    // Direct component indexing below is deliberate smoke-fixture setup. Keep
    // the normal save hooks from publishing a second dirty generation for the
    // same posts while the shared writer lease owns the relational writes.
    $settings = get_option(WP_FTS_Plugin::SETTINGS_OPTION, []);
    $settings = is_array($settings) ? $settings : [];
    $settings['auto_index'] = false;
    update_option(WP_FTS_Plugin::SETTINGS_OPTION, $settings, false);

    $sqliteEvidence = wp_fts_playground_sqlite_evidence();
    $storage = WP_FTS_Plugin::storage(true);
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    $fixtureWrite = WP_FTS_Plugin::run_index_writer_with_lock(
        'playground-sqlite-smoke',
        static function () use ($indexer): array {
            return [
                'polish' => wp_fts_playground_index_post($indexer, '', '<p>Wrocław oraz Łódź kotami</p>'),
                'german' => wp_fts_playground_index_post($indexer, '', '<p>Führung und Straße</p>'),
                'override' => wp_fts_playground_index_post($indexer, '', '<p>Wrocław explicit override</p>', ['lang' => 'en']),
                'fallback' => wp_fts_playground_index_post($indexer, '', '<p>alpha beta shared</p>'),
                'binary' => wp_fts_playground_index_post($indexer, '', '<p>sqliteprefixqzxv</p>', ['lang' => 'en']),
            ];
        },
        ['record_health' => false, 'record_skip' => false]
    );
    wp_fts_playground_assert($fixtureWrite['acquired'], 'Could not acquire the shared writer lease for SQLite smoke fixtures', $fixtureWrite['summary']);
    $fixtures = is_array($fixtureWrite['result']) ? $fixtureWrite['result'] : [];
    $polishId = (int) ($fixtures['polish'] ?? 0);
    $germanId = (int) ($fixtures['german'] ?? 0);
    $overrideId = (int) ($fixtures['override'] ?? 0);
    $fallbackId = (int) ($fixtures['fallback'] ?? 0);
    $binaryTermId = (int) ($fixtures['binary'] ?? 0);

    wp_fts_playground_assert_search($searcher, 'Wrocław', ['limit' => 10], [$polishId], 'untagged Polish query should meet detected Polish document partition');
    wp_fts_playground_assert_search($searcher, 'kot', ['lang' => 'pl', 'limit' => 10], [$polishId], 'Polish stemming should match kotami with an explicit Polish query');
    wp_fts_playground_assert_search($searcher, 'Führung', ['limit' => 10], [$germanId], 'untagged German query should meet detected German document partition');
    wp_fts_playground_assert_search($searcher, 'Wrocław', ['lang' => 'en', 'limit' => 10], [$overrideId], 'explicit English override should stay in English partition');
    wp_fts_playground_assert_search($searcher, 'alpha', ['limit' => 10], [$fallbackId], 'no-evidence content and query should stay on conservative fallback partition');
    wp_fts_playground_assert_search($searcher, 'sqliteprefixqzxv', ['lang' => 'en', 'prefix_matching' => false, 'limit' => 10], [$binaryTermId], 'SQLite exact binary term lookup should return the indexed document');
    wp_fts_playground_assert_search($searcher, 'sqliteprefixqz', ['lang' => 'en', 'prefix_matching' => true, 'limit' => 10], [$binaryTermId], 'SQLite binary prefix lookup should return the indexed document');
    wp_fts_playground_assert_search($searcher, 'sqliteabsentqzxv', ['lang' => 'en', 'prefix_matching' => false, 'limit' => 10], [], 'SQLite absent binary term lookup should stay empty');
    wp_fts_playground_assert_search($searcher, 'sqliteprefixqzxv sqliteabsentqzxv', ['lang' => 'en', 'mode' => 'OR', 'prefix_matching' => false, 'limit' => 10], [$binaryTermId], 'SQLite mixed exact lookup should return the existing term without scanning for the absent term');

    $restWrite = WP_FTS_Plugin::run_index_writer_with_lock(
        'playground-rest-smoke',
        static fn(): array => wp_fts_playground_rest_smoke($indexer),
        ['record_health' => false, 'record_skip' => false]
    );
    wp_fts_playground_assert($restWrite['acquired'], 'Could not acquire the shared writer lease for REST smoke fixtures', $restWrite['summary']);
    $rest = is_array($restWrite['result']) ? $restWrite['result'] : [];
    $cliFixtureIds = wp_fts_playground_prepare_wpcli_fixture_posts();

    $summary = 'WP_FTS_PLAYGROUND_SQLITE_SMOKE ' . json_encode([
        'sqlite' => $sqliteEvidence,
        'plugin_active' => is_plugin_active('indexer/indexer.php'),
        'posts' => [
            'polish_detected' => $polishId,
            'german_detected' => $germanId,
            'explicit_override' => $overrideId,
            'fallback' => $fallbackId,
            'binary_term' => $binaryTermId,
        ],
        'rest' => $rest,
        'wpcli_fixture_posts' => $cliFixtureIds,
        'probes' => [
            'polish_detection' => 'Wrocław',
            'polish_stemming' => 'kot -> kotami',
            'german_detection' => 'Führung',
            'explicit_override' => 'Wrocław in en',
            'fallback' => 'alpha',
            'sqlite_binary_exact' => 'sqliteprefixqzxv',
            'sqlite_binary_prefix' => 'sqliteprefixqz -> sqliteprefixqzxv',
            'sqlite_binary_absent' => 'sqliteabsentqzxv',
            'sqlite_binary_mixed' => 'sqliteprefixqzxv OR sqliteabsentqzxv',
            'rest_q' => 'restsurfacealpha',
            'rest_query' => 'restsurfacebeta',
            'rest_invalid_mode' => 'xor',
            'rest_missing_query' => 'blank q/query',
            'rest_visibility_before_limit' => 'refillvisibleword',
            'wpcli_reindex_search' => WP_FTS_PLAYGROUND_CLI_QUERY,
        ],
    ], JSON_UNESCAPED_SLASHES);

    error_log($summary);
    echo $summary . PHP_EOL;
}

$mode = defined('WP_FTS_PLAYGROUND_SMOKE_MODE') ? (string) WP_FTS_PLAYGROUND_SMOKE_MODE : 'setup';
if ($mode === 'setup') {
    wp_fts_playground_run_setup_smoke();
} elseif ($mode === 'post-wpcli') {
    wp_fts_playground_assert_wpcli_reindex_effect();
} else {
    wp_fts_playground_fail('Unknown Playground smoke mode', ['mode' => $mode]);
}
