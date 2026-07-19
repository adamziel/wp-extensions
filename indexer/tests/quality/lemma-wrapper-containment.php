<?php
declare(strict_types=1);

test_case('CoNLL-U and UniMorph discovery enforce exact recursive source envelopes', function (): void {
    $payload = wp_fts_lwc_case('discovery');
    assert_same(256, $payload['file_limit'] ?? null, 'wrapper discovery should bind accepted files to 256');
    assert_same(8192, $payload['path_byte_limit'] ?? null, 'wrapper discovery should bind accepted relative paths to 8 KiB');
    assert_same(8, $payload['depth_limit'] ?? null, 'wrapper discovery should bind recursive depth to eight directories');
    foreach (['conllu', 'unimorph'] as $kind) {
        $cases = $payload['results'][$kind] ?? [];
        foreach (['files-exact', 'paths-exact', 'depth-exact'] as $boundary) {
            assert_same(null, $cases[$boundary]['error_class'] ?? null, "{$kind} {$boundary} should remain accepted");
            assert_same(true, $cases[$boundary]['manifest_written'] ?? null, "{$kind} {$boundary} should produce an activatable pack");
            assert_true(
                (int) ($cases[$boundary]['manifest_bytes'] ?? PHP_INT_MAX) <= WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES,
                "{$kind} {$boundary} manifest should fit the runtime's 64-KiB read envelope"
            );
            assert_same(true, $cases[$boundary]['tmp_sentinel_retained'] ?? null, "{$kind} {$boundary} should retain the caller-owned temporary parent");
            assert_same([], $cases[$boundary]['tmp_entries'] ?? null, "{$kind} {$boundary} should remove wrapper staging files");
        }
        assert_same(256, $cases['files-exact']['source_files'] ?? null, "{$kind} should accept exactly 256 source files");
        assert_same(8192, $cases['paths-exact']['source_path_bytes'] ?? null, "{$kind} should accept exactly 8 KiB of relative paths");
        assert_same(8, $cases['depth-exact']['source_max_depth'] ?? null, "{$kind} should accept a source at exactly depth eight");
        foreach (['files-over', 'paths-over', 'depth-over', 'symlink'] as $boundary) {
            assert_same('RuntimeException', $cases[$boundary]['error_class'] ?? null, "{$kind} {$boundary} should reject during discovery");
            assert_same(false, $cases[$boundary]['manifest_written'] ?? null, "{$kind} {$boundary} should reject before pack generation");
            assert_same(true, $cases[$boundary]['tmp_sentinel_retained'] ?? null, "{$kind} {$boundary} should retain the caller-owned temporary parent");
            assert_same([], $cases[$boundary]['tmp_entries'] ?? null, "{$kind} {$boundary} should leave no staging files");
        }
    }
    wp_fts_lwc_assert_process($payload, 'wrapper discovery', 10.0);
});

test_case('CoNLL-U and UniMorph reject an over-width namespaced token on their first pass', function (): void {
    $payload = wp_fts_lwc_case('invalid');
    foreach (['conllu', 'unimorph'] as $kind) {
        $result = $payload['results'][$kind] ?? [];
        assert_same(null, $result['error_class'] ?? null, "{$kind} should skip the invalid source token without staging it");
        assert_same(1, $result['invalid_runtime_token_rows'] ?? null, "{$kind} should classify the first over-width token immediately");
        assert_same(1, $result['accepted_rows'] ?? null, "{$kind} should stage only the following valid row");
        assert_same(1, $result['runtime_rows'] ?? null, "{$kind} should publish only the valid row");
        assert_same(true, $result['tmp_sentinel_retained'] ?? null, "{$kind} should retain the caller-owned temporary parent");
        assert_same([], $result['tmp_entries'] ?? null, "{$kind} should remove first-pass staging files");
    }
    wp_fts_lwc_assert_process($payload, 'wrapper invalid-token pass', 10.0);
});

test_case('CoNLL-U provenance attests original artifacts instead of the staging TSV', function (): void {
    foreach ([false, true] as $noIni) {
        $label = $noIni ? 'CoNLL-U provenance php-n' : 'CoNLL-U provenance normal';
        $process = wp_fts_lwc_process('conllu-provenance', $noIni);
        assert_same('', trim($process['stderr']), "{$label} should emit no warning");
        $payload = $process['payload'];
        assert_same(null, $payload['result']['error_class'] ?? null, "{$label} import should succeed");
        assert_same(true, $payload['result']['manifest_written'] ?? null, "{$label} should publish a manifest");
        $source = $payload['manifest_source'] ?? [];
        assert_same($payload['expected_sha256'] ?? null, $source['artifact_sha256'] ?? null, "{$label} manifest should attest the original digest");
        assert_same($payload['expected_bytes'] ?? null, $source['byte_count'] ?? null, "{$label} manifest should attest the original bytes");
        assert_same('original-source.conllu', $source['file'] ?? null, "{$label} manifest should name the original source");
        assert_same('original-source.conllu', $source['files'][0]['path'] ?? null, "{$label} manifest should retain per-file original evidence");
        assert_same('conllu-ten-column-v1', $source['column_model']['format'] ?? null, "{$label} manifest should declare CoNLL-U columns");
        assert_same('indexer/tools/import-conllu-lemma-pack.php', $payload['manifest_importer'] ?? null, "{$label} manifest should name the source-aware importer");
        $lock = $payload['source_lock_source'] ?? [];
        assert_same($payload['expected_sha256'] ?? null, $lock['artifact_sha256'] ?? null, "{$label} source lock should attest the original digest");
        assert_same($payload['expected_bytes'] ?? null, $lock['byte_count'] ?? null, "{$label} source lock should attest the original bytes");
        assert_same('original-source.conllu', $lock['file'] ?? null, "{$label} source lock should name the original source");
        assert_same('conllu-ten-column-v1', $payload['source_lock_columns']['format'] ?? null, "{$label} source lock should declare CoNLL-U columns");
        assert_contains('Source file path: original-source.conllu', (string) ($payload['notice'] ?? ''), "{$label} NOTICE should name the original source");
        assert_contains('Source artifact SHA-256: ' . ($payload['expected_sha256'] ?? ''), (string) ($payload['notice'] ?? ''), "{$label} NOTICE should attest the original digest");
        assert_true(!str_contains((string) json_encode($source), 'normalized-lemma.tsv'), "{$label} provenance should not misrepresent the staging file as upstream");
        wp_fts_lwc_assert_process($payload, $label, 10.0);
    }
});

test_case('CoNLL-U and UniMorph staging accept exactly 64 MiB and reject the next byte', function (): void {
    foreach (['bytes-conllu', 'bytes-unimorph'] as $case) {
        $payload = wp_fts_lwc_case($case);
        assert_same(67108864, $payload['byte_limit'] ?? null, "{$case} should bind decoded staging to 64 MiB");
        $exact = $payload['results']['exact'] ?? [];
        assert_same(null, $exact['error_class'] ?? null, "{$case} exact byte boundary should remain accepted");
        assert_same(67108864, $exact['staged_tsv_bytes'] ?? null, "{$case} should stage exactly 64 MiB");
        assert_same(1024, $exact['runtime_rows'] ?? null, "{$case} should retain every exact-boundary row");
        assert_same(true, $exact['tmp_sentinel_retained'] ?? null, "{$case} exact boundary should retain the caller-owned temporary parent");
        assert_same([], $exact['tmp_entries'] ?? null, "{$case} exact boundary should clean staging");
        $over = $payload['results']['over'] ?? [];
        assert_same('RuntimeException', $over['error_class'] ?? null, "{$case} should reject the first byte above 64 MiB");
        assert_same(false, $over['manifest_written'] ?? null, "{$case} byte overflow should reject before pack generation");
        assert_same(true, $over['tmp_sentinel_retained'] ?? null, "{$case} byte overflow should retain the caller-owned temporary parent");
        assert_same([], $over['tmp_entries'] ?? null, "{$case} byte overflow should clean staging");
        wp_fts_lwc_assert_process($payload, $case, 10.0);
    }
});

test_case('CoNLL-U and UniMorph staging accept 1.25 million rows and reject the next row', function (): void {
    foreach (['rows-conllu', 'rows-unimorph'] as $case) {
        foreach ([false, true] as $noIni) {
            $label = $case . ($noIni ? ' php-n' : ' normal');
            $process = wp_fts_lwc_process($case, $noIni);
            assert_same('', trim($process['stderr']), "{$label} should emit no warning");
            $payload = $process['payload'];
            assert_same(1250000, $payload['row_limit'] ?? null, "{$label} should bind staging to 1.25 million rows");
            $exact = $payload['results']['exact'] ?? [];
            assert_same(null, $exact['error_class'] ?? null, "{$label} exact row boundary should remain accepted");
            assert_same(1250000, $exact['accepted_rows'] ?? null, "{$label} should stage exactly 1.25 million rows");
            assert_same(1, $exact['runtime_rows'] ?? null, "{$label} should deterministically deduplicate the exact-boundary source");
            assert_same(true, $exact['tmp_sentinel_retained'] ?? null, "{$label} exact row boundary should retain the caller-owned temporary parent");
            assert_same([], $exact['tmp_entries'] ?? null, "{$label} exact row boundary should clean staging");
            $over = $payload['results']['over'] ?? [];
            assert_same('RuntimeException', $over['error_class'] ?? null, "{$label} should reject the first row above 1.25 million");
            assert_same(false, $over['manifest_written'] ?? null, "{$label} row overflow should reject before pack generation");
            assert_same(true, $over['tmp_sentinel_retained'] ?? null, "{$label} row overflow should retain the caller-owned temporary parent");
            assert_same([], $over['tmp_entries'] ?? null, "{$label} row overflow should clean staging");
            wp_fts_lwc_assert_process($payload, $label, 90.0);
        }
    }
});

/** @return array<string,mixed> */
function wp_fts_lwc_case(string $case): array
{
    return wp_fts_lwc_process($case, false)['payload'];
}

/** @return array{payload:array<string,mixed>,stderr:string} */
function wp_fts_lwc_process(string $case, bool $noIni): array
{
    $command = [PHP_BINARY];
    if ($noIni) {
        $command[] = '-n';
    }
    array_push(
        $command,
        '-d',
        'memory_limit=128M',
        dirname(__DIR__) . '/fixtures/lemma-wrapper-containment.php',
        $case
    );
    $result = test_run_subprocess($command, dirname(__DIR__, 2));
    assert_same(0, $result['exit'], "{$case} wrapper containment child should finish under 128 MiB: {$result['stderr']}");
    $payload = json_decode(trim($result['stdout']), true);
    assert_true(is_array($payload), "{$case} wrapper containment child should emit JSON evidence");

    return ['payload' => $payload, 'stderr' => $result['stderr']];
}

/** @param array<string,mixed> $payload */
function wp_fts_lwc_assert_process(array $payload, string $case, float $seconds): void
{
    assert_true((int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, "{$case} PHP peak should stay below 128 MiB");
    assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= $seconds, "{$case} should finish within {$seconds} seconds");
    foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
        $value = $payload['proc_status'][$metric] ?? null;
        if (is_int($value)) {
            assert_true($value <= 128 * 1024 * 1024, "{$case} {$metric} should stay below 128 MiB");
        }
    }
}
