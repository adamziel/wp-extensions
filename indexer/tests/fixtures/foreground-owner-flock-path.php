<?php
declare(strict_types=1);

if ($argc !== 2 || !is_string($argv[1])) {
    fwrite(STDERR, "Usage: php foreground-owner-flock-path.php <base64-json-config>\n");
    exit(2);
}

$decoded = base64_decode($argv[1], true);
$config = is_string($decoded) ? json_decode($decoded, true) : null;
if (!is_array($config)) {
    fwrite(STDERR, "The foreground flock path config must be base64-encoded JSON.\n");
    exit(2);
}

// Path-mode assertions need one explicit host-independent creation mask. The
// subprocess exits after the probe, so no caller state needs restoring.
umask(0022);

$cwd = $config['cwd'] ?? null;
if (is_string($cwd) && $cwd !== '' && !chdir($cwd)) {
    throw new RuntimeException('Could not enter the requested path-probe working directory.');
}

define('DB_ENGINE', is_string($config['DB_ENGINE'] ?? null) ? $config['DB_ENGINE'] : 'sqlite');
foreach (['DB_HOST', 'DB_NAME', 'WP_FTS_FOREGROUND_LOCK_DIR', 'FQDB', 'DB_FILE', 'DB_DIR', 'FQDBDIR', 'ABSPATH', 'WP_CONTENT_DIR'] as $name) {
    $value = $config[$name] ?? null;
    if (is_string($value)) {
        define($name, $value);
    }
}

require_once dirname(__DIR__, 2) . '/src/IndexQueue.php';

$wpdb = new class {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
};
$queue = new WP_FTS_Index_Queue($wpdb);
$method = new ReflectionMethod(WP_FTS_Index_Queue::class, 'foreground_owner_guard_path');
try {
    $path = $method->invoke($queue);
} catch (Throwable $error) {
    fwrite(STDERR, substr($error->getMessage(), 0, 512) . "\n");
    exit(1);
}

echo json_encode([
    'path' => $path,
    'cwd' => getcwd(),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
