<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools/check-release-readiness.php';
require_once dirname(__DIR__, 3) . '/components/full-text-search/src/bootstrap.php';

final class WP_FTS_Release_Readiness_Contract_Pending extends RuntimeException
{
}

function wp_fts_release_readiness_contract_true(bool $condition, string $message): void
{
    if (function_exists('assert_true')) {
        assert_true($condition, $message);
        return;
    }

    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wp_fts_release_readiness_contract_same(mixed $expected, mixed $actual, string $message): void
{
    if (function_exists('assert_same')) {
        assert_same($expected, $actual, $message);
        return;
    }

    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function wp_fts_release_readiness_contract_contains(string $needle, string $haystack, string $message): void
{
    if (function_exists('assert_contains')) {
        assert_contains($needle, $haystack, $message);
        return;
    }

    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message);
    }
}

function wp_fts_release_readiness_contract_temp_dir(): string
{
    $dir = sys_get_temp_dir() . '/wp_fts_release_readiness_' . getmypid() . '_' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("Could not create release-readiness fixture directory: {$dir}");
    }

    return $dir;
}

function wp_fts_release_readiness_contract_remove_tree(string $directory): void
{
    if (function_exists('remove_directory_tree')) {
        remove_directory_tree($directory);
        return;
    }

    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $path) {
        if ($path->isDir()) {
            rmdir($path->getPathname());
            continue;
        }
        unlink($path->getPathname());
    }
    rmdir($directory);
}

function wp_fts_release_readiness_contract_pending(string $message): void
{
    if (function_exists('mark_pending')) {
        mark_pending($message);
    }

    throw new WP_FTS_Release_Readiness_Contract_Pending($message);
}

function wp_fts_release_readiness_contract_write_file(string $path, string $contents = "fixture\n"): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException("Could not create fixture directory: {$directory}");
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException("Could not write fixture file: {$path}");
    }
}

/**
 * @param array<string,mixed> $data
 */
function wp_fts_release_readiness_contract_write_json(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException("Could not encode fixture JSON: {$path}");
    }
    wp_fts_release_readiness_contract_write_file($path, $json . "\n");
}

function wp_fts_release_readiness_contract_adler32_bytes(string $data): string
{
    $a = 1;
    $b = 0;
    $length = strlen($data);
    for ($i = 0; $i < $length; $i++) {
        $a = ($a + ord($data[$i])) % 65521;
        $b = ($b + $a) % 65521;
    }

    return pack('nn', $b, $a);
}

function wp_fts_release_readiness_contract_zlib_store(string $data): string
{
    $stream = "\x78\x01";
    $offset = 0;
    $length = strlen($data);
    do {
        $chunk = substr($data, $offset, 65535);
        $offset += strlen($chunk);
        $final = $offset >= $length;
        $chunkLength = strlen($chunk);
        $stream .= chr($final ? 1 : 0);
        $stream .= pack('v', $chunkLength);
        $stream .= pack('v', (~$chunkLength) & 0xffff);
        $stream .= $chunk;
    } while ($offset < $length);

    return $stream . wp_fts_release_readiness_contract_adler32_bytes($data);
}

function wp_fts_release_readiness_contract_png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . hash('crc32b', $type . $data, true);
}

function wp_fts_release_readiness_contract_png_rgb_scanlines(int $width, int $height, string $pattern = 'checker'): string
{
    $raw = '';
    for ($y = 0; $y < $height; $y++) {
        $raw .= "\0";
        for ($x = 0; $x < $width; $x++) {
            if ($pattern === 'blank') {
                $raw .= "\x40\x40\x40";
                continue;
            }

            $toggle = (($x >> 4) + ($y >> 4)) % 2;
            $raw .= $toggle === 0
                ? chr(32 + ($x % 64)) . chr(96 + ($y % 64)) . "\xd0"
                : "\xd8" . chr(48 + ($y % 96)) . chr(64 + ($x % 96));
        }
    }

    return $raw;
}

function wp_fts_release_readiness_contract_png_fixture(int $width, int $height, string $pattern = 'checker'): string
{
    $raw = wp_fts_release_readiness_contract_png_rgb_scanlines($width, $height, $pattern);
    $header = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

    return "\x89PNG\r\n\x1a\n"
        . wp_fts_release_readiness_contract_png_chunk('IHDR', $header)
        . wp_fts_release_readiness_contract_png_chunk('IDAT', wp_fts_release_readiness_contract_zlib_store($raw))
        . wp_fts_release_readiness_contract_png_chunk('IEND', '');
}

function wp_fts_release_readiness_contract_png_corrupt_chunk_crc(string $png, string $type): string
{
    $offset = strlen("\x89PNG\r\n\x1a\n");
    $length = strlen($png);
    while ($offset + 12 <= $length) {
        $chunkLength = unpack('Nlength', substr($png, $offset, 4))['length'];
        $chunkType = substr($png, $offset + 4, 4);
        $crcOffset = $offset + 8 + $chunkLength;
        $nextOffset = $crcOffset + 4;
        if ($nextOffset > $length) {
            break;
        }
        if ($chunkType === $type) {
            return substr_replace($png, chr(ord($png[$crcOffset + 3]) ^ 0xff), $crcOffset + 3, 1);
        }
        $offset = $nextOffset;
    }

    throw new RuntimeException("Could not find PNG chunk to corrupt: {$type}");
}

function wp_fts_release_readiness_contract_png_first_chunk_data(string $png, string $type): string
{
    $offset = strlen("\x89PNG\r\n\x1a\n");
    $length = strlen($png);
    while ($offset + 12 <= $length) {
        $chunkLength = unpack('Nlength', substr($png, $offset, 4))['length'];
        $chunkType = substr($png, $offset + 4, 4);
        $dataOffset = $offset + 8;
        $nextOffset = $dataOffset + $chunkLength + 4;
        if ($nextOffset > $length) {
            break;
        }
        if ($chunkType === $type) {
            return substr($png, $dataOffset, $chunkLength);
        }
        $offset = $nextOffset;
    }

    throw new RuntimeException("Could not find PNG chunk data: {$type}");
}

function wp_fts_release_readiness_contract_png_first_chunk_crc(string $png, string $type): string
{
    $offset = strlen("\x89PNG\r\n\x1a\n");
    $length = strlen($png);
    while ($offset + 12 <= $length) {
        $chunkLength = unpack('Nlength', substr($png, $offset, 4))['length'];
        $chunkType = substr($png, $offset + 4, 4);
        $crcOffset = $offset + 8 + $chunkLength;
        $nextOffset = $crcOffset + 4;
        if ($nextOffset > $length) {
            break;
        }
        if ($chunkType === $type) {
            return substr($png, $crcOffset, 4);
        }
        $offset = $nextOffset;
    }

    throw new RuntimeException("Could not find PNG chunk CRC: {$type}");
}

function wp_fts_release_readiness_contract_png_replace_first_chunk_data(string $png, string $type, string $data): string
{
    $offset = strlen("\x89PNG\r\n\x1a\n");
    $length = strlen($png);
    while ($offset + 12 <= $length) {
        $chunkLength = unpack('Nlength', substr($png, $offset, 4))['length'];
        $chunkType = substr($png, $offset + 4, 4);
        $dataOffset = $offset + 8;
        $nextOffset = $dataOffset + $chunkLength + 4;
        if ($nextOffset > $length) {
            break;
        }
        if ($chunkType === $type) {
            return substr($png, 0, $offset)
                . wp_fts_release_readiness_contract_png_chunk($type, $data)
                . substr($png, $nextOffset);
        }
        $offset = $nextOffset;
    }

    throw new RuntimeException("Could not find PNG chunk to replace: {$type}");
}

function wp_fts_release_readiness_contract_write_public_asset_pair(string $source, string $banner, string $icon): void
{
    wp_fts_release_readiness_contract_write_file($source . '/assets/banner-772x250.png', $banner);
    wp_fts_release_readiness_contract_write_file($source . '/assets/icon-128x128.png', $icon);
}

/**
 * @param string[] $command
 * @return array{exit:int,stdout:string,stderr:string}
 */
function wp_fts_release_readiness_contract_run_command(array $command, string $cwd): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start command: ' . implode(' ', $command));
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return [
        'exit' => is_int($exit) ? $exit : 1,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

/**
 * @param string[] $command
 * @return array<int,array{exit:int,stdout:string,stderr:string}>
 */
function wp_fts_release_readiness_contract_run_concurrent_commands(array $command, string $cwd, int $count): array
{
    if (!function_exists('proc_open')) {
        wp_fts_release_readiness_contract_pending('proc_open() is unavailable, so the concurrent release-readiness CLI contract cannot run.');
    }

    $processes = [];
    for ($i = 0; $i < $count; $i++) {
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $cwd
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start concurrent command: ' . implode(' ', $command));
        }

        fclose($pipes[0]);
        $processes[] = [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
        ];
    }

    $results = [];
    foreach ($processes as $item) {
        $stdout = (string) stream_get_contents($item['stdout']);
        $stderr = (string) stream_get_contents($item['stderr']);
        fclose($item['stdout']);
        fclose($item['stderr']);
        $exit = proc_close($item['process']);
        $results[] = [
            'exit' => is_int($exit) ? $exit : 1,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    return $results;
}

/**
 * @param array{version?:string,license?:string,composer_version?:string,readme?:bool,readme_faq_heading?:string,license_file?:bool,public_assets?:bool,public_docs_ready?:bool,public_evidence?:bool} $options
 */
function wp_fts_release_readiness_contract_source_fixture(string $tmp, array $options = []): string
{
    $version = (string) ($options['version'] ?? '1.2.3');
    $license = (string) ($options['license'] ?? 'proprietary');
    $source = $tmp . '/source';

    wp_fts_release_readiness_contract_write_file($source . '/indexer.php', "<?php\n/**\n * Plugin Name: Pure PHP FTS Indexer\n * Version: {$version}\n * Requires PHP: 8.1\n */\n");
    wp_fts_release_readiness_contract_write_file($source . '/README.md', "# Pure PHP FTS Indexer\n");
    wp_fts_release_readiness_contract_write_json($source . '/composer.json', array_filter([
        'name' => 'local/wp-pure-php-fts',
        'description' => 'Fixture release-readiness plugin metadata.',
        'type' => 'wordpress-plugin',
        'license' => $license,
        'version' => $options['composer_version'] ?? null,
        'require' => [
            'php' => '>=8.1',
            'wp-php-toolkit/full-text-search' => '^0.1',
        ],
    ], static fn(mixed $value): bool => $value !== null));
    $shippedTools = var_export(wp_fts_release_readiness_contract_shipped_tool_paths(), true);
    wp_fts_release_readiness_contract_write_file(
        $source . '/tools/build-release-zip.php',
        "<?php\nif (!class_exists('WP_FTS_ReleasePackageBuilder')) {\n    final class WP_FTS_ReleasePackageBuilder {\n        public const SHIPPED_TOOL_PATHS = {$shippedTools};\n    }\n}\n"
    );

    if (($options['readme'] ?? false) === true) {
        $faqHeading = (string) ($options['readme_faq_heading'] ?? 'FAQ');
        wp_fts_release_readiness_contract_write_file(
            $source . '/readme.txt',
            implode("\n", [
                '=== Pure PHP FTS Indexer ===',
                'Contributors: fixture-maintainer',
                'Tags: search, full text search, indexing',
                'Requires at least: 6.5',
                'Tested up to: 6.9',
                'Requires PHP: 8.1',
                "Stable tag: {$version}",
                'License: GPL-2.0-or-later',
                'License URI: https://www.gnu.org/licenses/gpl-2.0.html',
                '',
                '== Description ==',
                'Fixture public submission readme content with reviewable details for search indexing.',
                '',
                '== Installation ==',
                'Upload the plugin directory, activate it, and run a small indexing smoke check.',
                '',
                "== {$faqHeading} ==",
                '= Does this fixture include public metadata? =',
                'Yes. The fixture carries enough public metadata to exercise the readiness gate.',
                '',
                '== Changelog ==',
                "= {$version} =",
                'Initial public-submission fixture release.',
                '',
            ])
        );
    }

    if (($options['license_file'] ?? false) === true) {
        wp_fts_release_readiness_contract_write_file($source . '/LICENSE', "GNU GENERAL PUBLIC LICENSE\nVersion 2, June 1991\nFixture redistribution terms for gate coverage.\n");
    }

    if (($options['public_assets'] ?? false) === true) {
        wp_fts_release_readiness_contract_write_file(
            $source . '/assets/banner-772x250.png',
            wp_fts_release_readiness_contract_png_fixture(772, 250)
        );
        wp_fts_release_readiness_contract_write_file(
            $source . '/assets/icon-128x128.png',
            wp_fts_release_readiness_contract_png_fixture(128, 128)
        );
    }

    $docs = ($options['public_docs_ready'] ?? false) === true
        ? "Public-submission artifacts have been reviewed and approved for this fixture.\n"
        : "This package is a direct-install ZIP boundary only and is not public-submission-ready.\n";
    wp_fts_release_readiness_contract_write_file($source . '/docs/release-packaging.md', $docs);

    if (($options['public_evidence'] ?? false) === true) {
        wp_fts_release_readiness_contract_write_json($source . '/docs/public-submission-readiness.json', [
            'status' => 'approved',
            'target' => 'wordpress.org-plugin-directory',
            'approver' => 'Fixture Reviewer',
            'reviewed_at' => '2026-06-21',
            'checks' => [
                'readme' => true,
                'license' => true,
                'assets' => true,
                'public_submission_authority' => true,
            ],
        ]);
    }

    return $source;
}

function wp_fts_release_readiness_contract_package_fixture(string $tmp, string $version = '1.2.3'): string
{
    $package = $tmp . '/indexer';
    wp_fts_release_readiness_contract_write_file($package . '/indexer.php', "<?php\n/**\n * Plugin Name: Pure PHP FTS Indexer\n * Version: {$version}\n * Requires PHP: 8.1\n */\n");
    wp_fts_release_readiness_contract_write_json($package . '/composer.json', [
        'name' => 'local/wp-pure-php-fts',
        'type' => 'wordpress-plugin',
        'license' => 'proprietary',
        'require' => [
            'php' => '>=8.1',
            'wp-php-toolkit/full-text-search' => '^0.1',
        ],
    ]);
    wp_fts_release_readiness_contract_write_json($package . '/composer.lock', [
        'packages' => [
            ['name' => 'wp-php-toolkit/full-text-search', 'version' => '0.1.0'],
            ['name' => 'wamania/php-stemmer', 'version' => 'v3.0.1'],
        ],
        'packages-dev' => [],
    ]);
    wp_fts_release_readiness_contract_write_file($package . '/README.md', "# Pure PHP FTS Indexer\n");
    wp_fts_release_readiness_contract_write_file($package . '/src/bootstrap.php', "<?php\n");
    wp_fts_release_readiness_contract_write_file($package . '/src/Plugin.php', "<?php\n");
    wp_fts_release_readiness_contract_write_file($package . '/src/WPCLICommand.php', "<?php\n");
    wp_fts_release_readiness_contract_write_file($package . '/vendor/autoload.php', "<?php\n");
    wp_fts_release_readiness_contract_write_file($package . '/vendor/wp-php-toolkit/full-text-search/src/bootstrap.php', "<?php\n");
    wp_fts_release_readiness_contract_write_file($package . '/vendor/wp-php-toolkit/full-text-search/src/LemmaPackLookupIndex.php', "<?php\n");
    foreach (wp_fts_release_readiness_contract_shipped_tool_paths() as $relativePath) {
        wp_fts_release_readiness_contract_write_file($package . '/' . $relativePath, "<?php\n");
    }

    return $package;
}

/** @return string[] */
function wp_fts_release_readiness_contract_shipped_tool_paths(): array
{
    return [
        'tools/import-lemma-tsv-pack.php',
        'tools/import-conllu-lemma-pack.php',
        'tools/import-unimorph-lemma-pack.php',
        'tools/import-polish-polimorf-lemmatizer.php',
        'tools/validate-analyzer-pack.php',
        'tools/audit-top-language-lemma-packs.php',
        'tools/build-polish-polimorf-external-pack.php',
        'tools/lemma-source-import-limits.php',
        'tools/lemma-chunk-merge.php',
    ];
}

/**
 * @return array{manifest:string,runtime:string,lookup:string}
 */
function wp_fts_release_readiness_contract_add_analyzer_pack(string $package): array
{
    $directory = $package . '/resources/analyzer-packs/qaa-release-fixture';
    $runtime = $directory . '/runtime.tsv.gz';
    $lookup = $runtime . '.lookup';
    $rows = "qaaform\tqaalemma\n";
    $compressed = gzencode($rows, 9, ZLIB_ENCODING_GZIP);
    if (!is_string($compressed)) {
        throw new RuntimeException('Could not compress the release analyzer fixture.');
    }
    wp_fts_release_readiness_contract_write_file($runtime, $compressed);
    $indexed = WP_FTS_LemmaPackLookupIndex::build(
        $runtime,
        (string) hash_file('sha256', $runtime),
        $lookup
    );
    wp_fts_release_readiness_contract_write_file($directory . '/NOTICE.txt', "Project-owned release analyzer fixture.\n");

    $manifest = $directory . '/manifest.json';
    wp_fts_release_readiness_contract_write_json($manifest, [
        'schema_version' => 1,
        'pack_id' => 'qaa-release-readiness-fixture',
        'language' => 'qaa',
        'version' => '1.0.0',
        'capabilities' => ['dictionary-lemmatizer', 'indexed-runtime-lookups'],
        'runtime' => [
            'format' => WP_FTS_AnalyzerPackValidator::RUNTIME_FORMAT_LEMMA_TSV,
            'normalization' => 'WP_FTS_Normalizer qaa with fold_diacritics=true',
            'ambiguity_policy' => 'ambiguous_surface_noop',
            'total_rows' => 1,
            'total_sha256' => hash('sha256', $rows),
            'files' => [[
                'path' => 'runtime.tsv.gz',
                'sha256' => $indexed['runtime_sha256'],
                'rows' => 1,
                'first_surface' => 'qaaform',
                'last_surface' => 'qaaform',
                'compression' => WP_FTS_AnalyzerPackValidator::RUNTIME_COMPRESSION_GZIP,
                'lookup' => [
                    'format' => $indexed['format'],
                    'path' => 'runtime.tsv.gz.lookup',
                    'sha256' => $indexed['sha256'],
                    'blocks' => $indexed['blocks'],
                ],
            ]],
        ],
        'source' => [
            'name' => 'Project-owned release analyzer source',
            'version' => '1.0.0',
            'url' => 'urn:wp-fts:test:release-analyzer-source',
            'artifact_sha256' => hash('sha256', $rows),
            'byte_count' => strlen($rows),
        ],
        'license' => [
            'spdx_id' => 'CC0-1.0',
            'notice_path' => 'NOTICE.txt',
        ],
        'attribution' => ['note' => 'Project-owned release analyzer fixture.'],
        'provenance' => [
            'no_runtime_network_access' => true,
        ],
    ]);

    return [
        'manifest' => $manifest,
        'runtime' => $runtime,
        'lookup' => $lookup,
    ];
}

/**
 * @param array<string,mixed> $report
 * @return string[]
 */
function wp_fts_release_readiness_contract_blocker_ids(array $report): array
{
    $ids = [];
    foreach (($report['blockers'] ?? []) as $blocker) {
        if (is_array($blocker) && is_string($blocker['id'] ?? null)) {
            $ids[] = $blocker['id'];
        }
    }
    sort($ids, SORT_STRING);

    return $ids;
}

function wp_fts_release_readiness_contract_has_check(array $report, string $id, string $status): bool
{
    foreach (($report['checks'] ?? []) as $check) {
        if (is_array($check) && ($check['id'] ?? null) === $id && ($check['status'] ?? null) === $status) {
            return true;
        }
    }

    return false;
}

function wp_fts_release_readiness_contract_direct_ready(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $source = wp_fts_release_readiness_contract_source_fixture($tmp);
        $package = wp_fts_release_readiness_contract_package_fixture($tmp);
        $report = (new WP_FTS_ReleaseReadinessChecker())->check([
            'target' => 'direct-install',
            'plugin_src' => $source,
            'monorepo_root' => $tmp,
            'package_dir' => $package,
        ]);

        wp_fts_release_readiness_contract_same('ready', $report['status'] ?? null, 'staged direct-install package should be ready');
        wp_fts_release_readiness_contract_same([], $report['blockers'] ?? null, 'ready direct-install package should not report blockers');
        wp_fts_release_readiness_contract_true(
            wp_fts_release_readiness_contract_has_check($report, 'direct_required_runtime_files', 'pass'),
            'direct-install readiness should validate required runtime files'
        );
        wp_fts_release_readiness_contract_true(
            wp_fts_release_readiness_contract_has_check($report, 'direct_package_prohibited_paths', 'pass'),
            'direct-install readiness should validate the package exclusion boundary'
        );
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

function wp_fts_release_readiness_contract_missing_shipped_tool(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $source = wp_fts_release_readiness_contract_source_fixture($tmp);
        $package = wp_fts_release_readiness_contract_package_fixture($tmp);
        $missing = 'tools/validate-analyzer-pack.php';
        unlink($package . '/' . $missing);

        $report = (new WP_FTS_ReleaseReadinessChecker())->check([
            'target' => 'direct-install',
            'plugin_src' => $source,
            'monorepo_root' => $tmp,
            'package_dir' => $package,
        ]);

        wp_fts_release_readiness_contract_same('blocked', $report['status'] ?? null, 'a missing shipped tool module should block direct-install readiness');
        wp_fts_release_readiness_contract_true(
            in_array('direct_required_runtime_files', wp_fts_release_readiness_contract_blocker_ids($report), true),
            'a missing shipped tool module should report the required-runtime blocker'
        );
        wp_fts_release_readiness_contract_contains(
            'indexer/' . $missing,
            WP_FTS_ReleaseReadinessChecker::render_json($report),
            'the required-runtime blocker should name the missing shipped tool module'
        );
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

function wp_fts_release_readiness_contract_analyzer_pack_tampering(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $source = wp_fts_release_readiness_contract_source_fixture($tmp);
        $package = wp_fts_release_readiness_contract_package_fixture($tmp);
        $pack = wp_fts_release_readiness_contract_add_analyzer_pack($package);
        $options = [
            'target' => 'direct-install',
            'plugin_src' => $source,
            'monorepo_root' => $tmp,
            'package_dir' => $package,
        ];

        $checker = new WP_FTS_ReleaseReadinessChecker();
        $ready = $checker->check($options);
        wp_fts_release_readiness_contract_same('ready', $ready['status'] ?? null, 'release gate should functionally load an untampered analyzer pack');
        wp_fts_release_readiness_contract_true(
            wp_fts_release_readiness_contract_has_check($ready, 'direct_analyzer_pack_runtime_integrity', 'pass'),
            'release gate should record strict analyzer runtime verification'
        );

        $changedRows = "qaaform\tchanged\n";
        $changedRuntime = gzencode($changedRows, 9, ZLIB_ENCODING_GZIP);
        if (!is_string($changedRuntime)) {
            throw new RuntimeException('Could not compress the changed release analyzer fixture.');
        }
        wp_fts_release_readiness_contract_write_file($pack['runtime'], $changedRuntime);
        $changedIndex = WP_FTS_LemmaPackLookupIndex::build(
            $pack['runtime'],
            (string) hash_file('sha256', $pack['runtime']),
            $pack['lookup']
        );
        $manifest = json_decode((string) file_get_contents($pack['manifest']), true, 512, JSON_THROW_ON_ERROR);
        $manifest['runtime']['files'][0]['sha256'] = $changedIndex['runtime_sha256'];
        $manifest['runtime']['files'][0]['lookup']['sha256'] = $changedIndex['sha256'];
        wp_fts_release_readiness_contract_write_json($pack['manifest'], $manifest);
        $blocked = $checker->check($options);
        wp_fts_release_readiness_contract_same('blocked', $blocked['status'] ?? null, 'release gate should block changed runtime rows even after file attestations are updated');
        wp_fts_release_readiness_contract_true(
            in_array('direct_analyzer_pack_runtime_integrity', wp_fts_release_readiness_contract_blocker_ids($blocked), true),
            'runtime tampering should be attributed to analyzer pack integrity'
        );

        $pack = wp_fts_release_readiness_contract_add_analyzer_pack($package);
        $lookupBytes = file_get_contents($pack['lookup']);
        if (!is_string($lookupBytes)) {
            throw new RuntimeException('Could not read the release analyzer lookup fixture.');
        }
        $changedLookup = str_replace('qaaform', 'raaform', $lookupBytes, $replacements);
        wp_fts_release_readiness_contract_same(2, $replacements, 'release lookup fixture should expose one first/last surface range');
        wp_fts_release_readiness_contract_write_file($pack['lookup'], $changedLookup);
        $manifest = json_decode((string) file_get_contents($pack['manifest']), true, 512, JSON_THROW_ON_ERROR);
        $manifest['runtime']['files'][0]['lookup']['sha256'] = hash_file('sha256', $pack['lookup']);
        wp_fts_release_readiness_contract_write_json($pack['manifest'], $manifest);
        $sidecarBlocked = $checker->check($options);
        wp_fts_release_readiness_contract_same('blocked', $sidecarBlocked['status'] ?? null, 'release gate should block sidecar ranges changed with a matching file attestation');
        wp_fts_release_readiness_contract_true(
            in_array('direct_analyzer_pack_runtime_integrity', wp_fts_release_readiness_contract_blocker_ids($sidecarBlocked), true),
            'sidecar tampering should be attributed to analyzer pack integrity'
        );
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

function wp_fts_release_readiness_contract_current_public_blocked(): void
{
    $root = dirname(__DIR__, 2);
    $report = (new WP_FTS_ReleaseReadinessChecker())->check([
        'target' => 'public-submission',
        'plugin_src' => $root,
        'monorepo_root' => dirname($root),
    ]);
    $ids = wp_fts_release_readiness_contract_blocker_ids($report);

    wp_fts_release_readiness_contract_same('blocked', $report['status'] ?? null, 'current package should not pass public-submission readiness');
    foreach (['docs_public_submission_blocker', 'package_license_file', 'package_public_assets', 'public_submission_authority_evidence'] as $id) {
        wp_fts_release_readiness_contract_true(in_array($id, $ids, true), "current package should report public-submission blocker {$id}");
    }
}

function wp_fts_release_readiness_contract_public_readme_and_license_blockers(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $missingReadme = wp_fts_release_readiness_contract_source_fixture($tmp . '/missing-readme', [
            'license' => 'GPL-2.0-or-later',
            'license_file' => true,
            'public_assets' => true,
            'public_docs_ready' => true,
        ]);
        $readmeReport = (new WP_FTS_ReleaseReadinessChecker())->check([
            'target' => 'public-submission',
            'plugin_src' => $missingReadme,
            'monorepo_root' => dirname($missingReadme),
        ]);
        wp_fts_release_readiness_contract_true(
            in_array('package_readme_txt', wp_fts_release_readiness_contract_blocker_ids($readmeReport), true),
            'public-submission readiness should detect missing readme.txt'
        );

        $missingLicense = wp_fts_release_readiness_contract_source_fixture($tmp . '/missing-license', [
            'license' => 'GPL-2.0-or-later',
            'readme' => true,
            'public_assets' => true,
            'public_docs_ready' => true,
        ]);
        $licenseReport = (new WP_FTS_ReleaseReadinessChecker())->check([
            'target' => 'public-submission',
            'plugin_src' => $missingLicense,
            'monorepo_root' => dirname($missingLicense),
        ]);
        wp_fts_release_readiness_contract_true(
            in_array('package_license_file', wp_fts_release_readiness_contract_blocker_ids($licenseReport), true),
            'public-submission readiness should detect missing package-level license file'
        );

        $proprietary = wp_fts_release_readiness_contract_source_fixture($tmp . '/proprietary-license', [
            'license' => 'proprietary',
            'readme' => true,
            'license_file' => true,
            'public_assets' => true,
            'public_docs_ready' => true,
        ]);
        $proprietaryReport = (new WP_FTS_ReleaseReadinessChecker())->check([
            'target' => 'public-submission',
            'plugin_src' => $proprietary,
            'monorepo_root' => dirname($proprietary),
        ]);
        wp_fts_release_readiness_contract_true(
            in_array('composer_public_license', wp_fts_release_readiness_contract_blocker_ids($proprietaryReport), true),
            'public-submission readiness should detect proprietary composer license policy'
        );
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

function wp_fts_release_readiness_contract_public_placeholder_artifacts_blocked(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $source = $tmp . '/source';
        wp_fts_release_readiness_contract_write_file($source . '/indexer.php', "<?php\n/**\n * Plugin Name: Pure PHP FTS Indexer\n * Version: 9.9.9\n */\n");
        wp_fts_release_readiness_contract_write_json($source . '/composer.json', [
            'name' => 'local/wp-pure-php-fts',
            'type' => 'wordpress-plugin',
            'license' => 'GPL-2.0-or-later',
            'require' => [
                'php' => '>=8.1',
                'wp-php-toolkit/full-text-search' => '^0.1',
            ],
        ]);
        wp_fts_release_readiness_contract_write_file($source . '/readme.txt', "=== Pure PHP FTS Indexer ===\nStable tag: 9.9.9\n");
        wp_fts_release_readiness_contract_write_file($source . '/LICENSE', "placeholder license text\n");
        wp_fts_release_readiness_contract_write_file($source . '/assets/not-a-wordpress-org-asset.txt', "placeholder asset\n");
        wp_fts_release_readiness_contract_write_file($source . '/docs/release-packaging.md', "Public submission placeholders are present.\n");

        $report = (new WP_FTS_ReleaseReadinessChecker())->check([
            'target' => 'public-submission',
            'plugin_src' => $source,
            'monorepo_root' => $tmp,
        ]);
        $ids = wp_fts_release_readiness_contract_blocker_ids($report);

        wp_fts_release_readiness_contract_same('blocked', $report['status'] ?? null, 'placeholder public-submission artifacts must not pass readiness');
        foreach (['package_license_file', 'package_public_assets', 'package_readme_txt', 'public_submission_authority_evidence'] as $id) {
            wp_fts_release_readiness_contract_true(in_array($id, $ids, true), "placeholder public-submission fixture should report blocker {$id}");
        }
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

/**
 * @param callable(string):void $writeAssets
 * @return array<string,mixed>
 */
function wp_fts_release_readiness_contract_public_asset_report(string $tmp, callable $writeAssets): array
{
    $source = wp_fts_release_readiness_contract_source_fixture($tmp, [
        'license' => 'GPL-2.0-or-later',
        'readme' => true,
        'license_file' => true,
        'public_docs_ready' => true,
        'public_evidence' => true,
    ]);
    $writeAssets($source);

    return (new WP_FTS_ReleaseReadinessChecker())->check([
        'target' => 'public-submission',
        'plugin_src' => $source,
        'monorepo_root' => dirname($source),
    ]);
}

function wp_fts_release_readiness_contract_public_asset_dimensions_and_placeholders_blocked(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $onePixel = wp_fts_release_readiness_contract_public_asset_report(
            $tmp . '/one-pixel',
            static function (string $source): void {
                wp_fts_release_readiness_contract_write_file(
                    $source . '/assets/banner-772x250.png',
                    wp_fts_release_readiness_contract_png_fixture(1, 1)
                );
                wp_fts_release_readiness_contract_write_file(
                    $source . '/assets/icon-128x128.png',
                    wp_fts_release_readiness_contract_png_fixture(1, 1)
                );
            }
        );
        wp_fts_release_readiness_contract_true(
            in_array('package_public_assets', wp_fts_release_readiness_contract_blocker_ids($onePixel), true),
            '1x1 public asset placeholders should block public-submission readiness'
        );
        wp_fts_release_readiness_contract_contains('trivial_dimensions', WP_FTS_ReleaseReadinessChecker::render_json($onePixel), '1x1 assets should be reported as trivial dimensions');

        $blank = wp_fts_release_readiness_contract_public_asset_report(
            $tmp . '/blank',
            static function (string $source): void {
                wp_fts_release_readiness_contract_write_file(
                    $source . '/assets/banner-772x250.png',
                    wp_fts_release_readiness_contract_png_fixture(772, 250, 'blank')
                );
                wp_fts_release_readiness_contract_write_file(
                    $source . '/assets/icon-128x128.png',
                    wp_fts_release_readiness_contract_png_fixture(128, 128, 'blank')
                );
            }
        );
        wp_fts_release_readiness_contract_true(
            in_array('package_public_assets', wp_fts_release_readiness_contract_blocker_ids($blank), true),
            'blank single-color public assets should block public-submission readiness'
        );
        wp_fts_release_readiness_contract_contains('blank_single_color', WP_FTS_ReleaseReadinessChecker::render_json($blank), 'blank assets should be reported as single-color placeholders');

        $wrongSize = wp_fts_release_readiness_contract_public_asset_report(
            $tmp . '/wrong-size',
            static function (string $source): void {
                wp_fts_release_readiness_contract_write_file(
                    $source . '/assets/banner-772x250.png',
                    wp_fts_release_readiness_contract_png_fixture(771, 250)
                );
                wp_fts_release_readiness_contract_write_file(
                    $source . '/assets/icon-128x128.png',
                    wp_fts_release_readiness_contract_png_fixture(128, 128)
                );
            }
        );
        wp_fts_release_readiness_contract_true(
            in_array('package_public_assets', wp_fts_release_readiness_contract_blocker_ids($wrongSize), true),
            'wrong-size public assets should block public-submission readiness'
        );
        wp_fts_release_readiness_contract_contains('wrong_dimensions', WP_FTS_ReleaseReadinessChecker::render_json($wrongSize), 'wrong-size assets should report exact dimension failures');

        $malformed = wp_fts_release_readiness_contract_public_asset_report(
            $tmp . '/malformed',
            static function (string $source): void {
                wp_fts_release_readiness_contract_write_file($source . '/assets/banner-772x250.png', "not a png\n");
                wp_fts_release_readiness_contract_write_file($source . '/assets/icon-128x128.png', "also not a png\n");
            }
        );
        wp_fts_release_readiness_contract_true(
            in_array('package_public_assets', wp_fts_release_readiness_contract_blocker_ids($malformed), true),
            'malformed files with expected public asset names should block public-submission readiness'
        );
        wp_fts_release_readiness_contract_contains('malformed_png', WP_FTS_ReleaseReadinessChecker::render_json($malformed), 'malformed asset files should report PNG parse failures');
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

/**
 * @param array<string,mixed> $report
 */
function wp_fts_release_readiness_contract_assert_public_assets_blocked_with_reason(array $report, string $reason, string $message): void
{
    wp_fts_release_readiness_contract_true(
        in_array('package_public_assets', wp_fts_release_readiness_contract_blocker_ids($report), true),
        $message
    );
    wp_fts_release_readiness_contract_contains($reason, WP_FTS_ReleaseReadinessChecker::render_json($report), $message . " should report {$reason}");
}

function wp_fts_release_readiness_contract_bytes_have_high_bit(string $bytes): bool
{
    return $bytes !== '' && ord($bytes[0]) >= 0x80;
}

function wp_fts_release_readiness_contract_public_asset_png_integrity_blocked(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $validBanner = wp_fts_release_readiness_contract_png_fixture(772, 250);
        $validIcon = wp_fts_release_readiness_contract_png_fixture(128, 128);

        $badHeaderCheck = wp_fts_release_readiness_contract_png_first_chunk_data($validIcon, 'IDAT');
        $badHeaderCheck[1] = "\0";

        $badAdler = wp_fts_release_readiness_contract_png_first_chunk_data($validIcon, 'IDAT');
        $badAdler[strlen($badAdler) - 1] = chr(ord($badAdler[strlen($badAdler) - 1]) ^ 0xff);

        $oversizedDecoded = wp_fts_release_readiness_contract_zlib_store(
            wp_fts_release_readiness_contract_png_rgb_scanlines(128, 128) . "\0"
        );

        $cases = [
            'corrupt-ihdr-crc' => [
                'banner' => wp_fts_release_readiness_contract_png_corrupt_chunk_crc($validBanner, 'IHDR'),
                'icon' => $validIcon,
                'reason' => 'checksum_mismatch',
            ],
            'corrupt-idat-crc' => [
                'banner' => wp_fts_release_readiness_contract_png_corrupt_chunk_crc($validBanner, 'IDAT'),
                'icon' => $validIcon,
                'reason' => 'checksum_mismatch',
            ],
            'corrupt-iend-crc' => [
                'banner' => wp_fts_release_readiness_contract_png_corrupt_chunk_crc($validBanner, 'IEND'),
                'icon' => $validIcon,
                'reason' => 'checksum_mismatch',
            ],
            'invalid-zlib-header-check' => [
                'banner' => $validBanner,
                'icon' => wp_fts_release_readiness_contract_png_replace_first_chunk_data($validIcon, 'IDAT', $badHeaderCheck),
                'reason' => 'malformed_png',
            ],
            'invalid-adler32' => [
                'banner' => $validBanner,
                'icon' => wp_fts_release_readiness_contract_png_replace_first_chunk_data($validIcon, 'IDAT', $badAdler),
                'reason' => 'checksum_mismatch',
            ],
            'oversized-idat' => [
                'banner' => $validBanner,
                'icon' => wp_fts_release_readiness_contract_png_replace_first_chunk_data($validIcon, 'IDAT', str_repeat('x', 300000)),
                'reason' => 'oversized_payload',
            ],
            'oversized-decoded' => [
                'banner' => $validBanner,
                'icon' => wp_fts_release_readiness_contract_png_replace_first_chunk_data($validIcon, 'IDAT', $oversizedDecoded),
                'reason' => 'oversized_payload',
            ],
        ];

        foreach ($cases as $name => $case) {
            $report = wp_fts_release_readiness_contract_public_asset_report(
                $tmp . '/' . $name,
                static function (string $source) use ($case): void {
                    wp_fts_release_readiness_contract_write_public_asset_pair(
                        $source,
                        (string) $case['banner'],
                        (string) $case['icon']
                    );
                }
            );
            wp_fts_release_readiness_contract_assert_public_assets_blocked_with_reason(
                $report,
                (string) $case['reason'],
                "{$name} public PNG fixture should block public-submission readiness"
            );
        }
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

function wp_fts_release_readiness_contract_public_asset_high_bit_checksums_are_portable(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $validBanner = wp_fts_release_readiness_contract_png_fixture(772, 250);
        $validIcon = wp_fts_release_readiness_contract_png_fixture(128, 128);
        $idat = wp_fts_release_readiness_contract_png_first_chunk_data($validIcon, 'IDAT');

        wp_fts_release_readiness_contract_true(
            wp_fts_release_readiness_contract_bytes_have_high_bit(wp_fts_release_readiness_contract_png_first_chunk_crc($validIcon, 'IEND')),
            'fixture IEND CRC should exercise unsigned 32-bit checksum bytes above PHP_INT_MAX on 32-bit runtimes'
        );
        wp_fts_release_readiness_contract_true(
            wp_fts_release_readiness_contract_bytes_have_high_bit(substr($idat, -4)),
            'fixture Adler-32 should exercise unsigned 32-bit checksum bytes above PHP_INT_MAX on 32-bit runtimes'
        );

        $ready = wp_fts_release_readiness_contract_public_asset_report(
            $tmp . '/high-bit-ready',
            static function (string $source) use ($validBanner, $validIcon): void {
                wp_fts_release_readiness_contract_write_public_asset_pair($source, $validBanner, $validIcon);
            }
        );
        wp_fts_release_readiness_contract_same('ready', $ready['status'] ?? null, 'valid public PNG assets with high-bit CRC and Adler bytes should pass readiness');

        $badAdler = $idat;
        $badAdler[strlen($badAdler) - 1] = chr(ord($badAdler[strlen($badAdler) - 1]) ^ 0xff);
        $cases = [
            'corrupt-high-bit-iend-crc' => [
                'banner' => wp_fts_release_readiness_contract_png_corrupt_chunk_crc($validBanner, 'IEND'),
                'icon' => $validIcon,
            ],
            'corrupt-high-bit-adler32' => [
                'banner' => $validBanner,
                'icon' => wp_fts_release_readiness_contract_png_replace_first_chunk_data($validIcon, 'IDAT', $badAdler),
            ],
        ];

        foreach ($cases as $name => $case) {
            $report = wp_fts_release_readiness_contract_public_asset_report(
                $tmp . '/' . $name,
                static function (string $source) use ($case): void {
                    wp_fts_release_readiness_contract_write_public_asset_pair(
                        $source,
                        (string) $case['banner'],
                        (string) $case['icon']
                    );
                }
            );
            wp_fts_release_readiness_contract_assert_public_assets_blocked_with_reason(
                $report,
                'checksum_mismatch',
                "{$name} public PNG fixture should fail checksum validation"
            );
        }
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

function wp_fts_release_readiness_contract_public_complete_fixture_ready(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $source = wp_fts_release_readiness_contract_source_fixture($tmp, [
            'license' => 'GPL-2.0-or-later',
            'readme' => true,
            'readme_faq_heading' => 'Frequently Asked Questions',
            'license_file' => true,
            'public_assets' => true,
            'public_docs_ready' => true,
            'public_evidence' => true,
        ]);
        $report = (new WP_FTS_ReleaseReadinessChecker())->check([
            'target' => 'public-submission',
            'plugin_src' => $source,
            'monorepo_root' => dirname($source),
        ]);

        wp_fts_release_readiness_contract_same('ready', $report['status'] ?? null, 'complete public-submission evidence fixture should pass readiness');
        wp_fts_release_readiness_contract_true(
            wp_fts_release_readiness_contract_has_check($report, 'public_submission_authority_evidence', 'pass'),
            'complete public-submission fixture should validate authority evidence'
        );
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

/** Seed every forbidden payload class and require package readiness to reject it. */
function wp_fts_release_readiness_contract_prohibited_package_paths(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $source = wp_fts_release_readiness_contract_source_fixture($tmp);
        $package = wp_fts_release_readiness_contract_package_fixture($tmp);
        wp_fts_release_readiness_contract_write_file($package . '/tests/smoke.php', "<?php\n");
        wp_fts_release_readiness_contract_write_file($package . '/tools/smoke-disposable-wordpress-release.php', "<?php\n");
        wp_fts_release_readiness_contract_write_file($package . '/vendor/bin/phpunit', "#!/usr/bin/env php\n");
        wp_fts_release_readiness_contract_write_file($package . '/vendor/example/library/coverage/report.xml', "<xml />\n");
        wp_fts_release_readiness_contract_write_file($package . '/vendor/wp-php-toolkit/full-text-search/resources/sources/jieba/jieba/dict.txt', "raw source fixture\n");
        wp_fts_release_readiness_contract_write_file($package . '/playground/indexer-preview.zip', "zip fixture\n");
        wp_fts_release_readiness_contract_write_file($package . '/cache/object-cache.bin', "cache fixture\n");
        wp_fts_release_readiness_contract_write_file($package . '/.gitignore', "*\n");

        $report = (new WP_FTS_ReleaseReadinessChecker())->check([
            'target' => 'direct-install',
            'plugin_src' => $source,
            'monorepo_root' => $tmp,
            'package_dir' => $package,
        ]);
        $ids = wp_fts_release_readiness_contract_blocker_ids($report);

        wp_fts_release_readiness_contract_same('blocked', $report['status'] ?? null, 'prohibited staged package paths should block direct-install readiness');
        wp_fts_release_readiness_contract_true(in_array('direct_package_prohibited_paths', $ids, true), 'prohibited paths should report the package boundary blocker');
        $json = WP_FTS_ReleaseReadinessChecker::render_json($report);
        foreach (['indexer/tests', 'indexer/tools/smoke-disposable-wordpress-release.php', 'indexer/vendor/bin', 'indexer/vendor/example/library/coverage', 'indexer/vendor/wp-php-toolkit/full-text-search/resources/sources', 'indexer/playground/indexer-preview.zip'] as $path) {
            wp_fts_release_readiness_contract_contains($path, $json, "prohibited path report should include {$path}");
        }
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

function wp_fts_release_readiness_contract_version_mismatch(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $source = wp_fts_release_readiness_contract_source_fixture($tmp, ['version' => '1.2.3']);
        $package = wp_fts_release_readiness_contract_package_fixture($tmp, '1.2.4');
        $report = (new WP_FTS_ReleaseReadinessChecker())->check([
            'target' => 'direct-install',
            'plugin_src' => $source,
            'monorepo_root' => $tmp,
            'package_dir' => $package,
        ]);

        wp_fts_release_readiness_contract_same('blocked', $report['status'] ?? null, 'package/source version mismatch should block direct-install readiness');
        wp_fts_release_readiness_contract_true(
            in_array('version_metadata_mismatch', wp_fts_release_readiness_contract_blocker_ids($report), true),
            'version mismatch should use the stable version_metadata_mismatch blocker id'
        );
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

function wp_fts_release_readiness_contract_default_direct_cli_output_is_deterministic(): void
{
    $root = dirname(__DIR__, 2);
    $monorepoRoot = dirname($root);
    $command = [PHP_BINARY, 'indexer/tools/check-release-readiness.php', '--target=direct-install'];

    $first = wp_fts_release_readiness_contract_run_command($command, $monorepoRoot);
    $second = wp_fts_release_readiness_contract_run_command($command, $monorepoRoot);

    wp_fts_release_readiness_contract_same(0, $first['exit'], 'first default direct-install readiness CLI run should pass');
    wp_fts_release_readiness_contract_same('', $first['stderr'], 'first default direct-install readiness CLI run should not emit stderr');
    wp_fts_release_readiness_contract_same(0, $second['exit'], 'second default direct-install readiness CLI run should pass');
    wp_fts_release_readiness_contract_same('', $second['stderr'], 'second default direct-install readiness CLI run should not emit stderr');
    wp_fts_release_readiness_contract_same($first['stdout'], $second['stdout'], 'default direct-install readiness CLI JSON output should be deterministic across unchanged runs');
}

function wp_fts_release_readiness_contract_concurrent_default_direct_cli_output_is_deterministic(): void
{
    $root = dirname(__DIR__, 2);
    $monorepoRoot = dirname($root);
    $command = [PHP_BINARY, 'indexer/tools/check-release-readiness.php', '--target=direct-install'];
    $results = wp_fts_release_readiness_contract_run_concurrent_commands($command, $monorepoRoot, 2);

    foreach ($results as $index => $result) {
        wp_fts_release_readiness_contract_same(0, $result['exit'], "concurrent default direct-install readiness CLI run {$index} should pass");
        wp_fts_release_readiness_contract_same('', $result['stderr'], "concurrent default direct-install readiness CLI run {$index} should not emit stderr");
    }
    wp_fts_release_readiness_contract_same(
        $results[0]['stdout'],
        $results[1]['stdout'],
        'concurrent default direct-install readiness CLI JSON output should be deterministic across unchanged runs'
    );
}

function wp_fts_release_readiness_contract_deterministic_output_and_docs(): void
{
    $tmp = wp_fts_release_readiness_contract_temp_dir();
    try {
        $source = wp_fts_release_readiness_contract_source_fixture($tmp);
        $package = wp_fts_release_readiness_contract_package_fixture($tmp);
        $options = [
            'target' => 'direct-install',
            'plugin_src' => $source,
            'monorepo_root' => $tmp,
            'package_dir' => $package,
        ];
        $checker = new WP_FTS_ReleaseReadinessChecker();
        $first = WP_FTS_ReleaseReadinessChecker::render_json($checker->check($options));
        $second = WP_FTS_ReleaseReadinessChecker::render_json($checker->check($options));
        wp_fts_release_readiness_contract_same($first, $second, 'release-readiness JSON output should be deterministic for an unchanged package');
        wp_fts_release_readiness_contract_contains('Release readiness target: direct-install', WP_FTS_ReleaseReadinessChecker::render_text($checker->check($options)), 'text output should name the checked target');

        $root = dirname(__DIR__, 2);
        $releaseDocs = (string) file_get_contents($root . '/docs/release-packaging.md');
        $testingDocs = (string) file_get_contents($root . '/docs/testing.md');
        foreach (['check-release-readiness.php', '--target=direct-install', '--target=public-submission'] as $needle) {
            wp_fts_release_readiness_contract_contains($needle, $releaseDocs . "\n" . $testingDocs, "release docs should document {$needle}");
        }
        wp_fts_release_readiness_contract_contains('not the same as WordPress.org/SVN', $releaseDocs, 'release docs should distinguish direct-install readiness from public submission');
    } finally {
        wp_fts_release_readiness_contract_remove_tree($tmp);
    }
}

/**
 * @return array<int,array{name:string,fn:callable():void}>
 */
function wp_fts_release_readiness_contract_cases(): array
{
    $cases = [
        [
            'name' => 'quality release readiness accepts a staged direct-install package',
            'fn' => static function (): void {
                wp_fts_release_readiness_contract_direct_ready();
            },
        ],
        [
            'name' => 'quality release readiness requires every shipped tool module',
            'fn' => static function (): void {
                wp_fts_release_readiness_contract_missing_shipped_tool();
            },
        ],
        [
            'name' => 'quality release readiness blocks analyzer pack tampering',
            'fn' => static function (): void {
                wp_fts_release_readiness_contract_analyzer_pack_tampering();
            },
        ],
        [
            'name' => 'quality release readiness blocks current public submission state',
            'fn' => static function (): void {
                wp_fts_release_readiness_contract_current_public_blocked();
            },
        ],
        [
            'name' => 'quality release readiness reports public metadata and license blockers',
            'fn' => static function (): void {
                wp_fts_release_readiness_contract_public_readme_and_license_blockers();
            },
        ],
        [
            'name' => 'quality release readiness blocks placeholder public-submission artifacts',
            'fn' => static function (): void {
                wp_fts_release_readiness_contract_public_placeholder_artifacts_blocked();
            },
        ],
        [
            'name' => 'quality release readiness blocks invalid public asset dimensions and placeholders',
            'fn' => static function (): void {
                wp_fts_release_readiness_contract_public_asset_dimensions_and_placeholders_blocked();
            },
        ],
        [
            'name' => 'quality release readiness blocks corrupt public PNG checksums and bounded payloads',
            'fn' => static function (): void {
                wp_fts_release_readiness_contract_public_asset_png_integrity_blocked();
            },
        ],
        [
            'name' => 'quality release readiness handles high-bit public PNG checksums portably',
            'fn' => static function (): void {
                wp_fts_release_readiness_contract_public_asset_high_bit_checksums_are_portable();
            },
        ],
        [
            'name' => 'quality release readiness accepts complete public-submission evidence fixtures',
            'fn' => static function (): void {
                wp_fts_release_readiness_contract_public_complete_fixture_ready();
            },
        ],
        [
            'name' => 'quality release readiness detects prohibited direct package paths',
            'fn' => static function (): void {
                wp_fts_release_readiness_contract_prohibited_package_paths();
            },
        ],
        [
            'name' => 'quality release readiness detects version mismatches',
            'fn' => static function (): void {
                wp_fts_release_readiness_contract_version_mismatch();
            },
        ],
    ];

    // These cases build ZIP artifacts in this process. The normal PHP lanes
    // provide ZipArchive; php -n intentionally exercises the remaining cases.
    if (class_exists('ZipArchive')) {
        $cases[] = [
            'name' => 'quality release readiness default direct-install CLI output is deterministic',
            'fn' => static function (): void {
                wp_fts_release_readiness_contract_default_direct_cli_output_is_deterministic();
            },
        ];
        $cases[] = [
            'name' => 'quality release readiness concurrent default direct-install CLI output is deterministic',
            'fn' => static function (): void {
                wp_fts_release_readiness_contract_concurrent_default_direct_cli_output_is_deterministic();
            },
        ];
    }

    $cases[] = [
        'name' => 'quality release readiness output and docs are deterministic',
        'fn' => static function (): void {
            wp_fts_release_readiness_contract_deterministic_output_and_docs();
        },
    ];

    return $cases;
}

function wp_fts_release_readiness_contract_run_standalone(): void
{
    $failures = 0;
    $pending = 0;
    $cases = wp_fts_release_readiness_contract_cases();

    foreach ($cases as $case) {
        try {
            ($case['fn'])();
            fwrite(STDOUT, "[PASS] {$case['name']}\n");
        } catch (WP_FTS_Release_Readiness_Contract_Pending $e) {
            $pending++;
            fwrite(STDOUT, "[PEND] {$case['name']}\n{$e->getMessage()}\n");
        } catch (Throwable $e) {
            $failures++;
            fwrite(STDERR, "[FAIL] {$case['name']}\n{$e->getMessage()}\n");
        }
    }

    $passed = count($cases) - $failures - $pending;
    $summary = "{$passed}/" . count($cases) . " release readiness contract tests passed; failures={$failures}; pending={$pending}\n";
    if ($failures > 0) {
        fwrite(STDERR, $summary);
        exit(1);
    }

    fwrite(STDOUT, $summary);
    exit(0);
}

if (function_exists('test_case')) {
    foreach (wp_fts_release_readiness_contract_cases() as $case) {
        test_case($case['name'], $case['fn']);
    }
} elseif (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    wp_fts_release_readiness_contract_run_standalone();
}
