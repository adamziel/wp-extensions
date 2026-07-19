<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/**
 * Deterministic generated-corpus scale fixture for the legacy component path.
 *
 * This intentionally stays pure PHP: it exercises the component analyzer,
 * indexer, in-memory storage, and posting-list searcher. It is not evidence for
 * WordPress, relational MySQL/MariaDB, or production traffic.
 */
final class WP_FTS_Production_Scale_Benchmark
{
    public const DEFAULT_PROFILE = 'pr-safe';
    public const EVIDENCE_NOTE = 'pure-PHP generated corpus evidence only; not live MySQL proof and not production traffic proof';

    /**
     * @return array<string,array{documents:int,window_limit:int,description:string}>
     */
    public static function profiles(): array
    {
        return [
            'pr-safe' => [
                'documents' => 128,
                'window_limit' => 8,
                'description' => 'Default PR-safe generated corpus loaded by tests/run.php.',
            ],
            'expanded' => [
                'documents' => 512,
                'window_limit' => 12,
                'description' => 'Optional larger generated corpus for local scale evidence.',
            ],
        ];
    }

    /**
     * @param array{documents?:int,window_limit?:int} $options
     * @return array<string,mixed>
     */
    public static function run(string $profileName = self::DEFAULT_PROFILE, array $options = []): array
    {
        $profiles = self::profiles();
        if (!isset($profiles[$profileName])) {
            throw new InvalidArgumentException("Unknown production-scale benchmark profile: {$profileName}");
        }

        $profile = $profiles[$profileName];
        $documentCount = max(1, (int) ($options['documents'] ?? $profile['documents']));
        $windowLimit = max(1, (int) ($options['window_limit'] ?? $profile['window_limit']));

        $analyzer = new WP_FTS_Analyzer([
            'stopwords_by_lang' => ['en' => ['the', 'and', 'for']],
        ]);
        $storage = new WP_FTS_Storage_InMemory();
        $indexer = new WP_FTS_Indexer($storage, $analyzer);
        $documents = self::generate_documents($documentCount);

        gc_collect_cycles();
        $memoryBefore = self::memory_usage();
        $peakBefore = self::memory_peak_usage();
        $started = microtime(true);

        $rawTokenOccurrences = 0;
        $indexChanges = 0;
        foreach ($documents as $document) {
            $rawTokenOccurrences += self::count_field_occurrences($analyzer, $document['fields'], $document['language']);
            if ($indexer->index_document_fields((int) $document['post_id'], $document['fields'], [
                'lang' => $document['language'],
                'metadata' => $document['metadata'],
            ])) {
                $indexChanges++;
            }
        }

        $indexer->flush();
        $indexDurationMs = (int) round((microtime(true) - $started) * 1000);
        $searcher = new WP_FTS_Searcher($storage, $analyzer);

        $queryReports = self::run_query_checks($searcher, $documentCount);
        $windowReports = self::run_result_windows($searcher, $windowLimit);
        $queryCheckTotalDurationMs = self::sum_duration_ms($queryReports);
        $queryCheckMaxDurationMs = self::max_duration_ms($queryReports);
        $resultWindowTotalDurationMs = self::sum_duration_ms($windowReports);
        $resultWindowMaxDurationMs = self::max_duration_ms($windowReports);
        $searchReadTotalDurationMs = $queryCheckTotalDurationMs + $resultWindowTotalDurationMs;
        $storageCounters = self::storage_counters($storage);
        $memoryAfter = self::memory_usage();
        $peakAfter = self::memory_peak_usage();

        $metrics = array_merge($storageCounters, [
            'configured_documents' => $documentCount,
            'indexed_documents' => $indexChanges,
            'raw_token_occurrences' => $rawTokenOccurrences,
            'weighted_token_instances' => self::weighted_token_instances($storage),
            'query_checks' => count($queryReports),
            'query_checks_passed' => count(array_filter($queryReports, static fn(array $row): bool => (bool) $row['passed'])),
            'multi_token_checks_passed' => count(array_filter($queryReports, static fn(array $row): bool => (bool) $row['passed'] && (string) $row['family'] === 'multi-token')),
            'folding_checks_passed' => count(array_filter($queryReports, static fn(array $row): bool => (bool) $row['passed'] && (string) $row['family'] === 'folding')),
            'result_windows' => count($windowReports),
            'result_window_limit' => $windowLimit,
            'hydrated_result_rows' => array_sum(array_map(static fn(array $row): int => (int) $row['hydrated_rows'], $windowReports)),
            'hydrated_rows_with_metadata' => array_sum(array_map(static fn(array $row): int => (int) $row['metadata_rows'], $windowReports)),
            'hydrated_rows_with_snippets' => array_sum(array_map(static fn(array $row): int => (int) $row['snippet_rows'], $windowReports)),
            'index_duration_ms' => $indexDurationMs,
            'query_check_total_duration_ms' => $queryCheckTotalDurationMs,
            'query_check_max_duration_ms' => $queryCheckMaxDurationMs,
            'result_window_total_duration_ms' => $resultWindowTotalDurationMs,
            'result_window_max_duration_ms' => $resultWindowMaxDurationMs,
            'search_read_total_duration_ms' => $searchReadTotalDurationMs,
            'memory_delta_bytes' => $memoryBefore === null || $memoryAfter === null ? null : max(0, $memoryAfter - $memoryBefore),
            'peak_memory_delta_bytes' => $peakBefore === null || $peakAfter === null ? null : max(0, $peakAfter - $peakBefore),
        ]);

        $gates = self::evaluate_gates($metrics, self::gates_for($documentCount, $windowLimit));
        $performanceBudget = self::performance_budget_summary($metrics, $gates);
        $failures = [];
        foreach ($queryReports as $queryReport) {
            if (!$queryReport['passed']) {
                $failures[] = 'query check failed: ' . $queryReport['id'];
            }
        }
        foreach ($windowReports as $windowReport) {
            if (!$windowReport['passed']) {
                $failures[] = 'result-window hydration failed: ' . $windowReport['id'];
            }
        }
        foreach ($gates as $gate) {
            if (!$gate['passed']) {
                if (($gate['category'] ?? '') === 'performance') {
                    $failures[] = sprintf(
                        'performance budget gate failed: %s %s %s (actual %s)',
                        (string) $gate['metric'],
                        (string) $gate['operator'],
                        self::format_nullable_int($gate['expected']),
                        self::format_nullable_int($gate['actual'])
                    );
                    continue;
                }

                $failures[] = 'gate failed: ' . $gate['metric'];
            }
        }

        return [
            'passed' => $failures === [],
            'evidence' => self::EVIDENCE_NOTE,
            'profile' => [
                'name' => $profileName,
                'documents' => $documentCount,
                'default_documents' => $profile['documents'],
                'available_profiles' => array_keys($profiles),
                'description' => $profile['description'],
            ],
            'metrics' => $metrics,
            'gates' => $gates,
            'performance_budget' => $performanceBudget,
            'query_checks' => $queryReports,
            'result_windows' => $windowReports,
            'failures' => $failures,
        ];
    }

    /**
     * @param array<string,mixed> $result
     */
    public static function format_text(array $result): string
    {
        $metrics = self::array_value($result['metrics'] ?? []);
        $profile = self::array_value($result['profile'] ?? []);
        $lines = [
            ($result['passed'] ? 'PASS' : 'FAIL') . ': Native production-scale generated benchmark gates',
            'evidence: ' . (string) ($result['evidence'] ?? self::EVIDENCE_NOTE),
            sprintf(
                'profile: %s; documents=%d; available_profiles=%s',
                (string) ($profile['name'] ?? ''),
                (int) ($profile['documents'] ?? 0),
                implode(',', self::string_list($profile['available_profiles'] ?? []))
            ),
            sprintf(
                'counters: indexed_docs=%d raw_tokens=%d weighted_tokens=%d unique_terms=%d postings=%d materialized_rows=%d hydrated_rows=%d memory_delta=%s peak_delta=%s index_ms=%d query_ms=%d window_ms=%d search_read_ms=%d',
                (int) ($metrics['indexed_documents'] ?? 0),
                (int) ($metrics['raw_token_occurrences'] ?? 0),
                (int) ($metrics['weighted_token_instances'] ?? 0),
                (int) ($metrics['unique_terms'] ?? 0),
                (int) ($metrics['posting_rows'] ?? 0),
                (int) ($metrics['materialized_rows'] ?? 0),
                (int) ($metrics['hydrated_result_rows'] ?? 0),
                self::format_nullable_int($metrics['memory_delta_bytes'] ?? null),
                self::format_nullable_int($metrics['peak_memory_delta_bytes'] ?? null),
                (int) ($metrics['index_duration_ms'] ?? 0),
                (int) ($metrics['query_check_total_duration_ms'] ?? 0),
                (int) ($metrics['result_window_total_duration_ms'] ?? 0),
                (int) ($metrics['search_read_total_duration_ms'] ?? 0)
            ),
            'gates:',
        ];

        foreach (self::array_value($result['gates'] ?? []) as $gate) {
            $lines[] = sprintf(
                '  [%s] %s %s %s: %s (actual %s)',
                (string) ($gate['category'] ?? 'structural'),
                (string) ($gate['metric'] ?? ''),
                (string) ($gate['operator'] ?? ''),
                self::format_nullable_int($gate['expected'] ?? null),
                !empty($gate['passed']) ? 'pass' : 'fail',
                self::format_nullable_int($gate['actual'] ?? null)
            );
        }

        $lines[] = 'query_checks:';
        foreach (self::array_value($result['query_checks'] ?? []) as $query) {
            $lines[] = sprintf(
                '  %s [%s/%s]: expected_top=%d actual_top=%d duration_ms=%d %s',
                (string) ($query['id'] ?? ''),
                (string) ($query['family'] ?? ''),
                (string) ($query['mode'] ?? ''),
                (int) ($query['expected_top_doc_id'] ?? 0),
                (int) ($query['actual_top_doc_id'] ?? 0),
                (int) ($query['duration_ms'] ?? 0),
                !empty($query['passed']) ? 'pass' : 'fail'
            );
        }

        $lines[] = 'result_windows:';
        foreach (self::array_value($result['result_windows'] ?? []) as $window) {
            $lines[] = sprintf(
                '  %s: total=%d hydrated=%d metadata=%d snippets=%d duration_ms=%d %s',
                (string) ($window['id'] ?? ''),
                (int) ($window['total'] ?? 0),
                (int) ($window['hydrated_rows'] ?? 0),
                (int) ($window['metadata_rows'] ?? 0),
                (int) ($window['snippet_rows'] ?? 0),
                (int) ($window['duration_ms'] ?? 0),
                !empty($window['passed']) ? 'pass' : 'fail'
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
     * @param string[] $argv
     */
    public static function cli(array $argv): int
    {
        $profile = self::DEFAULT_PROFILE;
        $json = false;
        $options = [];

        try {
            foreach (array_slice($argv, 1) as $arg) {
                if ($arg === '--json') {
                    $json = true;
                    continue;
                }
                if ($arg === '--help' || $arg === '-h') {
                    fwrite(STDOUT, "Usage: php tests/production-scale-benchmark.php [--profile=pr-safe|expanded] [--documents=N] [--window-limit=N] [--json]\n");
                    fwrite(STDOUT, "Evidence: " . self::EVIDENCE_NOTE . "\n");
                    return 0;
                }
                if (str_starts_with($arg, '--profile=')) {
                    $profile = substr($arg, strlen('--profile='));
                    continue;
                }
                if (str_starts_with($arg, '--documents=')) {
                    $options['documents'] = self::positive_int_arg($arg, '--documents=');
                    continue;
                }
                if (str_starts_with($arg, '--window-limit=')) {
                    $options['window_limit'] = self::positive_int_arg($arg, '--window-limit=');
                    continue;
                }

                fwrite(STDERR, "Unknown argument: {$arg}\n");
                return 2;
            }

            $result = self::run($profile, $options);
            if ($json) {
                self::write_json($result, STDOUT);
            } else {
                fwrite(STDOUT, self::format_text($result));
            }

            return $result['passed'] ? 0 : 1;
        } catch (Throwable $e) {
            if ($json) {
                self::write_json([
                    'passed' => false,
                    'evidence' => self::EVIDENCE_NOTE,
                    'error' => $e->getMessage(),
                ], STDOUT);
            } else {
                fwrite(STDERR, $e->getMessage() . "\n");
            }

            return 1;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function generate_documents(int $documentCount): array
    {
        $documents = [];
        for ($docId = 1; $docId <= $documentCount; $docId++) {
            $documents[] = self::generated_document($docId);
        }

        return $documents;
    }

    /**
     * @return array<string,mixed>
     */
    private static function generated_document(int $docId): array
    {
        $special = self::special_document($docId);
        if ($special !== null) {
            return $special;
        }

        $topics = self::topics();
        $topic = $topics[($docId - 1) % count($topics)];
        $variant = intdiv($docId - 1, count($topics));
        $neighbor = $topics[$docId % count($topics)];
        $langSpan = $docId % 5 === 0 ? '<span lang="pl">zamek most kolor</span>' : '<span lang="de">strasse hafen farbe</span>';
        $body = implode(' ', [
            'production benchmark generated corpus',
            $topic['terms'][0],
            $topic['terms'][1],
            $topic['terms'][2],
            $neighbor['terms'][0],
            'window hydration metadata snippet',
            'revision' . $variant,
            'shard' . ($docId % 11),
        ]);
        $content = '<article>'
            . '<h2>' . self::escape_html($topic['label']) . ' production benchmark ' . $docId . '</h2>'
            . '<p>' . self::escape_html($body) . '</p>'
            . '<p>' . $langSpan . '</p>'
            . '<aside>navigation bait should be skipped</aside>'
            . '</article>';

        return self::document_from_parts(
            $docId,
            (string) $topic['label'] . ' generated scale note ' . $docId,
            (string) $topic['terms'][1] . ' ' . (string) $topic['terms'][2] . ' production excerpt ' . ($docId % 9),
            $body,
            $content,
            'en',
            $docId % 9 === 0 ? 'page' : 'post'
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function special_document(int $docId): ?array
    {
        if ($docId === 1) {
            return self::document_from_parts(
                1,
                'Vector alpha calibration anchor',
                'vector alpha calibration gateway anchor',
                'vector alpha calibration gateway anchor production benchmark vector alpha',
                '<article><h1>Vector alpha calibration anchor</h1><p>gateway vector alpha calibration production benchmark</p></article>',
                'en',
                'post'
            );
        }

        if ($docId === 2) {
            return self::document_from_parts(
                2,
                'Accent folding benchmark anchor',
                'folding diacritic generated corpus check',
                'folding diacritic generated corpus',
                '<article><p>Caf&eacute; r&eacute;sum&eacute; co&ouml;perate facade production benchmark</p></article>',
                'en',
                'post'
            );
        }

        if ($docId === 3) {
            return self::document_from_parts(
                3,
                'Hydration window benchmark anchor',
                'metadata snippet hydration production benchmark',
                'hydration window metadata snippet production benchmark result row',
                '<article><h2>Hydration metadata snippet</h2><p>production benchmark result row hydration window metadata snippet</p></article>',
                'en',
                'page'
            );
        }

        return null;
    }

    /**
     * @return array<int,array{label:string,terms:array<int,string>}>
     */
    private static function topics(): array
    {
        return [
            ['label' => 'Atlas', 'terms' => ['atlas', 'harbor', 'vector']],
            ['label' => 'Beacon', 'terms' => ['beacon', 'orchard', 'ledger']],
            ['label' => 'Cinder', 'terms' => ['cinder', 'matrix', 'signal']],
            ['label' => 'Delta', 'terms' => ['delta', 'kernel', 'archive']],
            ['label' => 'Ember', 'terms' => ['ember', 'canvas', 'module']],
            ['label' => 'Fjord', 'terms' => ['fjord', 'packet', 'engine']],
            ['label' => 'Granite', 'terms' => ['granite', 'socket', 'index']],
            ['label' => 'Harbor', 'terms' => ['harbor', 'queue', 'filter']],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function document_from_parts(
        int $postId,
        string $title,
        string $excerpt,
        string $body,
        string $content,
        string $language,
        string $postType
    ): array {
        $fields = [
            ['name' => 'title', 'text' => $title, 'boost' => 5.0],
            ['name' => 'excerpt', 'text' => $excerpt, 'boost' => 2.0],
            ['name' => 'body', 'text' => $body, 'boost' => 1.5],
            ['name' => 'content', 'text' => trim(strip_tags($content)), 'html' => $content, 'boost' => 1.0],
        ];
        $searchText = trim(implode(' ', [$title, $excerpt, $body, strip_tags($content)]));

        return [
            'post_id' => $postId,
            'language' => $language,
            'fields' => $fields,
            'metadata' => [
                'post_id' => $postId,
                'post_type' => $postType,
                'post_status' => 'publish',
                'post_date_gmt' => sprintf('2026-06-%02d 00:00:00', 1 + ($postId % 28)),
                'title' => $title,
                'excerpt' => $excerpt,
                'search_text' => $searchText,
                'field_boosts' => [
                    'title' => 5.0,
                    'excerpt' => 2.0,
                    'body' => 1.5,
                    'content' => 1.0,
                ],
            ],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     */
    private static function count_field_occurrences(WP_FTS_Analyzer $analyzer, array $fields, string $language): int
    {
        $count = 0;
        foreach ($fields as $field) {
            $fieldOpts = [
                'lang' => $language,
                'field_name' => (string) ($field['name'] ?? 'content'),
            ];
            $html = isset($field['html'])
                ? (string) $field['html']
                : '<div>' . self::escape_html((string) ($field['text'] ?? '')) . '</div>';
            $count += count($analyzer->analyze_content($html, $fieldOpts));
        }

        return $count;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function run_query_checks(WP_FTS_Searcher $searcher, int $documentCount): array
    {
        $checks = [
            [
                'id' => 'multi-token-anchor',
                'family' => 'multi-token',
                'query' => 'vector alpha calibration',
                'mode' => 'AND',
                'expected_top_doc_id' => 1,
            ],
            [
                'id' => 'folding-diacritic-anchor',
                'family' => 'folding',
                'query' => 'cafe resume cooperate',
                'mode' => 'AND',
                'expected_top_doc_id' => 2,
            ],
            [
                'id' => 'hydration-anchor',
                'family' => 'multi-token',
                'query' => 'hydration metadata snippet',
                'mode' => 'AND',
                'expected_top_doc_id' => 3,
            ],
        ];

        $reports = [];
        foreach ($checks as $check) {
            if ((int) $check['expected_top_doc_id'] > $documentCount) {
                continue;
            }
            $started = microtime(true);
            $rows = $searcher->search((string) $check['query'], [
                'lang' => 'en',
                'mode' => (string) $check['mode'],
                'limit' => 5,
            ]);
            $durationMs = self::elapsed_ms($started);
            $topDocId = isset($rows[0]['doc_id']) ? (int) $rows[0]['doc_id'] : 0;
            $reports[] = [
                'id' => $check['id'],
                'family' => $check['family'],
                'query' => $check['query'],
                'mode' => $check['mode'],
                'expected_top_doc_id' => $check['expected_top_doc_id'],
                'actual_top_doc_id' => $topDocId,
                'result_count' => count($rows),
                'duration_ms' => $durationMs,
                'passed' => $topDocId === (int) $check['expected_top_doc_id'],
            ];
        }

        return $reports;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function run_result_windows(WP_FTS_Searcher $searcher, int $windowLimit): array
    {
        $windows = [];
        foreach ([0, $windowLimit, $windowLimit * 2] as $offset) {
            $started = microtime(true);
            $response = $searcher->search('production benchmark generated', [
                'lang' => 'en',
                'mode' => 'AND',
                'limit' => $windowLimit,
                'offset' => $offset,
                'include_total' => true,
                'include_metadata' => true,
                'include_snippets' => true,
                'snippet_length' => 96,
                'highlight' => true,
            ]);
            $durationMs = self::elapsed_ms($started);
            $results = isset($response['results']) && is_array($response['results']) ? $response['results'] : [];
            $metadataRows = count(array_filter($results, static fn(array $row): bool => isset($row['title']) && (string) $row['title'] !== ''));
            $snippetRows = count(array_filter($results, static fn(array $row): bool => isset($row['snippet']) && (string) $row['snippet'] !== ''));
            $hydratedRows = count($results);
            $windows[] = [
                'id' => 'production-window-offset-' . $offset,
                'offset' => $offset,
                'limit' => $windowLimit,
                'total' => (int) ($response['total'] ?? 0),
                'hydrated_rows' => $hydratedRows,
                'metadata_rows' => $metadataRows,
                'snippet_rows' => $snippetRows,
                'duration_ms' => $durationMs,
                'passed' => $hydratedRows > 0 && $metadataRows === $hydratedRows && $snippetRows === $hydratedRows,
            ];
        }

        return $windows;
    }

    /**
     * @return array<string,int>
     */
    private static function storage_counters(WP_FTS_Storage_InMemory $storage): array
    {
        $terms = $storage->all_terms();
        $postingRows = 0;
        foreach ($storage->get_terms($terms) as $row) {
            $postingRows += count(WP_FTS_PostingsCodec::decode($row['postings']));
        }

        $docIds = $storage->all_doc_ids();
        $docMetadata = $storage->get_doc_metadata($docIds);
        $languageLengthRows = 0;
        $metaLangs = [];
        foreach ($docIds as $docId) {
            $doc = $storage->get_doc((int) $docId);
            foreach (($doc['lang_lengths'] ?? []) as $lang => $length) {
                if ((int) $length > 0) {
                    $languageLengthRows++;
                    $metaLangs[(string) $lang] = true;
                }
            }
        }

        $metaRows = 1 + count($metaLangs);
        $materializedRows = count($terms)
            + $postingRows
            + count($docIds)
            + count($docMetadata)
            + $languageLengthRows
            + $metaRows;

        return [
            'unique_terms' => count($terms),
            'posting_rows' => $postingRows,
            'document_rows' => count($docIds),
            'document_metadata_rows' => count($docMetadata),
            'language_length_rows' => $languageLengthRows,
            'meta_rows' => $metaRows,
            'materialized_rows' => $materializedRows,
        ];
    }

    private static function weighted_token_instances(WP_FTS_Storage_InMemory $storage): int
    {
        $total = 0;
        foreach ($storage->get_doc_lengths($storage->all_doc_ids()) as $length) {
            $total += (int) $length;
        }

        return $total;
    }

    /**
     * @return array<string,array{operator:string,expected:int|null,category:string}>
     */
    private static function gates_for(int $documentCount, int $windowLimit): array
    {
        return [
            'indexed_documents' => ['operator' => '===', 'expected' => $documentCount, 'category' => 'structural'],
            'document_rows' => ['operator' => '===', 'expected' => $documentCount, 'category' => 'structural'],
            'document_metadata_rows' => ['operator' => '===', 'expected' => $documentCount, 'category' => 'structural'],
            'raw_token_occurrences' => ['operator' => '>=', 'expected' => $documentCount * 26, 'category' => 'structural'],
            'weighted_token_instances' => ['operator' => '>=', 'expected' => $documentCount * 32, 'category' => 'structural'],
            'unique_terms' => ['operator' => '>=', 'expected' => min(90, max(30, intdiv($documentCount, 3))), 'category' => 'structural'],
            'posting_rows' => ['operator' => '>=', 'expected' => $documentCount * 14, 'category' => 'structural'],
            'materialized_rows' => ['operator' => '<=', 'expected' => $documentCount * 180 + 2000, 'category' => 'structural'],
            'hydrated_result_rows' => ['operator' => '>=', 'expected' => $windowLimit * 3, 'category' => 'structural'],
            'hydrated_rows_with_metadata' => ['operator' => '>=', 'expected' => $windowLimit * 3, 'category' => 'structural'],
            'hydrated_rows_with_snippets' => ['operator' => '>=', 'expected' => $windowLimit * 3, 'category' => 'structural'],
            'query_checks_passed' => ['operator' => '===', 'expected' => 3, 'category' => 'structural'],
            'multi_token_checks_passed' => ['operator' => '>=', 'expected' => 2, 'category' => 'structural'],
            'folding_checks_passed' => ['operator' => '>=', 'expected' => 1, 'category' => 'structural'],
            'memory_delta_bytes' => ['operator' => '<=', 'expected' => max(64 * 1024 * 1024, $documentCount * 196608), 'category' => 'structural'],
            'index_duration_ms' => ['operator' => '<=', 'expected' => self::index_duration_budget_ms($documentCount), 'category' => 'performance'],
            'query_check_total_duration_ms' => ['operator' => '<=', 'expected' => self::query_check_total_budget_ms($documentCount), 'category' => 'performance'],
            'result_window_total_duration_ms' => ['operator' => '<=', 'expected' => self::result_window_total_budget_ms($documentCount, $windowLimit), 'category' => 'performance'],
            'search_read_total_duration_ms' => ['operator' => '<=', 'expected' => self::search_read_total_budget_ms($documentCount, $windowLimit), 'category' => 'performance'],
        ];
    }

    /**
     * @param array<string,mixed> $metrics
     * @param array<string,array{operator:string,expected:int|null,category:string}> $gates
     * @return array<int,array{metric:string,operator:string,expected:int|null,actual:int|null,passed:bool,category:string}>
     */
    private static function evaluate_gates(array $metrics, array $gates): array
    {
        $rows = [];
        foreach ($gates as $metric => $gate) {
            $actual = isset($metrics[$metric]) && is_numeric($metrics[$metric]) ? (int) $metrics[$metric] : null;
            $expected = $gate['expected'];
            $operator = $gate['operator'];
            $passed = $actual !== null && $expected !== null && match ($operator) {
                '===' => $actual === $expected,
                '>=' => $actual >= $expected,
                '<=' => $actual <= $expected,
                default => false,
            };
            $rows[] = [
                'metric' => $metric,
                'operator' => $operator,
                'expected' => $expected,
                'actual' => $actual,
                'passed' => $passed,
                'category' => $gate['category'],
            ];
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private static function sum_duration_ms(array $rows): int
    {
        $total = 0;
        foreach ($rows as $row) {
            $total += max(0, (int) ($row['duration_ms'] ?? 0));
        }

        return $total;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private static function max_duration_ms(array $rows): int
    {
        $max = 0;
        foreach ($rows as $row) {
            $max = max($max, max(0, (int) ($row['duration_ms'] ?? 0)));
        }

        return $max;
    }

    private static function elapsed_ms(float $started): int
    {
        return max(0, (int) round((microtime(true) - $started) * 1000));
    }

    private static function index_duration_budget_ms(int $documentCount): int
    {
        return max(15000, $documentCount * 100);
    }

    private static function query_check_total_budget_ms(int $documentCount): int
    {
        return max(3000, $documentCount * 20);
    }

    private static function result_window_total_budget_ms(int $documentCount, int $windowLimit): int
    {
        return max(5000, ($documentCount * 25) + ($windowLimit * 250));
    }

    private static function search_read_total_budget_ms(int $documentCount, int $windowLimit): int
    {
        return self::query_check_total_budget_ms($documentCount)
            + self::result_window_total_budget_ms($documentCount, $windowLimit);
    }

    /**
     * @param array<string,mixed> $metrics
     * @param array<int,array<string,mixed>> $gates
     * @return array<string,mixed>
     */
    private static function performance_budget_summary(array $metrics, array $gates): array
    {
        $passCount = 0;
        $failCount = 0;
        $failed = [];
        foreach ($gates as $gate) {
            if (($gate['category'] ?? '') !== 'performance') {
                continue;
            }
            if (!empty($gate['passed'])) {
                $passCount++;
                continue;
            }

            $failCount++;
            $failed[] = (string) ($gate['metric'] ?? '');
        }

        return [
            'metrics' => [
                'index_duration_ms' => $metrics['index_duration_ms'] ?? null,
                'query_check_total_duration_ms' => $metrics['query_check_total_duration_ms'] ?? null,
                'query_check_max_duration_ms' => $metrics['query_check_max_duration_ms'] ?? null,
                'result_window_total_duration_ms' => $metrics['result_window_total_duration_ms'] ?? null,
                'result_window_max_duration_ms' => $metrics['result_window_max_duration_ms'] ?? null,
                'search_read_total_duration_ms' => $metrics['search_read_total_duration_ms'] ?? null,
            ],
            'gate_counts' => [
                'pass' => $passCount,
                'fail' => $failCount,
            ],
            'failed_gates' => $failed,
        ];
    }

    private static function positive_int_arg(string $arg, string $prefix): int
    {
        $raw = substr($arg, strlen($prefix));
        if (preg_match('/^[1-9][0-9]*$/', $raw) !== 1) {
            throw new InvalidArgumentException("{$prefix} expects a positive integer.");
        }

        return (int) $raw;
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
     * @return string[]
     */
    private static function string_list(mixed $value): array
    {
        $list = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_scalar($item)) {
                $list[] = (string) $item;
            }
        }

        return $list;
    }

    private static function escape_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function format_nullable_int(mixed $value): string
    {
        return is_int($value) || (is_numeric($value) && $value !== null)
            ? (string) (int) $value
            : 'n/a';
    }

    private static function memory_usage(): ?int
    {
        return function_exists('memory_get_usage') ? memory_get_usage() : null;
    }

    private static function memory_peak_usage(): ?int
    {
        return function_exists('memory_get_peak_usage') ? memory_get_peak_usage() : null;
    }

    /**
     * @param array<string,mixed> $payload
     * @param resource $stream
     */
    private static function write_json(array $payload, mixed $stream): void
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new RuntimeException('Could not encode production-scale benchmark JSON.');
        }

        fwrite($stream, $encoded . "\n");
    }
}

$wp_fts_production_scale_script = $_SERVER['SCRIPT_FILENAME'] ?? '';
if (PHP_SAPI === 'cli' && is_string($wp_fts_production_scale_script) && realpath($wp_fts_production_scale_script) === __FILE__) {
    exit(WP_FTS_Production_Scale_Benchmark::cli($argv));
}
