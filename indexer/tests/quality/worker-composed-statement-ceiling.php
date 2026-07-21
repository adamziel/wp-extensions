<?php
declare(strict_types=1);

test_case('maximum mixed worker composition stays inside the complete statement ceiling', function (): void {
    $scenario = wp_fts_test_run_composed_worker_ceiling(false);
    /** @var WP_FTS_Test_WPDB $fake */
    $fake = $scenario['fake'];
    $summary = $scenario['summary'];
    $roles = $scenario['roles'];

    assert_same(19, count($scenario['queries']), 'the strongest successful worker must use exactly nineteen direct wpdb statements');
    assert_same(15, count(array_filter(
        $roles,
        static fn(string $role): bool => !str_starts_with($role, 'lease_')
            && !str_starts_with($role, 'transaction_')
    )), 'the strongest successful worker must use exactly fifteen indexing/data statements');
    assert_same(1, $scenario['cron_write_count'], 'the strongest successful worker must persist one successor with one cron-option write');
    assert_same(20, count($scenario['queries']) + $scenario['cron_write_count'], 'the complete successful callback must use exactly twenty statements including cron persistence');
    assert_same([
        'lease_acquire',
        'claim_batch',
        'claim_source_snapshot',
        'conditional_source_fallback',
        'dependency_measurement',
        'dependency_values',
        'replacement_frontier',
        'transaction_start',
        'dictionary_increment',
        'dictionary_decrement',
        'bounded_index_delete',
        'resolve_prepared_terms',
        'posting_replacement',
        'document_replacement',
        'scope_yield_and_post_batch_release',
        'atomic_worker_ack',
        'search_epoch_advance',
        'transaction_commit',
        'lease_release',
    ], $roles, 'the strongest successful worker protocol must remain exact and ordered');
    assert_same('success', $summary['status'] ?? null, 'the strongest worker must succeed');
    assert_same(1, $summary['attempted'] ?? null, 'the strongest worker must attempt the admitted maximum document');
    assert_same(1, $summary['indexed'] ?? null, 'the strongest worker must process the admitted maximum document');
    assert_same(1, $summary['committed'] ?? null, 'the strongest worker must commit the admitted maximum document');
    assert_same(2, $summary['analyzed'] ?? null, 'the worker must reach the first over-frontier document before deferring the suffix');
    assert_same(5, $summary['deferred'] ?? null, 'the posting/identity frontier must defer all five near-limit suffix documents');
    assert_same(0, $summary['last_batch_failures'] ?? null, 'the strongest worker must not hide a failure');
    assert_same(true, $summary['resolved_failure_records'] ?? null, 'successful acknowledgement must report the conditioned prior content failure');
    assert_true($scenario['aggregate_source_bytes'] > WP_FTS_Index_Queue::MAX_SOURCE_SNAPSHOT_BYTES, 'the fixture must cross the complete source-snapshot limit');
    assert_same(1, $scenario['selected_dependency_rows'], 'the fixture must hydrate exactly one selected dependency value');
    assert_same(0, count(array_filter(
        $roles,
        static fn(string $role): bool => $role === 'health_state_cas'
    )), 'resolved diagnostics must not append a health CAS after the maximum transaction');
    assert_true(
        isset($fake->docs[1]) && ($fake->docs[1]['content_hash'] ?? 'old-hash') !== 'old-hash',
        'the admitted maximum document must replace its old frontier'
    );
    assert_true(!isset($fake->queue[1]), 'the admitted maximum generation must be acknowledged atomically');
    for ($postId = 2; $postId <= 6; $postId++) {
        assert_same('ready', $fake->queue[$postId]['state'] ?? null, "deferred post {$postId} must return to ready");
        assert_same('', $fake->queue[$postId]['claim_token'] ?? null, "deferred post {$postId} must release its claim token");
    }
    $scope = wp_fts_test_composed_worker_scope($fake);
    assert_same('ready', $scope['state'] ?? null, 'the mixed scope must return to ready');
    assert_same(WP_FTS_Index_Queue::SCOPE_EXPANSION_TURN_CODE, $scope['last_error_code'] ?? null, 'the mixed scope must reserve the next turn');
});

test_case('content failure settles before maximum writer work can compose with it', function (): void {
    $scenario = wp_fts_test_run_composed_worker_ceiling(true);
    /** @var WP_FTS_Test_WPDB $fake */
    $fake = $scenario['fake'];
    $summary = $scenario['summary'];
    $roles = $scenario['roles'];

    assert_same(9, count($scenario['queries']), 'the failure-only settlement must use exactly nine direct statements');
    assert_same(1, $scenario['cron_write_count'], 'the failure-only settlement must persist one successor');
    assert_true(count($scenario['queries']) + $scenario['cron_write_count'] <= 20, 'the complete failure callback must remain inside the absolute ceiling');
    assert_same([
        'lease_acquire',
        'claim_batch',
        'claim_source_snapshot',
        'conditional_source_fallback',
        'dependency_measurement',
        'post_batch_failure',
        'scope_yield_and_post_batch_release',
        'health_state_cas',
        'lease_release',
    ], $roles, 'the failure settlement protocol must remain exact and ordered');
    assert_same('failed', $summary['status'] ?? null, 'the injected content failure must be reported');
    assert_same(1, $summary['attempted'] ?? null, 'only the failing document may be attempted');
    assert_same(0, $summary['indexed'] ?? null, 'no document may publish after the failure');
    assert_same(1, $summary['retryable_failures'] ?? null, 'the exact failing generation must become retryable');
    assert_same(11, $summary['deferred'] ?? null, 'the maximum document and remaining suffix must be deferred together');
    assert_same(1, $summary['last_batch_failures'] ?? null, 'the failure summary must contain exactly one failure');
    assert_true($scenario['aggregate_source_bytes'] > WP_FTS_Index_Queue::MAX_SOURCE_SNAPSHOT_BYTES, 'the failure fixture must still cross the source-snapshot limit');
    assert_same('retry', $fake->queue[1]['state'] ?? null, 'the failing generation must enter retry state');
    assert_same('content_failure', $fake->queue[1]['last_error_code'] ?? null, 'the failing generation must retain its typed error');
    assert_same(1, $fake->queue[1]['attempts'] ?? null, 'the failing generation must increment attempts once');
    assert_same('old-hash', $fake->docs[2]['content_hash'] ?? null, 'maximum writer publication must not start after a content failure');
    foreach (['transaction_start', 'dictionary_increment', 'posting_replacement', 'document_replacement', 'transaction_commit'] as $forbiddenRole) {
        assert_same(0, count(array_filter(
            $roles,
            static fn(string $role): bool => $role === $forbiddenRole
        )), "the failure settlement must not append {$forbiddenRole}");
    }
    $scope = wp_fts_test_composed_worker_scope($fake);
    assert_same('ready', $scope['state'] ?? null, 'the failure settlement must return its mixed scope to ready');
    assert_same(WP_FTS_Index_Queue::SCOPE_EXPANSION_TURN_CODE, $scope['last_error_code'] ?? null, 'the failure settlement must reserve the next scope turn');
});

/**
 * Run either the strongest successful composition or its pre-writer failure
 * boundary against the production worker with the query-recording wpdb fake.
 *
 * @return array{
 *   fake:WP_FTS_Test_WPDB,
 *   summary:array<string,mixed>,
 *   queries:string[],
 *   roles:string[],
 *   cron_write_count:int,
 *   aggregate_source_bytes:int,
 *   selected_dependency_rows:int
 * }
 */
function wp_fts_test_run_composed_worker_ceiling(bool $injectFailure): array
{
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
        $tokens[] = 'composedidentity' . str_pad((string) $index, 4, '0', STR_PAD_LEFT);
    }
    $maximum = wp_fts_test_backfill_post($injectFailure ? 2 : 1);
    $maximum->post_title = '';
    $maximum->post_content = '<p>' . implode(' ', $tokens) . '</p>';
    $posts = [];
    if ($injectFailure) {
        $failure = wp_fts_test_backfill_post(1, 'post', 'publish', 'composed filter failure');
        $posts[] = $failure;
    }
    $posts[] = $maximum;
    $lastPostId = $injectFailure ? 12 : 6;
    for ($postId = (int) $maximum->ID + 1; $postId <= $lastPostId; $postId++) {
        $post = wp_fts_test_backfill_post($postId);
        $post->post_title = '';
        $post->post_content = wp_fts_test_composed_near_limit_source($postId);
        $posts[] = $post;
    }
    $fake->postRows = $posts;
    foreach ($posts as $post) {
        $GLOBALS['wp_fts_test_posts'][(int) $post->ID] = $post;
    }
    $maximumId = (int) $maximum->ID;
    $fake->docs[$maximumId] = [
        'post_id' => $maximumId,
        'primary_lang' => 'en',
        'lang' => 'en',
        'doc_len' => WP_FTS_Relational_Storage::MAX_DOCUMENT_POSTINGS,
        'content_hash' => 'old-hash',
        'snippet_text' => 'old',
        'indexed_at' => 1,
        'is_deleted' => 0,
    ];
    $fake->replacementFrontierPostingCounts[$maximumId] = WP_FTS_Relational_Storage::MAX_DOCUMENT_POSTINGS;
    $selectedDependencyRows = 0;
    $payload = ['index_options' => ['document_lang' => 'en']];
    if (!$injectFailure) {
        $GLOBALS['wp_fts_test_post_meta'][$maximumId]['subtitle'] = [$tokens[0]];
        $payload['index_options']['custom_field_keys'] = ['subtitle'];
        $selectedDependencyRows = 1;
    }

    $postIds = range(1, $lastPostId);
    $queue = new WP_FTS_Index_Queue($fake);
    $queue->enqueue_many($postIds, null, $payload);
    wp_fts_test_seed_scope($fake, 'permanent-composed-statement-ceiling-' . ($injectFailure ? 'failure' : 'success'));
    if (!$injectFailure) {
        $fake->queue[$maximumId]['last_error_code'] = 'content_failure';
        $fake->queue[$maximumId]['last_error_at'] = time() - 60;
    }
    if ($injectFailure) {
        $GLOBALS['wp_fts_test_filters']['wp_fts_post_index_fields'] = static function (array $fields, object $post): array {
            if ((int) ($post->ID ?? 0) === 1) {
                throw new RuntimeException('permanent composed filter failure');
            }
            return $fields;
        };
    }

    $aggregateSourceBytes = array_sum(array_map(
        static fn(object $post): int => strlen((string) ($post->post_title ?? ''))
            + strlen((string) ($post->post_content ?? ''))
            + strlen((string) ($post->post_excerpt ?? '')),
        $posts
    ));
    $fake->queries = [];
    $fake->prepared = [];
    try {
        $summary = WP_FTS_Plugin::process_manual_index_batch([
            'batch_size' => 100,
            'source' => 'permanent-composed-statement-ceiling',
        ]);
    } finally {
        unset($GLOBALS['wp_fts_test_filters']['wp_fts_post_index_fields']);
        $wpdb = $oldWpdb;
    }
    $queries = array_map(
        static fn(mixed $statement): string => is_array($statement)
            ? (string) ($statement[0] ?? '')
            : (string) $statement,
        $fake->queries
    );

    return [
        'fake' => $fake,
        'summary' => $summary,
        'queries' => $queries,
        'roles' => array_map('wp_fts_test_composed_worker_statement_role', $queries),
        'cron_write_count' => (int) ($GLOBALS['wp_fts_test_cron_write_count'] ?? 0),
        'aggregate_source_bytes' => $aggregateSourceBytes,
        'selected_dependency_rows' => $selectedDependencyRows,
    ];
}

/** Place one visible token inside a source padded to the document-byte ceiling. */
function wp_fts_test_composed_near_limit_source(int $number): string
{
    $visible = '<p>composednear' . $number . '</p>';
    $open = '<!--';
    $close = '-->';
    $bytes = 1900000;

    return $visible . $open . str_repeat('x', $bytes - strlen($visible . $open . $close)) . $close;
}

/** @return array<string,mixed> */
function wp_fts_test_composed_worker_scope(WP_FTS_Test_WPDB $fake): array
{
    foreach ($fake->queue as $row) {
        if (($row['kind'] ?? null) === 'scope') {
            return $row;
        }
    }

    return [];
}

/** Classify every composed worker statement so no unbudgeted query can hide. */
function wp_fts_test_composed_worker_statement_role(string $sql): string
{
    $normalized = strtoupper(trim($sql));
    if ($normalized === 'START TRANSACTION') {
        return 'transaction_start';
    }
    if ($normalized === 'COMMIT') {
        return 'transaction_commit';
    }
    $tags = [
        'wp_fts:claim-batch' => 'claim_batch',
        'wp_fts:dependency_measurement' => 'dependency_measurement',
        'wp_fts:dependency_values' => 'dependency_values',
        'wp_fts:replacement-frontier' => 'replacement_frontier',
        'wp_fts:dictionary-increment' => 'dictionary_increment',
        'wp_fts:dictionary-decrement' => 'dictionary_decrement',
        'wp_fts:bounded-index-delete' => 'bounded_index_delete',
        'wp_fts:resolve-prepared-terms' => 'resolve_prepared_terms',
        'wp_fts:posting-replacement' => 'posting_replacement',
        'wp_fts:yield-scope-release-posts' => 'scope_yield_and_post_batch_release',
        'wp_fts:search-epoch-advance' => 'search_epoch_advance',
        'wp_fts:atomic-worker-ack' => 'atomic_worker_ack',
        'wp_fts:fail-batch' => 'post_batch_failure',
    ];
    foreach ($tags as $tag => $role) {
        if (str_contains($sql, $tag)) {
            return $role;
        }
    }
    $lower = strtolower($sql);
    if (str_starts_with($normalized, 'INSERT IGNORE INTO WP_OPTIONS')) {
        return 'lease_acquire';
    }
    if (str_starts_with($normalized, 'DELETE FROM WP_OPTIONS')) {
        return 'lease_release';
    }
    if (str_starts_with($normalized, 'UPDATE WP_OPTIONS')) {
        return 'health_state_cas';
    }
    if (str_starts_with($normalized, 'SELECT ') && str_contains($lower, 'source_snapshot_complete')) {
        return 'claim_source_snapshot';
    }
    if (str_starts_with($normalized, 'SELECT ') && str_contains($lower, 'fts_source_changed')) {
        return 'conditional_source_fallback';
    }
    if (str_starts_with($normalized, 'INSERT INTO WP_FTS_DOCUMENTS')) {
        return 'document_replacement';
    }

    return 'unknown';
}
