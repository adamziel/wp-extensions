<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/**
 * @return array{scenario:string,documents:int,limit:int,json:bool,help:bool}
 */
function language_fts_search_benchmark_parse_args(array $argv): array
{
    $options = [
        'scenario' => 'common-term',
        'documents' => 48,
        'limit' => 5,
        'json' => false,
        'help' => false,
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

        if (preg_match('/^--([^=]+)=(.*)$/', $arg, $matches) !== 1) {
            throw new InvalidArgumentException('Unknown option syntax: ' . $arg);
        }

        $name = str_replace('-', '_', (string) $matches[1]);
        $value = (string) $matches[2];
        if ($name === 'scenario') {
            $options['scenario'] = $value;
        } elseif ($name === 'documents') {
            $options['documents'] = max(1, (int) $value);
        } elseif ($name === 'limit') {
            $options['limit'] = max(1, (int) $value);
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
        '  --scenario=<name>   common-term, phrase, fuzzy, synonym, phrase-synonym, or all',
        '  --documents=<n>     Synthetic document count (default: 48)',
        '  --limit=<n>         Public result limit (default: 5)',
        '  --json              Emit JSON instead of human-readable text',
        '  --help              Show this help',
        '',
        'php -n compatible examples:',
        '  php -n language-fts-playground/tools/search-benchmark-counters.php --scenario=common-term --documents=64 --limit=5 --json',
        '  php -n language-fts-playground/tools/search-benchmark-counters.php --scenario=phrase --documents=64 --limit=5',
        '  php -n language-fts-playground/tools/search-benchmark-counters.php --scenario=fuzzy --documents=64 --limit=5 --json',
    ]) . "\n";
}

/**
 * @param array<string,mixed>|array<int,array<string,mixed>> $report
 */
function language_fts_search_benchmark_print_human(array $report): void
{
    $reports = array_is_list($report) ? $report : [$report];
    echo "Language FTS search benchmark counters\n";
    echo 'Resource root: ' . Language_FTS_Playground_Search_Benchmark_Fixture::resource_root() . "\n";

    foreach ($reports as $index => $entry) {
        if ($index > 0) {
            echo "\n";
        }
        echo 'Scenario: ' . (string) ($entry['scenario'] ?? '') . "\n";
        echo 'Query: ' . (string) ($entry['query'] ?? '') . "\n";
        echo 'Documents: ' . (int) ($entry['document_count'] ?? 0) . "\n";
        echo 'Limit: ' . (int) ($entry['limit'] ?? 0) . "\n";
        echo 'Results: ' . (int) ($entry['result_count'] ?? 0) . ' [' . implode(', ', array_map('strval', (array) ($entry['result_post_ids'] ?? []))) . "]\n";

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
    }
}

try {
    $options = language_fts_search_benchmark_parse_args($argv);
    if ($options['help']) {
        echo language_fts_search_benchmark_usage();
        exit(0);
    }

    $runner_options = [
        'documents' => $options['documents'],
        'limit' => $options['limit'],
    ];
    $report = $options['scenario'] === 'all'
        ? Language_FTS_Playground_Search_Benchmark_Fixture::run_all($runner_options)
        : Language_FTS_Playground_Search_Benchmark_Fixture::run_probe($options['scenario'], $runner_options);

    if ($options['json']) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        language_fts_search_benchmark_print_human($report);
    }
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Benchmark failed: ' . $throwable->getMessage() . "\n");
    fwrite(STDERR, language_fts_search_benchmark_usage());
    exit(1);
}
