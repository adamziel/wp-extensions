<?php
declare(strict_types=1);

/**
 * Verifies the Thai tokenizer source-candidate preflight lock.
 *
 * The verifier intentionally checks metadata only. It does not fetch public
 * URLs, read tokenizer data, load dictionary rows, or implement TCC rules.
 */
final class WP_FTS_ThaiTokenizerSourceCandidateLockVerifier
{
    public const SCHEMA = 'wp-fts-thai-tokenizer-source-candidate-lock-v1';
    public const LOCK_KIND = 'thai_tokenizer_exact_source_candidate_preflight';
    public const TOKENIZER_ID = 'thai_dictionary_tcc_v1';
    public const TOKENIZER_TYPE = 'thai_tcc_dictionary_segmenter';
    public const LANGUAGE_ID = 'th';

    /**
     * @var array<string,bool>
     */
    private const PENDING_EXACT_VALUE_PATHS = [
        'dictionary.version_ref' => true,
        'dictionary.artifact.name' => true,
        'dictionary.artifact.url' => true,
        'dictionary.artifact.sha256' => true,
        'dictionary.artifact.bytes' => true,
        'dictionary.license.identifier' => true,
        'dictionary.license.name' => true,
        'dictionary.license.url' => true,
        'dictionary.license.text_url' => true,
        'tcc_rules.variant' => true,
        'tcc_rules.rule_artifact.name' => true,
        'tcc_rules.rule_artifact.url' => true,
        'tcc_rules.rule_artifact.sha256' => true,
        'tcc_rules.rule_artifact.bytes' => true,
    ];

    /**
     * @return array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool}
     */
    public function verify_file(string $path, bool $allow_pending_exact_values = false, ?string $repo_root = null): array
    {
        $report = $this->empty_report($path, $allow_pending_exact_values);
        if (!is_file($path)) {
            $report['errors'][] = 'Source-candidate lock file does not exist: ' . $path;

            return $report;
        }

        $json = file_get_contents($path);
        if (!is_string($json)) {
            $report['errors'][] = 'Source-candidate lock file could not be read: ' . $path;

            return $report;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $report['errors'][] = 'Source-candidate lock file contains invalid JSON: ' . $exception->getMessage();

            return $report;
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            $report['errors'][] = 'Source-candidate lock file must decode to a JSON object: ' . $path;

            return $report;
        }

        return $this->verify($decoded, $path, $allow_pending_exact_values, $repo_root);
    }

    /**
     * @param array<string,mixed> $lock
     * @return array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool}
     */
    public function verify(array $lock, string $label, bool $allow_pending_exact_values = false, ?string $repo_root = null): array
    {
        $report = $this->empty_report($label, $allow_pending_exact_values);

        $this->require_exact_string($lock, 'schema', self::SCHEMA, $report);
        $this->require_exact_string($lock, 'lock_kind', self::LOCK_KIND, $report);
        $this->require_exact_string($lock, 'status', 'candidate_preflight_only', $report);
        $this->require_exact_string($lock, 'tokenizer_id', self::TOKENIZER_ID, $report);
        $this->require_exact_string($lock, 'tokenizer_type', self::TOKENIZER_TYPE, $report);
        $this->require_exact_string($lock, 'language_id', self::LANGUAGE_ID, $report);
        $this->require_exact_string($lock, 'data_kind', 'reference_only', $report);

        $this->verify_candidate_family($lock, $report);
        $this->verify_dictionary($lock, $allow_pending_exact_values, $report);
        $this->verify_tcc_rules($lock, $allow_pending_exact_values, $report);
        $this->verify_normalization_policy($lock, $report);
        $this->verify_questions($lock, $report);
        $this->verify_sources_inspected($lock, $report);
        $this->verify_claim_boundaries($lock, $report);
        $this->verify_no_go_conditions($lock, $report);
        $this->verify_verification_plan($lock, $repo_root, $report);

        $report['blocks_adapter'] = $report['pending_exact_values'] !== []
            || $this->value_at_path($lock, 'claim_boundaries.dictionary_data_committed') === false
            || $this->value_at_path($lock, 'claim_boundaries.tcc_rule_data_committed') === false
            || $this->value_at_path($lock, 'claim_boundaries.tokenizer_adapter_coded') === false
            || $this->value_at_path($lock, 'claim_boundaries.real_thai_segmentation_shipped') === false;
        $report['valid'] = $report['errors'] === [];

        return $report;
    }

    /**
     * @param array<string,mixed> $lock
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function verify_candidate_family(array $lock, array &$report): void
    {
        $family = $this->require_object($lock, 'preferred_candidate_source_family', $report);
        if ($family === []) {
            return;
        }

        $name = $this->require_string($lock, 'preferred_candidate_source_family.name', $report);
        if ($name !== '' && stripos($name, 'PyThaiNLP') === false) {
            $report['warnings'][] = 'preferred_candidate_source_family.name should identify the PyThaiNLP source family chosen for this preflight.';
        }

        $this->require_string_list($lock, 'preferred_candidate_source_family.reasoning', $report, 3);
        $this->require_string_list($lock, 'preferred_candidate_source_family.not_selected_alternatives', $report, 1);
    }

    /**
     * @param array<string,mixed> $lock
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function verify_dictionary(array $lock, bool $allow_pending_exact_values, array &$report): void
    {
        $dictionary = $this->require_object($lock, 'dictionary', $report);
        if ($dictionary === []) {
            return;
        }

        foreach ([
            'dictionary.source_family',
            'dictionary.candidate_source_name',
            'dictionary.upstream_project_url',
            'dictionary.upstream_documentation_url',
            'dictionary.upstream_path',
            'dictionary.retrieval_method',
            'dictionary.source_chain_status',
        ] as $path) {
            $this->require_string($lock, $path, $report);
        }

        $this->require_http_url($this->string_value($lock, 'dictionary.upstream_project_url'), 'dictionary.upstream_project_url', $report);
        $this->require_http_url($this->string_value($lock, 'dictionary.upstream_documentation_url'), 'dictionary.upstream_documentation_url', $report);
        $this->require_exact_string_value($lock, 'dictionary.version_ref', $allow_pending_exact_values, $report);

        $artifact = $this->require_object($lock, 'dictionary.artifact', $report);
        if ($artifact !== []) {
            $this->require_exact_string_value($lock, 'dictionary.artifact.name', $allow_pending_exact_values, $report);
            $artifact_url = $this->require_exact_string_value($lock, 'dictionary.artifact.url', $allow_pending_exact_values, $report);
            $this->require_http_url($artifact_url, 'dictionary.artifact.url', $report);
            $artifact_sha256 = $this->require_exact_string_value($lock, 'dictionary.artifact.sha256', $allow_pending_exact_values, $report);
            $this->require_sha256($artifact_sha256, 'dictionary.artifact.sha256', $report);
            $this->require_exact_positive_int($lock, 'dictionary.artifact.bytes', $allow_pending_exact_values, $report);
        }

        $license = $this->require_object($lock, 'dictionary.license', $report);
        if ($license !== []) {
            $this->require_exact_string_value($lock, 'dictionary.license.identifier', $allow_pending_exact_values, $report);
            $this->require_exact_string_value($lock, 'dictionary.license.name', $allow_pending_exact_values, $report);
            $license_url = $this->require_exact_string_value($lock, 'dictionary.license.url', $allow_pending_exact_values, $report);
            $this->require_http_url($license_url, 'dictionary.license.url', $report);
            $text_url = $this->require_exact_string_value($lock, 'dictionary.license.text_url', $allow_pending_exact_values, $report);
            $this->require_http_url($text_url, 'dictionary.license.text_url', $report);
            $this->require_string($lock, 'dictionary.license.notice_file', $report);
            $this->require_string($lock, 'dictionary.license.compatibility_status', $report);
            $this->require_string($lock, 'dictionary.license.attribution_status', $report);
        }

        $this->require_string_list($lock, 'dictionary.missing_exact_identity_fields', $report, 8);
    }

    /**
     * @param array<string,mixed> $lock
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function verify_tcc_rules(array $lock, bool $allow_pending_exact_values, array &$report): void
    {
        $rules = $this->require_object($lock, 'tcc_rules', $report);
        if ($rules === []) {
            return;
        }

        foreach ([
            'tcc_rules.source_family',
            'tcc_rules.source_chain_status',
            'tcc_rules.clean_room_implementation_approach',
            'tcc_rules.subword_boundary_notice',
        ] as $path) {
            $this->require_string($lock, $path, $report);
        }

        $this->require_exact_string_value($lock, 'tcc_rules.variant', $allow_pending_exact_values, $report);
        $notice = $this->string_value($lock, 'tcc_rules.subword_boundary_notice');
        if ($notice !== '' && stripos($notice, 'not Thai word segmentation') === false) {
            $report['errors'][] = 'tcc_rules.subword_boundary_notice must state that TCC boundaries are not Thai word segmentation.';
        }

        $this->require_string_list($lock, 'tcc_rules.candidate_sources', $report, 2);

        $artifact = $this->require_object($lock, 'tcc_rules.rule_artifact', $report);
        if ($artifact !== []) {
            $this->require_exact_string_value($lock, 'tcc_rules.rule_artifact.name', $allow_pending_exact_values, $report);
            $artifact_url = $this->require_exact_string_value($lock, 'tcc_rules.rule_artifact.url', $allow_pending_exact_values, $report);
            $this->require_http_url($artifact_url, 'tcc_rules.rule_artifact.url', $report);
            $artifact_sha256 = $this->require_exact_string_value($lock, 'tcc_rules.rule_artifact.sha256', $allow_pending_exact_values, $report);
            $this->require_sha256($artifact_sha256, 'tcc_rules.rule_artifact.sha256', $report);
            $this->require_exact_positive_int($lock, 'tcc_rules.rule_artifact.bytes', $allow_pending_exact_values, $report);
        }

        $clean_room = $this->require_object($lock, 'tcc_rules.clean_room', $report);
        if ($clean_room !== []) {
            $this->require_string_list($lock, 'tcc_rules.clean_room.references_consulted', $report, 2);
            $this->require_string($lock, 'tcc_rules.clean_room.local_implementation_author_requirement', $report);
            $this->require_bool($lock, 'tcc_rules.clean_room.no_upstream_code_copied', true, $report);
            $this->require_bool($lock, 'tcc_rules.clean_room.no_upstream_regex_or_grammar_copied', true, $report);
            $this->require_string($lock, 'tcc_rules.clean_room.notes', $report);
        }

        $this->require_string_list($lock, 'tcc_rules.missing_exact_identity_fields', $report, 5);
    }

    /**
     * @param array<string,mixed> $lock
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function verify_normalization_policy(array $lock, array &$report): void
    {
        $policy = $this->require_object($lock, 'normalization_policy', $report);
        if ($policy === []) {
            return;
        }

        foreach ([
            'normalization_policy.unicode_normalization',
            'normalization_policy.utf8_validation',
            'normalization_policy.blank_and_control_policy',
            'normalization_policy.duplicate_policy',
            'normalization_policy.sort_order',
            'normalization_policy.tie_break_policy',
        ] as $path) {
            $this->require_string($lock, $path, $report);
        }

        foreach ([
            'normalization_policy.max_token_bytes',
            'normalization_policy.max_source_bytes',
            'normalization_policy.max_generated_rows',
            'normalization_policy.max_thai_run_bytes',
        ] as $path) {
            $this->require_positive_int($lock, $path, $report);
        }
    }

    /**
     * @param array<string,mixed> $lock
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function verify_questions(array $lock, array &$report): void
    {
        $questions = $this->value_at_path($lock, 'license_source_chain_questions');
        if (!is_array($questions) || $questions === [] || !array_is_list($questions)) {
            $report['errors'][] = 'license_source_chain_questions must be a non-empty list.';

            return;
        }

        foreach ($questions as $index => $question) {
            if (!is_array($question) || array_is_list($question)) {
                $report['errors'][] = "license_source_chain_questions[{$index}] must be an object.";
                continue;
            }

            foreach (['id', 'question', 'required_resolution'] as $field) {
                $value = $question[$field] ?? null;
                if (!is_string($value) || trim($value) === '' || $this->contains_placeholder_text($value)) {
                    $report['errors'][] = "license_source_chain_questions[{$index}].{$field} must be a concrete non-empty string.";
                }
            }
            if (($question['blocks_adapter'] ?? null) !== true) {
                $report['errors'][] = "license_source_chain_questions[{$index}].blocks_adapter must be true.";
            }
        }
    }

    /**
     * @param array<string,mixed> $lock
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function verify_sources_inspected(array $lock, array &$report): void
    {
        $sources = $this->value_at_path($lock, 'sources_inspected');
        if (!is_array($sources) || $sources === [] || !array_is_list($sources)) {
            $report['errors'][] = 'sources_inspected must be a non-empty list.';

            return;
        }

        foreach ($sources as $index => $source) {
            if (!is_array($source) || array_is_list($source)) {
                $report['errors'][] = "sources_inspected[{$index}] must be an object.";
                continue;
            }

            foreach (['label', 'url', 'evidence'] as $field) {
                $value = $source[$field] ?? null;
                if (!is_string($value) || trim($value) === '' || $this->contains_placeholder_text($value)) {
                    $report['errors'][] = "sources_inspected[{$index}].{$field} must be a concrete non-empty string.";
                }
            }
            $url = is_string($source['url'] ?? null) ? trim((string) $source['url']) : '';
            $this->require_http_url($url, "sources_inspected[{$index}].url", $report);
        }
    }

    /**
     * @param array<string,mixed> $lock
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function verify_claim_boundaries(array $lock, array &$report): void
    {
        $boundaries = $this->require_object($lock, 'claim_boundaries', $report);
        if ($boundaries === []) {
            return;
        }

        foreach ([
            'claim_boundaries.dictionary_data_committed',
            'claim_boundaries.tcc_rule_data_committed',
            'claim_boundaries.tokenizer_adapter_coded',
            'claim_boundaries.runtime_tokenizer_registered',
            'claim_boundaries.real_thai_segmentation_shipped',
            'claim_boundaries.user_facing_thai_support_claimed',
        ] as $path) {
            $this->require_bool($lock, $path, false, $report);
        }
    }

    /**
     * @param array<string,mixed> $lock
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function verify_no_go_conditions(array $lock, array &$report): void
    {
        $this->require_string_list($lock, 'remaining_no_go_conditions_before_adapter', $report, 7);
    }

    /**
     * @param array<string,mixed> $lock
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function verify_verification_plan(array $lock, ?string $repo_root, array &$report): void
    {
        $plan = $this->require_object($lock, 'future_verification_plan', $report);
        if ($plan === []) {
            return;
        }

        foreach ([
            'future_verification_plan.source_lock_command',
            'future_verification_plan.tokenizer_fixture_requirement',
            'future_verification_plan.search_regression_requirement',
            'future_verification_plan.performance_requirement',
        ] as $path) {
            $this->require_string($lock, $path, $report);
        }

        $doc = $this->require_string($lock, 'future_verification_plan.documentation_file', $report);
        $this->require_existing_repo_file($doc, 'future_verification_plan.documentation_file', $repo_root, $report);
    }

    /**
     * @return array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool}
     */
    private function empty_report(string $path, bool $allow_pending_exact_values): array
    {
        return [
            'path' => $path,
            'valid' => false,
            'mode' => $allow_pending_exact_values ? 'candidate_preflight_pending_exact_values_allowed' : 'strict_source_lock',
            'errors' => [],
            'warnings' => [],
            'pending_exact_values' => [],
            'blocks_adapter' => true,
        ];
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function require_exact_string(array $source, string $path, string $expected, array &$report): void
    {
        $value = $this->value_at_path($source, $path);
        if ($value !== $expected) {
            $report['errors'][] = "{$path} must be {$expected}.";
        }
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function require_string(array $source, string $path, array &$report): string
    {
        $value = $this->value_at_path($source, $path);
        if (!is_string($value) || trim($value) === '') {
            $report['errors'][] = "{$path} must be a non-empty string.";

            return '';
        }

        if ($this->contains_placeholder_text($value)) {
            $report['errors'][] = "{$path} must not contain TODO/TBD placeholder text.";
        }

        return trim($value);
    }

    /**
     * @param array<string,mixed> $source
     */
    private function string_value(array $source, string $path): string
    {
        $value = $this->value_at_path($source, $path);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     * @return array<string,mixed>
     */
    private function require_object(array $source, string $path, array &$report): array
    {
        $value = $this->value_at_path($source, $path);
        if (!is_array($value) || array_is_list($value)) {
            $report['errors'][] = "{$path} must be an object.";

            return [];
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function require_bool(array $source, string $path, bool $expected, array &$report): void
    {
        $value = $this->value_at_path($source, $path);
        if ($value !== $expected) {
            $report['errors'][] = "{$path} must be " . ($expected ? 'true' : 'false') . '.';
        }
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function require_positive_int(array $source, string $path, array &$report): int
    {
        $value = $this->value_at_path($source, $path);
        if (!is_int($value) || $value < 1) {
            $report['errors'][] = "{$path} must be a positive integer.";

            return 0;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function require_exact_string_value(array $source, string $path, bool $allow_pending_exact_values, array &$report): string
    {
        $value = $this->value_at_path($source, $path);
        if ($this->is_pending_exact_value($value)) {
            $this->accept_or_reject_pending_exact_value($path, $allow_pending_exact_values, $report);

            return '';
        }

        if (!is_string($value) || trim($value) === '') {
            $report['errors'][] = "{$path} must be a concrete non-empty string.";

            return '';
        }

        if ($this->contains_placeholder_text($value)) {
            $report['errors'][] = "{$path} must not contain TODO/TBD placeholder text.";
        }

        return trim($value);
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function require_exact_positive_int(array $source, string $path, bool $allow_pending_exact_values, array &$report): int
    {
        $value = $this->value_at_path($source, $path);
        if ($this->is_pending_exact_value($value)) {
            $this->accept_or_reject_pending_exact_value($path, $allow_pending_exact_values, $report);

            return 0;
        }

        if (!is_int($value) || $value < 1) {
            $report['errors'][] = "{$path} must be a concrete positive integer.";

            return 0;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function require_string_list(array $source, string $path, array &$report, int $minimum_count): void
    {
        $value = $this->value_at_path($source, $path);
        if (!is_array($value) || !array_is_list($value) || count($value) < $minimum_count) {
            $report['errors'][] = "{$path} must be a list with at least {$minimum_count} item(s).";

            return;
        }

        foreach ($value as $index => $item) {
            if (!is_string($item) || trim($item) === '' || $this->contains_placeholder_text($item)) {
                $report['errors'][] = "{$path}[{$index}] must be a concrete non-empty string.";
            }
        }
    }

    /**
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function accept_or_reject_pending_exact_value(string $path, bool $allow_pending_exact_values, array &$report): void
    {
        if ($allow_pending_exact_values && isset(self::PENDING_EXACT_VALUE_PATHS[$path])) {
            if (!in_array($path, $report['pending_exact_values'], true)) {
                $report['pending_exact_values'][] = $path;
            }

            return;
        }

        $report['errors'][] = "{$path} must be a concrete source-lock value before adapter work.";
    }

    /**
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function require_http_url(string $value, string $path, array &$report): void
    {
        if ($value === '') {
            return;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($scheme) || !is_string($host) || $host === '' || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            $report['errors'][] = "{$path} must be an HTTP(S) URL.";
        }
    }

    /**
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function require_sha256(string $value, string $path, array &$report): void
    {
        if ($value !== '' && preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            $report['errors'][] = "{$path} must be 64 lowercase hex characters.";
        }
    }

    /**
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_adapter:bool} $report
     */
    private function require_existing_repo_file(string $relative_path, string $path, ?string $repo_root, array &$report): void
    {
        if ($relative_path === '' || $repo_root === null) {
            return;
        }

        if (str_starts_with($relative_path, '/') || str_contains($relative_path, "\0") || str_contains($relative_path, '..')) {
            $report['errors'][] = "{$path} must be a repository-relative file path.";

            return;
        }

        $absolute = rtrim($repo_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_path);
        if (!is_file($absolute)) {
            $report['errors'][] = "{$path} must point to an existing repository file: {$relative_path}";
        }
    }

    private function contains_placeholder_text(string $value): bool
    {
        return preg_match('/\b(TODO|TBD|FIXME|CHANGEME|PLACEHOLDER)\b/i', $value) === 1;
    }

    private function is_pending_exact_value(mixed $value): bool
    {
        return is_array($value)
            && !array_is_list($value)
            && ($value['pending_exact_value'] ?? null) === true
            && is_string($value['required_by'] ?? null)
            && trim((string) $value['required_by']) !== ''
            && is_string($value['description'] ?? null)
            && trim((string) $value['description']) !== '';
    }

    /**
     * @param array<string,mixed> $source
     */
    private function value_at_path(array $source, string $path): mixed
    {
        $value = $source;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
