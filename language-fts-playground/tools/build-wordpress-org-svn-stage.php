#!/usr/bin/env php
<?php
declare(strict_types=1);

const LANGUAGE_FTS_SVN_STAGE_SLUG = 'language-fts-playground';
const LANGUAGE_FTS_SVN_STAGE_MARKER = '.language-fts-wordpress-org-svn-stage';

$options = getopt(
    '',
    [
        'output:',
        'assets-source:',
        'allow-dirty',
        'skip-checks',
        'replace',
        'help',
    ]
);

if (isset($options['help'])) {
    echo "Usage: php tools/build-wordpress-org-svn-stage.php [--output=dist/wordpress-org-svn-stage] [--assets-source=path] [--allow-dirty] [--skip-checks] [--replace]\n";
    echo "\n";
    echo "Builds a dry-run WordPress.org Plugin Directory SVN layout with\n";
    echo "top-level trunk/, tags/<version>/, and assets/ directories. This tool\n";
    echo "does not run svn, create a zip, or replace the direct-ZIP workflow.\n";
    exit(0);
}

$plugin_root = dirname(__DIR__);
$repo_root = dirname($plugin_root);
$output = isset($options['output']) ? (string) $options['output'] : 'dist/wordpress-org-svn-stage';
$assets_source = isset($options['assets-source']) ? (string) $options['assets-source'] : null;

try {
    $metadata = inspect_svn_stage_metadata($plugin_root);
    assert_svn_stage_metadata_is_consistent($metadata);
    assert_required_source_paths($plugin_root);

    if (!isset($options['allow-dirty'])) {
        assert_clean_plugin_status($repo_root);
    }

    if (!isset($options['skip-checks'])) {
        run_preflight_checks($plugin_root, $repo_root);
    }

    $stage_root = absolute_path($output, $plugin_root);
    assert_safe_output_path($stage_root, $plugin_root, $repo_root);
    prepare_stage_root($stage_root, isset($options['replace']));

    ensure_directory($stage_root . '/assets');
    ensure_directory($stage_root . '/tags');
    ensure_directory($stage_root . '/trunk');

    copy_tracked_stage_files($repo_root, $stage_root . '/trunk', read_distignore_patterns($plugin_root));
    copy_tree($stage_root . '/trunk', $stage_root . '/tags/' . $metadata['version']);

    if (null !== $assets_source) {
        copy_approved_assets(absolute_path($assets_source, $plugin_root), $stage_root . '/assets');
    }

    write_stage_marker($stage_root, $metadata['version']);

    $verify_result = run_command(
        [PHP_BINARY, __DIR__ . '/verify-wordpress-org-svn-stage.php', '--stage=' . $stage_root],
        $plugin_root,
        'verify WordPress.org SVN stage',
        true
    );

    if ('' !== trim($verify_result['stderr'])) {
        throw new RuntimeException("WordPress.org SVN stage verification wrote unexpected stderr:\n" . truncate_diagnostic($verify_result['stderr']));
    }

    echo 'Built WordPress.org SVN stage: ' . $stage_root . "\n";
    echo 'Version: ' . $metadata['version'] . "\n";
    echo 'Trunk files: ' . count_files($stage_root . '/trunk') . "\n";
    echo 'Tag: tags/' . $metadata['version'] . "\n";
    echo 'Assets: ' . count_files($stage_root . '/assets') . "\n";
    echo 'Preflight checks: ' . (isset($options['skip-checks']) ? 'skipped' : 'ran') . "\n";
    echo 'SVN upload: not performed' . "\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'WordPress.org SVN stage build failed: ' . $error->getMessage() . "\n");
    exit(1);
}

/**
 * Reads release metadata from the main plugin file and WordPress.org readme.
 *
 * @param string $plugin_root Absolute plugin source path.
 * @return array{version:string,constant_version:string,stable_tag:string}
 */
function inspect_svn_stage_metadata(string $plugin_root): array
{
    $main_file = read_required_file($plugin_root . '/language-fts-playground.php');
    $readme = read_required_file($plugin_root . '/readme.txt');

    return [
        'version' => match_required('/^\s*\*\s*Version:\s*([^\r\n]+)/mi', $main_file, 'plugin header Version'),
        'constant_version' => match_required("/define\\(\\s*'LANGUAGE_FTS_PLAYGROUND_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $main_file, 'LANGUAGE_FTS_PLAYGROUND_VERSION constant'),
        'stable_tag' => match_required('/^Stable tag:\s*([^\r\n]+)/mi', $readme, 'readme Stable tag'),
    ];
}

/**
 * Verifies that SVN release metadata agrees before staging.
 *
 * @param array{version:string,constant_version:string,stable_tag:string} $metadata Release metadata.
 */
function assert_svn_stage_metadata_is_consistent(array $metadata): void
{
    if ($metadata['version'] !== $metadata['constant_version']) {
        throw new RuntimeException(
            'SVN stage version mismatch: plugin header Version is ' . $metadata['version'] .
            ', but LANGUAGE_FTS_PLAYGROUND_VERSION is ' . $metadata['constant_version'] . '.'
        );
    }

    if ($metadata['version'] !== $metadata['stable_tag']) {
        throw new RuntimeException(
            'SVN stage version mismatch: plugin header Version is ' . $metadata['version'] .
            ', but readme Stable tag is ' . $metadata['stable_tag'] . '.'
        );
    }

    if ('trunk' === strtolower($metadata['stable_tag'])) {
        throw new RuntimeException('SVN stage readme Stable tag must be a release version, not trunk.');
    }
}

/**
 * Requires source paths that the WordPress.org stage must carry.
 *
 * @param string $plugin_root Absolute plugin source path.
 */
function assert_required_source_paths(string $plugin_root): void
{
    $required_files = [
        'language-fts-playground.php',
        'LICENSE',
        'README.md',
        'readme.txt',
        'docs/lexical-resources.md',
        'docs/release-packaging.md',
        'playground/blueprint.json',
    ];
    $required_directories = [
        'docs',
        'resources/languages',
        'src',
    ];

    foreach ($required_files as $relative) {
        if (!is_file($plugin_root . '/' . $relative)) {
            throw new RuntimeException('Required SVN source file is missing: ' . $relative);
        }
    }

    foreach ($required_directories as $relative) {
        if (!is_dir($plugin_root . '/' . $relative)) {
            throw new RuntimeException('Required SVN source directory is missing: ' . $relative);
        }
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
        ['git', 'status', '--short', '--', LANGUAGE_FTS_SVN_STAGE_SLUG],
        $repo_root,
        'inspect plugin git status',
        true
    );

    if ('' !== trim($result['stdout'])) {
        throw new RuntimeException(
            "WordPress.org SVN staging requires a clean language-fts-playground source tree. " .
            "Commit changes or pass --allow-dirty.\n" . trim($result['stdout'])
        );
    }
}

/**
 * Runs source checks that should pass before staging a WordPress.org payload.
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
 * Copies tracked plugin files to a staged trunk, honoring .distignore.
 *
 * @param string   $repo_root Absolute repository root.
 * @param string   $target    Absolute target path.
 * @param string[] $patterns  Exclusion patterns.
 */
function copy_tracked_stage_files(string $repo_root, string $target, array $patterns): void
{
    foreach (tracked_stage_paths($repo_root) as $repo_relative) {
        $relative = plugin_relative_path($repo_relative);

        if (is_excluded($relative, $patterns)) {
            continue;
        }

        $source_path = rtrim($repo_root, '/') . '/' . $repo_relative;

        if (is_link($source_path)) {
            throw new RuntimeException('Refusing to stage symlink: ' . $relative);
        }

        if (!is_file($source_path)) {
            throw new RuntimeException('Tracked SVN stage file is missing from the working tree: ' . $relative);
        }

        $target_path = $target . '/' . $relative;
        ensure_directory(dirname($target_path));

        if (!copy($source_path, $target_path)) {
            throw new RuntimeException('Unable to copy SVN stage file: ' . $relative);
        }
    }
}

/**
 * Lists tracked plugin paths from git.
 *
 * @param string $repo_root Absolute repository root.
 * @return string[] Repository-relative file paths.
 */
function tracked_stage_paths(string $repo_root): array
{
    $result = run_command(
        ['git', 'ls-files', '-z', '--', LANGUAGE_FTS_SVN_STAGE_SLUG],
        $repo_root,
        'list tracked plugin SVN stage files',
        true
    );

    $paths = array_values(array_filter(
        explode("\0", $result['stdout']),
        static fn(string $path): bool => '' !== $path
    ));
    sort($paths, SORT_STRING);

    if ([] === $paths) {
        throw new RuntimeException('No tracked plugin files found for WordPress.org SVN staging.');
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
    $prefix = LANGUAGE_FTS_SVN_STAGE_SLUG . '/';

    if (0 !== strpos($repo_relative, $prefix)) {
        throw new RuntimeException('Tracked SVN stage path is outside the plugin root: ' . $repo_relative);
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
 * Copies approved WordPress.org asset files into top-level assets/.
 *
 * @param string $source Absolute asset source directory.
 * @param string $target Absolute stage assets directory.
 */
function copy_approved_assets(string $source, string $target): void
{
    $real_source = realpath($source);

    if (false === $real_source || !is_dir($real_source)) {
        throw new RuntimeException('WordPress.org assets source directory does not exist: ' . $source);
    }

    $items = scandir($real_source);

    if (false === $items) {
        throw new RuntimeException('Unable to read WordPress.org assets source directory: ' . $real_source);
    }

    sort($items);

    foreach ($items as $item) {
        if ('.' === $item || '..' === $item) {
            continue;
        }

        if (!is_allowed_asset_filename($item)) {
            throw new RuntimeException('Unsupported WordPress.org asset filename: ' . $item);
        }

        $source_path = $real_source . '/' . $item;

        if (is_link($source_path)) {
            throw new RuntimeException('Refusing to stage symlinked WordPress.org asset: ' . $item);
        }

        if (!is_file($source_path)) {
            throw new RuntimeException('WordPress.org assets source contains a non-file entry: ' . $item);
        }

        if (!copy($source_path, $target . '/' . $item)) {
            throw new RuntimeException('Unable to copy WordPress.org asset: ' . $item);
        }
    }
}

/**
 * Returns whether an asset filename is in the initial approved allowlist.
 *
 * @param string $filename Asset filename.
 * @return bool Whether the filename is allowed.
 */
function is_allowed_asset_filename(string $filename): bool
{
    if ($filename !== strtolower($filename)) {
        return false;
    }

    if (in_array($filename, ['banner-772x250.png', 'banner-1544x500.png', 'icon-128x128.png', 'icon-256x256.png'], true)) {
        return true;
    }

    return 1 === preg_match('/^screenshot-[1-9][0-9]*\.(?:png|jpg)$/', $filename);
}

/**
 * Prepares the output stage root.
 *
 * @param string $stage_root Absolute stage root path.
 * @param bool   $replace    Whether replacement is allowed.
 */
function prepare_stage_root(string $stage_root, bool $replace): void
{
    if (file_exists($stage_root)) {
        if (!$replace) {
            throw new RuntimeException('WordPress.org SVN stage path already exists; pass --replace to rebuild it: ' . $stage_root);
        }

        if (!is_file($stage_root . '/' . LANGUAGE_FTS_SVN_STAGE_MARKER)) {
            throw new RuntimeException('Refusing to replace unmarked WordPress.org SVN stage path: ' . $stage_root);
        }

        remove_tree($stage_root);
    }

    ensure_directory($stage_root);
}

/**
 * Writes the generated-stage marker used for safe replacement.
 *
 * @param string $stage_root Absolute stage root path.
 * @param string $version    Release version.
 */
function write_stage_marker(string $stage_root, string $version): void
{
    $contents = "Generated by build-wordpress-org-svn-stage.php\n" .
        'Slug: ' . LANGUAGE_FTS_SVN_STAGE_SLUG . "\n" .
        'Version: ' . $version . "\n" .
        "SVN upload: not performed\n";

    if (false === file_put_contents($stage_root . '/' . LANGUAGE_FTS_SVN_STAGE_MARKER, $contents)) {
        throw new RuntimeException('Unable to write WordPress.org SVN stage marker.');
    }
}

/**
 * Requires an output path that is not an important source or home directory.
 *
 * @param string $stage_root  Absolute stage root path.
 * @param string $plugin_root Absolute plugin source path.
 * @param string $repo_root   Absolute repository root.
 */
function assert_safe_output_path(string $stage_root, string $plugin_root, string $repo_root): void
{
    $stage_root = normalize_path($stage_root);
    $plugin_root = normalize_path($plugin_root);
    $repo_root = normalize_path($repo_root);
    $home = getenv('HOME');
    $home = is_string($home) && '' !== $home ? normalize_path($home) : null;
    $dangerous = array_filter(['/', $repo_root, $plugin_root, $home]);

    foreach ($dangerous as $path) {
        if ($stage_root === $path) {
            throw new RuntimeException('Refusing to use unsafe WordPress.org SVN stage output path: ' . $stage_root);
        }
    }

    if ('' === $stage_root || false !== strpos($stage_root, "\0")) {
        throw new RuntimeException('Refusing to use invalid WordPress.org SVN stage output path.');
    }
}

/**
 * Copies a directory tree.
 *
 * @param string $source Absolute source directory.
 * @param string $target Absolute target directory.
 */
function copy_tree(string $source, string $target): void
{
    if (is_link($source)) {
        throw new RuntimeException('Refusing to copy symlinked SVN stage directory: ' . $source);
    }

    if (!is_dir($source)) {
        throw new RuntimeException('SVN stage source directory is missing: ' . $source);
    }

    ensure_directory($target);
    $items = scandir($source);

    if (false === $items) {
        throw new RuntimeException('Unable to read SVN stage source directory: ' . $source);
    }

    sort($items);

    foreach ($items as $item) {
        if ('.' === $item || '..' === $item) {
            continue;
        }

        $source_path = $source . '/' . $item;
        $target_path = $target . '/' . $item;

        if (is_link($source_path)) {
            throw new RuntimeException('Refusing to stage symlink: ' . $source_path);
        }

        if (is_dir($source_path)) {
            copy_tree($source_path, $target_path);
            continue;
        }

        if (!is_file($source_path)) {
            throw new RuntimeException('Refusing to stage non-file path: ' . $source_path);
        }

        ensure_directory(dirname($target_path));

        if (!copy($source_path, $target_path)) {
            throw new RuntimeException('Unable to copy SVN stage path: ' . $source_path);
        }
    }
}

/**
 * Counts files under a directory.
 *
 * @param string $root Absolute directory path.
 * @return int File count.
 */
function count_files(string $root): int
{
    $count = 0;
    $items = scandir($root);

    if (false === $items) {
        throw new RuntimeException('Unable to read directory for file count: ' . $root);
    }

    foreach ($items as $item) {
        if ('.' === $item || '..' === $item) {
            continue;
        }

        $path = $root . '/' . $item;

        if (is_dir($path) && !is_link($path)) {
            $count += count_files($path);
            continue;
        }

        if (is_file($path)) {
            ++$count;
        }
    }

    return $count;
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
        throw new RuntimeException('Required file is missing: ' . $path);
    }

    $contents = file_get_contents($path);

    if (false === $contents) {
        throw new RuntimeException('Unable to read required file: ' . $path);
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
        throw new RuntimeException('Unable to find ' . $label . '.');
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
 * Normalizes a path string for comparisons.
 *
 * @param string $path Path.
 * @return string Normalized path.
 */
function normalize_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path);

    if (!is_string($path)) {
        return '';
    }

    return '/' === $path ? '/' : rtrim($path, '/');
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
 * Removes a directory tree created by this script.
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
            throw new RuntimeException('Unable to remove generated file: ' . $path);
        }

        return;
    }

    $items = scandir($path);

    if (false === $items) {
        throw new RuntimeException('Unable to read generated directory: ' . $path);
    }

    foreach ($items as $item) {
        if ('.' === $item || '..' === $item) {
            continue;
        }

        remove_tree($path . '/' . $item);
    }

    if (!rmdir($path)) {
        throw new RuntimeException('Unable to remove generated directory: ' . $path);
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
 * Truncates command diagnostics to keep stage failures readable.
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
