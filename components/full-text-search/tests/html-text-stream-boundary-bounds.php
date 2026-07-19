<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/** @return array<string,int|float|bool> */
function wp_fts_html_boundary_measure(int $paragraphs): array
{
    $paragraphText = str_repeat('x', 202);
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $memoryBefore = memory_get_usage(true);
    $source = str_repeat('<p>' . $paragraphText . '</p>', $paragraphs);
    $expected = str_repeat($paragraphText . ' ', $paragraphs - 1) . $paragraphText;

    $started = hrtime(true);
    WP_FTS_Analysis_Limits::assert_source_bytes($source);
    WP_FTS_Html_Text_Stream::assert_analysis_markup_limits($source);
    $visible = WP_FTS_Html_Text_Stream::visible_text($source);
    $elapsedMs = (hrtime(true) - $started) / 1_000_000;

    $usage = function_exists('getrusage') ? getrusage() : false;
    $peakRss = is_array($usage) ? max(0, (int) ($usage['ru_maxrss'] ?? 0)) : 0;
    if ($peakRss > 0 && PHP_OS_FAMILY !== 'Darwin') {
        $peakRss *= 1024;
    }

    return [
        'paragraphs' => $paragraphs,
        'markup_tokens' => $paragraphs * 2,
        'source_bytes' => strlen($source),
        'visible_bytes' => strlen($visible),
        'exact_output' => $visible === $expected,
        'elapsed_ms' => $elapsedMs,
        'allocation_delta_bytes' => memory_get_peak_usage(true) - $memoryBefore,
        'peak_rss_bytes' => $peakRss > 0 ? $peakRss : memory_get_peak_usage(true),
    ];
}

if (isset($argv[1]) && str_starts_with($argv[1], '--measure=')) {
    $paragraphs = (int) substr($argv[1], strlen('--measure='));
    if (!in_array($paragraphs, [5000, 10000], true)) {
        fwrite(STDERR, "Unsupported HTML boundary measurement size.\n");
        exit(20);
    }

    echo json_encode(wp_fts_html_boundary_measure($paragraphs), JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

$wp_fts_html_boundary_checks = 0;

/** Records one assertion and throws when an HTML-stream boundary invariant fails. */
function wp_fts_html_boundary_check(bool $condition, string $message): void
{
    global $wp_fts_html_boundary_checks;
    $wp_fts_html_boundary_checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string,int|float|bool> */
function wp_fts_html_boundary_run(int $paragraphs): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('proc_open() is required for the fresh-process HTML boundary proof.');
    }

    $process = proc_open(
        [
            PHP_BINARY,
            '-n',
            '-d',
            'memory_limit=128M',
            '-d',
            'max_execution_time=8',
            __FILE__,
            '--measure=' . $paragraphs,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        __DIR__
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not launch the fresh-process HTML boundary proof.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException("Fresh-process HTML boundary proof failed ({$exit}): {$stderr}");
    }

    $measurement = json_decode(trim((string) $stdout), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($measurement)) {
        throw new RuntimeException('Fresh-process HTML boundary proof returned no measurement.');
    }

    return $measurement;
}

$half = wp_fts_html_boundary_run(5000);
$full = wp_fts_html_boundary_run(10000);

wp_fts_html_boundary_check(
    ($full['source_bytes'] ?? null) === 2090000 && ($full['markup_tokens'] ?? null) === 20000,
    'the full boundary must stay just below 2 MiB and exercise exactly 20,000 markup tokens'
);
wp_fts_html_boundary_check(
    ($full['visible_bytes'] ?? null) === 2029999 && ($full['exact_output'] ?? null) === true,
    'the full boundary must preserve every visible byte and block separator in order'
);
wp_fts_html_boundary_check(
    (float) ($full['elapsed_ms'] ?? INF) < 2000.0,
    'the accepted near-2 MiB boundary must finish in less than two seconds on the low-host lane'
);
wp_fts_html_boundary_check(
    (int) ($full['allocation_delta_bytes'] ?? PHP_INT_MAX) <= 16 * 1024 * 1024,
    'the accepted near-2 MiB boundary must allocate at most 16 MiB above its fresh baseline'
);
wp_fts_html_boundary_check(
    (int) ($full['peak_rss_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024,
    'the accepted near-2 MiB boundary must keep fresh-process RSS at or below 128 MiB'
);
wp_fts_html_boundary_check(
    ($half['exact_output'] ?? null) === true
        && (float) ($full['elapsed_ms'] ?? INF) <= ((float) ($half['elapsed_ms'] ?? 0.0) * 3.0) + 50.0,
    'doubling accepted boundary input must remain linear rather than quadrupling scan time'
);
wp_fts_html_boundary_check(
    WP_FTS_Html_Text_Stream::visible_text('<p>A&nbsp;</p><p>B</p>') === 'A B',
    'bounded trailing-codepoint checks must retain Unicode whitespace semantics'
);
wp_fts_html_boundary_check(
    WP_FTS_Html_Text_Stream::visible_text("A\xFF</p><p>B") === 'A B',
    'bounded trailing-codepoint checks must retain malformed UTF-8 repair behavior'
);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    echo json_encode([
        'checks' => $wp_fts_html_boundary_checks,
        'half' => $half,
        'full' => $full,
    ], JSON_THROW_ON_ERROR), "\n";
}

return $wp_fts_html_boundary_checks;
