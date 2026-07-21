<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/lemma-source-import-limits.php';
require_once __DIR__ . '/lemma-chunk-merge.php';

/**
 * Deterministically imports PoliMorf-style five-column TSV data into a local
 * Polish lemma analyzer pack.
 */
final class WP_FTS_PolishPolimorfImporter
{
    private const RUNTIME_FORMAT = WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV;
    private const SOURCE_LOCK_SCHEMA = 'wp-fts-polish-polimorf-source-lock/v1';
    private const MAX_NOTICE_METADATA_LINES = 64;
    private const MAX_NOTICE_METADATA_BYTES = 65536;
    public const IMPORT_OPTION_KEYS = [
        'source',
        'out',
        'pack_id',
        'version',
        'source_url',
        'max_rows_per_file',
        'chunk_rows',
        'source_name',
        'source_version',
        'source_retrieval_note',
        'importer_commit',
        'tmp_dir',
    ];
    private const CLI_INTEGER_OPTION_KEYS = [
        'max-rows-per-file',
        'chunk-rows',
    ];
    private const CLI_VALUE_OPTION_KEYS = [
        'source',
        'out',
        'pack-id',
        'version',
        'source-url',
        'source-name',
        'source-version',
        'source-retrieval-note',
        'importer-commit',
        'tmp-dir',
        ...self::CLI_INTEGER_OPTION_KEYS,
    ];
    private const STRING_OPTION_KEYS = [
        'source',
        'out',
        'pack_id',
        'version',
        'source_url',
        'source_name',
        'source_version',
        'source_retrieval_note',
        'importer_commit',
        'tmp_dir',
    ];
    private const PATH_OPTION_KEYS = [
        'source',
        'out',
        'tmp_dir',
    ];

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function import(array $options): array
    {
        $this->assert_option_shapes($options);
        if (
            !function_exists('gzopen')
            || !function_exists('gzwrite')
            || !function_exists('gzclose')
            || !function_exists('gzencode')
            || !function_exists('gzdecode')
        ) {
            throw new RuntimeException('PoliMorf runtime pack generation requires PHP zlib gzip support.');
        }

        $sourcePath = $this->required_path($options, 'source');
        $outDir = $this->required_path_string($options, 'out');
        WP_FTS_LemmaSourceImportLimits::assert_source_output_separate($sourcePath, $outDir, 'PoliMorf');
        $packId = $this->optional_string($options, 'pack_id', 'pl-polimorf-20180722-full');
        $version = $this->optional_string($options, 'version', '2018.07.22-import-v1');
        $sourceUrl = $this->optional_string(
            $options,
            'source_url',
            'https://clarin-pl.eu/dspace/bitstream/handle/11321/577/polimorf-20180722.tab.gz?isAllowed=y&sequence=1'
        );
        $rowsPerFile = $this->bounded_positive_integer_option($options, 'max_rows_per_file', 100000);
        $chunkRows = $this->bounded_positive_integer_option(
            $options,
            'chunk_rows',
            WP_FTS_LemmaSourceImportLimits::MAX_CHUNK_ROWS
        );
        $sourceName = $this->optional_string($options, 'source_name', 'PoliMorf Polish morphological dictionary');
        $sourceVersion = $this->optional_string($options, 'source_version', '2018.07.22');
        $retrievalNote = $this->optional_string(
            $options,
            'source_retrieval_note',
            'Source bytes are locked by URL, SHA-256, and byte count; retrieval timestamp is recorded outside deterministic importer output.'
        );
        $importerCommit = $this->optional_string($options, 'importer_commit', 'recorded-in-task-result');

        $tmpParent = array_key_exists('tmp_dir', $options)
            ? $this->required_path_string($options, 'tmp_dir')
            : null;
        $tmpDir = $this->prepare_temp_directory($tmpParent);
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
                'PoliMorf'
            );
            $sourceBytes = $physicalEvidence['bytes'];
            $sourceSnapshot = $tmpDir . DIRECTORY_SEPARATOR . 'source.snapshot'
                . (str_ends_with(strtolower($sourcePath), '.gz') ? '.gz' : '');
            $hashedSource = WP_FTS_LemmaSourceImportLimits::snapshot_source_artifact(
                $sourcePath,
                $sourceSnapshot,
                $physicalEvidence['file_evidence'][$sourcePath],
                'PoliMorf'
            );
            $sourceSha = $hashedSource['sha256'];

            $normalizer = new WP_FTS_Normalizer();
            $stats = [
                'source_lines' => 0,
                'metadata_lines' => 0,
                'lexical_rows' => 0,
                'invalid_column_rows' => 0,
                'skipped_invalid_tokens' => 0,
                'accepted_source_rows' => 0,
            ];
            $sourceDecodedBytes = 0;
            $noticeLines = [];
            $noticeBytes = 0;
            $chunkSet = new WP_FTS_LemmaChunkSet($tmpDir);
            $chunkNumber = 0;
            $pairs = [];
            $chunkLexicalBytes = 0;
            $maxChunkLexicalBytes = 0;
            $seenLexicalRows = false;

            $reader = $this->open_source($sourceSnapshot);
            try {
                while (($line = $this->read_source_line($reader)) !== false) {
                    WP_FTS_LemmaSourceImportLimits::account_decoded_line(
                        $line,
                        $stats['source_lines'],
                        $sourceDecodedBytes,
                        'PoliMorf'
                    );
                    $line = rtrim((string) $line, "\n");
                    $line = rtrim($line, "\r");
                    $columns = explode("\t", $line);
                    if (count($columns) !== 5) {
                        if (!$seenLexicalRows) {
                            $stats['metadata_lines']++;
                            $encodedNoticeBytes = strlen($line) + 1;
                            if (
                                count($noticeLines) >= self::MAX_NOTICE_METADATA_LINES
                                || $noticeBytes + $encodedNoticeBytes > self::MAX_NOTICE_METADATA_BYTES
                            ) {
                                throw new RuntimeException(
                                    'PoliMorf source metadata retained for NOTICE.txt exceeds '
                                    . '64 lines or 64 KiB.'
                                );
                            }
                            $noticeLines[] = $line;
                            $noticeBytes += $encodedNoticeBytes;
                        } else {
                            $stats['invalid_column_rows']++;
                        }
                        continue;
                    }

                    $seenLexicalRows = true;
                    $stats['lexical_rows']++;
                    $surface = $normalizer->normalize_token($columns[0], 'pl');
                    $lemma = $normalizer->normalize_token($columns[1], 'pl');
                    if (!$this->is_runtime_token($surface, 'pl') || !$this->is_runtime_token($lemma, 'pl')) {
                        $stats['skipped_invalid_tokens']++;
                        continue;
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
            $stats['notice_metadata_bytes'] = $noticeBytes;
            $stats['notice_metadata_line_limit'] = self::MAX_NOTICE_METADATA_LINES;
            $stats['notice_metadata_byte_limit'] = self::MAX_NOTICE_METADATA_BYTES;

            if ($pairs !== []) {
                $chunkSet->add($this->flush_chunk($pairs, $tmpDir, ++$chunkNumber));
            }
            if ($chunkNumber === 0) {
                throw new RuntimeException('Source did not yield any normalized runtime rows.');
            }
            $chunkPlan = $chunkSet->finish();
            $chunkFiles = $chunkPlan['files'];
            $stats['chunk_files'] = $chunkPlan['initial_files'];
            $stats['chunk_merge_outputs'] = $chunkPlan['merge_outputs'];
            $stats['chunk_merge_passes'] = $chunkPlan['merge_passes'];
            $stats['max_live_chunk_files'] = $chunkPlan['max_live_files'];
            $stats['max_chunk_merge_inputs'] = $chunkPlan['max_merge_inputs'];
            $stats['chunk_merge_fan_in_limit'] = WP_FTS_LemmaChunkSet::MAX_MERGE_INPUTS;
            $stats['max_chunk_lexical_bytes'] = $maxChunkLexicalBytes;
            $stats['chunk_lexical_byte_limit'] = WP_FTS_LemmaSourceImportLimits::MAX_CHUNK_LEXICAL_BYTES;

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
                throw new RuntimeException('Generated PoliMorf runtime and lookup files exceed the 16 MiB per-pack limit.');
            }
            $stats['runtime_decoded_bytes'] = $runtimeDecodedBytes;
            $stats['runtime_encoded_bytes'] = $runtimeBytes;
            $stats['lookup_index_bytes'] = $lookupBytes;
            $stats['lookup_blocks'] = $lookupBlocks;
            $stats['runtime_lookup_bytes'] = $runtimeLookupBytes;
            $stats['runtime_lookup_byte_limit'] = WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK;
            $stats['ambiguous_surfaces'] = (int) $merge['ambiguous_surfaces'];
            $stats['unambiguous_surfaces'] = (int) $merge['unambiguous_surfaces'];
            $stats['ambiguity_noop_surfaces'] = (int) $merge['ambiguity_noop_surfaces'];
            $stats['ambiguity_noop_source_pairs'] = (int) $merge['ambiguity_noop_source_pairs'];

            $noticePath = $outDir . DIRECTORY_SEPARATOR . 'NOTICE.txt';
            $this->write_text($noticePath, $this->build_notice($sourceName, $sourceVersion, $sourceUrl, $sourceSha, $sourceBytes, $noticeLines));

            $manifest = $this->build_manifest([
                'pack_id' => $packId,
                'version' => $version,
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

            $manifestSha = WP_FTS_LemmaPackLimits::hash_file_bounded(
                $manifestPath,
                WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES,
                'manifest_bytes',
                'Generated analyzer-pack manifest exceeds 64 KiB.'
            )['sha256'];
            $sourceLock = $this->build_source_lock(
                $manifest,
                $manifestSha,
                $runtimeDecodedBytes,
                $runtimeBytes,
                $lookupBytes,
                $importerCommit
            );
            $sourceLockPath = $outDir . DIRECTORY_SEPARATOR . 'SOURCE.lock.json';
            $this->write_json($sourceLockPath, $sourceLock);

            $packBytes = $this->directory_bytes($outDir);

            $summary = [
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
                'stats' => $stats,
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
        if (!array_is_list($argv)) {
            throw new RuntimeException('PoliMorf importer arguments must be a list of strings.');
        }

        $valueOptions = array_fill_keys(self::CLI_VALUE_OPTION_KEYS, true);
        $integerOptions = array_fill_keys(self::CLI_INTEGER_OPTION_KEYS, true);
        $options = [];
        for ($i = 0, $count = count($argv); $i < $count; $i++) {
            if (!is_string($argv[$i])) {
                throw new RuntimeException('PoliMorf importer arguments must be strings.');
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
            if (!isset($valueOptions[$key])) {
                throw new RuntimeException("Unsupported PoliMorf importer option --{$key}.");
            }
            if ($value === true) {
                throw new RuntimeException("PoliMorf importer option --{$key} requires a value.");
            }
            if (isset($integerOptions[$key])) {
                $value = self::parse_cli_positive_integer($key, $value);
            }

            $optionKey = str_replace('-', '_', $key);
            if (array_key_exists($optionKey, $options)) {
                throw new RuntimeException("PoliMorf importer option --{$key} was supplied more than once.");
            }
            $options[$optionKey] = $value;
        }

        return $options;
    }

    private static function parse_cli_positive_integer(string $key, mixed $value): int
    {
        if (!is_string($value)
            || $value === ''
            || strspn($value, '0123456789') !== strlen($value)
            || $value[0] === '0'
        ) {
            throw new RuntimeException("PoliMorf importer option --{$key} must be a canonical positive integer.");
        }

        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)
        ) {
            throw new RuntimeException("PoliMorf importer option --{$key} exceeds the integer range.");
        }

        $integer = (int) $value;
        if ((string) $integer !== $value) {
            throw new RuntimeException("PoliMorf importer option --{$key} exceeds the integer range.");
        }

        return $integer;
    }

    /**
     * @param array<string,mixed> $options
     */
    private function required_path(array $options, string $key): string
    {
        $path = $this->required_path_string($options, $key);
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
        if (
            !array_key_exists($key, $options)
            || !is_string($options[$key])
            || $options[$key] === ''
            || trim($options[$key]) !== $options[$key]
        ) {
            throw new RuntimeException("Missing required option --" . str_replace('_', '-', $key) . '.');
        }

        return $options[$key];
    }

    /** Reject options outside the one deterministic PoliMorf import contract. */
    private function assert_option_keys(array $options): void
    {
        $allowed = array_fill_keys(self::IMPORT_OPTION_KEYS, true);
        foreach (array_keys($options) as $key) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new RuntimeException('PoliMorf importer received an unsupported option.');
            }
        }
    }

    /** Validate the complete option bag before probing extensions or paths. */
    private function assert_option_shapes(array $options): void
    {
        $this->assert_option_keys($options);
        $this->required_string($options, 'source');
        $this->required_string($options, 'out');

        foreach (self::STRING_OPTION_KEYS as $key) {
            if (array_key_exists($key, $options)) {
                $this->required_string($options, $key);
            }
        }
        foreach (self::PATH_OPTION_KEYS as $key) {
            if (!array_key_exists($key, $options)) {
                continue;
            }
            $path = $options[$key];
            if (strlen($path) > WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_PATH_BYTES || str_contains($path, "\0")) {
                throw new RuntimeException(
                    'PoliMorf importer option --' . str_replace('_', '-', $key)
                    . ' must be a path of at most '
                    . number_format(WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_PATH_BYTES)
                    . ' bytes without null bytes.'
                );
            }
        }
        foreach (['max_rows_per_file', 'chunk_rows'] as $key) {
            if (array_key_exists($key, $options)) {
                $this->bounded_positive_integer_option($options, $key, 1);
            }
        }
    }

    private function optional_string(array $options, string $key, string $default): string
    {
        if (!array_key_exists($key, $options)) {
            return $default;
        }

        return $this->required_string($options, $key);
    }

    private function bounded_positive_integer_option(array $options, string $key, int $default): int
    {
        if (!array_key_exists($key, $options)) {
            return $default;
        }
        $value = $options[$key];
        if (!is_int($value) || $value < 1 || $value > WP_FTS_LemmaSourceImportLimits::MAX_CHUNK_ROWS) {
            throw new RuntimeException(
                'PoliMorf importer option --' . str_replace('_', '-', $key)
                . ' must be an integer between 1 and '
                . number_format(WP_FTS_LemmaSourceImportLimits::MAX_CHUNK_ROWS)
                . '.'
            );
        }

        return $value;
    }

    private function required_path_string(array $options, string $key): string
    {
        $path = $this->required_string($options, $key);
        if (strlen($path) > WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_PATH_BYTES || str_contains($path, "\0")) {
            throw new RuntimeException(
                'PoliMorf importer option --' . str_replace('_', '-', $key)
                . ' must be a path of at most '
                . number_format(WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_PATH_BYTES)
                . ' bytes without null bytes.'
            );
        }

        return $path;
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

    /** Create a unique owned child beneath the optional caller-owned parent. */
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
            $tmpDir = $parent . DIRECTORY_SEPARATOR . 'wp-fts-polimorf-import-' . getmypid() . '-' . bin2hex(random_bytes(8));
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
        return WP_FTS_LemmaSourceImportLimits::read_line($reader, 'PoliMorf source');
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

    /** Accept one lexical token only when its namespaced key fits storage. */
    private function is_runtime_token(string $token, string $language): bool
    {
        if ($token === '' || strpbrk($token, " \t\r\n") !== false || str_contains($token, WP_FTS_TermNamespace::SEPARATOR)) {
            return false;
        }

        return preg_match('/^[\p{L}\p{M}\p{N}_]+$/u', $token) === 1
            && WP_FTS_TermNamespace::term_key_fits($token, $language);
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
     * @return array{files:array<int,array<string,mixed>>,rows:int,sha256:string,ambiguous_surfaces:int,unambiguous_surfaces:int,ambiguity_noop_surfaces:int,ambiguity_noop_source_pairs:int,decoded_bytes:int,encoded_bytes:int,lookup_bytes:int,lookup_blocks:int}
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
                    'Generated PoliMorf pack exceeds the 64-runtime-file limit.'
                );
            }
            if ($lookupBlocks > WP_FTS_Analyzer_Config_Limits::MAX_LOOKUP_BLOCKS_PER_PACK) {
                throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                    'lookup_blocks',
                    'Generated PoliMorf pack exceeds the 8,192-block lookup limit.'
                );
            }
            if (
                $encodedBytes + $lookupBytes
                > WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK
            ) {
                throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                    'runtime_lookup_bytes',
                    'Generated PoliMorf runtime and lookup files exceed the 16 MiB per-pack limit.'
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
                static fn(array $file): array => [
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
                ],
                $files
            ),
            'rows' => $totalRows,
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
     * @return array{path:string,handle:resource,rows:int,decoded_bytes:int,lookup_blocks:int,lookup_block_rows:int,lookup_block_bytes:int,lookup_header_bytes:int,lookup_block_header_bytes:int,lookup_block_first_surface:?string,first_surface:?string,last_surface:?string}
     */
    private function open_shard(string $runtimeDir, int $number): array
    {
        if ($number > WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_FILES) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'runtime_files',
                'Generated PoliMorf pack exceeds the 64-runtime-file limit.'
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
                'One generated PoliMorf surface exceeds the 16 KiB decoded lookup-block limit.'
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
                'Generated PoliMorf runtime shard exceeds the 256-block lookup limit.'
            );
        }
        if ($shard['lookup_header_bytes'] > WP_FTS_LemmaPackLookupIndex::MAX_HEADER_BYTES) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'lookup_header_bytes',
                'Generated PoliMorf lookup header exceeds the 64 KiB per-file limit.'
            );
        }

        $shard['lookup_block_rows'] += $surfaceRows;
        $shard['lookup_block_bytes'] += $surfaceBytes;
    }

    /**
     * @param array{path:string,handle:resource,rows:int,decoded_bytes:int,lookup_blocks:int,lookup_block_rows:int,lookup_block_bytes:int,lookup_header_bytes:int,lookup_block_header_bytes:int,lookup_block_first_surface:?string,first_surface:?string,last_surface:?string} $shard
     */
    private function write_pair_to_shard(array &$shard, string $pair, string $surface): void
    {
        $line = $pair . "\n";
        if (gzwrite($shard['handle'], $line) !== strlen($line)) {
            throw new RuntimeException("Could not write compressed runtime shard: {$shard['path']}");
        }
        $shard['rows']++;
        $shard['decoded_bytes'] += strlen($line);
        $shard['first_surface'] ??= $surface;
        $shard['last_surface'] = $surface;
    }

    /**
     * @param array{path:string,handle:resource,rows:int,decoded_bytes:int,lookup_blocks:int,lookup_block_rows:int,lookup_block_bytes:int,lookup_header_bytes:int,lookup_block_header_bytes:int,lookup_block_first_surface:?string,first_surface:?string,last_surface:?string} $shard
     * @return array{path:string,sha256:string,rows:int,decoded_bytes:int,encoded_bytes:int,lookup_bytes:int,lookup_blocks:int,first_surface:string,last_surface:string,compression:string,lookup:array{format:string,path:string,sha256:string,blocks:int}}
     */
    private function close_shard(array $shard): array
    {
        gzclose($shard['handle']);
        if (!is_string($shard['first_surface']) || !is_string($shard['last_surface'])) {
            throw new RuntimeException('Cannot close an empty runtime shard.');
        }

        $runtimeSha256 = WP_FTS_LemmaPackLimits::hash_file_bounded(
            $shard['path'],
            WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK,
            'runtime_lookup_bytes',
            'Generated analyzer-pack runtime exceeds the 16 MiB physical pack limit.'
        )['sha256'];
        $lookupPath = $shard['path'] . '.lookup';
        $lookup = WP_FTS_LemmaPackLookupIndex::build(
            $shard['path'],
            $runtimeSha256,
            $lookupPath
        );
        if ((int) $lookup['rows'] !== (int) $shard['rows']) {
            throw new RuntimeException("Indexed runtime row count changed for {$shard['path']}.");
        }
        if ((int) $lookup['blocks'] !== $shard['lookup_blocks']) {
            throw new RuntimeException("Lookup block planning changed for {$shard['path']}.");
        }
        $encodedBytes = filesize($shard['path']);
        $lookupBytes = filesize($lookupPath);
        if (!is_int($encodedBytes) || !is_int($lookupBytes)) {
            throw new RuntimeException("Could not measure indexed runtime files for {$shard['path']}.");
        }

        return [
            'path' => $shard['path'],
            'sha256' => $lookup['runtime_sha256'],
            'rows' => $shard['rows'],
            'decoded_bytes' => $shard['decoded_bytes'],
            'encoded_bytes' => $encodedBytes,
            'lookup_bytes' => $lookupBytes,
            'lookup_blocks' => (int) $lookup['blocks'],
            'first_surface' => $shard['first_surface'],
            'last_surface' => $shard['last_surface'],
            'compression' => WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
            'lookup' => [
                'format' => $lookup['format'],
                'path' => $lookupPath,
                'sha256' => $lookup['sha256'],
                'blocks' => $lookup['blocks'],
            ],
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
            'capabilities' => [
                'dictionary-lemmatizer',
                'ambiguous-form-noop',
                'normalized-runtime-rows',
                'sharded-runtime-files',
                'compressed-runtime-files',
                'indexed-runtime-lookups',
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
                'importer_command' => $this->canonical_importer_command((string) $data['pack_id'], (string) $data['version'], (string) $data['source_url']),
                'no_runtime_network_access' => true,
                'no_full_third_party_dictionary_dump' => false,
                'full_third_party_dictionary_dump_generated' => true,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private function build_source_lock(
        array $manifest,
        string $manifestSha,
        int $runtimeDecodedBytes,
        int $runtimeBytes,
        int $lookupBytes,
        string $importerCommit
    ): array
    {
        return [
            'schema_version' => self::SOURCE_LOCK_SCHEMA,
            'pack' => [
                'id' => $manifest['pack_id'],
                'language' => 'pl',
                'kind' => 'lemmatizer',
                'runtime_pack_committed' => false,
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
                'decoded_byte_count' => $runtimeDecodedBytes,
                'encoded_byte_count' => $runtimeBytes,
                'compressed_byte_count' => $runtimeBytes,
                'compression' => WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
                'lookup_index_format' => WP_FTS_LemmaPackLookupIndex::FORMAT,
                'lookup_index_file_count' => count($manifest['runtime']['files']),
                'lookup_index_byte_count' => $lookupBytes,
                'runtime_lookup_byte_count' => $runtimeBytes + $lookupBytes,
                'runtime_lookup_byte_limit' => WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK,
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
                'claim_boundary' => 'Full PoliMorf-derived Polish lemmatizer only. Runtime pack activates only through plugin configuration and is not committed unless packaging review approves the generated third-party data size.',
            ],
        ];
    }

    private function canonical_importer_command(string $packId, string $version, string $sourceUrl): string
    {
        $parts = [
            'php indexer/tools/import-polish-polimorf-lemmatizer.php',
            '--source=<artifact>',
            '--out=<pack-dir>',
            '--pack-id=' . $packId,
            '--version=' . $version,
            '--source-url=' . $sourceUrl,
        ];
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
        $options = WP_FTS_PolishPolimorfImporter::parse_cli_options(array_slice($argv, 1));
        $summary = (new WP_FTS_PolishPolimorfImporter())->import($options);
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Polish PoliMorf import failed: {$e->getMessage()}\n");
        exit(1);
    }
}
