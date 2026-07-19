<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$wp_fts_lemma_limit_checks = 0;

/** Records one assertion and throws when a lemma-pack limit invariant fails. */
function wp_fts_lemma_limit_check(bool $condition, string $message): void
{
    global $wp_fts_lemma_limit_checks;
    $wp_fts_lemma_limit_checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Runs a lemma-pack limit probe and returns its exception, if any. */
function wp_fts_lemma_limit_caught(callable $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $error) {
        return $error;
    }

    return null;
}

/** Searches an exception chain for the expected limit diagnostic. */
function wp_fts_lemma_limit_error_contains(?Throwable $error, string $needle): bool
{
    while ($error !== null) {
        if (str_contains($error->getMessage(), $needle)) {
            return true;
        }
        $error = $error->getPrevious();
    }

    return false;
}

/** @return string[] */
function wp_fts_lemma_limit_analysis_terms(
    WP_FTS_LanguageLemmaPack $pack,
    string $surface,
    string $language = 'qaa'
): array
{
    return array_column($pack->analyze($surface, $language), 'term');
}

/**
 * @param array<int,array{path:string,contents:string,compression?:string,lookup?:bool,block_rows?:int}> $files
 */
function wp_fts_lemma_limit_write_pack(
    string $directory,
    array $files,
    bool $fixtureOnly,
    string $language = 'qaa'
): string
{
    mkdir($directory . '/runtime', 0777, true);
    file_put_contents($directory . '/NOTICE.txt', "Synthetic lemma-cap test fixture.\n");
    $runtimeFiles = [];
    $totalDigest = hash_init('sha256');
    $totalRows = 0;

    foreach ($files as $number => $file) {
        $relativePath = 'runtime/' . $file['path'];
        $path = $directory . '/' . $relativePath;
        $contents = $file['contents'];
        $rows = array_values(array_filter(explode("\n", $contents), static fn(string $line): bool => $line !== ''));
        foreach ($rows as $row) {
            hash_update($totalDigest, $row . "\n");
        }
        $totalRows += count($rows);

        if (($file['compression'] ?? null) === 'gzip') {
            $encoded = gzencode($contents, 9, ZLIB_ENCODING_GZIP);
            if (!is_string($encoded)) {
                throw new RuntimeException('Could not encode a lemma-cap gzip fixture.');
            }
            file_put_contents($path, $encoded);
        } else {
            file_put_contents($path, $contents);
        }
        $entry = [
            'path' => $relativePath,
            'sha256' => hash_file('sha256', $path),
            'rows' => count($rows),
            'first_surface' => explode("\t", $rows[0], 2)[0],
            'last_surface' => explode("\t", $rows[count($rows) - 1], 2)[0],
        ];
        if (($file['compression'] ?? null) === 'gzip') {
            $entry['compression'] = 'gzip';
        }
        if (($file['lookup'] ?? false) === true) {
            $lookupPath = $path . '.lookup';
            $lookup = WP_FTS_LemmaPackLookupIndex::build(
                $path,
                'gzip',
                (string) $entry['sha256'],
                $lookupPath,
                (int) ($file['block_rows'] ?? 2)
            );
            $entry['sha256'] = $lookup['runtime_sha256'];
            $entry['lookup'] = [
                'format' => $lookup['format'],
                'path' => $relativePath . '.lookup',
                'sha256' => $lookup['sha256'],
                'blocks' => $lookup['blocks'],
            ];
        }
        $runtimeFiles[] = $entry;
    }

    $manifest = [
        'schema_version' => 1,
        'pack_id' => 'qaa-lemma-cap-' . basename($directory),
        'language' => $language,
        'version' => 'test-v1',
        'fixture_only' => $fixtureOnly,
        'default_enabled' => false,
        'capabilities' => ['dictionary-lemmatizer', 'ambiguous-form-noop', 'normalized-runtime-rows'],
        'runtime' => [
            'format' => WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV,
            'normalization' => "WP_FTS_Normalizer {$language} with fold_diacritics=true",
            'ambiguity_policy' => 'ambiguous_surface_noop',
            'total_rows' => $totalRows,
            'total_sha256' => hash_final($totalDigest),
            'files' => $runtimeFiles,
        ],
        'source' => [
            'name' => 'Synthetic lemma-cap fixture',
            'version' => 'test-v1',
            'url' => 'urn:wp-fts:test:lemma-cap',
            'artifact_sha256' => str_repeat('a', 64),
            'byte_count' => 1,
        ],
        'license' => ['spdx_id' => 'BSD-2-Clause', 'notice_path' => 'NOTICE.txt'],
        'attribution' => ['notice_path' => 'NOTICE.txt'],
        'provenance' => [
            'no_runtime_network_access' => true,
            'no_full_third_party_dictionary_dump' => $fixtureOnly,
        ],
    ];
    $path = $directory . '/manifest.json';
    file_put_contents(
        $path,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );

    return $path;
}

/** Removes a synthetic lemma-pack tree after a boundary test. */
function wp_fts_lemma_limit_remove_tree(string $path): void
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
        $child = $path . '/' . $item;
        if (is_dir($child)) {
            wp_fts_lemma_limit_remove_tree($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
}

wp_fts_lemma_limit_check(
    WP_FTS_AnalyzerPackValidator::MAX_LEMMAS_PER_SURFACE === 12,
    'validator and runtime must share the twelve-lemma relational-plan limit'
);
$elevenAlternatives = [];
for ($number = 1; $number <= 11; $number++) {
    $elevenAlternatives[] = sprintf('qaaalt%02d', $number);
}
$twelveAlternatives = array_merge($elevenAlternatives, ['qaaalt12']);
$twelveLemmas = array_merge($elevenAlternatives, ['qaasurface']);
$thirteenLemmas = array_merge($twelveAlternatives, ['qaasurface']);
$expectedTwelve = array_merge(['qaasurface'], $elevenAlternatives);
wp_fts_lemma_limit_check(
    WP_FTS_LemmaPackLimits::ordered_lemmas_for_surface('qaasurface', $twelveLemmas) === $expectedTwelve,
    'bounded selection should retain the exact lemma before all eleven lexical alternatives'
);
$directOverCapError = wp_fts_lemma_limit_caught(
    static fn(): array => WP_FTS_LemmaPackLimits::ordered_lemmas_for_surface('qaasurface', $thirteenLemmas)
);
wp_fts_lemma_limit_check(
    $directOverCapError instanceof RuntimeException
        && str_contains($directOverCapError->getMessage(), '12-lemma ambiguity limit'),
    'an over-cap dictionary surface must fail closed instead of returning a lexical first-twelve subset'
);
wp_fts_lemma_limit_check(
    WP_FTS_LemmaPackLimits::ordered_lemmas_for_surface('qaasurface', ['qaabravo', 'qaasurface', 'qaaalpha'])
        === ['qaasurface', 'qaaalpha', 'qaabravo'],
    'three-candidate behavior should remain unchanged'
);

$root = sys_get_temp_dir() . '/wp-fts-lemma-cap-' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);
try {
    $runtimeRows = static function (array $lemmas, bool $includeOther = true): string {
        $rows = array_map(
            static fn(string $lemma): string => "qaasurface\t{$lemma}",
            $lemmas
        );
        if ($includeOther) {
            $rows[] = "qaazother\tqaazother";
        }
        sort($rows, SORT_STRING);

        return implode("\n", $rows) . "\n";
    };
    $twelveRows = $runtimeRows($twelveLemmas);
    $thirteenRows = $runtimeRows($thirteenLemmas);

    $eagerManifest = wp_fts_lemma_limit_write_pack($root . '/eager', [
        ['path' => '0001.tsv', 'contents' => $twelveRows],
    ], true);
    $eagerPack = WP_FTS_LanguageLemmaPack::from_manifest_file($eagerManifest);
    wp_fts_lemma_limit_check(
        wp_fts_lemma_limit_analysis_terms($eagerPack, 'qaasurface', 'qab') === ['qaasurface'],
        'a pack must not expand a surface from another language partition'
    );
    wp_fts_lemma_limit_check(
        wp_fts_lemma_limit_analysis_terms($eagerPack, 'qaasurface') === $expectedTwelve,
        'eager lookup should preserve exact-first order at the twelve-candidate boundary'
    );
    $eagerOverCapManifest = wp_fts_lemma_limit_write_pack($root . '/eager-over-cap', [
        ['path' => '0001.tsv', 'contents' => $thirteenRows],
    ], true);
    $eagerOverCapError = wp_fts_lemma_limit_caught(
        static fn(): WP_FTS_LanguageLemmaPack => WP_FTS_LanguageLemmaPack::from_manifest_file($eagerOverCapManifest)
    );
    wp_fts_lemma_limit_check(
        $eagerOverCapError instanceof RuntimeException
            && str_contains($eagerOverCapError->getMessage(), '12-lemma ambiguity limit'),
        'full eager-pack validation should reject the thirteenth lemma before building an in-memory lookup'
    );

    $corruptRuntimeManifest = wp_fts_lemma_limit_write_pack($root . '/eager-corrupt-runtime', [
        ['path' => '0001.tsv', 'contents' => "qaacorrupt\tqaapacklemma\n"],
    ], true);
    $corruptRuntimeMetadata = json_decode(
        (string) file_get_contents($corruptRuntimeManifest),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $corruptRuntimePath = dirname($corruptRuntimeManifest)
        . '/' . (string) $corruptRuntimeMetadata['runtime']['files'][0]['path'];
    file_put_contents($corruptRuntimePath, "qaacorrupt\tqaapacklemmb\n");
    $corruptPipeline = null;
    $corruptPipelineError = wp_fts_lemma_limit_caught(
        static function () use ($corruptRuntimeManifest, &$corruptPipeline): void {
            $corruptPipeline = new WP_FTS_LanguagePipeline([
                'lemma_packs_by_lang' => ['qaa' => $corruptRuntimeManifest],
            ]);
        }
    );
    wp_fts_lemma_limit_check(
        $corruptPipelineError === null && $corruptPipeline instanceof WP_FTS_LanguagePipeline,
        'a preflight-valid pack with corrupt runtime bytes should fall back instead of aborting pipeline construction'
    );
    wp_fts_lemma_limit_check(
        array_column($corruptPipeline->analyze_detailed('qaacorrupt', 'qaa'), 'term') === ['qaacorrupt'],
        'a corrupt configured pack should preserve the built-in analyzer result rather than expose unverified morphology'
    );

    $detectionPipeline = new WP_FTS_LanguagePipeline([
        'lemma_packs_by_lang' => ['qaa' => $eagerManifest],
    ]);
    $detectionAnalyzer = new WP_FTS_Analyzer([
        'default_lang' => 'en',
        'language_pipeline' => $detectionPipeline,
    ]);
    $detectedQuery = $detectionAnalyzer->analyze_query_occurrences('qaasurface qaazother');
    wp_fts_lemma_limit_check(
        array_values(array_unique(array_column($detectedQuery, 'lang'))) === ['en'],
        'a compact detector miss must stay on the configured partition without probing enabled lemma packs'
    );
    wp_fts_lemma_limit_check(
        array_values(array_unique(array_column($detectionAnalyzer->analyze_query_occurrences('qaasurface'), 'lang'))) === ['en'],
        'one coincidental dictionary surface must not reroute a compact detector miss'
    );
    wp_fts_lemma_limit_check(
        str_contains($detectionPipeline->index_signature(), 'wp-fts-language-pipeline-v20:'),
        'lemma-pack limits should be represented in the pipeline signature'
    );
    $splitOverCapManifest = wp_fts_lemma_limit_write_pack($root . '/split-over-cap', [
        ['path' => '0001.tsv', 'contents' => $runtimeRows(array_slice($thirteenLemmas, 0, 6), false)],
        ['path' => '0002.tsv', 'contents' => $runtimeRows(array_slice($thirteenLemmas, 6), false)],
    ], false);
    $splitValidationError = wp_fts_lemma_limit_caught(
        static fn(): array => (new WP_FTS_AnalyzerPackValidator())->validate($splitOverCapManifest, false)
    );
    wp_fts_lemma_limit_check(
        $splitValidationError instanceof RuntimeException
            && str_contains($splitValidationError->getMessage(), 'strictly ordered and non-overlapping'),
        'validator should reject a surface split across shard ranges before runtime parsing'
    );
    $splitConstructionError = wp_fts_lemma_limit_caught(
        static fn(): WP_FTS_LanguageLemmaPack => WP_FTS_LanguageLemmaPack::from_manifest_file($splitOverCapManifest)
    );
    wp_fts_lemma_limit_check(
        $splitConstructionError instanceof RuntimeException
            && str_contains($splitConstructionError->getMessage(), 'strictly ordered and non-overlapping'),
        'lazy pack construction should reject a split surface instead of scanning both shards'
    );

    $streamManifest = wp_fts_lemma_limit_write_pack($root . '/stream', [
        ['path' => '0001.tsv', 'contents' => $twelveRows],
    ], false);
    $streamConstructionError = wp_fts_lemma_limit_caught(
        static fn(): WP_FTS_LanguageLemmaPack => WP_FTS_LanguageLemmaPack::from_manifest_file($streamManifest)
    );
    wp_fts_lemma_limit_check(
        $streamConstructionError instanceof RuntimeException
            && str_contains($streamConstructionError->getMessage(), 'requires a validated lookup sidecar'),
        'a non-eager plain runtime must be rejected at construction instead of enabling per-token scans'
    );
    wp_fts_lemma_limit_check(
        WP_FTS_LanguageLemmaPack::from_pack_option($streamManifest, 'qaa') === null,
        'the public custom-pack option must not bypass the non-eager sidecar requirement'
    );

    $fixtureStreamManifest = wp_fts_lemma_limit_write_pack($root . '/fixture-stream', [
        ['path' => '0001.tsv', 'contents' => $twelveRows],
    ], true);
    $fixtureStreamPack = WP_FTS_LanguageLemmaPack::from_manifest_file($fixtureStreamManifest);
    wp_fts_lemma_limit_check(
        wp_fts_lemma_limit_analysis_terms($fixtureStreamPack, 'qaasurface') === $expectedTwelve,
        'a small fixture-only plain runtime should retain eager morphology without a sidecar'
    );
    $fixtureStreamOverCapManifest = wp_fts_lemma_limit_write_pack($root . '/fixture-stream-over-cap', [
        ['path' => '0001.tsv', 'contents' => $thirteenRows],
    ], true);
    $fixtureStreamError = wp_fts_lemma_limit_caught(
        static fn(): WP_FTS_LanguageLemmaPack => WP_FTS_LanguageLemmaPack::from_manifest_file($fixtureStreamOverCapManifest)
    );
    wp_fts_lemma_limit_check(
        $fixtureStreamError instanceof RuntimeException
            && str_contains($fixtureStreamError->getMessage(), '12-lemma ambiguity limit'),
        'small fixture-only eager validation should still reject the thirteenth source lemma'
    );

    $gzipManifest = wp_fts_lemma_limit_write_pack($root . '/gzip', [
        ['path' => '0001.tsv.gz', 'contents' => $twelveRows, 'compression' => 'gzip'],
    ], true);
    $gzipPack = WP_FTS_LanguageLemmaPack::from_manifest_file($gzipManifest);
    wp_fts_lemma_limit_check(
        wp_fts_lemma_limit_analysis_terms($gzipPack, 'qaasurface') === $expectedTwelve,
        'small fixture-only gzip validation should preserve all twelve candidates eagerly'
    );
    $gzipOverCapManifest = wp_fts_lemma_limit_write_pack($root . '/gzip-over-cap', [
        ['path' => '0001.tsv.gz', 'contents' => $thirteenRows, 'compression' => 'gzip'],
    ], true);
    $gzipRuntimeError = wp_fts_lemma_limit_caught(
        static fn(): WP_FTS_LanguageLemmaPack => WP_FTS_LanguageLemmaPack::from_manifest_file($gzipOverCapManifest)
    );
    wp_fts_lemma_limit_check(
        $gzipRuntimeError instanceof RuntimeException
            && str_contains($gzipRuntimeError->getMessage(), '12-lemma ambiguity limit'),
        'small fixture-only gzip validation should reject the thirteenth source lemma eagerly'
    );

    $indexedManifest = wp_fts_lemma_limit_write_pack($root . '/indexed', [
        ['path' => '0001.tsv.gz', 'contents' => $twelveRows, 'compression' => 'gzip', 'lookup' => true],
    ], false);
    $indexedPack = WP_FTS_LanguageLemmaPack::from_manifest_file($indexedManifest);
    wp_fts_lemma_limit_check(
        wp_fts_lemma_limit_analysis_terms($indexedPack, 'qaasurface') === $expectedTwelve,
        'indexed gzip lookup should preserve the twelve-candidate boundary'
    );
    wp_fts_lemma_limit_check(
        in_array('block-index', $indexedPack->last_lookup_stats()['modes'], true),
        'indexed fixture should exercise the block-index path'
    );
    $largeBlockRows = [];
    for ($index = 0; $index < WP_FTS_LemmaPackLookupIndex::DEFAULT_BLOCK_ROWS; $index++) {
        $surface = 'qaakey' . str_pad((string) $index, 4, '0', STR_PAD_LEFT);
        $largeBlockRows[] = "{$surface}\t{$surface}";
    }
    $largeBlockManifest = wp_fts_lemma_limit_write_pack($root . '/indexed-large-block', [
        [
            'path' => '0001.tsv.gz',
            'contents' => implode("\n", $largeBlockRows) . "\n",
            'compression' => 'gzip',
            'lookup' => true,
            'block_rows' => WP_FTS_LemmaPackLookupIndex::DEFAULT_BLOCK_ROWS,
        ],
    ], false);
    $largeBlockPack = WP_FTS_LanguageLemmaPack::from_manifest_file($largeBlockManifest);
    wp_fts_lemma_limit_check(
        wp_fts_lemma_limit_analysis_terms($largeBlockPack, 'qaakey1937') === ['qaakey1937'],
        'indexed lookup should resolve a surface near the end of a full block'
    );
    wp_fts_lemma_limit_check(
        $largeBlockPack->last_lookup_stats()['lines_read'] <= 32,
        'indexed lookup should binary-search a full block instead of scanning all 2,048 rows'
    );

    $directTerms = [];
    for ($index = 0; $index < WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES; $index++) {
        $directTerms[] = 'miss' . str_pad((string) $index, 4, '0', STR_PAD_LEFT);
    }
    $directPack = WP_FTS_LanguageLemmaPack::from_manifest_file($indexedManifest, null, 'qaa');
    $directAnalyses = $directPack->analyze_many($directTerms, 'qaa');
    wp_fts_lemma_limit_check(
        count($directAnalyses) === WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES,
        'the direct lemma-pack batch API should accept exactly 4,096 distinct surfaces'
    );
    $directDigestBefore = $directPack->digest_attestation_stats();
    $directIoBefore = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
    $directOverflowError = wp_fts_lemma_limit_caught(
        static fn(): array => $directPack->analyze_many([...$directTerms, 'miss4096'], 'qaa')
    );
    wp_fts_lemma_limit_check(
        $directOverflowError instanceof WP_FTS_Analysis_Limit_Exceeded
            && $directOverflowError->reason_code === 'distinct_surfaces',
        'the direct lemma-pack batch API should reject distinct surface 4,097 with a typed limit'
    );
    wp_fts_lemma_limit_check(
        $directPack->digest_attestation_stats() === $directDigestBefore
            && WP_FTS_LemmaPackLookupIndex::io_diagnostics() === $directIoBefore,
        'direct lemma-pack surface overflow should perform zero hashes, opens, or payload reads'
    );

    $directValidation = (new WP_FTS_AnalyzerPackValidator())->validate_metadata($indexedManifest, false);
    $directRuntime = reset($directValidation['runtime_files']);
    if (!is_array($directRuntime) || !is_array($directRuntime['lookup'] ?? null)) {
        throw new RuntimeException('Direct lookup boundary fixture is missing sidecar metadata.');
    }
    $directLookup = WP_FTS_LemmaPackLookupIndex::lookup_many($directRuntime['lookup'], $directTerms);
    wp_fts_lemma_limit_check(
        count($directLookup['lemmas_by_term']) === WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES,
        'the low-level sidecar batch API should accept exactly 4,096 distinct surfaces'
    );
    $lookupIoBefore = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
    $lookupOverflowError = wp_fts_lemma_limit_caught(
        static fn(): array => WP_FTS_LemmaPackLookupIndex::lookup_many(
            $directRuntime['lookup'],
            [...$directTerms, 'miss4096']
        )
    );
    wp_fts_lemma_limit_check(
        $lookupOverflowError instanceof WP_FTS_Analysis_Limit_Exceeded
            && $lookupOverflowError->reason_code === 'distinct_surfaces',
        'the low-level sidecar batch API should reject distinct surface 4,097 with a typed limit'
    );
    wp_fts_lemma_limit_check(
        WP_FTS_LemmaPackLookupIndex::io_diagnostics() === $lookupIoBefore,
        'low-level sidecar surface overflow should perform zero opens or payload reads'
    );

    $indexedOverCapBuildError = wp_fts_lemma_limit_caught(static fn(): string => wp_fts_lemma_limit_write_pack(
        $root . '/indexed-over-cap',
        [['path' => '0001.tsv.gz', 'contents' => $thirteenRows, 'compression' => 'gzip', 'lookup' => true]],
        false
    ));
    wp_fts_lemma_limit_check(
        $indexedOverCapBuildError instanceof RuntimeException
            && str_contains($indexedOverCapBuildError->getMessage(), '12-lemma ambiguity limit'),
        'sidecar construction should reject an indexed runtime containing a thirteen-lemma surface'
    );

    $englishManifest = dirname(__DIR__, 3)
        . '/indexer/resources/analyzer-packs/en-unimorph-eng-66e0e9e8e2dc/manifest.json';
    $englishPack = WP_FTS_LanguageLemmaPack::from_manifest_file($englishManifest, null, 'en');
    wp_fts_lemma_limit_check(
        wp_fts_lemma_limit_analysis_terms($englishPack, 'best', 'en')
            === ['best', 'good', 'known', 'matching', 'well'],
        'bundled English best must retain its exact mapping and all four source alternatives'
    );
    foreach (['countable', 'uncountable'] as $surface) {
        wp_fts_lemma_limit_check(
            wp_fts_lemma_limit_analysis_terms($englishPack, $surface, 'en') === [$surface]
                && $englishPack->stem($surface, 'en') === $surface,
            "bundled English {$surface} must remain an identity no-op"
        );
    }
    $englishMetadata = json_decode((string) file_get_contents($englishManifest), true, 512, JSON_THROW_ON_ERROR);
    wp_fts_lemma_limit_check(
        ($englishMetadata['source']['parse_stats']['ambiguity_noop_surfaces'] ?? null) === 2
            && ($englishMetadata['source']['parse_stats']['ambiguity_noop_source_pairs'] ?? null) === 1954,
        'bundled English provenance must account for both over-cap surfaces and all 1,954 source pairs'
    );

    $spanishManifest = dirname(__DIR__, 3)
        . '/indexer/resources/analyzer-packs/es-unimorph-spa-b9655efb0e5c/manifest.json';
    $spanishPack = WP_FTS_LanguageLemmaPack::from_manifest_file($spanishManifest, null, 'es');
    foreach (['audio', 'file'] as $surface) {
        wp_fts_lemma_limit_check(
            wp_fts_lemma_limit_analysis_terms($spanishPack, $surface, 'es') === [$surface]
                && $spanishPack->stem($surface, 'es') === $surface,
            "bundled Spanish {$surface} must remain an identity no-op"
        );
    }
    $spanishMetadata = json_decode((string) file_get_contents($spanishManifest), true, 512, JSON_THROW_ON_ERROR);
    wp_fts_lemma_limit_check(
        ($spanishMetadata['source']['parse_stats']['ambiguity_noop_surfaces'] ?? null) === 2
            && ($spanishMetadata['source']['parse_stats']['ambiguity_noop_source_pairs'] ?? null) === 36,
        'bundled Spanish provenance must account for both over-cap surfaces and all 36 source pairs'
    );
} finally {
    wp_fts_lemma_limit_remove_tree($root);
}

return $wp_fts_lemma_limit_checks;
