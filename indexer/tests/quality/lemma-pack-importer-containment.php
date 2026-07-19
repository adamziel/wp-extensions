<?php
declare(strict_types=1);

$wp_fts_lpic_direct = !function_exists('test_case');
if ($wp_fts_lpic_direct) {
    require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

    final class WP_FTS_Lemma_Importer_Test_Failure extends RuntimeException
    {
    }

    /** @var array<int,array{name:string,fn:callable}> */
    $GLOBALS['wp_fts_lpic_tests'] = [];
    $GLOBALS['wp_fts_lpic_checks'] = 0;

    /** Register one assertion closure when this quality file runs standalone. */
    function test_case(string $name, callable $fn): void
    {
        $GLOBALS['wp_fts_lpic_tests'][] = ['name' => $name, 'fn' => $fn];
    }

    /** Count one standalone check and fail with its invariant description. */
    function assert_true(bool $condition, string $message): void
    {
        $GLOBALS['wp_fts_lpic_checks']++;
        if (!$condition) {
            throw new WP_FTS_Lemma_Importer_Test_Failure($message);
        }
    }

    /** Count one strict standalone equality check and report both values. */
    function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        $GLOBALS['wp_fts_lpic_checks']++;
        if ($expected !== $actual) {
            throw new WP_FTS_Lemma_Importer_Test_Failure(
                $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
            );
        }
    }
}

require_once dirname(__DIR__, 2) . '/tools/import-lemma-tsv-pack.php';
require_once dirname(__DIR__, 2) . '/tools/import-unimorph-lemma-pack.php';
require_once dirname(__DIR__, 2) . '/tools/import-polish-polimorf-lemmatizer.php';

/** @return array<string,mixed> */
function wp_fts_lpic_options(string $source, string $out, string $packId): array
{
    return [
        'source' => $source,
        'out' => $out,
        'language' => 'qaa',
        'pack_id' => $packId,
        'version' => 'ambiguity-boundary-v1',
        'source_name' => 'Project-owned bounded ambiguity fixture',
        'source_version' => 'ambiguity-boundary-v1',
        'source_url' => 'urn:wp-fts:test:bounded-ambiguity',
        'license' => 'CC0-1.0',
        'attribution' => 'Project-owned bounded ambiguity rows.',
        'fixture_only' => true,
        'max_rows_per_file' => 5,
        'chunk_rows' => 4,
        'runtime_compression' => WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
    ];
}

/** @return string[] */
function wp_fts_lpic_source_pairs(): array
{
    $pairs = [];
    for ($number = 1; $number <= 11; $number++) {
        $pairs[] = 'qaaexact' . "\t" . sprintf('qaaexactalt%02d', $number);
    }
    $pairs[] = "qaaexact\tqaaexact";
    for ($number = 1; $number <= 13; $number++) {
        $pairs[] = 'qaaoverflow' . "\t" . sprintf('qaaoverflowlemma%02d', $number);
    }
    $pairs[] = "qaaother\tqaaother";

    // Put duplicates in separate four-row sort chunks so the merge, rather
    // than one in-memory chunk map, proves global deduplication.
    $pairs[] = "qaaexact\tqaaexactalt01";
    $pairs[] = "qaaoverflow\tqaaoverflowlemma01";

    return array_reverse($pairs);
}

/** @return array<string,mixed> */
function wp_fts_lpic_manifest(string $path): array
{
    $json = file_get_contents($path);
    if (!is_string($json)) {
        throw new RuntimeException("Could not read lemma importer test manifest: {$path}");
    }
    $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest)) {
        throw new RuntimeException("Lemma importer test manifest is not an object: {$path}");
    }

    return $manifest;
}

/** Remove one isolated importer-test fixture tree after its assertions finish. */
function wp_fts_lpic_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child)) {
            wp_fts_lpic_remove_tree($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
}

test_case('lemma TSV compiler preserves twelve candidates and deterministically no-ops the thirteenth', function (): void {
    assert_true(
        WP_FTS_AnalyzerPackValidator::gzip_available(),
        'the shipped compressed-pack boundary must run with gzip support'
    );

    $root = sys_get_temp_dir() . '/wp-fts-lemma-importer-cap-' . bin2hex(random_bytes(6));
    $source = $root . '/source.tsv';
    $firstOut = $root . '/first';
    $secondOut = $root . '/second';
    mkdir($root, 0777, true);
    try {
        $pairs = wp_fts_lpic_source_pairs();
        file_put_contents($source, implode("\n", $pairs) . "\n");
        $first = (new WP_FTS_LemmaTsvPackImporter())->import(
            wp_fts_lpic_options($source, $firstOut, 'qaa-bounded-ambiguity')
        );
        $second = (new WP_FTS_LemmaTsvPackImporter())->import(
            wp_fts_lpic_options($source, $secondOut, 'qaa-bounded-ambiguity')
        );

        assert_same(14, $first['runtime']['rows'] ?? null, 'twelve exact-boundary rows, one identity no-op, and one ordinary row should be emitted');
        assert_same(2, $first['runtime']['files'] ?? null, 'a twelve-row surface must stay whole even when it exceeds the five-row shard target');
        assert_same(26, $first['stats']['unique_source_rows'] ?? null, 'all unique source pairs should be counted before ambiguity compilation');
        assert_same(2, $first['stats']['deduplicated_rows'] ?? null, 'duplicates spanning sort chunks should not be confused with ambiguity no-ops');
        assert_same(2, $first['stats']['ambiguous_surfaces'] ?? null, 'the exact boundary and over-boundary surfaces should remain source ambiguities');
        assert_same(1, $first['stats']['unambiguous_surfaces'] ?? null, 'the ordinary source surface should remain unambiguous');
        assert_same(1, $first['stats']['ambiguity_noop_surfaces'] ?? null, 'only the thirteen-candidate surface should compile to a no-op');
        assert_same(13, $first['stats']['ambiguity_noop_source_pairs'] ?? null, 'the no-op provenance should account for every replaced source pair');
        assert_same($first['runtime'], $second['runtime'], 'repeated compressed imports should produce byte-identical runtime and lookup attestations');

        $firstManifest = wp_fts_lpic_manifest($firstOut . '/manifest.json');
        $secondManifest = wp_fts_lpic_manifest($secondOut . '/manifest.json');
        assert_same($firstManifest, $secondManifest, 'repeated imports should produce identical manifests');
        assert_same([12, 2], array_column($firstManifest['runtime']['files'], 'rows'), 'sharding should never split one surface across files');

        (new WP_FTS_AnalyzerPackValidator())->validate($firstOut . '/manifest.json', false);
        $pack = WP_FTS_LanguageLemmaPack::from_manifest_file($firstOut . '/manifest.json');
        $exactTerms = array_column($pack->analyze('qaaexact', 'qaa'), 'term');
        $expectedExact = ['qaaexact'];
        for ($number = 1; $number <= 11; $number++) {
            $expectedExact[] = sprintf('qaaexactalt%02d', $number);
        }
        assert_same($expectedExact, $exactTerms, 'the exact twelve-candidate boundary must retain every source alternative in exact-first order');
        assert_same('qaaoverflow', $pack->stem('qaaoverflow', 'qaa'), 'the thirteenth source candidate must make stemming a deterministic identity no-op');
        assert_same(['qaaoverflow'], array_column($pack->analyze('qaaoverflow', 'qaa'), 'term'), 'analysis must not expose a lexical first-twelve subset');
    } finally {
        wp_fts_lpic_remove_tree($root);
    }
});

test_case('UniMorph provenance retains delegated ambiguity no-op counts', function (): void {
    $root = sys_get_temp_dir() . '/wp-fts-unimorph-importer-cap-' . bin2hex(random_bytes(6));
    $source = $root . '/qaa';
    $out = $root . '/pack';
    mkdir($root, 0777, true);
    try {
        $rows = [];
        for ($number = 1; $number <= 13; $number++) {
            $rows[] = sprintf("QAAOverflowLemma%02d\tQAAOverflow\tN;SG", $number);
        }
        file_put_contents($source, implode("\n", $rows) . "\n");

        $options = wp_fts_lpic_options($source, $out, 'qaa-unimorph-bounded-ambiguity');
        $options['source_repo_url'] = 'urn:wp-fts:test:bounded-ambiguity-repository';
        $options['source_commit'] = str_repeat('a', 40);
        $options['source_file_path'] = 'qaa';
        $summary = (new WP_FTS_UnimorphLemmaPackImporter())->import($options);
        $manifest = wp_fts_lpic_manifest($out . '/manifest.json');
        $stats = $manifest['source']['parse_stats'] ?? [];

        assert_same(13, $summary['unimorph']['accepted_rows'] ?? null, 'UniMorph should accept every source row before delegated ambiguity compilation');
        assert_same(1, $manifest['runtime']['total_rows'] ?? null, 'the UniMorph over-cap surface should compile to one identity runtime row');
        assert_same(13, $stats['unique_source_rows'] ?? null, 'published provenance should retain delegated unique-pair counts');
        assert_same(1, $stats['ambiguity_noop_surfaces'] ?? null, 'published provenance should identify the compiled no-op surface');
        assert_same(13, $stats['ambiguity_noop_source_pairs'] ?? null, 'published provenance should explain all replaced UniMorph source pairs');
        assert_same(13, $stats['accepted_rows'] ?? null, 'published provenance should retain the upstream UniMorph acceptance count');

        (new WP_FTS_AnalyzerPackValidator())->validate($out . '/manifest.json', false);
        $pack = WP_FTS_LanguageLemmaPack::from_manifest_file($out . '/manifest.json');
        assert_same(['qaaoverflow'], array_column($pack->analyze('qaaoverflow', 'qaa'), 'term'), 'delegated UniMorph runtime behavior should match the declared ambiguity no-op');
    } finally {
        wp_fts_lpic_remove_tree($root);
    }
});

test_case('PoliMorf compiler deterministically no-ops a thirteenth lemma', function (): void {
    $root = sys_get_temp_dir() . '/wp-fts-polimorf-importer-cap-' . bin2hex(random_bytes(6));
    $source = $root . '/source.tab';
    $out = $root . '/pack';
    mkdir($root, 0777, true);
    try {
        $rows = [];
        for ($number = 1; $number <= 13; $number++) {
            $rows[] = sprintf("powierzchnia\tlemat%02d\ttag\t\t", $number);
        }
        file_put_contents($source, implode("\n", $rows) . "\n");
        $summary = (new WP_FTS_PolishPolimorfImporter())->import([
            'source' => $source,
            'out' => $out,
            'pack_id' => 'pl-polimorf-bounded-ambiguity',
            'version' => 'ambiguity-boundary-v1',
            'source_url' => 'urn:wp-fts:test:polimorf-bounded-ambiguity',
            'source_name' => 'Project-owned PoliMorf ambiguity fixture',
            'source_version' => 'ambiguity-boundary-v1',
            'fixture_only' => true,
            'chunk_rows' => 4,
            'max_rows_per_file' => 5,
            'importer_commit' => 'test',
        ]);

        assert_same(1, $summary['runtime']['rows'] ?? null, 'the PoliMorf over-cap surface should compile to one identity row');
        assert_same(1, $summary['stats']['ambiguity_noop_surfaces'] ?? null, 'PoliMorf provenance should identify the compiled no-op surface');
        assert_same(13, $summary['stats']['ambiguity_noop_source_pairs'] ?? null, 'PoliMorf provenance should account for all replaced source pairs');
        $pack = WP_FTS_LanguageLemmaPack::from_manifest_file($out . '/manifest.json');
        assert_same(['powierzchnia'], array_column($pack->analyze('powierzchnia', 'pl'), 'term'), 'PoliMorf analysis should not expose a lexical first-twelve subset');
    } finally {
        wp_fts_lpic_remove_tree($root);
    }
});

if ($wp_fts_lpic_direct) {
    try {
        foreach ($GLOBALS['wp_fts_lpic_tests'] as $test) {
            $test['fn']();
        }
        fwrite(STDOUT, 'Lemma importer containment tests passed (' . $GLOBALS['wp_fts_lpic_checks'] . " assertions).\n");
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
