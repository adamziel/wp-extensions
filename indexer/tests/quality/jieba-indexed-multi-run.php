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

echo json_encode([
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
        'complete_dictionary_scans_per_analysis' => 0,
        'source_hash_scans_per_pinned_construction' => 0,
        'php_peak_delta_bytes_standard_cases' => 25165824,
        'php_peak_delta_bytes_disjoint_prefix_sets' => 41943040,
        'php_peak_delta_bytes_maximum_fanout' => 67108864,
        'rss_delta_bytes' => 25165824,
        'rss_peak_bytes' => 134217728,
    ],
    'measurements' => $metrics,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
