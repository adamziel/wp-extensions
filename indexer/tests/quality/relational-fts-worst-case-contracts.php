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
        printf '{"schema":"relational-fts-evidence-v2","status":"FAIL","completed":false}\n' > "${EVIDENCE_DIR}/relational-fts-evidence.json"
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
        'wp_fts_wc_concurrent_reader' => "return ['status' => 'PASS'",
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

    $baseline = $functions['wp_fts_wc_baseline_performance'] ?? '';
    assert_contains(
        "return ['status' => \$result['status']",
        $baseline,
        'the explicitly diagnostic legacy baseline may record timeout/OOM/incorrect-result FAIL evidence without aborting migration validation'
    );

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
        'cold_ready_upgrade_v6_seed_shape',
        'cold_ready_upgrade_profile_hash_preserved',
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
        'direct_set_oriented_mutation_guard_contract',
        'set_oriented_post_preparation_authority_contract',
        'set_oriented_dynamic_rendering_rejected_before_callbacks',
        'runtime_analyzer_default_provider_io_absent',
        'claim_index_options_preload_contract',
        'dependency_lob_actual_accepted_fixture_rows',
        'migration_all_failpoints_resumed',
        'migration_physical_monotonic_coverage',
        'migration_physical_max_sample_duration',
        'multisite_search_order_parity',
        'schema_exact_physical_contract',
        'schema_no_term_hash_column_or_index',
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
        'dense_relationship_rank_control_revocation_short_circuits_postings',
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
    $probe = "<?php\n" . $extracted
        . '$caseIds = ' . var_export($caseIds, true) . ";\n"
        . '$gateIds = ' . var_export($criticalGateIds, true) . ";\n"
        . <<<'PHP'
$evidence = [
    'schema' => 'relational-fts-evidence-v2',
    'status' => 'PASS',
    'source_sha' => str_repeat('a', 40),
    'zip_sha256' => str_repeat('b', 64),
    'source_dirty' => false,
    'acceptance_lane' => true,
    'lane_id' => 'mysql57-2k',
    'completed' => false,
    'engine' => 'mysql-5.7',
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
    ] as $required) {
        assert_contains($required, $acceptance, "acceptance must document authoritative search-memory invariant: {$required}");
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
    'free_claim_guarded_predicate_count' => 2,
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

test_case('relational direct mutation methods reject set-oriented storage before any work', function (): void {
    $indexer = (string) file_get_contents(dirname(__DIR__, 3) . '/components/full-text-search/src/Indexer.php');
    $firstToken = static function (string $methodSource): mixed {
        $inside = false;
        foreach (wp_fts_php_source_token_stream("<?php\n" . $methodSource) as $token) {
            $text = $token[1];
            if (!$inside) {
                if ($text === '{') {
                    $inside = true;
                }
                continue;
            }
            if (wp_fts_php_source_token_is_trivia($token)) {
                continue;
            }
            return $token;
        }
        return null;
    };

    $firstWorkMarkers = [
        'index_post' => 'return $this->index_prepared_document',
        'index_document' => 'if ($doc_id < 0)',
        'index_document_fields' => 'return $this->index_prepared_document',
        'delete_document' => '$existing = $this->storage->get_doc',
    ];
    foreach ($firstWorkMarkers as $method => $firstWorkMarker) {
        $methodSource = wp_fts_wc_contract_function_source($indexer, $method);
        $token = $firstToken($methodSource);
        assert_true(is_array($token) && $token[0] === 'if', "{$method} must begin with its relational capability guard");
        assert_contains('instanceof WP_FTS_Set_Oriented_Search_Storage', $methodSource, "{$method} should derive rejection from storage capability");
        assert_contains('throw new LogicException', $methodSource, "{$method} should throw the stable relational exception");
        $guardMessage = strpos($methodSource, 'Set-oriented storage mutations must use the bounded batch writer.');
        $firstWork = strpos($methodSource, $firstWorkMarker);
        assert_true(
            $guardMessage !== false && $firstWork !== false && $guardMessage < $firstWork,
            "{$method} must reject before validation, extraction, storage, or analysis"
        );
    }

    $mysqlStorage = (string) file_get_contents(dirname(__DIR__, 2) . '/src/MysqlStorage.php');
    foreach (['replace_doc_postings', 'put_doc', 'put_doc_metadata', 'delete_doc'] as $method) {
        $methodSource = wp_fts_wc_contract_function_source($mysqlStorage, $method);
        $token = $firstToken($methodSource);
        assert_true(is_array($token) && $token[0] === 'throw', "{$method} must reject before validation, locks, or SQL");
        assert_contains('throw new LogicException', $methodSource, "{$method} should throw the stable relational exception");
        assert_contains('Set-oriented storage mutations must use the bounded batch writer.', $methodSource, "{$method} should retain the exact relational exception message");
    }
});

test_case('relational preparation is pure and claim options reach the bounded preload', function (): void {
    $indexer = (string) file_get_contents(dirname(__DIR__, 3) . '/components/full-text-search/src/Indexer.php');
    $preparePost = wp_fts_wc_contract_function_source($indexer, 'prepare_post');
    $prepareSource = wp_fts_wc_contract_function_source($indexer, 'prepare_post_source');
    assert_contains('prepare_post_source($post, $opts)', $preparePost, 'prepare_post must delegate to the guarded source preparation path');
    foreach ([
        'instanceof WP_FTS_Set_Oriented_Search_Storage',
        "'render_blocks'",
        "'render_shortcodes'",
        "'render_content_callback'",
        "'dynamic_rendering_not_set_oriented'",
        'Dynamic rendering is unavailable in the bounded relational worker; index static post_content or provide precomputed attached fields.',
        "property_exists(\$post, 'terms')",
        "is_array(\$post->terms)",
        "property_exists(\$post, 'custom_fields')",
        "is_array(\$post->custom_fields)",
        'Set-oriented post preparation requires authoritative terms and custom_fields arrays.',
        "array_keys(\$post->custom_fields)",
    ] as $required) {
        assert_contains($required, $prepareSource, "set-oriented source preparation should retain {$required}");
    }
    $dynamicFence = strpos($prepareSource, "'dynamic_rendering_not_set_oriented'");
    $authorityFence = strpos($prepareSource, 'Set-oriented post preparation requires authoritative terms and custom_fields arrays.');
    $postIdRead = strpos($prepareSource, '$postProperties = get_object_vars($post);');
    assert_true(
        $dynamicFence !== false
            && $authorityFence !== false
            && $postIdRead !== false
            && $dynamicFence < $authorityFence
            && $authorityFence < $postIdRead,
        'dynamic and source-authority fences must run before even post-id normalization'
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
    assert_contains("!array_key_exists('custom_fields', \$index_options)", $preload, 'configured keys should be fallback-only');
    assert_contains("!array_key_exists('custom_field_keys', \$index_options)", $preload, 'the custom-field alias should remain explicit claim authority');

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
    $failureCapture = strpos($cleanup, 'capture_failure_environment_artifacts');
    $archivePublication = strpos($cleanup, 'publish_evidence');
    $composeTeardown = strpos($cleanup, 'docker compose -f "${COMPOSE_FILE}" down');
    assert_true(
        is_int($failureCapture)
            && is_int($archivePublication)
            && is_int($composeTeardown)
            && $failureCapture < $archivePublication
            && $archivePublication < $composeTeardown,
        'failure logs and state must be captured before the raw archive and Docker teardown'
    );
    $temporary = sys_get_temp_dir() . '/wp-fts-failure-publication-' . bin2hex(random_bytes(6));
    $bin = $temporary . '/bin';
    mkdir($bin, 0777, true);
    $stub = $bin . '/stub';
    file_put_contents($stub, "#!/usr/bin/env sh\nexit 0\n");
    chmod($stub, 0700);
    foreach (['docker', 'composer', 'unzip', 'rsync'] as $command) {
        symlink($stub, $bin . '/' . $command);
    }
    $output = $temporary . '/report.json';
    $missingRef = 'refs/heads/wp-fts-intentionally-missing-' . bin2hex(random_bytes(6));
    $path = getenv('PATH');
    $path = is_string($path) ? $path : '/usr/local/bin:/usr/bin:/bin';

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
        assert_true($clean['exit'] !== 0, 'the isolated fixture should stop at its intentionally absent legacy commit');
        assert_true(
            !str_contains($clean['stdout'] . $clean['stderr'], 'source tree is dirty'),
            'the required in-tree RUNNING envelope must not invalidate the cleanliness snapshot that preceded it'
        );
        $failure = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        assert_same('FAIL', $failure['status'] ?? null, 'the later fixture failure should still replace RUNNING with a terminal envelope');
        assert_same('baseline-worktree-create', $failure['phase'] ?? null, 'a clean source should advance beyond cleanliness validation');

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
        assert_same('source-ref-resolve', $preexistingFailure['phase'] ?? null, 'ignored preexisting .context state must be rejected before baseline-worktree-create');

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

test_case('relational worst-case rejects a legacy PASS artifact after process death', function (): void {
    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is required to exercise legacy process failure replacement.');
    }

    $root = dirname(__DIR__, 2);
    $runner = (string) file_get_contents($root . '/tools/run-relational-fts-worst-case.sh');
    $start = strpos($runner, "run_baseline_performance_case() {");
    $end = strpos($runner, "\n}\n\nset_run_stage \"baseline-corpus-and-index\"", $start === false ? 0 : $start);
    assert_true(is_int($start) && is_int($end), 'legacy wrapper function should remain independently executable');
    $function = substr($runner, $start, $end - $start + 2);

    $temporary = sys_get_temp_dir() . '/wp-fts-baseline-death-' . bin2hex(random_bytes(6));
    $evidence = $temporary . '/evidence';
    mkdir($evidence, 0777, true);
    $script = $temporary . '/probe.sh';
    $source = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
EVIDENCE_DIR="$1"
ACTIVE_SOURCE_SHA="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
ACTIVE_ZIP_SHA256="bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
PROFILE=2k
env_options() { printf '%s\n' -e WP_FTS_WC_PHASE=baseline-performance; }
timed_compose() {
    cat > "${EVIDENCE_DIR}/baseline-performance-common_or.json" <<'JSON'
{"schema":"relational-fts-baseline-performance-v2","case":"common_or","status":"PASS","sentinel":"written-before-death"}
JSON
    return 137
}
BASH;
    file_put_contents($script, $source . "\n" . $function . "\nrun_baseline_performance_case common_or\n");
    chmod($script, 0700);

    try {
        $result = test_run_subprocess(['bash', $script, $evidence], $root);
        assert_same(0, $result['exit'], 'legacy wrapper should convert the child death into explicit comparison evidence');
        $path = $evidence . '/baseline-performance-common_or.json';
        $discardedPath = $path . '.pre-failure.json';
        assert_true(is_file($path) && is_file($discardedPath), 'wrapper should retain both terminal FAIL and diagnostic pre-failure objects');
        $terminal = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $discarded = json_decode((string) file_get_contents($discardedPath), true, 512, JSON_THROW_ON_ERROR);
        assert_same('FAIL', $terminal['status'] ?? null, 'nonzero legacy child status must override an earlier PASS artifact');
        assert_same(137, $terminal['error']['exit'] ?? null, 'terminal evidence should retain the real child exit');
        assert_same('KilledOrOOM', $terminal['error']['class'] ?? null, 'early SIGKILL should not be reported as a completed comparison');
        assert_same('PASS', $discarded['status'] ?? null, 'pre-failure artifact should remain diagnostic rather than accepted');
        assert_same(hash_file('sha256', $discardedPath), $terminal['discarded_pre_failure_artifact']['sha256'] ?? null, 'terminal evidence should bind the discarded object by hash');
    } finally {
        foreach (glob($evidence . '/*') ?: [] as $pathToRemove) {
            @unlink($pathToRemove);
        }
        @unlink($script);
        @rmdir($evidence);
        @rmdir($temporary);
    }
});

test_case('production search cannot fall through to legacy posting-list ranking', function (): void {
    $rejected = false;
    try {
        WP_FTS_Searcher::for_set_oriented_storage(
            new WP_FTS_Storage_InMemory(),
            new WP_FTS_Analyzer()
        );
    } catch (LogicException) {
        $rejected = true;
    }
    assert_true($rejected, 'production factory must reject the legacy in-memory posting backend');

    $root = dirname(__DIR__, 2);
    $productionSources = [];
    foreach (glob($root . '/src/*.php') ?: [] as $path) {
        $productionSources[$path] = (string) file_get_contents($path);
    }
    assert_true($productionSources !== [], 'production adapter sources should be inspectable');
    foreach ($productionSources as $path => $source) {
        assert_true(
            !str_contains($source, 'new WP_FTS_Searcher'),
            basename($path) . ' must not bypass the set-oriented production factory'
        );
    }
    assert_contains(
        'WP_FTS_Searcher::for_set_oriented_storage(self::storage(false), self::runtime_analyzer())',
        $productionSources[$root . '/src/Plugin.php'] ?? '',
        'the shared WordPress search page must use the fail-closed production factory'
    );

    $analyzerSource = (string) file_get_contents(dirname($root) . '/components/full-text-search/src/Analyzer.php');
    $pipelineSource = (string) file_get_contents(dirname($root) . '/components/full-text-search/src/LanguagePipeline.php');
    assert_true(!str_contains($analyzerSource, 'detect_lemma_pack_language'), 'query language detection must not probe every enabled lemma pack');
    assert_true(!str_contains($pipelineSource, 'detect_lemma_pack_language'), 'the cross-pack dictionary router must not remain callable');
});

test_case('production component bootstrap lazy loads legacy storage fixtures', function (): void {
    $bootstrap = dirname(__DIR__, 3) . '/components/full-text-search/src/bootstrap.php';
    $code = 'require ' . var_export($bootstrap, true) . ';'
        . 'echo class_exists("WP_FTS_Storage_InMemory", false) ? "eager" : "lazy";'
        . '$storage = new WP_FTS_Storage_InMemory();'
        . 'echo $storage instanceof WP_FTS_Storage_InMemory ? ":loaded" : ":missing";';
    $result = test_run_subprocess([PHP_BINARY, '-r', $code], dirname(__DIR__, 2));

    assert_same(0, $result['exit'], 'legacy fixture autoload subprocess should succeed');
    assert_same('lazy:loaded', $result['stdout'], 'normal bootstrap should defer legacy storage code until a fixture explicitly asks for it');
});

test_case('surface-prefix architecture stays bounded and documents its irreducible broad work', function (): void {
    $root = dirname(__DIR__, 2);
    $surface = (string) file_get_contents(__DIR__ . '/surface-prefix-containment.php');
    $storage = (string) file_get_contents($root . '/src/MysqlStorage.php');
    $relationalRegressions = (string) file_get_contents(__DIR__ . '/relational-v4-cursor-and-lease-regressions.php');
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
        'legacy collection APIs fail closed while bounded document diagnostics stay post-first',
        "!in_array('term_hash', \$termIndexes, true)",
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
        'no materialized scan of the common exact posting list',
        'selective-prefix anchor AND warm p95 / p99',
        'selective-prefix anchor rows examined / common exact materializations',
        'leftover `term_hash` column/index',
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
    assert_contains('relational v6 broad non-anchor prefixes execute candidate-first with exact score', $relationalRegressions, 'real SQLite SQL must retain candidate-first membership and score parity');
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
    $supportQueue = wp_fts_wc_contract_function_source($pluginSource, 'support_snapshot_queue_cron_indexing');
    $diagnose = wp_fts_wc_contract_function_source($cliSource, 'diagnose');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');
    $readme = (string) file_get_contents($root . '/README.md');
    $operations = (string) file_get_contents($root . '/docs/operations.md');

    assert_true(!str_contains($pluginSource, 'include_exact_counts'), 'operator status should expose no switch that can revive exhaustive corpus counts');
    foreach ([
        "'counts_exact' => false",
        "'eligible_count' => null",
        "'indexed_count' => null",
        "'remaining_count' => null",
    ] as $required) {
        assert_contains($required, $operator, "operator status should retain bounded count contract: {$required}");
    }
    assert_contains('self::operator_status(true)', $supportSnapshot, 'support snapshots should opt into bounded physical schema verification');
    assert_contains("'remaining_count' => null", $supportQueue, 'support snapshots must preserve unknown remaining count instead of reporting a false zero');
    assert_contains('WP_FTS_Plugin::operator_status(true)', $diagnose, 'diagnose should opt into bounded physical schema verification');
    $physicalGuard = strpos($diagnose, 'physical_schema_usable');
    $searchCall = strpos($diagnose, 'WP_FTS_Plugin::search_with_explain');
    assert_true(is_int($physicalGuard) && is_int($searchCall) && $physicalGuard < $searchCall, 'diagnose should reject damaged physical schema before a search can schedule recovery');
    foreach (['schedule_queue_processor(', 'schedule_queue_processor_for_operator(', 'upgrade_schema(', 'enqueue_corpus_scope('] as $mutation) {
        assert_true(!str_contains($operator, $mutation), "operator status must not call mutator {$mutation}");
        assert_true(!str_contains($supportSnapshot, $mutation), "support snapshots must not call mutator {$mutation}");
        assert_true(!str_contains($diagnose, $mutation), "diagnose must not call mutator {$mutation}");
    }

    assert_contains('still report `counts_exact=false`', $acceptance, 'acceptance should keep physical diagnostics count-free');
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
        '--performance-schema-max-thread-instances=128',
        'db_data:/var/lib/mysql',
        '${EVIDENCE_DIR}:/evidence',
        '${MUTATION_PROOF_SCRIPT}:/proof/mutation-fence-concurrency.php:ro',
        '${ISOLATED_BOUNDARIES_SCRIPT}:/proof/relational-fts-isolated-boundaries.php:ro',
        'WP_FTS_MUTATION_QUEUE_PATH=/var/www/html/wp-content/plugins/indexer/src/IndexQueue.php',
        'wordpress timeout -s KILL 180 php /proof/mutation-fence-concurrency.php',
        'run_php_phase indexing-prepare',
        '"mode"=>"forced_full_rebuild"',
        'RUN_COMPLETED=0',
        'RUN_PUBLISHED=0',
        'relational-fts-run-state-v2',
        '${OUTPUT}.partial-evidence.json',
        'could not atomically publish the complete raw artifact bundle',
        'could not atomically publish the completed evidence report',
        'worktree add --detach "${SOURCE_ROOT}" "${SOURCE_COMMIT}"',
        'initialize_and_attest_jieba_source',
        'components/full-text-search/resources/sources/jieba',
        'indexer/resources/sources/jieba',
        '${EVIDENCE_DIR}/jieba-source-current.json',
        '${EVIDENCE_DIR}/jieba-source-baseline.json',
        'submodule update --init --depth 1',
        '7197c3211ddd98962b036cdf40324d1ea2bfaa12bd028e68faa70111a88e12a8',
        '18ba0984839f85853b29fadaf992f7dba8fd0ca0fbeae34de2b8735222dc7a37',
        'COLD_SAMPLES=10',
        'DB_PRE_CORPUS_PEAK_LIMIT_BYTES=805306368',
        '/sys/fs/cgroup/memory.peak',
        '/sys/fs/cgroup/memory/memory.max_usage_in_bytes',
        'event_value /sys/fs/cgroup/memory.events oom',
        'event_value /sys/fs/cgroup/memory.events oom_kill',
        'event_value /sys/fs/cgroup/memory/memory.oom_control oom_kill',
        'capture_database_memory_checkpoint "pre-cold-restart-${case_id}-${sample}"',
        'configure_performance_schema_consumers "cold-performance-schema-enable-${case_id}-${sample}"',
        'capture_database_memory_checkpoint final-workload',
        'finalize_database_memory_evidence',
        '["common_or","max_valid_or_prefix","rare_anchor_and","prefix_fanout"]',
        '"expected_checkpoint_labels"',
        '$actualLabels!==$expectedLabels',
        '"whole_run_peak_bytes"',
    ] as $required) {
        assert_contains($required, $runner, "runner should retain hard acceptance contract: {$required}");
    }
    $expectedLanes = [
        '2k/mysql-5.7) LANE_ID="mysql57-2k" ;;',
        '50k/mariadb-10.11) LANE_ID="mariadb1011-50k" ;;',
        '50k/mysql-8.0) LANE_ID="mysql80-50k" ;;',
        '100k/mariadb-10.11) LANE_ID="mariadb1011-100k" ;;',
        '100k/mysql-8.0) LANE_ID="mysql80-100k" ;;',
    ];
    foreach ($expectedLanes as $lane) {
        assert_contains($lane, $runner, "runner should retain the clean acceptance lane: {$lane}");
    }
    assert_same(count($expectedLanes), substr_count($runner, ') LANE_ID="'), 'runner must expose exactly five clean profile/engine lane identities');
    assert_same(3, substr_count($runner, 'configure_performance_schema_consumers'), 'the exact Performance Schema consumers must be configured initially and after every cold restart through one implementation');
    $laneMap = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_expected_lane_id');
    foreach ([
        "'2k/mysql-5.7' => 'mysql57-2k'",
        "'50k/mariadb-10.11' => 'mariadb1011-50k'",
        "'50k/mysql-8.0' => 'mysql80-50k'",
        "'100k/mariadb-10.11' => 'mariadb1011-100k'",
        "'100k/mysql-8.0' => 'mysql80-100k'",
    ] as $lane) {
        assert_contains($lane, $laneMap, "integration evidence should retain the clean acceptance lane: {$lane}");
    }
    assert_same(count($expectedLanes), substr_count($laneMap, " => '"), 'integration evidence must accept exactly the same five clean lane identities as the runner');
    assert_contains('one of the five clean acceptance lanes', $runner, 'unsupported clean tuples should report the complete lane cardinality');
    assert_contains('cannot substitute for one of these five lanes', $acceptance, 'the written contract should require every clean lane identity');
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
        '@@performance_schema_events_statements_history_long_size AS performance_schema_history_long_events',
        '@@performance_schema_events_statements_history_size AS performance_schema_thread_history_events',
        '@@performance_schema_digests_size AS performance_schema_digests',
        '@@performance_schema_max_thread_instances AS performance_schema_thread_capacity',
        "'performance_schema_events_statements_history_long_size'",
        "'performance_schema_events_statements_history_size'",
        "'performance_schema_digests_size'",
        "'performance_schema_max_thread_instances'",
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
    assert_true(!str_contains($cold, "=== '2k'"), 'the cold evidence consumer must not retain a 2k shortcut');
    $idle = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_idle_http');
    foreach (['$iterations = WP_FTS_WC_IDLE_HTTP_REQUEST_COUNT;', 'idle_http_request_count', 'idle_http_sample_count', 'relational-fts-idle-http-v2'] as $required) {
        assert_contains($required, $idle, "idle HTTP evidence should enforce the fixed 100-request sequence: {$required}");
    }
    assert_true(!str_contains($idle, "=== '2k'"), 'the idle HTTP proof must not retain a 2k shortcut');
    $finalize = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_finalize');
    assert_contains("['idle_http_request_count', 'idle_http_sample_count', 'idle_http_errors', 'idle_http_p95_ms']", $finalize, 'the finalizer should require both idle HTTP cardinality gates');
    assert_contains("'relational-fts-idle-http-v2'", $finalize, 'the finalizer should reject an older idle HTTP artifact without fixed cardinality');
    assert_true(!str_contains($runner, 'if [[ "${PROFILE}" == "2k" ]]'), 'the runner must not reduce cold samples for MySQL 5.7');
    assert_contains('`2k/mysql-5.7` compatibility lane runs the same 20 warmups, 200 warm samples', $acceptance, 'the written contract should explicitly include MySQL 5.7 in the full sample sequence');
    assert_contains('ten conditioned cold samples per case, and 100-request idle HTTP baseline', $acceptance, 'the written contract should state the full MySQL 5.7 cold and idle sequence');
    record_check('relational worst-case fixed profile contract', 16);
});

test_case('relational worst-case corpus starts with an empty configured post-type namespace', function (): void {
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $setup = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_setup');
    $settings = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_enable_search_settings');

    assert_contains("const WP_FTS_WC_INDEX_POST_TYPES = ['post', 'page', 'attachment'];", $integration, 'the deterministic corpus and public search scope should share one post-type list');
    assert_contains('count(WP_FTS_WC_INDEX_POST_TYPES)', $setup, 'fixture cleanup should prepare one placeholder for every configured post type');
    assert_contains('...WP_FTS_WC_INDEX_POST_TYPES', $setup, 'fixture cleanup should bind the shared configured post types');
    assert_contains('ORDER BY ID ASC', $setup, 'fixture cleanup should enumerate deterministic post IDs');
    assert_contains("'configured post-type fixture namespace after cleanup'", $setup, 'fixture cleanup should count its exact configured namespace after deletion');
    assert_contains('wp_fts_wc_assert($remainingIndexedPosts === 0', $setup, 'fixture setup should fail while any configured post type remains');
    assert_true(!str_contains($setup, "post_type = 'post'"), 'fixture cleanup must not leave stock pages or attachments outside its oracle corpus');
    assert_contains("\$settings['index_post_types'] = WP_FTS_WC_INDEX_POST_TYPES;", $settings, 'public search settings should use the same post-type list as fixture cleanup');
});

test_case('relational worst-case records measured mutation SQL and effective pinned resources', function (): void {
    $root = dirname(__DIR__, 2);
    $runnerPath = $root . '/tools/run-relational-fts-worst-case.sh';
    $runner = (string) file_get_contents($runnerPath);
    $mutation = (string) file_get_contents(dirname(__DIR__) . '/integration/mutation-fence-concurrency.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');

    foreach ([
        'statement_marker()',
        'statements_since($marker)',
        "'statement_evidence_schema' => 'relational-fts-mutation-statements-v1'",
        "'fence_boundary_statement_counts' => \$fenceStatementCounts",
        "'promotion_boundary_statement_counts' => \$promotionStatementCounts",
        "'boundary_statement_evidence' => \$boundaryEvidence",
        "'schema' => 'relational-fts-mutation-generation-cas-v2'",
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
        'immutable baseline package did not use the hardened source-bound Composer policy',
    ] as $required) {
        assert_contains($required, $runner, "runner should retain fail-closed resource evidence: {$required}");
    }
    assert_true(!str_contains($runner, 'php "${BASELINE_ROOT}/indexer/tools/build-release-zip.php"'), 'the runner must not execute historical packaging code with obsolete Composer policy');

    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    foreach ([
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
        'wp_fts_wc_database_memory_evidence_is_exact',
        'wp_fts_wc_package_reproducibility_is_exact',
        'package_toolchain_recorded',
        'composerStateValid',
        "str_starts_with((string) \$toolchain['composer_version'], 'Composer version 2.9.8 ')",
        'recorded non-acceptance image override',
        '!$requireAcceptanceDigest || $matchesExpected',
        "\$image['running_container_image_id']",
        'relational-fts-mutation-generation-cas-v2',
        'relational-fts-mutation-statements-v1',
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
    ] as $required) {
        assert_contains($required, $memoryContract, "restart-safe database memory contract must retain every segment maximum: {$required}");
    }
    foreach (['cgroup peak must be at most 768 MiB', 'exact ordered 42-checkpoint inventory', 'restart therefore cannot erase', 'requires zero OOM and OOM-kill events', 'no tighter cache-sensitive threshold'] as $required) {
        assert_contains($required, $acceptance, "constrained-host acceptance must document actual peak/OOM semantics: {$required}");
    }
    foreach ([
        'mariadb@sha256:5a5c675881ef3fd1c1da9b0a3bfd6ee82edbe39cd9e32e06be18034c37235e0e',
        'mysql@sha256:4bc6bc963e6d8443453676cae56536f4b8156d78bae03c0145cbe47c2aad73bb',
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
        'WP_FTS_MYSQL57_IMAGE',
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
            '--output=' . sys_get_temp_dir() . '/wp-fts-override-rejection.json',
        ], $root);
        assert_same(1, $result['exit'], "{$override} must fail before a clean acceptance run starts");
        assert_contains($override, $result['stdout'] . $result['stderr'], 'the rejected override should be named explicitly');
        assert_contains('image overrides are forbidden in clean acceptance lanes', $result['stdout'] . $result['stderr'], 'clean override rejection should explain the acceptance rule');
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
        'WP_FTS_IB_Distinct_Term_Analyzer(4096',
        'WP_FTS_IB_Distinct_Term_Analyzer(4097',
        'WP_FTS_IB_QUEUE_ACCEPTED_COUNT = 1000',
        'all_reject_paths_actual_wpdb_statement_count',
        'php_peak_memory_within_128_mib',
        'proc_vmrss_within_128_mib',
    ] as $required) {
        assert_contains($required, $isolated, "isolated real-WordPress proof should retain exact boundary: {$required}");
    }
    foreach (['4,095-byte contiguous CJK', '4,096 distinct terms', '4,097 terms', '1,000-ID enqueue', '1,001 IDs', '180-second'] as $required) {
        assert_contains($required, $acceptance, "acceptance writeup should retain isolated hard boundary: {$required}");
    }
    assert_contains('exactly 62 uniquely named passing', $acceptance, 'acceptance writeup should match the isolated runner and finalizer gate cardinality');
});

test_case('relational worst-case evidence gates query shape, memory, rows, latency, and failures', function (): void {
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $acceptance = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/relational-search-acceptance.md');
    foreach ([
        'relational-fts-evidence-v2',
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
        'rows_examined_conservative',
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
        'worker_batch_legacy_fts_statement_count',
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
        'unchanged_requeues_worker_analyzed',
        'unchanged_requeues_content_hash_signature',
        'unchanged_requeues_surface_posting_signature',
        'unchanged_requeues_index_data_writes',
        'direct_set_oriented_mutation_guard_contract',
        'direct_set_oriented_mutation_zero_wpdb_statements',
        'direct_set_oriented_mutation_zero_source_calls',
        'direct_set_oriented_mutation_zero_option_callbacks',
        'direct_set_oriented_mutation_zero_analyzer_calls',
        'Set-oriented storage mutations must use the bounded batch writer.',
        'wp_fts_wc_direct_set_oriented_mutation_rejection_proof',
        'set_oriented_post_preparation_authority_contract',
        'set_oriented_post_preparation_missing_authority_zero_side_effects',
        'Set-oriented post preparation requires authoritative terms and custom_fields arrays.',
        'set_oriented_dynamic_rendering_rejected_before_callbacks',
        'dynamic_rendering_not_set_oriented',
        'Dynamic rendering is unavailable in the bounded relational worker; index static post_content or provide precomputed attached fields.',
        'runtime_analyzer_default_provider_io_absent',
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
        'schema_no_term_hash_column_or_index',
        'dense_relationship_active_targeted_broad_prefix_rows_examined',
        'dense_relationship_rank_control_revocation_short_circuits_postings',
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
        'missing_table_{$caseId}_post_fault_state_latched',
        "(\$faultIsolation['post_fault_repair_scheduled'] ?? null) === true",
    ] as $required) {
        assert_contains($required, $missingTableGates, "missing-table critical gate must retain production latch contract: {$required}");
    }
    assert_same(
        5,
        substr_count($missingTableGates, 'wp_fts_wc_measure_missing_table_http('),
        'the five-adapter missing-table plan proof must remain intact beside stage injection'
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
    foreach (['WP_FTS_Plugin::SCHEMA_UPGRADE_CRON_HOOK', "(\$event['schedule'] ?? null) === false", "(\$event['args'] ?? null) === []"] as $required) {
        assert_contains($required, $cronSnapshot, "repair schedule evidence must retain exact single-event semantics: {$required}");
    }
    assert_contains("interface_exists('WP_FTS_Set_Oriented_Search_Storage')", $integration, 'proof should fail explicitly when the real set-oriented backend is absent');
    assert_contains("method_exists(\$queue, 'enqueue_many')", $integration, 'proof should require set-oriented work enqueueing');
    foreach ([
        'baseline-performance',
        'migration-failpoint',
        'wp_fts_schema_migration_phase',
        'multisite-migration',
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
    $storage = (string) file_get_contents(dirname(__DIR__, 2) . '/src/MysqlStorage.php');
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
    assert_contains('changed FORCE INDEX (post_term_impact)', $storage, 'the old-frequency decrement must force its post-first covering driver');
    assert_contains('STRAIGHT_JOIN {$this->termsTable} AS t FORCE INDEX (PRIMARY)', $storage, 'the old-frequency decrement must primary-key join the target after materialization');
    assert_contains('MAX_TERM_RESOLUTION_IDENTITIES = 8192', $storage, 'the maximum document must resolve its dictionary in one proven-width indexed read');
    assert_contains('$postsWithOldPostings = array_keys(array_filter(', $storage, 'fresh documents must derive an empty retirement set from measured old-posting counts');
    assert_contains('if ($postsWithOldPostings !== [])', $storage, 'fresh documents must skip the dictionary decrement statement');
    assert_contains('if ($retiredPosts !== [])', $storage, 'fresh documents must skip the bounded deletion statement');
    assert_true(!str_contains($storage, 'dictionary_delta_relation'), 'production storage must not restore the self-referential dictionary INSERT/SELECT');
    assert_contains('run_wpcli_php_phase wpcli-adapter', $runner, 'real database runner should measure the installed WP-CLI command through wpdb');
    assert_contains('run_wpcli_php_phase runtime-profile', $runner, 'real database runner should compare the complete web and WP-CLI index profiles');
    assert_contains('config set DISABLE_WP_CRON true --raw', $runner, 'fault injection must not race a spawned loopback repair process');
    foreach (['runtime_profile_full_parity', 'runtime_analyzer_signature_parity', 'runtime_unicode_normalizer_signature_parity'] as $gate) {
        assert_contains($gate, $runner, "real database runner should retain runtime parity gate: {$gate}");
    }
    record_check('relational worst-case evidence contract', 39);
});

test_case('relational worst-case retains the real 819200-row old-posting frontier proof', function (): void {
    $root = dirname(__DIR__, 2);
    $runner = (string) file_get_contents($root . '/tools/run-relational-fts-worst-case.sh');
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $frontier = (string) file_get_contents(dirname(__DIR__) . '/integration/old-posting-frontier.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');

    foreach ([
        '100 x 8,192 old-posting fixture',
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
        'candidate_posting FORCE INDEX (post_term_impact)',
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
        "'schema_version' => 9",
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
    foreach (['**819,200** old rows', '**50,001** rows inside', '**49,152** terms', '`doc_freq=2`', '`STRAIGHT_JOIN`', '**50,100**', 'combined posting/dictionary/document deletion', 'exactly **17** passes', '`post_term_impact`', '**919,200** populated postings', 'storage-only proof', 'exactly **9**', 'database statements', 'no `DELETE` or `COUNT`', '**10 plugin-owned', 'one epoch read plus **9 writes**'] as $required) {
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
        "\$groupedPosition < \$visibilityPosition",
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

    assert_same(1, substr_count($plugin, 'wp_fts:dependency_measurement'), 'the bounded dependency measurement should have one stable production SQL tag');
    assert_same(2, substr_count($plugin, 'wp_fts:dependency_values'), 'both possible value branches should carry the same stable production SQL tag');
    assert_contains('LEFT(CAST(pm.meta_value AS BINARY)', $plugin, 'MySQL and MariaDB dependency projections must count bytes, not characters');
    assert_contains('SUBSTR(CAST(pm.meta_value AS BLOB)', $plugin, 'SQLite dependency projections must count bytes, not characters');
    foreach ([
        'WP_FTS_WC_DEPENDENCY_LOB_BYTES = 262144',
        'WP_FTS_WC_DEPENDENCY_ACCEPTED_UNSELECTED_ROWS = 511',
        'WP_FTS_WC_DEPENDENCY_OVERFLOW_UNSELECTED_ROWS = 512',
        "'dependency-lob' => wp_fts_wc_dependency_lob()",
        'REPEAT(%s,%d)',
        'performance_schema.events_statements_history_long',
        'EXPLAIN FORMAT=JSON',
        'dependency_lob_measurement_rows_sent',
        'dependency_lob_measurement_rows_examined',
        'dependency_lob_value_rows_sent',
        'dependency_lob_value_rows_examined',
        'dependency_lob_tmp_disk_tables',
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
    assert_contains('run_php_phase dependency-lob', $runner, 'every real database profile should execute the dependency LOB proof');
    assert_true(strpos($runner, 'run_php_phase dependency-lob') < strpos($runner, 'run_php_phase validate'), 'dependency LOB gates must exist before final validation evidence is assembled');
    foreach (['real `wp_postmeta` table', '511 unselected 256 KiB values', 'return 1,027 measurement rows', 'strictly below twice', 'between measurement and hydration', 'dirty and invisible', 'A fake database or small placeholder value'] as $required) {
        assert_contains($required, $acceptance, "acceptance writeup should retain actual LOB requirement: {$required}");
    }
    record_check('relational actual dependency LOB contract', 40);
});

test_case('multisite migration probes the exact legacy and current dictionary identities', function (): void {
    $root = dirname(__DIR__, 2);
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');
    $state = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_multisite_sentinel_state');
    $layout = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_multisite_term_dictionary_layout');
    $frequency = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_multisite_term_doc_freq');

    assert_same(1, substr_count($state, '$termDictionaryLayout = wp_fts_wc_multisite_term_dictionary_layout()'), 'one schema probe should classify the dictionary for the complete site snapshot');
    assert_same(2, substr_count($state, 'wp_fts_wc_multisite_term_doc_freq($termKey, $termDictionaryLayout)'), 'own and foreign DF probes should share that positively identified layout');
    assert_same(1, substr_count($layout, 'SHOW COLUMNS FROM'), 'dictionary classification should need one bounded metadata statement');
    assert_contains("['term', 'doc_freq']", $layout, 'the immutable baseline dictionary must be recognized by its exact physical columns');
    assert_contains("['term_id', 'lang', 'kind', 'term', 'doc_freq']", $layout, 'the current dictionary must be recognized by its exact physical columns');
    assert_contains('Unsupported multisite sentinel dictionary schema:', $layout, 'unknown or partial migration schemas must fail closed');
    assert_contains('WHERE term=%s LIMIT 1', $frequency, 'legacy DF probes must bind the complete namespaced binary term identity');
    assert_contains('WHERE lang=%s AND kind=0 AND term=%s LIMIT 1', $frequency, 'current DF probes must bind the composite primary lexical identity');
    assert_contains('Unsupported multisite sentinel dictionary layout:', $frequency, 'an unclassified layout must never fall through to either query shape');
    foreach (['positively classifies', 'legacy single-column binary term identity', 'current composite identity'] as $required) {
        assert_contains($required, $acceptance, "migration acceptance should retain the dual dictionary invariant: {$required}");
    }
    record_check('relational migration dictionary shape contract', 12);
});

test_case('migration post-kill proof accounts for every phase-specific FTS write', function (): void {
    $root = dirname(__DIR__, 2);
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');
    $probe = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_migration_post_kill_probe');
    $phase = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_migration_phase_state');
    $work = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_migration_work_counts');
    $physical = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_is_fts_physical_schema_statement');
    $ownership = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_sql_touches_owned_fts_table');
    $tokens = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_sql_token_stream');

    assert_contains('wp_fts_wc_sql_token_stream($sql, $includeQuotedValues)', $ownership, 'FTS ownership must be derived from SQL tokens rather than substring or regexp parsing');
    assert_contains("['word', 'identifier']", $ownership, 'only executable bare or quoted identifiers may establish FTS table ownership');
    assert_contains("['word', 'identifier', 'string']", $ownership, 'physical-schema classification must also recognize table identities encoded as quoted values');
    assert_contains("yield ['type' => 'string'", $tokens, 'the SQL stream must decode quoted schema values only on explicit request');
    assert_contains(". 'fts_'", $ownership, 'the classifier must bind ownership to the active WordPress site prefix');
    assert_true(!str_contains($physical . $ownership, 'preg_'), 'physical-schema ownership must not regress to regexp parsing');
    assert_contains('wp_fts_wc_is_physical_schema_statement($sql)', $physical, 'FTS schema classification must first require an actual physical-schema statement');
    assert_contains('wp_fts_wc_sql_touches_owned_fts_table($sql, true)', $physical, 'only the physical-schema classifier may treat quoted table values as ownership');
    $physicalFirstStage = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_is_physical_schema_statement');
    assert_contains('wp_fts_wc_sql_tokens($sql)', $physicalFirstStage, 'physical-schema classification must ignore leading comments through the shared SQL token stream');
    assert_contains("=== 'information_schema'", $physicalFirstStage, 'physical-schema classification must recognize an exact information_schema relation identifier');
    assert_true(!str_contains($physicalFirstStage, 'strtok(') && !str_contains($physicalFirstStage, 'ltrim('), 'physical-schema classification must not use whitespace-sensitive string prefixes');

    assert_contains("kind IN ('post','scope')", $work, 'semantic work cardinality must exclude the persistent search-epoch metadata row');
    assert_contains("kind IN ('post','scope','meta')", $work, 'the bounded snapshot must still account for every relevant work-table kind');
    assert_contains('WP_FTS_Index_Queue::GLOBAL_CORPUS_SCOPE_KEY', $work, 'the exact canonical corpus authority must be inspected');
    assert_contains('WP_FTS_Index_Queue::SEARCH_EPOCH_JOB_KEY', $work, 'the exact search-epoch generation must be inspected');
    foreach (['meta_rows', 'corpus_scope_generation', 'corpus_scope_incarnation', 'corpus_scope_reason', 'search_epoch_generation'] as $field) {
        assert_contains("'{$field}'", $work, "migration work evidence must retain {$field}");
    }

    assert_contains("['ready_verified', 'legacy_cleaned']", $probe, 'only the two post-reconciliation boundaries may use current-schema save expectations');
    assert_contains("['v4_created', 'reconciliation_enqueued']", $probe, 'the two writable stale-schema boundaries must use recovery-scope expectations');
    assert_contains('wp_fts_wc_sql_touches_owned_fts_table($sql)', $probe, 'failed public search must reject current, legacy, and renamed site-owned FTS tables');
    foreach ([
        'migration_post_kill_accounted_scope_work_delta',
        'migration_post_kill_accounted_meta_work_delta',
        'migration_post_kill_accounted_corpus_generation',
        'migration_post_kill_accounted_search_epoch_generation',
        'migration_post_kill_accounted_recovery_authority',
        'migration_post_kill_exact_fts_dml_count',
        'migration_post_kill_fts_dml_max_sql_bytes',
        'migration_post_kill_no_fts_schema_sql',
        'wordpress_core_schema_queries',
    ] as $required) {
        assert_contains($required, $probe, "post-kill evidence must retain {$required}");
    }
    assert_contains("=== 'foreground_queue_failure'", $probe, 'recovery work must be rebound to the explicit foreground-failure authority');
    assert_contains('WP_FTS_Plugin::SCHEMA_VERSION', $phase, 'ready migration boundaries must follow the production logical schema version');
    assert_contains("'meta_rows' => 1", $phase, 'post-enqueue migration phases must retain the persistent epoch row');

    foreach (['semantic claimable debt', 'exactly one bounded recovery-scope DML', 'advances both generations once', 'exactly two', 'WordPress core may', 'cannot excuse any FTS physical-schema statement'] as $required) {
        assert_contains($required, $acceptance, "migration acceptance must retain the phase-aware invariant: {$required}");
    }
    record_check('relational phase-aware post-kill migration contract', 45);
});

test_case('migration physical-schema classifier covers quoted identities without DML false positives', function (): void {
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    foreach ([
        'wp_fts_wc_sql_token_stream',
        'wp_fts_wc_sql_tokens',
        'wp_fts_wc_is_physical_schema_statement',
        'wp_fts_wc_sql_touches_owned_fts_table',
        'wp_fts_wc_is_fts_physical_schema_statement',
        'wp_fts_wc_is_fts_data_mutation_statement',
    ] as $function) {
        eval(wp_fts_wc_contract_function_source($integration, $function));
    }

    global $wpdb;
    $previousWpdb = $wpdb ?? null;
    $wpdb = (object) ['prefix' => 'wp_'];
    try {
        assert_true(
            wp_fts_wc_is_fts_physical_schema_statement("/* diagnostic lead */ SHOW TABLES LIKE 'wp\\_fts\\_work'"),
            'a comment-prefixed escaped SHOW TABLES LIKE value must identify the owned work table'
        );
        assert_true(
            wp_fts_wc_is_fts_physical_schema_statement("SELECT TABLE_NAME FROM `information_schema` . `TABLES` WHERE TABLE_SCHEMA='wpfts' AND TABLE_NAME='wp_fts_terms'"),
            'a spaced and backticked information_schema relation must identify its quoted owned table name'
        );
        assert_true(
            !wp_fts_wc_is_fts_physical_schema_statement('SHOW FULL COLUMNS FROM `wp_posts`'),
            'WordPress core column diagnostics must not be attributed to FTS'
        );
        $coreDml = "UPDATE wp_options SET option_value='wp_fts_work' WHERE option_name='example'";
        assert_true(
            !wp_fts_wc_is_fts_data_mutation_statement($coreDml),
            'a quoted FTS-looking option value must not turn ordinary core DML into FTS DML'
        );
        assert_true(
            !wp_fts_wc_is_fts_physical_schema_statement($coreDml),
            'a non-schema statement must not become physical FTS work because of a quoted value'
        );
        assert_true(
            wp_fts_wc_is_fts_data_mutation_statement("INSERT INTO `wp_fts_work` (job_key) VALUES ('wp_fts_terms')"),
            'an executable owned identifier must still classify FTS DML while its quoted value is ignored'
        );
        assert_true(
            wp_fts_wc_sql_touches_owned_fts_table('SELECT * FROM `wp_fts_docs`'),
            'a search-side query against a legacy site-owned table must fail the no-FTS-SQL gate'
        );
    } finally {
        $wpdb = $previousWpdb;
    }
    record_check('relational quoted physical-schema classifier contract', 7);
});

test_case('relational worst-case migration kills every table boundary and validates populated parity', function (): void {
    $root = dirname(__DIR__, 2);
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $runner = (string) file_get_contents($root . '/tools/run-relational-fts-worst-case.sh');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');
    $perTablePhases = [
        'legacy_renamed_fts_terms',
        'legacy_renamed_fts_postings',
        'legacy_renamed_fts_docs',
        'legacy_renamed_fts_doc_lengths',
        'legacy_renamed_fts_docmeta',
        'legacy_renamed_fts_meta',
        'legacy_renamed_fts_queue',
    ];
    assert_true(class_exists('WP_FTS_Plugin'), 'production plugin class should be available for migration coupling');
    $suffixMethod = new ReflectionMethod('WP_FTS_Plugin', 'legacy_relational_table_suffixes');
    $suffixMethod->setAccessible(true);
    $productionSuffixes = $suffixMethod->invoke(null);
    assert_same(
        ['fts_terms', 'fts_postings', 'fts_docs', 'fts_doc_lengths', 'fts_docmeta', 'fts_meta', 'fts_queue'],
        is_array($productionSuffixes) ? array_keys($productionSuffixes) : [],
        'every production legacy table must have an explicit destructive failpoint contract'
    );
    foreach ([...$perTablePhases, 'v4_created', 'reconciliation_enqueued', 'ready_verified', 'legacy_cleaned'] as $phase) {
        assert_contains("'{$phase}'", $integration, "integration proof should install the {$phase} failpoint");
        assert_contains($phase, $runner, "real runner should SIGKILL the {$phase} boundary");
    }
    assert_contains('BASELINE_COMMIT="36a26f4ad1aaef9758922f24677069045c5291ab"', $runner, 'future migration runs must use the immutable populated v3 fixture');
    assert_contains('BASELINE_REF="${BASELINE_COMMIT}"', $runner, 'the baseline worktree must resolve only from the immutable commit constant');
    assert_contains('if [[ "${BASELINE_SHA}" != "${BASELINE_COMMIT}" ]]', $runner, 'the runner must reject any baseline ref that does not resolve to the immutable fixture');
    assert_true(!str_contains($runner, 'BASELINE_REF="origin/main"'), 'migration evidence must not silently change its source schema after this PR merges');
    assert_true(!str_contains($runner, 'for migration_phase in legacy_renamed v4_created'), 'runner must not collapse seven table renames into one coarse failpoint');

    $snapshotStart = strpos($integration, 'function wp_fts_wc_migration_snapshot()');
    $snapshotEnd = strpos($integration, 'function wp_fts_wc_migration_oracle()', $snapshotStart === false ? 0 : $snapshotStart);
    $finalizeStart = strpos($integration, 'function wp_fts_wc_migration_finalize()');
    $finalizeEnd = strpos($integration, 'function wp_fts_wc_migration_rerun()', $finalizeStart === false ? 0 : $finalizeStart);
    assert_true(is_int($snapshotStart) && is_int($snapshotEnd) && is_int($finalizeStart) && is_int($finalizeEnd), 'migration parity functions should remain independently inspectable');
    $snapshot = substr($integration, $snapshotStart, $snapshotEnd - $snapshotStart);
    $finalize = substr($integration, $finalizeStart, $finalizeEnd - $finalizeStart);
    assert_true(!str_contains($snapshot, 'sort('), 'legacy migration snapshot must preserve result order');
    assert_true(!str_contains($finalize, 'sort('), 'post-migration parity must preserve result order');
    foreach ([
        'relational-fts-v4-migration-oracle-v2',
        'legacy_bm25_float',
        'v4_quantized_impact_times_integer_rarity',
        'legacy_numeric_score_parity_expected',
        '(180234*p.tf+12) DIV (20*p.tf+24)',
        '1000000 DIV GREATEST(1,t.doc_freq)',
        'wp_fts_wc_v4_ordered_score_signature',
        'migration-rerun',
        '_fresh_process_stable',
        'WP_FTS_WC_MULTISITE_POST_TITLE_PREFIX',
        'multisite_search_order_parity',
        'multisite_doc_freq_parity',
        'multisite_document_parity',
        'multisite_cross_site_isolation',
        'multisite_recorded_prefix_parity',
        'migration_search_takeover_ready',
        'multisite_search_takeover_ready',
        'multisite_diagonal_token_matches',
        'multisite_off_diagonal_token_isolation',
        'multisite_empty_foreign_doc_freq_probes',
        'multisite_v4_oracle_score_parity',
        'multisite_index_content_hash_stable',
        'multisite_site_three_physical_sentinel_untouched_after_site_two_failure',
        'multisite_empty_foreign_query_probes',
        'multisite_site_three_untouched_after_site_two_failure',
        'migration-post-kill-probe',
        'relational-fts-migration-post-kill-probe-v2',
        'relational-fts-migration-phase-v3',
        'migration_phase_exact_tables',
        'migration_phase_exact_scope_rows',
        'migration_post_kill_public_search_unavailable',
        'migration_post_kill_public_search_duration_ms',
        'migration_post_kill_public_search_query_count',
        'migration_post_kill_public_search_max_sql_bytes',
        'migration_post_kill_accounted_health_effect',
        'migration_post_kill_exact_fts_dml_count',
        'migration_post_kill_no_core_like_sql',
        'migration_post_kill_no_fts_schema_sql',
        'relational-fts-migration-worker-journal-v1',
        'migration_worker_recorded_progress',
        'relational-fts-baseline-performance-v2',
        'migration_baseline_performance_artifacts_complete',
        'relational-fts-migration-baseline-v3',
        'ExecutionMismatch',
        'legacy_execution',
        'collection_metadata',
        'foreign_terms',
        'foreign_postings',
        'wp_fts_wc_multisite_requeue_and_drain',
        'multisite_reprocessed_sentinel_documents',
        '9007199254740991.0',
        "['post_id', 'primary_lang', 'content_hash', 'snippet_text', 'indexed_at']",
    ] as $required) {
        assert_contains($required, $integration, "migration proof should retain hard contract: {$required}");
    }
    assert_contains('run_php_phase migration-oracle', $runner, 'runner should capture the v4 oracle while v3 logical tables are intact');
    assert_contains('run_php_phase migration-rerun', $runner, 'runner should repeat migration parity in a fresh PHP process');
    assert_contains('Legacy baseline exceeded the 120-second process limit', $runner, 'a legacy timeout should retain an explicit reason instead of a null or zero measurement');
    assert_contains('"duration_ms"=>$elapsed', $runner, 'a killed legacy baseline should retain measured host wall time');
    $migrationLoopStart = strpos($runner, 'for migration_phase in');
    $migrationLoopEnd = strpos($runner, "done\nrun_php_phase migration-finalize", $migrationLoopStart === false ? 0 : $migrationLoopStart);
    assert_true(is_int($migrationLoopStart) && is_int($migrationLoopEnd), 'runner should retain one inspectable destructive migration loop');
    $migrationLoop = substr($runner, $migrationLoopStart, $migrationLoopEnd - $migrationLoopStart);
    assert_true(
        strpos($migrationLoop, 'kill_migration_phase "${migration_phase}"') < strpos($migrationLoop, 'run_migration_post_kill_probe "${migration_phase}"'),
        'a fresh availability/fail-closed probe must run immediately after every SIGKILL and before the next phase'
    );
    assert_contains('timeout -s KILL 120 php /proof/relational-fts-worst-case.php', $runner, 'every fresh post-kill availability probe needs a hard wall-clock bound');
    assert_true(
        strpos($runner, 'run_php_phase multisite-baseline-setup') > strpos($runner, 'BASELINE_INDEX_EXIT'),
        'multisite sentinels should be created after the main baseline corpus so setup cannot delete site-one evidence'
    );
    foreach (['seven physical table renames', '100,001st capped row', '138,564 construction-known candidate postings', '3×3 site-specific token matrix', 'six empty off-diagonal cells', 'append-only NDJSON', 'Immediately after every SIGKILL', 'collection-metadata', 'generation for every sentinel'] as $required) {
        assert_contains($required, $acceptance, "acceptance writeup should retain populated migration requirement: {$required}");
    }
    record_check('relational populated migration contract', 61);
});

test_case('relational migration freezes and reproduces the exact legacy candidate-budget outcome', function (): void {
    if (!function_exists('proc_open')) {
        mark_pending('proc_open() is required to execute the extracted legacy outcome contract.');
    }

    $root = dirname(__DIR__, 2);
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $runner = (string) file_get_contents($root . '/tools/run-relational-fts-worst-case.sh');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');
    $snapshot = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_migration_snapshot');
    $oracle = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_migration_oracle');
    $performance = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_baseline_performance');
    $execution = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_legacy_execution');
    $executionValidator = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_legacy_execution_is_valid');
    $baselineValidator = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_migration_baseline_is_valid');
    $oracleValidator = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_migration_oracle_is_valid');
    $finalize = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_migration_finalize');

    $sixCases = "['common_or', 'max_valid_or_prefix', 'rare_anchor_and', 'prefix_fanout', 'ambiguous_morphology_or', 'ambiguous_morphology_and']";
    $fiveOracleCases = "['common_or', 'rare_anchor_and', 'prefix_fanout', 'ambiguous_morphology_or', 'ambiguous_morphology_and']";
    assert_contains($sixCases, $snapshot, 'the frozen baseline should execute the exact ordered six-case inventory, including formerly-unbound max_valid_or_prefix');
    assert_contains("count(\$cases) === 6", $snapshot, 'the frozen baseline should assert all six executions were captured');
    assert_contains($sixCases, $baselineValidator, 'the reread baseline validator should reject missing, extra, or reordered legacy executions');
    assert_contains('array_keys($cases) !== $caseIds', $baselineValidator, 'the baseline validator should enforce the exact six-case inventory rather than a count alone');
    assert_contains($fiveOracleCases, $oracle, 'the independent SQL oracle should select its exact five migration parity cases');
    assert_true(!str_contains($oracle, 'max_valid_or_prefix'), 'max_valid_or_prefix should be measured against its legacy snapshot without becoming a sixth v4 migration oracle case');
    assert_contains($fiveOracleCases, $oracleValidator, 'every oracle reread should require the exact ordered five-case inventory');
    assert_contains("wp_fts_wc_v4_oracle_query_plan(\$case['query'], \$case['options'])", $oracleValidator, 'every oracle reread should recompute and bind its logical plan');
    assert_contains("(\$case['legacy_execution'] ?? null) !== (\$baselineCase['legacy_execution'] ?? null)", $oracleValidator, 'every oracle case should bind its legacy execution to the authenticated baseline');
    assert_contains('WP_FTS_Search_Budget_Exceeded $caught', $execution, 'legacy execution should catch only the typed search-budget exception');
    assert_contains("\$caught->budget() !== 'candidate rows'", $execution, 'legacy execution should reject every non-candidate budget');
    assert_contains("\$caught->getMessage() !== 'Search request exceeded its candidate rows budget.'", $execution, 'legacy execution should reject message or stage drift');
    assert_contains("'outcome' => 'candidate_rows_budget_rejection'", $execution, 'legacy execution should retain a distinct typed rejection outcome');
    assert_contains("'class' => 'WP_FTS_Search_Budget_Exceeded'", $executionValidator, 'legacy execution validation should require the exact exception class');
    assert_contains("'budget' => 'candidate rows'", $executionValidator, 'legacy execution validation should require the exact budget name');
    assert_contains("'message' => 'Search request exceeded its candidate rows budget.'", $executionValidator, 'legacy execution validation should require the exact exception message');
    assert_true(!str_contains($snapshot . $performance . $execution, 'max_candidate_rows'), 'migration evidence must never raise or override the immutable legacy candidate-row budget');
    assert_contains("\$query = (string) (\$case['query'] ?? '')", $performance, 'the isolated legacy measurement should execute the frozen snapshot query');
    assert_contains("\$options = is_array(\$case['options'] ?? null) ? \$case['options'] : []", $performance, 'the isolated legacy measurement should execute the frozen snapshot options');
    assert_contains('$actualExecution === $expectedExecution', $performance, 'the isolated legacy measurement should pass only on exact frozen-execution equality');
    assert_contains("'status' => \$executionMatchesSnapshot ? 'PASS' : 'FAIL'", $performance, 'an exactly reproduced typed rejection should be a successful measurement');
    assert_contains("(\$artifact['status'] ?? null) === 'PASS'", $finalize, 'generic or synthetic legacy FAIL evidence should fail final migration acceptance');
    assert_contains('$artifactActualExecution === $expectedExecution', $finalize, 'finalization should independently recheck the exact legacy execution');
    assert_contains('wp_fts_wc_migration_oracle_is_valid($oracle, $baseline)', $finalize, 'finalization should authenticate the exact v4 oracle before consuming results or keys');
    assert_contains('"execution_matches_snapshot"=>false', $runner, 'the wrapper should mark timeout, OOM, and process-death evidence as nonmatching');
    assert_contains('relational-fts-baseline-performance-v2', $performance . $runner . $finalize, 'the result-or-rejection measurement union should use only its v2 schema');
    assert_same(2, substr_count($integration, 'relational-fts-migration-evidence-v2'), 'the v2 populated-migration envelope should be required by its only consumer and emitted by its only producer');
    assert_true(!str_contains($integration, 'relational-fts-migration-evidence-v' . '1'), 'the incompatible v1 populated-migration envelope must not remain accepted or emitted');
    assert_contains('138,564 construction-known candidate postings', $acceptance, 'the acceptance contract should retain the measured 50k common-OR fanout');
    assert_contains('relational-fts-migration-evidence-v2', $acceptance, 'the acceptance contract should name the envelope version carrying the exact result-or-rejection union');
    assert_contains('cannot pass the final baseline-completeness gate', $acceptance, 'the acceptance contract should distinguish a reproduced rejection PASS from an arbitrary legacy failure');

    $extracted = '';
    foreach ([
        'wp_fts_wc_legacy_execution',
        'wp_fts_wc_legacy_execution_is_valid',
        'wp_fts_wc_migration_baseline_is_valid',
        'wp_fts_wc_migration_oracle_is_valid',
        'wp_fts_wc_case_definitions',
        'wp_fts_wc_ordered_score_signature',
        'wp_fts_wc_canonical_hash',
        'wp_fts_wc_canonicalize',
        'wp_fts_wc_is_ascii_digits',
        'wp_fts_wc_is_ascii_hex',
        'wp_fts_wc_assert',
    ] as $functionName) {
        $extracted .= wp_fts_wc_contract_function_source($integration, $functionName) . "\n";
    }
    $temporary = sys_get_temp_dir() . '/wp-fts-legacy-execution-' . bin2hex(random_bytes(6));
    mkdir($temporary, 0777, true);
    $script = $temporary . '/probe.php';
    $source = <<<'PHP'
<?php
declare(strict_types=1);

const WP_FTS_WC_IMMUTABLE_BASELINE_SHA = '36a26f4ad1aaef9758922f24677069045c5291ab';

final class WP_FTS_Search_Budget_Exceeded extends RuntimeException
{
    public function __construct(private string $budget)
    {
        parent::__construct("Search request exceeded its {$budget} budget.");
    }

    public function budget(): string
    {
        return $this->budget;
    }
}

final class WP_FTS_Plugin
{
    public static mixed $next = [];
    public static array $lastOptions = [];

    public static function search(string $query, array $options): array
    {
        self::$lastOptions = $options;
        if (self::$next instanceof Throwable) {
            throw self::$next;
        }
        return is_array(self::$next) ? self::$next : [];
    }
}

function wp_fts_wc_v4_oracle_query_plan(string $query, array $options): array
{
    return [
        'groups' => [[['key' => $query, 'rank' => 0]]],
        'prefix' => !empty($options['prefix_matching']) ? ['group_id' => 0, 'key' => $query] : null,
    ];
}
PHP;
    $probe = <<<'PHP'
function probe_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$options = ['limit' => 20, 'prefix_matching' => false];
WP_FTS_Plugin::$next = [['doc_id' => 9, 'score' => 1.25], ['doc_id' => 3, 'score' => 0.5]];
$results = wp_fts_wc_legacy_execution('probe', $options);
probe_assert(WP_FTS_Plugin::$lastOptions === $options, 'legacy execution changed caller options or injected a budget override');
probe_assert(($results['outcome'] ?? null) === 'results' && wp_fts_wc_legacy_execution_is_valid($results), 'nonempty legacy results did not validate');

WP_FTS_Plugin::$next = new WP_FTS_Search_Budget_Exceeded('candidate rows');
$rejection = wp_fts_wc_legacy_execution('probe', $options);
probe_assert(($rejection['outcome'] ?? null) === 'candidate_rows_budget_rejection', 'exact candidate-row rejection was not frozen');
probe_assert(wp_fts_wc_legacy_execution_is_valid($rejection), 'exact candidate-row rejection did not validate');
$mutated = $rejection;
$mutated['error']['message'] .= ' drift';
probe_assert(!wp_fts_wc_legacy_execution_is_valid($mutated), 'message drift should invalidate a frozen rejection');
$mutated = $rejection;
$mutated['result_count'] = '0';
probe_assert(!wp_fts_wc_legacy_execution_is_valid($mutated), 'string result cardinality should not validate');
$mutated = $results;
$mutated['extra'] = true;
probe_assert(!wp_fts_wc_legacy_execution_is_valid($mutated), 'extra execution fields should not validate');
$mutated = $results;
$mutated['ordered_score_results'][0]['doc_id'] = '9';
$mutated['ordered_score_result_hash'] = wp_fts_wc_canonical_hash($mutated['ordered_score_results']);
probe_assert(!wp_fts_wc_legacy_execution_is_valid($mutated), 'string document IDs should not validate after a matching rehash');
$mutated = $results;
$mutated['ordered_score_results'][0]['extra'] = true;
$mutated['ordered_score_result_hash'] = wp_fts_wc_canonical_hash($mutated['ordered_score_results']);
probe_assert(!wp_fts_wc_legacy_execution_is_valid($mutated), 'extra score-row fields should not validate after a matching rehash');
$mutated = $results;
$mutated['ordered_score_results'][0]['score'] = '1.2';
$mutated['ordered_score_result_hash'] = wp_fts_wc_canonical_hash($mutated['ordered_score_results']);
probe_assert(!wp_fts_wc_legacy_execution_is_valid($mutated), 'noncanonical score strings should not validate after a matching rehash');
$mutated = $results;
$mutated['ordered_score_results'][1]['doc_id'] = $mutated['ordered_score_results'][0]['doc_id'];
$mutated['ordered_score_result_hash'] = wp_fts_wc_canonical_hash($mutated['ordered_score_results']);
probe_assert(!wp_fts_wc_legacy_execution_is_valid($mutated), 'duplicate result document IDs should not validate after a matching rehash');

$legacyZip = str_repeat('a', 64);
$legacyManifestHash = str_repeat('b', 64);
$manifest = [
    'source_sha' => WP_FTS_WC_IMMUTABLE_BASELINE_SHA,
    'zip_sha256' => $legacyZip,
    'sha256' => $legacyManifestHash,
    'profile' => ['name' => '2k'],
];
$caseIds = ['common_or', 'max_valid_or_prefix', 'rare_anchor_and', 'prefix_fanout', 'ambiguous_morphology_or', 'ambiguous_morphology_and'];
$definitions = wp_fts_wc_case_definitions($manifest);
$cases = [];
foreach ($caseIds as $caseId) {
    $definition = $definitions[$caseId];
    $caseOptions = $definition['options'];
    unset($caseOptions['explain']);
    $caseOptions['include_snippets'] = false;
    $cases[$caseId] = [
        'query' => $definition['query'],
        'options' => $caseOptions,
        'legacy_execution' => $results,
    ];
}
$baseline = [
    'schema' => 'relational-fts-migration-baseline-v3',
    'source_sha' => WP_FTS_WC_IMMUTABLE_BASELINE_SHA,
    'zip_sha256' => $legacyZip,
    'manifest_sha256' => $legacyManifestHash,
    'indexer_signatures' => ['indexer' => 'legacy'],
    'cases' => $cases,
    'tables' => ['total_bytes' => 1],
];
$baseline50k = $baseline;
$baseline50k['cases']['common_or']['legacy_execution'] = $rejection;
$baseline50k['cases']['max_valid_or_prefix']['legacy_execution'] = $rejection;
$manifest50k = $manifest;
$manifest50k['profile']['name'] = '50k';
$baseline100k = $baseline50k;
$baseline100k['cases']['rare_anchor_and']['legacy_execution'] = $rejection;
$manifest100k = $manifest;
$manifest100k['profile']['name'] = '100k';
$profiles = [
    '2k' => ['manifest' => $manifest, 'baseline' => $baseline],
    '50k' => ['manifest' => $manifest50k, 'baseline' => $baseline50k],
    '100k' => ['manifest' => $manifest100k, 'baseline' => $baseline100k],
];
foreach ($profiles as $profileName => $fixture) {
    probe_assert(wp_fts_wc_migration_baseline_is_valid($fixture['baseline'], $fixture['manifest']), "{$profileName} exact six-case outcome matrix should validate");
    foreach ($caseIds as $caseId) {
        $mutatedBaseline = $fixture['baseline'];
        $outcome = $mutatedBaseline['cases'][$caseId]['legacy_execution']['outcome'];
        $mutatedBaseline['cases'][$caseId]['legacy_execution'] = $outcome === 'results' ? $rejection : $results;
        probe_assert(!wp_fts_wc_migration_baseline_is_valid($mutatedBaseline, $fixture['manifest']), "{$profileName}/{$caseId} should reject the opposite legacy outcome");
    }
}
$wrongLifecycleManifest = $manifest;
$wrongLifecycleManifest['source_sha'] = str_repeat('c', 40);
probe_assert(!wp_fts_wc_migration_baseline_is_valid($baseline, $wrongLifecycleManifest), 'a rebound or wrong-lifecycle manifest should not validate');

$oracleCaseIds = ['common_or', 'rare_anchor_and', 'prefix_fanout', 'ambiguous_morphology_or', 'ambiguous_morphology_and'];
$oracleResults = [['doc_id' => 9, 'score' => '123']];
$oracleCases = [];
foreach ($oracleCaseIds as $caseId) {
    $baselineCase = $baseline['cases'][$caseId];
    $plan = wp_fts_wc_v4_oracle_query_plan($baselineCase['query'], $baselineCase['options']);
    $oracleCases[$caseId] = [
        'query' => $baselineCase['query'],
        'options' => $baselineCase['options'],
        'logical_groups' => $plan['groups'],
        'prefix' => $plan['prefix'],
        'results' => $oracleResults,
        'result_hash' => wp_fts_wc_canonical_hash($oracleResults),
        'legacy_execution' => $baselineCase['legacy_execution'],
    ];
}
$migrationOracle = [
    'schema' => 'relational-fts-v4-migration-oracle-v2',
    'source' => 'v3 logical terms/postings plus canonical wp_posts',
    'scoring_cutover' => [
        'from' => 'legacy_bm25_float',
        'to' => 'v4_quantized_impact_times_integer_rarity',
        'legacy_numeric_score_parity_expected' => false,
    ],
    'v3_doc_freq_mismatches' => 0,
    'v3_queue_rows' => 0,
    'cases' => $oracleCases,
];
probe_assert(wp_fts_wc_migration_oracle_is_valid($migrationOracle, $baseline), 'exact five-case v4 oracle should validate');
$mutatedOracle = $migrationOracle;
unset($mutatedOracle['cases']['ambiguous_morphology_and']);
probe_assert(!wp_fts_wc_migration_oracle_is_valid($mutatedOracle, $baseline), 'missing oracle case should fail');
$mutatedOracle = $migrationOracle;
$mutatedOracle['cases']['common_or']['logical_groups'] = [];
probe_assert(!wp_fts_wc_migration_oracle_is_valid($mutatedOracle, $baseline), 'oracle logical groups should stay bound to the recomputed plan');
$mutatedOracle = $migrationOracle;
$mutatedOracle['cases']['common_or']['legacy_execution'] = $rejection;
probe_assert(!wp_fts_wc_migration_oracle_is_valid($mutatedOracle, $baseline), 'oracle legacy execution should stay bound to the baseline');
$mutatedOracle = $migrationOracle;
$mutatedOracle['cases']['common_or']['results'][0]['score'] = '0123';
$mutatedOracle['cases']['common_or']['result_hash'] = wp_fts_wc_canonical_hash($mutatedOracle['cases']['common_or']['results']);
probe_assert(!wp_fts_wc_migration_oracle_is_valid($mutatedOracle, $baseline), 'rehashed noncanonical oracle score should fail');

$otherBudget = new WP_FTS_Search_Budget_Exceeded('query terms');
WP_FTS_Plugin::$next = $otherBudget;
try {
    wp_fts_wc_legacy_execution('probe', $options);
    throw new RuntimeException('non-candidate budget was accepted');
} catch (WP_FTS_Search_Budget_Exceeded $caught) {
    probe_assert($caught === $otherBudget, 'non-candidate budget was not rethrown unchanged');
}

$arbitrary = new LogicException('arbitrary failure');
WP_FTS_Plugin::$next = $arbitrary;
try {
    wp_fts_wc_legacy_execution('probe', $options);
    throw new RuntimeException('arbitrary failure was accepted');
} catch (LogicException $caught) {
    probe_assert($caught === $arbitrary, 'arbitrary failure was not rethrown unchanged');
}

fwrite(STDOUT, "PASS\n");
PHP;
    file_put_contents($script, $source . "\n" . $extracted . "\n" . $probe);

    try {
        $command = [PHP_BINARY];
        if (php_ini_loaded_file() === false) {
            $command[] = '-n';
        }
        array_push($command, '-d', 'memory_limit=128M', $script);
        $result = test_run_subprocess($command, $root);
        assert_same(0, $result['exit'], 'the extracted exact legacy-execution contract should pass in an isolated process: ' . $result['stdout'] . $result['stderr']);
        assert_same("PASS\n", $result['stdout'], 'the isolated legacy-execution contract should reach its terminal assertion');
    } finally {
        @unlink($script);
        @rmdir($temporary);
    }
});

test_case('relational worst-case composes maximum document work with fair scope alternation', function (): void {
    $root = dirname(__DIR__, 2);
    $integration = (string) file_get_contents(dirname(__DIR__) . '/integration/relational-fts-worst-case.php');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');

    foreach ([
        "'schema' => 'relational-fts-drain-v5'",
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
        "assert_same(0, \$takeover['processed']",
        'count($takeoverQueries) <= 5',
        "assert_same(1, \$ordinary['processed']",
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
    $storage = (string) file_get_contents($root . '/src/MysqlStorage.php');
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
    $runner = (string) file_get_contents($root . '/tools/run-relational-fts-worst-case.sh');
    $acceptance = (string) file_get_contents($root . '/docs/relational-search-acceptance.md');
    $mysqlStorage = (string) file_get_contents($root . '/src/MysqlStorage.php');
    $plugin = (string) file_get_contents($root . '/src/Plugin.php');

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
        'column definitions do not exactly match the production schema contract',
        'indexes do not exactly match the production schema contract',
        'oracle_complete_slice_membership_and_order',
        'migration_physical_disk_sample_count',
        'migration_physical_fts_peak_ratio',
        'migration_physical_disk_raw_samples_hash',
        'max_valid_setup_artifact',
        ". str_repeat('x', \$contentBytes - strlen(\$visibleContent . \$commentOpen . \$commentClose))",
        'max_connections must be exactly 24',
        'PHP memory_limit must be exactly 128 MiB',
    ] as $required) {
        assert_contains($required, $integration, "integration proof should retain hard conditioning/evidence contract: {$required}");
    }
    assert_contains("'started_monotonic_ns' => \$startedNs", $integration, 'concurrent workers must retain their monotonic start inside the shared window');
    assert_contains("'finished_monotonic_ns' => \$finishedNs", $integration, 'concurrent workers must retain their monotonic finish inside the shared window');
    assert_contains('wp_fts_wc_elapsed_ms($batchStarted)', $integration, 'concurrent writer batches must retain a separate high-resolution timer');

    $indexingPrepare = strpos($runner, 'run_php_phase indexing-prepare');
    $timedIndex = strpos($runner, 'INDEX_STARTED=', $indexingPrepare === false ? 0 : $indexingPrepare);
    $reindexDrain = strpos($runner, 'run_php_phase reindex-drain', $timedIndex === false ? 0 : $timedIndex);
    $validation = strpos($runner, 'run_php_phase validate', $timedIndex === false ? 0 : $timedIndex);
    assert_true(is_int($indexingPrepare) && is_int($timedIndex) && is_int($reindexDrain) && is_int($validation), 'runner should retain the asynchronous forced full-rebuild lifecycle');
    assert_true($indexingPrepare < $timedIndex && $timedIndex < $reindexDrain && $reindexDrain < $validation, 'every timed current reindex must be invalidated, enqueued, drained in bounded production passes, and then verified');
    assert_contains('relational-fts-indexing-prepare-v2', $runner, 'the runner should consume the exact preparation schema emitted by the integration phase');
    assert_true(!str_contains($runner, "--post_status=publish,draft,pending,future,private \\\n    --batch_size"), 'the asynchronous reindex runner must not pass the rejected legacy batch-size option');

    $migrationOracle = strpos($runner, 'run_php_phase migration-oracle');
    $diskStart = strpos($runner, "\nstart_migration_disk_monitor\n", $migrationOracle === false ? 0 : $migrationOracle);
    $migrationFinalize = strpos($runner, 'run_php_phase migration-finalize', $diskStart === false ? 0 : $diskStart);
    $diskStop = strpos($runner, "\nstop_migration_disk_monitor\n", $migrationFinalize === false ? 0 : $migrationFinalize);
    assert_true(is_int($migrationOracle) && is_int($diskStart) && is_int($migrationFinalize) && is_int($diskStop), 'runner should retain the physical migration disk monitor lifecycle');
    assert_true($migrationOracle < $diskStart && $diskStart < $migrationFinalize && $migrationFinalize < $diskStop, 'physical disk monitoring must surround every destructive migration failpoint and finalization');
    foreach (['migration-disk-samples.tsv', 'sleep 0.25', 'peak_fts_bytes', 'migration_physical_fts_peak_ratio'] as $required) {
        assert_contains($required, $runner, "physical migration disk evidence should retain {$required}");
    }

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
    assert_true(!str_contains($documentsSchema, 'doc_len'), 'production v4 documents schema must not persist a scalar document length');
    assert_contains("'columns' => ['post_id', 'primary_lang', 'content_hash', 'snippet_text', 'indexed_at']", $mysqlStorage, 'schema verification must retain the exact production document columns');
    assert_contains('foreach (array_diff($physical[\'columns\'], $contract[\'columns\']) as $column)', $mysqlStorage, 'schema verification must reject every unexpected production column, including a leftover document length');
    assert_contains("INSERT IGNORE INTO {\$table} (option_name,option_value,autoload)", $plugin, 'the uncontended worker lease must remain one atomic option-table statement');
    assert_contains('WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION => []', $plugin, 'the bounded custom-field configuration should be initialized by the request-option migration');
    assert_contains("UPDATE `{\$table}` SET autoload = 'yes' WHERE option_name IN", $plugin, 'the request-option migration should autoload every bounded search input before worker and visitor requests');
    assert_contains('PRELOADED_POST_LANGUAGE_OPTION', $plugin, 'the dependency snapshot must carry authoritative language absence through analyzer callbacks');

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
    $nearLimitTerms = (new WP_FTS_Analyzer())->analyze_content($nearLimitContent, ['lang' => 'en']);
    assert_same(1, count($nearLimitTerms), 'near-limit valid source should analyze one bounded visible term');
    assert_same('en', $nearLimitTerms[0]['lang'] ?? null, 'near-limit valid source should preserve its explicit analyzer language');

    foreach (['all 8,192', 'dedicated 512-MiB InnoDB relation', 'every process records its own elapsed time', 'empty gate list', 'leftover `term_hash` column/index, scalar `doc_len`'] as $required) {
        assert_contains($required, $acceptance, "acceptance writeup should retain non-synthetic worst-case requirement: {$required}");
    }
    record_check('relational hard conditioning and evidence contract', 34);
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
        'function wp_fts_wc_populated_scope_index_upgrade(',
        'function wp_fts_wc_scope_ddl_writer()',
        "'scope_index_upgrade_innodb_core_clones'",
        "'scope_index_upgrade_v7_fixture_cardinality'",
        "'scope_index_upgrade_exact_ddl'",
        "'scope_index_upgrade_ownership_before_ddl'",
        "'scope_index_upgrade_schema_publication_last'",
        "'scope_index_upgrade_performance_schema_attribution'",
        "'scope_index_upgrade_concurrent_writes'",
        "'scope_index_upgrade_write_overlap'",
        "'scope_index_upgrade_write_duration_ms'",
        "'scope_index_upgrade_storage_delta'",
        "'scope_index_upgrade_readiness_preserved'",
        "'scope_index_upgrade_work_preserved'",
        "'relational-fts-populated-scope-index-upgrade-v2'",
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
        "'relational-fts-scope-expansion-v3'",
        '6600',
        'SORT_ROWS',
    ] as $required) {
        assert_contains($required, $integration, "scope proof should retain measured anti-join evidence: {$required}");
    }
    assert_contains('old-posting-frontier|scope-ddl-writer|scope-proof', $runner, 'the populated scope-index DDL phase should retain its 1,800-second external kill');
    assert_contains('scope_ddl_writer_pids', $runner, 'the populated scope-index proof should launch separate concurrent core-table writers');
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
        'introduced in schema v8 and required by schema v9',
        '`wp_fts_term_object(term_taxonomy_id, object_id)`',
        '`wp_fts_type_status_id(post_type, post_status, ID)`',
        "WordPress's canonical posts and relationships tables",
        '100,001 posts and 300,001 relationships',
        'v7-to-v9 plugin upgrade',
        'regions plus one explicit cursor sentinel',
        'exactly the two canonical `CREATE INDEX`',
        'Four separate WordPress processes synchronize',
        'server-timer interval that overlaps',
        '180,000 ms total client ceiling',
        'positive index-byte delta',
        'Unit failpoints separately reject a same-name collision',
        'steal the writer lease after the',
        'genuinely non-covering',
        '1,000 full member pages plus one exhaustion page',
        'proportional to affected membership',
        'two statements, not 1,002 raw-ID scans',
        'exactly 32 `wp_fts_type_status_id` branches',
        'at most 6,600 rows',
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
        'relational-fts-cold-ready-request-v1',
        "run_php_phase cold-ready-request",
        "'cold_ready_upgrade_v6_seed_shape'",
        "'cold_ready_upgrade_profile_hash_preserved'",
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
        'schema-v6 state',
        'exactly these seven bounded request inputs',
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
    foreach (['runtime_analyzer_pack_statuses', 'sandbox_demo_analyzer_pack_statuses', 'WP_FTS_Analyzer_Pack_Validator', 'validate_pack'] as $forbidden) {
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
    foreach (['new WP_Query()', "'cache_results' => \$cacheResults", "'update_post_term_cache' => \$updateTermCache", "'update_post_meta_cache' => \$updateMetaCache"] as $required) {
        assert_contains($required, $queryRunner, "the cache proof must run the complete main WP_Query lifecycle: {$required}");
    }
    $stockUnscopedRunner = wp_fts_wc_contract_function_source($integration, 'wp_fts_wc_run_stock_unscoped_frontend_wp_query');
    foreach (["'s' => 'commonalpha commonbeta commongamma'", "'cache_results' => false", "'update_post_term_cache' => false", "'update_post_meta_cache' => false"] as $required) {
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
        "foreach (['term_count', 'complete_dictionary_scans', 'indexed_range_reads'] as \$field)",
        "array_key_exists('run_count', \$freshExpected['measurement'])",
        "(\$freshMeasurement['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= \$freshDeltaCeiling",
        "(\$freshMeasurement['rss_peak_delta_bytes'] ?? PHP_INT_MAX) <= \$freshDeltaCeiling",
        "(\$freshProcessEvidence['php_peak_bytes'] ?? PHP_INT_MAX) <= 134217728",
        "(\$freshProcessEvidence['rss_peak_bytes'] ?? PHP_INT_MAX) <= 134217728",
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
        'WP_FTS_ChineseJiebaSegmenter::SOURCE_SHA256',
        'WP_FTS_ChineseJiebaSegmenter::LOOKUP_SHA256',
        "'query_bytes' => strlen(\$maximumQuery)",
        "'query_sha256' => hash('sha256', \$maximumQuery)",
        "'distinct_han_prefixes' => count(\$queryCharacters)",
        "\$measurement['complete_dictionary_scans'] === 0",
        "\$measurement['indexed_range_reads'] === 0",
        "\$measurement['cached_candidate_delta'] === 0",
        "(\$measurement['rejected_storage_search_calls'] ?? null) === 0",
        "(float) (\$measurement['elapsed_seconds'] ?? INF) < 1.0",
        "(int) (\$measurement['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= 4 * 1024 * 1024",
        "(\$measurement['producer_token_count'] ?? null) === 13",
        "(\$measurement['producer_indexed_range_reads'] ?? null) === 0",
        "(\$measurement['accepted_group_count'] ?? null) === 12",
        "(\$measurement['accepted_alternative_count'] ?? null) === 12",
        "\$tokens = \$this->cjkTokenizerAcceptsProducerLimit",
        "? (\$this->cjkTokenizer)(\$run, \$canonicalLanguage, \$maxTerms + 1)",
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
        'pull-request-mysql57-smoke:',
        '- mariadb-10.11',
        '- mysql-8.0',
        '--engine=mysql-5.7',
        '--profile=50k',
        '--profile=100k',
        'timeout-minutes: 360',
        'group: relational-fts-${{ github.workflow }}-${{ github.event.pull_request.number }}',
        'cancel-in-progress: true',
        'if: always()',
        'if-no-files-found: error',
        'include-hidden-files: true',
        'runs-on: ubuntu-24.04',
        "php-version: '8.4.5'",
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
        'maximum_fanout_evidence',
        'maximum_fanout',
        'complete_pinned_cache_evidence',
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
    $pr50Start = strpos($workflow, '  pull-request-50k:');
    $pr100Start = strpos($workflow, '  pull-request-100k:');
    $mysql57Start = strpos($workflow, '  pull-request-mysql57-smoke:');
    assert_true(is_int($pr50Start) && is_int($pr100Start) && is_int($mysql57Start), 'required pull-request jobs should remain independently inspectable');
    $pr50 = substr($workflow, $pr50Start, $pr100Start - $pr50Start);
    $pr100 = substr($workflow, $pr100Start, $mysql57Start - $pr100Start);
    $mysql57 = substr($workflow, $mysql57Start);
    foreach (['name: 50k / ${{ matrix.engine }}', '- mariadb-10.11', '- mysql-8.0', "--engine='\${{ matrix.engine }}'", '--profile=50k', '--output=".context/evidence-${{ matrix.engine }}-50k.json"', 'name: relational-fts-${{ matrix.engine }}-50k', '.context/evidence-${{ matrix.engine }}-50k.json*', 'if: always()', 'if-no-files-found: error', 'include-hidden-files: true'] as $required) {
        assert_contains($required, $pr50, "the 50k pull-request matrix should retain {$required}");
    }
    assert_same(1, substr_count($pr50, '- mariadb-10.11'), 'the 50k matrix must contain exactly one MariaDB 10.11 lane');
    assert_same(1, substr_count($pr50, '- mysql-8.0'), 'the 50k matrix must contain exactly one MySQL 8.0 lane');
    assert_same(2, substr_count($pr50, "\n          - "), 'the 50k matrix must contain exactly its two supported engines');
    assert_same(1, substr_count($pr50, "--engine='\${{ matrix.engine }}'"), 'both 50k engines must execute one shared proof command');
    assert_true(!str_contains($pr50, '--engine=mariadb-10.11'), 'the 50k matrix must not route both entries through a MariaDB-only proof path');
    foreach (['name: 100k / ${{ matrix.engine }}', '- mariadb-10.11', '- mysql-8.0', "--engine='\${{ matrix.engine }}'", '--profile=100k', '--output=".context/evidence-${{ matrix.engine }}-100k.json"', 'name: relational-fts-${{ matrix.engine }}-100k', '.context/evidence-${{ matrix.engine }}-100k.json*', 'Prove indexed Jieba multi-run work stays bounded', 'memory_limit=128M', 'JIEBA_EVIDENCE_DIR: ${{ runner.temp }}/relational-fts-jieba', '.context/jieba-*', 'if: always()', 'if-no-files-found: error', 'include-hidden-files: true'] as $required) {
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
    foreach (['--engine=mysql-5.7', '--profile=2k', 'if: always()', 'if-no-files-found: error', 'include-hidden-files: true'] as $required) {
        assert_contains($required, $mysql57, "the MySQL 5.7 pull-request smoke should retain {$required}");
    }
    assert_same(1, substr_count($mysql57, '--engine=mysql-5.7'), 'the compatibility job must contain exactly one MySQL 5.7 lane');
    foreach (['50k' => $pr50, '100k' => $pr100, 'MySQL 5.7' => $mysql57] as $label => $databaseJob) {
        assert_true(!str_contains($databaseJob, '--allow-dirty'), "the {$label} pull-request database job must require clean acceptance evidence");
    }
    $permissionsStart = strpos($workflow, "\npermissions:");
    $triggers = is_int($permissionsStart) ? substr($workflow, 0, $permissionsStart) : $workflow;
    assert_contains('pull_request:', $triggers, 'the five expensive database lanes should run for relevant pull requests');
    foreach (['components/full-text-search/tools/**', 'indexer/.distignore', 'indexer/config/**'] as $path) {
        assert_contains("- '{$path}'", $triggers, "the acceptance workflow should run when direct runner input {$path} changes");
    }
    foreach (['workflow_dispatch:', 'schedule:', 'push:'] as $unrequestedTrigger) {
        assert_true(!str_contains($triggers, $unrequestedTrigger), "the acceptance workflow should not add the unrequested {$unrequestedTrigger} trigger");
    }
    assert_true(!str_contains($workflow, 'continue-on-error'), 'real-database workflow must not tolerate failed proof commands');
    assert_same(4, substr_count($workflow, 'persist-credentials: false'), 'all database and PHP-compatibility jobs should remove their checkout credential');
    assert_same(3, substr_count($workflow, 'tools: composer:2.9.8'), 'each of the three jobs covering five database lanes should use the same exact Composer toolchain');
    assert_same(3, substr_count($workflow, 'timeout-minutes: 345'), 'each proof step should leave fifteen minutes for its required failure-artifact upload');
    assert_same(4, substr_count($workflow, 'uses: actions/upload-artifact@v4'), 'each database or PHP-compatibility job should upload its evidence');
    assert_same(3, substr_count($workflow, 'include-hidden-files: true'), 'each upload must include the hidden .context evidence path');
    assert_contains('Relational Search Worst-Case Acceptance', $testing, 'testing guide should document the real acceptance lane');
    $cleanEvidenceStart = strpos($testing, "Required clean-source evidence:\n");
    $cleanEvidenceEnd = strpos($testing, "\nDo not add this multi-hour real-database lane", is_int($cleanEvidenceStart) ? $cleanEvidenceStart : 0);
    assert_true(
        is_int($cleanEvidenceStart) && is_int($cleanEvidenceEnd) && $cleanEvidenceEnd > $cleanEvidenceStart,
        'testing guide clean-source commands should remain independently inspectable'
    );
    $cleanEvidence = is_int($cleanEvidenceStart) && is_int($cleanEvidenceEnd)
        ? substr($testing, $cleanEvidenceStart, $cleanEvidenceEnd - $cleanEvidenceStart)
        : '';
    assert_same(5, substr_count($cleanEvidence, 'tools/run-relational-fts-worst-case.sh'), 'testing guide should list exactly all five clean database lanes');
    foreach ([
        "--engine=mysql-5.7 \\\n  --profile=2k",
        "--engine=mariadb-10.11 \\\n  --profile=50k",
        "--engine=mysql-8.0 \\\n  --profile=50k",
        "--engine=mariadb-10.11 \\\n  --profile=100k",
        "--engine=mysql-8.0 \\\n  --profile=100k",
    ] as $requiredLane) {
        assert_contains($requiredLane, $cleanEvidence, "testing guide should retain clean lane: {$requiredLane}");
    }
    assert_contains('100,000', $acceptance, 'acceptance contract should retain the supported boundary corpus');
    foreach (['`100k/mysql-8.0`', '`mysql80-100k`', 'one two-engine matrix', 'identical structural, performance, memory, concurrency,', 'migration, evidence, finalization, and failure-artifact requirements', 'all five pull-request database lanes', '--engine=mysql-8.0 --profile=100k'] as $required) {
        assert_contains($required, $acceptance, "acceptance contract should retain the MySQL 8.0 boundary requirement: {$required}");
    }
    assert_contains('A missing dependency, `SKIP`, `PENDING`, timeout, OOM', $acceptance, 'acceptance contract should reject clean skips and incomplete evidence');
    assert_contains('not a universal hosting SLA', $acceptance, 'absolute CI latency should be qualified by its unthrottled host storage');
});
