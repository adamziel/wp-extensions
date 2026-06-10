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
    private const POLISH_RUNTIME_FORMAT = 'wp-fts-polish-lemma-tsv-v1';

    /**
     * Return the bundled Polish fixture manifest path.
     */
    public static function default_polish_fixture_manifest(): string
    {
        return dirname(__DIR__) . '/resources/analyzer-packs/pl-morfologik-polimorf-fixture/manifest.json';
    }

    /**
     * Validate a pack manifest and all referenced runtime files.
     *
     * @return array{
     *   manifest_path:string,
     *   manifest_sha256:string,
     *   manifest:array<string,mixed>,
     *   rows:array<int,array{surface:string,lemma:string,file:string,line:int}>,
     *   runtime_files:array<string,array{sha256:string,rows:int,path:string}>
     * }
     */
    public function validate(string $manifestPath): array
    {
        $manifestPath = $this->canonical_file($manifestPath, 'manifest');
        $manifest = $this->read_manifest($manifestPath);
        $this->validate_manifest_shape($manifest);

        $packDir = dirname($manifestPath);
        $rows = [];
        $runtimeFiles = [];
        foreach ($manifest['runtime']['files'] as $file) {
            $runtimePath = $this->runtime_file_path($packDir, $file['path']);
            $digest = hash_file('sha256', $runtimePath);
            if (!is_string($digest) || $digest !== $file['sha256']) {
                throw new RuntimeException("Runtime digest mismatch for {$file['path']}.");
            }

            $fileRows = $this->parse_runtime_rows($runtimePath);
            if (count($fileRows) !== (int) $file['rows']) {
                throw new RuntimeException("Runtime row count mismatch for {$file['path']}.");
            }

            foreach ($fileRows as $row) {
                $rows[] = $row;
            }
            $runtimeFiles[(string) $file['path']] = [
                'sha256' => $digest,
                'rows' => count($fileRows),
                'path' => $runtimePath,
            ];
        }

        $this->validate_global_row_determinism($rows);

        $manifestDigest = hash_file('sha256', $manifestPath);
        if (!is_string($manifestDigest)) {
            throw new RuntimeException('Could not compute manifest digest.');
        }

        return [
            'manifest_path' => $manifestPath,
            'manifest_sha256' => $manifestDigest,
            'manifest' => $manifest,
            'rows' => $rows,
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
        $this->require_string_value($manifest, 'language', 'pl');
        $this->require_non_empty_string($manifest, 'version');
        $this->require_bool($manifest, 'fixture_only', true);
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
        if (empty($manifest['provenance']['no_full_third_party_dictionary_dump'])) {
            throw new RuntimeException('Analyzer pack manifest must declare that no full third-party dictionary dump is vendored.');
        }

        if (!isset($manifest['runtime']) || !is_array($manifest['runtime'])) {
            throw new RuntimeException('Analyzer pack manifest missing runtime object.');
        }
        if (($manifest['runtime']['format'] ?? null) !== self::POLISH_RUNTIME_FORMAT) {
            throw new RuntimeException('Analyzer pack runtime format is not supported.');
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
        }
    }

    /**
     * Parse normalized TSV rows from one runtime dictionary file.
     *
     * @return array<int,array{surface:string,lemma:string,file:string,line:int}>
     */
    private function parse_runtime_rows(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            throw new RuntimeException("Could not read analyzer pack runtime file {$path}.");
        }

        $rows = [];
        $previousKey = null;
        $normalizer = new WP_FTS_Normalizer();
        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $line = rtrim((string) $line, "\r");
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $columns = explode("\t", $line);
            if (count($columns) !== 2) {
                throw new RuntimeException("Runtime row {$path}:{$lineNumber} must have surface and lemma columns.");
            }

            $surface = trim($columns[0]);
            $lemma = trim($columns[1]);
            $this->validate_normalized_runtime_token($surface, $normalizer, $path, $lineNumber, 'surface');
            $this->validate_normalized_runtime_token($lemma, $normalizer, $path, $lineNumber, 'lemma');

            $key = $surface . "\t" . $lemma;
            if ($previousKey !== null && strcmp($previousKey, $key) >= 0) {
                throw new RuntimeException("Runtime rows in {$path} must be unique and sorted by surface then lemma.");
            }
            $previousKey = $key;

            $rows[] = [
                'surface' => $surface,
                'lemma' => $lemma,
                'file' => $path,
                'line' => $lineNumber,
            ];
        }

        return $rows;
    }

    /**
     * Validate sort order after rows from all runtime files are concatenated.
     *
     * @param array<int,array{surface:string,lemma:string,file:string,line:int}> $rows
     */
    private function validate_global_row_determinism(array $rows): void
    {
        if ($rows === []) {
            throw new RuntimeException('Analyzer pack runtime must contain at least one row.');
        }

        $previousKey = null;
        foreach ($rows as $row) {
            $key = $row['surface'] . "\t" . $row['lemma'];
            if ($previousKey !== null && strcmp($previousKey, $key) >= 0) {
                throw new RuntimeException('Analyzer pack runtime rows must be globally unique and sorted.');
            }
            $previousKey = $key;
        }
    }

    private function validate_normalized_runtime_token(
        string $token,
        WP_FTS_Normalizer $normalizer,
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
        if ($normalizer->normalize_token($token, 'pl') !== $token) {
            throw new RuntimeException("Runtime {$column} at {$path}:{$lineNumber} is not normalized for Polish.");
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
    private function require_string_value(array $manifest, string $field, string $expected): void
    {
        if (($manifest[$field] ?? null) !== $expected) {
            throw new RuntimeException("Analyzer pack manifest field {$field} must be {$expected}.");
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
