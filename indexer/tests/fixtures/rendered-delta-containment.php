<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

/** Build distinct plain tokens without retaining a parallel token array. */
function wp_fts_rendered_delta_source(string $prefix, int $words): string
{
    $source = '';
    for ($index = 0; $index < $words; $index++) {
        $source .= ($source === '' ? '' : ' ') . $prefix . str_pad((string) $index, 5, '0', STR_PAD_LEFT);
    }

    return $source;
}

/** @return array{VmHWM_bytes:?int,VmRSS_bytes:?int} */
function wp_fts_rendered_delta_proc_status(): array
{
    if (!is_readable('/proc/self/status')) {
        return ['VmHWM_bytes' => null, 'VmRSS_bytes' => null];
    }
    $lines = file('/proc/self/status', FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return ['VmHWM_bytes' => null, 'VmRSS_bytes' => null];
    }
    $values = [];
    foreach ($lines as $line) {
        $separator = strpos($line, ':');
        if ($separator === false) {
            continue;
        }
        $key = substr($line, 0, $separator);
        if (!in_array($key, ['VmHWM', 'VmRSS'], true)) {
            continue;
        }
        $parts = array_values(array_filter(
            explode(' ', trim(substr($line, $separator + 1))),
            static fn(string $part): bool => $part !== ''
        ));
        if (count($parts) >= 2 && ctype_digit($parts[0]) && strtolower($parts[1]) === 'kb') {
            $values[$key] = (int) $parts[0] * 1024;
        }
    }

    return [
        'VmHWM_bytes' => $values['VmHWM'] ?? null,
        'VmRSS_bytes' => $values['VmRSS'] ?? null,
    ];
}

try {
    $case = (string) ($argv[1] ?? '');
    $raw = '';
    $rendered = '';
    if ($case === 'accepted_20000') {
        $raw = wp_fts_rendered_delta_source('raw', 10000);
        $rendered = wp_fts_rendered_delta_source('dynamic', 10000);
    } elseif ($case === 'rejected_20001') {
        $raw = wp_fts_rendered_delta_source('raw', 10000);
        $rendered = wp_fts_rendered_delta_source('dynamic', 10001);
    } elseif ($case === 'rejected_near_2m') {
        $raw = 'base';
        $rendered = str_repeat('z ', intdiv(WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES, 2));
    } else {
        throw new InvalidArgumentException('Unknown rendered-delta containment case.');
    }

    $post = (object) [
        'ID' => 7001,
        'post_title' => '',
        'post_content' => $raw,
        'post_excerpt' => '',
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-07-18 00:00:00',
    ];
    $extractor = new WP_FTS_PostContentExtractor();
    $peakBefore = memory_get_peak_usage(true);
    $started = microtime(true);
    $error = null;
    $renderedField = '';
    try {
        $result = $extractor->extract($post, [
            'render_content_callback' => static fn(): string => $rendered,
            'rendered_text_limit' => 200000,
        ]);
        foreach ($result['fields'] as $field) {
            if (($field['name'] ?? '') === 'rendered') {
                $renderedField = (string) ($field['text'] ?? '');
                break;
            }
        }
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
        'case' => $case,
        'error' => $error,
        'rendered_field_bytes' => strlen($renderedField),
        'rendered_field_occurrences' => $renderedField === '' ? 0 : substr_count($renderedField, ' ') + 1,
        'elapsed_seconds' => $elapsed,
        'php_peak_bytes' => $peakAfter,
        'php_peak_delta_bytes' => max(0, $peakAfter - $peakBefore),
        'proc_status' => wp_fts_rendered_delta_proc_status(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}
