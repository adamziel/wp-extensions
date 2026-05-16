<?php
/**
 * Plugin Name: Playground CLI — Edit Markdown
 * Description: Points wp_posts / wp_postmeta at a directory tree of `.md`
 *   files via sqlite-markdown virtual tables registered by a PHP.wasm
 *   extension loaded before PHP starts.
 *   PHP never enumerates the file tree — SQLite is the boundary that reads
 *   and writes Markdown.
 *
 * Architecture:
 *
 *   disk: /markdown-root/**\/*.md
 *        ↕ (sqlite-markdown registered via sqlite3_auto_extension; provides
 *           markdown_posts / markdown_postmeta virtual tables)
 *   SQLite engine inside Playground's PHP
 *        ↕
 *   wp_posts and wp_postmeta — CREATE VIRTUAL TABLE … USING markdown_posts
 *        ↕
 *   WordPress (sees plain wp_posts rows; the editor stores blocks; the
 *   filters below convert blocks → markdown on write and markdown → blocks
 *   on read so the on-disk files stay human-editable Markdown)
 *
 * The mu-plugin's only responsibility is to swap the regular wp_posts /
 * wp_postmeta tables for the virtual ones once, and translate post_content
 * between Markdown and block markup at the editor boundary using
 * wp-php-toolkit/markdown.
 */

if ( ! defined( 'EDIT_MD_ROOT' ) ) {
	define( 'EDIT_MD_ROOT', '/markdown-root' );
}
if ( ! defined( 'EDIT_MD_TOOLKIT_AUTOLOAD' ) ) {
	define(
		'EDIT_MD_TOOLKIT_AUTOLOAD',
		'/wordpress/wp-content/mu-plugins/vendor/php-toolkit/vendor/autoload.php'
	);
}

/**
 * Load the php-toolkit composer autoloader. Returns true on success.
 */
function edit_md_load_toolkit() {
	if ( class_exists( '\\WordPress\\Markdown\\MarkdownConsumer' ) ) {
		return true;
	}
	if ( is_readable( EDIT_MD_TOOLKIT_AUTOLOAD ) ) {
		require_once EDIT_MD_TOOLKIT_AUTOLOAD;
	}
	return class_exists( '\\WordPress\\Markdown\\MarkdownConsumer' );
}

/**
 * Return true when $content already contains WordPress block delimiters.
 * Used to guard against double-conversion when content passes through
 * multiple hooks (e.g. `the_post` then `rest_prepare_page`).
 */
function edit_md_looks_like_blocks( $content ) {
	return strpos( (string) $content, '<!-- wp:' ) !== false;
}

/**
 * Collapse whitespace for comparing plain text values. Markdown has already
 * been parsed by CommonMark before heading text reaches this function.
 */
function edit_md_collapse_text_whitespace( $text ) {
	$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$output = '';
	$in_whitespace = false;
	$length = strlen( $text );

	for ( $i = 0; $i < $length; $i++ ) {
		$char = $text[ $i ];
		if ( ctype_space( $char ) ) {
			if ( $output !== '' ) {
				$in_whitespace = true;
			}
			continue;
		}
		if ( $in_whitespace ) {
			$output .= ' ';
			$in_whitespace = false;
		}
		$output .= $char;
	}

	return $output;
}

/**
 * Parse Markdown into a CommonMark document when the toolkit is available.
 */
function edit_md_parse_markdown_document( $markdown ) {
	if ( ! edit_md_load_toolkit() ) {
		return null;
	}

	$environment = new \League\CommonMark\Environment\Environment( array() );
	$environment->addExtension( new \League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension() );
	$environment->addExtension( new \League\CommonMark\Extension\GithubFlavoredMarkdownExtension() );

	$parser = new \League\CommonMark\Parser\MarkdownParser( $environment );
	return $parser->parse( (string) $markdown );
}

function edit_md_collect_node_text( $node ) {
	if ( $node instanceof \League\CommonMark\Node\Inline\Text ) {
		return $node->getLiteral();
	}
	if ( $node instanceof \League\CommonMark\Extension\CommonMark\Node\Inline\Code ) {
		return $node->getLiteral();
	}
	if ( $node instanceof \League\CommonMark\Node\Inline\Newline ) {
		return ' ';
	}

	$text = '';
	foreach ( $node->children() as $child ) {
		$text .= edit_md_collect_node_text( $child );
	}
	return $text;
}

function edit_md_get_leading_h1( $markdown ) {
	$document = edit_md_parse_markdown_document( $markdown );
	if ( ! $document ) {
		return null;
	}

	foreach ( $document->children() as $child ) {
		if ( $child instanceof \League\CommonMark\Extension\CommonMark\Node\Block\Heading ) {
			if ( $child->getLevel() !== 1 ) {
				return null;
			}
			return array(
				'text' => edit_md_collect_node_text( $child ),
				'start_line' => $child->getStartLine(),
				'end_line' => $child->getEndLine(),
			);
		}
		return null;
	}

	return null;
}

function edit_md_has_redundant_leading_h1( $markdown, $post_title ) {
	if ( edit_md_collapse_text_whitespace( $post_title ) === '' ) {
		return false;
	}
	$heading = edit_md_get_leading_h1( $markdown );
	if ( $heading === null || ! isset( $heading['text'] ) ) {
		return false;
	}
	return edit_md_collapse_text_whitespace( $heading['text'] ) === edit_md_collapse_text_whitespace( $post_title );
}

function edit_md_split_lines_preserve_endings( $text ) {
	$text = (string) $text;
	$lines = array();
	$line_start = 0;
	$length = strlen( $text );

	for ( $i = 0; $i < $length; $i++ ) {
		if ( $text[ $i ] !== "\n" && $text[ $i ] !== "\r" ) {
			continue;
		}
		if ( $text[ $i ] === "\r" && $i + 1 < $length && $text[ $i + 1 ] === "\n" ) {
			$i++;
		}
		$lines[] = substr( $text, $line_start, $i - $line_start + 1 );
		$line_start = $i + 1;
	}

	if ( $line_start < $length ) {
		$lines[] = substr( $text, $line_start );
	}

	return $lines;
}

function edit_md_strip_redundant_leading_h1( $markdown, $post_title ) {
	$markdown = (string) $markdown;
	$heading = edit_md_get_leading_h1( $markdown );
	if (
		! $heading ||
		edit_md_collapse_text_whitespace( $heading['text'] ) !== edit_md_collapse_text_whitespace( $post_title )
	) {
		return $markdown;
	}

	$lines = edit_md_split_lines_preserve_endings( $markdown );
	$start = max( 0, (int) $heading['start_line'] - 1 );
	$end = max( $start, (int) $heading['end_line'] - 1 );

	while ( $start > 0 && trim( $lines[ $start - 1 ] ) === '' ) {
		$start--;
	}
	while ( isset( $lines[ $end + 1 ] ) && trim( $lines[ $end + 1 ] ) === '' ) {
		$end++;
	}

	array_splice( $lines, $start, $end - $start + 1 );
	return implode( '', $lines );
}

function edit_md_ensure_leading_h1( $markdown, $post_title ) {
	$markdown = (string) $markdown;
	$post_title = edit_md_collapse_text_whitespace( $post_title );
	if ( $post_title === '' || edit_md_has_redundant_leading_h1( $markdown, $post_title ) ) {
		return $markdown;
	}
	return '# ' . $post_title . "\n\n" . ltrim( $markdown );
}

function edit_md_strip_block_delimiters( $blocks ) {
	$blocks = (string) $blocks;
	$output = '';
	$offset = 0;

	while ( true ) {
		$start = strpos( $blocks, '<!-- wp:', $offset );
		$closing_start = strpos( $blocks, '<!-- /wp:', $offset );
		if ( $start === false || ( $closing_start !== false && $closing_start < $start ) ) {
			$start = $closing_start;
		}
		if ( $start === false ) {
			$output .= substr( $blocks, $offset );
			break;
		}
		$end = strpos( $blocks, '-->', $start );
		if ( $end === false ) {
			$output .= substr( $blocks, $offset );
			break;
		}

		$output .= substr( $blocks, $offset, $start - $offset );
		$offset = $end + 3;
	}

	return $output;
}

/**
 * Convert a Markdown string to block markup via php-toolkit's MarkdownConsumer.
 */
function edit_md_markdown_to_blocks( $markdown, $post_title = '' ) {
	$markdown = edit_md_strip_redundant_leading_h1( $markdown, $post_title );
	if ( ! edit_md_load_toolkit() ) {
		return '<!-- wp:html -->' . $markdown . '<!-- /wp:html -->';
	}
	$consumer = new \WordPress\Markdown\MarkdownConsumer( (string) $markdown );
	$result   = $consumer->consume();
	return $result->get_block_markup();
}

/**
 * Convert block markup back to Markdown via php-toolkit's MarkdownProducer.
 */
function edit_md_blocks_to_markdown( $blocks, $post_title = '', $restore_leading_h1 = false ) {
	if ( ! edit_md_load_toolkit() ) {
		$markdown = edit_md_strip_block_delimiters( $blocks );
		return $restore_leading_h1 ? edit_md_ensure_leading_h1( $markdown, $post_title ) : $markdown;
	}
	$bwm = new \WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata(
		(string) $blocks,
		array()
	);
	$producer = new \WordPress\Markdown\MarkdownProducer( $bwm );
	$markdown = $producer->produce();
	return $restore_leading_h1 ? edit_md_ensure_leading_h1( $markdown, $post_title ) : $markdown;
}

function edit_md_stored_post_had_redundant_leading_h1( $post_id, $next_title ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		return false;
	}

	global $wpdb;
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT post_title, post_content FROM {$wpdb->posts} WHERE ID = %d",
			$post_id
		)
	);
	if ( ! $row ) {
		return false;
	}
	return edit_md_has_redundant_leading_h1( $row->post_content, $row->post_title )
		|| edit_md_has_redundant_leading_h1( $row->post_content, $next_title );
}

/**
 * Replace the regular wp_posts / wp_postmeta tables with the virtual
 * ones backed by the markdown root.
 *
 * The sqlite-markdown PHP.wasm extension is loaded through the CLI's
 * phpExtension manifest before PHP starts. Its MINIT registers the SQLite
 * extension via sqlite3_auto_extension, so by the time this mu-plugin runs
 * the markdown_posts / markdown_postmeta modules are already known to the
 * SDI PDO handle; we just have to flip the persistent rowstore tables to
 * virtual ones for this connection.
 */
function edit_md_install_virtual_tables() {
	if ( ! empty( $GLOBALS['edit_md_sqlite_ready'] ) ) {
		return;
	}
	$pdo = isset( $GLOBALS['@pdo'] ) ? $GLOBALS['@pdo'] : null;
	if ( ! $pdo instanceof PDO ) {
		return;
	}

	global $table_prefix;
	$prefix = $table_prefix ?: 'wp_';
	$root   = EDIT_MD_ROOT;
	$quoted = "'" . str_replace( "'", "''", $root ) . "'";

	try {
		$pdo->exec( "DROP TABLE IF EXISTS {$prefix}posts" );
		$pdo->exec( "DROP TABLE IF EXISTS {$prefix}postmeta" );
		$pdo->exec(
			"CREATE VIRTUAL TABLE {$prefix}posts USING markdown_posts(root = {$quoted})"
		);
		$pdo->exec(
			"CREATE VIRTUAL TABLE {$prefix}postmeta USING markdown_postmeta(root = {$quoted})"
		);
		$GLOBALS['edit_md_sqlite_ready'] = true;
		delete_option( 'edit_md_last_error' );
	} catch ( Throwable $e ) {
		update_option(
			'edit_md_last_error',
			'CREATE VIRTUAL TABLE failed: ' . $e->getMessage() .
				' (did the sqlite-markdown PHP.wasm extension load?)'
		);
		error_log( '[edit-markdown] CREATE VIRTUAL TABLE failed: ' . $e->getMessage() );
	}
}

// SDI initializes the PDO during muplugins_loaded. Run our bootstrap right
// after, before WordPress core touches wp_posts.
add_action( 'plugins_loaded', 'edit_md_install_virtual_tables', 0 );
add_action( 'init', 'edit_md_install_virtual_tables', 0 );

/**
 * Convert the markdown stored on disk into block markup before WordPress
 * sees `post_content` for the editor.
 */
add_action( 'the_post', 'edit_md_decode_post_content_for_render', 0 );
function edit_md_decode_post_content_for_render( $post ) {
	if ( ! $post instanceof WP_Post ) {
		return;
	}
	if ( $post->post_type !== 'post' && $post->post_type !== 'page' ) {
		return;
	}
	if ( ! empty( $post->_edit_md_decoded ) ) {
		return;
	}
	if ( ! edit_md_looks_like_blocks( $post->post_content ) ) {
		$post->post_content = edit_md_markdown_to_blocks( $post->post_content, $post->post_title );
	}
	$post->_edit_md_decoded = 1;
}

/**
 * Convert raw Markdown loaded from the virtual table before front-end content
 * filters render it. Some template paths do not observe the mutated WP_Post
 * object from `the_post`, so keep the conversion at the content boundary too.
 */
add_filter( 'the_content', 'edit_md_decode_the_content_for_render', 0 );
function edit_md_decode_the_content_for_render( $content ) {
	if ( edit_md_looks_like_blocks( $content ) ) {
		return $content;
	}
	$post_title = isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof WP_Post
		? $GLOBALS['post']->post_title
		: '';
	return edit_md_markdown_to_blocks( $content, $post_title );
}

/**
 * Convert block markup back to Markdown right before WordPress writes the
 * row. The virtual table stores whatever string we hand it, so the disk
 * file ends up containing the Markdown the user expects to see.
 */
add_filter( 'wp_insert_post_data', 'edit_md_encode_post_content_for_storage', 0, 4 );
function edit_md_encode_post_content_for_storage( $data, $postarr = array(), $unsanitized_postarr = array(), $update = false ) {
	if ( empty( $data['post_content'] ) ) {
		return $data;
	}
	/* Only convert when the editor has sent real block markup. If the content
	 * is already plain Markdown (e.g. a programmatic insert with no block
	 * delimiters), leave it as-is so we don't double-encode. */
	if ( edit_md_looks_like_blocks( $data['post_content'] ) ) {
		$post_title = isset( $data['post_title'] ) ? $data['post_title'] : '';
		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$restore_leading_h1 = $update && edit_md_stored_post_had_redundant_leading_h1( $post_id, $post_title );
		$data['post_content'] = edit_md_blocks_to_markdown( $data['post_content'], $post_title, $restore_leading_h1 );
	}
	return $data;
}

/**
 * Convert the on-disk Markdown to block markup in REST API responses so that
 * the Gutenberg editor receives proper blocks (paragraphs, headings, etc.)
 * rather than raw Markdown wrapped in a single `wp:html` fence.
 *
 * Only fires for `context=edit` requests — the view context is handled by the
 * `the_content` filter through the normal template loop.
 */
add_filter( 'rest_prepare_page', 'edit_md_rest_prepare_response', 10, 3 );
add_filter( 'rest_prepare_post', 'edit_md_rest_prepare_response', 10, 3 );
function edit_md_rest_prepare_response( $response, $post, $request ) {
	if ( $request->get_param( 'context' ) !== 'edit' ) {
		return $response;
	}
	$data = $response->get_data();
	if ( isset( $data['content']['raw'] ) && ! edit_md_looks_like_blocks( $data['content']['raw'] ) ) {
		$data['content']['raw'] = edit_md_markdown_to_blocks( $data['content']['raw'], $post->post_title );
		$response->set_data( $data );
	}
	return $response;
}

/**
 * Default newly imported posts to the `page` post_type so the directory
 * hierarchy from `markdown_posts` shows up in wp-admin under Pages.
 */
add_filter( 'wp_insert_post_data', 'edit_md_default_post_type_to_page', 5 );
function edit_md_default_post_type_to_page( $data ) {
	if ( isset( $data['post_type'] ) && $data['post_type'] === 'post' ) {
		$data['post_type'] = 'page';
	}
	return $data;
}

add_action( 'admin_notices', 'edit_md_welcome_notice' );
function edit_md_welcome_notice() {
	if ( empty( $GLOBALS['edit_md_sqlite_ready'] ) ) {
		$err = get_option( 'edit_md_last_error', '' );
		echo '<div class="notice notice-error"><p><strong>edit-markdown:</strong> ' .
			'virtual tables are not active.</p>' .
			( $err ? '<pre>' . esc_html( $err ) . '</pre>' : '' ) .
			'</div>';
		return;
	}
	echo '<div class="notice notice-info"><p>Playground <strong>edit-markdown</strong> is reading and writing ' .
		'<code>' . esc_html( EDIT_MD_ROOT ) . '</code> through the sqlite-markdown virtual tables. ' .
		'<a href="' . esc_url( admin_url( 'edit.php?post_type=page' ) ) . '">Open Pages →</a></p></div>';
}
