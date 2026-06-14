<?php
declare(strict_types=1);

/**
 * Validates local analyzer-pack manifests and runtime dictionary rows.
 *
 * The validator is intentionally pure PHP so pack checks can run in bare test
 * harnesses and under `php -n`. It validates only local files and never reaches
 * out to network sources.
 */
final class WP_FTS_AnalyzerPackValidator
{
    private const MANIFEST_SCHEMA_VERSION = 1;
    public const RUNTIME_FORMAT_LEMMA_TSV = 'wp-fts-lemma-tsv-v1';
    public const RUNTIME_FORMAT_POLISH_LEGACY_TSV = 'wp-fts-polish-lemma-tsv-v1';
    private const DEFAULT_MAX_COLLECTED_RUNTIME_ROWS = 50000;
    public const RUNTIME_COMPRESSION_GZIP = 'gzip';

    private int $maxCollectedRuntimeRows;

    public function __construct(int $maxCollectedRuntimeRows = self::DEFAULT_MAX_COLLECTED_RUNTIME_ROWS)
    {
        if ($maxCollectedRuntimeRows < 1) {
            throw new InvalidArgumentException('Analyzer pack row collection cap must be positive.');
        }

        $this->maxCollectedRuntimeRows = $maxCollectedRuntimeRows;
    }

    /**
     * Return the bundled Polish fixture manifest path.
     */
    public static function default_polish_fixture_manifest(): string
    {
        return dirname(__DIR__) . '/resources/analyzer-packs/pl-morfologik-polimorf-fixture/manifest.json';
    }

    /**
     * Return the bundled compressed full Polish pack used by the Playground sandbox.
     */
    public static function default_polish_playground_full_manifest(): string
    {
        return dirname(__DIR__) . '/resources/analyzer-packs/pl-polimorf-20180722-full-playground/manifest.json';
    }

    /**
     * Return the bundled synthetic Bengali fixture manifest path.
     */
    public static function default_synthetic_bengali_fixture_manifest(): string
    {
        return dirname(__DIR__) . '/resources/analyzer-packs/bn-synthetic-lemma-fixture/manifest.json';
    }

    /**
     * Return bundled source-backed UniMorph top-language manifest paths keyed by
     * their manifest language.
     *
     * @return array<string,string>
     */
    public static function bundled_unimorph_top_language_pack_manifests(): array
    {
        $root = dirname(__DIR__) . '/resources/analyzer-packs';
        $paths = glob($root . '/*-unimorph-*/manifest.json');
        if (!is_array($paths)) {
            return [];
        }

        $manifests = [];
        foreach ($paths as $manifestPath) {
            if (!is_string($manifestPath) || !is_file($manifestPath)) {
                continue;
            }

            $json = file_get_contents($manifestPath);
            if (!is_string($json)) {
                continue;
            }

            $manifest = json_decode($json, true);
            if (!is_array($manifest) || !is_scalar($manifest['language'] ?? null)) {
                continue;
            }

            $language = WP_FTS_TermNamespace::canonicalize_lang((string) $manifest['language']);
            if ($language === '') {
                continue;
            }

            $manifests[$language] = $manifestPath;
        }

        ksort($manifests, SORT_STRING);

        return $manifests;
    }

    /**
     * Report whether this PHP runtime can stream gzip-compressed runtime shards.
     */
    public static function gzip_available(): bool
    {
        return function_exists('gzopen') && function_exists('gzgets') && function_exists('gzclose');
    }

    /**
     * Validate manifest shape, pack-local file references, optional compressed
     * file digests, and declared runtime metadata without parsing all runtime rows.
     *
     * Use this for runtime construction of full packs; call validate() when a
     * full row/digest audit is required.
     *
     * @return array{
     *   manifest_path:string,
     *   manifest_sha256:string,
     *   manifest:array<string,mixed>,
     *   rows:array<int,array{surface:string,lemma:string,file:string,line:int}>,
     *   runtime_rows:int,
     *   rows_collected:bool,
     *   runtime_files:array<string,array{sha256:string,rows:int,path:string,compression?:string,first_surface?:string,last_surface?:string}>
     * }
     */
    public function validate_metadata(string $manifestPath, bool $verifyRuntimeFileDigests = true): array
    {
        $manifestData = $this->load_validated_manifest($manifestPath);
        $manifestPath = $manifestData['path'];
        $manifest = $manifestData['manifest'];
        $packDir = dirname($manifestPath);

        $runtimeFiles = [];
        $totalRows = 0;
        foreach ($manifest['runtime']['files'] as $file) {
            $runtimePath = $this->runtime_file_path($packDir, $file['path']);
            $compression = $this->runtime_file_compression($file);
            $this->ensure_runtime_compression_available($compression);
            $digest = (string) $file['sha256'];
            if ($verifyRuntimeFileDigests) {
                $computedDigest = hash_file('sha256', $runtimePath);
                if (!is_string($computedDigest) || $computedDigest !== $digest) {
                    throw new RuntimeException("Runtime digest mismatch for {$file['path']}.");
                }
                $digest = $computedDigest;
            }

            $runtimeFile = [
                'sha256' => $digest,
                'rows' => (int) $file['rows'],
                'path' => $runtimePath,
            ];
            if ($compression !== null) {
                $runtimeFile['compression'] = $compression;
            }
            if (isset($file['first_surface'])) {
                $runtimeFile['first_surface'] = (string) $file['first_surface'];
            }
            if (isset($file['last_surface'])) {
                $runtimeFile['last_surface'] = (string) $file['last_surface'];
            }
            $runtimeFiles[(string) $file['path']] = $runtimeFile;
            $totalRows += (int) $file['rows'];
        }

        if ($totalRows < 1) {
            throw new RuntimeException('Analyzer pack runtime must contain at least one row.');
        }
        if (isset($manifest['runtime']['total_rows']) && $manifest['runtime']['total_rows'] !== $totalRows) {
            throw new RuntimeException('Analyzer pack runtime total_rows mismatch.');
        }

        $manifestDigest = hash_file('sha256', $manifestPath);
        if (!is_string($manifestDigest)) {
            throw new RuntimeException('Could not compute manifest digest.');
        }

        return [
            'manifest_path' => $manifestPath,
            'manifest_sha256' => $manifestDigest,
            'manifest' => $manifest,
            'rows' => [],
            'runtime_rows' => $totalRows,
            'rows_collected' => false,
            'runtime_files' => $runtimeFiles,
        ];
    }

    /**
     * Validate a pack manifest and all referenced runtime files.
     *
     * Fixture packs may return their tiny reviewed row set for eager tests and
     * lookup construction. Non-fixture full packs, and any pack above the bounded
     * collection cap, are streamed so the validator enforces the same file, count,
     * digest, sort, and uniqueness invariants without retaining the whole
     * dictionary in memory.
     *
     * @return array{
     *   manifest_path:string,
     *   manifest_sha256:string,
     *   manifest:array<string,mixed>,
     *   rows:array<int,array{surface:string,lemma:string,file:string,line:int}>,
     *   runtime_rows:int,
     *   rows_collected:bool,
     *   runtime_files:array<string,array{sha256:string,rows:int,path:string,compression?:string,first_surface?:string,last_surface?:string}>
     * }
     */
    public function validate(string $manifestPath, bool $collectRows = true): array
    {
        $manifestData = $this->load_validated_manifest($manifestPath);
        $manifestPath = $manifestData['path'];
        $manifest = $manifestData['manifest'];

        $packDir = dirname($manifestPath);
        $rows = [];
        $collectRuntimeRows = $collectRows
            && (bool) $manifest['fixture_only']
            && $this->declared_runtime_rows($manifest) <= $this->maxCollectedRuntimeRows;
        $runtimeFiles = [];
        $previousKey = null;
        $totalRows = 0;
        $runtimeDigest = hash_init('sha256');
        foreach ($manifest['runtime']['files'] as $file) {
            $runtimePath = $this->runtime_file_path($packDir, $file['path']);
            $compression = $this->runtime_file_compression($file);
            $this->ensure_runtime_compression_available($compression);
            $digest = hash_file('sha256', $runtimePath);
            if (!is_string($digest) || $digest !== $file['sha256']) {
                throw new RuntimeException("Runtime digest mismatch for {$file['path']}.");
            }

            $fileResult = $this->parse_runtime_rows(
                $runtimePath,
                $compression,
                (string) $manifest['language'],
                $collectRuntimeRows,
                $previousKey,
                $runtimeDigest,
                $rows
            );
            if ($fileResult['rows_count'] !== (int) $file['rows']) {
                throw new RuntimeException("Runtime row count mismatch for {$file['path']}.");
            }
            $this->validate_runtime_file_range($file, $fileResult, (string) $file['path']);

            $runtimeFile = [
                'sha256' => $digest,
                'rows' => $fileResult['rows_count'],
                'path' => $runtimePath,
            ];
            if ($compression !== null) {
                $runtimeFile['compression'] = $compression;
            }
            if ($fileResult['first_surface'] !== null) {
                $runtimeFile['first_surface'] = $fileResult['first_surface'];
            }
            if ($fileResult['last_surface'] !== null) {
                $runtimeFile['last_surface'] = $fileResult['last_surface'];
            }
            $runtimeFiles[(string) $file['path']] = $runtimeFile;
            $totalRows += $fileResult['rows_count'];
        }

        if ($totalRows < 1) {
            throw new RuntimeException('Analyzer pack runtime must contain at least one row.');
        }
        if (isset($manifest['runtime']['total_rows']) && $manifest['runtime']['total_rows'] !== $totalRows) {
            throw new RuntimeException('Analyzer pack runtime total_rows mismatch.');
        }
        $totalDigest = hash_final($runtimeDigest);
        if (isset($manifest['runtime']['total_sha256']) && $manifest['runtime']['total_sha256'] !== $totalDigest) {
            throw new RuntimeException('Analyzer pack runtime total_sha256 mismatch.');
        }

        $manifestDigest = hash_file('sha256', $manifestPath);
        if (!is_string($manifestDigest)) {
            throw new RuntimeException('Could not compute manifest digest.');
        }

        return [
            'manifest_path' => $manifestPath,
            'manifest_sha256' => $manifestDigest,
            'manifest' => $manifest,
            'rows' => $rows,
            'runtime_rows' => $totalRows,
            'rows_collected' => $collectRuntimeRows,
            'runtime_files' => $runtimeFiles,
        ];
    }

    /**
     * Read and decode a manifest as an associative array.
     *
     * @return array<string,mixed>
     */
    private function read_manifest(string $manifestPath): array
    {
        $json = file_get_contents($manifestPath);
        if (!is_string($json)) {
            throw new RuntimeException('Could not read analyzer pack manifest.');
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Analyzer pack manifest is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Analyzer pack manifest must decode to an object.');
        }

        return $decoded;
    }

    /**
     * Validate required manifest fields and fixture-only boundaries.
     *
     * @param array<string,mixed> $manifest
     */
    private function validate_manifest_shape(array $manifest): void
    {
        $this->require_int($manifest, 'schema_version', self::MANIFEST_SCHEMA_VERSION);
        $this->require_non_empty_string($manifest, 'pack_id');
        $this->require_language_tag($manifest, 'language');
        $this->require_non_empty_string($manifest, 'version');
        $this->require_bool_field($manifest, 'fixture_only');
        $this->require_bool($manifest, 'default_enabled', false);
        $this->require_string_array_contains($manifest, 'capabilities', 'dictionary-lemmatizer');

        foreach (['source', 'license', 'attribution', 'provenance'] as $field) {
            if (!isset($manifest[$field]) || !is_array($manifest[$field])) {
                throw new RuntimeException("Analyzer pack manifest missing {$field} object.");
            }
        }

        if (empty($manifest['provenance']['no_runtime_network_access'])) {
            throw new RuntimeException('Analyzer pack manifest must declare no runtime network access.');
        }
        if ($manifest['fixture_only'] === true && empty($manifest['provenance']['no_full_third_party_dictionary_dump'])) {
            throw new RuntimeException('Analyzer pack manifest must declare that no full third-party dictionary dump is vendored.');
        }
        if ($manifest['fixture_only'] === false) {
            $this->validate_full_pack_source_metadata($manifest);
        }

        if (!isset($manifest['runtime']) || !is_array($manifest['runtime'])) {
            throw new RuntimeException('Analyzer pack manifest missing runtime object.');
        }
        if (!in_array($manifest['runtime']['format'] ?? null, self::supported_runtime_formats(), true)) {
            throw new RuntimeException('Analyzer pack runtime format is not supported.');
        }
        if (isset($manifest['runtime']['total_sha256']) && (!is_string($manifest['runtime']['total_sha256']) || strlen($manifest['runtime']['total_sha256']) !== 64 || !$this->is_hex_digest($manifest['runtime']['total_sha256']))) {
            throw new RuntimeException('Analyzer pack runtime total_sha256 must be a 64-character hex digest.');
        }
        if (isset($manifest['runtime']['total_rows']) && (!is_int($manifest['runtime']['total_rows']) || $manifest['runtime']['total_rows'] < 1)) {
            throw new RuntimeException('Analyzer pack runtime total_rows must be a positive integer.');
        }
        if (isset($manifest['runtime']['ambiguity_policy']) && $manifest['runtime']['ambiguity_policy'] !== 'ambiguous_surface_noop') {
            throw new RuntimeException('Analyzer pack runtime ambiguity_policy is not supported.');
        }
        if (!isset($manifest['runtime']['files']) || !is_array($manifest['runtime']['files']) || $manifest['runtime']['files'] === []) {
            throw new RuntimeException('Analyzer pack manifest must list runtime files.');
        }

        foreach ($manifest['runtime']['files'] as $file) {
            if (!is_array($file)) {
                throw new RuntimeException('Analyzer pack runtime file entries must be objects.');
            }
            if (!isset($file['path'], $file['sha256'], $file['rows'])) {
                throw new RuntimeException('Analyzer pack runtime file entries require path, sha256, and rows.');
            }
            if (!is_string($file['path']) || trim($file['path']) === '' || $this->is_absolute_path($file['path'])) {
                throw new RuntimeException('Analyzer pack runtime file path must be a relative non-empty string.');
            }
            if (!is_string($file['sha256']) || strlen($file['sha256']) !== 64 || !$this->is_hex_digest($file['sha256'])) {
                throw new RuntimeException('Analyzer pack runtime sha256 must be a 64-character hex digest.');
            }
            if (!is_int($file['rows']) || $file['rows'] < 1) {
                throw new RuntimeException('Analyzer pack runtime rows must be a positive integer.');
            }
            foreach (['first_surface', 'last_surface'] as $field) {
                if (isset($file[$field]) && (!is_string($file[$field]) || trim($file[$field]) === '')) {
                    throw new RuntimeException("Analyzer pack runtime {$field} must be a non-empty string when present.");
                }
            }
            if (isset($file['first_surface'], $file['last_surface']) && strcmp((string) $file['first_surface'], (string) $file['last_surface']) > 0) {
                throw new RuntimeException('Analyzer pack runtime file surface range is invalid.');
            }
            if (array_key_exists('compression', $file)) {
                if ($file['compression'] !== self::RUNTIME_COMPRESSION_GZIP) {
                    throw new RuntimeException('Analyzer pack runtime compression is not supported.');
                }
                if (!str_ends_with((string) $file['path'], '.gz')) {
                    throw new RuntimeException('Analyzer pack gzip runtime files must use a .gz path.');
                }
            }
        }
    }

    /**
     * Parse normalized TSV rows from one runtime dictionary file.
     *
     * @param string|null $previousGlobalKey Previous row key from earlier files.
     * @param HashContext $runtimeDigest Digest context for normalized data rows.
     * @param array<int,array{surface:string,lemma:string,file:string,line:int}> $rows
     * @return array{
     *   rows_count:int,
     *   first_surface:?string,
     *   last_surface:?string
     * }
     */
    private function parse_runtime_rows(
        string $path,
        ?string $compression,
        string $language,
        bool &$collectRows,
        ?string &$previousGlobalKey,
        HashContext $runtimeDigest,
        array &$rows
    ): array
    {
        $handle = $this->open_runtime_file($path, $compression);

        $previousKey = null;
        $normalizer = new WP_FTS_Normalizer();
        $lineNumber = 0;
        $rowsCount = 0;
        $firstSurface = null;
        $lastSurface = null;
        while (($line = $this->read_runtime_line($handle, $compression)) !== false) {
            $lineNumber++;
            $line = rtrim((string) $line, "\n");
            $line = rtrim($line, "\r");
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $columns = explode("\t", $line);
            if (count($columns) !== 2) {
                throw new RuntimeException("Runtime row {$path}:{$lineNumber} must have surface and lemma columns.");
            }

            $surface = trim($columns[0]);
            $lemma = trim($columns[1]);
            $this->validate_normalized_runtime_token($surface, $normalizer, $language, $path, $lineNumber, 'surface');
            $this->validate_normalized_runtime_token($lemma, $normalizer, $language, $path, $lineNumber, 'lemma');

            $key = $surface . "\t" . $lemma;
            if ($previousKey !== null && strcmp($previousKey, $key) >= 0) {
                $this->close_runtime_file($handle, $compression);
                throw new RuntimeException("Runtime rows in {$path} must be unique and sorted by surface then lemma.");
            }
            if ($previousGlobalKey !== null && strcmp($previousGlobalKey, $key) >= 0) {
                $this->close_runtime_file($handle, $compression);
                throw new RuntimeException('Analyzer pack runtime rows must be globally unique and sorted.');
            }
            $previousKey = $key;
            $previousGlobalKey = $key;
            $firstSurface ??= $surface;
            $lastSurface = $surface;
            $rowsCount++;
            hash_update($runtimeDigest, $key . "\n");

            if ($collectRows) {
                if (count($rows) >= $this->maxCollectedRuntimeRows) {
                    $rows = [];
                    $collectRows = false;
                    continue;
                }

                $rows[] = [
                    'surface' => $surface,
                    'lemma' => $lemma,
                    'file' => $path,
                    'line' => $lineNumber,
                ];
            }
        }

        $this->close_runtime_file($handle, $compression);

        return [
            'rows_count' => $rowsCount,
            'first_surface' => $firstSurface,
            'last_surface' => $lastSurface,
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function declared_runtime_rows(array $manifest): int
    {
        $rows = 0;
        foreach ($manifest['runtime']['files'] as $file) {
            $rows += (int) $file['rows'];
        }

        return $rows;
    }

    /**
     * Validate optional first/last surface metadata against parsed file rows.
     *
     * @param array<string,mixed> $file
     * @param array{rows_count:int,first_surface:?string,last_surface:?string} $fileResult
     */
    private function validate_runtime_file_range(array $file, array $fileResult, string $path): void
    {
        if (isset($file['first_surface']) && $file['first_surface'] !== $fileResult['first_surface']) {
            throw new RuntimeException("Runtime first_surface mismatch for {$path}.");
        }
        if (isset($file['last_surface']) && $file['last_surface'] !== $fileResult['last_surface']) {
            throw new RuntimeException("Runtime last_surface mismatch for {$path}.");
        }
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function validate_full_pack_source_metadata(array $manifest): void
    {
        foreach (['name', 'version', 'url', 'artifact_sha256'] as $field) {
            if (!isset($manifest['source'][$field]) || !is_string($manifest['source'][$field]) || trim($manifest['source'][$field]) === '') {
                throw new RuntimeException("Full analyzer pack manifest source.{$field} is required.");
            }
        }
        if (strlen((string) $manifest['source']['artifact_sha256']) !== 64 || !$this->is_hex_digest((string) $manifest['source']['artifact_sha256'])) {
            throw new RuntimeException('Full analyzer pack source artifact_sha256 must be a 64-character hex digest.');
        }
        if (!isset($manifest['source']['byte_count']) || !is_int($manifest['source']['byte_count']) || $manifest['source']['byte_count'] < 1) {
            throw new RuntimeException('Full analyzer pack source byte_count must be a positive integer.');
        }
        if (!isset($manifest['license']['spdx_id']) || !is_string($manifest['license']['spdx_id']) || trim($manifest['license']['spdx_id']) === '') {
            throw new RuntimeException('Full analyzer pack license spdx_id is required.');
        }
        if (isset($manifest['license']['license_url']) && (!is_string($manifest['license']['license_url']) || trim($manifest['license']['license_url']) === '')) {
            throw new RuntimeException('Full analyzer pack license license_url must be a non-empty string when present.');
        }
        if (!isset($manifest['license']['notice_path']) || !is_string($manifest['license']['notice_path']) || trim($manifest['license']['notice_path']) === '') {
            throw new RuntimeException('Full analyzer pack must include a license notice_path.');
        }
        $hasAttribution = false;
        foreach (['upstream', 'note', 'notice_path'] as $field) {
            if (isset($manifest['attribution'][$field]) && is_string($manifest['attribution'][$field]) && trim($manifest['attribution'][$field]) !== '') {
                $hasAttribution = true;
                break;
            }
        }
        if (!$hasAttribution) {
            throw new RuntimeException('Full analyzer pack attribution metadata is required.');
        }
        if (($manifest['runtime']['ambiguity_policy'] ?? null) !== 'ambiguous_surface_noop') {
            throw new RuntimeException('Full analyzer pack must declare ambiguous_surface_noop ambiguity policy.');
        }
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function validate_manifest_pack_files(array $manifest, string $packDir): void
    {
        if (($manifest['fixture_only'] ?? true) !== false) {
            return;
        }

        $this->pack_relative_file_path($packDir, (string) $manifest['license']['notice_path'], 'license notice');
    }

    /**
     * @return array{path:string,manifest:array<string,mixed>}
     */
    private function load_validated_manifest(string $manifestPath): array
    {
        $manifestPath = $this->canonical_file($manifestPath, 'manifest');
        $manifest = $this->read_manifest($manifestPath);
        $this->validate_manifest_shape($manifest);
        $this->validate_manifest_pack_files($manifest, dirname($manifestPath));

        return [
            'path' => $manifestPath,
            'manifest' => $manifest,
        ];
    }

    /**
     * @param array<string,mixed> $file
     */
    private function runtime_file_compression(array $file): ?string
    {
        return isset($file['compression']) ? (string) $file['compression'] : null;
    }

    private function ensure_runtime_compression_available(?string $compression): void
    {
        if ($compression === null) {
            return;
        }
        if ($compression === self::RUNTIME_COMPRESSION_GZIP && self::gzip_available()) {
            return;
        }

        throw new RuntimeException('Analyzer pack gzip-compressed runtime files require PHP zlib gzip support.');
    }

    /**
     * @return resource
     */
    private function open_runtime_file(string $path, ?string $compression): mixed
    {
        if ($compression === self::RUNTIME_COMPRESSION_GZIP) {
            $this->ensure_runtime_compression_available($compression);
            $handle = gzopen($path, 'rb');
            if (!is_resource($handle)) {
                throw new RuntimeException("Could not read analyzer pack gzip runtime file {$path}.");
            }

            return $handle;
        }

        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Could not read analyzer pack runtime file {$path}.");
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private function read_runtime_line(mixed $handle, ?string $compression): string|false
    {
        if ($compression === self::RUNTIME_COMPRESSION_GZIP) {
            return gzgets($handle);
        }

        return fgets($handle);
    }

    /**
     * @param resource $handle
     */
    private function close_runtime_file(mixed $handle, ?string $compression): void
    {
        if ($compression === self::RUNTIME_COMPRESSION_GZIP) {
            gzclose($handle);
            return;
        }

        fclose($handle);
    }

    private function validate_normalized_runtime_token(
        string $token,
        WP_FTS_Normalizer $normalizer,
        string $language,
        string $path,
        int $lineNumber,
        string $column
    ): void {
        if ($token === '') {
            throw new RuntimeException("Runtime {$column} at {$path}:{$lineNumber} must not be empty.");
        }
        if (strpbrk($token, " \t\r\n") !== false || str_contains($token, WP_FTS_TermNamespace::SEPARATOR)) {
            throw new RuntimeException("Runtime {$column} at {$path}:{$lineNumber} must be one normalized token.");
        }
        if ($normalizer->normalize_token($token, $language) !== $token) {
            throw new RuntimeException("Runtime {$column} at {$path}:{$lineNumber} is not normalized for {$language}.");
        }
    }

    private function canonical_file(string $path, string $label): string
    {
        $real = realpath($path);
        if (!is_string($real) || !is_file($real)) {
            throw new RuntimeException("Analyzer pack {$label} file does not exist: {$path}");
        }

        return $real;
    }

    private function runtime_file_path(string $packDir, string $relativePath): string
    {
        if (str_contains($relativePath, "\0") || $this->is_absolute_path($relativePath)) {
            throw new RuntimeException('Analyzer pack runtime file path must stay inside the pack directory.');
        }

        $path = realpath($packDir . DIRECTORY_SEPARATOR . $relativePath);
        if (!is_string($path) || !is_file($path)) {
            throw new RuntimeException("Analyzer pack runtime file does not exist: {$relativePath}");
        }

        $packRoot = realpath($packDir);
        if (!is_string($packRoot) || strpos($path, $packRoot . DIRECTORY_SEPARATOR) !== 0) {
            throw new RuntimeException('Analyzer pack runtime file path escapes the pack directory.');
        }

        return $path;
    }

    private function pack_relative_file_path(string $packDir, string $relativePath, string $label): string
    {
        if (str_contains($relativePath, "\0") || $this->is_absolute_path($relativePath)) {
            throw new RuntimeException("Analyzer pack {$label} path must stay inside the pack directory.");
        }

        $segments = explode('/', str_replace('\\', '/', $relativePath));
        if (in_array('..', $segments, true)) {
            throw new RuntimeException("Analyzer pack {$label} path must not contain parent-directory traversal.");
        }

        $path = realpath($packDir . DIRECTORY_SEPARATOR . $relativePath);
        if (!is_string($path) || !is_file($path)) {
            throw new RuntimeException("Analyzer pack {$label} file does not exist: {$relativePath}");
        }

        $packRoot = realpath($packDir);
        if (!is_string($packRoot) || ($path !== $packRoot && strpos($path, $packRoot . DIRECTORY_SEPARATOR) !== 0)) {
            throw new RuntimeException("Analyzer pack {$label} path escapes the pack directory.");
        }

        return $path;
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function require_int(array $manifest, string $field, int $expected): void
    {
        if (($manifest[$field] ?? null) !== $expected) {
            throw new RuntimeException("Analyzer pack manifest field {$field} must be {$expected}.");
        }
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function require_bool(array $manifest, string $field, bool $expected): void
    {
        if (($manifest[$field] ?? null) !== $expected) {
            throw new RuntimeException("Analyzer pack manifest field {$field} must be " . ($expected ? 'true' : 'false') . '.');
        }
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function require_bool_field(array $manifest, string $field): void
    {
        if (!isset($manifest[$field]) || !is_bool($manifest[$field])) {
            throw new RuntimeException("Analyzer pack manifest field {$field} must be a boolean.");
        }
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function require_language_tag(array $manifest, string $field): void
    {
        if (!isset($manifest[$field]) || !is_string($manifest[$field])) {
            throw new RuntimeException("Analyzer pack manifest field {$field} must be a language tag.");
        }

        $language = trim($manifest[$field]);
        if (
            $language === ''
            || preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8}){0,3}$/', $language) !== 1
        ) {
            throw new RuntimeException("Analyzer pack manifest field {$field} must be a valid language tag.");
        }
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function require_non_empty_string(array $manifest, string $field): void
    {
        if (!isset($manifest[$field]) || !is_string($manifest[$field]) || trim($manifest[$field]) === '') {
            throw new RuntimeException("Analyzer pack manifest field {$field} must be a non-empty string.");
        }
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function require_string_array_contains(array $manifest, string $field, string $required): void
    {
        if (!isset($manifest[$field]) || !is_array($manifest[$field])) {
            throw new RuntimeException("Analyzer pack manifest field {$field} must be an array.");
        }

        foreach ($manifest[$field] as $value) {
            if ($value === $required) {
                return;
            }
        }

        throw new RuntimeException("Analyzer pack manifest field {$field} must include {$required}.");
    }

    /**
     * @return string[]
     */
    private static function supported_runtime_formats(): array
    {
        return [
            self::RUNTIME_FORMAT_LEMMA_TSV,
            self::RUNTIME_FORMAT_POLISH_LEGACY_TSV,
        ];
    }

    private function is_absolute_path(string $path): bool
    {
        return str_starts_with($path, '/') || (strlen($path) > 1 && $path[1] === ':');
    }

    private function is_hex_digest(string $value): bool
    {
        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $char = $value[$i];
            if (
                ($char >= '0' && $char <= '9')
                || ($char >= 'a' && $char <= 'f')
                || ($char >= 'A' && $char <= 'F')
            ) {
                continue;
            }

            return false;
        }

        return true;
    }
}
