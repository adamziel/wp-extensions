#!/usr/bin/env php
<?php
/**
 * Verifies release zip package integrity without activating WordPress.
 *
 * @package UniversalImporter
 */

use UniversalImporter\Tools\ReleasePackageBuilder;

require_once __DIR__ . '/ReleasePackageBuilder.php';

$options = getopt( '', array( 'zip:', 'help' ) );

if ( isset( $options['help'] ) || empty( $options['zip'] ) ) {
	echo "Usage: php tools/verify-release-zip.php --zip=dist/universal-wordpress-importer-0.1.0.zip\n";
	echo "\n";
	echo "Checks that a release zip has one plugin root, required runtime files,\n";
	echo "Composer vendor autoload, and maintained release exclusions.\n";
	exit( isset( $options['help'] ) ? 0 : 2 );
}

try {
	$zip_path = $options['zip'];
	$summary  = verify_release_zip( $zip_path );

	echo 'Release zip integrity passed: ' . $summary['zip_path'] . "\n";
	echo 'Entries: ' . $summary['entries'] . "\n";
	echo 'Root: ' . $summary['root'] . "\n";
	exit( 0 );
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Release zip integrity failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
}

/**
 * Verifies release zip contents.
 *
 * @param string $zip_path Zip path.
 * @return array{zip_path:string,entries:int,root:string}
 */
function verify_release_zip( $zip_path ) {
	$real_zip = realpath( $zip_path );

	if ( false === $real_zip || ! is_file( $real_zip ) ) {
		throw new RuntimeException( 'Release zip does not exist: ' . $zip_path );
	}

	if ( ! class_exists( ZipArchive::class ) ) {
		throw new RuntimeException( 'The PHP zip extension is required to inspect release zips.' );
	}

	$zip = new ZipArchive();

	if ( true !== $zip->open( $real_zip ) ) {
		throw new RuntimeException( 'Unable to open release zip: ' . $real_zip );
	}

	$paths      = array();
	$seen_paths = array();

	for ( $index = 0; $index < $zip->numFiles; ++$index ) {
		$path = $zip->getNameIndex( $index );

		if ( ! is_string( $path ) ) {
			throw new RuntimeException( 'Unable to read release zip entry at index: ' . $index );
		}

		if ( is_release_zip_symlink_entry( $zip, $index ) ) {
			throw new RuntimeException( 'Release zip contains symlink entry path: ' . $path );
		}

		if ( isset( $seen_paths[ $path ] ) ) {
			throw new RuntimeException( 'Release zip contains duplicate entry path: ' . $path );
		}

		$seen_paths[ $path ] = true;
		$paths[]            = $path;
	}

	$zip->close();

	sort( $paths );

	$root = ReleasePackageBuilder::PLUGIN_SLUG . '/';
	$set  = array_fill_keys( $paths, true );

	foreach ( $paths as $path ) {
		if ( is_unsafe_release_zip_path( $path ) ) {
			throw new RuntimeException( 'Release zip contains an unsafe entry path: ' . $path );
		}

		if ( 0 !== strpos( $path, $root ) ) {
			throw new RuntimeException( 'Release zip contains a path outside the plugin root: ' . $path );
		}
	}

	$required = array(
		$root . 'universal-wordpress-importer.php',
		$root . 'composer.json',
		$root . 'composer.lock',
		$root . 'vendor/autoload.php',
		$root . 'src/Plugin.php',
		$root . 'README.md',
		$root . 'readme.txt',
		$root . 'CHANGELOG.md',
		$root . 'docs/release-packaging.md',
	);

	foreach ( $required as $path ) {
		if ( ! isset( $set[ $path ] ) ) {
			throw new RuntimeException( 'Release zip is missing required runtime path: ' . $path );
		}
	}

	$excluded = array(
		$root . '.distignore',
		$root . '.autonomous-loop/goal.md',
		$root . 'tests/bootstrap.php',
		$root . 'phpunit.xml.dist',
		$root . 'phpstan.neon.dist',
		$root . 'phpstan-stubs/wp-cli.php',
		$root . 'run_autonomous_loop.sh',
		$root . 'scripts/codex-loop.sh',
		$root . 'tools/build-release.php',
		$root . 'vendor/bin/phpunit',
	);

	foreach ( $excluded as $path ) {
		if ( isset( $set[ $path ] ) ) {
			throw new RuntimeException( 'Release zip contains excluded path: ' . $path );
		}
	}

	foreach ( $paths as $path ) {
		if (
			false !== strpos( $path, '/.git/' )
			|| false !== strpos( $path, '/.autonomous-loop/' )
			|| false !== strpos( $path, '/tests/' )
			|| false !== strpos( $path, '/tools/' )
			|| false !== strpos( $path, '/dist/' )
			|| false !== strpos( $path, '/phpstan-stubs/' )
			|| false !== strpos( $path, '/vendor/bin/' )
		) {
			throw new RuntimeException( 'Release zip contains an excluded tree path: ' . $path );
		}
	}

	return array(
		'zip_path' => $real_zip,
		'entries'  => count( $paths ),
		'root'     => ReleasePackageBuilder::PLUGIN_SLUG,
	);
}

/**
 * Returns whether a zip entry path is unsafe to extract.
 *
 * @param string $path Zip entry path.
 * @return bool Whether the path is unsafe.
 */
function is_unsafe_release_zip_path( $path ) {
	$path = (string) $path;

	if ( '' === $path || '/' === $path[0] || false !== strpos( $path, '\\' ) ) {
		return true;
	}

	$path  = rtrim( $path, '/' );
	$parts = explode( '/', $path );

	foreach ( $parts as $part ) {
		if ( '' === $part || '.' === $part || '..' === $part ) {
			return true;
		}
	}

	return false;
}

/**
 * Returns whether a zip entry is a Unix symlink.
 *
 * @param ZipArchive $zip   Zip archive.
 * @param int        $index Entry index.
 * @return bool Whether the entry is a symlink.
 */
function is_release_zip_symlink_entry( ZipArchive $zip, $index ) {
	if ( ! method_exists( $zip, 'getExternalAttributesIndex' ) ) {
		return false;
	}

	$opsys = 0;
	$attr  = 0;

	if ( ! $zip->getExternalAttributesIndex( $index, $opsys, $attr ) ) {
		return false;
	}

	return ZipArchive::OPSYS_UNIX === $opsys && 0120000 === ( ( $attr >> 16 ) & 0170000 );
}
