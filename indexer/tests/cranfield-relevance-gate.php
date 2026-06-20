<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/**
 * Parser, importer, and local BM25 quality gate for operator-provided Cranfield
 * corpora. The full Cranfield data is intentionally not bundled here; callers
 * point this gate at local files they are licensed to use.
 */
final class WP_FTS_Cranfield_Relevance_Gate
{
    public const SCHEMA = 'wp-fts-cranfield-relevance-gate-v1';
    public const SUITE_SCHEMA = 'wp-fts-native-relevance-suite-v1';
    public const DEFAULT_TOP_K = 10;
    public const DEFAULT_MAX_DELTA = 0.05;

    private const K1 = 1.2;
    private const B = 0.75;

    public static function fixture_dir(): string
    {
        return __DIR__ . '/fixtures/cranfield-mini';
    }

    /**
     * Build the native relevance-suite JSON shape from a local Cranfield
     * directory. Supported filenames are the classic Cranfield names plus a few
     * obvious operator aliases for local experiments.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function build_suite_from_dir(string $directory, array $options = []): array
    {
        $paths = self::resolve_paths($directory);

        return self::build_suite($paths['documents'], $paths['queries'], $paths['qrels'], $options);
    }

    /**
     * @return array{documents:string,queries:string,qrels:string}
     */
    public static function resolve_paths(string $directory): array
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        if (!is_dir($directory)) {
            throw new RuntimeException("Cranfield directory does not exist: {$directory}");
        }

        return [
            'documents' => self::first_existing($directory, ['cran.all.1400', 'cran.all', 'documents.txt', 'docs.txt']),
            'queries' => self::first_existing($directory, ['cran.qry', 'queries.txt']),
            'qrels' => self::first_existing($directory, ['qrels.text', 'cranqrel', 'qrels.txt', 'cran.qrel']),
        ];
    }

    /**
     * @param string[] $names
     */
    private static function first_existing(string $directory, array $names): string
    {
        foreach ($names as $name) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) {
                return $path;
            }
        }

        throw new RuntimeException('Missing required Cranfield file in ' . $directory . ': expected one of ' . implode(', ', $names));
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function build_suite(string $documentsPath, string $queriesPath, string $qrelsPath, array $options = []): array
    {
        $documents = self::parse_documents($documentsPath);
        $queries = self::parse_queries($queriesPath);
        $qrels = self::parse_qrels($qrelsPath);
        $topK = max(1, (int) ($options['top_k'] ?? self::DEFAULT_TOP_K));
        $docIds = [];
        $queryIds = [];

        $suiteDocuments = [];
        foreach ($documents as $document) {
            $rawId = $document['id'];
            $fixtureId = self::document_fixture_id($rawId);
            $numericId = self::numeric_id($rawId, count($suiteDocuments) + 1);
            $docIds[$rawId] = $fixtureId;
            $suiteDocuments[] = [
                'id' => $fixtureId,
                'numeric_id' => $numericId,
                'language' => 'en',
                'family' => 'cranfield',
                'fields' => self::document_fields($document),
            ];
        }

        foreach ($queries as $query) {
            $queryIds[$query['id']] = true;
        }

        $suiteQueries = [];
        foreach ($queries as $query) {
            $judgments = [];
            foreach ($qrels[$query['id']] ?? [] as $docId => $grade) {
                if (!isset($docIds[$docId])) {
                    throw new RuntimeException("Qrels reference unknown Cranfield document {$docId} for query {$query['id']}.");
                }
                if ($grade > 0) {
                    $judgments[$docIds[$docId]] = $grade;
                }
            }
            arsort($judgments, SORT_NUMERIC);

            $suiteQueries[] = [
                'id' => self::query_fixture_id($query['id']),
                'family' => 'cranfield',
                'surface' => 'searcher',
                'query' => $query['text'],
                'language' => 'en',
                'mode' => 'OR',
                'limit' => $topK,
                'judgments' => $judgments,
            ];
        }

        foreach (array_keys($qrels) as $queryId) {
            if (!isset($queryIds[$queryId])) {
                throw new RuntimeException("Qrels reference unknown Cranfield query {$queryId}.");
            }
        }

        return [
            'schema' => self::SUITE_SCHEMA,
            'name' => (string) ($options['name'] ?? 'Operator-provided Cranfield relevance suite'),
            'source' => 'Built from local operator-provided Cranfield-shaped files; full corpus data is not bundled by this repository.',
            'top_k' => $topK,
            'analyzer' => [
                'default_lang' => 'en',
                'auto_detect_language' => false,
            ],
            'documents' => $suiteDocuments,
            'queries' => $suiteQueries,
        ];
    }

    /**
     * @return array<int,array{id:string,title:string,author:string,bibliography:string,content:string}>
     */
    private static function parse_documents(string $path): array
    {
        $records = self::parse_tagged_records($path);
        $documents = [];
        foreach ($records as $record) {
            $id = self::required_record_id($record, $path);
            $content = self::normalize_space((string) ($record['W'] ?? ''));
            if ($content === '') {
                throw new RuntimeException("Cranfield document {$id} has no .W content.");
            }
            $documents[] = [
                'id' => $id,
                'title' => self::normalize_space((string) ($record['T'] ?? '')),
                'author' => self::normalize_space((string) ($record['A'] ?? '')),
                'bibliography' => self::normalize_space((string) ($record['B'] ?? '')),
                'content' => $content,
            ];
        }

        return $documents;
    }

    /**
     * @return array<int,array{id:string,text:string}>
     */
    private static function parse_queries(string $path): array
    {
        $records = self::parse_tagged_records($path);
        $queries = [];
        foreach ($records as $record) {
            $id = self::required_record_id($record, $path);
            $text = self::normalize_space((string) ($record['W'] ?? $record['T'] ?? ''));
            if ($text === '') {
                throw new RuntimeException("Cranfield query {$id} has no .W or .T text.");
            }
            $queries[] = [
                'id' => $id,
                'text' => $text,
            ];
        }

        return $queries;
    }

    /**
     * Parse classic Cranfield tagged records. Section markers are a dot followed
     * by an uppercase letter; `.I` starts a new record and all other sections are
     * concatenated verbatim until the next marker.
     *
     * @return array<int,array<string,string>>
     */
    private static function parse_tagged_records(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("Cranfield file does not exist: {$path}");
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            throw new RuntimeException("Could not read Cranfield file: {$path}");
        }

        $records = [];
        $current = null;
        $section = null;
        foreach ($lines as $lineNumber => $line) {
            if (preg_match('/^\.([A-Z])(?:\s+(.*))?$/', rtrim($line), $match) === 1) {
                $marker = $match[1];
                $value = $match[2] ?? '';
                if ($marker === 'I') {
                    if ($current !== null) {
                        $records[] = self::finalize_record($current);
                    }
                    $current = ['I' => trim($value)];
                    $section = 'I';
                    continue;
                }
                if ($current === null) {
                    throw new RuntimeException(sprintf('Cranfield section .%s appears before .I in %s on line %d.', $marker, $path, $lineNumber + 1));
                }
                $section = $marker;
                $current[$section] = trim($value);
                continue;
            }

            if ($current === null || $section === null) {
                if (trim($line) === '') {
                    continue;
                }
                throw new RuntimeException(sprintf('Cranfield content appears before a section marker in %s on line %d.', $path, $lineNumber + 1));
            }
            if ($section === 'I') {
                if (trim($line) !== '') {
                    throw new RuntimeException(sprintf('Unexpected content inside .I section in %s on line %d.', $path, $lineNumber + 1));
                }
                continue;
            }
            $current[$section] = rtrim(($current[$section] ?? '') . "\n" . $line);
        }

        if ($current !== null) {
            $records[] = self::finalize_record($current);
        }
        if ($records === []) {
            throw new RuntimeException("No Cranfield records parsed from {$path}.");
        }

        return $records;
    }

    /**
     * @param array<string,string> $record
     * @return array<string,string>
     */
    private static function finalize_record(array $record): array
    {
        foreach ($record as $key => $value) {
            $record[$key] = trim($value);
        }

        return $record;
    }

    /**
     * @param array<string,string> $record
     */
    private static function required_record_id(array $record, string $path): string
    {
        $id = trim((string) ($record['I'] ?? ''));
        if ($id === '') {
            throw new RuntimeException("Cranfield record without .I id in {$path}.");
        }

        return $id;
    }

    /**
     * @return array<string,array<string,int>>
     */
    private static function parse_qrels(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("Cranfield qrels file does not exist: {$path}");
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            throw new RuntimeException("Could not read Cranfield qrels file: {$path}");
        }

        $qrels = [];
        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) >= 4) {
                [$queryId, $_iteration, $docId, $grade] = $parts;
            } elseif (count($parts) === 3) {
                [$queryId, $docId, $grade] = $parts;
            } else {
                throw new RuntimeException(sprintf('Invalid Cranfield qrels row in %s on line %d.', $path, $lineNumber + 1));
            }
            if (!is_numeric($grade)) {
                throw new RuntimeException(sprintf('Non-numeric Cranfield qrels grade in %s on line %d.', $path, $lineNumber + 1));
            }
            $qrels[(string) $queryId][(string) $docId] = max(0, (int) $grade);
        }
        if ($qrels === []) {
            throw new RuntimeException("No Cranfield qrels parsed from {$path}.");
        }

        return $qrels;
    }

    /**
     * @param array{id:string,title:string,author:string,bibliography:string,content:string} $document
     * @return array<int,array{name:string,text:string,boost:float}>
     */
    private static function document_fields(array $document): array
    {
        $fields = [];
        foreach ([
            ['name' => 'title', 'text' => $document['title'], 'boost' => 5.0],
            ['name' => 'author', 'text' => $document['author'], 'boost' => 1.0],
            ['name' => 'bibliography', 'text' => $document['bibliography'], 'boost' => 1.0],
            ['name' => 'content', 'text' => $document['content'], 'boost' => 1.0],
        ] as $field) {
            if (trim($field['text']) !== '') {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    private static function document_fixture_id(string $id): string
    {
        return 'cranfield-' . self::safe_id($id);
    }

    private static function query_fixture_id(string $id): string
    {
        return 'cranfield-q-' . self::safe_id($id);
    }

    private static function safe_id(string $id): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($id));

        return trim((string) $safe, '-') ?: 'id';
    }

    private static function numeric_id(string $id, int $fallback): int
    {
        return preg_match('/^[1-9][0-9]*$/', $id) === 1 ? (int) $id : $fallback;
    }

    private static function normalize_space(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /**
     * @param array<string,mixed> $suite
     */
    public static function write_suite(array $suite, string $path): void
    {
        $json = json_encode($suite, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Could not encode Cranfield relevance suite JSON.');
        }
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new RuntimeException("Output directory does not exist: {$directory}");
        }
        if (file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException("Could not write Cranfield relevance suite: {$path}");
        }
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function run(?string $directory = null, array $options = []): array
    {
        $suitePath = isset($options['suite']) && is_scalar($options['suite']) ? (string) $options['suite'] : '';
        $directory = $directory !== null && trim($directory) !== ''
            ? $directory
            : self::environment_directory();

        if ($suitePath === '' && trim((string) $directory) === '') {
            return self::pending_result('Set WP_FTS_CRANFIELD_DIR=/path/to/cranfield or pass --cranfield-dir=/path/to/cranfield. The repository does not bundle the full Cranfield corpus.');
        }

        try {
            $suite = $suitePath !== ''
                ? self::load_suite($suitePath)
                : self::build_suite_from_dir((string) $directory, $options);

            return self::run_suite($suite, array_merge($options, [
                'suite_path' => $suitePath !== '' ? $suitePath : null,
                'cranfield_dir' => $directory,
            ]));
        } catch (Throwable $e) {
            return [
                'schema' => self::SCHEMA,
                'status' => 'failed',
                'passed' => false,
                'pending' => false,
                'reason' => $e->getMessage(),
                'failures' => [$e->getMessage()],
            ];
        }
    }

    private static function environment_directory(): string
    {
        $value = getenv('WP_FTS_CRANFIELD_DIR');

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return array<string,mixed>
     */
    private static function load_suite(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("Cranfield suite does not exist: {$path}");
        }
        $suite = json_decode((string) file_get_contents($path), true);
        if (!is_array($suite)) {
            throw new RuntimeException("Cranfield suite is not valid JSON: {$path}");
        }
        if (($suite['schema'] ?? null) !== self::SUITE_SCHEMA) {
            throw new RuntimeException('Unsupported Cranfield suite schema.');
        }

        return $suite;
    }

    /**
     * @param array<string,mixed> $suite
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function run_suite(array $suite, array $options = []): array
    {
        self::validate_suite($suite);
        $topK = max(1, (int) ($suite['top_k'] ?? self::DEFAULT_TOP_K));
        $documents = self::array_list($suite['documents']);
        $queries = self::array_list($suite['queries']);
        $resultLimit = max($topK, (int) ($options['limit'] ?? count($documents)));
        $analyzer = new WP_FTS_Analyzer(self::array_value($suite['analyzer'] ?? []));
        $storage = new WP_FTS_Storage_InMemory();
        $indexer = new WP_FTS_Indexer($storage, $analyzer);
        $docMap = [];
        $referenceCorpus = [];

        foreach ($documents as $document) {
            $fixtureId = self::required_string($document, 'id', 'document');
            $numericId = (int) ($document['numeric_id'] ?? count($docMap) + 1);
            $language = self::document_language($document);
            $fields = self::suite_document_fields($document);
            $indexer->index_document_fields($numericId, $fields, [
                'lang' => $language,
                'metadata' => [
                    'post_id' => $numericId,
                    'post_type' => 'post',
                    'post_status' => 'publish',
                    'title' => self::document_title($document),
                    'search_text' => self::fields_text($fields),
                    'visibility' => 'public',
                ],
            ]);
            $docMap[$numericId] = [
                'id' => $fixtureId,
                'language' => $language,
            ];
            $referenceCorpus[$numericId] = self::reference_document_frequencies($analyzer, $fields, $language);
        }

        $searcher = new WP_FTS_Searcher($storage, $analyzer, self::K1, self::B);
        $queryReports = [];
        $nativeMetricRows = [];
        $referenceMetricRows = [];
        foreach ($queries as $query) {
            $queryId = self::required_string($query, 'id', 'query');
            $queryText = (string) ($query['query'] ?? '');
            $language = self::query_language($query);
            $judgments = self::judgments($query);
            if ($judgments === []) {
                continue;
            }

            $nativeRows = $searcher->search($queryText, [
                'lang' => $language,
                'query_lang' => $language,
                'mode' => 'OR',
                'limit' => $resultLimit,
                'include_total' => true,
                'exact_top_k' => true,
                'disable_language_fallback' => true,
                'prefix_matching' => false,
            ]);
            $nativeList = self::search_results($nativeRows);
            $referenceScores = self::reference_scores($analyzer, $referenceCorpus, $queryText, $language);
            $referenceList = self::score_rows($referenceScores, $resultLimit);
            $nativeIds = self::fixture_ids_from_rows($nativeList, $docMap);
            $referenceIds = self::fixture_ids_from_score_rows($referenceList, $docMap);
            $nativeMetrics = self::metrics_for_ranked_ids($nativeIds, $judgments, $topK);
            $referenceMetrics = self::metrics_for_ranked_ids($referenceIds, $judgments, $topK);
            $nativeMetricRows[] = $nativeMetrics;
            $referenceMetricRows[] = $referenceMetrics;

            $queryReports[] = [
                'id' => $queryId,
                'query' => $queryText,
                'judged_documents' => count($judgments),
                'native_top_ids' => array_slice($nativeIds, 0, $topK),
                'reference_top_ids' => array_slice($referenceIds, 0, $topK),
                'native_metrics' => $nativeMetrics,
                'reference_metrics' => $referenceMetrics,
                'deltas' => self::metric_deltas($nativeMetrics, $referenceMetrics),
            ];
        }

        $nativeMetrics = self::aggregate_metrics($nativeMetricRows);
        $referenceMetrics = self::aggregate_metrics($referenceMetricRows);
        $deltas = self::metric_deltas($nativeMetrics, $referenceMetrics);
        $maxDeltas = self::thresholds_from_options($options);
        $failures = [];
        if ($nativeMetricRows === []) {
            $failures[] = 'No judged Cranfield queries were available.';
        }
        foreach (['ndcg_at_10' => 'max_ndcg_delta', 'map' => 'max_map_delta', 'precision_at_5' => 'max_precision_at_5_delta'] as $metric => $thresholdKey) {
            if (($deltas[$metric] ?? 0.0) > $maxDeltas[$thresholdKey] + 1e-12) {
                $failures[] = sprintf('%s delta %.6f exceeds allowed %.6f', $metric, $deltas[$metric], $maxDeltas[$thresholdKey]);
            }
        }

        return [
            'schema' => self::SCHEMA,
            'status' => $failures === [] ? 'passed' : 'failed',
            'passed' => $failures === [],
            'pending' => false,
            'top_k' => $topK,
            'result_limit' => $resultLimit,
            'suite' => [
                'path' => $options['suite_path'] ?? null,
                'name' => (string) ($suite['name'] ?? ''),
                'source' => (string) ($suite['source'] ?? ''),
            ],
            'cranfield_dir' => $options['cranfield_dir'] ?? null,
            'documents' => ['count' => count($documents)],
            'queries' => [
                'count' => count($queries),
                'judged_count' => count($nativeMetricRows),
            ],
            'metrics' => [
                'native' => $nativeMetrics,
                'reference' => $referenceMetrics,
                'deltas' => $deltas,
                'thresholds' => $maxDeltas,
            ],
            'query_results' => $queryReports,
            'failures' => $failures,
        ];
    }

    /**
     * @param array<string,mixed> $suite
     */
    private static function validate_suite(array $suite): void
    {
        if (($suite['schema'] ?? null) !== self::SUITE_SCHEMA) {
            throw new RuntimeException('Unsupported relevance suite schema.');
        }
        if (!isset($suite['documents']) || !is_array($suite['documents']) || $suite['documents'] === []) {
            throw new RuntimeException('Cranfield suite must contain documents.');
        }
        if (!isset($suite['queries']) || !is_array($suite['queries']) || $suite['queries'] === []) {
            throw new RuntimeException('Cranfield suite must contain queries.');
        }
    }

    /**
     * @param array<string,mixed> $document
     */
    private static function document_language(array $document): string
    {
        return isset($document['language']) && is_scalar($document['language'])
            ? WP_FTS_TermNamespace::canonicalize_lang((string) $document['language'])
            : 'en';
    }

    /**
     * @param array<string,mixed> $query
     */
    private static function query_language(array $query): string
    {
        return isset($query['language']) && is_scalar($query['language'])
            ? WP_FTS_TermNamespace::canonicalize_lang((string) $query['language'])
            : 'en';
    }

    /**
     * @param array<string,mixed> $document
     * @return array<int,array<string,mixed>>
     */
    private static function suite_document_fields(array $document): array
    {
        if (isset($document['fields']) && is_array($document['fields'])) {
            $fields = [];
            foreach ($document['fields'] as $field) {
                if (is_array($field)) {
                    $fields[] = [
                        'name' => (string) ($field['name'] ?? 'content'),
                        'text' => (string) ($field['text'] ?? ''),
                        'boost' => (float) ($field['boost'] ?? 1.0),
                    ];
                }
            }
            if ($fields !== []) {
                return $fields;
            }
        }

        return [
            ['name' => 'title', 'text' => (string) ($document['title'] ?? ''), 'boost' => 5.0],
            ['name' => 'content', 'text' => (string) ($document['content'] ?? ''), 'boost' => 1.0],
        ];
    }

    /**
     * @param array<string,mixed> $document
     */
    private static function document_title(array $document): string
    {
        if (isset($document['title']) && is_scalar($document['title'])) {
            return (string) $document['title'];
        }
        foreach (self::suite_document_fields($document) as $field) {
            if (($field['name'] ?? '') === 'title') {
                return (string) ($field['text'] ?? '');
            }
        }

        return (string) ($document['id'] ?? '');
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     */
    private static function fields_text(array $fields): string
    {
        $parts = [];
        foreach ($fields as $field) {
            $text = trim((string) ($field['text'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @return array{frequencies:array<string,int>,lengths:array<string,int>}
     */
    private static function reference_document_frequencies(WP_FTS_Analyzer $analyzer, array $fields, string $defaultLang): array
    {
        $weights = [];
        foreach ($fields as $field) {
            $fieldName = (string) ($field['name'] ?? 'content');
            $fieldText = (string) ($field['text'] ?? '');
            if (trim($fieldText) === '') {
                continue;
            }
            $boost = max(0.0, (float) ($field['boost'] ?? 1.0));
            if ($boost <= 0.0) {
                continue;
            }
            $occurrences = $analyzer->analyze_plain_content($fieldText, [
                'lang' => $defaultLang,
                'language' => $defaultLang,
                'document_lang' => $defaultLang,
                'field_name' => $fieldName,
            ]);
            foreach ($occurrences as $occurrence) {
                $candidate = self::weighted_candidate($occurrence, $defaultLang, $boost);
                if ($candidate === null) {
                    continue;
                }
                $weights[$candidate['key']] = ($weights[$candidate['key']] ?? 0.0) + $candidate['weight'];
            }
        }

        $frequencies = [];
        $lengths = [];
        foreach ($weights as $key => $weight) {
            $tf = max(1, (int) round($weight));
            $frequencies[$key] = $tf;
            $split = WP_FTS_TermNamespace::split_term($key);
            $lang = $split['lang'] ?? WP_FTS_TermNamespace::canonicalize_lang($defaultLang);
            $lengths[$lang] = ($lengths[$lang] ?? 0) + $tf;
        }
        ksort($frequencies, SORT_STRING);
        ksort($lengths, SORT_STRING);

        return [
            'frequencies' => $frequencies,
            'lengths' => $lengths,
        ];
    }

    /**
     * @param array<string,mixed>|string $occurrence
     * @return array{key:string,weight:float}|null
     */
    private static function weighted_candidate(array|string $occurrence, string $defaultLang, float $fieldBoost): ?array
    {
        $term = is_array($occurrence)
            ? trim((string) ($occurrence['term'] ?? ''))
            : trim((string) $occurrence);
        if ($term === '') {
            return null;
        }
        $split = WP_FTS_TermNamespace::split_term($term);
        $lang = is_array($occurrence) && isset($occurrence['lang'])
            ? WP_FTS_TermNamespace::canonicalize_lang((string) $occurrence['lang'], $defaultLang)
            : WP_FTS_TermNamespace::canonicalize_lang($defaultLang);
        if ($split !== null) {
            $lang = $split['lang'];
            $term = $split['term'];
        }
        if (!WP_FTS_TermNamespace::term_key_fits($term, $lang)) {
            return null;
        }

        $weight = (is_array($occurrence) ? (float) ($occurrence['weight'] ?? 1.0) : 1.0) * $fieldBoost;
        if (
            is_array($occurrence)
            && ($occurrence['source'] ?? '') === 'lemma-pack'
            && isset($occurrence['rank'])
            && is_numeric($occurrence['rank'])
            && (int) $occurrence['rank'] === 0
        ) {
            $weight *= 2.0;
        }
        if ($weight <= 0.0) {
            return null;
        }

        return [
            'key' => WP_FTS_TermNamespace::namespace_term($lang, $term),
            'weight' => $weight,
        ];
    }

    /**
     * @param array<int,array{frequencies:array<string,int>,lengths:array<string,int>}> $corpus
     * @return array<int,float>
     */
    private static function reference_scores(WP_FTS_Analyzer $analyzer, array $corpus, string $query, string $language): array
    {
        $groups = self::query_groups($analyzer, $query, $language);
        if ($groups === []) {
            return [];
        }
        $terms = self::terms_by_key($groups);
        $scores = [];
        $bestRankByDoc = [];

        foreach ($terms as $key => $termInfo) {
            $lang = $termInfo['lang'];
            $docCount = 0;
            $docFreq = 0;
            $lengthSum = 0;
            foreach ($corpus as $doc) {
                if (($doc['lengths'][$lang] ?? 0) > 0) {
                    $docCount++;
                    $lengthSum += (int) $doc['lengths'][$lang];
                }
                if (($doc['frequencies'][$key] ?? 0) > 0) {
                    $docFreq++;
                }
            }
            if ($docFreq <= 0 || $docCount <= 0) {
                continue;
            }
            $avgDocLen = $lengthSum > 0 ? $lengthSum / $docCount : 1.0;
            $multiplier = 1.0 / (1.0 + max(0, min($termInfo['groups'])));
            $minRank = min($termInfo['groups']);
            foreach ($corpus as $docId => $doc) {
                $tf = (int) ($doc['frequencies'][$key] ?? 0);
                $docLen = (int) ($doc['lengths'][$lang] ?? 0);
                if ($tf <= 0 || $docLen <= 0) {
                    continue;
                }
                $scores[(int) $docId] = ($scores[(int) $docId] ?? 0.0) + self::bm25_score($tf, $docLen, $docCount, $docFreq, $avgDocLen) * $multiplier;
                $bestRankByDoc[(int) $docId] = min($bestRankByDoc[(int) $docId] ?? $minRank, $minRank);
            }
        }

        uksort($scores, static function (int $a, int $b) use ($scores, $bestRankByDoc): int {
            $rankOrder = ($bestRankByDoc[$a] ?? 0) <=> ($bestRankByDoc[$b] ?? 0);
            if ($rankOrder !== 0) {
                return $rankOrder;
            }
            $scoreOrder = $scores[$b] <=> $scores[$a];

            return $scoreOrder !== 0 ? $scoreOrder : ($a <=> $b);
        });

        return $scores;
    }

    /**
     * @return array<int,array<int,array{key:string,lang:string,term:string,rank:int}>>
     */
    private static function query_groups(WP_FTS_Analyzer $analyzer, string $query, string $language): array
    {
        $occurrences = $analyzer->analyze_query_occurrences($query, [
            'lang' => $language,
            'language' => $language,
            'query_lang' => $language,
            '_force_query_lang' => true,
        ]);
        $groups = [];
        $currentPosition = null;
        $currentGroupIndex = null;
        foreach ($occurrences as $occurrence) {
            $candidate = self::query_candidate($occurrence, $language);
            if ($candidate === null) {
                continue;
            }
            $position = is_array($occurrence) && isset($occurrence['position']) && is_scalar($occurrence['position'])
                ? (string) $occurrence['position']
                : null;
            if ($position !== null && $currentGroupIndex !== null && $currentPosition === $position) {
                $groups[$currentGroupIndex][] = $candidate;
                continue;
            }
            $groups[] = [$candidate];
            $currentPosition = $position;
            $currentGroupIndex = $position === null ? null : count($groups) - 1;
        }

        return self::dedupe_query_groups($groups);
    }

    /**
     * @param array<string,mixed>|string $occurrence
     * @return array{key:string,lang:string,term:string,rank:int}|null
     */
    private static function query_candidate(array|string $occurrence, string $language): ?array
    {
        $term = is_array($occurrence)
            ? trim((string) ($occurrence['term'] ?? ''))
            : trim((string) $occurrence);
        if ($term === '') {
            return null;
        }
        $split = WP_FTS_TermNamespace::split_term($term);
        if ($split !== null) {
            $term = $split['term'];
        }
        $lang = WP_FTS_TermNamespace::canonicalize_lang($language);
        if (!WP_FTS_TermNamespace::term_key_fits($term, $lang)) {
            return null;
        }

        return [
            'key' => WP_FTS_TermNamespace::namespace_term($lang, $term),
            'lang' => $lang,
            'term' => $term,
            'rank' => is_array($occurrence) && isset($occurrence['rank']) && is_numeric($occurrence['rank'])
                ? max(0, (int) $occurrence['rank'])
                : 0,
        ];
    }

    /**
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $groups
     * @return array<int,array<int,array{key:string,lang:string,term:string,rank:int}>>
     */
    private static function dedupe_query_groups(array $groups): array
    {
        $deduped = [];
        $seen = [];
        foreach ($groups as $group) {
            $byKey = [];
            foreach ($group as $candidate) {
                $key = $candidate['key'];
                if (!isset($byKey[$key]) || $candidate['rank'] < $byKey[$key]['rank']) {
                    $byKey[$key] = $candidate;
                }
            }
            if ($byKey === []) {
                continue;
            }
            ksort($byKey, SORT_STRING);
            $signature = implode("\0", array_keys($byKey));
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $deduped[] = array_values($byKey);
        }

        return $deduped;
    }

    /**
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $groups
     * @return array<string,array{lang:string,groups:array<int,int>}>
     */
    private static function terms_by_key(array $groups): array
    {
        $terms = [];
        foreach ($groups as $groupId => $group) {
            foreach ($group as $candidate) {
                $terms[$candidate['key']]['lang'] = $candidate['lang'];
                $terms[$candidate['key']]['groups'][$groupId] = min(
                    $terms[$candidate['key']]['groups'][$groupId] ?? $candidate['rank'],
                    $candidate['rank']
                );
            }
        }

        return $terms;
    }

    private static function bm25_score(int $tf, int $docLen, int $docCount, int $docFreq, float $avgDocLen): float
    {
        $idf = log(1.0 + (($docCount - $docFreq + 0.5) / ($docFreq + 0.5)));
        $normalizer = $tf + self::K1 * (1.0 - self::B + self::B * ($docLen / max(1.0, $avgDocLen)));

        return $idf * (($tf * (self::K1 + 1.0)) / $normalizer);
    }

    /**
     * @param array<int,array<string,mixed>>|array<string,mixed> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function search_results(array $rows): array
    {
        return isset($rows['results']) && is_array($rows['results'])
            ? self::array_list($rows['results'])
            : self::array_list($rows);
    }

    /**
     * @param array<int,float> $scores
     * @return array<int,array{doc_id:int,score:float}>
     */
    private static function score_rows(array $scores, int $limit): array
    {
        $rows = [];
        foreach (array_slice($scores, 0, $limit, true) as $docId => $score) {
            $rows[] = ['doc_id' => (int) $docId, 'score' => (float) $score];
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array{id:string,language:string}> $docMap
     * @return string[]
     */
    private static function fixture_ids_from_rows(array $rows, array $docMap): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $docId = (int) ($row['doc_id'] ?? 0);
            if (isset($docMap[$docId])) {
                $ids[] = $docMap[$docId]['id'];
            }
        }

        return $ids;
    }

    /**
     * @param array<int,array{doc_id:int,score:float}> $rows
     * @param array<int,array{id:string,language:string}> $docMap
     * @return string[]
     */
    private static function fixture_ids_from_score_rows(array $rows, array $docMap): array
    {
        return self::fixture_ids_from_rows($rows, $docMap);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,int>
     */
    private static function judgments(array $query): array
    {
        $judgments = [];
        foreach (self::array_value($query['judgments'] ?? []) as $id => $grade) {
            if (is_scalar($id) && is_numeric($grade) && (int) $grade > 0) {
                $judgments[(string) $id] = (int) $grade;
            }
        }
        ksort($judgments, SORT_STRING);

        return $judgments;
    }

    /**
     * @param string[] $rankedIds
     * @param array<string,int> $judgments
     * @return array<string,float|int>
     */
    public static function metrics_for_ranked_ids(array $rankedIds, array $judgments, int $topK = self::DEFAULT_TOP_K): array
    {
        $topK = max(1, $topK);
        $relevantTotal = count($judgments);
        $relevantHitsAt10 = 0;
        $relevantHitsAt5 = 0;
        $dcg = 0.0;
        $averagePrecisionSum = 0.0;
        $seenRelevant = 0;
        foreach ($rankedIds as $index => $id) {
            $rank = $index + 1;
            $grade = $judgments[$id] ?? 0;
            if ($grade <= 0) {
                continue;
            }
            $seenRelevant++;
            $averagePrecisionSum += $seenRelevant / $rank;
            if ($rank <= 5) {
                $relevantHitsAt5++;
            }
            if ($rank <= $topK) {
                $relevantHitsAt10++;
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
            'precision_at_5' => $relevantHitsAt5 / 5,
            'recall_at_10' => $relevantTotal > 0 ? $relevantHitsAt10 / $relevantTotal : 0.0,
            'ndcg_at_10' => $idealDcg > 0.0 ? $dcg / $idealDcg : 0.0,
            'map' => $relevantTotal > 0 ? $averagePrecisionSum / $relevantTotal : 0.0,
        ];
    }

    private static function log2(float $value): float
    {
        return log($value) / log(2.0);
    }

    /**
     * @param array<int,array<string,float|int>> $rows
     * @return array<string,float>
     */
    public static function aggregate_metrics(array $rows): array
    {
        $keys = ['precision_at_5', 'recall_at_10', 'ndcg_at_10', 'map'];
        $result = array_fill_keys($keys, 0.0);
        if ($rows === []) {
            return $result;
        }
        foreach ($rows as $row) {
            foreach ($keys as $key) {
                $result[$key] += (float) ($row[$key] ?? 0.0);
            }
        }
        foreach ($keys as $key) {
            $result[$key] /= count($rows);
        }

        return $result;
    }

    /**
     * @param array<string,float|int> $native
     * @param array<string,float|int> $reference
     * @return array<string,float>
     */
    private static function metric_deltas(array $native, array $reference): array
    {
        $keys = array_values(array_unique(array_merge(array_keys($native), array_keys($reference))));
        sort($keys, SORT_STRING);
        $deltas = [];
        foreach ($keys as $key) {
            if ($key === 'relevant_total') {
                continue;
            }
            $deltas[$key] = abs((float) ($native[$key] ?? 0.0) - (float) ($reference[$key] ?? 0.0));
        }

        return $deltas;
    }

    /**
     * @param array<string,mixed> $options
     * @return array{max_ndcg_delta:float,max_map_delta:float,max_precision_at_5_delta:float}
     */
    private static function thresholds_from_options(array $options): array
    {
        return [
            'max_ndcg_delta' => self::numeric_option($options, 'max_ndcg_delta', 'WP_FTS_CRANFIELD_MAX_NDCG_DELTA'),
            'max_map_delta' => self::numeric_option($options, 'max_map_delta', 'WP_FTS_CRANFIELD_MAX_MAP_DELTA'),
            'max_precision_at_5_delta' => self::numeric_option($options, 'max_precision_at_5_delta', 'WP_FTS_CRANFIELD_MAX_PRECISION_AT_5_DELTA'),
        ];
    }

    /**
     * @param array<string,mixed> $options
     */
    private static function numeric_option(array $options, string $option, string $env): float
    {
        if (isset($options[$option]) && is_numeric($options[$option])) {
            return max(0.0, (float) $options[$option]);
        }
        $value = getenv($env);
        if (is_string($value) && is_numeric($value)) {
            return max(0.0, (float) $value);
        }

        return self::DEFAULT_MAX_DELTA;
    }

    /**
     * @return array<string,mixed>
     */
    private static function pending_result(string $reason): array
    {
        return [
            'schema' => self::SCHEMA,
            'status' => 'pending',
            'passed' => false,
            'pending' => true,
            'reason' => $reason,
            'required' => [
                'environment' => 'WP_FTS_CRANFIELD_DIR',
                'command' => 'WP_FTS_CRANFIELD_DIR=/path/to/cranfield php tests/cranfield-relevance-gate.php',
            ],
            'failures' => [],
        ];
    }

    /**
     * @param mixed $value
     * @return array<int,array<string,mixed>>
     */
    private static function array_list(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
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
     * @param array<string,mixed> $row
     */
    private static function required_string(array $row, string $key, string $type): string
    {
        $value = $row[$key] ?? null;
        if (!is_scalar($value) || trim((string) $value) === '') {
            throw new RuntimeException("Cranfield {$type} is missing {$key}.");
        }

        return (string) $value;
    }

    /**
     * @param array<string,mixed> $result
     */
    public static function format_text(array $result): string
    {
        if (!empty($result['pending'])) {
            return "PENDING/NO-GO: Cranfield relevance gate\n" . (string) ($result['reason'] ?? '') . "\n";
        }
        $metrics = self::array_value($result['metrics'] ?? []);
        $native = self::array_value($metrics['native'] ?? []);
        $reference = self::array_value($metrics['reference'] ?? []);
        $deltas = self::array_value($metrics['deltas'] ?? []);
        $lines = [
            ((bool) ($result['passed'] ?? false) ? 'PASS' : 'FAIL') . ': Cranfield relevance gate',
            sprintf(
                'documents: %d; queries: %d; judged: %d; top_k: %d',
                (int) ($result['documents']['count'] ?? 0),
                (int) ($result['queries']['count'] ?? 0),
                (int) ($result['queries']['judged_count'] ?? 0),
                (int) ($result['top_k'] ?? self::DEFAULT_TOP_K)
            ),
            sprintf(
                'native: nDCG@10=%.6f MAP=%.6f P@5=%.6f',
                (float) ($native['ndcg_at_10'] ?? 0.0),
                (float) ($native['map'] ?? 0.0),
                (float) ($native['precision_at_5'] ?? 0.0)
            ),
            sprintf(
                'reference: nDCG@10=%.6f MAP=%.6f P@5=%.6f',
                (float) ($reference['ndcg_at_10'] ?? 0.0),
                (float) ($reference['map'] ?? 0.0),
                (float) ($reference['precision_at_5'] ?? 0.0)
            ),
            sprintf(
                'delta: nDCG@10=%.6f MAP=%.6f P@5=%.6f',
                (float) ($deltas['ndcg_at_10'] ?? 0.0),
                (float) ($deltas['map'] ?? 0.0),
                (float) ($deltas['precision_at_5'] ?? 0.0)
            ),
        ];
        foreach (self::array_value($result['failures'] ?? []) as $failure) {
            $lines[] = 'failure: ' . (string) $failure;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param string[] $argv
     */
    public static function cli(array $argv): int
    {
        $json = false;
        $dir = null;
        $options = [];
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--json') {
                $json = true;
                continue;
            }
            if ($arg === '--help' || $arg === '-h') {
                fwrite(STDOUT, "Usage: php tests/cranfield-relevance-gate.php [--cranfield-dir=PATH|--suite=PATH] [--json] [--max-ndcg-delta=N] [--max-map-delta=N] [--max-precision-at-5-delta=N]\n");
                return 0;
            }
            if (str_starts_with($arg, '--cranfield-dir=')) {
                $dir = substr($arg, strlen('--cranfield-dir='));
                continue;
            }
            if (str_starts_with($arg, '--suite=')) {
                $options['suite'] = substr($arg, strlen('--suite='));
                continue;
            }
            foreach ([
                '--max-ndcg-delta=' => 'max_ndcg_delta',
                '--max-map-delta=' => 'max_map_delta',
                '--max-precision-at-5-delta=' => 'max_precision_at_5_delta',
            ] as $prefix => $option) {
                if (str_starts_with($arg, $prefix)) {
                    $value = substr($arg, strlen($prefix));
                    if (!is_numeric($value)) {
                        fwrite(STDERR, "{$option} must be numeric.\n");
                        return 2;
                    }
                    $options[$option] = (float) $value;
                    continue 2;
                }
            }
            fwrite(STDERR, "Unknown argument: {$arg}\n");
            return 2;
        }

        $result = self::run($dir, $options);
        if ($json) {
            $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            fwrite(STDOUT, (is_string($encoded) ? $encoded : '{"status":"failed"}') . "\n");
        } else {
            fwrite((bool) ($result['passed'] ?? false) ? STDOUT : STDERR, self::format_text($result));
        }
        if (!empty($result['pending'])) {
            return 2;
        }

        return !empty($result['passed']) ? 0 : 1;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(WP_FTS_Cranfield_Relevance_Gate::cli($argv));
}
