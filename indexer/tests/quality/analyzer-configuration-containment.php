<?php
declare(strict_types=1);

/** Capture one expected configuration failure without hiding its exact type. */
function acc_caught(callable $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $error) {
        return $error;
    }

    return null;
}

final class WP_FTS_Analyzer_Config_Probe_Stream
{
    public mixed $context = null;
    public static int $filesystemCalls = 0;

    /** Count an attempted stat so pre-filesystem rejection stays observable. */
    public function url_stat(string $path, int $flags): array|false
    {
        self::$filesystemCalls++;
        return false;
    }

    /** Count an attempted open so oversized graphs cannot silently probe paths. */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$filesystemCalls++;
        return false;
    }
}

/** Register the inert filesystem probe once for all configuration cases. */
function acc_register_probe_stream(): void
{
    $scheme = 'wpftsconfigprobe';
    if (!in_array($scheme, stream_get_wrappers(), true)) {
        assert_true(stream_wrapper_register($scheme, WP_FTS_Analyzer_Config_Probe_Stream::class), 'configuration probe stream wrapper should register');
    }
}

test_case('quality analyzer configuration rejects one hundred thousand languages in constant validation work', function (): void {
    $scheme = 'wpftsconfigprobe';
    acc_register_probe_stream();
    WP_FTS_Analyzer_Config_Probe_Stream::$filesystemCalls = 0;

    $memoryBefore = memory_get_usage(true);
    $started = microtime(true);
    $hostileMap = array_fill(0, 100000, $scheme . '://manifest.json');
    $error = acc_caught(static fn(): WP_FTS_LanguagePipeline => new WP_FTS_LanguagePipeline([
        'lemma_packs_by_lang' => $hostileMap,
    ]));
    $elapsed = microtime(true) - $started;
    $memoryGrowth = max(0, memory_get_usage(true) - $memoryBefore);

    assert_true($error instanceof WP_FTS_Analyzer_Config_Limit_Exceeded, 'a 100,000-entry component language map should fail with the typed configuration limit');
    assert_same('configured_languages', $error instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $error->reason_code : null, 'oversized maps should be rejected by O(1) language cardinality before graph traversal');
    assert_same(0, WP_FTS_Analyzer_Config_Probe_Stream::$filesystemCalls, 'oversized maps should cause no manifest stat or open');
    assert_true($elapsed < 1.0, 'constructing and rejecting the complete 100,000-entry fixture should remain below one second');
    assert_true($memoryGrowth <= 32 * 1024 * 1024, 'the complete 100,000-entry fixture plus rejection should remain within 32 MiB');
    unset($hostileMap);
});

test_case('quality analyzer configuration bounds maps generators captures graph shape and paths', function (): void {
    $boundary = [];
    for ($number = 0; $number < WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_LANGUAGES; $number++) {
        $boundary['q' . str_pad((string) $number, 2, '0', STR_PAD_LEFT)] = false;
    }
    $pipeline = new WP_FTS_LanguagePipeline(['lemma_packs_by_lang' => $boundary]);
    assert_true(str_starts_with($pipeline->index_signature(), 'wp-fts-language-pipeline-v20:'), 'the exact 32-language disabled-pack boundary should remain valid');

    $otherBoundary = [];
    for ($number = 0; $number < WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_LANGUAGES; $number++) {
        $otherBoundary['r' . str_pad((string) $number, 2, '0', STR_PAD_LEFT)] = false;
    }
    $lemmaHalf = array_slice($boundary, 0, 16, true);
    $segmenterHalf = array_slice($otherBoundary, 0, 17, true);
    $crossKindError = acc_caught(static fn(): WP_FTS_LanguagePipeline => new WP_FTS_LanguagePipeline([
        'lemma_packs_by_lang' => $lemmaHalf,
        'segmenter_packs_by_lang' => $segmenterHalf,
    ]));
    assert_same('configured_languages', $crossKindError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $crossKindError->reason_code : null, 'lemmatizer and tokenizer maps should share one 32-language envelope');

    $iterations = 0;
    $lazyMap = (static function () use (&$iterations): Generator {
        while (true) {
            $iterations++;
            yield 'qaa-' . $iterations => false;
        }
    })();
    $lazyError = acc_caught(static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer([
        'lemma_packs_by_lang' => $lazyMap,
    ]));
    assert_true($lazyError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded, 'a Traversable language map should fail closed instead of being consumed');
    assert_same('language_map_type', $lazyError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $lazyError->reason_code : null, 'lazy maps should have a stable type-limit reason');
    assert_same(0, $iterations, 'configuration validation must not advance a lazy or infinite language-map generator');

    $captured = array_fill(0, 100000, 'captured');
    $callbackCalls = 0;
    $tokenizer = static function (string $run) use ($captured, &$callbackCalls): array {
        $callbackCalls++;
        return [$run];
    };
    $captureStarted = microtime(true);
    WP_FTS_Analyzer_Config_Probe_Stream::$filesystemCalls = 0;
    $captureError = acc_caught(static fn(): WP_FTS_LanguagePipeline => new WP_FTS_LanguagePipeline([
        'cjk_tokenizer' => $tokenizer,
        'lemma_packs_by_lang' => ['qaa' => 'wpftsconfigprobe://manifest.json'],
    ]));
    assert_true($captureError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded, 'constructor preflight should reject a callback capture with 100,000 nodes');
    assert_same(0, $callbackCalls, 'captured-state validation must not invoke the callback');
    assert_same(0, WP_FTS_Analyzer_Config_Probe_Stream::$filesystemCalls, 'callback capture validation should run before opening a configured pack');
    assert_true(microtime(true) - $captureStarted < 1.0, 'captured-state cardinality should reject in bounded time');

    $resolver = static function (array $options) use ($captured): ?string {
        return isset($options['lang'], $captured[0]) ? (string) $options['lang'] : null;
    };
    WP_FTS_Analyzer_Config_Probe_Stream::$filesystemCalls = 0;
    $resolverError = acc_caught(static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer([
        'document_language_resolver' => $resolver,
        'lemma_packs_by_lang' => ['qaa' => 'wpftsconfigprobe://manifest.json'],
    ]));
    assert_true($resolverError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded, 'an analyzer resolver capture with 100,000 nodes should fail at constructor preflight');
    assert_same(0, WP_FTS_Analyzer_Config_Probe_Stream::$filesystemCalls, 'analyzer resolver validation should run before opening a configured pack');
    unset($captured, $tokenizer, $resolver);

    $deep = 'leaf';
    for ($depth = 0; $depth <= WP_FTS_Analyzer_Config_Limits::MAX_OPTION_GRAPH_DEPTH; $depth++) {
        $deep = ['child' => $deep];
    }
    $depthError = acc_caught(static function () use ($deep): void {
        WP_FTS_Analyzer_Config_Limits::assert_option_graph($deep);
    });
    assert_same('analyzer_option_depth', $depthError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $depthError->reason_code : null, 'option graphs should have an exact depth fence');

    $wideNodes = [];
    for ($group = 0; $group < 9; $group++) {
        $wideNodes['group-' . $group] = array_fill(0, 256, true);
    }
    $nodeError = acc_caught(static function () use ($wideNodes): void {
        WP_FTS_Analyzer_Config_Limits::assert_option_graph($wideNodes);
    });
    assert_same('analyzer_option_nodes', $nodeError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $nodeError->reason_code : null, 'option graphs should have an exact aggregate node fence');

    $byteError = acc_caught(static function (): void {
        WP_FTS_Analyzer_Config_Limits::assert_option_graph([
            'payload' => array_fill(0, 70, str_repeat('b', 1000)),
        ]);
    });
    assert_same('analyzer_option_bytes', $byteError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $byteError->reason_code : null, 'option graphs should have an aggregate scalar-byte fence');

    $keyError = acc_caught(static function (): void {
        WP_FTS_Analyzer_Config_Limits::assert_option_graph([
            str_repeat('k', WP_FTS_Analyzer_Config_Limits::MAX_OPTION_KEY_BYTES + 1) => true,
        ]);
    });
    assert_same('analyzer_option_key_bytes', $keyError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $keyError->reason_code : null, 'option graph keys should have an exact byte fence');

    $pathError = acc_caught(static fn(): ?string => WP_FTS_LanguageLemmaPack::manifest_path_from_option(
        str_repeat('p', WP_FTS_Analyzer_Config_Limits::MAX_PATH_BYTES + 1)
    ));
    assert_same('path_bytes', $pathError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $pathError->reason_code : null, 'pack paths should be rejected before trim or filesystem access');

    if (WP_FTS_AnalyzerPackValidator::gzip_available()) {
        $fullPolish = WP_FTS_AnalyzerPackValidator::default_polish_playground_full_manifest();
        if (is_file($fullPolish)) {
            $metadataError = acc_caught(static fn(): WP_FTS_LanguagePipeline => new WP_FTS_LanguagePipeline([
                'lemma_packs_by_lang' => [
                    'pl' => $fullPolish,
                    'pl-PL' => $fullPolish,
                ],
            ]));
            assert_same(null, $metadataError, 'language aliases should count one physical pack once inside the 128-file, 16,384-block, and 32-MiB configured envelope');
        }
    }
});

test_case('quality WordPress analyzer configuration fails first search before SQL filesystem access or generator iteration', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    acc_register_probe_stream();
    WP_FTS_Analyzer_Config_Probe_Stream::$filesystemCalls = 0;
    $hostileMap = array_fill(0, 100000, 'wpftsconfigprobe://manifest.json');
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION] = [
        'lemma_packs_by_lang' => $hostileMap,
    ];
    WP_FTS_Plugin::reset_request_caches();

    try {
        $beforeQueries = $fake->num_queries;
        $started = microtime(true);
        $error = acc_caught(static fn(): array => WP_FTS_Plugin::search_page('bounded analyzer configuration'));
        $elapsed = microtime(true) - $started;

        assert_true($error instanceof WP_FTS_Search_Unavailable, 'a corrupt stored analyzer map should fail the public search boundary closed');
        assert_same($beforeQueries, $fake->num_queries, 'stored analyzer-map rejection should execute zero search SQL statements');
        assert_same(0, WP_FTS_Analyzer_Config_Probe_Stream::$filesystemCalls, 'stored analyzer-map rejection should probe zero configured files');
        assert_true($elapsed < 1.0, 'stored 100,000-entry analyzer-map rejection should remain below one second');

        wp_fts_test_reset_wordpress_fakes();
        WP_FTS_Plugin::reset_request_caches();
        $filterCalls = 0;
        $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::ANALYZER_OPTIONS_FILTER] = static function (array $options) use ($hostileMap, &$filterCalls): array {
            $filterCalls++;
            $options['lemma_packs_by_lang'] = $hostileMap;
            return $options;
        };
        $beforeQueries = $fake->num_queries;
        $filterStarted = microtime(true);
        $filterError = acc_caught(static fn(): array => WP_FTS_Plugin::search_page('bounded filtered configuration'));
        assert_true($filterError instanceof WP_FTS_Search_Unavailable, 'a 100,000-entry filtered analyzer map should fail the public search boundary closed');
        assert_same(1, $filterCalls, 'the first search should invoke the analyzer filter once before rejecting its result');
        assert_same($beforeQueries, $fake->num_queries, 'filtered analyzer-map rejection should execute zero search SQL statements');
        assert_same(0, WP_FTS_Analyzer_Config_Probe_Stream::$filesystemCalls, 'filtered analyzer-map rejection should probe zero configured files');
        assert_true(microtime(true) - $filterStarted < 1.0, 'filtered 100,000-entry analyzer-map rejection should remain below one second');

        wp_fts_test_reset_wordpress_fakes();
        WP_FTS_Plugin::reset_request_caches();
        $iterations = 0;
        $lazyMap = (static function () use (&$iterations): Generator {
            while (true) {
                $iterations++;
                yield 'qaa-' . $iterations => false;
            }
        })();
        $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::ANALYZER_OPTIONS_FILTER] = static function (array $options) use ($lazyMap): array {
            $options['lemma_packs_by_lang'] = $lazyMap;
            return $options;
        };
        $beforeQueries = $fake->num_queries;
        $lazyError = acc_caught(static fn(): array => WP_FTS_Plugin::search_page('bounded lazy configuration'));
        assert_true($lazyError instanceof WP_FTS_Search_Unavailable, 'a lazy filtered analyzer map should fail the public search boundary closed');
        assert_same(0, $iterations, 'the WordPress adapter must not advance a lazy analyzer-map generator');
        assert_same($beforeQueries, $fake->num_queries, 'lazy filtered analyzer-map rejection should execute zero search SQL statements');
    } finally {
        unset($hostileMap);
        $wpdb = $oldWpdb;
        wp_fts_test_reset_wordpress_fakes();
        WP_FTS_Plugin::reset_request_caches();
    }
});

test_case('quality analyzer manifests bound bytes depth and runtime files before runtime probes', function (): void {
    $root = sys_get_temp_dir() . '/wp-fts-analyzer-config-' . bin2hex(random_bytes(6));
    mkdir($root, 0777, true);
    try {
        $oversized = $root . '/oversized.json';
        file_put_contents($oversized, str_repeat(' ', WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES + 1));
        $bytesError = acc_caught(static fn(): array => (new WP_FTS_AnalyzerPackValidator())->validate_metadata($oversized, false));
        assert_same('manifest_bytes', $bytesError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $bytesError->reason_code : null, 'manifest reads should stop at 64 KiB plus one byte');

        $files = [];
        for ($number = 0; $number <= WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_FILES; $number++) {
            $files[] = [
                'path' => 'runtime/missing-' . $number . '.tsv',
                'sha256' => str_repeat('a', 64),
                'rows' => 1,
            ];
        }
        $manifest = [
            'schema_version' => 1,
            'pack_id' => 'qaa-runtime-file-overflow',
            'language' => 'qaa',
            'version' => 'test-v1',
            'fixture_only' => true,
            'default_enabled' => false,
            'capabilities' => ['dictionary-lemmatizer'],
            'runtime' => [
                'format' => WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV,
                'ambiguity_policy' => 'ambiguous_surface_noop',
                'files' => $files,
            ],
            'source' => [],
            'license' => [],
            'attribution' => [],
            'provenance' => [
                'no_runtime_network_access' => true,
                'no_full_third_party_dictionary_dump' => true,
            ],
        ];
        $manyFiles = $root . '/many-files.json';
        file_put_contents($manyFiles, json_encode($manifest, JSON_THROW_ON_ERROR));
        $filesError = acc_caught(static fn(): array => (new WP_FTS_AnalyzerPackValidator())->validate_metadata($manyFiles, false));
        assert_same('runtime_files', $filesError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $filesError->reason_code : null, '65 declared runtime files should reject before checking the first missing runtime path');

        $lookupOverflow = $manifest;
        $lookupOverflow['runtime']['files'] = [[
            'path' => 'runtime/missing.tsv.gz',
            'sha256' => str_repeat('a', 64),
            'rows' => 1,
            'compression' => 'gzip',
            'lookup' => [
                'format' => WP_FTS_LemmaPackLookupIndex::FORMAT,
                'path' => 'runtime/missing.tsv.gz.lookup',
                'sha256' => str_repeat('b', 64),
                'blocks' => WP_FTS_Analyzer_Config_Limits::MAX_LOOKUP_BLOCKS_PER_FILE + 1,
            ],
        ]];
        $lookupManifest = $root . '/lookup-overflow.json';
        file_put_contents($lookupManifest, json_encode($lookupOverflow, JSON_THROW_ON_ERROR));
        $lookupError = acc_caught(static fn(): array => (new WP_FTS_AnalyzerPackValidator())->validate_metadata($lookupManifest, false));
        assert_same('lookup_blocks', $lookupError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $lookupError->reason_code : null, '257 declared lookup blocks should reject before checking runtime or sidecar paths');

        $runtime = $root . '/runtime.gz';
        file_put_contents($runtime, 'x');
        $lookup = $root . '/runtime.gz.lookup';
        file_put_contents(
            $lookup,
            "WPFTSLI2" . pack('N', 65537)
        );
        $headerError = acc_caught(static fn(): array => WP_FTS_LemmaPackLookupIndex::metadata(
            $lookup,
            $runtime,
            str_repeat('a', 64),
            1
        ));
        assert_true($headerError instanceof RuntimeException, 'a lookup header above 64 KiB should reject from its fixed prefix without reading a payload');
        assert_contains('header length', $headerError?->getMessage() ?? '', 'oversized lookup headers should identify the bounded header field');

        $pathError = acc_caught(static fn(): array => (new WP_FTS_AnalyzerPackValidator())->validate_metadata(
            str_repeat('x', WP_FTS_Analyzer_Config_Limits::MAX_PATH_BYTES + 1),
            false
        ));
        assert_same('path_bytes', $pathError instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $pathError->reason_code : null, 'validator manifest paths should reject before realpath or stat');
    } finally {
        foreach (glob($root . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($root);
    }
});
