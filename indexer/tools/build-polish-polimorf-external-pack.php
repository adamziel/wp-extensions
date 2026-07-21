<?php
declare(strict_types=1);

require_once __DIR__ . '/import-polish-polimorf-lemmatizer.php';

/**
 * Builds an opt-in Polish PoliMorf runtime pack outside the plugin package.
 *
 * This wrapper owns the package-safety checks around the deterministic importer:
 * source identity verification, explicit download acknowledgement, output-path
 * guardrails, validation, and an operator-facing build summary.
 */
final class WP_FTS_PolishPolimorfExternalPackBuilder
{
    public const APPROVED_SOURCE_URL = 'https://clarin-pl.eu/dspace/bitstream/handle/11321/577/polimorf-20180722.tab.gz?isAllowed=y&sequence=1';
    public const APPROVED_SOURCE_SHA256 = '2b1f07224c434c8710def382d497cf8221d5764e8d683d2ad34242810ab72746';
    public const APPROVED_SOURCE_BYTES = 41550540;
    public const APPROVED_SOURCE_FILE = 'polimorf-20180722.tab.gz';

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function build(array $options): array
    {
        $download = $this->bool_option($options['download'] ?? false);
        $source = $this->resolve_source($options, $download);
        $outDir = $this->required_string($options, 'out');
        WP_FTS_LemmaSourceImportLimits::assert_source_output_separate(
            $source,
            $outDir,
            'PoliMorf external pack builder'
        );

        $this->reject_secret_path($outDir, 'output directory');
        $this->assert_external_directory($outDir, 'output directory', $this->bool_option($options['allow_repo_output'] ?? false));
        $expectedSha = $this->expected_sha256($options);
        $expectedBytes = $this->expected_bytes($options);
        $sourceVerification = $this->verify_source($source, $expectedSha, $expectedBytes);
        $this->prepare_output_directory($outDir, $this->bool_option($options['replace_output'] ?? false));

        $importOptions = [
            'source' => $source,
            'out' => $outDir,
            'pack_id' => (string) ($options['pack_id'] ?? 'pl-polimorf-20180722-full'),
            'version' => (string) ($options['version'] ?? '2018.07.22-external-pack-v1'),
            'source_url' => (string) ($options['source_url'] ?? self::APPROVED_SOURCE_URL),
            'source_name' => (string) ($options['source_name'] ?? 'PoliMorf Polish morphological dictionary'),
            'source_version' => (string) ($options['source_version'] ?? '2018.07.22'),
            'source_retrieval_note' => (string) ($options['source_retrieval_note'] ?? 'External pack workflow verified the source SHA-256 and byte count before import; generated runtime data is installed outside the plugin package.'),
            'fixture_only' => $this->bool_option($options['fixture_only'] ?? false),
            'importer_commit' => (string) ($options['importer_commit'] ?? 'external-pack-workflow'),
        ];
        foreach (['max_rows_per_file', 'chunk_rows', 'tmp_dir'] as $key) {
            if (array_key_exists($key, $options)) {
                if ($key === 'tmp_dir') {
                    $this->reject_secret_path((string) $options[$key], 'temporary directory');
                }
                $importOptions[$key] = $options[$key];
            }
        }

        $importSummary = (new WP_FTS_PolishPolimorfImporter())->import($importOptions);
        $importedSource = is_array($importSummary['source'] ?? null) ? $importSummary['source'] : [];
        if (
            !is_string($importedSource['sha256'] ?? null)
            || !hash_equals($sourceVerification['sha256'], $importedSource['sha256'])
            || ($importedSource['bytes'] ?? null) !== $sourceVerification['bytes']
        ) {
            $this->remove_tree($outDir);
            throw new RuntimeException('PoliMorf source changed between external verification and the importer snapshot.');
        }
        $validator = new WP_FTS_AnalyzerPackValidator();
        $validation = $validator->validate((string) $importSummary['manifest'], false);
        $pack = WP_FTS_LanguageLemmaPack::from_manifest_file(
            (string) $importSummary['manifest'],
            $validator,
            'pl'
        );
        if ($pack->runtime_file_count() !== (int) $importSummary['runtime']['files']) {
            throw new RuntimeException('Generated PoliMorf pack activation retained an unexpected runtime shard count.');
        }
        if ($pack->lookup_block_count() !== (int) $importSummary['lookup']['blocks']) {
            throw new RuntimeException('Generated PoliMorf pack activation retained an unexpected lookup block count.');
        }

        return $this->build_summary($download ? 'download' : 'local', $sourceVerification, $importSummary, $validation);
    }

    /**
     * @param string[] $argv
     * @return array<string,mixed>
     */
    public static function parse_cli_options(array $argv): array
    {
        return WP_FTS_PolishPolimorfImporter::parse_cli_options($argv);
    }

    /**
     * @param array<string,mixed> $options
     */
    private function resolve_source(array $options, bool $download): string
    {
        $hasSource = isset($options['source']) && is_scalar($options['source']) && trim((string) $options['source']) !== '';
        if ($download && $hasSource) {
            throw new RuntimeException('Use either --source or --download, not both.');
        }
        if (!$download && !$hasSource) {
            throw new RuntimeException('Missing required option --source, or pass --download with --cache-dir and --acknowledge-license=BSD-2-Clause.');
        }

        if (!$download) {
            $source = (string) $options['source'];
            $this->reject_secret_path($source, 'source artifact');
            if (!is_file($source)) {
                throw new RuntimeException("Source artifact does not exist: {$source}");
            }

            return $source;
        }

        $acknowledgement = isset($options['acknowledge_license']) && is_scalar($options['acknowledge_license'])
            ? trim((string) $options['acknowledge_license'])
            : '';
        if ($acknowledgement !== 'BSD-2-Clause') {
            throw new RuntimeException('Download mode requires --acknowledge-license=BSD-2-Clause.');
        }

        $cacheDir = $this->required_string($options, 'cache_dir');
        $this->reject_secret_path($cacheDir, 'cache directory');
        $this->assert_external_directory($cacheDir, 'cache directory', $this->bool_option($options['allow_repo_cache'] ?? false));
        if (is_file($cacheDir)) {
            throw new RuntimeException("Cache path is a file: {$cacheDir}");
        }
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true)) {
            throw new RuntimeException("Could not create cache directory: {$cacheDir}");
        }

        $source = rtrim($cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::APPROVED_SOURCE_FILE;
        $this->reject_secret_path($source, 'downloaded source artifact');
        if (is_file($source)) {
            return $source;
        }

        $this->download_approved_source($source);

        return $source;
    }

    /** Stream the approved download into a bounded, cleanup-safe partial file. */
    private function download_approved_source(string $target): void
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('Download mode requires allow_url_fopen; alternatively download the approved artifact separately and pass --source.');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: wp-fts-polimorf-external-pack-builder/1\r\n",
                'timeout' => 60,
            ],
        ]);
        $input = fopen(self::APPROVED_SOURCE_URL, 'rb', false, $context);
        if (!is_resource($input)) {
            throw new RuntimeException('Could not download approved PoliMorf source artifact.');
        }

        $partial = $target . '.partial';
        $this->reject_secret_path($partial, 'partial download path');
        $output = fopen($partial, 'wb');
        if (!is_resource($output)) {
            fclose($input);
            throw new RuntimeException("Could not write partial download: {$partial}");
        }

        $downloadComplete = false;
        $downloadedBytes = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 1048576);
                if ($chunk === false) {
                    throw new RuntimeException('Could not read source download stream.');
                }
                if ($chunk !== '') {
                    $downloadedBytes += strlen($chunk);
                    if ($downloadedBytes > WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_PHYSICAL_BYTES) {
                        throw new RuntimeException('Downloaded PoliMorf source exceeds the 64 MiB physical source limit.');
                    }
                    if (fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('Could not write source download stream.');
                    }
                }
            }
            $downloadComplete = true;
        } finally {
            fclose($input);
            fclose($output);
            if (!$downloadComplete) {
                @unlink($partial);
            }
        }

        if (!rename($partial, $target)) {
            @unlink($partial);
            throw new RuntimeException("Could not finalize downloaded source artifact: {$target}");
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function verify_source(string $source, string $expectedSha, int $expectedBytes): array
    {
        $physical = WP_FTS_LemmaSourceImportLimits::source_physical_evidence(
            [$source],
            'PoliMorf external builder'
        );
        $bytes = $physical['bytes'];
        if ($bytes !== $expectedBytes) {
            throw new RuntimeException("Source byte count mismatch for {$source}: expected {$expectedBytes}, got {$bytes}.");
        }

        $hashed = WP_FTS_LemmaSourceImportLimits::hash_source_artifact(
            $source,
            $physical['file_evidence'][$source],
            'PoliMorf external builder'
        );
        $sha = $hashed['sha256'];
        if (!hash_equals($expectedSha, $sha)) {
            throw new RuntimeException("Source SHA-256 mismatch for {$source}: expected {$expectedSha}, got {$sha}.");
        }

        $gzipIntegrity = $this->verify_gzip_integrity($source);
        WP_FTS_LemmaSourceImportLimits::assert_source_artifact_unchanged(
            $source,
            $hashed,
            $physical['file_evidence'][$source],
            'PoliMorf external builder'
        );

        return [
            'path' => $source,
            'sha256' => $sha,
            'bytes' => $bytes,
            'expected_sha256' => $expectedSha,
            'expected_bytes' => $expectedBytes,
            'gzip_integrity' => $gzipIntegrity,
        ];
    }

    /**
     * @return array{status:string,method:string|null}
     */
    private function verify_gzip_integrity(string $source): array
    {
        if (!str_ends_with(strtolower($source), '.gz')) {
            return ['status' => 'not_applicable', 'method' => null];
        }

        if (function_exists('gzopen')) {
            $handle = gzopen($source, 'rb');
            if (!is_resource($handle)) {
                throw new RuntimeException("Could not open gzip source for integrity check: {$source}");
            }
            $decodedBytes = 0;
            while (!gzeof($handle)) {
                $chunk = gzread($handle, 1048576);
                if ($chunk === false) {
                    gzclose($handle);
                    throw new RuntimeException("Gzip integrity check failed for {$source}.");
                }
                $decodedBytes += strlen($chunk);
                if ($decodedBytes > WP_FTS_LemmaSourceImportLimits::MAX_SOURCE_DECODED_BYTES) {
                    gzclose($handle);
                    throw new RuntimeException('PoliMorf gzip source exceeds the 512 MiB decoded source limit.');
                }
            }
            gzclose($handle);

            return ['status' => 'passed', 'method' => 'php-zlib'];
        }

        throw new RuntimeException('Bounded PoliMorf gzip integrity verification requires PHP zlib support.');
    }

    /** Refuse symlink roots and replace only a recognizable generated pack. */
    private function prepare_output_directory(string $outDir, bool $replaceOutput): void
    {
        if (is_link($outDir)) {
            throw new RuntimeException("Output path must not be a symbolic link: {$outDir}");
        }
        if (is_file($outDir)) {
            throw new RuntimeException("Output path is a file: {$outDir}");
        }
        if (!is_dir($outDir)) {
            return;
        }

        $iterator = new FilesystemIterator($outDir, FilesystemIterator::SKIP_DOTS);
        if (!$iterator->valid()) {
            return;
        }
        if (!$replaceOutput) {
            throw new RuntimeException("Output directory must be empty: {$outDir}");
        }
        if (!is_file($outDir . DIRECTORY_SEPARATOR . 'manifest.json') || !is_file($outDir . DIRECTORY_SEPARATOR . 'SOURCE.lock.json') || !is_dir($outDir . DIRECTORY_SEPARATOR . 'runtime')) {
            throw new RuntimeException('Refusing to replace a non-empty output directory that does not look like a generated analyzer pack.');
        }

        $this->remove_tree($outDir);
    }

    private function assert_external_directory(string $path, string $label, bool $allowRepoPath): void
    {
        if ($allowRepoPath) {
            return;
        }

        $pluginRoot = realpath(dirname(__DIR__));
        if (!is_string($pluginRoot)) {
            throw new RuntimeException('Could not resolve plugin root for output-path safety checks.');
        }
        $repositoryRoot = $this->resolve_repository_root($pluginRoot);
        $candidate = $this->canonical_candidate_path($path);
        if ($candidate === $pluginRoot || str_starts_with($candidate, $pluginRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("{$label} must be outside the committed plugin repository/package by default: {$path}");
        }
        if ($candidate === $repositoryRoot || str_starts_with($candidate, $repositoryRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("{$label} must be outside the committed Git repository worktree by default: {$path}");
        }
    }

    private function resolve_repository_root(string $pluginRoot): string
    {
        $current = $pluginRoot;
        while (true) {
            $gitEntry = $current . DIRECTORY_SEPARATOR . '.git';
            if (is_dir($gitEntry) || is_file($gitEntry)) {
                return $current;
            }

            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }

        $fallback = realpath(dirname($pluginRoot));
        if (!is_string($fallback)) {
            throw new RuntimeException('Could not resolve repository root for output-path safety checks.');
        }

        return $fallback;
    }

    private function canonical_candidate_path(string $path): string
    {
        $absolutePath = $this->absolute_path($path);
        $existing = $absolutePath;
        $suffix = [];
        while (!file_exists($existing)) {
            $parent = dirname($existing);
            if ($parent === $existing) {
                break;
            }
            array_unshift($suffix, basename($existing));
            $existing = $parent;
        }

        $real = realpath($existing);
        if (!is_string($real)) {
            throw new RuntimeException("Could not resolve path safety root for {$path}.");
        }
        foreach ($suffix as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                $real = dirname($real);
                continue;
            }
            $real .= DIRECTORY_SEPARATOR . $part;
        }

        return $real;
    }

    private function absolute_path(string $path): string
    {
        if ($path === '') {
            throw new RuntimeException('Could not resolve empty path for output-path safety checks.');
        }
        if ($this->is_absolute_path($path)) {
            return $path;
        }

        $cwd = getcwd();
        if (!is_string($cwd)) {
            throw new RuntimeException("Could not resolve current working directory for {$path}.");
        }

        return $cwd . DIRECTORY_SEPARATOR . $path;
    }

    private function is_absolute_path(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    private function reject_secret_path(string $path, string $label): void
    {
        $normalized = str_replace('\\', '/', $path);
        $basename = basename($normalized);
        $lower = strtolower($normalized);
        $lowerBase = strtolower($basename);
        if (
            $lowerBase === '.env'
            || str_ends_with($lowerBase, '.pem')
            || str_contains($lower, '/.ssh/')
            || str_contains($lower, '/.aws/')
            || str_contains($lower, '/credentials')
            || str_contains($lower, 'private-token')
            || str_contains($lower, 'secret-token')
        ) {
            throw new RuntimeException("Refusing to use {$label} because it looks like credentials or a secret file: {$path}");
        }
    }

    /**
     * @param array<string,mixed> $options
     */
    private function expected_sha256(array $options): string
    {
        $value = (string) ($options['expect_source_sha256'] ?? self::APPROVED_SOURCE_SHA256);
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new RuntimeException('--expect-source-sha256 must be a lower-case 64-character SHA-256 digest.');
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $options
     */
    private function expected_bytes(array $options): int
    {
        $raw = $options['expect_source_bytes'] ?? self::APPROVED_SOURCE_BYTES;
        if (!is_int($raw) && (!is_string($raw) || preg_match('/^[1-9][0-9]*$/', $raw) !== 1)) {
            throw new RuntimeException('--expect-source-bytes must be a positive integer.');
        }

        return (int) $raw;
    }

    /**
     * @param array<string,mixed> $options
     */
    private function required_string(array $options, string $key): string
    {
        if (!isset($options[$key]) || !is_scalar($options[$key]) || trim((string) $options[$key]) === '') {
            throw new RuntimeException("Missing required option --" . str_replace('_', '-', $key) . '.');
        }

        return (string) $options[$key];
    }

    private function bool_option(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $importSummary
     * @param array<string,mixed> $validation
     * @return array<string,mixed>
     */
    private function build_summary(string $mode, array $source, array $importSummary, array $validation): array
    {
        $manifestPath = (string) $importSummary['manifest'];
        $sourceLockPath = (string) $importSummary['source_lock'];

        return [
            'status' => 'ok',
            'mode' => $mode,
            'source' => $source,
            'manifest_path' => $manifestPath,
            'source_lock_path' => $sourceLockPath,
            'manifest_sha256' => $importSummary['manifest_sha256'],
            'pack_id' => $importSummary['pack_id'],
            'runtime' => [
                'rows' => $importSummary['runtime']['rows'],
                'files' => $importSummary['runtime']['files'],
                'bytes' => $importSummary['runtime']['bytes'],
                'decoded_bytes' => $importSummary['runtime']['decoded_bytes'],
                'encoded_bytes' => $importSummary['runtime']['encoded_bytes'],
                'sha256' => $importSummary['runtime']['sha256'],
            ],
            'lookup' => [
                'format' => $importSummary['lookup']['format'],
                'files' => $importSummary['lookup']['files'],
                'blocks' => $importSummary['lookup']['blocks'],
                'bytes' => $importSummary['lookup']['bytes'],
            ],
            'runtime_lookup_bytes' => $importSummary['runtime_lookup_bytes'],
            'validation' => [
                'status' => 'ok',
                'manifest_sha256' => $validation['manifest_sha256'],
                'runtime_files' => count($validation['runtime_files']),
                'activatable' => true,
                'lookup_blocks' => $importSummary['lookup']['blocks'],
            ],
            'configuration_example' => [
                'lemma_packs_by_lang' => [
                    'pl' => $manifestPath,
                ],
            ],
            'package_boundary' => 'The full PoliMorf runtime pack is generated and installed externally, is opt-in, remains default-disabled, and is not committed or bundled in the plugin repository/package.',
            'runtime_network_access' => false,
        ];
    }

    /** Remove a generated pack without following a nested or root symlink. */
    private function remove_tree(string $directory): void
    {
        if (is_link($directory)) {
            unlink($directory);
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
            if ($path->isLink() || !$path->isDir()) {
                unlink($path->getPathname());
            } else {
                rmdir($path->getPathname());
            }
        }
        rmdir($directory);
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === __FILE__) {
    try {
        $options = WP_FTS_PolishPolimorfExternalPackBuilder::parse_cli_options(array_slice($argv, 1));
        $summary = (new WP_FTS_PolishPolimorfExternalPackBuilder())->build($options);
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Polish PoliMorf external pack build failed: {$e->getMessage()}\n");
        exit(1);
    }
}
