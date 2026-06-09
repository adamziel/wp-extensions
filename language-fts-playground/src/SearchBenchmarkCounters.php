<?php
declare(strict_types=1);

/**
 * Storage decorator that records row-level materialization counters while
 * delegating all behavior to the normal storage implementation.
 */
final class Language_FTS_Playground_Search_Benchmark_Counting_Storage implements Language_FTS_Playground_Storage_Interface
{
    private Language_FTS_Playground_Storage_Interface $storage;

    /** @var array<string,mixed> */
    private array $counters = [];

    /** @var array<string,bool> */
    private array $candidate_lookup = [];

    public function __construct(Language_FTS_Playground_Storage_Interface|null $storage = null)
    {
        $this->storage = $storage ?? new Language_FTS_Playground_In_Memory_Storage();
        $this->reset_counters();
    }

    public function reset_counters(): void
    {
        $this->candidate_lookup = [];
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
                'document_count' => 0,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function counters(): array
    {
        $this->counters['candidate_count'] = count($this->candidate_lookup);

        return $this->counters;
    }

    public function install(): void
    {
        $this->storage->install();
    }

    public function clear(): void
    {
        $this->storage->clear();
        $this->reset_counters();
    }

    public function replace_document(
        int $post_id,
        string $language,
        string $title,
        string $status,
        int $document_length,
        array $field_term_frequencies,
        array $field_texts,
        array $term_positions
    ): void {
        $this->storage->replace_document(
            $post_id,
            $language,
            $title,
            $status,
            $document_length,
            $field_term_frequencies,
            $field_texts,
            $term_positions
        );
    }

    public function replace_document_partitions(int $post_id, array $partitions): void
    {
        $this->storage->replace_document_partitions($post_id, $partitions);
    }

    public function delete_document(int $post_id): void
    {
        $this->storage->delete_document($post_id);
    }

    public function fetch_postings(string $language, array $terms): array
    {
        $this->increment_fetch_call('fetch_postings');
        $unique_terms = array_values(array_unique(array_map('strval', $terms)));
        $this->counters['lookup_terms_requested'] = (int) $this->counters['lookup_terms_requested'] + count($unique_terms);

        $postings = $this->storage->fetch_postings($language, $terms);
        foreach ($postings as $term_postings) {
            foreach ($term_postings as $post_id => $_field_tfs) {
                $this->counters['postings_rows_materialized'] = (int) $this->counters['postings_rows_materialized'] + count((array) $_field_tfs);
                $this->candidate_lookup[$language . "\t" . (int) $post_id] = true;
            }
        }
        $this->counters['candidate_count'] = count($this->candidate_lookup);

        return $postings;
    }

    public function fetch_term_language_hits(array $language_terms): array
    {
        $this->increment_fetch_call('fetch_term_language_hits');
        $hits = $this->storage->fetch_term_language_hits($language_terms);
        foreach ($hits as $term_hits) {
            foreach ((array) $term_hits as $hit) {
                if ($hit === true) {
                    $this->counters['term_language_hit_rows_fetched'] = (int) $this->counters['term_language_hit_rows_fetched'] + 1;
                }
            }
        }

        return $hits;
    }

    public function fetch_positions(string $language, array $terms, array $post_ids): array
    {
        $this->increment_fetch_call('fetch_positions');
        $positions = $this->storage->fetch_positions($language, $terms, $post_ids);
        foreach ($positions as $term_positions) {
            $this->counters['position_rows_fetched'] = (int) $this->counters['position_rows_fetched'] + count((array) $term_positions);
        }

        return $positions;
    }

    public function fetch_candidate_terms(string $language, string $term, int $max_distance, int $limit): array
    {
        $this->increment_fetch_call('fetch_candidate_terms');
        $terms = $this->storage->fetch_candidate_terms($language, $term, $max_distance, $limit);
        $this->counters['fuzzy_candidate_terms_returned'] = (int) $this->counters['fuzzy_candidate_terms_returned'] + count($terms);

        return $terms;
    }

    public function fetch_document_lengths(string $language, array $post_ids): array
    {
        $this->increment_fetch_call('fetch_document_lengths');
        $lengths = $this->storage->fetch_document_lengths($language, $post_ids);
        $this->counters['document_length_rows_fetched'] = (int) $this->counters['document_length_rows_fetched'] + count($lengths);

        return $lengths;
    }

    public function fetch_document_fields(string $language, array $post_ids): array
    {
        $this->increment_fetch_call('fetch_document_fields');
        $fields = $this->storage->fetch_document_fields($language, $post_ids);
        $this->counters['field_text_rows_fetched'] = (int) $this->counters['field_text_rows_fetched'] + count($fields);

        return $fields;
    }

    public function fetch_document_field_metadata(string $language, array $post_ids): array
    {
        $this->increment_fetch_call('fetch_document_field_metadata');
        $metadata = $this->storage->fetch_document_field_metadata($language, $post_ids);
        $this->counters['field_metadata_rows_fetched'] = (int) $this->counters['field_metadata_rows_fetched'] + count($metadata);

        return $metadata;
    }

    public function document_count(string $language): int
    {
        $this->increment_fetch_call('document_count');

        return $this->storage->document_count($language);
    }

    public function all_documents(): array
    {
        return $this->storage->all_documents();
    }

    private function increment_fetch_call(string $name): void
    {
        $calls = (array) $this->counters['fetch_calls'];
        $calls[$name] = (int) ($calls[$name] ?? 0) + 1;
        $this->counters['fetch_calls'] = $calls;
    }
}

/**
 * Builds deterministic synthetic indexes and runs public search probes against
 * the normal searcher while returning materialization counters and gate rows.
 */
final class Language_FTS_Playground_Search_Benchmark_Fixture
{
    private const LANGUAGE = 'bm';
    private const SEED = 311;
    private const FUZZY_CANDIDATE_LIMIT = 128;
    private const AUTO_LANGUAGE_MAX_PARTITIONS = 5;

    /** @var array<string,string> */
    private const SCENARIO_QUERIES = [
        'common-term' => 'commonterm',
        'phrase-heavy' => '"alpha beta"',
        'fuzzy-heavy' => 'orchard~',
        'mixed-field' => 'fieldprobe',
        'single-token-synonym' => 'lookup',
        'phrase-synonym-heavy' => 'portal lookup',
    ];

    /** @var array<string,string> */
    private const SCENARIO_ALIASES = [
        'common' => 'common-term',
        'common_term' => 'common-term',
        'term' => 'common-term',
        'phrase' => 'phrase-heavy',
        'fuzzy' => 'fuzzy-heavy',
        'synonym' => 'single-token-synonym',
        'single_token_synonym' => 'single-token-synonym',
        'single-token' => 'single-token-synonym',
        'phrase_synonym' => 'phrase-synonym-heavy',
        'phrasesynonym' => 'phrase-synonym-heavy',
        'phrase-synonym' => 'phrase-synonym-heavy',
        'mixed_field' => 'mixed-field',
        'mixed-fields' => 'mixed-field',
    ];

    /** @var string[] */
    private const LEGACY_SCENARIOS = [
        'common-term',
        'phrase',
        'fuzzy',
        'synonym',
        'phrase-synonym',
        'mixed-field',
    ];

    /** @var array<string,string[]> */
    private const SUITE_SCENARIOS = [
        'pr-smoke' => [
            'common-term',
            'phrase-heavy',
            'fuzzy-heavy',
            'mixed-field',
        ],
        'scheduled' => [
            'common-term',
            'phrase-heavy',
            'fuzzy-heavy',
            'mixed-field',
            'single-token-synonym',
            'phrase-synonym-heavy',
        ],
        'manual-stress' => [
            'common-term',
            'phrase-heavy',
            'fuzzy-heavy',
            'mixed-field',
            'single-token-synonym',
            'phrase-synonym-heavy',
        ],
        'all' => [
            'common-term',
            'phrase-heavy',
            'fuzzy-heavy',
            'mixed-field',
            'single-token-synonym',
            'phrase-synonym-heavy',
        ],
    ];

    public static function resource_root(): string
    {
        return dirname(__DIR__) . '/tests/fixtures/search-benchmark-languages';
    }

    /**
     * @return string[]
     */
    public static function scenarios(): array
    {
        return self::LEGACY_SCENARIOS;
    }

    /**
     * @return string[]
     */
    public static function suites(): array
    {
        return array_keys(self::SUITE_SCENARIOS);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function run_probe(string $scenario, array $options = []): array
    {
        $normalized = self::normalize_scenario($scenario);
        $document_count = max(1, (int) ($options['documents'] ?? 48));
        $language_count = max(1, (int) ($options['languages'] ?? 1));
        $limit = max(1, (int) ($options['limit'] ?? 5));
        $resource_context = self::resource_context($language_count, $options);

        try {
            return self::run_probe_with_resource_root(
                $normalized['canonical'],
                $normalized['report'],
                $document_count,
                $language_count,
                $limit,
                $resource_context['root'],
                $options
            );
        } finally {
            if ($resource_context['temporary']) {
                self::remove_temp_tree($resource_context['root']);
            }
        }
    }

    /**
     * @param array<string,mixed> $options
     * @return array<int,array<string,mixed>>
     */
    public static function run_all(array $options = []): array
    {
        $reports = [];
        foreach (self::scenarios() as $scenario) {
            $reports[] = self::run_probe($scenario, $options);
        }

        return $reports;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function run_suite(string $suite, array $options = []): array
    {
        $suite = self::normalize_suite($suite);
        $defaults = self::suite_defaults($suite);
        $document_count = max(1, (int) ($options['documents'] ?? $defaults['documents']));
        $language_count = max(1, (int) ($options['languages'] ?? $defaults['languages']));
        $limit = max(1, (int) ($options['limit'] ?? $defaults['limit']));
        $resource_context = self::resource_context($language_count, $options);

        try {
            $reports = [];
            foreach (self::SUITE_SCENARIOS[$suite] as $scenario) {
                $reports[] = self::run_probe_with_resource_root(
                    $scenario,
                    $scenario,
                    $document_count,
                    $language_count,
                    $limit,
                    $resource_context['root'],
                    $options
                );
            }
        } finally {
            if ($resource_context['temporary']) {
                self::remove_temp_tree($resource_context['root']);
            }
        }

        return [
            'schema_version' => 'language-fts-search-benchmark-gates-v1',
            'suite' => $suite,
            'config' => [
                'documents' => $document_count,
                'languages' => $language_count,
                'limit' => $limit,
                'seed' => self::SEED,
            ],
            'summary' => self::gate_summary($reports),
            'scenarios' => $reports,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $reports
     * @return array{status:string,scenario_count:int,hard_gate_failures:int,advisory_gate_failures:int}
     */
    public static function gate_summary(array $reports): array
    {
        $hard_failures = 0;
        $advisory_failures = 0;
        foreach ($reports as $report) {
            foreach ((array) ($report['gates'] ?? []) as $gate) {
                if (!is_array($gate) || ($gate['status'] ?? '') !== 'fail') {
                    continue;
                }

                if (($gate['severity'] ?? 'hard') === 'hard') {
                    $hard_failures++;
                } else {
                    $advisory_failures++;
                }
            }
        }

        return [
            'status' => $hard_failures === 0 ? 'pass' : 'fail',
            'scenario_count' => count($reports),
            'hard_gate_failures' => $hard_failures,
            'advisory_gate_failures' => $advisory_failures,
        ];
    }

    /**
     * @param array<string,mixed>|array<int,array<string,mixed>> $report
     */
    public static function has_hard_gate_failures(array $report): bool
    {
        if (isset($report['summary']) && is_array($report['summary'])) {
            return (int) ($report['summary']['hard_gate_failures'] ?? 0) > 0;
        }

        $reports = array_is_list($report) ? $report : [$report];
        foreach ($reports as $entry) {
            if (is_array($entry) && self::scenario_has_hard_gate_failures($entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $report
     */
    private static function scenario_has_hard_gate_failures(array $report): bool
    {
        foreach ((array) ($report['gates'] ?? []) as $gate) {
            if (is_array($gate) && ($gate['severity'] ?? 'hard') === 'hard' && ($gate['status'] ?? '') === 'fail') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function run_probe_with_resource_root(
        string $canonical_scenario,
        string $report_scenario,
        int $document_count,
        int $language_count,
        int $limit,
        string $resource_root,
        array $options
    ): array {
        $storage = new Language_FTS_Playground_Search_Benchmark_Counting_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($resource_root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        $languages = self::language_ids($language_count);
        self::index_documents($indexer, $document_count, $languages);

        $query = self::SCENARIO_QUERIES[$canonical_scenario];
        $explain = $searcher->explain($query, self::LANGUAGE, $limit);
        $lookup_terms_by_class = self::lookup_terms_by_class($explain);
        $lookup_terms_by_partition = self::lookup_terms_by_partition($explain);
        $selected_partitions = self::selected_partitions($explain);

        $storage->reset_counters();
        $memory_before = memory_get_usage(true);
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $wall_start = microtime(true);
        $results = $searcher->search($query, self::LANGUAGE, $limit);
        $wall_time_ms = round((microtime(true) - $wall_start) * 1000, 3);
        $counters = $storage->counters();
        $peak_memory_delta_bytes = max(0, memory_get_peak_usage(true) - $memory_before);
        $memory_delta_bytes = max(0, memory_get_usage(true) - $memory_before);
        $counters['peak_memory_delta_bytes'] = $peak_memory_delta_bytes;

        $result_ids = array_values(array_map('intval', array_column($results, 'post_id')));

        $report = [
            'scenario' => $report_scenario,
            'query' => $query,
            'language' => self::LANGUAGE,
            'document_count' => $document_count,
            'language_count' => $language_count,
            'limit' => $limit,
            'result_count' => count($results),
            'result_ids' => $result_ids,
            'result_post_ids' => $result_ids,
            'selected_partitions' => $selected_partitions,
            'top_result_signature' => self::top_result_signature($results),
            'lookup_terms_by_class' => $lookup_terms_by_class,
            'lookup_terms_by_partition' => $lookup_terms_by_partition,
            'counters' => $counters,
            'runtime_cap_failures' => 0,
            'wall_time_ms' => $wall_time_ms,
            'memory_delta_bytes' => $memory_delta_bytes,
            'peak_memory_delta_bytes' => $peak_memory_delta_bytes,
        ];

        $report['metrics'] = self::derived_metrics($report);
        $report['gates'] = self::evaluate_gates($canonical_scenario, $report, $options);

        return $report;
    }

    /**
     * @return array{canonical:string,report:string}
     */
    private static function normalize_scenario(string $scenario): array
    {
        $report = strtolower(trim($scenario));
        $canonical = self::SCENARIO_ALIASES[$report] ?? $report;
        if (!isset(self::SCENARIO_QUERIES[$canonical])) {
            throw new InvalidArgumentException('Unknown benchmark scenario: ' . $scenario);
        }

        return [
            'canonical' => $canonical,
            'report' => $report === '' ? $canonical : $report,
        ];
    }

    private static function normalize_suite(string $suite): string
    {
        $suite = strtolower(trim($suite));
        if (!isset(self::SUITE_SCENARIOS[$suite])) {
            throw new InvalidArgumentException('Unknown benchmark suite: ' . $suite);
        }

        return $suite;
    }

    /**
     * @return array{documents:int,languages:int,limit:int}
     */
    private static function suite_defaults(string $suite): array
    {
        if ($suite === 'pr-smoke') {
            return [
                'documents' => 64,
                'languages' => 1,
                'limit' => 5,
            ];
        }

        if ($suite === 'scheduled') {
            return [
                'documents' => 5000,
                'languages' => 8,
                'limit' => 10,
            ];
        }

        if ($suite === 'manual-stress') {
            return [
                'documents' => 25000,
                'languages' => 16,
                'limit' => 10,
            ];
        }

        return [
            'documents' => 64,
            'languages' => 1,
            'limit' => 5,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{root:string,temporary:bool}
     */
    private static function resource_context(int $language_count, array $options): array
    {
        if (isset($options['resource_root'])) {
            return [
                'root' => (string) $options['resource_root'],
                'temporary' => false,
            ];
        }

        if ($language_count <= 1) {
            return [
                'root' => self::resource_root(),
                'temporary' => false,
            ];
        }

        return [
            'root' => self::create_generated_resource_root($language_count),
            'temporary' => true,
        ];
    }

    /**
     * @return string[]
     */
    private static function language_ids(int $language_count): array
    {
        $ids = [self::LANGUAGE];
        for ($i = 1; $i < $language_count; $i++) {
            $ids[] = 'bm' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        }

        return $ids;
    }

    private static function create_generated_resource_root(int $language_count): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'language-fts-benchmark-' . getmypid() . '-' . str_replace('.', '', uniqid('', true));
        if (!mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('Could not create benchmark language resource root.');
        }

        foreach (self::language_ids($language_count) as $index => $language) {
            self::write_generated_language_profile($root, $language, $index);
        }

        return $root;
    }

    private static function write_generated_language_profile(string $root, string $language, int $index): void
    {
        $directory = $root . DIRECTORY_SEPARATOR . $language;
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create benchmark language directory.');
        }

        $profile = [
            'id' => $language,
            'label' => 'Benchmark Fixture ' . $language,
            'order' => 10 + $index,
            'resources' => [
                'stopwords' => 'stopwords.txt',
                'lexemes' => 'lexemes.tsv',
                'synonyms' => 'synonyms.tsv',
                'synonym_phrases' => 'synonym_phrases.tsv',
            ],
        ];

        self::write_file($directory . DIRECTORY_SEPARATOR . 'profile.php', "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($profile, true) . ";\n");
        self::write_file($directory . DIRECTORY_SEPARATOR . 'stopwords.txt', "# Benchmark stopwords.\n");
        self::write_file($directory . DIRECTORY_SEPARATOR . 'lexemes.tsv', "# observed\tcanonical\tprovenance\n");
        self::write_file($directory . DIRECTORY_SEPARATOR . 'synonyms.tsv', "# source\ttarget\tdirection\tweight\tprovenance\nlookup\tsearchterm\tquery_to_index\t0.7\tfixture-search-benchmark\n");
        self::write_file($directory . DIRECTORY_SEPARATOR . 'synonym_phrases.tsv', "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\nportal lookup\tsearch site\tquery_to_index\t0.75\tfixture-search-benchmark\n");
    }

    private static function write_file(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Could not write benchmark fixture file.');
        }
    }

    private static function remove_temp_tree(string $root): void
    {
        if ($root === '' || !is_dir($root)) {
            return;
        }

        $entries = scandir($root);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $root . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                self::remove_temp_tree($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($root);
    }

    /**
     * @param string[] $languages
     */
    private static function index_documents(Language_FTS_Playground_Indexer $indexer, int $document_count, array $languages): void
    {
        $common_matches = min($document_count, max(1, (int) floor($document_count * 0.75)));
        foreach (array_values($languages) as $language_index => $language) {
            $offset = $language_index * 100000;
            for ($i = 1; $i <= $document_count; $i++) {
                $indexer->index_post(self::post($offset + $i, $i, $common_matches, (string) $language));
            }
        }
    }

    private static function post(int $post_id, int $local_id, int $common_matches, string $language): object
    {
        $title = 'Benchmark document docid' . $post_id;
        if ($local_id === 1) {
            $title .= ' commonterm title boost';
        }
        if ($local_id === 2) {
            $title .= ' orchard exact fuzzy anchor';
        }
        if ($local_id === 3) {
            $title .= ' fieldprobe title signal';
        }

        $excerpt = $local_id % 7 === 0 ? 'commonterm excerpt marker' : '';
        if ($local_id === 6) {
            $excerpt = trim($excerpt . ' fieldprobe excerpt signal');
        }

        $content_html = '<p>' . implode('. ', self::content_segments($local_id, $common_matches)) . '.</p>';
        if ($local_id === 12) {
            $content_html .= '<figure><img src="fixture.jpg" alt="fieldprobe alt signal" /></figure>';
        }

        return (object) [
            'ID' => $post_id,
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_password' => '',
            'post_title' => $title,
            'post_excerpt' => $excerpt,
            'post_content' => $content_html,
            'language' => $language,
        ];
    }

    /**
     * @return string[]
     */
    private static function content_segments(int $local_id, int $common_matches): array
    {
        $segments = [
            'baseline fixture token localdoc' . $local_id,
        ];

        if ($local_id <= $common_matches) {
            $segments[] = 'commonterm shared payload';
        }

        if ($local_id % 5 === 0) {
            $segments[] = 'alpha beta adjacent phrase payload';
        } elseif ($local_id % 5 === 1) {
            $segments[] = 'alpha bridge beta separated payload';
        }

        $fuzzy_variants = self::fuzzy_variants();
        if ($local_id >= 4 && $local_id < (4 + count($fuzzy_variants))) {
            $segments[] = $fuzzy_variants[$local_id - 4] . ' fuzzy candidate payload';
        } elseif ($local_id % 4 === 0) {
            $segments[] = 'orchart repeated typo payload';
        }

        if ($local_id % 6 === 0) {
            $segments[] = 'searchterm synonym target payload';
        }

        if ($local_id % 8 === 0) {
            $segments[] = 'search site adjacent phrase synonym target';
        } elseif ($local_id % 8 === 1) {
            $segments[] = 'search bridge site separated phrase synonym target';
        }

        if ($local_id === 9) {
            $segments[] = 'fieldprobe content signal';
        }

        return $segments;
    }

    /**
     * @return string[]
     */
    private static function fuzzy_variants(): array
    {
        return [
            'orcharb',
            'orcharc',
            'orchardx',
            'orchare',
            'orcharf',
            'orcharg',
            'orchari',
            'orcharj',
            'orchart',
        ];
    }

    /**
     * @param array<string,mixed> $explain
     * @return array<string,array{count:int,terms:string[]}>
     */
    private static function lookup_terms_by_class(array $explain): array
    {
        $classes = [
            'exact',
            'single_token_synonyms',
            'phrase_synonyms',
            'fuzzy',
            'all',
        ];
        $terms_by_class = [];
        foreach ($classes as $class) {
            $terms_by_class[$class] = [];
        }

        foreach ((array) ($explain['partitions'] ?? []) as $partition) {
            if (!is_array($partition)) {
                continue;
            }
            $lookup_terms = (array) ($partition['lookup_terms'] ?? []);
            foreach ($classes as $class) {
                foreach ((array) ($lookup_terms[$class] ?? []) as $term) {
                    $term = (string) $term;
                    if ($term !== '') {
                        $terms_by_class[$class][$term] = true;
                    }
                }
            }
        }

        $summary = [];
        foreach ($terms_by_class as $class => $terms) {
            $terms = array_keys($terms);
            sort($terms, SORT_STRING);
            $summary[$class] = [
                'count' => count($terms),
                'terms' => $terms,
            ];
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $explain
     * @return array<string,array<string,array{count:int,terms:string[]}>>
     */
    private static function lookup_terms_by_partition(array $explain): array
    {
        $partitions = [];
        foreach ((array) ($explain['partitions'] ?? []) as $partition) {
            if (!is_array($partition)) {
                continue;
            }

            $language = (string) ($partition['language'] ?? '');
            if ($language === '') {
                continue;
            }

            $partitions[$language] = self::lookup_terms_by_class([
                'partitions' => [$partition],
            ]);
        }

        ksort($partitions, SORT_STRING);

        return $partitions;
    }

    /**
     * @param array<string,mixed> $explain
     * @return string[]
     */
    private static function selected_partitions(array $explain): array
    {
        $routing = (array) ($explain['language_routing'] ?? []);
        $partitions = array_values(array_map('strval', (array) ($routing['selected_partitions'] ?? [])));
        sort($partitions, SORT_STRING);

        return $partitions;
    }

    /**
     * @param array<int,array<string,mixed>> $results
     */
    private static function top_result_signature(array $results): string
    {
        $top = $results[0] ?? null;
        if (!is_array($top)) {
            return '';
        }

        $terms = array_values(array_map('strval', (array) ($top['matched_terms'] ?? [])));
        sort($terms, SORT_STRING);

        return (string) ($top['matched_language'] ?? self::LANGUAGE)
            . ':'
            . (string) ((int) ($top['post_id'] ?? 0))
            . ':'
            . implode('+', $terms);
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private static function derived_metrics(array $report): array
    {
        $counters = (array) ($report['counters'] ?? []);
        $candidate_count = (int) ($counters['candidate_count'] ?? 0);
        $result_count = (int) ($report['result_count'] ?? 0);
        $postings_rows = (int) ($counters['postings_rows_materialized'] ?? 0);
        $field_text_rows = (int) ($counters['field_text_rows_fetched'] ?? 0);
        $position_rows = (int) ($counters['position_rows_fetched'] ?? 0);
        $lookup_terms_by_partition_max = 0;
        foreach ((array) ($report['lookup_terms_by_partition'] ?? []) as $partition) {
            if (is_array($partition)) {
                $lookup_terms_by_partition_max = max($lookup_terms_by_partition_max, (int) ($partition['all']['count'] ?? 0));
            }
        }

        return [
            'candidate_to_result_ratio' => $result_count > 0 ? round($candidate_count / $result_count, 6) : 0.0,
            'postings_rows_per_candidate' => $candidate_count > 0 ? round($postings_rows / $candidate_count, 6) : 0.0,
            'field_text_rows_per_result' => $result_count > 0 ? round($field_text_rows / $result_count, 6) : 0.0,
            'position_rows_per_candidate' => $candidate_count > 0 ? round($position_rows / $candidate_count, 6) : 0.0,
            'lookup_terms_total' => (int) ($report['lookup_terms_by_class']['all']['count'] ?? 0),
            'lookup_terms_by_partition_max' => $lookup_terms_by_partition_max,
            'selected_partition_count' => count((array) ($report['selected_partitions'] ?? [])),
            'runtime_cap_failure_count' => (int) ($report['runtime_cap_failures'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $options
     * @return array<int,array{id:string,status:string,metric:string,observed:mixed,operator:string,limit:mixed,severity:string}>
     */
    private static function evaluate_gates(string $canonical_scenario, array $report, array $options): array
    {
        $counters = (array) ($report['counters'] ?? []);
        $fetch_calls = (array) ($counters['fetch_calls'] ?? []);
        $metrics = (array) ($report['metrics'] ?? []);
        $result_ids = array_values(array_map('intval', (array) ($report['result_ids'] ?? [])));
        $document_count = (int) ($report['document_count'] ?? 0);
        $limit = (int) ($report['limit'] ?? 0);
        $selected_partition_count = max(1, (int) ($metrics['selected_partition_count'] ?? 1));
        $gates = [];

        $gates[] = self::gate('auto-selected-partitions-capped', 'selected_partition_count', $selected_partition_count, '<=', self::AUTO_LANGUAGE_MAX_PARTITIONS);
        $gates[] = self::gate('document-length-current-shape', 'document_length_rows_fetched', (int) ($counters['document_length_rows_fetched'] ?? 0), '=', (int) ($counters['candidate_count'] ?? 0));
        $gates[] = self::gate('fetch-postings-called', 'fetch_calls.fetch_postings', (int) ($fetch_calls['fetch_postings'] ?? 0), '>=', 1);
        $gates[] = self::gate('json-schema-valid', 'required_fields_present', self::required_report_fields_present($report), '=', 1);
        $gates[] = self::gate('lookup-terms-under-runtime-cap', 'lookup_terms_by_partition_max', (int) ($metrics['lookup_terms_by_partition_max'] ?? 0), '<=', self::FUZZY_CANDIDATE_LIMIT);
        $gates[] = self::gate('postings-materialization-counted', 'postings_rows_materialized', (int) ($counters['postings_rows_materialized'] ?? 0), '>=', (int) ($counters['candidate_count'] ?? 0));
        $gates[] = self::gate('public-field-metadata-zero', 'field_metadata_rows_fetched', (int) ($counters['field_metadata_rows_fetched'] ?? 0), '=', 0);
        $gates[] = self::gate('public-field-text-final-window', 'field_text_rows_fetched', (int) ($counters['field_text_rows_fetched'] ?? 0), '<=', (int) ($report['result_count'] ?? 0));

        if ($canonical_scenario === 'common-term') {
            $gates[] = self::gate('common-term-candidate-shape', 'candidate_count_minus_result_count', (int) ($counters['candidate_count'] ?? 0) - (int) ($report['result_count'] ?? 0), '>', 0);
            $gates[] = self::gate('common-term-result-order-deterministic', 'first_result_id', $result_ids[0] ?? 0, '=', self::expected_common_term_top_id($document_count));
        } elseif ($canonical_scenario === 'phrase-heavy') {
            $position_term_count = max(1, (int) ($report['lookup_terms_by_class']['exact']['count'] ?? 0));
            $gates[] = self::gate('phrase-position-rows-bounded', 'position_rows_fetched', (int) ($counters['position_rows_fetched'] ?? 0), '<=', (int) ($counters['candidate_count'] ?? 0) * $position_term_count);
            $gates[] = self::gate('phrase-position-rows-present', 'position_rows_fetched', (int) ($counters['position_rows_fetched'] ?? 0), '>', 0);
            $gates[] = self::gate('phrase-non-adjacent-results-excluded', 'non_adjacent_phrase_result_count', self::non_adjacent_phrase_result_count($result_ids), '=', 0);
        } elseif ($canonical_scenario === 'fuzzy-heavy') {
            $gates[] = self::gate('fuzzy-candidates-under-cap', 'fuzzy_candidate_terms_returned', (int) ($counters['fuzzy_candidate_terms_returned'] ?? 0), '<=', self::FUZZY_CANDIDATE_LIMIT * $selected_partition_count);
            $gates[] = self::gate('fuzzy-candidate-heavy-shape', 'fuzzy_candidate_terms_returned', (int) ($counters['fuzzy_candidate_terms_returned'] ?? 0), '>=', min(3, max(1, $document_count - 3)));
            $gates[] = self::gate('fuzzy-exact-match-outranks-candidates', 'first_result_id', $result_ids[0] ?? 0, '=', 2);
        } elseif ($canonical_scenario === 'mixed-field') {
            $gates[] = self::gate('mixed-field-alt-result-present', 'contains_alt_result_id_12', in_array(12, $result_ids, true) ? 1 : 0, '=', ($document_count >= 12 && $limit >= 4) ? 1 : 0);
            $gates[] = self::gate('mixed-field-title-first', 'first_result_id', $result_ids[0] ?? 0, '=', $document_count >= 3 ? 3 : ($result_ids[0] ?? 0));
        } elseif ($canonical_scenario === 'single-token-synonym') {
            $gates[] = self::gate('single-token-synonym-expanded', 'single_token_synonym_terms', (int) ($report['lookup_terms_by_class']['single_token_synonyms']['count'] ?? 0), '>=', 1);
        } elseif ($canonical_scenario === 'phrase-synonym-heavy') {
            $gates[] = self::gate('phrase-synonym-expanded', 'phrase_synonym_terms', (int) ($report['lookup_terms_by_class']['phrase_synonyms']['count'] ?? 0), '>=', 2);
            $gates[] = self::gate('phrase-synonym-position-rows-present', 'position_rows_fetched', (int) ($counters['position_rows_fetched'] ?? 0), '>', 0);
        }

        if (isset($options['memory_budget_mb'])) {
            $gates[] = self::gate('memory-budget', 'peak_memory_delta_bytes', (int) ($report['peak_memory_delta_bytes'] ?? 0), '<=', max(0, (int) $options['memory_budget_mb']) * 1024 * 1024);
        }
        if (isset($options['wall_time_budget_ms'])) {
            $gates[] = self::gate('wall-time-budget-advisory', 'wall_time_ms', (float) ($report['wall_time_ms'] ?? 0.0), '<=', max(0.0, (float) $options['wall_time_budget_ms']), 'advisory');
        }

        usort(
            $gates,
            static fn(array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id'])
        );

        return $gates;
    }

    /**
     * @param mixed $observed
     * @param mixed $limit
     * @return array{id:string,status:string,metric:string,observed:mixed,operator:string,limit:mixed,severity:string}
     */
    private static function gate(string $id, string $metric, mixed $observed, string $operator, mixed $limit, string $severity = 'hard'): array
    {
        return [
            'id' => $id,
            'status' => self::gate_passes($observed, $operator, $limit) ? 'pass' : 'fail',
            'metric' => $metric,
            'observed' => $observed,
            'operator' => $operator,
            'limit' => $limit,
            'severity' => $severity,
        ];
    }

    private static function gate_passes(mixed $observed, string $operator, mixed $limit): bool
    {
        if ($operator === '=') {
            return $observed === $limit;
        }
        if ($operator === '<=') {
            return (float) $observed <= (float) $limit;
        }
        if ($operator === '>=') {
            return (float) $observed >= (float) $limit;
        }
        if ($operator === '>') {
            return (float) $observed > (float) $limit;
        }

        throw new InvalidArgumentException('Unsupported benchmark gate operator: ' . $operator);
    }

    /**
     * @param array<string,mixed> $report
     */
    private static function required_report_fields_present(array $report): int
    {
        foreach ([
            'scenario',
            'query',
            'language',
            'document_count',
            'language_count',
            'limit',
            'result_ids',
            'counters',
            'wall_time_ms',
            'memory_delta_bytes',
            'peak_memory_delta_bytes',
        ] as $field) {
            if (!array_key_exists($field, $report)) {
                return 0;
            }
        }

        return 1;
    }

    private static function expected_common_term_top_id(int $document_count): int
    {
        if ($document_count >= 14) {
            return 14;
        }

        return 1;
    }

    /**
     * @param int[] $result_ids
     */
    private static function non_adjacent_phrase_result_count(array $result_ids): int
    {
        $count = 0;
        foreach ($result_ids as $post_id) {
            $local_id = $post_id % 100000;
            if ($local_id % 5 === 1) {
                $count++;
            }
        }

        return $count;
    }
}
