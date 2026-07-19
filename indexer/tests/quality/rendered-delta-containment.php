<?php
declare(strict_types=1);

test_case('rendered-content delta preserves ordered multiset removal without duplicate static tokens', function (): void {
    $extractor = new WP_FTS_PostContentExtractor();
    $post = (object) [
        'ID' => 7000,
        'post_title' => '',
        'post_content' => 'Alpha Beta Alpha',
        'post_excerpt' => '',
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-07-18 00:00:00',
    ];
    $result = $extractor->extract($post, [
        'render_content_callback' => static fn(): string => 'beta Dynamic alpha Other alpha',
    ]);
    $rendered = array_values(array_filter(
        $result['fields'],
        static fn(array $field): bool => ($field['name'] ?? '') === 'rendered'
    ));

    assert_same(1, count($rendered), 'reordered rendered output should retain one dynamic delta field');
    assert_same('Dynamic Other', $rendered[0]['text'] ?? null, 'multiset fallback should remove each static occurrence once and preserve rendered order');
});

test_case('rendered-content delta enforces 20000-word and near-2MiB limits in isolated 128MiB processes', function (): void {
    $fixture = dirname(__DIR__) . '/fixtures/rendered-delta-containment.php';
    $evidence = [];
    foreach (['accepted_20000', 'rejected_20001', 'rejected_near_2m'] as $case) {
        $result = test_run_subprocess([
            PHP_BINARY,
            '-d',
            'memory_limit=128M',
            $fixture,
            $case,
        ], dirname(__DIR__, 2));
        assert_same(0, $result['exit'], "the {$case} rendered-delta subprocess should complete: {$result['stderr']}");
        $payload = json_decode(trim($result['stdout']), true);
        assert_true(is_array($payload), "the {$case} rendered-delta subprocess should emit JSON evidence");
        $evidence[$case] = $payload;

        assert_true(
            (int) ($payload['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= 16 * 1024 * 1024,
            "the {$case} rendered-delta operation should add at most 16 MiB of PHP allocation"
        );
        assert_true(
            (int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024,
            "the {$case} rendered-delta process should remain below 128 MiB PHP peak"
        );
        foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
            $value = $payload['proc_status'][$metric] ?? null;
            if (is_int($value)) {
                assert_true($value <= 128 * 1024 * 1024, "the {$case} {$metric} should remain below 128 MiB");
            }
        }
    }

    assert_same(null, $evidence['accepted_20000']['error'] ?? null, 'exactly 20,000 aggregate raw+rendered words should remain accepted');
    assert_true(
        (int) ($evidence['accepted_20000']['rendered_field_occurrences'] ?? 0) > 0,
        'the exact accepted boundary should retain bounded rendered-only text'
    );
    assert_same(
        'WP_FTS_Analysis_Limit_Exceeded',
        $evidence['rejected_20001']['error']['class'] ?? null,
        'aggregate word 20,001 should raise the typed analysis limit'
    );
    assert_same('occurrences', $evidence['rejected_20001']['error']['reason_code'] ?? null, 'word 20,001 should expose the occurrence reason');
    assert_same(
        'WP_FTS_Analysis_Limit_Exceeded',
        $evidence['rejected_near_2m']['error']['class'] ?? null,
        'near-2MiB token-dense rendering should raise the typed analysis limit'
    );
    assert_same('occurrences', $evidence['rejected_near_2m']['error']['reason_code'] ?? null, 'near-2MiB rendering should stop on occurrence density');
    assert_true(
        (float) ($evidence['rejected_near_2m']['elapsed_seconds'] ?? INF) <= 1.0,
        'near-2MiB token density should reject within one second before full token materialization'
    );
});
