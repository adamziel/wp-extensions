<?php
declare(strict_types=1);

/**
 * Loads curated language analyzer profiles from local resource files.
 *
 * File formats:
 *
 * - profile.php returns an array with id, label, optional order, optional
 *   normalization.fold map, optional language_signals regexes, and resource
 *   file names for stopwords, lexemes, synonyms, and optional synsets.
 * - stopwords.txt contains one already-normalized stopword per line. Empty
 *   lines and full-line comments beginning with "#" are ignored.
 * - lexemes.tsv contains "observed<TAB>canonical<TAB>provenance". The third
 *   column is optional. Observed forms and canonical keys must be non-empty
 *   whitespace-free normalized terms.
 * - synonyms.tsv contains
 *   "source<TAB>target<TAB>direction<TAB>weight<TAB>provenance". Direction is
 *   "query_to_index" or "bidirectional"; weight must be in (0, 1]. Pairwise
 *   rows are an explicit compatibility/override layer for targeted fixes.
 * - synsets.tsv contains "concept_id<TAB>weight<TAB>provenance<TAB>terms".
 *   Terms are single-space-separated normalized canonical keys. Each concept
 *   expands every listed key to every other listed key at query time.
 * - pack.php optionally returns provenance metadata for repository tooling and
 *   maintainers. Query-time profile loading does not read it.
 *
 * The repository parses each language lazily and caches the parsed profile for
 * the analyzer instance. Parsed stopwords, lexemes, and query expansions are
 * keyed maps so token lookup and query expansion do not scan whole resource
 * files.
 */
final class Language_FTS_Playground_Lexical_Profile_Repository
{
    private string $resource_root;

    /**
     * @var array<string,array{directory:string,profile:array<string,mixed>,order:int}>|null
     */
    private ?array $manifest = null;

    /**
     * @var array<string,array{id:string,label:string,folds:array<string,string>,language_signals:string[],stopwords:array<string,bool>,lexemes:array<string,string[]>,lexeme_forms:array<string,bool>,canonical_keys:array<string,bool>,synonym_sources:array<string,bool>,synonyms:array<string,array<int,array{term:string,weight:float,source:string,direction:string,provenance:string}>>}>
     */
    private array $profiles = [];

    public function __construct(string|null $resource_root = null)
    {
        $this->resource_root = self::normalize_resource_root($resource_root ?? self::default_resource_root());
    }

    public static function default_resource_root(): string
    {
        return self::normalize_resource_root(dirname(__DIR__) . '/resources/languages');
    }

    public static function normalize_resource_root(string $resource_root): string
    {
        $resource_root = trim($resource_root);
        if ($resource_root === '') {
            throw new InvalidArgumentException('Language profile resource root must be a non-empty local path.');
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $resource_root) === 1) {
            throw new InvalidArgumentException('Language profile resource root must be a local filesystem path, not a URL.');
        }

        $normalized = rtrim($resource_root, "/\\");
        if ($normalized === '' && $resource_root !== '') {
            return DIRECTORY_SEPARATOR;
        }

        if (preg_match('/^[A-Za-z]:$/', $normalized) === 1 && preg_match('/^[A-Za-z]:[\/\\\\]+$/', $resource_root) === 1) {
            return $normalized . DIRECTORY_SEPARATOR;
        }

        return $normalized;
    }

    public function resource_root(): string
    {
        return $this->resource_root;
    }

    /**
     * @return string[]
     */
    public function language_ids(): array
    {
        return array_keys($this->manifest());
    }

    public function has_language(string $language): bool
    {
        $language = $this->normalize_language_id($language);

        return $language !== null && isset($this->manifest()[$language]);
    }

    public function language_label(string $language): string
    {
        $entry = $this->manifest_entry($language);

        return $this->require_profile_string($entry['profile'], 'label', $this->profile_file($entry));
    }

    /**
     * @return string[]
     */
    public function language_signals(string $language): array
    {
        $entry = $this->manifest_entry($language);

        return $this->profile_string_list($entry['profile']['language_signals'] ?? [], 'language_signals', $this->profile_file($entry));
    }

    /**
     * @return array{id:string,label:string,folds:array<string,string>,language_signals:string[],stopwords:array<string,bool>,lexemes:array<string,string[]>,lexeme_forms:array<string,bool>,canonical_keys:array<string,bool>,synonym_sources:array<string,bool>,synonyms:array<string,array<int,array{term:string,weight:float,source:string,direction:string,provenance:string}>>}
     */
    public function profile(string $language): array
    {
        $entry = $this->manifest_entry($language);
        $language = (string) $entry['profile']['id'];

        if (!isset($this->profiles[$language])) {
            $this->profiles[$language] = $this->load_language_profile($language, $entry);
        }

        return $this->profiles[$language];
    }

    /**
     * Compact profile maps used for automatic query-language evidence.
     *
     * These maps are derived from the same runtime resources as analysis and
     * query expansion. They intentionally do not read pack metadata or source
     * import files.
     *
     * @return array{stopwords:array<string,bool>,lexemes:array<string,string[]>,lexeme_forms:array<string,bool>,canonical_keys:array<string,bool>,synonym_sources:array<string,bool>}
     */
    public function query_language_evidence(string $language): array
    {
        $profile = $this->profile($language);

        return [
            'stopwords' => $profile['stopwords'],
            'lexemes' => $profile['lexemes'],
            'lexeme_forms' => $profile['lexeme_forms'],
            'canonical_keys' => $profile['canonical_keys'],
            'synonym_sources' => $profile['synonym_sources'],
        ];
    }

    /**
     * Load optional pack provenance metadata for repository tooling.
     *
     * The analyzer does not call this during query-time profile loading. Keeping
     * metadata behind an explicit accessor lets resource packs carry source and
     * license details without adding work to every search request.
     *
     * @return array{language_id:string,pack_version:string,pack_date:string,source_name:string,source_url:string,license_name:string,attribution_text:string,provenance:string,files:string[],data_kind:string}
     */
    public function pack_metadata(string $language): array
    {
        $entry = $this->manifest_entry($language);
        $path = $entry['directory'] . DIRECTORY_SEPARATOR . 'pack.php';
        if (!is_file($path)) {
            throw new RuntimeException('Language pack metadata does not exist: ' . $path);
        }

        $metadata = require $path;
        if (!is_array($metadata)) {
            throw new UnexpectedValueException('Language pack metadata must return an array: ' . $path);
        }

        return $this->validate_pack_metadata($metadata, (string) $entry['profile']['id'], $entry['directory'], $path);
    }

    public function pack_fingerprint(): string
    {
        $payload = [
            'schema' => 'language-fts-playground-lexical-pack-fingerprint-v1',
            'resource_root' => $this->resource_root,
            'languages' => [],
        ];

        foreach ($this->language_ids() as $language) {
            $metadata = $this->pack_metadata($language);
            $payload['languages'][] = [
                'language_id' => $metadata['language_id'],
                'pack_version' => $metadata['pack_version'],
                'pack_date' => $metadata['pack_date'],
                'provenance' => $metadata['provenance'],
                'data_kind' => $metadata['data_kind'],
                'source_name' => $metadata['source_name'],
                'source_url' => $metadata['source_url'],
                'license_name' => $metadata['license_name'],
                'attribution_text' => $metadata['attribution_text'],
                'files' => array_values($metadata['files']),
            ];
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Could not encode lexical pack fingerprint payload.');
        }

        return hash('sha256', $json);
    }

    /**
     * @return array{directory:string,profile:array<string,mixed>,order:int}
     */
    private function manifest_entry(string $language): array
    {
        $language = $this->normalize_language_id($language);
        if ($language === null || !isset($this->manifest()[$language])) {
            throw new InvalidArgumentException('Unsupported lexical profile language.');
        }

        return $this->manifest()[$language];
    }

    /**
     * @return array<string,array{directory:string,profile:array<string,mixed>,order:int}>
     */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        if (!is_dir($this->resource_root)) {
            throw new RuntimeException('Language profile resource root does not exist: ' . $this->resource_root);
        }

        $entries = scandir($this->resource_root);
        if ($entries === false) {
            throw new RuntimeException('Could not read language profile resource root: ' . $this->resource_root);
        }

        $manifest = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $directory = $this->resource_root . DIRECTORY_SEPARATOR . $entry;
            $profile_file = $directory . DIRECTORY_SEPARATOR . 'profile.php';
            if (!is_dir($directory) || !is_file($profile_file)) {
                continue;
            }

            $profile = $this->read_profile_file($profile_file);
            $id = $this->require_profile_string($profile, 'id', $profile_file);
            if ($this->normalize_language_id($id) !== $id) {
                throw new UnexpectedValueException('Invalid language profile id in ' . $profile_file);
            }

            if ($id !== $entry) {
                throw new UnexpectedValueException('Language profile id must match its directory in ' . $profile_file);
            }

            if (isset($manifest[$id])) {
                throw new UnexpectedValueException('Duplicate language profile id: ' . $id);
            }

            $order = $profile['order'] ?? 1000;
            if (!is_int($order)) {
                throw new UnexpectedValueException('Language profile order must be an integer in ' . $profile_file);
            }

            $manifest[$id] = [
                'directory' => $directory,
                'profile' => $profile,
                'order' => $order,
            ];
        }

        uasort(
            $manifest,
            static fn(array $a, array $b): int => ($a['order'] <=> $b['order'])
                ?: strcmp((string) ($a['profile']['id'] ?? ''), (string) ($b['profile']['id'] ?? ''))
        );

        if ($manifest === []) {
            throw new RuntimeException('No language lexical profiles were found in: ' . $this->resource_root);
        }

        $this->manifest = $manifest;

        return $manifest;
    }

    /**
     * @param array{directory:string,profile:array<string,mixed>,order:int} $manifest_entry
     * @return array{id:string,label:string,folds:array<string,string>,language_signals:string[],stopwords:array<string,bool>,lexemes:array<string,string[]>,lexeme_forms:array<string,bool>,canonical_keys:array<string,bool>,synonym_sources:array<string,bool>,synonyms:array<string,array<int,array{term:string,weight:float,source:string,direction:string,provenance:string}>>}
     */
    private function load_language_profile(string $language, array $manifest_entry): array
    {
        $profile = $manifest_entry['profile'];
        $directory = $manifest_entry['directory'];
        $profile_file = $directory . DIRECTORY_SEPARATOR . 'profile.php';
        $resources = $profile['resources'] ?? [];
        if (!is_array($resources)) {
            throw new UnexpectedValueException('Language profile resources must be an array in ' . $profile_file);
        }

        $synset_expansions = isset($resources['synsets'])
            ? $this->parse_synsets($this->resource_path($directory, $resources, 'synsets', $profile_file))
            : [];
        $pairwise_synonyms = $this->parse_synonyms($this->resource_path($directory, $resources, 'synonyms', $profile_file));
        $lexemes = $this->parse_lexemes($this->resource_path($directory, $resources, 'lexemes', $profile_file));
        $synonyms = $this->merge_expansion_maps($synset_expansions, $pairwise_synonyms);

        return [
            'id' => $language,
            'label' => $this->language_label($language),
            'folds' => $this->profile_string_map($profile['normalization']['fold'] ?? [], 'normalization.fold', $profile_file),
            'language_signals' => $this->language_signals($language),
            'stopwords' => $this->parse_stopwords($this->resource_path($directory, $resources, 'stopwords', $profile_file)),
            'lexemes' => $lexemes,
            'lexeme_forms' => $this->lookup_from_keys(array_keys($lexemes)),
            'canonical_keys' => $this->canonical_key_lookup($lexemes),
            'synonym_sources' => $this->lookup_from_keys(array_keys($synonyms)),
            'synonyms' => $synonyms,
        ];
    }

    /**
     * @param array{directory:string,profile:array<string,mixed>,order:int} $entry
     */
    private function profile_file(array $entry): string
    {
        return $entry['directory'] . DIRECTORY_SEPARATOR . 'profile.php';
    }

    /**
     * @return array<string,mixed>
     */
    private function read_profile_file(string $profile_file): array
    {
        $profile = require $profile_file;
        if (!is_array($profile)) {
            throw new UnexpectedValueException('Language profile must return an array: ' . $profile_file);
        }

        return $profile;
    }

    /**
     * @param array<mixed> $metadata
     * @return array{language_id:string,pack_version:string,pack_date:string,source_name:string,source_url:string,license_name:string,attribution_text:string,provenance:string,files:string[],data_kind:string}
     */
    private function validate_pack_metadata(array $metadata, string $expected_language, string $directory, string $path): array
    {
        $required = [
            'language_id',
            'pack_version',
            'pack_date',
            'source_name',
            'source_url',
            'license_name',
            'attribution_text',
            'provenance',
            'data_kind',
        ];
        $validated = [];
        foreach ($required as $key) {
            $value = $metadata[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                throw new UnexpectedValueException("Language pack metadata {$key} must be a non-empty string in {$path}");
            }
            $validated[$key] = trim($value);
        }

        if ($validated['language_id'] !== $expected_language) {
            throw new UnexpectedValueException('Language pack metadata language_id must match its profile in ' . $path);
        }

        if (!in_array($validated['data_kind'], ['curated_seed', 'imported_comprehensive'], true)) {
            throw new UnexpectedValueException('Language pack metadata data_kind must be curated_seed or imported_comprehensive in ' . $path);
        }

        $files = $metadata['files'] ?? null;
        if (!is_array($files) || $files === []) {
            throw new UnexpectedValueException('Language pack metadata files must be a non-empty array in ' . $path);
        }

        $validated_files = [];
        foreach ($files as $file) {
            if (!is_string($file) || trim($file) === '') {
                throw new UnexpectedValueException('Language pack metadata files must contain non-empty strings in ' . $path);
            }

            $file = trim($file);
            if ($file !== basename($file) || str_contains($file, '..')) {
                throw new UnexpectedValueException('Language pack metadata files must be local file names in ' . $path);
            }

            if (!is_file($directory . DIRECTORY_SEPARATOR . $file)) {
                throw new RuntimeException('Language pack metadata file does not exist: ' . $directory . DIRECTORY_SEPARATOR . $file);
            }

            $validated_files[] = $file;
        }

        return [
            'language_id' => $validated['language_id'],
            'pack_version' => $validated['pack_version'],
            'pack_date' => $validated['pack_date'],
            'source_name' => $validated['source_name'],
            'source_url' => $validated['source_url'],
            'license_name' => $validated['license_name'],
            'attribution_text' => $validated['attribution_text'],
            'provenance' => $validated['provenance'],
            'files' => $validated_files,
            'data_kind' => $validated['data_kind'],
        ];
    }

    /**
     * @param array<string,mixed> $profile
     */
    private function require_profile_string(array $profile, string $key, string $profile_file): string
    {
        $value = $profile[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new UnexpectedValueException("Language profile {$key} must be a non-empty string in {$profile_file}");
        }

        return trim($value);
    }

    /**
     * @param mixed $value
     * @return array<string,string>
     */
    private function profile_string_map(mixed $value, string $key, string $profile_file): array
    {
        if (!is_array($value)) {
            throw new UnexpectedValueException("Language profile {$key} must be an array in {$profile_file}");
        }

        $map = [];
        foreach ($value as $from => $to) {
            if (!is_string($from) || !is_string($to) || $from === '' || $to === '') {
                throw new UnexpectedValueException("Language profile {$key} must map non-empty strings in {$profile_file}");
            }
            $map[$from] = $to;
        }

        return $map;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private function profile_string_list(mixed $value, string $key, string $profile_file): array
    {
        if (!is_array($value)) {
            throw new UnexpectedValueException("Language profile {$key} must be an array in {$profile_file}");
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new UnexpectedValueException("Language profile {$key} must contain non-empty strings in {$profile_file}");
            }
            $items[] = trim($item);
        }

        return $items;
    }

    /**
     * @param array<mixed> $resources
     */
    private function resource_path(string $directory, array $resources, string $key, string $profile_file): string
    {
        $name = $resources[$key] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new UnexpectedValueException("Language profile resource {$key} must be declared in {$profile_file}");
        }

        $name = trim($name);
        if ($name !== basename($name) || str_contains($name, '..')) {
            throw new UnexpectedValueException("Language profile resource {$key} must be a local file name in {$profile_file}");
        }

        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) {
            throw new RuntimeException("Language profile resource {$key} does not exist: {$path}");
        }

        return $path;
    }

    /**
     * @return array<string,bool>
     */
    private function parse_stopwords(string $path): array
    {
        $stopwords = [];
        foreach ($this->resource_lines($path) as $line_number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $stopwords[$this->resource_token($line, $path, $line_number + 1)] = true;
        }

        return $stopwords;
    }

    /**
     * @return array<string,string[]>
     */
    private function parse_lexemes(string $path): array
    {
        $lexemes = [];
        foreach ($this->resource_lines($path) as $line_number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $columns = explode("\t", $line);
            if (count($columns) < 2 || count($columns) > 3) {
                throw new UnexpectedValueException($this->resource_error($path, $line_number + 1, 'lexeme rows must have 2 or 3 tab-separated columns'));
            }

            $form = $this->resource_token($columns[0], $path, $line_number + 1);
            $canonical = $this->resource_token($columns[1], $path, $line_number + 1);
            $lexemes[$form][$canonical] = true;
        }

        ksort($lexemes, SORT_STRING);
        foreach ($lexemes as $form => $canonical_keys) {
            $keys = array_keys($canonical_keys);
            sort($keys, SORT_STRING);
            $lexemes[$form] = $keys;
        }

        return $lexemes;
    }

    /**
     * @return array<string,array<int,array{term:string,weight:float,source:string,direction:string,provenance:string}>>
     */
    private function parse_synonyms(string $path): array
    {
        $synonyms = [];
        foreach ($this->resource_lines($path) as $line_number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $columns = explode("\t", $line);
            if (count($columns) !== 5) {
                throw new UnexpectedValueException($this->resource_error($path, $line_number + 1, 'synonym rows must have exactly 5 tab-separated columns'));
            }

            $source = $this->resource_token($columns[0], $path, $line_number + 1);
            $target = $this->resource_token($columns[1], $path, $line_number + 1);
            if ($source === $target) {
                throw new UnexpectedValueException($this->resource_error($path, $line_number + 1, 'synonym source and target must differ'));
            }

            $direction = trim($columns[2]);
            if (!in_array($direction, ['query_to_index', 'bidirectional'], true)) {
                throw new UnexpectedValueException($this->resource_error($path, $line_number + 1, 'synonym direction must be query_to_index or bidirectional'));
            }

            $weight_raw = trim($columns[3]);
            if (!is_numeric($weight_raw)) {
                throw new UnexpectedValueException($this->resource_error($path, $line_number + 1, 'synonym weight must be numeric'));
            }

            $weight = (float) $weight_raw;
            if ($weight <= 0.0 || $weight > 1.0) {
                throw new UnexpectedValueException($this->resource_error($path, $line_number + 1, 'synonym weight must be greater than 0 and no more than 1'));
            }

            $provenance = trim($columns[4]);
            if ($provenance === '') {
                throw new UnexpectedValueException($this->resource_error($path, $line_number + 1, 'synonym provenance must be non-empty'));
            }

            $this->add_synonym($synonyms, $source, $target, $direction, $weight, $provenance, $path, $line_number + 1);
            if ($direction === 'bidirectional') {
                $this->add_synonym($synonyms, $target, $source, $direction, $weight, $provenance, $path, $line_number + 1);
            }
        }

        return $this->finalize_expansion_map($synonyms);
    }

    /**
     * @return array<string,array<int,array{term:string,weight:float,source:string,direction:string,provenance:string}>>
     */
    private function parse_synsets(string $path): array
    {
        $synsets = [];
        $concept_ids = [];
        foreach ($this->resource_lines($path) as $line_number => $line) {
            $trimmed_line = trim($line);
            if ($trimmed_line === '' || str_starts_with($trimmed_line, '#')) {
                continue;
            }

            $line_number++;
            $columns = explode("\t", $line);
            if (count($columns) !== 4) {
                throw new UnexpectedValueException($this->resource_error($path, $line_number, 'synset rows must have exactly 4 tab-separated columns'));
            }

            $concept_id = $this->resource_token($columns[0], $path, $line_number);
            if (isset($concept_ids[$concept_id])) {
                throw new UnexpectedValueException($this->resource_error($path, $line_number, 'duplicate synset concept id'));
            }
            $concept_ids[$concept_id] = true;

            $weight = $this->resource_weight($columns[1], $path, $line_number, 'synset');
            $provenance = trim($columns[2]);
            if ($provenance === '') {
                throw new UnexpectedValueException($this->resource_error($path, $line_number, 'synset provenance must be non-empty'));
            }

            $terms = $this->parse_synset_terms($columns[3], $path, $line_number);
            if (count($terms) < 2) {
                throw new UnexpectedValueException($this->resource_error($path, $line_number, 'synset rows must contain at least 2 terms'));
            }

            foreach ($terms as $source) {
                foreach ($terms as $target) {
                    if ($source === $target) {
                        continue;
                    }

                    $this->add_synset_expansion($synsets, $source, $target, $weight, $provenance);
                }
            }
        }

        return $this->finalize_expansion_map($synsets);
    }

    /**
     * @param array<string,array<string,array{term:string,weight:float,source:string,direction:string,provenance:string}>> $synonyms
     */
    private function add_synonym(
        array &$synonyms,
        string $source,
        string $target,
        string $direction,
        float $weight,
        string $provenance,
        string $path,
        int $line_number
    ): void {
        if (isset($synonyms[$source][$target])) {
            throw new UnexpectedValueException($this->resource_error($path, $line_number, 'duplicate synonym source/target pair'));
        }

        $synonyms[$source][$target] = [
            'term' => $target,
            'weight' => $weight,
            'source' => $source,
            'direction' => $direction,
            'provenance' => $provenance,
        ];
    }

    /**
     * @param array<string,array<string,array{term:string,weight:float,source:string,direction:string,provenance:string}>> $synsets
     */
    private function add_synset_expansion(
        array &$synsets,
        string $source,
        string $target,
        float $weight,
        string $provenance
    ): void {
        $existing = $synsets[$source][$target] ?? null;
        if (
            is_array($existing) &&
            (
                (float) $existing['weight'] > $weight ||
                ((float) $existing['weight'] === $weight && strcmp((string) $existing['provenance'], $provenance) <= 0)
            )
        ) {
            return;
        }

        $synsets[$source][$target] = [
            'term' => $target,
            'weight' => $weight,
            'source' => $source,
            'direction' => 'synset',
            'provenance' => $provenance,
        ];
    }

    /**
     * @param array<string,array<int,array{term:string,weight:float,source:string,direction:string,provenance:string}>> $base
     * @param array<string,array<int,array{term:string,weight:float,source:string,direction:string,provenance:string}>> $overrides
     * @return array<string,array<int,array{term:string,weight:float,source:string,direction:string,provenance:string}>>
     */
    private function merge_expansion_maps(array $base, array $overrides): array
    {
        $merged = [];
        foreach ([$base, $overrides] as $map) {
            foreach ($map as $source => $expansions) {
                foreach ($expansions as $expansion) {
                    $target = (string) ($expansion['term'] ?? '');
                    if ($source === '' || $target === '' || $source === $target) {
                        continue;
                    }

                    $merged[(string) $source][$target] = [
                        'term' => $target,
                        'weight' => (float) $expansion['weight'],
                        'source' => (string) $source,
                        'direction' => (string) $expansion['direction'],
                        'provenance' => (string) $expansion['provenance'],
                    ];
                }
            }
        }

        return $this->finalize_expansion_map($merged);
    }

    /**
     * @param string[] $keys
     * @return array<string,bool>
     */
    private function lookup_from_keys(array $keys): array
    {
        $lookup = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            if ($key !== '') {
                $lookup[$key] = true;
            }
        }

        ksort($lookup, SORT_STRING);

        return $lookup;
    }

    /**
     * @param array<string,string[]> $lexemes
     * @return array<string,bool>
     */
    private function canonical_key_lookup(array $lexemes): array
    {
        $keys = [];
        foreach ($lexemes as $canonical_terms) {
            foreach ($canonical_terms as $canonical) {
                $keys[(string) $canonical] = true;
            }
        }

        ksort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * @param array<string,array<string,array{term:string,weight:float,source:string,direction:string,provenance:string}>> $expansions
     * @return array<string,array<int,array{term:string,weight:float,source:string,direction:string,provenance:string}>>
     */
    private function finalize_expansion_map(array $expansions): array
    {
        ksort($expansions, SORT_STRING);
        foreach ($expansions as $source => $targets) {
            ksort($targets, SORT_STRING);
            $expansions[$source] = array_values($targets);
        }

        return $expansions;
    }

    /**
     * @return string[]
     */
    private function parse_synset_terms(string $terms_column, string $path, int $line_number): array
    {
        if (trim($terms_column) === '') {
            throw new UnexpectedValueException($this->resource_error($path, $line_number, 'synset terms must be non-empty'));
        }

        if ($terms_column !== trim($terms_column) || str_contains($terms_column, '  ')) {
            throw new UnexpectedValueException($this->resource_error($path, $line_number, 'synset terms must be separated by single spaces'));
        }

        $terms = [];
        foreach (explode(' ', $terms_column) as $term) {
            if ($term === '') {
                throw new UnexpectedValueException($this->resource_error($path, $line_number, 'synset terms must be separated by single spaces'));
            }

            $term = $this->normalized_resource_token($term, $path, $line_number, 'synset terms');
            if (isset($terms[$term])) {
                throw new UnexpectedValueException($this->resource_error($path, $line_number, 'duplicate synset term'));
            }
            $terms[$term] = true;
        }

        return array_keys($terms);
    }

    private function resource_weight(string $value, string $path, int $line_number, string $label): float
    {
        $weight_raw = trim($value);
        if (!is_numeric($weight_raw)) {
            throw new UnexpectedValueException($this->resource_error($path, $line_number, "{$label} weight must be numeric"));
        }

        $weight = (float) $weight_raw;
        if ($weight <= 0.0 || $weight > 1.0) {
            throw new UnexpectedValueException($this->resource_error($path, $line_number, "{$label} weight must be greater than 0 and no more than 1"));
        }

        return $weight;
    }

    /**
     * @return string[]
     */
    private function resource_lines(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Could not read language profile resource: ' . $path);
        }

        return $lines;
    }

    private function resource_token(string $value, string $path, int $line_number): string
    {
        $token = trim($value);
        if ($token === '') {
            throw new UnexpectedValueException($this->resource_error($path, $line_number, 'resource token must be non-empty'));
        }

        $has_whitespace = preg_match('/\s/u', $token);
        if ($has_whitespace === false || $has_whitespace === 1 || str_contains($token, '#')) {
            throw new UnexpectedValueException($this->resource_error($path, $line_number, 'resource tokens must not contain whitespace or #'));
        }

        if (strlen($token) > 255) {
            throw new UnexpectedValueException($this->resource_error($path, $line_number, 'resource tokens must be 255 bytes or shorter'));
        }

        return $token;
    }

    private function normalized_resource_token(string $value, string $path, int $line_number, string $label): string
    {
        $token = $this->resource_token($value, $path, $line_number);
        $lowercase = function_exists('mb_strtolower') ? mb_strtolower($token, 'UTF-8') : strtolower($token);
        if ($token !== $lowercase) {
            throw new UnexpectedValueException($this->resource_error($path, $line_number, "{$label} must be normalized lowercase resource tokens"));
        }

        return $token;
    }

    private function resource_error(string $path, int $line_number, string $message): string
    {
        return "{$path}:{$line_number}: {$message}";
    }

    private function normalize_language_id(string $language): ?string
    {
        $language = strtolower(trim($language));
        if ($language === '') {
            return null;
        }

        return preg_match('/^[a-z0-9-]+$/', $language) === 1 ? $language : null;
    }
}
