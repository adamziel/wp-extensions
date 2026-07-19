<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$wp_fts_jieba_cache_checks = 0;

function wp_fts_jieba_cache_check(bool $condition, string $message): void
{
    global $wp_fts_jieba_cache_checks;
    $wp_fts_jieba_cache_checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wp_fts_jieba_cache_utf8(int $codepoint): string
{
    return chr(0xE0 | ($codepoint >> 12))
        . chr(0x80 | (($codepoint >> 6) & 0x3F))
        . chr(0x80 | ($codepoint & 0x3F));
}

function wp_fts_jieba_cache_segmenter(string $path): WP_FTS_ChineseJiebaSegmenter
{
    $segmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option([
        'source_file' => $path,
        'expected_sha256' => hash_file('sha256', $path),
        'expected_byte_size' => filesize($path),
        'fixture_only' => true,
    ], 'zh');
    if (!$segmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
        throw new RuntimeException('Synthetic Jieba cache-bound fixture should load.');
    }

    return $segmenter;
}

function wp_fts_jieba_cache_property(WP_FTS_ChineseJiebaSegmenter $segmenter, string $name): mixed
{
    return (new ReflectionProperty($segmenter, $name))->getValue($segmenter);
}

function wp_fts_jieba_cache_write_byte_dictionary(
    string $path,
    string $prefix,
    string $target,
    int $candidateBytes
): void {
    $candidateCount = 2048;
    $remainingBytes = $candidateBytes - strlen($target);
    $remainingCandidates = $candidateCount - 1;
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create the retained-word boundary fixture.');
    }
    try {
        fwrite($handle, $target . " 10000 n\n");
        for ($candidate = 1; $candidate < $candidateCount; $candidate++) {
            $wordBytes = intdiv($remainingBytes, $remainingCandidates);
            $lead = $prefix . 'x' . str_pad(base_convert((string) $candidate, 10, 36), 4, '0', STR_PAD_LEFT);
            if ($wordBytes < strlen($lead)) {
                throw new RuntimeException('The retained-word boundary fixture cannot encode a unique candidate.');
            }
            $word = $lead . str_repeat('z', $wordBytes - strlen($lead));
            fwrite($handle, $word . " 1 n\n");
            $remainingBytes -= $wordBytes;
            $remainingCandidates--;
        }
    } finally {
        fclose($handle);
    }
    if ($remainingBytes !== 0 || $remainingCandidates !== 0) {
        throw new RuntimeException('The retained-word boundary fixture did not consume its exact byte allowance.');
    }
}

$root = sys_get_temp_dir() . '/wp-fts-jieba-cache-' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);
$dictionary = $root . '/dict.txt';
$exactByteDictionary = $root . '/exact-bytes.txt';
$overByteDictionary = $root . '/over-bytes.txt';

try {
    $first = wp_fts_jieba_cache_utf8(0x4E00);
    $second = wp_fts_jieba_cache_utf8(0x4E01);
    $firstTail = wp_fts_jieba_cache_utf8(0x4E02);
    $secondTail = wp_fts_jieba_cache_utf8(0x4E03);
    $firstTarget = $first . str_repeat($firstTail, 4);
    $secondTarget = $second . str_repeat($secondTail, 4);
    $rows = [$firstTarget . " 10000 n\n"];
    $firstCandidateBytes = strlen($firstTarget);
    $firstCandidateCount = 4096;
    for ($candidate = 1; $candidate < $firstCandidateCount; $candidate++) {
        $word = $first . 'x' . str_pad(base_convert((string) $candidate, 10, 36), 4, '0', STR_PAD_LEFT);
        $firstCandidateBytes += strlen($word);
        $rows[] = $word . " 1 n\n";
    }
    $rows[] = $secondTarget . " 10000 n\n";
    file_put_contents($dictionary, implode('', $rows));

    wp_fts_jieba_cache_check(
        WP_FTS_ChineseJiebaSegmenter::MAX_RETAINED_DICTIONARY_CANDIDATES === 350000,
        'the compact Jieba prefix cache should cover all 337,461 eligible pinned rows with bounded headroom'
    );
    wp_fts_jieba_cache_check(
        WP_FTS_ChineseJiebaSegmenter::MAX_RETAINED_DICTIONARY_CANDIDATE_BYTES === 8388608,
        'the compact Jieba prefix cache should retain at most 8 MiB of candidate word bytes'
    );
    wp_fts_jieba_cache_check(
        $firstCandidateBytes < WP_FTS_ChineseJiebaSegmenter::MAX_RETAINED_DICTIONARY_CANDIDATE_BYTES,
        'the exact count fixture should remain below the independent word-byte bound'
    );

    $exact = wp_fts_jieba_cache_segmenter($dictionary);
    $exactTokens = $exact($firstTarget, 'zh');
    wp_fts_jieba_cache_check(
        in_array($firstTarget, $exactTokens, true),
        'a complete 4,096-candidate prefix should preserve a five-character dictionary match'
    );
    wp_fts_jieba_cache_check(
        wp_fts_jieba_cache_property($exact, 'dictionaryScanCount') === 1,
        'the complete synthetic prefix should use one source scan'
    );
    wp_fts_jieba_cache_check(
        wp_fts_jieba_cache_property($exact, 'cachedCandidateCount') === 4096,
        'all 4,096 candidates from one complete synthetic prefix should be retained'
    );
    wp_fts_jieba_cache_check(
        wp_fts_jieba_cache_property($exact, 'cachedCandidateBytes') === $firstCandidateBytes,
        'retained candidate word bytes should be accounted exactly'
    );
    wp_fts_jieba_cache_check(
        in_array($firstTarget, $exact($firstTarget, 'zh'), true),
        'a completely cached exact-boundary prefix should preserve its match'
    );
    wp_fts_jieba_cache_check(
        wp_fts_jieba_cache_property($exact, 'dictionaryScanCount') === 1,
        'a completely cached exact-boundary prefix should not rescan'
    );

    $overflow = wp_fts_jieba_cache_segmenter($dictionary);
    $overflowTokens = $overflow($firstTarget . $secondTarget, 'zh');
    wp_fts_jieba_cache_check(
        in_array($firstTarget, $overflowTokens, true) && in_array($secondTarget, $overflowTokens, true),
        'candidate 4,097 should preserve both five-character matches'
    );
    wp_fts_jieba_cache_check(
        wp_fts_jieba_cache_property($overflow, 'dictionaryScanCount') === 1,
        'candidate 4,097 should remain inside the same single dictionary scan'
    );
    wp_fts_jieba_cache_check(
        wp_fts_jieba_cache_property($overflow, 'cachedCandidateCount') === 4097,
        'candidate 4,097 should remain in the compact complete-prefix cache'
    );
    wp_fts_jieba_cache_check(
        wp_fts_jieba_cache_property($overflow, 'cachedCandidateBytes') === $firstCandidateBytes + strlen($secondTarget),
        'candidate 4,097 should retain exact compact word-byte accounting'
    );

    $existingTokens = $exact($secondTarget, 'zh');
    wp_fts_jieba_cache_check(
        in_array($secondTarget, $existingTokens, true),
        'a new prefix above an existing 4,096-row cache should preserve its dictionary match'
    );
    wp_fts_jieba_cache_check(
        wp_fts_jieba_cache_property($exact, 'dictionaryScanCount') === 1,
        'a new prefix above an existing full cache should reuse the custom-dictionary range index'
    );
    wp_fts_jieba_cache_check(
        wp_fts_jieba_cache_property($exact, 'cachedCandidateCount') === 4097,
        'a complete new prefix should coexist with the previously retained prefix'
    );
    $prefixCache = wp_fts_jieba_cache_property($exact, 'prefixCache');
    wp_fts_jieba_cache_check(
        is_array($prefixCache) && isset($prefixCache[$second], $prefixCache[$first]),
        'compact prefix retention should install both complete prefixes'
    );

    $byteTarget = $first . $second . $firstTail . $secondTail . wp_fts_jieba_cache_utf8(0x4E04);
    $retainedByteLimit = WP_FTS_ChineseJiebaSegmenter::MAX_RETAINED_DICTIONARY_CANDIDATE_BYTES;
    wp_fts_jieba_cache_write_byte_dictionary($exactByteDictionary, $first, $byteTarget, $retainedByteLimit);
    wp_fts_jieba_cache_write_byte_dictionary($overByteDictionary, $first, $byteTarget, $retainedByteLimit + 1);

    $exactBytes = wp_fts_jieba_cache_segmenter($exactByteDictionary);
    wp_fts_jieba_cache_check(
        in_array($byteTarget, $exactBytes($byteTarget, 'zh'), true),
        'the exact 8-MiB retained-word boundary should preserve its dictionary match'
    );
    wp_fts_jieba_cache_check(
        wp_fts_jieba_cache_property($exactBytes, 'dictionaryScanCount') === 1,
        'the exact retained-word boundary should use one dictionary scan'
    );
    wp_fts_jieba_cache_check(
        wp_fts_jieba_cache_property($exactBytes, 'cachedCandidateCount') === 2048,
        'the exact retained-word boundary should cache every complete row'
    );
    wp_fts_jieba_cache_check(
        wp_fts_jieba_cache_property($exactBytes, 'cachedCandidateBytes') === $retainedByteLimit,
        'the exact 8-MiB retained-word boundary should remain cached'
    );

    $overBytes = wp_fts_jieba_cache_segmenter($overByteDictionary);
    $overByteError = null;
    try {
        $overBytes($byteTarget, 'zh');
    } catch (WP_FTS_Analysis_Limit_Exceeded $error) {
        $overByteError = $error;
    }
    wp_fts_jieba_cache_check(
        $overByteError instanceof WP_FTS_Analysis_Limit_Exceeded
            && $overByteError->reason_code === 'jieba_dictionary_candidates',
        'retained word byte 8,388,609 should fail complete-cache admission explicitly'
    );
    wp_fts_jieba_cache_check(
        wp_fts_jieba_cache_property($overBytes, 'dictionaryScanCount') === 1,
        'retained word byte 8,388,609 should reject during the one dynamic-index scan'
    );
    wp_fts_jieba_cache_check(
        wp_fts_jieba_cache_property($overBytes, 'cachedCandidateCount') === 0,
        'a prefix above the retained-word boundary should install no partial compact cache'
    );
    wp_fts_jieba_cache_check(
        wp_fts_jieba_cache_property($overBytes, 'cachedCandidateBytes') === 0,
        'a prefix above the retained-word boundary should release all provisional candidate words'
    );
    try {
        $overBytes($byteTarget, 'zh');
    } catch (WP_FTS_Analysis_Limit_Exceeded) {
    }
    wp_fts_jieba_cache_check(
        wp_fts_jieba_cache_property($overBytes, 'dictionaryScanCount') === 1,
        'repeating an inadmissible custom lookup should reuse its permanent failure without rescanning'
    );
    unset($exact, $overflow, $exactBytes, $overBytes, $prefixCache);
} finally {
    foreach ([$overByteDictionary, $exactByteDictionary, $dictionary] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (is_dir($root)) {
        rmdir($root);
    }
}

return $wp_fts_jieba_cache_checks;
