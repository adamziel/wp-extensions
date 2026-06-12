<?php
declare(strict_types=1);

/**
 * Opt-in Polish dictionary lemmatizer backed by a validated local fixture pack.
 *
 * The adapter consumes normalized runtime rows. It deliberately no-ops
 * ambiguous and missing forms so a partial fixture pack cannot over-stem terms.
 */
final class WP_FTS_PolishMorfologikLemmatizer implements WP_FTS_Stemmer
{
    private const EAGER_ROW_LIMIT = 50000;
    private const MAX_LAZY_LOOKUP_CACHE_ENTRIES = 2048;

    /** @var array<string,string> */
    private array $lemmaBySurface = [];
    /** @var array<string,bool> */
    private array $ambiguousSurfaces = [];
    /** @var array<string,string> */
    private array $lazyLookupCache = [];
    /** @var string[] */
    private array $lazyLookupCacheOrder = [];
    private bool $lazy;
    private string $indexSignature;

    /**
     * @param array<string,mixed> $validation Result from WP_FTS_AnalyzerPackValidator::validate().
     */
    private function __construct(private array $validation, bool $lazy)
    {
        $this->lazy = $lazy;
        if (!$lazy) {
            $this->build_eager_lookup($validation['rows']);
        }

        $this->indexSignature = $this->build_index_signature($validation);
    }

    /**
     * Load a lemmatizer from one manifest file.
     */
    public static function from_manifest_file(string $manifestPath, ?WP_FTS_AnalyzerPackValidator $validator = null): self
    {
        $validator ??= new WP_FTS_AnalyzerPackValidator();
        $metadata = $validator->validate_metadata($manifestPath, false);
        $eager = (bool) $metadata['manifest']['fixture_only'] && self::runtime_rows_count($metadata) <= self::EAGER_ROW_LIMIT;
        if ($eager) {
            $validation = $validator->validate($manifestPath, true);
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
     * conservative Polish suffix stemmer without making indexing/search fatal.
     */
    public static function from_pack_option(mixed $option): ?self
    {
        $manifestPath = self::manifest_path_from_option($option);
        if ($manifestPath === null) {
            return null;
        }

        try {
            return self::from_manifest_file($manifestPath);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Lemmatize one normalized Polish token.
     */
    public function stem(string $term, string $language): string
    {
        if ($this->base_language($language) !== 'pl') {
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

    private static function manifest_path_from_option(mixed $option): ?string
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
            return WP_FTS_AnalyzerPackValidator::default_polish_fixture_manifest();
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
        if (array_key_exists($term, $this->lazyLookupCache)) {
            return $this->lazyLookupCache[$term];
        }

        $lemmas = [];
        foreach ($this->candidate_runtime_files($term) as $file) {
            foreach ($this->lookup_runtime_file($file, $term) as $lemma) {
                $lemmas[$lemma] = true;
                if (count($lemmas) > 1) {
                    $this->cache_lazy_lookup($term, $term);

                    return $term;
                }
            }
        }

        if (count($lemmas) !== 1) {
            $this->cache_lazy_lookup($term, $term);

            return $term;
        }

        $lemma = (string) array_key_first($lemmas);
        $this->cache_lazy_lookup($term, $lemma);

        return $lemma;
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
     * @return string[]
     */
    private function lookup_runtime_file(array $file, string $term): array
    {
        $path = (string) $file['path'];
        $lemmas = [];
        $compression = isset($file['compression']) ? (string) $file['compression'] : null;
        $handle = $this->open_runtime_file($path, $compression);
        if (!is_resource($handle)) {
            return [];
        }

        try {
            while (($line = $this->read_runtime_line($handle, $compression)) !== false) {
                $line = rtrim((string) $line, "\n");
                $line = rtrim($line, "\r");
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $columns = explode("\t", $line);
                if (count($columns) !== 2) {
                    continue;
                }
                $comparison = strcmp($columns[0], $term);
                if ($comparison > 0) {
                    break;
                }
                if ($comparison < 0) {
                    continue;
                }
                $lemmas[$columns[1]] = true;
            }
        } finally {
            $this->close_runtime_file($handle, $compression);
        }

        $result = array_keys($lemmas);
        sort($result, SORT_STRING);

        return $result;
    }

    private function cache_lazy_lookup(string $surface, string $result): void
    {
        if (array_key_exists($surface, $this->lazyLookupCache)) {
            $this->lazyLookupCache[$surface] = $result;

            return;
        }

        $this->lazyLookupCache[$surface] = $result;
        $this->lazyLookupCacheOrder[] = $surface;
        while (count($this->lazyLookupCacheOrder) > self::MAX_LAZY_LOOKUP_CACHE_ENTRIES) {
            $oldest = array_shift($this->lazyLookupCacheOrder);
            if (is_string($oldest)) {
                unset($this->lazyLookupCache[$oldest]);
            }
        }
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
            'contract' => 'wp-fts-polish-morfologik-lemma-pack',
            'version' => 1,
            'pack_id' => (string) $validation['manifest']['pack_id'],
            'pack_version' => (string) $validation['manifest']['version'],
            'language' => (string) $validation['manifest']['language'],
            'fixture_only' => (bool) $validation['manifest']['fixture_only'],
            'manifest_sha256' => (string) $validation['manifest_sha256'],
            'runtime' => $runtime,
        ];

        return 'wp-fts-polish-morfologik-lemma-pack-v1:' . sha1($this->stable_json($payload));
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
    private function base_language(string $language): string
    {
        $language = strtolower(str_replace('_', '-', trim($language)));
        $separator = strpos($language, '-');

        return $separator === false ? $language : substr($language, 0, $separator);
    }
}
