<?php
declare(strict_types=1);

$GLOBALS['wp_fts_eager_byte_runtime_options'] = null;
if (!function_exists('get_option')) {
    /** Return only the analyzer option supplied by this isolated runtime fixture. */
    function get_option(string $option, mixed $defaultValue = false): mixed
    {
        $configured = $GLOBALS['wp_fts_eager_byte_runtime_options'] ?? null;
        return $option === 'wp_fts_analyzer_options' && is_array($configured)
            ? $configured
            : $defaultValue;
    }
}

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$started = microtime(true);
$peakBefore = memory_get_peak_usage(true);
$root = sys_get_temp_dir() . '/wp-fts-eager-byte-config-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0777, true) && !is_dir($root)) {
    throw new RuntimeException('Could not create the eager-fixture byte aggregate root.');
}

try {
    $limit = WP_FTS_LemmaPackLimits::MAX_CONFIGURED_EAGER_FIXTURE_RUNTIME_BYTES;
    $half = intdiv($limit, 2);
    $modes = [];
    foreach (['plain' => false, 'gzip' => true] as $mode => $gzip) {
        $exact = wp_fts_eager_byte_write_pair($root . '/' . $mode . '-exact', $half, $half, $gzip, false);
        $overflow = wp_fts_eager_byte_write_pair(
            $root . '/' . $mode . '-overflow',
            $half,
            $half + 1,
            $gzip,
            !$gzip
        );
        $modes[$mode] = [
            'exact_decoded_bytes' => $exact['decoded_bytes'],
            'overflow_decoded_bytes' => $overflow['decoded_bytes'],
            'exact_physical_bytes' => $exact['physical_bytes'],
            'overflow_physical_bytes' => $overflow['physical_bytes'],
            'overflow_first_runtime_digest_matches' => $overflow['first_runtime_digest_matches'],
            'exact' => wp_fts_eager_byte_exercise($exact['options']),
            'overflow' => wp_fts_eager_byte_exercise($overflow['options']),
        ];
    }

    $lateCorrupt = wp_fts_eager_byte_write_late_corrupt_pack(
        $root . '/late-corrupt',
        $limit,
        20000
    );
    $lateCorruptAliases = wp_fts_eager_byte_language_aliases($lateCorrupt['manifest']);
    $lateCorruptAttempts = wp_fts_eager_byte_exercise_late_corrupt_aliases(
        $lateCorrupt['manifest'],
        $lateCorruptAliases
    );

    $repairable = wp_fts_eager_byte_write_pack(
        $root . '/repairable',
        'repair',
        4096,
        false,
        false,
        'pl'
    );
    $authoritativePreflight = wp_fts_eager_byte_exercise_repaired_preflight(
        $repairable['manifest'],
        wp_fts_eager_byte_language_aliases($repairable['manifest'])
    );
    $appearing = wp_fts_eager_byte_write_pack(
        $root . '/appearing',
        'appear',
        4096,
        false,
        false,
        'pl'
    );
    $authoritativeAppearance = wp_fts_eager_byte_exercise_appearing_preflight(
        $appearing['manifest'],
        $repairable['manifest']
    );

    $discarded = wp_fts_eager_byte_write_pack(
        $root . '/canonical-discarded',
        'discarded',
        4096,
        false,
        true
    );
    wp_fts_eager_byte_declare_runtime_rows($discarded['manifest'], 50000);
    $survivor = wp_fts_eager_byte_write_pack(
        $root . '/canonical-survivor',
        'survivor',
        4096,
        false,
        false
    );
    $canonicalLastWins = wp_fts_eager_byte_exercise_canonical_last_wins(
        $discarded['manifest'],
        $survivor['manifest']
    );
    $canonicalCrossMap = wp_fts_eager_byte_exercise_canonical_cross_map(
        $discarded['manifest'],
        $survivor['manifest']
    );
    $canonicalPolishPrecedence = wp_fts_eager_byte_exercise_canonical_polish_precedence(
        $repairable['manifest'],
        $lateCorrupt['manifest']
    );

    $peakAfter = memory_get_peak_usage(true);
    echo json_encode([
        'case' => 'configured-eager-fixture-bytes',
        'limit_bytes' => $limit,
        'physical_packs' => count(array_unique($exact['options'])),
        'configured_languages' => count($exact['options']),
        'modes' => $modes,
        'late_corrupt_aliases' => $lateCorruptAttempts + [
            'runtime_rows' => $lateCorrupt['runtime_rows'],
            'runtime_bytes' => $lateCorrupt['physical_bytes'],
            'configured_aliases' => count($lateCorruptAliases),
        ],
        'authoritative_preflight' => $authoritativePreflight,
        'authoritative_appearance' => $authoritativeAppearance,
        'canonical_last_wins' => $canonicalLastWins,
        'canonical_cross_map' => $canonicalCrossMap,
        'canonical_polish_precedence' => $canonicalPolishPrecedence,
        'elapsed_seconds' => microtime(true) - $started,
        'php_peak_bytes' => $peakAfter,
        'php_peak_delta_bytes' => max(0, $peakAfter - $peakBefore),
        'proc_status' => wp_fts_eager_byte_proc_status(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} finally {
    wp_fts_eager_byte_remove_tree($root);
}

/**
 * Build two physical packs while configuring the first under two language tags.
 * The duplicate alias proves aggregate accounting follows retained maps rather
 * than option cardinality.
 *
 * @return array{options:array<string,string>,decoded_bytes:int,physical_bytes:int,first_runtime_digest_matches:bool}
 */
function wp_fts_eager_byte_write_pair(
    string $root,
    int $firstBytes,
    int $secondBytes,
    bool $gzip,
    bool $corruptFirstDigest
): array
{
    if (!mkdir($root, 0777, true)) {
        throw new RuntimeException('Could not create an eager-fixture byte pair.');
    }

    $first = wp_fts_eager_byte_write_pack(
        $root . '/first',
        'first',
        $firstBytes,
        $gzip,
        $corruptFirstDigest
    );
    $second = wp_fts_eager_byte_write_pack($root . '/second', 'second', $secondBytes, $gzip, false);

    return [
        'options' => [
            'qaa' => $first['manifest'],
            'qaa-x-alias' => $first['manifest'],
            'qaa-x-second' => $second['manifest'],
        ],
        'decoded_bytes' => $first['decoded_bytes'] + $second['decoded_bytes'],
        'physical_bytes' => $first['physical_bytes'] + $second['physical_bytes'],
        'first_runtime_digest_matches' => $first['runtime_digest_matches'],
    ];
}

/** @return array{manifest:string,decoded_bytes:int,physical_bytes:int,runtime_digest_matches:bool} */
function wp_fts_eager_byte_write_pack(
    string $root,
    string $label,
    int $decodedBytes,
    bool $gzip,
    bool $corruptDigest,
    string $language = 'qaa'
): array
{
    if (!mkdir($root) || file_put_contents($root . '/NOTICE.txt', "Project-owned eager byte fixture.\n") === false) {
        throw new RuntimeException('Could not create one eager-fixture byte pack.');
    }

    $runtimeName = $gzip ? 'runtime.tsv.gz' : 'runtime.tsv';
    $runtimePath = $root . '/' . $runtimeName;
    $row = "surface\tlemma{$label}\n";
    if ($decodedBytes < strlen($row)) {
        throw new RuntimeException('Eager-fixture byte target is too small for its runtime row.');
    }
    wp_fts_eager_byte_write_runtime($runtimePath, $row, $decodedBytes, $gzip);

    $runtimeDigest = hash_file('sha256', $runtimePath);
    if (!is_string($runtimeDigest)) {
        throw new RuntimeException('Could not hash one eager-fixture byte runtime.');
    }
    $declaredRuntimeDigest = $corruptDigest
        ? str_repeat($runtimeDigest[0] === 'f' ? 'e' : 'f', 64)
        : $runtimeDigest;
    $runtimeFile = [
        'path' => $runtimeName,
        'sha256' => $declaredRuntimeDigest,
        'rows' => 1,
        'first_surface' => 'surface',
        'last_surface' => 'surface',
    ];
    if ($gzip) {
        $runtimeFile['compression'] = 'gzip';
    }

    $manifest = [
        'schema_version' => 1,
        'pack_id' => $language . '-eager-byte-' . $label . ($gzip ? '-gzip' : '-plain'),
        'language' => $language,
        'version' => '1',
        'fixture_only' => true,
        'default_enabled' => false,
        'capabilities' => ['dictionary-lemmatizer', 'ambiguous-form-noop', 'normalized-runtime-rows'],
        'runtime' => [
            'format' => WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV,
            'normalization' => "WP_FTS_Normalizer {$language} with fold_diacritics=true",
            'ambiguity_policy' => 'ambiguous_surface_noop',
            'total_rows' => 1,
            'total_sha256' => hash('sha256', $row),
            'files' => [$runtimeFile],
        ],
        'source' => [
            'name' => 'Project-owned eager byte aggregate source',
            'version' => '1',
            'url' => 'urn:wp-fts:test:eager-byte:' . $label,
            'artifact_sha256' => hash('sha256', 'eager-byte-' . $label),
            'byte_count' => (int) filesize($runtimePath),
        ],
        'license' => ['spdx_id' => 'CC0-1.0', 'notice_path' => 'NOTICE.txt'],
        'attribution' => ['note' => 'Project-owned generated fixture.'],
        'provenance' => [
            'no_runtime_network_access' => true,
            'no_full_third_party_dictionary_dump' => true,
        ],
    ];
    $manifestPath = $root . '/manifest.json';
    if (file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Could not write one eager-fixture byte manifest.');
    }

    return [
        'manifest' => $manifestPath,
        'decoded_bytes' => wp_fts_eager_byte_decoded_size($runtimePath, $gzip),
        'physical_bytes' => (int) filesize($runtimePath),
        'runtime_digest_matches' => hash_equals($runtimeDigest, $declaredRuntimeDigest),
    ];
}

/**
 * Build an exact-size eager pack whose aggregate row digest fails only after
 * every runtime row has been hashed, parsed, normalized, and retained.
 *
 * @return array{manifest:string,runtime_rows:int,physical_bytes:int}
 */
function wp_fts_eager_byte_write_late_corrupt_pack(
    string $root,
    int $runtimeBytes,
    int $runtimeRows
): array
{
    if (!mkdir($root) || file_put_contents($root . '/NOTICE.txt', "Project-owned late-corrupt fixture.\n") === false) {
        throw new RuntimeException('Could not create the late-corrupt eager pack.');
    }

    $runtimePath = $root . '/runtime.tsv';
    $handle = fopen($runtimePath, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create the late-corrupt eager runtime.');
    }
    $rowsDigest = hash_init('sha256');
    $written = 0;
    try {
        for ($row = 0; $row < $runtimeRows; $row++) {
            $suffix = str_pad((string) $row, 5, '0', STR_PAD_LEFT);
            $surface = 's' . $suffix . str_repeat('x', 180);
            $lemma = 'l' . $suffix . str_repeat('y', 200);
            $line = $surface . "\t" . $lemma . "\n";
            wp_fts_eager_byte_write_all($handle, $line, false);
            hash_update($rowsDigest, $line);
            $written += strlen($line);
        }
        $remaining = $runtimeBytes - $written;
        if ($remaining < 0) {
            throw new RuntimeException('Late-corrupt rows exceed the requested runtime byte boundary.');
        }
        while ($remaining > 0) {
            $length = min($remaining, WP_FTS_LemmaPackLimits::MAX_RUNTIME_LINE_BYTES + 1);
            $line = $length === 1
                ? '#'
                : ($length === 2 ? "#\n" : '#' . str_repeat('z', $length - 2) . "\n");
            wp_fts_eager_byte_write_all($handle, $line, false);
            $remaining -= strlen($line);
        }
    } finally {
        fclose($handle);
    }

    $runtimeDigest = hash_file('sha256', $runtimePath);
    if (!is_string($runtimeDigest)) {
        throw new RuntimeException('Could not hash the late-corrupt eager runtime.');
    }
    $validTotalDigest = hash_final($rowsDigest);
    $manifest = [
        'schema_version' => 1,
        'pack_id' => 'pl-eager-byte-late-corrupt',
        'language' => 'pl',
        'version' => '1',
        'fixture_only' => true,
        'default_enabled' => false,
        'capabilities' => ['dictionary-lemmatizer', 'ambiguous-form-noop', 'normalized-runtime-rows'],
        'runtime' => [
            'format' => WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV,
            'normalization' => 'WP_FTS_Normalizer pl with fold_diacritics=true',
            'ambiguity_policy' => 'ambiguous_surface_noop',
            'total_rows' => $runtimeRows,
            'total_sha256' => str_repeat($validTotalDigest[0] === 'f' ? 'e' : 'f', 64),
            'files' => [[
                'path' => 'runtime.tsv',
                'sha256' => $runtimeDigest,
                'rows' => $runtimeRows,
                'first_surface' => 's00000' . str_repeat('x', 180),
                'last_surface' => 's' . str_pad((string) ($runtimeRows - 1), 5, '0', STR_PAD_LEFT)
                    . str_repeat('x', 180),
            ]],
        ],
        'source' => [
            'name' => 'Project-owned late-corrupt eager source',
            'version' => '1',
            'url' => 'urn:wp-fts:test:eager-byte:late-corrupt',
            'artifact_sha256' => hash('sha256', 'eager-byte-late-corrupt'),
            'byte_count' => $runtimeBytes,
        ],
        'license' => ['spdx_id' => 'CC0-1.0', 'notice_path' => 'NOTICE.txt'],
        'attribution' => ['note' => 'Project-owned generated fixture.'],
        'provenance' => [
            'no_runtime_network_access' => true,
            'no_full_third_party_dictionary_dump' => true,
        ],
    ];
    $manifestPath = $root . '/manifest.json';
    if (file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Could not write the late-corrupt eager manifest.');
    }

    return [
        'manifest' => $manifestPath,
        'runtime_rows' => $runtimeRows,
        'physical_bytes' => (int) filesize($runtimePath),
    ];
}

/** @return array<string,string> */
function wp_fts_eager_byte_language_aliases(string $manifestPath): array
{
    $aliases = ['pl' => $manifestPath];
    for ($alias = 1; $alias < WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_LANGUAGES; $alias++) {
        $aliases['pl-x-' . str_pad((string) $alias, 2, '0', STR_PAD_LEFT)] = $manifestPath;
    }

    return $aliases;
}

/**
 * Compare one alias with the maximum alias set. The late aggregate digest
 * failure makes every physical retry repeat the complete 8-MiB PHP row scan.
 *
 * @param array<string,string> $aliases
 * @return array<string,mixed>
 */
function wp_fts_eager_byte_exercise_late_corrupt_aliases(
    string $manifestPath,
    array $aliases
): array
{
    $singlePipeline = wp_fts_eager_byte_time_pipeline(['pl' => $manifestPath]);
    $aliasPipeline = wp_fts_eager_byte_time_pipeline($aliases);
    $singleStatus = wp_fts_eager_byte_time_statuses(['pl' => $manifestPath]);
    $aliasStatus = wp_fts_eager_byte_time_statuses($aliases);

    return [
        'single_pipeline' => $singlePipeline,
        'alias_pipeline' => $aliasPipeline,
        'single_status' => $singleStatus,
        'alias_status' => $aliasStatus,
        'pipeline_time_ratio' => $aliasPipeline['elapsed_seconds']
            / max(0.000001, $singlePipeline['elapsed_seconds']),
        'status_time_ratio' => $aliasStatus['elapsed_seconds']
            / max(0.000001, $singleStatus['elapsed_seconds']),
    ];
}

/** @return array{elapsed_seconds:float,pack_active:bool} */
function wp_fts_eager_byte_time_pipeline(array $options): array
{
    $started = microtime(true);
    $pipeline = new WP_FTS_LanguagePipeline(['lemma_packs_by_lang' => $options]);
    $elapsed = microtime(true) - $started;

    return [
        'elapsed_seconds' => $elapsed,
        'pack_active' => $pipeline->lemma_pack_diagnostics('pl') !== null,
    ];
}

/** @return array{elapsed_seconds:float,statuses:int,active:int,corrupt:int} */
function wp_fts_eager_byte_time_statuses(array $options): array
{
    $started = microtime(true);
    $statuses = wp_fts_eager_byte_runtime_statuses($options);
    $elapsed = microtime(true) - $started;

    return [
        'elapsed_seconds' => $elapsed,
        'statuses' => count($statuses),
        'active' => count(array_filter(
            $statuses,
            static fn(array $status): bool => ($status['status'] ?? null) === 'active'
        )),
        'corrupt' => count(array_filter(
            $statuses,
            static fn(array $status): bool => ($status['status'] ?? null) === 'corrupt'
        )),
    ];
}

/**
 * Repair an unreadable manifest inside its first failed read. Every alias must
 * retain that first failure rather than admitting the repaired generation.
 *
 * @param array<string,string> $aliases
 * @return array<string,mixed>
 */
function wp_fts_eager_byte_exercise_repaired_preflight(
    string $manifestPath,
    array $aliases
): array
{
    wp_fts_eager_byte_set_small_descriptor_limit();

    $pipelineRepair = wp_fts_eager_byte_prepare_manifest_repair($manifestPath);
    $pipelineHandles = wp_fts_eager_byte_exhaust_descriptors();
    set_error_handler(wp_fts_eager_byte_repair_handler($pipelineRepair, $pipelineHandles));
    try {
        $pipeline = new WP_FTS_LanguagePipeline(['lemma_packs_by_lang' => $aliases]);
    } finally {
        restore_error_handler();
        wp_fts_eager_byte_close_handles($pipelineHandles);
        wp_fts_eager_byte_finish_manifest_repair($pipelineRepair);
    }

    $statusRepair = wp_fts_eager_byte_prepare_manifest_repair($manifestPath);
    $statusHandles = wp_fts_eager_byte_exhaust_descriptors();
    set_error_handler(wp_fts_eager_byte_repair_handler($statusRepair, $statusHandles));
    try {
        $statuses = wp_fts_eager_byte_runtime_statuses($aliases);
    } finally {
        restore_error_handler();
        wp_fts_eager_byte_close_handles($statusHandles);
        wp_fts_eager_byte_finish_manifest_repair($statusRepair);
    }

    return [
        'configured_aliases' => count($aliases),
        'pipeline_repair_warnings' => $pipelineRepair['repairs'],
        'pipeline_pack_active' => $pipeline->lemma_pack_diagnostics('pl') !== null,
        'status_repair_warnings' => $statusRepair['repairs'],
        'status_active' => count(array_filter(
            $statuses,
            static fn(array $status): bool => ($status['status'] ?? null) === 'active'
        )),
        'status_corrupt' => count(array_filter(
            $statuses,
            static fn(array $status): bool => ($status['status'] ?? null) === 'corrupt'
        )),
    ];
}

/**
 * Make one missing manifest appear while a later manifest's preflight is
 * failing. Neither caller may revisit the first path in its construction pass.
 *
 * @return array<string,mixed>
 */
function wp_fts_eager_byte_exercise_appearing_preflight(
    string $appearingManifest,
    string $triggerManifest
): array
{
    wp_fts_eager_byte_set_small_descriptor_limit();
    $options = [
        'pl' => $appearingManifest,
        'pl-x-trigger' => $triggerManifest,
    ];

    $pipelineRepair = wp_fts_eager_byte_prepare_manifest_repair($triggerManifest, $appearingManifest);
    $pipelineHandles = wp_fts_eager_byte_exhaust_descriptors();
    set_error_handler(wp_fts_eager_byte_repair_handler($pipelineRepair, $pipelineHandles));
    try {
        $pipeline = new WP_FTS_LanguagePipeline(['lemma_packs_by_lang' => $options]);
    } finally {
        restore_error_handler();
        wp_fts_eager_byte_close_handles($pipelineHandles);
        wp_fts_eager_byte_finish_manifest_repair($pipelineRepair);
    }

    $statusRepair = wp_fts_eager_byte_prepare_manifest_repair($triggerManifest, $appearingManifest);
    $statusHandles = wp_fts_eager_byte_exhaust_descriptors();
    set_error_handler(wp_fts_eager_byte_repair_handler($statusRepair, $statusHandles));
    try {
        $statuses = wp_fts_eager_byte_runtime_statuses($options);
    } finally {
        restore_error_handler();
        wp_fts_eager_byte_close_handles($statusHandles);
        wp_fts_eager_byte_finish_manifest_repair($statusRepair);
    }

    return [
        'configured_languages' => count($options),
        'pipeline_repair_warnings' => $pipelineRepair['repairs'],
        'pipeline_pack_active' => $pipeline->lemma_pack_diagnostics('pl') !== null,
        'status_repair_warnings' => $statusRepair['repairs'],
        'status_active' => count(array_filter(
            $statuses,
            static fn(array $status): bool => ($status['status'] ?? null) === 'active'
        )),
        'status_corrupt' => count(array_filter(
            $statuses,
            static fn(array $status): bool => ($status['status'] ?? null) === 'corrupt'
        )),
        'status_not_active' => count(array_filter(
            $statuses,
            static fn(array $status): bool => ($status['status'] ?? null) === 'not-active'
        )),
    ];
}

/** Set a portable low descriptor ceiling for deterministic open failure. */
function wp_fts_eager_byte_set_small_descriptor_limit(): void
{
    if (!function_exists('posix_getrlimit') || !function_exists('posix_setrlimit')) {
        throw new RuntimeException('The preflight TOCTOU fixture requires POSIX descriptor limits.');
    }
    $limits = posix_getrlimit();
    $hard = $limits['hard openfiles'] ?? null;
    $hardLimit = is_int($hard) ? $hard : POSIX_RLIMIT_INFINITY;
    $softLimit = is_int($hard) ? min(64, $hard) : 64;
    if ($softLimit < 16 || !posix_setrlimit(POSIX_RLIMIT_NOFILE, $softLimit, $hardLimit)) {
        throw new RuntimeException('Could not set the preflight TOCTOU descriptor limit.');
    }
}

/** @return array{manifest:string,backup:string,repairs:int,appear_manifest:?string,appear_backup:?string} */
function wp_fts_eager_byte_prepare_manifest_repair(
    string $manifestPath,
    ?string $appearingManifest = null
): array
{
    $backupPath = $manifestPath . '.preflight-valid';
    $appearingBackup = $appearingManifest === null
        ? null
        : $appearingManifest . '.preflight-absent';
    if ($appearingManifest !== null && !rename($appearingManifest, (string) $appearingBackup)) {
        throw new RuntimeException('Could not hide the preflight TOCTOU appearing manifest.');
    }
    if (!rename($manifestPath, $backupPath)
        || file_put_contents($manifestPath, "{\"broken\":\n") === false
    ) {
        throw new RuntimeException('Could not prepare the preflight TOCTOU manifest generation.');
    }
    clearstatcache(true, $manifestPath);

    return [
        'manifest' => $manifestPath,
        'backup' => $backupPath,
        'repairs' => 0,
        'appear_manifest' => $appearingManifest,
        'appear_backup' => $appearingBackup,
    ];
}

/** @return resource[] */
function wp_fts_eager_byte_exhaust_descriptors(): array
{
    $handles = [];
    while (($handle = @fopen('/dev/null', 'rb')) !== false) {
        $handles[] = $handle;
    }

    return $handles;
}

/**
 * Return an error handler that frees one descriptor and atomically installs
 * the valid manifest generation after the in-flight open has already failed.
 *
 * @param array{manifest:string,backup:string,repairs:int,appear_manifest:?string,appear_backup:?string} $repair
 * @param resource[] $handles
 */
function wp_fts_eager_byte_repair_handler(array &$repair, array &$handles): Closure
{
    return static function (int $_severity, string $message) use (&$repair, &$handles): bool {
        if ($repair['repairs'] !== 0 || !str_contains($message, $repair['manifest'])) {
            return false;
        }
        $handle = array_pop($handles);
        if (!is_resource($handle)) {
            throw new RuntimeException('The preflight TOCTOU fixture had no descriptor to release.');
        }
        fclose($handle);
        if (!unlink($repair['manifest']) || !rename($repair['backup'], $repair['manifest'])) {
            throw new RuntimeException('Could not repair the preflight TOCTOU manifest.');
        }
        if ($repair['appear_manifest'] !== null
            && !rename((string) $repair['appear_backup'], $repair['appear_manifest'])
        ) {
            throw new RuntimeException('Could not install the preflight TOCTOU appearing manifest.');
        }
        clearstatcache(true, $repair['manifest']);
        $repair['repairs']++;

        return true;
    };
}

/** @param resource[] $handles */
function wp_fts_eager_byte_close_handles(array &$handles): void
{
    foreach ($handles as $handle) {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }
    $handles = [];
}

/** @param array{manifest:string,backup:string,repairs:int,appear_manifest:?string,appear_backup:?string} $repair */
function wp_fts_eager_byte_finish_manifest_repair(array $repair): void
{
    if ($repair['repairs'] !== 1 || !is_file($repair['manifest']) || file_exists($repair['backup'])) {
        throw new RuntimeException('The preflight TOCTOU manifest was not repaired exactly once.');
    }
    if ($repair['appear_manifest'] !== null
        && (!is_file($repair['appear_manifest']) || file_exists((string) $repair['appear_backup']))
    ) {
        throw new RuntimeException('The preflight TOCTOU manifest did not appear exactly once.');
    }
}

/** Change only declared row metadata to create a discarded aggregate adversary. */
function wp_fts_eager_byte_declare_runtime_rows(string $manifestPath, int $rows): void
{
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $manifest['runtime']['total_rows'] = $rows;
    $manifest['runtime']['files'][0]['rows'] = $rows;
    if (file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Could not update discarded canonical-alias row metadata.');
    }
}

/** Prove raw spellings collapse to their last canonical assignment preflight. */
function wp_fts_eager_byte_exercise_canonical_last_wins(
    string $discardedManifest,
    string $survivingManifest
): array
{
    $pipeline = new WP_FTS_LanguagePipeline([
        'lemma_packs_by_lang' => [
            'qaa_US' => $discardedManifest,
            'qaa-us' => $survivingManifest,
        ],
    ]);

    return [
        'canonical_language' => $pipeline->canonicalize_language('qaa_US'),
        'pack_active' => $pipeline->lemma_pack_diagnostics('qaa-US') !== null,
        'morphology' => array_column($pipeline->analyze_detailed('surface', 'qaa-US'), 'term'),
        'discarded_declared_rows' => 50000,
        'surviving_declared_rows' => 1,
    ];
}

/** Prove two maximum raw alias maps merge as 32 effective languages. */
function wp_fts_eager_byte_exercise_canonical_cross_map(
    string $discardedManifest,
    string $survivingManifest
): array
{
    $lowerPrecedence = [];
    $higherPrecedence = [];
    for ($language = 0; $language < WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_LANGUAGES; $language++) {
        $suffix = str_pad((string) $language, 2, '0', STR_PAD_LEFT);
        $lowerPrecedence['qaa_x_' . $suffix] = $discardedManifest;
        $higherPrecedence['qaa-x-' . $suffix] = $survivingManifest;
    }
    $pipeline = new WP_FTS_LanguagePipeline([
        'lemmatizer_packs_by_lang' => $lowerPrecedence,
        'lemma_packs_by_lang' => $higherPrecedence,
    ]);

    return [
        'raw_entries' => count($lowerPrecedence) + count($higherPrecedence),
        'effective_languages' => count($higherPrecedence),
        'pack_active' => $pipeline->lemma_pack_diagnostics('qaa-x-00') !== null,
        'morphology' => array_column($pipeline->analyze_detailed('surface', 'qaa-x-00'), 'term'),
    ];
}

/** Prove a canonical generic Polish assignment outranks its legacy fallback. */
function wp_fts_eager_byte_exercise_canonical_polish_precedence(
    string $genericManifest,
    string $legacyManifest
): array
{
    $pipeline = new WP_FTS_LanguagePipeline([
        'lemma_packs_by_lang' => ['PL' => $genericManifest],
        'polish_lemma_pack' => $legacyManifest,
    ]);

    return [
        'pack_active' => $pipeline->lemma_pack_diagnostics('pl') !== null,
        'morphology' => array_column($pipeline->analyze_detailed('surface', 'pl'), 'term'),
    ];
}

/** Write one valid row followed by bounded comment lines to an exact decoded size. */
function wp_fts_eager_byte_write_runtime(string $path, string $row, int $decodedBytes, bool $gzip): void
{
    $handle = $gzip ? gzopen($path, 'wb9') : fopen($path, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create one eager-fixture byte runtime.');
    }

    try {
        wp_fts_eager_byte_write_all($handle, $row, $gzip);
        $remaining = $decodedBytes - strlen($row);
        while ($remaining > 0) {
            $length = min($remaining, WP_FTS_LemmaPackLimits::MAX_RUNTIME_LINE_BYTES + 1);
            if ($length === 1) {
                $line = '#';
            } elseif ($length === 2) {
                $line = "#\n";
            } else {
                $line = '#' . str_repeat('x', $length - 2) . "\n";
            }
            wp_fts_eager_byte_write_all($handle, $line, $gzip);
            $remaining -= strlen($line);
        }
    } finally {
        $gzip ? gzclose($handle) : fclose($handle);
    }
}

/** @param resource $handle */
function wp_fts_eager_byte_write_all(mixed $handle, string $data, bool $gzip): void
{
    $offset = 0;
    $length = strlen($data);
    while ($offset < $length) {
        $chunk = substr($data, $offset);
        $written = $gzip ? gzwrite($handle, $chunk) : fwrite($handle, $chunk);
        if (!is_int($written) || $written < 1) {
            throw new RuntimeException('Could not write an eager-fixture byte runtime chunk.');
        }
        $offset += $written;
    }
}

/** Independently stream one generated runtime and return its decoded size. */
function wp_fts_eager_byte_decoded_size(string $path, bool $gzip): int
{
    $handle = $gzip ? gzopen($path, 'rb') : fopen($path, 'rb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not reopen one eager-fixture byte runtime.');
    }

    $bytes = 0;
    try {
        while ($gzip ? !gzeof($handle) : !feof($handle)) {
            $chunk = $gzip ? gzread($handle, 8192) : fread($handle, 8192);
            if (!is_string($chunk)) {
                throw new RuntimeException('Could not measure one eager-fixture byte runtime.');
            }
            if ($chunk === '') {
                break;
            }
            $bytes += strlen($chunk);
        }
    } finally {
        $gzip ? gzclose($handle) : fclose($handle);
    }

    return $bytes;
}

/** @return array<string,mixed> */
function wp_fts_eager_byte_exercise(array $options): array
{
    $headersBefore = WP_FTS_LemmaPackLookupIndex::metadata_diagnostics();
    $ioBefore = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
    $pipelineError = null;
    $morphologies = [];
    try {
        $pipeline = new WP_FTS_LanguagePipeline(['lemma_packs_by_lang' => $options]);
        foreach (['qaa' => 'lemmafirst', 'qaa-x-alias' => 'lemmafirst', 'qaa-x-second' => 'lemmasecond'] as $language => $expected) {
            $morphologies[$language] = array_column($pipeline->analyze_detailed('surface', $language), 'term');
        }
        unset($pipeline);
        gc_collect_cycles();
    } catch (Throwable $caught) {
        $pipelineError = $caught;
    }

    $statusError = null;
    $activeStatuses = 0;
    try {
        $statuses = wp_fts_eager_byte_runtime_statuses($options);
        $activeStatuses = is_array($statuses)
            ? count(array_filter(
                $statuses,
                static fn(array $status): bool => ($status['status'] ?? null) === 'active'
            ))
            : 0;
        unset($statuses);
        gc_collect_cycles();
    } catch (Throwable $caught) {
        $statusError = $caught;
    }
    $headersAfter = WP_FTS_LemmaPackLookupIndex::metadata_diagnostics();
    $ioAfter = WP_FTS_LemmaPackLookupIndex::io_diagnostics();

    return [
        'pipeline_error_class' => $pipelineError instanceof Throwable ? get_class($pipelineError) : null,
        'pipeline_reason_code' => $pipelineError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded
            ? $pipelineError->reason_code
            : null,
        'status_error_class' => $statusError instanceof Throwable ? get_class($statusError) : null,
        'status_reason_code' => $statusError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded
            ? $statusError->reason_code
            : null,
        'active_statuses' => $activeStatuses,
        'morphologies' => $morphologies,
        'lookup_header_opens' => $headersAfter['lookup_header_opens'] - $headersBefore['lookup_header_opens'],
        'indexed_io' => wp_fts_eager_byte_diagnostic_delta($ioBefore, $ioAfter),
    ];
}

/**
 * Exercise the public WordPress runtime status entry point with only this
 * fixture's packs enabled. Reset its request cache between exact/+1 cases.
 *
 * @param array<string,string> $options
 * @return array<int,array<string,mixed>>
 */
function wp_fts_eager_byte_runtime_statuses(array $options): array
{
    $cache = new ReflectionProperty(WP_FTS_Plugin::class, 'runtime_analyzer_pack_statuses_cache');
    $cache->setAccessible(true);
    $cache->setValue(null, null);
    $GLOBALS['wp_fts_eager_byte_runtime_options'] = [
        'lemmatizer_packs_by_lang' => array_merge(['pl' => false], $options),
    ];

    try {
        return WP_FTS_Plugin::runtime_analyzer_pack_statuses();
    } finally {
        $GLOBALS['wp_fts_eager_byte_runtime_options'] = null;
        $cache->setValue(null, null);
    }
}

/** @return array<string,int> */
function wp_fts_eager_byte_diagnostic_delta(array $before, array $after): array
{
    $delta = [];
    foreach ($after as $key => $value) {
        $delta[$key] = (int) $value - (int) ($before[$key] ?? 0);
    }

    return $delta;
}

/** @return array{VmHWM_bytes:?int,VmRSS_bytes:?int} */
function wp_fts_eager_byte_proc_status(): array
{
    $result = ['VmHWM_bytes' => null, 'VmRSS_bytes' => null];
    $status = @file('/proc/self/status', FILE_IGNORE_NEW_LINES);
    if (!is_array($status)) {
        return $result;
    }

    foreach ($status as $line) {
        $separator = strpos($line, ':');
        if ($separator === false) {
            continue;
        }
        $name = substr($line, 0, $separator);
        if (!array_key_exists($name . '_bytes', $result)) {
            continue;
        }
        $value = trim(substr($line, $separator + 1));
        $space = strpos($value, ' ');
        $kilobytes = (int) ($space === false ? $value : substr($value, 0, $space));
        $result[$name . '_bytes'] = $kilobytes * 1024;
    }

    return $result;
}

/** Remove one generated eager-fixture byte directory tree. */
function wp_fts_eager_byte_remove_tree(string $path): void
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
            wp_fts_eager_byte_remove_tree($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
}
