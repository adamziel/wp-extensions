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
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
}
if ( ! function_exists( 'submit_button' ) ) {
	function submit_button( $text, $type = 'primary', $name = 'submit', $wrap = true ) {
		echo '<button type="submit" class="button button-primary">' . htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ) . '</button>';
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql', $gmt = 0 ) { return date( 'Y-m-d H:i:s' ); }
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

// Wrap the rendered importer page in a faithful wp-admin chrome
// simulation (admin bar + side menu + content canvas) so design review
// can evaluate the importer in real context.
$wp_chrome = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Universal Importer · wp-admin snapshot</title>
<style>
  /* ----- wp-admin chrome simulation ----- */
  *,*::before,*::after { box-sizing: border-box; }
  /* Mirror WP's accessibility utility so screen-reader-only labels are hidden visually. */
  .screen-reader-text {
    border: 0; clip: rect(1px, 1px, 1px, 1px); -webkit-clip-path: inset(50%);
    clip-path: inset(50%); height: 1px; margin: -1px; overflow: hidden;
    padding: 0; position: absolute; width: 1px; word-wrap: normal !important;
  }
  html, body { margin: 0; padding: 0; }
  body {
    background: #f0f0f1;
    color: #1d2327;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
    font-size: 13px;
    line-height: 1.4em;
    min-height: 100vh;
    overflow-x: hidden;
  }
  #wpadminbar {
    position: fixed; top: 0; left: 0; right: 0; height: 32px;
    background: #1d2327; color: #f0f0f1; z-index: 99999;
    display: flex; align-items: center; gap: 18px; padding: 0 16px;
    font-size: 13px;
  }
  #wpadminbar .ab-item { color: #f0f0f1; opacity: .85; cursor: pointer; }
  #wpadminbar .ab-item:hover { opacity: 1; color: #72aee6; }
  #wpadminbar .ab-logo { width: 20px; height: 20px; background: #82878c; border-radius: 2px; opacity: .55; }
  #adminmenuwrap {
    position: fixed; left: 0; top: 32px; bottom: 0; width: 160px;
    background: #1d2327; color: #f0f0f1; padding-top: 12px; overflow-y: auto;
  }
  #adminmenu { list-style: none; margin: 0; padding: 0; font-size: 14px; }
  #adminmenu li { padding: 8px 14px; color: #c3c4c7; cursor: pointer; display: flex; align-items: center; gap: 10px; }
  #adminmenu li:hover { background: #0a0a0a; color: #72aee6; }
  #adminmenu li.current { background: #2271b1; color: #fff; }
  #adminmenu li .icon { width: 16px; height: 16px; background: #757575; border-radius: 2px; opacity: .8; flex: none; }
  #adminmenu li.current .icon { background: #fff; opacity: 1; }
  #wpcontent {
    margin-left: 160px; padding-top: 32px; min-height: 100vh;
  }
  #wpbody { padding: 10px 20px 40px; }
  #wpbody .wrap > h1:first-of-type {
    font-weight: 400; font-size: 23px; line-height: 1.3; margin: 9px 0 4px;
  }
  /* Tablet collapse */
  @media (max-width: 960px) {
    #adminmenuwrap { width: 36px; }
    #wpcontent { margin-left: 36px; }
    #adminmenu li span.label { display: none; }
  }
</style>
</head>
<body class="wp-admin wp-core-ui">
<div id="wpadminbar" role="banner">
  <div class="ab-logo" title="WordPress logo placeholder"></div>
  <span class="ab-item">Howdy, admin</span>
  <span class="ab-item" style="margin-left:auto">View Site</span>
  <span class="ab-item">Updates</span>
  <span class="ab-item">Comments</span>
</div>
<div id="adminmenuwrap">
  <ul id="adminmenu">
    <li><span class="icon"></span><span class="label">Dashboard</span></li>
    <li><span class="icon"></span><span class="label">Posts</span></li>
    <li><span class="icon"></span><span class="label">Media</span></li>
    <li><span class="icon"></span><span class="label">Pages</span></li>
    <li class="current"><span class="icon"></span><span class="label">Tools</span></li>
    <li><span class="icon"></span><span class="label">Settings</span></li>
  </ul>
</div>
<div id="wpcontent">
  <div id="wpbody">
    __BODY__
  </div>
</div>
</body>
</html>
HTML;
$out = str_replace( '__BODY__', $body, $wp_chrome );

file_put_contents( __DIR__ . '/../snapshot.html', $out );
echo "Wrote snapshot.html (" . strlen( $out ) . " bytes)\n";
