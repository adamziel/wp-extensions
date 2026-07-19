<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/** @return array<string,int|float|bool|string> */
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

    $peakRss = wp_fts_html_boundary_proc_peak_rss_bytes();
    $peakRssSource = 'proc-self-status-vmhwm';
    if ($peakRss === null) {
        $peakRss = wp_fts_html_boundary_getrusage_peak_rss_bytes();
        $peakRssSource = PHP_OS_FAMILY === 'Darwin'
            ? 'getrusage-darwin-bytes'
            : 'getrusage-kib';
    }
    if ($peakRss === null) {
        $peakRss = memory_get_peak_usage(true);
        $peakRssSource = 'php-allocation-peak';
    }

    return [
        'paragraphs' => $paragraphs,
        'markup_tokens' => $paragraphs * 2,
        'source_bytes' => strlen($source),
        'visible_bytes' => strlen($visible),
        'exact_output' => $visible === $expected,
        'elapsed_ms' => $elapsedMs,
        'allocation_delta_bytes' => memory_get_peak_usage(true) - $memoryBefore,
        'peak_rss_bytes' => $peakRss,
        'peak_rss_source' => $peakRssSource,
    ];
}

/** Read Linux's authoritative process RSS high-water mark without buffering `/proc`. */
function wp_fts_html_boundary_proc_peak_rss_bytes(): ?int
{
    $handle = @fopen('/proc/self/status', 'rb');
    if (!is_resource($handle)) {
        return null;
    }

    try {
        while (($line = fgets($handle, 256)) !== false) {
            if (!str_starts_with($line, 'VmHWM:')) {
                continue;
            }

            $peak = wp_fts_html_boundary_parse_vm_hwm_line($line);
            return $peak !== null && $peak > 0 ? $peak : null;
        }
    } finally {
        fclose($handle);
    }

    return null;
}

/** Parse the exact `VmHWM: <kilobytes> kB` line published by procfs. */
function wp_fts_html_boundary_parse_vm_hwm_line(string $line): ?int
{
    if (!str_starts_with($line, 'VmHWM:')) {
        return null;
    }

    $length = strlen($line);
    $offset = strlen('VmHWM:');
    while ($offset < $length && wp_fts_html_boundary_is_ascii_whitespace($line[$offset])) {
        $offset++;
    }

    $kilobytes = 0;
    $digits = 0;
    while ($offset < $length) {
        $byte = ord($line[$offset]);
        if ($byte < 48 || $byte > 57) {
            break;
        }
        $digit = $byte - 48;
        if ($kilobytes > intdiv(PHP_INT_MAX - $digit, 10)) {
            return null;
        }
        $kilobytes = ($kilobytes * 10) + $digit;
        $digits++;
        $offset++;
    }
    if ($digits === 0) {
        return null;
    }

    while ($offset < $length && wp_fts_html_boundary_is_ascii_whitespace($line[$offset])) {
        $offset++;
    }
    if (substr_compare($line, 'kB', $offset, 2) !== 0) {
        return null;
    }
    $offset += 2;
    while ($offset < $length && wp_fts_html_boundary_is_ascii_whitespace($line[$offset])) {
        $offset++;
    }
    if ($offset !== $length || $kilobytes > intdiv(PHP_INT_MAX, 1024)) {
        return null;
    }

    return $kilobytes * 1024;
}

/** Fall back to the platform's `getrusage(2)` unit convention. */
function wp_fts_html_boundary_getrusage_peak_rss_bytes(): ?int
{
    if (!function_exists('getrusage')) {
        return null;
    }
    $usage = getrusage();
    $peak = is_array($usage) ? (int) ($usage['ru_maxrss'] ?? 0) : 0;
    if ($peak <= 0) {
        return null;
    }
    if (PHP_OS_FAMILY === 'Darwin') {
        return $peak;
    }
    if ($peak > intdiv(PHP_INT_MAX, 1024)) {
        return null;
    }

    return $peak * 1024;
}

/** Recognize only the ASCII spacing bytes permitted around procfs fields. */
function wp_fts_html_boundary_is_ascii_whitespace(string $byte): bool
{
    return $byte === ' '
        || $byte === "\t"
        || $byte === "\n"
        || $byte === "\r"
        || $byte === "\f";
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

/** @return array<string,int|float|bool|string> */
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
    !is_readable('/proc/self/status')
        || (
            ($half['peak_rss_source'] ?? null) === 'proc-self-status-vmhwm'
            && ($full['peak_rss_source'] ?? null) === 'proc-self-status-vmhwm'
        ),
    'Linux fresh-process RSS must come from the procfs VmHWM field rather than ambiguous getrusage units'
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
wp_fts_html_boundary_check(
    wp_fts_html_boundary_parse_vm_hwm_line("VmHWM:\t  131072 kB\n") === 128 * 1024 * 1024,
    'procfs VmHWM parsing must convert its documented KiB value to bytes exactly once'
);
wp_fts_html_boundary_check(
    wp_fts_html_boundary_parse_vm_hwm_line("VmHWM:\t128 MB\n") === null
        && wp_fts_html_boundary_parse_vm_hwm_line("VmHWM:\t-1 kB\n") === null,
    'procfs VmHWM parsing must reject unknown units and non-decimal values'
);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    echo json_encode([
        'checks' => $wp_fts_html_boundary_checks,
        'half' => $half,
        'full' => $full,
    ], JSON_THROW_ON_ERROR), "\n";
}

return $wp_fts_html_boundary_checks;
