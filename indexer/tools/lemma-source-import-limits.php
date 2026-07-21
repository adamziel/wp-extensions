<?php
declare(strict_types=1);

/** Memory bounds shared by the source-backed lemma-pack importers. */
final class WP_FTS_LemmaSourceImportLimits
{
    public const MAX_SOURCE_LINE_BYTES = 65536;
    public const MAX_CHUNK_LEXICAL_BYTES = 8388608;
    public const MAX_CHUNK_ROWS = 200000;
    public const MAX_SOURCE_FILES = 256;
    public const MAX_SOURCE_PATH_BYTES = 8192;
    public const MAX_SOURCE_DEPTH = 8;
    public const MAX_SOURCE_ENTRIES = 4096;
    public const MAX_STAGED_ROWS = 1250000;
    public const MAX_STAGED_TSV_BYTES = 67108864;
    public const MAX_SOURCE_PHYSICAL_BYTES = 67108864;
    public const MAX_SOURCE_DECODED_BYTES = 536870912;
    public const MAX_SOURCE_LINES = 8000000;

    /**
     * Reject source artifacts before a digest or decoder reads their bytes.
     *
     * @param string[] $paths
     * @return array{bytes:int,file_bytes:array<string,int>,file_evidence:array<string,array{bytes:int,device:int,inode:int,mtime:int,ctime:int}>}
     */
    public static function source_physical_evidence(array $paths, string $sourceKind): array
    {
        $total = 0;
        $fileBytes = [];
        $fileEvidence = [];
        foreach ($paths as $path) {
            clearstatcache(true, $path);
            $stat = @stat($path);
            if (!is_array($stat) || !is_int($stat['size'] ?? null) || $stat['size'] < 0) {
                throw new RuntimeException("Could not measure {$sourceKind} source artifact: {$path}");
            }
            $bytes = $stat['size'];
            $total += $bytes;
            if ($total > self::MAX_SOURCE_PHYSICAL_BYTES) {
                throw new RuntimeException("{$sourceKind} source artifacts exceed the aggregate 64 MiB physical limit.");
            }
            $fileBytes[$path] = $bytes;
            $fileEvidence[$path] = self::stat_evidence($stat);
        }

        return ['bytes' => $total, 'file_bytes' => $fileBytes, 'file_evidence' => $fileEvidence];
    }

    /**
     * Hash no more than the preflighted artifact generation and reject a
     * replacement, growth, truncation, or in-place mutation around parsing.
     *
     * @param array{bytes:int,device:int,inode:int,mtime:int,ctime:int} $physicalEvidence
     * @return array{sha256:string,bytes:int,device:int,inode:int,mtime:int,ctime:int}
     */
    public static function hash_source_artifact(
        string $path,
        array $physicalEvidence,
        string $sourceKind
    ): array {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Could not open {$sourceKind} source artifact for hashing: {$path}");
        }
        $digest = hash_init('sha256');
        $bytes = 0;
        try {
            $before = fstat($handle);
            if (!is_array($before) || !self::same_stat_generation($physicalEvidence, self::stat_evidence($before))) {
                throw new RuntimeException("{$sourceKind} source artifact changed after physical preflight: {$path}");
            }
            while (!feof($handle)) {
                $remaining = self::MAX_SOURCE_PHYSICAL_BYTES - $bytes;
                $chunk = fread($handle, min(1048576, max(1, $remaining + 1)));
                if ($chunk === false) {
                    throw new RuntimeException("Could not hash {$sourceKind} source artifact: {$path}");
                }
                if ($chunk === '') {
                    if (!feof($handle)) {
                        throw new RuntimeException("Could not make progress hashing {$sourceKind} source artifact: {$path}");
                    }
                    break;
                }
                $bytes += strlen($chunk);
                if ($bytes > self::MAX_SOURCE_PHYSICAL_BYTES || $bytes > $physicalEvidence['bytes']) {
                    throw new RuntimeException("{$sourceKind} source artifact changed size after physical preflight: {$path}");
                }
                hash_update($digest, $chunk);
            }
            $after = fstat($handle);
            if (!is_array($after) || !self::same_stat_generation($physicalEvidence, self::stat_evidence($after))) {
                throw new RuntimeException("{$sourceKind} source artifact changed while it was hashed: {$path}");
            }
        } finally {
            fclose($handle);
        }
        if ($bytes !== $physicalEvidence['bytes']) {
            throw new RuntimeException("{$sourceKind} source artifact changed size after physical preflight: {$path}");
        }
        clearstatcache(true, $path);
        $pathStat = @stat($path);
        if (!is_array($pathStat) || !self::same_stat_generation($physicalEvidence, self::stat_evidence($pathStat))) {
            throw new RuntimeException("{$sourceKind} source artifact path was replaced while it was hashed: {$path}");
        }

        return ['sha256' => hash_final($digest)] + $physicalEvidence;
    }

    /**
     * Hash and copy one preflighted source generation into a private snapshot.
     * Importers parse the snapshot so a swap-and-restore race cannot make the
     * generated rows disagree with the published source digest.
     *
     * @param array{bytes:int,device:int,inode:int,mtime:int,ctime:int} $physicalEvidence
     * @return array{sha256:string,bytes:int,device:int,inode:int,mtime:int,ctime:int}
     */
    public static function snapshot_source_artifact(
        string $path,
        string $snapshotPath,
        array $physicalEvidence,
        string $sourceKind
    ): array {
        $partialPath = $snapshotPath . '.partial';
        if (file_exists($snapshotPath) || is_link($snapshotPath) || file_exists($partialPath) || is_link($partialPath)) {
            throw new RuntimeException("Refusing to replace an existing {$sourceKind} source snapshot.");
        }

        $source = @fopen($path, 'rb');
        if (!is_resource($source)) {
            throw new RuntimeException("Could not open {$sourceKind} source artifact for snapshotting: {$path}");
        }
        $snapshot = @fopen($partialPath, 'xb');
        if (!is_resource($snapshot)) {
            fclose($source);
            throw new RuntimeException("Could not create {$sourceKind} source snapshot: {$snapshotPath}");
        }
        $complete = false;
        try {
            if (!@chmod($partialPath, 0600)) {
                throw new RuntimeException("Could not restrict {$sourceKind} source snapshot permissions.");
            }
            $before = fstat($source);
            if (!is_array($before) || !self::same_stat_generation($physicalEvidence, self::stat_evidence($before))) {
                throw new RuntimeException("{$sourceKind} source artifact changed after physical preflight: {$path}");
            }
            $hashed = WP_FTS_LemmaPackLimits::hash_open_file_bounded(
                $source,
                max(1, $physicalEvidence['bytes']),
                'source_physical_bytes',
                "{$sourceKind} source artifact changed size after physical preflight.",
                $snapshot
            );
            if ($hashed['bytes'] !== $physicalEvidence['bytes']) {
                throw new RuntimeException("{$sourceKind} source artifact changed size after physical preflight: {$path}");
            }
            $after = fstat($source);
            if (!is_array($after) || !self::same_stat_generation($physicalEvidence, self::stat_evidence($after))) {
                throw new RuntimeException("{$sourceKind} source artifact changed while it was snapshotted: {$path}");
            }
            if (!fflush($snapshot)) {
                throw new RuntimeException("Could not flush {$sourceKind} source snapshot: {$snapshotPath}");
            }
            clearstatcache(true, $path);
            $pathStat = @stat($path);
            if (!is_array($pathStat) || !self::same_stat_generation($physicalEvidence, self::stat_evidence($pathStat))) {
                throw new RuntimeException("{$sourceKind} source artifact path was replaced while it was snapshotted: {$path}");
            }

            fclose($snapshot);
            $snapshot = null;
            if (!rename($partialPath, $snapshotPath)) {
                throw new RuntimeException("Could not finalize {$sourceKind} source snapshot: {$snapshotPath}");
            }
            $complete = true;

            return ['sha256' => $hashed['sha256']] + $physicalEvidence;
        } finally {
            fclose($source);
            if (is_resource($snapshot)) {
                fclose($snapshot);
            }
            if (!$complete) {
                @unlink($partialPath);
                @unlink($snapshotPath);
            }
        }
    }

    /**
     * @param array{sha256:string,bytes:int,device:int,inode:int,mtime:int,ctime:int} $hashedEvidence
     * @param array{bytes:int,device:int,inode:int,mtime:int,ctime:int} $physicalEvidence
     */
    public static function assert_source_artifact_unchanged(
        string $path,
        array $hashedEvidence,
        array $physicalEvidence,
        string $sourceKind
    ): void {
        $after = self::hash_source_artifact($path, $physicalEvidence, $sourceKind);
        if ($after['sha256'] !== $hashedEvidence['sha256']) {
            throw new RuntimeException("{$sourceKind} source artifact contents changed while it was parsed: {$path}");
        }
    }

    /** Account one decoded record before the importer retains or parses it. */
    public static function account_decoded_line(
        string $line,
        int &$lines,
        int &$decodedBytes,
        string $sourceKind
    ): void {
        $lines++;
        if ($lines > self::MAX_SOURCE_LINES) {
            throw new RuntimeException("{$sourceKind} source exceeds the 8,000,000-line limit.");
        }
        $decodedBytes += strlen($line);
        if ($decodedBytes > self::MAX_SOURCE_DECODED_BYTES) {
            throw new RuntimeException("{$sourceKind} source exceeds the 512 MiB decoded-byte limit.");
        }
    }

    /** Reject output roots that could alias or recursively contain the source. */
    public static function assert_source_output_separate(
        string $sourcePath,
        string $outputPath,
        string $sourceKind
    ): void {
        if (is_link($outputPath)) {
            throw new RuntimeException("{$sourceKind} output path must not be a symbolic link: {$outputPath}");
        }
        $source = realpath($sourcePath);
        if (!is_string($source)) {
            throw new RuntimeException("Could not resolve {$sourceKind} source path: {$sourcePath}");
        }
        $output = self::canonical_candidate_path($outputPath);
        $separator = DIRECTORY_SEPARATOR;
        if (
            $source === $output
            || str_starts_with($source, rtrim($output, $separator) . $separator)
            || (is_dir($source) && str_starts_with($output, rtrim($source, $separator) . $separator))
        ) {
            throw new RuntimeException("{$sourceKind} source and output paths must not overlap.");
        }
    }

    /**
     * Discover a bounded set of canonical in-root source files without
     * following symlinks or first materializing an unbounded recursive list.
     *
     * @param callable(string):bool $accept
     * @return array{files:string[],path_bytes:int,entries:int,max_depth:int}
     */
    public static function discover_source_files(
        string $sourcePath,
        callable $accept,
        string $sourceKind
    ): array {
        if (is_link($sourcePath)) {
            throw new RuntimeException("{$sourceKind} source paths must not be symbolic links.");
        }
        $canonical = realpath($sourcePath);
        if (!is_string($canonical)) {
            throw new RuntimeException("Could not resolve {$sourceKind} source path: {$sourcePath}");
        }
        if (is_file($canonical)) {
            return ['files' => [$canonical], 'path_bytes' => strlen(basename($canonical)), 'entries' => 1, 'max_depth' => 0];
        }
        if (!is_dir($canonical)) {
            throw new RuntimeException("{$sourceKind} source path is not a regular file or directory: {$sourcePath}");
        }

        $rootPrefix = rtrim($canonical, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $files = [];
        $pathBytes = 0;
        $entries = 0;
        $maxDepth = 0;
        $walk = function (string $directory, int $depth) use (
            &$walk,
            &$files,
            &$pathBytes,
            &$entries,
            &$maxDepth,
            $rootPrefix,
            $accept,
            $sourceKind
        ): void {
            try {
                $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
            } catch (UnexpectedValueException $error) {
                throw new RuntimeException("Could not read {$sourceKind} source directory: {$directory}", 0, $error);
            }
            foreach ($iterator as $entry) {
                $entries++;
                if ($entries > self::MAX_SOURCE_ENTRIES) {
                    throw new RuntimeException("{$sourceKind} source traversal exceeds the 4,096-entry limit.");
                }
                if ($entry->isLink()) {
                    throw new RuntimeException("{$sourceKind} source trees must not contain symbolic links.");
                }
                $path = $entry->getPathname();
                $real = realpath($path);
                if (!is_string($real) || !str_starts_with($real, $rootPrefix)) {
                    throw new RuntimeException("{$sourceKind} source entry escapes its canonical source root.");
                }
                if ($entry->isDir()) {
                    if ($depth >= self::MAX_SOURCE_DEPTH) {
                        throw new RuntimeException("{$sourceKind} source traversal exceeds the eight-directory depth limit.");
                    }
                    $maxDepth = max($maxDepth, $depth + 1);
                    $walk($real, $depth + 1);
                    continue;
                }
                if (!$entry->isFile() || !$accept($real)) {
                    continue;
                }

                $relative = substr($real, strlen($rootPrefix));
                $files[] = $real;
                $pathBytes += strlen($relative);
                if (count($files) > self::MAX_SOURCE_FILES) {
                    throw new RuntimeException("{$sourceKind} source contains more than 256 accepted files.");
                }
                if ($pathBytes > self::MAX_SOURCE_PATH_BYTES) {
                    throw new RuntimeException("{$sourceKind} accepted source paths exceed the aggregate 8 KiB limit.");
                }
            }
        };
        $walk($canonical, 0);
        sort($files, SORT_STRING);

        return [
            'files' => $files,
            'path_bytes' => $pathBytes,
            'entries' => $entries,
            'max_depth' => $maxDepth,
        ];
    }

    /**
     * Read one source line without materializing an arbitrarily long plain or
     * gzip record before the format-specific importer can reject it.
     *
     * @param array{type:string,handle:resource} $reader
     */
    public static function read_line(array $reader, string $sourceKind): string|false
    {
        $line = $reader['type'] === 'gzip'
            ? @gzgets($reader['handle'], self::MAX_SOURCE_LINE_BYTES + 3)
            : fgets($reader['handle'], self::MAX_SOURCE_LINE_BYTES + 3);
        if ($line === false) {
            return false;
        }

        $terminated = str_ends_with($line, "\n");
        $payloadBytes = strlen(rtrim($line, "\r\n"));
        $atEnd = $reader['type'] === 'gzip'
            ? gzeof($reader['handle'])
            : feof($reader['handle']);
        if ($payloadBytes > self::MAX_SOURCE_LINE_BYTES || (!$terminated && !$atEnd)) {
            throw new RuntimeException(
                "{$sourceKind} lines may contain at most 64 KiB before the line ending."
            );
        }

        return $line;
    }

    /** Conservatively size the fixed portion of a generated lookup header. */
    public static function lookup_header_base_bytes(): int
    {
        $header = [
            'format' => WP_FTS_LemmaPackLookupIndex::FORMAT,
            'runtime_sha256' => str_repeat('0', 64),
            'rows' => PHP_INT_MAX,
            'rows_sha256' => str_repeat('0', 64),
            'block_rows' => WP_FTS_LemmaPackLookupIndex::DEFAULT_BLOCK_ROWS,
            'blocks' => [],
        ];
        $json = json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return strlen($json);
    }

    /**
     * Size one range entry with worst-case integer widths. Actual offsets,
     * compressed lengths, decoded bytes, and row counts cannot use more bytes.
     */
    public static function lookup_block_header_bytes(string $firstSurface, string $lastSurface): int
    {
        $entry = [
            'first_surface' => $firstSurface,
            'last_surface' => $lastSurface,
            'offset' => PHP_INT_MAX,
            'length' => PHP_INT_MAX,
            'decoded_bytes' => PHP_INT_MAX,
            'rows' => PHP_INT_MAX,
        ];
        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return strlen($json);
    }

    /** Resolve existing ancestors before normalizing a not-yet-created path. */
    private static function canonical_candidate_path(string $path): string
    {
        $cursor = $path;
        $suffix = [];
        while (!file_exists($cursor) && !is_link($cursor)) {
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $suffix[] = basename($cursor);
            $cursor = $parent;
        }

        $canonical = realpath($cursor);
        if (!is_string($canonical)) {
            throw new RuntimeException("Could not resolve output-path ancestor: {$path}");
        }
        foreach (array_reverse($suffix) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                $canonical = dirname($canonical);
                continue;
            }
            $canonical .= DIRECTORY_SEPARATOR . $segment;
        }

        return $canonical;
    }

    /**
     * @param array<string,mixed> $stat
     * @return array{bytes:int,device:int,inode:int,mtime:int,ctime:int}
     */
    private static function stat_evidence(array $stat): array
    {
        return [
            'bytes' => (int) ($stat['size'] ?? -1),
            'device' => (int) ($stat['dev'] ?? -1),
            'inode' => (int) ($stat['ino'] ?? -1),
            'mtime' => (int) ($stat['mtime'] ?? -1),
            'ctime' => (int) ($stat['ctime'] ?? -1),
        ];
    }

    /**
     * @param array{bytes:int,device:int,inode:int,mtime:int,ctime:int} $left
     * @param array{bytes:int,device:int,inode:int,mtime:int,ctime:int} $right
     */
    private static function same_stat_generation(array $left, array $right): bool
    {
        return $left === $right;
    }
}
