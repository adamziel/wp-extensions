#!/usr/bin/env php
<?php
declare(strict_types=1);

$options = getopt('', ['help']);

if (isset($options['help'])) {
    echo "Usage: php tools/verify-wordpress-org-readme.php\n";
    echo "\n";
    echo "Checks that language-fts-playground/readme.txt has the required\n";
    echo "WordPress.org-style metadata and remains aligned with the plugin header.\n";
    exit(0);
}

try {
    $plugin_root = dirname(__DIR__);
    $main_file = read_required_file($plugin_root . '/language-fts-playground.php');
    $readme = read_required_file($plugin_root . '/readme.txt');

    verify_wordpress_org_readme($readme, $main_file);

    echo "WordPress.org readme metadata passed.\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'WordPress.org readme metadata failed: ' . $error->getMessage() . "\n");
    exit(1);
}

/**
 * Verifies readme metadata against the plugin header and release scope.
 *
 * @param string $readme    WordPress.org readme contents.
 * @param string $main_file Main plugin file contents.
 */
function verify_wordpress_org_readme(string $readme, string $main_file): void
{
    if (!preg_match('/^===\s*Language FTS Playground\s*===\s*$/m', $readme)) {
        throw new RuntimeException('readme.txt must start with the plugin title.');
    }

    $headers = parse_wordpress_org_readme_headers($readme);
    $version = match_required('/^\s*\*\s*Version:\s*([^\r\n]+)/mi', $main_file, 'plugin header Version');
    $constant_version = match_required("/define\\(\\s*'LANGUAGE_FTS_PLAYGROUND_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $main_file, 'LANGUAGE_FTS_PLAYGROUND_VERSION constant');
    $requires_at_least = match_required('/^\s*\*\s*Requires at least:\s*([^\r\n]+)/mi', $main_file, 'plugin header Requires at least');
    $requires_php = match_required('/^\s*\*\s*Requires PHP:\s*([^\r\n]+)/mi', $main_file, 'plugin header Requires PHP');
    $license = match_required('/^\s*\*\s*License:\s*([^\r\n]+)/mi', $main_file, 'plugin header License');
    $license_uri = match_required('/^\s*\*\s*License URI:\s*([^\r\n]+)/mi', $main_file, 'plugin header License URI');

    $required_headers = [
        'contributors',
        'tags',
        'requires at least',
        'tested up to',
        'stable tag',
        'requires php',
        'license',
        'license uri',
    ];

    foreach ($required_headers as $header) {
        if (!isset($headers[$header]) || '' === $headers[$header]) {
            throw new RuntimeException('readme.txt is missing required header: ' . $header);
        }
    }

    if ($version !== $constant_version) {
        throw new RuntimeException('Plugin header Version and version constant do not match.');
    }

    assert_same_header($version, $headers['stable tag'], 'Stable tag');
    assert_same_header($requires_at_least, $headers['requires at least'], 'Requires at least');
    assert_same_header($requires_php, $headers['requires php'], 'Requires PHP');
    assert_same_header($license, $headers['license'], 'License');
    assert_same_header($license_uri, $headers['license uri'], 'License URI');

    foreach (['Description', 'Installation', 'Frequently Asked Questions', 'Screenshots', 'Changelog'] as $section) {
        if (!preg_match('/^==\s*' . preg_quote($section, '/') . '\s*==\s*$/m', $readme)) {
            throw new RuntimeException('readme.txt is missing required section: ' . $section);
        }
    }

    assert_wordpress_org_short_description($readme, 'readme.txt');

    foreach (['demo/seed-pack', 'direct ZIP', 'not a WordPress.org/plugin-directory release', 'WordPress.org submission'] as $phrase) {
        if (false === strpos($readme, $phrase)) {
            throw new RuntimeException('readme.txt is missing required scope wording: ' . $phrase);
        }
    }

    if (preg_match('/\b(available|listed|published)\s+on\s+WordPress\.org\b/i', $readme)) {
        throw new RuntimeException('readme.txt must not claim current WordPress.org availability.');
    }
}

/**
 * Verifies the one-line WordPress.org short description before Description.
 *
 * @param string $readme Readme contents.
 * @param string $label  Diagnostic label.
 * @return string Short description.
 */
function assert_wordpress_org_short_description(string $readme, string $label): string
{
    $lines = preg_split("/\r\n|\n|\r/", $readme);

    if (!is_array($lines)) {
        throw new RuntimeException('Unable to split ' . $label . ' into lines.');
    }

    $description_lines = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if (preg_match('/^==\s+/', $trimmed)) {
            break;
        }

        if (
            '' === $trimmed ||
            preg_match('/^===\s*.+\s*===$/', $trimmed) ||
            preg_match('/^[^:]+:\s*.+$/', $trimmed)
        ) {
            continue;
        }

        $description_lines[] = $trimmed;
    }

    if (1 !== count($description_lines)) {
        throw new RuntimeException($label . ' must contain exactly one short description line before the first section.');
    }

    $short_description = $description_lines[0];
    $length = strlen($short_description);

    if (150 < $length) {
        throw new RuntimeException($label . ' short description must be no more than 150 characters; found ' . $length . '.');
    }

    if (preg_match('/[<>\[\]`*_]/', $short_description)) {
        throw new RuntimeException($label . ' short description must not contain markup characters.');
    }

    return $short_description;
}

/**
 * Parses top-level WordPress.org readme headers before the first section.
 *
 * @param string $readme Readme contents.
 * @return array<string,string>
 */
function parse_wordpress_org_readme_headers(string $readme): array
{
    $headers = [];
    $lines = preg_split("/\r\n|\n|\r/", $readme);

    if (!is_array($lines)) {
        throw new RuntimeException('Unable to split readme.txt into lines.');
    }

    foreach ($lines as $line) {
        if (preg_match('/^==\s+/', $line)) {
            break;
        }

        if (preg_match('/^([^:]+):\s*(.+)$/', $line, $matches)) {
            $headers[strtolower(trim($matches[1]))] = trim($matches[2]);
        }
    }

    return $headers;
}

/**
 * Verifies an exact metadata value match.
 *
 * @param string $expected Expected value.
 * @param string $actual   Actual value.
 * @param string $label    Diagnostic label.
 */
function assert_same_header(string $expected, string $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' mismatch: expected ' . $expected . ', found ' . $actual . '.');
    }
}

/**
 * Reads a required file.
 *
 * @param string $path File path.
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
