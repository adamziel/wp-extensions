<?php
declare(strict_types=1);

test_case('Jieba dictionary giant line rejects in bounded memory in a fresh 128-MiB process', function (): void {
    $result = test_run_subprocess([
        PHP_BINARY,
        '-d',
        'memory_limit=128M',
        dirname(__DIR__) . '/fixtures/jieba-line-containment.php',
    ], dirname(__DIR__, 2));
    assert_same(0, $result['exit'], 'the giant-line Jieba process should finish under 128 MiB: ' . $result['stderr']);

    $payload = json_decode(trim($result['stdout']), true);
    assert_true(is_array($payload), 'the giant-line Jieba process should emit JSON evidence');
    assert_same(16 * 1024 * 1024, $payload['source_bytes'] ?? null, 'the hostile dictionary should occupy the complete accepted 16-MiB source envelope');
    assert_same(8192, $payload['line_limit_bytes'] ?? null, 'the fixture should bind itself to the exact production 8-KiB row limit');
    assert_same('WP_FTS_Analysis_Limit_Exceeded', $payload['error']['class'] ?? null, 'the giant row should raise a typed analysis limit instead of exhausting memory');
    assert_same('jieba_dictionary_line_bytes', $payload['error']['reason_code'] ?? null, 'the giant row should identify the dictionary-line limit');
    assert_same(1, $payload['dictionary_scan_count'] ?? null, 'the giant row should reject during one dictionary scan');
    assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 1.0, 'the 16-MiB giant line should reject within one second');
    assert_true((int) ($payload['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= 8 * 1024 * 1024, 'the giant line should add at most 8 MiB PHP allocation');
    assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, 'the giant-line process should stay below the PHP memory ceiling');
    foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
        $value = $payload['proc_status'][$metric] ?? null;
        if (is_int($value)) {
            assert_true($value <= 128 * 1024 * 1024, "giant-line Jieba {$metric} should stay below 128 MiB");
        }
    }
});

test_case('Jieba 32-prefix fanout retains 160000 compact candidates in a fresh 128-MiB process', function (): void {
    $result = test_run_subprocess([
        PHP_BINARY,
        '-d',
        'memory_limit=128M',
        dirname(__DIR__) . '/fixtures/jieba-cache-containment.php',
    ], dirname(__DIR__, 2));
    assert_same(0, $result['exit'], 'the 32-prefix Jieba fanout should finish under 128 MiB: ' . $result['stderr']);

    $payload = json_decode(trim($result['stdout']), true);
    assert_true(is_array($payload), 'the 32-prefix Jieba fanout should emit JSON evidence');
    assert_same(32, $payload['prefixes'] ?? null, 'the fixture should exercise 32 simultaneous high-fanout prefixes');
    assert_same(5000, $payload['candidates_per_prefix'] ?? null, 'every prefix should exercise the exact per-prefix candidate boundary');
    assert_same(160000, $payload['candidate_rows'] ?? null, 'the fixture should exercise 160,000 simultaneous candidate rows');
    assert_true(($payload['source_bytes'] ?? PHP_INT_MAX) <= 16 * 1024 * 1024, 'the complete hostile fanout should remain a valid-sized custom dictionary');
    assert_same(true, $payload['target_match_preserved'] ?? null, 'streaming should preserve a five-character dictionary match unavailable from fallback n-grams');
    assert_same(1, $payload['dictionary_scan_count'] ?? null, 'the complete hostile fanout should use exactly one source scan');
    assert_same(350000, $payload['retained_candidate_limit'] ?? null, 'the compact cache should cover all 337,461 eligible pinned rows with bounded headroom');
    assert_same(8 * 1024 * 1024, $payload['retained_candidate_byte_limit'] ?? null, 'the compact cache should bind itself to the exact 8-MiB logical word limit');
    assert_same(160000, $payload['cached_candidate_count'] ?? null, 'the hostile fanout should retain every complete compact candidate record');
    assert_same(1280007, $payload['cached_candidate_bytes'] ?? null, 'the hostile fanout should account for every retained candidate-word byte');
    assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 5.0, 'the 160,000-candidate admission and compact load should finish within five seconds');
    assert_true((int) ($payload['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= 24 * 1024 * 1024, 'the 160,000-candidate compact cache should add at most 24 MiB PHP allocation');
    assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, 'the fanout process should stay below the PHP memory ceiling');
    foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
        $value = $payload['proc_status'][$metric] ?? null;
        if (is_int($value)) {
            assert_true($value <= 128 * 1024 * 1024, "Jieba fanout {$metric} should stay below 128 MiB");
        }
    }
});
