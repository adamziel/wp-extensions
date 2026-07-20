<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools/validate-analyzer-source-lock.php';

$GLOBALS['wp_fts_analyzer_source_lock_checks'] = $GLOBALS['wp_fts_analyzer_source_lock_checks'] ?? 0;

function wp_fts_analyzer_source_lock_quality_record(string $label, int $count = 1): void
{
    if ($count < 1) {
        throw new WP_FTS_TestFailure('Analyzer source-lock check count must be at least 1.');
    }

    $GLOBALS['wp_fts_analyzer_source_lock_checks'] += $count;
    record_check($label, $count);
}

function wp_fts_analyzer_source_lock_quality_assert_true(bool $condition, string $message): void
{
    wp_fts_analyzer_source_lock_quality_record($message);
    if (!$condition) {
        throw new WP_FTS_TestFailure($message);
    }
}

function wp_fts_analyzer_source_lock_quality_assert_same(mixed $expected, mixed $actual, string $message): void
{
    wp_fts_analyzer_source_lock_quality_record($message);
    if ($expected !== $actual) {
        throw new WP_FTS_TestFailure($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function wp_fts_analyzer_source_lock_fixture_path(string $file): string
{
    return __DIR__ . '/../fixtures/analyzer-source-locks/' . $file;
}

test_case('analyzer source-lock fixture manifest validates schema, paths, and digests', function (): void {
    $manifestPath = wp_fts_analyzer_source_lock_fixture_path('noop-en.source-lock.json');
    $result = wp_fts_analyzer_source_lock_validate_file($manifestPath);

    wp_fts_analyzer_source_lock_quality_assert_same([], $result['errors'], 'valid source-lock fixture should not report errors');
    wp_fts_analyzer_source_lock_quality_assert_true($result['ok'], 'valid source-lock fixture should pass');

    $manifest = $result['manifest'] ?? [];
    wp_fts_analyzer_source_lock_quality_assert_same('fixture-noop-en', $manifest['pack']['id'] ?? null, 'fixture pack id should remain stable');
    wp_fts_analyzer_source_lock_quality_assert_same(false, $manifest['runtime']['contains_third_party_data'] ?? null, 'fixture must not contain third-party lexical data');
});

test_case('analyzer source-lock verifier rejects unsafe no-op metadata', function (): void {
    $manifestPath = wp_fts_analyzer_source_lock_fixture_path('noop-en.source-lock.json');
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest)) {
        throw new WP_FTS_TestFailure('Fixture manifest should decode as an object.');
    }

    $manifest['runtime']['contains_third_party_data'] = true;
    $manifest['pack']['status'] = 'fixture';
    $tempPath = tempnam(sys_get_temp_dir(), 'wp-fts-source-lock-');
    if ($tempPath === false) {
        mark_pending('Could not create temporary manifest for analyzer source-lock negative test.');
    }

    $tempManifest = $tempPath . '.json';
    $written = file_put_contents(
        $tempManifest,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
    if ($written === false) {
        mark_pending('Could not write temporary manifest for analyzer source-lock negative test.');
    }

    try {
        $result = wp_fts_analyzer_source_lock_validate_file($tempManifest);
    } finally {
        @unlink($tempPath);
        @unlink($tempManifest);
    }

    wp_fts_analyzer_source_lock_quality_assert_true(!$result['ok'], 'unsafe no-op metadata should fail validation');
    $errors = implode("\n", $result['errors']);
    wp_fts_analyzer_source_lock_quality_assert_true(str_contains($errors, 'runtime.contains_third_party_data as false'), 'unsafe no-op metadata should reject third-party data');
});
