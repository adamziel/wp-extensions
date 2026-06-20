<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/tests/cranfield-relevance-gate.php';

/**
 * Build a native relevance-suite JSON file from local Cranfield-shaped source
 * files. This command never downloads data; the operator supplies the corpus.
 */
final class WP_FTS_Build_Cranfield_Relevance_Suite_CLI
{
    /**
     * @param string[] $argv
     */
    public static function run(array $argv): int
    {
        $dir = null;
        $documents = null;
        $queries = null;
        $qrels = null;
        $out = null;
        $topK = WP_FTS_Cranfield_Relevance_Gate::DEFAULT_TOP_K;

        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                fwrite(STDOUT, self::usage());
                return 0;
            }
            if (str_starts_with($arg, '--cranfield-dir=')) {
                $dir = substr($arg, strlen('--cranfield-dir='));
                continue;
            }
            if (str_starts_with($arg, '--documents=')) {
                $documents = substr($arg, strlen('--documents='));
                continue;
            }
            if (str_starts_with($arg, '--queries=')) {
                $queries = substr($arg, strlen('--queries='));
                continue;
            }
            if (str_starts_with($arg, '--qrels=')) {
                $qrels = substr($arg, strlen('--qrels='));
                continue;
            }
            if (str_starts_with($arg, '--out=')) {
                $out = substr($arg, strlen('--out='));
                continue;
            }
            if (str_starts_with($arg, '--top-k=')) {
                $raw = substr($arg, strlen('--top-k='));
                if (preg_match('/^[1-9][0-9]*$/', $raw) !== 1) {
                    fwrite(STDERR, "--top-k must be a positive integer.\n");
                    return 2;
                }
                $topK = (int) $raw;
                continue;
            }
            fwrite(STDERR, "Unknown argument: {$arg}\n");
            return 2;
        }

        try {
            if ($dir !== null) {
                $suite = WP_FTS_Cranfield_Relevance_Gate::build_suite_from_dir($dir, ['top_k' => $topK]);
            } elseif ($documents !== null && $queries !== null && $qrels !== null) {
                $suite = WP_FTS_Cranfield_Relevance_Gate::build_suite($documents, $queries, $qrels, ['top_k' => $topK]);
            } else {
                fwrite(STDERR, self::usage());
                return 2;
            }

            if ($out !== null && trim($out) !== '') {
                WP_FTS_Cranfield_Relevance_Gate::write_suite($suite, $out);
                fwrite(STDOUT, "Wrote Cranfield relevance suite to {$out}\n");
                return 0;
            }

            $json = json_encode($suite, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            fwrite(STDOUT, (is_string($json) ? $json : '{}') . "\n");

            return 0;
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }
    }

    private static function usage(): string
    {
        return "Usage: php tools/build-cranfield-relevance-suite.php --cranfield-dir=PATH [--out=PATH] [--top-k=10]\n"
            . "   or: php tools/build-cranfield-relevance-suite.php --documents=PATH --queries=PATH --qrels=PATH [--out=PATH] [--top-k=10]\n";
    }
}

exit(WP_FTS_Build_Cranfield_Relevance_Suite_CLI::run($argv));
