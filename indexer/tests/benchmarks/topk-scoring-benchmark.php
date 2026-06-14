<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

/**
 * Deterministic top-K scoring benchmark for WP_FTS_Searcher.
 *
 * The workload intentionally uses a common term across many documents with a
 * small result limit so full-result sorting is visible in query timing.
 */
final class WP_FTS_TopK_Scoring_Benchmark
{
    private const LANGUAGES = ['en', 'pl', 'de', 'es', 'fr'];

    /**
     * @param string[] $argv
     */
    public static function main(array $argv): int
    {
        $documentCount = self::int_option($argv, '--documents', 1000);
        $iterations = self::int_option($argv, '--iterations', 200);
        $warmup = self::int_option($argv, '--warmup', 20);
        $json = in_array('--json', $argv, true);

        $analyzer = new WP_FTS_Analyzer([
            'default_lang' => 'en',
            'enable_stemming' => false,
            'auto_detect_language' => false,
        ]);
        $storage = new WP_FTS_Storage_InMemory();
        $indexer = new WP_FTS_Indexer($storage, $analyzer);

        $started = hrtime(true);
        foreach (self::documents($documentCount) as $doc) {
            $indexer->index_document_fields(
                $doc['id'],
                [
                    [
                        'name' => 'content',
                        'html' => $doc['html'],
                        'boost' => 1.0,
                    ],
                ],
                [
                    'lang' => $doc['lang'],
                    'metadata' => [
                        'post_id' => $doc['id'],
                        'post_type' => $doc['id'] % 11 === 0 ? 'page' : 'post',
                        'post_status' => 'publish',
                        'post_date_gmt' => sprintf('2026-06-%02d 12:00:00', ($doc['id'] % 27) + 1),
                        'title' => sprintf('Synthetic %s document %d', $doc['lang'], $doc['id']),
                        'excerpt' => '',
                        'language' => $doc['lang'],
                    ],
                ]
            );
        }
        $indexer->flush();
        $indexMs = self::elapsed_ms($started);

        $searcher = new WP_FTS_Searcher($storage, $analyzer);
        $queries = [
            'common_limit_10' => [
                'query' => 'commonterm',
                'opts' => [
                    'langs' => self::LANGUAGES,
                    'limit' => 10,
                ],
            ],
            'multi_limit_10' => [
                'query' => 'commonterm sharedtopic',
                'opts' => [
                    'langs' => self::LANGUAGES,
                    'limit' => 10,
                ],
            ],
            'snippet_highlight_limit_10' => [
                'query' => 'WordPress',
                'opts' => [
                    'langs' => self::LANGUAGES,
                    'limit' => 10,
                    'include_metadata' => true,
                    'include_snippets' => true,
                    'highlight' => true,
                    'snippet_length' => 140,
                ],
            ],
        ];

        $queryResults = [];
        foreach ($queries as $name => $definition) {
            $queryResults[$name] = self::measure_query(
                $searcher,
                $definition['query'],
                $definition['opts'],
                $iterations,
                $warmup
            );
        }

        $result = [
            'schema' => 'wp-fts-topk-scoring-benchmark-v1',
            'documents' => $documentCount,
            'tokens_per_document' => '80-150',
            'languages' => self::LANGUAGES,
            'iterations' => $iterations,
            'warmup' => $warmup,
            'indexing' => [
                'time_ms' => $indexMs,
            ],
            'storage' => self::storage_metrics($storage),
            'queries' => $queryResults,
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ];

        if ($json) {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            return 0;
        }

        printf("Indexed %d documents in %.3f ms\n", $documentCount, $indexMs);
        foreach ($queryResults as $name => $metrics) {
            printf(
                "%s: candidates=%d avg=%.6f ms p95=%.6f ms top=%s\n",
                $name,
                $metrics['candidate_count'],
                $metrics['avg_ms'],
                $metrics['p95_ms'],
                implode(',', $metrics['top_doc_ids'])
            );
        }

        return 0;
    }

    /**
     * @return iterable<int,array{id:int,lang:string,html:string}>
     */
    private static function documents(int $count): iterable
    {
        $fillers = [
            'en' => ['river', 'library', 'garden', 'signal', 'market', 'silver', 'planet', 'window'],
            'pl' => ['miasto', 'projekt', 'notatka', 'ogrod', 'rynek', 'srebro', 'planeta', 'okno'],
            'de' => ['fluss', 'bibliothek', 'garten', 'signal', 'markt', 'silber', 'planet', 'fenster'],
            'es' => ['rio', 'biblioteca', 'jardin', 'senal', 'mercado', 'plata', 'planeta', 'ventana'],
            'fr' => ['riviere', 'bibliotheque', 'jardin', 'signal', 'marche', 'argent', 'planete', 'fenetre'],
        ];

        for ($id = 1; $id <= $count; $id++) {
            $lang = self::LANGUAGES[($id - 1) % count(self::LANGUAGES)];
            $targetLength = 80 + (($id * 17) % 71);
            $tokens = [];

            for ($i = 0; $i < $targetLength; $i++) {
                $bucket = $fillers[$lang][($id + $i) % count($fillers[$lang])];
                $tokens[] = $bucket . (($id + $i) % 23);
            }

            for ($i = 0; $i < 1 + ($id % 5); $i++) {
                $tokens[] = 'commonterm';
            }
            for ($i = 0; $i < 1 + ($id % 3); $i++) {
                $tokens[] = 'sharedtopic';
            }
            if ($id % 97 === 0) {
                $tokens[] = 'rareterm';
            }
            if ($id % 19 === 0) {
                $tokens[] = '<strong>Word</strong>Press';
            }
            if ($lang === 'pl' && $id % 29 === 0) {
                $tokens[] = 'Szk<em>l<i><b>ar</b></i></em>nia';
            }

            yield [
                'id' => $id,
                'lang' => $lang,
                'html' => '<article lang="' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '"><p>'
                    . implode(' ', $tokens)
                    . '</p></article>',
            ];
        }
    }

    /**
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private static function measure_query(
        WP_FTS_Searcher $searcher,
        string $query,
        array $opts,
        int $iterations,
        int $warmup
    ): array {
        $countOpts = $opts;
        unset($countOpts['include_metadata'], $countOpts['include_snippets'], $countOpts['snippets'], $countOpts['highlight']);
        $countOpts['include_total'] = true;
        $candidatePayload = $searcher->search($query, $countOpts);
        $candidateCount = is_array($candidatePayload) && isset($candidatePayload['total'])
            ? (int) $candidatePayload['total']
            : 0;

        for ($i = 0; $i < $warmup; $i++) {
            $searcher->search($query, $opts);
        }

        $durations = [];
        $lastRows = [];
        $checksum = hash_init('sha256');
        for ($i = 0; $i < $iterations; $i++) {
            $started = hrtime(true);
            $rows = $searcher->search($query, $opts);
            $durations[] = self::elapsed_ms($started);
            $lastRows = $rows;
            hash_update($checksum, json_encode(self::result_signature($rows), JSON_THROW_ON_ERROR));
        }

        $sorted = $durations;
        sort($sorted, SORT_NUMERIC);
        $p95Index = max(0, (int) ceil(count($sorted) * 0.95) - 1);

        return [
            'query' => $query,
            'options' => $opts,
            'candidate_count' => $candidateCount,
            'avg_ms' => array_sum($durations) / max(1, count($durations)),
            'p95_ms' => $sorted[$p95Index] ?? 0.0,
            'min_ms' => $sorted[0] ?? 0.0,
            'max_ms' => $sorted[count($sorted) - 1] ?? 0.0,
            'top_doc_ids' => array_map('intval', array_column($lastRows, 'doc_id')),
            'checksum' => hash_final($checksum),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function result_signature(array $rows): array
    {
        $signature = [];
        foreach ($rows as $row) {
            $signature[] = [
                'doc_id' => (int) ($row['doc_id'] ?? 0),
                'score' => round((float) ($row['score'] ?? 0.0), 12),
                'snippet' => isset($row['snippet']) ? (string) $row['snippet'] : null,
            ];
        }

        return $signature;
    }

    /**
     * @return array{term_count:int,posting_count:int,postings_bytes:int,storage_bytes_estimate:int}
     */
    private static function storage_metrics(WP_FTS_Storage_InMemory $storage): array
    {
        $terms = $storage->all_terms();
        $rows = $storage->get_terms($terms);
        $postingCount = 0;
        $postingsBytes = 0;
        foreach ($rows as $row) {
            $postings = WP_FTS_PostingsCodec::decode((string) $row['postings']);
            $postingCount += count($postings);
            $postingsBytes += strlen((string) $row['postings']);
        }

        $snapshot = [];
        foreach (['terms', 'docs', 'docMetadata', 'meta'] as $property) {
            $reflection = new ReflectionProperty($storage, $property);
            $reflection->setAccessible(true);
            $snapshot[$property] = $reflection->getValue($storage);
        }

        return [
            'term_count' => count($terms),
            'posting_count' => $postingCount,
            'postings_bytes' => $postingsBytes,
            'storage_bytes_estimate' => strlen(serialize($snapshot)),
        ];
    }

    /**
     * @param string[] $argv
     */
    private static function int_option(array $argv, string $name, int $default): int
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, $name . '=')) {
                return max(1, (int) substr($arg, strlen($name) + 1));
            }
        }

        return $default;
    }

    private static function elapsed_ms(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000;
    }
}

exit(WP_FTS_TopK_Scoring_Benchmark::main($argv));
