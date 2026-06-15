<?php
declare(strict_types=1);

final class WP_FTS_TestFailure extends RuntimeException
{
}

final class WP_FTS_TestPending extends RuntimeException
{
}

/** @var array<int,array{name:string,fn:callable}> */
$tests = [];
$wp_fts_check_count = 0;

function test_case(string $name, callable $fn): void
{
    global $tests;
    $tests[] = ['name' => $name, 'fn' => $fn];
}

function record_check(?string $label = null, int $count = 1): void
{
    if ($count < 1) {
        throw new WP_FTS_TestFailure('record_check() count must be at least 1.');
    }

    global $wp_fts_check_count;
    $wp_fts_check_count += $count;
}

function executed_check_count(): int
{
    global $wp_fts_check_count;

    return $wp_fts_check_count;
}

function assert_true(bool $condition, string $message): void
{
    record_check($message);
    if (!$condition) {
        throw new WP_FTS_TestFailure($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    record_check($message);
    if ($expected !== $actual) {
        throw new WP_FTS_TestFailure($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function assert_contains(string $needle, string $haystack, string $message): void
{
    record_check($message);
    if (!str_contains($haystack, $needle)) {
        throw new WP_FTS_TestFailure($message . "\nMissing: " . var_export($needle, true) . "\nIn: " . $haystack);
    }
}

function mark_pending(string $message): never
{
    throw new WP_FTS_TestPending($message);
}

function temp_directory_path(string $suffix): string
{
    return sys_get_temp_dir() . '/wp_fts_' . getmypid() . '_' . $suffix . '_' . bin2hex(random_bytes(4));
}

function remove_directory_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $path) {
        if ($path->isDir()) {
            rmdir($path->getPathname());
            continue;
        }
        unlink($path->getPathname());
    }
    rmdir($directory);
}

/**
 * @param array<int,string> $command
 * @return array{exit:int,stdout:string,stderr:string}
 */
function test_run_subprocess(array $command, ?string $cwd = null): array
{
    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is unavailable, so this subprocess test cannot run in this PHP build.');
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd ?? dirname(__DIR__));
    if (!is_resource($process)) {
        mark_pending('Could not start a PHP subprocess.');
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

require_once __DIR__ . '/quality/search-corpus-generator.php';

$failed = 0;
$pending = 0;
foreach ($tests as $test) {
    try {
        $test['fn']();
        fwrite(STDOUT, "[PASS] {$test['name']}\n");
    } catch (WP_FTS_TestPending $e) {
        $pending++;
        fwrite(STDOUT, "[PENDING] {$test['name']}: {$e->getMessage()}\n");
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "[FAIL] {$test['name']}: {$e->getMessage()}\n");
    }
}

fwrite(STDOUT, 'Executed ' . count($tests) . ' corpus generator tests with ' . executed_check_count() . " checks.\n");
if ($pending > 0) {
    fwrite(STDOUT, "Pending: {$pending}\n");
}
if ($failed > 0) {
    fwrite(STDERR, "Failures: {$failed}\n");
    exit(1);
}
