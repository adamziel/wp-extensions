<?php
declare(strict_types=1);

/**
 * Validates lexical resource packs and reports deterministic status counts.
 *
 * Query-time analysis intentionally does not use this class. It parses pack
 * metadata and resource files for CLI/admin/status surfaces where maintainers
 * need complete provenance, row counts, and fanout warnings before shipping a
 * seed or imported comprehensive pack.
 */
final class Language_FTS_Playground_Lexical_Pack_Validator
{
    public const METADATA_SCHEMA_V2 = 'language-fts-playground-pack-metadata-v2';
    public const DEFAULT_MAX_SYNSET_SIZE = Language_FTS_Playground_Lexical_Profile_Repository::DEFAULT_MAX_SYNSET_SIZE;
    public const DEFAULT_MAX_EXPANSIONS_PER_TERM = Language_FTS_Playground_Lexical_Profile_Repository::DEFAULT_MAX_EXPANSIONS_PER_TERM;
    public const DEFAULT_MAX_PHRASE_EXPANSIONS_PER_SOURCE = Language_FTS_Playground_Lexical_Profile_Repository::DEFAULT_MAX_PHRASE_EXPANSIONS_PER_SOURCE;

    private string $resource_root;
    private int $max_synset_size;
    private int $max_expansions_per_term;
    private int $max_phrase_expansions_per_source;

    public function __construct(
        string|null $resource_root = null,
        int $max_synset_size = self::DEFAULT_MAX_SYNSET_SIZE,
        int $max_expansions_per_term = self::DEFAULT_MAX_EXPANSIONS_PER_TERM,
        int $max_phrase_expansions_per_source = self::DEFAULT_MAX_PHRASE_EXPANSIONS_PER_SOURCE
    ) {
        $this->resource_root = Language_FTS_Playground_Lexical_Profile_Repository::normalize_resource_root(
            $resource_root ?? Language_FTS_Playground_Lexical_Profile_Repository::default_resource_root()
        );
        $this->max_synset_size = max(1, $max_synset_size);
        $this->max_expansions_per_term = max(1, $max_expansions_per_term);
        $this->max_phrase_expansions_per_source = max(1, $max_phrase_expansions_per_source);
    }

    /**
     * @return array{resource_root:string,thresholds:array{max_synset_size:int,max_expansions_per_term:int,max_phrase_expansions_per_source:int},valid:bool,warnings:string[],languages:array<int,array<string,mixed>>}
     */
    public function validate_all(): array
    {
        $report = [
            'resource_root' => $this->resource_root,
            'thresholds' => [
                'max_synset_size' => $this->max_synset_size,
                'max_expansions_per_term' => $this->max_expansions_per_term,
                'max_phrase_expansions_per_source' => $this->max_phrase_expansions_per_source,
            ],
            'valid' => true,
            'warnings' => [],
            'languages' => [],
        ];

        if (!is_dir($this->resource_root)) {
            $report['valid'] = false;
            $report['warnings'][] = 'Lexical resource root does not exist: ' . $this->resource_root;

            return $report;
        }

        $entries = scandir($this->resource_root);
        if ($entries === false) {
            $report['valid'] = false;
            $report['warnings'][] = 'Could not read lexical resource root: ' . $this->resource_root;

            return $report;
        }

        $languages = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $directory = $this->resource_root . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($directory)) {
                continue;
            }

            $profile_file = $directory . DIRECTORY_SEPARATOR . 'profile.php';
            $pack_file = $directory . DIRECTORY_SEPARATOR . 'pack.php';
            if (!is_file($profile_file) && !is_file($pack_file)) {
                continue;
            }

            $languages[] = $this->validate_language_directory($entry, $directory);
        }

        usort(
            $languages,
            static fn(array $a, array $b): int => ((int) ($a['order'] ?? 1000) <=> (int) ($b['order'] ?? 1000))
                ?: strcmp((string) ($a['language_id'] ?? ''), (string) ($b['language_id'] ?? ''))
        );

        foreach ($languages as $language) {
            unset($language['order']);
            if (empty($language['valid'])) {
                $report['valid'] = false;
            }
            $report['languages'][] = $language;
        }

        if ($report['languages'] === []) {
            $report['valid'] = false;
            $report['warnings'][] = 'No lexical language packs were found in: ' . $this->resource_root;
        }

        return $report;
    }

    /**
     * @return array<string,mixed>
     */
    private function validate_language_directory(string $directory_id, string $directory): array
    {
        $warnings = [];
        $status = [
            'language_id' => $directory_id,
            'label' => $directory_id,
            'tokenizer' => Language_FTS_Playground_Unicode_Words_Tokenizer::default_contract(),
            'metadata' => $this->empty_metadata(),
            'metadata_valid' => false,
            'runtime_files_exist' => false,
            'missing_files' => [],
            'resource_digests' => [],
            'counts' => [
                'stopwords' => 0,
                'lexeme_rows' => 0,
                'pairwise_synonym_rows' => 0,
                'pairwise_synonym_expansions' => 0,
                'synset_rows' => 0,
                'concept_expansions' => 0,
                'phrase_synonym_rows' => 0,
                'phrase_synonym_expansions' => 0,
                'term_rule_rows' => 0,
                'protected_term_rows' => 0,
                'tokenizer_rows' => 0,
                'tokenizer_resource_bytes' => 0,
                'tokenizer_max_input_run_bytes' => 0,
                'tokenizer_max_output_token_bytes' => 0,
            ],
            'max_synset_size' => 0,
            'max_expansion_fanout' => 0,
            'max_phrase_expansion_fanout' => 0,
            'warnings' => [],
            'valid' => true,
            'order' => 1000,
        ];

        $profile_file = $directory . DIRECTORY_SEPARATOR . 'profile.php';
        $profile = null;
        $resources = [];
        $normalization_folds = [];

        if (!is_file($profile_file)) {
            $warnings[] = 'Missing language profile file: ' . $profile_file;
        } else {
            $profile = $this->read_php_array($profile_file, 'Language profile', $warnings);
            if (is_array($profile)) {
                $profile_id = $this->required_string($profile, 'id', $profile_file, $warnings);
                if ($profile_id !== '') {
                    $status['language_id'] = $profile_id;
                    if ($this->normalize_language_id($profile_id) !== $profile_id) {
                        $warnings[] = 'Language profile id must contain only lowercase letters, numbers, and hyphens in ' . $profile_file;
                    }
                    if ($profile_id !== $directory_id) {
                        $warnings[] = 'Language profile id must match its directory in ' . $profile_file;
                    }
                }

                $label = $this->required_string($profile, 'label', $profile_file, $warnings);
                if ($label !== '') {
                    $status['label'] = $label;
                }

                $order = $profile['order'] ?? 1000;
                if (!is_int($order)) {
                    $warnings[] = 'Language profile order must be an integer in ' . $profile_file;
                } else {
                    $status['order'] = $order;
                }

                if (isset($profile['normalization']['fold'])) {
                    $normalization_folds = $this->validate_string_map($profile['normalization']['fold'], 'normalization.fold', $profile_file, $warnings);
                }

                if (isset($profile['language_signals'])) {
                    $this->validate_string_list($profile['language_signals'], 'language_signals', $profile_file, $warnings);
                }

                $status['tokenizer'] = $this->validate_tokenizer_contract($profile['tokenizer'] ?? null, $profile_file, $warnings);

                if (!isset($profile['resources']) || !is_array($profile['resources'])) {
                    $warnings[] = 'Language profile resources must be an array in ' . $profile_file;
                } else {
                    $resources = $profile['resources'];
                }
            }
        }

        $resource_paths = $this->resolve_profile_resources($directory, $profile_file, $resources, $warnings);
        $tokenizer_resource_paths = $this->resolve_tokenizer_resources($directory, $profile_file, (array) $status['tokenizer'], $warnings);

        $metadata = $this->validate_pack_metadata($directory, (string) $status['language_id'], $profile_file, $resource_paths, (array) $status['tokenizer'], $warnings);
        $status['metadata'] = $metadata['metadata'];
        $status['metadata_valid'] = $metadata['valid'];
        $status['runtime_files_exist'] = $metadata['runtime_files_exist'];
        $status['missing_files'] = $metadata['missing_files'];

        if ($metadata['metadata']['files'] !== []) {
            $status['metadata_valid'] = $status['metadata_valid'] && $this->validate_pack_files_include_profile_resources(
                $metadata['metadata']['files'],
                array_merge($resources, (array) ($status['tokenizer']['resources'] ?? [])),
                $profile_file,
                $directory . DIRECTORY_SEPARATOR . 'pack.php',
                $warnings
            );
        }
        $status['resource_digests'] = array_merge(
            $this->resource_digests($resource_paths, ''),
            $this->resource_digests($tokenizer_resource_paths, 'tokenizer.')
        );
        $row_provenance_allow_list = $this->row_provenance_allow_list($metadata['metadata']);
        $single_token_expansion_targets = [];
        $phrase_expansion_targets = [];

        if (isset($resource_paths['stopwords'])) {
            $status['counts']['stopwords'] = $this->validate_stopwords($resource_paths['stopwords'], $warnings, $normalization_folds);
        }
        if (isset($resource_paths['lexemes'])) {
            $status['counts']['lexeme_rows'] = $this->validate_lexemes($resource_paths['lexemes'], $warnings, $normalization_folds, $row_provenance_allow_list);
        }
        if (isset($resource_paths['synonyms'])) {
            $pairwise = $this->validate_pairwise_synonyms($resource_paths['synonyms'], $warnings, $single_token_expansion_targets, $normalization_folds, $row_provenance_allow_list);
            $status['counts']['pairwise_synonym_rows'] = $pairwise['rows'];
            $status['counts']['pairwise_synonym_expansions'] = $pairwise['expansions'];
        }
        if (isset($resource_paths['synsets'])) {
            $synsets = $this->validate_synsets($resource_paths['synsets'], $warnings, $single_token_expansion_targets, $normalization_folds, $row_provenance_allow_list);
            $status['counts']['synset_rows'] = $synsets['rows'];
            $status['counts']['concept_expansions'] = $synsets['expansions'];
            $status['max_synset_size'] = $synsets['max_synset_size'];
        }
        if (isset($resource_paths['synonym_phrases'])) {
            $phrase_synonyms = $this->validate_synonym_phrases($resource_paths['synonym_phrases'], $warnings, $phrase_expansion_targets, $normalization_folds, $row_provenance_allow_list);
            $status['counts']['phrase_synonym_rows'] = $phrase_synonyms['rows'];
            $status['counts']['phrase_synonym_expansions'] = $phrase_synonyms['expansions'];
        }
        if (isset($resource_paths['term_rules'])) {
            $status['counts']['term_rule_rows'] = $this->validate_term_rules($resource_paths['term_rules'], $warnings, $normalization_folds, $row_provenance_allow_list);
        }
        if (isset($resource_paths['protected_terms'])) {
            $status['counts']['protected_term_rows'] = $this->validate_protected_terms($resource_paths['protected_terms'], $warnings, $normalization_folds);
        }
        if (isset($tokenizer_resource_paths['dictionary'])) {
            $tokenizer_stats = $this->validate_synthetic_tokenizer_dictionary($tokenizer_resource_paths['dictionary'], $warnings);
            $status['counts']['tokenizer_rows'] = $tokenizer_stats['rows'];
            $status['counts']['tokenizer_resource_bytes'] = $tokenizer_stats['resource_bytes'];
            $status['counts']['tokenizer_max_input_run_bytes'] = $tokenizer_stats['max_input_run_bytes'];
            $status['counts']['tokenizer_max_output_token_bytes'] = $tokenizer_stats['max_output_token_bytes'];
        }

        $status['max_expansion_fanout'] = $this->max_expansion_fanout($single_token_expansion_targets);
        if ($status['max_expansion_fanout'] > $this->max_expansions_per_term) {
            $warnings[] = sprintf(
                'Maximum expansion fanout %d exceeds threshold %d.',
                $status['max_expansion_fanout'],
                $this->max_expansions_per_term
            );
        }
        $status['max_phrase_expansion_fanout'] = $this->max_expansion_fanout($phrase_expansion_targets);
        if ($status['max_phrase_expansion_fanout'] > $this->max_phrase_expansions_per_source) {
            $warnings[] = sprintf(
                'Maximum phrase expansion fanout %d exceeds threshold %d.',
                $status['max_phrase_expansion_fanout'],
                $this->max_phrase_expansions_per_source
            );
        }

        $status['warnings'] = array_values(array_unique($warnings));
        $status['valid'] = $status['warnings'] === [];

        return $status;
    }

    /**
     * @return array<string,mixed>
     */
    private function empty_metadata(): array
    {
        return [
            'metadata_schema' => '',
            'language_id' => '',
            'pack_version' => '',
            'pack_date' => '',
            'source_name' => '',
            'source_url' => '',
            'license_name' => '',
            'attribution_text' => '',
            'provenance' => '',
            'files' => [],
            'data_kind' => '',
            'source' => [],
            'license' => [],
            'provenance_ids' => [],
            'normalization' => [],
            'importer' => [],
            'runtime_files' => [],
            'runtime_digest_status' => 'not_declared',
        ];
    }

    /**
     * @return array{metadata:array<string,mixed>,valid:bool,runtime_files_exist:bool,missing_files:string[]}
     */
    private function validate_pack_metadata(
        string $directory,
        string $expected_language,
        string $profile_file,
        array $resource_paths,
        array $tokenizer,
        array &$warnings
    ): array {
        $path = $directory . DIRECTORY_SEPARATOR . 'pack.php';
        $metadata = $this->empty_metadata();
        $missing_files = [];
        $valid = true;
        $runtime_files_exist = false;
        $metadata_warning_count = count($warnings);

        if (!is_file($path)) {
            $warnings[] = 'Missing language pack metadata file: ' . $path;

            return [
                'metadata' => $metadata,
                'valid' => false,
                'runtime_files_exist' => false,
                'missing_files' => [],
            ];
        }

        $raw = $this->read_php_array($path, 'Language pack metadata', $warnings);
        if (!is_array($raw)) {
            return [
                'metadata' => $metadata,
                'valid' => false,
                'runtime_files_exist' => false,
                'missing_files' => [],
            ];
        }

        if (array_key_exists('metadata_schema', $raw)) {
            $schema = $this->required_string($raw, 'metadata_schema', $path, $warnings);
            if ($schema !== '') {
                $metadata['metadata_schema'] = $schema;
                if ($schema !== self::METADATA_SCHEMA_V2) {
                    $warnings[] = 'Language pack metadata metadata_schema is not supported in ' . $path;
                }
            }
        }

        foreach (['language_id', 'pack_version', 'pack_date', 'source_name', 'source_url', 'license_name', 'attribution_text', 'provenance', 'data_kind'] as $key) {
            $value = $this->required_string($raw, $key, $path, $warnings);
            if ($value === '') {
                $valid = false;
                continue;
            }
            $metadata[$key] = $value;
        }

        if ($metadata['language_id'] !== '' && $metadata['language_id'] !== $expected_language) {
            $valid = false;
            $warnings[] = 'Language pack metadata language_id must match its profile in ' . $path;
        }

        if ($metadata['pack_date'] !== '' && !$this->is_valid_date($metadata['pack_date'])) {
            $valid = false;
            $warnings[] = 'Language pack metadata pack_date must be a valid YYYY-MM-DD date in ' . $path;
        }

        if ($metadata['source_url'] !== '' && !$this->is_valid_url($metadata['source_url'])) {
            $valid = false;
            $warnings[] = 'Language pack metadata source_url must be an HTTP(S) URL in ' . $path;
        }

        if ($metadata['data_kind'] !== '' && !$this->metadata_data_kind_is_allowed($metadata, $expected_language, $tokenizer, $path, $warnings)) {
            $valid = false;
            $warnings[] = 'Language pack metadata data_kind must be curated_seed, imported_comprehensive, or synthetic_readiness_fixture with synthetic tokenizer provenance in ' . $path;
        }

        $files = $raw['files'] ?? null;
        if (!is_array($files) || $files === []) {
            $valid = false;
            $warnings[] = 'Language pack metadata files must be a non-empty array in ' . $path;
        } else {
            $runtime_files_exist = true;
            $seen_files = [];
            foreach ($files as $file) {
                if (!is_string($file) || trim($file) === '') {
                    $valid = false;
                    $runtime_files_exist = false;
                    $warnings[] = 'Language pack metadata files must contain non-empty strings in ' . $path;
                    continue;
                }

                $file = trim($file);
                if (!$this->is_local_file_name($file)) {
                    $valid = false;
                    $runtime_files_exist = false;
                    $warnings[] = 'Language pack metadata files must be local file names in ' . $path;
                    continue;
                }

                if (isset($seen_files[$file])) {
                    $valid = false;
                    $warnings[] = 'Language pack metadata files contains duplicate entry ' . $file . ' in ' . $path;
                    continue;
                }
                $seen_files[$file] = true;
                $metadata['files'][] = $file;

                $listed_path = $directory . DIRECTORY_SEPARATOR . $file;
                if (!is_file($listed_path)) {
                    $valid = false;
                    $runtime_files_exist = false;
                    $missing_files[] = $file;
                    $warnings[] = 'Language pack metadata file does not exist: ' . $listed_path;
                }
            }
        }

        sort($metadata['files'], SORT_STRING);
        sort($missing_files, SORT_STRING);
        $files_lookup = array_fill_keys($metadata['files'], true);

        if ($metadata['data_kind'] === 'imported_comprehensive') {
            if ($metadata['metadata_schema'] !== self::METADATA_SCHEMA_V2) {
                $warnings[] = 'Language pack metadata imported_comprehensive packs must declare metadata_schema ' . self::METADATA_SCHEMA_V2 . ' in ' . $path;
            }

            $metadata['source'] = $this->validate_source_metadata($raw['source'] ?? null, $path, $warnings);
            $metadata['license'] = $this->validate_license_metadata($raw['license'] ?? null, $directory, $files_lookup, $path, $warnings);
            $metadata['provenance_ids'] = $this->validate_provenance_ids($raw['provenance_ids'] ?? null, $metadata['provenance'], $path, $warnings);
            $metadata['normalization'] = $this->validate_normalization_metadata($raw['normalization'] ?? null, $directory, $expected_language, $profile_file, $path, $warnings);
            $metadata['importer'] = $this->validate_importer_metadata($raw['importer'] ?? null, $path, $warnings);
            $runtime_files_invalid = false;
            $metadata['runtime_files'] = $this->validate_runtime_files_metadata($raw['runtime_files'] ?? null, $directory, $files_lookup, $path, $warnings, $runtime_files_invalid);

            $required_runtime_files = ['profile' => basename($profile_file)];
            foreach ($resource_paths as $resource => $resource_path) {
                $required_runtime_files[(string) $resource] = basename((string) $resource_path);
            }

            $license_text_file = is_array($metadata['license']) ? (string) ($metadata['license']['text_file'] ?? '') : '';
            if ($license_text_file !== '') {
                $required_runtime_files['license'] = $license_text_file;
            }

            foreach ($required_runtime_files as $resource => $file) {
                if (!isset($files_lookup[$file])) {
                    $warnings[] = 'Language pack metadata files must include ' . $file . ' for comprehensive resource ' . $resource . ' in ' . $path;
                }
            }

            $runtime_files_by_pair = [];
            $runtime_files_incomplete = false;
            $runtime_mismatched = false;
            foreach ($metadata['runtime_files'] as $record) {
                if (!is_array($record)) {
                    $runtime_files_invalid = true;
                    continue;
                }
                $resource = (string) ($record['resource'] ?? '');
                $file = (string) ($record['file'] ?? '');
                if ($resource !== '' && $file !== '') {
                    $runtime_files_by_pair[$this->runtime_file_pair_key($resource, $file)] = true;
                }
                if (($record['status'] ?? '') === 'mismatch') {
                    $runtime_mismatched = true;
                } elseif (($record['status'] ?? '') === 'invalid') {
                    $runtime_files_invalid = true;
                }
            }

            foreach ($required_runtime_files as $resource => $file) {
                if (!isset($runtime_files_by_pair[$this->runtime_file_pair_key((string) $resource, $file)])) {
                    $runtime_files_incomplete = true;
                    $warnings[] = 'Language pack metadata runtime_files must include ' . $file . ' for comprehensive resource ' . $resource . ' in ' . $path;
                }
            }

            $metadata['runtime_digest_status'] = $this->runtime_digest_status($raw['runtime_files'] ?? null, $runtime_files_invalid, $runtime_files_incomplete, $runtime_mismatched);
        }

        $valid = $valid && count($warnings) === $metadata_warning_count;

        return [
            'metadata' => $metadata,
            'valid' => $valid,
            'runtime_files_exist' => $runtime_files_exist,
            'missing_files' => $missing_files,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validate_source_metadata(mixed $source, string $path, array &$warnings): array
    {
        if (!is_array($source) || array_is_list($source)) {
            $warnings[] = 'Language pack metadata source must be an array in ' . $path;

            return [];
        }

        $metadata = [
            'name' => $this->metadata_required_string($source, 'name', 'source.name', $path, $warnings),
            'version' => $this->metadata_required_string($source, 'version', 'source.version', $path, $warnings),
            'version_url' => $this->metadata_optional_string($source, 'version_url', 'source.version_url', $path, $warnings),
            'retrieved_at' => $this->metadata_required_string($source, 'retrieved_at', 'source.retrieved_at', $path, $warnings),
            'artifacts' => [],
        ];

        if ($metadata['version_url'] !== '' && !$this->is_valid_url($metadata['version_url'])) {
            $warnings[] = 'Language pack metadata source.version_url must be an HTTP(S) URL in ' . $path;
        }

        if ($metadata['retrieved_at'] !== '' && !$this->is_valid_date($metadata['retrieved_at'])) {
            $warnings[] = 'Language pack metadata source.retrieved_at must be a valid YYYY-MM-DD date in ' . $path;
        }

        $artifacts = $source['artifacts'] ?? null;
        if (!is_array($artifacts) || $artifacts === []) {
            $warnings[] = 'Language pack metadata source.artifacts must be a non-empty array in ' . $path;

            return $metadata;
        }

        foreach ($artifacts as $index => $artifact) {
            if (!is_array($artifact) || array_is_list($artifact)) {
                $warnings[] = 'Language pack metadata source.artifacts entries must be arrays in ' . $path;
                continue;
            }

            $record = [
                'name' => $this->metadata_required_string($artifact, 'name', 'source.artifacts[' . (string) $index . '].name', $path, $warnings),
                'url' => $this->metadata_required_string($artifact, 'url', 'source.artifacts[' . (string) $index . '].url', $path, $warnings),
                'sha256' => $this->metadata_required_string($artifact, 'sha256', 'source.artifacts[' . (string) $index . '].sha256', $path, $warnings),
                'bytes' => $artifact['bytes'] ?? null,
            ];

            if ($record['url'] !== '' && !$this->is_valid_url($record['url'])) {
                $warnings[] = 'Language pack metadata source artifact URL must be an HTTP(S) URL in ' . $path;
            }
            if ($record['sha256'] !== '' && !$this->is_valid_sha256($record['sha256'])) {
                $warnings[] = 'Language pack metadata source artifact sha256 must be 64 lowercase hex characters in ' . $path;
            }
            if (!$this->is_positive_int($record['bytes'])) {
                $warnings[] = 'Language pack metadata source artifact bytes must be a positive integer in ' . $path;
                $record['bytes'] = 0;
            }

            $metadata['artifacts'][] = $record;
        }

        usort(
            $metadata['artifacts'],
            static fn(array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
                ?: strcmp((string) ($a['url'] ?? ''), (string) ($b['url'] ?? ''))
        );

        return $metadata;
    }

    /**
     * @param array<string,bool> $files_lookup
     * @return array<string,string>
     */
    private function validate_license_metadata(mixed $license, string $directory, array $files_lookup, string $path, array &$warnings): array
    {
        if (!is_array($license) || array_is_list($license)) {
            $warnings[] = 'Language pack metadata license must be an array in ' . $path;

            return [];
        }

        $metadata = [
            'identifier' => $this->metadata_required_string($license, 'identifier', 'license.identifier', $path, $warnings),
            'name' => $this->metadata_required_string($license, 'name', 'license.name', $path, $warnings),
            'url' => $this->metadata_required_string($license, 'url', 'license.url', $path, $warnings),
            'text_url' => $this->metadata_required_string($license, 'text_url', 'license.text_url', $path, $warnings),
            'text_file' => $this->metadata_required_string($license, 'text_file', 'license.text_file', $path, $warnings),
            'attribution' => $this->metadata_required_string($license, 'attribution', 'license.attribution', $path, $warnings),
        ];

        if ($metadata['identifier'] !== '' && !$this->is_valid_license_identifier($metadata['identifier'])) {
            $warnings[] = 'Language pack metadata license.identifier must be SPDX-like text without whitespace in ' . $path;
        }
        foreach (['url', 'text_url'] as $url_key) {
            if ($metadata[$url_key] !== '' && !$this->is_valid_url($metadata[$url_key])) {
                $warnings[] = 'Language pack metadata license.' . $url_key . ' must be an HTTP(S) URL in ' . $path;
            }
        }
        if ($metadata['text_file'] !== '' && !$this->is_local_file_name($metadata['text_file'])) {
            $warnings[] = 'Language pack metadata license.text_file must be a local file name in ' . $path;
        } elseif ($metadata['text_file'] !== '') {
            if (!isset($files_lookup[$metadata['text_file']])) {
                $warnings[] = 'Language pack metadata files must include license.text_file ' . $metadata['text_file'] . ' in ' . $path;
            }
            if (!is_file($directory . DIRECTORY_SEPARATOR . $metadata['text_file'])) {
                $warnings[] = 'Language pack metadata license.text_file does not exist: ' . $directory . DIRECTORY_SEPARATOR . $metadata['text_file'];
            }
        }

        return $metadata;
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function validate_provenance_ids(mixed $provenance_ids, string $default_provenance, string $path, array &$warnings): array
    {
        if (!is_array($provenance_ids) || $provenance_ids === [] || array_is_list($provenance_ids)) {
            $warnings[] = 'Language pack metadata provenance_ids must be a non-empty keyed array in ' . $path;

            return [];
        }

        $metadata = [];
        foreach ($provenance_ids as $id => $record) {
            $id = is_string($id) ? trim($id) : '';
            if ($id === '' || preg_match('/\s/u', $id) === 1 || str_contains($id, '#')) {
                $warnings[] = 'Language pack metadata provenance_ids keys must be non-empty provenance tokens in ' . $path;
                continue;
            }
            if (!is_array($record) || array_is_list($record)) {
                $warnings[] = 'Language pack metadata provenance_ids.' . $id . ' must be an array in ' . $path;
                continue;
            }

            $metadata[$id] = [
                'source' => $this->metadata_required_string($record, 'source', 'provenance_ids.' . $id . '.source', $path, $warnings),
                'source_version' => $this->metadata_required_string($record, 'source_version', 'provenance_ids.' . $id . '.source_version', $path, $warnings),
                'description' => $this->metadata_required_string($record, 'description', 'provenance_ids.' . $id . '.description', $path, $warnings),
            ];
        }

        if ($default_provenance !== '' && !isset($metadata[$default_provenance])) {
            $warnings[] = 'Language pack metadata provenance_ids must declare the top-level provenance ' . $default_provenance . ' in ' . $path;
        }

        ksort($metadata, SORT_STRING);

        return $metadata;
    }

    /**
     * @return array<string,string>
     */
    private function validate_normalization_metadata(mixed $normalization, string $directory, string $expected_language, string $profile_file, string $path, array &$warnings): array
    {
        if (!is_array($normalization) || array_is_list($normalization)) {
            $warnings[] = 'Language pack metadata normalization must be an array in ' . $path;

            return [];
        }

        $metadata = [
            'profile_id' => $this->metadata_required_string($normalization, 'profile_id', 'normalization.profile_id', $path, $warnings),
            'profile_version' => $this->metadata_required_string($normalization, 'profile_version', 'normalization.profile_version', $path, $warnings),
            'profile_file' => $this->metadata_required_string($normalization, 'profile_file', 'normalization.profile_file', $path, $warnings),
            'profile_sha256' => $this->metadata_required_string($normalization, 'profile_sha256', 'normalization.profile_sha256', $path, $warnings),
        ];

        if ($metadata['profile_id'] !== '' && $metadata['profile_id'] !== $expected_language) {
            $warnings[] = 'Language pack metadata normalization.profile_id must match profile language id ' . $expected_language . ' in ' . $path;
        }

        if ($metadata['profile_file'] !== '' && !$this->is_local_file_name($metadata['profile_file'])) {
            $warnings[] = 'Language pack metadata normalization.profile_file must be a local file name in ' . $path;
        } elseif ($metadata['profile_file'] !== '' && $directory . DIRECTORY_SEPARATOR . $metadata['profile_file'] !== $profile_file) {
            $warnings[] = 'Language pack metadata normalization.profile_file must match profile.php in ' . $path;
        }

        if ($metadata['profile_sha256'] !== '' && !$this->is_valid_sha256($metadata['profile_sha256'])) {
            $warnings[] = 'Language pack metadata normalization.profile_sha256 must be 64 lowercase hex characters in ' . $path;
        } elseif ($metadata['profile_sha256'] !== '') {
            $actual = $this->file_sha256($profile_file);
            if ($actual !== null && $actual !== $metadata['profile_sha256']) {
                $warnings[] = 'Language pack metadata normalization.profile_sha256 does not match installed profile file in ' . $path;
            }
        }

        return $metadata;
    }

    /**
     * @return array<string,mixed>
     */
    private function validate_importer_metadata(mixed $importer, string $path, array &$warnings): array
    {
        if (!is_array($importer) || array_is_list($importer)) {
            $warnings[] = 'Language pack metadata importer must be an array in ' . $path;

            return [];
        }

        $metadata = [
            'name' => $this->metadata_required_string($importer, 'name', 'importer.name', $path, $warnings),
            'version' => $this->metadata_required_string($importer, 'version', 'importer.version', $path, $warnings),
            'format' => $this->metadata_required_string($importer, 'format', 'importer.format', $path, $warnings),
            'command' => $this->metadata_required_string($importer, 'command', 'importer.command', $path, $warnings),
            'options' => [],
        ];

        $options = $importer['options'] ?? null;
        if (!is_array($options) || array_is_list($options)) {
            $warnings[] = 'Language pack metadata importer.options must be a keyed array in ' . $path;
        } else {
            foreach ($options as $key => $value) {
                if (!is_string($key) || $key === '' || !is_scalar($value)) {
                    $warnings[] = 'Language pack metadata importer.options must map non-empty string keys to scalar values in ' . $path;
                    continue;
                }
                $metadata['options'][$key] = (string) $value;
            }
            ksort($metadata['options'], SORT_STRING);
        }

        return $metadata;
    }

    /**
     * @param array<string,bool> $files_lookup
     * @return array<int,array<string,mixed>>
     */
    private function validate_runtime_files_metadata(mixed $runtime_files, string $directory, array $files_lookup, string $path, array &$warnings, bool &$invalid): array
    {
        if (!is_array($runtime_files) || $runtime_files === []) {
            $invalid = true;
            $warnings[] = 'Language pack metadata runtime_files must be a non-empty array in ' . $path;

            return [];
        }

        $metadata = [];
        $seen = [];
        foreach ($runtime_files as $index => $runtime_file) {
            if (!is_array($runtime_file) || array_is_list($runtime_file)) {
                $invalid = true;
                $warnings[] = 'Language pack metadata runtime_files entries must be arrays in ' . $path;
                continue;
            }

            $record = [
                'resource' => $this->metadata_required_string($runtime_file, 'resource', 'runtime_files[' . (string) $index . '].resource', $path, $warnings),
                'file' => $this->metadata_required_string($runtime_file, 'file', 'runtime_files[' . (string) $index . '].file', $path, $warnings),
                'sha256' => $this->metadata_required_string($runtime_file, 'sha256', 'runtime_files[' . (string) $index . '].sha256', $path, $warnings),
                'bytes' => $runtime_file['bytes'] ?? null,
                'generated' => (bool) ($runtime_file['generated'] ?? false),
                'status' => 'ok',
            ];

            $record_invalid = false;
            if ($record['resource'] === '' || $record['file'] === '' || $record['sha256'] === '') {
                $record_invalid = true;
            }

            if ($record['resource'] !== '' && preg_match('/^[a-z][a-z0-9_:-]*$/', $record['resource']) !== 1) {
                $record_invalid = true;
                $warnings[] = 'Language pack metadata runtime_files resource must be a stable lowercase key in ' . $path;
            }

            if ($record['file'] !== '' && !$this->is_local_file_name($record['file'])) {
                $record_invalid = true;
                $warnings[] = 'Language pack metadata runtime_files file must be a local file name in ' . $path;
            } elseif ($record['file'] !== '' && !isset($files_lookup[$record['file']])) {
                $record_invalid = true;
                $warnings[] = 'Language pack metadata runtime_files file must appear in files: ' . $record['file'] . ' in ' . $path;
            }

            $sha256_valid = $record['sha256'] !== '' && $this->is_valid_sha256($record['sha256']);
            if ($record['sha256'] !== '' && !$this->is_valid_sha256($record['sha256'])) {
                $record_invalid = true;
                $warnings[] = 'Language pack metadata runtime_files sha256 must be 64 lowercase hex characters in ' . $path;
            }
            if (!$this->is_positive_int($record['bytes'])) {
                $record_invalid = true;
                $warnings[] = 'Language pack metadata runtime_files bytes must be a positive integer in ' . $path;
                $record['bytes'] = 0;
            }

            $duplicate_key = $record['resource'] . "\t" . $record['file'];
            if ($record['resource'] !== '' && $record['file'] !== '' && isset($seen[$duplicate_key])) {
                $record_invalid = true;
                $warnings[] = 'Language pack metadata runtime_files contains duplicate resource/file entry ' . $record['resource'] . '/' . $record['file'] . ' in ' . $path;
            }
            $seen[$duplicate_key] = true;

            $absolute = $directory . DIRECTORY_SEPARATOR . $record['file'];
            if ($record['file'] !== '' && is_file($absolute)) {
                $actual_sha256 = $this->file_sha256($absolute);
                if ($actual_sha256 !== null && $sha256_valid && $actual_sha256 !== $record['sha256']) {
                    $record['status'] = 'mismatch';
                    $warnings[] = 'Language pack metadata runtime file sha256 mismatch for ' . $record['file'] . ' in ' . $path;
                }

                $actual_bytes = filesize($absolute);
                if (is_int($actual_bytes) && $record['bytes'] !== 0 && $actual_bytes !== (int) $record['bytes']) {
                    $record['status'] = 'mismatch';
                    $warnings[] = 'Language pack metadata runtime file byte count mismatch for ' . $record['file'] . ' in ' . $path;
                }
            }

            if ($record_invalid) {
                $invalid = true;
                $record['status'] = 'invalid';
            }

            $metadata[] = $record;
        }

        usort(
            $metadata,
            static fn(array $a, array $b): int => strcmp((string) ($a['resource'] ?? ''), (string) ($b['resource'] ?? ''))
                ?: strcmp((string) ($a['file'] ?? ''), (string) ($b['file'] ?? ''))
        );

        return $metadata;
    }

    private function runtime_file_pair_key(string $resource, string $file): string
    {
        return $resource . "\t" . $file;
    }

    private function runtime_digest_status(mixed $runtime_files, bool $invalid, bool $incomplete, bool $mismatched): string
    {
        if (!is_array($runtime_files) || $runtime_files === []) {
            return 'not_declared';
        }
        if ($mismatched) {
            return 'mismatch';
        }
        if ($invalid) {
            return 'invalid';
        }
        if ($incomplete) {
            return 'incomplete';
        }

        return 'ok';
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $tokenizer
     */
    private function metadata_data_kind_is_allowed(array $metadata, string $expected_language, array $tokenizer, string $path, array &$warnings): bool
    {
        $data_kind = (string) ($metadata['data_kind'] ?? '');
        if (in_array($data_kind, ['curated_seed', 'imported_comprehensive'], true)) {
            return true;
        }

        if ($data_kind !== 'synthetic_readiness_fixture') {
            return false;
        }

        $valid = true;
        if (!Language_FTS_Playground_Tokenizer_Registry::is_synthetic_readiness_contract($tokenizer)) {
            $warnings[] = 'Synthetic readiness fixture metadata requires the synthetic_dictionary_v1 tokenizer in ' . $path;
            $valid = false;
        }

        if (preg_match('/^q[a-z0-9]*$/', $expected_language) !== 1) {
            $warnings[] = 'Synthetic tokenizer readiness fixtures must use private q* language ids in ' . $path;
            $valid = false;
        }

        $combined = strtolower(
            (string) ($metadata['source_name'] ?? '')
            . ' '
            . (string) ($metadata['attribution_text'] ?? '')
            . ' '
            . (string) ($metadata['provenance'] ?? '')
        );
        if (!str_contains($combined, 'synthetic') || !str_contains($combined, 'readiness')) {
            $warnings[] = 'Synthetic tokenizer readiness metadata must clearly say synthetic readiness in ' . $path;
            $valid = false;
        }

        return $valid;
    }

    /**
     * @param array<mixed> $resources
     * @return array<string,string>
     */
    private function resolve_profile_resources(string $directory, string $profile_file, array $resources, array &$warnings): array
    {
        $paths = [];
        foreach (['stopwords', 'lexemes', 'synonyms'] as $key) {
            $path = $this->resolve_resource_path($directory, $resources, $key, $profile_file, $warnings, true);
            if ($path !== null) {
                $paths[$key] = $path;
            }
        }

        foreach (['synsets', 'synonym_phrases', 'term_rules', 'protected_terms'] as $optional_key) {
            $path = $this->resolve_resource_path($directory, $resources, $optional_key, $profile_file, $warnings, false);
            if ($path !== null) {
                $paths[$optional_key] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param array<string,mixed> $tokenizer
     * @return array<string,string>
     */
    private function resolve_tokenizer_resources(string $directory, string $profile_file, array $tokenizer, array &$warnings): array
    {
        $resources = $tokenizer['resources'] ?? [];
        if (!is_array($resources)) {
            return [];
        }

        $paths = [];
        foreach ($resources as $key => $name) {
            $key = (string) $key;
            if (!is_string($name) || trim($name) === '') {
                $warnings[] = "Language profile tokenizer resource {$key} must be a non-empty string in {$profile_file}";
                continue;
            }

            $name = trim($name);
            if (!$this->is_local_file_name($name)) {
                $warnings[] = "Language profile tokenizer resource {$key} must be a local file name in {$profile_file}";
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                $warnings[] = "Language profile tokenizer resource {$key} does not exist: {$path}";
                continue;
            }

            $paths[$key] = $path;
        }

        ksort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @param array<string,string> $paths
     * @return array<int,array{resource:string,file:string,sha256:string}>
     */
    private function resource_digests(array $paths, string $prefix): array
    {
        $digests = [];
        foreach ($paths as $resource => $path) {
            $digest = hash_file('sha256', $path);
            if (!is_string($digest)) {
                continue;
            }

            $digests[] = [
                'resource' => $prefix . (string) $resource,
                'file' => basename($path),
                'sha256' => $digest,
            ];
        }

        usort(
            $digests,
            static fn(array $a, array $b): int => strcmp($a['resource'], $b['resource'])
                ?: strcmp($a['file'], $b['file'])
        );

        return $digests;
    }

    /**
     * @return array{rows:int,resource_bytes:int,max_input_run_bytes:int,max_output_token_bytes:int}
     */
    private function validate_synthetic_tokenizer_dictionary(string $path, array &$warnings): array
    {
        try {
            return Language_FTS_Playground_Synthetic_Dictionary_Tokenizer::load_dictionary($path)['stats'];
        } catch (Throwable $throwable) {
            $warnings[] = $throwable->getMessage();

            return [
                'rows' => 0,
                'resource_bytes' => 0,
                'max_input_run_bytes' => 0,
                'max_output_token_bytes' => 0,
            ];
        }
    }

    /**
     * @param string[] $files
     * @param array<mixed> $resources
     */
    private function validate_pack_files_include_profile_resources(
        array $files,
        array $resources,
        string $profile_file,
        string $pack_file,
        array &$warnings
    ): bool {
        $valid = true;
        $listed_files = array_fill_keys($files, true);

        foreach ($resources as $key => $file) {
            if (!is_string($key) || !is_string($file)) {
                continue;
            }

            $file = trim($file);
            if ($file === '' || !$this->is_local_file_name($file)) {
                continue;
            }

            if (!isset($listed_files[$file])) {
                $valid = false;
                $warnings[] = "Language pack metadata files must include profile resource {$key} ({$file}) declared in {$profile_file}: {$pack_file}";
            }
        }

        return $valid;
    }

    /**
     * @param array<mixed> $resources
     */
    private function resolve_resource_path(
        string $directory,
        array $resources,
        string $key,
        string $profile_file,
        array &$warnings,
        bool $required
    ): string|null {
        if (!array_key_exists($key, $resources)) {
            if ($required) {
                $warnings[] = "Language profile resource {$key} must be declared in {$profile_file}";
            }

            return null;
        }

        $name = $resources[$key];
        if (!is_string($name) || trim($name) === '') {
            $warnings[] = "Language profile resource {$key} must be a non-empty string in {$profile_file}";

            return null;
        }

        $name = trim($name);
        if (!$this->is_local_file_name($name)) {
            $warnings[] = "Language profile resource {$key} must be a local file name in {$profile_file}";

            return null;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) {
            $warnings[] = "Language profile resource {$key} does not exist: {$path}";

            return null;
        }

        return $path;
    }

    /**
     * @param array<string,string> $normalization_folds
     */
    private function validate_stopwords(string $path, array &$warnings, array $normalization_folds): int
    {
        $count = 0;
        $seen = [];
        foreach ($this->resource_lines($path, $warnings) as $line_number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $token = $this->normalized_resource_token($line, $path, $line_number + 1, 'stopword', $warnings, $normalization_folds);
            if ($token === null) {
                continue;
            }

            $count++;
            if (isset($seen[$token])) {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'duplicate stopword row');
            }
            $seen[$token] = true;
        }

        return $count;
    }

    /**
     * @param array<string,string> $normalization_folds
     * @param array<string,bool>|null $row_provenance_allow_list
     */
    private function validate_lexemes(string $path, array &$warnings, array $normalization_folds, array|null $row_provenance_allow_list = null): int
    {
        $count = 0;
        $seen = [];
        foreach ($this->resource_lines($path, $warnings) as $line_number => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $columns = explode("\t", $line);
            if (count($columns) < 2 || count($columns) > 3) {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'lexeme rows must have 2 or 3 tab-separated columns');
                continue;
            }

            $form = $this->normalized_resource_token($columns[0], $path, $line_number + 1, 'lexeme observed form', $warnings, $normalization_folds);
            $canonical = $this->normalized_resource_token($columns[1], $path, $line_number + 1, 'lexeme canonical key', $warnings, $normalization_folds);
            if ($form === null || $canonical === null) {
                continue;
            }

            if (array_key_exists(2, $columns) && trim($columns[2]) === '') {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'lexeme provenance must be non-empty when present');
            } elseif (array_key_exists(2, $columns)) {
                $this->validate_row_provenance(trim($columns[2]), $path, $line_number + 1, 'lexeme', $warnings, $row_provenance_allow_list);
            } elseif ($row_provenance_allow_list !== null) {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'lexeme provenance must be present for imported_comprehensive packs');
            }

            $count++;
            $key = $form . "\t" . $canonical;
            if (isset($seen[$key])) {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'duplicate lexeme observed/canonical row');
            }
            $seen[$key] = true;
        }

        return $count;
    }

    /**
     * @param array<string,string> $normalization_folds
     */
    private function validate_protected_terms(string $path, array &$warnings, array $normalization_folds): int
    {
        $count = 0;
        $seen = [];
        foreach ($this->resource_lines($path, $warnings) as $line_number => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $term = $this->normalized_resource_token($trimmed, $path, $line_number + 1, 'protected term', $warnings, $normalization_folds);
            if ($term === null) {
                continue;
            }

            $count++;
            if (isset($seen[$term])) {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'duplicate protected term');
            }
            $seen[$term] = true;
        }

        return $count;
    }

    /**
     * @param array<string,string> $normalization_folds
     * @param array<string,bool>|null $row_provenance_allow_list
     */
    private function validate_term_rules(string $path, array &$warnings, array $normalization_folds, array|null $row_provenance_allow_list = null): int
    {
        $count = 0;
        $seen_ids = [];
        foreach ($this->resource_lines($path, $warnings) as $line_number => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $line_number++;
            $columns = explode("\t", $line);
            if (count($columns) !== 11) {
                $warnings[] = $this->resource_error($path, $line_number, 'term rule rows must have exactly 11 tab-separated columns');
                continue;
            }

            $id = $this->validate_term_rule_row($columns, $path, $line_number, $warnings, $normalization_folds, $row_provenance_allow_list);
            if ($id === null) {
                continue;
            }

            $count++;
            if (isset($seen_ids[$id])) {
                $warnings[] = $this->resource_error($path, $line_number, 'duplicate term rule id');
            }
            $seen_ids[$id] = true;
        }

        return $count;
    }

    /**
     * @param string[] $columns
     * @param array<string,string> $normalization_folds
     * @param array<string,bool>|null $row_provenance_allow_list
     */
    private function validate_term_rule_row(array $columns, string $path, int $line_number, array &$warnings, array $normalization_folds, array|null $row_provenance_allow_list = null): string|null
    {
        $id = $this->term_rule_id($columns[0], $path, $line_number, $warnings);
        $valid = $id !== null;

        if ($this->term_rule_positive_integer($columns[1], $path, $line_number, 'min_term_length', $warnings) === null) {
            $valid = false;
        }

        if (!$this->term_rule_regex_is_valid($columns[2], $path, $line_number, $warnings)) {
            $valid = false;
        }

        foreach ([3 => 'strip_prefix', 4 => 'strip_suffix', 5 => 'append'] as $column_index => $label) {
            if ($this->term_rule_literal_is_valid($columns[$column_index], $path, $line_number, $label, $warnings, $normalization_folds) === null) {
                $valid = false;
            }
        }

        if (trim($columns[3]) === '' && trim($columns[4]) === '' && trim($columns[5]) === '') {
            $warnings[] = $this->resource_error($path, $line_number, 'term rule must strip a prefix, strip a suffix, or append text');
            $valid = false;
        }

        if ($this->term_rule_positive_integer($columns[6], $path, $line_number, 'min_key_length', $warnings) === null) {
            $valid = false;
        }

        if (!$this->term_rule_flags_are_valid($columns[7], ['trim_doubled_final_consonant', 'require_vowel', 'require_vowel_or_y', 'append_e_if_cvc', 'stop_after_match'], $path, $line_number, $warnings, 'term rule flag must be trim_doubled_final_consonant, require_vowel, require_vowel_or_y, append_e_if_cvc, or stop_after_match')) {
            $valid = false;
        }

        if (!$this->term_rule_alternate_pattern_is_valid($columns[8], $columns[9], $path, $line_number, $warnings)) {
            $valid = false;
        }

        if ($this->term_rule_literal_is_valid($columns[9], $path, $line_number, 'alternate_replacement', $warnings, $normalization_folds) === null) {
            $valid = false;
        }

        if ($this->term_rule_provenance($columns[10], $path, $line_number, $warnings, $row_provenance_allow_list) === null) {
            $valid = false;
        }

        return $valid ? $id : null;
    }

    private function term_rule_id(string $value, string $path, int $line_number, array &$warnings): string|null
    {
        $id = trim($value);
        if ($id === '') {
            $warnings[] = $this->resource_error($path, $line_number, 'term rule id must be non-empty');

            return null;
        }

        return $id;
    }

    /**
     * @param array<string,bool>|null $row_provenance_allow_list
     */
    private function term_rule_provenance(string $value, string $path, int $line_number, array &$warnings, array|null $row_provenance_allow_list = null): string|null
    {
        $provenance = trim($value);
        if ($provenance === '') {
            $warnings[] = $this->resource_error($path, $line_number, 'term rule provenance must be non-empty');

            return null;
        }

        $this->validate_row_provenance($provenance, $path, $line_number, 'term rule', $warnings, $row_provenance_allow_list);

        return $provenance;
    }

    private function term_rule_positive_integer(string $value, string $path, int $line_number, string $label, array &$warnings): int|null
    {
        $value = trim($value);
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            $warnings[] = $this->resource_error($path, $line_number, "term rule {$label} must be a positive integer");

            return null;
        }

        return (int) $value;
    }

    private function term_rule_regex_is_valid(string $value, string $path, int $line_number, array &$warnings): bool
    {
        $pattern = trim($value);
        if ($pattern === '' || @preg_match($pattern, '') === false) {
            $warnings[] = $this->resource_error($path, $line_number, 'term rule regex must be valid');

            return false;
        }

        return true;
    }

    private function term_rule_alternate_pattern_is_valid(
        string $pattern_value,
        string $replacement_value,
        string $path,
        int $line_number,
        array &$warnings
    ): bool {
        $pattern = trim($pattern_value);
        $replacement = trim($replacement_value);
        if ($pattern === '') {
            if ($replacement !== '') {
                $warnings[] = $this->resource_error($path, $line_number, 'term rule alternate pattern is required when alternate replacement is set');

                return false;
            }

            return true;
        }

        if (@preg_match($pattern, '') === false) {
            $warnings[] = $this->resource_error($path, $line_number, 'term rule alternate regex must be valid');

            return false;
        }

        return true;
    }

    /**
     * @param string[] $allowed
     */
    private function term_rule_flags_are_valid(
        string $value,
        array $allowed,
        string $path,
        int $line_number,
        array &$warnings,
        string $message,
        bool $require_one = false
    ): bool {
        $value = trim($value);
        if ($value === '') {
            if ($require_one) {
                $warnings[] = $this->resource_error($path, $line_number, $message);

                return false;
            }

            return true;
        }

        $allowed_lookup = array_fill_keys($allowed, true);
        foreach (explode(',', $value) as $flag) {
            $flag = trim($flag);
            if ($flag === '' || !isset($allowed_lookup[$flag])) {
                $warnings[] = $this->resource_error($path, $line_number, $message);

                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,string> $normalization_folds
     */
    private function term_rule_literal_is_valid(
        string $value,
        string $path,
        int $line_number,
        string $label,
        array &$warnings,
        array $normalization_folds
    ): string|null {
        $literal = trim($value);
        if ($literal === '') {
            return '';
        }

        return $this->normalized_resource_token($literal, $path, $line_number, 'term rule ' . $label, $warnings, $normalization_folds);
    }

    /**
     * @param array<string,array<string,bool>> $expansion_targets
     * @param array<string,string> $normalization_folds
     * @param array<string,bool>|null $row_provenance_allow_list
     * @return array{rows:int,expansions:int}
     */
    private function validate_pairwise_synonyms(string $path, array &$warnings, array &$expansion_targets, array $normalization_folds, array|null $row_provenance_allow_list = null): array
    {
        $rows = 0;
        $expansions = 0;
        $seen_expansions = [];

        foreach ($this->resource_lines($path, $warnings) as $line_number => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $columns = explode("\t", $line);
            if (count($columns) !== 5) {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'synonym rows must have exactly 5 tab-separated columns');
                continue;
            }

            $source = $this->normalized_resource_token($columns[0], $path, $line_number + 1, 'synonym source', $warnings, $normalization_folds);
            $target = $this->normalized_resource_token($columns[1], $path, $line_number + 1, 'synonym target', $warnings, $normalization_folds);
            if ($source === null || $target === null) {
                continue;
            }
            if ($source === $target) {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'synonym source and target must differ');
                continue;
            }

            $direction = trim($columns[2]);
            if (!in_array($direction, ['query_to_index', 'bidirectional'], true)) {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'synonym direction must be query_to_index or bidirectional');
                continue;
            }

            if ($this->resource_weight($columns[3], $path, $line_number + 1, 'synonym', $warnings) === null) {
                continue;
            }

            if (trim($columns[4]) === '') {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'synonym provenance must be non-empty');
                continue;
            }
            $this->validate_row_provenance(trim($columns[4]), $path, $line_number + 1, 'synonym', $warnings, $row_provenance_allow_list);

            $rows++;
            foreach ($direction === 'bidirectional' ? [[$source, $target], [$target, $source]] : [[$source, $target]] as $pair) {
                [$pair_source, $pair_target] = $pair;
                if (isset($seen_expansions[$pair_source][$pair_target])) {
                    $warnings[] = $this->resource_error($path, $line_number + 1, 'duplicate synonym source/target pair');
                    continue;
                }

                $seen_expansions[$pair_source][$pair_target] = true;
                $expansion_targets[$pair_source][$pair_target] = true;
                $expansions++;
            }
        }

        return [
            'rows' => $rows,
            'expansions' => $expansions,
        ];
    }

    /**
     * @param array<string,array<string,bool>> $expansion_targets
     * @param array<string,string> $normalization_folds
     * @param array<string,bool>|null $row_provenance_allow_list
     * @return array{rows:int,expansions:int,max_synset_size:int}
     */
    private function validate_synsets(string $path, array &$warnings, array &$expansion_targets, array $normalization_folds, array|null $row_provenance_allow_list = null): array
    {
        $rows = 0;
        $expansions = 0;
        $max_synset_size = 0;
        $seen_concepts = [];

        foreach ($this->resource_lines($path, $warnings) as $line_number => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $columns = explode("\t", $line);
            if (count($columns) !== 4) {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'synset rows must have exactly 4 tab-separated columns');
                continue;
            }

            $concept_id = $this->resource_token($columns[0], $path, $line_number + 1, 'synset concept id', $warnings);
            if ($concept_id === null) {
                continue;
            }

            if (isset($seen_concepts[$concept_id])) {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'duplicate synset concept id');
                continue;
            }
            $seen_concepts[$concept_id] = true;

            if ($this->resource_weight($columns[1], $path, $line_number + 1, 'synset', $warnings) === null) {
                continue;
            }

            if (trim($columns[2]) === '') {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'synset provenance must be non-empty');
                continue;
            }
            $this->validate_row_provenance(trim($columns[2]), $path, $line_number + 1, 'synset', $warnings, $row_provenance_allow_list);

            $terms = $this->parse_synset_terms($columns[3], $path, $line_number + 1, $warnings, $normalization_folds);
            if ($terms === null) {
                continue;
            }

            $term_count = count($terms);
            if ($term_count < 2) {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'synset rows must contain at least 2 terms');
                continue;
            }

            $rows++;
            $max_synset_size = max($max_synset_size, $term_count);
            if ($term_count > $this->max_synset_size) {
                $warnings[] = sprintf(
                    '%s exceeds max synset size %d with %d terms at %s:%d.',
                    $concept_id,
                    $this->max_synset_size,
                    $term_count,
                    $path,
                    $line_number + 1
                );
            }

            foreach ($terms as $source) {
                foreach ($terms as $target) {
                    if ($source === $target) {
                        continue;
                    }

                    $expansion_targets[$source][$target] = true;
                    $expansions++;
                }
            }
        }

        return [
            'rows' => $rows,
            'expansions' => $expansions,
            'max_synset_size' => $max_synset_size,
        ];
    }

    /**
     * @param array<string,array<string,bool>> $expansion_targets
     * @param array<string,string> $normalization_folds
     * @param array<string,bool>|null $row_provenance_allow_list
     * @return array{rows:int,expansions:int}
     */
    private function validate_synonym_phrases(string $path, array &$warnings, array &$expansion_targets, array $normalization_folds, array|null $row_provenance_allow_list = null): array
    {
        $rows = 0;
        $expansions = 0;
        $seen_expansions = [];

        foreach ($this->resource_lines($path, $warnings) as $line_number => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $columns = explode("\t", $line);
            if (count($columns) !== 5) {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'synonym phrase rows must have exactly 5 tab-separated columns');
                continue;
            }

            $source_terms = $this->parse_synonym_phrase_terms($columns[0], $path, $line_number + 1, 'synonym phrase source terms', $warnings, $normalization_folds);
            $target_terms = $this->parse_synonym_phrase_terms($columns[1], $path, $line_number + 1, 'synonym phrase target terms', $warnings, $normalization_folds);
            if ($source_terms === null || $target_terms === null) {
                continue;
            }

            $source = implode(' ', $source_terms);
            $target = implode(' ', $target_terms);
            if ($source === $target) {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'synonym phrase source and target must differ');
                continue;
            }

            $direction = trim($columns[2]);
            if (!in_array($direction, ['query_to_index', 'bidirectional'], true)) {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'synonym phrase direction must be query_to_index or bidirectional');
                continue;
            }

            if ($this->resource_weight($columns[3], $path, $line_number + 1, 'synonym phrase', $warnings) === null) {
                continue;
            }

            if (trim($columns[4]) === '') {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'synonym phrase provenance must be non-empty');
                continue;
            }
            $this->validate_row_provenance(trim($columns[4]), $path, $line_number + 1, 'synonym phrase', $warnings, $row_provenance_allow_list);

            $rows++;
            foreach ($direction === 'bidirectional' ? [[$source, $target], [$target, $source]] : [[$source, $target]] as $pair) {
                [$pair_source, $pair_target] = $pair;
                if (isset($seen_expansions[$pair_source][$pair_target])) {
                    $warnings[] = $this->resource_error($path, $line_number + 1, 'duplicate synonym phrase source/target pair');
                    continue;
                }

                $seen_expansions[$pair_source][$pair_target] = true;
                $expansion_targets[$pair_source][$pair_target] = true;
                $expansions++;
            }
        }

        return [
            'rows' => $rows,
            'expansions' => $expansions,
        ];
    }

    /**
     * @param array<string,string> $normalization_folds
     * @return string[]|null
     */
    private function parse_synset_terms(string $terms_column, string $path, int $line_number, array &$warnings, array $normalization_folds): array|null
    {
        if (trim($terms_column) === '') {
            $warnings[] = $this->resource_error($path, $line_number, 'synset terms must be non-empty');

            return null;
        }

        if ($terms_column !== trim($terms_column) || str_contains($terms_column, '  ')) {
            $warnings[] = $this->resource_error($path, $line_number, 'synset terms must be separated by single spaces');

            return null;
        }

        $terms = [];
        foreach (explode(' ', $terms_column) as $term) {
            if ($term === '') {
                $warnings[] = $this->resource_error($path, $line_number, 'synset terms must be separated by single spaces');

                return null;
            }

            $term = $this->normalized_resource_token($term, $path, $line_number, 'synset terms', $warnings, $normalization_folds);
            if ($term === null) {
                return null;
            }

            if (isset($terms[$term])) {
                $warnings[] = $this->resource_error($path, $line_number, 'duplicate synset term');

                return null;
            }
            $terms[$term] = true;
        }

        return array_keys($terms);
    }

    /**
     * @param array<string,string> $normalization_folds
     * @return string[]|null
     */
    private function parse_synonym_phrase_terms(string $terms_column, string $path, int $line_number, string $label, array &$warnings, array $normalization_folds): array|null
    {
        if (trim($terms_column) === '') {
            $warnings[] = $this->resource_error($path, $line_number, "{$label} must be non-empty");

            return null;
        }

        if ($terms_column !== trim($terms_column) || str_contains($terms_column, '  ')) {
            $warnings[] = $this->resource_error($path, $line_number, "{$label} must be separated by single spaces");

            return null;
        }

        $terms = [];
        foreach (explode(' ', $terms_column) as $term) {
            if ($term === '') {
                $warnings[] = $this->resource_error($path, $line_number, "{$label} must be separated by single spaces");

                return null;
            }

            $term = $this->normalized_resource_token($term, $path, $line_number, $label, $warnings, $normalization_folds);
            if ($term === null) {
                return null;
            }

            if (isset($terms[$term])) {
                $warnings[] = $this->resource_error($path, $line_number, 'duplicate ' . rtrim($label, 's'));

                return null;
            }
            $terms[$term] = true;
        }

        return array_keys($terms);
    }

    /**
     * @param array<string,array<string,bool>> $expansion_targets
     */
    private function max_expansion_fanout(array $expansion_targets): int
    {
        $max = 0;
        foreach ($expansion_targets as $targets) {
            $max = max($max, count($targets));
        }

        return $max;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function read_php_array(string $path, string $label, array &$warnings): array|null
    {
        try {
            $value = require $path;
        } catch (Throwable $throwable) {
            $warnings[] = $label . ' could not be loaded from ' . $path . ': ' . $throwable->getMessage();

            return null;
        }

        if (!is_array($value)) {
            $warnings[] = $label . ' must return an array: ' . $path;

            return null;
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function required_string(array $data, string $key, string $path, array &$warnings): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            $warnings[] = "Language pack/profile metadata {$key} must be a non-empty string in {$path}";

            return '';
        }

        return trim($value);
    }

    /**
     * @param array<mixed> $data
     */
    private function metadata_required_string(array $data, string $key, string $label, string $path, array &$warnings): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            $warnings[] = "Language pack metadata {$label} must be a non-empty string in {$path}";

            return '';
        }

        return trim($value);
    }

    /**
     * @param array<mixed> $data
     */
    private function metadata_optional_string(array $data, string $key, string $label, string $path, array &$warnings): string
    {
        if (!array_key_exists($key, $data)) {
            return '';
        }

        $value = $data[$key];
        if (!is_string($value) || trim($value) === '') {
            $warnings[] = "Language pack metadata {$label} must be a non-empty string when present in {$path}";

            return '';
        }

        return trim($value);
    }

    /**
     * @return array<string,string>
     */
    private function validate_string_map(mixed $value, string $key, string $path, array &$warnings): array
    {
        if (!is_array($value)) {
            $warnings[] = "Language profile {$key} must be an array in {$path}";

            return [];
        }

        $map = [];
        foreach ($value as $from => $to) {
            if (!is_string($from) || !is_string($to) || $from === '' || $to === '') {
                $warnings[] = "Language profile {$key} must map non-empty strings in {$path}";

                return [];
            }

            $map[$from] = $to;
        }

        return $map;
    }

    private function validate_string_list(mixed $value, string $key, string $path, array &$warnings): void
    {
        if (!is_array($value)) {
            $warnings[] = "Language profile {$key} must be an array in {$path}";

            return;
        }

        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                $warnings[] = "Language profile {$key} must contain non-empty strings in {$path}";

                return;
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function validate_tokenizer_contract(mixed $value, string $path, array &$warnings): array
    {
        $default = Language_FTS_Playground_Unicode_Words_Tokenizer::default_contract();
        if ($value === null) {
            return $default;
        }

        if (!is_array($value)) {
            $warnings[] = 'Language profile tokenizer must be an array in ' . $path;

            return $default;
        }

        $id = $this->validate_tokenizer_string($value['id'] ?? null, 'id', $path, $warnings);
        $type = $this->validate_tokenizer_string($value['type'] ?? null, 'type', $path, $warnings);
        $resources = $this->validate_tokenizer_resources($value['resources'] ?? [], $path, $warnings);
        $capabilities = $this->validate_tokenizer_capabilities($value['capabilities'] ?? null, $path, $warnings);
        $version = array_key_exists('version', $value)
            ? $this->validate_tokenizer_version($value['version'], $path, $warnings)
            : null;

        $contract = [
            'id' => $id ?? $default['id'],
            'type' => $type ?? $default['type'],
            'resources' => $resources,
            'capabilities' => $capabilities ?? $default['capabilities'],
        ];
        if ($version !== null) {
            $contract['version'] = $version;
        }

        try {
            Language_FTS_Playground_Tokenizer_Registry::validate_contract($contract, $path);
        } catch (Throwable $throwable) {
            $warnings[] = $throwable->getMessage();
        }

        return $contract;
    }

    private function validate_tokenizer_string(mixed $value, string $key, string $path, array &$warnings): string|null
    {
        if (!is_string($value) || trim($value) === '') {
            $warnings[] = "Language profile tokenizer {$key} must be a non-empty string in {$path}";

            return null;
        }

        $value = trim($value);
        if (preg_match('/^[a-z][a-z0-9_]*(?:_v[0-9]+)?$/', $value) !== 1) {
            $warnings[] = "Language profile tokenizer {$key} has an invalid identifier in {$path}";

            return null;
        }

        return $value;
    }

    private function validate_tokenizer_version(mixed $value, string $path, array &$warnings): string|null
    {
        if (!is_string($value) || trim($value) === '') {
            $warnings[] = 'Language profile tokenizer version must be a non-empty string in ' . $path;

            return null;
        }

        $value = trim($value);
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $value) !== 1) {
            $warnings[] = 'Language profile tokenizer version has an invalid identifier in ' . $path;

            return null;
        }

        return $value;
    }

    /**
     * @return array<string,string>
     */
    private function validate_tokenizer_resources(mixed $value, string $path, array &$warnings): array
    {
        if (!is_array($value)) {
            $warnings[] = 'Language profile tokenizer resources must be an array in ' . $path;

            return [];
        }

        $resources = [];
        foreach ($value as $key => $name) {
            if (!is_string($key) || trim($key) === '') {
                $warnings[] = 'Language profile tokenizer resources must use non-empty string keys in ' . $path;
                continue;
            }

            if (!is_string($name) || trim($name) === '') {
                $warnings[] = "Language profile tokenizer resource {$key} must be a non-empty string in {$path}";
                continue;
            }

            $name = trim($name);
            if (!$this->is_local_file_name($name)) {
                $warnings[] = "Language profile tokenizer resource {$key} must be a local file name in {$path}";
                continue;
            }

            $resources[trim($key)] = $name;
        }

        ksort($resources, SORT_STRING);

        return $resources;
    }

    /**
     * @return array{emits_offsets:bool,emits_positions:bool,supports_fuzzy:bool,supports_overlaps:bool}|null
     */
    private function validate_tokenizer_capabilities(mixed $value, string $path, array &$warnings): array|null
    {
        if (!is_array($value)) {
            $warnings[] = 'Language profile tokenizer capabilities must be an array in ' . $path;

            return null;
        }

        $capabilities = [];
        foreach (['emits_offsets', 'emits_positions', 'supports_fuzzy', 'supports_overlaps'] as $key) {
            if (!array_key_exists($key, $value) || !is_bool($value[$key])) {
                $warnings[] = "Language profile tokenizer capability {$key} must be a boolean in {$path}";

                return null;
            }

            $capabilities[$key] = $value[$key];
        }

        return $capabilities;
    }

    /**
     * @return string[]
     */
    private function resource_lines(string $path, array &$warnings): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            $warnings[] = 'Could not read lexical resource: ' . $path;

            return [];
        }

        return $lines;
    }

    private function resource_token(string $value, string $path, int $line_number, string $label, array &$warnings): string|null
    {
        $token = trim($value);
        if ($token === '') {
            $warnings[] = $this->resource_error($path, $line_number, "{$label} must be non-empty");

            return null;
        }

        $has_whitespace = preg_match('/\s/u', $token);
        if ($has_whitespace === false || $has_whitespace === 1 || str_contains($token, '#')) {
            $warnings[] = $this->resource_error($path, $line_number, "{$label} must not contain whitespace or #");

            return null;
        }

        if (strlen($token) > 255) {
            $warnings[] = $this->resource_error($path, $line_number, "{$label} must be 255 bytes or shorter");

            return null;
        }

        return $token;
    }

    /**
     * @param array<string,string> $normalization_folds
     */
    private function normalized_resource_token(
        string $value,
        string $path,
        int $line_number,
        string $label,
        array &$warnings,
        array $normalization_folds
    ): string|null {
        $token = $this->resource_token($value, $path, $line_number, $label, $warnings);
        if ($token === null) {
            return null;
        }

        if ($token !== $this->normalize_resource_token($token, $normalization_folds)) {
            $warnings[] = $this->resource_error($path, $line_number, "{$label} must be normalized lowercase resource tokens");

            return null;
        }

        return $token;
    }

    /**
     * @param array<string,string> $normalization_folds
     */
    private function normalize_resource_token(string $token, array $normalization_folds): string
    {
        $lowercase = function_exists('mb_strtolower') ? mb_strtolower($token, 'UTF-8') : strtolower($token);

        return $normalization_folds === [] ? $lowercase : strtr($lowercase, $normalization_folds);
    }

    private function resource_weight(string $value, string $path, int $line_number, string $label, array &$warnings): float|null
    {
        $weight_raw = trim($value);
        if (!is_numeric($weight_raw)) {
            $warnings[] = $this->resource_error($path, $line_number, "{$label} weight must be numeric");

            return null;
        }

        $weight = (float) $weight_raw;
        if ($weight <= 0.0 || $weight > 1.0) {
            $warnings[] = $this->resource_error($path, $line_number, "{$label} weight must be greater than 0 and no more than 1");

            return null;
        }

        return $weight;
    }

    private function resource_error(string $path, int $line_number, string $message): string
    {
        return "{$path}:{$line_number}: {$message}";
    }

    private function is_valid_date(string $date): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches) === 1
            && checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }

    private function is_valid_url(string $url): bool
    {
        if ($url !== trim($url) || preg_match('/\s/u', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            && trim((string) $parts['host']) !== '';
    }

    private function is_valid_sha256(string $digest): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $digest) === 1;
    }

    private function is_positive_int(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private function is_valid_license_identifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9.+_-]*(?:-[A-Za-z0-9.+_-]+)*$/', $identifier) === 1;
    }

    private function file_sha256(string $path): string|null
    {
        $digest = is_file($path) ? hash_file('sha256', $path) : false;

        return is_string($digest) ? strtolower($digest) : null;
    }

    private function is_local_file_name(string $name): bool
    {
        return $name !== '' && $name === basename($name) && !str_contains($name, '..');
    }

    private function normalize_language_id(string $language): string|null
    {
        $language = strtolower(trim($language));
        if ($language === '') {
            return null;
        }

        return preg_match('/^[a-z0-9-]+$/', $language) === 1 ? $language : null;
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,bool>|null
     */
    private function row_provenance_allow_list(array $metadata): array|null
    {
        if (($metadata['data_kind'] ?? '') !== 'imported_comprehensive') {
            return null;
        }

        $allow_list = [];
        $default = (string) ($metadata['provenance'] ?? '');
        if ($default !== '') {
            $allow_list[$default] = true;
        }

        foreach (array_keys((array) ($metadata['provenance_ids'] ?? [])) as $provenance_id) {
            $allow_list[(string) $provenance_id] = true;
        }

        return $allow_list;
    }

    /**
     * @param array<string,bool>|null $allow_list
     */
    private function validate_row_provenance(string $provenance, string $path, int $line_number, string $label, array &$warnings, array|null $allow_list): void
    {
        if ($allow_list === null) {
            return;
        }

        if (!isset($allow_list[$provenance])) {
            $warnings[] = $this->resource_error($path, $line_number, "{$label} provenance must be declared in provenance_ids or match top-level provenance");
        }
    }
}
