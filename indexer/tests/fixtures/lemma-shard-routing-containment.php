<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

try {
    echo json_encode(
        wp_fts_lemma_shard_routing_main(),
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}

/** @return array<string,mixed> */
function wp_fts_lemma_shard_routing_main(): array
{
    $started = microtime(true);
    $peakBefore = memory_get_peak_usage(true);
    $root = sys_get_temp_dir() . '/wp-fts-lemma-shard-routing-' . bin2hex(random_bytes(6));
    mkdir($root . '/runtime', 0777, true);
    try {
        file_put_contents($root . '/NOTICE.txt', "Project-owned generated shard-routing fixture.\n");
        $runtimeFiles = [];
        for ($index = 0; $index < WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_FILES; $index++) {
            $surface = sprintf('s%04d', $index);
            $lemma = sprintf('lemma%04d', $index);
            $relativePath = sprintf('runtime/%04d.tsv.gz', $index + 1);
            $runtimePath = $root . '/' . $relativePath;
            $encoded = gzencode($surface . "\t" . $lemma . "\n", 9, ZLIB_ENCODING_GZIP);
            if (!is_string($encoded)) {
                throw new RuntimeException('Could not encode shard-routing fixture.');
            }
            file_put_contents($runtimePath, $encoded);
            $lookup = WP_FTS_LemmaPackLookupIndex::build(
                $runtimePath,
                WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
                (string) hash_file('sha256', $runtimePath),
                $runtimePath . '.lookup'
            );
            $runtimeFiles[] = [
                'path' => $relativePath,
                'sha256' => $lookup['runtime_sha256'],
                'rows' => 1,
                'first_surface' => $surface,
                'last_surface' => $surface,
                'compression' => WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
                'lookup' => [
                    'format' => $lookup['format'],
                    'path' => $relativePath . '.lookup',
                    'sha256' => $lookup['sha256'],
                    'blocks' => $lookup['blocks'],
                ],
            ];
        }

        $manifest = wp_fts_lemma_shard_routing_manifest($runtimeFiles);
        $invalid = [];
        foreach (wp_fts_lemma_shard_routing_invalid_files($runtimeFiles) as $name => $files) {
            $invalidManifest = $manifest;
            $invalidManifest['runtime']['files'] = $files;
            foreach ($invalidManifest['runtime']['files'] as &$file) {
                $file['path'] = 'never-opened/' . basename((string) $file['path']);
            }
            unset($file);
            $manifestPath = $root . '/manifest-invalid-' . $name . '.json';
            wp_fts_lemma_shard_routing_write_manifest($manifestPath, $invalidManifest);
            $caseStarted = microtime(true);
            $error = null;
            try {
                (new WP_FTS_AnalyzerPackValidator())->validate_metadata($manifestPath, false);
            } catch (Throwable $caught) {
                $error = $caught;
            }
            $invalid[$name] = [
                'error_class' => $error === null ? null : get_class($error),
                'error_message' => $error === null ? null : $error->getMessage(),
                'rejected_before_runtime_path_resolution' => $error !== null
                    && !str_contains($error->getMessage(), 'does not exist'),
                'elapsed_seconds' => microtime(true) - $caseStarted,
            ];
        }

        $validManifestPath = $root . '/manifest-valid.json';
        wp_fts_lemma_shard_routing_write_manifest($validManifestPath, $manifest);
        $pack = WP_FTS_LanguageLemmaPack::from_manifest_file($validManifestPath, null, 'en');
        $lookups = [];
        foreach (['s0000', 's0032', 's0063', 's0032x'] as $term) {
            $lookupStarted = microtime(true);
            $result = $pack->stem($term, 'en');
            $lookups[$term] = [
                'result' => $result,
                'stats' => $pack->last_lookup_stats(),
                'elapsed_seconds' => microtime(true) - $lookupStarted,
            ];
        }

        $validation = (new WP_FTS_AnalyzerPackValidator())->validate($validManifestPath, false);

        return [
            'runtime_file_limit' => WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_FILES,
            'runtime_files' => count($runtimeFiles),
            'lookup_decoded_byte_limit' => WP_FTS_LemmaPackLimits::MAX_RUNTIME_LOOKUP_DECODED_BYTES,
            'validated_runtime_rows' => $validation['runtime_rows'],
            'invalid' => $invalid,
            'lookups' => $lookups,
            'elapsed_seconds' => microtime(true) - $started,
            'php_peak_bytes' => memory_get_peak_usage(true),
            'php_peak_delta_bytes' => max(0, memory_get_peak_usage(true) - $peakBefore),
            'proc_status' => wp_fts_lemma_shard_routing_proc_status(),
        ];
    } finally {
        wp_fts_lemma_shard_routing_remove_tree($root);
    }
}

/**
 * @param array<int,array<string,mixed>> $runtimeFiles
 * @return array<string,array<int,array<string,mixed>>>
 */
function wp_fts_lemma_shard_routing_invalid_files(array $runtimeFiles): array
{
    $missing = $runtimeFiles;
    unset($missing[0]['first_surface'], $missing[0]['last_surface']);

    $overlap = $runtimeFiles;
    $overlap[32]['first_surface'] = (string) $overlap[31]['last_surface'];

    $outOfOrder = $runtimeFiles;
    foreach (['first_surface', 'last_surface'] as $field) {
        [$outOfOrder[32][$field], $outOfOrder[33][$field]] = [$outOfOrder[33][$field], $outOfOrder[32][$field]];
    }

    $unnormalized = $runtimeFiles;
    $unnormalized[0]['first_surface'] = 'S0000';
    $unnormalized[0]['last_surface'] = 'S0000';

    return [
        'missing_ranges' => $missing,
        'overlapping_ranges' => $overlap,
        'out_of_order_ranges' => $outOfOrder,
        'unnormalized_ranges' => $unnormalized,
    ];
}

/**
 * @param array<int,array<string,mixed>> $runtimeFiles
 * @return array<string,mixed>
 */
function wp_fts_lemma_shard_routing_manifest(array $runtimeFiles): array
{
    return [
        'schema_version' => 1,
        'pack_id' => 'en-shard-routing-containment',
        'language' => 'en',
        'version' => '1',
        'fixture_only' => false,
        'default_enabled' => false,
        'capabilities' => ['dictionary-lemmatizer', 'indexed-runtime-lookups'],
        'runtime' => [
            'format' => WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV,
            'ambiguity_policy' => 'ambiguous_surface_noop',
            'total_rows' => count($runtimeFiles),
            'files' => $runtimeFiles,
        ],
        'source' => [
            'name' => 'Project-owned shard-routing source',
            'version' => '1',
            'url' => 'urn:wp-fts:test:shard-routing',
            'artifact_sha256' => str_repeat('a', 64),
            'byte_count' => 1,
        ],
        'license' => [
            'spdx_id' => 'CC0-1.0',
            'notice_path' => 'NOTICE.txt',
        ],
        'attribution' => ['note' => 'Project-owned generated shard-routing fixture.'],
        'provenance' => ['no_runtime_network_access' => true],
    ];
}

/** @param array<string,mixed> $manifest */
function wp_fts_lemma_shard_routing_write_manifest(string $path, array $manifest): void
{
    file_put_contents($path, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** Remove all generated routing artifacts without following symlinks. */
function wp_fts_lemma_shard_routing_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child)) {
            wp_fts_lemma_shard_routing_remove_tree($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
}

/** @return array{VmHWM_bytes:?int,VmRSS_bytes:?int} */
function wp_fts_lemma_shard_routing_proc_status(): array
{
    if (!is_readable('/proc/self/status')) {
        return ['VmHWM_bytes' => null, 'VmRSS_bytes' => null];
    }
    $values = [];
    foreach (file('/proc/self/status', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $separator = strpos($line, ':');
        if ($separator === false) {
            continue;
        }
        $key = substr($line, 0, $separator);
        if (!in_array($key, ['VmHWM', 'VmRSS'], true)) {
            continue;
        }
        $value = trim(substr($line, $separator + 1));
        $space = strpos($value, ' ');
        if ($space !== false && $space > 0 && strspn(substr($value, 0, $space), '0123456789') === $space && strtolower(trim(substr($value, $space + 1))) === 'kb') {
            $values[$key] = (int) substr($value, 0, $space) * 1024;
        }
    }

    return [
        'VmHWM_bytes' => $values['VmHWM'] ?? null,
        'VmRSS_bytes' => $values['VmRSS'] ?? null,
    ];
}
