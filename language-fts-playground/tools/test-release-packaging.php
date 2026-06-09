#!/usr/bin/env php
<?php
declare(strict_types=1);

const LANGUAGE_FTS_RELEASE_SLUG = 'language-fts-playground';

$options = getopt('', ['help']);

if (isset($options['help'])) {
    echo "Usage: php tools/test-release-packaging.php\n";
    echo "\n";
    echo "Runs focused release packaging regressions for deterministic zip bytes\n";
    echo "and ignored local artifact exclusion.\n";
    exit(0);
}

$plugin_root = dirname(__DIR__);
$repo_root = dirname($plugin_root);
$workspace = create_temporary_directory('language-fts-release-regression-');
$artifact_root = $plugin_root . '/static-site-output';
$artifact_dir = $artifact_root . '/release-packaging-probe-' . str_replace('.', '', uniqid('', true));
$artifact_file = $artifact_dir . '/local-artifact.txt';

try {
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('The PHP zip extension is required for release packaging regressions.');
    }

    ensure_directory($artifact_dir);

    if (false === file_put_contents($artifact_file, "ignored local release artifact\n")) {
        throw new RuntimeException('Unable to write ignored local artifact probe.');
    }

    $first_output = $workspace . '/first';
    $second_output = $workspace . '/second';

    run_command([PHP_BINARY, 'tools/build-release.php', '--output=' . $first_output, '--skip-checks'], $plugin_root, 'build first release regression zip');
    run_command([PHP_BINARY, 'tools/build-release.php', '--output=' . $second_output, '--skip-checks'], $plugin_root, 'build second release regression zip');

    $first_zip = single_release_zip($first_output);
    $second_zip = single_release_zip($second_output);
    $first_hash = hash_file('sha256', $first_zip);
    $second_hash = hash_file('sha256', $second_zip);

    if (!is_string($first_hash) || !is_string($second_hash)) {
        throw new RuntimeException('Unable to hash release regression zips.');
    }

    if ($first_hash !== $second_hash) {
        throw new RuntimeException(
            "Same-source release zips are not deterministic.\n" .
            'First:  ' . $first_hash . "\n" .
            'Second: ' . $second_hash
        );
    }

    assert_zip_path_absent($first_zip, LANGUAGE_FTS_RELEASE_SLUG . '/static-site-output/');
    assert_zip_path_present($first_zip, LANGUAGE_FTS_RELEASE_SLUG . '/readme.txt');
    assert_zip_path_present($first_zip, LANGUAGE_FTS_RELEASE_SLUG . '/LICENSE');
    assert_zip_path_absent($first_zip, LANGUAGE_FTS_RELEASE_SLUG . '/tools/');
    assert_zip_path_absent($first_zip, LANGUAGE_FTS_RELEASE_SLUG . '/tests/');

    $svn_stage = $workspace . '/wordpress-org-svn-stage';
    run_command(
        [PHP_BINARY, 'tools/build-wordpress-org-svn-stage.php', '--output=' . $svn_stage, '--allow-dirty', '--skip-checks'],
        $plugin_root,
        'build WordPress.org SVN staging regression tree'
    );
    run_command(
        [PHP_BINARY, 'tools/verify-wordpress-org-svn-stage.php', '--stage=' . $svn_stage],
        $plugin_root,
        'verify WordPress.org SVN staging regression tree'
    );

    assert_directory_present($svn_stage . '/trunk');
    assert_directory_present($svn_stage . '/tags/0.3.0');
    assert_directory_present($svn_stage . '/assets');
    assert_file_present($svn_stage . '/trunk/language-fts-playground.php');
    assert_file_present($svn_stage . '/trunk/readme.txt');
    assert_file_present($svn_stage . '/tags/0.3.0/language-fts-playground.php');
    assert_file_present($svn_stage . '/tags/0.3.0/readme.txt');
    assert_path_absent($svn_stage . '/trunk/' . LANGUAGE_FTS_RELEASE_SLUG);
    assert_path_absent($svn_stage . '/trunk/tools');
    assert_path_absent($svn_stage . '/trunk/tests');
    assert_path_absent($svn_stage . '/trunk/.distignore');

    $bad_stage = $workspace . '/bad-wordpress-org-svn-stage';
    ensure_directory($bad_stage . '/trunk/' . LANGUAGE_FTS_RELEASE_SLUG);
    ensure_directory($bad_stage . '/tags/0.3.0');
    ensure_directory($bad_stage . '/assets');
    run_command_expect_failure(
        [PHP_BINARY, 'tools/verify-wordpress-org-svn-stage.php', '--stage=' . $bad_stage],
        $plugin_root,
        'reject nested plugin root in WordPress.org SVN stage',
        'trunk/language-fts-playground'
    );

    echo "Release packaging regressions passed.\n";
    echo 'Deterministic SHA-256: ' . $first_hash . "\n";
    echo 'Ignored artifact excluded: ' . LANGUAGE_FTS_RELEASE_SLUG . "/static-site-output/\n";
    echo "WordPress.org SVN staging regression: passed\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Release packaging regression failed: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    remove_tree($workspace);
    remove_tree($artifact_dir);

    if (is_dir($artifact_root)) {
        @rmdir($artifact_root);
    }
}

/**
 * Finds the single release zip in a build output directory.
 *
 * @param string $output_dir Build output directory.
 * @return string Release zip path.
 */
function single_release_zip(string $output_dir): string
{
    $matches = glob(rtrim($output_dir, '/') . '/' . LANGUAGE_FTS_RELEASE_SLUG . '-*.zip');

    if (!is_array($matches) || 1 !== count($matches)) {
        throw new RuntimeException('Expected exactly one release zip in: ' . $output_dir);
    }

    return $matches[0];
}

/**
 * Requires that a zip has an exact entry.
 *
 * @param string $zip_path Zip path.
 * @param string $entry    Zip entry.
 */
function assert_zip_path_present(string $zip_path, string $entry): void
{
    $zip = new ZipArchive();

    if (true !== $zip->open($zip_path)) {
        throw new RuntimeException('Unable to open release regression zip: ' . $zip_path);
    }

    try {
        if (false === $zip->locateName($entry)) {
            throw new RuntimeException('Expected release path was not packaged: ' . $entry);
        }
    } finally {
        $zip->close();
    }
}

/**
 * Requires that a zip has no entry at or under a path prefix.
 *
 * @param string $zip_path Zip path.
 * @param string $prefix   Zip entry prefix.
 */
function assert_zip_path_absent(string $zip_path, string $prefix): void
{
    $zip = new ZipArchive();

    if (true !== $zip->open($zip_path)) {
        throw new RuntimeException('Unable to open release regression zip: ' . $zip_path);
    }

    try {
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $path = $zip->getNameIndex($index);

            if (!is_string($path)) {
                throw new RuntimeException('Unable to read release regression zip entry at index: ' . $index);
            }

            if ($path === rtrim($prefix, '/') || 0 === strpos($path, $prefix)) {
                throw new RuntimeException('Ignored local artifact path was packaged: ' . $path);
            }
        }
    } finally {
        $zip->close();
    }
}

/**
 * Requires that a filesystem path exists as a file.
 *
 * @param string $path File path.
 */
function assert_file_present(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException('Expected file was not staged: ' . $path);
    }
}

/**
 * Requires that a filesystem path exists as a directory.
 *
 * @param string $path Directory path.
 */
function assert_directory_present(string $path): void
{
    if (!is_dir($path)) {
        throw new RuntimeException('Expected directory was not staged: ' . $path);
    }
}

/**
 * Requires that a filesystem path does not exist.
 *
 * @param string $path File or directory path.
 */
function assert_path_absent(string $path): void
{
    if (file_exists($path)) {
        throw new RuntimeException('Forbidden path was staged: ' . $path);
    }
}

/**
 * Runs a command and fails with bounded diagnostics.
 *
 * @param string[] $command Command and arguments.
 * @param string   $cwd     Working directory.
 * @param string   $action  Human-readable action.
 */
function run_command(array $command, string $cwd, string $action): void
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

    if ('' !== trim($stderr)) {
        throw new RuntimeException('Command to ' . $action . " wrote unexpected stderr:\n" . truncate_diagnostic($stderr));
    }
}

/**
 * Runs a command that must fail with an expected diagnostic.
 *
 * @param string[] $command Command and arguments.
 * @param string   $cwd     Working directory.
 * @param string   $action  Human-readable action.
 * @param string   $needle  Diagnostic substring expected in stdout/stderr.
 */
function run_command_expect_failure(array $command, string $cwd, string $action, string $needle): void
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
    $diagnostic = (false === $stderr ? '' : $stderr) . "\n" . (false === $stdout ? '' : $stdout);

    if (0 === $exit_code) {
        throw new RuntimeException('Command unexpectedly passed while trying to ' . $action . '.');
    }

    if (false === strpos($diagnostic, $needle)) {
        throw new RuntimeException(
            'Command failed without expected diagnostic while trying to ' . $action . ".\n" .
            'Expected: ' . $needle . "\n" .
            truncate_diagnostic($diagnostic)
        );
    }
}

/**
 * Creates a temporary directory.
 *
 * @param string $prefix Temporary path prefix.
 * @return string Absolute temporary directory path.
 */
function create_temporary_directory(string $prefix): string
{
    $base = tempnam(sys_get_temp_dir(), $prefix);

    if (false === $base) {
        throw new RuntimeException('Unable to create release regression staging path.');
    }

    if (!unlink($base) || !mkdir($base, 0777, true)) {
        throw new RuntimeException('Unable to create release regression staging directory.');
    }

    return str_replace('\\', '/', $base);
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
 * Removes a temporary directory tree.
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
