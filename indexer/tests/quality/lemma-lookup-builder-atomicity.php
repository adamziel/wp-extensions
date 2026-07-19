<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tools/build-lemma-pack-lookup-index.php';

test_case('low-level lemma lookup build rolls sidecar publication failure back byte-for-byte', function (): void {
    $root = lemma_builder_fixture_root('low-level-rollback');
    try {
        $runtime = $root . '/runtime/source.tsv.gz';
        $encoded = gzencode("alpha\tlemma-alpha\n", 9, ZLIB_ENCODING_GZIP);
        if (!is_string($encoded)) {
            throw new RuntimeException('Could not encode low-level rollback fixture.');
        }
        file_put_contents($runtime, $encoded);
        $lookupDestination = $runtime . '.lookup';
        if (!mkdir($lookupDestination)) {
            throw new RuntimeException('Could not create low-level sidecar publication fault.');
        }
        file_put_contents($lookupDestination . '/original.sidecar', "original sidecar sentinel\n");
        $before = lemma_builder_tree_snapshot($root);

        $error = null;
        try {
            WP_FTS_LemmaPackLookupIndex::build(
                $runtime,
                WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
                (string) hash_file('sha256', $runtime),
                $lookupDestination
            );
        } catch (Throwable $caught) {
            $error = $caught;
        }

        assert_true($error instanceof RuntimeException, 'a non-file sidecar destination should fail after staged repacking');
        assert_contains('not a regular file', $error?->getMessage() ?? '', 'the low-level publication failure should identify its unsafe sidecar destination');
        assert_same($before, lemma_builder_tree_snapshot($root), 'low-level rollback should restore the original runtime and sidecar sentinel byte-for-byte');
        assert_same([], lemma_builder_staging_paths($root), 'low-level rollback should leave no runtime, sidecar, or backup temporary artifacts');
    } finally {
        lemma_builder_remove_tree($root);
    }
});

test_case('low-level lemma lookup build rejects a runtime path alias as its sidecar destination', function (): void {
    $root = lemma_builder_fixture_root('same-path');
    try {
        $runtime = $root . '/runtime/source.tsv.gz';
        $encoded = gzencode("alpha\tlemma-alpha\n", 9, ZLIB_ENCODING_GZIP);
        if (!is_string($encoded)) {
            throw new RuntimeException('Could not encode same-path fixture.');
        }
        file_put_contents($runtime, $encoded);
        $before = lemma_builder_tree_snapshot($root);
        $error = null;
        try {
            WP_FTS_LemmaPackLookupIndex::build(
                $runtime,
                WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
                (string) hash_file('sha256', $runtime),
                dirname($runtime) . '/./' . basename($runtime)
            );
        } catch (Throwable $caught) {
            $error = $caught;
        }

        assert_true($error instanceof InvalidArgumentException, 'a lexical alias of the runtime path should be rejected as the sidecar destination');
        assert_contains('must differ', $error?->getMessage() ?? '', 'same-path rejection should identify the two-artifact invariant');
        assert_same($before, lemma_builder_tree_snapshot($root), 'same-path rejection should leave the runtime byte-identical');
        assert_same([], lemma_builder_staging_paths($root), 'same-path rejection should create no temporary artifacts');

        foreach (['hardlink', 'symlink'] as $aliasKind) {
            $alias = $root . '/runtime/' . $aliasKind . '.lookup';
            $created = $aliasKind === 'hardlink' ? link($runtime, $alias) : symlink($runtime, $alias);
            if (!$created) {
                throw new RuntimeException("Could not create {$aliasKind} output alias fixture.");
            }
            $aliasBefore = lemma_builder_tree_snapshot($root);
            $aliasError = null;
            try {
                WP_FTS_LemmaPackLookupIndex::build(
                    $runtime,
                    WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
                    (string) hash_file('sha256', $runtime),
                    $alias
                );
            } catch (Throwable $caught) {
                $aliasError = $caught;
            }
            assert_true($aliasError instanceof InvalidArgumentException, "a {$aliasKind} to the runtime should be rejected as the sidecar destination");
            assert_same($aliasBefore, lemma_builder_tree_snapshot($root), "{$aliasKind} rejection should preserve both paths byte-for-byte");
            assert_same([], lemma_builder_staging_paths($root), "{$aliasKind} rejection should create no temporary artifacts");
            unlink($alias);
        }
    } finally {
        lemma_builder_remove_tree($root);
    }
});

test_case('low-level lemma lookup build rejects source byte 16 MiB plus one before hashing or staging', function (): void {
    $root = lemma_builder_fixture_root('source-bytes');
    try {
        $runtime = $root . '/runtime/oversized.tsv.gz';
        $handle = fopen($runtime, 'w+b');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not open oversized runtime fixture.');
        }
        fwrite($handle, "start\n");
        ftruncate($handle, WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK + 1);
        fseek($handle, -5, SEEK_END);
        fwrite($handle, "\nend\n");
        fclose($handle);

        $started = microtime(true);
        $error = null;
        try {
            WP_FTS_LemmaPackLookupIndex::build(
                $runtime,
                WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
                str_repeat('0', 64),
                $runtime . '.lookup'
            );
        } catch (Throwable $caught) {
            $error = $caught;
        }

        assert_same('runtime_lookup_bytes', $error instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $error->reason_code : null, 'source byte 16 MiB plus one should raise the typed physical limit');
        assert_true(microtime(true) - $started <= 1.0, 'the initial fstat rejection should complete within one second');
        assert_same(WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK + 1, filesize($runtime), 'rejection should preserve the oversized source length');
        assert_same("start\n", file_get_contents($runtime, false, null, 0, 6), 'rejection should preserve the source prefix');
        assert_same("\nend\n", file_get_contents($runtime, false, null, filesize($runtime) - 5, 5), 'rejection should preserve the source suffix');
        assert_true(!file_exists($runtime . '.lookup'), 'rejection should not publish a lookup sidecar');
        assert_same([], lemma_builder_staging_paths($root), 'rejection should leave no source snapshot, runtime stage, sidecar stage, or backup');
    } finally {
        lemma_builder_remove_tree($root);
    }
});

test_case('lemma lookup builder rolls a late second-shard publication failure back byte-for-byte', function (): void {
    $root = lemma_builder_fixture_root('rollback');
    try {
        $fixture = lemma_builder_write_pack($root);
        $manifest = json_decode((string) file_get_contents($fixture['manifest']), true, 32, JSON_THROW_ON_ERROR);
        $generatedSecondLookup = $root . '/' . $manifest['runtime']['files'][1]['lookup']['path'];
        $oldSecondLookup = $root . '/runtime/old-second.lookup';
        if (!rename($generatedSecondLookup, $oldSecondLookup)) {
            throw new RuntimeException('Could not relocate the second rollback sidecar.');
        }
        $manifest['runtime']['files'][1]['lookup']['path'] = 'runtime/old-second.lookup';
        lemma_builder_write_json($fixture['manifest'], $manifest);

        // The builder always generates <runtime>.lookup. A directory at that
        // later destination lets runtime1, sidecar1, and runtime2 publish first,
        // then forces publish_pack() through its rollback path without a hook.
        if (!mkdir($generatedSecondLookup)) {
            throw new RuntimeException('Could not create the late publication fault.');
        }
        $before = lemma_builder_tree_snapshot($root);
        $error = null;
        try {
            (new WP_FTS_LemmaPackLookupIndexBuilder())->build($fixture['manifest']);
        } catch (Throwable $caught) {
            $error = $caught;
        }

        assert_true($error instanceof RuntimeException, 'the injected second-shard destination fault should fail publication');
        assert_contains('not a regular file', $error?->getMessage() ?? '', 'the late publication failure should identify its unsafe destination');
        assert_same($before, lemma_builder_tree_snapshot($root), 'every runtime, referenced sidecar, manifest, source lock, and injected directory should be byte-identical after rollback');
        assert_same([], lemma_builder_staging_paths($root), 'late publication rollback should leave no .wp-fts-* staging or backup artifacts');
    } finally {
        lemma_builder_remove_tree($root);
    }
});

test_case('lemma lookup builder rejects mixed compression before changing the pack', function (): void {
    $root = lemma_builder_fixture_root('mixed');
    try {
        $fixture = lemma_builder_write_pack($root);
        $manifest = json_decode((string) file_get_contents($fixture['manifest']), true, 32, JSON_THROW_ON_ERROR);
        $gzipPath = $root . '/' . $manifest['runtime']['files'][1]['path'];
        $plainPath = $root . '/runtime/0002.tsv';
        $malformed = str_repeat('not-a-tsv-runtime-row\n', 4096);
        if (file_put_contents($plainPath, $malformed) !== strlen($malformed)) {
            throw new RuntimeException('Could not create the mixed-compression runtime.');
        }
        unlink($gzipPath);
        unlink($root . '/' . $manifest['runtime']['files'][1]['lookup']['path']);
        $manifest['runtime']['files'][1]['path'] = 'runtime/0002.tsv';
        $manifest['runtime']['files'][1]['sha256'] = hash_file('sha256', $plainPath);
        unset($manifest['runtime']['files'][1]['compression'], $manifest['runtime']['files'][1]['lookup']);
        lemma_builder_write_json($fixture['manifest'], $manifest);

        $before = lemma_builder_tree_snapshot($root);
        $error = null;
        try {
            (new WP_FTS_LemmaPackLookupIndexBuilder())->build($fixture['manifest']);
        } catch (Throwable $caught) {
            $error = $caught;
        }

        assert_true($error instanceof RuntimeException, 'a mixed gzip/plain pack should be rejected');
        assert_contains('every runtime shard', $error?->getMessage() ?? '', 'mixed-compression rejection should state the all-shard requirement');
        assert_same($before, lemma_builder_tree_snapshot($root), 'mixed-compression rejection should leave every pack artifact byte-identical');
        assert_same([], lemma_builder_staging_paths($root), 'mixed-compression rejection should leave no .wp-fts-* staging artifacts');
    } finally {
        lemma_builder_remove_tree($root);
    }
});

test_case('lemma lookup builder bounds manifest and late source-lock reads', function (): void {
    foreach (['manifest', 'source-lock'] as $case) {
        $result = test_run_subprocess([
            PHP_BINARY,
            '-d',
            'memory_limit=128M',
            dirname(__DIR__) . '/fixtures/lemma-lookup-builder-bounded-read.php',
            $case,
        ], dirname(__DIR__, 2));
        assert_same(0, $result['exit'], "the 140-MiB sparse {$case} bounded-read child should survive under 128 MiB: " . $result['stderr']);
        $payload = json_decode(trim($result['stdout']), true);
        assert_true(is_array($payload), "the sparse {$case} child should emit JSON evidence");
        assert_same('manifest_bytes', $payload['reason_code'] ?? null, "the sparse {$case} should reject with the typed byte limit");
        assert_same(true, $payload['pack_unchanged'] ?? null, "the sparse {$case} rejection should leave the pack unchanged");
        assert_same([], $payload['staging_paths'] ?? null, "the sparse {$case} rejection should clean all staged artifacts");
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, "the sparse {$case} child should remain below 128 MiB PHP allocation");
        foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
            $value = $payload['proc_status'][$metric] ?? null;
            if (is_int($value)) {
                assert_true($value <= 128 * 1024 * 1024, "the sparse {$case} child {$metric} should remain below 128 MiB");
            }
        }
    }

    $manifestRoot = lemma_builder_fixture_root('manifest-bytes');
    try {
        $manifest = $manifestRoot . '/manifest.json';
        file_put_contents($manifest, str_repeat('x', WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES + 1));
        $error = null;
        try {
            (new WP_FTS_LemmaPackLookupIndexBuilder())->build($manifest);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        assert_same('manifest_bytes', $error instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $error->reason_code : null, 'manifest byte 65,537 should reject from a limit+1 read');
    } finally {
        lemma_builder_remove_tree($manifestRoot);
    }

    $lockRoot = lemma_builder_fixture_root('lock-bytes');
    try {
        $fixture = lemma_builder_write_pack($lockRoot);
        file_put_contents($fixture['source_lock'], str_repeat('x', WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES + 1));
        $before = lemma_builder_tree_snapshot($lockRoot);
        $error = null;
        try {
            (new WP_FTS_LemmaPackLookupIndexBuilder())->build($fixture['manifest']);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        assert_same('manifest_bytes', $error instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $error->reason_code : null, 'source-lock byte 65,537 should reject after staged shard validation');
        assert_same($before, lemma_builder_tree_snapshot($lockRoot), 'oversized late source-lock rejection should not publish staged runtimes or sidecars');
        assert_same([], lemma_builder_staging_paths($lockRoot), 'oversized source-lock rejection should clean every staged artifact');
    } finally {
        lemma_builder_remove_tree($lockRoot);
    }
});

/** @return array{manifest:string,source_lock:string} */
function lemma_builder_write_pack(string $root): array
{
    $runtimeFiles = [];
    $totalDigest = hash_init('sha256');
    foreach ([['alpha', 'lemma-alpha'], ['omega', 'lemma-omega']] as $index => [$surface, $lemma]) {
        $relativePath = sprintf('runtime/%04d.tsv.gz', $index + 1);
        $runtimePath = $root . '/' . $relativePath;
        $row = $surface . "\t" . $lemma . "\n";
        $encoded = gzencode($row, 9, ZLIB_ENCODING_GZIP);
        if (!is_string($encoded)) {
            throw new RuntimeException('Could not encode builder fixture runtime.');
        }
        file_put_contents($runtimePath, $encoded);
        $lookup = WP_FTS_LemmaPackLookupIndex::build(
            $runtimePath,
            WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
            (string) hash_file('sha256', $runtimePath),
            $runtimePath . '.lookup'
        );
        $runtimeFiles[] = [
            'path' => $relativePath,
            'sha256' => $lookup['runtime_sha256'],
            'rows' => 1,
            'first_surface' => $surface,
            'last_surface' => $surface,
            'compression' => WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
            'lookup' => [
                'format' => $lookup['format'],
                'path' => $relativePath . '.lookup',
                'sha256' => $lookup['sha256'],
                'blocks' => $lookup['blocks'],
            ],
        ];
        hash_update($totalDigest, $row);
    }

    $manifest = [
        'schema_version' => 1,
        'pack_id' => 'en-lookup-builder-atomicity',
        'language' => 'en',
        'version' => '1',
        'fixture_only' => false,
        'default_enabled' => false,
        'capabilities' => ['dictionary-lemmatizer', 'indexed-runtime-lookups'],
        'runtime' => [
            'format' => WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV,
            'ambiguity_policy' => 'ambiguous_surface_noop',
            'total_rows' => 2,
            'total_sha256' => hash_final($totalDigest),
            'files' => $runtimeFiles,
        ],
        'source' => [
            'name' => 'Project-owned lookup builder atomicity fixture',
            'version' => '1',
            'url' => 'urn:wp-fts:test:lookup-builder-atomicity',
            'artifact_sha256' => str_repeat('a', 64),
            'byte_count' => 2,
        ],
        'license' => ['spdx_id' => 'CC0-1.0', 'notice_path' => 'NOTICE.txt'],
        'attribution' => ['note' => 'Project-owned generated fixture.'],
        'provenance' => ['no_runtime_network_access' => true],
    ];
    $manifestPath = $root . '/manifest.json';
    lemma_builder_write_json($manifestPath, $manifest);
    $sourceLockPath = $root . '/SOURCE.lock.json';
    lemma_builder_write_json($sourceLockPath, [
        'runtime' => [
            'manifest_sha256' => hash_file('sha256', $manifestPath),
            'byte_count' => array_sum(array_map(
                static fn(array $file): int => (int) filesize($root . '/' . $file['path']),
                $runtimeFiles
            )),
        ],
    ]);

    return ['manifest' => $manifestPath, 'source_lock' => $sourceLockPath];
}

/** Create one isolated two-artifact publication root for a failure case. */
function lemma_builder_fixture_root(string $case): string
{
    $root = sys_get_temp_dir() . '/wp-fts-lemma-builder-' . $case . '-' . bin2hex(random_bytes(5));
    if (!mkdir($root . '/runtime', 0777, true)) {
        throw new RuntimeException('Could not create lookup builder fixture directory.');
    }
    file_put_contents($root . '/NOTICE.txt', "Project-owned lookup builder fixture.\n");

    return $root;
}

/** @param array<string,mixed> $value */
function lemma_builder_write_json(string $path, array $value): void
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $json) !== strlen($json)) {
        throw new RuntimeException('Could not write lookup builder fixture metadata.');
    }
}

/** @return array<string,string> */
function lemma_builder_tree_snapshot(string $root): array
{
    $snapshot = [];
    $walk = static function (string $directory) use (&$walk, &$snapshot, $root): void {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            $relative = substr($path, strlen($root) + 1);
            if (is_dir($path) && !is_link($path)) {
                $snapshot[$relative . '/'] = 'directory';
                $walk($path);
                continue;
            }
            $contents = file_get_contents($path);
            if (!is_string($contents)) {
                throw new RuntimeException('Could not snapshot lookup builder artifact.');
            }
            $snapshot[$relative] = hash('sha256', $contents) . ':' . strlen($contents);
        }
    };
    $walk($root);
    ksort($snapshot, SORT_STRING);

    return $snapshot;
}

/** @return string[] */
function lemma_builder_staging_paths(string $root): array
{
    $paths = [];
    $walk = static function (string $directory) use (&$walk, &$paths): void {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (str_starts_with($entry, '.wp-fts-')) {
                $paths[] = $path;
            }
            if (is_dir($path) && !is_link($path)) {
                $walk($path);
            }
        }
    };
    $walk($root);
    sort($paths, SORT_STRING);

    return $paths;
}

/** Remove a generated builder tree without following test-created symlinks. */
function lemma_builder_remove_tree(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    foreach (scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $root . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            lemma_builder_remove_tree($path);
        } else {
            unlink($path);
        }
    }
    rmdir($root);
}
