<?php
declare(strict_types=1);

/**
 * Optional real WordPress/MySQL integration harness.
 *
 * Direct PHP execution is the public entry point. It discovers a WordPress
 * install through WP-CLI, skips clearly when unavailable, and then re-enters
 * this same file through `wp eval-file` so real `$wpdb`, `dbDelta()`, and
 * WP-CLI command execution are exercised without affecting the default suite.
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
        array_merge($baseCommand, ['eval-file', __FILE__]),
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
        wp_fts_real_integration_db_delta_migration($wpdb, $prefix);
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

function wp_fts_real_integration_db_delta_migration(object $wpdb, string $prefix): void
{
    $tables = wp_fts_real_integration_tables($prefix);
    $docs = wp_fts_real_integration_identifier($tables['docs']);

    wp_fts_real_integration_query($wpdb, "CREATE TABLE `{$docs}` (
doc_id bigint unsigned NOT NULL,
lang varchar(16) NOT NULL DEFAULT 'und',
doc_len int unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=binary");

    $storage = new WP_FTS_Storage_Mysql($wpdb, $prefix);
    $storage->create_tables();
    $storage->create_tables();

    wp_fts_real_integration_assert(function_exists('dbDelta'), 'dbDelta() should be loaded for real WordPress schema migration.');
    foreach ($tables as $table) {
        wp_fts_real_integration_assert_table_exists($wpdb, $table);
    }

    wp_fts_real_integration_assert_column($wpdb, $tables['docs'], 'content_hash');
    wp_fts_real_integration_assert_column($wpdb, $tables['docs'], 'is_deleted');
    wp_fts_real_integration_assert_index($wpdb, $tables['docs'], 'lang');
    wp_fts_real_integration_assert_index($wpdb, $tables['docs'], 'is_deleted');
    wp_fts_real_integration_assert_column($wpdb, $tables['doc_lengths'], 'doc_len');
    wp_fts_real_integration_assert_index($wpdb, $tables['doc_lengths'], 'lang');
    wp_fts_real_integration_assert_column($wpdb, $tables['terms'], 'postings');

    echo "ok dbDelta created and migrated FTS tables\n";
}

function wp_fts_real_integration_binary_round_trips(object $wpdb, string $prefix): void
{
    $storage = new WP_FTS_Storage_Mysql($wpdb, $prefix);
    $tables = wp_fts_real_integration_tables($prefix);
    $termsTable = wp_fts_real_integration_identifier($tables['terms']);

    $binaryTerm = "pl\x1ebin\x00term\xff";
    $binaryPostings = "\x00\xff" . WP_FTS_PostingsCodec::encode([7 => 1, 130 => 300]) . "\x1e\x00";
    $storage->put_term($binaryTerm, 1, $binaryPostings);

    $row = $storage->get_terms([$binaryTerm])[$binaryTerm] ?? null;
    wp_fts_real_integration_assert($row !== null, 'binary term should be readable after put_term().');
    wp_fts_real_integration_assert_same(1, $row['df'], 'binary term doc frequency should round trip.');
    wp_fts_real_integration_assert_same($binaryPostings, $row['postings'], 'binary postings should round trip through LONGBLOB.');

    $hexRow = $wpdb->get_row($wpdb->prepare(
        "SELECT HEX(term) AS term_hex, HEX(postings) AS postings_hex FROM `{$termsTable}` WHERE term = %s",
        $binaryTerm
    ));
    wp_fts_real_integration_assert($hexRow !== null, 'binary row should be selectable with a prepared term predicate.');
    wp_fts_real_integration_assert_same(strtoupper(bin2hex($binaryTerm)), (string) $hexRow->term_hex, 'VARBINARY term bytes should be stored exactly.');
    wp_fts_real_integration_assert_same(strtoupper(bin2hex($binaryPostings)), (string) $hexRow->postings_hex, 'LONGBLOB postings bytes should be stored exactly.');

    $codecTerm = WP_FTS_TermNamespace::namespace_term('pl', 'zamek');
    $codecPostings = WP_FTS_PostingsCodec::encode([1001 => 2, 1005 => 7]);
    $storage->put_term($codecTerm, 2, $codecPostings);
    $codecRow = $storage->get_terms([$codecTerm])[$codecTerm] ?? null;
    wp_fts_real_integration_assert($codecRow !== null, 'codec term should be readable.');
    wp_fts_real_integration_assert_same([1001 => 2, 1005 => 7], WP_FTS_PostingsCodec::decode($codecRow['postings']), 'encoded postings should decode after MySQL storage.');

    echo "ok binary VARBINARY terms and prepared LONGBLOB postings round trip\n";
}

function wp_fts_real_integration_transactions(object $wpdb, string $prefix): void
{
    $storage = new WP_FTS_Storage_Mysql($wpdb, $prefix);
    $rolledBackTerm = WP_FTS_TermNamespace::namespace_term('en', 'rollback');
    $committedTerm = WP_FTS_TermNamespace::namespace_term('en', 'commit');

    $storage->begin_transaction();
    $storage->put_doc(2001, 'en', ['en' => 3], sha1('rollback'));
    $storage->put_term($rolledBackTerm, 1, WP_FTS_PostingsCodec::encode([2001 => 1]));
    $storage->rollback();

    wp_fts_real_integration_assert($storage->get_doc(2001) === null, 'rolled back document should not persist.');
    wp_fts_real_integration_assert_same([], $storage->get_terms([$rolledBackTerm]), 'rolled back term should not persist.');

    $storage->begin_transaction();
    $storage->put_doc(2002, 'en', ['en' => 4], sha1('commit'));
    $storage->put_term($committedTerm, 1, WP_FTS_PostingsCodec::encode([2002 => 4]));
    $storage->add_meta('en', 1, 4);
    $storage->commit();

    wp_fts_real_integration_assert($storage->get_doc(2002) !== null, 'committed document should persist.');
    wp_fts_real_integration_assert(isset($storage->get_terms([$committedTerm])[$committedTerm]), 'committed term should persist.');
    wp_fts_real_integration_assert_same(['doc_count' => 1, 'len_sum' => 4], $storage->get_meta('en'), 'committed metadata should persist.');

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
        '--batch_size=1',
    ]);
    $result = wp_fts_real_integration_process($command, ['WP_FTS_REAL_WPCLI_PREFIX' => $prefix]);
    $output = trim($result['stdout'] . "\n" . $result['stderr']);

    wp_fts_real_integration_assert(
        $result['exit'] === 0,
        "wp fts reindex process should exit cleanly. Output: {$output}"
    );
    wp_fts_real_integration_assert(
        str_contains($output, 'Indexed 1 posts in pl.'),
        "wp fts reindex process should report one indexed post. Output: {$output}"
    );

    $storage = new WP_FTS_Storage_Mysql($wpdb, $prefix);
    $doc = $storage->get_doc($postId);
    wp_fts_real_integration_assert($doc !== null, 'WP-CLI reindex should write the inserted post.');
    wp_fts_real_integration_assert_same('pl', $doc['primary_lang'], 'WP-CLI reindex should store the requested language.');
    wp_fts_real_integration_assert($doc['doc_len'] > 0, 'WP-CLI reindex should store a non-empty document length.');

    $searcher = new WP_FTS_Searcher($storage, new WP_FTS_Analyzer());
    $results = $searcher->search('wpftsneedle', ['lang' => 'pl', 'limit' => 3]);
    wp_fts_real_integration_assert_same($postId, $results[0]['doc_id'] ?? null, 'WP-CLI indexed document should be searchable.');

    echo "ok WP-CLI command process reindexed and searched a real WordPress post\n";

    return [$postId];
}

/**
 * @return array<string,string>
 */
function wp_fts_real_integration_tables(string $prefix): array
{
    return [
        'terms' => $prefix . 'fts_terms',
        'docs' => $prefix . 'fts_docs',
        'doc_lengths' => $prefix . 'fts_doc_lengths',
        'meta' => $prefix . 'fts_meta',
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

function wp_fts_real_integration_assert_column(object $wpdb, string $table, string $column): void
{
    $identifier = wp_fts_real_integration_identifier($table);
    $row = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM `{$identifier}` LIKE %s", $column));
    wp_fts_real_integration_assert($row !== null, "column {$table}.{$column} should exist.");
}

function wp_fts_real_integration_assert_index(object $wpdb, string $table, string $index): void
{
    $identifier = wp_fts_real_integration_identifier($table);
    $rows = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM `{$identifier}` WHERE Key_name = %s", $index));
    wp_fts_real_integration_assert($rows !== [], "index {$table}.{$index} should exist.");
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
