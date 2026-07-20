<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function wp_fts_indonesian_fixture_fail(string $message): void
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function wp_fts_indonesian_fixture_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        wp_fts_indonesian_fixture_fail(
            $message . '; expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE)
        );
    }
}

function wp_fts_indonesian_fixture_true(bool $condition, string $message): void
{
    if (!$condition) {
        wp_fts_indonesian_fixture_fail($message);
    }
}

/**
 * @return string[]
 */
function wp_fts_indonesian_fixture_lines(string $path): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        wp_fts_indonesian_fixture_fail("Unable to read {$path}");
    }

    return array_map(static fn(string $line): string => rtrim($line, "\r"), $lines);
}

$dataDir = getenv('SNOWBALL_DATA_DIR');
if (!is_string($dataDir) || trim($dataDir) === '') {
    fwrite(STDERR, "Set SNOWBALL_DATA_DIR to a local checkout of the official Snowball test data.\n");
    exit(2);
}
$dataDir = rtrim(trim($dataDir), DIRECTORY_SEPARATOR);
$voc = wp_fts_indonesian_fixture_lines($dataDir . '/indonesian/voc.txt');
$expected = wp_fts_indonesian_fixture_lines($dataDir . '/indonesian/output.txt');

wp_fts_indonesian_fixture_same(64586, count($voc), 'Indonesian voc.txt fixture line count');
wp_fts_indonesian_fixture_same(count($voc), count($expected), 'Indonesian fixture input/output line counts should match');

$stemmer = new WP_FTS_SnowballStemmer();
wp_fts_indonesian_fixture_true(
    str_contains($stemmer->source_identity('id'), 'Snowball Indonesian'),
    'Indonesian source identity should name the Snowball variant'
);

$rows = [
    ['line' => 3578, 'surface' => 'bahasa', 'stem' => 'bahasa', 'case' => 'unchanged noun'],
    ['line' => 5463, 'surface' => 'berjalan', 'stem' => 'jalan', 'case' => 'ber- prefix'],
    ['line' => 10862, 'surface' => 'datanya', 'stem' => 'data', 'case' => 'possessive pronoun'],
    ['line' => 11302, 'surface' => 'dengan', 'stem' => 'dengan', 'case' => 'unchanged preposition'],
    ['line' => 23289, 'surface' => 'indonesia', 'stem' => 'indonesia', 'case' => 'unchanged country name'],
    ['line' => 32592, 'surface' => 'makanan', 'stem' => 'makan', 'case' => '-an suffix'],
    ['line' => 35411, 'surface' => 'mencari', 'stem' => 'cari', 'case' => 'meN- prefix'],
    ['line' => 44777, 'surface' => 'pencarian', 'stem' => 'cari', 'case' => 'peN- prefix plus -an suffix'],
    ['line' => 46931, 'surface' => 'perjalanan', 'stem' => 'jalan', 'case' => 'per- form plus -an suffix'],
];

foreach ($rows as $row) {
    $index = $row['line'] - 1;
    wp_fts_indonesian_fixture_same($row['surface'], $voc[$index] ?? null, "Indonesian official input row {$row['line']}");
    wp_fts_indonesian_fixture_same($row['stem'], $expected[$index] ?? null, "Indonesian official output row {$row['line']}");
    wp_fts_indonesian_fixture_same(
        $row['stem'],
        $stemmer->stem($row['surface'], 'id'),
        "Snowball Indonesian row {$row['line']} {$row['case']}"
    );
}

foreach ($voc as $index => $surface) {
    wp_fts_indonesian_fixture_same(
        $expected[$index],
        $stemmer->stem($surface, 'id'),
        'Snowball Indonesian full fixture row ' . ($index + 1)
    );
}

$pipeline = new WP_FTS_LanguagePipeline();
wp_fts_indonesian_fixture_same(
    ['cari', 'cari', 'jalan', 'jalan', 'makan', 'bahasa', 'indonesia', 'dengan'],
    $pipeline->analyze('mencari pencarian berjalan perjalanan makanan bahasa indonesia dengan', 'id-ID'),
    'Indonesian normalization should run before Snowball stemming'
);

$analyzer = new WP_FTS_Analyzer(['default_lang' => 'id']);
$documentTerms = array_column(
    $analyzer->analyze_content(
        '<p>Kami sedang mencari data pencarian dan berjalan cepat.</p>',
        ['document_lang' => 'id']
    ),
    'term'
);
$queryTerms = $analyzer->analyze_query('cari data jalan', ['query_lang' => 'id']);

wp_fts_indonesian_fixture_same(
    [],
    array_values(array_diff($queryTerms, $documentTerms)),
    'Indonesian document and query analysis should produce the same stems'
);

fwrite(STDOUT, '[PASS] Indonesian Snowball fixtures: ' . count($voc) . " official line pairs plus document/query parity\n");
