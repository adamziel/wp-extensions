<?php
declare(strict_types=1);

/**
 * Verifies metadata-only lexical source-candidate locks.
 *
 * Candidate locks are maintainer evidence for future source review. They do
 * not contain raw lexical data and they deliberately keep real imports blocked.
 */
final class Language_FTS_Playground_Lexical_Source_Candidate_Lock_Verifier
{
    public const SCHEMA = 'language-fts-playground-lexical-source-candidate-lock-v1';

    /**
     * @var string[]
     */
    private const REQUIRED_PENDING_PATHS = [
        'source.version.full_commit_sha',
        'source.artifact.sha256',
        'source.artifact.bytes',
        'source.artifact.retrieved_at',
        'source.artifact.archive_member_manifest',
        'license.notice_files_required',
        'license.attribution_subjects',
        'importer.pre_extraction_questions',
        'gates_before_import.lexical_validator',
        'gates_before_import.evaluator',
        'gates_before_import.benchmark',
    ];

    /**
     * @var string[]
     */
    private const REQUIRED_FALSE_BOUNDARIES = [
        'claim_boundaries.bundled_pack_is_comprehensive_oewn',
        'claim_boundaries.source_artifact_downloaded_by_task',
        'claim_boundaries.source_artifact_committed',
        'claim_boundaries.full_source_import_started',
        'claim_boundaries.runtime_pack_generated',
        'claim_boundaries.comprehensive_english_support_claimed',
    ];

    /**
     * @return array{path:string,valid:bool,errors:string[],warnings:string[],pending_before_import:string[],blocks_real_import:bool}
     */
    public function verify_file(string $path, string|null $repo_root = null): array
    {
        $report = $this->empty_report($path);

        if (!is_file($path)) {
            $report['errors'][] = 'Candidate lock file does not exist: ' . $path;

            return $report;
        }

        $json = file_get_contents($path);
        if (!is_string($json)) {
            $report['errors'][] = 'Candidate lock file could not be read: ' . $path;

            return $report;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            $report['errors'][] = 'Candidate lock file must decode to a JSON object: ' . $path;

            return $report;
        }

        return $this->verify($decoded, $path, $repo_root);
    }

    /**
     * @param array<string,mixed> $lock
     * @return array{path:string,valid:bool,errors:string[],warnings:string[],pending_before_import:string[],blocks_real_import:bool}
     */
    public function verify(array $lock, string $label, string|null $repo_root = null): array
    {
        $report = $this->empty_report($label);

        $this->require_exact_string($lock, 'schema', self::SCHEMA, $report);
        $this->require_exact_string($lock, 'lock_kind', 'oewn_exact_source_candidate_lock', $report);
        $this->require_exact_string($lock, 'status', 'candidate_only', $report);
        $this->require_exact_string($lock, 'language_id', 'en', $report);
        $this->require_exact_string($lock, 'data_kind', 'imported_comprehensive', $report);

        $source = $this->required_object($lock, 'source', $report);
        if ($source !== []) {
            $this->require_exact_string($source, 'source.family_id', 'open_english_wordnet_core', $report);
            $this->require_exact_string($source, 'source.name', 'Open English WordNet', $report);
            $this->required_string($source, 'source.why_preferred', $report);

            $upstream = $this->required_object($source, 'source.canonical_upstream', $report);
            if ($upstream !== []) {
                foreach (['source.canonical_upstream.website', 'source.canonical_upstream.repository', 'source.canonical_upstream.release_url'] as $path) {
                    $this->require_http_url($this->required_string($upstream, $path, $report), $path, $report);
                }
            }

            $version = $this->required_object($source, 'source.version', $report);
            if ($version !== []) {
                $this->require_exact_string($version, 'source.version.label', '2025 Edition', $report);
                $this->require_exact_string($version, 'source.version.tag', '2025-edition', $report);
                $this->require_date($this->required_string($version, 'source.version.release_date', $report), 'source.version.release_date', $report);
                $this->required_pending_marker($version, 'source.version.full_commit_sha', $report);
            }

            $artifact = $this->required_object($source, 'source.artifact', $report);
            if ($artifact !== []) {
                $this->require_exact_string($artifact, 'source.artifact.name', 'english-wordnet-2025-json.zip', $report);
                $this->require_http_url($this->required_string($artifact, 'source.artifact.url', $report), 'source.artifact.url', $report);
                $this->require_exact_string($artifact, 'source.artifact.format', 'wordnet-json-zip', $report);
                foreach ([
                    'source.artifact.sha256',
                    'source.artifact.bytes',
                    'source.artifact.retrieved_at',
                    'source.artifact.archive_member_manifest',
                ] as $path) {
                    $this->required_pending_marker($artifact, $path, $report);
                }
            }

            $excluded = $this->required_string_list($source, 'source.excluded_variants', $report);
            foreach (['open_english_wordnet_plus_2025', 'open_english_namenet_2025'] as $variant) {
                if (!in_array($variant, $excluded, true)) {
                    $report['errors'][] = 'source.excluded_variants must include ' . $variant . '.';
                }
            }
        }

        $license = $this->required_object($lock, 'license', $report);
        if ($license !== []) {
            $this->require_exact_string($license, 'license.primary_identifier', 'CC-BY-4.0', $report);
            $source_chain = $this->required_string_list($license, 'license.source_chain_identifiers', $report);
            foreach (['WordNet-License-Princeton', 'CC-BY-4.0'] as $identifier) {
                if (!in_array($identifier, $source_chain, true)) {
                    $report['errors'][] = 'license.source_chain_identifiers must include ' . $identifier . '.';
                }
            }
            $notice_files = $this->required_string_list($license, 'license.notice_files_required', $report);
            foreach (['LICENSE.oewn.txt', 'WNDB_License.txt'] as $notice_file) {
                if (!in_array($notice_file, $notice_files, true)) {
                    $report['errors'][] = 'license.notice_files_required must include ' . $notice_file . '.';
                }
            }
            $attribution_subjects = $this->required_string_list($license, 'license.attribution_subjects', $report);
            foreach (['Princeton WordNet', 'Open English WordNet Team'] as $subject) {
                if (!in_array($subject, $attribution_subjects, true)) {
                    $report['errors'][] = 'license.attribution_subjects must include ' . $subject . '.';
                }
            }
            $this->required_string_list($license, 'license.open_questions', $report);
        }

        $importer = $this->required_object($lock, 'importer', $report);
        if ($importer !== []) {
            $tool = $this->required_string($importer, 'importer.tool', $report);
            $this->require_existing_repo_file($tool, 'importer.tool', $repo_root, $report);
            $this->require_exact_string($importer, 'importer.format', 'wordnet-json', $report);
            if (($importer['requires_zip_extraction'] ?? null) !== true) {
                $report['errors'][] = 'importer.requires_zip_extraction must be true for the OEWN JSON zip candidate.';
            }
            $command = $this->required_string($importer, 'importer.command_template', $report);
            foreach (['import-lexical-source.php', 'wordnet-json', '--language=en', '--data-kind=imported_comprehensive'] as $needle) {
                if ($command !== '' && !str_contains($command, $needle)) {
                    $report['errors'][] = 'importer.command_template must include ' . $needle . '.';
                }
            }
            $this->required_string_list($importer, 'importer.pre_extraction_questions', $report);
        }

        $gates = $this->required_object($lock, 'gates_before_import', $report);
        if ($gates !== []) {
            foreach ([
                'gates_before_import.lexical_validator' => 'validate-lexical-packs.php',
                'gates_before_import.evaluator' => 'evaluate-lexical-pack.php',
                'gates_before_import.benchmark' => 'search-benchmark-counters.php',
            ] as $path => $needle) {
                $command = $this->required_string($gates, $path, $report);
                if ($command !== '' && !str_contains($command, $needle)) {
                    $report['errors'][] = $path . ' must include ' . $needle . '.';
                }
            }

            $caps = $this->required_object($gates, 'gates_before_import.runtime_fanout_caps', $report);
            if ($caps !== []) {
                foreach ([
                    'gates_before_import.runtime_fanout_caps.max_synset_size',
                    'gates_before_import.runtime_fanout_caps.max_expansions_per_term',
                    'gates_before_import.runtime_fanout_caps.max_phrase_expansions_per_source',
                ] as $path) {
                    $this->required_positive_int($caps, $path, $report);
                }
            }
            $this->required_string_list($gates, 'gates_before_import.determinism', $report);
        }

        $pending = $this->required_string_list($lock, 'pending_before_import', $report);
        foreach (self::REQUIRED_PENDING_PATHS as $path) {
            if (!in_array($path, $pending, true)) {
                $report['errors'][] = 'pending_before_import must include ' . $path . '.';
            }
        }
        $report['pending_before_import'] = $pending;

        foreach (self::REQUIRED_FALSE_BOUNDARIES as $path) {
            if ($this->value_at_path($lock, $path) !== false) {
                $report['errors'][] = $path . ' must be false.';
            }
        }

        $no_go = $this->required_string_list($lock, 'real_import_no_go_conditions', $report);
        foreach (['download', 'runtime lexical packs', 'comprehensive English lexical support'] as $needle) {
            if (!$this->list_contains($no_go, $needle)) {
                $report['errors'][] = 'real_import_no_go_conditions must mention ' . $needle . '.';
            }
        }

        $sources = $this->required_list_of_objects($lock, 'evidence_sources', $report);
        foreach ($sources as $index => $source_evidence) {
            $prefix = 'evidence_sources.' . $index;
            $this->required_string($source_evidence, $prefix . '.title', $report);
            $this->require_http_url($this->required_string($source_evidence, $prefix . '.url', $report), $prefix . '.url', $report);
            $this->required_string($source_evidence, $prefix . '.used_for', $report);
        }

        $report['blocks_real_import'] = $report['pending_before_import'] !== []
            && $this->value_at_path($lock, 'claim_boundaries.full_source_import_started') === false
            && $this->value_at_path($lock, 'claim_boundaries.runtime_pack_generated') === false;
        $report['valid'] = $report['errors'] === [];

        return $report;
    }

    /**
     * @return array{path:string,valid:bool,errors:string[],warnings:string[],pending_before_import:string[],blocks_real_import:bool}
     */
    private function empty_report(string $path): array
    {
        return [
            'path' => $path,
            'valid' => false,
            'errors' => [],
            'warnings' => [],
            'pending_before_import' => [],
            'blocks_real_import' => true,
        ];
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,valid:bool,errors:string[],warnings:string[],pending_before_import:string[],blocks_real_import:bool} $report
     */
    private function required_string(array $source, string $path, array &$report): string
    {
        $value = $this->value_at_path($source, $path);
        if (!is_string($value) || trim($value) === '') {
            $report['errors'][] = $path . ' is required.';

            return '';
        }

        if (preg_match('/\b(TODO|TBD|FIXME|CHANGEME|PLACEHOLDER)\b/i', $value) === 1) {
            $report['errors'][] = $path . ' must not contain placeholder text.';
        }

        return trim($value);
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,valid:bool,errors:string[],warnings:string[],pending_before_import:string[],blocks_real_import:bool} $report
     */
    private function require_exact_string(array $source, string $path, string $expected, array &$report): void
    {
        $value = $this->required_string($source, $path, $report);
        if ($value !== '' && $value !== $expected) {
            $report['errors'][] = $path . ' must be ' . $expected . '.';
        }
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,valid:bool,errors:string[],warnings:string[],pending_before_import:string[],blocks_real_import:bool} $report
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
     * @param array{path:string,valid:bool,errors:string[],warnings:string[],pending_before_import:string[],blocks_real_import:bool} $report
     * @return string[]
     */
    private function required_string_list(array $source, string $path, array &$report): array
    {
        $value = $this->value_at_path($source, $path);
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            $report['errors'][] = $path . ' must be a non-empty array of strings.';

            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                $report['errors'][] = $path . ' must contain only non-empty strings.';
                continue;
            }
            $strings[] = trim($item);
        }

        return $strings;
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,valid:bool,errors:string[],warnings:string[],pending_before_import:string[],blocks_real_import:bool} $report
     * @return array<int,array<string,mixed>>
     */
    private function required_list_of_objects(array $source, string $path, array &$report): array
    {
        $value = $this->value_at_path($source, $path);
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            $report['errors'][] = $path . ' must be a non-empty array of objects.';

            return [];
        }

        $objects = [];
        foreach ($value as $item) {
            if (!is_array($item) || array_is_list($item)) {
                $report['errors'][] = $path . ' must contain only objects.';
                continue;
            }
            $objects[] = $item;
        }

        return $objects;
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,valid:bool,errors:string[],warnings:string[],pending_before_import:string[],blocks_real_import:bool} $report
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
     * @param array{path:string,valid:bool,errors:string[],warnings:string[],pending_before_import:string[],blocks_real_import:bool} $report
     */
    private function required_pending_marker(array $source, string $path, array &$report): void
    {
        $value = $this->value_at_path($source, $path);
        if (
            !is_array($value)
            || array_is_list($value)
            || ($value['pending_before_import'] ?? null) !== true
            || !is_string($value['required_by'] ?? null)
            || trim((string) $value['required_by']) === ''
            || !is_string($value['description'] ?? null)
            || trim((string) $value['description']) === ''
        ) {
            $report['errors'][] = $path . ' must be marked pending_before_import with required_by and description.';
        }
    }

    /**
     * @param array{path:string,valid:bool,errors:string[],warnings:string[],pending_before_import:string[],blocks_real_import:bool} $report
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
     * @param array{path:string,valid:bool,errors:string[],warnings:string[],pending_before_import:string[],blocks_real_import:bool} $report
     */
    private function require_date(string $value, string $path, array &$report): void
    {
        if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            $report['errors'][] = $path . ' must be YYYY-MM-DD.';
        }
    }

    /**
     * @param array{path:string,valid:bool,errors:string[],warnings:string[],pending_before_import:string[],blocks_real_import:bool} $report
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
     * @param string[] $items
     */
    private function list_contains(array $items, string $needle): bool
    {
        foreach ($items as $item) {
            if (stripos($item, $needle) !== false) {
                return true;
            }
        }

        return false;
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
