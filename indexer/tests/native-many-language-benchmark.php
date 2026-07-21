<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Deterministic native many-language benchmark for the current indexer plugin.
 *
 * The current native plugin has language partitions, custom analyzer extension
 * points, and bounded explicit-language search. It does not use a separate
 * custom resource-root repository. This benchmark uses
 * the closest native equivalent: generated in-memory language profiles, a
 * generated language-aware analyzer, bounded preflight routing, and the
 * production indexer/searcher/storage path for final retrieval.
 */
final class WP_FTS_Native_Many_Language_Benchmark
{
    public const MAX_SELECTED_PARTITIONS = 5;
    public const MIN_CONFIDENT_SCORE = 3.0;
    public const MIN_CONFIDENT_LEAD = 1.5;
    public const MIN_CONFIDENT_RATIO = 1.35;

    /**
     * Run the generated benchmark and return a machine-readable report.
     *
     * @return array<string,mixed>
     */
    public static function run(): array
    {
        $profiles = self::profiles();
        $documents = self::documents($profiles);
        $innerStorage = new WP_FTS_Storage_InMemory();
        $analyzer = new WP_FTS_NMLB_Analyzer($profiles);
        $indexer = new WP_FTS_Indexer($innerStorage, $analyzer);

        foreach ($documents as $document) {
            $indexer->index_document_fields(
                $document['doc_id'],
                [['name' => 'content', 'text' => $document['text'], 'boost' => 1.0]],
                [
                    'lang' => $document['lang'],
                    'metadata' => [
                        'post_id' => $document['doc_id'],
                        'post_type' => 'post',
                        'post_status' => 'publish',
                        'post_date_gmt' => '2026-06-10 00:00:00',
                        'title' => $document['id'],
                        'excerpt' => '',
                        'search_text' => $document['text'],
                    ],
                ]
            );
        }

        $countingStorage = new WP_FTS_NMLB_Counting_Storage($innerStorage);
        $router = new WP_FTS_NMLB_Router($profiles, $countingStorage);
        $searcher = new WP_FTS_Searcher($countingStorage, $analyzer);
        $docMap = [];
        foreach ($documents as $document) {
            $docMap[$document['doc_id']] = $document;
        }

        $scenarios = [];
        foreach (self::scenario_specs() as $spec) {
            $scenarios[] = self::run_scenario($spec, $router, $searcher, $countingStorage, $docMap);
        }

        $failures = [];
        foreach ($scenarios as $scenario) {
            foreach ($scenario['failures'] as $failure) {
                $failures[] = $scenario['id'] . ': ' . $failure;
            }
        }

        return [
            'passed' => $failures === [],
            'schema' => 'wp-fts-native-many-language-benchmark-v1',
            'seed' => 'task594-native-many-language-v1',
            'native_equivalent' => [
                'custom_root_supported' => false,
                'gap' => 'Current indexer/ exposes language partitions and analyzer extension points, but not a runtime custom lexical resource-root loader. Generated profiles are kept in memory and final retrieval uses native analyzer/indexer/searcher classes.',
            ],
            'language_count' => count($profiles),
            'max_selected_partitions' => self::MAX_SELECTED_PARTITIONS,
            'document_count' => count($documents),
            'scenario_count' => count($scenarios),
            'scenarios' => $scenarios,
            'metrics' => self::aggregate_metrics($scenarios),
            'failures' => $failures,
        ];
    }

    /**
     * @param array<string,mixed> $result
     */
    public static function format_text(array $result): string
    {
        $metrics = is_array($result['metrics'] ?? null) ? $result['metrics'] : [];
        $lines = [
            ((bool) ($result['passed'] ?? false) ? 'PASS' : 'FAIL') . ': native many-language benchmark',
            'languages: ' . (string) ($result['language_count'] ?? 0) . '; documents: ' . (string) ($result['document_count'] ?? 0) . '; scenarios: ' . (string) ($result['scenario_count'] ?? 0),
            'metrics: selected_max=' . (string) ($metrics['selected_partition_count_max'] ?? 0)
                . ' postings_fetch_calls=' . (string) ($metrics['fetch_postings_calls_total'] ?? 0)
                . ' postings_rows=' . (string) ($metrics['postings_rows_materialized_total'] ?? 0)
                . ' term_language_rows=' . (string) ($metrics['term_language_hit_rows_fetched_total'] ?? 0),
            'custom-root gap: ' . (string) (($result['native_equivalent']['gap'] ?? '') ?: 'none'),
            'scenarios:',
        ];

        foreach (($result['scenarios'] ?? []) as $scenario) {
            if (!is_array($scenario)) {
                continue;
            }
            $lines[] = sprintf(
                '  %s: %s selected=[%s] results=[%s] strategy=%s',
                (string) ($scenario['id'] ?? ''),
                !empty($scenario['passed']) ? 'pass' : 'fail',
                implode(',', self::string_list($scenario['selected_partitions'] ?? [])),
                implode(',', self::string_list($scenario['top_ids'] ?? [])),
                (string) ($scenario['routing']['strategy'] ?? '')
            );
        }

        $failures = self::string_list($result['failures'] ?? []);
        if ($failures !== []) {
            $lines[] = 'failures:';
            foreach ($failures as $failure) {
                $lines[] = '  ' . $failure;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Run the command-line interface.
     *
     * @param string[] $argv
     */
    public static function cli(array $argv): int
    {
        $json = in_array('--json', array_slice($argv, 1), true);
        if (in_array('--help', array_slice($argv, 1), true) || in_array('-h', array_slice($argv, 1), true)) {
            fwrite(STDOUT, "Usage: php tests/native-many-language-benchmark.php [--json]\n");
            return 0;
        }

        try {
            $result = self::run();
            if ($json) {
                $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                fwrite(STDOUT, (is_string($encoded) ? $encoded : '{"passed":false}') . "\n");
            } else {
                fwrite(STDOUT, self::format_text($result));
            }

            return (bool) $result['passed'] ? 0 : 1;
        } catch (Throwable $e) {
            if ($json) {
                $encoded = json_encode([
                    'passed' => false,
                    'error' => $e->getMessage(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                fwrite(STDOUT, (is_string($encoded) ? $encoded : '{"passed":false}') . "\n");
            } else {
                fwrite(STDERR, $e->getMessage() . "\n");
            }

            return 1;
        }
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function profiles(): array
    {
        $rows = [
            ['qaa-cp00-exact', 0, ['amberform' => 'amber'], [], ['amberform' => 'lexeme']],
            ['qaa-cp01-morph', 10, ['glimmering' => 'glimmer', 'shimmered' => 'shimmer'], [], ['glimmering' => 'term_rule', 'shimmered' => 'term_rule']],
            ['qaa-cp02-syn', 20, [], ['seekalpha' => 'findalpha'], ['seekalpha' => 'synonym']],
            ['qaa-cp03-filler', 30, [], [], []],
            ['qaa-cp04-silent', 40, [], [], []],
            ['qaa-cp05-amb-a', 50, [], ['sharedroute' => 'sharedtarget'], ['sharedroute' => 'synonym']],
            ['qaa-cp06-amb-b', 60, [], ['sharedroute' => 'sharedtarget'], ['sharedroute' => 'synonym']],
            ['qaa-cp07-amb-c', 70, [], ['sharedroute' => 'sharedtarget'], ['sharedroute' => 'synonym']],
            ['qaa-cp08-amb-target', 80, [], ['sharedroute' => 'sharedtarget'], ['sharedroute' => 'synonym']],
            ['qaa-cp09-unused-overcap', 90, [], [], []],
            ['qaa-cp10-noevidence-target', 100, [], [], []],
            ['qaa-cp11-cap-a', 110, [], [], []],
            ['qaa-cp12-cap-b', 120, [], [], []],
            ['qaa-cp13-cap-c', 130, [], [], []],
            ['qaa-cp14-cap-d', 140, [], [], []],
            ['qaa-cp15-order-guard', 150, [], [], []],
        ];

        $profiles = [];
        foreach ($rows as $row) {
            $lang = WP_FTS_TermNamespace::canonicalize_lang($row[0]);
            $profiles[$lang] = [
                'id' => $lang,
                'order' => $row[1],
                'stem_map' => $row[2],
                'synonyms' => $row[3],
                'evidence' => $row[4],
            ];
        }

        uasort($profiles, static fn(array $a, array $b): int => ((int) $a['order']) <=> ((int) $b['order']));

        return $profiles;
    }

    /**
     * @param array<string,array<string,mixed>> $profiles
     * @return array<int,array{id:string,doc_id:int,lang:string,text:string}>
     */
    private static function documents(array $profiles): array
    {
        $documents = [];
        $nextId = 1000;
        foreach (array_keys($profiles) as $lang) {
            $documents[] = [
                'id' => 'doc-routecap-' . self::fixture_language_id($lang),
                'doc_id' => $nextId++,
                'lang' => $lang,
                'text' => 'routecap fixture target for ' . $lang,
            ];
        }

        foreach ([
            ['doc-exact-target', 'qaa-cp00-exact', 'amberform selected native target'],
            ['doc-exact-bait-silent', 'qaa-cp04-silent', 'amberform cross language bait'],
            ['doc-morph-target', 'qaa-cp01-morph', 'glimmer shimmer morphology target'],
            ['doc-morph-bait-silent', 'qaa-cp04-silent', 'glimmering shimmered morphology bait'],
            ['doc-syn-target', 'qaa-cp02-syn', 'findalpha synonym target'],
            ['doc-syn-bait-silent', 'qaa-cp04-silent', 'seekalpha synonym bait'],
            ['doc-amb-target', 'qaa-cp08-amb-target', 'sharedtarget ambiguous over cap target'],
            ['doc-noevidence-target', 'qaa-cp10-noevidence-target', 'novelterm no evidence over cap target'],
        ] as $row) {
            $documents[] = [
                'id' => $row[0],
                'doc_id' => $nextId++,
                'lang' => WP_FTS_TermNamespace::canonicalize_lang($row[1]),
                'text' => $row[2],
            ];
        }

        return $documents;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function scenario_specs(): array
    {
        return [
            [
                'id' => 'exact-profile-route',
                'query' => 'amberform',
                'expect_strategy' => 'auto_confident_profile_evidence',
                'expect_selected' => ['qaa-cp00-exact'],
                'expect_top_ids' => ['doc-exact-target'],
                'irrelevant' => ['doc-exact-bait-silent'],
            ],
            [
                'id' => 'morphology-profile-route',
                'query' => 'glimmering shimmered',
                'expect_strategy' => 'auto_confident_profile_evidence',
                'expect_selected' => ['qaa-cp01-morph'],
                'expect_top_ids' => ['doc-morph-target'],
                'irrelevant' => ['doc-morph-bait-silent'],
            ],
            [
                'id' => 'single-token-synonym-route',
                'query' => 'seekalpha',
                'expect_strategy' => 'auto_confident_profile_evidence',
                'expect_selected' => ['qaa-cp02-syn'],
                'expect_top_ids' => ['doc-syn-target'],
                'irrelevant' => ['doc-syn-bait-silent'],
            ],
            [
                'id' => 'no-evidence-over-cap-fallback',
                'query' => 'novelterm',
                'expect_strategy' => 'auto_fallback_no_evidence_bounded_preflight',
                'expect_selected_contains' => ['qaa-cp10-noevidence-target'],
                'expect_top_ids' => ['doc-noevidence-target'],
                'irrelevant_prefix' => 'doc-routecap-',
            ],
            [
                'id' => 'ambiguous-evidence-over-cap-fallback',
                'query' => 'sharedroute',
                'expect_strategy' => 'auto_fallback_ambiguous_evidence_bounded_preflight',
                'expect_selected_contains' => ['qaa-cp08-amb-target'],
                'expect_top_ids' => ['doc-amb-target'],
            ],
            [
                'id' => 'runtime-all-hit-cap',
                'query' => 'routecap',
                'expect_strategy' => 'auto_fallback_no_evidence_bounded_preflight',
                'expect_selected' => ['qaa-cp00-exact', 'qaa-cp01-morph', 'qaa-cp02-syn', 'qaa-cp03-filler', 'qaa-cp04-silent'],
                'expect_result_set' => [
                    'doc-routecap-cp00-exact',
                    'doc-routecap-cp01-morph',
                    'doc-routecap-cp02-syn',
                    'doc-routecap-cp03-filler',
                    'doc-routecap-cp04-silent',
                ],
                'irrelevant_selected_suffix_after' => 5,
                'limit' => 16,
            ],
            [
                'id' => 'false-positive-short-form-guard',
                'query' => 'aing',
                'expect_not_strategy' => 'auto_confident_profile_evidence',
                'expect_top_ids' => [],
                'limit' => 5,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $spec
     * @param array<int,array<string,mixed>> $docMap
     * @return array<string,mixed>
     */
    private static function run_scenario(
        array $spec,
        WP_FTS_NMLB_Router $router,
        WP_FTS_Searcher $searcher,
        WP_FTS_NMLB_Counting_Storage $storage,
        array $docMap
    ): array {
        $storage->reset_counters();
        $route = $router->route((string) $spec['query']);
        $limit = max(1, (int) ($spec['limit'] ?? 5));
        $rows = $searcher->search((string) $spec['query'], [
            'languages' => $route['selected_partitions'],
            'limit' => $limit,
        ]);
        $topIds = self::fixture_ids_from_rows($rows, $docMap);
        $counters = $storage->counters();
        $failures = self::scenario_failures($spec, $route, $topIds, $counters);

        return [
            'id' => (string) $spec['id'],
            'query' => (string) $spec['query'],
            'passed' => $failures === [],
            'selected_partitions' => $route['selected_partitions'],
            'top_ids' => $topIds,
            'routing' => [
                'strategy' => $route['strategy'],
                'ranked_candidates' => $route['ranked_candidates'],
                'preflight_evaluated' => $route['preflight_evaluated'],
                'preflight' => $route['preflight'],
            ],
            'counters' => array_merge($counters, [
                'enabled_language_count' => $route['enabled_language_count'],
                'selected_partition_count' => count($route['selected_partitions']),
                'routing_strategy' => $route['strategy'],
                'preflight_was_evaluated' => $route['preflight_evaluated'],
                'preflight_term_counts_by_class' => $route['preflight_term_counts_by_class'],
                'maximum_lookup_terms_for_selected_partition' => $route['maximum_lookup_terms_for_selected_partition'],
                'candidate_to_result_ratio' => count($topIds) > 0 ? $counters['candidate_count'] / count($topIds) : $counters['candidate_count'],
                'postings_rows_per_candidate' => $counters['candidate_count'] > 0 ? $counters['postings_rows_materialized'] / $counters['candidate_count'] : 0.0,
                'field_text_rows_per_returned_result' => count($topIds) > 0 ? $counters['field_text_rows_fetched'] / count($topIds) : 0.0,
            ]),
            'failures' => $failures,
        ];
    }

    /**
     * @param array<string,mixed> $spec
     * @param array<string,mixed> $route
     * @param string[] $topIds
     * @param array<string,mixed> $counters
     * @return string[]
     */
    private static function scenario_failures(array $spec, array $route, array $topIds, array $counters): array
    {
        $failures = [];
        $selected = self::string_list($route['selected_partitions'] ?? []);
        $strategy = (string) ($route['strategy'] ?? '');

        if (isset($spec['expect_strategy']) && $strategy !== (string) $spec['expect_strategy']) {
            $failures[] = 'expected strategy ' . (string) $spec['expect_strategy'] . ', got ' . $strategy;
        }
        if (isset($spec['expect_not_strategy']) && $strategy === (string) $spec['expect_not_strategy']) {
            $failures[] = 'unexpected strategy ' . $strategy;
        }
        if (isset($spec['expect_selected'])) {
            $expected = array_map([WP_FTS_TermNamespace::class, 'canonicalize_lang'], self::string_list($spec['expect_selected']));
            if ($selected !== $expected) {
                $failures[] = 'selected partitions mismatch: expected ' . implode(',', $expected) . '; got ' . implode(',', $selected);
            }
        }
        foreach (self::string_list($spec['expect_selected_contains'] ?? []) as $lang) {
            $lang = WP_FTS_TermNamespace::canonicalize_lang($lang);
            if (!in_array($lang, $selected, true)) {
                $failures[] = 'selected partitions should contain ' . $lang;
            }
        }
        if (count($selected) > self::MAX_SELECTED_PARTITIONS) {
            $failures[] = 'selected partitions exceeded cap';
        }

        $hasTopExpectation = array_key_exists('expect_top_ids', $spec);
        $expectedTopIds = self::string_list($spec['expect_top_ids'] ?? []);
        if ($hasTopExpectation && $expectedTopIds !== [] && array_slice($topIds, 0, count($expectedTopIds)) !== $expectedTopIds) {
            $failures[] = 'top ids mismatch: expected ' . implode(',', $expectedTopIds) . '; got ' . implode(',', $topIds);
        }
        if ($hasTopExpectation && $expectedTopIds === [] && $topIds !== []) {
            $failures[] = 'expected no results, got ' . implode(',', $topIds);
        }
        $expectedResultSet = self::string_list($spec['expect_result_set'] ?? []);
        if ($expectedResultSet !== []) {
            $actualSet = $topIds;
            sort($actualSet, SORT_STRING);
            sort($expectedResultSet, SORT_STRING);
            if ($actualSet !== $expectedResultSet) {
                $failures[] = 'result set mismatch: expected ' . implode(',', $expectedResultSet) . '; got ' . implode(',', $actualSet);
            }
        }
        foreach (self::string_list($spec['irrelevant'] ?? []) as $fixtureId) {
            if (in_array($fixtureId, $topIds, true)) {
                $failures[] = 'irrelevant fixture id returned: ' . $fixtureId;
            }
        }
        if (isset($spec['irrelevant_selected_suffix_after'])) {
            $after = (int) $spec['irrelevant_selected_suffix_after'];
            foreach ($topIds as $fixtureId) {
                if (preg_match('/^doc-routecap-cp([0-9]{2})-/', $fixtureId, $matches) === 1 && (int) $matches[1] >= $after) {
                    $failures[] = 'over-cap routecap document returned: ' . $fixtureId;
                }
            }
        }
        if (($spec['irrelevant_prefix'] ?? '') !== '') {
            $prefix = (string) $spec['irrelevant_prefix'];
            foreach ($topIds as $fixtureId) {
                if (str_starts_with($fixtureId, $prefix)) {
                    $failures[] = 'irrelevant prefix returned: ' . $fixtureId;
                }
            }
        }

        $fetchLanguages = self::string_list($counters['languages_passed_to_full_postings_fetches'] ?? []);
        foreach ($fetchLanguages as $lang) {
            if (!in_array($lang, $selected, true)) {
                $failures[] = 'full postings fetched unselected language ' . $lang;
            }
        }
        if ((int) ($counters['field_metadata_rows_fetched'] ?? 0) !== 0) {
            $failures[] = 'field metadata should not be fetched for benchmark public-search probes';
        }

        return $failures;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $docMap
     * @return string[]
     */
    private static function fixture_ids_from_rows(array $rows, array $docMap): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $docId = (int) ($row['doc_id'] ?? 0);
            if (isset($docMap[$docId])) {
                $ids[] = (string) $docMap[$docId]['id'];
            }
        }

        return $ids;
    }

    private static function fixture_language_id(string $lang): string
    {
        return str_starts_with($lang, 'qaa-') ? substr($lang, 4) : $lang;
    }

    /**
     * @param array<int,array<string,mixed>> $scenarios
     * @return array<string,mixed>
     */
    private static function aggregate_metrics(array $scenarios): array
    {
        $metrics = [
            'selected_partition_count_max' => 0,
            'fetch_postings_calls_total' => 0,
            'fetch_term_language_hits_calls_total' => 0,
            'postings_rows_materialized_total' => 0,
            'term_language_hit_rows_fetched_total' => 0,
            'candidate_count_total' => 0,
            'preflight_scenario_count' => 0,
        ];

        foreach ($scenarios as $scenario) {
            $counters = is_array($scenario['counters'] ?? null) ? $scenario['counters'] : [];
            $fetchCalls = is_array($counters['fetch_calls'] ?? null) ? $counters['fetch_calls'] : [];
            $metrics['selected_partition_count_max'] = max(
                $metrics['selected_partition_count_max'],
                (int) ($counters['selected_partition_count'] ?? 0)
            );
            $metrics['fetch_postings_calls_total'] += (int) ($fetchCalls['fetch_postings'] ?? 0);
            $metrics['fetch_term_language_hits_calls_total'] += (int) ($fetchCalls['fetch_term_language_hits'] ?? 0);
            $metrics['postings_rows_materialized_total'] += (int) ($counters['postings_rows_materialized'] ?? 0);
            $metrics['term_language_hit_rows_fetched_total'] += (int) ($counters['term_language_hit_rows_fetched'] ?? 0);
            $metrics['candidate_count_total'] += (int) ($counters['candidate_count'] ?? 0);
            if (!empty($counters['preflight_was_evaluated'])) {
                $metrics['preflight_scenario_count']++;
            }
        }

        return $metrics;
    }

    /**
     * @return string[]
     */
    private static function string_list(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }
}

final class WP_FTS_NMLB_Analyzer
{
    private WP_FTS_Analyzer $inner;

    /**
     * @param array<string,array<string,mixed>> $profiles
     */
    public function __construct(private array $profiles)
    {
        $this->inner = new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'default_lang' => 'qaa-cp00-exact',
            'stemmer' => new WP_FTS_NMLB_Stemmer($profiles),
        ]);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<int,array{term:string,weight:float,lang:string}>
     */
    public function analyze_content(string $html, array $options = []): array
    {
        return $this->inner->analyze_content($html, $options);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<int,array{term:string,weight:float,lang:string}>
     */
    public function analyze_plain_content(string $text, array $options = []): array
    {
        return $this->inner->analyze_plain_content($text, $options);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<int,array{term:string,lang:string}>
     */
    public function analyze_query_occurrences(string $query, array $options = []): array
    {
        $occurrences = $this->inner->analyze_query_occurrences($query, $options);
        $lang = $this->query_lang($options);
        $synonyms = is_array($this->profiles[$lang]['synonyms'] ?? null) ? $this->profiles[$lang]['synonyms'] : [];
        $seen = [];
        $expanded = [];

        foreach ($occurrences as $occurrence) {
            $key = $occurrence['lang'] . "\0" . $occurrence['term'];
            if (!isset($seen[$key])) {
                $expanded[] = $occurrence;
                $seen[$key] = true;
            }

            $target = $synonyms[$occurrence['term']] ?? null;
            if (is_string($target) && $target !== '') {
                $targetKey = $lang . "\0" . $target;
                if (!isset($seen[$targetKey])) {
                    $expanded[] = ['term' => $target, 'lang' => $lang];
                    $seen[$targetKey] = true;
                }
            }
        }

        return $expanded;
    }

    /**
     * @param array<string,mixed> $options
     * @return string[]
     */
    public function analyze_query(string $query, array $options = []): array
    {
        $occurrences = $this->analyze_query_occurrences($query, $options);
        return array_map(static fn(array $occurrence): string => $occurrence['term'], $occurrences);
    }

    public function index_signature(): string
    {
        return 'task594-native-many-language-analyzer-v1';
    }

    private function query_lang(array $options): string
    {
        return WP_FTS_TermNamespace::language_from_options(
            $options,
            'qaa-cp00-exact',
            ['query_lang', '_default_query_lang']
        ) ?? 'qaa-cp00-exact';
    }
}

final class WP_FTS_NMLB_Stemmer implements WP_FTS_Stemmer
{
    /**
     * @param array<string,array<string,mixed>> $profiles
     */
    public function __construct(private array $profiles)
    {
    }

    public function stem(string $term, string $language): string
    {
        $language = WP_FTS_TermNamespace::canonicalize_lang($language);
        $map = is_array($this->profiles[$language]['stem_map'] ?? null) ? $this->profiles[$language]['stem_map'] : [];

        return is_string($map[$term] ?? null) ? $map[$term] : $term;
    }
}

final class WP_FTS_NMLB_Router
{
    /**
     * @param array<string,array<string,mixed>> $profiles
     */
    public function __construct(private array $profiles, private WP_FTS_NMLB_Counting_Storage $storage)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function route(string $query): array
    {
        $ranked = $this->rank_profiles($query);
        $enabled = array_keys($this->profiles);
        $confident = $this->confident_profile($ranked);
        if ($confident !== null) {
            return [
                'strategy' => 'auto_confident_profile_evidence',
                'enabled_language_count' => count($enabled),
                'selected_partitions' => [$confident],
                'ranked_candidates' => $ranked,
                'preflight_evaluated' => false,
                'preflight' => [],
                'preflight_term_counts_by_class' => ['exact' => 0, 'single_token_synonym' => 0, 'phrase_synonym' => 0, 'fuzzy' => 0],
                'maximum_lookup_terms_for_selected_partition' => count($this->lookup_terms_for($query, $confident)),
            ];
        }

        $preflight = $this->preflight($query, $ranked);
        $selected = [];
        foreach ($preflight as $row) {
            if ((int) $row['hit_count'] > 0) {
                $selected[] = $row['lang'];
            }
            if (count($selected) >= WP_FTS_Native_Many_Language_Benchmark::MAX_SELECTED_PARTITIONS) {
                break;
            }
        }
        foreach ($enabled as $lang) {
            if (count($selected) >= WP_FTS_Native_Many_Language_Benchmark::MAX_SELECTED_PARTITIONS) {
                break;
            }
            if (!in_array($lang, $selected, true)) {
                $selected[] = $lang;
            }
        }

        $selectedLookupCounts = [];
        foreach ($selected as $lang) {
            $selectedLookupCounts[] = count($this->lookup_terms_for($query, $lang));
        }

        $hasEvidence = false;
        foreach ($ranked as $candidate) {
            if ((float) $candidate['score'] > 0.0) {
                $hasEvidence = true;
                break;
            }
        }

        return [
            'strategy' => $hasEvidence
                ? 'auto_fallback_ambiguous_evidence_bounded_preflight'
                : 'auto_fallback_no_evidence_bounded_preflight',
            'enabled_language_count' => count($enabled),
            'selected_partitions' => $selected,
            'ranked_candidates' => $ranked,
            'preflight_evaluated' => true,
            'preflight' => $this->with_selected_flags($preflight, $selected),
            'preflight_term_counts_by_class' => $this->sum_preflight_class_counts($preflight),
            'maximum_lookup_terms_for_selected_partition' => $selectedLookupCounts === [] ? 0 : max($selectedLookupCounts),
        ];
    }

    /**
     * @return array<int,array{lang:string,score:float,reasons:string[],order:int}>
     */
    private function rank_profiles(string $query): array
    {
        $tokens = $this->raw_tokens($query);
        $ranked = [];
        foreach ($this->profiles as $lang => $profile) {
            $score = 0.0;
            $reasons = [];
            $stemMap = is_array($profile['stem_map'] ?? null) ? $profile['stem_map'] : [];
            $synonyms = is_array($profile['synonyms'] ?? null) ? $profile['synonyms'] : [];
            $evidence = is_array($profile['evidence'] ?? null) ? $profile['evidence'] : [];
            foreach ($tokens as $token) {
                if (isset($evidence[$token])) {
                    $score += $evidence[$token] === 'synonym' ? 3.0 : 4.0;
                    $reasons[] = (string) $evidence[$token] . ':' . $token;
                } elseif (isset($stemMap[$token])) {
                    $score += 3.0;
                    $reasons[] = 'term_rule:' . $token . '=>' . (string) $stemMap[$token];
                } elseif (isset($synonyms[$token])) {
                    $score += 3.0;
                    $reasons[] = 'synonym:' . $token . '=>' . (string) $synonyms[$token];
                }
            }
            $ranked[] = [
                'lang' => $lang,
                'score' => $score,
                'reasons' => array_values(array_unique($reasons)),
                'order' => (int) ($profile['order'] ?? 0),
            ];
        }

        usort($ranked, static function (array $a, array $b): int {
            $scoreOrder = ((float) $b['score']) <=> ((float) $a['score']);
            return $scoreOrder !== 0 ? $scoreOrder : ((int) $a['order'] <=> (int) $b['order']);
        });

        return $ranked;
    }

    /**
     * @param array<int,array{lang:string,score:float,reasons:string[],order:int}> $ranked
     */
    private function confident_profile(array $ranked): ?string
    {
        $top = $ranked[0] ?? null;
        if ($top === null || (float) $top['score'] < WP_FTS_Native_Many_Language_Benchmark::MIN_CONFIDENT_SCORE) {
            return null;
        }

        $secondScore = (float) ($ranked[1]['score'] ?? 0.0);
        $lead = (float) $top['score'] - $secondScore;
        $ratio = $secondScore > 0.0 ? (float) $top['score'] / $secondScore : INF;
        if ($lead < WP_FTS_Native_Many_Language_Benchmark::MIN_CONFIDENT_LEAD || $ratio < WP_FTS_Native_Many_Language_Benchmark::MIN_CONFIDENT_RATIO) {
            return null;
        }

        return (string) $top['lang'];
    }

    /**
     * @param array<int,array{lang:string,score:float,reasons:string[],order:int}> $ranked
     * @return array<int,array<string,mixed>>
     */
    private function preflight(string $query, array $ranked): array
    {
        $scoreByLang = [];
        foreach ($ranked as $candidate) {
            $scoreByLang[$candidate['lang']] = (float) $candidate['score'];
        }

        $rows = [];
        foreach ($this->profiles as $lang => $profile) {
            $termsByClass = $this->lookup_terms_by_class($query, $lang);
            $counts = $this->storage->term_hit_counts($lang, $termsByClass);
            $rows[] = [
                'lang' => $lang,
                'order' => (int) ($profile['order'] ?? 0),
                'profile_score' => $scoreByLang[$lang] ?? 0.0,
                'hit_count' => array_sum($counts),
                'term_counts_by_class' => $counts,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $hitOrder = ((int) $b['hit_count']) <=> ((int) $a['hit_count']);
            if ($hitOrder !== 0) {
                return $hitOrder;
            }
            $scoreOrder = ((float) $b['profile_score']) <=> ((float) $a['profile_score']);
            return $scoreOrder !== 0 ? $scoreOrder : ((int) $a['order'] <=> (int) $b['order']);
        });

        return $rows;
    }

    /**
     * @return array<string,string[]>
     */
    private function lookup_terms_by_class(string $query, string $lang): array
    {
        $exact = $this->lookup_terms_for($query, $lang);
        $profile = $this->profiles[$lang] ?? [];
        $synonyms = is_array($profile['synonyms'] ?? null) ? $profile['synonyms'] : [];
        $synonymTerms = [];
        foreach ($this->raw_tokens($query) as $token) {
            if (isset($synonyms[$token]) && is_string($synonyms[$token])) {
                $synonymTerms[] = $synonyms[$token];
            }
        }

        return [
            'exact' => $exact,
            'single_token_synonym' => array_values(array_unique($synonymTerms)),
            'phrase_synonym' => [],
            'fuzzy' => [],
        ];
    }

    /**
     * @return string[]
     */
    private function lookup_terms_for(string $query, string $lang): array
    {
        $profile = $this->profiles[$lang] ?? [];
        $stemMap = is_array($profile['stem_map'] ?? null) ? $profile['stem_map'] : [];
        $synonyms = is_array($profile['synonyms'] ?? null) ? $profile['synonyms'] : [];
        $terms = [];
        foreach ($this->raw_tokens($query) as $token) {
            $terms[] = is_string($stemMap[$token] ?? null) ? $stemMap[$token] : $token;
            if (is_string($synonyms[$token] ?? null)) {
                $terms[] = $synonyms[$token];
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * @return string[]
     */
    private function raw_tokens(string $query): array
    {
        preg_match_all('/[A-Za-z0-9_]+/', strtolower($query), $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    /**
     * @param array<int,array<string,mixed>> $preflight
     * @param string[] $selected
     * @return array<int,array<string,mixed>>
     */
    private function with_selected_flags(array $preflight, array $selected): array
    {
        $selectedLookup = array_fill_keys($selected, true);
        foreach ($preflight as &$row) {
            $row['selected'] = isset($selectedLookup[(string) $row['lang']]);
        }
        unset($row);

        return $preflight;
    }

    /**
     * @param array<int,array<string,mixed>> $preflight
     * @return array{exact:int,single_token_synonym:int,phrase_synonym:int,fuzzy:int}
     */
    private function sum_preflight_class_counts(array $preflight): array
    {
        $sums = ['exact' => 0, 'single_token_synonym' => 0, 'phrase_synonym' => 0, 'fuzzy' => 0];
        foreach ($preflight as $row) {
            $counts = is_array($row['term_counts_by_class'] ?? null) ? $row['term_counts_by_class'] : [];
            foreach (array_keys($sums) as $class) {
                $sums[$class] += (int) ($counts[$class] ?? 0);
            }
        }

        return $sums;
    }
}

final class WP_FTS_NMLB_Counting_Storage implements WP_FTS_Storage, WP_FTS_DocumentMetadataStorage
{
    /** @var array<string,mixed> */
    private array $counters = [];

    public function __construct(private WP_FTS_Storage_InMemory $inner)
    {
        $this->reset_counters();
    }

    public function reset_counters(): void
    {
        $this->counters = [
            'candidate_count' => 0,
            'lookup_terms_requested' => 0,
            'postings_rows_materialized' => 0,
            'document_length_rows_fetched' => 0,
            'position_rows_fetched' => 0,
            'field_text_rows_fetched' => 0,
            'field_metadata_rows_fetched' => 0,
            'fuzzy_candidate_terms_returned' => 0,
            'term_language_hit_rows_fetched' => 0,
            'fetch_calls' => [
                'fetch_postings' => 0,
                'fetch_term_language_hits' => 0,
                'fetch_candidate_terms' => 0,
                'fetch_positions' => 0,
                'fetch_document_lengths' => 0,
                'fetch_document_fields' => 0,
                'fetch_document_field_metadata' => 0,
            ],
            'languages_passed_to_full_postings_fetches' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function counters(): array
    {
        $this->counters['languages_passed_to_full_postings_fetches'] = array_values(array_unique(
            array_map('strval', $this->counters['languages_passed_to_full_postings_fetches'])
        ));
        sort($this->counters['languages_passed_to_full_postings_fetches'], SORT_STRING);

        return $this->counters;
    }

    /**
     * @param array<string,string[]> $termsByClass
     * @return array{exact:int,single_token_synonym:int,phrase_synonym:int,fuzzy:int}
     */
    public function term_hit_counts(string $lang, array $termsByClass): array
    {
        $lang = WP_FTS_TermNamespace::canonicalize_lang($lang);
        $this->counters['fetch_calls']['fetch_term_language_hits']++;
        $allTerms = array_fill_keys($this->inner->all_terms(), true);
        $counts = ['exact' => 0, 'single_token_synonym' => 0, 'phrase_synonym' => 0, 'fuzzy' => 0];

        foreach ($counts as $class => $_) {
            foreach (array_values(array_unique($termsByClass[$class] ?? [])) as $term) {
                $this->counters['term_language_hit_rows_fetched']++;
                if (isset($allTerms[WP_FTS_TermNamespace::namespace_term($lang, (string) $term)])) {
                    $counts[$class]++;
                }
            }
        }

        return $counts;
    }

    /**
     * @param string[] $terms
     * @return array<string,array{df:int,postings:string}>
     */
    public function get_terms(array $terms): array
    {
        $terms = array_values(array_unique(array_map('strval', $terms)));
        $this->counters['fetch_calls']['fetch_postings']++;
        $this->counters['lookup_terms_requested'] += count($terms);
        foreach ($terms as $term) {
            $split = WP_FTS_TermNamespace::split_term($term);
            if ($split !== null) {
                $this->counters['languages_passed_to_full_postings_fetches'][] = $split['lang'];
            }
        }

        $rows = $this->inner->get_terms($terms);
        $candidateIds = [];
        foreach ($rows as $row) {
            $postings = WP_FTS_PostingsCodec::decode($row['postings']);
            $this->counters['postings_rows_materialized'] += count($postings);
            foreach ($postings as $docId => $_) {
                $candidateIds[(int) $docId] = true;
            }
        }
        $this->counters['candidate_count'] = max($this->counters['candidate_count'], count($candidateIds));

        return $rows;
    }

    public function put_term(string $term, int $df, string $postings): void
    {
        $this->inner->put_term($term, $df, $postings);
    }

    public function delete_term(string $term): void
    {
        $this->inner->delete_term($term);
    }

    /**
     * @param int[] $doc_ids
     * @return array<int,int>
     */
    public function get_doc_lengths(array $doc_ids, ?string $lang = null): array
    {
        $this->counters['fetch_calls']['fetch_document_lengths']++;
        $rows = $this->inner->get_doc_lengths($doc_ids, $lang);
        $this->counters['document_length_rows_fetched'] += count($rows);

        return $rows;
    }

    /**
     * @return array{primary_lang:string,lang_lengths:array<string,int>,doc_len:int,content_hash:?string,deleted:bool}|null
     */
    public function get_doc(int $doc_id): ?array
    {
        return $this->inner->get_doc($doc_id);
    }

    public function put_doc(int $doc_id, string $primary_lang, array $lang_lengths, ?string $hash): void
    {
        $this->inner->put_doc($doc_id, $primary_lang, $lang_lengths, $hash);
    }

    public function delete_doc(int $doc_id): void
    {
        $this->inner->delete_doc($doc_id);
    }

    /**
     * @return array{doc_count:int,len_sum:int}
     */
    public function get_meta(?string $lang = null): array
    {
        return $this->inner->get_meta($lang);
    }

    public function add_meta(string $lang, int $d_docs, int $d_len): void
    {
        $this->inner->add_meta($lang, $d_docs, $d_len);
    }

    /**
     * @return string[]
     */
    public function all_terms(): array
    {
        return $this->inner->all_terms();
    }

    /**
     * @return int[]
     */
    public function all_doc_ids(bool $include_deleted = false): array
    {
        return $this->inner->all_doc_ids($include_deleted);
    }

    public function begin_transaction(): void
    {
        $this->inner->begin_transaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollback(): void
    {
        $this->inner->rollback();
    }

    public function flush(): void
    {
        $this->inner->flush();
    }

    public function optimize(): void
    {
        $this->inner->optimize();
    }

    public function put_doc_metadata(int $doc_id, array $metadata): void
    {
        $this->inner->put_doc_metadata($doc_id, $metadata);
    }

    /**
     * @param int[] $doc_ids
     * @return array<int,array<string,mixed>>
     */
    public function get_doc_metadata(array $doc_ids): array
    {
        $this->counters['fetch_calls']['fetch_document_field_metadata']++;
        $rows = $this->inner->get_doc_metadata($doc_ids);
        $this->counters['field_metadata_rows_fetched'] += count($rows);

        return $rows;
    }
}

if (
    PHP_SAPI === 'cli'
    && isset($argv)
    && realpath((string) ($argv[0] ?? '')) === __FILE__
) {
    exit(WP_FTS_Native_Many_Language_Benchmark::cli($argv));
}
