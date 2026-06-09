<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/LexicalPackValidator.php';

final class Language_FTS_Playground_Test_Failure extends RuntimeException
{
}

final class Language_FTS_Playground_Exploding_Phrase_Term
{
    public function __toString(): string
    {
        throw new RuntimeException('Unrelated phrase synonym row was evaluated.');
    }
}

final class Language_FTS_Playground_Test_Storage implements Language_FTS_Playground_Storage_Interface
{
    /** @var array<string,array{post_id:int,language:string,title:string,status:string,document_length:int,field_texts:array<string,string>,field_metadata:array<string,array{language:string,language_provenance:string}>,updated_at:string}> */
    private array $documents = [];

    /** @var array<string,array<string,array<int,array<string,int>>>> */
    private array $postings = [];

    /** @var array<string,array<string,array<int,int[]>>> */
    private array $positions = [];

    public int $install_count = 0;
    public int $clear_count = 0;
    public int $delete_count = 0;
    public int $fetch_term_language_hits_count = 0;
    public bool $fail_on_fetch_term_language_hits = false;
    public int $fetch_candidate_terms_count = 0;
    public bool $fail_on_fetch_candidate_terms = false;
    public int $fetch_document_fields_count = 0;
    public int $fetch_document_field_metadata_count = 0;
    /** @var string[] */
    public array $fetch_postings_languages = [];
    /** @var array<int,array{language:string,post_ids:int[]}> */
    public array $fetch_document_fields_requests = [];
    /** @var array<int,array{language:string,post_ids:int[]}> */
    public array $fetch_document_field_metadata_requests = [];

    public function install(): void
    {
        $this->install_count++;
    }

    public function clear(): void
    {
        $this->clear_count++;
        $this->documents = [];
        $this->postings = [];
        $this->positions = [];
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
        $this->replace_document_partitions(
            $post_id,
            [
                [
                    'language' => $language,
                    'title' => $title,
                    'status' => $status,
                    'document_length' => $document_length,
                    'field_term_frequencies' => $field_term_frequencies,
                    'field_texts' => $field_texts,
                    'term_positions' => $term_positions,
                ],
            ]
        );
    }

    public function replace_document_partitions(int $post_id, array $partitions): void
    {
        $this->delete_document($post_id);

        foreach ($partitions as $partition) {
            $language = (string) ($partition['language'] ?? '');
            $field_texts = (array) ($partition['field_texts'] ?? []);
            $field_keys = array_values(array_unique(array_merge(
                array_map('strval', array_keys($field_texts)),
                array_map('strval', array_keys((array) ($partition['field_term_frequencies'] ?? [])))
            )));
            $this->documents[$this->document_key($language, $post_id)] = [
                'post_id' => $post_id,
                'language' => $language,
                'title' => (string) ($partition['title'] ?? ''),
                'status' => (string) ($partition['status'] ?? ''),
                'document_length' => max(1, (int) ($partition['document_length'] ?? 0)),
                'field_texts' => $field_texts,
                'field_metadata' => $this->normalize_field_metadata($language, $field_keys, (array) ($partition['field_metadata'] ?? [])),
                'updated_at' => 'test',
            ];

            foreach ((array) ($partition['field_term_frequencies'] ?? []) as $field => $term_frequencies) {
                foreach ((array) $term_frequencies as $term => $tf) {
                    $term = (string) $term;
                    $field = (string) $field;
                    $this->postings[$language][$term][$post_id][$field] = max(1, (int) $tf);
                    $this->positions[$language][$term][$post_id] = array_values(array_map('intval', (array) ($partition['term_positions'][$term] ?? [])));
                }
            }
        }
    }

    public function delete_document(int $post_id): void
    {
        $this->delete_count++;
        foreach ($this->documents as $key => $document) {
            if ($document['post_id'] === $post_id) {
                unset($this->documents[$key]);
            }
        }
        foreach (array_keys($this->postings) as $language) {
            foreach (array_keys($this->postings[$language]) as $term) {
                unset($this->postings[$language][$term][$post_id]);
                unset($this->positions[$language][$term][$post_id]);
                if (($this->postings[$language][$term] ?? []) === []) {
                    unset($this->postings[$language][$term]);
                    unset($this->positions[$language][$term]);
                }
            }

            if (($this->postings[$language] ?? []) === []) {
                unset($this->postings[$language]);
            }
            if (($this->positions[$language] ?? []) === []) {
                unset($this->positions[$language]);
            }
        }
    }

    public function fetch_postings(string $language, array $terms): array
    {
        $this->fetch_postings_languages[] = $language;
        $result = [];
        foreach ($terms as $term) {
            $term = (string) $term;
            if (isset($this->postings[$language][$term])) {
                $result[$term] = $this->postings[$language][$term];
            }
        }

        return $result;
    }

    public function fetch_term_language_hits(array $language_terms): array
    {
        $this->fetch_term_language_hits_count++;
        if ($this->fail_on_fetch_term_language_hits) {
            throw new RuntimeException('fetch_term_language_hits should not run before lookup term cap enforcement.');
        }

        $hits = [];
        foreach ($language_terms as $language => $terms) {
            $language = (string) $language;
            $hits[$language] = [];

            foreach ($terms as $term) {
                $term = (string) $term;
                $hits[$language][$term] = ($this->postings[$language][$term] ?? []) !== [];
            }
        }

        return $hits;
    }

    public function fetch_positions(string $language, array $terms, array $post_ids): array
    {
        $post_id_lookup = [];
        foreach ($post_ids as $post_id) {
            $post_id_lookup[(int) $post_id] = true;
        }

        $result = [];
        foreach ($terms as $term) {
            $term = (string) $term;
            foreach ($this->positions[$language][$term] ?? [] as $post_id => $positions) {
                if (isset($post_id_lookup[(int) $post_id])) {
                    $result[$term][(int) $post_id] = $positions;
                }
            }
        }

        return $result;
    }

    public function fetch_candidate_terms(string $language, string $term, int $max_distance, int $limit): array
    {
        $this->fetch_candidate_terms_count++;
        if ($this->fail_on_fetch_candidate_terms) {
            throw new RuntimeException('fetch_candidate_terms should not run before lookup term cap enforcement.');
        }

        $max_distance = max(0, $max_distance);
        $min_length = max(1, strlen($term) - $max_distance);
        $max_length = strlen($term) + $max_distance;
        $limit = max(1, $limit);
        $terms = array_keys($this->postings[$language] ?? []);
        sort($terms, SORT_STRING);

        $candidates = [];
        foreach ($terms as $candidate) {
            $length = strlen($candidate);
            if ($length < $min_length || $length > $max_length) {
                continue;
            }

            if ($candidate === $term || levenshtein($term, $candidate) > $max_distance) {
                continue;
            }

            $candidates[] = $candidate;
            if (count($candidates) >= $limit) {
                break;
            }
        }

        return $candidates;
    }

    public function fetch_document_lengths(string $language, array $post_ids): array
    {
        $lengths = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            $key = $this->document_key($language, $post_id);
            if (isset($this->documents[$key])) {
                $lengths[$post_id] = $this->documents[$key]['document_length'];
            }
        }

        return $lengths;
    }

    public function fetch_document_fields(string $language, array $post_ids): array
    {
        $this->fetch_document_fields_count++;
        $this->fetch_document_fields_requests[] = [
            'language' => $language,
            'post_ids' => array_values(array_map('intval', $post_ids)),
        ];

        $fields = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            $key = $this->document_key($language, $post_id);
            if (isset($this->documents[$key])) {
                $fields[$post_id] = $this->documents[$key]['field_texts'];
            }
        }

        return $fields;
    }

    public function fetch_document_field_metadata(string $language, array $post_ids): array
    {
        $this->fetch_document_field_metadata_count++;
        $this->fetch_document_field_metadata_requests[] = [
            'language' => $language,
            'post_ids' => array_values(array_map('intval', $post_ids)),
        ];

        $metadata = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            $key = $this->document_key($language, $post_id);
            if (isset($this->documents[$key])) {
                $metadata[$post_id] = $this->documents[$key]['field_metadata'];
            }
        }

        return $metadata;
    }

    public function document_count(string $language): int
    {
        $count = 0;
        foreach ($this->documents as $document) {
            if ($document['language'] === $language) {
                $count++;
            }
        }

        return $count;
    }

    public function all_documents(): array
    {
        return array_values($this->documents);
    }

    private function document_key(string $language, int $post_id): string
    {
        return $language . "\t" . $post_id;
    }

    /**
     * @param string[] $field_keys
     * @param array<string,mixed> $field_metadata
     * @return array<string,array{language:string,language_provenance:string}>
     */
    private function normalize_field_metadata(string $language, array $field_keys, array $field_metadata): array
    {
        $metadata = [];
        foreach (array_unique(array_merge($field_keys, array_map('strval', array_keys($field_metadata)))) as $field) {
            $entry = $field_metadata[$field] ?? [];
            $entry = is_array($entry) ? $entry : [];
            $field_language = trim((string) ($entry['language'] ?? $language));
            $provenance = trim((string) ($entry['language_provenance'] ?? 'fallback'));

            $metadata[(string) $field] = [
                'language' => $field_language !== '' ? $field_language : $language,
                'language_provenance' => $provenance !== '' ? $provenance : 'fallback',
            ];
        }

        return $metadata;
    }
}

final class Language_FTS_Playground_Test_Failing_Storage implements Language_FTS_Playground_Storage_Interface
{
    public function __construct(private string $message = 'Simulated storage failure.')
    {
    }

    public function install(): void
    {
    }

    public function clear(): void
    {
        throw new RuntimeException($this->message);
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
        $this->replace_document_partitions(
            $post_id,
            [
                [
                    'language' => $language,
                    'title' => $title,
                    'status' => $status,
                    'document_length' => $document_length,
                    'field_term_frequencies' => $field_term_frequencies,
                    'field_texts' => $field_texts,
                    'term_positions' => $term_positions,
                ],
            ]
        );
    }

    public function replace_document_partitions(int $post_id, array $partitions): void
    {
        unset($post_id, $partitions);
        throw new RuntimeException($this->message);
    }

    public function delete_document(int $post_id): void
    {
        unset($post_id);
        throw new RuntimeException($this->message);
    }

    public function fetch_postings(string $language, array $terms): array
    {
        unset($language, $terms);
        throw new RuntimeException($this->message);
    }

    public function fetch_term_language_hits(array $language_terms): array
    {
        unset($language_terms);
        throw new RuntimeException($this->message);
    }

    public function fetch_positions(string $language, array $terms, array $post_ids): array
    {
        unset($language, $terms, $post_ids);
        throw new RuntimeException($this->message);
    }

    public function fetch_candidate_terms(string $language, string $term, int $max_distance, int $limit): array
    {
        unset($language, $term, $max_distance, $limit);
        throw new RuntimeException($this->message);
    }

    public function fetch_document_lengths(string $language, array $post_ids): array
    {
        unset($language, $post_ids);
        throw new RuntimeException($this->message);
    }

    public function fetch_document_fields(string $language, array $post_ids): array
    {
        unset($language, $post_ids);
        throw new RuntimeException($this->message);
    }

    public function fetch_document_field_metadata(string $language, array $post_ids): array
    {
        unset($language, $post_ids);
        throw new RuntimeException($this->message);
    }

    public function document_count(string $language): int
    {
        unset($language);
        throw new RuntimeException($this->message);
    }

    public function all_documents(): array
    {
        throw new RuntimeException($this->message);
    }
}

final class Language_FTS_Playground_Test_WPDB
{
    public string $prefix = 'wp_';
    public string $last_error = '';

    /** @var string[] */
    public array $queries = [];

    /** @var array<int,array{query:string,args:array<int,mixed>}> */
    public array $prepared = [];

    /** @var string[] */
    public array $result_queries = [];

    /** @var array<int,array{table:string,data:array<string,mixed>,format:string[]}> */
    public array $inserts = [];

    /** @var array<string,string[]> */
    private array $columns;

    /** @var array<string,string[]> */
    private array $indexes;

    /** @var string[] */
    private array $current_columns = [];

    /**
     * @param array<string,string[]> $columns
     * @param array<string,string[]> $indexes
     */
    public function __construct(private string $driver = 'sqlite', array $columns = [], array $indexes = [])
    {
        $this->columns = $columns + [
            'wp_language_fts_documents' => [
                'post_id',
                'language',
                'title',
                'status',
                'document_length',
                'field_texts',
                'field_metadata',
                'updated_at',
            ],
            'wp_language_fts_postings' => [
                'language',
                'term',
                'post_id',
                'field',
                'tf',
                'positions',
            ],
        ];
        $this->indexes = $indexes;
    }

    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $this->prepared[] = ['query' => $query, 'args' => $args];
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
            $query = preg_replace('/%[ds]/', $replacement, $query, 1) ?? $query;
        }

        return $query;
    }

    public function query(string $sql): int
    {
        $this->last_error = '';
        $this->queries[] = $sql;

        if (preg_match('/^ALTER TABLE ([A-Za-z0-9_]+) ADD COLUMN ([A-Za-z0-9_]+)/', $sql, $matches) === 1) {
            $table = $matches[1];
            $column = $matches[2];
            if (!in_array($column, $this->columns[$table] ?? [], true)) {
                $this->columns[$table][] = $column;
            }
        }

        if (preg_match('/^CREATE INDEX ([A-Za-z0-9_]+) ON ([A-Za-z0-9_]+)/', $sql, $matches) === 1) {
            $this->indexes[$matches[2]][] = $matches[1];
        }

        return 1;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function get_results(string $sql, string $output = 'ARRAY_A'): array
    {
        unset($output);
        $this->result_queries[] = $sql;

        if (preg_match('/^SELECT \* FROM ([A-Za-z0-9_]+) WHERE 1 = 0$/', $sql, $matches) === 1) {
            $this->last_error = '';
            $this->current_columns = $this->columns[$matches[1]] ?? [];

            return [];
        }

        if (preg_match('/^SHOW INDEX FROM ([A-Za-z0-9_]+)$/', $sql, $matches) === 1) {
            if ($this->driver !== 'mysql') {
                $this->last_error = 'near "SHOW": syntax error';

                return [];
            }

            $this->last_error = '';

            return array_map(
                static fn(string $name): array => ['Key_name' => $name],
                $this->indexes[$matches[1]] ?? []
            );
        }

        if (preg_match('/^PRAGMA index_list\(([A-Za-z0-9_]+)\)$/', $sql, $matches) === 1) {
            if ($this->driver !== 'sqlite') {
                $this->last_error = 'near "PRAGMA": syntax error';

                return [];
            }

            $this->last_error = '';

            return array_map(
                static fn(string $name): array => ['name' => $name],
                $this->indexes[$matches[1]] ?? []
            );
        }

        if (preg_match('/^PRAGMA table_info\(([A-Za-z0-9_]+)\)$/', $sql, $matches) === 1) {
            $this->last_error = $this->driver === 'sqlite' ? '' : 'near "PRAGMA": syntax error';

            return array_map(
                static fn(string $name): array => ['name' => $name],
                $this->columns[$matches[1]] ?? []
            );
        }

        if (preg_match('/^SHOW COLUMNS FROM ([A-Za-z0-9_]+)$/', $sql, $matches) === 1) {
            $this->last_error = $this->driver === 'mysql' ? '' : 'near "SHOW": syntax error';

            return array_map(
                static fn(string $name): array => ['Field' => $name],
                $this->columns[$matches[1]] ?? []
            );
        }

        $this->last_error = '';

        return [];
    }

    /**
     * @return string[]
     */
    public function get_col_info(string $type): array
    {
        return $type === 'name' ? $this->current_columns : [];
    }

    /**
     * @param array<string,mixed> $data
     * @param string[] $format
     */
    public function insert(string $table, array $data, array $format): int
    {
        $this->inserts[] = [
            'table' => $table,
            'data' => $data,
            'format' => $format,
        ];

        return 1;
    }
}

/**
 * @var array<int,array{name:string,fn:callable}>
 */
$tests = [];

function test_case(string $name, callable $fn): void
{
    global $tests;
    $tests[] = ['name' => $name, 'fn' => $fn];
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new Language_FTS_Playground_Test_Failure($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new Language_FTS_Playground_Test_Failure(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function assert_contains_text(string $needle, string $haystack, string $message): void
{
    assert_true(str_contains($haystack, $needle), $message . "\nMissing: {$needle}\nText: {$haystack}");
}

function assert_not_contains_text(string $needle, string $haystack, string $message): void
{
    assert_true(!str_contains($haystack, $needle), $message . "\nUnexpected: {$needle}\nText: {$haystack}");
}

function assert_throws(string $expected_class, callable $fn, string $message): Throwable
{
    try {
        $fn();
    } catch (Throwable $throwable) {
        assert_true(
            $throwable instanceof $expected_class,
            $message . "\nExpected exception: {$expected_class}\nActual exception: " . get_class($throwable)
        );

        return $throwable;
    }

    throw new Language_FTS_Playground_Test_Failure($message . "\nExpected exception: {$expected_class}\nNo exception was thrown.");
}

function reset_language_fts_wp_state(): void
{
    $GLOBALS['language_fts_test_options'] = [];
    $GLOBALS['language_fts_test_posts'] = [];
    $GLOBALS['language_fts_test_post_meta'] = [];
    $GLOBALS['language_fts_test_revisions'] = [];
    $GLOBALS['language_fts_test_autosaves'] = [];
    $GLOBALS['language_fts_test_actions'] = [];
    $GLOBALS['language_fts_test_filters'] = [];
    $GLOBALS['language_fts_test_scheduled'] = [];
    $GLOBALS['language_fts_test_option_reads'] = [];
    $GLOBALS['language_fts_test_get_option_interceptor'] = null;
    $GLOBALS['language_fts_test_failed_update_options'] = [];
    $GLOBALS['language_fts_test_current_user_can'] = true;
    $GLOBALS['language_fts_test_last_redirect'] = null;
    $_GET = [];
    $_POST = [];
}

function set_language_fts_plugin_runtime(Language_FTS_Playground_Storage_Interface $storage): void
{
    $storage_property = new ReflectionProperty(Language_FTS_Playground_Plugin::class, 'storage');
    $storage_property->setAccessible(true);
    $storage_property->setValue(null, $storage);

    $analyzer_property = new ReflectionProperty(Language_FTS_Playground_Plugin::class, 'analyzer');
    $analyzer_property->setAccessible(true);
    $analyzer_property->setValue(null, null);

    $queue_lock_token_property = new ReflectionProperty(Language_FTS_Playground_Plugin::class, 'queue_lock_token');
    $queue_lock_token_property->setAccessible(true);
    $queue_lock_token_property->setValue(null, null);

    $queue_lock_depth_property = new ReflectionProperty(Language_FTS_Playground_Plugin::class, 'queue_lock_depth');
    $queue_lock_depth_property->setAccessible(true);
    $queue_lock_depth_property->setValue(null, 0);

    $runtime_status_property = new ReflectionProperty(Language_FTS_Playground_Plugin::class, 'runtime_status');
    $runtime_status_property->setAccessible(true);
    $runtime_status_property->setValue(null, []);
}

function reset_language_fts_plugin_runtime(Language_FTS_Playground_Storage_Interface|null $storage = null): Language_FTS_Playground_Storage_Interface
{
    reset_language_fts_wp_state();
    $storage = $storage ?? new Language_FTS_Playground_Test_Storage();
    set_language_fts_plugin_runtime($storage);

    return $storage;
}

if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        $GLOBALS['language_fts_test_option_reads'][$name] = (int) ($GLOBALS['language_fts_test_option_reads'][$name] ?? 0) + 1;
        $interceptor = $GLOBALS['language_fts_test_get_option_interceptor'] ?? null;
        if (is_callable($interceptor)) {
            $interceptor($name, $GLOBALS['language_fts_test_option_reads'][$name]);
        }

        return array_key_exists($name, $GLOBALS['language_fts_test_options'] ?? [])
            ? $GLOBALS['language_fts_test_options'][$name]
            : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, mixed $value, mixed $autoload = null): bool
    {
        unset($autoload);
        if (!empty($GLOBALS['language_fts_test_failed_update_options'][$name])) {
            return false;
        }

        $old_value = get_option($name, null);
        $GLOBALS['language_fts_test_options'][$name] = $value;

        return $old_value !== $value;
    }
}

if (!function_exists('add_option')) {
    function add_option(string $name, mixed $value = '', mixed $deprecated = '', mixed $autoload = null): bool
    {
        unset($deprecated, $autoload);
        if (array_key_exists($name, $GLOBALS['language_fts_test_options'] ?? [])) {
            return false;
        }

        $GLOBALS['language_fts_test_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        $existed = array_key_exists($name, $GLOBALS['language_fts_test_options'] ?? []);
        unset($GLOBALS['language_fts_test_options'][$name]);

        return $existed;
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable|array|string $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['language_fts_test_actions'][$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args,
        ];

        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable|array|string $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['language_fts_test_filters'][$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args,
        ];

        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $filters = $GLOBALS['language_fts_test_filters'][$hook] ?? [];
        usort(
            $filters,
            static fn(array $a, array $b): int => ((int) ($a['priority'] ?? 10)) <=> ((int) ($b['priority'] ?? 10))
        );

        foreach ($filters as $filter) {
            $callback = $filter['callback'] ?? null;
            if (!is_callable($callback)) {
                continue;
            }

            $accepted_args = max(1, (int) ($filter['accepted_args'] ?? 1));
            $call_args = array_slice(array_merge([$value], $args), 0, $accepted_args);
            $value = $callback(...$call_args);
        }

        return $value;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook): int|false
    {
        return isset($GLOBALS['language_fts_test_scheduled'][$hook]) ? 1 : false;
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event(int $timestamp, string $hook): bool
    {
        $GLOBALS['language_fts_test_scheduled'][$hook][] = $timestamp;

        return true;
    }
}

if (!function_exists('wp_is_post_revision')) {
    function wp_is_post_revision(int $post_id): int|false
    {
        return !empty($GLOBALS['language_fts_test_revisions'][$post_id]) ? $post_id : false;
    }
}

if (!function_exists('wp_is_post_autosave')) {
    function wp_is_post_autosave(int $post_id): int|false
    {
        return !empty($GLOBALS['language_fts_test_autosaves'][$post_id]) ? $post_id : false;
    }
}

if (!function_exists('get_post')) {
    function get_post(int|object|null $post = null): object|null
    {
        if (is_object($post)) {
            return $post;
        }

        $post_id = (int) $post;

        return $GLOBALS['language_fts_test_posts'][$post_id] ?? null;
    }
}

if (!function_exists('get_posts')) {
    function get_posts(array $args = []): array
    {
        $posts = array_values($GLOBALS['language_fts_test_posts'] ?? []);
        $statuses = array_map('strval', (array) ($args['post_status'] ?? []));
        $types = array_map('strval', (array) ($args['post_type'] ?? []));

        $posts = array_values(array_filter(
            $posts,
            static function (object $post) use ($statuses, $types): bool {
                $status_matches = $statuses === [] || in_array((string) ($post->post_status ?? ''), $statuses, true);
                $type_matches = $types === [] || in_array((string) ($post->post_type ?? 'post'), $types, true);

                return $status_matches && $type_matches;
            }
        ));

        usort($posts, static fn(object $a, object $b): int => ((int) ($a->ID ?? 0)) <=> ((int) ($b->ID ?? 0)));

        if (($args['fields'] ?? '') === 'ids') {
            return array_map(static fn(object $post): int => (int) $post->ID, $posts);
        }

        return $posts;
    }
}

if (!function_exists('get_post_type')) {
    function get_post_type(int|object|null $post = null): string|false
    {
        $post = is_object($post) ? $post : get_post((int) $post);

        return is_object($post) ? (string) ($post->post_type ?? 'post') : false;
    }
}

if (!function_exists('get_post_type_object')) {
    function get_post_type_object(string $post_type): object|null
    {
        $public = !in_array($post_type, ['revision', 'nav_menu_item', 'private_type'], true);

        return (object) [
            'name' => $post_type,
            'public' => $public,
            'publicly_queryable' => $public,
        ];
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed
    {
        unset($single);

        return $GLOBALS['language_fts_test_post_meta'][$post_id][$key] ?? '';
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        unset($capability);

        return (bool) ($GLOBALS['language_fts_test_current_user_can'] ?? false);
    }
}

if (!function_exists('wp_die')) {
    function wp_die(string $message): never
    {
        throw new RuntimeException($message);
    }
}

if (!function_exists('check_admin_referer')) {
    function check_admin_referer(string $action): bool
    {
        unset($action);

        return true;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        unset($domain);

        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        unset($domain);

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return $url;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $text): string
    {
        return trim(strip_tags($text));
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(string $value): string
    {
        return stripslashes($value);
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return '/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_nonce_url')) {
    function wp_nonce_url(string $url, string $action): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . '_wpnonce=' . rawurlencode($action);
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action): string
    {
        return $action;
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(array $args, string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
    }
}

if (!function_exists('selected')) {
    function selected(mixed $selected, mixed $current = true, bool $display = true): string
    {
        $result = (string) $selected === (string) $current ? ' selected="selected"' : '';
        if ($display) {
            echo $result;
        }

        return $result;
    }
}

if (!function_exists('submit_button')) {
    function submit_button(string $text, string $type = 'primary', string $name = 'submit', bool $wrap = true): void
    {
        unset($type, $name, $wrap);
        echo '<button type="submit">' . esc_html($text) . '</button>';
    }
}

if (!function_exists('number_format_i18n')) {
    function number_format_i18n(float $number, int $decimals = 0): string
    {
        return number_format($number, $decimals, '.', ',');
    }
}

if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link(int $post_id): string
    {
        return '/wp-admin/post.php?post=' . $post_id . '&action=edit';
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title(int|object $post): string
    {
        $post = is_object($post) ? $post : get_post((int) $post);

        return is_object($post) ? (string) ($post->post_title ?? '') : '';
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location): bool
    {
        $GLOBALS['language_fts_test_last_redirect'] = $location;

        return true;
    }
}

function assert_query_terms_overlap(
    Language_FTS_Playground_Analyzer $analyzer,
    string $language,
    string $document_text,
    string $query,
    string $message
): void
{
    $document_terms = $analyzer->analyze_text($document_text, $language);
    $query_terms = $analyzer->analyze_query($query, $language);

    assert_true(
        array_values(array_intersect($document_terms, $query_terms)) !== [],
        $message .
        "\nDocument terms: " . var_export($document_terms, true) .
        "\nQuery terms: " . var_export($query_terms, true)
    );
}

function assert_query_terms_do_not_overlap(
    Language_FTS_Playground_Analyzer $analyzer,
    string $language,
    string $document_text,
    string $query,
    string $message
): void {
    $document_terms = $analyzer->analyze_text($document_text, $language);
    $query_terms = $analyzer->analyze_query($query, $language);

    assert_same(
        [],
        array_values(array_intersect($document_terms, $query_terms)),
        $message .
        "\nDocument terms: " . var_export($document_terms, true) .
        "\nQuery terms: " . var_export($query_terms, true)
    );
}

function fixture_post(
    int $id,
    string $language,
    string $title,
    string $content,
    string $status = 'publish',
    string $post_type = 'post',
    string $password = ''
): object
{
    return (object) [
        'ID' => $id,
        'post_status' => $status,
        'post_type' => $post_type,
        'post_password' => $password,
        'post_title' => $title,
        'post_excerpt' => '',
        'post_content' => $content,
        'language' => $language,
    ];
}

function mixed_language_segment_fixture_post(int $id, bool $include_polish_segment = true): object
{
    $content = '<p>Searching pages stay visible in English.</p>';
    if ($include_polish_segment) {
        $content .= '<p lang="pl">Partycja wyszukiwania pokazuje wynik.</p>';
    }

    return fixture_post($id, 'en', 'Mixed language note', $content);
}

/**
 * @param array<string,mixed> $explain_result
 * @return array<int,array{field:string,language:string,language_provenance:string}>
 */
function language_fts_explain_field_language_details(array $explain_result): array
{
    $language_details = [];
    foreach ((array) ($explain_result['score_breakdown']['details'] ?? []) as $score_detail) {
        if (!is_array($score_detail)) {
            continue;
        }

        foreach ((array) ($score_detail['fields'] ?? []) as $field_detail) {
            if (!is_array($field_detail)) {
                continue;
            }

            $language_details[] = [
                'field' => (string) ($field_detail['field'] ?? ''),
                'language' => (string) ($field_detail['language'] ?? ''),
                'language_provenance' => (string) ($field_detail['language_provenance'] ?? ''),
            ];
        }
    }

    return $language_details;
}

function create_language_fts_temp_profile_tree(
    string $lexemes,
    string $synonyms = "# source\ttarget\tdirection\tweight\tprovenance\n",
    string|null $synsets = null,
    string|null $synonym_phrases = null,
    string|null $term_rules = null,
    string|null $protected_terms = null
): string
{
    $root = sys_get_temp_dir() . '/language-fts-profile-' . str_replace('.', '-', uniqid('', true));
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    assert_true(mkdir($language_dir, 0777, true), 'Temporary language profile directory is created.');
    $synset_resource = $synsets === null ? '' : "        'synsets' => 'synsets.tsv',\n";
    $synonym_phrase_resource = $synonym_phrases === null ? '' : "        'synonym_phrases' => 'synonym_phrases.tsv',\n";
    $term_rule_resource = $term_rules === null ? '' : "        'term_rules' => 'term_rules.tsv',\n";
    $protected_terms_resource = $protected_terms === null ? '' : "        'protected_terms' => 'protected_terms.txt',\n";

    file_put_contents(
        $language_dir . DIRECTORY_SEPARATOR . 'profile.php',
        "<?php\nreturn [\n" .
        "    'id' => 'xx',\n" .
        "    'label' => 'Test',\n" .
        "    'resources' => [\n" .
        "        'stopwords' => 'stopwords.txt',\n" .
        "        'lexemes' => 'lexemes.tsv',\n" .
        "        'synonyms' => 'synonyms.tsv',\n" .
        $synset_resource .
        $synonym_phrase_resource .
        $term_rule_resource .
        $protected_terms_resource .
        "    ],\n" .
        "];\n"
    );
    file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'stopwords.txt', "and\n");
    file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'lexemes.tsv', $lexemes);
    file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'synonyms.tsv', $synonyms);
    if ($synsets !== null) {
        file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'synsets.tsv', $synsets);
    }
    if ($synonym_phrases !== null) {
        file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'synonym_phrases.tsv', $synonym_phrases);
    }
    if ($term_rules !== null) {
        file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'term_rules.tsv', $term_rules);
    }
    if ($protected_terms !== null) {
        file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'protected_terms.txt', $protected_terms);
    }

    return $root;
}

/**
 * @param array<string,array<string,mixed>> $profiles
 */
function create_language_fts_temp_profile_set(array $profiles): string
{
    $root = sys_get_temp_dir() . '/language-fts-profile-set-' . str_replace('.', '-', uniqid('', true));
    assert_true(mkdir($root, 0777, true), 'Temporary language profile set root is created.');

    foreach ($profiles as $language => $definition) {
        $language = (string) $language;
        $language_dir = $root . DIRECTORY_SEPARATOR . $language;
        assert_true(mkdir($language_dir, 0777, true), "Temporary {$language} profile directory is created.");

        $resources = [
            'stopwords' => 'stopwords.txt',
            'lexemes' => 'lexemes.tsv',
            'synonyms' => 'synonyms.tsv',
        ];
        if (array_key_exists('synsets', $definition)) {
            $resources['synsets'] = 'synsets.tsv';
        }
        if (array_key_exists('synonym_phrases', $definition)) {
            $resources['synonym_phrases'] = 'synonym_phrases.tsv';
        }
        if (array_key_exists('term_rules', $definition)) {
            $resources['term_rules'] = 'term_rules.tsv';
        }
        if (array_key_exists('protected_terms', $definition)) {
            $resources['protected_terms'] = 'protected_terms.txt';
        }

        $profile = [
            'id' => $language,
            'label' => (string) ($definition['label'] ?? strtoupper($language)),
            'order' => (int) ($definition['order'] ?? 100),
            'resources' => $resources,
        ];
        if (isset($definition['folds']) && is_array($definition['folds'])) {
            $profile['normalization'] = ['fold' => $definition['folds']];
        }
        if (isset($definition['signals']) && is_array($definition['signals'])) {
            $profile['language_signals'] = array_values(array_map('strval', $definition['signals']));
        }
        if (array_key_exists('tokenizer', $definition)) {
            $profile['tokenizer'] = $definition['tokenizer'];
        }

        file_put_contents(
            $language_dir . DIRECTORY_SEPARATOR . 'profile.php',
            "<?php\nreturn " . var_export($profile, true) . ";\n"
        );
        file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'stopwords.txt', (string) ($definition['stopwords'] ?? "\n"));
        file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'lexemes.tsv', (string) ($definition['lexemes'] ?? "# observed\tcanonical\tprovenance\n"));
        file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'synonyms.tsv', (string) ($definition['synonyms'] ?? "# source\ttarget\tdirection\tweight\tprovenance\n"));
        if (array_key_exists('synsets', $definition)) {
            file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'synsets.tsv', (string) $definition['synsets']);
        }
        if (array_key_exists('synonym_phrases', $definition)) {
            file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'synonym_phrases.tsv', (string) $definition['synonym_phrases']);
        }
        if (array_key_exists('term_rules', $definition)) {
            file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'term_rules.tsv', (string) $definition['term_rules']);
        }
        if (array_key_exists('protected_terms', $definition)) {
            file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'protected_terms.txt', (string) $definition['protected_terms']);
        }
    }

    return $root;
}

/**
 * @param array<string,mixed> $overrides
 */
function write_language_fts_temp_pack_metadata(string $language_dir, array $overrides = []): void
{
    $files = [
        'profile.php',
        'stopwords.txt',
        'lexemes.tsv',
        'synonyms.tsv',
    ];
    if (is_file($language_dir . DIRECTORY_SEPARATOR . 'synsets.tsv')) {
        $files[] = 'synsets.tsv';
    }
    if (is_file($language_dir . DIRECTORY_SEPARATOR . 'synonym_phrases.tsv')) {
        $files[] = 'synonym_phrases.tsv';
    }
    if (is_file($language_dir . DIRECTORY_SEPARATOR . 'term_rules.tsv')) {
        $files[] = 'term_rules.tsv';
    }
    if (is_file($language_dir . DIRECTORY_SEPARATOR . 'protected_terms.txt')) {
        $files[] = 'protected_terms.txt';
    }

    $metadata = array_merge(
        [
            'language_id' => basename($language_dir),
            'pack_version' => 'fixture-2026-06-08',
            'pack_date' => '2026-06-08',
            'source_name' => 'Fixture lexical pack',
            'source_url' => 'https://example.test/fixture-lexical-pack',
            'license_name' => 'Fixture license',
            'attribution_text' => 'Fixture lexical pack data.',
            'provenance' => 'fixture-lexical-pack',
            'files' => $files,
            'data_kind' => 'curated_seed',
        ],
        $overrides
    );

    file_put_contents(
        $language_dir . DIRECTORY_SEPARATOR . 'pack.php',
        "<?php\nreturn " . var_export($metadata, true) . ";\n"
    );
}

/**
 * @param array<string,mixed> $metadata
 */
function write_language_fts_temp_pack_metadata_array(string $language_dir, array $metadata): void
{
    file_put_contents(
        $language_dir . DIRECTORY_SEPARATOR . 'pack.php',
        "<?php\nreturn " . var_export($metadata, true) . ";\n"
    );
}

/**
 * @return array{resource:string,file:string,sha256:string,bytes:int,generated:bool}
 */
function language_fts_temp_runtime_file_record(string $language_dir, string $resource, string $file, bool $generated = false): array
{
    $path = $language_dir . DIRECTORY_SEPARATOR . $file;

    return [
        'resource' => $resource,
        'file' => $file,
        'sha256' => (string) hash_file('sha256', $path),
        'bytes' => (int) filesize($path),
        'generated' => $generated,
    ];
}

/**
 * @return array<int,array{resource:string,file:string,sha256:string,bytes:int,generated:bool}>
 */
function language_fts_temp_runtime_file_records(string $language_dir): array
{
    $profile_path = $language_dir . DIRECTORY_SEPARATOR . 'profile.php';
    $profile = require $profile_path;
    assert_true(is_array($profile), 'Temporary profile metadata is readable.');

    $records = [
        language_fts_temp_runtime_file_record($language_dir, 'profile', 'profile.php', false),
    ];
    foreach ((array) ($profile['resources'] ?? []) as $resource => $file) {
        $file = (string) $file;
        if (is_file($language_dir . DIRECTORY_SEPARATOR . $file)) {
            $records[] = language_fts_temp_runtime_file_record($language_dir, (string) $resource, $file, false);
        }
    }
    if (is_file($language_dir . DIRECTORY_SEPARATOR . 'LICENSE.fixture.txt')) {
        $records[] = language_fts_temp_runtime_file_record($language_dir, 'license', 'LICENSE.fixture.txt', false);
    }

    usort(
        $records,
        static fn(array $a, array $b): int => strcmp($a['resource'], $b['resource'])
            ?: strcmp($a['file'], $b['file'])
    );

    return $records;
}

/**
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function language_fts_temp_comprehensive_pack_metadata(string $language_dir, array $overrides = []): array
{
    $license_path = $language_dir . DIRECTORY_SEPARATOR . 'LICENSE.fixture.txt';
    if (!is_file($license_path)) {
        file_put_contents($license_path, "Fixture license text.\n");
    }

    $runtime_files = language_fts_temp_runtime_file_records($language_dir);
    $files = array_values(array_unique(array_column($runtime_files, 'file')));
    sort($files, SORT_STRING);
    $profile_sha256 = (string) hash_file('sha256', $language_dir . DIRECTORY_SEPARATOR . 'profile.php');

    $metadata = [
        'metadata_schema' => Language_FTS_Playground_Lexical_Pack_Validator::METADATA_SCHEMA_V2,
        'language_id' => basename($language_dir),
        'pack_version' => 'fixture-comprehensive-2026-06-09',
        'pack_date' => '2026-06-09',
        'data_kind' => 'imported_comprehensive',
        'source_name' => 'Fixture comprehensive lexical source',
        'source_url' => 'https://example.test/fixture-comprehensive-source',
        'license_name' => 'Fixture License 1.0',
        'attribution_text' => 'Fixture comprehensive attribution.',
        'provenance' => 'fixture-comprehensive',
        'files' => $files,
        'source' => [
            'name' => 'Fixture comprehensive lexical source',
            'version' => '2026-06-fixture',
            'retrieved_at' => '2026-06-09',
            'artifacts' => [
                [
                    'name' => 'fixture-source.json',
                    'url' => 'https://example.test/fixture-source.json',
                    'sha256' => str_repeat('a', 64),
                    'bytes' => 123,
                ],
            ],
        ],
        'license' => [
            'identifier' => 'Fixture-1.0',
            'name' => 'Fixture License 1.0',
            'url' => 'https://example.test/license',
            'text_url' => 'https://example.test/license.txt',
            'text_file' => 'LICENSE.fixture.txt',
            'attribution' => 'Fixture comprehensive attribution.',
        ],
        'provenance_ids' => [
            'fixture-comprehensive' => [
                'source' => 'Fixture comprehensive lexical source',
                'source_version' => '2026-06-fixture',
                'description' => 'Fixture rows generated from a reviewed source snapshot.',
            ],
        ],
        'normalization' => [
            'profile_id' => basename($language_dir),
            'profile_version' => 'language-fts-playground-normalization-fixture-v1',
            'profile_file' => 'profile.php',
            'profile_sha256' => $profile_sha256,
        ],
        'importer' => [
            'name' => 'language-fts-playground/tools/import-lexical-source.php',
            'version' => 'language-fts-playground-lexical-importer-v2',
            'format' => 'membership-tsv',
            'command' => 'php language-fts-playground/tools/import-lexical-source.php membership-tsv <source-artifact> <output-dir> --data-kind=imported_comprehensive',
            'options' => [
                'data_kind' => 'imported_comprehensive',
                'language' => basename($language_dir),
            ],
        ],
        'runtime_files' => $runtime_files,
    ];

    return array_replace_recursive($metadata, $overrides);
}

/**
 * @param array<string,mixed> $overrides
 */
function write_language_fts_temp_comprehensive_pack_metadata(string $language_dir, array $overrides = []): void
{
    write_language_fts_temp_pack_metadata_array($language_dir, language_fts_temp_comprehensive_pack_metadata($language_dir, $overrides));
}

/**
 * @return string[]
 */
function language_fts_numbered_terms(string $prefix, int $count): array
{
    $terms = [];
    for ($index = 1; $index <= $count; $index++) {
        $terms[] = $prefix . sprintf('%03d', $index);
    }

    return $terms;
}

/**
 * @param array<string,mixed> $report
 * @return array<string,array<string,mixed>>
 */
function language_fts_pack_status_by_id(array $report): array
{
    $by_id = [];
    foreach ((array) ($report['languages'] ?? []) as $language) {
        if (is_array($language) && isset($language['language_id'])) {
            $by_id[(string) $language['language_id']] = $language;
        }
    }

    return $by_id;
}

function remove_language_fts_temp_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $entries = scandir($path);
    foreach ($entries === false ? [] : $entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child)) {
            remove_language_fts_temp_tree($child);
        } else {
            unlink($child);
        }
    }

    rmdir($path);
}

function create_language_fts_temp_dir(string $prefix): string
{
    $path = sys_get_temp_dir() . '/' . $prefix . '-' . str_replace('.', '-', uniqid('', true));
    assert_true(mkdir($path, 0777, true), 'Temporary directory is created.');

    return $path;
}

/**
 * @return array{root:string,language_dir:string}
 */
function create_language_fts_temp_import_profile(string $language): array
{
    $root = create_language_fts_temp_dir('language-fts-import-profile');
    $language_dir = $root . DIRECTORY_SEPARATOR . $language;
    assert_true(mkdir($language_dir, 0777, true), 'Temporary import language directory is created.');

    file_put_contents(
        $language_dir . DIRECTORY_SEPARATOR . 'profile.php',
        "<?php\nreturn [\n" .
        "    'id' => '{$language}',\n" .
        "    'label' => 'Imported Test',\n" .
        "    'resources' => [\n" .
        "        'stopwords' => 'stopwords.txt',\n" .
        "        'lexemes' => 'lexemes.tsv',\n" .
        "        'synonyms' => 'synonyms.tsv',\n" .
        "        'synsets' => 'synsets.tsv',\n" .
        "    ],\n" .
        "];\n"
    );
    file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'stopwords.txt', "\n");
    file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'synonyms.tsv', "# source\ttarget\tdirection\tweight\tprovenance\n");

    return [
        'root' => $root,
        'language_dir' => $language_dir,
    ];
}

function language_fts_import_fixture_path(string $name): string
{
    return __DIR__ . '/fixtures/lexical-imports/' . $name;
}

function language_fts_eval_fixture_path(string $name): string
{
    return __DIR__ . '/fixtures/lexical-eval/' . $name;
}

function language_fts_morphology_fixture_path(string $name): string
{
    return __DIR__ . '/fixtures/morphology-sources/' . $name;
}

/**
 * @return array<string,mixed>
 */
function read_language_fts_morphology_fixture(string $name): array
{
    $path = language_fts_morphology_fixture_path($name);
    $json = file_get_contents($path);
    assert_true(is_string($json), 'Morphology fixture can be read: ' . $path);
    $fixture = json_decode($json, true);
    assert_true(is_array($fixture), 'Morphology fixture JSON decodes to an object: ' . $path);

    return $fixture;
}

/**
 * @param array<string,mixed> $fixture
 */
function write_language_fts_morphology_fixture_file(string $path, array $fixture): void
{
    $json = json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    assert_true(is_string($json), 'Morphology fixture variant encodes as JSON.');
    file_put_contents($path, $json . "\n");
}

/**
 * @param array<string,mixed> $options
 * @return array{exit_code:int,output:string}
 */
function run_language_fts_morphology_compiler(string $input_path, string $output_dir, array $options = []): array
{
    $command = [
        escapeshellarg(PHP_BINARY),
        '-n',
        escapeshellarg(__DIR__ . '/../tools/compile-morphology-fixture.php'),
        escapeshellarg($input_path),
        escapeshellarg($output_dir),
    ];

    if (!empty($options['file_only'])) {
        $command[] = escapeshellarg('--file-only');
    }

    $lines = [];
    $exit_code = 0;
    exec(implode(' ', $command) . ' 2>&1', $lines, $exit_code);

    return [
        'exit_code' => $exit_code,
        'output' => implode("\n", $lines),
    ];
}

/**
 * @return array<string,mixed>
 */
function compile_language_fts_morphology_fixture_to_root(string $fixture_name, string $root): array
{
    $fixture = read_language_fts_morphology_fixture($fixture_name);
    $language = (string) ($fixture['language'] ?? '');
    assert_true($language !== '', 'Morphology fixture declares a language.');
    $language_dir = $root . DIRECTORY_SEPARATOR . $language;
    assert_true(mkdir($language_dir, 0777, true), 'Temporary morphology language directory is created.');

    $result = run_language_fts_morphology_compiler(language_fts_morphology_fixture_path($fixture_name), $language_dir);
    assert_same(0, $result['exit_code'], 'Morphology fixture compiler exits successfully for ' . $fixture_name . '. Output: ' . $result['output']);

    return $fixture;
}

/**
 * @param array<string,mixed> $fixture
 */
function assert_language_fts_morphology_fixture_behavior(array $fixture, Language_FTS_Playground_Analyzer $analyzer): void
{
    $language = (string) ($fixture['language'] ?? '');
    assert_true($language !== '', 'Morphology fixture behavior has a language.');

    foreach ((array) ($fixture['stemmer_sample_pairs'] ?? []) as $pair) {
        assert_true(is_array($pair), 'Stemmer sample pair is an object.');
        $id = (string) ($pair['id'] ?? '');
        $surface = (string) ($pair['surface'] ?? '');
        $reference_key = (string) ($pair['reference_key'] ?? '');
        $policy = (string) ($pair['policy'] ?? '');
        $terms = $analyzer->analyze_text($surface, $language);

        if ($policy === 'must_emit') {
            assert_true(
                in_array($reference_key, $terms, true),
                "Morphology sample {$id} emits {$reference_key}. Terms: " . var_export($terms, true)
            );
        } elseif ($policy === 'must_not_emit') {
            assert_true(
                !in_array($reference_key, $terms, true),
                "Morphology bait sample {$id} does not emit {$reference_key}. Terms: " . var_export($terms, true)
            );
        }
    }

    foreach ((array) ($fixture['analyzer_expectations'] ?? []) as $expectation) {
        assert_true(is_array($expectation), 'Analyzer expectation is an object.');
        $id = (string) ($expectation['id'] ?? '');
        $terms = $analyzer->analyze_text((string) ($expectation['text'] ?? ''), $language);

        if (array_key_exists('keys_exact', $expectation)) {
            assert_same(
                array_values(array_map('strval', (array) $expectation['keys_exact'])),
                $terms,
                "Morphology analyzer expectation {$id} has exact keys."
            );
        }
        foreach (array_values(array_map('strval', (array) ($expectation['keys_include'] ?? []))) as $key) {
            assert_true(
                in_array($key, $terms, true),
                "Morphology analyzer expectation {$id} includes {$key}. Terms: " . var_export($terms, true)
            );
        }
        foreach (array_values(array_map('strval', (array) ($expectation['keys_exclude'] ?? []))) as $key) {
            assert_true(
                !in_array($key, $terms, true),
                "Morphology analyzer expectation {$id} excludes {$key}. Terms: " . var_export($terms, true)
            );
        }
    }
}

/**
 * @param array<string,string> $options
 * @return array{exit_code:int,output:string}
 */
function run_language_fts_importer(string $format, string $input_path, string $output_dir, array $options): array
{
    $command = [
        escapeshellarg(PHP_BINARY),
        '-n',
        escapeshellarg(__DIR__ . '/../tools/import-lexical-source.php'),
        escapeshellarg($format),
        escapeshellarg($input_path),
        escapeshellarg($output_dir),
    ];

    foreach ($options as $key => $value) {
        $command[] = escapeshellarg('--' . str_replace('_', '-', $key) . '=' . $value);
    }

    $lines = [];
    $exit_code = 0;
    exec(implode(' ', $command) . ' 2>&1', $lines, $exit_code);

    return [
        'exit_code' => $exit_code,
        'output' => implode("\n", $lines),
    ];
}

/**
 * @param array<string,mixed> $options
 * @return array{exit_code:int,output:string}
 */
function run_language_fts_validator(array $options = [], bool $no_ini = true): array
{
    $command = [
        escapeshellarg(PHP_BINARY),
    ];
    if ($no_ini) {
        $command[] = '-n';
    }
    $command[] = escapeshellarg(__DIR__ . '/../tools/validate-lexical-packs.php');

    foreach ($options as $key => $value) {
        $option = '--' . str_replace('_', '-', (string) $key);
        if ($value === true) {
            $command[] = escapeshellarg($option);
        } elseif ($value !== false && $value !== null) {
            $command[] = escapeshellarg($option . '=' . (string) $value);
        }
    }

    $lines = [];
    $exit_code = 0;
    exec(implode(' ', $command) . ' 2>&1', $lines, $exit_code);

    return [
        'exit_code' => $exit_code,
        'output' => implode("\n", $lines),
    ];
}

/**
 * @param array<string,mixed> $options
 * @return array{exit_code:int,output:string}
 */
function run_language_fts_evaluator(string $fixture_path, array $options = [], bool $no_ini = false): array
{
    $command = [
        escapeshellarg(PHP_BINARY),
    ];
    if ($no_ini) {
        $command[] = '-n';
    }
    $command[] = escapeshellarg(__DIR__ . '/../tools/evaluate-lexical-pack.php');
    $command[] = escapeshellarg($fixture_path);

    foreach ($options as $key => $value) {
        $option = '--' . str_replace('_', '-', (string) $key);
        if ($value === true) {
            $command[] = escapeshellarg($option);
        } elseif ($value !== false && $value !== null) {
            $command[] = escapeshellarg($option . '=' . (string) $value);
        }
    }

    $lines = [];
    $exit_code = 0;
    exec(implode(' ', $command) . ' 2>&1', $lines, $exit_code);

    return [
        'exit_code' => $exit_code,
        'output' => implode("\n", $lines),
    ];
}

/**
 * @param array<string,mixed> $options
 * @return array{exit_code:int,output:string}
 */
function run_language_fts_search_benchmark(array $options = [], bool $no_ini = false): array
{
    $command = [
        escapeshellarg(PHP_BINARY),
    ];
    if ($no_ini) {
        $command[] = '-n';
    }
    $command[] = escapeshellarg(__DIR__ . '/../tools/search-benchmark-counters.php');

    foreach ($options as $key => $value) {
        $option = '--' . str_replace('_', '-', (string) $key);
        if ($value === true) {
            $command[] = escapeshellarg($option);
        } elseif ($value !== false && $value !== null) {
            $command[] = escapeshellarg($option . '=' . (string) $value);
        }
    }

    $lines = [];
    $exit_code = 0;
    exec(implode(' ', $command) . ' 2>&1', $lines, $exit_code);

    return [
        'exit_code' => $exit_code,
        'output' => implode("\n", $lines),
    ];
}

/**
 * @return array{exit_code:int,output:string}
 */
function run_language_fts_wp_processor_lang_probe(): array
{
    $script_path = tempnam(sys_get_temp_dir(), 'language-fts-wp-processor-probe-');
    assert_true(is_string($script_path), 'Temporary WP_HTML_Processor probe script can be created.');

    $script = <<<'PHP'
<?php
declare(strict_types=1);

final class WP_HTML_Processor
{
    /** @var string[] */
    private array $tokens = [];
    private int $position = -1;

    public static function create_fragment(string $html): self
    {
        return new self($html);
    }

    private function __construct(string $html)
    {
        $matches = [];
        if (preg_match_all('/[^<]+/u', $html, $matches) === false) {
            return;
        }

        foreach ($matches[0] as $token) {
            $token = (string) $token;
            if ($token === '') {
                continue;
            }

            $this->tokens[] = $token;
        }
    }

    public function next_token(): bool
    {
        $this->position++;

        return isset($this->tokens[$this->position]);
    }

    /**
     * @return string[]
     */
    public function get_breadcrumbs(): array
    {
        return [];
    }

    public function get_token_type(): string
    {
        return '#text';
    }

    public function get_modifiable_text(): string
    {
        return (string) ($this->tokens[$this->position] ?? '');
    }

    public function get_tag(): string
    {
        return '';
    }
}

function probe_fail(string $message, array $payload = []): void
{
    fwrite(STDERR, $message . "\n");
    if ($payload !== []) {
        fwrite(STDERR, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
    exit(1);
}

/**
 * @param array<int,array{post_id:int}> $results
 * @return int[]
 */
function probe_post_ids(array $results): array
{
    return array_values(array_map(static fn(array $result): int => (int) $result['post_id'], $results));
}

/**
 * @param array<int,array{language:string}> $documents
 * @return string[]
 */
function probe_document_languages(array $documents): array
{
    $languages = array_values(array_unique(array_map(static fn(array $document): string => (string) $document['language'], $documents)));
    sort($languages, SORT_STRING);

    return $languages;
}

if (class_exists(DOMDocument::class, false)) {
    probe_fail('DOMDocument is available under php -n; the hybrid no-DOM runtime was not exercised.');
}

require __LANGUAGE_FTS_BOOTSTRAP_PATH__;

$storage = new Language_FTS_Playground_In_Memory_Storage();
$analyzer = new Language_FTS_Playground_Analyzer();
$indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
$searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

$post = (object) [
    'ID' => 185,
    'post_status' => 'publish',
    'post_type' => 'post',
    'post_password' => '',
    'post_title' => 'Hybrid runtime mixed language',
    'post_excerpt' => '',
    'post_content' => '<p>Searching pages stay visible in English.</p><p lang="pl">Partycja wyszukiwania pokazuje wynik.</p>',
    'language' => 'en',
];
$indexer->index_post($post);

$polish_post_ids = probe_post_ids($searcher->search('szukanie', 'pl'));
$english_post_ids = probe_post_ids($searcher->search('wyszukiwania', 'en'));
$documents = $storage->all_documents();
$languages = probe_document_languages($documents);
$payload = [
    'polish_post_ids' => $polish_post_ids,
    'english_post_ids' => $english_post_ids,
    'languages' => $languages,
    'document_count' => count($documents),
];

if ($polish_post_ids !== [185]) {
    probe_fail('Polish search did not return the lang-marked segment post.', $payload);
}
if ($english_post_ids !== []) {
    probe_fail('English search leaked the lang-marked Polish segment.', $payload);
}
if ($languages !== ['en', 'pl']) {
    probe_fail('Indexed documents did not include both en and pl partitions.', $payload);
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
PHP;
    $script = str_replace('__LANGUAGE_FTS_BOOTSTRAP_PATH__', var_export(__DIR__ . '/../src/bootstrap.php', true), $script);
    assert_true(file_put_contents($script_path, $script) !== false, 'Temporary WP_HTML_Processor probe script can be written.');

    try {
        $lines = [];
        $exit_code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($script_path) . ' 2>&1', $lines, $exit_code);

        return [
            'exit_code' => $exit_code,
            'output' => implode("\n", $lines),
        ];
    } finally {
        remove_language_fts_temp_file($script_path);
    }
}

/**
 * @param array<string,mixed> $fixture
 */
function write_language_fts_temp_eval_fixture(array $fixture): string
{
    $path = sys_get_temp_dir() . '/language-fts-eval-fixture-' . str_replace('.', '-', uniqid('', true)) . '.json';
    $json = json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    assert_true(is_string($json), 'Temporary evaluator fixture JSON is encoded.');
    file_put_contents($path, $json . "\n");

    return $path;
}

function remove_language_fts_temp_file(string $path): void
{
    if (is_file($path)) {
        unlink($path);
    }
}

/**
 * @param array<string,string> $overrides
 * @return array<string,string>
 */
function language_fts_import_options(array $overrides = []): array
{
    return array_merge(
        [
            'language' => 'en',
            'source_name' => 'Fixture lexical source',
            'source_url' => 'https://example.test/fixture-lexical-source',
            'license_name' => 'Fixture license',
            'attribution' => 'Fixture data for Language FTS Playground tests.',
            'pack_version' => 'fixture-2026-06-08',
            'pack_date' => '2026-06-08',
            'provenance' => 'fixture-lexical-import',
            'weight' => '0.62',
            'data_kind' => 'curated_seed',
        ],
        $overrides
    );
}

/**
 * @param array<string,string> $overrides
 * @return array<string,string>
 */
function language_fts_comprehensive_import_options(string $input_path, array $overrides = []): array
{
    return language_fts_import_options(array_merge(
        [
            'data_kind' => 'imported_comprehensive',
            'source_version' => '2026-06-fixture',
            'source_retrieved_at' => '2026-06-09',
            'source_artifact_name' => basename($input_path),
            'source_artifact_url' => 'https://example.test/' . basename($input_path),
            'source_artifact_sha256' => (string) hash_file('sha256', $input_path),
            'source_artifact_bytes' => (string) filesize($input_path),
            'license_id' => 'Fixture-1.0',
            'license_url' => 'https://example.test/license',
            'license_text_url' => 'https://example.test/license.txt',
            'license_text_file' => 'LICENSE.fixture.txt',
            'normalization_profile_version' => 'language-fts-playground-normalization-fixture-v1',
        ],
        $overrides
    ));
}

function assert_language_fts_term_rules_rejected(string $label, string $term_rules, string $expected_message): void
{
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\n",
        "# source\ttarget\tdirection\tweight\tprovenance\n",
        null,
        null,
        $term_rules
    );

    try {
        $repository = new Language_FTS_Playground_Lexical_Profile_Repository($root);
        $throwable = assert_throws(
            UnexpectedValueException::class,
            static fn(): array => $repository->profile('xx'),
            "Malformed term rule rows fail profile loading for {$label}."
        );
        assert_contains_text($expected_message, $throwable->getMessage(), "The malformed term rule reason is reported for {$label}.");
    } finally {
        remove_language_fts_temp_tree($root);
    }
}

function create_language_fts_temp_profile_tree_with_declared_resource(string $resource_key, string $resource_file): string
{
    $root = sys_get_temp_dir() . '/language-fts-profile-missing-resource-' . str_replace('.', '-', uniqid('', true));
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    assert_true(mkdir($language_dir, 0777, true), 'Temporary language profile directory is created.');

    $profile = [
        'id' => 'xx',
        'label' => 'Test',
        'resources' => [
            'stopwords' => 'stopwords.txt',
            'lexemes' => 'lexemes.tsv',
            'synonyms' => 'synonyms.tsv',
            $resource_key => $resource_file,
        ],
    ];

    file_put_contents(
        $language_dir . DIRECTORY_SEPARATOR . 'profile.php',
        "<?php\nreturn " . var_export($profile, true) . ";\n"
    );
    file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'stopwords.txt', "and\n");
    file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'lexemes.tsv', "# observed\tcanonical\tprovenance\n");
    file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'synonyms.tsv', "# source\ttarget\tdirection\tweight\tprovenance\n");

    return $root;
}

function assert_language_fts_declared_resource_missing(string $resource_key, string $resource_file): void
{
    $root = create_language_fts_temp_profile_tree_with_declared_resource($resource_key, $resource_file);

    try {
        $repository = new Language_FTS_Playground_Lexical_Profile_Repository($root);
        $throwable = assert_throws(
            RuntimeException::class,
            static fn(): array => $repository->profile('xx'),
            "Declared missing {$resource_key} resource fails profile loading."
        );
        assert_contains_text("Language profile resource {$resource_key} does not exist", $throwable->getMessage(), "The missing {$resource_key} resource reason is reported.");
        assert_contains_text($resource_file, $throwable->getMessage(), "The missing {$resource_key} file name is reported.");
    } finally {
        remove_language_fts_temp_tree($root);
    }
}

test_case('storage replacement supports multiple language partitions for one post', function (): void {
    $partitions = [
        [
            'language' => 'en',
            'title' => 'Shared post',
            'status' => 'publish',
            'document_length' => 3,
            'field_term_frequencies' => [
                'content' => ['search' => 2],
            ],
            'field_texts' => [
                'content' => 'search search page',
            ],
            'field_metadata' => [
                'content' => [
                    'language' => 'en',
                    'language_provenance' => 'post',
                ],
            ],
            'term_positions' => [
                'search' => [0, 1],
            ],
        ],
        [
            'language' => 'pl',
            'title' => 'Shared post',
            'status' => 'publish',
            'document_length' => 2,
            'field_term_frequencies' => [
                'content' => ['wyszukiw' => 1],
            ],
            'field_texts' => [
                'content' => 'wyszukiwania wynik',
            ],
            'field_metadata' => [
                'content' => [
                    'language' => 'pl',
                    'language_provenance' => 'html_lang',
                ],
            ],
            'term_positions' => [
                'wyszukiw' => [0],
            ],
        ],
    ];

    foreach ([new Language_FTS_Playground_Test_Storage(), new Language_FTS_Playground_In_Memory_Storage()] as $storage) {
        $storage->replace_document_partitions(77, $partitions);

        assert_same(2, count($storage->all_documents()), $storage::class . ' stores both language partitions.');
        assert_same(1, $storage->document_count('en'), $storage::class . ' counts the English partition.');
        assert_same(1, $storage->document_count('pl'), $storage::class . ' counts the Polish partition.');
        assert_same([77 => 3], $storage->fetch_document_lengths('en', [77]), $storage::class . ' fetches the English document length.');
        assert_same([77 => 2], $storage->fetch_document_lengths('pl', [77]), $storage::class . ' fetches the Polish document length.');
        assert_same(['content' => 'wyszukiwania wynik'], $storage->fetch_document_fields('pl', [77])[77] ?? null, $storage::class . ' fetches fields from the Polish partition.');
        assert_same(['content' => ['language' => 'pl', 'language_provenance' => 'html_lang']], $storage->fetch_document_field_metadata('pl', [77])[77] ?? null, $storage::class . ' fetches field metadata from the Polish partition.');
        assert_same(['content' => 2], $storage->fetch_postings('en', ['search'])['search'][77] ?? null, $storage::class . ' fetches English postings.');
        assert_same([0], $storage->fetch_positions('pl', ['wyszukiw'], [77])['wyszukiw'][77] ?? null, $storage::class . ' fetches Polish positions.');

        $storage->replace_document_partitions(77, [$partitions[0]]);

        assert_same(1, count($storage->all_documents()), $storage::class . ' removes stale partitions during replacement.');
        assert_same(0, $storage->document_count('pl'), $storage::class . ' removes the stale Polish document partition.');
        assert_same([], $storage->fetch_postings('pl', ['wyszukiw']), $storage::class . ' removes stale Polish postings.');
        assert_same([], $storage->fetch_document_field_metadata('pl', [77]), $storage::class . ' removes stale Polish field metadata.');

        $storage->delete_document(77);

        assert_same([], $storage->all_documents(), $storage::class . ' deletes all partitions for the post.');
    }
});

test_case('fuzzy candidate lookup applies the candidate limit after edit-distance filtering', function (): void {
    foreach ([new Language_FTS_Playground_Test_Storage(), new Language_FTS_Playground_In_Memory_Storage()] as $storage) {
        $analyzer = new Language_FTS_Playground_Analyzer();
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer, 1.2, 0.75, 4, 2, 1, 0.45);

        $indexer->index_post(fixture_post(611, 'en', 'Length-band noise A', '<p>aaaaa</p>'));
        $indexer->index_post(fixture_post(612, 'en', 'Length-band noise B', '<p>bbbbb</p>'));
        $indexer->index_post(fixture_post(613, 'en', 'Edit-distance target', '<p>zzzzr</p>'));

        $candidates = $storage->fetch_candidate_terms('en', 'zzzzq', 1, 2);
        assert_same(['zzzzr'], $candidates, $storage::class . ' caps fuzzy candidates after edit-distance filtering.');

        $results = $searcher->search('zzzzq~', 'en');
        assert_same([613], array_column($results, 'post_id'), $storage::class . ' fuzzy search reaches the one-edit target outside the raw length-band prefix.');

        $explain = $searcher->explain('zzzzq~', 'en');
        $fuzzy_expansion = $explain['partitions'][0]['fuzzy_expansions'][0] ?? [];
        assert_same('zzzzr', $fuzzy_expansion['candidate_term'] ?? null, $storage::class . ' explain reports the post-filtered fuzzy candidate.');
    }
});

test_case('wpdb storage install creates SQLite-compatible indexes and term length migration', function (): void {
    $wpdb = new Language_FTS_Playground_Test_WPDB('sqlite');
    $storage = new Language_FTS_Playground_Wpdb_Storage($wpdb);
    $storage->install();

    $sql = implode("\n", $wpdb->queries);
    assert_contains_text('term_length INTEGER NOT NULL DEFAULT 0', $sql, 'Fresh postings schema declares term_length.');
    assert_contains_text('ALTER TABLE wp_language_fts_postings ADD COLUMN term_length INTEGER NOT NULL DEFAULT 0', $sql, 'Existing postings tables receive term_length.');
    assert_contains_text("UPDATE wp_language_fts_postings SET term_length = LENGTH(term) WHERE term_length = 0 AND term <> ''", $sql, 'Existing postings rows backfill term_length portably.');

    foreach ([
        'CREATE INDEX lft_docs_lang_post ON wp_language_fts_documents (language, post_id)',
        'CREATE INDEX lft_docs_post ON wp_language_fts_documents (post_id)',
        'CREATE INDEX lft_post_lang_term_post ON wp_language_fts_postings (language, term, post_id)',
        'CREATE INDEX lft_post_lang_post ON wp_language_fts_postings (language, post_id)',
        'CREATE INDEX lft_post_post ON wp_language_fts_postings (post_id)',
        'CREATE INDEX lft_post_lang_len_term ON wp_language_fts_postings (language, term_length, term)',
    ] as $ddl) {
        assert_contains_text($ddl, $sql, "SQLite install creates {$ddl}.");
    }
    assert_not_contains_text('term(191)', $sql, 'SQLite indexes do not use MySQL prefix lengths.');
});

test_case('wpdb storage install creates MySQL prefix indexes for text columns', function (): void {
    $wpdb = new Language_FTS_Playground_Test_WPDB('mysql', [
        'wp_language_fts_postings' => [
            'language',
            'term',
            'term_length',
            'post_id',
            'field',
            'tf',
            'positions',
        ],
    ]);
    $storage = new Language_FTS_Playground_Wpdb_Storage($wpdb);
    $storage->install();

    $sql = implode("\n", $wpdb->queries);
    assert_contains_text('CREATE INDEX lft_docs_lang_post ON wp_language_fts_documents (language(16), post_id)', $sql, 'MySQL document language index uses a bounded prefix.');
    assert_contains_text('CREATE INDEX lft_post_lang_term_post ON wp_language_fts_postings (language(16), term(191), post_id)', $sql, 'MySQL posting term index uses bounded prefixes for TEXT columns.');
    assert_contains_text('CREATE INDEX lft_post_lang_len_term ON wp_language_fts_postings (language(16), term_length, term(191))', $sql, 'MySQL fuzzy candidate index uses term_length and a bounded term prefix.');
    assert_not_contains_text('ALTER TABLE wp_language_fts_postings ADD COLUMN term_length', $sql, 'Current MySQL schema does not repeat the term_length column migration.');
});

test_case('wpdb storage install skips indexes that already exist', function (): void {
    $existing_indexes = [
        'wp_language_fts_documents' => [
            'lft_docs_lang_post',
            'lft_docs_post',
        ],
        'wp_language_fts_postings' => [
            'lft_post_lang_term_post',
            'lft_post_lang_post',
            'lft_post_post',
            'lft_post_lang_len_term',
        ],
    ];
    $wpdb = new Language_FTS_Playground_Test_WPDB(
        'sqlite',
        [
            'wp_language_fts_postings' => [
                'language',
                'term',
                'term_length',
                'post_id',
                'field',
                'tf',
                'positions',
            ],
        ],
        $existing_indexes
    );
    $storage = new Language_FTS_Playground_Wpdb_Storage($wpdb);
    $storage->install();

    $index_queries = array_values(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_starts_with($sql, 'CREATE INDEX')
    ));
    assert_same([], $index_queries, 'Install does not recreate existing indexes.');
});

test_case('wpdb storage fuzzy candidate lookup uses indexed term lengths', function (): void {
    $wpdb = new Language_FTS_Playground_Test_WPDB('sqlite', [
        'wp_language_fts_postings' => [
            'language',
            'term',
            'term_length',
            'post_id',
            'field',
            'tf',
            'positions',
        ],
    ]);
    $storage = new Language_FTS_Playground_Wpdb_Storage($wpdb);
    $storage->fetch_candidate_terms('en', 'orchrd', 1, 10);

    $prepared = $wpdb->prepared[0]['query'] ?? '';
    assert_contains_text('term_length BETWEEN %d AND %d', $prepared, 'Fuzzy candidate SQL filters by indexed term_length.');
    assert_not_contains_text('LENGTH(term)', $prepared, 'Fuzzy candidate SQL avoids a per-row LENGTH(term) predicate.');
    assert_not_contains_text('LIMIT %d', $prepared, 'Fuzzy candidate SQL does not truncate the length band before edit-distance filtering.');
});

test_case('wpdb storage replacement deletes a post once before inserting partitions', function (): void {
    $wpdb = new class {
        public string $prefix = 'wp_';
        public string $last_error = '';
        /** @var string[] */
        public array $queries = [];
        /** @var array<int,array{table:string,data:array<string,mixed>,format:string[]}> */
        public array $inserts = [];

        public function prepare(string $query, mixed ...$args): string
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }

            foreach ($args as $arg) {
                $replacement = is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
                $query = preg_replace('/%[ds]/', $replacement, $query, 1) ?? $query;
            }

            return $query;
        }

        public function query(string $sql): int
        {
            $this->queries[] = $sql;

            return 1;
        }

        /**
         * @param array<string,mixed> $data
         * @param string[] $format
         */
        public function insert(string $table, array $data, array $format): int
        {
            $this->inserts[] = [
                'table' => $table,
                'data' => $data,
                'format' => $format,
            ];

            return 1;
        }
    };

    $storage = new Language_FTS_Playground_Wpdb_Storage($wpdb);
    $storage->replace_document_partitions(
        88,
        [
            [
                'language' => 'en',
                'title' => 'Shared SQL post',
                'status' => 'publish',
                'document_length' => 1,
                'field_term_frequencies' => [
                    'content' => ['search' => 1],
                ],
                'field_texts' => [
                    'content' => 'search',
                ],
                'field_metadata' => [
                    'content' => [
                        'language' => 'en',
                        'language_provenance' => 'post',
                    ],
                ],
                'term_positions' => [
                    'search' => [0],
                ],
            ],
            [
                'language' => 'pl',
                'title' => 'Shared SQL post',
                'status' => 'publish',
                'document_length' => 1,
                'field_term_frequencies' => [
                    'content' => ['wyszukiw' => 1],
                ],
                'field_texts' => [
                    'content' => 'wyszukiwania',
                ],
                'field_metadata' => [
                    'content' => [
                        'language' => 'pl',
                        'language_provenance' => 'html_lang',
                    ],
                ],
                'term_positions' => [
                    'wyszukiw' => [0],
                ],
            ],
        ]
    );

    $document_inserts = array_values(array_filter(
        $wpdb->inserts,
        static fn(array $insert): bool => $insert['table'] === 'wp_language_fts_documents'
    ));
    $posting_inserts = array_values(array_filter(
        $wpdb->inserts,
        static fn(array $insert): bool => $insert['table'] === 'wp_language_fts_postings'
    ));

    assert_same(2, count($wpdb->queries), 'Replacing partitions deletes document and posting rows once each.');
    assert_contains_text('post_id = 88', implode("\n", $wpdb->queries), 'Replacement deletes existing rows for the target post.');
    assert_same(['en', 'pl'], array_column(array_column($document_inserts, 'data'), 'language'), 'Both document partitions are inserted.');
    assert_same(
        ['content' => ['language' => 'pl', 'language_provenance' => 'html_lang']],
        json_decode((string) ($document_inserts[1]['data']['field_metadata'] ?? ''), true),
        'Document partitions persist field metadata JSON.'
    );
    assert_same(['en', 'pl'], array_column(array_column($posting_inserts, 'data'), 'language'), 'Both posting partitions are inserted.');
    assert_same([6, 8], array_column(array_column($posting_inserts, 'data'), 'term_length'), 'Posting inserts persist byte term lengths for fuzzy lookup.');
});

test_case('lexical resource root defaults to bundled resources and handles invalid filters safely', function (): void {
    reset_language_fts_plugin_runtime();
    $default_root = Language_FTS_Playground_Lexical_Profile_Repository::default_resource_root();

    assert_same($default_root, Language_FTS_Playground_Plugin::default_lexical_resource_root(), 'The plugin exposes the bundled lexical resource root.');
    assert_same($default_root, Language_FTS_Playground_Plugin::lexical_resource_root(), 'The effective lexical root defaults to bundled resources.');

    add_filter(
        'language_fts_playground_lexical_resource_root',
        static fn(): array => ['not-a-string-path'],
        10,
        1
    );
    assert_same($default_root, Language_FTS_Playground_Plugin::lexical_resource_root(), 'A non-string filter value is ignored safely.');

    reset_language_fts_plugin_runtime();
    add_filter(
        'language_fts_playground_lexical_resource_root',
        static fn(): string => 'https://example.test/lexical-packs',
        10,
        1
    );
    $throwable = assert_throws(
        InvalidArgumentException::class,
        static fn(): string => Language_FTS_Playground_Plugin::lexical_resource_root(),
        'URL-like lexical roots are rejected before any runtime download behavior is possible.'
    );
    assert_contains_text('local filesystem path', $throwable->getMessage(), 'The URL rejection explains that roots must be local paths.');
});

test_case('filter lexical root is used by plugin analyzer and admin validator', function (): void {
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\nalpha\talpha\tfixture\nbeta\tbeta\tfixture\n",
        "# source\ttarget\tdirection\tweight\tprovenance\nalpha\tbeta\tbidirectional\t0.77\tfixture-custom-root\n"
    );
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    write_language_fts_temp_pack_metadata($language_dir, [
        'source_name' => 'Fixture <script> source',
        'provenance' => 'fixture-filter-custom-root',
    ]);
    $normalized_root = Language_FTS_Playground_Lexical_Profile_Repository::normalize_resource_root($root);

    try {
        reset_language_fts_plugin_runtime();
        add_filter(
            'language_fts_playground_lexical_resource_root',
            static fn(string $current, string $default): string => $normalized_root . DIRECTORY_SEPARATOR,
            10,
            2
        );

        assert_same($normalized_root, Language_FTS_Playground_Plugin::lexical_resource_root(), 'The filter overrides the default lexical root and normalizes trailing slashes.');
        $analyzer = Language_FTS_Playground_Plugin::analyzer();
        assert_same(['xx'], $analyzer->enabled_languages(), 'The plugin analyzer is built from the filtered lexical root.');
        $query_terms = $analyzer->analyze_query('alpha', 'xx');
        $expansions = $analyzer->expand_query_synonyms($query_terms, 'xx');
        assert_same(['beta'], array_column($expansions['alpha'] ?? [], 'term'), 'A filtered custom pack changes analyzer synonym behavior without editing bundled resources.');

        ob_start();
        Language_FTS_Playground_Plugin::render_admin_page();
        $html = ob_get_clean();

        assert_contains_text('<code>' . esc_html($normalized_root) . '</code>', $html, 'Admin lexical status shows the filtered resource root.');
        assert_contains_text('Fixture &lt;script&gt; source', $html, 'Admin lexical status escapes custom pack source names.');
        assert_not_contains_text('<script>', $html, 'Admin lexical status does not emit raw custom pack source markup.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('lexical pack fingerprint changes when pack metadata changes', function (): void {
    $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\tfixture\n");
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    write_language_fts_temp_pack_metadata($language_dir, [
        'pack_version' => 'fixture-v1',
        'pack_date' => '2026-06-08',
        'provenance' => 'fixture-provenance-v1',
    ]);

    try {
        $first = (new Language_FTS_Playground_Lexical_Profile_Repository($root))->pack_fingerprint();
        write_language_fts_temp_pack_metadata($language_dir, [
            'pack_version' => 'fixture-v2',
            'pack_date' => '2026-06-09',
            'provenance' => 'fixture-provenance-v2',
        ]);
        $second = (new Language_FTS_Playground_Lexical_Profile_Repository($root))->pack_fingerprint();

        assert_true($first !== $second, 'Changing pack version/date/provenance changes the lightweight lexical fingerprint.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('lexical pack fingerprint changes when runtime resource content changes without metadata changes', function (): void {
    $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\tfixture\n");
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    $pack_file = $language_dir . DIRECTORY_SEPARATOR . 'pack.php';
    write_language_fts_temp_pack_metadata($language_dir, [
        'pack_version' => 'fixture-v1',
        'pack_date' => '2026-06-08',
        'provenance' => 'fixture-provenance-v1',
    ]);

    try {
        $first = (new Language_FTS_Playground_Lexical_Profile_Repository($root))->pack_fingerprint();
        $metadata_before = (string) file_get_contents($pack_file);
        file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'lexemes.tsv', "# observed\tcanonical\tprovenance\nalpha\talpha\tfixture\nbeta\tbeta\tfixture\n");
        $second = (new Language_FTS_Playground_Lexical_Profile_Repository($root))->pack_fingerprint();

        assert_same($metadata_before, (string) file_get_contents($pack_file), 'The fixture leaves pack.php metadata unchanged.');
        assert_true($first !== $second, 'Changing profile-declared TSV content changes the lexical fingerprint.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('ensure_schema marks rebuild required when lexical pack fingerprint changes', function (): void {
    $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\tfixture\n");
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    write_language_fts_temp_pack_metadata($language_dir, [
        'pack_version' => 'fixture-v1',
        'pack_date' => '2026-06-08',
        'provenance' => 'fixture-provenance-v1',
    ]);
    $normalized_root = Language_FTS_Playground_Lexical_Profile_Repository::normalize_resource_root($root);

    try {
        $storage = reset_language_fts_plugin_runtime();
        assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
        add_filter(
            'language_fts_playground_lexical_resource_root',
            static fn(): string => $normalized_root,
            10,
            1
        );
        $initial_fingerprint = Language_FTS_Playground_Plugin::lexical_pack_fingerprint();
        update_option('language_fts_playground_schema_version', LANGUAGE_FTS_PLAYGROUND_SCHEMA_VERSION);
        update_option('language_fts_playground_analyzer_version', LANGUAGE_FTS_PLAYGROUND_ANALYZER_VERSION);
        update_option('language_fts_playground_lexical_pack_fingerprint', $initial_fingerprint);
        update_option('language_fts_playground_rebuild_required', false);

        write_language_fts_temp_pack_metadata($language_dir, [
            'pack_version' => 'fixture-v2',
            'pack_date' => '2026-06-09',
            'provenance' => 'fixture-provenance-v2',
        ]);
        $next_fingerprint = Language_FTS_Playground_Plugin::lexical_pack_fingerprint();

        Language_FTS_Playground_Plugin::ensure_schema();
        $status = Language_FTS_Playground_Plugin::index_status();

        assert_same(1, $storage->install_count, 'A lexical fingerprint change still runs the idempotent schema installer.');
        assert_same($next_fingerprint, get_option('language_fts_playground_lexical_pack_fingerprint'), 'The changed lexical fingerprint is stored.');
        assert_same(true, get_option('language_fts_playground_rebuild_required'), 'A lexical fingerprint change marks the index for rebuild.');
        assert_same($normalized_root, $status['lexical_resource_root'] ?? null, 'Status records the resource root that triggered the rebuild check.');
        assert_contains_text('lexical resource packs changed', (string) ($status['last_status'] ?? ''), 'Status explains that lexical packs can require a rebuild.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('ensure_schema marks rebuild required when runtime resource content changes without metadata changes', function (): void {
    $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\tfixture\n");
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    $pack_file = $language_dir . DIRECTORY_SEPARATOR . 'pack.php';
    write_language_fts_temp_pack_metadata($language_dir, [
        'pack_version' => 'fixture-v1',
        'pack_date' => '2026-06-08',
        'provenance' => 'fixture-provenance-v1',
    ]);
    $normalized_root = Language_FTS_Playground_Lexical_Profile_Repository::normalize_resource_root($root);

    try {
        $storage = reset_language_fts_plugin_runtime();
        assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
        add_filter(
            'language_fts_playground_lexical_resource_root',
            static fn(): string => $normalized_root,
            10,
            1
        );
        $initial_fingerprint = Language_FTS_Playground_Plugin::lexical_pack_fingerprint();
        update_option('language_fts_playground_schema_version', LANGUAGE_FTS_PLAYGROUND_SCHEMA_VERSION);
        update_option('language_fts_playground_analyzer_version', LANGUAGE_FTS_PLAYGROUND_ANALYZER_VERSION);
        update_option('language_fts_playground_lexical_pack_fingerprint', $initial_fingerprint);
        update_option('language_fts_playground_rebuild_required', false);

        $metadata_before = (string) file_get_contents($pack_file);
        file_put_contents($language_dir . DIRECTORY_SEPARATOR . 'lexemes.tsv', "# observed\tcanonical\tprovenance\nalpha\talpha\tfixture\nbeta\tbeta\tfixture\n");
        $next_fingerprint = Language_FTS_Playground_Plugin::lexical_pack_fingerprint();

        Language_FTS_Playground_Plugin::ensure_schema();

        assert_same($metadata_before, (string) file_get_contents($pack_file), 'The fixture leaves pack.php metadata unchanged.');
        assert_true($initial_fingerprint !== $next_fingerprint, 'A changed TSV produces a new stored lexical fingerprint value.');
        assert_same(1, $storage->install_count, 'A runtime resource content change still runs the idempotent schema installer.');
        assert_same($next_fingerprint, get_option('language_fts_playground_lexical_pack_fingerprint'), 'The changed content fingerprint is stored.');
        assert_same(true, get_option('language_fts_playground_rebuild_required'), 'A runtime resource content change marks the index for rebuild.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('loads resource-backed lexical profiles for stopwords, lexemes, folds, and synonyms', function (): void {
    $repository = new Language_FTS_Playground_Lexical_Profile_Repository();
    $profile = $repository->profile('pl');
    $analyzer = new Language_FTS_Playground_Analyzer($repository);

    assert_same(['en', 'pl', 'de'], $repository->language_ids(), 'Lexical profile order comes from resource profile declarations.');
    assert_true(isset($profile['stopwords']['oraz']), 'Polish stopwords are loaded from a resource file.');
    assert_same(['szukac'], $profile['lexemes']['szukaj'] ?? [], 'Polish search commands map to a canonical resource key.');
    assert_same(['wyszukiwac'], $profile['lexemes']['wyszukiwania'] ?? [], 'Polish inflected search nouns map to a canonical resource key.');
    assert_same(['odnajdywac'], $profile['lexemes']['odnajdywanie'] ?? [], 'Polish related search nouns map to a canonical resource key.');
    assert_same('lodz', $analyzer->normalize_term('Łódź', 'pl'), 'Polish character folds are loaded from the profile.');
    assert_same('fuehrung', $analyzer->normalize_term('Führung', 'de'), 'German character folds are loaded from the profile.');
    assert_true(in_array('wyszukiwac', array_column($profile['synonyms']['szukac'] ?? [], 'term'), true), 'Polish query expansion is keyed by canonical resource terms.');
    assert_true(in_array('odnajdywac', array_column($profile['synonyms']['szukac'] ?? [], 'term'), true), 'Polish synset expansions are included in the profile expansion map.');
});

test_case('ranks automatic query languages from bundled profile evidence', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();

    $ranked = $analyzer->rank_query_languages('szukaj');
    assert_same('pl', $ranked[0]['language'] ?? null, 'A no-diacritic Polish command ranks Polish from lexical resources.');
    assert_same([], $ranked[0]['reasons']['language_signals'] ?? [], 'The no-diacritic Polish command does not rely on profile regex signals.');
    assert_true(in_array('szukaj', $ranked[0]['reasons']['lexeme_forms'] ?? [], true), 'The Polish command form is detected as a profile lexeme form.');
    assert_true(in_array('szukac', $ranked[0]['reasons']['canonical_keys'] ?? [], true), 'The Polish command contributes its canonical resource key.');
    assert_true(in_array('szukac', $ranked[0]['reasons']['synonym_sources'] ?? [], true), 'The Polish canonical key is recognized as a synset/synonym source.');

    $ranked = $analyzer->rank_query_languages('szukanie');
    assert_same('pl', $ranked[0]['language'] ?? null, 'A no-diacritic Polish noun ranks Polish from lexeme/synset evidence.');
    assert_true(in_array('szukanie', $ranked[0]['reasons']['lexeme_forms'] ?? [], true), 'The Polish noun form is detected as a profile lexeme form.');

    $ranked = $analyzer->rank_query_languages('Łódź');
    assert_same('pl', $ranked[0]['language'] ?? null, 'A Polish diacritic query ranks Polish from language_signals.');
    assert_true(($ranked[0]['reasons']['language_signals'] ?? []) !== [], 'The Polish diacritic query records the matching signal regex.');

    $ranked = $analyzer->rank_query_languages('Führung');
    assert_same('de', $ranked[0]['language'] ?? null, 'A German diacritic query ranks German from language_signals.');
    assert_true(($ranked[0]['reasons']['language_signals'] ?? []) !== [], 'The German diacritic query records the matching signal regex.');

    $ranked = $analyzer->rank_query_languages('searching');
    assert_same('en', $ranked[0]['language'] ?? null, 'An English resource form ranks English through profile lexemes.');
    assert_true(in_array('searching', $ranked[0]['reasons']['lexeme_forms'] ?? [], true), 'The English observed form is detected as a profile lexeme form.');
    assert_true(in_array('search', $ranked[0]['reasons']['canonical_keys'] ?? [], true), 'The English observed form contributes its canonical resource key.');

    $ranked = $analyzer->rank_query_languages('the searching');
    assert_same('en', $ranked[0]['language'] ?? null, 'Stopword evidence can contribute to an otherwise lexical English query.');
    assert_true(in_array('the', $ranked[0]['reasons']['stopwords'] ?? [], true), 'Stopwords are exposed as language evidence without becoming index terms.');
});

test_case('rejects malformed lexical resource rows deliberately', function (): void {
    $root = create_language_fts_temp_profile_tree("valid\tcanonical\nmalformed-row\n");

    try {
        $repository = new Language_FTS_Playground_Lexical_Profile_Repository($root);
        assert_same(['xx'], $repository->language_ids(), 'Manifest-only profile discovery does not parse malformed resources.');
        assert_same('Test', $repository->language_label('xx'), 'Profile labels load without parsing malformed resources.');
        $throwable = assert_throws(
            UnexpectedValueException::class,
            static fn(): array => $repository->profile('xx'),
            'Malformed lexeme rows fail profile loading.'
        );
        assert_contains_text('lexeme rows must have 2 or 3 tab-separated columns', $throwable->getMessage(), 'The malformed row reason is reported.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('tokenizer profile contract defaults and rejects unsupported declarations', function (): void {
    $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\tfixture\n");

    try {
        $repository = new Language_FTS_Playground_Lexical_Profile_Repository($root);
        $profile = $repository->profile('xx');
        assert_same(Language_FTS_Playground_Unicode_Words_Tokenizer::default_contract(), $profile['tokenizer'] ?? null, 'Profiles without tokenizer declarations default to unicode_words_v1.');
        assert_same(true, (new Language_FTS_Playground_Analyzer($repository))->tokenizer_supports_fuzzy('xx'), 'The default tokenizer keeps fuzzy support enabled.');
    } finally {
        remove_language_fts_temp_tree($root);
    }

    $unsupported_root = create_language_fts_temp_profile_set([
        'xx' => [
            'tokenizer' => [
                'id' => 'dictionary_segmenter_v1',
                'type' => 'dictionary_segmenter',
                'resources' => [],
                'capabilities' => [
                    'emits_offsets' => true,
                    'emits_positions' => true,
                    'supports_fuzzy' => false,
                    'supports_overlaps' => false,
                ],
            ],
        ],
    ]);
    write_language_fts_temp_pack_metadata($unsupported_root . DIRECTORY_SEPARATOR . 'xx');

    try {
        $repository = new Language_FTS_Playground_Lexical_Profile_Repository($unsupported_root);
        $throwable = assert_throws(
            UnexpectedValueException::class,
            static fn(): array => $repository->profile('xx'),
            'Unsupported tokenizer declarations fail profile loading.'
        );
        assert_contains_text('supported unicode_words_v1/unicode_words', $throwable->getMessage(), 'Unsupported tokenizer failures explain the supported baseline.');

        $report = (new Language_FTS_Playground_Lexical_Pack_Validator($unsupported_root))->validate_all();
        $warnings = implode("\n", language_fts_pack_status_by_id($report)['xx']['warnings'] ?? []);
        assert_same(false, $report['valid'], 'Unsupported tokenizer declarations fail validator checks.');
        assert_contains_text('supported unicode_words_v1/unicode_words', $warnings, 'Validator reports unsupported tokenizer declarations.');
    } finally {
        remove_language_fts_temp_tree($unsupported_root);
    }
});

test_case('tokenizer profile contract validates declaration shape', function (): void {
    $root = create_language_fts_temp_profile_set([
        'xx' => [
            'tokenizer' => [
                'id' => 'unicode_words_v1',
                'type' => 'unicode_words',
                'resources' => [],
                'capabilities' => [
                    'emits_offsets' => true,
                    'emits_positions' => true,
                    'supports_fuzzy' => 'yes',
                    'supports_overlaps' => false,
                ],
            ],
        ],
    ]);
    write_language_fts_temp_pack_metadata($root . DIRECTORY_SEPARATOR . 'xx');

    try {
        $repository = new Language_FTS_Playground_Lexical_Profile_Repository($root);
        $throwable = assert_throws(
            UnexpectedValueException::class,
            static fn(): array => $repository->profile('xx'),
            'Malformed tokenizer capabilities fail profile loading.'
        );
        assert_contains_text('capability supports_fuzzy must be a boolean', $throwable->getMessage(), 'Malformed tokenizer capability failures name the field.');

        $report = (new Language_FTS_Playground_Lexical_Pack_Validator($root))->validate_all();
        $warnings = implode("\n", language_fts_pack_status_by_id($report)['xx']['warnings'] ?? []);
        assert_same(false, $report['valid'], 'Malformed tokenizer capabilities fail validator checks.');
        assert_contains_text('capability supports_fuzzy must be a boolean', $warnings, 'Validator reports malformed tokenizer capabilities.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('static guard expects production term rule resource support', function (): void {
    $repository_source = file_get_contents(__DIR__ . '/../src/LexicalProfileRepository.php');
    $analyzer_source = file_get_contents(__DIR__ . '/../src/Analyzer.php');
    assert_true(is_string($repository_source) && is_string($analyzer_source), 'Production sources are readable.');

    $source = $repository_source . "\n" . $analyzer_source;
    $missing = [];
    foreach (['term_rules', 'protected_terms'] as $resource_key) {
        if (!str_contains($source, $resource_key)) {
            $missing[] = $resource_key;
        }
    }

    assert_same(
        [],
        $missing,
        'Production should expose resource-backed term_rules/protected_terms support instead of ignoring those profile resources.'
    );
});

test_case('bundled morphology profiles declare term rule resources', function (): void {
    $root = Language_FTS_Playground_Lexical_Profile_Repository::default_resource_root();
    $missing = [];

    foreach (['en', 'pl', 'de'] as $language) {
        $profile_file = $root . DIRECTORY_SEPARATOR . $language . DIRECTORY_SEPARATOR . 'profile.php';
        $profile = require $profile_file;
        assert_true(is_array($profile), "Bundled {$language} profile returns an array.");
        $resources = $profile['resources'] ?? [];
        assert_true(is_array($resources), "Bundled {$language} profile resources are an array.");

        if (($resources['term_rules'] ?? null) !== 'term_rules.tsv') {
            $missing[$language] = $resources['term_rules'] ?? null;
        }
    }

    assert_same(
        [],
        $missing,
        'Bundled en/pl/de profiles should declare term_rules.tsv for morphology migration.'
    );
});

test_case('bundled profiles declare the unicode words tokenizer baseline', function (): void {
    $root = Language_FTS_Playground_Lexical_Profile_Repository::default_resource_root();
    $expected = Language_FTS_Playground_Unicode_Words_Tokenizer::default_contract();

    foreach (['en', 'pl', 'de'] as $language) {
        $profile_file = $root . DIRECTORY_SEPARATOR . $language . DIRECTORY_SEPARATOR . 'profile.php';
        $profile = require $profile_file;
        assert_true(is_array($profile), "Bundled {$language} profile returns an array.");
        assert_same($expected, $profile['tokenizer'] ?? null, "Bundled {$language} profile declares the default tokenizer contract.");

        $repository_profile = (new Language_FTS_Playground_Lexical_Profile_Repository())->profile($language);
        assert_same($expected, $repository_profile['tokenizer'] ?? null, "Bundled {$language} runtime profile preserves the tokenizer contract.");
    }

    $report = (new Language_FTS_Playground_Lexical_Pack_Validator())->validate_all();
    $by_id = language_fts_pack_status_by_id($report);
    foreach (['en', 'pl', 'de'] as $language) {
        assert_same($expected['id'], $by_id[$language]['tokenizer']['id'] ?? null, "Validator reports {$language} tokenizer id.");
        assert_same($expected['capabilities'], $by_id[$language]['tokenizer']['capabilities'] ?? null, "Validator reports {$language} tokenizer capabilities.");
    }
});

test_case('bundled morphology pack metadata lists term rule resources', function (): void {
    $repository = new Language_FTS_Playground_Lexical_Profile_Repository();
    $missing = [];

    foreach (['en', 'pl', 'de'] as $language) {
        $metadata = $repository->pack_metadata($language);
        if (!in_array('term_rules.tsv', $metadata['files'], true)) {
            $missing[] = $language;
        }
    }

    assert_same(
        [],
        $missing,
        'Bundled en/pl/de pack metadata should list term_rules.tsv for morphology migration.'
    );
});

test_case('analyzer morphology has migrated out of concrete language branches', function (): void {
    $source = file_get_contents(__DIR__ . '/../src/Analyzer.php');
    assert_true(is_string($source), 'Analyzer source can be read.');

    $present = [];
    foreach (
        [
            'english_stem_keys',
            'polish_stem_keys',
            'german_stem_keys',
            "\$language === 'en'",
            "\$language === 'pl'",
            "\$language === 'de'",
        ] as $forbidden
    ) {
        if (str_contains($source, $forbidden)) {
            $present[] = $forbidden;
        }
    }

    assert_same(
        [],
        $present,
        'Analyzer.php should no longer contain concrete en/pl/de morphology helpers or language branches.'
    );
});

test_case('term_rules resource adds configured term keys for indexing and search', function (): void {
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\n",
        "# source\ttarget\tdirection\tweight\tprovenance\n",
        null,
        null,
        "# id\tmin_term_length\tpattern\tstrip_prefix\tstrip_suffix\tappend\tmin_key_length\tflags\talternate_pattern\talternate_replacement\tprovenance\n" .
        "glimmer-ed\t5\t/ed$/u\t\ted\t\t3\trequire_vowel\t\t\tfixture-term-rules\n" .
        "glimmer-ing\t5\t/ing$/u\t\ting\t\t3\trequire_vowel\t\t\tfixture-term-rules\n"
    );

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        $indexer->index_post(fixture_post(501, 'xx', 'Term rule target', '<p>glimmering</p>'));

        $issues = [];
        $shared_terms = $analyzer->analyze_text('glimmering glimmered', 'xx');
        $shared_counts = array_count_values($shared_terms);
        if (($shared_counts['glimmer'] ?? 0) !== 2) {
            $issues[] = 'glimmering and glimmered should each add the shared glimmer key. Terms: ' . var_export($shared_terms, true);
        }

        $glimmer_results = $searcher->search('glimmer', 'xx');
        if (array_column($glimmer_results, 'post_id') !== [501]) {
            $issues[] = 'search("glimmer", "xx") should find a document containing only glimmering. Results: ' . var_export($glimmer_results, true);
        }

        $glimmered_results = $searcher->search('glimmered', 'xx');
        if (array_column($glimmered_results, 'post_id') !== [501]) {
            $issues[] = 'search("glimmered", "xx") should share the glimmer key with indexed glimmering. Results: ' . var_export($glimmered_results, true);
        }

        assert_same([], $issues, 'term_rules.tsv should add configured term keys during indexing and query analysis.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('term_rules resource contributes conservative automatic routing evidence', function (): void {
    $root = create_language_fts_temp_profile_set([
        'xx' => [
            'order' => 10,
            'term_rules' => "# id\tmin_term_length\tpattern\tstrip_prefix\tstrip_suffix\tappend\tmin_key_length\tflags\talternate_pattern\talternate_replacement\tprovenance\n" .
                "drop-ing\t5\t/ing$/u\t\ting\t\t3\trequire_vowel\t\t\tfixture-term-rules\n" .
                "drop-ed\t5\t/ed$/u\t\ted\t\t3\trequire_vowel\t\t\tfixture-term-rules\n",
        ],
        'yy' => [
            'order' => 20,
        ],
    ]);

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        $indexer->index_post(fixture_post(506, 'xx', 'Term-rule routed target', '<p>glimmer shimmer</p>'));

        $ranked = $analyzer->rank_query_languages('glimmering shimmered');
        assert_same('xx', $ranked[0]['language'] ?? null, 'Term-rule generated keys rank their resource-backed fake language.');
        assert_true(in_array('glimmering=>glimmer', $ranked[0]['reasons']['term_rule_keys'] ?? [], true), 'Routing records the generated glimmer term-rule key.');
        assert_true(in_array('shimmered=>shimmer', $ranked[0]['reasons']['term_rule_keys'] ?? [], true), 'Routing records the generated shimmer term-rule key.');

        $explain = $searcher->explain('glimmering shimmered', 'auto');
        assert_same('auto_confident_profile_evidence', $explain['language_routing']['strategy'] ?? null, 'Multiple term-rule keys can create conservative confident routing.');
        assert_same(['xx'], $explain['language_routing']['selected_partitions'] ?? null, 'Term-rule routing selects the resource-backed language.');
        assert_same([506], array_column($explain['results'] ?? [], 'post_id'), 'The routed term-rule query reaches the indexed base-key document.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('too-short term-rule keys do not create confident automatic routing', function (): void {
    $root = create_language_fts_temp_profile_set([
        'xx' => [
            'order' => 10,
            'term_rules' => "# id\tmin_term_length\tpattern\tstrip_prefix\tstrip_suffix\tappend\tmin_key_length\tflags\talternate_pattern\talternate_replacement\tprovenance\n" .
                "drop-ing-short\t4\t/ing$/u\t\ting\t\t1\t\t\t\tfixture-term-rules\n",
        ],
        'yy' => [
            'order' => 20,
        ],
    ]);

    try {
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));

        assert_same([], $analyzer->rank_query_languages('aing'), 'A generated one-character term-rule key is ignored for automatic routing confidence.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('term_rules resource emits configured alternate replacement keys', function (): void {
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\n",
        "# source\ttarget\tdirection\tweight\tprovenance\n",
        null,
        null,
        "# id\tmin_term_length\tpattern\tstrip_prefix\tstrip_suffix\tappend\tmin_key_length\tflags\talternate_pattern\talternate_replacement\tprovenance\n" .
        "folded-umlaut-e\t6\t/^[a-z]+e$/u\t\te\t\t4\t\t/aeu/u\tau\tfixture-alternate-rule\n"
    );

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        $indexer->index_post(fixture_post(504, 'xx', 'Alternate rule target', '<p>raeume</p>'));

        $terms = $analyzer->analyze_text('raeume', 'xx');
        assert_true(in_array('raeum', $terms, true), 'A term rule keeps its normal strip/append key when an alternate is configured.');
        assert_true(in_array('raum', $terms, true), 'A term rule emits the regex-replaced alternate key.');
        assert_same([504], array_column($searcher->search('raum', 'xx'), 'post_id'), 'The alternate rule key is indexed and queryable.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('term_rules resource can require y as an ASCII vowel guard', function (): void {
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\n",
        "# source\ttarget\tdirection\tweight\tprovenance\n",
        null,
        null,
        "# id\tmin_term_length\tpattern\tstrip_prefix\tstrip_suffix\tappend\tmin_key_length\tflags\talternate_pattern\talternate_replacement\tprovenance\n" .
        "drop-ing-with-y\t6\t/^[a-z]+ing$/u\t\ting\t\t3\trequire_vowel_or_y\t\t\tfixture-y-vowel-rule\n"
    );

    try {
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));

        assert_true(in_array('try', $analyzer->analyze_text('trying', 'xx'), true), 'require_vowel_or_y accepts y-only stems.');
        assert_true(in_array('cry', $analyzer->analyze_text('crying', 'xx'), true), 'require_vowel_or_y accepts another y-only stem.');
        assert_same(['brring'], $analyzer->analyze_text('brring', 'xx'), 'require_vowel_or_y still rejects stems without a vowel or y.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('protected_terms blocks broad term rules while preserving lexeme keys', function (): void {
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\nanalytics\tmetric\tfixture-lexeme\n",
        "# source\ttarget\tdirection\tweight\tprovenance\n",
        null,
        null,
        "# id\tmin_term_length\tpattern\tstrip_prefix\tstrip_suffix\tappend\tmin_key_length\tflags\talternate_pattern\talternate_replacement\tprovenance\n" .
        "drop-final-s\t2\t/s$/u\t\ts\t\t2\trequire_vowel\t\t\tfixture-broad-rule\n",
        "analytics\n"
    );

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        $indexer->index_post(fixture_post(502, 'xx', 'Protected term target', '<p>analytics</p>'));
        $indexer->index_post(fixture_post(503, 'xx', 'Broad rule target', '<p>signals</p>'));

        $issues = [];
        $analytics_terms = $analyzer->analyze_text('analytics', 'xx');
        if (!in_array('metric', $analytics_terms, true)) {
            $issues[] = 'Protected term analytics should still receive its lexeme key metric. Terms: ' . var_export($analytics_terms, true);
        }
        if (in_array('analytic', $analytics_terms, true)) {
            $issues[] = 'Protected term analytics should not receive the broad drop-final-s key analytic. Terms: ' . var_export($analytics_terms, true);
        }

        $signals_terms = $analyzer->analyze_text('signals', 'xx');
        if (!in_array('signal', $signals_terms, true)) {
            $issues[] = 'Unprotected term signals should receive the broad drop-final-s key signal. Terms: ' . var_export($signals_terms, true);
        }

        $metric_results = $searcher->search('metric', 'xx');
        if (array_column($metric_results, 'post_id') !== [502]) {
            $issues[] = 'Lexeme search for metric should still find the protected analytics document. Results: ' . var_export($metric_results, true);
        }

        $analytic_results = $searcher->search('analytic', 'xx');
        if ($analytic_results !== []) {
            $issues[] = 'Broad-rule search for analytic should not find protected analytics. Results: ' . var_export($analytic_results, true);
        }

        $signal_results = $searcher->search('signal', 'xx');
        if (array_column($signal_results, 'post_id') !== [503]) {
            $issues[] = 'Broad-rule search for signal should find the unprotected signals document. Results: ' . var_export($signal_results, true);
        }

        assert_same([], $issues, 'protected_terms.txt should suppress broad rule keys without disabling lexeme mappings.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('rejects legacy scoped 5-column term rule rows', function (): void {
    assert_language_fts_term_rules_rejected(
        'legacy scoped row',
        "# id\tpattern\treplacement\tflags\tprovenance\n" .
        "legacy-scope\t/^(.+)s$/u\t\$1\tindex,query\tfixture\n",
        'term rule rows must have exactly 11 tab-separated columns'
    );
});

test_case('rejects malformed term rule rows with duplicate rule id', function (): void {
    assert_language_fts_term_rules_rejected(
        'duplicate rule id',
        "# id\tmin_term_length\tpattern\tstrip_prefix\tstrip_suffix\tappend\tmin_key_length\tflags\talternate_pattern\talternate_replacement\tprovenance\n" .
        "duplicate\t5\t/ing$/u\t\ting\t\t3\trequire_vowel\t\t\tfixture\n" .
        "duplicate\t5\t/ed$/u\t\ted\t\t3\trequire_vowel\t\t\tfixture\n",
        'duplicate term rule id'
    );
});

test_case('rejects malformed term rule rows with unknown flag', function (): void {
    assert_language_fts_term_rules_rejected(
        'unknown flag',
        "# id\tmin_term_length\tpattern\tstrip_prefix\tstrip_suffix\tappend\tmin_key_length\tflags\talternate_pattern\talternate_replacement\tprovenance\n" .
        "unknown-flag\t5\t/ing$/u\t\ting\t\t3\texplode\t\t\tfixture\n",
        'term rule flag must be trim_doubled_final_consonant, require_vowel, require_vowel_or_y, append_e_if_cvc, or stop_after_match'
    );
});

test_case('rejects malformed term rule rows with invalid regex', function (): void {
    assert_language_fts_term_rules_rejected(
        'invalid regex',
        "# id\tmin_term_length\tpattern\tstrip_prefix\tstrip_suffix\tappend\tmin_key_length\tflags\talternate_pattern\talternate_replacement\tprovenance\n" .
        "invalid-regex\t5\t/(?P<broken/u\t\ting\t\t3\trequire_vowel\t\t\tfixture\n",
        'term rule regex must be valid'
    );
});

test_case('rejects malformed term rule rows with invalid alternate regex', function (): void {
    assert_language_fts_term_rules_rejected(
        'invalid alternate regex',
        "# id\tmin_term_length\tpattern\tstrip_prefix\tstrip_suffix\tappend\tmin_key_length\tflags\talternate_pattern\talternate_replacement\tprovenance\n" .
        "invalid-alternate\t5\t/ing$/u\t\ting\t\t3\t\t/(?P<broken/u\tfixed\tfixture\n",
        'term rule alternate regex must be valid'
    );
});

test_case('rejects malformed term rule rows with non-normalized literal output fields', function (): void {
    assert_language_fts_term_rules_rejected(
        'non-normalized append literal',
        "# id\tmin_term_length\tpattern\tstrip_prefix\tstrip_suffix\tappend\tmin_key_length\tflags\talternate_pattern\talternate_replacement\tprovenance\n" .
        "bad-append\t5\t/ing$/u\t\ting\tE\t3\t\t\t\tfixture\n",
        'term rule append must be normalized lowercase resource tokens'
    );

    assert_language_fts_term_rules_rejected(
        'non-normalized alternate replacement literal',
        "# id\tmin_term_length\tpattern\tstrip_prefix\tstrip_suffix\tappend\tmin_key_length\tflags\talternate_pattern\talternate_replacement\tprovenance\n" .
        "bad-alternate\t5\t/^.+z$/u\t\tz\t\t3\t\t/e/u\tE\tfixture\n",
        'term rule alternate_replacement must be normalized lowercase resource tokens'
    );
});

test_case('declared missing term_rules resource fails profile loading', function (): void {
    assert_language_fts_declared_resource_missing('term_rules', 'missing-term-rules.tsv');
});

test_case('declared missing protected_terms resource fails profile loading', function (): void {
    assert_language_fts_declared_resource_missing('protected_terms', 'missing-protected-terms.txt');
});

test_case('loads synset resource rows into keyed query expansions', function (): void {
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\nalpha\talpha\ntwo\tbeta\nthree\tgamma\n",
        "# source\ttarget\tdirection\tweight\tprovenance\n",
        "# concept_id\tweight\tprovenance\tterms\nconcept.search\t0.62\ttest-concept-pack\talpha beta gamma\n"
    );

    try {
        $repository = new Language_FTS_Playground_Lexical_Profile_Repository($root);
        $profile = $repository->profile('xx');

        assert_same(['beta', 'gamma'], array_column($profile['synonyms']['alpha'] ?? [], 'term'), 'One concept row expands one term to every other concept term.');
        assert_same(['alpha', 'gamma'], array_column($profile['synonyms']['beta'] ?? [], 'term'), 'Synset expansion is keyed for each concept term.');
        assert_same(0.62, $profile['synonyms']['alpha'][0]['weight'] ?? null, 'Synset expansion carries its configured weight.');
        assert_same('test-concept-pack', $profile['synonyms']['alpha'][0]['provenance'] ?? null, 'Synset expansion carries provenance.');
        assert_same('synset', $profile['synonyms']['alpha'][0]['direction'] ?? null, 'Synset expansion records concept provenance in the direction field.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('single synset row expands many terms without pairwise synonym rows', function (): void {
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\nlookup\tlookup\nfind\tfind\nlocate\tlocate\nsearch\tsearch\n",
        "# source\ttarget\tdirection\tweight\tprovenance\n",
        "# concept_id\tweight\tprovenance\tterms\nconcept.lookup\t0.5\ttest-concept-pack\tlookup find locate search\n"
    );

    try {
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $query_terms = $analyzer->analyze_query('lookup', 'xx');
        $expansions = $analyzer->expand_query_synonyms($query_terms, 'xx');

        assert_same(['lookup'], $query_terms, 'The query remains a single canonical key.');
        assert_same(['find', 'locate', 'search'], array_column($expansions['lookup'] ?? [], 'term'), 'The synset row creates all non-self query expansions.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('rejects malformed synset resource rows deliberately', function (): void {
    $cases = [
        'wrong columns' => [
            "# concept_id\tweight\tprovenance\tterms\nbroken\t0.5\tmissing-terms\n",
            'synset rows must have exactly 4 tab-separated columns',
        ],
        'invalid weight' => [
            "# concept_id\tweight\tprovenance\tterms\nbroken\t1.5\ttest\talpha beta\n",
            'synset weight must be greater than 0 and no more than 1',
        ],
        'missing terms' => [
            "# concept_id\tweight\tprovenance\tterms\nbroken\t0.5\ttest\t\n",
            'synset terms must be non-empty',
        ],
        'broken whitespace' => [
            "# concept_id\tweight\tprovenance\tterms\nbroken\t0.5\ttest\talpha  beta\n",
            'synset terms must be separated by single spaces',
        ],
        'non-normalized term' => [
            "# concept_id\tweight\tprovenance\tterms\nbroken\t0.5\ttest\tAlpha beta\n",
            'synset terms must be normalized lowercase resource tokens',
        ],
    ];

    foreach ($cases as $label => [$synsets, $expected_message]) {
        $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\n", "# source\ttarget\tdirection\tweight\tprovenance\n", $synsets);

        try {
            $repository = new Language_FTS_Playground_Lexical_Profile_Repository($root);
            $throwable = assert_throws(
                UnexpectedValueException::class,
                static fn(): array => $repository->profile('xx'),
                "Malformed synset rows fail profile loading for {$label}."
            );
            assert_contains_text($expected_message, $throwable->getMessage(), "The malformed synset reason is reported for {$label}.");
        } finally {
            remove_language_fts_temp_tree($root);
        }
    }
});

test_case('rejects duplicate synset concept IDs', function (): void {
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\nalpha\talpha\nbeta\tbeta\n",
        "# source\ttarget\tdirection\tweight\tprovenance\n",
        "# concept_id\tweight\tprovenance\tterms\nconcept.duplicate\t0.5\ttest\talpha beta\nconcept.duplicate\t0.4\ttest\talpha beta\n"
    );

    try {
        $repository = new Language_FTS_Playground_Lexical_Profile_Repository($root);
        $throwable = assert_throws(
            UnexpectedValueException::class,
            static fn(): array => $repository->profile('xx'),
            'Duplicate synset concept IDs fail profile loading.'
        );
        assert_contains_text('duplicate synset concept id', $throwable->getMessage(), 'The duplicate concept id reason is reported.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('runtime profile loading rejects oversized synsets before expansion materialization', function (): void {
    $terms = language_fts_numbered_terms('term', Language_FTS_Playground_Lexical_Profile_Repository::DEFAULT_MAX_SYNSET_SIZE + 1);
    $lexeme_rows = "# observed\tcanonical\tprovenance\n";
    foreach ($terms as $term) {
        $lexeme_rows .= $term . "\t" . $term . "\tfixture\n";
    }
    $root = create_language_fts_temp_profile_tree(
        $lexeme_rows,
        "# source\ttarget\tdirection\tweight\tprovenance\n",
        "# concept_id\tweight\tprovenance\tterms\nconcept.too-wide\t0.5\tfixture\t" . implode(' ', $terms) . "\n"
    );

    try {
        $repository = new Language_FTS_Playground_Lexical_Profile_Repository($root);
        $throwable = assert_throws(
            UnexpectedValueException::class,
            static fn(): array => $repository->profile('xx'),
            'Oversized synsets fail closed during runtime profile loading.'
        );
        assert_contains_text('max synset size ' . Language_FTS_Playground_Lexical_Profile_Repository::DEFAULT_MAX_SYNSET_SIZE, $throwable->getMessage(), 'The synset hard cap is visible in the runtime error.');
        assert_contains_text('concept.too-wide', $throwable->getMessage(), 'The rejected synset concept is named in the runtime error.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('runtime profile loading rejects per-term synonym expansion fanout', function (): void {
    $target_terms = language_fts_numbered_terms('target', Language_FTS_Playground_Lexical_Profile_Repository::DEFAULT_MAX_EXPANSIONS_PER_TERM + 1);
    $synonyms = "# source\ttarget\tdirection\tweight\tprovenance\n";
    foreach ($target_terms as $target) {
        $synonyms .= "alpha\t{$target}\tquery_to_index\t0.6\tfixture-fanout\n";
    }
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\nalpha\talpha\tfixture\n",
        $synonyms
    );

    try {
        $repository = new Language_FTS_Playground_Lexical_Profile_Repository($root);
        $throwable = assert_throws(
            UnexpectedValueException::class,
            static fn(): array => $repository->profile('xx'),
            'Per-term synonym expansion fanout fails closed during runtime profile loading.'
        );
        assert_contains_text('max expansions per term ' . Language_FTS_Playground_Lexical_Profile_Repository::DEFAULT_MAX_EXPANSIONS_PER_TERM, $throwable->getMessage(), 'The per-term hard cap is visible in the runtime error.');
        assert_contains_text('alpha', $throwable->getMessage(), 'The rejected source term is named in the runtime error.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('runtime profile loading rejects phrase synonym expansion fanout', function (): void {
    $target_terms = language_fts_numbered_terms('phrase', Language_FTS_Playground_Lexical_Profile_Repository::DEFAULT_MAX_PHRASE_EXPANSIONS_PER_SOURCE + 1);
    $phrases = "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\n";
    foreach ($target_terms as $target) {
        $phrases .= "alpha beta\t{$target}\tquery_to_index\t0.6\tfixture-phrase-fanout\n";
    }
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\nalpha\talpha\tfixture\nbeta\tbeta\tfixture\n",
        "# source\ttarget\tdirection\tweight\tprovenance\n",
        null,
        $phrases
    );

    try {
        $repository = new Language_FTS_Playground_Lexical_Profile_Repository($root);
        $throwable = assert_throws(
            UnexpectedValueException::class,
            static fn(): array => $repository->profile('xx'),
            'Phrase synonym expansion fanout fails closed during runtime profile loading.'
        );
        assert_contains_text('max phrase expansions per source ' . Language_FTS_Playground_Lexical_Profile_Repository::DEFAULT_MAX_PHRASE_EXPANSIONS_PER_SOURCE, $throwable->getMessage(), 'The phrase hard cap is visible in the runtime error.');
        assert_contains_text('alpha beta', $throwable->getMessage(), 'The rejected phrase source is named in the runtime error.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('pairwise synonyms remain compatible and override duplicate synset pairs', function (): void {
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\nalpha\talpha\nbeta\tbeta\ngamma\tgamma\n",
        "# source\ttarget\tdirection\tweight\tprovenance\nalpha\tbeta\tquery_to_index\t0.91\texplicit-override\ngamma\tbeta\tquery_to_index\t0.33\texplicit-pair\n",
        "# concept_id\tweight\tprovenance\tterms\nconcept.search\t0.42\ttest-concept-pack\talpha beta\n"
    );

    try {
        $profile = (new Language_FTS_Playground_Lexical_Profile_Repository($root))->profile('xx');

        assert_same(['beta'], array_column($profile['synonyms']['alpha'] ?? [], 'term'), 'Duplicate source/target expansions are deduplicated.');
        assert_same(0.91, $profile['synonyms']['alpha'][0]['weight'] ?? null, 'Explicit pairwise rows override duplicate synset weights.');
        assert_same('explicit-override', $profile['synonyms']['alpha'][0]['provenance'] ?? null, 'Explicit pairwise provenance wins for duplicate pairs.');
        assert_same(['beta'], array_column($profile['synonyms']['gamma'] ?? [], 'term'), 'Existing pairwise synonym rows still work without a matching synset.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('loads synonym phrase resource rows into analyzer phrase expansions', function (): void {
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\n",
        "# source\ttarget\tdirection\tweight\tprovenance\n",
        null,
        "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\nfull text search\tfts\tquery_to_index\t0.82\tfixture-phrases\nsite search\tsearch site\tbidirectional\t0.72\tfixture-phrases\n"
    );

    try {
        $repository = new Language_FTS_Playground_Lexical_Profile_Repository($root);
        $profile = $repository->profile('xx');
        $analyzer = new Language_FTS_Playground_Analyzer($repository);
        $query_tokens = $analyzer->analyze_text_token_keys('full text search', 'xx');
        $expansions = $analyzer->expand_query_synonym_phrases($query_tokens, 'xx');

        assert_same(3, count($profile['synonym_phrases'] ?? []), 'Bidirectional synonym phrase rows are materialized into two runtime expansions.');
        assert_same(['full', 'text', 'search'], $expansions[0]['source_terms'] ?? [], 'The analyzer matches the source phrase over ordered query keys.');
        assert_same(['fts'], $expansions[0]['target_terms'] ?? [], 'The analyzer returns the configured target key sequence.');
        assert_same(0.82, $expansions[0]['weight'] ?? null, 'The phrase expansion carries the configured weight.');

        $reverse_tokens = $analyzer->analyze_text_token_keys('search site', 'xx');
        $reverse = $analyzer->expand_query_synonym_phrases($reverse_tokens, 'xx');
        assert_same(['site', 'search'], $reverse[0]['target_terms'] ?? [], 'Bidirectional phrase rows expand in the reverse direction.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('phrase synonym expansion uses indexed candidates instead of evaluating unrelated rows', function (): void {
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\n",
        "# source\ttarget\tdirection\tweight\tprovenance\n",
        null,
        "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\nportal lookup\tsearch site\tquery_to_index\t0.74\tfixture-phrases\nalpha beta\tgamma\tquery_to_index\t0.61\tfixture-phrases\n"
    );

    try {
        $repository = new Language_FTS_Playground_Lexical_Profile_Repository($root);
        $profile = $repository->profile('xx');
        assert_same(2, count($profile['synonym_phrases'] ?? []), 'The fixture loads multiple phrase synonym rows.');

        $profiles_property = new ReflectionProperty(Language_FTS_Playground_Lexical_Profile_Repository::class, 'profiles');
        $profiles_property->setAccessible(true);
        $profiles = $profiles_property->getValue($repository);
        $profiles['xx']['synonym_phrases'][] = [
            'source_terms' => [new Language_FTS_Playground_Exploding_Phrase_Term()],
            'target_terms' => ['unused'],
            'source' => 'exploding',
            'target' => 'unused',
            'weight' => 0.5,
            'direction' => 'query_to_index',
            'provenance' => 'test-only',
        ];
        $profiles_property->setValue($repository, $profiles);

        $analyzer = new Language_FTS_Playground_Analyzer($repository);
        $query_tokens = $analyzer->analyze_text_token_keys('portal lookup', 'xx');
        $expansions = $analyzer->expand_query_synonym_phrases($query_tokens, 'xx');

        assert_same(1, count($expansions), 'Only phrase rows matching the query first key and source length are evaluated.');
        assert_same(['search', 'site'], $expansions[0]['target_terms'] ?? [], 'The relevant phrase synonym still expands normally.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('bundled full text search phrase synonym finds FTS without PHP hardcoding', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(127, 'en', 'Acronym note', '<p>FTS handles compact indexing.</p>'));

    $results = $searcher->search('full text search', 'en');

    assert_same([127], array_column($results, 'post_id'), 'The bundled phrase resource lets a full text search query find an FTS document.');
    assert_contains_text('full text search=>fts', implode(', ', $results[0]['matched_terms'] ?? []), 'Phrase synonym diagnostics include source and target key sequences.');
    assert_contains_text('<mark>FTS</mark>', $results[0]['snippet'] ?? '', 'Phrase synonym snippets highlight the indexed target token.');

    $analyzer_source = file_get_contents(__DIR__ . '/../src/Analyzer.php');
    $searcher_source = file_get_contents(__DIR__ . '/../src/Searcher.php');
    assert_true(is_string($analyzer_source) && is_string($searcher_source), 'Runtime source files are readable.');
    foreach (['full text search', 'portal lookup', 'search site'] as $phrase_literal) {
        assert_not_contains_text($phrase_literal, $analyzer_source, "Analyzer does not hardcode phrase synonym literal {$phrase_literal}.");
        assert_not_contains_text($phrase_literal, $searcher_source, "Searcher does not hardcode phrase synonym literal {$phrase_literal}.");
    }
});

test_case('exact English phrase matches outrank phrase-synonym-only matches', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(128, 'en', 'Acronym only', '<p>FTS handles compact indexing.</p>'));
    $indexer->index_post(fixture_post(129, 'en', 'Full text search guide', '<p>A direct title and body match.</p>'));

    $results = $searcher->search('full text search', 'en');

    assert_true(count($results) >= 2, 'Both exact and phrase-synonym-only English documents match.');
    assert_same(129, $results[0]['post_id'], 'Exact full text search matches rank above phrase-synonym-only FTS matches.');
    assert_same(128, $results[1]['post_id'], 'The phrase-synonym-only FTS match remains available below the exact match.');
});

test_case('multiword phrase synonym targets require adjacent indexed positions', function (): void {
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\n",
        "# source\ttarget\tdirection\tweight\tprovenance\n",
        null,
        "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\nportal lookup\tsearch site\tquery_to_index\t0.74\tfixture-phrases\n"
    );

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        $indexer->index_post(fixture_post(130, 'xx', 'Adjacent target', '<p>The search site is visible.</p>'));
        $indexer->index_post(fixture_post(131, 'xx', 'Skipped boundary target', '<p>search <script>ignored()</script> site</p>'));
        $indexer->index_post(fixture_post(132, 'xx', 'Loose target', '<p>search notes mention a public site later.</p>'));

        $results = $searcher->search('portal lookup', 'xx');

        assert_same([130], array_column($results, 'post_id'), 'A multiword phrase target matches only adjacent indexed target positions.');
        assert_contains_text('portal lookup=>search site', implode(', ', $results[0]['matched_terms'] ?? []), 'Multiword phrase target diagnostics keep the phrase label.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('bidirectional phrase synonym rows work in both search directions', function (): void {
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\n",
        "# source\ttarget\tdirection\tweight\tprovenance\n",
        null,
        "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\nalpha beta\tgamma delta\tbidirectional\t0.66\tfixture-phrases\n"
    );

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        $indexer->index_post(fixture_post(133, 'xx', 'Forward target', '<p>gamma delta appears together.</p>'));
        $indexer->index_post(fixture_post(134, 'xx', 'Reverse target', '<p>alpha beta appears together.</p>'));

        $forward = $searcher->search('alpha beta', 'xx');
        $reverse = $searcher->search('gamma delta', 'xx');

        assert_true(in_array(133, array_column($forward, 'post_id'), true), 'The declared phrase direction expands source to target.');
        assert_true(in_array(134, array_column($reverse, 'post_id'), true), 'Bidirectional phrase rows also expand target to source.');
        foreach ($forward as $result) {
            if ((int) $result['post_id'] === 133) {
                assert_contains_text('alpha beta=>gamma delta', implode(', ', $result['matched_terms'] ?? []), 'Forward phrase diagnostics are reported.');
            }
        }
        foreach ($reverse as $result) {
            if ((int) $result['post_id'] === 134) {
                assert_contains_text('gamma delta=>alpha beta', implode(', ', $result['matched_terms'] ?? []), 'Reverse phrase diagnostics are reported.');
            }
        }
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('rejects malformed synonym phrase resource rows deliberately', function (): void {
    $cases = [
        'wrong columns' => [
            "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\nbroken\tfts\tquery_to_index\t0.8\n",
            'synonym phrase rows must have exactly 5 tab-separated columns',
        ],
        'empty source' => [
            "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\n\tfts\tquery_to_index\t0.8\tfixture\n",
            'synonym phrase source terms must be non-empty',
        ],
        'broken whitespace' => [
            "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\nfull  text\tfts\tquery_to_index\t0.8\tfixture\n",
            'synonym phrase source terms must be separated by single spaces',
        ],
        'non-normalized term' => [
            "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\nFull text\tfts\tquery_to_index\t0.8\tfixture\n",
            'synonym phrase source terms must be normalized lowercase resource tokens',
        ],
        'duplicate source term' => [
            "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\nfull full\tfts\tquery_to_index\t0.8\tfixture\n",
            'duplicate synonym phrase source term',
        ],
        'invalid direction' => [
            "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\nfull text\tfts\tindex_to_query\t0.8\tfixture\n",
            'synonym phrase direction must be query_to_index or bidirectional',
        ],
        'invalid weight' => [
            "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\nfull text\tfts\tquery_to_index\t1.5\tfixture\n",
            'synonym phrase weight must be greater than 0 and no more than 1',
        ],
        'empty provenance' => [
            "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\nfull text\tfts\tquery_to_index\t0.8\t\n",
            'synonym phrase provenance must be non-empty',
        ],
        'duplicate pair' => [
            "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\nfull text\tfts\tquery_to_index\t0.8\tfixture\nfull text\tfts\tquery_to_index\t0.7\tfixture\n",
            'duplicate synonym phrase source/target pair',
        ],
    ];

    foreach ($cases as $label => [$synonym_phrases, $expected_message]) {
        $root = create_language_fts_temp_profile_tree(
            "# observed\tcanonical\tprovenance\n",
            "# source\ttarget\tdirection\tweight\tprovenance\n",
            null,
            $synonym_phrases
        );

        try {
            $repository = new Language_FTS_Playground_Lexical_Profile_Repository($root);
            $throwable = assert_throws(
                UnexpectedValueException::class,
                static fn(): array => $repository->profile('xx'),
                "Malformed synonym phrase rows fail profile loading for {$label}."
            );
            assert_contains_text($expected_message, $throwable->getMessage(), "The malformed synonym phrase reason is reported for {$label}.");
        } finally {
            remove_language_fts_temp_tree($root);
        }
    }
});

test_case('automatic language routing uses phrase synonym source evidence without stopword-only confidence', function (): void {
    $root = create_language_fts_temp_profile_set([
        'qa' => [
            'label' => 'Phrase QA',
            'order' => 10,
            'synonym_phrases' => "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\nportal lookup\tsearch site\tquery_to_index\t0.74\tfixture-router\n",
        ],
        'qb' => [
            'label' => 'Phrase QB',
            'order' => 20,
        ],
        'qc' => [
            'label' => 'Phrase QC',
            'order' => 30,
        ],
    ]);

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        $ranked = $analyzer->rank_query_languages('portal lookup');
        assert_same('qa', $ranked[0]['language'] ?? null, 'Phrase source evidence ranks the fake language.');
        assert_true(in_array('portal lookup', $ranked[0]['reasons']['synonym_sources'] ?? [], true), 'Phrase source evidence is reported with the synonym source reasons.');

        $indexer->index_post(fixture_post(135, 'qa', 'Phrase target', '<p>search site appears together.</p>'));
        $indexer->index_post(fixture_post(136, 'qb', 'Exact bait', '<p>portal lookup would match if QB were searched.</p>'));
        $indexer->index_post(fixture_post(137, 'qc', 'Exact bait', '<p>portal lookup would match if QC were searched.</p>'));

        $results = $searcher->search('portal lookup', 'auto');
        assert_same(['qa'], $storage->fetch_postings_languages, 'Confident phrase evidence queries only the selected fake language partition.');
        assert_same([135], array_column($results, 'post_id'), 'The routed phrase search returns the phrase-synonym target.');
    } finally {
        remove_language_fts_temp_tree($root);
    }

    $stopword_root = create_language_fts_temp_profile_set([
        'sw' => [
            'label' => 'Stopword Phrase',
            'order' => 10,
            'stopwords' => "and\nor\n",
            'synonym_phrases' => "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\nand or\talpha\tquery_to_index\t0.8\tfixture-router\n",
        ],
    ]);

    try {
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($stopword_root));
        assert_same([], $analyzer->rank_query_languages('and or'), 'Stopword-only phrase sources do not create confident language evidence.');
    } finally {
        remove_language_fts_temp_tree($stopword_root);
    }
});

test_case('loads lexical pack provenance metadata explicitly', function (): void {
    $repository = new Language_FTS_Playground_Lexical_Profile_Repository();
    $metadata = $repository->pack_metadata('pl');

    assert_same('pl', $metadata['language_id'], 'Pack metadata language matches the requested profile.');
    assert_same('curated_seed', $metadata['data_kind'], 'The shipped Polish pack is marked as curated seed data.');
    assert_same('GPL-2.0-or-later', $metadata['license_name'], 'Seed pack metadata declares the repository license.');
    assert_same('language-fts-playground-polish-curated-seed', $metadata['provenance'], 'Seed pack metadata declares provenance.');
    assert_true(in_array('synsets.tsv', $metadata['files'], true), 'Seed pack metadata lists its synset runtime file.');
});

test_case('shared bootstrap does not load lexical pack validator', function (): void {
    $source = file_get_contents(__DIR__ . '/../src/bootstrap.php');

    assert_true(is_string($source), 'Shared bootstrap source is readable.');
    assert_not_contains_text('LexicalPackValidator.php', $source, 'Shared bootstrap keeps the lexical pack validator out of normal plugin requests.');
});

test_case('valid lexical packs produce deterministic validation stats', function (): void {
    $report = (new Language_FTS_Playground_Lexical_Pack_Validator())->validate_all();
    $by_id = language_fts_pack_status_by_id($report);

    assert_same(true, $report['valid'], 'Current shipped lexical packs validate cleanly.');
    assert_same([], $report['warnings'], 'Current shipped lexical packs have no top-level warnings.');
    assert_same(['en', 'pl', 'de'], array_column($report['languages'], 'language_id'), 'Validator reports languages in profile order.');
    assert_same('curated_seed', $by_id['en']['metadata']['data_kind'] ?? null, 'English pack is labeled as curated seed data.');
    assert_same('curated_seed', $by_id['pl']['metadata']['data_kind'] ?? null, 'Polish pack is labeled as curated seed data.');
    assert_same('curated_seed', $by_id['de']['metadata']['data_kind'] ?? null, 'German pack is labeled as curated seed data.');

    assert_same(42, $by_id['en']['counts']['stopwords'] ?? null, 'English stopword count is deterministic.');
    assert_same(28, $by_id['en']['counts']['lexeme_rows'] ?? null, 'English lexeme count is deterministic.');
    assert_same(0, $by_id['en']['counts']['synset_rows'] ?? null, 'English does not ship synset rows yet.');
    assert_same(3, $by_id['en']['counts']['phrase_synonym_rows'] ?? null, 'English ships deterministic phrase synonym seed rows.');
    assert_same(4, $by_id['en']['counts']['phrase_synonym_expansions'] ?? null, 'English bidirectional phrase rows produce deterministic phrase expansions.');
    assert_same(33, $by_id['pl']['counts']['stopwords'] ?? null, 'Polish stopword count is deterministic.');
    assert_same(34, $by_id['pl']['counts']['lexeme_rows'] ?? null, 'Polish lexeme count is deterministic.');
    assert_same(1, $by_id['pl']['counts']['synset_rows'] ?? null, 'Polish seed pack has one concept synset row.');
    assert_same(12, $by_id['pl']['counts']['concept_expansions'] ?? null, 'Polish concept-derived expansion count is deterministic.');
    assert_same(4, $by_id['pl']['max_synset_size'] ?? null, 'Polish max synset size is deterministic.');
    assert_same(3, $by_id['pl']['max_expansion_fanout'] ?? null, 'Polish max fanout is deterministic.');
    assert_same(35, $by_id['de']['counts']['stopwords'] ?? null, 'German stopword count is deterministic.');
    assert_same(26, $by_id['de']['counts']['lexeme_rows'] ?? null, 'German lexeme count is deterministic.');
});

test_case('lexical pack validator warns and fails for missing listed files', function (): void {
    $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\tfixture\n");
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    write_language_fts_temp_pack_metadata($language_dir, [
        'files' => [
            'profile.php',
            'stopwords.txt',
            'lexemes.tsv',
            'synonyms.tsv',
            'missing-runtime.tsv',
        ],
    ]);

    try {
        $report = (new Language_FTS_Playground_Lexical_Pack_Validator($root))->validate_all();
        $by_id = language_fts_pack_status_by_id($report);

        assert_same(false, $report['valid'], 'A missing metadata-listed file makes validation fail.');
        assert_same(false, $by_id['xx']['runtime_files_exist'] ?? null, 'Missing metadata-listed files are reflected in runtime_files_exist.');
        assert_same(['missing-runtime.tsv'], $by_id['xx']['missing_files'] ?? null, 'The missing runtime file name is reported.');
        assert_contains_text('missing-runtime.tsv', implode("\n", $by_id['xx']['warnings'] ?? []), 'The missing file warning names the file.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('lexical pack validator warns and fails for malformed metadata', function (): void {
    $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\tfixture\n");
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    write_language_fts_temp_pack_metadata($language_dir, [
        'source_name' => '',
        'pack_date' => 'not-a-date',
        'data_kind' => 'unreviewed_full_dump',
    ]);

    try {
        $report = (new Language_FTS_Playground_Lexical_Pack_Validator($root))->validate_all();
        $by_id = language_fts_pack_status_by_id($report);
        $warnings = implode("\n", $by_id['xx']['warnings'] ?? []);

        assert_same(false, $report['valid'], 'Malformed pack metadata makes validation fail.');
        assert_same(false, $by_id['xx']['metadata_valid'] ?? null, 'Malformed metadata is reflected in metadata_valid.');
        assert_same(true, $by_id['xx']['runtime_files_exist'] ?? null, 'Malformed metadata does not make existing runtime files look missing.');
        assert_same([], $by_id['xx']['missing_files'] ?? null, 'Malformed metadata with present runtime files has no missing file report.');
        assert_contains_text('source_name', $warnings, 'Missing source name is reported.');
        assert_contains_text('pack_date', $warnings, 'Invalid pack date is reported.');
        assert_contains_text('data_kind', $warnings, 'Invalid data kind is reported.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('lexical pack validator warns and fails when metadata omits profile resources', function (): void {
    $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\tfixture\n");
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    write_language_fts_temp_pack_metadata($language_dir, [
        'files' => [
            'profile.php',
            'stopwords.txt',
            'lexemes.tsv',
        ],
    ]);

    try {
        $report = (new Language_FTS_Playground_Lexical_Pack_Validator($root))->validate_all();
        $by_id = language_fts_pack_status_by_id($report);
        $warnings = implode("\n", $by_id['xx']['warnings'] ?? []);

        assert_same(false, $report['valid'], 'Omitted profile resources make validation fail.');
        assert_same(false, $by_id['xx']['metadata_valid'] ?? null, 'Omitted profile resources are reflected in metadata_valid.');
        assert_contains_text('profile resource synonyms (synonyms.tsv)', $warnings, 'The omitted profile-declared resource is reported.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('lexical pack validator requires v2 audit metadata for comprehensive packs', function (): void {
    $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\tfixture-comprehensive\n");
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    write_language_fts_temp_comprehensive_pack_metadata($language_dir, [
        'source' => null,
    ]);

    try {
        $report = (new Language_FTS_Playground_Lexical_Pack_Validator($root))->validate_all();
        $by_id = language_fts_pack_status_by_id($report);
        $warnings = implode("\n", $by_id['xx']['warnings'] ?? []);

        assert_same(false, $report['valid'], 'Comprehensive packs missing source metadata fail validation.');
        assert_contains_text('source must be an array', $warnings, 'The missing comprehensive source section is reported clearly.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('lexical pack validator requires runtime resource and file pairs', function (): void {
    $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\tfixture-comprehensive\n");
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    $metadata = language_fts_temp_comprehensive_pack_metadata($language_dir);
    foreach ($metadata['runtime_files'] as &$runtime_file) {
        if (($runtime_file['file'] ?? '') === 'profile.php') {
            $runtime_file['resource'] = 'license';
            break;
        }
    }
    unset($runtime_file);
    write_language_fts_temp_pack_metadata_array($language_dir, $metadata);

    try {
        $report = (new Language_FTS_Playground_Lexical_Pack_Validator($root))->validate_all();
        $by_id = language_fts_pack_status_by_id($report);
        $warnings = implode("\n", $by_id['xx']['warnings'] ?? []);

        assert_same(false, $report['valid'], 'A runtime file declared under the wrong resource label fails validation.');
        assert_same('incomplete', $by_id['xx']['metadata']['runtime_digest_status'] ?? null, 'Wrong resource/file coverage reports an incomplete runtime digest status.');
        assert_contains_text('runtime_files must include profile.php for comprehensive resource profile', $warnings, 'The missing runtime resource/file pair is reported.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('lexical pack validator requires normalization profile id to match the profile language', function (): void {
    $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\tfixture-comprehensive\n");
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    write_language_fts_temp_comprehensive_pack_metadata($language_dir, [
        'normalization' => [
            'profile_id' => 'de',
        ],
    ]);

    try {
        $report = (new Language_FTS_Playground_Lexical_Pack_Validator($root))->validate_all();
        $by_id = language_fts_pack_status_by_id($report);
        $warnings = implode("\n", $by_id['xx']['warnings'] ?? []);

        assert_same(false, $report['valid'], 'A comprehensive pack with the wrong normalization profile id fails validation.');
        assert_contains_text('normalization.profile_id must match profile language id xx', $warnings, 'The wrong normalization profile id is reported.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('lexical pack validator reports missing runtime digest metadata as not declared', function (): void {
    $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\tfixture-comprehensive\n");
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    write_language_fts_temp_comprehensive_pack_metadata($language_dir, [
        'runtime_files' => null,
    ]);

    try {
        $report = (new Language_FTS_Playground_Lexical_Pack_Validator($root))->validate_all();
        $by_id = language_fts_pack_status_by_id($report);
        $warnings = implode("\n", $by_id['xx']['warnings'] ?? []);

        assert_same(false, $report['valid'], 'A comprehensive pack missing runtime digest metadata fails validation.');
        assert_same('not_declared', $by_id['xx']['metadata']['runtime_digest_status'] ?? null, 'Missing runtime digest metadata does not report ok.');
        assert_contains_text('runtime_files must be a non-empty array', $warnings, 'Missing runtime digest metadata is reported.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('lexical pack validator reports malformed runtime digest metadata as invalid', function (): void {
    $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\tfixture-comprehensive\n");
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    $metadata = language_fts_temp_comprehensive_pack_metadata($language_dir);
    foreach ($metadata['runtime_files'] as &$runtime_file) {
        if (($runtime_file['file'] ?? '') === 'profile.php') {
            $runtime_file['sha256'] = 'not-a-sha256';
            break;
        }
    }
    unset($runtime_file);
    write_language_fts_temp_pack_metadata_array($language_dir, $metadata);

    try {
        $report = (new Language_FTS_Playground_Lexical_Pack_Validator($root))->validate_all();
        $by_id = language_fts_pack_status_by_id($report);
        $warnings = implode("\n", $by_id['xx']['warnings'] ?? []);

        assert_same(false, $report['valid'], 'A comprehensive pack with malformed runtime digest metadata fails validation.');
        assert_same('invalid', $by_id['xx']['metadata']['runtime_digest_status'] ?? null, 'Malformed runtime digest metadata does not report ok or mismatch.');
        assert_contains_text('runtime_files sha256 must be 64 lowercase hex characters', $warnings, 'Malformed runtime digest metadata is reported.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('lexical pack validator rejects non-normalized resource keys consistently', function (): void {
    $root = create_language_fts_temp_profile_set([
        'xx' => [
            'folds' => ['é' => 'e', 'É' => 'e'],
            'stopwords' => "And\ncafé\n",
            'lexemes' => "# observed\tcanonical\tprovenance\nCafé\tcafe\tfixture\nvalid\tValid\tfixture\n",
            'synonyms' => "# source\ttarget\tdirection\tweight\tprovenance\nAlpha\tbeta\tquery_to_index\t0.8\tfixture\n",
            'term_rules' => "# id\tmin_term_length\tpattern\tstrip_prefix\tstrip_suffix\tappend\tmin_key_length\tflags\talternate_pattern\talternate_replacement\tprovenance\nbad-append\t5\t/ing$/u\t\ting\tÉ\t3\t\t/e/u\tÉ\tfixture\n",
        ],
    ]);
    write_language_fts_temp_pack_metadata($root . DIRECTORY_SEPARATOR . 'xx');

    try {
        $report = (new Language_FTS_Playground_Lexical_Pack_Validator($root))->validate_all();
        $by_id = language_fts_pack_status_by_id($report);
        $warnings = implode("\n", $by_id['xx']['warnings'] ?? []);

        assert_same(false, $report['valid'], 'Non-normalized resource rows make validation fail.');
        assert_contains_text('stopword must be normalized lowercase resource tokens', $warnings, 'Non-normalized stopwords are reported.');
        assert_contains_text('lexeme observed form must be normalized lowercase resource tokens', $warnings, 'Non-normalized lexeme observed forms are reported.');
        assert_contains_text('lexeme canonical key must be normalized lowercase resource tokens', $warnings, 'Non-normalized lexeme canonical keys are reported.');
        assert_contains_text('synonym source must be normalized lowercase resource tokens', $warnings, 'Non-normalized pairwise synonym sources are reported.');
        assert_contains_text('term rule append must be normalized lowercase resource tokens', $warnings, 'Non-normalized term rule append literals are reported.');
        assert_contains_text('term rule alternate_replacement must be normalized lowercase resource tokens', $warnings, 'Non-normalized term rule alternate replacements are reported.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('lexical pack validator fails runtime digest and byte mismatches', function (): void {
    $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\tfixture-comprehensive\n");
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    $metadata = language_fts_temp_comprehensive_pack_metadata($language_dir);
    foreach ($metadata['runtime_files'] as &$runtime_file) {
        if (($runtime_file['file'] ?? '') === 'profile.php') {
            $runtime_file['sha256'] = str_repeat('b', 64);
        }
        if (($runtime_file['file'] ?? '') === 'stopwords.txt') {
            $runtime_file['bytes'] = ((int) $runtime_file['bytes']) + 1;
        }
    }
    unset($runtime_file);
    write_language_fts_temp_pack_metadata_array($language_dir, $metadata);

    try {
        $report = (new Language_FTS_Playground_Lexical_Pack_Validator($root))->validate_all();
        $by_id = language_fts_pack_status_by_id($report);
        $warnings = implode("\n", $by_id['xx']['warnings'] ?? []);

        assert_same(false, $report['valid'], 'Runtime digest and byte mismatches fail validation.');
        assert_same('mismatch', $by_id['xx']['metadata']['runtime_digest_status'] ?? null, 'Digest mismatch status is reported in metadata.');
        assert_contains_text('runtime file sha256 mismatch', $warnings, 'Runtime sha256 mismatch is reported.');
        assert_contains_text('runtime file byte count mismatch', $warnings, 'Runtime byte count mismatch is reported.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('lexical pack validator fails undeclared comprehensive row provenance', function (): void {
    $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\trogue-provenance\n");
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    write_language_fts_temp_comprehensive_pack_metadata($language_dir);

    try {
        $report = (new Language_FTS_Playground_Lexical_Pack_Validator($root))->validate_all();
        $by_id = language_fts_pack_status_by_id($report);
        $warnings = implode("\n", $by_id['xx']['warnings'] ?? []);

        assert_same(false, $report['valid'], 'Undeclared row provenance fails comprehensive pack validation.');
        assert_contains_text('lexeme provenance must be declared', $warnings, 'The undeclared provenance failure names row provenance.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('lexical pack validator warns and fails for malformed synonym phrase rows', function (): void {
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\nalpha\talpha\tfixture\n",
        "# source\ttarget\tdirection\tweight\tprovenance\n",
        null,
        "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\nalpha alpha\tbeta\tquery_to_index\t0.8\tfixture\n"
    );
    write_language_fts_temp_pack_metadata($root . DIRECTORY_SEPARATOR . 'xx');

    try {
        $report = (new Language_FTS_Playground_Lexical_Pack_Validator($root))->validate_all();
        $by_id = language_fts_pack_status_by_id($report);
        $warnings = implode("\n", $by_id['xx']['warnings'] ?? []);

        assert_same(false, $report['valid'], 'Malformed synonym phrase resources make validation fail.');
        assert_contains_text('duplicate synonym phrase source term', $warnings, 'Validator reports malformed synonym phrase rows.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('lexical pack validator fails too-large synsets and expansion fanout thresholds', function (): void {
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\nalpha\talpha\tfixture\nbeta\tbeta\tfixture\ngamma\tgamma\tfixture\ndelta\tdelta\tfixture\n",
        "# source\ttarget\tdirection\tweight\tprovenance\n",
        "# concept_id\tweight\tprovenance\tterms\nconcept.too-wide\t0.5\tfixture\talpha beta gamma delta\n"
    );
    write_language_fts_temp_pack_metadata($root . DIRECTORY_SEPARATOR . 'xx');

    try {
        $report = (new Language_FTS_Playground_Lexical_Pack_Validator($root, 3, 2))->validate_all();
        $by_id = language_fts_pack_status_by_id($report);
        $warnings = implode("\n", $by_id['xx']['warnings'] ?? []);

        assert_same(false, $report['valid'], 'Threshold failures make validation fail.');
        assert_same(4, $by_id['xx']['max_synset_size'] ?? null, 'The too-wide synset size is measured.');
        assert_same(3, $by_id['xx']['max_expansion_fanout'] ?? null, 'The too-wide per-term expansion fanout is measured.');
        assert_contains_text('max synset size 3', $warnings, 'Synset size threshold failure is reported.');
        assert_contains_text('Maximum expansion fanout 3 exceeds threshold 2', $warnings, 'Fanout threshold failure is reported.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('lexical pack validator CLI exposes phrase fanout thresholds and honors stricter overrides', function (): void {
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\nalpha\talpha\tfixture\nbeta\tbeta\tfixture\nfirst\tfirst\tfixture\nsecond\tsecond\tfixture\n",
        "# source\ttarget\tdirection\tweight\tprovenance\n",
        null,
        "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\nalpha beta\tfirst\tquery_to_index\t0.7\tfixture\nalpha beta\tsecond\tquery_to_index\t0.6\tfixture\n"
    );
    write_language_fts_temp_pack_metadata($root . DIRECTORY_SEPARATOR . 'xx');

    try {
        $default = run_language_fts_validator([
            'resource_root' => $root,
            'json' => true,
        ]);
        $default_decoded = json_decode($default['output'], true);

        assert_same(0, $default['exit_code'], 'The default phrase fanout cap accepts the small custom pack. Output: ' . $default['output']);
        assert_true(is_array($default_decoded), 'Default validator JSON is parseable.');
        assert_same(Language_FTS_Playground_Lexical_Profile_Repository::DEFAULT_MAX_PHRASE_EXPANSIONS_PER_SOURCE, $default_decoded['thresholds']['max_phrase_expansions_per_source'] ?? null, 'Validator JSON exposes the runtime phrase fanout default.');

        $strict = run_language_fts_validator([
            'resource_root' => $root,
            'max_phrase_expansions_per_source' => 1,
            'json' => true,
        ]);
        $strict_decoded = json_decode($strict['output'], true);

        assert_true($strict['exit_code'] !== 0, 'A stricter phrase fanout CLI cap makes validation fail.');
        assert_true(is_array($strict_decoded), 'Strict validator JSON is parseable.');
        assert_same(1, $strict_decoded['thresholds']['max_phrase_expansions_per_source'] ?? null, 'Validator JSON records the stricter CLI phrase fanout cap.');
        assert_contains_text('Maximum phrase expansion fanout 2 exceeds threshold 1', $strict['output'], 'The stricter phrase fanout failure is reported.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('lexical pack validator CLI emits deterministic parseable JSON', function (): void {
    $first = run_language_fts_validator(['json' => true]);
    $second = run_language_fts_validator(['json' => true]);

    assert_same(0, $first['exit_code'], 'Validator JSON CLI exits successfully for current packs. Output: ' . $first['output']);
    assert_same($first['output'], $second['output'], 'Validator JSON output is deterministic across runs.');

    $decoded = json_decode($first['output'], true);
    assert_true(is_array($decoded), 'Validator JSON output is parseable.');
    assert_same(true, $decoded['valid'] ?? null, 'Validator JSON marks current packs valid.');
    assert_same(Language_FTS_Playground_Lexical_Profile_Repository::DEFAULT_MAX_SYNSET_SIZE, $decoded['thresholds']['max_synset_size'] ?? null, 'Validator JSON exposes the runtime synset size default.');
    assert_same(Language_FTS_Playground_Lexical_Profile_Repository::DEFAULT_MAX_EXPANSIONS_PER_TERM, $decoded['thresholds']['max_expansions_per_term'] ?? null, 'Validator JSON exposes the runtime per-term fanout default.');
    assert_same(Language_FTS_Playground_Lexical_Profile_Repository::DEFAULT_MAX_PHRASE_EXPANSIONS_PER_SOURCE, $decoded['thresholds']['max_phrase_expansions_per_source'] ?? null, 'Validator JSON exposes the runtime phrase fanout default.');
    assert_same(['en', 'pl', 'de'], array_column($decoded['languages'] ?? [], 'language_id'), 'Validator JSON preserves deterministic language order.');
});

test_case('lexical pack validator CLI exits nonzero for a bad temp resource root', function (): void {
    $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\tfixture\n");
    write_language_fts_temp_pack_metadata($root . DIRECTORY_SEPARATOR . 'xx', [
        'files' => [
            'profile.php',
            'stopwords.txt',
            'lexemes.tsv',
            'synonyms.tsv',
            'missing-runtime.tsv',
        ],
    ]);

    try {
        $result = run_language_fts_validator([
            'resource_root' => $root,
            'json' => true,
        ]);
        $decoded = json_decode($result['output'], true);

        assert_true($result['exit_code'] !== 0, 'Bad resource roots make the validator CLI exit nonzero.');
        assert_true(is_array($decoded), 'Bad resource root JSON is still parseable.');
        assert_same(false, $decoded['valid'] ?? null, 'Bad resource root JSON marks validation invalid.');
        assert_contains_text('missing-runtime.tsv', $result['output'], 'Bad resource root output explains the missing file.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('lexical pack evaluator passes the committed fixture with quality gates', function (): void {
    $result = run_language_fts_evaluator(language_fts_eval_fixture_path('demo-suite.json'), [
        'min_recall_at_5' => '1.0',
        'min_precision_at_5' => '0.2',
        'min_mrr' => '1.0',
        'min_ndcg_at_5' => '1.0',
    ]);

    assert_same(0, $result['exit_code'], 'Committed lexical relevance fixture passes. Output: ' . $result['output']);
    assert_contains_text('Evaluation passed.', $result['output'], 'Human evaluator output reports success.');
    assert_contains_text('recall@5: 1.0000', $result['output'], 'Human evaluator output includes recall@5.');
    assert_contains_text('precision@5: 0.2000', $result['output'], 'Human evaluator output includes precision@5.');
    assert_contains_text('MRR: 1.0000', $result['output'], 'Human evaluator output includes MRR.');
    assert_contains_text('nDCG@5: 1.0000', $result['output'], 'Human evaluator output includes nDCG@5.');
});

test_case('lexical pack evaluator passes the committed phrase synonym fixture', function (): void {
    $result = run_language_fts_evaluator(language_fts_eval_fixture_path('phrase-suite.json'), ['json' => true]);
    $decoded = json_decode($result['output'], true);

    assert_same(0, $result['exit_code'], 'Committed phrase synonym relevance fixture passes. Output: ' . $result['output']);
    assert_true(is_array($decoded), 'Phrase synonym evaluator JSON is parseable.');
    assert_same(true, $decoded['passed'] ?? null, 'Phrase synonym evaluator output reports success.');
    assert_contains_text('full text search=>fts', $result['output'], 'Phrase synonym evaluator output includes phrase diagnostics.');
});

test_case('lexical pack evaluator JSON is deterministic and parseable', function (): void {
    $fixture = language_fts_eval_fixture_path('demo-suite.json');
    $first = run_language_fts_evaluator($fixture, ['json' => true]);
    $second = run_language_fts_evaluator($fixture, ['json' => true]);

    assert_same(0, $first['exit_code'], 'Evaluator JSON CLI exits successfully. Output: ' . $first['output']);
    assert_same($first['output'], $second['output'], 'Evaluator JSON output is deterministic across runs.');

    $decoded = json_decode($first['output'], true);
    assert_true(is_array($decoded), 'Evaluator JSON output is parseable.');
    assert_same(true, $decoded['passed'] ?? null, 'Evaluator JSON marks the committed fixture as passing.');
    assert_same(6, $decoded['query_count'] ?? null, 'Evaluator JSON reports the committed fixture query count.');
    assert_same(1, $decoded['metrics']['recall_at_5'] ?? null, 'Evaluator JSON reports deterministic recall@5.');
    assert_same(['en', 'pl', 'de'], $decoded['enabled_languages'] ?? null, 'Evaluator JSON reports enabled bundled languages in profile order.');
});

test_case('lexical pack evaluator fails too-strict metric thresholds clearly', function (): void {
    $result = run_language_fts_evaluator(language_fts_eval_fixture_path('demo-suite.json'), [
        'min_precision_at_5' => '0.21',
    ]);

    assert_true($result['exit_code'] !== 0, 'Too-strict evaluator thresholds exit nonzero.');
    assert_contains_text('precision@5', $result['output'], 'Threshold failure names the failing metric.');
    assert_contains_text('below minimum 0.2100', $result['output'], 'Threshold failure reports the configured minimum.');
    assert_contains_text('Evaluation failed:', $result['output'], 'Human evaluator output reports failure.');
});

test_case('lexical pack evaluator reports misses and unexpected top-k hits', function (): void {
    $fixture_path = write_language_fts_temp_eval_fixture([
        'name' => 'Miss and unexpected hit fixture',
        'thresholds' => [
            'recall_at_5' => 1.0,
        ],
        'documents' => [
            [
                'id' => 'expected',
                'language' => 'en',
                'title' => 'Expected document',
                'content' => '<p>This document deliberately lacks the query term.</p>',
            ],
            [
                'id' => 'bait',
                'language' => 'en',
                'title' => 'Orchard bait',
                'content' => '<p>orchard appears in the wrong document.</p>',
            ],
        ],
        'queries' => [
            [
                'query' => 'orchard',
                'language' => 'en',
                'relevant' => ['expected'],
                'irrelevant' => ['bait'],
            ],
        ],
    ]);

    try {
        $result = run_language_fts_evaluator($fixture_path);

        assert_true($result['exit_code'] !== 0, 'Misses plus unexpected hits fail when recall is gated.');
        assert_contains_text('missing relevant ids: expected', $result['output'], 'Evaluator human output lists missed relevant ids.');
        assert_contains_text('unexpected top-5 ids: bait', $result['output'], 'Evaluator human output lists unexpected top-k ids.');
        assert_contains_text('Unexpected top-5 hit for query "orchard": bait', $result['output'], 'Evaluator failure summary names the unexpected hit.');
    } finally {
        remove_language_fts_temp_file($fixture_path);
    }
});

test_case('lexical pack evaluator uses a custom resource root', function (): void {
    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\nalpha\talpha\tfixture\nbeta\tbeta\tfixture\n",
        "# source\ttarget\tdirection\tweight\tprovenance\nalpha\tbeta\tquery_to_index\t0.8\tfixture-evaluator-custom-root\n"
    );
    $fixture_path = write_language_fts_temp_eval_fixture([
        'name' => 'Custom evaluator root fixture',
        'documents' => [
            [
                'id' => 'custom-target',
                'language' => 'xx',
                'title' => 'Custom target',
                'content' => '<p>beta appears only through the custom lexical pack.</p>',
            ],
        ],
        'queries' => [
            [
                'query' => 'alpha',
                'language' => 'xx',
                'relevant' => ['custom-target'],
            ],
        ],
    ]);

    try {
        $without_custom_root = run_language_fts_evaluator($fixture_path, [
            'min_recall_at_5' => '1.0',
        ]);
        assert_true($without_custom_root['exit_code'] !== 0, 'The custom-root fixture does not pass against bundled resources.');
        assert_contains_text('missing relevant ids: custom-target', $without_custom_root['output'], 'Bundled resources miss the custom synonym target.');

        $with_custom_root = run_language_fts_evaluator($fixture_path, [
            'resource_root' => $root,
            'min_recall_at_5' => '1.0',
        ]);
        assert_same(0, $with_custom_root['exit_code'], 'The evaluator consumes the custom resource root. Output: ' . $with_custom_root['output']);
        assert_contains_text('Resource root: ' . Language_FTS_Playground_Lexical_Profile_Repository::normalize_resource_root($root), $with_custom_root['output'], 'Human output reports the custom resource root.');
        assert_contains_text('Evaluation passed.', $with_custom_root['output'], 'The custom resource root changes analyzer synonym behavior for the fixture.');
    } finally {
        remove_language_fts_temp_file($fixture_path);
        remove_language_fts_temp_tree($root);
    }
});

test_case('lexical pack evaluator works under php -n', function (): void {
    $result = run_language_fts_evaluator(language_fts_eval_fixture_path('demo-suite.json'), [], true);

    assert_same(0, $result['exit_code'], 'Evaluator runs under php -n. Output: ' . $result['output']);
    assert_contains_text('Evaluation passed.', $result['output'], 'php -n evaluator output reports success.');
});

test_case('lexical pack evaluator accepts suite option and spaced thresholds', function (): void {
    $command = [
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__DIR__ . '/../tools/evaluate-lexical-pack.php'),
        '--suite',
        escapeshellarg(language_fts_eval_fixture_path('phrase-suite.json')),
        '--min-recall-at-5',
        '1',
        '--min-mrr',
        '1',
    ];
    $lines = [];
    $exit_code = 0;
    exec(implode(' ', $command) . ' 2>&1', $lines, $exit_code);
    $output = implode("\n", $lines);

    assert_same(0, $exit_code, 'Evaluator accepts Task 154 --suite command syntax. Output: ' . $output);
    assert_contains_text('Evaluation passed.', $output, 'The --suite alias evaluates the requested fixture.');
});

test_case('morphology fixture compiler writes deterministic minimal English pack', function (): void {
    $output_dir = create_language_fts_temp_dir('language-fts-morphology-en');

    try {
        $result = run_language_fts_morphology_compiler(language_fts_morphology_fixture_path('en-seed.json'), $output_dir);
        assert_same(0, $result['exit_code'], 'Morphology fixture compiler exits successfully. Output: ' . $result['output']);

        $expected_term_rules =
            "# id\tmin_term_length\tpattern\tstrip_prefix\tstrip_suffix\tappend\tmin_key_length\tflags\talternate_pattern\talternate_replacement\tprovenance\n" .
            "en-010-ed-double\t5\t/^[a-z]*([bcdfghjkmnpqrtvwxyz])\\1ed$/u\t\ted\t\t3\ttrim_doubled_final_consonant,require_vowel_or_y,stop_after_match\t\t\tfixture-english-morphology\n" .
            "en-020-ed-base\t5\t/^[a-z]+ed$/u\t\ted\t\t3\trequire_vowel_or_y\t\t\tfixture-english-morphology\n" .
            "en-030-ing-double\t6\t/^[a-z]*([bcdfghjkmnpqrtvwxyz])\\1ing$/u\t\ting\t\t3\ttrim_doubled_final_consonant,require_vowel_or_y,stop_after_match\t\t\tfixture-english-morphology\n" .
            "en-040-ing-base\t6\t/^[a-z]+ing$/u\t\ting\t\t3\trequire_vowel_or_y\t\t\tfixture-english-morphology\n" .
            "en-050-es\t4\t/^[a-z]+(?:ches|shes|sses|xes|zes|ses|oes)$/u\t\tes\t\t3\tstop_after_match\t\t\tfixture-english-morphology\n" .
            "en-060-final-s\t4\t/^[a-z]+s$/u\t\ts\t\t3\t\t\t\tfixture-english-morphology\n";
        $expected_stopwords = "# Generated stopwords from morphology fixture.\n# provenance: fixture-english-morphology\nand\nthe\n";
        $expected_protected_terms = "# Generated protected terms from morphology fixture.\n# provenance: fixture-english-morphology\nanalysis\nbus\nnews\n";

        $first_outputs = [
            'term_rules.tsv' => file_get_contents($output_dir . DIRECTORY_SEPARATOR . 'term_rules.tsv'),
            'stopwords.txt' => file_get_contents($output_dir . DIRECTORY_SEPARATOR . 'stopwords.txt'),
            'protected_terms.txt' => file_get_contents($output_dir . DIRECTORY_SEPARATOR . 'protected_terms.txt'),
            'lexemes.tsv' => file_get_contents($output_dir . DIRECTORY_SEPARATOR . 'lexemes.tsv'),
            'synonyms.tsv' => file_get_contents($output_dir . DIRECTORY_SEPARATOR . 'synonyms.tsv'),
        ];

        assert_same($expected_term_rules, $first_outputs['term_rules.tsv'], 'Compiler writes deterministic term_rules.tsv.');
        assert_same($expected_stopwords, $first_outputs['stopwords.txt'], 'Compiler writes sorted stopwords with provenance.');
        assert_same($expected_protected_terms, $first_outputs['protected_terms.txt'], 'Compiler writes sorted protected terms with provenance.');
        assert_same("# observed\tcanonical\tprovenance\n", $first_outputs['lexemes.tsv'], 'Compiler writes a header-only lexemes.tsv when no lexeme rows are declared.');
        assert_same("# source\ttarget\tdirection\tweight\tprovenance\n", $first_outputs['synonyms.tsv'], 'Compiler writes a header-only synonyms.tsv when no synonym rows are declared.');

        $profile = require $output_dir . DIRECTORY_SEPARATOR . 'profile.php';
        assert_same('en', $profile['id'] ?? null, 'Generated profile declares the fixture language.');
        assert_same('term_rules.tsv', $profile['resources']['term_rules'] ?? null, 'Generated profile declares term_rules.tsv.');
        assert_same('protected_terms.txt', $profile['resources']['protected_terms'] ?? null, 'Generated profile declares protected_terms.txt.');

        $metadata = require $output_dir . DIRECTORY_SEPARATOR . 'pack.php';
        assert_same('fixture-english-morphology', $metadata['provenance'] ?? null, 'Generated pack metadata declares fixture provenance.');
        assert_same(
            ['profile.php', 'stopwords.txt', 'lexemes.tsv', 'synonyms.tsv', 'term_rules.tsv', 'protected_terms.txt'],
            $metadata['files'] ?? null,
            'Generated pack metadata lists every profile resource.'
        );

        $second_result = run_language_fts_morphology_compiler(language_fts_morphology_fixture_path('en-seed.json'), $output_dir);
        assert_same(0, $second_result['exit_code'], 'Morphology fixture compiler can overwrite fixed outputs. Output: ' . $second_result['output']);
        foreach ($first_outputs as $file => $contents) {
            assert_same($contents, file_get_contents($output_dir . DIRECTORY_SEPARATOR . $file), "Repeated morphology compile keeps {$file} deterministic.");
        }
    } finally {
        remove_language_fts_temp_tree($output_dir);
    }
});

test_case('morphology fixture compiler supports file-only resource output', function (): void {
    $output_dir = create_language_fts_temp_dir('language-fts-morphology-file-only');

    try {
        $result = run_language_fts_morphology_compiler(
            language_fts_morphology_fixture_path('en-seed.json'),
            $output_dir,
            ['file_only' => true]
        );
        assert_same(0, $result['exit_code'], 'File-only morphology compile exits successfully. Output: ' . $result['output']);

        $files = array_values(array_filter(
            scandir($output_dir) ?: [],
            static fn(string $file): bool => $file !== '.' && $file !== '..'
        ));
        sort($files, SORT_STRING);
        assert_same(['protected_terms.txt', 'stopwords.txt', 'term_rules.tsv'], $files, 'File-only mode writes only morphology resource files.');
    } finally {
        remove_language_fts_temp_tree($output_dir);
    }
});

test_case('morphology fixture compiler rejects malformed fixtures clearly', function (): void {
    $base = read_language_fts_morphology_fixture('en-seed.json');
    $cases = [
        'invalid schema' => [
            static function (array $fixture): array {
                $fixture['schema'] = 'broken-schema';

                return $fixture;
            },
            'schema must be language-fts-playground-morphology-fixture-v1',
        ],
        'duplicate rule id' => [
            static function (array $fixture): array {
                $fixture['term_rule_behaviors'][] = $fixture['term_rule_behaviors'][0];

                return $fixture;
            },
            'duplicate term_rule_behaviors id',
        ],
        'invalid surface regex' => [
            static function (array $fixture): array {
                $fixture['term_rule_behaviors'][0]['surface_pattern'] = '(?P<broken';

                return $fixture;
            },
            'surface_pattern regex must be valid',
        ],
        'unknown flag' => [
            static function (array $fixture): array {
                $fixture['term_rule_behaviors'][0]['flags'][] = 'explode';

                return $fixture;
            },
            'unknown term rule flag',
        ],
        'unsorted rule id' => [
            static function (array $fixture): array {
                $first = $fixture['term_rule_behaviors'][0];
                $fixture['term_rule_behaviors'][0] = $fixture['term_rule_behaviors'][1];
                $fixture['term_rule_behaviors'][1] = $first;

                return $fixture;
            },
            'ascending rule id order',
        ],
        'non-normalized stopword' => [
            static function (array $fixture): array {
                $fixture['stopword_excerpt'][] = ['term' => 'The'];

                return $fixture;
            },
            'stopword term must be normalized lowercase resource tokens',
        ],
        'duplicate protected term' => [
            static function (array $fixture): array {
                $fixture['protected_terms'][] = ['term' => 'news'];

                return $fixture;
            },
            'duplicate protected term after normalization',
        ],
        'invalid expectation shape' => [
            static function (array $fixture): array {
                $fixture['analyzer_expectations'][0]['keys_include'] = 'run';

                return $fixture;
            },
            'keys_include must be a list of strings',
        ],
    ];

    foreach ($cases as $label => [$mutate, $expected_message]) {
        $case_dir = create_language_fts_temp_dir('language-fts-morphology-invalid');
        try {
            $input_path = $case_dir . DIRECTORY_SEPARATOR . 'fixture.json';
            $output_dir = $case_dir . DIRECTORY_SEPARATOR . 'out';
            write_language_fts_morphology_fixture_file($input_path, $mutate($base));
            $result = run_language_fts_morphology_compiler($input_path, $output_dir);
            assert_true($result['exit_code'] !== 0, "Malformed morphology fixture exits nonzero for {$label}.");
            assert_contains_text($expected_message, $result['output'], "Malformed morphology fixture reports the expected reason for {$label}.");
        } finally {
            remove_language_fts_temp_tree($case_dir);
        }
    }
});

test_case('compiled morphology fixtures feed repository analyzer and validator', function (): void {
    $root = create_language_fts_temp_dir('language-fts-morphology-root');

    try {
        $fixtures = [
            compile_language_fts_morphology_fixture_to_root('en-seed.json', $root),
            compile_language_fts_morphology_fixture_to_root('de-ordering.json', $root),
            compile_language_fts_morphology_fixture_to_root('pl-conservative.json', $root),
        ];

        $repository = new Language_FTS_Playground_Lexical_Profile_Repository($root);
        $analyzer = new Language_FTS_Playground_Analyzer($repository);
        assert_same(['en', 'pl', 'de'], $repository->language_ids(), 'Generated morphology profiles use deterministic language order.');

        $report = (new Language_FTS_Playground_Lexical_Pack_Validator($root))->validate_all();
        assert_same(true, $report['valid'], 'Generated morphology fixture packs validate cleanly. Report: ' . var_export($report, true));

        foreach ($fixtures as $fixture) {
            assert_language_fts_morphology_fixture_behavior($fixture, $analyzer);
        }

        $metadata = $repository->pack_metadata('en');
        assert_same('curated_seed', $metadata['data_kind'], 'Generated morphology fixture metadata remains curated_seed.');
        assert_contains_text('synthetic_project_fixture', $metadata['attribution_text'], 'Generated metadata preserves sample-only reference scope.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('membership importer compiles deterministic synsets and lexemes', function (): void {
    $output_dir = create_language_fts_temp_dir('language-fts-membership-import');

    try {
        $options = language_fts_import_options([
            'provenance' => 'fixture-membership',
            'weight' => '0.5',
        ]);
        $result = run_language_fts_importer('membership-tsv', language_fts_import_fixture_path('membership.tsv'), $output_dir, $options);
        assert_same(0, $result['exit_code'], 'membership-tsv importer exits successfully. Output: ' . $result['output']);

        $first_synsets = file_get_contents($output_dir . DIRECTORY_SEPARATOR . 'synsets.tsv');
        $first_lexemes = file_get_contents($output_dir . DIRECTORY_SEPARATOR . 'lexemes.tsv');
        assert_same("# concept_id\tweight\tprovenance\tterms\nconcept.lookup\t0.5\tfixture-membership\tfind lookup search\n", $first_synsets, 'membership-tsv writes deterministic synset rows.');
        assert_same("# observed\tcanonical\tprovenance\nsearching\tsearch\tfixture-membership\n", $first_lexemes, 'membership-tsv writes observed/canonical lexeme rows when present.');

        $second_result = run_language_fts_importer('membership-tsv', language_fts_import_fixture_path('membership.tsv'), $output_dir, $options);
        assert_same(0, $second_result['exit_code'], 'membership-tsv importer can overwrite its fixed runtime outputs. Output: ' . $second_result['output']);
        assert_same($first_synsets, file_get_contents($output_dir . DIRECTORY_SEPARATOR . 'synsets.tsv'), 'Repeated membership import keeps synsets deterministic.');
        assert_same($first_lexemes, file_get_contents($output_dir . DIRECTORY_SEPARATOR . 'lexemes.tsv'), 'Repeated membership import keeps lexemes deterministic.');
    } finally {
        remove_language_fts_temp_tree($output_dir);
    }
});

test_case('membership importer defaults omitted data kind to curated seed and lists profile resources', function (): void {
    $profile_tree = create_language_fts_temp_import_profile('xx');

    try {
        $options = language_fts_import_options([
            'language' => 'xx',
            'source_name' => 'Fixture profile resource coverage',
            'provenance' => 'fixture-profile-resource-coverage',
            'weight' => '0.5',
        ]);
        unset($options['data_kind']);

        $result = run_language_fts_importer(
            'membership-tsv',
            language_fts_import_fixture_path('membership.tsv'),
            $profile_tree['language_dir'],
            $options
        );
        assert_same(0, $result['exit_code'], 'membership-tsv importer exits successfully without data_kind. Output: ' . $result['output']);

        $metadata = require $profile_tree['language_dir'] . DIRECTORY_SEPARATOR . 'pack.php';
        assert_same('curated_seed', $metadata['data_kind'] ?? null, 'Omitted importer data_kind defaults to safer curated seed metadata.');
        assert_same(
            ['profile.php', 'stopwords.txt', 'lexemes.tsv', 'synonyms.tsv', 'synsets.tsv'],
            $metadata['files'] ?? null,
            'Generated pack metadata lists every profile-declared runtime resource.'
        );

        $report = (new Language_FTS_Playground_Lexical_Pack_Validator($profile_tree['root']))->validate_all();
        assert_same(true, $report['valid'], 'Generated importer metadata passes lexical pack validation.');
    } finally {
        remove_language_fts_temp_tree($profile_tree['root']);
    }
});

test_case('comprehensive importer requires audit metadata and source digest match', function (): void {
    $input_path = language_fts_import_fixture_path('membership.tsv');
    $profile_tree = create_language_fts_temp_import_profile('en');
    file_put_contents($profile_tree['language_dir'] . DIRECTORY_SEPARATOR . 'LICENSE.fixture.txt', "Fixture license text.\n");

    try {
        $missing_metadata = run_language_fts_importer(
            'membership-tsv',
            $input_path,
            $profile_tree['language_dir'],
            language_fts_import_options([
                'data_kind' => 'imported_comprehensive',
                'provenance' => 'fixture-comprehensive-import',
            ])
        );
        assert_true($missing_metadata['exit_code'] !== 0, 'Comprehensive imports missing audit metadata exit nonzero.');
        assert_contains_text('Missing required comprehensive metadata option: --source-version', $missing_metadata['output'], 'Missing comprehensive metadata reports the first required option.');

        $digest_mismatch = run_language_fts_importer(
            'membership-tsv',
            $input_path,
            $profile_tree['language_dir'],
            language_fts_comprehensive_import_options($input_path, [
                'provenance' => 'fixture-comprehensive-import',
                'source_artifact_sha256' => str_repeat('c', 64),
            ])
        );
        assert_true($digest_mismatch['exit_code'] !== 0, 'Comprehensive imports with a source digest mismatch exit nonzero.');
        assert_contains_text('Source artifact sha256 mismatch', $digest_mismatch['output'], 'Source artifact digest mismatch is reported.');
    } finally {
        remove_language_fts_temp_tree($profile_tree['root']);
    }
});

test_case('comprehensive importer writes deterministic v2 audit metadata', function (): void {
    $input_path = language_fts_import_fixture_path('membership.tsv');
    $first_tree = create_language_fts_temp_import_profile('en');
    $second_tree = create_language_fts_temp_import_profile('en');
    file_put_contents($first_tree['language_dir'] . DIRECTORY_SEPARATOR . 'LICENSE.fixture.txt', "Fixture license text.\n");
    file_put_contents($second_tree['language_dir'] . DIRECTORY_SEPARATOR . 'LICENSE.fixture.txt', "Fixture license text.\n");

    $options = language_fts_comprehensive_import_options($input_path, [
        'source_name' => 'Deterministic comprehensive fixture',
        'source_url' => 'https://example.test/deterministic-comprehensive',
        'license_name' => 'Fixture License 1.0',
        'attribution' => 'Deterministic comprehensive fixture attribution.',
        'pack_version' => 'fixture-comprehensive-import-v1',
        'pack_date' => '2026-06-09',
        'provenance' => 'fixture-comprehensive-import',
        'weight' => '0.5',
    ]);

    try {
        $first = run_language_fts_importer('membership-tsv', $input_path, $first_tree['language_dir'], $options);
        $second = run_language_fts_importer('membership-tsv', $input_path, $second_tree['language_dir'], $options);

        assert_same(0, $first['exit_code'], 'First comprehensive import exits successfully. Output: ' . $first['output']);
        assert_same(0, $second['exit_code'], 'Second comprehensive import exits successfully. Output: ' . $second['output']);
        assert_same(file_get_contents($first_tree['language_dir'] . DIRECTORY_SEPARATOR . 'synsets.tsv'), file_get_contents($second_tree['language_dir'] . DIRECTORY_SEPARATOR . 'synsets.tsv'), 'Comprehensive synsets output is deterministic.');
        assert_same(file_get_contents($first_tree['language_dir'] . DIRECTORY_SEPARATOR . 'lexemes.tsv'), file_get_contents($second_tree['language_dir'] . DIRECTORY_SEPARATOR . 'lexemes.tsv'), 'Comprehensive lexemes output is deterministic.');
        assert_same(file_get_contents($first_tree['language_dir'] . DIRECTORY_SEPARATOR . 'pack.php'), file_get_contents($second_tree['language_dir'] . DIRECTORY_SEPARATOR . 'pack.php'), 'Comprehensive pack metadata output is deterministic.');

        $metadata = require $first_tree['language_dir'] . DIRECTORY_SEPARATOR . 'pack.php';
        assert_same(Language_FTS_Playground_Lexical_Pack_Validator::METADATA_SCHEMA_V2, $metadata['metadata_schema'] ?? null, 'Comprehensive importer writes the v2 metadata schema.');
        assert_same('imported_comprehensive', $metadata['data_kind'] ?? null, 'Comprehensive importer labels comprehensive output explicitly.');
        assert_same('fixture-comprehensive-import', $metadata['provenance'] ?? null, 'Comprehensive importer writes the requested provenance.');
        assert_true(isset($metadata['source'], $metadata['license'], $metadata['provenance_ids'], $metadata['normalization'], $metadata['importer'], $metadata['runtime_files']), 'Comprehensive importer writes all nested audit sections.');
        assert_true(in_array('LICENSE.fixture.txt', $metadata['files'] ?? [], true), 'Comprehensive metadata lists the local license text file.');
        assert_contains_text('profile.php', implode("\n", array_column((array) ($metadata['runtime_files'] ?? []), 'file')), 'Comprehensive metadata includes profile.php runtime digest.');
        assert_not_contains_text($first_tree['language_dir'], (string) ($metadata['importer']['command'] ?? ''), 'Importer command does not include the first temp output path.');
        assert_not_contains_text($second_tree['language_dir'], (string) ($metadata['importer']['command'] ?? ''), 'Importer command does not include the second temp output path.');

        $report = (new Language_FTS_Playground_Lexical_Pack_Validator($first_tree['root']))->validate_all();
        assert_same(true, $report['valid'], 'Generated comprehensive metadata validates cleanly.');
    } finally {
        remove_language_fts_temp_tree($first_tree['root']);
        remove_language_fts_temp_tree($second_tree['root']);
    }
});

test_case('OpenThesaurus text importer compiles a German synset row', function (): void {
    $output_dir = create_language_fts_temp_dir('language-fts-openthesaurus-import');

    try {
        $result = run_language_fts_importer(
            'openthesaurus-text',
            language_fts_import_fixture_path('openthesaurus.txt'),
            $output_dir,
            language_fts_import_options([
                'language' => 'de',
                'source_name' => 'OpenThesaurus German fixture',
                'source_url' => 'https://www.openthesaurus.de/about/download',
                'license_name' => 'CC BY-SA 4.0 or LGPL',
                'attribution' => 'OpenThesaurus-style German fixture data.',
                'provenance' => 'fixture-openthesaurus',
                'weight' => '0.64',
            ])
        );
        assert_same(0, $result['exit_code'], 'openthesaurus-text importer exits successfully. Output: ' . $result['output']);
        assert_same(
            "# concept_id\tweight\tprovenance\tterms\nopenthesaurus.line-000001\t0.64\tfixture-openthesaurus\tfinden suche suchen\n",
            file_get_contents($output_dir . DIRECTORY_SEPARATOR . 'synsets.tsv'),
            'OpenThesaurus-style groups become deterministic German synsets.'
        );
    } finally {
        remove_language_fts_temp_tree($output_dir);
    }
});

test_case('OpenThesaurus importer preserves ambiguous German source groups deterministically', function (): void {
    $output_dir = create_language_fts_temp_dir('language-fts-openthesaurus-ambiguity-import');

    try {
        $result = run_language_fts_importer(
            'openthesaurus-text',
            language_fts_import_fixture_path('openthesaurus-ambiguity.txt'),
            $output_dir,
            language_fts_import_options([
                'language' => 'de',
                'source_name' => 'OpenThesaurus ambiguity fixture',
                'source_url' => 'https://www.openthesaurus.de/about/download',
                'license_name' => 'CC BY-SA 4.0 or LGPL',
                'attribution' => 'Synthetic OpenThesaurus-style ambiguity fixture data.',
                'provenance' => 'fixture-openthesaurus-ambiguity',
                'weight' => '0.61',
            ])
        );
        assert_same(0, $result['exit_code'], 'openthesaurus-text ambiguity import exits successfully. Output: ' . $result['output']);
        assert_same(
            "# concept_id\tweight\tprovenance\tterms\n" .
            "openthesaurus.line-000002\t0.61\tfixture-openthesaurus-ambiguity\tbank geldinstitut kreditinstitut\n" .
            "openthesaurus.line-000003\t0.61\tfixture-openthesaurus-ambiguity\tbank parkbank sitzbank\n" .
            "openthesaurus.line-000004\t0.61\tfixture-openthesaurus-ambiguity\tfinden recherche suche suchen\n",
            file_get_contents($output_dir . DIRECTORY_SEPARATOR . 'synsets.tsv'),
            'Repeated ambiguous source terms stay in distinct source-line concepts.'
        );
    } finally {
        remove_language_fts_temp_tree($output_dir);
    }
});

test_case('simple WordNet JSON fixture compiles an English synset row', function (): void {
    $output_dir = create_language_fts_temp_dir('language-fts-wordnet-import');

    try {
        $result = run_language_fts_importer(
            'wordnet-json',
            language_fts_import_fixture_path('wordnet.json'),
            $output_dir,
            language_fts_import_options([
                'language' => 'en',
                'source_name' => 'Simple WordNet-like fixture',
                'source_url' => 'https://example.test/simple-wordnet-json',
                'license_name' => 'CC-BY 4.0',
                'attribution' => 'Simple WordNet-like fixture data.',
                'provenance' => 'fixture-wordnet',
            ])
        );
        assert_same(0, $result['exit_code'], 'wordnet-json importer exits successfully. Output: ' . $result['output']);
        assert_same(
            "# concept_id\tweight\tprovenance\tterms\noewn-search-v-0001\t0.71\tfixture-wordnet\tlook search seek\n",
            file_get_contents($output_dir . DIRECTORY_SEPARATOR . 'synsets.tsv'),
            'WordNet-style member arrays become deterministic English synsets.'
        );
        assert_same(
            "# observed\tcanonical\tprovenance\nsearching\tsearch\tfixture-wordnet\n",
            file_get_contents($output_dir . DIRECTORY_SEPARATOR . 'lexemes.tsv'),
            'WordNet-style observed forms become lexeme rows.'
        );
    } finally {
        remove_language_fts_temp_tree($output_dir);
    }
});

test_case('Global Wordnet JSON-LD importer resolves synset member IDs through lexical entries', function (): void {
    $output_dir = create_language_fts_temp_dir('language-fts-wordnet-jsonld-import');

    try {
        $result = run_language_fts_importer(
            'wordnet-json',
            language_fts_import_fixture_path('wordnet-jsonld.json'),
            $output_dir,
            language_fts_import_options([
                'language' => 'en',
                'source_name' => 'Open English WordNet JSON-LD fixture',
                'source_url' => 'https://globalwordnet.github.io/schemas/#json',
                'license_name' => 'CC-BY 4.0',
                'attribution' => 'Open English WordNet-shaped JSON-LD fixture data.',
                'provenance' => 'fixture-wordnet-jsonld',
            ])
        );
        assert_same(0, $result['exit_code'], 'wordnet-json JSON-LD importer exits successfully. Output: ' . $result['output']);

        $synsets = file_get_contents($output_dir . DIRECTORY_SEPARATOR . 'synsets.tsv');
        assert_same(
            "# concept_id\tweight\tprovenance\tterms\noewn-search-v-0001\t0.71\tfixture-wordnet-jsonld\tlook search seek\n",
            $synsets,
            'Global Wordnet JSON-LD member IDs become deterministic lexical terms.'
        );
        assert_not_contains_text('oewn-search-v-0001-01', $synsets, 'Resolved JSON-LD synsets do not write member IDs as searchable terms.');
        assert_not_contains_text('oewn-seek-v-0001-01', $synsets, 'Resolved JSON-LD synsets do not write sense IDs as searchable terms.');
    } finally {
        remove_language_fts_temp_tree($output_dir);
    }
});

test_case('Global Wordnet JSON-LD importer resolves object-map source excerpts deterministically', function (): void {
    $output_dir = create_language_fts_temp_dir('language-fts-wordnet-jsonld-source-shaped-import');

    try {
        $result = run_language_fts_importer(
            'wordnet-json',
            language_fts_import_fixture_path('wordnet-jsonld-source-shaped.json'),
            $output_dir,
            language_fts_import_options([
                'language' => 'en',
                'source_name' => 'Open English WordNet source-shaped fixture',
                'source_url' => 'https://globalwordnet.github.io/schemas/#json',
                'license_name' => 'CC-BY 4.0',
                'attribution' => 'Synthetic Open English WordNet-shaped JSON-LD fixture data.',
                'provenance' => 'fixture-wordnet-jsonld-source-shaped',
            ])
        );
        assert_same(0, $result['exit_code'], 'wordnet-json source-shaped JSON-LD importer exits successfully. Output: ' . $result['output']);

        $synsets = file_get_contents($output_dir . DIRECTORY_SEPARATOR . 'synsets.tsv');
        assert_same(
            "# concept_id\tweight\tprovenance\tterms\noewn-index-v-0001\t0.68\tfixture-wordnet-jsonld-source-shaped\tcatalog index list\n",
            $synsets,
            'Object-map lexical entries, mixed member refs, and confidenceScore compile deterministically.'
        );
        assert_not_contains_text('oewn-index-v-0001-01', $synsets, 'Object member refs are resolved before runtime synset output.');
        assert_not_contains_text('oewn-catalog-v-0001-01', $synsets, 'Target member refs are resolved before runtime synset output.');
    } finally {
        remove_language_fts_temp_tree($output_dir);
    }
});

test_case('Global Wordnet JSON-LD importer rejects unresolved member IDs', function (): void {
    $output_dir = create_language_fts_temp_dir('language-fts-wordnet-jsonld-broken-import');

    try {
        $result = run_language_fts_importer(
            'wordnet-json',
            language_fts_import_fixture_path('wordnet-jsonld-unresolved-member.json'),
            $output_dir,
            language_fts_import_options([
                'language' => 'en',
                'source_name' => 'Broken Open English WordNet JSON-LD fixture',
                'source_url' => 'https://globalwordnet.github.io/schemas/#json',
                'license_name' => 'CC-BY 4.0',
                'attribution' => 'Broken JSON-LD fixture data.',
                'provenance' => 'fixture-wordnet-jsonld-broken',
            ])
        );

        assert_true($result['exit_code'] !== 0, 'Unresolved JSON-LD member IDs exit nonzero.');
        assert_contains_text('member oewn-missing-v-0001-01 could not be resolved to a lexical written form', $result['output'], 'Unresolved JSON-LD member IDs report the missing mapping.');
    } finally {
        remove_language_fts_temp_tree($output_dir);
    }
});

test_case('Global Wordnet JSON-LD importer rejects malformed lexical entry containers', function (): void {
    $output_dir = create_language_fts_temp_dir('language-fts-wordnet-jsonld-malformed-import');

    try {
        $result = run_language_fts_importer(
            'wordnet-json',
            language_fts_import_fixture_path('wordnet-jsonld-malformed-entry.json'),
            $output_dir,
            language_fts_import_options([
                'language' => 'en',
                'source_name' => 'Malformed Open English WordNet JSON-LD fixture',
                'source_url' => 'https://globalwordnet.github.io/schemas/#json',
                'license_name' => 'CC-BY 4.0',
                'attribution' => 'Malformed JSON-LD fixture data.',
                'provenance' => 'fixture-wordnet-jsonld-malformed',
            ])
        );

        assert_true($result['exit_code'] !== 0, 'Malformed JSON-LD entry containers exit nonzero.');
        assert_contains_text('wordnet-json lexical entries must be an array or object', $result['output'], 'Malformed JSON-LD entry shape reports the source family problem.');
    } finally {
        remove_language_fts_temp_tree($output_dir);
    }
});

test_case('WordNet JSON importer rejects scalar sense IDs without lexical entries', function (): void {
    $output_dir = create_language_fts_temp_dir('language-fts-wordnet-unresolved-import');

    try {
        $result = run_language_fts_importer(
            'wordnet-json',
            language_fts_import_fixture_path('wordnet-unresolved-members.json'),
            $output_dir,
            language_fts_import_options([
                'language' => 'en',
                'source_name' => 'Unresolved WordNet member fixture',
                'source_url' => 'https://globalwordnet.github.io/schemas/#json',
                'license_name' => 'CC-BY 4.0',
                'attribution' => 'Broken scalar member fixture data.',
                'provenance' => 'fixture-wordnet-unresolved',
            ])
        );

        assert_true($result['exit_code'] !== 0, 'Unresolved scalar sense IDs exit nonzero.');
        assert_contains_text('member example-en-10161911-n-1 could not be resolved to a lexical written form', $result['output'], 'Unresolved scalar sense IDs report the missing mapping.');
    } finally {
        remove_language_fts_temp_tree($output_dir);
    }
});

test_case('plWordNet source-shaped membership import feeds repository and validator', function (): void {
    $profile_tree = create_language_fts_temp_import_profile('plx');

    try {
        $result = run_language_fts_importer(
            'wordnet-membership-tsv',
            language_fts_import_fixture_path('plwordnet-export-membership.tsv'),
            $profile_tree['language_dir'],
            language_fts_import_options([
                'language' => 'plx',
                'source_name' => 'plWordNet export fixture',
                'source_url' => 'https://clarin-pl.eu/license/plwordnet',
                'license_name' => 'plWordNet license',
                'attribution' => 'Synthetic plWordNet-style membership export fixture data.',
                'provenance' => 'fixture-plwordnet-export',
                'data_kind' => 'curated_seed',
            ])
        );
        assert_same(0, $result['exit_code'], 'wordnet-membership-tsv source-shaped import exits successfully. Output: ' . $result['output']);
        assert_same(
            "# concept_id\tweight\tprovenance\tterms\n" .
            "plwn-control-1\t0.58\tfixture-plwordnet-export\tkierowac prowadzic zarzadzac\n" .
            "plwn-find-1\t0.66\tfixture-plwordnet-export\todnajdywac szukac wyszukiwac\n",
            file_get_contents($profile_tree['language_dir'] . DIRECTORY_SEPARATOR . 'synsets.tsv'),
            'plWordNet-style membership rows preserve per-concept weights and deterministic canonical keys.'
        );
        assert_same(
            "# observed\tcanonical\tprovenance\n" .
            "kierowaniu\tkierowac\tfixture-plwordnet-export\n" .
            "odnajdywanie\todnajdywac\tfixture-plwordnet-export\n" .
            "prowadzeniu\tprowadzic\tfixture-plwordnet-export\n" .
            "szukaj\tszukac\tfixture-plwordnet-export\n" .
            "wyszukiwane\twyszukiwac\tfixture-plwordnet-export\n" .
            "zarzadzania\tzarzadzac\tfixture-plwordnet-export\n",
            file_get_contents($profile_tree['language_dir'] . DIRECTORY_SEPARATOR . 'lexemes.tsv'),
            'plWordNet-style observed forms become deterministic lexeme mappings.'
        );

        $repository = new Language_FTS_Playground_Lexical_Profile_Repository($profile_tree['root']);
        $profile = $repository->profile('plx');
        assert_same(['prowadzic'], $profile['lexemes']['prowadzeniu'] ?? [], 'Generated observed forms are consumable by the profile repository.');
        assert_same(['prowadzic', 'zarzadzac'], array_column($profile['synonyms']['kierowac'] ?? [], 'term'), 'Generated source-shaped synsets feed repository query expansions.');

        $normal_validator = run_language_fts_validator([
            'resource_root' => $profile_tree['root'],
            'json' => true,
        ], false);
        $no_ini_validator = run_language_fts_validator([
            'resource_root' => $profile_tree['root'],
            'json' => true,
        ]);

        assert_same(0, $normal_validator['exit_code'], 'Generated source-shaped pack validates under normal PHP. Output: ' . $normal_validator['output']);
        assert_same(0, $no_ini_validator['exit_code'], 'Generated source-shaped pack validates under php -n. Output: ' . $no_ini_validator['output']);
        $normal_report = json_decode($normal_validator['output'], true);
        $no_ini_report = json_decode($no_ini_validator['output'], true);
        assert_true(is_array($normal_report), 'Normal PHP validator JSON is parseable.');
        assert_true(is_array($no_ini_report), 'php -n validator JSON is parseable.');
        assert_same(true, $normal_report['valid'] ?? null, 'Normal PHP validator marks generated source-shaped pack valid.');
        assert_same(true, $no_ini_report['valid'] ?? null, 'php -n validator marks generated source-shaped pack valid.');
        $normal_by_id = language_fts_pack_status_by_id($normal_report);
        assert_same(6, $normal_by_id['plx']['counts']['lexeme_rows'] ?? null, 'Validator counts generated plWordNet observed-form rows.');
        assert_same(2, $normal_by_id['plx']['counts']['synset_rows'] ?? null, 'Validator counts generated plWordNet synset rows.');
    } finally {
        remove_language_fts_temp_tree($profile_tree['root']);
    }
});

test_case('plWordNet membership importer compiles Polish synsets and metadata', function (): void {
    $output_dir = create_language_fts_temp_dir('language-fts-plwordnet-import');

    try {
        $result = run_language_fts_importer(
            'wordnet-membership-tsv',
            language_fts_import_fixture_path('plwordnet-membership.tsv'),
            $output_dir,
            language_fts_import_options([
                'language' => 'pl',
                'source_name' => 'plWordNet fixture',
                'source_url' => 'https://clarin-pl.eu/license/plwordnet',
                'license_name' => 'plWordNet license',
                'attribution' => 'plWordNet-style pre-extracted membership fixture data.',
                'provenance' => 'fixture-plwordnet',
            ])
        );
        assert_same(0, $result['exit_code'], 'wordnet-membership-tsv importer exits successfully. Output: ' . $result['output']);
        assert_same(
            "# concept_id\tweight\tprovenance\tterms\nplwn-szukac-1\t0.62\tfixture-plwordnet\todnajdywac szukac wyszukiwac\n",
            file_get_contents($output_dir . DIRECTORY_SEPARATOR . 'synsets.tsv'),
            'plWordNet-style membership rows become deterministic Polish synsets.'
        );

        $metadata = require $output_dir . DIRECTORY_SEPARATOR . 'pack.php';
        assert_same('pl', $metadata['language_id'] ?? null, 'Generated plWordNet metadata declares the language.');
        assert_same('plWordNet fixture', $metadata['source_name'] ?? null, 'Generated plWordNet metadata declares the source.');
        assert_same('plWordNet license', $metadata['license_name'] ?? null, 'Generated plWordNet metadata declares the license.');
        assert_same('fixture-plwordnet', $metadata['provenance'] ?? null, 'Generated plWordNet metadata declares provenance.');
        assert_same('curated_seed', $metadata['data_kind'] ?? null, 'Fixture metadata does not claim comprehensive data.');
    } finally {
        remove_language_fts_temp_tree($output_dir);
    }
});

test_case('malformed lexical imports fail with clear nonzero CLI output', function (): void {
    $output_dir = create_language_fts_temp_dir('language-fts-malformed-import');

    try {
        $malformed = run_language_fts_importer(
            'membership-tsv',
            language_fts_import_fixture_path('malformed-membership.tsv'),
            $output_dir,
            language_fts_import_options(['provenance' => 'fixture-malformed'])
        );
        assert_true($malformed['exit_code'] !== 0, 'Malformed membership input exits nonzero.');
        assert_contains_text('membership rows must have 2 to 4 tab-separated columns', $malformed['output'], 'Malformed membership row reports the row shape problem.');

        $invalid_weight = run_language_fts_importer(
            'membership-tsv',
            language_fts_import_fixture_path('membership.tsv'),
            $output_dir,
            language_fts_import_options([
                'provenance' => 'fixture-invalid-weight',
                'weight' => '1.25',
            ])
        );
        assert_true($invalid_weight['exit_code'] !== 0, 'Invalid importer weight exits nonzero.');
        assert_contains_text('weight must be greater than 0 and no more than 1', $invalid_weight['output'], 'Invalid importer weight reports its valid range.');

        $malformed_openthesaurus = run_language_fts_importer(
            'openthesaurus-text',
            language_fts_import_fixture_path('openthesaurus-malformed.txt'),
            $output_dir,
            language_fts_import_options([
                'language' => 'de',
                'provenance' => 'fixture-openthesaurus-malformed',
            ])
        );
        assert_true($malformed_openthesaurus['exit_code'] !== 0, 'Malformed OpenThesaurus input exits nonzero.');
        assert_contains_text('OpenThesaurus rows must contain at least 2 delimiter-separated terms', $malformed_openthesaurus['output'], 'Malformed OpenThesaurus rows report the row shape problem.');

        $conflicting_plwordnet = run_language_fts_importer(
            'wordnet-membership-tsv',
            language_fts_import_fixture_path('plwordnet-membership-conflicting-weight.tsv'),
            $output_dir,
            language_fts_import_options([
                'language' => 'pl',
                'provenance' => 'fixture-plwordnet-conflicting-weight',
            ])
        );
        assert_true($conflicting_plwordnet['exit_code'] !== 0, 'Conflicting plWordNet membership weights exit nonzero.');
        assert_contains_text('conflicting weights for concept plwn-conflict-1', $conflicting_plwordnet['output'], 'Conflicting plWordNet membership weights report the concept id.');
    } finally {
        remove_language_fts_temp_tree($output_dir);
    }
});

test_case('generated lexical imports can be consumed by the profile repository', function (): void {
    $profile_tree = create_language_fts_temp_import_profile('xx');

    try {
        $result = run_language_fts_importer(
            'membership-tsv',
            language_fts_import_fixture_path('membership.tsv'),
            $profile_tree['language_dir'],
            language_fts_import_options([
                'language' => 'xx',
                'source_name' => 'Repository consumption fixture',
                'provenance' => 'fixture-repository-consumption',
                'weight' => '0.5',
            ])
        );
        assert_same(0, $result['exit_code'], 'Generated profile import exits successfully. Output: ' . $result['output']);

        $repository = new Language_FTS_Playground_Lexical_Profile_Repository($profile_tree['root']);
        $profile = $repository->profile('xx');
        assert_same(['find', 'lookup'], array_column($profile['synonyms']['search'] ?? [], 'term'), 'Generated synsets.tsv loads into repository query expansions.');

        $metadata = $repository->pack_metadata('xx');
        assert_same('Repository consumption fixture', $metadata['source_name'], 'Generated pack metadata can be loaded by the repository accessor.');
        assert_same('fixture-repository-consumption', $metadata['provenance'], 'Generated pack provenance can be loaded by the repository accessor.');
    } finally {
        remove_language_fts_temp_tree($profile_tree['root']);
    }
});

test_case('lexical resource docs keep comprehensive source caveats explicit', function (): void {
    $docs = file_get_contents(__DIR__ . '/../docs/lexical-resources.md');
    $readme = file_get_contents(__DIR__ . '/../README.md');
    assert_true(is_string($docs), 'Lexical resource docs can be read.');
    assert_true(is_string($readme), 'Language FTS README can be read.');

    assert_contains_text('curated seed', $docs, 'Lexical docs describe shipped resources as seed data.');
    assert_contains_text('not a comprehensive synonym database', $docs, 'Lexical docs do not imply comprehensive databases are shipped.');
    assert_contains_text('Open English WordNet', $docs, 'Lexical docs mention Open English WordNet source caveats.');
    assert_contains_text('JSON-LD excerpts with an `@graph`', $docs, 'Lexical docs describe the supported WordNet JSON-LD shape.');
    assert_contains_text('simple project fixture', $docs, 'Lexical docs distinguish simple WordNet-like fixtures from JSON-LD source excerpts.');
    assert_contains_text('OpenThesaurus', $docs, 'Lexical docs mention OpenThesaurus source caveats.');
    assert_contains_text('plWordNet', $docs, 'Lexical docs mention plWordNet source caveats.');
    assert_contains_text('validate-lexical-packs.php', $docs, 'Lexical docs describe the validation CLI.');
    assert_contains_text('evaluate-lexical-pack.php', $docs, 'Lexical docs describe the relevance evaluator CLI.');
    assert_contains_text('synonym_phrases.tsv', $docs, 'Lexical docs describe synonym phrase resources.');
    assert_contains_text('full text search -> fts', $docs, 'Lexical docs describe the committed phrase synonym smoke fixture.');
    assert_contains_text('Morphology Fixture Compiler', $docs, 'Lexical docs describe the morphology fixture compiler.');
    assert_contains_text('language-fts-playground-morphology-fixture-v1', $docs, 'Lexical docs name the morphology fixture schema.');
    assert_contains_text('not Snowball compliance tests', $docs, 'Lexical docs keep morphology fixture scope sample-only.');
    assert_contains_text('--max-synset-size', $docs, 'Lexical docs describe synset size thresholds.');
    assert_contains_text('recall@5', $docs, 'Lexical docs describe evaluator relevance metrics.');
    assert_contains_text('Broad synsets are dangerous', $docs, 'Lexical docs explain broad synset search-quality risk.');
    assert_contains_text('Lexical pack status', $docs, 'Lexical docs explain the admin pack status table.');
    assert_contains_text('seed data unless', $readme, 'README keeps the shipped-data limitation explicit.');
    assert_contains_text('validate-lexical-packs.php', $readme, 'README documents the validation CLI.');
    assert_contains_text('evaluate-lexical-pack.php', $readme, 'README documents the relevance evaluator CLI.');
    assert_contains_text('search-benchmark-counters.php', $readme, 'README documents the search benchmark counter CLI.');
    assert_contains_text('synonym_phrases.tsv', $readme, 'README documents phrase synonym resources.');
    assert_contains_text('curated_seed', $readme, 'README confirms current shipped packs are curated seed data.');
});

test_case('WordPress.org readme metadata stays aligned with plugin headers', function (): void {
    $plugin_file = file_get_contents(__DIR__ . '/../language-fts-playground.php');
    $readme = file_get_contents(__DIR__ . '/../readme.txt');
    assert_true(is_string($plugin_file), 'Plugin file can be read.');
    assert_true(is_string($readme), 'WordPress.org readme can be read.');

    assert_contains_text('Stable tag: 0.3.0', $readme, 'WordPress.org readme stable tag matches the release.');
    assert_contains_text('Requires at least: 6.5', $readme, 'WordPress.org readme records the WordPress requirement.');
    assert_contains_text('Requires PHP: 8.1', $readme, 'WordPress.org readme records the PHP requirement.');
    assert_contains_text('License: GPL-2.0-or-later', $readme, 'WordPress.org readme records the license.');
    assert_contains_text('License URI: https://www.gnu.org/licenses/gpl-2.0.html', $readme, 'WordPress.org readme records the license URI.');
    assert_contains_text('License URI: https://www.gnu.org/licenses/gpl-2.0.html', $plugin_file, 'Plugin header records the license URI.');
    assert_contains_text('== Description ==', $readme, 'WordPress.org readme has a Description section.');
    assert_contains_text('== Installation ==', $readme, 'WordPress.org readme has an Installation section.');
    assert_contains_text('== Frequently Asked Questions ==', $readme, 'WordPress.org readme has a FAQ section.');
    assert_contains_text('== Screenshots ==', $readme, 'WordPress.org readme has a Screenshots section.');
    assert_contains_text('== Changelog ==', $readme, 'WordPress.org readme has a Changelog section.');
    $short_description = 'Demo/seed-pack full-text search playground for language-partitioned WordPress search with English, Polish, and German resources.';
    assert_contains_text("\n\n" . $short_description . "\n\n== Description ==", $readme, 'WordPress.org readme has one short description line before Description.');
    assert_true(strlen($short_description) <= 150, 'WordPress.org readme short description is at most 150 characters.');
    assert_true(!preg_match('/[<>\[\]`*_]/', $short_description), 'WordPress.org readme short description has no markup characters.');
    assert_contains_text('demo/seed-pack release candidate', $readme, 'WordPress.org readme keeps demo/seed-pack scope explicit.');
    assert_contains_text('direct ZIP artifact', $readme, 'WordPress.org readme keeps direct-ZIP distribution scope explicit.');
    assert_contains_text('not a WordPress.org/plugin-directory release', $readme, 'WordPress.org readme avoids claiming current directory readiness.');
});

test_case('analyzer no longer ships a hardcoded query synonym map property', function (): void {
    $source = file_get_contents(__DIR__ . '/../src/Analyzer.php');
    assert_true(is_string($source), 'Analyzer source can be read.');
    assert_not_contains_text('$query_synonyms', $source, 'Analyzer does not retain the old hardcoded synonym-map property.');
    foreach (['wyszukiwac', 'szukac', 'wyszukiwarka', 'odnajdywac'] as $polish_synonym_literal) {
        assert_not_contains_text($polish_synonym_literal, $source, "Analyzer does not hardcode Polish synonym literal {$polish_synonym_literal}.");
    }
});

test_case('extracts visible text and image alt while excluding markup noise', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();
    $text = $analyzer->extract_searchable_text(
        '<article class="ghostmarkup" id="ghostmarkup">' .
        '<h2>Visible orchard</h2>' .
        '<img src="x.jpg" alt="falconalt from image" />' .
        '<style>.x{content:"ghostmarkup";}</style>' .
        '<script>const ghostmarkup = true;</script>' .
        '<!-- ghostmarkup -->' .
        '<template>ghostmarkup</template>' .
        '</article>'
    );

    assert_contains_text('Visible orchard', $text, 'Visible nodes are indexed.');
    assert_contains_text('falconalt from image', $text, 'Image alt text is indexed.');
    assert_not_contains_text('ghostmarkup', $text, 'Markup, CSS, script, comments, and templates are excluded.');
});

test_case('extracts language-aware searchable field segment details', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();
    $details = $analyzer->extract_searchable_field_segment_details(
        '<p>Searching pages stay visible.</p>' .
        '<p lang="pl">Partycja wyszukiwania pokazuje wynik.</p>' .
        '<img lang="pl" alt="polska fotografia" />',
        'en'
    );

    $content_details = array_values(array_filter(
        $details,
        static fn(array $detail): bool => $detail['field'] === 'content'
    ));
    $alt_details = array_values(array_filter(
        $details,
        static fn(array $detail): bool => $detail['field'] === 'alt'
    ));

    assert_same('polska fotografia', $alt_details[0]['text'] ?? null, 'Image alt text is extracted as an alt segment detail.');

    if (class_exists(DOMDocument::class)) {
        assert_same('Searching pages stay visible.', $content_details[0]['text'] ?? null, 'Sibling content without lang is extracted as its own content segment.');
        assert_same('en', $content_details[0]['language'] ?? null, 'Sibling content without lang inherits the fallback language.');
        assert_same('fallback', $content_details[0]['language_provenance'] ?? null, 'Sibling content without lang records fallback provenance.');
        assert_same('pl', $content_details[1]['language'] ?? null, 'Content inside p lang="pl" records the Polish segment language.');
        assert_same('html_lang', $content_details[1]['language_provenance'] ?? null, 'Content inside p lang="pl" records HTML lang provenance.');
        assert_same('pl', $alt_details[0]['language'] ?? null, 'Image alt text inherits the img lang attribute.');
        assert_same('html_lang', $alt_details[0]['language_provenance'] ?? null, 'Image alt text records HTML lang provenance.');
    } else {
        assert_contains_text('Searching pages stay visible.', $content_details[0]['text'] ?? '', 'The no-DOM fallback extracts sibling content text.');
        assert_contains_text('Partycja wyszukiwania pokazuje wynik.', $content_details[0]['text'] ?? '', 'The no-DOM fallback extracts lang-marked content text.');
        assert_same(['en'], array_values(array_unique(array_column($details, 'language'))), 'The no-DOM fallback keeps all segment details on the fallback language.');
        assert_same(['fallback'], array_values(array_unique(array_column($details, 'language_provenance'))), 'The no-DOM fallback keeps fallback provenance.');
    }
});

test_case('language-aware segment fallback provenance can represent post language', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();
    $details = $analyzer->extract_searchable_field_segment_details('<p>Visible orchard</p>', 'en', 'post');

    assert_same('en', $details[0]['language'] ?? null, 'Fallback segment language is canonicalized.');
    assert_same('post', $details[0]['language_provenance'] ?? null, 'Callers can mark fallback segments as post-language provenance.');
});

test_case('language-aware segment details inherit xml lang through descendants', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();
    $details = $analyzer->extract_searchable_field_segment_details(
        '<section xml:lang="pl"><span>Dziedziczony segment.</span></section>',
        'en'
    );

    assert_same('Dziedziczony segment.', $details[0]['text'] ?? null, 'Descendant text is extracted from an xml:lang ancestor.');
    if (class_exists(DOMDocument::class)) {
        assert_same('pl', $details[0]['language'] ?? null, 'Descendant text inherits the xml:lang language.');
        assert_same('html_lang', $details[0]['language_provenance'] ?? null, 'Descendant text records xml:lang as HTML lang provenance.');
    } else {
        assert_same('en', $details[0]['language'] ?? null, 'The no-DOM fallback keeps xml:lang content on the fallback language.');
        assert_same('fallback', $details[0]['language_provenance'] ?? null, 'The no-DOM fallback keeps fallback provenance for xml:lang content.');
    }
});

test_case('normalizes supported languages deterministically', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();

    assert_same(['orchard'], $analyzer->analyze_text('ORCHARD', 'en'), 'English terms are lowercased.');
    assert_same(['lodz'], $analyzer->analyze_text('Łódź', 'pl'), 'Polish diacritics are folded.');
    assert_same(['fuehrung', 'strasse', 'strass'], $analyzer->analyze_text('für Führung Straße', 'de'), 'German umlauts are folded and stopwords are removed.');
});

test_case('removes English stopwords and stems common English forms conservatively', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();

    assert_same([], $analyzer->analyze_text('the and of to in a an is are was were by for with', 'en'), 'Common English stopwords are not indexed.');
    assert_same(['runner'], $analyzer->analyze_text("the runner's and of", 'en'), 'English possessive noise and stopwords are removed.');
    assert_query_terms_overlap($analyzer, 'en', 'searching searched searches', 'search', 'English regular verb forms share a stem key.');
    assert_query_terms_overlap($analyzer, 'en', 'stories skies', 'story sky', 'English y/ies plural forms share stem keys.');
    assert_query_terms_overlap($analyzer, 'en', 'making baked boxes buses', 'make bake box bus', 'English dropped-e and es plural forms share stem keys.');
    assert_query_terms_overlap($analyzer, 'en', 'running stopped', 'run stop', 'English doubled-consonant verb forms are guarded and stemmed.');
    assert_query_terms_overlap($analyzer, 'en', 'falling missing missed', 'fall miss', 'English ll/ss doubled-consonant verb forms preserve their base keys.');
    assert_query_terms_overlap($analyzer, 'en', 'children people', 'child person', 'Guarded English irregular examples share stem keys.');
    foreach (['falling' => 'fall', 'missing' => 'miss', 'missed' => 'miss'] as $form => $key) {
        assert_true(
            in_array($key, $analyzer->analyze_text($form, 'en'), true),
            "English doubled-consonant form {$form} keeps the {$key} key."
        );
    }
    foreach (['falling' => 'fal', 'missing' => 'mis', 'missed' => 'mis'] as $form => $key) {
        assert_true(
            !in_array($key, $analyzer->analyze_text($form, 'en'), true),
            "English doubled-consonant form {$form} does not emit the shortened {$key} key."
        );
    }
    foreach (
        [
            'feet' => 'foot',
            'geese' => 'goose',
            'men' => 'man',
            'mice' => 'mouse',
            'teeth' => 'tooth',
            'women' => 'woman',
        ] as $plural => $singular
    ) {
        assert_true(
            in_array($singular, $analyzer->analyze_text($plural, 'en'), true),
            "English irregular plural {$plural} keeps the resource-backed {$singular} key."
        );
    }
    foreach (['trying' => 'try', 'crying' => 'cry', 'styling' => 'styl'] as $form => $key) {
        assert_true(
            in_array($key, $analyzer->analyze_text($form, 'en'), true),
            "English y-vowel verb form {$form} keeps the {$key} key."
        );
    }
    assert_query_terms_do_not_overlap($analyzer, 'en', 'runner', 'run', 'Agent nouns do not collapse to short verb stems.');
    assert_query_terms_do_not_overlap($analyzer, 'en', 'university', 'universe', 'English y-ending words are not broadly conflated.');
    assert_same(['news', 'bus', 'analysis'], $analyzer->analyze_text('news bus analysis', 'en'), 'Sensitive English words remain exact.');
});

test_case('removes German stopwords and stems German forms conservatively', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();

    assert_same([], $analyzer->analyze_text('der die das und in im zu am fuer von mit ein eine einer', 'de'), 'Common German stopwords are not indexed.');
    assert_query_terms_overlap($analyzer, 'de', 'deutschen deutscher deutsche deutsches', 'deutsch', 'German adjective forms share a stem key.');
    assert_query_terms_overlap($analyzer, 'de', 'schnelle schnellen schneller schnellem', 'schnell', 'German adjective suffixes are normalized.');
    assert_query_terms_overlap($analyzer, 'de', 'Führungen Straßen Kindern', 'fuehrung strasse kind', 'German noun plurals after folding share stem keys.');
    assert_query_terms_overlap($analyzer, 'de', 'Bäume Häuser', 'baum haus', 'German umlauted noun plurals share conservative singular keys.');
    foreach (['Räume' => 'raum', 'Träume' => 'traum'] as $plural => $singular) {
        assert_true(
            in_array($singular, $analyzer->analyze_text($plural, 'de'), true),
            "German non-demo umlauted plural {$plural} keeps the generic {$singular} key."
        );
    }
    assert_query_terms_overlap($analyzer, 'de', 'spielen spielte gespielt', 'spiel', 'German common verb endings and ge- participles share stem keys.');
    assert_query_terms_do_not_overlap($analyzer, 'de', 'gespielt', 'gespiel', 'German ge-participles do not add noisy intermediate stems.');
    assert_same(['gerechtest', 'rechtes'], $analyzer->analyze_text('gerechtest', 'de'), 'Guarded German ge-participles stop before generic est/st/t suffix rules.');
    assert_same(['gespieltest', 'spieltes'], $analyzer->analyze_text('gespieltest', 'de'), 'Guarded German ge-participles do not emit extra generic suffix keys.');
    assert_same(['gelobt', 'gelob'], $analyzer->analyze_text('gelobt', 'de'), 'Short German ge-t forms still fall through to the generic t fallback.');
    foreach (['gerecht', 'gerechte'] as $unexpected) {
        assert_true(
            !in_array($unexpected, $analyzer->analyze_text('gerechtest', 'de'), true),
            "German guarded ge-participle gerechtest does not emit {$unexpected}."
        );
    }
    foreach (['gespielt', 'gespielte'] as $unexpected) {
        assert_true(
            !in_array($unexpected, $analyzer->analyze_text('gespieltest', 'de'), true),
            "German guarded ge-participle gespieltest does not emit {$unexpected}."
        );
    }
    assert_query_terms_do_not_overlap($analyzer, 'de', 'artig', 'art', 'German ig-adjectives do not collapse to short nouns.');
    assert_same(['arm', 'arme'], $analyzer->analyze_text('arm arme', 'de'), 'Short German tokens stay guarded from broad stemming.');
});

test_case('removes Polish stopwords and stems Polish forms conservatively', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();
    $document_text = 'polskiej polskimi partycji partiami wyszukiwania wyszukiwarkach fotografiami';

    assert_same([], $analyzer->analyze_text('w i oraz na do z ze ma pod po dla', 'pl'), 'Common Polish stopwords are not indexed.');
    assert_query_terms_overlap($analyzer, 'pl', $document_text, 'polska', 'Polish adjective form shares a key with its inflected form.');
    assert_query_terms_overlap($analyzer, 'pl', $document_text, 'partycja', 'Polish noun form shares a key with its inflected form.');
    assert_query_terms_overlap($analyzer, 'pl', $document_text, 'wyszukiwanie', 'Polish verbal noun form shares a key with its inflected form.');
    assert_query_terms_overlap($analyzer, 'pl', 'domami domach domem domu', 'dom', 'Short but meaningful Polish nouns keep guarded stems.');
    assert_query_terms_overlap($analyzer, 'pl', 'zielonymi zielonego zielonych', 'zielony', 'Polish adjective endings share conservative stem keys.');
    assert_same(['ul', 'rok'], $analyzer->analyze_text('ul w rok i', 'pl'), 'Short non-stopword Polish tokens remain exact.');
    assert_query_terms_do_not_overlap($analyzer, 'pl', 'rama', 'ram', 'Polish short stems are guarded from broad final-vowel trimming.');
});

test_case('unicode words token streams match current analyzer keys across bundled languages', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();
    $cases = [
        'en' => [
            'text' => 'Searching stories 123 and boxes',
            'expected' => [
                ['surface' => 'Searching', 'normalized' => 'searching', 'keys' => ['searching', 'search'], 'position' => 0, 'start_byte' => 0, 'end_byte' => 9, 'type' => 'word', 'searchable' => true],
                ['surface' => 'stories', 'normalized' => 'stories', 'keys' => ['stories', 'story'], 'position' => 1, 'start_byte' => 10, 'end_byte' => 17, 'type' => 'word', 'searchable' => true],
                ['surface' => '123', 'normalized' => '123', 'keys' => ['123'], 'position' => 2, 'start_byte' => 18, 'end_byte' => 21, 'type' => 'number', 'searchable' => true],
                ['surface' => 'boxes', 'normalized' => 'boxes', 'keys' => ['boxes', 'box'], 'position' => 3, 'start_byte' => 26, 'end_byte' => 31, 'type' => 'word', 'searchable' => true],
            ],
        ],
        'pl' => [
            'text' => 'Łódź oraz ul 42',
            'expected' => [
                ['surface' => 'Łódź', 'normalized' => 'lodz', 'keys' => ['lodz'], 'position' => 0, 'start_byte' => 0, 'end_byte' => 7, 'type' => 'word', 'searchable' => true],
                ['surface' => 'ul', 'normalized' => 'ul', 'keys' => ['ul'], 'position' => 1, 'start_byte' => 13, 'end_byte' => 15, 'type' => 'word', 'searchable' => true],
                ['surface' => '42', 'normalized' => '42', 'keys' => ['42'], 'position' => 2, 'start_byte' => 16, 'end_byte' => 18, 'type' => 'number', 'searchable' => true],
            ],
        ],
        'de' => [
            'text' => 'Führung und 88',
            'expected' => [
                ['surface' => 'Führung', 'normalized' => 'fuehrung', 'keys' => ['fuehrung'], 'position' => 0, 'start_byte' => 0, 'end_byte' => 8, 'type' => 'word', 'searchable' => true],
                ['surface' => '88', 'normalized' => '88', 'keys' => ['88'], 'position' => 1, 'start_byte' => 13, 'end_byte' => 15, 'type' => 'number', 'searchable' => true],
            ],
        ],
    ];

    foreach ($cases as $language => $case) {
        $stream = $analyzer->analyze_token_stream($case['text'], $language);
        $summary = [];
        foreach ($stream as $token) {
            $summary[] = [
                'surface' => $token['surface'],
                'normalized' => $token['normalized'],
                'keys' => $token['keys'],
                'position' => $token['position'],
                'start_byte' => $token['start_byte'],
                'end_byte' => $token['end_byte'],
                'type' => $token['type'],
                'searchable' => $token['searchable'],
            ];
        }

        assert_same($case['expected'], $summary, "{$language} token stream exposes surfaces, normalized keys, positions, and byte offsets.");
        assert_same(array_column($case['expected'], 'keys'), $analyzer->analyze_text_token_keys($case['text'], $language), "{$language} token stream keys match analyze_text_token_keys.");

        $flattened = [];
        foreach ($case['expected'] as $expected_token) {
            array_push($flattened, ...$expected_token['keys']);
        }
        assert_same($flattened, $analyzer->analyze_text($case['text'], $language), "{$language} token stream flattening matches analyze_text.");
    }
});

test_case('token stream position gaps preserve phrase boundary behavior', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();
    $analysis = $analyzer->analyze_segments_with_positions(['alpha the beta', 'gamma'], 'en');

    assert_same(['alpha', 'beta', 'gamma'], $analysis['terms'], 'Stopwords are removed without adding in-segment positions.');
    assert_same(
        [
            'alpha' => [0],
            'beta' => [1],
            'gamma' => [3],
        ],
        $analysis['positions'],
        'Segment boundaries still insert a one-position phrase gap.'
    );
});

test_case('tokenizer stream offsets preserve snippet highlights across bundled languages', function (): void {
    $cases = [
        ['post_id' => 641, 'language' => 'en', 'title' => 'English tokenizer snippet', 'content' => '<p>Searching stories and boxes stay visible.</p>', 'query' => 'search', 'mark' => '<mark>Searching</mark>'],
        ['post_id' => 642, 'language' => 'pl', 'title' => 'Polish tokenizer snippet', 'content' => '<p>Łódź ma widoczny tekst wyszukiwania.</p>', 'query' => 'lodz', 'mark' => '<mark>Łódź</mark>'],
        ['post_id' => 643, 'language' => 'de', 'title' => 'German tokenizer snippet', 'content' => '<p>Führung zeigt sichtbaren Text.</p>', 'query' => 'fuehrung', 'mark' => '<mark>Führung</mark>'],
    ];

    foreach ($cases as $case) {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer();
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        $indexer->index_post(fixture_post($case['post_id'], $case['language'], $case['title'], $case['content']));
        $results = $searcher->search($case['query'], $case['language']);

        assert_same([$case['post_id']], array_column($results, 'post_id'), "{$case['language']} tokenizer snippet fixture is searchable.");
        assert_contains_text($case['mark'], $results[0]['snippet'] ?? '', "{$case['language']} tokenizer stream highlights the matched source token.");
    }
});

test_case('uses one analyzer path for indexed documents and queries with stopwords', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(20, 'en', 'Stopword path', '<p>The orchard and the running paths are visible.</p>'));

    assert_same([], $searcher->search('the and of', 'en'), 'Stopword-only English queries produce no matches.');
    assert_same([20], array_column($searcher->search('the run and orchard', 'en'), 'post_id'), 'Mixed English queries use the same stem and stopword keys as indexing.');
});

test_case('covers visible, alt, markup, and partition behavior across supported languages', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $matrix = [
        'en' => [
            'post_id' => 101,
            'title' => 'English matrix',
            'visible_query' => 'search',
            'alt_query' => 'story',
            'fold_query' => null,
            'noise_query' => 'ghostenglish',
            'content' =>
                '<article class="ghostenglish" id="ghostenglish">' .
                '<p>Searching through visible content shows searched and searches forms. Foreign bait: polskiej deutschen.</p>' .
                '<img alt="stories in an English image" />' .
                '<style>.ghostenglish{content:"ghostenglish";}</style>' .
                '<script>const ghostenglish = true;</script>' .
                '<!-- ghostenglish -->' .
                '<template>ghostenglish</template>' .
                '</article>',
        ],
        'pl' => [
            'post_id' => 102,
            'title' => 'Polish matrix',
            'visible_query' => 'polska',
            'alt_query' => 'fotografia',
            'fold_query' => 'lodz',
            'noise_query' => 'ghostpolish',
            'content' =>
                '<article class="ghostpolish" id="ghostpolish">' .
                '<p>Łódź ma widoczny akapit w polskiej partycji wyszukiwania. Foreign bait: searching deutschen.</p>' .
                '<img alt="polskiej fotografii" />' .
                '<style>.ghostpolish{content:"ghostpolish";}</style>' .
                '<script>const ghostpolish = true;</script>' .
                '<!-- ghostpolish -->' .
                '<template>ghostpolish</template>' .
                '</article>',
        ],
        'de' => [
            'post_id' => 103,
            'title' => 'German matrix',
            'visible_query' => 'deutsch',
            'alt_query' => 'hinweis',
            'fold_query' => 'fuehrung',
            'noise_query' => 'ghostgerman',
            'content' =>
                '<article class="ghostgerman" id="ghostgerman">' .
                '<p>Die deutschen Beispiele zeigen Führungen und suchen nach sichtbaren Treffern. Foreign bait: searching polskiej.</p>' .
                '<img alt="deutscher Hinweis für Führung" />' .
                '<style>.ghostgerman{content:"ghostgerman";}</style>' .
                '<script>const ghostgerman = true;</script>' .
                '<!-- ghostgerman -->' .
                '<template>ghostgerman</template>' .
                '</article>',
        ],
    ];

    foreach ($matrix as $language => $case) {
        $indexer->index_post(fixture_post($case['post_id'], $language, $case['title'], $case['content']));
    }

    foreach ($matrix as $language => $case) {
        assert_same([$case['post_id']], array_column($searcher->search($case['visible_query'], $language), 'post_id'), "{$language} visible content query matches its document.");
        assert_same([$case['post_id']], array_column($searcher->search($case['alt_query'], $language), 'post_id'), "{$language} image alt query matches its document.");
        if ($case['fold_query'] !== null) {
            assert_same([$case['post_id']], array_column($searcher->search($case['fold_query'], $language), 'post_id'), "{$language} folded query matches its document.");
        }

        foreach (array_keys($matrix) as $partition) {
            assert_same([], $searcher->search($case['noise_query'], $partition), "{$case['noise_query']} markup noise does not match in {$partition}.");
        }

        foreach (array_keys($matrix) as $partition) {
            if ($partition === $language) {
                continue;
            }

            assert_same([], $searcher->search($case['visible_query'], $partition), "{$language} visible query does not leak into {$partition}.");
            assert_same([], $searcher->search($case['alt_query'], $partition), "{$language} alt query does not leak into {$partition}.");
        }
    }
});

test_case('automatic search selects bundled profile-backed language candidates', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(110, 'en', 'English target', '<p>Searching pages are indexed in English.</p>'));
    $indexer->index_post(fixture_post(111, 'pl', 'Polski cel', '<p>Łódź jest indeksowana po polsku.</p>'));
    $indexer->index_post(fixture_post(112, 'de', 'Deutsches Ziel', '<p>Führung wird auf Deutsch indexiert.</p>'));
    $indexer->index_post(fixture_post(113, 'pl', 'Polish bait', '<p>Searching exact bait would match if Polish were searched.</p>'));
    $indexer->index_post(fixture_post(114, 'en', 'English bait', '<p>Łódź and Führung exact bait would match if English were queried.</p>'));

    $results = $searcher->search('searching', 'auto');
    assert_same(['en'], $storage->fetch_postings_languages, 'English lexical evidence routes auto search to the English partition.');
    assert_same([110], array_column($results, 'post_id'), 'The routed English query returns the English target.');
    assert_same('en', $results[0]['matched_language'] ?? null, 'The routed English query reports the English partition.');

    $storage->fetch_postings_languages = [];
    $results = $searcher->search('Łódź', 'auto');
    assert_same(['pl'], $storage->fetch_postings_languages, 'Polish language_signals route auto search to the Polish partition.');
    assert_same([111], array_column($results, 'post_id'), 'The routed Polish signal query returns the Polish target.');
    assert_same('pl', $results[0]['matched_language'] ?? null, 'The routed Polish signal query reports the Polish partition.');

    $storage->fetch_postings_languages = [];
    $results = $searcher->search('Führung', 'auto');
    assert_same(['de'], $storage->fetch_postings_languages, 'German language_signals route auto search to the German partition.');
    assert_same([112], array_column($results, 'post_id'), 'The routed German signal query returns the German target.');
    assert_same('de', $results[0]['matched_language'] ?? null, 'The routed German signal query reports the German partition.');
});

test_case('Polish resource-backed profile covers search commands, nouns, and searcher terms in automatic mode', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(119, 'pl', 'Polski dokument', '<p>Partycja wyszukiwania pokazuje wynik.</p>'));

    foreach (['szukaj', 'szukanie', 'wyszukiwarka', 'wyszukiwanie', 'wyszukiwania', 'odnajdywanie'] as $query) {
        $results = $searcher->search($query, 'auto');
        assert_same([119], array_column($results, 'post_id'), "Automatic Polish search for {$query} reaches the resource-backed target.");
        assert_same('pl', $results[0]['matched_language'], "Automatic Polish search for {$query} reports the matched partition.");
        assert_contains_text('<mark>wyszukiwania</mark>', $results[0]['snippet'], "Automatic Polish search for {$query} highlights the indexed source token.");
    }

    $query_terms = $analyzer->analyze_query('szukaj', 'pl');
    assert_true(in_array('szukac', $query_terms, true), 'The command form szukaj receives the canonical szukac key from lexemes.tsv.');
    $expansions = $analyzer->expand_query_synonyms($query_terms, 'pl');
    assert_true(isset($expansions['szukac']), 'The szukac canonical key expands through synsets.tsv.');
    assert_true(in_array('wyszukiwac', array_column($expansions['szukac'], 'term'), true), 'The szukac synonym target includes the canonical wyszukiwac key.');
    assert_true(in_array('odnajdywac', array_column($expansions['szukac'], 'term'), true), 'The szukac concept includes the related odnajdywac key.');
});

test_case('automatic search finds Polish synonym matches with matched language payloads', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(120, 'pl', 'Polski dokument', '<p>Partycja wyszukiwania pokazuje wynik.</p>'));

    $results = $searcher->search('szukanie', 'auto');

    assert_same([120], array_column($results, 'post_id'), 'Automatic mode searches the Polish partition for the synonym target.');
    assert_same('pl', $results[0]['matched_language'], 'The result reports the matched Polish partition.');
    assert_contains_text('szukac=>', implode(', ', $results[0]['matched_terms']), 'The resource-backed synonym relationship is reported as a query-time match.');
    assert_contains_text('<mark>wyszukiwania</mark>', $results[0]['snippet'], 'The synonym match highlights the indexed source token.');
});

test_case('mixed-language HTML lang segment preserves English and Polish recall', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(mixed_language_segment_fixture_post(140));

    $english_results = $searcher->search('searching', 'auto');
    assert_same([140], array_column($english_results, 'post_id'), 'Automatic English search still returns the mixed-language post.');
    assert_same('en', $english_results[0]['matched_language'], 'Automatic English search reports the English segment partition.');

    $polish_results = $searcher->search('szukanie', 'auto');
    assert_same([140], array_column($polish_results, 'post_id'), 'Automatic Polish synonym search reaches the HTML lang="pl" segment.');
    assert_same('pl', $polish_results[0]['matched_language'], 'Automatic Polish search reports the Polish segment partition.');
});

test_case('explicit language searches isolate mixed-language HTML lang segments', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(mixed_language_segment_fixture_post(141));

    $english_content_results = $searcher->search('searching', 'en');
    assert_same([141], array_column($english_content_results, 'post_id'), 'Explicit English search still returns the English segment.');
    assert_same('en', $english_content_results[0]['matched_language'], 'Explicit English search reports the English segment partition.');
    assert_same([], $searcher->search('szukanie', 'en'), 'Explicit English mode does not match the Polish synonym query from the HTML lang segment.');

    $polish_results = $searcher->search('szukanie', 'pl');
    assert_same([141], array_column($polish_results, 'post_id'), 'Explicit Polish mode matches the Polish HTML lang segment.');
    assert_same('pl', $polish_results[0]['matched_language'], 'Explicit Polish search reports the Polish segment partition.');
});

test_case('php-n WP_HTML_Processor runtime isolates HTML lang segment partitions', function (): void {
    $result = run_language_fts_wp_processor_lang_probe();

    assert_same(0, $result['exit_code'], 'php -n WP_HTML_Processor hybrid probe passes. Output: ' . $result['output']);
    assert_contains_text('"languages": [', $result['output'], 'The hybrid probe reports indexed language partitions.');
    assert_contains_text('"pl"', $result['output'], 'The hybrid probe indexes the Polish lang segment partition.');
});

test_case('reindex removes stale mixed-language HTML lang partitions', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(mixed_language_segment_fixture_post(142));
    $initial_polish_results = $searcher->search('szukanie', 'pl');
    assert_same([142], array_column($initial_polish_results, 'post_id'), 'The initial index writes the Polish HTML lang segment.');
    assert_same('pl', $initial_polish_results[0]['matched_language'], 'The initial Polish result reports the Polish segment partition.');

    $indexer->index_post(mixed_language_segment_fixture_post(142, false));

    assert_same([], $searcher->search('szukanie', 'pl'), 'Reindexing without the Polish segment removes stale Polish postings.');
    $english_results = $searcher->search('searching', 'en');
    assert_same([142], array_column($english_results, 'post_id'), 'Reindexing keeps the remaining English segment searchable.');
    assert_same('en', $english_results[0]['matched_language'], 'The remaining English segment keeps the English matched-language payload.');
});

test_case('explain reports HTML lang segment language provenance', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(mixed_language_segment_fixture_post(143));

    $explain = $searcher->explain('szukanie', 'auto');
    assert_same([143], array_column($explain['results'] ?? [], 'post_id'), 'Explain returns the mixed-language Polish segment hit.');
    assert_same('pl', $explain['results'][0]['matched_language'] ?? null, 'Explain result reports the Polish matched language.');

    $field_language_details = array_values(array_filter(
        language_fts_explain_field_language_details($explain['results'][0]),
        static fn(array $detail): bool => $detail['field'] === 'content'
    ));
    assert_true(in_array('pl', array_column($field_language_details, 'language'), true), 'Explain field contributions include the Polish content segment language.');
    assert_true(in_array('html_lang', array_column($field_language_details, 'language_provenance'), true), 'Explain field contributions include html_lang provenance for the Polish segment.');
});

test_case('explain reports automatic Polish routing and resource-backed synonyms', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(127, 'pl', 'Polski dokument', '<p>Partycja wyszukiwania pokazuje wynik.</p>'));

    $explain = $searcher->explain('szukanie', 'auto');
    $json = json_encode($explain);

    assert_true(is_string($json), 'Explain output is JSON serializable.');
    assert_same('szukanie', $explain['query'] ?? null, 'Explain preserves the original query.');
    assert_same('auto', $explain['requested_language'] ?? null, 'Explain records the requested language mode.');
    assert_same(['pl'], $explain['language_routing']['selected_partitions'] ?? null, 'Explain records the routed Polish partition.');
    assert_same('pl', $explain['language_routing']['ranked_candidates'][0]['language'] ?? null, 'Explain exposes Polish routing evidence.');

    $partition = $explain['partitions'][0] ?? [];
    assert_same('pl', $partition['language'] ?? null, 'Explain includes a Polish partition block.');
    assert_true(in_array('szukac', $partition['analyzed_query']['exact_terms'] ?? [], true), 'Explain includes the canonical Polish query key.');
    assert_true(in_array('wyszukiwac', $partition['lookup_terms']['single_token_synonyms'] ?? [], true), 'Explain includes the synonym target lookup term.');

    $synonym_targets = [];
    foreach ($partition['synonym_expansions'] ?? [] as $expansion) {
        if (($expansion['source_key'] ?? '') === 'szukac') {
            $synonym_targets = array_merge($synonym_targets, (array) ($expansion['target_keys'] ?? []));
            assert_same('synset', $expansion['direction'] ?? null, 'Explain records the synset expansion source kind.');
            assert_same('language-fts-playground-polish-curated-synset', $expansion['provenance'] ?? null, 'Explain records synonym provenance.');
        }
    }
    assert_true(in_array('wyszukiwac', $synonym_targets, true), 'Explain connects szukac to the indexed wyszukiwac key.');
    assert_same([127], array_column($explain['results'] ?? [], 'post_id'), 'Explain top-level results mirror the matching search result.');
    assert_true(in_array('synonym', $explain['results'][0]['match_classes'] ?? [], true), 'Explain classifies the result as a synonym match.');
});

test_case('explain reports multiword phrase synonyms and fuzzy candidates', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(128, 'en', 'FTS acronym', '<p>FTS makes local lookup visible.</p>'));
    $phrase_explain = $searcher->explain('full text search', 'en');
    $partition = $phrase_explain['partitions'][0] ?? [];
    $phrase_expansion = $partition['phrase_synonym_expansions'][0] ?? [];

    assert_same('full text search', $phrase_expansion['source_phrase'] ?? null, 'Explain records the multiword synonym source phrase.');
    assert_same('fts', $phrase_expansion['target_phrase'] ?? null, 'Explain records the multiword synonym target phrase.');
    assert_same('language-fts-playground-english-curated_seed', $phrase_expansion['provenance'] ?? null, 'Explain records synonym phrase provenance.');
    assert_same('en', $phrase_expansion['searched_language'] ?? null, 'Explain records the language that supplied the phrase synonym.');
    assert_same([128], array_column($phrase_explain['results'] ?? [], 'post_id'), 'Phrase synonym explain returns the matched FTS document.');
    assert_true(in_array('phrase_synonym', $phrase_explain['results'][0]['match_classes'] ?? [], true), 'Phrase synonym matches are classified separately.');

    $storage = new Language_FTS_Playground_Test_Storage();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);
    $indexer->index_post(fixture_post(129, 'en', 'Orchard', '<p>orchard meadow</p>'));

    $fuzzy_explain = $searcher->explain('orchrd~', 'en');
    $fuzzy_partition = $fuzzy_explain['partitions'][0] ?? [];
    $fuzzy_expansion = $fuzzy_partition['fuzzy_expansions'][0] ?? [];

    assert_same('orchrd', $fuzzy_expansion['query_term'] ?? null, 'Explain records the fuzzy source query term.');
    assert_same('orchard', $fuzzy_expansion['candidate_term'] ?? null, 'Explain records the fuzzy candidate term.');
    assert_same(1, $fuzzy_expansion['edit_distance'] ?? null, 'Explain records fuzzy edit distance.');
    assert_same('en', $fuzzy_expansion['searched_language'] ?? null, 'Explain records the fuzzy candidate language.');
    assert_true(in_array('fuzzy', $fuzzy_explain['results'][0]['match_classes'] ?? [], true), 'Fuzzy matches are classified separately.');
});

test_case('search diagnostics report lookup term caps and fail closed over the hard limit', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $safe_searcher = new Language_FTS_Playground_Searcher(
        storage: $storage,
        analyzer: $analyzer,
        max_lookup_terms: 4
    );

    $safe_explain = $safe_searcher->explain('full text search', 'en');
    $safe_caps = $safe_explain['partitions'][0]['expansion_caps'] ?? [];
    assert_same(4, $safe_caps['max_lookup_terms'] ?? null, 'Explain reports the active lookup term hard cap.');
    assert_same(4, $safe_caps['lookup_term_count'] ?? null, 'Explain reports the total lookup term count when search safely continues.');
    assert_same(false, $safe_caps['truncated'] ?? null, 'Safe diagnostics make it explicit that lookup terms were not truncated.');

    $strict_searcher = new Language_FTS_Playground_Searcher(
        storage: $storage,
        analyzer: $analyzer,
        max_lookup_terms: 3
    );
    $throwable = assert_throws(
        RuntimeException::class,
        static fn(): array => $strict_searcher->explain('full text search', 'en'),
        'Lookup term expansion over the hard cap fails closed instead of silently truncating.'
    );
    assert_contains_text('Lookup term expansion produced 4 terms, exceeding runtime cap 3', $throwable->getMessage(), 'The lookup cap failure explains the produced and allowed term counts.');
});

test_case('automatic fallback enforces lookup cap before preflight storage lookup', function (): void {
    $languages = ['qa', 'qb', 'qc', 'qd', 'qe', 'qf'];
    $profiles = [];
    foreach ($languages as $offset => $language) {
        $profiles[$language] = [
            'order' => ($offset + 1) * 10,
        ];
    }
    $root = create_language_fts_temp_profile_set($profiles);

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $storage->fail_on_fetch_term_language_hits = true;
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $searcher = new Language_FTS_Playground_Searcher(
            storage: $storage,
            analyzer: $analyzer,
            max_lookup_terms: 2
        );

        assert_same([], $analyzer->rank_query_languages('alpha beta gamma'), 'The exact-token cap regression enters no-evidence automatic fallback.');
        $throwable = assert_throws(
            RuntimeException::class,
            static fn(): array => $searcher->search('alpha beta gamma', 'auto'),
            'Over-cap exact fallback fails closed before storage preflight.'
        );
        assert_contains_text('Lookup term expansion produced 3 terms, exceeding runtime cap 2', $throwable->getMessage(), 'Exact-token fallback reports the existing lookup cap diagnostic.');
        assert_same(0, $storage->fetch_term_language_hits_count, 'Over-cap exact fallback never reaches preflight storage lookup.');

        $fuzzy_auto_storage = new Language_FTS_Playground_Test_Storage();
        $fuzzy_auto_storage->fail_on_fetch_term_language_hits = true;
        $fuzzy_auto_storage->fail_on_fetch_candidate_terms = true;
        $fuzzy_auto_searcher = new Language_FTS_Playground_Searcher(
            storage: $fuzzy_auto_storage,
            analyzer: $analyzer,
            max_lookup_terms: 2
        );

        $throwable = assert_throws(
            RuntimeException::class,
            static fn(): array => $fuzzy_auto_searcher->search('alpha beta fuzzyprobe~', 'auto'),
            'Over-cap fuzzy fallback fails closed before candidate-term storage lookup.'
        );
        assert_contains_text('Lookup term expansion produced 3 terms, exceeding runtime cap 2', $throwable->getMessage(), 'Fuzzy fallback reports the lookup cap diagnostic before candidate enumeration.');
        assert_same(0, $fuzzy_auto_storage->fetch_candidate_terms_count, 'Over-cap fuzzy fallback never reaches candidate-term lookup.');
        assert_same(0, $fuzzy_auto_storage->fetch_term_language_hits_count, 'Over-cap fuzzy fallback never reaches preflight storage lookup.');

        $fuzzy_explicit_storage = new Language_FTS_Playground_Test_Storage();
        $fuzzy_explicit_storage->fail_on_fetch_candidate_terms = true;
        $fuzzy_explicit_searcher = new Language_FTS_Playground_Searcher(
            storage: $fuzzy_explicit_storage,
            analyzer: $analyzer,
            max_lookup_terms: 2
        );

        $throwable = assert_throws(
            RuntimeException::class,
            static fn(): array => $fuzzy_explicit_searcher->search('alpha beta fuzzyprobe~', 'qa'),
            'Over-cap explicit fuzzy search fails closed before candidate-term storage lookup.'
        );
        assert_contains_text('Lookup term expansion produced 3 terms, exceeding runtime cap 2', $throwable->getMessage(), 'Explicit fuzzy search reports the lookup cap diagnostic before candidate enumeration.');
        assert_same(0, $fuzzy_explicit_storage->fetch_candidate_terms_count, 'Over-cap explicit fuzzy search never reaches candidate-term lookup.');
    } finally {
        remove_language_fts_temp_tree($root);
    }

    $profiles = [];
    foreach ($languages as $offset => $language) {
        $profiles[$language] = [
            'order' => ($offset + 1) * 10,
            'lexemes' => "# observed\tcanonical\tprovenance\nrouteprobe\trouteprobe\tfixture\n",
            'synonyms' => "# source\ttarget\tdirection\tweight\tprovenance\nrouteprobe\troutetarget\tquery_to_index\t0.7\tfixture\nrouteprobe\trouteextra\tquery_to_index\t0.6\tfixture\n",
        ];
    }
    $root = create_language_fts_temp_profile_set($profiles);

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $storage->fail_on_fetch_term_language_hits = true;
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $searcher = new Language_FTS_Playground_Searcher(
            storage: $storage,
            analyzer: $analyzer,
            max_lookup_terms: 2
        );

        $ranked = $analyzer->rank_query_languages('routeprobe', 2);
        assert_same($ranked[0]['score'] ?? null, $ranked[1]['score'] ?? null, 'The synonym-expanded cap regression enters ambiguous automatic fallback.');
        $throwable = assert_throws(
            RuntimeException::class,
            static fn(): array => $searcher->search('routeprobe', 'auto'),
            'Over-cap synonym-expanded fallback fails closed before storage preflight.'
        );
        assert_contains_text('Lookup term expansion produced 3 terms, exceeding runtime cap 2', $throwable->getMessage(), 'Expanded fallback reports the existing lookup cap diagnostic.');
        assert_same(0, $storage->fetch_term_language_hits_count, 'Over-cap expanded fallback never reaches preflight storage lookup.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('explicit Polish search finds the demo synonym target', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(121, 'pl', 'Polski dokument', '<p>Widoczna partycja wyszukiwania.</p>'));

    $results = $searcher->search('szukanie', 'pl');

    assert_same([121], array_column($results, 'post_id'), 'Explicit Polish mode applies Polish query-time synonyms.');
    assert_same('pl', $results[0]['matched_language'], 'Explicit Polish results still include the matched language payload.');
});

test_case('explicit English search does not search Polish synonym partitions', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(122, 'pl', 'Polski dokument', '<p>Widoczna partycja wyszukiwania.</p>'));

    assert_same([], $searcher->search('szukanie', 'en'), 'Explicit English mode remains a precision filter and does not search Polish.');
});

test_case('explain reports explicit-language narrowing and no-result causes', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(130, 'pl', 'Polski dokument', '<p>Widoczna partycja wyszukiwania.</p>'));

    $explain = $searcher->explain('szukanie', 'en');
    assert_same(['en'], $explain['language_routing']['selected_partitions'] ?? null, 'Explicit English explain searches only English.');
    assert_same('pl', $explain['language_routing']['ranked_candidates'][0]['language'] ?? null, 'Explain still exposes cross-language routing evidence in explicit mode.');
    assert_same([], $explain['results'] ?? null, 'Explicit English explain has no Polish synonym result.');
    assert_true(in_array('no_postings_for_searched_terms', $explain['partitions'][0]['no_result_causes'] ?? [], true), 'Explain reports missing postings in the narrowed partition.');

    $stopword_explain = $searcher->explain('the and of', 'en');
    assert_same([], $stopword_explain['partitions'][0]['analyzed_query']['exact_terms'] ?? null, 'Stopword-only explain has no searchable terms.');
    assert_true(in_array('analyzed_query_empty_after_stopwords', $stopword_explain['no_result_causes'] ?? [], true), 'Stopword-only explain reports why no terms were searched.');
});

test_case('exact Polish matches rank above synonym-only Polish matches', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(123, 'pl', 'Synonim', '<p>Partycja wyszukiwania ma tylko synonim.</p>'));
    $indexer->index_post(fixture_post(124, 'pl', 'Dokladne szukanie', '<p>Szukanie pasuje bez synonimu.</p>'));

    $results = $searcher->search('szukanie', 'pl');

    assert_true(count($results) >= 2, 'Both exact and synonym-only Polish documents match.');
    assert_same(124, $results[0]['post_id'], 'Exact/stem Polish matches rank above synonym-only matches.');
    assert_same(123, $results[1]['post_id'], 'The synonym-only match remains available below the exact match.');
});

test_case('Polish synonym expansion does not create cross-language matches', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(125, 'en', 'English bait', '<p>wyszukiwania appears in an English partition.</p>'));
    $indexer->index_post(fixture_post(126, 'de', 'German bait', '<p>wyszukiwania steht in einer deutschen Partition.</p>'));

    assert_same([], $searcher->search('szukanie', 'auto'), 'Polish synonym targets do not match English or German partitions.');
});

test_case('automatic search routes strong custom profile evidence to one fake language partition', function (): void {
    $root = create_language_fts_temp_profile_set([
        'qa' => [
            'label' => 'Fake QA',
            'order' => 10,
            'lexemes' => "# observed\tcanonical\tprovenance\nglimmering\tglimmer\tfixture\nglimmercore\tglimmer\tfixture\n",
            'synsets' => "# concept_id\tweight\tprovenance\tterms\nconcept.glimmer\t0.7\tfixture-custom-router\tglimmer glow\n",
        ],
        'qb' => [
            'label' => 'Fake QB',
            'order' => 20,
            'lexemes' => "# observed\tcanonical\tprovenance\nrippling\tripple\tfixture\nripplecore\tripple\tfixture\n",
        ],
        'qc' => [
            'label' => 'Fake QC',
            'order' => 30,
            'lexemes' => "# observed\tcanonical\tprovenance\nembering\tember\tfixture\nembercore\tember\tfixture\n",
        ],
    ]);

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        assert_same(['qa', 'qb', 'qc'], $analyzer->enabled_languages(), 'The custom resource root exposes arbitrary fake languages in profile order.');
        $ranked = $analyzer->rank_query_languages('glimmering');
        assert_same('qa', $ranked[0]['language'] ?? null, 'The fake language ranks from its own profile-backed lexeme evidence.');
        assert_true(in_array('glimmer', $ranked[0]['reasons']['synonym_sources'] ?? [], true), 'The fake language canonical key is recognized as a synset source.');

        $indexer->index_post(fixture_post(301, 'qa', 'QA target', '<p>glimmercore is indexed in the QA partition.</p>'));
        $indexer->index_post(fixture_post(302, 'qb', 'QB bait', '<p>glimmering exact bait would match if QB were searched.</p>'));
        $indexer->index_post(fixture_post(303, 'qc', 'QC bait', '<p>glimmering exact bait would match if QC were searched.</p>'));

        $results = $searcher->search('glimmering', 'auto');

        assert_same(['qa'], $storage->fetch_postings_languages, 'Confident automatic routing queries only the ranked fake language partition.');
        assert_same([301], array_column($results, 'post_id'), 'The routed fake-language search returns the selected partition result.');
        assert_same('qa', $results[0]['matched_language'] ?? null, 'The custom routed result reports its matched fake language.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('storage preflight reports term hits by language', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $storage->replace_document_partitions(
        401,
        [
            [
                'language' => 'en',
                'title' => 'English target',
                'status' => 'publish',
                'document_length' => 2,
                'field_term_frequencies' => [
                    'content' => ['orchard' => 1],
                ],
                'field_texts' => [
                    'content' => 'orchard notes',
                ],
                'term_positions' => [
                    'orchard' => [0],
                ],
            ],
            [
                'language' => 'pl',
                'title' => 'Polish target',
                'status' => 'publish',
                'document_length' => 2,
                'field_term_frequencies' => [
                    'content' => ['wyszukiw' => 1],
                ],
                'field_texts' => [
                    'content' => 'wyszukiw wynik',
                ],
                'term_positions' => [
                    'wyszukiw' => [0],
                ],
            ],
        ]
    );

    assert_true(
        method_exists($storage, 'fetch_term_language_hits'),
        'Storage preflight API fetch_term_language_hits(array $language_terms) should report which requested terms exist in each language partition.'
    );

    $hits = $storage->fetch_term_language_hits([
        'en' => ['orchard', 'wyszukiw', 'missing'],
        'pl' => ['orchard', 'wyszukiw', 'missing'],
    ]);

    assert_same(
        [
            'en' => [
                'orchard' => true,
                'wyszukiw' => false,
                'missing' => false,
            ],
            'pl' => [
                'orchard' => false,
                'wyszukiw' => true,
                'missing' => false,
            ],
        ],
        $hits,
        'Storage preflight returns per-language term hit booleans without fetching full postings payloads.'
    );
});

test_case('automatic fallback uses bounded preflight instead of scanning every language', function (): void {
    $profiles = [];
    foreach (['qa', 'qb', 'qc', 'qd', 'qe', 'qf', 'qg'] as $offset => $language) {
        $profiles[$language] = [
            'order' => ($offset + 1) * 10,
        ];
    }
    $root = create_language_fts_temp_profile_set($profiles);

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);
        $enabled = $analyzer->enabled_languages();

        assert_same('qg', $enabled[6] ?? null, 'The target fake language sits outside a small automatic fallback cap.');
        $indexer->index_post(fixture_post(402, 'qg', 'Bounded fallback target', '<p>zephyrneedle appears only in QG.</p>'));

        assert_same([], $analyzer->rank_query_languages('zephyrneedle'), 'The unknown query has no profile evidence before storage preflight.');
        $results = $searcher->search('zephyrneedle', 'auto');

        assert_same([402], array_column($results, 'post_id'), 'Bounded automatic fallback preserves unknown-term recall in later language partitions.');
        assert_same('qg', $results[0]['matched_language'] ?? null, 'The bounded fallback result reports the matched fake language.');
        assert_true(
            count($storage->fetch_postings_languages) < count($enabled),
            'Bounded preflight should avoid full fetch_postings() scans for every enabled language. Full postings languages: ' . implode(', ', $storage->fetch_postings_languages)
        );
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('automatic fallback returns results when preflight hits reach the partition cap', function (): void {
    $profiles = [];
    foreach (['qa', 'qb', 'qc', 'qd', 'qe', 'qf'] as $offset => $language) {
        $profiles[$language] = [
            'order' => ($offset + 1) * 10,
            'lexemes' => "# observed\tcanonical\tprovenance\nrouteprobe\trouteprobe\tfixture\n",
        ];
    }
    $root = create_language_fts_temp_profile_set($profiles);

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        foreach ($analyzer->enabled_languages() as $offset => $language) {
            $indexer->index_post(fixture_post(406 + (int) $offset, $language, strtoupper($language) . ' route probe', '<p>routeprobe appears in ' . strtoupper($language) . '.</p>'));
        }

        $ranked = $analyzer->rank_query_languages('routeprobe', 2);
        assert_same($ranked[0]['score'] ?? null, $ranked[1]['score'] ?? null, 'The fake routeprobe evidence is intentionally ambiguous before bounded fallback.');

        $results = $searcher->search('routeprobe', 'auto');

        assert_same(['qa', 'qb', 'qc', 'qd', 'qe'], $storage->fetch_postings_languages, 'Preflight hit-bearing fallback searches the capped selected partitions.');
        assert_same([406, 407, 408, 409, 410], array_column($results, 'post_id'), 'Public automatic search returns capped preflight-hit results instead of losing selected partitions.');
        assert_same(['qa', 'qb', 'qc', 'qd', 'qe'], array_column($results, 'matched_language'), 'Public automatic search keeps matched-language payloads for the selected hit partitions.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('automatic fallback preflight diagnostics mark order-filled partitions as selected', function (): void {
    $profiles = [];
    foreach (['qa', 'qb', 'qc', 'qd', 'qe', 'qf', 'qg'] as $offset => $language) {
        $profiles[$language] = [
            'order' => ($offset + 1) * 10,
        ];
    }
    $root = create_language_fts_temp_profile_set($profiles);

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        $indexer->index_post(fixture_post(411, 'qg', 'Order-fill diagnostic target', '<p>routefill appears only in QG.</p>'));

        $explain = $searcher->explain('routefill', 'auto');
        $selected_partitions = array_values(array_map('strval', (array) ($explain['language_routing']['selected_partitions'] ?? [])));
        $scored_languages = array_filter(
            (array) ($explain['language_routing']['preflight']['scored_languages'] ?? []),
            'is_array'
        );
        $diagnostics_by_language = [];
        foreach ($scored_languages as $candidate) {
            $diagnostics_by_language[(string) ($candidate['language'] ?? '')] = $candidate;
        }

        assert_same(['qg', 'qa', 'qb', 'qc', 'qd'], $selected_partitions, 'Bounded fallback selects the hit partition, then fills by enabled order.');
        assert_same(1, $diagnostics_by_language['qg']['hit_count'] ?? null, 'The preflight-hit partition records its exact hit.');
        assert_same(0, $diagnostics_by_language['qa']['hit_count'] ?? null, 'QA is selected only by enabled-order fill.');
        assert_same(true, $diagnostics_by_language['qa']['selected'] ?? null, 'Order-filled QA diagnostics agree with selected_partitions.');
        assert_same(false, $diagnostics_by_language['qe']['selected'] ?? null, 'Over-cap unselected partitions remain marked unselected.');

        foreach ($diagnostics_by_language as $language => $candidate) {
            assert_same(
                in_array($language, $selected_partitions, true),
                (bool) ($candidate['selected'] ?? false),
                "Preflight selected diagnostic matches selected_partitions for {$language}."
            );
        }
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('automatic fallback preflight includes opt-in fuzzy hits outside the fallback cap', function (): void {
    $profiles = [];
    foreach (['qa', 'qb', 'qc', 'qd', 'qe', 'qf', 'qg'] as $offset => $language) {
        $profiles[$language] = [
            'order' => ($offset + 1) * 10,
        ];
    }
    $root = create_language_fts_temp_profile_set($profiles);

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);
        $enabled = $analyzer->enabled_languages();

        assert_same('qg', $enabled[6] ?? null, 'The fuzzy target fake language sits outside a small automatic fallback cap.');
        $indexer->index_post(fixture_post(403, 'qg', 'Fuzzy bounded fallback target', '<p>needleterm appears only in QG.</p>'));

        assert_same([], $analyzer->rank_query_languages('needletrm~'), 'The fuzzy typo query has no profile evidence before storage preflight.');
        $results = $searcher->search('needletrm~', 'auto');

        assert_same([403], array_column($results, 'post_id'), 'Bounded automatic fallback recovers opt-in fuzzy matches in later language partitions.');
        assert_same('qg', $results[0]['matched_language'] ?? null, 'The fuzzy bounded fallback result reports the matched fake language.');
        assert_true(
            count($storage->fetch_postings_languages) < count($enabled),
            'Fuzzy preflight should avoid full fetch_postings() scans for every enabled language. Full postings languages: ' . implode(', ', $storage->fetch_postings_languages)
        );
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('ambiguous automatic fallback considers single-token synonym targets outside the fallback cap', function (): void {
    $profiles = [];
    foreach (['qa', 'qb', 'qc', 'qd', 'qe', 'qf', 'qg'] as $offset => $language) {
        $profiles[$language] = [
            'order' => ($offset + 1) * 10,
            'lexemes' => "# observed\tcanonical\tprovenance\nrouteprobe\trouteprobe\tfixture\n",
            'synonyms' => "# source\ttarget\tdirection\tweight\tprovenance\nrouteprobe\troutetarget\tquery_to_index\t0.7\tfixture\n",
        ];
    }
    $root = create_language_fts_temp_profile_set($profiles);

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);
        $enabled = $analyzer->enabled_languages();

        assert_same('qg', $enabled[6] ?? null, 'The synonym target fake language sits outside the automatic fallback cap.');
        $ranked = $analyzer->rank_query_languages('routeprobe', 2);
        assert_same($ranked[0]['score'] ?? null, $ranked[1]['score'] ?? null, 'The fake synonym-source evidence is intentionally ambiguous.');

        $indexer->index_post(fixture_post(404, 'qg', 'Synonym target', '<p>routetarget appears only in QG.</p>'));
        $explain = $searcher->explain('routeprobe', 'auto');
        $selected_partitions = array_values(array_map('strval', (array) ($explain['language_routing']['selected_partitions'] ?? [])));

        assert_same('auto_fallback_ambiguous_evidence_bounded_preflight', $explain['language_routing']['strategy'] ?? null, 'The tied profile evidence uses ambiguous bounded fallback.');
        assert_true(in_array('qg', $selected_partitions, true), 'Bounded preflight selects the over-cap synonym target partition.');

        $results = $searcher->search('routeprobe', 'auto');
        assert_same([404], array_column($results, 'post_id'), 'Automatic fallback returns the over-cap single-token synonym target.');
        assert_same('qg', $results[0]['matched_language'] ?? null, 'The synonym fallback result reports the matched fake language.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('ambiguous automatic fallback considers phrase synonym targets outside the fallback cap', function (): void {
    $profiles = [];
    foreach (['qa', 'qb', 'qc', 'qd', 'qe', 'qf', 'qg'] as $offset => $language) {
        $profiles[$language] = [
            'order' => ($offset + 1) * 10,
            'synonym_phrases' => "# source_terms\ttarget_terms\tdirection\tweight\tprovenance\nportal lookup\tsearch site\tquery_to_index\t0.8\tfixture\n",
        ];
    }
    $root = create_language_fts_temp_profile_set($profiles);

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);
        $enabled = $analyzer->enabled_languages();

        assert_same('qg', $enabled[6] ?? null, 'The phrase target fake language sits outside the automatic fallback cap.');
        $ranked = $analyzer->rank_query_languages('portal lookup', 2);
        assert_same($ranked[0]['score'] ?? null, $ranked[1]['score'] ?? null, 'The fake phrase-source evidence is intentionally ambiguous.');

        $indexer->index_post(fixture_post(405, 'qg', 'Phrase synonym target', '<p>search site appears only in QG.</p>'));
        $explain = $searcher->explain('portal lookup', 'auto');
        $selected_partitions = array_values(array_map('strval', (array) ($explain['language_routing']['selected_partitions'] ?? [])));

        assert_same('auto_fallback_ambiguous_evidence_bounded_preflight', $explain['language_routing']['strategy'] ?? null, 'The tied phrase evidence uses ambiguous bounded fallback.');
        assert_true(in_array('qg', $selected_partitions, true), 'Bounded preflight selects the over-cap phrase synonym target partition.');

        $results = $searcher->search('portal lookup', 'auto');
        assert_same([405], array_column($results, 'post_id'), 'Automatic fallback returns the over-cap phrase synonym target.');
        assert_same('qg', $results[0]['matched_language'] ?? null, 'The phrase fallback result reports the matched fake language.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('automatic search falls back to every custom partition when query has no profile evidence', function (): void {
    $root = create_language_fts_temp_profile_set([
        'qa' => [
            'order' => 10,
            'lexemes' => "# observed\tcanonical\tprovenance\nalphaform\talpha\tfixture\n",
        ],
        'qb' => [
            'order' => 20,
            'lexemes' => "# observed\tcanonical\tprovenance\nbetaform\tbeta\tfixture\n",
        ],
        'qc' => [
            'order' => 30,
            'lexemes' => "# observed\tcanonical\tprovenance\ngammaform\tgamma\tfixture\n",
        ],
    ]);

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        $indexer->index_post(fixture_post(304, 'qb', 'Fallback target', '<p>novelterm appears only in QB.</p>'));

        assert_same([], $analyzer->rank_query_languages('novelterm'), 'A query with no profile evidence has no ranked candidates.');
        $results = $searcher->search('novelterm', 'auto');

        assert_same(['qa', 'qb', 'qc'], $storage->fetch_postings_languages, 'No-evidence automatic routing searches every enabled fake partition.');
        assert_same(0, $storage->fetch_term_language_hits_count, 'Under-cap automatic fallback skips exact preflight storage calls.');
        assert_same([304], array_column($results, 'post_id'), 'Fallback fan-out preserves recall for the no-evidence query.');
        assert_same('qb', $results[0]['matched_language'] ?? null, 'The fallback result still reports the matched fake language.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('automatic search falls back when candidate evidence is stopword-only', function (): void {
    $root = create_language_fts_temp_profile_set([
        'en' => [
            'order' => 10,
            'stopwords' => "the\nand\nof\nto\n",
        ],
        'xx' => [
            'order' => 20,
        ],
    ]);

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        $indexer->index_post(fixture_post(306, 'xx', 'Stopword fallback target', '<p>novelterm appears only in XX.</p>'));

        assert_same([], $analyzer->rank_query_languages('the and of to novelterm'), 'Stopword-only evidence does not create a confident ranked candidate.');
        $results = $searcher->search('the and of to novelterm', 'auto');

        assert_same(['en', 'xx'], $storage->fetch_postings_languages, 'Stopword-only automatic routing searches every enabled partition.');
        assert_same([306], array_column($results, 'post_id'), 'Fallback fan-out preserves recall for an unknown term outside the stopword-matched partition.');
        assert_same('xx', $results[0]['matched_language'] ?? null, 'The stopword-only fallback result reports the matched fake language.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('automatic search falls back when custom profile evidence is ambiguous', function (): void {
    $root = create_language_fts_temp_profile_set([
        'qa' => [
            'order' => 10,
            'lexemes' => "# observed\tcanonical\tprovenance\nsharedform\tshared\tfixture\n",
        ],
        'qb' => [
            'order' => 20,
            'lexemes' => "# observed\tcanonical\tprovenance\nsharedform\tshared\tfixture\n",
        ],
        'qc' => [
            'order' => 30,
            'lexemes' => "# observed\tcanonical\tprovenance\notherform\tother\tfixture\n",
        ],
    ]);

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        $ranked = $analyzer->rank_query_languages('sharedform', 2);
        assert_same('qa', $ranked[0]['language'] ?? null, 'Tied evidence remains deterministically ordered by language id.');
        assert_same('qb', $ranked[1]['language'] ?? null, 'The second tied fake language remains visible to the router.');
        assert_same($ranked[0]['score'] ?? null, $ranked[1]['score'] ?? null, 'The fake language evidence is intentionally tied.');

        $indexer->index_post(fixture_post(305, 'qb', 'Ambiguous target', '<p>sharedform appears only in QB.</p>'));
        $results = $searcher->search('sharedform', 'auto');

        assert_same(['qa', 'qb', 'qc'], $storage->fetch_postings_languages, 'Ambiguous automatic routing searches every enabled fake partition.');
        assert_same([305], array_column($results, 'post_id'), 'Ambiguous fallback preserves recall outside the first ranked fake language.');
        assert_same('qb', $results[0]['matched_language'] ?? null, 'The ambiguous fallback result reports the matched fake language.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('explain reports normalized auto ranking signals across partitions', function (): void {
    $root = create_language_fts_temp_profile_set([
        'qa' => [
            'order' => 10,
        ],
        'qb' => [
            'order' => 20,
        ],
    ]);

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        $indexer->index_post(fixture_post(307, 'qa', 'QA normalized diagnostic target', '<p>sharedterm appears in QA.</p>'));
        $indexer->index_post(fixture_post(308, 'qb', 'QB normalized diagnostic target', '<p>sharedterm appears in QB.</p>'));

        $explain = $searcher->explain('sharedterm', 'auto');
        $selected_partitions = array_values(array_map('strval', (array) ($explain['language_routing']['selected_partitions'] ?? [])));
        $evaluated_partitions = array_values(array_map(
            static fn(array $partition): string => (string) ($partition['language'] ?? ''),
            array_filter((array) ($explain['partitions'] ?? []), 'is_array')
        ));

        assert_true(
            count($selected_partitions) > 1,
            'Automatic explain selects more than one partition for no-evidence custom profiles.' .
            "\nSelected partitions: " . var_export($selected_partitions, true)
        );
        assert_true(
            count($evaluated_partitions) > 1,
            'Automatic explain evaluates more than one selected partition.' .
            "\nEvaluated partitions: " . var_export($evaluated_partitions, true)
        );

        $result_languages = array_values(array_map('strval', array_column((array) ($explain['results'] ?? []), 'matched_language')));
        sort($result_languages, SORT_STRING);
        assert_same(['qa', 'qb'], $result_languages, 'Each fake language contributes one sharedterm explain result.');

        $required_fields = [
            'raw_score',
            'normalized_score',
            'rank_score',
            'routing_prior',
            'partition_max_score',
        ];
        $missing_fields_by_result = [];
        foreach ((array) ($explain['results'] ?? []) as $result) {
            if (!is_array($result)) {
                continue;
            }

            $result_key = (string) ($result['matched_language'] ?? 'unknown') . '#' . (string) ($result['post_id'] ?? '0');
            foreach ($required_fields as $field) {
                if (!array_key_exists($field, $result)) {
                    $missing_fields_by_result[$result_key][] = $field;
                }
            }
        }

        assert_same(
            [],
            $missing_fields_by_result,
            'Explain auto results should expose normalized ranking diagnostics for every partition result.'
        );
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('automatic multi-partition search orders results by normalized rank score', function (): void {
    $root = create_language_fts_temp_profile_set([
        'qa' => [
            'order' => 10,
            'signals' => [
                '/\bsharedterm\b/u',
            ],
        ],
        'qb' => [
            'order' => 20,
            'signals' => [
                '/\bpreferqb\b/u',
                '/\bsharedterm\b/u',
            ],
        ],
    ]);

    try {
        $storage = new Language_FTS_Playground_Test_Storage();
        $analyzer = new Language_FTS_Playground_Analyzer(new Language_FTS_Playground_Lexical_Profile_Repository($root));
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        $ranked = $analyzer->rank_query_languages('preferqb sharedterm');
        assert_same('qb', $ranked[0]['language'] ?? null, 'QB has the stronger fake routing prior.');
        assert_same('qa', $ranked[1]['language'] ?? null, 'QA remains above the automatic routing threshold.');

        $indexer->index_post(fixture_post(309, 'qa', 'sharedterm title match', '<p>QA content does not add another query hit.</p>'));
        $indexer->index_post(fixture_post(310, 'qb', 'QB content match', '<p>sharedterm appears only in QB content.</p>'));

        $explain = $searcher->explain('preferqb sharedterm', 'auto');
        assert_same(['qb', 'qa'], $explain['language_routing']['selected_partitions'] ?? null, 'Automatic routing evaluates both fake partitions by prior.');

        $results_by_language = [];
        foreach ((array) ($explain['results'] ?? []) as $result) {
            if (!is_array($result)) {
                continue;
            }

            $results_by_language[(string) ($result['matched_language'] ?? '')] = $result;
        }

        assert_true(
            isset($results_by_language['qa'], $results_by_language['qb']),
            'Explain includes results from both fake language partitions.' .
            "\nResults: " . var_export($explain['results'] ?? [], true)
        );
        assert_true(
            (float) ($results_by_language['qa']['raw_score'] ?? 0.0) > (float) ($results_by_language['qb']['raw_score'] ?? 0.0),
            'The QA title hit has the higher raw BM25 score.'
        );
        assert_true(
            (float) ($results_by_language['qb']['rank_score'] ?? 0.0) > (float) ($results_by_language['qa']['rank_score'] ?? 0.0),
            'The QB content hit has the higher normalized routing-aware rank score.'
        );

        $results = $searcher->search('preferqb sharedterm', 'auto');

        assert_same([310, 309], array_column($results, 'post_id'), 'Public search follows normalized auto rank score instead of raw score.');
        foreach ($results as $result) {
            assert_same(
                ['post_id', 'score', 'matched_terms', 'matched_fields', 'snippet', 'matched_language'],
                array_keys($result),
                'Public search results keep the expected result shape.'
            );
        }
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('ranks higher term frequency first', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(10, 'en', 'Dense', '<p>orchard orchard orchard orchard</p>'));
    $indexer->index_post(fixture_post(11, 'en', 'Sparse', '<p>orchard meadow river stone</p>'));

    $results = $searcher->search('orchard', 'en');

    assert_true(count($results) >= 2, 'Both English documents match.');
    assert_same(10, $results[0]['post_id'], 'The denser document ranks first.');
    assert_true($results[0]['score'] > $results[1]['score'], 'BM25 score reflects term frequency.');
});

test_case('quoted phrase search requires adjacent ordered analyzer positions', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(20, 'en', 'Adjacent', '<p>Searching pages stay adjacent.</p>'));
    $indexer->index_post(fixture_post(21, 'en', 'Reversed', '<p>Pages stay searching in reverse order.</p>'));
    $indexer->index_post(fixture_post(22, 'en', 'Separated', '<p>Searching useful pages are separated.</p>'));

    assert_same([20], array_column($searcher->search('"search pages"', 'en'), 'post_id'), 'Quoted phrases require adjacent ordered analyzer keys.');
});

test_case('quoted phrase search covers alt text without crossing excluded markup noise', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(30, 'en', 'Alt phrase', '<p>Visible prelude.</p><img alt="silver falcon" />'));
    $indexer->index_post(fixture_post(31, 'en', 'Markup gap', '<p>silver</p><script>ignored markup</script><p>falcon</p>'));

    assert_same([30], array_column($searcher->search('"silver falcon"', 'en'), 'post_id'), 'Phrases can match inside image alt text but not across skipped script/style/comment/template noise.');
});

test_case('fuzzy suffix matches one edit typo only when opted in', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(40, 'en', 'Orchard', '<p>orchard meadow</p>'));

    assert_same([], $searcher->search('orchrd', 'en'), 'Typo tolerance is opt-in and plain typos stay exact.');
    assert_same([40], array_column($searcher->search('orchrd~', 'en'), 'post_id'), 'A one-edit typo matches with the fuzzy suffix.');
});

test_case('fuzzy suffix rejects short noisy terms and ranks below exact matches', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(50, 'en', 'Exact orchard', '<p>orchard trail</p>'));
    $indexer->index_post(fixture_post(51, 'en', 'Fuzzy neighbor', '<p>orchart trail trail trail trail</p>'));
    $indexer->index_post(fixture_post(52, 'en', 'Short bait', '<p>bus stop</p>'));

    assert_same([], $searcher->search('bis~', 'en'), 'Short fuzzy terms are rejected to avoid noisy matches.');
    assert_same([50, 51], array_column($searcher->search('orchard~', 'en'), 'post_id'), 'Exact matches rank ahead of fuzzy one-edit neighbors.');
});

test_case('ranks title hits above equal content hits', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(41, 'en', 'Body match', '<p>orchard plain visible text</p>'));
    $indexer->index_post(fixture_post(42, 'en', 'Orchard title', '<p>plain visible text only</p>'));

    $results = $searcher->search('orchard', 'en');

    assert_true(count($results) >= 2, 'Both English documents match.');
    assert_same(42, $results[0]['post_id'], 'A title hit outranks an equal content hit even with the higher post ID.');
    assert_same(['title'], $results[0]['matched_fields'], 'The top result reports the title field.');
    assert_same(['content'], $results[1]['matched_fields'], 'The second result reports the content field.');
});

test_case('public search fetches field text only for final results', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(101, 'en', 'First orchard', '<p>Orchard match one.</p>'));
    $indexer->index_post(fixture_post(102, 'en', 'Second orchard', '<p>Orchard match two.</p>'));
    $indexer->index_post(fixture_post(103, 'en', 'Third orchard', '<p>Orchard match three.</p>'));

    $results = $searcher->search('orchard', 'en', 2);

    assert_same([101, 102], array_column($results, 'post_id'), 'The final limited public result order is stable.');
    assert_same(1, $storage->fetch_document_fields_count, 'Public search fetches document field text once for the final window.');
    assert_same(
        [
            [
                'language' => 'en',
                'post_ids' => [101, 102],
            ],
        ],
        $storage->fetch_document_fields_requests,
        'Public search fetches field text only for final result IDs.'
    );
    assert_same(0, $storage->fetch_document_field_metadata_count, 'Public search does not fetch document field metadata.');
    assert_contains_text('<mark>orchard</mark>', $results[0]['snippet'], 'Final public results still include highlighted snippets.');
    assert_contains_text('<mark>orchard</mark>', $results[1]['snippet'], 'Every final public result is snippet-enriched.');

    $storage = new Language_FTS_Playground_Test_Storage();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(101, 'en', 'First orchard', '<p>Orchard match one.</p>'));
    $indexer->index_post(fixture_post(102, 'en', 'Second orchard', '<p>Orchard match two.</p>'));
    $indexer->index_post(fixture_post(103, 'en', 'Third orchard', '<p>Orchard match three.</p>'));

    $explain = $searcher->explain('orchard', 'en', 2);

    assert_same(1, $storage->fetch_document_fields_count, 'Explain keeps fetching candidate field text for diagnostics.');
    assert_same(
        [
            [
                'language' => 'en',
                'post_ids' => [101, 102, 103],
            ],
        ],
        $storage->fetch_document_fields_requests,
        'Explain fetches field text for the complete candidate set.'
    );
    assert_same(1, $storage->fetch_document_field_metadata_count, 'Explain keeps fetching candidate field metadata for diagnostics.');
    assert_same(
        [
            [
                'language' => 'en',
                'post_ids' => [101, 102, 103],
            ],
        ],
        $storage->fetch_document_field_metadata_requests,
        'Explain fetches field metadata for the complete candidate set.'
    );
    assert_contains_text('<mark>orchard</mark>', $explain['results'][0]['snippet'] ?? '', 'Explain results keep highlighted snippets.');
});

test_case('search benchmark counter fixture gates public final-window hydration', function (): void {
    $report = Language_FTS_Playground_Search_Benchmark_Fixture::run_probe('common-term', [
        'documents' => 24,
        'limit' => 3,
    ]);
    $counters = (array) $report['counters'];

    assert_same('common-term', $report['scenario'], 'The fixture reports the requested common-term scenario.');
    assert_same(['commonterm'], $report['lookup_terms_by_class']['exact']['terms'] ?? null, 'Lookup classes include the exact common term.');
    assert_true((int) $counters['candidate_count'] > (int) $report['result_count'], 'The common-term fixture creates more candidates than final results.');
    assert_true((int) $counters['field_text_rows_fetched'] <= (int) $report['result_count'], 'Public search fetches field text rows only for the final result window.');
    assert_same(0, $counters['field_metadata_rows_fetched'] ?? null, 'Public search fetches no field metadata rows.');
    assert_same($counters['candidate_count'], $counters['document_length_rows_fetched'] ?? null, 'Document length rows match the materialized candidate set.');
    assert_true((int) $counters['postings_rows_materialized'] >= (int) $counters['candidate_count'], 'Postings row materialization is counted.');
    assert_true((int) $counters['peak_memory_delta_bytes'] >= 0, 'Peak memory delta is captured as a non-negative counter.');
});

test_case('search benchmark counting storage counts hit and field rows', function (): void {
    $storage = new Language_FTS_Playground_Search_Benchmark_Counting_Storage();
    $storage->replace_document(
        701,
        'en',
        'Shared marker',
        'publish',
        3,
        [
            'title' => ['sharedmarker' => 1],
            'content' => ['sharedmarker' => 2],
        ],
        [
            'title' => 'Shared marker',
            'content' => 'Shared marker body',
        ],
        ['sharedmarker' => [0, 2]]
    );

    $hits = $storage->fetch_term_language_hits(['en' => ['sharedmarker', 'missingmarker']]);
    $postings = $storage->fetch_postings('en', ['sharedmarker']);
    $counters = $storage->counters();

    assert_same(['sharedmarker' => true, 'missingmarker' => false], $hits['en'] ?? null, 'The fixture includes one preflight hit and one miss.');
    assert_same(['title' => 1, 'content' => 2], $postings['sharedmarker'][701] ?? null, 'The fixture stores one term across two fields.');
    assert_same(1, $counters['term_language_hit_rows_fetched'] ?? null, 'Preflight hit rows count only true storage hits.');
    assert_same(2, $counters['postings_rows_materialized'] ?? null, 'Posting row materialization counts field-aware storage rows.');
    assert_same(1, $counters['candidate_count'] ?? null, 'Candidate counting remains per language/post candidate.');
});

test_case('search benchmark counter fixture covers phrase fuzzy and expansion probes', function (): void {
    $phrase = Language_FTS_Playground_Search_Benchmark_Fixture::run_probe('phrase', [
        'documents' => 30,
        'limit' => 4,
    ]);
    assert_same(['alpha', 'beta'], $phrase['lookup_terms_by_class']['exact']['terms'] ?? null, 'Phrase probes report both exact phrase terms.');
    assert_true((int) ($phrase['counters']['position_rows_fetched'] ?? 0) > 0, 'Phrase probes fetch position rows.');
    assert_true((int) ($phrase['counters']['field_text_rows_fetched'] ?? 0) <= (int) $phrase['result_count'], 'Phrase probes keep public field text hydration final-window scoped.');
    assert_same(0, $phrase['counters']['field_metadata_rows_fetched'] ?? null, 'Phrase probes fetch no public field metadata rows.');

    $fuzzy = Language_FTS_Playground_Search_Benchmark_Fixture::run_probe('fuzzy', [
        'documents' => 30,
        'limit' => 4,
    ]);
    assert_same(['orchart'], $fuzzy['lookup_terms_by_class']['fuzzy']['terms'] ?? null, 'Fuzzy probes report the resolved typo candidate.');
    assert_true((int) ($fuzzy['counters']['fuzzy_candidate_terms_returned'] ?? 0) > 0, 'Fuzzy probes count candidate-term materialization.');

    $synonym = Language_FTS_Playground_Search_Benchmark_Fixture::run_probe('synonym', [
        'documents' => 30,
        'limit' => 4,
    ]);
    assert_same(['searchterm'], $synonym['lookup_terms_by_class']['single_token_synonyms']['terms'] ?? null, 'Single-token synonym probes report the configured target.');
    assert_true((int) $synonym['result_count'] > 0, 'Single-token synonym probes return synthetic results.');

    $phrase_synonym = Language_FTS_Playground_Search_Benchmark_Fixture::run_probe('phrase-synonym', [
        'documents' => 32,
        'limit' => 4,
    ]);
    assert_same(['search', 'site'], $phrase_synonym['lookup_terms_by_class']['phrase_synonyms']['terms'] ?? null, 'Phrase synonym probes report the target phrase terms.');
    assert_true((int) ($phrase_synonym['counters']['position_rows_fetched'] ?? 0) > 0, 'Phrase synonym probes fetch target position rows.');
    assert_same(0, $phrase_synonym['counters']['field_metadata_rows_fetched'] ?? null, 'Phrase synonym probes fetch no public field metadata rows.');
});

test_case('search benchmark counter CLI emits JSON under normal PHP and php -n', function (): void {
    $normal = run_language_fts_search_benchmark([
        'scenario' => 'fuzzy',
        'documents' => 24,
        'limit' => 3,
        'json' => true,
    ]);
    $normal_decoded = json_decode($normal['output'], true);

    assert_same(0, $normal['exit_code'], 'Benchmark counter CLI exits successfully under normal PHP. Output: ' . $normal['output']);
    assert_true(is_array($normal_decoded), 'Benchmark counter CLI JSON is parseable.');
    assert_same('fuzzy', $normal_decoded['scenario'] ?? null, 'Benchmark counter CLI reports the requested fuzzy scenario.');
    assert_true((int) ($normal_decoded['counters']['postings_rows_materialized'] ?? 0) > 0, 'Benchmark counter CLI JSON includes postings counters.');

    $no_ini = run_language_fts_search_benchmark([
        'scenario' => 'common-term',
        'documents' => 24,
        'limit' => 3,
        'json' => true,
    ], true);
    $no_ini_decoded = json_decode($no_ini['output'], true);

    assert_same(0, $no_ini['exit_code'], 'Benchmark counter CLI exits successfully under php -n. Output: ' . $no_ini['output']);
    assert_true(is_array($no_ini_decoded), 'php -n benchmark counter CLI JSON is parseable.');
    assert_same('common-term', $no_ini_decoded['scenario'] ?? null, 'php -n benchmark counter CLI reports the requested common-term scenario.');
    assert_true((int) ($no_ini_decoded['counters']['field_text_rows_fetched'] ?? 0) <= (int) ($no_ini_decoded['result_count'] ?? 0), 'php -n benchmark counter CLI preserves the final-window field text gate.');
    assert_same(0, $no_ini_decoded['counters']['field_metadata_rows_fetched'] ?? null, 'php -n benchmark counter CLI preserves the metadata gate.');
});

test_case('explain reports field boosts and phrase filter failures', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(131, 'en', 'Body match', '<p>orchard plain visible text</p>'));
    $indexer->index_post(fixture_post(132, 'en', 'Orchard title', '<p>plain visible text only</p>'));

    $field_explain = $searcher->explain('orchard', 'en');
    $first = $field_explain['results'][0] ?? [];
    $first_detail = $first['score_breakdown']['details'][0] ?? [];
    $first_field = $first_detail['fields'][0] ?? [];

    assert_same(132, $first['post_id'] ?? null, 'Explain preserves field-aware ranking.');
    assert_same(['title'], $first['matched_fields'] ?? null, 'Explain reports matched fields.');
    assert_same('exact', $first_detail['class'] ?? null, 'Exact matches have score detail entries.');
    assert_same('title', $first_field['field'] ?? null, 'Score details identify the matched field.');
    assert_same(4.0, $first_field['boost'] ?? null, 'Score details expose the field boost.');
    assert_true(($first['score_breakdown']['by_field']['title'] ?? 0.0) > 0.0, 'Score breakdown includes title contribution.');

    $storage = new Language_FTS_Playground_Test_Storage();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);
    $indexer->index_post(fixture_post(133, 'en', 'Reversed', '<p>Pages stay searching in reverse order.</p>'));

    $phrase_explain = $searcher->explain('"search pages"', 'en');
    $phrase_partition = $phrase_explain['partitions'][0] ?? [];
    assert_true(in_array('phrase_filter_removed_candidates', $phrase_partition['no_result_causes'] ?? [], true), 'Explain reports when phrase filters remove candidates.');
    assert_same(133, $phrase_partition['phrase_filters'][0]['documents'][0]['post_id'] ?? null, 'Phrase filter diagnostics identify the candidate document.');
    assert_same(false, $phrase_partition['phrase_filters'][0]['documents'][0]['passed'] ?? null, 'Phrase filter diagnostics record failed candidates.');
});

test_case('explain reports when no candidate passes all query phrase filters', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(1, 'en', 'First phrase', '<p>alpha beta only</p>'));
    $indexer->index_post(fixture_post(2, 'en', 'Second phrase', '<p>gamma delta only</p>'));

    $explain = $searcher->explain('"alpha beta" "gamma delta"', 'en');
    $partition = $explain['partitions'][0] ?? [];

    assert_same([], $explain['results'] ?? null, 'No document satisfies both query phrases.');
    assert_true(in_array('phrase_filter_removed_candidates', $explain['no_result_causes'] ?? [], true), 'Top-level explain reports conjunctive phrase filtering as the no-result cause.');
    assert_true(in_array('phrase_filter_removed_candidates', $partition['no_result_causes'] ?? [], true), 'Partition explain reports conjunctive phrase filtering as the no-result cause.');

    $passed_by_phrase = [];
    foreach ((array) ($partition['phrase_filters'] ?? []) as $filter) {
        if (!is_array($filter)) {
            continue;
        }

        $phrase = (string) ($filter['phrase'] ?? '');
        $passed_by_phrase[$phrase] = [];
        foreach ((array) ($filter['documents'] ?? []) as $document) {
            if (is_array($document) && !empty($document['passed'])) {
                $passed_by_phrase[$phrase][] = (int) ($document['post_id'] ?? 0);
            }
        }
    }

    assert_same([1], $passed_by_phrase['alpha beta'] ?? null, 'Phrase diagnostics show the first document passes only the first phrase.');
    assert_same([2], $passed_by_phrase['gamma delta'] ?? null, 'Phrase diagnostics show the second document passes only the second phrase.');
});

test_case('reports excerpt field matches with highlighted excerpt snippets', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);
    $post = fixture_post(45, 'en', 'Excerpt match', '<p>Visible meadow only</p>');
    $post->post_excerpt = 'Orchard summary text';

    $indexer->index_post($post);
    $results = $searcher->search('orchard', 'en');

    assert_same(45, $results[0]['post_id'], 'The query matches the indexed excerpt field.');
    assert_same(['excerpt'], $results[0]['matched_fields'], 'The result reports the excerpt field.');
    assert_contains_text('<mark>Orchard</mark>', $results[0]['snippet'], 'The excerpt snippet highlights the matched term.');
});

test_case('returns escaped highlighted snippets for stem-key matches', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(
        53,
        'en',
        'Safe snippet',
        '<p>Stories keep unsafe &lt;script&gt;alert(1)&lt;/script&gt; text visible.</p>'
    ));

    $results = $searcher->search('story', 'en');

    assert_same(53, $results[0]['post_id'], 'The English stem key matches an inflected visible term.');
    assert_contains_text('<mark>Stories</mark>', $results[0]['snippet'], 'The snippet highlights the raw inflected source term.');
    assert_contains_text('&lt;script&gt;alert(1)&lt;/script&gt;', $results[0]['snippet'], 'Unsafe-looking source text is escaped in snippets.');
    assert_not_contains_text('<script>', $results[0]['snippet'], 'Snippets do not emit unsafe raw HTML from post content.');
});

test_case('keeps long UTF-8 snippets valid and highlighted', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(
        55,
        'pl',
        'Dlugi opis',
        '<p>' . str_repeat('ą', 160) . ' Łódź końcówka</p>'
    ));

    $results = $searcher->search('lodz', 'pl');

    assert_same(55, $results[0]['post_id'], 'The folded Polish query matches the long UTF-8 content field.');
    assert_contains_text('<mark>Łódź</mark>', $results[0]['snippet'], 'The excerpt keeps the Polish match highlight after UTF-8 truncation.');
    assert_not_contains_text('�', $results[0]['snippet'], 'The excerpt is not cut inside a UTF-8 codepoint.');
});

test_case('reports alt field matches with highlighted alt snippets', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(60, 'en', 'Alt only', '<p>Visible meadow</p><img alt="falcon stories beside the image" />'));

    $results = $searcher->search('story', 'en');

    assert_same(60, $results[0]['post_id'], 'The query matches the indexed alt field.');
    assert_same(['alt'], $results[0]['matched_fields'], 'The result reports the alt field.');
    assert_contains_text('<mark>stories</mark>', $results[0]['snippet'], 'The alt snippet highlights the matched term.');
});

test_case('stores lifecycle versions and flags rebuilds on schema or analyzer change', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    update_option('language_fts_playground_schema_version', 'old-schema');
    update_option('language_fts_playground_analyzer_version', 'old-analyzer');

    Language_FTS_Playground_Plugin::ensure_schema();

    assert_same(1, $storage->install_count, 'Upgrade installs schema idempotently once.');
    assert_same(LANGUAGE_FTS_PLAYGROUND_SCHEMA_VERSION, get_option('language_fts_playground_schema_version'), 'Schema version is stored separately.');
    assert_same(LANGUAGE_FTS_PLAYGROUND_ANALYZER_VERSION, get_option('language_fts_playground_analyzer_version'), 'Analyzer version is stored separately.');
    assert_same(true, get_option('language_fts_playground_rebuild_required'), 'Analyzer/schema changes mark a rebuild as required.');
});

test_case('failed lifecycle version option writes are reported without claiming an upgrade', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    update_option('language_fts_playground_schema_version', 'old-schema');
    update_option('language_fts_playground_analyzer_version', 'old-analyzer');
    $GLOBALS['language_fts_test_failed_update_options']['language_fts_playground_schema_version'] = true;

    Language_FTS_Playground_Plugin::ensure_schema();
    $status = Language_FTS_Playground_Plugin::index_status();

    assert_same(1, $storage->install_count, 'The schema install is attempted before version persistence fails.');
    assert_same('old-schema', get_option('language_fts_playground_schema_version'), 'The stale schema version remains visible after the failed write.');
    assert_same(false, get_option('language_fts_playground_rebuild_required', false), 'A failed version write does not falsely mark a rebuild as persisted.');
    assert_contains_text('Could not inspect or upgrade the Language FTS analyzer resources.', (string) ($status['last_status'] ?? ''), 'The version persistence failure is surfaced as a lifecycle error.');
    assert_contains_text('language_fts_playground_schema_version', (string) ($status['last_error'] ?? ''), 'The failed version option name remains visible.');
});

test_case('failed rebuild option writes keep completed rebuilds visibly required', function (): void {
    reset_language_fts_plugin_runtime();
    $GLOBALS['language_fts_test_posts'][904] = fixture_post(904, 'en', 'Rebuild persistence orchard', '<p>orchard rebuild persistence</p>');

    $queued = Language_FTS_Playground_Plugin::queue_rebuild(false);
    assert_same(1, $queued, 'The fixture post is queued for rebuild.');
    $GLOBALS['language_fts_test_failed_update_options']['language_fts_playground_rebuild_in_progress'] = true;

    $result = Language_FTS_Playground_Plugin::process_index_queue(1);
    $status = Language_FTS_Playground_Plugin::index_status();

    assert_same(1, $result['processed'], 'The queued rebuild item is processed.');
    assert_same(1, $result['failed'], 'The rebuild state persistence failure is reported in the batch result.');
    assert_same(0, $result['remaining'], 'The queue itself drains even though rebuild state persistence fails.');
    assert_same(true, get_option('language_fts_playground_rebuild_required'), 'The rebuild-required flag remains visible after the failed completion write.');
    assert_same(true, get_option('language_fts_playground_rebuild_in_progress'), 'The stale in-progress flag remains visible for retry/diagnosis.');
    assert_contains_text('Could not persist completed Language FTS rebuild state.', (string) ($status['last_status'] ?? ''), 'The rebuild persistence failure is recorded in status.');
    assert_contains_text('language_fts_playground_rebuild_in_progress', (string) ($status['last_error'] ?? ''), 'The failed rebuild option name remains visible.');
});

test_case('failed queue option writes keep processed items queued for retry', function (): void {
    reset_language_fts_plugin_runtime();
    $GLOBALS['language_fts_test_posts'][905] = fixture_post(905, 'en', 'Queue persistence orchard', '<p>orchard queue persistence</p>');
    Language_FTS_Playground_Plugin::enqueue_posts([905]);
    $GLOBALS['language_fts_test_scheduled'] = [];
    $GLOBALS['language_fts_test_failed_update_options']['language_fts_playground_index_queue'] = true;

    $result = Language_FTS_Playground_Plugin::process_index_queue(1);
    $queue = get_option('language_fts_playground_index_queue', []);
    $status = Language_FTS_Playground_Plugin::index_status();

    assert_same(1, $result['processed'], 'The queued item is processed before completion persistence fails.');
    assert_same(1, $result['indexed'], 'The document write itself succeeds.');
    assert_same(1, $result['failed'], 'The queue persistence failure is reported in the batch result.');
    assert_same(1, $result['remaining'], 'The unconfirmed completion remains queued.');
    assert_true(array_key_exists(905, $queue), 'The processed item remains queued because completion was not durably written.');
    assert_contains_text('Could not persist completed Language FTS queue items.', (string) ($status['last_status'] ?? ''), 'The queue persistence failure is recorded in status.');
    assert_contains_text('language_fts_playground_index_queue', (string) ($status['last_error'] ?? ''), 'The failed queue option name remains visible.');
    assert_true($GLOBALS['language_fts_test_scheduled'] !== [], 'Remaining queue work is scheduled for a later retry.');
});

test_case('failed status option writes remain visible during current admin rendering', function (): void {
    reset_language_fts_plugin_runtime();
    $post = fixture_post(906, 'en', 'Status persistence orchard', '<p>orchard status persistence</p>');
    $GLOBALS['language_fts_test_posts'][906] = $post;
    $GLOBALS['language_fts_test_failed_update_options']['language_fts_playground_index_status'] = true;

    Language_FTS_Playground_Plugin::index_saved_post(906, $post, true);
    $stored_status = get_option('language_fts_playground_index_status', []);
    $status = Language_FTS_Playground_Plugin::index_status();

    assert_same([], $stored_status, 'The failed status write is not silently stored by the test option backend.');
    assert_contains_text('Queued a changed post for Language FTS indexing.', (string) ($status['last_status'] ?? ''), 'The attempted status message remains available in memory.');
    assert_contains_text('language_fts_playground_index_status', (string) ($status['last_error'] ?? ''), 'The failed status option name remains visible.');

    ob_start();
    Language_FTS_Playground_Plugin::render_admin_page();
    $html = (string) ob_get_clean();

    assert_contains_text('Language FTS Playground', $html, 'The admin page still renders after a failed status option write.');
    assert_contains_text('language_fts_playground_index_status', $html, 'The admin status panel surfaces the failed status option write.');
});

test_case('queues saved public posts and processes bounded batches', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $first = fixture_post(201, 'en', 'Queued orchard one', '<p>orchard one</p>');
    $second = fixture_post(202, 'en', 'Queued orchard two', '<p>orchard two</p>');
    $GLOBALS['language_fts_test_posts'][201] = $first;
    $GLOBALS['language_fts_test_posts'][202] = $second;

    Language_FTS_Playground_Plugin::index_saved_post(201, $first, true);
    Language_FTS_Playground_Plugin::index_saved_post(202, $second, true);

    assert_same([], $storage->all_documents(), 'Save hooks queue work instead of indexing synchronously.');
    assert_same(2, Language_FTS_Playground_Plugin::queued_count(), 'Both changed posts are queued.');
    assert_true($GLOBALS['language_fts_test_scheduled'] !== [], 'Queueing schedules a cron processor.');

    $first_batch = Language_FTS_Playground_Plugin::process_index_queue(1);
    assert_same(1, $first_batch['processed'], 'Only one queued post is processed in a one-item batch.');
    assert_same(1, count($storage->all_documents()), 'One document is indexed after the first bounded batch.');
    assert_same(1, Language_FTS_Playground_Plugin::queued_count(), 'One queued post remains after the first bounded batch.');

    $second_batch = Language_FTS_Playground_Plugin::process_index_queue(10);
    assert_same(1, $second_batch['processed'], 'The remaining queued post is processed by a later batch.');
    assert_same(2, count($storage->all_documents()), 'Both documents are indexed after the second batch.');
    assert_same(0, Language_FTS_Playground_Plugin::queued_count(), 'The queue is empty after all items are processed.');
});

test_case('contended public-post enqueue reports failure instead of claiming success', function (): void {
    reset_language_fts_plugin_runtime();
    $post = fixture_post(801, 'en', 'Contended public orchard', '<p>orchard queue contention</p>');
    $GLOBALS['language_fts_test_posts'][801] = $post;
    update_option(
        'language_fts_playground_index_queue_lock',
        [
            'token' => 'external-lock-token',
            'expires_at' => microtime(true) + 10,
        ]
    );

    Language_FTS_Playground_Plugin::index_saved_post(801, $post, true);
    $queue = get_option('language_fts_playground_index_queue', []);
    $status = Language_FTS_Playground_Plugin::index_status();

    assert_same([], $queue, 'The active lock prevents an unlocked queue write.');
    assert_same(0, Language_FTS_Playground_Plugin::queued_count(), 'No queue item is falsely counted after the failed enqueue.');
    assert_contains_text('Could not update the Language FTS queue for a saved post.', (string) ($status['last_status'] ?? ''), 'The failed enqueue is reported in status.');
    assert_contains_text('Could not acquire the Language FTS queue lock', (string) ($status['last_error'] ?? ''), 'The lock contention reason remains visible.');
    assert_not_contains_text('Queued a changed post for Language FTS indexing.', (string) ($status['last_status'] ?? ''), 'Status does not claim the post was queued.');
    assert_same([], $GLOBALS['language_fts_test_scheduled'], 'A failed enqueue does not schedule an empty queue.');
});

test_case('contended private save removes a previously indexed document', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $indexer = new Language_FTS_Playground_Indexer($storage, new Language_FTS_Playground_Analyzer());
    $indexer->index_post(fixture_post(900, 'en', 'Published orchard', '<p>orchard public</p>'));
    update_option(
        'language_fts_playground_index_queue_lock',
        [
            'token' => 'external-lock-token',
            'expires_at' => microtime(true) + 10,
        ]
    );

    $private = fixture_post(900, 'en', 'Private orchard', '<p>orchard hidden</p>', 'private');
    Language_FTS_Playground_Plugin::index_saved_post(900, $private, true);
    $status = Language_FTS_Playground_Plugin::index_status();

    assert_same([], $storage->all_documents(), 'A contended queue cleanup does not leave the private post indexed.');
    assert_same([], get_option('language_fts_playground_index_queue', []), 'The contended private save does not perform an unlocked queue write.');
    assert_contains_text('Removed a non-public post from the Language FTS index.', (string) ($status['last_status'] ?? ''), 'The storage removal remains visible in status.');
});

test_case('contended delete removes a previously indexed document', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $indexer = new Language_FTS_Playground_Indexer($storage, new Language_FTS_Playground_Analyzer());
    $indexer->index_post(fixture_post(901, 'en', 'Deleted orchard', '<p>orchard deleted</p>'));
    update_option(
        'language_fts_playground_index_queue_lock',
        [
            'token' => 'external-lock-token',
            'expires_at' => microtime(true) + 10,
        ]
    );

    Language_FTS_Playground_Plugin::delete_post(901);
    $status = Language_FTS_Playground_Plugin::index_status();

    assert_same([], $storage->all_documents(), 'A contended queue cleanup does not leave the deleted post indexed.');
    assert_same([], get_option('language_fts_playground_index_queue', []), 'The contended delete does not perform an unlocked queue write.');
    assert_contains_text('Removed a deleted post from the Language FTS index.', (string) ($status['last_status'] ?? ''), 'The storage removal remains visible in status.');
});

test_case('completion preserves queue IDs added between read and write', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $first = fixture_post(501, 'en', 'Interleaved orchard one', '<p>orchard one</p>');
    $second = fixture_post(502, 'en', 'Interleaved orchard two', '<p>orchard two</p>');
    $GLOBALS['language_fts_test_posts'][501] = $first;
    $GLOBALS['language_fts_test_posts'][502] = $second;

    Language_FTS_Playground_Plugin::index_saved_post(501, $first, true);
    $queue_reads = 0;
    $interleaved = false;
    $GLOBALS['language_fts_test_get_option_interceptor'] = static function (string $name, int $read_count) use (&$queue_reads, &$interleaved): void {
        unset($read_count);
        if ($name !== 'language_fts_playground_index_queue') {
            return;
        }

        $queue_reads++;
        if ($queue_reads !== 3) {
            return;
        }

        $interleaved = true;
        $GLOBALS['language_fts_test_get_option_interceptor'] = null;
        Language_FTS_Playground_Plugin::enqueue_posts([502]);
    };

    $result = Language_FTS_Playground_Plugin::process_index_queue(1);
    $queue = get_option('language_fts_playground_index_queue', []);

    assert_same(1, $result['processed'], 'The original queued post is processed.');
    assert_same(true, $interleaved, 'The regression injected a queue write before completion wrote back.');
    assert_true(!array_key_exists(501, $queue), 'The completed post is removed from the queue.');
    assert_true(array_key_exists(502, $queue), 'The concurrently queued post remains queued.');
    assert_same(1, Language_FTS_Playground_Plugin::queued_count(), 'Only the interleaved queue item remains.');
});

test_case('completion preserves same-post requeues with newer tokens', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $post = fixture_post(503, 'en', 'Retokened orchard', '<p>orchard token</p>');
    $GLOBALS['language_fts_test_posts'][503] = $post;

    Language_FTS_Playground_Plugin::index_saved_post(503, $post, true);
    $queue_reads = 0;
    $GLOBALS['language_fts_test_get_option_interceptor'] = static function (string $name, int $read_count) use (&$queue_reads): void {
        unset($read_count);
        if ($name !== 'language_fts_playground_index_queue') {
            return;
        }

        $queue_reads++;
        if ($queue_reads !== 3) {
            return;
        }

        $GLOBALS['language_fts_test_get_option_interceptor'] = null;
        $queue = get_option('language_fts_playground_index_queue', []);
        $queue[503] = 'newer-token';
        update_option('language_fts_playground_index_queue', $queue);
    };

    $result = Language_FTS_Playground_Plugin::process_index_queue(1);
    $queue = get_option('language_fts_playground_index_queue', []);

    assert_same(1, $result['processed'], 'The queued post is processed once.');
    assert_same('newer-token', $queue[503] ?? null, 'A same-post requeue with a newer token is not completed by the stale token.');
    assert_same(1, Language_FTS_Playground_Plugin::queued_count(), 'The requeued post remains queued.');
});

test_case('completion skips queue writes when the queue lock is contended', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $processed = fixture_post(701, 'en', 'Contended orchard one', '<p>orchard one</p>');
    $interleaved = fixture_post(702, 'en', 'Contended orchard two', '<p>orchard two</p>');
    $GLOBALS['language_fts_test_posts'][701] = $processed;
    $GLOBALS['language_fts_test_posts'][702] = $interleaved;

    Language_FTS_Playground_Plugin::enqueue_posts([701]);
    update_option(
        'language_fts_playground_index_queue_lock',
        [
            'token' => 'external-lock-token',
            'expires_at' => microtime(true) + 10,
        ]
    );
    $GLOBALS['language_fts_test_scheduled'] = [];

    $lock_reads = 0;
    $interleaved_enqueue = false;
    $GLOBALS['language_fts_test_get_option_interceptor'] = static function (string $name, int $read_count) use (&$lock_reads, &$interleaved_enqueue): void {
        unset($read_count);
        if ($name !== 'language_fts_playground_index_queue_lock') {
            return;
        }

        $lock_reads++;
        if ($lock_reads !== 2) {
            return;
        }

        $interleaved_enqueue = true;
        $GLOBALS['language_fts_test_get_option_interceptor'] = null;

        // Simulate the external lock owner committing a queue append while this completion waits.
        $queue_lock_token_property = new ReflectionProperty(Language_FTS_Playground_Plugin::class, 'queue_lock_token');
        $queue_lock_token_property->setAccessible(true);
        $queue_lock_depth_property = new ReflectionProperty(Language_FTS_Playground_Plugin::class, 'queue_lock_depth');
        $queue_lock_depth_property->setAccessible(true);
        $queue_lock_token_property->setValue(null, 'external-lock-token');
        $queue_lock_depth_property->setValue(null, 1);
        try {
            Language_FTS_Playground_Plugin::enqueue_posts([702]);
        } finally {
            $queue_lock_token_property->setValue(null, null);
            $queue_lock_depth_property->setValue(null, 0);
        }
    };

    $result = Language_FTS_Playground_Plugin::process_index_queue(1);
    $queue = get_option('language_fts_playground_index_queue', []);
    $status = Language_FTS_Playground_Plugin::index_status();

    assert_same(1, $result['processed'], 'The original queued post is processed.');
    assert_same(true, $interleaved_enqueue, 'The regression injected a queue write while completion was blocked by the active lock.');
    assert_true(array_key_exists(701, $queue), 'The completed post remains queued because completion could not acquire the queue lock.');
    assert_true(array_key_exists(702, $queue), 'The externally queued post remains queued after the skipped completion attempt.');
    assert_same(2, $result['remaining'], 'Processing reports the remaining locked work instead of clearing the queue.');
    assert_same(2, $status['last_result']['remaining'] ?? null, 'Status records the remaining queue count for retry.');
    assert_true($GLOBALS['language_fts_test_scheduled'] !== [], 'Remaining work is scheduled for a later retry.');
});

test_case('idle queue processing does not clear required rebuild before rebuild is queued', function (): void {
    reset_language_fts_plugin_runtime();
    update_option('language_fts_playground_rebuild_required', true);
    update_option('language_fts_playground_rebuild_in_progress', false);

    $result = Language_FTS_Playground_Plugin::process_index_queue(10);

    assert_same(0, $result['processed'], 'No queue items are processed.');
    assert_same(0, $result['remaining'], 'The queue remains empty.');
    assert_same(true, get_option('language_fts_playground_rebuild_required'), 'A version-required rebuild is not cleared before queue_rebuild() runs.');
});

test_case('contended rebuild stays required after empty queue processing', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $post = fixture_post(902, 'en', 'Rebuild contention orchard', '<p>orchard rebuild lock</p>');
    $GLOBALS['language_fts_test_posts'][902] = $post;
    $indexer = new Language_FTS_Playground_Indexer($storage, new Language_FTS_Playground_Analyzer());
    $indexer->index_post($post);
    update_option(
        'language_fts_playground_index_queue_lock',
        [
            'token' => 'external-lock-token',
            'expires_at' => microtime(true) + 10,
        ]
    );

    Language_FTS_Playground_Plugin::rebuild_index();

    assert_same(0, $storage->clear_count, 'The index is not cleared until rebuild queue replacement can acquire the lock.');
    assert_same(1, count($storage->all_documents()), 'Existing indexed documents remain active until a rebuild queue is durably written.');
    assert_same([], get_option('language_fts_playground_index_queue', []), 'The contended rebuild does not perform an unlocked queue replacement.');
    assert_same(true, get_option('language_fts_playground_rebuild_required'), 'The failed rebuild queue replacement remains visibly required.');
    assert_same(false, get_option('language_fts_playground_rebuild_in_progress', false), 'A rebuild is not marked in progress before its queue is written.');
    assert_same([], $GLOBALS['language_fts_test_scheduled'], 'A contended rebuild does not schedule an empty queue.');

    delete_option('language_fts_playground_index_queue_lock');
    $result = Language_FTS_Playground_Plugin::process_index_queue(10);

    assert_same(0, $result['processed'], 'No queue items are processed after the contended rebuild failed to queue work.');
    assert_same(0, $result['indexed'], 'No posts are falsely indexed by empty queue processing.');
    assert_same(true, get_option('language_fts_playground_rebuild_required'), 'Empty queue processing does not clear the required rebuild.');
    assert_same(false, get_option('language_fts_playground_rebuild_in_progress', false), 'Empty queue processing does not fabricate or complete an in-progress rebuild.');
});

test_case('version rebuild stays required until bounded queue drains', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $GLOBALS['language_fts_test_posts'][601] = fixture_post(601, 'en', 'Rebuild orchard one', '<p>orchard one</p>');
    $GLOBALS['language_fts_test_posts'][602] = fixture_post(602, 'en', 'Rebuild orchard two', '<p>orchard two</p>');

    $queued = Language_FTS_Playground_Plugin::queue_rebuild(true);
    assert_same(2, $queued, 'Both published posts are queued for rebuild.');
    assert_same(true, get_option('language_fts_playground_rebuild_required'), 'A queued rebuild remains marked as required.');
    assert_same(true, get_option('language_fts_playground_rebuild_in_progress'), 'A queued rebuild is tracked as in progress.');

    $first_batch = Language_FTS_Playground_Plugin::process_index_queue(1);
    assert_same(1, $first_batch['processed'], 'A bounded rebuild batch processes one item.');
    assert_same(1, $first_batch['remaining'], 'One rebuild item remains after the bounded batch.');
    assert_same(true, get_option('language_fts_playground_rebuild_required'), 'The rebuild-required flag remains true while queued work remains.');
    assert_same(true, get_option('language_fts_playground_rebuild_in_progress'), 'The rebuild remains in progress while queued work remains.');

    $second_batch = Language_FTS_Playground_Plugin::process_index_queue(10);
    assert_same(1, $second_batch['processed'], 'The final rebuild item is processed.');
    assert_same(0, $second_batch['remaining'], 'The rebuild queue drains.');
    assert_same(false, get_option('language_fts_playground_rebuild_required'), 'The rebuild-required flag clears only after a successful drain.');
    assert_same(false, get_option('language_fts_playground_rebuild_in_progress'), 'The in-progress marker clears after a successful drain.');
});

test_case('version rebuild stays required when queue processing fails', function (): void {
    reset_language_fts_plugin_runtime(new Language_FTS_Playground_Test_Failing_Storage('Index writes are temporarily unavailable.'));
    $GLOBALS['language_fts_test_posts'][603] = fixture_post(603, 'en', 'Failing rebuild orchard', '<p>orchard failure</p>');

    $queued = Language_FTS_Playground_Plugin::queue_rebuild(false);
    assert_same(1, $queued, 'The published post is queued for rebuild without clearing storage.');
    assert_same(true, get_option('language_fts_playground_rebuild_required'), 'A queued rebuild starts as required.');
    assert_same(true, get_option('language_fts_playground_rebuild_in_progress'), 'A queued rebuild starts in progress.');

    $result = Language_FTS_Playground_Plugin::process_index_queue(1);
    $status = Language_FTS_Playground_Plugin::index_status();

    assert_same(1, $result['failed'], 'The failing storage path reports a failed queue item.');
    assert_same(1, $result['remaining'], 'The failed queue item remains queued for retry.');
    assert_same(true, get_option('language_fts_playground_rebuild_required'), 'The rebuild-required flag stays true after a failed rebuild item.');
    assert_same(true, get_option('language_fts_playground_rebuild_in_progress'), 'The in-progress marker stays true after a failed rebuild item.');
    assert_contains_text('Index writes are temporarily unavailable.', (string) ($status['last_error'] ?? ''), 'The rebuild failure remains visible in status.');
});

test_case('removes non-public lifecycle states and ignores revisions and autosaves', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $indexer = new Language_FTS_Playground_Indexer($storage, new Language_FTS_Playground_Analyzer());

    foreach (['draft', 'private', 'trash'] as $status) {
        $published = fixture_post(300, 'en', 'Published orchard', '<p>orchard visible</p>');
        $indexer->index_post($published);
        assert_same(1, count($storage->all_documents()), 'The fixture starts indexed.');

        $non_public = fixture_post(300, 'en', 'Hidden orchard', '<p>orchard hidden</p>', $status);
        Language_FTS_Playground_Plugin::transition_post_status($status, 'publish', $non_public);

        assert_same([], $storage->all_documents(), "{$status} posts are removed from the index.");
        assert_same(0, Language_FTS_Playground_Plugin::queued_count(), "{$status} posts are not left in the queue.");
    }

    $indexer->index_post(fixture_post(301, 'en', 'Password orchard', '<p>orchard protected</p>'));
    $passworded = fixture_post(301, 'en', 'Password orchard', '<p>orchard protected</p>', 'publish', 'post', 'secret');
    Language_FTS_Playground_Plugin::index_saved_post(301, $passworded, true);
    assert_same([], $storage->all_documents(), 'Password-protected published posts are removed from the index.');

    $revision = fixture_post(302, 'en', 'Revision orchard', '<p>orchard revision</p>');
    $GLOBALS['language_fts_test_revisions'][302] = true;
    Language_FTS_Playground_Plugin::index_saved_post(302, $revision, true);
    assert_same(0, Language_FTS_Playground_Plugin::queued_count(), 'Revisions are ignored.');

    $autosave = fixture_post(303, 'en', 'Autosave orchard', '<p>orchard autosave</p>');
    $GLOBALS['language_fts_test_autosaves'][303] = true;
    Language_FTS_Playground_Plugin::index_saved_post(303, $autosave, true);
    assert_same(0, Language_FTS_Playground_Plugin::queued_count(), 'Autosaves are ignored.');

    $indexer->index_post(fixture_post(304, 'en', 'Deleted orchard', '<p>orchard deleted</p>'));
    Language_FTS_Playground_Plugin::delete_post(304);
    assert_same([], $storage->all_documents(), 'Deleted posts are removed from the index.');
});

test_case('renders admin lifecycle controls and protects destructive actions', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    Language_FTS_Playground_Plugin::register_hooks();

    assert_true(isset($GLOBALS['language_fts_test_actions']['language_fts_playground_process_queue']), 'Cron queue processor hook is registered.');
    assert_true(isset($GLOBALS['language_fts_test_actions']['admin_post_language_fts_playground_process_queue']), 'Admin process queue action is registered.');
    assert_true(isset($GLOBALS['language_fts_test_actions']['admin_post_language_fts_playground_clear_index']), 'Admin clear index action is registered.');

    $post = fixture_post(401, 'en', 'Queued admin orchard', '<p>orchard admin</p>');
    $GLOBALS['language_fts_test_posts'][401] = $post;
    Language_FTS_Playground_Plugin::index_saved_post(401, $post, true);

    ob_start();
    Language_FTS_Playground_Plugin::render_admin_page();
    $html = ob_get_clean();

    assert_contains_text('Queued posts', $html, 'Admin status shows queued count.');
    assert_contains_text('Process queue', $html, 'Admin page exposes manual queue processing.');
    assert_contains_text('method="post" action="/wp-admin/admin-post.php"', $html, 'Admin clear-index control uses a POST form.');
    assert_contains_text('language_fts_playground_confirm_clear" value="1"', $html, 'Admin clear-index control carries an explicit confirmation field.');
    assert_contains_text('return confirm(', $html, 'Admin clear-index control asks for browser confirmation.');
    assert_contains_text('Clear index and queue', $html, 'Admin page exposes a clearly destructive clear-index control.');
    assert_contains_text('Rebuild index', $html, 'Admin page keeps a rebuild control.');

    $GLOBALS['language_fts_test_current_user_can'] = false;
    try {
        Language_FTS_Playground_Plugin::handle_clear_action();
    } catch (RuntimeException $exception) {
        assert_contains_text('permission', strtolower($exception->getMessage()), 'Capability failure is surfaced.');
    }

    assert_same(0, $storage->clear_count, 'Clear index does not run without the required capability.');

    $GLOBALS['language_fts_test_current_user_can'] = true;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];
    try {
        Language_FTS_Playground_Plugin::handle_clear_action();
    } catch (RuntimeException $exception) {
        assert_contains_text('confirmation', strtolower($exception->getMessage()), 'Clear index requires explicit admin confirmation.');
    }

    assert_same(0, $storage->clear_count, 'Clear index does not run without the confirmation POST.');
});

test_case('admin search form defaults to automatic language mode', function (): void {
    reset_language_fts_plugin_runtime();

    ob_start();
    Language_FTS_Playground_Plugin::render_admin_page();
    $html = ob_get_clean();

    assert_contains_text('<option value="auto" selected="selected">Automatic</option>', $html, 'The admin language selector defaults to Automatic.');
    assert_not_contains_text('<option value="en" selected="selected">English</option>', $html, 'The admin language selector no longer defaults to English.');
    $search_position = strpos($html, 'Search results');
    $maintenance_position = strpos($html, 'Maintenance');
    $index_position = strpos($html, 'Index status');
    $lexical_position = strpos($html, 'Lexical pack status');
    assert_true(
        $search_position !== false
            && $maintenance_position !== false
            && $index_position !== false
            && $lexical_position !== false
            && $search_position < $maintenance_position
            && $search_position < $index_position
            && $search_position < $lexical_position,
        'The admin page prioritizes search results before maintenance and status sections.'
    );
});

test_case('admin page renders lexical pack status safely as curated seed data', function (): void {
    reset_language_fts_plugin_runtime();

    ob_start();
    Language_FTS_Playground_Plugin::render_admin_page();
    $html = ob_get_clean();

    assert_contains_text('Lexical pack status', $html, 'Admin page includes lexical pack status.');
    assert_contains_text('<code>curated_seed</code>', $html, 'Admin page labels current packs as curated seed data.');
    assert_contains_text('Language FTS Playground curated English seed data', $html, 'Admin page shows pack source names.');
    assert_contains_text('GPL-2.0-or-later', $html, 'Admin page shows pack licenses.');
    assert_contains_text('2026-06-08-seed 2026-06-08', $html, 'Admin page shows pack version/date.');
    assert_contains_text('lexemes 34; synsets 1; phrase rows 0; expansions 12', $html, 'Admin page shows compact Polish pack counts.');
    assert_not_contains_text('<script>', $html, 'Admin lexical pack status does not emit raw unsafe markup.');
});

test_case('admin page escapes comprehensive audit metadata details', function (): void {
    $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\tfixture-comprehensive\n");
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    $metadata = language_fts_temp_comprehensive_pack_metadata($language_dir, [
        'source_name' => 'Fixture <script>alert(1)</script> source',
        'attribution_text' => 'Attribution <script>alert(2)</script>',
    ]);
    $metadata['importer']['command'] = 'php import <script>alert(3)</script>';
    $metadata['provenance_ids']['fixture-comprehensive']['description'] = 'Description <script>alert(4)</script>';
    write_language_fts_temp_pack_metadata_array($language_dir, $metadata);
    $normalized_root = Language_FTS_Playground_Lexical_Profile_Repository::normalize_resource_root($root);

    try {
        reset_language_fts_plugin_runtime();
        add_filter(
            'language_fts_playground_lexical_resource_root',
            static fn(): string => $normalized_root,
            10,
            1
        );

        ob_start();
        Language_FTS_Playground_Plugin::render_admin_page();
        $html = ob_get_clean();

        assert_contains_text('Audit metadata', $html, 'Admin page renders audit metadata details for comprehensive packs.');
        assert_contains_text('Fixture &lt;script&gt;alert(1)&lt;/script&gt; source', $html, 'Admin page escapes malicious source metadata.');
        assert_contains_text('php import &lt;script&gt;alert(3)&lt;/script&gt;', $html, 'Admin page escapes malicious importer command metadata.');
        assert_contains_text('Description &lt;script&gt;alert(4)&lt;/script&gt;', $html, 'Admin page escapes malicious provenance metadata.');
        assert_not_contains_text('<script>', $html, 'Admin audit metadata details do not emit raw unsafe markup.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('admin page renders non-ok runtime digest status for malformed comprehensive metadata', function (): void {
    $root = create_language_fts_temp_profile_tree("# observed\tcanonical\tprovenance\nalpha\talpha\tfixture-comprehensive\n");
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    $metadata = language_fts_temp_comprehensive_pack_metadata($language_dir);
    foreach ($metadata['runtime_files'] as &$runtime_file) {
        if (($runtime_file['file'] ?? '') === 'profile.php') {
            $runtime_file['sha256'] = 'not-a-sha256';
            break;
        }
    }
    unset($runtime_file);
    write_language_fts_temp_pack_metadata_array($language_dir, $metadata);
    $normalized_root = Language_FTS_Playground_Lexical_Profile_Repository::normalize_resource_root($root);

    try {
        reset_language_fts_plugin_runtime();
        add_filter(
            'language_fts_playground_lexical_resource_root',
            static fn(): string => $normalized_root,
            10,
            1
        );

        ob_start();
        Language_FTS_Playground_Plugin::render_admin_page();
        $html = ob_get_clean();

        assert_contains_text('<code>invalid</code>', $html, 'Admin runtime digest column renders malformed metadata as invalid.');
        assert_contains_text('runtime_files sha256 must be 64 lowercase hex characters', $html, 'Admin warnings explain the malformed runtime digest metadata.');
        assert_not_contains_text('<code>ok</code>', $html, 'Admin runtime digest column does not report ok beside malformed metadata warnings.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('admin page renders lexical pack status and disables search when custom root is invalid', function (): void {
    reset_language_fts_plugin_runtime();
    $missing_root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'language-fts-missing-root-' . str_replace('.', '', uniqid('', true));
    $normalized_root = Language_FTS_Playground_Lexical_Profile_Repository::normalize_resource_root($missing_root);

    add_filter(
        'language_fts_playground_lexical_resource_root',
        static fn(): string => $missing_root,
        10,
        1
    );

    ob_start();
    Language_FTS_Playground_Plugin::render_admin_page();
    $html = ob_get_clean();

    assert_contains_text('Language FTS Playground', $html, 'The admin page shell still renders when a custom lexical root is missing.');
    assert_contains_text('Lexical pack status', $html, 'The lexical pack status section still renders when analyzer setup fails.');
    assert_contains_text('Language profile resource root does not exist: ' . $normalized_root, $html, 'The missing custom root error is visible to admins.');
    assert_contains_text('Search is unavailable because lexical resources could not be loaded', $html, 'Search setup failure is explained instead of hidden.');
    assert_contains_text('name="lft_query" value="orchard" class="regular-text" disabled="disabled"', $html, 'The search query control is disabled while lexical resources are unavailable.');
    assert_contains_text('<select id="lft-language" name="lft_language" disabled="disabled">', $html, 'The search language selector is disabled while lexical resources are unavailable.');
    assert_contains_text('<button type="submit" disabled="disabled">Search</button>', $html, 'The search submit control is disabled while lexical resources are unavailable.');
    assert_not_contains_text('English visible: orchard', $html, 'Sample search links are hidden while lexical resources are unavailable.');
    assert_not_contains_text('No matches for', $html, 'The admin page does not present an empty result set as a successful search.');
});

test_case('admin page disables search and records status when custom lexical packs exceed runtime caps', function (): void {
    $terms = language_fts_numbered_terms('term', Language_FTS_Playground_Lexical_Profile_Repository::DEFAULT_MAX_SYNSET_SIZE + 1);
    $lexeme_rows = "# observed\tcanonical\tprovenance\n";
    foreach ($terms as $term) {
        $lexeme_rows .= $term . "\t" . $term . "\tfixture\n";
    }
    $root = create_language_fts_temp_profile_tree(
        $lexeme_rows,
        "# source\ttarget\tdirection\tweight\tprovenance\n",
        "# concept_id\tweight\tprovenance\tterms\nconcept.too-wide\t0.5\tfixture\t" . implode(' ', $terms) . "\n"
    );
    write_language_fts_temp_pack_metadata($root . DIRECTORY_SEPARATOR . 'xx');
    $normalized_root = Language_FTS_Playground_Lexical_Profile_Repository::normalize_resource_root($root);

    try {
        reset_language_fts_plugin_runtime();
        add_filter(
            'language_fts_playground_lexical_resource_root',
            static fn(): string => $normalized_root,
            10,
            1
        );

        ob_start();
        Language_FTS_Playground_Plugin::render_admin_page();
        $html = ob_get_clean();
        $status = Language_FTS_Playground_Plugin::index_status();

        assert_contains_text('Search is unavailable because lexical resources could not be loaded', $html, 'Oversized custom resources make admin search unavailable.');
        assert_contains_text('max synset size ' . Language_FTS_Playground_Lexical_Profile_Repository::DEFAULT_MAX_SYNSET_SIZE, $html, 'The runtime cap failure is visible in admin output.');
        assert_contains_text('concept.too-wide', $html, 'The rejected concept is visible in admin output.');
        assert_contains_text('name="lft_query" value="orchard" class="regular-text" disabled="disabled"', $html, 'The search query control is disabled for oversized custom packs.');
        assert_contains_text('Could not load Language FTS analyzer resources for admin search.', (string) ($status['last_status'] ?? ''), 'Index status records the analyzer resource failure.');
        assert_contains_text('max synset size ' . Language_FTS_Playground_Lexical_Profile_Repository::DEFAULT_MAX_SYNSET_SIZE, (string) ($status['last_error'] ?? ''), 'Index status records the runtime cap failure.');
        assert_not_contains_text('No matches for', $html, 'The admin page does not present an empty result set when runtime packs are invalid.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

test_case('admin automatic results show matched language partition', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $post = fixture_post(403, 'pl', 'Admin Polish synonym', '<p>wyszukiwania w polskiej partycji.</p>');
    $GLOBALS['language_fts_test_posts'][403] = $post;
    $indexer = new Language_FTS_Playground_Indexer($storage, new Language_FTS_Playground_Analyzer());
    $indexer->index_post($post);
    $_GET['lft_query'] = 'szukanie';
    $_GET['lft_language'] = 'auto';

    ob_start();
    Language_FTS_Playground_Plugin::render_admin_page();
    $html = ob_get_clean();

    assert_contains_text('<option value="auto" selected="selected">Automatic</option>', $html, 'Automatic remains selected after an auto search.');
    assert_contains_text('<th>Matched language</th>', $html, 'Admin search results include a clearly labeled matched-language column.');
    assert_contains_text('<td><code>pl</code></td><td><mark>wyszukiwania</mark>', $html, 'Auto results show the Polish partition next to the highlighted synonym match.');
});

test_case('admin renders escaped search diagnostics for unsafe query text', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $post = fixture_post(
        404,
        'en',
        'Admin diagnostics',
        '<p>Stories keep unsafe &lt;script&gt;alert(1)&lt;/script&gt; text visible.</p>'
    );
    $GLOBALS['language_fts_test_posts'][404] = $post;
    $indexer = new Language_FTS_Playground_Indexer($storage, new Language_FTS_Playground_Analyzer());
    $indexer->index_post($post);
    $_GET['lft_query'] = 'story &lt;script&gt;alert(1)&lt;/script&gt;';
    $_GET['lft_language'] = 'en';

    ob_start();
    Language_FTS_Playground_Plugin::render_admin_page();
    $html = ob_get_clean();

    assert_contains_text('Search diagnostics', $html, 'Admin search renders a diagnostics section.');
    assert_contains_text('&amp;lt;script&amp;gt;alert(1)&amp;lt;/script&amp;gt;', $html, 'Diagnostics escape unsafe-looking query text inside JSON.');
    assert_not_contains_text('<script>', $html, 'Diagnostics do not emit raw script tags.');
});

test_case('renders admin snippets and matched fields safely', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $post = fixture_post(
        402,
        'en',
        'Admin safe snippet',
        '<p>Stories keep unsafe &lt;script&gt;alert(1)&lt;/script&gt; text visible.</p>'
    );
    $GLOBALS['language_fts_test_posts'][402] = $post;
    $indexer = new Language_FTS_Playground_Indexer($storage, new Language_FTS_Playground_Analyzer());
    $indexer->index_post($post);
    $_GET['lft_query'] = 'story';
    $_GET['lft_language'] = 'en';

    ob_start();
    Language_FTS_Playground_Plugin::render_admin_page();
    $html = ob_get_clean();

    assert_contains_text('Snippet', $html, 'Admin search results include a snippet column.');
    assert_contains_text('Matched fields', $html, 'Admin search results include a matched-fields column.');
    assert_contains_text('<mark>Stories</mark>', $html, 'Admin snippets preserve generated highlighted stem-match markup.');
    assert_contains_text('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'Admin snippets preserve escaped unsafe-looking source text.');
    assert_not_contains_text('<script>', $html, 'Admin snippets do not emit raw unsafe source HTML.');
});

test_case('surfaces storage failures in admin without fataling the page', function (): void {
    reset_language_fts_plugin_runtime(new Language_FTS_Playground_Test_Failing_Storage('Stored rows are temporarily unavailable.'));

    ob_start();
    Language_FTS_Playground_Plugin::render_admin_page();
    $html = ob_get_clean();

    assert_contains_text('Language FTS Playground', $html, 'The admin page still renders its shell.');
    assert_contains_text('Stored rows are temporarily unavailable.', $html, 'Storage errors are shown as admin notices.');
});

test_case('constant lexical root is used by plugin analyzer and admin validator', function (): void {
    assert_true(!defined('LANGUAGE_FTS_PLAYGROUND_LEXICAL_RESOURCE_ROOT'), 'The lexical root constant is not defined before the constant override test.');

    $root = create_language_fts_temp_profile_tree(
        "# observed\tcanonical\tprovenance\nconstant\tconstant\tfixture\nreplacement\treplacement\tfixture\n",
        "# source\ttarget\tdirection\tweight\tprovenance\nconstant\treplacement\tquery_to_index\t0.7\tfixture-constant-root\n"
    );
    $language_dir = $root . DIRECTORY_SEPARATOR . 'xx';
    write_language_fts_temp_pack_metadata($language_dir, [
        'source_name' => 'Constant <script> source',
        'provenance' => 'fixture-constant-custom-root',
    ]);
    $normalized_root = Language_FTS_Playground_Lexical_Profile_Repository::normalize_resource_root($root);

    try {
        define('LANGUAGE_FTS_PLAYGROUND_LEXICAL_RESOURCE_ROOT', $normalized_root . DIRECTORY_SEPARATOR);
        reset_language_fts_plugin_runtime();

        assert_same($normalized_root, Language_FTS_Playground_Plugin::lexical_resource_root(), 'The lexical root constant overrides the bundled root and normalizes trailing slashes.');
        $analyzer = Language_FTS_Playground_Plugin::analyzer();
        assert_same(['xx'], $analyzer->enabled_languages(), 'The plugin analyzer is built from the constant lexical root.');
        $query_terms = $analyzer->analyze_query('constant', 'xx');
        $expansions = $analyzer->expand_query_synonyms($query_terms, 'xx');
        assert_same(['replacement'], array_column($expansions['constant'] ?? [], 'term'), 'The constant custom pack changes analyzer synonym behavior.');

        ob_start();
        Language_FTS_Playground_Plugin::render_admin_page();
        $html = ob_get_clean();

        assert_contains_text('<code>' . esc_html($normalized_root) . '</code>', $html, 'Admin lexical status validates the constant resource root.');
        assert_contains_text('Constant &lt;script&gt; source', $html, 'Admin lexical status escapes constant-pack source names.');
        assert_not_contains_text('<script>', $html, 'Admin lexical status does not emit raw constant-pack source markup.');
    } finally {
        remove_language_fts_temp_tree($root);
    }
});

$failures = 0;
foreach ($tests as $test) {
    try {
        $test['fn']();
        echo "PASS {$test['name']}\n";
    } catch (Throwable $throwable) {
        $failures++;
        echo "FAIL {$test['name']}\n{$throwable->getMessage()}\n";
    }
}

if ($failures > 0) {
    exit(1);
}

echo 'All ' . count($tests) . " Language FTS Playground tests passed.\n";
