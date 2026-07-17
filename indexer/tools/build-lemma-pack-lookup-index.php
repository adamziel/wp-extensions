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

        $json = file_get_contents($manifestPath);
        if (!is_string($json)) {
            throw new RuntimeException('Could not read analyzer pack manifest.');
        }
        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new RuntimeException('Analyzer pack manifest must decode to an object.');
        }

        $validation = (new WP_FTS_AnalyzerPackValidator())->validate($manifestPath, false);
        $builtFiles = 0;
        $blocks = 0;
        $bytes = 0;
        foreach ($manifest['runtime']['files'] as $index => $file) {
            if (!is_array($file) || ($file['compression'] ?? null) !== WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP) {
                continue;
            }

            $relativePath = (string) $file['path'];
            $runtime = $validation['runtime_files'][$relativePath] ?? null;
            if (!is_array($runtime)) {
                throw new RuntimeException("Validated runtime metadata is missing for {$relativePath}.");
            }

            $lookupRelativePath = $relativePath . '.lookup';
            $lookupPath = dirname($manifestPath) . '/' . $lookupRelativePath;
            $result = WP_FTS_LemmaPackLookupIndex::build(
                (string) $runtime['path'],
                WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
                (string) $runtime['sha256'],
                $lookupPath
            );
            $manifest['runtime']['files'][$index]['sha256'] = $result['runtime_sha256'];
            $manifest['runtime']['files'][$index]['lookup'] = [
                'format' => $result['format'],
                'path' => $lookupRelativePath,
                'sha256' => $result['sha256'],
                'blocks' => $result['blocks'],
            ];
            $builtFiles++;
            $blocks += $result['blocks'];
            $size = filesize($lookupPath);
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

        $updated = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $stagedPath = tempnam(dirname($manifestPath), '.wp-fts-manifest-');
        if (!is_string($stagedPath) || file_put_contents($stagedPath, $updated) !== strlen($updated)) {
            if (is_string($stagedPath)) {
                @unlink($stagedPath);
            }
            throw new RuntimeException('Could not stage analyzer pack manifest update.');
        }
        @chmod($stagedPath, 0644);
        if (!rename($stagedPath, $manifestPath)) {
            @unlink($stagedPath);
            throw new RuntimeException('Could not publish analyzer pack manifest update.');
        }

        // Re-open the published manifest and inflate every sidecar block before
        // reporting success. This is the same strict path used by enable/release.
        (new WP_FTS_AnalyzerPackValidator())->validate($manifestPath, false);
        $this->update_source_lock($manifestPath, $manifest, $bytes, $builtFiles);

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
    private function update_source_lock(string $manifestPath, array $manifest, int $lookupBytes, int $lookupFiles): void
    {
        $sourceLockPath = dirname($manifestPath) . '/SOURCE.lock.json';
        if (!is_file($sourceLockPath)) {
            return;
        }

        $sourceLock = json_decode((string) file_get_contents($sourceLockPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($sourceLock) || !is_array($sourceLock['runtime'] ?? null)) {
            throw new RuntimeException('Analyzer pack source lock runtime evidence is invalid.');
        }

        $runtimeBytes = 0;
        $largestRuntime = 0;
        foreach ($manifest['runtime']['files'] as $file) {
            $size = filesize(dirname($manifestPath) . '/' . $file['path']);
            if (!is_int($size)) {
                throw new RuntimeException("Could not measure indexed runtime shard {$file['path']}.");
            }
            $runtimeBytes += $size;
            $largestRuntime = max($largestRuntime, $size);
        }

        $manifestSha256 = hash_file('sha256', $manifestPath);
        if (!is_string($manifestSha256)) {
            throw new RuntimeException('Could not hash indexed analyzer pack manifest.');
        }
        $sourceLock['runtime']['manifest_sha256'] = $manifestSha256;
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
        $stagedPath = tempnam(dirname($sourceLockPath), '.wp-fts-source-lock-');
        if (!is_string($stagedPath) || file_put_contents($stagedPath, $updated) !== strlen($updated)) {
            if (is_string($stagedPath)) {
                @unlink($stagedPath);
            }
            throw new RuntimeException('Could not update analyzer pack source lock evidence.');
        }
        @chmod($stagedPath, 0644);
        if (!rename($stagedPath, $sourceLockPath)) {
            @unlink($stagedPath);
            throw new RuntimeException('Could not publish analyzer pack source lock evidence.');
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
