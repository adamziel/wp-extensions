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
    public const DEFAULT_MAX_SYNSET_SIZE = 64;
    public const DEFAULT_MAX_EXPANSIONS_PER_TERM = 128;

    private string $resource_root;
    private int $max_synset_size;
    private int $max_expansions_per_term;

    public function __construct(
        string|null $resource_root = null,
        int $max_synset_size = self::DEFAULT_MAX_SYNSET_SIZE,
        int $max_expansions_per_term = self::DEFAULT_MAX_EXPANSIONS_PER_TERM
    ) {
        $this->resource_root = rtrim($resource_root ?? dirname(__DIR__) . '/resources/languages', DIRECTORY_SEPARATOR);
        $this->max_synset_size = max(1, $max_synset_size);
        $this->max_expansions_per_term = max(1, $max_expansions_per_term);
    }

    /**
     * @return array{resource_root:string,thresholds:array{max_synset_size:int,max_expansions_per_term:int},valid:bool,warnings:string[],languages:array<int,array<string,mixed>>}
     */
    public function validate_all(): array
    {
        $report = [
            'resource_root' => $this->resource_root,
            'thresholds' => [
                'max_synset_size' => $this->max_synset_size,
                'max_expansions_per_term' => $this->max_expansions_per_term,
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
            'metadata' => $this->empty_metadata(),
            'metadata_valid' => false,
            'runtime_files_exist' => false,
            'missing_files' => [],
            'counts' => [
                'stopwords' => 0,
                'lexeme_rows' => 0,
                'pairwise_synonym_rows' => 0,
                'pairwise_synonym_expansions' => 0,
                'synset_rows' => 0,
                'concept_expansions' => 0,
            ],
            'max_synset_size' => 0,
            'max_expansion_fanout' => 0,
            'warnings' => [],
            'valid' => true,
            'order' => 1000,
        ];

        $profile_file = $directory . DIRECTORY_SEPARATOR . 'profile.php';
        $profile = null;
        $resources = [];

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
                    $this->validate_string_map($profile['normalization']['fold'], 'normalization.fold', $profile_file, $warnings);
                }

                if (isset($profile['language_signals'])) {
                    $this->validate_string_list($profile['language_signals'], 'language_signals', $profile_file, $warnings);
                }

                if (!isset($profile['resources']) || !is_array($profile['resources'])) {
                    $warnings[] = 'Language profile resources must be an array in ' . $profile_file;
                } else {
                    $resources = $profile['resources'];
                }
            }
        }

        $metadata = $this->validate_pack_metadata($directory, (string) $status['language_id'], $warnings);
        $status['metadata'] = $metadata['metadata'];
        $status['metadata_valid'] = $metadata['valid'];
        $status['runtime_files_exist'] = $metadata['runtime_files_exist'];
        $status['missing_files'] = $metadata['missing_files'];

        $resource_paths = $this->resolve_profile_resources($directory, $profile_file, $resources, $warnings);
        $expansion_targets = [];

        if (isset($resource_paths['stopwords'])) {
            $status['counts']['stopwords'] = $this->validate_stopwords($resource_paths['stopwords'], $warnings);
        }
        if (isset($resource_paths['lexemes'])) {
            $status['counts']['lexeme_rows'] = $this->validate_lexemes($resource_paths['lexemes'], $warnings);
        }
        if (isset($resource_paths['synonyms'])) {
            $pairwise = $this->validate_pairwise_synonyms($resource_paths['synonyms'], $warnings, $expansion_targets);
            $status['counts']['pairwise_synonym_rows'] = $pairwise['rows'];
            $status['counts']['pairwise_synonym_expansions'] = $pairwise['expansions'];
        }
        if (isset($resource_paths['synsets'])) {
            $synsets = $this->validate_synsets($resource_paths['synsets'], $warnings, $expansion_targets);
            $status['counts']['synset_rows'] = $synsets['rows'];
            $status['counts']['concept_expansions'] = $synsets['expansions'];
            $status['max_synset_size'] = $synsets['max_synset_size'];
        }

        $status['max_expansion_fanout'] = $this->max_expansion_fanout($expansion_targets);
        if ($status['max_expansion_fanout'] > $this->max_expansions_per_term) {
            $warnings[] = sprintf(
                'Maximum expansion fanout %d exceeds threshold %d.',
                $status['max_expansion_fanout'],
                $this->max_expansions_per_term
            );
        }

        $status['warnings'] = array_values(array_unique($warnings));
        $status['valid'] = $status['warnings'] === [];

        return $status;
    }

    /**
     * @return array{language_id:string,pack_version:string,pack_date:string,source_name:string,source_url:string,license_name:string,attribution_text:string,provenance:string,files:string[],data_kind:string}
     */
    private function empty_metadata(): array
    {
        return [
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
        ];
    }

    /**
     * @return array{metadata:array<string,mixed>,valid:bool,runtime_files_exist:bool,missing_files:string[]}
     */
    private function validate_pack_metadata(string $directory, string $expected_language, array &$warnings): array
    {
        $path = $directory . DIRECTORY_SEPARATOR . 'pack.php';
        $metadata = $this->empty_metadata();
        $missing_files = [];
        $valid = true;
        $runtime_files_exist = false;

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

        if ($metadata['data_kind'] !== '' && !in_array($metadata['data_kind'], ['curated_seed', 'imported_comprehensive'], true)) {
            $valid = false;
            $warnings[] = 'Language pack metadata data_kind must be curated_seed or imported_comprehensive in ' . $path;
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

        return [
            'metadata' => $metadata,
            'valid' => $valid,
            'runtime_files_exist' => $runtime_files_exist,
            'missing_files' => $missing_files,
        ];
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

        $synsets = $this->resolve_resource_path($directory, $resources, 'synsets', $profile_file, $warnings, false);
        if ($synsets !== null) {
            $paths['synsets'] = $synsets;
        }

        return $paths;
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

    private function validate_stopwords(string $path, array &$warnings): int
    {
        $count = 0;
        $seen = [];
        foreach ($this->resource_lines($path, $warnings) as $line_number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $token = $this->resource_token($line, $path, $line_number + 1, 'stopword', $warnings);
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

    private function validate_lexemes(string $path, array &$warnings): int
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

            $form = $this->resource_token($columns[0], $path, $line_number + 1, 'lexeme observed form', $warnings);
            $canonical = $this->resource_token($columns[1], $path, $line_number + 1, 'lexeme canonical key', $warnings);
            if ($form === null || $canonical === null) {
                continue;
            }

            if (array_key_exists(2, $columns) && trim($columns[2]) === '') {
                $warnings[] = $this->resource_error($path, $line_number + 1, 'lexeme provenance must be non-empty when present');
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
     * @param array<string,array<string,bool>> $expansion_targets
     * @return array{rows:int,expansions:int}
     */
    private function validate_pairwise_synonyms(string $path, array &$warnings, array &$expansion_targets): array
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

            $source = $this->resource_token($columns[0], $path, $line_number + 1, 'synonym source', $warnings);
            $target = $this->resource_token($columns[1], $path, $line_number + 1, 'synonym target', $warnings);
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
     * @return array{rows:int,expansions:int,max_synset_size:int}
     */
    private function validate_synsets(string $path, array &$warnings, array &$expansion_targets): array
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

            $terms = $this->parse_synset_terms($columns[3], $path, $line_number + 1, $warnings);
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
     * @return string[]|null
     */
    private function parse_synset_terms(string $terms_column, string $path, int $line_number, array &$warnings): array|null
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

            $term = $this->resource_token($term, $path, $line_number, 'synset term', $warnings);
            if ($term === null) {
                return null;
            }

            $lowercase = function_exists('mb_strtolower') ? mb_strtolower($term, 'UTF-8') : strtolower($term);
            if ($term !== $lowercase) {
                $warnings[] = $this->resource_error($path, $line_number, 'synset terms must be normalized lowercase resource tokens');

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

    private function validate_string_map(mixed $value, string $key, string $path, array &$warnings): void
    {
        if (!is_array($value)) {
            $warnings[] = "Language profile {$key} must be an array in {$path}";

            return;
        }

        foreach ($value as $from => $to) {
            if (!is_string($from) || !is_string($to) || $from === '' || $to === '') {
                $warnings[] = "Language profile {$key} must map non-empty strings in {$path}";

                return;
            }
        }
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
}
