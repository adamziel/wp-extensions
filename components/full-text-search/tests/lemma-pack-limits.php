<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

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
 * @param array<int,array{path:string,contents:string,block_rows?:int}> $files
 */
function wp_fts_lemma_limit_write_pack(
    string $directory,
    array $files,
    string $language = 'qaa'
): string
{
    mkdir($directory . '/runtime', 0777, true);
    file_put_contents($directory . '/NOTICE.txt', "Synthetic lemma-cap test fixture.\n");
    $runtimeFiles = [];
    $totalDigest = hash_init('sha256');
    $totalRows = 0;

    foreach ($files as $file) {
        $relativePath = 'runtime/' . $file['path'];
        $path = $directory . '/' . $relativePath;
        $contents = $file['contents'];
        $rows = array_values(array_filter(explode("\n", $contents), static fn(string $line): bool => $line !== ''));
        foreach ($rows as $row) {
            hash_update($totalDigest, $row . "\n");
        }
        $totalRows += count($rows);

        $encoded = gzencode($contents, 9, ZLIB_ENCODING_GZIP);
        if (!is_string($encoded)) {
            throw new RuntimeException('Could not encode a lemma-cap gzip fixture.');
        }
        file_put_contents($path, $encoded);
        $runtimeSha256 = hash_file('sha256', $path);
        if (!is_string($runtimeSha256)) {
            throw new RuntimeException('Could not hash a lemma-cap gzip fixture.');
        }
        $lookupPath = $path . '.lookup';
        $lookup = WP_FTS_LemmaPackLookupIndex::build(
            $path,
            $runtimeSha256,
            $lookupPath,
            (int) ($file['block_rows'] ?? 2)
        );
        $entry = [
            'path' => $relativePath,
            'sha256' => $lookup['runtime_sha256'],
            'rows' => count($rows),
            'first_surface' => explode("\t", $rows[0], 2)[0],
            'last_surface' => explode("\t", $rows[count($rows) - 1], 2)[0],
            'compression' => 'gzip',
            'lookup' => [
                'format' => $lookup['format'],
                'path' => $relativePath . '.lookup',
                'sha256' => $lookup['sha256'],
                'blocks' => $lookup['blocks'],
            ],
        ];
        $runtimeFiles[] = $entry;
    }

    $manifest = [
        'schema_version' => 1,
        'pack_id' => 'qaa-lemma-cap-' . basename($directory),
        'language' => $language,
        'version' => 'test-v1',
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
            'no_full_third_party_dictionary_dump' => true,
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

    $indexedManifest = wp_fts_lemma_limit_write_pack($root . '/indexed', [
        ['path' => '0001.tsv.gz', 'contents' => $twelveRows],
    ]);
    $indexedManifestData = json_decode(
        (string) file_get_contents($indexedManifest),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $discovery = new ReflectionMethod(
        WP_FTS_AnalyzerPackValidator::class,
        'validated_manifest_paths_by_language'
    );
    $discovery->setAccessible(true);
    wp_fts_lemma_limit_check(
        $discovery->invoke(null, [$indexedManifest]) === ['qaa' => $indexedManifest],
        'bundled-pack discovery must retain a manifest only after strict shared validation'
    );

    mkdir($root . '/malformed-discovery', 0777, true);
    $malformedDiscoveryManifest = $root . '/malformed-discovery/manifest.json';
    file_put_contents($malformedDiscoveryManifest, "{\n");
    $malformedDiscoveryError = wp_fts_lemma_limit_caught(
        static fn(): array => $discovery->invoke(null, [$malformedDiscoveryManifest])
    );
    wp_fts_lemma_limit_check(
        $malformedDiscoveryError instanceof RuntimeException,
        'bundled-pack discovery must fail instead of hiding malformed manifest JSON'
    );

    $duplicateManifest = wp_fts_lemma_limit_write_pack($root . '/duplicate-language', [
        ['path' => '0001.tsv.gz', 'contents' => "qaasurface\tqaasurface\n"],
    ]);
    $duplicateDiscoveryError = wp_fts_lemma_limit_caught(
        static fn(): array => $discovery->invoke(null, [$indexedManifest, $duplicateManifest])
    );
    wp_fts_lemma_limit_check(
        $duplicateDiscoveryError instanceof RuntimeException
            && str_contains($duplicateDiscoveryError->getMessage(), 'duplicate language qaa'),
        'bundled-pack discovery must reject duplicate canonical languages instead of keeping the last path'
    );

    $shapeCases = [];
    $candidate = $indexedManifestData;
    $candidate['unsupported'] = true;
    $shapeCases['root object'] = [$candidate, 'unsupported field'];
    $candidate = $indexedManifestData;
    $candidate['pack_id'] = ' qaa-indexed-limit';
    $shapeCases['padded pack id'] = [$candidate, 'unpadded non-empty string'];
    $candidate = $indexedManifestData;
    $candidate['version'] = "fixture-v1\n";
    $shapeCases['padded version'] = [$candidate, 'unpadded non-empty string'];
    $candidate = $indexedManifestData;
    $candidate['runtime']['unsupported'] = true;
    $shapeCases['runtime object'] = [$candidate, 'unsupported field'];
    $candidate = $indexedManifestData;
    $candidate['source']['unsupported'] = true;
    $shapeCases['source object'] = [$candidate, 'unsupported field'];
    $candidate = $indexedManifestData;
    $candidate['license']['unsupported'] = true;
    $shapeCases['license object'] = [$candidate, 'unsupported field'];
    $candidate = $indexedManifestData;
    $candidate['attribution']['unsupported'] = true;
    $shapeCases['attribution object'] = [$candidate, 'unsupported field'];
    $candidate = $indexedManifestData;
    $candidate['provenance']['unsupported'] = true;
    $shapeCases['provenance object'] = [$candidate, 'unsupported field'];
    $candidate = $indexedManifestData;
    $candidate['source']['file'] = 1;
    $shapeCases['source optional string'] = [$candidate, 'source file must be an unpadded nonempty string'];
    $candidate = $indexedManifestData;
    $candidate['license']['notice_required'] = 1;
    $shapeCases['license optional boolean'] = [$candidate, 'notice_required must be a boolean'];
    $candidate = $indexedManifestData;
    $candidate['attribution']['upstream'] = 1;
    $shapeCases['attribution sibling type'] = [$candidate, 'attribution upstream must be an unpadded nonempty string'];
    $candidate = $indexedManifestData;
    $candidate['provenance']['importer'] = 1;
    $shapeCases['provenance optional string'] = [$candidate, 'provenance importer must be an unpadded nonempty string'];
    $candidate = $indexedManifestData;
    $candidate['source']['files'] = [[
        'path' => 'source.tsv',
        'sha256' => str_repeat('a', 64),
        'byte_count' => 1,
        'unsupported' => true,
    ]];
    $shapeCases['source file object'] = [$candidate, 'unsupported field'];
    $candidate = $indexedManifestData;
    $candidate['source']['column_model'] = [
        'format' => 'normalized-lemma-tsv-v1',
        'surface_column' => 0,
        'lemma_column' => 1,
        'tag_column' => 2,
        'source_note_column' => 3,
        'unsupported' => 4,
    ];
    $shapeCases['source column model'] = [$candidate, 'unsupported field'];
    $candidate = $indexedManifestData;
    $candidate['source']['parse_stats'] = ['unsupported' => 1];
    $shapeCases['source parse stats'] = [$candidate, 'unsupported field'];
    $candidate = $indexedManifestData;
    $candidate['runtime']['normalization'] = 'some normalizer';
    $shapeCases['mismatched normalization contract'] = [$candidate, 'WP_FTS_Normalizer qaa with fold_diacritics=true'];
    $candidate = $indexedManifestData;
    $candidate['source']['artifact_sha256'] = strtoupper($candidate['source']['artifact_sha256']);
    $shapeCases['uppercase source digest'] = [$candidate, 'lowercase 64-character hex digest'];
    $candidate = $indexedManifestData;
    $candidate['runtime']['total_sha256'] = strtoupper($candidate['runtime']['total_sha256']);
    $shapeCases['uppercase aggregate digest'] = [$candidate, 'lowercase 64-character hex digest'];
    $candidate = $indexedManifestData;
    $candidate['runtime']['files'][0]['sha256'] = strtoupper($candidate['runtime']['files'][0]['sha256']);
    $shapeCases['uppercase runtime digest'] = [$candidate, 'lowercase 64-character hex digest'];
    $candidate = $indexedManifestData;
    $candidate['runtime']['files'][0]['lookup']['sha256'] = strtoupper(
        $candidate['runtime']['files'][0]['lookup']['sha256']
    );
    $shapeCases['uppercase lookup digest'] = [$candidate, 'lowercase 64-character hex digest'];
    $candidate = $indexedManifestData;
    $candidate['runtime']['files'][0]['unsupported'] = true;
    $shapeCases['runtime file object'] = [$candidate, 'unsupported field'];
    $candidate = $indexedManifestData;
    $candidate['runtime']['files'][0]['path'] .= ' ';
    $shapeCases['padded runtime path'] = [$candidate, 'relative unpadded non-empty string'];
    $candidate = $indexedManifestData;
    $candidate['runtime']['files'][0]['first_surface'] = null;
    $shapeCases['nonnative first surface'] = [$candidate, 'unpadded non-empty string'];
    $candidate = $indexedManifestData;
    $candidate['runtime']['files'][0]['last_surface'] = null;
    $shapeCases['nonnative last surface'] = [$candidate, 'unpadded non-empty string'];
    $candidate = $indexedManifestData;
    $candidate['runtime']['files'][0]['lookup']['unsupported'] = true;
    $shapeCases['lookup object'] = [$candidate, 'unsupported field'];
    $candidate = $indexedManifestData;
    $candidate['runtime']['files'][0]['lookup']['path'] .= "\n";
    $shapeCases['padded lookup path'] = [$candidate, 'relative unpadded non-empty string'];
    $candidate = $indexedManifestData;
    unset($candidate['runtime']['total_rows']);
    $shapeCases['missing aggregate row count'] = [$candidate, 'total_rows is required'];
    $candidate = $indexedManifestData;
    unset($candidate['runtime']['total_sha256']);
    $shapeCases['missing aggregate digest'] = [$candidate, 'total_sha256 is required'];
    $candidate = $indexedManifestData;
    $candidate['runtime']['total_rows']++;
    $shapeCases['mismatched aggregate row count'] = [$candidate, 'total_rows mismatch'];
    $candidate = $indexedManifestData;
    $candidate['runtime']['files'] = ['primary' => $candidate['runtime']['files'][0]];
    $shapeCases['associative runtime file collection'] = [$candidate, 'must list runtime files'];
    $candidate = $indexedManifestData;
    $candidate['language'] = 'QAA';
    $shapeCases['noncanonical language'] = [$candidate, 'must already be canonical'];
    $candidate = $indexedManifestData;
    $candidate['language'] = 'qaa ';
    $shapeCases['padded language'] = [$candidate, 'must be a valid language tag'];
    $candidate = $indexedManifestData;
    $candidate['language'] = 'en-a';
    $shapeCases['malformed language'] = [$candidate, 'must be a valid language tag'];
    $candidate = $indexedManifestData;
    $candidate['capabilities'] = ['primary' => 'dictionary-lemmatizer'];
    $shapeCases['associative capabilities'] = [$candidate, 'must be a string list'];
    $candidate = $indexedManifestData;
    $candidate['capabilities'][] = 1;
    $shapeCases['nonnative capability'] = [$candidate, 'unpadded nonempty strings'];
    $candidate = $indexedManifestData;
    $candidate['capabilities'][] = ' padded-capability';
    $shapeCases['padded capability'] = [$candidate, 'unpadded nonempty strings'];
    $candidate = $indexedManifestData;
    $candidate['capabilities'][] = 'dictionary-lemmatizer';
    $shapeCases['duplicate capability'] = [$candidate, 'must not contain duplicates'];
    $candidate = $indexedManifestData;
    $candidate['provenance']['no_runtime_network_access'] = 1;
    $shapeCases['coercible network-access declaration'] = [$candidate, 'must declare no runtime network access'];
    foreach ($shapeCases as $description => [$candidate, $message]) {
        $candidatePath = dirname($indexedManifest) . '/invalid-' . count($shapeCases) . '-' . sha1($description) . '.json';
        file_put_contents(
            $candidatePath,
            json_encode($candidate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
        $candidateValidator = new WP_FTS_AnalyzerPackValidator();
        $candidateError = wp_fts_lemma_limit_caught(
            static fn(): array => $candidateValidator->resource_envelope($candidatePath)
        );
        wp_fts_lemma_limit_check(
            $candidateError instanceof RuntimeException
                && str_contains($candidateError->getMessage(), $message),
            "a v1 analyzer-pack {$description} must reject before resource inspection"
        );
        wp_fts_lemma_limit_check(
            $candidateValidator->digest_attestation_stats() === ['files_hashed' => 0, 'bytes_hashed' => 0],
            "a v1 analyzer-pack {$description} must not hash a runtime file"
        );
        unlink($candidatePath);
    }

    foreach ([
        'surface' => " qaaform\tqaalemma\n",
        'lemma' => "qaaform\tqaalemma \n",
    ] as $column => $runtimeContents) {
        $paddedManifest = wp_fts_lemma_limit_write_pack(
            $root . '/padded-' . $column,
            [['path' => '0001.tsv.gz', 'contents' => $runtimeContents]]
        );
        $paddedManifestData = json_decode(
            (string) file_get_contents($paddedManifest),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $paddedManifestData['runtime']['files'][0]['first_surface'] = 'qaaform';
        $paddedManifestData['runtime']['files'][0]['last_surface'] = 'qaaform';
        file_put_contents(
            $paddedManifest,
            json_encode($paddedManifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
        $paddedError = wp_fts_lemma_limit_caught(
            static fn(): array => (new WP_FTS_AnalyzerPackValidator())->validate($paddedManifest)
        );
        wp_fts_lemma_limit_check(
            $paddedError instanceof RuntimeException
                && str_contains($paddedError->getMessage(), "Runtime {$column}")
                && str_contains($paddedError->getMessage(), 'must be one normalized token'),
            "a padded runtime {$column} must reject without trimming"
        );
    }

    $indexedPack = WP_FTS_LanguageLemmaPack::from_manifest_file($indexedManifest);
    wp_fts_lemma_limit_check(
        wp_fts_lemma_limit_analysis_terms($indexedPack, 'qaasurface', 'qab') === ['qaasurface'],
        'a pack must not expand a surface from another language partition'
    );
    wp_fts_lemma_limit_check(
        wp_fts_lemma_limit_analysis_terms($indexedPack, 'qaasurface') === $expectedTwelve,
        'indexed gzip lookup should preserve exact-first order at the twelve-candidate boundary'
    );
    wp_fts_lemma_limit_check(
        in_array('block-index', $indexedPack->last_lookup_stats()['modes'], true),
        'the synthetic pack should exercise the block-index path'
    );
    wp_fts_lemma_limit_check(
        WP_FTS_LanguageLemmaPack::manifest_path_from_option($indexedManifest) === $indexedManifest
            && WP_FTS_LanguageLemmaPack::manifest_path_from_option(false) === null,
        'lemma-pack options should be an exact manifest path or false'
    );
    $invalidPackOptionError = wp_fts_lemma_limit_caught(
        static fn(): ?string => WP_FTS_LanguageLemmaPack::manifest_path_from_option([
            'manifest_path' => $indexedManifest,
        ])
    );
    $booleanPackOptionError = wp_fts_lemma_limit_caught(
        static fn(): ?string => WP_FTS_LanguageLemmaPack::manifest_path_from_option(true)
    );
    $nullPackOptionError = wp_fts_lemma_limit_caught(
        static fn(): ?string => WP_FTS_LanguageLemmaPack::manifest_path_from_option(null)
    );
    wp_fts_lemma_limit_check(
        $invalidPackOptionError instanceof InvalidArgumentException
            && $booleanPackOptionError instanceof InvalidArgumentException
            && $nullPackOptionError instanceof InvalidArgumentException,
        'lemma-pack options should reject path aliases, enable flags, and null'
    );

    foreach ([' qaa', 'q!a'] as $invalidLanguage) {
        foreach ([
            static fn(): string => $indexedPack->stem('qaasurface', $invalidLanguage),
            static fn(): array => $indexedPack->analyze('qaasurface', $invalidLanguage),
            static fn(): array => $indexedPack->analyze_many(['qaasurface'], $invalidLanguage),
            static fn(): array => $indexedPack->analyze_many_for_pipeline(
                ['qaasurface'],
                $invalidLanguage,
                1,
                static fn(string $_surface, string $_lemma): bool => true,
                static fn(string $_surface, int $_count): bool => false
            ),
        ] as $call) {
            wp_fts_lemma_limit_check(
                wp_fts_lemma_limit_caught($call) instanceof InvalidArgumentException,
                'direct lemma-pack analysis should reject malformed or noncanonical languages'
            );
        }
    }

    $admission = new WP_FTS_ConfiguredLemmaPackAdmission();
    wp_fts_lemma_limit_check(
        wp_fts_lemma_limit_caught(
            static fn(): array => $admission->preflight_manifest($indexedManifest, ' qaa')
        ) instanceof InvalidArgumentException,
        'configured pack admission should reject a padded language before manifest work'
    );
    wp_fts_lemma_limit_check(
        wp_fts_lemma_limit_caught(
            static fn(): array => $admission->preflight_manifest(' ' . $indexedManifest, 'qaa')
        ) instanceof InvalidArgumentException,
        'configured pack admission should reject a padded manifest path before resolution'
    );

    $corruptRuntimeManifest = wp_fts_lemma_limit_write_pack($root . '/corrupt-runtime', [
        ['path' => '0001.tsv.gz', 'contents' => "qaacorrupt\tqaapacklemma\n"],
    ]);
    $corruptRuntimeMetadata = json_decode(
        (string) file_get_contents($corruptRuntimeManifest),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $corruptRuntimePath = dirname($corruptRuntimeManifest)
        . '/' . (string) $corruptRuntimeMetadata['runtime']['files'][0]['path'];
    $corruptRuntimeBytes = file_get_contents($corruptRuntimePath);
    if (!is_string($corruptRuntimeBytes) || strlen($corruptRuntimeBytes) < 16) {
        throw new RuntimeException('Could not read the indexed corruption fixture.');
    }
    $corruptRuntimeBytes[15] = chr(ord($corruptRuntimeBytes[15]) ^ 1);
    file_put_contents($corruptRuntimePath, $corruptRuntimeBytes);
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
        'a preflight-valid pack with corrupt runtime bytes should construct before its first payload read'
    );
    $corruptLookupError = wp_fts_lemma_limit_caught(
        static fn(): array => $corruptPipeline->analyze_detailed('qaacorrupt', 'qaa')
    );
    wp_fts_lemma_limit_check(
        wp_fts_lemma_limit_error_contains($corruptLookupError, 'integrity verification failed'),
        'a corrupt configured pack should stop analysis at its first payload read'
    );

    $detectionPipeline = new WP_FTS_LanguagePipeline([
        'lemma_packs_by_lang' => ['qaa' => $indexedManifest],
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
        ['path' => '0001.tsv.gz', 'contents' => $runtimeRows(array_slice($thirteenLemmas, 0, 6), false)],
        ['path' => '0002.tsv.gz', 'contents' => $runtimeRows(array_slice($thirteenLemmas, 6), false)],
    ]);
    $splitValidationError = wp_fts_lemma_limit_caught(
        static fn(): array => (new WP_FTS_AnalyzerPackValidator())->validate($splitOverCapManifest)
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
        'pack construction should reject a surface split across shards'
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
            'block_rows' => WP_FTS_LemmaPackLookupIndex::DEFAULT_BLOCK_ROWS,
        ],
    ]);
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
        [['path' => '0001.tsv.gz', 'contents' => $thirteenRows]]
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
