<?php
declare(strict_types=1);

/** @return array{doc_id:int,primary_lang:string,content_hash:string,snippet_text:string,term_frequencies:array<string,int>,surface_frequencies:array<string,int>} */
function wp_fts_writer_document(
    int $docId,
    string $language,
    array $terms,
    array $surfaces = [],
    ?string $contentHash = null,
    string $snippetText = ''
): array {
    return [
        'doc_id' => $docId,
        'primary_lang' => $language,
        'content_hash' => $contentHash ?? sha1('writer-document:' . $docId),
        'snippet_text' => $snippetText,
        'term_frequencies' => $terms,
        'surface_frequencies' => $surfaces,
    ];
}

test_case('relational writer rejects one poison document before building an oversized dictionary statement', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $storage = new WP_FTS_Relational_Storage($wpdb);
    $terms = [];
    for ($index = 0; $index < 4097; $index++) {
        $terms[WP_FTS_TermNamespace::namespace_term('en', 'poison' . $index)] = 1;
    }

    $rejection = null;
    try {
        $storage->replace_prepared_documents([wp_fts_writer_document(41, 'en', $terms)]);
    } catch (WP_FTS_Prepared_Document_Rejected $error) {
        $rejection = $error;
    }

    assert_true($rejection instanceof WP_FTS_Prepared_Document_Rejected, 'one 4097-term document should be a permanent poison-document rejection');
    assert_same(41, $rejection->post_id, 'term-limit rejection should identify the poison post');
    assert_same('term_limit', $rejection->reason_code, 'term-limit rejection should expose a stable non-retryable reason');
    assert_same([], $wpdb->queries, 'one oversized document must be rejected before transaction or dictionary SQL');
});

test_case('relational writer exact posting boundary uses one memory-safe INSERT', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $wpdb->recordReadQueries = true;
    $storage = new WP_FTS_Relational_Storage($wpdb);
    $terms = [];
    for ($index = 0; $index < 500; $index++) {
        $terms[WP_FTS_TermNamespace::namespace_term('en', 'sharedboundary' . $index)] = 1;
    }
    $documents = [];
    for ($postId = 1; $postId <= 100; $postId++) {
        $documents[] = wp_fts_writer_document($postId, 'en', $terms, [], null, 'bounded');
    }

    $result = $storage->replace_prepared_documents($documents);
    $postingWrites = array_values(array_filter(
        $wpdb->queries,
        static fn(mixed $query): bool => is_string($query)
            && str_starts_with($query, 'INSERT INTO wp_fts_postings')
    ));
    assert_same(WP_FTS_Relational_Storage::MAX_BATCH_POSTINGS, $result['postings'], 'the exact batch boundary should retain all 50,000 postings');
    assert_same(1, count($postingWrites), 'the largest valid batch must issue exactly one posting INSERT');
    assert_true(
        strlen($postingWrites[0]) < 4194304,
        'the exact-boundary posting INSERT must remain below the fixed 4 MiB packet ceiling'
    );
    $dictionaryIdReads = array_values(array_filter(
        $wpdb->queries,
        static function (mixed $query): bool {
            $sql = is_array($query) ? (string) ($query[0] ?? '') : (string) $query;
            return str_starts_with($sql, '/* wp_fts:resolve-prepared-terms */');
        }
    ));
    $dictionaryIdSql = is_array($dictionaryIdReads[0] ?? null)
        ? (string) ($dictionaryIdReads[0][0] ?? '')
        : (string) ($dictionaryIdReads[0] ?? '');
    assert_same(1, count($dictionaryIdReads), 'the largest valid batch should resolve its dictionary ids once');
    assert_contains('stored_term.term_id', $dictionaryIdSql, 'dictionary-id SQL must use a non-reserved table alias on MySQL and MariaDB');
    assert_true(!str_contains($dictionaryIdSql, ' stored.'), 'dictionary-id SQL must never emit the reserved stored alias');
    $dfWrites = array_values(array_filter(
        $wpdb->prepared,
        static fn(array $prepared): bool => str_contains($prepared['sql'], 'wp_fts:dictionary-decrement')
    ));
    assert_same(0, count($dfWrites), 'a fresh batch must not issue an empty old-posting decrement');
    assert_same(0, count(array_filter(
        $wpdb->queries,
        static fn(mixed $query): bool => is_string($query)
            && str_contains($query, 'wp_fts:bounded-index-delete')
    )), 'a fresh batch must not issue an empty old-index retirement');
    $dictionaryWrites = array_values(array_filter(
        $wpdb->queries,
        static fn(mixed $query): bool => is_string($query)
            && str_starts_with($query, 'INSERT INTO wp_fts_terms')
            && str_contains($query, 'wp_fts:dictionary-increment')
    ));
    assert_same(1, count($dictionaryWrites), 'the new posting set should merge all bounded DF increments in one VALUES UPSERT');
    assert_contains('doc_freq = doc_freq + VALUES(doc_freq)', $dictionaryWrites[0], 'the dictionary VALUES UPSERT should add only the bounded new-document counts');
    assert_same(0, count(array_filter(
        $wpdb->prepared,
        static fn(array $prepared): bool => str_starts_with($prepared['sql'], 'DELETE FROM wp_fts_terms WHERE doc_freq = 0')
    )), 'the prepared MySQL transaction must not add an independent dictionary-cleanup statement');
    assert_true(count($wpdb->queries) <= 10, 'the exact-boundary fresh transaction must remain at ten or fewer statements');
});

test_case('relational writer resolves the maximum identity set in one packet-safe read', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $wpdb->recordReadQueries = true;
    $storage = new WP_FTS_Relational_Storage($wpdb);
    $lexical = [];
    for ($index = 0; $index < WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_TERMS; $index++) {
        $surface = str_repeat("'", 249) . 'l' . str_pad((string) $index, 5, '0', STR_PAD_LEFT);
        $lexical[WP_FTS_TermNamespace::namespace_term('en', $surface)] = 1;
    }
    $surfaces = [];
    for ($index = 0; $index < WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES; $index++) {
        $surface = str_pad('p' . str_pad((string) $index, 4, '0', STR_PAD_LEFT), 255, 'x');
        $surfaces[WP_FTS_TermNamespace::namespace_term('en', $surface)] = 1;
    }

    $result = $storage->replace_prepared_documents([
        wp_fts_writer_document(7001, 'en', $lexical, $surfaces),
    ]);
    $dictionaryIdReads = array_values(array_filter(
        $wpdb->queries,
        static function (mixed $query): bool {
            $sql = is_array($query) ? (string) ($query[0] ?? '') : (string) $query;
            return str_starts_with($sql, '/* wp_fts:resolve-prepared-terms */');
        }
    ));
    $dictionaryWrites = array_values(array_filter(
        $wpdb->queries,
        static fn(mixed $query): bool => is_string($query)
            && str_starts_with($query, 'INSERT INTO wp_fts_terms')
            && str_contains($query, 'wp_fts:dictionary-increment')
    ));

    assert_same(8192, $result['postings'] ?? null, 'one document should retain all 8,192 maximum-width lexical and normalized-surface identities');
    assert_same(1, count($dictionaryWrites), 'all 8,192 maximum-width quote-heavy identities must use one dictionary increment');
    assert_true(strlen($dictionaryWrites[0]) < 4194304, 'the maximum quote-heavy dictionary increment must remain below the hard 4 MiB statement ceiling');
    assert_contains('FROM_BASE64(', $dictionaryWrites[0], 'the dictionary increment must encode identity bytes independently of SQL escaping');
    assert_same(1, count($dictionaryIdReads), 'all 8,192 maximum-width identities must resolve in one compact indexed read');
    foreach ($dictionaryIdReads as $statement) {
        $sql = is_array($statement) ? (string) ($statement[0] ?? '') : (string) $statement;
        assert_true(strlen($sql) < 4194304, 'every dictionary-id read must remain below the hard 4 MiB statement ceiling');
        assert_contains('stored_term FORCE INDEX (term_identity)', $sql, 'dictionary-id reads must remain exact unique-identity joins');
    }
});

test_case('mixed scope and maximum-identity post work alternates inside the worker statement ceiling', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->options = 'wp_options';
    $fake->recordReadQueries = true;
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;

    $tokens = [];
    for ($index = 0; $index < WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_TERMS; $index++) {
        $tokens[] = 'mixedboundary' . str_pad((string) $index, 4, '0', STR_PAD_LEFT);
    }
    $changedPost = wp_fts_test_backfill_post(1);
    $changedPost->post_title = '';
    $changedPost->post_content = '<p>' . implode(' ', $tokens) . '</p>';
    $scopePost = wp_fts_test_backfill_post(2, 'post', 'publish', 'Later scope page');
    $fake->postRows = [$changedPost, $scopePost];
    $GLOBALS['wp_fts_test_posts'][1] = $changedPost;
    $GLOBALS['wp_fts_test_posts'][2] = $scopePost;

    $queue = new WP_FTS_Index_Queue($fake);
    $queue->enqueue_many([1], null, ['index_options' => ['document_lang' => 'en']]);
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION]['reconciliation_scope_completed_at'] = '';
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION]['reconciliation_scope_completed_incarnation'] = '';
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION]['reconciliation_scope_completed_profile_hash'] = '';
    wp_fts_test_seed_scope($fake, 'mixed-maximum-identity-scope');
    $scopeKey = '';
    foreach ($fake->queue as $jobKey => &$row) {
        if (($row['kind'] ?? '') === 'scope') {
            $scopeKey = (string) $jobKey;
            $row['cursor_post_id'] = 1;
            break;
        }
    }
    unset($row);
    assert_true($scopeKey !== '', 'the mixed worker fixture should retain one nonterminal scope generation');
    $scopeBefore = $fake->queue[$scopeKey];
    $fake->queries = [];
    $fake->prepared = [];

    try {
        $summary = WP_FTS_Plugin::process_manual_index_batch([
            'batch_size' => 100,
            'source' => 'mixed-maximum-identity-contract',
        ]);
        $mixedStatements = $fake->queries;
        $scopeAfterPost = $fake->queue[$scopeKey] ?? null;
        $postingCountAfterMaximum = array_sum(array_map('count', $fake->postings));

        $scopeSummary = WP_FTS_Plugin::process_manual_index_batch([
            'batch_size' => 100,
            'source' => 'mixed-maximum-identity-contract',
        ]);
        $completionBeforeExhaustedMixed = WP_FTS_Plugin::search_health()['reconciliation_scope_completed_at'] ?? '';
        $exhaustedMixed = WP_FTS_Plugin::process_manual_index_batch([
            'batch_size' => 100,
            'source' => 'mixed-maximum-identity-contract',
        ]);
        $scopeAfterExhaustedMixed = $fake->queue[$scopeKey] ?? null;
        $completionAfterExhaustedMixed = WP_FTS_Plugin::search_health()['reconciliation_scope_completed_at'] ?? '';
        $completion = WP_FTS_Plugin::process_manual_index_batch([
            'batch_size' => 100,
            'source' => 'mixed-maximum-identity-contract',
        ]);
        $completionAfterScopeOnly = WP_FTS_Plugin::search_health()['reconciliation_scope_completed_at'] ?? '';
    } finally {
        $wpdb = $oldWpdb;
    }

    $scopeYields = array_values(array_filter(
        $mixedStatements,
        static function (string|array $statement): bool {
            $sql = is_array($statement) ? (string) ($statement[0] ?? '') : $statement;

            return str_contains($sql, 'wp_fts:scope-yield-to-posts');
        }
    ));
    $resolutionReads = array_values(array_filter(
        $mixedStatements,
        static fn(string|array $statement): bool => str_starts_with(
            is_array($statement) ? (string) ($statement[0] ?? '') : $statement,
            '/* wp_fts:resolve-prepared-terms */'
        )
    ));
    $scopeSelectors = array_values(array_filter(
        $mixedStatements,
        static fn(string|array $statement): bool => str_contains(
            is_array($statement) ? (string) ($statement[0] ?? '') : $statement,
            'scope-page */'
        )
    ));
    $emptyTermCleanup = array_values(array_filter(
        $mixedStatements,
        static fn(string|array $statement): bool => str_contains(
            is_array($statement) ? (string) ($statement[0] ?? '') : $statement,
            'DELETE FROM wp_fts_terms WHERE doc_freq = 0'
        )
    ));

    assert_same(1, $summary['indexed'] ?? null, 'the mixed pass should commit its already-materialized changed document');
    assert_same(true, $summary['has_more'] ?? null, 'the released scope should request an immediate bounded successor');
    assert_same(8192, $postingCountAfterMaximum, 'the maximum document should retain all lexical and surface postings');
    assert_same(1, count($scopeYields), 'the mixed pass should replace scope expansion with one indexed turn-marked yield CAS');
    assert_same(1, count($resolutionReads), 'the literal maximum identity batch should use one compact packet-safe dictionary read');
    assert_same([], $scopeSelectors, 'a mixed pass must not append any scope selector or scope transaction');
    assert_same([], $emptyTermCleanup, 'has-more alternation should suppress terminal dictionary cleanup');
    assert_same(15, count($mixedStatements), 'the maximum mixed worker should stay five statements below the absolute twenty-statement ceiling');
    assert_same('ready', $scopeAfterPost['state'] ?? null, 'the deferred scope should return immediately to ready work');
    assert_same($scopeBefore['generation'] ?? null, $scopeAfterPost['generation'] ?? null, 'post-first alternation must preserve the scope generation');
    assert_same($scopeBefore['cursor_post_id'] ?? null, $scopeAfterPost['cursor_post_id'] ?? null, 'post-first alternation must not advance the scope cursor');
    assert_same($scopeBefore['payload'] ?? null, $scopeAfterPost['payload'] ?? null, 'post-first alternation must preserve the scope payload');
    assert_same(1, $scopeSummary['backfill_scanned'] ?? null, 'the immediate scope-only successor should scan exactly its remaining source row');
    assert_same(1, $scopeSummary['backfill_queued'] ?? null, 'the immediate scope-only successor should enqueue exactly one bounded page row');
    assert_same(1, $exhaustedMixed['indexed'] ?? null, 'the next mixed pass should drain the newly materialized post before proving scope EOF');
    assert_same(0, $exhaustedMixed['backfill_scanned'] ?? null, 'an exhausted mixed pass should issue no scope selector');
    assert_same(false, $exhaustedMixed['scope_completed'] ?? null, 'an exhausted mixed pass must not acknowledge scope authority beside document writes');
    assert_same(true, $exhaustedMixed['has_more'] ?? null, 'the exhausted deferred scope should request its scope-only completion pass');
    assert_same('ready', $scopeAfterExhaustedMixed['state'] ?? null, 'the exhausted deferred scope should remain ready and unacknowledged');
    assert_same(2, $scopeAfterExhaustedMixed['cursor_post_id'] ?? null, 'the exhausted deferred scope should preserve its last published cursor');
    assert_same('', $completionBeforeExhaustedMixed, 'the nonterminal scope page should not publish corpus completion');
    assert_same($completionBeforeExhaustedMixed, $completionAfterExhaustedMixed, 'mixed document work must not publish corpus completion without an EOF selector');
    assert_same(true, $completion['scope_completed'] ?? null, 'the scope-only EOF pass should acknowledge the exact corpus generation');
    assert_true($completionAfterScopeOnly !== '', 'the scope-only EOF pass should publish corpus completion provenance');
    assert_true(!isset($fake->queue[$scopeKey]), 'the acknowledged corpus scope should leave no hidden authority row');
});

test_case('relational writer preflights 100 maximum-old documents as one bounded complete prefix', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $wpdb->replacementFrontierPostingCounts = array_fill_keys(range(1, 100), WP_FTS_Relational_Storage::MAX_DOCUMENT_POSTINGS);
    $storage = new WP_FTS_Relational_Storage($wpdb);
    $deletions = array_fill_keys(range(1, 100), 0);

    $plan = $storage->plan_prepared_replacement($deletions);

    assert_same(range(1, 6), $plan->admitted_post_ids, 'six complete 8,192-row deletions should fit below the 50,000 mutation ceiling');
    assert_same(range(7, 100), $plan->deferred_post_ids, 'the partial seventh document and the whole ascending suffix must defer');
    assert_same(50001, $plan->scanned_old_postings, 'frontier discovery should stop after exactly the limit plus one indexed rows');
    assert_same(49152, $plan->posting_mutations, 'the admitted transaction should count only six complete old posting sets');
    assert_same(array_fill_keys(range(1, 6), WP_FTS_Relational_Storage::MAX_DOCUMENT_POSTINGS), $plan->old_posting_counts, 'the plan should carry only complete per-document counts into storage');
    $frontierReads = array_values(array_filter(
        $wpdb->prepared,
        static fn(array $prepared): bool => str_starts_with($prepared['sql'], '/* wp_fts:replacement-frontier */')
    ));
    assert_same(1, count($frontierReads), 'one claimed batch should issue exactly one old-posting frontier query');
    assert_contains('FORCE INDEX (post_term)', $frontierReads[0]['sql'], 'MySQL frontier discovery must use the post-first covering index');
    assert_contains('LIMIT 50001', $frontierReads[0]['sql'], 'the indexed inner scan must have the hard limit-plus-one row ceiling');
    assert_contains('COUNT(*) AS posting_count', $frontierReads[0]['sql'], 'the outer query must return per-post aggregates rather than posting rows');
    assert_same([], $wpdb->queries, 'frontier planning must defer the suffix before opening a transaction');

    $result = $storage->replace_prepared_documents([], $plan->admitted_post_ids, $plan);
    assert_same(49152, $result['old_postings'] ?? null, 'storage should consume the measured old count without rescanning');
    assert_same(49152, $result['posting_mutations'] ?? null, 'the transaction should expose its exact bounded posting mutation count');
    assert_same(1, count(array_filter(
        $wpdb->prepared,
        static fn(array $prepared): bool => str_starts_with($prepared['sql'], '/* wp_fts:replacement-frontier */')
    )), 'consuming a measured prefix must not repeat the old-posting scan');
    $deleteWrites = array_values(array_filter(
        $wpdb->prepared,
        static fn(array $prepared): bool => str_starts_with(
            $prepared['sql'],
            'DELETE old_posting, retired_term, retired_document'
        )
    ));
    assert_same(1, count($deleteWrites), 'one measured prefix should use one combined three-target DELETE');
    assert_contains('/* wp_fts:bounded-index-delete */', $deleteWrites[0]['sql'], 'the bounded DELETE must retain its exact evidence tag');
    assert_contains(
        "FROM (\n    SELECT bounded_rows.post_id, bounded_rows.delete_document, bounded_rows.term_id\n    FROM (\n        SELECT affected.post_id",
        $deleteWrites[0]['sql'],
        'the bounded DELETE must retain both derived-table levels that force materialization'
    );
    assert_contains('LIMIT 50100', $deleteWrites[0]['sql'], 'the materialized delete driver must retain its proven posting-plus-document ceiling');
    assert_contains('candidate_posting FORCE INDEX (post_term)', $deleteWrites[0]['sql'], 'the materialized delete driver must scan only the post-first frontier');
    foreach (['old_posting', 'retired_term', 'retired_document'] as $targetAlias) {
        assert_contains("{$targetAlias} FORCE INDEX (PRIMARY)", $deleteWrites[0]['sql'], "the {$targetAlias} delete target must use primary-key lookups");
    }

    $replacementPlan = (new WP_FTS_Relational_Storage($wpdb))->plan_prepared_replacement(
        array_fill_keys(range(1, 100), WP_FTS_Relational_Storage::MAX_DOCUMENT_POSTINGS)
    );
    assert_same(range(1, 3), $replacementPlan->admitted_post_ids, 'old deletions plus 8,192 new rows should admit three whole documents');
    assert_same(49152, $replacementPlan->posting_mutations, 'combined old and new rows must share the same 50,000 ceiling');
});

test_case('direct relational writer callers auto-measure safe batches and get a pre-transaction typed split', function (): void {
    $safeWpdb = new WP_FTS_Test_WPDB();
    $safeWpdb->replacementFrontierPostingCounts = [11 => 8192, 12 => 8192];
    $safeStorage = new WP_FTS_Relational_Storage($safeWpdb);
    $safe = $safeStorage->replace_prepared_documents([], [11, 12]);
    assert_same(16384, $safe['old_postings'] ?? null, 'a direct caller should auto-measure and apply an entirely safe old-posting batch');
    assert_same(1, count(array_filter(
        $safeWpdb->prepared,
        static fn(array $prepared): bool => str_starts_with($prepared['sql'], '/* wp_fts:replacement-frontier */')
    )), 'direct safe replacement should issue exactly one bounded frontier read');

    $splitWpdb = new WP_FTS_Test_WPDB();
    $splitWpdb->replacementFrontierPostingCounts = array_fill_keys(range(1, 100), 8192);
    $splitStorage = new WP_FTS_Relational_Storage($splitWpdb);
    $split = null;
    try {
        $splitStorage->replace_prepared_documents([], range(1, 100));
    } catch (WP_FTS_Prepared_Batch_Split_Required $error) {
        $split = $error;
    }
    assert_true($split instanceof WP_FTS_Prepared_Batch_Split_Required, 'an oversized direct replacement should retain the existing typed aggregate split API');
    assert_same(100, $split?->document_count, 'the direct split should report every requested document');
    assert_same(6, $split?->split_after_documents, 'the direct split should identify the exact complete ascending prefix');
    assert_same(WP_FTS_Relational_Storage::MAX_BATCH_POSTINGS + 1, $split?->posting_count, 'the direct split should report the first proven over-limit posting mutation');
    assert_true(!in_array('START TRANSACTION', $splitWpdb->queries, true), 'the direct split must happen before BEGIN');
    assert_same(1, count(array_filter(
        $splitWpdb->prepared,
        static fn(array $prepared): bool => str_starts_with($prepared['sql'], '/* wp_fts:replacement-frontier */')
    )), 'the oversized direct call should still use only one limit-plus-one frontier read');
});

test_case('relational replacement plans are opaque exact-prefix capabilities', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $wpdb->replacementFrontierPostingCounts = [21 => 4096, 22 => 4096];
    $storage = new WP_FTS_Relational_Storage($wpdb);
    $plan = $storage->plan_prepared_replacement([21 => 0]);
    $forged = new WP_FTS_Prepared_Replacement_Plan(
        $plan->new_posting_counts,
        [21 => 0],
        $plan->admitted_post_ids,
        $plan->deferred_post_ids,
        0,
        0
    );

    $wpdb->queries = [];
    $forgedError = null;
    try {
        $storage->replace_prepared_documents([], [21], $forged);
    } catch (InvalidArgumentException $error) {
        $forgedError = $error;
    }
    assert_true($forgedError instanceof InvalidArgumentException, 'a caller-constructed plan must not understate measured old postings');
    assert_true(!in_array('START TRANSACTION', $wpdb->queries, true), 'a forged plan must reject before BEGIN');

    $wpdb->queries = [];
    $mismatchError = null;
    try {
        $storage->replace_prepared_documents([], [22], $plan);
    } catch (InvalidArgumentException $error) {
        $mismatchError = $error;
    }
    assert_true($mismatchError instanceof InvalidArgumentException, 'a genuine plan must remain bound to its exact post ids and new counts');
    assert_true(!in_array('START TRANSACTION', $wpdb->queries, true), 'a mismatched genuine plan must reject before BEGIN');

    $reuseWpdb = new WP_FTS_Test_WPDB();
    $reuseWpdb->replacementFrontierPostingCounts = [31 => 4096];
    $reuseStorage = new WP_FTS_Relational_Storage($reuseWpdb);
    $abandoned = $reuseStorage->plan_prepared_replacement([31 => 0]);
    $issuedPlansProperty = (new ReflectionClass($reuseStorage))->getProperty('issuedReplacementPlans');
    $issuedPlans = $issuedPlansProperty->getValue($reuseStorage);
    assert_same(1, count($issuedPlans), 'the storage should retain one opaque capability while its measured plan is live');
    $abandonedFields = [
        $abandoned->new_posting_counts,
        $abandoned->old_posting_counts,
        $abandoned->admitted_post_ids,
        $abandoned->deferred_post_ids,
        $abandoned->scanned_old_postings,
        $abandoned->posting_mutations,
    ];
    unset($abandoned);
    gc_collect_cycles();
    assert_same(0, count($issuedPlans), 'destroying an abandoned plan should retire its WeakMap capability');
    $fieldIdenticalForgery = new WP_FTS_Prepared_Replacement_Plan(...$abandonedFields);
    $reuseWpdb->queries = [];
    $fieldIdenticalError = null;
    try {
        $reuseStorage->replace_prepared_documents([], [31], $fieldIdenticalForgery);
    } catch (InvalidArgumentException $error) {
        $fieldIdenticalError = $error;
    }
    assert_true($fieldIdenticalError instanceof InvalidArgumentException, 'a field-identical replacement must not revive an abandoned plan capability');
    assert_true(!in_array('START TRANSACTION', $reuseWpdb->queries, true), 'a field-identical abandoned-plan forgery must reject before BEGIN');
});

test_case('prepared-document partition preserves the exact strict payload used by its replacement plan', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $storage = new WP_FTS_Relational_Storage($wpdb);
    $canonicalTerm = WP_FTS_TermNamespace::namespace_term('en', 'merged');
    $document = wp_fts_writer_document(
        44,
        'en',
        [$canonicalTerm => 5],
        [],
        sha1('partitioned-document'),
        'The normalized snippet survives writer revalidation.'
    );
    $partition = $storage->partition_prepared_documents([$document]);

    assert_same([], $partition['rejections'], 'a strict six-field document should pass partition validation');
    assert_same([$document], $partition['documents'], 'partitioning must preserve the exact validated writer payload');
    $plan = $storage->plan_prepared_replacement([44 => 1]);
    $result = $storage->replace_prepared_documents($partition['documents'], [], $plan);
    assert_same(1, $result['postings'] ?? null, 'the plan count and writer validation must describe the same one-row document');
    assert_same(
        'The normalized snippet survives writer revalidation.',
        (string) ($wpdb->docs[44]['snippet_text'] ?? ''),
        'writer validation must preserve the partitioned snippet'
    );
    assert_same(1, count(array_filter(
        $wpdb->prepared,
        static fn(array $prepared): bool => str_starts_with($prepared['sql'], '/* wp_fts:replacement-frontier */')
    )), 'validation before planning must retain the sole bounded frontier read');
});

test_case('production worker commits and acknowledges only the measured old-posting prefix', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->options = 'wp_options';
    $postIds = range(31001, 31100);
    $fake->replacementFrontierPostingCounts = array_fill_keys($postIds, WP_FTS_Relational_Storage::MAX_DOCUMENT_POSTINGS);
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    wp_fts_test_seed_queue($fake, $postIds);

    try {
        $summary = WP_FTS_Plugin::process_manual_index_batch([
            'batch_size' => 100,
            'source' => 'old-posting-frontier-contract',
        ]);

        assert_same(6, $summary['queue_processed'] ?? null, 'only six complete 8,192-posting deletions should commit and acknowledge');
        assert_same(6, $summary['deleted'] ?? null, 'the committed prefix should report six canonical deletions');
        assert_same(94, $summary['deferred'] ?? null, 'the whole suffix should return immediately to durable ready work');
        assert_same('posting_mutation_cap', $summary['stop_reason'] ?? null, 'diagnostics should distinguish the structural posting mutation ceiling');
        assert_same(94, count($fake->queue), 'deferred suffix generations must remain durable and claimable');
        assert_same(array_slice($postIds, 6), array_map('intval', array_keys($fake->queue)), 'the worker must preserve the exact ascending suffix');
        assert_same(1, count(array_filter(
            $fake->prepared,
            static fn(array $prepared): bool => str_starts_with($prepared['sql'], '/* wp_fts:replacement-frontier */')
        )), 'the worker should measure one exact post-analysis old-posting frontier');
        assert_same(1, count(array_filter(
            $fake->queries,
            static fn(string|array $query): bool => is_string($query) && $query === 'START TRANSACTION'
        )), 'the worker should publish derived rows, epoch, acknowledgement, and lease retirement in one transaction');
        assert_true(count($fake->queries) <= 20, 'the complete synthetic production changed/deletion batch must remain at twenty statements or fewer, including transaction and lease controls');
        $syntheticStatements = array_values(array_unique([
            ...array_values(array_filter($fake->queries, 'is_string')),
            ...array_map(static fn(array $prepared): string => (string) ($prepared['sql'] ?? ''), $fake->prepared),
        ]));
        foreach (['wp_fts:claim-batch', 'wp_fts:replacement-frontier', 'wp_fts:dictionary-decrement', 'wp_fts:bounded-index-delete', 'wp_fts:search-epoch-advance', 'wp_fts:atomic-worker-ack'] as $roleTag) {
            assert_same(1, count(array_filter(
                $syntheticStatements,
                static fn(string $query): bool => str_contains($query, $roleTag)
            )), "the synthetic production batch should retain exactly one {$roleTag} statement role");
        }
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('production worker partitions storage-invalid documents before the valid replacement phase', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->options = 'wp_options';
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $postIds = range(32001, 32100);
    foreach ($postIds as $postId) {
        $post = wp_fts_test_backfill_post($postId, 'post', 'publish', 'Storage boundary ' . $postId);
        $fake->postRows[] = $post;
        $GLOBALS['wp_fts_test_posts'][$postId] = $post;
    }
    wp_fts_test_seed_queue($fake, $postIds);
    $statementSequence = [];
    $fake->queryObserver = static function (string $sql) use (&$statementSequence): void {
        $statementSequence[] = $sql;
    };
    $fake->readQueryObserver = static function (string $sql) use (&$statementSequence): void {
        $statementSequence[] = $sql;
    };
    $validPostId = $postIds[array_key_last($postIds)];
    $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::POST_INDEX_OPTIONS_FILTER] =
        static function (array $options, object $post) use ($validPostId): array {
            if ((int) ($post->ID ?? 0) !== $validPostId) {
                // The analyzer accepts a long canonical language tag, while the
                // relational primary-language column intentionally does not.
                $options['document_lang'] = 'en-abcdefgh-abcdefgh-abcdefgh-abcdefgh';
            }

            return $options;
        };

    try {
        $summary = WP_FTS_Plugin::process_manual_index_batch([
            'batch_size' => 100,
            'source' => 'storage-poison-partition-contract',
        ]);
        $firstStatementSequence = $statementSequence;
        $firstFrontierReads = array_values(array_filter(
            $fake->prepared,
            static fn(array $prepared): bool => str_starts_with($prepared['sql'], '/* wp_fts:replacement-frontier */')
        ));

        $statementSequence = [];
        $successor = WP_FTS_Plugin::process_manual_index_batch([
            'batch_size' => 100,
            'source' => 'storage-poison-successor-contract',
        ]);
        $secondStatementSequence = $statementSequence;
        $secondFrontierReads = array_values(array_filter(
            $fake->prepared,
            static fn(array $prepared): bool => str_starts_with($prepared['sql'], '/* wp_fts:replacement-frontier */')
        ));
    } finally {
        unset($GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::POST_INDEX_OPTIONS_FILTER]);
        $wpdb = $oldWpdb;
    }

    $frontierOffset = array_search(
        true,
        array_map(static fn(string $sql): bool => str_starts_with($sql, '/* wp_fts:replacement-frontier */'), $firstStatementSequence),
        true
    );
    $publicationStatements = $frontierOffset === false ? [] : array_slice($firstStatementSequence, $frontierOffset + 1);
    assert_same(99, $summary['committed'] ?? null, 'the poison phase should acknowledge every independently invalid generation together');
    assert_same(99, $summary['permanently_rejected'] ?? null, 'every independently invalid storage document should be classified in the one PHP partition pass');
    assert_same(0, $summary['indexed'] ?? null, 'the rejection phase must not compose with an otherwise independent relational replacement');
    assert_same(1, $summary['deferred'] ?? null, 'the valid suffix should remain as immediate durable work for the bounded successor');
    assert_same(1, $successor['committed'] ?? null, 'the immediate successor should acknowledge the valid suffix');
    assert_same(1, $successor['indexed'] ?? null, 'the valid document after 99 poison documents must still be indexed');
    assert_same(0, $successor['permanently_rejected'] ?? null, 'the successor must not repeat settled poison generations');
    assert_same([], wp_fts_test_queue_ids($fake), 'the two bounded phases should converge without leaving a poison retry');
    assert_same([$validPostId], array_map('intval', array_keys($fake->docs)), 'only the valid suffix document should reach relational storage');
    assert_same(1, count($firstFrontierReads), 'the complete poison phase must produce exactly one replacement-frontier SELECT');
    assert_same(2, count($secondFrontierReads), 'the valid successor must add exactly one replacement-frontier SELECT');
    assert_same(1, count(array_filter(
        $publicationStatements,
        static fn(string $sql): bool => $sql === 'START TRANSACTION'
    )), 'after its sole replacement plan, the complete poison partition must use one bounded deletion/acknowledgement transaction');
    assert_true(count($firstStatementSequence) <= 20, 'the complete poison phase must stay inside the worker statement ceiling');
    assert_true(count($secondStatementSequence) <= 20, 'the valid successor phase must stay inside the worker statement ceiling');
});

test_case('relational writer splits disjoint vocabulary at the 8192-identity transaction boundary', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $storage = new WP_FTS_Relational_Storage($wpdb);
    $documents = [];
    for ($postId = 1; $postId <= 6; $postId++) {
        $terms = [];
        for ($index = 0; $index < 4096; $index++) {
            $terms[WP_FTS_TermNamespace::namespace_term('en', "doc{$postId}term{$index}")] = 1;
        }
        $documents[] = wp_fts_writer_document($postId, 'en', $terms);
    }

    $split = null;
    try {
        $storage->replace_prepared_documents($documents);
    } catch (WP_FTS_Prepared_Batch_Split_Required $error) {
        $split = $error;
    }

    assert_true($split instanceof WP_FTS_Prepared_Batch_Split_Required, 'six valid documents cross the 8,192-identity batch boundary at the third document');
    assert_same('terms', $split->limit_kind, 'typed split should identify the distinct-term constraint');
    assert_same(12288, $split->term_count, 'typed split should report the first projected distinct-identity overflow');
    assert_same(8192, $split->term_limit, 'typed split should expose the dictionary transaction limit');
    assert_same(2, $split->split_after_documents, 'term-bound split should preserve the largest valid document prefix');
    assert_contains('split after 2 documents', $split->getMessage(), 'operator-visible term split should agree with the typed boundary');
    assert_same([], $wpdb->queries, 'aggregate term overflow must be detected before transaction or dictionary SQL');
});

test_case_with_pdo_sqlite_fixture('relational writer replaces a 6000-term old union without returning it to PHP', function (): void {
    $wpdb = new WP_FTS_Relational_Regression_SQLite_WPDB();
    wp_fts_relational_regression_create_schema($wpdb);
    $storage = new WP_FTS_Relational_Storage($wpdb);
    for ($postId = 1; $postId <= 2; $postId++) {
        $terms = [];
        for ($index = 0; $index < 3000; $index++) {
            $terms[WP_FTS_TermNamespace::namespace_term('en', "old{$postId}term{$index}")] = 1;
        }
        $storage->replace_prepared_documents([
            wp_fts_writer_document($postId, 'en', $terms),
        ]);
    }

    $wpdb->queries = [];
    $shared = WP_FTS_TermNamespace::namespace_term('en', 'replacement');
    $result = $storage->replace_prepared_documents([
        wp_fts_writer_document(1, 'en', [$shared => 1], [], sha1('replacement-1')),
        wp_fts_writer_document(2, 'en', [$shared => 1], [], sha1('replacement-2')),
    ]);

    assert_same(2, $result['replaced'], 'the bounded delta writer should replace both disjoint old documents');
    assert_true(count($wpdb->queries) <= 12, 'the disjoint old-union replacement should remain at twelve or fewer total statements');
    assert_true(!str_contains(implode("\n", $wpdb->queries), "SELECT 'old' AS row_kind"), 'the batch writer must not transfer old term ids into PHP');
    assert_same(1, (int) $wpdb->dbh->query('SELECT COUNT(*) FROM wp_fts_terms')->fetchColumn(), 'the bounded hot transaction should retire every zero-frequency term from the old posting union');
    assert_same(1, (int) $wpdb->dbh->query('SELECT COUNT(*) FROM wp_fts_terms WHERE doc_freq > 0')->fetchColumn(), 'only the replacement dictionary row should remain live');
    assert_same(2, (int) $wpdb->dbh->query('SELECT MAX(doc_freq) FROM wp_fts_terms')->fetchColumn(), 'the replacement term should retain exact distinct-document frequency');
    assert_same(2, (int) $wpdb->dbh->query('SELECT COUNT(*) FROM wp_fts_postings')->fetchColumn(), 'the replacement should retain one compact posting per document');
    $retirementSql = implode("\n", $wpdb->queries);
    assert_contains('DELETE FROM wp_fts_terms', $retirementSql, 'SQLite should retire the bounded old dictionary union before deleting its postings');
    assert_contains('SELECT retired_posting.term_id', $retirementSql, 'SQLite dictionary retirement should derive ids from only the measured posting frontier');
});
