<?php
declare(strict_types=1);

/**
 * Fast contracts for the real-database acceptance lane. These tests only keep
 * the destructive runner from silently shrinking or skipping; they are never a
 * substitute for running the Docker proof itself.
 */

function wp_fts_wc_contract_function_source(string $source, string $name): string
{
    foreach (wp_fts_php_source_function_stream($source) as $function) {
        if ($function['name'] === $name) {
            return $function['source'];
        }
    }

    throw new RuntimeException("Could not extract acceptance function {$name} through the PHP tokenizer.");
}

test_case('relational worst-case integration fails closed without its disposable environment', function (): void {
    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is required to exercise the integration entry point.');
    }

    $script = dirname(__DIR__) . '/integration/relational-fts-worst-case.php';
    $path = getenv('PATH');
    $path = is_string($path) ? $path : '/usr/local/bin:/usr/bin:/bin';
    $result = test_run_subprocess(
        ['env', '-i', 'PATH=' . $path, 'HOME=' . sys_get_temp_dir(), PHP_BINARY, $script],
        dirname(__DIR__, 2)
    );
    assert_true($result['exit'] !== 0, 'missing real WordPress environment must fail rather than pass or skip');
    assert_contains('FAIL:', $result['stdout'] . $result['stderr'], 'failure should be explicit');
    assert_true(!str_contains($result['stdout'] . $result['stderr'], 'SKIP:'), 'real acceptance entry point must not expose a successful skip path');
});

test_case('relational worst-case failed validation stops every later expensive phase', function (): void {
    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is required to exercise validation fail-fast ordering.');
    }

    $root = dirname(__DIR__, 2);
    $runner = (string) file_get_contents($root . '/tools/run-relational-fts-worst-case.sh');
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');

    $validateStart = strpos($integration, 'function wp_fts_wc_validate(): array');
    $validateEnd = strpos($integration, 'function wp_fts_wc_dependency_lob(): array', $validateStart === false ? 0 : $validateStart);
    assert_true(is_int($validateStart) && is_int($validateEnd), 'validation body should remain independently inspectable');
    $validateBody = substr($integration, $validateStart, $validateEnd - $validateStart);
    $evidenceWrite = strpos($validateBody, 'wp_fts_wc_write_json(wp_fts_wc_evidence_path(), $evidence);');
    $failedGuard = strpos($validateBody, "if (\$evidence['status'] !== 'PASS')");
    $failedThrow = strpos($validateBody, 'Validation evidence has failed gates:', $failedGuard === false ? 0 : $failedGuard);
    $successfulReturn = strpos($validateBody, "return ['status' => 'PASS'", $failedThrow === false ? 0 : $failedThrow);
    assert_true(
        is_int($evidenceWrite) && is_int($failedGuard) && is_int($failedThrow) && is_int($successfulReturn)
            && $evidenceWrite < $failedGuard
            && $failedGuard < $failedThrow
            && $failedThrow < $successfulReturn,
        'validation must atomically retain the preliminary report, then throw before its successful return'
    );

    $lines = file($root . '/tools/run-relational-fts-worst-case.sh', FILE_IGNORE_NEW_LINES);
    assert_true(is_array($lines), 'runner lines should be readable');
    $block = [];
    $inside = false;
    foreach ($lines as $line) {
        if ($line === 'set_run_stage "validation-and-boundaries"') {
            $inside = true;
        }
        if ($inside && $line === 'set_run_stage "cold-cache"') {
            break;
        }
        if ($inside) {
            $block[] = $line;
        }
    }
    assert_true($block !== [], 'runner validation block should remain independently executable');

    $temporary = sys_get_temp_dir() . '/wp-fts-validation-fail-fast-' . bin2hex(random_bytes(6));
    $evidence = $temporary . '/evidence';
    mkdir($evidence, 0777, true);
    $script = $temporary . '/proof.sh';
    $invocations = $temporary . '/invocations';
    $source = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
EVIDENCE_DIR="$1"
INVOCATIONS="$2"
PROFILE=2k
set_run_stage() { :; }
run_wpcli_php_phase() { printf '%s\n' "$1" >> "${INVOCATIONS}"; }
run_php_phase() {
    printf '%s\n' "$1" >> "${INVOCATIONS}"
    if [[ "$1" == validate ]]; then
        printf '{"schema":"relational-fts-evidence-v5","status":"FAIL","completed":false}\n' > "${EVIDENCE_DIR}/relational-fts-evidence.json"
        return 47
    fi
}
run_isolated_boundaries() { printf '%s\n' isolated-boundaries >> "${INVOCATIONS}"; }
kill_uncommitted_transaction() { printf '%s\n' transaction-crash >> "${INVOCATIONS}"; }
BASH;
    file_put_contents($script, $source . "\n" . implode("\n", $block) . "\n");
    chmod($script, 0700);

    try {
        $result = test_run_subprocess(['bash', $script, $evidence, $invocations], $root);
        assert_same(47, $result['exit'], 'the real validation block should preserve the failing phase exit');
        $actual = file($invocations, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        assert_same(
            ['wpcli-adapter', 'cold-ready-request', 'dependency-lob', 'validate'],
            is_array($actual) ? array_values($actual) : [],
            'writer, max-input, isolated, crash, cold, and concurrency commands must remain absent after validation failure'
        );
        $partial = json_decode((string) file_get_contents($evidence . '/relational-fts-evidence.json'), true, 512, JSON_THROW_ON_ERROR);
        assert_same(false, $partial['completed'] ?? null, 'failed validation should leave an explicitly incomplete preliminary artifact for runner cleanup');
    } finally {
        foreach (glob($evidence . '/*') ?: [] as $path) {
            unlink($path);
        }
        @unlink($invocations);
        @unlink($script);
        @rmdir($evidence);
        @rmdir($temporary);
    }
});

test_case('relational worst-case phase failures are terminal after preserving evidence', function (): void {
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $functions = [];
    foreach (wp_fts_php_source_function_stream($integration) as $function) {
        $functions[$function['name']] = $function['source'];
    }

    foreach ([
        'wp_fts_wc_wpcli_adapter' => "return ['status' => 'PASS'",
        'wp_fts_wc_validate' => "return ['status' => 'PASS'",
        'wp_fts_wc_reindex_drain' => "return ['status' => 'PASS'",
        'wp_fts_wc_dependency_lob' => "return ['status' => 'PASS'",
        'wp_fts_wc_max_valid_search' => 'return $evidence;',
        'wp_fts_wc_search_memory_sample' => "return ['status' => 'PASS'",
        'wp_fts_wc_cold_sample' => "return ['status' => 'PASS'",
        'wp_fts_wc_idle_http' => "return ['status' => 'PASS'",
        'wp_fts_wc_concurrent_writer' => "return ['status' => 'PASS'",
        'wp_fts_wc_scope_proof' => "return ['status' => 'PASS'",
        'wp_fts_wc_verify_transaction_crash' => "return ['status' => 'PASS'",
        'wp_fts_wc_cold_cleanup' => "return ['status' => 'PASS'",
        'wp_fts_wc_drain' => "return ['status' => 'PASS'",
        'wp_fts_wc_finalize' => "return ['status' => 'PASS'",
    ] as $functionName => $successReturn) {
        $functionBody = $functions[$functionName] ?? '';
        assert_true($functionBody !== '', "{$functionName} should remain inspectable through the PHP tokenizer");
        $write = strrpos($functionBody, 'wp_fts_wc_write_json(');
        $throw = is_int($write) ? strpos($functionBody, 'throw new RuntimeException', $write) : false;
        $success = is_int($throw) ? strpos($functionBody, $successReturn, $throw) : false;
        assert_true(
            is_int($write) && is_int($throw) && is_int($success) && $write < $throw && $throw < $success,
            "{$functionName} must preserve its terminal artifact, throw on failure, and only then expose a PASS return"
        );
    }

    $finalize = $functions['wp_fts_wc_finalize'] ?? '';
    $preliminaryHashRead = strpos($finalize, "\$recordedPreliminaryHash = \$evidence['evidence_sha256']");
    $preliminaryHashUnset = strpos($finalize, "unset(\$preliminaryHashInput['evidence_sha256'])", $preliminaryHashRead === false ? 0 : $preliminaryHashRead);
    $preliminaryHashCheck = strpos($finalize, 'Validation evidence self-hash is missing or invalid.', $preliminaryHashUnset === false ? 0 : $preliminaryHashUnset);
    $preliminaryInventoryCheck = strpos($finalize, 'wp_fts_wc_validation_inventory_matches($evidence)', $preliminaryHashCheck === false ? 0 : $preliminaryHashCheck);
    $preliminaryGateCheck = strpos($finalize, 'wp_fts_wc_require_evidence_gates(', $preliminaryInventoryCheck === false ? 0 : $preliminaryInventoryCheck);
    assert_true(
        is_int($preliminaryHashRead)
            && is_int($preliminaryHashUnset)
            && is_int($preliminaryHashCheck)
            && is_int($preliminaryInventoryCheck)
            && is_int($preliminaryGateCheck)
            && $preliminaryHashRead < $preliminaryHashUnset
            && $preliminaryHashUnset < $preliminaryHashCheck
            && $preliminaryHashCheck < $preliminaryInventoryCheck
            && $preliminaryInventoryCheck < $preliminaryGateCheck,
        'finalization must authenticate the complete preliminary report before consuming any of its PASS gates'
    );
});

test_case('relational worst-case preliminary inventory rejects self-rehashed evidence shrinkage', function (): void {
    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is required to execute the isolated inventory verifier.');
    }

    $root = dirname(__DIR__, 2);
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $functionNames = [
        'wp_fts_wc_validation_section_ids',
        'wp_fts_wc_validation_inventory_matches',
        'wp_fts_wc_canonical_hash',
        'wp_fts_wc_canonicalize',
    ];
    $extracted = '';
    foreach ($functionNames as $functionName) {
        $extracted .= wp_fts_wc_contract_function_source($integration, $functionName) . "\n";
    }
    $caseIds = [
        'common_or',
        'max_valid_or_prefix',
        'rare_anchor_and',
        'prefix_fanout',
        'surface_rarest_exact_anchor_and',
        'surface_dense_candidate_prefix_and',
        'selective_prefix_anchor_and',
        'hidden_dirty_head',
        'impossible_and',
        'all_packs',
        'ambiguous_morphology_or',
        'ambiguous_morphology_and',
        'field_impact',
    ];
    $criticalGateIds = [
        'runtime_performance_schema_integrity',
        'mutation_fence_real_database_artifact',
        'mutation_fence_real_database_cross_process_guard',
        'mutation_fence_real_database_owned_scope_delete',
        'mutation_fence_real_database_corpus_publication',
        'runtime_profile_full_parity',
        'actual_wpcli_query_count',
        'cold_ready_current_schema_option',
        'cold_ready_current_schema_requests',
        'cold_ready_request_autoloaded_options',
        'cold_ready_request_no_option_or_sitemeta_sql',
        'cold_ready_request_no_network_token_select',
        'cold_ready_request_zero_plugin_sql',
        'cold_ready_request_impossible_statement_shape',
        'cold_ready_request_nonhydrate_statement_shape',
        'cold_ready_request_hydrated_statement_shape',
        'common_or_query_count',
        'max_valid_or_prefix_query_count',
        'rare_anchor_and_query_count',
        'prefix_fanout_query_count',
        'surface_rarest_exact_anchor_and_query_count',
        'surface_dense_candidate_prefix_and_query_count',
        'selective_prefix_anchor_and_query_count',
        'hidden_dirty_head_query_count',
        'impossible_and_query_count',
        'all_packs_query_count',
        'ambiguous_morphology_or_query_count',
        'ambiguous_morphology_and_query_count',
        'field_impact_query_count',
        'one_pack_statement_count',
        'all_pack_statement_count',
        'public_common_or_oracle_order',
        'frontend_cache_prime_queries_independent_of_k',
        'frontend_author_prime_queries_independent_of_k',
        'frontend_wp_query_supported_version',
        'frontend_wp_query_cache_lifecycle_contract',
        'frontend_cache_flags_false_result_order',
        'frontend_cache_flags_false_plugin_statement_shape',
        'frontend_cache_flags_false_zero_prime_statements',
        'frontend_cache_flags_false_canonical_post_cache_hits',
        'frontend_cache_flags_false_post_cache_read_statements',
        'frontend_cache_results_false_result_order',
        'frontend_cache_results_false_plugin_statement_shape',
        'frontend_cache_results_false_zero_core_statements',
        'frontend_cache_results_false_zero_canonical_post_reads',
        'frontend_cache_results_false_post_cache_untouched',
        'frontend_cache_results_false_raw_post_objects',
        'frontend_cache_results_false_normalization_reads',
        'actual_rest_oracle_scores',
        'claim_index_options_preload_contract',
        'dependency_lob_actual_accepted_fixture_rows',
        'schema_exact_physical_contract',
        'surface_range_dictionary_terms',
        'surface_storage_per_document_surface_bound',
        'surface_storage_per_document_total_bound',
        'max_valid_or_prefix_surface_plan_aggregate_shape',
        'prefix_fanout_surface_plan_aggregate_shape',
        'surface_rarest_exact_anchor_and_surface_plan_aggregate_shape',
        'surface_dense_candidate_prefix_and_surface_plan_aggregate_shape',
        'selective_prefix_anchor_and_surface_plan_aggregate_shape',
        'impossible_and_surface_plan_aggregate_shape',
        'max_valid_or_prefix_surface_plan_rows_examined',
        'prefix_fanout_surface_plan_rows_examined',
        'surface_rarest_exact_anchor_and_surface_plan_rows_examined',
        'surface_dense_candidate_prefix_and_surface_plan_rows_examined',
        'selective_prefix_anchor_and_surface_plan_rows_examined',
        'impossible_and_surface_plan_rows_examined',
        'surface_rarest_exact_anchor_and_join_shape',
        'surface_rarest_exact_anchor_and_driver_cost',
        'surface_dense_candidate_prefix_and_join_shape',
        'surface_dense_candidate_prefix_and_driver_cost',
        'selective_prefix_anchor_and_join_shape',
        'selective_prefix_anchor_and_explain_bounded',
        'selective_prefix_anchor_and_rank_rows_examined',
        'selective_prefix_anchor_and_common_exact_not_materialized',
        'surface_dense_candidate_prefix_and_unrelated_posting_envelope',
        'indexing_rebuild_complete_signature_restored',
        'all_distributable_pack_identities_active',
        'failure_recovery_progress',
        'missing_table_all_adapters_same_pre_fault_state',
        'stage_failure_all_stages_same_pre_fault_state',
    ];
    foreach ([1, 20, 50] as $pageSize) {
        foreach ([
            'page_rows',
            'result_order',
            'plugin_statement_shape',
            'canonical_post_read_statements',
            'cache_prime_statement_count',
            'cache_prime_statement_shape',
            'pre_loop_statement_ceiling',
            'first_loop_author_statement_count',
            'first_loop_author_statement_shape',
            'total_through_first_loop_ceiling',
            'remaining_result_loop_statements',
            'canonical_post_cache_hits',
            'metadata_reads',
            'category_hits',
            'author_hits',
        ] as $suffix) {
            $criticalGateIds[] = "frontend_cache_k{$pageSize}_{$suffix}";
        }
    }
    foreach (['rest', 'frontend', 'admin_posts', 'sandbox', 'sandbox_ajax'] as $adapterId) {
        foreach ([
            'http_status',
            'result_count',
            'result_unique_count',
            'result_order',
            'query_count',
            'statement_shape',
            'connection_attribution',
            'all_table_sql_attributed',
            'exact_plugin_table_statement_set',
            'exact_plugin_statement_set',
            'no_plugin_option_or_sitemeta_sql',
        ] as $suffix) {
            $criticalGateIds[] = "actual_{$adapterId}_{$suffix}";
        }
        foreach ([
            'http_status',
            'fails_closed',
            'core_like_queries',
            'failed_plan_statement_shape',
            'failed_plan_plugin_attribution',
            'failure_control_statement_set',
            'total_plugin_statement_ceiling',
            'post_fault_state_latched',
            'isolated_state_restored',
            'connection_attribution',
            'all_table_sql_attributed',
        ] as $suffix) {
            $criticalGateIds[] = "missing_table_{$adapterId}_{$suffix}";
        }
    }
    foreach (['plan', 'rank', 'hydrate'] as $failureStage) {
        foreach ([
            'http_status',
            'fails_closed',
            'ordered_search_shape',
            'one_shot_real_database_failure',
            'no_later_search_or_core_like',
            'failure_control_statement_set',
            'total_plugin_statement_ceiling',
            'post_fault_state_latched',
            'isolated_state_restored',
            'connection_attribution',
            'all_table_sql_attributed',
        ] as $suffix) {
            $criticalGateIds[] = "stage_failure_{$failureStage}_{$suffix}";
        }
    }
    foreach ($caseIds as $caseId) {
        foreach ([
            'p95_ms',
            'p99_ms',
            'all_warm_sample_contracts',
            'all_warm_result_hashes_stable',
            'instrumented_result_hash',
            'total_raw_statement_parity',
            'sql_bytes',
            'memory_peak_delta',
            'diagnostic_rss_peak_increment',
            'rss_peak',
            'rows_examined',
        ] as $suffix) {
            $criticalGateIds[] = "{$caseId}_{$suffix}";
        }
    }
    $probe = "<?php\nfunction wp_fts_wc_gate_inventory_fingerprint_matches(mixed \$gateIds, string \$phase): bool { return \$phase === 'preliminary'; }\n" . $extracted
        . '$caseIds = ' . var_export($caseIds, true) . ";\n"
        . '$gateIds = ' . var_export($criticalGateIds, true) . ";\n"
        . <<<'PHP'
$evidence = [
    'schema' => 'relational-fts-evidence-v5',
    'status' => 'PASS',
    'source_sha' => str_repeat('a', 40),
    'zip_sha256' => str_repeat('b', 64),
    'source_dirty' => false,
    'acceptance_lane' => true,
    'lane_id' => 'mysql80-50k',
    'completed' => false,
    'engine' => 'mysql-8.0',
];
foreach (wp_fts_wc_validation_section_ids() as $section) {
    $evidence[$section] = [];
}
$evidence['cases'] = array_fill_keys($caseIds, []);
$evidence['validation_inventory'] = [
    'schema' => 'relational-fts-validation-inventory-v1',
    'section_ids' => wp_fts_wc_validation_section_ids(),
    'case_ids' => $caseIds,
    'gate_ids' => $gateIds,
    'gate_count' => count($gateIds),
    'gate_ids_sha256' => wp_fts_wc_canonical_hash($gateIds),
];
$evidence['gates'] = array_map(static fn(string $id): array => ['id' => $id], $gateIds);
$evidence['concurrency'] = null;
$evidence['evidence_sha256'] = wp_fts_wc_canonical_hash($evidence);

$criticalGateRemoved = [];
$criticalGateAndInventoryRewritten = [];
foreach ($gateIds as $criticalGateId) {
    $gateRemoved = $evidence;
    $gateRemoved['gates'] = array_values(array_filter(
        $gateRemoved['gates'],
        static fn(array $gate): bool => ($gate['id'] ?? null) !== $criticalGateId
    ));
    unset($gateRemoved['evidence_sha256']);
    $gateRemoved['evidence_sha256'] = wp_fts_wc_canonical_hash($gateRemoved);
    $criticalGateRemoved[$criticalGateId] = wp_fts_wc_validation_inventory_matches($gateRemoved);

    $gateInventoryRewritten = $gateRemoved;
    $rewrittenIds = array_column($gateInventoryRewritten['gates'], 'id');
    $gateInventoryRewritten['validation_inventory']['gate_ids'] = $rewrittenIds;
    $gateInventoryRewritten['validation_inventory']['gate_count'] = count($rewrittenIds);
    $gateInventoryRewritten['validation_inventory']['gate_ids_sha256'] = wp_fts_wc_canonical_hash($rewrittenIds);
    unset($gateInventoryRewritten['evidence_sha256']);
    $gateInventoryRewritten['evidence_sha256'] = wp_fts_wc_canonical_hash($gateInventoryRewritten);
    $criticalGateAndInventoryRewritten[$criticalGateId] = wp_fts_wc_validation_inventory_matches($gateInventoryRewritten);
}

$sectionRemoved = $evidence;
unset($sectionRemoved[wp_fts_wc_validation_section_ids()[0]], $sectionRemoved['evidence_sha256']);
$sectionRemoved['evidence_sha256'] = wp_fts_wc_canonical_hash($sectionRemoved);

$caseRemoved = $evidence;
array_pop($caseRemoved['cases']);
unset($caseRemoved['evidence_sha256']);
$caseRemoved['evidence_sha256'] = wp_fts_wc_canonical_hash($caseRemoved);

echo json_encode([
    'valid' => wp_fts_wc_validation_inventory_matches($evidence),
    'critical_gate_removed' => $criticalGateRemoved,
    'critical_gate_and_inventory_rewritten' => $criticalGateAndInventoryRewritten,
    'section_removed' => wp_fts_wc_validation_inventory_matches($sectionRemoved),
    'case_removed' => wp_fts_wc_validation_inventory_matches($caseRemoved),
], JSON_THROW_ON_ERROR);
PHP;

    $temporary = tempnam(sys_get_temp_dir(), 'wp-fts-inventory-probe-');
    assert_true(is_string($temporary), 'inventory verifier probe should have a temporary path');
    file_put_contents($temporary, $probe);
    try {
        $result = test_run_subprocess([PHP_BINARY, $temporary], $root);
        assert_same(0, $result['exit'], 'isolated inventory verifier should execute');
        $outcomes = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        $allCriticalGatesRejected = array_fill_keys($criticalGateIds, false);
        assert_same([
            'valid' => true,
            'critical_gate_removed' => $allCriticalGatesRejected,
            'critical_gate_and_inventory_rewritten' => $allCriticalGatesRejected,
            'section_removed' => false,
            'case_removed' => false,
        ], $outcomes, 'recomputing the evidence self-hash must not make a shrunken gate, section, or case inventory acceptable');
    } finally {
        @unlink($temporary);
    }
});

test_case('relational worst-case gate fingerprints reject ordered inventory drift', function (): void {
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $expectedSource = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_expected_gate_inventory_fingerprints');
    foreach ([
        "'count' => 1170",
        "'sha256' => '0419c613811a13d49755af028011543c41d31a8f0bbd4a2acf65e03f1c5a4e25'",
        "'count' => 2647",
        "'sha256' => 'ca53417ea9628ff92fc9087c5097cdc0928a3d409b50b222a2671a2c4f989492'",
    ] as $required) {
        assert_contains($required, $expectedSource, "reviewed gate fingerprint must retain {$required}");
    }

    $preliminary = ['alpha', 'beta', 'gamma'];
    $final = ['alpha', 'beta', 'gamma', 'delta', 'epsilon'];
    $canonicalHash = static fn(array $ids): string => hash('sha256', json_encode($ids, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    $matcherSource = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_gate_inventory_fingerprint_matches');
    $matcherSource = str_replace(
        ['wp_fts_wc_gate_inventory_fingerprint_matches', 'wp_fts_wc_expected_gate_inventory_fingerprints', 'wp_fts_wc_canonical_hash'],
        ['wp_fts_wc_test_gate_inventory_fingerprint_matches', 'wp_fts_wc_test_expected_gate_inventory_fingerprints', 'wp_fts_wc_test_canonical_hash'],
        $matcherSource
    );
    eval('function wp_fts_wc_test_expected_gate_inventory_fingerprints(): array { return ' . var_export([
        'preliminary' => ['count' => count($preliminary), 'sha256' => $canonicalHash($preliminary)],
        'final' => ['count' => count($final), 'sha256' => $canonicalHash($final)],
    ], true) . '; }');
    eval('function wp_fts_wc_test_canonical_hash(mixed $value): string { return hash("sha256", json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)); }');
    eval($matcherSource);

    assert_true(wp_fts_wc_test_gate_inventory_fingerprint_matches($preliminary, 'preliminary'), 'exact preliminary ordered inventory should pass');
    assert_true(wp_fts_wc_test_gate_inventory_fingerprint_matches($final, 'final'), 'exact final ordered inventory should pass');
    $mutations = [
        'deleted preliminary ID' => [array_slice($preliminary, 0, -1), 'preliminary'],
        'added preliminary ID' => [[...$preliminary, 'extra'], 'preliminary'],
        'reordered preliminary IDs' => [['beta', 'alpha', 'gamma'], 'preliminary'],
        'duplicate preliminary ID' => [['alpha', 'alpha', 'gamma'], 'preliminary'],
        'deleted final-only ID' => [array_slice($final, 0, -1), 'final'],
        'reordered final-only IDs' => [['alpha', 'beta', 'gamma', 'epsilon', 'delta'], 'final'],
        'unknown phase' => [$preliminary, 'unknown'],
        'associative list' => [['first' => 'alpha', 'second' => 'beta', 'third' => 'gamma'], 'preliminary'],
        'blank ID' => [['alpha', '', 'gamma'], 'preliminary'],
    ];
    foreach ($mutations as $description => [$ids, $phase]) {
        assert_true(!wp_fts_wc_test_gate_inventory_fingerprint_matches($ids, $phase), "gate fingerprint must reject {$description}");
    }
});

test_case('relational worst-case canonical hashes stream ordered JSON', function (): void {
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    foreach (['wp_fts_wc_canonicalize', 'wp_fts_wc_canonical_hash'] as $functionName) {
        if (!function_exists($functionName)) {
            eval(wp_fts_wc_contract_function_source($integration, $functionName));
        }
    }

    $values = [
        null,
        true,
        7,
        1.0,
        'slash/unicode-Łódź',
        ['list', 2, ['z' => 1, 'a' => 2]],
        ['10' => 'ten', '2' => 'two', 'nested' => ['b' => false, 'a' => null]],
        (object) ['second' => 2, 'first' => 1],
    ];
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
    foreach ($values as $value) {
        $expected = hash('sha256', json_encode(wp_fts_wc_canonicalize($value), $flags));
        assert_same($expected, wp_fts_wc_canonical_hash($value), 'streamed canonical JSON must retain the established digest');
    }

    $hashSource = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_canonical_hash');
    assert_contains("hash_init('sha256')", $hashSource, 'canonical hashing should stream into one hash context');
    assert_true(!str_contains($hashSource, 'json_encode(wp_fts_wc_canonicalize($value)'), 'canonical hashing must not materialize a sorted copy of the complete report');
});

test_case('relational worst-case report writer streams atomic JSON', function (): void {
    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is required to run the constrained report writer.');
    }

    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $writerSource = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_write_json');
    assert_true(!str_contains($writerSource, '$json = json_encode($value'), 'the report writer must not retain one complete encoded copy');
    if (!function_exists('wp_fts_wc_write_json')) {
        eval($writerSource);
    }

    $temporary = sys_get_temp_dir() . '/wp-fts-streamed-report-' . bin2hex(random_bytes(6));
    mkdir($temporary, 0777, true);
    $path = $temporary . '/mixed.json';
    $fixture = [
        'slash' => 'path/Łódź',
        'float' => 1.0,
        'list' => [true, null, ['nested' => 'value']],
        'map' => [2 => 'two', 0 => 'zero'],
        'object' => (object) ['second' => 2, 'first' => 1],
        'empty_object' => (object) [],
    ];
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    try {
        wp_fts_wc_write_json($path, $fixture);
        assert_same(json_encode($fixture, $flags) . "\n", file_get_contents($path), 'streaming must preserve the established pretty JSON bytes');

        $script = $temporary . '/bounded.php';
        $large = $temporary . '/large.json';
        $program = "<?php\ndeclare(strict_types=1);\n" . $writerSource . <<<'PHP'

$chunk = str_repeat('x', 1048576);
$value = array_fill(0, 20, $chunk);
wp_fts_wc_write_json($argv[1], $value);
echo filesize($argv[1]), "\n";
PHP;
        file_put_contents($script, $program);
        $result = test_run_subprocess([PHP_BINARY, '-d', 'memory_limit=16M', $script, $large], dirname(__DIR__, 2));
        assert_same(0, $result['exit'], 'a report larger than the PHP limit must stream successfully');
        assert_true((int) trim($result['stdout']) > 20 * 1048576, 'the constrained child must publish the complete large report');
    } finally {
        foreach (glob($temporary . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($temporary);
    }
});

test_case('relational worst-case worker probes follow current SQL contracts', function (): void {
    $root = dirname(__DIR__, 2);
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');
    foreach (['wp_fts_wc_sql_token_stream', 'wp_fts_wc_queue_claim_statement_kinds', 'wp_fts_wc_scope_index_probe_role'] as $functionName) {
        if (!function_exists($functionName)) {
            eval(wp_fts_wc_contract_function_source($integration, $functionName));
        }
    }

    $currentClaim = "UPDATE /* wp_fts:claim-batch */ (SELECT job_key FROM wp_fts_work WHERE kind = 'scope' UNION ALL SELECT job_key FROM wp_fts_work WHERE kind = 'post') claim_driver STRAIGHT_JOIN wp_fts_work claim_target ON claim_target.job_key=claim_driver.job_key SET claim_target.state = 'leased'";
    assert_same(['scope', 'post'], wp_fts_wc_queue_claim_statement_kinds($currentClaim), 'the aliased current claim should expose both bounded work kinds');
    assert_same(['scope'], wp_fts_wc_queue_claim_statement_kinds("UPDATE /* wp_fts:claim-batch */ wp_fts_work SET state='leased' WHERE kind='scope'"), 'a scope-only current claim should expose only scope work');
    assert_same([], wp_fts_wc_queue_claim_statement_kinds("UPDATE wp_fts_work SET state='leased' WHERE kind='post'"), 'an untagged update must not masquerade as the production claim');

    $hadWpdb = array_key_exists('wpdb', $GLOBALS);
    $oldWpdb = $GLOBALS['wpdb'] ?? null;
    $GLOBALS['wpdb'] = (object) [
        'term_relationships' => 'wp_term_relationships',
        'posts' => 'wp_posts',
    ];
    try {
        assert_same('targeted_scope_index_probe', wp_fts_wc_scope_index_probe_role("SHOW INDEX FROM `wp_term_relationships` WHERE Key_name = 'wp_fts_term_object'"), 'the exact targeted-scope metadata read should retain its worker role');
        assert_same('filtered_scope_index_probe', wp_fts_wc_scope_index_probe_role("SHOW INDEX FROM `wp_posts` WHERE Key_name = 'wp_fts_type_status_id'"), 'the exact filtered-scope metadata read should retain its worker role');
        assert_same(null, wp_fts_wc_scope_index_probe_role("SHOW INDEX FROM `wp_posts` WHERE Key_name = 'wrong'"), 'a different index metadata read must not inherit the bounded scope role');
    } finally {
        if ($hadWpdb) {
            $GLOBALS['wpdb'] = $oldWpdb;
        } else {
            unset($GLOBALS['wpdb']);
        }
    }

    foreach ([
        'wp_fts_wc_scope_index_probe_role',
        'targeted_scope_index_probe',
        'filtered_scope_index_probe',
        "'scope_index_probe_statement_count' => \$scopeIndexProbeCount",
        "'max_scope_index_probe_statement_count'",
        "'max_unexpected_physical_schema_statement_count'",
        "['scope_index_probe_max' => 1, 'unexpected_max' => 0]",
        "wp_fts_wc_gate('concurrent_p95_degradation', '<= 16'",
        "wp_fts_wc_gate('worker_rss_peak', '<= 167772160'",
    ] as $required) {
        assert_contains($required, $integration, "current worker proof must retain {$required}");
    }
    foreach ([
        'concurrent p95 degradation | <=16× idle HTTP',
        'terminal corpus-completion controls',
        'long-lived final drain process',
        'exactly 1 named-index metadata read / 0',
    ] as $required) {
        assert_contains($required, $acceptance, "acceptance should retain {$required}");
    }
});

test_case('relational worst-case authoritative search memory is fresh, source-bound, and shrink-proof', function (): void {
    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is required to execute the isolated search-memory inventory verifier.');
    }

    $root = dirname(__DIR__, 2);
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $runner = (string) file_get_contents($root . '/tools/run-relational-fts-worst-case.sh');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');
    $caseIds = [
        'common_or',
        'max_valid_or_prefix',
        'rare_anchor_and',
        'prefix_fanout',
        'surface_rarest_exact_anchor_and',
        'surface_dense_candidate_prefix_and',
        'selective_prefix_anchor_and',
        'hidden_dirty_head',
        'impossible_and',
        'all_packs',
        'ambiguous_morphology_or',
        'ambiguous_morphology_and',
        'field_impact',
    ];
    $runnerInventoryStart = strpos($runner, 'SEARCH_MEMORY_CASES=(');
    $runnerInventoryEnd = strpos($runner, ')', $runnerInventoryStart === false ? 0 : $runnerInventoryStart);
    assert_true(is_int($runnerInventoryStart) && is_int($runnerInventoryEnd), 'runner must expose one exact authoritative search-memory case inventory');
    $runnerInventory = substr($runner, $runnerInventoryStart, $runnerInventoryEnd - $runnerInventoryStart);
    foreach ($caseIds as $caseId) {
        assert_same(1, substr_count($runnerInventory, "\n    {$caseId}\n"), "runner must execute authoritative search-memory case exactly once: {$caseId}");
    }
    assert_same(13, substr_count($runnerInventory, "\n    "), 'runner authoritative search-memory inventory must contain exactly thirteen entries');
    foreach ([
        'run_php_phase search-memory-sample',
        'relational-fts-search-memory-sample-v1',
        'relational-fts-authoritative-search-memory-v1',
        'fresh_process_conservative_peak_attribution',
        "max(0, \$rssPeakAfter - \$rssBefore)",
        "'rss_peak_delta_authoritative' => true",
        "'php_peak_delta_authoritative' => true",
        'php_lifetime_peak_before_reset_bytes',
        'php_phase_peak_after_reset_bytes',
        'wp_fts_wc_reset_php_peak_usage',
        'Authoritative PHP peak attribution requires memory_reset_peak_usage().',
        'authoritative_search_memory_artifact_inventory',
        'authoritative_search_memory_distinct_fresh_processes',
        'authoritative_search_memory_artifact_self_hashes',
        'authoritative_search_memory_source_bindings',
        'authoritative_search_memory_measurement_inventory',
        'cold_all_sample_artifact_inventory',
        'cold_all_sample_distinct_fresh_processes',
        'max_valid_frontend_authoritative_memory_binding',
        'conservative_vmhwm_after_minus_vmrss_before',
        'reused_warm_process_diagnostic',
        "'rss_peak_delta_authoritative' => false",
    ] as $required) {
        assert_contains($required, $integration . "\n" . $runner, "authoritative search-memory proof must retain: {$required}");
    }
    assert_true(!str_contains($integration, '$rssPeakAfter - $rssPeakBefore'), 'VmHWM-to-VmHWM subtraction must never return as authoritative acceptance evidence');
    assert_same(1, substr_count($integration, '$rssPeakAfter - max($rssBefore, $rssPeakBefore)'), 'the sole old-high-water subtraction must remain explicitly diagnostic in the reused warm loop');
    assert_true(!str_contains($integration, "if (function_exists('memory_reset_peak_usage'))"), 'real-DB evidence must not silently downgrade resettable PHP peak attribution');
    foreach ([
        'thirteen distinct process identities',
        '`VmHWM` after minus `VmRSS` before—never',
        'exact forty-file and forty-process inventory',
        'cumulative diagnostics',
        'lifetime peak captured before reset',
        'authenticates the large preliminary report only after',
    ] as $required) {
        assert_contains($required, $acceptance, "acceptance must document authoritative search-memory invariant: {$required}");
    }
    foreach (['wp_fts_wc_search_memory_sample', 'wp_fts_wc_cold_sample'] as $functionName) {
        $measurement = wp_fts_wc_contract_function_source($integration, $functionName);
        $peakRead = strpos($measurement, '$rssPeakAfter = wp_fts_wc_rss_bytes(\'VmHWM\');');
        $reportRead = strpos($measurement, '$preliminary = wp_fts_wc_preliminary_report_binding();');
        assert_true(is_int($peakRead) && is_int($reportRead) && $peakRead < $reportRead, "{$functionName} must freeze the production RSS peak before authenticating the large report");
    }

    $extracted = '';
    foreach ([
        'wp_fts_wc_authoritative_search_memory_case_ids',
        'wp_fts_wc_authoritative_search_memory_gate_ids',
        'wp_fts_wc_authoritative_search_memory_inventory_matches',
        'wp_fts_wc_gates_pass',
        'wp_fts_wc_is_ascii_hex',
        'wp_fts_wc_is_lowercase_sha256',
        'wp_fts_wc_canonical_hash',
        'wp_fts_wc_canonicalize',
    ] as $functionName) {
        $extracted .= wp_fts_wc_contract_function_source($integration, $functionName) . "\n";
    }
    $probe = "<?php\n" . $extracted . <<<'PHP'
$caseIds = wp_fts_wc_authoritative_search_memory_case_ids();
$files = array_map(static fn(string $caseId): string => "search-memory-{$caseId}.json", $caseIds);
sort($files, SORT_STRING);
$gateIds = [
    'authoritative_search_memory_artifact_inventory',
    'authoritative_search_memory_case_inventory',
    'authoritative_search_memory_distinct_fresh_processes',
    'authoritative_search_memory_artifact_self_hashes',
    'authoritative_search_memory_source_bindings',
    'authoritative_search_memory_measurement_inventory',
];
foreach ($caseIds as $caseId) {
    array_push($gateIds, ...wp_fts_wc_authoritative_search_memory_gate_ids($caseId));
}
$evidence = [
    'schema' => 'relational-fts-authoritative-search-memory-v1',
    'status' => 'PASS',
    'case_ids' => $caseIds,
    'artifact_files' => $files,
    'artifact_sha256' => array_fill_keys($files, str_repeat('a', 64)),
    'process_identities' => array_combine($caseIds, array_map(static fn(int $index): string => hash('sha256', "process-{$index}"), array_keys($caseIds))),
    'cases' => array_fill_keys($caseIds, ['status' => 'PASS']),
    'gates' => array_map(static fn(string $id): array => ['id' => $id, 'expected' => true, 'actual' => true, 'passed' => true], $gateIds),
];
$evidence['evidence_sha256'] = wp_fts_wc_canonical_hash($evidence);
$rehash = static function (array $value): array {
    unset($value['evidence_sha256']);
    $value['evidence_sha256'] = wp_fts_wc_canonical_hash($value);
    return $value;
};
$caseRemoved = $evidence;
array_pop($caseRemoved['cases']);
$fileRemoved = $evidence;
array_pop($fileRemoved['artifact_files']);
$hashRemoved = $evidence;
array_pop($hashRemoved['artifact_sha256']);
$gateRemoved = $evidence;
array_pop($gateRemoved['gates']);
$processReused = $evidence;
$processReused['process_identities'][$caseIds[1]] = $processReused['process_identities'][$caseIds[0]];
echo json_encode([
    'valid' => wp_fts_wc_authoritative_search_memory_inventory_matches($evidence),
    'case_removed' => wp_fts_wc_authoritative_search_memory_inventory_matches($rehash($caseRemoved)),
    'file_removed' => wp_fts_wc_authoritative_search_memory_inventory_matches($rehash($fileRemoved)),
    'hash_removed' => wp_fts_wc_authoritative_search_memory_inventory_matches($rehash($hashRemoved)),
    'gate_removed' => wp_fts_wc_authoritative_search_memory_inventory_matches($rehash($gateRemoved)),
    'process_reused' => wp_fts_wc_authoritative_search_memory_inventory_matches($rehash($processReused)),
], JSON_THROW_ON_ERROR);
PHP;
    $temporary = tempnam(sys_get_temp_dir(), 'wp-fts-search-memory-inventory-');
    assert_true(is_string($temporary), 'search-memory inventory probe should have a temporary path');
    file_put_contents($temporary, $probe);
    try {
        $result = test_run_subprocess([PHP_BINARY, $temporary], $root);
        assert_same(0, $result['exit'], 'isolated search-memory inventory verifier should execute');
        assert_same([
            'valid' => true,
            'case_removed' => false,
            'file_removed' => false,
            'hash_removed' => false,
            'gate_removed' => false,
            'process_reused' => false,
        ], json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR), 'self-rehashing must not hide any shrunken or process-reused authoritative memory evidence');
    } finally {
        @unlink($temporary);
    }
});

test_case('relational worst-case cross-process guard rejects every removed proof field', function (): void {
    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is required to exercise the isolated cross-process validator.');
    }

    $root = dirname(__DIR__, 2);
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $extracted = '';
    foreach ([
        'wp_fts_wc_is_ascii_hex',
        'wp_fts_wc_is_lowercase_sha256',
        'wp_fts_wc_mutation_cross_process_guard_valid',
        'wp_fts_wc_mutation_guard_operation_valid',
    ] as $functionName) {
        $extracted .= wp_fts_wc_contract_function_source($integration, $functionName) . "\n";
    }
    $probe = "<?php\ndeclare(strict_types=1);\n" . $extracted . <<<'PHP'

function operation(int $count, int $bound, array $keys, array $methods, string $marker): array
{
    $statements = [];
    foreach ($methods as $index => $method) {
        $plan = [];
        foreach ($keys as $key) {
            $plan[] = [
                'select_type' => 'SIMPLE',
                'table' => $key === 'PRIMARY' ? 'claim_target' : 'wp_probe_fts_work',
                'access_type' => $key === 'PRIMARY' ? 'EQ_REF' : 'RANGE',
                'possible_keys' => implode(',', $keys),
                'key' => $key,
                'key_len' => '8',
                'ref' => null,
                'rows' => 1,
                'filtered' => 100.0,
                'extra' => '',
            ];
        }
        $redacted = ($index === 0 ? "/* {$marker} */ " : '') . 'SELECT ?';
        $statements[] = [
            'method' => $method,
            'elapsed_ms' => 0.25,
            'affected_rows' => 0,
            'sql_bytes' => 32 + $index,
            'sql_sha256' => hash('sha256', "sql-{$bound}-{$index}"),
            'redacted_sql' => $redacted,
            'redacted_sql_bytes' => strlen($redacted),
            'redacted_sql_truncated' => false,
            'plan_row_count' => count($plan),
            'plan_keys' => $keys,
            'plan_max_estimated_rows' => 1,
            'base_full_scan_count' => 0,
            'plan' => $plan,
        ];
    }
    $shape = array_map(static fn(array $statement): array => [
        'method' => $statement['method'],
        'sql_sha256' => $statement['sql_sha256'],
        'sql_bytes' => $statement['sql_bytes'],
        'plan_row_count' => $statement['plan_row_count'],
    ], $statements);

    return [
        'statement_count' => $count,
        'candidate_row_upper_bound' => $bound,
        'base_table' => 'wp_probe_fts_work',
        'required_plan_keys' => $keys,
        'observed_plan_keys' => $keys,
        'base_full_scan_count' => 0,
        'statement_shape_sha256' => hash('sha256', json_encode($shape, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'statements' => $statements,
    ];
}

$states = [
    ['post_id' => 45, 'generation' => 1, 'state' => 'guarded', 'claimed_generation' => 1, 'token_class' => 'guard'],
    ['post_id' => 46, 'generation' => 1, 'state' => 'fenced', 'claimed_generation' => 1, 'token_class' => 'unmarked'],
    ['post_id' => 47, 'generation' => 1, 'state' => 'ready', 'claimed_generation' => 0, 'token_class' => 'empty'],
];
$proofSha = hash('sha256', 'proof');
$proof = [
    'schema' => 'relational-fts-cross-process-owner-guard-v1',
    'status' => 'PASS',
    'parent_pid' => 100,
    'holder_pid' => 101,
    'holder_runtime_pid' => 101,
    'process_is_independent' => true,
    'holder_queue_path' => '/proof/IndexQueue.php',
    'holder_queue_sha256' => hash('sha256', 'queue'),
    'parent_queue_sha256' => hash('sha256', 'queue'),
    'holder_proof_sha256' => $proofSha,
    'holder_lock_path' => '/tmp/owner.lock',
    'parent_lock_path' => '/tmp/owner.lock',
    'holder_lock_device' => 1,
    'parent_lock_device' => 1,
    'holder_lock_inode' => 2,
    'parent_lock_inode' => 2,
    'same_lock_file' => true,
    'parent_static_guard_count' => 0,
    'seeded_post_ids' => [45, 46, 47],
    'seeded_states' => $states,
    'optimizer_noise_rows' => 512,
    'optimizer_noise_due_at' => 9000,
    'busy_probe_state' => 'busy',
    'busy_claimed_post_ids' => [47],
    'busy_protected_states' => array_slice($states, 0, 2),
    'busy_claim_guarded_predicate_count' => 0,
    'busy_claim_fenced_predicate_count' => 0,
    'busy_claim' => operation(2, 400, ['ready', 'recoverable', 'PRIMARY', 'claim_token'], ['query', 'get_results'], 'wp_fts:fences-require-free-guard'),
    'busy_schedule_probe_state' => 'busy',
    'busy_next_available' => 1000,
    'busy_watchdog_min' => 1000,
    'busy_watchdog_max' => 1001,
    'busy_schedule_guarded_predicate_count' => 2,
    'busy_schedule_fenced_predicate_count' => 2,
    'busy_schedule' => operation(1, 12, ['ready', 'recoverable'], ['get_var'], 'wp_fts:nonfree-fence-watchdog'),
    'holder_alive_after_busy_queries' => true,
    'kill_signal_requested' => 9,
    'kill_observed' => true,
    'kill_observed_signal' => 9,
    'process_reaped' => true,
    'free_probe_state' => 'free',
    'free_claimed_post_ids' => [45],
    'free_claim_guarded_predicate_count' => 3,
    'free_claim_fenced_predicate_count' => 0,
    'free_claim' => operation(2, 500, ['ready', 'recoverable', 'PRIMARY', 'claim_token'], ['query', 'get_results'], 'wp_fts:only-guarded-fence-recovery'),
    'fenced_state_after_free_claim' => [$states[1]],
    'free_schedule_probe_state' => 'free',
    'free_next_available' => 9000,
    'free_schedule_guarded_predicate_count' => 2,
    'free_schedule_fenced_predicate_count' => 0,
    'free_schedule' => operation(1, 10, ['ready', 'recoverable'], ['get_var'], 'wp_fts:only-guarded-fence-recovery'),
    'promoted_fenced_claimed_post_ids' => [46],
    'promoted_fenced_claim' => operation(2, 500, ['ready', 'recoverable', 'PRIMARY', 'claim_token'], ['query', 'get_results'], 'wp_fts:only-guarded-fence-recovery'),
];
$mutation = ['proof_sha256' => $proofSha, 'cross_process_foreground_guard' => $proof];

function paths(array $value, array $prefix = []): array
{
    $paths = [];
    foreach ($value as $key => $child) {
        $path = [...$prefix, $key];
        $paths[] = $path;
        if (is_array($child) && $child !== []) {
            array_push($paths, ...paths($child, $path));
        }
    }
    return $paths;
}

function without_path(array $value, array $path): array
{
    $cursor =& $value;
    $last = array_pop($path);
    foreach ($path as $key) {
        $cursor =& $cursor[$key];
    }
    unset($cursor[$last]);
    return $value;
}

$removalOutcomes = [];
foreach (paths($mutation) as $path) {
    $removalOutcomes[implode('.', $path)] = wp_fts_wc_mutation_cross_process_guard_valid(without_path($mutation, $path));
}
$semanticMutations = [];
foreach ([
    'same_process' => static function (array $value): array {
        $value['cross_process_foreground_guard']['holder_pid'] = 100;
        $value['cross_process_foreground_guard']['holder_runtime_pid'] = 100;
        return $value;
    },
    'busy_claims_guarded' => static function (array $value): array {
        $value['cross_process_foreground_guard']['busy_claimed_post_ids'] = [45, 47];
        return $value;
    },
    'holder_not_killed' => static function (array $value): array {
        $value['cross_process_foreground_guard']['kill_observed'] = false;
        return $value;
    },
    'base_table_scan' => static function (array $value): array {
        $value['cross_process_foreground_guard']['free_claim']['statements'][0]['plan'][0]['table'] = 'wp_probe_fts_work';
        $value['cross_process_foreground_guard']['free_claim']['statements'][0]['plan'][0]['access_type'] = 'ALL';
        return $value;
    },
] as $name => $mutate) {
    $semanticMutations[$name] = wp_fts_wc_mutation_cross_process_guard_valid($mutate($mutation));
}

echo json_encode([
    'valid' => wp_fts_wc_mutation_cross_process_guard_valid($mutation),
    'removal_count' => count($removalOutcomes),
    'all_removals_rejected' => !in_array(true, $removalOutcomes, true),
    'removal_failures' => array_keys(array_filter($removalOutcomes)),
    'semantic_mutations' => $semanticMutations,
], JSON_THROW_ON_ERROR);
PHP;

    $temporary = tempnam(sys_get_temp_dir(), 'wp-fts-cross-process-probe-');
    assert_true(is_string($temporary), 'cross-process validator probe should have a temporary path');
    file_put_contents($temporary, $probe);
    try {
        $result = test_run_subprocess([PHP_BINARY, $temporary], $root);
        assert_same(0, $result['exit'], 'isolated cross-process validator should execute: ' . $result['stderr']);
        $outcomes = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        assert_same(true, $outcomes['valid'] ?? null, 'the complete cross-process proof fixture should validate');
        assert_true((int) ($outcomes['removal_count'] ?? 0) >= 400, 'the destructive probe should remove every nested field, row, and statement proof');
        assert_same(true, $outcomes['all_removals_rejected'] ?? null, 'every removed cross-process proof path should fail closed');
        assert_same([], $outcomes['removal_failures'] ?? null, 'no cross-process proof field may be optional');
        assert_same([
            'same_process' => false,
            'busy_claims_guarded' => false,
            'holder_not_killed' => false,
            'base_table_scan' => false,
        ], $outcomes['semantic_mutations'] ?? null, 'same-process, unsafe claim, live holder, and table-scan substitutions must be rejected');
    } finally {
        @unlink($temporary);
    }
});

test_case('relational preparation is pure and claim options reach the bounded preload', function (): void {
    $indexer = (string) file_get_contents(dirname(__DIR__, 3) . '/components/full-text-search/src/Indexer.php');
    $preparePost = wp_fts_wc_contract_function_source($indexer, 'prepare_post');
    $prepareSource = wp_fts_wc_contract_function_source($indexer, 'prepare_post_source');
    assert_contains('prepare_post_source($post, $opts)', $preparePost, 'prepare_post must delegate to the guarded source preparation path');
    foreach ([
        "property_exists(\$post, 'terms')",
        "is_array(\$post->terms)",
        "property_exists(\$post, 'custom_fields')",
        "is_array(\$post->custom_fields)",
        'Set-oriented post preparation requires authoritative terms and custom_fields arrays.',
        "array_keys(\$post->custom_fields)",
    ] as $required) {
        assert_contains($required, $prepareSource, "set-oriented source preparation should retain {$required}");
    }
    $authorityFence = strpos($prepareSource, 'Set-oriented post preparation requires authoritative terms and custom_fields arrays.');
    $postIdRead = strpos($prepareSource, '$postProperties = get_object_vars($post);');
    assert_true(
        $authorityFence !== false
            && $postIdRead !== false
            && $authorityFence < $postIdRead,
        'source-authority validation must run before post-id normalization'
    );

    $extractor = (string) file_get_contents(dirname(__DIR__, 2) . '/src/PostContentExtractor.php');
    $selectedKeys = wp_fts_wc_contract_function_source($extractor, 'selected_custom_field_keys');
    assert_contains('array_keys($post->custom_fields)', $selectedKeys, 'an authoritative post map should provide the default selected key identities');
    assert_true(!str_contains($selectedKeys, 'get_option'), 'pure post preparation must not reopen WordPress option storage');
    assert_true(!str_contains($selectedKeys, 'get_post_meta'), 'pure post preparation must not reopen WordPress metadata storage');

    $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');
    $processBatch = wp_fts_wc_contract_function_source($plugin, 'process_prepared_claim_batch');
    $claimOptions = strpos($processBatch, "\$payload['index_options']");
    $loadPosts = strpos($processBatch, 'self::load_posts_for_indexing(');
    assert_true(
        $claimOptions !== false && $loadPosts !== false && $claimOptions < $loadPosts,
        'every claim payload must be decoded before the shared source/dependency preload begins'
    );
    assert_contains('$index_options_by_post_id', $processBatch, 'per-post claim options should remain keyed through the batch');
    $loadPostsSource = wp_fts_wc_contract_function_source($plugin, 'load_posts_for_indexing');
    assert_contains('self::preload_index_dependencies($posts, $index_options_by_post_id)', $loadPostsSource, 'the source loader must pass claim options into the one dependency preload');
    $preload = wp_fts_wc_contract_function_source($plugin, 'preload_index_dependencies');
    assert_contains('$index_options_by_post_id[(int) $post_id] ?? []', $preload, 'each preloaded post should receive only its owned claim options');
    assert_contains("!array_key_exists('custom_field_keys', \$index_options)", $preload, 'configured keys should be fallback-only');
    assert_true(!str_contains($preload, "array_key_exists('custom_fields', \$index_options)"), 'claim options should expose only the current custom-field key name');

    $wordpressOptions = wp_fts_wc_contract_function_source($plugin, 'with_wordpress_analyzer_options');
    assert_true(!str_contains($wordpressOptions, 'document_language_resolver'), 'the default runtime analyzer must not install a document provider callback');
    assert_true(!str_contains($wordpressOptions, 'query_language_resolver'), 'the default runtime analyzer must not install a query provider callback');
    assert_true(!str_contains($wordpressOptions, 'get_post_meta'), 'the analyzer option boundary must remain free of per-document metadata I/O');
    assert_true(!str_contains($wordpressOptions, 'pll_') && !str_contains($wordpressOptions, 'wpml_'), 'the analyzer option boundary must remain free of provider I/O');
});

test_case('relational worst-case runner publishes an atomic failure envelope and raw bundle', function (): void {
    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is required to exercise failed-run publication.');
    }

    $root = dirname(__DIR__, 2);
    $runner = (string) file_get_contents($root . '/tools/run-relational-fts-worst-case.sh');
    assert_contains("trap cleanup EXIT\ntrap 'publish_failure_envelope 130 || true; exit 130' INT\ntrap 'publish_failure_envelope 143 || true; exit 143' TERM", $runner, 'runner cancellation must synchronously publish and preserve SIGINT/SIGTERM exit classes');
    assert_true(!str_contains($runner, 'trap cleanup EXIT INT TERM'), 'signal handlers must not collapse cancellation into a generic exit 1');
    assert_contains("php -r '", $runner, 'the whole-run watchdog must remain one directly tracked PHP child');
    assert_contains('if(posix_getppid()!==$parent||!@posix_kill($parent,0)||!@posix_kill($parent,$first)){exit(0);}', $runner, 'the first watchdog signal must immediately recheck parentage and liveness');
    assert_contains('if(posix_getppid()===$parent&&@posix_kill($parent,0)){@posix_kill($parent,9);}', $runner, 'the hard-kill escalation must immediately recheck parentage and liveness');
    assert_contains('</dev/null >/dev/null 2>&1 &', $runner, 'the watchdog must not retain CI stdin/stdout/stderr pipes after runner death');
    assert_true(!str_contains($runner, '( sleep'), 'the watchdog must not hide an orphanable sleep child behind a background subshell');
    foreach ([
        'failure-compose-ps.json',
        'failure-compose.log',
        'failure-db-inspect.json',
        'failure-db.log',
        'docker compose -f "${COMPOSE_FILE}"',
        'ps --all --format json',
        'logs --no-color --timestamps',
        'docker inspect "${db_container}"',
        'docker logs --timestamps "${db_container}"',
    ] as $required) {
        assert_contains($required, $runner, "failed runner must preserve real database diagnostics: {$required}");
    }
    $cleanupStart = strpos($runner, "\ncleanup() {");
    $cleanupEnd = strpos($runner, "\ntrap cleanup EXIT", $cleanupStart === false ? 0 : $cleanupStart);
    assert_true(is_int($cleanupStart) && is_int($cleanupEnd), 'runner cleanup should remain independently inspectable');
    $cleanup = substr($runner, $cleanupStart, $cleanupEnd - $cleanupStart);
    $quiesceWorkloads = strpos($cleanup, 'quiesce_failed_workloads');
    $failureCapture = strpos($cleanup, 'capture_failure_environment_artifacts');
    $archivePublication = strpos($cleanup, 'publish_evidence');
    $composeTeardown = strpos($cleanup, 'docker compose -f "${COMPOSE_FILE}" down');
    assert_true(
        is_int($quiesceWorkloads)
            && is_int($failureCapture)
            && is_int($archivePublication)
            && is_int($composeTeardown)
            && $quiesceWorkloads < $failureCapture
            && $failureCapture < $composeTeardown
            && $composeTeardown < $archivePublication,
        'failed workloads must stop immediately, then retain logs before teardown and archive only the quiesced state'
    );
    assert_contains('timeout --signal=KILL 30s docker compose -f "${COMPOSE_FILE}" kill wordpress db', $runner, 'failed cleanup must bound residual WordPress and database work before diagnostics');
    $temporary = sys_get_temp_dir() . '/wp-fts-failure-publication-' . bin2hex(random_bytes(6));
    $bin = $temporary . '/bin';
    mkdir($bin, 0777, true);
    $quiesceStart = strpos($runner, "quiesce_failed_workloads() {");
    $quiesceEnd = strpos($runner, "\n}\n\ncleanup() {", $quiesceStart === false ? 0 : $quiesceStart);
    assert_true(is_int($quiesceStart) && is_int($quiesceEnd), 'failed-workload quiescence should remain independently executable');
    $quiesceFunction = substr($runner, $quiesceStart, $quiesceEnd - $quiesceStart + 2);
    $quiesceBin = $temporary . '/quiesce-bin';
    mkdir($quiesceBin, 0777, true);
    file_put_contents($quiesceBin . '/timeout', <<<'SH'
#!/bin/sh
while [ "$#" -gt 0 ]; do
    case "$1" in
        --*) shift ;;
        *s) shift; break ;;
        *) break ;;
    esac
done
exec "$@"
SH);
    file_put_contents($quiesceBin . '/docker', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "${QUIESCE_CALLS}"
case "$*" in
    *' kill wordpress db') exit 41 ;;
    *' down -v --remove-orphans') [ "${QUIESCE_SCENARIO}" = down_succeeds ] && exit 0; exit 42 ;;
    'kill wp-container'|'kill db-container') exit 43 ;;
esac
exit 44
SH);
    chmod($quiesceBin . '/timeout', 0700);
    chmod($quiesceBin . '/docker', 0700);
    $quiesceProbe = $temporary . '/quiesce-probe.sh';
    file_put_contents($quiesceProbe, "#!/usr/bin/env bash\nset -u\nCOMPOSE_FILE=\"\$1\"\nCOMPOSE_TORN_DOWN=0\nWP_CONTAINER=wp-container\nDB_CONTAINER=db-container\n" . $quiesceFunction . "\nquiesce_failed_workloads\nstatus=\$?\nprintf 'torn_down=%s\\n' \"\${COMPOSE_TORN_DOWN}\"\nexit \"\${status}\"\n");
    chmod($quiesceProbe, 0700);
    $quiesceCompose = $temporary . '/quiesce-compose.yaml';
    file_put_contents($quiesceCompose, "services: {}\n");
    $quiesceCalls = $temporary . '/quiesce-calls.log';
    $path = getenv('PATH');
    $path = is_string($path) ? $path : '/usr/local/bin:/usr/bin:/bin';
    $downFallback = test_run_subprocess([
        'env',
        'PATH=' . $quiesceBin . ':' . $path,
        'QUIESCE_CALLS=' . $quiesceCalls,
        'QUIESCE_SCENARIO=down_succeeds',
        'bash',
        $quiesceProbe,
        $quiesceCompose,
    ], $root);
    assert_same(0, $downFallback['exit'], 'compose-down fallback should quiesce the lane when compose kill fails');
    assert_contains('torn_down=1', $downFallback['stdout'], 'successful emergency teardown should prevent a later duplicate teardown');
    $downCalls = file($quiesceCalls, FILE_IGNORE_NEW_LINES) ?: [];
    assert_same(2, count($downCalls), 'kill failure should attempt immediate compose teardown before any container-specific fallback');
    assert_contains('kill wordpress db', $downCalls[0], 'quiescence should try the narrow workload kill first');
    assert_contains('down -v --remove-orphans', $downCalls[1], 'kill failure should immediately tear down rather than capture diagnostics');
    file_put_contents($quiesceCalls, '');
    $closedFailure = test_run_subprocess([
        'env',
        'PATH=' . $quiesceBin . ':' . $path,
        'QUIESCE_CALLS=' . $quiesceCalls,
        'QUIESCE_SCENARIO=all_fail',
        'bash',
        $quiesceProbe,
        $quiesceCompose,
    ], $root);
    assert_true($closedFailure['exit'] !== 0, 'unproven quiescence must remain nonzero so cleanup skips capture and compression');
    $failedCalls = file($quiesceCalls, FILE_IGNORE_NEW_LINES) ?: [];
    assert_same(4, count($failedCalls), 'failed project teardown should make exactly two final bounded direct-kill attempts');
    assert_same('kill wp-container', $failedCalls[2], 'direct WordPress kill should precede the database fallback');
    assert_same('kill db-container', $failedCalls[3], 'direct database kill should be the last quiescence fallback');
    $stub = $bin . '/stub';
    file_put_contents($stub, "#!/usr/bin/env sh\nexit 0\n");
    chmod($stub, 0700);
    foreach (['docker', 'composer', 'unzip', 'rsync'] as $command) {
        symlink($stub, $bin . '/' . $command);
    }
    $output = $temporary . '/report.json';
    $missingRef = 'refs/heads/wp-fts-intentionally-missing-' . bin2hex(random_bytes(6));

    try {
        $startedAt = hrtime(true);
        $result = test_run_subprocess([
            'env',
            'PATH=' . $bin . ':' . $path,
            'HOME=' . $temporary,
            'bash',
            $root . '/tools/run-relational-fts-worst-case.sh',
            '--source-ref=' . $missingRef,
            '--output=' . $output,
        ], $root);
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        assert_true($result['exit'] !== 0, 'an unresolvable source ref must fail the runner');
        assert_true($elapsedSeconds < 10.0, "early failure must close captured pipes promptly instead of orphaning the watchdog ({$elapsedSeconds}s)");
        assert_true(is_file($output), 'failed runner must atomically publish its primary failure envelope');
        $failure = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        assert_same('relational-fts-run-state-v2', $failure['schema'] ?? null, 'failed runner should never publish a preliminary PASS schema at the primary path');
        assert_same('FAIL', $failure['status'] ?? null, 'failed runner envelope should be explicit');
        assert_same(false, $failure['completed'] ?? null, 'failed runner envelope must be terminally incomplete');
        assert_same('source-and-package', $failure['stage'] ?? null, 'failure envelope should retain the last coarse phase');
        assert_same('source-ref-resolve', $failure['phase'] ?? null, 'failure envelope should retain the exact timed phase even when output was captured');
        assert_same('ProcessFailure', $failure['failure_class'] ?? null, 'ordinary source failure should retain its process-failure class');
        assert_true(is_string($failure['run_id'] ?? null) && strlen($failure['run_id']) === 32, 'failure envelope should retain one stable run identity');

        $processes = test_run_subprocess(['ps', 'ax', '-o', 'command='], $root);
        assert_same(0, $processes['exit'], 'watchdog orphan check should inspect the host process table');
        assert_true(!str_contains($processes['stdout'], 'wp-fts-watchdog-' . $failure['run_id']), 'early failure must kill and reap its directly tracked watchdog');

        $archive = $output . '.artifacts.tar.gz';
        assert_true(is_file($archive) && filesize($archive) > 0, 'failed runner should retain a non-empty raw artifact bundle');
        $archiveCheck = test_run_subprocess(['tar', '-tzf', $archive], $root);
        assert_same(0, $archiveCheck['exit'], 'failed-run raw artifact bundle should be a complete readable archive');
        assert_same([], glob($output . '.tmp.*') ?: [], 'primary publication must not leave a temporary file');
        assert_same([], glob($archive . '.tmp.*') ?: [], 'archive publication must not leave a temporary file');
    } finally {
        foreach (glob($temporary . '/*') ?: [] as $pathToRemove) {
            if (is_dir($pathToRemove)) {
                foreach (glob($pathToRemove . '/*') ?: [] as $nested) {
                    @unlink($nested);
                }
                @rmdir($pathToRemove);
            } else {
                @unlink($pathToRemove);
            }
        }
        @rmdir($temporary);
    }
});

test_case('relational worst-case Docker preflight failure still publishes evidence', function (): void {
    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is required to exercise Docker preflight publication.');
    }

    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir() . '/wp-fts-preflight-publication-' . bin2hex(random_bytes(6));
    $bin = $temporary . '/bin';
    mkdir($bin, 0777, true);
    $docker = $bin . '/docker';
    file_put_contents($docker, "#!/usr/bin/env sh\nexit 1\n");
    chmod($docker, 0700);
    $successfulCommand = $bin . '/successful-command';
    file_put_contents($successfulCommand, "#!/usr/bin/env sh\nexit 0\n");
    chmod($successfulCommand, 0700);
    foreach (['composer', 'unzip', 'rsync'] as $command) {
        symlink($successfulCommand, $bin . '/' . $command);
    }
    $output = $temporary . '/preflight.json';
    $path = getenv('PATH');
    $path = is_string($path) ? $path : '/usr/local/bin:/usr/bin:/bin';

    try {
        $result = test_run_subprocess([
            'env',
            'PATH=' . $bin . ':' . $path,
            'HOME=' . $temporary,
            'bash',
            $root . '/tools/run-relational-fts-worst-case.sh',
            '--output=' . $output,
        ], $root);
        assert_true($result['exit'] !== 0, 'Docker daemon preflight should remain terminal');
        assert_contains('Docker daemon is unavailable', $result['stdout'] . $result['stderr'], 'preflight failure should identify the unavailable daemon');
        assert_true(is_file($output), 'preflight failure should publish its primary envelope after output validation');
        $failure = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        assert_same('relational-fts-run-state-v2', $failure['schema'] ?? null, 'preflight failure should use the ordinary runner state schema');
        assert_same('FAIL', $failure['status'] ?? null, 'preflight failure should never look accepted');
        assert_same('source-and-package', $failure['stage'] ?? null, 'preflight envelope should retain the coarse source/package stage');
        assert_true(is_file($output . '.artifacts.tar.gz'), 'preflight failure should leave an uploadable raw artifact archive');
    } finally {
        foreach (glob($temporary . '/*') ?: [] as $pathToRemove) {
            if (is_dir($pathToRemove)) {
                foreach (glob($pathToRemove . '/*') ?: [] as $nested) {
                    @unlink($nested);
                }
                @rmdir($pathToRemove);
            } else {
                @unlink($pathToRemove);
            }
        }
        @rmdir($temporary);
    }
});

test_case('relational worst-case evidence publication does not dirty a clean source tree', function (): void {
    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is required to exercise clean-source attestation.');
    }

    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir() . '/wp-fts-clean-source-' . bin2hex(random_bytes(6));
    $repository = $temporary . '/repository';
    $runnerDirectory = $repository . '/indexer/tools';
    $bin = $temporary . '/bin';
    mkdir($runnerDirectory, 0777, true);
    mkdir($bin, 0777, true);
    $runner = $runnerDirectory . '/run-relational-fts-worst-case.sh';
    copy($root . '/tools/run-relational-fts-worst-case.sh', $runner);
    chmod($runner, 0700);
    $stub = $bin . '/stub';
    file_put_contents($stub, "#!/usr/bin/env sh\nexit 0\n");
    chmod($stub, 0700);
    foreach (['docker', 'composer', 'unzip', 'rsync'] as $command) {
        symlink($stub, $bin . '/' . $command);
    }
    $path = getenv('PATH');
    $path = is_string($path) ? $path : '/usr/local/bin:/usr/bin:/bin';

    try {
        foreach ([
            ['git', 'init', '--quiet'],
            ['git', 'config', 'user.name', 'Relational FTS Contract'],
            ['git', 'config', 'user.email', 'relational-fts-contract@example.invalid'],
            ['git', 'add', 'indexer/tools/run-relational-fts-worst-case.sh'],
            ['git', 'commit', '--quiet', '-m', 'contract fixture'],
        ] as $command) {
            $git = test_run_subprocess($command, $repository);
            assert_same(0, $git['exit'], 'clean-source contract fixture should initialize an exact committed source tree');
        }
        file_put_contents($repository . '/.git/info/exclude', ".context/\n", FILE_APPEND);

        $output = $repository . '/.context/evidence.json';
        $clean = test_run_subprocess([
            'env',
            'PATH=' . $bin . ':' . $path,
            'HOME=' . $temporary,
            'bash',
            $runner,
            '--output=' . $output,
        ], $repository);
        assert_true($clean['exit'] !== 0, 'the isolated fixture should stop at its intentionally incomplete source tree');
        assert_true(
            !str_contains($clean['stdout'] . $clean['stderr'], 'source tree is dirty'),
            'the required in-tree RUNNING envelope must not invalidate the cleanliness snapshot that preceded it'
        );
        $failure = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        assert_same('FAIL', $failure['status'] ?? null, 'the later fixture failure should still replace RUNNING with a terminal envelope');
        assert_same('source-worktree-create', $failure['phase'] ?? null, 'a clean source should advance beyond cleanliness validation');

        remove_directory_tree($repository . '/.context');
        mkdir($repository . '/.context');
        file_put_contents($repository . '/.context/preexisting-harness.json', "{}\n");
        $ignoredStatus = test_run_subprocess(['git', 'status', '--porcelain', '--untracked-files=all'], $repository);
        assert_same(0, $ignoredStatus['exit'], 'ignored-artifact fixture should remain a valid Git worktree');
        assert_same('', trim($ignoredStatus['stdout']), 'Git status alone must not reveal the ignored preexisting .context artifact');
        $preexistingOutput = $temporary . '/preexisting-evidence.json';
        $preexisting = test_run_subprocess([
            'env',
            'PATH=' . $bin . ':' . $path,
            'HOME=' . $temporary,
            'bash',
            $runner,
            '--output=' . $preexistingOutput,
        ], $repository);
        assert_true($preexisting['exit'] !== 0, 'a preexisting untracked harness artifact must remain terminal');
        assert_contains(
            'source tree is dirty; commit/stash it',
            $preexisting['stdout'] . $preexisting['stderr'],
            'the runner may exempt only its own post-snapshot publication, not arbitrary preexisting .context files'
        );
        $preexistingFailure = json_decode((string) file_get_contents($preexistingOutput), true, 512, JSON_THROW_ON_ERROR);
        assert_same('source-ref-resolve', $preexistingFailure['phase'] ?? null, 'ignored preexisting .context state must be rejected before source-worktree-create');

        remove_directory_tree($repository . '/.context');
        file_put_contents($runner, "\n# Deliberate tracked source change for the dirty-tree half of the contract.\n", FILE_APPEND);
        $dirtyOutput = $temporary . '/dirty-evidence.json';
        $dirty = test_run_subprocess([
            'env',
            'PATH=' . $bin . ':' . $path,
            'HOME=' . $temporary,
            'bash',
            $runner,
            '--output=' . $dirtyOutput,
        ], $repository);
        assert_true($dirty['exit'] !== 0, 'a genuinely dirty source tree must remain terminal');
        assert_contains(
            'source tree is dirty; commit/stash it',
            $dirty['stdout'] . $dirty['stderr'],
            'the pre-publication snapshot must preserve the clean acceptance gate for actual source changes'
        );
    } finally {
        remove_directory_tree($temporary);
    }
});

test_case('relational worst-case dependency install keeps generated component files out of source status', function (): void {
    $ignore = file(dirname(__DIR__, 3) . '/components/full-text-search/.gitignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    assert_true(is_array($ignore), 'the reusable component should define local dependency ignore rules');
    assert_true(in_array('/composer.lock', $ignore, true), 'the library lock file generated by Composer should stay untracked');
    assert_true(in_array('/vendor/', $ignore, true), 'the component dependency tree generated by Composer should stay untracked');
});

test_case('production search requires relational storage', function (): void {
    $rejected = false;
    try {
        new WP_FTS_Searcher(new stdClass(), new WP_FTS_Analyzer());
    } catch (TypeError) {
        $rejected = true;
    }
    assert_true($rejected, 'the public constructor must reject storage without the relational contract');
    assert_true(!method_exists(WP_FTS_Indexer::class, 'optimize'), 'the component indexer must not retain plugin storage maintenance');

    $root = dirname(__DIR__, 2);
    $productionSources = [];
    foreach (glob($root . '/src/*.php') ?: [] as $path) {
        $productionSources[$path] = (string) file_get_contents($path);
    }
    assert_true($productionSources !== [], 'production adapter sources should be inspectable');
    assert_contains(
        'new WP_FTS_Searcher(self::storage(false), self::runtime_analyzer())',
        $productionSources[$root . '/src/Plugin.php'] ?? '',
        'the shared WordPress search page must pass the relational storage directly'
    );

    $analyzerSource = (string) file_get_contents(dirname($root) . '/components/full-text-search/src/Analyzer.php');
    $pipelineSource = (string) file_get_contents(dirname($root) . '/components/full-text-search/src/LanguagePipeline.php');
    assert_true(!str_contains($analyzerSource, 'detect_lemma_pack_language'), 'query language detection must not probe every enabled lemma pack');
    assert_true(!str_contains($pipelineSource, 'detect_lemma_pack_language'), 'the cross-pack dictionary router must not remain callable');
});

test_case('production component bootstrap cannot autoload test storage fixtures', function (): void {
    $bootstrap = dirname(__DIR__, 3) . '/components/full-text-search/src/bootstrap.php';
    $code = 'require ' . var_export($bootstrap, true) . ';'
        . 'echo json_encode(['
        . '"in_memory" => class_exists("WP_FTS_Storage_InMemory", true),'
        . '"file" => class_exists("WP_FTS_Storage_File", true),'
        . '], JSON_THROW_ON_ERROR);';
    $result = test_run_subprocess([PHP_BINARY, '-r', $code], dirname(__DIR__, 2));

    assert_same(0, $result['exit'], 'production bootstrap probe should succeed');
    assert_same(
        ['in_memory' => false, 'file' => false],
        json_decode($result['stdout'], true, 8, JSON_THROW_ON_ERROR),
        'production bootstrap must not expose either removed application backend'
    );
});

test_case('surface-prefix architecture stays bounded and documents its irreducible broad work', function (): void {
    $root = dirname(__DIR__, 2);
    $surface = (string) file_get_contents(__DIR__ . '/surface-prefix-containment.php');
    $storage = (string) file_get_contents($root . '/src/RelationalStorage.php');
    $relationalRegressions = (string) file_get_contents(__DIR__ . '/relational-cursor-and-lease-regressions.php');
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');
    $readme = (string) file_get_contents($root . '/README.md');
    $configuration = (string) file_get_contents($root . '/docs/configuration.md');
    $limitations = (string) file_get_contents($root . '/docs/limitations.md');
    $operations = (string) file_get_contents($root . '/docs/operations.md');

    foreach ([
        'eighty 252-byte source tokens must create eighty kind=1 rows',
        'an 81st long token must add one row',
        '20,000 repeated source occurrences must create exactly one kind=1 surface identity',
        'a filtered final token cannot turn the previous word into a prefix',
        'one document admits 4096 lexical and 4096 bounded surface rows',
        'surface SQL cost-selects one bounded AND-prefix driver',
        'surface planning gates and costs every final-prefix range once',
    ] as $required) {
        assert_contains($required, $surface, "surface containment should retain hard invariant: {$required}");
    }
    foreach ([
        'Prefix representation and unavoidable broad-OR work',
        'database work is proportional to matching postings',
        '20,000 repeated',
        '4,096 lexical plus 4,096 distinct surface rows',
        '4,097th surface or lexical identity',
        'every prefix plan contains exactly one indexed surface',
        'Planning never scans',
        'If `P <= A × 8,192`',
        'multiplication-free in PHP',
        '9,900 / 103,500',
        '/ 201,000 physical postings',
        'at most 32,768 rows',
        'complete three-statement search at most 65,536',
        '8,000–8,192 candidate postings in total',
        'at most 2,048 rows',
        'no materialized scan of the common exact posting range',
        'selective-prefix anchor AND warm p95 / p99',
        'selective-prefix anchor rows examined / common exact materializations',
    ] as $required) {
        assert_contains($required, $acceptance, "acceptance should state bounded surface contract: {$required}");
    }
    assert_contains('normalized identity per distinct source surface as `kind=1`', $readme, 'README should explain the non-amplifying surface representation');
    assert_contains('database work is proportional to matching', $readme, 'README should state exact broad-query cost honestly');
    assert_contains('sums `doc_freq` across that one dictionary range', $configuration, 'configuration should distinguish dictionary planning from posting work');
    assert_contains('does not materialize every proper prefix', $limitations, 'limitations should state the surface storage shape');
    assert_contains('sums `doc_freq` over the one complete dictionary range', $limitations, 'limitations should state the bounded prefix-planning shape');
    assert_contains('database work is proportional to those matches', $operations, 'operations should expose broad-query host load');
    assert_contains('21,000-row dictionary/control ceiling', $operations, 'operations should expose the full-range planning bound');
    assert_contains('anchor DF upper × 8,192', $operations, 'operations should expose the hybrid driver threshold');
    assert_contains('Candidate-first ranking has a 32,768', $operations, 'operations should expose the candidate-first rank bound');
    assert_contains('complete three-statement search a 65,536', $operations, 'operations should expose the candidate-first request bound');
    assert_contains('2,048 rows', $operations, 'operations should expose the selective-prefix anchor bound');
    assert_contains('intdiv($prefixPostingRows - 1, self::MAX_DOCUMENT_POSTINGS)', $storage, 'the hybrid driver comparison must remain multiplication-free');
    assert_true(!str_contains($storage, '$anchorDocFreqUpper * self::MAX_DOCUMENT_POSTINGS'), 'the hybrid driver must not reintroduce an overflowing multiplication');
    assert_contains('relational broad non-anchor prefixes execute candidate-first with exact score', $relationalRegressions, 'real SQLite SQL must retain candidate-first membership and score parity');
    assert_contains("assert_same(100014640.0, \$payload['results'][0]['score']", $relationalRegressions, 'candidate-first scoring must retain an independent exact numerical assertion');
    foreach ([
        'const WP_FTS_WC_DENSE_CANDIDATE_TERMS = 4000;',
        'const WP_FTS_WC_DENSE_PREFIX_DOCUMENTS = 2000;',
        'const WP_FTS_WC_DENSE_PREFIX_COMPLETIONS = [',
        "'candidatefill' . wp_fts_wc_alpha_id(\$candidateTerm) . 'qx'",
        'surface_rarest_exact_anchor_and_driver_cost',
        'surface_dense_candidate_prefix_and_driver_cost',
        'surface_dense_candidate_prefix_and_unrelated_posting_envelope',
        '4,000-4,096 lexical + 4,000-4,096 surface; 8,000-8,192 total',
        "\$tokens[] = 'selectiveprefixcompletion';",
        'selective_prefix_anchor_and_common_exact_not_materialized',
    ] as $required) {
        assert_contains($required, $integration, "worst-case integration should retain dense candidate invariant: {$required}");
    }
});

test_case('operator diagnostics verify bounded schema state without corpus counts or scheduling', function (): void {
    $root = dirname(__DIR__, 2);
    $pluginSource = (string) file_get_contents($root . '/src/Plugin.php');
    $cliSource = (string) file_get_contents($root . '/src/WPCLICommand.php');
    $operator = wp_fts_wc_contract_function_source($pluginSource, 'operator_status');
    $supportSnapshot = wp_fts_wc_contract_function_source($pluginSource, 'support_snapshot');
    $diagnose = wp_fts_wc_contract_function_source($cliSource, 'diagnose');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');
    $readme = (string) file_get_contents($root . '/README.md');
    $operations = (string) file_get_contents($root . '/docs/operations.md');

    assert_true(!str_contains($pluginSource, 'include_exact_counts'), 'operator status should expose no switch that can revive exhaustive corpus counts');
    assert_contains('self::operator_status(true)', $supportSnapshot, 'support snapshots should opt into bounded physical schema verification');
    assert_contains('WP_FTS_Plugin::operator_status(true)', $diagnose, 'diagnose should opt into bounded physical schema verification');
    $physicalGuard = strpos($diagnose, 'physical_schema_usable');
    $searchCall = strpos($diagnose, 'WP_FTS_Plugin::search_with_explain');
    assert_true(is_int($physicalGuard) && is_int($searchCall) && $physicalGuard < $searchCall, 'diagnose should reject damaged physical schema before a search can schedule recovery');
    foreach (['schedule_queue_processor(', 'schedule_queue_processor_for_operator(', 'enqueue_corpus_scope('] as $mutation) {
        assert_true(!str_contains($operator, $mutation), "operator status must not call mutator {$mutation}");
        assert_true(!str_contains($supportSnapshot, $mutation), "support snapshots must not call mutator {$mutation}");
        assert_true(!str_contains($diagnose, $mutation), "diagnose must not call mutator {$mutation}");
    }

    assert_contains('No operator status path exhaustively counts posts or', $acceptance, 'acceptance should keep operator diagnostics count-free');
    assert_contains('no repair, enqueue, option mutation, or queue scheduling', $acceptance, 'acceptance should require damaged-schema diagnostics to stay read-only');
    assert_contains('it still does not count the corpus', $readme, 'README should distinguish physical diagnose from exhaustive counting');
    assert_contains('A damaged schema is', $operations, 'operations should document damaged-schema diagnose behavior');
});

test_case('relational worst-case runner has fixed real corpus and resource profiles', function (): void {
    $root = dirname(__DIR__, 2);
    $runner = (string) file_get_contents($root . '/tools/run-relational-fts-worst-case.sh');
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');

    foreach ([
        '2k) DOCUMENTS=2000',
        '50k) DOCUMENTS=50000',
        '100k) DOCUMENTS=100000',
        'mem_limit: 1073741824',
        'memswap_limit: 1073741824',
        '--innodb-buffer-pool-size=268435456',
        '--tmp-table-size=33554432',
        '--innodb-flush-log-at-trx-commit=1',
        'CONCURRENCY_SECONDS=60',
        '--performance-schema=ON',
        '--performance-schema-max-sql-text-length=32768',
        '--performance-schema-events-statements-history-long-size=2048',
        '--performance-schema-events-statements-history-size=0',
        '--performance-schema-digests-size=0',
        '--performance-schema-accounts-size=0',
        '--performance-schema-hosts-size=0',
        '--performance-schema-users-size=0',
        '--performance-schema-session-connect-attrs-size=0',
        '--performance-schema-events-stages-history-long-size=0',
        '--performance-schema-events-stages-history-size=0',
        '--performance-schema-events-transactions-history-long-size=0',
        '--performance-schema-events-transactions-history-size=0',
        '--performance-schema-events-waits-history-long-size=0',
        '--performance-schema-events-waits-history-size=0',
        '--performance-schema-max-thread-instances=128',
        'db_data:/var/lib/mysql',
        '${EVIDENCE_DIR}:/evidence',
        '${MUTATION_PROOF_SCRIPT}:/proof/mutation-fence-concurrency.php:ro',
        '${ISOLATED_BOUNDARIES_SCRIPT}:/proof/relational-fts-isolated-boundaries.php:ro',
        'WP_FTS_MUTATION_QUEUE_PATH=/var/www/html/wp-content/plugins/indexer/src/IndexQueue.php',
        'wordpress timeout -s KILL 180 php /proof/mutation-fence-concurrency.php',
        'run_php_phase indexing-prepare',
        'setup|indexing-prepare|initial-index-drain|reindex-drain|validate|drain',
        '"mode"=>"forced_full_rebuild"',
        'RUN_COMPLETED=0',
        'RUN_PUBLISHED=0',
        'relational-fts-run-state-v2',
        '${OUTPUT}.partial-evidence.json',
        'could not atomically publish the complete raw artifact bundle',
        'could not atomically publish the completed evidence report',
        'worktree add --detach "${SOURCE_ROOT}" "${SOURCE_COMMIT}"',
        'initialize_and_attest_jieba_source',
        'components/full-text-search/tools/initialize-jieba-source.sh',
        'components/full-text-search/resources/runtime/jieba/manifest.json',
        'components/full-text-search/resources/sources/jieba',
        '${EVIDENCE_DIR}/jieba-source-current.json',
        'COLD_SAMPLES=10',
        'DB_PRE_CORPUS_PEAK_LIMIT_BYTES=805306368',
        '/sys/fs/cgroup/memory.peak',
        '/sys/fs/cgroup/memory/memory.max_usage_in_bytes',
        'event_value /sys/fs/cgroup/memory.events oom',
        'event_value /sys/fs/cgroup/memory.events oom_kill',
        'event_value /sys/fs/cgroup/memory/memory.oom_control oom_kill',
        'capture_database_memory_checkpoint "pre-cold-restart-${case_id}-${sample}"',
        'capture_database_memory_checkpoint post-frontier',
        'capture_database_memory_checkpoint post-reindex',
        'capture_database_memory_checkpoint pre-scope-restart',
        '$databaseMemoryCheckpointLabels = ["pre-corpus", "post-frontier", "post-reindex"];',
        'exact ordered 45-checkpoint inventory',
        'timed_compose validation-database-restart 300 restart db',
        'timed_compose scope-database-restart 300 restart db',
        'configure_performance_schema_consumers validation-performance-schema-enable',
        'configure_performance_schema_consumers "cold-performance-schema-enable-${case_id}-${sample}"',
        'configure_performance_schema_consumers scope-performance-schema-enable',
        'capture_database_memory_checkpoint final-workload',
        'capture_wordpress_memory_checkpoint pre-corpus',
        'capture_wordpress_memory_checkpoint final-workload',
        'wordpress-memory-cgroup.tsv',
        'WordPress container identity changed before memory checkpoint',
        'relational-fts-resources-v2',
        'relational-fts-database-cgroup-memory-v2',
        'relational-fts-wordpress-cgroup-memory-v3',
        '"raw" => $raw',
        'hash_equals(hash("sha256",$raw),$checkpoint["raw_sha256"])',
        "--format '{{.State.StartedAt}}|{{.State.Pid}}|{{.RestartCount}}'",
        'finalize_cgroup_memory_evidence',
        '["common_or","max_valid_or_prefix","rare_anchor_and","prefix_fanout"]',
        '"expected_checkpoint_labels"',
        '$actualLabels!==$expectedLabels',
        '"whole_run_peak_bytes"',
    ] as $required) {
        assert_contains($required, $runner, "runner should retain hard acceptance contract: {$required}");
    }
    $expectedLanes = [
        '50k/mariadb-10.11) LANE_ID="mariadb1011-50k" ;;',
        '50k/mysql-8.0) LANE_ID="mysql80-50k" ;;',
        '100k/mariadb-10.11) LANE_ID="mariadb1011-100k" ;;',
        '100k/mysql-8.0) LANE_ID="mysql80-100k" ;;',
    ];
    foreach ($expectedLanes as $lane) {
        assert_contains($lane, $runner, "runner should retain the clean acceptance lane: {$lane}");
    }
    assert_same(count($expectedLanes), substr_count($runner, ') LANE_ID="'), 'runner must expose exactly four clean profile/engine lane identities');
    assert_same(6, substr_count($runner, 'configure_performance_schema_consumers'), 'the exact Performance Schema consumers must be configured initially and after every database restart through one implementation');
    $laneMap = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_expected_lane_id');
    foreach ([
        "'50k/mariadb-10.11' => 'mariadb1011-50k'",
        "'50k/mysql-8.0' => 'mysql80-50k'",
        "'100k/mariadb-10.11' => 'mariadb1011-100k'",
        "'100k/mysql-8.0' => 'mysql80-100k'",
    ] as $lane) {
        assert_contains($lane, $laneMap, "integration proof should retain the clean acceptance lane: {$lane}");
    }
    assert_same(count($expectedLanes), substr_count($laneMap, " => '"), 'integration proof must accept exactly the same four clean lane identities as the runner');
    assert_contains('one of the four clean acceptance lanes', $runner, 'unsupported clean tuples should report the complete lane cardinality');
    assert_contains('cannot substitute for one of these four lanes', $acceptance, 'the written contract should require every clean lane identity');
    $retiredTokens = [
        'mysql-' . implode('.', [5, 7]),
        'mysql ' . implode('.', [5, 7]),
        'mysql' . '57-2k',
        'WP_FTS_MYSQL' . '57_IMAGE',
    ];
    $retiredTargetSources = [
        'runner' => $runner,
        'integration proof' => $integration,
        'acceptance contract' => $acceptance,
    ];
    foreach ($retiredTargetSources as $label => $source) {
        foreach ($retiredTokens as $retiredToken) {
            assert_true(
                !str_contains(strtolower($source), strtolower($retiredToken)),
                "{$label} must not retain the retired database target token"
            );
        }
    }
    assert_true(
        !str_contains($runner, '--performance-schema-max-sql-text-length=65536'),
        'the 1 GiB database lane must not restore the OOM-inducing 65,536-byte Performance Schema allocation'
    );
    foreach ([
        'const WP_FTS_WC_PERFORMANCE_SCHEMA_SQL_TEXT_BYTES = 32768;',
        'const WP_FTS_WC_PERFORMANCE_SCHEMA_HISTORY_LONG_EVENTS = 2048;',
        'const WP_FTS_WC_PERFORMANCE_SCHEMA_THREAD_HISTORY_EVENTS = 0;',
        'const WP_FTS_WC_PERFORMANCE_SCHEMA_DIGESTS = 0;',
        'const WP_FTS_WC_PERFORMANCE_SCHEMA_THREAD_INSTANCES = 128;',
        'const WP_FTS_WC_UNUSED_PERFORMANCE_SCHEMA_CAPACITIES = [',
        '@@performance_schema_events_statements_history_long_size AS performance_schema_history_long_events',
        '@@performance_schema_events_statements_history_size AS performance_schema_thread_history_events',
        '@@performance_schema_digests_size AS performance_schema_digests',
        '@@performance_schema_max_thread_instances AS performance_schema_thread_capacity',
        '@@performance_schema_accounts_size AS performance_schema_accounts',
        '@@performance_schema_hosts_size AS performance_schema_hosts',
        '@@performance_schema_users_size AS performance_schema_users',
        '@@performance_schema_session_connect_attrs_size AS performance_schema_session_connect_attrs',
        '@@performance_schema_events_stages_history_long_size AS performance_schema_stages_history_long',
        '@@performance_schema_events_stages_history_size AS performance_schema_stages_history',
        '@@performance_schema_events_transactions_history_long_size AS performance_schema_transactions_history_long',
        '@@performance_schema_events_transactions_history_size AS performance_schema_transactions_history',
        '@@performance_schema_events_waits_history_long_size AS performance_schema_waits_history_long',
        '@@performance_schema_events_waits_history_size AS performance_schema_waits_history',
        "'performance_schema_events_statements_history_long_size'",
        "'performance_schema_events_statements_history_size'",
        "'performance_schema_digests_size'",
        "'performance_schema_max_thread_instances'",
        "'performance_schema_unused_capacities'",
        "'performance_schema_statement_consumers'",
        "'performance_schema_instrumented_threads'",
        "'performance_schema_thread_instances_lost'",
    ] as $required) {
        assert_contains($required, $integration, "runtime evidence must bind the bounded Performance Schema configuration: {$required}");
    }
    $runtimeAssertion = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_assert_runtime');
    assert_contains(
        '(int) $row->performance_schema_sql_bytes === WP_FTS_WC_PERFORMANCE_SCHEMA_SQL_TEXT_BYTES',
        $runtimeAssertion,
        'runtime validation must require the exact complete-SQL retention width'
    );
    assert_contains(
        '(int) $row->performance_schema_history_long_events === WP_FTS_WC_PERFORMANCE_SCHEMA_HISTORY_LONG_EVENTS',
        $runtimeAssertion,
        'runtime validation must require the exact bounded global statement ring'
    );
    assert_contains(
        '(int) $row->performance_schema_thread_history_events === WP_FTS_WC_PERFORMANCE_SCHEMA_THREAD_HISTORY_EVENTS',
        $runtimeAssertion,
        'runtime validation must require zero unused per-thread history'
    );
    assert_contains(
        '(int) $row->performance_schema_digests === WP_FTS_WC_PERFORMANCE_SCHEMA_DIGESTS',
        $runtimeAssertion,
        'runtime validation must require zero unused digest summaries'
    );
    assert_contains(
        '(int) $row->performance_schema_thread_capacity === WP_FTS_WC_PERFORMANCE_SCHEMA_THREAD_INSTANCES',
        $runtimeAssertion,
        'runtime validation must require the exact bounded thread-instrumentation reserve'
    );
    assert_contains(
        'wp_fts_wc_unused_performance_schema_capacities($row) === WP_FTS_WC_UNUSED_PERFORMANCE_SCHEMA_CAPACITIES',
        $runtimeAssertion,
        'runtime validation must reject unused Performance Schema memory reservoirs'
    );
    assert_contains(
        '(int) $instrumentedThreads < WP_FTS_WC_PERFORMANCE_SCHEMA_THREAD_INSTANCES',
        $runtimeAssertion,
        'runtime validation must retain instrumented-thread headroom'
    );
    assert_contains(
        "SHOW GLOBAL STATUS LIKE 'Performance_schema_thread_instances_lost'",
        $runtimeAssertion,
        'runtime validation must read the server loss counter'
    );
    assert_contains(
        '&& (int) $lostThreads->Value === 0',
        $runtimeAssertion,
        'runtime validation must reject incomplete thread attribution'
    );
    $consumerState = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_performance_schema_consumer_state');
    foreach (['events_statements_current', 'events_statements_history', 'events_statements_history_long', 'ORDER BY NAME ASC'] as $required) {
        assert_contains($required, $consumerState, "runtime validation must read the exact statement-consumer state: {$required}");
    }
    foreach ([
        "'events_statements_current' => 'YES'",
        "'events_statements_history' => 'NO'",
        "'events_statements_history_long' => 'YES'",
    ] as $required) {
        assert_contains($required, $runtimeAssertion, "runtime validation must enforce the exact statement-consumer state: {$required}");
    }
    $validation = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_validate');
    assert_same(2, substr_count($validation, 'wp_fts_wc_assert_runtime();'), 'validation must reject lost instrumentation both before and after its measured work');
    assert_contains("'runtime_performance_schema_integrity'", $validation, 'validation evidence must retain a passing end-of-work runtime integrity gate');
    $coldPrepare = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_cold_prepare');
    $coldSample = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_cold_sample');
    assert_same(2, substr_count($coldPrepare, 'wp_fts_wc_assert_runtime();'), 'cold preparation must reject lost instrumentation before the first restart can erase it');
    assert_same(2, substr_count($coldSample, 'wp_fts_wc_assert_runtime();'), 'every cold sample must reject lost instrumentation before the next restart can erase it');
    $validationInventory = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_validation_inventory_matches');
    assert_contains("'runtime_performance_schema_integrity'", $validationInventory, 'the finalizer must reject validation evidence that omits end-of-work runtime integrity');
    foreach (['reapplies and verifies the', 'both entry and exit', 'before the next restart can erase'] as $required) {
        assert_contains($required, $acceptance, "acceptance must state restart-safe Performance Schema validation: {$required}");
    }
    $requestAttribution = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_performance_schema_request_events');
    foreach ([
        'count($rows) <= intdiv(WP_FTS_WC_PERFORMANCE_SCHEMA_HISTORY_LONG_EVENTS, 2)',
        "'history_long_capacity' => WP_FTS_WC_PERFORMANCE_SCHEMA_HISTORY_LONG_EVENTS",
        "'history_long_event_count' => count(\$rows)",
        "'history_long_headroom' => WP_FTS_WC_PERFORMANCE_SCHEMA_HISTORY_LONG_EVENTS - count(\$rows)",
        "if (\$fromConnectionStart)",
        "\$firstConnectionEvent === 1",
    ] as $required) {
        assert_contains($required, $requestAttribution, "request attribution must prove retained ring headroom: {$required}");
    }
    foreach ([
        "'documents' => 50000",
        "'documents' => 100000",
        "'prefix_terms' => 20000",
        "'hidden' => 5000",
        "'dirty' => 20000",
        "'rare' => 64",
        'const WP_FTS_WC_WARMUP_COUNT = 20;',
        'const WP_FTS_WC_WARM_SAMPLE_COUNT = 200;',
        'const WP_FTS_WC_COLD_SAMPLE_COUNT = 10;',
        'const WP_FTS_WC_IDLE_HTTP_REQUEST_COUNT = 100;',
    ] as $required) {
        assert_contains($required, $integration, "integration corpus should retain hard acceptance shape: {$required}");
    }
    $profile = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_profile');
    assert_same(3, substr_count($profile, "'iterations' => WP_FTS_WC_WARM_SAMPLE_COUNT"), 'all three required profiles should derive the same 200-sample warm sequence');
    assert_same(3, substr_count($profile, "'warmup' => WP_FTS_WC_WARMUP_COUNT"), 'all three required profiles should derive the same 20 warmups');
    $measure = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_measure_case');
    foreach (['$index < WP_FTS_WC_WARMUP_COUNT', '$index < WP_FTS_WC_WARM_SAMPLE_COUNT', "'warmup_count' => WP_FTS_WC_WARMUP_COUNT", "'sample_count' => count(\$durations)"] as $required) {
        assert_contains($required, $measure, "warm measurement should retain the fixed sequence and evidence: {$required}");
    }
    $caseGates = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_case_gates');
    foreach (['{$caseId}_warmup_count', '{$caseId}_warm_sample_count', 'WP_FTS_WC_WARM_SAMPLE_COUNT'] as $required) {
        assert_contains($required, $caseGates, "warm evidence consumer should enforce the fixed sequence: {$required}");
    }
    $cold = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_collect_cold_evidence');
    assert_contains('$count = WP_FTS_WC_COLD_SAMPLE_COUNT;', $cold, 'every profile should consume ten conditioned cold samples per case');
    assert_contains('$limit = max($limit, ceil((float) $warmP99 * 2.5));', $cold, 'cold latency should remain bounded against the same-run warm tail and its absolute floor');
    assert_true(!str_contains($cold, "=== '2k'"), 'the cold evidence consumer must not retain a 2k shortcut');
    $idle = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_idle_http');
    foreach ([
        '$iterations = WP_FTS_WC_IDLE_HTTP_REQUEST_COUNT;',
        'idle_http_request_count',
        'idle_http_sample_count',
        'relational-fts-idle-http-v3',
        "['50k', 'mysql'] => 1500.0",
        "['50k', 'mariadb'] => 5000.0",
        "['100k', 'mysql'] => 16000.0",
        "['100k', 'mariadb'] => 12000.0",
    ] as $required) {
        assert_contains($required, $idle, "idle HTTP evidence should enforce the fixed 100-request sequence: {$required}");
    }
    assert_true(!str_contains($idle, "=== '2k'"), 'the idle HTTP proof must not retain a 2k shortcut');
    $finalize = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_finalize');
    assert_contains("['idle_http_request_count', 'idle_http_sample_count', 'idle_http_errors', 'idle_http_p95_ms']", $finalize, 'the finalizer should require both idle HTTP cardinality gates');
    assert_contains("'relational-fts-idle-http-v3'", $finalize, 'the finalizer should reject an older idle HTTP artifact without fixed cardinality and profile-aware latency');
    assert_true(!str_contains($runner, 'if [[ "${PROFILE}" == "2k" ]]'), 'the runner must not reduce cold samples for the diagnostic profile');
    assert_contains('every profile runs the same 20 warmups, 200 warm', $acceptance, 'the written contract should cover every profile with one full sample sequence');
    assert_contains('samples, ten conditioned cold samples per case, and 100-request idle HTTP', $acceptance, 'the written contract should state the complete cold and idle sequence');
    record_check('relational worst-case fixed profile contract', 16);
});

test_case('relational scale gates keep terminal traversal and engine limits explicit', function (): void {
    $root = dirname(__DIR__, 2);
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');
    $terminal = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_terminal_streaming_oracle');
    $populate = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_populate_terminal_oracle');
    $caseGates = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_case_gates');
    $validate = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_validate');

    assert_contains("const WP_FTS_WC_TERMINAL_QUERY_TERM = 'visibilityprobe';", $integration, 'terminal exact and prefix traversal should share one construction-known term');
    assert_same(2, substr_count($terminal, "'query' => WP_FTS_WC_TERMINAL_QUERY_TERM"), 'both terminal cases should use the shared visible-band term');
    assert_same(3, substr_count($populate, 'WP_FTS_WC_TERMINAL_QUERY_TERM'), 'the independent exact and prefix oracle should use the same term and prefix bound');
    foreach ([
        "\$expectedRows = (int) \$manifest['profile']['dirty'] + 200;",
        "terminal_{\$caseId}_uncapped_cardinality\", \"{\$expectedRows} (> {\$minimumRows})\"",
        "\$case['expected_rows'] === \$expectedRows",
        "['50k', '100k'], true) ? 5000 : 40",
    ] as $required) {
        assert_contains($required, $terminal, "terminal traversal should retain its explicit scale boundary: {$required}");
    }
    foreach ([
        "str_contains(\$engine, 'mariadb') => 'mariadb'",
        "['common_or' => 2000.0, 'max_valid_or_prefix' => 3250.0, 'prefix_fanout' => 2250.0, 'all_packs' => 1800.0]",
        "['common_or' => 2250.0, 'max_valid_or_prefix' => 3500.0, 'prefix_fanout' => 2500.0, 'all_packs' => 2000.0]",
        "['common_or' => 6500.0, 'max_valid_or_prefix' => 8500.0, 'prefix_fanout' => 7000.0, 'all_packs' => 6500.0]",
        "['common_or' => 7000.0, 'max_valid_or_prefix' => 9000.0, 'prefix_fanout' => 7500.0, 'all_packs' => 7000.0]",
        "['common_or' => 5000.0, 'max_valid_or_prefix' => 12000.0, 'prefix_fanout' => 5500.0, 'all_packs' => 5000.0]",
        "['common_or' => 6500.0, 'max_valid_or_prefix' => 14000.0, 'prefix_fanout' => 6500.0, 'all_packs' => 6500.0]",
        "['common_or' => 500.0, 'prefix_fanout' => 600.0]",
        "['common_or' => 550.0, 'prefix_fanout' => 650.0]",
        "\$serverRowsLimit = \$engineFamily === 'mariadb'",
        "\$sortMergePassesLimit = \$profileName === '100k' ? 8 : 1;",
    ] as $required) {
        assert_contains($required, $caseGates, "scale limits should retain their measured engine/profile boundary: {$required}");
    }
    $finalize = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_finalize');
    foreach ([
        "['50k', 'mysql'] => [10000.0, 15000.0]",
        "['50k', 'mariadb'] => [25000.0, 35000.0]",
        "['100k', 'mysql'] => [40000.0, 60000.0]",
        "['100k', 'mariadb'] => [90000.0, 120000.0]",
        "wp_fts_wc_gate('concurrent_p95_ms', \"<= {\$concurrentP95Limit}\"",
        "wp_fts_wc_gate('concurrent_p99_ms', \"<= {\$concurrentP99Limit}\"",
    ] as $required) {
        assert_contains($required, $finalize, "concurrency limits should retain their engine/profile boundary: {$required}");
    }
    assert_contains("'storage_total_bytes', '<= 1342177280'", $validate, 'the total storage ceiling should retain the declared 1.25-GiB boundary');
    foreach (['600 / 10,200 / 20,200 visible rows', 'MySQL 8.0 p95 / p99', 'MariaDB 10.11 p95 / p99', '| 50k | <=10 / <=15 seconds | <=25 / <=35 seconds |', '| 100k | <=40 / <=60 seconds | <=90 / <=120 seconds |', '<=1.25 GiB total'] as $required) {
        assert_contains($required, $acceptance, "the written scale contract should retain: {$required}");
    }
});

test_case('relational worst-case corpus starts with an empty configured post-type namespace', function (): void {
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $setup = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_setup');
    $settings = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_enable_search_settings');
    $oracle = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_oracle_proof');

    assert_contains("const WP_FTS_WC_INDEX_POST_TYPES = ['post', 'page', 'attachment'];", $integration, 'the deterministic corpus and public search scope should share one post-type list');
    assert_contains('count(WP_FTS_WC_INDEX_POST_TYPES)', $setup, 'fixture cleanup should prepare one placeholder for every configured post type');
    assert_contains('...WP_FTS_WC_INDEX_POST_TYPES', $setup, 'fixture cleanup should bind the shared configured post types');
    assert_contains('ORDER BY ID ASC', $setup, 'fixture cleanup should enumerate deterministic post IDs');
    assert_contains("'configured post-type fixture namespace after cleanup'", $setup, 'fixture cleanup should count its exact configured namespace after deletion');
    assert_contains('wp_fts_wc_assert($remainingIndexedPosts === 0', $setup, 'fixture setup should fail while any configured post type remains');
    assert_true(!str_contains($setup, "post_type = 'post'"), 'fixture cleanup must not leave stock pages or attachments outside its oracle corpus');
    assert_contains("\$settings['index_post_types'] = WP_FTS_WC_INDEX_POST_TYPES;", $settings, 'public search settings should use the same post-type list as fixture cleanup');
    assert_contains("\$firstOrdinal = (int) \$profile['hidden'] + 1;", $oracle, 'the oracle should start after the deliberately hidden corpus prefix');
    assert_contains("\$lastOrdinal = min((int) \$profile['documents'], \$firstOrdinal + 1999);", $oracle, 'the oracle should retain its 2,000-post upper bound');
    assert_contains("\$firstId = (int) \$manifest['first_post_id'] + \$firstOrdinal - 1;", $oracle, 'the oracle ID range should start at its first public ordinal');
    assert_contains("\$dateBefore = gmdate('Y-m-d H:i:s', 1704067200 + \$lastOrdinal);", $oracle, 'the production date cutoff should end at the same ordinal as the oracle range');
});

test_case('relational worst-case uses the strict search option vocabulary at each API boundary', function (): void {
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $mutation = (string) file_get_contents(dirname(__DIR__) . '/integration/mutation-fence-concurrency.php');

    foreach ([
        'wp_fts_wc_identity_bytes_proof',
        'wp_fts_wc_deleted_identity_cursor_proof',
        'wp_fts_wc_oracle_proof',
        'wp_fts_wc_terminal_streaming_oracle',
        'wp_fts_wc_cursor_security_proof',
        'wp_fts_wc_case_definitions',
    ] as $function) {
        $source = wp_fts_wc_contract_function_source($integration, $function);
        assert_contains("'query_lang' => 'en'", $source, "{$function} should use the component query-language option");
        assert_contains("'post_types' => ['post']", $source, "{$function} should use the bounded component post-type list");
        assert_contains("'post_statuses' => ['publish']", $source, "{$function} should use the bounded component status list");
        foreach (["'lang' =>", "'post_type' =>", "'post_status' =>", "'now_gmt' =>"] as $obsolete) {
            assert_true(!str_contains($source, $obsolete), "{$function} must not pass the removed component option {$obsolete}");
        }
    }

    $publicAdapter = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_public_adapter_proof');
    assert_contains("\$definition['options']['lang'] = \$definition['options']['query_lang'];", $publicAdapter, 'the WordPress adapter proof should translate the component language name explicitly');
    assert_contains("unset(\$definition['options']['query_lang']);", $publicAdapter, 'the WordPress adapter proof should remove the component-only language name');
    assert_contains("unset(\$definition['options']['explain']);", $publicAdapter, 'the WordPress adapter proof should remove the component-only plan switch');
    $httpSearchUrl = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_http_search_url');
    assert_contains("\$options['query_lang'] ?? 'en'", $httpSearchUrl, 'the REST proof should read the component case language explicitly');
    assert_contains('PHP_QUERY_RFC3986', $httpSearchUrl, 'the REST proof should publish cURL-safe encoded request URLs');

    foreach ([
        wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_dependency_lob'),
        wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_dependency_search_ids'),
        wp_fts_wc_contract_function_source($mutation, 'wp_fts_mutation_proof_production_worker_cas'),
    ] as $source) {
        assert_contains("'post_types' => ['post']", $source, 'production PHP searches should use the public post-type list');
        assert_contains("'post_statuses' => ['publish']", $source, 'production PHP searches should use the public status list');
    }

    foreach ([
        "'source' => 'wc-dependency-lob-accepted-successor'" => 'wc-dependency-lob-accepted-successor',
        "'wc-mixed-' . \$caseId . '-document'" => 'wc-mixed-exhausted-corpus-scope-document',
        "'wc-mixed-' . \$caseId . '-scope'" => 'wc-mixed-exhausted-corpus-scope-scope',
        "'wc-mixed-' . \$caseId . '-cleanup'" => 'wc-mixed-exhausted-corpus-scope-cleanup',
        "'wc-composed-cron-later-existing-event'" => 'wc-composed-cron-later-existing-event',
        "'wc-composed-later-event-successor'" => 'wc-composed-later-event-successor',
    ] as $expression => $longestValue) {
        assert_contains($expression, $integration, "the real proof should retain its bounded worker source {$longestValue}");
        assert_true(strlen($longestValue) <= 40, "the real proof worker source {$longestValue} should fit the public 40-byte bound");
        assert_same(strlen($longestValue), strspn($longestValue, 'abcdefghijklmnopqrstuvwxyz0123456789_-'), "the real proof worker source {$longestValue} should use the public identifier alphabet");
    }

});

test_case('relational worst-case records measured mutation SQL and effective pinned resources', function (): void {
    $root = dirname(__DIR__, 2);
    $runnerPath = $root . '/tools/run-relational-fts-worst-case.sh';
    $runner = (string) file_get_contents($runnerPath);
    $mutation = (string) file_get_contents(dirname(__DIR__) . '/integration/mutation-fence-concurrency.php');
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');

    foreach ([
        'statement_marker()',
        'statements_since($marker)',
        "'statement_evidence_schema' => 'relational-fts-mutation-statements-v1'",
        "'fence_boundary_statement_counts' => \$fenceStatementCounts",
        "'promotion_boundary_statement_counts' => \$promotionStatementCounts",
        "'boundary_statement_evidence' => \$boundaryEvidence",
        "'schema' => 'relational-fts-mutation-generation-cas-v4'",
        "'production_worker_cas' => \$productionWorker",
        "'cross_process_foreground_guard' => \$crossProcessGuardEvidence",
        "'schema' => 'relational-fts-cross-process-owner-guard-v1'",
        "'--foreground-owner-holder'",
        'wp_fts_mutation_proof_start_foreground_holder($prefix)',
        'wp_fts_mutation_proof_kill_foreground_holder(',
        "proc_terminate(\$process, 9)",
        "'optimizer_noise_rows' => 512",
        "'busy_claimed_post_ids' => array_column(\$claimsBehindGuard, 'post_id')",
        "'free_claimed_post_ids' => array_column(\$recoveredGuardedClaims, 'post_id')",
        "\$database->dbh->query('EXPLAIN ' . \$sql)",
        "'base_full_scan_count' => \$baseFullScans",
        "'sql_sha256' => hash('sha256', \$sql)",
        "'redacted_sql' => substr(\$redacted, 0, 2048)",
        'wp_fts_mutation_proof_production_worker_cas()',
        "'analyzed' => (int) (\$summary['analyzed'] ?? -1)",
        "'committed' => (int) (\$summary['committed'] ?? -1)",
        "'queue_processed' => (int) (\$summary['queue_processed'] ?? -1)",
        "'atomic_ack_generation_cas_valid' => \$ackCasValid",
        "'atomic_ack_excludes_writer_lease' => \$ackExcludesWriterLease",
        "'writer_lease_released_after_commit' => \$writerLeaseReleasedAfterCommit",
        "'writer_lease_delete_cas_valid' => \$writerLeaseDeleteCasValid",
        'wp_fts_mutation_proof_epoch_upsert_valid',
        'wp_fts_mutation_proof_ack_generation_cas_valid',
        'wp_fts_mutation_proof_ack_constant_driver_valid',
        'wp_fts_mutation_proof_writer_lease_delete_valid',
        'wp_fts_mutation_proof_worker_sql_evidence',
        'relational-fts-mutation-worker-sql-v1',
        'MySQL/MariaDB executable comments are',
        "'terms' => (int) \$wpdb->get_var",
        "\$result['fixture_cleanup'] = \$cleanup",
    ] as $required) {
        assert_contains($required, $mutation, "mutation proof should retain measured bounded statement evidence: {$required}");
    }
    assert_true(
        !str_contains($mutation, "'fence_statements_per_boundary' => 1"),
        'fence statement count must be derived from the executed SQL log, not asserted as a literal'
    );
    assert_true(
        !str_contains($mutation, "'promotion_statements_per_boundary' => 1"),
        'promotion statement count must be derived from the executed SQL log, not asserted as a literal'
    );
    foreach ([
        'wp_fts_mutation_proof_assert',
        'wp_fts_mutation_proof_canonicalize',
        'wp_fts_mutation_proof_canonical_hash',
        'wp_fts_mutation_proof_worker_sql_evidence',
        'wp_fts_mutation_proof_ascii_hex_string',
        'wp_fts_mutation_proof_transaction_control',
        'wp_fts_mutation_proof_transaction_control_tokens',
        'wp_fts_mutation_proof_ack_transaction_sequence',
        'wp_fts_mutation_proof_epoch_upsert_valid',
        'wp_fts_mutation_proof_sql_single_values_tuple_before_on_duplicate',
        'wp_fts_mutation_proof_ack_constant_driver_valid',
        'wp_fts_mutation_proof_ack_generation_cas_valid',
        'wp_fts_mutation_proof_writer_lease_insert_payload',
        'wp_fts_mutation_proof_writer_lease_delete_valid',
        'wp_fts_mutation_proof_serialized_writer_payload_valid',
        'wp_fts_mutation_proof_sql_is_single_dml',
        'wp_fts_mutation_proof_sql_tokens_are_single_dml',
        'wp_fts_mutation_proof_sql_tokens_are_exact_keywords',
        'wp_fts_mutation_proof_sql_token_is_keyword',
        'wp_fts_mutation_proof_sql_token_is_symbol',
        'wp_fts_mutation_proof_sql_token_is_positive_decimal',
        'wp_fts_mutation_proof_sql_identifier_matches',
        'wp_fts_mutation_proof_sql_token_sequence_count',
        'wp_fts_mutation_proof_sql_token_sequence_is_tail',
        'wp_fts_mutation_proof_sql_token_value_count',
        'wp_fts_mutation_proof_sql_string_value_count',
        'wp_fts_mutation_proof_sql_comment_count',
        'wp_fts_mutation_proof_sql_tokens',
        'wp_fts_mutation_proof_sql_read_quoted',
        'wp_fts_mutation_proof_sql_skip_line_comment',
        'wp_fts_mutation_proof_sql_is_space',
        'wp_fts_mutation_proof_sql_is_symbol_character',
    ] as $functionName) {
        if (!function_exists($functionName)) {
            eval(wp_fts_wc_contract_function_source($mutation, $functionName));
        }
    }
    $validAckSequence = [
        'SELECT writer lease',
        'START TRANSACTION',
        'UPDATE bounded rows',
        'DELETE /* wp_fts:atomic-worker-ack */ FROM work',
        'INSERT meta:search-epoch ON DUPLICATE KEY UPDATE generation=generation+1',
        'COMMIT',
        'DELETE exact writer lease',
    ];
    assert_true(
        wp_fts_mutation_proof_ack_transaction_sequence($validAckSequence, 3)['valid'],
        'the exact START/write/ACK/epoch/COMMIT sequence should validate'
    );
    $invalidAckSequences = [
        'intermediate COMMIT' => [['START TRANSACTION', 'UPDATE bounded rows', 'COMMIT', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 3],
        'intermediate ROLLBACK' => [['START TRANSACTION', 'UPDATE bounded rows', 'ROLLBACK', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 3],
        'nested START' => [['START TRANSACTION', 'UPDATE bounded rows', 'START TRANSACTION', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 3],
        'savepoint control' => [['START TRANSACTION', 'SAVEPOINT hidden', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 2],
        'ALTER TABLE' => [['START TRANSACTION', 'UPDATE bounded rows', 'ALTER TABLE work ADD hidden INT', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 3],
        'LOCK TABLES' => [['START TRANSACTION', 'UPDATE bounded rows', 'LOCK TABLES work WRITE', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 3],
        'SET SESSION autocommit' => [['START TRANSACTION', 'UPDATE bounded rows', 'SET SESSION autocommit=1', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 3],
        'SET @@session.autocommit' => [['START TRANSACTION', 'UPDATE bounded rows', 'SET @@session.autocommit=1', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 3],
        'leading-comment COMMIT' => [['START TRANSACTION', 'UPDATE bounded rows', "/* hidden */ -- boundary\nCOMMIT", 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 3],
        'multi-statement DML' => [['START TRANSACTION', 'UPDATE bounded rows; DELETE FROM work', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 2],
        'non-adjacent COMMIT' => [['START TRANSACTION', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'SELECT after epoch', 'COMMIT'], 1],
        'missing START' => [['DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 0],
        'prior completed transaction' => [['START TRANSACTION', 'COMMIT', 'START TRANSACTION', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 3],
    ];
    foreach ($invalidAckSequences as $description => [$queries, $ackIndex]) {
        assert_true(
            !wp_fts_mutation_proof_ack_transaction_sequence($queries, $ackIndex)['valid'],
            "atomic acknowledgement sequence validation must reject {$description}"
        );
    }

    $canonicalJobKey = 'post:7';
    $constantDriver = "SELECT bounded_claims.*
FROM (SELECT '{$canonicalJobKey}' AS job_key, '0123456789abcdef0123456789abcdef' AS claim_token, 2 AS claimed_generation, 2 AS generation) bounded_claims
LIMIT 1";
    $ackSql = "DELETE /* wp_fts:atomic-worker-ack */ work_row
FROM ({$constantDriver}) claim_driver
STRAIGHT_JOIN wp_fts_work work_row
ON work_row.job_key = claim_driver.job_key
AND work_row.claim_token = claim_driver.claim_token
AND work_row.claimed_generation = claim_driver.claimed_generation
AND work_row.generation = claim_driver.generation";
    assert_true(wp_fts_mutation_proof_ack_generation_cas_valid($ackSql, $canonicalJobKey, 'wp_fts_work'), 'the exact four generation predicates should validate');
    foreach (['job_key', 'claim_token', 'claimed_generation', 'generation'] as $field) {
        $wrongRhs = str_replace("work_row.{$field} = claim_driver.{$field}", "work_row.{$field} = claim_driver.wrong_{$field}", $ackSql);
        assert_true(!wp_fts_mutation_proof_ack_generation_cas_valid($wrongRhs, $canonicalJobKey, 'wp_fts_work'), "the generation CAS must reject a wrong {$field} RHS");
    }
    foreach ([
        $ackSql . ' OR 1=1',
        $ackSql . ' AND 1=1',
        str_replace(
            $constantDriver,
            "SELECT job_key,claim_token,claimed_generation,generation FROM wp_fts_work WHERE job_key='{$canonicalJobKey}'",
            $ackSql
        ),
    ] as $broadenedAckSql) {
        assert_true(
            !wp_fts_mutation_proof_ack_generation_cas_valid($broadenedAckSql, $canonicalJobKey, 'wp_fts_work'),
            'the generation CAS must reject a broadened predicate or live-row driver'
        );
    }

    $epochSql = "INSERT INTO wp_fts_work (job_key,generation) VALUES ('meta:search-epoch',1) ON DUPLICATE KEY UPDATE generation = generation + 1";
    assert_true(wp_fts_mutation_proof_epoch_upsert_valid($epochSql, 'wp_fts_work'), 'the exact singleton epoch UPSERT should validate');
    foreach ([
        str_replace('wp_fts_work', 'wrong_work', $epochSql),
        str_replace("'meta:search-epoch'", "'meta:wrong'", $epochSql),
        str_replace('generation = generation + 1', 'generation = generation', $epochSql),
        str_replace('VALUES', "SELECT 'meta:search-epoch',1 UNION ALL SELECT 'meta:search-epoch',1", $epochSql),
    ] as $invalidEpochSql) {
        assert_true(!wp_fts_mutation_proof_epoch_upsert_valid($invalidEpochSql, 'wp_fts_work'), 'epoch proof must reject a broadened or impersonated UPSERT');
    }

    $leasePayload = serialize([
        'token' => '0123456789abcdef01234567',
        'mode' => 'manual',
        'started_at' => 100,
        'heartbeat_at' => 100,
        'expires_at' => 400,
        'renewals' => 0,
    ]);
    $leaseInsert = "INSERT IGNORE INTO wp_options (option_name,option_value,autoload) SELECT '_wp_fts_index_lock','{$leasePayload}','no'";
    assert_same($leasePayload, wp_fts_mutation_proof_writer_lease_insert_payload($leaseInsert, 'wp_options', '_wp_fts_index_lock'), 'the exact acquired lease payload should be recovered');
    $leaseDelete = "DELETE FROM wp_options WHERE option_name = '_wp_fts_index_lock' AND option_value = '{$leasePayload}'";
    assert_true(wp_fts_mutation_proof_writer_lease_delete_valid($leaseDelete, 'wp_options', '_wp_fts_index_lock', $leasePayload), 'the exact post-COMMIT lease CAS should validate');
    foreach ([
        "DELETE FROM wp_options WHERE option_name = option_name AND option_value = '{$leasePayload}'",
        "DELETE FROM wp_options WHERE option_name = '_wp_fts_index_lock' AND option_value = option_value",
        "DELETE FROM wp_options WHERE option_name = '_wp_fts_index_lock' AND option_value = 'a:0:{}'",
    ] as $invalidLeaseDelete) {
        assert_true(!wp_fts_mutation_proof_writer_lease_delete_valid($invalidLeaseDelete, 'wp_options', '_wp_fts_index_lock', $leasePayload), 'writer lease CAS must reject tautological or wrong RHS predicates');
    }

    foreach (['wp_fts_wc_canonicalize', 'wp_fts_wc_canonical_hash', 'wp_fts_wc_is_ascii_hex', 'wp_fts_wc_is_lowercase_sha256', 'wp_fts_wc_mutation_worker_sql_evidence_valid'] as $functionName) {
        if (!function_exists($functionName)) {
            eval(wp_fts_wc_contract_function_source($integration, $functionName));
        }
    }
    $workerStatements = ['START TRANSACTION', 'COMMIT'];
    $workerSqlEvidence = wp_fts_mutation_proof_worker_sql_evidence($workerStatements);
    assert_same(
        ['schema', 'statement_count', 'total_bytes', 'max_statement_bytes', 'statements', 'evidence_sha256'],
        array_keys($workerSqlEvidence),
        'worker SQL evidence should retain its exact independently verifiable envelope'
    );
    assert_same(2, $workerSqlEvidence['statement_count'] ?? null, 'worker SQL evidence should retain every captured statement');
    assert_same(
        wp_fts_wc_canonical_hash(array_slice($workerSqlEvidence, 0, 5, true)),
        $workerSqlEvidence['evidence_sha256'] ?? null,
        'worker SQL evidence should bind its exact ordered statements and limits'
    );
    foreach ([
        'statement count' => array_fill(0, 33, 'SELECT 1'),
        'per-statement bytes' => [str_repeat('x', 1048577)],
        'total bytes' => array_fill(0, 5, str_repeat('x', 1048576)),
    ] as $context => $invalidStatements) {
        $caught = null;
        try {
            wp_fts_mutation_proof_worker_sql_evidence($invalidStatements);
        } catch (RuntimeException $error) {
            $caught = $error;
        }
        assert_true($caught instanceof RuntimeException, "worker SQL evidence must reject its {$context} cap");
    }

    $workerEnvelope = [
        'captured_worker_statement_count' => 2,
        'sql_evidence' => $workerSqlEvidence,
        'atomic_ack_statement_count' => 1,
        'atomic_ack_sql_bytes' => strlen($workerStatements[1]),
        'atomic_ack_sql_sha256' => hash('sha256', $workerStatements[1]),
    ];
    assert_true(wp_fts_wc_mutation_worker_sql_evidence_valid($workerEnvelope), 'finalizer should accept exact lossless worker SQL evidence');
    foreach (['sql', 'index', 'self_hash', 'ack_hash'] as $tamper) {
        $mutatedEnvelope = $workerEnvelope;
        if ($tamper === 'sql') {
            $mutatedEnvelope['sql_evidence']['statements'][0]['sql'] .= ' ';
        } elseif ($tamper === 'index') {
            $mutatedEnvelope['sql_evidence']['statements'][0]['index'] = 1;
        } elseif ($tamper === 'self_hash') {
            $mutatedEnvelope['sql_evidence']['evidence_sha256'] = str_repeat('0', 64);
        } else {
            $mutatedEnvelope['atomic_ack_sql_sha256'] = str_repeat('0', 64);
        }
        assert_true(!wp_fts_wc_mutation_worker_sql_evidence_valid($mutatedEnvelope), "finalizer should reject {$tamper} worker SQL evidence tampering");
    }

    foreach ([
        'image overrides are forbidden in clean acceptance lanes',
        'EXPECTED_DB_IMAGE',
        'WP_IMAGE=',
        'WPCLI_RUN_IMAGE=',
        'capture_compose DB_EFFECTIVE_CGROUP database-cgroup-probe',
        'capture_compose WP_EFFECTIVE_CGROUP wordpress-cgroup-probe',
        'capture_host WPCLI_EFFECTIVE_CGROUP wpcli-cgroup-probe',
        'relational-fts-resource-verification-v1',
        'expected_manifest_digest',
        'actual_manifest_digests',
        'actual_image_id',
        'running_container_image_id',
        'container_matches_inspected_image',
        'effective_cgroup',
        'matches_expected',
        '"status" => $gates === [] ? "PASS" : "FAIL"',
        '"isolated_boundaries_sha256" => $argv[24]',
        'WP_FTS_WP_PATH=/var/www/html',
        'package-reproducibility.json',
        'relational-fts-package-reproducibility-v1',
        'independent_build_zip_bytes_identical',
        'independent_build_entry_manifests_identical',
        'package_builds_use_fresh_composer_state',
        'host-provided-unthrottled',
        'php "${SOURCE_ROOT}/indexer/tools/build-release-zip.php"',
    ] as $required) {
        assert_contains($required, $runner, "runner should retain fail-closed resource evidence: {$required}");
    }
    assert_true(!str_contains($runner, 'BASELINE_ROOT') && !str_contains($runner, 'BASELINE_COMMIT'), 'the runner must not build or execute a second runtime');

    foreach ([
        'relational-fts-resources-v2',
        'relational-fts-database-cgroup-memory-v2',
        'relational-fts-wordpress-cgroup-memory-v3',
        'relational-fts-resource-verification-v1',
        'resource_artifact_contract',
        'resource_artifact_full_binding',
        'resource_{$role}_pinned_image',
        'resource_{$role}_effective_cgroup',
        'resource_package_reproducibility',
        'resource_latency_scope',
        'resource_host_environment',
        'resource_database_memory_checkpoint_contract',
        'resource_database_memory_checkpoint_inventory',
        'resource_database_pre_corpus_peak',
        'resource_database_whole_run_peak_recorded',
        'resource_database_zero_oom',
        'resource_wordpress_cgroup_memory_complete',
        'resource_wordpress_cgroup_peak',
        'resource_wordpress_cgroup_no_oom',
        'resource_gate_inventory',
        'wp_fts_wc_database_memory_evidence_is_exact',
        'wp_fts_wc_wordpress_memory_evidence_is_exact',
        'wp_fts_wc_package_reproducibility_is_exact',
        'package_toolchain_recorded',
        'composerStateValid',
        "str_starts_with((string) \$toolchain['composer_version'], 'Composer version 2.9.8 ')",
        'recorded non-acceptance image override',
        '!$requireAcceptanceDigest || $matchesExpected',
        "\$image['running_container_image_id']",
        'relational-fts-mutation-generation-cas-v4',
        'relational-fts-mutation-statements-v1',
        'relational-fts-mutation-worker-sql-v1',
        'mutation_boundary_statement_order',
        'mutation_boundary_statement_counts',
        'mutation_boundary_statement_payloads',
        'relational-fts-cross-process-owner-guard-v1',
        'mutation_fence_real_database_cross_process_guard',
        'mutation_artifact_cross_process_guard_contract',
        'wp_fts_wc_mutation_cross_process_guard_valid',
        'wp_fts_wc_mutation_guard_operation_valid',
        'mutation_artifact_preliminary_identity',
        'mutation_fence_production_worker_canonical_generation',
        'mutation_fence_production_worker_canonical_visible',
        'mutation_fence_production_worker_atomic_ack',
        'mutation_fence_production_worker_sql_evidence',
        'mutation_artifact_production_worker_sql_evidence_contract',
        'wp_fts_wc_mutation_worker_sql_evidence_valid',
        'mutation_fence_production_worker_fixture_restored',
        'mutation_fence_real_database_handoff_contract',
        'mutation_artifact_handoff_contract',
        "str_repeat('a', 32)",
        "str_repeat('d', 32)",
    ] as $required) {
        assert_contains($required, $integration, "final evidence should retain exact measured mutation/resource validation: {$required}");
    }
    $resourceGates = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_resource_artifact_gates');
    foreach ([
        "['common_or', 'max_valid_or_prefix', 'rare_anchor_and', 'prefix_fanout']",
        "\$actualMemoryCheckpointLabels === \$expectedMemoryCheckpointLabels",
        "\$preCorpusMemory['peak_bytes'] <= 805306368",
        "\$wholeRunHeadroom === 1073741824 - \$wholeRunPeak",
        "(\$databaseMemory['oom_events'] ?? null) === 0",
        "(\$databaseMemory['oom_kill_events'] ?? null) === 0",
        "\$wordpressWholeRunHeadroom === 536870912 - \$wordpressWholeRunPeak",
        "(\$wordpressFinalMemory['peak_bytes'] ?? null) === \$wordpressWholeRunPeak",
        "(\$wordpressMemory['max_limit_events'] ?? null) === 0",
        "(\$wordpressMemory['oom_events'] ?? null) === 0",
        "(\$wordpressMemory['oom_kill_events'] ?? null) === 0",
    ] as $required) {
        assert_contains($required, $resourceGates, "final resource gates must retain measured database memory evidence: {$required}");
    }
    $memoryContract = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_database_memory_evidence_is_exact');
    foreach ([
        "\$actualLabels === \$expectedLabels",
        "count(\$checkpoints) !== count(\$expectedLabels)",
        "(\$first['checkpoint'] ?? null) === 'pre-corpus'",
        "(\$final['checkpoint'] ?? null) === 'final-workload'",
        "\$checkpoint['peak_bytes'] < 1",
        "(\$memory['whole_run_peak_bytes'] ?? null) === \$wholeRunPeak",
        "(\$memory['oom_events'] ?? null) === max(\$oomEvents)",
        "(\$memory['oom_kill_events'] ?? null) === max(\$oomKills)",
        'count($rawParts) === 6',
        '$unsigned($rawParts[1] ?? null)',
        "hash_equals(hash('sha256', \$raw), \$checkpoint['raw_sha256'])",
    ] as $required) {
        assert_contains($required, $memoryContract, "restart-safe database memory contract must retain every segment maximum: {$required}");
    }
    $wordpressMemoryContract = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_wordpress_memory_evidence_is_exact');
    foreach ([
        "['pre-corpus', 'final-workload']",
        'relational-fts-wordpress-cgroup-memory-v3',
        '536870912',
        "preg_match('/\\A[0-9a-f]{64}\\z/D', \$containerId)",
        "(\$checkpoint['container_id'] ?? null) !== \$containerId",
        "array_keys(\$containerLifecycle) !== ['started_at', 'host_pid', 'restart_count']",
        "(\$checkpoint['container_started_at'] ?? null) !== \$containerLifecycle['started_at']",
        "(\$checkpoint['container_host_pid'] ?? null) !== \$containerLifecycle['host_pid']",
        "(\$checkpoint['container_restart_count'] ?? null) !== \$containerLifecycle['restart_count']",
        "(\$final['peak_bytes'] ?? null) === \$wholeRunPeak",
        '536870912 - $wholeRunPeak',
        'max($limitEvents) === 0',
        'max($oomEvents) === 0',
        'max($oomKills) === 0',
        'count($rawParts) === 10',
        '$unsigned($rawParts[8] ?? null)',
        "hash_equals(hash('sha256', \$raw), \$checkpoint['raw_sha256'])",
    ] as $required) {
        assert_contains($required, $wordpressMemoryContract, "persistent WordPress memory contract must retain identity, peak, and zero-event proof: {$required}");
    }
    $cgroupContract = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_resource_cgroup_is_exact');
    foreach ([
        'count($rawParts) === 5',
        "\$rawVersion === 'v1'",
        '$rawSwap - $rawMemory',
        "hash_equals(hash('sha256', \$raw), \$cgroup['raw_sha256'])",
    ] as $required) {
        assert_contains($required, $cgroupContract, "effective cgroup contract must bind the retained raw probe: {$required}");
    }
    foreach (['wp_fts_wc_is_ascii_hex', 'wp_fts_wc_is_lowercase_sha256', 'wp_fts_wc_database_memory_evidence_is_exact', 'wp_fts_wc_wordpress_memory_evidence_is_exact', 'wp_fts_wc_resource_cgroup_is_exact'] as $functionName) {
        if (!function_exists($functionName)) {
            eval(wp_fts_wc_contract_function_source($integration, $functionName));
        }
    }
    if (!defined('WP_FTS_WC_COLD_SAMPLE_COUNT')) {
        define('WP_FTS_WC_COLD_SAMPLE_COUNT', 10);
    }
    $cgroupRaw = "v2\t100000\t100000\t536870912\t0";
    $cgroupFixture = [
        'version' => 'v2',
        'cpu' => ['quota_us' => 100000, 'period_us' => 100000, 'effective_cpus' => 1.0, 'matches_expected' => true],
        'memory' => ['max_bytes' => 536870912, 'matches_expected' => true],
        'swap' => ['raw_max_bytes' => 0, 'effective_max_bytes' => 0, 'matches_expected' => true],
        'raw' => $cgroupRaw,
        'raw_sha256' => hash('sha256', $cgroupRaw),
        'matches_expected' => true,
    ];
    assert_true(wp_fts_wc_resource_cgroup_is_exact($cgroupFixture, 536870912), 'the exact effective cgroup raw probe fixture should validate');
    $cgroupMutations = [
        'missing raw probe' => static function (array &$cgroup): void { unset($cgroup['raw']); },
        'empty raw probe' => static function (array &$cgroup): void { $cgroup['raw'] = ''; },
        'raw probe digest mismatch' => static function (array &$cgroup): void { $cgroup['raw_sha256'] = str_repeat('0', 64); },
        'structured quota disagrees with raw' => static function (array &$cgroup): void { $cgroup['cpu']['quota_us']++; },
        'rehash cannot hide changed raw memory' => static function (array &$cgroup): void {
            $cgroup['raw'] = "v2\t100000\t100000\t536870913\t0";
            $cgroup['raw_sha256'] = hash('sha256', $cgroup['raw']);
        },
        'raw probe has an extra field' => static function (array &$cgroup): void {
            $cgroup['raw'] .= "\t0";
            $cgroup['raw_sha256'] = hash('sha256', $cgroup['raw']);
        },
        'raw probe is missing a field' => static function (array &$cgroup): void {
            $cgroup['raw'] = "v2\t100000\t100000\t536870912";
            $cgroup['raw_sha256'] = hash('sha256', $cgroup['raw']);
        },
    ];
    foreach ($cgroupMutations as $description => $mutate) {
        $mutated = $cgroupFixture;
        $mutate($mutated);
        assert_true(!wp_fts_wc_resource_cgroup_is_exact($mutated, 536870912), "effective cgroup evidence must reject {$description}");
    }
    $v1CgroupRaw = "v1\t100000\t100000\t536870912\t536870912";
    $v1CgroupFixture = [
        'version' => 'v1',
        'cpu' => ['quota_us' => 100000, 'period_us' => 100000, 'effective_cpus' => 1.0, 'matches_expected' => true],
        'memory' => ['max_bytes' => 536870912, 'matches_expected' => true],
        'swap' => ['raw_max_bytes' => 536870912, 'effective_max_bytes' => 0, 'matches_expected' => true],
        'raw' => $v1CgroupRaw,
        'raw_sha256' => hash('sha256', $v1CgroupRaw),
        'matches_expected' => true,
    ];
    assert_true(wp_fts_wc_resource_cgroup_is_exact($v1CgroupFixture, 536870912), 'cgroup v1 raw memsw must derive zero effective swap from raw memsw minus memory');
    $databaseLabels = ['pre-corpus', 'post-frontier', 'post-reindex'];
    foreach (['common_or', 'max_valid_or_prefix', 'rare_anchor_and', 'prefix_fanout'] as $caseId) {
        for ($sample = 0; $sample < WP_FTS_WC_COLD_SAMPLE_COUNT; $sample++) {
            $databaseLabels[] = "pre-cold-restart-{$caseId}-{$sample}";
        }
    }
    $databaseLabels[] = 'pre-scope-restart';
    $databaseLabels[] = 'final-workload';
    $databaseCheckpoints = [];
    foreach ($databaseLabels as $index => $label) {
        $usage = 67108864 + $index;
        $peak = 100663296 + $index;
        $raw = "v2\t{$usage}\t{$peak}\t0\t0\t0";
        $databaseCheckpoints[] = [
            'checkpoint' => $label,
            'cgroup_version' => 'v2',
            'usage_bytes' => $usage,
            'peak_bytes' => $peak,
            'limit_events' => 0,
            'oom_events' => 0,
            'oom_kill_events' => 0,
            'sources' => [
                'usage' => 'memory.current',
                'peak' => 'memory.peak',
                'limit_events' => 'memory.events:max',
                'oom_events' => 'memory.events:oom',
                'oom_kill_events' => 'memory.events:oom_kill',
            ],
            'raw' => $raw,
            'raw_sha256' => hash('sha256', $raw),
        ];
    }
    $databasePeak = $databaseCheckpoints[array_key_last($databaseCheckpoints)]['peak_bytes'];
    $databaseMemoryFixture = [
        'schema' => 'relational-fts-database-cgroup-memory-v2',
        'limit_bytes' => 1073741824,
        'pre_corpus_peak_limit_bytes' => 805306368,
        'pre_corpus' => $databaseCheckpoints[0],
        'expected_checkpoint_labels' => $databaseLabels,
        'checkpoints' => $databaseCheckpoints,
        'checkpoint_count' => count($databaseCheckpoints),
        'final_checkpoint' => $databaseCheckpoints[array_key_last($databaseCheckpoints)],
        'whole_run_peak_bytes' => $databasePeak,
        'whole_run_headroom_bytes' => 1073741824 - $databasePeak,
        'max_limit_events' => 0,
        'oom_events' => 0,
        'oom_kill_events' => 0,
        'counter_aggregation' => 'maximum across restart-delimited cumulative counters',
        'complete' => true,
    ];
    $databaseFixture = ['effective_cgroup' => ['version' => 'v2']];
    assert_true(wp_fts_wc_database_memory_evidence_is_exact($databaseMemoryFixture, $databaseFixture), 'the exact database cgroup raw checkpoint fixture should validate');
    $databaseMutations = [
        'missing raw probe' => static function (array &$memory): void { unset($memory['checkpoints'][1]['raw']); },
        'empty raw probe' => static function (array &$memory): void { $memory['checkpoints'][1]['raw'] = ''; },
        'raw probe digest mismatch' => static function (array &$memory): void { $memory['checkpoints'][1]['raw_sha256'] = str_repeat('0', 64); },
        'structured usage disagrees with raw' => static function (array &$memory): void { $memory['checkpoints'][1]['usage_bytes']++; },
        'rehash cannot hide changed raw usage' => static function (array &$memory): void {
            $parts = explode("\t", $memory['checkpoints'][1]['raw']);
            $parts[1] = (string) ((int) $parts[1] + 1);
            $memory['checkpoints'][1]['raw'] = implode("\t", $parts);
            $memory['checkpoints'][1]['raw_sha256'] = hash('sha256', $memory['checkpoints'][1]['raw']);
        },
        'raw checkpoint has an extra field' => static function (array &$memory): void {
            $memory['checkpoints'][1]['raw'] .= "\t0";
            $memory['checkpoints'][1]['raw_sha256'] = hash('sha256', $memory['checkpoints'][1]['raw']);
        },
        'raw checkpoint is missing a field' => static function (array &$memory): void {
            $parts = explode("\t", $memory['checkpoints'][1]['raw']);
            array_pop($parts);
            $memory['checkpoints'][1]['raw'] = implode("\t", $parts);
            $memory['checkpoints'][1]['raw_sha256'] = hash('sha256', $memory['checkpoints'][1]['raw']);
        },
    ];
    foreach ($databaseMutations as $description => $mutate) {
        $mutated = $databaseMemoryFixture;
        $mutate($mutated);
        assert_true(!wp_fts_wc_database_memory_evidence_is_exact($mutated, $databaseFixture), "database cgroup evidence must reject {$description}");
    }
    $containerId = str_repeat('a', 64);
    $startedAt = '2026-07-18T12:34:56.123456789Z';
    $hostPid = 4242;
    $restartCount = 0;
    $sources = [
        'usage' => 'memory.current',
        'peak' => 'memory.peak',
        'limit_events' => 'memory.events:max',
        'oom_events' => 'memory.events:oom',
        'oom_kill_events' => 'memory.events:oom_kill',
    ];
    $checkpoint = static function (string $label, int $usage, int $peak) use ($sources, $containerId, $startedAt, $hostPid, $restartCount): array {
        $raw = implode("\t", ['v2', $usage, $peak, 0, 0, 0, $containerId, $startedAt, $hostPid, $restartCount]);
        return [
            'checkpoint' => $label,
            'cgroup_version' => 'v2',
            'usage_bytes' => $usage,
            'peak_bytes' => $peak,
            'limit_events' => 0,
            'oom_events' => 0,
            'oom_kill_events' => 0,
            'sources' => $sources,
            'raw' => $raw,
            'raw_sha256' => hash('sha256', $raw),
            'container_id' => $containerId,
            'container_started_at' => $startedAt,
            'container_host_pid' => $hostPid,
            'container_restart_count' => $restartCount,
        ];
    };
    $pre = $checkpoint('pre-corpus', 67108864, 100663296);
    $final = $checkpoint('final-workload', 83886080, 201326592);
    $memoryFixture = [
        'schema' => 'relational-fts-wordpress-cgroup-memory-v3',
        'limit_bytes' => 536870912,
        'pre_corpus' => $pre,
        'expected_checkpoint_labels' => ['pre-corpus', 'final-workload'],
        'checkpoints' => [$pre, $final],
        'checkpoint_count' => 2,
        'final_checkpoint' => $final,
        'whole_run_peak_bytes' => 201326592,
        'whole_run_headroom_bytes' => 335544320,
        'max_limit_events' => 0,
        'oom_events' => 0,
        'oom_kill_events' => 0,
        'counter_aggregation' => 'maximum across cumulative checkpoints in one unrestarted container',
        'complete' => true,
    ];
    $wordpressFixture = [
        'container_id' => $containerId,
        'container_lifecycle' => ['started_at' => $startedAt, 'host_pid' => $hostPid, 'restart_count' => $restartCount],
        'effective_cgroup' => ['version' => 'v2'],
    ];
    assert_true(wp_fts_wc_wordpress_memory_evidence_is_exact($memoryFixture, $wordpressFixture), 'the exact persistent WordPress cgroup fixture should validate');
    $mutations = [
        'missing final checkpoint' => static function (array &$memory): void { array_pop($memory['checkpoints']); },
        'reordered checkpoints' => static function (array &$memory): void { $memory['checkpoints'] = array_reverse($memory['checkpoints']); },
        'duplicate checkpoint label' => static function (array &$memory): void { $memory['checkpoints'][1]['checkpoint'] = 'pre-corpus'; },
        'cgroup version drift' => static function (array &$memory): void { $memory['checkpoints'][1]['cgroup_version'] = 'v1'; },
        'missing raw probe' => static function (array &$memory): void { unset($memory['checkpoints'][1]['raw'], $memory['final_checkpoint']['raw']); },
        'empty raw probe' => static function (array &$memory): void { $memory['checkpoints'][1]['raw'] = ''; $memory['final_checkpoint']['raw'] = ''; },
        'raw probe digest mismatch' => static function (array &$memory): void { $memory['checkpoints'][1]['raw_sha256'] = str_repeat('0', 64); $memory['final_checkpoint']['raw_sha256'] = str_repeat('0', 64); },
        'invalid raw hash' => static function (array &$memory): void { $memory['checkpoints'][1]['raw_sha256'] = str_repeat('G', 64); $memory['final_checkpoint']['raw_sha256'] = str_repeat('G', 64); },
        'structured usage disagrees with raw' => static function (array &$memory): void { $memory['checkpoints'][1]['usage_bytes']++; },
        'rehash cannot hide changed raw peak' => static function (array &$memory): void {
            $parts = explode("\t", $memory['checkpoints'][1]['raw']);
            $parts[2] = (string) ((int) $parts[2] + 1);
            $memory['checkpoints'][1]['raw'] = implode("\t", $parts);
            $memory['checkpoints'][1]['raw_sha256'] = hash('sha256', $memory['checkpoints'][1]['raw']);
        },
        'raw checkpoint has an extra field' => static function (array &$memory): void {
            $memory['checkpoints'][1]['raw'] .= "\t0";
            $memory['checkpoints'][1]['raw_sha256'] = hash('sha256', $memory['checkpoints'][1]['raw']);
        },
        'raw checkpoint is missing a field' => static function (array &$memory): void {
            $parts = explode("\t", $memory['checkpoints'][1]['raw']);
            array_pop($parts);
            $memory['checkpoints'][1]['raw'] = implode("\t", $parts);
            $memory['checkpoints'][1]['raw_sha256'] = hash('sha256', $memory['checkpoints'][1]['raw']);
        },
        'peak beyond 512 MiB' => static function (array &$memory): void { $memory['checkpoints'][1]['peak_bytes'] = 536870913; },
        'incorrect headroom' => static function (array &$memory): void { $memory['whole_run_headroom_bytes']++; },
        'aggregation drift' => static function (array &$memory): void { $memory['counter_aggregation'] = 'latest only'; },
        'container replacement' => static function (array &$memory): void { $memory['checkpoints'][1]['container_id'] = str_repeat('b', 64); },
        'restart resets counters behind stable container ID' => static function (array &$memory): void { $memory['checkpoints'][1]['container_started_at'] = '2026-07-18T13:00:00Z'; },
        'container host PID drift' => static function (array &$memory): void { $memory['checkpoints'][1]['container_host_pid']++; },
        'container restart-count drift' => static function (array &$memory): void { $memory['checkpoints'][1]['container_restart_count']++; },
        'memory limit event' => static function (array &$memory): void { $memory['checkpoints'][1]['limit_events'] = 1; $memory['max_limit_events'] = 1; },
    ];
    foreach ($mutations as $description => $mutate) {
        $mutated = $memoryFixture;
        $mutate($mutated);
        assert_true(!wp_fts_wc_wordpress_memory_evidence_is_exact($mutated, $wordpressFixture), "WordPress cgroup evidence must reject {$description}");
    }
    foreach (['cgroup peak must be at most 768 MiB', 'exact ordered 45-checkpoint inventory', 'restart therefore cannot erase', 'requires zero OOM and OOM-kill events', 'no tighter cache-sensitive threshold', 'relational-fts-resources-v2', 'relational-fts-database-cgroup-memory-v2', 'relational-fts-wordpress-cgroup-memory-v3', 'SHA-256(raw) === raw_sha256', 'missing, empty, independently changed, or', 'structured-inconsistent raw probe fails acceptance'] as $required) {
        assert_contains($required, $acceptance, "constrained-host acceptance must document actual peak/OOM semantics: {$required}");
    }
    foreach ([
        'mariadb@sha256:5a5c675881ef3fd1c1da9b0a3bfd6ee82edbe39cd9e32e06be18034c37235e0e',
        'mysql@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b',
        'wordpress@sha256:bfc320ed4f02dd3939186b8020de64203a48a939d6dedcf44cb92cf2368923f5',
        'wordpress@sha256:7f492e43c962ee85b1a9d5f88a97111559d92c2fb785f5d20650670bfaaa1763',
        "'a_fence_generation_1' => 1",
        "'b_fence_generation_2' => 1",
        "'c_fence_generation_3' => 1",
        "'crash_fence' => 1",
        "'a_stale_promote' => 1",
        "'b_stale_promote' => 1",
        "'c_owned_promote' => 1",
    ] as $required) {
        assert_contains($required, $integration, "finalizer should pin exact resource/mutation evidence: {$required}");
    }

    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is required to exercise clean-lane image override rejection.');
    }
    $path = getenv('PATH');
    $path = is_string($path) ? $path : '/usr/local/bin:/usr/bin:/bin';
    foreach ([
        'WP_FTS_MARIADB_IMAGE',
        'WP_FTS_MYSQL_IMAGE',
        'WP_FTS_WORDPRESS_IMAGE',
        'WP_FTS_WPCLI_IMAGE',
    ] as $override) {
        $result = test_run_subprocess([
            'env',
            '-i',
            'PATH=' . $path,
            'HOME=' . sys_get_temp_dir(),
            $override . '=example.invalid/test@sha256:' . str_repeat('0', 64),
            'bash',
            $runnerPath,
            '--engine=mariadb-10.11',
            '--profile=50k',
            '--output=' . sys_get_temp_dir() . '/wp-fts-override-rejection.json',
        ], $root);
        assert_same(1, $result['exit'], "{$override} must fail before a clean acceptance run starts");
        assert_contains($override, $result['stdout'] . $result['stderr'], 'the rejected override should be named explicitly');
        assert_contains('image overrides are forbidden in clean acceptance lanes', $result['stdout'] . $result['stderr'], 'clean override rejection should explain the acceptance rule');
    }
});

test_case('killed transaction recovery retains an exact SIGKILL receipt', function (): void {
    $root = dirname(__DIR__, 2);
    $runner = (string) file_get_contents($root . '/tools/run-relational-fts-worst-case.sh');
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');

    foreach ([
        'transaction-crash-kill-receipt.json',
        'relational-fts-transaction-crash-ready-v1',
        'relational-fts-transaction-crash-kill-receipt-v2',
        'transaction-crash-observed-process.json',
        'file_get_contents("/proc/{$child}/stat")',
        'file_get_contents("/proc/sys/kernel/random/boot_id")',
        '$observed!==$identity',
        'if kill -9 "$child"',
        '"kill_exit_status"=>$killStatus',
        'if [ "$status" -ne 137 ]',
        '"ready_sha256"=>hash_file("sha256",$readyPath)',
        '"observed_process_identity"=>$observed',
        '"signal"=>$signal',
        'file_put_contents($temporary,$json,LOCK_EX)',
        'rename($temporary,$target)',
    ] as $required) {
        assert_contains($required, $runner, "transaction crash wrapper must retain: {$required}");
    }
    foreach ([
        'relational-fts-transaction-crash-v3',
        'relational-fts-transaction-crash-ready-v1',
        'relational-fts-transaction-crash-kill-receipt-v2',
        'wp_fts_wc_process_identity()',
        'wp_fts_wc_transaction_crash_receipt_is_exact',
        'killed_transaction_sigkill_receipt',
        "['killed_transaction_sigkill_receipt', 'killed_transaction_rolled_back', 'search_after_killed_transaction']",
    ] as $required) {
        assert_contains($required, $integration, "transaction crash evidence must retain: {$required}");
    }

    foreach ([
        'wp_fts_wc_is_ascii_hex',
        'wp_fts_wc_is_lowercase_sha256',
        'wp_fts_wc_process_identity_valid',
        'wp_fts_wc_transaction_crash_receipt_is_exact',
    ] as $functionName) {
        if (!function_exists($functionName)) {
            eval(wp_fts_wc_contract_function_source($integration, $functionName));
        }
    }
    $identity = ['pid' => 321, 'start_ticks' => 654, 'boot_id' => 'fixture-boot-id'];
    $identity['sha256'] = hash('sha256', implode('|', [$identity['boot_id'], (string) $identity['pid'], (string) $identity['start_ticks']]));
    $ready = [
        'schema' => 'relational-fts-transaction-crash-ready-v1',
        'process_identity' => $identity,
        'connection_id' => 987,
        'sentinel' => 'transactioncrashsentinel',
    ];
    $readySha256 = hash('sha256', json_encode($ready, JSON_THROW_ON_ERROR));
    $receipt = [
        'schema' => 'relational-fts-transaction-crash-kill-receipt-v2',
        'ready_sha256' => $readySha256,
        'child_pid' => 321,
        'observed_process_identity' => $identity,
        'kill_exit_status' => 0,
        'exit_status' => 137,
        'signal' => 9,
    ];
    assert_true(
        wp_fts_wc_transaction_crash_receipt_is_exact($ready, $receipt, $readySha256),
        'the exact process-bound SIGKILL receipt should validate'
    );
    $mutations = [
        'different killed PID' => static function (array &$ready, array &$receipt, string &$sha): void { $receipt['child_pid']++; },
        'different observed lifetime' => static function (array &$ready, array &$receipt, string &$sha): void {
            $receipt['observed_process_identity']['start_ticks']++;
            $observed = $receipt['observed_process_identity'];
            $receipt['observed_process_identity']['sha256'] = hash('sha256', implode('|', [$observed['boot_id'], (string) $observed['pid'], (string) $observed['start_ticks']]));
        },
        'failed kill command' => static function (array &$ready, array &$receipt, string &$sha): void { $receipt['kill_exit_status'] = 1; },
        'ordinary process exit' => static function (array &$ready, array &$receipt, string &$sha): void { $receipt['exit_status'] = 0; },
        'different signal' => static function (array &$ready, array &$receipt, string &$sha): void { $receipt['signal'] = 15; },
        'stale ready digest' => static function (array &$ready, array &$receipt, string &$sha): void { $receipt['ready_sha256'] = str_repeat('0', 64); },
        'invalid connection identity' => static function (array &$ready, array &$receipt, string &$sha): void { $ready['connection_id'] = 0; },
        'extra ready field' => static function (array &$ready, array &$receipt, string &$sha): void { $ready['unbound'] = true; },
        'extra receipt field' => static function (array &$ready, array &$receipt, string &$sha): void { $receipt['unbound'] = true; },
        'ready changed after receipt' => static function (array &$ready, array &$receipt, string &$sha): void {
            $ready['connection_id']++;
            $sha = hash('sha256', json_encode($ready, JSON_THROW_ON_ERROR));
        },
    ];
    foreach ($mutations as $description => $mutate) {
        $mutatedReady = $ready;
        $mutatedReceipt = $receipt;
        $mutatedSha256 = $readySha256;
        $mutate($mutatedReady, $mutatedReceipt, $mutatedSha256);
        assert_true(
            !wp_fts_wc_transaction_crash_receipt_is_exact($mutatedReady, $mutatedReceipt, $mutatedSha256),
            "SIGKILL receipt validation must reject {$description}"
        );
    }
    foreach (['relational-fts-transaction-crash-v3', 'same child', 'live Linux boot', 'start ticks', 'kill exit status 0', 'signal 9', 'exit status 137', 'exact SIGKILL receipt validates'] as $required) {
        assert_contains($required, $acceptance, "acceptance must document the retained kill receipt: {$required}");
    }
});

test_case('relational worst-case runs exact isolated accepted and rejected boundaries', function (): void {
    $root = dirname(__DIR__, 2);
    $runner = (string) file_get_contents($root . '/tools/run-relational-fts-worst-case.sh');
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $isolated = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-isolated-boundaries.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');

    foreach ([
        'ISOLATED_BOUNDARIES_SHA256=',
        '${ISOLATED_BOUNDARIES_SCRIPT}:/proof/relational-fts-isolated-boundaries.php:ro',
        'run_isolated_boundaries()',
        'command -v timeout',
        'timeout -s KILL 180 php -d memory_limit=128M',
        'WP_FTS_HARNESS_SHA256=${ISOLATED_BOUNDARIES_SHA256}',
        'relational-fts-isolated-boundaries-v1',
        'count($gates)!==62',
        'run_isolated_boundaries',
    ] as $required) {
        assert_contains($required, $runner, "runner should retain isolated-boundary contract: {$required}");
    }
    $maxValid = strpos($runner, 'run_php_phase max-valid-search');
    $runIsolated = strpos($runner, "\nrun_isolated_boundaries\n", $maxValid === false ? 0 : $maxValid);
    $transactionFault = strpos($runner, "\nkill_uncommitted_transaction\n", $runIsolated === false ? 0 : $runIsolated);
    assert_true(is_int($maxValid) && is_int($runIsolated) && is_int($transactionFault), 'isolated-boundary lifecycle should remain independently inspectable');
    assert_true($maxValid < $runIsolated && $runIsolated < $transactionFault, 'fresh isolated proof must run after near-limit search and clean up before later fault/concurrency phases');

    foreach ([
        'isolated_boundary_exact_gate_set',
        'isolated_boundary_source_binding',
        'isolated_boundary_evidence_hash',
        'isolated_boundary_stdout_artifact_identity',
        'cjk_accepted_input_exactly_4095_bytes',
        'html_nested_100000_sql_before_rejection',
        'html_language_1800000_sql_before_rejection',
        'infinite_tokenizer_consumed_occurrences',
        'logical_groups_13_sql_before_rejection',
        'query_alternatives_13_sql_before_rejection',
        'document_4096_writer_term_count',
        'document_4097_sql_before_rejection',
        'enqueue_many_1000_statement_count',
        'enqueue_many_1001_sql_before_rejection',
        'proc_vmhwm_within_128_mib',
        "\$evidence['isolated_boundaries'] = \$externalEvidence['isolated_boundaries']",
    ] as $required) {
        assert_contains($required, $integration, "finalizer should retain isolated evidence/gate: {$required}");
    }

    foreach ([
        "const WP_FTS_IB_MEMORY_LIMIT_BYTES = 134217728",
        "str_repeat('中', 1365)",
        "str_repeat('中', 1366)",
        "(\$accepted_result['analyzed_length'] ?? null) === 5454",
        "str_repeat('<span>', 100000)",
        "str_repeat('en-', 600000)",
        'html_nested_100000_vmhwm_within_128_mib',
        'html_language_1800000_vmhwm_within_128_mib',
        'WP_FTS_IB_Infinite_Cjk_Tokenizer',
        'wp-fts-isolated-infinite-cjk-tokenizer-v1',
        'function analyze_document_fields(array $fields, array $options = []): array',
        'WP_FTS_IB_Distinct_Term_Analyzer(4096',
        'WP_FTS_IB_Distinct_Term_Analyzer(4097',
        'WP_FTS_IB_QUEUE_ACCEPTED_COUNT = 1000',
        'all_reject_paths_actual_wpdb_statement_count',
        'php_peak_memory_within_128_mib',
        'proc_vmrss_within_128_mib',
    ] as $required) {
        assert_contains($required, $isolated, "isolated real-WordPress proof should retain exact boundary: {$required}");
    }
    $logicalPlans = wp_fts_wc_contract_function_source($isolated, 'wp_fts_ib_case_logical_plans');
    assert_contains("'key' => WP_FTS_TermNamespace::namespace_term('en', \$term)", $logicalPlans, 'isolated alternative boundaries should retain the current namespaced key');
    assert_contains("'rank' => \$index", $logicalPlans, 'isolated alternative boundaries should retain the current rank');
    assert_true(!str_contains($logicalPlans, "'lang' => 'en'"), 'isolated direct-storage alternatives must not retain the removed language field');
    assert_true(!str_contains($logicalPlans, "'term' => \$term"), 'isolated direct-storage alternatives must not retain the removed term field');
    foreach (['4,095-byte contiguous CJK', '4,096 distinct terms', '4,097 terms', '1,000-ID enqueue', '1,001 IDs', '180-second'] as $required) {
        assert_contains($required, $acceptance, "acceptance writeup should retain isolated hard boundary: {$required}");
    }
    assert_contains('exactly 62 uniquely named passing', $acceptance, 'acceptance writeup should match the isolated runner and finalizer gate cardinality');
});

test_case('relational worst-case evidence gates query shape, memory, rows, latency, and failures', function (): void {
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $acceptance = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/relational-search-acceptance.md');
    assert_contains(
        'WP_FTS_Plugin::run_scheduled_schema_repair();',
        $integration,
        'initial readiness should run through the current schema-repair callback'
    );
    assert_true(
        !str_contains($integration, 'run_scheduled_schema_upgrade'),
        'the real-database workload must not call the removed schema-upgrade alias'
    );
    foreach ([
        'relational-fts-evidence-v5',
        "'acceptance_lane' => wp_fts_wc_required_env('WP_FTS_WC_ALLOW_DIRTY') !== '1'",
        "'completed' => false",
        "\$evidence['completed'] = true",
        'Validation evidence has failed gates:',
        'common_or',
        'rare_anchor_and',
        'prefix_fanout',
        'surface_rarest_exact_anchor_and',
        'surface_dense_candidate_prefix_and',
        'selective_prefix_anchor_and',
        'hidden_dirty_head',
        'impossible_and',
        'all_packs',
        'ambiguous_morphology_or',
        'ambiguous_morphology_and',
        'field_impact',
        'failure_recovery_progress',
        'actual_http_adapters',
        'frontend_stock_unscoped',
        'actual_wpcli_adapter',
        'wp_fts_wc_actual_http_adapter_proof',
        'wp_fts_wc_measure_missing_table_http',
        'wp_fts_wc_measure_stage_failure_http',
        'wp_fts_wc_measure_isolated_search_failure_http',
        'wp_fts_wc_search_failure_attribution',
        'wp_fts_wc_raw_option_snapshot',
        'wp_fts_wc_restore_raw_option_snapshot',
        'wp_fts_wc_raw_cron_snapshot_has_schema_repair',
        'wp_fts_wc_performance_schema_request_events',
        'relational-fts-http-attribution-v1',
        'relational-fts-missing-table-isolation-v1',
        'relational-fts-stage-failure-isolation-v1',
        'terminal_event_count',
        'unattributed_connection_events',
        'actual_{$caseId}_statement_shape',
        'actual_{$caseId}_core_like_queries',
        'missing_table_{$caseId}_core_like_queries',
        'missing_table_{$caseId}_failed_plan_statement_shape',
        'missing_table_{$caseId}_failed_plan_plugin_attribution',
        'missing_table_{$caseId}_failure_control_statement_set',
        'missing_table_{$caseId}_total_plugin_statement_ceiling',
        'missing_table_{$caseId}_post_fault_state_latched',
        'missing_table_{$caseId}_isolated_state_restored',
        'missing_table_all_adapters_same_pre_fault_state',
        'stage_failure_{$failureStage}_ordered_search_shape',
        'stage_failure_{$failureStage}_one_shot_real_database_failure',
        'stage_failure_{$failureStage}_no_later_search_or_core_like',
        'stage_failure_{$failureStage}_failure_control_statement_set',
        'stage_failure_{$failureStage}_total_plugin_statement_ceiling',
        'stage_failure_{$failureStage}_post_fault_state_latched',
        'stage_failure_{$failureStage}_isolated_state_restored',
        'stage_failure_all_stages_same_pre_fault_state',
        'WP_FTS_WC_FAILED_SEARCH_MIN_CONTROL_STATEMENTS',
        'WP_FTS_WC_FAILED_SEARCH_MAX_CONTROL_STATEMENTS',
        'WP_FTS_WC_FAILED_SEARCH_MAX_PLUGIN_STATEMENTS',
        'WP_FTS_WC_FAILED_SEARCH_STAGE_CEILINGS',
        "'post_fault_search_ready_revoked'",
        "'post_fault_health_unhealthy'",
        "'post_fault_health_latched'",
        "'pre_fault_repair_scheduled'",
        "'post_fault_repair_scheduled'",
        "'post_fault_option_sha256'",
        "['rest', 'frontend', 'admin_posts', 'sandbox', 'sandbox_ajax']",
        "'exact_plugin_statement_set'",
        "'total_plugin_statement_ceiling'",
        "'runtime-profile' => wp_fts_wc_runtime_profile()",
        "'writer-aggregate' => wp_fts_wc_writer_aggregate()",
        'writer_4097_lexical_rejected_before_sql',
        'writer_4097_surfaces_rejected_before_sql',
        'writer_50001_posting_split_before_sql',
        'writer_8193_identity_split_before_sql',
        'writer_exact_50000_posting_transaction',
        'writer_fresh_transactions_skip_retirement',
        'writer_aggregate_flat_posting_values',
        'writer_aggregate_posting_affected_rows',
        'writer_aggregate_statement_count',
        'writer_aggregate_statement_bytes',
        'writer_aggregate_transaction_ms',
        'writer_aggregate_doc_freqs_exact',
        'writer_maximum_width_identity_rows',
        'writer_maximum_width_dictionary_packet',
        'writer_maximum_width_resolver_packet',
        'writer_maximum_width_cleanup',
        'writer_aggregate_php_peak',
        'writer_aggregate_rss_peak',
        'max_query_count',
        'max_total_query_count',
        'max_fts_query_count',
        'max_sql_bytes',
        'performance_schema_rows_examined',
        'handler_read_operations',
        'max_rss_delta_bytes',
        'max_diagnostic_rss_peak_increment_bytes',
        'rss_peak_bytes',
        'concurrent_p95_ms',
        'worker_batch_total_statement_count',
        'all_wpdb_including_transaction_control',
        'all_recorded_wpdb_including_transaction_control',
        'total_raw_statement_parity',
        'worker_batch_fts_data_statement_count',
        'worker_batch_lease_control_statement_count',
        'worker_batch_transaction_control_statement_count',
        'worker_batch_scheduling_control_statement_count',
        'worker_batch_physical_schema_statement_count',
        'worker_queue_claim_explain_plans',
        'worker_queue_claim_full_scans',
        'worker_changed_batch_analyzed',
        'worker_changed_batch_statement_scope',
        'worker_changed_batch_total_statement_count',
        'worker_changed_batch_data_statement_count',
        'worker_changed_batch_lease_controls',
        'worker_changed_batch_transaction_controls',
        'worker_changed_batch_statement_roles',
        'worker_changed_batch_hashes_rewritten',
        'worker_composed_cron_no_event',
        'worker_composed_cron_later_event',
        'worker_composed_control_protocols',
        'worker_composed_performance_schema_attribution',
        'worker_composed_recovery_diagnostics_deferred',
        'worker_composed_claim_snapshot_fallback',
        'later_event_bounded_two_statement_source_protocol',
        'worker_composed_scheduling_control_ceiling',
        'unchanged_requeues_worker_not_reindexed',
        'unchanged_requeues_worker_acknowledged',
        'unchanged_requeues_worker_analyzed',
        'unchanged_requeues_worker_adverse_outcomes',
        'unchanged_requeues_content_hash_signature',
        'unchanged_requeues_surface_posting_signature',
        'unchanged_requeues_index_data_writes',
        'claim_index_options_preload_contract',
        'claim_index_options_fixed_dependency_statements',
        'claim_index_options_worker_statement_roles',
        'claim_index_options_worker_total_statement_count',
        'claim_index_options_worker_data_statement_count',
        'claim_index_options_worker_lease_controls',
        'claim_index_options_worker_transaction_controls',
        'wp_fts_wc_claim_index_options_preload_proof',
        'oracle_complete_slice_membership_and_order',
        'performance_event_shape',
        'plan_rows_sent',
        'explain_by_statement',
        'posting_relation_references',
        'dictionary_term_ranges',
        'exact_plan_rows_sent',
        'surface_index_access',
        '{$caseId}_explain_bounded',
        'surface_range_dictionary_terms',
        'surface_storage_per_document_surface_bound',
        'surface_storage_per_document_total_bound',
        'max_valid_or_prefix_surface_plan_aggregate_shape',
        'prefix_fanout_surface_plan_aggregate_shape',
        'surface_rarest_exact_anchor_and_surface_plan_aggregate_shape',
        'surface_dense_candidate_prefix_and_surface_plan_aggregate_shape',
        'selective_prefix_anchor_and_surface_plan_aggregate_shape',
        'impossible_and_surface_plan_aggregate_shape',
        'max_valid_or_prefix_surface_plan_rows_examined',
        'prefix_fanout_surface_plan_rows_examined',
        'surface_rarest_exact_anchor_and_surface_plan_rows_examined',
        'surface_dense_candidate_prefix_and_surface_plan_rows_examined',
        'selective_prefix_anchor_and_surface_plan_rows_examined',
        'impossible_and_surface_plan_rows_examined',
        'surface_rarest_exact_anchor_and_join_shape',
        'surface_rarest_exact_anchor_and_driver_cost',
        'surface_dense_candidate_prefix_and_join_shape',
        'surface_dense_candidate_prefix_and_driver_cost',
        'selective_prefix_anchor_and_join_shape',
        'selective_prefix_anchor_and_explain_bounded',
        'selective_prefix_anchor_and_rank_rows_examined',
        'selective_prefix_anchor_and_common_exact_not_materialized',
        'surface_dense_candidate_prefix_and_unrelated_posting_envelope',
        'dense_relationship_active_targeted_broad_prefix_rows_examined',
        'dense_relationship_rank_control_revocation_returns_control_row',
        'broad_outer_visibility_shape',
        'broad_visibility_order',
        'generation_fence_stale_completions_noop',
        'mutation_fence_real_database_artifact',
        'mutation_fence_real_database_cross_process_guard',
        'mutation_fence_real_database_generations',
        'mutation_fence_real_database_statement_shape',
        'mutation_fence_production_worker_canonical_generation',
        'mutation_fence_production_worker_canonical_visible',
        'mutation_fence_production_worker_atomic_ack',
        'mutation_fence_production_worker_fixture_restored',
        'metadata_real_100_sequential_boundary_statements',
        'metadata_real_1000_row_boundary_statements',
        "'api' => 'wp_update_post'",
        'wp_update_term(',
        'prefix_cursor_surface_completion_membership',
        'cursor_signed_recency_epoch',
        'cursor_scope_replays_rejected',
        'identity_bytes_plan_rows_bounded',
        'identity_bytes_membership_exact',
        'identity_bytes_unique_index_access',
        'identity_bytes_fixture_restored',
        'deleted_identity_cursor_rejected',
        'deleted_identity_cursor_plan_statements',
        'deleted_identity_cursor_rank_statements',
        'deleted_identity_cursor_hydrate_statements',
        'deleted_identity_epoch_advanced',
        'deleted_identity_state_restored',
        'nontransactional_engine_rejected',
        'nontransactional_engine_reported',
        'nontransactional_engine_repaired',
        'nontransactional_engine_drop_count',
        'nontransactional_engine_repair_does_not_claim_state_preservation',
        'nontransactional_engine_fixture_cleanup_restored',
        "'production_repair_preserved_exact_work_state' => false",
        'final_work_rows',
        'search_epoch_rows',
        'evidence_sha256',
    ] as $required) {
        assert_contains($required, $integration, "machine evidence should retain required metric/case: {$required}");
    }
    $isolatedFailureMeasurement = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_measure_isolated_search_failure_http');
    $postFaultCapture = strpos($isolatedFailureMeasurement, '$postFaultOptionSnapshots[$key] = wp_fts_wc_raw_option_snapshot($optionName);');
    $harnessRestoration = strpos($isolatedFailureMeasurement, '} finally {');
    assert_true(
        $postFaultCapture !== false && $harnessRestoration !== false && $postFaultCapture < $harnessRestoration,
        'missing-table proof must capture production post-fault state before harness restoration'
    );
    $httpMeasurement = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_measure_http_adapter');
    assert_contains(
        '$attribution = wp_fts_wc_performance_schema_request_events($requestId, true);',
        $httpMeasurement,
        'HTTP measurement must close attribution through the unique shutdown marker before post-fault capture'
    );
    $faultEvidenceStart = strpos($isolatedFailureMeasurement, "\$response['fault_isolation'] = [");
    $faultEvidenceEnd = strpos($isolatedFailureMeasurement, 'return $response;', $faultEvidenceStart === false ? 0 : $faultEvidenceStart);
    assert_true(
        $faultEvidenceStart !== false && $faultEvidenceEnd !== false,
        'missing-table proof must emit bounded post-fault evidence'
    );
    $faultEvidence = substr($isolatedFailureMeasurement, $faultEvidenceStart, $faultEvidenceEnd - $faultEvidenceStart);
    assert_true(!str_contains($faultEvidence, "'option_value'"), 'missing-table evidence must not expose raw option values');
    foreach (['post_fault_search_ready_revoked', 'post_fault_health_unhealthy', 'post_fault_health_latched', 'pre_fault_repair_scheduled', 'post_fault_repair_scheduled', 'post_fault_option_sha256'] as $required) {
        assert_contains($required, $faultEvidence, "post-fault evidence must retain semantic/hash field: {$required}");
    }
    $missingTableGates = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_actual_http_adapter_proof');
    foreach ([
        "\$requiredOptionMutationCounts === ['search_ready' => 1, 'health' => 1]",
        "(\$case['main_query_probe']['result_ids'] ?? null) === []",
        "(\$case['main_query_probe']['search_unavailable'] ?? null) === 'runtime_failure'",
        'missing_table_{$caseId}_post_fault_state_latched',
        "(\$faultIsolation['post_fault_repair_scheduled'] ?? null) === true",
    ] as $required) {
        assert_contains($required, $missingTableGates, "missing-table critical gate must retain production latch contract: {$required}");
    }
    assert_same(
        2,
        substr_count($missingTableGates, "\$failureEvents = \$measurements['failure_events'];"),
        'missing-table and injected-stage ceilings must each count their own failed search prefix and controls'
    );
    $mainQueryProbe = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_main_query_probe');
    foreach (['data-wp-fts-wc-main-query-probe', 'data-result-ids', 'data-search-unavailable', 'count($probes) !== 1'] as $required) {
        assert_contains($required, $mainQueryProbe, "front-end result parsing must use the exact main-query probe: {$required}");
    }
    assert_same(
        5,
        substr_count($missingTableGates, 'wp_fts_wc_measure_missing_table_http('),
        'the five-adapter missing-table plan proof must remain intact beside stage injection'
    );
    $availabilityFault = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_missing_table_and_lock_proof');
    $tableRestored = strpos($availabilityFault, 'RENAME TABLE `{$backupTable}` TO `{$termTable}`');
    $maintenanceVerified = strpos($availabilityFault, 'WP_FTS_Plugin::run_scheduled_schema_repair()');
    $recoveryWorker = strpos($availabilityFault, 'WP_FTS_Plugin::process_manual_index_batch([', $maintenanceVerified === false ? 0 : $maintenanceVerified);
    $maintenanceRepublished = strpos($availabilityFault, 'WP_FTS_Plugin::run_scheduled_schema_repair()', $recoveryWorker === false ? 0 : $recoveryWorker + 1);
    $postRepairSearch = strpos($availabilityFault, "WP_FTS_Plugin::search('rareanchor'", $maintenanceRepublished === false ? 0 : $maintenanceRepublished);
    assert_true(
        $tableRestored !== false
            && $maintenanceVerified !== false
            && $recoveryWorker !== false
            && $maintenanceRepublished !== false
            && $postRepairSearch !== false
            && $tableRestored < $maintenanceVerified
            && $maintenanceVerified < $recoveryWorker
            && $recoveryWorker < $maintenanceRepublished
            && $maintenanceRepublished < $postRepairSearch,
        'the availability fault must verify restoration, drain canonical work, and republish readiness before search resumes'
    );
    $databaseErrorsStart = strpos($missingTableGates, '$databaseErrorEvents = array_values(array_filter(');
    $databaseErrorsEnd = strpos($missingTableGates, '$injectedEvent =', $databaseErrorsStart === false ? 0 : $databaseErrorsStart);
    assert_true($databaseErrorsStart !== false && $databaseErrorsEnd !== false, 'stage-failure proof must retain an independently inspectable database-error set');
    $databaseErrors = substr($missingTableGates, $databaseErrorsStart, $databaseErrorsEnd - $databaseErrorsStart);
    assert_contains('$pluginEvents', $databaseErrors, 'the one-shot failure gate must count database errors across every plugin-attributed statement');
    assert_true(!str_contains($databaseErrors, '$pluginSearchEvents'), 'failed plugin control statements must not escape the one-shot database-error gate');
    foreach ([
        "'plan' => 5",
        "'rank' => 6",
        "'hydrate' => 7",
        "foreach (array_keys(WP_FTS_WC_FAILED_SEARCH_STAGE_CEILINGS) as \$failureStage)",
        'stage_failure_{$failureStage}_ordered_search_shape',
        'stage_failure_{$failureStage}_one_shot_real_database_failure',
        'stage_failure_{$failureStage}_no_later_search_or_core_like',
        'stage_failure_{$failureStage}_failure_control_statement_set',
        'stage_failure_{$failureStage}_total_plugin_statement_ceiling',
        'stage_failure_{$failureStage}_post_fault_state_latched',
        'stage_failure_{$failureStage}_isolated_state_restored',
    ] as $required) {
        assert_contains($required, $integration, "stage-aware HTTP failure evidence must retain contract: {$required}");
    }
    $requestAttribution = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_install_request_attribution_mu_plugin');
    foreach ([
        'HTTP_X_WP_FTS_WC_FAILURE_STAGE',
        'static $injectedFailure = false',
        "in_array(\$failureStage, ['plan', 'rank', 'hydrate'], true)",
        "'plan' => \$wpdb->prefix . 'fts_terms'",
        "'rank' => \$wpdb->prefix . 'fts_postings'",
        "'hydrate' => \$wpdb->prefix . 'fts_documents'",
        'wp_fts_wc_injected_failure:',
        'substr_replace($sql, $missingTable, $sourceOffset, strlen($sourceTable))',
    ] as $required) {
        assert_contains($required, $requestAttribution, "one-shot query injection must retain real stage fault: {$required}");
    }
    $performanceAttribution = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_performance_schema_request_events');
    foreach (['MYSQL_ERRNO', 'RETURNED_SQLSTATE', 'MESSAGE_TEXT', "'mysql_errno'", "'returned_sqlstate'", "'message_text'"] as $required) {
        assert_contains($required, $performanceAttribution, "Performance Schema must retain real database failure field: {$required}");
    }
    $inventoryContract = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_validation_inventory_matches');
    foreach ([
        'stage_failure_all_stages_same_pre_fault_state',
        "foreach (['plan', 'rank', 'hydrate'] as \$failureStage)",
        "'one_shot_real_database_failure'",
        "'no_later_search_or_core_like'",
        "'isolated_state_restored'",
    ] as $required) {
        assert_contains($required, $inventoryContract, "critical validation inventory must reject deleted stage-failure proof: {$required}");
    }
    foreach ([
        'plan failure therefore executes at most five',
        'rank failure at most six',
        'hydration failure',
        'at most seven',
        'MySQL error 1146 / SQLSTATE',
        'sole nonzero MySQL error across every',
        'no later stage or core `LIKE`',
        'total plugin ceilings of 5/6/7',
    ] as $required) {
        assert_contains($required, $acceptance, "acceptance must state the measured stage-aware failure ceiling: {$required}");
    }
    $cronSnapshot = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_raw_cron_snapshot_has_schema_repair');
    foreach (['WP_FTS_Plugin::SCHEMA_REPAIR_CRON_HOOK', "(\$event['schedule'] ?? null) === false", "(\$event['args'] ?? null) === []"] as $required) {
        assert_contains($required, $cronSnapshot, "repair schedule proof must retain exact single-event semantics: {$required}");
    }
    assert_contains("interface_exists('WP_FTS_Set_Oriented_Search_Storage')", $integration, 'proof should fail explicitly when the real set-oriented backend is absent');
    assert_contains("method_exists(\$queue, 'enqueue_many')", $integration, 'proof should require set-oriented work enqueueing');
    foreach ([
        'transaction-crash',
        'cold-sample',
        'search-memory-sample',
        'idle-http',
        'wpcli',
        'wpcli-adapter',
        'writer-aggregate',
    ] as $required) {
        assert_contains($required, $integration, "machine evidence should retain destructive validation phase: {$required}");
    }
    $runner = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/run-relational-fts-worst-case.sh');
    assert_contains('run_php_phase writer-aggregate', $runner, 'real database runner should execute the aggregate writer proof on every required engine/profile lane');
    foreach (['relational-fts-writer-aggregate-v5', '50001', '8193', 'surface_limit', 'term_limit', 'posting_statement_count', 'posting_affected_rows', 'posting_values_shape', 'fresh_document_retirement_skipped', 'dictionary_increment_statement_count', 'dictionary_increment_statement_bytes', 'dictionary_decrement_statement_count', 'bounded_delete_statement_count', 'maximum_width_identity_capacity', 'distinct_identity_count', 'lexical_quote_backslash_control_bytes', 'resolver_statement_count', 'resolver_statement_bytes', 'resolver_server_events'] as $required) {
        assert_contains($required, $integration, "real writer proof should retain the exact posting boundary: {$required}");
    }
    assert_contains('public string $term_relationships;', $integration, 'the recording wpdb should expose the native relationships table property required by relational storage');
    assert_contains('$delegate->term_relationships ?? ($this->prefix . \'term_relationships\')', $integration, 'the recording wpdb should mirror the native relationships table name');
    $writerTransaction = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_record_writer_transaction');
    assert_contains('foreach ($statements as $statement)', $writerTransaction, 'the writer memory proof should scan retained statements without joining multi-megabyte SQL strings');
    assert_true(!str_contains($writerTransaction, 'implode('), 'the writer memory proof must not duplicate retained SQL into one aggregate string');
    $maxValidSetup = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_max_valid_setup');
    $manifestRelease = strpos($maxValidSetup, 'unset($manifest);');
    $seedRelease = strpos($maxValidSetup, 'unset($seed, $seedHashInput, $seedRows);');
    $databaseRelease = strpos($maxValidSetup, '$wpdb->flush();');
    $backlogDelay = strpos($maxValidSetup, '$backlogDelaySeconds = 86400;');
    $backlogDefer = strpos($maxValidSetup, 'available_at=available_at+{$backlogDelaySeconds}');
    $isolatedEnqueue = strpos($maxValidSetup, 'enqueue_many($ids)');
    $workerPasses = strpos($maxValidSetup, 'for ($pass = 0; $pass < 100; $pass++)');
    $backlogRestore = strpos($maxValidSetup, 'available_at=available_at-{$backlogDelaySeconds}');
    assert_true(
        $manifestRelease !== false
            && $seedRelease !== false
            && $databaseRelease !== false
            && $backlogDelay !== false
            && $backlogDefer !== false
            && $isolatedEnqueue !== false
            && $workerPasses !== false
            && $backlogRestore !== false
            && $manifestRelease < $seedRelease
            && $seedRelease < $databaseRelease
            && $databaseRelease < $backlogDelay
            && $backlogDelay < $backlogDefer
            && $backlogDefer < $isolatedEnqueue
            && $isolatedEnqueue < $workerPasses
            && $workerPasses < $backlogRestore,
        'near-limit setup should release seed records and reversibly defer unrelated work around the measured worker'
    );
    assert_contains("'batch_size' => 100", $maxValidSetup, 'near-limit setup should exercise the production-sized worker claim');
    assert_contains('$deferredWork === $unrelatedWorkBefore', $maxValidSetup, 'near-limit setup should defer every unrelated durable row');
    assert_contains('$restoredWork === $unrelatedWorkBefore', $maxValidSetup, 'near-limit setup should restore every deferred durable row');
    assert_contains('$unrelatedWorkAfter === $unrelatedWorkBefore', $maxValidSetup, 'near-limit setup should prove that it preserved unrelated durable work');
    foreach ([
        "\$rssBefore = wp_fts_wc_rss_bytes('VmRSS');",
        "'rss_peak_delta_bytes' => max(0, \$rssPeakAfter - \$rssBefore)",
        "'worker_rss_peak_delta_bytes' => \$workerRssDeltaMax",
        "'seed_process_identity' => \$seedProcessIdentity",
    ] as $required) {
        assert_contains($required, $maxValidSetup, "near-limit setup should retain fresh-process memory accounting: {$required}");
    }
    $maxValidSeedPhase = strpos($runner, 'run_php_phase max-valid-seed');
    $maxValidWorkerPhase = strpos($runner, 'run_php_phase max-valid-setup');
    assert_contains(
        'setup|indexing-prepare|initial-index-drain|reindex-drain|validate|drain',
        $runner,
        'full validation should retain the two-hour scale-lane phase bound'
    );
    assert_true(
        $maxValidSeedPhase !== false
            && $maxValidWorkerPhase !== false
            && $maxValidSeedPhase < $maxValidWorkerPhase,
        'near-limit source construction must finish in a separate process before the measured worker'
    );
    $storage = (string) file_get_contents(dirname(__DIR__, 2) . '/src/RelationalStorage.php');
    $postingInsertStart = strpos($storage, 'private function resolved_posting_insert(');
    $postingInsertEnd = strpos($storage, 'private function term_identity_ordinal_relation(', $postingInsertStart === false ? 0 : $postingInsertStart);
    assert_true(is_int($postingInsertStart) && is_int($postingInsertEnd), 'the bounded posting INSERT helper should remain independently inspectable');
    $postingInsert = substr($storage, $postingInsertStart, $postingInsertEnd - $postingInsertStart);
    assert_contains('/* wp_fts:posting-replacement */', $postingInsert, 'the bounded posting INSERT must retain its exact evidence tag');
    assert_contains(". '(' . \$termId . ',' . \$postId . ',' . \$impact . ')'", $postingInsert, 'the bounded posting writer must emit only flat numeric three-column tuples');
    assert_contains('$rowCount !== $expectedRows', $postingInsert, 'the posting writer must prove the emitted tuple count against preflight');
    foreach (['SELECT ', 'UNION ', 'FROM ', 'posting_chunk_', 'ON DUPLICATE'] as $forbidden) {
        assert_true(!str_contains($postingInsert, $forbidden), "the bounded posting INSERT must not contain {$forbidden}");
    }
    assert_contains(
        '/* wp_fts:dictionary-increment */',
        $storage,
        'the bounded dictionary VALUES UPSERT must retain its exact evidence tag'
    );
    assert_contains('/* wp_fts:dictionary-decrement */', $storage, 'the post-first old-frequency decrement must retain its exact evidence tag');
    assert_contains('UPDATE (', $storage, 'the old-frequency decrement must put its materialized posting relation before the update target');
    assert_contains('changed FORCE INDEX (post_term)', $storage, 'the old-frequency decrement must force its post-first covering driver');
    assert_contains('STRAIGHT_JOIN {$this->termsTable} AS t FORCE INDEX (PRIMARY)', $storage, 'the old-frequency decrement must primary-key join the target after materialization');
    assert_contains('MAX_TERM_RESOLUTION_IDENTITIES = 8192', $storage, 'the maximum document must resolve its dictionary in one proven-width indexed read');
    assert_contains('$postsWithOldPostings = array_keys(array_filter(', $storage, 'fresh documents must derive an empty retirement set from measured old-posting counts');
    assert_contains('if ($postsWithOldPostings !== [])', $storage, 'fresh documents must skip the dictionary decrement statement');
    assert_contains('if ($retiredPosts !== [])', $storage, 'fresh documents must skip the bounded deletion statement');
    assert_true(!str_contains($storage, 'dictionary_delta_relation'), 'relational storage must not restore the self-referential dictionary INSERT/SELECT');
    assert_contains('run_wpcli_php_phase wpcli-adapter', $runner, 'real database runner should measure the installed WP-CLI command through wpdb');
    assert_contains('run_wpcli_php_phase runtime-profile', $runner, 'real database runner should compare the complete web and WP-CLI index profiles');
    assert_contains("eval 'ini_set(\"memory_limit\", \"128M\"); require \"/proof/relational-fts-worst-case.php\";'", $runner, 'WP-CLI phases should restore the 128M limit before requiring the strict-types proof');
    assert_true(!str_contains($runner, 'eval-file /proof/relational-fts-worst-case.php'), 'WP-CLI phases must not place bootstrap statements before the proof strict-types declaration');
    assert_contains('config set DISABLE_WP_CRON true --raw', $runner, 'fault injection must not race a spawned loopback repair process');
    foreach (['runtime_profile_full_parity', 'runtime_analyzer_signature_parity', 'runtime_unicode_normalizer_signature_parity'] as $gate) {
        assert_contains($gate, $runner, "real database runner should retain runtime parity gate: {$gate}");
    }
    record_check('relational worst-case evidence contract', 39);
    record_check('WP-CLI strict-types execution contract', 2);
});

test_case('relational worst-case retains the real 57344-row old-posting frontier proof', function (): void {
    $root = dirname(__DIR__, 2);
    $runner = (string) file_get_contents($root . '/tools/run-relational-fts-worst-case.sh');
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $frontier = (string) file_get_contents(dirname(__DIR__) . '/integration/old-posting-frontier.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');

    foreach ([
        '7 x 8,192 old-posting fixture',
        'MAX_BATCH_POSTINGS + 1',
        'MAX_BATCH_TERMS',
        'plan_prepared_replacement',
        'replace_prepared_documents([], $plan->admitted_post_ids, $plan)',
        "'passes' => count(\$passes)",
        "'pass_details' => \$passes",
        "'frontier_rows_returned'",
        "'frontier_rows_scanned'",
        "'posting_mutations'",
        "'frontier_statement_count'",
        "'transaction_statement_count'",
        "'transaction_statement_ids'",
        "'transaction_statement_methods'",
        "'shared_survivor'",
        'wp_fts_frontier_seed_shared_fixture',
        "'initial_doc_freq' => 2",
        "'decrement_statement_count'",
        "'decrement_sql_sha256'",
        "'decrement_server_rows_examined'",
        "'decrement_server_rows_affected'",
        'wp_fts:dictionary-decrement',
        'wp_fts_frontier_decrement_performance_events',
        "'delete_statement_count'",
        "'delete_sql_sha256'",
        "'delete_server_rows_examined'",
        "'delete_server_rows_affected'",
        'wp_fts:bounded-index-delete',
        'wp_fts:search-epoch-advance',
        'LIMIT 50100',
        'candidate_posting FORCE INDEX (post_term)',
        "foreach (['old_posting', 'retired_term', 'retired_document']",
        'wp_fts_frontier_delete_performance_events',
        "'bad_document_frequencies'",
        "'old_posting_access' => \$oldAccess",
        "'max_server_rows_examined'",
        "'max_created_tmp_disk_tables'",
        "'remaining_terms'",
        "'preserved_decoy_postings'",
        "'zip_sha256' => \$zipSha",
        "'statement_count' => count(\$resetStatements)",
        "'reset_strategy' => 'mysql_atomic_table_swap'",
        "'exact_sql_shape' => \$resetSql === \$expectedResetSql",
        "'contains_delete_or_count' => \$resetHasForbiddenCorpusWork",
        "'schema_version' => 1",
        "'exact_current_contract' => true",
        "'recoverable' => ['unique' => false, 'columns' => ['kind', 'state', 'claim_expires_at', 'available_at', 'post_id', 'job_key']]",
        "'only_canonical_tables' => \$postResetTables === \$expectedPostResetTables",
        'memory_reset_peak_usage()',
        'wp_fts_frontier_linux_vmhwm_bytes()',
        "'php_peak_bytes' => \$overallPhpPeakBytes",
    ] as $required) {
        assert_contains($required, $frontier, "old-posting proof should retain hard evidence input: {$required}");
    }
    foreach ([
        'OLD_POSTING_FRONTIER_SHA256=',
        '${OLD_POSTING_FRONTIER_SCRIPT}:/proof/old-posting-frontier.php:ro',
        'WP_FTS_FRONTIER_HARNESS_SHA256=${OLD_POSTING_FRONTIER_SHA256}',
        'wordpress timeout -s KILL 300 php -d memory_limit=128M',
        'run_old_posting_frontier',
    ] as $required) {
        assert_contains($required, $runner, "runner should retain the source-bound frontier invocation: {$required}");
    }
    foreach ([
        'old_posting_frontier_artifact',
        'old_posting_frontier_disjoint_terms',
        'old_posting_frontier_pass_shapes',
        'old_posting_frontier_scan_rows',
        'old_posting_frontier_aggregate_rows',
        'old_posting_frontier_mutations',
        'old_posting_frontier_query_count',
        'old_posting_frontier_transaction_order',
        'old_posting_survivor_fixture',
        'old_posting_survivor_decrement',
        'old_posting_survivor_state',
        'old_posting_frontier_decrement_query_count',
        'old_posting_frontier_decrement_statement_bytes',
        'old_posting_frontier_decrement_elapsed_ms',
        'old_posting_frontier_decrement_server_rows_examined',
        'old_posting_frontier_decrement_server_rows_affected',
        'old_posting_frontier_decrement_disk_temp_tables',
        'old_posting_frontier_decrement_sort_merge_passes',
        'old_posting_frontier_decrement_server_ms',
        'old_posting_frontier_delete_query_count',
        'old_posting_frontier_delete_statement_bytes',
        'old_posting_frontier_delete_elapsed_ms',
        'old_posting_frontier_delete_server_rows_examined',
        'old_posting_frontier_delete_server_rows_affected',
        'old_posting_frontier_delete_disk_temp_tables',
        'old_posting_frontier_delete_sort_merge_passes',
        'old_posting_frontier_delete_server_ms',
        'old_posting_frontier_transaction_statements',
        'old_posting_frontier_pass_ms',
        'old_posting_frontier_server_rows_examined',
        'old_posting_frontier_disk_temp_tables',
        'old_posting_frontier_remaining_terms',
        'old_posting_frontier_preserved_decoy_postings',
        'old_posting_frontier_bad_doc_freqs',
        'old_posting_frontier_covering_index',
        'old_posting_frontier_inner_plan',
        'old_posting_frontier_cleanup',
        'old_posting_reset_populated_fixture',
        'old_posting_reset_storage_statements',
        'old_posting_reset_physical_schema',
        'old_posting_reset_published_state',
        'old_posting_frontier_php_peak',
    ] as $required) {
        assert_contains($required, $integration, "final evidence should retain frontier gate: {$required}");
    }
    foreach (['**57,344** old rows', '**50,001** rows inside', '**49,152** terms', '`doc_freq=2`', '`STRAIGHT_JOIN`', '**50,100**', 'combined posting/dictionary/document deletion', 'exactly **2** passes', '`post_term`', '**157,344** populated postings', 'storage-only proof', 'exactly **9**', 'database statements', 'no `DELETE` or `COUNT`', '**10 plugin-owned', 'one epoch read plus **9 writes**'] as $required) {
        assert_contains($required, $acceptance, "acceptance should retain the old-posting hard gate: {$required}");
    }
    assert_contains('exactly five', $acceptance, 'acceptance should retain the combined five-statement old-posting transaction boundary');
    assert_contains('`recoverable(kind,state,claim_expires_at,available_at,post_id,job_key)`', $acceptance, 'acceptance should retain the exact post-reset recoverable-work index');
    assert_contains("wp_fts_wc_gate('old_posting_frontier_transaction_statements', 5", $integration, 'final evidence should require the combined five-statement old-posting transaction boundary');
});

test_case('nontransactional work-table evidence distinguishes product recovery from fixture cleanup', function (): void {
    $root = dirname(__DIR__, 2);
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $pluginProof = (string) file_get_contents(__DIR__ . '/relational-input-containment.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');

    foreach ([
        'nontransactional_engine_repair_does_not_claim_state_preservation',
        'nontransactional_engine_fixture_cleanup_restored',
        "'production_repair_preserved_exact_work_state' => false",
        "'state_after_production_repair'",
        "'fixture_cleanup_state_after'",
    ] as $required) {
        assert_contains($required, $integration, "real engine fixture should retain truthful repair evidence: {$required}");
    }
    foreach ([
        'plugin schema repair replaces damaged work state with one bounded corpus recovery scope',
        "'scope:' . hash('sha256', WP_FTS_Index_Queue::GLOBAL_CORPUS_SCOPE_KEY)",
        "'schema_repair'",
        "!isset(\$fake->queue[88])",
    ] as $required) {
        assert_contains($required, $pluginProof, "Plugin-level recovery proof should retain semantic recovery invariant: {$required}");
    }
    foreach ([
        'Production does not preserve or',
        'exactly one',
        '`global-corpus` reconciliation scope',
        'explicitly labeled fixture cleanup, not',
    ] as $required) {
        assert_contains($required, $acceptance, "acceptance must distinguish product recovery from harness cleanup: {$required}");
    }
});

test_case('optimize acceptance tracks its one atomic cleanup data statement', function (): void {
    $root = dirname(__DIR__, 2);
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');
    $mysqlContracts = (string) file_get_contents(__DIR__ . '/mysql-wpcli-contracts.php');

    foreach ([
        'exactly **1 bounded indexed cleanup data statement**',
        'executes no transaction wrapper, cursor-epoch',
        'Shared writer-lease acquisition/release is',
        'measured separately from that single data statement',
    ] as $required) {
        assert_contains($required, $acceptance, "optimize acceptance should retain truthful storage work: {$required}");
    }
    foreach ([
        "assert_same(1, count(\$wpdb->queries)",
        "!str_contains(\$optimizeSql, 'START TRANSACTION')",
        "!str_contains(\$optimizeSql, 'meta:search-epoch')",
    ] as $required) {
        assert_contains($required, $mysqlContracts, "optimize contract should retain one-statement invariant: {$required}");
    }
});

test_case('real broad-query evidence rejects repeated inner visibility joins', function (): void {
    $root = dirname(__DIR__, 2);
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');

    foreach ([
        "'common_or', 'max_valid_or_prefix', 'prefix_fanout'",
        "substr_count(\$rankSql, ' d_f ON ')",
        "substr_count(\$rankSql, 'd_exact_match')",
        "substr_count(\$rankSql, 'd_prefix_match')",
        "{\$caseId}_broad_outer_visibility_shape",
        "{\$caseId}_broad_visibility_order",
        "\$rankedPosition < \$visibilityPosition",
        "\$visibilityPosition < \$orderPosition",
    ] as $required) {
        assert_contains($required, $integration, "real broad-query gate should retain final ranking shape: {$required}");
    }
    foreach (['compact posting arms first', 'exactly one', 'outer derived-document visibility join', '`d_exact_match` or `d_prefix_match`'] as $required) {
        assert_contains($required, $acceptance, "acceptance should retain broad visibility invariant: {$required}");
    }
});

test_case('relational worst-case proves actual huge postmeta containment on both engines', function (): void {
    $root = dirname(__DIR__, 2);
    $plugin = (string) file_get_contents($root . '/src/Plugin.php');
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $runner = (string) file_get_contents($root . '/tools/run-relational-fts-worst-case.sh');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');

    assert_same(1, substr_count($plugin, 'wp_fts:dependency_measurement'), 'the bounded dependency measurement should have one stable relational SQL tag');
    assert_same(2, substr_count($plugin, 'wp_fts:dependency_values'), 'both possible value branches should carry the same stable relational SQL tag');
    assert_contains('LEFT(CAST(pm.meta_value AS BINARY)', $plugin, 'MySQL and MariaDB dependency projections must count bytes, not characters');
    assert_contains('SUBSTR(CAST(pm.meta_value AS BLOB)', $plugin, 'SQLite dependency projections must count bytes, not characters');
    foreach ([
        'WP_FTS_WC_DEPENDENCY_LOB_BYTES = 262144',
        'WP_FTS_WC_DEPENDENCY_ACCEPTED_UNSELECTED_ROWS = 511',
        'WP_FTS_WC_DEPENDENCY_OVERFLOW_UNSELECTED_ROWS = 512',
        "update_option(WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION, ['selected_signal'], false)",
        "'dependency-lob' => wp_fts_wc_dependency_lob()",
        'REPEAT(%s,%d)',
        'performance_schema.events_statements_history_long',
        'EXPLAIN FORMAT=JSON',
        'dependency_lob_measurement_rows_sent',
        'dependency_lob_measurement_rows_examined',
        'dependency_lob_value_rows_sent',
        'dependency_lob_value_rows_examined',
        'dependency_lob_tmp_disk_tables',
        "wp_fts_wc_explain_dependency_sql(\$measurementSql, ['pm', 'postmeta'])",
        'count($measurementAccess) === 1',
        'dependency_lob_measurement_uses_post_id_index',
        'dependency_lob_value_uses_primary_index',
        'dependency_lob_accepted_selected_value_searchable',
        'dependency_lob_accepted_multibyte_round_trip',
        'dependency_lob_value_uses_binary_byte_slice',
        'dependency_lob_overflow_rejected',
        'dependency_lob_work_drained',
        'dependency_lob_growth_mutation_exactly_once',
        'dependency_lob_growth_projected_value_bytes',
        'dependency_lob_growth_transport_strictly_below_twice_measurement',
        'dependency_lob_growth_generation_deferred',
        'dependency_lob_growth_dirty_generation_hidden',
        'dependency_lob_growth_retry_exact_and_searchable',
        'dependency_lob_growth_rss_peak',
        'dependency_lob_fixture_cleanup',
    ] as $required) {
        assert_contains($required, $integration, "actual dependency LOB proof should retain {$required}");
    }
    assert_true(!str_contains($integration, 'dependency_lob_no_index_flags'), 'bounded derived-table scans must not be mistaken for unindexed base-table access');
    assert_contains('run_php_phase dependency-lob', $runner, 'every real database profile should execute the dependency LOB proof');
    assert_true(strpos($runner, 'run_php_phase dependency-lob') < strpos($runner, 'run_php_phase validate'), 'dependency LOB gates must exist before final validation evidence is assembled');
    foreach (['real `wp_postmeta` table', '511 unselected 256 KiB values', 'return 1,027 measurement rows', 'strictly below twice', 'between measurement and hydration', 'dirty and invisible', 'A fake database or small placeholder value'] as $required) {
        assert_contains($required, $acceptance, "acceptance writeup should retain actual LOB requirement: {$required}");
    }
    record_check('relational actual dependency LOB contract', 40);
});

test_case('relational worst-case composes maximum document work with fair scope alternation', function (): void {
    $root = dirname(__DIR__, 2);
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');

    foreach ([
        "'schema' => 'relational-fts-drain-v6'",
        'wp_fts_wc_mixed_scope_changed_batch(',
        'wp_fts_wc_composed_maximum_worker_path(',
        "'mixed_active_scope_continuous_arrival' => \$mixedActiveScope",
        "'mixed_exhausted_corpus_scope_continuous_arrival' => \$mixedExhaustedCorpusScope",
        "'composed_maximum_worker_path' => \$composedMaximumWorker",
        "(int) (\$documentTurn['total_statement_count'] ?? PHP_INT_MAX) <= 20",
        "(int) (\$scopeTurn['total_statement_count'] ?? PHP_INT_MAX) <= 20",
        "(int) (\$scopeTurn['deferred'] ?? -1) === 100",
        'SCOPE_EXPANSION_TURN_CODE',
        'scope_yield_to_posts',
        'scope_yield_and_post_batch_release',
        'post_batch_release',
        'corpus_scope_page',
        'scope_page_advance',
        'scope_acknowledgement',
        'health_state_cas',
        'conditional_source_fallback',
        'bounded_two_statement_source_protocol',
        "SET last_error_code='content_failure'",
        "'resolved_failure_records' => !empty(\$summary['resolved_failure_records'])",
        "'scheduling_control_statement_count' => count(\$roles['scheduling_control_roles'])",
        "'with_later_existing_event'",
        "'later_event_brought_forward'",
        "'max_scheduling_control_statement_count'",
        'worker_mixed_active_maximum_documents',
        'worker_mixed_active_continuous_arrival',
        'worker_mixed_active_scope_progress',
        'worker_mixed_exhausted_maximum_documents',
        'worker_mixed_exhausted_continuous_arrival',
        'worker_mixed_exhausted_scope_completion',
        'worker_composed_cron_no_event',
        'worker_composed_cron_later_event',
        'worker_composed_recovery_diagnostics_deferred',
        'worker_composed_claim_snapshot_fallback',
        'worker_composed_complete_statement_ceiling',
        'worker_composed_data_statement_ceiling',
        'worker_composed_scheduling_control_ceiling',
    ] as $required) {
        assert_contains($required, $integration, "mixed worker proof should retain hard alternation contract: {$required}");
    }
    assert_true(
        strpos($integration, "\$mixedActiveScope = wp_fts_wc_mixed_scope_changed_batch(")
            < strpos($integration, "\$mixedExhaustedCorpusScope = wp_fts_wc_mixed_scope_changed_batch("),
        'the real drain should prove an active cursor advance before exact exhausted corpus completion'
    );
    foreach ([
        'continuous-arrival alternation',
        'at most 20 complete worker statements',
        '**100** newly changed direct documents',
        'scope cursor advances',
        'exhausted corpus scope is acknowledged',
        'later existing singleton',
        'exactly one cron-option write',
        'prior `content_failure`',
        '8,192 distinct identities',
        '255-byte raw terms',
    ] as $required) {
        assert_contains($required, $acceptance, "acceptance writeup should retain fair mixed-work requirement: {$required}");
    }
});

test_case('relational worst-case generation fence uses the current queue claim API', function (): void {
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $generationFence = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_generation_fence_proof');

    assert_same(2, substr_count($generationFence, '->claim_batch('), 'both generation-fence claims should use the current bounded API');
    assert_true(!str_contains($generationFence, '->claim('), 'the real database proof must not call the removed queue API');
});

test_case('worker ceiling quality contracts retain hard composed and ambiguous outcome assertions', function (): void {
    $root = dirname(__DIR__, 2);
    $quality = dirname(__DIR__);
    $composed = (string) file_get_contents($quality . '/quality/worker-composed-statement-ceiling.php');
    $lateCommit = (string) file_get_contents($quality . '/quality/worker-late-commit-statement-ceiling.php');
    $plugin = (string) file_get_contents($root . '/src/Plugin.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');

    assert_true(!is_file($quality . '/quality/worker-source-overflow-statement-ceiling.php'), 'the redundant source-overflow diagnostic must stay removed');
    assert_true(!is_file($quality . '/quality/worker-source-dependency-overflow-statement-ceiling.php'), 'the redundant dependency-overflow diagnostic must stay removed');
    foreach ([
        'maximum mixed worker composition stays inside the complete statement ceiling',
        'content failure settles before maximum writer work can compose with it',
        "assert_same(19, count(\$scenario['queries'])",
        "assert_same(15, count(array_filter(",
        "assert_same(1, \$scenario['cron_write_count']",
        "assert_same(20, count(\$scenario['queries']) + \$scenario['cron_write_count']",
        "'conditional_source_fallback'",
        "'scope_yield_and_post_batch_release'",
        "assert_same(true, \$summary['resolved_failure_records']",
        "assert_same(9, count(\$scenario['queries'])",
        "assert_same('retry', \$fake->queue[1]['state']",
    ] as $required) {
        assert_contains($required, $composed, "permanent composed worker contract should retain: {$required}");
    }
    foreach (['fwrite(STDERR', "assert_true(true, 'diagnostic')", 'AUDIT_', 'OVERFLOW_COUNT'] as $forbidden) {
        assert_true(!str_contains($composed, $forbidden), "permanent composed worker contract must not retain diagnostic escape hatch: {$forbidden}");
    }

    foreach ([
        "[false => 'rejected', true => 'applied-but-reported-failed']",
        "count(\$queries)",
        'exact nineteen-statement protocol',
        "count(\$queries) + (int) \$scenario['cron_write_count'] <= 20",
        "strtoupper(trim(\$sql)) === 'COMMIT'",
        "strtoupper(trim(\$sql)) === 'ROLLBACK'",
        "'stale_writer_lease_recovered'",
        "assert_same(0, \$takeover['indexed']",
        'count($takeoverQueries) <= 5',
        "assert_same(1, \$ordinary['indexed']",
    ] as $required) {
        assert_contains($required, $lateCommit, "ambiguous COMMIT/stale takeover contract should retain: {$required}");
    }
    foreach ([
        "self::remember_index_batch_stop(\$summary, 'stale_writer_lease_recovered')",
        "if (\$recovered_stale_lease)",
        "'_writer_transaction_attempted'",
        "(!empty(\$summary['resolved_failure_records']) && empty(\$summary['_writer_transaction_attempted']))",
    ] as $required) {
        assert_contains($required, $plugin, "production worker should retain isolated diagnostics/lease rule: {$required}");
    }
    foreach ([
        'ambiguous COMMIT',
        'applied-but-reported-failed',
        'stale writer-lease takeover',
        'control-only phase',
    ] as $required) {
        assert_contains($required, $acceptance, "acceptance writeup should retain ambiguous publication boundary: {$required}");
    }
});

test_case('SQLite writer transport remains linear and distinct from the real MySQL maximum-width proof', function (): void {
    $root = dirname(__DIR__, 2);
    $storage = (string) file_get_contents($root . '/src/RelationalStorage.php');
    $containment = (string) file_get_contents(__DIR__ . '/sqlite-writer-transport-containment.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');
    $replace = wp_fts_wc_contract_function_source($storage, 'replace_prepared_documents');
    $partition = wp_fts_wc_contract_function_source($storage, 'partition_prepared_documents');
    $preflight = wp_fts_wc_contract_function_source($storage, 'sqlite_prepared_transport_prefix');

    assert_contains(
        'The MySQL/MariaDB ASCII-base64 path keeps all 8,192 maximum-width',
        $storage,
        'the 8,192-identity resolver claim should remain scoped to the MySQL/MariaDB transport'
    );
    foreach ([$replace, $partition] as $caller) {
        assert_same(1, substr_count($caller, 'sqlite_prepared_transport_prefix('), 'each SQLite writer entry point should perform one cumulative transport preflight');
    }
    $transportPosition = strpos($replace, 'sqlite_prepared_transport_prefix(');
    $frontierPosition = strpos($replace, 'plan_prepared_replacement(', is_int($transportPosition) ? $transportPosition : 0);
    $transactionPosition = strpos($replace, 'begin_transaction()', is_int($frontierPosition) ? $frontierPosition : 0);
    assert_true(
        is_int($transportPosition)
            && is_int($frontierPosition)
            && is_int($transactionPosition)
            && $transportPosition < $frontierPosition
            && $frontierPosition < $transactionPosition,
        'SQLite transport rejection/splitting must precede the old-posting frontier and transaction'
    );
    foreach ([
        '$identityVisits++;',
        'sqlite_dictionary_increment_row(',
        'sqlite_identity_relation_row(',
        "'accepted_documents' => \$acceptedDocuments",
        "'dictionary_bytes' => \$dictionaryBytes",
        "'resolution_bytes' => \$resolutionBytes",
        "'identity_visits' => \$identityVisits",
    ] as $required) {
        assert_contains($required, $preflight, "linear SQLite transport preflight should retain: {$required}");
    }
    foreach (['array_slice(', 'sqlite_prepared_documents_fit(', '$this->query(', '$this->get_results('] as $forbidden) {
        assert_true(!str_contains($preflight, $forbidden), "linear SQLite transport preflight must not retain repeated-prefix or SQL work: {$forbidden}");
    }
    foreach ([
        'SQLite maximum prepared identity document is a permanent pre-SQL rejection',
        'SQLite largest maximum-width transport boundary uses one dictionary write and one resolver',
        'SQLite maximum-width renderers and fake decoders retain no complete row copy',
        'SQLite maximum-width writer survives retained suite state under 128 MiB',
        'SQLite aggregate transport splits once and preflights 100 documents linearly under 128 MiB',
        '60 * 1024 * 1024',
        "assert_same(8192, \$evidence['input_identities']",
        "<= 8192, 'the cumulative preflight must visit no identity more than the bounded input once'",
        "memory_limit=128M",
    ] as $required) {
        assert_contains($required, $containment, "SQLite transport containment should retain: {$required}");
    }
    foreach ([
        'MySQL/MariaDB maximum-width 8,192-identity resolver',
        'SQLite maximum-width writer transport',
        '7,098 identities fit',
        '`sqlite_transport_limit` rejection',
        'one linear pure-PHP pass',
        'not a weakening of the required real',
    ] as $required) {
        assert_contains($required, $acceptance, "acceptance should distinguish SQLite transport from real MySQL/MariaDB width: {$required}");
    }
});

test_case('relational worst-case conditioning and phase evidence cannot pass on summaries alone', function (): void {
    $root = dirname(__DIR__, 2);
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $reader = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-concurrent-reader.php');
    $runner = (string) file_get_contents($root . '/tools/run-relational-fts-worst-case.sh');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');
    $mysqlStorage = (string) file_get_contents($root . '/src/RelationalStorage.php');
    $plugin = (string) file_get_contents($root . '/src/Plugin.php');
    $concurrentWriter = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_concurrent_writer');
    $finalize = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_finalize');
    foreach ([
        'WP_FTS_WC_COLD_EVICTION_BYTES = 536870912',
        'WP_FTS_WC_COLD_EVICTION_ROW_BYTES = 65536',
        "'cold-prepare' => wp_fts_wc_cold_prepare()",
        "'cold-cleanup' => wp_fts_wc_cold_cleanup()",
        'SUM(CRC32(payload))',
        'cold_eviction_buffer_pool_ratio',
        'cold_eviction_relation_cleaned',
        'wp_fts_wc_require_evidence_gates',
        'evidence_gate_ids_unique',
        'concurrency_baseline_case_oracles',
        'concurrency_writer_assignments',
        'concurrent_reader_{$worker}_overlap_seconds',
        'concurrent_writer_{$worker}_overlap_seconds',
        'concurrent_shared_window_identity',
        'concurrent_all_worker_intersection_seconds',
        'concurrent_writer_{$worker}_independent_progress',
        'concurrent_http_attempts',
        'concurrent_unavailable_retries',
        'worker_full_100_document_batch',
        'worker_changed_batch_analyzed',
        'worker_changed_batch_unchanged',
        'worker_changed_batch_statement_scope',
        'worker_changed_batch_total_statement_count',
        'worker_changed_batch_hashes_rewritten',
        'worker_drain_remaining',
        'full_rebuild_hashes_invalidated',
        'relational-fts-reindex-drain-v1',
        'reindex_enqueue_constant_scope',
        'reindex_worker_passes',
        'reindex_terminal_cleanup_pass',
        'indexing_rebuild_document_count',
        'indexing_rebuild_valid_content_hashes',
        'indexing_rebuild_markers_remaining',
        'schema_exact_physical_contract',
        "str_starts_with(\$type, 'varbinary(')",
        'hex2bin($hexDefault)',
        'function wp_fts_wc_search_ready_options(array $options): array',
        "'_search_ready_profile_hash' => \$profileHash",
        'column definitions do not exactly match the production schema contract',
        'indexes do not exactly match the production schema contract',
        'oracle_complete_slice_membership_and_order',
        'max_valid_setup_artifact',
        'max_valid_setup_artifact_self_hash',
        'relational-fts-max-valid-seed-v1',
        'relational-fts-max-valid-setup-v3',
        '$maxValidSetupSeedProcessIdentity === $maxValidSeedProcessIdentity',
        '$maxValidIds === $maxValidSeedIds',
        'max_valid_setup_worker_progress',
        "'passes' => '1..100'",
        'is_int($indexed) && $indexed >= 0',
        'max_valid_setup_worker_statement_bound',
        'max_valid_setup_worker_sql_bound',
        'max_valid_setup_worker_duration_bound',
        'max_valid_setup_worker_memory_bound',
        'relational-fts-max-valid-search-v2',
        'max_valid_frontend_content',
        'max_valid_frontend_artifact',
        'WP_FTS_WC_MAX_VALID_SEARCH_DELTA_BYTES = 25165824',
        "'results' => \$results",
        "'content_sha256' => \$expectedHash",
        'wp_fts_wc_max_valid_frontend_artifact_is_exact',
        "'statement_count' => count(\$queries)",
        "'statement_bytes' => \$statementBytes",
        "'statement_sha256' => \$statementHashes",
        "'statement_roles' => \$statementRoles['roles']",
        "'php_peak_delta_bytes' => max(0, \$phpPeakAfterReset - \$phpUsageBefore)",
        "'rss_peak_delta_bytes' => max(0, \$rssPeakAfter - \$rssBefore)",
        "'measurement_method' => 'fresh_phase_process_per_pass_php_peak_reset_conservative_rss_hwm'",
        'wp_fts_wc_max_valid_content_sha256()',
        '$phpPeak >= $workerPhpPeakMax',
        '$rssPeak >= $workerRssPeakMax',
        ". str_repeat('x', \$contentBytes - strlen(\$visibleContent . \$commentOpen . \$commentClose))",
        'max_connections must be exactly 24',
        'PHP memory_limit must be exactly 128 MiB',
    ] as $required) {
        assert_contains($required, $integration, "integration proof should retain hard conditioning/evidence contract: {$required}");
    }
    assert_true(!str_contains($integration, '$allPassesMadeProgress'), 'maximum-valid setup must permit measured housekeeping passes before its targeted rows progress');
    assert_contains("'started_monotonic_ns' => \$startedNs", $integration, 'concurrent workers must retain their monotonic start inside the shared window');
    assert_contains("'finished_monotonic_ns' => \$finishedNs", $integration, 'concurrent workers must retain their monotonic finish inside the shared window');
    assert_contains("'started_monotonic_ns' => \$startedNs", $reader, 'lightweight readers must retain their monotonic start inside the shared window');
    assert_contains("'finished_monotonic_ns' => \$finishedNs", $reader, 'lightweight readers must retain their monotonic finish inside the shared window');
    assert_contains('wp_fts_wc_elapsed_ms($batchStarted)', $integration, 'concurrent writer batches must retain a separate high-resolution timer');
    foreach ([
        "\$work = wp_fts_wc_identifier(\$wpdb->prefix . 'fts_work');",
        '$mutationIntervalNs = 15000000000;',
        '$lastMutationDeadlineNs = $deadlineNs - 5000000000;',
        '$assignedWork === 0',
        '$batchStarted >= $nextMutationNs',
        '$batchStarted < $lastMutationDeadlineNs',
        'relational-fts-concurrent-writer-v3',
        "\$errorMessage === 'Could not acquire the FTS index writer lease.'",
        "str_contains(\$databaseError, 'Deadlock found when trying to get lock')",
        '$deadlockRetries <= 8',
    ] as $required) {
        assert_contains($required, $concurrentWriter, "concurrent writer pacing should retain: {$required}");
    }
    foreach ([
        "['terminal' => 0, 'deadlock_retries' => '<= 12']",
        '$writerFailures === 0 && $writerDeadlockRetries <= 12',
        '$batchDeadlockRetries <= 8',
        '$batchMutations > 0',
        '$recordedDeadlockRetry === $recognizedDeadlockRetry',
    ] as $required) {
        assert_contains($required, $finalize, "concurrent writer validation should retain: {$required}");
    }
    foreach ([
        'relational-fts-concurrency-baseline-v5',
        'WP_FTS_WC_CONCURRENT_READER_SHA256',
        'relational-fts-concurrent-reader-v3',
        'WP_FTS_READER_MAX_UNAVAILABLE_RETRIES = 3',
        'wp_fts_reader_is_publication_retry',
        "'unavailable_retries' => \$unavailableRetries",
        "'harness_sha256' => \$expectedHarnessHash",
        "'request_url'",
        'CURLOPT_FOLLOWLOCATION => false',
        'CURLOPT_TIMEOUT => 120',
        'usleep(250000);',
        'count($ids) === count(array_unique($ids))',
    ] as $required) {
        assert_contains($required, $integration . $reader . $runner, "lightweight reader contract should retain: {$required}");
    }
    assert_true(!str_contains($reader, 'wp_fts_wc_bootstrap_wordpress'), 'REST reader clients must not bootstrap a second WordPress runtime');
    assert_true(!str_contains($integration, "'concurrent-reader' =>"), 'the main database harness must not retain a second reader implementation');
    assert_contains('run_concurrent_reader_phase "${worker}"', $runner, 'the runner should launch each lightweight REST reader');
    assert_contains('run --rm --no-deps -T --entrypoint php', $runner, 'REST load generators should run outside the persistent WordPress server cgroup');
    assert_contains('wordpress /proof/relational-fts-concurrent-reader.php', $runner, 'REST load generators should reuse the pinned WordPress image without its server entrypoint');
    assert_true(!str_contains($runner, 'run_php_phase concurrent-reader'), 'the runner must not launch full WordPress CLI readers');
    foreach (['StartServers 2', 'MinSpareServers 1', 'MaxSpareServers 2', 'MaxRequestWorkers 8', 'MaxConnectionsPerChild 0'] as $directive) {
        assert_contains($directive, $runner, "the constrained Apache prefork configuration should retain {$directive}");
    }
    assert_contains('ACTIVE_APACHE_MPM', $runner, 'the runner should compare the mounted Apache prefork configuration after startup');
    assert_contains('Apache prefork capped at eight request workers', $acceptance, 'the server resource contract should name the exact concurrent request capacity');
    assert_contains('ephemeral containers on the same Docker network', $acceptance, 'the server memory contract should not charge load-generator clients to Apache');
    assert_contains('once every 15 seconds', $acceptance, 'the written concurrency contract should retain paced writer generations');
    assert_contains('at most eight recognized deadlocks per writer may retry', $acceptance, 'the written concurrency contract should bound only recognized writer deadlocks');

    $indexingPrepare = strpos($runner, 'run_php_phase indexing-prepare');
    $timedIndex = strpos($runner, 'INDEX_STARTED=', $indexingPrepare === false ? 0 : $indexingPrepare);
    $reindexDrain = strpos($runner, 'run_php_phase reindex-drain', $timedIndex === false ? 0 : $timedIndex);
    $validation = strpos($runner, 'run_php_phase validate', $timedIndex === false ? 0 : $timedIndex);
    assert_true(is_int($indexingPrepare) && is_int($timedIndex) && is_int($reindexDrain) && is_int($validation), 'runner should retain the asynchronous forced full-rebuild lifecycle');
    assert_true($indexingPrepare < $timedIndex && $timedIndex < $reindexDrain && $reindexDrain < $validation, 'every timed current reindex must be invalidated, enqueued, drained in bounded production passes, and then verified');
    assert_contains('relational-fts-indexing-prepare-v2', $runner, 'the runner should consume the exact preparation schema emitted by the integration phase');
    $prepare = strpos($runner, 'run_php_phase cold-prepare');
    $sample = strpos($runner, 'run_php_phase cold-sample');
    $cleanup = strpos($runner, 'run_php_phase cold-cleanup');
    $concurrency = strpos($runner, 'run_php_phase concurrency-setup');
    assert_true(is_int($prepare) && is_int($sample) && is_int($cleanup) && is_int($concurrency), 'runner should retain all cold lifecycle and concurrency phases');
    assert_true($prepare < $sample && $sample < $cleanup && $cleanup < $concurrency, 'every cold sample must be conditioned and the auxiliary relation removed before concurrency');

    $documentsSchemaStart = strpos($mysqlStorage, '"CREATE TABLE {$this->documentsTable} (');
    $documentsSchemaEnd = strpos($mysqlStorage, '"CREATE TABLE {$this->workTable} (', $documentsSchemaStart === false ? 0 : $documentsSchemaStart);
    assert_true(is_int($documentsSchemaStart) && is_int($documentsSchemaEnd), 'production documents schema should remain inspectable');
    $documentsSchema = substr($mysqlStorage, $documentsSchemaStart, $documentsSchemaEnd - $documentsSchemaStart);
    assert_true(!str_contains($documentsSchema, 'doc_len'), 'production documents schema must not persist a scalar document length');
    assert_contains("'columns' => ['post_id', 'primary_lang', 'content_hash', 'snippet_text', 'indexed_at']", $mysqlStorage, 'schema verification must retain the exact production document columns');
    assert_contains('foreach (array_diff($physical[\'columns\'], $contract[\'columns\']) as $column)', $mysqlStorage, 'schema verification must reject every unexpected production column, including a leftover document length');
    $acceptanceSchema = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_assert_relational_schema');
    assert_contains("'content_hash' => ['type' => 'varbinary(40)', 'nullable' => false, 'default' => null, 'extra' => '']", $acceptanceSchema, 'the real schema proof should require the current fixed-width content hash');
    assert_contains("'snippet_text' => ['type' => 'mediumtext', 'nullable' => false, 'default' => null, 'extra' => '']", $acceptanceSchema, 'the real schema proof should require the current non-null snippet transport');
    assert_contains("INSERT IGNORE INTO {\$table} (option_name,option_value,autoload)", $plugin, 'the uncontended worker lease must remain one atomic option-table statement');
    assert_contains('WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION => []', $plugin, 'current setup should initialize the bounded custom-field configuration');
    assert_contains("UPDATE `{\$table}` SET autoload = 'yes' WHERE option_name IN", $plugin, 'current setup should autoload every bounded search input before worker and visitor requests');
    $atomicAcknowledgement = wp_fts_wc_contract_function_source($plugin, 'acknowledge_claims_under_index_lock');
    $claimRetirement = strpos($atomicAcknowledgement, '$result = $wpdb->query($statement);');
    $epochAdvance = strpos(
        $atomicAcknowledgement,
        '$storage->advance_epoch_before_capability_retirement();',
        $claimRetirement === false ? 0 : $claimRetirement
    );
    assert_true(
        is_int($claimRetirement) && is_int($epochAdvance) && $claimRetirement < $epochAdvance,
        'atomic worker publication must lock work rows before the singleton cursor epoch'
    );
    assert_true(
        str_contains($plugin, "property_exists(\$post, 'fts_language_override')")
            && str_contains($plugin, "property_exists(\$post, 'fts_integration_language')"),
        'the dependency snapshot must carry authoritative override and integration language state through analyzer callbacks'
    );

    $contentBytes = 1900000;
    $visibleContent = '<p>maxsizeprobe</p>';
    $commentOpen = '<!--';
    $commentClose = '-->';
    $nearLimitContent = $visibleContent
        . $commentOpen
        . str_repeat('x', $contentBytes - strlen($visibleContent . $commentOpen . $commentClose))
        . $commentClose;
    assert_same($contentBytes, strlen($nearLimitContent), 'near-limit proof source should retain its exact 1.9 MB transport size');
    assert_same('maxsizeprobe', WP_FTS_Html_Text_Stream::visible_text($nearLimitContent), 'near-limit padding must not become a forbidden visible lexical run');
    $nearLimitTerms = (new WP_FTS_Analyzer())->analyze_content($nearLimitContent, ['document_lang' => 'en']);
    assert_same(1, count($nearLimitTerms), 'near-limit valid source should analyze one bounded visible term');
    assert_same('en', $nearLimitTerms[0]['lang'] ?? null, 'near-limit valid source should preserve its explicit analyzer language');

    foreach (['all 8,192', 'dedicated 512-MiB InnoDB relation', 'every process records its own elapsed time', 'empty gate list', '`relational-fts-max-valid-setup-v3`', '20 recognized statements', 'no statement above 4 MiB', 'add at most 32 MiB PHP and RSS', 'at most 24 MiB PHP/RSS'] as $required) {
        assert_contains($required, $acceptance, "acceptance writeup should retain non-synthetic worst-case requirement: {$required}");
    }
    record_check('relational hard conditioning contract', 38);
});

test_case('relational worst-case database plan access records MySQL materialization and scan estimates', function (): void {
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    if (!function_exists('wp_fts_wc_collect_database_table_access')) {
        eval(wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_collect_database_table_access'));
    }

    $access = [];
    wp_fts_wc_collect_database_table_access([
        'table' => [
            'table_name' => 'filtered_candidates',
            'access_type' => 'ALL',
            'rows_examined_per_scan' => 2,
            'materialized_from_subquery' => [
                'query_block' => [
                    'table' => [
                        'table_name' => 'p',
                        'access_type' => 'range',
                        'key' => 'wp_fts_type_status_id',
                        'rows_examined_per_scan' => 100,
                    ],
                ],
            ],
        ],
    ], $access);

    assert_same([
        [
            'table_name' => 'filtered_candidates',
            'access_type' => 'ALL',
            'possible_keys' => [],
            'key' => '',
            'rows' => 2,
            'materialized' => true,
        ],
        [
            'table_name' => 'p',
            'access_type' => 'range',
            'possible_keys' => [],
            'key' => 'wp_fts_type_status_id',
            'rows' => 100,
            'materialized' => false,
        ],
    ], $access, 'MySQL plan aliases must retain bounded materialization and leaf scan roles');
});

test_case('taxonomy scope fail-closed search retains server-measured worst-case proof', function (): void {
    $root = dirname(__DIR__, 2);
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');
    $runner = (string) file_get_contents($root . '/tools/run-relational-fts-worst-case.sh');
    $scopeLifecycle = (string) file_get_contents(__DIR__ . '/scope-keyset-index-lifecycle.php');

    foreach ([
        'scopeSearchThreadId',
        'performance_schema.events_statements_history_long',
        'wp_fts_wc_search_statement_metrics($scopeSearchEvents)',
        "'plan' => 1, 'rank' => 0, 'hydrate' => 0",
        "'taxonomy_scope_search_duration_ms'",
        "'taxonomy_scope_active_surface_range_gated'",
        "'taxonomy_scope_search_rows_examined'",
        "'taxonomy_scope_search_disk_temp_tables'",
        "'taxonomy_scope_search_sort_merge_passes'",
        "'taxonomy_scope_search_server_duration_ms'",
        "'taxonomy_scope_search_server_attribution'",
        "'taxonomy_scope_search_explain_statement_shape'",
        "'taxonomy_scope_search_explain_indexed'",
        'function wp_fts_wc_sparse_provider_plan_proof(',
        "'provider_sparse_four_real_scenarios'",
        "'polylang_sparse_raw_frontier_cardinality'",
        "'polylang_sparse_raw_frontier_completion'",
        "'wpml_sparse_rows_examined'",
        'function wp_fts_wc_dense_relationship_search_proof(',
        '$expectedRelationships = $expectedObjects * $relationshipsPerObject',
        "'dense_relationship_no_scope_zero_sql_or_plan_access'",
        "'dense_relationship_active_targeted_plan_only'",
        "'dense_relationship_plan_rank_scope_race_shape'",
        "'dense_relationship_fixture_cleanup'",
        "return ['terms', 'work'];",
        'bounded_dictionary_and_control_plan',
        'hydrated_full_50_row_page',
        '$scopeSearchRowsExaminedLimit = 256',
        "'EXPLAIN FORMAT=JSON ' . \$sql",
        "'search_instrumentation' => [",
        "'performance_schema_sql_sha256' => \$scopeSearchPerformanceSqlHashes",
        'function wp_fts_wc_targeted_scope_expansion_proof()',
        '$gapCount = 100000',
        '$denseTargetCount = 100000',
        'ENGINE=MyISAM',
        'KEY term_taxonomy_id (term_taxonomy_id)',
        'function wp_fts_wc_populated_scope_index_repair(',
        'COUNT(*) AS row_count',
        'function wp_fts_wc_scope_ddl_writer()',
        "'scope_index_repair_innodb_core_clones'",
        "'scope_index_repair_fixture_cardinality'",
        "'scope_index_repair_exact_ddl'",
        "'scope_index_repair_ownership_before_ddl'",
        "'scope_index_repair_schema_version_stable'",
        "'scope_index_repair_performance_schema_attribution'",
        "'scope_index_repair_concurrent_writes'",
        "'scope_index_repair_write_overlap'",
        "'scope_index_repair_write_duration_ms'",
        "'scope_index_repair_storage_delta'",
        "'scope_index_repair_readiness_preserved'",
        "'scope_index_repair_work_preserved'",
        "'relational-fts-populated-scope-index-repair-v1'",
        "'posts' => 100001, 'relationships' => 300001",
        'Could not seed the target relationship cursor sentinel.',
        'VmHWM',
        'wp_fts:targeted-scope-page',
        'wp_fts:filtered-scope-page',
        'wp_fts:corpus-scope-page',
        'scope_rel FORCE INDEX (`wp_fts_term_object`)',
        'p FORCE INDEX (`wp_fts_type_status_id`)',
        'MAX(filtered_candidates.should_process)',
        'GROUP BY filtered_candidates.post_id',
        'function wp_fts_wc_measure_scope_gap(',
        "'scope_expansion_real_keyset_indexes'",
        "'scope_expansion_index_ownership'",
        "'scope_expansion_noncovering_decoy_index'",
        "'targeted_scope_expansion_one_statement_per_page'",
        "'filtered_scope_expansion_one_statement_per_page'",
        "'corpus_scope_expansion_one_statement_per_page'",
        "'filtered_scope_max_lanes_one_statement_per_page'",
        "'filtered_scope_lane_overflow_zero_sql'",
        "'targeted_scope_expansion_server_attribution'",
        "'targeted_scope_expansion_metadata_server_attribution'",
        "'targeted_scope_expansion_rows_examined_per_page'",
        "'filtered_scope_expansion_rows_examined_per_page'",
        "'filtered_scope_max_lanes_rows_examined_per_page'",
        "'corpus_scope_expansion_explain_bounded_derived'",
        "'scope_expansion_fixture_cleanup'",
        "'relational-fts-scope-expansion-v4'",
        "'schema' => 'relational-fts-scope-gap-sweep-v2'",
        "'selector_measurements' => \$selectorMeasurements",
        "'metadata_measurements' => \$metadataMeasurements",
        "'timer_wait_picoseconds' => \$timerWait",
        '9700',
        'SORT_ROWS',
        'array_change_key_case($row, CASE_LOWER)',
        "static fn(array \$row): bool => empty(\$row['materialized'])",
        "static fn(array \$row): bool => !empty(\$row['materialized'])",
    ] as $required) {
        assert_contains($required, $integration, "scope proof should retain measured anti-join evidence: {$required}");
    }
    assert_same(2, substr_count($integration, "'taxonomy_scope_active_surface_range_gated'"), 'the gated surface-range proof must be emitted once and consumed once during finalization');
    assert_contains('old-posting-frontier|scope-ddl-writer|scope-proof', $runner, 'the populated scope-index DDL phase should retain its 1,800-second external kill');
    assert_same(1, substr_count($runner, 'run_php_phase scope-ddl-writer'), 'the populated scope-index proof should load one persistent lightweight writer process');
    assert_contains('scope_ddl_writer_pid=$!', $runner, 'the populated scope-index proof should supervise its one persistent writer process');
    assert_contains("'pid' => \$pid", $integration, 'all four populated scope-index writes should identify their one persistent writer process');
    $scopeWriterDispatch = strpos($integration, "if (\$phase === 'scope-ddl-writer')");
    $wordpressBootstrapDispatch = strpos($integration, 'wp_fts_wc_bootstrap_wordpress();');
    assert_true(is_int($scopeWriterDispatch) && is_int($wordpressBootstrapDispatch) && $scopeWriterDispatch < $wordpressBootstrapDispatch, 'the lightweight DDL writer should run before the WordPress bootstrap branch');
    assert_contains("'wordpress_bootstrapped' => false", $integration, 'all four populated scope-index writes should record the lightweight runtime boundary');
    assert_contains('4 exact successful canonical writes from 1 persistent lightweight process', $integration, 'the populated scope-index gate should reject extra writer runtimes');
    assert_true(!str_contains($runner, 'WP_FTS_WC_DDL_OPERATION='), 'the DDL proof must not load one WordPress runtime per operation');
    assert_true(!str_contains($runner, 'WP_FTS_WC_DDL_ORDINAL=${ordinal}'), 'the DDL proof must not load one WordPress runtime per index and operation');
    foreach ([
        'resumes an interrupted two-index install without duplicate DDL',
        "\$fake->failQueryNeedleOccurrence = 2",
        'stops after first DDL when its writer lease is stolen',
        'lease loss after first CREATE must prevent the second core-table DDL',
        'a same-name index collision before ownership or DDL',
    ] as $required) {
        assert_contains($required, $scopeLifecycle, "scope-index lifecycle proof should retain failure contract: {$required}");
    }
    foreach ([
        'broad `prefixprobe*` surface query',
        'raise the typed unavailable error after exactly one plan statement',
        'at most 256 indexed rows',
        'one completed Performance Schema event',
        '`fts_terms` plus `fts_work` relation allowlist',
        '100,000 unrelated dirty rows',
        'only the direct dirty anti-join',
        '51,200,000 physical rows',
        'MyISAM only to make construction',
        'zero references to that dense relationship table',
        'after plan has completed but before',
        'Rank\'s driving snapshot control must reject it before surface',
        'examine at most 256 rows, send zero rows',
        'exactly plan+rank',
        'removes taxonomy fanout from search complexity entirely',
        'complete prepared production UNION',
        'four requested posts with',
        'explicit 2,049th cursor sentinel',
        '`el_type_id`',
        'public maximum of 50',
        'hydrating a full 50-row page',
        'complete physical relation allowlist is',
        'The current schema requires two',
        '`wp_fts_term_object(term_taxonomy_id, object_id)`',
        '`wp_fts_type_status_id(post_type, post_status, ID)`',
        'tables with their real InnoDB definitions',
        '100,001 posts and 300,001 relationships',
        'populated repair proof',
        'regions plus one explicit cursor sentinel',
        'canonical `CREATE INDEX` statements',
        'One persistent lightweight core-table writer process synchronizes',
        'server-timer interval that overlaps',
        '180,000 ms total client ceiling',
        'positive index-byte delta',
        'genuinely non-covering',
        '1,000 full member pages plus one exhaustion page',
        'proportional to affected membership',
        'two statements, not 1,002 raw-ID scans',
        'exactly 32 `wp_fts_type_status_id` branches',
        'at most 9,700 rows',
        'examines at most 900 rows',
        'exceeds the 32-lane contract',
        'Only corpus reconciliation is intentionally proportional',
        'at-most-200-row derived relation',
        'exactly one tagged data selector per page',
        'at most two plugin',
        'one set-oriented metadata query',
        'separately matches selector and metadata',
        '`NO_GOOD_INDEX_USED`',
    ] as $required) {
        assert_contains($required, $acceptance, "acceptance should retain taxonomy-scope worst case: {$required}");
    }
});

test_case('cold ready requests prove the complete plugin SQL set from connection start', function (): void {
    $root = dirname(__DIR__, 2);
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $runner = (string) file_get_contents($root . '/tools/run-relational-fts-worst-case.sh');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');
    $plugin = (string) file_get_contents($root . '/src/Plugin.php');

    foreach ([
        "'cold-ready-request' => wp_fts_wc_cold_ready_request()",
        'relational-fts-cold-ready-request-v2',
        "run_php_phase cold-ready-request",
        "'cold_ready_current_schema_option'",
        "'cold_ready_current_schema_requests'",
        "'cold_ready_request_autoloaded_options'",
        "'cold_ready_request_no_option_or_sitemeta_sql'",
        "'cold_ready_request_no_network_token_select'",
        "'cold_ready_request_zero_plugin_sql'",
        "'cold_ready_request_impossible_statement_shape'",
        "'cold_ready_request_nonhydrate_statement_shape'",
        "'cold_ready_request_hydrated_statement_shape'",
        'wp_fts_wc_plugin_query',
        'debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 32)',
        "add_filter('wp_fts_debug_enabled'",
        'first_connection_event_id',
        'plugin_option_sitemeta_events',
        'actual_{$caseId}_exact_plugin_statement_set',
        'actual_{$caseId}_no_plugin_option_or_sitemeta_sql',
    ] as $required) {
        $haystack = str_starts_with($required, 'run_php_phase') ? $runner : $integration;
        assert_contains($required, $haystack, "cold request proof should retain hard evidence: {$required}");
    }
    foreach ([
        'exact current schema',
        'seven bounded request inputs',
        '0 plugin-attributed statements',
        'plan+rank (**2**)',
        'plan+rank+hydrate',
        'direct plugin-caused option/site-option SQL',
        'real front-end and',
        'authenticated wp-admin Posts searches',
        'fully validate/read analyzer packs',
        'every process records its own elapsed time',
    ] as $required) {
        assert_contains($required, $acceptance, "cold request acceptance should retain: {$required}");
    }

    $debugSettings = wp_fts_wc_contract_function_source($plugin, 'debug_effective_settings');
    foreach (['known_search_provider_advisory', 'detect_known_search_providers', 'get_option(', 'get_site_option('] as $forbidden) {
        assert_true(
            !str_contains($debugSettings, $forbidden),
            "hot debug settings must not perform provider or option discovery: {$forbidden}"
        );
    }
    $debugProviderValue = wp_fts_wc_contract_function_source($plugin, 'search_provider_compatibility_debug_value');
    foreach (['known_search_provider_advisory', 'detect_known_search_providers', 'get_option(', 'get_site_option('] as $forbidden) {
        assert_true(
            !str_contains($debugProviderValue, $forbidden),
            "the hot debug provider formatter must remain a pure mode projection: {$forbidden}"
        );
    }
    $debugLanguage = wp_fts_wc_contract_function_source($plugin, 'debug_set_query_language');
    foreach (['runtime_analyzer_pack_statuses', 'WP_FTS_Analyzer_Pack_Validator', 'validate_pack'] as $forbidden) {
        assert_true(
            !str_contains($debugLanguage, $forbidden),
            "hot debug language recording must not validate or read analyzer packs: {$forbidden}"
        );
    }
    assert_same(3, substr_count($debugLanguage, '::'), 'hot debug language recording should contain only two trace-property accesses and canonical language normalization');
    assert_contains('WP_FTS_TermNamespace::canonicalize_lang', $debugLanguage, 'hot debug language recording should retain canonical language normalization');
});

test_case('frontend cache priming stays in the bounded core WP_Query lifecycle', function (): void {
    $root = dirname(__DIR__, 2);
    $plugin = (string) file_get_contents($root . '/src/Plugin.php');
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');

    $searchPage = wp_fts_wc_contract_function_source($plugin, 'search_result_page');
    foreach (['update_post_caches(', '_prime_post_caches('] as $forbidden) {
        assert_true(
            !str_contains($searchPage, $forbidden),
            "the posts_pre_query provider must return before WordPress-owned cache priming: {$forbidden}"
        );
    }
    $postHydration = wp_fts_wc_contract_function_source($plugin, 'hydrate_search_posts');
    foreach (["function_exists('sanitize_post')", "sanitize_post(\$row, 'raw')"] as $required) {
        assert_contains(
            $required,
            $postHydration,
            "canonical rows must be raw before WP_Query normalizes them: {$required}"
        );
    }
    foreach (['update_post_cache(', 'update_post_caches(', '_prime_post_caches('] as $forbidden) {
        assert_true(
            !str_contains($postHydration, $forbidden),
            "canonical post normalization must leave all cache policy to WP_Query: {$forbidden}"
        );
    }

    $cacheProof = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_frontend_cache_priming_proof');
    foreach ([
        'foreach ([1, 20, 50] as $pageSize)',
        'wp_fts_wc_cold_result_caches($expectedIds, [1])',
        'wp_fts_wc_record_query_ownership(',
        'wp_fts_wc_run_frontend_wp_query($pageSize, true, true)',
        'wp_fts_wc_template_result_access($wpQuery->post, $termId)',
        'frontend_cache_prime_queries_independent_of_k',
        'frontend_author_prime_queries_independent_of_k',
        'frontend_wp_query_cache_lifecycle_contract',
        'frontend_cache_flags_false_canonical_post_cache_hits',
        'wp_fts_wc_run_frontend_wp_query(50, true, true, false)',
        'frontend_cache_results_false_zero_core_statements',
        'frontend_cache_results_false_zero_canonical_post_reads',
        'frontend_cache_results_false_post_cache_untouched',
        'frontend_cache_results_false_raw_post_objects',
        'frontend_cache_results_false_normalization_reads',
        'frontend_cache_{$caseId}_pre_loop_statement_ceiling',
        'frontend_cache_{$caseId}_canonical_post_read_statements',
        'frontend_cache_{$caseId}_total_through_first_loop_ceiling',
        'frontend_cache_{$caseId}_remaining_result_loop_statements',
        'frontend_cache_flags_false_zero_prime_statements',
        'wp_fts_wc_run_stock_unscoped_frontend_wp_query(20)',
        'frontend_stock_unscoped_original_scope_omitted',
        'frontend_stock_unscoped_fts_candidate',
        'frontend_stock_unscoped_plugin_statement_ceiling',
        'frontend_stock_unscoped_plugin_statement_shape',
        'frontend_stock_unscoped_zero_core_posts_like',
        "['plan' => 1, 'rank' => 1, 'hydrate' => 1, 'other' => 0]",
        "['postmeta' => 1, 'term_relationships' => 1, 'other' => 0]",
        "['users' => 1, 'usermeta' => 1, 'other' => 0]",
        '$cachePrimeCounts === [2, 2, 2]',
        '$authorPrimeCounts === [2, 2, 2]',
    ] as $required) {
        assert_contains($required, $cacheProof, "the real cache proof must retain its adversarial boundary: {$required}");
    }
    assert_true(
        !str_contains($cacheProof, 'replace_frontend_search_posts('),
        'a direct provider call cannot prove the core post-filter cache lifecycle'
    );

    $queryRunner = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_run_frontend_wp_query');
    foreach (['new WP_Query()', "'wp_fts_lang' => 'en'", "'cache_results' => \$cacheResults", "'update_post_term_cache' => \$updateTermCache", "'update_post_meta_cache' => \$updateMetaCache"] as $required) {
        assert_contains($required, $queryRunner, "the cache proof must run the complete main WP_Query lifecycle: {$required}");
    }
    $stockUnscopedRunner = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_run_stock_unscoped_frontend_wp_query');
    foreach (["'s' => 'commonalpha commonbeta commongamma'", "'wp_fts_lang' => 'en'", "'cache_results' => false", "'update_post_term_cache' => false", "'update_post_meta_cache' => false"] as $required) {
        assert_contains($required, $stockUnscopedRunner, "the stock-unscoped proof must retain its bounded main-query input: {$required}");
    }
    foreach (["'post_type'", "'post_status'"] as $forbidden) {
        assert_true(!str_contains($stockUnscopedRunner, $forbidden), "the stock-unscoped runner must omit explicit scope: {$forbidden}");
    }
    $ownership = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_record_query_ownership');
    foreach (['debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 64)', "WP_FTS_Plugin::class", "\$owner = 'core'", "\$owner = 'plugin'"] as $required) {
        assert_contains($required, $ownership, "plugin and core statements must remain independently attributable: {$required}");
    }
    $coldCaches = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_cold_result_caches');
    foreach (['clean_post_cache($postId)', "wp_cache_delete(\$postId, 'posts')", "wp_cache_delete(\$postId, 'post_meta')", 'get_object_taxonomies', 'clean_user_cache($authorId)', "wp_cache_delete(\$authorId, 'user_meta')"] as $required) {
        assert_contains($required, $coldCaches, "every result-loop cache must start cold: {$required}");
    }
    $templateAccess = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_template_result_access');
    foreach (['get_post_meta($postId)', "get_the_terms(\$postId, 'category')", 'get_userdata($authorId)'] as $required) {
        assert_contains($required, $templateAccess, "the result loop must exercise ordinary cached template access: {$required}");
    }
    $coreContract = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_wp_query_cache_lifecycle_contract');
    foreach (['new ReflectionMethod(WP_Query::class, $method)', 'token_get_all(', "version_compare(\$version, '6.5', '>=')", "array_search('posts_pre_query'", "\$token === 'get_post'", "\$postsPreQuery < \$postNormalization", "\$postNormalization < \$postCachePrime", "'post_normalization_token'", "array_search('update_post_caches'", "array_search('update_post_author_caches'"] as $required) {
        assert_contains($required, $coreContract, "the runtime proof must bind itself to supported WordPress cache control flow: {$required}");
    }
    foreach ([
        'At page sizes K=1, K=20, and K=50',
        'exactly three plugin-owned statements',
        'already-hydrated canonical `WP_Post` objects',
        '`cache_results=false`',
        'stock-unscoped lifecycle case',
        'original arguments omit both `post_type` and `post_status`',
        'zero core `wp_posts ... LIKE` statements',
        'exactly two batched core pre-loop cache statements',
        'exactly five statements before the loop and seven through its first',
        'All remaining result iterations must add zero statements',
        'execute zero core metadata/taxonomy prime statements',
        'cannot create an N+1 query path',
        'arbitrary third-party hooks and templates to issue their own SQL',
    ] as $required) {
        assert_contains($required, $acceptance, "cache acceptance must publish the measured envelope and its scope: {$required}");
    }
});

test_case('relational worst-case producers retain bounded lossless observations for formerly aggregate-only claims', function (): void {
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $contracts = [
        'wp_fts_wc_analysis_proof' => [
            "'schema' => 'relational-fts-analysis-v1'",
            "'occurrences' => \$occurrences",
            "'sql' => \$queries",
            '65536',
        ],
        'wp_fts_wc_cursor_proof' => [
            "'schema' => 'relational-fts-cursor-traversal-v1'",
            "'result_ids' => \$ids",
            "'page_observations' => \$pageObservations",
            "'sql' => \$queries",
            '33554432',
        ],
        'wp_fts_wc_traverse_terminal_oracle_case' => [
            'relational-fts-terminal-pages-jsonl-v1',
            "'expected_ids' => \$expectedIds",
            "'actual_ids' => \$actualIds",
            "'page_observations_sha256' => hash('sha256', \$pageObservationsJsonl)",
            '16777216',
        ],
        'wp_fts_wc_missing_table_and_lock_proof' => [
            "'schema' => 'relational-fts-availability-faults-v1'",
            "'before_results' => \$before",
            "'after_results' => \$after",
            "'fault_class' => \$faultClass",
            '4194304',
        ],
        'wp_fts_wc_failure_recovery_proof' => [
            "'schema' => 'relational-fts-failure-recovery-v1'",
            "'enqueued_post_ids' => \$ids",
            "'enqueue_sql' => \$enqueueSql",
            "'poison_work_row_before_recovery' => \$workState",
            "'poison_remaining_after_recovery' => \$poisonRemaining",
            "'processed_by_attempt' => \$processedByAttempt",
        ],
        'wp_fts_wc_enqueue_dirty_head' => [
            "'schema' => 'relational-fts-dirty-head-v1'",
            "'post_ids' => \$ids",
            "'sql' => \$queries",
            "'statement_post_counts' => array_map('count', \$chunks)",
            '33554432',
        ],
        'wp_fts_wc_pack_cardinality_statement_proof' => [
            'new WP_FTS_Analyzer($analyzerOptions)',
            'pack_cardinality_option_unchanged',
            "'sql' => \$queries",
            "'statement_bytes' => \$statementBytes",
            "'sql_total_bytes' => array_sum(\$statementBytes)",
            '8388608',
        ],
    ];
    foreach ($contracts as $function => $requiredFragments) {
        $source = wp_fts_wc_contract_function_source($integration, $function);
        foreach ($requiredFragments as $fragment) {
            assert_contains($fragment, $source, "{$function} must retain bounded independently checkable observations: {$fragment}");
        }
        assert_true(!str_contains($source, 'preg_'), "{$function} evidence must not parse structured data with regular expressions");
    }

    $schema = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_assert_relational_schema');
    assert_contains("'engine' => \$engine", $schema, 'physical schema evidence must retain the normalized engine that was asserted');
    $bound = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_assert_json_evidence_bytes');
    foreach (['json_encode(', 'strlen($json) <= $maxBytes', 'JSON_THROW_ON_ERROR'] as $required) {
        assert_contains($required, $bound, "compact diagnostic vectors must fail closed through: {$required}");
    }
    assert_true(!str_contains($bound, 'preg_'), 'compact diagnostic byte bounds must not use regular-expression parsing');
    assert_true(!function_exists('wp_fts_wc_assert_json_evidence_bytes'), 'the evidence-bound helper should be isolated before its executable contract is loaded');
    if (!function_exists('wp_fts_wc_assert')) {
        eval(wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_assert'));
    }
    eval($bound);
    wp_fts_wc_assert_json_evidence_bytes(['value' => 'bounded'], 64, 'focused bounded fixture');
    $rejected = false;
    try {
        wp_fts_wc_assert_json_evidence_bytes(['value' => str_repeat('x', 65)], 64, 'focused oversized fixture');
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), '64-byte compact JSON bound');
    }
    assert_true($rejected, 'an oversized diagnostic vector must fail instead of merely reporting a hash');
});

test_case('HTTP attribution classifies physical table tokens without comment or string impersonation', function (): void {
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    foreach ([
        'wp_fts_wc_sql_token_stream',
        'wp_fts_wc_sql_references_physical_table',
        'wp_fts_wc_sql_references_owned_fts_table',
        'wp_fts_wc_is_owned_fts_table_identifier',
        'wp_fts_wc_sql_contains_executable_word',
    ] as $function) {
        if (!function_exists($function)) {
            eval(wp_fts_wc_contract_function_source($integration, $function));
        }
    }

    $requestMarker = '/* wp_fts_wc_request:0123456789abcdef0123456789abcdef */';
    $core = "SELECT ID FROM `wp_posts` WHERE post_title LIKE '%wp_fts_terms%' {$requestMarker}";
    assert_true(wp_fts_wc_sql_references_physical_table($core, 'wp_posts'), 'a decoded backtick identifier should retain the exact core table reference');
    assert_true(wp_fts_wc_sql_contains_executable_word($core, 'like'), 'an executable LIKE token should be detected');
    assert_true(!wp_fts_wc_sql_references_owned_fts_table($core, 'wp_'), 'a request comment and string literal must not impersonate an FTS table');

    foreach ([
        "SELECT 'wp_fts_terms' AS alleged_table",
        'SELECT 1 /* wp_fts_terms wp_fts_wc_failed_plan_deadbeef */',
        "SELECT 1 AS wp_fts_terms_evil {$requestMarker}",
        'SELECT 1 FROM `other_fts_terms`',
        'SELECT 1 FROM `wp_fts_wc_failed_plan_deadbee`',
        'SELECT 1 FROM `wp_fts_wc_failed_unknown_deadbeef`',
    ] as $spoof) {
        assert_true(!wp_fts_wc_sql_references_owned_fts_table($spoof, 'wp_'), "near-miss/comment/value SQL must not claim FTS ownership: {$spoof}");
    }
    foreach ([
        'SELECT * FROM `wp_fts_terms`',
        'SELECT * FROM wp_fts_postings',
        'SELECT * FROM `wp_fts_documents`',
        'SELECT * FROM wp_fts_work',
        'SELECT * FROM `wp_fts_terms_wc_missing`',
        'SELECT * FROM `wp_fts_wc_failed_plan_deadbeef`',
        'SELECT * FROM `wp_fts_wc_failed_rank_0123abcd`',
        'SELECT * FROM `wp_fts_wc_failed_hydrate_ffffffff`',
    ] as $owned) {
        assert_true(wp_fts_wc_sql_references_owned_fts_table($owned, 'wp_'), "exact current-site FTS identifier should be owned: {$owned}");
    }
    assert_true(!wp_fts_wc_sql_references_physical_table("SELECT 'wp_options' {$requestMarker}", 'wp_options'), 'an option-table string/comment must not count as a physical option-table reference');
    assert_true(wp_fts_wc_sql_references_physical_table('UPDATE `wp_options` SET option_value=1', 'wp_options'), 'an executable option-table identifier should be detected');
    assert_true(!wp_fts_wc_sql_contains_executable_word("SELECT 'LIKE' /* LIKE */", 'like'), 'LIKE inside strings/comments must not become a core-like query');

    $attribution = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_performance_schema_request_events');
    foreach ([
        'wp_fts_wc_sql_references_owned_fts_table',
        'wp_fts_wc_sql_references_physical_table',
        'wp_fts_wc_sql_contains_executable_word',
    ] as $required) {
        assert_contains($required, $attribution, "HTTP attribution must use structural SQL classification through {$required}");
    }
    assert_true(!str_contains($attribution, "strtolower(\$wpdb->prefix . 'fts_')"), 'HTTP attribution must not classify its own request-marker substring as a plugin table');
});

test_case('physical schema classification streams maximum worker statements', function (): void {
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    foreach (['wp_fts_wc_sql_token_stream', 'wp_fts_wc_is_physical_schema_statement'] as $function) {
        if (!function_exists($function)) {
            eval(wp_fts_wc_contract_function_source($integration, $function));
        }
    }

    $statement = 'INSERT INTO `wp_fts_postings` VALUES ' . str_repeat('(1,2,3),', 524288) . '(1,2,3)';
    $peakPadding = null;
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    } else {
        // PHP 8.1 cannot reset the suite's process-wide peak. Lift current
        // usage back to that peak so allocations made by classification still
        // establish a measurable new high-water mark.
        $paddingBytes = max(0, memory_get_peak_usage(true) - memory_get_usage(true));
        $peakPadding = $paddingBytes > 0 ? str_repeat('p', $paddingBytes) : null;
    }
    $peakBaseline = memory_get_peak_usage(true);
    assert_true(!wp_fts_wc_is_physical_schema_statement($statement), 'a maximum-width posting write must remain data rather than schema work');
    $peakDelta = max(0, memory_get_peak_usage(true) - $peakBaseline);
    unset($peakPadding);
    assert_true($peakDelta <= 4194304, "streamed schema classification should add at most four MiB; observed {$peakDelta}");
    assert_true(wp_fts_wc_is_physical_schema_statement('CREATE INDEX `bounded` ON `wp_posts` (`ID`)'), 'an executable CREATE must remain physical schema work');
    assert_true(wp_fts_wc_is_physical_schema_statement('SELECT TABLE_NAME FROM information_schema.TABLES'), 'an information_schema relation must remain physical schema work');

    $source = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_is_physical_schema_statement');
    assert_contains('wp_fts_wc_sql_token_stream($sql)', $source, 'physical schema classification must consume the token stream directly');
    assert_true(!str_contains($source, 'wp_fts_wc_sql_tokens($sql)'), 'physical schema classification must not materialize maximum-width DML tokens');
});

test_case('worker option classification decodes quoted identifiers and exact names', function (): void {
    global $wpdb;

    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    foreach ([
        'wp_fts_wc_sql_token_stream',
        'wp_fts_wc_sql_references_physical_table',
        'wp_fts_wc_sql_references_named_option',
        'wp_fts_wc_scope_index_probe_role',
        'wp_fts_wc_worker_statement_role',
    ] as $function) {
        if (!function_exists($function)) {
            eval(wp_fts_wc_contract_function_source($integration, $function));
        }
    }

    $hadWpdb = array_key_exists('wpdb', $GLOBALS);
    $oldWpdb = $wpdb ?? null;
    $wpdb = (object) ['options' => 'wp_options'];
    try {
        assert_same(
            'cron_schedule_write',
            wp_fts_wc_worker_statement_role("UPDATE `wp_options` SET `option_value` = 'payload' WHERE `option_name` = 'cron'"),
            'a backtick-quoted WordPress cron update must remain scheduling control'
        );
        assert_same(
            'cron_schedule_probe',
            wp_fts_wc_worker_statement_role("SELECT option_value FROM wp_options WHERE option_name='cron' LIMIT 1"),
            'an unquoted WordPress cron read must remain scheduling control'
        );
        assert_same(
            'health_state_read',
            wp_fts_wc_worker_statement_role("SELECT option_value FROM wp_options WHERE option_name = 'wp_fts_index_health' LIMIT 1"),
            'an uncached index Health read must remain explicit worker data control'
        );
        assert_same(
            'health_state_cas',
            wp_fts_wc_worker_statement_role("UPDATE `wp_options` SET `option_value` = 'payload' WHERE `option_name` = 'wp_fts_index_health'"),
            'a quoted index Health update must remain explicit worker data control'
        );
        assert_same(
            'unknown',
            wp_fts_wc_worker_statement_role("UPDATE wp_shadow_options SET option_value='payload' WHERE option_name='cron'"),
            'a different option table must not impersonate the WordPress cron control plane'
        );
        assert_same(
            'unknown',
            wp_fts_wc_worker_statement_role("UPDATE wp_options SET option_value='option_name = cron' WHERE option_name='not-cron'"),
            'cron-like text inside a value must not impersonate the exact option predicate'
        );
    } finally {
        if ($hadWpdb) {
            $wpdb = $oldWpdb;
        } else {
            unset($GLOBALS['wpdb']);
        }
    }

    $classifier = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_sql_references_named_option');
    assert_contains('wp_fts_wc_sql_token_stream($sql, true)', $classifier, 'named-option classification must decode identifier and string tokens');
    assert_true(!str_contains($classifier, 'preg_'), 'named-option classification must not use regular-expression parsing');
    $workerRole = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_worker_statement_role');
    assert_contains('wp_fts_wc_sql_references_named_option', $workerRole, 'cron roles must use the structured named-option classifier');
    assert_contains("return 'health_state_read';", $workerRole, 'index Health reads must retain their exact worker role');
});

test_case('instrumented worker batches discard raw SQL unless composition inspects it', function (): void {
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $batch = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_instrumented_worker_batch');
    foreach ([
        'bool $retainStatements = false',
        "'statement_sha256' => \$statementHashes",
        "'statement_bytes' => \$statementBytes",
        'if ($retainStatements)',
        "\$batch['statements'] = \$queries;",
    ] as $required) {
        assert_contains($required, $batch, "worker batch compaction must retain: {$required}");
    }
    assert_true(!str_contains($batch, "'statements' => \$queries"), 'ordinary worker batches must not retain raw SQL unconditionally');

    $composed = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_composed_maximum_worker_path');
    foreach ([
        "'without_preexisting_event',\n            true",
        "'with_later_existing_event',\n            true",
        "unset(\$firstBatch['statements'], \$laterEventBatch['statements']);",
        "unset(\$pathBatch['statements']);",
    ] as $required) {
        assert_contains($required, $composed, "composed-worker inspection must remain bounded through: {$required}");
    }
});

test_case('search relation scanning retains every physical table across STRAIGHT_JOIN', function (): void {
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    foreach ([
        'wp_fts_wc_sql_token_stream',
        'wp_fts_wc_sql_tokens',
        'wp_fts_wc_fts_table_kind',
        'wp_fts_wc_sql_fts_relations',
    ] as $function) {
        if (!function_exists($function)) {
            eval(wp_fts_wc_contract_function_source($integration, $function));
        }
    }

    $relations = wp_fts_wc_sql_fts_relations(
        'SELECT * FROM `wp_fts_terms` t '
        . 'STRAIGHT_JOIN `wp_fts_postings` AS p ON p.term_id=t.term_id '
        . 'STRAIGHT_JOIN wp_fts_documents d ON d.post_id=p.post_id'
    );
    assert_same(
        [
            ['table' => 'terms', 'relation' => 'wp_fts_terms', 'alias' => 't'],
            ['table' => 'postings', 'relation' => 'wp_fts_postings', 'alias' => 'p'],
            ['table' => 'documents', 'relation' => 'wp_fts_documents', 'alias' => 'd'],
        ],
        $relations,
        'STRAIGHT_JOIN relations must remain visible to allowlist, posting-count, and EXPLAIN checks'
    );
});

test_case('relational search ceilings count every recorded wpdb statement including transaction control', function (): void {
    $root = dirname(__DIR__, 2);
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');

    assert_true(
        !str_contains($integration, 'wp_fts_wc_is_counted_production_statement'),
        'no search-path statement ceiling may use the former transaction-control exclusion helper'
    );

    $rawStatementContracts = [
        'wp_fts_wc_max_valid_search' => "\$queries = array_values(\$recorded['queries']);",
        'wp_fts_wc_oracle_proof' => "\$queryCounts[] = count(\$recorded['queries']);",
        'wp_fts_wc_cursor_proof' => "\$queries = array_values(\$recorded['queries']);",
        'wp_fts_wc_prefix_cursor_proof' => "static fn(array \$recorded): int => count(\$recorded['queries'])",
        'wp_fts_wc_traverse_terminal_oracle_case' => "\$queries = array_values(\$recorded['queries']);",
        'wp_fts_wc_cursor_rejection' => "'total_statement_count' => count(\$recorded['queries'])",
        'wp_fts_wc_cold_sample' => "\$queries = array_values(\$recorded['queries']);",
        'wp_fts_wc_measure_case' => "\$queries = array_values(\$recorded['queries']);",
        'wp_fts_wc_instrument_case' => "\$instrumentedSql = array_values(\$recorded['queries']);",
        'wp_fts_wc_pack_cardinality_statement_proof' => "\$queries = array_values(\$recorded['queries']);",
        'wp_fts_wc_public_adapter_proof' => "\$queries = array_values(\$recorded['queries']);",
    ];
    foreach ($rawStatementContracts as $function => $required) {
        $source = wp_fts_wc_contract_function_source($integration, $function);
        assert_contains($required, $source, "{$function} must count the complete recorded wpdb callback");
    }

    $instrumentation = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_instrument_case');
    foreach ([
        "\$performanceSql = array_map(static fn(array \$event): string => (string) (\$event['SQL_TEXT'] ?? ''), \$events);",
        "'statement_scope' => 'all_recorded_wpdb_including_transaction_control'",
        "'captured_statement_count' => count(\$capturedSql)",
        "'instrumented_statement_count' => count(\$instrumentedSql)",
        "'performance_schema_statement_count' => count(\$performanceSql)",
        "return !str_contains(\$sql, 'PERFORMANCE_SCHEMA.') && !str_starts_with(\$sql, 'SHOW SESSION STATUS');",
    ] as $required) {
        assert_contains($required, $instrumentation, "instrumented wpdb/Performance Schema parity must retain: {$required}");
    }
    foreach (['START TRANSACTION', "'BEGIN'", "'COMMIT'", "'ROLLBACK'"] as $forbidden) {
        assert_true(!str_contains($instrumentation, $forbidden), "instrumentation must not filter transaction control: {$forbidden}");
    }

    $validation = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_validate');
    foreach (['wp_fts_wc_assert_source_binding();', '$manifest = wp_fts_wc_manifest();'] as $required) {
        assert_contains($required, $validation, "raw wpdb/Performance Schema evidence must run under source binding: {$required}");
    }
    $manifest = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_manifest');
    foreach (['WP_FTS_SOURCE_SHA', 'WP_FTS_ZIP_SHA256', 'WP_FTS_HARNESS_SHA256', 'WP_FTS_WC_LANE_ID'] as $required) {
        assert_contains($required, $manifest, "raw statement parity must retain corpus/source/lane binding: {$required}");
    }

    $caseGates = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_case_gates');
    foreach ([
        '_total_raw_statement_parity',
        "'scope' => 'all_recorded_wpdb_including_transaction_control'",
        "\$sqlAttribution['captured_statement_count']",
        "\$sqlAttribution['instrumented_statement_count']",
        "\$sqlAttribution['performance_schema_statement_count']",
    ] as $required) {
        assert_contains($required, $caseGates, "the <=3 gate must retain complete raw-statement parity: {$required}");
    }

    $wpcli = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_wpcli_adapter');
    foreach ([
        "\$queries = array_values(\$recorded['queries']);",
        "'actual_wpcli_query_count', 3, count(\$queries)",
        "'actual_wpcli_fts_query_count', 3, count(\$ftsQueries)",
        "'schema' => 'relational-fts-wpcli-adapter-v2'",
        "'statement_scope' => 'all_recorded_wpdb_including_transaction_control'",
    ] as $required) {
        assert_contains($required, $wpcli, "WP-CLI statement accounting must retain: {$required}");
    }

    $explosive = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_explosive_input_proof');
    assert_contains("\$queries = array_values(\$recorded['queries']);", $explosive, 'explosive-input rejection must count every wpdb statement');
    assert_contains("'explosive_input_total_queries', 0, count(\$queries), \$queries === []", $explosive, 'explosive-input rejection must require zero total statements');

    $workerRoles = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_worker_statement_roles');
    $workerRole = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_worker_statement_role');
    assert_contains("'transaction_roles' => \$transactionRoles", $workerRoles, 'worker-specific transaction accounting must remain explicit');
    foreach (['START TRANSACTION', 'BEGIN', 'COMMIT', 'ROLLBACK'] as $required) {
        assert_contains($required, $workerRole, "worker transaction classifier must retain: {$required}");
    }
    foreach (["'meta:search-epoch'", 'generation = generation + 1', "return 'search_epoch_advance';"] as $required) {
        assert_contains($required, $workerRole, "worker search-epoch classifier must retain: {$required}");
    }

    foreach ([
        'covers the complete recorded callback',
        '`START TRANSACTION`, `BEGIN`,',
        '`COMMIT`, and `ROLLBACK`',
        'transaction control cannot be filtered out',
    ] as $required) {
        assert_contains($required, $acceptance, "acceptance must document complete statement accounting: {$required}");
    }
});

test_case('Jieba memory proof is authoritative on PHP 8.1 and 8.4 with and without php.ini', function (): void {
    $root = dirname(__DIR__, 3);
    $component = (string) file_get_contents($root . '/components/full-text-search/tests/jieba-indexed-multi-run.php');
    $queryProducer = (string) file_get_contents($root . '/components/full-text-search/tests/jieba-query-producer-bounds.php');
    $pipeline = (string) file_get_contents($root . '/components/full-text-search/src/LanguagePipeline.php');
    $wrapper = (string) file_get_contents(dirname(__DIR__) . '/quality/jieba-indexed-multi-run.php');
    $smoke = (string) file_get_contents($root . '/components/full-text-search/tests/smoke.php');
    $workflow = (string) file_get_contents($root . '/.github/workflows/relational-fts-worst-case.yml');
    $acceptance = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/relational-search-acceptance.md');

    foreach ([
        '$canResetPeak || $freshProcess',
        'max(0, $memoryPeakAfter - $memoryBefore)',
        "'fresh_lifetime_peak_after_minus_usage_before'",
        "'cumulative_parent_diagnostic'",
        'max(0, $rssPeakAfter - $rssBefore)',
        "'php_peak_delta_authoritative' => \$canResetPeak || \$freshProcess",
        "'rss_peak_delta_authoritative' => \$freshProcess && \$rssSource === 'linux_proc_status'",
        "'rss_peak_bytes' => \$rssPeakAfter",
        "'rss_source' => \$rssSource",
        "'linux_proc_status'",
        "'jieba-isolated-memory-case-v2'",
        "'fresh_process_conservative_peak_attribution'",
        "'input_bytes'",
        "'input_sha256'",
        "'input_unit_count'",
        "(\$freshPayload['workload'] ?? null) === \$freshExpected['workload']",
        "foreach (['term_count', 'indexed_range_reads'] as \$field)",
        "array_key_exists('run_count', \$freshExpected['measurement'])",
        "(\$freshMeasurement['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= \$freshDeltaCeiling",
        "(\$freshMeasurement['rss_peak_delta_bytes'] ?? PHP_INT_MAX) <= \$freshDeltaCeiling",
        "(\$freshProcessRecord['php_peak_bytes'] ?? PHP_INT_MAX) <= 134217728",
        "(\$freshProcessRecord['rss_peak_bytes'] ?? PHP_INT_MAX) <= 134217728",
    ] as $required) {
        assert_contains($required, $component, "Jieba isolated memory proof must retain: {$required}");
    }
    assert_true(!str_contains($component, '$rssPeakAfter - $rssPeakBefore'), 'VmHWM-to-VmHWM subtraction can hide a transient and must not return');
    assert_same(9, substr_count($component, 'wp_fts_jieba_parent_php_delta_within('), 'all eight parent PHP memory checks plus their helper must retain authority-aware gating');
    assert_same(12, substr_count($component, 'wp_fts_jieba_parent_rss_deltas_within('), 'all eleven parent RSS checks plus their helper must remain diagnostic unless isolated');

    $caseStart = strpos($wrapper, '$expectedFreshCases = [');
    $caseEnd = strpos($wrapper, '];', $caseStart === false ? 0 : $caseStart);
    assert_true(is_int($caseStart) && is_int($caseEnd), 'quality wrapper must expose one exact fresh-case inventory');
    $caseInventory = substr($wrapper, $caseStart, $caseEnd - $caseStart);
    $expectedCases = [
        'cold_256',
        'saturated_256',
        'repeated_wide_cold',
        'repeated_wide_saturated',
        'permuted_wide',
        'changing_prefix',
        'distinct_prefix_sets',
        'maximum_distinct',
        'maximum_fanout',
        'complete_pinned_cache',
    ];
    foreach ($expectedCases as $caseId) {
        assert_contains("'{$caseId}'", $caseInventory, "fresh memory inventory must retain {$caseId}");
    }
    assert_same(10, substr_count($caseInventory, '=>'), 'fresh memory inventory must contain exactly ten cases');
    assert_same(7, substr_count($caseInventory, '=> 25165824'), 'seven exact cases must retain the 24 MiB ceiling');
    assert_same(1, substr_count($caseInventory, '=> 41943040'), 'one exact case must retain the 40 MiB ceiling');
    assert_same(2, substr_count($caseInventory, '=> 67108864'), 'two exact cases must retain the 64 MiB ceiling');
    foreach (['jieba-indexed-multi-run-v2', 'jieba-isolated-memory-case-v2', 'fresh_process_conservative_peak_attribution', 'php_peak_delta_authoritative', 'rss_peak_delta_authoritative', 'cumulative_multi_workload_diagnostic', 'within_128_mib', '134217728', 'linux_proc_status', 'input_sha256'] as $required) {
        assert_contains($required, $wrapper, "quality wrapper must reject missing authoritative evidence: {$required}");
    }

    assert_contains("\$hardeningChecks += require __DIR__ . '/jieba-indexed-multi-run.php';", $smoke, 'component smoke must execute the complete Jieba multi-run proof');
    assert_contains("\$hardeningChecks += require __DIR__ . '/jieba-query-producer-bounds.php';", $smoke, 'component smoke must execute the public Jieba producer-bound proof');
    foreach ([
        'proc_open($command',
        "'memory_limit=128M'",
        "'max_execution_time=5'",
        "'schema' => 'wp-fts-jieba-query-producer-bound-v1'",
        "'status' => \$passed ? 'pass' : 'fail'",
        'max(0, memory_get_peak_usage(true) - $usageBefore)',
        "'php_peak_delta_authoritative' => true",
        "'memory_authority'",
        "'bundled_source'",
        'WP_FTS_ChineseJiebaSegmenter::runtime_manifest()',
        "'query_bytes' => strlen(\$maximumQuery)",
        "'query_sha256' => hash('sha256', \$maximumQuery)",
        "'distinct_han_prefixes' => count(\$queryCharacters)",
        "\$measurement['indexed_range_reads'] === 0",
        "\$measurement['cached_candidate_delta'] === 0",
        "(\$measurement['rejected_storage_search_calls'] ?? null) === 0",
        "(float) (\$measurement['elapsed_seconds'] ?? INF) < 1.0",
        "(int) (\$measurement['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= 4 * 1024 * 1024",
        "(\$measurement['producer_token_count'] ?? null) === 13",
        "(\$measurement['producer_indexed_range_reads'] ?? null) === 0",
        "(\$measurement['accepted_group_count'] ?? null) === 12",
        "(\$measurement['accepted_alternative_count'] ?? null) === 12",
        "\$tokens = (\$this->cjkTokenizer)(\$run, \$canonicalLanguage, \$maxTerms + 1)",
        "\$segmenter(\$run, \$language, \$maxTokens)",
    ] as $required) {
        assert_contains($required, $queryProducer . $component . $pipeline, "public Jieba query proof must retain: {$required}");
    }
    $jobStart = strpos($workflow, '  jieba-memory-compatibility:');
    $jobEnd = strpos($workflow, '  pull-request-50k:', $jobStart === false ? 0 : $jobStart);
    assert_true(is_int($jobStart) && is_int($jobEnd), 'PHP compatibility matrix must remain independently inspectable');
    $job = substr($workflow, $jobStart, $jobEnd - $jobStart);
    foreach ([
        "- '8.1'",
        "- '8.4'",
        'composer install --no-interaction --prefer-dist',
        'php -l indexer/tests/integration/relational-fts-worst-case.php',
        'php -d memory_limit=128M indexer/tests/quality/jieba-indexed-multi-run.php',
        'php -n -d memory_limit=128M indexer/tests/quality/jieba-indexed-multi-run.php',
        'php -d memory_limit=128M components/full-text-search/tests/jieba-query-producer-bounds.php',
        'php -n -d memory_limit=128M components/full-text-search/tests/jieba-query-producer-bounds.php',
        'jieba-memory-query-normal.json',
        'jieba-memory-query-no-ini.json',
        'php -d memory_limit=128M components/full-text-search/tests/smoke.php',
        'php -n -d memory_limit=128M components/full-text-search/tests/smoke.php',
    ] as $required) {
        assert_contains($required, $job, "PHP 8.1/8.4 compatibility job must retain: {$required}");
    }
    foreach (['jieba-indexed-multi-run-v2', 'jieba-isolated-memory-case-v2', 'fresh_process_conservative_peak_attribution', 'php_peak_delta_authoritative', 'rss_peak_delta_authoritative', '!==true||max(', 'linux_proc_status'] as $required) {
        assert_contains($required, $workflow, "external CI evidence validation must retain: {$required}");
    }
    foreach (['lifetime PHP peak after the workload minus live PHP usage', '`VmHWM` after minus `VmRSS` before', 'cumulative multi-workload diagnostic'] as $required) {
        assert_contains($required, $acceptance, "acceptance writeup must distinguish authoritative and cumulative memory: {$required}");
    }
    foreach (['1,365-distinct-Han query', 'thirteen-item observation ceiling', 'exactly zero storage calls', 'at most 4 MiB', 'exactly twelve logical groups and alternatives'] as $required) {
        assert_contains($required, $acceptance, "acceptance must retain the public Jieba producer boundary: {$required}");
    }
});

test_case('relational worst-case shell and PHP entry points pass syntax checks', function (): void {
    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is required for shell syntax validation.');
    }

    $root = dirname(__DIR__, 2);
    $shell = test_run_subprocess(['bash', '-n', $root . '/tools/run-relational-fts-worst-case.sh'], $root);
    assert_same(0, $shell['exit'], 'worst-case Docker runner should pass bash -n');

    $php = test_run_subprocess([PHP_BINARY, '-l', dirname(__DIR__) . '/integration/relational-fts-worst-case.php'], $root);
    assert_same(0, $php['exit'], 'worst-case integration proof should pass PHP syntax validation');

    $reader = test_run_subprocess([PHP_BINARY, '-l', dirname(__DIR__) . '/integration/relational-fts-concurrent-reader.php'], $root);
    assert_same(0, $reader['exit'], 'lightweight concurrent-reader proof should pass PHP syntax validation');

    $mutation = test_run_subprocess([PHP_BINARY, '-l', dirname(__DIR__) . '/integration/mutation-fence-concurrency.php'], $root);
    assert_same(0, $mutation['exit'], 'mutation-fence concurrency proof should pass PHP syntax validation');

    $isolated = test_run_subprocess([PHP_BINARY, '-l', dirname(__DIR__) . '/integration/relational-fts-isolated-boundaries.php'], $root);
    assert_same(0, $isolated['exit'], 'isolated accepted/rejected boundary proof should pass PHP syntax validation');

    $frontier = test_run_subprocess([PHP_BINARY, '-l', dirname(__DIR__) . '/integration/old-posting-frontier.php'], $root);
    assert_same(0, $frontier['exit'], 'old-posting frontier proof should pass PHP syntax validation');
});

test_case('relational worst-case CI is a required real database lane with failure artifacts', function (): void {
    $root = dirname(__DIR__, 3);
    $workflow = (string) file_get_contents($root . '/.github/workflows/relational-fts-worst-case.yml');
    $testing = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/testing.md');
    $acceptance = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/relational-search-acceptance.md');

    foreach ([
        'pull-request-50k:',
        'pull-request-100k:',
        '- mariadb-10.11',
        '- mysql-8.0',
        '--profile=50k',
        '--profile=100k',
        'timeout-minutes: 360',
        'group: relational-fts-${{ github.workflow }}-${{ github.event.pull_request.number }}',
        'cancel-in-progress: true',
        'if: always()',
        'if-no-files-found: error',
        'include-hidden-files: true',
        'runs-on: ubuntu-24.04',
        "php-version: '8.4'",
        'tools: composer:2.9.8',
        'persist-credentials: false',
        'Initialize and attest the pinned Jieba source',
        'status --porcelain --untracked-files=all',
        'Prove indexed Jieba multi-run work stays bounded',
        'php -d memory_limit=128M indexer/tests/quality/jieba-indexed-multi-run.php',
        'php -d memory_limit=128M components/full-text-search/tools/build-jieba-lookup-index.php --check',
        'timeout --signal=TERM --kill-after=10s 60s',
        'repeated_wide_cold',
        'repeated_wide_saturated',
        'permuted_wide',
        'changing_prefix',
        'distinct_prefix_sets',
        'maximum_fanout_record',
        'maximum_fanout',
        'complete_pinned_cache_record',
        'complete_pinned_cache',
        'indexed_range_reads',
        '>=99',
        '===18600',
        '===285075',
        '===2581996',
        '===4095',
        '===5454',
        '===5628',
        '===337399',
        '===3013489',
        '===5652',
        '===337461',
        '===3013799',
        '===5632',
        '<=4000',
        '<=1600',
        '<2.0',
        '${JIEBA_EVIDENCE_DIR}/jieba-indexed-multi-run.json',
        '${JIEBA_EVIDENCE_DIR}/jieba-lookup-index-check.json',
        '.context/jieba-*',
    ] as $required) {
        assert_contains($required, $workflow, "workflow should retain hard real-database behavior: {$required}");
    }
    assert_same(2, substr_count($workflow, "php-version: '8.4'"), 'every real-database job should select the supported PHP 8.4 release line');
    assert_true(!str_contains($workflow, "php-version: '8.4.5'"), 'the workflow must not claim an exact PHP patch that setup-php resolves to a newer release');
    $packageValidator = wp_fts_wc_contract_function_source(
        (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php'),
        'wp_fts_wc_package_reproducibility_is_exact'
    );
    foreach (["explode('.', (string) \$toolchain['php_version'])", "\$phpVersionParts[0] === '8'", "\$phpVersionParts[1] === '4'", 'wp_fts_wc_is_ascii_digits($phpVersionParts[2])'] as $required) {
        assert_contains($required, $packageValidator, "package validation should bind a stable numeric PHP 8.4 release: {$required}");
    }
    assert_true(!str_contains($packageValidator, "=== '8.4.5'"), 'package validation must accept the exact stable PHP 8.4 patch resolved by the runner');
    $pr50Start = strpos($workflow, '  pull-request-50k:');
    $pr100Start = strpos($workflow, '  pull-request-100k:');
    assert_true(is_int($pr50Start) && is_int($pr100Start), 'required pull-request jobs should remain independently inspectable');
    $pr50 = substr($workflow, $pr50Start, $pr100Start - $pr50Start);
    $pr100 = substr($workflow, $pr100Start);
    foreach (['name: 50k / ${{ matrix.engine }}', '- mariadb-10.11', '- mysql-8.0', "--engine='\${{ matrix.engine }}'", '--profile=50k', '--output=".context/evidence-${{ matrix.engine }}-50k.json"', 'name: relational-fts-${{ matrix.engine }}-50k', '.context/evidence-${{ matrix.engine }}-50k.json*', 'if: always()', 'if-no-files-found: error', 'include-hidden-files: true'] as $required) {
        assert_contains($required, $pr50, "the 50k pull-request matrix should retain {$required}");
    }
    assert_same(1, substr_count($pr50, '- mariadb-10.11'), 'the 50k matrix must contain exactly one MariaDB 10.11 lane');
    assert_same(1, substr_count($pr50, '- mysql-8.0'), 'the 50k matrix must contain exactly one MySQL 8.0 lane');
    assert_same(2, substr_count($pr50, "\n          - "), 'the 50k matrix must contain exactly its two supported engines');
    assert_same(1, substr_count($pr50, "--engine='\${{ matrix.engine }}'"), 'both 50k engines must execute one shared proof command');
    assert_true(!str_contains($pr50, '--engine=mariadb-10.11'), 'the 50k matrix must not route both entries through a MariaDB-only proof path');
    foreach (['name: 100k / ${{ matrix.engine }}', '- mariadb-10.11', '- mysql-8.0', "--engine='\${{ matrix.engine }}'", '--profile=100k', '--output=".context/evidence-${{ matrix.engine }}-100k.json"', 'name: relational-fts-${{ matrix.engine }}-100k', '.context/evidence-${{ matrix.engine }}-100k.json*', 'composer install --no-interaction --prefer-dist', 'Prove indexed Jieba multi-run work stays bounded', 'memory_limit=128M', 'JIEBA_EVIDENCE_DIR: ${{ runner.temp }}/relational-fts-jieba', '.context/jieba-*', 'if: always()', 'if-no-files-found: error', 'include-hidden-files: true'] as $required) {
        assert_contains($required, $pr100, "the 100k pull-request boundary should retain {$required}");
    }
    assert_same(1, substr_count($pr100, '- mariadb-10.11'), 'the 100k matrix must contain exactly one MariaDB 10.11 lane');
    assert_same(1, substr_count($pr100, '- mysql-8.0'), 'the 100k matrix must contain exactly one MySQL 8.0 lane');
    assert_same(2, substr_count($pr100, "\n          - "), 'the 100k matrix must contain exactly its two supported engines');
    assert_same(1, substr_count($pr100, "--engine='\${{ matrix.engine }}'"), 'both 100k engines must execute one shared proof command');
    assert_true(!str_contains($pr100, '--engine=mariadb-10.11'), 'the 100k job must not retain a MariaDB-only proof path');
    assert_same(
        3,
        substr_count($pr100, 'JIEBA_EVIDENCE_DIR: ${{ runner.temp }}/relational-fts-jieba'),
        'the initializer, bounded analyzer proof, and post-run collector should share the same external evidence directory'
    );
    $proofStart = strpos($pr100, '- name: Run constrained real-WordPress 100k boundary proof');
    $collectStart = strpos($pr100, '- name: Collect pre-run evidence after clean-source proof');
    $uploadStart = strpos($pr100, '- name: Upload evidence even after failure');
    assert_true(
        is_int($proofStart) && is_int($collectStart) && is_int($uploadStart)
            && $proofStart < $collectStart
            && $collectStart < $uploadStart,
        'pre-run evidence must return to the checkout only after the runner has completed clean-source attestation'
    );
    $beforeProof = is_int($proofStart) ? substr($pr100, 0, $proofStart) : $pr100;
    assert_true(
        !str_contains($beforeProof, '.context/jieba-'),
        'the 100k pre-run steps must not create untracked harness artifacts inside the attested checkout'
    );
    $collect = is_int($collectStart) && is_int($uploadStart)
        ? substr($pr100, $collectStart, $uploadStart - $collectStart)
        : '';
    assert_contains('if: always()', $collect, 'pre-run evidence collection should survive a failed real-database proof');
    assert_contains('cp -a "${JIEBA_EVIDENCE_DIR}/." .context/', $collect, 'only the post-attestation collection step should return Jieba artifacts to the upload path');
    $retiredTokens = [
        'mysql-' . implode('.', [5, 7]),
        'mysql ' . implode('.', [5, 7]),
        'mysql' . '57',
        'WP_FTS_MYSQL' . '57_IMAGE',
    ];
    foreach (['workflow' => $workflow, 'testing guide' => $testing] as $label => $source) {
        foreach ($retiredTokens as $retiredToken) {
            assert_true(
                !str_contains(strtolower($source), strtolower($retiredToken)),
                "{$label} must not retain the retired database target token"
            );
        }
    }
    foreach (['50k' => $pr50, '100k' => $pr100] as $label => $databaseJob) {
        assert_true(!str_contains($databaseJob, '--allow-dirty'), "the {$label} pull-request database job must require clean acceptance evidence");
    }
    $permissionsStart = strpos($workflow, "\npermissions:");
    $triggers = is_int($permissionsStart) ? substr($workflow, 0, $permissionsStart) : $workflow;
    assert_contains('pull_request:', $triggers, 'the four expensive database lanes should run for relevant pull requests');
    foreach (['components/full-text-search/tools/**', 'indexer/.distignore', 'indexer/config/**'] as $path) {
        assert_contains("- '{$path}'", $triggers, "the acceptance workflow should run when direct runner input {$path} changes");
    }
    foreach (['workflow_dispatch:', 'schedule:', 'push:'] as $unrequestedTrigger) {
        assert_true(!str_contains($triggers, $unrequestedTrigger), "the acceptance workflow should not add the unrequested {$unrequestedTrigger} trigger");
    }
    assert_true(!str_contains($workflow, 'continue-on-error'), 'real-database workflow must not tolerate failed proof commands');
    assert_same(3, substr_count($workflow, 'persist-credentials: false'), 'all database and PHP-compatibility jobs should remove their checkout credential');
    assert_same(2, substr_count($workflow, 'tools: composer:2.9.8'), 'both jobs covering four database lanes should use the same exact Composer toolchain');
    assert_same(2, substr_count($workflow, 'timeout-minutes: 345'), 'each proof step should leave fifteen minutes for its required failure-artifact upload');
    assert_same(3, substr_count($workflow, 'uses: actions/upload-artifact@v4'), 'each database or PHP-compatibility job should upload its report');
    assert_same(2, substr_count($workflow, 'include-hidden-files: true'), 'each database upload must include the hidden .context report path');
    assert_contains('Relational Search Worst-Case Acceptance', $testing, 'testing guide should document the real acceptance lane');
    $cleanCommandsStart = strpos($testing, "Required clean-source " . 'evi' . "dence:\n");
    $cleanCommandsEnd = strpos($testing, "\nDo not add this multi-hour real-database lane", is_int($cleanCommandsStart) ? $cleanCommandsStart : 0);
    assert_true(
        is_int($cleanCommandsStart) && is_int($cleanCommandsEnd) && $cleanCommandsEnd > $cleanCommandsStart,
        'testing guide clean-source commands should remain independently inspectable'
    );
    $cleanCommands = is_int($cleanCommandsStart) && is_int($cleanCommandsEnd)
        ? substr($testing, $cleanCommandsStart, $cleanCommandsEnd - $cleanCommandsStart)
        : '';
    assert_same(4, substr_count($cleanCommands, 'tools/run-relational-fts-worst-case.sh'), 'testing guide should list exactly all four clean database lanes');
    foreach ([
        "--engine=mariadb-10.11 \\\n  --profile=50k",
        "--engine=mysql-8.0 \\\n  --profile=50k",
        "--engine=mariadb-10.11 \\\n  --profile=100k",
        "--engine=mysql-8.0 \\\n  --profile=100k",
    ] as $requiredLane) {
        assert_contains($requiredLane, $cleanCommands, "testing guide should retain clean lane: {$requiredLane}");
    }
    assert_contains('100,000', $acceptance, 'acceptance contract should retain the supported boundary corpus');
    foreach (['`100k/mysql-8.0`', '`mysql80-100k`', 'one two-engine matrix', 'identical structural, performance, memory, concurrency,', 'report validation, finalization, and failure-artifact requirements', 'all four pull-request database lanes', '--engine=mysql-8.0 --profile=100k'] as $required) {
        assert_contains($required, $acceptance, "acceptance contract should retain the MySQL 8.0 boundary requirement: {$required}");
    }
    assert_contains('A missing dependency, `SKIP`, `PENDING`, timeout, OOM', $acceptance, 'acceptance contract should reject clean skips and incomplete evidence');
    assert_contains('not a universal hosting SLA', $acceptance, 'absolute CI latency should be qualified by its unthrottled host storage');
});
