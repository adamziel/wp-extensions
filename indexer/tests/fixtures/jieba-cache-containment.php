<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

function wp_fts_jieba_cache_fixture_utf8(int $codepoint): string
{
    return chr(0xE0 | ($codepoint >> 12))
        . chr(0x80 | (($codepoint >> 6) & 0x3F))
        . chr(0x80 | ($codepoint & 0x3F));
}

/** @return array{VmHWM_bytes:?int,VmRSS_bytes:?int} */
function wp_fts_jieba_cache_fixture_proc_status(): array
{
    if (!is_readable('/proc/self/status')) {
        return ['VmHWM_bytes' => null, 'VmRSS_bytes' => null];
    }

    $values = [];
    $handle = fopen('/proc/self/status', 'rb');
    if (!is_resource($handle)) {
        return ['VmHWM_bytes' => null, 'VmRSS_bytes' => null];
    }
    try {
        while (($line = fgets($handle)) !== false) {
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $key = substr($line, 0, $separator);
            if (!in_array($key, ['VmHWM', 'VmRSS'], true)) {
                continue;
            }
            $value = trim(substr($line, $separator + 1));
            $space = strpos($value, ' ');
            if ($space !== false && ctype_digit(substr($value, 0, $space)) && strtolower(trim(substr($value, $space + 1))) === 'kb') {
                $values[$key] = (int) substr($value, 0, $space) * 1024;
            }
        }
    } finally {
        fclose($handle);
    }

    return [
        'VmHWM_bytes' => $values['VmHWM'] ?? null,
        'VmRSS_bytes' => $values['VmRSS'] ?? null,
    ];
}

$root = sys_get_temp_dir() . '/wp-fts-jieba-cache-containment-' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);
$dictionary = $root . '/dict.txt';

try {
    $prefixCount = 32;
    $candidatesPerPrefix = 5000;
    $prefixes = [];
    for ($prefix = 0; $prefix < $prefixCount; $prefix++) {
        $prefixes[] = wp_fts_jieba_cache_fixture_utf8(0x4E00 + $prefix);
    }
    $target = implode('', array_slice($prefixes, 0, 5));

    $handle = fopen($dictionary, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create the Jieba cache-containment dictionary.');
    }
    try {
        $buffer = '';
        foreach ($prefixes as $prefixOffset => $prefix) {
            for ($candidate = 0; $candidate < $candidatesPerPrefix; $candidate++) {
                $word = $prefixOffset === 0 && $candidate === 0
                    ? $target
                    : $prefix . 'x' . str_pad(base_convert((string) $candidate, 10, 36), 4, '0', STR_PAD_LEFT);
                $buffer .= $word . ' ' . ($candidatesPerPrefix - $candidate) . " n\n";
                if (strlen($buffer) >= 65536) {
                    fwrite($handle, $buffer);
                    $buffer = '';
                }
            }
        }
        if ($buffer !== '') {
            fwrite($handle, $buffer);
        }
    } finally {
        fclose($handle);
    }

    $sourceBytes = filesize($dictionary);
    $sha256 = hash_file('sha256', $dictionary);
    if (!is_int($sourceBytes) || !is_string($sha256)) {
        throw new RuntimeException('Could not inspect the Jieba cache-containment dictionary.');
    }
    $segmenter = WP_FTS_ChineseJiebaSegmenter::from_pack_option([
        'source_file' => $dictionary,
        'expected_sha256' => $sha256,
        'expected_byte_size' => $sourceBytes,
        'fixture_only' => true,
        'max_candidates_per_prefix' => $candidatesPerPrefix,
    ], 'zh');
    if (!$segmenter instanceof WP_FTS_ChineseJiebaSegmenter) {
        throw new RuntimeException('Could not load the Jieba cache-containment dictionary.');
    }

    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $peakBefore = memory_get_peak_usage(true);
    $started = microtime(true);
    $tokens = $segmenter(implode('', $prefixes), 'zh');
    $elapsed = microtime(true) - $started;
    $peakAfter = memory_get_peak_usage(true);

    echo json_encode([
        'source_bytes' => $sourceBytes,
        'prefixes' => $prefixCount,
        'candidates_per_prefix' => $candidatesPerPrefix,
        'candidate_rows' => $prefixCount * $candidatesPerPrefix,
        'target_match_preserved' => in_array($target, $tokens, true),
        'token_count' => count($tokens),
        'dictionary_scan_count' => (int) (new ReflectionProperty($segmenter, 'dictionaryScanCount'))->getValue($segmenter),
        'cached_candidate_count' => (int) (new ReflectionProperty($segmenter, 'cachedCandidateCount'))->getValue($segmenter),
        'cached_candidate_bytes' => (int) (new ReflectionProperty($segmenter, 'cachedCandidateBytes'))->getValue($segmenter),
        'retained_candidate_limit' => WP_FTS_ChineseJiebaSegmenter::MAX_RETAINED_DICTIONARY_CANDIDATES,
        'retained_candidate_byte_limit' => WP_FTS_ChineseJiebaSegmenter::MAX_RETAINED_DICTIONARY_CANDIDATE_BYTES,
        'elapsed_seconds' => $elapsed,
        'php_peak_bytes' => $peakAfter,
        'php_peak_delta_bytes' => max(0, $peakAfter - $peakBefore),
        'proc_status' => wp_fts_jieba_cache_fixture_proc_status(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (is_file($dictionary)) {
        unlink($dictionary);
    }
    if (is_dir($root)) {
        rmdir($root);
    }
}
