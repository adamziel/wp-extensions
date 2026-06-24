<?php
declare(strict_types=1);

require_once __DIR__ . '/release-channel-policy.php';

/**
 * Builds the optional extended analyzer-pack bundle.
 *
 * The bundle is not an installable plugin ZIP. It contains separately licensed
 * language packs plus machine-readable license and provenance summaries.
 */
final class WP_FTS_LanguagePackBundleBuilder
{
    private const BUNDLE_DIR_NAME = 'language-fts-extended-language-packs';
    private const DEFAULT_ZIP_NAME = 'language-fts-extended-language-packs.zip';
    private const DETERMINISTIC_ZIP_MTIME = 946684800; // 2000-01-01T00:00:00Z.

    /**
     * @param array<int,string> $args
     * @return array<string,mixed>
     */
    public static function parse_cli_options(array $args): array
    {
        $options = [];
        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;
                continue;
            }

            foreach (['plugin-src', 'build-dir', 'output', 'profile'] as $name) {
                $prefix = "--{$name}=";
                if (str_starts_with($arg, $prefix)) {
                    $key = str_replace('-', '_', $name);
                    $options[$key] = substr($arg, strlen($prefix));
                    continue 2;
                }
            }

            throw new InvalidArgumentException("Unknown option: {$arg}");
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function build(array $options = []): array
    {
        $pluginSource = self::existing_directory((string) ($options['plugin_src'] ?? dirname(__DIR__)), 'plugin source');
        $profile = WP_FTS_ReleaseChannelPolicy::normalize_profile((string) ($options['profile'] ?? WP_FTS_ReleaseChannelPolicy::PROFILE_EXTENDED_LANGUAGE_PACKS));

        if ($profile !== WP_FTS_ReleaseChannelPolicy::PROFILE_EXTENDED_LANGUAGE_PACKS) {
            throw new InvalidArgumentException('The language-pack bundle builder only supports the extended-language-packs profile.');
        }

        $buildDir = (string) ($options['build_dir'] ?? self::default_build_dir());
        $zipPath = (string) ($options['output'] ?? ($buildDir . '/' . self::DEFAULT_ZIP_NAME));
        $bundleRoot = rtrim($buildDir, '/') . '/' . self::BUNDLE_DIR_NAME;

        self::ensure_directory($buildDir);
        self::remove_path($bundleRoot);
        self::ensure_directory($bundleRoot . '/analyzer-packs');

        $included = [];
        $excluded = [];

        foreach (WP_FTS_ReleaseChannelPolicy::analyzer_pack_rows($pluginSource) as $row) {
            $publicRow = WP_FTS_ReleaseChannelPolicy::public_row($row);

            if (!WP_FTS_ReleaseChannelPolicy::profile_allows_row($profile, $row)) {
                $excluded[] = $publicRow;
                continue;
            }

            $source = $pluginSource . '/' . $row['path'];
            $destination = $bundleRoot . '/analyzer-packs/' . $row['pack_id'];
            self::copy_directory($source, $destination);
            $publicRow['bundle_path'] = 'analyzer-packs/' . $row['pack_id'];
            $included[] = $publicRow;
        }

        WP_FTS_ReleaseChannelPolicy::write_extended_bundle_metadata($bundleRoot, $included, $excluded);
        self::create_zip_from_directory($bundleRoot, $zipPath, self::BUNDLE_DIR_NAME);

        $sha256 = hash_file('sha256', $zipPath);
        if (!is_string($sha256)) {
            throw new RuntimeException("Could not hash language-pack bundle ZIP: {$zipPath}");
        }

        return [
            'profile' => $profile,
            'build_dir' => $buildDir,
            'bundle_root' => $bundleRoot,
            'zip_path' => $zipPath,
            'sha256' => $sha256,
            'included_packs' => $included,
            'excluded_packs' => $excluded,
        ];
    }

    public static function usage(): string
    {
        return implode("\n", [
            'Usage: php indexer/tools/build-language-pack-bundle.php [options]',
            '',
            'Options:',
            '  --build-dir=PATH       Directory used for staging and default ZIP output.',
            '  --output=PATH          Final ZIP path. Defaults to BUILD/language-fts-extended-language-packs.zip.',
            '  --profile=PROFILE      extended-language-packs. This is the default and only bundle profile.',
            '  --plugin-src=PATH      Plugin source directory. Defaults to this script parent.',
            '  -h, --help             Show this help.',
            '',
        ]);
    }

    private static function default_build_dir(): string
    {
        return sys_get_temp_dir() . '/wp-fts-language-pack-bundle-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    }

    private static function create_zip_from_directory(string $root, string $zipPath, string $archiveRoot): void
    {
        $root = self::existing_directory($root, 'bundle root');
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The PHP zip extension is required to create the language-pack bundle archive.');
        }

        self::ensure_directory(dirname($zipPath));
        if (file_exists($zipPath) && !unlink($zipPath)) {
            throw new RuntimeException("Could not replace existing ZIP: {$zipPath}");
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException("Could not create language-pack bundle ZIP at {$zipPath}; ZipArchive error {$opened}.");
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $files[] = $item->getPathname();
        }
        sort($files, SORT_STRING);

        foreach ($files as $path) {
            $archiveName = rtrim($archiveRoot, '/') . '/' . self::relative_path($root, $path);
            if (!$zip->addFile($path, $archiveName)) {
                $zip->close();
                throw new RuntimeException("Could not add {$archiveName} to language-pack bundle ZIP.");
            }
            self::normalize_zip_entry_metadata($zip, $archiveName);
        }

        if (!$zip->close()) {
            throw new RuntimeException("Could not finalize language-pack bundle ZIP: {$zipPath}");
        }
    }

    private static function copy_directory(string $source, string $destination): void
    {
        $source = self::existing_directory($source, 'analyzer pack source');
        self::remove_path($destination);
        self::ensure_directory($destination);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = self::relative_path($source, $item->getPathname());
            $target = $destination . '/' . $relativePath;
            $basename = $item->getBasename();

            if ($basename === '.git' || str_starts_with($relativePath, '.git/')) {
                continue;
            }

            if ($item->isDir() && !$item->isLink()) {
                self::ensure_directory($target);
                continue;
            }

            self::ensure_directory(dirname($target));
            if (!copy($item->getPathname(), $target)) {
                throw new RuntimeException("Could not copy analyzer pack file: {$relativePath}");
            }
        }
    }

    private static function normalize_zip_entry_metadata(ZipArchive $zip, string $archiveName): void
    {
        if (method_exists($zip, 'setMtimeName') && !$zip->setMtimeName($archiveName, self::DETERMINISTIC_ZIP_MTIME)) {
            throw new RuntimeException("Could not normalize ZIP mtime for {$archiveName}.");
        }

        if (method_exists($zip, 'setExternalAttributesName')) {
            $permissions = str_ends_with($archiveName, '.sh') ? 0100755 : 0100644;
            if (!$zip->setExternalAttributesName($archiveName, ZipArchive::OPSYS_UNIX, $permissions << 16)) {
                throw new RuntimeException("Could not normalize ZIP attributes for {$archiveName}.");
            }
        }
    }

    private static function existing_directory(string $path, string $label): string
    {
        $real = realpath($path);
        if (!is_string($real) || !is_dir($real)) {
            throw new RuntimeException("Missing {$label} directory: {$path}");
        }

        return rtrim($real, '/');
    }

    private static function ensure_directory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException("Could not create directory: {$path}");
        }
    }

    private static function remove_path(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) {
                throw new RuntimeException("Could not remove file: {$path}");
            }
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                if (!rmdir($item->getPathname())) {
                    throw new RuntimeException("Could not remove directory: {$item->getPathname()}");
                }
                continue;
            }

            if (!unlink($item->getPathname())) {
                throw new RuntimeException("Could not remove file: {$item->getPathname()}");
            }
        }

        if (!rmdir($path)) {
            throw new RuntimeException("Could not remove directory: {$path}");
        }
    }

    private static function relative_path(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }

        return ltrim($path, '/');
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $options = WP_FTS_LanguagePackBundleBuilder::parse_cli_options(array_slice($argv, 1));
        if (!empty($options['help'])) {
            fwrite(STDOUT, WP_FTS_LanguagePackBundleBuilder::usage());
            exit(0);
        }

        $result = (new WP_FTS_LanguagePackBundleBuilder())->build($options);
        fwrite(STDOUT, json_encode([
            'status' => 'ok',
            'profile' => $result['profile'],
            'build_dir' => $result['build_dir'],
            'bundle_root' => $result['bundle_root'],
            'zip_path' => $result['zip_path'],
            'sha256' => $result['sha256'],
            'included_packs' => $result['included_packs'],
            'excluded_packs' => $result['excluded_packs'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    } catch (Throwable $e) {
        fwrite(STDERR, "Language-pack bundle build failed: {$e->getMessage()}\n");
        exit(1);
    }
}
