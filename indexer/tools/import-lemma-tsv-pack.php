<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/lemma-source-import-limits.php';
require_once __DIR__ . '/lemma-chunk-merge.php';

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
    public const IMPORT_OPTION_KEYS = [
        'source',
        'out',
        'language',
        'pack_id',
        'version',
        'source_name',
        'source_url',
        'license',
        'attribution',
        'source_version',
        'license_url',
        'max_rows_per_file',
        'chunk_rows',
        'importer_commit',
        'tmp_dir',
    ];

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function import(array $options): array
    {
        if (!WP_FTS_AnalyzerPackValidator::gzip_available()) {
            throw new RuntimeException('Lemma pack generation requires the PHP zlib extension.');
        }
        $this->assert_option_keys($options);

        $sourcePath = $this->required_path($options, 'source');
        $outDir = $this->required_string($options, 'out');
        WP_FTS_LemmaSourceImportLimits::assert_source_output_separate(
            $sourcePath,
            $outDir,
            'Normalized lemma TSV'
        );
        $language = $this->required_language($options, 'language');
        $packId = $this->required_string($options, 'pack_id');
        $version = $this->required_string($options, 'version');
        $sourceName = $this->required_string($options, 'source_name');
        $sourceUrl = $this->required_string($options, 'source_url');
        $license = $this->required_string($options, 'license');
        $attribution = $this->required_string($options, 'attribution');
        $sourceVersion = $this->optional_string($options, 'source_version', $version);
        $licenseUrl = $this->optional_string($options, 'license_url', '');
        $rowsPerFile = $this->positive_integer_option($options, 'max_rows_per_file', 100000);
        $chunkRows = $this->positive_integer_option(
            $options,
            'chunk_rows',
            WP_FTS_LemmaSourceImportLimits::MAX_CHUNK_ROWS
        );
        if ($chunkRows < 1 || $chunkRows > WP_FTS_LemmaSourceImportLimits::MAX_CHUNK_ROWS) {
            throw new RuntimeException('Lemma importer chunk rows must be between 1 and 200,000.');
        }
        $importerCommit = $this->optional_string($options, 'importer_commit', 'recorded-in-task-result');

        $tmpDir = $this->prepare_temp_directory(
            array_key_exists('tmp_dir', $options)
                ? $this->optional_string($options, 'tmp_dir', '')
                : null
        );
        $importComplete = false;
        $outputPrepared = false;
        try {
            $this->prepare_output_directory($outDir);
            $outputPrepared = true;
            $runtimeDir = $outDir . DIRECTORY_SEPARATOR . 'runtime';
            if (!mkdir($runtimeDir, 0777, true) && !is_dir($runtimeDir)) {
                throw new RuntimeException("Could not create runtime directory: {$runtimeDir}");
            }

            $physicalEvidence = WP_FTS_LemmaSourceImportLimits::source_physical_evidence(
                [$sourcePath],
                'Normalized lemma TSV'
            );
            $sourceBytes = $physicalEvidence['bytes'];
            $sourceSnapshot = $tmpDir . DIRECTORY_SEPARATOR . 'source.snapshot'
                . (str_ends_with(strtolower($sourcePath), '.gz') ? '.gz' : '');
            $hashedSource = WP_FTS_LemmaSourceImportLimits::snapshot_source_artifact(
                $sourcePath,
                $sourceSnapshot,
                $physicalEvidence['file_evidence'][$sourcePath],
                'Normalized lemma TSV'
            );
            $sourceSha = $hashedSource['sha256'];

            $stats = [
                'source_lines' => 0,
                'blank_lines' => 0,
                'comment_lines' => 0,
                'accepted_source_rows' => 0,
                'rows_with_tags' => 0,
                'rows_with_source_notes' => 0,
            ];
            $sourceDecodedBytes = 0;
            $normalizer = new WP_FTS_Normalizer();
            $chunkSet = new WP_FTS_LemmaChunkSet($tmpDir);
            $chunkNumber = 0;
            $pairs = [];
            $chunkLexicalBytes = 0;
            $maxChunkLexicalBytes = 0;

            $reader = $this->open_source($sourceSnapshot);
            try {
                while (($line = $this->read_source_line($reader)) !== false) {
                    WP_FTS_LemmaSourceImportLimits::account_decoded_line(
                        $line,
                        $stats['source_lines'],
                        $sourceDecodedBytes,
                        'Normalized lemma TSV'
                    );
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

                    $pair = $surface . "\t" . $lemma;
                    WP_FTS_LemmaPackLimits::assert_runtime_line_bytes(strlen($pair));
                    if (!isset($pairs[$pair])) {
                        $pairBytes = strlen($pair) + 1;
                        if (
                            $pairs !== []
                            && $chunkLexicalBytes + $pairBytes > WP_FTS_LemmaSourceImportLimits::MAX_CHUNK_LEXICAL_BYTES
                        ) {
                            $chunkSet->add($this->flush_chunk($pairs, $tmpDir, ++$chunkNumber));
                            $pairs = [];
                            $chunkLexicalBytes = 0;
                        }
                        $pairs[$pair] = true;
                        $chunkLexicalBytes += $pairBytes;
                        $maxChunkLexicalBytes = max($maxChunkLexicalBytes, $chunkLexicalBytes);
                    }
                    $stats['accepted_source_rows']++;
                    if (count($pairs) >= $chunkRows) {
                        $chunkSet->add($this->flush_chunk($pairs, $tmpDir, ++$chunkNumber));
                        $pairs = [];
                        $chunkLexicalBytes = 0;
                    }
                }
            } finally {
                $this->close_source($reader);
            }
            $stats['source_physical_bytes'] = $sourceBytes;
            $stats['source_physical_byte_limit'] = WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_PHYSICAL_BYTES;
            $stats['source_decoded_bytes'] = $sourceDecodedBytes;
            $stats['source_decoded_byte_limit'] = WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_DECODED_BYTES;
            $stats['source_line_limit'] = WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_LINES;

            if ($pairs !== []) {
                $chunkSet->add($this->flush_chunk($pairs, $tmpDir, ++$chunkNumber));
            }
            if ($chunkNumber === 0) {
                throw new RuntimeException('Source TSV did not yield any normalized runtime rows.');
            }
            $chunkPlan = $chunkSet->finish();
            $chunkFiles = $chunkPlan['files'];

            try {
                $merge = $this->merge_chunks($chunkFiles, $runtimeDir, $rowsPerFile);
            } catch (Throwable $error) {
                $this->remove_tree($runtimeDir);
                throw $error;
            }
            $runtimeFiles = $merge['files'];
            $runtimeRows = (int) $merge['rows'];
            $runtimeDigest = (string) $merge['sha256'];
            $runtimeDecodedBytes = (int) $merge['decoded_bytes'];
            $runtimeBytes = (int) $merge['encoded_bytes'];
            $lookupBytes = (int) $merge['lookup_bytes'];
            $lookupBlocks = (int) $merge['lookup_blocks'];
            $runtimeLookupBytes = $runtimeBytes + $lookupBytes;
            if ($runtimeLookupBytes > WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK) {
                $this->remove_tree($runtimeDir);
                throw new RuntimeException(
                    'Generated lemma runtime and lookup files exceed the 16 MiB per-pack limit.'
                );
            }
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
                    'unique_source_rows' => $merge['source_rows'],
                    'deduplicated_rows' => $stats['accepted_source_rows'] - $merge['source_rows'],
                    'ambiguous_surfaces' => $merge['ambiguous_surfaces'],
                    'unambiguous_surfaces' => $merge['unambiguous_surfaces'],
                    'ambiguity_noop_surfaces' => $merge['ambiguity_noop_surfaces'],
                    'ambiguity_noop_source_pairs' => $merge['ambiguity_noop_source_pairs'],
                    'chunk_files' => $chunkPlan['initial_files'],
                    'chunk_merge_outputs' => $chunkPlan['merge_outputs'],
                    'chunk_merge_passes' => $chunkPlan['merge_passes'],
                    'max_live_chunk_files' => $chunkPlan['max_live_files'],
                    'max_chunk_merge_inputs' => $chunkPlan['max_merge_inputs'],
                    'chunk_merge_fan_in_limit' => WP_FTS_LemmaChunkSet::MAX_MERGE_INPUTS,
                    'max_chunk_lexical_bytes' => $maxChunkLexicalBytes,
                    'chunk_lexical_byte_limit' => WP_FTS_LemmaSourceImportLimits::MAX_CHUNK_LEXICAL_BYTES,
                    'runtime_decoded_bytes' => $runtimeDecodedBytes,
                    'runtime_encoded_bytes' => $runtimeBytes,
                    'lookup_index_bytes' => $lookupBytes,
                    'lookup_blocks' => $lookupBlocks,
                    'runtime_lookup_bytes' => $runtimeLookupBytes,
                    'runtime_lookup_byte_limit' => WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK,
                ],
                'importer_commit' => $importerCommit,
                'rows_per_file' => $rowsPerFile,
                'chunk_rows' => $chunkRows,
            ]);
            $manifestPath = $outDir . DIRECTORY_SEPARATOR . 'manifest.json';
            $this->write_json($manifestPath, $manifest);

            $manifestSha = WP_FTS_LemmaPackLimits::hash_file_bounded(
                $manifestPath,
                WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES,
                'manifest_bytes',
                'Generated analyzer-pack manifest exceeds 64 KiB.'
            )['sha256'];

            $packBytes = $this->directory_bytes($outDir);

            $summary = [
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
                    'decoded_bytes' => $runtimeDecodedBytes,
                    'encoded_bytes' => $runtimeBytes,
                    'sha256' => $runtimeDigest,
                ],
                'lookup' => [
                    'format' => WP_FTS_LemmaPackLookupIndex::FORMAT,
                    'files' => count($runtimeFiles),
                    'blocks' => $lookupBlocks,
                    'bytes' => $lookupBytes,
                ],
                'runtime_lookup_bytes' => $runtimeLookupBytes,
                'pack_bytes' => $packBytes,
                'stats' => $manifest['source']['parse_stats'],
            ];
            $importComplete = true;

            return $summary;
        } finally {
            $this->remove_tree($tmpDir);
            if ($outputPrepared && !$importComplete) {
                $this->remove_tree($outDir);
            }
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
            if (!is_string($argv[$i])) {
                throw new RuntimeException('Importer arguments must be strings.');
            }
            $arg = $argv[$i];
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
                if (isset($argv[$i + 1]) && is_string($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) {
                    $value = $argv[++$i];
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
        if (!array_key_exists($key, $options) || !is_string($options[$key]) || trim($options[$key]) === '') {
            throw new RuntimeException("Missing required option --" . str_replace('_', '-', $key) . '.');
        }

        return $options[$key];
    }

    /** Reject options that are not part of the one importer contract. */
    private function assert_option_keys(array $options): void
    {
        $allowed = array_fill_keys(self::IMPORT_OPTION_KEYS, true);
        foreach (array_keys($options) as $key) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new RuntimeException('Lemma importer received an unsupported option.');
            }
        }
    }

    private function optional_string(array $options, string $key, string $default): string
    {
        if (!array_key_exists($key, $options)) {
            return $default;
        }
        if (!is_string($options[$key]) || trim($options[$key]) === '') {
            throw new RuntimeException('Importer option --' . str_replace('_', '-', $key) . ' must be a non-empty string.');
        }

        return $options[$key];
    }

    private function positive_integer_option(array $options, string $key, int $default): int
    {
        if (!array_key_exists($key, $options)) {
            return $default;
        }
        $value = $options[$key];
        if (is_string($value)) {
            if ($value === ''
                || strlen($value) > 20
                || strspn($value, '0123456789') !== strlen($value)
                || (strlen($value) > 1 && $value[0] === '0')
            ) {
                throw new RuntimeException('Importer option --' . str_replace('_', '-', $key) . ' must be a canonical positive integer.');
            }
            $value = (int) $value;
            if ((string) $value !== $options[$key]) {
                throw new RuntimeException('Importer option --' . str_replace('_', '-', $key) . ' exceeds the integer range.');
            }
        }
        if (!is_int($value) || $value <= 0) {
            throw new RuntimeException('Importer option --' . str_replace('_', '-', $key) . ' must be a canonical positive integer.');
        }

        return $value;
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

    /** Refuse caller-owned files, symlink roots, and non-empty pack targets. */
    private function prepare_output_directory(string $outDir): void
    {
        if (is_link($outDir)) {
            throw new RuntimeException("Output path must not be a symbolic link: {$outDir}");
        }
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

    private function prepare_temp_directory(?string $requested): string
    {
        $parent = sys_get_temp_dir();
        if ($requested !== null) {
            $parent = $requested;
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
        return WP_FTS_LemmaSourceImportLimits::read_line($reader, 'Normalized lemma TSV source');
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

    /** Enforce normalization, lexical shape, and namespaced storage width. */
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
        if (!WP_FTS_TermNamespace::term_key_fits($token, $language)) {
            throw new RuntimeException(
                "Source {$column} at row {$lineNumber} exceeds the 255-byte namespaced term-key limit for {$language}."
            );
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
            $encoded = $line . "\n";
            if (fwrite($handle, $encoded) !== strlen($encoded)) {
                fclose($handle);
                throw new RuntimeException("Could not write chunk file: {$path}");
            }
        }
        fclose($handle);

        return $path;
    }

    /**
     * @param string[] $chunkFiles
     * @return array{files:array<int,array<string,mixed>>,rows:int,source_rows:int,sha256:string,ambiguous_surfaces:int,unambiguous_surfaces:int,ambiguity_noop_surfaces:int,ambiguity_noop_source_pairs:int,decoded_bytes:int,encoded_bytes:int,lookup_bytes:int,lookup_blocks:int}
     */
    private function merge_chunks(array $chunkFiles, string $runtimeDir, int $rowsPerFile): array
    {
        $files = [];
        $runtimeDigest = hash_init('sha256');
        $previousPair = null;
        $currentSurface = null;
        $currentSurfacePairs = [];
        $currentSurfaceLemmaCount = 0;
        $ambiguousSurfaces = 0;
        $unambiguousSurfaces = 0;
        $ambiguityNoopSurfaces = 0;
        $ambiguityNoopSourcePairs = 0;
        $sourceRows = 0;
        $totalRows = 0;
        $shard = null;
        $encodedBytes = 0;
        $lookupBytes = 0;
        $lookupBlocks = 0;

        $closeShard = function () use (
            &$files,
            &$shard,
            &$encodedBytes,
            &$lookupBytes,
            &$lookupBlocks
        ): void {
            if ($shard === null) {
                return;
            }

            $file = $this->close_shard($shard);
            $shard = null;
            $files[] = $file;
            $encodedBytes += (int) $file['encoded_bytes'];
            $lookupBytes += (int) $file['lookup_bytes'];
            $lookupBlocks += (int) $file['lookup_blocks'];
            if (count($files) > WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_FILES) {
                throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                    'runtime_files',
                    'Generated lemma pack exceeds the 64-runtime-file limit.'
                );
            }
            if ($lookupBlocks > WP_FTS_Analyzer_Config_Limits::MAX_LOOKUP_BLOCKS_PER_PACK) {
                throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                    'lookup_blocks',
                    'Generated lemma pack exceeds the 8,192-block lookup limit.'
                );
            }
            if (
                $encodedBytes + $lookupBytes
                > WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK
            ) {
                throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                    'runtime_lookup_bytes',
                    'Generated lemma runtime and lookup files exceed the 16 MiB per-pack limit.'
                );
            }
        };

        $finishSurface = function () use (
            &$currentSurface,
            &$currentSurfacePairs,
            &$currentSurfaceLemmaCount,
            &$ambiguousSurfaces,
            &$unambiguousSurfaces,
            &$ambiguityNoopSurfaces,
            &$ambiguityNoopSourcePairs,
            &$files,
            &$shard,
            &$runtimeDigest,
            &$totalRows,
            $closeShard,
            $runtimeDir,
            $rowsPerFile
        ): void {
            if ($currentSurface === null) {
                return;
            }
            if ($currentSurfaceLemmaCount === 1) {
                $unambiguousSurfaces++;
            } else {
                $ambiguousSurfaces++;
            }

            // Search SQL can represent at most twelve alternatives for one
            // source token. Silently keeping a lexical first-twelve subset
            // would make results depend on truncation, so an over-cap source
            // surface compiles to one explicit identity row instead. The
            // source remains recognized while both indexing and querying use
            // the declared ambiguity no-op behavior.
            if ($currentSurfaceLemmaCount > WP_FTS_LemmaPackLimits::MAX_LEMMAS_PER_SURFACE) {
                $ambiguityNoopSurfaces++;
                $ambiguityNoopSourcePairs += $currentSurfaceLemmaCount;
                $currentSurfacePairs = [$currentSurface . "\t" . $currentSurface];
            }

            $surfaceRows = count($currentSurfacePairs);
            $surfaceBytes = array_sum(array_map(
                static fn(string $pair): int => strlen($pair) + 1,
                $currentSurfacePairs
            ));
            if (
                $shard !== null
                && (
                    $shard['rows'] >= $rowsPerFile
                    || !$this->surface_fits_lookup_shard($shard, $currentSurface, $surfaceBytes)
                )
            ) {
                $closeShard();
            }
            if ($shard === null) {
                $shard = $this->open_shard($runtimeDir, count($files) + 1);
            }
            $this->start_surface_in_lookup_shard($shard, $currentSurface, $surfaceRows, $surfaceBytes);
            foreach ($currentSurfacePairs as $pair) {
                $this->write_pair_to_shard($shard, $pair, $currentSurface);
                hash_update($runtimeDigest, $pair . "\n");
                $totalRows++;
            }
        };

        foreach (WP_FTS_LemmaChunkSet::unique_lines($chunkFiles) as $pair) {
                if ($pair === $previousPair) {
                    continue;
                }
                $previousPair = $pair;
                $sourceRows++;
                [$surface] = explode("\t", $pair, 2);
                if ($currentSurface !== $surface) {
                    $finishSurface();
                    $currentSurface = $surface;
                    $currentSurfacePairs = [];
                    $currentSurfaceLemmaCount = 0;
                }
                $currentSurfaceLemmaCount++;
                if ($currentSurfaceLemmaCount <= WP_FTS_LemmaPackLimits::MAX_LEMMAS_PER_SURFACE) {
                    $currentSurfacePairs[] = $pair;
                } elseif ($currentSurfaceLemmaCount === WP_FTS_LemmaPackLimits::MAX_LEMMAS_PER_SURFACE + 1) {
                    // Once the surface is known to be a no-op, discard the
                    // bounded candidate buffer and only count later source
                    // rows. This keeps merge memory independent of ambiguity.
                    $currentSurfacePairs = [];
                }
        }

        $finishSurface();
        $closeShard();
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
                        'compression' => $file['compression'],
                        'lookup' => [
                            'format' => $file['lookup']['format'],
                            'path' => 'runtime/' . basename((string) $file['lookup']['path']),
                            'sha256' => $file['lookup']['sha256'],
                            'blocks' => $file['lookup']['blocks'],
                        ],
                    ];

                    return $entry;
                },
                $files
            ),
            'rows' => $totalRows,
            'source_rows' => $sourceRows,
            'sha256' => hash_final($runtimeDigest),
            'ambiguous_surfaces' => $ambiguousSurfaces,
            'unambiguous_surfaces' => $unambiguousSurfaces,
            'ambiguity_noop_surfaces' => $ambiguityNoopSurfaces,
            'ambiguity_noop_source_pairs' => $ambiguityNoopSourcePairs,
            'decoded_bytes' => array_sum(array_column($files, 'decoded_bytes')),
            'encoded_bytes' => $encodedBytes,
            'lookup_bytes' => $lookupBytes,
            'lookup_blocks' => $lookupBlocks,
        ];
    }

    /**
     * @return array{path:string,handle:resource,compression:string,rows:int,decoded_bytes:int,lookup_blocks:int,lookup_block_rows:int,lookup_block_bytes:int,lookup_header_bytes:int,lookup_block_header_bytes:int,lookup_block_first_surface:?string,first_surface:?string,last_surface:?string}
     */
    private function open_shard(string $runtimeDir, int $number): array
    {
        if ($number > WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_FILES) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'runtime_files',
                'Generated lemma pack exceeds the 64-runtime-file limit.'
            );
        }
        $path = $runtimeDir . DIRECTORY_SEPARATOR . sprintf('%04d.tsv.gz', $number);
        $handle = gzopen($path, 'wb9');
        if (!is_resource($handle)) {
            throw new RuntimeException("Could not write runtime shard: {$path}");
        }

        return [
            'path' => $path,
            'handle' => $handle,
            'compression' => WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
            'rows' => 0,
            'decoded_bytes' => 0,
            'lookup_blocks' => 0,
            'lookup_block_rows' => 0,
            'lookup_block_bytes' => 0,
            'lookup_header_bytes' => WP_FTS_LemmaSourceImportLimits::lookup_header_base_bytes(),
            'lookup_block_header_bytes' => 0,
            'lookup_block_first_surface' => null,
            'first_surface' => null,
            'last_surface' => null,
        ];
    }

    /**
     * Keep each source surface in one lookup block, matching the sidecar builder.
     *
     * @param array{lookup_blocks:int,lookup_block_rows:int,lookup_block_bytes:int,lookup_header_bytes:int,lookup_block_header_bytes:int,lookup_block_first_surface:?string} $shard
     */
    private function surface_fits_lookup_shard(array $shard, string $surface, int $surfaceBytes): bool
    {
        if ($surfaceBytes > WP_FTS_LemmaPackLookupIndex::MAX_BLOCK_DECODED_BYTES) {
            return false;
        }
        $startsNewBlock = $shard['lookup_block_rows'] === 0
            || $shard['lookup_block_rows'] >= WP_FTS_LemmaPackLookupIndex::DEFAULT_BLOCK_ROWS
            || $shard['lookup_block_bytes'] + $surfaceBytes > WP_FTS_LemmaPackLookupIndex::MAX_BLOCK_DECODED_BYTES;
        if ($startsNewBlock && $shard['lookup_blocks'] >= WP_FTS_Analyzer_Config_Limits::MAX_LOOKUP_BLOCKS_PER_FILE) {
            return false;
        }
        if ($startsNewBlock) {
            $candidateHeaderBytes = $shard['lookup_header_bytes']
                + ($shard['lookup_blocks'] > 0 ? 1 : 0)
                + WP_FTS_LemmaSourceImportLimits::lookup_block_header_bytes($surface, $surface);
        } else {
            $candidateHeaderBytes = $shard['lookup_header_bytes']
                - $shard['lookup_block_header_bytes']
                + WP_FTS_LemmaSourceImportLimits::lookup_block_header_bytes(
                    (string) $shard['lookup_block_first_surface'],
                    $surface
                );
        }

        return $candidateHeaderBytes <= WP_FTS_LemmaPackLookupIndex::MAX_HEADER_BYTES;
    }

    /**
     * @param array{lookup_blocks:int,lookup_block_rows:int,lookup_block_bytes:int,lookup_header_bytes:int,lookup_block_header_bytes:int,lookup_block_first_surface:?string} $shard
     */
    private function start_surface_in_lookup_shard(array &$shard, string $surface, int $surfaceRows, int $surfaceBytes): void
    {
        if ($surfaceBytes > WP_FTS_LemmaPackLookupIndex::MAX_BLOCK_DECODED_BYTES) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'lookup_block_decoded_bytes',
                'One generated lemma surface exceeds the 16 KiB decoded lookup-block limit.'
            );
        }
        if (
            $shard['lookup_block_rows'] === 0
            || $shard['lookup_block_rows'] >= WP_FTS_LemmaPackLookupIndex::DEFAULT_BLOCK_ROWS
            || $shard['lookup_block_bytes'] + $surfaceBytes > WP_FTS_LemmaPackLookupIndex::MAX_BLOCK_DECODED_BYTES
        ) {
            $shard['lookup_blocks']++;
            $shard['lookup_block_rows'] = 0;
            $shard['lookup_block_bytes'] = 0;
            $shard['lookup_block_first_surface'] = $surface;
            $shard['lookup_block_header_bytes'] = WP_FTS_LemmaSourceImportLimits::lookup_block_header_bytes($surface, $surface);
            $shard['lookup_header_bytes'] += ($shard['lookup_blocks'] > 1 ? 1 : 0)
                + $shard['lookup_block_header_bytes'];
        } else {
            $updatedBlockHeaderBytes = WP_FTS_LemmaSourceImportLimits::lookup_block_header_bytes(
                (string) $shard['lookup_block_first_surface'],
                $surface
            );
            $shard['lookup_header_bytes'] += $updatedBlockHeaderBytes - $shard['lookup_block_header_bytes'];
            $shard['lookup_block_header_bytes'] = $updatedBlockHeaderBytes;
        }
        if ($shard['lookup_blocks'] > WP_FTS_Analyzer_Config_Limits::MAX_LOOKUP_BLOCKS_PER_FILE) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'lookup_blocks',
                'Generated lemma runtime shard exceeds the 256-block lookup limit.'
            );
        }
        if ($shard['lookup_header_bytes'] > WP_FTS_LemmaPackLookupIndex::MAX_HEADER_BYTES) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'lookup_header_bytes',
                'Generated lemma lookup header exceeds the 64 KiB per-file limit.'
            );
        }

        $shard['lookup_block_rows'] += $surfaceRows;
        $shard['lookup_block_bytes'] += $surfaceBytes;
    }

    /**
     * @param array{path:string,handle:resource,compression:string,rows:int,decoded_bytes:int,lookup_blocks:int,lookup_block_rows:int,lookup_block_bytes:int,lookup_header_bytes:int,lookup_block_header_bytes:int,lookup_block_first_surface:?string,first_surface:?string,last_surface:?string} $shard
     */
    private function write_pair_to_shard(array &$shard, string $pair, string $surface): void
    {
        $line = $pair . "\n";
        $written = gzwrite($shard['handle'], $line);
        if ($written !== strlen($line)) {
            throw new RuntimeException("Could not write runtime shard: {$shard['path']}.");
        }
        $shard['rows']++;
        $shard['decoded_bytes'] += strlen($line);
        $shard['first_surface'] ??= $surface;
        $shard['last_surface'] = $surface;
    }

    /**
     * @param array{path:string,handle:resource,compression:string,rows:int,decoded_bytes:int,lookup_blocks:int,lookup_block_rows:int,lookup_block_bytes:int,lookup_header_bytes:int,lookup_block_header_bytes:int,lookup_block_first_surface:?string,first_surface:?string,last_surface:?string} $shard
     * @return array{path:string,sha256:string,rows:int,decoded_bytes:int,encoded_bytes:int,lookup_bytes:int,lookup_blocks:int,first_surface:string,last_surface:string,compression:string,lookup:array<string,mixed>}
     */
    private function close_shard(array $shard): array
    {
        gzclose($shard['handle']);
        if (!is_string($shard['first_surface']) || !is_string($shard['last_surface'])) {
            throw new RuntimeException('Cannot close an empty runtime shard.');
        }

        $sha = WP_FTS_LemmaPackLimits::hash_file_bounded(
            $shard['path'],
            WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK,
            'runtime_lookup_bytes',
            'Generated analyzer-pack runtime exceeds the 16 MiB physical pack limit.'
        )['sha256'];

        $file = [
            'path' => $shard['path'],
            'sha256' => $sha,
            'rows' => $shard['rows'],
            'decoded_bytes' => $shard['decoded_bytes'],
            'first_surface' => $shard['first_surface'],
            'last_surface' => $shard['last_surface'],
            'compression' => WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
        ];
        $lookupPath = $shard['path'] . '.lookup';
        $lookup = WP_FTS_LemmaPackLookupIndex::build(
            $shard['path'],
            $sha,
            $lookupPath
        );
        if ((int) $lookup['blocks'] !== $shard['lookup_blocks']) {
            throw new RuntimeException("Lookup block planning changed for {$shard['path']}.");
        }
        $file['sha256'] = $lookup['runtime_sha256'];
        $file['lookup'] = ['path' => $lookupPath] + $lookup;

        $encodedBytes = filesize($shard['path']);
        if (!is_int($encodedBytes)) {
            throw new RuntimeException("Could not measure runtime shard: {$shard['path']}.");
        }
        $lookupBytes = filesize((string) $file['lookup']['path']);
        if (!is_int($lookupBytes)) {
            throw new RuntimeException("Could not measure lookup sidecar for {$shard['path']}.");
        }
        $file['encoded_bytes'] = $encodedBytes;
        $file['lookup_bytes'] = $lookupBytes;
        $file['lookup_blocks'] = (int) $file['lookup']['blocks'];

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
            'compressed-runtime-files',
            'indexed-runtime-lookups',
        ];
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
                    (string) $data['license']
                ),
                'no_runtime_network_access' => true,
                'no_full_third_party_dictionary_dump' => false,
                'full_third_party_dictionary_dump_generated' => true,
                'rows_per_file' => $data['rows_per_file'],
                'chunk_rows' => $data['chunk_rows'],
            ],
        ];
    }

    private function canonical_importer_command(
        string $language,
        string $packId,
        string $version,
        string $sourceUrl,
        string $license
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

    /** Remove one owned tree while unlinking, never following, symlinks. */
    private function remove_tree(string $directory): void
    {
        if (is_link($directory)) {
            unlink($directory);
            return;
        }
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path) {
            if ($path->isLink() || !$path->isDir()) {
                unlink($path->getPathname());
            } else {
                rmdir($path->getPathname());
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
