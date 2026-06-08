<?php
declare(strict_types=1);

/**
 * Loads curated language analyzer profiles from plugin-local resource files.
 *
 * File formats:
 *
 * - profile.php returns an array with id, label, optional order, optional
 *   normalization.fold map, optional language_signals regexes, and resource
 *   file names for stopwords, lexemes, and synonyms.
 * - stopwords.txt contains one already-normalized stopword per line. Empty
 *   lines and full-line comments beginning with "#" are ignored.
 * - lexemes.tsv contains "observed<TAB>canonical<TAB>provenance". The third
 *   column is optional. Observed forms and canonical keys must be non-empty
 *   whitespace-free normalized terms.
 * - synonyms.tsv contains
 *   "source<TAB>target<TAB>direction<TAB>weight<TAB>provenance". Direction is
 *   "query_to_index" or "bidirectional"; weight must be in (0, 1].
 *
 * The repository parses each language lazily and caches the parsed profile for
 * the analyzer instance. Parsed stopwords, lexemes, and synonyms are keyed maps
 * so token lookup and query expansion do not scan whole resource files.
 */
final class Language_FTS_Playground_Lexical_Profile_Repository
{
    private string $resource_root;

    /**
     * @var array<string,array{directory:string,profile:array<string,mixed>,order:int}>|null
     */
    private ?array $manifest = null;

    /**
     * @var array<string,array{id:string,label:string,folds:array<string,string>,language_signals:string[],stopwords:array<string,bool>,lexemes:array<string,string[]>,synonyms:array<string,array<int,array{term:string,weight:float,source:string,direction:string,provenance:string}>>}>
     */
    private array $profiles = [];

    public function __construct(string|null $resource_root = null)
    {
        $this->resource_root = rtrim($resource_root ?? dirname(__DIR__) . '/resources/languages', DIRECTORY_SEPARATOR);
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
     * @return array{id:string,label:string,folds:array<string,string>,language_signals:string[],stopwords:array<string,bool>,lexemes:array<string,string[]>,synonyms:array<string,array<int,array{term:string,weight:float,source:string,direction:string,provenance:string}>>}
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
     * @return array{id:string,label:string,folds:array<string,string>,language_signals:string[],stopwords:array<string,bool>,lexemes:array<string,string[]>,synonyms:array<string,array<int,array{term:string,weight:float,source:string,direction:string,provenance:string}>>}
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

        return [
            'id' => $language,
            'label' => $this->language_label($language),
            'folds' => $this->profile_string_map($profile['normalization']['fold'] ?? [], 'normalization.fold', $profile_file),
            'language_signals' => $this->language_signals($language),
            'stopwords' => $this->parse_stopwords($this->resource_path($directory, $resources, 'stopwords', $profile_file)),
            'lexemes' => $this->parse_lexemes($this->resource_path($directory, $resources, 'lexemes', $profile_file)),
            'synonyms' => $this->parse_synonyms($this->resource_path($directory, $resources, 'synonyms', $profile_file)),
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

        ksort($synonyms, SORT_STRING);
        foreach ($synonyms as $source => $targets) {
            ksort($targets, SORT_STRING);
            $synonyms[$source] = array_values($targets);
        }

        return $synonyms;
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
