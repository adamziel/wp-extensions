<?php
declare(strict_types=1);

if (($argc !== 2 && $argc !== 3) || !is_string($argv[1]) || $argv[1] === '') {
    fwrite(STDERR, "Usage: php foreground-owner-flock-holder.php <lock-directory> [shared|exclusive|attempt]\n");
    exit(2);
}
$mode = is_string($argv[2] ?? null) ? $argv[2] : 'shared';
if (!in_array($mode, ['shared', 'exclusive', 'attempt'], true)) {
    fwrite(STDERR, "Unknown foreground owner flock fixture mode.\n");
    exit(2);
}

define('WP_FTS_FOREGROUND_LOCK_DIR', $argv[1]);
define('DB_NAME', 'wp_fts_flock_process_contract');

require_once dirname(__DIR__, 2) . '/src/IndexQueue.php';

$wpdb = new class {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
};

$queue = new WP_FTS_Index_Queue($wpdb);
$pathMethod = new ReflectionMethod(WP_FTS_Index_Queue::class, 'foreground_owner_guard_path');
$path = (string) $pathMethod->invoke($queue);

if ($mode === 'attempt') {
    $started = hrtime(true);
    $guard = null;
    $error = '';
    try {
        $guard = $queue->acquire_foreground_owner_guard();
    } catch (Throwable $caught) {
        $error = $caught->getMessage();
    }
    $elapsedMilliseconds = (hrtime(true) - $started) / 1000000;
    if (is_array($guard)) {
        $queue->release_foreground_owner_guard($guard);
    }
    echo json_encode([
        'path' => $path,
        'acquired' => is_array($guard),
        'elapsed_ms' => round($elapsedMilliseconds, 3),
        'error' => $error,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

if ($mode === 'exclusive') {
    $openMethod = new ReflectionMethod(WP_FTS_Index_Queue::class, 'open_foreground_owner_guard');
    $handle = $openMethod->invoke($queue, $path);
    if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
        throw new RuntimeException('Could not acquire the hostile exclusive flock fixture.');
    }
    $guard = ['kind' => 'exclusive-fixture', 'path' => $path, 'handle' => $handle];
} else {
    $guard = $queue->acquire_foreground_owner_guard();
}
$stat = @fstat($guard['handle']);
fwrite(STDOUT, json_encode([
    'pid' => getmypid(),
    'path' => $guard['path'],
    'device' => is_array($stat) ? ($stat['dev'] ?? null) : null,
    'inode' => is_array($stat) ? ($stat['ino'] ?? null) : null,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
fflush(STDOUT);

// One child receives a graceful release command. Another is deliberately
// killed while blocked here to prove kernel cleanup without PHP shutdown.
$command = fgets(STDIN);
if (is_string($command) && trim($command) === 'release') {
    if ($mode === 'exclusive') {
        flock($guard['handle'], LOCK_UN);
        fclose($guard['handle']);
    } else {
        $queue->release_foreground_owner_guard($guard);
    }
    exit(0);
}

sleep(60);
