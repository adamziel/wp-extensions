<?php
declare(strict_types=1);

/**
 * Opt-in dictionary lemmatizer backed by a validated local analyzer pack.
 *
 * The adapter consumes normalized surface-to-lemma runtime rows. It
 * deliberately no-ops unsupported language partitions, ambiguous surfaces, and
 * missing forms so partial fixture packs cannot over-stem terms.
 */
final class WP_FTS_LanguageLemmaPack implements WP_FTS_Stemmer
{
    private const EAGER_ROW_LIMIT = 50000;
    private const MAX_CACHED_LOOKUPS = 512;

    /** @var array<string,string> */
    private array $lemmaBySurface = [];
    /** @var array<string,bool> */
    private array $ambiguousSurfaces = [];
    /** @var array<string,string> */
    private array $lookupCache = [];
    /** @var string[] */
    private array $lookupCacheOrder = [];
    private bool $lazy;
    private string $indexSignature;
    private string $packLanguage;

    /**
     * @param array<string,mixed> $validation Result from WP_FTS_AnalyzerPackValidator::validate().
     */
    private function __construct(private array $validation, bool $lazy)
    {
        $this->lazy = $lazy;
        $this->packLanguage = self::base_language((string) $validation['manifest']['language']);
        if (!$lazy) {
            $this->build_eager_lookup($validation['rows']);
        }

        $this->indexSignature = $this->build_index_signature($validation);
    }

    /**
     * Load a lemmatizer from one manifest file.
     */
    public static function from_manifest_file(
        string $manifestPath,
        ?WP_FTS_AnalyzerPackValidator $validator = null,
        ?string $expectedLanguage = null
    ): self {
        $validator ??= new WP_FTS_AnalyzerPackValidator();
        $metadata = $validator->validate_metadata($manifestPath, false);
        self::assert_expected_language($metadata, $expectedLanguage);
        $eager = (bool) $metadata['manifest']['fixture_only'] && self::runtime_rows_count($metadata) <= self::EAGER_ROW_LIMIT;
        if ($eager) {
            $validation = $validator->validate($manifestPath, true);
            self::assert_expected_language($validation, $expectedLanguage);
            if (($validation['rows_collected'] ?? true) === true) {
                return new self($validation, false);
            }
        }

        return new self($metadata, true);
    }

    /**
     * Try to load a lemmatizer from the public analyzer option shape.
     *
     * Invalid or missing packs return null so callers can fall back to the
     * language's existing analyzer path without making indexing/search fatal.
     */
    public static function from_pack_option(
        mixed $option,
        ?string $expectedLanguage = null,
        ?string $defaultManifestPath = null
    ): ?self {
        $manifestPath = self::manifest_path_from_option($option, $defaultManifestPath);
        if ($manifestPath === null) {
            return null;
        }

        try {
            return self::from_manifest_file($manifestPath, null, $expectedLanguage);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Lemmatize one normalized token for the pack language.
     */
    public function stem(string $term, string $language): string
    {
        if (self::base_language($language) !== $this->packLanguage) {
            return $term;
        }

        if ($this->lazy) {
            return $this->lookup_lazy($term);
        }

        if (isset($this->ambiguousSurfaces[$term])) {
            return $term;
        }

        return $this->lemmaBySurface[$term] ?? $term;
    }

    /**
     * Return a stable analyzer signature component for stale-document checks.
     */
    public function index_signature(): string
    {
        return $this->indexSignature;
    }

    /**
     * Expose the manifest language for tests and diagnostics.
     */
    public function language(): string
    {
        return (string) $this->validation['manifest']['language'];
    }

    /**
     * Expose the base manifest language used for runtime routing.
     */
    public function base_language_code(): string
    {
        return $this->packLanguage;
    }

    /**
     * Expose pack identity for tests and diagnostics.
     */
    public function pack_id(): string
    {
        return (string) $this->validation['manifest']['pack_id'];
    }

    /**
     * Expose fixture-only status for tests and diagnostics.
     */
    public function is_fixture_only(): bool
    {
        return (bool) $this->validation['manifest']['fixture_only'];
    }

    private static function manifest_path_from_option(mixed $option, ?string $defaultManifestPath): ?string
    {
        if ($option === false || $option === null) {
            return null;
        }

        if (is_string($option)) {
            $option = trim($option);
            if ($option === '' || in_array(strtolower($option), ['0', 'false', 'no', 'off'], true)) {
                return null;
            }

            return is_dir($option) ? $option . DIRECTORY_SEPARATOR . 'manifest.json' : $option;
        }

        if ($option === true) {
            return $defaultManifestPath;
        }

        if (is_array($option)) {
            foreach (['manifest', 'manifest_path', 'path'] as $key) {
                if (!isset($option[$key]) || !is_scalar($option[$key])) {
                    continue;
                }
                $path = trim((string) $option[$key]);
                if ($path === '') {
                    continue;
                }

                return is_dir($path) ? $path . DIRECTORY_SEPARATOR . 'manifest.json' : $path;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $validation
     */
    private static function assert_expected_language(array $validation, ?string $expectedLanguage): void
    {
        if ($expectedLanguage === null || trim($expectedLanguage) === '') {
            return;
        }

        $expected = self::base_language($expectedLanguage);
        $actual = self::base_language((string) $validation['manifest']['language']);
        if ($expected !== $actual) {
            throw new RuntimeException("Analyzer pack language {$actual} does not match requested language {$expected}.");
        }
    }

    /**
     * @param array<string,mixed> $validation
     */
    private static function runtime_rows_count(array $validation): int
    {
        $rows = 0;
        foreach ($validation['runtime_files'] as $file) {
            $rows += (int) $file['rows'];
        }

        return $rows;
    }

    /**
     * @param array<int,array{surface:string,lemma:string,file:string,line:int}> $rows
     */
    private function build_eager_lookup(array $rows): void
    {
        $lemmasBySurface = [];
        foreach ($rows as $row) {
            $lemmasBySurface[$row['surface']][$row['lemma']] = true;
        }

        foreach ($lemmasBySurface as $surface => $lemmas) {
            $lemmaList = array_keys($lemmas);
            sort($lemmaList, SORT_STRING);
            if (count($lemmaList) === 1) {
                $this->lemmaBySurface[$surface] = $lemmaList[0];
                continue;
            }

            $this->ambiguousSurfaces[$surface] = true;
        }
    }

    private function lookup_lazy(string $term): string
    {
        if (isset($this->lookupCache[$term])) {
            return $this->lookupCache[$term];
        }

        $lemmas = [];
        foreach ($this->candidate_runtime_files($term) as $file) {
            foreach ($this->lookup_term_in_runtime_file($term, $file) as $lemma => $_) {
                $lemmas[$lemma] = true;
                if (count($lemmas) > 1) {
                    return $this->cache_lookup($term, $term);
                }
            }
        }

        if (count($lemmas) !== 1) {
            return $this->cache_lookup($term, $term);
        }

        return $this->cache_lookup($term, (string) array_key_first($lemmas));
    }

    /**
     * @return array<int,array{path:string,rows:int,sha256:string,compression?:string,first_surface?:string,last_surface?:string}>
     */
    private function candidate_runtime_files(string $term): array
    {
        $candidates = [];
        foreach ($this->validation['runtime_files'] as $file) {
            $first = $file['first_surface'] ?? null;
            $last = $file['last_surface'] ?? null;
            if (is_string($first) && is_string($last) && (strcmp($term, $first) < 0 || strcmp($term, $last) > 0)) {
                continue;
            }
            $candidates[] = $file;
        }

        return $candidates;
    }

    /**
     * @param array{path:string,rows:int,sha256:string,compression?:string,first_surface?:string,last_surface?:string} $file
     * @return array<string,bool>
     */
    private function lookup_term_in_runtime_file(string $term, array $file): array
    {
        $compression = isset($file['compression']) ? (string) $file['compression'] : null;
        $handle = $this->open_runtime_file((string) $file['path'], $compression);
        if (!is_resource($handle)) {
            return [];
        }

        $lemmas = [];
        try {
            while (($line = $this->read_runtime_line($handle, $compression)) !== false) {
                $line = rtrim((string) $line, "\n");
                $line = rtrim($line, "\r");
                if ($line === '' || $line[0] === '#') {
                    continue;
                }

                $columns = explode("\t", $line, 3);
                if (count($columns) !== 2) {
                    continue;
                }

                $surface = $columns[0];
                $comparison = strcmp($surface, $term);
                if ($comparison < 0) {
                    continue;
                }
                if ($comparison > 0) {
                    break;
                }

                $lemmas[$columns[1]] = true;
            }
        } finally {
            $this->close_runtime_file($handle, $compression);
        }

        return $lemmas;
    }

    private function cache_lookup(string $term, string $result): string
    {
        if (!isset($this->lookupCache[$term])) {
            $this->lookupCacheOrder[] = $term;
        }
        $this->lookupCache[$term] = $result;

        while (count($this->lookupCacheOrder) > self::MAX_CACHED_LOOKUPS) {
            $oldest = array_shift($this->lookupCacheOrder);
            if (is_string($oldest)) {
                unset($this->lookupCache[$oldest]);
            }
        }

        return $result;
    }

    /**
     * Open a runtime shard without materializing the full compressed pack.
     *
     * @return resource|null
     */
    private function open_runtime_file(string $path, ?string $compression): mixed
    {
        if ($compression === WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP) {
            if (!WP_FTS_AnalyzerPackValidator::gzip_available()) {
                return null;
            }
            $handle = gzopen($path, 'rb');

            return is_resource($handle) ? $handle : null;
        }

        $handle = fopen($path, 'rb');

        return is_resource($handle) ? $handle : null;
    }

    /**
     * @param resource $handle
     */
    private function read_runtime_line(mixed $handle, ?string $compression): string|false
    {
        if ($compression === WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP) {
            return gzgets($handle);
        }

        return fgets($handle);
    }

    /**
     * @param resource $handle
     */
    private function close_runtime_file(mixed $handle, ?string $compression): void
    {
        if ($compression === WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP) {
            gzclose($handle);
            return;
        }

        fclose($handle);
    }

    /**
     * @param array<string,mixed> $validation
     */
    private function build_index_signature(array $validation): string
    {
        $runtime = [];
        foreach ($validation['runtime_files'] as $relativePath => $file) {
            $runtime[$relativePath] = [
                'sha256' => $file['sha256'],
                'rows' => $file['rows'],
            ];
            if (isset($file['compression'])) {
                $runtime[$relativePath]['compression'] = $file['compression'];
            }
        }
        ksort($runtime, SORT_STRING);

        $payload = [
            'contract' => 'wp-fts-language-lemma-pack',
            'version' => 1,
            'pack_id' => (string) $validation['manifest']['pack_id'],
            'pack_version' => (string) $validation['manifest']['version'],
            'language' => (string) $validation['manifest']['language'],
            'fixture_only' => (bool) $validation['manifest']['fixture_only'],
            'manifest_sha256' => (string) $validation['manifest_sha256'],
            'runtime_format' => (string) $validation['manifest']['runtime']['format'],
            'runtime' => $runtime,
        ];

        return 'wp-fts-language-lemma-pack-v1:' . sha1($this->stable_json($payload));
    }

    /**
     * Encode arrays in a stable order for signatures.
     */
    private function stable_json(mixed $value): string
    {
        if (is_array($value)) {
            if (array_keys($value) !== range(0, count($value) - 1)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $child) {
                $value[$key] = $this->stable_json_value($child);
            }
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function stable_json_value(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->stable_json_value($child);
        }

        return $value;
    }

    /**
     * Reduce a language tag to the lower-case primary language subtag.
     */
    private static function base_language(string $language): string
    {
        $language = strtolower(str_replace('_', '-', trim($language)));
        $separator = strpos($language, '-');

        return $separator === false ? $language : substr($language, 0, $separator);
    }
}
