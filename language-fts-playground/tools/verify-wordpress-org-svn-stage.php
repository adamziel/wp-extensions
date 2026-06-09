#!/usr/bin/env php
<?php
declare(strict_types=1);

const LANGUAGE_FTS_SVN_STAGE_MARKER = '.language-fts-wordpress-org-svn-stage';

$options = getopt(
    '',
    [
        'stage:',
        'strict-assets',
        'manifest-json:',
        'allow-svn-metadata',
        'help',
    ]
);

if (isset($options['help'])) {
    echo "Usage: php tools/verify-wordpress-org-svn-stage.php [--stage=dist/wordpress-org-svn-stage] [--strict-assets] [--manifest-json=path] [--allow-svn-metadata]\n";
    echo "\n";
    echo "Checks a dry-run WordPress.org Plugin Directory SVN stage for top-level\n";
    echo "trunk/, tags/<version>/, and assets/ layout, forbidden release paths,\n";
    echo "version metadata agreement, and supported directory asset filenames.\n";
    exit(0);
}

$plugin_root = dirname(__DIR__);
$stage = isset($options['stage']) ? (string) $options['stage'] : 'dist/wordpress-org-svn-stage';
$strict_assets = isset($options['strict-assets']);
$allow_svn_metadata = isset($options['allow-svn-metadata']);
$manifest_json = isset($options['manifest-json']) ? (string) $options['manifest-json'] : null;

try {
    $stage_root = absolute_path($stage, $plugin_root);
    $summary = verify_wordpress_org_svn_stage($stage_root, $strict_assets, $allow_svn_metadata);

    if (null !== $manifest_json) {
        write_manifest_json(absolute_path($manifest_json, $plugin_root), $stage_root, $summary);
    }

    echo 'WordPress.org SVN stage passed: ' . $summary['stage_root'] . "\n";
    echo 'Version: ' . $summary['version'] . "\n";
    echo 'Trunk files: ' . $summary['trunk_files'] . "\n";
    echo 'Tag: tags/' . $summary['version'] . "\n";
    echo 'Tag files: ' . $summary['tag_files'] . "\n";
    echo 'Assets: ' . count($summary['assets']) . "\n";
    echo 'Strict assets: ' . ($strict_assets ? 'yes' : 'no') . "\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'WordPress.org SVN stage failed: ' . $error->getMessage() . "\n");
    exit(1);
}

/**
 * Verifies a WordPress.org SVN staging tree.
 *
 * @param string $stage_root         Absolute stage root path.
 * @param bool   $strict_assets      Whether launch assets are required.
 * @param bool   $allow_svn_metadata Whether .svn directories are ignored.
 * @return array{stage_root:string,version:string,trunk_files:int,tag_files:int,assets:string[],paths:string[]}
 */
function verify_wordpress_org_svn_stage(string $stage_root, bool $strict_assets, bool $allow_svn_metadata): array
{
    $real_stage = realpath($stage_root);

    if (false === $real_stage || !is_dir($real_stage)) {
        throw new RuntimeException('SVN stage root does not exist: ' . $stage_root);
    }

    $stage_root = normalize_path($real_stage);
    assert_top_level_layout($stage_root, $allow_svn_metadata);
    assert_path_absent(
        $stage_root . '/trunk/language-fts-playground',
        'SVN trunk must contain plugin files directly, not a nested plugin root: trunk/language-fts-playground.'
    );

    $trunk = $stage_root . '/trunk';
    $assets = $stage_root . '/assets';
    $tags = $stage_root . '/tags';
    $trunk_metadata = inspect_payload_metadata($trunk, 'trunk');
    assert_payload_metadata_is_consistent($trunk_metadata, 'trunk');

    $tag_names = list_tag_names($tags, $allow_svn_metadata);

    if (1 !== count($tag_names)) {
        throw new RuntimeException('SVN stage must contain exactly one release tag directory; found ' . count($tag_names) . '.');
    }

    $version = $trunk_metadata['version'];
    $tag_name = $tag_names[0];

    if ($tag_name !== $version) {
        throw new RuntimeException('SVN tag directory must match the staged version: expected tags/' . $version . ', found tags/' . $tag_name . '.');
    }

    $tag = $tags . '/' . $version;
    $tag_metadata = inspect_payload_metadata($tag, 'tags/' . $version);
    assert_payload_metadata_is_consistent($tag_metadata, 'tags/' . $version);

    foreach (['version', 'constant_version', 'stable_tag'] as $key) {
        if ($trunk_metadata[$key] !== $tag_metadata[$key]) {
            throw new RuntimeException('SVN trunk and tag metadata differ for ' . $key . '.');
        }
    }

    assert_required_payload_paths($trunk, 'trunk');
    assert_required_payload_paths($tag, 'tags/' . $version);
    assert_no_forbidden_paths($stage_root, $stage_root, $allow_svn_metadata);
    assert_no_payload_assets_directory($trunk, 'trunk');
    assert_no_payload_assets_directory($tag, 'tags/' . $version);

    $trunk_files = list_relative_files($trunk, $trunk, $allow_svn_metadata);
    $tag_files = list_relative_files($tag, $tag, $allow_svn_metadata);
    assert_payloads_match($trunk, $tag, $trunk_files, $tag_files, $version);

    $asset_files = verify_assets($assets, $strict_assets);
    verify_screenshot_captions($trunk . '/readme.txt', $asset_files, 'trunk/readme.txt');
    verify_screenshot_captions($tag . '/readme.txt', $asset_files, 'tags/' . $version . '/readme.txt');

    return [
        'stage_root' => $stage_root,
        'version' => $version,
        'trunk_files' => count($trunk_files),
        'tag_files' => count($tag_files),
        'assets' => $asset_files,
        'paths' => list_relative_files($stage_root, $stage_root, $allow_svn_metadata),
    ];
}

/**
 * Verifies the top-level SVN checkout shape.
 *
 * @param string $stage_root         Absolute stage root path.
 * @param bool   $allow_svn_metadata Whether .svn directories are ignored.
 */
function assert_top_level_layout(string $stage_root, bool $allow_svn_metadata): void
{
    foreach (['assets', 'tags', 'trunk'] as $directory) {
        if (!is_dir($stage_root . '/' . $directory)) {
            throw new RuntimeException('SVN stage is missing top-level ' . $directory . '/ directory.');
        }
    }

    $items = scandir($stage_root);

    if (false === $items) {
        throw new RuntimeException('Unable to read SVN stage root: ' . $stage_root);
    }

    foreach ($items as $item) {
        if ('.' === $item || '..' === $item || LANGUAGE_FTS_SVN_STAGE_MARKER === $item) {
            continue;
        }

        if ($allow_svn_metadata && '.svn' === $item) {
            continue;
        }

        if (!in_array($item, ['assets', 'tags', 'trunk'], true)) {
            throw new RuntimeException('SVN stage contains unexpected top-level path: ' . $item);
        }
    }
}

/**
 * Reads release metadata from a staged plugin payload.
 *
 * @param string $payload Absolute payload path.
 * @param string $label   Diagnostic label.
 * @return array{version:string,constant_version:string,stable_tag:string}
 */
function inspect_payload_metadata(string $payload, string $label): array
{
    $main_file = read_required_file($payload . '/language-fts-playground.php', $label . '/language-fts-playground.php');
    $readme = read_required_file($payload . '/readme.txt', $label . '/readme.txt');

    return [
        'version' => match_required('/^\s*\*\s*Version:\s*([^\r\n]+)/mi', $main_file, $label . ' plugin header Version'),
        'constant_version' => match_required("/define\\(\\s*'LANGUAGE_FTS_PLAYGROUND_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $main_file, $label . ' LANGUAGE_FTS_PLAYGROUND_VERSION constant'),
        'stable_tag' => match_required('/^Stable tag:\s*([^\r\n]+)/mi', $readme, $label . ' readme Stable tag'),
    ];
}

/**
 * Verifies metadata consistency inside a staged payload.
 *
 * @param array{version:string,constant_version:string,stable_tag:string} $metadata Payload metadata.
 * @param string $label Diagnostic label.
 */
function assert_payload_metadata_is_consistent(array $metadata, string $label): void
{
    if ($metadata['version'] !== $metadata['constant_version']) {
        throw new RuntimeException($label . ' plugin header Version and version constant do not match.');
    }

    if ($metadata['version'] !== $metadata['stable_tag']) {
        throw new RuntimeException($label . ' plugin header Version and readme Stable tag do not match.');
    }

    if ('trunk' === strtolower($metadata['stable_tag'])) {
        throw new RuntimeException($label . ' readme Stable tag must be a release version, not trunk.');
    }
}

/**
 * Requires all runtime/source paths expected in trunk and tags/<version>.
 *
 * @param string $payload Absolute payload path.
 * @param string $label   Diagnostic label.
 */
function assert_required_payload_paths(string $payload, string $label): void
{
    foreach (required_payload_files() as $relative) {
        if (!is_file($payload . '/' . $relative)) {
            throw new RuntimeException($label . ' is missing required payload file: ' . $relative);
        }
    }

    foreach (['docs', 'resources/languages', 'src'] as $relative) {
        if (!is_dir($payload . '/' . $relative)) {
            throw new RuntimeException($label . ' is missing required payload directory: ' . $relative);
        }
    }
}

/**
 * Returns required payload file paths.
 *
 * @return string[] Required payload files.
 */
function required_payload_files(): array
{
    return [
        'language-fts-playground.php',
        'LICENSE',
        'README.md',
        'readme.txt',
        'docs/lexical-resources.md',
        'docs/release-packaging.md',
        'playground/blueprint.json',
        'src/bootstrap.php',
        'src/Analyzer.php',
        'src/Demo.php',
        'src/InMemoryStorage.php',
        'src/Indexer.php',
        'src/LexicalPackValidator.php',
        'src/LexicalProfileRepository.php',
        'src/Plugin.php',
        'src/SearchBenchmarkCounters.php',
        'src/Searcher.php',
        'src/StorageInterface.php',
        'src/Tokenizer.php',
        'src/WpdbStorage.php',
        'resources/languages/en/profile.php',
        'resources/languages/en/pack.php',
        'resources/languages/en/stopwords.txt',
        'resources/languages/en/lexemes.tsv',
        'resources/languages/en/synonyms.tsv',
        'resources/languages/en/synonym_phrases.tsv',
        'resources/languages/en/term_rules.tsv',
        'resources/languages/en/protected_terms.txt',
        'resources/languages/pl/profile.php',
        'resources/languages/pl/pack.php',
        'resources/languages/pl/stopwords.txt',
        'resources/languages/pl/lexemes.tsv',
        'resources/languages/pl/synonyms.tsv',
        'resources/languages/pl/synsets.tsv',
        'resources/languages/pl/term_rules.tsv',
        'resources/languages/de/profile.php',
        'resources/languages/de/pack.php',
        'resources/languages/de/stopwords.txt',
        'resources/languages/de/lexemes.tsv',
        'resources/languages/de/synonyms.tsv',
        'resources/languages/de/term_rules.tsv',
    ];
}

/**
 * Rejects forbidden paths anywhere in the stage.
 *
 * @param string $path               Absolute path being inspected.
 * @param string $stage_root         Absolute stage root.
 * @param bool   $allow_svn_metadata Whether .svn directories are ignored.
 */
function assert_no_forbidden_paths(string $path, string $stage_root, bool $allow_svn_metadata): void
{
    if (is_link($path)) {
        throw new RuntimeException('SVN stage contains symlink path: ' . relative_path($path, $stage_root));
    }

    $basename = basename($path);
    $relative = relative_path($path, $stage_root);

    if ($allow_svn_metadata && '.svn' === $basename) {
        return;
    }

    if (LANGUAGE_FTS_SVN_STAGE_MARKER !== $basename) {
        if (preg_match('/^(?:\.git|\.github|\.cao|\.tmp|node_modules|dist|coverage|tests|tools|review-artifacts|smoke-artifacts|static-site-output)$/', $basename)) {
            throw new RuntimeException('SVN stage contains forbidden path: ' . $relative);
        }

        if (
            '.DS_Store' === $basename ||
            preg_match('/\.(?:log|tmp|bak|swp|zip)$/', $basename)
        ) {
            throw new RuntimeException('SVN stage contains forbidden file path: ' . $relative);
        }
    }

    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);

    if (false === $items) {
        throw new RuntimeException('Unable to read SVN stage directory: ' . $relative);
    }

    foreach ($items as $item) {
        if ('.' === $item || '..' === $item) {
            continue;
        }

        assert_no_forbidden_paths($path . '/' . $item, $stage_root, $allow_svn_metadata);
    }
}

/**
 * Verifies top-level assets/ filenames and selected dimensions.
 *
 * @param string $assets        Absolute assets directory.
 * @param bool   $strict_assets Whether launch assets are required.
 * @return string[] Asset filenames.
 */
function verify_assets(string $assets, bool $strict_assets): array
{
    $items = scandir($assets);

    if (false === $items) {
        throw new RuntimeException('Unable to read SVN assets directory.');
    }

    $files = [];

    foreach ($items as $item) {
        if ('.' === $item || '..' === $item) {
            continue;
        }

        $path = $assets . '/' . $item;

        if (is_link($path)) {
            throw new RuntimeException('SVN assets directory contains symlink: ' . $item);
        }

        if (!is_file($path)) {
            throw new RuntimeException('SVN assets directory contains non-file path: ' . $item);
        }

        if (!is_allowed_asset_filename($item)) {
            throw new RuntimeException('Unsupported WordPress.org asset filename: ' . $item);
        }

        assert_asset_dimensions($path, $item);
        $files[] = $item;
    }

    sort($files, SORT_STRING);

    if ($strict_assets) {
        foreach (['banner-772x250.png', 'icon-128x128.png'] as $required) {
            if (!in_array($required, $files, true)) {
                throw new RuntimeException('Strict assets mode requires: ' . $required);
            }
        }

        if ([] === array_filter($files, static fn(string $file): bool => 1 === preg_match('/^screenshot-[1-9][0-9]*\.(?:png|jpg)$/', $file))) {
            throw new RuntimeException('Strict assets mode requires at least one screenshot asset.');
        }
    }

    return $files;
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
 * Verifies fixed-size banner and icon dimensions when assets are present.
 *
 * @param string $path     Absolute asset path.
 * @param string $filename Asset filename.
 */
function assert_asset_dimensions(string $path, string $filename): void
{
    $expected = [
        'banner-772x250.png' => [772, 250],
        'banner-1544x500.png' => [1544, 500],
        'icon-128x128.png' => [128, 128],
        'icon-256x256.png' => [256, 256],
    ];

    if (!isset($expected[$filename])) {
        if (false === getimagesize($path)) {
            throw new RuntimeException('WordPress.org screenshot asset is not a readable image: ' . $filename);
        }

        return;
    }

    $size = getimagesize($path);

    if (!is_array($size) || !isset($size[0], $size[1])) {
        throw new RuntimeException('Unable to inspect WordPress.org asset dimensions: ' . $filename);
    }

    if ([$size[0], $size[1]] !== $expected[$filename]) {
        throw new RuntimeException(
            'WordPress.org asset has wrong dimensions: ' . $filename .
            ' expected ' . $expected[$filename][0] . 'x' . $expected[$filename][1] .
            ', found ' . $size[0] . 'x' . $size[1] . '.'
        );
    }
}

/**
 * Verifies readme screenshot captions when screenshot assets exist.
 *
 * @param string   $readme_path Absolute readme path.
 * @param string[] $asset_files Asset filenames.
 * @param string   $label       Diagnostic label.
 */
function verify_screenshot_captions(string $readme_path, array $asset_files, string $label): void
{
    $screenshot_numbers = [];

    foreach ($asset_files as $file) {
        if (preg_match('/^screenshot-([1-9][0-9]*)\.(?:png|jpg)$/', $file, $matches)) {
            $screenshot_numbers[] = (int) $matches[1];
        }
    }

    sort($screenshot_numbers, SORT_NUMERIC);

    if ([] === $screenshot_numbers) {
        return;
    }

    $readme = read_required_file($readme_path, $label);

    if (false !== stripos($readme, 'No screenshot image assets are bundled')) {
        throw new RuntimeException($label . ' still contains placeholder screenshot wording while screenshot assets exist.');
    }

    $captions = parse_screenshot_captions($readme);

    if (count($captions) !== count($screenshot_numbers)) {
        throw new RuntimeException($label . ' screenshot caption count does not match screenshot assets.');
    }

    foreach ($screenshot_numbers as $number) {
        if (!isset($captions[$number])) {
            throw new RuntimeException($label . ' is missing screenshot caption for screenshot-' . $number . '.');
        }
    }
}

/**
 * Parses numbered captions from the readme Screenshots section.
 *
 * @param string $readme Readme contents.
 * @return array<int,string> Captions keyed by screenshot number.
 */
function parse_screenshot_captions(string $readme): array
{
    if (!preg_match('/^==\s*Screenshots\s*==\s*$(.*?)(?:^==\s+|\z)/ms', $readme, $matches)) {
        return [];
    }

    $captions = [];
    $lines = preg_split("/\r\n|\n|\r/", $matches[1]);

    if (!is_array($lines)) {
        return [];
    }

    foreach ($lines as $line) {
        if (preg_match('/^\s*([1-9][0-9]*)\.\s+(.+)$/', $line, $line_matches)) {
            $captions[(int) $line_matches[1]] = trim($line_matches[2]);
        }
    }

    return $captions;
}

/**
 * Requires that a payload does not carry an assets directory.
 *
 * @param string $payload Absolute payload path.
 * @param string $label   Diagnostic label.
 */
function assert_no_payload_assets_directory(string $payload, string $label): void
{
    if (is_dir($payload . '/assets')) {
        throw new RuntimeException($label . ' must not contain assets/; WordPress.org assets belong at the SVN root.');
    }
}

/**
 * Verifies that trunk and the release tag carry identical payload bytes.
 *
 * @param string   $trunk       Absolute trunk path.
 * @param string   $tag         Absolute release tag path.
 * @param string[] $trunk_files Trunk-relative files.
 * @param string[] $tag_files   Tag-relative files.
 * @param string   $version     Release version.
 */
function assert_payloads_match(string $trunk, string $tag, array $trunk_files, array $tag_files, string $version): void
{
    if ($trunk_files !== $tag_files) {
        throw new RuntimeException('SVN trunk and tags/' . $version . ' file lists differ.');
    }

    foreach ($trunk_files as $relative) {
        $trunk_hash = hash_file('sha256', $trunk . '/' . $relative);
        $tag_hash = hash_file('sha256', $tag . '/' . $relative);

        if (!is_string($trunk_hash) || !is_string($tag_hash) || $trunk_hash !== $tag_hash) {
            throw new RuntimeException('SVN trunk and tags/' . $version . ' file contents differ at: ' . $relative);
        }
    }
}

/**
 * Lists tag directory names.
 *
 * @param string $tags               Absolute tags directory.
 * @param bool   $allow_svn_metadata Whether .svn directories are ignored.
 * @return string[] Tag names.
 */
function list_tag_names(string $tags, bool $allow_svn_metadata): array
{
    $items = scandir($tags);

    if (false === $items) {
        throw new RuntimeException('Unable to read SVN tags directory.');
    }

    $names = [];

    foreach ($items as $item) {
        if ('.' === $item || '..' === $item) {
            continue;
        }

        if ($allow_svn_metadata && '.svn' === $item) {
            continue;
        }

        if (!is_dir($tags . '/' . $item)) {
            throw new RuntimeException('SVN tags/ contains a non-directory path: ' . $item);
        }

        $names[] = $item;
    }

    sort($names, SORT_STRING);

    return $names;
}

/**
 * Requires that a path does not exist.
 *
 * @param string $path    Absolute path.
 * @param string $message Failure message.
 */
function assert_path_absent(string $path, string $message): void
{
    if (file_exists($path)) {
        throw new RuntimeException($message);
    }
}

/**
 * Counts files under a directory.
 *
 * @param string $root               Absolute directory path.
 * @param bool   $allow_svn_metadata Whether .svn directories are ignored.
 * @return int File count.
 */
function count_files(string $root, bool $allow_svn_metadata): int
{
    return count(list_relative_files($root, $root, $allow_svn_metadata));
}

/**
 * Lists relative file paths under a directory.
 *
 * @param string $root               Absolute listing root.
 * @param string $base               Absolute base path for relative paths.
 * @param bool   $allow_svn_metadata Whether .svn directories are ignored.
 * @return string[] Relative file paths.
 */
function list_relative_files(string $root, string $base, bool $allow_svn_metadata): array
{
    $files = [];
    $items = scandir($root);

    if (false === $items) {
        throw new RuntimeException('Unable to read SVN stage directory: ' . relative_path($root, $base));
    }

    foreach ($items as $item) {
        if ('.' === $item || '..' === $item) {
            continue;
        }

        if ($allow_svn_metadata && '.svn' === $item) {
            continue;
        }

        $path = $root . '/' . $item;

        if (is_dir($path) && !is_link($path)) {
            $files = array_merge($files, list_relative_files($path, $base, $allow_svn_metadata));
            continue;
        }

        if (is_file($path)) {
            $files[] = relative_path($path, $base);
        }
    }

    sort($files, SORT_STRING);

    return $files;
}

/**
 * Writes an optional JSON manifest outside the stage root.
 *
 * @param string $manifest_path Absolute manifest path.
 * @param string $stage_root    Absolute stage root path.
 * @param array{stage_root:string,version:string,trunk_files:int,tag_files:int,assets:string[],paths:string[]} $summary Verification summary.
 */
function write_manifest_json(string $manifest_path, string $stage_root, array $summary): void
{
    $manifest_path = normalize_path($manifest_path);
    $stage_root = normalize_path($stage_root);

    if ($manifest_path === $stage_root || 0 === strpos($manifest_path, $stage_root . '/')) {
        throw new RuntimeException('Manifest JSON path must be outside the SVN stage root.');
    }

    $encoded = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode SVN stage manifest JSON.');
    }

    ensure_directory(dirname($manifest_path));

    if (false === file_put_contents($manifest_path, $encoded . "\n")) {
        throw new RuntimeException('Unable to write SVN stage manifest JSON: ' . $manifest_path);
    }
}

/**
 * Reads a required file.
 *
 * @param string $path  Absolute path.
 * @param string $label Diagnostic label.
 * @return string File contents.
 */
function read_required_file(string $path, string $label): string
{
    if (!is_file($path)) {
        throw new RuntimeException('Required staged file is missing: ' . $label);
    }

    $contents = file_get_contents($path);

    if (false === $contents) {
        throw new RuntimeException('Unable to read required staged file: ' . $label);
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
 * Returns a relative path for diagnostics.
 *
 * @param string $path Absolute path.
 * @param string $root Absolute root.
 * @return string Relative path.
 */
function relative_path(string $path, string $root): string
{
    return ltrim(str_replace(str_replace('\\', '/', $root), '', str_replace('\\', '/', $path)), '/');
}
