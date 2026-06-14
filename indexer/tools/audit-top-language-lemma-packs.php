<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

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
            continue;
        }

        throw new InvalidArgumentException("Unknown option: {$arg}");
    }

    if (!is_string($options['pack_root']) || trim($options['pack_root']) === '') {
        throw new InvalidArgumentException('Missing required --pack-root=/path option.');
    }
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
    $json = file_get_contents($path);
    if (!is_string($json)) {
        throw new RuntimeException("Could not read language pack registry: {$path}");
    }

    $registry = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
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

    $directory = new RecursiveDirectoryIterator($packRoot, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator(
        $directory,
        static function (SplFileInfo $file) use ($skipDirectories): bool {
            if ($file->isDir() && isset($skipDirectories[$file->getFilename()])) {
                return false;
            }

            return true;
        }
    );
    $iterator = new RecursiveIteratorIterator($filter);
    $paths = [];
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename() === 'manifest.json') {
            $paths[] = $file->getPathname();
        }
    }
    sort($paths, SORT_STRING);

    return $paths;
}

/**
 * @return string|null
 */
function wp_fts_top_language_pack_audit_loose_manifest_language(string $path): ?string
{
    $json = @file_get_contents($path);
    if (!is_string($json)) {
        return null;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || !is_string($decoded['language'] ?? null)) {
        return null;
    }

    return (new WP_FTS_Normalizer())->canonicalize_language((string) $decoded['language']);
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
    try {
        $result = (new WP_FTS_AnalyzerPackValidator())->validate_metadata($path);
        $manifest = $result['manifest'];
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

        return [
            'expected_language' => $expectedLanguage,
            'manifest_language' => $expectedLanguage ?? wp_fts_top_language_pack_audit_loose_manifest_language($path),
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
        $candidate = $explicitByLanguage[$language] ?? null;
        if ($candidate === null && isset($discoveredByLanguage[$language])) {
            $candidate = wp_fts_top_language_pack_audit_best_candidate($discoveredByLanguage[$language]);
        }

        $row = [
            'language' => $language,
            'label' => (string) $languageConfig['label'],
            'role' => (string) $languageConfig['role'],
            'pack_required' => (bool) $languageConfig['pack_required'],
            'status' => 'missing_pack',
            'pack_id' => null,
            'version' => null,
            'manifest' => null,
        ];

        if ($candidate !== null) {
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
        if (($row['pack_required'] ?? false) === true && ($row['status'] ?? null) !== 'pack_backed') {
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
            "%s\t%s\t%s\t%s\t%s\n",
            (string) $row['language'],
            (string) $row['label'],
            (string) $row['role'],
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
