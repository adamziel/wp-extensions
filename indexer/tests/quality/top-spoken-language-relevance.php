<?php
declare(strict_types=1);

$wp_fts_tslr_direct = !function_exists('test_case');
if ($wp_fts_tslr_direct) {
    require_once dirname(__DIR__) . '/bootstrap.php';

    final class WP_FTS_TSLR_TestFailure extends RuntimeException
    {
    }

    $GLOBALS['wp_fts_tslr_tests'] = [];
    $GLOBALS['wp_fts_tslr_check_count'] = 0;

    function test_case(string $name, callable $fn): void
    {
        $GLOBALS['wp_fts_tslr_tests'][] = ['name' => $name, 'fn' => $fn];
    }

    function record_check(?string $label = null, int $count = 1): void
    {
        if ($count < 1) {
            throw new WP_FTS_TSLR_TestFailure('record_check() count must be at least 1.');
        }

        $GLOBALS['wp_fts_tslr_check_count'] += $count;
    }

    function assert_true(bool $condition, string $message): void
    {
        record_check($message);
        if (!$condition) {
            throw new WP_FTS_TSLR_TestFailure($message);
        }
    }

    function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        record_check($message);
        if ($expected !== $actual) {
            throw new WP_FTS_TSLR_TestFailure($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
        }
    }
}

test_case('quality top spoken language relevance keeps Urdu and Persian routing separate', function (): void {
    $detector = new WP_FTS_LanguageDetector();

    assert_same('ur', $detector->detect_text('یہ اردو تلاش اور فہرست ہے'), 'clear Urdu text should still route to Urdu');
    assert_same('fa', $detector->detect_text('فارسی جستجو'), 'clear Persian text should route to Persian, not Urdu');
});

if ($wp_fts_tslr_direct) {
    $failures = 0;
    $start = microtime(true);
    foreach ($GLOBALS['wp_fts_tslr_tests'] as $test) {
        try {
            ($test['fn'])();
            fwrite(STDOUT, "[PASS] {$test['name']}\n");
        } catch (Throwable $e) {
            $failures++;
            fwrite(STDERR, "[FAIL] {$test['name']}\n{$e->getMessage()}\n{$e->getTraceAsString()}\n");
        }
    }

    $duration = number_format(microtime(true) - $start, 3);
    $count = count($GLOBALS['wp_fts_tslr_tests']);
    $passed = $count - $failures;
    $checks = (int) $GLOBALS['wp_fts_tslr_check_count'];
    $summary = "{$passed}/{$count} top spoken language relevance tests passed; failures={$failures}; checks/scenarios={$checks}; duration={$duration}s\n";
    if ($failures > 0) {
        fwrite(STDERR, $summary);
        exit(1);
    }

    fwrite(STDOUT, $summary);
}
