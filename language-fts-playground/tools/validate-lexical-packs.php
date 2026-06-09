#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Validate lexical runtime packs and print deterministic statistics.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "validate-lexical-packs.php must run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/LexicalPackValidator.php';

exit(language_fts_validate_packs_main($_SERVER['argv'] ?? []));

/**
 * @param string[] $argv
 */
function language_fts_validate_packs_main(array $argv): int
{
    try {
        $options = language_fts_validate_packs_parse_options(array_slice($argv, 1));
    } catch (InvalidArgumentException $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        language_fts_validate_packs_usage(STDERR);

        return 1;
    }

    if ($options['help']) {
        language_fts_validate_packs_usage(STDOUT);

        return 0;
    }

    try {
        $validator = new Language_FTS_Playground_Lexical_Pack_Validator(
            $options['resource_root'],
            $options['max_synset_size'],
            $options['max_expansions_per_term']
        );
        $report = $validator->validate_all();
    } catch (Throwable $throwable) {
        fwrite(STDERR, $throwable->getMessage() . "\n");

        return 1;
    }

    if ($options['json']) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        language_fts_validate_packs_print_human($report);
    }

    return !empty($report['valid']) ? 0 : 1;
}

/**
 * @param string[] $args
 * @return array{json:bool,help:bool,resource_root:string|null,max_synset_size:int,max_expansions_per_term:int}
 */
function language_fts_validate_packs_parse_options(array $args): array
{
    $options = [
        'json' => false,
        'help' => false,
        'resource_root' => null,
        'max_synset_size' => Language_FTS_Playground_Lexical_Pack_Validator::DEFAULT_MAX_SYNSET_SIZE,
        'max_expansions_per_term' => Language_FTS_Playground_Lexical_Pack_Validator::DEFAULT_MAX_EXPANSIONS_PER_TERM,
    ];

    foreach ($args as $arg) {
        $arg = (string) $arg;
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        if ($arg === '--json') {
            $options['json'] = true;
            continue;
        }

        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            throw new InvalidArgumentException('Options must use --name=value syntax unless they are --json or --help: ' . $arg);
        }

        [$name, $value] = explode('=', substr($arg, 2), 2);
        $name = str_replace('-', '_', $name);
        switch ($name) {
            case 'resource_root':
                $options['resource_root'] = $value;
                break;

            case 'max_synset_size':
                $options['max_synset_size'] = language_fts_validate_packs_positive_int($value, '--max-synset-size');
                break;

            case 'max_expansions_per_term':
                $options['max_expansions_per_term'] = language_fts_validate_packs_positive_int($value, '--max-expansions-per-term');
                break;

            default:
                throw new InvalidArgumentException('Unknown option: --' . str_replace('_', '-', $name));
        }
    }

    return $options;
}

function language_fts_validate_packs_positive_int(string $value, string $option): int
{
    if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
        throw new InvalidArgumentException($option . ' must be a positive integer.');
    }

    return (int) $value;
}

/**
 * @param resource $stream
 */
function language_fts_validate_packs_usage($stream): void
{
    fwrite(
        $stream,
        "Usage: php validate-lexical-packs.php [options]\n" .
        "Options:\n" .
        "  --json                         Emit deterministic JSON.\n" .
        "  --max-synset-size=<n>           Fail when any synset contains more than n terms. Default: 64\n" .
        "  --max-expansions-per-term=<n>   Fail when any term expands to more than n unique targets. Default: 128\n" .
        "  --resource-root=<path>          Validate a non-default language resource root.\n"
    );
}

/**
 * @param array<string,mixed> $report
 */
function language_fts_validate_packs_print_human(array $report): void
{
    echo 'Lexical pack validation: ' . (string) ($report['resource_root'] ?? '') . "\n";
    $thresholds = is_array($report['thresholds'] ?? null) ? $report['thresholds'] : [];
    echo 'Thresholds: max synset size ' . (int) ($thresholds['max_synset_size'] ?? 0)
        . ', max expansions per term ' . (int) ($thresholds['max_expansions_per_term'] ?? 0) . "\n\n";

    foreach ((array) ($report['warnings'] ?? []) as $warning) {
        echo 'WARN ' . (string) $warning . "\n";
    }

    foreach ((array) ($report['languages'] ?? []) as $language) {
        if (!is_array($language)) {
            continue;
        }

        $metadata = is_array($language['metadata'] ?? null) ? $language['metadata'] : [];
        $counts = is_array($language['counts'] ?? null) ? $language['counts'] : [];
        $warnings = array_values(array_map('strval', (array) ($language['warnings'] ?? [])));
        $prefix = empty($language['valid']) ? 'FAIL' : 'OK';
        $version = trim((string) ($metadata['pack_version'] ?? '') . ' ' . (string) ($metadata['pack_date'] ?? ''));
        $expansions = (int) ($counts['pairwise_synonym_expansions'] ?? 0)
            + (int) ($counts['concept_expansions'] ?? 0)
            + (int) ($counts['phrase_synonym_expansions'] ?? 0);

        echo $prefix . ' ' . (string) ($language['label'] ?? '') . ' (' . (string) ($language['language_id'] ?? '') . ")\n";
        echo '  kind: ' . (string) ($metadata['data_kind'] ?? '') . "\n";
        echo '  source: ' . (string) ($metadata['source_name'] ?? '') . "\n";
        echo '  license: ' . (string) ($metadata['license_name'] ?? '') . "\n";
        echo '  version/date: ' . $version . "\n";
        echo '  counts: stopwords ' . (int) ($counts['stopwords'] ?? 0)
            . ', lexemes ' . (int) ($counts['lexeme_rows'] ?? 0)
            . ', pairwise rows ' . (int) ($counts['pairwise_synonym_rows'] ?? 0)
            . ', pairwise expansions ' . (int) ($counts['pairwise_synonym_expansions'] ?? 0)
            . ', synsets ' . (int) ($counts['synset_rows'] ?? 0)
            . ', concept expansions ' . (int) ($counts['concept_expansions'] ?? 0)
            . ', phrase rows ' . (int) ($counts['phrase_synonym_rows'] ?? 0)
            . ', phrase expansions ' . (int) ($counts['phrase_synonym_expansions'] ?? 0)
            . ', term rules ' . (int) ($counts['term_rule_rows'] ?? 0)
            . ', protected terms ' . (int) ($counts['protected_term_rows'] ?? 0)
            . ', total expansions ' . $expansions . "\n";
        echo '  max synset size: ' . (int) ($language['max_synset_size'] ?? 0)
            . ', max expansion fanout: ' . (int) ($language['max_expansion_fanout'] ?? 0) . "\n";

        if ($warnings === []) {
            echo "  warnings: none\n";
        } else {
            foreach ($warnings as $warning) {
                echo '  warning: ' . $warning . "\n";
            }
        }
        echo "\n";
    }

    echo !empty($report['valid'])
        ? "All lexical packs are valid.\n"
        : "One or more lexical packs are invalid.\n";
}
