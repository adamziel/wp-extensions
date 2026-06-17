<?php
declare(strict_types=1);

/**
 * Validates source-lock metadata required before adding bundled tokenizer data.
 *
 * This verifier intentionally covers only the future Thai TCC dictionary pilot.
 * It does not load dictionaries, implement TCC rules, or register a tokenizer.
 */
final class WP_FTS_TokenizerSourceLockVerifier
{
    public const TOKENIZER_ID = 'thai_dictionary_tcc_v1';
    public const TOKENIZER_TYPE = 'thai_tcc_dictionary_segmenter';
    public const LANGUAGE_ID = 'th';

    /**
     * @param array<string,mixed> $metadata
     * @param array{allow_test_fixture?:bool} $options
     * @return string[] Validation errors. An empty list means the record shape is complete.
     */
    public function validate(array $metadata, array $options = []): array
    {
        $errors = [];
        $allowTestFixture = (bool) ($options['allow_test_fixture'] ?? false);
        $kind = $metadata['metadata_kind'] ?? null;
        $isTestFixture = $kind === 'tokenizer_source_lock_test_fixture';

        if ($kind === 'tokenizer_source_lock') {
            $this->require_exact_string($metadata, 'status', 'approved', $errors);
        } elseif ($isTestFixture) {
            if (!$allowTestFixture) {
                $errors[] = 'metadata_kind marks this record as a test fixture; pass allow_test_fixture only in tests.';
            }
            $this->require_exact_bool($metadata, 'fixture_only', true, $errors);
            $this->require_non_empty_string($metadata, 'test_fixture_note', $errors);
            $this->require_exact_string($metadata, 'status', 'test_fixture_complete', $errors);
        } else {
            $errors[] = 'metadata_kind must be tokenizer_source_lock.';
        }

        $this->require_int($metadata, 'source_lock_version', 1, $errors);
        $this->require_exact_string($metadata, 'tokenizer_id', self::TOKENIZER_ID, $errors);
        $this->require_exact_string($metadata, 'tokenizer_type', self::TOKENIZER_TYPE, $errors);
        $this->require_exact_string($metadata, 'language_id', self::LANGUAGE_ID, $errors);

        $this->validate_dictionary($metadata, $errors);
        $this->validate_tcc_rules($metadata, $errors);
        $this->validate_normalization_policy($metadata, $errors);
        $this->validate_approval($metadata, $errors);
        $this->validate_no_go_conditions($metadata, $errors);
        $this->require_non_empty_string($metadata, 'clean_room_implementation_notes', $errors);

        return $errors;
    }

    /**
     * @param array{allow_test_fixture?:bool} $options
     * @return string[]
     */
    public function validate_file(string $path, array $options = []): array
    {
        if (!is_file($path)) {
            return ["{$path}: source-lock metadata file does not exist."];
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return ["{$path}: source-lock metadata file could not be read."];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return ["{$path}: invalid JSON: {$e->getMessage()}"];
        }

        if (!is_array($decoded)) {
            return ["{$path}: source-lock metadata must decode to an object."];
        }

        return array_map(
            static fn(string $error): string => "{$path}: {$error}",
            $this->validate($decoded, $options)
        );
    }

    /**
     * @param array<string,mixed> $metadata
     * @param string[] $errors
     */
    private function validate_dictionary(array $metadata, array &$errors): void
    {
        $dictionary = $this->require_map($metadata, 'dictionary', $errors);
        if ($dictionary === null) {
            return;
        }

        foreach ([
            'source_id',
            'source_name',
            'upstream_url',
            'upstream_repository_owner',
            'upstream_path',
            'version_ref',
            'retrieved_at',
            'retrieval_method',
            'source_chain_evidence',
        ] as $field) {
            $this->require_non_empty_string($metadata, "dictionary.{$field}", $errors);
        }

        $this->require_positive_int($metadata, 'dictionary.byte_count', $errors);
        $this->require_sha256($metadata, 'dictionary.sha256', $errors);
        $this->require_enum($metadata, 'dictionary.data_kind', [
            'imported_comprehensive',
            'imported_seed',
            'curated_seed',
            'reference_only',
        ], $errors);

        $license = $this->require_map($metadata, 'dictionary.license', $errors);
        if ($license === null) {
            return;
        }

        foreach ([
            'id',
            'name',
            'url',
            'text_file',
            'copyright_holder',
            'attribution_text',
            'notice_obligations',
        ] as $field) {
            $this->require_non_empty_string($metadata, "dictionary.license.{$field}", $errors);
        }
    }

    /**
     * @param array<string,mixed> $metadata
     * @param string[] $errors
     */
    private function validate_tcc_rules(array $metadata, array &$errors): void
    {
        $rules = $this->require_map($metadata, 'tcc_rules', $errors);
        if ($rules === null) {
            return;
        }

        $variant = $rules['variant'] ?? null;
        if (!is_string($variant) || trim($variant) === '') {
            $errors[] = 'tcc_rules.variant is required.';
        } elseif ($variant !== 'tcc' && $variant !== 'tcc_plus' && !str_starts_with($variant, 'project_')) {
            $errors[] = 'tcc_rules.variant must be tcc, tcc_plus, or a project_* variant.';
        }

        foreach ([
            'local_version_id',
            'source_id',
            'citation_or_url',
            'source_version_or_date',
            'license_or_rights_basis',
            'implementation_approach',
            'subword_boundary_notice',
        ] as $field) {
            $this->require_non_empty_string($metadata, "tcc_rules.{$field}", $errors);
        }

        $this->require_sha256($metadata, 'tcc_rules.source_sha256', $errors);
        $this->require_exact_string($metadata, 'tcc_rules.implementation_approach', 'clean_room_project_owned', $errors);
        $this->require_contains($metadata, 'tcc_rules.subword_boundary_notice', 'not Thai word segmentation', $errors);

        $cleanRoom = $this->require_map($metadata, 'tcc_rules.clean_room', $errors);
        if ($cleanRoom === null) {
            return;
        }

        $references = $cleanRoom['references_consulted'] ?? null;
        if (!is_array($references) || $references === []) {
            $errors[] = 'tcc_rules.clean_room.references_consulted must list at least one reference.';
        } else {
            foreach ($references as $index => $reference) {
                if (!is_string($reference) || trim($reference) === '') {
                    $errors[] = "tcc_rules.clean_room.references_consulted[{$index}] must be a non-empty string.";
                }
            }
        }

        $this->require_non_empty_string($metadata, 'tcc_rules.clean_room.local_implementation_author', $errors);
        $this->require_exact_bool($metadata, 'tcc_rules.clean_room.no_upstream_code_copied', true, $errors);
        $this->require_exact_bool($metadata, 'tcc_rules.clean_room.no_upstream_grammar_copied', true, $errors);
        $this->require_non_empty_string($metadata, 'tcc_rules.clean_room.notes', $errors);
    }

    /**
     * @param array<string,mixed> $metadata
     * @param string[] $errors
     */
    private function validate_normalization_policy(array $metadata, array &$errors): void
    {
        $policy = $this->require_map($metadata, 'normalization_policy', $errors);
        if ($policy === null) {
            return;
        }

        foreach ([
            'unicode_normalization',
            'utf8_validation',
            'blank_and_control_policy',
            'duplicate_policy',
            'sort_order',
            'tie_break_policy',
        ] as $field) {
            $this->require_non_empty_string($metadata, "normalization_policy.{$field}", $errors);
        }

        foreach ([
            'max_token_bytes',
            'max_source_bytes',
            'max_generated_rows',
            'max_thai_run_bytes',
        ] as $field) {
            $this->require_positive_int($metadata, "normalization_policy.{$field}", $errors);
        }
    }

    /**
     * @param array<string,mixed> $metadata
     * @param string[] $errors
     */
    private function validate_approval(array $metadata, array &$errors): void
    {
        $approval = $this->require_map($metadata, 'approval', $errors);
        if ($approval === null) {
            return;
        }

        $this->require_exact_bool($metadata, 'approval.wordpress_gpl2_or_later_compatible', true, $errors);
        $this->require_exact_bool($metadata, 'approval.wordpress_org_package_redistribution_approved', true, $errors);
        $this->require_non_empty_string($metadata, 'approval.approved_by', $errors);
        $this->require_non_empty_string($metadata, 'approval.approved_at', $errors);
        $this->require_non_empty_string($metadata, 'approval.notes', $errors);
    }

    /**
     * @param array<string,mixed> $metadata
     * @param string[] $errors
     */
    private function validate_no_go_conditions(array $metadata, array &$errors): void
    {
        $conditions = $this->require_map($metadata, 'no_go_conditions', $errors);
        if ($conditions === null) {
            return;
        }

        foreach ([
            'no_runtime_network_fetches',
            'no_unpinned_artifacts',
            'no_apache2_code_or_rules_copied',
            'no_gpl3_code_or_grammar_copied',
            'no_cc_by_sa_data_bundled_without_separate_approval',
            'no_production_adapter_before_this_lock_is_approved',
            'no_full_thai_or_cjk_support_claim',
        ] as $field) {
            $this->require_exact_bool($metadata, "no_go_conditions.{$field}", true, $errors);
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param string[] $errors
     * @return array<string,mixed>|null
     */
    private function require_map(array $data, string $path, array &$errors): ?array
    {
        $value = $this->value_at($data, $path);
        if (!is_array($value) || !$this->is_associative_array($value)) {
            $errors[] = "{$path} must be an object.";
            return null;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $data
     * @param string[] $errors
     */
    private function require_non_empty_string(array $data, string $path, array &$errors): void
    {
        $value = $this->value_at($data, $path);
        if (!is_string($value) || trim($value) === '') {
            $errors[] = "{$path} must be a non-empty string.";
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param string[] $errors
     */
    private function require_exact_string(array $data, string $path, string $expected, array &$errors): void
    {
        $value = $this->value_at($data, $path);
        if ($value !== $expected) {
            $errors[] = "{$path} must be {$expected}.";
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param string[] $errors
     */
    private function require_contains(array $data, string $path, string $needle, array &$errors): void
    {
        $value = $this->value_at($data, $path);
        if (!is_string($value) || !str_contains($value, $needle)) {
            $errors[] = "{$path} must state {$needle}.";
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param string[] $errors
     */
    private function require_exact_bool(array $data, string $path, bool $expected, array &$errors): void
    {
        $value = $this->value_at($data, $path);
        if ($value !== $expected) {
            $errors[] = "{$path} must be " . ($expected ? 'true' : 'false') . '.';
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param string[] $errors
     */
    private function require_int(array $data, string $path, int $expected, array &$errors): void
    {
        $value = $this->value_at($data, $path);
        if ($value !== $expected) {
            $errors[] = "{$path} must be {$expected}.";
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param string[] $errors
     */
    private function require_positive_int(array $data, string $path, array &$errors): void
    {
        $value = $this->value_at($data, $path);
        if (!is_int($value) || $value <= 0) {
            $errors[] = "{$path} must be a positive integer.";
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param string[] $errors
     */
    private function require_sha256(array $data, string $path, array &$errors): void
    {
        $value = $this->value_at($data, $path);
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            $errors[] = "{$path} must be a lowercase 64-character SHA-256 hex digest.";
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param string[] $allowed
     * @param string[] $errors
     */
    private function require_enum(array $data, string $path, array $allowed, array &$errors): void
    {
        $value = $this->value_at($data, $path);
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $errors[] = "{$path} must be one of: " . implode(', ', $allowed) . '.';
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function value_at(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /**
     * @param array<mixed> $value
     */
    private function is_associative_array(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}
