<?php
/**
 * Tests for SSGWP_Static_Exporter internals.
 *
 * @package PlaygroundStaticSiteGenerator
 */

$fixture_root = sys_get_temp_dir() . '/ssgwp-static-exporter-' . getmypid() . '-' . mt_rand();
$navigation_dir = $fixture_root . '/wp-includes/blocks/navigation';

if ( ! mkdir( $navigation_dir, 0777, true ) ) {
	ssgwp_fail( 'Could not create fixture directory.' );
}

file_put_contents(
	$navigation_dir . '/style.min.css',
	'.wp-block-navigation{display:flex}'
); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

define( 'ABSPATH', $fixture_root . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_CONTENT_DIR', $fixture_root . '/wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );
define( 'SSGWP_VERSION', '0.1.0' );
define( 'MB_IN_BYTES', 1024 * 1024 );

$ssgwp_test_home_url = 'https://example.test/';
$ssgwp_test_site_url = 'https://example.test/';
$ssgwp_test_posts = array();
$ssgwp_test_http_responses = array();
$ssgwp_test_active_plugins = array(
	'static-site-generator/static-site-generator.php',
);
$ssgwp_test_network_active_plugins = array();
$ssgwp_test_theme = array(
	'stylesheet' => 'twentytwentysix',
	'template'   => 'twentytwentysix',
	'name'       => 'Twenty Twenty-Six',
	'version'    => '1.0',
);
$ssgwp_test_export_wp_calls = 0;

if ( ! function_exists( 'export_wp' ) ) {
	/**
	 * Fake WordPress core export used to prove HTTP-like source-state exports
	 * avoid WXR download header side effects.
	 *
	 * @param array $args Export arguments.
	 */
	function export_wp( $args = array() ) {
		global $ssgwp_test_export_wp_calls;

		++$ssgwp_test_export_wp_calls;
		header( 'Content-Type: application/rss+xml; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="wordpress-export.xml"' );

		echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		echo "<rss version=\"2.0\"><channel><title>Core Export</title></channel></rss>\n";
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	/**
	 * Normalize paths for tests.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function wp_normalize_path( $path ) {
		return str_replace( '\\', '/', (string) $path );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Add a trailing slash.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function trailingslashit( $value ) {
		return rtrim( (string) $value, "/\\" ) . '/';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Remove trailing slashes.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, "/\\" );
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	/**
	 * Create a directory recursively.
	 *
	 * @param string $target Directory path.
	 * @return bool Whether the directory exists.
	 */
	function wp_mkdir_p( $target ) {
		return is_dir( $target ) || mkdir( $target, 0777, true );
	}
}

if ( ! function_exists( 'get_temp_dir' ) ) {
	/**
	 * Return a temporary directory path for tests.
	 *
	 * @return string Temp directory.
	 */
	function get_temp_dir() {
		return sys_get_temp_dir() . '/';
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	/**
	 * Return a unique test identifier.
	 *
	 * @return string UUID-like value.
	 */
	function wp_generate_uuid4() {
		return uniqid( 'ssgwp-test-', true );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Parse a URL for tests.
	 *
	 * @param string $url       URL.
	 * @param int    $component URL component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}

if ( ! function_exists( 'includes_url' ) ) {
	/**
	 * Return an includes URL for tests.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function includes_url( $path = '' ) {
		return 'https://example.test/wp-includes/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * Return test site metadata.
	 *
	 * @param string $show Metadata key.
	 * @return string
	 */
	function get_bloginfo( $show = '' ) {
		return 'version' === $show ? '6.9.4' : '';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Return a test home URL.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( $path = '' ) {
		global $ssgwp_test_home_url;

		return rtrim( $ssgwp_test_home_url, '/' ) . '/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'site_url' ) ) {
	/**
	 * Return a test site URL.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function site_url( $path = '' ) {
		global $ssgwp_test_site_url;

		return rtrim( $ssgwp_test_site_url, '/' ) . '/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'content_url' ) ) {
	/**
	 * Return a test content URL.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function content_url( $path = '' ) {
		return 'https://example.test/wp-content/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_get_upload_dir' ) ) {
	/**
	 * Return a test upload directory.
	 *
	 * @return array<string,string>
	 */
	function wp_get_upload_dir() {
		return array(
			'basedir' => WP_CONTENT_DIR . '/uploads',
			'baseurl' => content_url( 'uploads' ),
		);
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Add a query argument to a URL for tests.
	 *
	 * @param string $key   Query key.
	 * @param string $value Query value.
	 * @param string $url   URL.
	 * @return string
	 */
	function add_query_arg( $key, $value, $url ) {
		$separator = false === strpos( $url, '?' ) ? '?' : '&';

		return $url . $separator . rawurlencode( $key ) . '=' . rawurlencode( $value );
	}
}

if ( ! function_exists( 'remove_query_arg' ) ) {
	/**
	 * Remove query arguments from a URL for tests.
	 *
	 * @param string|string[] $keys Query key or keys.
	 * @param string          $url  URL.
	 * @return string URL without the query keys.
	 */
	function remove_query_arg( $keys, $url ) {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['query'] ) ) {
			return $url;
		}

		parse_str( $parts['query'], $query_args );

		foreach ( (array) $keys as $key ) {
			unset( $query_args[ $key ] );
		}

		$query = http_build_query( $query_args, '', '&', PHP_QUERY_RFC3986 );
		$base  = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' )
			. ( isset( $parts['host'] ) ? $parts['host'] : '' )
			. ( isset( $parts['path'] ) ? $parts['path'] : '' );

		return $base . ( '' !== $query ? '?' . $query : '' );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * Merge user arguments with defaults for tests.
	 *
	 * @param array $args     User arguments.
	 * @param array $defaults Default arguments.
	 * @return array Merged arguments.
	 */
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, (array) $args );
	}
}

if ( ! function_exists( 'wp_parse_str' ) ) {
	/**
	 * Parse a query string for tests.
	 *
	 * @param string $string Query string.
	 * @param array  $array  Parsed output.
	 */
	function wp_parse_str( $string, &$array ) {
		parse_str( $string, $array );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Check whether a value is a WP_Error for tests.
	 *
	 * @param mixed $value Value.
	 * @return bool Whether the value is a WP_Error.
	 */
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Return simple option values for tests.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Default value.
	 * @return mixed Option value.
	 */
	function get_option( $name, $default = false ) {
		global $ssgwp_test_active_plugins;

		if ( 'permalink_structure' === $name ) {
			return '/%postname%/';
		}

		if ( 'posts_per_page' === $name ) {
			return 10;
		}

		if ( 'active_plugins' === $name ) {
			return $ssgwp_test_active_plugins;
		}

		return $default;
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	/**
	 * Return simple network option values for tests.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Default value.
	 * @return mixed Option value.
	 */
	function get_site_option( $name, $default = false ) {
		global $ssgwp_test_network_active_plugins;

		if ( 'active_sitewide_plugins' === $name ) {
			return $ssgwp_test_network_active_plugins;
		}

		return $default;
	}
}

if ( ! function_exists( 'get_stylesheet' ) ) {
	/**
	 * Return the active stylesheet slug for tests.
	 *
	 * @return string Stylesheet slug.
	 */
	function get_stylesheet() {
		global $ssgwp_test_theme;

		return $ssgwp_test_theme['stylesheet'];
	}
}

if ( ! function_exists( 'get_template' ) ) {
	/**
	 * Return the active template slug for tests.
	 *
	 * @return string Template slug.
	 */
	function get_template() {
		global $ssgwp_test_theme;

		return $ssgwp_test_theme['template'];
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	/**
	 * Return active theme metadata for tests.
	 *
	 * @return object Theme test double.
	 */
	function wp_get_theme() {
		global $ssgwp_test_theme;

		return new class( $ssgwp_test_theme ) {
			/**
			 * Theme metadata.
			 *
			 * @var array<string,string>
			 */
			private $theme;

			/**
			 * Constructor.
			 *
			 * @param array<string,string> $theme Theme metadata.
			 */
			public function __construct( array $theme ) {
				$this->theme = $theme;
			}

			/**
			 * Return one theme header.
			 *
			 * @param string $header Header name.
			 * @return string Header value.
			 */
			public function get( $header ) {
				if ( 'Name' === $header ) {
					return $this->theme['name'];
				}

				if ( 'Version' === $header ) {
					return $this->theme['version'];
				}

				return '';
			}
		};
	}
}

if ( ! function_exists( 'get_template_directory' ) ) {
	/**
	 * Return the active template theme directory for tests.
	 *
	 * @return string Theme directory.
	 */
	function get_template_directory() {
		return WP_CONTENT_DIR . '/themes/' . get_template();
	}
}

if ( ! function_exists( 'get_stylesheet_directory' ) ) {
	/**
	 * Return the active stylesheet theme directory for tests.
	 *
	 * @return string Theme directory.
	 */
	function get_stylesheet_directory() {
		return WP_CONTENT_DIR . '/themes/' . get_stylesheet();
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Return a test post.
	 *
	 * @param int $post_id Post ID.
	 * @return object|null Test post, or null.
	 */
	function get_post( $post_id ) {
		global $ssgwp_test_posts;

		$post_id = is_object( $post_id ) ? $post_id->ID : (int) $post_id;

		return isset( $ssgwp_test_posts[ $post_id ] ) ? $ssgwp_test_posts[ $post_id ] : null;
	}
}

if ( ! function_exists( 'get_post_types' ) ) {
	/**
	 * Return public post types for export tests.
	 *
	 * @return array
	 */
	function get_post_types() {
		return array(
			'post'       => (object) array(
				'exclude_from_search' => false,
				'has_archive'         => false,
			),
			'page'       => (object) array(
				'exclude_from_search' => false,
				'has_archive'         => false,
			),
			'attachment' => (object) array(
				'exclude_from_search' => false,
				'has_archive'         => false,
			),
		);
	}
}

if ( ! function_exists( 'get_post_type_archive_link' ) ) {
	/**
	 * Return a post type archive URL.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	function get_post_type_archive_link( $post_type ) {
		return home_url( $post_type . '/' );
	}
}

if ( ! function_exists( 'wp_count_posts' ) ) {
	/**
	 * Count test posts.
	 *
	 * @param string $post_type Post type.
	 * @return object
	 */
	function wp_count_posts( $post_type ) {
		global $ssgwp_test_posts;

		$count = 0;

		foreach ( $ssgwp_test_posts as $post ) {
			if ( $post_type === $post->post_type && 'publish' === $post->post_status ) {
				++$count;
			}
		}

		return (object) array( 'publish' => $count );
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * Return a test permalink.
	 *
	 * @param int|object $post Post ID or object.
	 * @return string
	 */
	function get_permalink( $post ) {
		$post_id = is_object( $post ) ? $post->ID : (int) $post;
		$post    = get_post( $post_id );

		if ( null !== $post && ! empty( $post->permalink_path ) ) {
			return home_url( $post->permalink_path );
		}

		return home_url( 'post-' . $post_id . '/' );
	}
}

if ( ! function_exists( 'get_taxonomies' ) ) {
	/**
	 * Return no taxonomies by default.
	 *
	 * @return array
	 */
	function get_taxonomies() {
		return array();
	}
}

if ( ! function_exists( 'get_terms' ) ) {
	/**
	 * Return no terms by default.
	 *
	 * @return array
	 */
	function get_terms() {
		return array();
	}
}

if ( ! function_exists( 'get_term_link' ) ) {
	/**
	 * Return a test term link.
	 *
	 * @param object $term Term object.
	 * @return string
	 */
	function get_term_link( $term ) {
		return home_url( 'term-' . $term->term_id . '/' );
	}
}

if ( ! function_exists( 'get_users' ) ) {
	/**
	 * Return no users by default.
	 *
	 * @return array
	 */
	function get_users() {
		return array();
	}
}

if ( ! function_exists( 'get_author_posts_url' ) ) {
	/**
	 * Return a test author URL.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	function get_author_posts_url( $user_id ) {
		return home_url( 'author/user-' . (int) $user_id . '/' );
	}
}

if ( ! function_exists( 'count_user_posts' ) ) {
	/**
	 * Return no author posts by default.
	 *
	 * @return int
	 */
	function count_user_posts() {
		return 0;
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Minimal WP_Query test double.
	 */
	class WP_Query {
		/**
		 * Queried post IDs.
		 *
		 * @var int[]
		 */
		public $posts = array();

		/**
		 * Constructor.
		 *
		 * @param array $args Query arguments.
		 */
		public function __construct( array $args ) {
			global $ssgwp_test_posts;

			$post_type = isset( $args['post_type'] ) ? $args['post_type'] : 'post';
			$per_page  = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 10;
			$page      = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;
			$ids       = array();

			foreach ( $ssgwp_test_posts as $post ) {
				if ( $post_type === $post->post_type && 'publish' === $post->post_status ) {
					$ids[] = $post->ID;
				}
			}

			$this->posts = array_slice( $ids, ( $page - 1 ) * $per_page, $per_page );
		}
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * Return a successful HTML response for tests.
	 *
	 * @param string $url  URL.
	 * @param array  $args Request args.
	 * @return array Response.
	 */
	function wp_remote_get( $url, $args = array() ) {
		global $ssgwp_test_http_responses;

		if ( isset( $ssgwp_test_http_responses[ $url ] ) ) {
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
				'body'     => $ssgwp_test_http_responses[ $url ],
			);
		}

		$canonical_url = remove_query_arg( 'ssgwp_export', $url );

		if ( isset( $ssgwp_test_http_responses[ $canonical_url ] ) ) {
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
				'body'     => $ssgwp_test_http_responses[ $canonical_url ],
			);
		}

		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
			'body'     => '<html><head><title>Export</title></head><body>Exported</body></html>',
		);
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Retrieve a response status code for tests.
	 *
	 * @param array $response Response.
	 * @return int Status code.
	 */
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	/**
	 * Retrieve a response header for tests.
	 *
	 * @param array  $response Response.
	 * @param string $name     Header name.
	 * @return string Header value.
	 */
	function wp_remote_retrieve_header( $response, $name ) {
		$key = strtolower( $name );

		return isset( $response['headers'][ $key ] ) ? $response['headers'][ $key ] : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Retrieve a response body for tests.
	 *
	 * @param array $response Response.
	 * @return string Body.
	 */
	function wp_remote_retrieve_body( $response ) {
		return isset( $response['body'] ) ? $response['body'] : '';
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Encode JSON for tests.
	 *
	 * @param mixed $data    Data.
	 * @param int   $options JSON options.
	 * @return string JSON.
	 */
	function wp_json_encode( $data, $options = 0 ) {
		return json_encode( $data, $options );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Escape a URL for tests.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Escape an HTML attribute for tests.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function esc_attr( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error test double.
	 */
	class WP_Error {
		/**
		 * Error code.
		 *
		 * @var string
		 */
		private $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		private $message;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( $code, $message ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * Get the error code.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * Get the error message.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-path-utils.php';
require_once dirname( __DIR__ ) . '/includes/class-url-collector.php';
require_once dirname( __DIR__ ) . '/includes/class-url-rewriter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-exporter.php';

$exporter = new SSGWP_Static_Exporter();

$effective_port_method = new ReflectionMethod( $exporter, 'effective_url_port' );
$effective_port_method->setAccessible( true );

ssgwp_assert_same(
	443,
	$effective_port_method->invoke( $exporter, array( 'scheme' => 'https' ) ),
	'effective_url_port returns the HTTPS default port.'
);

ssgwp_assert_same(
	8443,
	$effective_port_method->invoke(
		$exporter,
		array(
			'scheme' => 'https',
			'port'   => 8443,
		)
	),
	'effective_url_port preserves explicit custom ports.'
);

$render_method = new ReflectionMethod( $exporter, 'render_url_in_process' );
$render_method->setAccessible( true );

$render_error = $render_method->invoke( $exporter, 'http://example.test:443/static-page/' );

ssgwp_assert_same(
	'ssgwp_not_same_site_scheme',
	$render_error->get_error_code(),
	'render_url_in_process rejects a different scheme before rendering.'
);

$method = new ReflectionMethod( $exporter, 'inject_missing_core_block_styles' );
$method->setAccessible( true );

$html = '<html><head><title>Test</title><style>.wp-block-audio{display:block}</style></head><body><nav class="wp-block-navigation wp-block-navigation-is-layout-flex"></nav></body></html>';
$html = $method->invoke( $exporter, $html );

ssgwp_assert_contains(
	'<link rel="stylesheet" id="wp-block-navigation-css" href="https://example.test/wp-includes/blocks/navigation/style.min.css?ver=6.9.4" media="all" />',
	$html,
	'inject_missing_core_block_styles injects the Navigation block stylesheet.'
);

ssgwp_assert_not_contains(
	'wp-block-navigation-is-layout-flex-css',
	$html,
	'inject_missing_core_block_styles ignores layout helper classes.'
);

ssgwp_assert_not_contains(
	'wp-block-audio-css',
	$html,
	'inject_missing_core_block_styles ignores block classes inside style tags.'
);

$charset_method = new ReflectionMethod( $exporter, 'ensure_html_charset' );
$charset_method->setAccessible( true );

ssgwp_assert_contains(
	'<meta charset="UTF-8" />',
	$charset_method->invoke( $exporter, '<html><head><title>Cart</title></head><body>You may be interested in…</body></html>' ),
	'ensure_html_charset adds UTF-8 metadata for file previews with non-ASCII text.'
);

ssgwp_assert_same(
	'<html><head><meta charset="UTF-8"><title>Cart</title></head><body>Cart</body></html>',
	$charset_method->invoke( $exporter, '<html><head><meta charset="UTF-8"><title>Cart</title></head><body>Cart</body></html>' ),
	'ensure_html_charset preserves existing charset metadata.'
);

$url_to_file_path_method = new ReflectionMethod( $exporter, 'url_to_file_path' );
$url_to_file_path_method->setAccessible( true );

ssgwp_assert_same(
	'collision%20page/index.html',
	$url_to_file_path_method->invoke( $exporter, 'https://example.test/collision%20page/' ),
	'url_to_file_path keeps encoded spaces distinct from other sanitized paths.'
);

ssgwp_assert_same(
	'collision%2Bpage/index.html',
	$url_to_file_path_method->invoke( $exporter, 'https://example.test/collision+page/' ),
	'url_to_file_path keeps literal plus signs distinct from encoded spaces.'
);

ssgwp_assert_same(
	'nested%2Fsegment/index.html',
	$url_to_file_path_method->invoke( $exporter, 'https://example.test/nested%2Fsegment/' ),
	'url_to_file_path keeps encoded slashes inside one exported path segment.'
);

ssgwp_assert_same(
	'%2E%2E/secret/index.html',
	$url_to_file_path_method->invoke( $exporter, 'https://example.test/%2e%2e/secret/' ),
	'url_to_file_path keeps encoded parent segments literal.'
);

ssgwp_assert_same(
	'nested/segment/index.html',
	$url_to_file_path_method->invoke( $exporter, 'https://example.test/nested/segment/' ),
	'url_to_file_path maps decoded slashes to nested exported directories.'
);

ssgwp_assert_same(
	true,
	$url_to_file_path_method->invoke( $exporter, 'https://example.test/nested%2Fsegment/' )
		!== $url_to_file_path_method->invoke( $exporter, 'https://example.test/nested/segment/' ),
	'url_to_file_path avoids encoded-slash normalization collisions.'
);

$view_hash = substr( md5( 'view=grid' ), 0, 8 );

ssgwp_assert_same(
	'collision%20page-' . $view_hash . '.html',
	$url_to_file_path_method->invoke( $exporter, 'https://example.test/collision%20page/?view=grid' ),
	'url_to_file_path keeps encoded paths distinct when adding query hashes.'
);

$ssgwp_test_home_url = 'https://playground.wordpress.net/scope:sad-quiet-school/';
$ssgwp_test_site_url = 'https://playground.wordpress.net/scope:sad-quiet-school/';

ssgwp_assert_same(
	'ssgwp_not_deployment_base',
	$render_method->invoke( $exporter, 'https://playground.wordpress.net/scope:other-site/static-page/' )->get_error_code(),
	'render_url_in_process rejects same-host URLs from a different Playground scope.'
);

ssgwp_assert_same(
	'sample-page/index.html',
	$url_to_file_path_method->invoke( $exporter, 'https://playground.wordpress.net/scope:sad-quiet-school/sample-page/' ),
	'url_to_file_path strips the Playground scope base from exported page paths.'
);

ssgwp_assert_same(
	'scope%3Asad-quiet-school/sample-page/index.html',
	$url_to_file_path_method->invoke( $exporter, 'https://playground.wordpress.net/scope:sad-quiet-school/scope:sad-quiet-school/sample-page/' ),
	'url_to_file_path does not duplicate-strip a repeated Playground scope segment.'
);

$ssgwp_test_home_url = 'https://example.test/';
$ssgwp_test_site_url = 'https://example.test/';

$html_with_link = $method->invoke(
	$exporter,
	'<html><head><link rel="stylesheet" id="wp-block-navigation-css" href="/already-loaded.css" /></head><body><nav class="wp-block-navigation"></nav></body></html>'
);

ssgwp_assert_same(
	1,
	substr_count( $html_with_link, 'wp-block-navigation-css' ),
	'inject_missing_core_block_styles does not duplicate existing core block styles.'
);

$progress_property = new ReflectionProperty( $exporter, 'progress_callback' );
$progress_property->setAccessible( true );

$events = array();
$progress_property->setValue(
	$exporter,
	static function ( $event ) use ( &$events ) {
		$events[] = $event;
	}
);

$progress_method = new ReflectionMethod( $exporter, 'report_progress' );
$progress_method->setAccessible( true );
$progress_method->invoke(
	$exporter,
	'render_page',
	'Rendering https://example.test/.',
	array(
		'url'            => 'https://example.test/',
		'queue_position' => 1,
		'queue_total'    => 3,
	)
);

ssgwp_assert_same(
	'render_page',
	$events[0]['stage'],
	'report_progress calls the configured callback with the stage.'
);

ssgwp_assert_same(
	3,
	$events[0]['context']['queue_total'],
	'report_progress preserves structured context.'
);

$bounded_output_dir = $fixture_root . '/bounded-export';
$bounded_events     = array();

set_error_handler(
	static function ( $severity, $message ) {
		ssgwp_fail( 'export_to_directory emitted a warning: ' . $message );
	}
);

$bounded_result = $exporter->export_to_directory(
	$bounded_output_dir,
	array(
		'max_pages'         => 1,
		'copy_uploads'      => false,
		'copy_theme'        => false,
		'copy_plugins'      => false,
		'copy_core_assets'  => false,
		'include_manifest'  => false,
		'progress_callback' => static function ( $event ) use ( &$bounded_events ) {
			$bounded_events[] = $event;
		},
	)
);

restore_error_handler();

ssgwp_assert_same(
	array( 'https://example.test/' ),
	$bounded_result['exported_urls'],
	'export_to_directory exports the bounded initial queue without warnings.'
);

ssgwp_assert_same(
	true,
	file_exists( $bounded_output_dir . '/index.html' ),
	'export_to_directory writes the bounded home page export.'
);

ssgwp_assert_same(
	false,
	file_exists( $bounded_output_dir . '/static-export.json' ),
	'export_to_directory skips static-export.json when manifest output is disabled.'
);

ssgwp_assert_same(
	true,
	file_exists( $bounded_output_dir . '/_static-export-preview.txt' ),
	'export_to_directory writes local preview guidance even when the technical manifest is disabled.'
);

ssgwp_assert_same(
	false,
	file_exists( $bounded_output_dir . '/_playground-source' ),
	'export_to_directory does not write Playground source-state artifacts by default.'
);

$preview_note = file_get_contents( $bounded_output_dir . '/_static-export-preview.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

ssgwp_assert_contains(
	'file://',
	$preview_note,
	'Static preview guidance explains file protocol limits.'
);

ssgwp_assert_contains(
	'python3 -m http.server 8080',
	$preview_note,
	'Static preview guidance gives a local HTTP server command.'
);

ssgwp_assert_contains(
	'Forms, search, comments, carts, checkout, account pages, and REST API writes need a live backend',
	$preview_note,
	'Static preview guidance explains dynamic WordPress limitations.'
);

ssgwp_assert_contains(
	'_playground-source/',
	$preview_note,
	'Static preview guidance warns about owner-only Playground source-state artifacts.'
);

ssgwp_assert_contains(
	'_cloudflare-publish/',
	$preview_note,
	'Static preview guidance warns about Cloudflare operational artifacts.'
);

ssgwp_assert_contains(
	'Generic static hosting should not blindly upload or serve `_playground-source/` or `_cloudflare-publish/`',
	$preview_note,
	'Static preview guidance tells generic hosts not to blindly publish operational directories.'
);

ssgwp_assert_same(
	'discovered',
	$bounded_events[0]['stage'],
	'export_to_directory reports initial URL discovery before rendering.'
);

ssgwp_assert_same(
	1,
	$bounded_events[0]['context']['max_pages'],
	'export_to_directory passes max_pages into initial URL discovery.'
);

ssgwp_assert_same(
	1,
	$bounded_events[0]['context']['queue_total'],
	'export_to_directory bounds the initial URL queue by max_pages.'
);

$source_state_output_dir = $fixture_root . '/source-state-export';
$previous_request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;
$_SERVER['REQUEST_METHOD'] = 'GET';
$ssgwp_test_export_wp_calls = 0;
$ssgwp_test_posts = array(
	31 => (object) array(
		'ID'                => 31,
		'post_type'         => 'post',
		'post_status'       => 'publish',
		'post_title'        => 'Coffee & Docs',
		'post_name'         => 'coffee-docs',
		'post_content'      => '<!-- wp:paragraph --><p>Source content & restore metadata.</p><!-- /wp:paragraph -->',
		'post_excerpt'      => 'Source excerpt',
		'post_date'         => '2026-06-01 10:00:00',
		'post_date_gmt'     => '2026-06-01 10:00:00',
		'post_modified'     => '2026-06-02 11:00:00',
		'post_modified_gmt' => '2026-06-02 11:00:00',
		'post_author'       => 1,
		'permalink_path'    => 'coffee-docs/',
	),
	32 => (object) array(
		'ID'                => 32,
		'post_type'         => 'page',
		'post_status'       => 'publish',
		'post_title'        => 'Restore Notes',
		'post_name'         => 'restore-notes',
		'post_content'      => '<!-- wp:paragraph --><p>Page content for WXR.</p><!-- /wp:paragraph -->',
		'post_excerpt'      => '',
		'post_date'         => '2026-06-03 09:00:00',
		'post_date_gmt'     => '2026-06-03 09:00:00',
		'post_modified'     => '2026-06-03 09:00:00',
		'post_modified_gmt' => '2026-06-03 09:00:00',
		'post_author'       => 1,
		'permalink_path'    => 'restore-notes/',
	),
	33 => (object) array(
		'ID'             => 33,
		'post_type'      => 'post',
		'post_status'    => 'draft',
		'post_title'     => 'Draft should not export',
		'post_content'   => 'Draft content',
		'permalink_path' => 'draft-should-not-export/',
	),
);
$ssgwp_test_http_responses = array(
	'https://example.test/' => '<html><head><title>Source State</title></head><body><main><h1>Source State</h1></main></body></html>',
);
$ssgwp_test_active_plugins = array(
	'static-site-generator/static-site-generator.php',
	'forms/forms.php',
);
$ssgwp_test_network_active_plugins = array(
	'network-tools/network-tools.php' => 1,
);

$fixture_paths = array(
	WP_CONTENT_DIR . '/database',
	WP_CONTENT_DIR . '/plugins/forms/includes',
	WP_CONTENT_DIR . '/plugins/network-tools',
	WP_CONTENT_DIR . '/themes/twentytwentysix/assets',
	WP_CONTENT_DIR . '/uploads/2026/06',
);

foreach ( $fixture_paths as $fixture_path ) {
	if ( ! wp_mkdir_p( $fixture_path ) ) {
		ssgwp_fail( 'Could not create full-site snapshot fixture path: ' . $fixture_path );
	}
}

file_put_contents( WP_CONTENT_DIR . '/database/.ht.sqlite', 'sqlite database bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( WP_CONTENT_DIR . '/plugins/forms/forms.php', "<?php\n/* Plugin Name: Forms */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( WP_CONTENT_DIR . '/plugins/forms/includes/field.php', "<?php\nreturn 'field';\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( WP_CONTENT_DIR . '/plugins/forms/.env', 'FORM_SECRET=do-not-export' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( WP_CONTENT_DIR . '/plugins/forms/private.pem', 'secret pem' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( WP_CONTENT_DIR . '/plugins/forms/api-token.txt', 'secret token' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( WP_CONTENT_DIR . '/plugins/network-tools/network-tools.php', "<?php\n/* Plugin Name: Network Tools */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( WP_CONTENT_DIR . '/themes/twentytwentysix/style.css', 'body{color:#111;}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( WP_CONTENT_DIR . '/themes/twentytwentysix/assets/theme.js', "console.log('theme');\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( WP_CONTENT_DIR . '/uploads/2026/06/photo.jpg', 'jpeg bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( WP_CONTENT_DIR . '/uploads/2026/06/credentials.json', '{"secret":true}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

$snapshot_symlink_created = function_exists( 'symlink' )
	&& @symlink( WP_CONTENT_DIR . '/uploads/2026/06/photo.jpg', WP_CONTENT_DIR . '/uploads/2026/06/photo-link.jpg' );

$full_site_source_state_output_dir = $fixture_root . '/source-state-full-site-export';
$full_site_source_state_result     = $exporter->export_to_directory(
	$full_site_source_state_output_dir,
	array(
		'max_pages'                       => 1,
		'copy_uploads'                    => false,
		'copy_theme'                      => false,
		'copy_plugins'                    => false,
		'copy_core_assets'                => false,
		'include_manifest'                => true,
		'include_playground_source_state' => true,
		'include_cloudflare_publish'      => true,
		'progress_callback'               => null,
	)
);

$full_site_snapshot_path   = $full_site_source_state_output_dir . '/_playground-source/wordpress-files.zip';
$full_site_source_json     = file_get_contents( $full_site_source_state_output_dir . '/_playground-source/source-state.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$full_site_source_data     = json_decode( $full_site_source_json, true );
$full_site_manifest_json   = file_get_contents( $full_site_source_state_output_dir . '/wp-admin/playground-source-manifest.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$full_site_manifest_data   = json_decode( $full_site_manifest_json, true );
$full_site_blueprint_data  = json_decode( file_get_contents( $full_site_source_state_output_dir . '/wp-admin/playground-blueprint.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$full_site_handoff         = file_get_contents( $full_site_source_state_output_dir . '/wp-admin/index.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$full_site_bundle_path     = $full_site_source_state_output_dir . '/_playground-source/playground-blueprint-bundle.zip';
$full_site_bundle_hash     = hash_file( 'sha256', $full_site_bundle_path );
$full_site_snapshot_hash   = hash_file( 'sha256', $full_site_snapshot_path );

ssgwp_assert_same(
	true,
	file_exists( $full_site_snapshot_path ),
	'export_to_directory writes wordpress-files.zip when a readable SQLite database exists.'
);

$full_site_snapshot_zip = new ZipArchive();

ssgwp_assert_same(
	true,
	true === $full_site_snapshot_zip->open( $full_site_snapshot_path ),
	'The full-site WordPress files snapshot is an inspectable ZIP archive.'
);

foreach (
	array(
		'wp-content/database/.ht.sqlite',
		'wp-content/plugins/forms/forms.php',
		'wp-content/plugins/forms/includes/field.php',
		'wp-content/plugins/network-tools/network-tools.php',
		'wp-content/themes/twentytwentysix/style.css',
		'wp-content/themes/twentytwentysix/assets/theme.js',
		'wp-content/uploads/2026/06/photo.jpg',
	) as $full_site_snapshot_entry
) {
	ssgwp_assert_same(
		true,
		false !== $full_site_snapshot_zip->locateName( $full_site_snapshot_entry ),
		'The full-site snapshot includes ' . $full_site_snapshot_entry . '.'
	);
}

foreach (
	array(
		'wp-content/plugins/forms/.env',
		'wp-content/plugins/forms/private.pem',
		'wp-content/plugins/forms/api-token.txt',
		'wp-content/uploads/2026/06/credentials.json',
		'wp-content/uploads/2026/06/photo-link.jpg',
	) as $full_site_excluded_entry
) {
	ssgwp_assert_same(
		false,
		false !== $full_site_snapshot_zip->locateName( $full_site_excluded_entry ),
		'The full-site snapshot excludes unsafe entry ' . $full_site_excluded_entry . '.'
	);
}

$full_site_snapshot_zip->close();

ssgwp_assert_same(
	'available',
	$full_site_source_data['artifacts']['wordpress_files_snapshot']['status'],
	'source-state.json records the available full-site snapshot status.'
);

ssgwp_assert_same(
	'_playground-source/wordpress-files.zip',
	$full_site_source_data['artifacts']['wordpress_files_snapshot']['path'],
	'source-state.json records the full-site snapshot path.'
);

ssgwp_assert_same(
	$full_site_snapshot_hash,
	$full_site_source_data['artifacts']['wordpress_files_snapshot']['sha256'],
	'source-state.json records the full-site snapshot SHA-256 hash.'
);

ssgwp_assert_same(
	'sqlite-full-site-wordpress-files',
	$full_site_source_data['artifacts']['wordpress_files_snapshot']['mode'],
	'source-state.json records the full-site snapshot mode.'
);

ssgwp_assert_same(
	true,
	$full_site_source_data['artifacts']['wordpress_files_snapshot']['sqlite_database_captured'],
	'source-state.json records that the SQLite database was captured.'
);

ssgwp_assert_same(
	true,
	$full_site_source_data['artifacts']['wordpress_files_snapshot']['file_count'] >= 7,
	'source-state.json records included full-site snapshot file counts.'
);

ssgwp_assert_same(
	true,
	$full_site_source_data['artifacts']['wordpress_files_snapshot']['total_size_bytes'] > 0,
	'source-state.json records included full-site snapshot byte size.'
);

ssgwp_assert_same(
	true,
	$full_site_source_data['artifacts']['wordpress_files_snapshot']['skipped_secret_count'] >= 4,
	'source-state.json records skipped secret-like files.'
);

if ( $snapshot_symlink_created ) {
	ssgwp_assert_same(
		true,
		$full_site_source_data['artifacts']['wordpress_files_snapshot']['skipped_symlink_count'] >= 1,
		'source-state.json records skipped symlink entries when symlink support is available.'
	);
}

ssgwp_assert_same(
	'sqlite-full-site-playground-blueprint-bundle',
	$full_site_source_data['artifacts']['blueprint_bundle_mode'],
	'source-state.json records the full-site Blueprint bundle mode.'
);

ssgwp_assert_same(
	'sqlite-full-site-blueprint-bundle-generated',
	$full_site_source_data['restore']['blueprint_bundle']['status'],
	'source-state.json records the full-site Blueprint bundle status.'
);

ssgwp_assert_same(
	true,
	$full_site_source_data['restore']['blueprint_bundle']['full_site_restore']
		&& ! $full_site_source_data['restore']['blueprint_bundle']['content_only']
		&& ! $full_site_source_data['restore']['blueprint_bundle']['not_full_restore_bundle'],
	'source-state.json records that the owner-only bundle is the full-site restore path.'
);

ssgwp_assert_contains(
	'SQLite full-site Blueprint bundle restores wp-content plus the SQLite database',
	implode( "\n", $full_site_source_data['limitations'] ),
	'source-state.json documents the SQLite full-site restore behavior.'
);

ssgwp_assert_same(
	'available',
	$full_site_manifest_data['source_state']['wordpress_files_snapshot']['status'],
	'The Playground source manifest records the full-site snapshot status.'
);

ssgwp_assert_same(
	true,
	$full_site_manifest_data['source_state']['blueprint_bundle']['full_site_restore'],
	'The Playground source manifest records that the owner-only bundle is full-site restore.'
);

ssgwp_assert_same(
	'importWxr',
	$full_site_manifest_data['source_state']['content_restore']['blueprint_step'],
	'The web /wp-admin/ handoff remains the inline WXR flow.'
);

ssgwp_assert_same(
	'importWxr',
	$full_site_blueprint_data['steps'][1]['step'],
	'The web /wp-admin/ Blueprint remains the inline WXR handoff when a full-site bundle is also available.'
);

ssgwp_assert_contains(
	'"step":"importWxr"',
	$full_site_handoff,
	'The web /wp-admin/ handoff inline Blueprint keeps the WXR import path.'
);

ssgwp_assert_contains(
	'wordpress-files.zip',
	$full_site_source_json,
	'source-state.json points owners to the full-site WordPress files snapshot.'
);

ssgwp_assert_same(
	$full_site_snapshot_hash,
	$full_site_source_state_result['playground_source_state']['wordpress_files_snapshot_sha256'],
	'export_to_directory reports the full-site snapshot hash through the source-state summary.'
);

ssgwp_assert_same(
	'sqlite-full-site-playground-blueprint-bundle',
	$full_site_source_state_result['playground_source_state']['blueprint_bundle_mode'],
	'export_to_directory reports the full-site Blueprint bundle mode through the source-state summary.'
);

ssgwp_assert_same(
	$full_site_snapshot_hash,
	$full_site_source_state_result['playground_admin']['wordpress_files_snapshot_sha256'],
	'export_to_directory reports the full-site snapshot hash through the Playground admin summary.'
);

ssgwp_assert_same(
	false,
	file_exists( $full_site_source_state_output_dir . '/_cloudflare-publish/site/_playground-source' ),
	'Cloudflare public assets do not copy owner-only full-site Playground source-state artifacts.'
);

$full_site_bundle_zip = new ZipArchive();

ssgwp_assert_same(
	true,
	true === $full_site_bundle_zip->open( $full_site_bundle_path ),
	'The full-site Playground Blueprint bundle is an inspectable ZIP archive.'
);

foreach ( array( 'blueprint.json', 'wordpress-files.zip', 'content/site-content.wxr', 'source-state.json' ) as $full_site_bundle_entry ) {
	ssgwp_assert_same(
		true,
		false !== $full_site_bundle_zip->locateName( $full_site_bundle_entry ),
		'The full-site Playground Blueprint bundle includes ' . $full_site_bundle_entry . '.'
	);
}

$full_site_bundle_blueprint_json = $full_site_bundle_zip->getFromName( 'blueprint.json' );
$full_site_bundle_metadata_json  = $full_site_bundle_zip->getFromName( 'source-state.json' );
$full_site_bundle_snapshot       = $full_site_bundle_zip->getFromName( 'wordpress-files.zip' );
$full_site_bundle_zip->close();

ssgwp_assert_same(
	file_get_contents( $full_site_snapshot_path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$full_site_bundle_snapshot,
	'The full-site Blueprint bundle embeds the generated WordPress files snapshot.'
);

$full_site_bundle_blueprint = json_decode( $full_site_bundle_blueprint_json, true );
$full_site_bundle_metadata  = json_decode( $full_site_bundle_metadata_json, true );

ssgwp_assert_same(
	'importWordPressFiles',
	$full_site_bundle_blueprint['steps'][1]['step'],
	'The full-site Blueprint bundle imports bundled WordPress files.'
);

ssgwp_assert_same(
	array(
		'resource' => 'bundled',
		'path' => '/wordpress-files.zip',
	),
	$full_site_bundle_blueprint['steps'][1]['wordPressFilesZip'],
	'The full-site Blueprint bundle imports wordpress-files.zip as a bundled resource.'
);

foreach ( $full_site_bundle_blueprint['steps'] as $full_site_bundle_step ) {
	if ( isset( $full_site_bundle_step['step'] ) && 'importWxr' === $full_site_bundle_step['step'] ) {
		ssgwp_fail( 'The full-site Blueprint bundle should not include an importWxr step after importWordPressFiles.' );
	}
}

ssgwp_assert_same(
	false,
	isset( $full_site_bundle_blueprint['stillpress'] ),
	'The full-site Blueprint avoids custom root metadata rejected by Playground schema validation.'
);

ssgwp_assert_same(
	true,
	! empty( $full_site_bundle_metadata['restore']['full_site_blueprint_bundle'] )
		&& ! empty( $full_site_bundle_metadata['restore']['blueprint_bundle']['full_site_restore'] )
		&& empty( $full_site_bundle_metadata['restore']['blueprint_bundle']['content_only'] ),
	'The bundled source-state metadata records the WordPress files restore mode.'
);

ssgwp_assert_same(
	'/wordpress-files.zip',
	$full_site_bundle_metadata['restore']['blueprint_bundle']['wordpress_files_resource_path'],
	'The bundled source-state metadata records the bundled WordPress files path.'
);

$full_site_bundle_handoff_context = ssgwp_decode_wp_cli_option_update_json(
	$full_site_bundle_blueprint['steps'][2]['command'],
	'ssgwp_playground_source_handoff'
);

ssgwp_assert_same(
	true,
	$full_site_bundle_handoff_context['restore']['full_site_restore']
		&& ! $full_site_bundle_handoff_context['restore']['content_only']
		&& ! $full_site_bundle_handoff_context['restore']['not_full_restore_bundle'],
	'The full-site restored-admin context records the SQLite full-site restore mode.'
);

ssgwp_assert_same(
	$full_site_snapshot_hash,
	$full_site_bundle_handoff_context['wordpress_files_snapshot']['sha256'],
	'The full-site restored-admin context records the WordPress files snapshot hash.'
);

ssgwp_assert_same(
	'available',
	$full_site_bundle_metadata['restore']['wordpress_files_snapshot']['status'],
	'The bundled source-state metadata records the full-site snapshot status.'
);

ssgwp_assert_same(
	true,
	$full_site_bundle_metadata['restore']['full_site_blueprint_bundle'],
	'The bundled source-state metadata records the full-site Blueprint bundle mode.'
);

$full_site_provided_output_dir = $fixture_root . '/source-state-full-site-provided-wxr-export';
$full_site_provided_wxr_url    = 'https://owner.example.test/playground/site-content.wxr?signature=fullsite123';
$full_site_provided_result     = $exporter->export_to_directory(
	$full_site_provided_output_dir,
	array(
		'max_pages'                       => 1,
		'copy_uploads'                    => false,
		'copy_theme'                      => false,
		'copy_plugins'                    => false,
		'copy_core_assets'                => false,
		'include_manifest'                => true,
		'include_playground_source_state' => true,
		'playground_source_wxr_url'       => $full_site_provided_wxr_url,
		'progress_callback'               => null,
	)
);

$full_site_provided_source_json   = file_get_contents( $full_site_provided_output_dir . '/_playground-source/source-state.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$full_site_provided_manifest_json = file_get_contents( $full_site_provided_output_dir . '/wp-admin/playground-source-manifest.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$full_site_provided_static_json   = file_get_contents( $full_site_provided_output_dir . '/static-export.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$full_site_provided_bundle_zip    = new ZipArchive();

ssgwp_assert_same(
	true,
	true === $full_site_provided_bundle_zip->open( $full_site_provided_output_dir . '/_playground-source/playground-blueprint-bundle.zip' ),
	'The provided-WXR full-site export writes an inspectable Blueprint bundle.'
);

$full_site_provided_bundle_blueprint_json = $full_site_provided_bundle_zip->getFromName( 'blueprint.json' );
$full_site_provided_bundle_metadata_json  = $full_site_provided_bundle_zip->getFromName( 'source-state.json' );
$full_site_provided_bundle_zip->close();

foreach (
	array(
		$full_site_provided_source_json,
		$full_site_provided_manifest_json,
		$full_site_provided_static_json,
		$full_site_provided_bundle_blueprint_json,
		$full_site_provided_bundle_metadata_json,
		wp_json_encode( $full_site_provided_result, JSON_UNESCAPED_SLASHES ),
	) as $full_site_provided_metadata_blob
) {
	ssgwp_assert_not_contains(
		$full_site_provided_wxr_url,
		$full_site_provided_metadata_blob,
		'Full-site metadata does not persist the provided signed WXR URL.'
	);
}

ssgwp_assert_same(
	true,
	isset( $full_site_provided_result['playground_source_state']['wordpress_files_snapshot_sqlite_database_captured'] )
		&& $full_site_provided_result['playground_source_state']['wordpress_files_snapshot_sqlite_database_captured'],
	'The provided-WXR full-site export still reports the captured SQLite database.'
);

unlink( WP_CONTENT_DIR . '/database/.ht.sqlite' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

$source_state_result = $exporter->export_to_directory(
	$source_state_output_dir,
	array(
		'max_pages'                       => 1,
		'copy_uploads'                    => false,
		'copy_theme'                      => false,
		'copy_plugins'                    => false,
		'copy_core_assets'                => false,
		'include_manifest'                => false,
		'include_playground_source_state' => true,
		'include_cloudflare_publish'      => true,
		'progress_callback'               => null,
	)
);

$source_state_wxr_path       = $source_state_output_dir . '/_playground-source/site-content.wxr';
$source_state_json_path      = $source_state_output_dir . '/_playground-source/source-state.json';
$source_state_bundle_path    = $source_state_output_dir . '/_playground-source/playground-blueprint-bundle.zip';
$source_state_manifest_path  = $source_state_output_dir . '/wp-admin/playground-source-manifest.json';
$source_state_blueprint_path = $source_state_output_dir . '/wp-admin/playground-blueprint.json';

ssgwp_assert_same(
	true,
	file_exists( $source_state_json_path ),
	'export_to_directory writes source-state.json when Playground source state is enabled.'
);

ssgwp_assert_same(
	true,
	file_exists( $source_state_wxr_path ),
	'export_to_directory writes site-content.wxr when Playground source state is enabled.'
);

ssgwp_assert_same(
	true,
	file_exists( $source_state_bundle_path ),
	'export_to_directory writes the Playground Blueprint bundle when source state is enabled.'
);

ssgwp_assert_same(
	true,
	file_exists( $source_state_output_dir . '/wp-admin/index.html' ),
	'Playground source state also includes the static /wp-admin/ handoff.'
);

$source_state_wxr       = file_get_contents( $source_state_wxr_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$source_state_data      = json_decode( file_get_contents( $source_state_json_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$source_manifest_data   = json_decode( file_get_contents( $source_state_manifest_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$source_state_blueprint = json_decode( file_get_contents( $source_state_blueprint_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$source_state_handoff   = file_get_contents( $source_state_output_dir . '/wp-admin/index.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$source_state_wxr_hash  = hash( 'sha256', $source_state_wxr );
$source_state_bundle_hash = hash_file( 'sha256', $source_state_bundle_path );
$source_state_bundle_zip  = new ZipArchive();

ssgwp_assert_same(
	true,
	true === $source_state_bundle_zip->open( $source_state_bundle_path ),
	'The Playground Blueprint bundle is an inspectable ZIP archive.'
);

foreach ( array( 'blueprint.json', 'content/site-content.wxr', 'source-state.json' ) as $source_state_bundle_entry ) {
	ssgwp_assert_same(
		true,
		false !== $source_state_bundle_zip->locateName( $source_state_bundle_entry ),
		'The Playground Blueprint bundle includes ' . $source_state_bundle_entry . '.'
	);
}

$source_state_bundle_blueprint_json = $source_state_bundle_zip->getFromName( 'blueprint.json' );
$source_state_bundle_wxr            = $source_state_bundle_zip->getFromName( 'content/site-content.wxr' );
$source_state_bundle_metadata_json  = $source_state_bundle_zip->getFromName( 'source-state.json' );
$source_state_bundle_zip->close();

$source_state_bundle_blueprint = json_decode( $source_state_bundle_blueprint_json, true );
$source_state_bundle_metadata  = json_decode( $source_state_bundle_metadata_json, true );

ssgwp_assert_same(
	true,
	is_array( $source_state_bundle_blueprint ),
	'The bundled Playground Blueprint is valid JSON.'
);

ssgwp_assert_same(
	true,
	is_array( $source_state_bundle_metadata ),
	'The bundled source-state metadata is valid JSON.'
);

ssgwp_assert_same(
	$source_state_wxr,
	$source_state_bundle_wxr,
	'The Playground Blueprint bundle contains the generated WXR as a bundled resource.'
);

ssgwp_assert_same(
	$source_state_wxr_hash,
	$source_state_data['artifacts']['wxr_sha256'],
	'source-state.json records the SHA-256 hash of site-content.wxr.'
);

ssgwp_assert_same(
	'_playground-source/playground-blueprint-bundle.zip',
	$source_state_data['artifacts']['blueprint_bundle'],
	'source-state.json records the Playground Blueprint bundle path.'
);

ssgwp_assert_same(
	$source_state_bundle_hash,
	$source_state_data['artifacts']['blueprint_bundle_sha256'],
	'source-state.json records the Playground Blueprint bundle SHA-256 hash.'
);

ssgwp_assert_same(
	'content-only-playground-blueprint-bundle',
	$source_state_data['artifacts']['blueprint_bundle_mode'],
	'source-state.json records the content-only Blueprint bundle mode.'
);

ssgwp_assert_same(
	'content-only-blueprint-bundle-generated',
	$source_state_data['restore']['blueprint_bundle']['status'],
	'source-state.json records the content-only Blueprint bundle status.'
);

ssgwp_assert_contains(
	'?blueprint-url=',
	$source_state_data['restore']['blueprint_bundle']['playground_url_usage'],
	'source-state.json explains intentional Playground bundle URL hosting.'
);

ssgwp_assert_same(
	'fallback-published-posts-pages',
	$source_state_data['artifacts']['wxr_generation_method'],
	'source-state.json records the deterministic fallback WXR generation method in tests.'
);

ssgwp_assert_same(
	'importWxr',
	$source_state_bundle_blueprint['steps'][1]['step'],
	'The bundled Playground Blueprint includes an importWxr step.'
);

ssgwp_assert_same(
	array(
		'resource' => 'bundled',
		'path' => '/content/site-content.wxr',
	),
	$source_state_bundle_blueprint['steps'][1]['file'],
	'The bundled Playground Blueprint imports WXR from the bundled resource path.'
);

ssgwp_assert_same(
	'installPlugin',
	$source_state_bundle_blueprint['steps'][0]['step'],
	'The bundled Playground Blueprint keeps the StillPress install step.'
);

ssgwp_assert_same(
	'static-site-generator',
	$source_state_bundle_blueprint['steps'][0]['pluginData']['path'],
	'The bundled Playground Blueprint installs the StillPress plugin directory.'
);

ssgwp_assert_same(
	'wp-cli',
	$source_state_bundle_blueprint['steps'][2]['step'],
	'The bundled Playground Blueprint writes restored Playground source handoff context after WXR import.'
);

ssgwp_assert_contains(
	'ssgwp_playground_source_handoff',
	$source_state_bundle_blueprint['steps'][2]['command'],
	'The bundled Playground Blueprint keeps the restored-admin context option write.'
);

ssgwp_assert_same(
	'wp option update permalink_structure \'/%postname%/\'',
	$source_state_bundle_blueprint['steps'][3]['command'],
	'The bundled Playground Blueprint keeps the permalink update step.'
);

ssgwp_assert_same(
	'wp rewrite flush --hard',
	$source_state_bundle_blueprint['steps'][4]['command'],
	'The bundled Playground Blueprint keeps the rewrite flush step.'
);

ssgwp_assert_same(
	'/wp-admin/tools.php?page=playground-static-site-generator',
	$source_state_bundle_blueprint['landingPage'],
	'The bundled Playground Blueprint keeps the StillPress landing page.'
);

ssgwp_assert_same(
	false,
	isset( $source_state_bundle_blueprint['stillpress'] ),
	'The bundled Playground Blueprint avoids custom root metadata rejected by Playground schema validation.'
);

ssgwp_assert_same(
	'bundled',
	$source_state_bundle_metadata['restore']['wxr_resource']['resource'],
	'The bundled source-state metadata records bundled WXR metadata.'
);

ssgwp_assert_same(
	'/content/site-content.wxr',
	$source_state_bundle_metadata['restore']['wxr_resource']['path'],
	'The bundled source-state metadata records the bundled WXR path.'
);

ssgwp_assert_not_contains(
	'__SSGWP_WXR_URL__',
	$source_state_bundle_blueprint_json,
	'The bundled Playground Blueprint does not contain the WXR URL placeholder.'
);

ssgwp_assert_not_contains(
	'"resource":"url"',
	$source_state_bundle_blueprint_json,
	'The bundled Playground Blueprint does not use a URL resource for importWxr.'
);

$source_state_bundle_handoff_context = ssgwp_decode_wp_cli_option_update_json(
	$source_state_bundle_blueprint['steps'][2]['command'],
	'ssgwp_playground_source_handoff'
);

ssgwp_assert_same(
	'bundled-resource',
	$source_state_bundle_handoff_context['wxr']['url_mode'],
	'The bundled Playground Blueprint restored-admin context records bundled-resource WXR mode.'
);

ssgwp_assert_same(
	$source_state_wxr_hash,
	$source_state_bundle_handoff_context['wxr']['sha256'],
	'The bundled Playground Blueprint restored-admin context records the WXR hash.'
);

ssgwp_assert_same(
	false,
	isset( $source_state_bundle_handoff_context['effective_wxr_url'] )
		|| isset( $source_state_bundle_handoff_context['wxr']['effective_wxr_url'] )
		|| isset( $source_state_bundle_handoff_context['wxr']['url'] ),
	'The bundled Playground Blueprint restored-admin context does not store an explicit WXR URL.'
);

ssgwp_assert_same(
	'bundled-resource',
	$source_state_bundle_metadata['restore']['wxr_url_mode'],
	'The bundled source-state metadata records bundled-resource mode.'
);

ssgwp_assert_same(
	array(
		'resource' => 'bundled',
		'path' => '/content/site-content.wxr',
	),
	$source_state_bundle_metadata['restore']['wxr_resource'],
	'The bundled source-state metadata records the bundled WXR resource.'
);

ssgwp_assert_same(
	false,
	isset( $source_state_bundle_metadata['restore']['wxr_url_runtime_expression'] ),
	'The bundled source-state metadata does not include the web handoff runtime WXR URL expression.'
);

ssgwp_assert_same(
	'/content/site-content.wxr',
	$source_state_bundle_metadata['restore']['blueprint_bundle']['wxr_resource_path'],
	'The bundled source-state metadata records the bundled WXR resource path.'
);

ssgwp_assert_same(
	null,
	$source_state_bundle_metadata['restore']['blueprint_bundle']['sha256'],
	'Bundled source-state metadata avoids a self-referential bundle hash.'
);

ssgwp_assert_same(
	0,
	$ssgwp_test_export_wp_calls,
	'HTTP-like source-state exports avoid core export_wp() so WXR download headers cannot leak.'
);

$source_state_headers = function_exists( 'headers_list' ) ? implode( "\n", headers_list() ) : '';

ssgwp_assert_not_contains(
	'Content-Disposition',
	$source_state_headers,
	'HTTP-like source-state exports do not leave a WXR Content-Disposition header behind.'
);

ssgwp_assert_not_contains(
	'application/rss+xml',
	$source_state_headers,
	'HTTP-like source-state exports do not leave a WXR Content-Type header behind.'
);

ssgwp_assert_same(
	'source-state-generated',
	$source_state_data['restore']['status'],
	'source-state.json records the generated restore status.'
);

ssgwp_assert_same(
	true,
	$source_state_data['restore']['wxr_import_enabled'],
	'source-state.json records that WXR content import is enabled for the source-state handoff.'
);

ssgwp_assert_contains(
	'public, signed, or private URL',
	$source_state_data['restore']['wxr_url_requirement'],
	'source-state.json records that the WXR URL must be reachable by Playground.'
);

ssgwp_assert_same(
	'runtime-relative-export-path',
	$source_state_data['restore']['wxr_url_mode'],
	'source-state.json records the runtime-relative WXR URL mode when no WXR URL is provided.'
);

ssgwp_assert_same(
	null,
	$source_state_data['restore']['source_access_expires_at'],
	'source-state.json records no authoritative source access expiry when none is provided.'
);

ssgwp_assert_same(
	'not-provided',
	$source_state_data['restore']['source_access_expires_at_status'],
	'source-state.json records the no-expiry status when no expiry is provided.'
);

ssgwp_assert_same(
	false,
	isset( $source_state_data['restore']['effective_wxr_url'] ),
	'source-state.json does not invent an effective WXR URL for runtime-relative handoffs.'
);

ssgwp_assert_same(
	true,
	$source_state_data['owner_access_policy']['owner_only'],
	'source-state.json records that source-state artifacts are owner-only.'
);

ssgwp_assert_same(
	true,
	$source_state_data['owner_access_policy']['may_expose_editable_source_content'],
	'source-state.json records that source-state artifacts may expose editable source content.'
);

ssgwp_assert_same(
	false,
	$source_state_data['owner_access_policy']['deploy_credentials_stored']
		|| $source_state_data['owner_access_policy']['owner_identity_stored']
		|| $source_state_data['owner_access_policy']['authorization_tokens_stored'],
	'source-state.json records that credentials, owner identity, and authorization tokens are not stored.'
);

ssgwp_assert_contains(
	'generated artifacts do not authorize a redeploy by themselves',
	$source_state_data['owner_access_policy']['redeploy_authorization_note'],
	'source-state.json records the redeploy authorization policy note.'
);

ssgwp_assert_same(
	'twentytwentysix',
	$source_state_data['active_theme']['stylesheet'],
	'source-state.json records the active theme stylesheet when available.'
);

ssgwp_assert_same(
	'static-site-generator/static-site-generator.php',
	$source_state_data['active_plugins'][0]['plugin'],
	'source-state.json records active plugin metadata when available.'
);

ssgwp_assert_same(
	true,
	$source_state_data['active_plugins'][2]['network_active'],
	'source-state.json records network-active plugin metadata when available.'
);

ssgwp_assert_contains(
	'WXR restores content but not the full WordPress database or plugin settings yet.',
	implode( "\n", $source_state_data['limitations'] ),
	'source-state.json documents restore limitations.'
);

ssgwp_assert_contains(
	'use intentional public, signed, or private URLs for owner-only restore',
	$source_state_data['security_warning'],
	'source-state.json includes the public exposure security warning.'
);

ssgwp_assert_contains(
	'<title>Coffee &amp; Docs</title>',
	$source_state_wxr,
	'Fallback WXR escapes published post titles.'
);

ssgwp_assert_contains(
	'<wp:post_id>32</wp:post_id>',
	$source_state_wxr,
	'Fallback WXR includes published pages.'
);

ssgwp_assert_not_contains(
	'Draft should not export',
	$source_state_wxr,
	'Fallback WXR excludes non-published content.'
);

ssgwp_assert_same(
	'source-state-generated',
	$source_manifest_data['source_state']['status'],
	'The Playground source manifest switches to source-state-generated when source artifacts exist.'
);

ssgwp_assert_same(
	'../_playground-source/source-state.json',
	$source_manifest_data['source_state']['source_state_path'],
	'The Playground source manifest points to source-state.json.'
);

ssgwp_assert_same(
	'../_playground-source/site-content.wxr',
	$source_manifest_data['source_state']['wxr_path'],
	'The Playground source manifest points to site-content.wxr.'
);

ssgwp_assert_same(
	$source_state_wxr_hash,
	$source_manifest_data['source_state']['wxr_sha256'],
	'The Playground source manifest records the WXR hash.'
);

ssgwp_assert_same(
	'_playground-source/playground-blueprint-bundle.zip',
	$source_manifest_data['source_state']['blueprint_bundle']['path'],
	'The Playground source manifest records the Blueprint bundle path.'
);

ssgwp_assert_same(
	$source_state_bundle_hash,
	$source_manifest_data['source_state']['blueprint_bundle']['sha256'],
	'The Playground source manifest records the Blueprint bundle SHA-256 hash.'
);

ssgwp_assert_same(
	'content-only-playground-blueprint-bundle',
	$source_manifest_data['source_state']['blueprint_bundle']['mode'],
	'The Playground source manifest records the content-only Blueprint bundle mode.'
);

ssgwp_assert_same(
	true,
	$source_manifest_data['source_state']['blueprint_bundle']['owner_only'],
	'The Playground source manifest records that the Blueprint bundle is owner-only.'
);

ssgwp_assert_contains(
	'?blueprint-url=',
	$source_manifest_data['source_state']['blueprint_bundle']['playground_url_usage'],
	'The Playground source manifest explains intentional Playground bundle URL hosting.'
);

ssgwp_assert_same(
	true,
	$source_manifest_data['source_state']['wxr_import_enabled'],
	'The Playground source manifest records that WXR import is enabled.'
);

ssgwp_assert_same(
	'importWxr',
	$source_manifest_data['source_state']['content_restore']['blueprint_step'],
	'The Playground source manifest records the WXR import Blueprint step.'
);

ssgwp_assert_same(
	true,
	$source_manifest_data['source_state']['not_full_restore_bundle'],
	'The Playground source manifest preserves the not-full-restore-bundle marker.'
);

ssgwp_assert_contains(
	'public, signed, or private URL',
	$source_manifest_data['source_state']['wxr_url_requirement'],
	'The Playground source manifest documents that Playground must be able to fetch the WXR URL.'
);

ssgwp_assert_same(
	'runtime-relative-export-path',
	$source_manifest_data['source_state']['wxr_url_mode'],
	'The Playground source manifest records the runtime-relative WXR URL mode when no WXR URL is provided.'
);

ssgwp_assert_same(
	'new URL("../_playground-source/site-content.wxr", window.location.href).href',
	$source_manifest_data['source_state']['wxr_url_runtime_expression'],
	'The Playground source manifest records the runtime-relative WXR URL expression when no WXR URL is provided.'
);

ssgwp_assert_same(
	false,
	isset( $source_manifest_data['source_state']['effective_wxr_url'] ),
	'The Playground source manifest does not invent an effective WXR URL for runtime-relative handoffs.'
);

ssgwp_assert_same(
	true,
	$source_manifest_data['source_state']['owner_access_policy']['owner_only'],
	'The Playground source manifest records the owner-only source-state policy.'
);

ssgwp_assert_same(
	'inline-fragment',
	$source_manifest_data['handoff']['blueprint_url_mode'],
	'The Playground source manifest records that source-state handoffs use an inline Blueprint fragment.'
);

ssgwp_assert_same(
	'importWxr',
	$source_state_blueprint['steps'][1]['step'],
	'The source-state Blueprint template includes an importWxr step.'
);

ssgwp_assert_same(
	'url',
	$source_state_blueprint['steps'][1]['file']['resource'],
	'The source-state Blueprint template imports WXR from a URL resource.'
);

ssgwp_assert_same(
	'__SSGWP_WXR_URL__',
	$source_state_blueprint['steps'][1]['file']['url'],
	'The source-state Blueprint template keeps a runtime WXR URL placeholder.'
);

ssgwp_assert_same(
	false,
	isset( $source_state_blueprint['stillpress'] ),
	'The source-state Blueprint template avoids custom root metadata rejected by Playground schema validation.'
);

ssgwp_assert_same(
	'runtime-relative-export-path',
	$source_manifest_data['source_state']['wxr_url_mode'],
	'The Playground source manifest records the runtime-relative WXR URL mode.'
);

ssgwp_assert_same(
	'new URL("../_playground-source/site-content.wxr", window.location.href).href',
	$source_manifest_data['source_state']['wxr_url_runtime_expression'],
	'The Playground source manifest records the runtime-relative WXR URL expression.'
);

ssgwp_assert_same(
	'wp-cli',
	$source_state_blueprint['steps'][2]['step'],
	'The source-state Blueprint writes restored Playground source handoff context after WXR import.'
);

$source_handoff_context = ssgwp_decode_wp_cli_option_update_json(
	$source_state_blueprint['steps'][2]['command'],
	'ssgwp_playground_source_handoff'
);

ssgwp_assert_same(
	'https://stillpress.local/playground-source-handoff/v1',
	$source_handoff_context['schema'],
	'The restored Playground source handoff context records its schema.'
);

ssgwp_assert_same(
	1,
	$source_handoff_context['version'],
	'The restored Playground source handoff context records its version.'
);

ssgwp_assert_same(
	'source-state-generated',
	$source_handoff_context['source_state']['status'],
	'The restored Playground source handoff context records source-state status.'
);

ssgwp_assert_same(
	true,
	$source_handoff_context['wxr']['import_enabled'],
	'The restored Playground source handoff context records that WXR import is enabled.'
);

ssgwp_assert_same(
	'runtime-relative-export-path',
	$source_handoff_context['wxr']['url_mode'],
	'The restored Playground source handoff context records the runtime-relative WXR URL mode.'
);

ssgwp_assert_same(
	$source_state_wxr_hash,
	$source_handoff_context['wxr']['sha256'],
	'The restored Playground source handoff context records the WXR hash.'
);

ssgwp_assert_same(
	'not-provided',
	$source_handoff_context['source_access']['expires_at_status'],
	'The restored Playground source handoff context records the source access expiry status.'
);

ssgwp_assert_same(
	true,
	$source_handoff_context['publish']['cloudflare_publish_included'],
	'The restored Playground source handoff context records whether Cloudflare publish artifacts were included.'
);

ssgwp_assert_same(
	true,
	$source_handoff_context['restore']['content_only'] && $source_handoff_context['restore']['not_full_restore_bundle'],
	'The restored Playground source handoff context records the content-only not-full-restore marker.'
);

ssgwp_assert_same(
	false,
	$source_handoff_context['security']['credentials_stored']
		|| $source_handoff_context['security']['tokens_stored']
		|| $source_handoff_context['security']['owner_identity_stored']
		|| $source_handoff_context['security']['effective_wxr_url_stored'],
	'The restored Playground source handoff context records that credentials, tokens, owner identity, and explicit WXR URLs are not stored.'
);

ssgwp_assert_same(
	true,
	$source_handoff_context['redeploy']['requires_external_credentials'],
	'The restored Playground source handoff context records that redeploy requires owner/operator credentials outside the export.'
);

ssgwp_assert_same(
	false,
	$source_handoff_context['redeploy']['automatic_cloudflare_deploy'],
	'The restored Playground source handoff context records that Cloudflare deployment is not automatic.'
);

ssgwp_assert_same(
	false,
	isset( $source_handoff_context['effective_wxr_url'] )
		|| isset( $source_handoff_context['wxr']['effective_wxr_url'] )
		|| isset( $source_handoff_context['wxr']['url'] ),
	'The restored Playground source handoff context does not store an explicit WXR URL.'
);

ssgwp_assert_same(
	'wp-cli',
	$source_state_blueprint['steps'][3]['step'],
	'The source-state Blueprint keeps the permalink step after the WXR import and handoff context steps.'
);

ssgwp_assert_contains(
	'_playground-source/source-state.json',
	$source_state_handoff,
	'The static admin handoff visibly states that source-state artifacts exist.'
);

ssgwp_assert_contains(
	'The handoff imports WXR content when the WXR URL is reachable by WordPress Playground',
	$source_state_handoff,
	'The static admin handoff explains the WXR content import path.'
);

ssgwp_assert_contains(
	'new URL("../_playground-source/site-content.wxr", window.location.href).href',
	$source_state_handoff,
	'The static admin handoff computes the absolute WXR URL at runtime.'
);

ssgwp_assert_contains(
	'https://playground.wordpress.net/#',
	$source_state_handoff,
	'The static admin handoff uses an inline Blueprint URL fragment for source-state restores.'
);

ssgwp_assert_not_contains(
	'https://playground.wordpress.net/?blueprint-url=',
	$source_state_handoff,
	'The static admin handoff does not use a remote Blueprint URL when WXR source-state import is available.'
);

ssgwp_assert_contains(
	'"step":"importWxr"',
	$source_state_handoff,
	'The static admin handoff inline Blueprint includes the importWxr step.'
);

ssgwp_assert_same(
	'source-state-generated',
	$source_state_result['playground_admin']['source_state_status'],
	'export_to_directory reports generated source-state status through the Playground admin summary.'
);

ssgwp_assert_same(
	true,
	$source_state_result['playground_admin']['wxr_import_enabled'],
	'export_to_directory reports that WXR import is enabled through the Playground admin summary.'
);

ssgwp_assert_same(
	'https://playground.wordpress.net/#{urlencoded_inline_blueprint_json}',
	$source_state_result['playground_admin']['playground_url_template'],
	'export_to_directory reports the inline Blueprint URL template for source-state handoffs.'
);

ssgwp_assert_same(
	$source_state_wxr_hash,
	$source_state_result['playground_source_state']['wxr_sha256'],
	'export_to_directory reports the generated WXR hash.'
);

ssgwp_assert_same(
	'_playground-source/playground-blueprint-bundle.zip',
	$source_state_result['playground_source_state']['blueprint_bundle_path'],
	'export_to_directory reports the generated Blueprint bundle path.'
);

ssgwp_assert_same(
	$source_state_bundle_hash,
	$source_state_result['playground_source_state']['blueprint_bundle_sha256'],
	'export_to_directory reports the generated Blueprint bundle hash.'
);

ssgwp_assert_same(
	$source_state_bundle_hash,
	$source_state_result['playground_admin']['blueprint_bundle_sha256'],
	'export_to_directory reports the generated Blueprint bundle hash through the Playground admin summary.'
);

ssgwp_assert_same(
	'runtime-relative-export-path',
	$source_state_result['playground_source_state']['wxr_url_mode'],
	'export_to_directory reports runtime-relative WXR URL mode when no WXR URL is provided.'
);

ssgwp_assert_same(
	'not-provided',
	$source_state_result['playground_source_state']['source_access_expires_at_status'],
	'export_to_directory reports no source access expiry when none is provided.'
);

ssgwp_assert_same(
	false,
	file_exists( $source_state_output_dir . '/_cloudflare-publish/site/_playground-source' ),
	'Cloudflare public assets do not copy owner-only Playground source-state artifacts.'
);

$provided_source_state_output_dir = $fixture_root . '/source-state-provided-wxr-export';
$provided_wxr_url                 = 'https://owner.example.test/playground/site-content.wxr?signature=abc123';
$provided_expires_at              = '2026-06-30T12:45:00Z';
$provided_source_state_result     = $exporter->export_to_directory(
	$provided_source_state_output_dir,
	array(
		'max_pages'                       => 1,
		'copy_uploads'                    => false,
		'copy_theme'                      => false,
		'copy_plugins'                    => false,
		'copy_core_assets'                => false,
		'include_manifest'                => false,
		'include_playground_source_state' => true,
		'playground_source_wxr_url'       => $provided_wxr_url,
		'playground_source_expires_at'    => $provided_expires_at,
		'progress_callback'               => null,
	)
);

$provided_source_state_data     = json_decode( file_get_contents( $provided_source_state_output_dir . '/_playground-source/source-state.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$provided_source_manifest_data  = json_decode( file_get_contents( $provided_source_state_output_dir . '/wp-admin/playground-source-manifest.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$provided_source_blueprint_data = json_decode( file_get_contents( $provided_source_state_output_dir . '/wp-admin/playground-blueprint.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$provided_source_handoff        = file_get_contents( $provided_source_state_output_dir . '/wp-admin/index.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$provided_source_bundle_path    = $provided_source_state_output_dir . '/_playground-source/playground-blueprint-bundle.zip';
$provided_source_bundle_zip     = new ZipArchive();

ssgwp_assert_same(
	true,
	true === $provided_source_bundle_zip->open( $provided_source_bundle_path ),
	'The provided-WXR source-state export writes an inspectable Playground Blueprint bundle.'
);

$provided_source_bundle_blueprint_json = $provided_source_bundle_zip->getFromName( 'blueprint.json' );
$provided_source_bundle_metadata_json  = $provided_source_bundle_zip->getFromName( 'source-state.json' );
$provided_source_bundle_zip->close();
$provided_source_bundle_blueprint = json_decode( $provided_source_bundle_blueprint_json, true );

ssgwp_assert_same(
	'provided-url',
	$provided_source_state_data['restore']['wxr_url_mode'],
	'source-state.json records provided-url mode when a WXR URL is supplied.'
);

ssgwp_assert_same(
	$provided_wxr_url,
	$provided_source_state_data['restore']['effective_wxr_url'],
	'source-state.json records the provided effective WXR URL.'
);

ssgwp_assert_same(
	$provided_expires_at,
	$provided_source_state_data['restore']['source_access_expires_at'],
	'source-state.json records a valid provided source access expiry.'
);

ssgwp_assert_same(
	'valid',
	$provided_source_state_data['restore']['source_access_expires_at_status'],
	'source-state.json records that the provided source access expiry is valid.'
);

ssgwp_assert_same(
	$provided_wxr_url,
	$provided_source_manifest_data['source_state']['effective_wxr_url'],
	'The Playground source manifest records the provided effective WXR URL.'
);

ssgwp_assert_same(
	'provided-url',
	$provided_source_manifest_data['source_state']['wxr_url_mode'],
	'The Playground source manifest records provided-url mode when a WXR URL is supplied.'
);

ssgwp_assert_same(
	false,
	isset( $provided_source_manifest_data['source_state']['wxr_url_runtime_expression'] ),
	'The Playground source manifest omits the runtime-relative expression when a WXR URL is supplied.'
);

ssgwp_assert_contains(
	'provided WXR URL is stored as the effective handoff URL',
	$provided_source_manifest_data['source_state']['owner_access_policy']['provided_url_sensitivity_note'],
	'The Playground source manifest records the provided URL sensitivity note.'
);

ssgwp_assert_same(
	false,
	isset( $provided_source_blueprint_data['stillpress'] ),
	'The provided-WXR source-state Blueprint avoids custom root metadata rejected by Playground schema validation.'
);

ssgwp_assert_same(
	'provided-url',
	$provided_source_manifest_data['source_state']['wxr_url_mode'],
	'The Playground source manifest records provided-url mode when a WXR URL is supplied.'
);

ssgwp_assert_same(
	$provided_wxr_url,
	$provided_source_manifest_data['source_state']['effective_wxr_url'],
	'The Playground source manifest records the effective provided WXR URL.'
);

ssgwp_assert_same(
	array(
		'resource' => 'bundled',
		'path' => '/content/site-content.wxr',
	),
	$provided_source_bundle_blueprint['steps'][1]['file'],
	'The provided-WXR Blueprint bundle still imports WXR from a bundled resource.'
);

ssgwp_assert_not_contains(
	$provided_wxr_url,
	$provided_source_bundle_blueprint_json,
	'The provided-WXR Blueprint bundle does not contain the signed WXR URL.'
);

ssgwp_assert_not_contains(
	$provided_wxr_url,
	$provided_source_bundle_metadata_json,
	'The provided-WXR bundled source-state metadata does not contain the signed WXR URL.'
);

$provided_source_handoff_context = ssgwp_decode_wp_cli_option_update_json(
	$provided_source_blueprint_data['steps'][2]['command'],
	'ssgwp_playground_source_handoff'
);

ssgwp_assert_same(
	'provided-url',
	$provided_source_handoff_context['wxr']['url_mode'],
	'The restored Playground source handoff context records provided-url mode when a WXR URL is supplied.'
);

ssgwp_assert_same(
	$provided_expires_at,
	$provided_source_handoff_context['source_access']['expires_at'],
	'The restored Playground source handoff context records source access expiry metadata when supplied.'
);

ssgwp_assert_same(
	false,
	isset( $provided_source_handoff_context['effective_wxr_url'] )
		|| isset( $provided_source_handoff_context['wxr']['effective_wxr_url'] )
		|| isset( $provided_source_handoff_context['wxr']['url'] ),
	'The restored Playground source handoff context omits explicit provided WXR URL fields.'
);

ssgwp_assert_not_contains(
	$provided_wxr_url,
	wp_json_encode( $provided_source_handoff_context, JSON_UNESCAPED_SLASHES ),
	'The restored Playground source handoff context does not store the explicit provided WXR URL.'
);

ssgwp_assert_contains(
	'var wxrUrl="https://owner.example.test/playground/site-content.wxr?signature=abc123";',
	$provided_source_handoff,
	'The static admin handoff uses the provided WXR URL in the inline Blueprint script.'
);

ssgwp_assert_not_contains(
	'var wxrUrl=new URL("../_playground-source/site-content.wxr", window.location.href).href;',
	$provided_source_handoff,
	'The static admin handoff does not compute a runtime-relative WXR URL when a WXR URL is supplied.'
);

ssgwp_assert_same(
	$provided_wxr_url,
	$provided_source_state_result['playground_admin']['effective_wxr_url'],
	'export_to_directory reports the effective provided WXR URL through the Playground admin summary.'
);

ssgwp_assert_same(
	'valid',
	$provided_source_state_result['playground_source_state']['source_access_expires_at_status'],
	'export_to_directory reports a valid provided source access expiry.'
);

$invalid_expiry_output_dir = $fixture_root . '/source-state-invalid-expiry-export';
$exporter->export_to_directory(
	$invalid_expiry_output_dir,
	array(
		'max_pages'                       => 1,
		'copy_uploads'                    => false,
		'copy_theme'                      => false,
		'copy_plugins'                    => false,
		'copy_core_assets'                => false,
		'include_manifest'                => false,
		'include_playground_source_state' => true,
		'playground_source_expires_at'    => '2026-99-99T99:99:99Z',
		'progress_callback'               => null,
	)
);

$invalid_expiry_source_state_data = json_decode( file_get_contents( $invalid_expiry_output_dir . '/_playground-source/source-state.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$invalid_expiry_manifest_data     = json_decode( file_get_contents( $invalid_expiry_output_dir . '/wp-admin/playground-source-manifest.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

ssgwp_assert_same(
	null,
	$invalid_expiry_source_state_data['restore']['source_access_expires_at'],
	'source-state.json does not emit an invalid source access expiry as authoritative.'
);

ssgwp_assert_same(
	'invalid-ignored',
	$invalid_expiry_source_state_data['restore']['source_access_expires_at_status'],
	'source-state.json records an invalid source access expiry as ignored.'
);

ssgwp_assert_same(
	null,
	$invalid_expiry_manifest_data['source_state']['source_access_expires_at'],
	'The Playground source manifest does not emit an invalid source access expiry as authoritative.'
);

ssgwp_assert_same(
	'invalid-ignored',
	$invalid_expiry_manifest_data['source_state']['source_access_expires_at_status'],
	'The Playground source manifest records an invalid source access expiry as ignored.'
);

$source_state_symlink_output_dir = $fixture_root . '/source-state-symlink-export';
$source_state_symlink_target_dir = $fixture_root . '/source-state-symlink-target';

if (
	! wp_mkdir_p( $source_state_symlink_output_dir . '/_playground-source' )
	|| ! wp_mkdir_p( $source_state_symlink_target_dir )
) {
	ssgwp_fail( 'Could not create the source-state symlink regression fixture directories.' );
}

$source_state_external_json = $source_state_symlink_target_dir . '/source-state-sentinel.json';
$source_state_external_wxr  = $source_state_symlink_target_dir . '/site-content-sentinel.wxr';

file_put_contents( $source_state_external_json, 'external source-state sentinel' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( $source_state_external_wxr, 'external wxr sentinel' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

$source_state_json_symlink = $source_state_symlink_output_dir . '/_playground-source/source-state.json';
$source_state_wxr_symlink  = $source_state_symlink_output_dir . '/_playground-source/site-content.wxr';
$source_state_symlinks_created = function_exists( 'symlink' )
	&& @symlink( $source_state_external_json, $source_state_json_symlink )
	&& @symlink( $source_state_external_wxr, $source_state_wxr_symlink );

if ( ! $source_state_symlinks_created ) {
	ssgwp_skip( 'Source-state leaf symlink regression requires symlink() support.' );
} else {
	$exporter->export_to_directory(
		$source_state_symlink_output_dir,
		array(
			'max_pages'                       => 1,
			'copy_uploads'                    => false,
			'copy_theme'                      => false,
			'copy_plugins'                    => false,
			'copy_core_assets'                => false,
			'include_manifest'                => false,
			'include_playground_source_state' => true,
			'progress_callback'               => null,
		)
	);

	ssgwp_assert_same(
		'external source-state sentinel',
		file_get_contents( $source_state_external_json ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		'Source-state export does not overwrite the external target of a preexisting source-state.json symlink.'
	);

	ssgwp_assert_same(
		'external wxr sentinel',
		file_get_contents( $source_state_external_wxr ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		'Source-state export does not overwrite the external target of a preexisting site-content.wxr symlink.'
	);

	ssgwp_assert_same(
		false,
		is_link( $source_state_json_symlink ),
		'Source-state export replaces a preexisting source-state.json symlink with a real generated file.'
	);

	ssgwp_assert_same(
		false,
		is_link( $source_state_wxr_symlink ),
		'Source-state export replaces a preexisting site-content.wxr symlink with a real generated file.'
	);

	$source_state_symlink_data = json_decode( file_get_contents( $source_state_json_symlink ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	ssgwp_assert_same(
		'source-state-generated',
		$source_state_symlink_data['restore']['status'],
		'Source-state export writes generated JSON after replacing a leaf symlink.'
	);

	ssgwp_assert_contains(
		'<rss',
		file_get_contents( $source_state_wxr_symlink ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		'Source-state export writes generated WXR after replacing a leaf symlink.'
	);
}

if ( null === $previous_request_method ) {
	unset( $_SERVER['REQUEST_METHOD'] );
} else {
	$_SERVER['REQUEST_METHOD'] = $previous_request_method;
}

$ssgwp_test_posts = array();
$ssgwp_test_http_responses = array();
$ssgwp_test_active_plugins = array(
	'static-site-generator/static-site-generator.php',
);
$ssgwp_test_network_active_plugins = array();

$manifest_output_dir = $fixture_root . '/manifest-export';
$manifest_result     = $exporter->export_to_directory(
	$manifest_output_dir,
	array(
		'max_pages'         => 1,
		'copy_uploads'      => false,
		'copy_theme'        => false,
		'copy_plugins'      => false,
		'copy_core_assets'  => false,
		'progress_callback' => null,
	)
);
$manifest_path       = $manifest_output_dir . '/static-export.json';

ssgwp_assert_same(
	true,
	file_exists( $manifest_path ),
	'export_to_directory writes static-export.json by default.'
);

$manifest_data = json_decode( file_get_contents( $manifest_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

ssgwp_assert_same(
	true,
	is_array( $manifest_data ),
	'static-export.json contains valid JSON.'
);

ssgwp_assert_same(
	array(
		'generated_at',
		'home_url',
		'pages_exported',
		'files_exported',
		'exported_urls',
		'warnings',
		'wordpress',
		'plugin_version',
		'url_mode',
		'generated_sitemap',
		'generated_robots',
		'progress',
		'playground_note',
	),
	array_keys( $manifest_data ),
	'static-export.json keeps the expected manifest fields.'
);

ssgwp_assert_same(
	$manifest_result['generated_at'],
	$manifest_data['generated_at'],
	'static-export.json records the generation timestamp.'
);

ssgwp_assert_same(
	'https://example.test/',
	$manifest_data['home_url'],
	'static-export.json records the home URL.'
);

ssgwp_assert_same(
	$manifest_result['exported_urls'],
	$manifest_data['exported_urls'],
	'static-export.json records exported URLs.'
);

ssgwp_assert_same(
	1,
	$manifest_data['pages_exported'],
	'static-export.json records the exported page count.'
);

ssgwp_assert_same(
	'0.1.0',
	$manifest_data['plugin_version'],
	'static-export.json records the plugin version.'
);

ssgwp_assert_same(
	'6.9.4',
	$manifest_data['wordpress'],
	'static-export.json records the WordPress version.'
);

ssgwp_assert_same(
	$manifest_result['files_exported'],
	$manifest_data['files_exported'],
	'static-export.json records the exported file count.'
);

ssgwp_assert_same(
	$manifest_result['warnings'],
	$manifest_data['warnings'],
	'static-export.json records export warnings.'
);

ssgwp_assert_same(
	'relative',
	$manifest_data['url_mode'],
	'static-export.json records the URL mode.'
);

ssgwp_assert_same(
	false,
	$manifest_data['generated_sitemap'],
	'static-export.json records when sitemap.xml was not generated.'
);

ssgwp_assert_same(
	false,
	$manifest_data['generated_robots'],
	'static-export.json records when robots.txt was not generated.'
);

ssgwp_assert_same(
	true,
	! empty( $manifest_data['progress'] ),
	'static-export.json records progress events.'
);

ssgwp_assert_same(
	$manifest_result['progress'],
	$manifest_data['progress'],
	'static-export.json records the same progress events returned to callers.'
);

ssgwp_assert_same(
	'complete',
	$manifest_data['progress'][ count( $manifest_data['progress'] ) - 1 ]['stage'],
	'static-export.json records the completion progress event.'
);

ssgwp_assert_contains(
	'WordPress Playground',
	$manifest_data['playground_note'],
	'static-export.json explains that the editable Playground site should be kept separately.'
);

$publishing_output_dir = $fixture_root . '/publishing-export';
$publishing_result     = $exporter->export_to_directory(
	$publishing_output_dir,
	array(
		'max_pages'                  => 1,
		'copy_uploads'               => false,
		'copy_theme'                 => false,
		'copy_plugins'               => false,
		'copy_core_assets'           => false,
		'include_manifest'           => false,
		'include_playground_admin'   => true,
		'include_cloudflare_publish'    => true,
		'cloudflare_worker_name'        => 'docs-site-2026',
		'cloudflare_compatibility_date' => '2026-99-99',
		'progress_callback'             => null,
	)
);

ssgwp_assert_same(
	true,
	file_exists( $publishing_output_dir . '/wp-admin/index.html' ),
	'export_to_directory writes the opt-in static /wp-admin/ Playground handoff route.'
);

ssgwp_assert_same(
	true,
	file_exists( $publishing_output_dir . '/wp-admin/playground-blueprint.json' ),
	'export_to_directory writes a Playground Blueprint for the admin handoff.'
);

ssgwp_assert_same(
	true,
	file_exists( $publishing_output_dir . '/wp-admin/playground-source-manifest.json' ),
	'export_to_directory writes the Playground source-state manifest.'
);

$playground_handoff = file_get_contents( $publishing_output_dir . '/wp-admin/index.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$playground_manifest = json_decode( file_get_contents( $publishing_output_dir . '/wp-admin/playground-source-manifest.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$playground_blueprint = json_decode( file_get_contents( $publishing_output_dir . '/wp-admin/playground-blueprint.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

ssgwp_assert_contains(
	'https://playground.wordpress.net/?blueprint-url=',
	$playground_handoff,
	'The static admin handoff points to WordPress Playground with a Blueprint URL.'
);

ssgwp_assert_not_contains(
	'new URL("../_playground-source/site-content.wxr", window.location.href).href',
	$playground_handoff,
	'The non-source-state admin handoff does not compute a WXR URL.'
);

ssgwp_assert_contains(
	'wp-admin/playground-source-manifest.json',
	$playground_handoff,
	'The static admin handoff documents the deterministic source-state manifest pointer.'
);

ssgwp_assert_same(
	'manifest-pointer-only',
	$playground_manifest['source_state']['status'],
	'The Playground source manifest records the first-slice manifest-pointer state.'
);

ssgwp_assert_same(
	'blueprint-url',
	$playground_manifest['handoff']['blueprint_url_mode'],
	'The non-source-state Playground source manifest keeps the remote Blueprint URL mode.'
);

ssgwp_assert_same(
	'../_cloudflare-publish/cloudflare-publish.json',
	$playground_manifest['publish']['cloudflare_manifest_path'],
	'The Playground source manifest links to the Cloudflare publish contract when it is enabled.'
);

ssgwp_assert_same(
	false,
	$playground_manifest['static_export']['manifest_included'],
	'The Playground source manifest does not force static-export.json when manifest output is disabled.'
);

ssgwp_assert_same(
	'/wp-admin/tools.php?page=playground-static-site-generator',
	$playground_blueprint['landingPage'],
	'The Playground Blueprint lands owners on the StillPress export screen.'
);

ssgwp_assert_same(
	false,
	isset( $playground_blueprint['stillpress'] ),
	'The non-source-state Playground Blueprint avoids custom root metadata rejected by Playground schema validation.'
);

foreach ( $playground_blueprint['steps'] as $playground_step ) {
	if ( isset( $playground_step['step'] ) && 'importWxr' === $playground_step['step'] ) {
		ssgwp_fail( 'The non-source-state Playground Blueprint should not include an importWxr step.' );
	}

	if (
		isset( $playground_step['step'], $playground_step['command'] )
		&& 'wp-cli' === $playground_step['step']
		&& false !== strpos( $playground_step['command'], 'ssgwp_playground_source_handoff' )
	) {
		ssgwp_fail( 'The non-source-state Playground Blueprint should not write the source handoff context option.' );
	}
}

ssgwp_assert_same(
	'wp-admin/index.html',
	$publishing_result['playground_admin']['handoff_path'],
	'export_to_directory reports the admin handoff path.'
);

ssgwp_assert_same(
	true,
	file_exists( $publishing_output_dir . '/_cloudflare-publish/cloudflare-publish.json' ),
	'export_to_directory writes the Cloudflare publish manifest in the deploy package.'
);

ssgwp_assert_same(
	true,
	file_exists( $publishing_output_dir . '/_cloudflare-publish/wrangler.jsonc' ),
	'export_to_directory writes a Wrangler config in the deploy package.'
);

ssgwp_assert_same(
	true,
	file_exists( $publishing_output_dir . '/_cloudflare-publish/cloudflare-worker.js' ),
	'export_to_directory writes a Worker script in the deploy package.'
);

ssgwp_assert_same(
	true,
	file_exists( $publishing_output_dir . '/_cloudflare-publish/package.json' ),
	'export_to_directory writes package.json in the Cloudflare deploy package.'
);

ssgwp_assert_same(
	true,
	file_exists( $publishing_output_dir . '/_cloudflare-publish/cloudflare-deploy-check.mjs' ),
	'export_to_directory writes the local Cloudflare deploy check script in the deploy package.'
);

$cloudflare_deploy_dir = $publishing_output_dir . '/_cloudflare-publish';
$cloudflare_asset_dir  = $cloudflare_deploy_dir . '/site';
$cloudflare_manifest   = file_get_contents( $cloudflare_deploy_dir . '/cloudflare-publish.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$cloudflare_data       = json_decode( $cloudflare_manifest, true );
$cloudflare_readme     = file_get_contents( $cloudflare_deploy_dir . '/CLOUDFLARE-WORKERS.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$cloudflare_package    = json_decode( file_get_contents( $cloudflare_deploy_dir . '/package.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$cloudflare_check      = file_get_contents( $cloudflare_deploy_dir . '/cloudflare-deploy-check.mjs' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$wrangler_data         = json_decode( file_get_contents( $cloudflare_deploy_dir . '/wrangler.jsonc' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$expected_cloudflare_package_scripts = array(
	'validate:offline' => 'node cloudflare-deploy-check.mjs --offline',
	'validate:credentials' => 'node cloudflare-deploy-check.mjs --require-credentials',
	'deploy:dry-run' => 'npx wrangler deploy --config wrangler.jsonc --dry-run',
	'deploy' => 'npx wrangler deploy --config wrangler.jsonc',
	'versions' => 'npx wrangler versions list --config wrangler.jsonc',
	'deployments' => 'npx wrangler deployments list --config wrangler.jsonc',
	'rollback' => 'npx wrangler rollback --config wrangler.jsonc',
);

ssgwp_assert_same(
	'_cloudflare-publish/site',
	$publishing_result['cloudflare_publish']['asset_directory'],
	'export_to_directory reports the Cloudflare served asset directory.'
);

ssgwp_assert_same(
	'./site',
	$wrangler_data['assets']['directory'],
	'The Wrangler config serves only the nested site asset directory.'
);

ssgwp_assert_same(
	true,
	file_exists( $cloudflare_asset_dir . '/index.html' ),
	'The Cloudflare served asset directory contains the static site.'
);

foreach ( array( 'cloudflare-worker.js', 'wrangler.jsonc', 'cloudflare-publish.json', 'CLOUDFLARE-WORKERS.md' ) as $cloudflare_control_file ) {
	ssgwp_assert_same(
		false,
		file_exists( $cloudflare_asset_dir . '/' . $cloudflare_control_file ),
		'The Cloudflare served asset directory excludes deploy control file ' . $cloudflare_control_file . '.'
	);
}

foreach ( array( 'package.json', 'cloudflare-deploy-check.mjs' ) as $cloudflare_workflow_file ) {
	ssgwp_assert_same(
		false,
		file_exists( $cloudflare_asset_dir . '/' . $cloudflare_workflow_file ),
		'The Cloudflare served asset directory excludes deploy workflow file ' . $cloudflare_workflow_file . '.'
	);
}

ssgwp_assert_same(
	false,
	file_exists( $cloudflare_asset_dir . '/_cloudflare-publish' ),
	'The Cloudflare served asset directory does not copy the deploy package into itself.'
);

$root_cloudflare_control_exists = file_exists( $publishing_output_dir . '/cloudflare-publish.json' )
	|| file_exists( $publishing_output_dir . '/wrangler.jsonc' )
	|| file_exists( $publishing_output_dir . '/cloudflare-worker.js' )
	|| file_exists( $publishing_output_dir . '/CLOUDFLARE-WORKERS.md' )
	|| file_exists( $publishing_output_dir . '/package.json' )
	|| file_exists( $publishing_output_dir . '/cloudflare-deploy-check.mjs' );

ssgwp_assert_same(
	false,
	$root_cloudflare_control_exists,
	'Cloudflare deploy control files are not written at the export root.'
);

$cloudflare_symlink_output_dir = $fixture_root . '/cloudflare-symlink-export';
$cloudflare_symlink_target_dir = $fixture_root . '/cloudflare-symlink-target';

if ( ! wp_mkdir_p( $cloudflare_symlink_output_dir ) || ! wp_mkdir_p( $cloudflare_symlink_target_dir ) ) {
	ssgwp_fail( 'Could not create the Cloudflare symlink regression fixture directories.' );
}

$cloudflare_symlink_sentinel = $cloudflare_symlink_target_dir . '/sentinel.txt';
file_put_contents( $cloudflare_symlink_sentinel, 'outside target must remain untouched' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

$cloudflare_symlink_deploy_dir = $cloudflare_symlink_output_dir . '/_cloudflare-publish';
$cloudflare_deploy_symlink_created = function_exists( 'symlink' )
	&& @symlink( $cloudflare_symlink_target_dir, $cloudflare_symlink_deploy_dir );

if ( ! $cloudflare_deploy_symlink_created ) {
	ssgwp_skip( 'Cloudflare deploy directory symlink regression requires symlink() support.' );
} else {
	$exporter->export_to_directory(
		$cloudflare_symlink_output_dir,
		array(
			'max_pages'                  => 1,
			'copy_uploads'               => false,
			'copy_theme'                 => false,
			'copy_plugins'               => false,
			'copy_core_assets'           => false,
			'include_manifest'           => false,
			'include_cloudflare_publish' => true,
			'progress_callback'          => null,
		)
	);

	ssgwp_assert_same(
		'outside target must remain untouched',
		file_get_contents( $cloudflare_symlink_sentinel ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		'Cloudflare deploy cleanup does not touch files in a symlink target outside the export root.'
	);

	ssgwp_assert_same(
		false,
		is_link( $cloudflare_symlink_deploy_dir ),
		'Cloudflare deploy cleanup replaces a preexisting symlink with a real deploy directory.'
	);

	ssgwp_assert_same(
		true,
		is_dir( $cloudflare_symlink_deploy_dir ),
		'Cloudflare deploy cleanup creates a real deploy directory after unlinking the symlink.'
	);

	ssgwp_assert_same(
		true,
		file_exists( $cloudflare_symlink_deploy_dir . '/cloudflare-publish.json' ),
		'Cloudflare deploy cleanup writes generated publish files after replacing the symlink.'
	);

	ssgwp_assert_same(
		true,
		file_exists( $cloudflare_symlink_deploy_dir . '/site/index.html' ),
		'Cloudflare deploy cleanup writes served static assets after replacing the symlink.'
	);
}

ssgwp_assert_same(
	'_cloudflare-publish/site',
	$cloudflare_data['artifacts']['asset_directory'],
	'The Cloudflare publish manifest records the served asset directory.'
);

ssgwp_assert_same(
	'./site',
	$cloudflare_data['artifacts']['asset_directory_from_wrangler_config'],
	'The Cloudflare publish manifest records the Wrangler-relative asset directory.'
);

ssgwp_assert_same(
	'_cloudflare-publish/package.json',
	$cloudflare_data['artifacts']['package_json'],
	'The Cloudflare publish manifest records the generated package.json path.'
);

ssgwp_assert_same(
	'_cloudflare-publish/cloudflare-deploy-check.mjs',
	$cloudflare_data['artifacts']['deploy_check_script'],
	'The Cloudflare publish manifest records the generated deploy check script path.'
);

ssgwp_assert_same(
	'2026-06-08',
	$wrangler_data['compatibility_date'],
	'Invalid Cloudflare compatibility dates fall back to the default date in Wrangler config.'
);

ssgwp_assert_same(
	'2026-06-08',
	$cloudflare_data['wrangler']['compatibility_date'],
	'Invalid Cloudflare compatibility dates fall back to the default date in the publish manifest.'
);

ssgwp_assert_same(
	array( 'CLOUDFLARE_ACCOUNT_ID', 'CLOUDFLARE_API_TOKEN' ),
	$cloudflare_data['credentials']['required_environment_variables'],
	'The Cloudflare manifest documents the required deploy environment variables.'
);

ssgwp_assert_same(
	array( 'Workers Scripts:Edit' ),
	$cloudflare_data['credentials']['account_permissions'],
	'The Cloudflare manifest documents the required account permission.'
);

ssgwp_assert_same(
	array( 'Workers Routes:Edit', 'Zone:Read' ),
	$cloudflare_data['credentials']['zone_permissions_for_routes_or_custom_domains'],
	'The Cloudflare manifest documents route/custom-domain zone permissions.'
);

ssgwp_assert_same(
	false,
	$cloudflare_data['network_calls'],
	'The Cloudflare manifest records that export generation does not call Cloudflare.'
);

$cloudflare_asset_inventory = ssgwp_directory_file_inventory( $cloudflare_asset_dir );

ssgwp_assert_same(
	$cloudflare_asset_inventory,
	$cloudflare_data['asset_inventory'],
	'The Cloudflare manifest records the served asset directory file count and largest file size.'
);

ssgwp_assert_same(
	$cloudflare_asset_inventory,
	$publishing_result['cloudflare_publish']['asset_inventory'],
	'export_to_directory reports the served asset directory file count and largest file size.'
);

ssgwp_assert_same(
	100000,
	$cloudflare_data['free_tier_limits']['requests_per_day'],
	'The Cloudflare manifest records the Workers Free daily request limit.'
);

ssgwp_assert_same(
	20000,
	$cloudflare_data['free_tier_limits']['static_asset_files_per_worker_version'],
	'The Cloudflare manifest records the Workers Free static asset count limit.'
);

ssgwp_assert_contains(
	'node cloudflare-deploy-check.mjs --offline',
	$cloudflare_check,
	'The deploy check script supports offline validation.'
);

ssgwp_assert_contains(
	'CLOUDFLARE_ACCOUNT_ID',
	$cloudflare_check,
	'The deploy check script validates credential variable presence without printing values.'
);

ssgwp_assert_same(
	$expected_cloudflare_package_scripts,
	$cloudflare_package['scripts'],
	'package.json exposes the expected Cloudflare deploy workflow scripts.'
);

ssgwp_assert_same(
	'generated',
	$cloudflare_data['deploy_workflow']['status'],
	'The Cloudflare manifest records that the deploy workflow was generated.'
);

ssgwp_assert_same(
	$expected_cloudflare_package_scripts,
	$cloudflare_data['deploy_workflow']['package_scripts'],
	'The Cloudflare manifest records the package.json deploy workflow scripts.'
);

ssgwp_assert_same(
	'cd _cloudflare-publish && npm run validate:offline',
	$cloudflare_data['deploy_workflow']['commands']['offline_validation'],
	'The Cloudflare manifest records the offline validation command.'
);

ssgwp_assert_same(
	'cd _cloudflare-publish && npm run validate:credentials',
	$cloudflare_data['deploy_workflow']['commands']['credentials_validation'],
	'The Cloudflare manifest records the credential validation command.'
);

ssgwp_assert_same(
	'cd _cloudflare-publish && npm run deploy:dry-run',
	$cloudflare_data['deploy_workflow']['commands']['dry_run_deploy'],
	'The Cloudflare manifest records the Wrangler dry-run deploy command.'
);

ssgwp_assert_same(
	'cd _cloudflare-publish && npm run deploy',
	$cloudflare_data['deploy_workflow']['commands']['deploy'],
	'The Cloudflare manifest records the real Wrangler deploy command.'
);

ssgwp_assert_same(
	'cd _cloudflare-publish && npm run versions',
	$cloudflare_data['deploy_workflow']['commands']['versions_list'],
	'The Cloudflare manifest records the Wrangler versions list command.'
);

ssgwp_assert_same(
	'cd _cloudflare-publish && npm run deployments',
	$cloudflare_data['deploy_workflow']['commands']['deployments_list'],
	'The Cloudflare manifest records the Wrangler deployments list command.'
);

ssgwp_assert_same(
	'cd _cloudflare-publish && npm run rollback',
	$cloudflare_data['deploy_workflow']['commands']['rollback'],
	'The Cloudflare manifest records the Wrangler rollback command.'
);

ssgwp_assert_same(
	false,
	$cloudflare_data['local_validation']['offline']['requires_credentials'],
	'The Cloudflare manifest records that offline validation does not require credentials.'
);

ssgwp_assert_same(
	true,
	$cloudflare_data['local_validation']['credentials']['requires_credentials'],
	'The Cloudflare manifest records that credential validation requires credentials.'
);

ssgwp_assert_same(
	false,
	$cloudflare_data['local_validation']['credentials']['network_calls'],
	'The Cloudflare manifest records that credential validation does not call Cloudflare.'
);

ssgwp_assert_same(
	true,
	$cloudflare_data['deploy_workflow']['wrangler_commands_may_call_cloudflare'],
	'The Cloudflare manifest records that generated Wrangler commands may call Cloudflare when run.'
);

foreach ( array( 'offline validation', 'Credential validation', 'dry-run deploy', 'deploy', 'versions list', 'deployments list', 'rollback' ) as $cloudflare_doc_term ) {
	ssgwp_assert_contains(
		$cloudflare_doc_term,
		$cloudflare_readme,
		'The Cloudflare README documents workflow step ' . $cloudflare_doc_term . '.'
	);
}

ssgwp_assert_contains(
	'cd _cloudflare-publish && npm run deploy',
	$cloudflare_readme,
	'The Cloudflare README documents the local deploy command without running it.'
);

ssgwp_assert_contains(
	'Workers Scripts:Edit',
	$cloudflare_readme,
	'The Cloudflare README documents the deploy token permission.'
);

$node_binary = ssgwp_find_executable( 'node' );

if ( null === $node_binary || ! function_exists( 'proc_open' ) ) {
	ssgwp_skip( 'Cloudflare deploy check offline execution requires node and proc_open().' );
} else {
	$cloudflare_offline_check = ssgwp_run_process(
		array( $node_binary, 'cloudflare-deploy-check.mjs', '--offline' ),
		$cloudflare_deploy_dir
	);

	ssgwp_assert_same(
		0,
		$cloudflare_offline_check['exit_code'],
		'The Cloudflare deploy check script passes in offline mode. Stdout: ' . $cloudflare_offline_check['stdout'] . ' Stderr: ' . $cloudflare_offline_check['stderr']
	);

	ssgwp_assert_contains(
		'Cloudflare deploy package check passed (offline).',
		$cloudflare_offline_check['stdout'],
		'The Cloudflare deploy check script reports a successful offline package validation.'
	);
}

$default_cloudflare_output_dir = $fixture_root . '/publishing-export-default-manifest';
$default_cloudflare_result     = $exporter->export_to_directory(
	$default_cloudflare_output_dir,
	array(
		'max_pages'                  => 1,
		'copy_uploads'               => false,
		'copy_theme'                 => false,
		'copy_plugins'               => false,
		'copy_core_assets'           => false,
		'include_cloudflare_publish' => true,
		'progress_callback'          => null,
	)
);

$default_cloudflare_deploy_dir      = $default_cloudflare_output_dir . '/_cloudflare-publish';
$default_cloudflare_asset_dir       = $default_cloudflare_deploy_dir . '/site';
$default_cloudflare_manifest_path   = $default_cloudflare_deploy_dir . '/cloudflare-publish.json';
$default_cloudflare_manifest        = file_get_contents( $default_cloudflare_manifest_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$default_cloudflare_data            = json_decode( $default_cloudflare_manifest, true );
$default_cloudflare_asset_inventory = ssgwp_directory_file_inventory( $default_cloudflare_asset_dir );
$default_static_manifest_data       = json_decode( file_get_contents( $default_cloudflare_output_dir . '/static-export.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$default_asset_static_manifest_data = json_decode( file_get_contents( $default_cloudflare_asset_dir . '/static-export.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

ssgwp_assert_same(
	true,
	file_exists( $default_cloudflare_output_dir . '/static-export.json' ),
	'Default Cloudflare exports keep the root static-export.json manifest.'
);

ssgwp_assert_same(
	true,
	file_exists( $default_cloudflare_asset_dir . '/static-export.json' ),
	'Default Cloudflare exports copy static-export.json into the served asset directory.'
);

ssgwp_assert_same(
	$default_cloudflare_asset_inventory,
	$default_cloudflare_data['asset_inventory'],
	'Default Cloudflare exports record the final served asset directory inventory after static-export.json is copied.'
);

ssgwp_assert_same(
	$default_cloudflare_asset_inventory,
	$default_cloudflare_result['cloudflare_publish']['asset_inventory'],
	'Default Cloudflare exports return the final served asset directory inventory after static-export.json is copied.'
);

ssgwp_assert_same(
	$default_cloudflare_asset_inventory,
	$default_static_manifest_data['cloudflare_publish']['asset_inventory'],
	'Default Cloudflare exports keep the root static-export.json Cloudflare inventory in sync with the final served asset directory.'
);

ssgwp_assert_same(
	$default_cloudflare_asset_inventory,
	$default_asset_static_manifest_data['cloudflare_publish']['asset_inventory'],
	'Default Cloudflare exports keep the served static-export.json Cloudflare inventory in sync with the final served asset directory.'
);

ssgwp_assert_same(
	false,
	$default_cloudflare_data['network_calls'],
	'Default Cloudflare exports still record that export generation does not call Cloudflare.'
);

if ( null === $node_binary || ! function_exists( 'proc_open' ) ) {
	ssgwp_skip( 'Default Cloudflare deploy check offline execution requires node and proc_open().' );
} else {
	$default_cloudflare_offline_check = ssgwp_run_process(
		array( $node_binary, 'cloudflare-deploy-check.mjs', '--offline' ),
		$default_cloudflare_deploy_dir
	);

	ssgwp_assert_same(
		0,
		$default_cloudflare_offline_check['exit_code'],
		'The default Cloudflare deploy check script passes in offline mode. Stdout: ' . $default_cloudflare_offline_check['stdout'] . ' Stderr: ' . $default_cloudflare_offline_check['stderr']
	);

	ssgwp_assert_contains(
		'Cloudflare deploy package check passed (offline).',
		$default_cloudflare_offline_check['stdout'],
		'The default Cloudflare deploy check script reports a successful offline package validation.'
	);
}

$publishing_output_dir_second = $fixture_root . '/publishing-export-second';
$exporter->export_to_directory(
	$publishing_output_dir_second,
	array(
		'max_pages'                  => 1,
		'copy_uploads'               => false,
		'copy_theme'                 => false,
		'copy_plugins'               => false,
		'copy_core_assets'           => false,
		'include_manifest'           => false,
		'include_playground_admin'   => true,
		'include_cloudflare_publish'    => true,
		'cloudflare_worker_name'        => 'docs-site-2026',
		'cloudflare_compatibility_date' => '2026-99-99',
		'progress_callback'             => null,
	)
);

ssgwp_assert_same(
	$cloudflare_manifest,
	file_get_contents( $publishing_output_dir_second . '/_cloudflare-publish/cloudflare-publish.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	'The Cloudflare publish manifest is deterministic across identical local exports.'
);

ssgwp_assert_same(
	file_get_contents( $cloudflare_deploy_dir . '/wrangler.jsonc' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	file_get_contents( $publishing_output_dir_second . '/_cloudflare-publish/wrangler.jsonc' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	'The Wrangler config is deterministic across identical local exports.'
);

ssgwp_assert_same(
	file_get_contents( $cloudflare_deploy_dir . '/cloudflare-worker.js' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	file_get_contents( $publishing_output_dir_second . '/_cloudflare-publish/cloudflare-worker.js' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	'The Cloudflare Worker script is deterministic across identical local exports.'
);

ssgwp_assert_same(
	file_get_contents( $cloudflare_deploy_dir . '/package.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	file_get_contents( $publishing_output_dir_second . '/_cloudflare-publish/package.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	'The Cloudflare deploy package.json is deterministic across identical local exports.'
);

ssgwp_assert_same(
	file_get_contents( $cloudflare_deploy_dir . '/cloudflare-deploy-check.mjs' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	file_get_contents( $publishing_output_dir_second . '/_cloudflare-publish/cloudflare-deploy-check.mjs' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	'The Cloudflare deploy check script is deterministic across identical local exports.'
);

ssgwp_assert_same(
	file_get_contents( $cloudflare_deploy_dir . '/CLOUDFLARE-WORKERS.md' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	file_get_contents( $publishing_output_dir_second . '/_cloudflare-publish/CLOUDFLARE-WORKERS.md' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	'The Cloudflare README is deterministic across identical local exports.'
);

$seo_output_dir = $fixture_root . '/seo-export';
$seo_result     = $exporter->export_to_directory(
	$seo_output_dir,
	array(
		'max_pages'         => 1,
		'copy_uploads'      => false,
		'copy_theme'        => false,
		'copy_plugins'      => false,
		'copy_core_assets'  => false,
		'include_manifest'  => false,
		'generate_sitemap'  => true,
		'generate_robots'   => true,
		'progress_callback' => null,
	)
);

ssgwp_assert_same(
	true,
	file_exists( $seo_output_dir . '/sitemap.xml' ),
	'export_to_directory writes sitemap.xml when requested.'
);

ssgwp_assert_contains(
	'<loc>https://example.test/</loc>',
	file_get_contents( $seo_output_dir . '/sitemap.xml' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	'sitemap.xml lists exported page URLs.'
);

ssgwp_assert_same(
	true,
	file_exists( $seo_output_dir . '/robots.txt' ),
	'export_to_directory writes robots.txt when requested.'
);

ssgwp_assert_contains(
	'Sitemap: https://example.test/sitemap.xml',
	file_get_contents( $seo_output_dir . '/robots.txt' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	'robots.txt references sitemap.xml.'
);

ssgwp_assert_same(
	true,
	$seo_result['generated_sitemap'],
	'export_to_directory reports generated sitemap.xml.'
);

ssgwp_assert_same(
	true,
	$seo_result['generated_robots'],
	'export_to_directory reports generated robots.txt.'
);

$markdown_docs_output_dir  = $fixture_root . '/markdown-docs-export';
$ssgwp_test_posts          = array(
	1 => (object) array(
		'ID'             => 1,
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'post_content'   => '<!-- wp:paragraph --><p>Markdown docs intro.</p><!-- /wp:paragraph -->',
		'permalink_path' => 'docs/intro/',
	),
	2 => (object) array(
		'ID'             => 2,
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'post_content'   => '<!-- wp:paragraph --><p>Block API attributes.</p><!-- /wp:paragraph -->',
		'permalink_path' => 'docs/reference/block-api/',
	),
);
$ssgwp_test_http_responses = array(
	'https://example.test/'                         => '<html><head><title>Docs Home</title></head><body><main><h1>Markdown Docs Home</h1><a href="https://example.test/docs/intro/">Read the docs</a></main></body></html>',
	'https://example.test/docs/intro/'              => '<html><head><title>Intro</title></head><body><main><h1>Imported Markdown Intro</h1><p>Intro page content, not the homepage.</p><a href="https://example.test/docs/reference/block-api/#attributes">Block API</a></main></body></html>',
	'https://example.test/docs/reference/block-api/' => '<html><head><title>Block API</title></head><body><main><h1>Block API Reference</h1><p>Reference page content, not the homepage.</p><a href="https://example.test/docs/intro/">Back to intro</a></main></body></html>',
);

$markdown_docs_result = $exporter->export_to_directory(
	$markdown_docs_output_dir,
	array(
		'max_pages'         => 5,
		'copy_uploads'      => false,
		'copy_theme'        => false,
		'copy_plugins'      => false,
		'copy_core_assets'  => false,
		'include_manifest'  => false,
	)
);

$markdown_docs_home      = file_get_contents( $markdown_docs_output_dir . '/index.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$markdown_docs_intro     = file_get_contents( $markdown_docs_output_dir . '/docs/intro/index.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$markdown_docs_reference = file_get_contents( $markdown_docs_output_dir . '/docs/reference/block-api/index.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

ssgwp_assert_same(
	array(
		'https://example.test/',
		'https://example.test/docs/intro/',
		'https://example.test/docs/reference/block-api/',
	),
	$markdown_docs_result['exported_urls'],
	'export_to_directory exports linked imported Markdown docs as distinct pages.'
);

ssgwp_assert_contains(
	'Markdown Docs Home',
	$markdown_docs_home,
	'export_to_directory writes the Markdown docs home page.'
);

ssgwp_assert_contains(
	'Imported Markdown Intro',
	$markdown_docs_intro,
	'export_to_directory writes the imported Markdown intro page content.'
);

ssgwp_assert_not_contains(
	'Markdown Docs Home',
	$markdown_docs_intro,
	'export_to_directory does not write homepage HTML into the imported Markdown intro page.'
);

ssgwp_assert_contains(
	'Block API Reference',
	$markdown_docs_reference,
	'export_to_directory writes the imported Markdown reference page content.'
);

ssgwp_assert_not_contains(
	'Markdown Docs Home',
	$markdown_docs_reference,
	'export_to_directory does not write homepage HTML into the imported Markdown reference page.'
);

ssgwp_assert_not_contains(
	'.md',
	$markdown_docs_intro,
	'export_to_directory receives imported docs with permalink links, not Markdown source paths.'
);

$commerce_assets_dir = $fixture_root . '/wp-content/plugins/woocommerce/assets/css';
wp_mkdir_p( $commerce_assets_dir );
file_put_contents(
	$commerce_assets_dir . '/woocommerce.css',
	'.woocommerce ul.products{display:grid}.woocommerce ul.products li.product{width:auto}'
); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
wp_mkdir_p( $fixture_root . '/wp-content/uploads/2024/11' );
file_put_contents(
	$fixture_root . '/wp-content/uploads/2024/11/triple-pack.jpeg',
	'jpeg'
); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

$commerce_output_dir       = $fixture_root . '/commerce-export';
$ssgwp_test_posts          = array(
	10 => (object) array(
		'ID'             => 10,
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'post_content'   => '',
		'permalink_path' => 'shop/',
	),
	11 => (object) array(
		'ID'             => 11,
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'post_content'   => '',
		'permalink_path' => 'cart/',
	),
	12 => (object) array(
		'ID'             => 12,
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'post_content'   => '',
		'permalink_path' => 'communication-preferences/',
	),
);
$ssgwp_test_http_responses = array(
	'https://example.test/' => '<html><head><title>Coffee Home</title><link rel="stylesheet" href="/wp-content/plugins/woocommerce/assets/css/woocommerce.css?ver=10.7.0"></head><body><main data-wp-context=\'{"shopUrl":"/shop/","cartUrl":"/cart/"}\'><h1>Coffee Home</h1><form role="search" action="/"><input name="s" value=""></form><a href="/shop/">Shop</a><a href="/cart/">Cart</a><a href="/communication-preferences/">Communication preferences</a></main></body></html>',
	'https://example.test/shop/' => '<html><head><title>Shop</title><link rel="stylesheet" href="/wp-content/plugins/woocommerce/assets/css/woocommerce.css?ver=10.7.0"></head><body><main><h1>Shop</h1><ul class="products columns-3"><li class="product"><a href="/product/triple-pack/">Triple Pack</a></li><li class="product">Espresso Roast</li><li class="product">Pour Over Kit</li><li class="product">Travel Tumbler</li></ul><a href="/product-category/beans/">Bean subscriptions</a><a href="/cart/">View cart</a><script type="application/json">{"cartUrl":"\u002Fcart\u002F","checkoutUrl":"\u002Fcheckout\u002F","styleUrl":"\u002Fwp-content\u002Fplugins\u002Fwoocommerce\u002Fassets\u002Fcss\u002Fwoocommerce.css"}</script></main></body></html>',
	'https://example.test/cart/' => '<html><head><title>Cart</title></head><body><main class="wc-block-cart"><h1>Cart</h1><p>Cart page rendered.</p><form class="woocommerce-cart-form" method="post" action="/cart/"><button name="update_cart">Update cart</button></form><h2>You may be interested in…</h2><a href="/shop/">Keep shopping</a></main></body></html>',
	'https://example.test/communication-preferences/' => '<html><head><title>Communication preferences</title></head><body><main><h1>Communication preferences</h1><p>Rendered communication preferences content.</p></main></body></html>',
	'https://example.test/checkout/' => '<html><head><title>Checkout</title></head><body><main><h1>Checkout</h1><p>Checkout page rendered.</p></main></body></html>',
	'https://example.test/product/triple-pack/' => '<html><head><title>Triple Pack</title><link rel="stylesheet" href="/wp-content/plugins/woocommerce/assets/css/woocommerce.css?ver=10.7.0"></head><body><main><h1>Triple Pack</h1><div class="woocommerce-product-gallery" style="opacity:1"><a href="/wp-content/uploads/2024/11/triple-pack.jpeg"><img width="600" height="600" src="/wp-content/uploads/2024/11/triple-pack.jpeg" class="wp-post-image" alt="Triple Pack" data-large_image="/wp-content/uploads/2024/11/triple-pack.jpeg"></a></div><p>Three bags of rotating house beans.</p><a href="/shop/">Back to shop</a></main></body></html>',
	'https://example.test/product-category/beans/' => '<html><head><title>Bean subscriptions</title><link rel="stylesheet" href="/wp-content/plugins/woocommerce/assets/css/woocommerce.css?ver=10.7.0"></head><body><main><h1>Bean subscriptions</h1><p>Fresh roasted coffee bundles.</p><a href="/product/triple-pack/">Triple Pack</a></main></body></html>',
);

$commerce_result = $exporter->export_to_directory(
	$commerce_output_dir,
	array(
		'max_pages'         => 10,
		'copy_uploads'      => false,
		'copy_theme'        => false,
		'copy_plugins'      => false,
		'copy_core_assets'  => false,
		'include_manifest'  => false,
	)
);

$commerce_shop          = file_get_contents( $commerce_output_dir . '/shop/index.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$commerce_home          = file_get_contents( $commerce_output_dir . '/index.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$commerce_cart          = file_get_contents( $commerce_output_dir . '/cart/index.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$commerce_communication = file_get_contents( $commerce_output_dir . '/communication-preferences/index.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$commerce_product       = file_get_contents( $commerce_output_dir . '/product/triple-pack/index.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$commerce_category      = file_get_contents( $commerce_output_dir . '/product-category/beans/index.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

ssgwp_assert_same(
	true,
	in_array( 'https://example.test/shop/', $commerce_result['exported_urls'], true ),
	'export_to_directory includes the commerce shop page in the export.'
);

ssgwp_assert_same(
	true,
	file_exists( $commerce_output_dir . '/shop/index.html' ),
	'export_to_directory writes the static shop page targeted by homepage links.'
);

ssgwp_assert_contains(
	'href="shop/index.html"',
	$commerce_home,
	'export_to_directory rewrites homepage shop links for file previews.'
);

ssgwp_assert_not_contains(
	'href="/shop/"',
	$commerce_home,
	'export_to_directory removes root-relative shop hrefs from the homepage.'
);

ssgwp_assert_contains(
	'Espresso Roast',
	$commerce_shop,
	'export_to_directory writes product content on the shop page.'
);

ssgwp_assert_contains(
	'Triple Pack',
	$commerce_product,
	'export_to_directory writes product detail page content.'
);

ssgwp_assert_contains(
	'Bean subscriptions',
	$commerce_category,
	'export_to_directory writes product category archive content.'
);

ssgwp_assert_same(
	true,
	file_exists( $commerce_output_dir . '/wp-content/uploads/2024/11/triple-pack.jpeg' ),
	'export_to_directory copies product images referenced by product detail pages.'
);

ssgwp_assert_contains(
	'src="../../wp-content/uploads/2024/11/triple-pack.jpeg"',
	$commerce_product,
	'export_to_directory rewrites product image URLs relative to the product page.'
);

ssgwp_assert_export_has_no_broken_file_preview_urls(
	$commerce_output_dir,
	array(
		'index.html',
		'shop/index.html',
		'cart/index.html',
		'product/triple-pack/index.html',
		'product-category/beans/index.html',
		'communication-preferences/index.html',
	)
);

ssgwp_assert_contains(
	'&quot;shopUrl&quot;:&quot;shop/index.html&quot;',
	$commerce_home,
	'export_to_directory rewrites Interactivity API shop URLs in homepage JSON context.'
);

ssgwp_assert_not_contains(
	'&quot;shopUrl&quot;:&quot;/shop/&quot;',
	$commerce_home,
	'export_to_directory removes root-relative shop URLs from homepage JSON context.'
);

ssgwp_assert_contains(
	'../cart/index.html',
	$commerce_shop,
	'export_to_directory rewrites root-relative cart links for file previews.'
);

ssgwp_assert_not_contains(
	'href="/cart/"',
	$commerce_shop,
	'export_to_directory removes root-relative cart hrefs from exported shop HTML.'
);

ssgwp_assert_contains(
	'..\u002Fcheckout\u002Findex.html',
	$commerce_shop,
	'export_to_directory rewrites JSON unicode-escaped checkout links for file previews.'
);

ssgwp_assert_not_contains(
	'"\u002Fcart\u002F"',
	$commerce_shop,
	'export_to_directory removes JSON unicode-escaped root cart URLs from exported shop HTML.'
);

ssgwp_assert_contains(
	'../wp-content/plugins/woocommerce/assets/css/woocommerce.css',
	$commerce_shop,
	'export_to_directory rewrites commerce stylesheet links relative to the shop page.'
);

ssgwp_assert_same(
	true,
	file_exists( $commerce_output_dir . '/wp-content/plugins/woocommerce/assets/css/woocommerce.css' ),
	'export_to_directory copies linked WooCommerce CSS needed for product grids.'
);

ssgwp_assert_contains(
	'Cart page rendered',
	$commerce_cart,
	'export_to_directory exports linked cart pages used by shop links.'
);

ssgwp_assert_contains(
	'<meta charset="UTF-8" />',
	$commerce_cart,
	'export_to_directory writes charset metadata for cart pages with non-ASCII copy.'
);

ssgwp_assert_contains(
	'You may be interested in…',
	$commerce_cart,
	'export_to_directory preserves UTF-8 cart cross-sell headings.'
);

ssgwp_assert_contains(
	'Rendered communication preferences content',
	$commerce_communication,
	'export_to_directory exports rendered communication preference pages.'
);

$commerce_warnings = implode( "\n", $commerce_result['warnings'] );

ssgwp_assert_contains(
	'Search forms are exported as static markup',
	$commerce_warnings,
	'export_to_directory warns that search forms need a dynamic backend.'
);

ssgwp_assert_contains(
	'WooCommerce cart, checkout, and account pages are exported as static snapshots',
	$commerce_warnings,
	'export_to_directory warns that WooCommerce cart-like pages need a dynamic backend.'
);

ssgwp_assert_contains(
	'POST forms are exported as static markup',
	$commerce_warnings,
	'export_to_directory warns that POST forms need a dynamic backend.'
);

ssgwp_assert_not_contains(
	'[automatewoo_communication_preferences]',
	$commerce_communication,
	'export_to_directory does not emit raw AutomateWoo shortcode placeholders in the commerce fixture.'
);

$commerce_zip_file = $fixture_root . '/commerce-export.zip';
$commerce_zip_result = $exporter->export_to_zip(
	$commerce_zip_file,
	array(
		'max_pages'         => 10,
		'copy_uploads'      => false,
		'copy_theme'        => false,
		'copy_plugins'      => false,
		'copy_core_assets'  => false,
		'include_manifest'  => false,
	)
);
$commerce_zip = new ZipArchive();

ssgwp_assert_same(
	true,
	true === $commerce_zip->open( $commerce_zip_file ),
	'export_to_zip writes an inspectable commerce ZIP archive.'
);

foreach (
	array(
		'index.html',
		'shop/index.html',
		'cart/index.html',
		'product/triple-pack/index.html',
		'product-category/beans/index.html',
		'wp-content/plugins/woocommerce/assets/css/woocommerce.css',
		'wp-content/uploads/2024/11/triple-pack.jpeg',
	) as $commerce_zip_entry
) {
	ssgwp_assert_same(
		true,
		false !== $commerce_zip->locateName( $commerce_zip_entry ),
		'export_to_zip includes ' . $commerce_zip_entry . '.'
	);
}

$commerce_zip_product = $commerce_zip->getFromName( 'product/triple-pack/index.html' );
$commerce_zip_shop    = $commerce_zip->getFromName( 'shop/index.html' );

$commerce_zip->close();

ssgwp_assert_contains(
	'Triple Pack',
	$commerce_zip_product,
	'export_to_zip stores distinct product page content.'
);

ssgwp_assert_contains(
	'Espresso Roast',
	$commerce_zip_shop,
	'export_to_zip stores distinct shop page content.'
);

ssgwp_assert_not_contains(
	'Coffee Home',
	$commerce_zip_product,
	'export_to_zip does not store homepage HTML in product page entries.'
);

ssgwp_assert_same(
	true,
	in_array( 'https://example.test/product/triple-pack/', $commerce_zip_result['exported_urls'], true ),
	'export_to_zip reports exported product URLs.'
);

$ssgwp_test_home_url = 'https://playground.wordpress.net/scope:coffee-shop/';
$ssgwp_test_site_url = 'https://playground.wordpress.net/scope:coffee-shop/';

$scoped_commerce_output_dir = $fixture_root . '/scoped-commerce-export';
$ssgwp_test_posts           = array(
	1 => (object) array(
		'ID'             => 1,
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'post_content'   => '',
		'permalink_path' => 'shop/',
	),
	2 => (object) array(
		'ID'             => 2,
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'post_content'   => '',
		'permalink_path' => 'cart/',
	),
);
$ssgwp_test_http_responses  = array(
	'https://playground.wordpress.net/scope:coffee-shop/' => '<html><body><main data-wp-context=\'{"shopUrl":"/shop/"}\'><a class="wp-block-button__link" href="/shop/">Shop now</a><a href="/product-category/beans/" data-type="product_cat" data-id="21">All the beans</a></main></body></html>',
	'https://playground.wordpress.net/scope:coffee-shop/shop/' => '<html><body><main><h1>Shop</h1><p>Scoped shop page rendered.</p></main></body></html>',
	'https://playground.wordpress.net/scope:coffee-shop/cart/' => '<html><body><main><h1>Cart</h1></main></body></html>',
	'https://playground.wordpress.net/scope:coffee-shop/product-category/beans/' => '<html><body><main><h1>All the beans</h1></main></body></html>',
);

$exporter->export_to_directory(
	$scoped_commerce_output_dir,
	array(
		'copy_core_assets' => false,
		'copy_plugins'     => false,
		'copy_theme'       => false,
		'copy_uploads'     => false,
		'fetch_mode'       => 'remote',
		'url_mode'         => 'relative',
	)
);

$scoped_commerce_home = file_get_contents( $scoped_commerce_output_dir . '/index.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

ssgwp_assert_contains(
	'href="shop/index.html"',
	$scoped_commerce_home,
	'export_to_directory rewrites scoped Playground root-relative shop hrefs for file previews.'
);

ssgwp_assert_not_contains(
	'href="/shop/"',
	$scoped_commerce_home,
	'export_to_directory removes scoped Playground root-relative shop hrefs from exported HTML.'
);

ssgwp_assert_contains(
	'href="product-category/beans/index.html"',
	$scoped_commerce_home,
	'export_to_directory rewrites scoped Playground root-relative product category hrefs.'
);

ssgwp_assert_contains(
	'&quot;shopUrl&quot;:&quot;shop/index.html&quot;',
	$scoped_commerce_home,
	'export_to_directory rewrites scoped Playground root-relative JSON shop URLs.'
);

ssgwp_assert_same(
	true,
	file_exists( $scoped_commerce_output_dir . '/shop/index.html' ),
	'export_to_directory writes the scoped Playground shop target.'
);

ssgwp_assert_same(
	true,
	file_exists( $scoped_commerce_output_dir . '/product-category/beans/index.html' ),
	'export_to_directory writes the scoped Playground product category target.'
);

$ssgwp_test_posts          = array();
$ssgwp_test_http_responses = array();
$ssgwp_test_home_url       = 'https://example.test/';
$ssgwp_test_site_url       = 'https://example.test/';

wp_mkdir_p( $fixture_root . '/theme/static-site-generator' );
file_put_contents( $fixture_root . '/theme/archive.phar', 'phar' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( $fixture_root . '/theme/template.phtml', '<?php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( $fixture_root . '/theme/.env', 'SECRET=value' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( $fixture_root . '/theme/style.css', 'body{color:red}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( $fixture_root . '/theme/style.css.map', '{}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

$symlink_path    = $fixture_root . '/theme/link.css';
$symlink_created = function_exists( 'symlink' )
	&& @symlink( $fixture_root . '/theme/style.css', $symlink_path );
$filter_phar = $exporter->filter_copied_path( new SplFileInfo( $fixture_root . '/theme/archive.phar' ) );
$filter_phtml = $exporter->filter_copied_path( new SplFileInfo( $fixture_root . '/theme/template.phtml' ) );
$filter_hidden = $exporter->filter_copied_path( new SplFileInfo( $fixture_root . '/theme/.env' ) );
$filter_css = $exporter->filter_copied_path( new SplFileInfo( $fixture_root . '/theme/style.css' ) );
$filter_map = $exporter->filter_copied_path( new SplFileInfo( $fixture_root . '/theme/style.css.map' ) );
$filter_named_dir = $exporter->filter_copied_path( new SplFileInfo( $fixture_root . '/theme/static-site-generator' ) );
$filter_symlink = $symlink_created
	? $exporter->filter_copied_path( new SplFileInfo( $symlink_path ) )
	: null;

ssgwp_assert_same(
	false,
	$filter_phar,
	'filter_copied_path rejects PHAR files from copied theme and plugin assets.'
);

ssgwp_assert_same(
	false,
	$filter_phtml,
	'filter_copied_path rejects PHTML files from copied theme and plugin assets.'
);

ssgwp_assert_same(
	false,
	$filter_hidden,
	'filter_copied_path rejects hidden files from copied theme and plugin assets.'
);

ssgwp_assert_same(
	true,
	$filter_css,
	'filter_copied_path keeps regular static assets.'
);

ssgwp_assert_same(
	false,
	$filter_map,
	'filter_copied_path rejects source maps from bulk copied assets.'
);

if ( $symlink_created ) {
	ssgwp_assert_same(
		false,
		$filter_symlink,
		'filter_copied_path rejects symlinks from bulk copied assets.'
	);
}

$is_exportable_asset_file_method = new ReflectionMethod( $exporter, 'is_exportable_asset_file' );
$is_exportable_asset_file_method->setAccessible( true );

ssgwp_assert_same(
	true,
	$is_exportable_asset_file_method->invoke( $exporter, $fixture_root . '/theme/style.css.map', true ),
	'is_exportable_asset_file allows explicitly linked source maps.'
);

ssgwp_assert_same(
	true,
	$filter_named_dir,
	'filter_copied_path keeps ordinary directories named static-site-generator.'
);

$copy_method = new ReflectionMethod( $exporter, 'copy_path' );
$copy_method->setAccessible( true );

$output_dir = $fixture_root . '/export';
wp_mkdir_p( $output_dir );

$current_output_dir_property = new ReflectionProperty( $exporter, 'current_output_dir' );
$current_output_dir_property->setAccessible( true );
$current_output_dir_property->setValue( $exporter, wp_normalize_path( realpath( $output_dir ) ) );

file_put_contents( $fixture_root . '/single-plugin.php', '<?php echo "secret";' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( $fixture_root . '/single-plugin.css', 'body{color:red}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

$copy_method->invoke(
	$exporter,
	$fixture_root . '/single-plugin.php',
	$output_dir . '/wp-content/plugins/single-plugin.php'
);
$copy_method->invoke(
	$exporter,
	$fixture_root . '/single-plugin.css',
	$output_dir . '/wp-content/plugins/single-plugin.css'
);

if ( $symlink_created ) {
	$copy_method->invoke(
		$exporter,
		$fixture_root . '/theme',
		$output_dir . '/wp-content/themes/theme'
	);
}

ssgwp_assert_same(
	false,
	file_exists( $output_dir . '/wp-content/plugins/single-plugin.php' ),
	'copy_path rejects single-file PHP plugins before writing them to the export.'
);

ssgwp_assert_same(
	true,
	file_exists( $output_dir . '/wp-content/plugins/single-plugin.css' ),
	'copy_path still copies single-file static assets.'
);

if ( $symlink_created ) {
	ssgwp_assert_same(
		false,
		file_exists( $output_dir . '/wp-content/themes/theme/link.css' ),
		'copy_path does not copy symlinked files from bulk asset directories.'
	);
}

$copy_linked_asset_method = new ReflectionMethod( $exporter, 'copy_linked_asset' );
$copy_linked_asset_method->setAccessible( true );

$warnings_property = new ReflectionProperty( $exporter, 'warnings' );
$warnings_property->setAccessible( true );
$warnings_property->setValue( $exporter, array() );

wp_mkdir_p( $fixture_root . '/wp-content/uploads' );
file_put_contents( $fixture_root . '/wp-content/uploads/copied.txt', 'copied' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( $fixture_root . '/wp-content/uploads/.secret', 'secret' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$linked_asset_symlink_path    = $fixture_root . '/wp-content/uploads/linked-symlink.txt';
$linked_asset_symlink_created = function_exists( 'symlink' )
	&& @symlink( $fixture_root . '/wp-content/uploads/copied.txt', $linked_asset_symlink_path );

$copy_linked_asset_method->invoke(
	$exporter,
	'https://example.test/wp-content/uploads/copied.txt',
	$output_dir
);
$copy_linked_asset_method->invoke(
	$exporter,
	'https://example.test/wp-content/uploads/missing.txt',
	$output_dir
);
$copy_linked_asset_method->invoke(
	$exporter,
	'https://example.test/wp-content/uploads/.secret',
	$output_dir
);
$copy_linked_asset_method->invoke(
	$exporter,
	'https://cdn.example.test/wp-content/uploads/copied.txt',
	$output_dir
);
$copy_linked_asset_method->invoke(
	$exporter,
	'http://example.test/wp-content/uploads/copied.txt',
	$output_dir
);
$copy_linked_asset_method->invoke(
	$exporter,
	'https://example.test:8443/wp-content/uploads/copied.txt',
	$output_dir
);

if ( $linked_asset_symlink_created ) {
	$copy_linked_asset_method->invoke(
		$exporter,
		'https://example.test/wp-content/uploads/linked-symlink.txt',
		$output_dir
	);
}

$ssgwp_test_home_url = 'https://playground.wordpress.net/scope:sad-quiet-school/';
$ssgwp_test_site_url = 'https://playground.wordpress.net/scope:sad-quiet-school/';

$copy_linked_asset_method->invoke(
	$exporter,
	'https://playground.wordpress.net/scope:other-site/wp-content/uploads/copied.txt',
	$output_dir
);

$ssgwp_test_home_url = 'https://example.test/';
$ssgwp_test_site_url = 'https://example.test/';

ssgwp_assert_same(
	true,
	file_exists( $output_dir . '/wp-content/uploads/copied.txt' ),
	'copy_linked_asset copies same-site files that were discovered in HTML.'
);

$warnings = implode( "\n", $warnings_property->getValue( $exporter ) );

ssgwp_assert_contains(
	'Could not copy linked asset https://example.test/wp-content/uploads/missing.txt: no matching local file was found.',
	$warnings,
	'copy_linked_asset warns when a discovered same-site asset is missing.'
);

ssgwp_assert_contains(
	'Could not copy linked asset https://example.test/wp-content/uploads/.secret: the local file is not exportable.',
	$warnings,
	'copy_linked_asset warns when a discovered same-site asset is not exportable.'
);

ssgwp_assert_contains(
	'Could not copy linked asset https://cdn.example.test/wp-content/uploads/copied.txt: not a same-site asset URL.',
	$warnings,
	'copy_linked_asset warns instead of copying local files for external asset URLs.'
);

ssgwp_assert_contains(
	'Could not copy linked asset http://example.test/wp-content/uploads/copied.txt: not a same-site asset URL.',
	$warnings,
	'copy_linked_asset warns instead of copying local files for cross-scheme asset URLs.'
);

ssgwp_assert_contains(
	'Could not copy linked asset https://example.test:8443/wp-content/uploads/copied.txt: not a same-site asset URL.',
	$warnings,
	'copy_linked_asset warns instead of copying local files for cross-port asset URLs.'
);

ssgwp_assert_contains(
	'Could not copy linked asset https://playground.wordpress.net/scope:other-site/wp-content/uploads/copied.txt: not a same-site asset URL.',
	$warnings,
	'copy_linked_asset warns instead of copying local files for another Playground scope.'
);

if ( $linked_asset_symlink_created ) {
	ssgwp_assert_same(
		false,
		file_exists( $output_dir . '/wp-content/uploads/linked-symlink.txt' ),
		'copy_linked_asset rejects symlinked same-site files discovered in HTML.'
	);

	ssgwp_assert_contains(
		'Could not copy linked asset https://example.test/wp-content/uploads/linked-symlink.txt: the local file is not exportable.',
		$warnings,
		'copy_linked_asset warns when a discovered same-site asset is a symlink.'
	);
}

$rewrite_assets_method = new ReflectionMethod( $exporter, 'rewrite_copied_text_assets' );
$rewrite_assets_method->setAccessible( true );

$copy_linked_assets_method = new ReflectionMethod( $exporter, 'copy_linked_assets' );
$copy_linked_assets_method->setAccessible( true );

wp_mkdir_p( $fixture_root . '/wp-content/plugins/transitive' );
file_put_contents(
	$fixture_root . '/wp-content/plugins/transitive/style.css',
	'@font-face{src:url("font.woff2")}'
); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$fixture_root . '/wp-content/plugins/transitive/font.woff2',
	'font'
); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
wp_mkdir_p( $fixture_root . '/wp-content/plugins/manifest-deps/icons' );
wp_mkdir_p( $fixture_root . '/wp-content/plugins/manifest-deps/runtime' );
file_put_contents(
	$fixture_root . '/wp-content/plugins/manifest-deps/manifest.json',
	'{"icons":[{"src":"icon-192.png"},{"src":"icons/icon.png"}]}'
); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$fixture_root . '/wp-content/plugins/manifest-deps/site.webmanifest',
	'{"icons":[{"src":"webmanifest-icon.png"}]}'
); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$fixture_root . '/wp-content/plugins/manifest-deps/player.json',
	'{"captions":"captions.vtt","runtime":"runtime/module.wasm"}'
); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$fixture_root . '/wp-content/plugins/manifest-deps/browserconfig.xml',
	'<browserconfig><msapplication><tile>'
		. '<square70x70logo src="tile-small.png"/>'
		. '<square150x150logo src="icons/tile-150.png"/>'
		. '</tile></msapplication></browserconfig>'
); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$fixture_root . '/wp-content/plugins/manifest-deps/icon-192.png',
	'icon-192'
); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$fixture_root . '/wp-content/plugins/manifest-deps/webmanifest-icon.png',
	'webmanifest-icon'
); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$fixture_root . '/wp-content/plugins/manifest-deps/tile-small.png',
	'tile-small'
); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$fixture_root . '/wp-content/plugins/manifest-deps/captions.vtt',
	'WEBVTT'
); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$fixture_root . '/wp-content/plugins/manifest-deps/runtime/module.wasm',
	'wasm'
); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$fixture_root . '/wp-content/plugins/manifest-deps/icons/icon.png',
	'icon'
); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$fixture_root . '/wp-content/plugins/manifest-deps/icons/tile-150.png',
	'tile-150'
); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

$copy_linked_asset_method->invoke(
	$exporter,
	'https://example.test/wp-content/plugins/transitive/style.css',
	$output_dir
);

$rewriter = new SSGWP_URL_Rewriter( new SSGWP_URL_Collector(), 'relative' );
$discovered_text_assets = $rewrite_assets_method->invoke(
	$exporter,
	$output_dir,
	$rewriter
);

ssgwp_assert_same(
	true,
	in_array( 'https://example.test/wp-content/plugins/transitive/font.woff2', $discovered_text_assets, true ),
	'rewrite_copied_text_assets reports assets discovered inside copied CSS files.'
);

$copied_count = $copy_linked_assets_method->invoke(
	$exporter,
	$discovered_text_assets,
	$output_dir
);

ssgwp_assert_same(
	1,
	$copied_count,
	'copy_linked_assets copies dependencies discovered inside copied CSS files.'
);

ssgwp_assert_same(
	true,
	file_exists( $output_dir . '/wp-content/plugins/transitive/font.woff2' ),
	'copy_linked_assets writes dependencies discovered inside copied CSS files.'
);

$copy_linked_asset_method->invoke(
	$exporter,
	'https://example.test/wp-content/plugins/manifest-deps/manifest.json',
	$output_dir
);

$discovered_text_assets = $rewrite_assets_method->invoke(
	$exporter,
	$output_dir,
	$rewriter
);

ssgwp_assert_same(
	true,
	in_array( 'https://example.test/wp-content/plugins/manifest-deps/icon-192.png', $discovered_text_assets, true ),
	'rewrite_copied_text_assets reports sibling assets discovered inside copied manifests.'
);

ssgwp_assert_same(
	true,
	in_array( 'https://example.test/wp-content/plugins/manifest-deps/icons/icon.png', $discovered_text_assets, true ),
	'rewrite_copied_text_assets reports assets discovered inside copied manifests.'
);

$copied_count = $copy_linked_assets_method->invoke(
	$exporter,
	$discovered_text_assets,
	$output_dir
);

ssgwp_assert_same(
	2,
	$copied_count,
	'copy_linked_assets copies dependencies discovered inside copied manifests.'
);

ssgwp_assert_same(
	true,
	file_exists( $output_dir . '/wp-content/plugins/manifest-deps/icon-192.png' ),
	'copy_linked_assets writes sibling dependencies discovered inside copied manifests.'
);

ssgwp_assert_same(
	true,
	file_exists( $output_dir . '/wp-content/plugins/manifest-deps/icons/icon.png' ),
	'copy_linked_assets writes dependencies discovered inside copied manifests.'
);

$copy_linked_asset_method->invoke(
	$exporter,
	'https://example.test/wp-content/plugins/manifest-deps/site.webmanifest',
	$output_dir
);

$discovered_text_assets = $rewrite_assets_method->invoke(
	$exporter,
	$output_dir,
	$rewriter
);

ssgwp_assert_same(
	true,
	in_array( 'https://example.test/wp-content/plugins/manifest-deps/webmanifest-icon.png', $discovered_text_assets, true ),
	'rewrite_copied_text_assets reports assets discovered inside copied web manifests.'
);

$copied_count = $copy_linked_assets_method->invoke(
	$exporter,
	$discovered_text_assets,
	$output_dir
);

ssgwp_assert_same(
	1,
	$copied_count,
	'copy_linked_assets copies dependencies discovered inside copied web manifests.'
);

ssgwp_assert_same(
	true,
	file_exists( $output_dir . '/wp-content/plugins/manifest-deps/webmanifest-icon.png' ),
	'copy_linked_assets writes dependencies discovered inside copied web manifests.'
);

$copy_linked_asset_method->invoke(
	$exporter,
	'https://example.test/wp-content/plugins/manifest-deps/browserconfig.xml',
	$output_dir
);

$discovered_text_assets = $rewrite_assets_method->invoke(
	$exporter,
	$output_dir,
	$rewriter
);

ssgwp_assert_same(
	true,
	in_array( 'https://example.test/wp-content/plugins/manifest-deps/tile-small.png', $discovered_text_assets, true ),
	'rewrite_copied_text_assets reports sibling assets discovered inside copied XML files.'
);

ssgwp_assert_same(
	true,
	in_array( 'https://example.test/wp-content/plugins/manifest-deps/icons/tile-150.png', $discovered_text_assets, true ),
	'rewrite_copied_text_assets reports nested assets discovered inside copied XML files.'
);

$copied_count = $copy_linked_assets_method->invoke(
	$exporter,
	$discovered_text_assets,
	$output_dir
);

ssgwp_assert_same(
	2,
	$copied_count,
	'copy_linked_assets copies dependencies discovered inside copied XML files.'
);

ssgwp_assert_same(
	true,
	file_exists( $output_dir . '/wp-content/plugins/manifest-deps/tile-small.png' ),
	'copy_linked_assets writes sibling dependencies discovered inside copied XML files.'
);

ssgwp_assert_same(
	true,
	file_exists( $output_dir . '/wp-content/plugins/manifest-deps/icons/tile-150.png' ),
	'copy_linked_assets writes nested dependencies discovered inside copied XML files.'
);

$copy_linked_asset_method->invoke(
	$exporter,
	'https://example.test/wp-content/plugins/manifest-deps/player.json',
	$output_dir
);

$discovered_text_assets = $rewrite_assets_method->invoke(
	$exporter,
	$output_dir,
	$rewriter
);

ssgwp_assert_same(
	true,
	in_array( 'https://example.test/wp-content/plugins/manifest-deps/captions.vtt', $discovered_text_assets, true ),
	'rewrite_copied_text_assets reports WebVTT captions discovered inside copied JSON files.'
);

ssgwp_assert_same(
	true,
	in_array( 'https://example.test/wp-content/plugins/manifest-deps/runtime/module.wasm', $discovered_text_assets, true ),
	'rewrite_copied_text_assets reports WebAssembly modules discovered inside copied JSON files.'
);

$copied_count = $copy_linked_assets_method->invoke(
	$exporter,
	$discovered_text_assets,
	$output_dir
);

ssgwp_assert_same(
	2,
	$copied_count,
	'copy_linked_assets copies media dependencies discovered inside copied JSON files.'
);

ssgwp_assert_same(
	true,
	file_exists( $output_dir . '/wp-content/plugins/manifest-deps/captions.vtt' ),
	'copy_linked_assets writes WebVTT captions discovered inside copied JSON files.'
);

ssgwp_assert_same(
	true,
	file_exists( $output_dir . '/wp-content/plugins/manifest-deps/runtime/module.wasm' ),
	'copy_linked_assets writes WebAssembly modules discovered inside copied JSON files.'
);

ssgwp_delete_directory( $fixture_root );

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
 * Assert a string contains a substring.
 *
 * @param string $needle  Expected substring.
 * @param string $haystack String to search.
 * @param string $message Failure message.
 */
function ssgwp_assert_contains( $needle, $haystack, $message ) {
	if ( false !== strpos( $haystack, $needle ) ) {
		return;
	}

	ssgwp_fail( $message . ' Missing ' . var_export( $needle, true ) . '.' );
}

/**
 * Assert a string does not contain a substring.
 *
 * @param string $needle  Unexpected substring.
 * @param string $haystack String to search.
 * @param string $message Failure message.
 */
function ssgwp_assert_not_contains( $needle, $haystack, $message ) {
	if ( false === strpos( $haystack, $needle ) ) {
		return;
	}

	ssgwp_fail( $message . ' Unexpected ' . var_export( $needle, true ) . '.' );
}

/**
 * Assert exported HTML files are usable in extracted file previews.
 *
 * @param string   $output_dir Export output directory.
 * @param string[] $files      Relative HTML files to inspect.
 */
function ssgwp_assert_export_has_no_broken_file_preview_urls( $output_dir, array $files ) {
	foreach ( $files as $file ) {
		$path = trailingslashit( $output_dir ) . $file;
		$html = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! is_string( $html ) ) {
			ssgwp_fail( 'Could not read exported HTML file ' . $file . '.' );
		}

		ssgwp_assert_not_contains(
			html_entity_decode( '&#65533;', ENT_QUOTES, 'UTF-8' ),
			$html,
			$file . ' does not contain replacement characters.'
		);

		ssgwp_assert_not_contains(
			'[automatewoo_communication_preferences]',
			$html,
			$file . ' does not contain unresolved AutomateWoo shortcode placeholders.'
		);

		if ( preg_match( '/\s(?:href|src|action|data-large_image)=(["\'])\/(?!\/)/i', $html, $matches ) ) {
			ssgwp_fail( $file . ' contains a root-relative URL that will break in file previews: ' . $matches[0] . '.' );
		}
	}
}

/**
 * Report a skipped test path without failing the script.
 *
 * @param string $message Skip reason.
 */
function ssgwp_skip( $message ) {
	fwrite( STDERR, 'Skipped: ' . $message . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
}

/**
 * Return file inventory for a directory.
 *
 * @param string $directory Directory path.
 * @return array<string,int>
 */
function ssgwp_directory_file_inventory( $directory ) {
	$file_count              = 0;
	$largest_file_size_bytes = 0;

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $item ) {
		if ( $item->isDir() || $item->isLink() ) {
			continue;
		}

		$size = (int) $item->getSize();
		++$file_count;

		if ( $size > $largest_file_size_bytes ) {
			$largest_file_size_bytes = $size;
		}
	}

	return array(
		'file_count' => $file_count,
		'largest_file_size_bytes' => $largest_file_size_bytes,
	);
}

/**
 * Decode a generated `wp option update <name> <json>` command payload.
 *
 * @param string $command     WP-CLI command.
 * @param string $option_name Expected option name.
 * @return array<string,mixed>
 */
function ssgwp_decode_wp_cli_option_update_json( $command, $option_name ) {
	$words = ssgwp_split_shell_words( $command );

	ssgwp_assert_same(
		array( 'wp', 'option', 'update', $option_name ),
		array_slice( $words, 0, 4 ),
		'The generated WP-CLI command writes the expected option.'
	);

	if ( ! isset( $words[4] ) ) {
		ssgwp_fail( 'The generated WP-CLI option update command is missing its JSON payload.' );
	}

	$payload = json_decode( $words[4], true );

	if ( ! is_array( $payload ) ) {
		ssgwp_fail( 'The generated WP-CLI option update payload is not valid JSON.' );
	}

	return $payload;
}

/**
 * Split a simple shell command into words, honoring single-quoted arguments.
 *
 * @param string $command Shell command.
 * @return string[]
 */
function ssgwp_split_shell_words( $command ) {
	$words      = array();
	$current    = '';
	$length     = strlen( $command );
	$in_single  = false;
	$has_current = false;

	for ( $i = 0; $i < $length; ++$i ) {
		$char = $command[ $i ];

		if ( $in_single ) {
			if ( "'" === $char ) {
				$in_single = false;
				continue;
			}

			$current    .= $char;
			$has_current = true;
			continue;
		}

		if ( "'" === $char ) {
			$in_single   = true;
			$has_current = true;
			continue;
		}

		if ( ' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char ) {
			if ( $has_current ) {
				$words[]     = $current;
				$current     = '';
				$has_current = false;
			}
			continue;
		}

		$current    .= $char;
		$has_current = true;
	}

	if ( $has_current ) {
		$words[] = $current;
	}

	return $words;
}

/**
 * Find an executable in PATH without invoking a shell.
 *
 * @param string $name Executable name.
 * @return string|null
 */
function ssgwp_find_executable( $name ) {
	$path = getenv( 'PATH' );

	if ( ! is_string( $path ) || '' === $path ) {
		return null;
	}

	foreach ( explode( PATH_SEPARATOR, $path ) as $directory ) {
		if ( '' === $directory ) {
			continue;
		}

		$candidate = rtrim( $directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $name;

		if ( is_file( $candidate ) && is_executable( $candidate ) ) {
			return $candidate;
		}
	}

	return null;
}

/**
 * Run a local command without a shell and capture its output.
 *
 * @param array<int,string> $command Command argv.
 * @param string            $cwd     Working directory.
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
function ssgwp_run_process( array $command, $cwd ) {
	$descriptor_spec = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	$process = proc_open( $command, $descriptor_spec, $pipes, $cwd ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open

	if ( ! is_resource( $process ) ) {
		return array(
			'exit_code' => 1,
			'stdout' => '',
			'stderr' => 'proc_open failed',
		);
	}

	fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

	return array(
		'exit_code' => proc_close( $process ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_close
		'stdout' => is_string( $stdout ) ? $stdout : '',
		'stderr' => is_string( $stderr ) ? $stderr : '',
	);
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
		if ( $item->isDir() ) {
			rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		} else {
			unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

/**
 * Exit with a test failure.
 *
 * @param string $message Failure message.
 */
function ssgwp_fail( $message ) {
	fwrite( STDERR, $message . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}
