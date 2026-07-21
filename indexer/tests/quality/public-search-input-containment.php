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
function psic_mysql_private(WP_FTS_Storage_Mysql $storage, string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod(WP_FTS_Storage_Mysql::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($storage, ...$args);
}

test_case('set-oriented snippets use only the typed prefix surface', function (): void {
    $searcher = new WP_FTS_Searcher(new WP_FTS_Storage_InMemory(), new WP_FTS_Analyzer([
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

test_case('quality direct indexer and metadata inputs are fenced before analysis', function (): void {
    $analyzer = new class {
        public int $calls = 0;
        /** @var array<int,array<string,mixed>|string> */
        public array $output = [];

        /** Return caller-controlled rows and count any analysis that escaped preflight. */
        public function analyze_content(string $source, array $options = []): array
        {
            $this->calls++;
            return $this->output;
        }

        /** Share the hostile output across HTML and plain-field paths. */
        public function analyze_plain_content(string $source, array $options = []): array
        {
            $this->calls++;
            return $this->output;
        }

        /** Keep prepared-source fingerprints stable across input-boundary cases. */
        public function index_signature(): string
        {
            return 'bounded-test-analyzer';
        }
    };
    $indexer = new WP_FTS_Indexer(new WP_FTS_Storage_InMemory(), $analyzer);

    foreach ([
        [array_fill(0, 33, ''), []],
        [[['name' => str_repeat('n', 192), 'text' => 'value']], []],
        [[['name' => 'content', 'text' => 'value', 'boost' => str_repeat('1', 65)]], []],
        [[], ['field_boosts' => array_fill(0, 33, 1)]],
        [[], ['lang' => str_repeat('l', 65)]],
        [[], ['metadata_text_limit' => str_repeat('1', 65)]],
        [[], array_fill(0, 100000, 'unknown')],
        [[], ['unknown' => array_fill(0, 100000, 'value')]],
    ] as [$fields, $options]) {
        $error = psic_caught(static fn(): array => $indexer->prepare_document_fields(1, $fields, $options));
        assert_true($error instanceof InvalidArgumentException || $error instanceof WP_FTS_Analysis_Limit_Exceeded, 'direct index fields, boosts, languages, and numeric options should fail before analysis');
    }
    assert_same(0, $analyzer->calls, 'pre-analysis direct indexer failures should not invoke the analyzer');

    $dualSource = str_repeat('s', 1100000);
    $dualSourceError = psic_caught(static fn(): array => $indexer->prepare_document_fields(1, [[
        'name' => 'content',
        'text' => $dualSource,
        'html' => $dualSource,
    ]]));
    assert_true($dualSourceError instanceof WP_FTS_Analysis_Limit_Exceeded, 'text and HTML buffers should share one 2 MiB direct-field source budget');
    assert_same(0, $analyzer->calls, 'aggregate direct-field source rejection should precede analyzer work');

    foreach ([
        ['term' => str_repeat('t', WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES + 1)],
        ['term' => 'term', 'lang' => str_repeat('l', 65)],
        ['term' => 'term', 'position' => str_repeat('1', 65)],
        ['term' => 'term', 'rank' => str_repeat('1', 65)],
        ['term' => 'term', 'source' => str_repeat('s', 257)],
        ['term' => 'term', 'payload' => array_fill(0, 100000, 'value')],
    ] as $occurrence) {
        $analyzer->output = [$occurrence];
        $error = psic_caught(static fn(): array => $indexer->prepare_document_fields(1, [['name' => 'content', 'text' => 'value']]));
        assert_true($error instanceof WP_FTS_Analysis_Limit_Exceeded, 'custom document analyzer scalars should be bounded before trim, canonicalization, or numeric casts');
    }

    $analyzer->output = array_fill(0, WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES + 1, 'term');
    $occurrenceCountError = psic_caught(static fn(): array => $indexer->prepare_document_fields(1, [['name' => 'content', 'text' => 'value']]));
    assert_true($occurrenceCountError instanceof WP_FTS_Analysis_Limit_Exceeded, 'custom document analyzer cardinality should be checked before copying occurrence rows');
    $analyzer->output = [];

    $baseSource = [
        'doc_id' => 1,
        'primary_lang' => 'en',
        'content_hash' => str_repeat('h', 40),
        'fields' => [],
        'analysis_options' => [],
        'metadata' => null,
        'replace_metadata_on_hash_match' => true,
    ];
    foreach ([
        ['fields' => array_fill(0, 33, ['name' => 'content', 'text' => ''])],
        ['primary_lang' => str_repeat('l', 65)],
        ['content_hash' => str_repeat('h', 65)],
        ['analysis_options' => ['lang' => str_repeat('l', 65)]],
        ['analysis_options' => array_fill(0, 100000, 'unknown')],
        ['metadata' => array_fill(0, 33, 'value')],
    ] as $override) {
        $error = psic_caught(static fn(): array => $indexer->prepare_post_from_source(array_replace($baseSource, $override)));
        assert_true($error instanceof InvalidArgumentException || $error instanceof WP_FTS_Analysis_Limit_Exceeded, 'caller-crafted prepared sources should be revalidated before field analysis');
    }

    $metadataError = psic_caught(static fn(): bool => $indexer->index_document(1, '<p>value</p>', [
        'metadata' => array_fill(0, 33, 'value'),
    ]));
    assert_true($metadataError instanceof WP_FTS_Analysis_Limit_Exceeded, 'direct document metadata should reject more than 32 keys before analysis');
    $metadataCalls = $analyzer->calls;
    $metadataKeyError = psic_caught(static fn(): bool => $indexer->index_document(1, '<p>value</p>', [
        'metadata' => [str_repeat('k', 192) => 'value'],
    ]));
    assert_true($metadataKeyError instanceof WP_FTS_Analysis_Limit_Exceeded, 'direct document metadata keys should be rejected before analysis');
    assert_same($metadataCalls, $analyzer->calls, 'invalid direct document metadata should not invoke the analyzer');

    $extractor = new class {
        public int $calls = 0;

        /** Emit twenty base keys so twenty overrides cross the shared metadata cap. */
        public function extract(object $post, array $options): array
        {
            $this->calls++;
            $metadata = [];
            for ($index = 1; $index <= 20; $index++) {
                $metadata['base_' . $index] = 'value';
            }
            return ['fields' => [['name' => 'content', 'text' => 'value']], 'metadata' => $metadata, 'field_boosts' => []];
        }
    };
    $postIndexer = new WP_FTS_Indexer(new WP_FTS_Storage_InMemory(), $analyzer, $extractor);
    $overrideMetadata = [];
    for ($index = 1; $index <= 20; $index++) {
        $overrideMetadata['override_' . $index] = 'value';
    }
    $mergeError = psic_caught(static fn(): array => $postIndexer->prepare_post_source(
        (object) ['ID' => 1],
        ['metadata' => $overrideMetadata]
    ));
    assert_true($mergeError instanceof WP_FTS_Analysis_Limit_Exceeded, 'extracted and override metadata should share one 32-key envelope before array replacement');

    $validSource = $postIndexer->prepare_post_source((object) ['ID' => 2]);
    $hashCalls = $analyzer->calls;
    $hashError = psic_caught(static fn(): array => $postIndexer->prepare_post_from_source(
        array_replace($validSource, ['content_hash' => str_repeat('0', 40)])
    ));
    assert_true($hashError instanceof InvalidArgumentException, 'caller-crafted prepared sources should not analyze content under a stale fingerprint');
    assert_same($hashCalls, $analyzer->calls, 'prepared-source hash integrity should be verified before analyzer work');

    $extractorCalls = $extractor->calls;
    $postIdError = psic_caught(static fn(): array => $postIndexer->prepare_post_source(
        (object) ['ID' => str_repeat('1', 65)]
    ));
    assert_true($postIdError instanceof InvalidArgumentException, 'post-like IDs should be bounded before integer casts');
    assert_same($extractorCalls, $extractor->calls, 'invalid post-like IDs should be rejected before extractor callbacks');

    foreach ([
        array_fill(0, 33, 'value'),
        [str_repeat('k', 192) => 'value'],
        ['title' => str_repeat('t', 140000), 'excerpt' => str_repeat('e', 140000)],
        ['terms' => ['category' => array_fill(0, 1023, 'term')], 'custom_fields' => ['signal' => array_fill(0, 1023, 'value')]],
    ] as $metadata) {
        $error = psic_caught(static fn(): array => WP_FTS_StorageCompat::normalize_doc_metadata($metadata));
        assert_true($error instanceof WP_FTS_Analysis_Limit_Exceeded, 'direct metadata normalization should share fixed key, node, and source-byte envelopes');
    }

    $magicMetadata = new class {
        public int $calls = 0;

        /** Fail if normalization invokes magic behavior on an untrusted object. */
        public function __get(string $name): mixed
        {
            $this->calls++;
            throw new RuntimeException('metadata magic access must not run');
        }
    };
    $normalizedMagic = WP_FTS_StorageCompat::normalize_doc_metadata(['custom_extra' => $magicMetadata]);
    assert_same('', $normalizedMagic['custom_extra'] ?? null, 'metadata objects without declared public data should normalize to an empty scalar');
    assert_same(0, $magicMetadata->calls, 'metadata normalization should not invoke magic object access');
});

test_case('set-oriented point mutations and dynamic rendering fail before callbacks, guards, or SQL', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $guardCalls = 0;
    $storage = new WP_FTS_Storage_Mysql($fake, null, static function () use (&$guardCalls): void {
        $guardCalls++;
        throw new RuntimeException('the mutation guard must not run');
    });
    $analyzer = new class {
        public int $calls = 0;

        /** Fail if relational point-mutation fences allow analyzer callbacks. */
        public function analyze_content(string $source, array $options = []): array
        {
            $this->calls++;
            throw new RuntimeException('the analyzer must not run');
        }

        /** Fail if a plain relational field bypasses the same mutation fence. */
        public function analyze_plain_content(string $source, array $options = []): array
        {
            $this->calls++;
            throw new RuntimeException('the analyzer must not run');
        }

        /** Provide the signature required to construct the fenced indexer. */
        public function index_signature(): string
        {
            return 'set-oriented-fence-test';
        }
    };
    $extractor = new class {
        public int $calls = 0;

        /** Fail if authoritative-snapshot checks invoke extraction first. */
        public function extract(object $post, array $options): array
        {
            $this->calls++;
            throw new RuntimeException('the extractor must not run');
        }
    };
    $indexer = new WP_FTS_Indexer($storage, $analyzer, $extractor);
    $post = (object) ['ID' => 1, 'terms' => [], 'custom_fields' => []];
    $mutationMessage = 'Set-oriented storage mutations must use the bounded batch writer.';

    foreach ([
        'index_document' => static fn(): bool => $indexer->index_document(-1, str_repeat('x', WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES + 1)),
        'index_document_fields' => static fn(): bool => $indexer->index_document_fields(-1, array_fill(0, 33, 'x')),
        'index_post' => static fn(): bool => $indexer->index_post((object) ['ID' => 0]),
        'delete_document' => static fn(): bool => $indexer->delete_document(-1),
    ] as $method => $mutation) {
        $error = psic_caught($mutation);
        assert_true($error instanceof LogicException, "{$method} should reject the relational point-mutation path");
        assert_same($mutationMessage, $error?->getMessage(), "{$method} should expose the exact bounded-writer contract");
    }

    $missingSnapshot = psic_caught(static fn(): array => $indexer->prepare_post_source((object) ['ID' => 1]));
    assert_true($missingSnapshot instanceof LogicException, 'relational post preparation should require authoritative attached dependencies');
    assert_same(
        'Set-oriented post preparation requires authoritative terms and custom_fields arrays.',
        $missingSnapshot?->getMessage(),
        'relational post preparation should expose the exact authoritative-snapshot contract'
    );

    $renderCallbackCalls = 0;
    foreach ([
        ['render_blocks' => true],
        ['render_shortcodes' => 1],
        ['render_content_callback' => static function () use (&$renderCallbackCalls): string {
            $renderCallbackCalls++;
            return 'dynamic';
        }],
        ['render_content_callback' => 'non-callable-but-non-null'],
    ] as $options) {
        $error = psic_caught(static fn(): array => $indexer->prepare_post_source($post, $options));
        assert_true($error instanceof WP_FTS_Analysis_Limit_Exceeded, 'relational dynamic rendering should be a permanent typed analysis rejection');
        assert_same('dynamic_rendering_not_set_oriented', $error instanceof WP_FTS_Analysis_Limit_Exceeded ? $error->reason_code : null, 'dynamic rendering should use the stable worker rejection reason');
        assert_same(
            'Dynamic rendering is unavailable in the bounded relational worker; index static post_content or provide precomputed attached fields.',
            $error?->getMessage(),
            'dynamic rendering should explain the static/precomputed replacement'
        );
    }

    assert_same(0, $renderCallbackCalls, 'the dynamic rendering callback must never execute');
    assert_same(0, $extractor->calls, 'relational fences must reject before extractor callbacks');
    assert_same(0, $analyzer->calls, 'relational fences must reject before analyzer callbacks');
    assert_same(0, $guardCalls, 'relational fences must reject before the mutation guard');
    assert_same(0, $fake->num_queries, 'relational fences must reject before SQL');
});

test_case('quality public search containment rejects custom analyzer expansion before storage', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $analysis = [];
    for ($index = 0; $index < 10000; $index++) {
        $analysis['occurrence_' . $index] = 'term';
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
    $searcher = WP_FTS_Searcher::for_set_oriented_storage(new WP_FTS_Storage_Mysql($fake), $analyzer);
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
        'position' => ['term' => 'term', 'lang' => 'en', 'position' => str_repeat('1', 65)],
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
        $boundedSearcher = WP_FTS_Searcher::for_set_oriented_storage(new WP_FTS_Storage_Mysql($fake), $boundedAnalyzer);
        $fieldError = psic_caught(static fn(): array => $boundedSearcher->search('term', ['prefix_matching' => false]));
        assert_true($fieldError instanceof WP_FTS_Search_Budget_Exceeded, "an oversized analyzer {$label} should be rejected before trim or canonicalization");
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
    $modeSearcher = WP_FTS_Searcher::for_set_oriented_storage(new WP_FTS_Storage_Mysql($fake), $modeAnalyzer);

    $wideSnippetOptions = [];
    for ($index = 0; $index < 65; $index++) {
        $wideSnippetOptions['custom_' . $index] = 'value';
    }
    $wideSnippetError = psic_caught(static fn(): string => $modeSearcher->snippet_for_text(
        'bounded source',
        'term',
        $wideSnippetOptions
    ));
    assert_true($wideSnippetError instanceof InvalidArgumentException, 'the public snippet API should reject more than 64 option keys before copying them into analyzer options');
    assert_same(0, $modeAnalyzer->calls, 'an over-wide snippet option map should be rejected before analyzer work');

    $nestedSnippetOption = 'leaf';
    for ($depth = 0; $depth < 10; $depth++) {
        $nestedSnippetOption = ['next' => $nestedSnippetOption];
    }
    $nestedSnippetError = psic_caught(static fn(): string => $modeSearcher->snippet_for_text(
        'bounded source',
        'term',
        ['custom_analyzer_option' => $nestedSnippetOption]
    ));
    assert_true($nestedSnippetError instanceof InvalidArgumentException, 'the public snippet API should reject an over-deep unknown analyzer option before forwarding it');
    assert_same(0, $modeAnalyzer->calls, 'an over-deep snippet option graph should be rejected before analyzer work');
    assert_same($before, $fake->num_queries, 'hostile public snippet option maps should execute no SQL');

    $modeError = psic_caught(static fn(): array => $modeSearcher->search('term', ['mode' => str_repeat('O', 4097)]));
    assert_true($modeError instanceof InvalidArgumentException, 'an oversized public mode should be rejected before strtoupper normalization');
    assert_same(0, $modeAnalyzer->calls, 'an invalid mode should be rejected before analyzer work');
    foreach ([
        ['cursor' => str_repeat('c', 2049)],
        ['prefix_matching' => str_repeat('y', 17)],
        ['date_after' => str_repeat('d', 65)],
        ['limit' => str_repeat('1', 65)],
        ['post_statues' => ['publish']],
        ['_empty_search_scope' => true],
        [0 => 'numeric option key'],
        ['include_total' => false],
        ['query_lang' => 'en', 'lang' => 'fr'],
        ['default_lang' => 'en', 'locale' => 'fr'],
        ['result_lang' => 'en', 'document_lang' => 'fr'],
        ['prefix_matching' => true, 'prefix' => false],
        ['include_snippets' => true, 'snippets' => false],
        ['date_after' => '2026-01-01', 'after' => '2026-01-02'],
        ['date_before' => '2026-01-02', 'post_date_before' => '2026-01-03'],
        ['recency_boost' => true, 'freshness_boost' => false],
        ['recency_boost_strength' => 1.0, 'freshness_boost_strength' => 2.0],
        ['recency_boost_half_life_days' => 30, 'freshness_boost_half_life_days' => 31],
        ['now_gmt' => '2026-01-01', 'recency_now' => '2026-01-02'],
        ['direction' => 'after'],
        ['after_cursor' => 'signed-token', 'direction' => 'before'],
        ['before_cursor' => 'signed-token', 'direction' => 'after'],
        ['cursor' => 'signed-token', 'direction' => 'AFTER'],
        ['cursor' => '   '],
        ['post_types' => array_fill(0, 33, 'post')],
        ['post_types' => ['']],
        ['post_types' => [false]],
        ['post_types' => ['post', '']],
        ['post_types' => 'post,'],
        ['post_type' => ['post'], 'post_types' => ['page']],
        ['post_statuses' => ['   ']],
        ['post_status' => ['publish'], 'post_statuses' => ['draft']],
        ['post_statuses' => null],
        ['_search_ready_incarnation' => str_repeat('a', 31)],
        ['_search_ready_incarnation' => str_repeat('A', 32)],
        ['_search_ready_incarnation' => ' ' . str_repeat('a', 32)],
        ['_search_ready_incarnation' => 1234],
        ['_search_ready_profile_hash' => str_repeat('b', 39)],
        ['_search_ready_profile_hash' => str_repeat('B', 40)],
        ['_search_ready_profile_hash' => ' ' . str_repeat('b', 40)],
        ['_search_ready_profile_hash' => 1234],
        array_fill(0, 100000, 'unknown'),
    ] as $options) {
        $optionError = psic_caught(static fn(): array => $modeSearcher->search('term', $options));
        assert_true($optionError instanceof InvalidArgumentException, 'set-oriented public options should be bounded before analyzer work');
    }
    $cursorResource = fopen('php://memory', 'rb');
    assert_true(is_resource($cursorResource), 'the hostile cursor fixture should allocate a resource');
    try {
        foreach (['cursor', 'after_cursor', 'before_cursor'] as $cursorAlias) {
            foreach ([["nested"], new stdClass(), $cursorResource, false, 0] as $cursorValue) {
                $optionError = psic_caught(static fn(): array => $modeSearcher->search('term', [
                    $cursorAlias => $cursorValue,
                ]));
                assert_true(
                    $optionError instanceof InvalidArgumentException,
                    "a non-string {$cursorAlias} must be rejected before analyzer work"
                );
            }
        }
        foreach (['phrase', 'prefix_matching', 'prefix', 'include_metadata', 'include_snippets', 'snippets', '_include_canonical_post_rows', 'highlight', 'explain', 'debug'] as $switchKey) {
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
            'offset' => [-1, 1, false, 0.0, '00', '0.0', 'nonsense', NAN, INF, [], $cursorResource],
            'limit' => [0, 51, false, 1.0, '01', '1.0', 'nonsense', NAN, INF, [], $cursorResource],
            'max_query_terms' => [0, 13, false, 1.0, '01', '1.0', 'nonsense', NAN, INF, [], $cursorResource],
            'prefix_min_length' => [-1, 0, 1, 256, false, 1.0, '01', '1.0', 'nonsense', NAN, INF, [], $cursorResource],
            'snippet_length' => [0, 501, false, 1.0, '01', '1.0', 'nonsense', NAN, INF, [], $cursorResource],
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
            'recency_boost' => [-0.1, 2.1, '', '01', '1e0', 'nonsense', NAN, INF, [], $cursorResource],
            'freshness_boost' => [-0.1, 2.1, '', '01', '1e0', 'nonsense', NAN, INF, [], $cursorResource],
            'recency_boost_strength' => [false, -0.1, 2.1, '', '01', '1e0', 'nonsense', NAN, INF, [], $cursorResource],
            'freshness_boost_strength' => [false, -0.1, 2.1, '', '01', '1e0', 'nonsense', NAN, INF, [], $cursorResource],
            'recency_boost_half_life_days' => [false, 0, 3651, '', '01', '1e0', 'nonsense', NAN, INF, [], $cursorResource],
            'freshness_boost_half_life_days' => [false, 0, 3651, '', '01', '1e0', 'nonsense', NAN, INF, [], $cursorResource],
            'recency_boost_window_days' => [false, 0, 3651, '', '01', '1e0', 'nonsense', NAN, INF, [], $cursorResource],
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
        foreach (['date_after', 'after', 'post_date_after', 'date_before', 'before', 'post_date_before', 'now_gmt', 'recency_now'] as $dateKey) {
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
        foreach ([null, false, 0, 'guard', [], new stdClass(), $cursorResource] as $guard) {
            $optionError = psic_caught(static fn(): array => $modeSearcher->search('term', [
                'request_budget_guard' => $guard,
            ]));
            assert_true($optionError instanceof InvalidArgumentException, 'a noncallable request budget guard must be rejected before analyzer work');
        }
    } finally {
        fclose($cursorResource);
    }
    assert_same(0, $modeAnalyzer->calls, 'cursor, switch, date, numeric, and filter failures should all precede analyzer work');
    assert_same($before, $fake->num_queries, 'all analyzer-bound failures should leave storage untouched');

    $exactCursor = ' signed.cursor.bytes ';
    assert_same(
        $exactCursor,
        psic_searcher_private($modeSearcher, 'set_oriented_cursor_value', $exactCursor),
        'cursor validation must preserve every nonblank signed byte instead of trimming it'
    );
    $guardCalls = 0;
    $boundaryPage = $modeSearcher->search('term', [
        'query_lang' => 'en_US',
        'lang' => 'en-US',
        'prefix_matching' => '1',
        'prefix' => true,
        'include_snippets' => '0',
        'snippets' => false,
        'offset' => '0',
        'limit' => '50',
        'max_query_terms' => '12',
        'prefix_min_length' => '255',
        'snippet_length' => '500',
        'recency_boost_strength' => '2.0',
        'freshness_boost_strength' => 2,
        'recency_boost_half_life_days' => '3650',
        'freshness_boost_half_life_days' => 3650.0,
        'date_after' => '2026-01-01',
        'after' => '2026-01-01 00:00:00',
        'now_gmt' => '2026-01-02T00:00:00',
        'recency_now' => '2026-01-02 00:00:00',
        'request_budget_guard' => static function () use (&$guardCalls): bool {
            $guardCalls++;
            return true;
        },
    ]);
    assert_same([], $boundaryPage['results'] ?? null, 'the exact strict option boundaries should retain an analyzer-empty first page');
    assert_same(1, $modeAnalyzer->calls, 'one valid strict-boundary request should invoke the analyzer exactly once');
    assert_true($guardCalls >= 1, 'a valid callable request budget guard must remain active');
    assert_same($before, $fake->num_queries, 'an analyzer-empty strict-boundary page should not execute storage SQL');

    $snippetAnalysis = [];
    for ($index = 0; $index < 10000; $index++) {
        $snippetAnalysis['snippet_' . $index] = ['term' => 'term', 'surface' => 'term', 'lang' => 'en'];
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
    $snippetSearcher = WP_FTS_Searcher::for_set_oriented_storage(new WP_FTS_Storage_Mysql($fake), $snippetAnalyzer);
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
            ['after_cursor' => str_repeat('c', 2049)],
            ['before_cursor' => str_repeat('c', 2049)],
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

        assert_same('after', psic_plugin_private('search_cursor_direction', str_repeat('b', 4097)), 'the WordPress direction adapter should not normalize an oversized scalar');
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
            ['_empty_search_scope' => true],
            ['_search_ready_incarnation' => str_repeat('a', 32)],
            ['include_total' => false],
            ['phrase' => false],
            ['explain' => false],
            ['debug' => false],
            [0 => 'numeric key'],
            array_fill(0, 100000, 'unknown'),
            ['mode' => false],
            ['mode' => 'XOR'],
            ['offset' => 1],
            ['offset' => 0.0],
            ['offset' => '00'],
            ['limit' => false],
            ['limit' => 1.0],
            ['limit' => '01'],
            ['limit' => 51],
            ['lang' => null],
            ['lang' => false],
            ['cursor' => null],
            ['cursor' => '   '],
            ['cursor' => false],
            ['cursor' => str_repeat('c', 2049)],
            ['direction' => 'after'],
            ['cursor' => 'signed', 'direction' => 'AFTER'],
            ['after_cursor' => 'signed', 'before_cursor' => 'signed'],
            ['after_cursor' => 'signed', 'direction' => 'before'],
            ['prefix_matching' => 'maybe'],
            ['prefix_matching' => true, 'prefix' => false],
            ['include_snippets' => true, 'snippets' => false],
            ['post_type' => ['post'], 'post_types' => ['post']],
            ['post_type' => false],
            ['post_types' => null],
            ['post_types' => ['post', '']],
            ['post_status' => ['publish'], 'post_statuses' => ['publish']],
            ['post_statuses' => [false]],
            ['date_after' => null],
            ['date_after' => '2026-02-30'],
            ['date_before' => ' tomorrow '],
            ['recency_boost' => true, 'freshness_boost' => false],
            ['recency_boost_strength' => 1.0, 'freshness_boost_strength' => 2.0],
            ['recency_boost_half_life_days' => 30, 'recency_boost_window_days' => 31],
            ['now_gmt' => '2026-01-01', 'recency_now' => '2026-01-02'],
            ['request_budget_guard' => 'not-callable'],
        ];
        $before = $fake->num_queries;
        foreach ($invalidOptions as $options) {
            $error = psic_caught(static fn(): array => WP_FTS_Plugin::search_page('typed boundary', $options));
            assert_true($error instanceof InvalidArgumentException, 'the WordPress facade must reject malformed options before readiness or storage');
        }
        assert_same($before, $fake->num_queries, 'all exact public option-boundary failures should execute zero SQL');

        $guardCalls = 0;
        $normalized = psic_plugin_private('normalize_public_search_options', [
            'mode' => 'and',
            'offset' => '0',
            'limit' => '50',
            'after_cursor' => ' exact.signed.bytes ',
            'prefix' => 'yes',
            'snippets' => 'no',
            'post_type' => 'post,page',
            'post_status' => ['publish'],
            'recency_boost' => 'true',
            'freshness_boost' => 0.25,
            'freshness_boost_strength' => '2.0',
            'recency_boost_window_days' => '3650',
            'recency_now' => '2026-01-01T00:00:00',
            'request_budget_guard' => static function () use (&$guardCalls): bool {
                $guardCalls++;
                return true;
            },
        ]);
        assert_same('AND', $normalized['mode'] ?? null, 'mode should canonicalize without permissive casting');
        assert_same(50, $normalized['limit'] ?? null, 'the exact page boundary should normalize to an integer');
        assert_same(' exact.signed.bytes ', $normalized['cursor'] ?? null, 'cursor aliases must preserve every signed byte');
        assert_same('after', $normalized['direction'] ?? null, 'after_cursor should canonicalize its direction');
        assert_same(true, $normalized['prefix_matching'] ?? null, 'the supported prefix alias should canonicalize once');
        assert_same(false, $normalized['include_snippets'] ?? null, 'the supported snippet alias should canonicalize once');
        assert_same(['page', 'post'], $normalized['post_types'] ?? null, 'singular scope input should become one sorted canonical list');
        assert_float_near(0.25, (float) ($normalized['recency_boost'] ?? -1), 'matching recency toggle aliases should retain their effective strength');
        assert_float_near(2.0, (float) ($normalized['recency_boost_strength'] ?? -1), 'freshness strength should map to the canonical recency option');
        assert_float_near(3650.0, (float) ($normalized['recency_boost_half_life_days'] ?? -1), 'recency window should map to canonical half-life');
        assert_same('2026-01-01T00:00:00', $normalized['now_gmt'] ?? null, 'recency clock should map to the canonical clock without changing bytes');
        assert_true(is_callable($normalized['request_budget_guard'] ?? null), 'the valid request guard should survive canonicalization');
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
        $storage = WP_FTS_Plugin::storage(false);
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

test_case('quality WP CLI search containment bounds cursor language and CSV options before parsing', function (): void {
    $command = new WP_FTS_WPCLI_Command();

    foreach ([
        ['lang' => str_repeat('l', 65)],
        ['cursor' => str_repeat('c', 2049)],
        ['direction' => str_repeat('d', 9), 'cursor' => 'cursor'],
        ['post_status' => str_repeat('s', 4097)],
        ['post_type' => implode(',', array_map(static fn(int $index): string => 'type' . $index, range(1, 33)))],
        ['post_status' => str_repeat('s', 65)],
        ['mode' => str_repeat('m', 9)],
        ['limit' => str_repeat('1', 65)],
        ['after' => str_repeat('d', 65)],
        ['prefix_matching' => str_repeat('y', 17)],
    ] as $args) {
        $error = psic_caught(static fn(): array => psic_cli_private($command, 'search_options_from_cli_args', $args));
        assert_true($error instanceof InvalidArgumentException, 'WP-CLI should reject over-wide search options before trim or CSV expansion');
    }

    $boundary = psic_cli_private($command, 'search_options_from_cli_args', [
        'lang' => str_repeat('l', 64),
        'cursor' => str_repeat('c', 2048),
        'post_type' => implode(',', array_map(static fn(int $index): string => 't' . $index, range(1, 32))),
    ]);
    assert_same(64, strlen((string) ($boundary['lang'] ?? '')), 'the exact CLI language boundary should remain accepted');
    assert_same(2048, strlen((string) ($boundary['cursor'] ?? '')), 'the exact CLI cursor boundary should remain accepted');
    assert_same(32, count($boundary['post_type'] ?? []), 'the exact CLI filter cardinality boundary should remain accepted');
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

    foreach ([
        [str_repeat('1', 65)],
        [new stdClass()],
    ] as $ids) {
        $error = psic_caught(static fn(): int => $queue->enqueue_many($ids));
        assert_true($error instanceof InvalidArgumentException, 'queue batches should reject non-scalar or overlong ids before integer casts');
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

    assert_same(false, $queue->acknowledge([
        'post_id' => str_repeat('1', 65),
        'generation' => 1,
        'token' => 'claim-token',
    ]), 'post claims should reject overlong numeric values before integer casts');
    assert_same(false, $queue->commit_scope_page([
        'job_key' => 'scope:bounded',
        'generation' => str_repeat('1', 65),
        'token' => 'claim-token',
    ], [], 1), 'scope claims should reject overlong generations before integer casts');
    assert_same('lost', $queue->fail_scope([
        'job_key' => 'scope:bounded',
        'generation' => 1,
        'token' => 'claim-token',
        'attempts' => str_repeat('1', 65),
    ])['status'], 'scope failures should reject overlong attempt counters before integer casts');
    assert_same(false, $queue->release([
        'job_key' => str_repeat('j', 192),
        'post_id' => 1,
        'generation' => 1,
        'token' => str_repeat('l', 65),
    ]), 'post releases should reject noncanonical job keys and overlong CAS tokens before SQL');
    assert_same(false, $queue->acknowledge(array_fill(0, 100000, 'unknown')), 'claim maps should reject explosive cardinality before normalization');
    assert_same($before, $fake->num_queries, 'all hostile queue inputs should fail before SQL');
});

test_case('quality extractor filters share fixed metadata and field-boost envelopes', function (): void {
    $extractor = new WP_FTS_PostContentExtractor();
    $post = (object) [
        'ID' => 7101,
        'post_title' => '',
        'post_content' => '',
        'post_excerpt' => '',
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-07-18 00:00:00',
        'terms' => [],
        'custom_fields' => [],
    ];

    $customFieldNodeError = psic_caught(static fn(): array => $extractor->extract($post, [
        'custom_fields' => array_fill(0, 2048, ''),
    ]));
    assert_true($customFieldNodeError instanceof WP_FTS_Analysis_Limit_Exceeded, 'repeated custom-field option values should stop at a fixed traversal budget even when deduplication would leave one key');
    assert_same('custom_field_key_nodes', $customFieldNodeError instanceof WP_FTS_Analysis_Limit_Exceeded ? $customFieldNodeError->reason_code : null, 'custom-field option traversal should have a stable node-limit reason');

    $deepCustomFields = 'key';
    for ($depth = 0; $depth < 17; $depth++) {
        $deepCustomFields = [$deepCustomFields];
    }
    $customFieldDepthError = psic_caught(static fn(): array => $extractor->extract($post, [
        'filters' => [
            'wp_fts_post_custom_fields' => static fn(): array => $deepCustomFields,
        ],
    ]));
    assert_true($customFieldDepthError instanceof WP_FTS_Analysis_Limit_Exceeded, 'deeply nested custom-field filter values should stop before recursive stack growth');
    assert_same('custom_field_key_depth', $customFieldDepthError instanceof WP_FTS_Analysis_Limit_Exceeded ? $customFieldDepthError->reason_code : null, 'custom-field filter nesting should have a stable depth-limit reason');

    $customFieldSourceError = psic_caught(static fn(): array => $extractor->normalize_selected_custom_field_keys(
        array_fill(0, 33, str_repeat('k', WP_FTS_PostContentExtractor::MAX_CUSTOM_FIELD_KEY_BYTES))
    ));
    assert_true($customFieldSourceError instanceof WP_FTS_Analysis_Limit_Exceeded, 'repeated custom-field scalars should share one aggregate source-byte envelope');
    assert_same('custom_field_key_source_bytes', $customFieldSourceError instanceof WP_FTS_Analysis_Limit_Exceeded ? $customFieldSourceError->reason_code : null, 'custom-field aggregate bytes should have a stable typed reason');

    $magicCustomField = new class {
        public int $calls = 0;

        /** Fail if custom-field normalization invokes magic option access. */
        public function __get(string $name): mixed
        {
            $this->calls++;
            throw new RuntimeException('magic option access must not run');
        }
    };
    assert_same([], $extractor->normalize_selected_custom_field_keys([$magicCustomField]), 'objects without declared public key data should contribute no custom-field names');
    assert_same(0, $magicCustomField->calls, 'custom-field normalization should not invoke option-object magic access');

    $optionBoostError = psic_caught(static fn(): array => $extractor->extract($post, [
        'field_boosts' => array_fill(0, 33, 1),
    ]));
    assert_true($optionBoostError instanceof WP_FTS_Analysis_Limit_Exceeded, 'field-boost options above 32 entries should be rejected before copying the map');
    assert_same('field_boosts', $optionBoostError instanceof WP_FTS_Analysis_Limit_Exceeded ? $optionBoostError->reason_code : null, 'option field-boost cardinality should have a stable typed reason');

    $filteredBoostError = psic_caught(static fn(): array => $extractor->extract($post, [
        'filters' => [
            'wp_fts_post_field_boosts' => static fn(): array => array_fill(0, 33, 1),
        ],
    ]));
    assert_true($filteredBoostError instanceof WP_FTS_Analysis_Limit_Exceeded, 'filtered field boosts above 32 entries should be rejected before normalization');
    assert_same('field_boosts', $filteredBoostError instanceof WP_FTS_Analysis_Limit_Exceeded ? $filteredBoostError->reason_code : null, 'filtered field-boost cardinality should have a stable typed reason');

    $metadataCountError = psic_caught(static fn(): array => $extractor->extract($post, [
        'filters' => [
            'wp_fts_post_index_metadata' => static function (array $metadata): array {
                for ($index = 1; $index <= 22; $index++) {
                    $metadata['extra_' . $index] = 'value';
                }

                return $metadata;
            },
        ],
    ]));
    assert_true($metadataCountError instanceof WP_FTS_Analysis_Limit_Exceeded, 'filtered metadata above 32 keys should be rejected before copy-on-write normalization');
    assert_same('metadata_keys', $metadataCountError instanceof WP_FTS_Analysis_Limit_Exceeded ? $metadataCountError->reason_code : null, 'filtered metadata cardinality should have a stable typed reason');

    $mapCountError = psic_caught(static fn(): array => $extractor->extract($post, [
        'filters' => [
            'wp_fts_post_index_metadata' => static function (array $metadata): array {
                $metadata['terms'] = array_fill(0, 33, ['value']);

                return $metadata;
            },
        ],
    ]));
    assert_true($mapCountError instanceof WP_FTS_Analysis_Limit_Exceeded, 'filtered structured maps above 32 keys should be rejected before traversing values');
    assert_same('structured_map_keys', $mapCountError instanceof WP_FTS_Analysis_Limit_Exceeded ? $mapCountError->reason_code : null, 'structured-map cardinality should have a stable typed reason');

    $sharedNodeError = psic_caught(static fn(): array => $extractor->extract($post, [
        'filters' => [
            'wp_fts_post_index_metadata' => static function (array $metadata): array {
                $metadata['terms'] = ['category' => array_fill(0, 1023, 'term')];
                $metadata['custom_fields'] = ['signal' => array_fill(0, 1023, 'value')];

                return $metadata;
            },
        ],
    ]));
    assert_true($sharedNodeError instanceof WP_FTS_Analysis_Limit_Exceeded, 'terms and custom fields should share one 2,048-node traversal budget');
    assert_same('structured_value_nodes', $sharedNodeError instanceof WP_FTS_Analysis_Limit_Exceeded ? $sharedNodeError->reason_code : null, 'shared structured traversal should fail with the node-limit reason');

    $sharedSourceError = psic_caught(static fn(): array => $extractor->extract($post, [
        'filters' => [
            'wp_fts_post_index_metadata' => static function (array $metadata): array {
                $metadata['terms'] = ['category' => [str_repeat('t', 140000)]];
                $metadata['custom_fields'] = ['signal' => [str_repeat('v', 140000)]];

                return $metadata;
            },
        ],
    ]));
    assert_true($sharedSourceError instanceof WP_FTS_Analysis_Limit_Exceeded, 'terms and custom fields should share one 256 KiB source-text budget');
    assert_same('structured_source_bytes', $sharedSourceError instanceof WP_FTS_Analysis_Limit_Exceeded ? $sharedSourceError->reason_code : null, 'shared structured source should fail before HTML normalization');

    $boundaryBoosts = [];
    for ($index = 1; $index <= 32; $index++) {
        $boundaryBoosts['field_' . $index] = 1;
    }
    $boundary = $extractor->extract($post, [
        'filters' => [
            'wp_fts_post_field_boosts' => static fn(): array => $boundaryBoosts,
            'wp_fts_post_index_metadata' => static function (array $metadata): array {
                $metadata['terms'] = ['category' => array_fill(0, 1022, 'term')];
                $metadata['custom_fields'] = ['signal' => array_fill(0, 1022, 'value')];

                return $metadata;
            },
        ],
    ]);
    assert_same(32, count($boundary['field_boosts'] ?? []), 'the exact 32-field-boost boundary should remain accepted');
    assert_same(['term'], $boundary['metadata']['terms']['category'] ?? null, 'the exact shared 2,048-node boundary should remain accepted');
    assert_same(['value'], $boundary['metadata']['custom_fields']['signal'] ?? null, 'the shared node boundary should include both structured maps');
});

test_case('quality MySQL set-oriented APIs reject explosive inputs before normalization', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $storage = new WP_FTS_Storage_Mysql($fake);
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

    $idError = psic_caught(static fn(): array => $storage->document_hashes(array_fill(0, 101, 1)));
    assert_true($idError instanceof InvalidArgumentException, 'bounded document reads should reject raw cardinality before array_map/filter/unique copies');

    $deleteError = psic_caught(static fn(): array => $storage->replace_prepared_documents([], array_fill(0, 101, 1)));
    assert_true($deleteError instanceof InvalidArgumentException, 'prepared writes should reject raw delete cardinality before traversal');

    foreach ([
        'put_term',
        'get_meta',
        'replace_doc_postings',
        'put_doc',
        'put_doc_metadata',
        'delete_doc',
    ] as $method) {
        assert_true(!method_exists($storage, $method), "production storage should not expose legacy {$method}");
    }
    assert_same($before, $fake->num_queries, 'legacy capability inspection should not execute SQL');

    $preparedLanguageError = psic_caught(static fn(): array => $storage->replace_prepared_documents([[
        'doc_id' => 1,
        'primary_lang' => str_repeat('l', 65),
        'term_frequencies' => [],
    ]]));
    assert_true($preparedLanguageError instanceof WP_FTS_Prepared_Document_Rejected, 'prepared document languages should be bounded before canonicalization');

    $preparedIdError = psic_caught(static fn(): array => $storage->replace_prepared_documents([[
        'doc_id' => str_repeat('1', 65),
        'term_frequencies' => [],
    ]]));
    assert_true($preparedIdError instanceof WP_FTS_Prepared_Document_Rejected, 'prepared document ids should be bounded before integer validation');

    $preparedFrequencyError = psic_caught(static fn(): array => $storage->replace_prepared_documents([[
        'doc_id' => 1,
        'term_frequencies' => [WP_FTS_TermNamespace::namespace_term('en', 'term') => str_repeat('1', 65)],
    ]]));
    assert_true($preparedFrequencyError instanceof WP_FTS_Prepared_Document_Rejected, 'prepared term frequencies should be bounded before integer validation');

    $preparedSnippetError = psic_caught(static fn(): array => $storage->replace_prepared_documents([[
        'doc_id' => 1,
        'term_frequencies' => [],
        'metadata' => ['content_search_text' => str_repeat('s', WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES + 1)],
    ]]));
    assert_true($preparedSnippetError instanceof WP_FTS_Prepared_Document_Rejected, 'prepared snippet sources should be bounded before UTF-8 processing');

    $preparedDeleteError = psic_caught(static fn(): array => $storage->replace_prepared_documents([], [str_repeat('1', 65)]));
    assert_true($preparedDeleteError instanceof WP_FTS_Prepared_Document_Rejected, 'prepared delete ids should be bounded before integer validation');

    $filterError = psic_caught(static fn(): array => $storage->search_page(
        [[['key' => WP_FTS_TermNamespace::namespace_term('en', 'term'), 'rank' => 0]]],
        ['query_lang' => 'en', 'post_types' => [str_repeat('p', 65)]]
    ));
    assert_true($filterError instanceof InvalidArgumentException, 'direct storage filters should check bytes before trim');
    foreach (['fast_top_k', 'approximate_top_k', 'exact_top_k', 'exact', 'candidate_cap', 'max_candidates'] as $legacyRetrievalOption) {
        $legacyOptionError = psic_caught(static fn(): array => $storage->search_page(
            [[['key' => WP_FTS_TermNamespace::namespace_term('en', 'term'), 'rank' => 0]]],
            ['query_lang' => 'en', $legacyRetrievalOption => false]
        ));
        assert_true(
            $legacyOptionError instanceof InvalidArgumentException
                && str_contains($legacyOptionError->getMessage(), $legacyRetrievalOption),
            "direct relational storage should reject legacy {$legacyRetrievalOption} instead of silently changing or ignoring retrieval semantics"
        );
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
            ['query_lang' => 'en'] + $options
        ));
        assert_true($optionError instanceof InvalidArgumentException, 'direct storage mode and direction values should be bounded and enumerated before normalization');
    }

    $rankError = psic_caught(static fn(): array => $storage->search_page(
        [[['key' => WP_FTS_TermNamespace::namespace_term('en', 'term'), 'rank' => str_repeat('1', 65)]]],
        ['query_lang' => 'en']
    ));
    assert_true($rankError instanceof InvalidArgumentException, 'direct storage alternative ranks should be bounded before integer normalization');

    assert_same($before, $fake->num_queries, 'all direct input rejections should happen before SQL');

    $guardCalls = 0;
    $guardedStorage = new WP_FTS_Storage_Mysql($fake, null, static function () use (&$guardCalls): void {
        $guardCalls++;
    });
    foreach (['get_doc', 'get_doc_metadata', 'terms_for_doc', 'put_doc', 'put_doc_metadata', 'delete_doc'] as $method) {
        assert_true(!method_exists($guardedStorage, $method), "guarded production storage should not expose {$method}");
    }
    assert_same(0, $guardCalls, 'capability inspection should not invoke the mutation guard');
    psic_caught(static fn(): array => $guardedStorage->replace_prepared_documents([[
        'doc_id' => 1,
        'term_frequencies' => [],
        'metadata' => ['search_text' => str_repeat('s', WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES + 1)],
    ]]));
    assert_same(0, $guardCalls, 'prepared-document input validation should finish before invoking a potentially SQL-backed mutation guard');
});
