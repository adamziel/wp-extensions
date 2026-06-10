#!/usr/bin/env php
<?php
declare(strict_types=1);

$options = getopt('', ['help']);

if (isset($options['help'])) {
    echo "Usage: php tools/test-wordpress-org-asset-manifest.php\n";
    echo "\n";
    echo "Runs focused regressions for the local WordPress.org asset\n";
    echo "source/license manifest verifier using generated temporary fixtures.\n";
    exit(0);
}

$plugin_root = dirname(__DIR__);
$workspace = create_temporary_directory('language-fts-asset-manifest-regression-');

try {
    $valid_root = $workspace . '/valid-assets';
    $valid_manifest = create_valid_manifest_fixture($valid_root);

    run_command(
        [PHP_BINARY, 'tools/verify-wordpress-org-asset-manifest.php', '--manifest=' . $valid_manifest],
        $plugin_root,
        'verify complete generated asset manifest fixture'
    );

    $missing_asset_root = copy_manifest_fixture($valid_root, $workspace . '/missing-required-asset');
    $missing_manifest = read_manifest($missing_asset_root);
    $missing_manifest['assets'] = array_values(array_filter(
        $missing_manifest['assets'],
        static function (array $asset): bool {
            return 'assets/icon-256x256.png' !== $asset['svn_asset_path'];
        }
    ));
    write_manifest($missing_asset_root, $missing_manifest);
    assert_command_fails(
        [PHP_BINARY, 'tools/verify-wordpress-org-asset-manifest.php', '--manifest=' . $missing_asset_root . '/manifest.json'],
        $plugin_root,
        'reject manifest missing required icon asset',
        'Required launch asset is missing'
    );

    $wrong_dimensions_root = copy_manifest_fixture($valid_root, $workspace . '/wrong-dimensions');
    write_png_fixture($wrong_dimensions_root . '/final/banner-772x250.png', 771, 250);
    $wrong_dimensions_manifest = read_manifest($wrong_dimensions_root);
    $wrong_dimensions_manifest['assets'][0]['sha256'] = hash_file('sha256', $wrong_dimensions_root . '/final/banner-772x250.png');
    $wrong_dimensions_manifest['assets'][0]['dimensions'] = ['width' => 771, 'height' => 250];
    write_manifest($wrong_dimensions_root, $wrong_dimensions_manifest);
    assert_command_fails(
        [PHP_BINARY, 'tools/verify-wordpress-org-asset-manifest.php', '--manifest=' . $wrong_dimensions_root . '/manifest.json'],
        $plugin_root,
        'reject manifest with wrong fixed banner dimensions',
        'wrong dimensions'
    );

    $bad_checksum_root = copy_manifest_fixture($valid_root, $workspace . '/bad-checksum');
    $bad_checksum_manifest = read_manifest($bad_checksum_root);
    $bad_checksum_manifest['assets'][0]['sha256'] = str_repeat('0', 64);
    write_manifest($bad_checksum_root, $bad_checksum_manifest);
    assert_command_fails(
        [PHP_BINARY, 'tools/verify-wordpress-org-asset-manifest.php', '--manifest=' . $bad_checksum_root . '/manifest.json'],
        $plugin_root,
        'reject manifest with mismatched asset checksum',
        'does not match local file SHA-256'
    );

    $missing_evidence_root = copy_manifest_fixture($valid_root, $workspace . '/missing-evidence');
    $missing_evidence_manifest = read_manifest($missing_evidence_root);
    unset($missing_evidence_manifest['assets'][0]['source_evidence']);
    write_manifest($missing_evidence_root, $missing_evidence_manifest);
    assert_command_fails(
        [PHP_BINARY, 'tools/verify-wordpress-org-asset-manifest.php', '--manifest=' . $missing_evidence_root . '/manifest.json'],
        $plugin_root,
        'reject manifest missing source/license evidence',
        'missing source/license evidence'
    );

    $non_contiguous_root = copy_manifest_fixture($valid_root, $workspace . '/non-contiguous-screenshots');
    $non_contiguous_manifest = read_manifest($non_contiguous_root);
    $non_contiguous_manifest['assets'][] = create_asset_entry(
        $non_contiguous_root,
        'screenshot-3.png',
        'screenshot',
        1200,
        800,
        3,
        'Second numbered screenshot intentionally omitted.'
    );
    write_manifest($non_contiguous_root, $non_contiguous_manifest);
    assert_command_fails(
        [PHP_BINARY, 'tools/verify-wordpress-org-asset-manifest.php', '--manifest=' . $non_contiguous_root . '/manifest.json'],
        $plugin_root,
        'reject manifest with non-contiguous screenshot numbering',
        'contiguous screenshot numbering'
    );

    $unapproved_asset_root = copy_manifest_fixture($valid_root, $workspace . '/unapproved-asset');
    $unapproved_asset_manifest = read_manifest($unapproved_asset_root);
    $unapproved_asset_manifest['assets'][0]['approval_status'] = 'pending';
    write_manifest($unapproved_asset_root, $unapproved_asset_manifest);
    assert_command_fails(
        [PHP_BINARY, 'tools/verify-wordpress-org-asset-manifest.php', '--manifest=' . $unapproved_asset_root . '/manifest.json'],
        $plugin_root,
        'reject manifest with unapproved final asset entry',
        'approval_status must be explicitly approved'
    );

    $unapproved_source_root = copy_manifest_fixture($valid_root, $workspace . '/unapproved-source');
    $unapproved_source_manifest = read_manifest($unapproved_source_root);
    $unapproved_source_manifest['assets'][0]['source_evidence'][0]['approval_status'] = 'rejected';
    write_manifest($unapproved_source_root, $unapproved_source_manifest);
    assert_command_fails(
        [PHP_BINARY, 'tools/verify-wordpress-org-asset-manifest.php', '--manifest=' . $unapproved_source_root . '/manifest.json'],
        $plugin_root,
        'reject manifest with unapproved source evidence entry',
        'approval_status must be explicitly approved'
    );

    echo "WordPress.org asset manifest regressions passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'WordPress.org asset manifest regression failed: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    remove_tree($workspace);
}

/**
 * Creates a complete local-only generated asset manifest fixture.
 *
 * @param string $root Fixture root.
 * @return string Manifest path.
 */
function create_valid_manifest_fixture(string $root): string
{
    ensure_directory($root . '/final');
    ensure_directory($root . '/source');

    $manifest = [
        'schema' => 'language-fts-playground.wordpress-org-assets.v1',
        'plugin' => 'language-fts-playground',
        'plugin_version' => '0.3.0',
        'status' => 'approved',
        'approved_by' => 'Local regression fixture',
        'approved_at' => '2026-06-10',
        'assets' => [
            create_asset_entry($root, 'banner-772x250.png', 'banner', 772, 250, null, null),
            create_asset_entry($root, 'banner-1544x500.png', 'banner', 1544, 500, null, null),
            create_asset_entry($root, 'icon-128x128.png', 'icon', 128, 128, null, null),
            create_asset_entry($root, 'icon-256x256.png', 'icon', 256, 256, null, null),
            create_asset_entry(
                $root,
                'screenshot-1.png',
                'screenshot',
                1200,
                800,
                1,
                'Language FTS Playground admin screen showing local demo search controls.'
            ),
        ],
    ];

    write_manifest($root, $manifest);

    return $root . '/manifest.json';
}

/**
 * Creates one generated image and retained source evidence entry.
 *
 * @param string   $root           Fixture root.
 * @param string   $filename       Final asset filename.
 * @param string   $role           Asset role.
 * @param int      $width          Image width.
 * @param int      $height         Image height.
 * @param int|null $caption_number Optional screenshot caption number.
 * @param string|null $caption     Optional screenshot caption.
 * @return array<string,mixed> Manifest asset entry.
 */
function create_asset_entry(
    string $root,
    string $filename,
    string $role,
    int $width,
    int $height,
    ?int $caption_number,
    ?string $caption
): array {
    $final_path = $root . '/final/' . $filename;
    $source_path = $root . '/source/' . $filename . '.source.txt';

    write_png_fixture($final_path, $width, $height);
    write_file(
        $source_path,
        'Generated local regression source for ' . $filename . ".\n" .
        "This is not a final WordPress.org directory asset.\n"
    );

    $asset = [
        'asset_path' => 'final/' . $filename,
        'svn_asset_path' => 'assets/' . $filename,
        'asset_role' => $role,
        'format' => 'PNG',
        'dimensions' => ['width' => $width, 'height' => $height],
        'sha256' => hash_file('sha256', $final_path),
        'author' => 'Local regression fixture',
        'license' => 'GPL-2.0-or-later',
        'license_evidence' => 'Generated by local regression fixture and marked GPL-2.0-or-later for verifier coverage.',
        'approval_status' => 'approved',
        'approved_by' => 'Local regression fixture',
        'approved_at' => '2026-06-10',
        'source_evidence' => [
            [
                'source_path' => 'source/' . $filename . '.source.txt',
                'sha256' => hash_file('sha256', $source_path),
                'creator' => 'Local regression fixture',
                'license' => 'GPL-2.0-or-later',
                'license_evidence' => 'Retained local text source fixture with GPL-compatible policy marker.',
                'approval_status' => 'approved',
                'approved_by' => 'Local regression fixture',
                'approved_at' => '2026-06-10',
            ],
        ],
    ];

    if (null !== $caption_number && null !== $caption) {
        $asset['readme_caption_number'] = $caption_number;
        $asset['caption'] = $caption;
    }

    return $asset;
}

/**
 * Copies a manifest fixture tree.
 *
 * @param string $source Source fixture root.
 * @param string $target Target fixture root.
 * @return string Target fixture root.
 */
function copy_manifest_fixture(string $source, string $target): string
{
    copy_tree($source, $target);

    return $target;
}

/**
 * Reads a fixture manifest.
 *
 * @param string $root Fixture root.
 * @return array<string,mixed> Manifest object.
 */
function read_manifest(string $root): array
{
    $contents = file_get_contents($root . '/manifest.json');

    if (false === $contents) {
        throw new RuntimeException('Unable to read fixture manifest.');
    }

    $decoded = json_decode($contents, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Unable to decode fixture manifest.');
    }

    return $decoded;
}

/**
 * Writes a fixture manifest.
 *
 * @param string              $root     Fixture root.
 * @param array<string,mixed> $manifest Manifest object.
 */
function write_manifest(string $root, array $manifest): void
{
    $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode fixture manifest.');
    }

    write_file($root . '/manifest.json', $encoded . "\n");
}

/**
 * Writes a tiny PNG fixture with the requested dimensions.
 *
 * @param string $path   File path.
 * @param int    $width  Image width.
 * @param int    $height Image height.
 */
function write_png_fixture(string $path, int $width, int $height): void
{
    $contents = "\x89PNG\r\n\x1a\n" .
        png_chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)) .
        png_chunk('IEND', '');
    write_file($path, $contents);
}

/**
 * Builds a PNG chunk.
 *
 * @param string $type PNG chunk type.
 * @param string $data PNG chunk data.
 * @return string Encoded chunk.
 */
function png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', hexdec(hash('crc32b', $type . $data)));
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
        throw new RuntimeException('Unable to create asset manifest regression path.');
    }

    if (!unlink($base) || !mkdir($base, 0777, true)) {
        throw new RuntimeException('Unable to create asset manifest regression directory.');
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
 * Truncates command diagnostics to keep failures readable.
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
