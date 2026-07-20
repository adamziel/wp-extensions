<?php
declare(strict_types=1);

test_case('lemma source importers reject oversized plain and gzip lines before materialization', function (): void {
    foreach (['line-plain', 'line-gzip'] as $case) {
        $payload = wp_fts_importer_containment_case($case);
        assert_same(32 * 1024 * 1024, $payload['decoded_line_bytes'] ?? null, "{$case} should exercise one 32-MiB logical line");
        assert_same(65536, $payload['line_byte_limit'] ?? null, "{$case} should bind the shared 64-KiB source-line limit");
        foreach (['generic', 'conllu', 'unimorph', 'polimorf'] as $importer) {
            $error = $payload['errors'][$importer] ?? [];
            assert_same('RuntimeException', $error['class'] ?? null, "{$case} {$importer} should reject the oversized source line");
            assert_contains('at most 64 KiB', (string) ($error['message'] ?? ''), "{$case} {$importer} should identify the bounded source-line contract");
            assert_same(false, $error['manifest_written'] ?? null, "{$case} {$importer} should reject before publishing a manifest");
        }
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 32 * 1024 * 1024, "{$case} should not materialize the 32-MiB line in PHP");
        assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 10.0, "{$case} four-importer rejection proof should finish within ten seconds");
        wp_fts_assert_importer_proc_memory($payload, $case);
    }
});

test_case('lemma source importers flush maximum-width dedupe chunks at eight lexical MiB', function (): void {
    foreach (['chunk-generic', 'chunk-polimorf'] as $case) {
        $payload = wp_fts_importer_containment_case($case);
        assert_same(17000, $payload['source_rows'] ?? null, "{$case} should exercise all maximum-width source rows");
        assert_same(17000, $payload['runtime_rows'] ?? null, "{$case} should preserve every maximum-width runtime row");
        assert_true((int) ($payload['max_runtime_term_bytes'] ?? 0) >= 251, "{$case} should fill the 255-byte namespaced term-key allowance");
        assert_true((int) ($payload['runtime_decoded_bytes'] ?? 0) > 8 * 1024 * 1024, "{$case} should process more than eight decoded runtime MiB");
        assert_same(8388608, $payload['chunk_lexical_byte_limit'] ?? null, "{$case} should bind the eight-MiB chunk ceiling");
        assert_true((int) ($payload['chunk_files'] ?? 0) >= 2, "{$case} should flush by lexical bytes before the 200,000-row setting");
        assert_true(
            (int) ($payload['max_chunk_lexical_bytes'] ?? PHP_INT_MAX) <= 8388608,
            "{$case} should never retain more than eight lexical MiB in one dedupe map"
        );
        assert_same($payload['runtime_files'] ?? null, $payload['activatable_runtime_files'] ?? null, "{$case} should produce an immediately activatable indexed pack");
        assert_true((int) ($payload['runtime_files'] ?? 0) > 1, "{$case} should shard by lookup-block capacity before its 200,000-row setting");
        assert_true((int) ($payload['lookup_blocks'] ?? 0) > 1, "{$case} should exercise multiple byte-bounded lookup blocks");
        assert_true(
            (int) ($payload['max_lookup_blocks_per_file'] ?? PHP_INT_MAX) <= WP_FTS_Analyzer_Config_Limits::MAX_LOOKUP_BLOCKS_PER_FILE,
            "{$case} should keep every generated shard within the 256-block limit"
        );
        assert_true(
            (int) ($payload['max_lookup_block_decoded_bytes'] ?? PHP_INT_MAX) <= WP_FTS_LemmaPackLookupIndex::MAX_BLOCK_DECODED_BYTES,
            "{$case} should keep every lookup block within 16 decoded KiB"
        );
        assert_same(
            (int) ($payload['runtime_encoded_bytes'] ?? 0) + (int) ($payload['lookup_bytes'] ?? 0),
            $payload['runtime_lookup_bytes'] ?? null,
            "{$case} should report exact encoded runtime-plus-lookup bytes"
        );
        assert_true(
            (int) ($payload['runtime_lookup_bytes'] ?? PHP_INT_MAX) <= WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK,
            "{$case} generated physical runtime evidence should remain within 16 MiB"
        );
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, "{$case} should finish under the low-host PHP ceiling");
        assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 15.0, "{$case} maximum-width chunk proof should finish within fifteen seconds");
        wp_fts_assert_importer_proc_memory($payload, $case);
    }
});

test_case('lemma source importers hierarchically merge fifteen thousand one-row chunks', function (): void {
    foreach (['fanin-generic', 'fanin-polimorf'] as $case) {
        $payload = wp_fts_importer_containment_case($case);
        assert_same(15000, $payload['source_rows'] ?? null, "{$case} should exercise fifteen thousand valid source rows");
        assert_same(15000, $payload['runtime_rows'] ?? null, "{$case} should retain every one-row chunk");
        assert_same($payload['expected_runtime_sha256'] ?? null, $payload['runtime_sha256'] ?? null, "{$case} should emit the exact globally sorted row digest");
        assert_same(15000, $payload['initial_chunk_files'] ?? null, "{$case} should prove the hostile chunk-rows=1 setting was honored");
        assert_true((int) ($payload['chunk_merge_outputs'] ?? 0) > 1, "{$case} should exercise hierarchical compaction");
        assert_true((int) ($payload['chunk_merge_passes'] ?? 0) >= 2, "{$case} should exercise more than one merge level");
        assert_same(64, $payload['chunk_merge_fan_in_limit'] ?? null, "{$case} should bind the merge fan-in to 64 inputs");
        assert_true((int) ($payload['max_chunk_merge_inputs'] ?? PHP_INT_MAX) <= 64, "{$case} should never open more than 64 chunk inputs in one merge");
        assert_true((int) ($payload['max_live_chunk_files'] ?? PHP_INT_MAX) <= 192, "{$case} should keep the live temporary hierarchy bounded");
        assert_same('q00000', $payload['first_surface'] ?? null, "{$case} should publish the globally first surface");
        assert_same('q14999', $payload['last_surface'] ?? null, "{$case} should publish the globally last surface");
        assert_same('l00000', $payload['first_lemma'] ?? null, "{$case} should resolve the first row after heap merge");
        assert_same('l07500', $payload['middle_lemma'] ?? null, "{$case} should resolve a middle row after heap merge");
        assert_same('l14999', $payload['last_lemma'] ?? null, "{$case} should resolve the last row after heap merge");
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, "{$case} should finish under the low-host PHP ceiling");
        assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 10.0, "{$case} bounded hierarchical merge should finish within ten seconds");
        wp_fts_assert_importer_proc_memory($payload, $case);
    }
});

test_case('lemma importer short-pair chunks bind row overhead at two hundred thousand', function (): void {
    foreach ([false, true] as $noIni) {
        $label = $noIni ? 'chunk row boundary php-n' : 'chunk row boundary normal';
        $result = wp_fts_importer_containment_process('chunk-row-boundary', $noIni);
        assert_same('', trim($result['stderr']), "{$label} should emit no warning");
        $payload = $result['payload'];
        assert_same(200000, $payload['chunk_row_limit'] ?? null, "{$label} should bind retained chunk rows");
        assert_same(8388608, $payload['chunk_lexical_byte_limit'] ?? null, "{$label} should also retain the lexical-byte bound");
        foreach (['generic', 'polimorf'] as $kind) {
            $case = $payload['results'][$kind] ?? [];
            assert_same(200000, $case['exact_runtime_rows'] ?? null, "{$label} {$kind} should retain the exact short-pair row boundary");
            assert_same(1, $case['exact_chunk_files'] ?? null, "{$label} {$kind} should exercise one full 200,000-row hash table");
            assert_true((int) ($case['exact_max_chunk_lexical_bytes'] ?? PHP_INT_MAX) <= 8388608, "{$label} {$kind} should remain beneath the independent lexical-byte ceiling");
            assert_same('RuntimeException', $case['over_class'] ?? null, "{$label} {$kind} should reject a 200,001 chunk-row option");
            assert_contains('between 1 and 200,000', (string) ($case['over_message'] ?? ''), "{$label} {$kind} should identify the option bound");
            assert_same(false, $case['over_output_exists'] ?? null, "{$label} {$kind} invalid option should create no output");
        }
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 64 * 1024 * 1024, "{$label} should keep the exact short-pair hash table beneath 64 MiB PHP");
        assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 30.0, "{$label} should finish within thirty seconds");
        wp_fts_assert_importer_proc_memory($payload, $label);
    }
});

test_case('lemma importer initial chunk files accept 16384 and reject the next leaf', function (): void {
    foreach ([false, true] as $noIni) {
        $label = $noIni ? 'chunk file boundary php-n' : 'chunk file boundary normal';
        $result = wp_fts_importer_containment_process('chunk-file-boundary', $noIni);
        assert_same('', trim($result['stderr']), "{$label} should emit no warning");
        $payload = $result['payload'];
        assert_same(16384, $payload['initial_file_limit'] ?? null, "{$label} should bind total leaf-file work");
        foreach (['generic', 'polimorf'] as $kind) {
            $case = $payload['results'][$kind] ?? [];
            assert_same(16384, $case['exact_runtime_rows'] ?? null, "{$label} {$kind} should retain the exact leaf boundary");
            assert_same(16384, $case['exact_chunk_files'] ?? null, "{$label} {$kind} should create exactly the allowed leaf count");
            assert_same('RuntimeException', $case['over_class'] ?? null, "{$label} {$kind} should reject leaf 16,385");
            assert_contains('16,384 initial-chunk file limit', (string) ($case['over_message'] ?? ''), "{$label} {$kind} should identify the structural work bound");
            assert_same(false, $case['over_manifest_written'] ?? null, "{$label} {$kind} overflow should publish no manifest");
            assert_same([], $case['over_output_entries'] ?? null, "{$label} {$kind} overflow should remove every temporary and output artifact");
        }
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 32 * 1024 * 1024, "{$label} should keep the total-file proof beneath 32 MiB PHP");
        assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 15.0, "{$label} should finish within fifteen seconds");
        wp_fts_assert_importer_proc_memory($payload, $label);
    }
});

test_case('lemma source importers reject the first pack above sixteen physical MiB', function (): void {
    foreach (['physical-generic', 'physical-polimorf'] as $case) {
        $payload = wp_fts_importer_containment_case($case);
        assert_same(300000, $payload['source_rows'] ?? null, "{$case} should exercise three hundred thousand high-entropy rows");
        assert_true((int) ($payload['source_bytes'] ?? 0) > 32 * 1024 * 1024, "{$case} should exercise more than 32 MiB of source data");
        assert_same(16 * 1024 * 1024, $payload['runtime_lookup_byte_limit'] ?? null, "{$case} should bind the 16-MiB physical pack limit");
        assert_same('WP_FTS_Analyzer_Config_Limit_Exceeded', $payload['error_class'] ?? null, "{$case} should reject with the typed configuration-limit failure");
        assert_same('runtime_lookup_bytes', $payload['error_reason'] ?? null, "{$case} should identify physical runtime-plus-lookup bytes");
        assert_same(false, $payload['manifest_written'] ?? null, "{$case} should reject before publishing a manifest");
        assert_same([], $payload['output_entries'] ?? null, "{$case} should remove every partial runtime and sidecar");
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, "{$case} should reject under the low-host PHP ceiling");
        assert_true((float) ($payload['source_generation_seconds'] ?? -1.0) >= 0.0, "{$case} should report high-entropy fixture generation separately");
        assert_true(
            (float) ($payload['total_elapsed_seconds'] ?? -1.0)
                >= (float) ($payload['source_generation_seconds'] ?? INF) + (float) ($payload['elapsed_seconds'] ?? INF),
            "{$case} total timing should cover separate fixture generation and importer execution"
        );
        assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 15.0, "{$case} physical-cap proof should finish within fifteen seconds");
        wp_fts_assert_importer_proc_memory($payload, $case);
    }
});

test_case('lemma importers refuse output symlink roots and source-output overlap', function (): void {
    foreach ([false, true] as $noIni) {
        $label = $noIni ? 'path-safety php-n' : 'path-safety normal';
        $result = wp_fts_importer_containment_process('path-safety', $noIni);
        assert_same('', trim($result['stderr']), "{$label} should emit no cleanup warning");
        $payload = $result['payload'];
        assert_same($payload['target_digest_before'] ?? null, $payload['target_digest_after'] ?? null, "{$label} external target should remain byte-identical");
        foreach (['generic', 'conllu', 'unimorph', 'polimorf', 'external-builder'] as $kind) {
            $symlink = $payload['root_symlinks'][$kind] ?? [];
            assert_same('RuntimeException', $symlink['class'] ?? null, "{$label} {$kind} should refuse a symlink output root");
            assert_contains('symbolic link', (string) ($symlink['message'] ?? ''), "{$label} {$kind} should identify the unsafe output root");
            assert_same(true, $symlink['link_retained'] ?? null, "{$label} {$kind} should not unlink the caller-owned output link");
            assert_same($payload['target_digest_before'] ?? null, $symlink['target_digest'] ?? null, "{$label} {$kind} should not mutate the symlink target");
            $overlap = $payload['overlaps'][$kind] ?? [];
            assert_same('RuntimeException', $overlap['class'] ?? null, "{$label} {$kind} should refuse source-output overlap");
            assert_contains('must not overlap', (string) ($overlap['message'] ?? ''), "{$label} {$kind} should identify source-output overlap");
            assert_same(true, $overlap['source_retained'] ?? null, "{$label} {$kind} should retain the source artifact");
            assert_same($overlap['source_digest_before'] ?? null, $overlap['source_digest'] ?? null, "{$label} {$kind} should leave the source byte-identical");
        }
        wp_fts_assert_importer_proc_memory($payload, $label);
    }
});

test_case('lemma importer owned temporary cleanup unlinks directory symlinks without following them', function (): void {
    foreach ([false, true] as $noIni) {
        $label = $noIni ? 'temp symlink php-n' : 'temp symlink normal';
        $result = wp_fts_importer_containment_process('temp-symlink-cleanup', $noIni);
        assert_same('', trim($result['stderr']), "{$label} should emit no cleanup warning");
        $payload = $result['payload'];
        assert_same($payload['target_digest_before'] ?? null, $payload['target_digest_after'] ?? null, "{$label} external target should remain byte-identical");
        foreach (['generic', 'conllu', 'unimorph', 'polimorf'] as $kind) {
            $case = $payload['results'][$kind] ?? [];
            assert_same(null, $case['class'] ?? null, "{$label} {$kind} should finish normally");
            assert_same(true, $case['manifest_written'] ?? null, "{$label} {$kind} should publish its pack");
            assert_same(true, $case['caller_sentinel_retained'] ?? null, "{$label} {$kind} should retain the caller-owned temporary parent");
            assert_same([], $case['tmp_entries'] ?? null, "{$label} {$kind} should remove only its owned child tree");
            assert_true((int) ($case['inserted_links'] ?? 0) >= 1, "{$label} {$kind} should exercise a real directory symlink inside the owned tree");
            assert_same('', $case['watcher_stderr'] ?? null, "{$label} {$kind} watcher should emit no warning");
            assert_same($payload['target_digest_before'] ?? null, $case['target_digest'] ?? null, "{$label} {$kind} should not follow the injected symlink");
        }
        assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 15.0, "{$label} should finish within fifteen seconds");
        wp_fts_assert_importer_proc_memory($payload, $label);
    }
});

test_case('lemma importer temp-parent failures publish no partial output', function (): void {
    foreach ([false, true] as $noIni) {
        $label = $noIni ? 'invalid temp parent php-n' : 'invalid temp parent normal';
        $result = wp_fts_importer_containment_process('invalid-temp-parent', $noIni);
        assert_same('', trim($result['stderr']), "{$label} should emit no cleanup warning");
        foreach (['generic', 'conllu', 'unimorph', 'polimorf'] as $kind) {
            $case = $result['payload']['results'][$kind] ?? [];
            assert_same('RuntimeException', $case['class'] ?? null, "{$label} {$kind} should reject a non-directory temporary parent");
            assert_contains('Temporary parent path is a file', (string) ($case['message'] ?? ''), "{$label} {$kind} should identify the invalid temporary parent");
            assert_same(false, $case['output_exists'] ?? null, "{$label} {$kind} should create no output before temporary setup succeeds");
            assert_same(true, $case['tmp_parent_retained'] ?? null, "{$label} {$kind} should retain the caller-owned path");
            assert_same($case['tmp_parent_sha256_before'] ?? null, $case['tmp_parent_sha256'] ?? null, "{$label} {$kind} should leave the caller-owned path byte-identical");
        }
    }
});

test_case('PoliMorf NOTICE metadata retention is bounded before append', function (): void {
    foreach ([false, true] as $noIni) {
        $label = $noIni ? 'PoliMorf notice php-n' : 'PoliMorf notice normal';
        $result = wp_fts_importer_containment_process('polimorf-notice-cap', $noIni);
        assert_same('', trim($result['stderr']), "{$label} should emit no warning");
        $payload = $result['payload'];
        assert_same(64, $payload['line_limit'] ?? null, "{$label} should bind retained metadata to 64 lines");
        assert_same(65536, $payload['byte_limit'] ?? null, "{$label} should bind retained metadata to 64 KiB");
        assert_same(null, $payload['results']['64']['class'] ?? null, "{$label} should accept exactly 64 metadata lines");
        assert_same(64, $payload['results']['64']['metadata_lines'] ?? null, "{$label} should retain the exact line boundary");
        assert_same('RuntimeException', $payload['results']['65']['class'] ?? null, "{$label} should reject line 65 before append");
        assert_same(false, $payload['results']['65']['manifest_written'] ?? null, "{$label} overflow should publish no partial pack");
        assert_same(null, $payload['results']['bytes-65536']['class'] ?? null, "{$label} should accept exactly 64 KiB of NOTICE metadata");
        assert_same(65536, $payload['results']['bytes-65536']['notice_metadata_bytes'] ?? null, "{$label} should account the exact NOTICE byte boundary");
        assert_same('RuntimeException', $payload['results']['bytes-65537']['class'] ?? null, "{$label} should reject the first NOTICE metadata byte above 64 KiB");
        assert_same(false, $payload['results']['bytes-65537']['manifest_written'] ?? null, "{$label} byte overflow should publish no partial pack");
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 32 * 1024 * 1024, "{$label} should stay well below the low-host memory ceiling");
    }
});

test_case('all lemma source importers accept exact envelopes and reject the first unit above them', function (): void {
    $cases = [
        'source-line-boundary' => ['limit_key' => 'line_byte_limit', 'stat' => null, 'limit' => 65536, 'message' => 'at most 64 KiB', 'seconds' => 5.0],
        'source-physical-boundary' => ['limit_key' => 'physical_byte_limit', 'stat' => 'source_physical_bytes', 'limit' => 67108864, 'message' => '64 MiB physical limit', 'seconds' => 10.0],
        'source-decoded-boundary' => ['limit_key' => 'decoded_byte_limit', 'stat' => 'source_decoded_bytes', 'limit' => 536870912, 'message' => '512 MiB decoded-byte limit', 'seconds' => 10.0],
        'source-count-boundary' => ['limit_key' => 'line_count_limit', 'stat' => 'source_lines', 'limit' => 8000000, 'message' => '8,000,000-line limit', 'seconds' => 90.0],
    ];
    foreach ($cases as $caseName => $expectation) {
        foreach ([false, true] as $noIni) {
            $label = $caseName . ($noIni ? ' php-n' : ' normal');
            $result = wp_fts_importer_containment_process($caseName, $noIni);
            assert_same('', trim($result['stderr']), "{$label} should emit no warning");
            $payload = $result['payload'];
            assert_same($expectation['limit'], $payload[$expectation['limit_key']] ?? null, "{$label} should publish the tested hard limit");
            foreach (['generic', 'conllu', 'unimorph', 'polimorf'] as $kind) {
                $exact = $payload['results'][$kind]['exact'] ?? [];
                assert_same(null, $exact['class'] ?? null, "{$label} {$kind} should accept the exact boundary");
                assert_same(true, $exact['manifest_written'] ?? null, "{$label} {$kind} exact boundary should publish an activatable pack");
                assert_same(1, $exact['runtime_rows'] ?? null, "{$label} {$kind} exact boundary should preserve its valid row");
                if (is_string($expectation['stat'])) {
                    assert_same($expectation['limit'], $exact[$expectation['stat']] ?? null, "{$label} {$kind} should account the exact boundary");
                }
                $over = $payload['results'][$kind]['over'] ?? [];
                assert_same('RuntimeException', $over['class'] ?? null, "{$label} {$kind} should reject the first unit above the boundary");
                assert_contains($expectation['message'], (string) ($over['message'] ?? ''), "{$label} {$kind} should identify the crossed envelope");
                assert_same(false, $over['manifest_written'] ?? null, "{$label} {$kind} overflow should publish no manifest");
                assert_same([], $over['output_entries'] ?? null, "{$label} {$kind} overflow should leave no partial output");
            }
            assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 32 * 1024 * 1024, "{$label} should stay below 32 MiB PHP despite hostile input");
            assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= $expectation['seconds'], "{$label} should finish within {$expectation['seconds']} seconds");
            wp_fts_assert_importer_proc_memory($payload, $label);
        }
    }
});

test_case('bounded source hashing rejects growth and same-size replacement after preflight', function (): void {
    foreach ([false, true] as $noIni) {
        $label = $noIni ? 'source hash race php-n' : 'source hash race normal';
        $result = wp_fts_importer_containment_process('source-hash-race', $noIni);
        assert_same('', trim($result['stderr']), "{$label} should emit no warning");
        $payload = $result['payload'];
        assert_same(67108864, $payload['physical_byte_limit'] ?? null, "{$label} should bind hashing to the physical source envelope");
        assert_same(67108865, $payload['grown_bytes'] ?? null, "{$label} should exercise the exact first physical byte above the preflight generation");
        assert_same('RuntimeException', $payload['grow_error_class'] ?? null, "{$label} should reject a grown source before hashing it without bound");
        assert_contains('changed after physical preflight', (string) ($payload['grow_error_message'] ?? ''), "{$label} should identify stale size evidence");
        assert_same(false, $payload['grow_snapshot_retained'] ?? null, "{$label} should remove a partial snapshot after growth rejection");
        assert_same(9, $payload['replacement_bytes'] ?? null, "{$label} should exercise a same-size source replacement");
        assert_same('RuntimeException', $payload['replace_error_class'] ?? null, "{$label} should reject a replaced inode despite equal bytes");
        assert_contains('changed after physical preflight', (string) ($payload['replace_error_message'] ?? ''), "{$label} should identify stale generation evidence");
        assert_same(false, $payload['replace_snapshot_retained'] ?? null, "{$label} should remove a partial snapshot after replacement rejection");
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 32 * 1024 * 1024, "{$label} should not materialize the sparse 64-MiB adversary");
        assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 5.0, "{$label} should reject before an unbounded hash pass");
    }
});

test_case('lemma importers parse private attested snapshots across source swap and restore', function (): void {
    foreach ([false, true] as $noIni) {
        $label = $noIni ? 'source snapshot swap php-n' : 'source snapshot swap normal';
        $result = wp_fts_importer_containment_process('source-snapshot-swap', $noIni);
        assert_same('', trim($result['stderr']), "{$label} should emit no warning");
        $payload = $result['payload'];
        assert_same(5000, $payload['rows'] ?? null, "{$label} should keep the source path swapped during material work");
        foreach (['generic', 'conllu', 'unimorph', 'polimorf'] as $kind) {
            $case = $payload['results'][$kind] ?? [];
            assert_same(null, $case['class'] ?? null, "{$label} {$kind} should finish from its private snapshot");
            assert_same(true, $case['manifest_written'] ?? null, "{$label} {$kind} should publish an activatable pack");
            assert_same(5000, $case['runtime_rows'] ?? null, "{$label} {$kind} should retain every attested source row");
            assert_same(true, $case['swapped'] ?? null, "{$label} {$kind} watcher should replace the caller-visible source");
            assert_same(true, $case['manifest_seen_while_swapped'] ?? null, "{$label} {$kind} source should remain replaced through publication");
            assert_same($case['expected_source_sha256'] ?? null, $case['snapshot_sha256'] ?? null, "{$label} {$kind} snapshot should match the preflighted source");
            assert_same($case['expected_source_sha256'] ?? null, $case['published_source_sha256'] ?? null, "{$label} {$kind} provenance should attest the parsed snapshot");
            assert_same($case['expected_source_sha256'] ?? null, $case['source_sha256'] ?? null, "{$label} {$kind} watcher should restore the original source generation");
            assert_true(($case['attacker_sha256'] ?? null) !== ($case['published_source_sha256'] ?? null), "{$label} {$kind} attacker digest must not be published");
            assert_same('l00000', $case['safe_first_lemma'] ?? null, "{$label} {$kind} should retain a row from the attested snapshot");
            assert_same('x00000', $case['attacker_first_lemma'] ?? null, "{$label} {$kind} should publish no attacker-only row");
            assert_same(true, $case['caller_sentinel_retained'] ?? null, "{$label} {$kind} should retain the caller-owned temporary parent");
            assert_same([], $case['tmp_entries'] ?? null, "{$label} {$kind} should remove every private snapshot");
            assert_same('', $case['watcher_stderr'] ?? null, "{$label} {$kind} watcher should emit no warning");
        }
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 32 * 1024 * 1024, "{$label} should keep source snapshots out of PHP memory");
        assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 30.0, "{$label} should finish within thirty seconds");
        wp_fts_assert_importer_proc_memory($payload, $label);
    }
});

/** @return array<string,mixed> */
function wp_fts_importer_containment_case(string $case): array
{
    $result = wp_fts_importer_containment_process($case, false);
    return $result['payload'];
}

/** @return array{payload:array<string,mixed>,stderr:string} */
function wp_fts_importer_containment_process(string $case, bool $noIni): array
{
    $command = [PHP_BINARY];
    $effectiveNoIni = $noIni || php_ini_loaded_file() === false;
    if ($effectiveNoIni) {
        $command[] = '-n';
    }
    array_push(
        $command,
        '-d',
        'memory_limit=128M',
        dirname(__DIR__) . '/fixtures/lemma-importer-containment.php',
        $case
    );
    $result = test_run_subprocess($command, dirname(__DIR__, 2));
    assert_same(0, $result['exit'], "{$case} importer containment child should finish under 128 MiB: {$result['stderr']}");
    $payload = json_decode(trim($result['stdout']), true);
    assert_true(is_array($payload), "{$case} importer containment child should emit JSON evidence");
    if ($effectiveNoIni) {
        assert_same(false, $payload['php_ini_loaded_file'] ?? null, "{$case} importer containment child should inherit the no-ini runtime");
    } else {
        assert_true(
            is_string($payload['php_ini_loaded_file'] ?? null)
                && trim((string) $payload['php_ini_loaded_file']) !== '',
            "{$case} importer containment child should retain its configured runtime"
        );
    }

    return ['payload' => $payload, 'stderr' => $result['stderr']];
}

/** @param array<string,mixed> $payload */
function wp_fts_assert_importer_proc_memory(array $payload, string $case): void
{
    foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
        $value = $payload['proc_status'][$metric] ?? null;
        if (is_int($value)) {
            assert_true($value <= 128 * 1024 * 1024, "{$case} {$metric} should remain below 128 MiB; observed {$value} bytes");
        }
    }
}
