<?php
declare(strict_types=1);

/**
 * Verifies repository-side lexical source-lock and preflight artifacts.
 *
 * This intentionally stays outside the normal plugin bootstrap. Source locks
 * are maintainer evidence for build-time imports, not runtime search data.
 */
final class Language_FTS_Playground_Lexical_Source_Lock_Verifier
{
    public const SCHEMA = 'language-fts-playground-lexical-source-lock-v1';

    /**
     * @var array<string,bool>
     */
    private const PENDING_EXACT_VALUE_PATHS = [
        'source.version' => true,
        'source.artifact.name' => true,
        'source.artifact.url' => true,
        'source.artifact.sha256' => true,
        'source.artifact.bytes' => true,
    ];

    /**
     * @return array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_real_import:bool}
     */
    public function verify_file(string $path, bool $allow_pending_exact_values = false, string|null $repo_root = null): array
    {
        $report = $this->empty_report($path, $allow_pending_exact_values);
        if (!is_file($path)) {
            $report['valid'] = false;
            $report['errors'][] = 'Source-lock file does not exist: ' . $path;

            return $report;
        }

        $json = file_get_contents($path);
        if (!is_string($json)) {
            $report['valid'] = false;
            $report['errors'][] = 'Source-lock file could not be read: ' . $path;

            return $report;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            $report['valid'] = false;
            $report['errors'][] = 'Source-lock file must decode to a JSON object: ' . $path;

            return $report;
        }

        return $this->verify($decoded, $path, $allow_pending_exact_values, $repo_root);
    }

    /**
     * @param array<string,mixed> $lock
     * @return array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_real_import:bool}
     */
    public function verify(array $lock, string $label, bool $allow_pending_exact_values = false, string|null $repo_root = null): array
    {
        $report = $this->empty_report($label, $allow_pending_exact_values);

        $schema = $this->required_string($lock, 'schema', $report);
        if ($schema !== '' && $schema !== self::SCHEMA) {
            $report['errors'][] = 'schema must be ' . self::SCHEMA . '.';
        }

        $language_id = $this->required_string($lock, 'language_id', $report);
        if ($language_id !== '' && $language_id !== 'en') {
            $report['errors'][] = 'language_id must be en for the OEWN comprehensive preflight.';
        }

        $data_kind = $this->required_string($lock, 'data_kind', $report);
        if ($data_kind !== '' && $data_kind !== 'imported_comprehensive') {
            $report['errors'][] = 'data_kind must be imported_comprehensive.';
        }

        $source = $this->required_object($lock, 'source', $report);
        if ($source !== []) {
            $source_name = $this->required_string($source, 'source.name', $report);
            if ($source_name !== '' && $source_name !== 'Open English WordNet') {
                $report['errors'][] = 'source.name must be Open English WordNet.';
            }

            $source_url = $this->required_string($source, 'source.url', $report);
            $this->require_http_url($source_url, 'source.url', $report);

            $source_format = $this->required_string($source, 'source.format', $report);
            if ($source_format !== '' && $source_format !== 'wordnet-json') {
                $report['errors'][] = 'source.format must be wordnet-json.';
            }

            $this->required_exact_string($source, 'source.version', $allow_pending_exact_values, $report);
            $artifact = $this->required_object($source, 'source.artifact', $report);
            if ($artifact !== []) {
                $this->required_exact_string($artifact, 'source.artifact.name', $allow_pending_exact_values, $report);
                $artifact_url = $this->required_exact_string($artifact, 'source.artifact.url', $allow_pending_exact_values, $report);
                $this->require_http_url($artifact_url, 'source.artifact.url', $report);
                $artifact_sha256 = $this->required_exact_string($artifact, 'source.artifact.sha256', $allow_pending_exact_values, $report);
                $this->require_sha256($artifact_sha256, 'source.artifact.sha256', $report);
                $this->required_exact_positive_int($artifact, 'source.artifact.bytes', $allow_pending_exact_values, $report);
            }
        }

        $license = $this->required_object($lock, 'license', $report);
        if ($license !== []) {
            $this->required_string($license, 'license.identifier', $report);
            $this->required_string($license, 'license.name', $report);
            $license_url = $this->required_string($license, 'license.url', $report);
            $this->require_http_url($license_url, 'license.url', $report);
            $text_url = $this->required_string($license, 'license.text_url', $report);
            $this->require_http_url($text_url, 'license.text_url', $report);
            $this->required_string($license, 'license.notice_file', $report);
            $this->required_string($license, 'license.attribution', $report);
            if (($license['notice_text_required'] ?? null) !== true) {
                $report['errors'][] = 'license.notice_text_required must be true.';
            }
        }

        $provenance = $this->required_object($lock, 'provenance', $report);
        $provenance_id = '';
        if ($provenance !== []) {
            $provenance_id = $this->required_string($provenance, 'provenance.id', $report);
            if ($provenance_id !== '' && preg_match('/^[a-z0-9][a-z0-9._:-]*$/', $provenance_id) !== 1) {
                $report['errors'][] = 'provenance.id must be a stable lowercase provenance key.';
            }
            $this->required_string($provenance, 'provenance.description', $report);
        }

        $normalization = $this->required_object($lock, 'normalization', $report);
        if ($normalization !== []) {
            $profile_id = $this->required_string($normalization, 'normalization.profile_id', $report);
            if ($profile_id !== '' && $profile_id !== 'en') {
                $report['errors'][] = 'normalization.profile_id must be en.';
            }
            $profile_file = $this->required_string($normalization, 'normalization.profile_file', $report);
            $this->require_existing_repo_file($profile_file, 'normalization.profile_file', $repo_root, $report);
            $this->required_string($normalization, 'normalization.profile_version', $report);
        }

        $importer = $this->required_object($lock, 'importer', $report);
        if ($importer !== []) {
            $this->required_string($importer, 'importer.tool', $report);
            $format = $this->required_string($importer, 'importer.format', $report);
            if ($format !== '' && $format !== 'wordnet-json') {
                $report['errors'][] = 'importer.format must be wordnet-json.';
            }
            $this->required_string($importer, 'importer.version', $report);
            $command = $this->required_string($importer, 'importer.command', $report);
            $this->require_command_contains($command, 'importer.command', 'import-lexical-source.php', $report);
            $this->require_command_contains($command, 'importer.command', 'wordnet-json', $report);
            $this->require_command_contains($command, 'importer.command', '--language=en', $report);
            $this->require_command_contains($command, 'importer.command', '--data-kind=imported_comprehensive', $report);
            if ($provenance_id !== '') {
                $this->require_command_contains($command, 'importer.command', '--provenance=' . $provenance_id, $report);
            }
        }

        $fanout_caps = $this->required_object($lock, 'fanout_caps', $report);
        if ($fanout_caps !== []) {
            $this->required_positive_int($fanout_caps, 'fanout_caps.max_synset_size', $report);
            $this->required_positive_int($fanout_caps, 'fanout_caps.max_expansions_per_term', $report);
            $this->required_positive_int($fanout_caps, 'fanout_caps.max_phrase_expansions_per_source', $report);
        }

        $evaluator = $this->required_object($lock, 'evaluator', $report);
        if ($evaluator !== []) {
            $fixture_path = $this->required_string($evaluator, 'evaluator.fixture_path', $report);
            $this->require_existing_repo_file($fixture_path, 'evaluator.fixture_path', $repo_root, $report);
            $command = $this->required_string($evaluator, 'evaluator.command', $report);
            $this->require_command_contains($command, 'evaluator.command', 'evaluate-lexical-pack.php', $report);
        }

        $benchmark = $this->required_object($lock, 'benchmark', $report);
        if ($benchmark !== []) {
            $command = $this->required_string($benchmark, 'benchmark.command', $report);
            $this->require_command_contains($command, 'benchmark.command', 'search-benchmark-counters.php', $report);

            $budget = $this->required_object($benchmark, 'benchmark.budget', $report);
            if ($budget !== []) {
                $this->required_string($budget, 'benchmark.budget.suite', $report);
                $this->required_positive_int($budget, 'benchmark.budget.max_languages', $report);
                $this->required_positive_int($budget, 'benchmark.budget.max_scenarios', $report);
                $this->required_positive_int($budget, 'benchmark.budget.max_duration_seconds', $report);
                if (($budget['fail_on_gate'] ?? null) !== true) {
                    $report['errors'][] = 'benchmark.budget.fail_on_gate must be true.';
                }
            }
        }

        $claim_boundaries = $this->required_object($lock, 'claim_boundaries', $report);
        if ($claim_boundaries !== []) {
            foreach ([
                'claim_boundaries.bundled_pack_is_comprehensive_oewn',
                'claim_boundaries.full_source_import_started',
                'claim_boundaries.runtime_pack_generated',
                'claim_boundaries.comprehensive_english_support_claimed',
            ] as $path) {
                if ($this->value_at_path($claim_boundaries, $path) !== false) {
                    $report['errors'][] = $path . ' must be false.';
                }
            }
        }

        $report['blocks_real_import'] = $report['pending_exact_values'] !== []
            || $this->value_at_path($lock, 'claim_boundaries.full_source_import_started') === false
            || $this->value_at_path($lock, 'claim_boundaries.runtime_pack_generated') === false;
        $report['valid'] = $report['errors'] === [];

        return $report;
    }

    /**
     * @return array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_real_import:bool}
     */
    private function empty_report(string $path, bool $allow_pending_exact_values): array
    {
        return [
            'path' => $path,
            'valid' => false,
            'mode' => $allow_pending_exact_values ? 'preflight_pending_exact_values_allowed' : 'strict_source_lock',
            'errors' => [],
            'warnings' => [],
            'pending_exact_values' => [],
            'blocks_real_import' => true,
        ];
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_real_import:bool} $report
     */
    private function required_string(array $source, string $path, array &$report): string
    {
        $value = $this->value_at_path($source, $path);
        if (!is_string($value) || trim($value) === '') {
            $report['errors'][] = $path . ' is required.';

            return '';
        }

        if ($this->contains_placeholder_text($value)) {
            $report['errors'][] = $path . ' must not contain TODO/TBD placeholder text.';
        }

        return trim($value);
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_real_import:bool} $report
     * @return array<string,mixed>
     */
    private function required_object(array $source, string $path, array &$report): array
    {
        $value = $this->value_at_path($source, $path);
        if (!is_array($value) || array_is_list($value)) {
            $report['errors'][] = $path . ' must be an object.';

            return [];
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_real_import:bool} $report
     */
    private function required_positive_int(array $source, string $path, array &$report): int
    {
        $value = $this->value_at_path($source, $path);
        if (!is_int($value) || $value < 1) {
            $report['errors'][] = $path . ' must be a positive integer.';

            return 0;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_real_import:bool} $report
     */
    private function required_exact_string(array $source, string $path, bool $allow_pending_exact_values, array &$report): string
    {
        $value = $this->value_at_path($source, $path);
        if ($this->is_pending_exact_value($value)) {
            return $this->accept_or_reject_pending_exact_value($path, $allow_pending_exact_values, $report) ? '' : '';
        }

        if (!is_string($value) || trim($value) === '') {
            $report['errors'][] = $path . ' is required.';

            return '';
        }

        if ($this->contains_placeholder_text($value)) {
            $report['errors'][] = $path . ' must be concrete and must not contain placeholder text.';
        }

        return trim($value);
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_real_import:bool} $report
     */
    private function required_exact_positive_int(array $source, string $path, bool $allow_pending_exact_values, array &$report): int
    {
        $value = $this->value_at_path($source, $path);
        if ($this->is_pending_exact_value($value)) {
            $this->accept_or_reject_pending_exact_value($path, $allow_pending_exact_values, $report);

            return 0;
        }

        if (!is_int($value) || $value < 1) {
            $report['errors'][] = $path . ' must be a positive integer.';

            return 0;
        }

        return $value;
    }

    /**
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_real_import:bool} $report
     */
    private function accept_or_reject_pending_exact_value(string $path, bool $allow_pending_exact_values, array &$report): bool
    {
        if ($allow_pending_exact_values && isset(self::PENDING_EXACT_VALUE_PATHS[$path])) {
            if (!in_array($path, $report['pending_exact_values'], true)) {
                $report['pending_exact_values'][] = $path;
            }

            return true;
        }

        $report['errors'][] = $path . ' must be a concrete source-lock value before import.';

        return false;
    }

    /**
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_real_import:bool} $report
     */
    private function require_http_url(string $value, string $path, array &$report): void
    {
        if ($value === '') {
            return;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($scheme) || !is_string($host) || $host === '' || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            $report['errors'][] = $path . ' must be an HTTP(S) URL.';
        }
    }

    /**
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_real_import:bool} $report
     */
    private function require_sha256(string $value, string $path, array &$report): void
    {
        if ($value !== '' && preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            $report['errors'][] = $path . ' must be 64 lowercase hex characters.';
        }
    }

    /**
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_real_import:bool} $report
     */
    private function require_existing_repo_file(string $relative_path, string $path, string|null $repo_root, array &$report): void
    {
        if ($relative_path === '' || $repo_root === null) {
            return;
        }

        if (str_starts_with($relative_path, '/') || str_contains($relative_path, "\0") || str_contains($relative_path, '..')) {
            $report['errors'][] = $path . ' must be a repository-relative file path.';

            return;
        }

        $absolute = rtrim($repo_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_path);
        if (!is_file($absolute)) {
            $report['errors'][] = $path . ' must point to an existing repository file: ' . $relative_path;
        }
    }

    /**
     * @param array{path:string,valid:bool,mode:string,errors:string[],warnings:string[],pending_exact_values:string[],blocks_real_import:bool} $report
     */
    private function require_command_contains(string $command, string $path, string $needle, array &$report): void
    {
        if ($command !== '' && !str_contains($command, $needle)) {
            $report['errors'][] = $path . ' must include ' . $needle . '.';
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
            && trim((string) $value['required_by']) !== '';
    }

    /**
     * @param array<string,mixed> $source
     */
    private function value_at_path(array $source, string $path): mixed
    {
        $segments = explode('.', $path);

        for ($offset = 0, $count = count($segments); $offset < $count; $offset++) {
            $value = $source;
            $found = true;
            for ($index = $offset; $index < $count; $index++) {
                $segment = $segments[$index];
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $found = false;
                    break;
                }
                $value = $value[$segment];
            }

            if ($found) {
                return $value;
            }
        }

        return null;
    }
}
