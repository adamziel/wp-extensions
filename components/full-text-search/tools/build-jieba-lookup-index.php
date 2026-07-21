<?php
declare(strict_types=1);

const WP_FTS_JIEBA_INDEX_MAGIC = "WPFTSJ2\0";
const WP_FTS_JIEBA_INDEX_MAX_LINE_BYTES = 8192;

/** @param resource $handle */
function wp_fts_jieba_index_write($handle, string $bytes): void
{
    $offset = 0;
    $length = strlen($bytes);
    while ($offset < $length) {
        $written = fwrite($handle, substr($bytes, $offset));
        if (!is_int($written) || $written < 1) {
            throw new RuntimeException('Could not write the Jieba lookup index.');
        }
        $offset += $written;
    }
}

/** @return array{codepoint:int,offset:int,length:int,digest:string} */
function wp_fts_jieba_index_finish_range(
    int $codepoint,
    int $offset,
    int $end,
    HashContext $hash
): array {
    return [
        'codepoint' => $codepoint,
        'offset' => $offset,
        'length' => $end - $offset,
        'digest' => substr(hash_final($hash, true), 0, 16),
    ];
}

/** Decodes the first non-whitespace UTF-8 code point in a dictionary line. */
function wp_fts_jieba_index_codepoint(string $line): int
{
    $length = strlen($line);
    $offset = 0;
    while ($offset < $length && str_contains(" \t\r\n", $line[$offset])) {
        $offset++;
    }
    if ($offset >= $length) {
        return 0;
    }

    $first = ord($line[$offset]);
    if ($first <= 0x7F) {
        return $first;
    }
    if (($first & 0xE0) === 0xC0 && $offset + 1 < $length) {
        return (($first & 0x1F) << 6) | (ord($line[$offset + 1]) & 0x3F);
    }
    if (($first & 0xF0) === 0xE0 && $offset + 2 < $length) {
        return (($first & 0x0F) << 12)
            | ((ord($line[$offset + 1]) & 0x3F) << 6)
            | (ord($line[$offset + 2]) & 0x3F);
    }
    if (($first & 0xF8) === 0xF0 && $offset + 3 < $length) {
        return (($first & 0x07) << 18)
            | ((ord($line[$offset + 1]) & 0x3F) << 12)
            | ((ord($line[$offset + 2]) & 0x3F) << 6)
            | (ord($line[$offset + 3]) & 0x3F);
    }

    throw new RuntimeException('The Jieba dictionary contains invalid UTF-8.');
}

/**
 * @return array{source_bytes:int,source_sha256:string,ranges:int,index_bytes:int,index_sha256:string}
 */
function wp_fts_build_jieba_lookup_index(string $sourcePath, string $outputPath): array
{
    $source = fopen($sourcePath, 'rb');
    if (!is_resource($source)) {
        throw new RuntimeException("Could not open Jieba source: {$sourcePath}");
    }

    $ranges = [];
    $sourceHash = hash_init('sha256');
    $rangeHash = null;
    $rangeCodepoint = null;
    $rangeOffset = 0;
    $rangeEnd = 0;
    $sourceBytes = 0;
    try {
        while (($line = fgets($source, WP_FTS_JIEBA_INDEX_MAX_LINE_BYTES + 3)) !== false) {
            $payloadBytes = strlen($line);
            if ($payloadBytes > 0 && $line[$payloadBytes - 1] === "\n") {
                $payloadBytes--;
                if ($payloadBytes > 0 && $line[$payloadBytes - 1] === "\r") {
                    $payloadBytes--;
                }
            } elseif (!feof($source)) {
                throw new RuntimeException('A Jieba dictionary row exceeds 8 KiB.');
            }
            if ($payloadBytes > WP_FTS_JIEBA_INDEX_MAX_LINE_BYTES) {
                throw new RuntimeException('A Jieba dictionary row exceeds 8 KiB.');
            }

            $lineBytes = strlen($line);
            $codepoint = wp_fts_jieba_index_codepoint($line);
            if ($rangeCodepoint !== $codepoint) {
                if ($rangeCodepoint !== null && $rangeHash instanceof HashContext) {
                    $ranges[] = wp_fts_jieba_index_finish_range(
                        $rangeCodepoint,
                        $rangeOffset,
                        $rangeEnd,
                        $rangeHash
                    );
                }
                $rangeCodepoint = $codepoint;
                $rangeOffset = $sourceBytes;
                $rangeHash = hash_init('sha256');
            }
            hash_update($sourceHash, $line);
            hash_update($rangeHash, $line);
            $sourceBytes += $lineBytes;
            $rangeEnd = $sourceBytes;
        }
        if (!feof($source)) {
            throw new RuntimeException('Could not read the Jieba dictionary source.');
        }
    } finally {
        fclose($source);
    }
    if ($rangeCodepoint !== null && $rangeHash instanceof HashContext) {
        $ranges[] = wp_fts_jieba_index_finish_range(
            $rangeCodepoint,
            $rangeOffset,
            $rangeEnd,
            $rangeHash
        );
    }
    usort(
        $ranges,
        static fn(array $a, array $b): int => [$a['codepoint'], $a['offset']] <=> [$b['codepoint'], $b['offset']]
    );

    $sourceDigest = hash_final($sourceHash, true);
    $output = fopen($outputPath, 'w+b');
    if (!is_resource($output)) {
        throw new RuntimeException("Could not create Jieba index: {$outputPath}");
    }
    try {
        wp_fts_jieba_index_write(
            $output,
            WP_FTS_JIEBA_INDEX_MAGIC
                . $sourceDigest
                . pack('NN', $sourceBytes, count($ranges))
        );
        foreach ($ranges as $range) {
            wp_fts_jieba_index_write(
                $output,
                pack('NNN', $range['codepoint'], $range['offset'], $range['length'])
                    . $range['digest']
            );
        }
        fflush($output);
    } finally {
        fclose($output);
    }

    $indexBytes = filesize($outputPath);
    $indexHash = hash_file('sha256', $outputPath);
    if (!is_int($indexBytes) || !is_string($indexHash)) {
        throw new RuntimeException('Could not attest the generated Jieba index.');
    }

    return [
        'source_bytes' => $sourceBytes,
        'source_sha256' => bin2hex($sourceDigest),
        'ranges' => count($ranges),
        'index_bytes' => $indexBytes,
        'index_sha256' => $indexHash,
    ];
}

/** Compares two generated lookup indexes with bounded streaming reads. */
function wp_fts_jieba_index_files_equal(string $firstPath, string $secondPath): bool
{
    if (filesize($firstPath) !== filesize($secondPath)) {
        return false;
    }
    $first = fopen($firstPath, 'rb');
    $second = fopen($secondPath, 'rb');
    if (!is_resource($first) || !is_resource($second)) {
        throw new RuntimeException('Could not open Jieba indexes for comparison.');
    }
    try {
        while (!feof($first)) {
            if (fread($first, 65536) !== fread($second, 65536)) {
                return false;
            }
        }
        return feof($second);
    } finally {
        fclose($first);
        fclose($second);
    }
}

$arguments = array_slice($argv, 1);
$check = ($arguments[0] ?? '') === '--check';
if ($check) {
    array_shift($arguments);
}
$componentRoot = dirname(__DIR__);
$sourcePath = $arguments[0] ?? $componentRoot . '/resources/sources/jieba/jieba/dict.txt';
$outputPath = $arguments[1] ?? $componentRoot . '/resources/runtime/jieba/dict.idx';
$targetPath = $outputPath;
if ($check) {
    $targetPath = tempnam(sys_get_temp_dir(), 'wp-fts-jieba-index-');
    if (!is_string($targetPath)) {
        throw new RuntimeException('Could not create a temporary Jieba index.');
    }
} else {
    $directory = dirname($outputPath);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException("Could not create Jieba index directory: {$directory}");
    }
}

try {
    $evidence = wp_fts_build_jieba_lookup_index($sourcePath, $targetPath);
    if ($check && (!is_file($outputPath) || !wp_fts_jieba_index_files_equal($targetPath, $outputPath))) {
        throw new RuntimeException('The committed Jieba lookup is not the deterministic v2 output.');
    }
    echo json_encode(
        ['status' => $check ? 'match' : 'written'] + $evidence,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ), "\n";
} finally {
    if ($check && is_file($targetPath)) {
        unlink($targetPath);
    }
}
