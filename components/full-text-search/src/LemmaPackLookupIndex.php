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
    public const FORMAT = 'wp-fts-lemma-block-index-v2';
    public const DEFAULT_BLOCK_ROWS = 2048;
    public const MAX_BLOCK_DECODED_BYTES = 16384;
    public const MAX_HEADER_BYTES = 65536;

    private const MAGIC = "WPFTSLI2";
    private const HEADER_PREFIX_BYTES = 12;
    private const MAX_BLOCK_ENCODED_BYTES = 32768;
    private const MAX_DECODED_BLOCK_CACHE_BYTES = 4194304;

    /** @var array<string,string> */
    private static array $decodedBlockCache = [];
    private static int $decodedBlockCacheBytes = 0;
    private static int $runtimeFileOpens = 0;
    private static int $runtimePayloadReads = 0;
    private static int $compressedPayloadBytesRead = 0;
    private static int $decodedPayloadBytesLoaded = 0;
    private static int $decodedBlockCacheHits = 0;
    private static int $lookupHeaderOpens = 0;

    /**
     * Report process-local indexed payload I/O for acceptance diagnostics.
     * Metadata-header reads and complete-file integrity hashes are reported by
     * their respective validator diagnostics instead.
     *
     * @return array{runtime_file_opens:int,runtime_payload_reads:int,compressed_payload_bytes_read:int,decoded_payload_bytes_loaded:int,decoded_block_cache_hits:int}
     */
    public static function io_diagnostics(): array
    {
        return [
            'runtime_file_opens' => self::$runtimeFileOpens,
            'runtime_payload_reads' => self::$runtimePayloadReads,
            'compressed_payload_bytes_read' => self::$compressedPayloadBytesRead,
            'decoded_payload_bytes_loaded' => self::$decodedPayloadBytesLoaded,
            'decoded_block_cache_hits' => self::$decodedBlockCacheHits,
        ];
    }

    /** @return array{lookup_header_opens:int} */
    public static function metadata_diagnostics(): array
    {
        return ['lookup_header_opens' => self::$lookupHeaderOpens];
    }

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
        $outputDirectory = dirname($outputPath);
        if (!is_dir($outputDirectory)) {
            throw new RuntimeException("Lemma lookup index output directory does not exist: {$outputDirectory}");
        }
        $canonicalOutputDirectory = realpath($outputDirectory);
        if (!is_string($canonicalOutputDirectory)) {
            throw new RuntimeException("Could not resolve lemma lookup index output directory: {$outputDirectory}");
        }
        $outputPath = $canonicalOutputDirectory . DIRECTORY_SEPARATOR . basename($outputPath);
        $realOutputPath = realpath($outputPath);
        $runtimeStat = @stat($runtimePath);
        $outputStat = (file_exists($outputPath) || is_link($outputPath)) ? @stat($outputPath) : false;
        if (
            $outputPath === $runtimePath
            || (is_string($realOutputPath) && $realOutputPath === $runtimePath)
            || (
                is_array($runtimeStat)
                && is_array($outputStat)
                && ($runtimeStat['dev'] ?? null) === ($outputStat['dev'] ?? null)
                && ($runtimeStat['ino'] ?? null) === ($outputStat['ino'] ?? null)
            )
        ) {
            throw new InvalidArgumentException('Lemma lookup index output must differ from its runtime shard.');
        }
        $sourceSnapshotPath = self::snapshot_runtime_file($runtimePath, $runtimeSha256);
        $outputDirectory = $canonicalOutputDirectory;

        try {
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
                $runtimeHandle = self::open_runtime_file($sourceSnapshotPath, $compression);
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
            $blockBytes = 0;
            $blockFirstSurface = null;
            $blockLastSurface = null;
            $surfaceLines = [];
            $surfaceBytes = 0;
            $surface = null;
            $rows = 0;
            $rowsDigest = hash_init('sha256');
            $previousKey = null;

            $flushBlock = static function () use (
                &$blocks,
                &$blockLines,
                &$blockBytes,
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
                if (strlen($encoded) > self::MAX_BLOCK_ENCODED_BYTES) {
                    throw new RuntimeException('Lemma lookup index block exceeds its encoded byte limit.');
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
                    'decoded_bytes' => strlen($plain),
                    'rows' => count($blockLines),
                ];
                $blockLines = [];
                $blockBytes = 0;
                $blockFirstSurface = null;
                $blockLastSurface = null;
            };

            $flushSurface = static function () use (
                &$surfaceLines,
                &$surfaceBytes,
                &$surface,
                &$blockLines,
                &$blockBytes,
                &$blockFirstSurface,
                &$blockLastSurface,
                $blockRows,
                $flushBlock
            ): void {
                if ($surfaceLines === []) {
                    return;
                }
                if ($surfaceBytes > self::MAX_BLOCK_DECODED_BYTES) {
                    throw new RuntimeException('One lemma surface exceeds the decoded lookup block limit.');
                }
                if ($blockLines !== [] && (
                    count($blockLines) >= $blockRows
                    || $blockBytes + $surfaceBytes > self::MAX_BLOCK_DECODED_BYTES
                )) {
                    $flushBlock();
                }

                foreach ($surfaceLines as $surfaceLine) {
                    $blockLines[] = $surfaceLine;
                }
                $blockBytes += $surfaceBytes;
                $blockFirstSurface ??= $surface;
                $blockLastSurface = $surface;
                $surfaceLines = [];
                $surfaceBytes = 0;
                $surface = null;
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
                        foreach (['surface', 'lemma'] as $token) {
                            if (strlen($pair[$token]) > WP_FTS_LemmaPackLimits::MAX_RUNTIME_TOKEN_BYTES) {
                                throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                                    'runtime_token_bytes',
                                    'Lemma runtime tokens may contain at most 255 bytes.'
                                );
                            }
                        }
                        $key = $pair['surface'] . "\t" . $pair['lemma'];
                        if ($previousKey !== null && strcmp($previousKey, $key) >= 0) {
                            throw new RuntimeException("Runtime rows in {$runtimePath} must be unique and sorted.");
                        }
                        if ($surface !== null && $surface !== $pair['surface']) {
                            $flushSurface();
                        }

                        $normalizedLine = $key . "\n";
                        $surface ??= $pair['surface'];
                        $surfaceLines[] = $normalizedLine;
                        WP_FTS_LemmaPackLimits::assert_surface_lemma_count(
                            $pair['surface'],
                            count($surfaceLines)
                        );
                        $surfaceBytes += strlen($normalizedLine);
                        $previousKey = $key;
                        $rows++;
                        hash_update($rowsDigest, $normalizedLine);
                    }
                    $flushSurface();
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

            try {
                $indexedRuntimeSha256 = WP_FTS_LemmaPackLimits::hash_file_bounded(
                    $stagedRuntimePath,
                    WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK,
                    'runtime_lookup_bytes',
                    'Indexed lemma runtime exceeds the 16 MiB physical pack limit.'
                )['sha256'];
            } catch (Throwable $error) {
                @unlink($stagedRuntimePath);
                @unlink($stagedPath);
                throw $error;
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
            self::publish_built_artifacts($stagedRuntimePath, $runtimePath, $stagedPath, $outputPath);

            $sha256 = WP_FTS_LemmaPackLimits::hash_file_bounded(
                $outputPath,
                WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK,
                'runtime_lookup_bytes',
                'Lemma lookup index exceeds the 16 MiB physical pack limit.'
            )['sha256'];

            return [
                'format' => self::FORMAT,
                'sha256' => $sha256,
                'runtime_sha256' => $indexedRuntimeSha256,
                'blocks' => count($blocks),
                'rows' => $rows,
                'rows_sha256' => (string) $header['rows_sha256'],
            ];
        } finally {
            @unlink($sourceSnapshotPath);
        }
    }

    /**
     * Validate the bounded header and indexed runtime layout without inflating
     * gzip members.
     *
     * @return array{format:string,runtime_sha256:string,rows:int,rows_sha256:string,block_rows:int,blocks:array<int,array{first_surface:string,last_surface:string,offset:int,length:int,decoded_bytes:int,rows:int}>,path:string,runtime_path:string,content_sha256:string}
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
        self::$lookupHeaderOpens++;

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
        $decodedBytes = 0;
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
                || !is_int($block['decoded_bytes'] ?? null)
                || !is_int($block['rows'] ?? null)
                || $block['offset'] !== $nextOffset
                || $block['length'] < 1
                || $block['length'] > self::MAX_BLOCK_ENCODED_BYTES
                || $block['decoded_bytes'] < 1
                || $block['decoded_bytes'] > self::MAX_BLOCK_DECODED_BYTES
                || $block['rows'] < 1
                || strlen($block['first_surface']) > WP_FTS_LemmaPackLimits::MAX_RUNTIME_TOKEN_BYTES
                || strlen($block['last_surface']) > WP_FTS_LemmaPackLimits::MAX_RUNTIME_TOKEN_BYTES
                || strcmp($block['first_surface'], $block['last_surface']) > 0
                || ($previousLast !== null && strcmp($previousLast, $block['first_surface']) >= 0)
            ) {
                throw new RuntimeException('Lemma lookup index block entry is invalid.');
            }

            $blocks[] = $block;
            $nextOffset += $block['length'];
            $decodedBytes += $block['decoded_bytes'];
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
            'decoded_bytes' => $decodedBytes,
            'path' => $path,
            'runtime_path' => $runtimePath,
            'content_sha256' => hash('sha256', $prefix . $headerJson),
        ];
    }

    /**
     * Inflate every indexed gzip member and attest its sorted row stream.
     *
     * @param array{rows:int,rows_sha256:string,blocks:array<int,array{first_surface:string,last_surface:string,offset:int,length:int,decoded_bytes:int,rows:int}>,path:string,runtime_path:string} $metadata
     */
    public static function validate_content(array $metadata, string $expectedRowsSha256): void
    {
        $digest = hash_init('sha256');
        $previousKey = null;
        $rows = 0;
        $runtimeHandle = self::open_indexed_runtime_handle($metadata);
        try {
            foreach ($metadata['blocks'] as $block) {
                $decoded = self::read_decoded_block($metadata, $block, $runtimeHandle);
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
        } finally {
            fclose($runtimeHandle);
        }

        $actualDigest = hash_final($digest);
        if ($rows !== $metadata['rows'] || !hash_equals(strtolower($expectedRowsSha256), strtolower($actualDigest))) {
            throw new RuntimeException('Lemma lookup index rows do not match the runtime shard.');
        }
    }

    /**
     * Lookup one surface by inflating only its indexed block.
     *
     * @param array{blocks:array<int,array{first_surface:string,last_surface:string,offset:int,length:int,decoded_bytes:int,rows:int}>,path:string,runtime_path:string} $metadata
     * @return array{lemmas:array<string,bool>,lines_read:int,compressed_bytes:int,decoded_bytes:int}
     */
    public static function lookup(array $metadata, string $term): array
    {
        $result = self::lookup_many($metadata, [$term]);

        return [
            'lemmas' => $result['lemmas_by_term'][$term] ?? [],
            'lines_read' => $result['lines_read'],
            'compressed_bytes' => $result['compressed_bytes'],
            'decoded_bytes' => $result['decoded_bytes'],
        ];
    }

    /**
     * Lookup many surfaces after grouping them by their indexed runtime block.
     *
     * A block is decoded at most once for this call, regardless of input order.
     * This prevents an adversarial round-robin term order from thrashing the
     * bounded process cache and turning one document into thousands of payload
     * reads. Duplicate terms are collapsed before block selection.
     *
     * @param array{blocks:array<int,array{first_surface:string,last_surface:string,offset:int,length:int,decoded_bytes:int,rows:int}>,path:string,runtime_path:string} $metadata
     * @param string[] $terms
     * @param null|callable(string):bool $acceptLemma
     * @param null|callable(string):bool $rejectSurface
     * @param array{runtime:resource,lookup:resource,block_sha256:string[]}|null $attestation
     * @return array{lemmas_by_term:array<string,array<string,bool>>,lemma_counts_by_term:array<string,int>,has_exact_by_term:array<string,bool>,lines_read:int,compressed_bytes:int,decoded_bytes:int,blocks_loaded:int}
     */
    public static function lookup_many(
        array $metadata,
        array $terms,
        ?callable $acceptLemma = null,
        int $maxLemmaResults = WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES,
        ?callable $rejectSurface = null,
        ?array $attestation = null
    ): array
    {
        if (count($terms) > WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES || $maxLemmaResults < 0) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'occurrences',
                'Lemma lookup input exceeds the 20,000-occurrence limit.'
            );
        }
        $lemmasByTerm = [];
        $lemmaCountsByTerm = [];
        $hasExactByTerm = [];
        $termsByBlock = [];
        foreach ($terms as $term) {
            if (!is_string($term)) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'occurrence_shape',
                    'Lemma lookup terms must be strings.'
                );
            }
            WP_FTS_Analysis_Limits::assert_lexical_run_bytes(strlen($term));
            if (strlen($term) > WP_FTS_LemmaPackLimits::MAX_RUNTIME_TOKEN_BYTES) {
                throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                    'runtime_token_bytes',
                    'Lemma runtime tokens may contain at most 255 bytes.'
                );
            }
            if (isset($lemmasByTerm[$term])) {
                continue;
            }
            $lemmasByTerm[$term] = [];
            $lemmaCountsByTerm[$term] = 0;
            $hasExactByTerm[$term] = false;
            if (count($lemmasByTerm) > WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'distinct_surfaces',
                    'Lemma lookup exceeds the 4,096-distinct-surface limit.'
                );
            }
            $blockIndex = self::candidate_block_index($metadata['blocks'], $term);
            if ($blockIndex !== null) {
                $termsByBlock[$blockIndex][] = $term;
            }
        }

        $linesRead = 0;
        $compressedBytes = 0;
        $decodedBytes = 0;
        $acceptedResults = 0;
        ksort($termsByBlock, SORT_NUMERIC);
        $runtimeHandle = null;
        $closeRuntimeHandle = false;
        $blockSha256 = null;
        if ($termsByBlock !== []) {
            if ($attestation === null) {
                $runtimeHandle = self::open_indexed_runtime_handle($metadata);
                $closeRuntimeHandle = true;
            } else {
                $runtimeHandle = $attestation['runtime'] ?? null;
                $blockSha256 = $attestation['block_sha256'] ?? null;
                if (
                    !is_resource($runtimeHandle)
                    || !is_array($blockSha256)
                    || !array_is_list($blockSha256)
                    || count($blockSha256) !== count($metadata['blocks'])
                ) {
                    throw new InvalidArgumentException('Indexed runtime lookup received an invalid attestation.');
                }
                foreach ($blockSha256 as $digest) {
                    if (
                        !is_string($digest)
                        || strlen($digest) !== 64
                        || strspn($digest, '0123456789abcdefABCDEF') !== 64
                    ) {
                        throw new InvalidArgumentException('Indexed runtime lookup received an invalid block attestation.');
                    }
                }
                self::$runtimeFileOpens++;
            }
        }
        try {
            foreach ($termsByBlock as $blockIndex => $blockTerms) {
                $block = $metadata['blocks'][$blockIndex];
                $expectedBlockSha256 = null;
                if ($blockSha256 !== null) {
                    $expectedBlockSha256 = $blockSha256[$blockIndex] ?? null;
                    if (!is_string($expectedBlockSha256)) {
                        throw new InvalidArgumentException('Indexed runtime lookup is missing a block attestation.');
                    }
                }
                $decoded = self::read_decoded_block(
                    $metadata,
                    $block,
                    $runtimeHandle,
                    $expectedBlockSha256
                );
                $compressedBytes += $block['length'];
                $decodedBytes += strlen($decoded);
                foreach ($blockTerms as $term) {
                    $lookup = self::lookup_decoded_block(
                        $decoded,
                        $term,
                        $linesRead,
                        $acceptLemma,
                        $rejectSurface
                    );
                    $lemmasByTerm[$term] = $lookup['lemmas'];
                    $lemmaCountsByTerm[$term] = $lookup['lemma_count'];
                    $hasExactByTerm[$term] = $lookup['has_exact'];
                    $acceptedResults += count($lookup['lemmas']);
                    if ($acceptedResults > $maxLemmaResults) {
                        throw new WP_FTS_Analysis_Limit_Exceeded(
                            'occurrences',
                            "FTS analysis exceeds its {$maxLemmaResults}-occurrence limit."
                        );
                    }
                }
            }
        } finally {
            if ($closeRuntimeHandle && is_resource($runtimeHandle)) {
                fclose($runtimeHandle);
            }
        }

        return [
            'lemmas_by_term' => $lemmasByTerm,
            'lemma_counts_by_term' => $lemmaCountsByTerm,
            'has_exact_by_term' => $hasExactByTerm,
            'lines_read' => $linesRead,
            'compressed_bytes' => $compressedBytes,
            'decoded_bytes' => $decodedBytes,
            'blocks_loaded' => count($termsByBlock),
        ];
    }

    /**
     * Binary-select the one indexed runtime block whose range can contain a term.
     *
     * @param array<int,array{first_surface:string,last_surface:string}> $blocks
     */
    private static function candidate_block_index(array $blocks, string $term): ?int
    {
        $low = 0;
        $high = count($blocks) - 1;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $block = $blocks[$mid];
            if (strcmp($term, $block['first_surface']) < 0) {
                $high = $mid - 1;
                continue;
            }
            if (strcmp($term, $block['last_surface']) > 0) {
                $low = $mid + 1;
                continue;
            }

            return $mid;
        }

        return null;
    }

    /**
     * Parse one already-bounded decoded block for one requested surface.
     *
     * @param null|callable(string):bool $acceptLemma
     * @param null|callable(string):bool $rejectSurface
     * @return array{lemmas:array<string,bool>,lemma_count:int,has_exact:bool}
     */
    private static function lookup_decoded_block(
        string $decoded,
        string $term,
        int &$linesRead,
        ?callable $acceptLemma = null,
        ?callable $rejectSurface = null
    ): array
    {
        if ($decoded === '') {
            return ['lemmas' => [], 'lemma_count' => 0, 'has_exact' => false];
        }

        $lemmas = [];
        $lemmaCount = 0;
        $hasExact = false;
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
            $lemmaCount++;
            WP_FTS_LemmaPackLimits::assert_surface_lemma_count($term, $lemmaCount);
            $hasExact = $hasExact || $pair['lemma'] === $term;
            if ($acceptLemma === null || $acceptLemma($pair['lemma'], $term) === true) {
                $lemmas[$pair['lemma']] = true;
            }
        }

        if ($rejectSurface !== null && $rejectSurface($term, $lemmaCount) === true) {
            $lemmas = [];
        }

        return [
            'lemmas' => $lemmas,
            'lemma_count' => $lemmaCount,
            'has_exact' => $hasExact,
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
     * Read, decode, verify, and cache exactly one declared runtime block.
     *
     * @param array{runtime_path:string} $metadata
     * @param array{offset:int,length:int} $block
     */
    private static function read_decoded_block(
        array $metadata,
        array $block,
        mixed $runtimeHandle = null,
        ?string $expectedBlockSha256 = null
    ): string
    {
        if (!function_exists('gzdecode')) {
            throw new RuntimeException('Lemma lookup index loading requires PHP zlib support.');
        }
        if ($block['length'] < 1 || $block['length'] > self::MAX_BLOCK_ENCODED_BYTES) {
            throw new RuntimeException('Lemma lookup index block exceeds its encoded byte limit.');
        }
        if (
            $expectedBlockSha256 !== null
            && (
                strlen($expectedBlockSha256) !== 64
                || strspn($expectedBlockSha256, '0123456789abcdefABCDEF') !== 64
            )
        ) {
            throw new InvalidArgumentException('Indexed runtime block attestation is invalid.');
        }

        $cacheKey = null;
        if ($expectedBlockSha256 !== null) {
            $cacheKey = hash('sha256', implode("\0", [
                (string) $metadata['runtime_path'],
                (string) ($metadata['runtime_sha256'] ?? ''),
                (string) $block['offset'],
                (string) $block['length'],
                strtolower($expectedBlockSha256),
            ]));
            if (isset(self::$decodedBlockCache[$cacheKey])) {
                self::$decodedBlockCacheHits++;
                return self::$decodedBlockCache[$cacheKey];
            }
        }

        if (!is_resource($runtimeHandle)) {
            throw new LogicException('Indexed runtime lookup requires one open shard handle.');
        }
        if (fseek($runtimeHandle, $block['offset']) !== 0) {
            throw new RuntimeException('Could not seek within indexed lemma runtime payload.');
        }
        $encoded = self::read_exact($runtimeHandle, $block['length']);
        self::$runtimePayloadReads++;
        self::$compressedPayloadBytesRead += $block['length'];
        if (
            $expectedBlockSha256 !== null
            && !hash_equals(strtolower($expectedBlockSha256), hash('sha256', $encoded))
        ) {
            throw new RuntimeException('Indexed lemma runtime block digest mismatch.');
        }

        $decoded = self::decode_gzip($encoded);
        if (
            !is_string($decoded)
            || strlen($decoded) > self::MAX_BLOCK_DECODED_BYTES
            || strlen($decoded) !== (int) ($block['decoded_bytes'] ?? 0)
        ) {
            throw new RuntimeException('Could not decode lemma lookup index block.');
        }
        self::$decodedPayloadBytesLoaded += strlen($decoded);
        WP_FTS_LemmaPackLimits::assert_runtime_buffer_lines($decoded);

        if (is_string($cacheKey)) {
            self::cache_decoded_block($cacheKey, $decoded);
        }

        return $decoded;
    }

    /**
     * Open one indexed runtime file for a monotonic multi-block lookup pass.
     *
     * @param array{runtime_path:string} $metadata
     * @return resource
     */
    private static function open_indexed_runtime_handle(array $metadata): mixed
    {
        $handle = @fopen($metadata['runtime_path'], 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not open indexed lemma runtime payload.');
        }
        self::$runtimeFileOpens++;

        return $handle;
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

    /**
     * Copy and hash one bounded runtime generation for deterministic parsing.
     * The caller owns the returned temporary path and must always unlink it.
     */
    private static function snapshot_runtime_file(string $runtimePath, string $expectedSha256): string
    {
        $snapshotPath = tempnam(dirname($runtimePath), '.wp-fts-lemma-source-');
        if (!is_string($snapshotPath)) {
            throw new RuntimeException('Could not create a bounded runtime snapshot.');
        }

        $source = @fopen($runtimePath, 'rb');
        $snapshot = @fopen($snapshotPath, 'w+b');
        if (!is_resource($source) || !is_resource($snapshot)) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($snapshot)) {
                fclose($snapshot);
            }
            @unlink($snapshotPath);
            throw new RuntimeException('Could not open the runtime snapshot streams.');
        }

        try {
            $result = WP_FTS_LemmaPackLimits::hash_open_file_bounded(
                $source,
                WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK,
                'runtime_lookup_bytes',
                'Lemma lookup index source exceeds the 16 MiB physical pack limit.',
                $snapshot
            );
            if (!fflush($snapshot)) {
                throw new RuntimeException('Could not flush the bounded runtime snapshot.');
            }
        } catch (Throwable $error) {
            fclose($source);
            fclose($snapshot);
            @unlink($snapshotPath);
            throw $error;
        }
        fclose($source);
        fclose($snapshot);

        if (!hash_equals(strtolower($expectedSha256), strtolower($result['sha256']))) {
            @unlink($snapshotPath);
            throw new RuntimeException('Lemma lookup index source digest does not match the runtime shard.');
        }

        return $snapshotPath;
    }

    /** Publish the repacked runtime and sidecar as one rollback unit. */
    private static function publish_built_artifacts(
        string $stagedRuntimePath,
        string $runtimePath,
        string $stagedLookupPath,
        string $lookupPath
    ): void {
        $runtimeBackup = null;
        $lookupBackup = null;
        $runtimePublished = false;
        $lookupPublished = false;
        try {
            $runtimeBackup = self::reserve_backup_path(dirname($runtimePath));
            if (!rename($runtimePath, $runtimeBackup)) {
                throw new RuntimeException("Could not back up indexed runtime shard: {$runtimePath}");
            }
            if (!rename($stagedRuntimePath, $runtimePath)) {
                throw new RuntimeException("Could not publish indexed runtime shard: {$runtimePath}");
            }
            $runtimePublished = true;

            if (file_exists($lookupPath) || is_link($lookupPath)) {
                if (!is_file($lookupPath)) {
                    throw new RuntimeException("Lemma lookup index destination is not a regular file: {$lookupPath}");
                }
                $lookupBackup = self::reserve_backup_path(dirname($lookupPath));
                if (!rename($lookupPath, $lookupBackup)) {
                    throw new RuntimeException("Could not back up lemma lookup index: {$lookupPath}");
                }
            }
            if (!rename($stagedLookupPath, $lookupPath)) {
                throw new RuntimeException("Could not publish lemma lookup index: {$lookupPath}");
            }
            $lookupPublished = true;
        } catch (Throwable $error) {
            $rollbackErrors = [];
            if ($lookupPublished && is_file($lookupPath) && !unlink($lookupPath)) {
                $rollbackErrors[] = "could not remove {$lookupPath}";
            }
            if (is_string($lookupBackup) && is_file($lookupBackup) && !rename($lookupBackup, $lookupPath)) {
                $rollbackErrors[] = "could not restore {$lookupPath}";
            }
            if ($runtimePublished && is_file($runtimePath) && !unlink($runtimePath)) {
                $rollbackErrors[] = "could not remove {$runtimePath}";
            }
            if (is_string($runtimeBackup) && is_file($runtimeBackup) && !rename($runtimeBackup, $runtimePath)) {
                $rollbackErrors[] = "could not restore {$runtimePath}";
            }
            @unlink($stagedRuntimePath);
            @unlink($stagedLookupPath);
            if ($rollbackErrors !== []) {
                throw new RuntimeException(
                    'Lemma lookup index publication failed and rollback was incomplete: ' . implode('; ', $rollbackErrors),
                    0,
                    $error
                );
            }
            throw $error;
        }

        if (is_string($runtimeBackup)) {
            @unlink($runtimeBackup);
        }
        if (is_string($lookupBackup)) {
            @unlink($lookupBackup);
        }
    }

    /** Reserve an unused same-directory path for atomic-publication backup. */
    private static function reserve_backup_path(string $directory): string
    {
        $path = tempnam($directory, '.wp-fts-lemma-backup-');
        if (!is_string($path)) {
            throw new RuntimeException('Could not reserve a lemma lookup publication backup.');
        }
        if (!unlink($path)) {
            throw new RuntimeException('Could not prepare a lemma lookup publication backup.');
        }

        return $path;
    }

    /** Resolve one local build input after applying the shared path ceiling. */
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
