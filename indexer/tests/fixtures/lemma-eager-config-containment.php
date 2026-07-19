<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$started = microtime(true);
$peakBefore = memory_get_peak_usage(true);
$root = sys_get_temp_dir() . '/wp-fts-eager-config-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0777, true) && !is_dir($root)) {
    throw new RuntimeException('Could not create the eager-fixture aggregate root.');
}

try {
    $exact = wp_fts_eager_config_write_packs(
        $root . '/exact',
        WP_FTS_LemmaPackLimits::MAX_CONFIGURED_EAGER_FIXTURE_ROWS,
        false,
        WP_FTS_LemmaPackLimits::MAX_CONFIGURED_EAGER_FIXTURE_RUNTIME_BYTES
    );
    $exactHeadersBefore = WP_FTS_LemmaPackLookupIndex::metadata_diagnostics();
    $exactIoBefore = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
    $pipeline = new WP_FTS_LanguagePipeline([
        'lemma_packs_by_lang' => $exact['options'],
    ]);
    $morphologyDigest = hash_init('sha256');
    $activePacks = 0;
    foreach ($exact['languages'] as $pack => $language) {
        $terms = array_column($pipeline->analyze_detailed('s00000', $language), 'term');
        $expected = $exact['probe_lemmas'][$pack];
        if ($terms !== [$expected]) {
            throw new RuntimeException("Eager fixture {$language} did not retain its exact morphology.");
        }
        hash_update($morphologyDigest, $language . "\0" . $expected . "\n");
        $activePacks++;
    }
    $exactHeadersAfter = WP_FTS_LemmaPackLookupIndex::metadata_diagnostics();
    $exactIoAfter = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
    unset($pipeline);
    gc_collect_cycles();
    $statusMethod = new ReflectionMethod(WP_FTS_Plugin::class, 'analyzer_pack_statuses');
    $statusMethod->setAccessible(true);
    $exactStatuses = $statusMethod->invoke(null, [
        'lemmatizer_packs_by_lang' => $exact['options'],
    ], false);
    $exactActiveStatuses = is_array($exactStatuses)
        ? count(array_filter(
            $exactStatuses,
            static fn(array $status): bool => ($status['status'] ?? null) === 'active'
        ))
        : 0;
    unset($exactStatuses);
    gc_collect_cycles();

    $overflow = wp_fts_eager_config_write_packs(
        $root . '/overflow',
        WP_FTS_LemmaPackLimits::MAX_CONFIGURED_EAGER_FIXTURE_ROWS + 1,
        true,
        null
    );
    $overflowHeadersBefore = WP_FTS_LemmaPackLookupIndex::metadata_diagnostics();
    $overflowIoBefore = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
    $overflowError = null;
    try {
        new WP_FTS_LanguagePipeline([
            'lemma_packs_by_lang' => $overflow['options'],
        ]);
    } catch (Throwable $caught) {
        $overflowError = $caught;
    }
    $statusOverflowError = null;
    try {
        $statusMethod->invoke(null, [
            'lemmatizer_packs_by_lang' => $overflow['options'],
        ], false);
    } catch (Throwable $caught) {
        $statusOverflowError = $caught;
    }
    $overflowHeadersAfter = WP_FTS_LemmaPackLookupIndex::metadata_diagnostics();
    $overflowIoAfter = WP_FTS_LemmaPackLookupIndex::io_diagnostics();

    $peakAfter = memory_get_peak_usage(true);
    echo json_encode([
        'case' => 'configured-eager-fixture-rows',
        'declared' => [
            'packs' => count($exact['options']),
            'exact_rows' => $exact['rows'],
            'overflow_rows' => $overflow['rows'],
            'exact_runtime_bytes' => $exact['runtime_bytes'],
            'overflow_runtime_bytes' => $overflow['runtime_bytes'],
        ],
        'exact' => [
            'active_packs' => $activePacks,
            'status_active_packs' => $exactActiveStatuses,
            'morphology_sha256' => hash_final($morphologyDigest),
            'lookup_header_opens' => $exactHeadersAfter['lookup_header_opens']
                - $exactHeadersBefore['lookup_header_opens'],
            'indexed_io' => wp_fts_eager_config_diagnostic_delta($exactIoBefore, $exactIoAfter),
        ],
        'overflow' => [
            'error_class' => $overflowError instanceof Throwable ? get_class($overflowError) : null,
            'reason_code' => $overflowError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded
                ? $overflowError->reason_code
                : null,
            'status_error_class' => $statusOverflowError instanceof Throwable
                ? get_class($statusOverflowError)
                : null,
            'status_reason_code' => $statusOverflowError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded
                ? $statusOverflowError->reason_code
                : null,
            'first_runtime_digest_matches' => $overflow['first_runtime_digest_matches'],
            'lookup_header_opens' => $overflowHeadersAfter['lookup_header_opens']
                - $overflowHeadersBefore['lookup_header_opens'],
            'indexed_io' => wp_fts_eager_config_diagnostic_delta($overflowIoBefore, $overflowIoAfter),
        ],
        'elapsed_seconds' => microtime(true) - $started,
        'php_peak_bytes' => $peakAfter,
        'php_peak_delta_bytes' => max(0, $peakAfter - $peakBefore),
        'proc_status' => wp_fts_eager_config_proc_status(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} finally {
    wp_fts_eager_config_remove_tree($root);
}

/**
 * Build 32 distinct physical fixture packs whose declared rows sum exactly to
 * the requested aggregate.
 *
 * @return array{options:array<string,string>,languages:array<int,string>,probe_lemmas:array<int,string>,rows:int,runtime_bytes:int,first_runtime_digest_matches:bool}
 */
function wp_fts_eager_config_write_packs(
    string $root,
    int $totalRows,
    bool $corruptFirstDigest,
    ?int $targetRuntimeBytes
): array
{
    $packCount = WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_LANGUAGES;
    if ($totalRows < $packCount || !mkdir($root, 0777, true)) {
        throw new RuntimeException('Could not create the eager-fixture pack set.');
    }

    $baseRows = intdiv($totalRows, $packCount);
    $remainder = $totalRows % $packCount;
    $options = [];
    $languages = [];
    $probeLemmas = [];
    $runtimeBytes = 0;
    $firstRuntimeDigestMatches = true;
    // Numeric suffixes are fixed-width throughout this 50,001-row fixture.
    $baseRuntimeBytes = $totalRows * strlen("s00000\tl0000000\n");
    if ($targetRuntimeBytes !== null && $targetRuntimeBytes < $baseRuntimeBytes) {
        throw new RuntimeException('The eager-fixture target cannot hold every declared row.');
    }
    $paddingBytes = $targetRuntimeBytes === null ? 0 : $targetRuntimeBytes - $baseRuntimeBytes;
    $paddingPerRow = intdiv($paddingBytes, $totalRows);
    $paddedRows = $paddingBytes % $totalRows;
    $globalRow = 0;
    for ($pack = 0; $pack < $packCount; $pack++) {
        $rows = $baseRows + ($pack < $remainder ? 1 : 0);
        $packRoot = $root . '/pack-' . str_pad((string) $pack, 2, '0', STR_PAD_LEFT);
        if (!mkdir($packRoot) || file_put_contents($packRoot . '/NOTICE.txt', "Project-owned eager fixture.\n") === false) {
            throw new RuntimeException('Could not create one eager-fixture pack directory.');
        }

        $runtime = $packRoot . '/runtime.tsv';
        $handle = fopen($runtime, 'wb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not create one eager-fixture runtime.');
        }
        $rowsDigest = hash_init('sha256');
        try {
            for ($row = 0; $row < $rows; $row++) {
                $surface = 's' . str_pad((string) $row, 5, '0', STR_PAD_LEFT);
                $lemma = 'l' . str_pad((string) $pack, 2, '0', STR_PAD_LEFT)
                    . str_pad((string) $row, 5, '0', STR_PAD_LEFT)
                    . str_repeat('x', $paddingPerRow + ($globalRow < $paddedRows ? 1 : 0));
                $line = $surface . "\t" . $lemma . "\n";
                if (fwrite($handle, $line) !== strlen($line)) {
                    throw new RuntimeException('Could not write one eager-fixture runtime row.');
                }
                hash_update($rowsDigest, $line);
                if ($row === 0) {
                    $probeLemmas[$pack] = $lemma;
                }
                $globalRow++;
            }
        } finally {
            fclose($handle);
        }

        $actualRuntimeDigest = hash_file('sha256', $runtime);
        if (!is_string($actualRuntimeDigest)) {
            throw new RuntimeException('Could not hash one eager-fixture runtime.');
        }
        $declaredRuntimeDigest = $corruptFirstDigest && $pack === 0
            ? str_repeat($actualRuntimeDigest[0] === 'f' ? 'e' : 'f', 64)
            : $actualRuntimeDigest;
        if ($pack === 0) {
            $firstRuntimeDigestMatches = hash_equals($actualRuntimeDigest, $declaredRuntimeDigest);
        }

        $manifest = [
            'schema_version' => 1,
            'pack_id' => 'qaa-eager-aggregate-' . $pack,
            'language' => 'qaa',
            'version' => '1',
            'fixture_only' => true,
            'default_enabled' => false,
            'capabilities' => ['dictionary-lemmatizer', 'ambiguous-form-noop', 'normalized-runtime-rows'],
            'runtime' => [
                'format' => WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV,
                'normalization' => 'WP_FTS_Normalizer qaa with fold_diacritics=true',
                'ambiguity_policy' => 'ambiguous_surface_noop',
                'total_rows' => $rows,
                'total_sha256' => hash_final($rowsDigest),
                'files' => [[
                    'path' => basename($runtime),
                    'sha256' => $declaredRuntimeDigest,
                    'rows' => $rows,
                    'first_surface' => 's00000',
                    'last_surface' => 's' . str_pad((string) ($rows - 1), 5, '0', STR_PAD_LEFT),
                ]],
            ],
            'source' => [
                'name' => 'Project-owned eager aggregate source',
                'version' => '1',
                'url' => 'urn:wp-fts:test:eager-aggregate:' . $pack,
                'artifact_sha256' => hash('sha256', 'eager-aggregate-' . $pack),
                'byte_count' => (int) filesize($runtime),
            ],
            'license' => ['spdx_id' => 'CC0-1.0', 'notice_path' => 'NOTICE.txt'],
            'attribution' => ['note' => 'Project-owned generated fixture.'],
            'provenance' => [
                'no_runtime_network_access' => true,
                'no_full_third_party_dictionary_dump' => true,
            ],
        ];
        $manifestPath = $packRoot . '/manifest.json';
        if (file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Could not write one eager-fixture manifest.');
        }

        $language = $pack === 0 ? 'qaa' : 'qaa-x-copy' . $pack;
        $languages[$pack] = $language;
        $options[$language] = $manifestPath;
        $runtimeBytes += (int) filesize($runtime);
    }

    return [
        'options' => $options,
        'languages' => $languages,
        'probe_lemmas' => $probeLemmas,
        'rows' => $totalRows,
        'runtime_bytes' => $runtimeBytes,
        'first_runtime_digest_matches' => $firstRuntimeDigestMatches,
    ];
}

/** @return array<string,int> */
function wp_fts_eager_config_diagnostic_delta(array $before, array $after): array
{
    $delta = [];
    foreach ($after as $key => $value) {
        $delta[$key] = (int) $value - (int) ($before[$key] ?? 0);
    }

    return $delta;
}

/** @return array{VmHWM_bytes:?int,VmRSS_bytes:?int} */
function wp_fts_eager_config_proc_status(): array
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

/** Remove one generated eager-fixture directory tree. */
function wp_fts_eager_config_remove_tree(string $path): void
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
            wp_fts_eager_config_remove_tree($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
}
