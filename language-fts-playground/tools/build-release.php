#!/usr/bin/env php
<?php
declare(strict_types=1);

const LANGUAGE_FTS_RELEASE_SLUG = 'language-fts-playground';
const LANGUAGE_FTS_RELEASE_MTIME = 946684800;

$options = getopt(
    '',
    [
        'output:',
        'allow-dirty',
        'skip-checks',
        'help',
    ]
);

if (isset($options['help'])) {
    echo "Usage: php tools/build-release.php [--output=dist] [--allow-dirty] [--skip-checks]\n";
    echo "\n";
    echo "Builds an installable Language FTS Playground plugin zip with one\n";
    echo "language-fts-playground/ top-level directory. By default the builder\n";
    echo "requires a clean plugin source tree and runs PHP lint, tests, Blueprint\n";
    echo "JSON validation, git diff checks, and zip integrity verification.\n";
    exit(0);
}

$plugin_root = dirname(__DIR__);
$repo_root = dirname($plugin_root);
$output = isset($options['output']) ? (string) $options['output'] : 'dist';

try {
    $metadata = inspect_release_metadata($plugin_root);
    assert_release_metadata_is_consistent($metadata);

    if (!isset($options['allow-dirty'])) {
        assert_clean_plugin_status($repo_root);
    }

    if (!isset($options['skip-checks'])) {
        run_preflight_checks($plugin_root, $repo_root);
    }

    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('The PHP zip extension is required to build release packages.');
    }

    $output_dir = absolute_path($output, $plugin_root);
    ensure_directory($output_dir);

    $zip_path = $output_dir . '/' . LANGUAGE_FTS_RELEASE_SLUG . '-' . $metadata['version'] . '.zip';
    $staging = create_temporary_directory();
    $payload = $staging . '/' . LANGUAGE_FTS_RELEASE_SLUG;

    try {
        copy_tracked_release_files($repo_root, $payload, read_distignore_patterns($plugin_root));
        create_release_zip($payload, $zip_path);
    } finally {
        remove_tree($staging);
    }

    $verify_result = run_command(
        [PHP_BINARY, __DIR__ . '/verify-release-zip.php', '--zip=' . $zip_path],
        $plugin_root,
        'verify release zip integrity',
        true
    );

    if ('' !== trim($verify_result['stderr'])) {
        throw new RuntimeException("Release zip verification wrote unexpected stderr:\n" . truncate_diagnostic($verify_result['stderr']));
    }

    echo 'Built release zip: ' . $zip_path . "\n";
    echo 'Version: ' . $metadata['version'] . "\n";
    echo 'Preflight checks: ' . (isset($options['skip-checks']) ? 'skipped' : 'ran') . "\n";
    echo 'Package integrity: verified' . "\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Release build failed: ' . $error->getMessage() . "\n");
    exit(1);
}

/**
 * Reads release metadata from the main plugin file.
 *
 * @param string $plugin_root Absolute plugin source path.
 * @return array{version:string,constant_version:string}
 */
function inspect_release_metadata(string $plugin_root): array
{
    $main_file = read_required_file($plugin_root . '/language-fts-playground.php');

    return [
        'version' => match_required('/^\s*\*\s*Version:\s*([^\r\n]+)/mi', $main_file, 'plugin header Version'),
        'constant_version' => match_required("/define\\(\\s*'LANGUAGE_FTS_PLAYGROUND_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $main_file, 'LANGUAGE_FTS_PLAYGROUND_VERSION constant'),
    ];
}

/**
 * Verifies that version metadata agrees before packaging.
 *
 * @param array{version:string,constant_version:string} $metadata Release metadata.
 */
function assert_release_metadata_is_consistent(array $metadata): void
{
    if ($metadata['version'] !== $metadata['constant_version']) {
        throw new RuntimeException(
            'Release version mismatch: plugin header Version is ' . $metadata['version'] .
            ', but LANGUAGE_FTS_PLAYGROUND_VERSION is ' . $metadata['constant_version'] . '.'
        );
    }
}

/**
 * Requires a clean plugin source tree.
 *
 * @param string $repo_root Absolute repository root.
 */
function assert_clean_plugin_status(string $repo_root): void
{
    $result = run_command(
        ['git', 'status', '--short', '--', LANGUAGE_FTS_RELEASE_SLUG],
        $repo_root,
        'inspect plugin git status',
        true
    );

    if ('' !== trim($result['stdout'])) {
        throw new RuntimeException(
            "Release packaging requires a clean language-fts-playground source tree. " .
            "Commit changes or pass --allow-dirty.\n" . trim($result['stdout'])
        );
    }
}

/**
 * Runs source checks that should pass before producing a release candidate.
 *
 * @param string $plugin_root Absolute plugin source path.
 * @param string $repo_root   Absolute repository root.
 */
function run_preflight_checks(string $plugin_root, string $repo_root): void
{
    foreach (find_php_files($plugin_root) as $php_file) {
        run_command([PHP_BINARY, '-l', $php_file], $plugin_root, 'lint ' . relative_path($php_file, $plugin_root), true);
    }

    run_command([PHP_BINARY, 'tests/run.php'], $plugin_root, 'run Language FTS Playground tests');
    run_command([PHP_BINARY, 'tools/verify-wordpress-org-readme.php'], $plugin_root, 'verify WordPress.org readme metadata');

    $blueprint_check = "json_decode(file_get_contents('playground/blueprint.json'));" .
        "if (json_last_error() !== JSON_ERROR_NONE) {" .
        "fwrite(STDERR, json_last_error_msg() . PHP_EOL); exit(1); }";
    run_command([PHP_BINARY, '-r', $blueprint_check], $plugin_root, 'validate Playground Blueprint JSON');

    run_command(['git', 'diff', '--check'], $repo_root, 'check git diff whitespace');
}

/**
 * Finds PHP files under the plugin source tree.
 *
 * @param string $root Absolute plugin source path.
 * @return string[] Sorted absolute paths.
 */
function find_php_files(string $root): array
{
    $files = [];
    $items = scandir($root);

    if (false === $items) {
        throw new RuntimeException('Unable to read directory: ' . $root);
    }

    sort($items);

    foreach ($items as $item) {
        if ('.' === $item || '..' === $item || 'dist' === $item) {
            continue;
        }

        $path = $root . '/' . $item;

        if (is_link($path)) {
            continue;
        }

        if (is_dir($path)) {
            $files = array_merge($files, find_php_files($path));
            continue;
        }

        if (is_file($path) && '.php' === substr($path, -4)) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

/**
 * Reads release exclusion patterns.
 *
 * @param string $plugin_root Absolute plugin source path.
 * @return string[] Patterns.
 */
function read_distignore_patterns(string $plugin_root): array
{
    $contents = read_required_file($plugin_root . '/.distignore');
    $patterns = [];

    foreach (preg_split("/\r\n|\n|\r/", $contents) as $line) {
        $line = trim((string) $line);

        if ('' === $line || '#' === substr($line, 0, 1)) {
            continue;
        }

        $patterns[] = trim(str_replace('\\', '/', $line), '/');
    }

    return $patterns;
}

/**
 * Copies tracked plugin files to staging, honoring .distignore.
 *
 * @param string   $repo_root Absolute repository root.
 * @param string   $target    Absolute target path.
 * @param string[] $patterns Exclusion patterns.
 */
function copy_tracked_release_files(string $repo_root, string $target, array $patterns): void
{
    ensure_directory($target);

    foreach (tracked_release_paths($repo_root) as $repo_relative) {
        $relative = plugin_relative_path($repo_relative);

        if (is_excluded($relative, $patterns)) {
            continue;
        }

        $source_path = rtrim($repo_root, '/') . '/' . $repo_relative;

        if (is_link($source_path)) {
            throw new RuntimeException('Refusing to package symlink: ' . $relative);
        }

        if (!is_file($source_path)) {
            throw new RuntimeException('Tracked release file is missing from the working tree: ' . $relative);
        }

        $target_path = $target . '/' . $relative;
        ensure_directory(dirname($target_path));

        if (!copy($source_path, $target_path)) {
            throw new RuntimeException('Unable to copy release file: ' . $relative);
        }
    }
}

/**
 * Lists tracked plugin paths from git.
 *
 * @param string $repo_root Absolute repository root.
 * @return string[] Repository-relative file paths.
 */
function tracked_release_paths(string $repo_root): array
{
    $result = run_command(
        ['git', 'ls-files', '-z', '--', LANGUAGE_FTS_RELEASE_SLUG],
        $repo_root,
        'list tracked plugin release files',
        true
    );

    $paths = array_values(array_filter(
        explode("\0", $result['stdout']),
        static fn(string $path): bool => '' !== $path
    ));
    sort($paths, SORT_STRING);

    if ([] === $paths) {
        throw new RuntimeException('No tracked plugin files found for release packaging.');
    }

    return $paths;
}

/**
 * Converts a repository-relative plugin path to a plugin-relative path.
 *
 * @param string $repo_relative Repository-relative path.
 * @return string Plugin-relative path.
 */
function plugin_relative_path(string $repo_relative): string
{
    $repo_relative = trim(str_replace('\\', '/', $repo_relative), '/');
    $prefix = LANGUAGE_FTS_RELEASE_SLUG . '/';

    if (0 !== strpos($repo_relative, $prefix)) {
        throw new RuntimeException('Tracked release path is outside the plugin root: ' . $repo_relative);
    }

    return substr($repo_relative, strlen($prefix));
}

/**
 * Checks whether a plugin-relative path is excluded.
 *
 * @param string   $relative Relative path.
 * @param string[] $patterns Exclusion patterns.
 * @return bool Whether the path is excluded.
 */
function is_excluded(string $relative, array $patterns): bool
{
    $relative = trim(str_replace('\\', '/', $relative), '/');

    foreach ($patterns as $pattern) {
        if ('' === $pattern) {
            continue;
        }

        if ($relative === $pattern || 0 === strpos($relative, $pattern . '/')) {
            return true;
        }

        if (fnmatch($pattern, $relative) || fnmatch($pattern, basename($relative))) {
            return true;
        }
    }

    return false;
}

/**
 * Creates a zip archive with deterministic entry order and timestamps.
 *
 * @param string $payload  Staged plugin directory.
 * @param string $zip_path Absolute output zip path.
 */
function create_release_zip(string $payload, string $zip_path): void
{
    $zip = new ZipArchive();

    if (is_file($zip_path) && !unlink($zip_path)) {
        throw new RuntimeException('Unable to replace existing release zip: ' . $zip_path);
    }

    if (true !== $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
        throw new RuntimeException('Unable to create release zip: ' . $zip_path);
    }

    add_directory_to_zip($zip, dirname($payload), basename($payload));

    if (!$zip->close()) {
        throw new RuntimeException('Unable to finalize release zip: ' . $zip_path);
    }
}

/**
 * Adds a directory recursively to a zip archive.
 *
 * @param ZipArchive $zip       Zip archive.
 * @param string     $base_path Base path.
 * @param string     $relative  Relative directory path.
 */
function add_directory_to_zip(ZipArchive $zip, string $base_path, string $relative): void
{
    $absolute = $base_path . '/' . $relative;
    $directory_entry = zip_directory_entry_name($relative);

    if (!$zip->addEmptyDir($directory_entry)) {
        throw new RuntimeException('Unable to add release directory to zip: ' . $directory_entry);
    }

    set_zip_entry_mtime($zip, $directory_entry);

    $items = scandir($absolute);

    if (false === $items) {
        throw new RuntimeException('Unable to read staged directory: ' . $absolute);
    }

    sort($items);

    foreach ($items as $item) {
        if ('.' === $item || '..' === $item) {
            continue;
        }

        $item_relative = $relative . '/' . $item;
        $item_absolute = $base_path . '/' . $item_relative;

        if (is_dir($item_absolute)) {
            add_directory_to_zip($zip, $base_path, $item_relative);
            continue;
        }

        if (!$zip->addFile($item_absolute, $item_relative)) {
            throw new RuntimeException('Unable to add release file to zip: ' . $item_relative);
        }

        set_zip_entry_mtime($zip, $item_relative);
    }
}

/**
 * Returns the exact directory entry name stored by ZipArchive.
 *
 * @param string $relative Relative directory path.
 * @return string Zip directory entry name.
 */
function zip_directory_entry_name(string $relative): string
{
    return rtrim(str_replace('\\', '/', $relative), '/') . '/';
}

/**
 * Sets a stable timestamp for a zip entry.
 *
 * @param ZipArchive $zip  Zip archive.
 * @param string     $name Entry name.
 */
function set_zip_entry_mtime(ZipArchive $zip, string $name): void
{
    if (!method_exists($zip, 'setMtimeName')) {
        throw new RuntimeException('The PHP zip extension does not support deterministic entry mtimes.');
    }

    if (!$zip->setMtimeName($name, LANGUAGE_FTS_RELEASE_MTIME)) {
        throw new RuntimeException('Unable to normalize release zip entry mtime: ' . $name);
    }
}

/**
 * Runs a command with bounded failure diagnostics.
 *
 * @param string[] $command Command and arguments.
 * @param string   $cwd     Working directory.
 * @param string   $action  Human-readable action.
 * @param bool     $capture Whether stdout/stderr are expected diagnostics.
 * @return array{stdout:string,stderr:string}
 */
function run_command(array $command, string $cwd, string $action, bool $capture = false): array
{
    $descriptor_spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $command_string = implode(' ', array_map('escapeshellarg', $command));
    $process = proc_open($command_string, $descriptor_spec, $pipes, $cwd);

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start command to ' . $action . ': ' . $command_string);
    }

    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit_code = proc_close($process);
    $stdout = false === $stdout ? '' : $stdout;
    $stderr = false === $stderr ? '' : $stderr;

    if (0 !== $exit_code) {
        $diagnostic = trim($stderr);

        if ('' === $diagnostic) {
            $diagnostic = trim($stdout);
        }

        throw new RuntimeException('Unable to ' . $action . ' (exit ' . $exit_code . "):\n" . truncate_diagnostic($diagnostic));
    }

    if (!$capture && '' !== trim($stderr)) {
        throw new RuntimeException('Command to ' . $action . " wrote unexpected stderr:\n" . truncate_diagnostic($stderr));
    }

    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

/**
 * Reads a required file.
 *
 * @param string $path Absolute path.
 * @return string File contents.
 */
function read_required_file(string $path): string
{
    if (!is_file($path)) {
        throw new RuntimeException('Required release file is missing: ' . $path);
    }

    $contents = file_get_contents($path);

    if (false === $contents) {
        throw new RuntimeException('Unable to read required release file: ' . $path);
    }

    return $contents;
}

/**
 * Matches a required value.
 *
 * @param string $pattern Pattern.
 * @param string $subject Subject.
 * @param string $label   Diagnostic label.
 * @return string Matched value.
 */
function match_required(string $pattern, string $subject, string $label): string
{
    if (!preg_match($pattern, $subject, $matches)) {
        throw new RuntimeException('Unable to find ' . $label . ' for release packaging.');
    }

    return trim($matches[1]);
}

/**
 * Converts a path to an absolute path.
 *
 * @param string $path Path from CLI option.
 * @param string $base Base path for relative paths.
 * @return string Absolute path.
 */
function absolute_path(string $path, string $base): string
{
    $path = str_replace('\\', '/', $path);

    if ('' !== $path && '/' === substr($path, 0, 1)) {
        return rtrim($path, '/');
    }

    return rtrim($base, '/') . '/' . trim($path, '/');
}

/**
 * Creates a directory if it does not exist.
 *
 * @param string $path Directory path.
 */
function ensure_directory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create directory: ' . $path);
    }
}

/**
 * Creates a temporary staging directory.
 *
 * @return string Absolute path.
 */
function create_temporary_directory(): string
{
    $base = tempnam(sys_get_temp_dir(), 'language-fts-release-');

    if (false === $base) {
        throw new RuntimeException('Unable to create release staging path.');
    }

    if (!unlink($base) || !mkdir($base, 0777, true)) {
        throw new RuntimeException('Unable to create release staging directory.');
    }

    return str_replace('\\', '/', $base);
}

/**
 * Removes a directory tree created by this release script.
 *
 * @param string $path Path to remove.
 */
function remove_tree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('Unable to remove temporary file: ' . $path);
        }

        return;
    }

    $items = scandir($path);

    if (false === $items) {
        throw new RuntimeException('Unable to read temporary directory: ' . $path);
    }

    foreach ($items as $item) {
        if ('.' === $item || '..' === $item) {
            continue;
        }

        remove_tree($path . '/' . $item);
    }

    if (!rmdir($path)) {
        throw new RuntimeException('Unable to remove temporary directory: ' . $path);
    }
}

/**
 * Returns a plugin-relative path for diagnostics.
 *
 * @param string $path Absolute path.
 * @param string $root Absolute plugin root.
 * @return string Relative path.
 */
function relative_path(string $path, string $root): string
{
    return ltrim(str_replace(str_replace('\\', '/', $root), '', str_replace('\\', '/', $path)), '/');
}

/**
 * Truncates command diagnostics to keep release failures readable.
 *
 * @param string $diagnostic Raw diagnostic text.
 * @return string Bounded diagnostic text.
 */
function truncate_diagnostic(string $diagnostic): string
{
    $diagnostic = trim($diagnostic);

    if (2000 < strlen($diagnostic)) {
        return substr($diagnostic, 0, 2000) . "\n... truncated ...";
    }

    return $diagnostic;
}
