<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

/**
 * Deterministic generated-page benchmark and profiler for the reusable FTS engine.
 *
 * The workload streams WordPress-page-shaped posts through the plugin extractor,
 * analyzer, indexer, in-memory storage, and searcher. It intentionally avoids
 * writing generated corpora or index dumps into the repository.
 */
final class WP_FTS_FiftyK_Page_Profile_Benchmark
{
    /**
     * @return array<string,array{documents:int,iterations:int,warmup:int,description:string}>
     */
    public static function profiles(): array
    {
        return [
            'ci' => [
                'documents' => 192,
                'iterations' => 5,
                'warmup' => 1,
                'description' => 'Small regression guard for tests/run.php.',
            ],
            'expanded' => [
                'documents' => 5000,
                'iterations' => 15,
                'warmup' => 3,
                'description' => 'Local profiling smoke before the full 50k run.',
            ],
            '50k' => [
                'documents' => 50000,
                'iterations' => 25,
                'warmup' => 5,
                'description' => 'Manual 50k generated WordPress page benchmark.',
            ],
        ];
    }

    /**
     * @param array{documents?:int,iterations?:int,warmup?:int,progress?:bool,progress_every?:int} $overrides
     * @return array<string,mixed>
     */
    public static function run(string $profileName = 'ci', array $overrides = []): array
    {
        $profiles = self::profiles();
        if (!isset($profiles[$profileName])) {
            throw new InvalidArgumentException("Unknown profile: {$profileName}");
        }

        $profile = $profiles[$profileName];
        $documentCount = max(1, (int) ($overrides['documents'] ?? $profile['documents']));
        $iterations = max(1, (int) ($overrides['iterations'] ?? $profile['iterations']));
        $warmup = max(0, (int) ($overrides['warmup'] ?? $profile['warmup']));
        $progress = !empty($overrides['progress']);
        $progressEvery = max(1, (int) ($overrides['progress_every'] ?? 5000));

        $realAnalyzer = new WP_FTS_Analyzer([
            'default_lang' => 'en',
            'stopwords_by_lang' => [
                'en' => ['the', 'and', 'for', 'with', 'from', 'into'],
            ],
        ]);
        $analyzer = new WP_FTS_Profiled_Analyzer($realAnalyzer);
        $storage = WP_FTS_Profiled_Storage::wrap(new WP_FTS_Storage_InMemory());
        $indexer = new WP_FTS_Indexer($storage, $analyzer);
        $searcher = new WP_FTS_Searcher($storage, $analyzer);
        $extractor = new WP_FTS_PostContentExtractor();
        $extractOptions = self::extract_options();

        gc_collect_cycles();
        $memoryBefore = memory_get_usage(true);
        $peakBefore = memory_get_peak_usage(true);

        $generationMs = 0.0;
        $extractionMs = 0.0;
        $indexingMs = 0.0;
        $indexedDocs = 0;
        $fieldRows = 0;
        $rawFieldTextBytes = 0;
        $rawFieldHtmlBytes = 0;

        $indexStarted = hrtime(true);
        for ($docId = 1; $docId <= $documentCount; $docId++) {
            $started = hrtime(true);
            $post = self::generated_page_post($docId);
            $generationMs += self::elapsed_ms($started);

            $started = hrtime(true);
            $extracted = $extractor->extract($post, $extractOptions);
            $extractionMs += self::elapsed_ms($started);

            $fields = self::fields($extracted['fields'] ?? []);
            $metadata = self::metadata($extracted['metadata'] ?? []);
            $metadata['post_type'] = 'page';
            $metadata['language'] = 'en';
            $metadata['benchmark_profile'] = $profileName;
            $fieldRows += count($fields);
            foreach ($fields as $field) {
                $rawFieldTextBytes += strlen((string) ($field['text'] ?? ''));
                $rawFieldHtmlBytes += strlen((string) ($field['html'] ?? ''));
            }

            $started = hrtime(true);
            if ($indexer->index_document_fields($docId, $fields, [
                'lang' => 'en',
                'metadata' => $metadata,
            ])) {
                $indexedDocs++;
            }
            $indexingMs += self::elapsed_ms($started);

            if ($progress && ($docId === 1 || $docId % $progressEvery === 0 || $docId === $documentCount)) {
                fwrite(
                    STDERR,
                    sprintf(
                        "progress: indexed_documents=%d/%d elapsed_ms=%.3f peak_bytes=%d\n",
                        $docId,
                        $documentCount,
                        self::elapsed_ms($indexStarted),
                        memory_get_peak_usage(true)
                    )
                );
            }
        }
        if ($progress) {
            fwrite(STDERR, "progress: indexing_complete; measuring_storage\n");
        }
        $indexer->flush();
        $indexWallMs = self::elapsed_ms($indexStarted);

        $materializationStarted = hrtime(true);
        $storageMetrics = self::storage_metrics($storage->inner());
        $materializationMs = self::elapsed_ms($materializationStarted);

        $indexAnalyzer = $analyzer->summary();
        $indexStorage = $storage->summary();
        $indexStorageTotalMs = self::timer_total_ms($indexStorage);
        $indexAnalyzerTotalMs = self::timer_total_ms($indexAnalyzer);

        $queryResults = [];
        foreach (self::queries($documentCount) as $queryId => $definition) {
            if ($progress) {
                fwrite(STDERR, "progress: measuring_query={$queryId}\n");
            }
            $analyzer->reset();
            $storage->reset();
            $queryResults[$queryId] = self::measure_query($searcher, $definition, $iterations, $warmup, $analyzer, $storage);
        }

        $memoryAfter = memory_get_usage(true);
        $peakAfter = memory_get_peak_usage(true);

        $phaseTotals = [
            'generation_ms' => $generationMs,
            'extraction_ms' => $extractionMs,
            'indexing_ms' => $indexingMs,
            'index_wall_ms' => $indexWallMs,
            'index_analyzer_ms' => $indexAnalyzerTotalMs,
            'index_storage_ms' => $indexStorageTotalMs,
            'indexer_overhead_ms' => max(0.0, $indexingMs - $indexAnalyzerTotalMs - $indexStorageTotalMs),
            'storage_materialization_ms' => $materializationMs,
        ];

        $summary = [
            'schema' => 'wp-fts-50k-page-profile-v1',
            'profile' => [
                'name' => $profileName,
                'documents' => $documentCount,
                'default_documents' => $profile['documents'],
                'description' => $profile['description'],
                'available_profiles' => array_keys($profiles),
            ],
            'workload' => [
                'document_shape' => 'generated WordPress pages via WP_FTS_PostContentExtractor',
                'post_type' => 'page',
                'language' => 'en',
                'iterations' => $iterations,
                'warmup' => $warmup,
            ],
            'storage_backend' => [
                'class' => get_class($storage->inner()),
                'row_postings' => $storage instanceof WP_FTS_Row_Postings_Storage,
                'document_terms' => $storage instanceof WP_FTS_Document_Terms_Storage,
            ],
            'indexing' => [
                'indexed_documents' => $indexedDocs,
                'wall_ms' => $indexWallMs,
                'docs_per_second' => $indexedDocs > 0 && $indexWallMs > 0.0 ? $indexedDocs / ($indexWallMs / 1000.0) : 0.0,
                'field_rows' => $fieldRows,
                'field_text_bytes' => $rawFieldTextBytes,
                'field_html_bytes' => $rawFieldHtmlBytes,
            ],
            'phase_totals' => $phaseTotals,
            'storage_timers' => $indexStorage,
            'analyzer_timers' => $indexAnalyzer,
            'search_queries' => $queryResults,
            'storage' => $storageMetrics,
            'memory' => [
                'usage_before_bytes' => $memoryBefore,
                'usage_after_bytes' => $memoryAfter,
                'usage_delta_bytes' => max(0, $memoryAfter - $memoryBefore),
                'peak_before_bytes' => $peakBefore,
                'peak_after_bytes' => $peakAfter,
                'peak_delta_bytes' => max(0, $peakAfter - $peakBefore),
            ],
        ];
        $summary['passed'] = self::passes_basic_checks($summary);

        return $summary;
    }

    /**
     * @param array<string,mixed> $result
     */
    public static function format_text(array $result): string
    {
        $profile = self::array_value($result['profile'] ?? []);
        $indexing = self::array_value($result['indexing'] ?? []);
        $phaseTotals = self::array_value($result['phase_totals'] ?? []);
        $storageBackend = self::array_value($result['storage_backend'] ?? []);
        $storage = self::array_value($result['storage'] ?? []);
        $memory = self::array_value($result['memory'] ?? []);
        $lines = [
            (($result['passed'] ?? false) ? 'PASS' : 'FAIL') . ': 50k page FTS profile benchmark',
            sprintf(
                'profile: %s; documents=%d; iterations=%d; warmup=%d',
                (string) ($profile['name'] ?? ''),
                (int) ($profile['documents'] ?? 0),
                (int) (($result['workload']['iterations'] ?? 0)),
                (int) (($result['workload']['warmup'] ?? 0))
            ),
            sprintf(
                'storage_backend: %s row_postings=%s document_terms=%s',
                (string) ($storageBackend['class'] ?? ''),
                !empty($storageBackend['row_postings']) ? 'yes' : 'no',
                !empty($storageBackend['document_terms']) ? 'yes' : 'no'
            ),
            sprintf(
                'indexing: indexed_docs=%d wall_ms=%.3f docs_per_sec=%.3f fields=%d',
                (int) ($indexing['indexed_documents'] ?? 0),
                (float) ($indexing['wall_ms'] ?? 0.0),
                (float) ($indexing['docs_per_second'] ?? 0.0),
                (int) ($indexing['field_rows'] ?? 0)
            ),
            sprintf(
                'phases_ms: generation=%.3f extraction=%.3f analyzer=%.3f storage=%.3f indexer_overhead=%.3f materialization=%.3f',
                (float) ($phaseTotals['generation_ms'] ?? 0.0),
                (float) ($phaseTotals['extraction_ms'] ?? 0.0),
                (float) ($phaseTotals['index_analyzer_ms'] ?? 0.0),
                (float) ($phaseTotals['index_storage_ms'] ?? 0.0),
                (float) ($phaseTotals['indexer_overhead_ms'] ?? 0.0),
                (float) ($phaseTotals['storage_materialization_ms'] ?? 0.0)
            ),
            sprintf(
                'storage: docs=%d metadata_rows=%d terms=%d postings=%d postings_bytes=%d estimated_bytes=%d',
                (int) ($storage['document_count'] ?? 0),
                (int) ($storage['metadata_rows'] ?? 0),
                (int) ($storage['term_count'] ?? 0),
                (int) ($storage['posting_count'] ?? 0),
                (int) ($storage['postings_bytes'] ?? 0),
                (int) ($storage['storage_bytes_estimate'] ?? 0)
            ),
            sprintf(
                'memory: usage_delta=%d peak_delta=%d peak_after=%d',
                (int) ($memory['usage_delta_bytes'] ?? 0),
                (int) ($memory['peak_delta_bytes'] ?? 0),
                (int) ($memory['peak_after_bytes'] ?? 0)
            ),
            'search:',
        ];

        foreach (self::array_value($result['search_queries'] ?? []) as $id => $query) {
            $latency = self::array_value($query['latency_ms'] ?? []);
            $lines[] = sprintf(
                '  %s: candidates=%d avg=%.6f p50=%.6f p95=%.6f p99=%.6f storage=%.6f analyzer=%.6f residual=%.6f top=%s checksum=%s',
                (string) $id,
                (int) ($query['candidate_count'] ?? 0),
                (float) ($latency['avg'] ?? 0.0),
                (float) ($latency['p50'] ?? 0.0),
                (float) ($latency['p95'] ?? 0.0),
                (float) ($latency['p99'] ?? 0.0),
                (float) ($query['storage_ms'] ?? 0.0),
                (float) ($query['analyzer_ms'] ?? 0.0),
                (float) ($query['residual_ms'] ?? 0.0),
                implode(',', self::int_list($query['top_doc_ids'] ?? [])),
                (string) ($query['checksum'] ?? '')
            );
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param string[] $argv
     */
    public static function main(array $argv): int
    {
        $profile = 'ci';
        $json = false;
        $overrides = [];

        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--json') {
                $json = true;
                continue;
            }
            if ($arg === '--progress') {
                $overrides['progress'] = true;
                continue;
            }
            if ($arg === '--help' || $arg === '-h') {
                fwrite(STDOUT, "Usage: php tests/benchmarks/fts-50k-page-profile.php [--profile=ci|expanded|50k] [--documents=N] [--iterations=N] [--warmup=N] [--progress] [--progress-every=N] [--json]\n");
                return 0;
            }
            if (str_starts_with($arg, '--profile=')) {
                $profile = substr($arg, strlen('--profile='));
                continue;
            }
            if (str_starts_with($arg, '--documents=')) {
                $overrides['documents'] = self::positive_int_arg($arg, '--documents=');
                continue;
            }
            if (str_starts_with($arg, '--iterations=')) {
                $overrides['iterations'] = self::positive_int_arg($arg, '--iterations=');
                continue;
            }
            if (str_starts_with($arg, '--warmup=')) {
                $overrides['warmup'] = self::non_negative_int_arg($arg, '--warmup=');
                continue;
            }
            if (str_starts_with($arg, '--progress-every=')) {
                $overrides['progress'] = true;
                $overrides['progress_every'] = self::positive_int_arg($arg, '--progress-every=');
                continue;
            }

            fwrite(STDERR, "Unknown argument: {$arg}\n");
            return 2;
        }

        try {
            $result = self::run($profile, $overrides);
            if ($json) {
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            } else {
                echo self::format_text($result);
            }

            return !empty($result['passed']) ? 0 : 1;
        } catch (Throwable $e) {
            if ($json) {
                echo json_encode([
                    'passed' => false,
                    'error' => $e->getMessage(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            } else {
                fwrite(STDERR, $e->getMessage() . "\n");
            }

            return 1;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function measure_query(
        WP_FTS_Searcher $searcher,
        array $definition,
        int $iterations,
        int $warmup,
        WP_FTS_Profiled_Analyzer $analyzer,
        WP_FTS_Profiled_Storage $storage
    ): array {
        $query = (string) ($definition['query'] ?? '');
        $opts = self::array_value($definition['options'] ?? []);
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

        $analyzer->reset();
        $storage->reset();

        $durations = [];
        $lastRows = [];
        $checksum = hash_init('sha256');
        for ($i = 0; $i < $iterations; $i++) {
            $started = hrtime(true);
            $rows = $searcher->search($query, $opts);
            $durations[] = self::elapsed_ms($started);
            $lastRows = self::result_rows($rows);
            hash_update($checksum, json_encode(self::result_signature($lastRows), JSON_THROW_ON_ERROR));
        }

        $totalMs = array_sum($durations);
        $analyzerMs = self::timer_total_ms($analyzer->summary());
        $storageMs = self::timer_total_ms($storage->summary());

        return [
            'query' => $query,
            'options' => $opts,
            'candidate_count' => $candidateCount,
            'latency_ms' => self::latency_distribution($durations),
            'total_measured_ms' => $totalMs,
            'storage_ms' => $storageMs,
            'analyzer_ms' => $analyzerMs,
            'residual_ms' => max(0.0, $totalMs - $storageMs - $analyzerMs),
            'top_doc_ids' => array_map('intval', array_column($lastRows, 'doc_id')),
            'checksum' => hash_final($checksum),
        ];
    }

    /**
     * @return array<string,array{query:string,options:array<string,mixed>}>
     */
    private static function queries(int $documentCount): array
    {
        return [
            'broad_common_or' => [
                'query' => 'wordpress page performance',
                'options' => [
                    'lang' => 'en',
                    'limit' => 10,
                ],
            ],
            'topic_and' => [
                'query' => 'atlas harbor editorial',
                'options' => [
                    'lang' => 'en',
                    'mode' => 'AND',
                    'limit' => 10,
                ],
            ],
            'metadata_filter' => [
                'query' => 'targetprofileterm',
                'options' => [
                    'lang' => 'en',
                    'limit' => 10,
                    'post_type' => 'page',
                    'post_status' => 'publish',
                    'include_metadata' => true,
                ],
            ],
            'snippet_highlight' => [
                'query' => 'inline highlight accessibility',
                'options' => [
                    'lang' => 'en',
                    'limit' => 10,
                    'include_metadata' => true,
                    'include_snippets' => true,
                    'highlight' => true,
                    'snippet_length' => 180,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function storage_metrics(WP_FTS_Storage $storage): array
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
        foreach (['terms', 'postingsByTerm', 'termsByDoc', 'docs', 'docMetadata', 'meta'] as $property) {
            if (!property_exists($storage, $property)) {
                continue;
            }

            $reflection = new ReflectionProperty($storage, $property);
            $reflection->setAccessible(true);
            $snapshot[$property] = $reflection->getValue($storage);
        }

        return [
            'document_count' => count($storage->all_doc_ids(false)),
            'document_rows' => count($storage->all_doc_ids(true)),
            'metadata_rows' => count($snapshot['docMetadata'] ?? []),
            'term_count' => count($terms),
            'posting_count' => $postingCount,
            'postings_bytes' => $postingsBytes,
            'field_text_rows' => count($snapshot['docMetadata'] ?? []),
            'meta_rows' => count($snapshot['meta'] ?? []),
            'storage_bytes_estimate' => strlen(serialize($snapshot)),
        ];
    }

    /**
     * @return object
     */
    private static function generated_page_post(int $docId): object
    {
        $topics = self::topics();
        $topic = $topics[($docId - 1) % count($topics)];
        $neighbor = $topics[$docId % count($topics)];
        $variant = intdiv($docId - 1, count($topics));
        $template = ['landing', 'documentation', 'support', 'comparison', 'pricing'][($docId - 1) % 5];
        $audience = ['editors', 'publishers', 'developers', 'marketers'][($docId - 1) % 4];
        $targetTerm = $docId % 17 === 0 ? 'targetprofileterm' : 'benchmarkvariant';
        $title = sprintf('%s %s page benchmark %d', $topic['label'], $template, $docId);
        $excerpt = sprintf(
            '%s checklist for %s teams covering WordPress search, accessibility, and performance variant %d.',
            $topic['terms'][1],
            $audience,
            $variant
        );

        $paragraphs = [];
        for ($i = 0; $i < 1; $i++) {
            $paragraphs[] = sprintf(
                '<p>%s %s %s WordPress page performance %s %s %s %s sharedtopic%d %s inline <strong>highlight</strong> accessibility checklist.</p>',
                $topic['terms'][$i % count($topic['terms'])],
                $topic['terms'][1],
                $topic['terms'][2],
                $neighbor['terms'][$i % count($neighbor['terms'])],
                $template,
                $audience,
                $targetTerm,
                ($docId + $i) % 17,
                $i % 2 === 0 ? 'landing conversion' : 'editorial archive'
            );
        }

        return (object) [
            'ID' => $docId,
            'post_type' => 'page',
            'post_status' => $docId % 23 === 0 ? 'private' : 'publish',
            'post_title' => $title,
            'post_excerpt' => $excerpt,
            'post_content' => '<!-- wp:heading --><h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2><!-- /wp:heading -->'
                . implode('', $paragraphs),
            'post_date_gmt' => sprintf('2026-06-%02d %02d:00:00', 1 + ($docId % 28), $docId % 24),
            'custom_fields' => [
                'section' => [$topic['label']],
                'template' => [$template],
                'audience' => [$audience],
            ],
        ];
    }

    /**
     * @return array<int,array{label:string,terms:array<int,string>}>
     */
    private static function topics(): array
    {
        return [
            ['label' => 'Atlas', 'terms' => ['atlas', 'harbor', 'editorial', 'vector']],
            ['label' => 'Beacon', 'terms' => ['beacon', 'orchard', 'landing', 'signal']],
            ['label' => 'Cinder', 'terms' => ['cinder', 'matrix', 'support', 'archive']],
            ['label' => 'Delta', 'terms' => ['delta', 'kernel', 'conversion', 'checklist']],
            ['label' => 'Ember', 'terms' => ['ember', 'canvas', 'accessibility', 'module']],
            ['label' => 'Fjord', 'terms' => ['fjord', 'packet', 'performance', 'engine']],
            ['label' => 'Granite', 'terms' => ['granite', 'socket', 'index', 'metadata']],
            ['label' => 'Harbor', 'terms' => ['harbor', 'queue', 'snippet', 'highlight']],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function extract_options(): array
    {
        return [
            'render_blocks' => false,
            'render_shortcodes' => false,
            'metadata_text_limit' => 20000,
        ];
    }

    /**
     * @param array<int,mixed> $fields
     * @return array<int,array<string,mixed>>
     */
    private static function fields(array $fields): array
    {
        return array_values(array_filter($fields, static fn(mixed $field): bool => is_array($field)));
    }

    /**
     * @param mixed $metadata
     * @return array<string,mixed>
     */
    private static function metadata(mixed $metadata): array
    {
        return is_array($metadata) ? $metadata : [];
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function passes_basic_checks(array $result): bool
    {
        $profile = self::array_value($result['profile'] ?? []);
        $indexing = self::array_value($result['indexing'] ?? []);
        $storage = self::array_value($result['storage'] ?? []);
        $queries = self::array_value($result['search_queries'] ?? []);

        if ((int) ($indexing['indexed_documents'] ?? 0) !== (int) ($profile['documents'] ?? -1)) {
            return false;
        }
        if ((int) ($storage['document_count'] ?? 0) !== (int) ($profile['documents'] ?? -1)) {
            return false;
        }
        if ((int) ($storage['term_count'] ?? 0) <= 0 || (int) ($storage['posting_count'] ?? 0) <= 0) {
            return false;
        }

        foreach ($queries as $query) {
            $topDocIds = self::int_list($query['top_doc_ids'] ?? []);
            if ($topDocIds === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int,array<string,mixed>>|array<string,mixed> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function result_rows(array $rows): array
    {
        if (isset($rows['results']) && is_array($rows['results'])) {
            return self::fields($rows['results']);
        }

        return self::fields($rows);
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
                'title' => isset($row['title']) ? (string) $row['title'] : null,
                'snippet' => isset($row['snippet']) ? (string) $row['snippet'] : null,
            ];
        }

        return $signature;
    }

    /**
     * @param float[] $durations
     * @return array{min:float,p50:float,p95:float,p99:float,max:float,avg:float}
     */
    private static function latency_distribution(array $durations): array
    {
        sort($durations, SORT_NUMERIC);
        $count = count($durations);
        if ($count === 0) {
            return ['min' => 0.0, 'p50' => 0.0, 'p95' => 0.0, 'p99' => 0.0, 'max' => 0.0, 'avg' => 0.0];
        }

        return [
            'min' => $durations[0],
            'p50' => self::percentile($durations, 0.50),
            'p95' => self::percentile($durations, 0.95),
            'p99' => self::percentile($durations, 0.99),
            'max' => $durations[$count - 1],
            'avg' => array_sum($durations) / $count,
        ];
    }

    /**
     * @param float[] $sorted
     */
    private static function percentile(array $sorted, float $percentile): float
    {
        $count = count($sorted);
        if ($count === 0) {
            return 0.0;
        }
        $index = max(0, min($count - 1, (int) ceil($count * $percentile) - 1));

        return $sorted[$index];
    }

    /**
     * @param array<string,array{calls:int,total_ms:float,avg_ms:float}> $summary
     */
    private static function timer_total_ms(array $summary): float
    {
        $total = 0.0;
        foreach ($summary as $row) {
            $total += (float) ($row['total_ms'] ?? 0.0);
        }

        return $total;
    }

    private static function elapsed_ms(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000;
    }

    private static function positive_int_arg(string $arg, string $prefix): int
    {
        $value = substr($arg, strlen($prefix));
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new InvalidArgumentException("Expected positive integer for {$prefix}");
        }

        return (int) $value;
    }

    private static function non_negative_int_arg(string $arg, string $prefix): int
    {
        $value = substr($arg, strlen($prefix));
        if (preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1) {
            throw new InvalidArgumentException("Expected non-negative integer for {$prefix}");
        }

        return (int) $value;
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    private static function array_value(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param mixed $value
     * @return int[]
     */
    private static function int_list(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map('intval', $value));
    }
}

/**
 * Timing adapter for analyzer calls made by the indexer and searcher.
 */
final class WP_FTS_Profiled_Analyzer
{
    /** @var array<string,array{calls:int,total_ms:float}> */
    private array $timers = [];

    public function __construct(private WP_FTS_Analyzer $inner)
    {
    }

    /**
     * @param array<string,mixed>|string|null $options
     * @return array<int,array<string,mixed>>
     */
    public function analyze_content(string $html, array|string|null $options = []): array
    {
        return $this->timed('analyze_content', fn(): array => $this->inner->analyze_content($html, $options));
    }

    /**
     * @param array<string,mixed>|string|null $options
     * @return array<int,array<string,mixed>>
     */
    public function analyze_plain_content(string $text, array|string|null $options = []): array
    {
        return $this->timed('analyze_plain_content', fn(): array => $this->inner->analyze_plain_content($text, $options));
    }

    /**
     * @param array<string,mixed>|string|null $options
     * @return array<int,array<string,mixed>>
     */
    public function analyze_query(string $query, array|string|null $options = []): array
    {
        return $this->timed('analyze_query', fn(): array => $this->inner->analyze_query($query, $options));
    }

    public function index_signature(): string
    {
        return $this->timed('index_signature', fn(): string => $this->inner->index_signature());
    }

    public function reset(): void
    {
        $this->timers = [];
    }

    /**
     * @return array<string,array{calls:int,total_ms:float,avg_ms:float}>
     */
    public function summary(): array
    {
        return $this->summary_from_timers($this->timers);
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function timed(string $name, callable $fn): mixed
    {
        $started = hrtime(true);
        try {
            return $fn();
        } finally {
            $this->record($name, (hrtime(true) - $started) / 1_000_000);
        }
    }

    private function record(string $name, float $ms): void
    {
        $this->timers[$name]['calls'] = ($this->timers[$name]['calls'] ?? 0) + 1;
        $this->timers[$name]['total_ms'] = ($this->timers[$name]['total_ms'] ?? 0.0) + $ms;
    }

    /**
     * @param array<string,array{calls:int,total_ms:float}> $timers
     * @return array<string,array{calls:int,total_ms:float,avg_ms:float}>
     */
    private function summary_from_timers(array $timers): array
    {
        $summary = [];
        foreach ($timers as $name => $row) {
            $calls = max(1, (int) $row['calls']);
            $summary[$name] = [
                'calls' => $calls,
                'total_ms' => (float) $row['total_ms'],
                'avg_ms' => (float) $row['total_ms'] / $calls,
            ];
        }
        uasort($summary, static fn(array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']);

        return $summary;
    }
}

/**
 * Timing adapter for storage calls made by the indexer and searcher.
 */
class WP_FTS_Profiled_Storage implements WP_FTS_Storage, WP_FTS_DocumentMetadataStorage, WP_FTS_DocumentMetadataFilterStorage
{
    /** @var array<string,array{calls:int,total_ms:float}> */
    private array $timers = [];

    public function __construct(protected WP_FTS_Storage&WP_FTS_DocumentMetadataStorage $inner)
    {
    }

    public static function wrap(WP_FTS_Storage&WP_FTS_DocumentMetadataStorage $inner): self
    {
        if ($inner instanceof WP_FTS_Row_Postings_Storage && $inner instanceof WP_FTS_Document_Terms_Storage) {
            return new WP_FTS_Profiled_Row_Postings_Storage($inner);
        }

        return new self($inner);
    }

    public function inner(): WP_FTS_Storage
    {
        return $this->inner;
    }

    public function get_terms(array $terms): array
    {
        return $this->timed('get_terms', fn(): array => $this->inner->get_terms($terms));
    }

    public function put_term(string $term, int $df, string $postings): void
    {
        $this->timed('put_term', fn(): null => $this->inner->put_term($term, $df, $postings));
    }

    public function delete_term(string $term): void
    {
        $this->timed('delete_term', fn(): null => $this->inner->delete_term($term));
    }

    public function get_doc_lengths(array $doc_ids, ?string $lang = null): array
    {
        return $this->timed('get_doc_lengths', fn(): array => $this->inner->get_doc_lengths($doc_ids, $lang));
    }

    public function get_doc(int $doc_id): ?array
    {
        return $this->timed('get_doc', fn(): ?array => $this->inner->get_doc($doc_id));
    }

    public function put_doc(int $doc_id, string|int $primary_lang, array|string $lang_lengths, ?string $hash = null): void
    {
        $this->timed('put_doc', fn(): null => $this->inner->put_doc($doc_id, $primary_lang, $lang_lengths, $hash));
    }

    public function delete_doc(int $doc_id): void
    {
        $this->timed('delete_doc', fn(): null => $this->inner->delete_doc($doc_id));
    }

    public function put_doc_metadata(int $doc_id, array $metadata): void
    {
        $this->timed('put_doc_metadata', fn(): null => $this->inner->put_doc_metadata($doc_id, $metadata));
    }

    public function get_doc_metadata(array $doc_ids): array
    {
        return $this->timed('get_doc_metadata', fn(): array => $this->inner->get_doc_metadata($doc_ids));
    }

    public function filter_doc_ids_by_metadata(
        array $doc_ids,
        array $post_types = [],
        array $post_statuses = [],
        ?string $date_after = null,
        ?string $date_before = null
    ): array {
        return $this->timed(
            'filter_doc_ids_by_metadata',
            fn(): array => WP_FTS_StorageCompat::filter_doc_ids_by_metadata(
                $this->inner,
                $doc_ids,
                $post_types,
                $post_statuses,
                $date_after,
                $date_before
            )
        );
    }

    public function get_meta(?string $lang = null): array
    {
        return $this->timed('get_meta', fn(): array => $this->inner->get_meta($lang));
    }

    public function add_meta(string|int $lang, int $d_docs, ?int $d_len = null): void
    {
        $this->timed('add_meta', fn(): null => $this->inner->add_meta($lang, $d_docs, $d_len));
    }

    public function all_terms(): array
    {
        return $this->timed('all_terms', fn(): array => $this->inner->all_terms());
    }

    public function all_doc_ids(bool $include_deleted = false): array
    {
        return $this->timed('all_doc_ids', fn(): array => $this->inner->all_doc_ids($include_deleted));
    }

    public function begin_transaction(): void
    {
        $this->timed('begin_transaction', fn(): null => $this->inner->begin_transaction());
    }

    public function commit(): void
    {
        $this->timed('commit', fn(): null => $this->inner->commit());
    }

    public function rollback(): void
    {
        $this->timed('rollback', fn(): null => $this->inner->rollback());
    }

    public function flush(): void
    {
        $this->timed('flush', fn(): null => $this->inner->flush());
    }

    public function optimize(): void
    {
        $this->timed('optimize', fn(): null => $this->inner->optimize());
    }

    public function reset(): void
    {
        $this->timers = [];
    }

    /**
     * @return array<string,array{calls:int,total_ms:float,avg_ms:float}>
     */
    public function summary(): array
    {
        $summary = [];
        foreach ($this->timers as $name => $row) {
            $calls = max(1, (int) $row['calls']);
            $summary[$name] = [
                'calls' => $calls,
                'total_ms' => (float) $row['total_ms'],
                'avg_ms' => (float) $row['total_ms'] / $calls,
            ];
        }
        uasort($summary, static fn(array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']);

        return $summary;
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    protected function timed(string $name, callable $fn): mixed
    {
        $started = hrtime(true);
        try {
            return $fn();
        } finally {
            $this->timers[$name]['calls'] = ($this->timers[$name]['calls'] ?? 0) + 1;
            $this->timers[$name]['total_ms'] = ($this->timers[$name]['total_ms'] ?? 0.0) + ((hrtime(true) - $started) / 1_000_000);
        }
    }
}

/**
 * Timing adapter that preserves row-postings capabilities when the wrapped
 * backend actually supports them.
 */
final class WP_FTS_Profiled_Row_Postings_Storage extends WP_FTS_Profiled_Storage implements WP_FTS_Row_Postings_Storage, WP_FTS_Document_Terms_Storage
{
    public function replace_doc_postings(int $doc_id, array $term_frequencies): void
    {
        $this->timed(
            'replace_doc_postings',
            fn(): null => $this->row_storage()->replace_doc_postings($doc_id, $term_frequencies)
        );
    }

    public function get_postings(array $terms): array
    {
        return $this->timed('get_postings', fn(): array => $this->row_storage()->get_postings($terms));
    }

    public function terms_for_doc(int $doc_id): array
    {
        return $this->timed('terms_for_doc', fn(): array => $this->document_terms_storage()->terms_for_doc($doc_id));
    }

    private function row_storage(): WP_FTS_Row_Postings_Storage
    {
        if (!$this->inner instanceof WP_FTS_Row_Postings_Storage) {
            throw new LogicException('Wrapped storage no longer exposes row postings.');
        }

        return $this->inner;
    }

    private function document_terms_storage(): WP_FTS_Document_Terms_Storage
    {
        if (!$this->inner instanceof WP_FTS_Document_Terms_Storage) {
            throw new LogicException('Wrapped storage no longer exposes document terms.');
        }

        return $this->inner;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(WP_FTS_FiftyK_Page_Profile_Benchmark::main($argv));
}
