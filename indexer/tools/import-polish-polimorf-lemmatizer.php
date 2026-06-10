<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

/**
 * Deterministically imports PoliMorf-style five-column TSV data into a local
 * Polish lemma analyzer pack.
 */
final class WP_FTS_PolishPolimorfImporter
{
    private const RUNTIME_FORMAT = 'wp-fts-polish-lemma-tsv-v1';
    private const SOURCE_LOCK_SCHEMA = 'wp-fts-polish-polimorf-source-lock/v1';

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function import(array $options): array
    {
        $sourcePath = $this->required_path($options, 'source');
        $outDir = $this->required_string($options, 'out');
        $packId = (string) ($options['pack_id'] ?? 'pl-polimorf-20180722-full');
        $version = (string) ($options['version'] ?? '2018.07.22-import-v1');
        $sourceUrl = (string) ($options['source_url'] ?? 'https://clarin-pl.eu/dspace/bitstream/handle/11321/577/polimorf-20180722.tab.gz?isAllowed=y&sequence=1');
        $fixtureOnly = $this->bool_option($options['fixture_only'] ?? false);
        $rowsPerFile = max(1, (int) ($options['max_rows_per_file'] ?? 100000));
        $chunkRows = max(1, (int) ($options['chunk_rows'] ?? 200000));
        $sourceName = (string) ($options['source_name'] ?? 'PoliMorf Polish morphological dictionary');
        $sourceVersion = (string) ($options['source_version'] ?? '2018.07.22');
        $retrievalNote = (string) ($options['source_retrieval_note'] ?? 'Source bytes are locked by URL, SHA-256, and byte count; retrieval timestamp is recorded outside deterministic importer output.');
        $importerCommit = (string) ($options['importer_commit'] ?? 'recorded-in-task-result');

        $this->prepare_output_directory($outDir);
        $runtimeDir = $outDir . DIRECTORY_SEPARATOR . 'runtime';
        if (!mkdir($runtimeDir, 0777, true) && !is_dir($runtimeDir)) {
            throw new RuntimeException("Could not create runtime directory: {$runtimeDir}");
        }

        $tmpDir = $this->prepare_temp_directory($options['tmp_dir'] ?? null);
        $sourceSha = hash_file('sha256', $sourcePath);
        if (!is_string($sourceSha)) {
            throw new RuntimeException('Could not hash source artifact.');
        }
        $sourceBytes = filesize($sourcePath);
        if (!is_int($sourceBytes)) {
            throw new RuntimeException('Could not measure source artifact size.');
        }

        $normalizer = new WP_FTS_Normalizer();
        $stats = [
            'source_lines' => 0,
            'metadata_lines' => 0,
            'lexical_rows' => 0,
            'invalid_column_rows' => 0,
            'skipped_invalid_tokens' => 0,
            'accepted_source_rows' => 0,
        ];
        $noticeLines = [];
        $chunkFiles = [];
        $pairs = [];
        $seenLexicalRows = false;

        $reader = $this->open_source($sourcePath);
        try {
            while (($line = $this->read_source_line($reader)) !== false) {
                $stats['source_lines']++;
                $line = rtrim((string) $line, "\n");
                $line = rtrim($line, "\r");
                $columns = explode("\t", $line);
                if (count($columns) !== 5) {
                    if (!$seenLexicalRows) {
                        $stats['metadata_lines']++;
                        $noticeLines[] = $line;
                    } else {
                        $stats['invalid_column_rows']++;
                    }
                    continue;
                }

                $seenLexicalRows = true;
                $stats['lexical_rows']++;
                $surface = $normalizer->normalize_token($columns[0], 'pl');
                $lemma = $normalizer->normalize_token($columns[1], 'pl');
                if (!$this->is_runtime_token($surface) || !$this->is_runtime_token($lemma)) {
                    $stats['skipped_invalid_tokens']++;
                    continue;
                }

                $pairs[$surface . "\t" . $lemma] = true;
                $stats['accepted_source_rows']++;
                if (count($pairs) >= $chunkRows) {
                    $chunkFiles[] = $this->flush_chunk($pairs, $tmpDir, count($chunkFiles) + 1);
                    $pairs = [];
                }
            }
        } finally {
            $this->close_source($reader);
        }

        if ($pairs !== []) {
            $chunkFiles[] = $this->flush_chunk($pairs, $tmpDir, count($chunkFiles) + 1);
        }
        if ($chunkFiles === []) {
            throw new RuntimeException('Source did not yield any normalized runtime rows.');
        }

        $merge = $this->merge_chunks($chunkFiles, $runtimeDir, $rowsPerFile);
        $runtimeFiles = $merge['files'];
        $runtimeRows = (int) $merge['rows'];
        $runtimeDigest = (string) $merge['sha256'];
        $runtimeBytes = $this->sum_file_bytes(array_map(static fn(array $file): string => $runtimeDir . DIRECTORY_SEPARATOR . basename((string) $file['path']), $runtimeFiles));

        $noticePath = $outDir . DIRECTORY_SEPARATOR . 'NOTICE.txt';
        $this->write_text($noticePath, $this->build_notice($sourceName, $sourceVersion, $sourceUrl, $sourceSha, $sourceBytes, $noticeLines));

        $manifest = $this->build_manifest([
            'pack_id' => $packId,
            'version' => $version,
            'fixture_only' => $fixtureOnly,
            'source_name' => $sourceName,
            'source_version' => $sourceVersion,
            'source_url' => $sourceUrl,
            'source_file' => basename($sourcePath),
            'source_sha256' => $sourceSha,
            'source_bytes' => $sourceBytes,
            'source_retrieval_note' => $retrievalNote,
            'runtime_rows' => $runtimeRows,
            'runtime_sha256' => $runtimeDigest,
            'runtime_files' => $runtimeFiles,
            'stats' => $stats,
            'importer_commit' => $importerCommit,
        ]);
        $manifestPath = $outDir . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->write_json($manifestPath, $manifest);

        $manifestSha = hash_file('sha256', $manifestPath);
        if (!is_string($manifestSha)) {
            throw new RuntimeException('Could not hash generated manifest.');
        }
        $sourceLock = $this->build_source_lock($manifest, $manifestSha, $runtimeBytes, $importerCommit);
        $sourceLockPath = $outDir . DIRECTORY_SEPARATOR . 'SOURCE.lock.json';
        $this->write_json($sourceLockPath, $sourceLock);

        $this->remove_tree($tmpDir);
        $packBytes = $this->directory_bytes($outDir);

        return [
            'status' => 'ok',
            'pack_id' => $packId,
            'manifest' => $manifestPath,
            'manifest_sha256' => $manifestSha,
            'source_lock' => $sourceLockPath,
            'source' => [
                'path' => $sourcePath,
                'url' => $sourceUrl,
                'sha256' => $sourceSha,
                'bytes' => $sourceBytes,
            ],
            'runtime' => [
                'rows' => $runtimeRows,
                'files' => count($runtimeFiles),
                'bytes' => $runtimeBytes,
                'sha256' => $runtimeDigest,
            ],
            'pack_bytes' => $packBytes,
            'stats' => $stats,
        ];
    }

    /**
     * @param string[] $argv
     * @return array<string,mixed>
     */
    public static function parse_cli_options(array $argv): array
    {
        $options = [];
        for ($i = 0, $count = count($argv); $i < $count; $i++) {
            $arg = (string) $argv[$i];
            if (!str_starts_with($arg, '--')) {
                throw new RuntimeException("Unexpected argument: {$arg}");
            }
            $arg = substr($arg, 2);
            $equals = strpos($arg, '=');
            if ($equals !== false) {
                $key = substr($arg, 0, $equals);
                $value = substr($arg, $equals + 1);
            } else {
                $key = $arg;
                if (isset($argv[$i + 1]) && !str_starts_with((string) $argv[$i + 1], '--')) {
                    $value = (string) $argv[++$i];
                } else {
                    $value = true;
                }
            }
            $options[str_replace('-', '_', $key)] = $value;
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $options
     */
    private function required_path(array $options, string $key): string
    {
        $path = $this->required_string($options, $key);
        if (!is_file($path)) {
            throw new RuntimeException("Required file --{$key} does not exist: {$path}");
        }

        return $path;
    }

    /**
     * @param array<string,mixed> $options
     */
    private function required_string(array $options, string $key): string
    {
        if (!isset($options[$key]) || !is_scalar($options[$key]) || trim((string) $options[$key]) === '') {
            throw new RuntimeException("Missing required option --{$key}.");
        }

        return (string) $options[$key];
    }

    private function bool_option(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function prepare_output_directory(string $outDir): void
    {
        if (is_file($outDir)) {
            throw new RuntimeException("Output path is a file: {$outDir}");
        }
        if (!is_dir($outDir) && !mkdir($outDir, 0777, true)) {
            throw new RuntimeException("Could not create output directory: {$outDir}");
        }

        $iterator = new FilesystemIterator($outDir, FilesystemIterator::SKIP_DOTS);
        if ($iterator->valid()) {
            throw new RuntimeException("Output directory must be empty: {$outDir}");
        }
    }

    private function prepare_temp_directory(mixed $requested): string
    {
        if (is_scalar($requested) && trim((string) $requested) !== '') {
            $tmpDir = (string) $requested;
        } else {
            $tmpDir = sys_get_temp_dir() . '/wp-fts-polimorf-import-' . getmypid() . '-' . bin2hex(random_bytes(4));
        }
        if (is_file($tmpDir)) {
            throw new RuntimeException("Temporary path is a file: {$tmpDir}");
        }
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0777, true)) {
            throw new RuntimeException("Could not create temporary directory: {$tmpDir}");
        }

        return $tmpDir;
    }

    /**
     * @return array{type:string,handle:resource}
     */
    private function open_source(string $sourcePath): array
    {
        if (str_ends_with(strtolower($sourcePath), '.gz')) {
            if (!function_exists('gzopen')) {
                throw new RuntimeException('Reading gzip sources requires the PHP zlib extension; use an extracted TSV under php -n.');
            }
            $handle = gzopen($sourcePath, 'rb');
            if (!is_resource($handle)) {
                throw new RuntimeException("Could not open gzip source: {$sourcePath}");
            }

            return ['type' => 'gzip', 'handle' => $handle];
        }

        $handle = fopen($sourcePath, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Could not open source: {$sourcePath}");
        }

        return ['type' => 'plain', 'handle' => $handle];
    }

    /**
     * @param array{type:string,handle:resource} $reader
     */
    private function read_source_line(array $reader): string|false
    {
        if ($reader['type'] === 'gzip') {
            return gzgets($reader['handle']);
        }

        return fgets($reader['handle']);
    }

    /**
     * @param array{type:string,handle:resource} $reader
     */
    private function close_source(array $reader): void
    {
        if ($reader['type'] === 'gzip') {
            gzclose($reader['handle']);
            return;
        }

        fclose($reader['handle']);
    }

    private function is_runtime_token(string $token): bool
    {
        if ($token === '' || strpbrk($token, " \t\r\n") !== false || str_contains($token, WP_FTS_TermNamespace::SEPARATOR)) {
            return false;
        }

        return preg_match('/^[\p{L}\p{M}\p{N}_]+$/u', $token) === 1;
    }

    /**
     * @param array<string,bool> $pairs
     */
    private function flush_chunk(array $pairs, string $tmpDir, int $number): string
    {
        $lines = array_keys($pairs);
        sort($lines, SORT_STRING);
        $path = $tmpDir . DIRECTORY_SEPARATOR . sprintf('chunk-%05d.tsv', $number);
        $handle = fopen($path, 'wb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Could not write chunk file: {$path}");
        }
        foreach ($lines as $line) {
            fwrite($handle, $line . "\n");
        }
        fclose($handle);

        return $path;
    }

    /**
     * @param string[] $chunkFiles
     * @return array{files:array<int,array<string,mixed>>,rows:int,sha256:string}
     */
    private function merge_chunks(array $chunkFiles, string $runtimeDir, int $rowsPerFile): array
    {
        $chunks = [];
        foreach ($chunkFiles as $path) {
            $handle = fopen($path, 'rb');
            if (!is_resource($handle)) {
                throw new RuntimeException("Could not read chunk file: {$path}");
            }
            $line = $this->read_chunk_line($handle);
            if ($line !== null) {
                $chunks[] = ['path' => $path, 'handle' => $handle, 'line' => $line];
            } else {
                fclose($handle);
            }
        }

        $files = [];
        $runtimeDigest = hash_init('sha256');
        $previousPair = null;
        $currentSurface = null;
        $currentSurfaceLemmaCount = 0;
        $ambiguousSurfaces = 0;
        $unambiguousSurfaces = 0;
        $totalRows = 0;
        $shard = null;

        $finishSurface = static function () use (&$currentSurface, &$currentSurfaceLemmaCount, &$ambiguousSurfaces, &$unambiguousSurfaces): void {
            if ($currentSurface === null) {
                return;
            }
            if ($currentSurfaceLemmaCount === 1) {
                $unambiguousSurfaces++;
            } else {
                $ambiguousSurfaces++;
            }
        };

        try {
            while ($chunks !== []) {
                $minIndex = $this->min_chunk_index($chunks);
                $pair = $chunks[$minIndex]['line'];
                $next = $this->read_chunk_line($chunks[$minIndex]['handle']);
                if ($next === null) {
                    fclose($chunks[$minIndex]['handle']);
                    array_splice($chunks, $minIndex, 1);
                } else {
                    $chunks[$minIndex]['line'] = $next;
                }

                if ($pair === $previousPair) {
                    continue;
                }
                $previousPair = $pair;
                [$surface] = explode("\t", $pair, 2);
                if ($currentSurface !== $surface) {
                    $finishSurface();
                    $currentSurface = $surface;
                    $currentSurfaceLemmaCount = 0;
                    if ($shard !== null && $shard['rows'] >= $rowsPerFile) {
                        $files[] = $this->close_shard($shard);
                        $shard = null;
                    }
                }
                $currentSurfaceLemmaCount++;

                if ($shard === null) {
                    $shard = $this->open_shard($runtimeDir, count($files) + 1);
                }
                $this->write_pair_to_shard($shard, $pair, $surface);
                hash_update($runtimeDigest, $pair . "\n");
                $totalRows++;
            }
        } finally {
            foreach ($chunks as $chunk) {
                if (is_resource($chunk['handle'])) {
                    fclose($chunk['handle']);
                }
            }
        }

        $finishSurface();
        if ($shard !== null) {
            $files[] = $this->close_shard($shard);
        }
        if ($totalRows < 1) {
            throw new RuntimeException('Chunk merge did not produce runtime rows.');
        }

        return [
            'files' => array_map(
                static fn(array $file): array => [
                    'path' => 'runtime/' . basename((string) $file['path']),
                    'sha256' => $file['sha256'],
                    'rows' => $file['rows'],
                    'first_surface' => $file['first_surface'],
                    'last_surface' => $file['last_surface'],
                ],
                $files
            ),
            'rows' => $totalRows,
            'sha256' => hash_final($runtimeDigest),
            'ambiguous_surfaces' => $ambiguousSurfaces,
            'unambiguous_surfaces' => $unambiguousSurfaces,
        ];
    }

    /**
     * @param resource $handle
     */
    private function read_chunk_line($handle): ?string
    {
        $line = fgets($handle);
        if ($line === false) {
            return null;
        }

        return rtrim(rtrim((string) $line, "\n"), "\r");
    }

    /**
     * @param array<int,array{line:string,handle:resource,path:string}> $chunks
     */
    private function min_chunk_index(array $chunks): int
    {
        $minIndex = 0;
        $minLine = $chunks[0]['line'];
        foreach ($chunks as $index => $chunk) {
            if ($index === 0) {
                continue;
            }
            if (strcmp($chunk['line'], $minLine) < 0) {
                $minIndex = $index;
                $minLine = $chunk['line'];
            }
        }

        return $minIndex;
    }

    /**
     * @return array{path:string,handle:resource,hash:HashContext,rows:int,first_surface:?string,last_surface:?string}
     */
    private function open_shard(string $runtimeDir, int $number): array
    {
        $path = $runtimeDir . DIRECTORY_SEPARATOR . sprintf('%04d.tsv', $number);
        $handle = fopen($path, 'wb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Could not write runtime shard: {$path}");
        }

        return [
            'path' => $path,
            'handle' => $handle,
            'hash' => hash_init('sha256'),
            'rows' => 0,
            'first_surface' => null,
            'last_surface' => null,
        ];
    }

    /**
     * @param array{path:string,handle:resource,hash:HashContext,rows:int,first_surface:?string,last_surface:?string} $shard
     */
    private function write_pair_to_shard(array &$shard, string $pair, string $surface): void
    {
        $line = $pair . "\n";
        fwrite($shard['handle'], $line);
        hash_update($shard['hash'], $line);
        $shard['rows']++;
        $shard['first_surface'] ??= $surface;
        $shard['last_surface'] = $surface;
    }

    /**
     * @param array{path:string,handle:resource,hash:HashContext,rows:int,first_surface:?string,last_surface:?string} $shard
     * @return array{path:string,sha256:string,rows:int,first_surface:string,last_surface:string}
     */
    private function close_shard(array $shard): array
    {
        fclose($shard['handle']);
        if (!is_string($shard['first_surface']) || !is_string($shard['last_surface'])) {
            throw new RuntimeException('Cannot close an empty runtime shard.');
        }

        return [
            'path' => $shard['path'],
            'sha256' => hash_final($shard['hash']),
            'rows' => $shard['rows'],
            'first_surface' => $shard['first_surface'],
            'last_surface' => $shard['last_surface'],
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function build_manifest(array $data): array
    {
        return [
            'schema_version' => 1,
            'pack_id' => $data['pack_id'],
            'language' => 'pl',
            'version' => $data['version'],
            'fixture_only' => $data['fixture_only'],
            'default_enabled' => false,
            'capabilities' => [
                'dictionary-lemmatizer',
                'ambiguous-form-noop',
                'normalized-runtime-rows',
                'sharded-runtime-files',
            ],
            'runtime' => [
                'format' => self::RUNTIME_FORMAT,
                'normalization' => 'WP_FTS_Normalizer pl with fold_diacritics=true',
                'ambiguity_policy' => 'ambiguous_surface_noop',
                'total_rows' => $data['runtime_rows'],
                'total_sha256' => $data['runtime_sha256'],
                'files' => $data['runtime_files'],
            ],
            'source' => [
                'name' => $data['source_name'],
                'version' => $data['source_version'],
                'file' => $data['source_file'],
                'url' => $data['source_url'],
                'artifact_sha256' => $data['source_sha256'],
                'byte_count' => $data['source_bytes'],
                'retrieval_note' => $data['source_retrieval_note'],
                'column_model' => [
                    'format' => 'polimorf-five-column-tab',
                    'surface_column' => 0,
                    'lemma_column' => 1,
                    'tag_column' => 2,
                    'qualifier_column' => 3,
                    'flags_column' => 4,
                ],
                'parse_stats' => $data['stats'],
            ],
            'license' => [
                'spdx_id' => 'BSD-2-Clause',
                'license_url' => 'https://opensource.org/licenses/BSD-2-Clause',
                'notice_path' => 'NOTICE.txt',
                'notice_required' => true,
            ],
            'attribution' => [
                'notice_path' => 'NOTICE.txt',
                'upstream' => 'PoliMorf is distributed by CLARIN-PL and includes Morfeusz SGJP and Morfologik-derived data.',
            ],
            'provenance' => [
                'importer' => 'indexer/tools/import-polish-polimorf-lemmatizer.php',
                'importer_commit' => $data['importer_commit'],
                'importer_command' => $this->canonical_importer_command((string) $data['pack_id'], (string) $data['version'], (string) $data['source_url'], (bool) $data['fixture_only']),
                'no_runtime_network_access' => true,
                'no_full_third_party_dictionary_dump' => (bool) $data['fixture_only'],
                'full_third_party_dictionary_dump_generated' => !(bool) $data['fixture_only'],
                'generated_pack_default_enabled' => false,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private function build_source_lock(array $manifest, string $manifestSha, int $runtimeBytes, string $importerCommit): array
    {
        return [
            'schema_version' => self::SOURCE_LOCK_SCHEMA,
            'pack' => [
                'id' => $manifest['pack_id'],
                'language' => 'pl',
                'kind' => 'lemmatizer',
                'runtime_pack_committed' => false,
                'default_enabled' => false,
            ],
            'source' => [
                'name' => $manifest['source']['name'],
                'version' => $manifest['source']['version'],
                'file' => $manifest['source']['file'],
                'url' => $manifest['source']['url'],
                'artifact_sha256' => $manifest['source']['artifact_sha256'],
                'byte_count' => $manifest['source']['byte_count'],
                'license_spdx_id' => $manifest['license']['spdx_id'],
                'license_url' => $manifest['license']['license_url'],
                'notice_path' => $manifest['license']['notice_path'],
            ],
            'columns' => $manifest['source']['column_model'],
            'importer' => [
                'path' => $manifest['provenance']['importer'],
                'commit' => $importerCommit,
                'command' => $manifest['provenance']['importer_command'],
            ],
            'runtime' => [
                'manifest_sha256' => $manifestSha,
                'row_count' => $manifest['runtime']['total_rows'],
                'file_count' => count($manifest['runtime']['files']),
                'byte_count' => $runtimeBytes,
                'digest_sha256' => $manifest['runtime']['total_sha256'],
                'contains_third_party_data' => true,
                'committed' => false,
            ],
            'behavior' => [
                'oov_policy' => 'return_original_normalized_term',
                'ambiguity_policy' => $manifest['runtime']['ambiguity_policy'],
                'unsupported_language_policy' => 'return_original_normalized_term',
            ],
            'release' => [
                'default_enabled' => false,
                'claim_boundary' => 'Full PoliMorf-derived Polish lemmatizer evidence only. Runtime pack remains opt-in and is not committed unless packaging review approves the generated third-party data size.',
            ],
        ];
    }

    private function canonical_importer_command(string $packId, string $version, string $sourceUrl, bool $fixtureOnly): string
    {
        $parts = [
            'php indexer/tools/import-polish-polimorf-lemmatizer.php',
            '--source=<artifact>',
            '--out=<pack-dir>',
            '--pack-id=' . $packId,
            '--version=' . $version,
            '--source-url=' . $sourceUrl,
        ];
        if ($fixtureOnly) {
            $parts[] = '--fixture-only=true';
        }

        return implode(' ', $parts);
    }

    /**
     * @param string[] $noticeLines
     */
    private function build_notice(string $sourceName, string $sourceVersion, string $sourceUrl, string $sourceSha, int $sourceBytes, array $noticeLines): string
    {
        $lines = [
            "{$sourceName} {$sourceVersion}",
            "Source URL: {$sourceUrl}",
            "Artifact SHA-256: {$sourceSha}",
            "Artifact byte count: {$sourceBytes}",
            'License: BSD-2-Clause',
            '',
            'Upstream notice retained from the source artifact:',
            '',
        ];
        foreach ($noticeLines as $line) {
            $lines[] = $line;
        }
        if ($noticeLines === []) {
            $lines[] = 'No upstream notice lines were present before the first lexical row.';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string,mixed> $data
     */
    private function write_json(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException("Could not encode JSON for {$path}.");
        }
        $this->write_text($path, $json . "\n");
    }

    private function write_text(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Could not write {$path}.");
        }
    }

    /**
     * @param string[] $paths
     */
    private function sum_file_bytes(array $paths): int
    {
        $bytes = 0;
        foreach ($paths as $path) {
            $size = filesize($path);
            if (is_int($size)) {
                $bytes += $size;
            }
        }

        return $bytes;
    }

    private function directory_bytes(string $directory): int
    {
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $bytes += $file->getSize();
            }
        }

        return $bytes;
    }

    private function remove_tree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path) {
            if ($path->isDir()) {
                rmdir($path->getPathname());
            } else {
                unlink($path->getPathname());
            }
        }
        rmdir($directory);
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === __FILE__) {
    try {
        $options = WP_FTS_PolishPolimorfImporter::parse_cli_options(array_slice($argv, 1));
        $summary = (new WP_FTS_PolishPolimorfImporter())->import($options);
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Polish PoliMorf import failed: {$e->getMessage()}\n");
        exit(1);
    }
}
