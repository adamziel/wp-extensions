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
    public const MAX_LEMMAS_PER_SURFACE = WP_FTS_LemmaPackLimits::MAX_LEMMAS_PER_SURFACE;
    private const DEFAULT_MAX_COLLECTED_RUNTIME_ROWS = 50000;
    private const MAX_DIGEST_ATTESTATIONS = 256;
    private const MAX_RUNTIME_BLOCK_ATTESTATIONS = 8192;
    public const RUNTIME_COMPRESSION_GZIP = 'gzip';

    /** @var array<string,true> */
    private static array $digestAttestations = [];
    /** @var string[] */
    private static array $digestAttestationOrder = [];
    /** @var array<string,array<string,string[]>> */
    private static array $runtimeBlockAttestations = [];
    /** @var array<int,array{file:string,layout:string,blocks:int}> */
    private static array $runtimeBlockAttestationOrder = [];
    private static int $runtimeBlockAttestationCount = 0;
    /** @var array<string,true> */
    private array $requestDigestAttestations = [];
    /** @var array<string,array<string,string[]>> */
    private array $requestRuntimeBlockAttestations = [];

    private int $maxCollectedRuntimeRows;
    private int $digestFileHashes = 0;
    private int $digestBytesHashed = 0;

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
        return self::bundled_analyzer_pack_root() . '/pl-morfologik-polimorf-fixture/manifest.json';
    }

    /**
     * Return the bundled compressed full Polish pack used by the Playground sandbox.
     */
    public static function default_polish_playground_full_manifest(): string
    {
        return self::bundled_analyzer_pack_root() . '/pl-polimorf-20180722-full-playground/manifest.json';
    }

    /**
     * Return the bundled synthetic Bengali fixture manifest path.
     */
    public static function default_synthetic_bengali_fixture_manifest(): string
    {
        return self::bundled_analyzer_pack_root() . '/bn-synthetic-lemma-fixture/manifest.json';
    }

    /**
     * Return bundled source-backed UniMorph top-language manifest paths keyed by
     * their manifest language.
     *
     * @return array<string,string>
     */
    public static function bundled_unimorph_top_language_pack_manifests(): array
    {
        $root = self::bundled_analyzer_pack_root();
        $paths = glob($root . '/*-unimorph-*/manifest.json');
        if (!is_array($paths)) {
            return [];
        }

        $manifests = [];
        foreach ($paths as $manifestPath) {
            if (!is_string($manifestPath) || !is_file($manifestPath)) {
                continue;
            }

            $json = file_get_contents(
                $manifestPath,
                false,
                null,
                0,
                WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES + 1
            );
            if (!is_string($json) || strlen($json) > WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES) {
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
     * Resolve bundled analyzer-pack resources from component or plugin layouts.
     */
    private static function bundled_analyzer_pack_root(): string
    {
        $candidates = [
            dirname(__DIR__) . '/resources/analyzer-packs',
            dirname(__DIR__, 3) . '/indexer/resources/analyzer-packs',
            dirname(__DIR__, 4) . '/resources/analyzer-packs',
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    /**
     * Report whether this PHP runtime can stream gzip-compressed runtime shards.
     */
    public static function gzip_available(): bool
    {
        return function_exists('gzopen') && function_exists('gzgets') && function_exists('gzclose');
    }

    /**
     * Report actual complete-file digest work performed by this validator.
     * Cached attestations are not counted because they do not read file bytes.
     *
     * @return array{files_hashed:int,bytes_hashed:int}
     */
    public function digest_attestation_stats(): array
    {
        return [
            'files_hashed' => $this->digestFileHashes,
            'bytes_hashed' => $this->digestBytesHashed,
        ];
    }

    /**
     * Start one bounded lookup operation's digest-attestation scope.
     *
     * Stable older file generations still use the process cache. A file from
     * the current/future second is hashed once per batch instead: PHP may expose
     * only second-resolution stat fields, so carrying that success into a later
     * batch could miss an in-place same-size replacement with restored mtime.
     */
    public function begin_digest_attestation_batch(): void
    {
        $this->requestDigestAttestations = [];
        $this->requestRuntimeBlockAttestations = [];
    }

    /**
     * Validate only manifest-declared resource shape and physical file sizes.
     * Analyzer configuration uses this pass to reject an aggregate overflow
     * before opening lookup headers or constructing any individual pack.
     *
     * @return array{manifest_path:string,manifest_sha256:string,language:string,fixture_only:bool,eager_fixture_candidate:bool,eager_fixture_decoded_bytes:int,runtime_rows:int,runtime_bytes:int,runtime_files:int,lookup_blocks:int,runtime_lookup_bytes:int}
     */
    public function resource_envelope(string $manifestPath): array
    {
        $manifestData = $this->load_validated_manifest($manifestPath);
        $manifest = $manifestData['manifest'];
        $lookupBlocks = 0;
        $runtimeBytes = 0;
        $runtimeIsPlain = true;
        $packDir = dirname($manifestData['path']);
        foreach ($manifest['runtime']['files'] as $file) {
            if (isset($file['lookup']['blocks']) && is_int($file['lookup']['blocks'])) {
                $lookupBlocks += $file['lookup']['blocks'];
            }
            if (isset($file['compression'])) {
                $runtimeIsPlain = false;
            }
            $runtimePath = $this->runtime_file_path($packDir, (string) $file['path']);
            $runtimeSize = @filesize($runtimePath);
            if (!is_int($runtimeSize) || $runtimeSize < 0) {
                throw new RuntimeException("Could not size analyzer pack file {$file['path']}.");
            }
            $runtimeBytes += $runtimeSize;
        }

        $eagerFixtureCandidate = self::manifest_can_use_eager_fixture_storage($manifest)
            && $runtimeBytes
                <= WP_FTS_LemmaPackLimits::MAX_EAGER_FIXTURE_RUNTIME_BYTES
                    + WP_FTS_LemmaPackLimits::MAX_EAGER_FIXTURE_RUNTIME_FRAMING_BYTES;

        return [
            'manifest_path' => $manifestData['path'],
            'manifest_sha256' => $manifestData['sha256'],
            'language' => (string) $manifest['language'],
            'fixture_only' => (bool) $manifest['fixture_only'],
            'eager_fixture_candidate' => $eagerFixtureCandidate,
            'eager_fixture_decoded_bytes' => $eagerFixtureCandidate && $runtimeIsPlain ? $runtimeBytes : 0,
            'runtime_rows' => $this->declared_runtime_rows($manifest),
            'runtime_bytes' => $runtimeBytes,
            'runtime_files' => count($manifest['runtime']['files']),
            'lookup_blocks' => $lookupBlocks,
            'runtime_lookup_bytes' => $this->assert_runtime_lookup_pack_bytes(
                $manifest,
                $packDir
            ),
        ];
    }

    /**
     * Return whether manifest metadata permits the bounded eager fixture path.
     * The caller separately applies the physical and decoded byte ceilings.
     *
     * @param array<string,mixed> $manifest
     */
    public static function manifest_can_use_eager_fixture_storage(array $manifest): bool
    {
        if (($manifest['fixture_only'] ?? false) !== true) {
            return false;
        }

        $rows = 0;
        foreach ($manifest['runtime']['files'] as $file) {
            $rows += (int) $file['rows'];
        }

        return $rows <= WP_FTS_LemmaPackLimits::MAX_EAGER_FIXTURE_ROWS;
    }

    /**
     * Validate manifest shape, pack-local file references, optional compressed
     * file digests, lookup-sidecar attestations, and declared runtime metadata
     * without parsing all runtime rows.
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
     *   runtime_decoded_bytes:int,
     *   rows_collected:bool,
     *   runtime_files:array<string,array{sha256:string,rows:int,path:string,compression?:string,first_surface?:string,last_surface?:string,lookup?:array<string,mixed>}>
     * }
     */
    public function validate_metadata(
        string $manifestPath,
        bool $verifyRuntimeFileDigests = true,
        ?string $expectedManifestSha256 = null
    ): array {
        $manifestData = $this->load_validated_manifest($manifestPath);
        $this->assert_expected_manifest_sha256($manifestData['sha256'], $expectedManifestSha256);
        $manifestPath = $manifestData['path'];
        $manifest = $manifestData['manifest'];
        $packDir = dirname($manifestPath);
        $runtimeLookupBytes = $this->assert_runtime_lookup_pack_bytes($manifest, $packDir);

        $runtimeFiles = [];
        $totalRows = 0;
        foreach ($manifest['runtime']['files'] as $file) {
            $runtimePath = $this->runtime_file_path($packDir, $file['path']);
            $compression = $this->runtime_file_compression($file);
            $this->ensure_runtime_compression_available($compression);
            $digest = (string) $file['sha256'];
            if ($verifyRuntimeFileDigests) {
                $digest = $this->attest_file_digest($runtimePath, $digest, "Runtime digest mismatch for {$file['path']}.");
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
            if (isset($file['lookup'])) {
                $runtimeFile['lookup'] = $this->lookup_index_metadata(
                    $packDir,
                    $file,
                    $digest,
                    (int) $file['rows'],
                    $verifyRuntimeFileDigests
                );
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

        return [
            'manifest_path' => $manifestPath,
            'manifest_sha256' => $manifestData['sha256'],
            'manifest' => $manifest,
            'rows' => [],
            'runtime_rows' => $totalRows,
            'runtime_lookup_bytes' => $runtimeLookupBytes,
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
     *   runtime_files:array<string,array{sha256:string,rows:int,path:string,compression?:string,first_surface?:string,last_surface?:string,lookup?:array<string,mixed>}>
     * }
     */
    public function validate(
        string $manifestPath,
        bool $collectRows = true,
        ?int $maxCollectedRuntimeBytes = null,
        ?string $expectedManifestSha256 = null
    ): array
    {
        if ($maxCollectedRuntimeBytes !== null && $maxCollectedRuntimeBytes < 1) {
            throw new InvalidArgumentException('Analyzer pack row collection byte cap must be positive.');
        }
        $manifestData = $this->load_validated_manifest($manifestPath);
        $this->assert_expected_manifest_sha256($manifestData['sha256'], $expectedManifestSha256);
        $manifestPath = $manifestData['path'];
        $manifest = $manifestData['manifest'];

        $packDir = dirname($manifestPath);
        $runtimeLookupBytes = $this->assert_runtime_lookup_pack_bytes($manifest, $packDir);
        $rows = [];
        $collectRuntimeRows = $collectRows
            && (bool) $manifest['fixture_only']
            && $this->declared_runtime_rows($manifest) <= $this->maxCollectedRuntimeRows;
        $runtimeFiles = [];
        $previousKey = null;
        $currentSurface = null;
        $currentSurfaceLemmaCount = 0;
        $collectedRuntimeBytes = 0;
        $totalRows = 0;
        $runtimeDigest = hash_init('sha256');
        foreach ($manifest['runtime']['files'] as $file) {
            $runtimePath = $this->runtime_file_path($packDir, $file['path']);
            $compression = $this->runtime_file_compression($file);
            $this->ensure_runtime_compression_available($compression);
            $digest = $this->attest_file_digest(
                $runtimePath,
                (string) $file['sha256'],
                "Runtime digest mismatch for {$file['path']}."
            );

            $fileResult = $this->parse_runtime_rows(
                $runtimePath,
                $compression,
                (string) $manifest['language'],
                $collectRuntimeRows,
                $previousKey,
                $currentSurface,
                $currentSurfaceLemmaCount,
                $collectedRuntimeBytes,
                $maxCollectedRuntimeBytes,
                (int) $file['rows'],
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
            if (isset($file['lookup'])) {
                $lookup = $this->lookup_index_metadata($packDir, $file, $digest, $fileResult['rows_count'], true);
                WP_FTS_LemmaPackLookupIndex::validate_content($lookup, $fileResult['rows_sha256']);
                $runtimeFile['lookup'] = $lookup;
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

        return [
            'manifest_path' => $manifestPath,
            'manifest_sha256' => $manifestData['sha256'],
            'manifest' => $manifest,
            'rows' => $rows,
            'runtime_rows' => $totalRows,
            'runtime_decoded_bytes' => $collectedRuntimeBytes,
            'runtime_lookup_bytes' => $runtimeLookupBytes,
            'rows_collected' => $collectRuntimeRows,
            'runtime_files' => $runtimeFiles,
        ];
    }

    /**
     * Validate one candidate shard without retaining its open generation.
     *
     * This compatibility API proves file integrity only. It cannot bind a later
     * pathname-based read to the validated generation; lazy lookup code must use
     * open_attested_runtime_file() and keep its returned descriptors open.
     *
     * @param array{path:string,sha256:string,lookup?:array{path:string,sha256:string}} $runtimeFile
     */
    public function attest_runtime_file(array $runtimeFile): void
    {
        $runtime = $this->open_attested_file(
            $runtimeFile['path'],
            $runtimeFile['sha256'],
            'Runtime digest mismatch for the candidate analyzer shard.'
        );
        $lookupHandle = null;
        try {
            if (isset($runtimeFile['lookup']) && is_array($runtimeFile['lookup'])) {
                $lookup = $this->open_attested_file(
                    $runtimeFile['lookup']['path'],
                    $runtimeFile['lookup']['sha256'],
                    'Runtime lookup digest mismatch for the candidate analyzer shard.'
                );
                $lookupHandle = $lookup['handle'];
            }
        } finally {
            if (is_resource($lookupHandle)) {
                fclose($lookupHandle);
            }
            fclose($runtime['handle']);
        }
    }

    /**
     * Open and attest one candidate runtime shard and its lookup sidecar.
     *
     * The returned descriptors bind the lookup to the exact generations whose
     * digests were accepted. Per-block digests are derived during that same
     * authenticated runtime pass, so a later same-inode write cannot poison the
     * decoded-block cache under the manifest's expected whole-file digest.
     * Callers own both descriptors and must close them after the block reads.
     *
     * @param array{path:string,sha256:string,lookup:array{path:string,sha256:string,blocks:array<int,array{offset:int,length:int}>}} $runtimeFile
     * @return array{runtime:resource,lookup:resource,block_sha256:string[]}
     */
    public function open_attested_runtime_file(array $runtimeFile): array
    {
        if (
            !isset($runtimeFile['lookup'])
            || !is_array($runtimeFile['lookup'])
            || !isset($runtimeFile['lookup']['blocks'])
            || !is_array($runtimeFile['lookup']['blocks'])
            || $runtimeFile['lookup']['blocks'] === []
        ) {
            throw new LogicException('Lazy runtime attestation requires a lookup sidecar.');
        }
        if (count($runtimeFile['lookup']['blocks']) > WP_FTS_Analyzer_Config_Limits::MAX_LOOKUP_BLOCKS_PER_FILE) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'lookup_blocks',
                'Analyzer pack lookup exceeds the 256-block per-file limit.'
            );
        }

        $runtime = $this->open_attested_file(
            $runtimeFile['path'],
            $runtimeFile['sha256'],
            'Runtime digest mismatch for the candidate analyzer shard.',
            $runtimeFile['lookup']['blocks']
        );
        try {
            $lookup = $this->open_attested_file(
                $runtimeFile['lookup']['path'],
                $runtimeFile['lookup']['sha256'],
                'Runtime lookup digest mismatch for the candidate analyzer shard.'
            );
        } catch (Throwable $error) {
            fclose($runtime['handle']);
            throw $error;
        }

        return [
            'runtime' => $runtime['handle'],
            'lookup' => $lookup['handle'],
            'block_sha256' => $runtime['block_sha256'],
        ];
    }

    /**
     * Read and decode a manifest as an associative array.
     *
     * @return array{manifest:array<string,mixed>,sha256:string}
     */
    private function read_manifest(string $manifestPath): array
    {
        $json = file_get_contents(
            $manifestPath,
            false,
            null,
            0,
            WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES + 1
        );
        if (!is_string($json)) {
            throw new RuntimeException('Could not read analyzer pack manifest.');
        }
        if (strlen($json) > WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'manifest_bytes',
                'Analyzer pack manifest exceeds the 64 KiB limit.'
            );
        }

        try {
            $decoded = json_decode(
                $json,
                true,
                WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_GRAPH_DEPTH + 2,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Analyzer pack manifest is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Analyzer pack manifest must decode to an object.');
        }
        WP_FTS_Analyzer_Config_Limits::assert_manifest_graph($decoded);

        return [
            'manifest' => $decoded,
            'sha256' => hash('sha256', $json),
        ];
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
        if (count($manifest['runtime']['files']) > WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_FILES) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'runtime_files',
                'Analyzer pack manifest exceeds the 64-runtime-file limit.'
            );
        }

        $runtimeFileCount = count($manifest['runtime']['files']);
        $requireSurfaceRanges = $runtimeFileCount > 1;
        $normalizer = new WP_FTS_Normalizer();
        $language = (string) $manifest['language'];
        $previousLastSurface = null;
        $lookupBlocks = 0;
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
            WP_FTS_Analyzer_Config_Limits::assert_path($file['path'], 'Analyzer pack runtime file path');
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
            $hasFirstSurface = isset($file['first_surface']);
            $hasLastSurface = isset($file['last_surface']);
            if ($requireSurfaceRanges && (!$hasFirstSurface || !$hasLastSurface)) {
                throw new RuntimeException('Multi-file analyzer packs require a complete surface range for every runtime file.');
            }
            if ($hasFirstSurface) {
                $this->validate_manifest_runtime_surface(
                    (string) $file['first_surface'],
                    $normalizer,
                    $language,
                    'first_surface'
                );
            }
            if ($hasLastSurface) {
                $this->validate_manifest_runtime_surface(
                    (string) $file['last_surface'],
                    $normalizer,
                    $language,
                    'last_surface'
                );
            }
            if ($hasFirstSurface && $hasLastSurface) {
                $firstSurface = (string) $file['first_surface'];
                $lastSurface = (string) $file['last_surface'];
                if (strcmp($firstSurface, $lastSurface) > 0) {
                    throw new RuntimeException('Analyzer pack runtime file surface range is invalid.');
                }
                if ($previousLastSurface !== null && strcmp($previousLastSurface, $firstSurface) >= 0) {
                    throw new RuntimeException('Analyzer pack runtime file surface ranges must be strictly ordered and non-overlapping.');
                }
                $previousLastSurface = $lastSurface;
            }
            if (array_key_exists('compression', $file)) {
                if ($file['compression'] !== self::RUNTIME_COMPRESSION_GZIP) {
                    throw new RuntimeException('Analyzer pack runtime compression is not supported.');
                }
                if (!str_ends_with((string) $file['path'], '.gz')) {
                    throw new RuntimeException('Analyzer pack gzip runtime files must use a .gz path.');
                }
            }
            if (array_key_exists('lookup', $file)) {
                $lookup = $file['lookup'];
                if (!is_array($lookup)) {
                    throw new RuntimeException('Analyzer pack runtime lookup entry must be an object.');
                }
                if (($lookup['format'] ?? null) !== WP_FTS_LemmaPackLookupIndex::FORMAT) {
                    throw new RuntimeException('Analyzer pack runtime lookup format is not supported.');
                }
                if (!is_string($lookup['path'] ?? null) || trim($lookup['path']) === '' || $this->is_absolute_path($lookup['path'])) {
                    throw new RuntimeException('Analyzer pack runtime lookup path must be a relative non-empty string.');
                }
                WP_FTS_Analyzer_Config_Limits::assert_path($lookup['path'], 'Analyzer pack lookup path');
                if (!is_string($lookup['sha256'] ?? null) || strlen($lookup['sha256']) !== 64 || !$this->is_hex_digest($lookup['sha256'])) {
                    throw new RuntimeException('Analyzer pack runtime lookup sha256 must be a 64-character hex digest.');
                }
                if (!is_int($lookup['blocks'] ?? null) || $lookup['blocks'] < 1) {
                    throw new RuntimeException('Analyzer pack runtime lookup blocks must be a positive integer.');
                }
                if ($lookup['blocks'] > WP_FTS_Analyzer_Config_Limits::MAX_LOOKUP_BLOCKS_PER_FILE) {
                    throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                        'lookup_blocks',
                        'Analyzer pack lookup exceeds the 256-block per-file limit.'
                    );
                }
                $lookupBlocks += $lookup['blocks'];
                if ($lookupBlocks > WP_FTS_Analyzer_Config_Limits::MAX_LOOKUP_BLOCKS_PER_PACK) {
                    throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                        'lookup_blocks',
                        'Analyzer pack exceeds the 8,192-block metadata limit.'
                    );
                }
            }
        }
    }

    /** Validate a declared shard boundary as a storable normalized token. */
    private function validate_manifest_runtime_surface(
        string $surface,
        WP_FTS_Normalizer $normalizer,
        string $language,
        string $field
    ): void {
        if (!WP_FTS_TermNamespace::term_key_fits($surface, $language)) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'runtime_token_bytes',
                "Analyzer pack runtime {$field} exceeds the storage term-key limit for {$language}."
            );
        }
        if (strpbrk($surface, " \t\r\n") !== false || str_contains($surface, WP_FTS_TermNamespace::SEPARATOR)) {
            throw new RuntimeException("Analyzer pack runtime {$field} must be one normalized token.");
        }
        if ($normalizer->normalize_token($surface, $language) !== $surface) {
            throw new RuntimeException("Analyzer pack runtime {$field} is not normalized for {$language}.");
        }
    }

    /**
     * Parse normalized TSV rows from one runtime dictionary file.
     *
     * @param string|null $previousGlobalKey Previous row key from earlier files.
     * @param string|null $currentGlobalSurface Current surface from earlier rows/files.
     * @param int $currentGlobalSurfaceLemmaCount Distinct lemmas seen for the current surface.
     * @param int $collectedRuntimeBytes Decoded bytes inspected for eager row collection.
     * @param int $expectedRows Manifest-declared rows for this runtime file.
     * @param HashContext $runtimeDigest Digest context for normalized data rows.
     * @param array<int,array{surface:string,lemma:string,file:string,line:int}> $rows
     * @return array{
     *   rows_count:int,
     *   first_surface:?string,
     *   last_surface:?string,
     *   rows_sha256:string
     * }
     */
    private function parse_runtime_rows(
        string $path,
        ?string $compression,
        string $language,
        bool &$collectRows,
        ?string &$previousGlobalKey,
        ?string &$currentGlobalSurface,
        int &$currentGlobalSurfaceLemmaCount,
        int &$collectedRuntimeBytes,
        ?int $maxCollectedRuntimeBytes,
        int $expectedRows,
        HashContext $runtimeDigest,
        array &$rows
    ): array
    {
        $handle = $this->open_runtime_file($path, $compression);

        $previousKey = null;
        $normalizer = new WP_FTS_Normalizer();
        $lineNumber = 0;
        $rowsCount = 0;
        $rowsDigest = hash_init('sha256');
        $firstSurface = null;
        $lastSurface = null;
        try {
            while (($line = WP_FTS_LemmaPackLimits::read_runtime_line($handle, $compression)) !== false) {
                if ($collectRows && $maxCollectedRuntimeBytes !== null) {
                    $collectedRuntimeBytes += strlen($line);
                    if ($collectedRuntimeBytes > $maxCollectedRuntimeBytes) {
                        throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                            'eager_fixture_bytes',
                            'Fixture-only eager lemma runtime exceeds the 8 MiB decoded byte limit.'
                        );
                    }
                }
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
                    throw new RuntimeException("Runtime rows in {$path} must be unique and sorted by surface then lemma.");
                }
                if ($previousGlobalKey !== null && strcmp($previousGlobalKey, $key) >= 0) {
                    throw new RuntimeException('Analyzer pack runtime rows must be globally unique and sorted.');
                }
                if ($currentGlobalSurface !== $surface) {
                    $currentGlobalSurface = $surface;
                    $currentGlobalSurfaceLemmaCount = 0;
                }
                $currentGlobalSurfaceLemmaCount++;
                if ($currentGlobalSurfaceLemmaCount > self::MAX_LEMMAS_PER_SURFACE) {
                    throw new RuntimeException(
                        "Runtime surface {$surface} exceeds the "
                        . self::MAX_LEMMAS_PER_SURFACE
                        . "-lemma ambiguity limit at {$path}:{$lineNumber}."
                    );
                }
                $previousKey = $key;
                $previousGlobalKey = $key;
                $firstSurface ??= $surface;
                $lastSurface = $surface;
                $rowsCount++;
                if ($rowsCount > $expectedRows) {
                    throw new RuntimeException("Runtime row count exceeds the manifest declaration for {$path}.");
                }
                hash_update($runtimeDigest, $key . "\n");
                hash_update($rowsDigest, $key . "\n");

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
        } finally {
            $this->close_runtime_file($handle, $compression);
        }

        return [
            'rows_count' => $rowsCount,
            'first_surface' => $firstSurface,
            'last_surface' => $lastSurface,
            'rows_sha256' => hash_final($rowsDigest),
        ];
    }

    /**
     * Resolve and attest one optional seekable lookup sidecar.
     *
     * @param array<string,mixed> $runtimeFile
     * @return array<string,mixed>
     */
    private function lookup_index_metadata(
        string $packDir,
        array $runtimeFile,
        string $runtimeDigest,
        int $runtimeRows,
        bool $verifyDigest
    ): array {
        if (!function_exists('gzdecode')) {
            throw new RuntimeException('Indexed lemma runtime files require PHP zlib gzip decode support.');
        }
        $lookup = $runtimeFile['lookup'];
        $lookupPath = $this->runtime_file_path($packDir, (string) $lookup['path']);
        if ($verifyDigest) {
            $this->attest_file_digest(
                $lookupPath,
                (string) $lookup['sha256'],
                "Runtime lookup digest mismatch for {$lookup['path']}."
            );
        }

        $metadata = WP_FTS_LemmaPackLookupIndex::metadata(
            $lookupPath,
            $this->runtime_file_path($packDir, (string) $runtimeFile['path']),
            $runtimeDigest,
            $runtimeRows
        );
        if (!hash_equals(strtolower((string) $lookup['sha256']), $metadata['content_sha256'])) {
            throw new RuntimeException("Runtime lookup digest mismatch for {$lookup['path']}.");
        }
        if (count($metadata['blocks']) !== (int) $lookup['blocks']) {
            throw new RuntimeException("Runtime lookup block count mismatch for {$lookup['path']}.");
        }
        if (
            isset($runtimeFile['first_surface'])
            && $metadata['blocks'][0]['first_surface'] !== $runtimeFile['first_surface']
        ) {
            throw new RuntimeException("Runtime lookup first_surface mismatch for {$lookup['path']}.");
        }
        $lastBlock = $metadata['blocks'][count($metadata['blocks']) - 1];
        if (
            isset($runtimeFile['last_surface'])
            && $lastBlock['last_surface'] !== $runtimeFile['last_surface']
        ) {
            throw new RuntimeException("Runtime lookup last_surface mismatch for {$lookup['path']}.");
        }

        return $metadata + [
            'sha256' => strtolower((string) $lookup['sha256']),
        ];
    }

    /**
     * Cache successful file attestations by path, filesystem generation, and
     * expected digest so repeated lookups do not rehash unchanged shards. Every
     * generation is cached in this request-local validator instance. Only an
     * older stable generation enters the process-static cache, because another
     * validator must re-attest a current/future-second replacement when PHP
     * exposes only second-resolution mtime and ctime values.
     */
    private function attest_file_digest(string $path, string $expectedDigest, string $mismatchMessage): string
    {
        $attestation = $this->open_attested_file($path, $expectedDigest, $mismatchMessage);
        fclose($attestation['handle']);

        return strtolower($expectedDigest);
    }

    /**
     * Open one exact file generation and authenticate its bounded contents.
     *
     * Optional contiguous block ranges derive encoded block digests from the
     * same stream whose whole-file digest is accepted. Those digests have their
     * own 8,192-block process ceiling and are reused only with the matching
     * filesystem generation, expected digest, and block layout.
     *
     * @param array<int,array{offset:int,length:int}>|null $blocks
     * @return array{handle:resource,block_sha256:string[]}
     */
    private function open_attested_file(
        string $path,
        string $expectedDigest,
        string $mismatchMessage,
        ?array $blocks = null
    ): array {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if (!is_array($stat)) {
            throw new RuntimeException("Could not stat analyzer pack file {$path}.");
        }

        $expectedDigest = strtolower($expectedDigest);
        $generation = $this->file_generation($stat, $path);
        $key = hash('sha256', $path . "\0" . implode("\0", $generation) . "\0" . $expectedDigest);
        $now = time();
        $cacheableGeneration = $stat['mtime'] < $now && $stat['ctime'] < $now;
        if ($stat['size'] > WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'runtime_lookup_bytes',
                'Analyzer pack runtime and lookup files exceed the 16 MiB limit.'
            );
        }

        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Could not open analyzer pack file {$path} for hashing.");
        }
        try {
            $openedStat = fstat($handle);
            if (!is_array($openedStat) || !$this->same_file_generation($generation, $openedStat)) {
                throw new RuntimeException("Analyzer pack file generation changed before opening: {$path}.");
            }

            $layout = $blocks === null ? null : $this->runtime_block_layout_key($blocks);
            $digestIsCached = isset($this->requestDigestAttestations[$key])
                || ($cacheableGeneration && isset(self::$digestAttestations[$key]));
            $blockSha256 = $layout === null
                ? []
                : ($this->requestRuntimeBlockAttestations[$key][$layout]
                    ?? ($cacheableGeneration ? (self::$runtimeBlockAttestations[$key][$layout] ?? null) : null));
            if ($digestIsCached && ($layout === null || is_array($blockSha256))) {
                $this->requestDigestAttestations[$key] = true;
                if ($layout !== null) {
                    $this->requestRuntimeBlockAttestations[$key][$layout] = $blockSha256;
                }

                return ['handle' => $handle, 'block_sha256' => $blockSha256 ?? []];
            }

            $boundedDigest = $blocks === null
                ? WP_FTS_LemmaPackLimits::hash_open_file_bounded(
                    $handle,
                    WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK,
                    'runtime_lookup_bytes',
                    'Analyzer pack runtime and lookup files exceed the 16 MiB limit.'
                )
                : $this->hash_runtime_blocks($handle, $blocks);
            if (!$this->same_file_generation($generation, $boundedDigest['stat'])) {
                throw new RuntimeException("Analyzer pack file generation changed before hashing: {$path}.");
            }
            $computedDigest = $boundedDigest['sha256'];
            $this->digestFileHashes++;
            $this->digestBytesHashed += $boundedDigest['bytes'];
            if (!is_string($computedDigest) || !hash_equals($expectedDigest, strtolower($computedDigest))) {
                throw new RuntimeException($mismatchMessage);
            }
            $this->requestDigestAttestations[$key] = true;
            $blockSha256 = $boundedDigest['block_sha256'] ?? [];
            if ($layout !== null) {
                $this->requestRuntimeBlockAttestations[$key][$layout] = $blockSha256;
            }

            if ($cacheableGeneration) {
                $this->cache_file_attestation($key);
                if ($layout !== null) {
                    $this->cache_runtime_block_attestation($key, $layout, $blockSha256);
                }
            }

            return ['handle' => $handle, 'block_sha256' => $blockSha256];
        } catch (Throwable $error) {
            fclose($handle);
            throw $error;
        }
    }

    /**
     * Hash one indexed runtime stream and each contiguous encoded block in the
     * same pass.
     *
     * @param resource $handle
     * @param array<int,array{offset:int,length:int}> $blocks
     * @return array{sha256:string,bytes:int,stat:array<string|int,mixed>,block_sha256:string[]}
     */
    private function hash_runtime_blocks(mixed $handle, array $blocks): array
    {
        if ($blocks === []) {
            throw new RuntimeException('Indexed runtime attestation requires declared lookup blocks.');
        }
        if (fseek($handle, 0) !== 0) {
            throw new RuntimeException('Could not rewind indexed runtime payload for hashing.');
        }
        $stat = fstat($handle);
        if (!is_array($stat)) {
            throw new RuntimeException('Could not identify the indexed runtime generation before hashing.');
        }

        $digest = hash_init('sha256');
        $bytes = 0;
        $blockSha256 = [];
        foreach ($blocks as $block) {
            $offset = $block['offset'] ?? null;
            $length = $block['length'] ?? null;
            if (!is_int($offset) || !is_int($length) || $offset !== $bytes || $length < 1) {
                throw new RuntimeException('Indexed runtime block layout is invalid during attestation.');
            }
            $blockDigest = hash_init('sha256');
            $remaining = $length;
            while ($remaining > 0) {
                $chunk = fread($handle, min(8192, $remaining));
                if (!is_string($chunk) || $chunk === '') {
                    throw new RuntimeException('Could not read an indexed runtime block while hashing.');
                }
                $chunkBytes = strlen($chunk);
                $bytes += $chunkBytes;
                $remaining -= $chunkBytes;
                if ($bytes > WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK) {
                    throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                        'runtime_lookup_bytes',
                        'Analyzer pack runtime and lookup files exceed the 16 MiB limit.'
                    );
                }
                hash_update($digest, $chunk);
                hash_update($blockDigest, $chunk);
            }
            $blockSha256[] = hash_final($blockDigest);
        }

        $extra = fread($handle, 1);
        if (!is_string($extra)) {
            throw new RuntimeException('Could not confirm the end of an indexed runtime payload.');
        }
        if ($extra !== '') {
            throw new RuntimeException('Indexed runtime contains bytes outside its declared blocks.');
        }

        return [
            'sha256' => hash_final($digest),
            'bytes' => $bytes,
            'stat' => $stat,
            'block_sha256' => $blockSha256,
        ];
    }

    /**
     * Bind one block layout to its file-generation attestation cache entry.
     *
     * @param array<int,array{offset:int,length:int}> $blocks
     */
    private function runtime_block_layout_key(array $blocks): string
    {
        if ($blocks === [] || count($blocks) > WP_FTS_Analyzer_Config_Limits::MAX_LOOKUP_BLOCKS_PER_FILE) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'lookup_blocks',
                'Analyzer pack lookup exceeds the 256-block per-file limit.'
            );
        }
        $parts = [];
        foreach ($blocks as $block) {
            $offset = $block['offset'] ?? null;
            $length = $block['length'] ?? null;
            if (!is_int($offset) || !is_int($length) || $offset < 0 || $length < 1) {
                throw new RuntimeException('Indexed runtime block layout is invalid during attestation.');
            }
            $parts[] = $offset . ':' . $length;
        }

        return hash('sha256', implode(',', $parts));
    }

    /** Record one bounded successful whole-file attestation. */
    private function cache_file_attestation(string $key): void
    {
        if (isset(self::$digestAttestations[$key])) {
            return;
        }
        self::$digestAttestations[$key] = true;
        self::$digestAttestationOrder[] = $key;
        while (count(self::$digestAttestationOrder) > self::MAX_DIGEST_ATTESTATIONS) {
            $oldest = array_shift(self::$digestAttestationOrder);
            if (is_string($oldest)) {
                unset(self::$digestAttestations[$oldest]);
                $this->remove_runtime_block_attestations($oldest);
            }
        }
    }

    /**
     * Retain authenticated block digests under the configured 8,192-block cap.
     *
     * @param string[] $blockSha256
     */
    private function cache_runtime_block_attestation(string $key, string $layout, array $blockSha256): void
    {
        if (isset(self::$runtimeBlockAttestations[$key][$layout])) {
            return;
        }
        $blockCount = count($blockSha256);
        self::$runtimeBlockAttestations[$key][$layout] = $blockSha256;
        self::$runtimeBlockAttestationOrder[] = [
            'file' => $key,
            'layout' => $layout,
            'blocks' => $blockCount,
        ];
        self::$runtimeBlockAttestationCount += $blockCount;
        while (self::$runtimeBlockAttestationCount > self::MAX_RUNTIME_BLOCK_ATTESTATIONS) {
            $oldest = array_shift(self::$runtimeBlockAttestationOrder);
            if (!is_array($oldest)) {
                break;
            }
            unset(self::$runtimeBlockAttestations[$oldest['file']][$oldest['layout']]);
            if ((self::$runtimeBlockAttestations[$oldest['file']] ?? []) === []) {
                unset(self::$runtimeBlockAttestations[$oldest['file']]);
            }
            self::$runtimeBlockAttestationCount -= $oldest['blocks'];
        }
    }

    /** Remove every cached block layout owned by one evicted file generation. */
    private function remove_runtime_block_attestations(string $key): void
    {
        if (!isset(self::$runtimeBlockAttestations[$key])) {
            return;
        }
        foreach (self::$runtimeBlockAttestations[$key] as $blockSha256) {
            self::$runtimeBlockAttestationCount -= count($blockSha256);
        }
        unset(self::$runtimeBlockAttestations[$key]);
        self::$runtimeBlockAttestationOrder = array_values(array_filter(
            self::$runtimeBlockAttestationOrder,
            static fn(array $entry): bool => $entry['file'] !== $key
        ));
    }

    /**
     * Return the filesystem identity fields used to bind a digest cache entry.
     *
     * @param array<string|int,mixed> $stat
     * @return string[]
     */
    private function file_generation(array $stat, string $path): array
    {
        $generation = [];
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
            if (!isset($stat[$field]) || !is_int($stat[$field])) {
                throw new RuntimeException("Could not identify analyzer pack file generation for {$path}.");
            }
            $generation[] = (string) $stat[$field];
        }
        foreach (['mtime_nsec', 'ctime_nsec', 'mtimensec', 'ctimensec'] as $field) {
            if (isset($stat[$field]) && is_int($stat[$field])) {
                $generation[] = $field . '=' . $stat[$field];
            }
        }

        return $generation;
    }

    /** Confirm that fopen() reached the same generation previously statted. */
    private function same_file_generation(array $expected, array $stat): bool
    {
        return $expected === $this->file_generation($stat, 'opened analyzer pack file');
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
     * Reject oversized runtime+lookup payload sets before any full-file digest
     * or sidecar content read. This is the physical half of the fixed low-end
     * host envelope; decoded work is bounded independently per v2 block.
     *
     * @param array<string,mixed> $manifest
     */
    private function assert_runtime_lookup_pack_bytes(array $manifest, string $packDir): int
    {
        $bytes = 0;
        foreach ($manifest['runtime']['files'] as $file) {
            $paths = [(string) $file['path']];
            if (isset($file['lookup']['path']) && is_string($file['lookup']['path'])) {
                $paths[] = $file['lookup']['path'];
            }
            foreach ($paths as $relativePath) {
                $path = $this->runtime_file_path($packDir, $relativePath);
                $size = @filesize($path);
                if (!is_int($size) || $size < 0) {
                    throw new RuntimeException("Could not size analyzer pack file {$relativePath}.");
                }
                $bytes += $size;
                if ($bytes > WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK) {
                    throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                        'runtime_lookup_bytes',
                        'Analyzer pack runtime and lookup files exceed the 16 MiB limit.'
                    );
                }
            }
        }

        return $bytes;
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
     * @return array{path:string,manifest:array<string,mixed>,sha256:string}
     */
    private function load_validated_manifest(string $manifestPath): array
    {
        WP_FTS_Analyzer_Config_Limits::assert_path($manifestPath, 'Analyzer pack manifest path');
        $manifestPath = $this->canonical_file($manifestPath, 'manifest');
        $manifestData = $this->read_manifest($manifestPath);
        $manifest = $manifestData['manifest'];
        $this->validate_manifest_shape($manifest);
        $this->validate_manifest_pack_files($manifest, dirname($manifestPath));

        return [
            'path' => $manifestPath,
            'manifest' => $manifest,
            'sha256' => $manifestData['sha256'],
        ];
    }

    /** Reject a manifest generation that differs from aggregate preflight. */
    private function assert_expected_manifest_sha256(string $actual, ?string $expected): void
    {
        if ($expected !== null && !hash_equals($expected, $actual)) {
            throw new RuntimeException('Analyzer pack manifest changed after aggregate preflight.');
        }
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
            $handle = @gzopen($path, 'rb');
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
    private function close_runtime_file(mixed $handle, ?string $compression): void
    {
        if ($compression === self::RUNTIME_COMPRESSION_GZIP) {
            gzclose($handle);
            return;
        }

        fclose($handle);
    }

    /** Validate one parsed TSV token before it enters ordering or digest state. */
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
        if (!WP_FTS_TermNamespace::term_key_fits($token, $language)) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'runtime_token_bytes',
                "Runtime {$column} at {$path}:{$lineNumber} exceeds the storage term-key limit for {$language}."
            );
        }
        if ($normalizer->normalize_token($token, $language) !== $token) {
            throw new RuntimeException("Runtime {$column} at {$path}:{$lineNumber} is not normalized for {$language}.");
        }
    }

    /** Resolve an existing manifest-owned file after applying the path cap. */
    private function canonical_file(string $path, string $label): string
    {
        WP_FTS_Analyzer_Config_Limits::assert_path($path, "Analyzer pack {$label} path");
        $real = realpath($path);
        if (!is_string($real) || !is_file($real)) {
            throw new RuntimeException("Analyzer pack {$label} file does not exist: {$path}");
        }

        return $real;
    }

    /** Resolve a runtime path and reject every escape from its pack directory. */
    private function runtime_file_path(string $packDir, string $relativePath): string
    {
        WP_FTS_Analyzer_Config_Limits::assert_path($relativePath, 'Analyzer pack runtime file path');
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

    /** Resolve non-runtime evidence while preserving the same pack-root boundary. */
    private function pack_relative_file_path(string $packDir, string $relativePath, string $label): string
    {
        WP_FTS_Analyzer_Config_Limits::assert_path($relativePath, "Analyzer pack {$label} path");
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
