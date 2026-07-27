<?php
declare(strict_types=1);

/** Capture one strict-boundary rejection for direct inspection. */
function splwo_caught(callable $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $error) {
        return $error;
    }

    return null;
}

/** Invoke one private plugin parser without crossing its later state boundary. */
function splwo_plugin_private(string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod(WP_FTS_Plugin::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(null, ...$args);
}

/** Invoke one private CLI parser without running a command. */
function splwo_cli_private(WP_FTS_WPCLI_Command $command, string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod(WP_FTS_WPCLI_Command::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($command, ...$args);
}

test_case('production storage factories and inactive transaction controls stay closed', function (): void {
    $storageFactory = new ReflectionMethod(WP_FTS_Plugin::class, 'storage');
    assert_true($storageFactory->isPrivate(), 'the production storage object must not escape through a public plugin accessor');
    assert_same(0, $storageFactory->getNumberOfParameters(), 'the production storage factory must expose no schema-check mode');
    $queueFactory = new ReflectionMethod(WP_FTS_Plugin::class, 'index_queue');
    assert_true($queueFactory->isPrivate(), 'the production queue object must not escape through a public plugin accessor');
    assert_same(0, $queueFactory->getNumberOfParameters(), 'the production queue factory must expose no schema-check mode');

    $wpdb = new WP_FTS_Test_WPDB();
    $storage = new WP_FTS_Relational_Storage($wpdb);
    foreach (['commit', 'advance_epoch_before_capability_retirement', 'rollback'] as $method) {
        $before = $wpdb->num_queries;
        $error = splwo_caught(static fn(): mixed => $storage->{$method}());
        assert_true($error instanceof LogicException, "inactive {$method} should fail instead of controlling the shared connection");
        assert_same($before, $wpdb->num_queries, "inactive {$method} should execute no transaction SQL");
    }

    foreach (['prefix', 'posts', 'term_relationships'] as $property) {
        $adapter = (object) [
            'prefix' => 'wp_',
            'posts' => 'wp_posts',
            'term_relationships' => 'wp_term_relationships',
        ];
        $adapter->{$property} = 1;
        $error = splwo_caught(static fn(): object => new WP_FTS_Relational_Storage($adapter));
        assert_true($error instanceof InvalidArgumentException, "storage must reject a non-string wpdb {$property}");
    }
});

test_case('index reset requires one canonical positive database epoch', function (): void {
    $method = new ReflectionMethod(WP_FTS_Relational_Storage::class, 'current_search_epoch_for_reset');
    $method->setAccessible(true);

    $adapter = static function (mixed $epoch): object {
        return new class($epoch) {
            public string $prefix = 'wp_';
            public string $posts = 'wp_posts';
            public string $term_relationships = 'wp_term_relationships';
            public string $last_error = '';

            public function __construct(private mixed $epoch)
            {
            }

            public function prepare(string $sql, mixed ...$args): string
            {
                return $sql;
            }

            public function get_var(string $sql): mixed
            {
                return $this->epoch;
            }
        };
    };

    foreach ([null, 0, -1, true, 1.0, '', '0', '01', ' 1', '1 ', '1e0', (string) PHP_INT_MAX . '0'] as $epoch) {
        $storage = new WP_FTS_Relational_Storage($adapter($epoch));
        $error = splwo_caught(static fn(): mixed => $method->invoke($storage));
        assert_true($error instanceof RuntimeException, 'index reset must reject a missing or noncanonical database epoch');
    }
    foreach ([1, '1', PHP_INT_MAX, (string) PHP_INT_MAX] as $epoch) {
        $storage = new WP_FTS_Relational_Storage($adapter($epoch));
        assert_same((int) $epoch, $method->invoke($storage), 'index reset must accept native and canonical database integers');
    }
});

test_case('strict public language parser accepts only the documented tag grammar', function (): void {
    assert_same('en-US', WP_FTS_TermNamespace::parse_language_tag('en_US'), 'underscores should normalize to hyphens after strict validation');
    assert_same('zh-Hant', WP_FTS_TermNamespace::parse_language_tag('zh-TW'), 'a valid Chinese region should normalize to its analyzer script partition');
    assert_same('sr-Cyrl-RS', WP_FTS_TermNamespace::parse_language_tag('sr_Cyrl_RS'), 'valid script and region subtags should retain canonical casing');
    assert_same('en-a1', WP_FTS_TermNamespace::parse_language_tag('en-a1'), 'two-byte alphanumeric subtags should remain valid');

    foreach ([
        '!!!',
        'en@@US',
        '123',
        'C',
        'POSIX',
        'en-a',
        'en-abcdefghi',
        'aa-bb-cc-dd-ee-ff-gg-hh-ii',
    ] as $language) {
        $error = splwo_caught(static fn(): string => WP_FTS_TermNamespace::parse_language_tag($language));
        assert_true($error instanceof InvalidArgumentException, "{$language} should be rejected instead of collapsing to a fallback partition");
    }
});

test_case('strict public indexing and search languages reject before analyzer or storage work', function (): void {
    $analyzer = new class {
        public int $documentCalls = 0;
        public int $queryCalls = 0;

        public function index_signature(): string
        {
            return 'strict-language-test-v1';
        }

        public function analyze_document_fields(array $fields, array $options): array
        {
            $this->documentCalls++;

            return array_fill(0, count($fields), []);
        }

        public function analyze_query_occurrences(string $query, array $options): array
        {
            $this->queryCalls++;

            return [];
        }
    };
    $storage = new class implements WP_FTS_Set_Oriented_Search_Storage {
        public int $calls = 0;

        public function search_page(array $groups, array $options): array
        {
            $this->calls++;

            return [
                'results' => [],
                'has_more' => false,
                'next_cursor' => null,
                'previous_cursor' => null,
            ];
        }
    };
    $indexer = new WP_FTS_Indexer($analyzer);
    $searcher = new WP_FTS_Searcher($storage, $analyzer);
    $malformed = ['!!!', 'en@@US', '123', 'C', 'POSIX', 'aa-bb-cc-dd-ee-ff-gg-hh-ii'];

    foreach ($malformed as $language) {
        foreach (['document_lang', 'default_lang'] as $key) {
            $error = splwo_caught(static fn(): array => $indexer->prepare_document_fields(1, [], [$key => $language]));
            assert_true($error instanceof InvalidArgumentException, "indexer {$key} should reject {$language}");
        }
        foreach (['query_lang', 'default_lang'] as $key) {
            $error = splwo_caught(static fn(): array => $searcher->search('term', [$key => $language]));
            assert_true($error instanceof InvalidArgumentException, "searcher {$key} should reject {$language}");
        }
        $snippetError = splwo_caught(static fn(): string => $searcher->snippet_for_text(
            'term source',
            'term',
            ['result_lang' => $language]
        ));
        assert_true($snippetError instanceof InvalidArgumentException, "snippet result_lang should reject {$language}");
    }
    assert_same(0, $analyzer->documentCalls, 'malformed indexing languages should be rejected before document analysis');
    assert_same(0, $analyzer->queryCalls, 'malformed search languages should be rejected before query analysis');
    assert_same(0, $storage->calls, 'malformed search languages should be rejected before storage');

    $prepared = $indexer->prepare_document_fields(1, [], ['document_lang' => 'en_US']);
    assert_same('en-US', $prepared['primary_lang'] ?? null, 'the indexer should store the strict canonical language');
    $page = $searcher->search('term', ['query_lang' => 'zh-TW']);
    assert_same('zh-Hant', $page['query_lang'] ?? null, 'the searcher should report the strict canonical language');
});

test_case('strict WordPress REST and CLI language boundaries share one parser', function (): void {
    $command = new WP_FTS_WPCLI_Command();
    $post = (object) [];
    $malformed = ['!!!', 'en@@US', '123', 'C', 'POSIX', 'aa-bb-cc-dd-ee-ff-gg-hh-ii'];

    foreach ($malformed as $language) {
        $indexError = splwo_caught(static fn(): array => WP_FTS_Plugin::prepare_post_index_options(
            $post,
            ['document_lang' => $language]
        ));
        assert_true($indexError instanceof InvalidArgumentException, "WordPress indexing should reject {$language}");

        $packError = splwo_caught(static fn(): array => WP_FTS_Plugin::set_runtime_lemma_pack_option(
            $language,
            'unused-manifest.json'
        ));
        assert_true($packError instanceof InvalidArgumentException, "runtime lemma-pack setup should reject {$language}");

        $searchError = splwo_caught(static fn(): array => splwo_plugin_private(
            'normalize_public_search_options',
            ['lang' => $language]
        ));
        assert_true($searchError instanceof InvalidArgumentException, "WordPress search should reject {$language}");

        $restError = splwo_caught(static fn(): ?string => splwo_plugin_private(
            'rest_language',
            ['lang' => $language]
        ));
        assert_true($restError instanceof InvalidArgumentException, "REST search should reject {$language}");

        $cliError = splwo_caught(static fn(): string => splwo_cli_private($command, 'language_arg', $language));
        assert_true($cliError instanceof InvalidArgumentException, "WP-CLI should reject {$language}");
    }

    $optionsBeforeInvalidPath = $GLOBALS['wp_fts_test_options'] ?? [];
    foreach (['', ' manifest.json '] as $manifestPath) {
        $packError = splwo_caught(static fn(): array => WP_FTS_Plugin::set_runtime_lemma_pack_option(
            'en',
            $manifestPath
        ));
        assert_true($packError instanceof InvalidArgumentException, 'runtime lemma-pack setup should reject empty or padded manifest paths');
    }
    assert_same($optionsBeforeInvalidPath, $GLOBALS['wp_fts_test_options'] ?? [], 'invalid manifest paths should not mutate stored options');

    assert_same(
        ['lang' => 'en-US'],
        splwo_plugin_private('normalize_public_search_options', ['lang' => 'en_US']),
        'WordPress search should retain the canonical strict language'
    );
    assert_same('zh-Hant', splwo_plugin_private('rest_language', ['lang' => 'zh-TW']), 'REST should return the canonical strict language');
    assert_same('en-US', splwo_cli_private($command, 'language_arg', 'en_US'), 'WP-CLI should return the canonical strict language');
});

test_case('strict runtime analyzer maps require canonical keys and valid stored state', function (): void {
    foreach ([
        ['lemma_packs_by_lang' => ['en' => null]],
        ['lemma_packs_by_lang' => ['en' => ' manifest.json']],
        ['lemma_packs_by_lang' => ['en_US' => false, 'en-US' => false]],
        ['lemma_packs_by_lang' => ['e!n' => false]],
        ['segmenter_packs_by_lang' => ['zh' => null]],
        ['segmenter_packs_by_lang' => ['zh' => 1]],
        ['segmenter_packs_by_lang' => ['zh_CN' => false, 'zh-Hans' => false]],
    ] as $options) {
        $error = splwo_caught(static fn(): array => splwo_plugin_private(
            'sanitize_runtime_analyzer_options',
            $options
        ));
        assert_true($error instanceof InvalidArgumentException, 'runtime analyzer maps should reject ambiguous or malformed states');
    }

    $storedEntryError = splwo_caught(static fn(): array => splwo_plugin_private(
        'stored_runtime_lemma_pack_entries_for_language',
        ['lemma_packs_by_lang' => [' EN ' => false]],
        'en'
    ));
    assert_true($storedEntryError instanceof InvalidArgumentException, 'stored entry inspection should use the strict runtime map parser');

    $paddedPathError = splwo_caught(static fn(): bool => splwo_plugin_private(
        'lemma_pack_option_points_to_manifest',
        ' manifest.json',
        'manifest.json'
    ));
    assert_true($paddedPathError instanceof InvalidArgumentException, 'bundled-path comparison should not trim configured paths');

    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = 'malformed-state';
    $beforeUpdates = $GLOBALS['wp_fts_test_updated_options'];
    $storedStateError = splwo_caught(static fn(): array => WP_FTS_Plugin::set_runtime_lemma_pack_option(
        'bn',
        wp_fts_test_synthetic_bengali_fixture_manifest()
    ));
    assert_true($storedStateError instanceof UnexpectedValueException, 'a non-array stored analyzer option should fail instead of being replaced');
    assert_same($beforeUpdates, $GLOBALS['wp_fts_test_updated_options'], 'malformed stored analyzer state should not be rewritten');
    wp_fts_test_reset_wordpress_fakes();
});

test_case('manual batch and private writer option bags reject before state or callbacks', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    try {
        $writerCalls = 0;
        foreach (['', ' padded ', 'BadSource', 'bad.source', str_repeat('s', 41)] as $source) {
            $beforeQueries = $fake->num_queries;
            $beforeSchedules = count($GLOBALS['wp_fts_test_schedule_calls']);
            $error = splwo_caught(static function () use (&$writerCalls, $source): array {
                return splwo_plugin_private(
                    'run_index_writer_with_lock',
                    $source,
                    static function () use (&$writerCalls): int {
                        $writerCalls++;

                        return 1;
                    }
                );
            });
            assert_true($error instanceof InvalidArgumentException, 'direct writer should reject malformed sources');
            assert_same($beforeQueries, $fake->num_queries, 'direct writer source rejection should execute no SQL');
            assert_same($beforeSchedules, count($GLOBALS['wp_fts_test_schedule_calls']), 'direct writer source rejection should schedule nothing');
        }
        assert_same(0, $writerCalls, 'direct writer source rejection should not invoke its callback');

        $manualBags = [
            ['unknown' => true],
            [0 => 'batch_size'],
            ['batch_size' => '1'],
            ['batch_size' => 0],
            ['batch_size' => WP_FTS_Plugin::MAX_MANUAL_INDEX_BATCH_SIZE + 1],
            ['source' => 1],
            ['source' => ' padded '],
            ['source' => 'BadSource'],
            ['source' => 'bad.source'],
            ['source' => str_repeat('s', 41)],
            ['time_budget' => '1'],
            ['time_budget' => false],
            ['time_budget' => 0],
            ['time_budget' => WP_FTS_Plugin::MAX_MANUAL_INDEX_TIME_BUDGET_SECONDS + 1],
            ['time_budget' => INF],
            ['time_budget' => NAN],
        ];
        foreach ($manualBags as $options) {
            $beforeQueries = $fake->num_queries;
            $beforeSchedules = count($GLOBALS['wp_fts_test_schedule_calls']);
            $error = splwo_caught(static fn(): array => WP_FTS_Plugin::process_manual_index_batch($options));
            assert_true($error instanceof InvalidArgumentException, 'manual batch should reject malformed or unsupported options');
            assert_same($beforeQueries, $fake->num_queries, 'manual batch option rejection should execute no SQL');
            assert_same($beforeSchedules, count($GLOBALS['wp_fts_test_schedule_calls']), 'manual batch option rejection should schedule nothing');
        }
        assert_same(
            'manual_test-2',
            splwo_plugin_private('index_batch_source', 'manual', ['source' => 'manual_test-2']),
            'an accepted manual source should remain byte-for-byte unchanged'
        );
        $cliBudgetError = splwo_caught(static function (): void {
            (new WP_FTS_WPCLI_Command())->process_batch([], ['time_budget' => '301']);
        });
        assert_true($cliBudgetError instanceof InvalidArgumentException, 'WP-CLI should reject a manual batch budget above 300 seconds');
        assert_same(0, $fake->num_queries, 'WP-CLI budget rejection should execute no SQL');

        $writerBags = [
            ['unknown' => true],
            [0 => 'batch_size'],
            ['batch_size' => 1],
            ['batch_size' => '1'],
            ['batch_size' => -1],
            ['batch_size' => WP_FTS_Plugin::MAX_MANUAL_INDEX_BATCH_SIZE + 1],
            ['indexed' => 0],
            ['indexed' => '0'],
            ['indexed' => -1],
            ['indexed' => WP_FTS_Plugin::MAX_MANUAL_INDEX_BATCH_SIZE + 1],
            ['record_skip' => 0],
            ['record_skip' => '0'],
            ['record_health' => 1],
            ['record_health' => '1'],
        ];
        foreach ($writerBags as $options) {
            $beforeQueries = $fake->num_queries;
            $beforeSchedules = count($GLOBALS['wp_fts_test_schedule_calls']);
            $error = splwo_caught(static function () use (&$writerCalls, $options): array {
                return splwo_plugin_private(
                    'run_index_writer_with_lock',
                    'strict-option-test',
                    static function () use (&$writerCalls): int {
                        $writerCalls++;

                        return 1;
                    },
                    $options
                );
            });
            assert_true($error instanceof InvalidArgumentException, 'direct writer should reject malformed or unsupported options');
            assert_same($beforeQueries, $fake->num_queries, 'direct writer option rejection should execute no SQL');
            assert_same($beforeSchedules, count($GLOBALS['wp_fts_test_schedule_calls']), 'direct writer option rejection should schedule nothing');
        }
        assert_same(0, $writerCalls, 'direct writer option rejection should not invoke its callback');
    } finally {
        $wpdb = $oldWpdb;
    }
});
