#!/usr/bin/env php
<?php
declare(strict_types=1);

const LANGUAGE_FTS_RELEASE_SLUG = 'language-fts-playground';
const LANGUAGE_FTS_RELEASE_MTIME = 946684800;

$options = getopt('', ['zip:', 'help']);

if (isset($options['help']) || empty($options['zip'])) {
    echo "Usage: php tools/verify-release-zip.php --zip=dist/language-fts-playground-0.3.0.zip\n";
    echo "\n";
    echo "Checks that a Language FTS Playground release zip has one plugin root,\n";
    echo "required runtime files, bundled lexical resources, the Playground\n";
    echo "Blueprint, and maintained release exclusions.\n";
    exit(isset($options['help']) ? 0 : 2);
}

try {
    $summary = verify_release_zip((string) $options['zip']);

    echo 'Release zip integrity passed: ' . $summary['zip_path'] . "\n";
    echo 'Entries: ' . $summary['entries'] . "\n";
    echo 'Root: ' . $summary['root'] . "\n";
    echo 'Version: ' . $summary['version'] . "\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Release zip integrity failed: ' . $error->getMessage() . "\n");
    exit(1);
}

/**
 * Verifies release zip contents.
 *
 * @param string $zip_path Zip path.
 * @return array{zip_path:string,entries:int,root:string,version:string}
 */
function verify_release_zip(string $zip_path): array
{
    $real_zip = realpath($zip_path);

    if (false === $real_zip || !is_file($real_zip)) {
        throw new RuntimeException('Release zip does not exist: ' . $zip_path);
    }

    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('The PHP zip extension is required to inspect release zips.');
    }

    $zip = new ZipArchive();

    if (true !== $zip->open($real_zip)) {
        throw new RuntimeException('Unable to open release zip: ' . $real_zip);
    }

    $paths = [];
    $seen_paths = [];

    for ($index = 0; $index < $zip->numFiles; ++$index) {
        $path = $zip->getNameIndex($index);

        if (!is_string($path)) {
            throw new RuntimeException('Unable to read release zip entry at index: ' . $index);
        }

        if (is_release_zip_symlink_entry($zip, $index)) {
            throw new RuntimeException('Release zip contains symlink entry path: ' . $path);
        }

        assert_release_zip_entry_mtime($zip, $index, $path);

        if (isset($seen_paths[$path])) {
            throw new RuntimeException('Release zip contains duplicate entry path: ' . $path);
        }

        $seen_paths[$path] = true;
        $paths[] = $path;
    }

    sort($paths);

    $root = LANGUAGE_FTS_RELEASE_SLUG . '/';
    $set = array_fill_keys($paths, true);

    foreach ($paths as $path) {
        if (is_unsafe_release_zip_path($path)) {
            throw new RuntimeException('Release zip contains an unsafe entry path: ' . $path);
        }

        if (0 !== strpos($path, $root)) {
            throw new RuntimeException('Release zip contains a path outside the plugin root: ' . $path);
        }

        assert_not_excluded_release_path($path);
    }

    foreach (required_release_paths($root) as $path) {
        if (!isset($set[$path])) {
            throw new RuntimeException('Release zip is missing required runtime path: ' . $path);
        }
    }

    $main_file = $zip->getFromName($root . 'language-fts-playground.php');

    if (!is_string($main_file)) {
        throw new RuntimeException('Unable to read main plugin file from release zip.');
    }

    $version = match_required('/^\s*\*\s*Version:\s*([^\r\n]+)/mi', $main_file, 'plugin header Version');
    $constant_version = match_required("/define\\(\\s*'LANGUAGE_FTS_PLAYGROUND_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $main_file, 'LANGUAGE_FTS_PLAYGROUND_VERSION constant');

    if ($version !== $constant_version) {
        throw new RuntimeException(
            'Release version mismatch inside zip: plugin header Version is ' . $version .
            ', but LANGUAGE_FTS_PLAYGROUND_VERSION is ' . $constant_version . '.'
        );
    }

    $blueprint = $zip->getFromName($root . 'playground/blueprint.json');

    if (!is_string($blueprint)) {
        throw new RuntimeException('Unable to read Playground Blueprint from release zip.');
    }

    json_decode($blueprint);

    if (JSON_ERROR_NONE !== json_last_error()) {
        throw new RuntimeException('Release zip contains invalid Playground Blueprint JSON: ' . json_last_error_msg());
    }

    $zip->close();

    return [
        'zip_path' => $real_zip,
        'entries' => count($paths),
        'root' => LANGUAGE_FTS_RELEASE_SLUG,
        'version' => $version,
    ];
}

/**
 * Returns required package paths.
 *
 * @param string $root Zip root path with trailing slash.
 * @return string[] Required paths.
 */
function required_release_paths(string $root): array
{
    return [
        $root . 'language-fts-playground.php',
        $root . 'LICENSE',
        $root . 'README.md',
        $root . 'docs/lexical-resources.md',
        $root . 'docs/release-packaging.md',
        $root . 'playground/blueprint.json',
        $root . 'src/bootstrap.php',
        $root . 'src/Analyzer.php',
        $root . 'src/Demo.php',
        $root . 'src/InMemoryStorage.php',
        $root . 'src/Indexer.php',
        $root . 'src/LexicalPackValidator.php',
        $root . 'src/LexicalProfileRepository.php',
        $root . 'src/Plugin.php',
        $root . 'src/SearchBenchmarkCounters.php',
        $root . 'src/Searcher.php',
        $root . 'src/StorageInterface.php',
        $root . 'src/Tokenizer.php',
        $root . 'src/WpdbStorage.php',
        $root . 'resources/languages/en/profile.php',
        $root . 'resources/languages/en/pack.php',
        $root . 'resources/languages/en/stopwords.txt',
        $root . 'resources/languages/en/lexemes.tsv',
        $root . 'resources/languages/en/synonyms.tsv',
        $root . 'resources/languages/en/synonym_phrases.tsv',
        $root . 'resources/languages/en/term_rules.tsv',
        $root . 'resources/languages/en/protected_terms.txt',
        $root . 'resources/languages/pl/profile.php',
        $root . 'resources/languages/pl/pack.php',
        $root . 'resources/languages/pl/stopwords.txt',
        $root . 'resources/languages/pl/lexemes.tsv',
        $root . 'resources/languages/pl/synonyms.tsv',
        $root . 'resources/languages/pl/synsets.tsv',
        $root . 'resources/languages/pl/term_rules.tsv',
        $root . 'resources/languages/de/profile.php',
        $root . 'resources/languages/de/pack.php',
        $root . 'resources/languages/de/stopwords.txt',
        $root . 'resources/languages/de/lexemes.tsv',
        $root . 'resources/languages/de/synonyms.tsv',
        $root . 'resources/languages/de/term_rules.tsv',
    ];
}

/**
 * Rejects paths that should never ship in the release package.
 *
 * @param string $path Zip entry path.
 */
function assert_not_excluded_release_path(string $path): void
{
    $trimmed = rtrim($path, '/');
    $basename = basename($trimmed);

    $excluded_exact = [
        LANGUAGE_FTS_RELEASE_SLUG . '/.distignore',
        LANGUAGE_FTS_RELEASE_SLUG . '/tools/build-release.php',
        LANGUAGE_FTS_RELEASE_SLUG . '/tools/verify-release-zip.php',
        LANGUAGE_FTS_RELEASE_SLUG . '/tests/run.php',
    ];

    if (in_array($trimmed, $excluded_exact, true)) {
        throw new RuntimeException('Release zip contains excluded path: ' . $path);
    }

    if (preg_match('#/(?:\.git|\.github|\.cao|\.tmp|node_modules|dist|coverage|tests|tools|review-artifacts|smoke-artifacts|static-site-output)(?:/|$)#', '/' . $trimmed . '/')) {
        throw new RuntimeException('Release zip contains an excluded tree path: ' . $path);
    }

    if (
        '.DS_Store' === $basename ||
        preg_match('/\.(?:log|tmp|bak|swp|zip)$/', $basename)
    ) {
        throw new RuntimeException('Release zip contains excluded file path: ' . $path);
    }
}

/**
 * Verifies that a zip entry has the release-normalized mtime.
 *
 * @param ZipArchive $zip   Zip archive.
 * @param int        $index Entry index.
 * @param string     $path  Entry path.
 */
function assert_release_zip_entry_mtime(ZipArchive $zip, int $index, string $path): void
{
    $stat = $zip->statIndex($index);

    if (!is_array($stat) || !isset($stat['mtime'])) {
        throw new RuntimeException('Unable to inspect release zip entry mtime: ' . $path);
    }

    if (LANGUAGE_FTS_RELEASE_MTIME !== (int) $stat['mtime']) {
        throw new RuntimeException('Release zip entry has nondeterministic mtime: ' . $path);
    }
}

/**
 * Returns whether a zip entry path is unsafe to extract.
 *
 * @param string $path Zip entry path.
 * @return bool Whether the path is unsafe.
 */
function is_unsafe_release_zip_path(string $path): bool
{
    if ('' === $path || '/' === $path[0] || false !== strpos($path, '\\')) {
        return true;
    }

    $parts = explode('/', rtrim($path, '/'));

    foreach ($parts as $part) {
        if ('' === $part || '.' === $part || '..' === $part) {
            return true;
        }
    }

    return false;
}

/**
 * Returns whether a zip entry is a Unix symlink.
 *
 * @param ZipArchive $zip   Zip archive.
 * @param int        $index Entry index.
 * @return bool Whether the entry is a symlink.
 */
function is_release_zip_symlink_entry(ZipArchive $zip, int $index): bool
{
    if (!method_exists($zip, 'getExternalAttributesIndex')) {
        return false;
    }

    $opsys = 0;
    $attr = 0;

    if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
        return false;
    }

    return ZipArchive::OPSYS_UNIX === $opsys && 0120000 === (($attr >> 16) & 0170000);
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
