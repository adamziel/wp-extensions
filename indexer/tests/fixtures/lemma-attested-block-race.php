<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/components/full-text-search/src/bootstrap.php';

wp_fts_reset_block_race_attestation_cache();
$cacheBound = wp_fts_probe_block_race_cache_bound();
wp_fts_reset_block_race_attestation_cache();
$startedAt = microtime(true);
$memoryBefore = memory_get_usage(true);
$root = sys_get_temp_dir() . '/wp-fts-lemma-block-race-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0777, true) && !is_dir($root)) {
    throw new RuntimeException('Could not create the lemma block-race fixture directory.');
}

try {
    $atomic = wp_fts_block_race_case($root . '/atomic');
    $atomicValidator = new WP_FTS_AnalyzerPackValidator();
    $compatibilityStreamsBefore = count(get_resources('stream'));
    $compatibilityResult = $atomicValidator->attest_runtime_file($atomic['file']);
    $compatibilityStreamsAfter = count(get_resources('stream'));
    $oversizedFile = $atomic['file'];
    $oversizedFile['lookup']['blocks'] = array_fill(
        0,
        WP_FTS_Analyzer_Config_Limits::MAX_LOOKUP_BLOCKS_PER_FILE + 1,
        ['offset' => 0, 'length' => 1]
    );
    $oversizedError = null;
    try {
        $atomicValidator->open_attested_runtime_file($oversizedFile);
    } catch (Throwable $error) {
        $oversizedError = $error;
    }
    $atomicAttestation = $atomicValidator->open_attested_runtime_file($atomic['file']);
    $cardinalityError = null;
    try {
        WP_FTS_LemmaPackLookupIndex::lookup_many(
            $atomic['metadata'],
            ['surface'],
            null,
            WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES,
            null,
            array_replace($atomicAttestation, ['block_sha256' => []])
        );
    } catch (Throwable $error) {
        $cardinalityError = $error;
    }
    $atomicReplacement = $atomic['runtime'] . '.replacement';
    file_put_contents($atomicReplacement, $atomic['mutant']);
    if (!rename($atomicReplacement, $atomic['runtime'])) {
        throw new RuntimeException('Could not publish the atomic runtime replacement.');
    }
    $compatibilityCorruptionError = null;
    try {
        (new WP_FTS_AnalyzerPackValidator())->attest_runtime_file($atomic['file']);
    } catch (Throwable $error) {
        $compatibilityCorruptionError = $error;
    }
    $atomicLookup = WP_FTS_LemmaPackLookupIndex::lookup_many(
        $atomic['metadata'],
        ['surface'],
        null,
        WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES,
        null,
        $atomicAttestation
    );
    wp_fts_close_block_race_attestation($atomicAttestation);

    $inPlace = wp_fts_block_race_case($root . '/in-place');
    $inPlaceValidator = new WP_FTS_AnalyzerPackValidator();
    $inPlaceAttestation = $inPlaceValidator->open_attested_runtime_file($inPlace['file']);
    $openedStat = fstat($inPlaceAttestation['runtime']);
    file_put_contents($inPlace['runtime'], $inPlace['mutant']);
    clearstatcache(true, $inPlace['runtime']);
    $mutatedStat = stat($inPlace['runtime']);
    $inPlaceError = null;
    try {
        WP_FTS_LemmaPackLookupIndex::lookup_many(
            $inPlace['metadata'],
            ['surface'],
            null,
            WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES,
            null,
            $inPlaceAttestation
        );
    } catch (Throwable $error) {
        $inPlaceError = $error;
    } finally {
        wp_fts_close_block_race_attestation($inPlaceAttestation);
    }

    file_put_contents($inPlace['runtime'], $inPlace['original']);
    $inPlaceValidator->begin_digest_attestation_batch();
    $restoredAttestation = $inPlaceValidator->open_attested_runtime_file($inPlace['file']);
    try {
        $restoredLookup = WP_FTS_LemmaPackLookupIndex::lookup_many(
            $inPlace['metadata'],
            ['surface'],
            null,
            WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES,
            null,
            $restoredAttestation
        );
    } finally {
        wp_fts_close_block_race_attestation($restoredAttestation);
    }

    $validation = wp_fts_block_race_case($root . '/validation');
    $validationIoBefore = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
    file_put_contents($validation['runtime'], $validation['mutant']);
    $validationError = null;
    try {
        WP_FTS_LemmaPackLookupIndex::validate_content(
            $validation['metadata'],
            $validation['metadata']['rows_sha256']
        );
    } catch (Throwable $error) {
        $validationError = $error;
    }
    file_put_contents($validation['runtime'], $validation['original']);
    $restoredValidation = true;
    try {
        WP_FTS_LemmaPackLookupIndex::validate_content(
            $validation['metadata'],
            $validation['metadata']['rows_sha256']
        );
    } catch (Throwable) {
        $restoredValidation = false;
    }
    $validationIoAfter = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
    $validationIo = [];
    foreach ($validationIoAfter as $name => $value) {
        $validationIo[$name] = $value - ($validationIoBefore[$name] ?? 0);
    }

    echo json_encode([
        'compatibility_method_exists' => method_exists(
            WP_FTS_AnalyzerPackValidator::class,
            'attest_runtime_file'
        ),
        'compatibility_stream_delta' => $compatibilityStreamsAfter - $compatibilityStreamsBefore,
        'compatibility_corruption_error_class' => $compatibilityCorruptionError === null
            ? null
            : get_class($compatibilityCorruptionError),
        'atomic_path_contains_mutant' => str_contains(
            (string) gzdecode((string) file_get_contents($atomic['runtime'])),
            "surface\tlemmab\n"
        ),
        'atomic_lookup' => array_keys($atomicLookup['lemmas_by_term']['surface'] ?? []),
        'same_inode_in_place_write' => is_array($openedStat)
            && is_array($mutatedStat)
            && ($openedStat['dev'] ?? null) === ($mutatedStat['dev'] ?? null)
            && ($openedStat['ino'] ?? null) === ($mutatedStat['ino'] ?? null),
        'in_place_error_class' => $inPlaceError === null ? null : get_class($inPlaceError),
        'in_place_error_message' => $inPlaceError?->getMessage(),
        'restored_lookup' => array_keys($restoredLookup['lemmas_by_term']['surface'] ?? []),
        'oversized_error_class' => $oversizedError === null ? null : get_class($oversizedError),
        'oversized_reason_code' => $oversizedError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded
            ? $oversizedError->reason_code
            : null,
        'cardinality_error_class' => $cardinalityError === null ? null : get_class($cardinalityError),
        'compatibility_void_result' => $compatibilityResult === null,
        'block_cache_bound' => $cacheBound,
        'validation_error_class' => $validationError === null ? null : get_class($validationError),
        'restored_validation' => $restoredValidation,
        'validation_io' => $validationIo,
        'digest_attestation' => $inPlaceValidator->digest_attestation_stats(),
        'indexed_io' => WP_FTS_LemmaPackLookupIndex::io_diagnostics(),
        'original_bytes' => strlen($inPlace['original']),
        'mutant_bytes' => strlen($inPlace['mutant']),
        'elapsed_seconds' => microtime(true) - $startedAt,
        'php_peak_delta_bytes' => max(0, memory_get_peak_usage(true) - $memoryBefore),
        'php_peak_bytes' => memory_get_peak_usage(true),
    ], JSON_THROW_ON_ERROR) . "\n";
} finally {
    wp_fts_reset_block_race_attestation_cache();
    wp_fts_remove_block_race_tree($root);
}

/**
 * Build one original indexed runtime and a same-length unmanifested payload.
 *
 * @return array{runtime:string,original:string,mutant:string,metadata:array<string,mixed>,file:array<string,mixed>}
 */
function wp_fts_block_race_case(string $directory): array
{
    if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create a lemma block-race case directory.');
    }
    $runtime = $directory . '/runtime.tsv.gz';
    $lookup = $runtime . '.lookup';
    $source = gzencode("surface\tlemmaa\n", 9, ZLIB_ENCODING_GZIP);
    if (!is_string($source)) {
        throw new RuntimeException('Could not encode the original runtime fixture.');
    }
    file_put_contents($runtime, $source);
    $sourceSha256 = hash_file('sha256', $runtime);
    if (!is_string($sourceSha256)) {
        throw new RuntimeException('Could not hash the original runtime fixture.');
    }
    $built = WP_FTS_LemmaPackLookupIndex::build(
        $runtime,
        WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
        $sourceSha256,
        $lookup
    );
    $original = file_get_contents($runtime);
    $mutant = gzencode("surface\tlemmab\n", 9, ZLIB_ENCODING_GZIP);
    if (!is_string($original) || !is_string($mutant) || strlen($original) !== strlen($mutant)) {
        throw new RuntimeException('The race fixture requires equal-length original and mutant runtimes.');
    }
    $metadata = WP_FTS_LemmaPackLookupIndex::metadata(
        $lookup,
        $runtime,
        $built['runtime_sha256'],
        1
    ) + ['sha256' => $built['sha256']];
    touch($runtime, time() + 5);
    touch($lookup, time() + 5);

    return [
        'runtime' => $runtime,
        'original' => $original,
        'mutant' => $mutant,
        'metadata' => $metadata,
        'file' => [
            'path' => $runtime,
            'sha256' => $built['runtime_sha256'],
            'lookup' => $metadata,
        ],
    ];
}

/** Close both descriptors owned by one runtime attestation. */
function wp_fts_close_block_race_attestation(array $attestation): void
{
    foreach (['runtime', 'lookup'] as $name) {
        if (is_resource($attestation[$name] ?? null)) {
            fclose($attestation[$name]);
        }
    }
}

/** Reset every process-static authenticated-block cache field used by this fixture. */
function wp_fts_reset_block_race_attestation_cache(): void
{
    foreach ([
        'runtimeBlockAttestations' => [],
        'runtimeBlockAttestationOrder' => [],
        'runtimeBlockAttestationCount' => 0,
    ] as $property => $value) {
        $reflection = new ReflectionProperty(WP_FTS_AnalyzerPackValidator::class, $property);
        $reflection->setValue(null, $value);
    }
}

/**
 * Fill the authenticated-block cache once past its ceiling and report eviction.
 *
 * @return array{blocks:int,files:int,oldest_evicted:bool}
 */
function wp_fts_probe_block_race_cache_bound(): array
{
    $validator = new WP_FTS_AnalyzerPackValidator();
    $cache = new ReflectionMethod(WP_FTS_AnalyzerPackValidator::class, 'cache_runtime_block_attestation');
    $digests = array_fill(
        0,
        WP_FTS_Analyzer_Config_Limits::MAX_LOOKUP_BLOCKS_PER_FILE,
        str_repeat('a', 64)
    );
    for ($file = 0; $file < 33; $file++) {
        $cache->invoke($validator, 'file-' . $file, 'layout', $digests);
    }

    $count = new ReflectionProperty(WP_FTS_AnalyzerPackValidator::class, 'runtimeBlockAttestationCount');
    $order = new ReflectionProperty(WP_FTS_AnalyzerPackValidator::class, 'runtimeBlockAttestationOrder');
    $entries = new ReflectionProperty(WP_FTS_AnalyzerPackValidator::class, 'runtimeBlockAttestations');
    $cachedEntries = $entries->getValue();

    return [
        'blocks' => $count->getValue(),
        'files' => count($order->getValue()),
        'oldest_evicted' => is_array($cachedEntries) && !isset($cachedEntries['file-0']),
    ];
}

/** Remove one fixture tree without following links outside it. */
function wp_fts_remove_block_race_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child) && !is_link($child)) {
            wp_fts_remove_block_race_tree($child);
            continue;
        }
        unlink($child);
    }
    rmdir($path);
}
