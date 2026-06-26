<?php
declare(strict_types=1);

function wp_fts_release_workflow_contract_contains(string $needle, string $haystack, string $message): void
{
    if (function_exists('assert_contains')) {
        assert_contains($needle, $haystack, $message);
        return;
    }

    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message);
    }
}

function wp_fts_release_workflow_contract_true(bool $condition, string $message): void
{
    if (function_exists('assert_true')) {
        assert_true($condition, $message);
        return;
    }

    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return list<string>
 */
function wp_fts_release_workflow_contract_job_env_lines(string $workflow, string $jobName): array
{
    $lines = explode("\n", $workflow);
    $inJobs = false;
    $inJob = false;
    $inEnv = false;
    $envLines = [];

    foreach ($lines as $line) {
        $line = rtrim($line, "\r");

        if (!$inJobs) {
            $inJobs = ($line === 'jobs:');
            continue;
        }

        if (!$inJob) {
            $inJob = ($line === "  {$jobName}:");
            continue;
        }

        if (!$inEnv) {
            if ($line === '    env:') {
                $inEnv = true;
                continue;
            }

            if ($line !== '' && str_starts_with($line, '  ') && !str_starts_with($line, '    ')) {
                break;
            }

            continue;
        }

        if ($line === '' || str_starts_with($line, '      ')) {
            $envLines[] = $line;
            continue;
        }

        break;
    }

    return $envLines;
}

function wp_fts_release_workflow_contract_run(): void
{
    $repoRoot = dirname(__DIR__, 3);
    $pluginRoot = dirname(__DIR__, 2);
    $workflowPath = $repoRoot . '/.github/workflows/release-language-fts.yml';
    $docsPath = $pluginRoot . '/docs/release-packaging.md';

    wp_fts_release_workflow_contract_true(is_file($workflowPath), 'Language FTS release workflow should exist');

    $workflow = (string) file_get_contents($workflowPath);
    $docs = (string) file_get_contents($docsPath);

    foreach ([
        'name: Release Language FTS',
        'workflow_dispatch:',
        'tag:',
        'target_ref:',
        'draft:',
        'prerelease:',
        'push:',
        "- 'language-fts-v*'",
        'permissions:',
        'contents: write',
        'actions/checkout@v4',
        'submodules: false',
        'shivammathur/setup-php@v2',
        "php-version: '8.3'",
        'extensions: zip, sodium',
        'composer config cache-files-dir',
        'php -l indexer/tools/release-channel-policy.php',
        'php -l indexer/tools/build-language-pack-release-manifest.php',
        'php indexer/tests/quality/release-packaging-contracts.php',
        'php indexer/tools/build-release-zip.php',
        '--profile=core',
        '--profile=github-full',
        'php indexer/tools/build-language-pack-bundle.php',
        'php indexer/tools/build-language-pack-release-manifest.php',
        '--profile=extended-language-packs',
        'LANGUAGE_FTS_LANGUAGE_PACK_MANIFEST_SIGNING_KEY: ${{ secrets.LANGUAGE_FTS_LANGUAGE_PACK_MANIFEST_SIGNING_KEY }}',
        'ASSET_BASE_URL="https://github.com/${GITHUB_REPOSITORY}/releases/download/${RELEASE_TAG}"',
        'language-fts-core.zip',
        'language-fts-full.zip',
        'language-fts-extended-language-packs.zip',
        'language-fts-extended-language-packs.manifest.json',
        'language-fts-extended-language-packs.manifest.json.sig',
        'language-fts-language-pack-release-manifest-v1',
        'language-fts-release-evidence.json',
        'SHA256SUMS.txt',
        'te-unimorph-tel-551f60f5f434',
        'en-unimorph-eng-66e0e9e8e2dc',
        'language-fts-extended-language-packs/analyzer-packs/pl-polimorf-20180722-full/',
        'MIXED-LICENSE-NOTICE.txt',
        'No WordPress.org submission',
        'gh release view',
        'gh release view "$RELEASE_TAG" --json databaseId',
        'repos/${GITHUB_REPOSITORY}/releases/${release_id}/assets',
        'repos/${GITHUB_REPOSITORY}/releases/assets/${asset_id}',
        'uploads.github.com/repos/${GITHUB_REPOSITORY}/releases/${release_id}/assets?name=${asset_name}',
        'DEPRECATED_ASSETS=',
        'language-fts-wporg-compatible.zip',
        'target_commitish="$RELEASE_TARGET_REF"',
        'release create "$RELEASE_TAG"',
        'gh api --method PATCH',
        'gh api --method DELETE',
        'gh api --method POST',
        'BUILD_ROOT="${RUNNER_TEMP:?}/language-fts-build"',
        '^language-fts-v[A-Za-z0-9._-]+$',
    ] as $needle) {
        wp_fts_release_workflow_contract_contains(
            $needle,
            $workflow,
            "Language FTS release workflow should contain {$needle}"
        );
    }

    foreach ([
        'git tag -f',
        'git push --force',
        'markdown-editor-latest',
        'language-fts-latest',
    ] as $forbidden) {
        wp_fts_release_workflow_contract_true(
            !str_contains($workflow, $forbidden),
            "Language FTS release workflow should not contain {$forbidden}"
        );
    }

    $releaseJobEnv = implode("\n", wp_fts_release_workflow_contract_job_env_lines($workflow, 'release'));
    wp_fts_release_workflow_contract_true(
        $releaseJobEnv !== '',
        'Language FTS release workflow should expose release job-level env for context guard'
    );
    wp_fts_release_workflow_contract_true(
        !str_contains($releaseJobEnv, '${{ runner.'),
        'Language FTS release workflow should not use runner context in release job-level env'
    );

    foreach ([
        '.github/workflows/release-language-fts.yml',
        'Release Language FTS',
        'language-fts-v0.1.10',
        'language-fts-v0.1.10-rc1',
        'language-fts-v0.1.9-test',
        'language-fts-core.zip',
        'language-fts-full.zip',
        'language-fts-extended-language-packs.zip',
        'language-fts-extended-language-packs.manifest.json',
        'language-fts-extended-language-packs.manifest.json.sig',
        'language-fts-release-evidence.json',
        'SHA256SUMS.txt',
        'LANGUAGE_FTS_LANGUAGE_PACK_MANIFEST_SIGNING_KEY',
        'Ed25519',
        'SHA-256 hash before extraction',
        'language-fts-v0.1.9',
        'https://github.com/adamziel/wp-extensions/releases/tag/untagged-ddb06656129684895c65',
        'does not move existing tags',
        'does not force-push tags',
        'does not maintain a moving `latest` tag',
        'This workflow does not submit to WordPress.org',
    ] as $needle) {
        wp_fts_release_workflow_contract_contains(
            $needle,
            $docs,
            "release packaging docs should document {$needle}"
        );
    }
}

if (function_exists('test_case')) {
    test_case('quality release workflow defines Language FTS assets and safeguards', function (): void {
        wp_fts_release_workflow_contract_run();
    });
} elseif (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    wp_fts_release_workflow_contract_run();
    fwrite(STDOUT, "OK: Language FTS release workflow contract is documented and guarded.\n");
}
