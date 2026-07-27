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
require_once dirname(__DIR__, 2) . '/tools/import-conllu-lemma-pack.php';
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
        'max_rows_per_file' => 5,
        'chunk_rows' => 4,
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

/** Invoke one importer input boundary without creating source artifacts. */
function wp_fts_lpic_private(object $importer, string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod($importer::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($importer, ...$args);
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

test_case('lemma TSV compiler accepts only its exact string and count option shapes', function (): void {
    $importer = new WP_FTS_LemmaTsvPackImporter();
    $allowedKeys = [
        'source',
        'out',
        'language',
        'pack_id',
        'version',
        'source_name',
        'source_url',
        'license',
        'attribution',
        'source_version',
        'license_url',
        'max_rows_per_file',
        'chunk_rows',
        'importer_commit',
        'tmp_dir',
    ];
    assert_same(
        null,
        wp_fts_lpic_private($importer, 'assert_option_keys', array_fill_keys($allowedKeys, true)),
        'the generic importer should accept exactly the documented current option keys'
    );
    foreach ([['unknown' => true], [0 => 'source']] as $options) {
        $error = null;
        try {
            wp_fts_lpic_private($importer, 'assert_option_keys', $options);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        assert_true($error instanceof RuntimeException, 'the generic importer should reject unsupported string and numeric option keys');
    }

    assert_same(
        ' exact bytes ',
        wp_fts_lpic_private($importer, 'required_string', ['name' => ' exact bytes '], 'name'),
        'required importer strings should be validated without rewriting their bytes'
    );
    foreach ([null, false, true, 0, 1, 1.0, [], new stdClass(), '', '   '] as $value) {
        $error = null;
        try {
            wp_fts_lpic_private($importer, 'required_string', ['name' => $value], 'name');
        } catch (Throwable $caught) {
            $error = $caught;
        }
        assert_true($error instanceof RuntimeException, 'required importer values should reject every non-string and blank shape');
    }
    $missingRequired = null;
    try {
        wp_fts_lpic_private($importer, 'required_string', [], 'name');
    } catch (Throwable $caught) {
        $missingRequired = $caught;
    }
    assert_true($missingRequired instanceof RuntimeException, 'required importer strings should reject absence');

    assert_same(
        'default',
        wp_fts_lpic_private($importer, 'optional_string', [], 'name', 'default'),
        'optional importer strings should use their default only when absent'
    );
    assert_same(
        ' exact bytes ',
        wp_fts_lpic_private($importer, 'optional_string', ['name' => ' exact bytes '], 'name', 'default'),
        'supplied optional importer strings should retain their exact bytes'
    );
    foreach ([null, false, true, 0, 1, 1.0, [], new stdClass(), '', '   '] as $value) {
        $error = null;
        try {
            wp_fts_lpic_private($importer, 'optional_string', ['name' => $value], 'name', 'default');
        } catch (Throwable $caught) {
            $error = $caught;
        }
        assert_true($error instanceof RuntimeException, 'supplied optional importer values should reject every non-string and blank shape');
    }

    assert_same(23, wp_fts_lpic_private($importer, 'positive_integer_option', [], 'count', 23), 'importer counts should use their default only when absent');
    foreach ([1, '1', 42, '42'] as $value) {
        assert_same(
            (int) $value,
            wp_fts_lpic_private($importer, 'positive_integer_option', ['count' => $value], 'count', 23),
            'importer counts should accept native positive integers and canonical decimal strings'
        );
    }
    foreach ([0, '0', 1.0, '1.0', '1e0', -1, '-1', '01', ' 1 ', '+1', 'junk', true, false, null, [], new stdClass(), str_repeat('9', 21)] as $value) {
        $error = null;
        try {
            wp_fts_lpic_private($importer, 'positive_integer_option', ['count' => $value], 'count', 23);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        assert_true($error instanceof RuntimeException, 'importer counts should reject non-positive, noncanonical, and out-of-range values');
    }
});

test_case('lemma source wrappers validate their keys and project only TSV compiler options', function (): void {
    $baseOptions = wp_fts_lpic_options('source.fixture', 'output.fixture', 'qaa-wrapper-options');
    $conllu = new WP_FTS_ConlluLemmaPackImporter();
    $conlluOptions = $baseOptions + [
        'source_repo_url' => 'urn:wp-fts:test:conllu-repository',
        'source_commit' => str_repeat('a', 40),
        'source_file_path' => 'source.fixture.conllu',
    ];
    assert_same(null, wp_fts_lpic_private($conllu, 'assert_option_keys', $conlluOptions), 'CoNLL-U should accept the TSV contract plus its three source fields');

    $unimorph = new WP_FTS_UnimorphLemmaPackImporter();
    $unimorphOptions = $baseOptions + [
        'source_repo_url' => 'urn:wp-fts:test:unimorph-repository',
        'source_commit' => str_repeat('b', 40),
        'source_file_path' => 'source.fixture.unimorph',
        'license_record_path' => 'LICENSE.test.txt',
        'license_record_sha256' => str_repeat('c', 64),
    ];
    assert_same(null, wp_fts_lpic_private($unimorph, 'assert_option_keys', $unimorphOptions), 'UniMorph should accept the TSV contract plus its five source fields');

    foreach ([$conllu, $unimorph] as $wrapper) {
        foreach ([['unknown' => true], [0 => 'source']] as $unsupported) {
            $error = null;
            try {
                wp_fts_lpic_private($wrapper, 'assert_option_keys', $baseOptions + $unsupported);
            } catch (Throwable $caught) {
                $error = $caught;
            }
            assert_true($error instanceof RuntimeException, 'source wrappers should reject unsupported string and numeric keys before reading source paths');
        }
    }

    foreach (['source_repo_url', 'source_commit', 'source_file_path'] as $key) {
        foreach ([null, false, true, 0, 1, 1.0, [], new stdClass(), '', '   '] as $value) {
            foreach ([$conllu, $unimorph] as $wrapper) {
                $candidate = $baseOptions + [$key => $value];
                $error = null;
                try {
                    wp_fts_lpic_private($wrapper, 'assert_option_keys', $candidate);
                } catch (Throwable $caught) {
                    $error = $caught;
                }
                assert_true($error instanceof RuntimeException, "a supplied wrapper {$key} must be a nonblank native string");
            }
        }
    }

    foreach (['license_record_path', 'license_record_sha256'] as $key) {
        foreach ([null, false, true, 0, 1, 1.0, [], new stdClass(), '', '   '] as $value) {
            $candidate = $baseOptions + [
                'license_record_path' => 'LICENSE.test.txt',
                'license_record_sha256' => str_repeat('c', 64),
            ];
            $candidate[$key] = $value;
            $error = null;
            try {
                wp_fts_lpic_private($unimorph, 'assert_option_keys', $candidate);
            } catch (Throwable $caught) {
                $error = $caught;
            }
            assert_true($error instanceof RuntimeException, "a supplied UniMorph {$key} must be a nonblank native string");
        }
    }

    foreach ([
        ['license_record_path' => 'LICENSE.test.txt'],
        ['license_record_sha256' => str_repeat('c', 64)],
        ['license_record_path' => 'LICENSE.test.txt', 'license_record_sha256' => str_repeat('c', 63)],
        ['license_record_path' => 'LICENSE.test.txt', 'license_record_sha256' => str_repeat('C', 64)],
        ['license_record_path' => 'LICENSE.test.txt', 'license_record_sha256' => str_repeat('g', 64)],
    ] as $invalidLicenseRecord) {
        $error = null;
        try {
            wp_fts_lpic_private($unimorph, 'assert_option_keys', $baseOptions + $invalidLicenseRecord);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        assert_true($error instanceof RuntimeException, 'UniMorph license record path and lowercase digest must form one valid pair');
    }

    $root = sys_get_temp_dir() . '/wp-fts-conllu-option-projection-' . bin2hex(random_bytes(6));
    $source = $root . '/source.conllu';
    $out = $root . '/pack';
    mkdir($root, 0777, true);
    try {
        file_put_contents($source, "1\tqaaform\tqaalemma\tNOUN\t_\t_\t0\troot\t_\t_\n");
        $options = wp_fts_lpic_options($source, $out, 'qaa-conllu-option-projection') + [
            'source_repo_url' => 'urn:wp-fts:test:conllu-repository',
            'source_commit' => str_repeat('d', 40),
            'source_file_path' => 'source.conllu',
        ];
        $summary = $conllu->import($options);
        assert_same(1, $summary['runtime']['rows'] ?? null, 'CoNLL-U source fields should remain wrapper-local while the TSV compiler receives one valid row');
        assert_same('urn:wp-fts:test:conllu-repository', $summary['source']['repo_url'] ?? null, 'CoNLL-U should retain its wrapper-only repository field');
        assert_same(str_repeat('d', 40), $summary['source']['commit'] ?? null, 'CoNLL-U should retain its wrapper-only commit field');
        (new WP_FTS_AnalyzerPackValidator())->validate($out . '/manifest.json');
    } finally {
        wp_fts_lpic_remove_tree($root);
    }
});

test_case('PoliMorf compiler and CLI accept only their current typed option contract', function (): void {
    $importer = new WP_FTS_PolishPolimorfImporter();
    assert_same(
        null,
        wp_fts_lpic_private(
            $importer,
            'assert_option_keys',
            array_fill_keys(WP_FTS_PolishPolimorfImporter::IMPORT_OPTION_KEYS, true)
        ),
        'the PoliMorf compiler should accept every current option key'
    );
    foreach ([['unknown' => true], [0 => 'source']] as $options) {
        $error = null;
        try {
            wp_fts_lpic_private($importer, 'assert_option_keys', $options);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        assert_true($error instanceof RuntimeException, 'the PoliMorf compiler should reject unsupported option keys');
    }

    assert_same(
        'exact bytes',
        wp_fts_lpic_private($importer, 'required_string', ['name' => 'exact bytes'], 'name'),
        'PoliMorf string options should retain their exact bytes'
    );
    foreach ([null, false, true, 0, 1, 1.0, [], new stdClass(), '', '   ', ' padded '] as $value) {
        $error = null;
        try {
            wp_fts_lpic_private($importer, 'required_string', ['name' => $value], 'name');
        } catch (Throwable $caught) {
            $error = $caught;
        }
        assert_true($error instanceof RuntimeException, 'PoliMorf string options should reject non-string and blank values');
    }

    assert_same(
        100000,
        wp_fts_lpic_private($importer, 'bounded_positive_integer_option', [], 'count', 100000),
        'an absent PoliMorf count should use its native integer default'
    );
    foreach ([1, WP_FTS_LemmaSourceImportLimits::MAX_CHUNK_ROWS] as $value) {
        assert_same(
            $value,
            wp_fts_lpic_private($importer, 'bounded_positive_integer_option', ['count' => $value], 'count', 100000),
            'PoliMorf counts should accept native integers at both bounds'
        );
    }
    foreach ([0, -1, WP_FTS_LemmaSourceImportLimits::MAX_CHUNK_ROWS + 1, '1', 1.0, true, false, null, [], new stdClass()] as $value) {
        $error = null;
        try {
            wp_fts_lpic_private($importer, 'bounded_positive_integer_option', ['count' => $value], 'count', 100000);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        assert_true($error instanceof RuntimeException, 'PoliMorf programmatic counts should reject coercion and values outside their bound');
    }

    foreach ([str_repeat('x', WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_PATH_BYTES + 1), "path\0tail"] as $path) {
        $error = null;
        try {
            wp_fts_lpic_private($importer, 'required_path_string', ['path' => $path], 'path');
        } catch (Throwable $caught) {
            $error = $caught;
        }
        assert_true($error instanceof RuntimeException, 'PoliMorf paths should reject oversized and null-byte values');
    }

    assert_same(
        [
            'source' => 'source.tab',
            'out' => 'pack',
            'pack_id' => 'current-pack',
            'max_rows_per_file' => 2,
            'chunk_rows' => 3,
        ],
        WP_FTS_PolishPolimorfImporter::parse_cli_options([
            '--source=source.tab',
            '--out',
            'pack',
            '--pack-id=current-pack',
            '--max-rows-per-file=2',
            '--chunk-rows',
            '3',
        ]),
        'the PoliMorf CLI boundary should preserve current strings and type canonical integers'
    );

    foreach ([
        ['--source'],
        ['--download=maybe'],
        ['--download=1'],
        ['--allow-repo-output=false'],
        ['--expect-source-bytes=4'],
        ['--chunk-rows=0'],
        ['--chunk-rows=01'],
        ['--chunk-rows=' . str_repeat('9', 30)],
        ['--unknown=value'],
        ['--pack_id=value'],
        ['--source=a', '--source=b'],
        [1],
        [1 => '--source=a'],
    ] as $argv) {
        $error = null;
        try {
            WP_FTS_PolishPolimorfImporter::parse_cli_options($argv);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        assert_true($error instanceof RuntimeException, 'the PoliMorf CLI should reject malformed, ambiguous, or out-of-range arguments');
    }
});

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

        (new WP_FTS_AnalyzerPackValidator())->validate($firstOut . '/manifest.json');
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
        $options['license_record_path'] = 'LICENSE.test.txt';
        $options['license_record_sha256'] = str_repeat('b', 64);
        $summary = (new WP_FTS_UnimorphLemmaPackImporter())->import($options);
        $manifest = wp_fts_lpic_manifest($out . '/manifest.json');
        $stats = $manifest['source']['parse_stats'] ?? [];

        assert_same(13, $summary['unimorph']['accepted_rows'] ?? null, 'UniMorph should accept every source row before delegated ambiguity compilation');
        assert_same(1, $manifest['runtime']['total_rows'] ?? null, 'the UniMorph over-cap surface should compile to one identity runtime row');
        assert_same(13, $stats['unique_source_rows'] ?? null, 'published provenance should retain delegated unique-pair counts');
        assert_same(1, $stats['ambiguity_noop_surfaces'] ?? null, 'published provenance should identify the compiled no-op surface');
        assert_same(13, $stats['ambiguity_noop_source_pairs'] ?? null, 'published provenance should explain all replaced UniMorph source pairs');
        assert_same(13, $stats['accepted_rows'] ?? null, 'published provenance should retain the upstream UniMorph acceptance count');

        (new WP_FTS_AnalyzerPackValidator())->validate($out . '/manifest.json');
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
