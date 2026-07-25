<?php
declare(strict_types=1);

/**
 * Destructive real-MySQL/MariaDB proof for the old-posting transaction frontier.
 *
 * Required environment:
 *   WP_FTS_FRONTIER_HOST, WP_FTS_FRONTIER_PORT, WP_FTS_FRONTIER_USER,
 *   WP_FTS_FRONTIER_PASSWORD, WP_FTS_FRONTIER_DATABASE,
 *   WP_FTS_FRONTIER_HARNESS_SHA256, WP_FTS_FRONTIER_SOURCE_SHA,
 *   WP_FTS_FRONTIER_ZIP_SHA256, WP_FTS_FRONTIER_ENGINE
 */

$expectedHarnessSha = wp_fts_frontier_env('WP_FTS_FRONTIER_HARNESS_SHA256');
$actualHarnessSha = hash_file('sha256', __FILE__);
if (!is_string($actualHarnessSha) || !hash_equals($expectedHarnessSha, $actualHarnessSha)) {
    throw new RuntimeException('The old-posting frontier harness hash does not match the mounted source.');
}
$sourceSha = wp_fts_frontier_env('WP_FTS_FRONTIER_SOURCE_SHA');
$zipSha = wp_fts_frontier_env('WP_FTS_FRONTIER_ZIP_SHA256');
$expectedEngine = wp_fts_frontier_env('WP_FTS_FRONTIER_ENGINE');
if (
    strlen($sourceSha) !== 40
    || !wp_fts_frontier_is_ascii_hex($sourceSha)
    || strtolower($sourceSha) !== $sourceSha
    || !wp_fts_frontier_is_sha256($zipSha)
) {
    throw new RuntimeException('The frontier source commit and installed ZIP hashes are malformed.');
}
$pluginPath = getenv('WP_FTS_FRONTIER_PLUGIN_PATH');
if (is_string($pluginPath) && trim($pluginPath) !== '') {
    require_once rtrim($pluginPath, '/\\') . '/src/bootstrap.php';
} else {
    require_once dirname(__DIR__, 3) . '/components/full-text-search/src/bootstrap.php';
    require_once dirname(__DIR__, 2) . '/src/IndexQueue.php';
    require_once dirname(__DIR__, 2) . '/src/RelationalStorage.php';
}

if (!extension_loaded('mysqli')) {
    throw new RuntimeException('The old-posting frontier proof requires mysqli.');
}

$host = wp_fts_frontier_env('WP_FTS_FRONTIER_HOST');
$port = (int) wp_fts_frontier_env('WP_FTS_FRONTIER_PORT');
$user = wp_fts_frontier_env('WP_FTS_FRONTIER_USER');
$password = wp_fts_frontier_env('WP_FTS_FRONTIER_PASSWORD');
$database = wp_fts_frontier_env('WP_FTS_FRONTIER_DATABASE');
$mysqli = mysqli_init();
if (!$mysqli instanceof mysqli || !$mysqli->real_connect($host, $user, $password, $database, $port)) {
    throw new RuntimeException('Could not connect to the disposable frontier database.');
}
$mysqli->set_charset('utf8mb4');
$connectionId = (int) $mysqli->thread_id;
$prefix = 'wpfts_frontier_' . getmypid() . '_';
$db = new WP_FTS_Frontier_WPDB($mysqli, $prefix);
$storage = new WP_FTS_Relational_Storage($db, $prefix);
$postIds = range(700001, 700007);
$lexicalTermCountPerPost = WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_TERMS;
$surfaceTermCountPerPost = WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES;
$termCountPerPost = $lexicalTermCountPerPost + $surfaceTermCountPerPost;
wp_fts_frontier_assert($lexicalTermCountPerPost === 4096, 'The lexical document frontier drifted from 4,096.');
wp_fts_frontier_assert($surfaceTermCountPerPost === 4096, 'The normalized-surface document frontier drifted from 4,096.');
wp_fts_frontier_assert($termCountPerPost === WP_FTS_Relational_Storage::MAX_DOCUMENT_POSTINGS, 'The document posting frontier is not the lexical-plus-surface envelope.');
wp_fts_frontier_assert(WP_FTS_Relational_Storage::MAX_BATCH_TERMS === 8192, 'The batch dictionary frontier drifted from 8,192 identities.');
wp_fts_frontier_assert(WP_FTS_Relational_Storage::MAX_BATCH_POSTINGS === 50000, 'The batch mutation frontier drifted from 50,000 postings.');
wp_fts_frontier_assert(WP_FTS_Relational_Storage::MAX_BATCH_POSTINGS + 1 === 50001, 'The old-posting read frontier drifted from the exact 50,001-row limit-plus-one bound.');
wp_fts_frontier_assert(WP_FTS_Relational_Storage::MAX_BATCH_POSTINGS + WP_FTS_Relational_Storage::MAX_BATCH_DOCUMENTS === 50100, 'The combined delete materialization barrier drifted from 50,100 rows.');
$targetPostingCount = count($postIds) * $termCountPerPost;
$decoyPostingCount = 100000;
$decoyTermId = $targetPostingCount + 1;
$termsTable = $prefix . 'fts_terms';
$postingsTable = $prefix . 'fts_postings';
$documentsTable = $prefix . 'fts_documents';
$workTable = $prefix . 'fts_work';
$started = hrtime(true);
$evidence = null;
$primaryError = null;
$cleanupError = null;
$cleanupEvidence = [];

try {
    wp_fts_frontier_create_schema($db, $termsTable, $postingsTable, $documentsTable, $workTable);
    $seedStarted = hrtime(true);
    wp_fts_frontier_seed_target_fixture(
        $mysqli,
        $termsTable,
        $postingsTable,
        $documentsTable,
        $postIds,
        $termCountPerPost,
        $lexicalTermCountPerPost
    );
    // A substantial lower-key decoy range prevents an all-index scan from
    // looking cheap merely because the requested seven documents are the whole
    // table. The production query must select seven disjoint ranges instead.
    wp_fts_frontier_query(
        $mysqli,
        "INSERT INTO {$termsTable} (term_id,lang,kind,term,doc_freq) VALUES "
        . "({$decoyTermId},'en',0,'frontier-plan-decoy',{$decoyPostingCount})"
    );
    for ($firstDecoyPostId = 1; $firstDecoyPostId <= $decoyPostingCount; $firstDecoyPostId += 5000) {
        $decoyRows = [];
        $lastDecoyPostId = min($decoyPostingCount, $firstDecoyPostId + 4999);
        for ($decoyPostId = $firstDecoyPostId; $decoyPostId <= $lastDecoyPostId; $decoyPostId++) {
            $decoyRows[] = "({$decoyTermId},{$decoyPostId},1)";
        }
        wp_fts_frontier_query(
            $mysqli,
            "INSERT INTO {$postingsTable} (term_id,post_id,impact) VALUES " . implode(',', $decoyRows)
        );
    }
    wp_fts_frontier_query($mysqli, "ANALYZE TABLE {$postingsTable}");
    $seedMs = wp_fts_frontier_elapsed_ms($seedStarted);

    $expectedPostings = $targetPostingCount;
    wp_fts_frontier_assert(
        (int) $db->get_var("SELECT COUNT(*) FROM {$postingsTable}") === $expectedPostings + $decoyPostingCount,
        'The 7 x 8,192 old-posting fixture is incomplete.'
    );
    wp_fts_frontier_assert(
        (int) $db->get_var("SELECT COUNT(*) FROM {$termsTable}") === $expectedPostings + 1,
        'The disjoint dictionary fixture is incomplete.'
    );
    $targetKinds = $db->get_results(
        "SELECT kind, COUNT(*) AS term_count FROM {$termsTable}"
        . " WHERE term_id <= {$targetPostingCount} GROUP BY kind ORDER BY kind"
    );
    $targetKindCounts = [];
    foreach ($targetKinds as $row) {
        $targetKindCounts[(int) ($row->kind ?? -1)] = (int) ($row->term_count ?? -1);
    }
    wp_fts_frontier_assert($targetKindCounts === [
        0 => count($postIds) * $lexicalTermCountPerPost,
        1 => count($postIds) * $surfaceTermCountPerPost,
    ], 'The old-posting fixture does not contain the exact lexical and normalized-surface split.');

    // A separate exact-bound pass proves that the decrement is cheap even when
    // every affected term survives. This catches dictionary plans that happen
    // to look bounded only when df=1 rows immediately retire.
    $sharedTargetPostIds = range(710001, 710006);
    $sharedSurvivorPostIds = range(720001, 720006);
    $sharedTargetLastPostId = $sharedTargetPostIds[array_key_last($sharedTargetPostIds)];
    $sharedSurvivorLastPostId = $sharedSurvivorPostIds[array_key_last($sharedSurvivorPostIds)];
    $sharedFirstTermId = $decoyTermId + 1;
    $sharedTermCount = count($sharedTargetPostIds) * $termCountPerPost;
    wp_fts_frontier_assert($sharedTermCount === 49152, 'The survivor fixture drifted from the exact 49,152-term pass.');
    $sharedLastTermId = $sharedFirstTermId + $sharedTermCount - 1;
    $sharedSeedStarted = hrtime(true);
    wp_fts_frontier_seed_shared_fixture(
        $mysqli,
        $termsTable,
        $postingsTable,
        $documentsTable,
        $sharedTargetPostIds,
        $sharedSurvivorPostIds,
        $sharedFirstTermId,
        $termCountPerPost,
        $lexicalTermCountPerPost
    );
    $sharedSeedMs = wp_fts_frontier_elapsed_ms($sharedSeedStarted);
    wp_fts_frontier_assert(
        (int) $db->get_var("SELECT COUNT(*) FROM {$termsTable} WHERE term_id BETWEEN {$sharedFirstTermId} AND {$sharedLastTermId}") === $sharedTermCount,
        'The shared-term survivor fixture is missing dictionary rows.'
    );
    wp_fts_frontier_assert(
        (int) $db->get_var("SELECT COUNT(*) FROM {$postingsTable} WHERE term_id BETWEEN {$sharedFirstTermId} AND {$sharedLastTermId}") === 2 * $sharedTermCount,
        'The shared-term survivor fixture is missing postings.'
    );
    $sharedKinds = $db->get_results(
        "SELECT kind, COUNT(*) AS term_count FROM {$termsTable}"
        . " WHERE term_id BETWEEN {$sharedFirstTermId} AND {$sharedLastTermId}"
        . ' GROUP BY kind ORDER BY kind'
    );
    $sharedKindCounts = [];
    foreach ($sharedKinds as $row) {
        $sharedKindCounts[(int) ($row->kind ?? -1)] = (int) ($row->term_count ?? -1);
    }
    wp_fts_frontier_assert($sharedKindCounts === [
        0 => count($sharedTargetPostIds) * $lexicalTermCountPerPost,
        1 => count($sharedTargetPostIds) * $surfaceTermCountPerPost,
    ], 'The survivor fixture does not contain the exact lexical and normalized-surface split.');

    $sharedPassStarted = hrtime(true);
    $beforeSharedPlan = $db->statement_marker();
    $sharedPlan = $storage->plan_prepared_replacement(array_fill_keys($sharedTargetPostIds, 0));
    $sharedPlanStatements = $db->statements_since($beforeSharedPlan);
    wp_fts_frontier_assert(count($sharedPlanStatements) === 1, 'The survivor pass must use exactly one frontier statement.');
    wp_fts_frontier_assert($sharedPlan->admitted_post_ids === $sharedTargetPostIds, 'The survivor pass did not admit its exact six-document prefix.');
    wp_fts_frontier_assert($sharedPlan->deferred_post_ids === [], 'The survivor pass unexpectedly deferred bounded work.');
    wp_fts_frontier_assert($sharedPlan->scanned_old_postings === 49152, 'The survivor pass did not scan its exact 49,152 old postings.');
    wp_fts_frontier_assert((int) ($sharedPlanStatements[0]['row_count'] ?? -1) === 6, 'The survivor frontier did not return exactly six per-document aggregates.');
    wp_fts_frontier_assert($sharedPlan->posting_mutations === $sharedTermCount, 'The survivor pass measured the wrong old-posting count.');
    $beforeSharedWrite = $db->statement_marker();
    $sharedResult = $storage->replace_prepared_documents([], $sharedTargetPostIds, $sharedPlan);
    $sharedWriteEvidence = wp_fts_frontier_write_evidence($db->statements_since($beforeSharedWrite));
    wp_fts_frontier_assert((int) ($sharedResult['old_postings'] ?? -1) === $sharedTermCount, 'The survivor pass consumed the wrong posting frontier.');
    $sharedPass = [
        'admitted_documents' => count($sharedTargetPostIds),
        'deferred_documents' => 0,
        'frontier_rows_returned' => (int) ($sharedPlanStatements[0]['row_count'] ?? -1),
        'frontier_rows_scanned' => $sharedPlan->scanned_old_postings,
        'posting_mutations' => $sharedPlan->posting_mutations,
        'frontier_statement_count' => count($sharedPlanStatements),
        'expected_decrement_rows_affected' => $sharedTermCount,
        'expected_delete_rows_affected' => $sharedTermCount + count($sharedTargetPostIds),
        ...$sharedWriteEvidence,
        'elapsed_ms' => wp_fts_frontier_elapsed_ms($sharedPassStarted),
    ];
    $sharedSurvivorState = [
        'target_postings' => (int) $db->get_var(
            "SELECT COUNT(*) FROM {$postingsTable} WHERE post_id BETWEEN {$sharedTargetPostIds[0]} AND {$sharedTargetLastPostId}"
        ),
        'survivor_postings' => (int) $db->get_var(
            "SELECT COUNT(*) FROM {$postingsTable} WHERE post_id BETWEEN {$sharedSurvivorPostIds[0]} AND {$sharedSurvivorLastPostId}"
        ),
        'target_documents' => (int) $db->get_var(
            "SELECT COUNT(*) FROM {$documentsTable} WHERE post_id BETWEEN {$sharedTargetPostIds[0]} AND {$sharedTargetLastPostId}"
        ),
        'survivor_documents' => (int) $db->get_var(
            "SELECT COUNT(*) FROM {$documentsTable} WHERE post_id BETWEEN {$sharedSurvivorPostIds[0]} AND {$sharedSurvivorLastPostId}"
        ),
        'surviving_terms' => (int) $db->get_var(
            "SELECT COUNT(*) FROM {$termsTable} WHERE term_id BETWEEN {$sharedFirstTermId} AND {$sharedLastTermId} AND doc_freq = 1"
        ),
        'bad_document_frequencies' => (int) $db->get_var(
            "SELECT COUNT(*) FROM {$termsTable} WHERE term_id BETWEEN {$sharedFirstTermId} AND {$sharedLastTermId} AND doc_freq <> 1"
        ),
    ];
    wp_fts_frontier_assert($sharedSurvivorState === [
        'target_postings' => 0,
        'survivor_postings' => $sharedTermCount,
        'target_documents' => 0,
        'survivor_documents' => count($sharedSurvivorPostIds),
        'surviving_terms' => $sharedTermCount,
        'bad_document_frequencies' => 0,
    ], 'The df=2 to df=1 survivor pass produced an incorrect final state.');

    wp_fts_frontier_query(
        $mysqli,
        "DELETE FROM {$postingsTable} WHERE post_id BETWEEN {$sharedSurvivorPostIds[0]} AND {$sharedSurvivorLastPostId}"
    );
    wp_fts_frontier_query(
        $mysqli,
        "DELETE FROM {$termsTable} WHERE term_id BETWEEN {$sharedFirstTermId} AND {$sharedLastTermId}"
    );
    wp_fts_frontier_query(
        $mysqli,
        "DELETE FROM {$documentsTable} WHERE post_id BETWEEN {$sharedSurvivorPostIds[0]} AND {$sharedSurvivorLastPostId}"
    );
    wp_fts_frontier_assert(
        (int) $db->get_var("SELECT COUNT(*) FROM {$termsTable} WHERE term_id BETWEEN {$sharedFirstTermId} AND {$sharedLastTermId}") === 0,
        'The survivor subfixture was not isolated from the retirement proof.'
    );
    wp_fts_frontier_query($mysqli, "ANALYZE TABLE {$postingsTable}");

    $remaining = $postIds;
    $passes = [];
    $firstPlanSql = '';
    $planEvidence = [];
    while ($remaining !== []) {
        $beforePlan = $db->statement_marker();
        $passStarted = hrtime(true);
        $plan = $storage->plan_prepared_replacement(array_fill_keys($remaining, 0));
        $planStatements = $db->statements_since($beforePlan);
        wp_fts_frontier_assert(count($planStatements) === 1, 'Each pass must use exactly one frontier statement.');
        wp_fts_frontier_assert($planStatements[0]['method'] === 'get_results', 'The frontier statement must be one aggregate read.');
        if ($firstPlanSql === '') {
            $firstPlanSql = $planStatements[0]['sql'];
            $planEvidence = wp_fts_frontier_explain($mysqli, $firstPlanSql);
        }
        $beforeWrite = $db->statement_marker();
        $result = $storage->replace_prepared_documents([], $plan->admitted_post_ids, $plan);
        $writeStatements = $db->statements_since($beforeWrite);
        $writeEvidence = wp_fts_frontier_write_evidence($writeStatements);
        wp_fts_frontier_assert($plan->posting_mutations <= WP_FTS_Relational_Storage::MAX_BATCH_POSTINGS, 'A pass exceeded the posting mutation ceiling.');
        wp_fts_frontier_assert($plan->admitted_post_ids !== [], 'A valid 8,192-row document must always make progress.');
        wp_fts_frontier_assert(
            (int) ($result['old_postings'] ?? -1) === count($plan->admitted_post_ids) * $termCountPerPost,
            'Storage did not consume the exact measured old-posting count.'
        );
        $passes[] = [
            'admitted_documents' => count($plan->admitted_post_ids),
            'deferred_documents' => count($plan->deferred_post_ids),
            'frontier_rows_returned' => (int) ($planStatements[0]['row_count'] ?? -1),
            'frontier_rows_scanned' => $plan->scanned_old_postings,
            'posting_mutations' => $plan->posting_mutations,
            'frontier_statement_count' => count($planStatements),
            'expected_decrement_rows_affected' => $plan->posting_mutations,
            'expected_delete_rows_affected' => (2 * $plan->posting_mutations) + count($plan->admitted_post_ids),
            ...$writeEvidence,
            'elapsed_ms' => wp_fts_frontier_elapsed_ms($passStarted),
        ];
        $remaining = $plan->deferred_post_ids;
    }

    $measuredPasses = [$sharedPass, ...$passes];
    $performanceEvents = wp_fts_frontier_performance_events($mysqli, $connectionId);
    wp_fts_frontier_assert(count($performanceEvents) === count($measuredPasses), 'Performance Schema did not retain every frontier statement.');
    foreach ($performanceEvents as $offset => $event) {
        $logicalRows = (int) ($measuredPasses[$offset]['frontier_rows_scanned'] ?? 0);
        $returnedRows = (int) ($measuredPasses[$offset]['frontier_rows_returned'] ?? 0);
        wp_fts_frontier_assert($event['rows_examined'] >= $logicalRows, 'Server rows examined are smaller than the logical frontier scan.');
        // MariaDB charges both the 50,001-row capped input and its at-most-seven
        // aggregate rows twice in Performance Schema. Keep the independently
        // measured ceiling until all supported engines prove a tighter one.
        wp_fts_frontier_assert($event['rows_examined'] <= 100016, 'A frontier statement examined more than 100,016 server rows.');
        wp_fts_frontier_assert($event['rows_sent'] === $returnedRows, 'Performance Schema rows sent disagree with the aggregate result.');
        wp_fts_frontier_assert($event['created_tmp_disk_tables'] === 0, 'A frontier statement created a disk temporary table.');
        wp_fts_frontier_assert($event['sort_merge_passes'] === 0, 'A frontier statement performed an external sort merge pass.');
        wp_fts_frontier_assert($event['duration_ms'] <= 5000.0, 'A frontier SQL statement exceeded five seconds.');
        $measuredPasses[$offset]['server_rows_examined'] = $event['rows_examined'];
        $measuredPasses[$offset]['server_rows_sent'] = $event['rows_sent'];
        $measuredPasses[$offset]['created_tmp_tables'] = $event['created_tmp_tables'];
        $measuredPasses[$offset]['created_tmp_disk_tables'] = $event['created_tmp_disk_tables'];
        $measuredPasses[$offset]['sort_merge_passes'] = $event['sort_merge_passes'];
        $measuredPasses[$offset]['server_duration_ms'] = $event['duration_ms'];
    }
    $decrementPerformanceEvents = wp_fts_frontier_decrement_performance_events($mysqli, $connectionId);
    wp_fts_frontier_assert(count($decrementPerformanceEvents) === count($measuredPasses), 'Performance Schema did not retain every bounded dictionary decrement.');
    foreach ($decrementPerformanceEvents as $offset => $event) {
        wp_fts_frontier_assert(
            $event['rows_affected'] === (int) ($measuredPasses[$offset]['expected_decrement_rows_affected'] ?? -1),
            'The bounded dictionary decrement affected an unexpected number of terms.'
        );
        wp_fts_frontier_assert($event['rows_sent'] === 0, 'The bounded dictionary decrement unexpectedly returned rows.');
        // The slowest supported optimizer accounts five indexed operations per
        // row at the 50,000-mutation transaction ceiling.
        wp_fts_frontier_assert($event['rows_examined'] <= 250000, 'A bounded dictionary decrement examined more than 250,000 server rows.');
        wp_fts_frontier_assert($event['created_tmp_disk_tables'] === 0, 'The bounded dictionary decrement created a disk temporary table.');
        wp_fts_frontier_assert($event['sort_merge_passes'] <= 1, 'The bounded dictionary decrement required more than one merge pass.');
        wp_fts_frontier_assert($event['duration_ms'] <= 5000.0, 'A bounded dictionary decrement exceeded five seconds.');
        $measuredPasses[$offset]['decrement_server_rows_examined'] = $event['rows_examined'];
        $measuredPasses[$offset]['decrement_server_rows_affected'] = $event['rows_affected'];
        $measuredPasses[$offset]['decrement_created_tmp_tables'] = $event['created_tmp_tables'];
        $measuredPasses[$offset]['decrement_created_tmp_disk_tables'] = $event['created_tmp_disk_tables'];
        $measuredPasses[$offset]['decrement_sort_merge_passes'] = $event['sort_merge_passes'];
        $measuredPasses[$offset]['decrement_server_duration_ms'] = $event['duration_ms'];
    }
    $deletePerformanceEvents = wp_fts_frontier_delete_performance_events($mysqli, $connectionId);
    wp_fts_frontier_assert(count($deletePerformanceEvents) === count($measuredPasses), 'Performance Schema did not retain every bounded index DELETE.');
    foreach ($deletePerformanceEvents as $offset => $event) {
        $expectedAffectedRows = (int) ($measuredPasses[$offset]['expected_delete_rows_affected'] ?? -1);
        wp_fts_frontier_assert($event['rows_affected'] === $expectedAffectedRows, 'The bounded index DELETE affected an unexpected number of physical rows.');
        wp_fts_frontier_assert($event['rows_sent'] === 0, 'The bounded index DELETE unexpectedly returned rows.');
        // MariaDB accounts no more than six indexed operations per measured
        // mutation for the combined posting/term/document delete.
        wp_fts_frontier_assert($event['rows_examined'] <= 300000, 'A bounded index DELETE examined more than 300,000 server rows.');
        wp_fts_frontier_assert($event['created_tmp_disk_tables'] === 0, 'The bounded index DELETE created a disk temporary table.');
        wp_fts_frontier_assert($event['sort_merge_passes'] <= 2, 'The bounded index DELETE required more than two row-id merge passes.');
        wp_fts_frontier_assert($event['duration_ms'] <= 5000.0, 'A bounded index DELETE exceeded five seconds.');
        $measuredPasses[$offset]['delete_server_rows_examined'] = $event['rows_examined'];
        $measuredPasses[$offset]['delete_server_rows_affected'] = $event['rows_affected'];
        $measuredPasses[$offset]['delete_created_tmp_tables'] = $event['created_tmp_tables'];
        $measuredPasses[$offset]['delete_created_tmp_disk_tables'] = $event['created_tmp_disk_tables'];
        $measuredPasses[$offset]['delete_sort_merge_passes'] = $event['sort_merge_passes'];
        $measuredPasses[$offset]['delete_server_duration_ms'] = $event['duration_ms'];
    }
    $sharedPass = $measuredPasses[0];
    $passes = array_slice($measuredPasses, 1);

    $remainingPostings = (int) $db->get_var(
        "SELECT COUNT(*) FROM {$postingsTable} WHERE post_id BETWEEN {$postIds[0]} AND {$postIds[count($postIds) - 1]}"
    );
    $remainingDocuments = (int) $db->get_var("SELECT COUNT(*) FROM {$documentsTable}");
    $remainingTerms = (int) $db->get_var("SELECT COUNT(*) FROM {$termsTable} WHERE term_id <= {$targetPostingCount}");
    $preservedDecoyPostings = (int) $db->get_var("SELECT COUNT(*) FROM {$postingsTable} WHERE term_id = {$decoyTermId}");
    $preservedDecoyTerms = (int) $db->get_var(
        "SELECT COUNT(*) FROM {$termsTable} WHERE term_id = {$decoyTermId} AND doc_freq = {$decoyPostingCount}"
    );
    $badDocumentFrequencies = (int) $db->get_var(
        "SELECT COUNT(*) FROM {$termsTable} WHERE term_id <= {$targetPostingCount}"
        . " OR (term_id = {$decoyTermId} AND doc_freq <> {$decoyPostingCount})"
    );
    wp_fts_frontier_assert($remainingPostings === 0, 'Eventually draining every admitted document prefix left old postings behind.');
    wp_fts_frontier_assert($remainingDocuments === 0, 'Eventually draining every admitted document prefix left derived documents behind.');
    wp_fts_frontier_assert($remainingTerms === 0, 'Eventually draining every admitted document prefix left retired dictionary rows behind.');
    wp_fts_frontier_assert($preservedDecoyPostings === $decoyPostingCount, 'The frontier transaction changed unrelated postings.');
    wp_fts_frontier_assert($preservedDecoyTerms === 1, 'The frontier transaction changed the unrelated dictionary row.');
    wp_fts_frontier_assert($badDocumentFrequencies === 0, 'Eventually draining every admitted document prefix produced incorrect document frequencies.');
    wp_fts_frontier_assert(($sharedPass['decrement_server_rows_affected'] ?? null) === $sharedTermCount, 'The survivor decrement did not update exactly 49,152 terms.');
    wp_fts_frontier_assert(($sharedPass['delete_server_rows_affected'] ?? null) === $sharedTermCount + 6, 'The survivor DELETE changed a term that should remain live.');
    wp_fts_frontier_assert((float) ($sharedPass['elapsed_ms'] ?? INF) <= 5000.0, 'The df=2 to df=1 survivor pass exceeded five seconds.');
    $expectedPassShapes = [
        [6, 1, 7, 50001, 49152],
        [1, 0, 1, 8192, 8192],
    ];
    wp_fts_frontier_assert(count($passes) === 2, 'Seven 8,192-row documents should drain in one six-document prefix and one single-document prefix.');
    foreach ($passes as $offset => $pass) {
        [
            $expectedAdmittedDocuments,
            $expectedDeferredDocuments,
            $expectedFrontierRows,
            $expectedScannedRows,
            $expectedPostingMutations,
        ] = $expectedPassShapes[$offset];
        wp_fts_frontier_assert(
            ($pass['admitted_documents'] ?? null) === $expectedAdmittedDocuments
            && ($pass['deferred_documents'] ?? null) === $expectedDeferredDocuments
            && ($pass['frontier_rows_returned'] ?? null) === $expectedFrontierRows
            && ($pass['frontier_rows_scanned'] ?? null) === $expectedScannedRows
            && ($pass['posting_mutations'] ?? null) === $expectedPostingMutations,
            "Old-posting pass {$offset} did not retain its exact frontier shape."
        );
    }
    wp_fts_frontier_assert(max(array_column($passes, 'frontier_rows_returned')) <= 7, 'The aggregate frontier returned more than seven per-post rows.');
    wp_fts_frontier_assert(
        max(array_column($passes, 'frontier_rows_scanned')) === WP_FTS_Relational_Storage::MAX_BATCH_POSTINGS + 1,
        'The old-posting scan did not exercise the exact limit-plus-one frontier.'
    );
    wp_fts_frontier_assert(max(array_column($passes, 'posting_mutations')) === 49152, 'The complete admitted prefix did not expose its exact posting mutation count.');
    wp_fts_frontier_assert(array_values(array_unique(array_column($passes, 'frontier_statement_count'))) === [1], 'A pass used more than one frontier statement.');
    wp_fts_frontier_assert(array_values(array_unique(array_column($passes, 'decrement_statement_count'))) === [1], 'A pass used more than one bounded dictionary decrement.');
    wp_fts_frontier_assert(max(array_column($passes, 'decrement_sql_bytes')) <= 4096, 'A frontier dictionary decrement exceeded 4 KiB.');
    wp_fts_frontier_assert(max(array_column($passes, 'decrement_elapsed_ms')) <= 5000.0, 'A recorded dictionary decrement exceeded five seconds.');
    wp_fts_frontier_assert(array_values(array_unique(array_column($passes, 'delete_statement_count'))) === [1], 'A pass used more than one bounded index DELETE.');
    wp_fts_frontier_assert(max(array_column($passes, 'delete_sql_bytes')) <= 4096, 'A frontier DELETE exceeded 4 KiB.');
    wp_fts_frontier_assert(max(array_column($passes, 'delete_elapsed_ms')) <= 5000.0, 'A recorded frontier DELETE exceeded five seconds.');
    wp_fts_frontier_assert(max(array_column($passes, 'transaction_statement_count')) === 5, 'A deletion prefix did not retain its five-statement transaction shape.');
    wp_fts_frontier_assert(max(array_column($passes, 'elapsed_ms')) <= 5000.0, 'A frontier pass exceeded five seconds.');
    wp_fts_frontier_assert(memory_get_peak_usage(true) <= 134217728, 'The frontier proof exceeded the 128 MiB PHP ceiling.');

    // Reuse the hard frontier fixture for the reset proof. Together with the
    // preserved plan decoy, reset publishes an empty generation over 157,344
    // populated postings rather than proving metadata work on an empty table.
    $resetSeedStarted = hrtime(true);
    wp_fts_frontier_seed_target_fixture(
        $mysqli,
        $termsTable,
        $postingsTable,
        $documentsTable,
        $postIds,
        $termCountPerPost,
        $lexicalTermCountPerPost
    );
    wp_fts_frontier_query(
        $mysqli,
        "INSERT INTO {$workTable} (job_key,kind,post_id,generation,state) "
        . "VALUES ('post:999999','post',999999,7,'ready')"
    );
    $resetSeedMs = wp_fts_frontier_elapsed_ms($resetSeedStarted);
    $oldSearchEpoch = (int) $db->get_var(
        "SELECT generation FROM {$workTable} WHERE job_key='meta:search-epoch' AND kind='meta'"
    );
    $oldSearchEpochIncarnation = (string) $db->get_var(
        "SELECT payload FROM {$workTable} WHERE job_key='meta:search-epoch' AND kind='meta'"
    );
    $resetTermKinds = $db->get_row(
        "SELECT COUNT(*) AS total_terms, SUM(kind = 0) AS lexical_terms,"
        . " SUM(kind = 1) AS surface_terms FROM {$termsTable}"
    );
    if (!is_object($resetTermKinds)) {
        throw new RuntimeException('Could not measure the reset dictionary fixture.');
    }
    $resetFixture = [
        'postings' => (int) $db->get_var("SELECT COUNT(*) FROM {$postingsTable}"),
        'terms' => (int) $resetTermKinds->total_terms,
        'lexical_terms' => (int) $resetTermKinds->lexical_terms,
        'surface_terms' => (int) $resetTermKinds->surface_terms,
        'documents' => (int) $db->get_var("SELECT COUNT(*) FROM {$documentsTable}"),
        'work_rows' => (int) $db->get_var("SELECT COUNT(*) FROM {$workTable}"),
        'non_epoch_work_rows' => (int) $db->get_var(
            "SELECT COUNT(*) FROM {$workTable} WHERE job_key <> 'meta:search-epoch'"
        ),
        'search_epoch' => $oldSearchEpoch,
        'search_epoch_incarnation' => $oldSearchEpochIncarnation,
        'seed_ms' => $resetSeedMs,
    ];
    wp_fts_frontier_assert($resetFixture['postings'] === 157344, 'The reset fixture must contain exactly 157,344 postings.');
    wp_fts_frontier_assert($resetFixture['terms'] === 57345, 'The reset fixture must contain exactly 57,345 terms.');
    wp_fts_frontier_assert($resetFixture['lexical_terms'] === 28673, 'The reset fixture must contain exactly 28,673 lexical terms including the plan decoy.');
    wp_fts_frontier_assert($resetFixture['surface_terms'] === 28672, 'The reset fixture must contain exactly 28,672 normalized-surface terms.');
    wp_fts_frontier_assert($resetFixture['documents'] === 7, 'The reset fixture must contain exactly 7 documents.');
    wp_fts_frontier_assert($resetFixture['work_rows'] === 2, 'The reset fixture must contain an epoch and durable work row.');
    wp_fts_frontier_assert($resetFixture['non_epoch_work_rows'] === 1, 'The reset fixture must contain one non-epoch work row.');
    wp_fts_frontier_assert($oldSearchEpoch > 0, 'The reset fixture must begin from a published nonzero search epoch.');
    wp_fts_frontier_assert(
        preg_match('/^[a-f0-9]{32}$/D', $oldSearchEpochIncarnation) === 1,
        'The reset fixture must begin with a valid random search-epoch incarnation.'
    );

    $preResetPhpPeakBytes = memory_get_peak_usage(true);
    if (!function_exists('memory_reset_peak_usage')) {
        throw new RuntimeException('The reset proof requires resettable PHP peak-memory accounting.');
    }
    $resetGuardCalls = 0;
    $resetStorage = new WP_FTS_Relational_Storage(
        $db,
        $prefix,
        static function () use (&$resetGuardCalls): void {
            $resetGuardCalls++;
        }
    );
    $resetMarker = $db->statement_marker();
    memory_reset_peak_usage();
    $resetPhpStartBytes = memory_get_usage(true);
    $resetStarted = hrtime(true);
    $resetSummary = $resetStorage->reset_index();
    $resetElapsedMs = wp_fts_frontier_elapsed_ms($resetStarted);
    $resetPhpPeakBytes = memory_get_peak_usage(true);
    $resetPhpDeltaBytes = max(0, $resetPhpPeakBytes - $resetPhpStartBytes);
    $resetStatements = $db->statements_since($resetMarker);
    $resetSql = array_column($resetStatements, 'sql');
    $resetMethods = array_column($resetStatements, 'method');
    $newSearchEpochIncarnation = (string) $db->get_var(
        "SELECT payload FROM {$workTable} WHERE job_key='meta:search-epoch' AND kind='meta'"
    );
    wp_fts_frontier_assert(
        preg_match('/^[a-f0-9]{32}$/D', $newSearchEpochIncarnation) === 1,
        'Atomic reset must publish a valid random search-epoch incarnation.'
    );
    wp_fts_frontier_assert(
        !hash_equals($oldSearchEpochIncarnation, $newSearchEpochIncarnation),
        'Atomic reset must replace the search-epoch incarnation even when the numeric generation advances.'
    );
    $expectedResetSql = wp_fts_frontier_expected_reset_sql(
        $prefix,
        $oldSearchEpoch + 1,
        $newSearchEpochIncarnation
    );
    $resetStatementEvidence = [];
    foreach ($resetStatements as $offset => $statement) {
        $statementSql = (string) ($statement['sql'] ?? '');
        $resetStatementEvidence[] = [
            'ordinal' => $offset + 1,
            'method' => (string) ($statement['method'] ?? ''),
            'bytes' => strlen($statementSql),
            'sha256' => hash('sha256', $statementSql),
            'duration_ms' => (float) ($statement['duration_ms'] ?? -1.0),
            'sql' => $statementSql,
        ];
    }
    $resetSqlUpper = strtoupper(implode("\n", $resetSql));
    $resetHasForbiddenCorpusWork = str_contains($resetSqlUpper, 'DELETE')
        || str_contains($resetSqlUpper, 'COUNT(');
    wp_fts_frontier_assert($resetSql === $expectedResetSql, 'Populated reset did not retain its exact nine-statement SQL shape.');
    wp_fts_frontier_assert(
        $resetMethods === ['get_var', 'query', 'query', 'query', 'query', 'query', 'query', 'query', 'query'],
        'Populated reset used an unexpected database method shape.'
    );
    wp_fts_frontier_assert(!$resetHasForbiddenCorpusWork, 'Populated reset issued DELETE or COUNT corpus work.');
    wp_fts_frontier_assert($resetGuardCalls === 3, 'Populated reset did not revalidate ownership exactly three times.');
    wp_fts_frontier_assert($resetElapsedMs <= 5000.0, 'Populated atomic reset exceeded five seconds.');
    wp_fts_frontier_assert($resetPhpDeltaBytes <= 16777216, 'Populated atomic reset added more than 16 MiB of PHP allocation.');
    wp_fts_frontier_assert($resetPhpPeakBytes <= 134217728, 'Populated atomic reset exceeded the 128 MiB PHP ceiling.');
    wp_fts_frontier_assert(
        max(array_column($resetStatementEvidence, 'bytes')) <= 4096,
        'Populated atomic reset emitted a statement larger than 4 KiB.'
    );
    $expectedResetSummary = [
        'reset_strategy' => 'mysql_atomic_table_swap',
        'search_epoch' => $oldSearchEpoch + 1,
    ];
    wp_fts_frontier_assert($resetSummary === $expectedResetSummary, 'Populated reset returned the strategy and new search epoch.');

    $schemaVerification = $resetStorage->verify_schema();
    wp_fts_frontier_assert(($schemaVerification['valid'] ?? false) === true, 'Atomic reset did not preserve the exact current schema.');
    $schemaEvidence = wp_fts_frontier_schema_evidence(
        $mysqli,
        [$termsTable, $postingsTable, $documentsTable, $workTable]
    );
    $postResetTables = wp_fts_frontier_prefix_tables($mysqli, $prefix);
    $expectedPostResetTables = [$documentsTable, $postingsTable, $termsTable, $workTable];
    sort($expectedPostResetTables, SORT_STRING);
    $postResetWorkRows = $db->get_results(
        "SELECT job_key,kind,post_id,generation,state,payload FROM {$workTable} ORDER BY job_key"
    );
    $postReset = [
        'postings' => (int) $db->get_var("SELECT COUNT(*) FROM {$postingsTable}"),
        'terms' => (int) $db->get_var("SELECT COUNT(*) FROM {$termsTable}"),
        'documents' => (int) $db->get_var("SELECT COUNT(*) FROM {$documentsTable}"),
        'work_rows' => count($postResetWorkRows),
        'work_row' => isset($postResetWorkRows[0]) ? get_object_vars($postResetWorkRows[0]) : null,
        'prefix_tables' => $postResetTables,
        'only_canonical_tables' => $postResetTables === $expectedPostResetTables,
    ];
    wp_fts_frontier_assert($postReset['postings'] === 0, 'Atomic reset left postings in the published generation.');
    wp_fts_frontier_assert($postReset['terms'] === 0, 'Atomic reset left terms in the published generation.');
    wp_fts_frontier_assert($postReset['documents'] === 0, 'Atomic reset left documents in the published generation.');
    wp_fts_frontier_assert($postReset['work_rows'] === 1, 'Atomic reset did not leave exactly one work row.');
    wp_fts_frontier_assert(
        $postReset['work_row'] === [
            'job_key' => 'meta:search-epoch',
            'kind' => 'meta',
            'post_id' => '0',
            'generation' => (string) ($oldSearchEpoch + 1),
            'state' => 'meta',
            'payload' => $newSearchEpochIncarnation,
        ],
        'Atomic reset did not leave only the advanced epoch with its new incarnation.'
    );
    wp_fts_frontier_assert($postReset['only_canonical_tables'], 'Atomic reset leaked a staging or retired generation table.');
    $resetLinuxVmHwmBytes = wp_fts_frontier_linux_vmhwm_bytes();
    wp_fts_frontier_assert($resetLinuxVmHwmBytes > 0, 'Could not measure Linux VmHWM for populated reset.');
    wp_fts_frontier_assert($resetLinuxVmHwmBytes <= 134217728, 'Populated reset exceeded the 128 MiB Linux VmHWM ceiling.');
    $overallPhpPeakBytes = max($preResetPhpPeakBytes, memory_get_peak_usage(true));

    $databaseVersion = (string) $db->get_var('SELECT VERSION()');
    $databaseIdentity = strtolower($databaseVersion);
    $actualEngine = str_contains($databaseIdentity, 'mariadb')
        ? 'mariadb-10.11'
        : (
            version_compare($databaseIdentity, '8.0', '>=')
                && version_compare($databaseIdentity, '8.1', '<')
                ? 'mysql-8.0'
                : 'unsupported'
        );
    wp_fts_frontier_assert($actualEngine === $expectedEngine, 'The frontier proof ran against the wrong database family.');
    $evidence = [
        'schema' => 'relational-old-posting-frontier-v2',
        'status' => 'PASS',
        'source_sha' => $sourceSha,
        'zip_sha256' => $zipSha,
        'harness_sha256' => $actualHarnessSha,
        'engine' => $actualEngine,
        'database' => $databaseVersion,
        'fixture' => [
            'documents' => count($postIds),
            'postings_per_document' => $termCountPerPost,
            'lexical_postings_per_document' => $lexicalTermCountPerPost,
            'surface_postings_per_document' => $surfaceTermCountPerPost,
            'lexical_terms' => $targetKindCounts[0],
            'surface_terms' => $targetKindCounts[1],
            'disjoint_terms' => $expectedPostings,
            'old_postings' => $expectedPostings,
            'plan_decoy_postings' => $decoyPostingCount,
            'seed_ms' => $seedMs,
        ],
        'shared_survivor' => [
            'fixture' => [
                'target_documents' => count($sharedTargetPostIds),
                'survivor_documents' => count($sharedSurvivorPostIds),
                'shared_terms' => $sharedTermCount,
                'shared_lexical_terms' => $sharedKindCounts[0],
                'shared_surface_terms' => $sharedKindCounts[1],
                'postings' => 2 * $sharedTermCount,
                'initial_doc_freq' => 2,
                'seed_ms' => $sharedSeedMs,
            ],
            'pass' => $sharedPass,
            'state' => $sharedSurvivorState,
        ],
        'execution' => [
            'passes' => count($passes),
            'admitted_documents_per_full_pass' => $passes[0]['admitted_documents'] ?? null,
            'max_frontier_rows_returned' => max(array_column($passes, 'frontier_rows_returned')),
            'max_frontier_rows_scanned' => max(array_column($passes, 'frontier_rows_scanned')),
            'max_posting_mutations' => max(array_column($passes, 'posting_mutations')),
            'frontier_statements_per_pass' => array_values(array_unique(array_column($passes, 'frontier_statement_count'))),
            'transaction_statement_ids' => $passes[0]['transaction_statement_ids'] ?? null,
            'transaction_statement_methods' => $passes[0]['transaction_statement_methods'] ?? null,
            'decrement_statements_per_pass' => array_values(array_unique(array_column($passes, 'decrement_statement_count'))),
            'max_decrement_sql_bytes' => max(array_column($passes, 'decrement_sql_bytes')),
            'max_decrement_elapsed_ms' => max(array_column($passes, 'decrement_elapsed_ms')),
            'max_decrement_server_rows_examined' => max(array_column($passes, 'decrement_server_rows_examined')),
            'max_decrement_server_rows_affected' => max(array_column($passes, 'decrement_server_rows_affected')),
            'max_decrement_created_tmp_disk_tables' => max(array_column($passes, 'decrement_created_tmp_disk_tables')),
            'max_decrement_sort_merge_passes' => max(array_column($passes, 'decrement_sort_merge_passes')),
            'max_decrement_server_duration_ms' => max(array_column($passes, 'decrement_server_duration_ms')),
            'delete_statements_per_pass' => array_values(array_unique(array_column($passes, 'delete_statement_count'))),
            'max_delete_sql_bytes' => max(array_column($passes, 'delete_sql_bytes')),
            'max_delete_elapsed_ms' => max(array_column($passes, 'delete_elapsed_ms')),
            'max_delete_server_rows_examined' => max(array_column($passes, 'delete_server_rows_examined')),
            'max_delete_server_rows_affected' => max(array_column($passes, 'delete_server_rows_affected')),
            'max_delete_created_tmp_disk_tables' => max(array_column($passes, 'delete_created_tmp_disk_tables')),
            'max_delete_sort_merge_passes' => max(array_column($passes, 'delete_sort_merge_passes')),
            'max_delete_server_duration_ms' => max(array_column($passes, 'delete_server_duration_ms')),
            'max_transaction_statements' => max(array_column($passes, 'transaction_statement_count')),
            'max_pass_ms' => max(array_column($passes, 'elapsed_ms')),
            'total_pass_ms' => array_sum(array_column($passes, 'elapsed_ms')),
            'max_server_rows_examined' => max(array_column($passes, 'server_rows_examined')),
            'max_server_rows_sent' => max(array_column($passes, 'server_rows_sent')),
            'max_created_tmp_disk_tables' => max(array_column($passes, 'created_tmp_disk_tables')),
            'max_sort_merge_passes' => max(array_column($passes, 'sort_merge_passes')),
            'max_server_duration_ms' => max(array_column($passes, 'server_duration_ms')),
            'pass_details' => $passes,
        ],
        'eventual_state' => [
            'remaining_postings' => $remainingPostings,
            'remaining_documents' => $remainingDocuments,
            'remaining_terms' => $remainingTerms,
            'preserved_decoy_postings' => $preservedDecoyPostings,
            'preserved_decoy_terms' => $preservedDecoyTerms,
            'bad_document_frequencies' => $badDocumentFrequencies,
        ],
        'plan' => $planEvidence,
        'reset' => [
            'fixture' => $resetFixture,
            'summary' => $resetSummary,
            'guard_calls' => $resetGuardCalls,
            'statement_count' => count($resetStatements),
            'statement_methods' => $resetMethods,
            'statements' => $resetStatementEvidence,
            'exact_sql_shape' => $resetSql === $expectedResetSql,
            'contains_delete_or_count' => $resetHasForbiddenCorpusWork,
            'max_statement_bytes' => max(array_column($resetStatementEvidence, 'bytes')),
            'elapsed_ms' => $resetElapsedMs,
            'php_allocation_delta_bytes' => $resetPhpDeltaBytes,
            'php_peak_bytes' => $resetPhpPeakBytes,
            'linux_vmhwm_bytes' => $resetLinuxVmHwmBytes,
            'schema_version' => 1,
            'schema_verification' => $schemaVerification,
            'physical_schema' => $schemaEvidence,
            'post_reset' => $postReset,
        ],
        'php_peak_bytes' => $overallPhpPeakBytes,
        'elapsed_ms' => wp_fts_frontier_elapsed_ms($started),
    ];
} catch (Throwable $error) {
    $primaryError = $error;
} finally {
    $dropFailures = [];
    $fixtureTables = [];
    try {
        $fixtureTables = wp_fts_frontier_prefix_tables($mysqli, $prefix);
    } catch (Throwable $error) {
        $dropFailures['enumeration'] = $error->getMessage();
    }
    foreach ($fixtureTables as $table) {
        $identifier = '`' . str_replace('`', '``', $table) . '`';
        if ($mysqli->query("DROP TABLE IF EXISTS {$identifier}") === false) {
            $dropFailures[$table] = $mysqli->error;
        }
    }
    $remainingFixtureTables = [];
    try {
        $remainingFixtureTables = wp_fts_frontier_prefix_tables($mysqli, $prefix);
    } catch (Throwable $error) {
        $dropFailures['verification'] = $error->getMessage();
    }
    $cleanupEvidence = [
        'requested_tables' => count($fixtureTables),
        'drop_statements' => count($fixtureTables),
        'drop_failures' => $dropFailures,
        'remaining_tables' => $remainingFixtureTables,
        'verified_absent' => $dropFailures === [] && $remainingFixtureTables === [],
    ];
    if (!$cleanupEvidence['verified_absent']) {
        $cleanupError = new RuntimeException('The old-posting frontier fixture tables were not completely removed.');
    }
    $mysqli->close();
}

if ($primaryError instanceof Throwable) {
    if ($cleanupError instanceof Throwable) {
        throw new RuntimeException(
            $primaryError->getMessage() . ' Cleanup also failed: ' . $cleanupError->getMessage(),
            0,
            $primaryError
        );
    }
    throw $primaryError;
}
if ($cleanupError instanceof Throwable) {
    throw $cleanupError;
}
if (!is_array($evidence)) {
    throw new RuntimeException('The frontier proof completed without evidence.');
}
$evidence['fixture_cleanup'] = $cleanupEvidence;
echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

final class WP_FTS_Frontier_WPDB
{
    public string $last_error = '';
    public string $posts;
    public string $term_relationships;
    /** @var array<int,array{method:string,sql:string,row_count:int,duration_ms:float}> */
    private array $statements = [];

    /** Expose only the wpdb surface needed to exercise the real storage writer. */
    public function __construct(public mysqli $dbh, public string $prefix)
    {
        $this->posts = $prefix . 'posts';
        $this->term_relationships = $prefix . 'term_relationships';
    }

    /** Expand the proof's `%d`/`%s` subset without hiding the executed SQL. */
    public function prepare(string $sql, mixed ...$args): string
    {
        $result = '';
        $argumentOffset = 0;
        $length = strlen($sql);
        for ($offset = 0; $offset < $length; $offset++) {
            if ($sql[$offset] !== '%' || $offset + 1 >= $length || !in_array($sql[$offset + 1], ['d', 's'], true)) {
                $result .= $sql[$offset];
                continue;
            }
            $specifier = $sql[++$offset];
            $value = $args[$argumentOffset++] ?? null;
            $result .= $specifier === 'd'
                ? (string) (int) $value
                : "'" . $this->dbh->real_escape_string((string) $value) . "'";
        }
        if ($argumentOffset !== count($args)) {
            throw new InvalidArgumentException('Frontier proof SQL placeholder count does not match its arguments.');
        }

        return $result;
    }

    /** Execute and record mutation statements with their affected-row result. */
    public function query(string $sql): int|bool
    {
        $started = hrtime(true);
        $result = $this->dbh->query($sql);
        $this->last_error = $result === false ? $this->dbh->error : '';
        $this->record('query', $sql, 0, $started);
        if ($result === false) {
            return false;
        }
        if ($result instanceof mysqli_result) {
            $result->free();
            return 0;
        }
        return $this->dbh->affected_rows;
    }

    /** @return object[] */
    public function get_results(string $sql): array
    {
        $started = hrtime(true);
        $result = $this->dbh->query($sql);
        $this->last_error = $result === false ? $this->dbh->error : '';
        $rows = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_object()) {
                $rows[] = $row;
            }
            $result->free();
        }
        $this->record('get_results', $sql, count($rows), $started);

        return $rows;
    }

    /** Return one wpdb-shaped object while sharing the recorded result path. */
    public function get_row(string $sql): ?object
    {
        return $this->get_results($sql)[0] ?? null;
    }

    /** Execute scalar reads without dropping their timing or statement evidence. */
    public function get_var(string $sql): mixed
    {
        $started = hrtime(true);
        $result = $this->dbh->query($sql);
        $this->last_error = $result === false ? $this->dbh->error : '';
        $row = $result instanceof mysqli_result ? $result->fetch_row() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $this->record('get_var', $sql, is_array($row) ? 1 : 0, $started);

        return $row[0] ?? null;
    }

    /** Mark the statement stream immediately before the operation under test. */
    public function statement_marker(): int
    {
        return count($this->statements);
    }

    /** @return array<int,array{method:string,sql:string,row_count:int,duration_ms:float}> */
    public function statements_since(int $marker): array
    {
        return array_slice($this->statements, $marker);
    }

    /** Retain exact SQL because role and size assertions run after the mutation. */
    private function record(string $method, string $sql, int $rowCount, int $started): void
    {
        $this->statements[] = [
            'method' => $method,
            'sql' => $sql,
            'row_count' => $rowCount,
            'duration_ms' => wp_fts_frontier_elapsed_ms($started),
        ];
    }
}

/**
 * @param array<int,array{method:string,sql:string,row_count:int,duration_ms:float}> $statements
 * @return array<string,mixed>
 */
function wp_fts_frontier_write_evidence(array $statements): array
{
    $statementIds = array_map(
        static function (array $statement): string {
            $sql = (string) ($statement['sql'] ?? '');
            return match (true) {
                $sql === 'START TRANSACTION' => 'begin',
                str_contains($sql, 'wp_fts:dictionary-decrement') => 'dictionary_decrement',
                str_contains($sql, 'wp_fts:bounded-index-delete') => 'bounded_index_delete',
                str_contains($sql, 'wp_fts:search-epoch-advance') => 'search_epoch_advance',
                $sql === 'COMMIT' => 'commit',
                default => 'unexpected',
            };
        },
        $statements
    );
    $statementMethods = array_column($statements, 'method');
    wp_fts_frontier_assert(
        $statementIds === ['begin', 'dictionary_decrement', 'bounded_index_delete', 'search_epoch_advance', 'commit'],
        'The bounded replacement transaction changed statement identity or order.'
    );
    wp_fts_frontier_assert(
        $statementMethods === ['query', 'query', 'query', 'query', 'query'],
        'The bounded replacement transaction changed database method shape.'
    );
    wp_fts_frontier_assert(
        count(array_filter(
            $statements,
            static fn(array $statement): bool => str_contains($statement['sql'], 'wp_fts:replacement-frontier')
        )) === 0,
        'A carried plan must prevent a second frontier scan.'
    );
    $decrementStatements = array_values(array_filter(
        $statements,
        static fn(array $statement): bool => str_contains($statement['sql'], 'wp_fts:dictionary-decrement')
    ));
    wp_fts_frontier_assert(count($decrementStatements) === 1, 'Each pass must use exactly one bounded dictionary decrement.');
    $decrementStatement = $decrementStatements[0];
    $decrementSql = (string) ($decrementStatement['sql'] ?? '');
    wp_fts_frontier_assert(($decrementStatement['method'] ?? null) === 'query', 'The bounded dictionary decrement must be one data statement.');
    wp_fts_frontier_assert(str_starts_with($decrementSql, 'UPDATE ('), 'The bounded dictionary decrement must begin from its materialized driver.');
    wp_fts_frontier_assert(str_contains($decrementSql, 'changed FORCE INDEX (post_term)'), 'The bounded dictionary decrement lost its post-first driver.');
    wp_fts_frontier_assert(str_contains($decrementSql, 'STRAIGHT_JOIN '), 'The bounded dictionary decrement lost its fixed join order.');
    wp_fts_frontier_assert(str_contains($decrementSql, 'AS t FORCE INDEX (PRIMARY)'), 'The bounded dictionary decrement lost its primary-key target lookup.');
    wp_fts_frontier_assert(!str_contains($decrementSql, 'INSERT INTO '), 'The bounded dictionary decrement became a self-referential INSERT again.');
    $deleteStatements = array_values(array_filter(
        $statements,
        static fn(array $statement): bool => str_contains($statement['sql'], 'wp_fts:bounded-index-delete')
    ));
    wp_fts_frontier_assert(count($deleteStatements) === 1, 'Each pass must use exactly one bounded index DELETE.');
    $deleteStatement = $deleteStatements[0];
    $deleteSql = (string) ($deleteStatement['sql'] ?? '');
    wp_fts_frontier_assert(($deleteStatement['method'] ?? null) === 'query', 'The bounded index DELETE must be one data statement.');
    wp_fts_frontier_assert(str_contains($deleteSql, 'LIMIT 50100'), 'The bounded index DELETE lost its 50,100-row materialization barrier.');
    wp_fts_frontier_assert(str_contains($deleteSql, 'candidate_posting FORCE INDEX (post_term)'), 'The bounded index DELETE lost its post-first driver.');
    foreach (['old_posting', 'retired_term', 'retired_document'] as $targetAlias) {
        wp_fts_frontier_assert(
            str_contains($deleteSql, "{$targetAlias} FORCE INDEX (PRIMARY)"),
            "The {$targetAlias} DELETE target lost its primary-key lookup."
        );
    }

    return [
        'transaction_statement_count' => count($statements),
        'transaction_statement_ids' => $statementIds,
        'transaction_statement_methods' => $statementMethods,
        'decrement_statement_count' => count($decrementStatements),
        'decrement_sql_bytes' => strlen($decrementSql),
        'decrement_sql_sha256' => hash('sha256', $decrementSql),
        'decrement_elapsed_ms' => (float) ($decrementStatement['duration_ms'] ?? -1.0),
        'delete_statement_count' => count($deleteStatements),
        'delete_sql_bytes' => strlen($deleteSql),
        'delete_sql_sha256' => hash('sha256', $deleteSql),
        'delete_elapsed_ms' => (float) ($deleteStatement['duration_ms'] ?? -1.0),
    ];
}

/** @return array<string,mixed> */
function wp_fts_frontier_explain(mysqli $db, string $sql): array
{
    $result = $db->query('EXPLAIN FORMAT=JSON ' . $sql);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('Could not EXPLAIN the production frontier query: ' . $db->error);
    }
    $row = $result->fetch_assoc();
    $result->free();
    $raw = (string) (array_values(is_array($row) ? $row : [])[0] ?? '');
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('The frontier EXPLAIN JSON is malformed.');
    }

    $access = [];
    wp_fts_frontier_collect_table_access($decoded, '$', $access);
    $oldPostingAccess = array_values(array_filter(
        $access,
        static fn(array $table): bool => ($table['table_name'] ?? null) === 'old_posting'
    ));
    wp_fts_frontier_assert(count($oldPostingAccess) === 1, 'The frontier EXPLAIN must contain exactly one old_posting access.');
    $oldAccess = $oldPostingAccess[0];
    wp_fts_frontier_assert(($oldAccess['access_type'] ?? null) === 'range', 'The old-posting frontier must use range access, not a table/index scan.');
    wp_fts_frontier_assert(($oldAccess['key'] ?? null) === 'post_term', 'The old-posting frontier did not select post_term.');
    wp_fts_frontier_assert(($oldAccess['using_index'] ?? null) === true, 'The old-posting frontier access is not covering.');

    $queryBlocks = [];
    wp_fts_frontier_collect_query_blocks($decoded, 0, $queryBlocks);
    $innerCandidates = array_values(array_filter(
        $queryBlocks,
        static fn(array $candidate): bool => wp_fts_frontier_node_contains_table($candidate['block'], 'old_posting')
    ));
    usort($innerCandidates, static fn(array $left, array $right): int => $right['depth'] <=> $left['depth']);
    $innerBlock = $innerCandidates[0]['block'] ?? null;
    wp_fts_frontier_assert(is_array($innerBlock), 'Could not isolate the frontier inner query block.');
    $innerFilesort = wp_fts_frontier_node_has_active_filesort($innerBlock);
    $innerTemporary = wp_fts_frontier_node_has_temporary_table($innerBlock);
    wp_fts_frontier_assert(!$innerFilesort, 'The LIMIT 50,001 inner frontier performs a filesort before it can stop.');
    wp_fts_frontier_assert(!$innerTemporary, 'The LIMIT 50,001 inner frontier materializes a temporary table before it can stop.');

    return [
        'sha256' => hash('sha256', $raw),
        'raw_json' => $raw,
        'table_access' => $access,
        'old_posting_access' => $oldAccess,
        'inner_query_block_sha256' => hash(
            'sha256',
            json_encode($innerBlock, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        ),
        'inner_uses_filesort' => $innerFilesort,
        'inner_uses_temporary_table' => $innerTemporary,
    ];
}

/** @return array<int,array<string,int|float>> */
function wp_fts_frontier_performance_events(mysqli $db, int $connectionId): array
{
    $sql = "SELECT event_id, rows_examined, rows_sent, created_tmp_tables,
       created_tmp_disk_tables, sort_merge_passes, timer_wait
FROM performance_schema.events_statements_history_long events
INNER JOIN performance_schema.threads threads ON threads.thread_id = events.thread_id
WHERE threads.processlist_id = {$connectionId}
  AND events.sql_text LIKE '/* wp\\_fts:replacement-frontier */%' ESCAPE '\\\\'
ORDER BY event_id ASC";
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('Could not read frontier Performance Schema evidence: ' . $db->error);
    }
    $events = [];
    while ($row = $result->fetch_assoc()) {
        $events[] = [
            'event_id' => (int) ($row['event_id'] ?? 0),
            'rows_examined' => (int) ($row['rows_examined'] ?? -1),
            'rows_sent' => (int) ($row['rows_sent'] ?? -1),
            'created_tmp_tables' => (int) ($row['created_tmp_tables'] ?? -1),
            'created_tmp_disk_tables' => (int) ($row['created_tmp_disk_tables'] ?? -1),
            'sort_merge_passes' => (int) ($row['sort_merge_passes'] ?? -1),
            'duration_ms' => ((int) ($row['timer_wait'] ?? 0)) / 1_000_000_000,
        ];
    }
    $result->free();

    return $events;
}

/** @return array<int,array<string,int|float>> */
function wp_fts_frontier_decrement_performance_events(mysqli $db, int $connectionId): array
{
    $sql = "SELECT event_id, rows_examined, rows_affected, rows_sent, created_tmp_tables,
       created_tmp_disk_tables, sort_merge_passes, timer_wait
FROM performance_schema.events_statements_history_long events
INNER JOIN performance_schema.threads threads ON threads.thread_id = events.thread_id
WHERE threads.processlist_id = {$connectionId}
  AND events.sql_text LIKE 'UPDATE (%' ESCAPE '\\\\'
  AND events.sql_text LIKE '%/* wp\_fts:dictionary-decrement */%' ESCAPE '\\\\'
ORDER BY event_id ASC";
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('Could not read bounded dictionary decrement Performance Schema evidence: ' . $db->error);
    }
    $events = [];
    while ($row = $result->fetch_assoc()) {
        $events[] = [
            'event_id' => (int) ($row['event_id'] ?? 0),
            'rows_examined' => (int) ($row['rows_examined'] ?? -1),
            'rows_affected' => (int) ($row['rows_affected'] ?? -1),
            'rows_sent' => (int) ($row['rows_sent'] ?? -1),
            'created_tmp_tables' => (int) ($row['created_tmp_tables'] ?? -1),
            'created_tmp_disk_tables' => (int) ($row['created_tmp_disk_tables'] ?? -1),
            'sort_merge_passes' => (int) ($row['sort_merge_passes'] ?? -1),
            'duration_ms' => ((int) ($row['timer_wait'] ?? 0)) / 1_000_000_000,
        ];
    }
    $result->free();

    return $events;
}

/** @return array<int,array<string,int|float>> */
function wp_fts_frontier_delete_performance_events(mysqli $db, int $connectionId): array
{
    $sql = "SELECT event_id, rows_examined, rows_affected, rows_sent, created_tmp_tables,
       created_tmp_disk_tables, sort_merge_passes, timer_wait
FROM performance_schema.events_statements_history_long events
INNER JOIN performance_schema.threads threads ON threads.thread_id = events.thread_id
WHERE threads.processlist_id = {$connectionId}
  AND events.sql_text LIKE 'DELETE old\_posting, retired\_term, retired\_document%' ESCAPE '\\\\'
  AND events.sql_text LIKE '%/* wp\_fts:bounded-index-delete */%' ESCAPE '\\\\'
ORDER BY event_id ASC";
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('Could not read bounded index DELETE Performance Schema evidence: ' . $db->error);
    }
    $events = [];
    while ($row = $result->fetch_assoc()) {
        $events[] = [
            'event_id' => (int) ($row['event_id'] ?? 0),
            'rows_examined' => (int) ($row['rows_examined'] ?? -1),
            'rows_affected' => (int) ($row['rows_affected'] ?? -1),
            'rows_sent' => (int) ($row['rows_sent'] ?? -1),
            'created_tmp_tables' => (int) ($row['created_tmp_tables'] ?? -1),
            'created_tmp_disk_tables' => (int) ($row['created_tmp_disk_tables'] ?? -1),
            'sort_merge_passes' => (int) ($row['sort_merge_passes'] ?? -1),
            'duration_ms' => ((int) ($row['timer_wait'] ?? 0)) / 1_000_000_000,
        ];
    }
    $result->free();

    return $events;
}

/** @param array<string|int,mixed> $node @param array<int,array<string,mixed>> $access */
function wp_fts_frontier_collect_table_access(array $node, string $path, array &$access): void
{
    if (isset($node['table_name']) && is_scalar($node['table_name'])) {
        $access[] = [
            'path' => $path,
            'table_name' => (string) $node['table_name'],
            'access_type' => is_scalar($node['access_type'] ?? null) ? strtolower((string) $node['access_type']) : '',
            'key' => is_scalar($node['key'] ?? null) ? (string) $node['key'] : '',
            'possible_keys' => is_array($node['possible_keys'] ?? null) ? array_values($node['possible_keys']) : [],
            'used_key_parts' => is_array($node['used_key_parts'] ?? null) ? array_values($node['used_key_parts']) : [],
            'rows' => is_numeric($node['rows_examined_per_scan'] ?? null)
                ? (int) $node['rows_examined_per_scan']
                : (is_numeric($node['rows'] ?? null) ? (int) $node['rows'] : null),
            'using_index' => ($node['using_index'] ?? null) === true,
        ];
    }
    foreach ($node as $key => $child) {
        if (is_array($child)) {
            wp_fts_frontier_collect_table_access($child, $path . '.' . (string) $key, $access);
        }
    }
}

/** @param array<string|int,mixed> $node @param array<int,array{depth:int,block:array<string|int,mixed>}> $blocks */
function wp_fts_frontier_collect_query_blocks(array $node, int $depth, array &$blocks): void
{
    foreach ($node as $key => $child) {
        if (!is_array($child)) {
            continue;
        }
        if ($key === 'query_block') {
            $blocks[] = ['depth' => $depth, 'block' => $child];
        }
        wp_fts_frontier_collect_query_blocks($child, $depth + 1, $blocks);
    }
}

/** @param array<string|int,mixed> $node */
function wp_fts_frontier_node_contains_table(array $node, string $tableName): bool
{
    if (($node['table_name'] ?? null) === $tableName) {
        return true;
    }
    foreach ($node as $child) {
        if (is_array($child) && wp_fts_frontier_node_contains_table($child, $tableName)) {
            return true;
        }
    }
    return false;
}

/** @param array<string|int,mixed> $node */
function wp_fts_frontier_node_has_active_filesort(array $node): bool
{
    foreach ($node as $key => $child) {
        if ($key === 'using_filesort' && $child === true) {
            return true;
        }
        if ($key === 'filesort' && is_array($child)) {
            return true;
        }
        if (is_array($child) && wp_fts_frontier_node_has_active_filesort($child)) {
            return true;
        }
    }
    return false;
}

/** @param array<string|int,mixed> $node */
function wp_fts_frontier_node_has_temporary_table(array $node): bool
{
    foreach ($node as $key => $child) {
        if ($key === 'using_temporary_table' && $child === true) {
            return true;
        }
        if ($key === 'temporary_table' && is_array($child)) {
            return true;
        }
        if (is_array($child) && wp_fts_frontier_node_has_temporary_table($child)) {
            return true;
        }
    }
    return false;
}

/** @param int[] $postIds */
function wp_fts_frontier_seed_target_fixture(
    mysqli $db,
    string $termsTable,
    string $postingsTable,
    string $documentsTable,
    array $postIds,
    int $termCountPerPost,
    int $lexicalTermCountPerPost
): void {
    $offsetByPostId = array_flip($postIds);
    foreach (array_chunk($postIds, 10) as $postChunk) {
        wp_fts_frontier_query($db, 'START TRANSACTION');
        try {
            foreach ($postChunk as $postId) {
                $absoluteDocumentOffset = $offsetByPostId[$postId] ?? null;
                if (!is_int($absoluteDocumentOffset)) {
                    throw new RuntimeException('Could not resolve a deterministic fixture document offset.');
                }
                $termRows = [];
                $postingRows = [];
                for ($termOffset = 0; $termOffset < $termCountPerPost; $termOffset++) {
                    $termId = ($absoluteDocumentOffset * $termCountPerPost) + $termOffset + 1;
                    $kind = $termOffset < $lexicalTermCountPerPost ? 0 : 1;
                    $kindOffset = $kind === 0 ? $termOffset : $termOffset - $lexicalTermCountPerPost;
                    $term = sprintf('d%03d%s%05d', $absoluteDocumentOffset, $kind === 0 ? 'l' : 'p', $kindOffset);
                    $termRows[] = '(' . $termId . ",'en',{$kind},'{$term}',1)";
                    $postingRows[] = '(' . $termId . ',' . $postId . ',1)';
                }
                wp_fts_frontier_query(
                    $db,
                    "INSERT INTO {$termsTable} (term_id,lang,kind,term,doc_freq) VALUES "
                    . implode(',', $termRows)
                );
                wp_fts_frontier_query(
                    $db,
                    "INSERT INTO {$postingsTable} (term_id,post_id,impact) VALUES "
                    . implode(',', $postingRows)
                );
            }
            wp_fts_frontier_query($db, 'COMMIT');
        } catch (Throwable $error) {
            $db->query('ROLLBACK');
            throw $error;
        }
    }
    $documentRows = array_map(
        static fn(int $postId): string => "({$postId},'en','" . sha1("fixture:{$postId}") . "','',1)",
        $postIds
    );
    wp_fts_frontier_query(
        $db,
        "INSERT INTO {$documentsTable} (post_id,primary_lang,content_hash,snippet_text,indexed_at) VALUES "
        . implode(',', $documentRows)
    );
}

/** @param int[] $targetPostIds @param int[] $survivorPostIds */
function wp_fts_frontier_seed_shared_fixture(
    mysqli $db,
    string $termsTable,
    string $postingsTable,
    string $documentsTable,
    array $targetPostIds,
    array $survivorPostIds,
    int $firstTermId,
    int $termCountPerPost,
    int $lexicalTermCountPerPost
): void {
    wp_fts_frontier_assert(
        count($targetPostIds) === count($survivorPostIds) && $targetPostIds !== [],
        'The shared survivor fixture requires matching target and survivor documents.'
    );
    wp_fts_frontier_query($db, 'START TRANSACTION');
    try {
        foreach ($targetPostIds as $documentOffset => $targetPostId) {
            $survivorPostId = $survivorPostIds[$documentOffset];
            $termRows = [];
            $postingRows = [];
            for ($termOffset = 0; $termOffset < $termCountPerPost; $termOffset++) {
                $termId = $firstTermId + ($documentOffset * $termCountPerPost) + $termOffset;
                $kind = $termOffset < $lexicalTermCountPerPost ? 0 : 1;
                $kindOffset = $kind === 0 ? $termOffset : $termOffset - $lexicalTermCountPerPost;
                $term = sprintf('s%02d%s%05d', $documentOffset, $kind === 0 ? 'l' : 'p', $kindOffset);
                $termRows[] = '(' . $termId . ",'en',{$kind},'{$term}',2)";
                $postingRows[] = "({$termId},{$targetPostId},1)";
                $postingRows[] = "({$termId},{$survivorPostId},1)";
            }
            wp_fts_frontier_query(
                $db,
                "INSERT INTO {$termsTable} (term_id,lang,kind,term,doc_freq) VALUES "
                . implode(',', $termRows)
            );
            wp_fts_frontier_query(
                $db,
                "INSERT INTO {$postingsTable} (term_id,post_id,impact) VALUES "
                . implode(',', $postingRows)
            );
        }
        $documentRows = [];
        foreach ([...$targetPostIds, ...$survivorPostIds] as $postId) {
            $documentRows[] = "({$postId},'en','" . sha1("shared-fixture:{$postId}") . "','',1)";
        }
        wp_fts_frontier_query(
            $db,
            "INSERT INTO {$documentsTable} (post_id,primary_lang,content_hash,snippet_text,indexed_at) VALUES "
            . implode(',', $documentRows)
        );
        wp_fts_frontier_query($db, 'COMMIT');
    } catch (Throwable $error) {
        $db->query('ROLLBACK');
        throw $error;
    }
}

/** @return string[] */
function wp_fts_frontier_expected_reset_sql(string $prefix, int $nextEpoch, string $nextIncarnation): array
{
    $current = [
        $prefix . 'fts_terms',
        $prefix . 'fts_postings',
        $prefix . 'fts_documents',
        $prefix . 'fts_work',
    ];
    $staging = [];
    $retired = [];
    foreach ($current as $table) {
        $staging[$table] = wp_fts_frontier_reset_table_name($table, 'new');
        $retired[$table] = wp_fts_frontier_reset_table_name($table, 'old');
    }
    $quote = static fn(string $table): string => '`' . $table . '`';
    $stale = [];
    foreach ([$staging, $retired] as $generation) {
        foreach ($current as $table) {
            $stale[] = $quote($generation[$table]);
        }
    }
    $sql = [
        "SELECT generation FROM {$current[3]} WHERE job_key = 'meta:search-epoch' AND kind = 'meta' LIMIT 1",
        'DROP TABLE IF EXISTS ' . implode(', ', $stale),
    ];
    foreach ($current as $table) {
        $sql[] = 'CREATE TABLE ' . $quote($staging[$table]) . ' LIKE ' . $quote($table);
    }
    $sql[] = 'INSERT INTO ' . $quote($staging[$current[3]]) . "
    (job_key, kind, post_id, generation, state, available_at, attempts, claim_token, claimed_generation, claim_expires_at, cursor_post_id, scope_subject_type, scope_subject_id, payload, last_error_code, last_error_at)
VALUES ('meta:search-epoch', 'meta', 0, {$nextEpoch}, 'meta', 0, 0, '', 0, 0, 0, '', 0, '{$nextIncarnation}', '', 0)";
    $renames = [];
    foreach ($current as $table) {
        $renames[] = $quote($table) . ' TO ' . $quote($retired[$table]);
        $renames[] = $quote($staging[$table]) . ' TO ' . $quote($table);
    }
    $sql[] = 'RENAME TABLE ' . implode(', ', $renames);
    $sql[] = 'DROP TABLE IF EXISTS ' . implode(', ', array_map(
        static fn(string $table): string => $quote($retired[$table]),
        $current
    ));

    return $sql;
}

/** Derive collision-resistant reset names that remain within MySQL's 64-byte limit. */
function wp_fts_frontier_reset_table_name(string $table, string $role): string
{
    $suffix = '_r' . ($role === 'new' ? 'n' : 'o')
        . '_' . substr(hash('sha256', $table . '|' . $role), 0, 10);

    return substr($table, 0, 64 - strlen($suffix)) . $suffix;
}

/** @param string[] $tables @return array<string,mixed> */
function wp_fts_frontier_schema_evidence(mysqli $db, array $tables): array
{
    [$termsTable, $postingsTable, $documentsTable, $workTable] = $tables;
    $expectedColumns = [
        $termsTable => ['term_id', 'lang', 'kind', 'term', 'doc_freq'],
        $postingsTable => ['term_id', 'post_id', 'impact'],
        $documentsTable => ['post_id', 'primary_lang', 'content_hash', 'snippet_text', 'indexed_at'],
        $workTable => ['job_key', 'kind', 'post_id', 'generation', 'state', 'available_at', 'attempts', 'claim_token', 'claimed_generation', 'claim_expires_at', 'cursor_post_id', 'scope_coverage', 'scope_incarnation', 'scope_subject_type', 'scope_subject_id', 'payload', 'last_error_code', 'last_error_at'],
    ];
    $expectedIndexes = [
        $termsTable => [
            'PRIMARY' => ['unique' => true, 'columns' => ['term_id']],
            'empty_terms' => ['unique' => false, 'columns' => ['doc_freq']],
            'term_identity' => ['unique' => true, 'columns' => ['lang', 'kind', 'term']],
        ],
        $postingsTable => [
            'PRIMARY' => ['unique' => true, 'columns' => ['term_id', 'post_id']],
            'post_term' => ['unique' => false, 'columns' => ['post_id', 'term_id']],
        ],
        $documentsTable => [
            'PRIMARY' => ['unique' => true, 'columns' => ['post_id']],
        ],
        $workTable => [
            'PRIMARY' => ['unique' => true, 'columns' => ['job_key']],
            'claim_token' => ['unique' => false, 'columns' => ['claim_token', 'post_id']],
            'dirty' => ['unique' => false, 'columns' => ['post_id', 'kind']],
            'kind_job' => ['unique' => false, 'columns' => ['kind', 'job_key']],
            'ready' => ['unique' => false, 'columns' => ['kind', 'state', 'available_at', 'post_id', 'job_key']],
            'recoverable' => ['unique' => false, 'columns' => ['kind', 'state', 'claim_expires_at', 'available_at', 'post_id', 'job_key']],
            'scope_subject' => ['unique' => false, 'columns' => ['kind', 'scope_coverage', 'scope_subject_type', 'scope_subject_id']],
        ],
    ];
    foreach ($expectedIndexes as &$tableIndexes) {
        ksort($tableIndexes, SORT_STRING);
    }
    unset($tableIndexes);
    ksort($expectedColumns, SORT_STRING);
    ksort($expectedIndexes, SORT_STRING);

    $quotedTables = array_map(
        static fn(string $table): string => "'" . $db->real_escape_string($table) . "'",
        $tables
    );
    $tableList = implode(',', $quotedTables);
    $engines = [];
    $engineResult = $db->query(
        'SELECT table_name AS table_name, engine AS engine FROM information_schema.tables'
        . ' WHERE table_schema = DATABASE() AND table_name IN (' . $tableList . ')'
        . ' ORDER BY table_name'
    );
    if (!$engineResult instanceof mysqli_result) {
        throw new RuntimeException('Could not inspect reset table engines: ' . $db->error);
    }
    while ($row = $engineResult->fetch_assoc()) {
        $engines[(string) ($row['table_name'] ?? '')] = strtolower((string) ($row['engine'] ?? ''));
    }
    $engineResult->free();
    ksort($engines, SORT_STRING);

    $columns = [];
    $columnResult = $db->query(
        'SELECT table_name AS table_name, column_name AS column_name FROM information_schema.columns'
        . ' WHERE table_schema = DATABASE() AND table_name IN (' . $tableList . ')'
        . ' ORDER BY table_name, ordinal_position'
    );
    if (!$columnResult instanceof mysqli_result) {
        throw new RuntimeException('Could not inspect reset table columns: ' . $db->error);
    }
    while ($row = $columnResult->fetch_assoc()) {
        $columns[(string) ($row['table_name'] ?? '')][] = (string) ($row['column_name'] ?? '');
    }
    $columnResult->free();
    ksort($columns, SORT_STRING);

    $indexes = [];
    $indexResult = $db->query(
        'SELECT table_name AS table_name, index_name AS index_name, non_unique AS non_unique,'
        . ' seq_in_index AS seq_in_index, column_name AS column_name'
        . ' FROM information_schema.statistics'
        . ' WHERE table_schema = DATABASE() AND table_name IN (' . $tableList . ')'
        . ' ORDER BY table_name, index_name, seq_in_index'
    );
    if (!$indexResult instanceof mysqli_result) {
        throw new RuntimeException('Could not inspect reset table indexes: ' . $db->error);
    }
    while ($row = $indexResult->fetch_assoc()) {
        $table = (string) ($row['table_name'] ?? '');
        $index = (string) ($row['index_name'] ?? '');
        $indexes[$table][$index]['unique'] = (int) ($row['non_unique'] ?? 1) === 0;
        $indexes[$table][$index]['columns'][] = (string) ($row['column_name'] ?? '');
    }
    $indexResult->free();
    foreach ($indexes as &$tableIndexes) {
        ksort($tableIndexes, SORT_STRING);
    }
    unset($tableIndexes);
    ksort($indexes, SORT_STRING);

    $expectedEngines = array_fill_keys($tables, 'innodb');
    ksort($expectedEngines, SORT_STRING);
    wp_fts_frontier_assert($engines === $expectedEngines, 'Atomic reset did not preserve InnoDB on every current table.');
    wp_fts_frontier_assert($columns === $expectedColumns, 'Atomic reset did not preserve the exact current columns.');
    wp_fts_frontier_assert($indexes === $expectedIndexes, 'Atomic reset did not preserve the exact current indexes.');

    return [
        'engines' => $engines,
        'columns' => $columns,
        'indexes' => $indexes,
        'exact_current_contract' => true,
    ];
}

/** @return string[] */
function wp_fts_frontier_prefix_tables(mysqli $db, string $prefix): array
{
    $like = str_replace(['=', '%', '_'], ['==', '=%', '=_'], $prefix) . '%';
    $result = $db->query(
        "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()"
        . " AND table_name LIKE '" . $db->real_escape_string($like) . "' ESCAPE '=' ORDER BY table_name"
    );
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('Could not enumerate frontier fixture tables: ' . $db->error);
    }
    $tables = [];
    while ($row = $result->fetch_row()) {
        $tables[] = (string) ($row[0] ?? '');
    }
    $result->free();

    return $tables;
}

/** Read Linux peak RSS for the low-host memory gate, or zero when unavailable. */
function wp_fts_frontier_linux_vmhwm_bytes(): int
{
    $lines = @file('/proc/self/status', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return 0;
    }
    foreach ($lines as $line) {
        if (!str_starts_with($line, 'VmHWM:')) {
            continue;
        }
        $parts = array_values(array_filter(explode(' ', trim(substr($line, strlen('VmHWM:'))))));
        if (count($parts) !== 2 || !wp_fts_frontier_is_ascii_digits($parts[0]) || strtolower($parts[1]) !== 'kb') {
            return 0;
        }
        return (int) $parts[0] * 1024;
    }

    return 0;
}

/** Install the exact four-table contract used by the old-posting proof. */
function wp_fts_frontier_create_schema(
    WP_FTS_Frontier_WPDB $db,
    string $terms,
    string $postings,
    string $documents,
    string $work
): void {
    $statements = [
        "CREATE TABLE {$terms} (
term_id bigint unsigned NOT NULL AUTO_INCREMENT,
lang varbinary(32) NOT NULL,
kind tinyint unsigned NOT NULL DEFAULT 0,
term varbinary(255) NOT NULL,
doc_freq int unsigned NOT NULL DEFAULT 0,
PRIMARY KEY (term_id), UNIQUE KEY term_identity (lang,kind,term),
KEY empty_terms (doc_freq)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=binary",
        "CREATE TABLE {$postings} (
term_id bigint unsigned NOT NULL, post_id bigint unsigned NOT NULL, impact smallint unsigned NOT NULL,
PRIMARY KEY (term_id,post_id), KEY post_term (post_id,term_id)
) ENGINE=InnoDB DEFAULT CHARSET=binary",
        "CREATE TABLE {$documents} (
post_id bigint unsigned NOT NULL, primary_lang varbinary(32) NOT NULL DEFAULT 'und',
content_hash varbinary(40) NOT NULL, snippet_text mediumtext NOT NULL, indexed_at bigint unsigned NOT NULL DEFAULT 0,
PRIMARY KEY (post_id), KEY document_presence (post_id,indexed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE {$work} (
job_key varbinary(191) NOT NULL, kind varchar(16) NOT NULL, post_id bigint unsigned NOT NULL DEFAULT 0,
generation bigint unsigned NOT NULL DEFAULT 1, state varchar(12) NOT NULL DEFAULT 'pending',
available_at bigint unsigned NOT NULL DEFAULT 0, attempts int unsigned NOT NULL DEFAULT 0,
claim_token varchar(64) NOT NULL DEFAULT '', claimed_generation bigint unsigned NOT NULL DEFAULT 0,
claim_expires_at bigint unsigned NOT NULL DEFAULT 0, cursor_post_id bigint unsigned NOT NULL DEFAULT 0,
scope_coverage varchar(12) NOT NULL DEFAULT '', scope_incarnation varbinary(32) NOT NULL DEFAULT '',
scope_subject_type varchar(24) NOT NULL DEFAULT '', scope_subject_id bigint unsigned NOT NULL DEFAULT 0,
payload longtext NULL, last_error_code varchar(64) NOT NULL DEFAULT '', last_error_at bigint unsigned NOT NULL DEFAULT 0,
PRIMARY KEY (job_key),
KEY ready (kind,state,available_at,post_id,job_key),
KEY recoverable (kind,state,claim_expires_at,available_at,post_id,job_key),
KEY claim_token (claim_token,post_id),
KEY kind_job (kind,job_key),
KEY scope_subject (kind,scope_coverage,scope_subject_type,scope_subject_id),
KEY dirty (post_id,kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($statements as $statement) {
        if ($db->query($statement) === false) {
            throw new RuntimeException('Could not create the frontier fixture schema: ' . $db->last_error);
        }
    }
}

/** Fail the fixture immediately instead of allowing a partial seed state. */
function wp_fts_frontier_query(mysqli $db, string $sql): void
{
    if ($db->query($sql) === false) {
        throw new RuntimeException('Frontier fixture SQL failed: ' . $db->error);
    }
}

/** Promote a failed proof invariant to the enclosing cleanup-aware error path. */
function wp_fts_frontier_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Require every wrapper binding that makes the destructive proof disposable. */
function wp_fts_frontier_env(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException("{$name} is required for the old-posting frontier proof.");
    }
    return $value;
}

/** Accepts only a nonempty sequence of ASCII decimal digits. */
function wp_fts_frontier_is_ascii_digits(string $value): bool
{
    return $value !== '' && strspn($value, '0123456789') === strlen($value);
}

/** Accepts only a nonempty sequence of ASCII hexadecimal digits. */
function wp_fts_frontier_is_ascii_hex(string $value): bool
{
    return $value !== '' && strspn($value, '0123456789abcdefABCDEF') === strlen($value);
}

/** Accept only the lowercase digest representation written to evidence. */
function wp_fts_frontier_is_sha256(string $value): bool
{
    return strlen($value) === 64
        && wp_fts_frontier_is_ascii_hex($value)
        && strtolower($value) === $value;
}

/** Convert a monotonic start sample into statement-duration milliseconds. */
function wp_fts_frontier_elapsed_ms(int $started): float
{
    return (hrtime(true) - $started) / 1_000_000;
}
