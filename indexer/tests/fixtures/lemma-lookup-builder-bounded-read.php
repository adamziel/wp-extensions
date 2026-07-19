<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tools/build-lemma-pack-lookup-index.php';

$case = $argv[1] ?? '';
if (!in_array($case, ['manifest', 'source-lock'], true)) {
    fwrite(STDERR, "Expected manifest or source-lock case.\n");
    exit(2);
}

$root = sys_get_temp_dir() . '/wp-fts-builder-bounded-' . $case . '-' . bin2hex(random_bytes(5));
if (!mkdir($root, 0777, true)) {
    fwrite(STDERR, "Could not create bounded builder fixture.\n");
    exit(1);
}

try {
    if ($case === 'manifest') {
        $manifest = $root . '/manifest.json';
        lemma_builder_bounded_sparse_file($manifest, 140 * 1024 * 1024);
        $before = ['manifest_bytes' => filesize($manifest)];
    } else {
        $source = dirname(__DIR__, 2) . '/resources/analyzer-packs/te-unimorph-tel-551f60f5f434';
        lemma_builder_bounded_copy_tree($source, $root);
        $manifest = $root . '/manifest.json';
        lemma_builder_bounded_sparse_file($root . '/SOURCE.lock.json', 140 * 1024 * 1024);
        $before = lemma_builder_bounded_snapshot($root);
    }

    $started = microtime(true);
    $error = null;
    try {
        (new WP_FTS_LemmaPackLookupIndexBuilder())->build($manifest);
    } catch (Throwable $caught) {
        $error = $caught;
    }
    $elapsed = microtime(true) - $started;
    $after = $case === 'manifest'
        ? ['manifest_bytes' => filesize($manifest)]
        : lemma_builder_bounded_snapshot($root);

    echo json_encode([
        'case' => $case,
        'sparse_bytes' => 140 * 1024 * 1024,
        'error_class' => $error === null ? null : get_class($error),
        'reason_code' => $error instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $error->reason_code : null,
        'error_message' => $error === null ? null : $error->getMessage(),
        'pack_unchanged' => $before === $after,
        'staging_paths' => lemma_builder_bounded_staging_paths($root),
        'elapsed_seconds' => $elapsed,
        'php_peak_bytes' => memory_get_peak_usage(true),
        'proc_status' => lemma_builder_bounded_proc_status(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    lemma_builder_bounded_remove_tree($root);
}

/** Create an exact-length fixture with ftruncate instead of buffering its payload. */
function lemma_builder_bounded_sparse_file(string $path, int $bytes): void
{
    $handle = fopen($path, 'w+b');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create sparse bounded-read fixture.');
    }
    try {
        if (!ftruncate($handle, $bytes)) {
            throw new RuntimeException('Could not size sparse bounded-read fixture.');
        }
    } finally {
        fclose($handle);
    }
}

/** Recursively clone the baseline pack into an isolated bounded-read fixture. */
function lemma_builder_bounded_copy_tree(string $source, string $destination): void
{
    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $from = $source . '/' . $entry;
        $to = $destination . '/' . $entry;
        if (is_dir($from)) {
            if (!mkdir($to) && !is_dir($to)) {
                throw new RuntimeException('Could not copy bounded-read fixture directory.');
            }
            lemma_builder_bounded_copy_tree($from, $to);
            continue;
        }
        if (!copy($from, $to)) {
            throw new RuntimeException('Could not copy bounded-read fixture artifact.');
        }
    }
}

/** @return array<string,string|int> */
function lemma_builder_bounded_snapshot(string $root): array
{
    $snapshot = [];
    $walk = static function (string $directory) use (&$walk, &$snapshot, $root): void {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.wp-fts-')) {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $walk($path);
                continue;
            }
            $relative = substr($path, strlen($root) + 1);
            if ($relative === 'SOURCE.lock.json') {
                $snapshot[$relative] = (int) filesize($path);
                continue;
            }
            $digest = hash_file('sha256', $path);
            if (!is_string($digest)) {
                throw new RuntimeException('Could not snapshot bounded-read fixture.');
            }
            $snapshot[$relative] = $digest;
        }
    };
    $walk($root);
    ksort($snapshot, SORT_STRING);

    return $snapshot;
}

/** @return string[] */
function lemma_builder_bounded_staging_paths(string $root): array
{
    $paths = [];
    $walk = static function (string $directory) use (&$walk, &$paths): void {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (str_starts_with($entry, '.wp-fts-')) {
                $paths[] = $path;
            }
            if (is_dir($path)) {
                $walk($path);
            }
        }
    };
    $walk($root);
    sort($paths, SORT_STRING);

    return $paths;
}

/** @return array{VmHWM_bytes:?int,VmRSS_bytes:?int} */
function lemma_builder_bounded_proc_status(): array
{
    if (!is_readable('/proc/self/status')) {
        return ['VmHWM_bytes' => null, 'VmRSS_bytes' => null];
    }
    $values = [];
    $handle = fopen('/proc/self/status', 'rb');
    if (!is_resource($handle)) {
        return ['VmHWM_bytes' => null, 'VmRSS_bytes' => null];
    }
    try {
        while (($line = fgets($handle)) !== false) {
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $key = substr($line, 0, $separator);
            if (!in_array($key, ['VmHWM', 'VmRSS'], true)) {
                continue;
            }
            $parts = explode(' ', trim(substr($line, $separator + 1)));
            $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
            if (count($parts) === 2 && $parts[0] !== '' && strspn($parts[0], '0123456789') === strlen($parts[0]) && strtolower($parts[1]) === 'kb') {
                $values[$key] = (int) $parts[0] * 1024;
            }
        }
    } finally {
        fclose($handle);
    }

    return [
        'VmHWM_bytes' => $values['VmHWM'] ?? null,
        'VmRSS_bytes' => $values['VmRSS'] ?? null,
    ];
}

/** Remove the complete owned fixture tree after evidence capture. */
function lemma_builder_bounded_remove_tree(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    foreach (scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $root . '/' . $entry;
        if (is_dir($path)) {
            lemma_builder_bounded_remove_tree($path);
        } else {
            unlink($path);
        }
    }
    rmdir($root);
}
