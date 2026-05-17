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
	'importer/index.html',
	'importer/get-started.html',
	'importer/examples.html',
	'importer/formats.html',
	'dev/motion-bench.html',
	'styles.css',
	'assets/portpress-logo.svg',
	'assets/portpress-flow.svg',
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
	'importer/index.html'       => docs_read( $docs_dir . '/importer/index.html' ),
	'importer/get-started.html' => docs_read( $docs_dir . '/importer/get-started.html' ),
	'importer/examples.html'    => docs_read( $docs_dir . '/importer/examples.html' ),
	'importer/formats.html'     => docs_read( $docs_dir . '/importer/formats.html' ),
);

foreach ( $pages as $file => $html ) {
	docs_assert( false === strpos( $html, 'TODO' ), $file . ' has no TODO placeholders.' );
	docs_assert( false !== strpos( $html, 'styles.css' ), $file . ' loads the shared stylesheet.' );
	docs_assert( false !== strpos( $html, 'PortPress' ), $file . ' uses the PortPress brand.' );
	docs_assert( false === strpos( $html, 'WP Extensions' ), $file . ' does not use the old umbrella brand.' );
	docs_assert( false === strpos( $html, 'wp-extensions-logo.svg' ), $file . ' does not reference the old umbrella logo.' );
}

docs_assert(
	false !== strpos( $pages['index.html'], 'playground.wordpress.net/?blueprint-url=' )
		&& false !== strpos( $pages['index.html'], 'portpress-demo.json' ),
	'Landing page links to the guided PortPress Playground demo.'
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

$playground_button = docs_read( $docs_dir . '/assets/try-it-in-playground.svg' );

docs_assert(
	false !== strpos( $playground_button, '<svg width="447" height="104"' )
		&& false !== strpos( $playground_button, 'fill="#3858E9"' ),
	'Docs site uses the official WordPress Playground preview button SVG.'
);

$portpress_logo = docs_read( $docs_dir . '/assets/portpress-logo.svg' );
$portpress_flow = docs_read( $docs_dir . '/assets/portpress-flow.svg' );
$motion_bench   = docs_read( $docs_dir . '/dev/motion-bench.html' );

docs_assert(
	false !== strpos( $portpress_logo, 'PortPress' )
		&& false !== strpos( $portpress_logo, 'content portability for WordPress' ),
	'Docs site includes the PortPress wordmark.'
);

docs_assert(
	false !== strpos( $pages['index.html'], 'assets/portpress-flow.svg' )
		&& false !== strpos( $portpress_flow, 'Static files' )
		&& false !== strpos( $portpress_flow, 'WordPress' )
		&& false !== strpos( $portpress_flow, 'Static site' )
		&& false !== strpos( $portpress_flow, '1. Import' )
		&& false !== strpos( $portpress_flow, '2. Export' )
		&& false !== strpos( $portpress_flow, 'README.md' )
		&& false === strpos( $portpress_flow, 'draft.md' )
		&& false !== strpos( $portpress_flow, 'prefers-reduced-motion' ),
	'Landing page includes the animated PortPress flow illustration.'
);

docs_assert(
	false !== strpos( $pages['index.html'], '1. Static files -> WordPress' )
		&& false !== strpos( $pages['index.html'], '2. WordPress -> Static HTML' )
		&& strpos( $pages['index.html'], '1. Static files -> WordPress' ) < strpos( $pages['index.html'], '2. WordPress -> Static HTML' ),
	'Landing page orders demos from static files into WordPress, then WordPress into static HTML.'
);

docs_assert(
	false !== strpos( $motion_bench, '../assets/portpress-flow.svg' )
		&& false !== strpos( $motion_bench, 'FRAME_POINTS' )
		&& false !== strpos( $motion_bench, 'setCurrentTime' )
		&& false !== strpos( $motion_bench, 'Agent Review Checklist' ),
	'Docs include a dev-only motion bench for reviewing PortPress animation frames.'
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
