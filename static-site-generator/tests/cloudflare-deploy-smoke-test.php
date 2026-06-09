<?php
/**
 * Contract tests for the Cloudflare deploy package smoke harness.
 *
 * @package PlaygroundStaticSiteGenerator
 */

require_once __DIR__ . '/../tools/smoke-cloudflare-deploy-package.php';

$fixture_root = sys_get_temp_dir() . '/ssgwp-cloudflare-deploy-smoke-' . getmypid() . '-' . mt_rand();

if ( ! mkdir( $fixture_root, 0777, true ) ) {
	ssgwp_fail( 'Could not create fixture root.' );
}

$runner = new SSGWP_Cloudflare_Deploy_Package_Smoke_Runner();

try {
	$export_root = $fixture_root . '/export';
	$deploy_root = $export_root . '/_cloudflare-publish';
	ssgwp_create_cloudflare_deploy_fixture( $deploy_root );

	$resolved_from_export = $runner->resolve_deploy_package( $export_root );
	$resolved_from_deploy = $runner->resolve_deploy_package( $deploy_root );

	ssgwp_assert_same( $deploy_root, $resolved_from_export['path'], 'The runner resolves a package from an export root.' );
	ssgwp_assert_same( $deploy_root, $resolved_from_deploy['path'], 'The runner accepts the deploy package root directly.' );

	$offline_command = $runner->offline_validation_command();
	ssgwp_assert_same(
		array( 'node', 'cloudflare-deploy-check.mjs', '--offline' ),
		$offline_command,
		'Offline validation runs the generated deploy check script.'
	);
	ssgwp_assert_same(
		array( 'node', 'cloudflare-deploy-check.mjs', '--require-credentials' ),
		$runner->credentials_validation_command(),
		'Credential validation runs the generated credential presence check.'
	);
	ssgwp_assert_same(
		array( 'npx', 'wrangler', 'deploy', '--config', 'wrangler.jsonc', '--dry-run' ),
		$runner->wrangler_command_for_mode( SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::MODE_DRY_RUN ),
		'Dry-run mode builds the expected Wrangler deploy command.'
	);
	ssgwp_assert_same(
		array( 'npx', 'wrangler', 'deploy', '--config', 'wrangler.jsonc' ),
		$runner->wrangler_command_for_mode( SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::MODE_DEPLOY ),
		'Deploy mode builds the expected real Wrangler deploy command.'
	);

	$fake_bin = $fixture_root . '/fake-bin';
	ssgwp_create_fake_cloudflare_runtime( $fake_bin );
	$fake_node_source = file_get_contents( $fake_bin . '/node' );

	if ( ! is_string( $fake_node_source ) ) {
		ssgwp_fail( 'Could not read fake node shim.' );
	}

	ssgwp_assert_not_contains( '/usr/bin/env node', $fake_node_source, 'The fake node shim must not recurse through PATH.' );

	$original_path       = getenv( 'PATH' );
	$original_account_id = getenv( SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::REQUIRED_ACCOUNT_ID );
	$original_api_token  = getenv( SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::REQUIRED_API_TOKEN );
	$had_account_id      = false !== $original_account_id;
	$had_api_token       = false !== $original_api_token;

	try {
		putenv( 'PATH=' . $fake_bin . PATH_SEPARATOR . ( false === $original_path ? '' : $original_path ) );
		putenv( SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::REQUIRED_ACCOUNT_ID );
		putenv( SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::REQUIRED_API_TOKEN );

		$offline_result = $runner->run(
			array(
				'input_path'              => $export_root,
				'mode'                    => SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::MODE_OFFLINE,
				'command_timeout_seconds' => 5,
			)
		);

		ssgwp_assert_same( 'passed', $offline_result['status'], 'Offline mode passes with fake node and no Cloudflare credentials.' );
		ssgwp_assert_same( 1, count( $offline_result['commands'] ), 'Offline mode only runs one command.' );
		ssgwp_assert_same( 'node cloudflare-deploy-check.mjs --offline', $offline_result['commands'][0]['display'], 'Offline command summaries are deterministic.' );
		ssgwp_assert_contains( 'Cloudflare deploy package check passed (offline).', $offline_result['commands'][0]['stdout'], 'Offline mode executes the generated deploy check.' );

		$skip_result = $runner->run(
			array(
				'input_path'                  => $deploy_root,
				'mode'                        => SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::MODE_DRY_RUN,
				'skip_if_missing_credentials' => true,
				'command_timeout_seconds'     => 5,
			)
		);

		ssgwp_assert_same( 'skipped', $skip_result['status'], 'Credentialed modes skip cleanly when credentials are missing and skip is requested.' );
		ssgwp_assert_same(
			array(
				SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::REQUIRED_ACCOUNT_ID,
				SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::REQUIRED_API_TOKEN,
			),
			$skip_result['missing_credentials'],
			'Credential skips name missing keys only.'
		);
		ssgwp_assert_same( 1, count( $skip_result['commands'] ), 'Credential skip still proves offline package validation first.' );

		ssgwp_assert_throws_message(
			static function () use ( $runner, $deploy_root ) {
				$runner->run(
					array(
						'input_path'              => $deploy_root,
						'mode'                    => SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::MODE_DEPLOY,
						'command_timeout_seconds' => 5,
					)
				);
			},
			'Refusing real Cloudflare deploy without --confirm-deploy.',
			'Real deploy mode refuses to run without explicit confirmation.'
		);

		$secret_account = 'acct-secret-value-for-redaction';
		$secret_token   = 'token-secret-value-for-redaction';
		putenv( SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::REQUIRED_ACCOUNT_ID . '=' . $secret_account );
		putenv( SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::REQUIRED_API_TOKEN . '=' . $secret_token );

		$credentials_result = $runner->run(
			array(
				'input_path'              => $deploy_root,
				'mode'                    => SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::MODE_CREDENTIALS,
				'command_timeout_seconds' => 5,
			)
		);

		$credential_summary = json_encode( $credentials_result, JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $credential_summary ) ) {
			ssgwp_fail( 'Could not encode credential smoke result.' );
		}

			ssgwp_assert_same( 'passed', $credentials_result['status'], 'Credential presence mode passes when required env vars are set.' );
			ssgwp_assert_same( 2, count( $credentials_result['commands'] ), 'Credential mode runs offline and credential validation.' );
			ssgwp_assert_not_contains( $secret_account, $credential_summary, 'Command summaries do not expose the account ID value.' );
			ssgwp_assert_not_contains( $secret_token, $credential_summary, 'Command summaries do not expose the API token value.' );
			ssgwp_assert_contains( '[redacted:CLOUDFLARE_ACCOUNT_ID]', $credential_summary, 'Account ID values are redacted from command output.' );
			ssgwp_assert_contains( '[redacted:CLOUDFLARE_API_TOKEN]', $credential_summary, 'API token values are redacted from command output.' );

			$overlap_account = 'abc';
			$overlap_token   = 'abcdef';
			putenv( SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::REQUIRED_ACCOUNT_ID . '=' . $overlap_account );
			putenv( SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::REQUIRED_API_TOKEN . '=' . $overlap_token );

			$overlap_result = $runner->run(
				array(
					'input_path'              => $deploy_root,
					'mode'                    => SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::MODE_CREDENTIALS,
					'command_timeout_seconds' => 5,
				)
			);
			$overlap_stdout = $overlap_result['commands'][1]['stdout'];

			ssgwp_assert_same( 'passed', $overlap_result['status'], 'Credential mode passes with overlapping fake credential values.' );
			ssgwp_assert_not_contains( $overlap_token, $overlap_stdout, 'Overlapping credential redaction does not expose the full API token.' );
			ssgwp_assert_not_contains( 'def', $overlap_stdout, 'Overlapping credential redaction does not leak the API token suffix.' );
			ssgwp_assert_contains(
				'Credential echo for redaction: [redacted:CLOUDFLARE_ACCOUNT_ID] [redacted:CLOUDFLARE_API_TOKEN]',
				$overlap_stdout,
				'Overlapping credential values are redacted with the correct key placeholders.'
			);

			$dry_run_result = $runner->run(
				array(
					'input_path'              => $deploy_root,
					'mode'                    => SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::MODE_DRY_RUN,
					'command_timeout_seconds' => 5,
				)
			);

		ssgwp_assert_same( 'passed', $dry_run_result['status'], 'Dry-run mode can run through fake Wrangler.' );
		ssgwp_assert_same( 3, count( $dry_run_result['commands'] ), 'Dry-run mode runs offline validation, credential validation, and Wrangler dry-run.' );
		ssgwp_assert_same( 'npx wrangler deploy --config wrangler.jsonc --dry-run', $dry_run_result['commands'][2]['display'], 'Dry-run command summary matches the expected Wrangler command.' );
		ssgwp_assert_contains( 'Fake Wrangler dry-run completed.', $dry_run_result['commands'][2]['stdout'], 'Dry-run mode invokes npx Wrangler through the command runner.' );

		$parsed = ssgwp_cloudflare_deploy_smoke_parse_args(
			array(
				'smoke-cloudflare-deploy-package.php',
				'--dry-run',
				'--skip-if-missing-credentials',
				$export_root,
			)
		);

		ssgwp_assert_same( SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::MODE_DRY_RUN, $parsed['mode'], 'Argument parsing accepts --dry-run mode.' );
		ssgwp_assert_same( true, $parsed['skip_if_missing_credentials'], 'Argument parsing accepts --skip-if-missing-credentials.' );
		ssgwp_assert_same( $export_root, $parsed['input_path'], 'Argument parsing accepts one input path.' );

		ssgwp_assert_throws_message(
			static function () use ( $export_root ) {
				ssgwp_cloudflare_deploy_smoke_parse_args(
					array(
						'smoke-cloudflare-deploy-package.php',
						'--credentials',
						'--dry-run',
						$export_root,
					)
				);
			},
			'Only one Cloudflare smoke mode may be provided.',
			'Argument parsing rejects multiple smoke modes.'
		);
	} finally {
		ssgwp_restore_env( 'PATH', $original_path, false !== $original_path );
		ssgwp_restore_env( SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::REQUIRED_ACCOUNT_ID, $original_account_id, $had_account_id );
		ssgwp_restore_env( SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::REQUIRED_API_TOKEN, $original_api_token, $had_api_token );
	}

	if ( '1' === getenv( 'SSGWP_RUN_CLOUDFLARE_DRY_RUN' ) ) {
		$live_result = $runner->run(
			array(
				'input_path'                  => $deploy_root,
				'mode'                        => SSGWP_Cloudflare_Deploy_Package_Smoke_Runner::MODE_DRY_RUN,
				'skip_if_missing_credentials' => true,
				'command_timeout_seconds'     => 120,
			)
		);

		if ( ! in_array( $live_result['status'], array( 'passed', 'skipped' ), true ) ) {
			ssgwp_fail( 'The optional Cloudflare Wrangler dry-run smoke returned an unexpected status: ' . $live_result['status'] );
		}
	}

	ssgwp_delete_directory( $fixture_root );
	echo "Cloudflare deploy smoke tests passed.\n";
} catch ( Exception $error ) {
	ssgwp_delete_directory( $fixture_root );
	throw $error;
}

/**
 * Create a fake generated Cloudflare deploy package.
 *
 * @param string $deploy_root Deploy package root.
 */
function ssgwp_create_cloudflare_deploy_fixture( $deploy_root ) {
	if ( ! mkdir( $deploy_root . '/site', 0777, true ) ) {
		ssgwp_fail( 'Could not create fake deploy package.' );
	}

	ssgwp_write_file( $deploy_root . '/site/index.html', "<!doctype html>\n<title>Cloudflare smoke fixture</title>\n" );
	ssgwp_write_file( $deploy_root . '/cloudflare-worker.js', "export default { async fetch(request, env) { return env.ASSETS.fetch(request); } };\n" );
	ssgwp_write_file( $deploy_root . '/CLOUDFLARE-WORKERS.md', "# Cloudflare Workers Static Deploy Workflow\n" );
	ssgwp_write_json(
		$deploy_root . '/package.json',
		array(
			'name' => 'stillpress-static-site-cloudflare-publish',
			'private' => true,
			'type' => 'module',
			'scripts' => array(
				'validate:offline' => 'node cloudflare-deploy-check.mjs --offline',
				'validate:credentials' => 'node cloudflare-deploy-check.mjs --require-credentials',
				'deploy:dry-run' => 'npx wrangler deploy --config wrangler.jsonc --dry-run',
				'deploy' => 'npx wrangler deploy --config wrangler.jsonc',
				'versions' => 'npx wrangler versions list --config wrangler.jsonc',
				'deployments' => 'npx wrangler deployments list --config wrangler.jsonc',
				'rollback' => 'npx wrangler rollback --config wrangler.jsonc',
			),
		)
	);
	ssgwp_write_json(
		$deploy_root . '/wrangler.jsonc',
		array(
			'name' => 'stillpress-static-site',
			'compatibility_date' => '2026-06-08',
			'main' => './cloudflare-worker.js',
			'assets' => array(
				'directory' => './site',
				'binding' => 'ASSETS',
				'html_handling' => 'auto-trailing-slash',
				'not_found_handling' => '404-page',
			),
			'workers_dev' => true,
		)
	);
	ssgwp_write_json(
		$deploy_root . '/cloudflare-publish.json',
		array(
			'schema' => 'https://stillpress.local/cloudflare-workers-publish/v1',
			'version' => 1,
			'network_calls' => false,
			'artifacts' => array(
				'asset_directory_from_wrangler_config' => './site',
			),
			'asset_inventory' => array(
				'file_count' => 1,
				'largest_file_size_bytes' => strlen( "<!doctype html>\n<title>Cloudflare smoke fixture</title>\n" ),
			),
			'deploy_workflow' => array(
				'export_generation_network_calls' => false,
			),
			'credentials' => array(
				'required_environment_variables' => array(
					'CLOUDFLARE_ACCOUNT_ID',
					'CLOUDFLARE_API_TOKEN',
				),
			),
			'free_tier_limits' => array(
				'static_asset_files_per_worker_version' => 20000,
				'individual_static_asset_file_size_bytes' => 26214400,
			),
		)
	);
	ssgwp_write_file( $deploy_root . '/cloudflare-deploy-check.mjs', ssgwp_fake_deploy_check_script() );
}

/**
 * Return a local Node script with the generated deploy-check contract.
 *
 * @return string JavaScript source.
 */
function ssgwp_fake_deploy_check_script() {
	return <<<'JS'
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";

const root = process.cwd();
const args = new Set(process.argv.slice(2));
const requireCredentials = args.has("--require-credentials");
const offline = args.has("--offline") || requireCredentials;

if (!offline) {
	console.error("Usage: node cloudflare-deploy-check.mjs --offline|--require-credentials");
	process.exit(2);
}

function fail(message) {
	console.error("Cloudflare deploy package check failed: " + message);
	process.exit(1);
}

function readJson(file) {
	try {
		return JSON.parse(readFileSync(join(root, file), "utf8"));
	} catch (error) {
		fail("Could not read valid JSON from " + file + ": " + error.message);
	}
}

for (const file of ["package.json", "cloudflare-deploy-check.mjs", "wrangler.jsonc", "cloudflare-worker.js", "cloudflare-publish.json", "CLOUDFLARE-WORKERS.md"]) {
	if (!existsSync(join(root, file)) || !statSync(join(root, file)).isFile()) {
		fail("Missing required file " + file);
	}
}

if (!existsSync(join(root, "site")) || !statSync(join(root, "site")).isDirectory()) {
	fail("Missing served asset directory site");
}

const manifest = readJson("cloudflare-publish.json");
const packageJson = readJson("package.json");
const wrangler = readJson("wrangler.jsonc");
const siteFiles = readdirSync(join(root, "site")).filter((name) => statSync(join(root, "site", name)).isFile());

if (manifest.network_calls !== false || manifest.deploy_workflow?.export_generation_network_calls !== false) {
	fail("Manifest must record that export generation makes no network calls");
}

if (wrangler.assets?.directory !== "./site") {
	fail("Wrangler assets.directory must be ./site");
}

if (packageJson.scripts?.["validate:offline"] !== "node cloudflare-deploy-check.mjs --offline") {
	fail("package.json script validate:offline does not match the expected command");
}

if (requireCredentials) {
	const required = manifest.credentials?.required_environment_variables ?? ["CLOUDFLARE_ACCOUNT_ID", "CLOUDFLARE_API_TOKEN"];
	const missing = required.filter((name) => !process.env[name]);

	if (missing.length > 0) {
		fail("Missing required environment variable(s): " + missing.join(", "));
	}

	console.log("Credential echo for redaction: " + process.env.CLOUDFLARE_ACCOUNT_ID + " " + process.env.CLOUDFLARE_API_TOKEN);
}

console.log("Cloudflare deploy package check passed (" + (requireCredentials ? "credentials" : "offline") + ").");
console.log("Files: " + siteFiles.length + "; largest file: " + statSync(join(root, "site", siteFiles[0])).size + " bytes.");
JS;
}

/**
 * Create local node/npx shims.
 *
 * @param string $directory Fake binary directory.
 */
function ssgwp_create_fake_cloudflare_runtime( $directory ) {
	if ( ! mkdir( $directory, 0777, true ) ) {
		ssgwp_fail( 'Could not create fake Cloudflare runtime directory.' );
	}

	ssgwp_write_executable(
		$directory . '/node',
		<<<SH
#!/bin/sh
if [ "\${1:-}" != "cloudflare-deploy-check.mjs" ]; then
	printf '%s\n' "Unexpected node command: \$*" >&2
	exit 11
fi

mode=\${2:-}
require_credentials=0

if [ "\$mode" = "--require-credentials" ]; then
	require_credentials=1
elif [ "\$mode" != "--offline" ]; then
	printf '%s\n' 'Usage: node cloudflare-deploy-check.mjs --offline|--require-credentials' >&2
	exit 2
fi

for file in package.json cloudflare-deploy-check.mjs wrangler.jsonc cloudflare-worker.js cloudflare-publish.json CLOUDFLARE-WORKERS.md; do
	if [ ! -f "\$file" ]; then
		printf '%s\n' "Cloudflare deploy package check failed: Missing required file \$file" >&2
		exit 1
	fi
done

if [ ! -d site ]; then
	printf '%s\n' 'Cloudflare deploy package check failed: Missing served asset directory site' >&2
	exit 1
fi

if [ "\$require_credentials" = "1" ]; then
	missing=''

	if [ -z "\${CLOUDFLARE_ACCOUNT_ID:-}" ]; then
		missing='CLOUDFLARE_ACCOUNT_ID'
	fi

	if [ -z "\${CLOUDFLARE_API_TOKEN:-}" ]; then
		if [ -n "\$missing" ]; then
			missing="\$missing, CLOUDFLARE_API_TOKEN"
		else
			missing='CLOUDFLARE_API_TOKEN'
		fi
	fi

	if [ -n "\$missing" ]; then
		printf '%s\n' "Cloudflare deploy package check failed: Missing required environment variable(s): \$missing" >&2
		exit 1
	fi

	printf '%s\n' "Credential echo for redaction: \$CLOUDFLARE_ACCOUNT_ID \$CLOUDFLARE_API_TOKEN"
	printf '%s\n' 'Cloudflare deploy package check passed (credentials).'
else
	printf '%s\n' 'Cloudflare deploy package check passed (offline).'
fi

printf '%s\n' 'Files: 1; largest file: 54 bytes.'
exit 0
SH
	);

	ssgwp_write_executable(
		$directory . '/npx',
		<<<SH
#!/bin/sh
if [ "\${1:-}" = "wrangler" ] && [ "\${2:-}" = "deploy" ] && [ "\${3:-}" = "--config" ] && [ "\${4:-}" = "wrangler.jsonc" ] && [ "\${5:-}" = "--dry-run" ]; then
	printf '%s\n' 'Fake Wrangler dry-run completed.'
	exit 0
fi

if [ "\${1:-}" = "wrangler" ] && [ "\${2:-}" = "deploy" ] && [ "\${3:-}" = "--config" ] && [ "\${4:-}" = "wrangler.jsonc" ]; then
	printf '%s\n' 'Fake Wrangler deploy completed.'
	exit 0
fi

printf '%s\n' "Unexpected npx command: \$*" >&2
exit 17
SH
	);
}

/**
 * Write deterministic JSON.
 *
 * @param string              $path Path.
 * @param array<string,mixed> $data Data.
 */
function ssgwp_write_json( $path, array $data ) {
	$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

	if ( ! is_string( $json ) ) {
		ssgwp_fail( 'Could not encode fixture JSON.' );
	}

	ssgwp_write_file( $path, $json . "\n" );
}

/**
 * Write a fixture file.
 *
 * @param string $path     File path.
 * @param string $contents File contents.
 */
function ssgwp_write_file( $path, $contents ) {
	$directory = dirname( $path );

	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) ) {
		ssgwp_fail( 'Could not create fixture directory: ' . $directory );
	}

	if ( false === file_put_contents( $path, $contents ) ) {
		ssgwp_fail( 'Could not write fixture file: ' . $path );
	}
}

/**
 * Write an executable test helper script.
 *
 * @param string $path   Script path.
 * @param string $source Script source.
 */
function ssgwp_write_executable( $path, $source ) {
	ssgwp_write_file( $path, $source );

	if ( ! chmod( $path, 0755 ) ) {
		ssgwp_fail( 'Could not make test helper executable: ' . $path );
	}
}

/**
 * Restore an environment variable.
 *
 * @param string      $name    Env var name.
 * @param string|bool $value   Original value.
 * @param bool        $existed Whether it originally existed.
 */
function ssgwp_restore_env( $name, $value, $existed ) {
	if ( $existed ) {
		putenv( $name . '=' . $value );
		return;
	}

	putenv( $name );
}

/**
 * Assert two values are identical.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Failure message.
 */
function ssgwp_assert_same( $expected, $actual, $message ) {
	if ( $expected === $actual ) {
		return;
	}

	ssgwp_fail( $message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . '.' );
}

/**
 * Assert a callback throws a message fragment.
 *
 * @param callable $callback Callback expected to throw.
 * @param string   $needle   Message fragment.
 * @param string   $message  Failure message.
 */
function ssgwp_assert_throws_message( $callback, $needle, $message ) {
	try {
		$callback();
	} catch ( Exception $error ) {
		ssgwp_assert_contains( $needle, $error->getMessage(), $message );
		return;
	}

	ssgwp_fail( $message . ' Expected exception containing ' . var_export( $needle, true ) . '.' );
}

/**
 * Assert a string contains a substring.
 *
 * @param string $needle   Expected substring.
 * @param string $haystack String to inspect.
 * @param string $message  Failure message.
 */
function ssgwp_assert_contains( $needle, $haystack, $message ) {
	if ( false !== strpos( (string) $haystack, (string) $needle ) ) {
		return;
	}

	ssgwp_fail( $message . ' Missing ' . var_export( $needle, true ) . '.' );
}

/**
 * Assert a string does not contain a substring.
 *
 * @param string $needle   Unexpected substring.
 * @param string $haystack String to inspect.
 * @param string $message  Failure message.
 */
function ssgwp_assert_not_contains( $needle, $haystack, $message ) {
	if ( false === strpos( (string) $haystack, (string) $needle ) ) {
		return;
	}

	ssgwp_fail( $message . ' Unexpected ' . var_export( $needle, true ) . '.' );
}

/**
 * Delete a directory recursively.
 *
 * @param string $directory Directory.
 */
function ssgwp_delete_directory( $directory ) {
	if ( ! is_dir( $directory ) ) {
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			rmdir( $item->getPathname() );
		} else {
			unlink( $item->getPathname() );
		}
	}

	rmdir( $directory );
}

/**
 * Exit with a test failure.
 *
 * @param string $message Failure message.
 */
function ssgwp_fail( $message ) {
	fwrite( STDERR, $message . PHP_EOL );
	exit( 1 );
}
