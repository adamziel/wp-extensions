<?php
declare(strict_types=1);

/**
 * SAVEQUERIES finalization containment contract.
 *
 * Direct execution re-enters the shared harness with a focused filter. Normal
 * tests/run.php discovery registers this test alongside the rest of the suite.
 */
function wp_fts_debug_sql_finalization_contract_direct(): int
{
    if (!function_exists('proc_open')) {
        fwrite(STDOUT, "SKIP: proc_open() is unavailable, so the focused SQL finalization contract cannot launch tests/run.php.\n");
        return 0;
    }

    $root = dirname(__DIR__, 2);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $process = proc_open(
        [PHP_BINARY, $root . '/tests/run.php'],
        $descriptors,
        $pipes,
        $root,
        array_merge($environment, [
            'WP_FTS_TEST_FILTER' => '100,000 SAVEQUERIES entries',
            'WP_FTS_MIN_CHECKS' => '0',
        ])
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "FAIL: Could not launch the focused SQL finalization contract.\n");
        return 1;
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($stdout !== '') {
        fwrite(STDOUT, $stdout);
    }
    if ($stderr !== '') {
        fwrite(STDERR, $stderr);
    }

    return is_int($exit) ? $exit : 1;
}

if (!function_exists('test_case')) {
    exit(wp_fts_debug_sql_finalization_contract_direct());
}

test_case('SQL debug finalization bounds 100,000 SAVEQUERIES entries to its fixed inspection cap', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    $plugin = new ReflectionClass(WP_FTS_Plugin::class);
    $maxQueries = $plugin->getReflectionConstant('DEBUG_MAX_SQL_QUERIES');
    assert_true($maxQueries !== false, 'the SQL diagnostics inspection cap should remain explicit');
    $inspectionCap = (int) $maxQueries->getValue();

    $captureMethod = new ReflectionMethod(WP_FTS_Plugin::class, 'debug_sql_query_capture_start');
    $captureMethod->setAccessible(true);
    $finishMethod = new ReflectionMethod(WP_FTS_Plugin::class, 'debug_sql_query_finish_summary');
    $finishMethod->setAccessible(true);
    $probe = new class {
        public int $queryInspections = 0;
        public int $timingInspections = 0;

        /** Count every attempt to inspect an entry after the capture ceiling. */
        public function __isset(string $name): bool
        {
            if ($name === 'query') {
                $this->queryInspections++;
            } elseif ($name === 'elapsed') {
                $this->timingInspections++;
            }

            return $name === 'query' || $name === 'elapsed';
        }

        /** Supply valid-looking fields so only the inspection ceiling can stop reads. */
        public function __get(string $name): mixed
        {
            return match ($name) {
                'query' => 'SELECT 1 FROM wp_fts_documents',
                'elapsed' => 0.001,
                default => null,
            };
        }
    };

    try {
        $fake->queries = [];
        $capture = $captureMethod->invoke(null);
        // Repeating one observable entry keeps fixture construction cheap while
        // making every attempted query or timing inspection visible.
        $fake->queries = array_fill(0, 100_000, $probe);
        $summary = $finishMethod->invoke(null, $capture);

        assert_same(100_000, $summary['captured_count'] ?? null, 'finalization should retain the exact constant-time SAVEQUERIES delta count');
        assert_same($inspectionCap, $summary['shown_count'] ?? null, 'finalization should render only the fixed diagnostic window');
        assert_same(true, $summary['more'] ?? null, 'the summary should report the undisplayed SAVEQUERIES tail');
        assert_same('partial', $summary['timing_relation'] ?? null, 'truncation must label the bounded timing subtotal as partial');
        assert_float_near((float) $inspectionCap, (float) ($summary['total_time_ms'] ?? -1), 'only timing inside the bounded diagnostic window should be accumulated');
        assert_same($inspectionCap, $probe->queryInspections, 'finalization must not inspect query text after the fixed diagnostic window');
        assert_same($inspectionCap, $probe->timingInspections, 'finalization must not inspect timing data after the fixed diagnostic window');
    } finally {
        $wpdb = $oldWpdb;
        wp_fts_test_reset_wordpress_fakes();
    }
});
