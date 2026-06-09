<?php
/**
 * Contract test for the canonical StillPress preview Blueprint.
 *
 * @package PlaygroundStaticSiteGenerator
 */

$repo_root      = dirname( dirname( __DIR__ ) );
$blueprint_path = $repo_root . '/static-site-generator/blueprints/stillpress-blueprints-101-export-preview.json';
$blueprint      = ssgwp_blueprint_contract_decode( $blueprint_path );

$expected_schema        = 'https://playground.wordpress.net/blueprint-schema.json';
$expected_landing_page  = '/wp-admin/tools.php?page=playground-static-site-generator';
$expected_importer_zip  = 'https://raw.githubusercontent.com/adamziel/wp-extensions/codex/playground-publish-docs-importer/universal-wordpress-importer/dist/universal-wordpress-importer-0.1.0.zip?v=c360d5b';
$expected_import_source = 'https://github.com/WordPress/wordpress-playground/tree/trunk/packages/docs/site/docs/blueprints/tutorial';

$allowed_root_fields = array(
	'$schema',
	'meta',
	'landingPage',
	'login',
	'preferredVersions',
	'features',
	'extraLibraries',
	'steps',
);

ssgwp_blueprint_contract_assert_same(
	$expected_schema,
	isset( $blueprint['$schema'] ) ? $blueprint['$schema'] : null,
	'Blueprint declares the official Playground schema root.'
);

ssgwp_blueprint_contract_assert_same(
	$expected_landing_page,
	isset( $blueprint['landingPage'] ) ? $blueprint['landingPage'] : null,
	'Blueprint lands on Tools -> StillPress.'
);

foreach ( array_keys( $blueprint ) as $field ) {
	ssgwp_blueprint_contract_assert(
		in_array( $field, $allowed_root_fields, true ),
		'Blueprint root contains only schema-supported fields; unexpected field: ' . $field
	);
}

foreach ( array( 'plugins', 'constants', 'siteOptions', 'wpCli', 'blueprint-url' ) as $invalid_root_field ) {
	ssgwp_blueprint_contract_assert(
		! array_key_exists( $invalid_root_field, $blueprint ),
		'Blueprint omits custom root field known to fail schema validation: ' . $invalid_root_field
	);
}

ssgwp_blueprint_contract_assert(
	isset( $blueprint['features']['networking'] ) && true === $blueprint['features']['networking'],
	'Blueprint enables networking.'
);

ssgwp_blueprint_contract_assert(
	isset( $blueprint['extraLibraries'] )
		&& is_array( $blueprint['extraLibraries'] )
		&& in_array( 'wp-cli', $blueprint['extraLibraries'], true ),
	'Blueprint enables wp-cli.'
);

ssgwp_blueprint_contract_assert(
	ssgwp_blueprint_contract_has_importer_install( $blueprint, $expected_importer_zip ),
	'Blueprint installs and activates Universal WordPress Importer from the expected PR-branch ZIP.'
);

ssgwp_blueprint_contract_assert(
	ssgwp_blueprint_contract_writes_stillpress_files( $blueprint ),
	'Blueprint writes the required StillPress runtime PHP files from raw branch URLs.'
);

ssgwp_blueprint_contract_assert(
	ssgwp_blueprint_contract_activates_stillpress( $blueprint ),
	'Blueprint activates StillPress via runPHP and activate_plugin().'
);

ssgwp_blueprint_contract_assert(
	! ssgwp_blueprint_contract_installs_stillpress_from_git_directory( $blueprint ),
	'Blueprint writes StillPress raw PHP files instead of using git:directory installPlugin.'
);

ssgwp_blueprint_contract_assert(
	in_array( 'wp universal-importer import ' . $expected_import_source, ssgwp_blueprint_contract_wp_cli_commands( $blueprint ), true ),
	'Blueprint imports the WordPress Playground Blueprints tutorial GitHub tree.'
);

ssgwp_blueprint_contract_assert(
	20 <= ssgwp_blueprint_contract_count_wp_cli_command( $blueprint, 'wp universal-importer tick --max-ticks=1' ),
	'Blueprint runs enough bounded importer ticks for the current tutorial subtree.'
);

ssgwp_blueprint_contract_assert(
	in_array( 'wp universal-importer tick', ssgwp_blueprint_contract_wp_cli_commands( $blueprint ), true ),
	'Blueprint finishes with an unbounded importer tick.'
);

/**
 * Decode a Blueprint JSON file.
 *
 * @param string $path Blueprint file path.
 * @return array<string,mixed>
 */
function ssgwp_blueprint_contract_decode( $path ) {
	$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	if ( ! is_string( $contents ) ) {
		ssgwp_blueprint_contract_fail( 'Could not read ' . $path );
	}

	try {
		$decoded = json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
	} catch ( Exception $exception ) {
		ssgwp_blueprint_contract_fail( $path . ' is not valid JSON: ' . $exception->getMessage() );
	}

	if ( ! is_array( $decoded ) ) {
		ssgwp_blueprint_contract_fail( $path . ' did not decode to a JSON object.' );
	}

	return $decoded;
}

/**
 * Check whether the Blueprint installs the expected importer ZIP.
 *
 * @param array<string,mixed> $blueprint Blueprint data.
 * @param string              $expected_url Expected plugin ZIP URL.
 * @return bool
 */
function ssgwp_blueprint_contract_has_importer_install( array $blueprint, $expected_url ) {
	foreach ( ssgwp_blueprint_contract_steps( $blueprint ) as $step ) {
		if (
			'installPlugin' === ssgwp_blueprint_contract_step_name( $step )
			&& isset( $step['pluginData']['resource'], $step['pluginData']['url'], $step['options']['activate'], $step['options']['targetFolderName'] )
			&& 'url' === $step['pluginData']['resource']
			&& $expected_url === $step['pluginData']['url']
			&& true === $step['options']['activate']
			&& 'universal-wordpress-importer' === $step['options']['targetFolderName']
		) {
			return true;
		}
	}

	return false;
}

/**
 * Check whether the Blueprint writes the required StillPress files.
 *
 * @param array<string,mixed> $blueprint Blueprint data.
 * @return bool
 */
function ssgwp_blueprint_contract_writes_stillpress_files( array $blueprint ) {
	$expected_files = array(
		'/wordpress/wp-content/plugins/static-site-generator/static-site-generator.php' => 'https://raw.githubusercontent.com/adamziel/wp-extensions/codex/playground-publish-docs-importer/static-site-generator/static-site-generator.php?v=8aa7937',
		'/wordpress/wp-content/plugins/static-site-generator/includes/class-path-utils.php' => 'https://raw.githubusercontent.com/adamziel/wp-extensions/codex/playground-publish-docs-importer/static-site-generator/includes/class-path-utils.php?v=8aa7937',
		'/wordpress/wp-content/plugins/static-site-generator/includes/class-url-collector.php' => 'https://raw.githubusercontent.com/adamziel/wp-extensions/codex/playground-publish-docs-importer/static-site-generator/includes/class-url-collector.php?v=8aa7937',
		'/wordpress/wp-content/plugins/static-site-generator/includes/class-url-rewriter.php' => 'https://raw.githubusercontent.com/adamziel/wp-extensions/codex/playground-publish-docs-importer/static-site-generator/includes/class-url-rewriter.php?v=8aa7937',
		'/wordpress/wp-content/plugins/static-site-generator/includes/class-static-exporter.php' => 'https://raw.githubusercontent.com/adamziel/wp-extensions/codex/playground-publish-docs-importer/static-site-generator/includes/class-static-exporter.php?v=8aa7937',
		'/wordpress/wp-content/plugins/static-site-generator/includes/class-plugin.php' => 'https://raw.githubusercontent.com/adamziel/wp-extensions/codex/playground-publish-docs-importer/static-site-generator/includes/class-plugin.php?v=8aa7937',
	);
	$written_files  = array();

	foreach ( ssgwp_blueprint_contract_steps( $blueprint ) as $step ) {
		if (
			'writeFile' === ssgwp_blueprint_contract_step_name( $step )
			&& isset( $step['path'], $step['data']['resource'], $step['data']['url'] )
			&& 'url' === $step['data']['resource']
		) {
			$written_files[ $step['path'] ] = $step['data']['url'];
		}
	}

	foreach ( $expected_files as $path => $url ) {
		if ( ! isset( $written_files[ $path ] ) || $url !== $written_files[ $path ] ) {
			return false;
		}
	}

	return true;
}

/**
 * Check whether StillPress activation happens through runPHP.
 *
 * @param array<string,mixed> $blueprint Blueprint data.
 * @return bool
 */
function ssgwp_blueprint_contract_activates_stillpress( array $blueprint ) {
	foreach ( ssgwp_blueprint_contract_steps( $blueprint ) as $step ) {
		if (
			'runPHP' === ssgwp_blueprint_contract_step_name( $step )
			&& isset( $step['code'] )
			&& false !== strpos( $step['code'], "activate_plugin( 'static-site-generator/static-site-generator.php' )" )
		) {
			return true;
		}
	}

	return false;
}

/**
 * Check whether any installPlugin step still installs StillPress from git:directory.
 *
 * @param array<string,mixed> $blueprint Blueprint data.
 * @return bool
 */
function ssgwp_blueprint_contract_installs_stillpress_from_git_directory( array $blueprint ) {
	foreach ( ssgwp_blueprint_contract_steps( $blueprint ) as $step ) {
		if (
			'installPlugin' === ssgwp_blueprint_contract_step_name( $step )
			&& isset( $step['pluginData']['resource'], $step['pluginData']['path'] )
			&& 'git:directory' === $step['pluginData']['resource']
			&& 'static-site-generator' === $step['pluginData']['path']
		) {
			return true;
		}
	}

	return false;
}

/**
 * Return WP-CLI command strings from a Blueprint.
 *
 * @param array<string,mixed> $blueprint Blueprint data.
 * @return array<int,string>
 */
function ssgwp_blueprint_contract_wp_cli_commands( array $blueprint ) {
	$commands = array();

	foreach ( ssgwp_blueprint_contract_steps( $blueprint ) as $step ) {
		if ( 'wp-cli' === ssgwp_blueprint_contract_step_name( $step ) && isset( $step['command'] ) ) {
			$commands[] = (string) $step['command'];
		}
	}

	return $commands;
}

/**
 * Count a WP-CLI command in a Blueprint.
 *
 * @param array<string,mixed> $blueprint Blueprint data.
 * @param string              $command   Command to count.
 * @return int
 */
function ssgwp_blueprint_contract_count_wp_cli_command( array $blueprint, $command ) {
	$count = 0;

	foreach ( ssgwp_blueprint_contract_wp_cli_commands( $blueprint ) as $candidate ) {
		if ( $command === $candidate ) {
			++$count;
		}
	}

	return $count;
}

/**
 * Return normalized Blueprint step arrays.
 *
 * @param array<string,mixed> $blueprint Blueprint data.
 * @return array<int,array<string,mixed>>
 */
function ssgwp_blueprint_contract_steps( array $blueprint ) {
	return isset( $blueprint['steps'] ) && is_array( $blueprint['steps'] )
		? array_values( array_filter( $blueprint['steps'], 'is_array' ) )
		: array();
}

/**
 * Return the Blueprint step name.
 *
 * @param array<string,mixed> $step Blueprint step.
 * @return string
 */
function ssgwp_blueprint_contract_step_name( array $step ) {
	return isset( $step['step'] ) ? (string) $step['step'] : '';
}

/**
 * Assert strict equality.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Failure message.
 * @return void
 */
function ssgwp_blueprint_contract_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		ssgwp_blueprint_contract_fail( $message );
	}
}

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure message.
 * @return void
 */
function ssgwp_blueprint_contract_assert( $condition, $message ) {
	if ( ! $condition ) {
		ssgwp_blueprint_contract_fail( $message );
	}
}

/**
 * Fail the contract test.
 *
 * @param string $message Failure message.
 * @return void
 */
function ssgwp_blueprint_contract_fail( $message ) {
	fwrite( STDERR, $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}
