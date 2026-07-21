<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function wp_fts_english_fixture_fail(string $message): void
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function wp_fts_english_fixture_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        wp_fts_english_fixture_fail(
            $message . '; expected ' . json_encode($expected) . ', got ' . json_encode($actual)
        );
    }
}

function wp_fts_english_fixture_true(bool $condition, string $message): void
{
    if (!$condition) {
        wp_fts_english_fixture_fail($message);
    }
}

$stemmer = new WP_FTS_SnowballStemmer();
wp_fts_english_fixture_true(
    str_contains($stemmer->source_identity('en'), 'Snowball English (Porter2)'),
    'English source identity should name the Snowball/Porter2 variant'
);

$rows = [
    ['line' => 20, 'surface' => 'abandoned', 'stem' => 'abandon', 'case' => 'past tense'],
    ['line' => 21, 'surface' => 'abandoning', 'stem' => 'abandon', 'case' => '-ing'],
    ['line' => 835, 'surface' => 'agreed', 'stem' => 'agre', 'case' => 'R1 eed handling'],
    ['line' => 5778, 'surface' => 'cats', 'stem' => 'cat', 'case' => 'plural s'],
    ['line' => 5569, 'surface' => 'caresses', 'stem' => 'caress', 'case' => 'sses plural'],
    ['line' => 28367, 'surface' => 'ponies', 'stem' => 'poni', 'case' => 'ies plural'],
    ['line' => 18341, 'surface' => 'hopping', 'stem' => 'hop', 'case' => 'double consonant'],
    ['line' => 34163, 'surface' => 'skies', 'stem' => 'sky', 'case' => 'exception'],
    ['line' => 25049, 'surface' => 'news', 'stem' => 'news', 'case' => 'protected token'],
    ['line' => 10, 'surface' => "'s", 'stem' => "'s", 'case' => 'short apostrophe token'],
    ['line' => 11, 'surface' => "'s'", 'stem' => 's', 'case' => 'apostrophe cleanup'],
    ['line' => 2035, 'surface' => 'as', 'stem' => 'as', 'case' => 'protected short token'],
    ['line' => 40277, 'surface' => 'us', 'stem' => 'us', 'case' => 'protected short token'],
    ['line' => 19762, 'surface' => 'inning', 'stem' => 'inning', 'case' => 'exception2 no-op'],
    ['line' => 29113, 'surface' => 'proceed', 'stem' => 'proceed', 'case' => 'exception2 no-op'],
    ['line' => 16101, 'surface' => 'generously', 'stem' => 'generous', 'case' => 'ly suffix'],
    ['line' => 11964, 'surface' => 'early', 'stem' => 'earli', 'case' => 'ly exception'],
];

foreach ($rows as $row) {
    wp_fts_english_fixture_same(
        $row['stem'],
        $stemmer->stem($row['surface'], 'en'),
        "Snowball English row {$row['line']} {$row['case']}"
    );
}

$pipeline = new WP_FTS_LanguagePipeline();
wp_fts_english_fixture_same(
    ['color', 'organiz', 'organiz'],
    $pipeline->analyze('colour organise organising', 'en-GB'),
    'English dialect normalization should run before Snowball stemming'
);
wp_fts_english_fixture_same(
    ['reilli', 'run'],
    $pipeline->analyze("O'Reilly's running", 'en'),
    'ASCII apostrophe tokenization should keep analyzer/query normalization deterministic'
);

$analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
$documentTerms = array_column(
    $analyzer->analyze_content('<p>Running cats and ponies were hopping.</p>', ['document_lang' => 'en']),
    'term'
);
$queryTerms = $analyzer->analyze_query('run cat pony hop', ['query_lang' => 'en']);

wp_fts_english_fixture_same(
    [],
    array_values(array_diff($queryTerms, $documentTerms)),
    'English document and query analysis should produce the same stems'
);

fwrite(STDOUT, '[PASS] English Snowball fixtures: ' . count($rows) . " direct rows plus document/query parity\n");
