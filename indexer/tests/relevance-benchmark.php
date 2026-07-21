<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Native relevance evaluator for committed multilingual gold fixtures.
 *
 * The evaluator intentionally uses the component analyzer/indexer and legacy
 * in-memory searcher. It does not exercise the production relational ranker,
 * WordPress visibility, or MySQL query plans; those have separate gates.
 */
final class WP_FTS_Relevance_Benchmark
{
    public const DEFAULT_TOP_K = 5;
    private const SCHEMA = 'wp-fts-native-relevance-suite-v1';

    /**
     * Return the committed default relevance suite path.
     */
    public static function default_suite_path(): string
    {
        return __DIR__ . '/fixtures/relevance/native-core.json';
    }

    /**
     * Load and validate a relevance suite JSON file.
     *
     * @return array<string,mixed>
     */
    public static function load_suite(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("Relevance suite does not exist: {$path}");
        }

        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new RuntimeException("Could not read relevance suite: {$path}");
        }

        $suite = json_decode($raw, true);
        if (!is_array($suite)) {
            throw new RuntimeException("Could not decode relevance suite JSON: {$path}");
        }

        if (($suite['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('Unsupported relevance suite schema.');
        }
        if (!isset($suite['documents']) || !is_array($suite['documents'])) {
            throw new RuntimeException('Relevance suite must contain a documents array.');
        }
        if (!isset($suite['queries']) || !is_array($suite['queries'])) {
            throw new RuntimeException('Relevance suite must contain a queries array.');
        }

        return $suite;
    }

    /**
     * Run a relevance suite and return a machine-readable report.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function run(string $suitePath, array $options = []): array
    {
        $suite = self::load_suite($suitePath);
        $topK = max(1, (int) ($suite['top_k'] ?? self::DEFAULT_TOP_K));
        $analyzer = new WP_FTS_Analyzer(self::array_value($suite['analyzer'] ?? []));
        $storage = new WP_FTS_Storage_InMemory();
        $indexer = new WP_FTS_Indexer($storage, $analyzer);
        $docMap = self::index_documents($suite, $indexer);
        $searcher = new WP_FTS_Searcher($storage, $analyzer);

        $queryRows = [];
        $metricRows = [];
        $failures = [];
        $crossLanguageFalsePositiveCount = 0;
        $crossLanguageCheckCount = 0;
        $noResultExpectationFailures = 0;
        $topIdExpectationFailures = 0;
        $irrelevantExpectationFailures = 0;

        foreach ($suite['queries'] as $query) {
            $query = self::array_value($query);
            $queryId = self::required_string($query, 'id', 'query');
            $surface = (string) ($query['surface'] ?? 'searcher');
            $limit = max(1, (int) ($query['limit'] ?? $topK));
            $rows = self::run_query($searcher, $docMap, $query, $limit);
            $topRows = array_slice($rows, 0, $topK);
            $topIds = self::fixture_ids_from_rows($topRows, $docMap);
            $judgments = self::normalized_judgments($query);
            $expect = self::array_value($query['expect'] ?? []);
            $irrelevant = self::string_list($query['irrelevant'] ?? []);
            $crossLanguageIrrelevant = self::string_list($query['cross_language_irrelevant'] ?? []);
            $crossLanguageCheckCount += count($crossLanguageIrrelevant);

            $queryFailures = [];
            foreach ($crossLanguageIrrelevant as $fixtureId) {
                if (in_array($fixtureId, $topIds, true)) {
                    $crossLanguageFalsePositiveCount++;
                    $queryFailures[] = "cross-language false positive {$fixtureId}";
                }
            }

            foreach ($irrelevant as $fixtureId) {
                if (in_array($fixtureId, $topIds, true)) {
                    $irrelevantExpectationFailures++;
                    $queryFailures[] = "irrelevant result {$fixtureId}";
                }
            }

            if (!empty($expect['no_results']) && $topIds !== []) {
                $noResultExpectationFailures++;
                $queryFailures[] = 'expected no results, got ' . implode(', ', $topIds);
            }

            $expectedTopIds = self::string_list($expect['top_ids'] ?? []);
            if ($expectedTopIds !== []) {
                $actualPrefix = array_slice($topIds, 0, count($expectedTopIds));
                if ($actualPrefix !== $expectedTopIds) {
                    $topIdExpectationFailures++;
                    $queryFailures[] = 'expected top prefix ' . implode(', ', $expectedTopIds) . '; got ' . implode(', ', $actualPrefix);
                }
            }

            $metricRow = null;
            if ($judgments !== [] && empty($expect['no_results'])) {
                $metricRow = self::query_metrics($topIds, $judgments, $topK);
                $metricRows[] = $metricRow;
            }

            if ($queryFailures !== []) {
                foreach ($queryFailures as $failure) {
                    $failures[] = "{$queryId}: {$failure}";
                }
            }

            $queryRows[] = [
                'id' => $queryId,
                'family' => (string) ($query['family'] ?? ''),
                'surface' => $surface,
                'query' => (string) ($query['query'] ?? ''),
                'language' => isset($query['language']) ? (string) $query['language'] : null,
                'mode' => strtoupper((string) ($query['mode'] ?? 'OR')),
                'limit' => $limit,
                'top_ids' => $topIds,
                'hits' => self::describe_rows($topRows, $docMap),
                'metrics' => $metricRow,
                'failures' => $queryFailures,
            ];
        }

        $metrics = self::aggregate_metrics($metricRows, $topK);
        $metrics['cross_language_false_positive_count'] = $crossLanguageFalsePositiveCount;
        $metrics['cross_language_bait_checks'] = $crossLanguageCheckCount;
        $metrics['no_result_expectation_failures'] = $noResultExpectationFailures;
        $metrics['top_id_expectation_failures'] = $topIdExpectationFailures;
        $metrics['irrelevant_expectation_failures'] = $irrelevantExpectationFailures;

        $composition = self::composition($suite, count($docMap), count($metricRows), $crossLanguageCheckCount);
        foreach ($composition['failures'] as $failure) {
            $failures[] = $failure;
        }

        $thresholdRows = self::threshold_rows(
            array_merge(self::array_value($suite['thresholds'] ?? []), self::array_value($options['thresholds'] ?? [])),
            $metrics
        );
        foreach ($thresholdRows as $threshold) {
            if (!$threshold['passed']) {
                $failures[] = 'threshold failed: ' . $threshold['metric'];
            }
        }

        $advisoryThresholdRows = self::advisory_threshold_rows(
            self::array_value($suite['advisory_thresholds'] ?? []),
            $metrics
        );

        return [
            'passed' => $failures === [],
            'suite' => [
                'path' => $suitePath,
                'schema' => self::SCHEMA,
                'name' => (string) ($suite['name'] ?? basename($suitePath)),
                'source' => (string) ($suite['source'] ?? ''),
            ],
            'top_k' => $topK,
            'documents' => [
                'count' => count($docMap),
            ],
            'queries' => [
                'count' => count($suite['queries']),
                'retrieval_count' => count($metricRows),
            ],
            'metrics' => $metrics,
            'composition' => $composition,
            'thresholds' => $thresholdRows,
            'advisory_thresholds' => $advisoryThresholdRows,
            'query_results' => $queryRows,
            'failures' => $failures,
        ];
    }

    /**
     * Render a compact human-readable report.
     *
     * @param array<string,mixed> $result
     */
    public static function format_text(array $result): string
    {
        $metrics = self::array_value($result['metrics'] ?? []);
        $lines = [
            ($result['passed'] ? 'PASS' : 'FAIL') . ': ' . (string) (($result['suite']['name'] ?? '') ?: 'Native relevance benchmark'),
            'suite: ' . (string) ($result['suite']['path'] ?? ''),
            'documents: ' . (string) (($result['documents']['count'] ?? 0)) . '; queries: ' . (string) (($result['queries']['count'] ?? 0)) . '; retrieval queries: ' . (string) (($result['queries']['retrieval_count'] ?? 0)) . '; top_k: ' . (string) ($result['top_k'] ?? self::DEFAULT_TOP_K),
            sprintf(
                'metrics: recall@5=%.4f precision@5=%.4f MRR=%.4f nDCG@5=%.4f cross_language_false_positives=%d',
                (float) ($metrics['recall_at_5'] ?? 0.0),
                (float) ($metrics['precision_at_5'] ?? 0.0),
                (float) ($metrics['mrr'] ?? 0.0),
                (float) ($metrics['ndcg_at_5'] ?? 0.0),
                (int) ($metrics['cross_language_false_positive_count'] ?? 0)
            ),
            'thresholds:',
        ];

        foreach (self::array_value($result['thresholds'] ?? []) as $threshold) {
            $lines[] = sprintf(
                '  %s %s %s: %s (actual %s)',
                (string) ($threshold['metric'] ?? ''),
                (string) ($threshold['operator'] ?? ''),
                self::format_number($threshold['expected'] ?? null),
                !empty($threshold['passed']) ? 'pass' : 'fail',
                self::format_number($threshold['actual'] ?? null)
            );
        }

        $advisory = self::array_value($result['advisory_thresholds'] ?? []);
        if ($advisory !== []) {
            $lines[] = 'advisory:';
            foreach ($advisory as $threshold) {
                $lines[] = sprintf(
                    '  %s %s %s: %s (actual %s)',
                    (string) ($threshold['metric'] ?? ''),
                    (string) ($threshold['operator'] ?? ''),
                    self::format_number($threshold['expected'] ?? null),
                    !empty($threshold['passed']) ? 'pass' : 'miss',
                    self::format_number($threshold['actual'] ?? null)
                );
            }
        }

        $lines[] = 'queries:';
        foreach (self::array_value($result['query_results'] ?? []) as $query) {
            $metric = is_array($query['metrics'] ?? null) ? $query['metrics'] : null;
            $metricText = $metric === null
                ? 'no retrieval metrics'
                : sprintf(
                    'recall=%.3f precision=%.3f mrr=%.3f ndcg=%.3f',
                    (float) $metric['recall_at_5'],
                    (float) $metric['precision_at_5'],
                    (float) $metric['mrr'],
                    (float) $metric['ndcg_at_5']
                );
            $lines[] = sprintf(
                '  %s [%s/%s]: %s (%s)',
                (string) ($query['id'] ?? ''),
                (string) ($query['family'] ?? ''),
                (string) ($query['surface'] ?? ''),
                implode(', ', self::string_list($query['top_ids'] ?? [])),
                $metricText
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
        $suitePath = self::default_suite_path();
        $json = false;
        $thresholds = [];

        try {
            foreach (array_slice($argv, 1) as $arg) {
                if ($arg === '--json') {
                    $json = true;
                    continue;
                }
                if ($arg === '--help' || $arg === '-h') {
                    fwrite(STDOUT, "Usage: php tests/relevance-benchmark.php [--suite=PATH] [--json] [--recall-at-5=N] [--precision-at-5=N] [--mrr=N] [--ndcg-at-5=N]\n");
                    return 0;
                }
                if (str_starts_with($arg, '--suite=')) {
                    $suitePath = substr($arg, strlen('--suite='));
                    continue;
                }

                $threshold = self::parse_threshold_arg($arg);
                if ($threshold !== null) {
                    $thresholds[$threshold[0]] = $threshold[1];
                    continue;
                }

                fwrite(STDERR, "Unknown argument: {$arg}\n");
                return 2;
            }

            $result = self::run($suitePath, ['thresholds' => $thresholds]);
            if ($json) {
                $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($encoded)) {
                    throw new RuntimeException('Could not encode benchmark result JSON.');
                }
                fwrite(STDOUT, $encoded . "\n");
            } else {
                fwrite(STDOUT, self::format_text($result));
            }

            return $result['passed'] ? 0 : 1;
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
     * @param array<string,mixed> $suite
     * @return array<int,array<string,mixed>>
     */
    private static function index_documents(array $suite, WP_FTS_Indexer $indexer): array
    {
        $docMap = [];
        $seenFixtureIds = [];
        $seenNumericIds = [];
        $nextId = 1;

        foreach ($suite['documents'] as $document) {
            $document = self::array_value($document);
            $fixtureId = self::required_string($document, 'id', 'document');
            if (isset($seenFixtureIds[$fixtureId])) {
                throw new RuntimeException("Duplicate document id: {$fixtureId}");
            }
            $seenFixtureIds[$fixtureId] = true;

            $numericId = isset($document['numeric_id']) ? (int) $document['numeric_id'] : $nextId;
            while (isset($seenNumericIds[$numericId])) {
                $numericId++;
            }
            if ($numericId < 1) {
                throw new RuntimeException("Document {$fixtureId} has an invalid numeric_id.");
            }
            $nextId = max($nextId, $numericId + 1);
            $seenNumericIds[$numericId] = true;

            $fields = self::document_fields($document);
            if ($fields === []) {
                throw new RuntimeException("Document {$fixtureId} has no indexable fields.");
            }

            $opts = [];
            if (isset($document['language']) && is_scalar($document['language']) && trim((string) $document['language']) !== '') {
                $opts['lang'] = (string) $document['language'];
            }
            $opts['metadata'] = self::document_metadata($document, $numericId);
            $indexer->index_document_fields($numericId, $fields, $opts);

            $document['id'] = $fixtureId;
            $document['numeric_id'] = $numericId;
            $docMap[$numericId] = $document;
        }

        ksort($docMap, SORT_NUMERIC);

        return $docMap;
    }

    /**
     * @param array<string,mixed> $document
     * @return array<int,array<string,mixed>>
     */
    private static function document_fields(array $document): array
    {
        if (isset($document['fields']) && is_array($document['fields'])) {
            $fields = [];
            foreach ($document['fields'] as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $normalized = [
                    'name' => (string) ($field['name'] ?? 'content'),
                    'text' => (string) ($field['text'] ?? ''),
                    'boost' => (float) ($field['boost'] ?? 1.0),
                ];
                if (isset($field['html']) && is_scalar($field['html'])) {
                    $normalized['html'] = (string) $field['html'];
                }
                $fields[] = $normalized;
            }

            return $fields;
        }

        $fields = [];
        if (isset($document['title']) && is_scalar($document['title']) && trim((string) $document['title']) !== '') {
            $fields[] = ['name' => 'title', 'text' => (string) $document['title'], 'boost' => 5.0];
        }
        if (isset($document['excerpt']) && is_scalar($document['excerpt']) && trim((string) $document['excerpt']) !== '') {
            $fields[] = ['name' => 'excerpt', 'text' => (string) $document['excerpt'], 'boost' => 2.0];
        }
        foreach (['content', 'html'] as $name) {
            if (!isset($document[$name]) || !is_scalar($document[$name]) || trim((string) $document[$name]) === '') {
                continue;
            }
            $value = (string) $document[$name];
            $field = [
                'name' => $name === 'html' ? 'content' : $name,
                'text' => trim(strip_tags($value)),
                'boost' => 1.0,
            ];
            if (str_contains($value, '<')) {
                $field['html'] = $value;
            }
            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private static function document_metadata(array $document, int $numericId): array
    {
        $visibility = (string) ($document['visibility'] ?? 'public');
        $postStatus = (string) ($document['post_status'] ?? ($visibility === 'private' || $visibility === 'private_readable' ? 'private' : 'publish'));
        $postType = (string) ($document['post_type'] ?? ($visibility === 'excluded_type' ? 'secret' : 'post'));
        $password = (string) ($document['post_password'] ?? ($visibility === 'password' ? 'secret' : ''));

        return [
            'post_id' => (int) ($document['post_id'] ?? $numericId),
            'post_type' => $postType,
            'post_status' => $postStatus,
            'post_date_gmt' => (string) ($document['post_date_gmt'] ?? '2026-06-10 00:00:00'),
            'title' => (string) ($document['title'] ?? $document['id'] ?? ''),
            'excerpt' => (string) ($document['excerpt'] ?? ''),
            'search_text' => trim(implode(' ', [
                (string) ($document['title'] ?? ''),
                (string) ($document['excerpt'] ?? ''),
                trim(strip_tags((string) ($document['content'] ?? $document['html'] ?? ''))),
            ])),
            'visibility' => $visibility,
            'post_password' => $password,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $docMap
     * @param array<string,mixed> $query
     * @return array<int,array<string,mixed>>
     */
    private static function run_query(WP_FTS_Searcher $searcher, array $docMap, array $query, int $limit): array
    {
        $searchOptions = [
            'mode' => strtoupper((string) ($query['mode'] ?? 'OR')),
            'limit' => $limit,
        ];
        if (isset($query['language']) && is_scalar($query['language']) && trim((string) $query['language']) !== '') {
            $searchOptions['lang'] = (string) $query['language'];
        }
        if (isset($query['languages'])) {
            $searchOptions['languages'] = $query['languages'];
        }
        if (array_key_exists('language_fallback', $query)) {
            $searchOptions['language_fallback'] = $query['language_fallback'];
        }
        if (array_key_exists('fallback_languages', $query)) {
            $searchOptions['fallback_languages'] = $query['fallback_languages'];
        }
        if (array_key_exists('disable_language_fallback', $query)) {
            $searchOptions['disable_language_fallback'] = $query['disable_language_fallback'];
        }

        $surface = (string) ($query['surface'] ?? 'searcher');
        if ($surface === 'plugin_rest' || $surface === 'native_visibility') {
            return self::legacy_visibility_page_search($searcher, $docMap, (string) ($query['query'] ?? ''), $searchOptions, $query, $limit);
        }

        return $searcher->search((string) ($query['query'] ?? ''), $searchOptions);
    }

    /**
     * Preserve the retired component fixture's post-filtered page semantics.
     *
     * @param array<int,array<string,mixed>> $docMap
     * @param array<string,mixed> $searchOptions
     * @param array<string,mixed> $query
     * @return array<int,array<string,mixed>>
     */
    private static function legacy_visibility_page_search(WP_FTS_Searcher $searcher, array $docMap, string $queryText, array $searchOptions, array $query, int $limit): array
    {
        $visible = [];
        $offset = 0;
        $maxScan = 250;
        $batchLimit = min($maxScan, max(10, $limit * 4));

        while (count($visible) < $limit && $offset < $maxScan) {
            $searchOptions['limit'] = min($batchLimit, $maxScan - $offset);
            $searchOptions['offset'] = $offset;
            $rows = $searcher->search($queryText, $searchOptions);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $docId = (int) ($row['doc_id'] ?? 0);
                if (isset($docMap[$docId]) && self::query_can_read_document($docMap[$docId], $query)) {
                    $visible[] = $row;
                    if (count($visible) >= $limit) {
                        break;
                    }
                }
            }

            if (count($rows) < $searchOptions['limit']) {
                break;
            }
            $offset += $searchOptions['limit'];
        }

        return $visible;
    }

    /**
     * @param array<string,mixed> $document
     * @param array<string,mixed> $query
     */
    private static function query_can_read_document(array $document, array $query): bool
    {
        $visibility = (string) ($document['visibility'] ?? 'public');
        if ($visibility === 'public') {
            return true;
        }
        if ($visibility === 'private_readable') {
            $capabilities = self::array_value($query['capabilities'] ?? []);
            return in_array((string) $document['id'], self::string_list($capabilities['read_post'] ?? []), true);
        }

        return false;
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

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $docMap
     * @return array<int,array<string,mixed>>
     */
    private static function describe_rows(array $rows, array $docMap): array
    {
        $hits = [];
        foreach ($rows as $index => $row) {
            $docId = (int) ($row['doc_id'] ?? 0);
            $hits[] = [
                'rank' => $index + 1,
                'id' => isset($docMap[$docId]) ? (string) $docMap[$docId]['id'] : (string) $docId,
                'doc_id' => $docId,
                'score' => (float) ($row['score'] ?? 0.0),
            ];
        }

        return $hits;
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,int>
     */
    private static function normalized_judgments(array $query): array
    {
        $judgments = [];
        foreach (self::array_value($query['judgments'] ?? []) as $id => $grade) {
            if (is_scalar($id) && is_numeric($grade) && (int) $grade > 0) {
                $judgments[(string) $id] = (int) $grade;
            }
        }
        foreach (self::string_list($query['relevant'] ?? []) as $id) {
            $judgments[$id] ??= 1;
        }
        ksort($judgments, SORT_STRING);

        return $judgments;
    }

    /**
     * @param string[] $topIds
     * @param array<string,int> $judgments
     * @return array<string,float|int>
     */
    private static function query_metrics(array $topIds, array $judgments, int $topK): array
    {
        $topIds = array_slice($topIds, 0, $topK);
        $relevantTotal = count($judgments);
        $relevantHits = 0;
        $firstRelevantRank = null;
        $dcg = 0.0;

        foreach ($topIds as $index => $id) {
            $rank = $index + 1;
            $grade = $judgments[$id] ?? 0;
            if ($grade > 0) {
                $relevantHits++;
                $firstRelevantRank ??= $rank;
                $dcg += $grade / self::log2($rank + 1);
            }
        }

        $idealGrades = array_values($judgments);
        rsort($idealGrades, SORT_NUMERIC);
        $idealDcg = 0.0;
        foreach (array_slice($idealGrades, 0, $topK) as $index => $grade) {
            $idealDcg += $grade / self::log2($index + 2);
        }

        return [
            'relevant_total' => $relevantTotal,
            'relevant_hits_at_5' => $relevantHits,
            'recall_at_5' => $relevantTotal > 0 ? $relevantHits / $relevantTotal : 0.0,
            'precision_at_5' => $relevantHits / $topK,
            'mrr' => $firstRelevantRank === null ? 0.0 : 1.0 / $firstRelevantRank,
            'ndcg_at_5' => $idealDcg > 0.0 ? $dcg / $idealDcg : 0.0,
        ];
    }

    /**
     * @param array<int,array<string,float|int>> $rows
     * @return array<string,float|int>
     */
    private static function aggregate_metrics(array $rows, int $topK): array
    {
        if ($rows === []) {
            return [
                'recall_at_5' => 0.0,
                'precision_at_5' => 0.0,
                'mrr' => 0.0,
                'ndcg_at_5' => 0.0,
            ];
        }

        $sums = [
            'recall_at_5' => 0.0,
            'precision_at_5' => 0.0,
            'mrr' => 0.0,
            'ndcg_at_5' => 0.0,
        ];
        foreach ($rows as $row) {
            foreach (array_keys($sums) as $metric) {
                $sums[$metric] += (float) ($row[$metric] ?? 0.0);
            }
        }

        foreach ($sums as $metric => $sum) {
            $sums[$metric] = $sum / count($rows);
        }

        return $sums;
    }

    /**
     * @param array<string,mixed> $suite
     * @return array<string,mixed>
     */
    private static function composition(array $suite, int $documentCount, int $retrievalQueryCount, int $crossLanguageCheckCount): array
    {
        $families = [];
        foreach ($suite['queries'] as $query) {
            if (is_array($query) && isset($query['family']) && is_scalar($query['family'])) {
                $families[(string) $query['family']] = true;
            }
        }

        $requiredFamilies = [
            'polish-morphology',
            'german-detection-stemming-boundary',
            'english-explicit-override',
            'mixed-language-documents',
            'rest-visible-hidden-ranking',
            'wordpress-field-ranking',
            'fallback-no-evidence',
        ];
        $missingFamilies = [];
        foreach ($requiredFamilies as $family) {
            if (!isset($families[$family])) {
                $missingFamilies[] = $family;
            }
        }

        $failures = [];
        if ($documentCount < 30) {
            $failures[] = 'fixture composition failed: expected at least 30 documents';
        }
        if ($retrievalQueryCount < 20) {
            $failures[] = 'fixture composition failed: expected at least 20 retrieval queries';
        }
        if ($crossLanguageCheckCount < 18) {
            $failures[] = 'fixture composition failed: expected at least 18 cross-language bait checks';
        }
        foreach ($missingFamilies as $family) {
            $failures[] = "fixture composition failed: missing family {$family}";
        }

        $presentFamilies = array_keys($families);
        sort($presentFamilies, SORT_STRING);

        return [
            'document_count' => $documentCount,
            'retrieval_query_count' => $retrievalQueryCount,
            'cross_language_bait_checks' => $crossLanguageCheckCount,
            'families' => $presentFamilies,
            'required_families' => $requiredFamilies,
            'missing_families' => $missingFamilies,
            'failures' => $failures,
        ];
    }

    /**
     * @param array<string,mixed> $thresholds
     * @param array<string,mixed> $metrics
     * @return array<int,array<string,mixed>>
     */
    private static function threshold_rows(array $thresholds, array $metrics): array
    {
        $thresholds += [
            'recall_at_5' => 0.80,
            'precision_at_5' => 0.18,
            'mrr' => 0.70,
            'ndcg_at_5' => 0.75,
            'max_cross_language_false_positives' => 0,
        ];

        $rows = [
            self::threshold_row('recall_at_5', '>=', (float) $thresholds['recall_at_5'], $metrics),
            self::threshold_row('precision_at_5', '>=', (float) $thresholds['precision_at_5'], $metrics),
            self::threshold_row('mrr', '>=', (float) $thresholds['mrr'], $metrics),
            self::threshold_row('ndcg_at_5', '>=', (float) $thresholds['ndcg_at_5'], $metrics),
            self::threshold_row('cross_language_false_positive_count', '<=', (float) $thresholds['max_cross_language_false_positives'], $metrics),
            self::threshold_row('no_result_expectation_failures', '<=', 0.0, $metrics),
            self::threshold_row('top_id_expectation_failures', '<=', 0.0, $metrics),
            self::threshold_row('irrelevant_expectation_failures', '<=', 0.0, $metrics),
        ];

        return $rows;
    }

    /**
     * @param array<string,mixed> $thresholds
     * @param array<string,mixed> $metrics
     * @return array<int,array<string,mixed>>
     */
    private static function advisory_threshold_rows(array $thresholds, array $metrics): array
    {
        $rows = [];
        foreach ($thresholds as $metric => $expected) {
            if (!is_numeric($expected)) {
                continue;
            }
            $rows[] = self::threshold_row((string) $metric, '>=', (float) $expected, $metrics);
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $metrics
     * @return array<string,mixed>
     */
    private static function threshold_row(string $metric, string $operator, float $expected, array $metrics): array
    {
        $actual = (float) ($metrics[$metric] ?? 0.0);
        $passed = $operator === '>='
            ? $actual + 1e-12 >= $expected
            : $actual <= $expected + 1e-12;

        return [
            'metric' => $metric,
            'operator' => $operator,
            'expected' => $expected,
            'actual' => $actual,
            'passed' => $passed,
        ];
    }

    /**
     * @return array{0:string,1:float}|null
     */
    private static function parse_threshold_arg(string $arg): ?array
    {
        $map = [
            '--recall-at-5=' => 'recall_at_5',
            '--precision-at-5=' => 'precision_at_5',
            '--mrr=' => 'mrr',
            '--ndcg-at-5=' => 'ndcg_at_5',
            '--max-cross-language-false-positives=' => 'max_cross_language_false_positives',
        ];
        foreach ($map as $prefix => $metric) {
            if (str_starts_with($arg, $prefix)) {
                $raw = substr($arg, strlen($prefix));
                if (!is_numeric($raw)) {
                    throw new InvalidArgumentException("Threshold {$metric} must be numeric.");
                }

                return [$metric, (float) $raw];
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function required_string(array $row, string $key, string $label): string
    {
        if (!isset($row[$key]) || !is_scalar($row[$key]) || trim((string) $row[$key]) === '') {
            throw new RuntimeException("Missing {$label} {$key}.");
        }

        return (string) $row[$key];
    }

    /**
     * @return array<string,mixed>
     */
    private static function array_value(mixed $value): array
    {
        return is_array($value) ? $value : [];
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
            if (is_scalar($item) && trim((string) $item) !== '') {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }

    private static function log2(float $value): float
    {
        return log($value) / log(2.0);
    }

    private static function format_number(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return sprintf('%.4f', $value);
        }
        if (is_numeric($value)) {
            return sprintf('%.4f', (float) $value);
        }

        return 'n/a';
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(WP_FTS_Relevance_Benchmark::cli($argv));
}
