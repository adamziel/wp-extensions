<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

const WP_FTS_TOP_LANGUAGE_AUDIT_MAX_MANIFESTS = 256;
const WP_FTS_TOP_LANGUAGE_AUDIT_MAX_DEPTH = 8;
const WP_FTS_TOP_LANGUAGE_AUDIT_MAX_ENTRIES = 4096;
const WP_FTS_TOP_LANGUAGE_AUDIT_MAX_PATH_BYTES = 262144;

/**
 * @return array{
 *   pack_root:string,
 *   manifests:array<int,array{language:string,path:string}>,
 *   json:bool,
 *   require_pack_backed:bool
 * }
 */
function wp_fts_top_language_pack_audit_parse_args(array $argv): array
{
    $options = [
        'pack_root' => '',
        'manifests' => [],
        'json' => false,
        'require_pack_backed' => false,
    ];
    $normalizer = new WP_FTS_Normalizer();

    foreach (array_slice($argv, 1) as $arg) {
        $arg = (string) $arg;
        if ($arg === '--json') {
            $options['json'] = true;
            continue;
        }
        if ($arg === '--require-pack-backed') {
            $options['require_pack_backed'] = true;
            continue;
        }
        if (str_starts_with($arg, '--pack-root=')) {
            $options['pack_root'] = substr($arg, strlen('--pack-root='));
            continue;
        }
        if (str_starts_with($arg, '--manifest=')) {
            $value = substr($arg, strlen('--manifest='));
            $separator = strpos($value, ':');
            if ($separator === false || $separator === 0 || $separator === strlen($value) - 1) {
                throw new InvalidArgumentException('Expected --manifest=<lang>:/path/to/manifest.json.');
            }

            $language = substr($value, 0, $separator);
            if (preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8}){0,3}$/', $language) !== 1) {
                throw new InvalidArgumentException("Invalid manifest language tag: {$language}");
            }
            $options['manifests'][] = [
                'language' => $normalizer->canonicalize_language($language),
                'path' => substr($value, $separator + 1),
            ];
            if (count($options['manifests']) > WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_LANGUAGES) {
                throw new InvalidArgumentException('Explicit audit manifests exceed the 32-language limit.');
            }
            WP_FTS_Analyzer_Config_Limits::assert_path(
                (string) $options['manifests'][count($options['manifests']) - 1]['path'],
                'Explicit audit manifest path'
            );
            continue;
        }

        throw new InvalidArgumentException("Unknown option: {$arg}");
    }

    if (!is_string($options['pack_root']) || trim($options['pack_root']) === '') {
        throw new InvalidArgumentException('Missing required --pack-root=/path option.');
    }
    WP_FTS_Analyzer_Config_Limits::assert_path($options['pack_root'], 'Audit pack-root path');
    $packRoot = realpath($options['pack_root']);
    if (!is_string($packRoot) || !is_dir($packRoot)) {
        throw new InvalidArgumentException("Pack root does not exist or is not a directory: {$options['pack_root']}");
    }
    $options['pack_root'] = $packRoot;

    return $options;
}

/**
 * @return array{schema_version:string,languages:array<int,array<string,mixed>>}
 */
function wp_fts_top_language_pack_audit_load_registry(): array
{
    $path = dirname(__DIR__) . '/config/top-language-lemma-packs.json';
    $json = wp_fts_top_language_pack_audit_bounded_read($path, WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES);
    if ($json === null) {
        throw new RuntimeException("Could not read language pack registry: {$path}");
    }

    $registry = json_decode($json, true, WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_GRAPH_DEPTH + 2, JSON_THROW_ON_ERROR);
    if (!is_array($registry) || ($registry['schema_version'] ?? null) !== 'wp-fts-top-language-lemma-packs/v1' || !is_array($registry['languages'] ?? null)) {
        throw new RuntimeException('Top-language lemma-pack registry has an invalid shape.');
    }

    return $registry;
}

/**
 * @return string[]
 */
function wp_fts_top_language_pack_audit_discover_manifests(string $packRoot): array
{
    $skipDirectories = [
        '.aws' => true,
        '.cao' => true,
        '.git' => true,
        '.hg' => true,
        '.ssh' => true,
        '.svn' => true,
        'node_modules' => true,
        'vendor' => true,
    ];

    $canonicalRoot = realpath($packRoot);
    if (!is_string($canonicalRoot) || !is_dir($canonicalRoot)) {
        throw new RuntimeException("Could not resolve analyzer-pack root: {$packRoot}");
    }
    $rootPrefix = rtrim($canonicalRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $paths = [];
    $entries = 0;
    $pathBytes = 0;
    $walk = function (string $directory, int $depth) use (
        &$walk,
        &$paths,
        &$entries,
        &$pathBytes,
        $skipDirectories,
        $rootPrefix
    ): void {
        try {
            $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException $error) {
            throw new RuntimeException("Could not read analyzer-pack directory: {$directory}", 0, $error);
        }
        foreach ($iterator as $entry) {
            $entries++;
            if ($entries > WP_FTS_TOP_LANGUAGE_AUDIT_MAX_ENTRIES) {
                throw new RuntimeException('Analyzer-pack discovery exceeds the 4,096-entry limit.');
            }
            if ($entry->isLink()) {
                continue;
            }
            $real = realpath($entry->getPathname());
            if (!is_string($real) || !str_starts_with($real, $rootPrefix)) {
                throw new RuntimeException('Analyzer-pack discovery encountered an entry outside its canonical root.');
            }
            $relative = substr($real, strlen($rootPrefix));
            $pathBytes += strlen($relative);
            if ($pathBytes > WP_FTS_TOP_LANGUAGE_AUDIT_MAX_PATH_BYTES) {
                throw new RuntimeException('Analyzer-pack discovery exceeds the 256 KiB aggregate path limit.');
            }
            if ($entry->isDir()) {
                if (isset($skipDirectories[$entry->getFilename()])) {
                    continue;
                }
                if ($depth >= WP_FTS_TOP_LANGUAGE_AUDIT_MAX_DEPTH) {
                    throw new RuntimeException('Analyzer-pack discovery exceeds the eight-directory depth limit.');
                }
                $walk($real, $depth + 1);
                continue;
            }
            if (!$entry->isFile() || $entry->getFilename() !== 'manifest.json') {
                continue;
            }
            $paths[] = $real;
            if (count($paths) > WP_FTS_TOP_LANGUAGE_AUDIT_MAX_MANIFESTS) {
                throw new RuntimeException('Analyzer-pack discovery exceeds the 256-manifest limit.');
            }
        }
    };
    $walk($canonicalRoot, 0);
    sort($paths, SORT_STRING);

    return $paths;
}

/**
 * @return string|null
 */
function wp_fts_top_language_pack_audit_loose_manifest_identity(string $path): ?array
{
    $json = wp_fts_top_language_pack_audit_bounded_read($path, WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES);
    if ($json === null) {
        return null;
    }
    $decoded = json_decode($json, true, WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_GRAPH_DEPTH + 2);
    if (!is_array($decoded) || !is_string($decoded['language'] ?? null)) {
        return null;
    }

    return [
        'language' => (new WP_FTS_Normalizer())->canonicalize_language((string) $decoded['language']),
        'pack_id' => is_string($decoded['pack_id'] ?? null) ? $decoded['pack_id'] : null,
        'version' => is_string($decoded['version'] ?? null) ? $decoded['version'] : null,
        'fixture_only' => is_bool($decoded['fixture_only'] ?? null) ? $decoded['fixture_only'] : null,
    ];
}

/** Read one manifest only when both stat and stream stay inside its byte cap. */
function wp_fts_top_language_pack_audit_bounded_read(string $path, int $maxBytes): ?string
{
    $size = @filesize($path);
    if (!is_int($size) || $size < 1 || $size > $maxBytes) {
        return null;
    }
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        return null;
    }
    try {
        $contents = stream_get_contents($handle, $maxBytes + 1);
    } finally {
        fclose($handle);
    }

    return is_string($contents) && strlen($contents) <= $maxBytes ? $contents : null;
}

/**
 * @return array{
 *   expected_language:?string,
 *   manifest_language:?string,
 *   status:string,
 *   pack_id:?string,
 *   version:?string,
 *   manifest:string,
 *   source:string,
 *   error?:string
 * }
 */
function wp_fts_top_language_pack_audit_manifest_candidate(string $path, ?string $expectedLanguage, string $source): array
{
    $identity = null;
    try {
        $result = (new WP_FTS_AnalyzerPackValidator())->validate_metadata($path);
        $manifest = $result['manifest'];
        $allIndexed = true;
        $allPlain = true;
        foreach ($result['runtime_files'] as $runtimeFile) {
            $allIndexed = $allIndexed && isset($runtimeFile['lookup']);
            $allPlain = $allPlain && !isset($runtimeFile['compression']);
        }
        $eagerFixture = (bool) $manifest['fixture_only']
            && $allPlain
            && (int) $result['runtime_rows'] <= WP_FTS_LemmaPackLimits::MAX_EAGER_FIXTURE_ROWS
            && (int) $result['runtime_lookup_bytes'] <= WP_FTS_LemmaPackLimits::MAX_EAGER_FIXTURE_RUNTIME_BYTES;
        if (!$allIndexed && !$eagerFixture) {
            throw new RuntimeException('Analyzer pack runtime storage cannot activate within the eager or indexed lookup envelope.');
        }
        $manifestLanguage = (string) $manifest['language'];
        $status = ((bool) $manifest['fixture_only']) ? 'fixture_only' : 'pack_backed';
        if ($expectedLanguage !== null && $manifestLanguage !== $expectedLanguage) {
            $status = 'language_mismatch';
        }

        return [
            'expected_language' => $expectedLanguage,
            'manifest_language' => $manifestLanguage,
            'status' => $status,
            'pack_id' => (string) $manifest['pack_id'],
            'version' => (string) $manifest['version'],
            'manifest' => (string) $result['manifest_path'],
            'source' => $source,
        ];
    } catch (Throwable $e) {
        $real = realpath($path);
        $identity = wp_fts_top_language_pack_audit_loose_manifest_identity($path);

        return [
            'expected_language' => $expectedLanguage,
            'manifest_language' => $expectedLanguage ?? ($identity['language'] ?? null),
            'status' => 'invalid_pack',
            'pack_id' => null,
            'version' => null,
            'manifest' => is_string($real) ? $real : $path,
            'source' => $source,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * @param array<int,array<string,mixed>> $candidates
 * @return array<string,mixed>
 */
function wp_fts_top_language_pack_audit_best_candidate(array $candidates): array
{
    $priority = [
        'pack_backed' => 4,
        'fixture_only' => 3,
        'invalid_pack' => 2,
        'language_mismatch' => 1,
    ];
    usort(
        $candidates,
        static function (array $a, array $b) use ($priority): int {
            $statusOrder = ($priority[(string) $b['status']] ?? 0) <=> ($priority[(string) $a['status']] ?? 0);
            if ($statusOrder !== 0) {
                return $statusOrder;
            }

            return strcmp((string) $a['manifest'], (string) $b['manifest']);
        }
    );

    return $candidates[0];
}

/**
 * @param array{schema_version:string,languages:array<int,array<string,mixed>>} $registry
 * @param array<int,array{language:string,path:string}> $explicitManifests
 * @return array<int,array<string,mixed>>
 */
function wp_fts_top_language_pack_audit_rows(array $registry, string $packRoot, array $explicitManifests): array
{
    $discoveredByLanguage = [];
    foreach (wp_fts_top_language_pack_audit_discover_manifests($packRoot) as $path) {
        $candidate = wp_fts_top_language_pack_audit_manifest_candidate($path, null, 'pack-root');
        $language = $candidate['manifest_language'];
        if (is_string($language) && $language !== '') {
            $discoveredByLanguage[$language][] = $candidate;
        }
    }

    $explicitByLanguage = [];
    foreach ($explicitManifests as $manifest) {
        $explicitByLanguage[$manifest['language']] = wp_fts_top_language_pack_audit_manifest_candidate(
            $manifest['path'],
            $manifest['language'],
            'explicit'
        );
    }

    $rows = [];
    foreach ($registry['languages'] as $languageConfig) {
        if (!is_array($languageConfig)) {
            throw new RuntimeException('Top-language registry language entries must be objects.');
        }
        foreach (['language', 'label', 'role', 'pack_required'] as $field) {
            if (!array_key_exists($field, $languageConfig)) {
                throw new RuntimeException("Top-language registry language entry missing {$field}.");
            }
        }

        $language = (string) $languageConfig['language'];
        $supportKind = (string) ($languageConfig['support_kind'] ?? 'lemma_pack');
        $candidate = $explicitByLanguage[$language] ?? null;
        if ($candidate === null && isset($discoveredByLanguage[$language])) {
            $candidate = wp_fts_top_language_pack_audit_best_candidate($discoveredByLanguage[$language]);
        }

        $row = [
            'language' => $language,
            'label' => (string) $languageConfig['label'],
            'role' => (string) $languageConfig['role'],
            'support_kind' => $supportKind,
            'pack_required' => (bool) $languageConfig['pack_required'],
            'status' => 'missing_pack',
            'pack_id' => null,
            'version' => null,
            'manifest' => null,
        ];

        if ($supportKind === 'tokenizer') {
            $row['status'] = 'tokenizer_supported';
            $row['notes'] = (string) ($languageConfig['notes'] ?? '');
        } elseif ($supportKind === 'license_blocked') {
            $row['status'] = 'license_blocked';
            $row['blocker'] = (string) ($languageConfig['blocker'] ?? 'Redistribution review is incomplete.');
            $row['notes'] = (string) ($languageConfig['notes'] ?? '');
        } elseif ($candidate !== null) {
            $row['status'] = $candidate['status'];
            $row['pack_id'] = $candidate['pack_id'];
            $row['version'] = $candidate['version'];
            $row['manifest'] = $candidate['manifest'];
            if (($candidate['manifest_language'] ?? null) !== null && $candidate['manifest_language'] !== $language) {
                $row['manifest_language'] = $candidate['manifest_language'];
            }
            if (isset($candidate['error'])) {
                $row['error'] = $candidate['error'];
            }
        }

        $rows[] = $row;
    }

    return $rows;
}

/**
 * @param array<int,array<string,mixed>> $rows
 */
function wp_fts_top_language_pack_audit_has_required_gap(array $rows): bool
{
    foreach ($rows as $row) {
        if (
            ($row['support_kind'] ?? 'lemma_pack') === 'lemma_pack'
            && ($row['pack_required'] ?? false) === true
            && ($row['status'] ?? null) !== 'pack_backed'
        ) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<int,array<string,mixed>> $rows
 */
function wp_fts_top_language_pack_audit_print_human(array $rows, bool $failed): void
{
    foreach ($rows as $row) {
        printf(
            "%s\t%s\t%s\t%s\t%s\t%s\n",
            (string) $row['language'],
            (string) $row['label'],
            (string) $row['role'],
            (string) ($row['support_kind'] ?? 'lemma_pack'),
            (string) $row['status'],
            (string) ($row['pack_id'] ?? '')
        );
    }
    if ($failed) {
        fwrite(STDERR, "One or more required top-language packs are not pack-backed.\n");
    }
}

/**
 * @param string[] $argv
 */
function wp_fts_top_language_pack_audit_main(array $argv): int
{
    $options = wp_fts_top_language_pack_audit_parse_args($argv);
    $registry = wp_fts_top_language_pack_audit_load_registry();
    $rows = wp_fts_top_language_pack_audit_rows($registry, $options['pack_root'], $options['manifests']);
    $failed = $options['require_pack_backed'] && wp_fts_top_language_pack_audit_has_required_gap($rows);

    if ($options['json']) {
        echo json_encode(
            [
                'schema_version' => $registry['schema_version'],
                'status' => $failed ? 'fail' : 'ok',
                'require_pack_backed' => $options['require_pack_backed'],
                'rows' => $rows,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ), "\n";
    } else {
        wp_fts_top_language_pack_audit_print_human($rows, $failed);
    }

    return $failed ? 1 : 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === __FILE__) {
    try {
        exit(wp_fts_top_language_pack_audit_main($argv));
    } catch (Throwable $e) {
        fwrite(STDERR, "Top-language lemma-pack audit failed: {$e->getMessage()}\n");
        exit(2);
    }
}
