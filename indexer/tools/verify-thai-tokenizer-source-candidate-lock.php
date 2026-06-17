#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "verify-thai-tokenizer-source-candidate-lock.php must run from the command line.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

exit(wp_fts_verify_thai_tokenizer_source_candidate_lock_main($_SERVER['argv'] ?? []));

/**
 * @param string[] $argv
 */
function wp_fts_verify_thai_tokenizer_source_candidate_lock_main(array $argv): int
{
    try {
        $options = wp_fts_verify_thai_tokenizer_source_candidate_lock_parse_options(array_slice($argv, 1));
    } catch (InvalidArgumentException $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        wp_fts_verify_thai_tokenizer_source_candidate_lock_usage(STDERR);

        return 1;
    }

    if ($options['help']) {
        wp_fts_verify_thai_tokenizer_source_candidate_lock_usage(STDOUT);

        return 0;
    }

    if ($options['path'] === '') {
        wp_fts_verify_thai_tokenizer_source_candidate_lock_usage(STDERR);

        return 1;
    }

    $verifier = new WP_FTS_ThaiTokenizerSourceCandidateLockVerifier();
    $report = $verifier->verify_file(
        $options['path'],
        $options['allow_pending_exact_values'],
        dirname(__DIR__, 2)
    );

    if ($options['json']) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        wp_fts_verify_thai_tokenizer_source_candidate_lock_print_report($report);
    }

    return $report['valid'] ? 0 : 1;
}

/**
 * @param string[] $args
 * @return array{path:string,json:bool,help:bool,allow_pending_exact_values:bool}
 */
function wp_fts_verify_thai_tokenizer_source_candidate_lock_parse_options(array $args): array
{
    $options = [
        'path' => '',
        'json' => false,
        'help' => false,
        'allow_pending_exact_values' => false,
    ];

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--json') {
            $options['json'] = true;
            continue;
        }
        if ($arg === '--allow-pending-exact-values' || $arg === '--allow-pending-artifact-values') {
            $options['allow_pending_exact_values'] = true;
            continue;
        }
        if (str_starts_with($arg, '--')) {
            throw new InvalidArgumentException('Unknown option: ' . $arg);
        }
        if ($options['path'] !== '') {
            throw new InvalidArgumentException('Only one source-candidate lock path may be provided.');
        }
        $options['path'] = $arg;
    }

    return $options;
}

/**
 * @param resource $stream
 */
function wp_fts_verify_thai_tokenizer_source_candidate_lock_usage($stream): void
{
    fwrite(
        $stream,
        "Usage: php verify-thai-tokenizer-source-candidate-lock.php <source-candidate-lock.json> [options]\n" .
        "Options:\n" .
        "  --allow-pending-exact-values  Allow exact artifact identity and license values to remain pending for preflight only.\n" .
        "  --json                        Emit deterministic JSON.\n" .
        "  --help                        Show this help text.\n"
    );
}

/**
 * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
 */
function wp_fts_verify_thai_tokenizer_source_candidate_lock_print_report(array $report): void
{
    echo 'Thai tokenizer source-candidate verification: ' . $report['path'] . "\n";
    echo 'Mode: ' . $report['mode'] . "\n";
    echo 'Status: ' . ($report['valid'] ? 'PASS' : 'FAIL') . "\n";
    echo 'Blocks adapter: ' . ($report['blocks_adapter'] ? 'yes' : 'no') . "\n";

    if ($report['pending_exact_values'] !== []) {
        echo 'Pending exact values: ' . implode(', ', $report['pending_exact_values']) . "\n";
    }

    foreach ($report['warnings'] as $warning) {
        echo 'WARN ' . $warning . "\n";
    }
    foreach ($report['errors'] as $error) {
        echo 'ERROR ' . $error . "\n";
    }
}
