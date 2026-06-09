<?php
/**
 * Contract tests for the Playground source bundle smoke harness.
 *
 * @package PlaygroundStaticSiteGenerator
 */

require_once __DIR__ . '/../tools/smoke-playground-source-bundle.php';

$fixture_root = sys_get_temp_dir() . '/ssgwp-playground-source-bundle-smoke-' . getmypid() . '-' . mt_rand();

if ( ! mkdir( $fixture_root, 0777, true ) ) {
	ssgwp_fail( 'Could not create fixture root.' );
}

$runner = new SSGWP_Playground_Source_Bundle_Smoke_Runner( dirname( __DIR__, 2 ) );

try {
	$full_bundle = $fixture_root . '/full-site-playground-blueprint-bundle.zip';
	ssgwp_create_source_bundle_fixture( $full_bundle, 'sqlite-full-site-wordpress-files' );
	$full_bundle_hash = hash_file( 'sha256', $full_bundle );

	$export_dir = $fixture_root . '/export';
	if ( ! mkdir( $export_dir . '/_playground-source', 0777, true ) ) {
		ssgwp_fail( 'Could not create fake export directory.' );
	}
	if ( ! copy( $full_bundle, $export_dir . '/_playground-source/playground-blueprint-bundle.zip' ) ) {
		ssgwp_fail( 'Could not copy full-site bundle into fake export directory.' );
	}

	$dry_run = $runner->run(
		array(
			'input_path'   => $export_dir,
			'dry_run'     => true,
			'keep_bundle' => true,
		)
	);

	ssgwp_assert_same( 'dry-run', $dry_run['status'], 'The runner supports contract dry-run mode.' );
	ssgwp_assert_same( 'sqlite-full-site-wordpress-files', $dry_run['mode'], 'The runner detects full-site SQLite bundles.' );
	ssgwp_assert_same( true, is_dir( $dry_run['workdir'] ), 'Dry-run keeps the temporary bundle when --keep-bundle is set.' );

	$command = $dry_run['command'];
	ssgwp_assert_same( 'npx', $command[0], 'The Playground smoke command starts with npx.' );
	ssgwp_assert_same( '--yes', $command[1], 'The Playground smoke command uses npx --yes.' );
	ssgwp_assert_same( '@wp-playground/cli@latest', $command[2], 'The Playground smoke command uses the default Playground CLI package.' );
	ssgwp_assert_same( 'run-blueprint', $command[3], 'The Playground smoke command runs a Blueprint.' );
	ssgwp_assert_contains( '--mount=', $command[4], 'The Playground smoke command mounts the temporary result directory.' );
	ssgwp_assert_contains( ':' . SSGWP_Playground_Source_Bundle_Smoke_Runner::ASSERTION_MOUNT_PATH, $command[4], 'The Playground smoke command mounts the result directory at a deterministic runtime path.' );
	ssgwp_assert_contains( '--blueprint=', $command[5], 'The Playground smoke command passes a Blueprint path.' );
	ssgwp_assert_same( '--blueprint-may-read-adjacent-files', $command[6], 'The Playground smoke command allows adjacent bundled files.' );

	$full_smoke_blueprint  = ssgwp_read_bundle_json( $dry_run['bundle_path'], 'blueprint.json' );
	$full_smoke_steps      = $full_smoke_blueprint['steps'];
	$full_write_step       = $full_smoke_steps[ count( $full_smoke_steps ) - 2 ];
	$full_assertion_step   = $full_smoke_steps[ count( $full_smoke_steps ) - 1 ];
	$full_assertion_code   = file_get_contents( $dry_run['assertion_path'] );

	if ( ! is_string( $full_assertion_code ) ) {
		ssgwp_fail( 'Could not read the full-site smoke assertion file.' );
	}

	ssgwp_assert_same( 'writeFile', $full_write_step['step'], 'The smoke harness writes the bundled assertion file into Playground.' );
	ssgwp_assert_same( SSGWP_Playground_Source_Bundle_Smoke_Runner::ASSERTION_RUNTIME_PATH, $full_write_step['path'], 'The smoke harness writes the assertion file to a deterministic runtime path.' );
	ssgwp_assert_same( 'bundled', $full_write_step['data']['resource'], 'The smoke harness copies the assertion file from the bundle.' );
	ssgwp_assert_same( '/' . SSGWP_Playground_Source_Bundle_Smoke_Runner::ASSERTION_BUNDLE_PATH, $full_write_step['data']['path'], 'The smoke harness references the bundled assertion file.' );
	ssgwp_assert_same( 'wp-cli', $full_assertion_step['step'], 'The smoke harness injects a WP-CLI assertion step.' );
	ssgwp_assert_same( 'wp eval-file ' . SSGWP_Playground_Source_Bundle_Smoke_Runner::ASSERTION_RUNTIME_PATH, $full_assertion_step['command'], 'The smoke assertion runs through wp eval-file so marker output is visible.' );
	ssgwp_assert_contains( "require '/wordpress/wp-load.php';", $full_assertion_code, 'The smoke assertion can load WordPress before checking restored state.' );
	ssgwp_assert_contains( SSGWP_Playground_Source_Bundle_Smoke_Runner::SUCCESS_MARKER, $full_assertion_code, 'The full-site assertion prints the success marker.' );
	ssgwp_assert_contains( SSGWP_Playground_Source_Bundle_Smoke_Runner::FAILURE_MARKER, $full_assertion_code, 'The full-site assertion prints the failure marker.' );
	ssgwp_assert_contains( 'sqlite-full-site-wordpress-files', $full_assertion_code, 'The full-site assertion checks the restore mode.' );
	ssgwp_assert_contains( 'wp-content/database/.ht.sqlite', $full_assertion_code, 'The full-site assertion checks the SQLite database path.' );
	ssgwp_assert_contains( 'wp-content/plugins/forms/forms.php', $full_assertion_code, 'The full-site assertion checks a restored plugin fixture path.' );
	ssgwp_assert_contains( 'wp-content/themes/twentytwentysix/style.css', $full_assertion_code, 'The full-site assertion checks a restored theme fixture path.' );
	ssgwp_assert_contains( 'wp-content/uploads/2026/06/photo.jpg', $full_assertion_code, 'The full-site assertion checks a restored upload fixture path.' );
	ssgwp_assert_contains( 'static-site-generator/static-site-generator.php', $full_assertion_code, 'The smoke assertion checks that StillPress is active.' );
	ssgwp_assert_contains( 'wp-cli', json_encode( $full_smoke_blueprint['extraLibraries'], JSON_UNESCAPED_SLASHES ), 'The smoke Blueprint keeps WP-CLI available.' );
	ssgwp_assert_same( false, isset( $full_smoke_blueprint['stillpress'] ), 'The smoke fixture Blueprint avoids custom root metadata rejected by Playground schema validation.' );

	$original_full_blueprint = ssgwp_read_bundle_json( $full_bundle, 'blueprint.json' );
	ssgwp_assert_same( 3, count( $original_full_blueprint['steps'] ), 'The original full-site bundle is not mutated with smoke steps.' );
	ssgwp_assert_not_contains( SSGWP_Playground_Source_Bundle_Smoke_Runner::SUCCESS_MARKER, json_encode( $original_full_blueprint, JSON_UNESCAPED_SLASHES ), 'The original full-site bundle has no smoke marker.' );
	ssgwp_assert_same( $full_bundle_hash, hash_file( 'sha256', $full_bundle ), 'The original full-site bundle hash is unchanged.' );
	ssgwp_assert_same( $full_bundle_hash, hash_file( 'sha256', $export_dir . '/_playground-source/playground-blueprint-bundle.zip' ), 'The original export bundle copy is unchanged.' );

	ssgwp_delete_directory( $dry_run['workdir'] );

	$cleaned_dry_run = $runner->run(
		array(
			'input_path' => $full_bundle,
			'dry_run'   => true,
		)
	);

	ssgwp_assert_same( 'dry-run', $cleaned_dry_run['status'], 'Dry-run still reports success when cleanup is enabled.' );
	ssgwp_assert_same( false, is_dir( $cleaned_dry_run['workdir'] ), 'Dry-run removes the temporary bundle unless --keep-bundle is set.' );

	$wxr_bundle = $fixture_root . '/wxr-playground-blueprint-bundle.zip';
	ssgwp_create_source_bundle_fixture( $wxr_bundle, 'wxr-content-only' );
	$wxr_bundle_hash = hash_file( 'sha256', $wxr_bundle );
	$wxr_prepared = $runner->prepare_smoke_bundle( $wxr_bundle );
	$wxr_smoke_blueprint = ssgwp_read_bundle_json( $wxr_prepared['bundle_path'], 'blueprint.json' );
	$wxr_smoke_steps     = $wxr_smoke_blueprint['steps'];
	$wxr_write_step      = $wxr_smoke_steps[ count( $wxr_smoke_steps ) - 2 ];
	$wxr_assertion_step  = $wxr_smoke_steps[ count( $wxr_smoke_steps ) - 1 ];
	$wxr_assertion_code  = file_get_contents( $wxr_prepared['assertion_path'] );

	if ( ! is_string( $wxr_assertion_code ) ) {
		ssgwp_fail( 'Could not read the WXR smoke assertion file.' );
	}

	ssgwp_assert_same( 'wxr-content-only', $wxr_prepared['mode'], 'The runner detects WXR/content-only fallback bundles.' );
	ssgwp_assert_same( 'writeFile', $wxr_write_step['step'], 'The WXR smoke harness writes the bundled assertion file into Playground.' );
	ssgwp_assert_same( 'wp-cli', $wxr_assertion_step['step'], 'The WXR smoke harness injects a WP-CLI assertion step.' );
	ssgwp_assert_same( 'wp eval-file ' . SSGWP_Playground_Source_Bundle_Smoke_Runner::ASSERTION_RUNTIME_PATH, $wxr_assertion_step['command'], 'The WXR smoke assertion runs through wp eval-file so marker output is visible.' );
	ssgwp_assert_contains( "require '/wordpress/wp-load.php';", $wxr_assertion_code, 'The WXR smoke assertion can load WordPress before checking restored state.' );
	ssgwp_assert_contains( 'wxr-content-only', $wxr_assertion_code, 'The WXR assertion checks content-only mode.' );
	ssgwp_assert_not_contains( 'wp-content/database/.ht.sqlite', $wxr_assertion_code, 'The WXR assertion does not require a SQLite database.' );
	ssgwp_assert_not_contains( 'wp-content/plugins/forms/forms.php', $wxr_assertion_code, 'The WXR assertion does not require full-site fixture paths.' );
	ssgwp_assert_same( $wxr_bundle_hash, hash_file( 'sha256', $wxr_bundle ), 'The original WXR bundle hash is unchanged.' );

	ssgwp_delete_directory( $wxr_prepared['workdir'] );

	$custom_command = $runner->build_playground_command( '@wp-playground/cli@1.0.0', '/tmp/source-bundle.zip', '6.8', '8.2' );
	ssgwp_assert_same(
		array(
			'npx',
			'--yes',
			'@wp-playground/cli@1.0.0',
			'run-blueprint',
			'--blueprint=/tmp/source-bundle.zip',
			'--blueprint-may-read-adjacent-files',
			'--wp=6.8',
			'--php=8.2',
			'--verbosity=normal',
		),
		$custom_command,
		'The Playground command builder honors custom CLI, WordPress, and PHP versions.'
	);

	ssgwp_assert_same( true, SSGWP_Playground_Source_Bundle_Smoke_Runner::is_supported_node_version( 'v20.18.0' ), 'Node.js 20.18 is supported.' );
	ssgwp_assert_same( false, SSGWP_Playground_Source_Bundle_Smoke_Runner::is_supported_node_version( 'v20.17.9' ), 'Node.js 20.17 is not supported.' );
	ssgwp_assert_same( true, SSGWP_Playground_Source_Bundle_Smoke_Runner::is_playground_infrastructure_failure( 'Error: fetch failed' ), 'Fetch failures can be skipped as infrastructure failures.' );
	ssgwp_assert_same( false, SSGWP_Playground_Source_Bundle_Smoke_Runner::is_playground_infrastructure_failure( SSGWP_Playground_Source_Bundle_Smoke_Runner::FAILURE_MARKER . ': missing option' ), 'Smoke assertion failures are not infrastructure skips.' );

	$parsed = ssgwp_playground_source_smoke_parse_args(
		array(
			'smoke-playground-source-bundle.php',
			'--playground-cli',
			'@wp-playground/cli@1.0.0',
			'--wp=6.8',
			'--php',
			'8.2',
			'--no-skip-if-unavailable',
			'--dry-run',
			'--keep-bundle',
			$export_dir,
		)
	);

	ssgwp_assert_same( '@wp-playground/cli@1.0.0', $parsed['playground_cli'], 'Argument parsing accepts --playground-cli values.' );
	ssgwp_assert_same( '6.8', $parsed['wp_version'], 'Argument parsing accepts --wp=value.' );
	ssgwp_assert_same( '8.2', $parsed['php_version'], 'Argument parsing accepts --php values.' );
	ssgwp_assert_same( false, $parsed['skip_if_unavailable'], 'Argument parsing accepts --no-skip-if-unavailable.' );
	ssgwp_assert_same( true, $parsed['dry_run'], 'Argument parsing accepts --dry-run.' );
	ssgwp_assert_same( true, $parsed['keep_bundle'], 'Argument parsing accepts --keep-bundle.' );
	ssgwp_assert_same( $export_dir, $parsed['input_path'], 'Argument parsing accepts one input path.' );

	ssgwp_assert_throws_message(
		static function () {
			ssgwp_playground_source_smoke_parse_args(
				array(
					'smoke-playground-source-bundle.php',
					'--wp',
					'--dry-run',
					'/tmp/export',
				)
			);
		},
		'Missing value for --wp.',
		'Argument parsing rejects missing option values.'
	);

	$fake_bin                 = $fixture_root . '/fake-bin';
	$fake_live_result         = null;
	$original_path            = getenv( 'PATH' );
	$original_fake_status     = getenv( 'SSGWP_FAKE_PLAYGROUND_STATUS' );
	$had_original_fake_status = false !== $original_fake_status;

	ssgwp_create_fake_playground_runtime( $fake_bin );

	try {
		putenv( 'PATH=' . $fake_bin . PATH_SEPARATOR . ( false === $original_path ? '' : $original_path ) );

		putenv( 'SSGWP_FAKE_PLAYGROUND_STATUS=passed' );
		$fake_live_result = $runner->run(
			array(
				'input_path'           => $wxr_bundle,
				'skip_if_unavailable' => false,
				'keep_bundle'         => true,
			)
		);

		ssgwp_assert_same( 'playground', $fake_live_result['runtime'], 'The fake live path exercises the Playground runner branch.' );
		ssgwp_assert_same( 'passed', $fake_live_result['status'], 'The fake live path can pass from the mounted assertion result file.' );
		ssgwp_assert_same( true, is_file( $fake_live_result['result_path'] ), 'The fake live path writes the assertion result into the mounted workdir.' );
		ssgwp_assert_same( 'passed', $fake_live_result['assertion_result']['status'], 'The fake live path reads the mounted assertion result file.' );
		ssgwp_assert_same( SSGWP_Playground_Source_Bundle_Smoke_Runner::SUCCESS_MARKER, $fake_live_result['assertion_result']['marker'], 'The fake live assertion result records the success marker.' );
		ssgwp_assert_not_contains( SSGWP_Playground_Source_Bundle_Smoke_Runner::SUCCESS_MARKER, $fake_live_result['stdout'], 'The fake live pass depends on the result file, not marker output.' );

		putenv( 'SSGWP_FAKE_PLAYGROUND_STATUS=failed' );
		ssgwp_assert_throws_message(
			static function () use ( $runner, $wxr_bundle ) {
				$runner->run(
					array(
						'input_path'           => $wxr_bundle,
						'skip_if_unavailable' => true,
					)
				);
			},
			'Playground source bundle smoke assertion failed: synthetic assertion failed',
			'Assertion result failures are not skipped as infrastructure failures.'
		);

		putenv( 'SSGWP_FAKE_PLAYGROUND_STATUS=infra' );
		$fake_skip_result = $runner->run(
			array(
				'input_path'           => $wxr_bundle,
				'skip_if_unavailable' => true,
			)
		);

		ssgwp_assert_same( 'skipped', $fake_skip_result['status'], 'Infrastructure failures can skip when skip-if-unavailable is enabled.' );
		ssgwp_assert_contains( 'fetch failed', $fake_skip_result['skip_reason'], 'Infrastructure skips preserve the failure diagnostic.' );

		ssgwp_assert_throws_message(
			static function () use ( $runner, $wxr_bundle ) {
				$runner->run(
					array(
						'input_path'           => $wxr_bundle,
						'skip_if_unavailable' => false,
					)
				);
			},
			'fetch failed',
			'Infrastructure failures are not skipped when skip-if-unavailable is disabled.'
		);
	} finally {
		if ( is_array( $fake_live_result ) && isset( $fake_live_result['workdir'] ) ) {
			ssgwp_delete_directory( $fake_live_result['workdir'] );
		}

		if ( false === $original_path ) {
			putenv( 'PATH' );
		} else {
			putenv( 'PATH=' . $original_path );
		}

		if ( $had_original_fake_status ) {
			putenv( 'SSGWP_FAKE_PLAYGROUND_STATUS=' . $original_fake_status );
		} else {
			putenv( 'SSGWP_FAKE_PLAYGROUND_STATUS' );
		}
	}

	if ( '1' === getenv( 'SSGWP_RUN_PLAYGROUND_SMOKE' ) ) {
		$live_result = $runner->run(
			array(
				'input_path'          => $wxr_bundle,
				'skip_if_unavailable' => true,
			)
		);

		if ( ! in_array( $live_result['status'], array( 'passed', 'skipped' ), true ) ) {
			ssgwp_fail( 'The optional live Playground smoke returned an unexpected status: ' . $live_result['status'] );
		}
	}

	ssgwp_delete_directory( $fixture_root );
	echo "Playground source bundle smoke tests passed.\n";
} catch ( Exception $error ) {
	ssgwp_delete_directory( $fixture_root );
	throw $error;
}

/**
 * Create a tiny source bundle fixture.
 *
 * @param string $path Bundle path.
 * @param string $mode Restore mode.
 */
function ssgwp_create_source_bundle_fixture( $path, $mode ) {
	$zip = new ZipArchive();

	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		ssgwp_fail( 'Could not create fixture bundle: ' . $path );
	}

	$zip->addFromString( 'blueprint.json', ssgwp_json( ssgwp_fixture_blueprint( $mode ) ) );
	$zip->addFromString( 'source-state.json', ssgwp_json( ssgwp_fixture_source_state( $mode ) ) );
	$zip->addFromString( 'content/site-content.wxr', ssgwp_fixture_wxr() );

	if ( 'sqlite-full-site-wordpress-files' === $mode ) {
		$wordpress_files = dirname( $path ) . '/wordpress-files-' . basename( $path );
		ssgwp_create_wordpress_files_zip( $wordpress_files );
		$zip->addFile( $wordpress_files, 'wordpress-files.zip' );
		$zip->close();
		unlink( $wordpress_files );
		return;
	}

	$zip->close();
}

/**
 * Return a fixture Blueprint.
 *
 * @param string $mode Restore mode.
 * @return array<string,mixed>
 */
function ssgwp_fixture_blueprint( $mode ) {
	$steps = array(
		array(
			'step' => 'installPlugin',
			'pluginData' => array(
				'resource' => 'git:directory',
				'url' => 'https://github.com/adamziel/wp-extensions',
				'ref' => 'main',
				'refType' => 'branch',
				'path' => 'static-site-generator',
			),
			'options' => array(
				'activate' => true,
				'targetFolderName' => 'static-site-generator',
			),
		),
	);

	if ( 'sqlite-full-site-wordpress-files' === $mode ) {
		$steps[] = array(
			'step' => 'importWordPressFiles',
			'wordPressFilesZip' => array(
				'resource' => 'bundled',
				'path' => '/wordpress-files.zip',
			),
		);
	} else {
		$steps[] = array(
			'step' => 'importWxr',
			'file' => array(
				'resource' => 'bundled',
				'path' => '/content/site-content.wxr',
			),
		);
	}

	$steps[] = array(
		'step' => 'wp-cli',
		'command' => 'wp option update ssgwp_playground_source_handoff ' . escapeshellarg( ssgwp_json( ssgwp_fixture_handoff_option( $mode ), false ) ) . ' --format=json --autoload=off',
	);

	return array(
		'$schema' => 'https://playground.wordpress.net/blueprint-schema.json',
		'landingPage' => '/wp-admin/tools.php?page=playground-static-site-generator',
		'login' => true,
		'extraLibraries' => array( 'wp-cli' ),
		'steps' => $steps,
	);
}

/**
 * Return fixture source-state metadata.
 *
 * @param string $mode Restore mode.
 * @return array<string,mixed>
 */
function ssgwp_fixture_source_state( $mode ) {
	$full_site = 'sqlite-full-site-wordpress-files' === $mode;

	return array(
		'schema' => 'https://stillpress.local/playground-source-state/v1',
		'version' => 1,
		'restore' => array(
			'status' => 'source-state-generated',
			'bundle_mode' => $full_site ? 'sqlite-full-site-playground-blueprint-bundle' : 'content-only-playground-blueprint-bundle',
			'full_site_blueprint_bundle' => $full_site,
			'content_only_blueprint_bundle' => ! $full_site,
		),
	);
}

/**
 * Return fixture handoff option data.
 *
 * @param string $mode Restore mode.
 * @return array<string,mixed>
 */
function ssgwp_fixture_handoff_option( $mode ) {
	$full_site = 'sqlite-full-site-wordpress-files' === $mode;

	return array(
		'schema' => 'https://stillpress.local/playground-source-handoff/v1',
		'version' => 1,
		'source_state' => array(
			'status' => 'source-state-generated',
		),
		'restore' => array(
			'content_only' => ! $full_site,
			'full_site_restore' => $full_site,
			'not_full_restore_bundle' => ! $full_site,
			'mode' => $full_site ? 'sqlite-full-site-wordpress-files' : 'wxr-content-only',
		),
	);
}

/**
 * Create a nested wordpress-files.zip fixture.
 *
 * @param string $path ZIP path.
 */
function ssgwp_create_wordpress_files_zip( $path ) {
	$zip = new ZipArchive();

	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		ssgwp_fail( 'Could not create nested wordpress-files.zip fixture.' );
	}

	$zip->addFromString( 'wp-content/database/.ht.sqlite', 'sqlite bytes' );
	$zip->addFromString( 'wp-content/plugins/forms/forms.php', "<?php\n/* Plugin Name: Forms */\n" );
	$zip->addFromString( 'wp-content/plugins/forms/includes/field.php', "<?php\nreturn 'field';\n" );
	$zip->addFromString( 'wp-content/themes/twentytwentysix/style.css', 'body{color:#111;}' );
	$zip->addFromString( 'wp-content/uploads/2026/06/photo.jpg', 'jpeg bytes' );
	$zip->close();
}

/**
 * Return a minimal WXR fixture.
 *
 * @return string WXR XML.
 */
function ssgwp_fixture_wxr() {
	return implode(
		"\n",
		array(
			'<?xml version="1.0" encoding="UTF-8" ?>',
			'<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">',
			'<channel>',
			'<title>Smoke Fixture</title>',
			'<wp:wxr_version>1.2</wp:wxr_version>',
			'</channel>',
			'</rss>',
			'',
		)
	);
}

/**
 * Read a JSON entry from a bundle ZIP.
 *
 * @param string $bundle Bundle path.
 * @param string $entry  Entry name.
 * @return array<string,mixed>
 */
function ssgwp_read_bundle_json( $bundle, $entry ) {
	if ( is_dir( $bundle ) ) {
		$path = rtrim( $bundle, '/\\' ) . '/' . ltrim( $entry, '/\\' );

		if ( ! is_file( $path ) ) {
			ssgwp_fail( 'Missing bundle JSON file: ' . $entry );
		}

		$json = file_get_contents( $path );

		if ( ! is_string( $json ) ) {
			ssgwp_fail( 'Could not read bundle JSON file: ' . $entry );
		}

		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			ssgwp_fail( 'Invalid bundle JSON file: ' . $entry );
		}

		return $data;
	}

	$zip = new ZipArchive();

	if ( true !== $zip->open( $bundle ) ) {
		ssgwp_fail( 'Could not open bundle: ' . $bundle );
	}

	$json = $zip->getFromName( $entry );
	$zip->close();

	if ( ! is_string( $json ) ) {
		ssgwp_fail( 'Missing bundle JSON entry: ' . $entry );
	}

	$data = json_decode( $json, true );

	if ( ! is_array( $data ) ) {
		ssgwp_fail( 'Invalid bundle JSON entry: ' . $entry );
	}

	return $data;
}

/**
 * Encode deterministic JSON for fixtures.
 *
 * @param mixed $data Data to encode.
 * @param bool  $pretty Whether to pretty-print.
 * @return string JSON.
 */
function ssgwp_json( $data, $pretty = true ) {
	$options = JSON_UNESCAPED_SLASHES;

	if ( $pretty ) {
		$options |= JSON_PRETTY_PRINT;
	}

	$json = json_encode( $data, $options );

	if ( ! is_string( $json ) ) {
		ssgwp_fail( 'Could not encode fixture JSON.' );
	}

	return $json . "\n";
}

/**
 * Create local node/npx shims for exercising the non-dry smoke branch.
 *
 * @param string $directory Fake binary directory.
 */
function ssgwp_create_fake_playground_runtime( $directory ) {
	if ( ! mkdir( $directory, 0777, true ) ) {
		ssgwp_fail( 'Could not create fake Playground runtime directory.' );
	}

	ssgwp_write_executable(
		$directory . '/node',
		"#!/bin/sh\nprintf '%s\\n' 'v20.18.0'\n"
	);

	$success_marker = SSGWP_Playground_Source_Bundle_Smoke_Runner::SUCCESS_MARKER;
	$failure_marker = SSGWP_Playground_Source_Bundle_Smoke_Runner::FAILURE_MARKER;

	ssgwp_write_executable(
		$directory . '/npx',
		<<<SH
#!/bin/sh
if [ "\${1:-}" = "--version" ]; then
	printf '%s\n' '10.0.0'
	exit 0
fi

status=\${SSGWP_FAKE_PLAYGROUND_STATUS:-passed}

if [ "\$status" = "infra" ]; then
	printf '%s\n' 'Error: fetch failed' >&2
	exit 1
fi

mount_arg=''
for arg in "\$@"; do
	case "\$arg" in
		--mount=*)
			mount_arg=\${arg#--mount=}
			;;
	esac
done

if [ -z "\$mount_arg" ]; then
	printf '%s\n' 'Missing smoke result mount.' >&2
	exit 12
fi

host_path=\${mount_arg%%:*}

if ! mkdir -p "\$host_path"; then
	printf '%s\n' 'Could not create smoke result mount.' >&2
	exit 13
fi

if [ "\$status" = "failed" ]; then
	printf '%s\n' '{"status":"failed","marker":"{$failure_marker}","mode":"fake","path_count":0,"message":"synthetic assertion failed"}' > "\$host_path/smoke-result.json"
	printf '%s\n' 'Synthetic assertion failure recorded.'
	exit 0
fi

printf '%s\n' '{"status":"passed","marker":"{$success_marker}","mode":"fake","path_count":0,"message":""}' > "\$host_path/smoke-result.json"
printf '%s\n' 'Synthetic Playground run completed.'
exit 0
SH
	);
}

/**
 * Write an executable test helper script.
 *
 * @param string $path   Script path.
 * @param string $source Script source.
 */
function ssgwp_write_executable( $path, $source ) {
	if ( false === file_put_contents( $path, $source ) ) {
		ssgwp_fail( 'Could not write executable test helper: ' . $path );
	}

	if ( ! chmod( $path, 0755 ) ) {
		ssgwp_fail( 'Could not make test helper executable: ' . $path );
	}
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
 * @param string $needle  Expected substring.
 * @param string $haystack String to inspect.
 * @param string $message Failure message.
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
 * @param string $needle  Unexpected substring.
 * @param string $haystack String to inspect.
 * @param string $message Failure message.
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
