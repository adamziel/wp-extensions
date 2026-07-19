<?php
declare(strict_types=1);

/**
 * Builds and reads a seekable, block-compressed index for lemma runtime rows.
 *
 * A normal gzip stream cannot seek to one dictionary key without inflating all
 * preceding bytes. The indexed runtime is therefore a concatenation of small,
 * independently compressed gzip members. Its sidecar records each member's byte
 * offset and surface range, so lookup inflates one member without duplicating
 * dictionary data in the index.
 */
final class WP_FTS_LemmaPackLookupIndex
{
    public const FORMAT = 'wp-fts-lemma-block-index-v1';
    public const DEFAULT_BLOCK_ROWS = 2048;

    private const MAGIC = "WPFTSLI1";
    private const HEADER_PREFIX_BYTES = 12;
    private const MAX_HEADER_BYTES = 65536;
    private const MAX_BLOCK_DECODED_BYTES = 1048576;
    private const MAX_DECODED_BLOCK_CACHE_BYTES = 4194304;

    /** @var array<string,string> */
    private static array $decodedBlockCache = [];
    private static int $decodedBlockCacheBytes = 0;

    /**
     * Repack one sorted gzip runtime shard into independently compressed members
     * and build its offset sidecar.
     *
     * @return array{format:string,sha256:string,runtime_sha256:string,blocks:int,rows:int,rows_sha256:string}
     */
    public static function build(
        string $runtimePath,
        ?string $compression,
        string $runtimeSha256,
        string $outputPath,
        int $blockRows = self::DEFAULT_BLOCK_ROWS
    ): array {
        if ($blockRows < 1) {
            throw new InvalidArgumentException('Lemma lookup index block row limit must be positive.');
        }
        if ($compression !== 'gzip') {
            throw new InvalidArgumentException('Lemma lookup indexes require a gzip runtime shard.');
        }
        if (!function_exists('gzencode') || !function_exists('gzdecode')) {
            throw new RuntimeException('Lemma lookup index generation requires PHP zlib support.');
        }

        $runtimePath = self::canonical_file($runtimePath, 'runtime shard');
        $actualRuntimeSha256 = hash_file('sha256', $runtimePath);
        if (
            !is_string($actualRuntimeSha256)
            || !hash_equals(strtolower($runtimeSha256), strtolower($actualRuntimeSha256))
        ) {
            throw new RuntimeException('Lemma lookup index source digest does not match the runtime shard.');
        }
        $outputDirectory = dirname($outputPath);
        if (!is_dir($outputDirectory)) {
            throw new RuntimeException("Lemma lookup index output directory does not exist: {$outputDirectory}");
        }

        $stagedRuntimePath = tempnam(dirname($runtimePath), '.wp-fts-lemma-runtime-');
        $stagedPath = tempnam($outputDirectory, '.wp-fts-lemma-index-');
        if (!is_string($stagedRuntimePath) || !is_string($stagedPath)) {
            if (is_string($stagedRuntimePath)) {
                @unlink($stagedRuntimePath);
            }
            if (is_string($stagedPath)) {
                @unlink($stagedPath);
            }
            throw new RuntimeException('Could not create temporary lemma lookup index files.');
        }

        try {
            $runtimeHandle = self::open_runtime_file($runtimePath, $compression);
        } catch (Throwable $e) {
            @unlink($stagedRuntimePath);
            @unlink($stagedPath);
            throw $e;
        }
        $stagedRuntimeHandle = fopen($stagedRuntimePath, 'w+b');
        if (!is_resource($stagedRuntimeHandle)) {
            self::close_runtime_file($runtimeHandle, $compression);
            @unlink($stagedRuntimePath);
            @unlink($stagedPath);
            throw new RuntimeException('Could not open the staged indexed runtime shard.');
        }

        $blocks = [];
        $blockLines = [];
        $blockFirstSurface = null;
        $blockLastSurface = null;
        $rows = 0;
        $rowsDigest = hash_init('sha256');
        $previousKey = null;

        $flushBlock = static function () use (
            &$blocks,
            &$blockLines,
            &$blockFirstSurface,
            &$blockLastSurface,
            $stagedRuntimeHandle
        ): void {
            if ($blockLines === []) {
                return;
            }
            if (count($blocks) >= WP_FTS_Analyzer_Config_Limits::MAX_LOOKUP_BLOCKS_PER_FILE) {
                throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                    'lookup_blocks',
                    'Lemma lookup index exceeds the 256-block per-file limit.'
                );
            }

            $plain = implode('', $blockLines);
            if (strlen($plain) > self::MAX_BLOCK_DECODED_BYTES) {
                throw new RuntimeException('Lemma lookup index block exceeds its decoded byte limit.');
            }
            $encoded = gzencode($plain, 9, ZLIB_ENCODING_GZIP);
            if (!is_string($encoded)) {
                throw new RuntimeException('Could not compress a lemma lookup index block.');
            }

            $offset = ftell($stagedRuntimeHandle);
            if (!is_int($offset) || !self::write_all($stagedRuntimeHandle, $encoded)) {
                throw new RuntimeException('Could not write an indexed runtime gzip member.');
            }

            $blocks[] = [
                'first_surface' => $blockFirstSurface,
                'last_surface' => $blockLastSurface,
                'offset' => $offset,
                'length' => strlen($encoded),
                'rows' => count($blockLines),
            ];
            $blockLines = [];
            $blockFirstSurface = null;
            $blockLastSurface = null;
        };

        try {
            try {
                while (($line = WP_FTS_LemmaPackLimits::read_runtime_line($runtimeHandle, $compression)) !== false) {
                    $line = rtrim(rtrim((string) $line, "\n"), "\r");
                    if ($line === '' || $line[0] === '#') {
                        continue;
                    }

                    $pair = self::parse_pair($line);
                    if ($pair === null) {
                        throw new RuntimeException("Runtime row in {$runtimePath} must have exactly two TSV columns.");
                    }
                    $key = $pair['surface'] . "\t" . $pair['lemma'];
                    if ($previousKey !== null && strcmp($previousKey, $key) >= 0) {
                        throw new RuntimeException("Runtime rows in {$runtimePath} must be unique and sorted.");
                    }
                    if (
                        count($blockLines) >= $blockRows
                        && $blockLastSurface !== null
                        && $pair['surface'] !== $blockLastSurface
                    ) {
                        $flushBlock();
                    }

                    $normalizedLine = $key . "\n";
                    $blockLines[] = $normalizedLine;
                    $blockFirstSurface ??= $pair['surface'];
                    $blockLastSurface = $pair['surface'];
                    $previousKey = $key;
                    $rows++;
                    hash_update($rowsDigest, $normalizedLine);
                }
                $flushBlock();
            } finally {
                self::close_runtime_file($runtimeHandle, $compression);
                fclose($stagedRuntimeHandle);
            }
        } catch (Throwable $e) {
            @unlink($stagedRuntimePath);
            @unlink($stagedPath);
            throw $e;
        }

        if ($rows < 1 || $blocks === []) {
            @unlink($stagedRuntimePath);
            @unlink($stagedPath);
            throw new RuntimeException('Lemma lookup index source must contain runtime rows.');
        }

        $indexedRuntimeSha256 = hash_file('sha256', $stagedRuntimePath);
        if (!is_string($indexedRuntimeSha256)) {
            @unlink($stagedRuntimePath);
            @unlink($stagedPath);
            throw new RuntimeException('Could not hash the indexed runtime shard.');
        }

        try {
            $header = [
                'format' => self::FORMAT,
                'runtime_sha256' => $indexedRuntimeSha256,
                'rows' => $rows,
                'rows_sha256' => hash_final($rowsDigest),
                'block_rows' => $blockRows,
                'blocks' => $blocks,
            ];
            $headerJson = json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            @unlink($stagedRuntimePath);
            @unlink($stagedPath);
            throw $e;
        }
        if (strlen($headerJson) > self::MAX_HEADER_BYTES) {
            @unlink($stagedRuntimePath);
            @unlink($stagedPath);
            throw new RuntimeException('Lemma lookup index header exceeds its bounded size.');
        }

        $outputHandle = fopen($stagedPath, 'w+b');
        if (!is_resource($outputHandle)) {
            @unlink($stagedRuntimePath);
            @unlink($stagedPath);
            throw new RuntimeException('Could not assemble the lemma lookup index.');
        }

        try {
            try {
                $prefix = self::MAGIC . pack('N', strlen($headerJson)) . $headerJson;
                if (!self::write_all($outputHandle, $prefix)) {
                    throw new RuntimeException('Could not write the lemma lookup index.');
                }
                if (!fflush($outputHandle)) {
                    throw new RuntimeException('Could not flush the lemma lookup index.');
                }
            } finally {
                fclose($outputHandle);
            }
        } catch (Throwable $e) {
            @unlink($stagedRuntimePath);
            @unlink($stagedPath);
            throw $e;
        }

        $runtimePermissions = fileperms($runtimePath);
        if (is_int($runtimePermissions)) {
            @chmod($stagedRuntimePath, $runtimePermissions & 0777);
        }
        @chmod($stagedPath, 0644);
        if (!rename($stagedRuntimePath, $runtimePath)) {
            @unlink($stagedRuntimePath);
            @unlink($stagedPath);
            throw new RuntimeException("Could not publish indexed runtime shard: {$runtimePath}");
        }
        if (!rename($stagedPath, $outputPath)) {
            @unlink($stagedPath);
            throw new RuntimeException("Could not publish lemma lookup index: {$outputPath}");
        }

        $sha256 = hash_file('sha256', $outputPath);
        if (!is_string($sha256)) {
            throw new RuntimeException("Could not hash lemma lookup index: {$outputPath}");
        }

        return [
            'format' => self::FORMAT,
            'sha256' => $sha256,
            'runtime_sha256' => $indexedRuntimeSha256,
            'blocks' => count($blocks),
            'rows' => $rows,
            'rows_sha256' => (string) $header['rows_sha256'],
        ];
    }

    /**
     * Validate the bounded header and indexed runtime layout without inflating
     * gzip members.
     *
     * @return array{format:string,runtime_sha256:string,rows:int,rows_sha256:string,block_rows:int,blocks:array<int,array{first_surface:string,last_surface:string,offset:int,length:int,rows:int}>,path:string,runtime_path:string}
     */
    public static function metadata(
        string $path,
        string $runtimePath,
        string $expectedRuntimeSha256,
        int $expectedRows
    ): array
    {
        $path = self::canonical_file($path, 'lookup index');
        $runtimePath = self::canonical_file($runtimePath, 'runtime shard');
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Could not read lemma lookup index: {$path}");
        }

        try {
            $prefix = self::read_exact($handle, self::HEADER_PREFIX_BYTES);
            if (substr($prefix, 0, 8) !== self::MAGIC) {
                throw new RuntimeException('Lemma lookup index magic is invalid.');
            }
            $length = unpack('Nlength', substr($prefix, 8, 4));
            $headerLength = is_array($length) ? (int) ($length['length'] ?? 0) : 0;
            if ($headerLength < 2 || $headerLength > self::MAX_HEADER_BYTES) {
                throw new RuntimeException('Lemma lookup index header length is invalid.');
            }
            $headerJson = self::read_exact($handle, $headerLength);
        } finally {
            fclose($handle);
        }

        $header = json_decode(
            $headerJson,
            true,
            WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_GRAPH_DEPTH + 2,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($header) || ($header['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('Lemma lookup index format is invalid.');
        }
        WP_FTS_Analyzer_Config_Limits::assert_manifest_graph($header);
        if (!is_string($header['runtime_sha256'] ?? null) || !hash_equals(strtolower($expectedRuntimeSha256), strtolower($header['runtime_sha256']))) {
            throw new RuntimeException('Lemma lookup index does not attest the runtime shard digest.');
        }
        if (
            ($header['rows'] ?? null) !== $expectedRows
            || !is_string($header['rows_sha256'] ?? null)
            || strlen($header['rows_sha256']) !== 64
            || strspn($header['rows_sha256'], '0123456789abcdefABCDEF') !== 64
        ) {
            throw new RuntimeException('Lemma lookup index row metadata does not match the runtime shard.');
        }
        if (!is_int($header['block_rows'] ?? null) || $header['block_rows'] < 1 || !is_array($header['blocks'] ?? null) || $header['blocks'] === []) {
            throw new RuntimeException('Lemma lookup index block metadata is invalid.');
        }
        if (count($header['blocks']) > WP_FTS_Analyzer_Config_Limits::MAX_LOOKUP_BLOCKS_PER_FILE) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'lookup_blocks',
                'Lemma lookup index exceeds the 256-block per-file limit.'
            );
        }

        $indexSize = @filesize($path);
        if (!is_int($indexSize) || $indexSize !== self::HEADER_PREFIX_BYTES + $headerLength) {
            throw new RuntimeException('Lemma lookup index contains undeclared payload bytes.');
        }
        $runtimeSize = @filesize($runtimePath);
        if (!is_int($runtimeSize) || $runtimeSize < 1) {
            throw new RuntimeException('Indexed lemma runtime payload is missing.');
        }

        $blocks = [];
        $nextOffset = 0;
        $rows = 0;
        $previousLast = null;
        foreach ($header['blocks'] as $block) {
            if (
                !is_array($block)
                || !is_string($block['first_surface'] ?? null)
                || $block['first_surface'] === ''
                || !is_string($block['last_surface'] ?? null)
                || $block['last_surface'] === ''
                || !is_int($block['offset'] ?? null)
                || !is_int($block['length'] ?? null)
                || !is_int($block['rows'] ?? null)
                || $block['offset'] !== $nextOffset
                || $block['length'] < 1
                || $block['rows'] < 1
                || strcmp($block['first_surface'], $block['last_surface']) > 0
                || ($previousLast !== null && strcmp($previousLast, $block['first_surface']) >= 0)
            ) {
                throw new RuntimeException('Lemma lookup index block entry is invalid.');
            }

            $blocks[] = $block;
            $nextOffset += $block['length'];
            $rows += $block['rows'];
            $previousLast = $block['last_surface'];
        }
        if ($rows !== $expectedRows || $nextOffset !== $runtimeSize) {
            throw new RuntimeException('Indexed lemma runtime size or row count is invalid.');
        }

        return [
            'format' => self::FORMAT,
            'runtime_sha256' => strtolower($header['runtime_sha256']),
            'rows' => $rows,
            'rows_sha256' => strtolower($header['rows_sha256']),
            'block_rows' => $header['block_rows'],
            'blocks' => $blocks,
            'path' => $path,
            'runtime_path' => $runtimePath,
        ];
    }

    /**
     * Inflate every indexed gzip member and attest its sorted row stream.
     *
     * @param array{rows:int,rows_sha256:string,blocks:array<int,array{first_surface:string,last_surface:string,offset:int,length:int,rows:int}>,path:string,runtime_path:string} $metadata
     */
    public static function validate_content(array $metadata, string $expectedRowsSha256): void
    {
        $digest = hash_init('sha256');
        $previousKey = null;
        $rows = 0;
        foreach ($metadata['blocks'] as $block) {
            $decoded = self::read_decoded_block($metadata, $block);
            $blockRows = 0;
            $firstSurface = null;
            $lastSurface = null;
            foreach (self::decoded_lines($decoded) as $line) {
                $pair = self::parse_pair($line);
                if ($pair === null) {
                    throw new RuntimeException('Lemma lookup index block contains an invalid TSV row.');
                }
                $key = $pair['surface'] . "\t" . $pair['lemma'];
                if ($previousKey !== null && strcmp($previousKey, $key) >= 0) {
                    throw new RuntimeException('Lemma lookup index rows are not globally unique and sorted.');
                }
                $previousKey = $key;
                $firstSurface ??= $pair['surface'];
                $lastSurface = $pair['surface'];
                $blockRows++;
                $rows++;
                hash_update($digest, $key . "\n");
            }

            if (
                $blockRows !== $block['rows']
                || $firstSurface !== $block['first_surface']
                || $lastSurface !== $block['last_surface']
            ) {
                throw new RuntimeException('Lemma lookup index block range or row count is invalid.');
            }
        }

        $actualDigest = hash_final($digest);
        if ($rows !== $metadata['rows'] || !hash_equals(strtolower($expectedRowsSha256), strtolower($actualDigest))) {
            throw new RuntimeException('Lemma lookup index rows do not match the runtime shard.');
        }
    }

    /**
     * Lookup one surface by inflating only its indexed block.
     *
     * @param array{blocks:array<int,array{first_surface:string,last_surface:string,offset:int,length:int,rows:int}>,path:string,runtime_path:string} $metadata
     * @return array{lemmas:array<string,bool>,lines_read:int,compressed_bytes:int,decoded_bytes:int}
     */
    public static function lookup(array $metadata, string $term): array
    {
        $low = 0;
        $high = count($metadata['blocks']) - 1;
        $candidate = null;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $block = $metadata['blocks'][$mid];
            if (strcmp($term, $block['first_surface']) < 0) {
                $high = $mid - 1;
                continue;
            }
            if (strcmp($term, $block['last_surface']) > 0) {
                $low = $mid + 1;
                continue;
            }
            $candidate = $block;
            break;
        }

        if ($candidate === null) {
            return [
                'lemmas' => [],
                'lines_read' => 0,
                'compressed_bytes' => 0,
                'decoded_bytes' => 0,
            ];
        }

        $decoded = self::read_decoded_block($metadata, $candidate);
        $lemmas = [];
        $linesRead = 0;
        $offset = self::first_surface_offset($decoded, $term, $linesRead);
        $decodedLength = strlen($decoded);
        while ($offset !== null && $offset < $decodedLength) {
            $line = self::decoded_line_at_or_after($decoded, $offset);
            if ($line === null) {
                break;
            }
            $linesRead++;
            $pair = self::parse_pair($line['line']);
            if ($pair === null) {
                throw new RuntimeException('Lemma lookup index block contains an invalid TSV row.');
            }
            $offset = $line['end'] + 1;
            $comparison = strcmp($pair['surface'], $term);
            if ($comparison < 0) {
                continue;
            }
            if ($comparison > 0) {
                break;
            }
            $lemmas[$pair['lemma']] = true;
            WP_FTS_LemmaPackLimits::assert_surface_lemma_count($term, count($lemmas));
        }

        return [
            'lemmas' => $lemmas,
            'lines_read' => $linesRead,
            'compressed_bytes' => $candidate['length'],
            'decoded_bytes' => strlen($decoded),
        ];
    }

    /**
     * @return string[]
     */
    private static function decoded_lines(string $decoded): array
    {
        $lines = explode("\n", $decoded);
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * @param array{runtime_path:string} $metadata
     * @param array{offset:int,length:int} $block
     */
    private static function read_decoded_block(array $metadata, array $block): string
    {
        if (!function_exists('gzdecode')) {
            throw new RuntimeException('Lemma lookup index loading requires PHP zlib support.');
        }

        $cacheKey = hash('sha256', implode("\0", [
            (string) $metadata['runtime_path'],
            (string) ($metadata['runtime_sha256'] ?? ''),
            (string) $block['offset'],
            (string) $block['length'],
        ]));
        if (isset(self::$decodedBlockCache[$cacheKey])) {
            return self::$decodedBlockCache[$cacheKey];
        }

        $handle = @fopen($metadata['runtime_path'], 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not open indexed lemma runtime payload.');
        }

        try {
            if (fseek($handle, $block['offset']) !== 0) {
                throw new RuntimeException('Could not seek within indexed lemma runtime payload.');
            }
            $encoded = self::read_exact($handle, $block['length']);
        } finally {
            fclose($handle);
        }

        $decoded = self::decode_gzip($encoded);
        if (!is_string($decoded) || strlen($decoded) > self::MAX_BLOCK_DECODED_BYTES) {
            throw new RuntimeException('Could not decode lemma lookup index block.');
        }
        WP_FTS_LemmaPackLimits::assert_runtime_buffer_lines($decoded);

        self::cache_decoded_block($cacheKey, $decoded);

        return $decoded;
    }

    /**
     * Locate the first row whose surface is greater than or equal to a term.
     *
     * @param int $linesRead Number of rows inspected by the binary search.
     */
    private static function first_surface_offset(string $data, string $term, int &$linesRead): ?int
    {
        $low = 0;
        $high = strlen($data);
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            $line = self::decoded_line_at_or_after($data, $mid);
            if ($line === null) {
                $high = $mid;
                continue;
            }
            $linesRead++;

            $pair = self::parse_pair($line['line']);
            if ($pair === null) {
                throw new RuntimeException('Lemma lookup index block contains an invalid TSV row.');
            }
            if (strcmp($pair['surface'], $term) < 0) {
                $next = $line['end'] + 1;
                if ($next <= $low) {
                    break;
                }
                $low = $next;
                continue;
            }

            $high = $mid;
        }

        $line = self::decoded_line_at_or_after($data, $low);
        while ($line !== null) {
            $pair = self::parse_pair($line['line']);
            if ($pair === null) {
                throw new RuntimeException('Lemma lookup index block contains an invalid TSV row.');
            }
            if (strcmp($pair['surface'], $term) >= 0) {
                return $line['start'];
            }

            $next = $line['end'] + 1;
            if ($next <= $low) {
                return null;
            }
            $low = $next;
            $line = self::decoded_line_at_or_after($data, $low);
        }

        return null;
    }

    /** @return array{start:int,end:int,line:string}|null */
    private static function decoded_line_at_or_after(string $data, int $offset): ?array
    {
        $length = strlen($data);
        $offset = max(0, min($offset, $length));
        if ($offset >= $length) {
            return null;
        }

        if ($offset === 0 || $data[$offset - 1] === "\n") {
            $start = $offset;
        } else {
            $newline = strpos($data, "\n", $offset);
            if ($newline === false) {
                return null;
            }
            $start = $newline + 1;
        }
        if ($start >= $length) {
            return null;
        }

        $end = strpos($data, "\n", $start);
        if ($end === false) {
            $end = $length;
        }
        WP_FTS_LemmaPackLimits::assert_runtime_line_bytes($end - $start);

        return [
            'start' => $start,
            'end' => $end,
            'line' => rtrim(substr($data, $start, $end - $start), "\r"),
        ];
    }

    /** Keep only a few decoded blocks alive for adjacent dictionary lookups. */
    private static function cache_decoded_block(string $cacheKey, string $decoded): void
    {
        $bytes = strlen($decoded);
        if ($bytes > self::MAX_DECODED_BLOCK_CACHE_BYTES || isset(self::$decodedBlockCache[$cacheKey])) {
            return;
        }

        self::$decodedBlockCache[$cacheKey] = $decoded;
        self::$decodedBlockCacheBytes += $bytes;
        while (self::$decodedBlockCacheBytes > self::MAX_DECODED_BLOCK_CACHE_BYTES) {
            $oldest = array_key_first(self::$decodedBlockCache);
            if (!is_string($oldest) || !isset(self::$decodedBlockCache[$oldest])) {
                break;
            }
            self::$decodedBlockCacheBytes -= strlen(self::$decodedBlockCache[$oldest]);
            unset(self::$decodedBlockCache[$oldest]);
        }
    }

    private static function decode_gzip(string $encoded): string|false
    {
        set_error_handler(static fn(): bool => true);
        try {
            return gzdecode($encoded, self::MAX_BLOCK_DECODED_BYTES + 1);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return array{surface:string,lemma:string}|null
     */
    private static function parse_pair(string $line): ?array
    {
        $separator = strpos($line, "\t");
        if ($separator === false || $separator === 0 || $separator === strlen($line) - 1 || strpos($line, "\t", $separator + 1) !== false) {
            return null;
        }

        return [
            'surface' => substr($line, 0, $separator),
            'lemma' => substr($line, $separator + 1),
        ];
    }

    /**
     * @return resource
     */
    private static function open_runtime_file(string $path, ?string $compression): mixed
    {
        if ($compression === 'gzip') {
            if (!function_exists('gzopen') || !function_exists('gzgets') || !function_exists('gzclose')) {
                throw new RuntimeException('Compressed lemma runtime files require PHP zlib support.');
            }
            $handle = @gzopen($path, 'rb');
        } else {
            $handle = fopen($path, 'rb');
        }
        if (!is_resource($handle)) {
            throw new RuntimeException("Could not read lemma runtime file: {$path}");
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private static function close_runtime_file(mixed $handle, ?string $compression): void
    {
        if ($compression === 'gzip') {
            gzclose($handle);
            return;
        }
        fclose($handle);
    }

    /**
     * @param resource $handle
     */
    private static function read_exact(mixed $handle, int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes && !feof($handle)) {
            $chunk = @fread($handle, $bytes - strlen($data));
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        if (strlen($data) !== $bytes) {
            throw new RuntimeException('Lemma lookup index ended before the declared payload length.');
        }

        return $data;
    }

    /**
     * @param resource $handle
     */
    private static function write_all(mixed $handle, string $data): bool
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($handle, substr($data, $offset));
            if (!is_int($written) || $written < 1) {
                return false;
            }
            $offset += $written;
        }

        return true;
    }

    private static function canonical_file(string $path, string $label): string
    {
        WP_FTS_Analyzer_Config_Limits::assert_path($path, "Lemma {$label} path");
        $real = realpath($path);
        if (!is_string($real) || !is_file($real)) {
            throw new RuntimeException("Lemma {$label} does not exist: {$path}");
        }

        return $real;
    }
}
