<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

/**
 * Imports a source-approved, already-normalized lemma TSV into an analyzer pack.
 *
 * The input contract is intentionally narrow: each non-comment row must provide
 * surface<TAB>lemma, with optional tag and source-note columns. Source-specific
 * extraction and licensing review happen before this tool sees the TSV.
 */
final class WP_FTS_LemmaTsvPackImporter
{
    private const RUNTIME_FORMAT = WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV;

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function import(array $options): array
    {
        $sourcePath = $this->required_path($options, 'source');
        $outDir = $this->required_output_dir($options);
        $language = $this->required_language($options, 'language');
        $packId = $this->required_string($options, 'pack_id');
        $version = $this->required_string($options, 'version');
        $sourceName = $this->required_string($options, 'source_name');
        $sourceUrl = $this->required_string($options, 'source_url');
        $license = $this->required_string($options, 'license');
        $attribution = $this->required_string($options, 'attribution');
        $sourceVersion = (string) ($options['source_version'] ?? $version);
        $licenseUrl = (string) ($options['license_url'] ?? '');
        $fixtureOnly = $this->bool_option($options['fixture_only'] ?? false);
        $rowsPerFile = max(1, (int) ($options['max_rows_per_file'] ?? 100000));
        $chunkRows = max(1, (int) ($options['chunk_rows'] ?? 200000));
        $importerCommit = (string) ($options['importer_commit'] ?? 'recorded-in-task-result');
        $runtimeCompression = $this->runtime_compression_option($options['runtime_compression'] ?? $options['compression'] ?? null);

        $this->prepare_output_directory($outDir);
        $runtimeDir = $outDir . DIRECTORY_SEPARATOR . 'runtime';
        if (!mkdir($runtimeDir, 0777, true) && !is_dir($runtimeDir)) {
            throw new RuntimeException("Could not create runtime directory: {$runtimeDir}");
        }

        $tmpDir = $this->prepare_temp_directory($options['tmp_dir'] ?? null);
        try {
            $sourceSha = hash_file('sha256', $sourcePath);
            if (!is_string($sourceSha)) {
                throw new RuntimeException('Could not hash source TSV.');
            }
            $sourceBytes = filesize($sourcePath);
            if (!is_int($sourceBytes)) {
                throw new RuntimeException('Could not measure source TSV size.');
            }

            $stats = [
                'source_lines' => 0,
                'blank_lines' => 0,
                'comment_lines' => 0,
                'accepted_source_rows' => 0,
                'rows_with_tags' => 0,
                'rows_with_source_notes' => 0,
            ];
            $normalizer = new WP_FTS_Normalizer();
            $chunkFiles = [];
            $pairs = [];

            $reader = $this->open_source($sourcePath);
            try {
                while (($line = $this->read_source_line($reader)) !== false) {
                    $stats['source_lines']++;
                    $line = rtrim((string) $line, "\n");
                    $line = rtrim($line, "\r");
                    if ($stats['source_lines'] === 1) {
                        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
                    }
                    if ($line === '') {
                        $stats['blank_lines']++;
                        continue;
                    }
                    if ($line[0] === '#') {
                        $stats['comment_lines']++;
                        continue;
                    }
                    if (preg_match('//u', $line) !== 1) {
                        throw new RuntimeException("Source row {$stats['source_lines']} is not valid UTF-8.");
                    }

                    $columns = explode("\t", $line);
                    $columnCount = count($columns);
                    if ($columnCount < 2 || $columnCount > 4) {
                        throw new RuntimeException("Source row {$stats['source_lines']} must have surface, lemma, and optional tag/source-note columns.");
                    }

                    $surface = trim($columns[0]);
                    $lemma = trim($columns[1]);
                    $this->validate_normalized_source_token($surface, $normalizer, $language, $stats['source_lines'], 'surface');
                    $this->validate_normalized_source_token($lemma, $normalizer, $language, $stats['source_lines'], 'lemma');
                    if (isset($columns[2]) && trim($columns[2]) !== '') {
                        $stats['rows_with_tags']++;
                    }
                    if (isset($columns[3]) && trim($columns[3]) !== '') {
                        $stats['rows_with_source_notes']++;
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
                throw new RuntimeException('Source TSV did not yield any normalized runtime rows.');
            }

            $merge = $this->merge_chunks($chunkFiles, $runtimeDir, $rowsPerFile, $runtimeCompression);
            $runtimeFiles = $merge['files'];
            $runtimeRows = (int) $merge['rows'];
            $runtimeDigest = (string) $merge['sha256'];
            $runtimeBytes = $this->sum_file_bytes(array_map(
                static fn(array $file): string => $outDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $file['path']),
                $runtimeFiles
            ));

            $noticePath = $outDir . DIRECTORY_SEPARATOR . 'NOTICE.txt';
            $this->write_text($noticePath, $this->build_notice(
                $sourceName,
                $sourceVersion,
                $sourceUrl,
                $sourceSha,
                $sourceBytes,
                $license,
                $licenseUrl,
                $attribution
            ));

            $manifest = $this->build_manifest([
                'pack_id' => $packId,
                'language' => $language,
                'version' => $version,
                'fixture_only' => $fixtureOnly,
                'source_name' => $sourceName,
                'source_version' => $sourceVersion,
                'source_url' => $sourceUrl,
                'source_file' => basename($sourcePath),
                'source_sha256' => $sourceSha,
                'source_bytes' => $sourceBytes,
                'license' => $license,
                'license_url' => $licenseUrl,
                'attribution' => $attribution,
                'runtime_rows' => $runtimeRows,
                'runtime_sha256' => $runtimeDigest,
                'runtime_files' => $runtimeFiles,
                'stats' => $stats + [
                    'runtime_rows' => $runtimeRows,
                    'deduplicated_rows' => $stats['accepted_source_rows'] - $runtimeRows,
                    'ambiguous_surfaces' => $merge['ambiguous_surfaces'],
                    'unambiguous_surfaces' => $merge['unambiguous_surfaces'],
                ],
                'importer_commit' => $importerCommit,
                'rows_per_file' => $rowsPerFile,
                'chunk_rows' => $chunkRows,
                'runtime_compression' => $runtimeCompression,
            ]);
            $manifestPath = $outDir . DIRECTORY_SEPARATOR . 'manifest.json';
            $this->write_json($manifestPath, $manifest);

            $manifestSha = hash_file('sha256', $manifestPath);
            if (!is_string($manifestSha)) {
                throw new RuntimeException('Could not hash generated manifest.');
            }

            $packBytes = $this->directory_bytes($outDir);

            return [
                'status' => 'ok',
                'pack_id' => $packId,
                'language' => $language,
                'manifest' => $manifestPath,
                'manifest_sha256' => $manifestSha,
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
                'stats' => $manifest['source']['parse_stats'],
            ];
        } finally {
            $this->remove_tree($tmpDir);
        }
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
    private function required_output_dir(array $options): string
    {
        if (isset($options['out'])) {
            return $this->required_string($options, 'out');
        }

        return $this->required_string($options, 'output_dir');
    }

    /**
     * @param array<string,mixed> $options
     */
    private function required_string(array $options, string $key): string
    {
        if (!isset($options[$key]) || !is_scalar($options[$key]) || trim((string) $options[$key]) === '') {
            throw new RuntimeException("Missing required option --" . str_replace('_', '-', $key) . '.');
        }

        return (string) $options[$key];
    }

    /**
     * @param array<string,mixed> $options
     */
    private function required_language(array $options, string $key): string
    {
        $language = $this->required_string($options, $key);
        if (preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8}){0,3}$/', $language) !== 1) {
            throw new RuntimeException("Invalid language tag for --{$key}: {$language}");
        }

        return (new WP_FTS_Normalizer())->canonicalize_language($language);
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

    private function runtime_compression_option(mixed $value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }
        if (!is_scalar($value)) {
            throw new RuntimeException('Runtime compression must be none or gzip.');
        }

        $compression = strtolower(trim((string) $value));
        if ($compression === '' || in_array($compression, ['0', 'false', 'no', 'none', 'off'], true)) {
            return null;
        }
        if ($compression !== WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP) {
            throw new RuntimeException('Runtime compression must be none or gzip.');
        }
        if (!WP_FTS_AnalyzerPackValidator::gzip_available()) {
            throw new RuntimeException('Gzip runtime compression requires the PHP zlib extension.');
        }

        return WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP;
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
        $parent = sys_get_temp_dir();
        if (is_scalar($requested) && trim((string) $requested) !== '') {
            $parent = (string) $requested;
        }

        if (is_file($parent)) {
            throw new RuntimeException("Temporary parent path is a file: {$parent}");
        }
        if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
            throw new RuntimeException("Could not create temporary parent directory: {$parent}");
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $tmpDir = $parent . DIRECTORY_SEPARATOR . 'wp-fts-lemma-tsv-import-' . getmypid() . '-' . bin2hex(random_bytes(8));
            if (mkdir($tmpDir, 0700)) {
                return $tmpDir;
            }
            if (!file_exists($tmpDir)) {
                throw new RuntimeException("Could not create importer temporary directory: {$tmpDir}");
            }
        }

        throw new RuntimeException("Could not create a unique importer temporary directory under: {$parent}");
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

    private function validate_normalized_source_token(
        string $token,
        WP_FTS_Normalizer $normalizer,
        string $language,
        int $lineNumber,
        string $column
    ): void {
        if ($token === '') {
            throw new RuntimeException("Source {$column} at row {$lineNumber} must not be empty.");
        }
        if (strpbrk($token, " \t\r\n") !== false || str_contains($token, WP_FTS_TermNamespace::SEPARATOR)) {
            throw new RuntimeException("Source {$column} at row {$lineNumber} must be one normalized token.");
        }
        if ($normalizer->normalize_token($token, $language) !== $token) {
            throw new RuntimeException("Source {$column} at row {$lineNumber} is not normalized for {$language}.");
        }
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
     * @return array{files:array<int,array<string,mixed>>,rows:int,sha256:string,ambiguous_surfaces:int,unambiguous_surfaces:int}
     */
    private function merge_chunks(array $chunkFiles, string $runtimeDir, int $rowsPerFile, ?string $runtimeCompression): array
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
                    $shard = $this->open_shard($runtimeDir, count($files) + 1, $runtimeCompression);
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
                static function (array $file): array {
                    $entry = [
                        'path' => 'runtime/' . basename((string) $file['path']),
                        'sha256' => $file['sha256'],
                        'rows' => $file['rows'],
                        'first_surface' => $file['first_surface'],
                        'last_surface' => $file['last_surface'],
                    ];
                    if (isset($file['compression'])) {
                        $entry['compression'] = $file['compression'];
                    }
                    if (isset($file['lookup']) && is_array($file['lookup'])) {
                        $entry['lookup'] = [
                            'format' => $file['lookup']['format'],
                            'path' => 'runtime/' . basename((string) $file['lookup']['path']),
                            'sha256' => $file['lookup']['sha256'],
                            'blocks' => $file['lookup']['blocks'],
                        ];
                    }

                    return $entry;
                },
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
     * @return array{path:string,handle:resource,compression:?string,rows:int,first_surface:?string,last_surface:?string}
     */
    private function open_shard(string $runtimeDir, int $number, ?string $runtimeCompression): array
    {
        $extension = $runtimeCompression === WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP ? '.tsv.gz' : '.tsv';
        $path = $runtimeDir . DIRECTORY_SEPARATOR . sprintf('%04d%s', $number, $extension);
        $handle = $runtimeCompression === WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP
            ? gzopen($path, 'wb9')
            : fopen($path, 'wb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Could not write runtime shard: {$path}");
        }

        return [
            'path' => $path,
            'handle' => $handle,
            'compression' => $runtimeCompression,
            'rows' => 0,
            'first_surface' => null,
            'last_surface' => null,
        ];
    }

    /**
     * @param array{path:string,handle:resource,compression:?string,rows:int,first_surface:?string,last_surface:?string} $shard
     */
    private function write_pair_to_shard(array &$shard, string $pair, string $surface): void
    {
        $line = $pair . "\n";
        if ($shard['compression'] === WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP) {
            gzwrite($shard['handle'], $line);
        } else {
            fwrite($shard['handle'], $line);
        }
        $shard['rows']++;
        $shard['first_surface'] ??= $surface;
        $shard['last_surface'] = $surface;
    }

    /**
     * @param array{path:string,handle:resource,compression:?string,rows:int,first_surface:?string,last_surface:?string} $shard
     * @return array{path:string,sha256:string,rows:int,first_surface:string,last_surface:string,compression?:string,lookup?:array<string,mixed>}
     */
    private function close_shard(array $shard): array
    {
        if ($shard['compression'] === WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP) {
            gzclose($shard['handle']);
        } else {
            fclose($shard['handle']);
        }
        if (!is_string($shard['first_surface']) || !is_string($shard['last_surface'])) {
            throw new RuntimeException('Cannot close an empty runtime shard.');
        }

        $sha = hash_file('sha256', $shard['path']);
        if (!is_string($sha)) {
            throw new RuntimeException("Could not hash runtime shard: {$shard['path']}");
        }

        $file = [
            'path' => $shard['path'],
            'sha256' => $sha,
            'rows' => $shard['rows'],
            'first_surface' => $shard['first_surface'],
            'last_surface' => $shard['last_surface'],
        ];
        if ($shard['compression'] !== null) {
            $file['compression'] = $shard['compression'];
        }
        if ($shard['compression'] === WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP) {
            $lookupPath = $shard['path'] . '.lookup';
            $lookup = WP_FTS_LemmaPackLookupIndex::build(
                $shard['path'],
                $shard['compression'],
                $sha,
                $lookupPath
            );
            $file['sha256'] = $lookup['runtime_sha256'];
            $file['lookup'] = ['path' => $lookupPath] + $lookup;
        }

        return $file;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function build_manifest(array $data): array
    {
        $capabilities = [
            'dictionary-lemmatizer',
            'ambiguous-form-noop',
            'normalized-runtime-rows',
            'sharded-runtime-files',
            'source-backed-lemma-tsv-import',
        ];
        if ((bool) $data['fixture_only']) {
            $capabilities[] = 'synthetic-test-data';
        }
        if ($data['runtime_compression'] === WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP) {
            $capabilities[] = 'compressed-runtime-files';
            $capabilities[] = 'indexed-runtime-lookups';
        }
        $license = [
            'spdx_id' => $data['license'],
            'notice_path' => 'NOTICE.txt',
            'notice_required' => true,
        ];
        if ((string) $data['license_url'] !== '') {
            $license['license_url'] = $data['license_url'];
        }

        return [
            'schema_version' => 1,
            'pack_id' => $data['pack_id'],
            'language' => $data['language'],
            'version' => $data['version'],
            'fixture_only' => $data['fixture_only'],
            'default_enabled' => false,
            'capabilities' => $capabilities,
            'runtime' => [
                'format' => self::RUNTIME_FORMAT,
                'normalization' => 'WP_FTS_Normalizer ' . $data['language'] . ' with fold_diacritics=true',
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
                'column_model' => [
                    'format' => 'normalized-lemma-tsv-v1',
                    'surface_column' => 0,
                    'lemma_column' => 1,
                    'tag_column' => 2,
                    'source_note_column' => 3,
                ],
                'parse_stats' => $data['stats'],
            ],
            'license' => $license,
            'attribution' => [
                'notice_path' => 'NOTICE.txt',
                'upstream' => $data['attribution'],
            ],
            'provenance' => [
                'importer' => 'indexer/tools/import-lemma-tsv-pack.php',
                'importer_commit' => $data['importer_commit'],
                'importer_command' => $this->canonical_importer_command(
                    (string) $data['language'],
                    (string) $data['pack_id'],
                    (string) $data['version'],
                    (string) $data['source_url'],
                    (string) $data['license'],
                    (bool) $data['fixture_only'],
                    $data['runtime_compression'] ?? null
                ),
                'no_runtime_network_access' => true,
                'no_full_third_party_dictionary_dump' => (bool) $data['fixture_only'],
                'full_third_party_dictionary_dump_generated' => !(bool) $data['fixture_only'],
                'generated_pack_default_enabled' => false,
                'rows_per_file' => $data['rows_per_file'],
                'chunk_rows' => $data['chunk_rows'],
                'runtime_compression' => $data['runtime_compression'] ?? null,
            ],
        ];
    }

    private function canonical_importer_command(
        string $language,
        string $packId,
        string $version,
        string $sourceUrl,
        string $license,
        bool $fixtureOnly,
        ?string $runtimeCompression
    ): string {
        $parts = [
            'php indexer/tools/import-lemma-tsv-pack.php',
            '--source=<normalized-tsv>',
            '--out=<pack-dir>',
            '--language=' . $language,
            '--pack-id=' . $packId,
            '--version=' . $version,
            '--source-name=<approved-source-name>',
            '--source-url=' . $sourceUrl,
            '--license=' . $license,
            '--attribution=<required-attribution>',
        ];
        if ($fixtureOnly) {
            $parts[] = '--fixture-only=true';
        }
        if ($runtimeCompression === WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP) {
            $parts[] = '--runtime-compression=gzip';
        }

        return implode(' ', $parts);
    }

    private function build_notice(
        string $sourceName,
        string $sourceVersion,
        string $sourceUrl,
        string $sourceSha,
        int $sourceBytes,
        string $license,
        string $licenseUrl,
        string $attribution
    ): string {
        $lines = [
            "{$sourceName} {$sourceVersion}",
            "Source URL: {$sourceUrl}",
            "Artifact SHA-256: {$sourceSha}",
            "Artifact byte count: {$sourceBytes}",
            "License: {$license}",
        ];
        if ($licenseUrl !== '') {
            $lines[] = "License URL: {$licenseUrl}";
        }
        $lines[] = "Attribution: {$attribution}";
        $lines[] = '';
        $lines[] = 'Generated from a source-approved normalized lemma TSV. The importer performs no network access at runtime.';

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
        $options = WP_FTS_LemmaTsvPackImporter::parse_cli_options(array_slice($argv, 1));
        $summary = (new WP_FTS_LemmaTsvPackImporter())->import($options);
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Lemma TSV import failed: {$e->getMessage()}\n");
        exit(1);
    }
}
