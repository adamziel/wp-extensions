#!/usr/bin/env php
<?php
declare(strict_types=1);

$options = getopt('', ['help']);

if (isset($options['help'])) {
    echo "Usage: php tools/test-wordpress-org-svn-stage.php\n";
    echo "\n";
    echo "Runs focused regressions for the WordPress.org SVN dry-run staging\n";
    echo "builder and verifier.\n";
    exit(0);
}

$plugin_root = dirname(__DIR__);
$workspace = create_temporary_directory('language-fts-svn-stage-regression-');

try {
    $stage = $workspace . '/stage';
    $manifest = $workspace . '/stage-manifest.json';

    run_command(
        [
            PHP_BINARY,
            'tools/build-wordpress-org-svn-stage.php',
            '--output=' . $stage,
            '--allow-dirty',
            '--skip-checks',
        ],
        $plugin_root,
        'build WordPress.org SVN regression stage'
    );

    run_command(
        [
            PHP_BINARY,
            'tools/verify-wordpress-org-svn-stage.php',
            '--stage=' . $stage,
            '--manifest-json=' . $manifest,
        ],
        $plugin_root,
        'verify WordPress.org SVN regression stage'
    );

    assert_path_present($stage . '/trunk/language-fts-playground.php');
    assert_path_present($stage . '/trunk/readme.txt');
    assert_path_present($stage . '/trunk/LICENSE');
    assert_path_present($stage . '/tags/0.3.0/readme.txt');
    assert_path_present($manifest);
    assert_path_absent($stage . '/trunk/language-fts-playground');
    assert_path_absent($stage . '/trunk/tools');
    assert_path_absent($stage . '/trunk/tests');
    assert_path_absent($stage . '/trunk/.cao');

    $nested_stage = copy_stage_fixture($stage, $workspace . '/nested-stage');
    ensure_directory($nested_stage . '/trunk/language-fts-playground');
    assert_command_fails(
        [PHP_BINARY, 'tools/verify-wordpress-org-svn-stage.php', '--stage=' . $nested_stage],
        $plugin_root,
        'reject nested plugin root staging',
        'nested plugin root'
    );

    $payload_assets_stage = copy_stage_fixture($stage, $workspace . '/payload-assets-stage');
    ensure_directory($payload_assets_stage . '/trunk/assets');
    assert_command_fails(
        [PHP_BINARY, 'tools/verify-wordpress-org-svn-stage.php', '--stage=' . $payload_assets_stage],
        $plugin_root,
        'reject payload assets directory',
        'must not contain assets'
    );

    $forbidden_stage = copy_stage_fixture($stage, $workspace . '/forbidden-stage');
    ensure_directory($forbidden_stage . '/trunk/tools');
    write_file($forbidden_stage . '/trunk/tools/local.php', "<?php\n");
    assert_command_fails(
        [PHP_BINARY, 'tools/verify-wordpress-org-svn-stage.php', '--stage=' . $forbidden_stage],
        $plugin_root,
        'reject forbidden tools directory',
        'forbidden path'
    );

    $asset_stage = copy_stage_fixture($stage, $workspace . '/asset-stage');
    write_file($asset_stage . '/assets/Screenshot-1.png', 'not an approved lowercase asset name');
    assert_command_fails(
        [PHP_BINARY, 'tools/verify-wordpress-org-svn-stage.php', '--stage=' . $asset_stage],
        $plugin_root,
        'reject unsupported asset filename',
        'Unsupported WordPress.org asset filename'
    );

    $drift_stage = copy_stage_fixture($stage, $workspace . '/drift-stage');
    write_file($drift_stage . '/tags/0.3.0/README.md', "tag drift\n");
    assert_command_fails(
        [PHP_BINARY, 'tools/verify-wordpress-org-svn-stage.php', '--stage=' . $drift_stage],
        $plugin_root,
        'reject trunk and tag payload drift',
        'file contents differ'
    );

    echo "WordPress.org SVN staging regressions passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'WordPress.org SVN staging regression failed: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    remove_tree($workspace);
}

/**
 * Copies a stage fixture for a destructive verifier test.
 *
 * @param string $source Source stage directory.
 * @param string $target Target stage directory.
 * @return string Target path.
 */
function copy_stage_fixture(string $source, string $target): string
{
    copy_tree($source, $target);

    return $target;
}

/**
 * Requires that a file or directory exists.
 *
 * @param string $path Path.
 */
function assert_path_present(string $path): void
{
    if (!file_exists($path)) {
        throw new RuntimeException('Expected staged path is missing: ' . $path);
    }
}

/**
 * Requires that a file or directory does not exist.
 *
 * @param string $path Path.
 */
function assert_path_absent(string $path): void
{
    if (file_exists($path)) {
        throw new RuntimeException('Forbidden staged path exists: ' . $path);
    }
}

/**
 * Requires that a command fails with an expected diagnostic.
 *
 * @param string[] $command Command and arguments.
 * @param string   $cwd     Working directory.
 * @param string   $action  Human-readable action.
 * @param string   $needle  Expected diagnostic substring.
 */
function assert_command_fails(array $command, string $cwd, string $action, string $needle): void
{
    $result = run_command_with_status($command, $cwd);

    if (0 === $result['exit_code']) {
        throw new RuntimeException('Expected command to fail while trying to ' . $action . '.');
    }

    $diagnostic = $result['stderr'] . "\n" . $result['stdout'];

    if (false === strpos($diagnostic, $needle)) {
        throw new RuntimeException(
            'Command failed without expected diagnostic while trying to ' . $action . ".\n" .
            'Expected: ' . $needle . "\n" .
            truncate_diagnostic($diagnostic)
        );
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
    $result = run_command_with_status($command, $cwd);

    if (0 !== $result['exit_code']) {
        $diagnostic = trim($result['stderr']);

        if ('' === $diagnostic) {
            $diagnostic = trim($result['stdout']);
        }

        throw new RuntimeException('Unable to ' . $action . ' (exit ' . $result['exit_code'] . "):\n" . truncate_diagnostic($diagnostic));
    }

    if ('' !== trim($result['stderr'])) {
        throw new RuntimeException('Command to ' . $action . " wrote unexpected stderr:\n" . truncate_diagnostic($result['stderr']));
    }
}

/**
 * Runs a command and returns status and output.
 *
 * @param string[] $command Command and arguments.
 * @param string   $cwd     Working directory.
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
function run_command_with_status(array $command, string $cwd): array
{
    $descriptor_spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $command_string = implode(' ', array_map('escapeshellarg', $command));
    $process = proc_open($command_string, $descriptor_spec, $pipes, $cwd);

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start command: ' . $command_string);
    }

    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit_code = proc_close($process);

    return [
        'exit_code' => $exit_code,
        'stdout' => false === $stdout ? '' : $stdout,
        'stderr' => false === $stderr ? '' : $stderr,
    ];
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
        throw new RuntimeException('Unable to create SVN stage regression path.');
    }

    if (!unlink($base) || !mkdir($base, 0777, true)) {
        throw new RuntimeException('Unable to create SVN stage regression directory.');
    }

    return str_replace('\\', '/', $base);
}

/**
 * Copies a directory tree.
 *
 * @param string $source Source directory.
 * @param string $target Target directory.
 */
function copy_tree(string $source, string $target): void
{
    ensure_directory($target);
    $items = scandir($source);

    if (false === $items) {
        throw new RuntimeException('Unable to read fixture directory: ' . $source);
    }

    foreach ($items as $item) {
        if ('.' === $item || '..' === $item) {
            continue;
        }

        $source_path = $source . '/' . $item;
        $target_path = $target . '/' . $item;

        if (is_dir($source_path) && !is_link($source_path)) {
            copy_tree($source_path, $target_path);
            continue;
        }

        ensure_directory(dirname($target_path));

        if (!copy($source_path, $target_path)) {
            throw new RuntimeException('Unable to copy fixture file: ' . $source_path);
        }
    }
}

/**
 * Writes a file, creating parent directories first.
 *
 * @param string $path     File path.
 * @param string $contents File contents.
 */
function write_file(string $path, string $contents): void
{
    ensure_directory(dirname($path));

    if (false === file_put_contents($path, $contents)) {
        throw new RuntimeException('Unable to write fixture file: ' . $path);
    }
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
