#!/usr/bin/env php
<?php
/**
 * Runs a clean WordPress activation smoke test for a release zip.
 *
 * @package UniversalImporter
 */

use UniversalImporter\Tools\ReleaseActivationSmokeRunner;

require_once __DIR__ . '/ReleaseActivationSmokeRunner.php';

$options = getopt(
	'',
	array(
		'zip:',
		'build',
		'output:',
		'allow-dirty',
		'skip-build-checks',
		'use-existing-vendor',
		'runtime:',
		'playground-cli:',
		'wp:',
		'php:',
		'wp-cli-phar:',
		'keep-bundle',
		'keep-workdir',
		'help',
	)
);

if ( isset( $options['help'] ) ) {
	echo "Usage: php tools/smoke-release-activation.php [--zip=dist/plugin.zip | --build] [options]\n";
	echo "\n";
	echo "Options:\n";
	echo "  --build                 Build the release zip before smoke testing.\n";
	echo "  --output=dist           Build output directory when --build is used.\n";
	echo "  --allow-dirty           Allow a dirty tree when --build is used.\n";
	echo "  --skip-build-checks     Skip release builder preflight checks when --build is used.\n";
	echo "  --use-existing-vendor   Copy the current vendor tree when --build is used.\n";
	echo "  --runtime=auto          Smoke runtime: auto, playground, or local. Default: auto.\n";
	echo "  --playground-cli=spec   npm package spec for Playground CLI. Default: @wp-playground/cli@latest.\n";
	echo "  --wp=latest             WordPress version for the smoke runtime.\n";
	echo "  --php=8.3               PHP version for Playground.\n";
	echo "  --wp-cli-phar=path      Existing WP-CLI Phar for the local runtime.\n";
	echo "  --keep-bundle           Keep the generated blueprint bundle for debugging.\n";
	echo "  --keep-workdir          Keep the local runtime work directory for debugging.\n";
	exit( 0 );
}

$repo_root = dirname( __DIR__ );

try {
	$runner = new ReleaseActivationSmokeRunner( $repo_root );
	$summary = $runner->run(
		array(
			'zip_path'            => isset( $options['zip'] ) ? $options['zip'] : null,
			'build_release'       => isset( $options['build'] ),
			'output_dir'          => isset( $options['output'] ) ? $options['output'] : 'dist',
			'allow_dirty'         => isset( $options['allow-dirty'] ),
			'run_build_checks'    => ! isset( $options['skip-build-checks'] ),
			'use_existing_vendor' => isset( $options['use-existing-vendor'] ),
			'runtime'             => isset( $options['runtime'] ) ? $options['runtime'] : 'auto',
			'playground_cli'      => isset( $options['playground-cli'] ) ? $options['playground-cli'] : '@wp-playground/cli@latest',
			'wp_version'          => isset( $options['wp'] ) ? $options['wp'] : 'latest',
			'php_version'         => isset( $options['php'] ) ? $options['php'] : '8.3',
			'wp_cli_phar'         => isset( $options['wp-cli-phar'] ) ? $options['wp-cli-phar'] : null,
			'keep_bundle'         => isset( $options['keep-bundle'] ),
			'keep_workdir'        => isset( $options['keep-workdir'] ),
		)
	);

	echo 'Release activation smoke passed for: ' . $summary['zip_path'] . "\n";
	echo 'Runtime: ' . $summary['runtime'] . "\n";
	if ( ! empty( $summary['fallback_reason'] ) ) {
		echo "Fallback reason: " . strtok( $summary['fallback_reason'], "\n" ) . "\n";
	}
	if ( 'playground' === $summary['runtime'] && ! empty( $summary['bundle_kept'] ) ) {
		echo 'Blueprint: ' . $summary['blueprint_path'] . "\n";
	} elseif ( 'playground' === $summary['runtime'] ) {
		echo "Blueprint bundle: temporary, removed after the smoke run\n";
	}
	if ( 'local' === $summary['runtime'] && ! empty( $summary['workdir_kept'] ) ) {
		echo 'Local workdir: ' . $summary['workdir_path'] . "\n";
	} elseif ( 'local' === $summary['runtime'] ) {
		echo "Local workdir: temporary, removed after the smoke run\n";
	}
	if ( ! empty( $summary['command'] ) ) {
		echo 'Command: ' . implode( ' ', array_map( 'escapeshellarg', $summary['command'] ) ) . "\n";
	}
	exit( 0 );
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Release activation smoke failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
