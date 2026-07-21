<?php
declare(strict_types=1);

test_case('CJK lexical run containment rejects the accepted-source adversary before Jieba work', function (): void {
    $fixture = dirname(__DIR__) . '/fixtures/cjk-lexical-run-containment.php';
    foreach ([
        'normal PHP' => [PHP_BINARY, '-d', 'memory_limit=128M', $fixture],
        'PHP without extensions' => [PHP_BINARY, '-n', '-d', 'memory_limit=128M', $fixture],
    ] as $label => $command) {
        $result = test_run_subprocess($command, dirname(__DIR__, 2));
        assert_same(0, $result['exit'], "{$label} CJK containment should finish under 128 MiB: {$result['stderr']}");
        $payload = json_decode(trim($result['stdout']), true);
        assert_true(is_array($payload), "{$label} CJK containment should emit JSON evidence");
        assert_same(2097152, $payload['source_limit_bytes'] ?? null, "{$label} fixture should bind itself to the complete accepted source envelope");
        assert_same(2097150, $payload['run_bytes'] ?? null, "{$label} fixture should use the largest repeated valid CJK run within 2 MiB");
        assert_same(4096, $payload['lexical_run_limit_bytes'] ?? null, "{$label} fixture should bind itself to the shared lexical-run envelope");
        assert_same('WP_FTS_Analysis_Limit_Exceeded', $payload['error']['class'] ?? null, "{$label} adversary should raise the typed analysis limit");
        assert_same('lexical_run_bytes', $payload['error']['reason_code'] ?? null, "{$label} adversary should identify the lexical-run boundary");
        assert_same(0, $payload['dictionary_scan_count'] ?? null, "{$label} adversary must fail before one Jieba dictionary scan");
        assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 1.0, "{$label} adversary should reject within one second");
        assert_true((int) ($payload['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= 16 * 1024 * 1024, "{$label} adversary should add at most 16 MiB PHP allocation");
        assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, "{$label} adversary should stay below the 128-MiB PHP ceiling");
        foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
            $value = $payload['proc_status'][$metric] ?? null;
            if (is_int($value)) {
                assert_true($value <= 128 * 1024 * 1024, "{$label} {$metric} should stay below 128 MiB");
            }
        }
    }
});
