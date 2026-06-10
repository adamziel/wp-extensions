#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Verify lexical source-lock evidence before a comprehensive source import.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "verify-lexical-source-lock.php must run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../src/LexicalSourceLockVerifier.php';

exit(language_fts_verify_source_lock_main($_SERVER['argv'] ?? []));

/**
 * @param string[] $argv
 */
function language_fts_verify_source_lock_main(array $argv): int
{
    try {
        $options = language_fts_verify_source_lock_parse_options(array_slice($argv, 1));
    } catch (InvalidArgumentException $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        language_fts_verify_source_lock_usage(STDERR);

        return 1;
    }

    if ($options['help']) {
        language_fts_verify_source_lock_usage(STDOUT);

        return 0;
    }

    if ($options['path'] === '') {
        language_fts_verify_source_lock_usage(STDERR);

        return 1;
    }

    $repo_root = dirname(__DIR__, 2);
    $verifier = new Language_FTS_Playground_Lexical_Source_Lock_Verifier();
    $report = $verifier->verify_file($options['path'], $options['allow_pending_exact_values'], $repo_root);

    if ($options['json']) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        language_fts_verify_source_lock_print_human($report);
    }

    return $report['valid'] ? 0 : 1;
}

/**
 * @param string[] $args
 * @return array{path:string,json:bool,help:bool,allow_pending_exact_values:bool}
 */
function language_fts_verify_source_lock_parse_options(array $args): array
{
    $options = [
        'path' => '',
        'json' => false,
        'help' => false,
        'allow_pending_exact_values' => false,
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
        if ($arg === '--allow-pending-artifact-values' || $arg === '--allow-pending-exact-values') {
            $options['allow_pending_exact_values'] = true;
            continue;
        }
        if (str_starts_with($arg, '--')) {
            throw new InvalidArgumentException('Unknown option: ' . $arg);
        }
        if ($options['path'] !== '') {
            throw new InvalidArgumentException('Only one source-lock path may be provided.');
        }
        $options['path'] = $arg;
    }

    return $options;
}

/**
 * @param resource $stream
 */
function language_fts_verify_source_lock_usage($stream): void
{
    fwrite(
        $stream,
        "Usage: php verify-lexical-source-lock.php <source-lock.json> [options]\n" .
        "Options:\n" .
        "  --allow-pending-artifact-values  Allow exact source artifact values to be marked pending for preflight only.\n" .
        "  --json                           Emit deterministic JSON.\n" .
        "  --help                           Show this help text.\n"
    );
}

/**
 * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_real_import:bool} $report
 */
function language_fts_verify_source_lock_print_human(array $report): void
{
    echo 'Lexical source-lock verification: ' . $report['path'] . "\n";
    echo 'Mode: ' . $report['mode'] . "\n";
    echo 'Status: ' . ($report['valid'] ? 'PASS' : 'FAIL') . "\n";
    echo 'Blocks real import: ' . ($report['blocks_real_import'] ? 'yes' : 'no') . "\n";

    if ($report['pending_exact_values'] !== []) {
        echo 'Pending exact artifact values: ' . implode(', ', $report['pending_exact_values']) . "\n";
    }

    foreach ($report['warnings'] as $warning) {
        echo 'WARN ' . $warning . "\n";
    }
    foreach ($report['errors'] as $error) {
        echo 'ERROR ' . $error . "\n";
    }
}
