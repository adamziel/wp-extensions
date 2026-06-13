<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/snowball-fixture-stream.php';

final class WP_FTS_HindiFixtureFailure extends RuntimeException
{
}

function wp_fts_hindi_fixture_fail(string $message): void
{
    throw new WP_FTS_HindiFixtureFailure($message);
}

function wp_fts_hindi_fixture_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        wp_fts_hindi_fixture_fail(
            $message . '; expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE)
        );
    }
}

function wp_fts_hindi_fixture_true(bool $condition, string $message): void
{
    if (!$condition) {
        wp_fts_hindi_fixture_fail($message);
    }
}

try {
    $dataDir = getenv('SNOWBALL_DATA_DIR');
    $dataDir = $dataDir === false || trim($dataDir) === '' ? '/home/claude/.cache/snowball-data' : $dataDir;
    $dataDir = rtrim($dataDir, DIRECTORY_SEPARATOR);
    $hindiDir = $dataDir . DIRECTORY_SEPARATOR . 'hindi';
    $vocPath = wp_fts_snowball_fixture_file($hindiDir, 'voc.txt');
    $outputPath = wp_fts_snowball_fixture_file($hindiDir, 'output.txt');

    wp_fts_hindi_fixture_true($vocPath !== null, 'Hindi voc.txt or voc.txt.gz fixture should exist');
    wp_fts_hindi_fixture_true($outputPath !== null, 'Hindi output.txt or output.txt.gz fixture should exist');

    $stemmer = new WP_FTS_SnowballStemmer();
    wp_fts_hindi_fixture_true($stemmer->supports_language('hi-IN'), 'Hindi locale should be advertised');
    wp_fts_hindi_fixture_true($stemmer->is_language_available('hi'), 'Hindi should not require optional Wamania classes');
    wp_fts_hindi_fixture_true(
        str_contains($stemmer->source_identity('hi'), 'Snowball Hindi'),
        'Hindi source identity should name the Snowball variant'
    );

    $rows = [
        1 => ['surface' => 'ं', 'stem' => 'ं', 'case' => 'initial combining mark row'],
        10 => ['surface' => 'अँगूठा', 'stem' => 'अँगूठ', 'case' => 'final vowel suffix'],
        100 => ['surface' => 'अंग्रेज़ी', 'stem' => 'अंग्रेज़', 'case' => 'adjectival ending'],
        1000 => ['surface' => 'अधिनियमों', 'stem' => 'अधिनियम', 'case' => 'oblique plural suffix'],
        5000 => ['surface' => 'इंडिया', 'stem' => 'इंडिय', 'case' => 'final aa deletion'],
        10000 => ['surface' => 'कब्रें', 'stem' => 'कब्र', 'case' => 'plural nasal suffix'],
        20000 => ['surface' => 'जलपान', 'stem' => 'जलपान', 'case' => 'unchanged compound noun'],
        30000 => ['surface' => 'नैणी', 'stem' => 'नैण', 'case' => 'final vowel suffix'],
        40000 => ['surface' => 'बैरिस्टर', 'stem' => 'बैरिस्टर', 'case' => 'unchanged loanword'],
        50000 => ['surface' => 'लीचिंग', 'stem' => 'लीचिंग', 'case' => 'unchanged gerund loanword'],
        60000 => ['surface' => 'सेवानिवृत्त', 'stem' => 'सेवानिवृत्त', 'case' => 'unchanged compound adjective'],
        65118 => ['surface' => '९९९', 'stem' => '९९९', 'case' => 'final fixture row'],
    ];
    $seenRows = [];

    $lineCount = wp_fts_snowball_fixture_for_each_pair(
        $vocPath,
        $outputPath,
        static function (int $line, string $surface, string $expected) use ($stemmer, $rows, &$seenRows): void {
            if (isset($rows[$line])) {
                wp_fts_hindi_fixture_same($rows[$line]['surface'], $surface, "Hindi official input row {$line}");
                wp_fts_hindi_fixture_same($rows[$line]['stem'], $expected, "Hindi official output row {$line}");
                $seenRows[$line] = true;
            }

            $actual = $stemmer->stem($surface, 'hi');
            wp_fts_hindi_fixture_same($expected, $actual, "Snowball Hindi full fixture row {$line}");
        }
    );

    wp_fts_hindi_fixture_same(65118, $lineCount, 'Hindi fixture line count');
    foreach (array_keys($rows) as $line) {
        wp_fts_hindi_fixture_true(isset($seenRows[$line]), "Hindi requested fixture row {$line} should be checked");
    }

    $pipeline = new WP_FTS_LanguagePipeline();
    wp_fts_hindi_fixture_same(
        ['अधिनियम', 'इंडिय', 'कब्र', 'हिंद', 'खोज', 'कर'],
        $pipeline->analyze('अधिनियमों इंडिया कब्रें हिंदी खोजता करना', 'hi-IN'),
        'Hindi normalization should run before Snowball stemming'
    );

    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'hi']);
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $indexer->index_document(973, '<p>अधिनियमों इंडिया कब्रें हिंदी खोजता करना।</p>', ['lang' => 'hi']);
    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    wp_fts_hindi_fixture_same(
        [973],
        array_column($searcher->search('अधिनियम इंडिय कब्र हिंद खोज कर', ['lang' => 'hi', 'mode' => 'AND']), 'doc_id'),
        'Hindi query and document inflections should meet through the same Snowball stems'
    );

    fwrite(STDOUT, '[PASS] Hindi Snowball fixtures: ' . $lineCount . " official line pairs plus analyzer/search parity\n");
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Hindi Snowball fixtures: ' . $e->getMessage() . "\n");
    exit(1);
}
