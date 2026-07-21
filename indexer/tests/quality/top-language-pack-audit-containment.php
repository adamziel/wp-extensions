<?php
declare(strict_types=1);

test_case('top-language audit bounds recursive discovery and rejects symlink escapes', function (): void {
    $payload = wp_fts_tlpac_case(['traversal']);
    assert_same(256, $payload['manifest_limit'] ?? null, 'audit discovery should bind manifests to 256');
    assert_same(4096, $payload['entry_limit'] ?? null, 'audit discovery should bind traversed entries to 4,096');
    assert_same(262144, $payload['path_byte_limit'] ?? null, 'audit discovery should bind aggregate paths to 256 KiB');
    assert_same(8, $payload['depth_limit'] ?? null, 'audit discovery should bind recursion to eight directories');
    $results = $payload['results'] ?? [];
    assert_same(256, $results['manifests-exact']['manifests'] ?? null, 'audit should accept exactly 256 manifests');
    assert_same(0, $results['entries-exact']['manifests'] ?? null, 'audit should traverse exactly 4,096 bounded non-manifest entries');
    assert_same(1, $results['depth-exact']['manifests'] ?? null, 'audit should discover a manifest at exactly depth eight');
    foreach (['manifests-over', 'entries-over', 'paths-over', 'depth-over'] as $boundary) {
        assert_same('RuntimeException', $results[$boundary]['error_class'] ?? null, "audit {$boundary} should reject at the first over-limit entry");
    }
    assert_same(0, $results['symlink-file']['manifests'] ?? null, 'audit should not discover a symlinked manifest outside its root');
    assert_same(0, $results['symlink-prefix']['manifests'] ?? null, 'audit should not traverse a root/root2 lexical-prefix symlink escape');
    wp_fts_tlpac_assert_process($payload, 'audit traversal', 10.0);
});

test_case('top-language audit rejects a sparse 140 MiB manifest under normal and php -n runtimes', function (): void {
    foreach ([[], ['-n']] as $runtimeArgs) {
        $payload = wp_fts_tlpac_case([...$runtimeArgs, 'oversized']);
        $label = $runtimeArgs === [] ? 'normal PHP' : 'php -n';
        assert_same(140 * 1024 * 1024, $payload['manifest_bytes'] ?? null, "{$label} should exercise a sparse 140-MiB manifest");
        assert_same(65536, $payload['manifest_limit'] ?? null, "{$label} should bind the manifest read to 64 KiB");
        assert_same(0, $payload['exit'] ?? null, "{$label} audit should return normally instead of fatally allocating the manifest");
        assert_same('invalid_pack', $payload['spanish']['status'] ?? null, "{$label} audit should retain invalid-pack evidence");
        assert_true(str_contains((string) ($payload['spanish']['error'] ?? ''), '64 KiB'), "{$label} audit should explain the bounded rejection");
        wp_fts_tlpac_assert_process($payload, $label . ' oversized manifest', 10.0);
    }
});

test_case('top-language audit inspects the bundled root once per candidate under normal and php -n runtimes', function (): void {
    foreach ([[], ['-n']] as $runtimeArgs) {
        $payload = wp_fts_tlpac_case([...$runtimeArgs, 'bundled']);
        $label = $runtimeArgs === [] ? 'normal PHP' : 'php -n';
        assert_same(0, $payload['exit'] ?? null, "{$label} bundled audit should exit normally");
        assert_true((int) ($payload['manifests'] ?? 0) >= 16, "{$label} bundled audit should traverse every shipped analyzer manifest");
        assert_true((int) ($payload['rows'] ?? 0) >= 20, "{$label} bundled audit should report every registry language");
        if ($runtimeArgs === []) {
            assert_true((int) ($payload['statuses']['pack_backed'] ?? 0) >= 16, 'normal PHP bundled audit should certify shipped indexed packs');
        }
        wp_fts_tlpac_assert_process($payload, $label . ' bundled audit', 5.0);
    }
});

/**
 * @param string[] $arguments Arguments before the fixture case; `-n` disables php.ini.
 * @return array<string,mixed>
 */
function wp_fts_tlpac_case(array $arguments): array
{
    $case = array_pop($arguments);
    $command = [PHP_BINARY, ...$arguments, '-d', 'memory_limit=128M', dirname(__DIR__) . '/fixtures/top-language-pack-audit-containment.php', $case];
    $result = test_run_subprocess($command, dirname(__DIR__, 2));
    assert_same(0, $result['exit'], "{$case} audit containment child should finish under 128 MiB: {$result['stderr']}");
    $payload = json_decode(trim($result['stdout']), true);
    assert_true(is_array($payload), "{$case} audit containment child should emit JSON evidence");

    return $payload;
}

/** @param array<string,mixed> $payload */
function wp_fts_tlpac_assert_process(array $payload, string $case, float $seconds): void
{
    assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, "{$case} should remain below the 128-MiB PHP ceiling");
    assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= $seconds, "{$case} should finish within {$seconds} seconds");
}
