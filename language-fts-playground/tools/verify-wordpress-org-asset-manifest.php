#!/usr/bin/env php
<?php
declare(strict_types=1);

const LANGUAGE_FTS_ASSET_MANIFEST_SCHEMA = 'language-fts-playground.wordpress-org-assets.v1';
const LANGUAGE_FTS_ASSET_MANIFEST_PLUGIN = 'language-fts-playground';

$options = getopt('', ['manifest:', 'help']);

if (isset($options['help'])) {
    echo "Usage: php tools/verify-wordpress-org-asset-manifest.php --manifest=/path/to/manifest.json\n";
    echo "\n";
    echo "Checks a local WordPress.org Plugin Directory asset source/license\n";
    echo "manifest before approved files are copied with --assets-source.\n";
    echo "The verifier reads local files only; it does not fetch URLs, read\n";
    echo "credentials, run SVN, contact WordPress.org, or approve submission.\n";
    exit(0);
}

$plugin_root = dirname(__DIR__);
$manifest = isset($options['manifest']) ? (string) $options['manifest'] : 'wordpress-org-assets/manifest.json';

try {
    $summary = verify_wordpress_org_asset_manifest(absolute_path($manifest, $plugin_root));

    echo 'WordPress.org asset manifest passed: ' . $summary['manifest_path'] . "\n";
    echo 'Plugin: ' . $summary['plugin'] . "\n";
    echo 'Schema: ' . $summary['schema'] . "\n";
    echo 'Assets: ' . $summary['asset_count'] . "\n";
    echo 'Source evidence entries: ' . $summary['source_evidence_count'] . "\n";
    echo 'Screenshots: ' . $summary['screenshot_count'] . "\n";
    echo 'Network access: not performed' . "\n";
    echo 'WordPress.org/SVN access: not performed' . "\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'WordPress.org asset manifest failed: ' . $error->getMessage() . "\n");
    exit(1);
}

/**
 * Verifies a retained source/license manifest for final WordPress.org assets.
 *
 * @param string $manifest_path Absolute or plugin-root-relative manifest path.
 * @return array{manifest_path:string,plugin:string,schema:string,asset_count:int,source_evidence_count:int,screenshot_count:int}
 */
function verify_wordpress_org_asset_manifest(string $manifest_path): array
{
    if (is_link($manifest_path)) {
        throw new RuntimeException('Manifest path must be a regular file, not a symlink.');
    }

    $real_manifest = realpath($manifest_path);

    if (false === $real_manifest || !is_file($real_manifest)) {
        throw new RuntimeException('Manifest JSON does not exist: ' . $manifest_path);
    }

    if (!is_readable($real_manifest)) {
        throw new RuntimeException('Manifest JSON is not readable: ' . $manifest_path);
    }

    $manifest_root = normalize_path(dirname($real_manifest));
    $manifest = read_json_file($real_manifest);

    assert_no_secret_like_keys($manifest);
    assert_manifest_header($manifest);

    $assets = require_array_field($manifest, 'assets', 'manifest');

    if ([] === $assets) {
        throw new RuntimeException('Manifest assets must contain at least the required launch asset entries.');
    }

    $filenames = [];
    $svn_paths = [];
    $screenshot_numbers = [];
    $source_evidence_count = 0;

    foreach ($assets as $index => $asset) {
        $label = 'assets[' . $index . ']';

        if (!is_array($asset)) {
            throw new RuntimeException($label . ' must be an object.');
        }

        $filename = verify_asset_entry($asset, $label, $manifest_root);

        if (isset($filenames[$filename])) {
            throw new RuntimeException('Manifest contains duplicate final asset filename: ' . $filename);
        }

        $svn_path = require_non_empty_string_field($asset, 'svn_asset_path', $label);

        if (isset($svn_paths[$svn_path])) {
            throw new RuntimeException('Manifest contains duplicate SVN asset path: ' . $svn_path);
        }

        $filenames[$filename] = true;
        $svn_paths[$svn_path] = true;

        if (preg_match('/^screenshot-([1-9][0-9]*)\.(?:png|jpg)$/', $filename, $matches)) {
            $number = (int) $matches[1];

            if (isset($screenshot_numbers[$number])) {
                throw new RuntimeException('Manifest must include one image per screenshot number; duplicate screenshot-' . $number . '.');
            }

            $screenshot_numbers[$number] = $filename;
        }

        $source_evidence_count += verify_source_evidence_entries($asset, $label, $manifest_root);
    }

    assert_required_launch_assets($filenames);
    assert_contiguous_screenshots($screenshot_numbers);

    return [
        'manifest_path' => normalize_path($real_manifest),
        'plugin' => (string) $manifest['plugin'],
        'schema' => (string) $manifest['schema'],
        'asset_count' => count($assets),
        'source_evidence_count' => $source_evidence_count,
        'screenshot_count' => count($screenshot_numbers),
    ];
}

/**
 * Verifies plugin identity, schema marker, and manifest-level approval.
 *
 * @param array<string,mixed> $manifest Decoded manifest.
 */
function assert_manifest_header(array $manifest): void
{
    $schema = require_non_empty_string_field($manifest, 'schema', 'manifest');

    if (LANGUAGE_FTS_ASSET_MANIFEST_SCHEMA !== $schema) {
        throw new RuntimeException(
            'Manifest schema must be ' . LANGUAGE_FTS_ASSET_MANIFEST_SCHEMA . '; found ' . $schema . '.'
        );
    }

    $plugin = require_non_empty_string_field($manifest, 'plugin', 'manifest');

    if (LANGUAGE_FTS_ASSET_MANIFEST_PLUGIN !== $plugin) {
        throw new RuntimeException(
            'Manifest plugin identity must be ' . LANGUAGE_FTS_ASSET_MANIFEST_PLUGIN . '; found ' . $plugin . '.'
        );
    }

    require_non_empty_string_field($manifest, 'plugin_version', 'manifest');
    require_approved_status($manifest, 'status', 'manifest');
    require_non_empty_string_field($manifest, 'approved_by', 'manifest');
    require_non_empty_string_field($manifest, 'approved_at', 'manifest');
}

/**
 * Verifies one final asset entry and its local image metadata.
 *
 * @param array<string,mixed> $asset         Manifest asset entry.
 * @param string              $label         Diagnostic label.
 * @param string              $manifest_root Manifest directory.
 * @return string Final asset filename.
 */
function verify_asset_entry(array $asset, string $label, string $manifest_root): string
{
    $asset_path = require_non_empty_string_field($asset, 'asset_path', $label);
    assert_safe_relative_path($asset_path, $label . '.asset_path');

    $filename = basename($asset_path);

    if (!is_allowed_asset_filename($filename)) {
        throw new RuntimeException($label . ' has unsupported WordPress.org asset filename: ' . $filename);
    }

    $svn_asset_path = require_non_empty_string_field($asset, 'svn_asset_path', $label);

    if ('assets/' . $filename !== $svn_asset_path) {
        throw new RuntimeException($label . '.svn_asset_path must be assets/' . $filename . '.');
    }

    $role = require_non_empty_string_field($asset, 'asset_role', $label);
    $expected_role = expected_asset_role($filename);

    if ($role !== $expected_role) {
        throw new RuntimeException($label . '.asset_role must be ' . $expected_role . ' for ' . $filename . '.');
    }

    $asset_file = resolve_manifest_file($manifest_root, $asset_path, $label . '.asset_path');
    $details = inspect_asset_image($asset_file, $filename);
    $format = require_non_empty_string_field($asset, 'format', $label);

    if ($format !== $details['format']) {
        throw new RuntimeException($label . '.format must be ' . $details['format'] . ' for ' . $filename . '.');
    }

    $dimensions = require_array_field($asset, 'dimensions', $label);
    $width = require_int_field($dimensions, 'width', $label . '.dimensions');
    $height = require_int_field($dimensions, 'height', $label . '.dimensions');

    if ($width !== $details['width'] || $height !== $details['height']) {
        throw new RuntimeException(
            $label . '.dimensions do not match image metadata for ' . $filename .
            ': manifest ' . $width . 'x' . $height .
            ', image ' . $details['width'] . 'x' . $details['height'] . '.'
        );
    }

    assert_sha256_matches($asset_file, require_sha256_field($asset, 'sha256', $label), $label . '.sha256');
    require_creator_or_owner($asset, $label);
    require_accepted_license($asset, 'license', $label);
    require_non_empty_string_field($asset, 'license_evidence', $label);
    require_approved_status($asset, 'approval_status', $label);
    require_non_empty_string_field($asset, 'approved_by', $label);
    require_non_empty_string_field($asset, 'approved_at', $label);

    if ('screenshot' === $expected_role) {
        assert_screenshot_caption($asset, $label, $filename);
    }

    return $filename;
}

/**
 * Verifies retained source/license evidence entries for one final asset.
 *
 * @param array<string,mixed> $asset         Manifest asset entry.
 * @param string              $label         Diagnostic label.
 * @param string              $manifest_root Manifest directory.
 * @return int Number of source evidence entries.
 */
function verify_source_evidence_entries(array $asset, string $label, string $manifest_root): int
{
    if (!array_key_exists('source_evidence', $asset)) {
        throw new RuntimeException($label . ' is missing source/license evidence entries.');
    }

    $entries = require_array_field($asset, 'source_evidence', $label);

    if ([] === $entries) {
        throw new RuntimeException($label . '.source_evidence must contain at least one retained source entry.');
    }

    foreach ($entries as $index => $entry) {
        $entry_label = $label . '.source_evidence[' . $index . ']';

        if (!is_array($entry)) {
            throw new RuntimeException($entry_label . ' must be an object.');
        }

        $source_path = require_non_empty_string_field($entry, 'source_path', $entry_label);
        assert_safe_relative_path($source_path, $entry_label . '.source_path');

        $source_file = resolve_manifest_file($manifest_root, $source_path, $entry_label . '.source_path');
        assert_sha256_matches($source_file, require_sha256_field($entry, 'sha256', $entry_label), $entry_label . '.sha256');
        require_creator_or_owner($entry, $entry_label);
        require_accepted_license($entry, 'license', $entry_label);
        require_non_empty_string_field($entry, 'license_evidence', $entry_label);
        require_approved_status($entry, 'approval_status', $entry_label);
        require_non_empty_string_field($entry, 'approved_by', $entry_label);
        require_non_empty_string_field($entry, 'approved_at', $entry_label);
    }

    return count($entries);
}

/**
 * Requires every launch filename covered by the manifest.
 *
 * @param array<string,bool> $filenames Manifest filenames.
 */
function assert_required_launch_assets(array $filenames): void
{
    foreach (['banner-772x250.png', 'banner-1544x500.png', 'icon-128x128.png', 'icon-256x256.png'] as $required) {
        if (!isset($filenames[$required])) {
            throw new RuntimeException('Required launch asset is missing from manifest: ' . $required);
        }
    }

    if (!isset($filenames['screenshot-1.png']) && !isset($filenames['screenshot-1.jpg'])) {
        throw new RuntimeException('Required launch screenshot is missing from manifest: screenshot-1.png or screenshot-1.jpg.');
    }
}

/**
 * Requires screenshot numbers to be contiguous from screenshot-1.
 *
 * @param array<int,string> $screenshot_numbers Screenshot filename map.
 */
function assert_contiguous_screenshots(array $screenshot_numbers): void
{
    if ([] === $screenshot_numbers) {
        throw new RuntimeException('Manifest must contain at least one screenshot asset.');
    }

    ksort($screenshot_numbers, SORT_NUMERIC);
    $numbers = array_keys($screenshot_numbers);
    $expected = range(1, count($numbers));

    if ($numbers !== $expected) {
        $missing = array_values(array_diff($expected, $numbers));
        throw new RuntimeException(
            'Manifest requires contiguous screenshot numbering starting at screenshot-1; missing screenshot-' . $missing[0] . '.'
        );
    }
}

/**
 * Requires a screenshot caption and matching readme caption number.
 *
 * @param array<string,mixed> $asset    Manifest asset entry.
 * @param string              $label    Diagnostic label.
 * @param string              $filename Screenshot filename.
 */
function assert_screenshot_caption(array $asset, string $label, string $filename): void
{
    if (!preg_match('/^screenshot-([1-9][0-9]*)\.(?:png|jpg)$/', $filename, $matches)) {
        throw new RuntimeException($label . ' is not a supported screenshot filename: ' . $filename);
    }

    $number = (int) $matches[1];
    $caption_number = require_int_field($asset, 'readme_caption_number', $label);

    if ($caption_number !== $number) {
        throw new RuntimeException($label . '.readme_caption_number must match ' . $filename . '.');
    }

    require_non_empty_string_field($asset, 'caption', $label);
}

/**
 * Verifies that a file SHA-256 exactly matches the manifest value.
 *
 * @param string $path     Absolute file path.
 * @param string $expected Expected hex SHA-256.
 * @param string $label    Diagnostic label.
 */
function assert_sha256_matches(string $path, string $expected, string $label): void
{
    $actual = hash_file('sha256', $path);

    if (!is_string($actual)) {
        throw new RuntimeException('Unable to hash file for ' . $label . '.');
    }

    if (!hash_equals(strtolower($expected), strtolower($actual))) {
        throw new RuntimeException($label . ' does not match local file SHA-256.');
    }
}

/**
 * Returns whether a filename is allowed for this WordPress.org asset policy.
 *
 * @param string $filename Asset filename.
 * @return bool Whether the filename is accepted.
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
 * Returns the expected asset role for a filename.
 *
 * @param string $filename Asset filename.
 * @return string Asset role.
 */
function expected_asset_role(string $filename): string
{
    if (0 === strpos($filename, 'banner-')) {
        return 'banner';
    }

    if (0 === strpos($filename, 'icon-')) {
        return 'icon';
    }

    if (0 === strpos($filename, 'screenshot-')) {
        return 'screenshot';
    }

    throw new RuntimeException('Unsupported WordPress.org asset filename: ' . $filename);
}

/**
 * Inspects a final asset image and verifies format/dimensions required by name.
 *
 * @param string $path     Absolute asset path.
 * @param string $filename Asset filename.
 * @return array{width:int,height:int,format:string,mime:string}
 */
function inspect_asset_image(string $path, string $filename): array
{
    $expected = [
        'banner-772x250.png' => [772, 250],
        'banner-1544x500.png' => [1544, 500],
        'icon-128x128.png' => [128, 128],
        'icon-256x256.png' => [256, 256],
    ];
    $expected_format = expected_image_format($filename);
    $size = getimagesize($path);

    if (!is_array($size) || !isset($size[0], $size[1], $size[2], $size['mime'])) {
        throw new RuntimeException('Unable to inspect WordPress.org asset image metadata: ' . $filename);
    }

    if ($size[2] !== $expected_format['type']) {
        throw new RuntimeException(
            'WordPress.org asset format does not match filename: ' . $filename .
            ' expected ' . $expected_format['label'] . ', found ' . image_type_label((int) $size[2]) . '.'
        );
    }

    if (isset($expected[$filename]) && [$size[0], $size[1]] !== $expected[$filename]) {
        throw new RuntimeException(
            'WordPress.org asset has wrong dimensions: ' . $filename .
            ' expected ' . $expected[$filename][0] . 'x' . $expected[$filename][1] .
            ', found ' . $size[0] . 'x' . $size[1] . '.'
        );
    }

    return [
        'width' => (int) $size[0],
        'height' => (int) $size[1],
        'format' => $expected_format['label'],
        'mime' => (string) $size['mime'],
    ];
}

/**
 * Returns the image type required by an asset filename.
 *
 * @param string $filename Asset filename.
 * @return array{type:int,label:string}
 */
function expected_image_format(string $filename): array
{
    if ('.png' === substr($filename, -4)) {
        return [
            'type' => IMAGETYPE_PNG,
            'label' => 'PNG',
        ];
    }

    if ('.jpg' === substr($filename, -4)) {
        return [
            'type' => IMAGETYPE_JPEG,
            'label' => 'JPEG',
        ];
    }

    throw new RuntimeException('Unsupported WordPress.org asset image format: ' . $filename);
}

/**
 * Converts a PHP image type constant to a readable label.
 *
 * @param int $type Image type.
 * @return string Image label.
 */
function image_type_label(int $type): string
{
    if (IMAGETYPE_PNG === $type) {
        return 'PNG';
    }

    if (IMAGETYPE_JPEG === $type) {
        return 'JPEG';
    }

    return 'type ' . $type;
}

/**
 * Reads and decodes a JSON file into an associative array.
 *
 * @param string $path Absolute file path.
 * @return array<string,mixed> Decoded object.
 */
function read_json_file(string $path): array
{
    $contents = file_get_contents($path);

    if (false === $contents) {
        throw new RuntimeException('Unable to read manifest JSON: ' . $path);
    }

    $decoded = json_decode($contents, true);

    if (JSON_ERROR_NONE !== json_last_error()) {
        throw new RuntimeException('Manifest JSON is invalid: ' . json_last_error_msg());
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('Manifest JSON must decode to an object.');
    }

    return $decoded;
}

/**
 * Rejects manifest keys that imply credentials or secrets belong in the file.
 *
 * @param mixed  $value Decoded JSON value.
 * @param string $path  Diagnostic path.
 */
function assert_no_secret_like_keys($value, string $path = 'manifest'): void
{
    if (!is_array($value)) {
        return;
    }

    foreach ($value as $key => $child) {
        $key_label = is_int($key) ? '[' . $key . ']' : (string) $key;
        $child_path = is_int($key) ? $path . $key_label : $path . '.' . $key_label;

        if (
            is_string($key) &&
            1 === preg_match('/(?:credential|secret|token|password|private[_-]?key|api[_-]?key|authorization|cookie)/i', $key)
        ) {
            throw new RuntimeException('Manifest must not contain credential/secret-like field: ' . $child_path);
        }

        assert_no_secret_like_keys($child, $child_path);
    }
}

/**
 * Resolves a relative manifest file path and requires it to stay under root.
 *
 * @param string $manifest_root Manifest directory.
 * @param string $relative      Relative manifest path.
 * @param string $label         Diagnostic label.
 * @return string Absolute resolved file path.
 */
function resolve_manifest_file(string $manifest_root, string $relative, string $label): string
{
    $candidate = $manifest_root . '/' . $relative;

    if (is_link($candidate)) {
        throw new RuntimeException($label . ' must be a regular file, not a symlink.');
    }

    $real = realpath($candidate);

    if (false === $real || !is_file($real)) {
        throw new RuntimeException($label . ' does not exist as a local file: ' . $relative);
    }

    if (!is_readable($real)) {
        throw new RuntimeException($label . ' is not readable: ' . $relative);
    }

    $real = normalize_path($real);

    if ($real !== $manifest_root && 0 !== strpos($real, $manifest_root . '/')) {
        throw new RuntimeException($label . ' must resolve under the manifest root: ' . $relative);
    }

    return $real;
}

/**
 * Requires a manifest path to be a local relative path.
 *
 * @param string $path  Relative path.
 * @param string $label Diagnostic label.
 */
function assert_safe_relative_path(string $path, string $label): void
{
    if ('' === trim($path)) {
        throw new RuntimeException($label . ' must not be empty.');
    }

    if (false !== strpos($path, "\0") || false !== strpos($path, "\n") || false !== strpos($path, "\r")) {
        throw new RuntimeException($label . ' contains invalid control characters.');
    }

    $path = str_replace('\\', '/', $path);

    if ('/' === substr($path, 0, 1) || '~' === substr($path, 0, 1) || false !== strpos($path, '://')) {
        throw new RuntimeException($label . ' must be a local relative path.');
    }

    if (1 === preg_match('#(?:^|/)\.\.(?:/|$)#', $path)) {
        throw new RuntimeException($label . ' must not contain parent-directory traversal.');
    }
}

/**
 * Requires a non-empty string field.
 *
 * @param array<string,mixed> $data  Object.
 * @param string              $field Field name.
 * @param string              $label Diagnostic label.
 * @return string Field value.
 */
function require_non_empty_string_field(array $data, string $field, string $label): string
{
    if (!array_key_exists($field, $data) || !is_string($data[$field]) || '' === trim($data[$field])) {
        throw new RuntimeException($label . '.' . $field . ' must be an explicit non-empty string.');
    }

    return trim($data[$field]);
}

/**
 * Requires an array/object field.
 *
 * @param array<string,mixed> $data  Object.
 * @param string              $field Field name.
 * @param string              $label Diagnostic label.
 * @return array<mixed> Field value.
 */
function require_array_field(array $data, string $field, string $label): array
{
    if (!array_key_exists($field, $data) || !is_array($data[$field])) {
        throw new RuntimeException($label . '.' . $field . ' must be present.');
    }

    return $data[$field];
}

/**
 * Requires an integer field.
 *
 * @param array<string,mixed> $data  Object.
 * @param string              $field Field name.
 * @param string              $label Diagnostic label.
 * @return int Field value.
 */
function require_int_field(array $data, string $field, string $label): int
{
    if (!array_key_exists($field, $data) || !is_int($data[$field])) {
        throw new RuntimeException($label . '.' . $field . ' must be an explicit integer.');
    }

    return $data[$field];
}

/**
 * Requires a valid SHA-256 field.
 *
 * @param array<string,mixed> $data  Object.
 * @param string              $field Field name.
 * @param string              $label Diagnostic label.
 * @return string SHA-256 value.
 */
function require_sha256_field(array $data, string $field, string $label): string
{
    $sha256 = require_non_empty_string_field($data, $field, $label);

    if (1 !== preg_match('/^[a-f0-9]{64}$/', strtolower($sha256))) {
        throw new RuntimeException($label . '.' . $field . ' must be a 64-character SHA-256 hex digest.');
    }

    return strtolower($sha256);
}

/**
 * Requires explicit approved status; missing approval never defaults to pass.
 *
 * @param array<string,mixed> $data  Object.
 * @param string              $field Approval field name.
 * @param string              $label Diagnostic label.
 */
function require_approved_status(array $data, string $field, string $label): void
{
    $status = require_non_empty_string_field($data, $field, $label);

    if ('approved' !== $status) {
        throw new RuntimeException($label . '.' . $field . ' must be explicitly approved.');
    }
}

/**
 * Requires an accepted GPL-compatible license value.
 *
 * @param array<string,mixed> $data  Object.
 * @param string              $field License field name.
 * @param string              $label Diagnostic label.
 */
function require_accepted_license(array $data, string $field, string $label): void
{
    $license = require_non_empty_string_field($data, $field, $label);

    if (!in_array($license, accepted_gpl_compatible_licenses(), true)) {
        throw new RuntimeException(
            $label . '.' . $field . ' must be an accepted explicit GPL-compatible license; found ' . $license . '.'
        );
    }
}

/**
 * Returns the repository policy allowlist for asset evidence licenses.
 *
 * @return string[] Accepted SPDX-like license values.
 */
function accepted_gpl_compatible_licenses(): array
{
    return [
        'GPL-2.0-only',
        'GPL-2.0-or-later',
        'MIT',
        'BSD-2-Clause',
        'BSD-3-Clause',
        'CC0-1.0',
    ];
}

/**
 * Requires creator/author/source owner attribution.
 *
 * @param array<string,mixed> $data  Object.
 * @param string              $label Diagnostic label.
 */
function require_creator_or_owner(array $data, string $label): void
{
    foreach (['creator', 'author', 'source_owner'] as $field) {
        if (array_key_exists($field, $data) && is_string($data[$field]) && '' !== trim($data[$field])) {
            return;
        }
    }

    throw new RuntimeException($label . ' must name a creator, author, or source owner.');
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
