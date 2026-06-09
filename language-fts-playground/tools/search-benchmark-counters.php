<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/**
 * @return array{scenario:string,suite:string|null,documents:int|null,languages:int|null,limit:int|null,json:bool,fail_on_gate:bool,help:bool,memory_budget_mb:int|null,wall_time_budget_ms:float|null}
 */
function language_fts_search_benchmark_parse_args(array $argv): array
{
    $options = [
        'scenario' => 'common-term',
        'suite' => null,
        'documents' => null,
        'languages' => null,
        'limit' => null,
        'json' => false,
        'fail_on_gate' => false,
        'help' => false,
        'memory_budget_mb' => null,
        'wall_time_budget_ms' => null,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = (string) $argv[$i];
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--json') {
            $options['json'] = true;
            continue;
        }
        if ($arg === '--fail-on-gate') {
            $options['fail_on_gate'] = true;
            continue;
        }

        if (preg_match('/^--([^=]+)=(.*)$/', $arg, $matches) !== 1) {
            throw new InvalidArgumentException('Unknown option syntax: ' . $arg);
        }

        $name = str_replace('-', '_', (string) $matches[1]);
        $value = (string) $matches[2];
        if ($name === 'scenario') {
            $options['scenario'] = $value;
        } elseif ($name === 'suite') {
            $options['suite'] = $value;
        } elseif ($name === 'documents') {
            $options['documents'] = max(1, (int) $value);
        } elseif ($name === 'languages') {
            $options['languages'] = max(1, (int) $value);
        } elseif ($name === 'limit') {
            $options['limit'] = max(1, (int) $value);
        } elseif ($name === 'memory_budget_mb') {
            $options['memory_budget_mb'] = max(0, (int) $value);
        } elseif ($name === 'wall_time_budget_ms') {
            $options['wall_time_budget_ms'] = max(0.0, (float) $value);
        } else {
            throw new InvalidArgumentException('Unknown option: --' . (string) $matches[1]);
        }
    }

    return $options;
}

function language_fts_search_benchmark_usage(): string
{
    return implode("\n", [
        'Usage: php language-fts-playground/tools/search-benchmark-counters.php [options]',
        '',
        'Options:',
        '  --scenario=<name>        common-term, phrase, fuzzy, synonym, phrase-synonym, mixed-field, or all',
        '  --suite=<name>           pr-smoke, scheduled, manual-stress, or all',
        '  --documents=<n>          Synthetic documents per generated language',
        '  --languages=<n>          Generated benchmark language count',
        '  --limit=<n>              Public result limit',
        '  --json                   Emit JSON instead of human-readable text',
        '  --fail-on-gate           Exit nonzero when any hard gate fails',
        '  --memory-budget-mb=<n>   Add a hard peak-memory gate',
        '  --wall-time-budget-ms=<n> Add an advisory wall-time gate',
        '  --help                   Show this help',
        '',
        'php -n compatible examples:',
        '  php -n language-fts-playground/tools/search-benchmark-counters.php --scenario=common-term --documents=64 --limit=5 --json',
        '  php -n language-fts-playground/tools/search-benchmark-counters.php --scenario=phrase-heavy --documents=64 --limit=5',
        '  php -n language-fts-playground/tools/search-benchmark-counters.php --suite=pr-smoke --json --fail-on-gate',
    ]) . "\n";
}

/**
 * @param array<string,mixed>|array<int,array<string,mixed>> $report
 */
function language_fts_search_benchmark_print_human(array $report): void
{
    echo "Language FTS search benchmark counters\n";
    if (isset($report['suite']) && isset($report['scenarios']) && is_array($report['scenarios'])) {
        $summary = (array) ($report['summary'] ?? []);
        echo 'Suite: ' . (string) $report['suite'] . "\n";
        echo 'Status: ' . (string) ($summary['status'] ?? 'unknown') . "\n";
        echo 'Hard gate failures: ' . (int) ($summary['hard_gate_failures'] ?? 0) . "\n";
        echo 'Advisory gate failures: ' . (int) ($summary['advisory_gate_failures'] ?? 0) . "\n\n";
        $reports = (array) $report['scenarios'];
    } else {
        echo 'Resource root: ' . Language_FTS_Playground_Search_Benchmark_Fixture::resource_root() . "\n";
        $reports = array_is_list($report) ? $report : [$report];
    }

    foreach ($reports as $index => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if ($index > 0) {
            echo "\n";
        }
        echo 'Scenario: ' . (string) ($entry['scenario'] ?? '') . "\n";
        echo 'Query: ' . (string) ($entry['query'] ?? '') . "\n";
        echo 'Documents: ' . (int) ($entry['document_count'] ?? 0) . "\n";
        echo 'Languages: ' . (int) ($entry['language_count'] ?? 0) . "\n";
        echo 'Limit: ' . (int) ($entry['limit'] ?? 0) . "\n";
        echo 'Results: ' . (int) ($entry['result_count'] ?? 0) . ' [' . implode(', ', array_map('strval', (array) ($entry['result_ids'] ?? ($entry['result_post_ids'] ?? [])))) . "]\n";
        echo 'Wall time ms: ' . (string) ($entry['wall_time_ms'] ?? 0) . "\n";
        echo 'Peak memory delta bytes: ' . (int) ($entry['peak_memory_delta_bytes'] ?? 0) . "\n";

        echo "Lookup terms by class:\n";
        foreach ((array) ($entry['lookup_terms_by_class'] ?? []) as $class => $summary) {
            $terms = implode(', ', array_map('strval', (array) ($summary['terms'] ?? [])));
            echo '  ' . (string) $class . ': ' . (int) ($summary['count'] ?? 0) . ($terms !== '' ? ' [' . $terms . ']' : '') . "\n";
        }

        echo "Counters:\n";
        foreach ((array) ($entry['counters'] ?? []) as $name => $value) {
            if ($name === 'fetch_calls') {
                continue;
            }
            echo '  ' . (string) $name . ': ' . (is_scalar($value) ? (string) $value : json_encode($value)) . "\n";
        }

        $failed_gates = [];
        foreach ((array) ($entry['gates'] ?? []) as $gate) {
            if (is_array($gate) && ($gate['status'] ?? '') === 'fail' && ($gate['severity'] ?? 'hard') === 'hard') {
                $failed_gates[] = (string) ($gate['id'] ?? '');
            }
        }
        echo 'Hard gates: ' . ($failed_gates === [] ? 'pass' : 'fail [' . implode(', ', $failed_gates) . ']') . "\n";
    }
}

/**
 * @param array{documents:int|null,languages:int|null,limit:int|null,memory_budget_mb:int|null,wall_time_budget_ms:float|null} $options
 * @return array<string,mixed>
 */
function language_fts_search_benchmark_runner_options(array $options): array
{
    $runner_options = [];
    foreach (['documents', 'languages', 'limit', 'memory_budget_mb', 'wall_time_budget_ms'] as $key) {
        if ($options[$key] !== null) {
            $runner_options[$key] = $options[$key];
        }
    }

    return $runner_options;
}

try {
    $options = language_fts_search_benchmark_parse_args($argv);
    if ($options['help']) {
        echo language_fts_search_benchmark_usage();
        exit(0);
    }

    $runner_options = language_fts_search_benchmark_runner_options($options);
    if ($options['suite'] !== null) {
        $report = Language_FTS_Playground_Search_Benchmark_Fixture::run_suite((string) $options['suite'], $runner_options);
    } else {
        $report = $options['scenario'] === 'all'
        ? Language_FTS_Playground_Search_Benchmark_Fixture::run_all($runner_options)
        : Language_FTS_Playground_Search_Benchmark_Fixture::run_probe($options['scenario'], $runner_options);
    }

    if ($options['json']) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        language_fts_search_benchmark_print_human($report);
    }

    if ($options['fail_on_gate'] && Language_FTS_Playground_Search_Benchmark_Fixture::has_hard_gate_failures($report)) {
        exit(2);
    }
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Benchmark failed: ' . $throwable->getMessage() . "\n");
    fwrite(STDERR, language_fts_search_benchmark_usage());
    exit(1);
}
