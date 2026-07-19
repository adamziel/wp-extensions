<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

/** @return array{VmHWM_bytes:?int,VmRSS_bytes:?int} */
function wp_fts_jieba_line_fixture_proc_status(): array
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
            $value = trim(substr($line, $separator + 1));
            $space = strpos($value, ' ');
            if ($space !== false && $space > 0 && strspn(substr($value, 0, $space), '0123456789') === $space && strtolower(trim(substr($value, $space + 1))) === 'kb') {
                $values[$key] = (int) substr($value, 0, $space) * 1024;
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

$root = sys_get_temp_dir() . '/wp-fts-jieba-line-containment-' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);
$dictionary = $root . '/dict.txt';

try {
    $sourceBytes = 16 * 1024 * 1024;
    $header = '中国 1 n';
    $handle = fopen($dictionary, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create the giant-line Jieba fixture.');
    }
    try {
        fwrite($handle, $header);
        $remaining = $sourceBytes - strlen($header);
        while ($remaining > 0) {
            $bytes = min(65536, $remaining);
            if (fwrite($handle, str_repeat('x', $bytes)) !== $bytes) {
                throw new RuntimeException('Could not complete the giant-line Jieba fixture.');
            }
            $remaining -= $bytes;
        }
    } finally {
        fclose($handle);
    }

    $sha256 = hash_file('sha256', $dictionary);
    if (!is_string($sha256)) {
        throw new RuntimeException('Could not hash the giant-line Jieba fixture.');
    }
    $segmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option([
        'source_file' => $dictionary,
        'expected_sha256' => $sha256,
        'expected_byte_size' => $sourceBytes,
        'fixture_only' => true,
    ], 'zh');
    if (!$segmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
        throw new RuntimeException('Could not load the giant-line Jieba fixture.');
    }

    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $peakBefore = memory_get_peak_usage(true);
    $started = microtime(true);
    $error = null;
    try {
        $segmenter('中国', 'zh');
    } catch (Throwable $caught) {
        $error = [
            'class' => get_class($caught),
            'reason_code' => $caught instanceof WP_FTS_Analysis_Limit_Exceeded ? $caught->reason_code : '',
            'message' => $caught->getMessage(),
        ];
    }
    $elapsed = microtime(true) - $started;
    $peakAfter = memory_get_peak_usage(true);
    $scanCount = (int) (new ReflectionProperty($segmenter, 'dictionaryScanCount'))->getValue($segmenter);

    echo json_encode([
        'source_bytes' => filesize($dictionary),
        'line_limit_bytes' => WP_FTS_ChineseJiebaSegmenter::MAX_DICTIONARY_LINE_BYTES,
        'error' => $error,
        'elapsed_seconds' => $elapsed,
        'dictionary_scan_count' => $scanCount,
        'php_peak_bytes' => $peakAfter,
        'php_peak_delta_bytes' => max(0, $peakAfter - $peakBefore),
        'proc_status' => wp_fts_jieba_line_fixture_proc_status(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (is_file($dictionary)) {
        unlink($dictionary);
    }
    if (is_dir($root)) {
        rmdir($root);
    }
}
