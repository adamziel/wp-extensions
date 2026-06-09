<?php
/**
 * Tests for SSGWP_Plugin helpers.
 *
 * @package PlaygroundStaticSiteGenerator
 */

define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$ssgwp_transients = array();
$ssgwp_user_meta  = array();
$ssgwp_options    = array();

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html_e( $text, $domain = 'default' ) {
	echo esc_html( $text );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $value ) {
	return trim( ssgwp_remove_percent_encoded_octets( strip_tags( (string) $value ) ) );
}

function ssgwp_remove_percent_encoded_octets( $value ) {
	$output = '';
	$length = strlen( (string) $value );

	for ( $i = 0; $i < $length; ++$i ) {
		if (
			'%' === $value[ $i ]
			&& $i + 2 < $length
			&& ctype_xdigit( $value[ $i + 1 ] )
			&& ctype_xdigit( $value[ $i + 2 ] )
		) {
			$i += 2;
			continue;
		}

		$output .= $value[ $i ];
	}

	return $output;
}

function absint( $value ) {
	return abs( (int) $value );
}

function trailingslashit( $value ) {
	return rtrim( (string) $value, "/\\" ) . '/';
}

function get_temp_dir() {
	return '/tmp/wp-playground-test/';
}

function get_current_user_id() {
	return 42;
}

function get_transient( $key ) {
	global $ssgwp_transients;

	if ( ! isset( $ssgwp_transients[ $key ] ) ) {
		return false;
	}

	return $ssgwp_transients[ $key ]['value'];
}

function set_transient( $key, $value, $expiration ) {
	global $ssgwp_transients;

	$ssgwp_transients[ $key ] = array(
		'value'      => $value,
		'expiration' => $expiration,
	);

	return true;
}

function get_user_meta( $user_id, $key, $single = false ) {
	global $ssgwp_user_meta;

	if ( ! isset( $ssgwp_user_meta[ $user_id ][ $key ] ) ) {
		return $single ? '' : array();
	}

	return $single ? $ssgwp_user_meta[ $user_id ][ $key ] : array( $ssgwp_user_meta[ $user_id ][ $key ] );
}

function update_user_meta( $user_id, $key, $value ) {
	global $ssgwp_user_meta;

	if ( ! isset( $ssgwp_user_meta[ $user_id ] ) ) {
		$ssgwp_user_meta[ $user_id ] = array();
	}

	$ssgwp_user_meta[ $user_id ][ $key ] = $value;

	return true;
}

function get_option( $name, $default = false ) {
	global $ssgwp_options;

	return array_key_exists( $name, $ssgwp_options ) ? $ssgwp_options[ $name ] : $default;
}

require_once dirname( __DIR__ ) . '/includes/class-plugin.php';

$sanitize_method = new ReflectionMethod( 'SSGWP_Plugin', 'sanitize_export_job_id' );
$sanitize_method->setAccessible( true );

$temp_dir_method = new ReflectionMethod( 'SSGWP_Plugin', 'get_export_temp_directory' );
$temp_dir_method->setAccessible( true );

ssgwp_assert_same(
	'/tmp/wp-playground-test/static-site-generator',
	$temp_dir_method->invoke( null ),
	'get_export_temp_directory keeps admin ZIP scratch files outside uploads.'
);

ssgwp_assert_same(
	'job_123-danger',
	$sanitize_method->invoke( null, 'JOB_123-<b>Danger</b>../' ),
	'sanitize_export_job_id keeps only safe opaque id characters.'
);

ssgwp_assert_same(
	64,
	strlen( $sanitize_method->invoke( null, str_repeat( 'a', 80 ) ) ),
	'sanitize_export_job_id limits stored ids to 64 characters.'
);

$progress_key_method = new ReflectionMethod( 'SSGWP_Plugin', 'progress_transient_key' );
$progress_key_method->setAccessible( true );

$latest_meta_key_method = new ReflectionMethod( 'SSGWP_Plugin', 'latest_export_meta_key' );
$latest_meta_key_method->setAccessible( true );

$store_method = new ReflectionMethod( 'SSGWP_Plugin', 'store_progress_event' );
$store_method->setAccessible( true );

$latest_progress_method = new ReflectionMethod( 'SSGWP_Plugin', 'get_latest_export_progress' );
$latest_progress_method->setAccessible( true );

$request_args_method = new ReflectionMethod( 'SSGWP_Plugin', 'request_to_export_args' );
$request_args_method->setAccessible( true );

$cli_args_method = new ReflectionMethod( 'SSGWP_Plugin', 'wp_cli_assoc_args_to_export_args' );
$cli_args_method->setAccessible( true );

$handoff_context_method = new ReflectionMethod( 'SSGWP_Plugin', 'get_playground_source_handoff_context' );
$handoff_context_method->setAccessible( true );

$handoff_panel_method = new ReflectionMethod( 'SSGWP_Plugin', 'render_playground_source_handoff_panel' );
$handoff_panel_method->setAccessible( true );

$ssgwp_options['ssgwp_playground_source_handoff'] = array(
	'schema' => 'https://stillpress.local/playground-source-handoff/v1',
	'version' => 1,
	'source_state' => array(
		'status' => 'source-state-generated',
		'not_full_restore_bundle' => true,
	),
	'wxr' => array(
		'import_enabled' => true,
		'url_mode' => 'provided-url',
		'sha256' => str_repeat( 'a', 64 ),
	),
	'source_access' => array(
		'expires_at' => '2026-06-30T12:45:00Z',
		'expires_at_status' => 'valid',
		'metadata_only' => true,
	),
	'publish' => array(
		'cloudflare_publish_included' => true,
	),
	'restore' => array(
		'content_only' => true,
		'not_full_restore_bundle' => true,
	),
	'security' => array(
		'credentials_stored' => false,
		'tokens_stored' => false,
		'owner_identity_stored' => false,
		'effective_wxr_url_stored' => false,
	),
	'redeploy' => array(
		'requires_external_credentials' => true,
		'automatic_cloudflare_deploy' => false,
	),
);

$handoff_context = $handoff_context_method->invoke( null );

ssgwp_assert_same(
	'provided-url',
	$handoff_context['wxr_url_mode'],
	'get_playground_source_handoff_context returns valid restored Playground source-state context.'
);

ob_start();
$handoff_panel_method->invoke( null );
$handoff_panel = ob_get_clean();

ssgwp_assert_contains(
	'Playground source-state handoff',
	$handoff_panel,
	'render_playground_source_handoff_panel shows the restored Playground source-state panel for valid context.'
);

ssgwp_assert_contains(
	'content-only',
	$handoff_panel,
	'render_playground_source_handoff_panel explains that WXR restore is content-only.'
);

ssgwp_assert_contains(
	'Cloudflare Workers publish contract: included in the source export',
	$handoff_panel,
	'render_playground_source_handoff_panel reports included Cloudflare publish artifacts.'
);

ssgwp_assert_contains(
	'2026-06-30T12:45:00Z',
	$handoff_panel,
	'render_playground_source_handoff_panel displays source access expiry as metadata.'
);

ssgwp_assert_contains(
	'No Cloudflare credentials',
	$handoff_panel,
	'render_playground_source_handoff_panel states that Cloudflare credentials are not stored.'
);

$ssgwp_options['ssgwp_playground_source_handoff'] = 'not json';

ssgwp_assert_same(
	null,
	$handoff_context_method->invoke( null ),
	'get_playground_source_handoff_context ignores invalid non-array option values.'
);

ob_start();
$handoff_panel_method->invoke( null );
$invalid_handoff_panel = ob_get_clean();

ssgwp_assert_same(
	'',
	$invalid_handoff_panel,
	'render_playground_source_handoff_panel emits no panel for invalid option values.'
);

$ssgwp_options['ssgwp_playground_source_handoff'] = array(
	'schema' => 'https://stillpress.local/playground-source-handoff/v1',
	'version' => 1,
	'source_state' => array(
		'status' => 'source-state-generated',
	),
);

ssgwp_assert_same(
	null,
	$handoff_context_method->invoke( null ),
	'get_playground_source_handoff_context ignores incomplete array option values.'
);

$ssgwp_options = array();

$ssgwp_options['ssgwp_playground_source_handoff'] = array(
	'schema' => 'https://stillpress.local/playground-source-handoff/v1',
	'version' => 1,
	'source_state' => array(
		'status' => 'source-state-generated',
		'not_full_restore_bundle' => true,
	),
	'wxr' => array(
		'import_enabled' => true,
		'url_mode' => 'bundled-resource',
		'sha256' => str_repeat( 'b', 64 ),
	),
	'publish' => array(
		'cloudflare_publish_included' => false,
	),
	'restore' => array(
		'content_only' => true,
		'not_full_restore_bundle' => true,
	),
	'security' => array(
		'credentials_stored' => false,
		'tokens_stored' => false,
		'owner_identity_stored' => false,
		'effective_wxr_url_stored' => false,
	),
	'redeploy' => array(
		'requires_external_credentials' => true,
		'automatic_cloudflare_deploy' => false,
	),
);

$bundled_handoff_context = $handoff_context_method->invoke( null );

ssgwp_assert_same(
	'bundled-resource',
	$bundled_handoff_context['wxr_url_mode'],
	'get_playground_source_handoff_context accepts bundled-resource WXR mode from Blueprint bundles.'
);

$ssgwp_options['ssgwp_playground_source_handoff'] = array(
	'schema' => 'https://stillpress.local/playground-source-handoff/v1',
	'version' => 1,
	'source_state' => array(
		'status' => 'source-state-generated',
		'not_full_restore_bundle' => false,
	),
	'wxr' => array(
		'import_enabled' => false,
		'url_mode' => 'not-used-full-site-sqlite',
		'sha256' => str_repeat( 'c', 64 ),
	),
	'publish' => array(
		'cloudflare_publish_included' => false,
	),
	'restore' => array(
		'content_only' => false,
		'full_site_restore' => true,
		'not_full_restore_bundle' => false,
		'mode' => 'sqlite-full-site-wordpress-files',
	),
	'wordpress_files_snapshot' => array(
		'status' => 'available',
		'path' => '_playground-source/wordpress-files.zip',
		'sha256' => str_repeat( 'd', 64 ),
		'mode' => 'sqlite-full-site-wordpress-files',
		'sqlite_database_captured' => true,
	),
	'security' => array(
		'credentials_stored' => false,
		'tokens_stored' => false,
		'owner_identity_stored' => false,
		'effective_wxr_url_stored' => false,
	),
	'redeploy' => array(
		'requires_external_credentials' => true,
		'automatic_cloudflare_deploy' => false,
	),
);

$full_site_handoff_context = $handoff_context_method->invoke( null );

ssgwp_assert_same(
	true,
	$full_site_handoff_context['full_site_restore'],
	'get_playground_source_handoff_context accepts SQLite full-site restored context.'
);

ssgwp_assert_same(
	'not-used-full-site-sqlite',
	$full_site_handoff_context['wxr_url_mode'],
	'get_playground_source_handoff_context accepts the no-WXR full-site mode.'
);

ssgwp_assert_same(
	str_repeat( 'd', 64 ),
	$full_site_handoff_context['wordpress_files_snapshot_sha256'],
	'get_playground_source_handoff_context returns the WordPress files snapshot hash for full-site restores.'
);

ob_start();
$handoff_panel_method->invoke( null );
$full_site_handoff_panel = ob_get_clean();

ssgwp_assert_contains(
	'SQLite full-site source-state bundle',
	$full_site_handoff_panel,
	'render_playground_source_handoff_panel explains the SQLite full-site restore mode.'
);

ssgwp_assert_contains(
	'Full-site snapshot: available',
	$full_site_handoff_panel,
	'render_playground_source_handoff_panel reports the full-site snapshot status.'
);

$ssgwp_options = array();

$admin_args = $request_args_method->invoke(
	null,
	array(
		'url_mode'         => 'bad-value',
		'max_pages'        => 1,
		'include_media'    => '1',
		'generate_sitemap' => '1',
	)
);

ssgwp_assert_same(
	'relative',
	$admin_args['url_mode'],
	'request_to_export_args falls back to portable relative links for invalid URL modes.'
);

ssgwp_assert_same(
	10000,
	$admin_args['max_pages'],
	'request_to_export_args keeps the page limit as an internal runaway guard instead of a UI setting.'
);

ssgwp_assert_same(
	true,
	$admin_args['copy_theme'] && $admin_args['copy_plugins'] && $admin_args['copy_core_assets'] && $admin_args['crawl_links'],
	'request_to_export_args always includes required frontend assets and linked site pages.'
);

ssgwp_assert_same(
	true,
	$admin_args['copy_uploads'] && $admin_args['generate_sitemap'],
	'request_to_export_args maps user-facing include options to exporter settings.'
);

ssgwp_assert_same(
	false,
	$admin_args['include_manifest'],
	'request_to_export_args leaves the technical export report disabled unless selected.'
);

ssgwp_assert_same(
	false,
	$admin_args['generate_robots'],
	'request_to_export_args leaves optional SEO files disabled when not selected.'
);

ssgwp_assert_same(
	false,
	$admin_args['include_cloudflare_publish'],
	'request_to_export_args leaves the Cloudflare publishing contract disabled unless selected.'
);

ssgwp_assert_same(
	true,
	$admin_args['include_playground_admin'],
	'request_to_export_args includes the static /wp-admin/ Playground handoff by default.'
);

ssgwp_assert_same(
	true,
	$admin_args['include_playground_source_state'],
	'request_to_export_args includes owner-only Playground source-state artifacts by default.'
);

$report_args = $request_args_method->invoke( null, array( 'include_report' => '1' ) );

ssgwp_assert_same(
	true,
	$report_args['include_manifest'],
	'request_to_export_args enables the technical export report when selected.'
);

$admin_publish_args = $request_args_method->invoke(
	null,
	array(
		'include_playground_admin'   => '1',
		'include_cloudflare_publish' => '1',
	)
);

ssgwp_assert_same(
	true,
	$admin_publish_args['include_playground_admin'] && $admin_publish_args['include_cloudflare_publish'],
	'request_to_export_args maps selected publishing handoff artifacts to exporter settings.'
);

$admin_playground_opt_out_args = $request_args_method->invoke(
	null,
	array(
		'include_playground_admin'        => '0',
		'include_playground_source_state' => '0',
	)
);

ssgwp_assert_same(
	false,
	$admin_playground_opt_out_args['include_playground_admin'] || $admin_playground_opt_out_args['include_playground_source_state'],
	'request_to_export_args preserves an explicit admin opt-out of Playground handoff and source-state artifacts.'
);

$admin_source_state_args = $request_args_method->invoke(
	null,
	array(
		'include_playground_admin'        => '0',
		'include_playground_source_state' => '1',
	)
);

ssgwp_assert_same(
	true,
	$admin_source_state_args['include_playground_source_state'],
	'request_to_export_args maps the Playground source-state checkbox to exporter settings.'
);

ssgwp_assert_same(
	true,
	$admin_source_state_args['include_playground_admin'],
	'request_to_export_args includes the static /wp-admin/ handoff when Playground source state is selected.'
);

$cli_args = $cli_args_method->invoke(
	null,
	array(
		'url-mode' => 'bad-value',
	)
);

ssgwp_assert_same(
	'relative',
	$cli_args['url_mode'],
	'wp_cli_assoc_args_to_export_args falls back to portable relative links for invalid URL modes.'
);

ssgwp_assert_same(
	10000,
	$cli_args['max_pages'],
	'wp_cli_assoc_args_to_export_args uses a high page limit as an internal runaway guard.'
);

ssgwp_assert_same(
	false,
	$cli_args['include_manifest'],
	'wp_cli_assoc_args_to_export_args leaves the technical export report disabled by default.'
);

ssgwp_assert_same(
	false,
	$cli_args['include_playground_source_state'],
	'wp_cli_assoc_args_to_export_args leaves Playground source-state artifacts disabled by default.'
);

$cli_publish_args = $cli_args_method->invoke(
	null,
	array(
		'include-playground-admin'        => true,
		'include-cloudflare-publish'      => true,
		'cloudflare-worker-name'          => 'docs-site',
		'cloudflare-compatibility-date'   => '2026-06-08',
		'playground-source-bundle-url'    => 'https://example.test/source.zip',
		'playground-source-wxr-url'       => 'https://example.test/source/site-content.wxr?signature=abc123',
		'playground-source-expires-at'    => '2026-06-30T12:45:00Z',
	)
);

ssgwp_assert_same(
	true,
	$cli_publish_args['include_playground_admin'] && $cli_publish_args['include_cloudflare_publish'],
	'wp_cli_assoc_args_to_export_args maps publishing handoff flags to exporter settings.'
);

ssgwp_assert_same(
	'docs-site',
	$cli_publish_args['cloudflare_worker_name'],
	'wp_cli_assoc_args_to_export_args preserves the requested Cloudflare Worker name.'
);

ssgwp_assert_same(
	'https://example.test/source.zip',
	$cli_publish_args['playground_source_bundle_url'],
	'wp_cli_assoc_args_to_export_args preserves the optional Playground source bundle URL.'
);

ssgwp_assert_same(
	'https://example.test/source/site-content.wxr?signature=abc123',
	$cli_publish_args['playground_source_wxr_url'],
	'wp_cli_assoc_args_to_export_args preserves a valid optional Playground source WXR URL.'
);

ssgwp_assert_same(
	'2026-06-30T12:45:00Z',
	$cli_publish_args['playground_source_expires_at'],
	'wp_cli_assoc_args_to_export_args preserves a valid optional Playground source expiry timestamp.'
);

$signed_bundle_url = 'https://example.test/source%2Fbundle.zip?token=abc%3Ddef%2Fghi#owner%3D1';
$signed_wxr_url    = 'https://example.test/source%2Fsite-content.wxr?signature=abc%3Ddef%2Fghi&expires=2026-06-30T12%3A45%3A00Z#owner%3D1';

ssgwp_assert_not_same(
	$signed_wxr_url,
	sanitize_text_field( $signed_wxr_url ),
	'The test sanitize_text_field shim exposes production-style percent-encoded URL mutation.'
);

$cli_signed_source_url_args = $cli_args_method->invoke(
	null,
	array(
		'playground-source-bundle-url' => $signed_bundle_url,
		'playground-source-wxr-url'    => $signed_wxr_url,
	)
);

ssgwp_assert_same(
	$signed_bundle_url,
	$cli_signed_source_url_args['playground_source_bundle_url'],
	'wp_cli_assoc_args_to_export_args preserves percent-encoded signed Playground source bundle URLs.'
);

ssgwp_assert_same(
	$signed_wxr_url,
	$cli_signed_source_url_args['playground_source_wxr_url'],
	'wp_cli_assoc_args_to_export_args preserves percent-encoded signed Playground source WXR URLs.'
);

$cli_invalid_source_policy_args = $cli_args_method->invoke(
	null,
	array(
		'playground-source-bundle-url' => 'javascript:alert(1)',
		'playground-source-wxr-url'    => 'javascript:alert(1)',
		'playground-source-expires-at' => '2026-99-99T99:99:99Z',
	)
);

ssgwp_assert_same(
	'',
	$cli_invalid_source_policy_args['playground_source_bundle_url'],
	'wp_cli_assoc_args_to_export_args falls back to an empty bundle URL for non-HTTP(S) values.'
);

ssgwp_assert_same(
	'',
	$cli_invalid_source_policy_args['playground_source_wxr_url'],
	'wp_cli_assoc_args_to_export_args falls back to an empty WXR URL for non-HTTP(S) values.'
);

ssgwp_assert_same(
	'',
	$cli_invalid_source_policy_args['playground_source_expires_at'],
	'wp_cli_assoc_args_to_export_args falls back to an empty expiry for invalid timestamps.'
);

$cli_source_state_args = $cli_args_method->invoke(
	null,
	array(
		'include-playground-source-state' => true,
	)
);

ssgwp_assert_same(
	true,
	$cli_source_state_args['include_playground_source_state'],
	'wp_cli_assoc_args_to_export_args maps --include-playground-source-state to exporter settings.'
);

ssgwp_assert_same(
	true,
	$cli_source_state_args['include_playground_admin'],
	'wp_cli_assoc_args_to_export_args includes the static /wp-admin/ handoff when --include-playground-source-state is selected.'
);

$cli_report_args = $cli_args_method->invoke( null, array( 'report' => true ) );

ssgwp_assert_same(
	true,
	$cli_report_args['include_manifest'],
	'wp_cli_assoc_args_to_export_args enables the technical export report when selected.'
);

$cli_directory_args = $cli_args_method->invoke(
	null,
	array(
		'output-dir' => 'static-site',
	)
);

ssgwp_assert_same(
	'static-site',
	isset( $cli_directory_args['output_dir'] ) ? $cli_directory_args['output_dir'] : null,
	'wp_cli_assoc_args_to_export_args accepts a directory export target for local HTTP previews.'
);

$store_method->invoke(
	null,
	'job-1',
	array(
		'stage'          => 'render_page',
		'message'        => 'Rendering <strong>page</strong>.',
		'pages_exported' => 2,
		'files_exported' => 5,
		'context'        => array(
			'queue_position' => 3,
			'queue_total'    => 7,
		),
	)
);

$progress_key = $progress_key_method->invoke( null, 'job-1' );

ssgwp_assert_same(
	'render_page',
	$ssgwp_transients[ $progress_key ]['value']['stage'],
	'store_progress_event stores the latest progress stage.'
);

ssgwp_assert_same(
	'Rendering page.',
	$ssgwp_transients[ $progress_key ]['value']['message'],
	'store_progress_event sanitizes the browser-facing progress message.'
);

ssgwp_assert_same(
	7,
	$ssgwp_transients[ $progress_key ]['value']['context']['queue_total'],
	'store_progress_event preserves structured progress context.'
);

ssgwp_assert_same(
	25,
	$ssgwp_transients[ $progress_key ]['value']['percent'],
	'store_progress_event calculates progress from queue position and queue total.'
);

ssgwp_assert_same(
	array(
		'job_id' => 'job-1',
		'run_id' => '',
	),
	$ssgwp_user_meta[42][ $latest_meta_key_method->invoke( null ) ],
	'store_progress_event remembers the latest export for admin page reloads.'
);

ssgwp_assert_same(
	'render_page',
	$latest_progress_method->invoke( null )['state']['stage'],
	'get_latest_export_progress reloads the current export progress state.'
);

ssgwp_assert_same(
	HOUR_IN_SECONDS,
	$ssgwp_transients[ $progress_key ]['expiration'],
	'store_progress_event keeps progress available long enough for browser polling.'
);

$store_method->invoke(
	null,
	'job-1',
	array(
		'stage'   => 'zip_complete',
		'message' => 'Previous export finished.',
	),
	'run-previous'
);
$store_method->invoke(
	null,
	'job-1',
	array(
		'stage'   => 'started',
		'message' => 'New export started.',
	),
	'run-current'
);

$previous_run_key = $progress_key_method->invoke( null, 'job-1', 'run-previous' );
$current_run_key  = $progress_key_method->invoke( null, 'job-1', 'run-current' );

ssgwp_assert_not_same(
	$previous_run_key,
	$current_run_key,
	'progress_transient_key isolates repeated exports with different run ids.'
);

ssgwp_assert_same(
	'zip_complete',
	$ssgwp_transients[ $previous_run_key ]['value']['stage'],
	'store_progress_event keeps previous run progress isolated.'
);

ssgwp_assert_same(
	'started',
	$ssgwp_transients[ $current_run_key ]['value']['stage'],
	'store_progress_event stores current run progress separately.'
);

$store_method->invoke(
	null,
	'job-3',
	array(
		'stage'          => 'page_exported',
		'message'        => 'Exported a page.',
		'pages_exported' => 1,
		'context'        => array(
			'queue_position' => 11,
			'queue_total'    => 11,
		),
	),
	'run-3'
);

$log_key = $progress_key_method->invoke( null, 'job-3', 'run-3' );

ssgwp_assert_same(
	75,
	$ssgwp_transients[ $log_key ]['value']['percent'],
	'store_progress_event uses queue position so completed page discovery reaches the asset phase.'
);

$store_method->invoke(
	null,
	'job-3',
	array(
		'stage'   => 'zip_complete',
		'message' => 'Static export ZIP created.',
	),
	'run-3'
);

ssgwp_assert_same(
	100,
	$ssgwp_transients[ $log_key ]['value']['percent'],
	'store_progress_event advances terminal progress to 100 percent.'
);

ssgwp_assert_same(
	true,
	$ssgwp_transients[ $log_key ]['value']['is_terminal'],
	'store_progress_event marks completed exports as terminal for polling.'
);

ssgwp_assert_same(
	'page_exported',
	$ssgwp_transients[ $log_key ]['value']['log'][0]['stage'],
	'store_progress_event keeps a browser-facing log of completed actions.'
);

ssgwp_assert_same(
	'zip_complete',
	$ssgwp_transients[ $log_key ]['value']['log'][1]['stage'],
	'store_progress_event appends terminal events to the completed action log.'
);

$callback_method = new ReflectionMethod( 'SSGWP_Plugin', 'create_progress_callback' );
$callback_method->setAccessible( true );
$callback = $callback_method->invoke( null, 'job-2', 'run-2' );
$callback(
	array(
		'stage'   => 'zip_complete',
		'message' => 'Static export ZIP created.',
	)
);

$callback_key = $progress_key_method->invoke( null, 'job-2', 'run-2' );

ssgwp_assert_same(
	'zip_complete',
	$ssgwp_transients[ $callback_key ]['value']['stage'],
	'create_progress_callback stores exporter progress events.'
);

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
 * Assert two values are not identical.
 *
 * @param mixed  $unexpected Unexpected value.
 * @param mixed  $actual     Actual value.
 * @param string $message    Failure message.
 */
function ssgwp_assert_not_same( $unexpected, $actual, $message ) {
	if ( $unexpected !== $actual ) {
		return;
	}

	ssgwp_fail( $message . ' Did not expect ' . var_export( $actual, true ) . '.' );
}

/**
 * Assert that a string contains a substring.
 *
 * @param string $needle   Expected substring.
 * @param string $haystack Full string.
 * @param string $message  Failure message.
 */
function ssgwp_assert_contains( $needle, $haystack, $message ) {
	if ( false !== strpos( (string) $haystack, (string) $needle ) ) {
		return;
	}

	ssgwp_fail( $message . ' Expected to find ' . var_export( $needle, true ) . '.' );
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
