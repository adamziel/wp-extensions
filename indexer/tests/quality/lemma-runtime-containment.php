<?php
declare(strict_types=1);

test_case('lemma runtime lines reject the first byte above the shared 4-KiB bound', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'wp-fts-runtime-line-');
    if (!is_string($path)) {
        throw new RuntimeException('Could not create runtime-line fixture.');
    }
    $writer = gzopen($path, 'wb9');
    if (!is_resource($writer)) {
        throw new RuntimeException('Could not open runtime-line fixture.');
    }
    gzwrite($writer, str_repeat('x', WP_FTS_LemmaPackLimits::MAX_RUNTIME_LINE_BYTES) . "\n");
    gzclose($writer);

    $reader = gzopen($path, 'rb');
    assert_true(is_resource($reader), 'the exact runtime-line fixture should open');
    assert_same(
        str_repeat('x', WP_FTS_LemmaPackLimits::MAX_RUNTIME_LINE_BYTES) . "\n",
        WP_FTS_LemmaPackLimits::read_runtime_line($reader),
        'the exact 4-KiB runtime line should remain accepted'
    );
    gzclose($reader);

    $writer = gzopen($path, 'wb9');
    gzwrite($writer, str_repeat('x', WP_FTS_LemmaPackLimits::MAX_RUNTIME_LINE_BYTES + 1) . "\n");
    gzclose($writer);
    $reader = gzopen($path, 'rb');
    $error = null;
    try {
        WP_FTS_LemmaPackLimits::read_runtime_line($reader);
    } catch (Throwable $caught) {
        $error = $caught;
    }
    gzclose($reader);
    unlink($path);

    assert_true($error instanceof WP_FTS_Analyzer_Config_Limit_Exceeded, 'runtime line byte 4,097 should raise a typed configuration limit');
    assert_same('runtime_line_bytes', $error instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $error->reason_code : null, 'gzip runtime lines should retain the stable limit reason');
});

test_case('active lemma packs treat an exact 4-KiB lexical run as a guaranteed no-I/O miss', function (): void {
    $manifest = dirname(__DIR__, 2) . '/resources/analyzer-packs/en-unimorph-eng-66e0e9e8e2dc/manifest.json';
    $analyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'default_lang' => 'en',
        'document_lang' => 'en',
        'query_lang' => 'en',
        'lemma_packs_by_lang' => ['en' => $manifest],
    ]);
    $run = str_repeat('x', WP_FTS_Analysis_Limits::MAX_LEXICAL_RUN_BYTES);
    $ioBefore = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
    $digestBefore = $analyzer->lemma_pack_diagnostics('en')['digest'] ?? null;

    $document = $analyzer->analyze_content($run, ['document_lang' => 'en']);
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

test_case('indexed lemma packs batch exact 8-MiB production workloads', function (): void {
    $payloads = [];
    foreach (['indexed-document', 'indexed-query'] as $case) {
        $result = test_run_subprocess([
            PHP_BINARY,
            '-d',
            'memory_limit=128M',
            dirname(__DIR__) . '/fixtures/lemma-runtime-boundaries.php',
            $case,
        ], dirname(__DIR__, 2));
        assert_same(0, $result['exit'], "the {$case} exact-runtime process should finish under 128 MiB: " . $result['stderr']);
        $payload = json_decode(trim($result['stdout']), true);
        assert_true(is_array($payload), "the {$case} exact-runtime process should emit JSON output");
        $payloads[$case] = $payload;
        assert_same($case, $payload['case'] ?? null, "the {$case} child should identify its workload");
        assert_same(8 * 1024 * 1024, $payload['runtime_decoded_bytes'] ?? null, "the {$case} runtime should contain exactly 8 MiB of valid rows");
        assert_same(32768, $payload['runtime_rows'] ?? null, "the {$case} runtime should contain every fixed-width row");
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, "the {$case} child should remain below 128 MiB PHP allocation");
    }

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
    assert_contains('->resource_envelope($realManifestPath)', $admission, 'configured admission should read the indexed runtime envelope once per physical manifest');
    assert_contains('$this->admit_resource_envelope($realManifestPath, $envelope);', $admission, 'configured admission should charge each language-compatible indexed runtime once');
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
        'Configured lemma packs exceed the 128-file, 16,384-block, or 32 MiB runtime envelope.',
    ] as $ownedDiagnostic) {
        assert_same(0, substr_count($delegates, $ownedDiagnostic), "{$ownedDiagnostic} should not be duplicated outside configured admission");
        assert_same(1, substr_count($admission, $ownedDiagnostic), "{$ownedDiagnostic} should have exactly one configured-admission source");
    }
});

test_case('compressed packs without lookup sidecars reject before a 191-MiB expansion', function (): void {
    $result = test_run_subprocess([
        PHP_BINARY,
        '-d',
        'memory_limit=128M',
        dirname(__DIR__) . '/fixtures/lemma-runtime-containment.php',
    ], dirname(__DIR__, 2));
    assert_same(0, $result['exit'], 'the compressed-runtime containment process should finish under 128 MiB: ' . $result['stderr']);

    $payload = json_decode(trim($result['stdout']), true);
    assert_true(is_array($payload), 'the compressed-runtime containment process should emit JSON output');
    assert_true((int) ($payload['decoded_fixture_bytes'] ?? 0) > 180 * 1024 * 1024, 'the decoded runtime fixture should be larger than the complete PHP memory limit');
    assert_true((int) ($payload['compressed_fixture_bytes'] ?? PHP_INT_MAX) <= 2 * 1024 * 1024, 'the highly compressed runtime should remain a credible expansion adversary');
    assert_same('RuntimeException', $payload['error']['class'] ?? null, 'the compressed runtime must reject structurally rather than inflate toward OOM');
    assert_contains('require an indexed lookup sidecar', (string) ($payload['error']['message'] ?? ''), 'the compressed runtime should identify its missing sidecar');
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
