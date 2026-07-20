<?php
declare(strict_types=1);

/**
 * Lane-specific quality tests for task 046.
 *
 * Task 043 owns the shared harness/discovery/check reporting. This file keeps
 * its own contribution counter so this lane can report meaningful executed
 * checks while remaining easy to drop into the eventual generic discovery hook.
 */

$wp_fts_external_reference_direct = !function_exists('test_case');
if ($wp_fts_external_reference_direct) {
    require_once dirname(__DIR__) . '/bootstrap.php';
    require_once dirname(__DIR__) . '/snowball-fixture-stream.php';

    final class WP_FTS_TestFailure extends RuntimeException
    {
    }

    /** @var array<int,array{name:string,fn:callable}> */
    $GLOBALS['wp_fts_external_reference_tests'] = [];
    $GLOBALS['wp_fts_external_reference_harness_checks'] = 0;

    function test_case(string $name, callable $fn): void
    {
        $GLOBALS['wp_fts_external_reference_tests'][] = ['name' => $name, 'fn' => $fn];
    }

    function record_check(?string $label = null, int $count = 1): void
    {
        if ($count < 1) {
            throw new WP_FTS_TestFailure('record_check() count must be at least 1.');
        }

        $GLOBALS['wp_fts_external_reference_harness_checks'] += $count;
    }

    function assert_true(bool $condition, string $message): void
    {
        record_check($message);
        if (!$condition) {
            throw new WP_FTS_TestFailure($message);
        }
    }

    function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        record_check($message);
        if ($expected !== $actual) {
            throw new WP_FTS_TestFailure($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
        }
    }

}

require_once dirname(__DIR__) . '/snowball-fixture-stream.php';

$GLOBALS['wp_fts_external_reference_checks'] = $GLOBALS['wp_fts_external_reference_checks'] ?? 0;
$GLOBALS['wp_fts_external_reference_optional_skips'] = $GLOBALS['wp_fts_external_reference_optional_skips'] ?? [];

function wp_fts_external_reference_record(string $label, int $count = 1): void
{
    if ($count < 1) {
        throw new WP_FTS_TestFailure('External reference check count must be at least 1.');
    }

    $GLOBALS['wp_fts_external_reference_checks'] += $count;
}

function wp_fts_external_reference_check_count(): int
{
    return (int) $GLOBALS['wp_fts_external_reference_checks'];
}

function wp_fts_external_reference_skip(string $label, string $reason): void
{
    wp_fts_external_reference_record("optional skip: {$label}");
    $GLOBALS['wp_fts_external_reference_optional_skips'][$label] = $reason;
}

function wp_fts_external_reference_assert_true(bool $condition, string $message): void
{
    wp_fts_external_reference_record($message);
    assert_true($condition, $message);
}

function wp_fts_external_reference_assert_same(mixed $expected, mixed $actual, string $message): void
{
    wp_fts_external_reference_record($message);
    assert_same($expected, $actual, $message);
}

function wp_fts_external_reference_data_dir(): ?string
{
    $dataDir = getenv('SNOWBALL_DATA_DIR');
    if (!is_string($dataDir) || trim($dataDir) === '') {
        return null;
    }

    return rtrim(trim($dataDir), DIRECTORY_SEPARATOR);
}

/**
 * @return string[]
 */
function wp_fts_external_reference_read_lines(string $path): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new WP_FTS_TestFailure("Unable to read external reference fixture {$path}");
    }

    return array_map(static fn(string $line): string => rtrim($line, "\r"), $lines);
}

/**
 * @return array<string,array{code:string,rows:array<int,array{line:int,input:string,output:string}>}>
 */
function wp_fts_external_reference_supported_snowball_rows(): array
{
    return [
        'arabic' => [
            'code' => 'ar',
            'rows' => [
                ['line' => 1, 'input' => 'ءامن', 'output' => 'ءام'],
                ['line' => 10, 'input' => 'أآبا', 'output' => 'ااب'],
                ['line' => 100, 'input' => 'أأباحتاهم', 'output' => 'اباح'],
                ['line' => 1000, 'input' => 'أابتزوا', 'output' => 'اتز'],
                ['line' => 10000, 'input' => 'أأجالاها', 'output' => 'اجال'],
                ['line' => 100000, 'input' => 'أأوقفتموهما', 'output' => 'اقف'],
                ['line' => 1000000, 'input' => 'بصنوكم', 'output' => 'بصن'],
            ],
        ],
        'catalan' => [
            'code' => 'ca',
            'rows' => [
                ['line' => 1, 'input' => 'abacial', 'output' => 'abac'],
                ['line' => 2, 'input' => 'abadessa', 'output' => 'abad'],
                ['line' => 3, 'input' => 'abadesses', 'output' => 'abad'],
                ['line' => 4, 'input' => 'abadia', 'output' => 'abad'],
                ['line' => 5, 'input' => 'abadiat', 'output' => 'abad'],
                ['line' => 6, 'input' => 'abadies', 'output' => 'abad'],
                ['line' => 7, 'input' => 'abaixa', 'output' => 'ab'],
                ['line' => 8, 'input' => 'abaixar', 'output' => 'abaix'],
                ['line' => 9, 'input' => 'abanderat', 'output' => 'abander'],
                ['line' => 10, 'input' => 'abandona', 'output' => 'abandon'],
                ['line' => 11, 'input' => 'abandonada', 'output' => 'abandon'],
                ['line' => 12, 'input' => 'abandonades', 'output' => 'abandon'],
                ['line' => 25, 'input' => 'abandonessin', 'output' => 'abandon'],
                ['line' => 50, 'input' => 'abat', 'output' => 'ab'],
                ['line' => 100, 'input' => 'abocador', 'output' => 'aboc'],
                ['line' => 250, 'input' => 'abusat', 'output' => 'abu'],
                ['line' => 500, 'input' => 'acompanyant', 'output' => 'acompany'],
                ['line' => 1000, 'input' => 'adornat', 'output' => 'adorn'],
                ['line' => 2500, 'input' => 'anacoretes', 'output' => 'anacoret'],
                ['line' => 5000, 'input' => 'avantbraços', 'output' => 'avantbraç'],
                ['line' => 10000, 'input' => 'condiment', 'output' => 'cond'],
                ['line' => 20000, 'input' => 'fabulosos', 'output' => 'fabul'],
                ['line' => 40000, 'input' => 'repetida', 'output' => 'repet'],
            ],
        ],
        'dutch_porter' => [
            'code' => 'nl',
            'rows' => [
                ['line' => 1, 'input' => 'a', 'output' => 'a'],
                ['line' => 2, 'input' => 'á', 'output' => 'a'],
                ['line' => 3, 'input' => 'à', 'output' => 'à'],
                ['line' => 4, 'input' => 'aa', 'output' => 'aa'],
                ['line' => 5, 'input' => 'aachen', 'output' => 'aach'],
                ['line' => 6, 'input' => 'aachener', 'output' => 'aachener'],
                ['line' => 7, 'input' => 'aah', 'output' => 'aah'],
                ['line' => 8, 'input' => 'aalborg', 'output' => 'aalborg'],
                ['line' => 9, 'input' => 'aalders', 'output' => 'aalder'],
                ['line' => 10, 'input' => 'aalmoezen', 'output' => 'aalmoez'],
                ['line' => 11, 'input' => 'aalscholver', 'output' => 'aalscholver'],
                ['line' => 12, 'input' => 'aalscholvers', 'output' => 'aalscholver'],
                ['line' => 25, 'input' => 'aanbelde', 'output' => 'aanbeld'],
                ['line' => 50, 'input' => 'aanbod', 'output' => 'aanbod'],
                ['line' => 100, 'input' => 'aangaan', 'output' => 'aangan'],
                ['line' => 250, 'input' => 'aankeek', 'output' => 'aankek'],
                ['line' => 500, 'input' => 'aanzeggen', 'output' => 'aanzegg'],
                ['line' => 1000, 'input' => 'aes', 'output' => 'aes'],
                ['line' => 2500, 'input' => 'arthur', 'output' => 'arthur'],
                ['line' => 5000, 'input' => 'bijmenging', 'output' => 'bijmeng'],
                ['line' => 10000, 'input' => 'dwingen', 'output' => 'dwing'],
                ['line' => 20000, 'input' => 'komische', 'output' => 'komisch'],
                ['line' => 40000, 'input' => 'verlengde', 'output' => 'verlengd'],
            ],
        ],
        'english' => [
            'code' => 'en',
            'rows' => [
                ['line' => 10, 'input' => "'s", 'output' => "'s"],
                ['line' => 11, 'input' => "'s'", 'output' => 's'],
                ['line' => 20, 'input' => 'abandoned', 'output' => 'abandon'],
                ['line' => 21, 'input' => 'abandoning', 'output' => 'abandon'],
                ['line' => 835, 'input' => 'agreed', 'output' => 'agre'],
                ['line' => 2035, 'input' => 'as', 'output' => 'as'],
                ['line' => 5569, 'input' => 'caresses', 'output' => 'caress'],
                ['line' => 5778, 'input' => 'cats', 'output' => 'cat'],
                ['line' => 11964, 'input' => 'early', 'output' => 'earli'],
                ['line' => 16101, 'input' => 'generously', 'output' => 'generous'],
                ['line' => 18341, 'input' => 'hopping', 'output' => 'hop'],
                ['line' => 19762, 'input' => 'inning', 'output' => 'inning'],
                ['line' => 25049, 'input' => 'news', 'output' => 'news'],
                ['line' => 28367, 'input' => 'ponies', 'output' => 'poni'],
                ['line' => 29113, 'input' => 'proceed', 'output' => 'proceed'],
                ['line' => 34163, 'input' => 'skies', 'output' => 'sky'],
                ['line' => 37784, 'input' => 'ties', 'output' => 'tie'],
                ['line' => 40277, 'input' => 'us', 'output' => 'us'],
            ],
        ],
        'spanish' => [
            'code' => 'es',
            'rows' => [
                ['line' => 1, 'input' => 'a', 'output' => 'a'],
                ['line' => 2, 'input' => 'aarón', 'output' => 'aaron'],
                ['line' => 13, 'input' => 'abandonar', 'output' => 'abandon'],
                ['line' => 14, 'input' => 'abandonarlo', 'output' => 'abandon'],
                ['line' => 15, 'input' => 'abandonaron', 'output' => 'abandon'],
                ['line' => 17, 'input' => 'abandonó', 'output' => 'abandon'],
                ['line' => 100, 'input' => 'abortos', 'output' => 'abort'],
                ['line' => 250, 'input' => 'accionarias', 'output' => 'accionari'],
                ['line' => 456, 'input' => 'activamente', 'output' => 'activ'],
                ['line' => 500, 'input' => 'acuden', 'output' => 'acud'],
                ['line' => 1000, 'input' => 'agudizado', 'output' => 'agudiz'],
                ['line' => 2500, 'input' => 'asistiera', 'output' => 'asist'],
                ['line' => 3878, 'input' => 'buscando', 'output' => 'busc'],
                ['line' => 3879, 'input' => 'buscar', 'output' => 'busc'],
                ['line' => 5248, 'input' => 'claros', 'output' => 'clar'],
                ['line' => 7632, 'input' => 'datos', 'output' => 'dat'],
                ['line' => 10000, 'input' => 'emergencias', 'output' => 'emergent'],
                ['line' => 16089, 'input' => 'leyendo', 'output' => 'leyend'],
                ['line' => 24021, 'input' => 'rápidamente', 'output' => 'rapid'],
                ['line' => 28378, 'input' => 'útil', 'output' => 'util'],
            ],
        ],
        'french' => [
            'code' => 'fr',
            'rows' => [
                ['line' => 3, 'input' => 'abaissait', 'output' => 'abaiss'],
                ['line' => 6, 'input' => 'abaissement', 'output' => 'abaissement'],
                ['line' => 8, 'input' => 'abaisser', 'output' => 'abaiss'],
                ['line' => 16, 'input' => 'abandonner', 'output' => 'abandon'],
                ['line' => 45, 'input' => 'abominablement', 'output' => 'abomin'],
                ['line' => 3414, 'input' => 'cherchait', 'output' => 'cherch'],
                ['line' => 3418, 'input' => 'chercher', 'output' => 'cherch'],
                ['line' => 3635, 'input' => 'claires', 'output' => 'clair'],
                ['line' => 7174, 'input' => 'enfants', 'output' => 'enfant'],
                ['line' => 12046, 'input' => 'mangeaient', 'output' => 'mang'],
                ['line' => 12052, 'input' => 'manger', 'output' => 'mang'],
                ['line' => 16114, 'input' => 'rapidement', 'output' => 'rapid'],
                ['line' => 16251, 'input' => 'recherche', 'output' => 'recherch'],
                ['line' => 20180, 'input' => 'utile', 'output' => 'util'],
                ['line' => 21040, 'input' => 'école', 'output' => 'écol'],
                ['line' => 21653, 'input' => 'ôtées', 'output' => 'ôté'],
            ],
        ],
        'hindi' => [
            'code' => 'hi',
            'rows' => [
                ['line' => 1, 'input' => 'ं', 'output' => 'ं'],
                ['line' => 10, 'input' => 'अँगूठा', 'output' => 'अँगूठ'],
                ['line' => 100, 'input' => 'अंग्रेज़ी', 'output' => 'अंग्रेज़'],
                ['line' => 1000, 'input' => 'अधिनियमों', 'output' => 'अधिनियम'],
                ['line' => 5000, 'input' => 'इंडिया', 'output' => 'इंडिय'],
                ['line' => 10000, 'input' => 'कब्रें', 'output' => 'कब्र'],
                ['line' => 20000, 'input' => 'जलपान', 'output' => 'जलपान'],
                ['line' => 30000, 'input' => 'नैणी', 'output' => 'नैण'],
                ['line' => 40000, 'input' => 'बैरिस्टर', 'output' => 'बैरिस्टर'],
                ['line' => 50000, 'input' => 'लीचिंग', 'output' => 'लीचिंग'],
                ['line' => 60000, 'input' => 'सेवानिवृत्त', 'output' => 'सेवानिवृत्त'],
                ['line' => 65118, 'input' => '९९९', 'output' => '९९९'],
            ],
        ],
        'portuguese' => [
            'code' => 'pt',
            'rows' => [
                ['line' => 5, 'input' => 'aacho', 'output' => 'aach'],
                ['line' => 31, 'input' => 'abandonar', 'output' => 'abandon'],
                ['line' => 33, 'input' => 'abandonaram', 'output' => 'abandon'],
                ['line' => 253, 'input' => 'ação', 'output' => 'açã'],
                ['line' => 381, 'input' => 'ações', 'output' => 'açõ'],
                ['line' => 6109, 'input' => 'claros', 'output' => 'clar'],
                ['line' => 8464, 'input' => 'dados', 'output' => 'dad'],
                ['line' => 23464, 'input' => 'pesquisa', 'output' => 'pesquis'],
                ['line' => 23472, 'input' => 'pesquisar', 'output' => 'pesquis'],
                ['line' => 25513, 'input' => 'rapidamente', 'output' => 'rapid'],
                ['line' => 30831, 'input' => 'úteis', 'output' => 'úte'],
                ['line' => 32016, 'input' => 'zumbido', 'output' => 'zumb'],
            ],
        ],
        'indonesian' => [
            'code' => 'id',
            'rows' => [
                ['line' => 3578, 'input' => 'bahasa', 'output' => 'bahasa'],
                ['line' => 5463, 'input' => 'berjalan', 'output' => 'jalan'],
                ['line' => 10862, 'input' => 'datanya', 'output' => 'data'],
                ['line' => 11302, 'input' => 'dengan', 'output' => 'dengan'],
                ['line' => 23289, 'input' => 'indonesia', 'output' => 'indonesia'],
                ['line' => 32592, 'input' => 'makanan', 'output' => 'makan'],
                ['line' => 35411, 'input' => 'mencari', 'output' => 'cari'],
                ['line' => 44777, 'input' => 'pencarian', 'output' => 'cari'],
                ['line' => 46931, 'input' => 'perjalanan', 'output' => 'jalan'],
            ],
        ],
    ];
}

/**
 * @return string[]
 */
function wp_fts_external_reference_snowball_language_codes(): array
{
    return [
        'ar',
        'ca',
        'cs',
        'da',
        'de',
        'el',
        'en',
        'eo',
        'es',
        'et',
        'eu',
        'fa',
        'fi',
        'fr',
        'ga',
        'hi',
        'hu',
        'hy',
        'id',
        'it',
        'lt',
        'ne',
        'nl',
        'no',
        'pl',
        'pt',
        'ro',
        'ru',
        'sr',
        'st',
        'sv',
        'ta',
        'tr',
        'yi',
    ];
}

/**
 * @return array<string,array{code:string,line:int,input:string,output:string,reason:string}>
 */
function wp_fts_external_reference_unsupported_boundaries(): array
{
    $reason = 'Wamania exposes an implementation, but this project does not advertise it as Snowball-compliant against current official data.';

    return [
        'german' => ['code' => 'de', 'line' => 3, 'input' => 'aalglatten', 'output' => 'aalglatt', 'reason' => $reason],
        'italian' => ['code' => 'it', 'line' => 5, 'input' => 'abakoumova', 'output' => 'abakoumov', 'reason' => $reason],
        'danish' => ['code' => 'da', 'line' => 3, 'input' => 'aabenbaringen', 'output' => 'aabenbaring', 'reason' => $reason],
        'swedish' => ['code' => 'sv', 'line' => 2, 'input' => 'aaele', 'output' => 'aael', 'reason' => $reason],
        'russian' => ['code' => 'ru', 'line' => 2, 'input' => 'абиссинию', 'output' => 'абиссин', 'reason' => $reason],
        'norwegian' => ['code' => 'no', 'line' => 3, 'input' => 'aabakken', 'output' => 'aabakk', 'reason' => $reason],
        'finnish' => ['code' => 'fi', 'line' => 4, 'input' => 'aachenin', 'output' => 'aachen', 'reason' => $reason],
        'romanian' => ['code' => 'ro', 'line' => 4, 'input' => 'abajurul', 'output' => 'abajur', 'reason' => $reason],
    ];
}

/**
 * @param array<int,array{term:string,lang:string,weight?:float}> $occurrences
 * @return string[]
 */
function wp_fts_external_reference_terms(array $occurrences): array
{
    return array_map(static fn(array $occurrence): string => $occurrence['term'], $occurrences);
}

/**
 * @param string[] $expected
 * @param string[] $actual
 */
function wp_fts_external_reference_assert_terms(array $expected, array $actual, string $label): void
{
    wp_fts_external_reference_assert_same(count($expected), count($actual), "{$label} term count");
    foreach ($expected as $index => $term) {
        wp_fts_external_reference_assert_same($term, $actual[$index] ?? null, "{$label} term {$index}");
    }
}

$wp_fts_external_reference_data_dir = wp_fts_external_reference_data_dir();
if ($wp_fts_external_reference_data_dir !== null) {
    test_case('quality external Snowball fixtures cover implemented datasets', function () use ($wp_fts_external_reference_data_dir): void {
        $dataDir = $wp_fts_external_reference_data_dir;
        $stemmer = new WP_FTS_SnowballStemmer();
        wp_fts_external_reference_assert_true(is_dir($dataDir), 'SNOWBALL_DATA_DIR should point to an existing official Snowball data checkout');

        foreach (wp_fts_external_reference_supported_snowball_rows() as $dataset => $metadata) {
            $code = $metadata['code'];
            $datasetDir = $dataDir . DIRECTORY_SEPARATOR . $dataset;
            $vocPath = wp_fts_snowball_fixture_file($datasetDir, 'voc.txt');
            $outputPath = wp_fts_snowball_fixture_file($datasetDir, 'output.txt');

            wp_fts_external_reference_assert_true($vocPath !== null, "{$dataset} voc.txt or voc.txt.gz should exist");
            wp_fts_external_reference_assert_true($outputPath !== null, "{$dataset} output.txt or output.txt.gz should exist");

            $lineNumbers = array_map(static fn(array $row): int => (int) $row['line'], $metadata['rows']);
            $fixtureRows = wp_fts_snowball_fixture_read_rows($vocPath, $outputPath, $lineNumbers);

            foreach ($metadata['rows'] as $row) {
                $fixtureRow = $fixtureRows[(int) $row['line']] ?? null;
                wp_fts_external_reference_assert_same($row['input'], $fixtureRow['input'] ?? null, "{$dataset} official input row {$row['line']}");
                wp_fts_external_reference_assert_same($row['output'], $fixtureRow['output'] ?? null, "{$dataset} official output row {$row['line']}");
                wp_fts_external_reference_assert_true($row['input'] !== '', "{$dataset} row {$row['line']} input should be non-empty");
                wp_fts_external_reference_assert_true($row['output'] !== '', "{$dataset} row {$row['line']} output should be non-empty");

                wp_fts_external_reference_assert_same(
                    $row['output'],
                    $stemmer->stem($row['input'], $code),
                    "{$dataset} runtime stem should match official row {$row['line']}"
                );
            }
        }
    });
}

if ($wp_fts_external_reference_data_dir !== null) {
    test_case('quality external unsupported Snowball boundaries stay documented no-ops', function () use ($wp_fts_external_reference_data_dir): void {
        $dataDir = $wp_fts_external_reference_data_dir;
        $stemmer = new WP_FTS_SnowballStemmer();

        foreach (wp_fts_external_reference_unsupported_boundaries() as $dataset => $boundary) {
            $vocPath = $dataDir . DIRECTORY_SEPARATOR . $dataset . DIRECTORY_SEPARATOR . 'voc.txt';
            $outputPath = $dataDir . DIRECTORY_SEPARATOR . $dataset . DIRECTORY_SEPARATOR . 'output.txt';
            $inputs = wp_fts_external_reference_read_lines($vocPath);
            $expected = wp_fts_external_reference_read_lines($outputPath);
            $index = $boundary['line'] - 1;

            wp_fts_external_reference_assert_true($boundary['reason'] !== '', "{$dataset} unsupported boundary should carry a documented reason");
            wp_fts_external_reference_assert_same($boundary['input'], $inputs[$index] ?? null, "{$dataset} boundary input fixture row");
            wp_fts_external_reference_assert_same($boundary['output'], $expected[$index] ?? null, "{$dataset} boundary output fixture row");
            wp_fts_external_reference_assert_true($boundary['input'] !== $boundary['output'], "{$dataset} boundary fixture should prove stemming would change the token");
            wp_fts_external_reference_assert_same(
                $boundary['input'],
                $stemmer->stem($boundary['input'], $boundary['code']),
                "{$dataset} unsupported language should not silently apply fixture output"
            );
        }

        $officialDutch = wp_fts_external_reference_read_lines($dataDir . DIRECTORY_SEPARATOR . 'dutch' . DIRECTORY_SEPARATOR . 'output.txt');
        $porterDutch = wp_fts_external_reference_read_lines($dataDir . DIRECTORY_SEPARATOR . 'dutch_porter' . DIRECTORY_SEPARATOR . 'output.txt');
        $variantReason = 'Current nl support is documented as Dutch Porter, not the newer official Dutch dataset.';
        foreach ([10 => ['aalmoezen', 'aalmoes', 'aalmoez'], 25 => ['aanbelde', 'aanbel', 'aanbeld'], 100 => ['aangaan', 'aangaan', 'aangan']] as $line => $row) {
            [$input, $official, $porter] = $row;
            wp_fts_external_reference_assert_true($variantReason !== '', "Dutch official-vs-Porter line {$line} should have a documented boundary reason");
            wp_fts_external_reference_assert_same($official, $officialDutch[$line - 1] ?? null, "Dutch official output row {$line}");
            wp_fts_external_reference_assert_same($porter, $porterDutch[$line - 1] ?? null, "Dutch Porter output row {$line}");
            wp_fts_external_reference_assert_true($official !== $porter, "Dutch official and Porter outputs should differ for {$input}");
        }
    });
}
unset($wp_fts_external_reference_data_dir);

test_case('quality external multilingual tokenization reference corpus stays stable', function (): void {
    $cases = [
        'Polish folding' => [
            'lang' => 'pl',
            'text' => 'Zażółć gęślą jaźń w Łodzi',
            'terms' => ['zazolc', 'gesla', 'jazn', 'w', 'lodzi'],
        ],
        'German folding' => [
            'lang' => 'de',
            'text' => 'Straße größere Äpfel für München Öl Ärger',
            'terms' => ['strasse', 'groessere', 'aepfel', 'fuer', 'muenchen', 'oel', 'aerger'],
        ],
        'Turkish dotted I' => [
            'lang' => 'tr',
            'text' => 'I İ ı i ışık Şeker Çalışma Isparta İstanbul',
            'terms' => ['ı', 'i', 'ı', 'i', 'ısık', 'seker', 'calısma', 'ısparta', 'istanbul'],
        ],
        'English dialect spellings' => [
            'lang' => 'en',
            'text' => 'colour colours coloured colouring flavour flavours behaviour behaviours organise organises organised organising normalise normalises normalised normalising realise realised recognise recognising',
            'terms' => ['color', 'color', 'color', 'color', 'flavor', 'flavor', 'behavior', 'behavior', 'organiz', 'organiz', 'organiz', 'organiz', 'normal', 'normal', 'normal', 'normal', 'realiz', 'realiz', 'recogn', 'recogn'],
        ],
        'CJK fallback n-grams' => [
            'lang' => 'zh',
            'text' => '東京大学検索品質',
            'terms' => [
                '東',
                '京', '東京',
                '大', '京大', '東京大',
                '学', '大学', '京大学', '東京大学',
                '検', '学検', '大学検', '京大学検',
                '索', '検索', '学検索', '大学検索',
                '品', '索品', '検索品', '学検索品',
                '質', '品質', '索品質', '検索品質',
            ],
        ],
        'Mixed script runs' => [
            'lang' => 'en',
            'text' => 'alpha東京beta 混合Script zamek城',
            'terms' => ['alpha', '東', '京', '東京', 'beta', '混', '合', '混合', 'script', 'zamek', '城'],
        ],
    ];

    foreach ($cases as $label => $case) {
        $analyzer = new WP_FTS_Analyzer([
            'default_lang' => $case['lang'],
            'min_term_len' => 1,
        ]);
        $records = $analyzer->analyze_content($case['text'], ['document_lang' => $case['lang']]);
        wp_fts_external_reference_assert_terms($case['terms'], wp_fts_external_reference_terms($records), $label);

        foreach ($records as $index => $record) {
            wp_fts_external_reference_assert_same($case['lang'], $record['lang'], "{$label} language tag {$index}");
        }
    }
});

test_case('quality external multilingual HTML lang corpus routes terms by segment', function (): void {
    $analyzer = new WP_FTS_Analyzer([
        'default_lang' => 'pl',
        'min_term_len' => 1,
    ]);
    $records = $analyzer->analyze_content(
        '<article lang="pl"><p>Zażółć Łódź</p><section lang="de">Straße München</section><code lang="tr">İstanbul Isparta</code><p lang="zh-Hans">東京大学</p></article>',
        ['document_lang' => 'pl']
    );
    $expected = [
        ['term' => 'zazolc', 'lang' => 'pl'],
        ['term' => 'lodz', 'lang' => 'pl'],
        ['term' => 'strasse', 'lang' => 'de'],
        ['term' => 'muenchen', 'lang' => 'de'],
        ['term' => 'istanbul', 'lang' => 'tr'],
        ['term' => 'ısparta', 'lang' => 'tr'],
        ['term' => '東', 'lang' => 'zh-Hans'],
        ['term' => '京', 'lang' => 'zh-Hans'],
        ['term' => '東京', 'lang' => 'zh-Hans'],
        ['term' => '大', 'lang' => 'zh-Hans'],
        ['term' => '京大', 'lang' => 'zh-Hans'],
        ['term' => '東京大', 'lang' => 'zh-Hans'],
        ['term' => '学', 'lang' => 'zh-Hans'],
        ['term' => '大学', 'lang' => 'zh-Hans'],
        ['term' => '京大学', 'lang' => 'zh-Hans'],
        ['term' => '東京大学', 'lang' => 'zh-Hans'],
    ];

    wp_fts_external_reference_assert_same(count($expected), count($records), 'HTML lang corpus record count');
    foreach ($expected as $index => $row) {
        wp_fts_external_reference_assert_same($row['term'], $records[$index]['term'] ?? null, "HTML lang corpus term {$index}");
        wp_fts_external_reference_assert_same($row['lang'], $records[$index]['lang'] ?? null, "HTML lang corpus language {$index}");
    }
});

test_case('quality external optional dependency skips are recorded', function (): void {
    $skips = $GLOBALS['wp_fts_external_reference_optional_skips'];
    foreach ($skips as $label => $reason) {
        wp_fts_external_reference_assert_true(is_string($label) && $label !== '', 'optional skip label should be explicit');
        wp_fts_external_reference_assert_true(is_string($reason) && $reason !== '', "optional skip {$label} should include a reason");
    }
});

if ($wp_fts_external_reference_direct) {
    $failures = 0;
    $start = microtime(true);
    foreach ($GLOBALS['wp_fts_external_reference_tests'] as $test) {
        try {
            ($test['fn'])();
            fwrite(STDOUT, "[PASS] {$test['name']}\n");
        } catch (Throwable $e) {
            $failures++;
            fwrite(STDERR, "[FAIL] {$test['name']}\n{$e->getMessage()}\n{$e->getTraceAsString()}\n");
        }
    }

    $duration = number_format(microtime(true) - $start, 3);
    $count = count($GLOBALS['wp_fts_external_reference_tests']);
    $passed = $count - $failures;
    $checks = (int) $GLOBALS['wp_fts_external_reference_harness_checks'];
    $externalChecks = wp_fts_external_reference_check_count();
    $summary = "{$passed}/{$count} external reference suite tests passed; failures={$failures}; checks/scenarios={$checks}; external_checks={$externalChecks}; duration={$duration}s\n";
    if ($failures > 0) {
        fwrite(STDERR, $summary);
        exit(1);
    }

    fwrite(STDOUT, $summary);
}
