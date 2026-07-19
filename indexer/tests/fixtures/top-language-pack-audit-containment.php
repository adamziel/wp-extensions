<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tools/audit-top-language-lemma-packs.php';

$case = $argv[1] ?? '';
try {
    $payload = match ($case) {
        'oversized' => wp_fts_tlpac_oversized(),
        'traversal' => wp_fts_tlpac_traversal(),
        'bundled' => wp_fts_tlpac_bundled(),
        default => throw new InvalidArgumentException('Expected an audit containment case.'),
    };
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}

/** @return array<string,mixed> */
function wp_fts_tlpac_bundled(): array
{
    $started = microtime(true);
    $packRoot = dirname(__DIR__, 2) . '/resources/analyzer-packs';
    ob_start();
    $exit = wp_fts_top_language_pack_audit_main([
        'audit-top-language-lemma-packs.php',
        '--pack-root=' . $packRoot,
        '--json',
    ]);
    $audit = json_decode((string) ob_get_clean(), true, 32, JSON_THROW_ON_ERROR);
    $statuses = [];
    foreach ($audit['rows'] ?? [] as $row) {
        $status = (string) ($row['status'] ?? '');
        $statuses[$status] = ($statuses[$status] ?? 0) + 1;
    }

    return wp_fts_tlpac_evidence($started) + [
        'case' => 'bundled',
        'exit' => $exit,
        'audit_status' => $audit['status'] ?? null,
        'rows' => count($audit['rows'] ?? []),
        'statuses' => $statuses,
        'manifests' => count(wp_fts_top_language_pack_audit_discover_manifests($packRoot)),
    ];
}

/** @return array<string,mixed> */
function wp_fts_tlpac_oversized(): array
{
    $started = microtime(true);
    $root = wp_fts_tlpac_root('oversized');
    try {
        $packRoot = $root . '/packs';
        $pack = $root . '/explicit';
        mkdir($packRoot);
        mkdir($pack);
        $manifest = $pack . '/manifest.json';
        $handle = fopen($manifest, 'wb');
        fwrite($handle, '{"language":"es"}');
        ftruncate($handle, 140 * 1024 * 1024);
        fclose($handle);

        ob_start();
        $exit = wp_fts_top_language_pack_audit_main([
            'audit-top-language-lemma-packs.php',
            '--pack-root=' . $packRoot,
            '--manifest=es:' . $manifest,
            '--json',
        ]);
        $output = (string) ob_get_clean();
        $audit = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        $spanish = null;
        foreach ($audit['rows'] ?? [] as $row) {
            if (($row['language'] ?? null) === 'es') {
                $spanish = $row;
                break;
            }
        }

        return wp_fts_tlpac_evidence($started) + [
            'case' => 'oversized',
            'manifest_bytes' => filesize($manifest),
            'manifest_limit' => WP_FTS_Analyzer_Config_Limits::MAX_MANIFEST_BYTES,
            'exit' => $exit,
            'audit_status' => $audit['status'] ?? null,
            'spanish' => $spanish,
        ];
    } finally {
        wp_fts_tlpac_remove_tree($root);
    }
}

/** @return array<string,mixed> */
function wp_fts_tlpac_traversal(): array
{
    $started = microtime(true);
    $root = wp_fts_tlpac_root('traversal');
    try {
        $results = [];
        foreach (['manifests-exact', 'manifests-over', 'entries-exact', 'entries-over', 'paths-over', 'depth-exact', 'depth-over', 'symlink-file', 'symlink-prefix'] as $boundary) {
            $source = $root . '/' . $boundary;
            mkdir($source);
            wp_fts_tlpac_write_tree($source, $boundary);
            try {
                $paths = wp_fts_top_language_pack_audit_discover_manifests($source);
                $results[$boundary] = [
                    'error_class' => null,
                    'error_message' => null,
                    'manifests' => count($paths),
                ];
            } catch (Throwable $error) {
                $results[$boundary] = [
                    'error_class' => get_class($error),
                    'error_message' => $error->getMessage(),
                    'manifests' => null,
                ];
            }
        }

        return wp_fts_tlpac_evidence($started) + [
            'case' => 'traversal',
            'manifest_limit' => WP_FTS_TOP_LANGUAGE_AUDIT_MAX_MANIFESTS,
            'entry_limit' => WP_FTS_TOP_LANGUAGE_AUDIT_MAX_ENTRIES,
            'path_byte_limit' => WP_FTS_TOP_LANGUAGE_AUDIT_MAX_PATH_BYTES,
            'depth_limit' => WP_FTS_TOP_LANGUAGE_AUDIT_MAX_DEPTH,
            'results' => $results,
        ];
    } finally {
        wp_fts_tlpac_remove_tree($root);
    }
}

/** Materialize the requested exact, over-limit, or symlink traversal fixture. */
function wp_fts_tlpac_write_tree(string $root, string $boundary): void
{
    if ($boundary === 'manifests-exact' || $boundary === 'manifests-over') {
        $count = WP_FTS_TOP_LANGUAGE_AUDIT_MAX_MANIFESTS + ($boundary === 'manifests-over' ? 1 : 0);
        for ($index = 0; $index < $count; $index++) {
            $directory = $root . '/' . sprintf('p%03d', $index);
            mkdir($directory);
            file_put_contents($directory . '/manifest.json', "{}\n");
        }
        return;
    }
    if (in_array($boundary, ['entries-exact', 'entries-over', 'paths-over'], true)) {
        $count = WP_FTS_TOP_LANGUAGE_AUDIT_MAX_ENTRIES + ($boundary === 'entries-over' ? 1 : 0);
        $nameBytes = intdiv(WP_FTS_TOP_LANGUAGE_AUDIT_MAX_PATH_BYTES, WP_FTS_TOP_LANGUAGE_AUDIT_MAX_ENTRIES);
        for ($index = 0; $index < $count; $index++) {
            $prefix = sprintf('%04x', $index);
            $length = $boundary === 'paths-over' && $index === 0 ? $nameBytes + 1 : $nameBytes;
            $name = $prefix . str_repeat('a', $length - strlen($prefix));
            file_put_contents($root . '/' . $name, 'x');
        }
        return;
    }
    if ($boundary === 'depth-exact' || $boundary === 'depth-over') {
        $depth = WP_FTS_TOP_LANGUAGE_AUDIT_MAX_DEPTH + ($boundary === 'depth-over' ? 1 : 0);
        $directory = $root;
        for ($index = 0; $index < $depth; $index++) {
            $directory .= '/d';
            mkdir($directory);
        }
        file_put_contents($directory . '/manifest.json', "{}\n");
        return;
    }
    if ($boundary === 'symlink-file') {
        $outside = dirname($root) . '/outside-manifest.json';
        file_put_contents($outside, "{}\n");
        symlink($outside, $root . '/manifest.json');
        return;
    }
    if ($boundary === 'symlink-prefix') {
        $outside = $root . '2';
        mkdir($outside);
        file_put_contents($outside . '/manifest.json', "{}\n");
        symlink($outside, $root . '/escape');
    }
}

/** Create an unpredictable temporary root dedicated to one fixture case. */
function wp_fts_tlpac_root(string $case): string
{
    $root = sys_get_temp_dir() . '/wp-fts-audit-' . $case . '-' . bin2hex(random_bytes(6));
    mkdir($root, 0777, true);

    return $root;
}

/** @return array<string,mixed> */
function wp_fts_tlpac_evidence(float $started): array
{
    return [
        'elapsed_seconds' => microtime(true) - $started,
        'php_peak_bytes' => memory_get_peak_usage(true),
    ];
}

/** Remove a fixture path without following symlinks outside its tree. */
function wp_fts_tlpac_remove_tree(string $path): void
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
        wp_fts_tlpac_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}
