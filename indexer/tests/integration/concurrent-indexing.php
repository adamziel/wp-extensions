<?php
declare(strict_types=1);

/**
 * Optional concurrent indexing diagnostic.
 *
 * The script creates worker-specific post types in a disposable WordPress
 * database, concurrently coalesces filtered reindex scopes, then starts
 * contending one-pass workers against one generated FTS table prefix and
 * verifies the shared term contains every generated post.
 */

try {
    exit(wp_fts_concurrent_main());
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    exit(1);
}

function wp_fts_concurrent_main(): int
{
    if (!function_exists('proc_open')) {
        return wp_fts_concurrent_skip('proc_open() is unavailable; cannot launch WP-CLI workers.');
    }

    $wpPath = trim((string) getenv('WP_FTS_WP_PATH'));
    if ($wpPath === '') {
        return wp_fts_concurrent_skip('Set WP_FTS_WP_PATH to an installed WordPress root to run concurrent indexing.');
    }
    if (!is_dir($wpPath)) {
        return wp_fts_concurrent_skip("WP_FTS_WP_PATH does not exist or is not a directory: {$wpPath}");
    }

    $baseCommand = wp_fts_concurrent_wp_cli_base_command();
    $installed = wp_fts_concurrent_process(array_merge($baseCommand, ['core', 'is-installed']));
    if ($installed['exit'] !== 0) {
        $detail = trim($installed['stderr'] . "\n" . $installed['stdout']);
        return wp_fts_concurrent_skip('WP-CLI is unavailable or WordPress is not installed at WP_FTS_WP_PATH.'
            . ($detail !== '' ? " Detail: {$detail}" : ''));
    }

    $workers = max(2, (int) (getenv('WP_FTS_CONCURRENT_WORKERS') ?: 4));
    $postsPerWorker = max(1, (int) (getenv('WP_FTS_CONCURRENT_POSTS_PER_WORKER') ?: 3));
    $token = substr(hash('sha256', getmypid() . ':' . microtime(true) . ':' . random_int(1, PHP_INT_MAX)), 0, 8);
    $prefix = 'wp_fts_cc_' . $token . '_';
    $env = [
        'WP_FTS_CONCURRENT_TOKEN' => $token,
        'WP_FTS_CONCURRENT_WORKERS' => (string) $workers,
        'WP_FTS_CONCURRENT_POSTS_PER_WORKER' => (string) $postsPerWorker,
        'WP_FTS_INDEXER_ROOT' => dirname(__DIR__, 2),
        'WP_FTS_REAL_WPCLI_PREFIX' => $prefix,
    ];

    try {
        wp_fts_concurrent_must_succeed(
            wp_fts_concurrent_process(array_merge($baseCommand, ['eval', wp_fts_concurrent_setup_code()]), $env),
            'setup generated WordPress posts'
        );

        $workerProcesses = [];
        for ($worker = 0; $worker < $workers; $worker++) {
            $postType = wp_fts_concurrent_post_type($token, $worker);
            $command = array_merge($baseCommand, [
                '--require=' . __DIR__ . '/wpcli-require.php',
                'fts',
                'reindex',
                '--post_status=publish',
                '--post_type=' . $postType,
                '--lang=en',
                '--limit=0',
                '--format=json',
            ]);
            $workerProcesses[] = wp_fts_concurrent_start_process($command, $env);
        }

        foreach ($workerProcesses as $worker => $process) {
            $result = wp_fts_concurrent_finish_process($process);
            wp_fts_concurrent_must_succeed($result, "worker {$worker} reindex process");
            if (!str_contains($result['stdout'], '"status":"queued"')) {
                throw new RuntimeException("worker {$worker} reindex process did not report queued scope acceptance");
            }
        }

        $drainRounds = wp_fts_concurrent_drain_with_contending_workers($baseCommand, $env, $workers);

        wp_fts_concurrent_must_succeed(
            wp_fts_concurrent_process(array_merge($baseCommand, ['eval', wp_fts_concurrent_verify_code()]), $env),
            'verify concurrent postings'
        );

        $expectedPosts = $workers * $postsPerWorker;
        echo "PASS: concurrent scope enqueue plus {$drainRounds} contended one-pass worker rounds preserved {$expectedPosts} shared-term postings with {$workers} workers\n";
        return 0;
    } finally {
        wp_fts_concurrent_process(array_merge($baseCommand, ['eval', wp_fts_concurrent_cleanup_code()]), $env);
    }
}

/**
 * Run bounded worker commands concurrently until every queued scope and post
 * generation is acknowledged. Each process still performs exactly one pass;
 * this harness owns the explicit retry rounds and a finite completion guard.
 */
function wp_fts_concurrent_drain_with_contending_workers(array $baseCommand, array $env, int $workers): int
{
    $maxRounds = ($workers * 3) + 20;
    for ($round = 1; $round <= $maxRounds; $round++) {
        $processes = [];
        for ($worker = 0; $worker < $workers; $worker++) {
            $processes[] = wp_fts_concurrent_start_process(array_merge($baseCommand, [
                '--require=' . __DIR__ . '/wpcli-require.php',
                'fts',
                'process-batch',
                '--batch_size=100',
                '--time_budget=20',
                '--format=json',
            ]), $env);
        }
        foreach ($processes as $worker => $process) {
            wp_fts_concurrent_must_succeed(
                wp_fts_concurrent_finish_process($process),
                "worker {$worker} process-batch round {$round}"
            );
        }

        $status = wp_fts_concurrent_process(array_merge($baseCommand, [
            '--require=' . __DIR__ . '/wpcli-require.php',
            'fts',
            'status',
            '--format=json',
        ]), $env);
        wp_fts_concurrent_must_succeed($status, "status after process-batch round {$round}");
        $payload = json_decode(trim($status['stdout']), true, 512, JSON_THROW_ON_ERROR);
        if (empty($payload['has_more'])) {
            return $round;
        }
        usleep(50000);
    }

    throw new RuntimeException("concurrent worker drain exceeded {$maxRounds} bounded rounds");
}

function wp_fts_concurrent_setup_code(): string
{
    return <<<'PHP'
require_once getenv('WP_FTS_INDEXER_ROOT') . '/src/bootstrap.php';
global $wpdb;
$prefix = getenv('WP_FTS_REAL_WPCLI_PREFIX');
if (!is_string($prefix) || preg_match('/^[A-Za-z0-9_]+$/', $prefix) !== 1) {
    fwrite(STDERR, "unsafe generated FTS prefix\n");
    exit(1);
}
$wpdb->prefix = $prefix;
WP_FTS_Plugin::upgrade_schema();
$token = getenv('WP_FTS_CONCURRENT_TOKEN');
$workers = max(2, (int) getenv('WP_FTS_CONCURRENT_WORKERS'));
$postsPerWorker = max(1, (int) getenv('WP_FTS_CONCURRENT_POSTS_PER_WORKER'));
for ($worker = 0; $worker < $workers; $worker++) {
    $postType = 'wpftsc' . $token . sprintf('%02d', $worker);
    for ($i = 0; $i < $postsPerWorker; $i++) {
        $postId = wp_insert_post([
            'post_title' => "Concurrent {$worker} {$i}",
            'post_content' => "<p>concurrentneedle worker{$worker} post{$i}</p>",
            'post_status' => 'publish',
            'post_type' => $postType,
        ], true);
        if (is_wp_error($postId)) {
            fwrite(STDERR, $postId->get_error_message());
            exit(1);
        }
    }
}
echo "created " . ($workers * $postsPerWorker) . " posts\n";
PHP;
}

function wp_fts_concurrent_verify_code(): string
{
    return <<<'PHP'
require_once getenv('WP_FTS_INDEXER_ROOT') . '/src/bootstrap.php';
global $wpdb;
$prefix = getenv('WP_FTS_REAL_WPCLI_PREFIX');
$workers = max(2, (int) getenv('WP_FTS_CONCURRENT_WORKERS'));
$postsPerWorker = max(1, (int) getenv('WP_FTS_CONCURRENT_POSTS_PER_WORKER'));
$expected = $workers * $postsPerWorker;
$term = WP_FTS_TermNamespace::namespace_term('en', 'concurrentneedle');
if (preg_match('/^[A-Za-z0-9_]+$/', $prefix) !== 1) {
    fwrite(STDERR, "unsafe generated FTS prefix\n");
    exit(1);
}
$identity = WP_FTS_TermNamespace::split_term($term);
$terms = $prefix . 'fts_terms';
$postings = $prefix . 'fts_postings';
$row = $wpdb->get_row($wpdb->prepare(
    "SELECT term_id,doc_freq FROM `{$terms}` WHERE lang=%s AND kind=0 AND term=%s LIMIT 1",
    (string) $identity['lang'],
    (string) $identity['term']
));
if ($row === null) {
    fwrite(STDERR, "shared term was not indexed\n");
    exit(1);
}
$postingCount = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM `{$postings}` WHERE term_id=%d",
    (int) $row->term_id
));
if ($postingCount !== $expected || (int) $row->doc_freq !== $expected) {
    fwrite(STDERR, "expected {$expected} postings, got df={$row->doc_freq} postings={$postingCount}\n");
    exit(1);
}
echo "verified {$expected} postings\n";
PHP;
}

function wp_fts_concurrent_cleanup_code(): string
{
    return <<<'PHP'
require_once getenv('WP_FTS_INDEXER_ROOT') . '/src/bootstrap.php';
global $wpdb;
$token = getenv('WP_FTS_CONCURRENT_TOKEN');
$workers = max(2, (int) getenv('WP_FTS_CONCURRENT_WORKERS'));
for ($worker = 0; $worker < $workers; $worker++) {
    $postType = 'wpftsc' . $token . sprintf('%02d', $worker);
    $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $postType));
    foreach ($ids ?: [] as $id) {
        wp_delete_post((int) $id, true);
    }
}
$prefix = getenv('WP_FTS_REAL_WPCLI_PREFIX');
if (preg_match('/^[A-Za-z0-9_]+$/', $prefix) === 1) {
    foreach (['fts_postings', 'fts_terms', 'fts_documents', 'fts_work'] as $suffix) {
        $wpdb->query('DROP TABLE IF EXISTS `' . $prefix . $suffix . '`');
    }
}
PHP;
}

function wp_fts_concurrent_post_type(string $token, int $worker): string
{
    return 'wpftsc' . $token . sprintf('%02d', $worker);
}

function wp_fts_concurrent_wp_cli_base_command(): array
{
    $command = [trim((string) (getenv('WP_FTS_WP_CLI') ?: 'wp')), '--path=' . trim((string) getenv('WP_FTS_WP_PATH'))];
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
function wp_fts_concurrent_process(array $command, array $env = []): array
{
    return wp_fts_concurrent_finish_process(wp_fts_concurrent_start_process($command, $env));
}

/**
 * @param string[] $command
 * @param array<string,string> $env
 * @return array{resource:resource|null,pipes:array<int,resource>,command:string[]}
 */
function wp_fts_concurrent_start_process(array $command, array $env = []): array
{
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
        return ['resource' => null, 'pipes' => [], 'command' => $command];
    }

    fclose($pipes[0]);

    return ['resource' => $process, 'pipes' => $pipes, 'command' => $command];
}

/**
 * @param array{resource:resource|null,pipes:array<int,resource>,command:string[]} $process
 * @return array{exit:int,stdout:string,stderr:string}
 */
function wp_fts_concurrent_finish_process(array $process): array
{
    if ($process['resource'] === null) {
        return [
            'exit' => 127,
            'stdout' => '',
            'stderr' => 'Could not start process: ' . wp_fts_concurrent_command_string($process['command']),
        ];
    }

    $stdout = (string) stream_get_contents($process['pipes'][1]);
    $stderr = (string) stream_get_contents($process['pipes'][2]);
    fclose($process['pipes'][1]);
    fclose($process['pipes'][2]);
    $exit = proc_close($process['resource']);

    return [
        'exit' => is_int($exit) ? $exit : 1,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

/**
 * @param array{exit:int,stdout:string,stderr:string} $result
 */
function wp_fts_concurrent_must_succeed(array $result, string $label): void
{
    if ($result['exit'] === 0) {
        return;
    }

    $output = trim($result['stdout'] . "\n" . $result['stderr']);
    throw new RuntimeException("{$label} failed with exit {$result['exit']}: {$output}");
}

function wp_fts_concurrent_skip(string $reason): int
{
    echo "SKIP: {$reason}\n";

    return 0;
}

/**
 * @param string[] $command
 */
function wp_fts_concurrent_command_string(array $command): string
{
    return implode(' ', array_map(static fn(string $arg): string => escapeshellarg($arg), $command));
}
