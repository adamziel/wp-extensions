<?php
declare(strict_types=1);

/** Capture the boundary failure while letting assertions inspect its typed reason. */
function psic_caught(callable $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $error) {
        return $error;
    }

    return null;
}

/** Invoke one plugin boundary directly without bootstrapping an HTTP request. */
function psic_plugin_private(string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod(WP_FTS_Plugin::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(null, ...$args);
}

/** Invoke one CLI normalization boundary without changing its production visibility. */
function psic_cli_private(WP_FTS_WPCLI_Command $command, string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod(WP_FTS_WPCLI_Command::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($command, ...$args);
}

/** Reach presentation helpers directly so hostile inputs cannot be hidden by search. */
function psic_searcher_private(WP_FTS_Searcher $searcher, string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod(WP_FTS_Searcher::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($searcher, ...$args);
}

/** Exercise storage input fences before any public wrapper can sanitize them. */
function psic_mysql_private(WP_FTS_Relational_Storage $storage, string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod(WP_FTS_Relational_Storage::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($storage, ...$args);
}

/** Build the exact six-field document accepted by the relational writer. */
function psic_prepared_document(array $overrides = []): array
{
    return array_replace([
        'doc_id' => 1,
        'primary_lang' => 'en',
        'content_hash' => sha1('public-search-containment'),
        'snippet_text' => '',
        'term_frequencies' => [],
        'surface_frequencies' => [],
    ], $overrides);
}

test_case('set-oriented snippets use only the typed prefix surface', function (): void {
    $searcher = new WP_FTS_Searcher(new WP_FTS_Relational_Storage(new WP_FTS_Test_WPDB()), new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'default_lang' => 'en',
    ]));

    $snippet = $searcher->snippet_for_text('dog dogmatic', 'dogs', [
        'query_lang' => 'en',
        'prefix_matching' => true,
        'prefix_min_length' => 2,
        'highlight' => true,
        'snippet_length' => 100,
    ]);

    assert_contains('<mark>dog</mark>', $snippet, 'the exact dog lemma may still explain membership for typed dogs');
    assert_true(
        !str_contains($snippet, '<mark>dogmatic</mark>'),
        'the dog lemma must not become a presentation prefix when relational membership ranged only on typed dogs'
    );

    $surfaceOnly = $searcher->snippet_for_text('running', 'runni', [
        'query_lang' => 'en',
        'prefix_matching' => true,
        'prefix_min_length' => 2,
        'highlight' => true,
        'snippet_length' => 100,
    ]);
    assert_contains(
        '<mark>running</mark>',
        $surfaceOnly,
        'a typed prefix must compare with the normalized raw document surface even when exact stems differ'
    );
});

test_case('set-oriented search sends storage only key-rank candidates and supplied optional values', function (): void {
    $storage = new class implements WP_FTS_Set_Oriented_Search_Storage {
        /** @var array<int,array<int,array<string,mixed>>> */
        public array $groups = [];
        /** @var array<string,mixed> */
        public array $options = [];

        public function search_page(array $groups, array $options): array
        {
            $this->groups = $groups;
            $this->options = $options;

            return ['results' => [], 'has_more' => false, 'next_cursor' => null, 'previous_cursor' => null];
        }
    };
    $analyzer = new class {
        /** Return one fully described occurrence so projection can discard analyzer-only fields. */
        public function analyze_query_occurrences(string $_query, array $_options): array
        {
            return [[
                'term' => 'alpha',
                'lang' => 'en',
                'position' => 0,
                'rank' => 3,
                'source' => 'token',
                'surface' => 'Alpha',
                'normalized_surface' => 'alpha',
            ]];
        }
    };

    $page = (new WP_FTS_Searcher($storage, $analyzer))->search('Alpha', [
        'mode' => 'OR',
        'query_lang' => 'en',
    ]);
    assert_same([], $page['results'] ?? null, 'the projected storage call should retain an empty result page');
    assert_same([[['key' => WP_FTS_TermNamespace::namespace_term('en', 'alpha'), 'rank' => 3]]], $storage->groups, 'storage candidates should contain exactly key and rank');
    foreach (['term', 'lang', 'position', 'source', 'surface', 'normalized_surface'] as $analyzerField) {
        assert_true(!array_key_exists($analyzerField, $storage->groups[0][0] ?? []), "storage candidates must omit analyzer-only {$analyzerField}");
    }
    foreach (['cursor', 'direction', 'date_after', 'date_before'] as $optionalKey) {
        assert_true(!array_key_exists($optionalKey, $storage->options), "storage options must omit absent {$optionalKey}");
    }
});

test_case('quality lexical streaming rejects oversized runs during construction', function (): void {
    $boundary = iterator_to_array(WP_FTS_Html_Text_Stream::visible_word_stream(str_repeat('a', 4096)), false);
    assert_same(4096, strlen((string) ($boundary[0]['text'] ?? '')), 'the exact 4 KiB lexical-run boundary should remain accepted');

    $oneByteOver = psic_caught(static fn(): array => iterator_to_array(
        WP_FTS_Html_Text_Stream::visible_word_stream(str_repeat('a', 4097)),
        false
    ));
    assert_true($oneByteOver instanceof WP_FTS_Analysis_Limit_Exceeded, 'the 4,097th lexical byte should fail while the run is being extended');
    assert_same('lexical_run_bytes', $oneByteOver instanceof WP_FTS_Analysis_Limit_Exceeded ? $oneByteOver->reason_code : null, 'lexical streaming should use the shared run-limit reason');

    $started = microtime(true);
    $hostile = psic_caught(static fn(): array => iterator_to_array(
        WP_FTS_Html_Text_Stream::visible_word_stream(str_repeat('z', WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES)),
        false
    ));
    assert_true($hostile instanceof WP_FTS_Analysis_Limit_Exceeded, 'a near-2 MiB contiguous run should stop at 4 KiB instead of materializing the complete token');
    assert_true(microtime(true) - $started < 1.0, 'a near-2 MiB contiguous run should reject in bounded time under the 128 MiB test worker');
});

test_case('quality public search containment rejects custom analyzer expansion before storage', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $analysis = [];
    for ($index = 0; $index < 10000; $index++) {
        $analysis[] = ['term' => 'term', 'lang' => 'en'];
    }
    $analyzer = new class($analysis) {
        public int $calls = 0;

        /** Retain an over-wide analyzer result without regenerating it per call. */
        public function __construct(private array $analysis)
        {
        }

        /** Return the hostile cardinality in one call so search must reject before SQL. */
        public function analyze_query_occurrences(string $query, array $options): array
        {
            $this->calls++;
            return $this->analysis;
        }
    };
    $searcher = new WP_FTS_Searcher(new WP_FTS_Relational_Storage($fake), $analyzer);
    $before = $fake->num_queries;
    $error = psic_caught(static fn(): array => $searcher->search('term', ['prefix_matching' => false]));

    assert_true($error instanceof WP_FTS_Search_Budget_Exceeded, 'a custom analyzer may not expand one query beyond the fixed alternative envelope');
    assert_same('analyzer occurrences', $error instanceof WP_FTS_Search_Budget_Exceeded ? $error->budget() : null, 'analyzer cardinality should have a stable typed budget');
    assert_same(1, $analyzer->calls, 'the set-oriented path should invoke the custom analyzer exactly once');
    assert_same($before, $fake->num_queries, 'over-wide custom analyzer output should reach no storage query');

    foreach ([
        'term' => ['term' => str_repeat('t', WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES + 1), 'lang' => 'en'],
        'language' => ['term' => 'term', 'lang' => str_repeat('l', 65)],
        'surface' => ['term' => 'term', 'lang' => 'en', 'normalized_surface' => str_repeat('s', 4097)],
    ] as $label => $occurrence) {
        $boundedAnalyzer = new class($occurrence) {
            /** Configure one independently oversized analyzer field. */
            public function __construct(private array $occurrence)
            {
            }

            /** Return one hostile row so scalar validation, not cardinality, rejects it. */
            public function analyze_query_occurrences(string $query, array $options): array
            {
                return [$this->occurrence];
            }
        };
        $boundedSearcher = new WP_FTS_Searcher(new WP_FTS_Relational_Storage($fake), $boundedAnalyzer);
        $fieldError = psic_caught(static fn(): array => $boundedSearcher->search('term', ['prefix_matching' => false]));
        assert_true(
            $fieldError instanceof InvalidArgumentException,
            "an oversized analyzer {$label} should violate the exact occurrence contract"
        );
    }

    $modeAnalyzer = new class {
        public int $calls = 0;

        /** Count valid calls while producing an intentionally empty search plan. */
        public function analyze_query_occurrences(string $query, array $options): array
        {
            $this->calls++;
            return [];
        }
    };
    $modeSearcher = new WP_FTS_Searcher(new WP_FTS_Relational_Storage($fake), $modeAnalyzer);

    foreach ([
        ['unsupported' => true],
        [0 => 'numeric option key'],
        ['include_snippets' => true],
        ['mode' => 'OR'],
        ['query_lang' => null],
        ['query_lang' => ''],
        ['default_lang' => false],
        ['result_lang' => []],
        ['prefix_matching' => 1],
        ['highlight' => 'true'],
        ['prefix_min_length' => 1],
        ['prefix_min_length' => 256],
        ['snippet_length' => 39],
        ['snippet_length' => 501],
    ] as $snippetOptions) {
        $snippetOptionError = psic_caught(static fn(): string => $modeSearcher->snippet_for_text(
            'bounded source',
            'term',
            $snippetOptions
        ));
        assert_true($snippetOptionError instanceof InvalidArgumentException, 'the public snippet API should reject unsupported or malformed exact options before analyzer work');
    }
    assert_same(0, $modeAnalyzer->calls, 'invalid exact snippet options should be rejected before analyzer work');
    assert_same($before, $fake->num_queries, 'invalid public snippet options should execute no SQL');

    $modeError = psic_caught(static fn(): array => $modeSearcher->search('term', ['mode' => str_repeat('O', 4097)]));
    assert_true($modeError instanceof InvalidArgumentException, 'an oversized public mode should be rejected before strtoupper normalization');
    assert_same(0, $modeAnalyzer->calls, 'an invalid mode should be rejected before analyzer work');
    foreach ([
        ['mode' => 'or'],
        ['mode' => 'and'],
        ['mode' => ' OR '],
        ['mode' => 'XOR'],
        ['mode' => 1],
        ['cursor' => str_repeat('c', 2049)],
        ['prefix_matching' => str_repeat('y', 17)],
        ['date_after' => str_repeat('d', 65)],
        ['limit' => str_repeat('1', 65)],
        ['result_lang' => 'en'],
        ['max_query_terms' => 12],
        ['now_gmt' => '2026-01-02T00:00:00'],
        ['request_budget_guard' => static fn(): bool => true],
        ['unsupported_option' => true],
        ['_empty_search_scope' => true],
        [0 => 'numeric option key'],
        ['direction' => 'after'],
        ['cursor' => 'signed-token', 'direction' => 'AFTER'],
        ['cursor' => '   '],
        ['post_types' => array_fill(0, 33, 'post')],
        ['post_types' => ['post' => true]],
        ['post_types' => [['post']]],
        ['post_types' => ['']],
        ['post_types' => [false]],
        ['post_types' => ['post', '']],
        ['post_types' => 'post,'],
        ['post_statuses' => ['   ']],
        array_fill(0, 100000, 'unknown'),
    ] as $options) {
        $optionError = psic_caught(static fn(): array => $modeSearcher->search('term', $options));
        assert_true(
            $optionError instanceof InvalidArgumentException,
            'set-oriented public options should reject keys: ' . implode(', ', array_map('strval', array_keys($options)))
                . '; got ' . (is_object($optionError) ? get_class($optionError) : gettype($optionError))
        );
    }
    $cursorResource = fopen('php://memory', 'rb');
    assert_true(is_resource($cursorResource), 'the hostile cursor fixture should allocate a resource');
    try {
        foreach ([["nested"], new stdClass(), $cursorResource, false, 0] as $cursorValue) {
            $optionError = psic_caught(static fn(): array => $modeSearcher->search('term', [
                'cursor' => $cursorValue,
            ]));
            assert_true(
                $optionError instanceof InvalidArgumentException,
                'a non-string cursor must be rejected before analyzer work'
            );
        }
        foreach (['prefix_matching', 'include_metadata', 'include_snippets', '_include_canonical_post_rows', 'highlight', 'explain'] as $switchKey) {
            foreach ([null, '', 'maybe', 2, -1, 1.0, NAN, INF, [], new stdClass(), $cursorResource] as $switchValue) {
                $optionError = psic_caught(static fn(): array => $modeSearcher->search('term', [
                    $switchKey => $switchValue,
                ]));
                assert_true(
                    $optionError instanceof InvalidArgumentException,
                    "a malformed {$switchKey} switch must be rejected before analyzer work"
                );
            }
        }
        foreach ([
            'limit' => [0, 51, false, 1.0, '01', '1.0', 'nonsense', NAN, INF, [], $cursorResource],
            'prefix_min_length' => [-1, 0, 1, 13, false, 1.0, '01', '1.0', 'nonsense', NAN, INF, [], $cursorResource],
            'snippet_length' => [0, 39, 501, false, 1.0, '01', '1.0', 'nonsense', NAN, INF, [], $cursorResource],
        ] as $numericKey => $numericValues) {
            foreach ($numericValues as $numericValue) {
                $optionError = psic_caught(static fn(): array => $modeSearcher->search('term', [
                    $numericKey => $numericValue,
                ]));
                assert_true(
                    $optionError instanceof InvalidArgumentException,
                    "a malformed {$numericKey} integer must be rejected before analyzer work"
                );
            }
        }
        foreach ([
            'recency_boost_strength' => [false, -0.1, 2.1, '', '01', '1e0', 'nonsense', NAN, INF, [], $cursorResource],
            'recency_boost_half_life_days' => [false, 0, 3651, '', '01', '1e0', 'nonsense', NAN, INF, [], $cursorResource],
        ] as $numericKey => $numericValues) {
            foreach ($numericValues as $numericValue) {
                $optionError = psic_caught(static fn(): array => $modeSearcher->search('term', [
                    $numericKey => $numericValue,
                ]));
                assert_true(
                    $optionError instanceof InvalidArgumentException,
                    "a malformed {$numericKey} number must be rejected before analyzer work"
                );
            }
        }
        foreach (['date_after', 'date_before'] as $dateKey) {
            foreach ([null, false, 0, ' ', '2026-02-30', 'tomorrow', [], new stdClass(), $cursorResource] as $dateValue) {
                $optionError = psic_caught(static fn(): array => $modeSearcher->search('term', [
                    $dateKey => $dateValue,
                ]));
                assert_true(
                    $optionError instanceof InvalidArgumentException,
                    "a malformed {$dateKey} date must be rejected before analyzer work"
                );
            }
        }
    } finally {
        fclose($cursorResource);
    }
    assert_same(0, $modeAnalyzer->calls, 'cursor, switch, date, numeric, filter, and unsupported-option failures should all precede analyzer work');
    assert_same($before, $fake->num_queries, 'all analyzer-bound failures should leave storage untouched');

    assert_true(
        psic_caught(
            static fn(): mixed => psic_searcher_private(
                $modeSearcher,
                'set_oriented_cursor_value',
                ' signed.cursor.bytes '
            )
        ) instanceof InvalidArgumentException,
        'cursor validation must reject padded signed bytes instead of changing their identity'
    );
    $boundaryPage = $modeSearcher->search('term', [
        'query_lang' => 'en_US',
        'prefix_matching' => true,
        'include_snippets' => false,
        'limit' => 50,
        'prefix_min_length' => 12,
        'snippet_length' => 500,
        'recency_boost_strength' => 2.0,
        'recency_boost_half_life_days' => 3650.0,
        'date_after' => '2026-01-01',
    ]);
    assert_same([], $boundaryPage['results'] ?? null, 'the exact strict option boundaries should retain an analyzer-empty first page');
    assert_same(1, $modeAnalyzer->calls, 'one valid strict-boundary request should invoke the analyzer exactly once');
    assert_same($before, $fake->num_queries, 'an analyzer-empty strict-boundary page should not execute storage SQL');

    $snippetAnalysis = [];
    for ($index = 0; $index < 10000; $index++) {
        $snippetAnalysis[] = ['term' => 'term', 'surface' => 'term', 'lang' => 'en'];
    }
    $snippetAnalyzer = new class($snippetAnalysis) {
        public int $calls = 0;

        /** Retain the oversized snippet analysis used by both internal and public paths. */
        public function __construct(private array $analysis)
        {
        }

        /** Force highlighting to cap analyzer output before scanning source text. */
        public function analyze_query_occurrences(string $query, array $options): array
        {
            $this->calls++;
            return $this->analysis;
        }
    };
    $snippetSearcher = new WP_FTS_Searcher(new WP_FTS_Relational_Storage($fake), $snippetAnalyzer);
    $key = WP_FTS_TermNamespace::namespace_term('en', 'term');
    $groups = [[['key' => $key, 'lang' => 'en', 'term' => 'term', 'rank' => 0]]];
    $surfaces = psic_searcher_private(
        $snippetSearcher,
        'snippet_matching_surfaces',
        'term snippet source',
        [$key => true],
        ['prefix_matching' => false],
        $groups,
        'en',
        'en'
    );
    assert_same([], $surfaces, 'presentation highlighting should discard a custom analyzer array above its 3,072-occurrence cap');
    assert_same(1, $snippetAnalyzer->calls, 'hostile snippet analysis should stop after one capped analyzer call');
    assert_same($before, $fake->num_queries, 'snippet analyzer rejection should not add storage work');

    $publicSnippetError = psic_caught(static fn(): string => $snippetSearcher->snippet_for_text(
        str_repeat('source ', 100000),
        'term',
        ['query_lang' => 'en', 'highlight' => true, 'snippet_length' => 500]
    ));
    assert_true($publicSnippetError instanceof WP_FTS_Search_Budget_Exceeded, 'the public snippet API should reject hostile query-analyzer expansion before source processing');
    assert_same(2, $snippetAnalyzer->calls, 'the public snippet path should make one capped query-analyzer call');
    assert_same($before, $fake->num_queries, 'the bounded public snippet path should execute no SQL');
});

test_case('quality public search containment keeps oversized WordPress cursors FTS-owned and fail closed', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = array_replace(
        WP_FTS_Plugin::default_settings(),
        ['replace_frontend_search' => true]
    );

    try {
        $query = new WP_FTS_Test_Query([
            's' => 'bounded cursor query',
            'posts_per_page' => 10,
            'paged' => 2,
            'wp_fts_cursor' => str_repeat('c', 2049),
        ]);
        $before = $fake->num_queries;
        WP_FTS_Plugin::prepare_frontend_search_query($query);
        assert_true(!empty($query->query_vars['wp_fts_search_candidate']), 'an overlong cursor should remain owned so core LIKE cannot take over');
        assert_same([], WP_FTS_Plugin::replace_frontend_search_posts(null, $query), 'an overlong cursor should fail closed at the FTS adapter');
        assert_true(
            in_array($query->query_vars['wp_fts_search_unavailable'] ?? null, ['runtime_failure', 'unavailable_or_unbounded_page'], true),
            'the failed cursor request should expose a closed replacement state'
        );
        assert_same($before, $fake->num_queries, 'an overlong WordPress cursor should execute no SQL');

        foreach ([
            ['lang' => str_repeat('l', 65)],
            ['cursor' => str_repeat('c', 2049)],
            ['direction' => str_repeat('d', 9)],
            ['mode' => str_repeat('m', 9)],
            ['prefix_matching' => str_repeat('y', 17)],
            ['date_after' => str_repeat('d', 65)],
            ['limit' => str_repeat('1', 65)],
        ] as $options) {
            $error = psic_caught(static fn(): array => WP_FTS_Plugin::search_page('bounded options query', $options));
            assert_true($error instanceof InvalidArgumentException, 'oversized public search option scalars should be rejected before readiness or storage work');
        }
        assert_same($before, $fake->num_queries, 'public option byte rejections should execute no SQL');

        foreach ([str_repeat('b', 4097), ' after ', 'AFTER', 1, null] as $direction) {
            $error = psic_caught(
                static fn(): mixed => psic_plugin_private('search_cursor_direction', $direction)
            );
            assert_true($error instanceof InvalidArgumentException, 'the WordPress direction adapter should reject every non-exact value');
        }
        assert_same('after', psic_plugin_private('search_cursor_direction', 'after'), 'the exact forward direction should remain accepted');
        assert_same('before', psic_plugin_private('search_cursor_direction', 'before'), 'the exact reverse direction should remain accepted');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality WordPress search facade rejects every untyped unsupported or conflicting option before SQL', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    try {
        $invalidOptions = [
            ['post_statues' => ['publish']],
            ['post_type' => ['post']],
            ['post_status' => ['publish']],
            ['_empty_search_scope' => true],
            ['_search_ready_incarnation' => str_repeat('a', 32)],
            ['phrase' => false],
            ['explain' => false],
            ['debug' => false],
            [0 => 'numeric key'],
            array_fill(0, 100000, 'unknown'),
            ['mode' => false],
            ['mode' => 'XOR'],
            ['offset' => 0],
            ['limit' => false],
            ['limit' => 1.0],
            ['limit' => '01'],
            ['limit' => 51],
            ['lang' => null],
            ['lang' => false],
            ['lang' => ''],
            ['lang' => '   '],
            ['cursor' => null],
            ['cursor' => '   '],
            ['cursor' => ' signed '],
            ['cursor' => false],
            ['cursor' => str_repeat('c', 2049)],
            ['direction' => 'after'],
            ['cursor' => 'signed', 'direction' => 'AFTER'],
            ['prefix_matching' => 'maybe'],
            ['include_snippets' => 'no'],
            ['post_types' => null],
            ['post_types' => 'post,page'],
            ['post_types' => ['post', '']],
            ['post_statuses' => 'publish'],
            ['post_statuses' => [false]],
            ['recency_boost_strength' => '2.0'],
            ['recency_boost_half_life_days' => '3650'],
            ['date_after' => null],
            ['date_after' => '2026-02-30'],
            ['date_before' => ' tomorrow '],
            ['snippet_length' => 39],
            ['max_query_terms' => 12],
            ['now_gmt' => '2026-01-01T00:00:00'],
            ['request_budget_guard' => static fn(): bool => true],
        ];
        $before = $fake->num_queries;
        foreach ($invalidOptions as $options) {
            $error = psic_caught(static fn(): array => WP_FTS_Plugin::search_page('typed boundary', $options));
            assert_true(
                $error instanceof InvalidArgumentException,
                'the WordPress facade must reject malformed options before readiness or storage: '
                    . json_encode($options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }
        assert_same($before, $fake->num_queries, 'all exact public option-boundary failures should execute zero SQL');

        $normalized = psic_plugin_private('normalize_public_search_options', [
            'mode' => 'AND',
            'limit' => 50,
            'cursor' => 'exact.signed.bytes',
            'direction' => 'after',
            'prefix_matching' => true,
            'include_snippets' => false,
            'post_types' => ['post', 'page'],
            'post_statuses' => ['publish'],
            'recency_boost_strength' => 2.0,
            'recency_boost_half_life_days' => 3650.0,
        ]);
        assert_same('AND', $normalized['mode'] ?? null, 'mode should canonicalize without permissive casting');
        assert_same(50, $normalized['limit'] ?? null, 'the exact page boundary should normalize to an integer');
        assert_same('exact.signed.bytes', $normalized['cursor'] ?? null, 'cursor validation must preserve one canonical signed value');
        assert_same('after', $normalized['direction'] ?? null, 'cursor direction should remain explicit');
        assert_same(true, $normalized['prefix_matching'] ?? null, 'prefix matching should normalize once');
        assert_same(false, $normalized['include_snippets'] ?? null, 'snippet selection should normalize once');
        assert_same(['page', 'post'], $normalized['post_types'] ?? null, 'post types should become one sorted canonical list');
        assert_float_near(2.0, (float) ($normalized['recency_boost_strength'] ?? -1), 'recency strength should normalize to the canonical option');
        assert_float_near(3650.0, (float) ($normalized['recency_boost_half_life_days'] ?? -1), 'recency half-life should normalize to the canonical option');
        assert_same($before, $fake->num_queries, 'valid option normalization itself should execute zero SQL');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('quality empty WordPress scopes authenticate cursors on facade and adapter zero-SQL exits', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $oldPostTypes = array_map(static fn(object $postType): object => clone $postType, $GLOBALS['wp_fts_test_post_types']);
    $GLOBALS['wp_fts_test_post_types']['post']->exclude_from_search = true;
    $GLOBALS['wp_fts_test_post_types']['page']->exclude_from_search = true;
    $GLOBALS['wp_fts_test_post_types']['attachment']->exclude_from_search = true;

    try {
        $storage = wp_fts_test_storage(false);
        $cursor = psic_mysql_private($storage, 'encode_cursor', (object) [
            'score' => 123,
            'post_date_gmt' => '2026-01-01 00:00:00',
            'doc_id' => 42,
        ], str_repeat('f', 64), '');
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        assert_true(is_string($decoded) && str_contains($decoded, '.'), 'the fixture should create a real signed storage cursor');
        $decoded[1] = $decoded[1] === 'x' ? 'y' : 'x';
        $tampered = rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=');

        $before = $fake->num_queries;
        $storage->assert_search_cursor_authenticity($cursor);
        foreach (['', '   ', str_repeat('c', 2049)] as $invalidCursor) {
            $boundError = psic_caught(static fn() => $storage->assert_search_cursor_authenticity($invalidCursor));
            assert_true($boundError instanceof InvalidArgumentException, 'direct cursor authentication must reject empty or oversized envelopes');
            assert_contains('2,048 bytes', $boundError instanceof Throwable ? $boundError->getMessage() : '', 'direct cursor authentication should enforce the shared storage cursor bound before decoding');
        }
        assert_same($before, $fake->num_queries, 'direct cursor envelope checks should execute zero SQL');

        $page = WP_FTS_Plugin::search_page('empty scope cursor', ['cursor' => $cursor]);
        assert_same([], $page['results'] ?? null, 'a valid signed cursor should retain the authorized empty page');
        assert_same($before, $fake->num_queries, 'the valid empty-scope facade page should execute zero SQL');
        $tamperedError = psic_caught(static fn(): array => WP_FTS_Plugin::search_page(
            'empty scope cursor',
            ['cursor' => $tampered]
        ));
        assert_true($tamperedError instanceof InvalidArgumentException, 'the empty-scope facade must reject a forged cursor');
        assert_contains('signature', $tamperedError instanceof Throwable ? $tamperedError->getMessage() : '', 'the empty-scope facade should actually verify the cursor HMAC');
        assert_same($before, $fake->num_queries, 'forged empty-scope facade cursors should execute zero SQL');

        foreach (['frontend', 'admin'] as $context) {
            $query = new WP_FTS_Test_Query([
                'wp_fts_cursor' => $cursor,
                'wp_fts_cursor_direction' => 'after',
                'posts_per_page' => 10,
            ]);
            $result = psic_plugin_private(
                'search_result_page',
                $query,
                'empty adapter cursor',
                [],
                ['publish'],
                $context,
                0,
                WP_FTS_Plugin::default_settings()
            );
            assert_same([], $result['posts'] ?? null, "the shared {$context} adapter should accept an authentic cursor before its empty-scope return");

            $forgedQuery = new WP_FTS_Test_Query([
                'wp_fts_cursor' => $tampered,
                'wp_fts_cursor_direction' => 'after',
                'posts_per_page' => 10,
            ]);
            $adapterError = psic_caught(static fn(): array => psic_plugin_private(
                'search_result_page',
                $forgedQuery,
                'empty adapter cursor',
                [],
                ['publish'],
                $context,
                0,
                WP_FTS_Plugin::default_settings()
            ));
            assert_true($adapterError instanceof InvalidArgumentException, "the shared {$context} adapter must reject a forged cursor before empty bailout");
            assert_contains('signature', $adapterError instanceof Throwable ? $adapterError->getMessage() : '', "the shared {$context} adapter should verify the HMAC rather than only cursor length");
        }
        assert_same($before, $fake->num_queries, 'facade and both WordPress adapter empty-scope paths should authenticate cursors with zero SQL');
    } finally {
        $GLOBALS['wp_fts_test_post_types'] = $oldPostTypes;
        $wpdb = $oldWpdb;
    }
});

test_case('quality WP CLI search containment rejects malformed supplied strings before parsing', function (): void {
    $command = new WP_FTS_WPCLI_Command();

    foreach ([
        ['mode' => null],
        ['mode' => false],
        ['mode' => 1],
        ['mode' => []],
        ['mode' => 'or'],
        ['mode' => 'and'],
        ['mode' => ' OR '],
        ['mode' => 'XOR'],
        ['lang' => null],
        ['lang' => true],
        ['lang' => 1],
        ['lang' => []],
        ['lang' => ''],
        ['lang' => '   '],
        ['lang' => ' en'],
        ['lang' => 'en '],
        ['lang' => str_repeat('l', 65)],
        ['cursor' => null],
        ['cursor' => true],
        ['cursor' => 1],
        ['cursor' => []],
        ['cursor' => ''],
        ['cursor' => '   '],
        ['cursor' => ' padded '],
        ['cursor' => str_repeat('c', 2049)],
        ['direction' => 'after'],
        ['direction' => null, 'cursor' => 'cursor'],
        ['direction' => true, 'cursor' => 'cursor'],
        ['direction' => 1, 'cursor' => 'cursor'],
        ['direction' => [], 'cursor' => 'cursor'],
        ['direction' => 'AFTER', 'cursor' => 'cursor'],
        ['direction' => ' after ', 'cursor' => 'cursor'],
        ['direction' => str_repeat('d', 9), 'cursor' => 'cursor'],
        ['post_status' => null],
        ['post_status' => true],
        ['post_status' => 1],
        ['post_status' => []],
        ['post_status' => ''],
        ['post_status' => '   '],
        ['post_status' => ',publish'],
        ['post_status' => 'publish,'],
        ['post_status' => 'publish,,draft'],
        ['post_status' => str_repeat('s', 4097)],
        ['post_type' => null],
        ['post_type' => true],
        ['post_type' => 1],
        ['post_type' => []],
        ['post_type' => ''],
        ['post_type' => '   '],
        ['post_type' => ',post'],
        ['post_type' => 'post,'],
        ['post_type' => 'post,,page'],
        ['post_type' => implode(',', array_map(static fn(int $index): string => 'type' . $index, range(1, 33)))],
        ['post_status' => str_repeat('s', 65)],
        ['after' => null],
        ['after' => true],
        ['after' => 1],
        ['after' => []],
        ['after' => ''],
        ['after' => '   '],
        ['after' => ' 2026-01-01'],
        ['before' => '2026-01-01 '],
        ['limit' => str_repeat('1', 65)],
        ['after' => str_repeat('d', 65)],
        ['prefix_matching' => str_repeat('y', 17)],
    ] as $args) {
        $error = psic_caught(static fn(): array => psic_cli_private($command, 'search_options_from_cli_args', $args));
        assert_true($error instanceof InvalidArgumentException, 'WP-CLI should reject malformed supplied search options before normalization or CSV expansion');
    }

    $maximumLanguage = 'lll-aaaaaaaa-bbbbbbbb-cccccccc-dddddddd-eeeeeeee-ffffffff-gggggg';
    $boundary = psic_cli_private($command, 'search_options_from_cli_args', [
        'mode' => 'AND',
        'lang' => $maximumLanguage,
        'cursor' => str_repeat('c', 2048),
        'direction' => 'before',
        'after' => '2026-01-01',
        'before' => '2026-01-31',
        'post_type' => implode(',', array_map(static fn(int $index): string => 't' . $index, range(1, 32))),
    ]);
    assert_same('AND', $boundary['mode'] ?? null, 'the exact CLI AND mode should remain accepted');
    assert_same($maximumLanguage, $boundary['lang'] ?? null, 'the exact CLI language boundary should remain accepted');
    assert_same(2048, strlen((string) ($boundary['cursor'] ?? '')), 'the exact CLI cursor boundary should remain accepted');
    assert_same('before', $boundary['direction'] ?? null, 'an exact CLI direction should remain paired with its cursor');
    assert_same('2026-01-01', $boundary['date_after'] ?? null, 'an exact CLI after date should remain accepted');
    assert_same('2026-01-31', $boundary['date_before'] ?? null, 'an exact CLI before date should remain accepted');
    assert_same(32, count($boundary['post_types'] ?? []), 'the exact CLI filter cardinality boundary should remain accepted');
});

test_case('quality WP CLI search queries require exactly one native string', function (): void {
    $command = new WP_FTS_WPCLI_Command();

    foreach (['search', 'diagnose'] as $method) {
        foreach ([[], [null], [true], [1], [1.0], [[]], [new stdClass()], ['first', 'second'], [1 => 'query'], ['query' => 'value']] as $args) {
            $error = psic_caught(static fn(): mixed => $command->{$method}($args, []));
            assert_true(
                $error instanceof InvalidArgumentException,
                "wp fts {$method} should reject a missing or malformed query before plugin work"
            );
        }
    }
    assert_same('', psic_cli_private($command, 'query_arg', ['']), 'an empty native query should remain exact for the PHP search boundary to classify');
    assert_same('   ', psic_cli_private($command, 'query_arg', ['   ']), 'CLI query parsing should not trim caller bytes');
});

test_case('quality WP CLI search accepts canonical integers and explicit booleans only', function (): void {
    $command = new WP_FTS_WPCLI_Command();

    foreach ([10, '10'] as $limit) {
        $options = psic_cli_private($command, 'search_options_from_cli_args', ['limit' => $limit]);
        assert_same(10, $options['limit'] ?? null, 'CLI limit should accept native integers and canonical decimal text');
    }
    foreach ([3, '3'] as $prefixMinLength) {
        $options = psic_cli_private($command, 'search_options_from_cli_args', [
            'prefix_min_length' => $prefixMinLength,
        ]);
        assert_same(3, $options['prefix_min_length'] ?? null, 'CLI prefix minimum should accept native integers and canonical decimal text');
    }

    foreach (['1.0', '1e1', '01', ' 1 ', 1.0, 'garbage', 0, 101] as $limit) {
        $error = psic_caught(static fn(): array => psic_cli_private(
            $command,
            'search_options_from_cli_args',
            ['limit' => $limit]
        ));
        assert_true($error instanceof InvalidArgumentException, 'CLI limit should reject malformed and out-of-range values');
    }
    foreach (['3.0', '3e0', '03', ' 3 ', 3.0, 'garbage', 1, 13] as $prefixMinLength) {
        $error = psic_caught(static fn(): array => psic_cli_private(
            $command,
            'search_options_from_cli_args',
            ['prefix_min_length' => $prefixMinLength]
        ));
        assert_true($error instanceof InvalidArgumentException, 'CLI prefix minimum should reject malformed and out-of-range values');
    }

    $flagNames = ['explain', 'all', 'yes', 'enable'];
    foreach ($flagNames as $name) {
        assert_same(false, psic_cli_private($command, 'bool_flag_arg', [], $name, false), "missing --{$name} should use its false default");
        assert_same(true, psic_cli_private($command, 'bool_flag_arg', [], $name, true), "missing --{$name} should use its true default");
    }

    $booleanConsumers = [...$flagNames, 'prefix_matching', 'snippet'];
    $readBoolean = static function (string $name, mixed $value) use ($command): mixed {
        if ($name === 'prefix_matching' || $name === 'snippet') {
            $options = psic_cli_private($command, 'search_options_from_cli_args', [$name => $value]);

            return $name === 'snippet'
                ? ($options['include_snippets'] ?? null)
                : ($options['prefix_matching'] ?? null);
        }

        return psic_cli_private($command, 'bool_flag_arg', [$name => $value], $name, false);
    };
    foreach ($booleanConsumers as $name) {
        foreach ([true, 1, '1', 'true', 'TRUE', 'yes', 'YeS', 'on', 'ON'] as $value) {
            assert_same(true, $readBoolean($name, $value), "CLI {$name} should accept an explicit true value");
        }
        foreach ([false, 0, '0', 'false', 'FALSE', 'no', 'No', 'off', 'OFF'] as $value) {
            assert_same(false, $readBoolean($name, $value), "CLI {$name} should accept an explicit false value");
        }
        foreach (['maybe', null, [], ['true'], ' true ', 2, -1, 1.0, new stdClass()] as $value) {
            $booleanError = psic_caught(static fn(): mixed => $readBoolean($name, $value));
            assert_true($booleanError instanceof InvalidArgumentException, "CLI {$name} should reject non-explicit boolean input");
        }
    }
});

test_case('quality WP CLI counts IDs and time budgets accept only explicit numeric shapes', function (): void {
    $command = new WP_FTS_WPCLI_Command();

    foreach ([1, '1', 42, '42'] as $value) {
        assert_same((int) $value, psic_cli_private($command, 'positive_int_arg', $value, '--count'), 'positive CLI counts should accept native integers and canonical decimal strings');
    }
    foreach ([0, '0', 42, '42'] as $value) {
        assert_same((int) $value, psic_cli_private($command, 'non_negative_int_arg', $value, '--count'), 'nonnegative CLI counts should accept native integers and canonical decimal strings');
    }

    $malformedIntegers = [1.0, '1.0', '1e0', -1, '-1', '01', ' 1 ', '+1', 'junk', true, false, null, [], new stdClass()];
    foreach ($malformedIntegers as $value) {
        foreach (['positive_int_arg', 'non_negative_int_arg'] as $method) {
            $error = psic_caught(static fn(): mixed => psic_cli_private($command, $method, $value, '--count'));
            assert_true($error instanceof InvalidArgumentException, "{$method} should reject noncanonical CLI integer input");
        }
    }
    foreach ([0, '0'] as $value) {
        $error = psic_caught(static fn(): mixed => psic_cli_private($command, 'positive_int_arg', $value, '--count'));
        assert_true($error instanceof InvalidArgumentException, 'positive CLI counts should reject zero');
    }

    foreach ([1, 1.5, '1', '1.5'] as $value) {
        assert_same((float) $value, psic_cli_private($command, 'positive_float_arg', $value, '--time_budget'), 'manual batch time budgets should accept positive native numbers and canonical decimal strings');
    }
    foreach ([0, 0.0, '0', '0.0', -1, -0.25, '-1', '-0.25', '01', '01.5', '.5', '1.', ' 1 ', '+1', '1e2', 'junk', 'INF', 'NAN', INF, -INF, NAN, true, false, null, [], new stdClass()] as $value) {
        $error = psic_caught(static fn(): mixed => psic_cli_private($command, 'positive_float_arg', $value, '--time_budget'));
        assert_true($error instanceof InvalidArgumentException, 'manual batch time budgets should reject zero, malformed, non-finite, and nonnumeric values');
    }

    assert_same(1000, WP_FTS_Plugin::MAX_MANUAL_INDEX_BATCH_SIZE, 'the manual CLI batch boundary should remain exactly 1000 posts');
    $oversizedBatchError = psic_caught(static fn(): mixed => $command->process_batch([], ['batch_size' => 1001]));
    assert_true($oversizedBatchError instanceof InvalidArgumentException, 'wp fts process-batch should reject the first batch size above 1000 before plugin work');

    foreach ([0, '0', 1.0, '1.0', '1e0', -1, '-1', '01', ' 1 ', '+1', 'junk', true, false, null, [], new stdClass()] as $value) {
        $error = psic_caught(static fn(): mixed => $command->delete([$value], []));
        assert_true($error instanceof InvalidArgumentException, 'wp fts delete should reject every present non-positive or noncanonical document ID');
    }
});

test_case('quality WP CLI lemma imports preserve strings and reject malformed counts', function (): void {
    $command = new WP_FTS_WPCLI_Command();
    $required = [
        'source' => ' source.tsv ',
        'language' => 'qaa',
        'pack-id' => ' pack-id ',
        'version' => ' version ',
        'source-name' => ' source name ',
        'source-url' => ' source URL ',
        'license' => ' license ',
    ];
    $supplied = $required + [
        'attribution' => ' attribution ',
        'out' => ' output ',
        'license-url' => ' license URL ',
        'source-version' => ' source version ',
        'tmp-dir' => ' temporary parent ',
        'max-rows-per-file' => '17',
        'chunk-rows' => 19,
    ];
    $options = psic_cli_private($command, 'lemma_pack_import_options', $supplied);
    assert_same([
        'source' => ' source.tsv ',
        'language' => 'qaa',
        'pack_id' => ' pack-id ',
        'version' => ' version ',
        'source_name' => ' source name ',
        'source_url' => ' source URL ',
        'license' => ' license ',
        'attribution' => ' attribution ',
        'out' => ' output ',
        'license_url' => ' license URL ',
        'source_version' => ' source version ',
        'tmp_dir' => ' temporary parent ',
        'max_rows_per_file' => 17,
        'chunk_rows' => 19,
    ], $options, 'WP CLI importer parsing should preserve supplied strings byte-for-byte and parse only canonical counts');

    $defaults = psic_cli_private($command, 'lemma_pack_import_options', $required + ['out' => 'output']);
    assert_same(' source name ', $defaults['attribution'] ?? null, 'an absent attribution should default to the exact source name');
    assert_true(!array_key_exists('license_url', $defaults), 'an absent license URL should remain absent');
    assert_true(!array_key_exists('source_version', $defaults), 'an absent source version should remain absent');
    assert_true(!array_key_exists('tmp_dir', $defaults), 'an absent temporary parent should remain absent');
    assert_true(!array_key_exists('max_rows_per_file', $defaults), 'an absent rows-per-file count should remain absent');
    assert_true(!array_key_exists('chunk_rows', $defaults), 'an absent chunk count should remain absent');

    foreach (array_keys($required) as $name) {
        $candidate = $required + ['out' => 'output'];
        unset($candidate[$name]);
        $missingError = psic_caught(static fn(): array => psic_cli_private($command, 'lemma_pack_import_options', $candidate));
        assert_true($missingError instanceof RuntimeException, "a required importer --{$name} must not default when absent");

        foreach ([null, false, true, 0, 1, 1.0, [], new stdClass(), '', '   '] as $value) {
            $candidate = $required + ['out' => 'output'];
            $candidate[$name] = $value;
            $error = psic_caught(static fn(): array => psic_cli_private($command, 'lemma_pack_import_options', $candidate));
            assert_true($error instanceof RuntimeException, "a required importer --{$name} must be a supplied nonblank native string");
        }
    }

    foreach (['attribution', 'out', 'license-url', 'source-version', 'tmp-dir'] as $name) {
        foreach ([null, false, true, 0, 1, 1.0, [], new stdClass(), '', '   '] as $value) {
            $candidate = $required + ['out' => 'output'];
            $candidate[$name] = $value;
            $error = psic_caught(static fn(): array => psic_cli_private($command, 'lemma_pack_import_options', $candidate));
            assert_true($error instanceof InvalidArgumentException, "a supplied importer --{$name} must be a nonblank native string");
        }
    }

    foreach (['max-rows-per-file', 'chunk-rows'] as $name) {
        foreach ([1, '1', 42, '42'] as $value) {
            $candidate = $required + ['out' => 'output', $name => $value];
            $parsed = psic_cli_private($command, 'lemma_pack_import_options', $candidate);
            $key = str_replace('-', '_', $name);
            assert_same((int) $value, $parsed[$key] ?? null, "importer --{$name} should accept native positive integers and canonical decimal strings");
        }
        foreach ([0, '0', 1.0, '1.0', '1e0', -1, '-1', '01', ' 1 ', '+1', 'junk', true, false, null, [], new stdClass()] as $value) {
            $candidate = $required + ['out' => 'output', $name => $value];
            $error = psic_caught(static fn(): array => psic_cli_private($command, 'lemma_pack_import_options', $candidate));
            assert_true($error instanceof InvalidArgumentException, "importer --{$name} should reject non-positive or noncanonical counts");
        }
    }
});

test_case('quality persisted post type scopes cannot expand worker loops beyond 32 values', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $originalPostTypes = $GLOBALS['wp_fts_test_post_types'];

    try {
        for ($index = 1; $index <= 100; $index++) {
            $name = 'public_type_' . str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $GLOBALS['wp_fts_test_post_types'][$name] = (object) [
                'public' => true,
                'exclude_from_search' => false,
                'cap' => (object) [],
            ];
        }

        $choices = psic_plugin_private('settings_post_type_choices');
        assert_same(32, count($choices), 'the registered public post-type registry should be streamed into a fixed 32-value settings envelope');
        assert_true(in_array('post', $choices, true), 'the bounded choices should retain the default post type');
        assert_true(in_array('page', $choices, true), 'the bounded choices should retain the default page type');

        $anyScope = psic_plugin_private('wordpress_any_search_post_types');
        assert_same(33, count($anyScope), 'an over-wide WordPress any scope should stop after 32 types plus one unsupported sentinel');

        $normalizedOverflow = psic_plugin_private('normalize_string_list', array_fill(0, 10000, 'post'));
        assert_same(1, count($normalizedOverflow), 'WP_Query list normalization should reject raw cardinality in O(1) instead of traversing the request array');
        assert_true($normalizedOverflow !== ['post'], 'an over-wide WP_Query list should remain conservatively unsupported rather than silently narrowing semantics');

        assert_same(true, psic_plugin_private('constraint_value_present', array_fill(0, 65, null)), 'wide nested query constraints should conservatively bail after the fixed node envelope');
        assert_same(true, psic_plugin_private('constraint_value_present', str_repeat(' ', 1048576)), 'a megabyte constraint scalar should be treated as present before trim');

        $allowed = array_map(static fn(int $index): string => 'allowed_' . $index, range(1, 100));
        $sanitized = psic_plugin_private('sanitize_post_type_list', $allowed, $allowed);
        $expectedAllowed = array_slice($allowed, 0, 32);
        sort($expectedAllowed, SORT_STRING);
        assert_same($expectedAllowed, $sanitized, 'saved post-type values and their allow-list should both stop at 32 raw entries');

        $skipsWideValues = psic_plugin_private(
            'sanitize_post_type_list',
            [str_repeat('x', 1048576), 'post'],
            ['post']
        );
        assert_same(['post'], $skipsWideValues, 'a megabyte post-type value should be skipped before sanitize_key scans it');

        $requested = psic_plugin_private(
            'request_list_value',
            ['types' => array_merge(array_slice($allowed, 0, 32), array_fill(0, 10000, str_repeat('z', 65)))],
            'types',
            $allowed,
            []
        );
        assert_same($expectedAllowed, $requested, 'admin list parsing should stop after 32 raw values without unslashing the remaining request graph');

        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = [
            'index_post_types' => array_keys($GLOBALS['wp_fts_test_post_types']),
        ];
        $settings = psic_plugin_private('settings');
        $backfillTypes = psic_plugin_private('configured_backfill_post_types');
        assert_true(count($settings['index_post_types'] ?? []) <= 32, 'stored settings should never retain more than 32 indexed post types');
        assert_true(count($backfillTypes) <= 32, 'worker backfill scope should never expand beyond 32 post types');
    } finally {
        $GLOBALS['wp_fts_test_post_types'] = $originalPostTypes;
    }
});

test_case('quality queue public inputs reject hostile ids and payload graphs before SQL', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);
    $before = $fake->num_queries;

    assert_same(0, $queue->enqueue_many([]), 'an empty exact ID list should remain a zero-work queue request');
    foreach ([
        [1 => 1],
        ['post' => 1],
        array_fill(0, WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS + 1, 1),
        [0],
        [-1],
        ['1'],
        [1.0],
        [true],
        [false],
        [null],
        [[]],
        [new stdClass()],
    ] as $ids) {
        $error = psic_caught(static fn(): int => $queue->enqueue_many($ids));
        assert_true($error instanceof InvalidArgumentException, 'queue batches should require a bounded list of positive native integer IDs');
    }

    $claim = [
        'job_key' => 'scope:' . str_repeat('a', 64),
        'generation' => 1,
        'token' => 'claim-token',
        'scope_coverage' => WP_FTS_Index_Queue::SCOPE_COVERAGE_FILTERED,
    ];
    $scopeIdError = psic_caught(static fn(): bool => $queue->commit_scope_page(
        $claim,
        [str_repeat('1', 65)],
        1
    ));
    assert_true($scopeIdError instanceof InvalidArgumentException, 'scope page ids should be bounded before integer casts or transactions');

    $deepPayload = 'value';
    for ($depth = 0; $depth < 9; $depth++) {
        $deepPayload = ['nested' => $deepPayload];
    }
    foreach ([
        ['scope' => $deepPayload],
        ['scope' => array_fill(0, 256, null)],
        [str_repeat('k', 8193) => 'value'],
        ['scope_subject_type' => 'term_taxonomy', 'scope_subject_id' => str_repeat('1', 65)],
    ] as $payload) {
        $error = psic_caught(static fn(): mixed => $queue->enqueue_scope('bounded-scope', $payload));
        assert_true($error instanceof InvalidArgumentException, 'scope payloads should reject depth, nodes, bytes, and overlong subject scalars before JSON encoding');
    }

    $scopeKeyError = psic_caught(static fn(): mixed => $queue->enqueue_scope(str_repeat(' ', 1025)));
    assert_true($scopeKeyError instanceof InvalidArgumentException, 'scope keys should be byte-bounded before trim or hashing');

    $malformedSettlements = [
        'post claim scalar aliases' => static fn(): array => $queue->acknowledge_many([[
            'post_id' => str_repeat('1', 65),
            'generation' => 1,
            'token' => 'claim-token',
        ]]),
        'scope generation' => static fn(): bool => $queue->commit_scope_page([
            'job_key' => 'scope:bounded',
            'generation' => str_repeat('1', 65),
            'token' => 'claim-token',
        ], [], 1),
        'scope attempt counter' => static fn(): array => $queue->fail_scope([
            'job_key' => 'scope:bounded',
            'generation' => 1,
            'token' => 'claim-token',
            'attempts' => str_repeat('1', 65),
        ]),
        'post release identity' => static fn(): int => $queue->release_many([[
            'job_key' => str_repeat('j', 192),
            'post_id' => 1,
            'generation' => 1,
            'token' => str_repeat('l', 65),
        ]]),
        'explosive claim map' => static fn(): array => $queue->acknowledge_many([
            array_fill(0, 100000, 'unknown'),
        ]),
    ];
    foreach ($malformedSettlements as $label => $settle) {
        assert_true(
            psic_caught($settle) instanceof InvalidArgumentException,
            "{$label} should throw before SQL instead of impersonating a stale capability"
        );
    }
    foreach ([
        'negative claim size' => static fn(): array => $queue->claim_batch(-1),
        'zero timestamp' => static fn(): int => $queue->enqueue_many([1], 0),
        'padded scope key' => static fn(): mixed => $queue->enqueue_scope(' padded-scope '),
        'padded corpus hash' => static fn(): bool => $queue->corpus_scope_matches(
            'scope-key',
            ' ' . str_repeat('a', 32),
            str_repeat('b', 40)
        ),
    ] as $label => $operation) {
        assert_true(psic_caught($operation) instanceof InvalidArgumentException, "{$label} should reject before SQL");
    }
    assert_same($before, $fake->num_queries, 'all hostile queue inputs should fail before SQL');
});

test_case('quality post index options reject unsupported caller and filter fields', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $post = (object) [
        'ID' => 7100,
        'fts_language_override' => '',
        'fts_integration_language' => '',
    ];
    $filterCalls = 0;
    $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::POST_INDEX_OPTIONS_FILTER] = static function (array $options) use (&$filterCalls): array {
        $filterCalls++;

        return $options;
    };
    try {
        foreach ([
            ['lang' => 'en'],
            ['unknown' => true],
            [0 => 'document_lang'],
        ] as $options) {
            $error = psic_caught(static fn(): array => WP_FTS_Plugin::prepare_post_index_options($post, $options));
            assert_true($error instanceof InvalidArgumentException, 'unsupported caller indexing keys should reject before the options filter');
        }
        assert_same(0, $filterCalls, 'invalid caller indexing keys must not reach the options filter');

        foreach (['document_lang', 'default_lang'] as $key) {
            foreach ([null, false, true, 1, 1.0, [], new stdClass(), '', '   ', ' en', 'en ', str_repeat('l', 65)] as $value) {
                $error = psic_caught(static fn(): array => WP_FTS_Plugin::prepare_post_index_options($post, [$key => $value]));
                assert_true($error instanceof InvalidArgumentException, "caller {$key} should require an unpadded nonempty native string");
            }
        }
        assert_same(0, $filterCalls, 'malformed caller indexing languages must not reach the options filter');
    } finally {
        unset($GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::POST_INDEX_OPTIONS_FILTER]);
    }

    foreach ([
        static fn(array $options): array => $options + ['unknown' => true],
        static fn(array $options): array => $options + [0 => 'unknown'],
        static fn(array $options): array => array_replace($options, ['document_lang' => ' en']),
    ] as $filteredOptions) {
        $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::POST_INDEX_OPTIONS_FILTER] = $filteredOptions;
        try {
            $error = psic_caught(static fn(): array => WP_FTS_Plugin::prepare_post_index_options($post));
            assert_true($error instanceof InvalidArgumentException, 'the options filter must not inject unsupported keys or malformed languages');
        } finally {
            unset($GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::POST_INDEX_OPTIONS_FILTER]);
        }
    }
});

test_case('quality extractor enforces exact options and strict custom-field and field-boost inputs', function (): void {
    $extractor = new WP_FTS_PostContentExtractor();
    $post = (object) [
        'ID' => 7101,
        'post_title' => 'Strict boost input',
        'post_content' => '',
        'post_excerpt' => '',
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-07-18 00:00:00',
        'terms' => [],
        'custom_fields' => [],
    ];

    foreach (['post_title', 'post_content', 'post_excerpt'] as $property) {
        $malformedPost = clone $post;
        $malformedPost->{$property} = 7;
        $error = psic_caught(static fn(): array => $extractor->extract($malformedPost));
        assert_true($error instanceof InvalidArgumentException, "{$property} should require an authoritative native string");
    }

    foreach (['wp_fts_post_terms', 'wp_fts_post_custom_field_values'] as $hook) {
        foreach ([
            'scalar map' => 'term',
            'numeric key' => [0 => ['term']],
            'padded key' => [' topic ' => ['term']],
            'scalar values' => ['topic' => 'term'],
            'associative values' => ['topic' => ['first' => 'term']],
            'non-string item' => ['topic' => [7]],
            'blank item' => ['topic' => ['   ']],
            'markup-only item' => ['topic' => ['<span></span>']],
        ] as $description => $filtered) {
            $filteredPost = clone $post;
            $filteredPost->custom_fields = ['topic' => ['term']];
            $GLOBALS['wp_fts_test_filters'][$hook] = static fn(): mixed => $filtered;
            try {
                $error = psic_caught(static fn(): array => $extractor->extract($filteredPost));
                assert_same(
                    'structured_map_shape',
                    $error instanceof WP_FTS_Analysis_Limit_Exceeded ? $error->reason_code : null,
                    "{$hook} should reject {$description}"
                );
            } finally {
                unset($GLOBALS['wp_fts_test_filters'][$hook]);
            }
        }
    }

    $magicCustomField = new class {
        public int $calls = 0;

        /** Fail if custom-field normalization invokes magic option access. */
        public function __get(string $name): mixed
        {
            $this->calls++;
            throw new RuntimeException('magic option access must not run');
        }
    };
    foreach ([
        'non-array' => null,
        'scalar' => 'meta_key',
        'map' => ['meta_key' => true],
        'integer item' => [1],
        'boolean item' => [true],
        'null item' => [null],
        'nested item' => [['meta_key']],
        'object item' => [$magicCustomField],
        'empty item' => [''],
        'blank item' => ['   '],
        'padded item' => [' meta_key '],
    ] as $description => $keys) {
        $error = psic_caught(static fn(): array => $extractor->normalize_selected_custom_field_keys($keys));
        assert_true($error instanceof WP_FTS_Analysis_Limit_Exceeded, "custom-field keys should reject {$description}");
        assert_same('custom_field_key_shape', $error instanceof WP_FTS_Analysis_Limit_Exceeded ? $error->reason_code : null, "custom-field {$description} should use the strict shape reason");
    }
    assert_same(0, $magicCustomField->calls, 'custom-field validation should reject objects without invoking magic access');

    $customFieldCountError = psic_caught(static fn(): array => $extractor->normalize_selected_custom_field_keys(
        array_map(static fn(int $index): string => 'meta_' . $index, range(1, 33))
    ));
    assert_same('custom_field_keys', $customFieldCountError instanceof WP_FTS_Analysis_Limit_Exceeded ? $customFieldCountError->reason_code : null, 'the 33rd custom-field key should use the fixed cardinality reason');
    $customFieldBytesError = psic_caught(static fn(): array => $extractor->normalize_selected_custom_field_keys([
        str_repeat('k', WP_FTS_PostContentExtractor::MAX_CUSTOM_FIELD_KEY_BYTES + 1),
    ]));
    assert_same('custom_field_key_bytes', $customFieldBytesError instanceof WP_FTS_Analysis_Limit_Exceeded ? $customFieldBytesError->reason_code : null, 'custom-field key byte 192 should use the fixed byte-bound reason');
    assert_same(
        ['alpha', 'zeta'],
        $extractor->normalize_selected_custom_field_keys(['zeta', 'alpha', 'alpha']),
        'valid custom-field key lists should deduplicate and sort without accepting nested shapes'
    );
    $GLOBALS['wp_fts_test_filters']['wp_fts_post_custom_fields'] = static fn(): array => [['nested']];
    try {
        $filteredCustomFieldError = psic_caught(static fn(): array => $extractor->extract($post, [
            'custom_field_keys' => [],
        ]));
        assert_same('custom_field_key_shape', $filteredCustomFieldError instanceof WP_FTS_Analysis_Limit_Exceeded ? $filteredCustomFieldError->reason_code : null, 'the WordPress custom-field filter must still return a flat string list');
    } finally {
        unset($GLOBALS['wp_fts_test_filters']['wp_fts_post_custom_fields']);
    }

    foreach ([
        ['filters' => []],
        ['document_lang' => 'en'],
        [0 => 'numeric option key'],
    ] as $options) {
        $optionError = psic_caught(static fn(): array => $extractor->extract($post, $options));
        assert_true($optionError instanceof InvalidArgumentException, 'extractor options should contain only custom_field_keys and field_boosts');
    }

    $validOption = $extractor->extract($post, [
        'field_boosts' => ['title' => 7, 'content' => 2.0],
    ]);
    assert_same(7.0, $validOption['fields'][0]['boost'] ?? null, 'field-boost options should accept native whole-number integers');
    $GLOBALS['wp_fts_test_filters']['wp_fts_post_field_boosts'] = static fn(array $boosts): array => array_replace($boosts, ['title' => 9.0]);
    try {
        $validFilter = $extractor->extract($post);
        assert_same(9.0, $validFilter['fields'][0]['boost'] ?? null, 'the WordPress field-boost filter should accept native integral floats');
    } finally {
        unset($GLOBALS['wp_fts_test_filters']['wp_fts_post_field_boosts']);
    }

    $malformedBoostMaps = [
        ['non-array', null],
        ['numeric key', [0 => 1]],
        ['empty key', ['' => 1]],
        ['blank key', ['   ' => 1]],
        ['padded key', [' title ' => 1]],
        ['oversized key', [str_repeat('k', 192) => 1]],
        ['numeric string value', ['title' => '2']],
        ['boolean value', ['title' => true]],
        ['null value', ['title' => null]],
        ['fractional float', ['title' => 2.5]],
        ['zero value', ['title' => 0]],
        ['negative value', ['title' => -1]],
        ['oversized value', ['title' => 101]],
        ['NAN value', ['title' => NAN]],
        ['positive infinite value', ['title' => INF]],
        ['negative infinite value', ['title' => -INF]],
        ['array value', ['title' => []]],
    ];
    foreach (['option', 'filter'] as $source) {
        foreach ($malformedBoostMaps as [$description, $candidate]) {
            if ($source === 'filter') {
                $GLOBALS['wp_fts_test_filters']['wp_fts_post_field_boosts'] = static fn(): mixed => $candidate;
            }
            try {
                $opts = $source === 'option' ? ['field_boosts' => $candidate] : [];
                $error = psic_caught(static fn(): array => $extractor->extract($post, $opts));
                assert_true(
                    $error instanceof WP_FTS_Analysis_Limit_Exceeded,
                    "{$source} field boosts should reject {$description}"
                );
            } finally {
                if ($source === 'filter') {
                    unset($GLOBALS['wp_fts_test_filters']['wp_fts_post_field_boosts']);
                }
            }
        }
    }

    $optionBoostError = psic_caught(static fn(): array => $extractor->extract($post, [
        'field_boosts' => array_fill(0, 33, 1),
    ]));
    assert_true($optionBoostError instanceof WP_FTS_Analysis_Limit_Exceeded, 'field-boost options above 32 entries should be rejected before copying the map');
    assert_same('field_boosts', $optionBoostError instanceof WP_FTS_Analysis_Limit_Exceeded ? $optionBoostError->reason_code : null, 'option field-boost cardinality should have a stable typed reason');

    $GLOBALS['wp_fts_test_filters']['wp_fts_post_field_boosts'] = static fn(): array => array_fill(0, 33, 1);
    try {
        $filteredBoostError = psic_caught(static fn(): array => $extractor->extract($post));
        assert_true($filteredBoostError instanceof WP_FTS_Analysis_Limit_Exceeded, 'filtered field boosts above 32 entries should be rejected before normalization');
        assert_same('field_boosts', $filteredBoostError instanceof WP_FTS_Analysis_Limit_Exceeded ? $filteredBoostError->reason_code : null, 'filtered field-boost cardinality should have a stable typed reason');
    } finally {
        unset($GLOBALS['wp_fts_test_filters']['wp_fts_post_field_boosts']);
    }

    $boundaryBoosts = [];
    for ($index = 1; $index <= 32; $index++) {
        $boundaryBoosts['field_' . $index] = 1;
    }
    $GLOBALS['wp_fts_test_filters']['wp_fts_post_field_boosts'] = static fn(): array => $boundaryBoosts;
    try {
        $boundary = $extractor->extract($post);
        assert_same(['fields', 'snippet_text'], array_keys($boundary), 'the exact 32-field-boost boundary should retain the current extractor output');
    } finally {
        unset($GLOBALS['wp_fts_test_filters']['wp_fts_post_field_boosts']);
    }
});

test_case('quality MySQL set-oriented APIs reject explosive inputs before normalization', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $storage = new WP_FTS_Relational_Storage($fake);
    $before = $fake->num_queries;

    $boundaryAlternatives = array_map(
        static fn(int $index): array => [
            'key' => WP_FTS_TermNamespace::namespace_term('en', 'term_' . $index),
            'rank' => $index,
        ],
        range(1, 12)
    );
    $boundaryPlan = psic_mysql_private($storage, 'normalize_search_plan', [$boundaryAlternatives], []);
    assert_same(12, count($boundaryPlan['groups'][0] ?? []), 'the exact 12-alternative direct-storage boundary should remain accepted');
    $alternativeError = psic_caught(static fn(): array => psic_mysql_private(
        $storage,
        'normalize_search_plan',
        [[...$boundaryAlternatives, ['key' => WP_FTS_TermNamespace::namespace_term('en', 'term_13'), 'rank' => 13]]],
        []
    ));
    assert_true($alternativeError instanceof WP_FTS_Search_Budget_Exceeded, 'a thirteenth direct-storage alternative should fail at the documented boundary');

    $deleteError = psic_caught(static fn(): array => $storage->replace_prepared_documents([], array_fill(0, 101, 1)));
    assert_true($deleteError instanceof InvalidArgumentException, 'prepared writes should reject raw delete cardinality before traversal');

    $preparedLanguageError = psic_caught(static fn(): array => $storage->replace_prepared_documents([
        psic_prepared_document(['primary_lang' => str_repeat('l', 65)]),
    ]));
    assert_true($preparedLanguageError instanceof WP_FTS_Prepared_Document_Rejected, 'prepared document languages should be bounded before canonicalization');

    $preparedIdError = psic_caught(static fn(): array => $storage->replace_prepared_documents([
        psic_prepared_document(['doc_id' => str_repeat('1', 65)]),
    ]));
    assert_true($preparedIdError instanceof WP_FTS_Prepared_Document_Rejected, 'prepared document ids should be bounded before integer validation');

    $preparedFrequencyError = psic_caught(static fn(): array => $storage->replace_prepared_documents([
        psic_prepared_document([
            'term_frequencies' => [WP_FTS_TermNamespace::namespace_term('en', 'term') => str_repeat('1', 65)],
        ]),
    ]));
    assert_true($preparedFrequencyError instanceof WP_FTS_Prepared_Document_Rejected, 'prepared term frequencies should be bounded before integer validation');

    $preparedSnippetError = psic_caught(static fn(): array => $storage->replace_prepared_documents([
        psic_prepared_document([
            'snippet_text' => str_repeat('s', WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES + 1),
        ]),
    ]));
    assert_true($preparedSnippetError instanceof WP_FTS_Prepared_Document_Rejected, 'prepared snippet sources should be bounded before UTF-8 processing');

    $preparedDeleteError = psic_caught(static fn(): array => $storage->replace_prepared_documents([], [str_repeat('1', 65)]));
    assert_true($preparedDeleteError instanceof WP_FTS_Prepared_Document_Rejected, 'prepared delete ids should be bounded before integer validation');

    $filterError = psic_caught(static fn(): array => $storage->search_page(
        [[['key' => WP_FTS_TermNamespace::namespace_term('en', 'term'), 'rank' => 0]]],
        ['mode' => 'OR', 'direction' => 'after', 'post_types' => [str_repeat('p', 65)]]
    ));
    assert_true($filterError instanceof InvalidArgumentException, 'direct storage filters should check bytes before trim');
    foreach ([
        ['post_types' => 'post'],
        ['post_types' => ['post' => 'post']],
        ['post_types' => [1]],
        ['post_types' => [' post']],
        ['post_types' => ['post', 'post']],
    ] as $filters) {
        $filterError = psic_caught(static fn(): array => $storage->search_page(
            [[['key' => WP_FTS_TermNamespace::namespace_term('en', 'term'), 'rank' => 0]]],
            ['mode' => 'OR', 'direction' => 'after'] + $filters
        ));
        assert_true($filterError instanceof InvalidArgumentException, 'direct storage filters must be exact unique lists of native unpadded strings');
    }
    foreach ([
        ['mode' => str_repeat('O', 9)],
        ['mode' => 'XOR'],
        ['direction' => str_repeat('b', 9)],
        ['direction' => 'sideways'],
        ['page_size' => str_repeat('1', 65)],
        ['include_metadata' => str_repeat('y', 17)],
        ['prefix_surface' => ['lang' => 'en', 'term' => str_repeat('t', 256)]],
        array_fill(0, 100000, 'unknown'),
    ] as $options) {
        $optionError = psic_caught(static fn(): array => $storage->search_page(
            [[['key' => WP_FTS_TermNamespace::namespace_term('en', 'term'), 'rank' => 0]]],
            array_replace(['mode' => 'OR', 'direction' => 'after'], $options)
        ));
        assert_true($optionError instanceof InvalidArgumentException, 'direct storage mode and direction values should be bounded and enumerated before normalization');
    }

    $rankError = psic_caught(static fn(): array => $storage->search_page(
        [[['key' => WP_FTS_TermNamespace::namespace_term('en', 'term'), 'rank' => str_repeat('1', 65)]]],
        ['mode' => 'OR', 'direction' => 'after']
    ));
    assert_true($rankError instanceof InvalidArgumentException, 'direct storage alternative ranks should be bounded before integer normalization');

    assert_same($before, $fake->num_queries, 'all direct input rejections should happen before SQL');

    $guardCalls = 0;
    $guardedStorage = new WP_FTS_Relational_Storage($fake, null, static function () use (&$guardCalls): void {
        $guardCalls++;
    });
    foreach (['get_doc', 'get_doc_metadata', 'terms_for_doc', 'put_doc', 'put_doc_metadata', 'delete_doc'] as $method) {
        assert_true(!method_exists($guardedStorage, $method), "guarded relational storage should not expose {$method}");
    }
    assert_same(0, $guardCalls, 'capability inspection should not invoke the mutation guard');
    psic_caught(static fn(): array => $guardedStorage->replace_prepared_documents([
        psic_prepared_document([
            'snippet_text' => str_repeat('s', WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES + 1),
        ]),
    ]));
    assert_same(0, $guardCalls, 'prepared-document input validation should finish before invoking a potentially SQL-backed mutation guard');
});
