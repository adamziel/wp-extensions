<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    require '/wordpress/wp-load.php';
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

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
 * Insert a published post and index it through the plugin path.
 *
 * @param array<string,mixed> $indexOptions
 */
function wp_fts_playground_index_post(WP_FTS_Indexer $indexer, string $title, string $content, array $indexOptions = []): int
{
    $postId = wp_insert_post([
        'post_title' => $title,
        'post_content' => $content,
        'post_status' => 'publish',
        'post_type' => 'post',
    ], true);
    wp_fts_playground_assert(!is_wp_error($postId) && (int) $postId > 0, 'Could not insert smoke post');

    $post = get_post((int) $postId);
    wp_fts_playground_assert($post instanceof WP_Post, 'Could not load inserted smoke post', ['post_id' => (int) $postId]);
    $indexer->index_post($post, $indexOptions);

    return (int) $postId;
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

$pluginFile = WP_PLUGIN_DIR . '/indexer/indexer.php';
wp_fts_playground_assert(is_file($pluginFile), 'Mounted indexer plugin was not found', ['plugin_file' => $pluginFile]);

if (!is_plugin_active('indexer/indexer.php')) {
    $activation = activate_plugin('indexer/indexer.php');
    wp_fts_playground_assert(!is_wp_error($activation), 'Could not activate indexer plugin', [
        'error' => is_wp_error($activation) ? $activation->get_error_message() : null,
    ]);
}

wp_fts_playground_assert(class_exists('WP_FTS_Plugin'), 'Indexer plugin classes were not loaded after activation');

$sqliteEvidence = wp_fts_playground_sqlite_evidence();
$storage = WP_FTS_Plugin::storage(true);
$analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
$indexer = new WP_FTS_Indexer($storage, $analyzer);
$searcher = new WP_FTS_Searcher($storage, $analyzer);

$polishId = wp_fts_playground_index_post($indexer, '', '<p>Wrocław oraz Łódź kotami</p>');
$germanId = wp_fts_playground_index_post($indexer, '', '<p>Führung und Straße</p>');
$overrideId = wp_fts_playground_index_post($indexer, '', '<p>Wrocław explicit override</p>', ['lang' => 'en']);
$fallbackId = wp_fts_playground_index_post($indexer, '', '<p>alpha beta shared</p>');

wp_fts_playground_assert_search($searcher, 'Wrocław', ['limit' => 10], [$polishId], 'untagged Polish query should meet detected Polish document partition');
wp_fts_playground_assert_search($searcher, 'kot', ['lang' => 'pl', 'limit' => 10], [$polishId], 'Polish stemming should match kotami with an explicit Polish query');
wp_fts_playground_assert_search($searcher, 'Führung', ['limit' => 10], [$germanId], 'untagged German query should meet detected German document partition');
wp_fts_playground_assert_search($searcher, 'Wrocław', ['lang' => 'en', 'limit' => 10], [$overrideId], 'explicit English override should stay in English partition');
wp_fts_playground_assert_search($searcher, 'alpha', ['limit' => 10], [$fallbackId], 'no-evidence content and query should stay on conservative fallback partition');

$summary = 'WP_FTS_PLAYGROUND_SQLITE_SMOKE ' . json_encode([
    'sqlite' => $sqliteEvidence,
    'plugin_active' => is_plugin_active('indexer/indexer.php'),
    'posts' => [
        'polish_detected' => $polishId,
        'german_detected' => $germanId,
        'explicit_override' => $overrideId,
        'fallback' => $fallbackId,
    ],
    'probes' => [
        'polish_detection' => 'Wrocław',
        'polish_stemming' => 'kot -> kotami',
        'german_detection' => 'Führung',
        'explicit_override' => 'Wrocław in en',
        'fallback' => 'alpha',
    ],
], JSON_UNESCAPED_SLASHES);

error_log($summary);
echo $summary . PHP_EOL;
