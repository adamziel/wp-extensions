<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$wp_fts_jieba_line_checks = 0;

/** Records one assertion and throws when a Jieba line-bound invariant fails. */
function wp_fts_jieba_line_check(bool $condition, string $message): void
{
    global $wp_fts_jieba_line_checks;
    $wp_fts_jieba_line_checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Encodes the fixture's three-byte Unicode code point as UTF-8. */
function wp_fts_jieba_line_utf8(int $codepoint): string
{
    return chr(0xE0 | ($codepoint >> 12))
        . chr(0x80 | (($codepoint >> 6) & 0x3F))
        . chr(0x80 | ($codepoint & 0x3F));
}

/** Creates a fixture-only segmenter for a line-bound dictionary probe. */
function wp_fts_jieba_line_segmenter(string $path): WP_FTS_ChineseJiebaSegmenter
{
    $segmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option([
        'source_file' => $path,
        'expected_sha256' => hash_file('sha256', $path),
        'expected_byte_size' => filesize($path),
        'fixture_only' => true,
    ], 'zh');
    if (!$segmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
        throw new RuntimeException('Synthetic Jieba line-bound fixture should load.');
    }

    return $segmenter;
}

/** Returns the private dictionary-scan count for line-bound assertions. */
function wp_fts_jieba_line_scan_count(WP_FTS_ChineseJiebaSegmenter $segmenter): int
{
    $property = new ReflectionProperty($segmenter, 'dictionaryScanCount');

    return (int) $property->getValue($segmenter);
}

/** @return array{0:?Throwable,1:int} */
function wp_fts_jieba_line_invoke(string $path, string $run): array
{
    $segmenter = wp_fts_jieba_line_segmenter($path);
    $error = null;
    try {
        $segmenter($run, 'zh');
    } catch (Throwable $caught) {
        $error = $caught;
    }

    return [$error, wp_fts_jieba_line_scan_count($segmenter)];
}

$root = sys_get_temp_dir() . '/wp-fts-jieba-line-' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);
$exactDictionary = $root . '/exact.txt';
$overDictionary = $root . '/over.txt';

try {
    wp_fts_jieba_line_check(
        WP_FTS_ChineseJiebaSegmenter::MAX_DICTIONARY_LINE_BYTES === 8192,
        'the Jieba row bound should remain exactly 8 KiB'
    );
    wp_fts_jieba_line_check(
        WP_FTS_ChineseJiebaSegmenter::SOURCE_SHA256 === '7197c3211ddd98962b036cdf40324d1ea2bfaa12bd028e68faa70111a88e12a8',
        'line containment must not change the pinned Jieba dictionary digest'
    );
    wp_fts_jieba_line_check(
        WP_FTS_ChineseJiebaSegmenter::SOURCE_BYTE_SIZE === 5071852,
        'line containment must not change the pinned Jieba dictionary byte size'
    );
    $defaultDictionary = WP_FTS_ChineseJiebaSegmenter::default_source_file();
    wp_fts_jieba_line_check(
        $defaultDictionary === dirname(__DIR__) . '/resources/sources/jieba/' . WP_FTS_ChineseJiebaSegmenter::SOURCE_FILE,
        'the default Jieba option should resolve the component-owned source checkout'
    );
    if (is_file($defaultDictionary)) {
        wp_fts_jieba_line_check(
            filesize($defaultDictionary) === WP_FTS_ChineseJiebaSegmenter::SOURCE_BYTE_SIZE,
            'the available pinned Jieba dictionary should preserve its declared byte size'
        );
        wp_fts_jieba_line_check(
            hash_file('sha256', $defaultDictionary) === WP_FTS_ChineseJiebaSegmenter::SOURCE_SHA256,
            'the available pinned Jieba dictionary should preserve its declared digest'
        );
    }

    $chars = [];
    for ($offset = 0; $offset < 33; $offset++) {
        $chars[] = wp_fts_jieba_line_utf8(0x4E00 + $offset);
    }
    $word = $chars[0] . $chars[1];
    $prefix = $word . ' 1 ';
    $exactPayload = $prefix . str_repeat(
        'n',
        WP_FTS_ChineseJiebaSegmenter::MAX_DICTIONARY_LINE_BYTES - strlen($prefix)
    );
    $overPayload = $exactPayload . 'n';
    wp_fts_jieba_line_check(
        strlen($exactPayload) === WP_FTS_ChineseJiebaSegmenter::MAX_DICTIONARY_LINE_BYTES,
        'the accepted dictionary fixture should contain exactly 8 KiB before LF'
    );
    wp_fts_jieba_line_check(
        strlen($overPayload) === WP_FTS_ChineseJiebaSegmenter::MAX_DICTIONARY_LINE_BYTES + 1,
        'the rejected dictionary fixture should cross the row bound by one byte'
    );
    file_put_contents($exactDictionary, $exactPayload . "\n");
    file_put_contents($overDictionary, $overPayload . "\n");

    $preloadSegmenter = wp_fts_jieba_line_segmenter($exactDictionary);
    $preloadTokens = $preloadSegmenter($word, 'zh');
    wp_fts_jieba_line_check(
        in_array($word, $preloadTokens, true),
        'the prefix preload path should accept and match an exact 8-KiB row'
    );
    wp_fts_jieba_line_check(
        wp_fts_jieba_line_scan_count($preloadSegmenter) === 1,
        'the exact preload boundary should use one dictionary scan'
    );

    $bufferReader = new ReflectionMethod($preloadSegmenter, 'dictionary_lines_from_buffer');
    $exactLfRows = iterator_to_array($bufferReader->invoke(
        $preloadSegmenter,
        str_repeat('x', WP_FTS_ChineseJiebaSegmenter::MAX_DICTIONARY_LINE_BYTES) . "\n"
    ));
    wp_fts_jieba_line_check(
        count($exactLfRows) === 1,
        'the attested-buffer reader should accept exactly 8 KiB before LF'
    );
    $exactCrlfRows = iterator_to_array($bufferReader->invoke(
        $preloadSegmenter,
        str_repeat('x', WP_FTS_ChineseJiebaSegmenter::MAX_DICTIONARY_LINE_BYTES) . "\r\n"
    ));
    wp_fts_jieba_line_check(
        count($exactCrlfRows) === 1,
        'the attested-buffer reader should accept exactly 8 KiB before CRLF'
    );
    foreach (["\n" => 'LF', "\r\n" => 'CRLF'] as $ending => $label) {
        $bufferRejected = false;
        try {
            iterator_to_array($bufferReader->invoke(
                $preloadSegmenter,
                str_repeat('x', WP_FTS_ChineseJiebaSegmenter::MAX_DICTIONARY_LINE_BYTES + 1) . $ending
            ));
        } catch (WP_FTS_Analysis_Limit_Exceeded $error) {
            $bufferRejected = $error->reason_code === 'jieba_dictionary_line_bytes';
        }
        wp_fts_jieba_line_check(
            $bufferRejected,
            "the attested-buffer reader should reject byte 8,193 before {$label}"
        );
    }

    $wideSegmenter = wp_fts_jieba_line_segmenter($exactDictionary);
    $wideTokens = $wideSegmenter(implode('', $chars), 'zh');
    wp_fts_jieba_line_check(
        in_array($word, $wideTokens, true),
        'the wide-run path should accept and match an exact 8-KiB row'
    );
    wp_fts_jieba_line_check(
        wp_fts_jieba_line_scan_count($wideSegmenter) === 1,
        'the exact wide-run boundary should use one dictionary scan'
    );

    foreach (['preload' => $word, 'wide' => implode('', $chars)] as $path => $run) {
        [$error, $scanCount] = wp_fts_jieba_line_invoke($overDictionary, $run);
        wp_fts_jieba_line_check(
            $error instanceof WP_FTS_Analysis_Limit_Exceeded,
            "the {$path} path should reject dictionary row byte 8,193 with the typed analysis limit"
        );
        wp_fts_jieba_line_check(
            $error instanceof WP_FTS_Analysis_Limit_Exceeded
                && $error->reason_code === 'jieba_dictionary_line_bytes',
            "the {$path} row rejection should retain the stable Jieba line reason"
        );
        wp_fts_jieba_line_check(
            $scanCount === 1,
            "the {$path} row rejection should happen during its first dictionary scan"
        );
    }
} finally {
    foreach ([$overDictionary, $exactDictionary] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (is_dir($root)) {
        rmdir($root);
    }
}

return $wp_fts_jieba_line_checks;
