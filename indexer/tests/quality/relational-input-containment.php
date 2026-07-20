<?php
declare(strict_types=1);

/** Invoke the private normalization boundary so malformed inputs fail before SQL. */
function qric_private(string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod(WP_FTS_Plugin::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(null, ...$args);
}

/** Capture a boundary rejection for assertions without weakening its exception path. */
function qric_caught(callable $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $error) {
        return $error;
    }

    return null;
}

test_case('quality relational input containment rejects raw query bytes before normalization', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $wpdb = new WP_FTS_Test_WPDB();
    wp_fts_test_reset_wordpress_fakes();

    try {
        $before = $wpdb->num_queries;
        $error = qric_caught(static fn(): array => WP_FTS_Plugin::search_page(str_repeat(' ', 4097)));
        assert_true($error instanceof WP_FTS_Search_Budget_Exceeded, 'a whitespace-only query above 4 KiB should be a typed complexity rejection');
        assert_same('query bytes', $error instanceof WP_FTS_Search_Budget_Exceeded ? $error->budget() : null, 'raw query rejection should identify its fixed byte budget');
        assert_same($before, $wpdb->num_queries, 'raw query byte rejection should execute no SQL');

        $rawFrontendQuery = str_repeat(' ', 4097);
        $query = new WP_FTS_Test_Query(['s' => $rawFrontendQuery, 'posts_per_page' => 10]);
        assert_same($rawFrontendQuery, qric_private('frontend_search_query_text', $query), 'the WordPress query adapter should not allocate a trimmed copy before the shared byte check');

        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = array_replace(
            WP_FTS_Plugin::default_settings(),
            ['replace_frontend_search' => true]
        );
        WP_FTS_Plugin::prepare_frontend_search_query($query);
        assert_true(!empty($query->query_vars['wp_fts_search_candidate']), 'an oversized supported WordPress search should remain owned by the fail-closed adapter');
        assert_same([], WP_FTS_Plugin::replace_frontend_search_posts(null, $query), 'an oversized WordPress query should fail closed instead of reaching core LIKE search');
        assert_same('unavailable_or_unbounded_page', $query->query_vars['wp_fts_search_unavailable'] ?? null, 'the WordPress adapter should expose its pre-execution fail-closed result marker');
        assert_same($before, $wpdb->num_queries, 'the oversized WordPress search should execute no SQL');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality relational input containment streams bounded visibility scopes', function (): void {
    $tooMany = array_fill(0, 33, 'post');
    $countError = qric_caught(static fn(): array => qric_private('search_scope_values', $tooMany));
    assert_true($countError instanceof InvalidArgumentException, 'more than 32 raw scope entries should be rejected before traversal');
    assert_contains('32', $countError?->getMessage() ?? '', 'scope cardinality rejection should identify the hard limit');

    $bytesError = qric_caught(static fn(): array => qric_private('search_scope_values', str_repeat('p', 4097)));
    assert_true($bytesError instanceof InvalidArgumentException, 'scope input above 4 KiB should be rejected before comma expansion');
    assert_contains('4,096', $bytesError?->getMessage() ?? '', 'scope byte rejection should identify the hard limit');

    $parts = array_map(static fn(int $index): string => 'type' . $index, range(1, 33));
    $partsError = qric_caught(static fn(): array => qric_private('search_scope_values', implode(',', $parts)));
    assert_true($partsError instanceof InvalidArgumentException, 'a comma list expanding beyond 32 unique scope values should be rejected while scanning');

    $wideError = qric_caught(static fn(): array => qric_private('search_scope_values', str_repeat('a', 65)));
    assert_true($wideError instanceof InvalidArgumentException, 'one scope value above 64 bytes should be rejected before sanitization');

    $boundary = array_map(static fn(int $index): string => 'type' . str_pad((string) $index, 2, '0', STR_PAD_LEFT), range(1, 32));
    assert_same($boundary, qric_private('search_scope_values', $boundary), 'the exact 32-value scope boundary should remain valid and deterministic');
});

test_case('quality relational input containment bounds filtered index fields before metadata copies', function (): void {
    $extractor = new WP_FTS_PostContentExtractor();
    $post = (object) [
        'ID' => 7001,
        'post_title' => '',
        'post_content' => '',
        'post_excerpt' => '',
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-07-18 00:00:00',
        'terms' => [],
        'custom_fields' => [],
    ];

    $fieldCountError = qric_caught(static fn(): array => $extractor->extract($post, [
        'filters' => [
            'wp_fts_post_index_fields' => static fn(): array => array_fill(0, 33, ['name' => 'extra', 'text' => 'value']),
        ],
    ]));
    assert_true($fieldCountError instanceof WP_FTS_Analysis_Limit_Exceeded, 'more than 32 filtered field rows should be rejected before normalization');
    assert_same('index_fields', $fieldCountError instanceof WP_FTS_Analysis_Limit_Exceeded ? $fieldCountError->reason_code : null, 'filtered field cardinality should have a stable typed reason');

    $fieldNameError = qric_caught(static fn(): array => $extractor->extract($post, [
        'filters' => [
            'wp_fts_post_index_fields' => static fn(): array => [['name' => str_repeat('n', 192), 'text' => 'value']],
        ],
    ]));
    assert_true($fieldNameError instanceof WP_FTS_Analysis_Limit_Exceeded, 'an oversized filtered field name should be rejected before trim');
    assert_same('index_field_name_bytes', $fieldNameError instanceof WP_FTS_Analysis_Limit_Exceeded ? $fieldNameError->reason_code : null, 'filtered field-name rejection should have a stable typed reason');

    $largeSource = str_repeat('a ', 524289);
    $aggregateError = qric_caught(static fn(): array => $extractor->extract($post, [
        'filters' => [
            'wp_fts_post_index_fields' => static fn(): array => [
                ['name' => 'first', 'text' => $largeSource],
                ['name' => 'second', 'text' => $largeSource],
            ],
        ],
    ]));
    assert_true($aggregateError instanceof WP_FTS_Analysis_Limit_Exceeded, 'aggregate filtered field source above 2 MiB should be rejected before metadata extraction');
    assert_same('source_bytes', $aggregateError instanceof WP_FTS_Analysis_Limit_Exceeded ? $aggregateError->reason_code : null, 'aggregate field source should reuse the document source-byte reason');

    $dualSource = str_repeat('b', 1153434);
    $dualSourceError = qric_caught(static fn(): array => $extractor->extract($post, [
        'filters' => [
            'wp_fts_post_index_fields' => static fn(): array => [[
                'name' => 'dual_source',
                'text' => $dualSource,
                'html' => '<p>' . $dualSource . '</p>',
            ]],
        ],
    ]));
    assert_true($dualSourceError instanceof WP_FTS_Analysis_Limit_Exceeded, 'distinct text and HTML buffers in one filtered field should both count against the aggregate source limit');
    assert_same('source_bytes', $dualSourceError instanceof WP_FTS_Analysis_Limit_Exceeded ? $dualSourceError->reason_code : null, 'dual field sources should fail with the document source-byte reason');

    $boundary = $extractor->extract($post, [
        'filters' => [
            'wp_fts_post_index_fields' => static fn(): array => array_map(
                static fn(int $index): array => ['name' => 'field_' . $index, 'text' => 'value_' . $index],
                range(1, 32)
            ),
        ],
    ]);
    assert_same(32, count($boundary['fields'] ?? []), 'the exact 32-field boundary should remain indexable');
});

test_case('quality relational input containment ignores result-filter membership expansion without extra work', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $post = (object) [
        'ID' => 7002,
        'post_title' => 'Filter envelope host',
        'post_content' => '<p>qricfilterenvelopetoken</p>',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => '2026-07-18 00:00:00',
    ];
    $GLOBALS['wp_fts_test_posts'][7002] = $post;

    try {
        wp_fts_test_replace_post(
            wp_fts_test_unleased_storage(),
            $post,
            ['lang' => 'en'],
            WP_FTS_Plugin::runtime_analyzer()
        );
        wp_fts_test_mark_search_takeover_ready();

        $beforeCanonical = $fake->num_queries;
        $canonical = WP_FTS_Plugin::search_page('qricfilterenvelopetoken', ['lang' => 'en', 'limit' => 10]);
        $canonicalQueries = $fake->num_queries - $beforeCanonical;

        $GLOBALS['wp_fts_test_filters']['wp_fts_search_results'] = static function (array $rows): array {
            $expanded = array_fill(0, 11, $rows[0] ?? []);
            foreach ($expanded as &$row) {
                $row['filter_only_decoration'] = true;
            }
            unset($row);
            return $expanded;
        };
        $beforeFiltered = $fake->num_queries;
        $filtered = WP_FTS_Plugin::search_page('qricfilterenvelopetoken', ['lang' => 'en', 'limit' => 10]);
        $filteredQueries = $fake->num_queries - $beforeFiltered;

        assert_same($canonical['results'] ?? [], $filtered['results'] ?? [], 'a decoration-only result filter must not expand, duplicate, replace, or reorder canonical membership');
        assert_same([7002], array_column($filtered['results'] ?? [], 'doc_id'), 'invalid filter membership should leave the one authorized canonical row intact');
        assert_same(false, isset($filtered['results'][0]['filter_only_decoration']), 'decorations from an invalid expanded result set should be ignored with the membership change');
        assert_same($canonicalQueries, $filteredQueries, 'ignoring invalid filter membership should execute no SQL beyond the canonical search page');
    } finally {
        unset($GLOBALS['wp_fts_test_filters']['wp_fts_search_results']);
        $wpdb = $oldWpdb;
    }
});

test_case('quality relational input containment byte-bounds metadata hydration across the full page', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $publishedCapability = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SEARCH_READY_INCARNATION_OPTION] ?? [];
    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $fake->searchEpoch = 1;
    $fake->searchEpochIncarnation = str_repeat('e', 32);
    $storage = new WP_FTS_Storage_Mysql($fake);
    $key = WP_FTS_TermNamespace::namespace_term('en', 'metadatatransport');
    $nearLimitExcerpt = str_repeat('m', 1450000);
    $fake->ftsTerms[$key] = ['doc_freq' => 4];
    $fake->postings[$key] = [];

    for ($postId = 1; $postId <= 4; $postId++) {
        $fake->postings[$key][$postId] = 4096;
        $fake->docs[$postId] = [
            'post_id' => $postId,
            'primary_lang' => 'en',
            'doc_len' => 1,
            'content_hash' => 'metadata-transport-' . $postId,
            'snippet_text' => 'metadatatransport',
            'indexed_at' => time(),
        ];
        $GLOBALS['wp_fts_test_posts'][$postId] = (object) [
            'ID' => $postId,
            'post_date_gmt' => '2026-07-18 00:00:00',
            'post_content' => 'metadatatransport',
            'post_title' => 'Metadata transport ' . $postId,
            'post_excerpt' => $nearLimitExcerpt,
            'post_status' => 'publish',
            'post_password' => '',
            'post_type' => 'post',
        ];
    }

    $groups = [[['key' => $key, 'rank' => 0]]];
    $options = [
        'query_lang' => 'en',
        'mode' => 'OR',
        'page_size' => 20,
        'post_types' => ['post'],
        'post_statuses' => ['publish'],
        'include_metadata' => true,
        'search_ready_incarnation' => (string) ($publishedCapability['incarnation'] ?? ''),
        'search_ready_profile_hash' => (string) ($publishedCapability['profile_hash'] ?? ''),
    ];
    $first = $storage->search_page($groups, $options);
    $firstQueries = array_map(
        static fn(mixed $query): string => is_array($query) ? (string) ($query[0] ?? '') : (string) $query,
        $fake->queries
    );

    assert_same([4, 3], array_column($first['results'], 'doc_id'), 'metadata hydration should defer the ordered third near-1.5 MiB row before exceeding the 4 MiB page envelope');
    assert_same(true, $first['has_more'] ?? null, 'a byte-shortened metadata page should retain next-page reachability');
    assert_true(is_string($first['next_cursor'] ?? null) && $first['next_cursor'] !== '', 'a byte-shortened metadata page should issue a signed cursor at its last complete row');
    assert_same($nearLimitExcerpt, $first['results'][0]['excerpt'] ?? null, 'a retained metadata excerpt must be complete rather than truncated');
    assert_same(3, count($firstQueries), 'large metadata transport should still execute exactly plan, rank, and hydrate statements');
    assert_contains('OCTET_LENGTH(wp_size.post_title)', $firstQueries[1] ?? '', 'metadata ranking should measure the title selected by hydration');
    assert_contains('OCTET_LENGTH(wp_size.post_excerpt)', $firstQueries[1] ?? '', 'metadata ranking should measure the excerpt selected by hydration');
    assert_true(!str_contains($firstQueries[1] ?? '', 'OCTET_LENGTH(wp_size.post_content)'), 'metadata-only ranking must not measure unselected post content');
    assert_true(!str_contains($firstQueries[1] ?? '', 'OCTET_LENGTH(wp_size.post_content_filtered)'), 'metadata-only ranking must not measure unselected filtered content');
    assert_same(2, substr_count($firstQueries[2] ?? '', 'SELECT %d AS post_id'), 'metadata hydration SQL should contain only the two byte-accepted row ids');

    $fake->queries = [];
    $second = $storage->search_page($groups, array_replace($options, ['cursor' => $first['next_cursor']]));
    assert_same([2, 1], array_column($second['results'], 'doc_id'), 'the metadata cursor should reach every row deferred by the byte envelope');
    assert_same(false, $second['has_more'] ?? null, 'the second byte-bounded metadata page should finish the four-row fixture');
    assert_same([4, 3, 2, 1], [
        ...array_column($first['results'], 'doc_id'),
        ...array_column($second['results'], 'doc_id'),
    ], 'metadata byte paging must neither omit nor repeat ranked posts');
    assert_same(3, count($fake->queries), 'each adjacent metadata page should retain the exact three-statement ceiling');
});

test_case('quality relational input containment never accepts missing profile provenance on a ready index', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] = [
        'status' => 'ready',
        'initial_index_status' => 'ready',
        'index_profile_hash' => '',
        'accepted_index_profile_hash' => '',
    ];

    try {
        WP_FTS_Plugin::detect_index_profile_drift();
        $health = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];
        $scopeRows = array_filter($fake->queue, static fn(array $row): bool => ($row['kind'] ?? '') === 'scope');

        assert_true(preg_match('/^[a-f0-9]{40}$/', (string) ($health['index_profile_hash'] ?? '')) === 1, 'the current desired profile should still be recorded');
        assert_same('', $health['accepted_index_profile_hash'] ?? null, 'a ready index with missing provenance must not blindly accept the current runtime profile');
        assert_same('pending', $health['initial_index_status'] ?? null, 'missing ready-index provenance should fail search takeover immediately');
        assert_same('reconciling', $health['status'] ?? null, 'missing ready-index provenance should enter durable reconciliation');
        assert_same(1, count($scopeRows), 'missing ready-index provenance should enqueue one coalesced profile reconciliation scope');

        $pendingFake = new WP_FTS_Test_WPDB();
        $wpdb = $pendingFake;
        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] = [
            'status' => 'reconciling',
            'initial_index_status' => 'pending',
            'index_profile_hash' => '',
            'accepted_index_profile_hash' => '',
        ];
        WP_FTS_Plugin::detect_index_profile_drift();
        $pendingHealth = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];
        assert_true(preg_match('/^[a-f0-9]{40}$/', (string) ($pendingHealth['index_profile_hash'] ?? '')) === 1, 'a fresh pending index should bind its initial corpus to one desired profile');
        assert_same('', $pendingHealth['accepted_index_profile_hash'] ?? '', 'a fresh pending index must not accept profile provenance before maintenance publication');
        assert_same(1, count(array_filter($pendingFake->queue, static fn(array $row): bool => ($row['kind'] ?? '') === 'scope')), 'an unbound pending index should create one exact profile scope');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality relational input containment replaces tables with unexpected physical indexes', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $fake->schemaIndexes['wp_fts_postings']['duplicate_post_first'] = ['post_id', 'term_id', 'impact'];

    try {
        $before = (new WP_FTS_Storage_Mysql($fake))->verify_schema();
        assert_same(['wp_fts_postings.duplicate_post_first(post_id,term_id,impact)'], $before['unexpected_indexes'] ?? null, 'the verifier should identify the exact redundant index before repair');

        WP_FTS_Plugin::create_or_repair_schema();
        $after = (new WP_FTS_Storage_Mysql($fake))->verify_schema();
        assert_same(true, $after['valid'] ?? null, 'dedicated maintenance should restore the exact physical schema');
        assert_true(!isset($fake->schemaIndexes['wp_fts_postings']['duplicate_post_first']), 'repair should remove an unexpected duplicate index instead of leaving verification permanently failed');
        assert_true(count(array_filter(
            $fake->queries,
            static fn(mixed $query): bool => is_string($query) && str_starts_with($query, 'DROP TABLE `wp_fts_postings`')
        )) === 1, 'repair should replace the incompatible postings table exactly once');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality schema repair recreates a missing work table and reconciles the corpus', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_mark_search_takeover_ready();
    $fake->options = 'wp_options';
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    unset($fake->schemaColumns['wp_fts_work'], $fake->schemaIndexes['wp_fts_work']);
    $fake->queries = [];
    $fake->prepared = [];

    try {
        WP_FTS_Plugin::create_or_repair_schema();

        $health = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];
        $scopeRows = array_values(array_filter(
            $fake->queue,
            static fn(array $row): bool => ($row['kind'] ?? null) === 'scope'
        ));
        assert_same(WP_FTS_Plugin::SCHEMA_VERSION, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null, 'generic repair should publish the current version only after recreating the absent work table');
        assert_same(true, (new WP_FTS_Storage_Mysql($fake))->verify_schema()['valid'] ?? null, 'generic repair should restore the complete physical work-table contract');
        assert_same('pending', $health['initial_index_status'] ?? null, 'losing the durable work relation must invalidate the accepted search generation');
        assert_same(1, count($scopeRows), 'work-table recreation must enqueue one bounded corpus reconciliation');
        assert_same('schema_repair', json_decode((string) ($scopeRows[0]['payload'] ?? ''), true)['reason'] ?? null, 'the replacement scope should retain the physical-repair reason');
        assert_same(0, count(array_filter(
            $fake->queries,
            static fn(mixed $sql): bool => is_string($sql) && str_starts_with($sql, 'CREATE INDEX recoverable ON ')
        )), 'schema repair must not issue an orphan CREATE INDEX against a missing work table');
        assert_same(false, WP_FTS_Plugin::search_takeover_status()['ready'] ?? null, 'search must fail closed until the recreated work generation converges');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality schema repair replaces a conflicting recoverable index and reconciles the corpus', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_mark_search_takeover_ready();
    $fake->options = 'wp_options';
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $fake->schemaIndexes['wp_fts_work']['recoverable'] = ['kind', 'state', 'available_at'];
    $fake->queries = [];
    $fake->prepared = [];

    try {
        WP_FTS_Plugin::create_or_repair_schema();

        $health = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];
        $scopeRows = array_values(array_filter(
            $fake->queue,
            static fn(array $row): bool => ($row['kind'] ?? null) === 'scope'
        ));
        assert_same(
            ['kind', 'state', 'claim_expires_at', 'available_at', 'post_id', 'job_key'],
            $fake->schemaIndexes['wp_fts_work']['recoverable'] ?? null,
            'generic repair should replace the conflicting named index with the exact recoverable definition'
        );
        assert_same('pending', $health['initial_index_status'] ?? null, 'a conflicting queue index must invalidate readiness instead of being accepted as an additive migration');
        assert_same(1, count($scopeRows), 'replacement of an incompatible work table must enqueue one bounded corpus reconciliation');
        assert_same(1, count(array_filter(
            $fake->queries,
            static fn(mixed $sql): bool => is_string($sql) && str_starts_with($sql, 'DROP TABLE `wp_fts_work`')
        )), 'the incompatible named index should enter generic work-table replacement exactly once');
        assert_same(0, count(array_filter(
            $fake->queries,
            static fn(mixed $sql): bool => is_string($sql) && str_starts_with($sql, 'CREATE INDEX recoverable ON ')
        )), 'schema repair must not try to create a duplicate recoverable name before generic replacement');
        assert_same(false, WP_FTS_Plugin::search_takeover_status()['ready'] ?? null, 'search must stay unavailable until the replacement work generation converges');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality schema repair replaces work-table engine and index damage together', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_mark_search_takeover_ready();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    unset($fake->schemaIndexes['wp_fts_work']['recoverable']);
    $fake->schemaEngines['wp_fts_work'] = 'MyISAM';
    $fake->queries = [];
    $fake->prepared = [];

    try {
        WP_FTS_Plugin::create_or_repair_schema();

        $health = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];
        $scopeRows = array_values(array_filter(
            $fake->queue,
            static fn(array $row): bool => ($row['kind'] ?? null) === 'scope'
        ));
        assert_same(true, (new WP_FTS_Storage_Mysql($fake))->verify_schema()['valid'] ?? null, 'metadata maintenance should repair all physical damage instead of accepting a partial index-only result');
        assert_same('pending', $health['initial_index_status'] ?? null, 'additional physical damage must invalidate search readiness');
        assert_same(1, count($scopeRows), 'additional physical damage must enqueue one bounded corpus reconciliation scope');
        assert_same('schema_repair', json_decode((string) ($scopeRows[0]['payload'] ?? ''), true)['reason'] ?? null, 'the recovery scope should retain the physical-repair reason');
        assert_same(1, count(array_filter(
            $fake->queries,
            static fn(mixed $sql): bool => is_string($sql) && str_starts_with($sql, 'DROP TABLE `wp_fts_work`')
        )), 'additional work-table damage should rebuild the incompatible relation exactly once');
        assert_same(false, WP_FTS_Plugin::search_takeover_status()['ready'] ?? null, 'search must remain fail-closed until the repaired generation is reconciled');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality relational input containment requires transactional engines for every MySQL table', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $fake->schemaEngines['wp_fts_terms'] = 'MyISAM';

    try {
        $before = (new WP_FTS_Storage_Mysql($fake))->verify_schema();
        assert_same(false, $before['valid'] ?? null, 'a non-transactional dictionary table must fail the production schema contract');
        assert_same(['wp_fts_terms(myisam)'], $before['invalid_engines'] ?? null, 'the verifier should identify the exact table and physical engine');
        $failureSummary = new ReflectionMethod(WP_FTS_Plugin::class, 'schema_verification_failure_summary');
        $failureSummary->setAccessible(true);
        assert_same(
            'invalid_engines=wp_fts_terms(myisam)',
            $failureSummary->invoke(null, $before),
            'an engine-only schema failure should retain its bounded physical cause'
        );

        WP_FTS_Plugin::create_or_repair_schema();
        $after = (new WP_FTS_Storage_Mysql($fake))->verify_schema();
        assert_same(true, $after['valid'] ?? null, 'dedicated maintenance should rebuild a non-transactional derived table as InnoDB');
        assert_same('InnoDB', $fake->schemaEngines['wp_fts_terms'] ?? null, 'schema repair should restore transaction participation for dictionary writes');
        assert_true(count(array_filter(
            $fake->queries,
            static fn(mixed $query): bool => is_string($query) && str_starts_with($query, 'DROP TABLE `wp_fts_terms`')
        )) === 1, 'repair should replace the incompatible engine exactly once');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('plugin schema repair replaces damaged work state with one bounded corpus recovery scope', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $queue = new WP_FTS_Index_Queue($fake);
    $queue->enqueue(88, 1700000000, ['reason' => 'pre_repair_fixture']);
    $fake->schemaEngines['wp_fts_work'] = 'MyISAM';
    $fake->queries = [];
    $fake->prepared = [];

    try {
        WP_FTS_Plugin::create_or_repair_schema();

        $after = (new WP_FTS_Storage_Mysql($fake))->verify_schema();
        assert_same(true, $after['valid'] ?? null, 'plugin repair should rebuild a non-transactional work table as the exact current InnoDB schema');
        assert_same('InnoDB', $fake->schemaEngines['wp_fts_work'] ?? null, 'plugin repair should restore work mutations to the shared transaction boundary');
        assert_true(!isset($fake->queue[88]), 'schema repair must not claim that rows from the dropped work relation were physically preserved');

        $scopeRows = array_values(array_filter(
            $fake->queue,
            static fn(array $row): bool => ($row['kind'] ?? null) === 'scope'
        ));
        assert_same(1, count($scopeRows), 'plugin repair should replace unknowable work state with one bounded corpus recovery scope');
        assert_same(
            'scope:' . hash('sha256', WP_FTS_Index_Queue::GLOBAL_CORPUS_SCOPE_KEY),
            $scopeRows[0]['job_key'] ?? null,
            'schema repair should use the one canonical corpus recovery identity'
        );
        assert_same('', $scopeRows[0]['scope_subject_type'] ?? null, 'schema repair recovery must cover the complete corpus');
        assert_same('ready', $scopeRows[0]['state'] ?? null, 'schema repair recovery should be claimable by the bounded scope worker');
        assert_same(
            'schema_repair',
            json_decode((string) ($scopeRows[0]['payload'] ?? ''), true)['reason'] ?? null,
            'schema repair recovery should retain its truthful product reason'
        );
        assert_same(1, count(array_filter(
            $fake->queries,
            static fn(string $sql): bool => str_starts_with($sql, 'DROP TABLE `wp_fts_work`')
        )), 'plugin repair should replace the damaged work table exactly once');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality relational input containment rejects truncated or disabled required indexes', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $fake->schemaIndexSubParts['wp_fts_terms']['term_identity'][2] = 16;
    $fake->schemaInvisibleIndexes['wp_fts_postings']['post_term_impact'] = true;

    try {
        $before = (new WP_FTS_Storage_Mysql($fake))->verify_schema();
        assert_contains('wp_fts_terms.term_identity(lang,kind,term)', implode(',', $before['missing_indexes'] ?? []), 'a prefix-truncated unique identity must not satisfy the exact lexical identity contract');
        assert_contains('wp_fts_postings.post_term_impact(post_id,term_id,impact)', implode(',', $before['missing_indexes'] ?? []), 'a disabled candidate index must not satisfy the production access contract');

        WP_FTS_Plugin::create_or_repair_schema();
        assert_same(true, (new WP_FTS_Storage_Mysql($fake))->verify_schema()['valid'] ?? null, 'maintenance should rebuild truncated and disabled required indexes');
        assert_same([], $fake->schemaIndexSubParts, 'schema repair should remove every prefix-index override');
        assert_same([], $fake->schemaInvisibleIndexes, 'schema repair should restore every required index as usable');
    } finally {
        $wpdb = $oldWpdb;
    }
});
