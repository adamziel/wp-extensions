<?php
declare(strict_types=1);

/**
 * Isolated real-WordPress/MySQL proof for the largest accepted public inputs.
 *
 * Run this file in a fresh PHP process after the relational worst-case corpus
 * has been indexed. The caller must enforce an external process timeout: one
 * case intentionally supplies an infinite tokenizer, so a regression in the
 * analyzer stop condition must fail the process rather than hang CI forever.
 * The disposable-database marker, source/package/harness digests, database
 * limits, PHP 128 MiB limit, and Linux /proc accounting are mandatory. There
 * are no successful skips.
 */

const WP_FTS_IB_EVIDENCE_SCHEMA = 'relational-fts-isolated-boundaries-v1';
const WP_FTS_IB_EVIDENCE_FILE = 'relational-fts-isolated-boundaries.json';
const WP_FTS_IB_MANIFEST_OPTION = 'wp_fts_relational_wc_manifest';
const WP_FTS_IB_DOCUMENT_ID = 4000000001;
const WP_FTS_IB_QUEUE_FIRST_ID = 4000001001;
const WP_FTS_IB_QUEUE_ACCEPTED_COUNT = 1000;
const WP_FTS_IB_MEMORY_LIMIT_BYTES = 134217728;
const WP_FTS_IB_MAX_ENQUEUE_SQL_BYTES = 1048576;
const WP_FTS_IB_MAX_WRITE_SQL_BYTES = 4194304;

/** Capture the statements that actually cross WordPress's wpdb query filter. */
final class WP_FTS_IB_Query_Capture
{
    /** @var string[] */
    private array $queries = [];
    private bool $active = false;

    /** Install the late query filter so the proof observes executed SQL unchanged. */
    public function start(): void
    {
        if ($this->active) {
            throw new LogicException('A SQL capture cannot be started twice.');
        }
        if (!function_exists('add_filter') || !function_exists('remove_filter')) {
            throw new RuntimeException('WordPress query filters are unavailable.');
        }

        $this->active = true;
        add_filter('query', [$this, 'record'], PHP_INT_MAX, 1);
    }

    /** Retain the exact statement while preserving WordPress's filter value. */
    public function record(string $query): string
    {
        $this->queries[] = $query;

        return $query;
    }

    /** @return string[] */
    public function stop(): array
    {
        if ($this->active) {
            remove_filter('query', [$this, 'record'], PHP_INT_MAX);
            $this->active = false;
        }

        $queries = $this->queries;
        $this->queries = [];

        return $queries;
    }
}

/** An intentionally endless extension tokenizer used to prove the hard stop. */
final class WP_FTS_IB_Infinite_Cjk_Tokenizer
{
    public int $calls = 0;
    public int $yields = 0;

    /** Never terminate on its own; only the analyzer's occurrence guard may stop it. */
    public function __invoke(string $run, string $language): Generator
    {
        $this->calls++;
        while (true) {
            $this->yields++;
            yield 'wpftsinfiniteboundary';
        }
    }
}

/** Emit an exact number of distinct analyzed terms through the real indexer. */
final class WP_FTS_IB_Distinct_Term_Analyzer
{
    public int $calls = 0;
    public ?int $requested_max_occurrences = null;

    /** Configure the exact accepted or rejected distinct-term boundary. */
    public function __construct(
        private int $term_count,
        private string $term_prefix,
    ) {
    }

    /** @return array<int,array{term:string,lang:string,weight:float}> */
    public function analyze_plain_content(string $text, array $options = []): array
    {
        return $this->occurrences($options);
    }

    /** @return array<int,array{term:string,lang:string,weight:float}> */
    public function analyze_content(string $html, array $options = []): array
    {
        return $this->occurrences($options);
    }

    /** Keep fixture output deterministic across the isolated boundary cases. */
    public function index_signature(): string
    {
        return 'wp-fts-isolated-distinct-term-analyzer-v1';
    }

    /** @return array<int,array{term:string,lang:string,weight:float}> */
    private function occurrences(array $options): array
    {
        $this->calls++;
        $this->requested_max_occurrences = isset($options['_max_document_occurrences'])
            ? (int) $options['_max_document_occurrences']
            : null;
        $rows = [];
        for ($index = 0; $index < $this->term_count; $index++) {
            $rows[] = [
                'term' => $this->term_prefix . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'lang' => 'en',
                'weight' => 1.0,
            ];
        }

        return $rows;
    }
}

$evidence = wp_fts_ib_base_evidence();
$exit_status = 1;

try {
    wp_fts_ib_bootstrap_wordpress();

    $preflight_capture = new WP_FTS_IB_Query_Capture();
    $preflight_capture->start();
    try {
        $preflight = wp_fts_ib_preflight();
    } finally {
        $preflight_queries = $preflight_capture->stop();
        $evidence['preflight']['sql'] = wp_fts_ib_summarize_sql($preflight_queries);
        unset($preflight_queries);
    }

    $evidence['binding'] = $preflight['binding'];
    $evidence['runtime'] = $preflight['runtime'];
    $evidence['preflight']['fixture'] = $preflight['fixture'];
    $evidence = wp_fts_ib_run_cases($evidence);
    $evidence['status'] = wp_fts_ib_gates_pass($evidence['gates']) ? 'PASS' : 'FAIL';
    $exit_status = $evidence['status'] === 'PASS' ? 0 : 1;
} catch (Throwable $error) {
    $evidence['status'] = 'FAIL';
    $evidence['error'] = wp_fts_ib_error_row($error);
}

$evidence = wp_fts_ib_finalize_evidence($evidence);
$evidence_emitted = wp_fts_ib_emit_evidence($evidence);
if ($evidence['status'] !== 'PASS' || !$evidence_emitted) {
    $exit_status = 1;
}
exit($exit_status);

/** @return array<string,mixed> */
function wp_fts_ib_base_evidence(): array
{
    return [
        'schema' => WP_FTS_IB_EVIDENCE_SCHEMA,
        'status' => 'FAIL',
        'generated_at_gmt' => gmdate('Y-m-d\TH:i:s\Z'),
        'binding' => [
            'source_sha' => wp_fts_ib_env_or_null('WP_FTS_SOURCE_SHA'),
            'source_dirty' => wp_fts_ib_env_or_null('WP_FTS_SOURCE_DIRTY'),
            'zip_sha256' => wp_fts_ib_env_or_null('WP_FTS_ZIP_SHA256'),
            'harness_sha256' => wp_fts_ib_env_or_null('WP_FTS_HARNESS_SHA256'),
            'profile' => wp_fts_ib_env_or_null('WP_FTS_WC_PROFILE'),
            'engine' => wp_fts_ib_env_or_null('WP_FTS_WC_ENGINE'),
        ],
        'runtime' => null,
        'preflight' => ['fixture' => null, 'sql' => wp_fts_ib_empty_sql_summary()],
        'cases' => [
            'contiguous_cjk_lexical_run' => null,
            'html_markup_limits' => null,
            'infinite_custom_tokenizer' => null,
            'logical_plan_limits' => null,
            'document_distinct_terms' => null,
            'enqueue_many' => null,
        ],
        'sql' => null,
        'cleanup' => [
            'document' => null,
            'queue' => null,
        ],
        'resources' => null,
        'gates' => [],
        'error' => null,
        'evidence_sha256' => null,
    ];
}

/** Accepts only a nonempty sequence of ASCII decimal digits. */
function wp_fts_ib_is_ascii_digits(string $value): bool
{
    return $value !== '' && strspn($value, '0123456789') === strlen($value);
}

/** Accepts only a nonempty sequence of ASCII hexadecimal digits. */
function wp_fts_ib_is_ascii_hex(string $value): bool
{
    return $value !== '' && strspn($value, '0123456789abcdefABCDEF') === strlen($value);
}

/** Bootstrap only from the disposable installation named by the wrapper. */
function wp_fts_ib_bootstrap_wordpress(): void
{
    if (defined('ABSPATH') && function_exists('get_option')) {
        return;
    }

    $wp_path = getenv('WP_FTS_WP_PATH');
    if (!is_string($wp_path) || trim($wp_path) === '') {
        throw new RuntimeException('WP_FTS_WP_PATH is required; use the disposable Docker wrapper.');
    }
    $wp_load = rtrim($wp_path, '/\\') . '/wp-load.php';
    if (!is_file($wp_load)) {
        throw new RuntimeException("WordPress bootstrap was not found at {$wp_load}.");
    }

    require $wp_load;
    if (!function_exists('get_option')) {
        throw new RuntimeException('WordPress did not bootstrap correctly.');
    }
}

/** @return array{binding:array<string,mixed>,runtime:array<string,mixed>,fixture:array<string,mixed>} */
function wp_fts_ib_preflight(): array
{
    global $wpdb, $wp_version;

    if (!isset($wpdb) || !is_object($wpdb)) {
        throw new RuntimeException('WordPress loaded without $wpdb.');
    }
    if (wp_fts_ib_required_env('WP_FTS_WC_ALLOW_DISPOSABLE') !== '1') {
        throw new RuntimeException('The destructive disposable-database guard is absent.');
    }
    $wp_path = rtrim(wp_fts_ib_required_env('WP_FTS_WP_PATH'), '/\\');
    $marker = $wp_path . '/.wp-fts-relational-worst-case';
    if (!is_file($marker)) {
        throw new RuntimeException("Disposable marker is absent: {$marker}");
    }
    $evidence_directory = wp_fts_ib_required_env('WP_FTS_WC_EVIDENCE_DIR');
    wp_fts_ib_assert(is_dir($evidence_directory) && is_writable($evidence_directory), 'The isolated evidence directory must exist and be writable.');

    $source_sha = wp_fts_ib_required_env('WP_FTS_SOURCE_SHA');
    $zip_sha = wp_fts_ib_required_env('WP_FTS_ZIP_SHA256');
    $harness_sha = wp_fts_ib_required_env('WP_FTS_HARNESS_SHA256');
    $source_dirty = wp_fts_ib_required_env('WP_FTS_SOURCE_DIRTY');
    wp_fts_ib_assert(strlen($source_sha) === 40 && wp_fts_ib_is_ascii_hex($source_sha), 'WP_FTS_SOURCE_SHA must be a full Git SHA.');
    wp_fts_ib_assert(strlen($zip_sha) === 64 && wp_fts_ib_is_ascii_hex($zip_sha), 'WP_FTS_ZIP_SHA256 must be a SHA-256 digest.');
    wp_fts_ib_assert(strlen($harness_sha) === 64 && wp_fts_ib_is_ascii_hex($harness_sha), 'WP_FTS_HARNESS_SHA256 must be a SHA-256 digest.');
    wp_fts_ib_assert(hash_equals(strtolower($harness_sha), hash_file('sha256', __FILE__)), 'Mounted isolated-boundary harness does not match its source digest.');
    wp_fts_ib_assert(in_array($source_dirty, ['0', '1'], true), 'WP_FTS_SOURCE_DIRTY must be 0 or 1.');
    if ($source_dirty === '1' && getenv('WP_FTS_WC_ALLOW_DIRTY') !== '1') {
        throw new RuntimeException('Acceptance evidence cannot be created from a dirty source tree.');
    }

    foreach ([
        'WP_FTS_Plugin',
        'WP_FTS_Storage_Mysql',
        'WP_FTS_Index_Queue',
        'WP_FTS_Indexer',
        'WP_FTS_Analyzer',
        'WP_FTS_Searcher',
        'WP_FTS_Search_Budget_Exceeded',
        'WP_FTS_Analysis_Limits',
        'WP_FTS_Set_Oriented_Search_Storage',
    ] as $class) {
        if (!class_exists($class) && !interface_exists($class)) {
            throw new RuntimeException("The installed release ZIP is missing {$class}.");
        }
    }
    wp_fts_ib_assert(WP_FTS_Analysis_Limits::MAX_LEXICAL_RUN_BYTES === 4096, 'The lexical-run byte contract drifted from 4 KiB.');
    wp_fts_ib_assert(WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_TERMS === 4096, 'The analyzed distinct-term contract drifted from 4,096.');
    wp_fts_ib_assert(WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES === 4096, 'The normalized-surface contract drifted from 4,096.');
    wp_fts_ib_assert(WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_GROUPS === 12, 'The logical-group contract drifted from 12.');
    wp_fts_ib_assert(WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_ALTERNATIVES === 12, 'The query-alternative contract drifted from 12.');
    wp_fts_ib_assert(WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS === 1000, 'The enqueue-many contract drifted from 1,000.');
    wp_fts_ib_assert(WP_FTS_Storage_Mysql::MAX_DOCUMENT_POSTINGS === 8192, 'The relational document contract drifted from 8,192 postings.');
    wp_fts_ib_assert(WP_FTS_Storage_Mysql::MAX_DOCUMENT_POSTINGS === WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_TERMS + WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES, 'The relational document contract is not the lexical-plus-surface envelope.');
    wp_fts_ib_assert(WP_FTS_Storage_Mysql::MAX_BATCH_TERMS === 8192, 'The relational writer term contract drifted from 8,192 identities.');
    wp_fts_ib_assert(WP_FTS_Storage_Mysql::MAX_BATCH_POSTINGS === 50000, 'The relational writer mutation contract drifted from 50,000 postings.');

    $memory_limit_bytes = wp_fts_ib_ini_bytes((string) ini_get('memory_limit'));
    wp_fts_ib_assert($memory_limit_bytes === WP_FTS_IB_MEMORY_LIMIT_BYTES, 'PHP memory_limit must be exactly 128 MiB.');

    $database = $wpdb->get_row(
        'SELECT VERSION() AS version, @@version_comment AS comment, '
        . '@@innodb_buffer_pool_size AS buffer_pool, @@tmp_table_size AS tmp_table_size, '
        . '@@max_heap_table_size AS max_heap_table_size, @@max_connections AS max_connections, '
        . '@@innodb_flush_log_at_trx_commit AS flush_mode'
    );
    if (!is_object($database)) {
        throw new RuntimeException('Could not read the MySQL/MariaDB runtime: ' . (string) $wpdb->last_error);
    }
    $engine = wp_fts_ib_required_env('WP_FTS_WC_ENGINE');
    $identity = strtolower((string) $database->version . ' ' . (string) $database->comment);
    $version = strtolower((string) $database->version);
    if ($engine === 'mariadb-10.11') {
        wp_fts_ib_assert(
            str_contains($identity, 'mariadb')
                && version_compare($version, '10.11', '>=')
                && version_compare($version, '10.12', '<'),
            'The mariadb-10.11 lane must run MariaDB 10.11.x.'
        );
    } elseif ($engine === 'mysql-8.0') {
        wp_fts_ib_assert(
            !str_contains($identity, 'mariadb')
                && str_contains($identity, 'mysql')
                && version_compare($version, '8.0', '>=')
                && version_compare($version, '8.1', '<'),
            'The mysql-8.0 lane must run MySQL 8.0.x.'
        );
    } else {
        throw new RuntimeException("Unsupported declared database engine: {$engine}");
    }
    wp_fts_ib_assert((int) $database->buffer_pool === 268435456, 'InnoDB buffer pool must be exactly 256 MiB.');
    wp_fts_ib_assert((int) $database->tmp_table_size === 33554432, 'tmp_table_size must be exactly 32 MiB.');
    wp_fts_ib_assert((int) $database->max_heap_table_size === 33554432, 'max_heap_table_size must be exactly 32 MiB.');
    wp_fts_ib_assert((int) $database->max_connections === 24, 'max_connections must be exactly 24.');
    wp_fts_ib_assert((int) $database->flush_mode === 1, 'innodb_flush_log_at_trx_commit must remain 1.');

    $expected_tables = [
        $wpdb->prefix . 'fts_documents',
        $wpdb->prefix . 'fts_postings',
        $wpdb->prefix . 'fts_terms',
        $wpdb->prefix . 'fts_work',
    ];
    $actual_tables = array_map('strval', $wpdb->get_col($wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $wpdb->esc_like($wpdb->prefix . 'fts_') . '%'
    )) ?: []);
    sort($expected_tables, SORT_STRING);
    sort($actual_tables, SORT_STRING);
    wp_fts_ib_assert($actual_tables === $expected_tables, 'The isolated proof requires the exact four-table relational schema.');

    $manifest = get_option(WP_FTS_IB_MANIFEST_OPTION, null);
    wp_fts_ib_assert(is_array($manifest), 'The worst-case corpus manifest is absent; run this proof after corpus setup.');
    $term_prefix = wp_fts_ib_term_prefix($source_sha);
    $reserved_last = WP_FTS_IB_QUEUE_FIRST_ID + WP_FTS_IB_QUEUE_ACCEPTED_COUNT;
    $fixture = $wpdb->get_row($wpdb->prepare(
        "SELECT
            (SELECT COUNT(*) FROM {$wpdb->prefix}fts_documents) AS indexed_documents,
            (SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID BETWEEN %d AND %d) AS reserved_posts,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fts_documents WHERE post_id BETWEEN %d AND %d) AS reserved_documents,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fts_postings WHERE post_id BETWEEN %d AND %d) AS reserved_postings,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fts_work WHERE post_id BETWEEN %d AND %d) AS reserved_work,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fts_terms WHERE lang = %s AND term LIKE %s) AS reserved_terms",
        WP_FTS_IB_DOCUMENT_ID,
        $reserved_last,
        WP_FTS_IB_DOCUMENT_ID,
        $reserved_last,
        WP_FTS_IB_DOCUMENT_ID,
        $reserved_last,
        WP_FTS_IB_DOCUMENT_ID,
        $reserved_last,
        'en',
        $wpdb->esc_like($term_prefix) . '%'
    ));
    if (!is_object($fixture)) {
        throw new RuntimeException('Could not validate the isolated fixture range: ' . (string) $wpdb->last_error);
    }
    wp_fts_ib_assert((int) $fixture->indexed_documents > 0, 'The proof requires an already-indexed relational corpus.');
    foreach (['reserved_posts', 'reserved_documents', 'reserved_postings', 'reserved_work', 'reserved_terms'] as $field) {
        wp_fts_ib_assert((int) $fixture->{$field} === 0, "The isolated fixture has pre-existing {$field} rows.");
    }

    // Constructing the public production backend is deliberately schema-I/O free.
    $storage = WP_FTS_Plugin::storage(false);
    wp_fts_ib_assert($storage instanceof WP_FTS_Storage_Mysql, 'The plugin did not construct the production MySQL storage backend.');

    return [
        'binding' => [
            'source_sha' => strtolower($source_sha),
            'source_dirty' => $source_dirty === '1',
            'zip_sha256' => strtolower($zip_sha),
            'harness_sha256' => strtolower($harness_sha),
            'profile' => wp_fts_ib_required_env('WP_FTS_WC_PROFILE'),
            'engine' => $engine,
        ],
        'runtime' => [
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'wordpress_version' => isset($wp_version) ? (string) $wp_version : '',
            'database_version' => (string) $database->version,
            'database_comment' => (string) $database->comment,
            'memory_limit' => (string) ini_get('memory_limit'),
            'memory_limit_bytes' => $memory_limit_bytes,
            'pid' => getmypid(),
        ],
        'fixture' => [
            'manifest_schema' => is_scalar($manifest['schema'] ?? null) ? (string) $manifest['schema'] : '',
            'indexed_documents' => (int) $fixture->indexed_documents,
            'document_id' => WP_FTS_IB_DOCUMENT_ID,
            'queue_first_id' => WP_FTS_IB_QUEUE_FIRST_ID,
            'queue_accepted_last_id' => WP_FTS_IB_QUEUE_FIRST_ID + WP_FTS_IB_QUEUE_ACCEPTED_COUNT - 1,
            'queue_rejected_last_id' => WP_FTS_IB_QUEUE_FIRST_ID + WP_FTS_IB_QUEUE_ACCEPTED_COUNT,
            'term_prefix' => $term_prefix,
        ],
    ];
}

/** @param array<string,mixed> $evidence @return array<string,mixed> */
function wp_fts_ib_run_cases(array $evidence): array
{
    global $wpdb;

    // These cases capture only the relational writer statements. The public
    // factory was verified above; a shared writer lease would add unrelated
    // ownership SQL to the isolated boundary evidence.
    $storage = new WP_FTS_Storage_Mysql($wpdb);
    $gates = [];
    $captures = [];

    $case = wp_fts_ib_case_cjk_lexical_run($storage, $gates);
    $evidence['cases']['contiguous_cjk_lexical_run'] = $case['evidence'];
    $captures += $case['captures'];

    $case = wp_fts_ib_case_html_markup_limits($storage, $gates);
    $evidence['cases']['html_markup_limits'] = $case['evidence'];
    $captures += $case['captures'];

    $case = wp_fts_ib_case_infinite_tokenizer($storage, $gates);
    $evidence['cases']['infinite_custom_tokenizer'] = $case['evidence'];
    $captures += $case['captures'];

    $case = wp_fts_ib_case_logical_plans($storage, $gates);
    $evidence['cases']['logical_plan_limits'] = $case['evidence'];
    $captures += $case['captures'];

    $case = wp_fts_ib_case_document_terms($storage, $gates);
    $evidence['cases']['document_distinct_terms'] = $case['evidence'];
    $evidence['cleanup']['document'] = $case['cleanup'];
    $captures += $case['captures'];

    $case = wp_fts_ib_case_enqueue_many($gates);
    $evidence['cases']['enqueue_many'] = $case['evidence'];
    $evidence['cleanup']['queue'] = $case['cleanup'];
    $captures += $case['captures'];

    $aggregate_sql = wp_fts_ib_aggregate_sql($captures);
    wp_fts_ib_gate(
        $gates,
        'all_reject_paths_actual_wpdb_statement_count',
        '= 0',
        $aggregate_sql['reject_path_statement_count'],
        $aggregate_sql['reject_path_statement_count'] === 0
    );
    $evidence['gates'] = $gates;
    $evidence['sql'] = $aggregate_sql;

    return $evidence;
}

/**
 * @param array<int,array<string,mixed>> $gates
 * @return array{evidence:array<string,mixed>,captures:array<string,array{summary:array<string,mixed>,reject_path:bool}>}
 */
function wp_fts_ib_case_html_markup_limits(WP_FTS_Storage_Mysql $storage, array &$gates): array
{
    $nested_source = str_repeat('<span>', 100000) . 'boundedword' . str_repeat('</span>', 100000);
    $language_source = '<p lang="' . str_repeat('en-', 600000) . '">boundedword</p>';
    $analyzer = WP_FTS_Plugin::runtime_analyzer();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $nested_before = wp_fts_ib_resource_row(true);
    $nested = wp_fts_ib_attempt(static fn(): bool => $indexer->index_document(
        WP_FTS_IB_DOCUMENT_ID + 4,
        $nested_source,
        ['lang' => 'en', 'metadata' => ['search_text' => 'nested markup rejection fixture']]
    ));
    $nested_after = wp_fts_ib_resource_row(true);
    $nested_sql = wp_fts_ib_summarize_sql($nested['queries']);
    unset($nested['queries'], $nested['result']);

    $language_before = wp_fts_ib_resource_row(true);
    $language = wp_fts_ib_attempt(static fn(): bool => $indexer->index_document(
        WP_FTS_IB_DOCUMENT_ID + 5,
        $language_source,
        ['lang' => 'en', 'metadata' => ['search_text' => 'language attribute rejection fixture']]
    ));
    $language_after = wp_fts_ib_resource_row(true);
    $language_sql = wp_fts_ib_summarize_sql($language['queries']);
    unset($language['queries'], $language['result']);

    $nested_allocated_delta = max(
        0,
        (int) $nested_after['php_current_allocated_bytes'] - (int) $nested_before['php_current_allocated_bytes']
    );
    $language_allocated_delta = max(
        0,
        (int) $language_after['php_current_allocated_bytes'] - (int) $language_before['php_current_allocated_bytes']
    );

    wp_fts_ib_gate($gates, 'html_nested_fixture_within_source_limit', '<= 2097152 bytes', strlen($nested_source), strlen($nested_source) <= WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES);
    wp_fts_ib_gate($gates, 'html_nested_100000_rejects_as_element_depth', 'WP_FTS_Analysis_Limit_Exceeded: html_element_depth', $nested['error'], wp_fts_ib_is_analysis_limit($nested['error'], 'html_element_depth'));
    wp_fts_ib_gate($gates, 'html_nested_100000_sql_before_rejection', '= 0', $nested_sql['statement_count'], $nested_sql['statement_count'] === 0);
    wp_fts_ib_gate($gates, 'html_nested_100000_duration_ms', '<= 1000', $nested['duration_ms'], $nested['duration_ms'] <= 1000.0);
    wp_fts_ib_gate($gates, 'html_nested_100000_php_allocation_delta', '<= 16777216', $nested_allocated_delta, $nested_allocated_delta <= 16777216);
    wp_fts_ib_gate($gates, 'html_nested_100000_vmhwm_within_128_mib', '<= 134217728', $nested_after['proc_status']['VmHWM_bytes'], $nested_after['proc_status']['VmHWM_bytes'] <= WP_FTS_IB_MEMORY_LIMIT_BYTES);
    wp_fts_ib_gate($gates, 'html_nested_100000_vmrss_within_128_mib', '<= 134217728', $nested_after['proc_status']['VmRSS_bytes'], $nested_after['proc_status']['VmRSS_bytes'] <= WP_FTS_IB_MEMORY_LIMIT_BYTES);

    wp_fts_ib_gate($gates, 'html_language_fixture_within_source_limit', '<= 2097152 bytes', strlen($language_source), strlen($language_source) <= WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES);
    wp_fts_ib_gate($gates, 'html_language_1800000_rejects_as_attribute_bytes', 'WP_FTS_Analysis_Limit_Exceeded: html_language_attribute_bytes', $language['error'], wp_fts_ib_is_analysis_limit($language['error'], 'html_language_attribute_bytes'));
    wp_fts_ib_gate($gates, 'html_language_1800000_sql_before_rejection', '= 0', $language_sql['statement_count'], $language_sql['statement_count'] === 0);
    wp_fts_ib_gate($gates, 'html_language_1800000_duration_ms', '<= 1000', $language['duration_ms'], $language['duration_ms'] <= 1000.0);
    wp_fts_ib_gate($gates, 'html_language_1800000_php_allocation_delta', '<= 16777216', $language_allocated_delta, $language_allocated_delta <= 16777216);
    wp_fts_ib_gate($gates, 'html_language_1800000_vmhwm_within_128_mib', '<= 134217728', $language_after['proc_status']['VmHWM_bytes'], $language_after['proc_status']['VmHWM_bytes'] <= WP_FTS_IB_MEMORY_LIMIT_BYTES);
    wp_fts_ib_gate($gates, 'html_language_1800000_vmrss_within_128_mib', '<= 134217728', $language_after['proc_status']['VmRSS_bytes'], $language_after['proc_status']['VmRSS_bytes'] <= WP_FTS_IB_MEMORY_LIMIT_BYTES);

    return [
        'evidence' => [
            'nested_100000' => [
                'input' => ['kind' => 'nested_span_elements', 'elements' => 100000, 'bytes' => strlen($nested_source), 'sha256' => hash('sha256', $nested_source)],
                'outcome' => $nested['error'],
                'duration_ms' => $nested['duration_ms'],
                'php_allocated_delta_bytes' => $nested_allocated_delta,
                'resources_before' => $nested_before,
                'resources_after' => $nested_after,
                'sql' => $nested_sql,
            ],
            'language_attribute_1800000' => [
                'input' => ['kind' => 'oversized_html_language_attribute', 'value_bytes' => 1800000, 'bytes' => strlen($language_source), 'sha256' => hash('sha256', $language_source)],
                'outcome' => $language['error'],
                'duration_ms' => $language['duration_ms'],
                'php_allocated_delta_bytes' => $language_allocated_delta,
                'resources_before' => $language_before,
                'resources_after' => $language_after,
                'sql' => $language_sql,
            ],
        ],
        'captures' => [
            'html_nested_100000_rejected' => ['summary' => $nested_sql, 'reject_path' => true],
            'html_language_1800000_rejected' => ['summary' => $language_sql, 'reject_path' => true],
        ],
    ];
}

/**
 * @param array<int,array<string,mixed>> $gates
 * @return array{evidence:array<string,mixed>,captures:array<string,array{summary:array<string,mixed>,reject_path:bool}>}
 */
function wp_fts_ib_case_cjk_lexical_run(WP_FTS_Storage_Mysql $storage, array &$gates): array
{
    $accepted_source = str_repeat('中', 1365);
    $rejected_source = str_repeat('中', 1366);
    $analyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'default_lang' => 'zh',
        'document_lang' => 'zh',
        'enable_stemming' => false,
    ]);
    $indexer = new WP_FTS_Indexer($storage, $analyzer);

    $accepted = wp_fts_ib_attempt(static function () use ($indexer, $storage, $accepted_source): array {
        $prepared = $indexer->prepare_document_fields(WP_FTS_IB_DOCUMENT_ID + 2, [[
            'name' => 'content',
            'text' => $accepted_source,
            'boost' => 1.0,
        ]], [
            'lang' => 'zh',
            'metadata' => ['search_text' => 'isolated CJK lexical boundary'],
        ]);
        $write = $storage->replace_prepared_documents([$prepared]);

        return [
            'prepared_distinct_terms' => count($prepared['term_frequencies']),
            'prepared_distinct_surfaces' => count($prepared['surface_frequencies']),
            'analyzed_length' => array_sum($prepared['lang_lengths']),
            'write' => $write,
        ];
    });
    $accepted_sql = wp_fts_ib_summarize_sql($accepted['queries']);
    $accepted_result = is_array($accepted['result']) ? $accepted['result'] : null;
    unset($accepted['queries'], $accepted['result']);

    $rejected = wp_fts_ib_attempt(static function () use ($indexer, $storage, $rejected_source): array {
        $prepared = $indexer->prepare_document_fields(WP_FTS_IB_DOCUMENT_ID + 3, [[
            'name' => 'content',
            'text' => $rejected_source,
            'boost' => 1.0,
        ]], ['lang' => 'zh']);

        // If the production analyzer stops enforcing the lexical bound, the
        // real writer remains inside this capture and the zero-SQL gate fails.
        return $storage->replace_prepared_documents([$prepared]);
    });
    $rejected_sql = wp_fts_ib_summarize_sql($rejected['queries']);
    unset($rejected['queries'], $rejected['result']);

    $cleanup = wp_fts_ib_attempt(static function () use ($storage): array {
        global $wpdb;

        $storage->replace_prepared_documents([], [WP_FTS_IB_DOCUMENT_ID + 2, WP_FTS_IB_DOCUMENT_ID + 3]);
        $terms = ['中', '中中', '中中中', '中中中中'];
        $placeholders = implode(',', array_fill(0, count($terms), '%s'));
        $deleted_terms = $wpdb->query($wpdb->prepare(
            "DELETE dictionary FROM {$wpdb->prefix}fts_terms dictionary
             WHERE dictionary.lang = 'zh' AND dictionary.term IN ({$placeholders}) AND dictionary.doc_freq = 0
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->prefix}fts_postings retained
                   WHERE retained.term_id = dictionary.term_id
               )",
            ...$terms
        ));
        if ($deleted_terms === false) {
            throw new RuntimeException('Could not remove isolated CJK dictionary fixtures: ' . (string) $wpdb->last_error);
        }
        $remaining = $wpdb->get_row($wpdb->prepare(
            "SELECT
                (SELECT COUNT(*) FROM {$wpdb->prefix}fts_documents WHERE post_id IN (%d,%d)) AS documents,
                (SELECT COUNT(*) FROM {$wpdb->prefix}fts_postings WHERE post_id IN (%d,%d)) AS postings",
            WP_FTS_IB_DOCUMENT_ID + 2,
            WP_FTS_IB_DOCUMENT_ID + 3,
            WP_FTS_IB_DOCUMENT_ID + 2,
            WP_FTS_IB_DOCUMENT_ID + 3
        ));
        if (!is_object($remaining)) {
            throw new RuntimeException('Could not verify isolated CJK cleanup: ' . (string) $wpdb->last_error);
        }

        return [
            'deleted_zero_frequency_terms' => (int) $deleted_terms,
            'remaining_documents' => (int) $remaining->documents,
            'remaining_postings' => (int) $remaining->postings,
        ];
    });
    $cleanup_sql = wp_fts_ib_summarize_sql($cleanup['queries']);
    $cleanup_result = is_array($cleanup['result']) ? $cleanup['result'] : null;
    unset($cleanup['queries'], $cleanup['result']);

    wp_fts_ib_gate($gates, 'cjk_accepted_input_exactly_4095_bytes', '= 4095', strlen($accepted_source), strlen($accepted_source) === 4095);
    wp_fts_ib_gate($gates, 'cjk_4095_production_analyzer_indexer_accepted', 'no exception', $accepted['error'], !$accepted['error']['thrown']);
    wp_fts_ib_gate($gates, 'cjk_4095_distinct_terms_bounded', '= 4', $accepted_result['prepared_distinct_terms'] ?? null, ($accepted_result['prepared_distinct_terms'] ?? null) === 4);
    wp_fts_ib_gate($gates, 'cjk_4095_occurrences_bounded', '= 5454', $accepted_result['analyzed_length'] ?? null, ($accepted_result['analyzed_length'] ?? null) === 5454);
    wp_fts_ib_gate($gates, 'cjk_4095_writer_replaced_document', '= 1', $accepted_result['write']['replaced'] ?? null, ($accepted_result['write']['replaced'] ?? null) === 1);
    wp_fts_ib_gate($gates, 'cjk_4095_writer_terms_and_postings', '= 4 lexical + 2 surface terms and 6 postings', $accepted_result, ($accepted_result['prepared_distinct_terms'] ?? null) === 4
        && ($accepted_result['prepared_distinct_surfaces'] ?? null) === 2
        && ($accepted_result['write']['terms'] ?? null) === 6
        && ($accepted_result['write']['postings'] ?? null) === 6);
    wp_fts_ib_gate($gates, 'cjk_4095_writer_statement_count', '1..10 including transaction control', $accepted_sql['statement_count'], $accepted_sql['statement_count'] >= 1 && $accepted_sql['statement_count'] <= 10);
    wp_fts_ib_gate($gates, 'cjk_4095_writer_sql_bytes', '<= 4194304 per statement', $accepted_sql['max_statement_bytes'], $accepted_sql['max_statement_bytes'] <= WP_FTS_IB_MAX_WRITE_SQL_BYTES);
    wp_fts_ib_gate($gates, 'cjk_rejected_input_is_above_4096_bytes', '> 4096', strlen($rejected_source), strlen($rejected_source) > 4096);
    wp_fts_ib_gate($gates, 'cjk_above_4k_rejects_as_lexical_run_bytes', 'WP_FTS_Analysis_Limit_Exceeded: lexical_run_bytes', $rejected['error'], wp_fts_ib_is_analysis_limit($rejected['error'], 'lexical_run_bytes'));
    wp_fts_ib_gate($gates, 'cjk_above_4k_sql_before_rejection', '= 0', $rejected_sql['statement_count'], $rejected_sql['statement_count'] === 0);
    wp_fts_ib_gate($gates, 'cjk_fixture_cleanup_completed', 'no exception', $cleanup['error'], !$cleanup['error']['thrown']);
    wp_fts_ib_gate($gates, 'cjk_fixture_cleanup_rows', '0 documents and postings', $cleanup_result, is_array($cleanup_result)
        && ($cleanup_result['remaining_documents'] ?? -1) === 0
        && ($cleanup_result['remaining_postings'] ?? -1) === 0);

    return [
        'evidence' => [
            'accepted_4095' => [
                'input' => ['kind' => 'contiguous_han_lexical_run', 'code_points' => 1365, 'bytes' => strlen($accepted_source), 'sha256' => hash('sha256', $accepted_source)],
                'outcome' => $accepted['error'],
                'duration_ms' => $accepted['duration_ms'],
                'production_path' => [WP_FTS_Analyzer::class, WP_FTS_Indexer::class, WP_FTS_Storage_Mysql::class],
                'result' => $accepted_result,
                'sql' => $accepted_sql,
            ],
            'rejected_above_4k' => [
                'input' => ['kind' => 'contiguous_han_lexical_run', 'code_points' => 1366, 'bytes' => strlen($rejected_source), 'sha256' => hash('sha256', $rejected_source)],
                'outcome' => $rejected['error'],
                'duration_ms' => $rejected['duration_ms'],
                'sql' => $rejected_sql,
            ],
            'cleanup' => [
                'outcome' => $cleanup['error'],
                'duration_ms' => $cleanup['duration_ms'],
                'result' => $cleanup_result,
                'sql' => $cleanup_sql,
            ],
        ],
        'captures' => [
            'cjk_4095_accepted' => ['summary' => $accepted_sql, 'reject_path' => false],
            'cjk_above_4k_rejected' => ['summary' => $rejected_sql, 'reject_path' => true],
            'cjk_cleanup' => ['summary' => $cleanup_sql, 'reject_path' => false],
        ],
    ];
}

/**
 * @param array<int,array<string,mixed>> $gates
 * @return array{evidence:array<string,mixed>,captures:array<string,array{summary:array<string,mixed>,reject_path:bool}>}
 */
function wp_fts_ib_case_infinite_tokenizer(WP_FTS_Storage_Mysql $storage, array &$gates): array
{
    $probe = new WP_FTS_IB_Infinite_Cjk_Tokenizer();
    $analyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'default_lang' => 'zh',
        'query_lang' => 'zh',
        'enable_stemming' => false,
        'cjk_tokenizer' => $probe,
    ]);
    $searcher = WP_FTS_Searcher::for_set_oriented_storage($storage, $analyzer);
    $attempt = wp_fts_ib_attempt(static fn(): array => $searcher->search('中文', [
        'query_lang' => 'zh',
        'limit' => 1,
        'prefix_matching' => false,
    ]));
    $sql = wp_fts_ib_summarize_sql($attempt['queries']);
    unset($attempt['queries'], $attempt['result']);

    wp_fts_ib_gate($gates, 'infinite_tokenizer_rejected_by_occurrence_limit', 'WP_FTS_Search_Budget_Exceeded: analyzer occurrences', $attempt['error'], wp_fts_ib_is_budget($attempt['error'], 'analyzer occurrences'));
    wp_fts_ib_gate($gates, 'infinite_tokenizer_call_count', '= 1', $probe->calls, $probe->calls === 1);
    wp_fts_ib_gate($gates, 'infinite_tokenizer_consumed_occurrences', '= 13 (12 accepted plus rejecting row)', $probe->yields, $probe->yields === 13);
    wp_fts_ib_gate($gates, 'infinite_tokenizer_storage_sql', '= 0', $sql['statement_count'], $sql['statement_count'] === 0);

    return [
        'evidence' => [
            'input' => ['query' => '中文', 'bytes' => strlen('中文'), 'tokenizer_result' => 'non-terminating Generator'],
            'configured_max_query_occurrences' => WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_ALTERNATIVES,
            'tokenizer' => ['class' => WP_FTS_IB_Infinite_Cjk_Tokenizer::class, 'calls' => $probe->calls, 'yields_before_stop' => $probe->yields],
            'outcome' => $attempt['error'],
            'duration_ms' => $attempt['duration_ms'],
            'sql' => $sql,
        ],
        'captures' => [
            'infinite_tokenizer_rejected' => ['summary' => $sql, 'reject_path' => true],
        ],
    ];
}

/**
 * @param array<int,array<string,mixed>> $gates
 * @return array{evidence:array<string,mixed>,captures:array<string,array{summary:array<string,mixed>,reject_path:bool}>}
 */
function wp_fts_ib_case_logical_plans(WP_FTS_Storage_Mysql $storage, array &$gates): array
{
    $analyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'default_lang' => 'en',
        'query_lang' => 'en',
        'enable_stemming' => false,
    ]);
    $searcher = WP_FTS_Searcher::for_set_oriented_storage($storage, $analyzer);
    $terms = [];
    for ($index = 0; $index < 13; $index++) {
        $terms[] = 'wpftsibgroup' . chr(97 + $index) . chr(97 + $index);
    }
    $query_12 = implode(' ', array_slice($terms, 0, 12));
    $query_13 = implode(' ', $terms);

    $groups_12 = wp_fts_ib_attempt(static fn(): array => $searcher->search($query_12, [
        'query_lang' => 'en',
        'limit' => 1,
        'prefix_matching' => false,
    ]));
    $groups_12_sql = wp_fts_ib_summarize_sql($groups_12['queries']);
    $groups_12_page = wp_fts_ib_page_row($groups_12['result']);
    unset($groups_12['queries'], $groups_12['result']);

    $groups_13 = wp_fts_ib_attempt(static fn(): array => $searcher->search($query_13, [
        'query_lang' => 'en',
        'limit' => 1,
        'prefix_matching' => false,
    ]));
    $groups_13_sql = wp_fts_ib_summarize_sql($groups_13['queries']);
    unset($groups_13['queries'], $groups_13['result']);

    $alternative_candidates = [];
    for ($index = 0; $index < 13; $index++) {
        $term = 'wpftsibalternative' . chr(97 + $index) . chr(97 + $index);
        $alternative_candidates[] = [
            'key' => WP_FTS_TermNamespace::namespace_term('en', $term),
            'lang' => 'en',
            'term' => $term,
            'rank' => $index,
        ];
    }
    $storage_options = [
        'mode' => 'OR',
        'page_size' => 1,
        'limit' => 2,
        'query_lang' => 'en',
        'prefix_matching' => false,
        'include_metadata' => false,
        'include_snippets' => false,
    ];
    $alternatives_12 = wp_fts_ib_attempt(static fn(): array => $storage->search_page([
        array_slice($alternative_candidates, 0, 12),
    ], $storage_options));
    $alternatives_12_sql = wp_fts_ib_summarize_sql($alternatives_12['queries']);
    $alternatives_12_page = wp_fts_ib_page_row($alternatives_12['result']);
    unset($alternatives_12['queries'], $alternatives_12['result']);

    // Keep both groups under the per-group limit so the aggregate 13th
    // alternative, rather than the per-group guard, is the rejecting boundary.
    $alternatives_13 = wp_fts_ib_attempt(static fn(): array => $storage->search_page([
        array_slice($alternative_candidates, 0, 6),
        array_slice($alternative_candidates, 6, 7),
    ], $storage_options));
    $alternatives_13_sql = wp_fts_ib_summarize_sql($alternatives_13['queries']);
    unset($alternatives_13['queries'], $alternatives_13['result']);

    wp_fts_ib_gate($gates, 'logical_groups_12_accepted', 'no exception', $groups_12['error'], !$groups_12['error']['thrown']);
    wp_fts_ib_gate($gates, 'logical_groups_12_statement_count', '= 1 planning statement for impossible fixture terms', $groups_12_sql['statement_count'], $groups_12_sql['statement_count'] === 1);
    wp_fts_ib_gate($gates, 'logical_groups_13_rejected', 'WP_FTS_Search_Budget_Exceeded: logical query groups', $groups_13['error'], wp_fts_ib_is_budget($groups_13['error'], 'logical query groups'));
    wp_fts_ib_gate($gates, 'logical_groups_13_sql_before_rejection', '= 0', $groups_13_sql['statement_count'], $groups_13_sql['statement_count'] === 0);
    wp_fts_ib_gate($gates, 'query_alternatives_12_accepted', 'no exception', $alternatives_12['error'], !$alternatives_12['error']['thrown']);
    wp_fts_ib_gate($gates, 'query_alternatives_12_statement_count', '= 1 planning statement for impossible fixture terms', $alternatives_12_sql['statement_count'], $alternatives_12_sql['statement_count'] === 1);
    wp_fts_ib_gate($gates, 'query_alternatives_13_rejected', 'WP_FTS_Search_Budget_Exceeded: analyzed alternatives', $alternatives_13['error'], wp_fts_ib_is_budget($alternatives_13['error'], 'analyzed alternatives'));
    wp_fts_ib_gate($gates, 'query_alternatives_13_sql_before_rejection', '= 0', $alternatives_13_sql['statement_count'], $alternatives_13_sql['statement_count'] === 0);

    return [
        'evidence' => [
            'groups_12' => [
                'input' => ['logical_units' => 12, 'bytes' => strlen($query_12), 'sha256' => hash('sha256', $query_12)],
                'outcome' => $groups_12['error'],
                'duration_ms' => $groups_12['duration_ms'],
                'page' => $groups_12_page,
                'sql' => $groups_12_sql,
            ],
            'groups_13' => [
                'input' => ['logical_units' => 13, 'bytes' => strlen($query_13), 'sha256' => hash('sha256', $query_13)],
                'outcome' => $groups_13['error'],
                'duration_ms' => $groups_13['duration_ms'],
                'sql' => $groups_13_sql,
            ],
            'alternatives_12' => [
                'input' => ['groups' => 1, 'alternatives_by_group' => [12], 'alternatives_total' => 12],
                'outcome' => $alternatives_12['error'],
                'duration_ms' => $alternatives_12['duration_ms'],
                'page' => $alternatives_12_page,
                'sql' => $alternatives_12_sql,
            ],
            'alternatives_13' => [
                'input' => ['groups' => 2, 'alternatives_by_group' => [6, 7], 'alternatives_total' => 13],
                'outcome' => $alternatives_13['error'],
                'duration_ms' => $alternatives_13['duration_ms'],
                'sql' => $alternatives_13_sql,
            ],
        ],
        'captures' => [
            'logical_groups_12_accepted' => ['summary' => $groups_12_sql, 'reject_path' => false],
            'logical_groups_13_rejected' => ['summary' => $groups_13_sql, 'reject_path' => true],
            'query_alternatives_12_accepted' => ['summary' => $alternatives_12_sql, 'reject_path' => false],
            'query_alternatives_13_rejected' => ['summary' => $alternatives_13_sql, 'reject_path' => true],
        ],
    ];
}

/**
 * @param array<int,array<string,mixed>> $gates
 * @return array{evidence:array<string,mixed>,cleanup:array<string,mixed>,captures:array<string,array{summary:array<string,mixed>,reject_path:bool}>}
 */
function wp_fts_ib_case_document_terms(WP_FTS_Storage_Mysql $storage, array &$gates): array
{
    $term_prefix = wp_fts_ib_term_prefix(wp_fts_ib_required_env('WP_FTS_SOURCE_SHA'));
    $accepted_analyzer = new WP_FTS_IB_Distinct_Term_Analyzer(4096, $term_prefix);
    $accepted_indexer = new WP_FTS_Indexer($storage, $accepted_analyzer);
    $accepted = wp_fts_ib_attempt(static function () use ($accepted_indexer, $storage): array {
        $prepared = $accepted_indexer->prepare_document_fields(WP_FTS_IB_DOCUMENT_ID, [[
            'name' => 'content',
            'text' => 'isolated 4096-distinct-term boundary',
            'boost' => 1.0,
        ]], [
            'lang' => 'en',
            'metadata' => ['search_text' => 'isolated distinct-term boundary'],
        ]);
        $prepared_terms = count($prepared['term_frequencies']);
        $write = $storage->replace_prepared_documents([$prepared]);

        return ['prepared_distinct_terms' => $prepared_terms, 'write' => $write];
    });
    $accepted_sql = wp_fts_ib_summarize_sql($accepted['queries']);
    $accepted_result = is_array($accepted['result']) ? $accepted['result'] : null;
    unset($accepted['queries'], $accepted['result']);

    $rejected_analyzer = new WP_FTS_IB_Distinct_Term_Analyzer(4097, $term_prefix . 'reject');
    $rejected_indexer = new WP_FTS_Indexer($storage, $rejected_analyzer);
    $rejected = wp_fts_ib_attempt(static function () use ($rejected_indexer, $storage): array {
        $prepared = $rejected_indexer->prepare_document_fields(WP_FTS_IB_DOCUMENT_ID + 1, [[
            'name' => 'content',
            'text' => 'isolated 4097-distinct-term boundary',
            'boost' => 1.0,
        ]], ['lang' => 'en']);

        // If analysis ever stops enforcing the limit, the real storage writer
        // is still inside this capture and the zero-SQL rejection gate fails.
        return $storage->replace_prepared_documents([$prepared]);
    });
    $rejected_sql = wp_fts_ib_summarize_sql($rejected['queries']);
    unset($rejected['queries'], $rejected['result']);

    $cleanup_attempt = wp_fts_ib_attempt(static function () use ($storage, $term_prefix): array {
        global $wpdb;

        $storage->replace_prepared_documents([], [WP_FTS_IB_DOCUMENT_ID, WP_FTS_IB_DOCUMENT_ID + 1]);
        $deleted_terms = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}fts_terms WHERE lang = %s AND term LIKE %s AND doc_freq = 0",
            'en',
            $wpdb->esc_like($term_prefix) . '%'
        ));
        if ($deleted_terms === false) {
            throw new RuntimeException('Could not remove isolated dictionary fixtures: ' . (string) $wpdb->last_error);
        }
        $remaining = $wpdb->get_row($wpdb->prepare(
            "SELECT
                (SELECT COUNT(*) FROM {$wpdb->prefix}fts_documents WHERE post_id IN (%d,%d)) AS documents,
                (SELECT COUNT(*) FROM {$wpdb->prefix}fts_postings WHERE post_id IN (%d,%d)) AS postings,
                (SELECT COUNT(*) FROM {$wpdb->prefix}fts_terms WHERE lang = %s AND term LIKE %s) AS terms",
            WP_FTS_IB_DOCUMENT_ID,
            WP_FTS_IB_DOCUMENT_ID + 1,
            WP_FTS_IB_DOCUMENT_ID,
            WP_FTS_IB_DOCUMENT_ID + 1,
            'en',
            $wpdb->esc_like($term_prefix) . '%'
        ));
        if (!is_object($remaining)) {
            throw new RuntimeException('Could not verify isolated document cleanup: ' . (string) $wpdb->last_error);
        }

        return [
            'deleted_zero_frequency_terms' => (int) $deleted_terms,
            'remaining_documents' => (int) $remaining->documents,
            'remaining_postings' => (int) $remaining->postings,
            'remaining_terms' => (int) $remaining->terms,
        ];
    });
    $cleanup_sql = wp_fts_ib_summarize_sql($cleanup_attempt['queries']);
    $cleanup_result = is_array($cleanup_attempt['result']) ? $cleanup_attempt['result'] : null;
    unset($cleanup_attempt['queries'], $cleanup_attempt['result']);

    $accepted_write = is_array($accepted_result['write'] ?? null) ? $accepted_result['write'] : [];
    wp_fts_ib_gate($gates, 'document_4096_distinct_terms_accepted', 'no exception', $accepted['error'], !$accepted['error']['thrown']);
    wp_fts_ib_gate($gates, 'document_4096_prepared_term_count', '= 4096', $accepted_result['prepared_distinct_terms'] ?? null, ($accepted_result['prepared_distinct_terms'] ?? null) === 4096);
    wp_fts_ib_gate($gates, 'document_4096_writer_term_count', '= 4096', $accepted_write['terms'] ?? null, ($accepted_write['terms'] ?? null) === 4096);
    wp_fts_ib_gate($gates, 'document_4096_writer_posting_count', '= 4096', $accepted_write['postings'] ?? null, ($accepted_write['postings'] ?? null) === 4096);
    wp_fts_ib_gate($gates, 'document_4096_writer_statement_count', '1..10 including transaction control', $accepted_sql['statement_count'], $accepted_sql['statement_count'] >= 1 && $accepted_sql['statement_count'] <= 10);
    wp_fts_ib_gate($gates, 'document_4096_writer_max_sql_bytes', '<= 4194304', $accepted_sql['max_statement_bytes'], $accepted_sql['max_statement_bytes'] <= WP_FTS_IB_MAX_WRITE_SQL_BYTES);
    wp_fts_ib_gate($gates, 'document_4097_distinct_terms_rejected', 'WP_FTS_Analysis_Limit_Exceeded: distinct_terms', $rejected['error'], wp_fts_ib_is_analysis_limit($rejected['error'], 'distinct_terms'));
    wp_fts_ib_gate($gates, 'document_4097_sql_before_rejection', '= 0', $rejected_sql['statement_count'], $rejected_sql['statement_count'] === 0);
    wp_fts_ib_gate($gates, 'document_fixture_cleanup_completed', 'no exception', $cleanup_attempt['error'], !$cleanup_attempt['error']['thrown']);
    wp_fts_ib_gate($gates, 'document_fixture_cleanup_rows', '0 documents, postings, and prefixed terms', $cleanup_result, is_array($cleanup_result)
        && ($cleanup_result['remaining_documents'] ?? -1) === 0
        && ($cleanup_result['remaining_postings'] ?? -1) === 0
        && ($cleanup_result['remaining_terms'] ?? -1) === 0);

    return [
        'evidence' => [
            'accepted_4096' => [
                'input' => ['document_id' => WP_FTS_IB_DOCUMENT_ID, 'analyzed_occurrences' => 4096, 'distinct_terms' => 4096, 'term_prefix' => $term_prefix],
                'analyzer' => ['class' => WP_FTS_IB_Distinct_Term_Analyzer::class, 'calls' => $accepted_analyzer->calls, 'requested_max_occurrences' => $accepted_analyzer->requested_max_occurrences],
                'outcome' => $accepted['error'],
                'duration_ms' => $accepted['duration_ms'],
                'result' => $accepted_result,
                'sql' => $accepted_sql,
            ],
            'rejected_4097' => [
                'input' => ['document_id' => WP_FTS_IB_DOCUMENT_ID + 1, 'analyzed_occurrences' => 4097, 'distinct_terms' => 4097, 'term_prefix' => $term_prefix . 'reject'],
                'analyzer' => ['class' => WP_FTS_IB_Distinct_Term_Analyzer::class, 'calls' => $rejected_analyzer->calls, 'requested_max_occurrences' => $rejected_analyzer->requested_max_occurrences],
                'outcome' => $rejected['error'],
                'duration_ms' => $rejected['duration_ms'],
                'sql' => $rejected_sql,
            ],
        ],
        'cleanup' => [
            'outcome' => $cleanup_attempt['error'],
            'duration_ms' => $cleanup_attempt['duration_ms'],
            'result' => $cleanup_result,
            'sql' => $cleanup_sql,
        ],
        'captures' => [
            'document_4096_accepted' => ['summary' => $accepted_sql, 'reject_path' => false],
            'document_4097_rejected' => ['summary' => $rejected_sql, 'reject_path' => true],
            'document_cleanup' => ['summary' => $cleanup_sql, 'reject_path' => false],
        ],
    ];
}

/**
 * @param array<int,array<string,mixed>> $gates
 * @return array{evidence:array<string,mixed>,cleanup:array<string,mixed>,captures:array<string,array{summary:array<string,mixed>,reject_path:bool}>}
 */
function wp_fts_ib_case_enqueue_many(array &$gates): array
{
    global $wpdb;

    $queue = new WP_FTS_Index_Queue($wpdb);
    $accepted_ids = range(WP_FTS_IB_QUEUE_FIRST_ID, WP_FTS_IB_QUEUE_FIRST_ID + WP_FTS_IB_QUEUE_ACCEPTED_COUNT - 1);
    $rejected_ids = range(WP_FTS_IB_QUEUE_FIRST_ID, WP_FTS_IB_QUEUE_FIRST_ID + WP_FTS_IB_QUEUE_ACCEPTED_COUNT);
    $payload = ['source' => 'relational-fts-isolated-boundaries-v1'];

    $accepted = wp_fts_ib_attempt(static fn(): int => $queue->enqueue_many($accepted_ids, 1700000000, $payload));
    $accepted_sql = wp_fts_ib_summarize_sql($accepted['queries']);
    $accepted_result = $accepted['result'];
    unset($accepted['queries'], $accepted['result']);

    $rejected = wp_fts_ib_attempt(static fn(): int => $queue->enqueue_many($rejected_ids, 1700000000, $payload));
    $rejected_sql = wp_fts_ib_summarize_sql($rejected['queries']);
    unset($rejected['queries'], $rejected['result']);

    $cleanup_attempt = wp_fts_ib_attempt(static function (): array {
        global $wpdb;

        $last_id = WP_FTS_IB_QUEUE_FIRST_ID + WP_FTS_IB_QUEUE_ACCEPTED_COUNT - 1;
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}fts_work WHERE kind = 'post' AND post_id BETWEEN %d AND %d",
            WP_FTS_IB_QUEUE_FIRST_ID,
            $last_id
        ));
        if ($deleted === false) {
            throw new RuntimeException('Could not remove isolated queue fixtures: ' . (string) $wpdb->last_error);
        }
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fts_work WHERE post_id BETWEEN %d AND %d",
            WP_FTS_IB_QUEUE_FIRST_ID,
            $last_id
        ));

        return ['deleted' => (int) $deleted, 'remaining' => $remaining];
    });
    $cleanup_sql = wp_fts_ib_summarize_sql($cleanup_attempt['queries']);
    $cleanup_result = is_array($cleanup_attempt['result']) ? $cleanup_attempt['result'] : null;
    unset($cleanup_attempt['queries'], $cleanup_attempt['result']);

    $accepted_statement = $accepted_sql['statements'][0] ?? null;
    $accepted_is_one_work_upsert = is_array($accepted_statement)
        && ($accepted_statement['type'] ?? null) === 'UPSERT'
        && in_array('fts_work', $accepted_statement['table_roles'] ?? [], true);
    wp_fts_ib_gate($gates, 'enqueue_many_1000_return_count', '= 1000', $accepted_result, $accepted_result === 1000);
    wp_fts_ib_gate($gates, 'enqueue_many_1000_statement_count', '= 1', $accepted_sql['statement_count'], $accepted_sql['statement_count'] === 1);
    wp_fts_ib_gate($gates, 'enqueue_many_1000_statement_shape', 'one fts_work UPSERT', $accepted_statement, $accepted_is_one_work_upsert);
    wp_fts_ib_gate($gates, 'enqueue_many_1000_statement_bytes', '<= 1048576', $accepted_sql['max_statement_bytes'], $accepted_sql['max_statement_bytes'] <= WP_FTS_IB_MAX_ENQUEUE_SQL_BYTES);
    wp_fts_ib_gate($gates, 'enqueue_many_1001_rejected', 'InvalidArgumentException', $rejected['error'], $rejected['error']['thrown'] && $rejected['error']['class'] === InvalidArgumentException::class);
    wp_fts_ib_gate($gates, 'enqueue_many_1001_sql_before_rejection', '= 0', $rejected_sql['statement_count'], $rejected_sql['statement_count'] === 0);
    wp_fts_ib_gate($gates, 'queue_fixture_cleanup_completed', 'no exception', $cleanup_attempt['error'], !$cleanup_attempt['error']['thrown']);
    wp_fts_ib_gate($gates, 'queue_fixture_cleanup_rows', 'deleted = 1000 and remaining = 0', $cleanup_result, is_array($cleanup_result)
        && ($cleanup_result['deleted'] ?? -1) === 1000
        && ($cleanup_result['remaining'] ?? -1) === 0);

    return [
        'evidence' => [
            'accepted_1000' => [
                'input' => ['count' => count($accepted_ids), 'first_id' => $accepted_ids[0], 'last_id' => $accepted_ids[array_key_last($accepted_ids)], 'payload_bytes' => strlen(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))],
                'outcome' => $accepted['error'],
                'duration_ms' => $accepted['duration_ms'],
                'accepted_count' => $accepted_result,
                'sql' => $accepted_sql,
            ],
            'rejected_1001' => [
                'input' => ['count' => count($rejected_ids), 'first_id' => $rejected_ids[0], 'last_id' => $rejected_ids[array_key_last($rejected_ids)], 'payload_bytes' => strlen(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))],
                'outcome' => $rejected['error'],
                'duration_ms' => $rejected['duration_ms'],
                'sql' => $rejected_sql,
            ],
        ],
        'cleanup' => [
            'outcome' => $cleanup_attempt['error'],
            'duration_ms' => $cleanup_attempt['duration_ms'],
            'result' => $cleanup_result,
            'sql' => $cleanup_sql,
        ],
        'captures' => [
            'enqueue_many_1000_accepted' => ['summary' => $accepted_sql, 'reject_path' => false],
            'enqueue_many_1001_rejected' => ['summary' => $rejected_sql, 'reject_path' => true],
            'queue_cleanup' => ['summary' => $cleanup_sql, 'reject_path' => false],
        ],
    ];
}

/** @return array{result:mixed,error:array<string,mixed>,duration_ms:float,queries:string[]} */
function wp_fts_ib_attempt(callable $operation): array
{
    $capture = new WP_FTS_IB_Query_Capture();
    $capture->start();
    $started = hrtime(true);
    $result = null;
    $error = null;
    try {
        $result = $operation();
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        $elapsed_ms = round((hrtime(true) - $started) / 1000000, 3);
        $queries = $capture->stop();
    }

    return [
        'result' => $result,
        'error' => $error === null ? wp_fts_ib_no_error_row() : wp_fts_ib_error_row($error),
        'duration_ms' => $elapsed_ms,
        'queries' => $queries,
    ];
}

/** @return array{thrown:bool,class:?string,message:?string,budget:?string,reason_code:?string,post_id:?int} */
function wp_fts_ib_no_error_row(): array
{
    return [
        'thrown' => false,
        'class' => null,
        'message' => null,
        'budget' => null,
        'reason_code' => null,
        'post_id' => null,
    ];
}

/** @return array{thrown:bool,class:string,message:string,budget:?string,reason_code:?string,post_id:?int} */
function wp_fts_ib_error_row(Throwable $error): array
{
    $budget = $error instanceof WP_FTS_Search_Budget_Exceeded
        ? $error->budget()
        : null;
    $reason_code = property_exists($error, 'reason_code') && is_scalar($error->reason_code)
        ? (string) $error->reason_code
        : null;
    $post_id = property_exists($error, 'post_id') && is_scalar($error->post_id)
        ? (int) $error->post_id
        : null;

    return [
        'thrown' => true,
        'class' => get_class($error),
        'message' => wp_fts_ib_truncate($error->getMessage(), 512),
        'budget' => $budget,
        'reason_code' => $reason_code,
        'post_id' => $post_id,
    ];
}

/** @param array<string,mixed> $error */
function wp_fts_ib_is_budget(array $error, string $budget): bool
{
    return ($error['thrown'] ?? false) === true
        && ($error['class'] ?? null) === WP_FTS_Search_Budget_Exceeded::class
        && ($error['budget'] ?? null) === $budget;
}

/** @param array<string,mixed> $error */
function wp_fts_ib_is_analysis_limit(array $error, string $reason_code): bool
{
    return ($error['thrown'] ?? false) === true
        && ($error['class'] ?? null) === WP_FTS_Analysis_Limit_Exceeded::class
        && ($error['reason_code'] ?? null) === $reason_code;
}

/** @return array{result_count:int,has_more:?bool,total_relation:?string,query_lang:?string} */
function wp_fts_ib_page_row(mixed $page): array
{
    if (!is_array($page)) {
        return ['result_count' => 0, 'has_more' => null, 'total_relation' => null, 'query_lang' => null];
    }
    $results = isset($page['results']) && is_array($page['results']) ? $page['results'] : $page;

    return [
        'result_count' => count($results),
        'has_more' => array_key_exists('has_more', $page) ? (bool) $page['has_more'] : null,
        'total_relation' => is_scalar($page['total_relation'] ?? null) ? (string) $page['total_relation'] : null,
        'query_lang' => is_scalar($page['query_lang'] ?? null) ? (string) $page['query_lang'] : null,
    ];
}

/** @param string[] $queries @return array<string,mixed> */
function wp_fts_ib_summarize_sql(array $queries): array
{
    global $wpdb;

    $statements = [];
    $total_bytes = 0;
    $max_bytes = 0;
    foreach (array_values($queries) as $ordinal => $query) {
        $bytes = strlen($query);
        $total_bytes += $bytes;
        $max_bytes = max($max_bytes, $bytes);
        $statements[] = [
            'ordinal' => $ordinal + 1,
            'bytes' => $bytes,
            'sha256' => hash('sha256', $query),
            'tag' => wp_fts_ib_sql_tag($query),
            'type' => wp_fts_ib_sql_type($query),
            'table_roles' => wp_fts_ib_sql_table_roles($query, (string) ($wpdb->prefix ?? '')),
        ];
    }

    return [
        'statement_count' => count($statements),
        'total_statement_bytes' => $total_bytes,
        'max_statement_bytes' => $max_bytes,
        'statements' => $statements,
    ];
}

/** @return array{statement_count:int,total_statement_bytes:int,max_statement_bytes:int,statements:array<int,mixed>} */
function wp_fts_ib_empty_sql_summary(): array
{
    return [
        'statement_count' => 0,
        'total_statement_bytes' => 0,
        'max_statement_bytes' => 0,
        'statements' => [],
    ];
}

/** Extract the plugin statement tag without accepting an unterminated comment. */
function wp_fts_ib_sql_tag(string $sql): string
{
    $start = strpos($sql, '/* wp_fts:');
    if ($start === false) {
        return 'untagged';
    }
    $end = strpos($sql, '*/', $start + 3);
    if ($end === false) {
        return 'malformed';
    }

    return trim(substr($sql, $start + 3, $end - ($start + 3)));
}

/** Classify SQL after leading comments so tagged statements retain their real role. */
function wp_fts_ib_sql_type(string $sql): string
{
    $remaining = ltrim($sql);
    while (str_starts_with($remaining, '/*')) {
        $comment_end = strpos($remaining, '*/');
        if ($comment_end === false) {
            return 'UNKNOWN';
        }
        $remaining = ltrim(substr($remaining, $comment_end + 2));
    }
    $word = '';
    $length = strlen($remaining);
    for ($index = 0; $index < $length; $index++) {
        $character = $remaining[$index];
        if (!(($character >= 'A' && $character <= 'Z') || ($character >= 'a' && $character <= 'z'))) {
            break;
        }
        $word .= $character;
    }
    $word = strtoupper($word === '' ? 'UNKNOWN' : $word);
    if ($word === 'INSERT' && stripos($remaining, 'ON DUPLICATE KEY UPDATE') !== false) {
        return 'UPSERT';
    }

    return $word;
}

/** @return string[] */
function wp_fts_ib_sql_table_roles(string $sql, string $prefix): array
{
    $roles = [];
    foreach ([
        'fts_terms' => $prefix . 'fts_terms',
        'fts_postings' => $prefix . 'fts_postings',
        'fts_documents' => $prefix . 'fts_documents',
        'fts_work' => $prefix . 'fts_work',
        'wp_posts' => $prefix . 'posts',
    ] as $role => $table) {
        if ($table !== '' && stripos($sql, $table) !== false) {
            $roles[] = $role;
        }
    }

    return $roles;
}

/**
 * @param array<string,array{summary:array<string,mixed>,reject_path:bool}> $captures
 * @return array<string,mixed>
 */
function wp_fts_ib_aggregate_sql(array $captures): array
{
    $statement_count = 0;
    $total_bytes = 0;
    $max_bytes = 0;
    $reject_statements = 0;
    $by_tag = [];
    $by_type = [];
    $by_role = [];
    $by_capture = [];
    foreach ($captures as $name => $capture) {
        $summary = $capture['summary'];
        $count = (int) ($summary['statement_count'] ?? 0);
        $statement_count += $count;
        $total_bytes += (int) ($summary['total_statement_bytes'] ?? 0);
        $max_bytes = max($max_bytes, (int) ($summary['max_statement_bytes'] ?? 0));
        if ($capture['reject_path']) {
            $reject_statements += $count;
        }
        $by_capture[$name] = [
            'reject_path' => $capture['reject_path'],
            'statement_count' => $count,
            'statement_bytes' => (int) ($summary['total_statement_bytes'] ?? 0),
        ];
        foreach (($summary['statements'] ?? []) as $statement) {
            $tag = (string) ($statement['tag'] ?? 'unknown');
            $type = (string) ($statement['type'] ?? 'UNKNOWN');
            $by_tag[$tag] = ($by_tag[$tag] ?? 0) + 1;
            $by_type[$type] = ($by_type[$type] ?? 0) + 1;
            foreach (($statement['table_roles'] ?? []) as $role) {
                $by_role[$role] = ($by_role[$role] ?? 0) + 1;
            }
        }
    }
    ksort($by_capture, SORT_STRING);
    ksort($by_tag, SORT_STRING);
    ksort($by_type, SORT_STRING);
    ksort($by_role, SORT_STRING);

    return [
        'scope' => 'case_and_cleanup_captures_after_wordpress_bootstrap',
        'capture_boundary' => 'WordPress query filter at PHP_INT_MAX',
        'capture_count' => count($captures),
        'statement_count' => $statement_count,
        'total_statement_bytes' => $total_bytes,
        'max_statement_bytes' => $max_bytes,
        'reject_path_statement_count' => $reject_statements,
        'by_capture' => $by_capture,
        'by_tag' => $by_tag,
        'by_type' => $by_type,
        'by_table_role' => $by_role,
    ];
}

/** @param array<int,array<string,mixed>> $gates */
function wp_fts_ib_gate(array &$gates, string $id, mixed $expected, mixed $actual, bool $passed): void
{
    $gates[] = [
        'id' => $id,
        'expected' => $expected,
        'actual' => $actual,
        'passed' => $passed,
    ];
}

/** @param array<int,array<string,mixed>> $gates */
function wp_fts_ib_gates_pass(array $gates): bool
{
    if ($gates === []) {
        return false;
    }
    foreach ($gates as $gate) {
        if (($gate['passed'] ?? false) !== true) {
            return false;
        }
    }

    return true;
}

/** @param array<string,mixed> $evidence @return array<string,mixed> */
function wp_fts_ib_finalize_evidence(array $evidence): array
{
    $evidence['resources'] = wp_fts_ib_resource_row(false);
    $resource_gate_offset = count($evidence['gates']);
    array_push($evidence['gates'], ...wp_fts_ib_resource_gates($evidence['resources']));

    // Warm both encodings at the final field/gate cardinality before taking the
    // high-water sample. The 64-byte placeholder also matches the self-hash
    // that the emitted artifact receives after the measurement.
    $evidence['evidence_sha256'] = str_repeat('0', 64);
    $warm_canonical = wp_fts_ib_canonical_json($evidence);
    $warm_artifact = json_encode(
        $evidence,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    ) . "\n";
    unset($warm_canonical, $warm_artifact);
    $evidence['evidence_sha256'] = null;
    gc_collect_cycles();

    try {
        $resources = wp_fts_ib_resource_row(true);
        $evidence['resources'] = $resources;
        array_splice($evidence['gates'], $resource_gate_offset, 4, wp_fts_ib_resource_gates($resources));
    } catch (Throwable $resource_error) {
        if ($evidence['error'] === null) {
            $evidence['error'] = wp_fts_ib_error_row($resource_error);
        }
        array_splice($evidence['gates'], $resource_gate_offset, 4, [[
            'id' => 'linux_proc_resource_evidence_available',
            'expected' => 'VmHWM and VmRSS present',
            'actual' => null,
            'passed' => false,
        ]]);
    }

    if (!wp_fts_ib_gates_pass($evidence['gates'])) {
        $evidence['status'] = 'FAIL';
    }
    $hash_input = $evidence;
    $hash_input['evidence_sha256'] = null;
    $evidence['evidence_sha256'] = hash('sha256', wp_fts_ib_canonical_json($hash_input));

    return $evidence;
}

/**
 * @param array<string,mixed> $resources
 * @return array<int,array{id:string,expected:mixed,actual:mixed,passed:bool}>
 */
function wp_fts_ib_resource_gates(array $resources): array
{
    return [
        [
            'id' => 'php_memory_limit_exactly_128_mib',
            'expected' => '= 134217728',
            'actual' => $resources['php_memory_limit_bytes'],
            'passed' => $resources['php_memory_limit_bytes'] === WP_FTS_IB_MEMORY_LIMIT_BYTES,
        ],
        [
            'id' => 'php_peak_memory_within_128_mib',
            'expected' => '<= 134217728',
            'actual' => $resources['php_peak_allocated_bytes'],
            'passed' => $resources['php_peak_allocated_bytes'] <= WP_FTS_IB_MEMORY_LIMIT_BYTES,
        ],
        [
            'id' => 'proc_vmhwm_within_128_mib',
            'expected' => '<= 134217728',
            'actual' => $resources['proc_status']['VmHWM_bytes'],
            'passed' => $resources['proc_status']['VmHWM_bytes'] <= WP_FTS_IB_MEMORY_LIMIT_BYTES,
        ],
        [
            'id' => 'proc_vmrss_within_128_mib',
            'expected' => '<= 134217728',
            'actual' => $resources['proc_status']['VmRSS_bytes'],
            'passed' => $resources['proc_status']['VmRSS_bytes'] <= WP_FTS_IB_MEMORY_LIMIT_BYTES,
        ],
    ];
}

/** @return array<string,mixed> */
function wp_fts_ib_resource_row(bool $require_proc): array
{
    $proc = wp_fts_ib_proc_status($require_proc);

    return [
        'php_memory_limit_bytes' => wp_fts_ib_ini_bytes((string) ini_get('memory_limit')),
        'php_current_usage_bytes' => memory_get_usage(false),
        'php_current_allocated_bytes' => memory_get_usage(true),
        'php_peak_usage_bytes' => memory_get_peak_usage(false),
        'php_peak_allocated_bytes' => memory_get_peak_usage(true),
        'proc_status' => $proc,
    ];
}

/** @return array{VmHWM_bytes:?int,VmRSS_bytes:?int} */
function wp_fts_ib_proc_status(bool $required): array
{
    $path = '/proc/self/status';
    if (!is_readable($path)) {
        if ($required) {
            throw new RuntimeException('Linux /proc/self/status is required for isolated memory evidence.');
        }
        return ['VmHWM_bytes' => null, 'VmRSS_bytes' => null];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('Could not read /proc/self/status.');
    }
    $values = [];
    foreach ($lines as $line) {
        $separator = strpos($line, ':');
        if ($separator === false) {
            continue;
        }
        $key = substr($line, 0, $separator);
        if (!in_array($key, ['VmHWM', 'VmRSS'], true)) {
            continue;
        }
        $parts = array_values(array_filter(explode(' ', trim(substr($line, $separator + 1))), static fn(string $part): bool => $part !== ''));
        if (count($parts) < 2 || !wp_fts_ib_is_ascii_digits($parts[0]) || strtolower($parts[1]) !== 'kb') {
            throw new RuntimeException("Malformed {$key} row in /proc/self/status.");
        }
        $values[$key] = (int) $parts[0] * 1024;
    }
    if (!isset($values['VmHWM'], $values['VmRSS'])) {
        if ($required) {
            throw new RuntimeException('/proc/self/status did not expose both VmHWM and VmRSS.');
        }
        return ['VmHWM_bytes' => $values['VmHWM'] ?? null, 'VmRSS_bytes' => $values['VmRSS'] ?? null];
    }

    return ['VmHWM_bytes' => $values['VmHWM'], 'VmRSS_bytes' => $values['VmRSS']];
}

/** Convert the shorthand used by php.ini into the evidence schema's byte count. */
function wp_fts_ib_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return -1;
    }
    $suffix = strtolower(substr($value, -1));
    if ($suffix >= '0' && $suffix <= '9') {
        return (int) $value;
    }
    $number = (int) substr($value, 0, -1);

    return match ($suffix) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => (int) $value,
    };
}

/** @param array<string,mixed> $evidence */
function wp_fts_ib_emit_evidence(array $evidence): bool
{
    $json = json_encode(
        $evidence,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    ) . "\n";
    $directory = getenv('WP_FTS_WC_EVIDENCE_DIR');
    if (!is_string($directory) || trim($directory) === '') {
        fwrite(STDERR, "FAIL: WP_FTS_WC_EVIDENCE_DIR is required.\n");
        fwrite(STDOUT, $json);
        return false;
    }
    $directory = rtrim($directory, '/\\');
    if (!is_dir($directory) || !is_writable($directory)) {
        fwrite(STDERR, "FAIL: isolated evidence directory is unavailable: {$directory}\n");
        fwrite(STDOUT, $json);
        return false;
    }
    $path = $directory . '/' . WP_FTS_IB_EVIDENCE_FILE;
    $temporary = $path . '.tmp.' . getmypid();
    $written = file_put_contents($temporary, $json, LOCK_EX);
    if ($written !== strlen($json) || !rename($temporary, $path)) {
        @unlink($temporary);
        fwrite(STDERR, "FAIL: could not atomically write isolated evidence: {$path}\n");
        fwrite(STDOUT, $json);
        return false;
    }

    fwrite(STDOUT, $json);
    if (($evidence['status'] ?? 'FAIL') !== 'PASS') {
        fwrite(STDERR, "FAIL: relational FTS isolated boundary proof did not pass.\n");
    }

    return true;
}

/** Namespace disposable terms by source revision to prevent cross-run collisions. */
function wp_fts_ib_term_prefix(string $source_sha): string
{
    return 'wpftsib' . strtolower(substr($source_sha, 0, 10));
}

/** Reject missing wrapper bindings instead of silently weakening the proof. */
function wp_fts_ib_required_env(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException("{$name} is required.");
    }

    return trim($value);
}

/** Preserve optional pre-bootstrap bindings as null in failure evidence. */
function wp_fts_ib_env_or_null(string $name): ?string
{
    $value = getenv($name);

    return is_string($value) && trim($value) !== '' ? trim($value) : null;
}

/** Turn a failed proof invariant into the enclosing evidence failure path. */
function wp_fts_ib_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Bound diagnostic text without changing the bytes used by measured SQL. */
function wp_fts_ib_truncate(string $value, int $bytes): string
{
    if (strlen($value) <= $bytes) {
        return $value;
    }

    return substr($value, 0, $bytes);
}

/** Encode evidence with stable object-key order for its self-attesting digest. */
function wp_fts_ib_canonical_json(mixed $value): string
{
    return json_encode(
        wp_fts_ib_canonical_value($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    );
}

/** Sort associative evidence recursively while preserving list order. */
function wp_fts_ib_canonical_value(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $child) {
        $value[$key] = wp_fts_ib_canonical_value($child);
    }

    return $value;
}
