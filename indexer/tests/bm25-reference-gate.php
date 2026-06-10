<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/**
 * Deterministic local BM25 reference gate for the native PHP searcher.
 *
 * The fixture is intentionally small enough to audit by hand, but it still uses
 * the production indexer/searcher path so field boosts, weighted term
 * frequencies, document length normalization, missing terms, and boolean
 * narrowing are all covered by the comparison.
 */
final class WP_FTS_BM25_Reference_Gate
{
    public const SCHEMA = 'wp-fts-native-bm25-reference-gate-v1';
    public const EPSILON = 1e-9;

    private const K1 = 1.2;
    private const B = 0.75;

    /**
     * Run the fixture through the native searcher and the local oracle.
     *
     * @return array<string,mixed>
     */
    public static function run(): array
    {
        $fixture = self::fixture();
        $storage = new WP_FTS_Storage_InMemory();
        $analyzer = self::analyzer();
        $indexer = new WP_FTS_Indexer($storage, $analyzer);

        foreach ($fixture['documents'] as $document) {
            $indexer->index_document_fields(
                (int) $document['id'],
                $document['fields'],
                ['lang' => 'en']
            );
        }

        $searcher = new WP_FTS_Searcher($storage, $analyzer, self::K1, self::B);
        $oraclePostings = self::oracle_postings($fixture['documents']);
        $nativePostings = self::native_postings($storage, array_keys($oraclePostings));
        $docLengths = self::doc_lengths($fixture['documents']);
        $collection = self::collection_summary($docLengths);
        $failures = [];

        if ($nativePostings !== $oraclePostings) {
            $failures[] = 'native postings do not match the fixture weighted term-frequency oracle';
        }

        $queryReports = [];
        $maxDelta = 0.0;
        foreach ($fixture['queries'] as $query) {
            $queryReport = self::compare_query($searcher, $oraclePostings, $docLengths, $query);
            $maxDelta = max($maxDelta, (float) $queryReport['max_delta']);
            foreach ($queryReport['failures'] as $failure) {
                $failures[] = (string) $query['id'] . ': ' . $failure;
            }
            $queryReports[] = $queryReport;
        }

        return [
            'schema' => self::SCHEMA,
            'passed' => $failures === [],
            'epsilon' => self::EPSILON,
            'k1' => self::K1,
            'b' => self::B,
            'corpus' => [
                'language' => 'en',
                'doc_count' => count($fixture['documents']),
                'avg_doc_len' => $collection['avg_doc_len'],
                'documents' => self::document_report($fixture['documents'], $docLengths),
            ],
            'postings' => [
                'match' => $nativePostings === $oraclePostings,
                'oracle' => $oraclePostings,
                'native' => $nativePostings,
            ],
            'queries' => $queryReports,
            'max_delta' => $maxDelta,
            'optional_dependencies' => [],
            'failures' => $failures,
        ];
    }

    /**
     * Render a compact text report for humans.
     *
     * @param array<string,mixed> $result
     */
    public static function format_text(array $result): string
    {
        $lines = [
            ((bool) ($result['passed'] ?? false) ? 'PASS' : 'FAIL') . ': Native BM25 reference gate',
            'schema: ' . (string) ($result['schema'] ?? ''),
            sprintf(
                'documents: %d; queries: %d; max_delta=%.12g; postings=%s',
                (int) ($result['corpus']['doc_count'] ?? 0),
                count(is_array($result['queries'] ?? null) ? $result['queries'] : []),
                (float) ($result['max_delta'] ?? 0.0),
                !empty($result['postings']['match']) ? 'match' : 'mismatch'
            ),
            'queries:',
        ];

        foreach (is_array($result['queries'] ?? null) ? $result['queries'] : [] as $query) {
            $lines[] = sprintf(
                '  %s: oracle=%s native=%s max_delta=%.12g and=%s',
                (string) ($query['id'] ?? ''),
                implode(',', self::string_list($query['oracle_top_ids'] ?? [])),
                implode(',', self::string_list($query['native_top_ids'] ?? [])),
                (float) ($query['max_delta'] ?? 0.0),
                implode(',', self::string_list($query['and_native_top_ids'] ?? []))
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
     * Return a stable JSON representation.
     *
     * @param array<string,mixed> $result
     */
    public static function to_json(array $result): string
    {
        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Could not encode BM25 reference gate JSON.');
        }

        return $json . "\n";
    }

    /**
     * Command-line entry point.
     *
     * @param string[] $argv
     */
    public static function cli(array $argv): int
    {
        $json = false;
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--json') {
                $json = true;
                continue;
            }
            if ($arg === '--help' || $arg === '-h') {
                fwrite(STDOUT, "Usage: php tests/bm25-reference-gate.php [--json]\n");
                return 0;
            }

            fwrite(STDERR, "Unknown argument: {$arg}\n");
            return 2;
        }

        try {
            $result = self::run();
            fwrite(STDOUT, $json ? self::to_json($result) : self::format_text($result));

            return (bool) $result['passed'] ? 0 : 1;
        } catch (Throwable $e) {
            if ($json) {
                $encoded = json_encode([
                    'schema' => self::SCHEMA,
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
     * @return array<string,mixed>
     */
    private static function fixture(): array
    {
        return [
            'documents' => [
                [
                    'id' => 101,
                    'label' => 'title boosted apple',
                    'fields' => [
                        ['name' => 'title', 'text' => 'apple', 'boost' => 4.0],
                        ['name' => 'content', 'text' => 'banana cafe', 'boost' => 1.0],
                    ],
                    'term_frequencies' => ['apple' => 4, 'banana' => 1, 'cafe' => 1],
                ],
                [
                    'id' => 202,
                    'label' => 'title boosted banana',
                    'fields' => [
                        ['name' => 'title', 'text' => 'banana', 'boost' => 3.0],
                        ['name' => 'content', 'text' => 'carrot carrot', 'boost' => 1.0],
                    ],
                    'term_frequencies' => ['banana' => 3, 'carrot' => 2],
                ],
                [
                    'id' => 303,
                    'label' => 'short durian apple',
                    'fields' => [
                        ['name' => 'content', 'text' => 'durian apple', 'boost' => 1.0],
                    ],
                    'term_frequencies' => ['apple' => 1, 'durian' => 1],
                ],
                [
                    'id' => 404,
                    'label' => 'short apple carrot',
                    'fields' => [
                        ['name' => 'content', 'text' => 'apple carrot', 'boost' => 1.0],
                    ],
                    'term_frequencies' => ['apple' => 1, 'carrot' => 1],
                ],
            ],
            'queries' => [
                [
                    'id' => 'apple',
                    'query' => 'apple',
                    'terms' => ['apple'],
                    'expected_top_ids' => [101, 303, 404],
                    'expected_and_top_ids' => [101, 303, 404],
                ],
                [
                    'id' => 'carrot apple',
                    'query' => 'carrot apple',
                    'terms' => ['carrot', 'apple'],
                    'expected_top_ids' => [404, 202, 101, 303],
                    'expected_and_top_ids' => [404],
                ],
                [
                    'id' => 'banana missing',
                    'query' => 'banana missing',
                    'terms' => ['banana', 'missing'],
                    'expected_top_ids' => [202, 101],
                    'expected_and_top_ids' => [],
                ],
                [
                    'id' => 'durian carrot',
                    'query' => 'durian carrot',
                    'terms' => ['durian', 'carrot'],
                    'expected_top_ids' => [303, 202, 404],
                    'expected_and_top_ids' => [],
                ],
                [
                    'id' => 'apple banana carrot',
                    'query' => 'apple banana carrot',
                    'terms' => ['apple', 'banana', 'carrot'],
                    'expected_top_ids' => [202, 404, 101, 303],
                    'expected_and_top_ids' => [],
                ],
            ],
        ];
    }

    private static function analyzer(): WP_FTS_Analyzer
    {
        return new WP_FTS_Analyzer([
            'default_lang' => 'en',
            'auto_detect_language' => false,
            'enable_stemming' => false,
            'stopwords' => [],
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $documents
     * @return array<string,array<int,int>>
     */
    private static function oracle_postings(array $documents): array
    {
        $postings = [];
        foreach ($documents as $document) {
            $docId = (int) $document['id'];
            foreach (self::int_map($document['term_frequencies']) as $term => $tf) {
                $postings[$term][$docId] = $tf;
            }
        }

        ksort($postings, SORT_STRING);
        foreach ($postings as &$rows) {
            ksort($rows, SORT_NUMERIC);
        }
        unset($rows);

        return $postings;
    }

    /**
     * @param string[] $terms
     * @return array<string,array<int,int>>
     */
    private static function native_postings(WP_FTS_Storage $storage, array $terms): array
    {
        $termKeys = [];
        foreach ($terms as $term) {
            $termKeys[$term] = WP_FTS_TermNamespace::namespace_term('en', $term);
        }

        $raw = WP_FTS_StorageCompat::get_postings($storage, array_values($termKeys));
        $postings = [];
        foreach ($termKeys as $term => $key) {
            $rows = $raw[$key] ?? [];
            ksort($rows, SORT_NUMERIC);
            $postings[$term] = $rows;
        }
        ksort($postings, SORT_STRING);

        return $postings;
    }

    /**
     * @param array<int,array<string,mixed>> $documents
     * @return array<int,int>
     */
    private static function doc_lengths(array $documents): array
    {
        $lengths = [];
        foreach ($documents as $document) {
            $lengths[(int) $document['id']] = array_sum(self::int_map($document['term_frequencies']));
        }
        ksort($lengths, SORT_NUMERIC);

        return $lengths;
    }

    /**
     * @param array<int,int> $docLengths
     * @return array{doc_count:int,avg_doc_len:float}
     */
    private static function collection_summary(array $docLengths): array
    {
        $docCount = count($docLengths);

        return [
            'doc_count' => $docCount,
            'avg_doc_len' => array_sum($docLengths) / max(1, $docCount),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $documents
     * @param array<int,int> $docLengths
     * @return array<int,array<string,mixed>>
     */
    private static function document_report(array $documents, array $docLengths): array
    {
        $rows = [];
        foreach ($documents as $document) {
            $docId = (int) $document['id'];
            $rows[] = [
                'id' => $docId,
                'label' => (string) $document['label'],
                'doc_len' => $docLengths[$docId],
                'term_frequencies' => self::int_map($document['term_frequencies']),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,array<int,int>> $oraclePostings
     * @param array<int,int> $docLengths
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private static function compare_query(WP_FTS_Searcher $searcher, array $oraclePostings, array $docLengths, array $query): array
    {
        $terms = self::string_list($query['terms'] ?? []);
        $oracleScores = self::oracle_scores($oraclePostings, $docLengths, $terms, 'OR');
        $nativeScores = self::scores_from_rows($searcher->search((string) $query['query'], [
            'lang' => 'en',
            'mode' => 'OR',
            'limit' => 10,
        ]));
        $deltas = self::score_deltas($oracleScores, $nativeScores);
        $maxDelta = max($deltas ?: [0.0]);

        $andOracleScores = self::oracle_scores($oraclePostings, $docLengths, $terms, 'AND');
        $andNativeScores = self::scores_from_rows($searcher->search((string) $query['query'], [
            'lang' => 'en',
            'mode' => 'AND',
            'limit' => 10,
        ]));
        $andDeltas = self::score_deltas($andOracleScores, $andNativeScores);
        $andMaxDelta = max($andDeltas ?: [0.0]);

        $expectedTopIds = array_map('intval', $query['expected_top_ids'] ?? []);
        $expectedAndTopIds = array_map('intval', $query['expected_and_top_ids'] ?? []);
        $oracleTopIds = array_keys($oracleScores);
        $nativeTopIds = array_keys($nativeScores);
        $andOracleTopIds = array_keys($andOracleScores);
        $andNativeTopIds = array_keys($andNativeScores);

        $failures = [];
        if ($oracleTopIds !== $expectedTopIds) {
            $failures[] = 'oracle OR top ids changed; expected ' . implode(', ', $expectedTopIds) . ' got ' . implode(', ', $oracleTopIds);
        }
        if ($nativeTopIds !== $oracleTopIds) {
            $failures[] = 'native OR top ids differ from oracle; expected ' . implode(', ', $oracleTopIds) . ' got ' . implode(', ', $nativeTopIds);
        }
        if ($maxDelta > self::EPSILON) {
            $failures[] = sprintf('native OR scores differ from oracle by %.12g', $maxDelta);
        }
        if ($andOracleTopIds !== $expectedAndTopIds) {
            $failures[] = 'oracle AND top ids changed; expected ' . implode(', ', $expectedAndTopIds) . ' got ' . implode(', ', $andOracleTopIds);
        }
        if ($andNativeTopIds !== $andOracleTopIds) {
            $failures[] = 'native AND top ids differ from oracle; expected ' . implode(', ', $andOracleTopIds) . ' got ' . implode(', ', $andNativeTopIds);
        }
        if ($andMaxDelta > self::EPSILON) {
            $failures[] = sprintf('native AND scores differ from oracle by %.12g', $andMaxDelta);
        }

        return [
            'id' => (string) $query['id'],
            'query' => (string) $query['query'],
            'terms' => $terms,
            'expected_top_ids' => $expectedTopIds,
            'oracle_top_ids' => $oracleTopIds,
            'native_top_ids' => $nativeTopIds,
            'oracle_scores' => $oracleScores,
            'native_scores' => $nativeScores,
            'score_delta' => $deltas,
            'max_delta' => $maxDelta,
            'expected_and_top_ids' => $expectedAndTopIds,
            'and_oracle_top_ids' => $andOracleTopIds,
            'and_native_top_ids' => $andNativeTopIds,
            'and_oracle_scores' => $andOracleScores,
            'and_native_scores' => $andNativeScores,
            'and_score_delta' => $andDeltas,
            'and_max_delta' => $andMaxDelta,
            'failures' => $failures,
        ];
    }

    /**
     * @param array<string,array<int,int>> $postings
     * @param array<int,int> $docLengths
     * @param string[] $queryTerms
     * @return array<int,float>
     */
    private static function oracle_scores(array $postings, array $docLengths, array $queryTerms, string $mode): array
    {
        $mode = strtoupper($mode);
        $docCount = count($docLengths);
        $avgDocLen = array_sum($docLengths) / max(1, $docCount);
        $scores = [];
        $matchedTermsByDoc = [];

        foreach ($queryTerms as $term) {
            if (!isset($postings[$term])) {
                if ($mode === 'AND') {
                    return [];
                }
                continue;
            }

            $docFreq = 0;
            foreach ($postings[$term] as $docId => $_tf) {
                if (isset($docLengths[$docId])) {
                    $docFreq++;
                }
            }
            if ($docFreq === 0) {
                if ($mode === 'AND') {
                    return [];
                }
                continue;
            }

            foreach ($postings[$term] as $docId => $tf) {
                if (!isset($docLengths[$docId])) {
                    continue;
                }

                $scores[$docId] = ($scores[$docId] ?? 0.0) + self::bm25_score($tf, $docLengths[$docId], $docCount, $docFreq, $avgDocLen);
                $matchedTermsByDoc[$docId][$term] = true;
            }
        }

        if ($mode === 'AND') {
            $required = array_fill_keys($queryTerms, true);
            $scores = array_filter(
                $scores,
                static fn(int $docId): bool => isset($matchedTermsByDoc[$docId]) && count(array_intersect_key($required, $matchedTermsByDoc[$docId])) === count($required),
                ARRAY_FILTER_USE_KEY
            );
        }

        uksort($scores, static function (int $a, int $b) use ($scores): int {
            $scoreOrder = $scores[$b] <=> $scores[$a];

            return $scoreOrder !== 0 ? $scoreOrder : ($a <=> $b);
        });

        return $scores;
    }

    private static function bm25_score(int $tf, int $docLen, int $docCount, int $docFreq, float $avgDocLen): float
    {
        $idf = log(1.0 + (($docCount - $docFreq + 0.5) / ($docFreq + 0.5)));
        $normalizer = $tf + self::K1 * (1.0 - self::B + self::B * ($docLen / max(1.0, $avgDocLen)));

        return $idf * (($tf * (self::K1 + 1.0)) / $normalizer);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,float>
     */
    private static function scores_from_rows(array $rows): array
    {
        $scores = [];
        foreach ($rows as $row) {
            $scores[(int) $row['doc_id']] = (float) $row['score'];
        }

        return $scores;
    }

    /**
     * @param array<int,float> $expected
     * @param array<int,float> $actual
     * @return array<int,float>
     */
    private static function score_deltas(array $expected, array $actual): array
    {
        $docIds = array_values(array_unique(array_merge(array_keys($expected), array_keys($actual))));
        sort($docIds, SORT_NUMERIC);
        $deltas = [];
        foreach ($docIds as $docId) {
            $deltas[(int) $docId] = abs(($expected[$docId] ?? 0.0) - ($actual[$docId] ?? 0.0));
        }

        return $deltas;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function string_list(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', $value));
    }

    /**
     * @param mixed $value
     * @return array<string,int>
     */
    private static function int_map(mixed $value): array
    {
        $result = [];
        if (!is_array($value)) {
            return $result;
        }

        foreach ($value as $key => $raw) {
            if (is_scalar($key)) {
                $result[(string) $key] = (int) $raw;
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(WP_FTS_BM25_Reference_Gate::cli($argv));
}
