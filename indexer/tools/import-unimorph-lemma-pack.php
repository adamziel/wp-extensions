<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/import-lemma-tsv-pack.php';

/**
 * Converts source-approved UniMorph-style lemma/form/feature rows into the
 * normalized lemma TSV importer contract, then delegates analyzer-pack
 * generation to that importer.
 */
final class WP_FTS_UnimorphLemmaPackImporter
{
    private const SOURCE_OPTION_KEYS = [
        'source_repo_url',
        'source_commit',
        'source_file_path',
        'license_record_path',
        'license_record_sha256',
    ];

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function import(array $options): array
    {
        $this->assert_option_keys($options);
        $sourcePath = $this->required_source_path($options, 'source');
        $outputPath = $this->required_output_path($options);
        WP_FTS_LemmaSourceImportLimits::assert_source_output_separate($sourcePath, $outputPath, 'UniMorph');
        $language = $this->required_language($options, 'language');
        $tmpDir = $this->prepare_temp_directory(
            array_key_exists('tmp_dir', $options) ? $this->required_string($options, 'tmp_dir') : null
        );
        $packDir = null;
        $importComplete = false;
        try {
            $normalizedTsv = $tmpDir . DIRECTORY_SEPARATOR . 'normalized-lemma.tsv';
            $staging = $this->write_normalized_tsv($sourcePath, $normalizedTsv, $language);
            $stats = $staging['stats'];
            if ((int) $stats['accepted_rows'] < 1) {
                throw new RuntimeException('UniMorph source did not yield any normalized runtime rows.');
            }

            $tsvOptions = array_intersect_key(
                $options,
                array_fill_keys(WP_FTS_LemmaTsvPackImporter::IMPORT_OPTION_KEYS, true)
            );
            $tsvOptions['source'] = $normalizedTsv;
            $tsvOptions['language'] = $language;
            $summary = (new WP_FTS_LemmaTsvPackImporter())->import($tsvOptions);
            $manifestPath = is_string($summary['manifest'] ?? null) ? $summary['manifest'] : '';
            if ($manifestPath !== '') {
                $packDir = dirname($manifestPath);
            }
            $summary = $this->rewrite_pack_metadata_for_unimorph_source(
                $summary,
                $options,
                $sourcePath,
                $language,
                $stats,
                $staging['source_evidence']
            );
            $summary['unimorph'] = $stats;
            $importComplete = true;

            return $summary;
        } finally {
            $this->remove_tree($tmpDir);
            if (!$importComplete && is_string($packDir)) {
                $this->remove_tree($packDir);
            }
        }
    }

    /**
     * @param string[] $argv
     * @return array<string,mixed>
     */
    public static function parse_cli_options(array $argv): array
    {
        return WP_FTS_LemmaTsvPackImporter::parse_cli_options($argv);
    }

    /**
     * @param array<string,mixed> $options
     */
    private function required_source_path(array $options, string $key): string
    {
        $path = $this->required_string($options, $key);
        if (!is_file($path) && !is_dir($path)) {
            throw new RuntimeException("Required path --{$key} does not exist: {$path}");
        }

        return $path;
    }

    /** @param array<string,mixed> $options */
    private function required_output_path(array $options): string
    {
        return $this->required_string($options, 'out');
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

    /** Reject inputs outside the normalized TSV contract and UniMorph source metadata. */
    private function assert_option_keys(array $options): void
    {
        $allowed = array_fill_keys([
            ...WP_FTS_LemmaTsvPackImporter::IMPORT_OPTION_KEYS,
            ...self::SOURCE_OPTION_KEYS,
        ], true);
        foreach (array_keys($options) as $key) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new RuntimeException('UniMorph importer received an unsupported option.');
            }
        }
        foreach (self::SOURCE_OPTION_KEYS as $key) {
            if (array_key_exists($key, $options)) {
                $this->required_string($options, $key);
            }
        }
        if (
            array_key_exists('license_record_path', $options)
            !== array_key_exists('license_record_sha256', $options)
        ) {
            throw new RuntimeException('UniMorph license record path and SHA-256 must be supplied together.');
        }
        if (
            array_key_exists('license_record_sha256', $options)
            && (
                strlen($options['license_record_sha256']) !== 64
                || strspn($options['license_record_sha256'], '0123456789abcdef') !== 64
            )
        ) {
            throw new RuntimeException('UniMorph license record SHA-256 must be a lowercase 64-character hex digest.');
        }
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

    /**
     * @return array{stats:array<string,mixed>,source_evidence:array{sha256:string,bytes:int,files:array<int,array{path:string,sha256:string,byte_count:int}>}}
     */
    private function write_normalized_tsv(string $sourcePath, string $tsvPath, string $language): array
    {
        $discovery = $this->discover_source_files($sourcePath);
        $sources = $discovery['files'];
        $physicalEvidence = WP_FTS_LemmaSourceImportLimits::source_physical_evidence($sources, 'UniMorph');
        $handle = fopen($tsvPath, 'wb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Could not write normalized TSV: {$tsvPath}");
        }

        $normalizer = new WP_FTS_Normalizer();
        $stats = [
            'source_path' => $sourcePath,
            'source_files' => count($sources),
            'files' => array_map(fn(string $path): string => $this->source_label($path, $sourcePath), $sources),
            'source_path_bytes' => $discovery['path_bytes'],
            'source_entries' => $discovery['entries'],
            'source_max_depth' => $discovery['max_depth'],
            'source_physical_bytes' => $physicalEvidence['bytes'],
            'source_physical_byte_limit' => WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_PHYSICAL_BYTES,
            'source_lines' => 0,
            'source_line_limit' => WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_LINES,
            'source_decoded_bytes' => 0,
            'source_decoded_byte_limit' => WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_DECODED_BYTES,
            'blank_lines' => 0,
            'comment_lines' => 0,
            'placeholder_rows' => 0,
            'invalid_runtime_token_rows' => 0,
            'rows_with_features' => 0,
            'accepted_rows' => 0,
            'staged_tsv_bytes' => 0,
            'staged_row_limit' => WP_FTS_LemmaSourceImportLimits::MAX_STAGED_ROWS,
            'staged_tsv_byte_limit' => WP_FTS_LemmaSourceImportLimits::MAX_STAGED_TSV_BYTES,
        ];
        $sourceFiles = [];

        try {
            foreach ($sources as $sourceIndex => $file) {
                $sourceSnapshot = dirname($tsvPath) . DIRECTORY_SEPARATOR
                    . sprintf('upstream-%04d.snapshot', $sourceIndex + 1)
                    . (str_ends_with(strtolower($file), '.gz') ? '.gz' : '');
                $sourceFiles[] = $this->append_source_file(
                    $file,
                    $sourceSnapshot,
                    $sourcePath,
                    $language,
                    $normalizer,
                    $handle,
                    $stats,
                    $physicalEvidence['file_evidence'][$file]
                );
            }
        } finally {
            fclose($handle);
        }

        return ['stats' => $stats, 'source_evidence' => $this->source_evidence_from_files($sourceFiles)];
    }

    /**
     * @return array{files:string[],path_bytes:int,entries:int,max_depth:int}
     */
    private function discover_source_files(string $sourcePath): array
    {
        $discovery = WP_FTS_LemmaSourceImportLimits::discover_source_files(
            $sourcePath,
            fn(string $path): bool => $this->has_supported_source_extension($path),
            'UniMorph'
        );
        if ($discovery['files'] === []) {
            throw new RuntimeException("Source directory did not contain any .txt, .tsv, or .unimorph files: {$sourcePath}");
        }

        return $discovery;
    }

    private function has_supported_source_extension(string $path): bool
    {
        $lower = strtolower($path);
        foreach (['.txt', '.tsv', '.unimorph'] as $extension) {
            if (str_ends_with($lower, $extension) || str_ends_with($lower, $extension . '.gz')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param resource $tsvHandle
     * @param array<string,mixed> $stats
     * @param array{bytes:int,device:int,inode:int,mtime:int,ctime:int} $physicalEvidence
     * @return array{path:string,sha256:string,byte_count:int}
     */
    private function append_source_file(
        string $file,
        string $sourceSnapshot,
        string $sourceRoot,
        string $language,
        WP_FTS_Normalizer $normalizer,
        $tsvHandle,
        array &$stats,
        array $physicalEvidence
    ): array {
        $label = $this->source_label($file, $sourceRoot);
        $hashedSource = WP_FTS_LemmaSourceImportLimits::snapshot_source_artifact(
            $file,
            $sourceSnapshot,
            $physicalEvidence,
            'UniMorph'
        );
        $reader = $this->open_source($sourceSnapshot);
        $lineNumber = 0;
        try {
            while (($line = $this->read_source_line($reader)) !== false) {
                $lineNumber++;
                WP_FTS_LemmaSourceImportLimits::account_decoded_line(
                    $line,
                    $stats['source_lines'],
                    $stats['source_decoded_bytes'],
                    'UniMorph'
                );
                $line = rtrim((string) $line, "\n");
                $line = rtrim($line, "\r");
                if ($lineNumber === 1) {
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
                    throw new RuntimeException("UniMorph row {$label}:{$lineNumber} is not valid UTF-8.");
                }

                $columns = explode("\t", $line);
                $columnCount = count($columns);
                if ($columnCount !== 3) {
                    $direction = $columnCount < 3 ? 'too few' : 'too many';
                    throw new RuntimeException("UniMorph row {$label}:{$lineNumber} has {$direction} columns; expected exactly 3 tab-separated columns, found {$columnCount}.");
                }

                $lemma = trim($columns[0]);
                $form = trim($columns[1]);
                if ($lemma === '' || $form === '' || $lemma === '_' || $form === '_') {
                    $stats['placeholder_rows']++;
                    continue;
                }

                $surface = $normalizer->normalize_token($form, $language);
                $normalizedLemma = $normalizer->normalize_token($lemma, $language);
                if (!$this->is_single_runtime_token($surface, $language) || !$this->is_single_runtime_token($normalizedLemma, $language)) {
                    $stats['invalid_runtime_token_rows']++;
                    continue;
                }

                $features = trim($columns[2]);
                $tag = $features === '' || $features === '_' ? '' : $this->clean_tsv_note($features);
                if ($tag !== '') {
                    $stats['rows_with_features']++;
                }
                $sourceNote = $this->clean_tsv_note($label . ':' . $lineNumber);
                $row = $surface . "\t" . $normalizedLemma . "\t" . $tag . "\t" . $sourceNote . "\n";
                if ($stats['accepted_rows'] >= WP_FTS_LemmaSourceImportLimits::MAX_STAGED_ROWS) {
                    throw new RuntimeException(
                        'UniMorph staging exceeds the '
                        . number_format(WP_FTS_LemmaSourceImportLimits::MAX_STAGED_ROWS)
                        . '-row limit.'
                    );
                }
                if ($stats['staged_tsv_bytes'] + strlen($row) > WP_FTS_LemmaSourceImportLimits::MAX_STAGED_TSV_BYTES) {
                    throw new RuntimeException('UniMorph staging exceeds the 64 MiB decoded TSV limit.');
                }
                if (fwrite($tsvHandle, $row) !== strlen($row)) {
                    throw new RuntimeException("Could not append normalized UniMorph row for {$label}:{$lineNumber}.");
                }
                $stats['accepted_rows']++;
                $stats['staged_tsv_bytes'] += strlen($row);
            }
        } finally {
            $this->close_source($reader);
        }
        return [
            'path' => $label,
            'sha256' => $hashedSource['sha256'],
            'byte_count' => $physicalEvidence['bytes'],
        ];
    }

    /** Accept one lexical token only when its namespaced key fits storage. */
    private function is_single_runtime_token(string $token, string $language): bool
    {
        if ($token === '' || strpbrk($token, " \t\r\n") !== false || str_contains($token, WP_FTS_TermNamespace::SEPARATOR)) {
            return false;
        }

        $unicodeMatch = @preg_match('/^[\p{L}\p{M}\p{N}_]+$/u', $token);
        if ($unicodeMatch === 1) {
            return WP_FTS_TermNamespace::term_key_fits($token, $language);
        }
        if ($unicodeMatch === 0) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9_]+$/', $token) === 1
            && WP_FTS_TermNamespace::term_key_fits($token, $language);
    }

    /** Publish a bounded in-root relative path instead of a host path. */
    private function source_label(string $file, string $sourceRoot): string
    {
        if (is_dir($sourceRoot)) {
            $canonicalRoot = realpath($sourceRoot);
            $root = rtrim(is_string($canonicalRoot) ? $canonicalRoot : $sourceRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (str_starts_with($file, $root)) {
                return str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root)));
            }
        }

        return basename($file);
    }

    private function clean_tsv_note(string $value): string
    {
        return str_replace(["\t", "\r", "\n"], ' ', $value);
    }

    /**
     * Replace the delegated normalized-TSV provenance with the real UniMorph
     * source file provenance that generated the runtime rows.
     *
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $options
     * @param array<string,mixed> $stats
     * @param array{sha256:string,bytes:int,files:array<int,array{path:string,sha256:string,byte_count:int}>} $sourceEvidence
     * @return array<string,mixed>
     */
    private function rewrite_pack_metadata_for_unimorph_source(
        array $summary,
        array $options,
        string $sourcePath,
        string $language,
        array $stats,
        array $sourceEvidence
    ): array {
        $manifestPath = (string) ($summary['manifest'] ?? '');
        if ($manifestPath === '' || !is_file($manifestPath)) {
            throw new RuntimeException('Generated UniMorph pack summary did not include a manifest path.');
        }

        $manifest = $this->read_json_file($manifestPath);
        $sourceUrl = $this->required_string($options, 'source_url');
        $sourceName = $this->required_string($options, 'source_name');
        $sourceVersion = $options['source_version'] ?? $manifest['version'] ?? 'unknown';
        $license = $this->required_string($options, 'license');
        $licenseUrl = $options['license_url'] ?? '';
        $attribution = $this->required_string($options, 'attribution');
        $repoUrl = $options['source_repo_url'] ?? '';
        $sourceCommit = $options['source_commit'] ?? '';
        $declaredSourceFile = $options['source_file_path'] ?? '';
        $licenseRecordPath = $options['license_record_path'] ?? '';
        $licenseRecordSha = $options['license_record_sha256'] ?? '';
        $publishedStats = $stats;
        $delegatedStats = $manifest['source']['parse_stats'] ?? [];
        if (is_array($delegatedStats)) {
            // UniMorph owns upstream parsing counts while the delegated TSV
            // compiler owns deduplication and bounded-ambiguity counts. Keep
            // both so a shipped manifest explains every source-to-runtime row
            // difference instead of publishing only the first phase.
            $publishedStats += $delegatedStats;
        }
        // source.files is the sole per-file path/digest list. Keeping a second
        // joined copy in parse_stats would violate the bounded manifest at the
        // exact accepted discovery envelope.
        unset($publishedStats['files']);
        if ($declaredSourceFile !== '') {
            $publishedStats['source_path'] = $declaredSourceFile;
        } elseif (count($sourceEvidence['files']) === 1) {
            $publishedStats['source_path'] = $sourceEvidence['files'][0]['path'];
        } else {
            unset($publishedStats['source_path']);
        }

        $capabilities = $manifest['capabilities'] ?? [];
        if (is_array($capabilities)) {
            $capabilities[] = 'unimorph-source-import';
            $manifest['capabilities'] = array_values(array_unique(array_map('strval', $capabilities)));
        }

        $sourceFiles = $sourceEvidence['files'];
        $manifest['source'] = [
            'name' => $sourceName,
            'version' => $sourceVersion,
            'file' => $declaredSourceFile !== '' ? $declaredSourceFile : (string) ($sourceFiles[0]['path'] ?? basename($sourcePath)),
            'url' => $sourceUrl,
            'repository_url' => $repoUrl !== '' ? $repoUrl : null,
            'commit' => $sourceCommit !== '' ? $sourceCommit : null,
            'artifact_sha256' => $sourceEvidence['sha256'],
            'byte_count' => $sourceEvidence['bytes'],
            'files' => $sourceFiles,
            'column_model' => [
                'format' => 'unimorph-three-column-tsv-v1',
                'lemma_column' => 0,
                'surface_column' => 1,
                'features_column' => 2,
            ],
            'parse_stats' => $publishedStats,
        ];
        $manifest['source'] = array_filter(
            $manifest['source'],
            static fn(mixed $value): bool => $value !== null
        );

        $manifest['provenance']['importer'] = 'indexer/tools/import-unimorph-lemma-pack.php';
        $manifest['provenance']['importer_command'] = $this->canonical_unimorph_importer_command(
            $language,
            (string) $manifest['pack_id'],
            (string) $manifest['version'],
            $sourceUrl,
            $license
        );
        $manifest['provenance']['source_importer'] = 'indexer/tools/import-unimorph-lemma-pack.php';
        $manifest['provenance']['delegated_runtime_importer'] = 'indexer/tools/import-lemma-tsv-pack.php';

        $this->write_json_file($manifestPath, $manifest);
        $manifestSha = WP_FTS_LemmaPackLimits::hash_file_bounded(
            $manifestPath,
            WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES,
            'manifest_bytes',
            'Generated UniMorph analyzer-pack manifest exceeds 64 KiB.'
        )['sha256'];

        $packDir = dirname($manifestPath);
        $noticePath = $packDir . DIRECTORY_SEPARATOR . 'NOTICE.txt';
        $this->write_text($noticePath, $this->build_unimorph_notice(
            $sourceName,
            $sourceVersion,
            $sourceUrl,
            $repoUrl,
            $sourceCommit,
            (string) $manifest['source']['file'],
            $sourceEvidence['sha256'],
            $sourceEvidence['bytes'],
            $license,
            $licenseUrl,
            $attribution,
            $licenseRecordPath,
            $licenseRecordSha
        ));

        $runtimeBytes = $this->runtime_bytes($packDir, $manifest);
        $lookupStats = $this->runtime_lookup_stats($packDir, $manifest);
        $sourceLock = $this->build_source_lock(
            $manifest,
            $manifestSha,
            $runtimeBytes,
            $license,
            $licenseUrl,
            $attribution,
            $licenseRecordPath,
            $licenseRecordSha,
            $lookupStats
        );
        $sourceLockPath = $packDir . DIRECTORY_SEPARATOR . 'SOURCE.lock.json';
        $this->write_json_file($sourceLockPath, $sourceLock);

        $provenancePath = $packDir . DIRECTORY_SEPARATOR . 'PROVENANCE.md';
        $this->write_text($provenancePath, $this->build_provenance_markdown($manifest, $sourceLock));

        $summary['manifest_sha256'] = $manifestSha;
        $summary['source_lock'] = $sourceLockPath;
        $summary['provenance'] = $provenancePath;
        $summary['source'] = [
            'path' => $sourcePath,
            'url' => $sourceUrl,
            'repo_url' => $repoUrl,
            'commit' => $sourceCommit,
            'sha256' => $sourceEvidence['sha256'],
            'bytes' => $sourceEvidence['bytes'],
            'files' => $sourceFiles,
        ];
        $summary['pack_bytes'] = $this->directory_bytes($packDir);

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private function read_json_file(string $path): array
    {
        $json = file_get_contents(
            $path,
            false,
            null,
            0,
            WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES + 1
        );
        if (!is_string($json) || strlen($json) > WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES) {
            throw new RuntimeException("Could not read a bounded analyzer-pack manifest: {$path}");
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException("JSON file must decode to an object: {$path}");
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function write_json_file(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException("Could not write JSON file: {$path}");
        }
    }

    private function write_text(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Could not write file: {$path}");
        }
    }

    /**
     * @param array<int,array{path:string,sha256:string,byte_count:int}> $sourceFiles
     * @return array{sha256:string,bytes:int,files:array<int,array{path:string,sha256:string,byte_count:int}>}
     */
    private function source_evidence_from_files(array $sourceFiles): array
    {
        $totalBytes = 0;
        $digest = hash_init('sha256');
        foreach ($sourceFiles as $file) {
            $totalBytes += $file['byte_count'];
            hash_update(
                $digest,
                $file['path'] . "\0" . $file['sha256'] . "\0" . (string) $file['byte_count'] . "\n"
            );
        }

        if (count($sourceFiles) === 1) {
            return [
                'sha256' => $sourceFiles[0]['sha256'],
                'bytes' => $sourceFiles[0]['byte_count'],
                'files' => $sourceFiles,
            ];
        }

        return [
            'sha256' => hash_final($digest),
            'bytes' => $totalBytes,
            'files' => $sourceFiles,
        ];
    }

    private function canonical_unimorph_importer_command(
        string $language,
        string $packId,
        string $version,
        string $sourceUrl,
        string $license
    ): string {
        $parts = [
            'php indexer/tools/import-unimorph-lemma-pack.php',
            '--source=<unimorph-source>',
            '--out=<pack-dir>',
            '--language=' . $language,
            '--pack-id=' . $packId,
            '--version=' . $version,
            '--source-name=<approved-unimorph-source-name>',
            '--source-url=' . $sourceUrl,
            '--license=' . $license,
            '--attribution=<required-attribution>',
        ];
        return implode(' ', $parts);
    }

    private function build_unimorph_notice(
        string $sourceName,
        string $sourceVersion,
        string $sourceUrl,
        string $repoUrl,
        string $sourceCommit,
        string $sourceFile,
        string $sourceSha,
        int $sourceBytes,
        string $license,
        string $licenseUrl,
        string $attribution,
        string $licenseRecordPath,
        string $licenseRecordSha
    ): string {
        $lines = [
            "{$sourceName} {$sourceVersion}",
            "Source URL: {$sourceUrl}",
        ];
        if ($repoUrl !== '') {
            $lines[] = "Source repository: {$repoUrl}";
        }
        if ($sourceCommit !== '') {
            $lines[] = "Source commit: {$sourceCommit}";
        }
        $lines[] = "Source file path: {$sourceFile}";
        $lines[] = "Source artifact SHA-256: {$sourceSha}";
        $lines[] = "Source artifact byte count: {$sourceBytes}";
        $lines[] = "License: {$license}";
        if ($licenseUrl !== '') {
            $lines[] = "License URL: {$licenseUrl}";
        }
        if ($licenseRecordPath !== '') {
            $lines[] = "License record path: {$licenseRecordPath}";
        }
        if ($licenseRecordSha !== '') {
            $lines[] = "License record SHA-256: {$licenseRecordSha}";
        }
        $lines[] = "Attribution: {$attribution}";
        $lines[] = '';
        $lines[] = 'Generated from source-approved UniMorph lemma/form/features rows.';
        $lines[] = 'The runtime analyzer performs no network access.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function runtime_bytes(string $packDir, array $manifest): int
    {
        $bytes = 0;
        foreach (($manifest['runtime']['files'] ?? []) as $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null)) {
                continue;
            }
            $path = $packDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $file['path']);
            $fileBytes = filesize($path);
            if (!is_int($fileBytes)) {
                throw new RuntimeException("Could not measure runtime file: {$path}");
            }
            $bytes += $fileBytes;
        }

        return $bytes;
    }

    /**
     * @return array{format:?string,files:int,bytes:int}
     */
    private function runtime_lookup_stats(string $packDir, array $manifest): array
    {
        $format = null;
        $files = 0;
        $bytes = 0;
        foreach ($manifest['runtime']['files'] as $file) {
            if (!is_array($file['lookup'] ?? null)) {
                continue;
            }
            $path = $packDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $file['lookup']['path']);
            $size = filesize($path);
            if (!is_int($size)) {
                throw new RuntimeException("Could not measure runtime lookup sidecar: {$path}");
            }
            $format ??= (string) $file['lookup']['format'];
            $files++;
            $bytes += $size;
        }

        return ['format' => $format, 'files' => $files, 'bytes' => $bytes];
    }

    private function directory_bytes(string $directory): int
    {
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $size = $file->getSize();
            if ($size > 0) {
                $bytes += $size;
            }
        }

        return $bytes;
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array{format:?string,files:int,bytes:int} $lookupStats
     * @return array<string,mixed>
     */
    private function build_source_lock(
        array $manifest,
        string $manifestSha,
        int $runtimeBytes,
        string $license,
        string $licenseUrl,
        string $attribution,
        string $licenseRecordPath,
        string $licenseRecordSha,
        array $lookupStats
    ): array {
        $runtime = [
            'manifest_sha256' => $manifestSha,
            'row_count' => $manifest['runtime']['total_rows'],
            'file_count' => count($manifest['runtime']['files']),
            'byte_count' => $runtimeBytes,
            'digest_sha256' => $manifest['runtime']['total_sha256'],
            'contains_third_party_data' => true,
            'committed' => true,
            'compression' => WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
            'lookup_index_format' => $lookupStats['format'],
            'lookup_index_file_count' => $lookupStats['files'],
            'lookup_index_byte_count' => $lookupStats['bytes'],
        ];

        return [
            'schema_version' => 'wp-fts-unimorph-lemma-pack-source-lock/v1',
            'pack' => [
                'id' => $manifest['pack_id'],
                'language' => $manifest['language'],
                'kind' => 'lemmatizer',
                'status' => 'production_candidate',
                'runtime_pack_committed' => true,
            ],
            'source' => [
                'name' => $manifest['source']['name'],
                'version' => $manifest['source']['version'],
                'url' => $manifest['source']['url'],
                'repository_url' => $manifest['source']['repository_url'] ?? '',
                'commit' => $manifest['source']['commit'] ?? '',
                'file' => $manifest['source']['file'],
                'files' => $manifest['source']['files'],
                'artifact_sha256' => $manifest['source']['artifact_sha256'],
                'byte_count' => $manifest['source']['byte_count'],
                'license' => [
                    'spdx_id' => $license,
                    'license_url' => $licenseUrl,
                    'notice_path' => 'NOTICE.txt',
                    'record_path' => $licenseRecordPath,
                    'record_sha256' => $licenseRecordSha,
                ],
            ],
            'columns' => $manifest['source']['column_model'],
            'importer' => [
                'path' => $manifest['provenance']['importer'],
                'delegated_runtime_importer' => $manifest['provenance']['delegated_runtime_importer'],
                'commit' => $manifest['provenance']['importer_commit'],
                'command' => $manifest['provenance']['importer_command'],
            ],
            'runtime' => $runtime,
            'behavior' => [
                'oov_policy' => 'return_original_normalized_term',
                'ambiguity_policy' => $manifest['runtime']['ambiguity_policy'],
                'unsupported_language_policy' => 'return_original_normalized_term',
            ],
            'release' => [
                'claim_boundary' => 'Source-backed UniMorph lemmatizer. Runtime pack is bundled and activates only through plugin configuration.',
            ],
            'attribution' => [
                'upstream' => $attribution,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $sourceLock
     */
    private function build_provenance_markdown(array $manifest, array $sourceLock): string
    {
        $runtime = $sourceLock['runtime'];
        $source = $sourceLock['source'];
        $license = '- License: `' . $source['license']['spdx_id'] . '`';
        if ($source['license']['license_url'] !== '') {
            $license .= ' ' . $source['license']['license_url'];
        }
        $lines = [
            '# UniMorph Analyzer Pack Provenance',
            '',
            'This pack is generated from source-approved UniMorph data through the repository importer. It contains normalized surface-to-lemma runtime rows and no runtime network access.',
            '',
            '- Pack ID: `' . $manifest['pack_id'] . '`',
            '- Language: `' . $manifest['language'] . '`',
            '- Source repository: ' . ($source['repository_url'] !== '' ? $source['repository_url'] : $source['url']),
            '- Source commit: `' . $source['commit'] . '`',
            '- Source file: `' . $source['file'] . '`',
            '- Source SHA-256: `' . $source['artifact_sha256'] . '`',
            $license,
            '- Importer command: `' . $sourceLock['importer']['command'] . '`',
            '- Runtime rows: `' . $runtime['row_count'] . '`',
            '- Runtime files: `' . $runtime['file_count'] . '`',
            '- Runtime digest SHA-256: `' . $runtime['digest_sha256'] . '`',
            '',
            'The generated pack activates only through `lemma_packs_by_lang`.',
            '',
        ];

        return implode("\n", $lines);
    }

    /**
     * @return array{type:string,handle:resource}
     */
    private function open_source(string $sourcePath): array
    {
        if (str_ends_with(strtolower($sourcePath), '.gz')) {
            if (!function_exists('gzopen')) {
                throw new RuntimeException('Reading gzip UniMorph sources requires the PHP zlib extension; use extracted .txt, .tsv, or .unimorph files under php -n.');
            }
            $handle = gzopen($sourcePath, 'rb');
            if (!is_resource($handle)) {
                throw new RuntimeException("Could not open gzip UniMorph source: {$sourcePath}");
            }

            return ['type' => 'gzip', 'handle' => $handle];
        }

        $handle = fopen($sourcePath, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Could not open UniMorph source: {$sourcePath}");
        }

        return ['type' => 'plain', 'handle' => $handle];
    }

    /**
     * @param array{type:string,handle:resource} $reader
     */
    private function read_source_line(array $reader): string|false
    {
        return WP_FTS_LemmaSourceImportLimits::read_line($reader, 'UniMorph source');
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
            $tmpDir = $parent . DIRECTORY_SEPARATOR . 'wp-fts-unimorph-lemma-import-' . getmypid() . '-' . bin2hex(random_bytes(8));
            if (mkdir($tmpDir, 0700)) {
                return $tmpDir;
            }
            if (!file_exists($tmpDir)) {
                throw new RuntimeException("Could not create importer temporary directory: {$tmpDir}");
            }
        }

        throw new RuntimeException("Could not create a unique importer temporary directory under: {$parent}");
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
        $options = WP_FTS_UnimorphLemmaPackImporter::parse_cli_options(array_slice($argv, 1));
        $summary = (new WP_FTS_UnimorphLemmaPackImporter())->import($options);
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "UniMorph lemma import failed: {$e->getMessage()}\n");
        exit(1);
    }
}
