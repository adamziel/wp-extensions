<?php
declare(strict_types=1);

/**
 * Optional real WordPress/MySQL integration harness.
 *
 * Direct PHP execution is the public entry point. It discovers a WordPress
 * install through WP-CLI, skips clearly when unavailable, and then re-enters
 * this same file through `wp eval` + `require` so real `$wpdb`, `dbDelta()`,
 * and WP-CLI command execution are exercised without affecting the default
 * suite.
 */

const WP_FTS_REAL_INTEGRATION_SCHEMA_VERSION = 'simulated-1';

try {
    exit(wp_fts_real_integration_main());
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    exit(1);
}

function wp_fts_real_integration_main(): int
{
    if (getenv('WP_FTS_REAL_INTEGRATION_INSIDE') === '1') {
        wp_fts_real_integration_run_inside_wordpress();
        return 0;
    }

    return wp_fts_real_integration_run_from_shell();
}

function wp_fts_real_integration_run_from_shell(): int
{
    if (!function_exists('proc_open')) {
        return wp_fts_real_integration_skip('proc_open() is unavailable; cannot launch WP-CLI.');
    }

    $wpPath = trim((string) getenv('WP_FTS_WP_PATH'));
    if ($wpPath === '') {
        return wp_fts_real_integration_skip('Set WP_FTS_WP_PATH to an installed WordPress root to run real integration tests.');
    }

    if (!is_dir($wpPath)) {
        return wp_fts_real_integration_skip("WP_FTS_WP_PATH does not exist or is not a directory: {$wpPath}");
    }

    $baseCommand = wp_fts_real_integration_wp_cli_base_command();
    $installed = wp_fts_real_integration_process(array_merge($baseCommand, ['core', 'is-installed']));
    if ($installed['exit'] !== 0) {
        $detail = trim($installed['stderr'] . "\n" . $installed['stdout']);
        return wp_fts_real_integration_skip('WP-CLI is unavailable or WordPress is not installed at WP_FTS_WP_PATH.'
            . ($detail !== '' ? " Detail: {$detail}" : ''));
    }

    $result = wp_fts_real_integration_process(
        array_merge($baseCommand, ['eval', 'require ' . var_export(__FILE__, true) . ';']),
        ['WP_FTS_REAL_INTEGRATION_INSIDE' => '1']
    );

    echo $result['stdout'];
    if ($result['stderr'] !== '') {
        fwrite(STDERR, $result['stderr']);
    }

    return $result['exit'];
}

function wp_fts_real_integration_run_inside_wordpress(): void
{
    global $wpdb;

    if (!isset($wpdb) || !is_object($wpdb)) {
        throw new RuntimeException('WordPress loaded without a usable $wpdb object.');
    }

    require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

    $token = wp_fts_real_integration_token();
    $prefix = 'wp_fts_it_' . $token . '_';
    $optionName = 'wp_fts_it_schema_version_' . $token;
    $postIds = [];

    try {
        wp_fts_real_integration_drop_tables($wpdb, $prefix);
        wp_fts_real_integration_db_delta_repair($wpdb, $prefix);
        wp_fts_real_integration_binary_round_trips($wpdb, $prefix);
        wp_fts_real_integration_transactions($wpdb, $prefix);
        wp_fts_real_integration_schema_version_path($wpdb, $prefix, $optionName);
        $postIds = wp_fts_real_integration_wp_cli_process($wpdb, $prefix, $token);

        echo "PASS: real WordPress/MySQL integration checks completed for prefix {$prefix}\n";
    } finally {
        foreach ($postIds as $postId) {
            if (function_exists('wp_delete_post')) {
                wp_delete_post($postId, true);
            }
        }
        if (function_exists('delete_option')) {
            delete_option($optionName);
        }
        wp_fts_real_integration_drop_tables($wpdb, $prefix);
    }
}

/** Prove dbDelta replaces an incompatible derived table with the exact schema. */
function wp_fts_real_integration_db_delta_repair(object $wpdb, string $prefix): void
{
    $tables = wp_fts_real_integration_tables($prefix);
    $terms = wp_fts_real_integration_identifier($tables['terms']);

    // Seed an incompatible derived table. Current schema repair must
    // replace it rather than leave a half-converted dictionary in service.
    wp_fts_real_integration_query($wpdb, "CREATE TABLE `{$terms}` (
term varbinary(255) NOT NULL,
doc_freq int unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (term)
) ENGINE=InnoDB DEFAULT CHARSET=binary");

    $storage = new WP_FTS_Storage_Mysql($wpdb, $prefix);
    $storage->create_tables();
    $storage->create_tables();

    wp_fts_real_integration_assert(function_exists('dbDelta'), 'dbDelta() should be loaded for real WordPress schema repair.');
    foreach ($tables as $table) {
        wp_fts_real_integration_assert_table_exists($wpdb, $table);
    }

    wp_fts_real_integration_assert_schema($wpdb, $tables);
    wp_fts_real_integration_assert_same(true, $storage->verify_schema()['valid'] ?? null, 'physical current schema should satisfy the production verifier.');

    echo "ok dbDelta replaced an incompatible index with the exact four-table current schema\n";
}

/** Round-trip binary term identities and maximum prepared posting payloads. */
function wp_fts_real_integration_binary_round_trips(object $wpdb, string $prefix): void
{
    $storage = new WP_FTS_Storage_Mysql($wpdb, $prefix);
    $tables = wp_fts_real_integration_tables($prefix);
    $termsTable = wp_fts_real_integration_identifier($tables['terms']);

    $binaryTerm = "pl\x1ebin\x00term\xff";
    $binaryPostingMap = [7 => 1, 130 => 300];
    $binaryDocuments = [];
    foreach ($binaryPostingMap as $postId => $frequency) {
        $binaryDocuments[] = [
            'doc_id' => $postId,
            'primary_lang' => 'pl',
            'content_hash' => hash('sha256', "binary:{$postId}"),
            'term_frequencies' => [$binaryTerm => $frequency],
            'surface_frequencies' => [],
        ];
    }
    $binaryWrite = $storage->replace_prepared_documents($binaryDocuments);
    wp_fts_real_integration_assert_same(2, $binaryWrite['postings'] ?? null, 'the bounded writer should persist both binary posting rows.');

    $row = wp_fts_real_integration_term_state($wpdb, $prefix, $binaryTerm, 10);
    wp_fts_real_integration_assert($row !== null, 'binary term should be readable after a bounded prepared replacement.');
    wp_fts_real_integration_assert_same(count($binaryPostingMap), $row['df'], 'binary term doc frequency should round trip.');
    wp_fts_real_integration_assert_same([
        7 => wp_fts_real_integration_impact(1),
        130 => wp_fts_real_integration_impact(300),
    ], $row['postings'], 'bounded relational inspection should preserve every quantized posting impact.');

    $identity = WP_FTS_TermNamespace::split_term($binaryTerm);
    $termRow = $wpdb->get_row($wpdb->prepare(
        "SELECT HEX(lang) AS lang_hex, HEX(term) AS term_hex, doc_freq FROM `{$termsTable}` WHERE lang = UNHEX(%s) AND kind = 0 AND term = UNHEX(%s) LIMIT 1",
        bin2hex((string) $identity['lang']),
        bin2hex((string) $identity['term'])
    ));
    wp_fts_real_integration_assert($termRow !== null, 'binary term row should be selectable with a prepared term predicate.');
    wp_fts_real_integration_assert_same(strtoupper(bin2hex((string) $identity['lang'])), (string) $termRow->lang_hex, 'VARBINARY language bytes should be stored exactly.');
    wp_fts_real_integration_assert_same(strtoupper(bin2hex((string) $identity['term'])), (string) $termRow->term_hex, 'VARBINARY lexical term bytes should be stored exactly.');
    wp_fts_real_integration_assert_same(count($binaryPostingMap), (int) $termRow->doc_freq, 'terms table should store document frequency only.');

    $codecTerm = WP_FTS_TermNamespace::namespace_term('pl', 'zamek');
    $codecDocuments = [];
    foreach ([1001 => 2, 1005 => 7] as $postId => $frequency) {
        $codecDocuments[] = [
            'doc_id' => $postId,
            'primary_lang' => 'pl',
            'content_hash' => hash('sha256', "codec:{$postId}"),
            'term_frequencies' => [$codecTerm => $frequency],
            'surface_frequencies' => [],
        ];
    }
    $storage->replace_prepared_documents($codecDocuments);
    $codecRow = wp_fts_real_integration_term_state($wpdb, $prefix, $codecTerm, 10);
    wp_fts_real_integration_assert($codecRow !== null, 'codec term should be readable.');
    wp_fts_real_integration_assert_same([
        1001 => wp_fts_real_integration_impact(2),
        1005 => wp_fts_real_integration_impact(7),
    ], $codecRow['postings'], 'encoded compatibility writes should become quantized relational posting rows.');

    wp_fts_real_integration_assert_point_reads_absent($storage, $wpdb);

    echo "ok binary dictionary identities and bounded prepared posting writes round trip\n";
}

/** Distinguish rolled-back and committed derived writes on the real engine. */
function wp_fts_real_integration_transactions(object $wpdb, string $prefix): void
{
    $storage = new WP_FTS_Storage_Mysql($wpdb, $prefix);
    $rolledBackTerm = WP_FTS_TermNamespace::namespace_term('en', 'rollback');
    $committedTerm = WP_FTS_TermNamespace::namespace_term('en', 'commit');

    $storage->begin_transaction();
    $storage->replace_prepared_documents([[
        'doc_id' => 2001,
        'primary_lang' => 'en',
        'content_hash' => sha1('rollback'),
        'term_frequencies' => [$rolledBackTerm => 1],
        'surface_frequencies' => [],
    ]]);
    $storage->rollback();

    wp_fts_real_integration_assert_same([], $storage->document_hashes([2001]), 'rolled back document should not persist.');
    wp_fts_real_integration_assert_same(null, wp_fts_real_integration_term_state($wpdb, $prefix, $rolledBackTerm, 10), 'rolled back term should not persist.');

    $storage->begin_transaction();
    $storage->replace_prepared_documents([[
        'doc_id' => 2002,
        'primary_lang' => 'en',
        'content_hash' => sha1('commit'),
        'term_frequencies' => [$committedTerm => 4],
        'surface_frequencies' => [],
    ]]);
    $storage->commit();

    wp_fts_real_integration_assert_same([2002 => sha1('commit')], $storage->document_hashes([2002]), 'committed document should persist.');
    wp_fts_real_integration_assert(wp_fts_real_integration_term_state($wpdb, $prefix, $committedTerm, 10) !== null, 'committed term should persist.');
    $documents = wp_fts_real_integration_identifier(wp_fts_real_integration_tables($prefix)['documents']);
    wp_fts_real_integration_assert_same(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$documents}`"), 'rollback should leave only the committed document row.');

    echo "ok MySQL transaction commit and rollback behavior verified\n";
}

function wp_fts_real_integration_schema_version_path(object $wpdb, string $prefix, string $optionName): void
{
    $storage = new WP_FTS_Storage_Mysql($wpdb, $prefix);
    $storage->create_tables();

    if (!function_exists('update_option') || !function_exists('get_option')) {
        throw new RuntimeException('WordPress options API is unavailable for schema-version simulation.');
    }

    update_option($optionName, WP_FTS_REAL_INTEGRATION_SCHEMA_VERSION, false);
    wp_fts_real_integration_assert_same(
        WP_FTS_REAL_INTEGRATION_SCHEMA_VERSION,
        get_option($optionName),
        'simulated activation path should persist a schema version option.'
    );

    foreach (wp_fts_real_integration_tables($prefix) as $table) {
        wp_fts_real_integration_assert_table_exists($wpdb, $table);
    }

    echo "ok activation/schema-version path simulated for current baseline\n";
}

/**
 * @return int[]
 */
function wp_fts_real_integration_wp_cli_process(object $wpdb, string $prefix, string $token): array
{
    if (!function_exists('wp_insert_post')) {
        throw new RuntimeException('wp_insert_post() is unavailable for the WP-CLI process smoke test.');
    }

    $postType = 'wpftsit' . substr($token, 0, 8);
    $postId = wp_insert_post([
        'post_title' => 'WP FTS integration needle',
        'post_content' => '<p lang="pl">wpftsneedle alfa beta</p>',
        'post_status' => 'publish',
        'post_type' => $postType,
    ], true);

    if (function_exists('is_wp_error') && is_wp_error($postId)) {
        throw new RuntimeException('Could not create integration post: ' . $postId->get_error_message());
    }

    $postId = (int) $postId;
    wp_fts_real_integration_assert($postId > 0, 'wp_insert_post() should return a positive post id.');

    $command = array_merge(wp_fts_real_integration_wp_cli_base_command(), [
        '--require=' . __DIR__ . '/wpcli-require.php',
        'fts',
        'reindex',
        '--post_status=publish',
        '--post_type=' . $postType,
        '--lang=pl',
        '--limit=1',
        '--format=json',
    ]);
    $result = wp_fts_real_integration_process($command, ['WP_FTS_REAL_WPCLI_PREFIX' => $prefix]);
    $output = trim($result['stdout'] . "\n" . $result['stderr']);

    wp_fts_real_integration_assert(
        $result['exit'] === 0,
        "wp fts reindex process should exit cleanly. Output: {$output}"
    );
    wp_fts_real_integration_assert(
        str_contains($output, '"status":"queued"') && str_contains($output, '"language":"pl"'),
        "wp fts reindex process should report one queued language scope. Output: {$output}"
    );

    $workerOutput = [];
    $hasMore = true;
    for ($pass = 1; $pass <= 10 && $hasMore; $pass++) {
        $worker = wp_fts_real_integration_process(array_merge(wp_fts_real_integration_wp_cli_base_command(), [
            '--require=' . __DIR__ . '/wpcli-require.php',
            'fts',
            'process-batch',
            '--batch_size=100',
            '--time_budget=20',
            '--format=json',
        ]), ['WP_FTS_REAL_WPCLI_PREFIX' => $prefix]);
        $workerCombined = trim($worker['stdout'] . "\n" . $worker['stderr']);
        wp_fts_real_integration_assert(
            $worker['exit'] === 0,
            "wp fts process-batch pass {$pass} should exit cleanly. Output: {$workerCombined}"
        );
        $workerPayload = json_decode(trim($worker['stdout']), true, 512, JSON_THROW_ON_ERROR);
        $hasMore = !empty($workerPayload['has_more']);
        $workerOutput[] = $workerPayload;
    }
    wp_fts_real_integration_assert(!$hasMore, 'Ten explicit bounded worker passes should be sufficient for the one-post integration scope.');

    $storage = new WP_FTS_Storage_Mysql($wpdb, $prefix);
    $hashes = $storage->document_hashes([$postId]);
    wp_fts_real_integration_assert(isset($hashes[$postId]) && $hashes[$postId] !== '', 'WP-CLI reindex should write the inserted post with a content fingerprint.');
    $documents = wp_fts_real_integration_identifier(wp_fts_real_integration_tables($prefix)['documents']);
    $primaryLang = $wpdb->get_var($wpdb->prepare("SELECT primary_lang FROM `{$documents}` WHERE post_id = %d", $postId));
    wp_fts_real_integration_assert_same('pl', $primaryLang, 'WP-CLI reindex should store the requested language.');

    $searcher = new WP_FTS_Searcher($storage, new WP_FTS_Analyzer());
    $payload = $searcher->search('wpftsneedle', [
        'lang' => 'pl',
        'limit' => 3,
        'post_type' => $postType,
        'post_status' => 'publish',
    ]);
    wp_fts_real_integration_assert_same($postId, $payload['results'][0]['doc_id'] ?? null, 'WP-CLI indexed document should be searchable.');

    echo 'ok WP-CLI queued one scope, drained it in ' . count($workerOutput) . " bounded passes, and searched a real WordPress post\n";

    return [$postId];
}

/**
 * @return array<string,string>
 */
function wp_fts_real_integration_tables(string $prefix): array
{
    return [
        'terms' => $prefix . 'fts_terms',
        'postings' => $prefix . 'fts_postings',
        'documents' => $prefix . 'fts_documents',
        'work' => $prefix . 'fts_work',
    ];
}

function wp_fts_real_integration_token(): string
{
    return substr(hash('sha256', getmypid() . ':' . microtime(true) . ':' . random_int(1, PHP_INT_MAX)), 0, 10);
}

function wp_fts_real_integration_wp_cli_base_command(): array
{
    $wpCli = trim((string) (getenv('WP_FTS_WP_CLI') ?: 'wp'));
    $wpPath = trim((string) getenv('WP_FTS_WP_PATH'));
    $command = [$wpCli, '--path=' . $wpPath];
    $url = trim((string) getenv('WP_FTS_WP_URL'));
    if ($url !== '') {
        $command[] = '--url=' . $url;
    }

    return $command;
}

/**
 * @param string[] $command
 * @param array<string,string> $env
 * @return array{exit:int,stdout:string,stderr:string}
 */
function wp_fts_real_integration_process(array $command, array $env = []): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('proc_open() is required to launch WP-CLI.');
    }

    $baseEnv = getenv();
    if (!is_array($baseEnv)) {
        $baseEnv = [];
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2), array_merge($baseEnv, $env));
    if (!is_resource($process)) {
        return [
            'exit' => 127,
            'stdout' => '',
            'stderr' => 'Could not start process: ' . wp_fts_real_integration_command_string($command),
        ];
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

function wp_fts_real_integration_query(object $wpdb, string $sql): void
{
    $result = $wpdb->query($sql);
    if ($result === false) {
        $error = (string) ($wpdb->last_error ?? 'unknown database error');
        throw new RuntimeException("Database query failed: {$error}; SQL: {$sql}");
    }
}

function wp_fts_real_integration_drop_tables(object $wpdb, string $prefix): void
{
    foreach (wp_fts_real_integration_tables($prefix) as $table) {
        $identifier = wp_fts_real_integration_identifier($table);
        $wpdb->query("DROP TABLE IF EXISTS `{$identifier}`");
    }
}

function wp_fts_real_integration_assert_table_exists(object $wpdb, string $table): void
{
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    wp_fts_real_integration_assert_same($table, (string) $found, "table {$table} should exist.");
}

/** @param array<string,string> $tables */
function wp_fts_real_integration_assert_schema(object $wpdb, array $tables): void
{
    $contracts = [
        $tables['terms'] => [
            'columns' => ['term_id', 'lang', 'kind', 'term', 'doc_freq'],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['term_id']],
                'empty_terms' => ['unique' => false, 'columns' => ['doc_freq']],
                'term_identity' => ['unique' => true, 'columns' => ['lang', 'kind', 'term']],
            ],
        ],
        $tables['postings'] => [
            'columns' => ['term_id', 'post_id', 'impact'],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['term_id', 'post_id']],
                'post_term_impact' => ['unique' => false, 'columns' => ['post_id', 'term_id', 'impact']],
            ],
        ],
        $tables['documents'] => [
            'columns' => ['post_id', 'primary_lang', 'content_hash', 'snippet_text', 'indexed_at'],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['post_id']],
            ],
        ],
        $tables['work'] => [
            'columns' => ['job_key', 'kind', 'post_id', 'generation', 'state', 'available_at', 'attempts', 'claim_token', 'claimed_generation', 'claim_expires_at', 'cursor_post_id', 'scope_coverage', 'scope_incarnation', 'scope_subject_type', 'scope_subject_id', 'payload', 'last_error_code', 'last_error_at'],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['job_key']],
                'claim_token' => ['unique' => false, 'columns' => ['claim_token', 'post_id']],
                'dirty' => ['unique' => false, 'columns' => ['post_id', 'kind']],
                'kind_job' => ['unique' => false, 'columns' => ['kind', 'job_key']],
                'ready' => ['unique' => false, 'columns' => ['kind', 'state', 'available_at', 'post_id', 'job_key']],
                'recoverable' => ['unique' => false, 'columns' => ['kind', 'state', 'claim_expires_at', 'available_at', 'post_id', 'job_key']],
                'scope_subject' => ['unique' => false, 'columns' => ['kind', 'scope_coverage', 'scope_subject_type', 'scope_subject_id']],
            ],
        ],
    ];

    foreach ($contracts as $table => $contract) {
        $identifier = wp_fts_real_integration_identifier($table);
        $columnRows = $wpdb->get_results("SHOW COLUMNS FROM `{$identifier}`");
        $actualColumns = array_map(static fn(object $row): string => (string) $row->Field, is_array($columnRows) ? $columnRows : []);
        wp_fts_real_integration_assert_same($contract['columns'], $actualColumns, "table {$table} should have only the exact current columns.");

        $indexRows = $wpdb->get_results("SHOW INDEX FROM `{$identifier}`");
        $actualIndexes = [];
        foreach (is_array($indexRows) ? $indexRows : [] as $row) {
            $name = (string) $row->Key_name;
            $actualIndexes[$name] ??= ['unique' => (int) $row->Non_unique === 0, 'columns' => []];
            $actualIndexes[$name]['columns'][(int) $row->Seq_in_index] = (string) $row->Column_name;
        }
        foreach ($actualIndexes as &$index) {
            ksort($index['columns'], SORT_NUMERIC);
            $index['columns'] = array_values($index['columns']);
        }
        unset($index);
        ksort($actualIndexes, SORT_STRING);
        $expectedIndexes = $contract['indexes'];
        ksort($expectedIndexes, SORT_STRING);
        wp_fts_real_integration_assert_same($expectedIndexes, $actualIndexes, "table {$table} should have only the exact current indexes.");
    }
}

/** @return array{df:int,postings:array<int,int>}|null */
function wp_fts_real_integration_term_state(object $wpdb, string $prefix, string $termKey, int $rowCap): ?array
{
    $tables = wp_fts_real_integration_tables($prefix);
    $terms = wp_fts_real_integration_identifier($tables['terms']);
    $postings = wp_fts_real_integration_identifier($tables['postings']);
    $identity = WP_FTS_TermNamespace::split_term($termKey);
    $termRow = $wpdb->get_row($wpdb->prepare(
        "SELECT term_id,doc_freq FROM `{$terms}` WHERE lang=UNHEX(%s) AND kind=0 AND term=UNHEX(%s) LIMIT 1",
        bin2hex((string) $identity['lang']),
        bin2hex((string) $identity['term'])
    ));
    if ($termRow === null) {
        wp_fts_real_integration_assert_same('', trim((string) ($wpdb->last_error ?? '')), 'exact dictionary inspection should not fail.');
        return null;
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id,impact FROM `{$postings}` WHERE term_id=%d ORDER BY post_id LIMIT %d",
        (int) $termRow->term_id,
        $rowCap + 1
    ));
    wp_fts_real_integration_assert_same('', trim((string) ($wpdb->last_error ?? '')), 'bounded posting inspection should not fail.');
    $rows = is_array($rows) ? $rows : [];
    wp_fts_real_integration_assert(count($rows) <= $rowCap, "test-only posting inspection should stay within {$rowCap} rows.");
    $result = [];
    foreach ($rows as $row) {
        $result[(int) $row->post_id] = (int) $row->impact;
    }

    return ['df' => (int) $termRow->doc_freq, 'postings' => $result];
}

/** Require point and posting-list readers to be absent from production storage. */
function wp_fts_real_integration_assert_point_reads_absent(WP_FTS_Storage_Mysql $storage, object $wpdb): void
{
    $queriesBefore = (int) ($wpdb->num_queries ?? 0);
    foreach (['get_doc', 'get_doc_metadata', 'terms_for_doc', 'get_terms', 'get_postings', 'get_capped_postings', 'get_budgeted_postings'] as $method) {
        wp_fts_real_integration_assert(!method_exists($storage, $method), "production storage should not expose {$method}.");
    }
    wp_fts_real_integration_assert_same($queriesBefore, (int) ($wpdb->num_queries ?? 0), 'production capability inspection should not run SQL.');
}

/** Reproduce the bounded integer impact expected from the production writer. */
function wp_fts_real_integration_impact(int $weightedTf): int
{
    $tf = max(1, $weightedTf);

    return max(1, min(65535, (int) round(4096.0 * ((2.2 * $tf) / (1.2 + $tf)))));
}

function wp_fts_real_integration_identifier(string $identifier): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
        throw new InvalidArgumentException("Unsafe generated identifier: {$identifier}");
    }

    return $identifier;
}

function wp_fts_real_integration_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wp_fts_real_integration_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function wp_fts_real_integration_skip(string $reason): int
{
    echo "SKIP: {$reason}\n";

    return 0;
}

/**
 * @param string[] $command
 */
function wp_fts_real_integration_command_string(array $command): string
{
    return implode(' ', array_map(static fn(string $arg): string => escapeshellarg($arg), $command));
}
