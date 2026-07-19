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

test_case('pack paths apply tighter term keys before the shared 4-KiB line ceiling', function (): void {
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
                'fixture_only' => true,
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
                'provenance' => [
                    'no_runtime_network_access' => true,
                    'no_full_third_party_dictionary_dump' => true,
                ],
            ];
            $path = $root . '/manifest-' . $id . '.json';
            file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
            return $path;
        };

        $exactData = "a\t" . str_repeat('l', WP_FTS_LemmaPackLimits::MAX_RUNTIME_LINE_BYTES - 2) . "\n";
        $overData = "a\t" . str_repeat('l', WP_FTS_LemmaPackLimits::MAX_RUNTIME_LINE_BYTES - 1) . "\n";
        $exactError = null;
        try {
            WP_FTS_LanguageLemmaPack::from_manifest_file($manifestFor('exact', $exactData), null, 'en');
        } catch (Throwable $caught) {
            $exactError = $caught;
        }
        assert_true($exactError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded, 'eager validation should apply its tighter storage-key limit to an exact 4-KiB row');
        assert_same('runtime_token_bytes', $exactError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $exactError->reason_code : null, 'eager validation should reject the oversized token before pack activation');
        $wholeError = null;
        try {
            $overPack = WP_FTS_LanguageLemmaPack::from_manifest_file($manifestFor('over', $overData), null, 'en');
            $overPack->stem('a', 'en');
        } catch (Throwable $caught) {
            $wholeError = $caught;
        }
        assert_true($wholeError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded, 'eager fixture byte 4,097 should raise a typed line limit before parsing tokens');
        assert_same('runtime_line_bytes', $wholeError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $wholeError->reason_code : null, 'eager fixture over-limit line should preserve the shared reason');

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
                assert_true($error instanceof WP_FTS_Analyzer_Config_Limit_Exceeded, 'sidecar construction should apply its tighter token limit to an exact 4-KiB row');
                assert_same('runtime_token_bytes', $error instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $error->reason_code : null, 'sidecar construction should reject the oversized token before writing an index');
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

test_case('active lemma packs treat an exact 4-KiB lexical run as a guaranteed no-I/O miss', function (): void {
    $manifest = dirname(__DIR__, 2) . '/resources/analyzer-packs/en-unimorph-eng-66e0e9e8e2dc/manifest.json';
    $analyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'default_lang' => 'en',
        'document_lang' => 'en',
        'query_lang' => 'en',
        'lemmatizer_packs_by_lang' => ['en' => $manifest],
    ]);
    $run = str_repeat('x', WP_FTS_Analysis_Limits::MAX_LEXICAL_RUN_BYTES);
    $ioBefore = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
    $digestBefore = $analyzer->lemma_pack_diagnostics('en')['digest'] ?? null;

    $document = $analyzer->analyze_content($run, ['lang' => 'en']);
    $query = $analyzer->analyze_query_occurrences($run, [
        'query_lang' => 'en',
        '_max_query_occurrences' => WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_GROUPS,
    ]);

    assert_same([], $document, 'an exact 4-KiB document run that cannot fit a term identity should remain surface-only');
    assert_same([], $query, 'an exact 4-KiB query run that cannot fit a term identity should remain surface-only');
    assert_same($ioBefore, WP_FTS_LemmaPackLookupIndex::io_diagnostics(), 'an unrepresentable exact 4-KiB run should perform zero sidecar opens or reads');
    assert_same($digestBefore, $analyzer->lemma_pack_diagnostics('en')['digest'] ?? null, 'an unrepresentable exact 4-KiB run should perform zero runtime or sidecar hashes');
});

test_case('lemma digest hashing stops at the physical cap after a stat-to-read growth race', function (): void {
    foreach ([[], ['-n']] as $phpOptions) {
        $result = test_run_subprocess(array_merge([
            PHP_BINARY,
        ], $phpOptions, [
            '-d',
            'memory_limit=128M',
            dirname(__DIR__) . '/fixtures/lemma-bounded-hash-growth.php',
        ]), dirname(__DIR__, 2));
        assert_same(0, $result['exit'], 'the bounded growth child should exit normally: ' . $result['stderr']);
        $payload = json_decode(trim($result['stdout']), true);
        assert_true(is_array($payload), 'the bounded growth child should emit JSON evidence');
        assert_same('WP_FTS_Analyzer_Config_Limit_Exceeded', $payload['error_class'] ?? null, 'post-stat growth should raise the typed physical limit');
        assert_same('runtime_lookup_bytes', $payload['reason_code'] ?? null, 'post-stat growth should retain the physical limit reason');
        assert_same(WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK + 1, $payload['bytes_read'] ?? null, 'hashing should stop on the first byte past 16 MiB');
        assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 2.0, 'bounded growth hashing should finish within two seconds');
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, 'bounded growth hashing should stay below 128 MiB');
    }
});

test_case('lazy lemma block reads stay bound to the attested file generation', function (): void {
    foreach ([
        'normal' => [],
        'php-n' => ['-n'],
    ] as $label => $phpOptions) {
        $result = test_run_subprocess(array_merge([
            PHP_BINARY,
        ], $phpOptions, [
            '-d',
            'memory_limit=128M',
            dirname(__DIR__) . '/fixtures/lemma-attested-block-race.php',
        ]), dirname(__DIR__, 2));
        assert_same(0, $result['exit'], "the {$label} attested-block race proof should exit normally: " . $result['stderr']);
        $payload = json_decode(trim($result['stdout']), true);
        assert_true(is_array($payload), "the {$label} attested-block race proof should emit JSON evidence");
        assert_same(true, $payload['compatibility_method_exists'] ?? null, "the {$label} validator must retain its public validation-only attestation method");
        assert_same(0, $payload['compatibility_stream_delta'] ?? null, "the {$label} compatibility attestation must close every descriptor before returning");
        assert_same('RuntimeException', $payload['compatibility_corruption_error_class'] ?? null, "the {$label} compatibility attestation must still reject corrupt runtime bytes");
        assert_same(true, $payload['atomic_path_contains_mutant'] ?? null, "the {$label} atomic replacement must publish the unmanifested bytes at the original pathname");
        assert_same(['lemmaa'], $payload['atomic_lookup'] ?? null, "the {$label} lookup must read the attested descriptor rather than the replaced pathname");
        assert_same(true, $payload['same_inode_in_place_write'] ?? null, "the {$label} mutation must alter the same inode held by the attested descriptor");
        assert_same('RuntimeException', $payload['in_place_error_class'] ?? null, "the {$label} same-inode mutant block must fail closed");
        assert_contains('block digest mismatch', (string) ($payload['in_place_error_message'] ?? ''), "the {$label} rejection should identify authenticated block failure");
        assert_same(['lemmaa'], $payload['restored_lookup'] ?? null, "the {$label} restored generation must return only the manifested lemma after fresh attestation");
        assert_same($payload['original_bytes'] ?? null, $payload['mutant_bytes'] ?? null, "the {$label} adversary must retain the exact runtime byte length");
        assert_same('WP_FTS_Analyzer_Config_Limit_Exceeded', $payload['oversized_error_class'] ?? null, "the {$label} public attestation API must reject block 257 before iteration");
        assert_same('lookup_blocks', $payload['oversized_reason_code'] ?? null, "the {$label} oversized block layout should retain the typed metadata reason");
        assert_same('InvalidArgumentException', $payload['cardinality_error_class'] ?? null, "the {$label} lookup must require exactly one authenticated digest per declared block");
        assert_same(true, $payload['compatibility_void_result'] ?? null, "the {$label} integrity-only compatibility API must retain its void contract");
        assert_same(8192, $payload['block_cache_bound']['blocks'] ?? null, "the {$label} authenticated-block cache must stop at 8,192 retained digests");
        assert_same(32, $payload['block_cache_bound']['files'] ?? null, "the {$label} authenticated-block cache should evict the oldest 256-block file at its ceiling");
        assert_same(true, $payload['block_cache_bound']['oldest_evicted'] ?? null, "the {$label} authenticated-block cache must evict rather than leak its oldest generation");
        assert_same('RuntimeException', $payload['validation_error_class'] ?? null, "the {$label} full-content validation must reject the mutant row digest");
        assert_same(true, $payload['restored_validation'] ?? null, "the {$label} restored full validation must not reuse bytes from the rejected mutant");
        assert_same(2, $payload['validation_io']['runtime_file_opens'] ?? null, "the {$label} two full validations should each open the runtime once");
        assert_same(2, $payload['validation_io']['runtime_payload_reads'] ?? null, "the {$label} two full validations should each read the declared block once");
        assert_same(0, $payload['validation_io']['decoded_block_cache_hits'] ?? null, "the {$label} unauthenticated validation path must neither consume nor publish decoded cache entries");
        assert_same(4, $payload['digest_attestation']['files_hashed'] ?? null, "the {$label} in-place case should hash its runtime and sidecar once before mutation and once after restoration");
        assert_same(5, $payload['indexed_io']['runtime_file_opens'] ?? null, "the {$label} proof should use one descriptor per lookup or validation pass");
        assert_same(5, $payload['indexed_io']['runtime_payload_reads'] ?? null, "the {$label} proof should perform exactly one bounded block read per lookup or validation attempt");
        assert_same(60, $payload['indexed_io']['decoded_payload_bytes_loaded'] ?? null, "the {$label} failed attested mutant must never enter decoded-block accounting or cache residency");
        assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 2.0, "the {$label} race proof should complete within two seconds");
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, "the {$label} race proof should remain below 128 MiB");
    }
});

test_case('non-eager lemma packs reject unindexed and batch exact 8-MiB production workloads', function (): void {
    $payloads = [];
    foreach (['reject', 'indexed-document', 'indexed-query'] as $case) {
        $result = test_run_subprocess([
            PHP_BINARY,
            '-d',
            'memory_limit=128M',
            dirname(__DIR__) . '/fixtures/lemma-runtime-boundaries.php',
            $case,
        ], dirname(__DIR__, 2));
        assert_same(0, $result['exit'], "the {$case} exact-runtime process should finish under 128 MiB: " . $result['stderr']);
        $payload = json_decode(trim($result['stdout']), true);
        assert_true(is_array($payload), "the {$case} exact-runtime process should emit JSON evidence");
        $payloads[$case] = $payload;
        assert_same($case, $payload['case'] ?? null, "the {$case} child should identify its workload");
        assert_same(WP_FTS_LemmaPackLimits::MAX_RUNTIME_LOOKUP_DECODED_BYTES, $payload['runtime_decoded_bytes'] ?? null, "the {$case} runtime should contain exactly 8 MiB of valid rows");
        assert_same(32768, $payload['runtime_rows'] ?? null, "the {$case} runtime should contain every fixed-width row");
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, "the {$case} child should remain below 128 MiB PHP allocation");
    }

    $rejected = $payloads['reject'];
    assert_same(false, $rejected['sidecar_exists'] ?? null, 'the rejection pack must omit every lookup sidecar');
    assert_same(false, $rejected['construction']['accepted'] ?? null, 'the non-eager unindexed pack must fail at construction');
    assert_contains('requires a validated lookup sidecar', (string) ($rejected['construction']['error_message'] ?? ''), 'the rejection should tell custom-pack operators what is missing');
    assert_same(0, $rejected['construction']['token_lookup_calls'] ?? null, 'construction rejection must precede every token lookup');
    assert_same(['files_hashed' => 0, 'bytes_hashed' => 0], $rejected['construction']['digest_attestation'] ?? null, 'construction rejection must not hash or scan runtime payloads');
    assert_same([
        'runtime_file_opens' => 0,
        'runtime_payload_reads' => 0,
        'compressed_payload_bytes_read' => 0,
        'decoded_payload_bytes_loaded' => 0,
        'decoded_block_cache_hits' => 0,
    ], $rejected['construction']['indexed_io'] ?? null, 'construction rejection must perform no indexed payload I/O');

    $document = $payloads['indexed-document'];
    assert_same(512, $document['sidecar_blocks'] ?? null, 'the two indexed shards should declare all 512 exact 16-KiB blocks');
    assert_same(4096, $document['document']['distinct_terms'] ?? null, 'the document should reach the exact distinct-surface boundary');
    assert_same(32, $document['document']['fields'] ?? null, 'the production proof should cross all 32 index fields');
    assert_same(4096, $document['document']['distinct_morphologies'] ?? null, 'all document morphologies should survive production indexing');
    assert_same(2, $document['document']['indexed_io']['runtime_file_opens'] ?? null, 'the document should open each selected shard once');
    assert_same(64, $document['document']['indexed_io']['runtime_payload_reads'] ?? null, 'round-robin terms should read each of 64 selected blocks once');
    assert_same(64 * WP_FTS_LemmaPackLookupIndex::MAX_BLOCK_DECODED_BYTES, $document['document']['indexed_io']['decoded_payload_bytes_loaded'] ?? null, 'the exact document should decode one MiB, independent of term order');
    assert_same(0, $document['document']['indexed_io']['decoded_block_cache_hits'] ?? null, 'grouped lookup should not depend on the eviction cache');
    assert_same(4, $document['digest_attestation']['files_hashed'] ?? null, 'two runtime shards and sidecars should each be hashed once');
    assert_same(
        (int) ($document['runtime_compressed_bytes'] ?? 0) + (int) ($document['sidecar_bytes'] ?? 0),
        $document['digest_attestation']['bytes_hashed'] ?? null,
        'document attestation should account for exactly one complete hash of each selected file'
    );

    $query = $payloads['indexed-query'];
    assert_same(12, $query['query']['terms'] ?? null, 'the query should reach the exact twelve-group boundary');
    assert_same(2, $query['query']['indexed_io']['runtime_file_opens'] ?? null, 'the query should open each selected shard once');
    assert_same(12, $query['query']['indexed_io']['runtime_payload_reads'] ?? null, 'the query should read its twelve distinct blocks once');
    assert_same(12 * WP_FTS_LemmaPackLookupIndex::MAX_BLOCK_DECODED_BYTES, $query['query']['indexed_io']['decoded_payload_bytes_loaded'] ?? null, 'the query should decode at most 192 KiB');
    assert_same(4, $query['digest_attestation']['files_hashed'] ?? null, 'query shards and sidecars should each be hashed once despite current-second generations');
    assert_same($rejected['document']['source_sha256'] ?? null, $document['document']['source_sha256'] ?? null, 'rejected and indexed cases must use the same maximum document');
    assert_same($rejected['query']['source_sha256'] ?? null, $query['query']['source_sha256'] ?? null, 'rejected and indexed cases must use the same twelve-term query');
});

test_case('maximum decoded work across 64 shards has a fixed 64-MiB document and 192-KiB query ceiling', function (): void {
    $payloads = [];
    foreach (['maximum-document', 'maximum-query'] as $case) {
        $result = test_run_subprocess([
            PHP_BINARY,
            '-d',
            'memory_limit=128M',
            dirname(__DIR__) . '/fixtures/lemma-runtime-boundaries.php',
            $case,
        ], dirname(__DIR__, 2));
        assert_same(0, $result['exit'], "the {$case} structural-maximum process should finish under 128 MiB: " . $result['stderr']);
        $payload = json_decode(trim($result['stdout']), true);
        assert_true(is_array($payload), "the {$case} structural-maximum process should emit JSON evidence");
        $payloads[$case] = $payload;
        assert_same(64, $payload['declared']['runtime_files'] ?? null, 'the maximum fan-out fixture should span all sixty-four accepted runtime shards');
        assert_same(4096, $payload['declared']['lookup_blocks'] ?? null, 'the fixture should declare one block for every accepted distinct surface');
        assert_same(WP_FTS_LemmaPackLookupIndex::MAX_BLOCK_DECODED_BYTES, $payload['declared']['decoded_block_bytes'] ?? null, 'every declared block should bind the exact 16-KiB ceiling');
        assert_same(4096 * WP_FTS_LemmaPackLookupIndex::MAX_BLOCK_DECODED_BYTES, $payload['declared']['decoded_bytes'] ?? null, 'the declared maximum selected block set should be exactly 64 MiB');
        assert_true((int) ($payload['declared']['runtime_lookup_bytes'] ?? PHP_INT_MAX) <= WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK, 'the maximum-work fixture should remain inside the 16-MiB physical pack envelope');
        assert_same(true, $payload['same_second_generation'] ?? null, 'the gate must exercise freshly written current-second generations');
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, "the {$case} process should stay below 128 MiB");
        assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 10.0, "the {$case} proof should complete within ten seconds");
        assert_true((int) ($payload['memory']['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, "the {$case} attributable PHP peak should stay below 128 MiB");
        foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
            $value = $payload['proc_status'][$metric] ?? null;
            if (is_int($value)) {
                assert_true($value <= 128 * 1024 * 1024, "the {$case} Linux {$metric} should stay below 128 MiB");
            }
        }
        $sourceSha256 = $payload['workload']['source_sha256'] ?? null;
        assert_true(
            is_string($sourceSha256)
                && strlen($sourceSha256) === 64
                && strspn($sourceSha256, '0123456789abcdef') === 64,
            "the {$case} workload should bind its exact adversarial input order"
        );
    }

    $document = $payloads['maximum-document'];
    assert_same(4096, $document['workload']['selected_identities'] ?? null, 'maximum production indexing should retain all 4,096 identities');
    assert_same(4096, $document['workload']['selected_blocks'] ?? null, 'the adversarial permutation should select all 4,096 blocks');
    assert_same(64, $document['workload']['indexed_io']['runtime_file_opens'] ?? null, 'maximum indexing should open each of the sixty-four shards exactly once');
    assert_same(4096, $document['workload']['indexed_io']['runtime_payload_reads'] ?? null, 'maximum indexing should read each selected block exactly once');
    assert_same(64 * 1024 * 1024, $document['workload']['indexed_io']['decoded_payload_bytes_loaded'] ?? null, 'maximum indexing should decode exactly the structural 64-MiB ceiling');
    assert_same($document['declared']['runtime_compressed_bytes'] ?? null, $document['workload']['indexed_io']['compressed_payload_bytes_read'] ?? null, 'maximum indexing should read each compressed runtime byte exactly once');
    assert_same(0, $document['workload']['indexed_io']['decoded_block_cache_hits'] ?? null, 'maximum work should not rely on cache capacity or input order');
    assert_same(128, $document['digest_attestation']['files_hashed'] ?? null, 'maximum indexing should hash sixty-four runtimes and sidecars exactly once');
    assert_same($document['declared']['runtime_lookup_bytes'] ?? null, $document['digest_attestation']['bytes_hashed'] ?? null, 'maximum indexing should hash exactly the admitted physical pack bytes');

    $query = $payloads['maximum-query'];
    assert_same(12, $query['workload']['selected_identities'] ?? null, 'maximum query should retain exactly twelve groups');
    assert_same(12, $query['workload']['indexed_io']['runtime_file_opens'] ?? null, 'maximum query should open no more than twelve selected shards');
    assert_same(12, $query['workload']['indexed_io']['runtime_payload_reads'] ?? null, 'maximum query should read no more than twelve blocks');
    assert_same(192 * 1024, $query['workload']['indexed_io']['decoded_payload_bytes_loaded'] ?? null, 'maximum query should decode exactly 192 KiB');
    assert_true((int) ($query['workload']['indexed_io']['compressed_payload_bytes_read'] ?? 0) > 0, 'maximum query should report its exact compressed payload work');
    assert_same(24, $query['digest_attestation']['files_hashed'] ?? null, 'maximum query should hash only its twelve selected runtime/sidecar pairs once');
});

test_case('maximum production document spans the exact 128-file configured aggregate without exceeding 64 MiB decoded', function (): void {
    $result = test_run_subprocess([
        PHP_BINARY,
        '-d',
        'memory_limit=128M',
        dirname(__DIR__) . '/fixtures/lemma-runtime-boundaries.php',
        'configured-maximum-document',
    ], dirname(__DIR__, 2));
    assert_same(0, $result['exit'], 'the configured-maximum production child should finish under 128 MiB: ' . $result['stderr']);
    $payload = json_decode(trim($result['stdout']), true);
    assert_true(is_array($payload), 'the configured-maximum production child should emit JSON evidence');
    assert_same('configured-maximum-document', $payload['case'] ?? null, 'the child should identify the configured aggregate workload');
    assert_same(2, $payload['declared']['packs'] ?? null, 'the configured aggregate should use two independent language packs');
    assert_same(WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_RUNTIME_FILES, $payload['declared']['runtime_files'] ?? null, 'analyzer construction should accept the exact 128-runtime-file aggregate');
    assert_same(4096, $payload['declared']['lookup_blocks'] ?? null, 'the two packs should expose exactly one selected block per maximum document surface');
    assert_same(64 * 1024 * 1024, $payload['declared']['decoded_bytes'] ?? null, 'the two-pack decoded block set should be exactly 64 MiB');
    assert_true((int) ($payload['declared']['runtime_lookup_bytes'] ?? PHP_INT_MAX) <= WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_RUNTIME_LOOKUP_BYTES, 'the exact 128-file aggregate should remain inside the 32-MiB physical configured envelope');
    assert_same(true, $payload['same_second_generation'] ?? null, 'the configured aggregate should exercise current-second runtime and sidecar generations');

    assert_same(32, $payload['workload']['fields'] ?? null, 'the production Indexer path should span all thirty-two HTML fields');
    assert_same(['qaa', 'qab'], $payload['workload']['languages'] ?? null, 'HTML lang scopes should route one half of the document through each pack');
    assert_same(4096, $payload['workload']['selected_identities'] ?? null, 'the maximum multilingual document should retain all 4,096 morphology identities');
    assert_same(4096, $payload['workload']['selected_blocks'] ?? null, 'the maximum multilingual document should select exactly 4,096 bounded blocks');
    assert_same(128, $payload['workload']['selected_files'] ?? null, 'the adversarial language split should select all 128 configured runtime files');
    assert_same(128, $payload['workload']['indexed_io']['runtime_file_opens'] ?? null, 'batched lookup should open every selected runtime file exactly once');
    assert_same(4096, $payload['workload']['indexed_io']['runtime_payload_reads'] ?? null, 'batched lookup should read every selected block exactly once');
    assert_same(64 * 1024 * 1024, $payload['workload']['indexed_io']['decoded_payload_bytes_loaded'] ?? null, 'the complete request should decode exactly the 64-MiB document-work ceiling');
    assert_same($payload['declared']['runtime_compressed_bytes'] ?? null, $payload['workload']['indexed_io']['compressed_payload_bytes_read'] ?? null, 'the complete request should read every selected compressed runtime byte exactly once');
    assert_same(0, $payload['workload']['indexed_io']['decoded_block_cache_hits'] ?? null, 'the 128-file proof should not rely on cache capacity or term order');
    assert_same(256, $payload['digest_attestation']['files_hashed'] ?? null, 'current-second attestation should hash 128 runtimes and 128 sidecars exactly once');
    assert_same($payload['declared']['runtime_lookup_bytes'] ?? null, $payload['digest_attestation']['bytes_hashed'] ?? null, 'attestation should hash exactly the admitted aggregate physical bytes');

    $sourceSha256 = $payload['workload']['source_sha256'] ?? null;
    assert_true(
        is_string($sourceSha256)
            && strlen($sourceSha256) === 64
            && strspn($sourceSha256, '0123456789abcdef') === 64,
        'the configured-maximum proof should bind its exact HTML field and language order'
    );
    assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 10.0, 'the configured-maximum production proof should finish within ten seconds');
    assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, 'the configured-maximum production proof should stay below 128 MiB PHP allocation');
    foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
        $value = $payload['proc_status'][$metric] ?? null;
        if (is_int($value)) {
            assert_true($value <= 128 * 1024 * 1024, "the configured-maximum Linux {$metric} should stay below 128 MiB");
        }
    }
});

test_case('distinct physical pack copies reject file 129 before lookup-header or payload I/O', function (): void {
    $result = test_run_subprocess([
        PHP_BINARY,
        '-d',
        'memory_limit=128M',
        dirname(__DIR__) . '/fixtures/lemma-runtime-boundaries.php',
        'configured-overflow',
    ], dirname(__DIR__, 2));
    assert_same(0, $result['exit'], 'the configured physical-copy overflow child should finish under 128 MiB: ' . $result['stderr']);
    $payload = json_decode(trim($result['stdout']), true);
    assert_true(is_array($payload), 'the configured physical-copy overflow child should emit JSON evidence');
    assert_same(2, $payload['declared']['accepted_physical_packs'] ?? null, 'two distinct sixty-four-file manifests should be the exact configured boundary');
    assert_same(128, $payload['declared']['accepted_runtime_files'] ?? null, 'two physical copies should account for all 128 admitted runtime files');
    assert_same(192, $payload['declared']['overflow_runtime_files'] ?? null, 'a third physical copy should declare 192 runtime files rather than deduping by content signature');
    assert_same(true, $payload['accepted']['pipeline'] ?? null, 'the language pipeline should construct two physical copies at the exact boundary');
    assert_same(2, $payload['accepted']['active_statuses'] ?? null, 'plugin health should validate both exact-boundary physical copies as active');

    $zeroIo = [
        'runtime_file_opens' => 0,
        'runtime_payload_reads' => 0,
        'compressed_payload_bytes_read' => 0,
        'decoded_payload_bytes_loaded' => 0,
        'decoded_block_cache_hits' => 0,
    ];
    foreach (['pipeline_overflow', 'status_overflow'] as $path) {
        assert_same('WP_FTS_Analyzer_Config_Limit_Exceeded', $payload[$path]['error_class'] ?? null, "{$path} should fail with the typed configured limit");
        assert_same('configured_pack_metadata', $payload[$path]['reason_code'] ?? null, "{$path} should retain the stable aggregate reason");
        assert_same(0, $payload[$path]['lookup_header_opens'] ?? null, "{$path} should reject before opening any lookup header, including the malformed third copy");
        assert_same($zeroIo, $payload[$path]['indexed_io'] ?? null, "{$path} should reject before every indexed runtime open or payload read");
    }
    assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 2.0, 'configured physical-copy admission and overflow should finish within two seconds');
    assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, 'configured physical-copy proof should stay below 128 MiB PHP allocation');
    foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
        $value = $payload['proc_status'][$metric] ?? null;
        if (is_int($value)) {
            assert_true($value <= 128 * 1024 * 1024, "configured physical-copy {$metric} should stay below 128 MiB");
        }
    }
});

test_case('configured eager fixtures accept 50,000 aggregate rows and reject row 50,001 before construction', function (): void {
    foreach ([[], ['-n']] as $phpOptions) {
        $result = test_run_subprocess(array_merge([
            PHP_BINARY,
        ], $phpOptions, [
            '-d',
            'memory_limit=128M',
            dirname(__DIR__) . '/fixtures/lemma-eager-config-containment.php',
        ]), dirname(__DIR__, 2));
        assert_same(0, $result['exit'], 'the eager-fixture aggregate child should finish under 128 MiB: ' . $result['stderr']);
        $payload = json_decode(trim($result['stdout']), true);
        assert_true(is_array($payload), 'the eager-fixture aggregate child should emit JSON evidence');
        assert_same('configured-eager-fixture-rows', $payload['case'] ?? null, 'the child should identify its aggregate eager-fixture workload');
        assert_same(WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_LANGUAGES, $payload['declared']['packs'] ?? null, 'the workload should span all thirty-two configurable language entries');
        assert_same(WP_FTS_LemmaPackLimits::MAX_CONFIGURED_EAGER_FIXTURE_ROWS, $payload['declared']['exact_rows'] ?? null, 'the exact workload should declare the complete aggregate eager-row allowance');
        assert_same(WP_FTS_LemmaPackLimits::MAX_CONFIGURED_EAGER_FIXTURE_ROWS + 1, $payload['declared']['overflow_rows'] ?? null, 'the overflow workload should differ by exactly one declared row');
        assert_same(WP_FTS_LemmaPackLimits::MAX_CONFIGURED_EAGER_FIXTURE_RUNTIME_BYTES, $payload['declared']['exact_runtime_bytes'] ?? null, 'the exact 50,000 retained rows should also consume the complete 8 MiB decoded-byte allowance');
        assert_true((int) ($payload['declared']['overflow_runtime_bytes'] ?? PHP_INT_MAX) < WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_RUNTIME_LOOKUP_BYTES, 'the row overflow should remain well below the independent physical-byte ceiling');

        assert_same(WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_LANGUAGES, $payload['exact']['active_packs'] ?? null, 'all exact-boundary fixture maps should construct and return their own morphology');
        assert_same(WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_LANGUAGES, $payload['exact']['status_active_packs'] ?? null, 'the production plugin status path should validate all thirty-two packs without retaining their eager maps together');
        assert_same('0d4adb9fd747adcedd8c551181592e6e96a9666ec2fca55e0468cab7c1d6edda', $payload['exact']['morphology_sha256'] ?? null, 'the exact-boundary proof should bind every long retained lemma in language order');
        assert_same(0, $payload['exact']['lookup_header_opens'] ?? null, 'eager fixture construction should not open lookup sidecar headers');

        assert_same('WP_FTS_Analyzer_Config_Limit_Exceeded', $payload['overflow']['error_class'] ?? null, 'aggregate eager row 50,001 should fail with the typed configuration limit');
        assert_same('configured_eager_fixture_rows', $payload['overflow']['reason_code'] ?? null, 'aggregate eager row overflow should retain its stable reason');
        assert_same('WP_FTS_Analyzer_Config_Limit_Exceeded', $payload['overflow']['status_error_class'] ?? null, 'plugin status should reject aggregate eager row 50,001 with the typed configuration limit');
        assert_same('configured_eager_fixture_rows', $payload['overflow']['status_reason_code'] ?? null, 'plugin status aggregate rejection should retain the stable eager-row reason');
        assert_same(false, $payload['overflow']['first_runtime_digest_matches'] ?? null, 'the overflow fixture should carry an earlier corrupt digest sentinel');
        assert_same(0, $payload['overflow']['lookup_header_opens'] ?? null, 'aggregate row overflow should reject before any lookup header is opened');

        $zeroIo = [
            'runtime_file_opens' => 0,
            'runtime_payload_reads' => 0,
            'compressed_payload_bytes_read' => 0,
            'decoded_payload_bytes_loaded' => 0,
            'decoded_block_cache_hits' => 0,
        ];
        assert_same($zeroIo, $payload['exact']['indexed_io'] ?? null, 'eager exact construction should perform no indexed payload I/O');
        assert_same($zeroIo, $payload['overflow']['indexed_io'] ?? null, 'aggregate row overflow should perform no indexed payload I/O');
        assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 5.0, 'both thirty-two-pack boundaries should finish within five seconds');
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, 'both eager aggregate boundaries should stay below 128 MiB PHP allocation');
        assert_true((int) ($payload['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= 32 * 1024 * 1024, 'the eager aggregate proof should add at most 32 MiB PHP allocation');
        foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
            $value = $payload['proc_status'][$metric] ?? null;
            if (is_int($value)) {
                assert_true($value <= 128 * 1024 * 1024, "eager aggregate {$metric} should stay below 128 MiB");
            }
        }
    }
});

test_case('configured eager fixtures share an exact 8-MiB decoded-byte limit across plain and gzip packs', function (): void {
    foreach ([[], ['-n']] as $phpOptions) {
        $result = test_run_subprocess(array_merge([
            PHP_BINARY,
        ], $phpOptions, [
            '-d',
            'memory_limit=128M',
            dirname(__DIR__) . '/fixtures/lemma-eager-byte-config-containment.php',
        ]), dirname(__DIR__, 2));
        assert_same(0, $result['exit'], 'the eager-fixture byte aggregate child should finish under 128 MiB: ' . $result['stderr']);
        $payload = json_decode(trim($result['stdout']), true);
        assert_true(is_array($payload), 'the eager-fixture byte aggregate child should emit JSON evidence');
        assert_same('configured-eager-fixture-bytes', $payload['case'] ?? null, 'the child should identify its aggregate eager-fixture byte workload');
        assert_same(WP_FTS_LemmaPackLimits::MAX_CONFIGURED_EAGER_FIXTURE_RUNTIME_BYTES, $payload['limit_bytes'] ?? null, 'the exercised aggregate limit should remain exactly 8 MiB');
        assert_same(2, $payload['physical_packs'] ?? null, 'each byte case should span two distinct physical manifests');
        assert_same(3, $payload['configured_languages'] ?? null, 'a duplicate language alias should prove physical manifests are charged once');

        $zeroIo = [
            'runtime_file_opens' => 0,
            'runtime_payload_reads' => 0,
            'compressed_payload_bytes_read' => 0,
            'decoded_payload_bytes_loaded' => 0,
            'decoded_block_cache_hits' => 0,
        ];
        foreach (['plain', 'gzip'] as $mode) {
            $case = $payload['modes'][$mode] ?? null;
            assert_true(is_array($case), "the {$mode} eager-byte case should emit evidence");
            assert_same(WP_FTS_LemmaPackLimits::MAX_CONFIGURED_EAGER_FIXTURE_RUNTIME_BYTES, $case['exact_decoded_bytes'] ?? null, "the {$mode} exact pair should contain exactly 8 MiB decoded");
            assert_same(WP_FTS_LemmaPackLimits::MAX_CONFIGURED_EAGER_FIXTURE_RUNTIME_BYTES + 1, $case['overflow_decoded_bytes'] ?? null, "the {$mode} overflow pair should differ by one decoded byte");

            $exact = $case['exact'] ?? [];
            assert_same(null, $exact['pipeline_error_class'] ?? null, "the {$mode} exact pair should construct through WP_FTS_LanguagePipeline");
            assert_same(null, $exact['status_error_class'] ?? null, "the {$mode} exact pair should pass the public runtime analyzer status path");
            assert_same(3, $exact['active_statuses'] ?? null, "the {$mode} public runtime status path should report all configured aliases active");
            assert_same([
                'qaa' => ['lemmafirst'],
                'qaa-x-alias' => ['lemmafirst'],
                'qaa-x-second' => ['lemmasecond'],
            ], $exact['morphologies'] ?? null, "the {$mode} pipeline should retain both pack-specific morphologies and the shared alias");
            assert_same(0, $exact['lookup_header_opens'] ?? null, "the {$mode} exact eager path should not open a lookup sidecar header");
            assert_same($zeroIo, $exact['indexed_io'] ?? null, "the {$mode} exact eager path should perform no indexed runtime I/O");

            $overflow = $case['overflow'] ?? [];
            assert_same('WP_FTS_Analyzer_Config_Limit_Exceeded', $overflow['pipeline_error_class'] ?? null, "the {$mode} byte 8,388,609 should fail pipeline construction with the typed configuration limit");
            assert_same('configured_eager_fixture_bytes', $overflow['pipeline_reason_code'] ?? null, "the {$mode} pipeline overflow should retain the stable aggregate byte reason");
            assert_same('WP_FTS_Analyzer_Config_Limit_Exceeded', $overflow['status_error_class'] ?? null, "the {$mode} public runtime status path should fail byte 8,388,609 with the typed configuration limit");
            assert_same('configured_eager_fixture_bytes', $overflow['status_reason_code'] ?? null, "the {$mode} status overflow should retain the stable aggregate byte reason");
            assert_same(0, $overflow['active_statuses'] ?? null, "the {$mode} overflow should publish no partial active status set");
            assert_same([], $overflow['morphologies'] ?? null, "the {$mode} overflow should publish no partial morphology result");
            assert_same(0, $overflow['lookup_header_opens'] ?? null, "the {$mode} overflow should not open a lookup sidecar header");
            assert_same($zeroIo, $overflow['indexed_io'] ?? null, "the {$mode} overflow should perform no indexed runtime I/O");
        }

        assert_same(WP_FTS_LemmaPackLimits::MAX_CONFIGURED_EAGER_FIXTURE_RUNTIME_BYTES, $payload['modes']['plain']['exact_physical_bytes'] ?? null, 'plain physical bytes should equal decoded bytes at the exact boundary');
        assert_same(WP_FTS_LemmaPackLimits::MAX_CONFIGURED_EAGER_FIXTURE_RUNTIME_BYTES + 1, $payload['modes']['plain']['overflow_physical_bytes'] ?? null, 'plain physical bytes should expose the exact +1 preflight boundary');
        assert_same(false, $payload['modes']['plain']['overflow_first_runtime_digest_matches'] ?? null, 'plain +1 preflight should win before validating an earlier corrupt runtime digest');
        assert_same(true, $payload['modes']['gzip']['overflow_first_runtime_digest_matches'] ?? null, 'gzip +1 should use valid runtimes so its typed failure proves bounded decoded-byte accounting');
        assert_true((int) ($payload['modes']['gzip']['exact_physical_bytes'] ?? 0) > 0, 'gzip exact fixtures should have a non-empty physical representation');
        assert_true((int) ($payload['modes']['gzip']['exact_physical_bytes'] ?? PHP_INT_MAX) < 64 * 1024, 'gzip decoded accounting should not be confused with its small physical representation');

        $lateCorrupt = $payload['late_corrupt_aliases'] ?? [];
        assert_same(WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_LANGUAGES, $lateCorrupt['configured_aliases'] ?? null, 'the late-corrupt adversary should configure the maximum 32 physical aliases');
        assert_same(20000, $lateCorrupt['runtime_rows'] ?? null, 'the late-corrupt adversary should parse and normalize 20,000 retained rows before failing');
        assert_same(WP_FTS_LemmaPackLimits::MAX_CONFIGURED_EAGER_FIXTURE_RUNTIME_BYTES, $lateCorrupt['runtime_bytes'] ?? null, 'the late-corrupt adversary should scan the complete 8-MiB eager allowance');
        assert_same(false, $lateCorrupt['single_pipeline']['pack_active'] ?? null, 'one late-corrupt pipeline assignment should fall back');
        assert_same(false, $lateCorrupt['alias_pipeline']['pack_active'] ?? null, 'all 32 late-corrupt pipeline aliases should share the same fallback');
        assert_same(1, $lateCorrupt['single_status']['corrupt'] ?? null, 'one late-corrupt status should report one corrupt pack');
        assert_same(32, $lateCorrupt['alias_status']['statuses'] ?? null, 'the public status path should preserve all 32 alias rows');
        assert_same(0, $lateCorrupt['alias_status']['active'] ?? null, 'no late-corrupt alias should become active');
        assert_same(32, $lateCorrupt['alias_status']['corrupt'] ?? null, 'every late-corrupt alias should reuse the one physical failure');
        assert_true((float) ($lateCorrupt['pipeline_time_ratio'] ?? INF) <= 2.5, '32 pipeline aliases should take at most 2.5x one complete late-corrupt scan, not repeat it 32 times');
        assert_true((float) ($lateCorrupt['status_time_ratio'] ?? INF) <= 2.5, '32 public status aliases should take at most 2.5x one complete late-corrupt scan, not repeat it 32 times');
        assert_true((float) ($lateCorrupt['alias_pipeline']['elapsed_seconds'] ?? INF) <= 2.0, 'the maximum pipeline alias set should finish its one physical 8-MiB attempt within two seconds');
        assert_true((float) ($lateCorrupt['alias_status']['elapsed_seconds'] ?? INF) <= 2.0, 'the maximum status alias set should finish its one physical 8-MiB attempt within two seconds');

        $authoritative = $payload['authoritative_preflight'] ?? [];
        assert_same(32, $authoritative['configured_aliases'] ?? null, 'the repaired-manifest TOCTOU should span 32 physical aliases');
        assert_same(1, $authoritative['pipeline_repair_warnings'] ?? null, 'pipeline preflight should physically attempt the initially broken manifest once');
        assert_same(false, $authoritative['pipeline_pack_active'] ?? null, 'pipeline aliases must retain the first failed preflight after the manifest is repaired');
        assert_same(1, $authoritative['status_repair_warnings'] ?? null, 'public status preflight should physically attempt the initially broken manifest once');
        assert_same(0, $authoritative['status_active'] ?? null, 'public status must not activate the repaired but unadmitted generation');
        assert_same(32, $authoritative['status_corrupt'] ?? null, 'public status aliases should preserve the captured first-pass corruption reason');

        $appearance = $payload['authoritative_appearance'] ?? [];
        assert_same(2, $appearance['configured_languages'] ?? null, 'the appearing-manifest TOCTOU should include one missing target and one repair trigger');
        assert_same(1, $appearance['pipeline_repair_warnings'] ?? null, 'pipeline appearance synchronization should use exactly one failed physical preflight');
        assert_same(false, $appearance['pipeline_pack_active'] ?? null, 'pipeline construction must not retry a manifest that appeared after its failed realpath preflight');
        assert_same(1, $appearance['status_repair_warnings'] ?? null, 'status appearance synchronization should use exactly one failed physical preflight');
        assert_same(0, $appearance['status_active'] ?? null, 'public status must not activate a manifest that appeared after preflight');
        assert_same(1, $appearance['status_corrupt'] ?? null, 'the trigger should retain its first-pass corruption status');
        assert_same(1, $appearance['status_not_active'] ?? null, 'the appearing target should retain its first-pass missing status');

        $canonical = $payload['canonical_last_wins'] ?? [];
        assert_same('qaa-US', $canonical['canonical_language'] ?? null, 'raw underscore and hyphen spellings should resolve to one canonical language');
        assert_same(50000, $canonical['discarded_declared_rows'] ?? null, 'the discarded canonical alias should be large enough to cause a false 50,001-row aggregate rejection');
        assert_same(1, $canonical['surviving_declared_rows'] ?? null, 'the surviving canonical assignment should consume only one eager row');
        assert_same(true, $canonical['pack_active'] ?? null, 'the surviving canonical assignment should be admitted');
        assert_same(['lemmasurvivor'], $canonical['morphology'] ?? null, 'canonical last-wins collapse should construct only the surviving physical manifest');
        assert_same(64, $payload['canonical_cross_map']['raw_entries'] ?? null, 'two maximum raw alias maps should exercise all 64 bounded entries');
        assert_same(32, $payload['canonical_cross_map']['effective_languages'] ?? null, 'underscore and hyphen aliases should collapse to 32 effective languages during merge');
        assert_same(true, $payload['canonical_cross_map']['pack_active'] ?? null, 'the higher-precedence canonical alias map should remain active');
        assert_same(['lemmasurvivor'], $payload['canonical_cross_map']['morphology'] ?? null, 'the discarded lower-precedence alias map should consume no admission or construction work');
        assert_same(true, $payload['canonical_polish_precedence']['pack_active'] ?? null, 'a canonical generic Polish assignment should suppress legacy fallback');
        assert_same(['lemmarepair'], $payload['canonical_polish_precedence']['morphology'] ?? null, 'case-equivalent explicit Polish configuration should retain precedence over the legacy pack');

        assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 5.0, 'all eager boundary and maximum-alias adversaries should finish within five seconds');
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, 'the eager byte aggregate proof should stay below 128 MiB PHP allocation');
        assert_true((int) ($payload['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= 32 * 1024 * 1024, 'the eager byte aggregate proof should add at most 32 MiB PHP allocation');
        foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
            $value = $payload['proc_status'][$metric] ?? null;
            if (is_int($value)) {
                assert_true($value <= 128 * 1024 * 1024, "eager byte aggregate {$metric} should stay below 128 MiB");
            }
        }
    }
});

test_case('configured lemma-pack admission has one component source of truth', function (): void {
    $component = dirname(__DIR__, 3) . '/components/full-text-search/src';
    $admission = (string) file_get_contents($component . '/ConfiguredLemmaPackAdmission.php');
    $pipeline = (string) file_get_contents($component . '/LanguagePipeline.php');
    $pack = (string) file_get_contents($component . '/LanguageLemmaPack.php');
    $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');
    $bootstrap = (string) file_get_contents($component . '/bootstrap.php');

    assert_contains("__DIR__ . '/ConfiguredLemmaPackAdmission.php'", $bootstrap, 'the component bootstrap should load configured-pack admission');
    assert_true(
        strpos($bootstrap, "__DIR__ . '/ConfiguredLemmaPackAdmission.php'")
            < strpos($bootstrap, "__DIR__ . '/LanguageLemmaPack.php'"),
        'configured-pack admission should load before the lemma-pack constructor that type-hints it'
    );
    assert_contains('new WP_FTS_ConfiguredLemmaPackAdmission()', $pipeline, 'the language pipeline should delegate aggregate admission to the component');
    assert_contains('new WP_FTS_ConfiguredLemmaPackAdmission()', $plugin, 'the production status path should delegate aggregate admission to the component');
    assert_contains('?WP_FTS_ConfiguredLemmaPackAdmission $admission = null', $pack, 'lemma-pack construction should accept one lifecycle admission object rather than parallel limit knobs');
    assert_contains('reserve_eager_pack(', $pack, 'lemma-pack construction should reserve decoded work through configured admission');
    assert_contains('consume_eager_pack(', $pack, 'lemma-pack construction should report retained eager residency through configured admission');
    assert_contains('$this->resourceEnvelopeFailures[$realManifestPath] = $error;', $admission, 'configured admission should cache a physical preflight failure');
    assert_contains('$packs[$canonicalLanguage] = $option;', $pipeline, 'pipeline options should merge directly into one canonical map before admission');
    assert_true(
        strpos($pipeline, '$packs[$canonicalLanguage] = $option;')
            < strpos($pipeline, 'new WP_FTS_ConfiguredLemmaPackAdmission()'),
        'canonical last-wins merge should precede every configured-pack preflight'
    );
    assert_contains('array_key_exists($manifestIdentity, $packsByManifest)', $pipeline, 'pipeline construction should cache positive and negative physical outcomes');
    assert_contains('$packsByManifest[$manifestIdentity] = $pack;', $pipeline, 'pipeline construction should retain a null physical failure sentinel');
    assert_contains("'state' => 'failed'", $plugin, 'public status should capture failed first-pass preflights');
    assert_contains('throw $preflight[\'error\'];', $plugin, 'public status should report rather than retry a failed preflight');
    assert_contains('$lemmaPackAttemptsByManifest[$manifestIdentity] = $error;', $plugin, 'public status should cache a failed physical construction attempt');

    $delegates = $pipeline . $pack . $plugin;
    foreach ([
        'configured_pack_metadata',
        'configured_eager_fixture_rows',
        'configured_eager_fixture_bytes',
        'Configured lemma packs exceed the 128-file, 16,384-block, or 32 MiB runtime envelope.',
        'Configured eager fixture packs exceed the aggregate 50,000-row limit.',
        'Configured eager fixture packs exceed the aggregate 8 MiB decoded byte limit.',
    ] as $ownedDiagnostic) {
        assert_same(0, substr_count($delegates, $ownedDiagnostic), "{$ownedDiagnostic} should not be duplicated outside configured admission");
        assert_same(1, substr_count($admission, $ownedDiagnostic), "{$ownedDiagnostic} should have exactly one configured-admission source");
    }
    foreach (['remainingEagerFixtureRows', 'remainingEagerFixtureBytes', 'countedManifestResources'] as $removedCounter) {
        assert_same(0, substr_count($delegates, $removedCounter), "{$removedCounter} should not reintroduce caller-owned aggregate accounting");
    }
});

test_case('non-eager compressed packs reject before a 191-MiB expansion in a fresh process', function (): void {
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
    assert_true((int) ($payload['compressed_fixture_bytes'] ?? PHP_INT_MAX) <= 2 * 1024 * 1024, 'the highly compressed runtime should remain a credible expansion adversary');
    assert_same('RuntimeException', $payload['error']['class'] ?? null, 'the compressed runtime must reject structurally rather than inflate toward OOM');
    assert_contains('requires a validated lookup sidecar', (string) ($payload['error']['message'] ?? ''), 'the compressed runtime should identify its missing sidecar');
    assert_same(['files_hashed' => 0, 'bytes_hashed' => 0], $payload['digest_attestation'] ?? null, 'compressed construction should not hash the runtime before rejecting it');
    assert_same(0, $payload['indexed_io']['runtime_payload_reads'] ?? null, 'compressed construction should not read any runtime payload block');
    assert_same(0, $payload['indexed_io']['decoded_payload_bytes_loaded'] ?? null, 'compressed construction should not inflate any runtime payload bytes');
    assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 1.0, 'the compressed runtime should reject within one second');
    assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, 'the compressed runtime should stay below the PHP memory ceiling');
    assert_true((int) ($payload['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= 16 * 1024 * 1024, 'the compressed lookup should add at most 16 MiB PHP allocation');
    foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
        $value = $payload['proc_status'][$metric] ?? null;
        if (is_int($value)) {
            assert_true($value <= 128 * 1024 * 1024, "compressed runtime {$metric} should stay below 128 MiB");
        }
    }
});
