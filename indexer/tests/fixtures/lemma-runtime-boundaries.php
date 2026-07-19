<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

try {
    echo json_encode(
        wp_fts_lemma_runtime_boundary_main(),
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}

/** @return array<string,mixed> */
function wp_fts_lemma_runtime_boundary_main(): array
{
    $started = microtime(true);
    $peakBefore = memory_get_peak_usage(true);
    $root = sys_get_temp_dir() . '/wp-fts-lemma-runtime-boundaries-' . bin2hex(random_bytes(6));
    mkdir($root, 0777, true);
    try {
        file_put_contents($root . '/NOTICE.txt', "Project-owned generated boundary fixture.\n");
        $limit = WP_FTS_LemmaPackLimits::MAX_RUNTIME_LOOKUP_DECODED_BYTES;
        $overLine = "zzzzzz\tz\n";

        $wholePlain = $root . '/whole-source.tsv';
        $whole = wp_fts_lemma_runtime_write_exact_rows($wholePlain, 100000, $limit);
        $wholeExactPath = $root . '/whole-exact.tsv.gz';
        $wholeOverPath = $root . '/whole-over.tsv.gz';
        wp_fts_lemma_runtime_gzip_file($wholePlain, $wholeExactPath);
        wp_fts_lemma_runtime_append_copy($wholePlain, $root . '/whole-over.tsv', $overLine);
        wp_fts_lemma_runtime_gzip_file($root . '/whole-over.tsv', $wholeOverPath);
        unlink($wholePlain);
        unlink($root . '/whole-over.tsv');

        $streamExactPath = $root . '/stream-exact.tsv';
        $stream = wp_fts_lemma_runtime_write_exact_rows($streamExactPath, 250000, $limit);
        $streamOverPath = $root . '/stream-over.tsv';
        wp_fts_lemma_runtime_append_copy($streamExactPath, $streamOverPath, $overLine);
        $streamExactGzipPath = $root . '/stream-exact.tsv.gz';
        $streamOverGzipPath = $root . '/stream-over.tsv.gz';
        wp_fts_lemma_runtime_gzip_file($streamExactPath, $streamExactGzipPath);
        wp_fts_lemma_runtime_gzip_file($streamOverPath, $streamOverGzipPath);

        $sidecarExactPath = $root . '/sidecar-exact.tsv.gz';
        $sidecarOverPath = $root . '/sidecar-over.tsv.gz';
        copy($streamExactGzipPath, $sidecarExactPath);
        copy($streamOverGzipPath, $sidecarOverPath);
        $sidecarExact = WP_FTS_LemmaPackLookupIndex::build(
            $sidecarExactPath,
            WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
            (string) hash_file('sha256', $sidecarExactPath),
            $sidecarExactPath . '.lookup'
        );
        $sidecarOver = WP_FTS_LemmaPackLookupIndex::build(
            $sidecarOverPath,
            WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
            (string) hash_file('sha256', $sidecarOverPath),
            $sidecarOverPath . '.lookup'
        );

        $manifests = [
            'plain_exact' => wp_fts_lemma_runtime_manifest($root, 'plain-exact', basename($streamExactPath), $stream['rows'], $stream['first'], $stream['last']),
            'plain_over' => wp_fts_lemma_runtime_manifest($root, 'plain-over', basename($streamOverPath), $stream['rows'] + 1, $stream['first'], 'zzzzzz'),
            'whole_gzip_exact' => wp_fts_lemma_runtime_manifest($root, 'whole-gzip-exact', basename($wholeExactPath), $whole['rows'], $whole['first'], $whole['last'], true),
            'whole_gzip_over' => wp_fts_lemma_runtime_manifest($root, 'whole-gzip-over', basename($wholeOverPath), $whole['rows'] + 1, $whole['first'], 'zzzzzz', true),
            'stream_gzip_exact' => wp_fts_lemma_runtime_manifest($root, 'stream-gzip-exact', basename($streamExactGzipPath), $stream['rows'], $stream['first'], $stream['last'], true),
            'stream_gzip_over' => wp_fts_lemma_runtime_manifest($root, 'stream-gzip-over', basename($streamOverGzipPath), $stream['rows'] + 1, $stream['first'], 'zzzzzz', true),
            'sidecar_exact' => wp_fts_lemma_runtime_manifest($root, 'sidecar-exact', basename($sidecarExactPath), $sidecarExact['rows'], $stream['first'], $stream['last'], true, $sidecarExact),
            'sidecar_over' => wp_fts_lemma_runtime_manifest($root, 'sidecar-over', basename($sidecarOverPath), $sidecarOver['rows'], $stream['first'], 'zzzzzz', true, $sidecarOver),
        ];

        $cases = [];
        foreach ($manifests as $name => $manifestPath) {
            $cases[$name] = wp_fts_lemma_runtime_case($manifestPath);
        }

        return [
            'limit_bytes' => $limit,
            'exact_fixture_bytes' => [
                'whole' => $whole['bytes'],
                'stream' => $stream['bytes'],
                'sidecar' => $stream['bytes'],
            ],
            'over_fixture_bytes' => [
                'whole' => $whole['bytes'] + strlen($overLine),
                'stream' => $stream['bytes'] + strlen($overLine),
                'sidecar' => $stream['bytes'] + strlen($overLine),
            ],
            'rows' => [
                'whole_exact' => $whole['rows'],
                'stream_exact' => $stream['rows'],
                'sidecar_exact' => $sidecarExact['rows'],
                'sidecar_over' => $sidecarOver['rows'],
            ],
            'compressed_bytes' => [
                'whole_exact' => filesize($wholeExactPath),
                'whole_over' => filesize($wholeOverPath),
                'stream_exact' => filesize($streamExactGzipPath),
                'stream_over' => filesize($streamOverGzipPath),
                'sidecar_exact' => filesize($sidecarExactPath),
                'sidecar_over' => filesize($sidecarOverPath),
            ],
            'sidecar_blocks' => [
                'exact' => $sidecarExact['blocks'],
                'over' => $sidecarOver['blocks'],
            ],
            'cases' => $cases,
            'elapsed_seconds' => microtime(true) - $started,
            'php_peak_bytes' => memory_get_peak_usage(true),
            'php_peak_delta_bytes' => max(0, memory_get_peak_usage(true) - $peakBefore),
            'proc_status' => wp_fts_lemma_runtime_boundary_proc_status(),
        ];
    } finally {
        wp_fts_lemma_runtime_boundary_remove_tree($root);
    }
}

/**
 * @return array{bytes:int,rows:int,first:string,last:string}
 */
function wp_fts_lemma_runtime_write_exact_rows(string $path, int $regularRows, int $bytes): array
{
    $target = "target\tlemma\n";
    $regularBytes = $bytes - strlen($target);
    $baseLineBytes = intdiv($regularBytes, $regularRows);
    $extraLines = $regularBytes % $regularRows;
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create exact runtime fixture.');
    }

    $buffer = '';
    try {
        for ($index = 0; $index < $regularRows; $index++) {
            $lineBytes = $baseLineBytes + ($index < $extraLines ? 1 : 0);
            $surface = 'a' . str_pad((string) $index, 7, '0', STR_PAD_LEFT);
            $lemmaBytes = $lineBytes - strlen($surface) - 2;
            if ($lemmaBytes < 1) {
                throw new RuntimeException('Exact runtime row cannot fit its normalized columns.');
            }
            $buffer .= $surface . "\t" . str_repeat('l', $lemmaBytes) . "\n";
            if (strlen($buffer) >= 65536) {
                wp_fts_lemma_runtime_write_all($handle, $buffer);
                $buffer = '';
            }
        }
        $buffer .= $target;
        wp_fts_lemma_runtime_write_all($handle, $buffer);
    } finally {
        fclose($handle);
    }

    $actualBytes = filesize($path);
    if ($actualBytes !== $bytes) {
        throw new RuntimeException("Exact runtime fixture is {$actualBytes} bytes instead of {$bytes}.");
    }

    return [
        'bytes' => $actualBytes,
        'rows' => $regularRows + 1,
        'first' => 'a0000000',
        'last' => 'target',
    ];
}

/** @param resource $handle */
function wp_fts_lemma_runtime_write_all(mixed $handle, string $data): void
{
    $offset = 0;
    $length = strlen($data);
    while ($offset < $length) {
        $written = fwrite($handle, substr($data, $offset));
        if (!is_int($written) || $written < 1) {
            throw new RuntimeException('Could not write complete runtime fixture.');
        }
        $offset += $written;
    }
}

function wp_fts_lemma_runtime_append_copy(string $source, string $destination, string $suffix): void
{
    if (!copy($source, $destination)) {
        throw new RuntimeException('Could not copy over-boundary runtime fixture.');
    }
    $handle = fopen($destination, 'ab');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not open over-boundary runtime fixture.');
    }
    try {
        wp_fts_lemma_runtime_write_all($handle, $suffix);
    } finally {
        fclose($handle);
    }
}

function wp_fts_lemma_runtime_gzip_file(string $source, string $destination): void
{
    $input = fopen($source, 'rb');
    $output = gzopen($destination, 'wb9');
    if (!is_resource($input) || !is_resource($output)) {
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_resource($output)) {
            gzclose($output);
        }
        throw new RuntimeException('Could not open gzip boundary fixture streams.');
    }
    try {
        while (!feof($input)) {
            $chunk = fread($input, 65536);
            if (!is_string($chunk)) {
                throw new RuntimeException('Could not read gzip boundary fixture source.');
            }
            if ($chunk === '') {
                break;
            }
            if (gzwrite($output, $chunk) !== strlen($chunk)) {
                throw new RuntimeException('Could not write complete gzip boundary fixture.');
            }
        }
    } finally {
        fclose($input);
        gzclose($output);
    }
}

/**
 * @param array{format:string,sha256:string,runtime_sha256:string,blocks:int,rows:int,rows_sha256:string}|null $lookup
 */
function wp_fts_lemma_runtime_manifest(
    string $root,
    string $id,
    string $runtimeFile,
    int $rows,
    string $first,
    string $last,
    bool $gzip = false,
    ?array $lookup = null
): string {
    $runtimePath = $root . '/' . $runtimeFile;
    $runtime = [
        'path' => $runtimeFile,
        'sha256' => $lookup['runtime_sha256'] ?? hash_file('sha256', $runtimePath),
        'rows' => $rows,
        'first_surface' => $first,
        'last_surface' => $last,
    ];
    if ($gzip) {
        $runtime['compression'] = WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP;
    }
    if ($lookup !== null) {
        $runtime['lookup'] = [
            'format' => $lookup['format'],
            'path' => $runtimeFile . '.lookup',
            'sha256' => $lookup['sha256'],
            'blocks' => $lookup['blocks'],
        ];
    }

    $manifest = [
        'schema_version' => 1,
        'pack_id' => 'en-runtime-boundary-' . $id,
        'language' => 'en',
        'version' => '1',
        'fixture_only' => false,
        'default_enabled' => false,
        'capabilities' => ['dictionary-lemmatizer'],
        'runtime' => [
            'format' => WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV,
            'normalization' => 'WP_FTS_Normalizer en with fold_diacritics=true',
            'ambiguity_policy' => 'ambiguous_surface_noop',
            'total_rows' => $rows,
            'files' => [$runtime],
        ],
        'source' => [
            'name' => 'Project-owned generated boundary source',
            'version' => '1',
            'url' => 'urn:wp-fts:test:runtime-boundary',
            'artifact_sha256' => str_repeat('a', 64),
            'byte_count' => 1,
        ],
        'license' => ['spdx_id' => 'CC0-1.0', 'notice_path' => 'NOTICE.txt'],
        'attribution' => ['note' => 'Project-owned generated boundary fixture.'],
        'provenance' => [
            'no_runtime_network_access' => true,
            'no_full_third_party_dictionary_dump' => true,
        ],
    ];
    $path = $root . '/manifest-' . $id . '.json';
    file_put_contents($path, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    return $path;
}

/** @return array<string,mixed> */
function wp_fts_lemma_runtime_case(string $manifestPath): array
{
    $pack = WP_FTS_LanguageLemmaPack::from_manifest_file($manifestPath, null, 'en');
    $started = microtime(true);
    $result = null;
    $error = null;
    try {
        $result = $pack->stem('target', 'en');
    } catch (Throwable $caught) {
        $reason = '';
        for ($current = $caught; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof WP_FTS_Analyzer_Config_Limit_Exceeded) {
                $reason = $current->reason_code;
                break;
            }
        }
        $error = [
            'class' => get_class($caught),
            'reason_code' => $reason,
            'message' => $caught->getMessage(),
        ];
    }

    return [
        'result' => $result,
        'error' => $error,
        'elapsed_seconds' => microtime(true) - $started,
        'lookup' => $pack->last_lookup_stats(),
    ];
}

/** @return array{VmHWM_bytes:?int,VmRSS_bytes:?int} */
function wp_fts_lemma_runtime_boundary_proc_status(): array
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

function wp_fts_lemma_runtime_boundary_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($child) ? wp_fts_lemma_runtime_boundary_remove_tree($child) : unlink($child);
    }
    rmdir($path);
}
