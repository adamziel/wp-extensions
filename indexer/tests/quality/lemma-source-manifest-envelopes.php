<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tools/lemma-source-import-limits.php';

test_case('bundled source-backed lemma manifests fit every importer envelope', function (): void {
    $manifests = glob(dirname(__DIR__, 2) . '/resources/analyzer-packs/*/manifest.json');
    assert_true(is_array($manifests) && $manifests !== [], 'bundled lemma manifest envelope audit should discover manifests');
    foreach ($manifests as $manifestPath) {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $source = is_array($manifest['source'] ?? null) ? $manifest['source'] : [];
        $stats = is_array($source['parse_stats'] ?? null) ? $source['parse_stats'] : [];
        $label = basename(dirname($manifestPath));
        if (is_int($source['byte_count'] ?? null)) {
            assert_true(
                $source['byte_count'] <= WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_PHYSICAL_BYTES,
                "{$label} original source bytes should fit the physical importer envelope"
            );
        }
        if (is_int($stats['source_physical_bytes'] ?? null)) {
            assert_true(
                $stats['source_physical_bytes'] <= WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_PHYSICAL_BYTES,
                "{$label} recorded physical bytes should fit the importer envelope"
            );
        }
        if (is_int($stats['source_decoded_bytes'] ?? null)) {
            assert_true(
                $stats['source_decoded_bytes'] <= WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_DECODED_BYTES,
                "{$label} recorded decoded bytes should fit the importer envelope"
            );
        }
        if (is_int($stats['source_lines'] ?? null)) {
            assert_true(
                $stats['source_lines'] <= WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_LINES,
                "{$label} recorded source lines should fit the importer envelope"
            );
        }
        if (is_int($stats['accepted_rows'] ?? null)) {
            assert_true(
                $stats['accepted_rows'] <= WP_FTS_LemmaSourceImportLimits::MAX_STAGED_ROWS,
                "{$label} recorded wrapper rows should fit the staging envelope"
            );
        }
        if (is_int($stats['staged_tsv_bytes'] ?? null)) {
            assert_true(
                $stats['staged_tsv_bytes'] <= WP_FTS_LemmaSourceImportLimits::MAX_STAGED_TSV_BYTES,
                "{$label} recorded staged TSV should fit the staging envelope"
            );
        }
        if (is_int($stats['accepted_source_rows'] ?? null)) {
            assert_true(
                $stats['accepted_source_rows'] <= WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_LINES,
                "{$label} accepted source rows should fit within the global line envelope"
            );
        }
    }

    $spanish = json_decode((string) file_get_contents(
        dirname(__DIR__, 2) . '/resources/analyzer-packs/es-unimorph-spa-b9655efb0e5c/manifest.json'
    ), true, 512, JSON_THROW_ON_ERROR);
    assert_same(50335761, $spanish['source']['byte_count'] ?? null, 'pinned Spanish source should retain its measured physical size');
    assert_same(1196245, $spanish['source']['parse_stats']['source_lines'] ?? null, 'pinned Spanish source should retain its measured line count');
    assert_same(1162505, $spanish['source']['parse_stats']['accepted_rows'] ?? null, 'pinned Spanish source should fit beneath the 1.25-million staging limit');

    $polimorf = json_decode((string) file_get_contents(
        dirname(__DIR__, 2) . '/resources/analyzer-packs/pl-polimorf-20180722-full-playground/manifest.json'
    ), true, 512, JSON_THROW_ON_ERROR);
    assert_same(41550540, $polimorf['source']['byte_count'] ?? null, 'pinned PoliMorf source should retain its measured physical size');
    assert_same(7374578, $polimorf['source']['parse_stats']['source_lines'] ?? null, 'pinned PoliMorf source should fit beneath the eight-million-line limit');
});
