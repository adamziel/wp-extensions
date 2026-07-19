<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

/** @return array{VmHWM_bytes:?int,VmRSS_bytes:?int} */
function wp_fts_encoded_metadata_proc_status(): array
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
            if ($space === false) {
                continue;
            }
            $kilobytes = substr($value, 0, $space);
            if (ctype_digit($kilobytes) && strtolower(trim(substr($value, $space + 1))) === 'kb') {
                $values[$key] = (int) $kilobytes * 1024;
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

try {
    // This is valid, sub-2-MiB source. Before metadata extraction became a
    // bounded stream, it retained 250,000 PHP range arrays and exhausted a
    // 128-MiB process before the analyzer could issue its typed occurrence
    // rejection.
    $html = str_repeat('&#97; ', 250000);
    $indexer = new WP_FTS_Indexer(
        new WP_FTS_Storage_InMemory(),
        new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'enable_stemming' => false,
            'default_lang' => 'en',
        ])
    );
    $peakBefore = memory_get_peak_usage(true);
    $started = microtime(true);
    $error = null;
    try {
        $indexer->prepare_document_fields(
            1,
            [['name' => 'content', 'text' => '', 'html' => $html]],
            ['metadata' => []]
        );
    } catch (Throwable $caught) {
        $error = [
            'class' => get_class($caught),
            'reason_code' => $caught instanceof WP_FTS_Analysis_Limit_Exceeded ? $caught->reason_code : '',
            'message' => $caught->getMessage(),
        ];
    }
    $peakAfter = memory_get_peak_usage(true);

    echo json_encode([
        'source_bytes' => strlen($html),
        'error' => $error,
        'elapsed_seconds' => microtime(true) - $started,
        'php_peak_bytes' => $peakAfter,
        'php_peak_delta_bytes' => max(0, $peakAfter - $peakBefore),
        'proc_status' => wp_fts_encoded_metadata_proc_status(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}
