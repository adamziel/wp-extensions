<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$wp_fts_jieba_multi_run_checks = 0;
$wp_fts_jieba_fresh_case = null;
foreach (is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $argument) {
    if (str_starts_with((string) $argument, '--fresh-process=')) {
        $wp_fts_jieba_fresh_case = substr((string) $argument, strlen('--fresh-process='));
        break;
    }
}

/** Records one assertion and throws when a multi-run Jieba invariant fails. */
function wp_fts_jieba_multi_run_check(bool $condition, string $message): void
{
    global $wp_fts_jieba_multi_run_checks;
    $wp_fts_jieba_multi_run_checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Returns the private dictionary-scan count for containment assertions. */
function wp_fts_jieba_multi_run_scan_count(WP_FTS_ChineseJiebaSegmenter $segmenter): int
{
    return (int) (new ReflectionProperty($segmenter, 'dictionaryScanCount'))->getValue($segmenter);
}

/** @return array{bytes:int,source:string} */
function wp_fts_jieba_multi_run_rss_sample(string $field): array
{
    $lines = @file('/proc/self/status', FILE_IGNORE_NEW_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            if ($name !== $field) {
                continue;
            }
            $parts = array_values(array_filter(
                explode(' ', trim($value)),
                static fn(string $part): bool => $part !== ''
            ));
            if (isset($parts[0])
                && $parts[0] !== ''
                && strspn($parts[0], '0123456789') === strlen($parts[0])
            ) {
                return ['bytes' => (int) $parts[0] * 1024, 'source' => 'linux_proc_status'];
            }
        }
    }

    return ['bytes' => memory_get_usage(true), 'source' => 'php_allocator_fallback'];
}

/** Returns one resident-memory measurement in bytes. */
function wp_fts_jieba_multi_run_rss_bytes(string $field): int
{
    return wp_fts_jieba_multi_run_rss_sample($field)['bytes'];
}

/** Encodes the fixture's three-byte Unicode code point as UTF-8. */
function wp_fts_jieba_multi_run_utf8(int $codepoint): string
{
    return chr(0xE0 | ($codepoint >> 12))
        . chr(0x80 | (($codepoint >> 6) & 0x3F))
        . chr(0x80 | ($codepoint & 0x3F));
}

/** @param string[] $characters @return string[] */
function wp_fts_jieba_multi_run_permutation(array $characters, int $ordinal): array
{
    $remaining = array_values($characters);
    $permutation = [];
    while ($remaining !== []) {
        $index = $ordinal % count($remaining);
        $ordinal = intdiv($ordinal, count($remaining));
        $permutation[] = $remaining[$index];
        array_splice($remaining, $index, 1);
    }

    return $permutation;
}

/** Extracts the first UTF-8 character without requiring mbstring. */
function wp_fts_jieba_multi_run_first_character(string $word): string
{
    if ($word === '') {
        return '';
    }
    $firstByte = ord($word[0]);
    $length = 1;
    if (($firstByte & 0xE0) === 0xC0) {
        $length = 2;
    } elseif (($firstByte & 0xF0) === 0xE0) {
        $length = 3;
    } elseif (($firstByte & 0xF8) === 0xF0) {
        $length = 4;
    }

    return substr($word, 0, $length);
}

/**
 * Return the distinct prefixes with the most eligible pinned dictionary rows.
 *
 * @return array{
 *   run:string,
 *   prefixes:string[],
 *   prefix_count:int,
 *   candidate_count:int,
 *   candidate_bytes:int,
 *   source_prefix_count:int,
 *   source_candidate_count:int,
 *   source_candidate_bytes:int
 * }
 */
function wp_fts_jieba_multi_run_high_fanout_prefixes(
    string $sourcePath,
    int $prefixLimit,
    bool $hanOnly = true
): array
{
    $counts = [];
    $bytes = [];
    $handle = fopen($sourcePath, 'rb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not open the pinned Jieba source for the high-fanout proof.');
    }
    try {
        while (($line = fgets($handle)) !== false) {
            $separator = strpos($line, ' ');
            if ($separator === false) {
                continue;
            }
            $word = substr($line, 0, $separator);
            $first = wp_fts_jieba_multi_run_first_character($word);
            if ($first === '' || strlen($word) <= strlen($first) || preg_match('/\p{Han}/u', $word) !== 1) {
                continue;
            }
            $counts[$first] = ($counts[$first] ?? 0) + 1;
            $bytes[$first] = ($bytes[$first] ?? 0) + strlen($word);
        }
    } finally {
        fclose($handle);
    }

    $sourcePrefixCount = count($counts);
    $sourceCandidateCount = array_sum($counts);
    $sourceCandidateBytes = array_sum($bytes);
    $prefixes = array_map('strval', array_keys($counts));
    if ($hanOnly) {
        $prefixes = array_values(array_filter(
            $prefixes,
            static fn(string $prefix): bool => preg_match('/^\p{Han}$/u', $prefix) === 1
        ));
    }
    usort($prefixes, static function (string $left, string $right) use ($counts): int {
        $byCount = $counts[$right] <=> $counts[$left];

        return $byCount !== 0 ? $byCount : strcmp($left, $right);
    });
    $prefixes = array_slice($prefixes, 0, $prefixLimit);
    $candidateCount = 0;
    $candidateBytes = 0;
    foreach ($prefixes as $prefix) {
        $candidateCount += $counts[$prefix];
        $candidateBytes += $bytes[$prefix];
    }

    return [
        'run' => implode('', $prefixes),
        'prefixes' => $prefixes,
        'prefix_count' => count($prefixes),
        'candidate_count' => $candidateCount,
        'candidate_bytes' => $candidateBytes,
        'source_prefix_count' => $sourcePrefixCount,
        'source_candidate_count' => $sourceCandidateCount,
        'source_candidate_bytes' => $sourceCandidateBytes,
    ];
}

/**
 * @param string[] $runs
 * @return array{
 *   elapsed_seconds:float,
 *   complete_dictionary_scans:int,
 *   indexed_range_reads:int,
 *   php_peak_delta_bytes:int,
 *   rss_delta_bytes:int,
 *   rss_peak_delta_bytes:int,
 *   rss_peak_bytes:int,
 *   run_count:int,
 *   term_count:int
 * }
 */
function wp_fts_jieba_measure_run_batch(WP_FTS_ChineseJiebaSegmenter $segmenter, array $runs): array
{
    global $wp_fts_jieba_fresh_case;

    $freshProcess = is_string($wp_fts_jieba_fresh_case);
    $canResetPeak = function_exists('memory_reset_peak_usage');
    if ($canResetPeak) {
        memory_reset_peak_usage();
    }
    $memoryBefore = memory_get_usage(true);
    $memoryPeakBefore = memory_get_peak_usage(true);
    $rssBeforeSample = wp_fts_jieba_multi_run_rss_sample('VmRSS');
    $scansBefore = wp_fts_jieba_multi_run_scan_count($segmenter);
    $rangeReadsBefore = (int) (new ReflectionProperty($segmenter, 'indexedRangeReadCount'))->getValue($segmenter);
    $termCount = 0;
    $started = microtime(true);
    foreach ($runs as $run) {
        $termCount += count($segmenter($run, 'zh'));
    }
    $elapsed = microtime(true) - $started;
    $memoryAfter = memory_get_usage(true);
    $memoryPeakAfter = memory_get_peak_usage(true);
    $rssAfterSample = wp_fts_jieba_multi_run_rss_sample('VmRSS');
    $rssPeakAfterSample = wp_fts_jieba_multi_run_rss_sample('VmHWM');
    $rssBefore = $rssBeforeSample['bytes'];
    $rssAfter = $rssAfterSample['bytes'];
    $rssPeakAfter = $rssPeakAfterSample['bytes'];
    $rssSource = count(array_unique([
        $rssBeforeSample['source'],
        $rssAfterSample['source'],
        $rssPeakAfterSample['source'],
    ])) === 1 ? $rssBeforeSample['source'] : 'mixed_unusable';

    // Only a resettable peak or a fresh process can attribute a peak to this
    // workload. PHP 8.1's long-lived parent retains the best diagnostic it can
    // provide, but acceptance comes from the conservative fresh child below.
    $phpPeakDelta = $canResetPeak || $freshProcess
        ? max(0, $memoryPeakAfter - $memoryBefore)
        : max(0, $memoryAfter - $memoryBefore, $memoryPeakAfter - $memoryPeakBefore);

    return [
        'elapsed_seconds' => $elapsed,
        'complete_dictionary_scans' => wp_fts_jieba_multi_run_scan_count($segmenter) - $scansBefore,
        'indexed_range_reads' => (int) (new ReflectionProperty($segmenter, 'indexedRangeReadCount'))->getValue($segmenter)
            - $rangeReadsBefore,
        'php_peak_delta_bytes' => $phpPeakDelta,
        'php_peak_delta_authoritative' => $canResetPeak || $freshProcess,
        'php_peak_delta_source' => $canResetPeak
            ? 'reset_peak_after_minus_usage_before'
            : ($freshProcess
                ? 'fresh_lifetime_peak_after_minus_usage_before'
                : 'cumulative_parent_diagnostic'),
        'rss_delta_bytes' => max(0, $rssAfter - $rssBefore),
        'rss_peak_delta_bytes' => max(0, $rssPeakAfter - $rssBefore),
        'rss_peak_delta_authoritative' => $freshProcess && $rssSource === 'linux_proc_status',
        'rss_peak_bytes' => $rssPeakAfter,
        'rss_source' => $rssSource,
        'input_bytes' => array_sum(array_map('strlen', $runs)),
        'input_sha256' => hash('sha256', implode('', array_map(
            static fn(string $run): string => strlen($run) . ':' . $run,
            $runs
        ))),
        'input_unit_count' => count($runs),
        'run_count' => count($runs),
        'term_count' => $termCount,
    ];
}

/**
 * @return array{
 *   elapsed_seconds:float,
 *   complete_dictionary_scans:int,
 *   indexed_range_reads:int,
 *   php_peak_delta_bytes:int,
 *   rss_delta_bytes:int,
 *   rss_peak_delta_bytes:int,
 *   rss_peak_bytes:int,
 *   term_count:int
 * }
 */
function wp_fts_jieba_measure_multi_run(
    WP_FTS_ChineseJiebaSegmenter $segmenter,
    string $text,
    string $expectedToken
): array {
    global $wp_fts_jieba_fresh_case;

    $freshProcess = is_string($wp_fts_jieba_fresh_case);
    $pipeline = new WP_FTS_LanguagePipeline([
        'cjk_tokenizer' => $segmenter,
        'enable_stemming' => false,
    ]);
    $canResetPeak = function_exists('memory_reset_peak_usage');
    if ($canResetPeak) {
        memory_reset_peak_usage();
    }
    $memoryBefore = memory_get_usage(true);
    $memoryPeakBefore = memory_get_peak_usage(true);
    $rssBeforeSample = wp_fts_jieba_multi_run_rss_sample('VmRSS');
    $scansBefore = wp_fts_jieba_multi_run_scan_count($segmenter);
    $rangeReadsBefore = (int) (new ReflectionProperty($segmenter, 'indexedRangeReadCount'))->getValue($segmenter);
    $started = microtime(true);
    $terms = $pipeline->analyze($text, 'zh');
    $elapsed = microtime(true) - $started;
    $memoryAfter = memory_get_usage(true);
    $memoryPeakAfter = memory_get_peak_usage(true);
    $rssAfterSample = wp_fts_jieba_multi_run_rss_sample('VmRSS');
    $rssPeakAfterSample = wp_fts_jieba_multi_run_rss_sample('VmHWM');
    $rssBefore = $rssBeforeSample['bytes'];
    $rssAfter = $rssAfterSample['bytes'];
    $rssPeakAfter = $rssPeakAfterSample['bytes'];
    $rssSource = count(array_unique([
        $rssBeforeSample['source'],
        $rssAfterSample['source'],
        $rssPeakAfterSample['source'],
    ])) === 1 ? $rssBeforeSample['source'] : 'mixed_unusable';

    // See wp_fts_jieba_measure_run_batch(): the fresh child is authoritative;
    // an unresettable long-lived parent is explicitly diagnostic.
    $phpPeakDelta = $canResetPeak || $freshProcess
        ? max(0, $memoryPeakAfter - $memoryBefore)
        : max(0, $memoryAfter - $memoryBefore, $memoryPeakAfter - $memoryPeakBefore);

    wp_fts_jieba_multi_run_check(
        in_array($expectedToken, $terms, true),
        'the measured analysis should preserve its expected fallback token'
    );

    return [
        'elapsed_seconds' => $elapsed,
        'complete_dictionary_scans' => wp_fts_jieba_multi_run_scan_count($segmenter) - $scansBefore,
        'indexed_range_reads' => (int) (new ReflectionProperty($segmenter, 'indexedRangeReadCount'))->getValue($segmenter)
            - $rangeReadsBefore,
        'php_peak_delta_bytes' => $phpPeakDelta,
        'php_peak_delta_authoritative' => $canResetPeak || $freshProcess,
        'php_peak_delta_source' => $canResetPeak
            ? 'reset_peak_after_minus_usage_before'
            : ($freshProcess
                ? 'fresh_lifetime_peak_after_minus_usage_before'
                : 'cumulative_parent_diagnostic'),
        'rss_delta_bytes' => max(0, $rssAfter - $rssBefore),
        'rss_peak_delta_bytes' => max(0, $rssPeakAfter - $rssBefore),
        'rss_peak_delta_authoritative' => $freshProcess && $rssSource === 'linux_proc_status',
        'rss_peak_bytes' => $rssPeakAfter,
        'rss_source' => $rssSource,
        'input_bytes' => strlen($text),
        'input_sha256' => hash('sha256', $text),
        'input_unit_count' => $text === '' ? 0 : substr_count($text, '，') + 1,
        'term_count' => count($terms),
    ];
}

/**
 * @param array<string,mixed> $measurement
 * @param array<string,mixed> $extra
 * @return array<string,mixed>
 */
function wp_fts_jieba_multi_run_workload_identity(array $measurement, array $extra = []): array
{
    return [
        'input_bytes' => $measurement['input_bytes'] ?? null,
        'input_sha256' => $measurement['input_sha256'] ?? null,
        'input_unit_count' => $measurement['input_unit_count'] ?? null,
    ] + $extra;
}

/** @param array<string,mixed> $measurement */
function wp_fts_jieba_parent_php_delta_within(array $measurement, int $ceiling): bool
{
    return ($measurement['php_peak_delta_authoritative'] ?? false) !== true
        || (int) ($measurement['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= $ceiling;
}

/** @param array<string,mixed> $measurement */
function wp_fts_jieba_parent_rss_deltas_within(array $measurement, int $ceiling): bool
{
    return ($measurement['rss_peak_delta_authoritative'] ?? false) !== true
        || (
            (int) ($measurement['rss_delta_bytes'] ?? PHP_INT_MAX) <= $ceiling
            && (int) ($measurement['rss_peak_delta_bytes'] ?? PHP_INT_MAX) <= $ceiling
        );
}

if ($wp_fts_jieba_fresh_case !== null) {
    $freshPhpDeltaCeiling = 25165824;
    $freshRssDeltaCeiling = 25165824;
    $freshMeasurement = null;
    $freshSegmenter = null;
    $freshWorkloadEvidence = [];
    $wideCharacters = ['一', '中', '大', '三', '王', '不', '第', '马', '李', '二', '小', '金', '十', '张', '高', '阿', '无'];

    switch ($wp_fts_jieba_fresh_case) {
        case 'cold_256':
        case 'saturated_256':
            $runs = [];
            for ($tail = 0; $tail < 256; $tail++) {
                $runs[] = '大' . wp_fts_jieba_multi_run_utf8(0x4E10 + $tail);
            }
            $freshSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
            if ($wp_fts_jieba_fresh_case === 'saturated_256' && $freshSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
                $freshSegmenter('一一', 'zh');
            }
            if ($freshSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
                $freshMeasurement = wp_fts_jieba_measure_multi_run(
                    $freshSegmenter,
                    implode('，', $runs),
                    $runs[array_key_last($runs)]
                );
            }
            break;

        case 'repeated_wide_cold':
        case 'repeated_wide_saturated':
            $wideRun = implode('', $wideCharacters);
            $freshSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
            if ($wp_fts_jieba_fresh_case === 'repeated_wide_saturated'
                && $freshSegmenter instanceof WP_FTS_ChineseJiebaSegmenter
            ) {
                $freshSegmenter('一一', 'zh');
            }
            if ($freshSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
                $freshMeasurement = wp_fts_jieba_measure_multi_run(
                    $freshSegmenter,
                    implode('，', array_fill(0, 300, $wideRun)),
                    '一中大三'
                );
            }
            break;

        case 'permuted_wide':
            $permutedRuns = [];
            for ($run = 0; $run < 300; $run++) {
                $permutedRuns[] = implode('', wp_fts_jieba_multi_run_permutation($wideCharacters, $run));
            }
            $lastPermutation = wp_fts_jieba_multi_run_permutation($wideCharacters, 299);
            $freshSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
            if ($freshSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
                $freshMeasurement = wp_fts_jieba_measure_multi_run(
                    $freshSegmenter,
                    implode('，', $permutedRuns),
                    implode('', array_slice($lastPermutation, 0, 4))
                );
            }
            break;

        case 'changing_prefix':
            $commonCharacters = array_slice($wideCharacters, 0, 16);
            $changingRuns = [];
            for ($run = 0; $run < 300; $run++) {
                $characters = $commonCharacters;
                $characters[] = wp_fts_jieba_multi_run_utf8(0x6000 + $run);
                $changingRuns[] = implode('', wp_fts_jieba_multi_run_permutation($characters, $run));
            }
            $lastCharacters = $commonCharacters;
            $lastCharacters[] = wp_fts_jieba_multi_run_utf8(0x6000 + 299);
            $lastCharacters = wp_fts_jieba_multi_run_permutation($lastCharacters, 299);
            $freshSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
            if ($freshSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
                $freshMeasurement = wp_fts_jieba_measure_multi_run(
                    $freshSegmenter,
                    implode('，', $changingRuns),
                    implode('', array_slice($lastCharacters, 0, 4))
                );
            }
            break;

        case 'distinct_prefix_sets':
            $freshPhpDeltaCeiling = 41943040;
            $freshRssDeltaCeiling = 41943040;
            $distinctRuns = [];
            for ($run = 0; $run < 300; $run++) {
                $characters = [];
                for ($offset = 0; $offset < 17; $offset++) {
                    $characters[] = wp_fts_jieba_multi_run_utf8(0x4E00 + ($run * 17) + $offset);
                }
                $distinctRuns[] = implode('', $characters);
            }
            $lastCharacters = [];
            for ($offset = 0; $offset < 17; $offset++) {
                $lastCharacters[] = wp_fts_jieba_multi_run_utf8(0x4E00 + (299 * 17) + $offset);
            }
            $freshSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
            if ($freshSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
                $freshMeasurement = wp_fts_jieba_measure_multi_run(
                    $freshSegmenter,
                    implode('，', $distinctRuns),
                    implode('', array_slice($lastCharacters, 0, 4))
                );
            }
            break;

        case 'maximum_distinct':
            $maximumRuns = [];
            for ($run = 0; $run < WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES; $run++) {
                $maximumRuns[] = wp_fts_jieba_multi_run_utf8(0x4E00 + $run);
            }
            $freshSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
            if ($freshSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
                $freshMeasurement = wp_fts_jieba_measure_multi_run(
                    $freshSegmenter,
                    implode('，', $maximumRuns),
                    $maximumRuns[array_key_last($maximumRuns)]
                );
            }
            break;

        case 'maximum_fanout':
            $freshPhpDeltaCeiling = 67108864;
            $freshRssDeltaCeiling = 67108864;
            $maximumFanout = wp_fts_jieba_multi_run_high_fanout_prefixes(
                WP_FTS_ChineseJiebaSegmenter::default_source_file(),
                1365
            );
            $freshWorkloadEvidence = [
                'run_bytes' => strlen($maximumFanout['run']),
                'prefix_count' => $maximumFanout['prefix_count'],
                'candidate_count' => $maximumFanout['candidate_count'],
                'candidate_bytes' => $maximumFanout['candidate_bytes'],
            ];
            $freshSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
            if ($freshSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
                $freshMeasurement = wp_fts_jieba_measure_multi_run(
                    $freshSegmenter,
                    $maximumFanout['run'],
                    substr($maximumFanout['run'], 0, 12)
                );
            }
            break;

        case 'complete_pinned_cache':
            $freshPhpDeltaCeiling = 67108864;
            $freshRssDeltaCeiling = 67108864;
            $completePinned = wp_fts_jieba_multi_run_high_fanout_prefixes(
                WP_FTS_ChineseJiebaSegmenter::default_source_file(),
                PHP_INT_MAX
            );
            $freshWorkloadEvidence = [
                'prefix_count' => $completePinned['prefix_count'],
                'candidate_count' => $completePinned['candidate_count'],
                'candidate_bytes' => $completePinned['candidate_bytes'],
                'source_prefix_count' => $completePinned['source_prefix_count'],
                'source_candidate_count' => $completePinned['source_candidate_count'],
                'source_candidate_bytes' => $completePinned['source_candidate_bytes'],
            ];
            $completeRuns = array_map(
                static fn(array $prefixes): string => implode('', $prefixes),
                array_chunk($completePinned['prefixes'], 1365)
            );
            $freshSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
            if ($freshSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
                $freshMeasurement = wp_fts_jieba_measure_run_batch($freshSegmenter, $completeRuns);
            }
            break;

        default:
            throw new RuntimeException('Unknown isolated Jieba memory case.');
    }

    if (!$freshSegmenter instanceof WP_FTS_ChineseJiebaSegmenter || !is_array($freshMeasurement)) {
        throw new RuntimeException('The pinned Jieba segmenter should load for its isolated memory proof.');
    }
    $freshWorkloadEvidence = wp_fts_jieba_multi_run_workload_identity(
        $freshMeasurement,
        $freshWorkloadEvidence
    );
    $freshRssPeak = wp_fts_jieba_multi_run_rss_sample('VmHWM');
    $freshProcessEvidence = [
        'php_peak_bytes' => memory_get_peak_usage(true),
        'rss_peak_bytes' => $freshRssPeak['bytes'],
        'rss_source' => $freshRssPeak['source'],
    ];
    wp_fts_jieba_multi_run_check(
        $freshMeasurement['php_peak_delta_bytes'] <= $freshPhpDeltaCeiling,
        'the isolated Jieba workload should retain its PHP allocation-delta ceiling'
    );
    wp_fts_jieba_multi_run_check(
        max($freshMeasurement['rss_delta_bytes'], $freshMeasurement['rss_peak_delta_bytes'])
            <= $freshRssDeltaCeiling,
        'the isolated Jieba workload should retain its RSS allocation-delta ceiling'
    );
    wp_fts_jieba_multi_run_check(
        $freshProcessEvidence['php_peak_bytes'] <= 134217728,
        'the isolated Jieba workload should stay within a 128 MiB PHP peak'
    );
    wp_fts_jieba_multi_run_check(
        $freshProcessEvidence['rss_peak_bytes'] <= 134217728,
        'the isolated Jieba workload should stay within a 128 MiB RSS peak'
    );
    if (PHP_OS_FAMILY === 'Linux') {
        wp_fts_jieba_multi_run_check(
            ($freshMeasurement['rss_source'] ?? null) === 'linux_proc_status'
                && $freshProcessEvidence['rss_source'] === 'linux_proc_status',
            'the isolated Linux RSS proof must come from /proc/self/status'
        );
    }
    echo json_encode([
        'schema' => 'jieba-isolated-memory-case-v2',
        'status' => 'pass',
        'case' => $wp_fts_jieba_fresh_case,
        'memory_authority' => 'fresh_process_conservative_peak_attribution',
        'memory_limit' => ini_get('memory_limit'),
        'measurement' => $freshMeasurement,
        'process' => $freshProcessEvidence,
        'workload' => $freshWorkloadEvidence,
    ], JSON_THROW_ON_ERROR), "\n";

    return $wp_fts_jieba_multi_run_checks;
}

$sourcePath = WP_FTS_ChineseJiebaSegmenter::default_source_file();
$sourceBytes = is_file($sourcePath) ? filesize($sourcePath) : false;
$lookupEvidence = WP_FTS_ChineseJiebaSegmenter::default_lookup_evidence();
wp_fts_jieba_multi_run_check(
    $sourceBytes === WP_FTS_ChineseJiebaSegmenter::SOURCE_BYTE_SIZE,
    'the exact-size pinned Jieba dictionary should be available'
);
wp_fts_jieba_multi_run_check($lookupEvidence['available'], 'the attested Jieba range index should be available');
wp_fts_jieba_multi_run_check(
    $lookupEvidence['byte_size'] < intdiv((int) $sourceBytes, 15),
    'the range index should stay below one fifteenth of the dictionary source bytes'
);
wp_fts_jieba_multi_run_check(
    $lookupEvidence['range_count'] === 11783,
    'the pinned source should retain exactly 11,783 first-codepoint ranges'
);

$runs = [];
for ($tail = 0; $tail < 256; $tail++) {
    $runs[] = '大' . wp_fts_jieba_multi_run_utf8(0x4E10 + $tail);
}
$text = implode('，', $runs);
$lastRun = $runs[array_key_last($runs)];

$coldConstructionStarted = microtime(true);
$coldSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
$coldConstructionElapsed = microtime(true) - $coldConstructionStarted;
if (!$coldSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
    throw new RuntimeException('The pinned Jieba segmenter should load for the cold multi-run proof.');
}
wp_fts_jieba_multi_run_check(
    (int) (new ReflectionProperty($coldSegmenter, 'sourceHashScanCount'))->getValue($coldSegmenter) === 0,
    'cold pinned construction should attest the compact index without hashing all 5 MiB of source'
);
$lookupAttestation = new ReflectionMethod($coldSegmenter, 'lookup_file_is_attested');
wp_fts_jieba_multi_run_check(
    $lookupAttestation->invoke($coldSegmenter, WP_FTS_ChineseJiebaSegmenter::default_lookup_file()) === true,
    'the exact committed lookup should pass the constructor attestation helper'
);
$missingLookup = sys_get_temp_dir() . '/wp-fts-missing-jieba-index-' . bin2hex(random_bytes(6));
wp_fts_jieba_multi_run_check(
    $lookupAttestation->invoke($coldSegmenter, $missingLookup) === false,
    'a missing curated lookup should fail the constructor attestation helper'
);
$corruptLookup = tempnam(sys_get_temp_dir(), 'wp-fts-corrupt-jieba-index-');
if (!is_string($corruptLookup) || !copy(WP_FTS_ChineseJiebaSegmenter::default_lookup_file(), $corruptLookup)) {
    throw new RuntimeException('Could not create the corrupt Jieba lookup fixture.');
}
try {
    $corruptHandle = fopen($corruptLookup, 'c+b');
    if (!is_resource($corruptHandle)) {
        throw new RuntimeException('Could not open the corrupt Jieba lookup fixture.');
    }
    fwrite($corruptHandle, 'X');
    fclose($corruptHandle);
    wp_fts_jieba_multi_run_check(
        $lookupAttestation->invoke($coldSegmenter, $corruptLookup) === false,
        'a byte-corrupt curated lookup should fail the constructor attestation helper'
    );
} finally {
    unlink($corruptLookup);
}
$cold = wp_fts_jieba_measure_multi_run($coldSegmenter, $text, $lastRun);
wp_fts_jieba_multi_run_check(
    in_array('中华人民共和国', $coldSegmenter('中华人民共和国', 'zh'), true),
    'the attested ranges should preserve a dictionary word longer than the four-character fallback'
);

$saturatedConstructionStarted = microtime(true);
$saturatedSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
$saturatedConstructionElapsed = microtime(true) - $saturatedConstructionStarted;
if (!$saturatedSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
    throw new RuntimeException('The pinned Jieba segmenter should load for the saturated multi-run proof.');
}
wp_fts_jieba_multi_run_check(
    (int) (new ReflectionProperty($saturatedSegmenter, 'sourceHashScanCount'))->getValue($saturatedSegmenter) === 0,
    'saturated pinned construction should not rehash all 5 MiB of source'
);
$saturatedSegmenter('一一', 'zh');
$cachedAfterPrime = (int) (new ReflectionProperty($saturatedSegmenter, 'cachedCandidateCount'))->getValue($saturatedSegmenter);
wp_fts_jieba_multi_run_check(
    $cachedAfterPrime >= 3000,
    'the saturated proof should begin with the high-fanout 一 prefix retained'
);
$saturated = wp_fts_jieba_measure_multi_run($saturatedSegmenter, $text, $lastRun);
unset($coldSegmenter, $saturatedSegmenter);

$wideCharacters = ['一', '中', '大', '三', '王', '不', '第', '马', '李', '二', '小', '金', '十', '张', '高', '阿', '无'];
$wideRun = implode('', $wideCharacters);
$wideText = implode('，', array_fill(0, 300, $wideRun));
$wideExpectedToken = '一中大三';
$wideColdSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
if (!$wideColdSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
    throw new RuntimeException('The pinned Jieba segmenter should load for the repeated-wide-run proof.');
}
$repeatedWideCold = wp_fts_jieba_measure_multi_run($wideColdSegmenter, $wideText, $wideExpectedToken);

$wideSaturatedSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
if (!$wideSaturatedSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
    throw new RuntimeException('The pinned Jieba segmenter should load for the saturated wide-run proof.');
}
$wideSaturatedSegmenter('一一', 'zh');
$repeatedWideSaturated = wp_fts_jieba_measure_multi_run(
    $wideSaturatedSegmenter,
    $wideText,
    $wideExpectedToken
);
foreach (
    ['cold' => $repeatedWideCold, 'saturated' => $repeatedWideSaturated]
    as $name => $measurement
) {
    wp_fts_jieba_multi_run_check(
        $measurement['complete_dictionary_scans'] === 0,
        "the {$name} repeated-wide-run analysis should perform no complete dictionary scan"
    );
    wp_fts_jieba_multi_run_check(
        $measurement['indexed_range_reads'] <= 20,
        "the {$name} repeated-wide-run analysis should read each of its 17 prefix ranges only once"
    );
    wp_fts_jieba_multi_run_check(
        $measurement['elapsed_seconds'] < 2.0,
        "the {$name} repeated-wide-run analysis should finish within two seconds"
    );
    wp_fts_jieba_multi_run_check(
        $measurement['term_count'] <= WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES,
        "the {$name} repeated-wide-run output should stay within the occurrence boundary"
    );
    wp_fts_jieba_multi_run_check(
        wp_fts_jieba_parent_php_delta_within($measurement, 25165824),
        "the {$name} repeated-wide-run analysis should stay within a 24 MiB PHP peak delta"
    );
    wp_fts_jieba_multi_run_check(
        wp_fts_jieba_parent_rss_deltas_within($measurement, 25165824),
        "the {$name} repeated-wide-run analysis should stay within a 24 MiB RSS delta"
    );
    wp_fts_jieba_multi_run_check(
        wp_fts_jieba_parent_rss_deltas_within($measurement, 25165824),
        "the {$name} repeated-wide-run analysis should stay within a 24 MiB RSS peak delta"
    );
}
wp_fts_jieba_multi_run_check(
    count((array) (new ReflectionProperty($wideColdSegmenter, 'runCache'))->getValue($wideColdSegmenter)) <= 256,
    'wide-run memoization should retain at most 256 complete runs'
);
wp_fts_jieba_multi_run_check(
    (int) (new ReflectionProperty($wideColdSegmenter, 'cachedRunTokenCount'))->getValue($wideColdSegmenter) <= 4096,
    'wide-run memoization should retain at most 4,096 result tokens'
);
wp_fts_jieba_multi_run_check(
    (int) (new ReflectionProperty($wideColdSegmenter, 'cachedRunBytes'))->getValue($wideColdSegmenter) <= 262144,
    'wide-run memoization should retain at most 256 KiB of run and token bytes'
);
unset($wideColdSegmenter, $wideSaturatedSegmenter);

$permutedRuns = [];
$permutedRunSet = [];
for ($run = 0; $run < 300; $run++) {
    $permutedRun = implode('', wp_fts_jieba_multi_run_permutation($wideCharacters, $run));
    $permutedRuns[] = $permutedRun;
    $permutedRunSet[$permutedRun] = true;
}
wp_fts_jieba_multi_run_check(
    count($permutedRunSet) === 300,
    'the hot-prefix adversary should contain 300 distinct permutations rather than memoizable duplicate runs'
);
$permutedSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
if (!$permutedSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
    throw new RuntimeException('The pinned Jieba segmenter should load for the permuted-wide-run proof.');
}
$lastPermutation = wp_fts_jieba_multi_run_permutation($wideCharacters, 299);
$permutedWide = wp_fts_jieba_measure_multi_run(
    $permutedSegmenter,
    implode('，', $permutedRuns),
    implode('', array_slice($lastPermutation, 0, 4))
);
wp_fts_jieba_multi_run_check(
    $permutedWide['complete_dictionary_scans'] === 0,
    '300 distinct hot-prefix permutations should perform no complete dictionary scan'
);
wp_fts_jieba_multi_run_check(
    $permutedWide['indexed_range_reads'] <= 20,
    '300 distinct hot-prefix permutations should read each populated prefix range only once'
);
wp_fts_jieba_multi_run_check(
    $permutedWide['elapsed_seconds'] < 2.0,
    '300 distinct hot-prefix permutations should finish within two seconds'
);
wp_fts_jieba_multi_run_check(
    wp_fts_jieba_parent_php_delta_within($permutedWide, 25165824),
    '300 distinct hot-prefix permutations should stay within a 24 MiB PHP peak delta'
);
wp_fts_jieba_multi_run_check(
    wp_fts_jieba_parent_rss_deltas_within($permutedWide, 25165824),
    '300 distinct hot-prefix permutations should stay within a 24 MiB attributable RSS delta'
);
unset($permutedSegmenter, $permutedRuns, $permutedRunSet, $lastPermutation);

$commonCharacters = array_slice($wideCharacters, 0, 16);
$changingRuns = [];
for ($run = 0; $run < 300; $run++) {
    $characters = $commonCharacters;
    $characters[] = wp_fts_jieba_multi_run_utf8(0x6000 + $run);
    $changingRuns[] = implode('', wp_fts_jieba_multi_run_permutation($characters, $run));
}
$changingLastCharacters = $commonCharacters;
$changingLastCharacters[] = wp_fts_jieba_multi_run_utf8(0x6000 + 299);
$changingLastCharacters = wp_fts_jieba_multi_run_permutation($changingLastCharacters, 299);
$changingSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
if (!$changingSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
    throw new RuntimeException('The pinned Jieba segmenter should load for the changing-prefix proof.');
}
$changingPrefix = wp_fts_jieba_measure_multi_run(
    $changingSegmenter,
    implode('，', $changingRuns),
    implode('', array_slice($changingLastCharacters, 0, 4))
);
wp_fts_jieba_multi_run_check(
    $changingPrefix['complete_dictionary_scans'] === 0,
    '300 runs with one changing prefix should perform no complete dictionary scan'
);
wp_fts_jieba_multi_run_check(
    $changingPrefix['indexed_range_reads'] <= 350,
    '300 runs with one changing prefix should read only the populated prefix ranges in their union'
);
wp_fts_jieba_multi_run_check(
    $changingPrefix['elapsed_seconds'] < 2.0,
    '300 runs with one changing prefix should finish within two seconds'
);
wp_fts_jieba_multi_run_check(
    wp_fts_jieba_parent_php_delta_within($changingPrefix, 25165824),
    '300 runs with one changing prefix should stay within a 24 MiB PHP peak delta'
);
wp_fts_jieba_multi_run_check(
    wp_fts_jieba_parent_rss_deltas_within($changingPrefix, 25165824),
    '300 runs with one changing prefix should stay within a 24 MiB attributable RSS delta'
);
unset($changingSegmenter, $changingRuns, $changingLastCharacters);

$distinctSetRuns = [];
for ($run = 0; $run < 300; $run++) {
    $characters = [];
    for ($offset = 0; $offset < 17; $offset++) {
        $characters[] = wp_fts_jieba_multi_run_utf8(0x4E00 + ($run * 17) + $offset);
    }
    $distinctSetRuns[] = implode('', $characters);
}
$distinctSetLastCharacters = [];
for ($offset = 0; $offset < 17; $offset++) {
    $distinctSetLastCharacters[] = wp_fts_jieba_multi_run_utf8(0x4E00 + (299 * 17) + $offset);
}
$distinctSetSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
if (!$distinctSetSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
    throw new RuntimeException('The pinned Jieba segmenter should load for the distinct-prefix-set proof.');
}
$distinctPrefixSets = wp_fts_jieba_measure_multi_run(
    $distinctSetSegmenter,
    implode('，', $distinctSetRuns),
    implode('', array_slice($distinctSetLastCharacters, 0, 4))
);
wp_fts_jieba_multi_run_check(
    $distinctPrefixSets['complete_dictionary_scans'] === 0,
    '300 disjoint 17-prefix sets should perform no complete dictionary scan'
);
wp_fts_jieba_multi_run_check(
    $distinctPrefixSets['indexed_range_reads'] <= 4000,
    '300 disjoint 17-prefix sets should read at most the populated ranges in their 5,100-prefix union'
);
wp_fts_jieba_multi_run_check(
    $distinctPrefixSets['elapsed_seconds'] < 3.0,
    '300 disjoint 17-prefix sets should finish within three seconds'
);
wp_fts_jieba_multi_run_check(
    wp_fts_jieba_parent_php_delta_within($distinctPrefixSets, 41943040),
    '300 disjoint 17-prefix sets should stay within a 40 MiB PHP peak delta'
);
wp_fts_jieba_multi_run_check(
    wp_fts_jieba_parent_rss_deltas_within($distinctPrefixSets, 41943040),
    '300 disjoint 17-prefix sets should stay within a 40 MiB attributable RSS delta'
);
unset($distinctSetSegmenter, $distinctSetRuns, $distinctSetLastCharacters);

$maximumRuns = [];
for ($run = 0; $run < WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES; $run++) {
    $maximumRuns[] = wp_fts_jieba_multi_run_utf8(0x4E00 + $run);
}
$maximumText = implode('，', $maximumRuns);
$maximumLastRun = $maximumRuns[array_key_last($maximumRuns)];
unset($maximumRuns);
$maximumSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
if (!$maximumSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
    throw new RuntimeException('The pinned Jieba segmenter should load for the maximum-run proof.');
}
$maximumDistinct = wp_fts_jieba_measure_multi_run($maximumSegmenter, $maximumText, $maximumLastRun);
wp_fts_jieba_multi_run_check(
    $maximumDistinct['term_count'] === WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES,
    '20,000 distinct one-character CJK runs should exercise the exact occurrence boundary'
);
wp_fts_jieba_multi_run_check(
    $maximumDistinct['complete_dictionary_scans'] === 0,
    'the 20,000-run occurrence boundary should perform no complete dictionary scan'
);
wp_fts_jieba_multi_run_check(
    $maximumDistinct['indexed_range_reads'] === 0,
    'one-character runs should bypass dictionary ranges whose eligible words are necessarily longer'
);
wp_fts_jieba_multi_run_check(
    $maximumDistinct['elapsed_seconds'] < 15.0,
    'the 20,000-run occurrence boundary should finish within fifteen seconds'
);
wp_fts_jieba_multi_run_check(
    wp_fts_jieba_parent_php_delta_within($maximumDistinct, 25165824),
    'the 20,000-run occurrence boundary should stay within a 24 MiB PHP peak delta'
);
wp_fts_jieba_multi_run_check(
    wp_fts_jieba_parent_rss_deltas_within($maximumDistinct, 25165824),
    'the 20,000-run occurrence boundary should stay within a 24 MiB RSS delta'
);
wp_fts_jieba_multi_run_check(
    wp_fts_jieba_parent_rss_deltas_within($maximumDistinct, 25165824),
    'the 20,000-run occurrence boundary should stay within a 24 MiB RSS peak delta'
);
unset($maximumSegmenter);

$highFanout = wp_fts_jieba_multi_run_high_fanout_prefixes($sourcePath, 1365);
wp_fts_jieba_multi_run_check(
    $highFanout['prefix_count'] === 1365 && strlen($highFanout['run']) === 4095,
    'the high-fanout proof should fill the accepted 4-KiB lexical envelope with 1,365 distinct Han prefixes'
);
wp_fts_jieba_multi_run_check(
    $highFanout['candidate_count'] === 285075,
    'the accepted high-fanout run should cover the pinned source’s exact 285,075 eligible candidate rows'
);
wp_fts_jieba_multi_run_check(
    $highFanout['candidate_bytes'] === 2581996,
    'the accepted high-fanout run should cover the pinned source’s exact 2,581,996 candidate-word bytes'
);
$highFanoutSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
if (!$highFanoutSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
    throw new RuntimeException('The pinned Jieba segmenter should load for the maximum-fanout proof.');
}
$maximumFanout = wp_fts_jieba_measure_multi_run(
    $highFanoutSegmenter,
    $highFanout['run'],
    substr($highFanout['run'], 0, 12)
);
wp_fts_jieba_multi_run_check(
    $maximumFanout['complete_dictionary_scans'] === 0,
    'the maximum accepted pinned fanout should perform no complete dictionary scan'
);
wp_fts_jieba_multi_run_check(
    $maximumFanout['indexed_range_reads'] <= 1600,
    'the maximum accepted pinned fanout should read at most the indexed ranges for its 1,365-prefix union'
);
wp_fts_jieba_multi_run_check(
    $maximumFanout['elapsed_seconds'] < 5.0,
    'the maximum accepted pinned fanout should finish within five seconds'
);
wp_fts_jieba_multi_run_check(
    wp_fts_jieba_parent_php_delta_within($maximumFanout, 67108864),
    'the maximum accepted pinned fanout should stay within a 64 MiB PHP peak delta'
);
wp_fts_jieba_multi_run_check(
    wp_fts_jieba_parent_rss_deltas_within($maximumFanout, 67108864),
    'the maximum accepted pinned fanout should stay within a 64 MiB attributable RSS delta'
);
wp_fts_jieba_multi_run_check(
    (int) (new ReflectionProperty($highFanoutSegmenter, 'cachedCandidateCount'))->getValue($highFanoutSegmenter)
        === $highFanout['candidate_count'],
    'the compact cache should retain every eligible prefix row from the maximum accepted pinned fanout'
);
unset($highFanoutSegmenter);

$completePinned = wp_fts_jieba_multi_run_high_fanout_prefixes($sourcePath, PHP_INT_MAX);
wp_fts_jieba_multi_run_check(
    $completePinned['prefix_count'] === 5628,
    'the complete pinned source should expose exactly 5,628 LanguagePipeline-reachable Han prefixes'
);
wp_fts_jieba_multi_run_check(
    $completePinned['candidate_count'] === 337399,
    'the complete pinned source should expose exactly 337,399 Han-prefix candidate rows'
);
wp_fts_jieba_multi_run_check(
    $completePinned['candidate_bytes'] === 3013489,
    'the complete pinned source should expose exactly 3,013,489 Han-prefix candidate-word bytes'
);
wp_fts_jieba_multi_run_check(
    $completePinned['source_prefix_count'] === 5652
        && $completePinned['source_candidate_count'] === 337461
        && $completePinned['source_candidate_bytes'] === 3013799,
    'the public segmenter eligibility definition should also account for 62 non-Han-leading rows across 24 prefixes'
);
$completePinnedRuns = [];
foreach (array_chunk($completePinned['prefixes'], 1365) as $prefixChunk) {
    $run = implode('', $prefixChunk);
    wp_fts_jieba_multi_run_check(
        strlen($run) <= WP_FTS_Analysis_Limits::MAX_LEXICAL_RUN_BYTES,
        'every complete-cache warmup run should stay inside the accepted 4-KiB lexical envelope'
    );
    $completePinnedRuns[] = $run;
}
$completePinnedSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
if (!$completePinnedSegmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
    throw new RuntimeException('The pinned Jieba segmenter should load for the complete-cache proof.');
}
$completePinnedCache = wp_fts_jieba_measure_run_batch($completePinnedSegmenter, $completePinnedRuns);
wp_fts_jieba_multi_run_check(
    $completePinnedCache['complete_dictionary_scans'] === 0,
    'warming every populated pinned prefix should perform no complete dictionary scan'
);
wp_fts_jieba_multi_run_check(
    $completePinnedCache['indexed_range_reads'] === 5632,
    'warming every populated Han prefix should read each of its 5,632 applicable attested ranges exactly once'
);
wp_fts_jieba_multi_run_check(
    $completePinnedCache['elapsed_seconds'] < 5.0,
    'warming the complete pinned prefix cache should finish within five seconds'
);
wp_fts_jieba_multi_run_check(
    wp_fts_jieba_parent_php_delta_within($completePinnedCache, 67108864),
    'the complete pinned prefix cache should stay within a 64 MiB PHP peak delta'
);
wp_fts_jieba_multi_run_check(
    wp_fts_jieba_parent_rss_deltas_within($completePinnedCache, 67108864),
    'the complete pinned prefix cache should stay within a 64 MiB attributable RSS delta'
);
wp_fts_jieba_multi_run_check(
    (int) (new ReflectionProperty($completePinnedSegmenter, 'cachedCandidateCount'))->getValue($completePinnedSegmenter)
        === $completePinned['candidate_count'],
    'the complete pinned cache should retain every eligible candidate row without eviction'
);
wp_fts_jieba_multi_run_check(
    (int) (new ReflectionProperty($completePinnedSegmenter, 'cachedCandidateBytes'))->getValue($completePinnedSegmenter)
        === $completePinned['candidate_bytes'],
    'the complete pinned cache should retain every eligible candidate-word byte without eviction'
);
unset($completePinnedSegmenter);

foreach (['cold' => $coldConstructionElapsed, 'saturated' => $saturatedConstructionElapsed] as $name => $elapsed) {
    wp_fts_jieba_multi_run_check(
        $elapsed < 1.0,
        "the {$name} pinned segmenter construction should attest its compact index within one second"
    );
}

foreach (['cold' => $cold, 'saturated' => $saturated] as $name => $measurement) {
    wp_fts_jieba_multi_run_check(
        $measurement['complete_dictionary_scans'] === 0,
        "the {$name} 256-run analyzer call should use indexed ranges and no complete dictionary scan"
    );
    wp_fts_jieba_multi_run_check(
        $measurement['elapsed_seconds'] < 5.0,
        "the {$name} 256-run analyzer call should finish within five seconds"
    );
    wp_fts_jieba_multi_run_check(
        wp_fts_jieba_parent_php_delta_within($measurement, 25165824),
        "the {$name} 256-run analyzer call should stay within a 24 MiB PHP peak delta"
    );
    wp_fts_jieba_multi_run_check(
        wp_fts_jieba_parent_rss_deltas_within($measurement, 25165824),
        "the {$name} 256-run analyzer call should stay within a 24 MiB RSS delta"
    );
    wp_fts_jieba_multi_run_check(
        wp_fts_jieba_parent_rss_deltas_within($measurement, 25165824),
        "the {$name} 256-run analyzer call should stay within a 24 MiB RSS peak delta"
    );
    wp_fts_jieba_multi_run_check(
        $measurement['term_count'] >= 768 && $measurement['term_count'] <= 1024,
        "the {$name} analyzer output should remain proportional to its 256 two-character runs"
    );
}

$GLOBALS['wp_fts_jieba_multi_run_metrics'] = [
    'dictionary_bytes' => $sourceBytes,
    'lookup_bytes' => $lookupEvidence['byte_size'],
    'lookup_ranges' => $lookupEvidence['range_count'],
    'distinct_runs' => count($runs),
    'cached_candidates_after_prime' => $cachedAfterPrime,
    'cold_construction_seconds' => $coldConstructionElapsed,
    'saturated_construction_seconds' => $saturatedConstructionElapsed,
    'source_hash_scans_per_pinned_construction' => 0,
    'cold' => $cold,
    'saturated' => $saturated,
    'repeated_wide_cold' => $repeatedWideCold,
    'repeated_wide_saturated' => $repeatedWideSaturated,
    'permuted_wide' => $permutedWide,
    'changing_prefix' => $changingPrefix,
    'distinct_prefix_sets' => $distinctPrefixSets,
    'maximum_distinct' => $maximumDistinct,
    'maximum_fanout_evidence' => [
        'run_bytes' => strlen($highFanout['run']),
        'prefix_count' => $highFanout['prefix_count'],
        'candidate_count' => $highFanout['candidate_count'],
        'candidate_bytes' => $highFanout['candidate_bytes'],
    ],
    'maximum_fanout' => $maximumFanout,
    'complete_pinned_cache_evidence' => [
        'prefix_count' => $completePinned['prefix_count'],
        'candidate_count' => $completePinned['candidate_count'],
        'candidate_bytes' => $completePinned['candidate_bytes'],
        'source_prefix_count' => $completePinned['source_prefix_count'],
        'source_candidate_count' => $completePinned['source_candidate_count'],
        'source_candidate_bytes' => $completePinned['source_candidate_bytes'],
    ],
    'complete_pinned_cache' => $completePinnedCache,
];
$parentRssPeak = wp_fts_jieba_multi_run_rss_sample('VmHWM');
$GLOBALS['wp_fts_jieba_multi_run_metrics']['parent_process'] = [
    // This process deliberately composes all adversaries. Its lifetime peak is
    // retained as a cumulative diagnostic; the ten fresh children below are
    // the authoritative per-request memory proof.
    'role' => 'cumulative_multi_workload_diagnostic',
    'php_peak_bytes' => memory_get_peak_usage(true),
    'rss_peak_bytes' => $parentRssPeak['bytes'],
    'rss_source' => $parentRssPeak['source'],
    'within_128_mib' => memory_get_peak_usage(true) <= 134217728
        && $parentRssPeak['bytes'] <= 134217728,
];

if (!function_exists('proc_open')) {
    throw new RuntimeException('The isolated Jieba memory proof requires proc_open().');
}
$freshCommandPrefix = [PHP_BINARY];
if (php_ini_loaded_file() === false) {
    $freshCommandPrefix[] = '-n';
}
$freshCommandPrefix[] = '-d';
$freshCommandPrefix[] = 'memory_limit=128M';
$freshCommandPrefix[] = __FILE__;
$freshProofs = [];
$freshCases = [
    'cold_256' => ['measurement' => $cold, 'workload' => wp_fts_jieba_multi_run_workload_identity($cold), 'delta_ceiling' => 25165824],
    'saturated_256' => ['measurement' => $saturated, 'workload' => wp_fts_jieba_multi_run_workload_identity($saturated), 'delta_ceiling' => 25165824],
    'repeated_wide_cold' => ['measurement' => $repeatedWideCold, 'workload' => wp_fts_jieba_multi_run_workload_identity($repeatedWideCold), 'delta_ceiling' => 25165824],
    'repeated_wide_saturated' => ['measurement' => $repeatedWideSaturated, 'workload' => wp_fts_jieba_multi_run_workload_identity($repeatedWideSaturated), 'delta_ceiling' => 25165824],
    'permuted_wide' => ['measurement' => $permutedWide, 'workload' => wp_fts_jieba_multi_run_workload_identity($permutedWide), 'delta_ceiling' => 25165824],
    'changing_prefix' => ['measurement' => $changingPrefix, 'workload' => wp_fts_jieba_multi_run_workload_identity($changingPrefix), 'delta_ceiling' => 25165824],
    'distinct_prefix_sets' => ['measurement' => $distinctPrefixSets, 'workload' => wp_fts_jieba_multi_run_workload_identity($distinctPrefixSets), 'delta_ceiling' => 41943040],
    'maximum_distinct' => ['measurement' => $maximumDistinct, 'workload' => wp_fts_jieba_multi_run_workload_identity($maximumDistinct), 'delta_ceiling' => 25165824],
    'maximum_fanout' => [
        'measurement' => $maximumFanout,
        'workload' => wp_fts_jieba_multi_run_workload_identity(
            $maximumFanout,
            $GLOBALS['wp_fts_jieba_multi_run_metrics']['maximum_fanout_evidence']
        ),
        'delta_ceiling' => 67108864,
    ],
    'complete_pinned_cache' => [
        'measurement' => $completePinnedCache,
        'workload' => wp_fts_jieba_multi_run_workload_identity(
            $completePinnedCache,
            $GLOBALS['wp_fts_jieba_multi_run_metrics']['complete_pinned_cache_evidence']
        ),
        'delta_ceiling' => 67108864,
    ],
];
foreach ($freshCases as $freshCase => $freshExpected) {
    $freshCommand = $freshCommandPrefix;
    $freshCommand[] = '--fresh-process=' . $freshCase;
    $freshPipes = [];
    $freshProcess = proc_open($freshCommand, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $freshPipes);
    if (!is_resource($freshProcess)) {
        throw new RuntimeException("Could not start the isolated {$freshCase} Jieba memory proof.");
    }
    fclose($freshPipes[0]);
    $freshOutput = stream_get_contents($freshPipes[1]);
    fclose($freshPipes[1]);
    $freshError = stream_get_contents($freshPipes[2]);
    fclose($freshPipes[2]);
    $freshStatus = proc_close($freshProcess);
    if ($freshStatus !== 0) {
        throw new RuntimeException(
            "The isolated {$freshCase} Jieba memory proof failed: " . trim((string) $freshError)
        );
    }
    $freshPayload = json_decode((string) $freshOutput, true, 16, JSON_THROW_ON_ERROR);
    $freshMeasurement = is_array($freshPayload['measurement'] ?? null) ? $freshPayload['measurement'] : [];
    $freshProcessEvidence = is_array($freshPayload['process'] ?? null) ? $freshPayload['process'] : [];
    wp_fts_jieba_multi_run_check(
        ($freshPayload['schema'] ?? null) === 'jieba-isolated-memory-case-v2'
            && ($freshPayload['status'] ?? null) === 'pass'
            && ($freshPayload['case'] ?? null) === $freshCase
            && ($freshPayload['memory_authority'] ?? null) === 'fresh_process_conservative_peak_attribution'
            && ($freshPayload['memory_limit'] ?? null) === '128M'
            && $freshMeasurement !== [],
        "the isolated {$freshCase} Jieba proof should complete under a 128 MiB PHP limit"
    );
    $measurementMatches = true;
    foreach (['term_count', 'complete_dictionary_scans', 'indexed_range_reads'] as $field) {
        $measurementMatches = $measurementMatches
            && ($freshMeasurement[$field] ?? null) === ($freshExpected['measurement'][$field] ?? null);
    }
    if (array_key_exists('run_count', $freshExpected['measurement'])) {
        $measurementMatches = $measurementMatches
            && ($freshMeasurement['run_count'] ?? null) === $freshExpected['measurement']['run_count'];
    }
    wp_fts_jieba_multi_run_check(
        $measurementMatches,
        "the isolated {$freshCase} Jieba workload should match its parent term and range inventory"
    );
    wp_fts_jieba_multi_run_check(
        ($freshPayload['workload'] ?? null) === $freshExpected['workload'],
        "the isolated {$freshCase} Jieba workload should match its parent candidate inventory"
    );
    $freshDeltaCeiling = (int) $freshExpected['delta_ceiling'];
    wp_fts_jieba_multi_run_check(
        ($freshMeasurement['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= $freshDeltaCeiling,
        "the isolated {$freshCase} Jieba PHP peak delta should retain its per-case ceiling"
    );
    wp_fts_jieba_multi_run_check(
        ($freshMeasurement['rss_delta_bytes'] ?? PHP_INT_MAX) <= $freshDeltaCeiling
            && ($freshMeasurement['rss_peak_delta_bytes'] ?? PHP_INT_MAX) <= $freshDeltaCeiling,
        "the isolated {$freshCase} Jieba RSS deltas should retain their per-case ceiling"
    );
    wp_fts_jieba_multi_run_check(
        ($freshProcessEvidence['php_peak_bytes'] ?? PHP_INT_MAX) <= 134217728,
        "the isolated {$freshCase} Jieba proof should stay within a 128 MiB PHP peak"
    );
    wp_fts_jieba_multi_run_check(
        ($freshProcessEvidence['rss_peak_bytes'] ?? PHP_INT_MAX) <= 134217728,
        "the isolated {$freshCase} Jieba proof should stay within a 128 MiB RSS peak"
    );
    if (PHP_OS_FAMILY === 'Linux') {
        wp_fts_jieba_multi_run_check(
            ($freshMeasurement['rss_source'] ?? null) === 'linux_proc_status'
                && ($freshProcessEvidence['rss_source'] ?? null) === 'linux_proc_status',
            "the isolated {$freshCase} Linux RSS evidence should retain /proc/self/status provenance"
        );
    }
    $freshProofs[$freshCase] = [
        'schema' => $freshPayload['schema'],
        'memory_authority' => $freshPayload['memory_authority'],
        'memory_limit' => $freshPayload['memory_limit'],
        'measurement' => $freshMeasurement,
        'process' => $freshProcessEvidence,
        'workload' => $freshPayload['workload'],
    ];
}
$GLOBALS['wp_fts_jieba_multi_run_metrics']['fresh_processes'] = $freshProofs;

return $wp_fts_jieba_multi_run_checks;
