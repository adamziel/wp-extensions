<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tools/import-conllu-lemma-pack.php';
require_once dirname(__DIR__, 2) . '/tools/import-unimorph-lemma-pack.php';
require_once dirname(__DIR__, 2) . '/tools/import-polish-polimorf-lemmatizer.php';
require_once dirname(__DIR__, 2) . '/tools/build-polish-polimorf-external-pack.php';

$case = $argv[1] ?? '';
try {
    $payload = match ($case) {
        'line-plain' => wp_fts_importer_line_case(false),
        'line-gzip' => wp_fts_importer_line_case(true),
        'chunk-generic' => wp_fts_importer_chunk_case(false),
        'chunk-polimorf' => wp_fts_importer_chunk_case(true),
        'fanin-generic' => wp_fts_importer_fan_in_case(false),
        'fanin-polimorf' => wp_fts_importer_fan_in_case(true),
        'chunk-row-boundary' => wp_fts_importer_chunk_row_boundary_case(),
        'chunk-file-boundary' => wp_fts_importer_chunk_file_boundary_case(),
        'physical-generic' => wp_fts_importer_physical_cap_case(false),
        'physical-polimorf' => wp_fts_importer_physical_cap_case(true),
        'plain-fixture' => wp_fts_importer_plain_fixture_case(),
        'path-safety' => wp_fts_importer_path_safety_case(),
        'temp-symlink-cleanup' => wp_fts_importer_temp_symlink_cleanup_case(),
        'invalid-temp-parent' => wp_fts_importer_invalid_temp_parent_case(),
        'polimorf-notice-cap' => wp_fts_importer_polimorf_notice_cap_case(),
        'source-line-boundary' => wp_fts_importer_source_envelope_case('line'),
        'source-physical-boundary' => wp_fts_importer_source_envelope_case('physical'),
        'source-decoded-boundary' => wp_fts_importer_source_envelope_case('decoded'),
        'source-count-boundary' => wp_fts_importer_source_envelope_case('count'),
        'source-hash-race' => wp_fts_importer_source_hash_race_case(),
        'source-snapshot-swap' => wp_fts_importer_source_snapshot_swap_case(),
        default => throw new InvalidArgumentException('Expected a lemma importer containment case.'),
    };
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}

/** @return array<string,mixed> */
function wp_fts_importer_path_safety_case(): array
{
    $started = microtime(true);
    $root = wp_fts_importer_fixture_root('path-safety');
    try {
        $target = $root . '/external-target';
        mkdir($target);
        mkdir($target . '/runtime');
        file_put_contents($target . '/manifest.json', "{}\n");
        file_put_contents($target . '/SOURCE.lock.json', "{}\n");
        file_put_contents($target . '/sentinel', "external target must survive\n");
        $before = wp_fts_importer_tree_digest($target);
        $rootSymlinkResults = [];
        foreach (['generic', 'conllu', 'unimorph', 'polimorf', 'external-builder'] as $kind) {
            $source = $root . '/source-' . $kind . wp_fts_importer_source_extension($kind);
            file_put_contents($source, wp_fts_importer_valid_source_row($kind));
            $out = $root . '/out-' . $kind;
            symlink($target, $out);
            $failure = null;
            try {
                if ($kind === 'external-builder') {
                    (new WP_FTS_PolishPolimorfExternalPackBuilder())->build([
                        'source' => $source,
                        'out' => $out,
                        'allow_repo_output' => true,
                        'replace_output' => true,
                    ]);
                } else {
                    wp_fts_importer_run($kind, $source, $out);
                }
            } catch (Throwable $caught) {
                $failure = $caught;
            }
            $rootSymlinkResults[$kind] = [
                'class' => $failure instanceof Throwable ? get_class($failure) : null,
                'message' => $failure instanceof Throwable ? $failure->getMessage() : null,
                'link_retained' => is_link($out),
                'target_digest' => wp_fts_importer_tree_digest($target),
            ];
            unlink($out);
        }

        $overlapResults = [];
        foreach (['generic', 'conllu', 'unimorph', 'polimorf', 'external-builder'] as $kind) {
            $out = $root . '/overlap-' . $kind;
            mkdir($out);
            if ($kind === 'conllu' || $kind === 'unimorph') {
                $source = $out;
                file_put_contents($source . '/source' . wp_fts_importer_source_extension($kind), wp_fts_importer_valid_source_row($kind));
            } else {
                $source = $out . '/source' . wp_fts_importer_source_extension($kind);
                file_put_contents($source, wp_fts_importer_valid_source_row($kind));
            }
            $sourceBefore = hash_file('sha256', is_dir($source) ? $source . '/source' . wp_fts_importer_source_extension($kind) : $source);
            $failure = null;
            try {
                if ($kind === 'external-builder') {
                    (new WP_FTS_PolishPolimorfExternalPackBuilder())->build([
                        'source' => $source,
                        'out' => $out,
                        'allow_repo_output' => true,
                        'replace_output' => true,
                    ]);
                } else {
                    wp_fts_importer_run($kind, $source, $out);
                }
            } catch (Throwable $caught) {
                $failure = $caught;
            }
            $sourceFile = is_dir($source) ? $source . '/source' . wp_fts_importer_source_extension($kind) : $source;
            $overlapResults[$kind] = [
                'class' => $failure instanceof Throwable ? get_class($failure) : null,
                'message' => $failure instanceof Throwable ? $failure->getMessage() : null,
                'source_retained' => is_file($sourceFile),
                'source_digest' => is_file($sourceFile) ? hash_file('sha256', $sourceFile) : null,
                'source_digest_before' => $sourceBefore,
            ];
        }

        return wp_fts_importer_process_evidence($started) + [
            'case' => 'path-safety',
            'target_digest_before' => $before,
            'target_digest_after' => wp_fts_importer_tree_digest($target),
            'root_symlinks' => $rootSymlinkResults,
            'overlaps' => $overlapResults,
        ];
    } finally {
        wp_fts_importer_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_importer_temp_symlink_cleanup_case(): array
{
    $started = microtime(true);
    $root = wp_fts_importer_fixture_root('temp-symlink');
    try {
        $target = $root . '/external-target';
        mkdir($target);
        file_put_contents($target . '/sentinel', "temporary cleanup must not follow this link\n");
        $targetDigest = wp_fts_importer_tree_digest($target);
        $results = [];
        foreach (['generic', 'conllu', 'unimorph', 'polimorf'] as $kind) {
            $source = $root . '/source-' . $kind . wp_fts_importer_source_extension($kind);
            wp_fts_importer_write_cleanup_rows($source, $kind, 5000);
            $out = $root . '/pack-' . $kind;
            $tmpParent = $root . '/tmp-' . $kind;
            mkdir($tmpParent);
            file_put_contents($tmpParent . '/sentinel', "caller-owned temp parent\n");
            $watcher = wp_fts_importer_start_symlink_watcher($tmpParent, $target);
            $failure = null;
            try {
                wp_fts_importer_run($kind, $source, $out, [
                    'tmp_dir' => $tmpParent,
                    'chunk_rows' => 1,
                    'max_rows_per_file' => 100000,
                ]);
            } catch (Throwable $caught) {
                $failure = $caught;
            }
            $watcherEvidence = wp_fts_importer_stop_symlink_watcher($watcher);
            $results[$kind] = [
                'class' => $failure instanceof Throwable ? get_class($failure) : null,
                'message' => $failure instanceof Throwable ? $failure->getMessage() : null,
                'manifest_written' => is_file($out . '/manifest.json'),
                'caller_sentinel_retained' => is_file($tmpParent . '/sentinel'),
                'tmp_entries' => array_values(array_diff(scandir($tmpParent) ?: [], ['.', '..', 'sentinel'])),
                'inserted_links' => $watcherEvidence['inserted_links'],
                'watcher_stderr' => $watcherEvidence['stderr'],
                'target_digest' => wp_fts_importer_tree_digest($target),
            ];
        }

        return wp_fts_importer_process_evidence($started) + [
            'case' => 'temp-symlink-cleanup',
            'target_digest_before' => $targetDigest,
            'target_digest_after' => wp_fts_importer_tree_digest($target),
            'results' => $results,
        ];
    } finally {
        wp_fts_importer_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_importer_invalid_temp_parent_case(): array
{
    $started = microtime(true);
    $root = wp_fts_importer_fixture_root('invalid-temp-parent');
    try {
        $results = [];
        foreach (['generic', 'conllu', 'unimorph', 'polimorf'] as $kind) {
            $source = $root . '/source-' . $kind . wp_fts_importer_source_extension($kind);
            file_put_contents($source, wp_fts_importer_valid_source_row($kind));
            $out = $root . '/out-' . $kind;
            $tmpParent = $root . '/tmp-parent-' . $kind;
            file_put_contents($tmpParent, "caller-owned non-directory\n");
            $before = hash_file('sha256', $tmpParent);
            $failure = null;
            try {
                wp_fts_importer_run($kind, $source, $out, ['tmp_dir' => $tmpParent]);
            } catch (Throwable $caught) {
                $failure = $caught;
            }
            $results[$kind] = [
                'class' => $failure instanceof Throwable ? get_class($failure) : null,
                'message' => $failure instanceof Throwable ? $failure->getMessage() : null,
                'output_exists' => file_exists($out) || is_link($out),
                'tmp_parent_retained' => is_file($tmpParent),
                'tmp_parent_sha256' => is_file($tmpParent) ? hash_file('sha256', $tmpParent) : null,
                'tmp_parent_sha256_before' => $before,
            ];
        }

        return wp_fts_importer_process_evidence($started) + [
            'case' => 'invalid-temp-parent',
            'results' => $results,
        ];
    } finally {
        wp_fts_importer_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_importer_polimorf_notice_cap_case(): array
{
    $started = microtime(true);
    $root = wp_fts_importer_fixture_root('polimorf-notice');
    try {
        $results = [];
        foreach ([64, 65] as $lines) {
            $source = $root . '/metadata-' . $lines . '.tsv';
            $handle = fopen($source, 'wb');
            if (!is_resource($handle)) {
                throw new RuntimeException('Could not create PoliMorf metadata boundary source.');
            }
            for ($index = 0; $index < $lines; $index++) {
                fwrite($handle, "metadata {$index}\n");
            }
            fwrite($handle, "surface\tlemma\ttag\t\t\n");
            fclose($handle);
            $out = $root . '/pack-' . $lines;
            $failure = null;
            $summary = null;
            try {
                $summary = wp_fts_importer_run('polimorf', $source, $out);
            } catch (Throwable $caught) {
                $failure = $caught;
            }
            $results[(string) $lines] = [
                'class' => $failure instanceof Throwable ? get_class($failure) : null,
                'message' => $failure instanceof Throwable ? $failure->getMessage() : null,
                'manifest_written' => is_file($out . '/manifest.json'),
                'metadata_lines' => is_array($summary) ? ($summary['stats']['metadata_lines'] ?? null) : null,
                'notice_metadata_bytes' => is_array($summary) ? ($summary['stats']['notice_metadata_bytes'] ?? null) : null,
            ];
        }
        foreach ([65536, 65537] as $bytes) {
            $source = $root . '/metadata-bytes-' . $bytes . '.tsv';
            $metadata = str_repeat('m', $bytes - 1) . "\n";
            file_put_contents($source, $metadata . "surface\tlemma\ttag\t\t\n");
            $out = $root . '/pack-bytes-' . $bytes;
            $failure = null;
            $summary = null;
            try {
                $summary = wp_fts_importer_run('polimorf', $source, $out);
            } catch (Throwable $caught) {
                $failure = $caught;
            }
            $results['bytes-' . $bytes] = [
                'class' => $failure instanceof Throwable ? get_class($failure) : null,
                'message' => $failure instanceof Throwable ? $failure->getMessage() : null,
                'manifest_written' => is_file($out . '/manifest.json'),
                'metadata_lines' => is_array($summary) ? ($summary['stats']['metadata_lines'] ?? null) : null,
                'notice_metadata_bytes' => is_array($summary) ? ($summary['stats']['notice_metadata_bytes'] ?? null) : null,
            ];
        }

        return wp_fts_importer_process_evidence($started) + [
            'case' => 'polimorf-notice-cap',
            'line_limit' => 64,
            'byte_limit' => 65536,
            'results' => $results,
        ];
    } finally {
        wp_fts_importer_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_importer_source_envelope_case(string $boundary): array
{
    $started = microtime(true);
    $root = wp_fts_importer_fixture_root('source-' . $boundary);
    try {
        $results = [];
        foreach (['generic', 'conllu', 'unimorph', 'polimorf'] as $kind) {
            $extension = wp_fts_importer_source_extension($kind);
            $source = $root . '/source-' . $kind . $extension . ($boundary === 'decoded' ? '.gz' : '');
            if ($boundary === 'line') {
                wp_fts_importer_write_line_boundary_source(
                    $source,
                    $kind,
                    WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_LINE_BYTES
                );
            } elseif ($boundary === 'count') {
                wp_fts_importer_write_line_count_source(
                    $source,
                    $kind,
                    WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_LINES
                );
            } else {
                wp_fts_importer_write_byte_envelope_source(
                    $source,
                    $kind,
                    $boundary === 'physical'
                        ? WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_PHYSICAL_BYTES
                        : WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_DECODED_BYTES,
                    $boundary === 'decoded'
                );
            }

            $exact = wp_fts_importer_capture_run($kind, $source, $root . '/out-' . $kind . '-exact');
            if ($boundary === 'line') {
                wp_fts_importer_write_line_boundary_source(
                    $source,
                    $kind,
                    WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_LINE_BYTES + 1
                );
            } elseif ($boundary === 'decoded') {
                $append = gzopen($source, 'ab1');
                if (!is_resource($append) || gzwrite($append, 'x') !== 1) {
                    throw new RuntimeException('Could not append the first decoded byte above the source envelope.');
                }
                gzclose($append);
            } else {
                $append = $boundary === 'count' ? "\n" : 'x';
                if (file_put_contents($source, $append, FILE_APPEND) !== strlen($append)) {
                    throw new RuntimeException('Could not append the first source unit above the envelope.');
                }
            }
            $over = wp_fts_importer_capture_run($kind, $source, $root . '/out-' . $kind . '-over');
            $results[$kind] = ['exact' => $exact, 'over' => $over];
        }

        return wp_fts_importer_process_evidence($started) + [
            'case' => 'source-' . $boundary . '-boundary',
            'boundary' => $boundary,
            'line_byte_limit' => WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_LINE_BYTES,
            'physical_byte_limit' => WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_PHYSICAL_BYTES,
            'decoded_byte_limit' => WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_DECODED_BYTES,
            'line_count_limit' => WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_LINES,
            'results' => $results,
        ];
    } finally {
        wp_fts_importer_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_importer_capture_run(string $kind, string $source, string $out): array
{
    $failure = null;
    $summary = null;
    try {
        $summary = wp_fts_importer_run($kind, $source, $out);
    } catch (Throwable $caught) {
        $failure = $caught;
    }
    $stats = [];
    if (is_array($summary)) {
        $stats = in_array($kind, ['conllu', 'unimorph'], true)
            ? ($summary[$kind] ?? [])
            : ($summary['stats'] ?? []);
    }

    return [
        'class' => $failure instanceof Throwable ? get_class($failure) : null,
        'message' => $failure instanceof Throwable ? $failure->getMessage() : null,
        'manifest_written' => is_file($out . '/manifest.json'),
        'output_entries' => is_dir($out)
            ? array_values(array_diff(scandir($out) ?: [], ['.', '..']))
            : [],
        'source_physical_bytes' => $stats['source_physical_bytes'] ?? null,
        'source_decoded_bytes' => $stats['source_decoded_bytes'] ?? null,
        'source_lines' => $stats['source_lines'] ?? null,
        'runtime_rows' => is_array($summary) ? ($summary['runtime']['rows'] ?? null) : null,
    ];
}

/** @return array<string,mixed> */
function wp_fts_importer_source_hash_race_case(): array
{
    $started = microtime(true);
    $root = wp_fts_importer_fixture_root('source-hash-race');
    try {
        $grow = $root . '/grow.tsv';
        $handle = fopen($grow, 'wb');
        if (!is_resource($handle) || !ftruncate($handle, WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_PHYSICAL_BYTES)) {
            throw new RuntimeException('Could not create exact physical hash-race source.');
        }
        fclose($handle);
        $growEvidence = WP_FTS_LemmaSourceImportLimits::source_physical_evidence([$grow], 'Hash-race');
        file_put_contents($grow, 'x', FILE_APPEND);
        $growSnapshot = $root . '/grow.snapshot';
        $growFailure = null;
        try {
            WP_FTS_LemmaSourceImportLimits::snapshot_source_artifact(
                $grow,
                $growSnapshot,
                $growEvidence['file_evidence'][$grow],
                'Hash-race'
            );
        } catch (Throwable $caught) {
            $growFailure = $caught;
        }

        $replace = $root . '/replace.tsv';
        file_put_contents($replace, "original\n");
        $replaceEvidence = WP_FTS_LemmaSourceImportLimits::source_physical_evidence([$replace], 'Hash-race');
        $replacement = $root . '/replacement.tsv';
        file_put_contents($replacement, "replaced\n");
        rename($replacement, $replace);
        $replaceSnapshot = $root . '/replace.snapshot';
        $replaceFailure = null;
        try {
            WP_FTS_LemmaSourceImportLimits::snapshot_source_artifact(
                $replace,
                $replaceSnapshot,
                $replaceEvidence['file_evidence'][$replace],
                'Hash-race'
            );
        } catch (Throwable $caught) {
            $replaceFailure = $caught;
        }

        return wp_fts_importer_process_evidence($started) + [
            'case' => 'source-hash-race',
            'physical_byte_limit' => WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_PHYSICAL_BYTES,
            'grown_bytes' => filesize($grow),
            'grow_error_class' => $growFailure instanceof Throwable ? get_class($growFailure) : null,
            'grow_error_message' => $growFailure instanceof Throwable ? $growFailure->getMessage() : null,
            'grow_snapshot_retained' => is_file($growSnapshot) || is_file($growSnapshot . '.partial'),
            'replacement_bytes' => filesize($replace),
            'replace_error_class' => $replaceFailure instanceof Throwable ? get_class($replaceFailure) : null,
            'replace_error_message' => $replaceFailure instanceof Throwable ? $replaceFailure->getMessage() : null,
            'replace_snapshot_retained' => is_file($replaceSnapshot) || is_file($replaceSnapshot . '.partial'),
        ];
    } finally {
        wp_fts_importer_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_importer_source_snapshot_swap_case(): array
{
    $started = microtime(true);
    $root = wp_fts_importer_fixture_root('source-snapshot-swap');
    try {
        $rows = 5000;
        $results = [];
        foreach (['generic', 'conllu', 'unimorph', 'polimorf'] as $kind) {
            $source = $root . '/source-' . $kind . wp_fts_importer_source_extension($kind);
            $attacker = $root . '/attacker-' . $kind . wp_fts_importer_source_extension($kind);
            wp_fts_importer_write_snapshot_rows($source, $kind, 'q', 'l', $rows);
            wp_fts_importer_write_snapshot_rows($attacker, $kind, 'x', 'y', $rows);
            $sourceDigest = hash_file('sha256', $source);
            $attackerDigest = hash_file('sha256', $attacker);
            if (!is_string($sourceDigest) || !is_string($attackerDigest)) {
                throw new RuntimeException('Could not hash source snapshot race fixtures.');
            }

            $out = $root . '/out-' . $kind;
            $tmpParent = $root . '/tmp-' . $kind;
            mkdir($tmpParent);
            file_put_contents($tmpParent . '/sentinel', "caller-owned temp parent\n");
            $snapshotName = in_array($kind, ['conllu', 'unimorph'], true)
                ? 'upstream-0001.snapshot'
                : 'source.snapshot';
            $watcher = wp_fts_importer_start_source_swap_watcher(
                $tmpParent,
                $source,
                $attacker,
                $out . '/manifest.json',
                $snapshotName
            );
            $failure = null;
            $summary = null;
            try {
                $summary = wp_fts_importer_run($kind, $source, $out, [
                    'tmp_dir' => $tmpParent,
                    'chunk_rows' => 1,
                    'max_rows_per_file' => 100000,
                ]);
            } catch (Throwable $caught) {
                $failure = $caught;
            }
            $watcherEvidence = wp_fts_importer_stop_source_swap_watcher($watcher);
            $manifestPath = is_array($summary) && is_string($summary['manifest'] ?? null)
                ? $summary['manifest']
                : '';
            $language = $kind === 'polimorf' ? 'pl' : 'qaa';
            $pack = $manifestPath !== ''
                ? WP_FTS_LanguageLemmaPack::from_manifest_file($manifestPath, null, $language)
                : null;
            $publishedSource = is_array($summary['source'] ?? null) ? $summary['source'] : [];
            $results[$kind] = [
                'class' => $failure instanceof Throwable ? get_class($failure) : null,
                'message' => $failure instanceof Throwable ? $failure->getMessage() : null,
                'manifest_written' => is_file($out . '/manifest.json'),
                'runtime_rows' => is_array($summary) ? ($summary['runtime']['rows'] ?? null) : null,
                'published_source_sha256' => $publishedSource['sha256'] ?? null,
                'source_sha256' => hash_file('sha256', $source),
                'expected_source_sha256' => $sourceDigest,
                'attacker_sha256' => $attackerDigest,
                'snapshot_sha256' => $watcherEvidence['snapshot_sha256'],
                'swapped' => $watcherEvidence['swapped'],
                'manifest_seen_while_swapped' => $watcherEvidence['manifest_seen_while_swapped'],
                'watcher_stderr' => $watcherEvidence['stderr'],
                'safe_first_lemma' => $pack instanceof WP_FTS_LanguageLemmaPack
                    ? $pack->stem('q00000', $language)
                    : null,
                'attacker_first_lemma' => $pack instanceof WP_FTS_LanguageLemmaPack
                    ? $pack->stem('x00000', $language)
                    : null,
                'caller_sentinel_retained' => is_file($tmpParent . '/sentinel'),
                'tmp_entries' => array_values(array_diff(scandir($tmpParent) ?: [], ['.', '..', 'sentinel'])),
            ];
        }

        return wp_fts_importer_process_evidence($started) + [
            'case' => 'source-snapshot-swap',
            'rows' => $rows,
            'results' => $results,
        ];
    } finally {
        wp_fts_importer_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_importer_fan_in_case(bool $polimorf): array
{
    $started = microtime(true);
    $kind = $polimorf ? 'polimorf' : 'generic';
    $root = wp_fts_importer_fixture_root('fan-in-' . $kind);
    try {
        $source = $root . '/one-row-chunks.tsv';
        $rows = 15000;
        wp_fts_importer_write_reverse_compact_rows($source, $rows, $polimorf);
        $out = $root . '/pack';
        $summary = wp_fts_importer_run($kind, $source, $out, [
            'fixture_only' => false,
            'runtime_compression' => 'gzip',
            'chunk_rows' => 1,
            'max_rows_per_file' => 200000,
        ]);
        $manifest = json_decode((string) file_get_contents((string) $summary['manifest']), true, 32, JSON_THROW_ON_ERROR);
        $pack = WP_FTS_LanguageLemmaPack::from_manifest_file(
            (string) $summary['manifest'],
            null,
            $polimorf ? 'pl' : 'qaa'
        );
        $runtimeFiles = $manifest['runtime']['files'] ?? [];
        $expectedDigest = hash_init('sha256');
        for ($index = 0; $index < $rows; $index++) {
            hash_update($expectedDigest, sprintf("q%05d\tl%05d\n", $index, $index));
        }

        return wp_fts_importer_process_evidence($started) + [
            'case' => 'fan-in-' . $kind,
            'source_rows' => $rows,
            'runtime_rows' => $summary['runtime']['rows'] ?? null,
            'runtime_sha256' => $summary['runtime']['sha256'] ?? null,
            'expected_runtime_sha256' => hash_final($expectedDigest),
            'initial_chunk_files' => $summary['stats']['chunk_files'] ?? null,
            'chunk_merge_outputs' => $summary['stats']['chunk_merge_outputs'] ?? null,
            'chunk_merge_passes' => $summary['stats']['chunk_merge_passes'] ?? null,
            'max_live_chunk_files' => $summary['stats']['max_live_chunk_files'] ?? null,
            'max_chunk_merge_inputs' => $summary['stats']['max_chunk_merge_inputs'] ?? null,
            'chunk_merge_fan_in_limit' => $summary['stats']['chunk_merge_fan_in_limit'] ?? null,
            'first_surface' => $runtimeFiles[0]['first_surface'] ?? null,
            'last_surface' => $runtimeFiles[count($runtimeFiles) - 1]['last_surface'] ?? null,
            'first_lemma' => $pack->stem('q00000', $polimorf ? 'pl' : 'qaa'),
            'middle_lemma' => $pack->stem('q07500', $polimorf ? 'pl' : 'qaa'),
            'last_lemma' => $pack->stem('q14999', $polimorf ? 'pl' : 'qaa'),
        ];
    } finally {
        wp_fts_importer_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_importer_chunk_row_boundary_case(): array
{
    $started = microtime(true);
    $root = wp_fts_importer_fixture_root('chunk-row-boundary');
    try {
        $results = [];
        foreach (['generic', 'polimorf'] as $kind) {
            $source = $root . '/source-' . $kind . '.tsv';
            wp_fts_importer_write_reverse_compact_rows(
                $source,
                WP_FTS_LemmaSourceImportLimits::MAX_CHUNK_ROWS,
                $kind === 'polimorf'
            );
            $exact = wp_fts_importer_run($kind, $source, $root . '/exact-' . $kind, [
                'fixture_only' => false,
                'runtime_compression' => 'gzip',
                'chunk_rows' => WP_FTS_LemmaSourceImportLimits::MAX_CHUNK_ROWS,
                'max_rows_per_file' => WP_FTS_LemmaSourceImportLimits::MAX_CHUNK_ROWS,
            ]);

            $overOut = $root . '/over-' . $kind;
            $overFailure = null;
            try {
                wp_fts_importer_run($kind, $source, $overOut, [
                    'fixture_only' => false,
                    'runtime_compression' => 'gzip',
                    'chunk_rows' => WP_FTS_LemmaSourceImportLimits::MAX_CHUNK_ROWS + 1,
                ]);
            } catch (Throwable $caught) {
                $overFailure = $caught;
            }
            $results[$kind] = [
                'exact_runtime_rows' => $exact['runtime']['rows'] ?? null,
                'exact_chunk_files' => $exact['stats']['chunk_files'] ?? null,
                'exact_max_chunk_lexical_bytes' => $exact['stats']['max_chunk_lexical_bytes'] ?? null,
                'over_class' => $overFailure instanceof Throwable ? get_class($overFailure) : null,
                'over_message' => $overFailure instanceof Throwable ? $overFailure->getMessage() : null,
                'over_output_exists' => file_exists($overOut) || is_link($overOut),
            ];
        }

        return wp_fts_importer_process_evidence($started) + [
            'case' => 'chunk-row-boundary',
            'chunk_row_limit' => WP_FTS_LemmaSourceImportLimits::MAX_CHUNK_ROWS,
            'chunk_lexical_byte_limit' => WP_FTS_LemmaSourceImportLimits::MAX_CHUNK_LEXICAL_BYTES,
            'results' => $results,
        ];
    } finally {
        wp_fts_importer_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_importer_chunk_file_boundary_case(): array
{
    $started = microtime(true);
    $root = wp_fts_importer_fixture_root('chunk-file-boundary');
    try {
        $results = [];
        foreach (['generic', 'polimorf'] as $kind) {
            $source = $root . '/source-' . $kind . '.tsv';
            wp_fts_importer_write_reverse_compact_rows(
                $source,
                WP_FTS_LemmaChunkSet::MAX_INITIAL_FILES,
                $kind === 'polimorf'
            );
            $exact = wp_fts_importer_run($kind, $source, $root . '/exact-' . $kind, [
                'fixture_only' => false,
                'runtime_compression' => 'gzip',
                'chunk_rows' => 1,
                'max_rows_per_file' => WP_FTS_LemmaChunkSet::MAX_INITIAL_FILES,
            ]);

            $append = wp_fts_importer_valid_source_row($kind, WP_FTS_LemmaChunkSet::MAX_INITIAL_FILES + 1);
            file_put_contents($source, $append, FILE_APPEND);
            $overOut = $root . '/over-' . $kind;
            $overFailure = null;
            try {
                wp_fts_importer_run($kind, $source, $overOut, [
                    'fixture_only' => false,
                    'runtime_compression' => 'gzip',
                    'chunk_rows' => 1,
                    'max_rows_per_file' => WP_FTS_LemmaChunkSet::MAX_INITIAL_FILES,
                ]);
            } catch (Throwable $caught) {
                $overFailure = $caught;
            }
            $results[$kind] = [
                'exact_runtime_rows' => $exact['runtime']['rows'] ?? null,
                'exact_chunk_files' => $exact['stats']['chunk_files'] ?? null,
                'over_class' => $overFailure instanceof Throwable ? get_class($overFailure) : null,
                'over_message' => $overFailure instanceof Throwable ? $overFailure->getMessage() : null,
                'over_manifest_written' => is_file($overOut . '/manifest.json'),
                'over_output_entries' => is_dir($overOut)
                    ? array_values(array_diff(scandir($overOut) ?: [], ['.', '..']))
                    : [],
            ];
        }

        return wp_fts_importer_process_evidence($started) + [
            'case' => 'chunk-file-boundary',
            'initial_file_limit' => WP_FTS_LemmaChunkSet::MAX_INITIAL_FILES,
            'results' => $results,
        ];
    } finally {
        wp_fts_importer_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_importer_physical_cap_case(bool $polimorf): array
{
    $started = microtime(true);
    $kind = $polimorf ? 'polimorf' : 'generic';
    $root = wp_fts_importer_fixture_root('physical-' . $kind);
    try {
        $source = $root . '/high-entropy.tsv';
        $rows = 300000;
        wp_fts_importer_write_high_entropy_rows($source, $rows, $polimorf);
        $out = $root . '/pack';
        $failure = null;
        try {
            wp_fts_importer_run($kind, $source, $out, [
                'fixture_only' => false,
                'runtime_compression' => 'gzip',
                'chunk_rows' => 200000,
                'max_rows_per_file' => 200000,
            ]);
        } catch (Throwable $caught) {
            $failure = $caught;
        }

        return wp_fts_importer_process_evidence($started) + [
            'case' => 'physical-' . $kind,
            'source_rows' => $rows,
            'source_bytes' => filesize($source),
            'runtime_lookup_byte_limit' => WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK,
            'error_class' => $failure instanceof Throwable ? get_class($failure) : null,
            'error_reason' => $failure instanceof WP_FTS_Analyzer_Config_Limit_Exceeded
                ? $failure->reason_code
                : null,
            'error_message' => $failure instanceof Throwable ? $failure->getMessage() : null,
            'manifest_written' => is_file($out . '/manifest.json'),
            'output_entries' => is_dir($out)
                ? array_values(array_diff(scandir($out) ?: [], ['.', '..']))
                : [],
        ];
    } finally {
        wp_fts_importer_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_importer_line_case(bool $gzip): array
{
    $started = microtime(true);
    $root = wp_fts_importer_fixture_root('line-' . ($gzip ? 'gzip' : 'plain'));
    try {
        $source = $root . '/oversized-source.tsv' . ($gzip ? '.gz' : '');
        wp_fts_importer_write_repeated_line($source, 32 * 1024 * 1024, $gzip);
        $errors = [];
        foreach (['generic', 'conllu', 'unimorph', 'polimorf'] as $importer) {
            $out = $root . '/out-' . $importer;
            $failure = null;
            try {
                wp_fts_importer_run($importer, $source, $out);
            } catch (Throwable $caught) {
                $failure = $caught;
            }
            $errors[$importer] = [
                'class' => $failure instanceof Throwable ? get_class($failure) : null,
                'message' => $failure instanceof Throwable ? $failure->getMessage() : null,
                'manifest_written' => is_file($out . '/manifest.json'),
            ];
        }

        return wp_fts_importer_process_evidence($started) + [
            'case' => $gzip ? 'line-gzip' : 'line-plain',
            'source_bytes' => filesize($source),
            'decoded_line_bytes' => 32 * 1024 * 1024,
            'line_byte_limit' => WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_LINE_BYTES,
            'errors' => $errors,
        ];
    } finally {
        wp_fts_importer_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_importer_chunk_case(bool $polimorf): array
{
    $started = microtime(true);
    $kind = $polimorf ? 'polimorf' : 'generic';
    $root = wp_fts_importer_fixture_root('chunk-' . $kind);
    try {
        $source = $root . '/maximum-width.tsv';
        $rows = 17000;
        wp_fts_importer_write_maximum_width_rows($source, $rows, $polimorf);
        $out = $root . '/pack';
        $summary = wp_fts_importer_run($kind, $source, $out, [
            'fixture_only' => false,
            'runtime_compression' => 'gzip',
            'chunk_rows' => 200000,
            'max_rows_per_file' => 200000,
        ]);
        $pack = WP_FTS_LanguageLemmaPack::from_manifest_file(
            (string) $summary['manifest'],
            null,
            $polimorf ? 'pl' : 'qaa'
        );

        return wp_fts_importer_process_evidence($started) + [
            'case' => 'chunk-' . $kind,
            'source_bytes' => filesize($source),
            'source_rows' => $rows,
            'max_runtime_term_bytes' => wp_fts_importer_max_term_bytes($polimorf ? 'pl' : 'qaa'),
            'runtime_rows' => $summary['runtime']['rows'] ?? null,
            'runtime_files' => $summary['runtime']['files'] ?? null,
            'runtime_decoded_bytes' => $summary['runtime']['decoded_bytes'] ?? null,
            'runtime_encoded_bytes' => $summary['runtime']['encoded_bytes'] ?? null,
            'lookup_bytes' => $summary['lookup']['bytes'] ?? null,
            'runtime_lookup_bytes' => $summary['runtime_lookup_bytes'] ?? null,
            'lookup_blocks' => $polimorf
                ? ($summary['lookup']['blocks'] ?? null)
                : $pack->lookup_block_count(),
            'max_lookup_blocks_per_file' => wp_fts_importer_max_lookup_blocks_per_file((string) $summary['manifest']),
            'max_lookup_block_decoded_bytes' => wp_fts_importer_max_lookup_block_decoded_bytes((string) $summary['manifest']),
            'chunk_files' => $summary['stats']['chunk_files'] ?? null,
            'max_chunk_lexical_bytes' => $summary['stats']['max_chunk_lexical_bytes'] ?? null,
            'chunk_lexical_byte_limit' => $summary['stats']['chunk_lexical_byte_limit'] ?? null,
            'activatable_runtime_files' => $pack->runtime_file_count(),
        ];
    } finally {
        wp_fts_importer_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_importer_plain_fixture_case(): array
{
    $started = microtime(true);
    $root = wp_fts_importer_fixture_root('plain-fixture');
    try {
        $cases = [];
        foreach (['rows-exact', 'rows', 'bytes-exact', 'bytes'] as $boundary) {
            $source = $root . '/' . $boundary . '.tsv';
            if ($boundary === 'rows-exact' || $boundary === 'rows') {
                wp_fts_importer_write_compact_rows(
                    $source,
                    WP_FTS_LemmaPackLimits::MAX_EAGER_FIXTURE_ROWS + ($boundary === 'rows' ? 1 : 0)
                );
            } else {
                wp_fts_importer_write_exact_runtime_bytes(
                    $source,
                    WP_FTS_LemmaPackLimits::MAX_EAGER_FIXTURE_RUNTIME_BYTES + ($boundary === 'bytes' ? 1 : 0)
                );
            }
            $out = $root . '/out-' . $boundary;
            $failure = null;
            $summary = null;
            $activatable = false;
            try {
                $summary = wp_fts_importer_run('generic', $source, $out, [
                    'fixture_only' => true,
                    'runtime_compression' => 'none',
                    'chunk_rows' => 200000,
                    'max_rows_per_file' => 100000,
                ]);
                $pack = WP_FTS_LanguageLemmaPack::from_manifest_file((string) $summary['manifest'], null, 'qaa');
                $activatable = $pack->runtime_file_count() === (int) $summary['runtime']['files'];
            } catch (Throwable $caught) {
                $failure = $caught;
            }
            $entries = is_dir($out)
                ? array_values(array_diff(scandir($out) ?: [], ['.', '..']))
                : [];
            $cases[$boundary] = [
                'class' => $failure instanceof Throwable ? get_class($failure) : null,
                'message' => $failure instanceof Throwable ? $failure->getMessage() : null,
                'manifest_written' => is_file($out . '/manifest.json'),
                'output_entries' => $entries,
                'source_bytes' => filesize($source),
                'runtime_rows' => is_array($summary) ? ($summary['runtime']['rows'] ?? null) : null,
                'activatable' => $activatable,
            ];
        }

        return wp_fts_importer_process_evidence($started) + [
            'case' => 'plain-fixture',
            'row_limit' => WP_FTS_LemmaPackLimits::MAX_EAGER_FIXTURE_ROWS,
            'decoded_byte_limit' => WP_FTS_LemmaPackLimits::MAX_EAGER_FIXTURE_RUNTIME_BYTES,
            'cases' => $cases,
        ];
    } finally {
        wp_fts_importer_remove_tree($root);
    }
}

/**
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function wp_fts_importer_run(string $kind, string $source, string $out, array $overrides = []): array
{
    $options = [
        'source' => $source,
        'out' => $out,
        'language' => 'qaa',
        'pack_id' => 'qaa-importer-containment-' . $kind,
        'version' => '1',
        'source_name' => 'Project-owned lemma importer containment source',
        'source_version' => '1',
        'source_url' => 'urn:wp-fts:test:lemma-importer-containment',
        'license' => 'CC0-1.0',
        'license_url' => 'urn:wp-fts:test:lemma-importer-containment-license',
        'attribution' => 'Project-owned generated importer containment data.',
        'fixture_only' => true,
        'runtime_compression' => 'none',
        'chunk_rows' => 200000,
        'max_rows_per_file' => 100000,
        'importer_commit' => 'test',
    ];
    foreach ($overrides as $key => $value) {
        $options[$key] = $value;
    }

    return match ($kind) {
        'generic' => (new WP_FTS_LemmaTsvPackImporter())->import($options),
        'conllu' => (new WP_FTS_ConlluLemmaPackImporter())->import($options),
        'unimorph' => (new WP_FTS_UnimorphLemmaPackImporter())->import($options),
        'polimorf' => (new WP_FTS_PolishPolimorfImporter())->import(array_replace($options, [
            'pack_id' => 'pl-importer-containment-polimorf',
            'source_name' => 'Project-owned PoliMorf importer containment source',
            'source_url' => 'urn:wp-fts:test:polimorf-importer-containment',
        ])),
        default => throw new InvalidArgumentException("Unknown importer kind: {$kind}"),
    };
}

/** Write one hostile unterminated logical record without retaining it twice. */
function wp_fts_importer_write_repeated_line(string $path, int $bytes, bool $gzip): void
{
    $handle = $gzip ? gzopen($path, 'wb1') : fopen($path, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create oversized importer source.');
    }
    $write = $gzip ? 'gzwrite' : 'fwrite';
    $close = $gzip ? 'gzclose' : 'fclose';
    $chunk = str_repeat('x', 65536);
    try {
        for ($written = 0; $written < $bytes; $written += strlen($chunk)) {
            if ($write($handle, $chunk) !== strlen($chunk)) {
                throw new RuntimeException('Could not write oversized importer source.');
            }
        }
    } finally {
        $close($handle);
    }
}

/** Fill namespaced term width while keeping each generated pair distinct. */
function wp_fts_importer_write_maximum_width_rows(string $path, int $rows, bool $polimorf): void
{
    $language = $polimorf ? 'pl' : 'qaa';
    $tokenBytes = wp_fts_importer_max_term_bytes($language);
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create maximum-width importer source.');
    }
    try {
        for ($index = 0; $index < $rows; $index++) {
            $surface = wp_fts_importer_fixed_token('q', $index, $tokenBytes, 'a');
            $lemma = wp_fts_importer_fixed_token('l', $index, $tokenBytes, 'b');
            $line = $surface . "\t" . $lemma;
            if ($polimorf) {
                $line .= "\ttag\t\t";
            }
            if (fwrite($handle, $line . "\n") !== strlen($line) + 1) {
                throw new RuntimeException('Could not write maximum-width importer row.');
            }
        }
    } finally {
        fclose($handle);
    }
}

/** Emit compact distinct pairs for the eager-fixture row boundary. */
function wp_fts_importer_write_compact_rows(string $path, int $rows): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create row-boundary importer source.');
    }
    try {
        for ($index = 0; $index < $rows; $index++) {
            $row = sprintf("q%05d\tl%05d\n", $index, $index);
            if (fwrite($handle, $row) !== strlen($row)) {
                throw new RuntimeException('Could not write row-boundary importer source.');
            }
        }
    } finally {
        fclose($handle);
    }
}

/** Force global sorting across one-row chunks by emitting reverse order. */
function wp_fts_importer_write_reverse_compact_rows(string $path, int $rows, bool $polimorf): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create fan-in importer source.');
    }
    try {
        for ($index = $rows - 1; $index >= 0; $index--) {
            $row = sprintf("q%05d\tl%05d", $index, $index) . ($polimorf ? "\ttag\t\t" : '') . "\n";
            if (fwrite($handle, $row) !== strlen($row)) {
                throw new RuntimeException('Could not write fan-in importer source row.');
            }
        }
    } finally {
        fclose($handle);
    }
}

/** Generate poorly compressible pairs that cross the physical pack ceiling. */
function wp_fts_importer_write_high_entropy_rows(string $path, int $rows, bool $polimorf): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create high-entropy importer source.');
    }
    try {
        for ($index = 0; $index < $rows; $index++) {
            $surface = 'q' . hash('sha256', 'surface-' . $index);
            $lemma = 'l' . hash('sha256', 'lemma-' . $index);
            $row = $surface . "\t" . $lemma . ($polimorf ? "\ttag\t\t" : '') . "\n";
            if (fwrite($handle, $row) !== strlen($row)) {
                throw new RuntimeException('Could not write high-entropy importer source row.');
            }
        }
    } finally {
        fclose($handle);
    }
}

/** Partition an exact decoded-runtime byte count into valid bounded rows. */
function wp_fts_importer_write_exact_runtime_bytes(string $path, int $bytes): void
{
    $tokenBytes = wp_fts_importer_max_term_bytes('qaa');
    $maxLineBytes = ($tokenBytes * 2) + 2;
    $rows = (int) ceil($bytes / $maxLineBytes);
    $baseLineBytes = intdiv($bytes, $rows);
    $longLineCount = $bytes % $rows;
    if ($baseLineBytes < 4 || $baseLineBytes + ($longLineCount > 0 ? 1 : 0) > $maxLineBytes) {
        throw new RuntimeException('Exact runtime byte fixture cannot partition its bounded TSV rows.');
    }

    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create decoded-byte-boundary importer source.');
    }
    try {
        for ($index = 0; $index < $rows; $index++) {
            $lineBytes = $baseLineBytes + ($index < $longLineCount ? 1 : 0);
            $surface = wp_fts_importer_fixed_token('q', $index, $tokenBytes, 'a');
            $lemmaPrefix = 'l' . str_pad((string) $index, 8, '0', STR_PAD_LEFT);
            $lemmaBytes = $lineBytes - strlen($surface) - 2;
            if ($lemmaBytes < strlen($lemmaPrefix)) {
                throw new RuntimeException('Decoded-byte-boundary row cannot fit its normalized tokens.');
            }
            $row = $surface . "\t" . $lemmaPrefix . str_repeat('a', $lemmaBytes - strlen($lemmaPrefix)) . "\n";
            if (fwrite($handle, $row) !== strlen($row)) {
                throw new RuntimeException('Could not write decoded-byte-boundary importer row.');
            }
        }
    } finally {
        fclose($handle);
    }
    if (filesize($path) !== $bytes) {
        throw new RuntimeException('Decoded-byte-boundary importer source has the wrong size.');
    }
}

/** Return usable token bytes after the language namespace consumes its prefix. */
function wp_fts_importer_max_term_bytes(string $language): int
{
    return WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES
        - strlen(WP_FTS_TermNamespace::namespace_term($language, ''));
}

/** Build a reproducibly unique token at one exact byte width. */
function wp_fts_importer_fixed_token(string $prefix, int $index, int $bytes, string $padding): string
{
    $tokenPrefix = $prefix . str_pad((string) $index, 8, '0', STR_PAD_LEFT);
    if (strlen($tokenPrefix) > $bytes) {
        throw new RuntimeException('Importer containment token prefix exceeds its bounded width.');
    }

    return $tokenPrefix . str_repeat($padding, $bytes - strlen($tokenPrefix));
}

/** Select an extension that makes each wrapper recognize the fixture. */
function wp_fts_importer_source_extension(string $kind): string
{
    return $kind === 'conllu' ? '.conllu' : '.tsv';
}

/** Encode the same synthetic pair in each importer's upstream format. */
function wp_fts_importer_valid_source_row(string $kind, int $index = 1): string
{
    $surface = 'q' . $index;
    $lemma = 'l' . $index;

    return match ($kind) {
        'conllu' => implode("\t", [(string) $index, $surface, $lemma, 'NOUN', '_', '_', '0', 'root', '_', '_']) . "\n",
        'unimorph' => $lemma . "\t" . $surface . "\tN;SG\n",
        'polimorf', 'external-builder' => $surface . "\t" . $lemma . "\ttag\t\t\n",
        default => $surface . "\t" . $lemma . "\n",
    };
}

/** Keep an owned temp tree alive long enough for the symlink watcher to inject. */
function wp_fts_importer_write_cleanup_rows(string $path, string $kind, int $rows): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create temporary cleanup source.');
    }
    try {
        for ($index = 1; $index <= $rows; $index++) {
            $row = wp_fts_importer_valid_source_row($kind, $index);
            if (fwrite($handle, $row) !== strlen($row)) {
                throw new RuntimeException('Could not write temporary cleanup source.');
            }
        }
    } finally {
        fclose($handle);
    }
}

/** Write distinct source rows whose one-byte prefixes expose source swapping. */
function wp_fts_importer_write_snapshot_rows(
    string $path,
    string $kind,
    string $surfacePrefix,
    string $lemmaPrefix,
    int $rows
): void {
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create source snapshot race fixture.');
    }
    try {
        for ($index = 0; $index < $rows; $index++) {
            $surface = sprintf('%s%05d', $surfacePrefix, $index);
            $lemma = sprintf('%s%05d', $lemmaPrefix, $index);
            $row = match ($kind) {
                'conllu' => implode("\t", [(string) ($index + 1), $surface, $lemma, 'NOUN', '_', '_', '0', 'root', '_', '_']) . "\n",
                'unimorph' => $lemma . "\t" . $surface . "\tN;SG\n",
                'polimorf' => $surface . "\t" . $lemma . "\ttag\t\t\n",
                default => $surface . "\t" . $lemma . "\n",
            };
            if (fwrite($handle, $row) !== strlen($row)) {
                throw new RuntimeException('Could not write source snapshot race row.');
            }
        }
    } finally {
        fclose($handle);
    }
}

/** Exercise an exact source-line payload without overflowing staged UniMorph. */
function wp_fts_importer_write_line_boundary_source(string $path, string $kind, int $payloadBytes): void
{
    if ($kind === 'unimorph') {
        $contents = '#' . str_repeat('x', $payloadBytes - 1) . "\n" . wp_fts_importer_valid_source_row($kind);
        if (file_put_contents($path, $contents) !== strlen($contents)) {
            throw new RuntimeException('Could not write UniMorph source line boundary fixture.');
        }
        return;
    }
    $prefix = match ($kind) {
        'conllu' => "1\tq\tl\tNOUN\t_\t_\t0\troot\t_\t",
        'polimorf' => "q\tl\t",
        default => "q\tl\t\t",
    };
    $suffix = $kind === 'polimorf' ? "\t\t" : '';
    $padding = $payloadBytes - strlen($prefix) - strlen($suffix);
    if ($padding < 0) {
        throw new RuntimeException('Source line boundary is too small for its fixture format.');
    }
    $line = $prefix . str_repeat('x', $padding) . $suffix . "\n";
    if (file_put_contents($path, $line) !== strlen($line)) {
        throw new RuntimeException('Could not write source line boundary fixture.');
    }
}

/** Stream one useful row followed by an exact count of cheap blank records. */
function wp_fts_importer_write_line_count_source(string $path, string $kind, int $lines): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create source line-count boundary fixture.');
    }
    try {
        $valid = wp_fts_importer_valid_source_row($kind);
        if (fwrite($handle, $valid) !== strlen($valid)) {
            throw new RuntimeException('Could not write source line-count boundary row.');
        }
        $remaining = $lines - 1;
        $chunkLines = 1048576;
        $chunk = str_repeat("\n", $chunkLines);
        while ($remaining > 0) {
            $writeLines = min($chunkLines, $remaining);
            $bytes = $writeLines === $chunkLines ? $chunk : str_repeat("\n", $writeLines);
            if (fwrite($handle, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Could not extend source line-count boundary fixture.');
            }
            $remaining -= $writeLines;
        }
    } finally {
        fclose($handle);
    }
}

/** Fill an exact physical or gzip-decoded envelope with bounded records. */
function wp_fts_importer_write_byte_envelope_source(
    string $path,
    string $kind,
    int $decodedBytes,
    bool $gzip
): void {
    $handle = $gzip ? gzopen($path, 'wb1') : fopen($path, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create source byte-envelope fixture.');
    }
    $write = $gzip ? 'gzwrite' : 'fwrite';
    $close = $gzip ? 'gzclose' : 'fclose';
    $valid = wp_fts_importer_valid_source_row($kind);
    if ($decodedBytes < strlen($valid)) {
        $close($handle);
        throw new RuntimeException('Source byte envelope is too small for its valid fixture row.');
    }
    try {
        if ($kind === 'polimorf') {
            if ($write($handle, $valid) !== strlen($valid)) {
                throw new RuntimeException('Could not write source byte-envelope lexical row.');
            }
            wp_fts_importer_write_filler_bytes($handle, $write, $decodedBytes - strlen($valid), true);
        } else {
            wp_fts_importer_write_filler_bytes($handle, $write, $decodedBytes - strlen($valid), false);
            if ($write($handle, $valid) !== strlen($valid)) {
                throw new RuntimeException('Could not write source byte-envelope lexical row.');
            }
        }
    } finally {
        $close($handle);
    }
}

/** @param resource $handle */
function wp_fts_importer_write_filler_bytes($handle, string $write, int $bytes, bool $afterLexical): void
{
    while ($bytes > 0) {
        $lineBytes = min(WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_LINE_BYTES + 1, $bytes);
        if ($lineBytes === 1) {
            $line = "\n";
        } else {
            $prefix = $afterLexical ? 'x' : '#';
            $line = $prefix . str_repeat('x', $lineBytes - 2) . "\n";
        }
        if ($write($handle, $line) !== strlen($line)) {
            throw new RuntimeException('Could not write source byte-envelope filler.');
        }
        $bytes -= $lineBytes;
    }
}

/** @return array{process:resource,pipes:array<int,resource>,stop:string} */
function wp_fts_importer_start_symlink_watcher(string $parent, string $target): array
{
    $stop = $parent . '/watcher-stop';
    $script = <<<'PHP'
$parent = $argv[1];
$target = $argv[2];
$stop = $argv[3];
$seen = [];
$inserted = 0;
$deadline = microtime(true) + 30.0;
while (!is_file($stop) && microtime(true) < $deadline) {
    foreach (scandir($parent) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $candidate = $parent . DIRECTORY_SEPARATOR . $entry;
        if (isset($seen[$candidate]) || is_link($candidate) || !is_dir($candidate)) {
            continue;
        }
        $seen[$candidate] = true;
        $link = $candidate . DIRECTORY_SEPARATOR . 'external-directory-link';
        if (@symlink($target, $link)) {
            $inserted++;
        }
    }
    usleep(1000);
}
echo $inserted, "\n";
PHP;
    $process = proc_open(
        [PHP_BINARY, '-n', '-r', $script, $parent, $target, $stop],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start temporary symlink cleanup watcher.');
    }
    fclose($pipes[0]);

    return ['process' => $process, 'pipes' => $pipes, 'stop' => $stop];
}

/**
 * @param array{process:resource,pipes:array<int,resource>,stop:string} $watcher
 * @return array{inserted_links:int,stderr:string}
 */
function wp_fts_importer_stop_symlink_watcher(array $watcher): array
{
    file_put_contents($watcher['stop'], "stop\n");
    $stdout = stream_get_contents($watcher['pipes'][1]);
    $stderr = stream_get_contents($watcher['pipes'][2]);
    fclose($watcher['pipes'][1]);
    fclose($watcher['pipes'][2]);
    $exit = proc_close($watcher['process']);
    unlink($watcher['stop']);
    if ($exit !== 0) {
        throw new RuntimeException("Temporary symlink cleanup watcher failed: {$stderr}");
    }

    return ['inserted_links' => (int) trim((string) $stdout), 'stderr' => (string) $stderr];
}

/**
 * Replace the caller-visible source only after the private snapshot is final,
 * keep the attacker generation installed through manifest publication, then
 * restore the original path before returning evidence.
 *
 * @return array{process:resource,pipes:array<int,resource>,stop:string}
 */
function wp_fts_importer_start_source_swap_watcher(
    string $parent,
    string $source,
    string $attacker,
    string $manifest,
    string $snapshotName
): array {
    $stop = $parent . '/source-swap-stop';
    $script = <<<'PHP'
$parent = $argv[1];
$source = $argv[2];
$attacker = $argv[3];
$manifest = $argv[4];
$snapshotName = $argv[5];
$stop = $argv[6];
$original = $source . '.original-generation';
$swapped = false;
$manifestSeen = false;
$snapshotSha = null;
$deadline = microtime(true) + 30.0;
while (!is_file($stop) && microtime(true) < $deadline) {
    if (!$swapped) {
        foreach (scandir($parent) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $parent . DIRECTORY_SEPARATOR . $entry;
            $snapshot = $child . DIRECTORY_SEPARATOR . $snapshotName;
            if (!is_file($snapshot)) {
                continue;
            }
            $snapshotSha = hash_file('sha256', $snapshot);
            if (
                is_string($snapshotSha)
                && rename($source, $original)
                && rename($attacker, $source)
            ) {
                $swapped = true;
            }
            break;
        }
    } elseif (is_file($manifest)) {
        $manifestSeen = true;
        break;
    }
    usleep(500);
}
if ($swapped && is_file($manifest)) {
    $manifestSeen = true;
}
if ($swapped) {
    if (is_file($source)) {
        rename($source, $attacker);
    }
    if (is_file($original)) {
        rename($original, $source);
    }
}
echo json_encode([
    'swapped' => $swapped,
    'manifest_seen_while_swapped' => $manifestSeen,
    'snapshot_sha256' => $snapshotSha,
]), "\n";
PHP;
    $process = proc_open(
        [PHP_BINARY, '-n', '-r', $script, $parent, $source, $attacker, $manifest, $snapshotName, $stop],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start source snapshot swap watcher.');
    }
    fclose($pipes[0]);

    return ['process' => $process, 'pipes' => $pipes, 'stop' => $stop];
}

/**
 * @param array{process:resource,pipes:array<int,resource>,stop:string} $watcher
 * @return array{swapped:bool,manifest_seen_while_swapped:bool,snapshot_sha256:?string,stderr:string}
 */
function wp_fts_importer_stop_source_swap_watcher(array $watcher): array
{
    file_put_contents($watcher['stop'], "stop\n");
    $stdout = stream_get_contents($watcher['pipes'][1]);
    $stderr = stream_get_contents($watcher['pipes'][2]);
    fclose($watcher['pipes'][1]);
    fclose($watcher['pipes'][2]);
    $exit = proc_close($watcher['process']);
    @unlink($watcher['stop']);
    if ($exit !== 0) {
        throw new RuntimeException("Source snapshot swap watcher failed: {$stderr}");
    }
    $payload = json_decode(trim((string) $stdout), true);
    if (!is_array($payload)) {
        throw new RuntimeException("Source snapshot swap watcher emitted invalid evidence: {$stdout}");
    }

    return [
        'swapped' => (bool) ($payload['swapped'] ?? false),
        'manifest_seen_while_swapped' => (bool) ($payload['manifest_seen_while_swapped'] ?? false),
        'snapshot_sha256' => is_string($payload['snapshot_sha256'] ?? null)
            ? $payload['snapshot_sha256']
            : null,
        'stderr' => (string) $stderr,
    ];
}

/** Attest every regular target file so symlink cleanup cannot hide mutation. */
function wp_fts_importer_tree_digest(string $root): string
{
    $paths = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $path) {
        if ($path->isFile()) {
            $paths[] = $path->getPathname();
        }
    }
    sort($paths, SORT_STRING);
    $digest = hash_init('sha256');
    foreach ($paths as $path) {
        $relative = substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
        hash_update($digest, $relative . "\0" . hash_file('sha256', $path) . "\n");
    }

    return hash_final($digest);
}

/** Read generated manifest evidence for the worst per-shard block fan-out. */
function wp_fts_importer_max_lookup_blocks_per_file(string $manifestPath): int
{
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
    $maximum = 0;
    foreach ($manifest['runtime']['files'] ?? [] as $file) {
        $maximum = max($maximum, (int) ($file['lookup']['blocks'] ?? 0));
    }

    return $maximum;
}

/** Re-open every sidecar to prove the largest actual decoded block. */
function wp_fts_importer_max_lookup_block_decoded_bytes(string $manifestPath): int
{
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
    $root = dirname($manifestPath);
    $maximum = 0;
    foreach ($manifest['runtime']['files'] ?? [] as $file) {
        $runtimePath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $file['path']);
        $lookupPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $file['lookup']['path']);
        $metadata = WP_FTS_LemmaPackLookupIndex::metadata(
            $lookupPath,
            $runtimePath,
            (string) $file['sha256'],
            (int) $file['rows']
        );
        foreach ($metadata['blocks'] as $block) {
            $maximum = max($maximum, (int) ($block['decoded_bytes'] ?? 0));
        }
    }

    return $maximum;
}

/** Allocate an isolated root whose full contents are disposable test data. */
function wp_fts_importer_fixture_root(string $case): string
{
    $root = sys_get_temp_dir() . '/wp-fts-lemma-importer-' . $case . '-' . bin2hex(random_bytes(6));
    if (!mkdir($root, 0777, true) && !is_dir($root)) {
        throw new RuntimeException('Could not create importer containment root.');
    }

    return $root;
}

/** @return array<string,mixed> */
function wp_fts_importer_process_evidence(float $started): array
{
    return [
        'elapsed_seconds' => microtime(true) - $started,
        'php_peak_bytes' => memory_get_peak_usage(true),
        'proc_status' => wp_fts_importer_proc_status(),
    ];
}

/** @return array{VmHWM_bytes:?int,VmRSS_bytes:?int} */
function wp_fts_importer_proc_status(): array
{
    if (!is_readable('/proc/self/status')) {
        return ['VmHWM_bytes' => null, 'VmRSS_bytes' => null];
    }
    $values = [];
    $handle = fopen('/proc/self/status', 'rb');
    if (!is_resource($handle)) {
        return ['VmHWM_bytes' => null, 'VmRSS_bytes' => null];
    }
    try {
        while (($line = fgets($handle)) !== false) {
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $key = substr($line, 0, $separator);
            if (!in_array($key, ['VmHWM', 'VmRSS'], true)) {
                continue;
            }
            $value = trim(substr($line, $separator + 1));
            $space = strpos($value, ' ');
            $digits = $space === false ? '' : substr($value, 0, $space);
            if (
                $digits !== ''
                && strspn($digits, '0123456789') === strlen($digits)
                && strtolower(trim(substr($value, $space + 1))) === 'kb'
            ) {
                $values[$key] = (int) $digits * 1024;
            }
        }
    } finally {
        fclose($handle);
    }

    return [
        'VmHWM_bytes' => $values['VmHWM'] ?? null,
        'VmRSS_bytes' => $values['VmRSS'] ?? null,
    ];
}

/** Remove fixture trees without following directory symlinks into sentinels. */
function wp_fts_importer_remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        wp_fts_importer_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
    }
    rmdir($path);
}
