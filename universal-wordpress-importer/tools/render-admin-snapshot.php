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

// Flag: --running injects a fake running session so we can review the
// in-flight UI without spinning up a real worker.
$inject_running = in_array( '--running', $argv ?? array(), true );

// --scenario=<name> picks a specific running-state shape so we can
// review different focus moments in one tool. Recognized names:
//   stage-1-early     GitHub source just queued, no file count yet, with a
//                     recovered sparse-Git ref failure in the event stream.
//   stage-2-mid       Source done, mid Prepare content with repeated events.
//   stage-3-decision  Prepare done, URL-treatment decision pending.
//   stage-3-resolved  Decision resolved, chips visible, mid Import media.
$scenario = 'default';
foreach ( $argv ?? array() as $arg ) {
	if ( 0 === strpos( (string) $arg, '--scenario=' ) ) {
		$scenario = substr( (string) $arg, strlen( '--scenario=' ) );
	}
}
if ( 'default' !== $scenario ) {
	$inject_running = true;
}

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

if ( $inject_running ) {
	// Build a minimal snapshot matching the shape returned by
	// ImportAdminPage::get_status_snapshot(), with stage 1 active and 6 of
	// 7 source items read. We render render_session_list() directly via
	// reflection and splice the output into the static page so design
	// review can see the actual running-state markup.
	$fake_session = array(
		'id'                    => 'preview-running',
		'source'                => 'https://adamadam.blog',
		'status'                => 'running',
		'post_status'           => 'publish',
		'dry_run'               => false,
		'pending_decisions'     => array(),
		'relationship_warnings' => array(),
		'recent_events'         => array(
			array( 'type' => 'source.queued',   'message' => 'Queued 7 URLs from adamadam.blog',  'created_at' => '2026-05-22 10:00:00', 'context' => array() ),
			array( 'type' => 'source.imported', 'message' => 'Read /2024/the-summer-bookshelf/', 'created_at' => '2026-05-22 10:00:08', 'context' => array() ),
			array( 'type' => 'source.imported', 'message' => 'Read /2024/sailing-notes/',         'created_at' => '2026-05-22 10:00:12', 'context' => array() ),
		),
		'source_items'          => array(
			'total'    => 7,
			'statuses' => array( 'queued' => 1, 'processing' => 0, 'discovered' => 0, 'imported' => 6, 'skipped' => 0, 'failed' => 0 ),
			'recent'   => array(),
		),
		'prepared_documents'    => array( 'total' => 0, 'recent' => array() ),
		'posts'                 => array( 'persisted' => 0 ),
		'comments'              => array( 'persisted' => 0 ),
		'media'                 => array( 'total' => 0, 'statuses' => array( 'queued' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0 ) ),
		'progress'              => array( 'total' => 7, 'completed' => 6, 'errors' => 0 ),
		'github_git'            => array( 'active' => false, 'recent' => array() ),
		'remote_backoff'        => array( 'total' => 0, 'recent' => array() ),
		'epub_tocs'             => array( 'total' => 0, 'recent' => array() ),
		'pdf_documents'         => array( 'total' => 0, 'recent' => array() ),
		'dashboard'             => array(
			'percentage'        => 85,
			'indeterminate'     => false,
			'status_label'      => 'running',
			'progress_note'     => '',
			'current_action'    => 'Reading the source.',
			'attention_message' => '',
			'needs_keepalive'   => true,
			'summary'           => array( 'total' => 7, 'completed' => 6, 'errors' => 0 ),
			'checklist'         => array(
				array( 'index' => '1', 'key' => 'read_source',     'label' => 'Read source',     'detail' => '', 'state' => 'active' ),
				array( 'index' => '2', 'key' => 'prepare_content', 'label' => 'Prepare content', 'detail' => '',                          'state' => 'pending' ),
				array( 'index' => '3', 'key' => 'url_treatment',   'label' => 'URL treatment',   'detail' => '',                          'state' => 'pending' ),
				array( 'index' => '4', 'key' => 'import_media',    'label' => 'Import media',    'detail' => '',                          'state' => 'pending' ),
				array( 'index' => '5', 'key' => 'write_pages',     'label' => 'Write pages',     'detail' => '',                          'state' => 'pending' ),
				array( 'index' => '6', 'key' => 'finish',          'label' => 'Finish',          'detail' => '',                          'state' => 'pending' ),
			),
			'progress_summary'  => 'Stage 1 of 6 · Read source · 6 of 7 source items read (85%)',
			'activity_log'      => array(
				array( 'type' => 'source.imported', 'message' => 'Read /2024/the-summer-bookshelf/', 'created_at' => '2026-05-22 10:00:08' ),
				array( 'type' => 'source.imported', 'message' => 'Read /2024/sailing-notes/',         'created_at' => '2026-05-22 10:00:12' ),
			),
		),
	);

	if ( 'stage-1-early' === $scenario ) {
		// GitHub URL was just queued. No source items discovered yet. The
		// event stream contains a recovered sparse-Git ref failure that the
		// importer rolled past — it must NOT leak into the user log.
		$activity = array(
			array(
				'type'       => 'source.queued',
				'message'    => 'Queued to fetch GitHub repository files.',
				'created_at' => '2026-05-22 09:59:55',
			),
			array(
				// Real importer fires github.fetch_queued with a phrasing that
				// restates the current-action line ("Queued to fetch GitHub
				// repository files."). It is the second copy of one fact the
				// user reported, so the stage panel must filter it out.
				'type'       => 'github.fetch_queued',
				'message'    => 'GitHub repository fetch queued; file count will appear after discovery.',
				'created_at' => '2026-05-22 09:59:56',
			),
			array(
				'type'       => 'github.git_unavailable',
				'message'    => 'php-toolkit Git traversal failed for ref "trunk/docs" at path "/": Invalid Git ref: branch names cannot contain a slash. The importer will try the next GitHub path candidate.',
				'created_at' => '2026-05-22 10:00:00',
			),
			array(
				'type'       => 'github.git_fetching',
				'message'    => 'Fetching repository files with sparse Git.',
				'created_at' => '2026-05-22 10:00:02',
			),
		);
		$fake_session['source']                    = 'https://github.com/WordPress/gutenberg/tree/trunk/docs';
		$fake_session['github_git']                = array( 'active' => false, 'recent' => array() );
		$fake_session['progress']                  = array( 'total' => 0, 'completed' => 0, 'errors' => 0 );
		$fake_session['source_items']              = array(
			'total'    => 0,
			'statuses' => array( 'queued' => 0, 'processing' => 0, 'discovered' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0 ),
			'recent'   => array(),
		);
		$fake_session['recent_events']             = $activity;
		$fake_session['dashboard']['percentage']     = 0;
		$fake_session['dashboard']['indeterminate']  = true;
		$fake_session['dashboard']['status_label']   = 'Starting';
		$fake_session['dashboard']['current_action'] = 'Queued to fetch GitHub repository files.';
		$fake_session['dashboard']['progress_note']  = 'File count appears after GitHub repository discovery.';
		$fake_session['dashboard']['progress_summary'] = 'Stage 1 of 6 · Read source';
		$fake_session['dashboard']['checklist']      = array(
			array( 'index' => '1', 'key' => 'read_source',     'label' => 'Read source',     'detail' => '', 'state' => 'active' ),
			array( 'index' => '2', 'key' => 'prepare_content', 'label' => 'Prepare content', 'detail' => '', 'state' => 'pending' ),
			array( 'index' => '3', 'key' => 'url_treatment',   'label' => 'URL treatment',   'detail' => '', 'state' => 'pending' ),
			array( 'index' => '4', 'key' => 'import_media',    'label' => 'Import media',    'detail' => '', 'state' => 'pending' ),
			array( 'index' => '5', 'key' => 'write_pages',     'label' => 'Write pages',     'detail' => '', 'state' => 'pending' ),
			array( 'index' => '6', 'key' => 'finish',          'label' => 'Finish',          'detail' => '', 'state' => 'pending' ),
		);
		$fake_session['dashboard']['activity_log']   = $activity;
	}

	if ( 'stage-2-mid' === $scenario ) {
		// Read source done. Prepare content in progress with 5 identical
		// "documents converted" events (dedup target) plus distinct paths.
		$activity = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$activity[] = array(
				'type'       => 'document.prepared',
				'message'    => 'Source item was converted into initial block markup.',
				'created_at' => '2026-05-22 10:01:0' . $i,
			);
		}
		$activity[] = array( 'type' => 'document.epub_progress', 'message' => 'Working on /docs/chapter-3.html', 'created_at' => '2026-05-22 10:01:09' );
		$fake_session['progress']                    = array( 'total' => 117, 'completed' => 12, 'errors' => 0 );
		$fake_session['prepared_documents']['total'] = 5;
		$fake_session['source_items']                = array(
			'total'    => 117,
			'statuses' => array( 'queued' => 0, 'processing' => 0, 'discovered' => 112, 'imported' => 117, 'skipped' => 0, 'failed' => 0 ),
			'recent'   => array(),
		);
		$fake_session['dashboard']['percentage']     = 14;
		$fake_session['dashboard']['current_action'] = 'Preparing imported content.';
		$fake_session['dashboard']['progress_summary'] = 'Stage 2 of 6 · Prepare content · 5 of 117 documents converted (14%)';
		// Detail strings mirror what dashboard_checklist() would build for the
		// same counts so the snapshot exercises the plural-form code path.
		$fake_session['dashboard']['checklist']      = array(
			array( 'index' => '1', 'key' => 'read_source',     'label' => 'Read source',     'detail' => '117 source items found.', 'state' => 'done' ),
			array( 'index' => '2', 'key' => 'prepare_content', 'label' => 'Prepare content', 'detail' => 'Preparing 112 items.',    'state' => 'active' ),
			array( 'index' => '3', 'key' => 'url_treatment',   'label' => 'URL treatment',   'detail' => '',                        'state' => 'pending' ),
			array( 'index' => '4', 'key' => 'import_media',    'label' => 'Import media',    'detail' => '',                        'state' => 'pending' ),
			array( 'index' => '5', 'key' => 'write_pages',     'label' => 'Write pages',     'detail' => '',                        'state' => 'pending' ),
			array( 'index' => '6', 'key' => 'finish',          'label' => 'Finish',          'detail' => '',                        'state' => 'pending' ),
		);
		$fake_session['dashboard']['activity_log']   = $activity;
		$fake_session['recent_events']               = $activity;
	}

	if ( 'stage-3-decision' === $scenario ) {
		// URL-treatment decision is pending; show the new per-host UI with
		// a realistic mix of GitHub-flavored domains.
		$fake_session['pending_decisions'] = array(
			array(
				'key'     => 'confirm-first-party-domains',
				'prompt'  => 'Rewrite old-site URLs to this site?',
				'options' => array(
					'domains'  => array( 'cli.github.com', 'docs.github.com', 'gist.github.com', 'github.com', 'help.github.com' ),
					'counts'   => array(
						'cli.github.com'  => 4,
						'docs.github.com' => 91,
						'gist.github.com' => 2,
						'github.com'      => 38,
						'help.github.com' => 11,
					),
					'examples' => array(
						'cli.github.com'  => array( 'https://cli.github.com/manual/gh_repo_clone' ),
						'docs.github.com' => array( 'https://docs.github.com/en/get-started/quickstart' ),
						'gist.github.com' => array( 'https://gist.github.com/octocat/abc123' ),
						'github.com'      => array( 'https://github.com/WordPress/gutenberg/blob/trunk/docs/contributors/README.md' ),
						'help.github.com' => array( 'https://help.github.com/articles/two-factor-authentication/' ),
					),
					'defaults' => array(
						'cli.github.com'  => true,
						'docs.github.com' => true,
						'gist.github.com' => false,
						'github.com'      => true,
						'help.github.com' => false,
					),
				),
			),
		);
		$fake_session['progress']                    = array( 'total' => 117, 'completed' => 117, 'errors' => 0 );
		$fake_session['prepared_documents']['total'] = 117;
		$fake_session['source_items']                = array(
			'total'    => 117,
			'statuses' => array( 'queued' => 0, 'processing' => 0, 'discovered' => 0, 'imported' => 117, 'skipped' => 0, 'failed' => 0 ),
			'recent'   => array(),
		);
		// Mirror what the live admin's dashboard_payload() emits when a
		// confirm-first-party-domains decision is pending. The scenario is
		// only useful as a deduplication fixture when its dashboard values
		// match what render_session_list() actually sees in production.
		$fake_session['dashboard']['percentage']       = 50;
		$fake_session['dashboard']['current_action']   = 'Choose URL treatment to continue.';
		$fake_session['dashboard']['attention_message'] = 'Answer the prompt below to continue the import.';
		$fake_session['dashboard']['progress_summary'] = 'Stage 3 of 6 · URL treatment · waiting for your choice';
		$fake_session['dashboard']['checklist']      = array(
			array( 'index' => '1', 'key' => 'read_source',     'label' => 'Read source',     'detail' => '117 source items found.',     'state' => 'done' ),
			array( 'index' => '2', 'key' => 'prepare_content', 'label' => 'Prepare content', 'detail' => '117 documents ready.',        'state' => 'done' ),
			array( 'index' => '3', 'key' => 'url_treatment',   'label' => 'URL treatment',   'detail' => '',                            'state' => 'blocked' ),
			array( 'index' => '4', 'key' => 'import_media',    'label' => 'Import media',    'detail' => '',                            'state' => 'pending' ),
			array( 'index' => '5', 'key' => 'write_pages',     'label' => 'Write pages',     'detail' => '',                            'state' => 'pending' ),
			array( 'index' => '6', 'key' => 'finish',          'label' => 'Finish',          'detail' => '',                            'state' => 'pending' ),
		);
		$fake_session['dashboard']['activity_log']   = array();
		$fake_session['recent_events']               = array();
	}

	if ( 'stage-3-resolved' === $scenario ) {
		// User chose to rewrite three hosts. Media stage is mid-flight.
		$fake_session['confirmed_first_party_domains'] = array( 'cli.github.com', 'docs.github.com', 'github.com' );
		$activity = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$activity[] = array(
				'type'    => 'media.attachment_created',
				'message' => 'Imported attachment image-' . ( $i + 1 ) . '.png',
				'created_at' => '2026-05-22 10:02:0' . $i,
			);
		}
		$activity[] = array( 'type' => 'url.rewritten', 'message' => 'https://cli.github.com/manual → /manual', 'created_at' => '2026-05-22 10:02:04' );
		$activity[] = array( 'type' => 'url.rewritten', 'message' => 'https://docs.github.com/quickstart → /quickstart', 'created_at' => '2026-05-22 10:02:05' );
		$activity[] = array( 'type' => 'url.rewritten', 'message' => 'https://github.com/foo/bar → /foo/bar', 'created_at' => '2026-05-22 10:02:06' );
		$fake_session['progress']                    = array( 'total' => 117, 'completed' => 117, 'errors' => 0 );
		$fake_session['prepared_documents']['total'] = 117;
		$fake_session['media']                       = array( 'total' => 12, 'statuses' => array( 'queued' => 4, 'imported' => 3, 'skipped' => 0, 'failed' => 0 ) );
		$fake_session['source_items']                = array(
			'total'    => 117,
			'statuses' => array( 'queued' => 0, 'processing' => 0, 'discovered' => 0, 'imported' => 117, 'skipped' => 0, 'failed' => 0 ),
			'recent'   => array(),
		);
		$fake_session['dashboard']['percentage']     = 62;
		$fake_session['dashboard']['current_action'] = 'Importing media.';
		$fake_session['dashboard']['progress_summary'] = 'Stage 4 of 6 · Import media · 3 of 12 media items imported';
		$fake_session['dashboard']['checklist']      = array(
			array( 'index' => '1', 'key' => 'read_source',     'label' => 'Read source',     'detail' => '117 source items found.',  'state' => 'done' ),
			array( 'index' => '2', 'key' => 'prepare_content', 'label' => 'Prepare content', 'detail' => '117 documents ready.',     'state' => 'done' ),
			array( 'index' => '3', 'key' => 'url_treatment',   'label' => 'URL treatment',   'detail' => 'URL choice is set.',       'state' => 'done' ),
			array( 'index' => '4', 'key' => 'import_media',    'label' => 'Import media',    'detail' => '4 media items queued.',    'state' => 'active' ),
			array( 'index' => '5', 'key' => 'write_pages',     'label' => 'Write pages',     'detail' => '',                         'state' => 'pending' ),
			array( 'index' => '6', 'key' => 'finish',          'label' => 'Finish',          'detail' => '',                         'state' => 'pending' ),
		);
		$fake_session['dashboard']['activity_log']   = $activity;
		$fake_session['recent_events']               = $activity;
	}

	$render_method = $reflection->getMethod( 'render_session_list' );
	$render_method->setAccessible( true );

	ob_start();
	$render_method->invoke( $page, array( $fake_session ) );
	$session_html = ob_get_clean();

	// Wrap the rendered session card in a faux .universal-importer-admin
	// container so the CSS custom properties resolve, and replace the body
	// entirely with this focused running-state view for design review.
	$body = '<div class="wrap universal-importer-admin"><h1 class="wp-heading-inline">Universal Importer (running-state preview)</h1>'
		. '<div id="universal-importer-sessions" class="universal-importer-sessions">'
		. $session_html
		. '</div></div>'
		. '<!-- styles+JS are still inherited from the previously rendered output above -->';

	// To keep the original styles, prepend the <style> blocks captured from
	// the full render so the preview shows accurate CSS.
	if ( preg_match( '/<style.*?<\/style>/s', $body, $m ) === 0 ) {
		// Re-capture full styles from the previously rendered page so the
		// preview body looks correct.
		ob_start();
		$page->render_admin_page();
		$full_body = ob_get_clean();
		$styles = '';
		if ( preg_match_all( '/<style\b[^>]*>.*?<\/style>/s', $full_body, $matches ) ) {
			$styles = implode( "\n", $matches[0] );
		}
		$body = $styles . $body;
	}
}

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
