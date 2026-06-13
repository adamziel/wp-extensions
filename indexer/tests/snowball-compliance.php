<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/**
 * @return array<string,array{code:string|null,name:string,variant?:bool}>
 */
function wp_fts_snowball_datasets(): array
{
    $wamaniaDiverges = 'wamania/php-stemmer exposes this language, but its implementation does not match current official Snowball data';

    return [
        'arabic' => ['code' => 'ar', 'name' => 'Arabic'],
        'armenian' => ['code' => 'hy', 'name' => 'Armenian'],
        'basque' => ['code' => 'eu', 'name' => 'Basque'],
        'catalan' => ['code' => 'ca', 'name' => 'Catalan'],
        'czech' => ['code' => 'cs', 'name' => 'Czech'],
        'danish' => ['code' => 'da', 'name' => 'Danish', 'skip_reason' => $wamaniaDiverges],
        'dutch' => [
            'code' => 'nl',
            'name' => 'Dutch',
            'variant' => true,
            'skip_reason' => 'WP_FTS_SnowballStemmer maps nl to Wamania Dutch Porter; the newer official Dutch algorithm is not implemented',
        ],
        'dutch_porter' => ['code' => 'nl', 'name' => 'Dutch Porter'],
        'english' => ['code' => 'en', 'name' => 'English'],
        'esperanto' => ['code' => 'eo', 'name' => 'Esperanto'],
        'estonian' => ['code' => 'et', 'name' => 'Estonian'],
        'finnish' => ['code' => 'fi', 'name' => 'Finnish', 'skip_reason' => $wamaniaDiverges],
        'french' => ['code' => 'fr', 'name' => 'French'],
        'german' => ['code' => 'de', 'name' => 'German', 'skip_reason' => $wamaniaDiverges],
        'greek' => ['code' => 'el', 'name' => 'Greek'],
        'hindi' => ['code' => 'hi', 'name' => 'Hindi'],
        'hungarian' => ['code' => 'hu', 'name' => 'Hungarian'],
        'indonesian' => ['code' => 'id', 'name' => 'Indonesian'],
        'irish' => ['code' => 'ga', 'name' => 'Irish'],
        'italian' => ['code' => 'it', 'name' => 'Italian', 'skip_reason' => $wamaniaDiverges],
        'lithuanian' => ['code' => 'lt', 'name' => 'Lithuanian'],
        'lovins' => ['code' => 'en', 'name' => 'Lovins English', 'variant' => true],
        'nepali' => ['code' => 'ne', 'name' => 'Nepali'],
        'norwegian' => ['code' => 'no', 'name' => 'Norwegian', 'skip_reason' => $wamaniaDiverges],
        'persian' => ['code' => 'fa', 'name' => 'Persian'],
        'polish' => ['code' => 'pl', 'name' => 'Polish'],
        'porter' => ['code' => 'en', 'name' => 'Porter English', 'variant' => true],
        'portuguese' => ['code' => 'pt', 'name' => 'Portuguese', 'skip_reason' => $wamaniaDiverges],
        'romanian' => ['code' => 'ro', 'name' => 'Romanian', 'skip_reason' => $wamaniaDiverges],
        'russian' => ['code' => 'ru', 'name' => 'Russian', 'skip_reason' => $wamaniaDiverges],
        'serbian' => ['code' => 'sr', 'name' => 'Serbian'],
        'sesotho' => ['code' => 'st', 'name' => 'Sesotho'],
        'spanish' => ['code' => 'es', 'name' => 'Spanish'],
        'swedish' => ['code' => 'sv', 'name' => 'Swedish', 'skip_reason' => $wamaniaDiverges],
        'tamil' => ['code' => 'ta', 'name' => 'Tamil'],
        'turkish' => ['code' => 'tr', 'name' => 'Turkish'],
        'yiddish' => ['code' => 'yi', 'name' => 'Yiddish'],
    ];
}

/**
 * @return string[]
 */
function wp_fts_discover_snowball_dirs(string $dataDir): array
{
    $entries = @scandir($dataDir);
    if ($entries === false) {
        throw new RuntimeException("Unable to read SNOWBALL_DATA_DIR: {$dataDir}");
    }

    $dirs = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
            continue;
        }

        if (is_dir($dataDir . DIRECTORY_SEPARATOR . $entry)) {
            $dirs[] = $entry;
        }
    }

    sort($dirs, SORT_STRING);

    return $dirs;
}

/**
 * @return string[]
 */
function wp_fts_read_lines(string $path): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException("Unable to read {$path}");
    }

    return array_map(static fn(string $line): string => rtrim($line, "\r"), $lines);
}

function wp_fts_display_value(string $value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function wp_fts_language_label(string $dataset, array $metadata): string
{
    $code = $metadata['code'] ?? null;
    $suffix = $code === null ? '' : " ({$code})";

    return "{$metadata['name']} [{$dataset}]{$suffix}";
}

$dataDir = getenv('SNOWBALL_DATA_DIR');
$dataDir = $dataDir === false || trim($dataDir) === '' ? '/home/claude/.cache/snowball-data' : $dataDir;
$dataDir = rtrim($dataDir, DIRECTORY_SEPARATOR);

$stemmer = new WP_FTS_SnowballStemmer();
$knownDatasets = wp_fts_snowball_datasets();
$discoveredDirs = wp_fts_discover_snowball_dirs($dataDir);
$discovered = array_fill_keys($discoveredDirs, true);
$datasets = [];

foreach ($knownDatasets as $dataset => $metadata) {
    if (isset($discovered[$dataset])) {
        $datasets[$dataset] = $metadata;
    }
}

foreach ($discoveredDirs as $dataset) {
    if (isset($knownDatasets[$dataset]) || $dataset === 'scripts') {
        continue;
    }

    $dir = $dataDir . DIRECTORY_SEPARATOR . $dataset;
    if (is_file($dir . DIRECTORY_SEPARATOR . 'voc.txt') || is_file($dir . DIRECTORY_SEPARATOR . 'output.txt')) {
        $datasets[$dataset] = ['code' => null, 'name' => $dataset];
    }
}

ksort($datasets, SORT_STRING);

$results = [
    'pass' => [],
    'skip' => [],
    'fail' => [],
];

fwrite(STDOUT, "Snowball data: {$dataDir}\n");
fwrite(STDOUT, 'Wamania available: ' . ($stemmer->is_available() ? 'yes' : 'no') . "\n\n");
fwrite(
    STDOUT,
    'Dependency status: ' . (
        $stemmer->is_available()
            ? 'wamania/php-stemmer available; Wamania-backed supported-language comparisons will run'
            : 'wamania/php-stemmer missing; Wamania-backed supported-language comparisons will be reported as SKIP'
    ) . "\n"
);
fwrite(STDOUT, 'English source: ' . $stemmer->source_identity('en') . "\n");
fwrite(STDOUT, 'Spanish source: ' . $stemmer->source_identity('es') . "\n");
fwrite(STDOUT, 'French source: ' . $stemmer->source_identity('fr') . "\n\n");

foreach ($datasets as $dataset => $metadata) {
    $label = wp_fts_language_label($dataset, $metadata);
    $code = $metadata['code'];
    $dir = $dataDir . DIRECTORY_SEPARATOR . $dataset;
    $vocPath = $dir . DIRECTORY_SEPARATOR . 'voc.txt';
    $outputPath = $dir . DIRECTORY_SEPARATOR . 'output.txt';

    if (($metadata['variant'] ?? false) === true) {
        $reason = $metadata['skip_reason'] ?? 'algorithm variant is not part of WP_FTS_SnowballStemmer language support';
        $results['skip'][] = $label;
        fwrite(STDOUT, "[SKIP] {$label}: {$reason}\n");
        continue;
    }

    if ($code === null || !$stemmer->supports_language($code)) {
        $reason = $metadata['skip_reason'] ?? 'language is not supported by WP_FTS_SnowballStemmer';
        $results['skip'][] = $label;
        fwrite(STDOUT, "[SKIP] {$label}: {$reason}\n");
        continue;
    }

    if (!$stemmer->is_language_available($code)) {
        $reason = 'language runtime is unavailable; Wamania-backed languages require composer install';
        $results['skip'][] = $label;
        fwrite(STDOUT, "[SKIP] {$label}: {$reason}\n");
        continue;
    }

    if (!is_file($vocPath) || !is_file($outputPath)) {
        $reason = 'supported language is missing voc.txt or output.txt';
        $results['fail'][] = $label;
        fwrite(STDOUT, "[FAIL] {$label}: {$reason}\n");
        continue;
    }

    $inputs = wp_fts_read_lines($vocPath);
    $expected = wp_fts_read_lines($outputPath);
    $lineCount = count($inputs);
    $expectedCount = count($expected);

    if ($lineCount !== $expectedCount) {
        $results['fail'][] = $label;
        fwrite(
            STDOUT,
            "[FAIL] {$label}: voc.txt has {$lineCount} lines; output.txt has {$expectedCount} lines\n"
        );
        continue;
    }

    $mismatches = 0;
    $firstMismatch = null;
    foreach ($inputs as $index => $input) {
        $actual = $stemmer->stem($input, $code);
        if ($actual === $expected[$index]) {
            continue;
        }

        $mismatches++;
        if ($firstMismatch === null) {
            $firstMismatch = [
                'line' => $index + 1,
                'input' => $input,
                'expected' => $expected[$index],
                'actual' => $actual,
            ];
        }
    }

    if ($mismatches === 0) {
        $results['pass'][] = $label;
        fwrite(STDOUT, "[PASS] {$label}: {$lineCount} line pairs matched\n");
        continue;
    }

    $results['fail'][] = $label;
    fwrite(
        STDOUT,
        sprintf(
            '[FAIL] %s: %d mismatches across %d line pairs; first mismatch line %d input %s expected %s actual %s',
            $label,
            $mismatches,
            $lineCount,
            $firstMismatch['line'],
            wp_fts_display_value($firstMismatch['input']),
            wp_fts_display_value($firstMismatch['expected']),
            wp_fts_display_value($firstMismatch['actual'])
        ) . "\n"
    );
}

fwrite(STDOUT, "\nSummary: " . count($results['pass']) . ' pass, ' . count($results['skip']) . ' skip, ' . count($results['fail']) . " fail\n");
foreach (['pass' => 'PASS', 'skip' => 'SKIP', 'fail' => 'FAIL'] as $key => $heading) {
    $languages = $results[$key] === [] ? '(none)' : implode(', ', $results[$key]);
    fwrite(STDOUT, "{$heading} languages: {$languages}\n");
}

exit($results['fail'] === [] ? 0 : 1);
