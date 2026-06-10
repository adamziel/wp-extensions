<?php
declare(strict_types=1);

/**
 * Verifier for the Polish lemmatizer source-lock pilot fixture.
 *
 * This file is intentionally standalone so reviewers can run it directly, while
 * tests/run.php also discovers it as a normal quality test.
 */

function wp_fts_plsl_fixture_path(): string
{
    return dirname(__DIR__) . '/fixtures/source-lock/polish-lemmatizer-pilot.json';
}

/**
 * @return array<string,mixed>
 */
function wp_fts_plsl_load_fixture(): array
{
    $path = wp_fts_plsl_fixture_path();
    $json = file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException("Could not read Polish source-lock fixture at {$path}.");
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new RuntimeException('Polish source-lock fixture is not valid JSON: ' . json_last_error_msg());
    }

    return $data;
}

/**
 * @param array<string,mixed> $manifest
 * @return string[]
 */
function wp_fts_plsl_validate_manifest(array $manifest): array
{
    $errors = [];

    if (($manifest['schema'] ?? null) !== 'wp-fts-polish-lemmatizer-source-lock-pilot-v1') {
        $errors[] = 'schema must be wp-fts-polish-lemmatizer-source-lock-pilot-v1';
    }

    $requiredStrings = [
        'pack.language',
        'pack.stage',
        'pack.candidate_family',
        'pack.source_identity.source_id',
        'pack.source_identity.source_name',
        'pack.source_identity.upstream_family',
        'pack.source_identity.canonical_artifact',
        'pack.source_identity.artifact_version',
        'pack.source_identity.source_url',
        'pack.source_identity.artifact_type',
        'pack.source_identity.selection_status',
        'pack.license.spdx_id',
        'pack.license.license_url',
        'pack.license.license_text',
        'pack.license.redistribution_status',
        'pack.artifact.sha256',
        'pack.artifact.byte_count',
        'pack.artifact.hash_status',
        'pack.importer.provenance_id',
        'pack.importer.command',
        'pack.importer.implementation_status',
        'pack.policies.ambiguity_policy.decision',
        'pack.policies.ambiguity_policy.required_fixture',
        'pack.policies.no_op_fallback_policy.behavior',
        'pack.policies.no_op_fallback_policy.required_fixture',
        'pack.current_runtime.behavior',
        'pack.current_runtime.default_status',
        'pack.future_pack_gate.default_status_before_full_lock',
        'pack.future_pack_gate.claim_boundary',
    ];

    foreach ($requiredStrings as $path) {
        $value = wp_fts_plsl_path($manifest, $path);
        if (!is_string($value) || trim($value) === '') {
            $errors[] = "{$path} must be a non-empty string";
        }
    }

    foreach (['pack.runtime_pack_committed', 'pack.third_party_lexical_data_committed', 'pack.license.notice_required'] as $path) {
        if (!is_bool(wp_fts_plsl_path($manifest, $path))) {
            $errors[] = "{$path} must be a boolean";
        }
    }

    if (wp_fts_plsl_path($manifest, 'pack.language') !== 'pl') {
        $errors[] = 'pack.language must be pl';
    }

    if (wp_fts_plsl_path($manifest, 'pack.stage') !== 'lemmatizer') {
        $errors[] = 'pack.stage must be lemmatizer';
    }

    if (wp_fts_plsl_path($manifest, 'pack.runtime_pack_committed') !== false) {
        $errors[] = 'pack.runtime_pack_committed must remain false for this pilot';
    }

    if (wp_fts_plsl_path($manifest, 'pack.third_party_lexical_data_committed') !== false) {
        $errors[] = 'pack.third_party_lexical_data_committed must remain false for this pilot';
    }

    if (wp_fts_plsl_path($manifest, 'pack.current_runtime.default_status') !== 'conservative_suffix_fallback') {
        $errors[] = 'pack.current_runtime.default_status must document conservative_suffix_fallback';
    }

    $artifactHash = wp_fts_plsl_path($manifest, 'pack.artifact.sha256');
    if (is_string($artifactHash) && !str_starts_with($artifactHash, 'PENDING_') && preg_match('/^[a-f0-9]{64}$/', $artifactHash) !== 1) {
        $errors[] = 'pack.artifact.sha256 must be a pending marker or a lower-case SHA-256 hex digest';
    }

    $byteCount = wp_fts_plsl_path($manifest, 'pack.artifact.byte_count');
    if (is_string($byteCount) && !str_starts_with($byteCount, 'PENDING_') && preg_match('/^[1-9][0-9]*$/', $byteCount) !== 1) {
        $errors[] = 'pack.artifact.byte_count must be a pending marker or a positive integer string';
    }

    $licenseText = wp_fts_plsl_path($manifest, 'pack.license.license_text');
    if (is_string($licenseText) && !str_contains(strtolower($licenseText), 'license')) {
        $errors[] = 'pack.license.license_text must name the license text/notice requirement';
    }

    $importerCommand = wp_fts_plsl_path($manifest, 'pack.importer.command');
    if (is_string($importerCommand) && !str_starts_with($importerCommand, 'php indexer/tools/')) {
        $errors[] = 'pack.importer.command must be a repository-local PHP importer command';
    }

    $ambiguity = wp_fts_plsl_path($manifest, 'pack.policies.ambiguity_policy.required_fixture');
    if (is_string($ambiguity) && !str_contains(strtolower($ambiguity), 'ambiguous')) {
        $errors[] = 'pack.policies.ambiguity_policy.required_fixture must cover ambiguous forms';
    }

    $fallback = wp_fts_plsl_path($manifest, 'pack.policies.no_op_fallback_policy.behavior');
    if (is_string($fallback) && !str_contains(strtolower($fallback), 'original normalized term')) {
        $errors[] = 'pack.policies.no_op_fallback_policy.behavior must require original normalized term fallback';
    }

    foreach (['sample_rows', 'lexical_rows', 'dictionary_rows', 'runtime_pack'] as $forbiddenKey) {
        if (wp_fts_plsl_contains_key($manifest, $forbiddenKey)) {
            $errors[] = "{$forbiddenKey} must not be present in the source-lock pilot fixture";
        }
    }

    return $errors;
}

/**
 * @param array<string,mixed> $data
 */
function wp_fts_plsl_path(array $data, string $path): mixed
{
    $value = $data;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }

    return $value;
}

/**
 * @param array<string,mixed> $data
 */
function wp_fts_plsl_unset_path(array &$data, string $path): void
{
    $segments = explode('.', $path);
    $last = array_pop($segments);
    $target =& $data;
    foreach ($segments as $segment) {
        if (!is_array($target) || !array_key_exists($segment, $target)) {
            return;
        }
        $target =& $target[$segment];
    }

    if (is_array($target) && $last !== null) {
        unset($target[$last]);
    }
}

/**
 * @param array<string,mixed> $data
 */
function wp_fts_plsl_contains_key(array $data, string $needle): bool
{
    foreach ($data as $key => $value) {
        if ($key === $needle) {
            return true;
        }
        if (is_array($value) && wp_fts_plsl_contains_key($value, $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<string,string>
 */
function wp_fts_plsl_required_field_cases(): array
{
    return [
        'source identity' => 'pack.source_identity.source_id',
        'license URL' => 'pack.license.license_url',
        'license text' => 'pack.license.license_text',
        'artifact hash' => 'pack.artifact.sha256',
        'importer command' => 'pack.importer.command',
        'provenance id' => 'pack.importer.provenance_id',
        'ambiguity policy' => 'pack.policies.ambiguity_policy.required_fixture',
        'no-op fallback policy' => 'pack.policies.no_op_fallback_policy.behavior',
    ];
}

/**
 * @return string[]
 */
function wp_fts_plsl_run_verifier(): array
{
    $errors = wp_fts_plsl_validate_manifest(wp_fts_plsl_load_fixture());
    if ($errors !== []) {
        return $errors;
    }

    $fixture = wp_fts_plsl_load_fixture();
    foreach (wp_fts_plsl_required_field_cases() as $label => $path) {
        $invalid = $fixture;
        wp_fts_plsl_unset_path($invalid, $path);
        $caseErrors = wp_fts_plsl_validate_manifest($invalid);
        $matched = false;
        foreach ($caseErrors as $caseError) {
            if (str_contains($caseError, $path)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $errors[] = "missing {$label} case did not produce an error for {$path}";
        }
    }

    return $errors;
}

if (function_exists('test_case')) {
    test_case('quality Polish lemmatizer source-lock pilot fixture is complete and data-free', function (): void {
        $errors = wp_fts_plsl_validate_manifest(wp_fts_plsl_load_fixture());
        assert_same([], $errors, 'Polish source-lock fixture should satisfy pilot metadata gates');
    });

    test_case('quality Polish lemmatizer source-lock verifier rejects missing required gates', function (): void {
        $fixture = wp_fts_plsl_load_fixture();
        foreach (wp_fts_plsl_required_field_cases() as $label => $path) {
            $invalid = $fixture;
            wp_fts_plsl_unset_path($invalid, $path);
            $errors = wp_fts_plsl_validate_manifest($invalid);
            $matched = false;
            foreach ($errors as $error) {
                if (str_contains($error, $path)) {
                    $matched = true;
                    break;
                }
            }

            record_check("Polish source-lock missing {$label}");
            assert_true($matched, "missing {$label} should be rejected at {$path}");
        }
    });
} elseif (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $errors = wp_fts_plsl_run_verifier();
    if ($errors !== []) {
        fwrite(STDERR, "Polish lemmatizer source-lock pilot verifier failed:\n- " . implode("\n- ", $errors) . "\n");
        exit(1);
    }

    fwrite(STDOUT, "Polish lemmatizer source-lock pilot verifier passed.\n");
}
