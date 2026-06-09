<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    require '/wordpress/wp-load.php';
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

const WP_FTS_LIVE_BENCHMARK_DOCS_PER_LANGUAGE = 24;
const WP_FTS_LIVE_BENCHMARK_LANGUAGES = ['en', 'pl', 'de'];
const WP_FTS_LIVE_BENCHMARK_OUTPUT_FILE = '/wordpress/wp-content/benchmark-output/live-benchmark.json';
const WP_FTS_LIVE_BENCHMARK_INDEX_WALL_TIME_BUDGET_MS = 60000;
const WP_FTS_LIVE_BENCHMARK_PEAK_MEMORY_BUDGET_BYTES = 134217728;

/**
 * Fail the live benchmark with a JSON context payload visible in Playground logs.
 *
 * @param array<string,mixed> $context
 */
function wp_fts_live_benchmark_fail(string $message, array $context = []): void
{
    throw new RuntimeException($message . ($context === [] ? '' : ' ' . wp_json_encode($context)));
}

/**
 * Assert a live benchmark condition.
 *
 * @param array<string,mixed> $context
 */
function wp_fts_live_benchmark_assert(bool $condition, string $message, array $context = []): void
{
    if (!$condition) {
        wp_fts_live_benchmark_fail($message, $context);
    }
}

/**
 * Return a monotonic-ish millisecond timestamp for coarse benchmark phases.
 */
function wp_fts_live_benchmark_ms(): float
{
    return microtime(true) * 1000;
}

/**
 * Write benchmark JSON to an optional host-mounted output directory.
 */
function wp_fts_live_benchmark_artifact_file(): ?string
{
    $directory = dirname(WP_FTS_LIVE_BENCHMARK_OUTPUT_FILE);
    if (!is_dir($directory)) {
        return null;
    }

    return WP_FTS_LIVE_BENCHMARK_OUTPUT_FILE;
}

/**
 * Write benchmark JSON to the host-mounted output file.
 *
 * @param array<string,mixed> $summary
 */
function wp_fts_live_benchmark_write_artifact(array $summary, string $outputFile): void
{
    $json = wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    wp_fts_live_benchmark_assert(is_string($json) && $json !== '', 'Could not encode live benchmark artifact');

    $written = file_put_contents($outputFile, $json . PHP_EOL);
    wp_fts_live_benchmark_assert($written !== false, 'Could not write live benchmark artifact', [
        'output_file' => $outputFile,
    ]);
}

/**
 * Gather SQLite evidence from the live WordPress runtime.
 *
 * @return array<string,mixed>
 */
function wp_fts_live_benchmark_sqlite_evidence(): array
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

    $encoded = wp_json_encode($evidence);
    wp_fts_live_benchmark_assert(
        stripos((string) $encoded, 'sqlite') !== false,
        'Playground runtime did not expose SQLite evidence',
        $evidence
    );

    return $evidence;
}

/**
 * Return FTS table names used by the MySQL-compatible storage backend.
 *
 * @return array<string,string>
 */
function wp_fts_live_benchmark_tables(): array
{
    global $wpdb;

    $prefix = (string) $wpdb->prefix;

    return [
        'terms' => $prefix . 'fts_terms',
        'postings' => $prefix . 'fts_postings',
        'docs' => $prefix . 'fts_docs',
        'doc_lengths' => $prefix . 'fts_doc_lengths',
        'docmeta' => $prefix . 'fts_docmeta',
        'meta' => $prefix . 'fts_meta',
    ];
}

/**
 * Clear only the plugin benchmark tables so repeated Playground runs are stable.
 */
function wp_fts_live_benchmark_clear_index_tables(): void
{
    global $wpdb;

    foreach (array_reverse(wp_fts_live_benchmark_tables()) as $table) {
        $result = $wpdb->query("DELETE FROM {$table}");
        wp_fts_live_benchmark_assert($result !== false, 'Could not clear FTS benchmark table', [
            'table' => $table,
            'error' => (string) $wpdb->last_error,
        ]);
    }
}

/**
 * Count rows in the plugin benchmark tables.
 *
 * @return array<string,int>
 */
function wp_fts_live_benchmark_table_counts(): array
{
    global $wpdb;

    $counts = [];
    foreach (wp_fts_live_benchmark_tables() as $name => $table) {
        $value = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        wp_fts_live_benchmark_assert(is_numeric($value), 'Could not count FTS benchmark table rows', [
            'table' => $table,
            'value' => $value,
            'error' => (string) $wpdb->last_error,
        ]);
        $counts[$name] = (int) $value;
    }

    return $counts;
}

/**
 * Inspect direct term/posting lookups for benchmark terms before public search.
 *
 * @return array<string,mixed>
 */
function wp_fts_live_benchmark_direct_lookup_debug(WP_FTS_Storage_Mysql $storage): array
{
    global $wpdb;

    $keys = [
        'en_benchmarkneedle' => WP_FTS_TermNamespace::namespace_term('en', 'benchmarkneedle'),
        'pl_benchmarkneedle' => WP_FTS_TermNamespace::namespace_term('pl', 'benchmarkneedle'),
        'de_benchmarkneedle' => WP_FTS_TermNamespace::namespace_term('de', 'benchmarkneedle'),
        'pl_raresignal' => WP_FTS_TermNamespace::namespace_term('pl', 'raresignal'),
    ];
    $postings = WP_FTS_StorageCompat::get_postings($storage, array_values($keys));
    $tables = wp_fts_live_benchmark_tables();
    $sampleRows = $wpdb->get_results(
        "SELECT term, doc_freq FROM {$tables['terms']} ORDER BY term ASC LIMIT 8",
        ARRAY_A
    );
    $sampleTerms = [];
    foreach (is_array($sampleRows) ? $sampleRows : [] as $row) {
        $term = (string) ($row['term'] ?? '');
        $split = WP_FTS_TermNamespace::split_term($term);
        $sampleTerms[] = [
            'hex' => bin2hex($term),
            'split' => $split,
            'doc_freq' => (int) ($row['doc_freq'] ?? 0),
        ];
    }

    $matchingTerms = [];
    foreach ($storage->all_terms() as $storedTerm) {
        $split = WP_FTS_TermNamespace::split_term((string) $storedTerm);
        $term = $split['term'] ?? (string) $storedTerm;
        foreach (['benchmark', 'needle', 'stable', 'rare', 'signal'] as $needle) {
            if (str_contains($term, $needle)) {
                $matchingTerms[] = [
                    'hex' => bin2hex((string) $storedTerm),
                    'split' => $split,
                ];
                break;
            }
        }
        if (count($matchingTerms) >= 24) {
            break;
        }
    }

    $postingCounts = [];
    foreach ($keys as $name => $key) {
        $postingCounts[$name] = count($postings[$key] ?? []);
    }

    return [
        'posting_counts' => $postingCounts,
        'sample_terms' => $sampleTerms,
        'matching_terms' => $matchingTerms,
    ];
}

/**
 * Build deterministic synthetic post content for one language partition.
 */
function wp_fts_live_benchmark_content(string $language, int $ordinal): string
{
    $rare = $ordinal <= 4 ? ' raresignal' : '';
    $titleProbe = $ordinal % 3 === 0 ? ' titleboostprobe' : '';
    $body = [];
    for ($i = 0; $i < 4; $i++) {
        $body[] = sprintf(
            '<p>benchmarkneedle stablefield lang%s doc%02d shard%02d%s%s repeated benchmarkneedle context%02d</p>',
            esc_html($language),
            $ordinal,
            $i,
            $rare,
            $titleProbe,
            $i
        );
    }

    return implode("\n", $body);
}

/**
 * Insert and index the synthetic benchmark corpus through real WordPress posts.
 *
 * @return array<int,int> Inserted post ids.
 */
function wp_fts_live_benchmark_index_corpus(WP_FTS_Indexer $indexer): array
{
    $postIds = [];

    foreach (WP_FTS_LIVE_BENCHMARK_LANGUAGES as $language) {
        for ($i = 1; $i <= WP_FTS_LIVE_BENCHMARK_DOCS_PER_LANGUAGE; $i++) {
            $postId = wp_insert_post([
                'post_title' => sprintf('FTS live benchmark %s %02d benchmarkneedle', $language, $i),
                'post_content' => wp_fts_live_benchmark_content($language, $i),
                'post_excerpt' => sprintf('Excerpt fieldprobe %s %02d benchmarkneedle', $language, $i),
                'post_status' => 'publish',
                'post_type' => 'post',
            ], true);
            wp_fts_live_benchmark_assert(!is_wp_error($postId) && (int) $postId > 0, 'Could not insert benchmark post');

            $post = get_post((int) $postId);
            wp_fts_live_benchmark_assert($post instanceof WP_Post, 'Could not load benchmark post', [
                'post_id' => (int) $postId,
            ]);

            $indexer->index_post($post, ['lang' => $language]);
            $postIds[] = (int) $postId;
        }
    }

    $indexer->flush();

    return $postIds;
}

/**
 * Run one live search probe and return timing plus result shape.
 *
 * @param array<string,mixed> $options
 * @return array<string,mixed>
 */
function wp_fts_live_benchmark_search_probe(WP_FTS_Searcher $searcher, string $label, string $query, array $options): array
{
    $start = wp_fts_live_benchmark_ms();
    $result = $searcher->search($query, $options + ['include_total' => true]);
    $elapsed = wp_fts_live_benchmark_ms() - $start;
    $rows = $result['results'] ?? [];

    return [
        'label' => $label,
        'query' => $query,
        'options' => $options,
        'total' => (int) ($result['total'] ?? count($rows)),
        'result_count' => count($rows),
        'result_ids' => array_map('intval', array_column($rows, 'doc_id')),
        'wall_time_ms' => round($elapsed, 3),
        'first_score' => isset($rows[0]['score']) ? round((float) $rows[0]['score'], 6) : null,
        'snippet_present' => isset($rows[0]['snippet']) && (string) $rows[0]['snippet'] !== '',
    ];
}

$pluginFile = WP_PLUGIN_DIR . '/indexer/indexer.php';
wp_fts_live_benchmark_assert(is_file($pluginFile), 'Mounted indexer plugin was not found', [
    'plugin_file' => $pluginFile,
]);

if (!is_plugin_active('indexer/indexer.php')) {
    $activation = activate_plugin('indexer/indexer.php');
    wp_fts_live_benchmark_assert(!is_wp_error($activation), 'Could not activate indexer plugin', [
        'error' => is_wp_error($activation) ? $activation->get_error_message() : null,
    ]);
}

wp_fts_live_benchmark_assert(class_exists('WP_FTS_Plugin'), 'Indexer plugin classes were not loaded after activation');

$sqliteEvidence = wp_fts_live_benchmark_sqlite_evidence();
$storage = WP_FTS_Plugin::storage(true);
wp_fts_live_benchmark_clear_index_tables();

$analyzer = new WP_FTS_Analyzer();
$indexer = new WP_FTS_Indexer($storage, $analyzer);
$searcher = new WP_FTS_Searcher($storage, $analyzer);
$memoryStart = memory_get_usage(true);
$peakStart = memory_get_peak_usage(true);

$insertStart = wp_fts_live_benchmark_ms();
$postIds = wp_fts_live_benchmark_index_corpus($indexer);
$indexMs = wp_fts_live_benchmark_ms() - $insertStart;
$rowCounts = wp_fts_live_benchmark_table_counts();
$directLookupDebug = wp_fts_live_benchmark_direct_lookup_debug($storage);
$languages = WP_FTS_LIVE_BENCHMARK_LANGUAGES;
$expectedDocs = WP_FTS_LIVE_BENCHMARK_DOCS_PER_LANGUAGE * count($languages);

$probes = [
    wp_fts_live_benchmark_search_probe($searcher, 'single-language-common', 'benchmarkneedle', [
        'lang' => 'en',
        'limit' => 8,
    ]),
    wp_fts_live_benchmark_search_probe($searcher, 'single-language-rare-and', 'benchmarkneedle raresignal', [
        'lang' => 'pl',
        'mode' => 'AND',
        'limit' => 8,
    ]),
    wp_fts_live_benchmark_search_probe($searcher, 'multi-language-common', 'benchmarkneedle', [
        'langs' => $languages,
        'limit' => 12,
    ]),
    wp_fts_live_benchmark_search_probe($searcher, 'snippet-metadata-window', 'benchmarkneedle stablefield', [
        'lang' => 'de',
        'mode' => 'AND',
        'limit' => 5,
        'include_metadata' => true,
        'include_snippets' => true,
    ]),
];

$probesByLabel = [];
foreach ($probes as $probe) {
    $probesByLabel[(string) $probe['label']] = $probe;
}

$gates = [
    [
        'id' => 'sqlite-runtime-detected',
        'status' => 'pass',
        'threshold' => 'SQLite signal present in Playground runtime evidence',
    ],
    [
        'id' => 'all-posts-indexed',
        'status' => $rowCounts['docs'] === $expectedDocs ? 'pass' : 'fail',
        'actual' => $rowCounts['docs'],
        'threshold' => $expectedDocs,
    ],
    [
        'id' => 'language-length-rows',
        'status' => $rowCounts['doc_lengths'] === $expectedDocs ? 'pass' : 'fail',
        'actual' => $rowCounts['doc_lengths'],
        'threshold' => $expectedDocs,
    ],
    [
        'id' => 'postings-materialized',
        'status' => $rowCounts['postings'] >= $expectedDocs * 8 ? 'pass' : 'fail',
        'actual' => $rowCounts['postings'],
        'threshold' => $expectedDocs * 8,
    ],
    [
        'id' => 'single-language-common-total',
        'status' => ($probesByLabel['single-language-common']['total'] ?? 0) === WP_FTS_LIVE_BENCHMARK_DOCS_PER_LANGUAGE ? 'pass' : 'fail',
        'actual' => $probesByLabel['single-language-common']['total'] ?? 0,
        'threshold' => WP_FTS_LIVE_BENCHMARK_DOCS_PER_LANGUAGE,
    ],
    [
        'id' => 'multi-language-common-total',
        'status' => ($probesByLabel['multi-language-common']['total'] ?? 0) === $expectedDocs ? 'pass' : 'fail',
        'actual' => $probesByLabel['multi-language-common']['total'] ?? 0,
        'threshold' => $expectedDocs,
    ],
    [
        'id' => 'snippet-metadata-present',
        'status' => !empty($probesByLabel['snippet-metadata-window']['snippet_present']) ? 'pass' : 'fail',
        'actual' => (bool) ($probesByLabel['snippet-metadata-window']['snippet_present'] ?? false),
        'threshold' => true,
    ],
    [
        'id' => 'playground-wall-time-budget',
        'status' => $indexMs <= WP_FTS_LIVE_BENCHMARK_INDEX_WALL_TIME_BUDGET_MS ? 'pass' : 'fail',
        'actual_ms' => round($indexMs, 3),
        'threshold_ms' => WP_FTS_LIVE_BENCHMARK_INDEX_WALL_TIME_BUDGET_MS,
    ],
    [
        'id' => 'playground-peak-memory-budget',
        'status' => memory_get_peak_usage(true) <= WP_FTS_LIVE_BENCHMARK_PEAK_MEMORY_BUDGET_BYTES ? 'pass' : 'fail',
        'actual_bytes' => memory_get_peak_usage(true),
        'threshold_bytes' => WP_FTS_LIVE_BENCHMARK_PEAK_MEMORY_BUDGET_BYTES,
    ],
];

$failedGates = array_values(array_filter(
    $gates,
    static fn(array $gate): bool => ($gate['status'] ?? '') !== 'pass'
));

$summary = [
    'schema_version' => 'wp-fts-live-playground-benchmark-v1',
    'status' => $failedGates === [] ? 'pass' : 'fail',
    'runtime' => [
        'php_version' => PHP_VERSION,
        'wp_version' => function_exists('get_bloginfo') ? (string) get_bloginfo('version') : null,
        'sqlite' => $sqliteEvidence,
    ],
    'fixture' => [
        'documents_per_language' => WP_FTS_LIVE_BENCHMARK_DOCS_PER_LANGUAGE,
        'languages' => $languages,
        'expected_documents' => $expectedDocs,
        'inserted_post_ids_sample' => array_slice($postIds, 0, 6),
    ],
    'row_counts' => $rowCounts,
    'direct_lookup_debug' => $directLookupDebug,
    'timings' => [
        'insert_and_index_ms' => round($indexMs, 3),
    ],
    'memory' => [
        'usage_delta_bytes' => memory_get_usage(true) - $memoryStart,
        'peak_delta_bytes' => memory_get_peak_usage(true) - $peakStart,
        'peak_bytes' => memory_get_peak_usage(true),
    ],
    'probes' => $probes,
    'gates' => $gates,
];
$artifactFile = wp_fts_live_benchmark_artifact_file();
$summary['artifact'] = [
    'output_file' => $artifactFile,
];
if ($artifactFile !== null) {
    wp_fts_live_benchmark_write_artifact($summary, $artifactFile);
}

$line = 'WP_FTS_LIVE_PLAYGROUND_BENCHMARK ' . wp_json_encode($summary);
error_log($line);
echo $line . PHP_EOL;

wp_fts_live_benchmark_assert($failedGates === [], 'Live Playground benchmark gates failed', [
    'failed_gates' => $failedGates,
]);
