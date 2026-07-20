<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$wp_fts_jieba_bound_checks = 0;

/** Count one containment assertion and fail with its specific invariant. */
function wp_fts_jieba_bound_check(bool $condition, string $message): void
{
    global $wp_fts_jieba_bound_checks;
    $wp_fts_jieba_bound_checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Encode one synthetic codepoint without depending on optional mbstring. */
function wp_fts_jieba_bound_utf8(int $codepoint): string
{
    if ($codepoint <= 0x7F) {
        return chr($codepoint);
    }
    if ($codepoint <= 0x7FF) {
        return chr(0xC0 | ($codepoint >> 6))
            . chr(0x80 | ($codepoint & 0x3F));
    }
    if ($codepoint <= 0xFFFF) {
        return chr(0xE0 | ($codepoint >> 12))
            . chr(0x80 | (($codepoint >> 6) & 0x3F))
            . chr(0x80 | ($codepoint & 0x3F));
    }

    return chr(0xF0 | ($codepoint >> 18))
        . chr(0x80 | (($codepoint >> 12) & 0x3F))
        . chr(0x80 | (($codepoint >> 6) & 0x3F))
        . chr(0x80 | ($codepoint & 0x3F));
}

/** Read the private full-scan counter used by the fixture's complexity gates. */
function wp_fts_jieba_bound_scan_count(WP_FTS_ChineseJiebaSegmenter $segmenter): int
{
    $property = new ReflectionProperty($segmenter, 'dictionaryScanCount');

    return (int) $property->getValue($segmenter);
}

/** Read one Linux RSS metric, with allocator usage as the macOS fallback. */
function wp_fts_jieba_bound_rss_bytes(string $field): int
{
    $lines = @file('/proc/self/status', FILE_IGNORE_NEW_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            if ($name !== $field) {
                continue;
            }
            $parts = array_values(array_filter(explode(' ', trim($value)), static fn(string $part): bool => $part !== ''));
            if (isset($parts[0])
                && $parts[0] !== ''
                && strspn($parts[0], '0123456789') === strlen($parts[0])
            ) {
                return (int) $parts[0] * 1024;
            }
        }
    }

    // macOS has no /proc; the allocator ceiling still keeps the local smoke
    // proof meaningful while the Linux real lane records VmRSS/VmHWM.
    return memory_get_usage(true);
}

$root = sys_get_temp_dir() . '/wp-fts-jieba-bound-' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);
$dictionary = $root . '/dict.txt';
$overflowDictionary = $root . '/dict-overflow.txt';
$snapshotDictionary = $root . '/dict-snapshot.txt';

try {
    $snapshotRow = "中文词 10 n\n";
    file_put_contents($snapshotDictionary, $snapshotRow);
    $snapshotSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option([
        'source_file' => $snapshotDictionary,
        'expected_sha256' => hash_file('sha256', $snapshotDictionary),
        'expected_byte_size' => filesize($snapshotDictionary),
        'fixture_only' => true,
    ], 'zh');
    $snapshotStat = stat($snapshotDictionary);
    file_put_contents($snapshotDictionary, "篡改词 10 n\n");
    touch($snapshotDictionary, (int) $snapshotStat['mtime']);
    $snapshotTokens = $snapshotSegmenter('中文词', 'zh');
    wp_fts_jieba_bound_check(
        in_array('中文词', $snapshotTokens, true),
        'a custom dictionary should scan its attested snapshot after a same-size in-place source replacement'
    );

    $chars = [];
    $rows = [];
    $dictionaryWords = [];
    for ($index = 0; $index < 128; $index++) {
        $char = wp_fts_jieba_bound_utf8(0x4E00 + $index);
        $chars[] = $char;
        $dictionaryWords[] = str_repeat($char, 5);
        $rows[] = str_repeat($char, 5) . ' ' . (10000 - $index) . " n\n";
    }
    // The first prefix has exactly the declared 5,000-candidate maximum: its
    // five-character dictionary word above plus 4,999 unique three-character
    // candidates. The repeated-prefix proof below therefore exercises the real
    // candidate-by-offset worst case rather than a convenient smaller sample.
    for ($candidate = 0; $candidate < 4999; $candidate++) {
        $middle = 1 + intdiv($candidate, 127);
        $tail = 1 + ($candidate % 127);
        $rows[] = $chars[0] . $chars[$middle] . $chars[$tail]
            . ' ' . (9000 - $candidate) . " n\n";
    }
    // A nonmatching tail makes each counted pass do real streamed work while
    // keeping the component smoke test compact.
    for ($index = 0; $index < 4096; $index++) {
        $rows[] = '词条' . $index . " 1 n\n";
    }
    file_put_contents($dictionary, implode('', $rows));

    $segmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option([
        'source_file' => $dictionary,
        'expected_sha256' => hash_file('sha256', $dictionary),
        'expected_byte_size' => filesize($dictionary),
        'fixture_only' => true,
    ], 'zh');
    wp_fts_jieba_bound_check($segmenter instanceof WP_FTS_ChineseJiebaSegmenter, 'synthetic Jieba fixture should load');

    $wideRun = implode('', array_slice($dictionaryWords, 0, 64));
    $started = microtime(true);
    $tokens = $segmenter($wideRun, 'zh');
    $elapsed = microtime(true) - $started;
    wp_fts_jieba_bound_check(
        wp_fts_jieba_bound_scan_count($segmenter) === 1,
        '64 distinct Han prefixes must cause exactly one complete dictionary scan'
    );
    wp_fts_jieba_bound_check($elapsed < 1.0, 'the 64-prefix adversary should finish in bounded time');
    foreach (array_slice($dictionaryWords, 0, 64) as $word) {
        wp_fts_jieba_bound_check(
            in_array($word, $tokens, true),
            'wide-run segmentation must preserve every dictionary word across all requested prefixes'
        );
    }
    wp_fts_jieba_bound_check(count($tokens) <= 1400, 'wide-run output should remain proportional to source characters');

    $segmenter($wideRun, 'zh');
    wp_fts_jieba_bound_check(
        wp_fts_jieba_bound_scan_count($segmenter) === 1,
        'repeating an over-wide run must reuse the custom-dictionary range index without another complete scan'
    );

    $omittedWord = $dictionaryWords[64];
    $lateTokens = $segmenter($omittedWord, 'zh');
    wp_fts_jieba_bound_check(
        wp_fts_jieba_bound_scan_count($segmenter) === 1,
        'a later short run must seek the custom-dictionary range index without another complete scan'
    );
    wp_fts_jieba_bound_check(
        in_array($omittedWord, $lateTokens, true),
        'a prefix omitted from one wide run must remain available to a later bounded run'
    );

    $thirtyThreeSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option([
        'source_file' => $dictionary,
        'expected_sha256' => hash_file('sha256', $dictionary),
        'expected_byte_size' => filesize($dictionary),
        'fixture_only' => true,
    ], 'zh');
    $thirtyThreeTokens = $thirtyThreeSegmenter(implode('', array_slice($dictionaryWords, 0, 33)), 'zh');
    wp_fts_jieba_bound_check(
        wp_fts_jieba_bound_scan_count($thirtyThreeSegmenter) === 1,
        'a 33-prefix fixture run must still use one dictionary scan'
    );
    foreach (array_slice($dictionaryWords, 0, 33) as $word) {
        wp_fts_jieba_bound_check(
            in_array($word, $thirtyThreeTokens, true),
            'the 33-prefix boundary must not change segmentation by encounter order'
        );
    }

    $repeatedPrefixSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option([
        'source_file' => $dictionary,
        'expected_sha256' => hash_file('sha256', $dictionary),
        'expected_byte_size' => filesize($dictionary),
        'fixture_only' => true,
    ], 'zh');
    // 1,333 repeated offsets plus 32 distinct trailing characters occupy the
    // largest complete UTF-8 run below the shared 4-KiB lexical limit.
    $repeatedPrefixRun = str_repeat($chars[0], 1333) . implode('', array_slice($chars, 1, 32));
    wp_fts_jieba_bound_check(
        strlen($repeatedPrefixRun) === 4095,
        'the repeated-prefix adversary should exercise the largest valid UTF-8 run below 4 KiB'
    );
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $memoryBefore = memory_get_usage(true);
    $rssBefore = wp_fts_jieba_bound_rss_bytes('VmRSS');
    $started = microtime(true);
    $repeatedPrefixTokens = $repeatedPrefixSegmenter($repeatedPrefixRun, 'zh');
    $repeatedPrefixElapsed = microtime(true) - $started;
    $rssAfter = wp_fts_jieba_bound_rss_bytes('VmRSS');
    $rssPeak = wp_fts_jieba_bound_rss_bytes('VmHWM');
    wp_fts_jieba_bound_check(
        wp_fts_jieba_bound_scan_count($repeatedPrefixSegmenter) === 1,
        'the repeated-prefix adversary must retain the one-scan wide-run contract'
    );
    wp_fts_jieba_bound_check(
        in_array(str_repeat($chars[0], 5), $repeatedPrefixTokens, true),
        'the repeated-prefix adversary must retain its exact longest dictionary match'
    );
    wp_fts_jieba_bound_check(
        $repeatedPrefixElapsed < 5.0,
        '1,333 shared-prefix offsets with 5,000 candidates should finish within five seconds'
    );
    wp_fts_jieba_bound_check(
        memory_get_peak_usage(true) - $memoryBefore <= 16777216,
        'the repeated-prefix adversary must stay within a 16 MiB PHP peak delta'
    );
    wp_fts_jieba_bound_check(
        max(0, $rssAfter - $rssBefore) <= 16777216,
        'the repeated-prefix adversary must stay within a 16 MiB RSS delta'
    );
    wp_fts_jieba_bound_check(
        $rssPeak <= 134217728,
        'the repeated-prefix adversary must stay within a 128 MiB RSS peak'
    );

    $oversizedRunSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option([
        'source_file' => $dictionary,
        'expected_sha256' => hash_file('sha256', $dictionary),
        'expected_byte_size' => filesize($dictionary),
        'fixture_only' => true,
    ], 'zh');
    $oversizedRunError = null;
    try {
        $oversizedRunSegmenter($repeatedPrefixRun . $chars[0], 'zh');
    } catch (Throwable $error) {
        $oversizedRunError = $error;
    }
    wp_fts_jieba_bound_check(
        $oversizedRunError instanceof WP_FTS_Analysis_Limit_Exceeded
            && $oversizedRunError->reason_code === 'lexical_run_bytes',
        'the first complete CJK code point above 4 KiB must raise the shared lexical-run limit'
    );
    wp_fts_jieba_bound_check(
        wp_fts_jieba_bound_scan_count($oversizedRunSegmenter) === 0,
        'an oversized direct Jieba run must fail before scanning the dictionary'
    );

    $overflowCandidate = 4999;
    $overflowMiddle = 1 + intdiv($overflowCandidate, 127);
    $overflowTail = 1 + ($overflowCandidate % 127);
    file_put_contents(
        $overflowDictionary,
        file_get_contents($dictionary)
            . $chars[0] . $chars[$overflowMiddle] . $chars[$overflowTail] . " 1 n\n"
    );
    $overflowSegmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option([
        'source_file' => $overflowDictionary,
        'expected_sha256' => hash_file('sha256', $overflowDictionary),
        'expected_byte_size' => filesize($overflowDictionary),
        'fixture_only' => true,
        'max_candidates_per_prefix' => 5000,
    ], 'zh');
    $overflowRejected = false;
    try {
        $overflowSegmenter($repeatedPrefixRun, 'zh');
    } catch (WP_FTS_Analysis_Limit_Exceeded $error) {
        $overflowRejected = $error->reason_code === 'jieba_dictionary_candidates';
    }
    wp_fts_jieba_bound_check(
        $overflowRejected,
        'candidate 5,001 must raise the typed Jieba dictionary-candidate limit'
    );
    wp_fts_jieba_bound_check(
        wp_fts_jieba_bound_scan_count($overflowSegmenter) === 1,
        'candidate 5,001 must reject during the same single dictionary scan'
    );
} finally {
    if (is_file($snapshotDictionary)) {
        unlink($snapshotDictionary);
    }
    if (is_file($overflowDictionary)) {
        unlink($overflowDictionary);
    }
    if (is_file($dictionary)) {
        unlink($dictionary);
    }
    if (is_dir($root)) {
        rmdir($root);
    }
}

return $wp_fts_jieba_bound_checks;
