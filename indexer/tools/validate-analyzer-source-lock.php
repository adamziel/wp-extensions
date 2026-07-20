<?php
declare(strict_types=1);

const WP_FTS_ANALYZER_SOURCE_LOCK_SCHEMA_VERSION = 'wp-fts-analyzer-source-lock/v1';

/**
 * @return array{ok:bool,errors:string[],manifest?:array<string,mixed>}
 */
function wp_fts_analyzer_source_lock_validate_file(string $manifestPath): array
{
    $errors = [];
    if (!is_file($manifestPath)) {
        return [
            'ok' => false,
            'errors' => ["Manifest does not exist: {$manifestPath}"],
        ];
    }

    $contents = file_get_contents($manifestPath);
    if ($contents === false) {
        return [
            'ok' => false,
            'errors' => ["Manifest is not readable: {$manifestPath}"],
        ];
    }

    try {
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return [
            'ok' => false,
            'errors' => ["Manifest is not valid JSON: {$e->getMessage()}"],
        ];
    }

    if (!is_array($manifest)) {
        return [
            'ok' => false,
            'errors' => ['Manifest root must be a JSON object.'],
        ];
    }

    wp_fts_analyzer_source_lock_validate_manifest($manifest, dirname($manifestPath), $errors);

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'manifest' => $manifest,
    ];
}

/**
 * @param array<string,mixed> $manifest
 * @param string[] $errors
 */
function wp_fts_analyzer_source_lock_validate_manifest(array $manifest, string $baseDir, array &$errors): void
{
    $requiredStrings = [
        'schema_version',
        'pack.id',
        'pack.language',
        'pack.kind',
        'pack.status',
        'source.name',
        'source.url',
        'source.version',
        'source.artifact_sha256',
        'source.license.spdx_id',
        'source.license.notice_path',
        'importer.command',
        'importer.commit',
        'analyzer.signature',
        'analyzer.runtime_digest_sha256',
        'runtime.path',
        'runtime.digest_sha256',
        'behavior.oov_policy',
        'behavior.ambiguity_policy',
        'compliance.fixture_path',
        'release.claim_boundary',
    ];

    foreach ($requiredStrings as $path) {
        wp_fts_analyzer_source_lock_required_string($manifest, $path, $errors);
    }

    wp_fts_analyzer_source_lock_required_bool($manifest, 'behavior.noop', $errors);
    wp_fts_analyzer_source_lock_required_bool($manifest, 'runtime.contains_third_party_data', $errors);
    wp_fts_analyzer_source_lock_required_int($manifest, 'runtime.row_count', $errors);

    if (wp_fts_analyzer_source_lock_get($manifest, 'schema_version') !== WP_FTS_ANALYZER_SOURCE_LOCK_SCHEMA_VERSION) {
        $errors[] = 'schema_version must be ' . WP_FTS_ANALYZER_SOURCE_LOCK_SCHEMA_VERSION . '.';
    }

    $packId = wp_fts_analyzer_source_lock_string($manifest, 'pack.id');
    if ($packId !== null && preg_match('/^[a-z0-9][a-z0-9._-]{2,80}$/', $packId) !== 1) {
        $errors[] = 'pack.id must be a stable lowercase id using letters, digits, dots, underscores, or hyphens.';
    }

    $language = wp_fts_analyzer_source_lock_string($manifest, 'pack.language');
    if ($language !== null && preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $language) !== 1) {
        $errors[] = 'pack.language must be a BCP 47-style language tag such as en, pl, or pl-PL.';
    }

    wp_fts_analyzer_source_lock_enum($manifest, 'pack.kind', ['analyzer', 'lemmatizer', 'normalizer', 'noop', 'stemmer', 'tokenizer'], $errors);
    wp_fts_analyzer_source_lock_enum($manifest, 'pack.status', ['experimental', 'fixture', 'production_candidate'], $errors);

    $sourceUrl = wp_fts_analyzer_source_lock_string($manifest, 'source.url');
    if ($sourceUrl !== null && preg_match('/^[a-z][a-z0-9+.-]*:/i', $sourceUrl) !== 1) {
        $errors[] = 'source.url must be an absolute URI.';
    }

    foreach (['source.artifact_sha256', 'analyzer.runtime_digest_sha256', 'runtime.digest_sha256'] as $path) {
        wp_fts_analyzer_source_lock_sha256($manifest, $path, $errors);
    }

    if (wp_fts_analyzer_source_lock_get($manifest, 'analyzer.runtime_digest_sha256') !== wp_fts_analyzer_source_lock_get($manifest, 'runtime.digest_sha256')) {
        $errors[] = 'analyzer.runtime_digest_sha256 must match runtime.digest_sha256.';
    }

    $sourceArtifactPath = wp_fts_analyzer_source_lock_string($manifest, 'source.artifact_path');
    if ($sourceArtifactPath !== null) {
        wp_fts_analyzer_source_lock_validate_relative_hash(
            $baseDir,
            $sourceArtifactPath,
            (string) wp_fts_analyzer_source_lock_get($manifest, 'source.artifact_sha256'),
            'source.artifact_path',
            $errors
        );
    }

    wp_fts_analyzer_source_lock_validate_relative_path(
        $baseDir,
        (string) wp_fts_analyzer_source_lock_get($manifest, 'source.license.notice_path'),
        'source.license.notice_path',
        true,
        $errors
    );

    wp_fts_analyzer_source_lock_validate_relative_hash(
        $baseDir,
        (string) wp_fts_analyzer_source_lock_get($manifest, 'runtime.path'),
        (string) wp_fts_analyzer_source_lock_get($manifest, 'runtime.digest_sha256'),
        'runtime.path',
        $errors
    );

    $fixturePath = (string) wp_fts_analyzer_source_lock_get($manifest, 'compliance.fixture_path');
    $resolvedFixture = wp_fts_analyzer_source_lock_validate_relative_path($baseDir, $fixturePath, 'compliance.fixture_path', true, $errors);
    if ($resolvedFixture !== null) {
        wp_fts_analyzer_source_lock_validate_compliance_fixture($resolvedFixture, $packId ?? '', $errors);
    }

    $rowCount = wp_fts_analyzer_source_lock_get($manifest, 'runtime.row_count');
    $noop = wp_fts_analyzer_source_lock_get($manifest, 'behavior.noop');
    $containsThirdPartyData = wp_fts_analyzer_source_lock_get($manifest, 'runtime.contains_third_party_data');

    if ($noop === true && $rowCount !== 0) {
        $errors[] = 'No-op manifests must declare runtime.row_count as 0.';
    }

    if ($noop === true && $containsThirdPartyData !== false) {
        $errors[] = 'No-op manifests must declare runtime.contains_third_party_data as false.';
    }

}

/**
 * @param array<string,mixed> $fixture
 * @param string[] $errors
 */
function wp_fts_analyzer_source_lock_validate_fixture_array(array $fixture, string $packId, array &$errors): void
{
    if (($fixture['format'] ?? null) !== 'wp-fts-analyzer-compliance-fixture/v1') {
        $errors[] = 'Compliance fixture format must be wp-fts-analyzer-compliance-fixture/v1.';
    }

    if (($fixture['pack_id'] ?? null) !== $packId) {
        $errors[] = 'Compliance fixture pack_id must match pack.id.';
    }

    if (!isset($fixture['cases']) || !is_array($fixture['cases']) || $fixture['cases'] === []) {
        $errors[] = 'Compliance fixture must include at least one case.';
        return;
    }

    foreach ($fixture['cases'] as $index => $case) {
        if (!is_array($case)) {
            $errors[] = "Compliance fixture case {$index} must be an object.";
            continue;
        }

        foreach (['input', 'expected_output', 'language', 'reason'] as $field) {
            if (!isset($case[$field]) || !is_string($case[$field]) || trim($case[$field]) === '') {
                $errors[] = "Compliance fixture case {$index} must include non-empty string {$field}.";
            }
        }
    }
}

/**
 * @param string[] $errors
 */
function wp_fts_analyzer_source_lock_validate_compliance_fixture(string $path, string $packId, array &$errors): void
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        $errors[] = "Compliance fixture is not readable: {$path}";
        return;
    }

    try {
        $fixture = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $errors[] = "Compliance fixture is not valid JSON: {$e->getMessage()}";
        return;
    }

    if (!is_array($fixture)) {
        $errors[] = 'Compliance fixture root must be a JSON object.';
        return;
    }

    wp_fts_analyzer_source_lock_validate_fixture_array($fixture, $packId, $errors);
}

/**
 * @param array<string,mixed> $root
 */
function wp_fts_analyzer_source_lock_get(array $root, string $path): mixed
{
    $current = $root;
    foreach (explode('.', $path) as $part) {
        if (!is_array($current) || !array_key_exists($part, $current)) {
            return null;
        }
        $current = $current[$part];
    }

    return $current;
}

/**
 * @param array<string,mixed> $root
 */
function wp_fts_analyzer_source_lock_string(array $root, string $path): ?string
{
    $value = wp_fts_analyzer_source_lock_get($root, $path);
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    return $value;
}

/**
 * @param array<string,mixed> $root
 * @param string[] $errors
 */
function wp_fts_analyzer_source_lock_required_string(array $root, string $path, array &$errors): void
{
    if (wp_fts_analyzer_source_lock_string($root, $path) === null) {
        $errors[] = "{$path} is required and must be a non-empty string.";
    }
}

/**
 * @param array<string,mixed> $root
 * @param string[] $errors
 */
function wp_fts_analyzer_source_lock_required_bool(array $root, string $path, array &$errors): void
{
    if (!is_bool(wp_fts_analyzer_source_lock_get($root, $path))) {
        $errors[] = "{$path} is required and must be a boolean.";
    }
}

/**
 * @param array<string,mixed> $root
 * @param string[] $errors
 */
function wp_fts_analyzer_source_lock_required_int(array $root, string $path, array &$errors): void
{
    $value = wp_fts_analyzer_source_lock_get($root, $path);
    if (!is_int($value) || $value < 0) {
        $errors[] = "{$path} is required and must be a non-negative integer.";
    }
}

/**
 * @param array<string,mixed> $root
 * @param string[] $allowed
 * @param string[] $errors
 */
function wp_fts_analyzer_source_lock_enum(array $root, string $path, array $allowed, array &$errors): void
{
    $value = wp_fts_analyzer_source_lock_string($root, $path);
    if ($value !== null && !in_array($value, $allowed, true)) {
        $errors[] = "{$path} must be one of: " . implode(', ', $allowed) . '.';
    }
}

/**
 * @param array<string,mixed> $root
 * @param string[] $errors
 */
function wp_fts_analyzer_source_lock_sha256(array $root, string $path, array &$errors): void
{
    $value = wp_fts_analyzer_source_lock_string($root, $path);
    if ($value !== null && preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
        $errors[] = "{$path} must be a lowercase SHA-256 hex digest.";
    }
}

/**
 * @param string[] $errors
 */
function wp_fts_analyzer_source_lock_validate_relative_hash(string $baseDir, string $path, string $expectedHash, string $label, array &$errors): void
{
    $resolved = wp_fts_analyzer_source_lock_validate_relative_path($baseDir, $path, $label, true, $errors);
    if ($resolved === null || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1) {
        return;
    }

    $actualHash = hash_file('sha256', $resolved);
    if (!is_string($actualHash) || $actualHash !== $expectedHash) {
        $errors[] = "{$label} SHA-256 mismatch; expected {$expectedHash}.";
    }
}

/**
 * @param string[] $errors
 */
function wp_fts_analyzer_source_lock_validate_relative_path(string $baseDir, string $path, string $label, bool $mustExist, array &$errors): ?string
{
    if ($path === '' || str_starts_with($path, '/') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1) {
        $errors[] = "{$label} must be a relative repository path.";
        return null;
    }

    $segments = explode('/', str_replace('\\', '/', $path));
    if (in_array('..', $segments, true)) {
        $errors[] = "{$label} must not contain parent-directory traversal.";
        return null;
    }

    $candidate = $baseDir . DIRECTORY_SEPARATOR . $path;
    if ($mustExist && !is_file($candidate)) {
        $errors[] = "{$label} does not exist relative to the manifest: {$path}.";
        return null;
    }

    return $candidate;
}

/**
 * @param string[] $paths
 */
function wp_fts_analyzer_source_lock_cli(array $paths): int
{
    if ($paths === []) {
        $paths = glob(dirname(__DIR__) . '/tests/fixtures/analyzer-source-locks/*.source-lock.json') ?: [];
        sort($paths, SORT_STRING);
    }

    if ($paths === []) {
        fwrite(STDERR, "No analyzer source-lock manifests found.\n");
        return 1;
    }

    $failed = false;
    foreach ($paths as $path) {
        $result = wp_fts_analyzer_source_lock_validate_file($path);
        if ($result['ok']) {
            fwrite(STDOUT, "[PASS] {$path}\n");
            continue;
        }

        $failed = true;
        fwrite(STDERR, "[FAIL] {$path}\n");
        foreach ($result['errors'] as $error) {
            fwrite(STDERR, " - {$error}\n");
        }
    }

    return $failed ? 1 : 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === __FILE__) {
    exit(wp_fts_analyzer_source_lock_cli(array_slice($argv, 1)));
}
