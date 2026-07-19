<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

/** @return array{VmHWM_bytes:?int,VmRSS_bytes:?int} */
function wp_fts_html_product_proc_status(): array
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
    $word = $argv[1] ?? '';
    if (!in_array($word, ['a', 'aa'], true)) {
        throw new InvalidArgumentException('Expected the bounded word variant a or aa.');
    }

    // This simultaneously reaches the 20,000-token ceiling and the 256-level
    // depth ceiling. Before inline paths used persistent IDs, all 9,745 text
    // rows copied 255 ancestor strings and added about 88 MiB of PHP allocation.
    $repetitions = 9745;
    $html = str_repeat('<i>', 255)
        . str_repeat($word . ' <b></b>', $repetitions)
        . str_repeat('</i>', 255);
    $peakBefore = memory_get_peak_usage(true);
    $started = microtime(true);
    $analyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'enable_stemming' => false,
        'document_lang' => 'en',
    ]);
    $terms = $analyzer->analyze_content($html, ['lang' => 'en']);
    $peakAfter = memory_get_peak_usage(true);

    echo json_encode([
        'variant' => $word,
        'source_bytes' => strlen($html),
        'markup_tokens' => 20000,
        'max_element_depth' => 256,
        'occurrences' => count($terms),
        'elapsed_seconds' => microtime(true) - $started,
        'php_peak_bytes' => $peakAfter,
        'php_peak_delta_bytes' => max(0, $peakAfter - $peakBefore),
        'proc_status' => wp_fts_html_product_proc_status(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}
