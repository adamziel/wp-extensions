<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

/** @return array{VmHWM_bytes:?int,VmRSS_bytes:?int} */
function wp_fts_lemma_runtime_proc_status(): array
{
    if (!is_readable('/proc/self/status')) {
        return ['VmHWM_bytes' => null, 'VmRSS_bytes' => null];
    }

    $values = [];
    $handle = fopen('/proc/self/status', 'rb');
    if (!is_resource($handle)) {
        return ['VmHWM_bytes' => null, 'VmRSS_bytes' => null];
    }
    try {
        while (($line = fgets($handle)) !== false) {
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
            if ($space !== false && ctype_digit(substr($value, 0, $space)) && strtolower(trim(substr($value, $space + 1))) === 'kb') {
                $values[$key] = (int) substr($value, 0, $space) * 1024;
            }
        }
    } finally {
        fclose($handle);
    }

    return [
        'VmHWM_bytes' => $values['VmHWM'] ?? null,
        'VmRSS_bytes' => $values['VmRSS'] ?? null,
    ];
}

/** Remove the fixture directory without retaining a recursive file list. */
function wp_fts_lemma_runtime_remove_tree(string $path): void
{
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($child) ? wp_fts_lemma_runtime_remove_tree($child) : unlink($child);
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/wp-fts-lemma-runtime-cap-' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);
try {
    $runtime = $root . '/runtime.tsv.gz';
    $handle = gzopen($runtime, 'wb9');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create compressed runtime fixture.');
    }
    gzwrite($handle, "target\tlemma\n");
    $commentLine = '#' . str_repeat('x', 99) . "\n";
    $decodedChunk = str_repeat($commentLine, 10000);
    for ($chunk = 0; $chunk < 190; $chunk++) {
        gzwrite($handle, $decodedChunk);
    }
    gzclose($handle);

    $runtimeSha = hash_file('sha256', $runtime);
    if (!is_string($runtimeSha)) {
        throw new RuntimeException('Could not hash compressed runtime fixture.');
    }
    file_put_contents($root . '/NOTICE.txt', "Project-owned generated containment fixture.\n");
    $manifest = [
        'schema_version' => 1,
        'pack_id' => 'en-compressed-runtime-containment',
        'language' => 'en',
        'version' => '1',
        'fixture_only' => false,
        'default_enabled' => false,
        'capabilities' => [
            'dictionary-lemmatizer',
            'ambiguous-form-noop',
            'normalized-runtime-rows',
            'compressed-runtime-files',
        ],
        'runtime' => [
            'format' => WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV,
            'normalization' => 'WP_FTS_Normalizer en with fold_diacritics=true',
            'ambiguity_policy' => 'ambiguous_surface_noop',
            'total_rows' => 1,
            'files' => [[
                'path' => 'runtime.tsv.gz',
                'sha256' => $runtimeSha,
                'rows' => 1,
                'first_surface' => 'target',
                'last_surface' => 'target',
                'compression' => WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
            ]],
        ],
        'source' => [
            'name' => 'Project-owned generated containment source',
            'version' => '1',
            'url' => 'urn:wp-fts:test:compressed-runtime-containment',
            'artifact_sha256' => str_repeat('a', 64),
            'byte_count' => 1,
        ],
        'license' => ['spdx_id' => 'CC0-1.0', 'notice_path' => 'NOTICE.txt'],
        'attribution' => ['note' => 'Project-owned generated containment fixture.'],
        'provenance' => [
            'no_runtime_network_access' => true,
            'no_full_third_party_dictionary_dump' => true,
        ],
    ];
    $manifestPath = $root . '/manifest.json';
    file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
    $pack = WP_FTS_LanguageLemmaPack::from_manifest_file($manifestPath, null, 'en');

    $peakBefore = memory_get_peak_usage(true);
    $started = microtime(true);
    $error = null;
    try {
        $pack->stem('target', 'en');
    } catch (Throwable $caught) {
        $error = [
            'class' => get_class($caught),
            'reason_code' => $caught instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $caught->reason_code : '',
            'message' => $caught->getMessage(),
        ];
    }
    $peakAfter = memory_get_peak_usage(true);

    echo json_encode([
        'decoded_fixture_bytes' => strlen("target\tlemma\n") + 190 * strlen($decodedChunk),
        'compressed_fixture_bytes' => filesize($runtime),
        'error' => $error,
        'elapsed_seconds' => microtime(true) - $started,
        'php_peak_bytes' => $peakAfter,
        'php_peak_delta_bytes' => max(0, $peakAfter - $peakBefore),
        'proc_status' => wp_fts_lemma_runtime_proc_status(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (is_dir($root)) {
        wp_fts_lemma_runtime_remove_tree($root);
    }
}
