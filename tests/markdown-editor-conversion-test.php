<?php
/**
 * Regression checks for the Markdown editor conversion boundary.
 *
 * @package WPExtensions
 */

$repo_root = dirname( __DIR__ );

define( 'EDIT_MD_TOOLKIT_AUTOLOAD', $repo_root . '/markdown-editor/vendor/php-toolkit/vendor/autoload.php' );

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {}
}

require_once $repo_root . '/markdown-editor/edit-markdown-mu-plugin.php';

md_editor_assert_same(
	"<!-- wp:paragraph -->\n<p>Body</p>\n<!-- /wp:paragraph -->\n\n",
	edit_md_markdown_to_blocks( "# Same Title\n\nBody", 'Same Title' ),
	'Matching leading H1 is hidden from block content.'
);

md_editor_assert_contains(
	'<h1 class="wp-block-heading" id="different-title">Different Title</h1>',
	edit_md_markdown_to_blocks( "# Different Title\n\nBody", 'Same Title' ),
	'Non-matching leading H1 remains in block content.'
);

md_editor_assert_contains(
	'<h2 class="wp-block-heading" id="same-title">Same Title</h2>',
	edit_md_markdown_to_blocks( "## Same Title\n\nBody", 'Same Title' ),
	'Leading H2 is not treated as the page title.'
);

md_editor_assert_contains(
	'<h1 class="wp-block-heading" id="same-title">Same Title</h1>',
	edit_md_markdown_to_blocks( "Intro paragraph.\n\n# Same Title\n\nBody", 'Same Title' ),
	'Non-leading H1 is not treated as the page title.'
);

md_editor_assert_same(
	"<!-- wp:paragraph -->\n<p>Body</p>\n<!-- /wp:paragraph -->\n\n",
	edit_md_markdown_to_blocks( "\n\n# Same **Title**\n\nBody", 'Same Title' ),
	'Heading inline formatting is compared through parsed text, not Markdown syntax.'
);

md_editor_assert_same(
	"<!-- wp:paragraph -->\n<p>Body</p>\n<!-- /wp:paragraph -->\n\n",
	edit_md_markdown_to_blocks( "# Same [Title](https://example.com)\n\nBody", 'Same Title' ),
	'Heading link text is compared through parsed text, not Markdown syntax.'
);

$strikethrough_blocks = edit_md_markdown_to_blocks(
	"# Core Blocks Reference\n\n- Items marked with a strikeout (~~strikeout~~) are explicitly disabled.\n- Blocks marked with **Experimental:** true continue.\n\n## Accordion\n\n- **Name:** core/accordion",
	'Other Title'
);

md_editor_assert_contains(
	'<del>strikeout</del>',
	$strikethrough_blocks,
	'GitHub-flavored strikethrough is converted to HTML.'
);

md_editor_assert_contains(
	'core/accordion',
	$strikethrough_blocks,
	'Conversion continues after strikethrough instead of truncating the document.'
);

md_editor_assert_same(
	"# Same Title\n\nBody\n\n",
	edit_md_blocks_to_markdown(
		"<!-- wp:paragraph -->\n<p>Body</p>\n<!-- /wp:paragraph -->",
		'Same Title',
		true
	),
	'Hidden leading H1 is restored when the stored Markdown originally had one.'
);

md_editor_assert_same(
	"Body\n\n",
	edit_md_blocks_to_markdown(
		"<!-- wp:paragraph -->\n<p>Body</p>\n<!-- /wp:paragraph -->",
		'Same Title',
		false
	),
	'Leading H1 is not added when the stored Markdown did not have one.'
);

md_editor_assert_same(
	"# Same Title\n\nBody\n\n",
	edit_md_blocks_to_markdown(
		"<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Same Title</h1>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Body</p>\n<!-- /wp:paragraph -->",
		'Same Title',
		true
	),
	'Restore path does not duplicate an H1 already present in edited block content.'
);

md_editor_assert_same(
	"\n<p>Raw</p>\n",
	edit_md_strip_block_delimiters( "<!-- wp:html -->\n<p>Raw</p>\n<!-- /wp:html -->" ),
	'Block delimiter fallback strips opening and closing block comments without a regexp.'
);

md_editor_assert_same(
	'',
	md_editor_find_regexp_parsing_calls( $repo_root . '/markdown-editor/edit-markdown-mu-plugin.php' ),
	'Markdown editor mu-plugin does not use preg_* parsing helpers.'
);

/**
 * Assert strict equality.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual Actual value.
 * @param string $message Failure message.
 */
function md_editor_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		md_editor_fail(
			$message . "\nExpected:\n" . var_export( $expected, true ) . "\nActual:\n" . var_export( $actual, true )
		);
	}
}

/**
 * Assert a string contains a fragment.
 *
 * @param string $needle Expected fragment.
 * @param string $haystack Full string.
 * @param string $message Failure message.
 */
function md_editor_assert_contains( $needle, $haystack, $message ) {
	if ( false === strpos( $haystack, $needle ) ) {
		md_editor_fail( $message . "\nMissing fragment:\n" . $needle . "\nActual:\n" . $haystack );
	}
}

/**
 * Find disallowed preg_* function calls using PHP tokens.
 *
 * @param string $path PHP file path.
 * @return string Newline-separated findings, or empty string.
 */
function md_editor_find_regexp_parsing_calls( $path ) {
	$source = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! is_string( $source ) ) {
		md_editor_fail( 'Could not read ' . $path );
	}

	$findings = array();
	foreach ( token_get_all( $source ) as $token ) {
		if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
			continue;
		}
		if ( 0 === strpos( $token[1], 'preg_' ) ) {
			$findings[] = $token[1] . ' on line ' . $token[2];
		}
	}

	return implode( "\n", $findings );
}

/**
 * Exit with a test failure.
 *
 * @param string $message Failure message.
 */
function md_editor_fail( $message ) {
	fwrite( STDERR, $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}
