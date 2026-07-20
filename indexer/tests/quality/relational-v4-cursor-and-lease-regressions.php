<?php
declare(strict_types=1);

test_case_with_pdo_sqlite_fixture('relational query alternatives share their position even when analyzer output is reordered', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    wp_fts_v4_regression_add_post($wpdb, 1, '2026-01-01 00:00:00');
    wp_fts_v4_regression_add_term($wpdb, 'surface', [1 => 100.0]);
    wp_fts_v4_regression_add_term($wpdb, 'other', [1 => 100.0]);
    wp_fts_v4_regression_add_term($wpdb, 'lemma', [1 => 100.0]);

    $analyzer = new class {
        /** @return array<int,array<string,mixed>> */
        public function analyze_query_occurrences(string $query, array $options = []): array
        {
            return [
                ['term' => 'surface', 'lang' => 'en', 'position' => 0],
                ['term' => 'other', 'lang' => 'en', 'position' => 1],
                // A third-party analyzer may emit a lemma after later source
                // positions. Position, not output adjacency, defines identity.
                ['term' => 'lemma', 'lang' => 'en', 'position' => 0],
            ];
        }
    };
    $payload = WP_FTS_Searcher::for_set_oriented_storage($storage, $analyzer)->search('ignored', [
        'lang' => 'en',
        'mode' => 'AND',
        'limit' => 10,
        'post_type' => ['post'],
        'post_status' => ['publish'],
        '_search_ready_incarnation' => wp_fts_v4_regression_ready_incarnation(),
        '_search_ready_profile_hash' => wp_fts_v4_regression_ready_profile_hash(),
        'explain' => true,
    ]);

    assert_same([1], array_column($payload['results'] ?? [], 'doc_id'), 'reordered alternatives should preserve exact AND membership');
    assert_same(2, $payload['explain']['logical_group_count'] ?? null, 'two source positions must remain two logical groups');
    assert_same(3, $payload['explain']['resolved_alternatives'] ?? null, 'both alternatives from the first position should remain in its one group');
});

test_case_with_pdo_sqlite_fixture('relational v4 regression keeps a signed recency cursor on its original scoring clock', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    for ($postId = 1; $postId <= 6; $postId++) {
        wp_fts_v4_regression_add_post(
            $wpdb,
            $postId,
            gmdate('Y-m-d H:i:s', strtotime('2026-01-01 00:00:00 UTC') + ($postId * 3600))
        );
    }
    wp_fts_v4_regression_add_term($wpdb, 'clockprobe', array_fill_keys(range(1, 6), 101.0));

    $groups = wp_fts_v4_regression_groups('clockprobe');
    $options = wp_fts_v4_regression_search_options() + [
        'recency_boost_strength' => 1.5,
        'recency_boost_half_life_days' => 2,
        'now_gmt' => '2026-01-03 00:00:00',
    ];
    $first = $storage->search_page($groups, $options);
    $cursor = $first['next_cursor'] ?? null;
    assert_true(is_string($cursor) && $cursor !== '', 'recency fixture should produce a signed next cursor');

    $continued = $storage->search_page($groups, array_replace($options, [
        'cursor' => $cursor,
        'now_gmt' => '2036-01-03 00:00:00',
    ]));
    $continuedRankSql = wp_fts_v4_regression_last_rank_sql($wpdb);
    $fixedClock = $storage->search_page($groups, array_replace($options, ['cursor' => $cursor]));
    assert_same(
        array_column($fixedClock['results'], 'doc_id'),
        array_column($continued['results'], 'doc_id'),
        'a later request clock must not change membership or ordering after a recency cursor is issued'
    );
    assert_contains('2026-01-03 00:00:00', $continuedRankSql, 'continued ranking should reuse the scoring epoch authenticated by the cursor');
    assert_true(!str_contains($continuedRankSql, '2036-01-03 00:00:00'), 'continued ranking must ignore a caller-supplied replacement scoring clock');

    $tamperOffset = intdiv(strlen($cursor), 2);
    $tampered = substr($cursor, 0, $tamperOffset)
        . ($cursor[$tamperOffset] === 'A' ? 'B' : 'A')
        . substr($cursor, $tamperOffset + 1);
    wp_fts_v4_regression_assert_invalid_cursor(
        static fn() => $storage->search_page($groups, array_replace($options, ['cursor' => $tampered])),
        'changing one byte of a recency cursor must invalidate its signature'
    );
});

test_case_with_pdo_sqlite_fixture('relational recency cursors retain posts with zero GMT dates', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    $dates = [
        1 => '2026-01-05 00:00:00',
        2 => '0000-00-00 00:00:00',
        3 => '2026-01-04 00:00:00',
        4 => '0000-00-00 00:00:00',
        5 => '2026-01-03 00:00:00',
    ];
    foreach ($dates as $postId => $date) {
        wp_fts_v4_regression_add_post($wpdb, $postId, $date);
    }
    wp_fts_v4_regression_add_term($wpdb, 'zerodateprobe', array_fill_keys(array_keys($dates), 101.0));

    $groups = wp_fts_v4_regression_groups('zerodateprobe');
    $options = wp_fts_v4_regression_search_options(2) + [
        'recency_boost_strength' => 1.0,
        'recency_boost_half_life_days' => 1,
        'now_gmt' => '2026-01-06 00:00:00',
    ];
    $seen = [];
    $cursor = null;
    do {
        $pageOptions = $cursor === null ? $options : array_replace($options, ['cursor' => $cursor]);
        $page = $storage->search_page($groups, $pageOptions);
        array_push($seen, ...array_column($page['results'] ?? [], 'doc_id'));
        $cursor = $page['next_cursor'] ?? null;
    } while (!empty($page['has_more']));

    assert_same([1, 3, 5, 4, 2], $seen, 'zero-date drafts should receive the unboosted score and remain reachable after valid dated posts');
    assert_same(null, $cursor, 'the terminal zero-date page should not manufacture another cursor');
    $rankSql = wp_fts_v4_regression_last_rank_sql($wpdb);
    assert_contains('COALESCE(CAST(ROUND(', $rankSql, 'portable ranking SQL should fall back when date arithmetic returns NULL');
    assert_contains('), ranked.score) AS score', $rankSql, 'a missing or invalid date should preserve the original integral score');

    $mysqlStorage = new WP_FTS_Storage_Mysql(new WP_FTS_Test_WPDB());
    $recencyExpression = new ReflectionMethod(WP_FTS_Storage_Mysql::class, 'recency_score_expression');
    $recencyExpression->setAccessible(true);
    $mysqlSql = (string) $recencyExpression->invoke($mysqlStorage, $options, 'wp_f');
    assert_contains('TIMESTAMPDIFF(SECOND, wp_f.post_date_gmt', $mysqlSql, 'MySQL and MariaDB should retain indexed date arithmetic');
    assert_true(str_starts_with($mysqlSql, 'COALESCE(CAST(ROUND('), 'MySQL recency scoring should guard a NULL TIMESTAMPDIFF result');
    assert_true(str_ends_with($mysqlSql, ', ranked.score)'), 'MySQL zero dates should use the unboosted ranked score rather than NULL');
});

test_case('relational v6 cursor fingerprints bind one normalized range prefix', function (): void {
    $storage = new WP_FTS_Storage_Mysql(new WP_FTS_Test_WPDB());
    $fingerprint = new ReflectionMethod(WP_FTS_Storage_Mysql::class, 'search_cursor_fingerprint');
    $plan = [
        'groups' => [[[
            'key' => WP_FTS_TermNamespace::namespace_term('und', 'ÿ'),
            'rank' => 0,
        ]]],
    ];
    $prefix = [
        'group_id' => 0,
        'lang' => 'und',
        'term' => 'ÿ',
    ];

    $hash = $fingerprint->invoke($storage, $plan, $prefix, 'OR', []);
    $otherSurfaceHash = $fingerprint->invoke(
        $storage,
        $plan,
        array_replace($prefix, ['term' => 'ÿx']),
        'OR',
        []
    );

    assert_true(is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1, 'a Unicode normalized prefix should produce a stable cursor fingerprint');
    assert_true(!hash_equals($hash, $otherSurfaceHash), 'a cursor for one normalized surface range must not replay against another range');
});

test_case('relational v4 cursor fingerprints are bound to one multisite index namespace', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $mainStorage = new WP_FTS_Storage_Mysql($wpdb, 'wp_');
    $siteStorage = new WP_FTS_Storage_Mysql($wpdb, 'wp_2_');
    $fingerprint = new ReflectionMethod(WP_FTS_Storage_Mysql::class, 'search_cursor_fingerprint');
    $plan = [
        'groups' => [[[
            'key' => WP_FTS_TermNamespace::namespace_term('en', 'namespaceprobe'),
            'rank' => 0,
        ]]],
    ];

    $mainHash = $fingerprint->invoke($mainStorage, $plan, null, 'OR', [], 7);
    $siteHash = $fingerprint->invoke($siteStorage, $plan, null, 'OR', [], 7);

    assert_true(!hash_equals($mainHash, $siteHash), 'a network-wide signing salt must not make a cursor replayable against another blog index');
});

test_case_with_pdo_sqlite_fixture('relational v6 integerizes surface-range scores before forward and reverse cursors', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    for ($postId = 10; $postId <= 15; $postId++) {
        wp_fts_v4_regression_add_post($wpdb, $postId, '2026-02-01 00:00:00');
    }
    // REAL affinity is intentional: it proves the ranking expression itself
    // integerizes MySQL-style fractional prefix arithmetic before cursoring.
    wp_fts_v4_regression_add_term($wpdb, 'prealpha', array_fill_keys(range(10, 15), 101.0));
    wp_fts_v6_regression_add_surface($wpdb, 'prealpha', array_fill_keys(range(10, 15), 101.0));

    $groups = wp_fts_v4_regression_groups('pre');
    $options = array_replace(wp_fts_v4_regression_search_options(), [
        'prefix_matching' => true,
        'prefix_group_index' => 0,
        'prefix_surface' => ['lang' => 'en', 'term' => 'pre'],
        'prefix_min_length' => 3,
    ]);
    $first = $storage->search_page($groups, $options);
    assert_same([15, 14], array_column($first['results'], 'doc_id'), 'first prefix page should use the complete score/date/id ordering');
    $firstScore = (float) ($first['results'][0]['score'] ?? 0.0);
    assert_same($firstScore, floor($firstScore), 'prefix arithmetic must be integerized before the score is signed into a cursor');

    $second = $storage->search_page($groups, array_replace($options, ['cursor' => $first['next_cursor']]));
    $third = $storage->search_page($groups, array_replace($options, ['cursor' => $second['next_cursor']]));
    $forwardIds = [
        ...array_column($first['results'], 'doc_id'),
        ...array_column($second['results'], 'doc_id'),
        ...array_column($third['results'], 'doc_id'),
    ];
    assert_same([15, 14, 13, 12, 11, 10], $forwardIds, 'integer prefix cursor boundaries must neither omit nor repeat equal-score rows');
    assert_same(count($forwardIds), count(array_unique($forwardIds)), 'forward prefix pages should contain no duplicate document ids');

    $reverse = $storage->search_page($groups, array_replace($options, [
        'cursor' => $second['previous_cursor'],
        'direction' => 'before',
    ]));
    assert_same(
        array_column($first['results'], 'doc_id'),
        array_column($reverse['results'], 'doc_id'),
        'the reverse cursor from page two should reproduce page one exactly'
    );
});

test_case_with_pdo_sqlite_fixture('relational v4 regression rejects cross-query and cross-filter cursors', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    for ($postId = 21; $postId <= 26; $postId++) {
        wp_fts_v4_regression_add_post($wpdb, $postId, '2026-03-' . str_pad((string) ($postId - 20), 2, '0', STR_PAD_LEFT) . ' 00:00:00');
    }
    wp_fts_v4_regression_add_term($wpdb, 'queryone', array_fill_keys(range(21, 26), 100.0));
    wp_fts_v4_regression_add_term($wpdb, 'querytwo', array_fill_keys(range(21, 26), 100.0));

    $options = wp_fts_v4_regression_search_options();
    $first = $storage->search_page(wp_fts_v4_regression_groups('queryone'), $options);
    $cursor = $first['next_cursor'] ?? null;
    assert_true(is_string($cursor) && $cursor !== '', 'cross-input fixture should produce a next cursor');

    $cases = [
        'query terms' => [wp_fts_v4_regression_groups('querytwo'), $options],
        'post type' => [wp_fts_v4_regression_groups('queryone'), array_replace($options, ['post_types' => ['page']])],
        'post status' => [wp_fts_v4_regression_groups('queryone'), array_replace($options, ['post_statuses' => ['draft']])],
        'lower date boundary' => [wp_fts_v4_regression_groups('queryone'), array_replace($options, ['date_after' => '2026-03-03 00:00:00'])],
        'upper date boundary' => [wp_fts_v4_regression_groups('queryone'), array_replace($options, ['date_before' => '2026-03-05 00:00:00'])],
    ];
    foreach ($cases as $label => [$groups, $changedOptions]) {
        wp_fts_v4_regression_assert_invalid_cursor(
            static fn() => $storage->search_page($groups, array_replace($changedOptions, ['cursor' => $cursor])),
            "a cursor must be rejected after changing {$label}"
        );
    }
});

test_case_with_pdo_sqlite_fixture('relational v4 regression rejects cursors after the durable search epoch advances', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    for ($postId = 31; $postId <= 34; $postId++) {
        wp_fts_v4_regression_add_post($wpdb, $postId, '2026-03-10 00:00:00');
    }
    wp_fts_v4_regression_add_term($wpdb, 'epochprobe', array_fill_keys(range(31, 34), 100.0));

    $groups = wp_fts_v4_regression_groups('epochprobe');
    $options = wp_fts_v4_regression_search_options(1);
    $first = $storage->search_page($groups, $options);
    $cursor = $first['next_cursor'] ?? null;
    assert_true(is_string($cursor) && $cursor !== '', 'epoch fixture should issue a signed cursor');

    // An impact-only rewrite can change order without changing document
    // frequency. The singleton epoch is therefore the authenticated boundary,
    // rather than a fingerprint derived only from dictionary statistics.
    $wpdb->execute(
        'UPDATE wp_fts_work SET generation = generation + 1 WHERE job_key = ?',
        [WP_FTS_Index_Queue::SEARCH_EPOCH_JOB_KEY]
    );
    $wpdb->execute(
        'UPDATE wp_fts_postings SET impact = impact + 1 WHERE post_id = ?',
        [34]
    );
    $wpdb->queries = [];
    wp_fts_v4_regression_assert_invalid_cursor(
        static fn() => $storage->search_page($groups, array_replace($options, ['cursor' => $cursor])),
        'an old cursor must fail closed after a same-DF impact rewrite'
    );
    assert_same(1, count($wpdb->queries), 'a stale cursor should be rejected after the combined plan/epoch read and before ranking');
    assert_contains('meta:search-epoch', $wpdb->queries[0] ?? '', 'the existing plan statement should read the epoch by its fixed primary key');
    assert_true(!str_contains(implode("\n", $wpdb->queries), '/* wp_fts:rank */'), 'stale cursor rejection must issue no ranking statement');
});

test_case_with_pdo_sqlite_fixture('relational v4 search statements fail closed when publication changes between bounded reads', function (): void {
    $search = static function (string $mutateBefore): array {
        [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
        wp_fts_v4_regression_add_post($wpdb, 35, '2026-03-10 00:00:00');
        wp_fts_v4_regression_add_term($wpdb, 'snapshotrace', [35 => 100.0]);
        $options = array_replace(wp_fts_v4_regression_search_options(1), [
            'include_metadata' => $mutateBefore === 'hydrate',
        ]);
        $wpdb->queries = [];
        $wpdb->readQueryObserver = static function (string $sql) use ($wpdb, $mutateBefore): void {
            if (!str_contains($sql, "/* wp_fts:{$mutateBefore} */")) {
                return;
            }
            $wpdb->readQueryObserver = null;
            $wpdb->execute(
                'UPDATE wp_options SET option_value = ? WHERE option_name = ?',
                [serialize([
                    'incarnation' => str_repeat('d', 32),
                    'profile_hash' => str_repeat('e', 40),
                ]), WP_FTS_Plugin::SEARCH_READY_INCARNATION_OPTION]
            );
        };
        $error = null;
        try {
            $storage->search_page(wp_fts_v4_regression_groups('snapshotrace'), $options);
        } catch (WP_FTS_Search_Unavailable $caught) {
            $error = $caught;
        }

        return [$wpdb, $error];
    };

    foreach (['plan' => 1, 'rank' => 2, 'hydrate' => 3] as $boundary => $expectedQueries) {
        [$wpdb, $error] = $search($boundary);
        assert_true($error instanceof WP_FTS_Search_Unavailable, "a publication change immediately before {$boundary} must return the typed unavailable result");
        assert_same($expectedQueries, count($wpdb->queries), "a {$boundary} publication race must stop after the first invalid snapshot statement");
        assert_true(str_contains($wpdb->queries[$expectedQueries - 1] ?? '', "/* wp_fts:{$boundary} */"), "the {$boundary} race should be detected by that statement's own control sentinel");
    }
});

test_case_with_pdo_sqlite_fixture('relational v4 search uses one authoritative sentinel per statement including valid zero hits', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    wp_fts_v4_regression_add_post($wpdb, 36, '2026-03-10 00:00:00', 'post', 'draft');
    wp_fts_v4_regression_add_term($wpdb, 'snapshotzerohit', [36 => 100.0]);

    $wpdb->queries = [];
    $page = $storage->search_page(
        wp_fts_v4_regression_groups('snapshotzerohit'),
        wp_fts_v4_regression_search_options(1)
    );
    assert_same([], $page['results'] ?? null, 'a valid publication with no visible hits should return an empty page rather than unavailable');
    assert_same(2, count($wpdb->queries), 'a valid zero-hit search should remain one plan plus one rank statement');
    assert_same(1, substr_count($wpdb->queries[0] ?? '', 'schema_option.option_name'), 'planning should evaluate one authoritative publication sentinel');
    // Ranking needs an inner gate so revoked readiness prevents the expensive
    // posting scan, plus an outer sentinel that remains observable at zero hits.
    assert_same(2, substr_count($wpdb->queries[1] ?? '', 'schema_option.option_name'), 'ranking should retain its work-prevention gate and its zero-hit control row');
    assert_true(str_contains($wpdb->queries[1] ?? '', 'snapshot.snapshot_ready'), 'zero-hit ranking must retain a control row so readiness remains observable');

    [$hydrationWpdb, $hydrationStorage] = wp_fts_v4_regression_search_fixture();
    wp_fts_v4_regression_add_post($hydrationWpdb, 37, '2026-03-10 00:00:00');
    wp_fts_v4_regression_add_term($hydrationWpdb, 'snapshothydrate', [37 => 100.0]);
    $hydrationWpdb->queries = [];
    $hydrationStorage->search_page(
        wp_fts_v4_regression_groups('snapshothydrate'),
        array_replace(wp_fts_v4_regression_search_options(1), ['include_metadata' => true])
    );
    assert_same(3, count($hydrationWpdb->queries), 'a hydrated hit should remain one plan, one rank, and one hydrate statement');
    assert_same(1, substr_count($hydrationWpdb->queries[0] ?? '', 'schema_option.option_name'), 'hydrated planning should evaluate one publication sentinel');
    assert_same(2, substr_count($hydrationWpdb->queries[1] ?? '', 'schema_option.option_name'), 'hydrated ranking should retain both its pre-scan gate and result sentinel');
    assert_same(1, substr_count($hydrationWpdb->queries[2] ?? '', 'schema_option.option_name'), 'page-sized hydration should evaluate one publication sentinel');
});

test_case_with_pdo_sqlite_fixture('relational v4 cursors cannot cross a capability publication with an unchanged epoch', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    for ($postId = 38; $postId <= 41; $postId++) {
        wp_fts_v4_regression_add_post($wpdb, $postId, '2026-03-10 00:00:00');
    }
    wp_fts_v4_regression_add_term($wpdb, 'capabilitycursor', array_fill_keys(range(38, 41), 100.0));
    $oldOptions = wp_fts_v4_regression_search_options(1);
    $first = $storage->search_page(wp_fts_v4_regression_groups('capabilitycursor'), $oldOptions);
    $cursor = $first['next_cursor'] ?? null;
    assert_true(is_string($cursor) && $cursor !== '', 'the capability replay fixture should issue a signed cursor');

    $newIncarnation = str_repeat('d', 32);
    $newProfile = str_repeat('e', 40);
    $wpdb->execute(
        'UPDATE wp_options SET option_value = ? WHERE option_name = ?',
        [$newIncarnation, WP_FTS_Plugin::READINESS_INCARNATION_OPTION]
    );
    $wpdb->execute(
        'UPDATE wp_options SET option_value = ? WHERE option_name = ?',
        [serialize(['incarnation' => $newIncarnation, 'profile_hash' => $newProfile]), WP_FTS_Plugin::SEARCH_READY_INCARNATION_OPTION]
    );
    $epochBefore = $wpdb->dbh->query(
        "SELECT generation || ':' || payload FROM wp_fts_work WHERE job_key = 'meta:search-epoch'"
    )->fetchColumn();
    $wpdb->queries = [];
    wp_fts_v4_regression_assert_invalid_cursor(
        static fn() => $storage->search_page(
            wp_fts_v4_regression_groups('capabilitycursor'),
            array_replace($oldOptions, [
                'cursor' => $cursor,
                'search_ready_incarnation' => $newIncarnation,
                'search_ready_profile_hash' => $newProfile,
            ])
        ),
        'a cursor must bind the exact published incarnation and profile even when the search epoch did not move'
    );
    $epochAfter = $wpdb->dbh->query(
        "SELECT generation || ':' || payload FROM wp_fts_work WHERE job_key = 'meta:search-epoch'"
    )->fetchColumn();
    assert_same($epochBefore, $epochAfter, 'the replay regression must hold the durable epoch generation and incarnation constant');
    assert_same(1, count($wpdb->queries), 'a cross-capability cursor should fail after the current plan sentinel and before ranking');
    assert_true(!str_contains(implode("\n", $wpdb->queries), '/* wp_fts:rank */'), 'a cross-capability cursor must issue no ranking statement');
});

test_case_with_pdo_sqlite_fixture('relational v4 validates cursors before every empty or impossible search return', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    for ($postId = 41; $postId <= 44; $postId++) {
        wp_fts_v4_regression_add_post($wpdb, $postId, '2026-03-11 00:00:00');
    }
    wp_fts_v4_regression_add_term($wpdb, 'cursorpresent', array_fill_keys(range(41, 44), 100.0));

    $options = wp_fts_v4_regression_search_options(1);
    $presentGroups = wp_fts_v4_regression_groups('cursorpresent');
    $first = $storage->search_page($presentGroups, $options);
    $cursor = $first['next_cursor'] ?? null;
    assert_true(is_string($cursor) && $cursor !== '', 'impossible-plan fixture should issue a signed cursor');

    $wpdb->queries = [];
    wp_fts_v4_regression_assert_invalid_cursor(
        static fn() => $storage->search_page(
            wp_fts_v4_regression_groups('cursorabsent'),
            array_replace($options, ['cursor' => $cursor])
        ),
        'a cross-query cursor must be rejected even when the new dictionary identity is absent'
    );
    assert_same(1, count($wpdb->queries), 'an impossible cross-query cursor should execute only the bounded plan/epoch statement');
    assert_contains('/* wp_fts:plan */', $wpdb->queries[0] ?? '', 'the sole impossible-query statement should be dictionary planning');
    assert_true(!str_contains(implode("\n", $wpdb->queries), '/* wp_fts:rank */'), 'an impossible cross-query cursor must be rejected before ranking');

    $tamperOffset = intdiv(strlen($cursor), 2);
    $tampered = substr($cursor, 0, $tamperOffset)
        . ($cursor[$tamperOffset] === 'A' ? 'B' : 'A')
        . substr($cursor, $tamperOffset + 1);
    $wpdb->queries = [];
    wp_fts_v4_regression_assert_invalid_cursor(
        static fn() => $storage->search_page(
            wp_fts_v4_regression_groups('cursorabsent'),
            array_replace($options, ['cursor' => $tampered])
        ),
        'a tampered cursor must be rejected even when the dictionary plan is impossible'
    );
    assert_same(1, count($wpdb->queries), 'a tampered impossible-plan cursor should be rejected after planning and before ranking');
    assert_true(!str_contains(implode("\n", $wpdb->queries), '/* wp_fts:rank */'), 'tampered impossible-plan rejection must issue no rank statement');

    $wpdb->execute(
        'UPDATE wp_fts_work SET generation = generation + 1 WHERE job_key = ?',
        [WP_FTS_Index_Queue::SEARCH_EPOCH_JOB_KEY]
    );
    $wpdb->execute(
        'DELETE FROM wp_fts_postings WHERE term_id = (SELECT term_id FROM wp_fts_terms WHERE term = ?)',
        ['cursorpresent']
    );
    $wpdb->execute('DELETE FROM wp_fts_terms WHERE term = ?', ['cursorpresent']);
    $wpdb->queries = [];
    wp_fts_v4_regression_assert_invalid_cursor(
        static fn() => $storage->search_page($presentGroups, array_replace($options, ['cursor' => $cursor])),
        'a stale cursor must be rejected even when its exact term disappeared with the epoch change'
    );
    assert_same(1, count($wpdb->queries), 'a vanished-term stale cursor should execute only the bounded plan/epoch statement');
    assert_true(!str_contains(implode("\n", $wpdb->queries), '/* wp_fts:rank */'), 'a vanished-term stale cursor must be rejected before ranking');

    $wpdb->queries = [];
    wp_fts_v4_regression_assert_invalid_cursor(
        static fn() => $storage->search_page([], array_replace($options, ['cursor' => $cursor])),
        'direct storage must reject a cursor when normalization produces no query groups'
    );
    assert_same([], $wpdb->queries, 'an empty direct-storage plan should reject its cursor before SQL');

    $emptyAnalyzer = new class {
        /** Force the public cursor path to confront an analyzer-empty plan. */
        public function analyze_query_occurrences(string $query, array $options = []): array
        {
            return [];
        }
    };
    wp_fts_v4_regression_assert_invalid_cursor(
        static fn() => WP_FTS_Searcher::for_set_oriented_storage($storage, $emptyAnalyzer)->search(
            'ignored',
            ['cursor' => $cursor, 'lang' => 'en']
        ),
        'the public searcher must reject a cursor when analysis produces no query groups'
    );
    assert_same([], $wpdb->queries, 'an analyzer-empty cursor request should reject before storage SQL');
});

test_case_with_pdo_sqlite_fixture('relational planning cardinality is independent of adversarial lexical neighbors', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    wp_fts_v4_regression_add_post($wpdb, 51, '2026-03-12 00:00:00');
    wp_fts_v4_regression_add_term($wpdb, 'collisiontarget', [51 => 100.0]);

    $wpdb->dbh->beginTransaction();
    $insert = $wpdb->dbh->prepare(
        'INSERT INTO wp_fts_terms (lang,kind,term,doc_freq) VALUES (?,?,?,0)'
    );
    for ($index = 0; $index < 4096; $index++) {
        $insert->bindValue(1, 'en', PDO::PARAM_LOB);
        $insert->bindValue(2, 0, PDO::PARAM_INT);
        $insert->bindValue(3, 'collisiontarget-decoy-' . $index, PDO::PARAM_LOB);
        $insert->execute();
    }
    $wpdb->dbh->commit();

    $wpdb->queries = [];
    $payload = $storage->search_page(
        wp_fts_v4_regression_groups('collisiontarget'),
        wp_fts_v4_regression_search_options(1)
    );
    assert_same([51], array_column($payload['results'], 'doc_id'), 'full lexical identity should select the requested term despite thousands of adjacent dictionary keys');

    $planSql = wp_fts_v4_regression_last_plan_sql($wpdb);
    assert_contains('LEFT JOIN wp_fts_terms exact_term', $planSql, 'planning should probe the unique full-identity key from a bounded requested relation');
    assert_contains('exact_term.lang = requested_terms.lang', $planSql, 'planning should join the canonical language identity');
    assert_contains('exact_term.term = requested_terms.term', $planSql, 'planning should join the complete lexical bytes');
    assert_true(!str_contains($planSql, 'term_hash'), 'planning must not compute or scan a redundant hash identity');

    $planRows = $wpdb->dbh->query($planSql)->fetchAll(PDO::FETCH_OBJ);
    assert_same(1, count($planRows), 'one requested identity must return exactly one planning row even with 4,096 adjacent decoys');
    $explainRows = $wpdb->dbh->query('EXPLAIN QUERY PLAN ' . $planSql)->fetchAll(PDO::FETCH_ASSOC);
    $explain = implode("\n", array_map(static fn(array $row): string => (string) ($row['detail'] ?? ''), $explainRows));
    assert_contains('wp_fts_term_identity', $explain, 'the adversarial plan should use the unique composite identity index');
});

test_case_with_pdo_sqlite_fixture('relational v4 pagination advances across a full K+1 window of oversized legacy rows', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    for ($postId = 61; $postId <= 63; $postId++) {
        wp_fts_v4_regression_add_post($wpdb, $postId, '2026-03-13 00:00:00');
    }
    wp_fts_v4_regression_add_term($wpdb, 'oversizedlegacy', array_fill_keys(range(61, 63), 100.0));
    $wpdb->rankCanonicalByteOverrides = [
        63 => WP_FTS_Storage_Mysql::MAX_CANONICAL_POST_BYTES + 1,
        62 => WP_FTS_Storage_Mysql::MAX_CANONICAL_POST_BYTES + 1,
    ];
    $options = array_replace(wp_fts_v4_regression_search_options(1), ['include_metadata' => true]);

    $wpdb->queries = [];
    $first = $storage->search_page(wp_fts_v4_regression_groups('oversizedlegacy'), $options);
    assert_same([], $first['results'], 'oversized legacy rows should not be partially hydrated or returned');
    assert_same(true, $first['has_more'] ?? null, 'a full skipped K+1 ranking window should remain traversable');
    assert_true(is_string($first['next_cursor'] ?? null) && $first['next_cursor'] !== '', 'the cursor must advance to the last inspected oversized boundary');
    assert_same(2, count($wpdb->queries), 'an empty accepted page should execute plan and rank but no hydration statement');

    $wpdb->queries = [];
    $second = $storage->search_page(
        wp_fts_v4_regression_groups('oversizedlegacy'),
        array_replace($options, ['cursor' => $first['next_cursor']])
    );
    assert_same([61], array_column($second['results'], 'doc_id'), 'the next cursor should reach the first returnable row after all skipped oversized rows');
    assert_same(false, $second['has_more'] ?? null, 'the page after the skipped K+1 window should terminate normally');
    assert_same(3, count($wpdb->queries), 'the returnable continuation should retain plan, rank, and one bounded hydration statement');

    [$reverseWpdb, $reverseStorage] = wp_fts_v4_regression_search_fixture();
    for ($postId = 71; $postId <= 75; $postId++) {
        wp_fts_v4_regression_add_post($reverseWpdb, $postId, '2026-03-14 00:00:00');
    }
    wp_fts_v4_regression_add_term($reverseWpdb, 'oversizedreverse', array_fill_keys(range(71, 75), 100.0));
    $reverseWpdb->rankCanonicalByteOverrides = [
        74 => WP_FTS_Storage_Mysql::MAX_CANONICAL_POST_BYTES + 1,
        73 => WP_FTS_Storage_Mysql::MAX_CANONICAL_POST_BYTES + 1,
    ];
    $reverseGroups = wp_fts_v4_regression_groups('oversizedreverse');
    $reverseOptions = array_replace(wp_fts_v4_regression_search_options(1), ['include_metadata' => true]);
    $origin = $reverseStorage->search_page($reverseGroups, $reverseOptions);
    $skippedForward = $reverseStorage->search_page(
        $reverseGroups,
        array_replace($reverseOptions, ['cursor' => $origin['next_cursor']])
    );
    $returnable = $reverseStorage->search_page(
        $reverseGroups,
        array_replace($reverseOptions, ['cursor' => $skippedForward['next_cursor']])
    );
    assert_same([75], array_column($origin['results'], 'doc_id'), 'reverse fixture should start above the two oversized rows');
    assert_same([], $skippedForward['results'], 'forward traversal should cross the reverse fixture oversized window');
    assert_same([72], array_column($returnable['results'], 'doc_id'), 'forward traversal should reach a returnable row below the oversized window');

    $skippedReverse = $reverseStorage->search_page($reverseGroups, array_replace($reverseOptions, [
        'cursor' => $returnable['previous_cursor'],
        'direction' => 'before',
    ]));
    assert_same([], $skippedReverse['results'], 'reverse traversal should skip the complete oversized K+1 window without returning partial rows');
    assert_true(is_string($skippedReverse['previous_cursor'] ?? null) && $skippedReverse['previous_cursor'] !== '', 'reverse traversal must sign the farthest inspected oversized boundary');
    $backAtOrigin = $reverseStorage->search_page($reverseGroups, array_replace($reverseOptions, [
        'cursor' => $skippedReverse['previous_cursor'],
        'direction' => 'before',
    ]));
    assert_same([75], array_column($backAtOrigin['results'], 'doc_id'), 'the reverse progress cursor should reach the original returnable page without looping');
});

test_case_with_pdo_sqlite_fixture('relational v6 ranges over one normalized surface per indexed token', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    wp_fts_v4_regression_add_post($wpdb, 900, '2026-04-01 00:00:00');
    wp_fts_v4_regression_add_term($wpdb, 'construction', [900 => 0.001]);
    wp_fts_v6_regression_add_surface($wpdb, 'construction', [900 => 0.001]);
    for ($ordinal = 0; $ordinal <= 256; $ordinal++) {
        $term = 'construction' . str_pad((string) $ordinal, 3, '0', STR_PAD_LEFT);
        $postId = 1000 + $ordinal;
        wp_fts_v4_regression_add_post($wpdb, $postId, '2026-04-01 00:00:00');
        $impact = $ordinal === 256 ? 10000.0 : 100.0;
        wp_fts_v4_regression_add_term($wpdb, $term, [$postId => $impact]);
        wp_fts_v6_regression_add_surface($wpdb, $term, [$postId => $impact]);
    }

    $groups = wp_fts_v4_regression_groups('construction');
    $exactPayload = $storage->search_page($groups, wp_fts_v4_regression_search_options(10));
    assert_same([900], array_column($exactPayload['results'], 'doc_id'), 'prefix-disabled exact search must read only the lexical identity');
    assert_same(
        [0, 1],
        array_map('intval', $wpdb->dbh->query("SELECT kind FROM wp_fts_terms WHERE hex(lang) = '656E' AND hex(term) = '636F6E737472756374696F6E' ORDER BY kind")->fetchAll(PDO::FETCH_COLUMN)),
        'lexical and normalized-surface identities for the same bytes must remain separately typed dictionary rows'
    );

    $options = array_replace(wp_fts_v4_regression_search_options(10), [
        'prefix_matching' => true,
        'prefix_group_index' => 0,
        'prefix_surface' => ['lang' => 'en', 'term' => 'construction'],
        'prefix_min_length' => 4,
    ]);
    $wpdb->queries = [];
    $payload = $storage->search_page($groups, $options);
    $ids = array_column($payload['results'], 'doc_id');
    assert_same(1256, $ids[0] ?? null, 'the 257th completion must participate through the one indexed surface range beyond the old 256-term expansion cap');

    $rankSql = implode("\n", array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_contains($sql, '/* wp_fts:rank */')
    ));
    assert_contains('pt.kind = 1', $rankSql, 'prefix ranking should restrict its range to normalized-surface identities');
    assert_same(1, substr_count($rankSql, 'pt.term >='), 'prefix ranking should compile exactly one inclusive lower range bound');
    assert_same(1, substr_count($rankSql, 'pt.term <'), 'prefix ranking should compile exactly one exclusive successor bound');
    assert_true(!str_contains(strtolower($rankSql), ' like '), 'prefix ranking must not fall back to a LIKE scan');
    assert_same(1, substr_count($rankSql, 'JOIN wp_fts_documents d_f'), 'a broad exact-plus-prefix query should pay one visibility join after compacting posting arms');
    assert_true(!str_contains($rankSql, 'd_exact_match') && !str_contains($rankSql, 'd_prefix_match'), 'broad posting arms must not repeat visibility per alternative or prefix row');
    assert_same(2, count($wpdb->queries), 'a surface-range prefix search should remain exactly one plan plus one rank statement');
    $groupedPosition = strpos($rankSql, ') grouped');
    $visibilityPosition = strpos($rankSql, 'JOIN wp_fts_documents d_f');
    $orderPosition = strpos($rankSql, 'ORDER BY scored.score');
    assert_true(
        $groupedPosition !== false
            && $visibilityPosition !== false
            && $orderPosition !== false
            && $groupedPosition < $visibilityPosition
            && $visibilityPosition < $orderPosition,
        'broad search should compact postings first but still apply visibility before ordering and LIMIT'
    );
});

test_case('relational visibility never walks taxonomy relationships per ranked candidate', function (): void {
    $storage = new WP_FTS_Storage_Mysql(new WP_FTS_Test_WPDB());
    $visibilitySql = new ReflectionMethod(WP_FTS_Storage_Mysql::class, 'visibility_sql');
    $visibilitySql->setAccessible(true);
    $visibility = $visibilitySql->invoke($storage, 'ranked.post_id', 'ranked', []);
    assert_true(!str_contains((string) ($visibility['where'] ?? ''), 'term_relationships'), 'ranked visibility must not inspect taxonomy relationships');
    assert_true(!str_contains((string) ($visibility['joins'] ?? ''), 'term_relationships'), 'ranked visibility joins must remain independent of taxonomy fanout');
    assert_contains('LEFT JOIN wp_fts_work dirty_ranked FORCE INDEX (dirty)', (string) ($visibility['joins'] ?? ''), 'dirty visibility should always probe the post-first work index');
});

test_case('relational v6 real Russian ambiguity retains exact lemmas without mbstring', function (): void {
    assert_or_pending(
        WP_FTS_AnalyzerPackValidator::gzip_available(),
        'gzip support should be available for the bundled Russian real-pack prefix regression',
        'PHP zlib gzip support is unavailable, so the Russian real-pack prefix regression is skipped.'
    );

    $manifest = dirname(__DIR__, 2) . '/resources/analyzer-packs/ru-unimorph-rus-50dcabfd0a04/manifest.json';
    $analyzer = new WP_FTS_Analyzer([
        'default_lang' => 'ru',
        'auto_detect_language' => false,
        'lemmatizer_packs_by_lang' => ['ru' => $manifest],
    ]);
    $analysis = $analyzer->analyze_query_occurrences('МАТЕРИ', [
        'lang' => 'ru',
        '_include_query_surface' => true,
    ]);
    assert_same(
        ['материть', 'матерь', 'мати', 'мать'],
        array_column($analysis, 'term'),
        'the pinned Russian pack should retain all four exact lemmas for the ambiguous final surface'
    );
    assert_same(
        ['матери'],
        array_values(array_unique(array_column($analysis, 'normalized_surface'))),
        'the real analyzer should expose one normalized typed surface distinct from every exact lemma'
    );
    assert_true(
        !in_array('матери', array_column($analysis, 'term'), true),
        'the regression surface must differ from every lemma or it cannot detect a lemma-derived prefix identity'
    );
});

test_case_with_pdo_sqlite_fixture('relational v6 real Russian ambiguity ranges on the typed surface without replacing exact lemmas', function (): void {
    $manifest = dirname(__DIR__, 2) . '/resources/analyzer-packs/ru-unimorph-rus-50dcabfd0a04/manifest.json';
    $analyzer = new WP_FTS_Analyzer([
        'default_lang' => 'ru',
        'auto_detect_language' => false,
        'lemmatizer_packs_by_lang' => ['ru' => $manifest],
    ]);

    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    $terms = [
        3001 => 'материть',
        3002 => 'матерь',
        3003 => 'мати',
        3004 => 'мать',
        // This normalized surface is inside the typed `матери` dictionary range.
        3005 => 'материализация',
        // This begins an exact lemma but not the typed surface. It must remain
        // outside that posting while the exact `мать` dictionary row still joins.
        3006 => 'матьbait',
    ];
    foreach ($terms as $postId => $term) {
        wp_fts_v4_regression_add_post($wpdb, $postId, '2026-04-01 00:00:00');
        wp_fts_v4_regression_add_term($wpdb, $term, [$postId => 100.0], 'ru');
        wp_fts_v6_regression_add_surface($wpdb, $term, [$postId => 100.0], 'ru');
    }

    $wpdb->queries = [];
    $payload = (new WP_FTS_Searcher($storage, $analyzer))->search('МАТЕРИ', [
        'lang' => 'ru',
        'mode' => 'OR',
        'limit' => 10,
        'prefix_matching' => true,
        'prefix_min_length' => 4,
        'post_type' => ['post'],
        'post_status' => ['publish'],
        '_search_ready_incarnation' => wp_fts_v4_regression_ready_incarnation(),
        '_search_ready_profile_hash' => wp_fts_v4_regression_ready_profile_hash(),
    ]);
    $ids = array_column($payload['results'] ?? [], 'doc_id');
    sort($ids, SORT_NUMERIC);
    assert_same(
        [3001, 3002, 3003, 3004, 3005],
        $ids,
        'one typed-surface range should add its completion while all four exact lexical lemmas remain searchable and lemma-only bait stays excluded'
    );

    $rankSql = wp_fts_v4_regression_last_rank_sql($wpdb);
    $normalizedSurface = 'матери';
    assert_contains("pt.kind = 1 AND pt.term >= X'" . bin2hex($normalizedSurface) . "'", $rankSql, 'the real relational range lower bound should be the normalized typed surface, not any lemma or raw uppercase text');
    assert_same(1, substr_count($rankSql, 'pt.term >='), 'four exact lexical lemmas must still produce only one surface range');
    assert_same(1, substr_count($rankSql, 'pt.term <'), 'the surface range must have one exclusive byte-successor bound');
    assert_same(2, count($wpdb->queries), 'an unhydrated real-pack prefix search should use exactly one plan and one rank statement');
});

test_case_with_pdo_sqlite_fixture('relational v6 AND prefixes intersect one range-led scan with exact candidates', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    for ($postId = 1; $postId <= 100; $postId++) {
        wp_fts_v4_regression_add_post($wpdb, $postId, '2026-04-02 00:00:00');
    }
    wp_fts_v4_regression_add_term($wpdb, 'commonanchor', array_fill_keys(range(1, 100), 100.0));
    wp_fts_v4_regression_add_term($wpdb, 'rareprecompletion', [1 => 100.0, 2 => 100.0]);
    wp_fts_v6_regression_add_surface($wpdb, 'rareprecompletion', [1 => 100.0, 2 => 100.0]);
    wp_fts_v4_regression_add_term($wpdb, 'lemmaoutside', [3 => 200.0]);

    $groups = [
        [['key' => WP_FTS_TermNamespace::namespace_term('en', 'commonanchor'), 'rank' => 0]],
        [
            ['key' => WP_FTS_TermNamespace::namespace_term('en', 'rarepre'), 'rank' => 0],
            ['key' => WP_FTS_TermNamespace::namespace_term('en', 'lemmaoutside'), 'rank' => 1],
        ],
    ];
    $wpdb->queries = [];
    $payload = $storage->search_page($groups, array_replace(wp_fts_v4_regression_search_options(10), [
        'mode' => 'AND',
        'prefix_matching' => true,
        'prefix_group_index' => 1,
        'prefix_surface' => ['lang' => 'en', 'term' => 'rarepre'],
        'prefix_min_length' => 4,
        'explain' => true,
    ]));
    $ids = array_column($payload['results'], 'doc_id');
    sort($ids, SORT_NUMERIC);
    assert_same([1, 2, 3], $ids, 'range-led prefix matching must include both surface completions and exact lexical alternatives from its logical group');
    assert_same(1, $payload['explain']['anchor_group'] ?? null, 'multi-group AND must anchor the lower-cost final prefix group');

    $rankSql = wp_fts_v4_regression_last_rank_sql($wpdb);
    assert_same(1, substr_count($rankSql, 'pt.term >='), 'range-led ranking must compile one normalized-surface predicate');
    assert_same(1, substr_count($rankSql, 'pt.term <'), 'range-led ranking must compile one exclusive range successor');
    assert_contains('LEFT JOIN wp_fts_postings po', $rankSql, 'the selective prefix candidates must probe remaining exact groups by post and term id');
    assert_contains("(SELECT pt.term_id, pt.doc_freq\nFROM wp_fts_terms pt", $rankSql, 'the surface anchor must begin with one indexed dictionary range');
    assert_contains('JOIN wp_fts_postings prefix_posting', $rankSql, 'the dictionary range must stream only its matching posting lists');
    assert_contains('JOIN wp_fts_postings exact_posting', $rankSql, 'exact alternatives from the prefix logical group must join the same candidate union');
    assert_true(!str_contains($rankSql, 'JOIN wp_fts_postings ppo ON ppo.post_id = c.post_id'), 'the surface arm must not classify every posting of every exact candidate');
    assert_same(1, substr_count($rankSql, 'JOIN wp_fts_documents d_surface_anchor'), 'prefix candidates must apply visibility once before probing common exact groups');
    assert_same(0, substr_count($rankSql, 'JOIN wp_fts_documents d_exact_anchor'), 'the common exact group must not be materialized as an anchor');
    assert_true(!str_contains($rankSql, 'JOIN wp_fts_documents d_f'), 'an anchored AND must not repeat complete visibility after its post-first probes');
    assert_contains('JOIN wp_posts wp_f ON wp_f.ID = ranked.post_id', $rankSql, 'an anchored AND should retain only the canonical date join needed after early visibility');
    $planSql = wp_fts_v4_regression_last_plan_sql($wpdb);
    assert_same(1, substr_count($planSql, 'SUM(surface_identity.doc_freq)'), 'surface planning must cost the final prefix range once');
    assert_same(2, count($wpdb->queries), 'a range-led surface AND should remain exactly one plan plus one rank statement');

    wp_fts_v6_regression_add_surface($wpdb, 'selectivecompletion', [1 => 100.0, 2 => 300.0, 3 => 200.0]);
    $wpdb->queries = [];
    $selectiveGroups = [
        [['key' => WP_FTS_TermNamespace::namespace_term('en', 'commonanchor'), 'rank' => 0]],
        [['key' => WP_FTS_TermNamespace::namespace_term('en', 'selective'), 'rank' => 0]],
    ];
    $selectiveOptions = array_replace(wp_fts_v4_regression_search_options(2), [
        'mode' => 'AND',
        'prefix_matching' => true,
        'prefix_group_index' => 1,
        'prefix_surface' => ['lang' => 'en', 'term' => 'selective'],
        'prefix_min_length' => 4,
        'explain' => true,
    ]);
    $selective = $storage->search_page($selectiveGroups, $selectiveOptions);
    assert_same([2, 3], array_column($selective['results'], 'doc_id'), 'a final prefix with no exact dictionary identity must retain exact AND order and page size');
    assert_same(true, $selective['has_more'] ?? null, 'the selective prefix anchor should publish a continuation at the page boundary');
    assert_true(is_string($selective['next_cursor'] ?? null) && $selective['next_cursor'] !== '', 'the selective prefix anchor should sign its continuation cursor');
    assert_same(1, $selective['explain']['anchor_group'] ?? null, 'a three-post prefix must anchor instead of scanning the 100-post exact group');
    $selectiveRankSql = wp_fts_v4_regression_last_rank_sql($wpdb);
    assert_contains('LEFT JOIN wp_fts_postings po', $selectiveRankSql, 'each prefix candidate must probe the common exact group by primary key');
    assert_true(!str_contains($selectiveRankSql, 'SELECT DISTINCT ap.post_id'), 'the 100-post exact group must not be materialized as the selective query anchor');
    assert_same(2, count($wpdb->queries), 'selective prefix AND must remain one plan plus one rank statement');

    $nextSelective = $storage->search_page($selectiveGroups, array_replace($selectiveOptions, [
        'cursor' => $selective['next_cursor'],
    ]));
    assert_same([1], array_column($nextSelective['results'], 'doc_id'), 'the selective prefix anchor cursor must return the remaining exact member without a skip or duplicate');
    assert_same(false, $nextSelective['has_more'] ?? null, 'the final selective prefix page should terminate traversal');
    assert_same(4, count($wpdb->queries), 'two selective prefix pages must remain exactly two statements each');
});

test_case_with_pdo_sqlite_fixture('relational v6 broad non-anchor prefixes execute candidate-first with exact score', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    $wpdb->dbh->beginTransaction();
    for ($postId = 1; $postId <= 8193; $postId++) {
        wp_fts_v4_regression_add_post($wpdb, $postId, '2026-04-02 00:00:00');
    }
    wp_fts_v4_regression_add_term($wpdb, 'candidateanchor', [1 => 100.0]);
    wp_fts_v6_regression_add_surface(
        $wpdb,
        'broadprefixcompletion',
        array_fill_keys(range(1, 8193), 200.0)
    );
    for ($term = 0; $term < 32; $term++) {
        wp_fts_v4_regression_add_term($wpdb, 'unrelated' . $term, [1 => 300.0]);
        wp_fts_v6_regression_add_surface($wpdb, 'unrelated' . $term, [1 => 300.0]);
    }
    $wpdb->dbh->commit();

    $groups = [
        [['key' => WP_FTS_TermNamespace::namespace_term('en', 'candidateanchor'), 'rank' => 0]],
        [['key' => WP_FTS_TermNamespace::namespace_term('en', 'broadprefix'), 'rank' => 0]],
    ];
    $wpdb->queries = [];
    $payload = $storage->search_page($groups, array_replace(wp_fts_v4_regression_search_options(10), [
        'mode' => 'AND',
        'prefix_matching' => true,
        'prefix_group_index' => 1,
        'prefix_surface' => ['lang' => 'en', 'term' => 'broadprefix'],
        'prefix_min_length' => 4,
        'explain' => true,
    ]));

    assert_same([1], array_column($payload['results'], 'doc_id'), 'candidate-first prefix classification must preserve exact AND membership');
    assert_same(100014640.0, $payload['results'][0]['score'] ?? null, 'candidate-first prefix classification must preserve exact per-surface rarity scoring');
    assert_same(0, $payload['explain']['anchor_group'] ?? null, 'the one-row exact group must anchor ahead of the 8,193-row prefix');
    assert_same('candidate_first', $payload['explain']['prefix_strategy'] ?? null, 'the prefix must cross the one-candidate 8,192-posting upper bound');
    $rankSql = wp_fts_v4_regression_last_rank_sql($wpdb);
    assert_contains('JOIN wp_fts_postings ppo ON prefix_candidate.post_id = ppo.post_id', $rankSql, 'candidate-first SQL must scan postings from the bounded exact candidate');
    assert_contains('JOIN wp_fts_terms pt ON pt.term_id = ppo.term_id', $rankSql, 'candidate-first SQL must classify candidate term identities by primary key');
    assert_true(!str_contains($rankSql, 'JOIN wp_fts_postings ppo ON ppo.term_id = pt.term_id'), 'candidate-first SQL must not scan all 8,193 broad-prefix postings');
    assert_same(1, substr_count($rankSql, 'pt.term >='), 'candidate-first SQL must retain one exact binary surface predicate');
    assert_same(2, count($wpdb->queries), 'candidate-first AND must remain exactly one plan plus one rank statement');
});

test_case_with_pdo_sqlite_fixture('relational v6 unavailable surface ranges use exact probes without weakening cursor identity', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    foreach ([1, 2, 3] as $postId) {
        wp_fts_v4_regression_add_post($wpdb, $postId, '2026-04-02 00:00:00');
    }
    wp_fts_v4_regression_add_term($wpdb, 'commonanchor', [1 => 100.0, 2 => 100.0, 3 => 100.0]);
    wp_fts_v4_regression_add_term($wpdb, 'nosurface', [1 => 100.0, 2 => 100.0]);

    $groups = [
        [['key' => WP_FTS_TermNamespace::namespace_term('en', 'commonanchor'), 'rank' => 0]],
        [['key' => WP_FTS_TermNamespace::namespace_term('en', 'nosurface'), 'rank' => 0]],
    ];
    $options = array_replace(wp_fts_v4_regression_search_options(1), [
        'mode' => 'AND',
        'prefix_matching' => true,
        'prefix_group_index' => 1,
        'prefix_surface' => ['lang' => 'en', 'term' => 'nosurf'],
        'prefix_min_length' => 4,
        'explain' => true,
    ]);

    $wpdb->queries = [];
    $page = $storage->search_page($groups, $options);
    assert_same([2], array_column($page['results'] ?? [], 'doc_id'), 'exact alternatives must remain searchable when the optional surface range is empty');
    assert_same(true, $page['has_more'] ?? null, 'the exact-only regression needs a real continuation cursor');
    assert_true(is_string($page['next_cursor'] ?? null) && $page['next_cursor'] !== '', 'the exact-only page should publish its signed continuation cursor');
    $rankSql = wp_fts_v4_regression_last_rank_sql($wpdb);
    assert_contains('JOIN wp_fts_postings po ON po.post_id = c.post_id AND po.term_id = q.term_id', $rankSql, 'an unavailable surface range should use bounded exact term-id probes for each anchor candidate');
    assert_true(!str_contains($rankSql, 'pt.term >='), 'a proven-empty surface range must not force the 8,192-row candidate classifier');
    assert_same(2, count($wpdb->queries), 'the exact fallback should remain one planning and one ranking statement');

    $wpdb->queries = [];
    $replayed = null;
    try {
        $storage->search_page($groups, array_replace($options, [
            'cursor' => $page['next_cursor'],
            'prefix_surface' => ['lang' => 'en', 'term' => 'different'],
        ]));
    } catch (Throwable $error) {
        $replayed = $error;
    }
    assert_true($replayed instanceof InvalidArgumentException, 'an unavailable range must remain part of the cursor fingerprint');
    assert_same(1, count($wpdb->queries), 'a changed empty-range prefix should reject its cursor after planning and before ranking');
});

test_case('relational MySQL rank arms pin bounded drivers ahead of postings and visibility', function (): void {
    $storage = new WP_FTS_Storage_Mysql(new WP_FTS_Test_WPDB());
    $buildRankQuery = new ReflectionMethod(WP_FTS_Storage_Mysql::class, 'build_rank_query');
    $buildRankQuery->setAccessible(true);
    $options = [
        'limit' => 21,
        'post_types' => ['post'],
        'post_statuses' => ['publish'],
    ];
    $exactGroups = [
        0 => [['term_id' => 1, 'weight' => 100, 'doc_freq' => 50000]],
        1 => [['term_id' => 2, 'weight' => 100, 'doc_freq' => 50000]],
        2 => [['term_id' => 3, 'weight' => 1000, 'doc_freq' => 100]],
    ];
    $exact = $buildRankQuery->invoke($storage, $exactGroups, 3, null, 'AND', $options, null);
    $exactSql = (string) ($exact['sql'] ?? '');

    assert_contains('STRAIGHT_JOIN wp_fts_postings ap ON ap.term_id = aq.term_id', $exactSql, 'the rare dictionary arm must drive anchor postings by term id');
    assert_contains('STRAIGHT_JOIN wp_fts_documents d_exact_anchor ON', $exactSql, 'the rare posting candidate must drive its indexed-document probe');
    assert_contains('STRAIGHT_JOIN wp_posts wp_exact_anchor ON', $exactSql, 'the indexed candidate must drive its canonical visibility probe');
    assert_contains('STRAIGHT_JOIN wp_fts_postings po FORCE INDEX (post_term_impact) ON po.post_id = c.post_id', $exactSql, 'the bounded candidate relation must drive every post-first mandatory-group probe');
    assert_contains('STRAIGHT_JOIN wp_posts wp_f ON wp_f.ID = ranked.post_id', $exactSql, 'the bounded ranked relation must drive the final canonical date lookup');

    $prefix = [
        'group_id' => 2,
        'lang' => 'en',
        'term' => 'needle',
        'doc_freq' => 50000,
    ];
    $prefixGroups = $exactGroups;
    $prefixGroups[2][0]['doc_freq'] = 10;
    $prefixRank = $buildRankQuery->invoke($storage, $prefixGroups, 3, $prefix, 'AND', $options, null);
    $prefixRankSql = (string) ($prefixRank['sql'] ?? '');
    assert_same(0, $prefixRank['anchor_group'] ?? null, 'the prefix group must include its complete range cost before anchor selection');
    assert_contains('STRAIGHT_JOIN wp_fts_postings po FORCE INDEX (post_term_impact) ON po.post_id = c.post_id AND po.term_id = q.term_id', $prefixRankSql, 'exact alternatives must remain bounded candidate-first primary-key probes');
    assert_contains("FROM (SELECT pt.term_id, pt.doc_freq\nFROM wp_fts_terms pt FORCE INDEX (term_identity)", $prefixRankSql, 'the surface arm must start from the one indexed dictionary range');
    assert_contains('STRAIGHT_JOIN wp_fts_postings ppo FORCE INDEX (PRIMARY) ON ppo.term_id = pt.term_id', $prefixRankSql, 'the surface dictionary range must drive its posting lists by term-id primary-key prefix');
    assert_contains('STRAIGHT_JOIN (SELECT anchor_posts.post_id', $prefixRankSql, 'surface postings must intersect the complete exact candidate relation');
    assert_contains('prefix_candidate ON prefix_candidate.post_id = ppo.post_id', $prefixRankSql, 'the range-led stream must intersect candidates by post id');
    assert_contains('pt.kind = 1 AND pt.term >=', $prefixRankSql, 'range-led membership must apply the normalized-surface lower bound');
    assert_contains('pt.term <', $prefixRankSql, 'range-led membership must apply the byte successor');
    assert_true(!str_contains($prefixRankSql, 'ppo FORCE INDEX (post_term_impact) ON ppo.post_id = c.post_id'), 'the rank query must not classify every posting of every exact candidate');
    assert_same(1, substr_count($prefixRankSql, 'pt.term >='), 'range-led ranking must contain exactly one range predicate rather than SQL arms per completion');

    $candidateLedGroups = [
        0 => [['term_id' => 3, 'weight' => 1000, 'doc_freq' => 10]],
        1 => [['term_id' => 4, 'weight' => 100, 'doc_freq' => 500]],
    ];
    $nonAnchorPrefix = array_replace($prefix, ['group_id' => 1, 'doc_freq' => 5000]);
    $candidateLed = $buildRankQuery->invoke($storage, $candidateLedGroups, 2, $nonAnchorPrefix, 'AND', $options, null);
    $candidateLedSql = (string) ($candidateLed['sql'] ?? '');
    assert_contains('STRAIGHT_JOIN wp_fts_postings po FORCE INDEX (post_term_impact) ON po.post_id = c.post_id AND po.term_id = q.term_id', $candidateLedSql, 'a rare exact anchor must retain bounded exact term-id probes');
    assert_contains('STRAIGHT_JOIN wp_fts_postings ppo FORCE INDEX (PRIMARY) ON ppo.term_id = pt.term_id', $candidateLedSql, 'the non-anchor surface range must drive only its posting lists by term-id primary-key prefix');
    assert_contains('prefix_candidate ON prefix_candidate.post_id = ppo.post_id', $candidateLedSql, 'the surface posting stream must intersect rare exact candidates');
    assert_contains('pt.kind = 1 AND pt.term >=', $candidateLedSql, 'range-led membership must apply the same normalized-surface predicate');
    $termPosition = strpos($candidateLedSql, 'FROM (SELECT pt.term_id, pt.doc_freq');
    $postingPosition = strpos($candidateLedSql, 'STRAIGHT_JOIN wp_fts_postings ppo FORCE INDEX (PRIMARY) ON ppo.term_id = pt.term_id');
    $candidatePosition = strpos($candidateLedSql, 'prefix_candidate ON prefix_candidate.post_id = ppo.post_id');
    assert_true(
        $termPosition !== false
            && $postingPosition !== false
            && $candidatePosition !== false
            && $termPosition < $postingPosition
            && $postingPosition < $candidatePosition,
        'the bounded non-anchor join order must be dictionary range, matching postings, then exact candidate intersection'
    );
    assert_true(!str_contains($candidateLedSql, 'ppo FORCE INDEX (post_term_impact) ON ppo.post_id = c.post_id'), 'the non-anchor surface branch must not retain a post-first classifier');
    assert_same(1, substr_count($candidateLedSql, 'pt.term >='), 'the range-led branch must retain one predicate regardless of completion count');

    $commonExactGroups = $candidateLedGroups;
    $commonExactGroups[0][0]['doc_freq'] = 50000;
    $commonExactGroups[1][0]['doc_freq'] = 1;
    $selectivePrefix = array_replace($nonAnchorPrefix, ['doc_freq' => 1]);
    $commonExact = $buildRankQuery->invoke($storage, $commonExactGroups, 2, $selectivePrefix, 'AND', $options, null);
    $commonExactSql = (string) ($commonExact['sql'] ?? '');
    assert_same(1, $commonExact['anchor_group'] ?? null, 'a selective final prefix must anchor ahead of a common exact group');
    assert_contains('STRAIGHT_JOIN wp_fts_postings prefix_posting FORCE INDEX (PRIMARY)', $commonExactSql, 'the prefix anchor must stream only matching term-first postings');
    assert_contains('LEFT JOIN wp_fts_postings po FORCE INDEX (post_term_impact)', $commonExactSql, 'the prefix candidate set must drive post-first probes for common exact groups');
    assert_true(!str_contains($commonExactSql, 'SELECT DISTINCT ap.post_id'), 'the common exact posting list must not be materialized as an anchor');
    assert_same(1, substr_count($commonExactSql, 'pt.term >='), 'the prefix anchor must retain one surface predicate');
});

test_case_with_pdo_sqlite_fixture('relational v4 overlap costing uses dictionary DF only and never scans postings during planning', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    for ($postId = 1; $postId <= 60; $postId++) {
        wp_fts_v4_regression_add_post($wpdb, $postId, '2026-04-03 00:00:00');
    }
    $overlap = array_fill_keys(range(1, 40), 100.0);
    wp_fts_v4_regression_add_term($wpdb, 'overlapalpha', $overlap);
    wp_fts_v4_regression_add_term($wpdb, 'overlapbeta', $overlap);
    wp_fts_v4_regression_add_term($wpdb, 'sixtyanchor', array_fill_keys(range(1, 60), 100.0));

    $groups = [
        [
            ['key' => WP_FTS_TermNamespace::namespace_term('en', 'overlapalpha'), 'rank' => 0],
            ['key' => WP_FTS_TermNamespace::namespace_term('en', 'overlapbeta'), 'rank' => 1],
        ],
        [['key' => WP_FTS_TermNamespace::namespace_term('en', 'sixtyanchor'), 'rank' => 0]],
    ];
    $wpdb->queries = [];
    $payload = $storage->search_page($groups, array_replace(wp_fts_v4_regression_search_options(50), [
        'mode' => 'AND',
        'explain' => true,
    ]));

    assert_same(array_reverse(range(1, 40)), array_column($payload['results'], 'doc_id'), 'overlapping alternatives should retain exact AND membership without double-counting the logical group');
    assert_same(1, $payload['explain']['anchor_group'] ?? null, 'summed alternative DFs should conservatively cost the overlapping group at 80 and choose the 60-row group');
    $planStatements = array_values(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_contains($sql, '/* wp_fts:plan */')
    ));
    assert_same(1, count($planStatements), 'overlap costing should use exactly one dictionary planning statement');
    $planSql = (string) ($planStatements[0] ?? '');
    assert_contains('LEFT JOIN wp_fts_terms exact_term', $planSql, 'the estimate should come from stored dictionary document frequencies through the bounded identity relation');
    assert_true(!str_contains($planSql, 'wp_fts_postings'), 'planning must not scan posting rows to deduplicate overlapping alternatives');
    assert_true(!str_contains($planSql, 'COUNT('), 'planning must not add an exact overlap-count aggregation');
    assert_same(2, count($wpdb->queries), 'the conservative estimate should retain one plan and one rank statement total');
    $rankSql = wp_fts_v4_regression_last_rank_sql($wpdb);
    assert_same(1, substr_count($rankSql, 'JOIN wp_fts_documents d_exact_anchor'), 'exact AND should apply complete visibility once at the rare anchor');
    assert_true(!str_contains($rankSql, 'JOIN wp_fts_documents d_f'), 'exact AND must not repeat complete visibility after bounded post-first probes');
});

test_case_with_pdo_sqlite_fixture('relational v4 regression fences an explicit retry that arrives during an active lease', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    $queue = new WP_FTS_Index_Queue($wpdb);

    $queue->enqueue(71, 1000);
    $old = $queue->claim(1, 1000, 300)[0] ?? null;
    assert_true(is_array($old), 'the original generation should hold an active lease');
    assert_same(1, $old['generation'] ?? null, 'the original lease should own generation one');

    $queue->retry(71, 1001);
    $row = wp_fts_v4_regression_work_row($wpdb, 71);
    assert_same(2, (int) ($row['generation'] ?? 0), 'retry during a lease must advance the fencing generation');
    assert_same('ready', $row['state'] ?? null, 'the retried generation should become immediately available');
    assert_same('', $row['claim_token'] ?? null, 'retry must clear the older worker token');
    assert_same(0, (int) ($row['attempts'] ?? -1), 'operator retry should start with a clean failure count');

    assert_true(!$queue->acknowledge($old, 1002), 'the stale lease must not acknowledge the retried generation');
    assert_same('lost', $queue->fail($old, 1002)['status'] ?? null, 'the stale lease must not defer the retried generation');
    assert_true(!$queue->release($old, 1002), 'the stale lease must not release or rewrite the retried generation');

    $new = $queue->claim(1, 1002, 300)[0] ?? null;
    assert_same(2, $new['generation'] ?? null, 'a new worker should claim the retried generation without waiting for the old lease expiry');
    assert_true(($new['token'] ?? '') !== ($old['token'] ?? ''), 'retry recovery should transfer ownership to a new token');
    assert_true($queue->acknowledge($new, 1003), 'the new generation owner should retain acknowledgement rights');
    assert_same(0, $queue->count(), 'only the new generation owner should remove the work row');
});

test_case_with_pdo_sqlite_fixture('relational v4 SQLite mutation fences recover and supersede by generation CAS', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    $queue = new WP_FTS_Index_Queue($wpdb);

    $originalToken = 'guard:' . str_repeat('a', 32);
    $queue->fence_post(72, $originalToken, 1300, ['source' => 'sqlite-fence']);
    $fenced = wp_fts_v4_regression_work_row($wpdb, 72);
    assert_same('guarded', $fenced['state'] ?? null, 'SQLite should persist the guard-backed mutation state until its recovery time');
    assert_same(1, $queue->status()['post_count'] ?? null, 'operator status should count a guarded SQLite post generation');
    assert_true($queue->has_work(), 'automatic scheduling should retain guarded SQLite crash debt');
    assert_same(1300, $queue->next_available_at(), 'SQLite status should expose the bounded recovery time');
    assert_same([], $queue->claim(1, 1299, 30), 'ordinary claim CAS must wait for the durable recovery time');

    $recovered = $queue->claim(1, 1300, 30)[0] ?? null;
    assert_true(is_array($recovered), 'the ordinary claim path should recover an elapsed fence by exact generation CAS');
    assert_same(1, $recovered['generation'] ?? null, 'recovery should own only the selected generation');
    assert_true(($recovered['token'] ?? '') !== $originalToken, 'recovery should replace the foreground token with one fresh worker token');
    assert_true($queue->acknowledge($recovered, 1301), 'the exact recovered SQLite generation should be acknowledgeable');
    assert_true(!$queue->acknowledge($recovered, 1301), 'one recovered generation must not acknowledge twice');
    assert_same(0, $queue->count(), 'SQLite recovery should leave no phantom fenced work');

    $olderToken = 'guard:' . str_repeat('b', 32);
    $newerToken = 'guard:' . str_repeat('c', 32);
    $wpdb->queries = [];
    $queue->fence_post(73, $olderToken, 1500, ['source' => 'older']);
    $queue->fence_post(73, $newerToken, 1500, ['source' => 'newer']);
    $queue->promote_post(73, $olderToken, 1400, ['source' => 'stale-completion']);
    $superseded = wp_fts_v4_regression_work_row($wpdb, 73);
    assert_same(2, (int) ($superseded['generation'] ?? 0), 'the second fence should atomically advance one canonical generation');
    assert_same('guarded', $superseded['state'] ?? null, 'the stale completion must not release the newer guarded boundary');
    assert_same($newerToken, $superseded['claim_token'] ?? null, 'the stale completion must not replace the newer token');
    assert_same(['source' => 'newer'], json_decode((string) ($superseded['payload'] ?? ''), true), 'the stale completion must not replace the newer payload');

    $queue->promote_post(73, $newerToken, 1400, ['source' => 'newer']);
    $promoted = wp_fts_v4_regression_work_row($wpdb, 73);
    assert_same('ready', $promoted['state'] ?? null, 'the matching SQLite token should promote its exact generation');
    assert_same('', $promoted['claim_token'] ?? null, 'the matching promotion should clear foreground ownership');
    assert_same(1, (int) $wpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_work WHERE kind='post' AND post_id=73")->fetchColumn(), 'all races must share one canonical primary-key row');
    assert_same(4, count(array_filter($wpdb->queries, static fn(string $sql): bool => str_starts_with($sql, 'INSERT INTO wp_fts_work'))), 'two fences and two promotions should each remain one bounded generation UPSERT');
});

test_case_with_pdo_sqlite_fixture('relational v4 SQLite late promotions preserve postdeadline successor intent', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    $queue = new WP_FTS_Index_Queue($wpdb);

    $postToken = 'guard:' . str_repeat('p', 32);
    $postPayload = ['index_options' => ['language' => 'pl']];
    $queue->fence_post(74, $postToken, 1500, ['source' => 'foreground']);
    $queue->enqueue_many([74], 1400, $postPayload);
    $recoveredPost = $queue->claim(1, 1500, 30)[0] ?? null;
    assert_true(is_array($recoveredPost), 'SQLite should recover the coalesced post generation at its exact watchdog deadline');
    $queue->promote_post(74, $postToken, 1501);
    $postAfterPromotion = wp_fts_v4_regression_work_row($wpdb, 74);
    assert_same('ready', $postAfterPromotion['state'] ?? null, 'a late post hook should revoke the recovered lease and publish a successor generation');
    assert_same($postPayload, json_decode((string) ($postAfterPromotion['payload'] ?? ''), true), 'late post promotion must preserve newer coalesced index options');
    assert_true(!$queue->acknowledge($recoveredPost, 1502), 'the recovered post worker must not acknowledge the post-hook successor');
    $postAfterStaleAcknowledge = wp_fts_v4_regression_work_row($wpdb, 74);
    assert_same('ready', $postAfterStaleAcknowledge['state'] ?? null, 'a rolled-back stale acknowledgement must leave the successor ready');
    $postSuccessor = $queue->claim_batch(1, 1502, 30)[0] ?? null;
    assert_same($postPayload, $postSuccessor['payload'] ?? null, 'the next SQLite post claim should retain the coalesced index options');
    assert_true($queue->acknowledge($postSuccessor, 1503), 'the post-hook successor alone should retain acknowledgement rights');

    $scopeKey = 'sqlite-late-targeted';
    $scopeToken = 'guard:' . str_repeat('s', 32);
    $scopePayload = ['reason' => 'newer-coalesced-scope'];
    $queue->fence_scope(
        $scopeKey,
        $scopeToken,
        ['reason' => 'foreground'],
        1600,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED,
        'term_taxonomy',
        77
    );
    $queue->enqueue_scope(
        $scopeKey,
        $scopePayload,
        1500,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED,
        'term_taxonomy',
        99
    );
    $recoveredScope = $queue->claim_scope(1600, 30);
    assert_same('scope', $recoveredScope['kind'] ?? null, 'SQLite should recover the coalesced scope generation at its exact watchdog deadline');
    $queue->promote_scope(
        $scopeKey,
        $scopeToken,
        ['reason' => 'late-hook'],
        1601,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED,
        'term_taxonomy',
        88
    );
    $scopeJobKey = 'scope:' . hash('sha256', $scopeKey);
    $scopeStatement = $wpdb->dbh->prepare('SELECT state,scope_subject_id,payload FROM wp_fts_work WHERE job_key = ?');
    $scopeStatement->execute([$scopeJobKey]);
    $scopeAfterPromotion = $scopeStatement->fetch(PDO::FETCH_ASSOC);
    assert_same('ready', $scopeAfterPromotion['state'] ?? null, 'a late scope hook should revoke the recovered lease and publish a successor generation');
    assert_same(99, (int) ($scopeAfterPromotion['scope_subject_id'] ?? 0), 'late scope promotion must preserve newer coalesced scope authority');
    assert_same($scopePayload, json_decode((string) ($scopeAfterPromotion['payload'] ?? ''), true), 'late scope promotion must preserve newer coalesced scope payload');
    assert_true(!$queue->acknowledge_scope($recoveredScope, 1602), 'the recovered scope worker must not acknowledge the scope-hook successor');
    $scopeSuccessor = $queue->claim_scope(1602, 30);
    assert_same(99, $scopeSuccessor['scope_subject_id'] ?? null, 'the next SQLite scope claim should retain the coalesced authority');
    assert_same($scopePayload, $scopeSuccessor['payload'] ?? null, 'the next SQLite scope claim should retain the coalesced payload');
});

test_case_with_pdo_sqlite_fixture('relational v4 SQLite foreground handoff releases owned fences without tombstones or accidental global scopes', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    $queue = new WP_FTS_Index_Queue($wpdb);
    $postToken = str_repeat('p', 32);
    $exactToken = str_repeat('e', 32);

    $queue->fence_post(740, $postToken, 2000);
    $queue->fence_scope('sqlite-exact-sentinel', $exactToken, ['reason' => 'exact'], 2000);
    $wpdb->queries = [];
    $queue->handoff_foreground_mutation_scope(
        'sqlite-exact-sentinel',
        $exactToken,
        [740],
        [740 => $postToken],
        [],
        false,
        ['reason' => 'exact'],
        1700
    );
    $post = wp_fts_v4_regression_work_row($wpdb, 740);
    assert_same('ready', $post['state'] ?? null, 'SQLite exact handoff must release the request-owned post fence');
    assert_same('', $post['claim_token'] ?? null, 'SQLite exact handoff must clear its ownership token');
    $exactJobKey = 'scope:' . hash('sha256', 'sqlite-exact-sentinel');
    $exactStatement = $wpdb->dbh->prepare('SELECT COUNT(*) FROM wp_fts_work WHERE job_key = ?');
    $exactStatement->execute([$exactJobKey]);
    assert_same(0, (int) $exactStatement->fetchColumn(), 'exact handoff must delete its request-unique sentinel rather than create metadata');
    assert_same(0, (int) $wpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_work WHERE kind = 'meta' AND job_key <> 'meta:search-epoch'")->fetchColumn(), 'exact handoff must leave no random metadata tombstone');
    assert_same(2, count($wpdb->queries), 'SQLite exact handoff must remain one post UPSERT plus one sentinel DELETE');

    $targetToken = str_repeat('t', 32);
    $globalToken = str_repeat('g', 32);
    $queue->fence_scope('sqlite-targeted', $targetToken, [], 2100, WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED, 'term_taxonomy', 77);
    $queue->promote_scope('sqlite-targeted', $targetToken, [], 1800, WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED, 'term_taxonomy', 77);
    $queue->fence_scope('sqlite-global-sentinel', $globalToken, ['reason' => 'global'], 2100, WP_FTS_Index_Queue::SCOPE_COVERAGE_GLOBAL);
    $wpdb->queries = [];
    $queue->handoff_foreground_mutation_scope(
        'sqlite-global-sentinel',
        $globalToken,
        [],
        [],
        ['sqlite-targeted' => ''],
        true,
        ['reason' => 'global'],
        1800,
        '0123456789abcdef0123456789abcdef'
    );
    $scopeRows = $wpdb->dbh->query("SELECT state,scope_subject_type,scope_subject_id FROM wp_fts_work WHERE kind = 'scope' ORDER BY scope_subject_type DESC")->fetchAll(PDO::FETCH_ASSOC);
    assert_same(2, count($scopeRows), 'global handoff must retain the completed targeted generation beside one corpus generation');
    assert_same('term_taxonomy', $scopeRows[0]['scope_subject_type'] ?? null, 'a ready targeted scope must never be erased into a second global scope');
    assert_same(77, (int) ($scopeRows[0]['scope_subject_id'] ?? 0), 'the targeted scope identifier must survive global handoff');
    assert_same('', $scopeRows[1]['scope_subject_type'] ?? null, 'the canonical corpus row should replace the request sentinel');
    assert_same('ready', $scopeRows[1]['state'] ?? null, 'the promoted global corpus generation must be claimable');
    assert_same(2, count($wpdb->queries), 'SQLite corpus handoff must use one canonical scope UPSERT and one exact sentinel DELETE when no active targeted fences remain');
    assert_contains('meta:search-epoch', $wpdb->queries[0] ?? '', 'the canonical bounded UPSERT should advance the cursor epoch atomically');
    assert_contains('/* wp_fts:foreground-global-delete */', $wpdb->queries[1] ?? '', 'the second statement must delete only the exact request sentinel');

    for ($offset = 0; $offset < 25; $offset++) {
        $postId = 800 + $offset;
        $postLoopToken = hash('sha256', 'post-' . $offset);
        $scopeLoopToken = hash('sha256', 'scope-' . $offset);
        $scopeKey = 'sqlite-exact-loop-' . $offset;
        $queue->fence_post($postId, $postLoopToken, 2300);
        $queue->fence_scope($scopeKey, $scopeLoopToken, [], 2300);
        $queue->handoff_foreground_mutation_scope(
            $scopeKey,
            $scopeLoopToken,
            [$postId],
            [$postId => $postLoopToken],
            [],
            false,
            [],
            1900
        );
    }
    assert_same(0, (int) $wpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_work WHERE kind = 'meta' AND job_key <> 'meta:search-epoch'")->fetchColumn(), 'repeated exact requests must never accumulate one immortal metadata row per request');

    $wpdb->execute(
        "INSERT INTO wp_fts_work (job_key,kind,post_id,generation,state) VALUES (?,?,?,?,?)",
        ['legacy-handoff-tombstone', 'meta', 0, 1, 'handoff']
    );
    $queue->clear();
    assert_same(0, (int) $wpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_work WHERE job_key <> 'meta:search-epoch'")->fetchColumn(), 'real SQLite reset must remove legacy random handoff metadata with pending work');
    assert_same(1, (int) $wpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_work WHERE job_key = 'meta:search-epoch'")->fetchColumn(), 'real SQLite reset must retain exactly the singleton cursor epoch');
});

test_case_with_pdo_sqlite_fixture('relational v4 SQLite rejects impossible multi-scope ownership and deletes the maximum one by primary-key CAS', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    $queue = new WP_FTS_Index_Queue($wpdb);
    $oversizedTokens = [];
    for ($offset = 1; $offset <= 1000; $offset++) {
        $scopeKey = 'sqlite-impossible-owned-scope-' . $offset;
        $oversizedTokens[$scopeKey] = hash('sha256', 'sqlite-impossible-owned-token-' . $offset);
    }

    $wpdb->queries = [];
    $rejected = null;
    try {
        $queue->handoff_foreground_mutation_scope(
            'sqlite-impossible-global-sentinel',
            str_repeat('a', 32),
            [],
            [],
            $oversizedTokens,
            true,
            [],
            2200,
            str_repeat('1', 32)
        );
    } catch (Throwable $error) {
        $rejected = $error;
    }
    assert_true($rejected instanceof InvalidArgumentException, 'the public handoff must reject 1,000 owned scopes before building SQL');
    assert_same([], $wpdb->queries, 'ownership above the structural one-scope maximum must execute zero SQLite statements');

    $targetedKey = 'sqlite-maximum-owned-targeted';
    $targetedToken = str_repeat('b', 32);
    $globalKey = 'sqlite-maximum-owned-global';
    $globalToken = str_repeat('c', 32);
    $queue->fence_scope(
        $targetedKey,
        $targetedToken,
        [],
        2500,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED,
        'term_taxonomy',
        91
    );
    $queue->promote_scope(
        $targetedKey,
        $targetedToken,
        [],
        2200,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED,
        'term_taxonomy',
        91
    );
    $queue->fence_scope($globalKey, $globalToken, [], 2500, WP_FTS_Index_Queue::SCOPE_COVERAGE_GLOBAL);

    $wpdb->queries = [];
    $started = microtime(true);
    $queue->handoff_foreground_mutation_scope(
        $globalKey,
        $globalToken,
        [],
        [],
        [$targetedKey => $targetedToken],
        true,
        [],
        2200,
        str_repeat('1', 32)
    );
    $elapsed = microtime(true) - $started;

    $ownedDeletes = array_values(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_contains($sql, 'wp_fts:foreground-owned-scope-delete')
    ));
    assert_same('', $wpdb->last_error, 'the exact maximum SQLite boundary must execute without an expression-depth failure');
    assert_same(3, count($wpdb->queries), 'maximum corpus handoff must remain one canonical UPSERT plus two exact deletes');
    assert_same(1, count($ownedDeletes), 'maximum corpus handoff must issue exactly one owned-scope delete');
    assert_contains("WHERE job_key = 'scope:", $ownedDeletes[0] ?? '', 'the owned delete must drive from one literal primary key');
    assert_contains("AND claim_token = '{$targetedToken}'", $ownedDeletes[0] ?? '', 'the owned delete must compare the exact request capability');
    assert_true(!str_contains($ownedDeletes[0] ?? '', ' OR '), 'SQLite must not build a branch predicate tree');
    assert_true(!str_contains($ownedDeletes[0] ?? '', 'JOIN '), 'SQLite must not build a derived target relation');
    assert_true(strlen($ownedDeletes[0] ?? '') < 512, 'the maximum owned-scope delete must remain below 512 bytes');
    $planRows = $wpdb->dbh->query('EXPLAIN QUERY PLAN ' . ($ownedDeletes[0] ?? ''))->fetchAll(PDO::FETCH_ASSOC);
    $planText = implode(' ', array_map(static fn(array $row): string => (string) ($row['detail'] ?? ''), $planRows));
    assert_contains('job_key=?', $planText, 'SQLite must plan the owned delete as an exact job-key lookup');
    $targetedJobKey = 'scope:' . hash('sha256', $targetedKey);
    assert_same(0, (int) $wpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_work WHERE job_key='{$targetedJobKey}'")->fetchColumn(), 'the exact owned targeted generation must leave no residue');
    assert_same(1, (int) $wpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_work WHERE kind='scope' AND scope_coverage='corpus'")->fetchColumn(), 'the handoff must leave exactly one canonical corpus generation');
    assert_true($elapsed < 1.0, "the exact maximum SQLite boundary should remain cheap (measured {$elapsed} seconds)");
});

test_case_with_pdo_sqlite_fixture('relational v4 SQLite reset rebuilds 409600 populated postings with constant metadata work', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    $wpdb->dbh->beginTransaction();
    try {
        $term = $wpdb->dbh->prepare('INSERT INTO wp_fts_terms(lang,kind,term,doc_freq) VALUES(?,?,?,100)');
        for ($termId = 1; $termId <= 4096; $termId++) {
            $surface = 'reset-term-' . $termId;
            $term->execute(['en', 0, $surface]);
        }
        $document = $wpdb->dbh->prepare("INSERT INTO wp_fts_documents(post_id,primary_lang,content_hash,snippet_text,indexed_at) VALUES(?,'en',?,'reset',1)");
        for ($postId = 1; $postId <= 100; $postId++) {
            $document->execute([$postId, hash('sha256', (string) $postId)]);
        }
        $posting = $wpdb->dbh->prepare('INSERT INTO wp_fts_postings(term_id,post_id,impact) VALUES(?,?,1)');
        for ($postId = 1; $postId <= 100; $postId++) {
            for ($termId = 1; $termId <= 4096; $termId++) {
                $posting->execute([$termId, $postId]);
            }
        }
        $wpdb->dbh->exec("UPDATE wp_fts_work SET generation=37 WHERE job_key='meta:search-epoch'");
        $work = $wpdb->dbh->prepare("INSERT INTO wp_fts_work(job_key,kind,post_id,generation,state) VALUES(?,'post',?,1,'ready')");
        for ($postId = 1; $postId <= 1000; $postId++) {
            $work->execute(['post:' . $postId, $postId]);
        }
        $wpdb->dbh->commit();
    } catch (Throwable $error) {
        $wpdb->dbh->rollBack();
        throw $error;
    }
    assert_same(409600, (int) $wpdb->dbh->query('SELECT COUNT(*) FROM wp_fts_postings')->fetchColumn(), 'hard reset fixture must contain all 409,600 posting rows');

    $guardCalls = 0;
    $storage = new WP_FTS_Storage_Mysql($wpdb, null, static function () use (&$guardCalls): void {
        $guardCalls++;
    });
    $wpdb->queries = [];
    $memoryBefore = memory_get_usage(true);
    $started = microtime(true);
    $summary = $storage->reset_index();
    $elapsed = microtime(true) - $started;
    $memoryDelta = max(0, memory_get_usage(true) - $memoryBefore);
    $resetQueries = $wpdb->queries;

    assert_same('sqlite_transactional_schema_rebuild', $summary['reset_strategy'] ?? null, 'SQLite reset should report its transactional schema strategy');
    assert_same(false, $summary['counts_exact'] ?? null, 'SQLite reset should not scan the populated relations for cosmetic counts');
    assert_same(null, $summary['postings_deleted'] ?? null, 'SQLite reset should report populated posting count as unknown');
    assert_same(38, $summary['search_epoch'] ?? null, 'SQLite reset should carry the cursor epoch monotonically into the new work table');
    assert_same(2, $guardCalls, 'SQLite reset should validate writer ownership at entry and immediately before commit');
    assert_same(21, count($resetQueries), 'SQLite reset statement count must remain constant at the 409,600-row boundary');
    assert_true(!str_contains(implode("\n", $resetQueries), 'DELETE FROM'), 'SQLite reset must not delete populated rows one by one');
    assert_true(!str_contains(implode("\n", $resetQueries), 'COUNT('), 'SQLite reset must not count populated rows during the reset operation');
    assert_true($elapsed <= 5.0, "SQLite 409,600-posting reset should finish within five seconds; observed {$elapsed}");
    assert_true($memoryDelta <= 16 * 1024 * 1024, "SQLite reset should add at most 16 MiB PHP allocation; observed {$memoryDelta}");
    assert_same(0, (int) $wpdb->dbh->query('SELECT COUNT(*) FROM wp_fts_terms')->fetchColumn(), 'SQLite reset should publish an empty term dictionary');
    assert_same(0, (int) $wpdb->dbh->query('SELECT COUNT(*) FROM wp_fts_postings')->fetchColumn(), 'SQLite reset should publish an empty posting relation');
    assert_same(0, (int) $wpdb->dbh->query('SELECT COUNT(*) FROM wp_fts_documents')->fetchColumn(), 'SQLite reset should publish an empty document relation');
    assert_same(1, (int) $wpdb->dbh->query('SELECT COUNT(*) FROM wp_fts_work')->fetchColumn(), 'SQLite reset should retain only the new cursor epoch row');
    assert_same(38, (int) $wpdb->dbh->query("SELECT generation FROM wp_fts_work WHERE job_key='meta:search-epoch'")->fetchColumn(), 'SQLite reset should reject every cursor from the retired generation');
    assert_same(true, $storage->verify_schema()['valid'] ?? null, 'SQLite reset should recreate the exact production table and index contract');
});

test_case_with_pdo_sqlite_fixture('relational v4 SQLite schema verification stays fixed with 2048 unrelated indexes', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    $storage = new WP_FTS_Storage_Mysql($wpdb);

    // Reset publishes the exact current SQLite names. The older regression
    // fixture intentionally uses several compatibility names that a bounded
    // inspector must not expand into an open-ended candidate list.
    $storage->reset_index();
    $wpdb->dbh->exec('DROP TABLE wp_fts_terms');
    $wpdb->dbh->exec("CREATE TABLE wp_fts_terms (
        term_id INTEGER PRIMARY KEY AUTOINCREMENT,
        lang BLOB NOT NULL,
        kind INTEGER NOT NULL DEFAULT 0,
        term BLOB NOT NULL,
        doc_freq INTEGER NOT NULL DEFAULT 0,
        UNIQUE(lang,kind,term)
    )");
    $wpdb->dbh->exec('CREATE INDEX wp_fts_terms_empty_terms ON wp_fts_terms(doc_freq)');
    $wpdb->queries = [];
    $baseline = $storage->verify_schema();
    assert_same(true, $baseline['valid'] ?? null, 'the hostile-index fixture must accept SQLite inline UNIQUE autoindexes for the exact production schema');
    assert_same(1, count($wpdb->queries), 'four complete SQLite tables should share one set-oriented metadata statement');

    // All three names are compatibility candidates for one physical contract,
    // not permission to retain duplicate copies of the same index.
    $wpdb->dbh->exec('CREATE UNIQUE INDEX wp_fts_term_identity ON wp_fts_terms(lang,kind,term)');
    $wpdb->queries = [];
    $duplicate = $storage->verify_schema();
    assert_same(false, $duplicate['valid'] ?? null, 'two allowed aliases for one definition must still violate the exact index contract');
    assert_same(1, count($wpdb->queries), 'detecting a duplicate compatibility alias must retain the one-statement metadata contract');
    $wpdb->dbh->exec('DROP INDEX wp_fts_term_identity');

    for ($index = 0; $index < 2048; $index++) {
        $name = 'wp_fts_hostile_' . str_pad((string) $index, 4, '0', STR_PAD_LEFT);
        $wpdb->dbh->exec("CREATE INDEX {$name} ON wp_fts_documents(indexed_at)");
    }

    $wpdb->queries = [];
    $started = microtime(true);
    $damaged = $storage->verify_schema();
    $elapsed = microtime(true) - $started;
    assert_same(false, $damaged['valid'] ?? null, 'an unexpected FTS-table index must still invalidate the physical schema');
    assert_same(2, count($damaged['unexpected_indexes'] ?? []), 'the bounded inspector should retain one hostile-index sentinel plus the fixed extra-index marker, not all 2,048 definitions');
    assert_contains('<uninspected>', implode(',', $damaged['unexpected_indexes'] ?? []), 'the unexpected sentinel should not inspect or allocate the hostile index definition');
    assert_same(1, count($wpdb->queries), '2,048 peer indexes must not add SQLite metadata round trips');
    assert_same(1, count(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_contains(strtolower($sql), 'pragma_index_info(')
    )), 'SQLite should inspect every expected index through one correlated table-valued pragma query');
    assert_true($elapsed < 1.0, "bounded SQLite schema verification should remain cheap with 2,048 peer indexes; observed {$elapsed} seconds");
});

test_case_with_pdo_sqlite_fixture('relational v4 real SQLite worker drains only the newest canonical generation', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    wp_fts_v4_regression_add_source_post(
        $wpdb,
        741,
        '<p>CommittedGenerationProjection</p>',
        'Generation host'
    );

    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = [
        'auto_detect_language' => false,
        'enable_stemming' => false,
    ];
    WP_FTS_Plugin::reset_request_caches();

    $queue = new WP_FTS_Index_Queue($wpdb);
    $olderToken = str_repeat('c', 32);
    $newerToken = str_repeat('d', 32);
    $queue->fence_post(741, $olderToken, time() + 300, ['source' => 'older']);
    $queue->fence_post(741, $newerToken, time() + 300, ['source' => 'newer']);
    $queue->promote_post(741, $olderToken, null, ['source' => 'stale']);
    $fenced = wp_fts_v4_regression_work_row($wpdb, 741);
    assert_same(2, (int) ($fenced['generation'] ?? 0), 'the second foreground request should own generation two');
    assert_same('fenced', $fenced['state'] ?? null, 'the stale foreground completion must not expose generation two');
    assert_same($newerToken, $fenced['claim_token'] ?? null, 'the stale foreground completion must not replace the newer token');

    $queue->promote_post(741, $newerToken, null, ['source' => 'newer']);
    $ready = wp_fts_v4_regression_work_row($wpdb, 741);
    assert_same('ready', $ready['state'] ?? null, 'the newest foreground request should expose its generation');
    assert_same(1, (int) $wpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_work WHERE kind = 'post' AND post_id = 741")->fetchColumn(), 'real storage should retain one canonical row for the racing requests');

    $wpdb->queries = [];
    $summary = wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_manual_index_batch([
        'batch_size' => 1,
        'source' => 'sqlite-generation-cas-regression',
    ]));
    $search = wp_fts_quality_with_wpdb($wpdb, static function () use ($wpdb): array {
        $storage = new WP_FTS_Storage_Mysql($wpdb);

        return WP_FTS_Searcher::for_set_oriented_storage($storage, WP_FTS_Plugin::runtime_analyzer())->search(
            'CommittedGenerationProjection',
            [
                'lang' => 'en',
                'mode' => 'OR',
                'limit' => 10,
                'post_type' => ['post'],
                'post_status' => ['publish'],
                'prefix_matching' => false,
                '_search_ready_incarnation' => wp_fts_v4_regression_ready_incarnation(),
                '_search_ready_profile_hash' => wp_fts_v4_regression_ready_profile_hash(),
            ]
        );
    });

    assert_same(1, $summary['analyzed'] ?? null, 'one canonical generation should require one source analysis');
    assert_same(1, $summary['indexed'] ?? null, 'one canonical generation should require one relational replacement');
    assert_same(1, $summary['committed'] ?? null, 'the worker should acknowledge one exact queue generation');
    assert_same(0, (int) $wpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_work WHERE kind = 'post'")->fetchColumn(), 'the worker must leave no canonical dirty row');
    assert_same([741], array_column($search['results'] ?? [], 'doc_id'), 'relational search must expose the newest committed canonical projection');
});

test_case_with_pdo_sqlite_fixture('relational v4 claim_scope executes its complete SQL lifecycle on SQLite', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    $queue = new WP_FTS_Index_Queue($wpdb);

    $queue->enqueue_scope('real-scope-sql', ['reason' => 'claim-scope-regression'], 1000);
    $wpdb->queries = [];
    $claim = $queue->claim_scope(1000, 30);
    assert_true(is_array($claim), 'a ready scope should execute its real selection and update statements');
    assert_same('scope', $claim['kind'] ?? null, 'the compatibility claim should preserve the scope kind');
    assert_same('claim-scope-regression', $claim['payload']['reason'] ?? null, 'the real claim should decode its bounded payload');
    assert_same(1030, $claim['claim_expires_at'] ?? null, 'the real claim should expose its deterministic lease boundary');
    assert_same(2, count($wpdb->queries), 'a successful compatibility scope claim should use exactly one read and one compare-and-swap update');
    assert_true(str_starts_with($wpdb->queries[0] ?? '', 'SELECT job_key, kind, generation,'), 'scope claim statement one should be the bounded indexed selection');
    assert_contains('(job_key, generation) IN', $wpdb->queries[0] ?? '', 'SQLite scope selection must bind its bounded candidate to the observed generation');
    assert_contains('SELECT job_key, generation FROM', $wpdb->queries[0] ?? '', 'every SQLite state-arm driver must carry generation beside the primary key');
    assert_true(str_starts_with($wpdb->queries[1] ?? '', 'UPDATE wp_fts_work'), 'scope claim statement two should lease the selected generation');
    assert_contains('WHERE job_key =', $wpdb->queries[1] ?? '', 'SQLite scope CAS must target the selected primary key');
    assert_contains('AND generation =', $wpdb->queries[1] ?? '', 'SQLite scope CAS must reject a generation advanced after candidate selection');
    assert_same(6, substr_count(strtoupper($wpdb->queries[0] ?? ''), 'FROM WP_FTS_WORK'), 'scope selection should use one outer lookup plus five one-row state/index arms');
    assert_same(4, substr_count(strtoupper($wpdb->queries[0] ?? ''), 'UNION ALL'), 'the five fixed state arms should compose through exactly four bounded unions');
    assert_same(null, $queue->claim_scope(1029, 30), 'an active real lease should not be claimed twice');
    assert_true($queue->commit_scope_page($claim, [], 77), 'the real claimed generation should persist its keyset cursor and release ownership');

    $continued = $queue->claim_scope(1030, 30);
    assert_same(77, $continued['cursor_post_id'] ?? null, 'the next real claim should resume from the persisted scope cursor');
    assert_true($queue->acknowledge_scope($continued, 1031), 'the resumed real claim should acknowledge its exact generation');
    assert_same(0, $queue->count(), 'real scope acknowledgement should remove the completed durable row');

    $queue->enqueue_scope('real-future-scope', ['reason' => 'scheduled-reconciliation'], 1400);
    assert_same(null, $queue->claim_scope(1399, 30), 'a future reconciliation scope must remain unclaimable before its available time');
    $scheduled = $queue->claim_scope(1400, 30);
    assert_true(is_array($scheduled), 'the same real scope should become claimable exactly at its available time');
    assert_same('scheduled-reconciliation', $scheduled['payload']['reason'] ?? null, 'scheduled scope claiming should preserve its bounded diagnostic reason');
});

test_case_with_pdo_sqlite_fixture('relational v4 SQLite schema repair is idempotent and preserves postings, work, and cursor epoch', function (): void {
    $fixture = dirname(__DIR__) . '/fixtures/sqlite-schema-repair-idempotence.php';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture);
    $lines = [];
    $status = 0;
    exec($command . ' 2>&1', $lines, $status);
    assert_same(0, $status, 'the isolated real-PDO SQLite repair regression should complete: ' . implode("\n", $lines));

    $payload = json_decode(implode("\n", $lines), true);
    assert_true(is_array($payload), 'the isolated SQLite repair regression should return JSON evidence');
    assert_same(true, $payload['before_valid'] ?? null, 'the translated SQLite schema should satisfy the v4 contract before repair');
    assert_same(true, $payload['after_valid'] ?? null, 'the translated SQLite schema should satisfy the v4 contract after repeated repair');
    assert_same(2, $payload['dbdelta_calls'] ?? null, 'each create_tables call should reach one idempotent dbDelta pass');
    assert_same(0, $payload['drop_statements'] ?? null, 'repeated repair must not drop an already-compatible SQLite table');
    assert_same(1, $payload['postings'] ?? null, 'repeated repair must preserve the existing posting');
    assert_same(1, $payload['document'] ?? null, 'repeated repair must preserve the existing document');
    assert_same(7, $payload['epoch'] ?? null, 'repeated repair must preserve the durable cursor epoch');
    assert_same('0123456789abcdef0123456789abcdef', $payload['incarnation'] ?? null, 'idempotent repair must preserve the work-table cursor incarnation');
    assert_same(3, $payload['work_generation'] ?? null, 'repeated repair must preserve pending work generations');
    assert_same([
        'valid' => true,
        'drops' => 0,
        'terms' => 0,
        'postings' => 0,
        'documents' => 0,
        'work' => 0,
    ], $payload['fresh'] ?? null, 'a fresh install should create all four tables without issuing a pointless drop');
    assert_same([
        'valid' => true,
        'drops' => 2,
        'terms' => 0,
        'postings' => 0,
        'documents' => 0,
        'work' => 1,
    ], $payload['missing_document'] ?? null, 'a missing document projection must discard every surviving search-generation peer while preserving work');
    assert_same([
        'valid' => true,
        'drops' => 2,
        'terms' => 0,
        'postings' => 0,
        'documents' => 0,
        'work' => 1,
    ], $payload['missing_term'] ?? null, 'a missing dictionary must discard retained postings and documents before term ids can be reused');
    assert_same([
        'valid' => true,
        'drops' => 3,
        'terms' => 0,
        'postings' => 0,
        'documents' => 0,
        'work' => 1,
    ], $payload['mismatched_document'] ?? null, 'one incompatible search table must replace the complete three-table generation');
    assert_same([
        'valid' => true,
        'drops' => 3,
        'terms' => 0,
        'postings' => 0,
        'documents' => 0,
        'work' => 1,
    ], $payload['legacy_term_hash'] ?? null, 'removing the redundant term hash must replace the complete search generation while retaining work and epoch state');
    assert_same([
        'valid' => true,
        'drops' => 0,
        'terms' => 1,
        'postings' => 1,
        'documents' => 1,
        'work' => 0,
    ], $payload['missing_work'] ?? null, 'a missing work table must be created without discarding a coherent search index');
    assert_same([
        'valid' => true,
        'drops' => 1,
        'terms' => 1,
        'postings' => 1,
        'documents' => 1,
        'work' => 0,
    ], $payload['mismatched_work'] ?? null, 'an incompatible work table must be replaced independently of the search generation');
    assert_same([
        'valid' => true,
        'drops' => 3,
        'terms' => 0,
        'postings' => 0,
        'documents' => 0,
        'work' => 0,
    ], $payload['mixed_damage'] ?? null, 'mixed search and work damage must rebuild both consistency units without retaining stale rows');
});

test_case('relational v4 native schema repair drops only inspected search-generation tables', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $storage = new WP_FTS_Storage_Mysql($wpdb);
    $drop = new ReflectionMethod(WP_FTS_Storage_Mysql::class, 'drop_existing_schema_tables');
    $drop->setAccessible(true);
    $tables = ['wp_fts_terms', 'wp_fts_postings', 'wp_fts_documents'];
    $physical = array_fill_keys($tables, ['exists' => true]);

    $drop->invoke($storage, $tables, $physical, 'replace incoherent FTS search generation');

    assert_same(3, count($wpdb->queries), 'native repair should issue one checked DROP for each existing search-generation table');
    assert_same(
        [
            'DROP TABLE `wp_fts_terms`',
            'DROP TABLE `wp_fts_postings`',
            'DROP TABLE `wp_fts_documents`',
        ],
        $wpdb->queries,
        'native repair should drop exactly the already-inspected generation members and no unrelated table'
    );
});

test_case_with_pdo_sqlite_fixture('relational v4 scope page fan-out and cursor progress commit atomically behind the generation fence', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    $queue = new WP_FTS_Index_Queue($wpdb);

    $queue->enqueue_scope('atomic-scope-page', ['reason' => 'atomic-page-regression'], 1000);
    $claim = $queue->claim_scope(1000, 30);
    assert_true(is_array($claim), 'the atomic page fixture should own its first scope generation');
    $wpdb->queries = [];

    assert_true($queue->commit_scope_page($claim, [81, 82], 82, 1001), 'the exact owner should commit fan-out and cursor progress together');
    assert_same(4, count($wpdb->queries), 'a successful page should use BEGIN, one fenced scope UPDATE, one post UPSERT, and COMMIT');
    assert_same('BEGIN', $wpdb->queries[0] ?? null, 'scope page commit should open one real transaction');
    assert_true(str_starts_with($wpdb->queries[1] ?? '', 'UPDATE wp_fts_work'), 'the generation-fenced scope UPDATE must execute before fan-out');
    assert_true(str_starts_with($wpdb->queries[2] ?? '', 'INSERT INTO wp_fts_work'), 'all page post rows should share one set-oriented UPSERT');
    assert_same('COMMIT', $wpdb->queries[3] ?? null, 'cursor progress and page rows should become durable at one commit boundary');

    $scopeStatement = $wpdb->dbh->prepare('SELECT generation,state,cursor_post_id,claim_token FROM wp_fts_work WHERE job_key = ?');
    $scopeStatement->execute([(string) ($claim['job_key'] ?? '')]);
    $scopeRow = $scopeStatement->fetch(PDO::FETCH_ASSOC);
    assert_same(82, (int) ($scopeRow['cursor_post_id'] ?? 0), 'a committed page should persist its exact greatest post id');
    assert_same('ready', $scopeRow['state'] ?? null, 'a committed page should be immediately ready for its next bounded pass');
    assert_same('', $scopeRow['claim_token'] ?? null, 'a committed page should release its old lease');
    assert_same([81, 82], array_map('intval', $wpdb->dbh->query("SELECT post_id FROM wp_fts_work WHERE kind = 'post' ORDER BY post_id")->fetchAll(PDO::FETCH_COLUMN)), 'one commit should expose every exact page row');

    $continued = $queue->claim_scope(1002, 30);
    assert_same(82, $continued['cursor_post_id'] ?? null, 'the next owner should continue from the atomically published cursor');
    $queue->enqueue_scope('atomic-scope-page', ['reason' => 'superseding-generation'], 1003);
    $wpdb->queries = [];
    assert_true(!$queue->commit_scope_page($continued, [83], 83, 1004), 'a superseded owner must lose before it can fan out stale ids');
    assert_same(3, count($wpdb->queries), 'a stale page should stop after BEGIN, the failed compare-and-swap, and ROLLBACK');
    assert_same('BEGIN', $wpdb->queries[0] ?? null, 'a stale page should still test ownership transactionally');
    assert_true(str_starts_with($wpdb->queries[1] ?? '', 'UPDATE wp_fts_work'), 'stale ownership should fail at the first data statement');
    assert_same('ROLLBACK', $wpdb->queries[2] ?? null, 'a stale page should explicitly close its transaction');
    assert_same(0, (int) $wpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_work WHERE kind = 'post' AND post_id = 83")->fetchColumn(), 'a stale generation must produce zero post work');
    $scopeStatement->execute([(string) ($claim['job_key'] ?? '')]);
    $supersededRow = $scopeStatement->fetch(PDO::FETCH_ASSOC);
    assert_same(2, (int) ($supersededRow['generation'] ?? 0), 'the newer desired scope generation should remain authoritative');
    assert_same(0, (int) ($supersededRow['cursor_post_id'] ?? -1), 'the newer generation should retain its reset cursor');

    $failureQueue = new WP_FTS_Index_Queue($wpdb);
    $failureQueue->enqueue_scope('failed-atomic-scope-page', ['reason' => 'statement-failure'], 1005);
    $failedClaim = $failureQueue->claim_scope(1005, 30);
    assert_true(is_array($failedClaim), 'the statement-failure fixture should own its scope generation');
    $wpdb->dbh->exec("CREATE TRIGGER wp_fts_fail_scope_page BEFORE INSERT ON wp_fts_work WHEN NEW.kind = 'post' AND NEW.post_id = 84 BEGIN SELECT RAISE(ABORT, 'simulated page insert failure'); END");
    $wpdb->queries = [];
    $thrown = null;
    try {
        $failureQueue->commit_scope_page($failedClaim, [84], 84, 1006);
    } catch (RuntimeException $error) {
        $thrown = $error;
    }
    assert_true($thrown instanceof RuntimeException, 'a failed page UPSERT should escape instead of publishing cursor-only progress');
    assert_same(4, count($wpdb->queries), 'a failed page should stop after BEGIN, the scope UPDATE, the one page UPSERT, and ROLLBACK');
    assert_same('ROLLBACK', $wpdb->queries[count($wpdb->queries) - 1] ?? null, 'a page statement failure should roll back the complete transaction');
    assert_same(0, (int) $wpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_work WHERE kind = 'post' AND post_id = 84")->fetchColumn(), 'a failed page should leave no partial post row');
    $failedScopeStatement = $wpdb->dbh->prepare('SELECT state,cursor_post_id,claim_token FROM wp_fts_work WHERE job_key = ?');
    $failedScopeStatement->execute([(string) ($failedClaim['job_key'] ?? '')]);
    $failedScopeRow = $failedScopeStatement->fetch(PDO::FETCH_ASSOC);
    assert_same('leased', $failedScopeRow['state'] ?? null, 'rollback should restore the pre-page scope lease for normal failure handling');
    assert_same(0, (int) ($failedScopeRow['cursor_post_id'] ?? -1), 'rollback must never skip a page after an enqueue failure');
    assert_same($failedClaim['token'] ?? null, $failedScopeRow['claim_token'] ?? null, 'rollback should restore the exact scope owner so fail_scope can apply bounded backoff');
});

test_case_with_pdo_sqlite_fixture('relational v4 worker alternates direct claims and scope pages across bounded cron invocations', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    $directIds = range(91, 110);
    $scopeIds = range(1, 20);
    foreach ([...$directIds, ...$scopeIds] as $postId) {
        wp_fts_v4_regression_add_source_post($wpdb, $postId, "<p>fairness source {$postId}</p>", '');
    }
    foreach ($scopeIds as $postId) {
        $wpdb->execute('INSERT INTO wp_term_relationships (object_id,term_taxonomy_id) VALUES (?,?)', [$postId, 501]);
    }

    wp_fts_test_reset_wordpress_fakes();
    WP_FTS_Plugin::reset_request_caches();
    $queue = new WP_FTS_Index_Queue($wpdb);
    $queue->enqueue_many($directIds);
    $queue->enqueue_scope('fair-scope-page', [
        'reason' => 'taxonomy_term_edited',
    ], null, WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED, 'term_taxonomy', 501);
    $wpdb->queries = [];

    $before = time();
    $postDrain = wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_scheduled_indexing());
    $postDrainQueries = $wpdb->queries;
    $postDrainScheduleCalls = $GLOBALS['wp_fts_test_schedule_calls'];
    assert_same(0, $postDrain['backfill_scanned'] ?? null, 'the direct-post cron invocation should not add a scope keyset read');
    assert_same(0, $postDrain['backfill_queued'] ?? null, 'the direct-post cron invocation should not publish scope rows');
    assert_same(20, $postDrain['queue_processed'] ?? null, 'a full direct batch claimed beside the scope must be indexed instead of released and starved');
    assert_same(true, $postDrain['has_more'] ?? null, 'the released scope should request immediate continuation');
    assert_same(20, (int) $wpdb->dbh->query('SELECT COUNT(*) FROM wp_fts_documents')->fetchColumn(), 'the post-drain invocation should publish every direct document');
    assert_same(0, (int) $wpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_work WHERE kind = 'post'")->fetchColumn(), 'the post-drain invocation should acknowledge every direct generation');
    assert_same(0, (int) $wpdb->dbh->query("SELECT cursor_post_id FROM wp_fts_work WHERE kind = 'scope'")->fetchColumn(), 'releasing the co-claimed scope must preserve its unadvanced cursor');

    unset($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]);
    $GLOBALS['wp_fts_test_schedule_calls'] = [];
    $wpdb->queries = [];
    $scopeAdvance = wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_scheduled_indexing());
    assert_same(20, $scopeAdvance['backfill_scanned'] ?? null, 'the immediate scope-only successor should keyset-scan one full taxonomy page');
    assert_same(20, $scopeAdvance['backfill_queued'] ?? null, 'the scope-only successor should atomically publish every scoped post row');
    assert_same(0, $scopeAdvance['queue_processed'] ?? null, 'scope expansion should not repeat direct document work');
    assert_same(true, $scopeAdvance['has_more'] ?? null, 'the newly published scope page should request immediate continuation');
    assert_same(20, (int) $wpdb->dbh->query('SELECT COUNT(*) FROM wp_fts_documents')->fetchColumn(), 'scope expansion should leave the direct documents unchanged');
    assert_same($scopeIds, array_map('intval', $wpdb->dbh->query("SELECT post_id FROM wp_fts_work WHERE kind = 'post' ORDER BY post_id")->fetchAll(PDO::FETCH_COLUMN)), 'only the newly expanded full scope page should remain as direct work');
    assert_same(20, (int) $wpdb->dbh->query("SELECT cursor_post_id FROM wp_fts_work WHERE kind = 'scope'")->fetchColumn(), 'the scope-only successor should advance to the page high-water');

    $sourceFallbacks = array_values(array_filter(
        $postDrainQueries,
        static fn(string $sql): bool => str_starts_with($sql, "SELECT p.ID,\n       CASE WHEN")
    ));
    assert_same([], $sourceFallbacks, 'a post claimed beside a scope should reuse the bounded confirmation snapshot instead of issuing a fallback source query');
    assert_same(0, count(array_filter(
        $postDrainQueries,
        static fn(string $sql): bool => str_starts_with($sql, '/* wp_fts:targeted-scope-page */')
    )), 'the direct-post invocation must issue no targeted scope selector');
    assert_same(1, count(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_starts_with($sql, '/* wp_fts:targeted-scope-page */')
    )), 'the scope-only successor should issue one targeted scope selector');
    assert_same(1, count($postDrainScheduleCalls), 'the post-drain invocation should schedule exactly one scope successor');
    $postDrainScheduledAt = (int) ($postDrainScheduleCalls[0]['timestamp'] ?? 0);
    assert_true($postDrainScheduledAt >= $before + 1 && $postDrainScheduledAt <= time() + 2, 'the deferred scope should be scheduled promptly instead of imposing the former sixty-second delay');
    assert_same(1, count($GLOBALS['wp_fts_test_schedule_calls']), 'continued scope work should schedule exactly one post-drain successor');
});

test_case_with_pdo_sqlite_fixture('relational v4 targeted scopes use the exact composite membership keyset', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    wp_fts_v4_regression_add_source_post($wpdb, 1, '<p>first exact target</p>', '');
    wp_fts_v4_regression_add_source_post($wpdb, 201, '<p>last exact target</p>', '');
    $wpdb->execute('INSERT INTO wp_term_relationships (object_id,term_taxonomy_id) VALUES (?,?)', [1, 999]);
    for ($objectId = 2; $objectId <= 200; $objectId++) {
        $wpdb->execute('INSERT INTO wp_term_relationships (object_id,term_taxonomy_id) VALUES (?,?)', [$objectId, 1]);
    }
    $wpdb->execute('INSERT INTO wp_term_relationships (object_id,term_taxonomy_id) VALUES (?,?)', [201, 999]);

    wp_fts_test_reset_wordpress_fakes();
    WP_FTS_Plugin::reset_request_caches();
    $queue = new WP_FTS_Index_Queue($wpdb);
    $queue->enqueue_scope(
        'sparse-targeted-scope',
        ['reason' => 'sparse-targeted-regression'],
        null,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED,
        'term_taxonomy',
        999
    );
    $wpdb->queries = [];

    $first = wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_manual_index_batch([
        'batch_size' => 100,
        'source' => 'sparse-targeted-regression',
    ]));
    assert_same(2, $first['backfill_scanned'] ?? null, 'unrelated relationships must not consume the target page bound');
    assert_same(2, $first['backfill_queued'] ?? null, 'one target-index range should queue both sparse exact relationships');
    assert_same(201, (int) $wpdb->dbh->query("SELECT cursor_post_id FROM wp_fts_work WHERE kind = 'scope'")->fetchColumn(), 'target progress should advance to the last matching object only');
    assert_same([1, 201], array_map('intval', $wpdb->dbh->query("SELECT post_id FROM wp_fts_work WHERE kind = 'post' ORDER BY post_id")->fetchAll(PDO::FETCH_COLUMN)), 'the target range must never enqueue unrelated relationship objects');

    $targetedQueries = array_values(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_starts_with($sql, '/* wp_fts:targeted-scope-page */')
    ));
    $targetedMetadataQueries = array_values(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_contains($sql, "FROM pragma_index_list('wp_term_relationships')")
            && str_contains($sql, 'LEFT JOIN pragma_index_info(il.name)')
    ));
    assert_same(1, count($targetedMetadataQueries), 'one SQLite target page should use one set-oriented named-index metadata query');
    assert_same(1, count($targetedQueries), 'one worker pass should issue one targeted relationship query');
    assert_true(str_contains($targetedQueries[0] ?? '', 'scope_rel.term_taxonomy_id = 999'), 'the exact relationship probe must bind the requested taxonomy identity');
    assert_true(str_contains($targetedQueries[0] ?? '', 'scope_rel.object_id > 0'), 'the relationship keyset must use the durable object-id cursor');
    assert_same(1, substr_count($targetedQueries[0] ?? '', 'LIMIT 100'), 'the target-index range must carry the hard page bound exactly once');
    assert_contains('INDEXED BY `wp_fts_', $targetedQueries[0] ?? '', 'SQLite target expansion must force the plugin-owned composite keyset');
    assert_true(!str_contains($targetedQueries[0] ?? '', 'FROM wp_posts'), 'target expansion must never walk unrelated canonical posts');

    $wpdb->queries = [];
    $postDrain = wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_manual_index_batch([
        'batch_size' => 100,
        'source' => 'sparse-targeted-regression',
    ]));
    assert_same(2, $postDrain['processed'] ?? null, 'the second pass should drain both exact target generations');
    assert_same(0, $postDrain['backfill_scanned'] ?? null, 'the direct-post pass should not issue the target-index EOF query');
    assert_same(false, $postDrain['scope_completed'] ?? null, 'the direct-post pass should leave the targeted scope ready');
    assert_same(true, $postDrain['has_more'] ?? null, 'the deferred targeted scope should request its immediate successor');
    assert_same(1, (int) $wpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_work WHERE kind = 'scope'")->fetchColumn(), 'the post-drain pass must retain the exact targeted scope generation');
    assert_same(0, count(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_starts_with($sql, '/* wp_fts:targeted-scope-page */')
    )), 'the post-drain pass must issue no targeted scope selector');

    $wpdb->queries = [];
    $scopeCompletion = wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_manual_index_batch([
        'batch_size' => 100,
        'source' => 'sparse-targeted-regression',
    ]));
    assert_same(0, $scopeCompletion['backfill_scanned'] ?? null, 'one scope-only empty target-index page should prove true targeted EOF');
    assert_same(true, $scopeCompletion['scope_completed'] ?? null, 'target-index EOF should acknowledge the exact scope generation');
    assert_same(0, $scopeCompletion['processed'] ?? null, 'target-index acknowledgement should not repeat either document write');
    assert_same(1, count(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_starts_with($sql, '/* wp_fts:targeted-scope-page */')
    )), 'the scope-only completion pass should issue one targeted EOF selector');
    assert_same(0, (int) $wpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_work WHERE kind = 'scope'")->fetchColumn(), 'the targeted scope should disappear at target-index EOF');
    assert_same(2, (int) $wpdb->dbh->query('SELECT COUNT(*) FROM wp_fts_documents')->fetchColumn(), 'the two exact targets should be indexed while unrelated relationships remain untouched');
});

test_case_with_pdo_sqlite_fixture('relational v4 targeted scopes ignore unrelated relationship fanout', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    wp_fts_v4_regression_add_source_post($wpdb, 300, '<p>high fanout exact target</p>', '');
    for ($termTaxonomyId = 1; $termTaxonomyId <= 150; $termTaxonomyId++) {
        $wpdb->execute('INSERT INTO wp_term_relationships (object_id,term_taxonomy_id) VALUES (?,?)', [300, $termTaxonomyId]);
    }

    wp_fts_test_reset_wordpress_fakes();
    WP_FTS_Plugin::reset_request_caches();
    $queue = new WP_FTS_Index_Queue($wpdb);
    $queue->enqueue_scope(
        'high-fanout-targeted-scope',
        ['reason' => 'high-fanout-targeted-regression'],
        null,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED,
        'term_taxonomy',
        150
    );
    $wpdb->queries = [];

    $first = wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_manual_index_batch([
        'batch_size' => 100,
        'source' => 'high-fanout-targeted-regression',
    ]));
    assert_same(1, $first['backfill_scanned'] ?? null, '149 unrelated terms on one object must not consume the target page');
    assert_same(1, $first['backfill_queued'] ?? null, 'the requested taxonomy range should queue its one exact object');
    assert_same(300, (int) $wpdb->dbh->query("SELECT cursor_post_id FROM wp_fts_work WHERE kind = 'scope'")->fetchColumn(), 'the target cursor should advance to the exact object');
    assert_same([300], array_map('intval', $wpdb->dbh->query("SELECT post_id FROM wp_fts_work WHERE kind = 'post'")->fetchAll(PDO::FETCH_COLUMN)), 'the high-fanout object must be enqueued exactly once');

    $targetedQueries = array_values(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_starts_with($sql, '/* wp_fts:targeted-scope-page */')
    ));
    assert_same(1, count($targetedQueries), 'the high-fanout page should remain one SQL statement');
    assert_true(str_contains($targetedQueries[0] ?? '', 'scope_rel.term_taxonomy_id = 150'), 'the exact relationship probe must bind the requested taxonomy identity directly');
    assert_same(1, substr_count($targetedQueries[0] ?? '', 'FROM wp_term_relationships scope_rel'), 'high fanout must retain one exact relationship-table probe');
    assert_contains('INDEXED BY `wp_fts_', $targetedQueries[0] ?? '', 'high fanout must force the exact composite membership index');
});

test_case_with_pdo_sqlite_fixture('relational v4 selective keysets skip sparse gaps while corpus pages stay raw and bounded', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    for ($postId = 1; $postId <= 201; $postId++) {
        wp_fts_v4_regression_add_source_post(
            $wpdb,
            $postId,
            '<p>ineligible raw window</p>',
            '',
            'attachment',
            'inherit'
        );
    }
    wp_fts_v4_regression_add_source_post($wpdb, 202, '<p>first eligible row after sparse gap</p>', '');
    $wpdb->execute('INSERT INTO wp_term_relationships (object_id,term_taxonomy_id) VALUES (?,?)', [202, 777]);

    wp_fts_test_reset_wordpress_fakes();
    WP_FTS_Plugin::reset_request_caches();
    $scopePage = new ReflectionMethod(WP_FTS_Plugin::class, 'scope_candidate_post_ids_after');
    $filteredPayload = [
        'post_status' => ['publish'],
        'post_type' => ['post'],
        'remaining_limit' => 1,
    ];
    $pages = wp_fts_quality_with_wpdb($wpdb, static function () use ($scopePage, $filteredPayload): array {
        return [
            'targeted' => $scopePage->invoke(
                null,
                0,
                100,
                WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED,
                'term_taxonomy',
                777,
                []
            ),
            'filtered' => $scopePage->invoke(
                null,
                0,
                100,
                WP_FTS_Index_Queue::SCOPE_COVERAGE_FILTERED,
                '',
                0,
                $filteredPayload
            ),
            'corpus' => [
                $scopePage->invoke(null, 0, 100, WP_FTS_Index_Queue::SCOPE_COVERAGE_CORPUS, '', 0, []),
                $scopePage->invoke(null, 100, 100, WP_FTS_Index_Queue::SCOPE_COVERAGE_CORPUS, '', 0, []),
                $scopePage->invoke(null, 200, 100, WP_FTS_Index_Queue::SCOPE_COVERAGE_CORPUS, '', 0, []),
            ],
        ];
    });

    foreach (['targeted', 'filtered'] as $coverage) {
        assert_same(1, $pages[$coverage]['scanned_count'] ?? null, "{$coverage} must read only its one matching composite-key row");
        assert_same(202, $pages[$coverage]['cursor_post_id'] ?? null, "{$coverage} must advance directly to the selected high-water");
        assert_same([202], $pages[$coverage]['post_ids'] ?? null, "{$coverage} must skip all 201 unrelated canonical rows");
        assert_same(false, $pages[$coverage]['exhausted'] ?? null, "{$coverage} must return a non-terminal selected page");
    }
    $corpusPages = $pages['corpus'];
    assert_same([100, 100, 2], array_column($corpusPages, 'scanned_count'), 'corpus reconciliation must bound each raw posts/documents page');
    assert_same([100, 200, 202], array_column($corpusPages, 'cursor_post_id'), 'corpus reconciliation must advance its raw global high-water');
    assert_same([], $corpusPages[0]['post_ids'] ?? null, 'the first corpus page must not queue ineligible attachments');
    assert_same([], $corpusPages[1]['post_ids'] ?? null, 'the second corpus page must not queue ineligible attachments');
    assert_same([202], $corpusPages[2]['post_ids'] ?? null, 'the corpus tail must queue its one eligible canonical post');

    $queue = new WP_FTS_Index_Queue($wpdb);
    $queue->enqueue_scope(
        'sparse-gap-worker-scope',
        ['reason' => 'sparse-gap-worker-regression', ...$filteredPayload],
        null,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_FILTERED
    );
    $wpdb->queries = [];
    $selectedPage = wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_manual_index_batch([
        'batch_size' => 100,
        'source' => 'sparse-gap-worker-regression',
    ]));
    assert_same(1, $selectedPage['backfill_scanned'] ?? null, 'the worker must reach a rare filtered row in one direct page');
    assert_same(1, $selectedPage['backfill_queued'] ?? null, 'the direct filtered page must enqueue exactly its match');
    assert_same(202, (int) $wpdb->dbh->query("SELECT cursor_post_id FROM wp_fts_work WHERE kind='scope'")->fetchColumn(), 'the filtered cursor must advance directly over the sparse gap');
    assert_same([202], array_map('intval', $wpdb->dbh->query("SELECT post_id FROM wp_fts_work WHERE kind='post'")->fetchAll(PDO::FETCH_COLUMN)), 'only the selected filtered post may be queued');
    assert_same(1, count(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_starts_with($sql, '/* wp_fts:filtered-scope-page */')
    )), 'the sparse filtered selection must use one SQL statement, not one page per unrelated ID range');

    $wpdb->queries = [];
    $postDrain = wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_manual_index_batch([
        'batch_size' => 100,
        'source' => 'sparse-gap-worker-regression',
    ]));
    assert_same(false, $postDrain['scope_completed'] ?? null, 'the exact post drain should leave the filtered scope ready');
    assert_same(1, $postDrain['processed'] ?? null, 'the second pass must process the exact post without adding scope SQL');
    assert_same(true, $postDrain['has_more'] ?? null, 'the exact post drain should request its filtered EOF successor');
    assert_same(0, count(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_starts_with($sql, '/* wp_fts:filtered-scope-page */')
    )), 'the exact post drain must issue no filtered scope selector');
    assert_same(1, (int) $wpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_work WHERE kind='scope'")->fetchColumn(), 'the post drain should preserve the exact filtered scope generation');

    $wpdb->queries = [];
    $completion = wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_manual_index_batch([
        'batch_size' => 100,
        'source' => 'sparse-gap-worker-regression',
    ]));
    assert_same(true, $completion['scope_completed'] ?? null, 'one scope-only empty composite range must prove filtered EOF');
    assert_same(0, $completion['processed'] ?? null, 'the filtered EOF pass must not repeat the exact post');
    assert_same(0, count(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_starts_with($sql, '/* wp_fts:filtered-scope-page */')
    )), 'the exhausted durable remaining limit should complete without a redundant composite selector');
    assert_same(0, (int) $wpdb->dbh->query("SELECT COUNT(*) FROM wp_fts_work WHERE kind IN ('scope','post')")->fetchColumn(), 'the sparse filtered workflow must fully drain after alternating scope and post passes');
});

test_case_with_pdo_sqlite_fixture('relational v6 worker defers the 50000-posting aggregate overflow without a hot loop or lost generation', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    $terms = [];
    // Each token contributes one lexical and one normalized-surface identity.
    // 252 tokens therefore produce 504 postings: 99 documents fit, while the
    // 100th crosses the 50,000-row transaction frontier.
    for ($term = 0; $term < 252; $term++) {
        $terms[] = 'aggregate' . str_pad((string) $term, 4, '0', STR_PAD_LEFT);
    }
    $content = '<p>' . implode(' ', $terms) . '</p>';
    for ($postId = 20001; $postId <= 20100; $postId++) {
        wp_fts_v4_regression_add_source_post($wpdb, $postId, $content, '');
    }

    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = [
        'auto_detect_language' => false,
        'enable_stemming' => false,
    ];
    WP_FTS_Plugin::reset_request_caches();

    wp_fts_quality_with_wpdb($wpdb, static function (): void {
        assert_same(100, WP_FTS_Plugin::enqueue_posts_for_reindex(range(20001, 20100), ['lang' => 'en']), 'fixture should durably enqueue all 100 generations');
    });
    $wpdb->queries = [];

    $first = wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_manual_index_batch([
        'batch_size' => 100,
        'source' => 'aggregate-split-regression',
    ]));
    assert_same(99, $first['processed'] ?? null, 'first worker pass should commit only the deterministic prefix that fits 50,000 posting mutations');
    assert_same(true, $first['has_more'] ?? null, 'the split pass should explicitly signal deferred work');
    assert_same(99, (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_fts_documents'), 'first pass should publish exactly the committed prefix');
    $remaining = $wpdb->get_row("SELECT post_id,generation,state,attempts,claim_token,claimed_generation FROM wp_fts_work WHERE kind = 'post'");
    assert_same(20100, (int) ($remaining->post_id ?? 0), 'only the document beyond the deterministic split should remain queued');
    assert_same(1, (int) ($remaining->generation ?? 0), 'deferral must preserve the desired generation');
    assert_same('ready', (string) ($remaining->state ?? ''), 'deferred claim should be released immediately rather than left leased');
    assert_same(0, (int) ($remaining->attempts ?? -1), 'aggregate deferral is not a content failure and must not consume a retry');
    assert_same('', (string) ($remaining->claim_token ?? ''), 'deferred claim should not retain its worker token');
    assert_same(0, (int) ($remaining->claimed_generation ?? -1), 'deferred claim should clear its claimed generation');

    $firstTransactions = count(array_filter($wpdb->queries, static fn(string $sql): bool => $sql === 'BEGIN'));
    assert_same(2, $firstTransactions, 'one worker invocation should atomically commit the storage replacement and the cursor-invalidating acknowledgement');
    assert_same(2, count(array_filter($wpdb->queries, static fn(string $sql): bool => $sql === 'COMMIT')), 'the split pass should commit replacement and visibility publication exactly once each');
    assert_same(0, count(array_filter($wpdb->queries, static fn(string $sql): bool => $sql === 'ROLLBACK')), 'a deterministic aggregate split should not enter and roll back an oversized transaction');
    $releaseQueries = array_values(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_starts_with($sql, "UPDATE wp_fts_work\nSET state = 'ready', available_at = ")
    ));
    assert_same(1, count($releaseQueries), 'first pass should release deferred claims exactly once, with no internal retry loop');
    assert_true(!str_contains(implode("\n", $wpdb->queries), "last_error_code = 'content_failure'"), 'aggregate deferral must not use the failure path');

    $wpdb->queries = [];
    $second = wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_manual_index_batch([
        'batch_size' => 100,
        'source' => 'aggregate-split-regression',
    ]));
    assert_same(1, $second['processed'] ?? null, 'the next bounded pass should process the one deferred generation');
    assert_same(false, $second['has_more'] ?? null, 'the drained queue should not force a synchronous empty claim solely for dictionary cleanup');
    assert_same(false, $second['cleanup_pending'] ?? null, 'the final short document batch should complete bounded dictionary cleanup without scheduling an empty pass');
    assert_same(100, (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_fts_documents'), 'both passes should publish all 100 documents');
    assert_same(50400, (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_fts_postings'), 'both passes should retain every valid lexical and normalized-surface posting');
    assert_same(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_fts_terms WHERE doc_freq <> 100'), 'split passes should converge every shared term document frequency to 100');
    assert_same(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_fts_work WHERE kind IN ('post','scope')"), 'the deferred generation should be acknowledged only after the second commit');
    assert_same(2, count(array_filter($wpdb->queries, static fn(string $sql): bool => $sql === 'BEGIN')), 'next pass should atomically commit one replacement and one visibility publication');

    $wpdb->queries = [];
    $cleanup = wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_manual_index_batch([
        'batch_size' => 100,
        'source' => 'aggregate-split-regression',
    ]));
    assert_same(0, $cleanup['processed'] ?? null, 'an idempotent drained continuation should not repeat document work');
    assert_same(false, $cleanup['has_more'] ?? null, 'an idempotent drained continuation should terminate without a hot loop');
    assert_same(0, count(array_filter($wpdb->queries, static fn(string $sql): bool => $sql === 'BEGIN')), 'a drained continuation should not open a document replacement transaction');
});

test_case_with_pdo_sqlite_fixture('relational v6 worker preserves a source-deferred suffix at the 50000-posting frontier', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);
    $terms = [];
    // 255 lexical identities plus their 255 normalized surfaces produce 510
    // postings per document: 98 fit, while 99 exceed 50,000.
    for ($term = 0; $term < 255; $term++) {
        $terms[] = 'mixedsplit' . str_pad((string) $term, 4, '0', STR_PAD_LEFT);
    }
    $sharedContent = '<!--' . str_repeat('x', 64000) . '--><p>' . implode(' ', $terms) . '</p>';
    for ($postId = 21001; $postId <= 21099; $postId++) {
        wp_fts_v4_regression_add_source_post($wpdb, $postId, $sharedContent, '');
    }
    wp_fts_v4_regression_add_source_post($wpdb, 21100, str_repeat(' ', 1200000), '');

    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = [
        'auto_detect_language' => false,
        'enable_stemming' => false,
    ];
    WP_FTS_Plugin::reset_request_caches();
    wp_fts_quality_with_wpdb($wpdb, static function (): void {
        assert_same(100, WP_FTS_Plugin::enqueue_posts_for_reindex(range(21001, 21100), ['lang' => 'en']), 'fixture should enqueue every mixed-deferral generation');
    });
    $wpdb->queries = [];

    $first = wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_manual_index_batch([
        'batch_size' => 100,
        'source' => 'source-plus-writer-split-regression',
    ]));
    assert_same(98, $first['processed'] ?? null, 'first pass should commit only the 98-document posting prefix');
    assert_same(true, $first['has_more'] ?? null, 'both deferral causes should leave explicit follow-up work');
    assert_same(
        range(21001, 21098),
        array_map('intval', $wpdb->get_col('SELECT post_id FROM wp_fts_documents ORDER BY post_id')),
        'first commit should publish and acknowledge only the retained writer prefix'
    );
    $remaining = $wpdb->get_results("SELECT post_id,generation,state,attempts,claim_token,claimed_generation FROM wp_fts_work WHERE kind = 'post' ORDER BY post_id");
    assert_same([21099, 21100], array_map(static fn(object $row): int => (int) $row->post_id, $remaining), 'writer split and source cap should release their exact two generations');
    foreach ($remaining as $row) {
        assert_same(1, (int) $row->generation, 'each deferred row should retain its desired generation');
        assert_same('ready', (string) $row->state, 'each deferred row should be immediately claimable');
        assert_same(0, (int) $row->attempts, 'capacity deferral must not consume a content retry');
        assert_same('', (string) $row->claim_token, 'released rows must not retain the first worker token');
        assert_same(0, (int) $row->claimed_generation, 'released rows must clear the claimed generation fence');
    }
    assert_same(2, count(array_filter($wpdb->queries, static fn(string $sql): bool => $sql === 'BEGIN')), 'mixed deferral should atomically commit the retained prefix and its visibility publication');
    assert_same(2, count(array_filter($wpdb->queries, static fn(string $sql): bool => $sql === 'COMMIT')), 'mixed deferral should commit replacement and acknowledgement exactly once each');
    assert_same(0, count(array_filter($wpdb->queries, static fn(string $sql): bool => $sql === 'ROLLBACK')), 'pre-write split should not attempt an oversized transaction');
    $releaseQueries = array_values(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_starts_with($sql, "UPDATE wp_fts_work\nSET state = 'ready', available_at = ")
    ));
    assert_same(1, count($releaseQueries), 'source and writer deferrals should coalesce into one exact release statement');
    assert_contains("job_key = 'post:21099'", $releaseQueries[0] ?? '', 'release statement should include the writer-split generation');
    assert_contains("job_key = 'post:21100'", $releaseQueries[0] ?? '', 'release statement should include the pre-existing source-deferred generation');

    $wpdb->queries = [];
    $second = wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_manual_index_batch([
        'batch_size' => 100,
        'source' => 'source-plus-writer-split-regression',
    ]));
    assert_same(2, $second['processed'] ?? null, 'the next pass should make progress on both exact deferred generations');
    assert_same(false, $second['has_more'] ?? null, 'draining mixed deferrals should not force a synchronous empty claim solely for dictionary cleanup');
    assert_same(false, $second['cleanup_pending'] ?? null, 'the final short capacity batch should complete bounded dictionary cleanup without scheduling an empty pass');
    assert_same(100, (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_fts_documents'), 'follow-up should publish all 100 documents');
    assert_same(50490, (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_fts_postings'), 'follow-up should retain every posting from the 99 nonempty documents');
    assert_same(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_fts_terms WHERE doc_freq <> 99'), 'split passes should converge every shared term frequency to 99');
    assert_same(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_fts_work WHERE kind IN ('post','scope')"), 'only successful follow-up should acknowledge both deferred generations');

    $wpdb->queries = [];
    $cleanup = wp_fts_quality_with_wpdb($wpdb, static fn(): array => WP_FTS_Plugin::process_manual_index_batch([
        'batch_size' => 100,
        'source' => 'source-plus-writer-split-regression',
    ]));
    assert_same(0, $cleanup['processed'] ?? null, 'an idempotent mixed-deferral continuation should not repeat document work');
    assert_same(false, $cleanup['has_more'] ?? null, 'an idempotent mixed-deferral continuation should terminate without a hot loop');
    assert_same(0, count(array_filter($wpdb->queries, static fn(string $sql): bool => $sql === 'BEGIN')), 'a drained mixed-deferral continuation should not open a document replacement transaction');
});

test_case('relational v6 writer signals the 50000-posting aggregate overflow before issuing SQL', function (): void {
    assert_same(8192, WP_FTS_Storage_Mysql::MAX_DOCUMENT_POSTINGS, 'one prepared document should admit at most 4096 lexical plus 4096 normalized-surface postings');
    assert_same(50000, WP_FTS_Storage_Mysql::MAX_BATCH_POSTINGS, 'one replacement transaction should retain the bounded old-plus-new 50000-posting mutation frontier');

    $wpdb = new WP_FTS_Test_WPDB();
    $storage = new WP_FTS_Storage_Mysql($wpdb);
    $sharedTerms = [];
    for ($term = 0; $term < 501; $term++) {
        $sharedTerms[WP_FTS_TermNamespace::namespace_term('en', 'aggregate' . $term)] = 1;
    }
    $documents = [];
    for ($postId = 1; $postId <= 100; $postId++) {
        $documents[] = [
            'doc_id' => $postId,
            'primary_lang' => 'en',
            'content_hash' => hash('sha256', (string) $postId),
            'lang_lengths' => ['en' => 501],
            'term_frequencies' => $sharedTerms,
            'metadata' => ['search_text' => "100% aggregate O'Reilly post {$postId}"],
        ];
    }

    $split = null;
    try {
        $storage->replace_prepared_documents($documents);
    } catch (WP_FTS_Prepared_Batch_Split_Required $error) {
        $split = $error;
    }
    assert_true($split instanceof WP_FTS_Prepared_Batch_Split_Required, '100 individually valid documents with 50100 aggregate postings should request a typed split');
    assert_same(100, $split->document_count, 'typed split should report the complete document count');
    assert_same(50100, $split->posting_count, 'typed split should report the aggregate posting count');
    assert_same(50000, $split->posting_limit, 'typed split should expose the storage posting-mutation limit');
    assert_same(99, $split->split_after_documents, 'typed split should identify the largest prefix within the aggregate limit');
    assert_contains('split after 99 documents', $split->getMessage(), 'operator-visible split detail should agree with typed properties');
    assert_same([], $wpdb->queries, 'aggregate overflow must be detected before opening a transaction or issuing any SQL');
    assert_same([], $wpdb->docs, 'pre-write aggregate overflow must leave document state untouched');
    assert_same([], $wpdb->postings, 'pre-write aggregate overflow must leave posting state untouched');

    $languageRejection = null;
    try {
        $storage->replace_prepared_documents([[
            'doc_id' => 77,
            'primary_lang' => str_repeat('a', 33),
            'content_hash' => 'language-poison',
            'lang_lengths' => [],
            'term_frequencies' => [],
            'metadata' => ['search_text' => ''],
        ]]);
    } catch (WP_FTS_Prepared_Document_Rejected $error) {
        $languageRejection = $error;
    }
    assert_true($languageRejection instanceof WP_FTS_Prepared_Document_Rejected, 'a canonical language wider than VARBINARY(32) should be rejected as a typed poison document');
    assert_same(77, $languageRejection->post_id, 'language rejection should identify only the poison generation');
    assert_same('invalid_language', $languageRejection->reason_code, 'language rejection should expose a stable recovery reason');
    assert_same([], $wpdb->queries, 'oversized canonical language must be rejected before opening a transaction or issuing SQL');
    assert_same([], $wpdb->docs, 'oversized canonical language must not publish a document');
});

test_case_with_pdo_sqlite_fixture('relational v4 search plan accepts 12x12x12 boundaries and rejects the next unit before SQL', function (): void {
    assert_same(12, WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_GROUPS, 'the relational interface should cap one query at twelve logical groups');
    assert_same(12, WP_FTS_Set_Oriented_Search_Storage::MAX_ALTERNATIVES_PER_GROUP, 'one logical group should retain up to twelve exact morphology alternatives');
    assert_same(12, WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_ALTERNATIVES, 'one query should carry at most twelve total alternatives');

    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    wp_fts_v4_regression_add_post($wpdb, 1, '2026-07-18 00:00:00');
    $keys = [];
    for ($index = 0; $index < 20; $index++) {
        $term = 'planbound' . str_pad((string) $index, 2, '0', STR_PAD_LEFT);
        $keys[] = WP_FTS_TermNamespace::namespace_term('en', $term);
        wp_fts_v4_regression_add_term($wpdb, $term, [1 => 100.0]);
    }
    $options = array_replace(wp_fts_v4_regression_search_options(10), ['mode' => 'AND']);

    $twelveGroups = [];
    for ($group = 0; $group < 12; $group++) {
        $twelveGroups[] = [['key' => $keys[$group], 'rank' => 0]];
    }
    $wpdb->queries = [];
    assert_same([1], array_column($storage->search_page($twelveGroups, $options)['results'], 'doc_id'), 'exactly twelve mandatory groups should remain searchable');
    assert_same(2, count($wpdb->queries), 'the maximum group boundary should still use one plan and one rank statement');
    assert_same(
        12,
        count($wpdb->dbh->query(wp_fts_v4_regression_last_plan_sql($wpdb))->fetchAll(PDO::FETCH_OBJ)),
        'the exact planning arm should return one row per requested identity at the twelve-alternative boundary'
    );

    $twelveSingleGroupAlternatives = [[]];
    for ($rank = 0; $rank < 12; $rank++) {
        $twelveSingleGroupAlternatives[0][] = ['key' => $keys[$rank], 'rank' => $rank];
    }
    $wpdb->queries = [];
    assert_same([1], array_column($storage->search_page($twelveSingleGroupAlternatives, array_replace($options, ['mode' => 'OR']))['results'], 'doc_id'), 'exactly twelve alternatives in one logical group should retain complete bounded real-pack ambiguity');
    assert_same(2, count($wpdb->queries), 'the per-group alternative boundary should retain the two-statement search shape');

    $wpdb->queries = [];
    $prefixBoundaryOptions = array_replace($options, [
        'mode' => 'OR',
        'prefix_matching' => true,
        'prefix_group_index' => 0,
        'prefix_surface' => ['lang' => 'en', 'term' => 'planbound'],
        'prefix_min_length' => 4,
    ]);
    wp_fts_v6_regression_add_surface($wpdb, 'planbound00', [1 => 100.0]);
    assert_same([1], array_column($storage->search_page($twelveSingleGroupAlternatives, $prefixBoundaryOptions)['results'], 'doc_id'), 'the exact twelve-alternative boundary should compose with one surface dictionary range');
    assert_same(
        13,
        count($wpdb->dbh->query(wp_fts_v4_regression_last_plan_sql($wpdb))->fetchAll(PDO::FETCH_OBJ)),
        'twelve exact identities plus one bounded surface-cost aggregate row should be the hard maximum planning result'
    );
    assert_same(2, count($wpdb->queries), 'the maximum 13-row plan should still use one planning and one ranking statement');
    $prefixPlanSql = wp_fts_v4_regression_last_plan_sql($wpdb);
    assert_contains('pt.kind = 1 AND pt.term >=', $prefixPlanSql, 'the maximum plan should prove one normalized-surface range');
    assert_same(1, substr_count($prefixPlanSql, 'pt.term >='), 'the maximum plan must contain one indexed lower range bound');
    assert_same(1, substr_count($prefixPlanSql, 'pt.term <'), 'the maximum plan must contain one exclusive successor bound');

    $wpdb->queries = [];
    $maximumAndPrefix = $storage->search_page($twelveGroups, array_replace($prefixBoundaryOptions, [
        'mode' => 'AND',
        'prefix_group_index' => 11,
    ]));
    assert_same([1], array_column($maximumAndPrefix['results'], 'doc_id'), 'the twelve-group AND boundary should compose with one costed final-prefix range');
    assert_same(2, count($wpdb->queries), 'the maximum AND-prefix boundary should remain one plan and one rank statement');
    $maximumAndPlanSql = wp_fts_v4_regression_last_plan_sql($wpdb);
    assert_true(strlen($maximumAndPlanSql) <= 32768, 'the maximum AND-prefix plan, including its impossible-group gate, must stay below 32 KiB');
    assert_same(1, substr_count($maximumAndPlanSql, ') mandatory_requested'), 'the maximum AND-prefix plan should gate all eleven non-prefix groups in one constant relation');
    assert_same(1, substr_count($maximumAndPlanSql, 'SUM(surface_identity.doc_freq)'), 'the maximum AND-prefix plan should cost its one surface range exactly once');

    $twelveAlternatives = [];
    for ($group = 0; $group < 3; $group++) {
        $alternatives = [];
        for ($rank = 0; $rank < 4; $rank++) {
            $alternatives[] = ['key' => $keys[$group * 4 + $rank], 'rank' => $rank];
        }
        $twelveAlternatives[] = $alternatives;
    }
    $wpdb->queries = [];
    assert_same([1], array_column($storage->search_page($twelveAlternatives, $options)['results'], 'doc_id'), 'exactly twelve alternatives across valid groups should remain searchable');
    assert_same(2, count($wpdb->queries), 'the total-alternative boundary should retain the two-statement search shape');

    $rejections = [
        'thirteenth logical group' => ['logical groups', [...$twelveGroups, [['key' => $keys[12], 'rank' => 0]]]],
        'thirteenth group alternative' => ['input alternatives per logical group', [[
            ...$twelveSingleGroupAlternatives[0],
            ['key' => $keys[12], 'rank' => 12],
        ]]],
        'thirteenth total alternative' => ['analyzed alternatives', [
            ...$twelveAlternatives,
            [['key' => $keys[12], 'rank' => 0]],
        ]],
    ];
    foreach ($rejections as $label => [$expectedBudget, $groups]) {
        $wpdb->queries = [];
        $rejected = null;
        try {
            $storage->search_page($groups, $options);
        } catch (WP_FTS_Search_Budget_Exceeded $error) {
            $rejected = $error;
        }
        assert_true($rejected instanceof WP_FTS_Search_Budget_Exceeded, "the {$label} should be rejected rather than truncated");
        assert_same($expectedBudget, $rejected?->budget(), "the {$label} should expose its stable bounded-plan reason");
        assert_same([], $wpdb->queries, "the {$label} must be rejected before dictionary or ranking SQL");
    }
});

test_case_with_pdo_sqlite_fixture('relational v4 direct AND plans reject malformed groups without widening', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    wp_fts_v4_regression_add_post($wpdb, 1, '2026-07-18 00:00:00');
    wp_fts_v4_regression_add_term($wpdb, 'validplanarm', [1 => 100.0]);
    $validGroup = wp_fts_v4_regression_groups('validplanarm')[0];
    $options = array_replace(wp_fts_v4_regression_search_options(10), ['mode' => 'AND']);

    $wpdb->queries = [];
    assert_same([1], array_column($storage->search_page([$validGroup], $options)['results'], 'doc_id'), 'the valid AND arm should prove that dropping a malformed peer would widen results');
    assert_same(2, count($wpdb->queries), 'the valid control should retain the one-plan/one-rank shape');

    $malformedPlans = [
        'non-array group' => [$validGroup, 'not-a-group'],
        'empty group' => [$validGroup, []],
        'non-array candidate' => [$validGroup, ['not-a-candidate']],
        'missing candidate key' => [$validGroup, [['rank' => 0]]],
        'non-scalar candidate key' => [$validGroup, [['key' => new stdClass(), 'rank' => 0]]],
        'empty candidate key' => [$validGroup, [['key' => '   ', 'rank' => 0]]],
    ];
    foreach ([false, -1, 1.0, '01', '-1', 13, str_repeat('1', 65)] as $rank) {
        $malformedPlans['malformed alternative rank ' . var_export($rank, true)] = [
            $validGroup,
            [['key' => WP_FTS_TermNamespace::namespace_term('en', 'validplanarm'), 'rank' => $rank]],
        ];
    }
    foreach ($malformedPlans as $label => $groups) {
        $wpdb->queries = [];
        $error = null;
        try {
            $storage->search_page($groups, $options);
        } catch (InvalidArgumentException $caught) {
            $error = $caught;
        }
        assert_true($error instanceof InvalidArgumentException, "a {$label} must reject the complete AND plan rather than disappear");
        assert_same([], $wpdb->queries, "a {$label} must be rejected before dictionary planning SQL");
    }
});

test_case_with_pdo_sqlite_fixture('relational v4 direct metadata filters reject malformed restrictions without widening', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    wp_fts_v4_regression_add_post($wpdb, 1, '2026-07-18 00:00:00');
    wp_fts_v4_regression_add_term($wpdb, 'filterfailclosed', [1 => 100.0]);
    $groups = wp_fts_v4_regression_groups('filterfailclosed');
    $options = wp_fts_v4_regression_search_options(10);

    $wpdb->queries = [];
    assert_same([1], array_column($storage->search_page($groups, $options)['results'], 'doc_id'), 'the fixture must prove an omitted filter would return the document');
    assert_same(2, count($wpdb->queries), 'the unfiltered control should execute one plan and one rank statement');

    $wpdb->queries = [];
    assert_same(
        [1],
        array_column($storage->search_page($groups, array_replace($options, [
            'post_types' => [],
            'post_statuses' => [],
        ]))['results'], 'doc_id'),
        'exactly empty filter arrays should remain the explicit no-backend-filter form'
    );
    assert_same(2, count($wpdb->queries), 'the exact-empty filter control should execute one plan and one rank statement');

    $resource = fopen('php://memory', 'rb');
    assert_true(is_resource($resource), 'the malformed-filter fixture should allocate a resource');
    try {
        foreach (['post_types', 'post_statuses'] as $filter) {
            foreach ([
                null,
                'post',
                '',
                [''],
                ['   '],
                [false],
                ['post', ''],
                [str_repeat('x', 65)],
                new stdClass(),
                [new stdClass()],
                $resource,
                [$resource],
            ] as $value) {
                $wpdb->queries = [];
                $rejected = null;
                try {
                    $storage->search_page($groups, array_replace($options, [$filter => $value]));
                } catch (InvalidArgumentException $error) {
                    $rejected = $error;
                }
                assert_true($rejected instanceof InvalidArgumentException, "a malformed {$filter} restriction must fail closed");
                assert_same([], $wpdb->queries, "a malformed {$filter} restriction must be rejected before planning SQL");
            }
        }
    } finally {
        fclose($resource);
    }
});

test_case_with_pdo_sqlite_fixture('relational v4 direct storage options reject unknown keys and malformed values before SQL', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();
    wp_fts_v4_regression_add_post($wpdb, 1, '2026-07-18 00:00:00');
    wp_fts_v4_regression_add_term($wpdb, 'optionfailclosed', [1 => 100.0]);
    $groups = wp_fts_v4_regression_groups('optionfailclosed');
    $options = wp_fts_v4_regression_search_options(10);

    foreach ([
        'singular filter alias' => ['post_type' => ['post']],
        'misspelled option' => ['post_statues' => ['publish']],
        'legacy prefix limit' => ['prefix_max_terms' => 2],
        'numeric option key' => [0 => 'ignored'],
    ] as $label => $extra) {
        $wpdb->queries = [];
        $error = null;
        try {
            $storage->search_page($groups, $options + $extra);
        } catch (InvalidArgumentException $caught) {
            $error = $caught;
        }
        assert_true($error instanceof InvalidArgumentException, "an {$label} must not be silently ignored");
        assert_same([], $wpdb->queries, "an {$label} must be rejected before planning SQL");
    }

    $resource = fopen('php://memory', 'rb');
    assert_true(is_resource($resource), 'the malformed-option fixture should allocate a resource');
    try {
        $knownOptions = [
            'mode', 'page_size', 'limit', 'cursor', 'direction', 'query_lang',
            'prefix_matching', 'prefix_group_index', 'prefix_min_length', 'prefix_surface',
            'post_types', 'post_statuses', 'date_after', 'date_before',
            'include_metadata', 'include_snippets', 'include_canonical_post_row',
            'highlight', 'snippet_length', 'explain', 'recency_boost_strength',
            'recency_boost_half_life_days', 'now_gmt',
        ];
        foreach ($knownOptions as $key) {
            foreach ([new stdClass(), $resource] as $value) {
                $wpdb->queries = [];
                $error = null;
                try {
                    $storage->search_page($groups, array_replace($options, [$key => $value]));
                } catch (InvalidArgumentException $caught) {
                    $error = $caught;
                }
                assert_true($error instanceof InvalidArgumentException, "a non-scalar {$key} value must reject the direct storage call");
                assert_same([], $wpdb->queries, "a non-scalar {$key} value must be rejected before planning SQL");
            }
        }

        foreach ([false, 0, '', '   ', new stdClass(), $resource] as $cursor) {
            $wpdb->queries = [];
            $error = null;
            try {
                $storage->search_page($groups, array_replace($options, ['cursor' => $cursor]));
            } catch (InvalidArgumentException $caught) {
                $error = $caught;
            }
            assert_true($error instanceof InvalidArgumentException, 'a direct cursor must be a nonempty bounded string');
            assert_same([], $wpdb->queries, 'a malformed direct cursor must be rejected before planning SQL');
        }
    } finally {
        fclose($resource);
    }

    $numericRejections = [
        'page_size' => [false, 0, -1, 1.0, '1.0', '01', 51, NAN, INF],
        'limit' => [false, 0, -1, 1.0, '1.0', '01', 52, NAN, INF],
        'prefix_group_index' => [false, -1, 1.0, '1.0', '01', 12, NAN, INF],
        'prefix_min_length' => [false, -1, 1.0, '1.0', '01', 256, 'nonsense', NAN, INF],
        'snippet_length' => [false, 0, -1, 1.0, '1.0', '01', 501, NAN, INF],
        'recency_boost_strength' => [false, -0.1, 2.1, 'nonsense', NAN, INF, -INF],
        'recency_boost_half_life_days' => [false, 0, -1, 3651, 'nonsense', NAN, INF, -INF],
    ];
    foreach ($numericRejections as $key => $values) {
        foreach ($values as $value) {
            $wpdb->queries = [];
            $error = null;
            try {
                $storage->search_page($groups, array_replace($options, [$key => $value]));
            } catch (InvalidArgumentException $caught) {
                $error = $caught;
            }
            assert_true($error instanceof InvalidArgumentException, "a malformed or out-of-range {$key} must reject the direct storage call");
            assert_same([], $wpdb->queries, "a malformed or out-of-range {$key} must be rejected before planning SQL");
        }
    }

    $wpdb->queries = [];
    $overlongSnippet = null;
    try {
        $storage->search_page($groups, array_replace($options, ['snippet_length' => str_repeat('1', 65)]));
    } catch (InvalidArgumentException $caught) {
        $overlongSnippet = $caught;
    }
    assert_true($overlongSnippet instanceof InvalidArgumentException, 'snippet_length should share the bounded numeric-scalar validation');
    assert_same([], $wpdb->queries, 'an overlong snippet_length must be rejected before planning SQL');
});

test_case_with_pdo_sqlite_fixture('relational v6 Mysql advertises set-oriented capabilities without legacy posting primitives', function (): void {
    [$wpdb, $storage] = wp_fts_v4_regression_search_fixture();

    assert_true($storage instanceof WP_FTS_Set_Oriented_Search_Storage, 'Mysql should expose database-owned planning, ranking, and pagination');
    assert_true($storage instanceof WP_FTS_Resettable_Storage, 'Mysql should expose its constant-statement index reset');
    assert_true(!$storage instanceof WP_FTS_Storage, 'Mysql must not implement the legacy blob storage contract');
    assert_true(!$storage instanceof WP_FTS_DocumentMetadataStorage, 'Mysql must not claim the legacy point-metadata writer contract');
    assert_true(!$storage instanceof WP_FTS_Row_Postings_Writer_Storage, 'Mysql must not expose the obsolete one-document posting primitive');
    assert_true(!$storage instanceof WP_FTS_Row_Postings_Storage, 'Mysql must not claim that legacy callers can read materialized posting maps');
    assert_true(!$storage instanceof WP_FTS_Capped_Postings_Storage, 'Mysql must not publish a capped posting-list reader that always throws');
    assert_true(!$storage instanceof WP_FTS_Budgeted_Postings_Storage, 'Mysql must not publish a budgeted posting-list reader that always throws');
    assert_true(!$storage instanceof WP_FTS_DocumentMetadataFilterStorage, 'Mysql must not publish a PHP metadata-filter reader that always throws');
    assert_true(!$storage instanceof WP_FTS_Prefix_Term_Storage, 'Mysql must not publish a PHP prefix enumeration reader that always throws');
    assert_true(new WP_FTS_Storage_InMemory() instanceof WP_FTS_Row_Postings_Storage, 'the legacy in-memory fixture should retain its existing decoded posting reader');
    assert_true(!is_subclass_of(WP_FTS_Storage_File::class, WP_FTS_Row_Postings_Writer_Storage::class), 'the legacy file backend should retain its existing blob writer behavior');

    foreach (['replace_doc_postings', 'get_terms', 'get_postings', 'put_doc', 'delete_doc'] as $method) {
        assert_true(!method_exists($storage, $method), "Mysql must not expose obsolete {$method}");
    }

    $termKey = WP_FTS_TermNamespace::namespace_term('en', 'writerprobe');
    $result = $storage->replace_prepared_documents([[
        'doc_id' => 991,
        'primary_lang' => 'en',
        'term_frequencies' => [$termKey => 3],
        'surface_frequencies' => [],
    ]]);
    assert_same(1, $result['replaced'] ?? null, 'the measured prepared-document writer should publish one document');
    assert_same(
        1,
        (int) $wpdb->dbh->query('SELECT COUNT(*) FROM wp_fts_postings WHERE post_id = 991')->fetchColumn(),
        'the bounded batch writer should publish the requested posting on real SQLite SQL'
    );
});

/** @return array{0:WP_FTS_V4_Regression_SQLite_WPDB,1:WP_FTS_Storage_Mysql} */
function wp_fts_v4_regression_search_fixture(): array
{
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    wp_fts_v4_regression_create_schema($wpdb);

    return [$wpdb, new WP_FTS_Storage_Mysql($wpdb)];
}

/** Install the production-shaped schema and readiness state used by every case. */
function wp_fts_v4_regression_create_schema(WP_FTS_V4_Regression_SQLite_WPDB $wpdb): void
{
    $statements = [
        "CREATE TABLE wp_posts (
            ID INTEGER PRIMARY KEY,
            post_author INTEGER NOT NULL DEFAULT 0,
            post_date TEXT NOT NULL DEFAULT '',
            post_date_gmt TEXT NOT NULL DEFAULT '',
            post_content TEXT NOT NULL DEFAULT '',
            post_title TEXT NOT NULL DEFAULT '',
            post_excerpt TEXT NOT NULL DEFAULT '',
            post_status TEXT NOT NULL DEFAULT '',
            comment_status TEXT NOT NULL DEFAULT '',
            ping_status TEXT NOT NULL DEFAULT '',
            post_password TEXT NOT NULL DEFAULT '',
            post_name TEXT NOT NULL DEFAULT '',
            to_ping TEXT NOT NULL DEFAULT '',
            pinged TEXT NOT NULL DEFAULT '',
            post_modified TEXT NOT NULL DEFAULT '',
            post_modified_gmt TEXT NOT NULL DEFAULT '',
            post_content_filtered TEXT NOT NULL DEFAULT '',
            post_parent INTEGER NOT NULL DEFAULT 0,
            guid TEXT NOT NULL DEFAULT '',
            menu_order INTEGER NOT NULL DEFAULT 0,
            post_type TEXT NOT NULL DEFAULT '',
            post_mime_type TEXT NOT NULL DEFAULT '',
            comment_count INTEGER NOT NULL DEFAULT 0
        )",
        'CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT NOT NULL UNIQUE, option_value TEXT NOT NULL)',
        'CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT)',
        'CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
        'CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL)',
        'CREATE TABLE wp_term_relationships (object_id INTEGER NOT NULL, term_taxonomy_id INTEGER NOT NULL, PRIMARY KEY(object_id,term_taxonomy_id))',
        'CREATE TABLE wp_fts_terms (term_id INTEGER PRIMARY KEY AUTOINCREMENT, lang BLOB NOT NULL, kind INTEGER NOT NULL, term BLOB NOT NULL, doc_freq INTEGER NOT NULL)',
        'CREATE UNIQUE INDEX wp_fts_term_identity ON wp_fts_terms(lang,kind,term)',
        'CREATE INDEX wp_fts_empty_terms ON wp_fts_terms(doc_freq)',
        'CREATE TABLE wp_fts_postings (term_id INTEGER NOT NULL, post_id INTEGER NOT NULL, impact REAL NOT NULL, PRIMARY KEY(term_id,post_id))',
        'CREATE INDEX wp_fts_post_term_impact ON wp_fts_postings(post_id,term_id,impact)',
        'CREATE TABLE wp_fts_documents (post_id INTEGER PRIMARY KEY, primary_lang BLOB NOT NULL, content_hash BLOB, snippet_text TEXT, indexed_at INTEGER NOT NULL)',
        "CREATE TABLE wp_fts_work (job_key BLOB PRIMARY KEY, kind TEXT NOT NULL, post_id INTEGER NOT NULL DEFAULT 0, generation INTEGER NOT NULL DEFAULT 1, state TEXT NOT NULL DEFAULT 'pending', available_at INTEGER NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0, claim_token TEXT NOT NULL DEFAULT '', claimed_generation INTEGER NOT NULL DEFAULT 0, claim_expires_at INTEGER NOT NULL DEFAULT 0, cursor_post_id INTEGER NOT NULL DEFAULT 0, scope_coverage TEXT NOT NULL DEFAULT '', scope_incarnation BLOB NOT NULL DEFAULT '', scope_subject_type TEXT NOT NULL DEFAULT '', scope_subject_id INTEGER NOT NULL DEFAULT 0, payload TEXT, last_error_code TEXT NOT NULL DEFAULT '', last_error_at INTEGER NOT NULL DEFAULT 0)",
        'CREATE INDEX wp_fts_work_ready ON wp_fts_work(kind,state,available_at,post_id,job_key)',
        'CREATE INDEX wp_fts_work_claim_token ON wp_fts_work(claim_token,post_id)',
        'CREATE INDEX wp_fts_work_kind_job ON wp_fts_work(kind,job_key)',
        'CREATE INDEX wp_fts_work_dirty ON wp_fts_work(post_id,kind)',
        'CREATE INDEX wp_fts_work_scope_subject ON wp_fts_work(kind,scope_coverage,scope_subject_type,scope_subject_id)',
        'CREATE INDEX wp_fts_work_recoverable ON wp_fts_work(kind,state,claim_expires_at,available_at,post_id,job_key)',
    ];
    foreach ($statements as $statement) {
        $wpdb->query($statement);
    }
    (new WP_FTS_Storage_Mysql($wpdb))->ensure_scope_keyset_indexes();
    foreach ([
        WP_FTS_Plugin::SCHEMA_VERSION_OPTION => (string) WP_FTS_Plugin::SCHEMA_VERSION,
        WP_FTS_Plugin::READINESS_INCARNATION_OPTION => wp_fts_v4_regression_ready_incarnation(),
        WP_FTS_Plugin::SEARCH_READY_INCARNATION_OPTION => serialize([
            'incarnation' => wp_fts_v4_regression_ready_incarnation(),
            'profile_hash' => wp_fts_v4_regression_ready_profile_hash(),
        ]),
    ] as $name => $value) {
        $wpdb->execute('INSERT INTO wp_options (option_name,option_value) VALUES (?,?)', [$name, $value]);
    }
    $wpdb->execute(
        'INSERT INTO wp_fts_work (job_key,kind,post_id,generation,state,payload) VALUES (?,?,?,?,?,?)',
        [WP_FTS_Index_Queue::SEARCH_EPOCH_JOB_KEY, 'meta', 0, 1, 'meta', wp_fts_v4_regression_epoch_incarnation()]
    );
}

/** Seed one canonical and indexed row so visibility is tested through real SQL. */
function wp_fts_v4_regression_add_post(
    WP_FTS_V4_Regression_SQLite_WPDB $wpdb,
    int $postId,
    string $date,
    string $postType = 'post',
    string $postStatus = 'publish'
): void
{
    $wpdb->execute(
        'INSERT INTO wp_posts (ID,post_type,post_status,post_password,post_date_gmt,post_title,post_content,post_excerpt) VALUES (?,?,?,?,?,?,?,?)',
        [$postId, $postType, $postStatus, '', $date, "Regression {$postId}", '', '']
    );
    $wpdb->execute(
        'INSERT INTO wp_fts_documents (post_id,primary_lang,content_hash,snippet_text,indexed_at) VALUES (?,?,?,?,?)',
        [$postId, 'en', hash('sha256', (string) $postId), '', 1]
    );
}

/** Seed only canonical source content for cases that exercise preparation first. */
function wp_fts_v4_regression_add_source_post(
    WP_FTS_V4_Regression_SQLite_WPDB $wpdb,
    int $postId,
    string $content,
    string $languageProbe,
    string $postType = 'post',
    string $postStatus = 'publish',
    string $date = '2026-07-01 00:00:00'
): void {
    $wpdb->execute(
        'INSERT INTO wp_posts (ID,post_type,post_status,post_password,post_date_gmt,post_title,post_content,post_excerpt) VALUES (?,?,?,?,?,?,?,?)',
        [$postId, $postType, $postStatus, '', $date, $languageProbe, $content, '']
    );
}

/** @param array<int,float> $impactsByPost */
function wp_fts_v4_regression_add_term(WP_FTS_V4_Regression_SQLite_WPDB $wpdb, string $term, array $impactsByPost, string $lang = 'en'): void
{
    wp_fts_v6_regression_add_dictionary_identity($wpdb, $term, $impactsByPost, $lang, 0);
}

/** @param array<int,float> $impactsByPost */
function wp_fts_v6_regression_add_surface(WP_FTS_V4_Regression_SQLite_WPDB $wpdb, string $term, array $impactsByPost, string $lang = 'en'): void
{
    wp_fts_v6_regression_add_dictionary_identity($wpdb, $term, $impactsByPost, $lang, 1);
}

/** @param array<int,float> $impactsByPost */
function wp_fts_v6_regression_add_dictionary_identity(
    WP_FTS_V4_Regression_SQLite_WPDB $wpdb,
    string $term,
    array $impactsByPost,
    string $lang,
    int $kind
): void {
    $lang = WP_FTS_TermNamespace::canonicalize_lang($lang, 'und');
    $wpdb->execute(
        'INSERT INTO wp_fts_terms (lang,kind,term,doc_freq) VALUES (?,?,?,?)',
        [$lang, $kind, $term, count($impactsByPost)],
        [PDO::PARAM_LOB, PDO::PARAM_INT, PDO::PARAM_LOB, PDO::PARAM_INT]
    );
    $termId = (int) $wpdb->dbh->lastInsertId();
    foreach ($impactsByPost as $postId => $impact) {
        $wpdb->execute(
            'INSERT INTO wp_fts_postings (term_id,post_id,impact) VALUES (?,?,?)',
            [$termId, (int) $postId, (float) $impact]
        );
    }
}

/** @return array<int,array<int,array{key:string,rank:int}>> */
function wp_fts_v4_regression_groups(string $term): array
{
    return [[['key' => WP_FTS_TermNamespace::namespace_term('en', $term), 'rank' => 0]]];
}

/** @return array<string,mixed> */
function wp_fts_v4_regression_search_options(int $pageSize = 2): array
{
    return [
        'mode' => 'OR',
        'page_size' => $pageSize,
        'limit' => $pageSize + 1,
        'post_types' => ['post'],
        'post_statuses' => ['publish'],
        'query_lang' => 'en',
        'prefix_matching' => false,
        'search_ready_incarnation' => wp_fts_v4_regression_ready_incarnation(),
        'search_ready_profile_hash' => wp_fts_v4_regression_ready_profile_hash(),
    ];
}

/** Keep the published readiness generation distinct from the epoch payload. */
function wp_fts_v4_regression_ready_incarnation(): string
{
    return str_repeat('a', 32);
}

/** Provide a stable analyzer-profile binding for cursor and readiness checks. */
function wp_fts_v4_regression_ready_profile_hash(): string
{
    return str_repeat('b', 40);
}

/** Deliberately separate the epoch incarnation from the publication token. */
function wp_fts_v4_regression_epoch_incarnation(): string
{
    return str_repeat('c', 32);
}

/** Recover the latest rank statement for structural regression assertions. */
function wp_fts_v4_regression_last_rank_sql(WP_FTS_V4_Regression_SQLite_WPDB $wpdb): string
{
    for ($index = count($wpdb->queries) - 1; $index >= 0; $index--) {
        if (str_contains($wpdb->queries[$index], '/* wp_fts:rank */')) {
            return $wpdb->queries[$index];
        }
    }
    return '';
}

/** Recover the latest plan statement without depending on incidental query order. */
function wp_fts_v4_regression_last_plan_sql(WP_FTS_V4_Regression_SQLite_WPDB $wpdb): string
{
    for ($index = count($wpdb->queries) - 1; $index >= 0; $index--) {
        if (str_contains($wpdb->queries[$index], '/* wp_fts:plan */')) {
            return $wpdb->queries[$index];
        }
    }
    return '';
}

/** Require a cursor-specific rejection rather than accepting an unrelated error. */
function wp_fts_v4_regression_assert_invalid_cursor(callable $operation, string $message): void
{
    $rejected = false;
    try {
        $operation();
    } catch (InvalidArgumentException $error) {
        $rejected = str_contains(strtolower($error->getMessage()), 'cursor');
    }
    assert_true($rejected, $message);
}

/** @return array<string,mixed> */
function wp_fts_v4_regression_work_row(WP_FTS_V4_Regression_SQLite_WPDB $wpdb, int $postId): array
{
    $statement = $wpdb->dbh->prepare('SELECT * FROM wp_fts_work WHERE post_id = ?');
    $statement->execute([$postId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
}

/**
 * Minimal real-SQL wpdb adapter for relational query semantics.
 *
 * The class name deliberately includes SQLite so production storage selects
 * its portable SQL branches. Unlike the broad fake wpdb, this executes the
 * generated planning, ranking, cursor, and queue statements transactionally.
 */
final class WP_FTS_V4_Regression_SQLite_WPDB
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $terms = 'wp_terms';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $term_relationships = 'wp_term_relationships';
    public string $last_error = '';
    public PDO $dbh;
    /** @var string[] */
    public array $queries = [];
    /** @var null|callable(string):void */
    public mixed $readQueryObserver = null;
    /** @var array<int,int> Test-only rank transport sizes keyed by document id. */
    public array $rankCanonicalByteOverrides = [];

    /** Run each regression against an isolated transactional SQL database. */
    public function __construct()
    {
        $this->dbh = new PDO('sqlite::memory:');
        $this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->dbh->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
    }

    /** Emulate wpdb placeholders while respecting quoted percent sequences. */
    public function prepare(string $sql, mixed ...$args): string
    {
        $prepared = '';
        $argument = 0;
        $length = strlen($sql);
        $quote = null;
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $sql[$offset];
            if ($quote !== null) {
                $prepared .= $character;
                if ($character === $quote) {
                    if ($offset + 1 < $length && $sql[$offset + 1] === $quote) {
                        $prepared .= $sql[++$offset];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                $prepared .= $character;
                continue;
            }
            if ($character !== '%' || $offset + 1 >= $length) {
                $prepared .= $character;
                continue;
            }
            $type = $sql[$offset + 1];
            if ($type === '%') {
                $prepared .= '%';
                $offset++;
                continue;
            }
            if (!in_array($type, ['d', 'f', 's'], true) || !array_key_exists($argument, $args)) {
                $prepared .= $character;
                continue;
            }
            $value = $args[$argument++];
            $prepared .= match ($type) {
                'd' => (string) (int) $value,
                'f' => sprintf('%.17g', (float) $value),
                's' => $this->dbh->quote((string) $value),
            };
            $offset++;
        }
        if ($argument !== count($args)) {
            throw new RuntimeException('SQLite regression wpdb received unused prepare arguments.');
        }

        return $prepared;
    }

    /** Translate only the required dialect subset and retain executed SQL. */
    public function query(mixed $statement): int|false
    {
        $sql = $this->portable_sql((string) $statement);
        $this->queries[] = $sql;
        $this->last_error = '';
        try {
            return $this->dbh->exec($sql);
        } catch (Throwable $error) {
            $this->last_error = $error->getMessage();
            return false;
        }
    }

    /** @return object[] */
    public function get_results(mixed $statement): array
    {
        $sql = (string) $statement;
        $this->queries[] = $sql;
        $this->last_error = '';
        if (is_callable($this->readQueryObserver)) {
            ($this->readQueryObserver)($sql);
        }
        try {
            $query = $this->dbh->query($sql);
            $rows = $query === false ? [] : $query->fetchAll(PDO::FETCH_OBJ);
            if (str_contains($sql, '/* wp_fts:rank */') && $this->rankCanonicalByteOverrides !== []) {
                foreach ($rows as $row) {
                    $docId = max(0, (int) ($row->doc_id ?? 0));
                    if (isset($this->rankCanonicalByteOverrides[$docId])) {
                        $row->canonical_post_bytes = $this->rankCanonicalByteOverrides[$docId];
                    }
                }
            }
            return $rows;
        } catch (Throwable $error) {
            $this->last_error = $error->getMessage();
            return [];
        }
    }

    /** Execute scalar reads through the observer used by statement-count tests. */
    public function get_var(mixed $statement): mixed
    {
        $sql = (string) $statement;
        $this->queries[] = $sql;
        $this->last_error = '';
        if (is_callable($this->readQueryObserver)) {
            ($this->readQueryObserver)($sql);
        }
        try {
            $query = $this->dbh->query($sql);
            return $query === false ? null : $query->fetchColumn();
        } catch (Throwable $error) {
            $this->last_error = $error->getMessage();
            return null;
        }
    }

    /** Preserve wpdb's first-row shape while sharing the observed read path. */
    public function get_row(mixed $statement): ?object
    {
        $rows = $this->get_results($statement);

        return $rows[0] ?? null;
    }

    /** @return array<int,mixed> */
    public function get_col(mixed $statement): array
    {
        $sql = (string) $statement;
        $this->queries[] = $sql;
        $this->last_error = '';
        if (is_callable($this->readQueryObserver)) {
            ($this->readQueryObserver)($sql);
        }
        try {
            $query = $this->dbh->query($sql);
            return $query === false ? [] : $query->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $error) {
            $this->last_error = $error->getMessage();
            return [];
        }
    }

    /** @param array<int,mixed> $args @param array<int,int> $types */
    public function execute(string $sql, array $args, array $types = []): void
    {
        $statement = $this->dbh->prepare($sql);
        foreach ($args as $index => $value) {
            $type = $types[$index] ?? match (true) {
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($index + 1, $value, $type);
        }
        $statement->execute();
    }

    /** Map the narrow production MySQL forms exercised here onto SQLite syntax. */
    private function portable_sql(string $sql): string
    {
        if ($sql === 'START TRANSACTION') {
            return 'BEGIN';
        }

        $sql = str_replace('UPDATE wp_fts_terms t SET', 'UPDATE wp_fts_terms AS t SET', $sql);
        if (!str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
            return $sql;
        }

        if (str_starts_with($sql, 'INSERT INTO wp_fts_work')) {
            return str_replace(
                [
                    'ON DUPLICATE KEY UPDATE',
                    'IF(',
                    'VALUES(kind)',
                    'VALUES(state)',
                    'VALUES(available_at)',
                    'VALUES(claim_token)',
                    'VALUES(scope_coverage)',
                    'VALUES(scope_incarnation)',
                    'VALUES(scope_subject_type)',
                    'VALUES(scope_subject_id)',
                    'VALUES(payload)',
                ],
                [
                    'ON CONFLICT(job_key) DO UPDATE SET',
                    'IIF(',
                    'excluded.kind',
                    'excluded.state',
                    'excluded.available_at',
                    'excluded.claim_token',
                    'excluded.scope_coverage',
                    'excluded.scope_incarnation',
                    'excluded.scope_subject_type',
                    'excluded.scope_subject_id',
                    'excluded.payload',
                ],
                $sql
            );
        }
        if (str_starts_with($sql, 'INSERT INTO wp_fts_terms')) {
            return str_replace(
                'ON DUPLICATE KEY UPDATE doc_freq = doc_freq + VALUES(doc_freq)',
                'ON CONFLICT(lang,kind,term) DO UPDATE SET doc_freq = doc_freq + excluded.doc_freq',
                $sql
            );
        }
        if (str_starts_with($sql, 'INSERT INTO wp_fts_postings')) {
            return str_replace(
                'ON DUPLICATE KEY UPDATE impact = VALUES(impact)',
                'ON CONFLICT(term_id,post_id) DO UPDATE SET impact = excluded.impact',
                $sql
            );
        }
        if (str_starts_with($sql, 'INSERT INTO wp_fts_documents')) {
            $update = substr($sql, strpos($sql, 'ON DUPLICATE KEY UPDATE') + strlen('ON DUPLICATE KEY UPDATE'));
            $update = str_replace(
                ['VALUES(primary_lang)', 'VALUES(content_hash)', 'VALUES(snippet_text)', 'VALUES(indexed_at)'],
                ['excluded.primary_lang', 'excluded.content_hash', 'excluded.snippet_text', 'excluded.indexed_at'],
                $update
            );
            return substr($sql, 0, strpos($sql, 'ON DUPLICATE KEY UPDATE'))
                . 'ON CONFLICT(post_id) DO UPDATE SET' . $update;
        }

        throw new RuntimeException('SQLite regression adapter received an unknown MySQL UPSERT.');
    }
}

test_case_with_pdo_sqlite_fixture('SQLite regression adapter translates nested MySQL IF work expressions', function (): void {
    $wpdb = new WP_FTS_V4_Regression_SQLite_WPDB();
    $portableSql = new ReflectionMethod($wpdb, 'portable_sql');
    $translated = (string) $portableSql->invoke(
        $wpdb,
        "INSERT INTO wp_fts_work (job_key,kind,state,available_at) VALUES ('post:1','post','ready',0) "
            . "ON DUPLICATE KEY UPDATE available_at = IF(kind = 'meta', 0, IF(state = 'fenced', available_at, VALUES(available_at)))"
    );

    assert_contains('ON CONFLICT(job_key) DO UPDATE SET', $translated, 'the focused fixture should retain its SQLite UPSERT translation');
    assert_same(2, substr_count($translated, 'IIF('), 'both nested MySQL IF expressions should use SQLite IIF');
    assert_same(0, substr_count($translated, 'IF(') - substr_count($translated, 'IIF('), 'no standalone MySQL IF expression should survive translation');
});
