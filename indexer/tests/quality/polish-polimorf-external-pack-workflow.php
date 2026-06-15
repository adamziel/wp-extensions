<?php
declare(strict_types=1);

/**
 * Focused tests for the package-safe external Polish PoliMorf pack workflow.
 *
 * This file is intentionally standalone so reviewers can run it directly, while
 * tests/run.php also discovers it as a normal quality test.
 */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/tools/build-polish-polimorf-external-pack.php';

final class WP_FTS_PPEW_Network_Trap_Stream
{
    public static int $opens = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        self::$opens++;

        return false;
    }
}

function wp_fts_ppew_fixture_source(): string
{
    return dirname(__DIR__) . '/fixtures/polimorf-importer/sample-polimorf.tab';
}

function wp_fts_ppew_plugin_root(): string
{
    return dirname(__DIR__, 2);
}

function wp_fts_ppew_repository_root(): string
{
    return dirname(wp_fts_ppew_plugin_root());
}

function wp_fts_ppew_temp_dir(string $suffix): string
{
    return sys_get_temp_dir() . '/wp_fts_ppew_' . getmypid() . '_' . $suffix . '_' . bin2hex(random_bytes(4));
}

function wp_fts_ppew_remove_tree(string $directory): void
{
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
        } else {
            unlink($path->getPathname());
        }
    }
    rmdir($directory);
}

/**
 * @param array<string,mixed> $extra
 * @return array<string,mixed>
 */
function wp_fts_ppew_fixture_options(string $out, array $extra = []): array
{
    $source = wp_fts_ppew_fixture_source();
    $bytes = filesize($source);
    if (!is_int($bytes)) {
        throw new RuntimeException('Could not measure synthetic PoliMorf fixture.');
    }
    $sha = hash_file('sha256', $source);
    if (!is_string($sha)) {
        throw new RuntimeException('Could not hash synthetic PoliMorf fixture.');
    }

    return $extra + [
        'source' => $source,
        'out' => $out,
        'expect_source_sha256' => $sha,
        'expect_source_bytes' => $bytes,
        'pack_id' => 'pl-polimorf-external-fixture',
        'version' => 'fixture-external-v1',
        'source_url' => 'urn:wp-fts:test:polimorf-external-pack',
        'source_name' => 'WP FTS source-shaped PoliMorf external pack fixture',
        'source_version' => 'fixture',
        'max_rows_per_file' => 2,
        'chunk_rows' => 2,
        'fixture_only' => false,
        'importer_commit' => 'test-commit',
    ];
}

/**
 * @param string[] $errors
 */
function wp_fts_ppew_check(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

/**
 * @return string[]
 */
function wp_fts_ppew_case_rejects_output_inside_plugin_root(): array
{
    $errors = [];
    $out = wp_fts_ppew_plugin_root() . '/ppew-plugin-output-' . getmypid() . '-' . bin2hex(random_bytes(4));
    try {
        (new WP_FTS_PolishPolimorfExternalPackBuilder())->build(
            wp_fts_ppew_fixture_options($out, ['expect_source_bytes' => 1])
        );
        $errors[] = 'output inside plugin root should fail before source verification';
    } catch (RuntimeException $e) {
        wp_fts_ppew_check(str_contains($e->getMessage(), 'plugin repository/package'), 'plugin-root output failure should name plugin package boundary', $errors);
        wp_fts_ppew_check(!str_contains($e->getMessage(), 'Source byte count mismatch'), 'plugin-root output failure should happen before source verification', $errors);
    } finally {
        wp_fts_ppew_remove_tree($out);
    }

    return $errors;
}

/**
 * @return string[]
 */
function wp_fts_ppew_case_rejects_output_inside_repository_root(): array
{
    $errors = [];
    $repoRoot = wp_fts_ppew_repository_root();
    $outName = 'ppew-repo-output-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $out = $repoRoot . '/' . $outName;
    $cwd = getcwd();
    try {
        if (!is_string($cwd) || !chdir($repoRoot)) {
            throw new RuntimeException("Could not enter repository root fixture: {$repoRoot}");
        }
        (new WP_FTS_PolishPolimorfExternalPackBuilder())->build(
            wp_fts_ppew_fixture_options($outName, ['expect_source_bytes' => 1])
        );
        $errors[] = 'output inside repository root should fail before source verification';
    } catch (RuntimeException $e) {
        wp_fts_ppew_check(str_contains($e->getMessage(), 'Git repository worktree'), 'repo-root output failure should name Git worktree boundary', $errors);
        wp_fts_ppew_check(!str_contains($e->getMessage(), 'Source byte count mismatch'), 'repo-root output failure should happen before source verification', $errors);
    } finally {
        if (is_string($cwd)) {
            chdir($cwd);
        }
        wp_fts_ppew_remove_tree($out);
    }

    return $errors;
}

/**
 * @return string[]
 */
function wp_fts_ppew_case_rejects_output_inside_repository_docs_directory(): array
{
    $errors = [];
    $repoRoot = wp_fts_ppew_repository_root();
    $out = $repoRoot . '/docs/ppew-docs-output-' . getmypid() . '-' . bin2hex(random_bytes(4));
    try {
        (new WP_FTS_PolishPolimorfExternalPackBuilder())->build(
            wp_fts_ppew_fixture_options($out, ['expect_source_bytes' => 1])
        );
        $errors[] = 'output inside repository docs directory should fail before source verification';
    } catch (RuntimeException $e) {
        wp_fts_ppew_check(str_contains($e->getMessage(), 'Git repository worktree'), 'docs output failure should name Git worktree boundary', $errors);
        wp_fts_ppew_check(!str_contains($e->getMessage(), 'Source byte count mismatch'), 'docs output failure should happen before source verification', $errors);
    } finally {
        wp_fts_ppew_remove_tree($out);
    }

    return $errors;
}

/**
 * @return string[]
 */
function wp_fts_ppew_case_rejects_cache_inside_repository_root(): array
{
    $errors = [];
    $repoRoot = wp_fts_ppew_repository_root();
    $cacheName = 'ppew-repo-cache-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $cache = $repoRoot . '/' . $cacheName;
    $out = wp_fts_ppew_temp_dir('cache_boundary_out');
    $cwd = getcwd();
    try {
        if (!is_string($cwd) || !chdir($repoRoot)) {
            throw new RuntimeException("Could not enter repository root fixture: {$repoRoot}");
        }
        (new WP_FTS_PolishPolimorfExternalPackBuilder())->build([
            'download' => true,
            'acknowledge_license' => 'BSD-2-Clause',
            'cache_dir' => $cacheName,
            'out' => $out,
        ]);
        $errors[] = 'cache inside repository root should fail before download setup';
    } catch (RuntimeException $e) {
        wp_fts_ppew_check(str_contains($e->getMessage(), 'Git repository worktree'), 'repo-root cache failure should name Git worktree boundary', $errors);
        wp_fts_ppew_check(!str_contains($e->getMessage(), 'allow_url_fopen'), 'repo-root cache failure should happen before download setup', $errors);
    } finally {
        if (is_string($cwd)) {
            chdir($cwd);
        }
        wp_fts_ppew_remove_tree($cache);
        wp_fts_ppew_remove_tree($out);
    }

    return $errors;
}

/**
 * @return string[]
 */
function wp_fts_ppew_case_rejects_cache_inside_repository_docs_directory(): array
{
    $errors = [];
    $repoRoot = wp_fts_ppew_repository_root();
    $cache = $repoRoot . '/docs/ppew-docs-cache-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $out = wp_fts_ppew_temp_dir('docs_cache_boundary_out');
    try {
        (new WP_FTS_PolishPolimorfExternalPackBuilder())->build([
            'download' => true,
            'acknowledge_license' => 'BSD-2-Clause',
            'cache_dir' => $cache,
            'out' => $out,
        ]);
        $errors[] = 'cache inside repository docs directory should fail before download setup';
    } catch (RuntimeException $e) {
        wp_fts_ppew_check(str_contains($e->getMessage(), 'Git repository worktree'), 'docs cache failure should name Git worktree boundary', $errors);
        wp_fts_ppew_check(!str_contains($e->getMessage(), 'allow_url_fopen'), 'docs cache failure should happen before download setup', $errors);
    } finally {
        wp_fts_ppew_remove_tree($cache);
        wp_fts_ppew_remove_tree($out);
    }

    return $errors;
}

/**
 * @return string[]
 */
function wp_fts_ppew_case_allows_safe_external_cache_path_to_reach_source_verification(): array
{
    $errors = [];
    $cache = wp_fts_ppew_temp_dir('safe_cache');
    $out = wp_fts_ppew_temp_dir('safe_cache_out');
    try {
        if (!mkdir($cache, 0777, true) && !is_dir($cache)) {
            throw new RuntimeException("Could not create safe cache fixture: {$cache}");
        }
        $cachedSource = $cache . '/' . WP_FTS_PolishPolimorfExternalPackBuilder::APPROVED_SOURCE_FILE;
        if (!copy(wp_fts_ppew_fixture_source(), $cachedSource)) {
            throw new RuntimeException("Could not seed safe cache fixture: {$cachedSource}");
        }

        (new WP_FTS_PolishPolimorfExternalPackBuilder())->build([
            'download' => true,
            'acknowledge_license' => 'BSD-2-Clause',
            'cache_dir' => $cache,
            'out' => $out,
            'expect_source_sha256' => str_repeat('0', 64),
            'expect_source_bytes' => 1,
        ]);
        $errors[] = 'safe external cache path should advance past repository boundary checks';
    } catch (RuntimeException $e) {
        wp_fts_ppew_check(str_contains($e->getMessage(), 'Source byte count mismatch'), 'safe external cache path should reach source verification without network', $errors);
        wp_fts_ppew_check(!str_contains($e->getMessage(), 'Git repository worktree'), 'safe external cache path should not fail repository boundary checks', $errors);
    } finally {
        wp_fts_ppew_remove_tree($cache);
        wp_fts_ppew_remove_tree($out);
    }

    return $errors;
}

/**
 * @return string[]
 */
function wp_fts_ppew_case_builds_local_fixture_and_stays_offline(): array
{
    $errors = [];
    $out = wp_fts_ppew_temp_dir('success');
    $scheme = 'wpftstestnet';
    $registeredTrap = false;
    try {
        if (!in_array($scheme, stream_get_wrappers(), true)) {
            $registeredTrap = stream_wrapper_register($scheme, WP_FTS_PPEW_Network_Trap_Stream::class);
        }

        $summary = (new WP_FTS_PolishPolimorfExternalPackBuilder())->build(
            wp_fts_ppew_fixture_options($out, [
                'source_url' => $scheme . '://polimorf-fixture',
            ])
        );

        wp_fts_ppew_check($summary['status'] === 'ok', 'external pack fixture build should pass', $errors);
        wp_fts_ppew_check(is_file((string) $summary['manifest_path']), 'build summary should expose generated manifest path', $errors);
        wp_fts_ppew_check(is_file((string) $summary['source_lock_path']), 'build summary should expose generated source lock path', $errors);
        wp_fts_ppew_check(($summary['runtime']['rows'] ?? null) === 6, 'external fixture build should generate six normalized runtime rows', $errors);
        wp_fts_ppew_check(($summary['runtime']['files'] ?? null) === 3, 'external fixture build should generate three runtime shards', $errors);
        wp_fts_ppew_check(($summary['runtime_network_access'] ?? null) === false, 'summary should declare no runtime network access', $errors);
        wp_fts_ppew_check(str_contains((string) $summary['package_boundary'], 'not committed or bundled'), 'summary should state package boundary', $errors);
        wp_fts_ppew_check(isset($summary['configuration_example']['polish_lemma_pack']), 'summary should include polish_lemma_pack example', $errors);
        wp_fts_ppew_check(isset($summary['configuration_example']['polish_lemmatizer_pack']), 'summary should include polish_lemmatizer_pack example', $errors);

        $validation = (new WP_FTS_AnalyzerPackValidator())->validate((string) $summary['manifest_path'], false);
        wp_fts_ppew_check(($validation['manifest']['pack_id'] ?? null) === 'pl-polimorf-external-fixture', 'generated external pack should validate', $errors);

        WP_FTS_PPEW_Network_Trap_Stream::$opens = 0;
        $lemmatizer = WP_FTS_PolishMorfologikLemmatizer::from_manifest_file((string) $summary['manifest_path']);
        wp_fts_ppew_check($lemmatizer->stem('kotami', 'pl') === 'kot', 'generated external pack should drive Polish lemmatizer lookup', $errors);
        wp_fts_ppew_check($lemmatizer->stem('drogi', 'pl') === 'drogi', 'generated external pack should preserve ambiguous forms', $errors);
        wp_fts_ppew_check(WP_FTS_PPEW_Network_Trap_Stream::$opens === 0, 'analyzer lookup should not open the source URL wrapper', $errors);
    } catch (Throwable $e) {
        $errors[] = 'unexpected success-case failure: ' . $e->getMessage();
    } finally {
        if ($registeredTrap) {
            stream_wrapper_unregister($scheme);
        }
        wp_fts_ppew_remove_tree($out);
    }

    return $errors;
}

/**
 * @return string[]
 */
function wp_fts_ppew_case_rejects_source_identity_mismatch(): array
{
    $errors = [];
    $outBytes = wp_fts_ppew_temp_dir('bad_bytes');
    $outHash = wp_fts_ppew_temp_dir('bad_hash');
    try {
        try {
            (new WP_FTS_PolishPolimorfExternalPackBuilder())->build(
                wp_fts_ppew_fixture_options($outBytes, ['expect_source_bytes' => 1])
            );
            $errors[] = 'bad source byte count should fail';
        } catch (RuntimeException $e) {
            wp_fts_ppew_check(str_contains($e->getMessage(), 'Source byte count mismatch'), 'bad source byte count should name mismatch', $errors);
        }

        try {
            (new WP_FTS_PolishPolimorfExternalPackBuilder())->build(
                wp_fts_ppew_fixture_options($outHash, ['expect_source_sha256' => str_repeat('0', 64)])
            );
            $errors[] = 'bad source hash should fail';
        } catch (RuntimeException $e) {
            wp_fts_ppew_check(str_contains($e->getMessage(), 'Source SHA-256 mismatch'), 'bad source hash should name mismatch', $errors);
        }
    } finally {
        wp_fts_ppew_remove_tree($outBytes);
        wp_fts_ppew_remove_tree($outHash);
    }

    return $errors;
}

/**
 * @return string[]
 */
function wp_fts_ppew_case_requires_download_license_acknowledgement(): array
{
    $errors = [];
    $cache = wp_fts_ppew_temp_dir('cache');
    $out = wp_fts_ppew_temp_dir('download_out');
    try {
        (new WP_FTS_PolishPolimorfExternalPackBuilder())->build([
            'download' => true,
            'cache_dir' => $cache,
            'out' => $out,
        ]);
        $errors[] = 'download mode without license acknowledgement should fail';
    } catch (RuntimeException $e) {
        wp_fts_ppew_check(str_contains($e->getMessage(), 'acknowledge-license=BSD-2-Clause'), 'download ack failure should name required acknowledgement', $errors);
    } finally {
        wp_fts_ppew_remove_tree($cache);
        wp_fts_ppew_remove_tree($out);
    }

    return $errors;
}

/**
 * @return string[]
 */
function wp_fts_ppew_case_refuses_non_empty_output(): array
{
    $errors = [];
    $out = wp_fts_ppew_temp_dir('nonempty');
    try {
        if (!mkdir($out, 0777, true) && !is_dir($out)) {
            throw new RuntimeException("Could not create non-empty output fixture: {$out}");
        }
        file_put_contents($out . '/keep.txt', "not a generated pack\n");
        (new WP_FTS_PolishPolimorfExternalPackBuilder())->build(wp_fts_ppew_fixture_options($out));
        $errors[] = 'non-empty output directory should fail';
    } catch (RuntimeException $e) {
        wp_fts_ppew_check(str_contains($e->getMessage(), 'Output directory must be empty'), 'non-empty output failure should name empty-directory requirement', $errors);
    } finally {
        wp_fts_ppew_remove_tree($out);
    }

    return $errors;
}

/**
 * @return string[]
 */
function wp_fts_ppew_run_verifier(): array
{
    return array_merge(
        wp_fts_ppew_case_rejects_output_inside_plugin_root(),
        wp_fts_ppew_case_rejects_output_inside_repository_root(),
        wp_fts_ppew_case_rejects_output_inside_repository_docs_directory(),
        wp_fts_ppew_case_rejects_cache_inside_repository_root(),
        wp_fts_ppew_case_rejects_cache_inside_repository_docs_directory(),
        wp_fts_ppew_case_allows_safe_external_cache_path_to_reach_source_verification(),
        wp_fts_ppew_case_builds_local_fixture_and_stays_offline(),
        wp_fts_ppew_case_rejects_source_identity_mismatch(),
        wp_fts_ppew_case_requires_download_license_acknowledgement(),
        wp_fts_ppew_case_refuses_non_empty_output()
    );
}

if (function_exists('test_case')) {
    test_case('quality Polish PoliMorf external pack workflow rejects output inside plugin root', function (): void {
        assert_same([], wp_fts_ppew_case_rejects_output_inside_plugin_root(), 'external pack workflow should reject plugin-root output paths');
    });

    test_case('quality Polish PoliMorf external pack workflow rejects output inside repository root', function (): void {
        assert_same([], wp_fts_ppew_case_rejects_output_inside_repository_root(), 'external pack workflow should reject repo-root output paths');
    });

    test_case('quality Polish PoliMorf external pack workflow rejects output inside repository docs directory', function (): void {
        assert_same([], wp_fts_ppew_case_rejects_output_inside_repository_docs_directory(), 'external pack workflow should reject docs output paths before source verification');
    });

    test_case('quality Polish PoliMorf external pack workflow rejects cache inside repository root', function (): void {
        assert_same([], wp_fts_ppew_case_rejects_cache_inside_repository_root(), 'external pack workflow should reject repo-root cache paths');
    });

    test_case('quality Polish PoliMorf external pack workflow rejects cache inside repository docs directory', function (): void {
        assert_same([], wp_fts_ppew_case_rejects_cache_inside_repository_docs_directory(), 'external pack workflow should reject docs cache paths before download setup');
    });

    test_case('quality Polish PoliMorf external pack workflow allows safe external cache boundary', function (): void {
        assert_same([], wp_fts_ppew_case_allows_safe_external_cache_path_to_reach_source_verification(), 'external pack workflow should allow safe external cache paths past boundary checks');
    });

    test_case('quality Polish PoliMorf external pack workflow builds local fixture and stays offline', function (): void {
        assert_same([], wp_fts_ppew_case_builds_local_fixture_and_stays_offline(), 'external pack local fixture workflow should pass');
    });

    test_case('quality Polish PoliMorf external pack workflow rejects source identity mismatch', function (): void {
        assert_same([], wp_fts_ppew_case_rejects_source_identity_mismatch(), 'external pack workflow should reject source hash and byte mismatches');
    });

    test_case('quality Polish PoliMorf external pack workflow requires download acknowledgement', function (): void {
        assert_same([], wp_fts_ppew_case_requires_download_license_acknowledgement(), 'external pack download mode should require license acknowledgement');
    });

    test_case('quality Polish PoliMorf external pack workflow refuses non-empty output', function (): void {
        assert_same([], wp_fts_ppew_case_refuses_non_empty_output(), 'external pack workflow should refuse non-empty output by default');
    });
} elseif (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $errors = wp_fts_ppew_run_verifier();
    if ($errors !== []) {
        fwrite(STDERR, "Polish PoliMorf external pack workflow verifier failed:\n- " . implode("\n- ", $errors) . "\n");
        exit(1);
    }

    fwrite(STDOUT, "Polish PoliMorf external pack workflow verifier passed.\n");
}
