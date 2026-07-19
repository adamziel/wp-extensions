<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$wp_fts_jieba_multi_run_checks = 0;

function wp_fts_jieba_multi_run_check(bool $condition, string $message): void
{
    global $wp_fts_jieba_multi_run_checks;
    $wp_fts_jieba_multi_run_checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wp_fts_jieba_multi_run_scan_count(WP_FTS_ChineseJiebaSegmenter $segmenter): int
{
    return (int) (new ReflectionProperty($segmenter, 'dictionaryScanCount'))->getValue($segmenter);
}

function wp_fts_jieba_multi_run_rss_bytes(string $field): int
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
            if (isset($parts[0]) && ctype_digit($parts[0])) {
                return (int) $parts[0] * 1024;
            }
        }
    }

    return memory_get_usage(true);
}

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
 *   rss_peak_bytes:int,
 *   run_count:int,
 *   term_count:int
 * }
 */
function wp_fts_jieba_measure_run_batch(WP_FTS_ChineseJiebaSegmenter $segmenter, array $runs): array
{
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $memoryBefore = memory_get_usage(true);
    $rssBefore = wp_fts_jieba_multi_run_rss_bytes('VmRSS');
    $scansBefore = wp_fts_jieba_multi_run_scan_count($segmenter);
    $rangeReadsBefore = (int) (new ReflectionProperty($segmenter, 'indexedRangeReadCount'))->getValue($segmenter);
    $termCount = 0;
    $started = microtime(true);
    foreach ($runs as $run) {
        $termCount += count($segmenter($run, 'zh'));
    }
    $elapsed = microtime(true) - $started;
    $rssAfter = wp_fts_jieba_multi_run_rss_bytes('VmRSS');

    return [
        'elapsed_seconds' => $elapsed,
        'complete_dictionary_scans' => wp_fts_jieba_multi_run_scan_count($segmenter) - $scansBefore,
        'indexed_range_reads' => (int) (new ReflectionProperty($segmenter, 'indexedRangeReadCount'))->getValue($segmenter)
            - $rangeReadsBefore,
        'php_peak_delta_bytes' => max(0, memory_get_peak_usage(true) - $memoryBefore),
        'rss_delta_bytes' => max(0, $rssAfter - $rssBefore),
        'rss_peak_bytes' => wp_fts_jieba_multi_run_rss_bytes('VmHWM'),
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
 *   rss_peak_bytes:int,
 *   term_count:int
 * }
 */
function wp_fts_jieba_measure_multi_run(
    WP_FTS_ChineseJiebaSegmenter $segmenter,
    string $text,
    string $expectedToken
): array {
    $pipeline = new WP_FTS_LanguagePipeline([
        'cjk_tokenizer' => $segmenter,
        'enable_stemming' => false,
    ]);
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $memoryBefore = memory_get_usage(true);
    $rssBefore = wp_fts_jieba_multi_run_rss_bytes('VmRSS');
    $scansBefore = wp_fts_jieba_multi_run_scan_count($segmenter);
    $rangeReadsBefore = (int) (new ReflectionProperty($segmenter, 'indexedRangeReadCount'))->getValue($segmenter);
    $started = microtime(true);
    $terms = $pipeline->analyze($text, 'zh');
    $elapsed = microtime(true) - $started;
    $rssAfter = wp_fts_jieba_multi_run_rss_bytes('VmRSS');
    $rssPeak = wp_fts_jieba_multi_run_rss_bytes('VmHWM');

    wp_fts_jieba_multi_run_check(
        in_array($expectedToken, $terms, true),
        'the measured analysis should preserve its expected fallback token'
    );

    return [
        'elapsed_seconds' => $elapsed,
        'complete_dictionary_scans' => wp_fts_jieba_multi_run_scan_count($segmenter) - $scansBefore,
        'indexed_range_reads' => (int) (new ReflectionProperty($segmenter, 'indexedRangeReadCount'))->getValue($segmenter)
            - $rangeReadsBefore,
        'php_peak_delta_bytes' => max(0, memory_get_peak_usage(true) - $memoryBefore),
        'rss_delta_bytes' => max(0, $rssAfter - $rssBefore),
        'rss_peak_bytes' => $rssPeak,
        'term_count' => count($terms),
    ];
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
        $measurement['php_peak_delta_bytes'] <= 25165824,
        "the {$name} repeated-wide-run analysis should stay within a 24 MiB PHP peak delta"
    );
    wp_fts_jieba_multi_run_check(
        $measurement['rss_delta_bytes'] <= 25165824,
        "the {$name} repeated-wide-run analysis should stay within a 24 MiB RSS delta"
    );
    wp_fts_jieba_multi_run_check(
        $measurement['rss_peak_bytes'] <= 134217728,
        "the {$name} repeated-wide-run analysis should stay within a 128 MiB RSS peak"
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
    $permutedWide['php_peak_delta_bytes'] <= 25165824,
    '300 distinct hot-prefix permutations should stay within a 24 MiB PHP peak delta'
);
wp_fts_jieba_multi_run_check(
    $permutedWide['rss_peak_bytes'] <= 134217728,
    '300 distinct hot-prefix permutations should stay within a 128 MiB RSS peak'
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
    $changingPrefix['php_peak_delta_bytes'] <= 25165824,
    '300 runs with one changing prefix should stay within a 24 MiB PHP peak delta'
);
wp_fts_jieba_multi_run_check(
    $changingPrefix['rss_peak_bytes'] <= 134217728,
    '300 runs with one changing prefix should stay within a 128 MiB RSS peak'
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
    $distinctPrefixSets['php_peak_delta_bytes'] <= 41943040,
    '300 disjoint 17-prefix sets should stay within a 40 MiB PHP peak delta'
);
wp_fts_jieba_multi_run_check(
    $distinctPrefixSets['rss_peak_bytes'] <= 134217728,
    '300 disjoint 17-prefix sets should stay within a 128 MiB RSS peak'
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
    $maximumDistinct['php_peak_delta_bytes'] <= 25165824,
    'the 20,000-run occurrence boundary should stay within a 24 MiB PHP peak delta'
);
wp_fts_jieba_multi_run_check(
    $maximumDistinct['rss_delta_bytes'] <= 25165824,
    'the 20,000-run occurrence boundary should stay within a 24 MiB RSS delta'
);
wp_fts_jieba_multi_run_check(
    $maximumDistinct['rss_peak_bytes'] <= 134217728,
    'the 20,000-run occurrence boundary should stay within a 128 MiB RSS peak'
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
    $maximumFanout['php_peak_delta_bytes'] <= 67108864,
    'the maximum accepted pinned fanout should stay within a 64 MiB PHP peak delta'
);
wp_fts_jieba_multi_run_check(
    $maximumFanout['rss_peak_bytes'] <= 134217728,
    'the maximum accepted pinned fanout should stay within a 128 MiB RSS peak'
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
    $completePinnedCache['php_peak_delta_bytes'] <= 67108864,
    'the complete pinned prefix cache should stay within a 64 MiB PHP peak delta'
);
wp_fts_jieba_multi_run_check(
    $completePinnedCache['rss_peak_bytes'] <= 134217728,
    'the complete pinned prefix cache should stay within a 128 MiB RSS peak'
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
        $measurement['php_peak_delta_bytes'] <= 25165824,
        "the {$name} 256-run analyzer call should stay within a 24 MiB PHP peak delta"
    );
    wp_fts_jieba_multi_run_check(
        $measurement['rss_delta_bytes'] <= 25165824,
        "the {$name} 256-run analyzer call should stay within a 24 MiB RSS delta"
    );
    wp_fts_jieba_multi_run_check(
        $measurement['rss_peak_bytes'] <= 134217728,
        "the {$name} 256-run analyzer call should stay within a 128 MiB RSS peak"
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

return $wp_fts_jieba_multi_run_checks;
