#!/usr/bin/env php
<?php
/**
 * Builds an installable Universal WordPress Importer release zip.
 *
 * @package UniversalImporter
 */

use UniversalImporter\Tools\ReleasePackageBuilder;

require_once __DIR__ . '/ReleasePackageBuilder.php';

$options = getopt(
	'',
	array(
		'output:',
		'allow-dirty',
		'skip-checks',
		'use-existing-vendor',
		'help',
	)
);

if ( isset( $options['help'] ) ) {
	echo "Usage: php tools/build-release.php [--output=dist] [--allow-dirty] [--skip-checks] [--use-existing-vendor]\n";
	echo "\n";
	echo "By default the builder requires a clean git tree, runs composer validate/test/lint/diff checks,\n";
	echo "stages the plugin, runs composer install --no-dev in staging, writes a versioned zip,\n";
	echo "and verifies package integrity before reporting success.\n";
	exit( 0 );
}

$repo_root = dirname( __DIR__ );
$output    = isset( $options['output'] ) ? $options['output'] : 'dist';

try {
	$builder = new ReleasePackageBuilder( $repo_root );
	$summary = $builder->build(
		$output,
		array(
			'allow_dirty'         => isset( $options['allow-dirty'] ),
			'run_checks'          => ! isset( $options['skip-checks'] ),
			'use_existing_vendor' => isset( $options['use-existing-vendor'] ),
		)
	);

	$verify_command = array( PHP_BINARY, __DIR__ . '/verify-release-zip.php', '--zip=' . $summary['zip_path'] );
	$verify_result  = run_release_command( $verify_command, $repo_root );

	if ( 0 !== $verify_result['exit_code'] ) {
		throw new RuntimeException( "Release zip verification failed.\n" . trim( $verify_result['stderr'] . "\n" . $verify_result['stdout'] ) );
	}

	echo 'Built release zip: ' . $summary['zip_path'] . "\n";
	echo 'Version: ' . $summary['version'] . "\n";
	echo 'Vendor mode: ' . $summary['vendor_mode'] . "\n";
	echo 'Preflight checks: ' . ( $summary['checks_ran'] ? 'ran' : 'skipped' ) . "\n";
	echo 'Package integrity: verified' . "\n";
	exit( 0 );
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Release build failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
}

/**
 * Runs a release helper command.
 *
 * @param string[] $command Command and arguments.
 * @param string   $cwd     Working directory.
 * @return array{exit_code:int,stdout:string,stderr:string} Command result.
 */
function run_release_command( array $command, $cwd ) {
	$descriptor_spec = array(
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$command_string  = implode( ' ', array_map( 'escapeshellarg', $command ) );
	$pipes           = array();
	$process         = proc_open( $command_string, $descriptor_spec, $pipes, $cwd );

	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Unable to start release helper command: ' . $command_string );
	}

	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );

	fclose( $pipes[1] );
	fclose( $pipes[2] );

	return array(
		'exit_code' => proc_close( $process ),
		'stdout'    => false === $stdout ? '' : $stdout,
		'stderr'    => false === $stderr ? '' : $stderr,
	);
}
