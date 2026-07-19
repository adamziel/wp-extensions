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
    private const BUILD_LOCK_FILE = '.wp-fts-release-build.lock';
    private const JIEBA_DICTIONARY_SHA256 = '7197c3211ddd98962b036cdf40324d1ea2bfaa12bd028e68faa70111a88e12a8';
    private const JIEBA_DICTIONARY_BYTES = 5071852;
    private const JIEBA_LICENSE_SHA256 = '18ba0984839f85853b29fadaf992f7dba8fd0ca0fbeae34de2b8735222dc7a37';
    private const JIEBA_LICENSE_BYTES = 1075;
    private const JIEBA_LOOKUP_SHA256 = '4c979fd244e59b8343c2e584dbd5ba062deb1f836b8ae9ca2b56b54f130b9046';
    private const JIEBA_LOOKUP_BYTES = 329972;
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
        'auth.json',
        '.composer',
        'vendor/bin',
        'vendor/wp-php-toolkit/full-text-search/resources/sources',
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

            foreach (['plugin-src', 'monorepo-root', 'build-dir', 'output', 'composer-cache-dir'] as $name) {
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
     * @return array{build_dir:string,zip_path:string,sha256:string,removed_paths:string[],prohibited_paths:string[],composer_home:string,composer_cache_dir:string,composer_plugins:bool,composer_scripts:bool}
     */
    public function build(array $options = []): array
    {
        $pluginSource = self::existing_directory((string) ($options['plugin_src'] ?? dirname(__DIR__)), 'plugin source');
        $monorepoRoot = self::existing_directory((string) ($options['monorepo_root'] ?? dirname($pluginSource)), 'monorepo root');
        $buildDir = (string) ($options['build_dir'] ?? self::default_build_dir());
        $zipPath = (string) ($options['output'] ?? ($buildDir . '/' . self::DEFAULT_ZIP_NAME));
        $composerCacheDir = isset($options['composer_cache_dir'])
            ? (string) $options['composer_cache_dir']
            : null;

        $componentSource = self::existing_directory($monorepoRoot . '/components/full-text-search', 'FTS component source');
        self::assert_release_paths_safe(
            $pluginSource,
            $componentSource,
            $buildDir,
            $zipPath,
            $composerCacheDir
        );

        if (($options['skip_build_lock'] ?? false) === true) {
            return $this->build_unlocked($pluginSource, $monorepoRoot, $buildDir, $zipPath, $composerCacheDir);
        }

        return self::with_build_lock(
            $buildDir,
            fn(): array => $this->build_unlocked($pluginSource, $monorepoRoot, $buildDir, $zipPath, $composerCacheDir)
        );
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function with_build_lock(string $buildDir, callable $callback): mixed
    {
        self::ensure_directory($buildDir);
        $lockPath = rtrim($buildDir, '/') . '/' . self::BUILD_LOCK_FILE;
        $handle = fopen($lockPath, 'c');
        if (!is_resource($handle)) {
            throw new RuntimeException("Could not open release build lock: {$lockPath}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException("Could not acquire release build lock: {$lockPath}");
            }

            try {
                return $callback();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{build_dir:string,zip_path:string,sha256:string,removed_paths:string[],prohibited_paths:string[],composer_home:string,composer_cache_dir:string,composer_plugins:bool,composer_scripts:bool}
     */
    private function build_unlocked(
        string $pluginSource,
        string $monorepoRoot,
        string $buildDir,
        string $zipPath,
        ?string $composerCacheDir
    ): array
    {
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
            '--exclude=.git',
            self::trailing_slash($componentSource),
            self::trailing_slash($componentStage),
        ]);

        $sourceSymlinks = array_merge(
            self::find_symlink_paths($stagePlugin, self::PLUGIN_DIR_NAME),
            self::find_symlink_paths($componentStage, 'components/full-text-search')
        );
        if ($sourceSymlinks !== []) {
            throw new RuntimeException(
                "Staged source contains symlinks outside the immutable package boundary:\n" . implode("\n", $sourceSymlinks)
            );
        }

        $vcsMetadataPaths = array_merge(
            self::find_vcs_metadata_paths($stagePlugin, self::PLUGIN_DIR_NAME),
            self::find_vcs_metadata_paths($componentStage, 'components/full-text-search')
        );
        if ($vcsMetadataPaths !== []) {
            throw new RuntimeException(
                "Staged source contains prohibited local VCS metadata before dependency installation:\n" . implode("\n", $vcsMetadataPaths)
            );
        }

        $composerAuthPaths = array_merge(
            self::find_composer_auth_package_paths($stagePlugin, self::PLUGIN_DIR_NAME),
            self::find_composer_auth_package_paths($componentStage, 'components/full-text-search')
        );
        if ($composerAuthPaths !== []) {
            throw new RuntimeException(
                "Staged source contains Composer auth files before dependency installation:\n" . implode("\n", $composerAuthPaths)
            );
        }

        $composerEnv = self::composer_install_environment(
            self::current_environment(),
            $buildDir,
            $composerCacheDir
        );
        self::assert_composer_state_outside_package(
            $stagePlugin,
            $componentStage,
            $composerEnv['COMPOSER_HOME'],
            $composerEnv['COMPOSER_CACHE_DIR']
        );
        // The source checkout may contain a vendor tree from a prior Composer
        // install. Composer considers an installed path package at the same
        // locked version satisfied, even when its source files changed. Start
        // from an empty dependency tree so the ZIP cannot silently preserve a
        // stale analyzer/search runtime from the developer checkout.
        self::remove_path($stagePlugin . '/vendor');
        self::run_command([
            'composer',
            'install',
            '--no-dev',
            '--optimize-autoloader',
            '--no-interaction',
            '--no-plugins',
            '--no-scripts',
            '--no-progress',
            '--prefer-dist',
            "--working-dir={$stagePlugin}",
        ], $composerEnv);
        self::assert_component_runtime_matches_source($componentStage, $stagePlugin);
        self::install_pinned_jieba_runtime($componentStage, $pluginSource, $stagePlugin);

        $installedSymlinks = self::find_symlink_paths($stagePlugin, self::PLUGIN_DIR_NAME);
        if ($installedSymlinks !== []) {
            throw new RuntimeException(
                "Composer installation produced prohibited package symlinks:\n" . implode("\n", $installedSymlinks)
            );
        }

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
            'composer_home' => $composerEnv['COMPOSER_HOME'],
            'composer_cache_dir' => $composerEnv['COMPOSER_CACHE_DIR'],
            'composer_plugins' => false,
            'composer_scripts' => false,
        ];
    }

    /**
     * Prove that Composer installed the staged component rather than a stale
     * same-version path package left in the plugin source tree.
     */
    private static function assert_component_runtime_matches_source(
        string $componentStage,
        string $stagePlugin
    ): void {
        $source = self::existing_directory($componentStage . '/src', 'staged FTS component runtime');
        $installed = self::existing_directory(
            $stagePlugin . '/vendor/wp-php-toolkit/full-text-search/src',
            'installed FTS component runtime'
        );
        $sourceFiles = self::relative_file_hashes($source);
        $installedFiles = self::relative_file_hashes($installed);
        if ($sourceFiles !== $installedFiles) {
            $paths = array_values(array_unique(array_merge(
                array_keys(array_diff_assoc($sourceFiles, $installedFiles)),
                array_keys(array_diff_assoc($installedFiles, $sourceFiles))
            )));
            sort($paths, SORT_STRING);
            throw new RuntimeException(
                "Composer installed an FTS runtime that differs from the staged component source:\n"
                . implode("\n", $paths)
            );
        }
    }

    /** @return array<string,string> */
    private static function relative_file_hashes(string $directory): array
    {
        $hashes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $path = $file->getPathname();
            $relative = substr($path, strlen(rtrim($directory, '/')) + 1);
            $hash = hash_file('sha256', $path);
            if (!is_string($hash)) {
                throw new RuntimeException("Could not hash staged component runtime file: {$path}");
            }
            $hashes[str_replace(DIRECTORY_SEPARATOR, '/', $relative)] = $hash;
        }
        ksort($hashes, SORT_STRING);

        return $hashes;
    }

    /**
     * Package only the pinned Jieba dictionary and its MIT license, not the
     * upstream source checkout, tests, Python implementation, or model data.
     */
    private static function install_pinned_jieba_runtime(
        string $componentStage,
        string $pluginSource,
        string $stagePlugin
    ): void {
        $sourceRoot = $componentStage . '/resources/sources/jieba';
        $historicalSourceLayout = false;
        if (!is_file($sourceRoot . '/jieba/dict.txt')) {
            // Immutable pre-component-move baselines owned the same pinned
            // gitlink under indexer/resources. Accept that exact historical
            // location so the hardened current packager can build a faithful
            // baseline without borrowing current-branch source data.
            $sourceRoot = $pluginSource . '/resources/sources/jieba';
            $historicalSourceLayout = true;
        }
        $dictionary = $sourceRoot . '/jieba/dict.txt';
        $license = $sourceRoot . '/LICENSE';
        $lookup = $componentStage . '/resources/runtime/jieba/dict.idx';
        if (
            !is_file($dictionary)
            || filesize($dictionary) !== self::JIEBA_DICTIONARY_BYTES
            || hash_file('sha256', $dictionary) !== self::JIEBA_DICTIONARY_SHA256
        ) {
            throw new RuntimeException(
                'The pinned Jieba submodule dictionary is missing or invalid; initialize the exact gitlink before building a release.'
            );
        }
        if (
            !is_file($license)
            || filesize($license) !== self::JIEBA_LICENSE_BYTES
            || hash_file('sha256', $license) !== self::JIEBA_LICENSE_SHA256
        ) {
            throw new RuntimeException('The pinned Jieba MIT license is missing or invalid.');
        }
        if (
            !$historicalSourceLayout
            && (
                !is_file($lookup)
                || filesize($lookup) !== self::JIEBA_LOOKUP_BYTES
                || hash_file('sha256', $lookup) !== self::JIEBA_LOOKUP_SHA256
            )
        ) {
            throw new RuntimeException('The attested Jieba dictionary lookup index is missing or invalid.');
        }

        $runtime = $stagePlugin . '/vendor/wp-php-toolkit/full-text-search/resources/runtime/jieba';
        self::ensure_directory($runtime);
        $runtimeFiles = [$dictionary => $runtime . '/dict.txt', $license => $runtime . '/LICENSE'];
        if (!$historicalSourceLayout) {
            $runtimeFiles[$lookup] = $runtime . '/dict.idx';
        }
        foreach ($runtimeFiles as $source => $destination) {
            if (!copy($source, $destination)) {
                throw new RuntimeException("Could not stage the pinned Jieba runtime file: {$destination}");
            }
            @chmod($destination, 0644);
        }
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
            $relativePath = self::relative_path($stagePlugin, $item->getPathname());
            if (self::is_composer_auth_relative_path($relativePath)) {
                $path = $item->getPathname();
                if (file_exists($path) || is_link($path)) {
                    $removed[] = self::package_path($relativePath);
                    self::remove_path($path);
                }
                continue;
            }

            $basename = $item->getBasename();
            if ($basename === '' || $basename[0] !== '.') {
                continue;
            }

            $path = $item->getPathname();
            if (!file_exists($path) && !is_link($path)) {
                continue;
            }

            $removed[] = self::package_path($relativePath);
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
            if ($item->isLink()) {
                $prohibited[] = self::package_path($relativePath);
                continue;
            }
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
            if (self::is_composer_auth_relative_path($relativePath)) {
                $prohibited[] = self::package_path($relativePath);
                continue;
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
        $symlinks = self::find_symlink_paths($stagePlugin, self::PLUGIN_DIR_NAME);
        if ($symlinks !== []) {
            throw new RuntimeException(
                "Refusing to follow staged symlinks while creating the release ZIP:\n" . implode("\n", $symlinks)
            );
        }
        $sensitiveMetadata = array_merge(
            self::find_vcs_metadata_paths($stagePlugin, self::PLUGIN_DIR_NAME),
            self::find_composer_auth_package_paths($stagePlugin, self::PLUGIN_DIR_NAME)
        );
        if ($sensitiveMetadata !== []) {
            throw new RuntimeException(
                "Refusing to archive staged VCS or credential metadata:\n" . implode("\n", $sensitiveMetadata)
            );
        }
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
            '  --composer-cache-dir=PATH  Explicit archive cache; Composer home remains isolated.',
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
        if (!method_exists($zip, 'setMtimeName') || !method_exists($zip, 'setExternalAttributesName')) {
            throw new RuntimeException('The PHP zip extension cannot normalize deterministic release metadata.');
        }
        if (!$zip->setMtimeName($archiveName, self::DETERMINISTIC_ZIP_MTIME)) {
            throw new RuntimeException("Could not normalize ZIP mtime for {$archiveName}.");
        }

        $permissions = str_ends_with($archiveName, '.sh') ? 0100755 : 0100644;
        if (!$zip->setExternalAttributesName($archiveName, ZipArchive::OPSYS_UNIX, $permissions << 16)) {
            throw new RuntimeException("Could not normalize ZIP attributes for {$archiveName}.");
        }
    }

    private static function assert_release_paths_safe(
        string $pluginSource,
        string $componentSource,
        string $buildDir,
        string $zipPath,
        ?string $explicitComposerCache
    ): void {
        $buildDir = self::canonical_boundary_path($buildDir);
        $zipPath = self::canonical_boundary_path($zipPath);
        $stagePlugin = $buildDir . '/' . self::PLUGIN_DIR_NAME;
        $componentStage = $buildDir . '/components/full-text-search';
        $composerHome = $buildDir . '/composer-home';
        $composerCache = self::canonical_boundary_path(
            $explicitComposerCache !== null && $explicitComposerCache !== ''
                ? $explicitComposerCache
                : $buildDir . '/composer-cache'
        );

        foreach ([$pluginSource, $componentSource] as $source) {
            if (self::paths_overlap($source, $buildDir)) {
                throw new RuntimeException('Release build directory must not overlap immutable package source.');
            }
        }

        foreach ([$pluginSource, $componentSource, $stagePlugin, $componentStage, $composerHome, $composerCache] as $protectedPath) {
            if (self::paths_overlap($protectedPath, $zipPath)) {
                throw new RuntimeException('Release ZIP path must not overlap package source or mutable Composer state.');
            }
        }

        foreach ([$pluginSource, $componentSource, $stagePlugin, $componentStage, $composerHome] as $protectedPath) {
            if (self::paths_overlap($protectedPath, $composerCache)) {
                throw new RuntimeException('Composer cache must not overlap package source, staging, or Composer home.');
            }
        }
    }

    private static function assert_composer_state_outside_package(
        string $stagePlugin,
        string $componentStage,
        string $composerHome,
        string $composerCache
    ): void {
        $stagePlugin = self::existing_directory($stagePlugin, 'staged plugin');
        $componentStage = self::existing_directory($componentStage, 'staged component');
        $composerHome = self::existing_directory($composerHome, 'Composer home');
        $composerCache = self::existing_directory($composerCache, 'Composer cache');

        if ($composerHome === $composerCache) {
            throw new RuntimeException('Composer home and cache must be isolated from each other.');
        }
        foreach ([$stagePlugin, $componentStage] as $packageSource) {
            if (self::paths_overlap($packageSource, $composerHome) || self::paths_overlap($packageSource, $composerCache)) {
                throw new RuntimeException('Composer home and cache must not overlap staged package source.');
            }
        }
    }

    private static function paths_overlap(string $left, string $right): bool
    {
        $left = rtrim(str_replace('\\', '/', $left), '/');
        $right = rtrim(str_replace('\\', '/', $right), '/');

        return $left === $right
            || str_starts_with($left . '/', $right . '/')
            || str_starts_with($right . '/', $left . '/');
    }

    private static function canonical_boundary_path(string $path): string
    {
        if ($path === '') {
            throw new InvalidArgumentException('Release build paths must not be empty.');
        }

        $probe = $path;
        $suffixes = [];
        while (true) {
            $real = realpath($probe);
            if (is_string($real)) {
                $resolved = $real;
                break;
            }
            if (is_link($probe)) {
                throw new RuntimeException("Could not resolve release build path: {$path}");
            }

            $parent = dirname($probe);
            if ($parent === $probe) {
                throw new RuntimeException("Could not resolve release build path: {$path}");
            }
            array_unshift($suffixes, basename($probe));
            $probe = $parent;
        }

        $resolved = str_replace('\\', '/', $resolved . ($suffixes === [] ? '' : '/' . implode('/', $suffixes)));
        $prefix = '/';
        if (strlen($resolved) >= 3 && ctype_alpha($resolved[0]) && $resolved[1] === ':' && $resolved[2] === '/') {
            $prefix = substr($resolved, 0, 3);
            $resolved = substr($resolved, 3);
        } else {
            $resolved = ltrim($resolved, '/');
        }

        $parts = [];
        foreach (explode('/', $resolved) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return $prefix . implode('/', $parts);
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
     * @param array<string,string>|null $env
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private static function run_command(array $command, ?array $env = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, null, $env);
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
     * @param array<string,string> $env
     * @return array<string,string>
     */
    public static function composer_install_environment(
        array $env,
        string $buildDir,
        ?string $explicitCacheDir = null
    ): array
    {
        // Never inherit Composer home: it may contain auth.json, global plugins,
        // or mutable global configuration that is not identified by source SHA.
        $composerHome = rtrim($buildDir, '/') . '/composer-home';
        self::remove_path($composerHome);
        self::ensure_directory($composerHome);

        // A caller may explicitly provide an archive cache for an offline
        // historical build. Ambient cache state is never selected implicitly.
        $usesExplicitCache = $explicitCacheDir !== null && $explicitCacheDir !== '';
        $composerCacheDir = $usesExplicitCache
            ? $explicitCacheDir
            : rtrim($buildDir, '/') . '/composer-cache';
        if (!$usesExplicitCache) {
            self::remove_path($composerCacheDir);
        }
        self::ensure_directory($composerCacheDir);

        return self::scrub_process_environment($env, [
            'COMPOSER_HOME' => $composerHome,
            'COMPOSER_CACHE_DIR' => $composerCacheDir,
            'LANG' => 'C',
            'LC_ALL' => 'C',
            'TZ' => 'UTC',
            'SOURCE_DATE_EPOCH' => (string) self::DETERMINISTIC_ZIP_MTIME,
        ]);
    }

    /**
     * @param array<string,string> $env
     * @param array<string,string> $overrides
     * @return array<string,string>
     */
    private static function scrub_process_environment(array $env, array $overrides = []): array
    {
        $safe = [];
        foreach ($env as $key => $value) {
            if (!is_string($key) || !is_scalar($value) || !self::is_safe_process_environment_key($key)) {
                continue;
            }
            $safe[$key] = (string) $value;
        }

        foreach ($overrides as $key => $value) {
            if (!self::is_safe_process_environment_key($key)) {
                continue;
            }
            $safe[$key] = $value;
        }

        ksort($safe, SORT_STRING);

        return $safe;
    }

    private static function is_safe_process_environment_key(string $key): bool
    {
        $upper = strtoupper($key);
        if (str_starts_with($upper, 'GIT_') || str_starts_with($upper, 'SSH_')) {
            return false;
        }
        if (preg_match('/(?:TOKEN|SECRET|PASSWORD|PASS(?:PHRASE)?|CREDENTIAL|AUTH|COOKIE|API[_-]?KEY|ACCESS[_-]?KEY|PRIVATE[_-]?KEY)/i', $key) === 1) {
            return false;
        }

        return in_array($upper, [
            'COMPOSER_HOME',
            'COMPOSER_CACHE_DIR',
            'COMPOSER_DISABLE_NETWORK',
            'PATH',
            'TEMP',
            'TMP',
            'TMPDIR',
            'LANG',
            'LC_ALL',
            'LC_CTYPE',
            'TZ',
            'SOURCE_DATE_EPOCH',
            'SYSTEMROOT',
            'WINDIR',
            'COMSPEC',
            'PATHEXT',
        ], true);
    }

    /**
     * @return array<string,string>
     */
    private static function current_environment(): array
    {
        $env = getenv();
        if (!is_array($env)) {
            return [];
        }

        $normalized = [];
        foreach ($env as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
    }

    /**
     * @return string[]
     */
    private static function find_composer_auth_package_paths(
        string $stagePlugin,
        string $displayRoot = self::PLUGIN_DIR_NAME
    ): array
    {
        $stagePlugin = self::existing_directory($stagePlugin, 'staged plugin');
        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stagePlugin, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = self::relative_path($stagePlugin, $item->getPathname());
            if (self::is_composer_auth_relative_path($relativePath)) {
                $paths[] = rtrim($displayRoot, '/') . '/' . $relativePath;
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Find local repository/configuration files that can expose VCS credentials.
     *
     * @return string[]
     */
    public static function find_vcs_metadata_paths(
        string $root,
        string $displayRoot = self::PLUGIN_DIR_NAME
    ): array {
        $root = self::existing_directory($root, 'VCS metadata scan root');
        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relativePath = self::relative_path($root, $item->getPathname());
            $basename = strtolower($item->getBasename());
            if (!in_array($basename, ['.git', '.gitconfig', '.git-credentials', '.netrc'], true)) {
                continue;
            }
            $paths[] = rtrim($displayRoot, '/') . '/' . $relativePath;
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @return string[]
     */
    public static function find_symlink_paths(string $root, string $displayRoot = self::PLUGIN_DIR_NAME): array
    {
        $root = self::existing_directory($root, 'symlink scan root');
        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                $paths[] = rtrim($displayRoot, '/') . '/' . self::relative_path($root, $item->getPathname());
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }

    private static function is_composer_auth_relative_path(string $relativePath): bool
    {
        $relativePath = strtolower(ltrim(str_replace('\\', '/', $relativePath), '/'));
        if ($relativePath === '') {
            return false;
        }

        return basename($relativePath) === 'auth.json'
            || $relativePath === '.composer'
            || str_starts_with($relativePath, '.composer/');
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
            'composer_home' => $result['composer_home'],
            'composer_cache_dir' => $result['composer_cache_dir'],
            'composer_plugins' => $result['composer_plugins'],
            'composer_scripts' => $result['composer_scripts'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    } catch (Throwable $e) {
        fwrite(STDERR, "Release package build failed: {$e->getMessage()}\n");
        exit(1);
    }
}
