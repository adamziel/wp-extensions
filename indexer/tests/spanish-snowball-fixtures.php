<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

function wp_fts_spanish_fixture_fail(string $message): void
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function wp_fts_spanish_fixture_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        wp_fts_spanish_fixture_fail(
            $message . '; expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE)
        );
    }
}

function wp_fts_spanish_fixture_true(bool $condition, string $message): void
{
    if (!$condition) {
        wp_fts_spanish_fixture_fail($message);
    }
}

/**
 * @return string[]
 */
function wp_fts_spanish_fixture_lines(string $path): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        wp_fts_spanish_fixture_fail("Unable to read {$path}");
    }

    return array_map(static fn(string $line): string => rtrim($line, "\r"), $lines);
}

$dataDir = getenv('SNOWBALL_DATA_DIR');
if (!is_string($dataDir) || trim($dataDir) === '') {
    fwrite(STDERR, "Set SNOWBALL_DATA_DIR to a local checkout of the official Snowball test data.\n");
    exit(2);
}
$dataDir = rtrim(trim($dataDir), DIRECTORY_SEPARATOR);
$voc = wp_fts_spanish_fixture_lines($dataDir . '/spanish/voc.txt');
$expected = wp_fts_spanish_fixture_lines($dataDir . '/spanish/output.txt');

wp_fts_spanish_fixture_same(28378, count($voc), 'Spanish voc.txt fixture line count');
wp_fts_spanish_fixture_same(count($voc), count($expected), 'Spanish fixture input/output line counts should match');

$stemmer = new WP_FTS_SnowballStemmer();
wp_fts_spanish_fixture_true($stemmer->supports_language('es-MX'), 'Spanish locale should be advertised');
wp_fts_spanish_fixture_true($stemmer->is_language_available('es'), 'Spanish should not require optional Wamania classes');
wp_fts_spanish_fixture_true(
    str_contains($stemmer->source_identity('es'), 'Snowball Spanish'),
    'Spanish source identity should name the Snowball variant'
);

$rows = [
    ['line' => 2, 'surface' => 'aarón', 'stem' => 'aaron', 'case' => 'accent postlude'],
    ['line' => 14, 'surface' => 'abandonarlo', 'stem' => 'abandon', 'case' => 'attached pronoun'],
    ['line' => 17, 'surface' => 'abandonó', 'stem' => 'abandon', 'case' => 'accented preterite'],
    ['line' => 456, 'surface' => 'activamente', 'stem' => 'activ', 'case' => 'adverb suffix'],
    ['line' => 3878, 'surface' => 'buscando', 'stem' => 'busc', 'case' => 'gerund'],
    ['line' => 3879, 'surface' => 'buscar', 'stem' => 'busc', 'case' => 'infinitive'],
    ['line' => 5248, 'surface' => 'claros', 'stem' => 'clar', 'case' => 'adjective plural'],
    ['line' => 7632, 'surface' => 'datos', 'stem' => 'dat', 'case' => 'plural noun'],
    ['line' => 16089, 'surface' => 'leyendo', 'stem' => 'leyend', 'case' => 'yendo residual'],
    ['line' => 24021, 'surface' => 'rápidamente', 'stem' => 'rapid', 'case' => 'accented adverb'],
    ['line' => 28378, 'surface' => 'útil', 'stem' => 'util', 'case' => 'final fixture row'],
];

foreach ($rows as $row) {
    $index = $row['line'] - 1;
    wp_fts_spanish_fixture_same($row['surface'], $voc[$index] ?? null, "Spanish official input row {$row['line']}");
    wp_fts_spanish_fixture_same($row['stem'], $expected[$index] ?? null, "Spanish official output row {$row['line']}");
    wp_fts_spanish_fixture_same(
        $row['stem'],
        $stemmer->stem($row['surface'], 'es'),
        "Snowball Spanish row {$row['line']} {$row['case']}"
    );
}

$pipeline = new WP_FTS_LanguagePipeline();
wp_fts_spanish_fixture_same(
    ['busc', 'busc', 'dat', 'clar', 'rapid'],
    $pipeline->analyze('buscar buscando datos claros rápidamente', 'es-MX'),
    'Spanish normalization should run before Snowball stemming'
);

$analyzer = new WP_FTS_Analyzer(['default_lang' => 'es']);
$storage = new WP_FTS_Storage_InMemory();
$indexer = new WP_FTS_Indexer($storage, $analyzer);
$indexer->index_document(956, '<p>Estamos buscando datos claros rapidamente.</p>', ['lang' => 'es']);
$searcher = new WP_FTS_Searcher($storage, $analyzer);

wp_fts_spanish_fixture_same(
    [956],
    array_column($searcher->search('buscar datos claros rapidamente', ['lang' => 'es', 'mode' => 'AND']), 'doc_id'),
    'Spanish query and document inflections should meet through the same stems'
);

fwrite(STDOUT, '[PASS] Spanish Snowball fixtures: ' . count($rows) . " direct rows plus analyzer/search parity\n");
