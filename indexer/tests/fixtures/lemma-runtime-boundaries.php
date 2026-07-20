<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$case = $argv[1] ?? '';
try {
    if ($case === 'indexed-document') {
        $evidence = wp_fts_lemma_sidecar_indexed_document_case();
    } elseif ($case === 'indexed-query') {
        $evidence = wp_fts_lemma_sidecar_indexed_query_case();
    } elseif ($case === 'maximum-document') {
        $evidence = wp_fts_lemma_sidecar_maximum_case(false);
    } elseif ($case === 'maximum-query') {
        $evidence = wp_fts_lemma_sidecar_maximum_case(true);
    } elseif ($case === 'configured-maximum-document') {
        $evidence = wp_fts_lemma_sidecar_configured_maximum_case();
    } elseif ($case === 'configured-overflow') {
        $evidence = wp_fts_lemma_sidecar_configured_overflow_case();
    } else {
        throw new InvalidArgumentException('Expected indexed-document, indexed-query, maximum-document, maximum-query, configured-maximum-document, or configured-overflow fixture case.');
    }

    echo json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}

/** @return array<string,mixed> */
function wp_fts_lemma_sidecar_indexed_document_case(): array
{
    $started = microtime(true);
    $root = wp_fts_lemma_sidecar_fixture_root('indexed-document');
    try {
        $fixture = wp_fts_lemma_sidecar_write_fixture($root);
        $analyzer = wp_fts_lemma_sidecar_analyzer($fixture['manifest']);
        $indexer = new WP_FTS_Indexer($analyzer);
        $documentIoBefore = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
        $fields = [];
        foreach (array_chunk($fixture['document_terms'], 128) as $fieldIndex => $terms) {
            $fields[] = [
                'name' => 'field_' . $fieldIndex,
                'text' => implode(' ', $terms),
                'boost' => 1.0,
            ];
        }
        $prepared = $indexer->prepare_document_fields(1, $fields, ['document_lang' => 'qaa']);
        $documentIoAfter = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
        $documentDigest = hash_init('sha256');
        foreach ($fixture['document_indexes'] as $index => $runtimeIndex) {
            $surface = $fixture['document_terms'][$index];
            $expected = wp_fts_lemma_sidecar_lemma($runtimeIndex);
            $stored = WP_FTS_TermNamespace::namespace_term('qaa', $expected);
            if (($prepared['term_frequencies'][$stored] ?? 0) < 1) {
                throw new RuntimeException("Indexed document morphology mismatch for {$surface}.");
            }
            if (!WP_FTS_TermNamespace::term_key_fits($expected, 'qaa')) {
                throw new RuntimeException("Indexed document lemma does not fit the production term identity for {$surface}.");
            }
            hash_update($documentDigest, $expected . "\n");
        }
        $documentEvidence = [
            'analyzer_path' => 'WP_FTS_Indexer::prepare_document_fields -> WP_FTS_Analyzer::analyze_document_fields',
            'fields' => count($fields),
            'lookup_calls' => count($fixture['document_terms']),
            'distinct_morphologies' => count($prepared['term_frequencies']),
            'morphology_sha256' => hash_final($documentDigest),
            'indexed_io' => wp_fts_lemma_sidecar_diagnostic_delta($documentIoBefore, $documentIoAfter),
        ];

        return [
            'case' => 'indexed-document',
            'runtime_decoded_bytes' => $fixture['decoded_bytes'],
            'runtime_compressed_bytes' => $fixture['runtime_compressed_bytes'],
            'runtime_rows' => $fixture['rows'],
            'sidecar_bytes' => $fixture['sidecar_bytes'],
            'sidecar_blocks' => $fixture['sidecar_blocks'],
            'decoded_block_rows' => $fixture['decoded_block_rows'],
            'document' => wp_fts_lemma_sidecar_document_identity($fixture) + $documentEvidence,
            'digest_attestation' => $analyzer->lemma_pack_diagnostics('qaa')['digest'] ?? null,
            'elapsed_seconds' => microtime(true) - $started,
            'php_peak_bytes' => memory_get_peak_usage(true),
            'proc_status' => wp_fts_lemma_sidecar_proc_status(),
        ];
    } finally {
        wp_fts_lemma_sidecar_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_lemma_sidecar_indexed_query_case(): array
{
    $started = microtime(true);
    $root = wp_fts_lemma_sidecar_fixture_root('indexed-query');
    try {
        $fixture = wp_fts_lemma_sidecar_write_fixture($root);
        $analyzer = wp_fts_lemma_sidecar_analyzer($fixture['manifest']);
        $query = implode(' ', $fixture['query_terms']);
        $ioBefore = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
        $occurrences = $analyzer->analyze_query_occurrences($query, [
            'query_lang' => 'qaa',
            '_max_query_occurrences' => WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_GROUPS,
        ]);
        $ioAfter = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
        $digest = hash_init('sha256');
        foreach ($fixture['query_indexes'] as $index => $runtimeIndex) {
            $expected = wp_fts_lemma_sidecar_lemma($runtimeIndex);
            if (($occurrences[$index]['term'] ?? null) !== $expected) {
                throw new RuntimeException("Indexed query morphology mismatch for {$fixture['query_terms'][$index]}.");
            }
            hash_update($digest, $expected . "\n");
        }

        return [
            'case' => 'indexed-query',
            'runtime_decoded_bytes' => $fixture['decoded_bytes'],
            'runtime_compressed_bytes' => $fixture['runtime_compressed_bytes'],
            'runtime_rows' => $fixture['rows'],
            'sidecar_bytes' => $fixture['sidecar_bytes'],
            'sidecar_blocks' => $fixture['sidecar_blocks'],
            'decoded_block_rows' => $fixture['decoded_block_rows'],
            'query' => wp_fts_lemma_sidecar_query_identity($fixture) + [
                'analyzer_path' => 'WP_FTS_Analyzer::analyze_query_occurrences',
                'lookup_calls' => count($occurrences),
                'morphology_sha256' => hash_final($digest),
                'indexed_io' => wp_fts_lemma_sidecar_diagnostic_delta($ioBefore, $ioAfter),
            ],
            'digest_attestation' => $analyzer->lemma_pack_diagnostics('qaa')['digest'] ?? null,
            'elapsed_seconds' => microtime(true) - $started,
            'php_peak_bytes' => memory_get_peak_usage(true),
            'proc_status' => wp_fts_lemma_sidecar_proc_status(),
        ];
    } finally {
        wp_fts_lemma_sidecar_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_lemma_sidecar_maximum_case(bool $queryCase): array
{
    $case = $queryCase ? 'maximum-query' : 'maximum-document';
    $started = microtime(true);
    $phpUsageBefore = memory_get_usage(true);
    $phpPeakBefore = memory_get_peak_usage(true);
    $procBefore = wp_fts_lemma_sidecar_proc_status();
    $root = wp_fts_lemma_sidecar_fixture_root($case);
    try {
        $fixture = wp_fts_lemma_sidecar_write_maximum_fixture($root);
        $analyzer = wp_fts_lemma_sidecar_analyzer($fixture['manifest']);
        $ioBefore = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
        $digest = hash_init('sha256');
        $workload = [];
        if ($queryCase) {
            $source = implode(' ', $fixture['query_terms']);
            $occurrences = $analyzer->analyze_query_occurrences($source, [
                'query_lang' => 'qaa',
                '_max_query_occurrences' => WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_GROUPS,
            ]);
            foreach ($fixture['query_indexes'] as $index => $runtimeIndex) {
                $expected = wp_fts_lemma_sidecar_lemma($runtimeIndex);
                if (($occurrences[$index]['term'] ?? null) !== $expected) {
                    throw new RuntimeException('Maximum-shape query morphology mismatch.');
                }
                hash_update($digest, $expected . "\n");
            }
            $workload = [
                'source_bytes' => strlen($source),
                'source_sha256' => hash('sha256', $source),
                'selected_identities' => count($occurrences),
                'selected_blocks' => count($occurrences),
                'selected_files' => count(array_unique(array_map(
                    static fn(int $index): int => intdiv($index, $fixture['rows_per_shard']),
                    $fixture['query_indexes']
                ))),
            ];
        } else {
            $fields = [];
            foreach (array_chunk($fixture['document_terms'], 128) as $fieldIndex => $terms) {
                $fields[] = ['name' => 'field_' . $fieldIndex, 'text' => implode(' ', $terms), 'boost' => 1.0];
            }
            $prepared = (new WP_FTS_Indexer($analyzer))
                ->prepare_document_fields(2, $fields, ['document_lang' => 'qaa']);
            foreach ($fixture['document_indexes'] as $runtimeIndex) {
                $expected = wp_fts_lemma_sidecar_lemma($runtimeIndex);
                if (!isset($prepared['term_frequencies'][WP_FTS_TermNamespace::namespace_term('qaa', $expected)])) {
                    throw new RuntimeException('Maximum-shape document morphology mismatch.');
                }
                hash_update($digest, $expected . "\n");
            }
            $source = implode(' ', $fixture['document_terms']);
            $workload = [
                'source_bytes' => strlen($source),
                'source_sha256' => hash('sha256', $source),
                'fields' => count($fields),
                'selected_identities' => count($prepared['term_frequencies']),
                'selected_blocks' => count($fixture['document_terms']),
                'selected_files' => $fixture['runtime_files'],
            ];
        }
        $ioAfter = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
        $procAfter = wp_fts_lemma_sidecar_proc_status();
        $phpUsageAfter = memory_get_usage(true);
        $phpPeakAfter = memory_get_peak_usage(true);

        return [
            'case' => $case,
            'declared' => [
                'runtime_files' => $fixture['runtime_files'],
                'lookup_blocks' => $fixture['sidecar_blocks'],
                'decoded_block_bytes' => WP_FTS_LemmaPackLookupIndex::MAX_BLOCK_DECODED_BYTES,
                'decoded_bytes' => $fixture['decoded_bytes'],
                'runtime_compressed_bytes' => $fixture['runtime_compressed_bytes'],
                'sidecar_bytes' => $fixture['sidecar_bytes'],
                'runtime_lookup_bytes' => $fixture['runtime_compressed_bytes'] + $fixture['sidecar_bytes'],
            ],
            'workload' => $workload + [
                'morphology_sha256' => hash_final($digest),
                'indexed_io' => wp_fts_lemma_sidecar_diagnostic_delta($ioBefore, $ioAfter),
            ],
            'digest_attestation' => $analyzer->lemma_pack_diagnostics('qaa')['digest'] ?? null,
            'same_second_generation' => $fixture['newest_generation'] >= $fixture['created_second'],
            'elapsed_seconds' => microtime(true) - $started,
            'php_peak_bytes' => $phpPeakAfter,
            'memory' => [
                'php_usage_before_bytes' => $phpUsageBefore,
                'php_usage_after_bytes' => $phpUsageAfter,
                'php_usage_delta_bytes' => max(0, $phpUsageAfter - $phpUsageBefore),
                'php_peak_before_bytes' => $phpPeakBefore,
                'php_peak_after_bytes' => $phpPeakAfter,
                'php_peak_delta_bytes' => max(0, $phpPeakAfter - $phpPeakBefore),
                'proc_before' => $procBefore,
                'proc_after' => $procAfter,
                'rss_delta_bytes' => is_int($procBefore['VmRSS_bytes']) && is_int($procAfter['VmRSS_bytes'])
                    ? max(0, $procAfter['VmRSS_bytes'] - $procBefore['VmRSS_bytes'])
                    : null,
            ],
            'proc_status' => $procAfter,
        ];
    } finally {
        wp_fts_lemma_sidecar_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_lemma_sidecar_configured_maximum_case(): array
{
    $started = microtime(true);
    $phpUsageBefore = memory_get_usage(true);
    $phpPeakBefore = memory_get_peak_usage(true);
    $procBefore = wp_fts_lemma_sidecar_proc_status();
    $root = wp_fts_lemma_sidecar_fixture_root('configured-maximum-document');
    try {
        $packs = [];
        foreach (['qaa', 'qab'] as $language) {
            $packRoot = $root . '/' . $language;
            if (!mkdir($packRoot)) {
                throw new RuntimeException('Could not create configured fan-out pack directory.');
            }
            file_put_contents($packRoot . '/NOTICE.txt', "Project-owned configured fan-out fixture.\n");
            $packs[$language] = wp_fts_lemma_sidecar_write_block_pack(
                $packRoot,
                $language,
                WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_FILES,
                intdiv(WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES, 2)
            );
        }

        $analyzer = new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'default_lang' => 'qaa',
            'document_lang' => 'qaa',
            'query_lang' => 'qaa',
            'lemma_packs_by_lang' => [
                'qaa' => $packs['qaa']['manifest'],
                'qab' => $packs['qab']['manifest'],
            ],
        ]);
        $fields = [];
        $fieldSources = [];
        $expectedRows = [];
        $morphologyDigest = hash_init('sha256');
        foreach ($packs as $language => $pack) {
            $indexes = [];
            for ($ordinal = 0; $ordinal < intdiv(WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES, 2); $ordinal++) {
                $block = ($ordinal * 2027) % $pack['sidecar_blocks'];
                $indexes[] = $block * 64;
            }
            $terms = array_map(
                static fn(int $index): string => wp_fts_lemma_sidecar_surface($index, $language),
                $indexes
            );
            foreach ($indexes as $index) {
                $lemma = wp_fts_lemma_sidecar_lemma($index, $language);
                $expectedRows[] = ['language' => $language, 'lemma' => $lemma];
                hash_update($morphologyDigest, $language . "\t" . $lemma . "\n");
            }
            foreach (array_chunk($terms, 128) as $fieldIndex => $fieldTerms) {
                $html = '<div lang="' . $language . '">' . implode(' ', $fieldTerms) . '</div>';
                $fieldSources[] = $html;
                $fields[] = [
                    'name' => $language . '_field_' . $fieldIndex,
                    'text' => '',
                    'html' => $html,
                    'boost' => 1.0,
                ];
            }
        }

        $ioBefore = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
        $prepared = (new WP_FTS_Indexer($analyzer))
            ->prepare_document_fields(3, $fields, ['document_lang' => 'qaa']);
        $ioAfter = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
        foreach ($expectedRows as $row) {
            $identity = WP_FTS_TermNamespace::namespace_term($row['language'], $row['lemma']);
            if (!isset($prepared['term_frequencies'][$identity])) {
                throw new RuntimeException("Configured fan-out morphology mismatch for {$identity}.");
            }
        }

        $digest = ['files_hashed' => 0, 'bytes_hashed' => 0];
        foreach (array_keys($packs) as $language) {
            $packDigest = $analyzer->lemma_pack_diagnostics($language)['digest'] ?? [];
            $digest['files_hashed'] += (int) ($packDigest['files_hashed'] ?? 0);
            $digest['bytes_hashed'] += (int) ($packDigest['bytes_hashed'] ?? 0);
        }
        $runtimeCompressedBytes = array_sum(array_column($packs, 'runtime_compressed_bytes'));
        $sidecarBytes = array_sum(array_column($packs, 'sidecar_bytes'));
        $sameSecondGeneration = true;
        foreach ($packs as $pack) {
            $sameSecondGeneration = $sameSecondGeneration
                && $pack['newest_generation'] >= $pack['created_second'];
        }
        $procAfter = wp_fts_lemma_sidecar_proc_status();
        $phpUsageAfter = memory_get_usage(true);
        $phpPeakAfter = memory_get_peak_usage(true);
        $source = implode("\n", $fieldSources);

        return [
            'case' => 'configured-maximum-document',
            'declared' => [
                'packs' => count($packs),
                'runtime_files' => array_sum(array_column($packs, 'runtime_files')),
                'lookup_blocks' => array_sum(array_column($packs, 'sidecar_blocks')),
                'decoded_block_bytes' => WP_FTS_LemmaPackLookupIndex::MAX_BLOCK_DECODED_BYTES,
                'decoded_bytes' => array_sum(array_column($packs, 'decoded_bytes')),
                'runtime_compressed_bytes' => $runtimeCompressedBytes,
                'sidecar_bytes' => $sidecarBytes,
                'runtime_lookup_bytes' => $runtimeCompressedBytes + $sidecarBytes,
            ],
            'workload' => [
                'source_bytes' => strlen($source),
                'source_sha256' => hash('sha256', $source),
                'fields' => count($fields),
                'languages' => array_keys($packs),
                'selected_identities' => count($prepared['term_frequencies']),
                'selected_blocks' => count($expectedRows),
                'selected_files' => array_sum(array_column($packs, 'runtime_files')),
                'morphology_sha256' => hash_final($morphologyDigest),
                'indexed_io' => wp_fts_lemma_sidecar_diagnostic_delta($ioBefore, $ioAfter),
            ],
            'digest_attestation' => $digest,
            'same_second_generation' => $sameSecondGeneration,
            'elapsed_seconds' => microtime(true) - $started,
            'php_peak_bytes' => $phpPeakAfter,
            'memory' => [
                'php_usage_before_bytes' => $phpUsageBefore,
                'php_usage_after_bytes' => $phpUsageAfter,
                'php_usage_delta_bytes' => max(0, $phpUsageAfter - $phpUsageBefore),
                'php_peak_before_bytes' => $phpPeakBefore,
                'php_peak_after_bytes' => $phpPeakAfter,
                'php_peak_delta_bytes' => max(0, $phpPeakAfter - $phpPeakBefore),
                'proc_before' => $procBefore,
                'proc_after' => $procAfter,
                'rss_delta_bytes' => is_int($procBefore['VmRSS_bytes']) && is_int($procAfter['VmRSS_bytes'])
                    ? max(0, $procAfter['VmRSS_bytes'] - $procBefore['VmRSS_bytes'])
                    : null,
            ],
            'proc_status' => $procAfter,
        ];
    } finally {
        wp_fts_lemma_sidecar_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_lemma_sidecar_configured_overflow_case(): array
{
    $started = microtime(true);
    $root = wp_fts_lemma_sidecar_fixture_root('configured-overflow');
    try {
        $firstRoot = $root . '/copy-1';
        if (!mkdir($firstRoot)) {
            throw new RuntimeException('Could not create configured-overflow source pack.');
        }
        file_put_contents($firstRoot . '/NOTICE.txt', "Project-owned configured-overflow fixture.\n");
        $first = wp_fts_lemma_sidecar_write_block_pack(
            $firstRoot,
            'qaa',
            WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_FILES,
            WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_FILES
        );
        $manifests = [$first['manifest']];
        foreach ([2, 3] as $copy) {
            $copyRoot = $root . '/copy-' . $copy;
            if (!mkdir($copyRoot)) {
                throw new RuntimeException('Could not create configured-overflow copy directory.');
            }
            wp_fts_lemma_sidecar_copy_tree($firstRoot, $copyRoot);
            $copyManifestPath = $copyRoot . '/manifest.json';
            $copyManifest = json_decode(
                (string) file_get_contents($copyManifestPath),
                true,
                64,
                JSON_THROW_ON_ERROR
            );
            $copyLanguage = $copy === 2 ? 'qab' : 'qac';
            $copyManifest['language'] = $copyLanguage;
            $copyManifest['pack_id'] = "{$copyLanguage}-maximum-fan-out-envelope";
            $copyManifest['runtime']['normalization'] = "WP_FTS_Normalizer {$copyLanguage} with fold_diacritics=true";
            file_put_contents(
                $copyManifestPath,
                json_encode($copyManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
            );
            $manifests[] = $copyManifestPath;
        }

        $twoPackOptions = [
            'qaa' => $manifests[0],
            'qab' => $manifests[1],
        ];
        $acceptedPipeline = new WP_FTS_LanguagePipeline(['lemma_packs_by_lang' => $twoPackOptions]);
        $statusMethod = new ReflectionMethod(WP_FTS_Plugin::class, 'analyzer_pack_statuses');
        $statusMethod->setAccessible(true);
        $acceptedStatuses = $statusMethod->invoke(null, ['lemma_packs_by_lang' => $twoPackOptions], false);

        $thirdLookups = glob(dirname($manifests[2]) . '/*.lookup');
        if (!is_array($thirdLookups) || !isset($thirdLookups[0])) {
            throw new RuntimeException('Configured-overflow third copy has no lookup sidecar to corrupt.');
        }
        file_put_contents($thirdLookups[0], "malformed third-copy sidecar\n");
        $threePackOptions = $twoPackOptions + ['qac' => $manifests[2]];

        $pipelineIoBefore = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
        $pipelineError = null;
        try {
            new WP_FTS_LanguagePipeline(['lemma_packs_by_lang' => $threePackOptions]);
        } catch (Throwable $caught) {
            $pipelineError = $caught;
        }
        $pipelineIoAfter = WP_FTS_LemmaPackLookupIndex::io_diagnostics();

        $statusIoBefore = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
        $statusError = null;
        try {
            $statusMethod->invoke(null, ['lemma_packs_by_lang' => $threePackOptions], false);
        } catch (Throwable $caught) {
            $statusError = $caught;
        }
        $statusIoAfter = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
        unset($acceptedPipeline);

        return [
            'case' => 'configured-overflow',
            'declared' => [
                'accepted_physical_packs' => 2,
                'overflow_physical_packs' => 3,
                'files_per_pack' => WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_FILES,
                'accepted_runtime_files' => 2 * WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_FILES,
                'overflow_runtime_files' => 3 * WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_FILES,
            ],
            'accepted' => [
                'pipeline' => true,
                'active_statuses' => is_array($acceptedStatuses)
                    ? count(array_filter(
                        $acceptedStatuses,
                        static fn(array $status): bool => ($status['status'] ?? null) === 'active'
                    ))
                    : 0,
            ],
            'pipeline_overflow' => [
                'error_class' => $pipelineError === null ? null : get_class($pipelineError),
                'reason_code' => $pipelineError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded
                    ? $pipelineError->reason_code
                    : null,
                'lookup_header_opens' => $pipelineIoAfter['lookup_header_opens']
                    - $pipelineIoBefore['lookup_header_opens'],
                'indexed_io' => wp_fts_lemma_sidecar_diagnostic_delta($pipelineIoBefore, $pipelineIoAfter),
            ],
            'status_overflow' => [
                'error_class' => $statusError === null ? null : get_class($statusError),
                'reason_code' => $statusError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded
                    ? $statusError->reason_code
                    : null,
                'lookup_header_opens' => $statusIoAfter['lookup_header_opens']
                    - $statusIoBefore['lookup_header_opens'],
                'indexed_io' => wp_fts_lemma_sidecar_diagnostic_delta($statusIoBefore, $statusIoAfter),
            ],
            'elapsed_seconds' => microtime(true) - $started,
            'php_peak_bytes' => memory_get_peak_usage(true),
            'proc_status' => wp_fts_lemma_sidecar_proc_status(),
        ];
    } finally {
        wp_fts_lemma_sidecar_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_lemma_sidecar_write_maximum_fixture(string $root): array
{
    $runtimeFiles = WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_FILES;
    $fixture = wp_fts_lemma_sidecar_write_block_pack(
        $root,
        'qaa',
        $runtimeFiles,
        WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES
    );
    $rowsPerBlock = 64;
    $documentIndexes = [];
    for ($ordinal = 0; $ordinal < WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES; $ordinal++) {
        $block = ($ordinal * 4051) % $fixture['sidecar_blocks'];
        $documentIndexes[] = $block * $rowsPerBlock;
    }
    $queryIndexes = [];
    for ($ordinal = 0; $ordinal < WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_GROUPS; $ordinal++) {
        $block = ($ordinal * 341) % $fixture['sidecar_blocks'];
        $queryIndexes[] = $block * $rowsPerBlock;
    }

    return $fixture + [
        'document_indexes' => $documentIndexes,
        'document_terms' => array_map('wp_fts_lemma_sidecar_surface', $documentIndexes),
        'query_indexes' => $queryIndexes,
        'query_terms' => array_map('wp_fts_lemma_sidecar_surface', $queryIndexes),
    ];
}

/** @return array<string,mixed> */
function wp_fts_lemma_sidecar_write_block_pack(
    string $root,
    string $language,
    int $runtimeFiles,
    int $totalBlocks
): array {
    if ($runtimeFiles < 1 || $totalBlocks < 1 || $totalBlocks % $runtimeFiles !== 0) {
        throw new InvalidArgumentException('Block-pack shape must divide evenly across runtime files.');
    }
    $createdSecond = time();
    $blocksPerFile = intdiv($totalBlocks, $runtimeFiles);
    $rowsPerBlock = 64;
    $rowsPerShard = $blocksPerFile * $rowsPerBlock;
    $rows = $runtimeFiles * $rowsPerShard;
    $decodedBytes = $rows * 256;
    $runtimeEntries = [];
    $runtimeCompressedBytes = 0;
    $sidecarBytes = 0;
    $sidecarBlocks = 0;
    $newestGeneration = 0;
    $rowDigest = hash_init('sha256');
    for ($shard = 0; $shard < $runtimeFiles; $shard++) {
        $firstIndex = $shard * $rowsPerShard;
        $lastIndex = $firstIndex + $rowsPerShard - 1;
        $plain = $root . "/{$language}-maximum-{$shard}.tsv";
        $handle = fopen($plain, 'wb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not create maximum-fan-out lemma runtime shard.');
        }
        try {
            $buffer = '';
            for ($index = $firstIndex; $index <= $lastIndex; $index++) {
                $line = wp_fts_lemma_sidecar_surface($index, $language)
                    . "\t"
                    . wp_fts_lemma_sidecar_lemma($index, $language)
                    . "\n";
                if (strlen($line) !== 256) {
                    throw new RuntimeException('Maximum-fan-out lemma row is not exactly 256 bytes.');
                }
                $buffer .= $line;
                hash_update($rowDigest, $line);
                if (strlen($buffer) >= 65536) {
                    wp_fts_lemma_sidecar_write_all($handle, $buffer);
                    $buffer = '';
                }
            }
            wp_fts_lemma_sidecar_write_all($handle, $buffer);
        } finally {
            fclose($handle);
        }
        $runtime = $root . "/{$language}-maximum-{$shard}.tsv.gz";
        wp_fts_lemma_sidecar_gzip_file($plain, $runtime);
        unlink($plain);
        $lookup = $runtime . '.lookup';
        $sidecar = WP_FTS_LemmaPackLookupIndex::build(
            $runtime,
            (string) hash_file('sha256', $runtime),
            $lookup
        );
        if ($sidecar['blocks'] !== $blocksPerFile) {
            throw new RuntimeException("Maximum-fan-out shard did not produce exactly {$blocksPerFile} decoded blocks.");
        }
        $runtimeEntries[] = [
            'path' => basename($runtime),
            'sha256' => $sidecar['runtime_sha256'],
            'rows' => $rowsPerShard,
            'first_surface' => wp_fts_lemma_sidecar_surface($firstIndex, $language),
            'last_surface' => wp_fts_lemma_sidecar_surface($lastIndex, $language),
            'compression' => WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
            'lookup' => [
                'format' => $sidecar['format'],
                'path' => basename($lookup),
                'sha256' => $sidecar['sha256'],
                'blocks' => $sidecar['blocks'],
            ],
        ];
        $runtimeCompressedBytes += (int) filesize($runtime);
        $sidecarBytes += (int) filesize($lookup);
        $sidecarBlocks += $sidecar['blocks'];
        $newestGeneration = max($newestGeneration, (int) filemtime($runtime), (int) filemtime($lookup));
    }

    $manifest = [
        'schema_version' => 1,
        'pack_id' => "{$language}-maximum-fan-out-envelope",
        'language' => $language,
        'version' => '1',
        'capabilities' => ['dictionary-lemmatizer', 'normalized-runtime-rows', 'indexed-runtime-lookups'],
        'runtime' => [
            'format' => WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV,
            'normalization' => "WP_FTS_Normalizer {$language} with fold_diacritics=true",
            'ambiguity_policy' => 'ambiguous_surface_noop',
            'total_rows' => $rows,
            'total_sha256' => hash_final($rowDigest),
            'files' => $runtimeEntries,
        ],
        'source' => [
            'name' => 'Project-owned maximum fan-out lemma fixture',
            'version' => '1',
            'url' => "urn:wp-fts:test:maximum-fan-out-lemma:{$language}",
            'artifact_sha256' => hash('sha256', $language),
            'byte_count' => $decodedBytes,
        ],
        'license' => ['spdx_id' => 'CC0-1.0', 'notice_path' => 'NOTICE.txt'],
        'attribution' => ['note' => 'Project-owned generated fixture.'],
        'provenance' => ['no_runtime_network_access' => true],
    ];
    $manifestPath = $root . '/manifest.json';
    file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    return [
        'manifest' => $manifestPath,
        'language' => $language,
        'runtime_files' => $runtimeFiles,
        'rows_per_shard' => $rowsPerShard,
        'decoded_bytes' => $decodedBytes,
        'runtime_compressed_bytes' => $runtimeCompressedBytes,
        'sidecar_bytes' => $sidecarBytes,
        'sidecar_blocks' => $sidecarBlocks,
        'created_second' => $createdSecond,
        'newest_generation' => $newestGeneration,
    ];
}

/** Construct the production analyzer shape used by single-pack workloads. */
function wp_fts_lemma_sidecar_analyzer(string $manifest): WP_FTS_Analyzer
{
    return new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'default_lang' => 'qaa',
        'document_lang' => 'qaa',
        'query_lang' => 'qaa',
        'lemma_packs_by_lang' => ['qaa' => $manifest],
    ]);
}

/** Create one isolated pack root with the required project-owned notice. */
function wp_fts_lemma_sidecar_fixture_root(string $case): string
{
    $root = sys_get_temp_dir() . '/wp-fts-lemma-sidecar-' . $case . '-' . bin2hex(random_bytes(6));
    if (!mkdir($root, 0777, true) && !is_dir($root)) {
        throw new RuntimeException('Could not create lemma-sidecar fixture directory.');
    }
    file_put_contents($root . '/NOTICE.txt', "Project-owned generated lemma-sidecar fixture.\n");

    return $root;
}

/**
 * @return array{
 *   manifest:string,
 *   runtime:string,
 *   lookup:string,
 *   decoded_bytes:int,
 *   rows:int,
 *   block_rows:int,
 *   sidecar:?array<string,mixed>,
 *   document_terms:string[],
 *   document_indexes:int[],
 *   query_terms:string[],
 *   query_indexes:int[]
 * }
 */
function wp_fts_lemma_sidecar_write_fixture(string $root): array
{
    $decodedBytes = 8 * 1024 * 1024;
    $rows = 32768;
    $lineBytes = intdiv($decodedBytes, $rows);
    $blockRows = 2048;
    if ($lineBytes * $rows !== $decodedBytes) {
        throw new RuntimeException('Lemma-sidecar rows do not divide the exact decoded boundary.');
    }

    $rowDigest = hash_init('sha256');
    $runtimeEntries = [];
    $runtimeCompressedBytes = 0;
    $sidecarBytes = 0;
    $sidecarBlocks = 0;
    $rowsPerShard = intdiv($rows, 2);
    for ($shard = 0; $shard < 2; $shard++) {
        $firstIndex = $shard * $rowsPerShard;
        $lastIndex = $firstIndex + $rowsPerShard - 1;
        $plain = $root . '/runtime-' . $shard . '.tsv';
        $plainHandle = fopen($plain, 'wb');
        if (!is_resource($plainHandle)) {
            throw new RuntimeException('Could not create exact lemma-sidecar runtime source.');
        }
        try {
            $buffer = '';
            for ($index = $firstIndex; $index <= $lastIndex; $index++) {
                $surface = wp_fts_lemma_sidecar_surface($index);
                $lemma = wp_fts_lemma_sidecar_lemma($index);
                $line = $surface . "\t" . $lemma . "\n";
                if (strlen($line) !== $lineBytes) {
                    throw new RuntimeException('Lemma-sidecar runtime row does not have the exact fixed width.');
                }
                $buffer .= $line;
                hash_update($rowDigest, $line);
                if (strlen($buffer) >= 65536) {
                    wp_fts_lemma_sidecar_write_all($plainHandle, $buffer);
                    $buffer = '';
                }
            }
            wp_fts_lemma_sidecar_write_all($plainHandle, $buffer);
        } finally {
            fclose($plainHandle);
        }
        if (filesize($plain) !== intdiv($decodedBytes, 2)) {
            throw new RuntimeException('Lemma-sidecar runtime shard is not exactly 4 MiB.');
        }

        $runtime = $root . '/runtime-' . $shard . '.tsv.gz';
        wp_fts_lemma_sidecar_gzip_file($plain, $runtime);
        unlink($plain);
        $lookup = $runtime . '.lookup';
        $sidecar = WP_FTS_LemmaPackLookupIndex::build(
            $runtime,
            (string) hash_file('sha256', $runtime),
            $lookup,
            $blockRows
        );

        $runtimeEntry = [
            'path' => basename($runtime),
            'sha256' => $sidecar['runtime_sha256'],
            'rows' => $rowsPerShard,
            'first_surface' => wp_fts_lemma_sidecar_surface($firstIndex),
            'last_surface' => wp_fts_lemma_sidecar_surface($lastIndex),
            'compression' => WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
        ];
        $runtimeEntry['lookup'] = [
            'format' => $sidecar['format'],
            'path' => basename($lookup),
            'sha256' => $sidecar['sha256'],
            'blocks' => $sidecar['blocks'],
        ];
        $sidecarBytes += (int) filesize($lookup);
        $sidecarBlocks += $sidecar['blocks'];
        $runtimeEntries[] = $runtimeEntry;
        $runtimeCompressedBytes += (int) filesize($runtime);
    }

    $manifest = [
        'schema_version' => 1,
        'pack_id' => 'qaa-exact-eight-mib-indexed',
        'language' => 'qaa',
        'version' => '1',
        'capabilities' => ['dictionary-lemmatizer', 'normalized-runtime-rows'],
        'runtime' => [
            'format' => WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV,
            'normalization' => 'WP_FTS_Normalizer qaa with fold_diacritics=true',
            'ambiguity_policy' => 'ambiguous_surface_noop',
            'total_rows' => $rows,
            'total_sha256' => hash_final($rowDigest),
            'files' => $runtimeEntries,
        ],
        'source' => [
            'name' => 'Project-owned exact 8-MiB lemma-sidecar source',
            'version' => '1',
            'url' => 'urn:wp-fts:test:exact-eight-mib-sidecar',
            'artifact_sha256' => str_repeat('a', 64),
            'byte_count' => $decodedBytes,
        ],
        'license' => ['spdx_id' => 'CC0-1.0', 'notice_path' => 'NOTICE.txt'],
        'attribution' => ['note' => 'Project-owned generated fixture.'],
        'provenance' => ['no_runtime_network_access' => true],
    ];
    $manifestPath = $root . '/manifest.json';
    file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );

    $documentTerms = [];
    $documentIndexes = [];
    $rowsPerDecodedBlock = intdiv(WP_FTS_LemmaPackLookupIndex::MAX_BLOCK_DECODED_BYTES, $lineBytes);
    $documentBlocks = array_merge(range(0, 31), range(256, 287));
    for ($row = 0; $row < $rowsPerDecodedBlock; $row++) {
        foreach ($documentBlocks as $block) {
            $index = $block * $rowsPerDecodedBlock + $row;
            $documentIndexes[] = $index;
            $documentTerms[] = wp_fts_lemma_sidecar_surface($index);
        }
    }
    $queryIndexes = [];
    foreach (array_merge(range(4, 9), range(260, 265)) as $block) {
        $queryIndexes[] = $block * $rowsPerDecodedBlock;
    }
    $queryTerms = array_map('wp_fts_lemma_sidecar_surface', $queryIndexes);

    return [
        'manifest' => $manifestPath,
        'decoded_bytes' => $decodedBytes,
        'runtime_compressed_bytes' => $runtimeCompressedBytes,
        'rows' => $rows,
        'block_rows' => $blockRows,
        'decoded_block_rows' => $rowsPerDecodedBlock,
        'sidecar_bytes' => $sidecarBytes,
        'sidecar_blocks' => $sidecarBlocks,
        'document_terms' => $documentTerms,
        'document_indexes' => $documentIndexes,
        'query_terms' => $queryTerms,
        'query_indexes' => $queryIndexes,
    ];
}

/** Encode a stable sorted surface that routes predictably across shards. */
function wp_fts_lemma_sidecar_surface(int $index, string $language = 'qaa'): string
{
    return $language . str_pad((string) $index, 6, '0', STR_PAD_LEFT);
}

/** Fill one lemma to the maximum storable namespaced width. */
function wp_fts_lemma_sidecar_lemma(int $index, string $language = 'qaa'): string
{
    $surface = wp_fts_lemma_sidecar_surface($index, $language);
    $prefix = 'lemma' . $surface;

    return $prefix . str_repeat('l', 245 - strlen($prefix));
}

/** @param array<string,mixed> $fixture @return array<string,mixed> */
function wp_fts_lemma_sidecar_document_identity(array $fixture): array
{
    $document = implode(' ', $fixture['document_terms']);
    WP_FTS_Analysis_Limits::assert_source_bytes($document);
    foreach ($fixture['document_terms'] as $term) {
        WP_FTS_Analysis_Limits::assert_lexical_run_bytes(strlen($term));
    }

    return [
        'source_bytes' => strlen($document),
        'source_sha256' => hash('sha256', $document),
        'distinct_terms' => count(array_unique($fixture['document_terms'])),
        'limit' => WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_TERMS,
    ];
}

/** @param array<string,mixed> $fixture @return array<string,mixed> */
function wp_fts_lemma_sidecar_query_identity(array $fixture): array
{
    $query = implode(' ', $fixture['query_terms']);

    return [
        'source_bytes' => strlen($query),
        'source_sha256' => hash('sha256', $query),
        'terms' => count($fixture['query_terms']),
        'limit' => WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_GROUPS,
    ];
}

/** @param array<string,int> $before @param array<string,int> $after @return array<string,int> */
function wp_fts_lemma_sidecar_diagnostic_delta(array $before, array $after): array
{
    $delta = [];
    foreach (['runtime_file_opens', 'runtime_payload_reads', 'compressed_payload_bytes_read', 'decoded_payload_bytes_loaded', 'decoded_block_cache_hits'] as $key) {
        $delta[$key] = max(0, (int) ($after[$key] ?? 0) - (int) ($before[$key] ?? 0));
    }

    return $delta;
}

/** @param resource $handle */
function wp_fts_lemma_sidecar_write_all(mixed $handle, string $data): void
{
    $offset = 0;
    $length = strlen($data);
    while ($offset < $length) {
        $written = fwrite($handle, substr($data, $offset));
        if (!is_int($written) || $written < 1) {
            throw new RuntimeException('Could not write complete lemma-sidecar fixture data.');
        }
        $offset += $written;
    }
}

/** Stream one plain fixture shard into gzip without materializing it twice. */
function wp_fts_lemma_sidecar_gzip_file(string $source, string $destination): void
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
        throw new RuntimeException('Could not open lemma-sidecar gzip fixture streams.');
    }
    try {
        while (!feof($input)) {
            $chunk = fread($input, 65536);
            if (!is_string($chunk)) {
                throw new RuntimeException('Could not read lemma-sidecar fixture source.');
            }
            if ($chunk === '') {
                break;
            }
            if (gzwrite($output, $chunk) !== strlen($chunk)) {
                throw new RuntimeException('Could not write complete lemma-sidecar gzip fixture.');
            }
        }
    } finally {
        fclose($input);
        gzclose($output);
    }
}

/** Copy a generated pack so aggregate tests use distinct physical manifests. */
function wp_fts_lemma_sidecar_copy_tree(string $source, string $destination): void
{
    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $from = $source . '/' . $entry;
        $to = $destination . '/' . $entry;
        if (is_dir($from)) {
            if (!mkdir($to) && !is_dir($to)) {
                throw new RuntimeException('Could not copy configured-overflow directory.');
            }
            wp_fts_lemma_sidecar_copy_tree($from, $to);
            continue;
        }
        if (!copy($from, $to)) {
            throw new RuntimeException('Could not copy configured-overflow artifact.');
        }
    }
}

/** @return array{VmHWM_bytes:?int,VmRSS_bytes:?int} */
function wp_fts_lemma_sidecar_proc_status(): array
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
            $digits = $space === false ? '' : substr($value, 0, $space);
            if (
                $digits !== ''
                && strspn($digits, '0123456789') === strlen($digits)
                && strtolower(trim(substr($value, $space + 1))) === 'kb'
            ) {
                $values[$key] = (int) $digits * 1024;
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

/** Remove one generated pack tree after a fresh-process workload. */
function wp_fts_lemma_sidecar_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($child) ? wp_fts_lemma_sidecar_remove_tree($child) : unlink($child);
    }
    rmdir($path);
}
