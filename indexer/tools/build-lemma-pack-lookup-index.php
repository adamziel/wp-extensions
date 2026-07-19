<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

/**
 * Repacks gzip lemma shards into seekable members and adds offset sidecars.
 */
final class WP_FTS_LemmaPackLookupIndexBuilder
{
    /**
     * @return array{status:string,manifest:string,files:int,blocks:int,bytes:int}
     */
    public function build(string $manifestPath): array
    {
        $manifestPath = realpath($manifestPath);
        if (!is_string($manifestPath) || !is_file($manifestPath)) {
            throw new RuntimeException('Analyzer pack manifest does not exist.');
        }

        $json = $this->read_bounded_json_file($manifestPath, 'analyzer pack manifest');
        $manifest = json_decode(
            $json,
            true,
            WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_GRAPH_DEPTH + 2,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($manifest)) {
            throw new RuntimeException('Analyzer pack manifest must decode to an object.');
        }
        WP_FTS_Analyzer_Config_Limits::assert_manifest_graph($manifest);
        if (!isset($manifest['runtime']['files']) || !is_array($manifest['runtime']['files'])) {
            throw new RuntimeException('Analyzer pack manifest runtime files are invalid.');
        }
        foreach ($manifest['runtime']['files'] as $file) {
            if (!is_array($file) || ($file['compression'] ?? null) !== WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP) {
                throw new RuntimeException('Lookup index generation requires every runtime shard to use gzip compression.');
            }
        }

        // A format migration must be able to rebuild the runtime bytes attested
        // by an older sidecar. Validate the runtime stream through a pack-local
        // temporary manifest with lookup entries removed, then publish only v2
        // sidecars and strictly re-open the final manifest below.
        $runtimeOnlyManifest = $manifest;
        foreach ($runtimeOnlyManifest['runtime']['files'] as &$runtimeFile) {
            if (is_array($runtimeFile)) {
                unset($runtimeFile['lookup']);
            }
        }
        unset($runtimeFile);
        $validationManifestPath = tempnam(dirname($manifestPath), '.wp-fts-runtime-validation-');
        if (!is_string($validationManifestPath)) {
            throw new RuntimeException('Could not stage runtime-only analyzer validation.');
        }
        try {
            $validationJson = json_encode(
                $runtimeOnlyManifest,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            if (file_put_contents($validationManifestPath, $validationJson) !== strlen($validationJson)) {
                throw new RuntimeException('Could not write runtime-only analyzer validation manifest.');
            }
            $validation = (new WP_FTS_AnalyzerPackValidator())->validate($validationManifestPath, false);
        } finally {
            @unlink($validationManifestPath);
        }

        $builtFiles = 0;
        $blocks = 0;
        $bytes = 0;
        $packDirectory = dirname($manifestPath);
        $validationManifest = $manifest;
        $stagedPaths = [];
        $publish = [];
        $stagedRuntimePaths = [];
        try {
            foreach ($manifest['runtime']['files'] as $index => $file) {
                if (!is_array($file) || ($file['compression'] ?? null) !== WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP) {
                    continue;
                }

                $relativePath = (string) $file['path'];
                $runtime = $validation['runtime_files'][$relativePath] ?? null;
                if (!is_array($runtime)) {
                    throw new RuntimeException("Validated runtime metadata is missing for {$relativePath}.");
                }

                $runtimePath = (string) $runtime['path'];
                $stagedRuntimePath = $this->stage_copy($runtimePath, '.wp-fts-runtime-stage-');
                $stagedPaths[] = $stagedRuntimePath;
                $lookupRelativePath = $relativePath . '.lookup';
                if (strlen($lookupRelativePath) > WP_FTS_Analyzer_Config_Limits::MAX_PATH_BYTES) {
                    throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                        'path_bytes',
                        'Generated lemma lookup path exceeds the 4-KiB path limit.'
                    );
                }
                $lookupPath = $packDirectory . '/' . $lookupRelativePath;
                $stagedLookupPath = $this->reserve_stage_path(dirname($lookupPath), '.wp-fts-lookup-stage-');
                $stagedPaths[] = $stagedLookupPath;
                $result = WP_FTS_LemmaPackLookupIndex::build(
                    $stagedRuntimePath,
                    WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
                    (string) $runtime['sha256'],
                    $stagedLookupPath
                );
                $lookupMetadata = [
                    'format' => $result['format'],
                    'path' => $lookupRelativePath,
                    'sha256' => $result['sha256'],
                    'blocks' => $result['blocks'],
                ];
                $manifest['runtime']['files'][$index]['sha256'] = $result['runtime_sha256'];
                $manifest['runtime']['files'][$index]['lookup'] = $lookupMetadata;
                $validationManifest['runtime']['files'][$index]['path'] = $this->pack_relative_path(
                    $packDirectory,
                    $stagedRuntimePath
                );
                $validationManifest['runtime']['files'][$index]['sha256'] = $result['runtime_sha256'];
                $validationLookupMetadata = $lookupMetadata;
                $validationLookupMetadata['path'] = $this->pack_relative_path(
                    $packDirectory,
                    $stagedLookupPath
                );
                $validationManifest['runtime']['files'][$index]['lookup'] = $validationLookupMetadata;
                $publish[] = ['staged' => $stagedRuntimePath, 'destination' => $runtimePath];
                $publish[] = ['staged' => $stagedLookupPath, 'destination' => $lookupPath];
                $stagedRuntimePaths[$relativePath] = $stagedRuntimePath;
                $builtFiles++;
                $blocks += $result['blocks'];
                $size = filesize($stagedLookupPath);
                if (!is_int($size)) {
                    throw new RuntimeException("Could not measure lookup sidecar {$lookupRelativePath}.");
                }
                $bytes += $size;
            }

            if ($builtFiles < 1) {
                throw new RuntimeException('Analyzer pack has no gzip runtime shards to index.');
            }
            $capabilities = is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : [];
            if (!in_array('indexed-runtime-lookups', $capabilities, true)) {
                $capabilities[] = 'indexed-runtime-lookups';
            }
            $manifest['capabilities'] = array_values($capabilities);
            $validationManifest['capabilities'] = $manifest['capabilities'];

            $updated = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . "\n";
            if (strlen($updated) > WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES) {
                throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                    'manifest_bytes',
                    'Generated analyzer pack manifest exceeds 64 KiB.'
                );
            }
            $stagedManifestPath = $this->stage_text(
                $packDirectory,
                '.wp-fts-manifest-stage-',
                $updated
            );
            $stagedPaths[] = $stagedManifestPath;

            // Strictly validate every staged runtime and sidecar before any
            // committed pack artifact is replaced.
            $validationJson = json_encode(
                $validationManifest,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $stagedValidationPath = $this->stage_text(
                $packDirectory,
                '.wp-fts-index-validation-',
                $validationJson
            );
            $stagedPaths[] = $stagedValidationPath;
            (new WP_FTS_AnalyzerPackValidator())->validate($stagedValidationPath, false);

            $publish[] = ['staged' => $stagedManifestPath, 'destination' => $manifestPath];
            $sourceLock = $this->stage_source_lock(
                $manifestPath,
                $updated,
                $manifest,
                $stagedRuntimePaths,
                $bytes,
                $builtFiles
            );
            if ($sourceLock !== null) {
                $stagedPaths[] = $sourceLock['staged'];
                $publish[] = $sourceLock;
            }

            $this->publish_pack($publish, $manifestPath);
        } finally {
            foreach ($stagedPaths as $stagedPath) {
                @unlink($stagedPath);
            }
        }

        return [
            'status' => 'ok',
            'manifest' => $manifestPath,
            'files' => $builtFiles,
            'blocks' => $blocks,
            'bytes' => $bytes,
        ];
    }

    /**
     * Keep committed source evidence aligned with rewritten gzip bytes and the
     * published manifest. The Polish lock separately records uncompressed and
     * compressed sizes; UniMorph locks use runtime.byte_count for gzip bytes.
     *
     * @param array<string,mixed> $manifest
     */
    private function stage_source_lock(
        string $manifestPath,
        string $manifestJson,
        array $manifest,
        array $stagedRuntimePaths,
        int $lookupBytes,
        int $lookupFiles
    ): ?array
    {
        $sourceLockPath = dirname($manifestPath) . '/SOURCE.lock.json';
        if (!is_file($sourceLockPath)) {
            return null;
        }

        $sourceLock = json_decode(
            $this->read_bounded_json_file($sourceLockPath, 'analyzer pack source lock'),
            true,
            WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_GRAPH_DEPTH + 2,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($sourceLock) || !is_array($sourceLock['runtime'] ?? null)) {
            throw new RuntimeException('Analyzer pack source lock runtime evidence is invalid.');
        }
        WP_FTS_Analyzer_Config_Limits::assert_manifest_graph($sourceLock);

        $runtimeBytes = 0;
        $largestRuntime = 0;
        foreach ($manifest['runtime']['files'] as $file) {
            $relativePath = (string) $file['path'];
            $runtimePath = $stagedRuntimePaths[$relativePath] ?? dirname($manifestPath) . '/' . $relativePath;
            $size = filesize($runtimePath);
            if (!is_int($size)) {
                throw new RuntimeException("Could not measure indexed runtime shard {$file['path']}.");
            }
            $runtimeBytes += $size;
            $largestRuntime = max($largestRuntime, $size);
        }

        $sourceLock['runtime']['manifest_sha256'] = hash('sha256', $manifestJson);
        if (array_key_exists('compressed_byte_count', $sourceLock['runtime'])) {
            $sourceLock['runtime']['compressed_byte_count'] = $runtimeBytes;
            $sourceLock['runtime']['largest_compressed_shard_byte_count'] = $largestRuntime;
        } else {
            $sourceLock['runtime']['byte_count'] = $runtimeBytes;
        }
        $sourceLock['runtime']['lookup_index_format'] = WP_FTS_LemmaPackLookupIndex::FORMAT;
        $sourceLock['runtime']['lookup_index_file_count'] = $lookupFiles;
        $sourceLock['runtime']['lookup_index_byte_count'] = $lookupBytes;

        $updated = json_encode($sourceLock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (strlen($updated) > WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'manifest_bytes',
                'Generated analyzer pack source lock exceeds 64 KiB.'
            );
        }
        $stagedPath = $this->stage_text(dirname($sourceLockPath), '.wp-fts-source-lock-stage-', $updated);
        @chmod($stagedPath, 0644);

        return ['staged' => $stagedPath, 'destination' => $sourceLockPath];
    }

    /** Read one metadata artifact without materializing bytes past 64 KiB. */
    private function read_bounded_json_file(string $path, string $label): string
    {
        $json = file_get_contents(
            $path,
            false,
            null,
            0,
            WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES + 1
        );
        if (!is_string($json)) {
            throw new RuntimeException("Could not read {$label}.");
        }
        if (strlen($json) > WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'manifest_bytes',
                ucfirst($label) . ' exceeds 64 KiB.'
            );
        }

        return $json;
    }

    /** Reserve a same-directory staging name without leaving an empty artifact. */
    private function reserve_stage_path(string $directory, string $prefix): string
    {
        $path = tempnam($directory, $prefix);
        if (!is_string($path)) {
            throw new RuntimeException('Could not reserve an analyzer pack staging path.');
        }
        if (!unlink($path)) {
            throw new RuntimeException('Could not prepare an analyzer pack staging path.');
        }

        return $path;
    }

    /** Copy one generated artifact to a same-directory publication stage. */
    private function stage_copy(string $source, string $prefix): string
    {
        $path = $this->reserve_stage_path(dirname($source), $prefix) . '.gz';
        if (file_exists($path) || !copy($source, $path)) {
            @unlink($path);
            throw new RuntimeException('Could not stage an analyzer pack runtime shard.');
        }
        $permissions = fileperms($source);
        if (is_int($permissions)) {
            @chmod($path, $permissions & 0777);
        }

        return $path;
    }

    /** Write one bounded metadata string to a same-directory publication stage. */
    private function stage_text(string $directory, string $prefix, string $contents): string
    {
        $path = tempnam($directory, $prefix);
        if (!is_string($path) || file_put_contents($path, $contents) !== strlen($contents)) {
            if (is_string($path)) {
                @unlink($path);
            }
            throw new RuntimeException('Could not stage analyzer pack metadata.');
        }
        @chmod($path, 0644);

        return $path;
    }

    /** Convert an attested pack-local path to its manifest-relative form. */
    private function pack_relative_path(string $packDirectory, string $path): string
    {
        $prefix = rtrim($packDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($path, $prefix)) {
            throw new RuntimeException('Analyzer pack staging path escaped its pack directory.');
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($prefix)));
    }

    /**
     * Replace the complete staged pack as one rollback unit. Original artifacts
     * remain in same-directory backups until strict validation of final paths.
     *
     * @param array<int,array{staged:string,destination:string}> $artifacts
     */
    private function publish_pack(array $artifacts, string $manifestPath): void
    {
        $backups = [];
        $published = [];
        try {
            foreach ($artifacts as $artifact) {
                $staged = $artifact['staged'];
                $destination = $artifact['destination'];
                if (!is_file($staged)) {
                    throw new RuntimeException("Staged analyzer pack artifact is missing: {$staged}");
                }
                $backup = null;
                if (file_exists($destination) || is_link($destination)) {
                    if (!is_file($destination)) {
                        throw new RuntimeException("Analyzer pack destination is not a regular file: {$destination}");
                    }
                    $backup = $this->reserve_stage_path(dirname($destination), '.wp-fts-pack-backup-');
                    if (!rename($destination, $backup)) {
                        @unlink($backup);
                        throw new RuntimeException("Could not back up analyzer pack artifact: {$destination}");
                    }
                }
                $backups[$destination] = $backup;
                if (!rename($staged, $destination)) {
                    if (is_string($backup)) {
                        if (rename($backup, $destination)) {
                            $backups[$destination] = null;
                        }
                    }
                    throw new RuntimeException("Could not publish analyzer pack artifact: {$destination}");
                }
                $published[] = $destination;
            }

            // Re-open final paths before deleting backups. A path-specific or
            // same-second integrity failure therefore rolls the whole pack back.
            (new WP_FTS_AnalyzerPackValidator())->validate($manifestPath, false);
        } catch (Throwable $error) {
            $rollbackErrors = [];
            foreach (array_reverse($published) as $destination) {
                if (is_file($destination) && !unlink($destination)) {
                    $rollbackErrors[] = "could not remove {$destination}";
                    continue;
                }
                $backup = $backups[$destination] ?? null;
                if (is_string($backup) && !rename($backup, $destination)) {
                    $rollbackErrors[] = "could not restore {$destination}";
                }
                $backups[$destination] = null;
            }
            foreach ($backups as $destination => $backup) {
                if (is_string($backup) && is_file($backup)) {
                    if (file_exists($destination)) {
                        @unlink($destination);
                    }
                    if (!rename($backup, $destination)) {
                        $rollbackErrors[] = "could not restore {$destination}";
                    }
                }
            }
            if ($rollbackErrors !== []) {
                throw new RuntimeException(
                    'Analyzer pack publication failed and rollback was incomplete: ' . implode('; ', $rollbackErrors),
                    0,
                    $error
                );
            }
            throw $error;
        }

        foreach ($backups as $backup) {
            if (is_string($backup)) {
                @unlink($backup);
            }
        }
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $manifest = null;
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--manifest=')) {
            $manifest = substr($arg, strlen('--manifest='));
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            fwrite(STDOUT, "Usage: php indexer/tools/build-lemma-pack-lookup-index.php --manifest=PATH\n");
            exit(0);
        }
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }
    if (!is_string($manifest) || trim($manifest) === '') {
        fwrite(STDERR, "Missing required --manifest=PATH.\n");
        exit(2);
    }

    try {
        $result = (new WP_FTS_LemmaPackLookupIndexBuilder())->build($manifest);
        fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
