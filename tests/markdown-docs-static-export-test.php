<?php
/**
 * End-to-end Markdown import to static export smoke test.
 *
 * @package WPExtensions
 */

use UniversalImporter\Import\ImportRunner;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\WordPressImportSessionStore;
use UniversalImporter\Tests\Unit\Import\FakeMediaGateway;
use UniversalImporter\Tests\Unit\Import\FakePostGateway;
use UniversalImporter\Tests\Unit\Import\FakeWpdb;

$repo_root  = dirname( __DIR__ );
$test_root  = rtrim( getenv( 'TMPDIR' ) ? getenv( 'TMPDIR' ) : sys_get_temp_dir(), '/\\' ) . '/wp-ext-markdown-static-' . getmypid() . '-' . mt_rand();
$wp_root    = $test_root . '/wordpress';
$docs_root  = $test_root . '/markdown-docs';
$export_dir = $test_root . '/static-export';

require_once $repo_root . '/universal-wordpress-importer/vendor/autoload.php';

if ( ! mkdir( $wp_root . '/wp-includes/blocks/navigation', 0777, true ) ) {
	md_static_fail( 'Could not create WordPress fixture tree.' );
}

if ( ! mkdir( $wp_root . '/wp-content/uploads', 0777, true ) ) {
	md_static_fail( 'Could not create uploads fixture tree.' );
}

if ( ! mkdir( $docs_root . '/reference', 0777, true ) || ! mkdir( $docs_root . '/guides', 0777, true ) || ! mkdir( $docs_root . '/assets', 0777, true ) ) {
	md_static_fail( 'Could not create Markdown docs fixture tree.' );
}

file_put_contents( $wp_root . '/wp-includes/blocks/navigation/style.min.css', '.wp-block-navigation{display:flex}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( $docs_root . '/assets/block-flow.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"><rect width="20" height="20" fill="#3858e9"/></svg>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( $docs_root . '/index.md', "---\ntitle: Gutenberg Handbook\n---\n\n# Gutenberg Handbook\n\n![Block flow](assets/block-flow.svg \"Block flow\")\n\nUse [Block API](reference/block-api.md#attributes), [Root API](/reference/block-api.md#supports), and [Nested Guide](guides/nested.mdown).\n\n| Scenario | Expected |\n| --- | --- |\n| Relative Markdown links | Imported page permalinks |\n| Static export | Separate HTML files |\n\n```js\nregisterBlockType('demo/example', {});\n```" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( $docs_root . '/reference/block-api.md', "# Block API\n\nThe attributes section should survive export.\n\nReturn to [Handbook](../index.md#overview)." ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( $docs_root . '/guides/nested.mdown', "# Nested Guide\n\nBack to [Handbook](../index.md) and [Block API](../reference/block-api.md)." ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

define( 'ABSPATH', $wp_root . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_CONTENT_DIR', $wp_root . '/wp-content' );
define( 'SSGWP_VERSION', '0.1.0' );
define( 'MB_IN_BYTES', 1024 * 1024 );

$md_static_home_url       = 'https://local.example.test/';
$md_static_posts          = array();
$md_static_http_responses = array();

md_static_define_wordpress_stubs();

require_once $repo_root . '/static-site-generator/includes/class-path-utils.php';
require_once $repo_root . '/static-site-generator/includes/class-url-collector.php';
require_once $repo_root . '/static-site-generator/includes/class-url-rewriter.php';
require_once $repo_root . '/static-site-generator/includes/class-static-exporter.php';

$wpdb    = new FakeWpdb();
$store   = new WordPressImportSessionStore( $wpdb );
$posts   = new FakePostGateway();
$media   = new FakeMediaGateway();
$session = ImportSession::start_for_source( $docs_root );
$store->save( $session );

for ( $tick = 0; $tick < 10; ++$tick ) {
	( new ImportRunner( $store, 'markdown-static-test', 60, null, $posts, $md_static_home_url, $media ) )->run( $session->get_id() );

	if ( ImportSession::STATUS_DONE === $store->find( $session->get_id() )->get_status() ) {
		break;
	}
}

md_static_assert_same( ImportSession::STATUS_DONE, $store->find( $session->get_id() )->get_status(), 'Markdown docs import should finish.' );
md_static_assert_same( 3, $posts->count_posts(), 'Markdown docs import should create three pages.' );
md_static_assert_same( 1, $media->count_attachments(), 'Markdown docs import should import the linked SVG asset.' );

for ( $attachment_id = 1; $attachment_id <= 200; ++$attachment_id ) {
	$attachment = $media->get_attachment( $attachment_id );

	if ( null === $attachment ) {
		continue;
	}

	copy( $attachment['resolved_source_uri'], WP_CONTENT_DIR . '/uploads/' . basename( $attachment['resolved_source_uri'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
}

for ( $post_id = 1; $post_id <= $posts->count_posts(); ++$post_id ) {
	$post = $posts->get_post( $post_id );

	if ( null === $post ) {
		continue;
	}

	$path = trim( parse_url( $posts->get_permalink( $post_id ), PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	$md_static_posts[ $post_id ] = (object) array(
		'ID'             => $post_id,
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'post_content'   => $post['post_content'],
		'permalink_path' => $path . '/',
		'post_title'     => $post['post_title'],
	);
	$md_static_http_responses[ $posts->get_permalink( $post_id ) ] = md_static_render_post( $post );
}

$md_static_http_responses[ home_url( '/' ) ] = '<html><head><title>Imported Docs</title></head><body><main><h1>Imported Docs Home</h1><a href="' . esc_attr( $posts->get_permalink( 1 ) ) . '">Gutenberg Handbook</a><a href="' . esc_attr( $posts->get_permalink( 2 ) ) . '">Block API</a><a href="' . esc_attr( $posts->get_permalink( 3 ) ) . '">Nested Guide</a></main></body></html>';

$exporter = new SSGWP_Static_Exporter();
$result   = $exporter->export_to_directory(
	$export_dir,
	array(
		'max_pages'         => 10,
		'copy_uploads'      => false,
		'copy_theme'        => false,
		'copy_plugins'      => false,
		'copy_core_assets'  => false,
		'crawl_links'       => true,
		'include_manifest'  => false,
		'url_mode'          => 'relative',
	)
);

md_static_assert_same( 4, count( $result['exported_urls'] ), 'Static exporter should export home and three imported docs pages.' );
md_static_assert_file_contains( $export_dir . '/index.html', 'Imported Docs Home', 'Static export should include the docs home.' );
md_static_assert_file_contains( $export_dir . '/imported/1/index.html', 'Gutenberg Handbook', 'Static export should include the imported Handbook page.' );
md_static_assert_file_contains( $export_dir . '/imported/2/index.html', 'Block API', 'Static export should include the imported Block API page.' );
md_static_assert_file_contains( $export_dir . '/imported/3/index.html', 'Nested Guide', 'Static export should include the imported nested guide page.' );
md_static_assert_file_not_contains( $export_dir . '/imported/1/index.html', 'Imported Docs Home', 'Imported docs pages should not contain homepage HTML.' );
md_static_assert_file_not_contains( $export_dir . '/imported/1/index.html', '.md', 'Imported docs page should not link to Markdown source paths.' );
md_static_assert_file_not_contains( $export_dir . '/imported/2/index.html', '../index.md', 'Reference page should not link to Markdown source paths.' );
md_static_assert_file_contains( $export_dir . '/wp-content/uploads/block-flow.svg', '<svg', 'Static export should copy the imported SVG asset.' );
md_static_assert_same( false, file_exists( $export_dir . '/static-export.json' ), 'Static export should not require a manifest file.' );

md_static_assert_local_references_resolve( $export_dir, $export_dir . '/index.html' );
md_static_assert_local_references_resolve( $export_dir, $export_dir . '/imported/1/index.html' );
md_static_assert_local_references_resolve( $export_dir, $export_dir . '/imported/2/index.html' );
md_static_assert_local_references_resolve( $export_dir, $export_dir . '/imported/3/index.html' );
md_static_remove_path( $test_root );

function md_static_define_wordpress_stubs() {
	if ( ! function_exists( 'wp_normalize_path' ) ) {
		function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
	}
	if ( ! function_exists( 'trailingslashit' ) ) {
		function trailingslashit( $value ) { return rtrim( (string) $value, "/\\" ) . '/'; }
	}
	if ( ! function_exists( 'untrailingslashit' ) ) {
		function untrailingslashit( $value ) { return rtrim( (string) $value, "/\\" ); }
	}
	if ( ! function_exists( 'wp_mkdir_p' ) ) {
		function wp_mkdir_p( $target ) { return is_dir( $target ) || mkdir( $target, 0777, true ); }
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( $url ) : parse_url( $url, $component ); }
	}
	if ( ! function_exists( 'home_url' ) ) {
		function home_url( $path = '' ) { global $md_static_home_url; return rtrim( $md_static_home_url, '/' ) . '/' . ltrim( $path, '/' ); }
	}
	if ( ! function_exists( 'site_url' ) ) {
		function site_url( $path = '' ) { return home_url( $path ); }
	}
	if ( ! function_exists( 'content_url' ) ) {
		function content_url( $path = '' ) { return home_url( 'wp-content/' . ltrim( $path, '/' ) ); }
	}
	if ( ! function_exists( 'includes_url' ) ) {
		function includes_url( $path = '' ) { return home_url( 'wp-includes/' . ltrim( $path, '/' ) ); }
	}
	if ( ! function_exists( 'get_bloginfo' ) ) {
		function get_bloginfo( $show = '' ) { return 'version' === $show ? '6.9.4' : ''; }
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) {
			$options = array( 'page_for_posts' => 0, 'permalink_structure' => '/%postname%/', 'posts_per_page' => 10, 'show_on_front' => 'posts' );
			return isset( $options[ $name ] ) ? $options[ $name ] : $default;
		}
	}
	if ( ! function_exists( 'get_post_types' ) ) {
		function get_post_types() {
			return array(
				'post'       => (object) array( 'exclude_from_search' => false, 'has_archive' => false ),
				'page'       => (object) array( 'exclude_from_search' => false, 'has_archive' => false ),
				'attachment' => (object) array( 'exclude_from_search' => false, 'has_archive' => false ),
			);
		}
	}
	if ( ! function_exists( 'get_post_type_archive_link' ) ) {
		function get_post_type_archive_link( $post_type ) { return home_url( $post_type . '/' ); }
	}
	if ( ! function_exists( 'wp_count_posts' ) ) {
		function wp_count_posts( $post_type ) {
			global $md_static_posts;
			$count = 0;
			foreach ( $md_static_posts as $post ) {
				if ( $post_type === $post->post_type && 'publish' === $post->post_status ) { ++$count; }
			}
			return (object) array( 'publish' => $count );
		}
	}
	if ( ! function_exists( 'get_permalink' ) ) {
		function get_permalink( $post ) {
			$post_id = is_object( $post ) ? $post->ID : (int) $post;
			$post    = get_post( $post_id );
			return null === $post ? home_url( 'post-' . $post_id . '/' ) : home_url( $post->permalink_path );
		}
	}
	if ( ! function_exists( 'get_post' ) ) {
		function get_post( $post_id ) { global $md_static_posts; $post_id = is_object( $post_id ) ? $post_id->ID : (int) $post_id; return isset( $md_static_posts[ $post_id ] ) ? $md_static_posts[ $post_id ] : null; }
	}
	if ( ! function_exists( 'get_taxonomies' ) ) {
		function get_taxonomies() { return array(); }
	}
	if ( ! function_exists( 'get_terms' ) ) {
		function get_terms() { return array(); }
	}
	if ( ! function_exists( 'get_term_link' ) ) {
		function get_term_link( $term ) { return home_url( 'term-' . $term->term_id . '/' ); }
	}
	if ( ! function_exists( 'get_users' ) ) {
		function get_users() { return array(); }
	}
	if ( ! function_exists( 'get_author_posts_url' ) ) {
		function get_author_posts_url( $user_id ) { return home_url( 'author/user-' . (int) $user_id . '/' ); }
	}
	if ( ! function_exists( 'count_user_posts' ) ) {
		function count_user_posts() { return 0; }
	}
	if ( ! function_exists( 'add_query_arg' ) ) {
		function add_query_arg( $key, $value, $url ) { $separator = false === strpos( $url, '?' ) ? '?' : '&'; return $url . $separator . rawurlencode( $key ) . '=' . rawurlencode( (string) $value ); }
	}
	if ( ! function_exists( 'remove_query_arg' ) ) {
		function remove_query_arg( $keys, $url ) {
			$parts = wp_parse_url( $url );
			if ( empty( $parts['query'] ) ) { return $url; }
			parse_str( $parts['query'], $query_args );
			foreach ( (array) $keys as $key ) { unset( $query_args[ $key ] ); }
			$query = http_build_query( $query_args, '', '&', PHP_QUERY_RFC3986 );
			$base  = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' ) . ( isset( $parts['host'] ) ? $parts['host'] : '' ) . ( isset( $parts['path'] ) ? $parts['path'] : '' );
			return $base . ( '' === $query ? '' : '?' . $query );
		}
	}
	if ( ! function_exists( 'wp_parse_args' ) ) {
		function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
	}
	if ( ! function_exists( 'wp_parse_str' ) ) {
		function wp_parse_str( $string, &$array ) { parse_str( $string, $array ); }
	}
	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $value ) { return $value instanceof WP_Error; }
	}
	if ( ! function_exists( 'wp_remote_get' ) ) {
		function wp_remote_get( $url, $args = array() ) {
			global $md_static_http_responses;
			unset( $args );
			$canonical_url = remove_query_arg( 'ssgwp_export', $url );
			if ( isset( $md_static_http_responses[ $canonical_url ] ) ) {
				return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'text/html; charset=UTF-8' ), 'body' => $md_static_http_responses[ $canonical_url ] );
			}
			return new WP_Error( 'not_found', 'No fixture response for ' . $canonical_url );
		}
	}
	if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
		function wp_remote_retrieve_response_code( $response ) { return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0; }
	}
	if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
		function wp_remote_retrieve_header( $response, $name ) { $key = strtolower( $name ); return isset( $response['headers'][ $key ] ) ? $response['headers'][ $key ] : ''; }
	}
	if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
		function wp_remote_retrieve_body( $response ) { return isset( $response['body'] ) ? (string) $response['body'] : ''; }
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, $options = 0 ) { return json_encode( $data, $options ); }
	}
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	}
	if ( ! function_exists( 'esc_url' ) ) {
		function esc_url( $value ) { return esc_attr( $value ); }
	}
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private $code;
			private $message;
			public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
			public function get_error_code() { return $this->code; }
			public function get_error_message() { return $this->message; }
		}
	}
	if ( ! class_exists( 'WP_Query' ) ) {
		class WP_Query {
			public $posts = array();
			public function __construct( array $args ) {
				global $md_static_posts;
				$post_type = isset( $args['post_type'] ) ? $args['post_type'] : 'post';
				$per_page  = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 10;
				$page      = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;
				$ids       = array();
				foreach ( $md_static_posts as $post ) {
					if ( $post_type === $post->post_type && 'publish' === $post->post_status ) { $ids[] = $post->ID; }
				}
				$this->posts = array_slice( $ids, ( $page - 1 ) * $per_page, $per_page );
			}
		}
	}
}

function md_static_render_post( array $post ) {
	return '<html><head><title>' . esc_attr( $post['post_title'] ) . '</title></head><body><main><article><h1>' . esc_attr( $post['post_title'] ) . '</h1>' . $post['post_content'] . '</article></main></body></html>';
}

function md_static_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) { md_static_fail( $message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . '.' ); }
}

function md_static_assert_file_contains( $path, $needle, $message ) {
	if ( ! is_file( $path ) ) { md_static_fail( $message . ' Missing file ' . $path . '.' ); }
	$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false === $content || false === strpos( $content, $needle ) ) { md_static_fail( $message . ' Missing ' . var_export( $needle, true ) . ' in ' . $path . '.' ); }
}

function md_static_assert_file_not_contains( $path, $needle, $message ) {
	if ( ! is_file( $path ) ) { md_static_fail( $message . ' Missing file ' . $path . '.' ); }
	$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false !== $content && false !== strpos( $content, $needle ) ) { md_static_fail( $message . ' Unexpected ' . var_export( $needle, true ) . ' in ' . $path . '.' ); }
}

function md_static_assert_local_references_resolve( $export_dir, $html_file ) {
	$html = file_get_contents( $html_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false === $html ) { md_static_fail( 'Could not read exported HTML file ' . $html_file . '.' ); }
	preg_match_all( '/\b(?:href|src)\s*=\s*(["\'])(.*?)\1/i', $html, $matches, PREG_SET_ORDER );
	foreach ( $matches as $match ) {
		$reference = html_entity_decode( $match[2], ENT_QUOTES, 'UTF-8' );
		if ( '' === $reference || '#' === $reference[0] || preg_match( '/^[a-z][a-z0-9+.-]*:/i', $reference ) || 0 === strpos( $reference, '//' ) ) { continue; }
		$path = preg_replace( '/[?#].*$/', '', $reference );
		if ( ! is_string( $path ) || '' === $path ) { continue; }
		$target = '/' === $path[0] ? rtrim( $export_dir, '/\\' ) . $path : dirname( $html_file ) . '/' . $path;
		$target = md_static_normalize_path( $target );
		if ( '/' === substr( $target, -1 ) || is_dir( $target ) ) { $target = rtrim( $target, '/\\' ) . '/index.html'; }
		if ( ! is_file( $target ) ) { md_static_fail( 'Exported reference ' . $reference . ' in ' . $html_file . ' does not resolve to a file.' ); }
	}
}

function md_static_normalize_path( $path ) {
	$parts = array();
	foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $part ) {
		if ( '' === $part || '.' === $part ) { continue; }
		if ( '..' === $part ) { array_pop( $parts ); continue; }
		$parts[] = $part;
	}
	return '/' . implode( '/', $parts );
}

function md_static_remove_path( $path ) {
	if ( ! file_exists( $path ) && ! is_link( $path ) ) { return; }
	if ( is_file( $path ) || is_link( $path ) ) { unlink( $path ); return; }
	foreach ( scandir( $path ) as $entry ) {
		if ( '.' === $entry || '..' === $entry ) { continue; }
		md_static_remove_path( rtrim( $path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $entry );
	}
	rmdir( $path );
}

function md_static_fail( $message ) {
	fwrite( STDERR, $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}
