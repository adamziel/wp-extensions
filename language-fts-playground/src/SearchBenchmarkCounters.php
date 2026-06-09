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
 * Builds a deterministic synthetic index and runs public search probes against
 * the normal searcher while returning materialization counters.
 */
final class Language_FTS_Playground_Search_Benchmark_Fixture
{
    private const LANGUAGE = 'bm';

    /** @var array<string,string> */
    private const SCENARIO_QUERIES = [
        'common-term' => 'commonterm',
        'phrase' => '"alpha beta"',
        'fuzzy' => 'orchard~',
        'synonym' => 'lookup',
        'phrase-synonym' => 'portal lookup',
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
        return array_keys(self::SCENARIO_QUERIES);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function run_probe(string $scenario, array $options = []): array
    {
        $scenario = self::normalize_scenario($scenario);
        $document_count = max(1, (int) ($options['documents'] ?? 48));
        $limit = max(1, (int) ($options['limit'] ?? 5));
        $resource_root = (string) ($options['resource_root'] ?? self::resource_root());

        $storage = new Language_FTS_Playground_Search_Benchmark_Counting_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($resource_root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        self::index_documents($indexer, $document_count);

        $query = self::SCENARIO_QUERIES[$scenario];
        $explain = $searcher->explain($query, self::LANGUAGE, $limit);
        $lookup_terms_by_class = self::lookup_terms_by_class($explain);

        $storage->reset_counters();
        $memory_before = memory_get_usage(true);
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $results = $searcher->search($query, self::LANGUAGE, $limit);
        $counters = $storage->counters();
        $counters['peak_memory_delta_bytes'] = max(0, memory_get_peak_usage(true) - $memory_before);

        return [
            'scenario' => $scenario,
            'query' => $query,
            'language' => self::LANGUAGE,
            'document_count' => $document_count,
            'limit' => $limit,
            'result_count' => count($results),
            'result_post_ids' => array_values(array_map('intval', array_column($results, 'post_id'))),
            'lookup_terms_by_class' => $lookup_terms_by_class,
            'counters' => $counters,
        ];
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

    private static function normalize_scenario(string $scenario): string
    {
        $scenario = strtolower(trim($scenario));
        $aliases = [
            'common' => 'common-term',
            'common_term' => 'common-term',
            'term' => 'common-term',
            'phrase_synonym' => 'phrase-synonym',
            'phrasesynonym' => 'phrase-synonym',
        ];
        $scenario = $aliases[$scenario] ?? $scenario;
        if (!isset(self::SCENARIO_QUERIES[$scenario])) {
            throw new InvalidArgumentException('Unknown benchmark scenario: ' . $scenario);
        }

        return $scenario;
    }

    private static function index_documents(Language_FTS_Playground_Indexer $indexer, int $document_count): void
    {
        $common_matches = min($document_count, max(1, (int) floor($document_count * 0.75)));
        for ($i = 1; $i <= $document_count; $i++) {
            $indexer->index_post(self::post($i, $common_matches));
        }
    }

    private static function post(int $post_id, int $common_matches): object
    {
        return (object) [
            'ID' => $post_id,
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_password' => '',
            'post_title' => 'Benchmark document docid' . $post_id,
            'post_excerpt' => $post_id % 7 === 0 ? 'commonterm excerpt marker' : '',
            'post_content' => '<p>' . implode('. ', self::content_segments($post_id, $common_matches)) . '.</p>',
            'language' => self::LANGUAGE,
        ];
    }

    /**
     * @return string[]
     */
    private static function content_segments(int $post_id, int $common_matches): array
    {
        $segments = [
            'baseline fixture token docid' . $post_id,
        ];

        if ($post_id <= $common_matches) {
            $segments[] = 'commonterm shared payload';
        }

        if ($post_id % 5 === 0) {
            $segments[] = 'alpha beta adjacent phrase payload';
        } elseif ($post_id % 5 === 1) {
            $segments[] = 'alpha bridge beta separated payload';
        }

        if ($post_id % 4 === 0) {
            $segments[] = 'orchart typo payload';
        }

        if ($post_id % 6 === 0) {
            $segments[] = 'searchterm synonym target payload';
        }

        if ($post_id % 8 === 0) {
            $segments[] = 'search site adjacent phrase synonym target';
        } elseif ($post_id % 8 === 1) {
            $segments[] = 'search bridge site separated phrase synonym target';
        }

        return $segments;
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
}
