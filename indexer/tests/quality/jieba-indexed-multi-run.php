<?php
declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

$checks = require dirname(__DIR__, 3) . '/components/full-text-search/tests/jieba-indexed-multi-run.php';
$metrics = $GLOBALS['wp_fts_jieba_multi_run_metrics'] ?? null;
if (!is_array($metrics)) {
    throw new RuntimeException('Jieba multi-run evidence was not produced.');
}
$expectedFreshCases = [
    'cold_256' => 25165824,
    'saturated_256' => 25165824,
    'repeated_wide_cold' => 25165824,
    'repeated_wide_saturated' => 25165824,
    'permuted_wide' => 25165824,
    'changing_prefix' => 25165824,
    'distinct_prefix_sets' => 41943040,
    'maximum_distinct' => 25165824,
    'maximum_fanout' => 67108864,
    'complete_pinned_cache' => 67108864,
];
$freshProcesses = is_array($metrics['fresh_processes'] ?? null) ? $metrics['fresh_processes'] : [];
if (array_keys($freshProcesses) !== array_keys($expectedFreshCases)) {
    throw new RuntimeException('Jieba memory evidence must contain the exact ten isolated cases.');
}
foreach ($expectedFreshCases as $caseId => $deltaCeiling) {
    $record = is_array($freshProcesses[$caseId] ?? null) ? $freshProcesses[$caseId] : [];
    $measurement = is_array($record['measurement'] ?? null) ? $record['measurement'] : [];
    $process = is_array($record['process'] ?? null) ? $record['process'] : [];
    $workload = is_array($record['workload'] ?? null) ? $record['workload'] : [];
    $inputHash = (string) ($workload['input_sha256'] ?? '');
    $valid = ($record['schema'] ?? null) === 'jieba-isolated-memory-case-v2'
        && ($record['memory_authority'] ?? null) === 'fresh_process_conservative_peak_attribution'
        && ($record['memory_limit'] ?? null) === '128M'
        && ($measurement['php_peak_delta_authoritative'] ?? null) === true
        && (PHP_OS_FAMILY !== 'Linux' || ($measurement['rss_peak_delta_authoritative'] ?? null) === true)
        && (int) ($measurement['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= $deltaCeiling
        && (int) ($measurement['rss_delta_bytes'] ?? PHP_INT_MAX) <= $deltaCeiling
        && (int) ($measurement['rss_peak_delta_bytes'] ?? PHP_INT_MAX) <= $deltaCeiling
        && (int) ($process['php_peak_bytes'] ?? PHP_INT_MAX) <= 134217728
        && (int) ($process['rss_peak_bytes'] ?? PHP_INT_MAX) <= 134217728
        && (int) ($workload['input_bytes'] ?? 0) > 0
        && (int) ($workload['input_unit_count'] ?? 0) > 0
        && strlen($inputHash) === 64
        && strspn($inputHash, '0123456789abcdef') === 64;
    if (PHP_OS_FAMILY === 'Linux') {
        $valid = $valid
            && ($measurement['rss_source'] ?? null) === 'linux_proc_status'
            && ($process['rss_source'] ?? null) === 'linux_proc_status';
    }
    if (!$valid) {
        throw new RuntimeException("The isolated {$caseId} Jieba memory evidence is incomplete or outside its hard ceiling.");
    }
}
$parentProcess = is_array($metrics['parent_process'] ?? null) ? $metrics['parent_process'] : [];
if (
    ($parentProcess['role'] ?? null) !== 'cumulative_multi_workload_diagnostic'
    || ($parentProcess['within_128_mib'] ?? null) !== true
) {
    throw new RuntimeException('The dedicated parent process exceeded its cumulative 128 MiB diagnostic ceiling.');
}
if (PHP_OS_FAMILY === 'Linux' && ($parentProcess['rss_source'] ?? null) !== 'linux_proc_status') {
    throw new RuntimeException('The dedicated parent Linux RSS diagnostic must come from /proc/self/status.');
}

echo json_encode([
    'schema' => 'jieba-indexed-multi-run-v2',
    'status' => 'pass',
    'php_version' => PHP_VERSION,
    'memory_limit' => ini_get('memory_limit'),
    'checks' => $checks,
    'ceilings' => [
        'elapsed_seconds_per_256_run_analysis' => 5.0,
        'elapsed_seconds_at_20000_run_occurrence_boundary' => 15.0,
        'elapsed_seconds_per_300_repeated_wide_runs' => 2.0,
        'indexed_range_reads_per_300_repeated_wide_runs' => 20,
        'elapsed_seconds_per_300_permuted_wide_runs' => 2.0,
        'indexed_range_reads_per_300_permuted_wide_runs' => 20,
        'elapsed_seconds_per_300_changing_prefix_runs' => 2.0,
        'indexed_range_reads_per_300_changing_prefix_runs' => 350,
        'elapsed_seconds_per_300_disjoint_prefix_sets' => 3.0,
        'indexed_range_reads_per_300_disjoint_prefix_sets' => 4000,
        'elapsed_seconds_at_1365_prefix_maximum_fanout' => 5.0,
        'indexed_range_reads_at_1365_prefix_maximum_fanout' => 1600,
        'maximum_fanout_run_bytes' => 4095,
        'maximum_fanout_prefixes' => 1365,
        'maximum_fanout_candidates' => 285075,
        'maximum_fanout_candidate_bytes' => 2581996,
        'complete_pinned_han_prefixes' => 5628,
        'complete_pinned_han_candidates' => 337399,
        'complete_pinned_han_candidate_bytes' => 3013489,
        'complete_pinned_source_prefixes' => 5652,
        'complete_pinned_source_candidates' => 337461,
        'complete_pinned_source_candidate_bytes' => 3013799,
        'complete_pinned_indexed_range_reads' => 5632,
        'elapsed_seconds_complete_pinned_cache' => 5.0,
        'elapsed_seconds_per_pinned_construction' => 1.0,
        'source_hash_scans_per_pinned_construction' => 0,
        'php_peak_delta_bytes_standard_cases' => 25165824,
        'php_peak_delta_bytes_disjoint_prefix_sets' => 41943040,
        'php_peak_delta_bytes_maximum_fanout' => 67108864,
        'rss_delta_bytes_standard_cases' => 25165824,
        'rss_delta_bytes_disjoint_prefix_sets' => 41943040,
        'rss_delta_bytes_maximum_fanout' => 67108864,
        'fresh_process_php_peak_bytes' => 134217728,
        'fresh_process_rss_peak_bytes' => 134217728,
        'fresh_process_count' => 10,
    ],
    'memory_evidence' => [
        'authoritative' => 'ten fresh isolated child processes',
        'parent_rss_peak_bytes' => 'cumulative diagnostic only',
        'no_reset_php_delta' => 'memory_get_peak_usage(true) after minus memory_get_usage(true) before',
        'rss_peak_delta' => 'VmHWM after minus VmRSS before',
    ],
    'measurements' => $metrics,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
