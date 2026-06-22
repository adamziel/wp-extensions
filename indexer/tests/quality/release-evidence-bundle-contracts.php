<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools/collect-release-evidence.php';

if (!function_exists('test_case')) {
    $GLOBALS['wp_fts_release_evidence_contract_direct_failures'] = 0;

    function test_case(string $name, callable $fn): void
    {
        try {
            $fn();
            fwrite(STDOUT, "[PASS] {$name}\n");
        } catch (Throwable $e) {
            $GLOBALS['wp_fts_release_evidence_contract_direct_failures']++;
            fwrite(STDERR, "[FAIL] {$name}\n{$e->getMessage()}\n");
        }
    }

    register_shutdown_function(static function (): void {
        $failures = (int) ($GLOBALS['wp_fts_release_evidence_contract_direct_failures'] ?? 0);
        if ($failures > 0) {
            fwrite(STDERR, "Release evidence bundle contract failures={$failures}\n");
            exit(1);
        }

        fwrite(STDOUT, "OK: release evidence bundle contracts passed.\n");
    });
}

function wp_fts_release_evidence_contract_true(bool $condition, string $message): void
{
    if (function_exists('assert_true')) {
        assert_true($condition, $message);
        return;
    }

    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wp_fts_release_evidence_contract_same(mixed $expected, mixed $actual, string $message): void
{
    if (function_exists('assert_same')) {
        assert_same($expected, $actual, $message);
        return;
    }

    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function wp_fts_release_evidence_contract_contains(string $needle, string $haystack, string $message): void
{
    if (function_exists('assert_contains')) {
        assert_contains($needle, $haystack, $message);
        return;
    }

    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . "\nMissing: " . var_export($needle, true));
    }
}

function wp_fts_release_evidence_contract_pending(string $message): void
{
    if (function_exists('mark_pending')) {
        mark_pending($message);
    }

    throw new RuntimeException($message);
}

/**
 * @param string[] $command
 * @param array<string,string> $env
 * @return array{exit:int,stdout:string,stderr:string}
 */
function wp_fts_release_evidence_contract_run_command(array $command, array $env = []): array
{
    if (!function_exists('proc_open')) {
        wp_fts_release_evidence_contract_pending('proc_open() is unavailable, so the release evidence collector CLI contract cannot launch subprocesses.');
    }

    $baseEnv = getenv();
    if (!is_array($baseEnv)) {
        $baseEnv = [];
    }

    $root = dirname(__DIR__, 2);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $root, array_merge($baseEnv, $env));
    if (!is_resource($process)) {
        wp_fts_release_evidence_contract_pending('Could not start the release evidence collector subprocess.');
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
 * @return array<string,string>
 */
function wp_fts_release_evidence_contract_clean_env(): array
{
    return [
        'WP_FTS_WP_PATH' => '',
        'WP_FTS_WP_URL' => '',
        'WP_FTS_WP_CLI' => 'wp-fts-contract-missing-wp-cli',
        'WP_FTS_RELEASE_ZIP' => '',
        'WP_FTS_DISPOSABLE_SMOKE_ALLOW' => '',
        'WP_FTS_DISPOSABLE_SMOKE_CONFIRM_PATH' => '',
        'WP_FTS_LIFECYCLE_SMOKE_ALLOW' => '',
        'WP_FTS_LIFECYCLE_SMOKE_CONFIRM_PATH' => '',
        'WP_FTS_PROVIDER_COMPATIBILITY_ALLOW' => '',
        'WP_FTS_PROVIDER_COMPATIBILITY_CONFIRM_PATH' => '',
        'WP_FTS_PROVIDER_COMPATIBILITY_INSIDE' => '',
        'WP_FTS_EVIDENCE_RUN_REAL_WORDPRESS_MYSQL' => '',
        'WP_FTS_REAL_INTEGRATION_INSIDE' => '',
        'WP_FTS_MYSQL_PROOF_ALLOW_DISPOSABLE' => '',
        'WP_FTS_REAL_MYSQL_PROOF_INSIDE' => '',
    ];
}

/**
 * @return array<string,mixed>
 */
function wp_fts_release_evidence_contract_default_report(): array
{
    static $report = null;
    if (is_array($report)) {
        return $report;
    }

    $result = wp_fts_release_evidence_contract_run_command(
        [PHP_BINARY, 'tools/collect-release-evidence.php', '--timeout=120'],
        wp_fts_release_evidence_contract_clean_env()
    );

    wp_fts_release_evidence_contract_same(0, $result['exit'], 'default release evidence collector should exit zero');
    wp_fts_release_evidence_contract_same('', $result['stderr'], 'default release evidence collector should not emit stderr');

    $decoded = json_decode($result['stdout'], true);
    wp_fts_release_evidence_contract_true(is_array($decoded), 'default release evidence collector should emit JSON');
    $report = $decoded;

    return $report;
}

/**
 * @return array<string,mixed>
 */
function wp_fts_release_evidence_contract_lane(array $report, string $id): array
{
    foreach (($report['lanes'] ?? []) as $lane) {
        if (is_array($lane) && ($lane['id'] ?? null) === $id) {
            return $lane;
        }
    }

    throw new RuntimeException("Missing release evidence lane {$id}.");
}

/**
 * @return string[]
 */
function wp_fts_release_evidence_contract_lane_ids(array $report): array
{
    $ids = [];
    foreach (($report['lanes'] ?? []) as $lane) {
        if (is_array($lane) && is_string($lane['id'] ?? null)) {
            $ids[] = $lane['id'];
        }
    }
    sort($ids, SORT_STRING);

    return $ids;
}

function wp_fts_release_evidence_contract_lifecycle_output(string $status): string
{
    $json = json_encode([
        'schema' => 'wp-fts-disposable-lifecycle-wrapper-proof-v1',
        'inner_report_schema' => 'wp-fts-disposable-lifecycle-smoke-v1',
        'inner_report_status' => $status,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Could not encode fake lifecycle report.');
    }

    return "INFO: Running disposable lifecycle smoke against source-copy plugin\n"
        . $json . "\n"
        . ($status === 'passed'
            ? "PASS: Disposable WordPress lifecycle smoke completed.\nPASS: Running disposable lifecycle smoke against source-copy plugin\n"
            : "SKIP: Lifecycle preconditions were not met.\nPASS: Running disposable lifecycle smoke against source-copy plugin\n");
}

function wp_fts_release_evidence_contract_fake_runner(): callable
{
    return static function (array $command, string $cwd, int $timeout): array {
        unset($cwd, $timeout);

        if (($command[0] ?? '') === 'git') {
            if (($command[1] ?? '') === 'rev-parse' && ($command[2] ?? '') === 'HEAD') {
                return ['exit' => 0, 'stdout' => str_repeat('a', 40) . "\n", 'stderr' => ''];
            }
            if (($command[1] ?? '') === 'rev-parse' && ($command[2] ?? '') === '--abbrev-ref') {
                return ['exit' => 0, 'stdout' => "task/fake-release-evidence\n", 'stderr' => ''];
            }
            if (($command[1] ?? '') === 'status') {
                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            }
        }

        $script = (string) ($command[1] ?? '');
        if ($script === 'tools/check-release-readiness.php' && in_array('--target=direct-install', $command, true)) {
            return [
                'exit' => 0,
                'stdout' => json_encode([
                    'tool' => 'wp-fts-release-readiness',
                    'target' => 'direct-install',
                    'status' => 'ready',
                    'checks' => [
                        ['id' => 'direct_zip_boundary', 'status' => 'pass', 'message' => 'Direct ZIP boundary passed.'],
                    ],
                    'blockers' => [],
                ], JSON_UNESCAPED_SLASHES) . "\n",
                'stderr' => '',
            ];
        }
        if ($script === 'tools/check-release-readiness.php' && in_array('--target=public-submission', $command, true)) {
            return [
                'exit' => 1,
                'stdout' => json_encode([
                    'tool' => 'wp-fts-release-readiness',
                    'target' => 'public-submission',
                    'status' => 'blocked',
                    'checks' => [
                        ['id' => 'package_readme_txt', 'status' => 'fail', 'message' => 'Missing public readme.'],
                        ['id' => 'public_submission_authority_evidence', 'status' => 'fail', 'message' => 'Missing authority evidence.'],
                    ],
                    'blockers' => [
                        ['id' => 'package_readme_txt', 'message' => 'Missing public readme.'],
                        ['id' => 'public_submission_authority_evidence', 'message' => 'Missing authority evidence.'],
                    ],
                ], JSON_UNESCAPED_SLASHES) . "\n",
                'stderr' => '',
            ];
        }
        if ($script === 'tests/production-scale-benchmark.php') {
            return [
                'exit' => 0,
                'stdout' => json_encode([
                    'passed' => true,
                    'profile' => ['name' => 'fake', 'documents' => 12],
                    'metrics' => ['indexed_documents' => 12, 'query_checks_passed' => 4],
                    'failures' => [],
                ], JSON_UNESCAPED_SLASHES) . "\n",
                'stderr' => '',
            ];
        }
        if (($command[0] ?? '') === 'tools/run-disposable-release-provider-smoke.sh') {
            return [
                'exit' => 0,
                'stdout' => "PASS: Docker disposable release/provider smoke completed.\n",
                'stderr' => '',
            ];
        }
        if (($command[0] ?? '') === 'tools/run-disposable-lifecycle-smoke.sh') {
            return [
                'exit' => 0,
                'stdout' => wp_fts_release_evidence_contract_lifecycle_output('passed')
                    . "PASS: Docker disposable lifecycle smoke completed.\n",
                'stderr' => '',
            ];
        }

        return [
            'exit' => 0,
            'stdout' => 'SKIP: fake optional lane is not configured.',
            'stderr' => '',
        ];
    };
}

/**
 * @param array<string,mixed> $options
 * @return array<string,mixed>
 */
function wp_fts_release_evidence_contract_fake_report(array $options): array
{
    return wp_fts_release_evidence_contract_fake_report_with_runner(
        wp_fts_release_evidence_contract_fake_runner(),
        $options
    );
}

/**
 * @param array<string,mixed> $options
 * @return array<string,mixed>
 */
function wp_fts_release_evidence_contract_fake_report_with_runner(callable $runner, array $options): array
{
    $root = dirname(__DIR__, 2);
    $collector = new WP_FTS_ReleaseEvidenceCollector(
        $runner,
        wp_fts_release_evidence_contract_clean_env()
    );

    return $collector->collect(array_merge([
        'plugin_src' => $root,
        'monorepo_root' => dirname($root),
        'timeout' => 120,
    ], $options));
}

test_case('quality release evidence collector default report has stable schema and metadata', function (): void {
    $report = wp_fts_release_evidence_contract_default_report();

    wp_fts_release_evidence_contract_same(WP_FTS_ReleaseEvidenceCollector::SCHEMA, $report['schema'] ?? null, 'release evidence schema should be stable');
    wp_fts_release_evidence_contract_true(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ($report['generated_at'] ?? '')) === 1,
        'release evidence should include an ISO UTC generation timestamp'
    );
    wp_fts_release_evidence_contract_same('direct-install', $report['release_target'] ?? null, 'default release evidence target should be direct-install');
    wp_fts_release_evidence_contract_same('blocked', $report['overall_status'] ?? null, 'default direct-install evidence should be blocked until required direct-install readiness is explicitly run');
    wp_fts_release_evidence_contract_same('direct_install_readiness', $report['summary']['required_readiness_lane'] ?? null, 'default required readiness lane should match the direct-install release target');
    wp_fts_release_evidence_contract_same('indexer', $report['source']['plugin_path'] ?? null, 'source metadata should use a relative plugin path');
    wp_fts_release_evidence_contract_true(isset($report['collector']['output_policy']), 'collector metadata should describe bounded output policy');

    $expectedLanes = [
        'direct_install_readiness',
        'disposable_wordpress_release_smoke',
        'docker_disposable_lifecycle_smoke',
        'docker_disposable_release_provider_smoke',
        'production_scale_benchmark',
        'provider_compatibility_smoke',
        'public_submission_readiness',
        'real_mysql_production_proof',
        'real_wordpress_mysql_integration',
    ];
    wp_fts_release_evidence_contract_same($expectedLanes, wp_fts_release_evidence_contract_lane_ids($report), 'default report should include the expected evidence lanes');

    foreach (($report['lanes'] ?? []) as $lane) {
        wp_fts_release_evidence_contract_true(is_array($lane), 'each evidence lane should be an object');
        foreach (['id', 'label', 'status', 'command', 'summary'] as $key) {
            wp_fts_release_evidence_contract_true(array_key_exists($key, $lane), "evidence lane should include {$key}");
        }
        wp_fts_release_evidence_contract_true(
            in_array($lane['status'], ['pass', 'skip', 'blocked', 'fail'], true),
            'evidence lane status should use the stable status vocabulary'
        );
    }
});

test_case('quality release evidence collector reports expected default skips and benchmark pass', function (): void {
    $report = wp_fts_release_evidence_contract_default_report();

    wp_fts_release_evidence_contract_same('blocked', wp_fts_release_evidence_contract_lane($report, 'direct_install_readiness')['status'] ?? null, 'required direct-install readiness should block by default until artifact-producing readiness is explicitly opted in');
    wp_fts_release_evidence_contract_same('skip', wp_fts_release_evidence_contract_lane($report, 'disposable_wordpress_release_smoke')['status'] ?? null, 'disposable smoke should skip without WordPress config');
    wp_fts_release_evidence_contract_same('skip', wp_fts_release_evidence_contract_lane($report, 'docker_disposable_lifecycle_smoke')['status'] ?? null, 'Docker lifecycle smokes should require explicit collector opt-in');
    wp_fts_release_evidence_contract_same('skip', wp_fts_release_evidence_contract_lane($report, 'docker_disposable_release_provider_smoke')['status'] ?? null, 'Docker disposable smokes should require explicit collector opt-in');
    wp_fts_release_evidence_contract_same('skip', wp_fts_release_evidence_contract_lane($report, 'provider_compatibility_smoke')['status'] ?? null, 'provider smoke should skip without WordPress config');
    wp_fts_release_evidence_contract_same('skip', wp_fts_release_evidence_contract_lane($report, 'real_wordpress_mysql_integration')['status'] ?? null, 'real WordPress/MySQL integration should require collector opt-in');
    wp_fts_release_evidence_contract_same('skip', wp_fts_release_evidence_contract_lane($report, 'real_mysql_production_proof')['status'] ?? null, 'real MySQL proof should skip without WordPress config');
    wp_fts_release_evidence_contract_same('pass', wp_fts_release_evidence_contract_lane($report, 'production_scale_benchmark')['status'] ?? null, 'PR-safe production benchmark should run by default');

    $counts = $report['summary']['status_counts'] ?? [];
    wp_fts_release_evidence_contract_same(2, $counts['blocked'] ?? null, 'default direct-install evidence should have required direct-install and non-target public-submission blocked lanes');
    wp_fts_release_evidence_contract_true(($counts['skip'] ?? 0) >= 5, 'default evidence should record optional lanes as skips');
});

test_case('quality release evidence collector runs Docker disposable smokes only with explicit opt-in', function (): void {
    $defaultLane = wp_fts_release_evidence_contract_lane(
        wp_fts_release_evidence_contract_fake_report([]),
        'docker_disposable_release_provider_smoke'
    );
    $defaultDetails = is_array($defaultLane['details'] ?? null) ? $defaultLane['details'] : [];
    wp_fts_release_evidence_contract_same('skip', $defaultLane['status'] ?? null, 'Docker disposable smoke lane should skip without explicit opt-in');
    wp_fts_release_evidence_contract_same('--run-docker-disposable-smokes', $defaultDetails['enable_with'] ?? null, 'Docker lane should document its collector opt-in flag');

    $optInLane = wp_fts_release_evidence_contract_lane(
        wp_fts_release_evidence_contract_fake_report(['run_docker_disposable_smokes' => true]),
        'docker_disposable_release_provider_smoke'
    );
    $optInDetails = is_array($optInLane['details'] ?? null) ? $optInLane['details'] : [];
    wp_fts_release_evidence_contract_same('pass', $optInLane['status'] ?? null, 'Docker disposable smoke lane should run through the shell wrapper when opted in');
    wp_fts_release_evidence_contract_same('tools/run-disposable-release-provider-smoke.sh', $optInLane['command'] ?? null, 'Docker lane should report the shell wrapper command');
    wp_fts_release_evidence_contract_contains('direct-install and provider smoke evidence only', (string) ($optInDetails['target_policy'] ?? ''), 'Docker lane should keep target policy explicit');
});

test_case('quality release evidence collector runs Docker lifecycle smokes only with explicit opt-in', function (): void {
    $defaultLane = wp_fts_release_evidence_contract_lane(
        wp_fts_release_evidence_contract_fake_report([]),
        'docker_disposable_lifecycle_smoke'
    );
    $defaultDetails = is_array($defaultLane['details'] ?? null) ? $defaultLane['details'] : [];
    wp_fts_release_evidence_contract_same('skip', $defaultLane['status'] ?? null, 'Docker lifecycle smoke lane should skip without explicit opt-in');
    wp_fts_release_evidence_contract_same('--run-docker-lifecycle-smokes', $defaultDetails['enable_with'] ?? null, 'Docker lifecycle lane should document its collector opt-in flag');
    wp_fts_release_evidence_contract_contains('not public-submission readiness', (string) ($defaultDetails['target_policy'] ?? ''), 'Docker lifecycle lane should label direct-install/operator evidence');
    wp_fts_release_evidence_contract_contains('multisite lifecycle proof is explicitly not run', (string) ($defaultDetails['multisite_policy'] ?? ''), 'Docker lifecycle lane should record the multisite boundary');

    $optInLane = wp_fts_release_evidence_contract_lane(
        wp_fts_release_evidence_contract_fake_report(['run_docker_lifecycle_smokes' => true]),
        'docker_disposable_lifecycle_smoke'
    );
    $optInDetails = is_array($optInLane['details'] ?? null) ? $optInLane['details'] : [];
    wp_fts_release_evidence_contract_same('pass', $optInLane['status'] ?? null, 'Docker lifecycle smoke lane should run through the shell wrapper when opted in');
    wp_fts_release_evidence_contract_same('tools/run-disposable-lifecycle-smoke.sh', $optInLane['command'] ?? null, 'Docker lifecycle lane should report the shell wrapper command');
    wp_fts_release_evidence_contract_same('passed', $optInDetails['lifecycle_report_status'] ?? null, 'Docker lifecycle lane should require a passed inner lifecycle report');
    wp_fts_release_evidence_contract_contains('not public-submission readiness', (string) ($optInDetails['target_policy'] ?? ''), 'Docker lifecycle lane should keep target policy explicit');
    wp_fts_release_evidence_contract_contains('multisite lifecycle proof is explicitly not run', (string) ($optInDetails['multisite_policy'] ?? ''), 'Docker lifecycle lane should keep multisite boundary explicit');
});

test_case('quality release evidence collector does not pass Docker lifecycle lane when inner report is skipped', function (): void {
    $base = wp_fts_release_evidence_contract_fake_runner();
    $runner = static function (array $command, string $cwd, int $timeout) use ($base): array {
        if (($command[0] ?? '') === 'tools/run-disposable-lifecycle-smoke.sh') {
            return [
                'exit' => 0,
                'stdout' => wp_fts_release_evidence_contract_lifecycle_output('skipped'),
                'stderr' => '',
            ];
        }

        return $base($command, $cwd, $timeout);
    };

    $lane = wp_fts_release_evidence_contract_lane(
        wp_fts_release_evidence_contract_fake_report_with_runner($runner, ['run_docker_lifecycle_smokes' => true]),
        'docker_disposable_lifecycle_smoke'
    );
    $details = is_array($lane['details'] ?? null) ? $lane['details'] : [];

    wp_fts_release_evidence_contract_same('skip', $lane['status'] ?? null, 'inner lifecycle skipped report must not become Docker lifecycle pass');
    wp_fts_release_evidence_contract_same('skipped', $details['lifecycle_report_status'] ?? null, 'collector should record the skipped inner lifecycle report status');
    wp_fts_release_evidence_contract_contains('not treated as lifecycle proof', (string) ($lane['summary'] ?? ''), 'collector should explain skipped inner lifecycle evidence is not proof');
});

test_case('quality release evidence collector does not pass Docker lifecycle lane from wrapper PASS text alone', function (): void {
    $base = wp_fts_release_evidence_contract_fake_runner();
    $runner = static function (array $command, string $cwd, int $timeout) use ($base): array {
        if (($command[0] ?? '') === 'tools/run-disposable-lifecycle-smoke.sh') {
            return [
                'exit' => 0,
                'stdout' => "INFO: Running disposable lifecycle smoke against source-copy plugin\nPASS: Running disposable lifecycle smoke against source-copy plugin\nPASS: Docker disposable lifecycle smoke completed.\n",
                'stderr' => '',
            ];
        }

        return $base($command, $cwd, $timeout);
    };

    $lane = wp_fts_release_evidence_contract_lane(
        wp_fts_release_evidence_contract_fake_report_with_runner($runner, ['run_docker_lifecycle_smokes' => true]),
        'docker_disposable_lifecycle_smoke'
    );
    $details = is_array($lane['details'] ?? null) ? $lane['details'] : [];

    wp_fts_release_evidence_contract_same('fail', $lane['status'] ?? null, 'wrapper PASS text without an inner passed report must not become Docker lifecycle pass');
    wp_fts_release_evidence_contract_same(null, $details['lifecycle_report_status'] ?? null, 'collector should expose missing inner lifecycle report status');
    wp_fts_release_evidence_contract_contains('did not emit a parseable inner lifecycle report', (string) ($lane['summary'] ?? ''), 'collector should explain missing inner lifecycle report proof');
});

test_case('quality release evidence collector captures public-submission blockers as blocked evidence', function (): void {
    $lane = wp_fts_release_evidence_contract_lane(wp_fts_release_evidence_contract_default_report(), 'public_submission_readiness');
    $details = is_array($lane['details'] ?? null) ? $lane['details'] : [];
    $blockers = is_array($details['blocker_ids'] ?? null) ? $details['blocker_ids'] : [];

    wp_fts_release_evidence_contract_same('blocked', $lane['status'] ?? null, 'public-submission readiness should be blocked on current main');
    wp_fts_release_evidence_contract_same('non_target', $details['target_role'] ?? null, 'default direct-install evidence should label public-submission as non-target evidence');
    foreach (['composer_public_license', 'package_license_file', 'package_public_assets', 'package_readme_txt', 'public_submission_authority_evidence'] as $id) {
        wp_fts_release_evidence_contract_true(in_array($id, $blockers, true), "public-submission evidence should include blocker {$id}");
    }
    wp_fts_release_evidence_contract_true(($details['readiness_status'] ?? null) !== 'ready', 'public-submission blockers must not be reported as pass');
});

test_case('quality release evidence collector does not silently create direct-install release assets by default', function (): void {
    $root = dirname(__DIR__, 2);
    $lane = wp_fts_release_evidence_contract_lane(wp_fts_release_evidence_contract_default_report(), 'direct_install_readiness');
    $details = is_array($lane['details'] ?? null) ? $lane['details'] : [];

    wp_fts_release_evidence_contract_same('blocked', $lane['status'] ?? null, 'default direct-install lane should not run artifact-producing readiness');
    wp_fts_release_evidence_contract_same('not_run_by_default', $details['artifact_policy'] ?? null, 'default direct-install lane should declare artifact policy');
    wp_fts_release_evidence_contract_true(!file_exists($root . '/wp-fts-indexer.zip'), 'collector should not create a release ZIP in the plugin root');
    wp_fts_release_evidence_contract_true(!is_dir($root . '/build'), 'collector should not create a build directory in the plugin root');
});

test_case('quality release evidence collector direct-install target can pass with explicit readiness evidence', function (): void {
    $report = wp_fts_release_evidence_contract_fake_report([
        'release_target' => 'direct-install',
        'run_direct_install_readiness' => true,
    ]);

    wp_fts_release_evidence_contract_same('direct-install', $report['release_target'] ?? null, 'direct-install report should record the selected target');
    wp_fts_release_evidence_contract_same('pass', $report['overall_status'] ?? null, 'direct-install target should pass when required direct-install and pure-PHP lanes pass');
    wp_fts_release_evidence_contract_same('direct_install_readiness', $report['summary']['required_readiness_lane'] ?? null, 'direct-install target should require direct-install readiness');
    wp_fts_release_evidence_contract_same('pass', wp_fts_release_evidence_contract_lane($report, 'direct_install_readiness')['status'] ?? null, 'direct-install readiness should pass with explicit opt-in evidence');

    $publicLane = wp_fts_release_evidence_contract_lane($report, 'public_submission_readiness');
    $publicDetails = is_array($publicLane['details'] ?? null) ? $publicLane['details'] : [];
    wp_fts_release_evidence_contract_same('blocked', $publicLane['status'] ?? null, 'public-submission blockers should remain visible in direct-install evidence');
    wp_fts_release_evidence_contract_same('non_target', $publicDetails['target_role'] ?? null, 'public-submission readiness should be non-target evidence for direct-install');
    wp_fts_release_evidence_contract_contains('Non-target public-submission readiness remains blocked', (string) ($publicLane['summary'] ?? ''), 'direct-install evidence should not imply public-submission approval');
});

test_case('quality release evidence collector public-submission target remains blocked by public evidence', function (): void {
    $report = wp_fts_release_evidence_contract_fake_report([
        'release_target' => 'public-submission',
    ]);
    $publicLane = wp_fts_release_evidence_contract_lane($report, 'public_submission_readiness');
    $publicDetails = is_array($publicLane['details'] ?? null) ? $publicLane['details'] : [];
    $directDetails = is_array(wp_fts_release_evidence_contract_lane($report, 'direct_install_readiness')['details'] ?? null)
        ? wp_fts_release_evidence_contract_lane($report, 'direct_install_readiness')['details']
        : [];

    wp_fts_release_evidence_contract_same('public-submission', $report['release_target'] ?? null, 'public-submission report should record the selected target');
    wp_fts_release_evidence_contract_same('blocked', $report['overall_status'] ?? null, 'public-submission target should remain blocked on missing public evidence');
    wp_fts_release_evidence_contract_same('public_submission_readiness', $report['summary']['required_readiness_lane'] ?? null, 'public-submission target should require public-submission readiness');
    wp_fts_release_evidence_contract_same('blocked', $publicLane['status'] ?? null, 'public-submission readiness should be blocked');
    wp_fts_release_evidence_contract_same('required', $publicDetails['target_role'] ?? null, 'public-submission readiness should be required for public-submission target');
    wp_fts_release_evidence_contract_same('non_target', $directDetails['target_role'] ?? null, 'direct-install readiness should be non-target evidence for public-submission target');
});

test_case('quality release evidence collector rejects invalid release target values', function (): void {
    $result = wp_fts_release_evidence_contract_run_command(
        [PHP_BINARY, 'tools/collect-release-evidence.php', '--release-target=wporg', '--timeout=120'],
        wp_fts_release_evidence_contract_clean_env()
    );

    wp_fts_release_evidence_contract_same(2, $result['exit'], 'invalid release target should fail with usage error exit code');
    wp_fts_release_evidence_contract_same('', $result['stdout'], 'invalid release target should not emit a report');
    wp_fts_release_evidence_contract_contains('Unknown release target: wporg', $result['stderr'], 'invalid release target should report a clear error');
});

test_case('quality release evidence collector records Git SHA when available', function (): void {
    $git = wp_fts_release_evidence_contract_run_command(['git', 'rev-parse', 'HEAD']);
    $report = wp_fts_release_evidence_contract_default_report();

    if ($git['exit'] === 0) {
        $expectedSha = trim($git['stdout']);
        wp_fts_release_evidence_contract_true(
            preg_match('/^[0-9a-f]{40}$/', (string) ($report['source']['sha'] ?? '')) === 1,
            'collector should include a 40-character source SHA when Git is available'
        );
        wp_fts_release_evidence_contract_same($expectedSha, $report['source']['sha'] ?? null, 'collector source SHA should match Git HEAD');
        wp_fts_release_evidence_contract_same(true, $report['source']['git_available'] ?? null, 'collector should mark Git metadata as available');
    } else {
        wp_fts_release_evidence_contract_same('unknown', $report['source']['sha'] ?? null, 'collector should use unknown SHA when Git is unavailable');
    }
});

test_case('quality release evidence collector redacts path-like, secret-like, SQL, and long payloads', function (): void {
    $privateKeyMarker = 'PRIVATE ' . 'KEY';
    $payload = implode("\n", [
        'TOKEN=super-secret-token',
        'Authorization: Bearer secret-bearer-value',
        '/home/claude/project/local-file.txt',
        'SELECT post_password, post_content FROM wp_posts WHERE post_password = "secret";',
        "-----BEGIN {$privateKeyMarker}-----abc-----END {$privateKeyMarker}-----",
        str_repeat('x', 5000),
    ]);
    $sanitized = WP_FTS_ReleaseEvidenceCollector::sanitize_text($payload, 350);

    foreach (['super-secret-token', 'secret-bearer-value', '/home/claude', 'post_password', 'post_content', 'abc-----END', str_repeat('x', 400)] as $needle) {
        wp_fts_release_evidence_contract_true(!str_contains($sanitized, $needle), "sanitized text should not contain {$needle}");
    }
    wp_fts_release_evidence_contract_contains('[redacted]', $sanitized, 'sanitized text should redact secret-like values');
    wp_fts_release_evidence_contract_contains('[path]', $sanitized, 'sanitized text should redact absolute paths');
    wp_fts_release_evidence_contract_contains('[redacted-sql]', $sanitized, 'sanitized text should redact SQL payloads');
    wp_fts_release_evidence_contract_contains('[truncated]', $sanitized, 'sanitized text should indicate truncation');

    $safe = WP_FTS_ReleaseEvidenceCollector::sanitize_value([
        'api_key' => 'secret-api-key',
        'nested' => ['password' => 'secret-password'],
    ]);
    wp_fts_release_evidence_contract_same('[redacted]', $safe['api_key'] ?? null, 'sanitize_value should redact sensitive top-level keys');
    wp_fts_release_evidence_contract_same('[redacted]', $safe['nested']['password'] ?? null, 'sanitize_value should redact sensitive nested keys');
});

test_case('quality release evidence collector output omits raw local paths and raw subprocess dumps', function (): void {
    $json = json_encode(wp_fts_release_evidence_contract_default_report(), JSON_UNESCAPED_SLASHES);
    wp_fts_release_evidence_contract_true(is_string($json), 'release evidence report should encode for output inspection');
    $json = (string) $json;

    foreach (['/home/claude', '/tmp/', 'PRIVATE KEY', 'Authorization: Bearer'] as $needle) {
        wp_fts_release_evidence_contract_true(!str_contains($json, $needle), "release evidence JSON should not contain {$needle}");
    }
    wp_fts_release_evidence_contract_true(strlen($json) < 50000, 'release evidence JSON should stay bounded and avoid raw subprocess dumps');
});

test_case('quality release evidence collector works under php without loaded extensions', function (): void {
    $result = wp_fts_release_evidence_contract_run_command(
        [PHP_BINARY, '-n', 'tools/collect-release-evidence.php', '--timeout=120'],
        wp_fts_release_evidence_contract_clean_env()
    );

    wp_fts_release_evidence_contract_same(0, $result['exit'], 'php -n release evidence collector should exit zero');
    wp_fts_release_evidence_contract_same('', $result['stderr'], 'php -n release evidence collector should not emit stderr');
    $decoded = json_decode($result['stdout'], true);
    wp_fts_release_evidence_contract_true(is_array($decoded), 'php -n release evidence collector should emit JSON');
    wp_fts_release_evidence_contract_same(WP_FTS_ReleaseEvidenceCollector::SCHEMA, $decoded['schema'] ?? null, 'php -n release evidence collector should emit the same schema');
    wp_fts_release_evidence_contract_same('blocked', wp_fts_release_evidence_contract_lane($decoded, 'direct_install_readiness')['status'] ?? null, 'php -n direct-install lane should keep read-only opt-in behavior');
});
