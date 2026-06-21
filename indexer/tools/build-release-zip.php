<?php
declare(strict_types=1);

/**
 * Builds the direct-install WordPress plugin ZIP for the Indexer package.
 *
 * The build stages the plugin through .distignore, installs production Composer
 * dependencies, prunes development artifacts from the staged tree, validates
 * the package boundary, then writes a ZIP rooted at indexer/.
 */
final class WP_FTS_ReleasePackageBuilder
{
    private const PLUGIN_DIR_NAME = 'indexer';
    private const DEFAULT_ZIP_NAME = 'wp-fts-indexer.zip';
    private const DETERMINISTIC_ZIP_MTIME = 946684800; // 2000-01-01T00:00:00Z.
    private const VENDOR_DEVELOPMENT_DIRS = ['test', 'tests', 'Tests', 'coverage'];
    private const PROHIBITED_RELATIVE_PATHS = [
        '.cao',
        '.distignore',
        '.git',
        '.gitignore',
        'goal.md',
        'playground/indexer-preview.zip',
        'resources/sources',
        'review-artifacts',
        'tests',
        'vendor/bin',
    ];

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

            foreach (['plugin-src', 'monorepo-root', 'build-dir', 'output'] as $name) {
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
     * @return array{build_dir:string,zip_path:string,sha256:string,removed_paths:string[],prohibited_paths:string[]}
     */
    public function build(array $options = []): array
    {
        $pluginSource = self::existing_directory((string) ($options['plugin_src'] ?? dirname(__DIR__)), 'plugin source');
        $monorepoRoot = self::existing_directory((string) ($options['monorepo_root'] ?? dirname($pluginSource)), 'monorepo root');
        $buildDir = (string) ($options['build_dir'] ?? self::default_build_dir());
        $zipPath = (string) ($options['output'] ?? ($buildDir . '/' . self::DEFAULT_ZIP_NAME));

        self::ensure_directory($buildDir);

        $stagePlugin = $buildDir . '/' . self::PLUGIN_DIR_NAME;
        $componentStage = $buildDir . '/components/full-text-search';
        self::remove_path($stagePlugin);
        self::remove_path($componentStage);
        self::ensure_directory($stagePlugin);
        self::ensure_directory(dirname($componentStage));

        $distignore = $pluginSource . '/.distignore';
        if (!is_file($distignore)) {
            throw new RuntimeException("Missing release exclude file: {$distignore}");
        }

        self::run_command([
            'rsync',
            '-a',
            '--delete',
            "--exclude-from={$distignore}",
            self::trailing_slash($pluginSource),
            self::trailing_slash($stagePlugin),
        ]);

        $componentSource = self::existing_directory($monorepoRoot . '/components/full-text-search', 'FTS component source');
        self::run_command([
            'rsync',
            '-a',
            '--delete',
            self::trailing_slash($componentSource),
            self::trailing_slash($componentStage),
        ]);

        self::run_command([
            'composer',
            'install',
            '--no-dev',
            '--optimize-autoloader',
            '--no-interaction',
            "--working-dir={$stagePlugin}",
        ]);

        $removedPaths = self::prune_staged_package($stagePlugin);
        $prohibitedPaths = self::find_prohibited_package_paths($stagePlugin);
        if ($prohibitedPaths !== []) {
            throw new RuntimeException(
                "Staged package still contains prohibited paths:\n" . implode("\n", $prohibitedPaths)
            );
        }

        self::create_zip_from_stage($stagePlugin, $zipPath);
        $sha256 = hash_file('sha256', $zipPath);
        if (!is_string($sha256)) {
            throw new RuntimeException("Could not hash release ZIP: {$zipPath}");
        }

        return [
            'build_dir' => $buildDir,
            'zip_path' => $zipPath,
            'sha256' => $sha256,
            'removed_paths' => $removedPaths,
            'prohibited_paths' => $prohibitedPaths,
        ];
    }

    /**
     * @return string[]
     */
    public static function prune_staged_package(string $stagePlugin): array
    {
        $stagePlugin = self::existing_directory($stagePlugin, 'staged plugin');
        $removed = [];

        foreach (self::PROHIBITED_RELATIVE_PATHS as $relativePath) {
            $path = $stagePlugin . '/' . $relativePath;
            if (file_exists($path) || is_link($path)) {
                self::remove_path($path);
                $removed[] = self::package_path($relativePath);
            }
        }

        $vendor = $stagePlugin . '/vendor';
        if (is_dir($vendor)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($vendor, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if (!$item->isDir()) {
                    continue;
                }

                $basename = $item->getBasename();
                if (!in_array($basename, self::VENDOR_DEVELOPMENT_DIRS, true)) {
                    continue;
                }

                $path = $item->getPathname();
                if (!is_dir($path)) {
                    continue;
                }

                $removed[] = self::package_path(self::relative_path($stagePlugin, $path));
                self::remove_path($path);
            }
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stagePlugin, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $basename = $item->getBasename();
            if ($basename === '' || $basename[0] !== '.') {
                continue;
            }

            $path = $item->getPathname();
            if (!file_exists($path) && !is_link($path)) {
                continue;
            }

            $removed[] = self::package_path(self::relative_path($stagePlugin, $path));
            self::remove_path($path);
        }

        $removed = array_values(array_unique($removed));
        sort($removed, SORT_STRING);

        return $removed;
    }

    /**
     * @return string[]
     */
    public static function find_prohibited_package_paths(string $stagePlugin): array
    {
        $stagePlugin = self::existing_directory($stagePlugin, 'staged plugin');
        $prohibited = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stagePlugin, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = self::relative_path($stagePlugin, $item->getPathname());
            $basename = $item->getBasename();
            if ($basename !== '' && $basename[0] === '.') {
                $prohibited[] = self::package_path($relativePath);
                continue;
            }

            foreach (self::PROHIBITED_RELATIVE_PATHS as $blocked) {
                if ($relativePath === $blocked || str_starts_with($relativePath, $blocked . '/')) {
                    $prohibited[] = self::package_path($relativePath);
                    continue 2;
                }
            }

            if (str_starts_with($relativePath, 'vendor/')) {
                $parts = explode('/', $relativePath);
                foreach ($parts as $part) {
                    if (in_array($part, self::VENDOR_DEVELOPMENT_DIRS, true)) {
                        $prohibited[] = self::package_path($relativePath);
                        continue 2;
                    }
                }
            }
        }

        $prohibited = array_values(array_unique($prohibited));
        sort($prohibited, SORT_STRING);

        return $prohibited;
    }

    public static function create_zip_from_stage(string $stagePlugin, string $zipPath): void
    {
        $stagePlugin = self::existing_directory($stagePlugin, 'staged plugin');
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The PHP zip extension is required to create the release archive.');
        }

        self::ensure_directory(dirname($zipPath));
        if (file_exists($zipPath) && !unlink($zipPath)) {
            throw new RuntimeException("Could not replace existing ZIP: {$zipPath}");
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException("Could not create release ZIP at {$zipPath}; ZipArchive error {$opened}.");
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stagePlugin, FilesystemIterator::SKIP_DOTS),
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
            $archiveName = self::package_path(self::relative_path($stagePlugin, $path));
            if (!$zip->addFile($path, $archiveName)) {
                $zip->close();
                throw new RuntimeException("Could not add {$archiveName} to release ZIP.");
            }
            self::normalize_zip_entry_metadata($zip, $archiveName);
        }

        if (!$zip->close()) {
            throw new RuntimeException("Could not finalize release ZIP: {$zipPath}");
        }
    }

    public static function usage(): string
    {
        return implode("\n", [
            'Usage: php indexer/tools/build-release-zip.php [options]',
            '',
            'Options:',
            '  --build-dir=PATH       Directory used for staging and default ZIP output.',
            '  --output=PATH          Final ZIP path. Defaults to BUILD/wp-fts-indexer.zip.',
            '  --plugin-src=PATH      Plugin source directory. Defaults to this script parent.',
            '  --monorepo-root=PATH   Monorepo root. Defaults to the plugin source parent.',
            '  -h, --help             Show this help.',
            '',
        ]);
    }

    private static function default_build_dir(): string
    {
        return sys_get_temp_dir() . '/wp-fts-indexer-release-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
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

    /**
     * @param array<int,string> $command
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private static function run_command(array $command): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start command: ' . self::format_command($command));
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $exit = is_int($exit) ? $exit : 1;

        if ($exit !== 0) {
            $message = "Command failed with exit {$exit}: " . self::format_command($command);
            if ($stdout !== '') {
                $message .= "\nSTDOUT:\n{$stdout}";
            }
            if ($stderr !== '') {
                $message .= "\nSTDERR:\n{$stderr}";
            }
            throw new RuntimeException($message);
        }

        return [
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * @param array<int,string> $command
     */
    private static function format_command(array $command): string
    {
        return implode(' ', array_map('escapeshellarg', $command));
    }

    private static function trailing_slash(string $path): string
    {
        return rtrim($path, '/') . '/';
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

    private static function package_path(string $relativePath): string
    {
        return self::PLUGIN_DIR_NAME . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $options = WP_FTS_ReleasePackageBuilder::parse_cli_options(array_slice($argv, 1));
        if (!empty($options['help'])) {
            fwrite(STDOUT, WP_FTS_ReleasePackageBuilder::usage());
            exit(0);
        }

        $result = (new WP_FTS_ReleasePackageBuilder())->build($options);
        fwrite(STDOUT, json_encode([
            'status' => 'ok',
            'build_dir' => $result['build_dir'],
            'zip_path' => $result['zip_path'],
            'sha256' => $result['sha256'],
            'removed_paths' => $result['removed_paths'],
            'prohibited_paths' => $result['prohibited_paths'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    } catch (Throwable $e) {
        fwrite(STDERR, "Release package build failed: {$e->getMessage()}\n");
        exit(1);
    }
}
