<?php
declare(strict_types=1);

test_case('64-file lemma packs reject unsafe ranges and route every lookup to at most one shard', function (): void {
    $fixture = dirname(__DIR__) . '/fixtures/lemma-shard-routing-containment.php';
    foreach ([
        'normal PHP' => [PHP_BINARY, '-d', 'memory_limit=128M', $fixture],
        'PHP without extensions' => [PHP_BINARY, '-n', '-d', 'memory_limit=128M', $fixture],
    ] as $label => $command) {
        $result = test_run_subprocess($command, dirname(__DIR__, 2));
        assert_same(0, $result['exit'], "{$label} shard-routing containment should finish under 128 MiB: {$result['stderr']}");
        $payload = json_decode(trim($result['stdout']), true);
        assert_true(is_array($payload), "{$label} shard-routing containment should emit JSON evidence");
        assert_same(64, $payload['runtime_file_limit'] ?? null, "{$label} fixture should bind itself to the production runtime-file limit");
        assert_same(64, $payload['runtime_files'] ?? null, "{$label} fixture should exercise the complete accepted shard count");
        assert_same(64, $payload['validated_runtime_rows'] ?? null, "{$label} full validation should parse every generated shard");

        $invalid = is_array($payload['invalid'] ?? null) ? $payload['invalid'] : [];
        foreach ([
            'missing_ranges' => 'require a complete surface range',
            'overlapping_ranges' => 'strictly ordered and non-overlapping',
            'out_of_order_ranges' => 'strictly ordered and non-overlapping',
            'unnormalized_ranges' => 'is not normalized',
        ] as $name => $message) {
            assert_same('RuntimeException', $invalid[$name]['error_class'] ?? null, "{$label} {$name} manifest should fail structural validation");
            assert_contains($message, (string) ($invalid[$name]['error_message'] ?? ''), "{$label} {$name} rejection should identify the violated range invariant");
            assert_same(true, $invalid[$name]['rejected_before_runtime_path_resolution'] ?? null, "{$label} {$name} should reject before any nonexistent runtime path is resolved");
            assert_true((float) ($invalid[$name]['elapsed_seconds'] ?? INF) <= 1.0, "{$label} {$name} should reject within one second");
        }

        $lookups = is_array($payload['lookups'] ?? null) ? $payload['lookups'] : [];
        $totalFilesOpened = 0;
        $totalDecodedBytes = 0;
        foreach (['s0000' => 'lemma0000', 's0032' => 'lemma0032', 's0063' => 'lemma0063'] as $term => $expected) {
            assert_same($expected, $lookups[$term]['result'] ?? null, "{$label} {$term} should return its attested lemma");
            $stats = is_array($lookups[$term]['stats'] ?? null) ? $lookups[$term]['stats'] : [];
            assert_same(1, $stats['candidate_files'] ?? null, "{$label} {$term} should binary-select exactly one candidate shard");
            assert_same(1, $stats['files_opened'] ?? null, "{$label} {$term} should open exactly one runtime shard");
            assert_same(2, $stats['lines_read'] ?? null, "{$label} {$term} should use one bounded locate comparison and read its one result row");
            assert_true(
                (int) ($stats['bytes_loaded'] ?? 0) > 0
                    && (int) ($stats['bytes_loaded'] ?? PHP_INT_MAX) <= WP_FTS_LemmaPackLookupIndex::MAX_BLOCK_DECODED_BYTES,
                "{$label} {$term} should decode one nonempty block within the 16-KiB limit"
            );
            assert_true(in_array('block-index', $stats['modes'] ?? [], true), "{$label} {$term} should exercise the required indexed path");
            assert_true((float) ($lookups[$term]['elapsed_seconds'] ?? INF) <= 1.0, "{$label} {$term} lookup should finish within one second");
            $totalFilesOpened += (int) ($stats['files_opened'] ?? 0);
            $totalDecodedBytes += (int) ($stats['bytes_loaded'] ?? 0);
        }

        $miss = is_array($lookups['s0032x']['stats'] ?? null) ? $lookups['s0032x']['stats'] : [];
        assert_same('s0032x', $lookups['s0032x']['result'] ?? null, "{$label} gap miss should preserve the original term");
        assert_same(0, $miss['candidate_files'] ?? null, "{$label} gap miss should not select a shard");
        assert_same(0, $miss['files_opened'] ?? null, "{$label} gap miss should not open a runtime file");
        assert_same(0, $miss['bytes_loaded'] ?? null, "{$label} gap miss should decode no runtime bytes");
        assert_same(3, $totalFilesOpened, "{$label} four distinct terms should open only the three shards containing hits");
        assert_true($totalDecodedBytes <= 3 * WP_FTS_LemmaPackLookupIndex::MAX_BLOCK_DECODED_BYTES, "{$label} all three hit terms together should decode no more than three bounded blocks");
        assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 2.0, "{$label} complete invalid and valid 64-shard proof should finish within two seconds");
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, "{$label} shard-routing proof should stay below the PHP memory ceiling");
        assert_true((int) ($payload['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= 32 * 1024 * 1024, "{$label} shard-routing proof should add at most 32 MiB PHP allocation");
        foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
            $value = $payload['proc_status'][$metric] ?? null;
            if (is_int($value)) {
                assert_true($value <= 128 * 1024 * 1024, "{$label} {$metric} should stay below 128 MiB");
            }
        }
    }
});

test_case('every bundled multi-file lemma pack satisfies the binary-routing range contract', function (): void {
    $paths = glob(dirname(__DIR__, 2) . '/resources/analyzer-packs/*/manifest.json');
    assert_true(is_array($paths) && $paths !== [], 'bundled analyzer-pack manifests should be discoverable');
    sort($paths, SORT_STRING);
    $validator = new WP_FTS_AnalyzerPackValidator();
    $multiFilePacks = 0;
    foreach ($paths as $path) {
        $metadata = $validator->validate_metadata($path, false);
        $files = array_values($metadata['runtime_files']);
        if (count($files) <= 1) {
            continue;
        }
        $multiFilePacks++;
        $previousLast = null;
        foreach ($files as $file) {
            $first = $file['first_surface'] ?? null;
            $last = $file['last_surface'] ?? null;
            assert_true(is_string($first) && $first !== '', basename(dirname($path)) . ' should declare every shard first_surface');
            assert_true(is_string($last) && $last !== '', basename(dirname($path)) . ' should declare every shard last_surface');
            assert_true(is_string($first) && is_string($last) && strcmp($first, $last) <= 0, basename(dirname($path)) . ' should declare a valid range for every shard');
            assert_true($previousLast === null || (is_string($first) && strcmp($previousLast, $first) < 0), basename(dirname($path)) . ' shard ranges should be strictly ordered and non-overlapping');
            $previousLast = is_string($last) ? $last : null;
        }
    }
    assert_true($multiFilePacks >= 9, 'the bundled audit should exercise every current multi-file analyzer pack');
});
