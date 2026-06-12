<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$args = array_slice($argv, 1);
$metadataOnly = false;
$manifest = null;
foreach ($args as $arg) {
    if ($arg === '--metadata-only') {
        $metadataOnly = true;
        continue;
    }
    if ($manifest === null) {
        $manifest = $arg;
        continue;
    }
    fwrite(STDERR, "Usage: php tools/validate-analyzer-pack.php [manifest.json] [--metadata-only]\n");
    exit(1);
}
$manifest ??= WP_FTS_AnalyzerPackValidator::default_polish_fixture_manifest();

try {
    $validator = new WP_FTS_AnalyzerPackValidator();
    $result = $metadataOnly
        ? $validator->validate_metadata((string) $manifest)
        : $validator->validate((string) $manifest, false);
    $summary = [
        'status' => 'ok',
        'pack_id' => $result['manifest']['pack_id'],
        'language' => $result['manifest']['language'],
        'version' => $result['manifest']['version'],
        'fixture_only' => $result['manifest']['fixture_only'],
        'metadata_only' => $metadataOnly,
        'manifest_sha256' => $result['manifest_sha256'],
        'runtime_rows' => $result['runtime_rows'],
        'rows_collected' => $result['rows_collected'],
        'runtime_files' => array_map(
            static fn(array $file): array => [
                'sha256' => $file['sha256'],
                'rows' => $file['rows'],
            ],
            $result['runtime_files']
        ),
    ];
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Analyzer pack validation failed: {$e->getMessage()}\n");
    exit(1);
}
