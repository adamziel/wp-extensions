<?php
/**
 * Checks for the GitHub Pages documentation site.
 *
 * @package WPExtensions
 */

$repo_root = dirname( __DIR__ );
$docs_dir  = $repo_root . '/docs';

$required_files = array(
	'index.html',
	'get-started.html',
	'examples.html',
	'limitations.html',
	'styles.css',
	'assets/stillpress-logo.svg',
	'assets/try-it-in-playground.svg',
);

foreach ( $required_files as $file ) {
	docs_assert(
		is_file( $docs_dir . '/' . $file ),
		'Docs site includes ' . $file . '.'
	);
}

$pages = array(
	'index.html'       => docs_read( $docs_dir . '/index.html' ),
	'get-started.html' => docs_read( $docs_dir . '/get-started.html' ),
	'examples.html'    => docs_read( $docs_dir . '/examples.html' ),
	'limitations.html' => docs_read( $docs_dir . '/limitations.html' ),
);

foreach ( $pages as $file => $html ) {
	docs_assert( false !== strpos( $html, 'StillPress' ), $file . ' uses the selected brand.' );
	docs_assert( false === strpos( $html, 'TODO' ), $file . ' has no TODO placeholders.' );
	docs_assert( false !== strpos( $html, 'styles.css' ), $file . ' loads the shared stylesheet.' );
}

docs_assert(
	false !== strpos( $pages['index.html'], 'playground.wordpress.net/?blueprint-url=' )
		&& false !== strpos( $pages['index.html'], 'static-site-generator-browser.json' ),
	'Landing page links to the browser Playground demo.'
);

docs_assert(
	false !== strpos( $pages['examples.html'], 'static-site-generator-brewcommerce.json' )
		&& false !== strpos( $pages['examples.html'], 'static-site-generator-cli-export.json' ),
	'Examples page links to the BrewCommerce and CLI export Blueprints.'
);

docs_assert(
	false !== strpos( $pages['get-started.html'], 'wp static-site export --output=./static-site.zip --fetch-mode=auto' )
		&& false !== strpos( $pages['get-started.html'], 'wp static-site export --output-dir=./static-site --fetch-mode=auto' )
		&& false !== strpos( $pages['get-started.html'], 'python3 -m http.server 8080' ),
	'Get started page documents regular WP-CLI export and local HTTP preview.'
);

docs_assert(
	false !== strpos( $pages['limitations.html'], 'file://' )
		&& false !== strpos( $pages['limitations.html'], 'JavaScript ES modules' )
		&& false !== strpos( $pages['limitations.html'], 'WooCommerce' ),
	'Limitations page explains file previews, module scripts, and WooCommerce snapshots.'
);

docs_assert(
	is_file( $repo_root . '/.github/workflows/deploy-pages.yml' ),
	'Repository includes a GitHub Pages deployment workflow.'
);

/**
 * Read a required docs file.
 *
 * @param string $path File path.
 * @return string File contents.
 */
function docs_read( $path ) {
	$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	if ( ! is_string( $contents ) ) {
		docs_fail( 'Could not read ' . $path );
	}

	return $contents;
}

/**
 * Assert a docs condition.
 *
 * @param bool   $condition Condition.
 * @param string $message Failure message.
 */
function docs_assert( $condition, $message ) {
	if ( ! $condition ) {
		docs_fail( $message );
	}
}

/**
 * Exit with a test failure.
 *
 * @param string $message Failure message.
 */
function docs_fail( $message ) {
	fwrite( STDERR, $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}
