<?php
declare(strict_types=1);

test_case('lemma runtime lines reject the first byte above the shared 4-KiB bound', function (): void {
    foreach ([null, WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP] as $compression) {
        $path = tempnam(sys_get_temp_dir(), 'wp-fts-runtime-line-');
        if (!is_string($path)) {
            throw new RuntimeException('Could not create runtime-line fixture.');
        }
        $writer = $compression === null ? fopen($path, 'wb') : gzopen($path, 'wb9');
        if (!is_resource($writer)) {
            throw new RuntimeException('Could not open runtime-line fixture.');
        }
        $write = $compression === null ? 'fwrite' : 'gzwrite';
        $close = $compression === null ? 'fclose' : 'gzclose';
        $write($writer, str_repeat('x', WP_FTS_LemmaPackLimits::MAX_RUNTIME_LINE_BYTES) . "\n");
        $close($writer);

        $reader = $compression === null ? fopen($path, 'rb') : gzopen($path, 'rb');
        assert_true(is_resource($reader), 'the exact runtime-line fixture should open');
        assert_same(
            str_repeat('x', WP_FTS_LemmaPackLimits::MAX_RUNTIME_LINE_BYTES) . "\n",
            WP_FTS_LemmaPackLimits::read_runtime_line($reader, $compression),
            'the exact 4-KiB runtime line should remain accepted'
        );
        $close($reader);

        $writer = $compression === null ? fopen($path, 'wb') : gzopen($path, 'wb9');
        $write($writer, str_repeat('x', WP_FTS_LemmaPackLimits::MAX_RUNTIME_LINE_BYTES + 1) . "\n");
        $close($writer);
        $reader = $compression === null ? fopen($path, 'rb') : gzopen($path, 'rb');
        $error = null;
        try {
            WP_FTS_LemmaPackLimits::read_runtime_line($reader, $compression);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        $close($reader);
        unlink($path);

        assert_true($error instanceof WP_FTS_Analyzer_Config_Limit_Exceeded, 'runtime line byte 4,097 should raise a typed configuration limit');
        assert_same('runtime_line_bytes', $error instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $error->reason_code : null, 'plain and gzip runtime lines should share one stable limit reason');
    }
});

test_case('whole-gzip and sidecar paths enforce the exact shared 4-KiB line boundary', function (): void {
    $root = sys_get_temp_dir() . '/wp-fts-runtime-line-paths-' . bin2hex(random_bytes(6));
    mkdir($root, 0777, true);
    try {
        file_put_contents($root . '/NOTICE.txt', "Project-owned runtime-line fixture.\n");
        $manifestFor = static function (string $id, string $data) use ($root): string {
            $runtime = $root . '/' . $id . '.tsv.gz';
            $encoded = gzencode($data, 9, ZLIB_ENCODING_GZIP);
            if (!is_string($encoded)) {
                throw new RuntimeException('Could not encode runtime-line fixture.');
            }
            file_put_contents($runtime, $encoded);
            $manifest = [
                'schema_version' => 1,
                'pack_id' => 'en-runtime-line-' . $id,
                'language' => 'en',
                'version' => '1',
                'fixture_only' => false,
                'default_enabled' => false,
                'capabilities' => ['dictionary-lemmatizer'],
                'runtime' => [
                    'format' => WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV,
                    'ambiguity_policy' => 'ambiguous_surface_noop',
                    'total_rows' => 1,
                    'files' => [[
                        'path' => basename($runtime),
                        'sha256' => hash_file('sha256', $runtime),
                        'rows' => 1,
                        'first_surface' => 'a',
                        'last_surface' => 'a',
                        'compression' => WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
                    ]],
                ],
                'source' => [
                    'name' => 'Project-owned runtime-line source',
                    'version' => '1',
                    'url' => 'urn:wp-fts:test:runtime-line',
                    'artifact_sha256' => str_repeat('a', 64),
                    'byte_count' => 1,
                ],
                'license' => ['spdx_id' => 'CC0-1.0', 'notice_path' => 'NOTICE.txt'],
                'attribution' => ['note' => 'Project-owned runtime-line fixture.'],
                'provenance' => ['no_runtime_network_access' => true],
            ];
            $path = $root . '/manifest-' . $id . '.json';
            file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
            return $path;
        };

        $exactData = "a\t" . str_repeat('l', WP_FTS_LemmaPackLimits::MAX_RUNTIME_LINE_BYTES - 2) . "\n";
        $overData = "a\t" . str_repeat('l', WP_FTS_LemmaPackLimits::MAX_RUNTIME_LINE_BYTES - 1) . "\n";
        $exactPack = WP_FTS_LanguageLemmaPack::from_manifest_file($manifestFor('exact', $exactData), null, 'en');
        assert_same(
            str_repeat('l', WP_FTS_LemmaPackLimits::MAX_RUNTIME_LINE_BYTES - 2),
            $exactPack->stem('a', 'en'),
            'whole-gzip binary lookup should accept an exact 4-KiB decoded row'
        );
        $overPack = WP_FTS_LanguageLemmaPack::from_manifest_file($manifestFor('over', $overData), null, 'en');
        $wholeError = null;
        try {
            $overPack->stem('a', 'en');
        } catch (Throwable $caught) {
            $wholeError = $caught;
        }
        assert_true($wholeError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded, 'whole-gzip byte 4,097 should raise a typed line limit');
        assert_same('runtime_line_bytes', $wholeError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $wholeError->reason_code : null, 'whole-gzip line rejection should preserve the shared reason');

        foreach (['exact' => $exactData, 'over' => $overData] as $id => $data) {
            $runtime = $root . '/sidecar-' . $id . '.tsv.gz';
            file_put_contents($runtime, gzencode($data, 9, ZLIB_ENCODING_GZIP));
            $error = null;
            try {
                WP_FTS_LemmaPackLookupIndex::build(
                    $runtime,
                    WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
                    (string) hash_file('sha256', $runtime),
                    $runtime . '.lookup'
                );
            } catch (Throwable $caught) {
                $error = $caught;
            }
            if ($id === 'exact') {
                assert_same(null, $error, 'sidecar construction should accept an exact 4-KiB decoded row');
                continue;
            }
            assert_true($error instanceof WP_FTS_Analyzer_Config_Limit_Exceeded, 'sidecar construction should reject decoded row byte 4,097');
            assert_same('runtime_line_bytes', $error instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $error->reason_code : null, 'sidecar line rejection should preserve the shared reason');
        }
    } finally {
        $remove = static function (string $path) use (&$remove): void {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $child = $path . DIRECTORY_SEPARATOR . $entry;
                is_dir($child) ? $remove($child) : unlink($child);
            }
            rmdir($path);
        };
        $remove($root);
    }
});

test_case('lemma lookups enforce exact 8-MiB boundaries across plain gzip and sidecar paths', function (): void {
    $result = test_run_subprocess([
        PHP_BINARY,
        '-d',
        'memory_limit=128M',
        dirname(__DIR__) . '/fixtures/lemma-runtime-boundaries.php',
    ], dirname(__DIR__, 2));
    assert_same(0, $result['exit'], 'the exact runtime-boundary process should finish under 128 MiB: ' . $result['stderr']);

    $payload = json_decode(trim($result['stdout']), true);
    assert_true(is_array($payload), 'the exact runtime-boundary process should emit JSON evidence');
    $limit = WP_FTS_LemmaPackLimits::MAX_RUNTIME_LOOKUP_DECODED_BYTES;
    assert_same($limit, $payload['limit_bytes'] ?? null, 'the fixture should bind itself to the production 8-MiB constant');
    foreach (['whole', 'stream', 'sidecar'] as $path) {
        assert_same($limit, $payload['exact_fixture_bytes'][$path] ?? null, "{$path} exact fixture should contain exactly 8 MiB decoded bytes");
        assert_same($limit + 9, $payload['over_fixture_bytes'][$path] ?? null, "{$path} over fixture should cross the bound by one valid row");
    }
    assert_true((int) ($payload['rows']['whole_exact'] ?? PHP_INT_MAX) <= 250000, 'whole-gzip fixture should enter the bounded binary lookup path');
    assert_true((int) ($payload['compressed_bytes']['whole_exact'] ?? PHP_INT_MAX) <= 2 * 1024 * 1024, 'whole-gzip fixture should satisfy the compressed admission bound');
    assert_true((int) ($payload['rows']['stream_exact'] ?? 0) > 250000, 'stream-gzip fixture should force the streaming path');

    $cases = is_array($payload['cases'] ?? null) ? $payload['cases'] : [];
    foreach (['plain_exact' => 'stream-scan', 'stream_gzip_exact' => 'stream-scan', 'whole_gzip_exact' => 'gzip-binary-search', 'sidecar_exact' => 'block-index', 'sidecar_over' => 'block-index'] as $name => $mode) {
        assert_same('lemma', $cases[$name]['result'] ?? null, "{$name} should preserve the target lemma");
        assert_same(null, $cases[$name]['error'] ?? null, "{$name} should remain inside its supported boundary");
        assert_true(in_array($mode, $cases[$name]['lookup']['modes'] ?? [], true), "{$name} should exercise {$mode}");
    }
    foreach (['plain_over', 'stream_gzip_over', 'whole_gzip_over'] as $name) {
        assert_same('WP_FTS_Analyzer_Config_Limit_Exceeded', $cases[$name]['error']['class'] ?? null, "{$name} should fail with the typed configuration limit");
        assert_same('runtime_lookup_decoded_bytes', $cases[$name]['error']['reason_code'] ?? null, "{$name} should identify the decoded lookup byte bound");
    }
    assert_true((int) ($cases['sidecar_over']['lookup']['bytes_loaded'] ?? PHP_INT_MAX) <= 1048576, 'the >8-MiB sidecar should inflate at most one 1-MiB block');
    assert_true((int) ($cases['sidecar_over']['lookup']['lines_read'] ?? PHP_INT_MAX) <= 32, 'the >8-MiB sidecar should retain logarithmic row probes');
    foreach ($cases as $name => $case) {
        assert_true((float) ($case['elapsed_seconds'] ?? INF) <= 2.0, "{$name} should accept or reject within two seconds");
    }
    assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 8.0, 'the complete eight-path boundary proof should finish within eight seconds');
    assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, 'the complete boundary proof should stay below the PHP memory ceiling');
    assert_true((int) ($payload['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= 32 * 1024 * 1024, 'the complete boundary proof should add at most 32 MiB PHP allocation');
    foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
        $value = $payload['proc_status'][$metric] ?? null;
        if (is_int($value)) {
            assert_true($value <= 128 * 1024 * 1024, "runtime boundary {$metric} should stay below 128 MiB");
        }
    }
});

test_case('compressed lemma lookup rejects a 191-MiB expansion in a fresh 128-MiB process', function (): void {
    $result = test_run_subprocess([
        PHP_BINARY,
        '-d',
        'memory_limit=128M',
        dirname(__DIR__) . '/fixtures/lemma-runtime-containment.php',
    ], dirname(__DIR__, 2));
    assert_same(0, $result['exit'], 'the compressed-runtime containment process should finish under 128 MiB: ' . $result['stderr']);

    $payload = json_decode(trim($result['stdout']), true);
    assert_true(is_array($payload), 'the compressed-runtime containment process should emit JSON evidence');
    assert_true((int) ($payload['decoded_fixture_bytes'] ?? 0) > 180 * 1024 * 1024, 'the decoded runtime fixture should be larger than the complete PHP memory limit');
    assert_true((int) ($payload['compressed_fixture_bytes'] ?? PHP_INT_MAX) <= 2 * 1024 * 1024, 'the compressed bomb should cross the old binary-lookup admission path');
    assert_same('WP_FTS_Analyzer_Config_Limit_Exceeded', $payload['error']['class'] ?? null, 'the compressed runtime must raise a typed limit rather than OOM');
    assert_same('runtime_lookup_decoded_bytes', $payload['error']['reason_code'] ?? null, 'the compressed runtime should identify its decoded lookup bound');
    assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 2.0, 'the compressed runtime should reject within two seconds');
    assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, 'the compressed runtime should stay below the PHP memory ceiling');
    assert_true((int) ($payload['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= 16 * 1024 * 1024, 'the compressed lookup should add at most 16 MiB PHP allocation');
    foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
        $value = $payload['proc_status'][$metric] ?? null;
        if (is_int($value)) {
            assert_true($value <= 128 * 1024 * 1024, "compressed runtime {$metric} should stay below 128 MiB");
        }
    }
});
