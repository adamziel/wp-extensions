<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

function wp_fts_portuguese_fixture_fail(string $message): void
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function wp_fts_portuguese_fixture_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        wp_fts_portuguese_fixture_fail(
            $message . '; expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE)
        );
    }
}

function wp_fts_portuguese_fixture_true(bool $condition, string $message): void
{
    if (!$condition) {
        wp_fts_portuguese_fixture_fail($message);
    }
}

/**
 * @return string[]
 */
function wp_fts_portuguese_fixture_lines(string $path): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        wp_fts_portuguese_fixture_fail("Unable to read {$path}");
    }

    return array_map(static fn(string $line): string => rtrim($line, "\r"), $lines);
}

$dataDir = getenv('SNOWBALL_DATA_DIR');
$dataDir = $dataDir === false || trim($dataDir) === '' ? '/home/claude/.cache/snowball-data' : $dataDir;
$dataDir = rtrim($dataDir, DIRECTORY_SEPARATOR);
$voc = wp_fts_portuguese_fixture_lines($dataDir . '/portuguese/voc.txt');
$expected = wp_fts_portuguese_fixture_lines($dataDir . '/portuguese/output.txt');

wp_fts_portuguese_fixture_same(32016, count($voc), 'Portuguese voc.txt fixture line count');
wp_fts_portuguese_fixture_same(count($voc), count($expected), 'Portuguese fixture input/output line counts should match');

$stemmer = new WP_FTS_SnowballStemmer();
wp_fts_portuguese_fixture_true($stemmer->supports_language('pt-BR'), 'Portuguese locale should be advertised');
wp_fts_portuguese_fixture_true($stemmer->is_language_available('pt'), 'Portuguese should not require optional Wamania classes');
wp_fts_portuguese_fixture_true(
    str_contains($stemmer->source_identity('pt'), 'Snowball Portuguese'),
    'Portuguese source identity should name the Snowball variant'
);

$rows = [
    ['line' => 5, 'surface' => 'aacho', 'stem' => 'aach', 'case' => 'early fixture suffix'],
    ['line' => 31, 'surface' => 'abandonar', 'stem' => 'abandon', 'case' => 'infinitive'],
    ['line' => 33, 'surface' => 'abandonaram', 'stem' => 'abandon', 'case' => 'preterite plural'],
    ['line' => 253, 'surface' => 'ação', 'stem' => 'açã', 'case' => 'nasal postlude singular'],
    ['line' => 381, 'surface' => 'ações', 'stem' => 'açõ', 'case' => 'nasal postlude plural'],
    ['line' => 6109, 'surface' => 'claros', 'stem' => 'clar', 'case' => 'adjective plural'],
    ['line' => 8464, 'surface' => 'dados', 'stem' => 'dad', 'case' => 'plural noun'],
    ['line' => 23464, 'surface' => 'pesquisa', 'stem' => 'pesquis', 'case' => 'noun form'],
    ['line' => 23472, 'surface' => 'pesquisar', 'stem' => 'pesquis', 'case' => 'infinitive'],
    ['line' => 25513, 'surface' => 'rapidamente', 'stem' => 'rapid', 'case' => 'adverb suffix'],
    ['line' => 30831, 'surface' => 'úteis', 'stem' => 'úte', 'case' => 'accented plural adjective'],
    ['line' => 32016, 'surface' => 'zumbido', 'stem' => 'zumb', 'case' => 'final fixture row'],
];

foreach ($rows as $row) {
    $index = $row['line'] - 1;
    wp_fts_portuguese_fixture_same($row['surface'], $voc[$index] ?? null, "Portuguese official input row {$row['line']}");
    wp_fts_portuguese_fixture_same($row['stem'], $expected[$index] ?? null, "Portuguese official output row {$row['line']}");
}

foreach ($voc as $index => $surface) {
    wp_fts_portuguese_fixture_same(
        $expected[$index],
        $stemmer->stem($surface, 'pt'),
        'Snowball Portuguese full fixture row ' . ($index + 1)
    );
}

$pipeline = new WP_FTS_LanguagePipeline();
wp_fts_portuguese_fixture_same(
    ['pesquis', 'pesquis', 'dad', 'clar', 'rapid', 'aco', 'aca', 'util'],
    $pipeline->analyze('pesquisar pesquisando dados claros rapidamente ações ação útil', 'pt-BR'),
    'Portuguese normalization should run before Snowball stemming'
);

$analyzer = new WP_FTS_Analyzer(['default_lang' => 'pt']);
$storage = new WP_FTS_Storage_InMemory();
$indexer = new WP_FTS_Indexer($storage, $analyzer);
$indexer->index_document(963, '<p>Estamos pesquisando dados claros rapidamente.</p>', ['lang' => 'pt']);
$searcher = new WP_FTS_Searcher($storage, $analyzer);

wp_fts_portuguese_fixture_same(
    [963],
    array_column($searcher->search('pesquisar dado claro rapidamente', ['lang' => 'pt', 'mode' => 'AND']), 'doc_id'),
    'Portuguese query and document inflections should meet through the same stems'
);

fwrite(STDOUT, '[PASS] Portuguese Snowball fixtures: ' . count($voc) . " official line pairs plus analyzer/search parity\n");
