<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/snowball-fixture-stream.php';

final class WP_FTS_ArabicFixtureFailure extends RuntimeException
{
}

function wp_fts_arabic_fixture_fail(string $message): void
{
    throw new WP_FTS_ArabicFixtureFailure($message);
}

function wp_fts_arabic_fixture_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        wp_fts_arabic_fixture_fail(
            $message . '; expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE)
        );
    }
}

function wp_fts_arabic_fixture_true(bool $condition, string $message): void
{
    if (!$condition) {
        wp_fts_arabic_fixture_fail($message);
    }
}

try {
    $dataDir = getenv('SNOWBALL_DATA_DIR');
    $dataDir = $dataDir === false || trim($dataDir) === '' ? '/home/claude/.cache/snowball-data' : $dataDir;
    $dataDir = rtrim($dataDir, DIRECTORY_SEPARATOR);
    $arabicDir = $dataDir . DIRECTORY_SEPARATOR . 'arabic';
    $vocPath = wp_fts_snowball_fixture_file($arabicDir, 'voc.txt');
    $outputPath = wp_fts_snowball_fixture_file($arabicDir, 'output.txt');

    wp_fts_arabic_fixture_true($vocPath !== null, 'Arabic voc.txt or voc.txt.gz fixture should exist');
    wp_fts_arabic_fixture_true($outputPath !== null, 'Arabic output.txt or output.txt.gz fixture should exist');

    $stemmer = new WP_FTS_SnowballStemmer();
    wp_fts_arabic_fixture_true($stemmer->supports_language('ar-EG'), 'Arabic locale should be advertised');
    wp_fts_arabic_fixture_true($stemmer->is_language_available('ar'), 'Arabic should not require optional Wamania classes');
    wp_fts_arabic_fixture_true(
        str_contains($stemmer->source_identity('ar'), 'Snowball Arabic'),
        'Arabic source identity should name the Snowball variant'
    );

    $rows = [
        1 => ['surface' => 'ءامن', 'stem' => 'ءام', 'case' => 'hamza normalization'],
        10 => ['surface' => 'أآبا', 'stem' => 'ااب', 'case' => 'alef normalization'],
        100 => ['surface' => 'أأباحتاهم', 'stem' => 'اباح', 'case' => 'verb and pronoun suffix'],
        1000 => ['surface' => 'أابتزوا', 'stem' => 'اتز', 'case' => 'verb suffix'],
        10000 => ['surface' => 'أأجالاها', 'stem' => 'اجال', 'case' => 'suffix removal'],
        100000 => ['surface' => 'أأوقفتموهما', 'stem' => 'اقف', 'case' => 'compound suffix removal'],
        1000000 => ['surface' => 'بصنوكم', 'stem' => 'بصن', 'case' => 'possessive suffix'],
        9196214 => ['surface' => 'ييئسن', 'stem' => 'يييس', 'case' => 'final fixture row'],
    ];
    $seenRows = [];

    $lineCount = wp_fts_snowball_fixture_for_each_pair(
        $vocPath,
        $outputPath,
        static function (int $line, string $surface, string $expected) use ($stemmer, $rows, &$seenRows): void {
            if (isset($rows[$line])) {
                wp_fts_arabic_fixture_same($rows[$line]['surface'], $surface, "Arabic official input row {$line}");
                wp_fts_arabic_fixture_same($rows[$line]['stem'], $expected, "Arabic official output row {$line}");
                $seenRows[$line] = true;
            }

            $actual = $stemmer->stem($surface, 'ar');
            wp_fts_arabic_fixture_same($expected, $actual, "Snowball Arabic full fixture row {$line}");
        }
    );

    wp_fts_arabic_fixture_same(9196214, $lineCount, 'Arabic compressed fixture line count');
    foreach (array_keys($rows) as $line) {
        wp_fts_arabic_fixture_true(isset($seenRows[$line]), "Arabic requested fixture row {$line} should be checked");
    }

    $pipeline = new WP_FTS_LanguagePipeline();
    wp_fts_arabic_fixture_same(
        ['اباح', 'مفيد', 'بحث', 'اقف', 'بصن', 'يييس'],
        $pipeline->analyze('أأباحتاهم مفيدة للبحث أأوقفتموهما بصنوكم ييئسن', 'ar-EG'),
        'Arabic normalization should run before Snowball stemming'
    );

    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'ar']);
    $storage = new WP_FTS_Storage_InMemory();
    $indexer = new WP_FTS_Indexer($storage, $analyzer);
    $indexer->index_document(969, '<p>أأباحتاهم مفيدة للبحث أأوقفتموهما بصنوكم ييئسن.</p>', ['lang' => 'ar']);
    $searcher = new WP_FTS_Searcher($storage, $analyzer);

    wp_fts_arabic_fixture_same(
        [969],
        array_column($searcher->search('اباح اقف بصن يييس بحث', ['lang' => 'ar', 'mode' => 'AND']), 'doc_id'),
        'Arabic query and document inflections should meet through the same Snowball stems'
    );

    fwrite(STDOUT, '[PASS] Arabic Snowball fixtures: ' . $lineCount . " official compressed line pairs plus analyzer/search parity\n");
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Arabic Snowball fixtures: ' . $e->getMessage() . "\n");
    exit(1);
}
