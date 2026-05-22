<?php
/**
 * Render the importer admin page to a static HTML file for visual inspection.
 * Not part of the plugin; lives in tools/ for ad-hoc snapshots.
 */

require_once __DIR__ . '/../tests/bootstrap.php';

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) { return true; }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action ) { return 'preview-nonce'; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = null ) { echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = null ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $value ) { return addslashes( (string) $value ); }
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) { return (string) $text; }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ) { echo $message; exit; }
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $query, $url ) {
		$sep = false === strpos( $url, '?' ) ? '?' : '&';
		return $url . $sep . http_build_query( (array) $query );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) { return trim( (string) $value ); }
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $value ) { return (string) $value; }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : (string) $value; }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) { return abs( (int) $value ); }
}
if ( ! function_exists( 'sprintf_safe' ) ) {
	// nothing to register
}

use UniversalImporter\Admin\ImportAdminPage;
use UniversalImporter\Import\InMemoryImportSessionStore;

// Anonymous class that satisfies just what render_admin_page() needs.
$store = new class {
	public function list_recent_sessions( $limit = 10 ) { return array(); }
};

$reflection = new \ReflectionClass( ImportAdminPage::class );
$page = $reflection->newInstanceWithoutConstructor();
$store_prop = $reflection->getProperty( 'store' );
$store_prop->setAccessible( true );
$store_prop->setValue( $page, $store );

// Render and capture
ob_start();
try {
	$page->render_admin_page();
} catch ( \Throwable $e ) {
	echo "\n<!-- RENDER ERROR: " . htmlspecialchars( $e->getMessage(), ENT_QUOTES, 'UTF-8' ) . " -->\n";
}
$body = ob_get_clean();

// Wrap in a minimal admin-like chrome so it looks closer to wp-admin
$out = '<!doctype html><html><head><meta charset="utf-8"><title>Importer admin snapshot</title>'
	. '<style>body{margin:0;background:#f0f0f1;font:13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1d2327}'
	. '.wpadmin{padding:20px 0 60px}'
	. '.wpadmin > .wrap{margin:0 20px;max-width:none}'
	. '</style></head><body><div class="wpadmin">'
	. $body
	. '</div></body></html>';

file_put_contents( __DIR__ . '/../snapshot.html', $out );
echo "Wrote snapshot.html (" . strlen( $out ) . " bytes)\n";
