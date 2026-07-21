<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

final class WP_FTS_Jieba_Query_Bound_Storage implements WP_FTS_Set_Oriented_Search_Storage
{
    public int $searchCalls = 0;
    /** @var array<int,array<int,array<string,mixed>>> */
    public array $lastGroups = [];

    public function search_page(array $groups, array $options): array
    {
        $this->searchCalls++;
        $this->lastGroups = $groups;

        return [
            'results' => [['doc_id' => 77, 'score' => 1.0]],
            'has_more' => false,
            'query_lang' => (string) ($options['query_lang'] ?? 'zh'),
        ];
    }

}

$wp_fts_jieba_query_bound_checks = 0;

/** Records one assertion and throws when a public query bound is violated. */
function wp_fts_jieba_query_bound_check(bool $condition, string $message): void
{
    global $wp_fts_jieba_query_bound_checks;
    $wp_fts_jieba_query_bound_checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Encode the three-byte Han range used by the maximum public query. */
function wp_fts_jieba_query_bound_character(int $codepoint): string
{
    return chr(0xE0 | ($codepoint >> 12))
        . chr(0x80 | (($codepoint >> 6) & 0x3F))
        . chr(0x80 | ($codepoint & 0x3F));
}

/** Read one private integer diagnostic without adding a production API. */
function wp_fts_jieba_query_bound_counter(
    WP_FTS_ChineseJiebaSegmenter $segmenter,
    string $property
): int {
    return (int) (new ReflectionProperty($segmenter, $property))->getValue($segmenter);
}

/** Return the bundled tokenizer selected by the public analyzer pack option. */
function wp_fts_jieba_query_bound_segmenter(WP_FTS_Analyzer $analyzer): WP_FTS_ChineseJiebaSegmenter
{
    $pipeline = (new ReflectionProperty($analyzer, 'languagePipeline'))->getValue($analyzer);
    $tokenizer = (new ReflectionProperty($pipeline, 'cjkTokenizer'))->getValue($pipeline);
    if (!$tokenizer instanceof WP_FTS_ChineseJiebaSegmenter) {
        throw new RuntimeException('The public zh segmenter-pack option should select the bundled Jieba producer.');
    }

    return $tokenizer;
}

/**
 * Exercise both sides of the public twelve-occurrence boundary in one fresh
 * process and return source-bound work, result, time, and memory evidence.
 *
 * @param string[] $queryCharacters
 * @return array<string,mixed>
 */
function wp_fts_jieba_query_bound_measure(string $maximumQuery, array $queryCharacters): array
{
    $analyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'default_lang' => 'zh',
        'query_lang' => 'zh',
        'enable_stemming' => false,
        'segmenter_packs_by_lang' => ['zh' => true],
    ]);
    $segmenter = wp_fts_jieba_query_bound_segmenter($analyzer);
    $pipeline = (new ReflectionProperty($analyzer, 'languagePipeline'))->getValue($analyzer);
    $producerLimitEnabled = (bool) (new ReflectionProperty(
        $pipeline,
        'cjkTokenizerAcceptsProducerLimit'
    ))->getValue($pipeline);
    $storage = new WP_FTS_Jieba_Query_Bound_Storage();
    $searcher = WP_FTS_Searcher::for_set_oriented_storage($storage, $analyzer);
    $scansBefore = wp_fts_jieba_query_bound_counter($segmenter, 'dictionaryScanCount');
    $readsBefore = wp_fts_jieba_query_bound_counter($segmenter, 'indexedRangeReadCount');
    $candidatesBefore = wp_fts_jieba_query_bound_counter($segmenter, 'cachedCandidateCount');

    $canResetPeak = function_exists('memory_reset_peak_usage');
    if ($canResetPeak) {
        memory_reset_peak_usage();
    }
    $usageBefore = memory_get_usage(true);
    $exactUsageBefore = memory_get_usage(false);
    $started = hrtime(true);
    $rejection = null;
    try {
        $searcher->search($maximumQuery, [
            'query_lang' => 'zh',
            'mode' => 'OR',
            'prefix_matching' => false,
        ]);
    } catch (Throwable $error) {
        $rejection = $error;
    }
    $elapsedSeconds = (hrtime(true) - $started) / 1_000_000_000;
    // In PHP 8.1 the process peak cannot be reset. Subtracting the current
    // pre-search usage from the lifetime peak is deliberately conservative:
    // it includes any earlier construction high-water gap instead of hiding a
    // query allocation below that mark.
    $allocatorPeakDelta = max(0, memory_get_peak_usage(true) - $usageBefore);
    $exactPeakDelta = max(0, memory_get_peak_usage(false) - $exactUsageBefore);
    $scansAfterSearch = wp_fts_jieba_query_bound_counter($segmenter, 'dictionaryScanCount');
    $readsAfterSearch = wp_fts_jieba_query_bound_counter($segmenter, 'indexedRangeReadCount');
    $candidatesAfterSearch = wp_fts_jieba_query_bound_counter($segmenter, 'cachedCandidateCount');
    $rejectedStorageSearchCalls = $storage->searchCalls;

    $boundedTokens = $segmenter(
        $maximumQuery,
        'zh',
        WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_ALTERNATIVES + 1
    );
    $accepted = $searcher->search(implode('，', array_slice($queryCharacters, 0, 12)), [
        'query_lang' => 'zh',
        'mode' => 'OR',
        'prefix_matching' => false,
    ]);

    $peakDelta = max($allocatorPeakDelta, $exactPeakDelta);
    $searchScanDelta = $scansAfterSearch - $scansBefore;
    $searchReadDelta = $readsAfterSearch - $readsBefore;
    $searchCandidateDelta = $candidatesAfterSearch - $candidatesBefore;
    $producerReadDelta = wp_fts_jieba_query_bound_counter($segmenter, 'indexedRangeReadCount')
        - $readsAfterSearch;
    $dictionaryEvidence = WP_FTS_ChineseJiebaSegmenter::default_source_evidence();
    $lookupEvidence = WP_FTS_ChineseJiebaSegmenter::default_lookup_evidence();
    $passed = $rejection instanceof WP_FTS_Search_Budget_Exceeded
        && $rejection->budget() === 'analyzer occurrences'
        && $rejectedStorageSearchCalls === 0
        && $searchScanDelta === 0
        && $searchReadDelta === 0
        && $searchCandidateDelta === 0
        && $elapsedSeconds < 1.0
        && $peakDelta <= 4 * 1024 * 1024
        && count($boundedTokens) === 13
        && $boundedTokens === array_slice($queryCharacters, 0, 13)
        && $producerReadDelta === 0
        && ($accepted['results'][0]['doc_id'] ?? null) === 77
        && $storage->searchCalls === 1
        && count($storage->lastGroups) === 12
        && array_sum(array_map('count', $storage->lastGroups)) === 12
        && $producerLimitEnabled
        && $dictionaryEvidence['available'] === true
        && $lookupEvidence['available'] === true;

    return [
        'schema' => 'wp-fts-jieba-query-producer-bound-v1',
        'status' => $passed ? 'pass' : 'fail',
        'fresh_process' => true,
        'php_version' => PHP_VERSION,
        'php_ini_loaded' => php_ini_loaded_file() !== false,
        'memory_limit' => (string) ini_get('memory_limit'),
        'query_bytes' => strlen($maximumQuery),
        'query_sha256' => hash('sha256', $maximumQuery),
        'distinct_han_prefixes' => count($queryCharacters),
        'elapsed_seconds' => $elapsedSeconds,
        'php_peak_delta_bytes' => $peakDelta,
        'php_allocator_peak_delta_bytes' => $allocatorPeakDelta,
        'php_exact_peak_delta_bytes' => $exactPeakDelta,
        'php_peak_delta_authoritative' => true,
        'peak_reset_available' => $canResetPeak,
        'memory_authority' => [
            'authoritative' => true,
            'process' => 'fresh',
            'peak_attribution' => $canResetPeak
                ? 'reset_peak_minus_pre_search_usage'
                : 'lifetime_peak_minus_pre_search_usage',
        ],
        'limits' => [
            'max_query_alternatives' => 12,
            'producer_stop_items' => 13,
            'elapsed_milliseconds_lt' => 1000,
            'php_peak_delta_bytes_lte' => 4 * 1024 * 1024,
        ],
        'bundled_source' => [
            'repository' => WP_FTS_ChineseJiebaSegmenter::SOURCE_REPOSITORY,
            'commit' => WP_FTS_ChineseJiebaSegmenter::SOURCE_COMMIT,
            'file' => WP_FTS_ChineseJiebaSegmenter::SOURCE_FILE,
            'dictionary_sha256' => WP_FTS_ChineseJiebaSegmenter::SOURCE_SHA256,
            'dictionary_bytes' => WP_FTS_ChineseJiebaSegmenter::SOURCE_BYTE_SIZE,
            'lookup_sha256' => WP_FTS_ChineseJiebaSegmenter::LOOKUP_SHA256,
            'lookup_bytes' => WP_FTS_ChineseJiebaSegmenter::LOOKUP_BYTE_SIZE,
            'lookup_ranges' => WP_FTS_ChineseJiebaSegmenter::LOOKUP_RANGE_COUNT,
            'available' => $dictionaryEvidence['available'] === true
                && $lookupEvidence['available'] === true,
        ],
        'complete_dictionary_scans' => $searchScanDelta,
        'indexed_range_reads' => $searchReadDelta,
        'cached_candidate_delta' => $searchCandidateDelta,
        'rejected_storage_search_calls' => $rejectedStorageSearchCalls,
        'rejection' => $rejection instanceof WP_FTS_Search_Budget_Exceeded
            ? $rejection->budget()
            : get_debug_type($rejection),
        'producer_token_count' => count($boundedTokens),
        'producer_tokens_exact' => $boundedTokens === array_slice($queryCharacters, 0, 13),
        'producer_indexed_range_reads' => $producerReadDelta,
        'accepted_result_doc_id' => $accepted['results'][0]['doc_id'] ?? null,
        'accepted_storage_search_calls' => $storage->searchCalls,
        'accepted_group_count' => count($storage->lastGroups),
        'accepted_alternative_count' => array_sum(array_map('count', $storage->lastGroups)),
        'producer_limit_enabled' => $producerLimitEnabled,
    ];
}

$queryCharacters = [];
for ($index = 0; $index < 1365; $index++) {
    $queryCharacters[] = wp_fts_jieba_query_bound_character(0x4E00 + $index);
}
$maximumQuery = implode('', $queryCharacters);
if (in_array('--measure-public-search', $_SERVER['argv'] ?? [], true)) {
    echo json_encode(
        wp_fts_jieba_query_bound_measure($maximumQuery, $queryCharacters),
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ), "\n";
    return 0;
}

wp_fts_jieba_query_bound_check(
    strlen($maximumQuery) === 4095 && count(array_unique($queryCharacters)) === 1365,
    'the public worst case must contain 1,365 distinct Han prefixes in exactly 4,095 bytes'
);

if (!function_exists('proc_open')) {
    throw new RuntimeException('The public Jieba query bound requires a fresh PHP process.');
}
$command = [PHP_BINARY];
if (php_ini_loaded_file() === false) {
    $command[] = '-n';
}
array_push(
    $command,
    '-d',
    'memory_limit=128M',
    '-d',
    'max_execution_time=5',
    __FILE__,
    '--measure-public-search'
);
$pipes = [];
$process = proc_open($command, [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes, __DIR__);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the fresh public Jieba query proof.');
}
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
wp_fts_jieba_query_bound_check(
    $exitCode === 0 && trim((string) $stderr) === '',
    "the fresh public Jieba query proof must finish cleanly: {$stderr}"
);
$measurement = json_decode(trim((string) $stdout), true, 64, JSON_THROW_ON_ERROR);
wp_fts_jieba_query_bound_check(
    is_array($measurement)
        && ($measurement['schema'] ?? null) === 'wp-fts-jieba-query-producer-bound-v1'
        && ($measurement['status'] ?? null) === 'pass'
        && ($measurement['fresh_process'] ?? false) === true
        && ($measurement['php_peak_delta_authoritative'] ?? false) === true
        && ($measurement['memory_limit'] ?? null) === '128M'
        && ($measurement['memory_authority']['authoritative'] ?? false) === true
        && ($measurement['memory_authority']['process'] ?? null) === 'fresh'
        && in_array(
            $measurement['memory_authority']['peak_attribution'] ?? null,
            ['reset_peak_minus_pre_search_usage', 'lifetime_peak_minus_pre_search_usage'],
            true
        ),
    'the public Jieba query evidence must be a passing fixed-schema artifact from an authoritative fresh 128M process'
);
wp_fts_jieba_query_bound_check(
    ($measurement['query_bytes'] ?? null) === 4095
        && ($measurement['distinct_han_prefixes'] ?? null) === 1365
        && ($measurement['query_sha256'] ?? null) === hash('sha256', $maximumQuery),
    'the fresh process must measure the exact source-bound 4,095-byte Han query'
);
wp_fts_jieba_query_bound_check(
    ($measurement['limits']['max_query_alternatives'] ?? null) === 12
        && ($measurement['limits']['producer_stop_items'] ?? null) === 13
        && ($measurement['limits']['elapsed_milliseconds_lt'] ?? null) === 1000
        && ($measurement['limits']['php_peak_delta_bytes_lte'] ?? null) === 4 * 1024 * 1024
        && ($measurement['bundled_source']['commit'] ?? null) === WP_FTS_ChineseJiebaSegmenter::SOURCE_COMMIT
        && ($measurement['bundled_source']['dictionary_sha256'] ?? null) === WP_FTS_ChineseJiebaSegmenter::SOURCE_SHA256
        && ($measurement['bundled_source']['dictionary_bytes'] ?? null) === WP_FTS_ChineseJiebaSegmenter::SOURCE_BYTE_SIZE
        && ($measurement['bundled_source']['lookup_sha256'] ?? null) === WP_FTS_ChineseJiebaSegmenter::LOOKUP_SHA256
        && ($measurement['bundled_source']['lookup_bytes'] ?? null) === WP_FTS_ChineseJiebaSegmenter::LOOKUP_BYTE_SIZE
        && ($measurement['bundled_source']['lookup_ranges'] ?? null) === WP_FTS_ChineseJiebaSegmenter::LOOKUP_RANGE_COUNT
        && ($measurement['bundled_source']['available'] ?? false) === true,
    'the artifact must bind its thresholds and exact bundled dictionary/index identities'
);

wp_fts_jieba_query_bound_check(
    ($measurement['rejection'] ?? null) === 'analyzer occurrences',
    'the public Searcher must reject the first occurrence above twelve with its typed budget error'
);
wp_fts_jieba_query_bound_check(
    ($measurement['rejected_storage_search_calls'] ?? null) === 0,
    'the rejected maximum Han query must not reach set-oriented storage'
);
wp_fts_jieba_query_bound_check(
    $measurement['complete_dictionary_scans'] === 0
        && $measurement['indexed_range_reads'] === 0
        && $measurement['cached_candidate_delta'] === 0,
    'the rejected maximum Han query must perform no dictionary scan, range read, or candidate retention'
);
wp_fts_jieba_query_bound_check(
    (float) ($measurement['elapsed_seconds'] ?? INF) < 1.0,
    'the maximum public Han query must reject within one second on the focused low-host lane'
);
wp_fts_jieba_query_bound_check(
    (int) ($measurement['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= 4 * 1024 * 1024,
    'the maximum public Han query must stay within a 4 MiB attributable PHP peak delta'
);
wp_fts_jieba_query_bound_check(
    ($measurement['producer_token_count'] ?? null) === 13
        && ($measurement['producer_tokens_exact'] ?? false) === true,
    'the bundled producer must stop after the exact thirteen fallback items needed for public rejection'
);
wp_fts_jieba_query_bound_check(
    ($measurement['producer_indexed_range_reads'] ?? null) === 0,
    'the direct thirteen-item producer proof must also avoid every indexed dictionary range'
);
wp_fts_jieba_query_bound_check(
    ($measurement['accepted_result_doc_id'] ?? null) === 77
        && ($measurement['accepted_storage_search_calls'] ?? null) === 1,
    'twelve one-character Jieba occurrences must still reach storage and preserve its public result'
);
wp_fts_jieba_query_bound_check(
    ($measurement['accepted_group_count'] ?? null) === 12
        && ($measurement['accepted_alternative_count'] ?? null) === 12,
    'the accepted boundary must preserve exactly twelve logical groups and alternatives'
);

wp_fts_jieba_query_bound_check(
    ($measurement['producer_limit_enabled'] ?? false) === true,
    'the public bundled segmenter path must enable its finite producer ceiling'
);

$measurement['checks'] = $wp_fts_jieba_query_bound_checks;
$GLOBALS['wp_fts_jieba_query_bound_metrics'] = $measurement;
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    echo json_encode($measurement, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
}

return $wp_fts_jieba_query_bound_checks;
