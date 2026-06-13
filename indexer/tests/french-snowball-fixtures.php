<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

function wp_fts_french_fixture_fail(string $message): void
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function wp_fts_french_fixture_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        wp_fts_french_fixture_fail(
            $message . '; expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE)
        );
    }
}

function wp_fts_french_fixture_true(bool $condition, string $message): void
{
    if (!$condition) {
        wp_fts_french_fixture_fail($message);
    }
}

/**
 * @return string[]
 */
function wp_fts_french_fixture_lines(string $path): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        wp_fts_french_fixture_fail("Unable to read {$path}");
    }

    return array_map(static fn(string $line): string => rtrim($line, "\r"), $lines);
}

$dataDir = getenv('SNOWBALL_DATA_DIR');
$dataDir = $dataDir === false || trim($dataDir) === '' ? '/home/claude/.cache/snowball-data' : $dataDir;
$dataDir = rtrim($dataDir, DIRECTORY_SEPARATOR);
$voc = wp_fts_french_fixture_lines($dataDir . '/french/voc.txt');
$expected = wp_fts_french_fixture_lines($dataDir . '/french/output.txt');

wp_fts_french_fixture_same(21653, count($voc), 'French voc.txt fixture line count');
wp_fts_french_fixture_same(count($voc), count($expected), 'French fixture input/output line counts should match');

$stemmer = new WP_FTS_SnowballStemmer();
wp_fts_french_fixture_true($stemmer->supports_language('fr-FR'), 'French locale should be advertised');
wp_fts_french_fixture_true($stemmer->is_language_available('fr'), 'French should not require optional Wamania classes');
wp_fts_french_fixture_true(
    str_contains($stemmer->source_identity('fr'), 'Snowball French'),
    'French source identity should name the Snowball variant'
);

$rows = [
    ['line' => 3, 'surface' => 'abaissait', 'stem' => 'abaiss', 'case' => 'imperfect verb suffix'],
    ['line' => 6, 'surface' => 'abaissement', 'stem' => 'abaissement', 'case' => 'protected R2 boundary'],
    ['line' => 8, 'surface' => 'abaisser', 'stem' => 'abaiss', 'case' => 'infinitive'],
    ['line' => 16, 'surface' => 'abandonner', 'stem' => 'abandon', 'case' => 'verb suffix'],
    ['line' => 45, 'surface' => 'abominablement', 'stem' => 'abomin', 'case' => 'adverb suffix'],
    ['line' => 3414, 'surface' => 'cherchait', 'stem' => 'cherch', 'case' => 'imperfect verb'],
    ['line' => 3418, 'surface' => 'chercher', 'stem' => 'cherch', 'case' => 'infinitive'],
    ['line' => 3635, 'surface' => 'claires', 'stem' => 'clair', 'case' => 'adjective plural'],
    ['line' => 7174, 'surface' => 'enfants', 'stem' => 'enfant', 'case' => 'plural noun'],
    ['line' => 12046, 'surface' => 'mangeaient', 'stem' => 'mang', 'case' => 'imperfect plural'],
    ['line' => 12052, 'surface' => 'manger', 'stem' => 'mang', 'case' => 'infinitive'],
    ['line' => 16114, 'surface' => 'rapidement', 'stem' => 'rapid', 'case' => 'adverb suffix'],
    ['line' => 16251, 'surface' => 'recherche', 'stem' => 'recherch', 'case' => 'final e suffix'],
    ['line' => 20180, 'surface' => 'utile', 'stem' => 'util', 'case' => 'final e suffix'],
    ['line' => 21040, 'surface' => 'école', 'stem' => 'écol', 'case' => 'accented word'],
    ['line' => 21653, 'surface' => 'ôtées', 'stem' => 'ôté', 'case' => 'final fixture row'],
];

foreach ($rows as $row) {
    $index = $row['line'] - 1;
    wp_fts_french_fixture_same($row['surface'], $voc[$index] ?? null, "French official input row {$row['line']}");
    wp_fts_french_fixture_same($row['stem'], $expected[$index] ?? null, "French official output row {$row['line']}");
}

foreach ($voc as $index => $surface) {
    wp_fts_french_fixture_same(
        $expected[$index],
        $stemmer->stem($surface, 'fr'),
        'Snowball French full fixture row ' . ($index + 1)
    );
}

$pipeline = new WP_FTS_LanguagePipeline();
wp_fts_french_fixture_same(
    ['mang', 'mang', 'donne', 'clair', 'rapid'],
    $pipeline->analyze('manger mangeaient donnees claires rapidement', 'fr-FR'),
    'French normalization should run before Snowball stemming'
);

$analyzer = new WP_FTS_Analyzer(['default_lang' => 'fr']);
$storage = new WP_FTS_Storage_InMemory();
$indexer = new WP_FTS_Indexer($storage, $analyzer);
$indexer->index_document(960, '<p>Les enfants mangeaient des donnees claires rapidement.</p>', ['lang' => 'fr']);
$searcher = new WP_FTS_Searcher($storage, $analyzer);

wp_fts_french_fixture_same(
    [960],
    array_column($searcher->search('manger donnee claire rapide', ['lang' => 'fr', 'mode' => 'AND']), 'doc_id'),
    'French query and document inflections should meet through the same stems'
);

fwrite(STDOUT, '[PASS] French Snowball fixtures: ' . count($voc) . " official line pairs plus analyzer/search parity\n");
