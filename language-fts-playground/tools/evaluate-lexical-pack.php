#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Evaluate lexical pack relevance against a compact JSON fixture.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "evaluate-lexical-pack.php must run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';

exit(language_fts_evaluate_pack_main($_SERVER['argv'] ?? []));

/**
 * @param string[] $argv
 */
function language_fts_evaluate_pack_main(array $argv): int
{
    try {
        $options = language_fts_evaluate_pack_parse_options(array_slice($argv, 1));
    } catch (InvalidArgumentException $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        language_fts_evaluate_pack_usage(STDERR);

        return 1;
    }

    if ($options['help']) {
        language_fts_evaluate_pack_usage(STDOUT);

        return 0;
    }

    try {
        $fixture = language_fts_evaluate_pack_load_fixture($options['fixture']);
        $fixture_thresholds = language_fts_evaluate_pack_fixture_thresholds($fixture);
        $thresholds = array_merge($fixture_thresholds, $options['thresholds']);
        $report = language_fts_evaluate_pack_run($fixture, $options['fixture'], $options['resource_root'], $thresholds);
    } catch (Throwable $throwable) {
        fwrite(STDERR, $throwable->getMessage() . "\n");

        return 1;
    }

    if ($options['json']) {
        echo language_fts_evaluate_pack_json($report) . "\n";
    } else {
        language_fts_evaluate_pack_print_human($report);
    }

    return !empty($report['passed']) ? 0 : 1;
}

/**
 * @param string[] $args
 * @return array{fixture:string,resource_root:string|null,json:bool,help:bool,thresholds:array<string,float>}
 */
function language_fts_evaluate_pack_parse_options(array $args): array
{
    $options = [
        'fixture' => '',
        'resource_root' => null,
        'json' => false,
        'help' => false,
        'thresholds' => [],
    ];

    $count = count($args);
    for ($i = 0; $i < $count; $i++) {
        $arg = $args[$i];
        $arg = (string) $arg;
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        if ($arg === '--json') {
            $options['json'] = true;
            continue;
        }

        if (str_starts_with($arg, '--')) {
            $raw_option = substr($arg, 2);
            if (str_contains($raw_option, '=')) {
                [$name, $value] = explode('=', $raw_option, 2);
            } else {
                $name = $raw_option;
                if (!isset($args[$i + 1]) || str_starts_with((string) $args[$i + 1], '--')) {
                    throw new InvalidArgumentException('Option requires a value: ' . $arg);
                }
                $value = (string) $args[++$i];
            }

            $name = str_replace('-', '_', $name);
            switch ($name) {
                case 'suite':
                    if ($options['fixture'] !== '') {
                        throw new InvalidArgumentException('Only one fixture path may be provided.');
                    }
                    $options['fixture'] = $value;
                    break;

                case 'resource_root':
                    $options['resource_root'] = $value;
                    break;

                case 'min_recall_at_5':
                    $options['thresholds']['recall_at_5'] = language_fts_evaluate_pack_threshold_float($value, '--min-recall-at-5');
                    break;

                case 'min_precision_at_5':
                    $options['thresholds']['precision_at_5'] = language_fts_evaluate_pack_threshold_float($value, '--min-precision-at-5');
                    break;

                case 'min_mrr':
                    $options['thresholds']['mrr'] = language_fts_evaluate_pack_threshold_float($value, '--min-mrr');
                    break;

                case 'min_ndcg_at_5':
                    $options['thresholds']['ndcg_at_5'] = language_fts_evaluate_pack_threshold_float($value, '--min-ndcg-at-5');
                    break;

                default:
                    throw new InvalidArgumentException('Unknown option: --' . str_replace('_', '-', $name));
            }
            continue;
        }

        if ($options['fixture'] !== '') {
            throw new InvalidArgumentException('Only one fixture path may be provided.');
        }
        $options['fixture'] = $arg;
    }

    if (!$options['help'] && $options['fixture'] === '') {
        throw new InvalidArgumentException('A fixture JSON path is required.');
    }

    return $options;
}

function language_fts_evaluate_pack_threshold_float(string $value, string $option): float
{
    if (preg_match('/^(?:0(?:\.[0-9]+)?|1(?:\.0+)?)$/', $value) !== 1) {
        throw new InvalidArgumentException($option . ' must be a float between 0 and 1.');
    }

    return (float) $value;
}

/**
 * @return array<string,mixed>
 */
function language_fts_evaluate_pack_load_fixture(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Fixture does not exist: ' . $path);
    }

    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Could not read fixture: ' . $path);
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded) || array_is_list($decoded)) {
        $message = json_last_error() === JSON_ERROR_NONE ? 'Fixture root must be a JSON object.' : json_last_error_msg();
        throw new UnexpectedValueException('Invalid fixture JSON in ' . $path . ': ' . $message);
    }

    return $decoded;
}

/**
 * @param array<string,mixed> $fixture
 * @return array<string,float>
 */
function language_fts_evaluate_pack_fixture_thresholds(array $fixture): array
{
    $raw = $fixture['thresholds'] ?? [];
    if ($raw === null || $raw === []) {
        return [];
    }
    if (!is_array($raw) || array_is_list($raw)) {
        throw new UnexpectedValueException('Fixture thresholds must be a JSON object.');
    }

    $thresholds = [];
    foreach ($raw as $name => $value) {
        $metric = language_fts_evaluate_pack_normalize_metric_name((string) $name);
        if ($metric === null) {
            throw new UnexpectedValueException('Unknown fixture threshold metric: ' . (string) $name);
        }
        if (!is_int($value) && !is_float($value)) {
            throw new UnexpectedValueException('Fixture threshold for ' . (string) $name . ' must be numeric.');
        }
        $float = (float) $value;
        if ($float < 0.0 || $float > 1.0) {
            throw new UnexpectedValueException('Fixture threshold for ' . (string) $name . ' must be between 0 and 1.');
        }
        $thresholds[$metric] = $float;
    }

    return $thresholds;
}

function language_fts_evaluate_pack_normalize_metric_name(string $name): ?string
{
    $name = strtolower(trim($name));
    $name = str_replace(['-', '@'], ['_', '_at_'], $name);
    $name = preg_replace('/_+/', '_', $name);
    $name = is_string($name) ? trim($name, '_') : '';
    $aliases = [
        'recall_at_5' => 'recall_at_5',
        'min_recall_at_5' => 'recall_at_5',
        'precision_at_5' => 'precision_at_5',
        'min_precision_at_5' => 'precision_at_5',
        'mrr' => 'mrr',
        'min_mrr' => 'mrr',
        'ndcg_at_5' => 'ndcg_at_5',
        'min_ndcg_at_5' => 'ndcg_at_5',
    ];

    return $aliases[$name] ?? null;
}

/**
 * @param array<string,mixed> $fixture
 * @param array<string,float> $thresholds
 * @return array<string,mixed>
 */
function language_fts_evaluate_pack_run(array $fixture, string $fixture_path, string|null $resource_root, array $thresholds): array
{
    $top_k = 5;
    $repository = new Language_FTS_Playground_Lexical_Profile_Repository($resource_root);
    $analyzer = new Language_FTS_Playground_Analyzer($repository);
    $storage = new Language_FTS_Playground_In_Memory_Storage();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $documents = language_fts_evaluate_pack_documents($fixture);
    $queries = language_fts_evaluate_pack_queries($fixture, array_keys($documents));

    $posts = [];
    $numeric_to_fixture_ids = [];
    $next_post_id = 1;
    foreach ($documents as $fixture_id => $document) {
        $post_id = $next_post_id++;
        $numeric_to_fixture_ids[$post_id] = $fixture_id;
        $posts[] = language_fts_evaluate_pack_document_to_post($document, $post_id);
    }

    $storage->install();
    $indexer->rebuild($posts);

    $query_reports = [];
    $metric_sums = [
        'recall_at_5' => 0.0,
        'precision_at_5' => 0.0,
        'mrr' => 0.0,
        'ndcg_at_5' => 0.0,
    ];
    $failures = [];

    foreach ($queries as $offset => $query) {
        $results = $searcher->search($query['query'], $query['language'], $top_k);
        $diagnostics = $searcher->explain($query['query'], $query['language'], $top_k);
        $diagnostic_results = [];
        foreach ((array) ($diagnostics['results'] ?? []) as $diagnostic_result) {
            if (!is_array($diagnostic_result)) {
                continue;
            }
            $fixture_id = $numeric_to_fixture_ids[(int) ($diagnostic_result['post_id'] ?? 0)] ?? '';
            if ($fixture_id !== '') {
                $diagnostic_results[$fixture_id] = $diagnostic_result;
            }
        }

        $top_hits = [];
        foreach ($results as $rank => $result) {
            $fixture_id = $numeric_to_fixture_ids[(int) $result['post_id']] ?? '#' . (string) $result['post_id'];
            $diagnostic_result = $diagnostic_results[$fixture_id] ?? [];
            $diagnostic_result = is_array($diagnostic_result) ? $diagnostic_result : [];
            $top_hits[] = [
                'rank' => $rank + 1,
                'id' => $fixture_id,
                'score' => language_fts_evaluate_pack_round_metric((float) $result['score']),
                'matched_language' => (string) $result['matched_language'],
                'matched_terms' => array_values(array_map('strval', $result['matched_terms'] ?? [])),
                'matched_fields' => array_values(array_map('strval', $result['matched_fields'] ?? [])),
                'match_classes' => array_values(array_map('strval', (array) ($diagnostic_result['match_classes'] ?? []))),
                'snippet' => (string) ($result['snippet'] ?? ''),
            ];
        }

        $top_ids = array_values(array_map(static fn(array $hit): string => (string) $hit['id'], $top_hits));
        $metrics = language_fts_evaluate_pack_query_metrics($top_ids, $query['relevant'], $top_k);
        foreach ($metric_sums as $metric => $_sum) {
            $metric_sums[$metric] += $metrics[$metric];
        }

        $misses = array_values(array_filter(
            $query['relevant'],
            static fn(string $id): bool => !in_array($id, $top_ids, true)
        ));
        $unexpected = [];
        foreach ($top_ids as $id) {
            if (in_array($id, $query['irrelevant'], true)) {
                $unexpected[] = $id;
            }
        }

        if ($unexpected !== []) {
            $failures[] = 'Unexpected top-' . $top_k . ' hit for query "' . $query['query'] . '": ' . implode(', ', $unexpected);
        }

        $expectation_failures = language_fts_evaluate_pack_expectation_failures(
            $query,
            $top_hits,
            $top_ids,
            $diagnostics
        );
        foreach ($expectation_failures as $failure) {
            $failures[] = $failure;
        }

        $query_reports[] = [
            'ordinal' => $offset + 1,
            'query' => $query['query'],
            'language' => $query['language'],
            'notes' => $query['notes'],
            'relevant' => $query['relevant'],
            'irrelevant' => $query['irrelevant'],
            'top_hits' => $top_hits,
            'metrics' => $metrics,
            'misses' => $misses,
            'unexpected_top_hits' => $unexpected,
            'expectations' => $query['expectations'],
            'expectation_failures' => $expectation_failures,
            'explain_summary' => language_fts_evaluate_pack_explain_summary($diagnostics, $numeric_to_fixture_ids),
        ];
    }

    $query_count = count($query_reports);
    $aggregate = [];
    foreach ($metric_sums as $metric => $sum) {
        $aggregate[$metric] = language_fts_evaluate_pack_round_metric($sum / max(1, $query_count));
    }

    $threshold_results = [];
    foreach ($thresholds as $metric => $minimum) {
        $actual = (float) ($aggregate[$metric] ?? 0.0);
        $passed = $actual + 0.000000000001 >= $minimum;
        $threshold_results[$metric] = [
            'minimum' => language_fts_evaluate_pack_round_metric($minimum),
            'actual' => language_fts_evaluate_pack_round_metric($actual),
            'passed' => $passed,
        ];
        if (!$passed) {
            $failures[] = language_fts_evaluate_pack_metric_label($metric) . ' ' . language_fts_evaluate_pack_format_metric($actual)
                . ' is below minimum ' . language_fts_evaluate_pack_format_metric($minimum);
        }
    }

    return [
        'passed' => $failures === [],
        'fixture' => [
            'path' => $fixture_path,
            'name' => language_fts_evaluate_pack_optional_string($fixture['name'] ?? null) ?? basename($fixture_path),
            'source' => language_fts_evaluate_pack_optional_string($fixture['source'] ?? null),
            'language_pack_expectations' => language_fts_evaluate_pack_string_map($fixture['language_pack_expectations'] ?? []),
        ],
        'resource_root' => $repository->resource_root(),
        'top_k' => $top_k,
        'document_count' => count($documents),
        'query_count' => $query_count,
        'enabled_languages' => $analyzer->enabled_languages(),
        'thresholds' => $threshold_results,
        'metrics' => $aggregate,
        'queries' => $query_reports,
        'failures' => $failures,
    ];
}

/**
 * @param array<string,mixed> $fixture
 * @return array<string,array<string,mixed>>
 */
function language_fts_evaluate_pack_documents(array $fixture): array
{
    $raw_documents = $fixture['documents'] ?? null;
    if (!is_array($raw_documents) || !array_is_list($raw_documents) || $raw_documents === []) {
        throw new UnexpectedValueException('Fixture documents must be a non-empty array.');
    }

    $documents = [];
    foreach ($raw_documents as $offset => $document) {
        if (!is_array($document) || array_is_list($document)) {
            throw new UnexpectedValueException('Fixture document at index ' . (string) $offset . ' must be an object.');
        }

        $id = language_fts_evaluate_pack_required_string($document, 'id', 'document at index ' . (string) $offset);
        if (isset($documents[$id])) {
            throw new UnexpectedValueException('Duplicate fixture document id: ' . $id);
        }

        $documents[$id] = [
            'id' => $id,
            'language' => language_fts_evaluate_pack_required_string($document, 'language', 'document ' . $id),
            'title' => language_fts_evaluate_pack_required_string($document, 'title', 'document ' . $id),
            'excerpt' => language_fts_evaluate_pack_optional_string($document['excerpt'] ?? null) ?? '',
            'content' => language_fts_evaluate_pack_optional_string($document['content'] ?? null) ?? '',
            'searchable_html' => language_fts_evaluate_pack_optional_string($document['searchable_html'] ?? ($document['html'] ?? null)) ?? '',
            'image_alt' => language_fts_evaluate_pack_optional_string_list($document['image_alt'] ?? ($document['image_alts'] ?? []), 'document ' . $id . ' image_alt'),
            'notes' => language_fts_evaluate_pack_optional_string($document['notes'] ?? null),
        ];
    }

    return $documents;
}

/**
 * @param array<string,mixed> $fixture
 * @param string[] $document_ids
 * @return array<int,array{query:string,language:string,relevant:string[],irrelevant:string[],notes:?string,expectations:array<string,mixed>}>
 */
function language_fts_evaluate_pack_queries(array $fixture, array $document_ids): array
{
    $raw_queries = $fixture['queries'] ?? null;
    if (!is_array($raw_queries) || !array_is_list($raw_queries) || $raw_queries === []) {
        throw new UnexpectedValueException('Fixture queries must be a non-empty array.');
    }

    $document_lookup = array_fill_keys($document_ids, true);
    $queries = [];
    foreach ($raw_queries as $offset => $query) {
        if (!is_array($query) || array_is_list($query)) {
            throw new UnexpectedValueException('Fixture query at index ' . (string) $offset . ' must be an object.');
        }

        $label = 'query at index ' . (string) $offset;
        $query_text = language_fts_evaluate_pack_required_string($query, 'query', $label);
        $language = language_fts_evaluate_pack_optional_string($query['language'] ?? null) ?? 'auto';
        $expectations = language_fts_evaluate_pack_query_expectations($query, $label);
        $relevant = language_fts_evaluate_pack_document_id_list(
            $query['relevant'] ?? ($query['relevant_document_ids'] ?? ($query['relevant_ids'] ?? null)),
            $label . ' relevant',
            empty($expectations['no_results'])
        );
        $irrelevant = language_fts_evaluate_pack_document_id_list(
            $query['irrelevant'] ?? ($query['irrelevant_document_ids'] ?? ($query['irrelevant_ids'] ?? [])),
            $label . ' irrelevant',
            false
        );

        foreach (array_merge($relevant, $irrelevant, language_fts_evaluate_pack_expectation_document_ids($expectations)) as $id) {
            if (!isset($document_lookup[$id])) {
                throw new UnexpectedValueException($label . ' references unknown document id: ' . $id);
            }
        }

        $overlap = array_values(array_intersect($relevant, $irrelevant));
        if ($overlap !== []) {
            throw new UnexpectedValueException($label . ' lists a document as both relevant and irrelevant: ' . implode(', ', $overlap));
        }

        $queries[] = [
            'query' => $query_text,
            'language' => $language,
            'relevant' => $relevant,
            'irrelevant' => $irrelevant,
            'notes' => language_fts_evaluate_pack_optional_string($query['notes'] ?? ($query['provenance'] ?? null)),
            'expectations' => $expectations,
        ];
    }

    return $queries;
}

/**
 * @param array<string,mixed> $query
 * @return array<string,mixed>
 */
function language_fts_evaluate_pack_query_expectations(array $query, string $label): array
{
    $raw = $query['expect'] ?? ($query['expectations'] ?? []);
    if ($raw === null || $raw === []) {
        return language_fts_evaluate_pack_empty_expectations();
    }
    if (!is_array($raw) || array_is_list($raw)) {
        throw new UnexpectedValueException($label . ' expect must be a JSON object when present.');
    }

    $top_ids = language_fts_evaluate_pack_optional_string_list(
        $raw['top_ids'] ?? ($raw['ordered_ids'] ?? ($raw['expected_top_ids'] ?? [])),
        $label . ' expect.top_ids'
    );

    $expectations = [
        'no_results' => language_fts_evaluate_pack_optional_bool($raw['no_results'] ?? ($raw['expect_no_results'] ?? false), $label . ' expect.no_results'),
        'top_ids' => $top_ids,
        'selected_partitions' => language_fts_evaluate_pack_optional_string_list(
            $raw['selected_partitions'] ?? ($raw['expected_selected_partitions'] ?? []),
            $label . ' expect.selected_partitions'
        ),
        'diagnostics_contains' => language_fts_evaluate_pack_optional_string_list(
            $raw['diagnostics_contains'] ?? [],
            $label . ' expect.diagnostics_contains'
        ),
        'diagnostics_not_contains' => language_fts_evaluate_pack_optional_string_list(
            $raw['diagnostics_not_contains'] ?? [],
            $label . ' expect.diagnostics_not_contains'
        ),
        'matched_fields' => language_fts_evaluate_pack_expectation_map(
            $raw['matched_fields'] ?? [],
            $label . ' expect.matched_fields'
        ),
        'matched_terms' => language_fts_evaluate_pack_expectation_map(
            $raw['matched_terms'] ?? [],
            $label . ' expect.matched_terms'
        ),
        'match_classes' => language_fts_evaluate_pack_expectation_map(
            $raw['match_classes'] ?? [],
            $label . ' expect.match_classes'
        ),
        'snippet_contains' => language_fts_evaluate_pack_expectation_map(
            $raw['snippet_contains'] ?? [],
            $label . ' expect.snippet_contains'
        ),
        'snippet_not_contains' => language_fts_evaluate_pack_expectation_map(
            $raw['snippet_not_contains'] ?? [],
            $label . ' expect.snippet_not_contains'
        ),
    ];

    if ($expectations['no_results'] && $top_ids !== []) {
        throw new UnexpectedValueException($label . ' cannot combine expect.no_results with expect.top_ids.');
    }

    return $expectations;
}

/**
 * @return array<string,mixed>
 */
function language_fts_evaluate_pack_empty_expectations(): array
{
    return [
        'no_results' => false,
        'top_ids' => [],
        'selected_partitions' => [],
        'diagnostics_contains' => [],
        'diagnostics_not_contains' => [],
        'matched_fields' => [],
        'matched_terms' => [],
        'match_classes' => [],
        'snippet_contains' => [],
        'snippet_not_contains' => [],
    ];
}

function language_fts_evaluate_pack_optional_bool(mixed $value, string $context): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if ($value === null || $value === '') {
        return false;
    }

    throw new UnexpectedValueException($context . ' must be a boolean.');
}

/**
 * @return array<string,string[]>
 */
function language_fts_evaluate_pack_expectation_map(mixed $value, string $context): array
{
    if ($value === null || $value === []) {
        return [];
    }
    if (!is_array($value) || array_is_list($value)) {
        throw new UnexpectedValueException($context . ' must be a JSON object keyed by document id.');
    }

    $map = [];
    foreach ($value as $document_id => $items) {
        if (!is_string($document_id) || trim($document_id) === '') {
            throw new UnexpectedValueException($context . ' keys must be non-empty document ids.');
        }
        $map[$document_id] = language_fts_evaluate_pack_optional_string_list($items, $context . '.' . $document_id);
    }
    ksort($map, SORT_STRING);

    return $map;
}

/**
 * @param array<string,mixed> $expectations
 * @return string[]
 */
function language_fts_evaluate_pack_expectation_document_ids(array $expectations): array
{
    $ids = [];
    foreach (['top_ids', 'matched_fields', 'matched_terms', 'match_classes', 'snippet_contains', 'snippet_not_contains'] as $key) {
        $value = $expectations[$key] ?? [];
        if ($key === 'top_ids') {
            foreach ((array) $value as $id) {
                $ids[] = (string) $id;
            }
            continue;
        }
        foreach (array_keys((array) $value) as $id) {
            $ids[] = (string) $id;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * @param array<string,mixed> $document
 */
function language_fts_evaluate_pack_document_to_post(array $document, int $post_id): object
{
    $content_parts = [];
    foreach (['content', 'searchable_html'] as $field) {
        $value = trim((string) ($document[$field] ?? ''));
        if ($value !== '') {
            $content_parts[] = $value;
        }
    }

    foreach ((array) ($document['image_alt'] ?? []) as $alt) {
        $content_parts[] = '<img alt="' . htmlspecialchars((string) $alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
    }

    return (object) [
        'ID' => $post_id,
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_password' => '',
        'post_title' => (string) $document['title'],
        'post_excerpt' => (string) ($document['excerpt'] ?? ''),
        'post_content' => implode("\n", $content_parts),
        'language' => (string) $document['language'],
    ];
}

/**
 * @param array<string,mixed> $object
 */
function language_fts_evaluate_pack_required_string(array $object, string $key, string $context): string
{
    $value = $object[$key] ?? null;
    if (!is_scalar($value) || trim((string) $value) === '') {
        throw new UnexpectedValueException($context . ' must include a non-empty string ' . $key . '.');
    }

    return trim((string) $value);
}

function language_fts_evaluate_pack_optional_string(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    if (!is_scalar($value)) {
        throw new UnexpectedValueException('Expected a string-compatible scalar value.');
    }

    return (string) $value;
}

/**
 * @return string[]
 */
function language_fts_evaluate_pack_optional_string_list(mixed $value, string $context): array
{
    if ($value === null || $value === '') {
        return [];
    }
    if (is_scalar($value)) {
        return [(string) $value];
    }
    if (!is_array($value) || !array_is_list($value)) {
        throw new UnexpectedValueException($context . ' must be a string or array of strings.');
    }

    $items = [];
    foreach ($value as $item) {
        if (!is_scalar($item)) {
            throw new UnexpectedValueException($context . ' must contain only strings.');
        }
        $items[] = (string) $item;
    }

    return $items;
}

/**
 * @return string[]
 */
function language_fts_evaluate_pack_document_id_list(mixed $value, string $context, bool $required): array
{
    if ($value === null) {
        if ($required) {
            throw new UnexpectedValueException($context . ' must be a non-empty array of document ids.');
        }

        return [];
    }
    if (!is_array($value) || !array_is_list($value) || ($required && $value === [])) {
        throw new UnexpectedValueException($context . ' must be ' . ($required ? 'a non-empty' : 'an') . ' array of document ids.');
    }

    $ids = [];
    foreach ($value as $item) {
        if (!is_scalar($item) || trim((string) $item) === '') {
            throw new UnexpectedValueException($context . ' must contain only non-empty scalar document ids.');
        }
        $ids[] = trim((string) $item);
    }

    return array_values(array_unique($ids));
}

/**
 * @param string[] $top_ids
 * @param string[] $relevant
 * @return array{recall_at_5:float,precision_at_5:float,mrr:float,ndcg_at_5:float}
 */
function language_fts_evaluate_pack_query_metrics(array $top_ids, array $relevant, int $top_k): array
{
    if ($relevant === []) {
        $empty_pass = $top_ids === [] ? 1.0 : 0.0;

        return [
            'recall_at_5' => $empty_pass,
            'precision_at_5' => $empty_pass,
            'mrr' => $empty_pass,
            'ndcg_at_5' => $empty_pass,
        ];
    }

    $relevant_lookup = array_fill_keys($relevant, true);
    $relevant_hits = 0;
    $mrr = 0.0;
    $dcg = 0.0;

    foreach (array_slice($top_ids, 0, $top_k) as $rank => $id) {
        if (!isset($relevant_lookup[$id])) {
            continue;
        }

        $relevant_hits++;
        $rank_one_based = $rank + 1;
        if ($mrr === 0.0) {
            $mrr = 1.0 / $rank_one_based;
        }
        $dcg += 1.0 / log($rank_one_based + 1, 2);
    }

    $ideal_hits = min(count($relevant), $top_k);
    $idcg = 0.0;
    for ($rank = 1; $rank <= $ideal_hits; $rank++) {
        $idcg += 1.0 / log($rank + 1, 2);
    }

    return [
        'recall_at_5' => language_fts_evaluate_pack_round_metric($relevant_hits / max(1, count($relevant))),
        'precision_at_5' => language_fts_evaluate_pack_round_metric($relevant_hits / max(1, $top_k)),
        'mrr' => language_fts_evaluate_pack_round_metric($mrr),
        'ndcg_at_5' => language_fts_evaluate_pack_round_metric($idcg > 0.0 ? $dcg / $idcg : 0.0),
    ];
}

/**
 * @param array{query:string,language:string,relevant:string[],irrelevant:string[],notes:?string,expectations:array<string,mixed>} $query
 * @param array<int,array<string,mixed>> $top_hits
 * @param string[] $top_ids
 * @param array<string,mixed> $diagnostics
 * @return string[]
 */
function language_fts_evaluate_pack_expectation_failures(array $query, array $top_hits, array $top_ids, array $diagnostics): array
{
    $expectations = $query['expectations'];
    $failures = [];
    $query_label = 'query "' . $query['query'] . '"';

    if (!empty($expectations['no_results']) && $top_ids !== []) {
        $failures[] = $query_label . ' expected no results, got: ' . implode(', ', $top_ids);
    }

    $expected_top_ids = array_values(array_map('strval', (array) ($expectations['top_ids'] ?? [])));
    if ($expected_top_ids !== []) {
        $actual_prefix = array_slice($top_ids, 0, count($expected_top_ids));
        if ($actual_prefix !== $expected_top_ids) {
            $failures[] = $query_label . ' expected top ids [' . implode(', ', $expected_top_ids)
                . '], got [' . implode(', ', $actual_prefix) . ']';
        }
    }

    $selected_partitions = array_values(array_map('strval', (array) (($diagnostics['language_routing'] ?? [])['selected_partitions'] ?? [])));
    $expected_partitions = array_values(array_map('strval', (array) ($expectations['selected_partitions'] ?? [])));
    if ($expected_partitions !== [] && $selected_partitions !== $expected_partitions) {
        $failures[] = $query_label . ' expected selected partitions [' . implode(', ', $expected_partitions)
            . '], got [' . implode(', ', $selected_partitions) . ']';
    }

    $diagnostics_json = language_fts_evaluate_pack_stable_json($diagnostics);
    foreach (array_values(array_map('strval', (array) ($expectations['diagnostics_contains'] ?? []))) as $needle) {
        if ($needle !== '' && !str_contains($diagnostics_json, $needle)) {
            $failures[] = $query_label . ' expected diagnostics to contain "' . $needle . '"';
        }
    }
    foreach (array_values(array_map('strval', (array) ($expectations['diagnostics_not_contains'] ?? []))) as $needle) {
        if ($needle !== '' && str_contains($diagnostics_json, $needle)) {
            $failures[] = $query_label . ' expected diagnostics not to contain "' . $needle . '"';
        }
    }

    $hits_by_id = [];
    foreach ($top_hits as $hit) {
        $id = (string) ($hit['id'] ?? '');
        if ($id !== '') {
            $hits_by_id[$id] = $hit;
        }
    }

    $list_checks = [
        'matched_fields' => 'matched_fields',
        'matched_terms' => 'matched_terms',
        'match_classes' => 'match_classes',
    ];
    foreach ($list_checks as $expectation_key => $hit_key) {
        foreach ((array) ($expectations[$expectation_key] ?? []) as $document_id => $required_values) {
            $hit = $hits_by_id[(string) $document_id] ?? null;
            if (!is_array($hit)) {
                $failures[] = $query_label . ' expected ' . $expectation_key . ' for missing top hit ' . (string) $document_id;
                continue;
            }
            $actual_values = array_values(array_map('strval', (array) ($hit[$hit_key] ?? [])));
            foreach (array_values(array_map('strval', (array) $required_values)) as $required_value) {
                if (!in_array($required_value, $actual_values, true)) {
                    $failures[] = $query_label . ' expected ' . (string) $document_id . ' ' . $expectation_key
                        . ' to contain "' . $required_value . '", got [' . implode(', ', $actual_values) . ']';
                }
            }
        }
    }

    foreach ((array) ($expectations['snippet_contains'] ?? []) as $document_id => $needles) {
        $snippet = (string) (($hits_by_id[(string) $document_id] ?? [])['snippet'] ?? '');
        foreach (array_values(array_map('strval', (array) $needles)) as $needle) {
            if ($needle !== '' && !str_contains($snippet, $needle)) {
                $failures[] = $query_label . ' expected ' . (string) $document_id . ' snippet to contain "' . $needle . '"';
            }
        }
    }

    foreach ((array) ($expectations['snippet_not_contains'] ?? []) as $document_id => $needles) {
        $snippet = (string) (($hits_by_id[(string) $document_id] ?? [])['snippet'] ?? '');
        foreach (array_values(array_map('strval', (array) $needles)) as $needle) {
            if ($needle !== '' && str_contains($snippet, $needle)) {
                $failures[] = $query_label . ' expected ' . (string) $document_id . ' snippet not to contain "' . $needle . '"';
            }
        }
    }

    return $failures;
}

/**
 * @param array<string,mixed> $diagnostics
 * @param array<int,string> $numeric_to_fixture_ids
 * @return array<string,mixed>
 */
function language_fts_evaluate_pack_explain_summary(array $diagnostics, array $numeric_to_fixture_ids): array
{
    $routing = is_array($diagnostics['language_routing'] ?? null) ? $diagnostics['language_routing'] : [];
    $results = [];
    foreach ((array) ($diagnostics['results'] ?? []) as $result) {
        if (!is_array($result)) {
            continue;
        }
        $post_id = (int) ($result['post_id'] ?? 0);
        $results[] = [
            'id' => $numeric_to_fixture_ids[$post_id] ?? '#' . (string) $post_id,
            'matched_language' => (string) ($result['matched_language'] ?? ''),
            'matched_fields' => array_values(array_map('strval', (array) ($result['matched_fields'] ?? []))),
            'matched_terms' => array_values(array_map('strval', (array) ($result['matched_terms'] ?? []))),
            'match_classes' => array_values(array_map('strval', (array) ($result['match_classes'] ?? []))),
        ];
    }

    return [
        'selected_partitions' => array_values(array_map('strval', (array) ($routing['selected_partitions'] ?? []))),
        'strategy' => (string) ($routing['strategy'] ?? ''),
        'preflight_evaluated' => (bool) (($routing['preflight'] ?? [])['evaluated'] ?? false),
        'no_result_causes' => array_values(array_map('strval', (array) ($diagnostics['no_result_causes'] ?? []))),
        'results' => $results,
    ];
}

/**
 * @param array<string,mixed> $value
 */
function language_fts_evaluate_pack_stable_json(array $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Could not encode evaluator diagnostics JSON.');
    }

    return $json;
}

function language_fts_evaluate_pack_round_metric(float $value): float
{
    return round($value, 6);
}

/**
 * @return array<string,string>
 */
function language_fts_evaluate_pack_string_map(mixed $value): array
{
    if ($value === null || $value === []) {
        return [];
    }
    if (!is_array($value) || array_is_list($value)) {
        throw new UnexpectedValueException('language_pack_expectations must be a JSON object when present.');
    }

    $map = [];
    foreach ($value as $key => $item) {
        if (!is_scalar($item)) {
            throw new UnexpectedValueException('language_pack_expectations values must be strings.');
        }
        $map[(string) $key] = (string) $item;
    }
    ksort($map, SORT_STRING);

    return $map;
}

/**
 * @param array<string,mixed> $report
 */
function language_fts_evaluate_pack_json(array $report): string
{
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Could not encode evaluator report JSON.');
    }

    return $json;
}

/**
 * @param array<string,mixed> $report
 */
function language_fts_evaluate_pack_print_human(array $report): void
{
    $fixture = is_array($report['fixture'] ?? null) ? $report['fixture'] : [];
    echo 'Lexical pack relevance evaluation: ' . (string) ($fixture['name'] ?? '') . "\n";
    echo 'Fixture: ' . (string) ($fixture['path'] ?? '') . "\n";
    echo 'Resource root: ' . (string) ($report['resource_root'] ?? '') . "\n";
    echo 'Documents: ' . (int) ($report['document_count'] ?? 0)
        . '; Queries: ' . (int) ($report['query_count'] ?? 0)
        . '; Top K: ' . (int) ($report['top_k'] ?? 0) . "\n";

    $source = $fixture['source'] ?? null;
    if (is_string($source) && trim($source) !== '') {
        echo 'Source: ' . $source . "\n";
    }

    $expectations = is_array($fixture['language_pack_expectations'] ?? null) ? $fixture['language_pack_expectations'] : [];
    if ($expectations !== []) {
        echo "Language pack expectations:\n";
        foreach ($expectations as $language => $expectation) {
            echo '  ' . (string) $language . ': ' . (string) $expectation . "\n";
        }
    }

    echo "\nMetrics:\n";
    foreach ((array) ($report['metrics'] ?? []) as $metric => $value) {
        echo '  ' . language_fts_evaluate_pack_metric_label((string) $metric) . ': ' . language_fts_evaluate_pack_format_metric((float) $value) . "\n";
    }

    $thresholds = is_array($report['thresholds'] ?? null) ? $report['thresholds'] : [];
    echo "\nThresholds:\n";
    if ($thresholds === []) {
        echo "  none configured\n";
    } else {
        foreach ($thresholds as $metric => $threshold) {
            if (!is_array($threshold)) {
                continue;
            }
            $prefix = !empty($threshold['passed']) ? 'OK' : 'FAIL';
            echo '  ' . $prefix . ' ' . language_fts_evaluate_pack_metric_label((string) $metric)
                . ' ' . language_fts_evaluate_pack_format_metric((float) ($threshold['actual'] ?? 0.0))
                . ' >= ' . language_fts_evaluate_pack_format_metric((float) ($threshold['minimum'] ?? 0.0)) . "\n";
        }
    }

    echo "\nQueries:\n";
    foreach ((array) ($report['queries'] ?? []) as $query) {
        if (!is_array($query)) {
            continue;
        }
        $misses = array_values(array_map('strval', (array) ($query['misses'] ?? [])));
        $unexpected = array_values(array_map('strval', (array) ($query['unexpected_top_hits'] ?? [])));
        $expectation_failures = array_values(array_map('strval', (array) ($query['expectation_failures'] ?? [])));
        $prefix = ($unexpected !== [] || $expectation_failures !== []) ? 'FAIL' : ($misses !== [] ? 'MISS' : 'OK');
        echo '  ' . $prefix . ' ' . (int) ($query['ordinal'] ?? 0) . '. '
            . (string) ($query['query'] ?? '') . ' [' . (string) ($query['language'] ?? '') . "]\n";
        echo '    relevant: ' . implode(', ', array_map('strval', (array) ($query['relevant'] ?? []))) . "\n";
        $summary = is_array($query['explain_summary'] ?? null) ? $query['explain_summary'] : [];
        $partitions = array_values(array_map('strval', (array) ($summary['selected_partitions'] ?? [])));
        if ($partitions !== []) {
            echo '    selected partitions: ' . implode(', ', $partitions) . "\n";
        }
        $top_hits = [];
        foreach ((array) ($query['top_hits'] ?? []) as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $top_hits[] = (string) ($hit['id'] ?? '') . ' (' . (string) ($hit['matched_language'] ?? '')
                . ', score ' . language_fts_evaluate_pack_format_metric((float) ($hit['score'] ?? 0.0)) . ')';
        }
        echo '    top-5: ' . ($top_hits === [] ? 'none' : implode(', ', $top_hits)) . "\n";
        if ($misses !== []) {
            echo '    missing relevant ids: ' . implode(', ', $misses) . "\n";
        }
        if ($unexpected !== []) {
            echo '    unexpected top-5 ids: ' . implode(', ', $unexpected) . "\n";
        }
        foreach ($expectation_failures as $failure) {
            echo '    expectation failure: ' . $failure . "\n";
        }
    }

    $failures = array_values(array_map('strval', (array) ($report['failures'] ?? [])));
    echo "\n";
    if (!empty($report['passed'])) {
        echo "Evaluation passed.\n";
        return;
    }

    echo "Evaluation failed:\n";
    foreach ($failures as $failure) {
        echo '  - ' . $failure . "\n";
    }
}

function language_fts_evaluate_pack_metric_label(string $metric): string
{
    return match ($metric) {
        'recall_at_5' => 'recall@5',
        'precision_at_5' => 'precision@5',
        'mrr' => 'MRR',
        'ndcg_at_5' => 'nDCG@5',
        default => $metric,
    };
}

function language_fts_evaluate_pack_format_metric(float $value): string
{
    return number_format($value, 4, '.', '');
}

/**
 * @param resource $stream
 */
function language_fts_evaluate_pack_usage($stream): void
{
    fwrite(
        $stream,
        "Usage: php evaluate-lexical-pack.php <fixture.json> [options]\n" .
        "Options:\n" .
        "  --suite=<fixture.json>          Alias for the fixture path.\n" .
        "  --json                         Emit deterministic JSON.\n" .
        "  --resource-root=<path>          Use a non-default language resource root.\n" .
        "  --min-recall-at-5=<float>       Fail when mean recall@5 is below the value.\n" .
        "  --min-precision-at-5=<float>    Fail when mean precision@5 is below the value.\n" .
        "  --min-mrr=<float>               Fail when mean MRR is below the value.\n" .
        "  --min-ndcg-at-5=<float>         Fail when mean nDCG@5 is below the value.\n"
    );
}
