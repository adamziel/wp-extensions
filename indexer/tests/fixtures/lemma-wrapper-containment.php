<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tools/import-conllu-lemma-pack.php';
require_once dirname(__DIR__, 2) . '/tools/import-unimorph-lemma-pack.php';

$case = $argv[1] ?? '';
try {
    $payload = match ($case) {
        'discovery' => wp_fts_lwc_discovery_case(),
        'rows-conllu' => wp_fts_lwc_staging_case('conllu', 'rows'),
        'rows-unimorph' => wp_fts_lwc_staging_case('unimorph', 'rows'),
        'bytes-conllu' => wp_fts_lwc_staging_case('conllu', 'bytes'),
        'bytes-unimorph' => wp_fts_lwc_staging_case('unimorph', 'bytes'),
        'invalid' => wp_fts_lwc_invalid_case(),
        'conllu-provenance' => wp_fts_lwc_conllu_provenance_case(),
        default => throw new InvalidArgumentException('Expected a lemma wrapper containment case.'),
    };
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}

/** @return array<string,mixed> */
function wp_fts_lwc_discovery_case(): array
{
    $started = microtime(true);
    $root = wp_fts_lwc_root('discovery');
    try {
        $results = [];
        foreach (['conllu', 'unimorph'] as $kind) {
            foreach (['files-exact', 'files-over', 'paths-exact', 'paths-over', 'depth-exact', 'depth-over', 'symlink'] as $boundary) {
                $source = $root . '/' . $kind . '-' . $boundary;
                wp_fts_lwc_write_discovery_source($source, $kind, $boundary);
                $results[$kind][$boundary] = wp_fts_lwc_run($kind, $source, $root, $kind . '-' . $boundary);
            }
        }

        return wp_fts_lwc_evidence($started) + [
            'case' => 'discovery',
            'file_limit' => WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_FILES,
            'path_byte_limit' => WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_PATH_BYTES,
            'depth_limit' => WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_DEPTH,
            'results' => $results,
        ];
    } finally {
        wp_fts_lwc_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_lwc_staging_case(string $kind, string $boundaryKind): array
{
    $started = microtime(true);
    $root = wp_fts_lwc_root($boundaryKind . '-' . $kind);
    try {
        $results = [];
        foreach (['exact', 'over'] as $boundary) {
            $source = $root . '/' . ($boundary === 'exact' ? 'exact' : 'overx') . wp_fts_lwc_extension($kind)
                . ($boundaryKind === 'bytes' ? '.gz' : '');
            if ($boundaryKind === 'rows') {
                wp_fts_lwc_write_row_boundary_source(
                    $source,
                    $kind,
                    WP_FTS_LemmaSourceImportLimits::MAX_STAGED_ROWS + ($boundary === 'over' ? 1 : 0)
                );
            } else {
                wp_fts_lwc_write_byte_boundary_source(
                    $source,
                    $kind,
                    WP_FTS_LemmaSourceImportLimits::MAX_STAGED_TSV_BYTES + ($boundary === 'over' ? 1 : 0)
                );
            }
            $results[$boundary] = wp_fts_lwc_run($kind, $source, $root, $kind . '-' . $boundaryKind . '-' . $boundary);
        }

        return wp_fts_lwc_evidence($started) + [
            'case' => $boundaryKind . '-' . $kind,
            'row_limit' => WP_FTS_LemmaSourceImportLimits::MAX_STAGED_ROWS,
            'byte_limit' => WP_FTS_LemmaSourceImportLimits::MAX_STAGED_TSV_BYTES,
            'results' => $results,
        ];
    } finally {
        wp_fts_lwc_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_lwc_invalid_case(): array
{
    $started = microtime(true);
    $root = wp_fts_lwc_root('invalid');
    try {
        $results = [];
        foreach (['conllu', 'unimorph'] as $kind) {
            $source = $root . '/' . $kind . wp_fts_lwc_extension($kind);
            $oversized = str_repeat('q', 252);
            $rows = $kind === 'conllu'
                ? [wp_fts_lwc_conllu_row(1, $oversized, 'lemma'), wp_fts_lwc_conllu_row(2, 'validform', 'validlemma')]
                : [wp_fts_lwc_unimorph_row($oversized, 'lemma'), wp_fts_lwc_unimorph_row('validform', 'validlemma')];
            file_put_contents($source, implode('', $rows));
            $results[$kind] = wp_fts_lwc_run($kind, $source, $root, $kind . '-invalid');
        }

        return wp_fts_lwc_evidence($started) + ['case' => 'invalid', 'results' => $results];
    } finally {
        wp_fts_lwc_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_lwc_conllu_provenance_case(): array
{
    $started = microtime(true);
    $root = wp_fts_lwc_root('conllu-provenance');
    try {
        $source = $root . '/original-source.conllu';
        file_put_contents($source, "# original CoNLL-U bytes\n" . wp_fts_lwc_valid_row('conllu', 1));
        $expectedSha = hash_file('sha256', $source);
        $expectedBytes = filesize($source);
        $result = wp_fts_lwc_run('conllu', $source, $root, 'conllu-provenance');
        $out = $root . '/out-conllu-provenance';
        $manifest = json_decode((string) file_get_contents($out . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $sourceLock = json_decode((string) file_get_contents($out . '/SOURCE.lock.json'), true, 512, JSON_THROW_ON_ERROR);
        $notice = (string) file_get_contents($out . '/NOTICE.txt');

        return wp_fts_lwc_evidence($started) + [
            'case' => 'conllu-provenance',
            'result' => $result,
            'expected_sha256' => $expectedSha,
            'expected_bytes' => $expectedBytes,
            'manifest_source' => $manifest['source'] ?? null,
            'manifest_importer' => $manifest['provenance']['importer'] ?? null,
            'source_lock_source' => $sourceLock['source'] ?? null,
            'source_lock_columns' => $sourceLock['columns'] ?? null,
            'notice' => $notice,
        ];
    } finally {
        wp_fts_lwc_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_lwc_run(string $kind, string $source, string $root, string $label): array
{
    $out = $root . '/out-' . $label;
    $tmpParent = $root . '/tmp-' . $label;
    mkdir($tmpParent, 0777, true);
    file_put_contents($tmpParent . '/sentinel', "keep\n");
    $failure = null;
    $summary = null;
    try {
        $options = [
            'source' => $source,
            'out' => $out,
            'tmp_dir' => $tmpParent,
            'language' => 'qaa',
            'pack_id' => 'qaa-wrapper-containment-' . $label,
            'version' => '1',
            'source_name' => 'Project-owned wrapper containment source',
            'source_version' => '1',
            'source_url' => 'urn:wp-fts:test:wrapper-containment',
            'license' => 'CC0-1.0',
            'license_url' => 'urn:wp-fts:test:wrapper-containment-license',
            'attribution' => 'Project-owned generated wrapper containment data.',
            'chunk_rows' => 200000,
            'max_rows_per_file' => 200000,
            'importer_commit' => 'test',
        ];
        $summary = $kind === 'conllu'
            ? (new WP_FTS_ConlluLemmaPackImporter())->import($options)
            : (new WP_FTS_UnimorphLemmaPackImporter())->import($options);
    } catch (Throwable $caught) {
        $failure = $caught;
    }
    $stats = is_array($summary) ? ($summary[$kind] ?? []) : [];

    return [
        'error_class' => $failure instanceof Throwable ? get_class($failure) : null,
        'error_message' => $failure instanceof Throwable ? $failure->getMessage() : null,
        'manifest_written' => is_file($out . '/manifest.json'),
        'manifest_bytes' => is_file($out . '/manifest.json') ? filesize($out . '/manifest.json') : null,
        'source_lock_bytes' => is_file($out . '/SOURCE.lock.json') ? filesize($out . '/SOURCE.lock.json') : null,
        'accepted_rows' => $stats['accepted_rows'] ?? null,
        'invalid_runtime_token_rows' => $stats['invalid_runtime_token_rows'] ?? null,
        'staged_tsv_bytes' => $stats['staged_tsv_bytes'] ?? null,
        'source_files' => $stats['source_files'] ?? null,
        'source_path_bytes' => $stats['source_path_bytes'] ?? null,
        'source_max_depth' => $stats['source_max_depth'] ?? null,
        'source_physical_bytes' => $stats['source_physical_bytes'] ?? null,
        'source_decoded_bytes' => $stats['source_decoded_bytes'] ?? null,
        'source_lines' => $stats['source_lines'] ?? null,
        'runtime_rows' => is_array($summary) ? ($summary['runtime']['rows'] ?? null) : null,
        'tmp_sentinel_retained' => is_file($tmpParent . '/sentinel'),
        'tmp_entries' => array_values(array_diff(scandir($tmpParent) ?: [], ['.', '..', 'sentinel'])),
    ];
}

/** Materialize one exact/max+1 traversal shape for a wrapper source tree. */
function wp_fts_lwc_write_discovery_source(string $source, string $kind, string $boundary): void
{
    mkdir($source, 0777, true);
    $extension = wp_fts_lwc_extension($kind);
    if ($boundary === 'symlink') {
        $outside = dirname($source) . '/outside-' . basename($source) . $extension;
        file_put_contents($outside, wp_fts_lwc_valid_row($kind, 1));
        symlink($outside, $source . '/claimed-inside' . $extension);
        return;
    }
    if ($boundary === 'depth-exact' || $boundary === 'depth-over') {
        $depth = WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_DEPTH + ($boundary === 'depth-over' ? 1 : 0);
        $directory = $source;
        for ($index = 0; $index < $depth; $index++) {
            $directory .= '/d';
            mkdir($directory);
        }
        file_put_contents($directory . '/source' . $extension, wp_fts_lwc_valid_row($kind, 1));
        return;
    }

    $count = WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_FILES + ($boundary === 'files-over' ? 1 : 0);
    $directory = $source;
    $relativeBytes = null;
    if ($boundary === 'paths-exact' || $boundary === 'paths-over') {
        $directory .= '/d';
        mkdir($directory);
        $count = WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_FILES;
        $relativeBytes = intdiv(WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_PATH_BYTES, $count);
    }
    for ($index = 0; $index < $count; $index++) {
        if ($relativeBytes === null) {
            $name = sprintf('%03d', $index) . $extension;
        } else {
            $nameBytes = $relativeBytes - 2 + ($boundary === 'paths-over' && $index === 0 ? 1 : 0);
            $prefix = sprintf('%03d', $index);
            $name = $prefix . str_repeat('a', $nameBytes - strlen($prefix) - strlen($extension)) . $extension;
        }
        file_put_contents($directory . '/' . $name, wp_fts_lwc_valid_row($kind, $index + 1));
    }
}

/** Stream duplicate lexical pairs whose source rows remain independently counted. */
function wp_fts_lwc_write_row_boundary_source(string $path, string $kind, int $rows): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create row-boundary wrapper source.');
    }
    try {
        for ($index = 1; $index <= $rows; $index++) {
            $row = wp_fts_lwc_valid_row($kind, $index, false);
            if (fwrite($handle, $row) !== strlen($row)) {
                throw new RuntimeException('Could not write row-boundary wrapper source.');
            }
        }
    } finally {
        fclose($handle);
    }
}

/** Size normalized staging exactly while gzip keeps original input physical bytes bounded. */
function wp_fts_lwc_write_byte_boundary_source(string $path, string $kind, int $bytes): void
{
    $rows = 1024;
    $baseBytes = intdiv($bytes, $rows);
    $extra = $bytes % $rows;
    $label = basename($path);
    $gzip = str_ends_with(strtolower($path), '.gz');
    $handle = $gzip ? gzopen($path, 'wb1') : fopen($path, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not create byte-boundary wrapper source.');
    }
    $write = $gzip ? 'gzwrite' : 'fwrite';
    $close = $gzip ? 'gzclose' : 'fclose';
    try {
        for ($index = 1; $index <= $rows; $index++) {
            $target = $baseBytes + ($index <= $extra ? 1 : 0);
            $surface = sprintf('q%04d', $index);
            $lemma = sprintf('l%04d', $index);
            $note = $kind === 'conllu' ? $label . ':' . $index . '#' . $index : $label . ':' . $index;
            $fixedStagedBytes = strlen($surface . "\t" . $lemma . "\t\t" . $note . "\n");
            $tag = str_repeat('a', $target - $fixedStagedBytes);
            $row = $kind === 'conllu'
                ? wp_fts_lwc_conllu_row($index, $surface, $lemma, $tag)
                : wp_fts_lwc_unimorph_row($surface, $lemma, $tag);
            if ($write($handle, $row) !== strlen($row)) {
                throw new RuntimeException('Could not write byte-boundary wrapper source.');
            }
        }
    } finally {
        $close($handle);
    }
}

/** Produce one valid row, optionally collapsing every row to the same pair. */
function wp_fts_lwc_valid_row(string $kind, int $index, bool $unique = true): string
{
    $surface = $unique ? sprintf('q%06d', $index) : 'q';
    $lemma = $unique ? sprintf('l%06d', $index) : 'l';

    return $kind === 'conllu'
        ? wp_fts_lwc_conllu_row($index, $surface, $lemma)
        : wp_fts_lwc_unimorph_row($surface, $lemma);
}

/** Encode the ten mandatory CoNLL-U columns used by first-pass staging. */
function wp_fts_lwc_conllu_row(int $id, string $surface, string $lemma, string $tag = 'NOUN'): string
{
    return implode("\t", [(string) $id, $surface, $lemma, $tag, '_', '_', '0', 'root', '_', '_']) . "\n";
}

/** Encode the lemma/form/features order expected by the UniMorph wrapper. */
function wp_fts_lwc_unimorph_row(string $surface, string $lemma, string $tag = 'N;SG'): string
{
    return $lemma . "\t" . $surface . "\t" . $tag . "\n";
}

/** Select a source suffix recognized by recursive wrapper discovery. */
function wp_fts_lwc_extension(string $kind): string
{
    return $kind === 'conllu' ? '.conllu' : '.tsv';
}

/** Allocate an isolated disposable wrapper fixture root. */
function wp_fts_lwc_root(string $case): string
{
    $root = sys_get_temp_dir() . '/wp-fts-wrapper-' . $case . '-' . bin2hex(random_bytes(6));
    mkdir($root, 0777, true);

    return $root;
}

/** @return array<string,mixed> */
function wp_fts_lwc_evidence(float $started): array
{
    return [
        'elapsed_seconds' => microtime(true) - $started,
        'php_peak_bytes' => memory_get_peak_usage(true),
        'proc_status' => wp_fts_lwc_proc_status(),
    ];
}

/** @return array{VmHWM_bytes:?int,VmRSS_bytes:?int} */
function wp_fts_lwc_proc_status(): array
{
    $values = [];
    $handle = @fopen('/proc/self/status', 'rb');
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
            if ($space !== false && strtolower(trim(substr($value, $space + 1))) === 'kb') {
                $values[$key] = (int) substr($value, 0, $space) * 1024;
            }
        }
    } finally {
        fclose($handle);
    }

    return ['VmHWM_bytes' => $values['VmHWM'] ?? null, 'VmRSS_bytes' => $values['VmRSS'] ?? null];
}

/** Recursively clean fixture data without following a symbolic link. */
function wp_fts_lwc_remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        wp_fts_lwc_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}
