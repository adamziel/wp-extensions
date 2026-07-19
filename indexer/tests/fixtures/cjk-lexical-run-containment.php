<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/../components/full-text-search/src/bootstrap.php';

/** @return array{VmHWM_bytes:?int,VmRSS_bytes:?int} */
function wp_fts_cjk_run_proc_status(): array
{
    if (!is_readable('/proc/self/status')) {
        return ['VmHWM_bytes' => null, 'VmRSS_bytes' => null];
    }

    $values = [];
    foreach (file('/proc/self/status', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $separator = strpos($line, ':');
        if ($separator === false) {
            continue;
        }
        $key = substr($line, 0, $separator);
        if (!in_array($key, ['VmHWM', 'VmRSS'], true)) {
            continue;
        }
        $value = trim(substr($line, $separator + 1));
        $parts = array_values(array_filter(explode(' ', $value), static fn(string $part): bool => $part !== ''));
        if (isset($parts[0]) && $parts[0] !== '' && strspn($parts[0], '0123456789') === strlen($parts[0])) {
            $values[$key] = (int) $parts[0] * 1024;
        }
    }

    return [
        'VmHWM_bytes' => $values['VmHWM'] ?? null,
        'VmRSS_bytes' => $values['VmRSS'] ?? null,
    ];
}

$segmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
if (!$segmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
    fwrite(STDERR, "The pinned Jieba segmenter is unavailable.\n");
    exit(1);
}
$pipeline = new WP_FTS_LanguagePipeline([
    'enable_stemming' => false,
    'cjk_tokenizer' => $segmenter,
]);
$run = str_repeat('中', intdiv(WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES, strlen('中')));

if (function_exists('memory_reset_peak_usage')) {
    memory_reset_peak_usage();
}
$peakBefore = memory_get_peak_usage(true);
$started = microtime(true);
$error = null;
try {
    $pipeline->analyze_detailed($run, 'zh');
} catch (Throwable $caught) {
    $error = [
        'class' => get_class($caught),
        'reason_code' => $caught instanceof WP_FTS_Analysis_Limit_Exceeded ? $caught->reason_code : '',
        'message' => $caught->getMessage(),
    ];
}
$elapsed = microtime(true) - $started;
$peakAfter = memory_get_peak_usage(true);

echo json_encode([
    'source_limit_bytes' => WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES,
    'run_bytes' => strlen($run),
    'lexical_run_limit_bytes' => WP_FTS_Analysis_Limits::MAX_LEXICAL_RUN_BYTES,
    'error' => $error,
    'dictionary_scan_count' => (int) (new ReflectionProperty($segmenter, 'dictionaryScanCount'))->getValue($segmenter),
    'elapsed_seconds' => $elapsed,
    'php_peak_bytes' => $peakAfter,
    'php_peak_delta_bytes' => max(0, $peakAfter - $peakBefore),
    'proc_status' => wp_fts_cjk_run_proc_status(),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
