<?php
/**
 * Static export implementation.
 *
 * @package PlaygroundStaticSiteGenerator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports public WordPress pages and assets to a static directory or ZIP.
 */
final class SSGWP_Static_Exporter {
	const PLAYGROUND_WXR_URL_PLACEHOLDER = '__SSGWP_WXR_URL__';
	const PLAYGROUND_SOURCE_HANDOFF_OPTION = 'ssgwp_playground_source_handoff';

	/**
	 * Export warnings.
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Exported file count.
	 *
	 * @var int
	 */
	private $files_exported = 0;

	/**
	 * Linked assets already copied during this export.
	 *
	 * @var array<string,bool>
	 */
	private $linked_assets_copied = array();

	/**
	 * Dynamic behavior warning categories reported during this export.
	 *
	 * @var array<string,bool>
	 */
	private $dynamic_warnings = array();

	/**
	 * Current export root directory.
	 *
	 * @var string
	 */
	private $current_output_dir = '';

	/**
	 * Progress callback for long-running exports.
	 *
	 * @var callable|null
	 */
	private $progress_callback = null;

	/**
	 * Progress events emitted during the current export.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $progress = array();

	/**
	 * Export a site to a ZIP file.
	 *
	 * @param string $output_file Absolute path to the zip file.
	 * @param array  $args        Export options.
	 * @return array Export summary.
	 * @throws Exception When export fails.
	 */
	public function export_to_zip( $output_file, array $args = array() ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new Exception( 'The PHP zip extension is required to create static exports.' );
		}

		$output_file = wp_normalize_path( $output_file );
		$output_dir  = dirname( $output_file );

		if ( ! wp_mkdir_p( $output_dir ) ) {
			throw new Exception( 'Could not create the export output directory.' );
		}

		$work_dir = $this->make_temp_dir();

		try {
			$result = $this->export_to_directory( $work_dir, $args );
			$this->report_progress( 'zip', 'Creating static export ZIP.', array( 'output_file' => $output_file ) );
			$this->zip_directory( $work_dir, $output_file );
			$this->report_progress( 'zip_complete', 'Static export ZIP created.', array( 'output_file' => $output_file ) );
			$result['progress'] = $this->progress;
		} finally {
			$this->delete_directory( $work_dir );
		}

		return $result;
	}

	/**
	 * Export a site to a directory.
	 *
	 * @param string $output_dir Directory path.
	 * @param array  $args       Export options.
	 * @return array Export summary.
	 * @throws Exception When export fails.
	 */
	public function export_to_directory( $output_dir, array $args = array() ) {
		$this->warnings             = array();
		$this->files_exported       = 0;
		$this->linked_assets_copied = array();
		$this->dynamic_warnings     = array();
		$this->progress             = array();

		$args = wp_parse_args(
			$args,
			array(
				'url_mode'         => 'relative',
				'max_pages'        => 500,
				'copy_uploads'     => true,
				'copy_theme'       => true,
				'copy_plugins'     => true,
				'copy_core_assets' => true,
				'crawl_links'      => true,
				'include_manifest' => true,
				'generate_sitemap' => false,
				'generate_robots'  => false,
				'fetch_mode'       => 'auto',
				'progress_callback' => null,
				'include_playground_admin' => false,
				'include_playground_source_state' => false,
				'playground_source_bundle_url' => '',
				'playground_source_wxr_url' => '',
				'playground_source_expires_at' => '',
				'include_cloudflare_publish' => false,
				'cloudflare_worker_name' => 'stillpress-static-site',
				'cloudflare_compatibility_date' => '2026-06-08',
			)
		);

		$args['playground_source_wxr_url']    = $this->sanitize_playground_source_url( $args['playground_source_wxr_url'] );
		$args['playground_source_expires_at'] = $this->sanitize_plain_export_value( $args['playground_source_expires_at'] );

		if ( ! empty( $args['include_playground_source_state'] ) ) {
			$args['include_playground_admin'] = true;
		}

		$this->progress_callback = is_callable( $args['progress_callback'] ) ? $args['progress_callback'] : null;
		$output_dir = wp_normalize_path( $output_dir );

		if ( ! wp_mkdir_p( $output_dir ) ) {
			throw new Exception( 'Could not create the static export directory.' );
		}

		$output_dir = realpath( $output_dir );

		if ( false === $output_dir ) {
			throw new Exception( 'Could not resolve the static export directory.' );
		}

		$output_dir               = wp_normalize_path( $output_dir );
		$this->current_output_dir = $output_dir;

		$max_pages         = max( 1, (int) $args['max_pages'] );
		$collector         = new SSGWP_URL_Collector();
		$rewriter          = new SSGWP_URL_Rewriter( $collector, $args['url_mode'] );
		$queue             = $collector->collect( $max_pages );
		$queue_index       = 0;
		$seen              = array();
		$exported          = array();
		$linked_asset_urls = array();

		$this->report_progress(
			'discovered',
			sprintf( 'Discovered %d initial URLs for export.', count( $queue ) ),
			array(
				'queue_total' => count( $queue ),
				'max_pages'   => $max_pages,
			)
		);

		while ( isset( $queue[ $queue_index ] ) && count( $exported ) < $max_pages ) {
			$url = $queue[ $queue_index ];
			++$queue_index;

			$url = $collector->normalize_url( $url );

			if ( null === $url || isset( $seen[ $url ] ) ) {
				continue;
			}

			$seen[ $url ] = true;

			$this->report_progress(
				'render_page',
				sprintf( 'Rendering %s.', $url ),
				array(
					'url'            => $url,
					'queue_position' => $queue_index,
					'queue_total'    => count( $queue ),
				)
			);

			$response = $this->fetch_url( $url, $args );

			if ( is_wp_error( $response ) ) {
				$this->warnings[] = sprintf( 'Could not export %1$s: %2$s', $url, $response->get_error_message() );
				$this->report_progress(
					'page_failed',
					sprintf( 'Could not export %s.', $url ),
					array(
						'url'            => $url,
						'error'          => $response->get_error_message(),
						'queue_position' => $queue_index,
						'queue_total'    => count( $queue ),
					)
				);
				continue;
			}

			$target_path = $this->url_to_file_path( $url );
			$response    = $this->inject_missing_core_block_styles( $response );
			$response    = $this->ensure_html_charset( $response );
			$this->collect_dynamic_behavior_warnings( $response, $url );
			$rewritten   = $rewriter->rewrite_html( $response, $url, $target_path );

			$this->write_file( trailingslashit( $output_dir ) . $target_path, $rewritten['content'] );
			$exported[] = $url;
			$this->report_progress(
				'page_exported',
				sprintf( 'Exported %s.', $url ),
				array(
					'url'            => $url,
					'target_path'    => $target_path,
					'pages_exported' => count( $exported ),
					'queue_position' => $queue_index,
					'queue_total'    => count( $queue ),
				)
			);

			foreach ( $rewritten['assets'] as $asset_url ) {
				$linked_asset_urls[ $asset_url ] = $asset_url;
			}

			if ( ! empty( $args['crawl_links'] ) ) {
				foreach ( $rewritten['links'] as $linked_url ) {
					if ( ! isset( $seen[ $linked_url ] ) ) {
						$queue[] = $linked_url;
					}
				}
			}
		}

		if ( count( $exported ) >= $max_pages && $queue_index < count( $queue ) ) {
			$this->warnings[] = sprintf( 'Stopped after reaching the max page limit of %d.', $max_pages );
		}

		if ( empty( $exported ) ) {
			throw new Exception( 'No pages were exported. ' . implode( ' ', $this->warnings ) );
		}

		$this->report_progress( 'copy_assets', 'Copying frontend assets.', array( 'output_dir' => $output_dir ) );
		$this->copy_assets( $output_dir, $args );
		$this->report_progress( 'copy_linked_assets', 'Copying linked same-site assets.', array( 'asset_count' => count( $linked_asset_urls ) ) );
		$this->copy_linked_assets( array_values( $linked_asset_urls ), $output_dir );
		$this->rewrite_copied_text_assets_and_copy_dependencies( $output_dir, $rewriter );

		if ( ! empty( $args['generate_sitemap'] ) ) {
			$this->report_progress( 'generate_sitemap', 'Generating sitemap.xml.', array( 'url_count' => count( $exported ) ) );
			$this->write_sitemap( $output_dir, $exported );
		}

		if ( ! empty( $args['generate_robots'] ) ) {
			$this->report_progress( 'generate_robots', 'Generating robots.txt.', array( 'sitemap' => 'sitemap.xml' ) );
			$this->write_robots_txt( $output_dir );
		}

		$this->write_preview_instructions( $output_dir );

		$playground_source_state = null;
		if ( ! empty( $args['include_playground_source_state'] ) ) {
			$this->report_progress( 'playground_source_state', 'Writing WordPress Playground source-state artifacts.', array( 'path' => '_playground-source/source-state.json' ) );
			$playground_source_state = $this->write_playground_source_state( $output_dir, $args );
		}

		$playground_admin = null;
		if ( ! empty( $args['include_playground_admin'] ) ) {
			$this->report_progress( 'playground_admin', 'Writing WordPress Playground admin handoff.', array( 'path' => 'wp-admin/index.html' ) );
			$playground_admin = $this->write_playground_admin_handoff( $output_dir, $args, $playground_source_state );
		}

		$cloudflare_publish = null;
		if ( ! empty( $args['include_cloudflare_publish'] ) ) {
			$this->report_progress( 'cloudflare_publish', 'Writing Cloudflare Workers publish contract.', array( 'path' => '_cloudflare-publish/cloudflare-publish.json' ) );
			$cloudflare_publish = $this->write_cloudflare_publish_contract( $output_dir, $args );
		}

		$this->report_progress(
			'complete',
			sprintf( 'Exported %1$d pages and %2$d files.', count( $exported ), $this->files_exported ),
			array(
				'pages_exported' => count( $exported ),
				'files_exported' => $this->files_exported,
			)
		);

		$result = array(
			'generated_at'    => gmdate( 'c' ),
			'home_url'        => home_url( '/' ),
			'pages_exported'  => count( $exported ),
			'files_exported'  => $this->files_exported,
			'exported_urls'   => $exported,
			'warnings'        => $this->warnings,
			'wordpress'       => get_bloginfo( 'version' ),
			'plugin_version'  => SSGWP_VERSION,
			'url_mode'        => $args['url_mode'],
			'generated_sitemap' => ! empty( $args['generate_sitemap'] ),
			'generated_robots' => ! empty( $args['generate_robots'] ),
			'progress'        => $this->progress,
			'playground_note' => 'This static export can be hosted anywhere. Keep a WordPress Playground site export separately if you want to restore the editable source site later.',
		);

		if ( null !== $playground_admin ) {
			$result['playground_admin'] = $playground_admin;
		}

		if ( null !== $playground_source_state ) {
			$result['playground_source_state'] = $playground_source_state;
		}

		if ( null !== $cloudflare_publish ) {
			$result['cloudflare_publish'] = $cloudflare_publish;
		}

		if ( ! empty( $args['include_manifest'] ) ) {
			$manifest_write_increments = true;
			$cloudflare_manifest_ready = null === $cloudflare_publish || empty( $cloudflare_publish['asset_directory'] );

			for ( $manifest_attempt = 0; $manifest_attempt < 5; ++$manifest_attempt ) {
				$static_manifest = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				$this->write_file(
					trailingslashit( $output_dir ) . 'static-export.json',
					$static_manifest,
					$manifest_write_increments
				);

				if ( null === $cloudflare_publish || empty( $cloudflare_publish['asset_directory'] ) ) {
					break;
				}

				$this->write_file(
					trailingslashit( $output_dir ) . $cloudflare_publish['asset_directory'] . '/static-export.json',
					$static_manifest,
					$manifest_write_increments
				);

				$manifest_write_increments = false;
				$previous_asset_inventory  = isset( $cloudflare_publish['asset_inventory'] ) ? $cloudflare_publish['asset_inventory'] : null;
				$cloudflare_publish        = $this->refresh_cloudflare_publish_asset_inventory( $output_dir, $cloudflare_publish );
				$result['cloudflare_publish'] = $cloudflare_publish;

				if ( $previous_asset_inventory === $cloudflare_publish['asset_inventory'] ) {
					$cloudflare_manifest_ready = true;
					break;
				}
			}

			if ( ! $cloudflare_manifest_ready ) {
				throw new Exception( 'Could not stabilize the Cloudflare publish manifest asset inventory.' );
			}
		}

		return $result;
	}

	/**
	 * Emit a progress event.
	 *
	 * @param string $stage   Short stage identifier.
	 * @param string $message Human-readable progress message.
	 * @param array  $context Additional structured context.
	 */
	private function report_progress( $stage, $message, array $context = array() ) {
		$event = array(
			'time'           => gmdate( 'c' ),
			'stage'          => $stage,
			'message'        => $message,
			'pages_exported' => isset( $context['pages_exported'] ) ? (int) $context['pages_exported'] : 0,
			'files_exported' => $this->files_exported,
			'context'        => $context,
		);

		$this->progress[] = $event;

		if ( null !== $this->progress_callback ) {
			call_user_func( $this->progress_callback, $event );
		}
	}

	/**
	 * Write a sitemap for the exported public pages.
	 *
	 * @param string   $output_dir    Static export directory.
	 * @param string[] $exported_urls Exported page URLs.
	 * @throws Exception When the file cannot be written.
	 */
	private function write_sitemap( $output_dir, array $exported_urls ) {
		$lines = array(
			'<?xml version="1.0" encoding="UTF-8"?>',
			'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
		);

		foreach ( $exported_urls as $url ) {
			$lines[] = "\t<url>";
			$lines[] = "\t\t<loc>" . htmlspecialchars( $url, ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '</loc>';
			$lines[] = "\t</url>";
		}

		$lines[] = '</urlset>';

		$this->write_file( trailingslashit( $output_dir ) . 'sitemap.xml', implode( "\n", $lines ) . "\n" );
	}

	/**
	 * Write a robots.txt that points crawlers to the generated sitemap.
	 *
	 * @param string $output_dir Static export directory.
	 * @throws Exception When the file cannot be written.
	 */
	private function write_robots_txt( $output_dir ) {
		$contents = "User-agent: *\nAllow: /\nSitemap: " . home_url( '/sitemap.xml' ) . "\n";

		$this->write_file( trailingslashit( $output_dir ) . 'robots.txt', $contents );
	}

	/**
	 * Write local preview instructions into the static export.
	 *
	 * Browser file previews are useful for simple pages, but module scripts are
	 * blocked on file:// origins. A local HTTP server gives the static export the
	 * same protocol shape it will have on real hosting.
	 *
	 * @param string $output_dir Static export directory.
	 * @throws Exception When the file cannot be written.
	 */
	private function write_preview_instructions( $output_dir ) {
		$contents = implode(
			"\n",
			array(
				'Static export preview',
				'',
				'Open index.html directly for a quick file:// preview of basic HTML and CSS.',
				'Some browser features, including JavaScript ES modules used by the WordPress Interactivity API, cannot run from file:// origins.',
				'For the closest local preview, serve the extracted folder over HTTP:',
				'',
				'python3 -m http.server 8080',
				'',
				'Then open http://localhost:8080/ in your browser.',
				'',
				'Forms, search, comments, carts, checkout, account pages, and REST API writes need a live backend or a static-compatible service.',
				'',
				'Operational artifacts:',
				'',
				'- `_playground-source/`, when present, contains owner-only source-state restore metadata and WXR content. Do not upload or serve it publicly unless that is intentional; use signed or private URLs for owner-only restore.',
				'- `_cloudflare-publish/`, when present, is a Cloudflare Workers deploy package. Its public static assets are in `_cloudflare-publish/site/`.',
				'- Generic static hosting should not blindly upload or serve `_playground-source/` or `_cloudflare-publish/`; publish the static site files you intend visitors to access.',
				''
			)
		);

		$this->write_file( trailingslashit( $output_dir ) . '_static-export-preview.txt', $contents );
	}

	/**
	 * Write owner-only Playground source-state artifacts for future restore flows.
	 *
	 * @param string $output_dir Static export directory.
	 * @return array<string,mixed> Public export summary.
	 * @throws Exception When files cannot be written.
	 */
	private function write_playground_source_state( $output_dir, array $args = array() ) {
		$wxr_export                 = $this->generate_site_content_wxr();
		$wxr_hash                   = hash( 'sha256', $wxr_export['content'] );
		$policy                     = $this->playground_source_policy_metadata( $args );
		$wordpress_files_snapshot   = $this->write_playground_wordpress_files_snapshot( $output_dir );
		$source_policy              = $this->is_playground_wordpress_files_snapshot_available( $wordpress_files_snapshot )
			? $this->playground_source_policy_without_effective_wxr_url( $policy )
			: $policy;
		$pending_bundle_metadata    = $this->playground_blueprint_bundle_metadata( null, $wordpress_files_snapshot );
		$bundle_source_state        = $this->playground_source_state_manifest(
			$wxr_hash,
			$wxr_export['method'],
			$this->playground_bundle_source_policy_metadata( $source_policy ),
			$pending_bundle_metadata,
			$wordpress_files_snapshot
		);
		$bundle_source_manifest     = $this->playground_bundle_source_manifest( $wxr_hash, $pending_bundle_metadata, $wordpress_files_snapshot );
		$bundle_blueprint           = $this->playground_handoff_blueprint(
			'source-state-generated',
			$bundle_source_manifest,
			! empty( $args['include_cloudflare_publish'] )
		);
		$generated_bundle_metadata  = $this->write_playground_blueprint_bundle(
			$output_dir,
			$bundle_blueprint,
			$wxr_export['content'],
			$bundle_source_state,
			$wordpress_files_snapshot
		);
		$manifest                   = $this->playground_source_state_manifest( $wxr_hash, $wxr_export['method'], $source_policy, $generated_bundle_metadata, $wordpress_files_snapshot );

		$this->write_file(
			trailingslashit( $output_dir ) . '_playground-source/site-content.wxr',
			$wxr_export['content'],
			true,
			true
		);
		$this->write_file(
			trailingslashit( $output_dir ) . '_playground-source/source-state.json',
			$this->encode_static_json( $manifest ),
			true,
			true
		);

		return array(
			'included'              => true,
			'directory'             => '_playground-source',
			'source_state_path'     => '_playground-source/source-state.json',
			'wxr_path'              => '_playground-source/site-content.wxr',
			'wxr_sha256'            => $wxr_hash,
			'wxr_generation_method' => $wxr_export['method'],
			'wordpress_files_snapshot' => $this->playground_wordpress_files_snapshot_public_metadata( $wordpress_files_snapshot ),
			'wordpress_files_snapshot_status' => $wordpress_files_snapshot['status'],
			'wordpress_files_snapshot_path' => $wordpress_files_snapshot['path'],
			'wordpress_files_snapshot_sha256' => $wordpress_files_snapshot['sha256'],
			'wordpress_files_snapshot_mode' => $wordpress_files_snapshot['mode'],
			'wordpress_files_snapshot_sqlite_database_captured' => $wordpress_files_snapshot['sqlite_database_captured'],
			'wordpress_files_snapshot_file_count' => $wordpress_files_snapshot['file_count'],
			'wordpress_files_snapshot_total_size_bytes' => $wordpress_files_snapshot['total_size_bytes'],
			'blueprint_bundle_path' => $generated_bundle_metadata['path'],
			'blueprint_bundle_sha256' => $generated_bundle_metadata['sha256'],
			'blueprint_bundle_mode' => $generated_bundle_metadata['mode'],
			'blueprint_bundle_status' => $generated_bundle_metadata['status'],
			'blueprint_bundle_url_usage' => $generated_bundle_metadata['playground_url_usage'],
			'restore_status'        => 'source-state-generated',
			'wxr_import_enabled'    => true,
			'wxr_url_mode'          => $source_policy['wxr_url_mode'],
			'wxr_url_requirement'   => $manifest['restore']['wxr_url_requirement'],
			'source_access_expires_at' => $source_policy['source_access_expires_at'],
			'source_access_expires_at_status' => $source_policy['source_access_expires_at_status'],
			'owner_access_policy'   => $source_policy['owner_access_policy'],
			'security_warning'      => $manifest['security_warning'],
			'limitations'           => $manifest['limitations'],
		) + ( isset( $source_policy['effective_wxr_url'] ) ? array( 'effective_wxr_url' => $source_policy['effective_wxr_url'] ) : array() );
	}

	/**
	 * Build source-state metadata for the owner-only restore artifact directory.
	 *
	 * @param string $wxr_hash              SHA-256 hash of site-content.wxr.
	 * @param string $wxr_generation_method How the WXR was generated.
	 * @param array<string,mixed>|null $blueprint_bundle Owner-only Blueprint bundle metadata.
	 * @return array<string,mixed>
	 */
	private function playground_source_state_manifest( $wxr_hash, $wxr_generation_method, array $policy, $blueprint_bundle = null, array $wordpress_files_snapshot = null ) {
		$is_bundled_resource = 'bundled-resource' === $policy['wxr_url_mode'];
		$has_full_site_snapshot = $this->is_playground_wordpress_files_snapshot_available( $wordpress_files_snapshot );
		$content_wxr_path    = $is_bundled_resource ? '/content/site-content.wxr' : '_playground-source/site-content.wxr';
		$restore = array(
			'status' => 'source-state-generated',
			'content_wxr' => $content_wxr_path,
			'content_wxr_sha256' => $wxr_hash,
			'wxr_import_enabled' => true,
			'wxr_import_step' => 'importWxr',
			'wxr_url_mode' => $policy['wxr_url_mode'],
			'wxr_url_requirement' => $is_bundled_resource
				? 'No external WXR URL is required for this Blueprint bundle; the WXR is included as a bundled resource.'
				: 'The WXR file must be reachable by WordPress Playground through a public, signed, or private URL before the handoff can import content.',
			'source_access_expires_at' => $policy['source_access_expires_at'],
			'source_access_expires_at_status' => $policy['source_access_expires_at_status'],
			'not_full_restore_bundle' => true,
		);
		$artifacts = array(
			'directory' => '_playground-source',
			'source_state' => '_playground-source/source-state.json',
			'wxr' => $is_bundled_resource ? 'content/site-content.wxr' : '_playground-source/site-content.wxr',
			'wxr_sha256' => $wxr_hash,
			'wxr_generation_method' => $wxr_generation_method,
			'wxr_url_mode' => $policy['wxr_url_mode'],
		);

		if ( is_array( $wordpress_files_snapshot ) ) {
			$artifacts['wordpress_files_snapshot'] = $this->playground_wordpress_files_snapshot_public_metadata( $wordpress_files_snapshot );
			$restore['wordpress_files_snapshot'] = $this->playground_wordpress_files_snapshot_public_metadata( $wordpress_files_snapshot );
		}

		if ( is_array( $blueprint_bundle ) ) {
			$is_full_site_bundle = ! empty( $blueprint_bundle['full_site_restore'] );
			$bundle_metadata = array(
				'path' => isset( $blueprint_bundle['path'] ) ? (string) $blueprint_bundle['path'] : '_playground-source/playground-blueprint-bundle.zip',
				'sha256' => isset( $blueprint_bundle['sha256'] ) ? $blueprint_bundle['sha256'] : null,
				'mode' => isset( $blueprint_bundle['mode'] ) ? (string) $blueprint_bundle['mode'] : 'content-only-playground-blueprint-bundle',
				'status' => isset( $blueprint_bundle['status'] ) ? (string) $blueprint_bundle['status'] : 'content-only-blueprint-bundle-generated',
				'blueprint_path' => isset( $blueprint_bundle['blueprint_path'] ) ? (string) $blueprint_bundle['blueprint_path'] : 'blueprint.json',
				'wxr_resource_path' => isset( $blueprint_bundle['wxr_resource_path'] ) ? (string) $blueprint_bundle['wxr_resource_path'] : '/content/site-content.wxr',
				'wxr_zip_path' => isset( $blueprint_bundle['wxr_zip_path'] ) ? (string) $blueprint_bundle['wxr_zip_path'] : 'content/site-content.wxr',
				'wordpress_files_resource_path' => isset( $blueprint_bundle['wordpress_files_resource_path'] ) ? (string) $blueprint_bundle['wordpress_files_resource_path'] : null,
				'wordpress_files_zip_path' => isset( $blueprint_bundle['wordpress_files_zip_path'] ) ? (string) $blueprint_bundle['wordpress_files_zip_path'] : null,
				'source_state_metadata_path' => isset( $blueprint_bundle['source_state_metadata_path'] ) ? (string) $blueprint_bundle['source_state_metadata_path'] : 'source-state.json',
				'content_only' => ! $is_full_site_bundle,
				'full_site_restore' => $is_full_site_bundle,
				'not_full_restore_bundle' => ! $is_full_site_bundle,
				'owner_only' => true,
				'playground_url_usage' => isset( $blueprint_bundle['playground_url_usage'] ) ? (string) $blueprint_bundle['playground_url_usage'] : 'Owner/operator may intentionally host this ZIP and use its URL as a WordPress Playground ?blueprint-url= bundle URL.',
			);

			$artifacts['blueprint_bundle'] = $bundle_metadata['path'];
			$artifacts['blueprint_bundle_sha256'] = $bundle_metadata['sha256'];
			$artifacts['blueprint_bundle_mode'] = $bundle_metadata['mode'];
			$artifacts['blueprint_bundle_status'] = $bundle_metadata['status'];
			$artifacts['blueprint_bundle_blueprint'] = $bundle_metadata['blueprint_path'];
			if ( $is_full_site_bundle ) {
				$artifacts['blueprint_bundle_wordpress_files_resource'] = $bundle_metadata['wordpress_files_resource_path'];
			} else {
				$artifacts['blueprint_bundle_wxr_resource'] = $bundle_metadata['wxr_resource_path'];
			}
			$restore['blueprint_bundle'] = $bundle_metadata;
			$restore['bundle_status'] = $bundle_metadata['status'];
			$restore['bundle_mode'] = $bundle_metadata['mode'];
			$restore['content_only_blueprint_bundle'] = ! $is_full_site_bundle;
			$restore['full_site_blueprint_bundle'] = $is_full_site_bundle;
		}

		if ( $has_full_site_snapshot ) {
			$restore['full_site_restore_available'] = true;
			$restore['sqlite_database_captured'] = true;
			$restore['full_site_restore_note'] = 'SQLite-backed source sites can use the owner-only Blueprint bundle to import wp-content plus the SQLite database, restoring plugins, themes, uploads, content, and database-stored settings.';
		} elseif ( is_array( $wordpress_files_snapshot ) ) {
			$restore['full_site_restore_available'] = false;
			$restore['sqlite_database_captured'] = false;
			$restore['wxr_fallback_note'] = 'No readable SQLite database was captured, so the owner-only Blueprint bundle remains WXR/content-only.';
		}

		if ( $is_bundled_resource ) {
			$restore['wxr_resource'] = array(
				'resource' => 'bundled',
				'path' => $content_wxr_path,
			);
		} elseif ( isset( $policy['effective_wxr_url'] ) ) {
			$restore['effective_wxr_url'] = $policy['effective_wxr_url'];
		} else {
			$restore['wxr_url_runtime_expression'] = 'new URL("../_playground-source/site-content.wxr", window.location.href).href';
		}

		return array(
			'schema' => 'https://stillpress.local/playground-source-state/v1',
			'version' => 1,
			'generated_at' => gmdate( 'c' ),
			'home_url' => home_url( '/' ),
			'site_url' => site_url( '/' ),
			'wordpress_version' => get_bloginfo( 'version' ),
			'stillpress_version' => SSGWP_VERSION,
			'active_theme' => $this->get_active_theme_metadata(),
			'active_plugins' => $this->get_active_plugin_metadata(),
			'artifacts' => $artifacts,
			'restore' => $restore,
			'owner_access_policy' => $policy['owner_access_policy'],
			'limitations' => $this->playground_source_state_limitations( $has_full_site_snapshot ),
			'security_warning' => 'The _playground-source directory may expose editable source content if uploaded publicly; do not blindly expose it on generic hosting, and use intentional public, signed, or private URLs for owner-only restore.',
		);
	}

	/**
	 * Build Blueprint bundle metadata shared by source-state manifests.
	 *
	 * @param string|null $sha256 ZIP SHA-256 hash when known.
	 * @return array<string,mixed>
	 */
	private function playground_blueprint_bundle_metadata( $sha256 = null, array $wordpress_files_snapshot = null ) {
		$has_full_site_snapshot = $this->is_playground_wordpress_files_snapshot_available( $wordpress_files_snapshot );
		$metadata = array(
			'path' => '_playground-source/playground-blueprint-bundle.zip',
			'sha256' => $sha256,
			'mode' => $has_full_site_snapshot ? 'sqlite-full-site-playground-blueprint-bundle' : 'content-only-playground-blueprint-bundle',
			'status' => $has_full_site_snapshot ? 'sqlite-full-site-blueprint-bundle-generated' : 'content-only-blueprint-bundle-generated',
			'blueprint_path' => 'blueprint.json',
			'wxr_resource_path' => '/content/site-content.wxr',
			'wxr_zip_path' => 'content/site-content.wxr',
			'wordpress_files_resource_path' => $has_full_site_snapshot ? '/wordpress-files.zip' : null,
			'wordpress_files_zip_path' => $has_full_site_snapshot ? 'wordpress-files.zip' : null,
			'source_state_metadata_path' => 'source-state.json',
			'content_only' => ! $has_full_site_snapshot,
			'full_site_restore' => $has_full_site_snapshot,
			'not_full_restore_bundle' => ! $has_full_site_snapshot,
			'owner_only' => true,
			'playground_url_usage' => 'Owner/operator may intentionally host this ZIP and use its URL as a WordPress Playground ?blueprint-url= bundle URL; keep it owner-only unless that exposure is intentional.',
		);

		if ( $has_full_site_snapshot ) {
			$metadata['restore_note'] = 'This bundle imports bundled WordPress files, including wp-content/database/.ht.sqlite, before landing in the restored admin.';
		}

		return $metadata;
	}

	/**
	 * Build a source policy for metadata embedded in the Blueprint bundle.
	 *
	 * Provided WXR URLs may be signed, so bundle metadata avoids copying them.
	 *
	 * @param array<string,mixed> $policy External source-state policy.
	 * @return array<string,mixed>
	 */
	private function playground_bundle_source_policy_metadata( array $policy ) {
		$bundle_policy = $policy;
		$bundle_policy['wxr_url_mode'] = 'bundled-resource';
		$bundle_policy['source_access_expires_at'] = null;
		$bundle_policy['source_access_expires_at_status'] = 'not-applicable-bundled-resource';
		unset( $bundle_policy['effective_wxr_url'] );
		unset( $bundle_policy['owner_access_policy']['provided_url_sensitivity_note'] );

		return $bundle_policy;
	}

	/**
	 * Remove effective WXR URLs from full-site metadata paths.
	 *
	 * A caller-provided WXR URL may be signed. Full-site SQLite bundles do not
	 * need it because they restore from bundled WordPress files.
	 *
	 * @param array<string,mixed> $policy Source-state policy.
	 * @return array<string,mixed>
	 */
	private function playground_source_policy_without_effective_wxr_url( array $policy ) {
		$policy['wxr_url_mode'] = 'runtime-relative-export-path';
		unset( $policy['effective_wxr_url'] );
		unset( $policy['owner_access_policy']['provided_url_sensitivity_note'] );

		return $policy;
	}

	/**
	 * Build the source-state manifest section used inside the bundled Blueprint.
	 *
	 * @param string $wxr_hash SHA-256 hash of bundled WXR content.
	 * @param array<string,mixed> $bundle_metadata Blueprint bundle metadata.
	 * @return array<string,mixed>
	 */
	private function playground_bundle_source_manifest( $wxr_hash, array $bundle_metadata, array $wordpress_files_snapshot = null ) {
		$has_full_site_snapshot = $this->is_playground_wordpress_files_snapshot_available( $wordpress_files_snapshot );
		$manifest = array(
			'status' => 'source-state-generated',
			'bundle_url' => null,
			'source_state_path' => 'source-state.json',
			'wxr_path' => $bundle_metadata['wxr_resource_path'],
			'wxr_sha256' => $wxr_hash,
			'wxr_import_enabled' => ! $has_full_site_snapshot,
			'wxr_url_mode' => $has_full_site_snapshot ? 'not-used-full-site-sqlite' : 'bundled-resource',
			'wxr_url_requirement' => $has_full_site_snapshot
				? 'The bundled WXR is retained as a fallback artifact, but the full-site SQLite bundle imports WordPress files instead of running importWxr.'
				: 'No external WXR URL is required for this Blueprint bundle; the WXR is included as a bundled resource.',
			'source_access_expires_at' => null,
			'source_access_expires_at_status' => 'not-applicable-bundled-resource',
			'content_restore' => array(
				'type' => 'wxr',
				'enabled' => true,
				'blueprint_step' => 'importWxr',
				'file_resource' => 'bundled',
				'path' => $bundle_metadata['wxr_resource_path'],
			),
			'owner_access_policy' => array(
				'artifact_directory' => '_playground-source',
				'owner_only' => true,
				'may_expose_editable_source_content' => true,
				'serve_only_through' => 'intentional-public-signed-or-private-url',
				'generated_artifacts_authorize_redeploy' => false,
				'deploy_credentials_stored' => false,
				'owner_identity_stored' => false,
				'authorization_tokens_stored' => false,
				'access_note' => 'The Blueprint bundle is owner-only restore material and should be hosted as a Playground ?blueprint-url= bundle only when intentionally exposed by the owner/operator.',
				'secrets_note' => 'No deploy credentials, owner identity, authorization tokens, or explicit WXR URL are stored in the bundled Blueprint.',
				'redeploy_authorization_note' => 'Redeploy is a local/generated workflow that must be run by an authorized owner/operator with credentials; generated artifacts do not authorize a redeploy by themselves.',
			),
			'blueprint_bundle_path' => $bundle_metadata['path'],
			'blueprint_bundle_sha256' => isset( $bundle_metadata['sha256'] ) ? $bundle_metadata['sha256'] : null,
			'blueprint_bundle_mode' => $bundle_metadata['mode'],
			'blueprint_bundle_status' => $bundle_metadata['status'],
			'blueprint_bundle_url_usage' => $bundle_metadata['playground_url_usage'],
			'not_full_restore_bundle' => ! $has_full_site_snapshot,
			'note' => 'This Blueprint bundle imports bundled WXR content only; it is not a full database, plugin settings, users, secrets, or runtime restore.',
			'limitations' => array(
				'WXR imports content only.',
				'The bundle does not restore the full database, plugin settings, users, secrets, or runtime configuration.',
				'The bundle should be hosted as a Playground ?blueprint-url= URL only when intentionally exposed by the owner/operator.',
			),
		);

		if ( $has_full_site_snapshot ) {
			$manifest['content_restore'] = array(
				'type' => 'wordpress-files-sqlite',
				'enabled' => true,
				'blueprint_step' => 'importWordPressFiles',
				'file_resource' => 'bundled',
				'path' => $bundle_metadata['wordpress_files_resource_path'],
				'wxr_import_enabled' => false,
			);
			$manifest['wordpress_files_snapshot'] = $this->playground_wordpress_files_snapshot_public_metadata( $wordpress_files_snapshot );
			$manifest['full_site_restore'] = true;
			$manifest['note'] = 'This owner-only Blueprint bundle imports wp-content plus the SQLite database, restoring plugins, themes, uploads, content, and database-stored settings for SQLite-backed Playground/source sites.';
			$manifest['limitations'] = array(
				'The full-site SQLite bundle is owner-only restore material, not a credential or authorization system.',
				'Secrets and symlink targets are intentionally excluded from wordpress-files.zip.',
				'Non-SQLite/MySQL source sites still fall back to WXR/content-only until a database dump and restore path exists.',
			);
		}

		return $manifest;
	}

	/**
	 * Write an owner-only Playground Blueprint bundle ZIP.
	 *
	 * @param string              $output_dir Static export directory.
	 * @param array<string,mixed> $blueprint  Bundle Blueprint.
	 * @param string              $wxr        WXR contents.
	 * @param array<string,mixed> $source_state Source-state metadata to include in the bundle.
	 * @return array<string,mixed> Generated bundle metadata, including SHA-256 hash.
	 * @throws Exception When ZIP generation fails.
	 */
	private function write_playground_blueprint_bundle( $output_dir, array $blueprint, $wxr, array $source_state, array $wordpress_files_snapshot = null ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new Exception( 'The PHP zip extension is required to generate the Playground Blueprint bundle.' );
		}

		$metadata    = $this->playground_blueprint_bundle_metadata( null, $wordpress_files_snapshot );
		$bundle_path = trailingslashit( $output_dir ) . $metadata['path'];
		$bundle_path = wp_normalize_path( $bundle_path );

		if ( SSGWP_Path_Utils::has_parent_segment( $bundle_path ) || ! $this->is_inside_export_root( $bundle_path ) ) {
			throw new Exception( 'Refusing to write the Playground Blueprint bundle outside of the export directory.' );
		}

		if ( ! wp_mkdir_p( dirname( $bundle_path ) ) ) {
			throw new Exception( 'Could not create the Playground source-state directory for the Blueprint bundle.' );
		}

		$directory = realpath( dirname( $bundle_path ) );

		if ( false === $directory || ! $this->is_inside_export_root( $directory ) ) {
			throw new Exception( 'Refusing to write the Playground Blueprint bundle outside of the export directory.' );
		}

		if ( is_link( $bundle_path ) ) {
			if ( ! unlink( $bundle_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				throw new Exception( 'Could not replace an existing Playground Blueprint bundle symlink.' );
			}
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $bundle_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new Exception( 'Could not open the Playground Blueprint bundle for writing.' );
		}

		$entries = array(
			'blueprint.json' => $this->encode_static_json( $blueprint ),
			'content/site-content.wxr' => (string) $wxr,
			'source-state.json' => $this->encode_static_json( $source_state ),
		);

		if ( $this->is_playground_wordpress_files_snapshot_available( $wordpress_files_snapshot ) ) {
			$wordpress_files_path = trailingslashit( $output_dir ) . $wordpress_files_snapshot['path'];
			$entries[ $metadata['wordpress_files_zip_path'] ] = file_get_contents( $wordpress_files_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		foreach ( $entries as $entry_name => $entry_contents ) {
			if ( ! $zip->addFromString( $entry_name, $entry_contents ) ) {
				$zip->close();
				throw new Exception( 'Could not add ' . $entry_name . ' to the Playground Blueprint bundle.' );
			}

			if ( method_exists( $zip, 'setMtimeName' ) ) {
				$zip->setMtimeName( $entry_name, 0 );
			}
		}

		if ( ! $zip->close() ) {
			throw new Exception( 'Could not finalize the Playground Blueprint bundle.' );
		}

		++$this->files_exported;

		return $this->playground_blueprint_bundle_metadata( hash_file( 'sha256', $bundle_path ), $wordpress_files_snapshot );
	}

	/**
	 * Write a full-site WordPress files snapshot for SQLite-backed source sites.
	 *
	 * @param string $output_dir Static export directory.
	 * @return array<string,mixed> Snapshot metadata.
	 * @throws Exception When ZIP generation fails.
	 */
	private function write_playground_wordpress_files_snapshot( $output_dir ) {
		$metadata = $this->playground_wordpress_files_snapshot_empty_metadata();
		$db_path  = $this->playground_sqlite_database_path();

		if ( null === $db_path ) {
			$metadata['status'] = 'unavailable-no-readable-sqlite-database';
			$metadata['reason'] = 'No readable SQLite database was found at wp-content/database/.ht.sqlite.';
			return $metadata;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new Exception( 'The PHP zip extension is required to generate the Playground WordPress files snapshot.' );
		}

		$entries = array();
		$this->playground_snapshot_add_file( $db_path, 'wp-content/database/.ht.sqlite', $entries, $metadata, 'database' );
		$this->playground_snapshot_collect_active_plugins( $entries, $metadata );
		$this->playground_snapshot_collect_active_themes( $entries, $metadata );
		$this->playground_snapshot_collect_uploads( $entries, $metadata );

		if ( ! isset( $entries['wp-content/database/.ht.sqlite'] ) ) {
			$metadata['status'] = 'unavailable-no-readable-sqlite-database';
			$metadata['reason'] = 'The SQLite database path was rejected by the snapshot safety filter.';
			return $metadata;
		}

		ksort( $entries, SORT_STRING );

		$snapshot_path = trailingslashit( $output_dir ) . $metadata['path'];
		$snapshot_path = wp_normalize_path( $snapshot_path );

		if ( SSGWP_Path_Utils::has_parent_segment( $snapshot_path ) || ! $this->is_inside_export_root( $snapshot_path ) ) {
			throw new Exception( 'Refusing to write the Playground WordPress files snapshot outside of the export directory.' );
		}

		if ( ! wp_mkdir_p( dirname( $snapshot_path ) ) ) {
			throw new Exception( 'Could not create the Playground source-state directory for the WordPress files snapshot.' );
		}

		$directory = realpath( dirname( $snapshot_path ) );

		if ( false === $directory || ! $this->is_inside_export_root( $directory ) ) {
			throw new Exception( 'Refusing to write the Playground WordPress files snapshot outside of the export directory.' );
		}

		if ( is_link( $snapshot_path ) ) {
			if ( ! unlink( $snapshot_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				throw new Exception( 'Could not replace an existing Playground WordPress files snapshot symlink.' );
			}
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $snapshot_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new Exception( 'Could not open the Playground WordPress files snapshot for writing.' );
		}

		foreach ( $entries as $entry_name => $source_path ) {
			if ( ! $zip->addFile( $source_path, $entry_name ) ) {
				$zip->close();
				throw new Exception( 'Could not add ' . $entry_name . ' to the Playground WordPress files snapshot.' );
			}

			if ( method_exists( $zip, 'setMtimeName' ) ) {
				$zip->setMtimeName( $entry_name, 0 );
			}
		}

		if ( ! $zip->close() ) {
			throw new Exception( 'Could not finalize the Playground WordPress files snapshot.' );
		}

		++$this->files_exported;

		$metadata['status'] = 'available';
		$metadata['mode'] = 'sqlite-full-site-wordpress-files';
		$metadata['sha256'] = hash_file( 'sha256', $snapshot_path );
		$metadata['sqlite_database_captured'] = true;
		$metadata['file_count'] = count( $entries );
		$metadata['entry_count'] = count( $entries );
		unset( $metadata['reason'] );

		return $metadata;
	}

	/**
	 * Return default metadata for the WordPress files snapshot.
	 *
	 * @return array<string,mixed>
	 */
	private function playground_wordpress_files_snapshot_empty_metadata() {
		return array(
			'status' => 'unavailable',
			'path' => '_playground-source/wordpress-files.zip',
			'sha256' => null,
			'mode' => 'wxr-content-only-fallback',
			'owner_only' => true,
			'sqlite_database_captured' => false,
			'file_count' => 0,
			'entry_count' => 0,
			'total_size_bytes' => 0,
			'database_file_count' => 0,
			'plugin_file_count' => 0,
			'theme_file_count' => 0,
			'upload_file_count' => 0,
			'skipped_symlink_count' => 0,
			'skipped_secret_count' => 0,
			'skipped_operational_count' => 0,
			'skipped_unreadable_count' => 0,
			'security_note' => 'Secrets, credential-like files, operational cache/temp/export directories, and symlink targets are excluded; this owner-only artifact is not a credential or authorization system.',
			'restore_note' => 'When available, this snapshot lets the owner-only Playground Blueprint import wp-content plus the SQLite database before landing in the restored admin.',
		);
	}

	/**
	 * Return public snapshot metadata for JSON manifests and summaries.
	 *
	 * @param array<string,mixed> $snapshot Snapshot metadata.
	 * @return array<string,mixed>
	 */
	private function playground_wordpress_files_snapshot_public_metadata( array $snapshot ) {
		$keys   = array(
			'status',
			'path',
			'sha256',
			'mode',
			'owner_only',
			'sqlite_database_captured',
			'file_count',
			'entry_count',
			'total_size_bytes',
			'database_file_count',
			'plugin_file_count',
			'theme_file_count',
			'upload_file_count',
			'skipped_symlink_count',
			'skipped_secret_count',
			'skipped_operational_count',
			'skipped_unreadable_count',
			'security_note',
			'restore_note',
			'reason',
		);
		$output = array();

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $snapshot ) ) {
				$output[ $key ] = $snapshot[ $key ];
			}
		}

		return $output;
	}

	/**
	 * Determine whether snapshot metadata represents a usable full-site restore.
	 *
	 * @param array<string,mixed>|null $snapshot Snapshot metadata.
	 * @return bool Whether the snapshot is usable.
	 */
	private function is_playground_wordpress_files_snapshot_available( array $snapshot = null ) {
		return is_array( $snapshot )
			&& 'available' === ( isset( $snapshot['status'] ) ? (string) $snapshot['status'] : '' )
			&& ! empty( $snapshot['sqlite_database_captured'] )
			&& ! empty( $snapshot['sha256'] );
	}

	/**
	 * Return the readable SQLite database path when it can be captured safely.
	 *
	 * @return string|null
	 */
	private function playground_sqlite_database_path() {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return null;
		}

		$db_path = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'database/.ht.sqlite' );

		if (
			! file_exists( $db_path )
			|| is_link( $db_path )
			|| SSGWP_Path_Utils::path_has_symlink_segment( $db_path )
			|| ! is_readable( $db_path )
			|| ! is_file( $db_path )
		) {
			return null;
		}

		return $db_path;
	}

	/**
	 * Collect active plugin files into the full-site snapshot.
	 *
	 * @param array<string,string> $entries  ZIP entries keyed by entry path.
	 * @param array<string,mixed>  $metadata Snapshot metadata.
	 */
	private function playground_snapshot_collect_active_plugins( array &$entries, array &$metadata ) {
		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : trailingslashit( WP_CONTENT_DIR ) . 'plugins';
		$plugins    = array();

		if ( function_exists( 'get_option' ) ) {
			foreach ( (array) get_option( 'active_plugins', array() ) as $plugin_file ) {
				$plugin_file = trim( (string) $plugin_file );

				if ( '' !== $plugin_file ) {
					$plugins[ $plugin_file ] = $plugin_file;
				}
			}
		}

		if ( function_exists( 'get_site_option' ) ) {
			foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) as $plugin_file ) {
				$plugin_file = trim( (string) $plugin_file );

				if ( '' !== $plugin_file ) {
					$plugins[ $plugin_file ] = $plugin_file;
				}
			}
		}

		ksort( $plugins, SORT_STRING );

		foreach ( $plugins as $plugin_file ) {
			if ( SSGWP_Path_Utils::has_parent_segment( $plugin_file ) ) {
				continue;
			}

			$plugin_path = wp_normalize_path( trailingslashit( $plugin_dir ) . $plugin_file );
			$source      = is_dir( dirname( $plugin_path ) ) && '.' !== dirname( $plugin_file ) ? dirname( $plugin_path ) : $plugin_path;
			$relative    = $this->playground_snapshot_wp_content_relative_path( $source );

			if ( null === $relative || 0 !== strpos( trailingslashit( $relative ), 'plugins/' ) ) {
				continue;
			}

			$this->playground_snapshot_collect_path( $source, 'wp-content/' . $relative, $entries, $metadata, 'plugin' );
		}
	}

	/**
	 * Collect active theme files into the full-site snapshot.
	 *
	 * @param array<string,string> $entries  ZIP entries keyed by entry path.
	 * @param array<string,mixed>  $metadata Snapshot metadata.
	 */
	private function playground_snapshot_collect_active_themes( array &$entries, array &$metadata ) {
		$theme_dirs = array();

		if ( function_exists( 'get_template_directory' ) ) {
			$theme_dirs[] = get_template_directory();
		}

		if ( function_exists( 'get_stylesheet_directory' ) ) {
			$theme_dirs[] = get_stylesheet_directory();
		}

		if ( function_exists( 'get_template' ) ) {
			$template = trim( (string) get_template() );

			if ( '' !== $template ) {
				$theme_dirs[] = trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $template;
			}
		}

		if ( function_exists( 'get_stylesheet' ) ) {
			$stylesheet = trim( (string) get_stylesheet() );

			if ( '' !== $stylesheet ) {
				$theme_dirs[] = trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $stylesheet;
			}
		}

		$theme_dirs = array_unique(
			array_filter(
				array_map(
					static function ( $theme_dir ) {
						return wp_normalize_path( (string) $theme_dir );
					},
					$theme_dirs
				)
			)
		);
		sort( $theme_dirs, SORT_STRING );

		foreach ( $theme_dirs as $theme_dir ) {
			$relative = $this->playground_snapshot_wp_content_relative_path( $theme_dir );

			if ( null === $relative || 0 !== strpos( trailingslashit( $relative ), 'themes/' ) ) {
				continue;
			}

			$this->playground_snapshot_collect_path( $theme_dir, 'wp-content/' . $relative, $entries, $metadata, 'theme' );
		}
	}

	/**
	 * Collect upload files into the full-site snapshot.
	 *
	 * @param array<string,string> $entries  ZIP entries keyed by entry path.
	 * @param array<string,mixed>  $metadata Snapshot metadata.
	 */
	private function playground_snapshot_collect_uploads( array &$entries, array &$metadata ) {
		$uploads_dir = trailingslashit( WP_CONTENT_DIR ) . 'uploads';

		if ( function_exists( 'wp_get_upload_dir' ) ) {
			$uploads = wp_get_upload_dir();

			if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
				$uploads_dir = (string) $uploads['basedir'];
			}
		}

		$relative = $this->playground_snapshot_wp_content_relative_path( $uploads_dir );

		if ( null === $relative || 0 !== strpos( trailingslashit( $relative ), 'uploads/' ) ) {
			return;
		}

		$this->playground_snapshot_collect_path( $uploads_dir, 'wp-content/' . $relative, $entries, $metadata, 'upload' );
	}

	/**
	 * Collect a file or directory into the full-site snapshot.
	 *
	 * @param string               $source    Source path.
	 * @param string               $entry     ZIP entry path.
	 * @param array<string,string> $entries   ZIP entries keyed by entry path.
	 * @param array<string,mixed>  $metadata  Snapshot metadata.
	 * @param string               $category  Snapshot category.
	 */
	private function playground_snapshot_collect_path( $source, $entry, array &$entries, array &$metadata, $category ) {
		$source = wp_normalize_path( $source );
		$entry  = $this->playground_snapshot_normalize_entry_name( $entry );

		if ( null === $entry || ! file_exists( $source ) || is_link( $source ) || SSGWP_Path_Utils::path_has_symlink_segment( $source ) ) {
			if ( is_link( $source ) || SSGWP_Path_Utils::path_has_symlink_segment( $source ) ) {
				++$metadata['skipped_symlink_count'];
			}
			return;
		}

		if ( is_file( $source ) ) {
			$this->playground_snapshot_add_file( $source, $entry, $entries, $metadata, $category );
			return;
		}

		if ( ! is_dir( $source ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
				function ( $item ) use ( $source, $entry, &$metadata ) {
					$item_path = wp_normalize_path( $item->getPathname() );
					$relative  = ltrim( str_replace( trailingslashit( $source ), '', $item_path ), '/' );
					$zip_entry = $this->playground_snapshot_normalize_entry_name( trailingslashit( $entry ) . $relative );

					if ( $item->isLink() ) {
						++$metadata['skipped_symlink_count'];
						return false;
					}

					if ( null === $zip_entry ) {
						return false;
					}

					if ( $item->isDir() && $this->is_playground_snapshot_operational_directory( $item->getFilename() ) ) {
						++$metadata['skipped_operational_count'];
						return false;
					}

					if ( $item->isFile() && $this->is_playground_snapshot_secret_file( $zip_entry, $item->getFilename() ) ) {
						++$metadata['skipped_secret_count'];
						return false;
					}

					return true;
				}
			),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}

			$item_path = wp_normalize_path( $item->getPathname() );
			$relative  = ltrim( str_replace( trailingslashit( $source ), '', $item_path ), '/' );
			$zip_entry = $this->playground_snapshot_normalize_entry_name( trailingslashit( $entry ) . $relative );

			if ( null === $zip_entry ) {
				continue;
			}

			$this->playground_snapshot_add_file( $item_path, $zip_entry, $entries, $metadata, $category );
		}
	}

	/**
	 * Add one readable file to the full-site snapshot entry map.
	 *
	 * @param string               $source   Source path.
	 * @param string               $entry    ZIP entry path.
	 * @param array<string,string> $entries  ZIP entries keyed by entry path.
	 * @param array<string,mixed>  $metadata Snapshot metadata.
	 * @param string               $category Snapshot category.
	 */
	private function playground_snapshot_add_file( $source, $entry, array &$entries, array &$metadata, $category ) {
		$source = wp_normalize_path( $source );
		$entry  = $this->playground_snapshot_normalize_entry_name( $entry );

		if ( null === $entry ) {
			return;
		}

		if ( is_link( $source ) || SSGWP_Path_Utils::path_has_symlink_segment( $source ) ) {
			++$metadata['skipped_symlink_count'];
			return;
		}

		if ( $this->is_playground_snapshot_secret_file( $entry, basename( $source ) ) ) {
			++$metadata['skipped_secret_count'];
			return;
		}

		if ( ! is_readable( $source ) || ! is_file( $source ) ) {
			++$metadata['skipped_unreadable_count'];
			return;
		}

		$entries[ $entry ] = $source;
		$size              = filesize( $source );
		$metadata['total_size_bytes'] += false === $size ? 0 : (int) $size;

		if ( 'database' === $category ) {
			++$metadata['database_file_count'];
		} elseif ( 'plugin' === $category ) {
			++$metadata['plugin_file_count'];
		} elseif ( 'theme' === $category ) {
			++$metadata['theme_file_count'];
		} elseif ( 'upload' === $category ) {
			++$metadata['upload_file_count'];
		}
	}

	/**
	 * Return a path relative to WP_CONTENT_DIR when it is safely under wp-content.
	 *
	 * @param string $path Filesystem path.
	 * @return string|null Relative path.
	 */
	private function playground_snapshot_wp_content_relative_path( $path ) {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return null;
		}

		$content_dir = wp_normalize_path( WP_CONTENT_DIR );
		$path        = wp_normalize_path( $path );

		if ( ! SSGWP_Path_Utils::is_path_inside_directory( $path, $content_dir ) ) {
			return null;
		}

		$relative = ltrim( substr( $path, strlen( untrailingslashit( $content_dir ) ) ), '/' );

		if ( '' === $relative || SSGWP_Path_Utils::has_parent_segment( $relative ) ) {
			return null;
		}

		return $relative;
	}

	/**
	 * Normalize and validate a ZIP entry name for the WordPress files snapshot.
	 *
	 * @param string $entry Candidate entry.
	 * @return string|null Safe entry, or null.
	 */
	private function playground_snapshot_normalize_entry_name( $entry ) {
		$entry = trim( wp_normalize_path( (string) $entry ), '/' );

		if ( '' === $entry || SSGWP_Path_Utils::has_parent_segment( $entry ) || 0 !== strpos( trailingslashit( $entry ), 'wp-content/' ) ) {
			return null;
		}

		return $entry;
	}

	/**
	 * Return whether a directory is operational export/cache/temp material.
	 *
	 * @param string $name Directory basename.
	 * @return bool Whether the directory should be skipped.
	 */
	private function is_playground_snapshot_operational_directory( $name ) {
		$name = strtolower( (string) $name );

		return in_array(
			$name,
			array(
				'.cache',
				'.git',
				'backup',
				'backups',
				'cache',
				'caches',
				'export',
				'exports',
				'static-export',
				'static-site-export',
				'temp',
				'tmp',
				'upgrade',
				'upgrades',
			),
			true
		);
	}

	/**
	 * Return whether a file is an obvious credential or secret.
	 *
	 * @param string $entry    ZIP entry path.
	 * @param string $basename File basename.
	 * @return bool Whether the file should be skipped.
	 */
	private function is_playground_snapshot_secret_file( $entry, $basename ) {
		if ( 'wp-content/database/.ht.sqlite' === $entry ) {
			return false;
		}

		$name      = strtolower( (string) $basename );
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( 0 === strpos( $name, '.env' ) || in_array( $extension, array( 'key', 'pem', 'p12', 'pfx' ), true ) ) {
			return true;
		}

		if (
			in_array(
				$name,
				array(
					'.htaccess',
					'.htpasswd',
					'auth.json',
					'credentials',
					'credentials.json',
					'id_dsa',
					'id_ecdsa',
					'id_ed25519',
					'id_rsa',
					'service-account.json',
					'wp-config.php',
				),
				true
			)
		) {
			return true;
		}

		return false !== strpos( $name, 'credential' )
			|| false !== strpos( $name, 'secret' )
			|| false !== strpos( $name, 'token' );
	}

	/**
	 * Return restore limitations for generated source-state metadata.
	 *
	 * @param bool $has_full_site_snapshot Whether a SQLite snapshot is available.
	 * @return string[]
	 */
	private function playground_source_state_limitations( $has_full_site_snapshot ) {
		if ( $has_full_site_snapshot ) {
			return array(
				'The owner-only SQLite full-site Blueprint bundle restores wp-content plus the SQLite database, including plugins, themes, uploads, content, and database-stored settings.',
				'The SQLite full-site bundle is still owner-only source material and is not a credential, authentication, or redeploy authorization system.',
				'Secrets, symlink targets, cache/temp/export directories, and runtime credentials are intentionally excluded.',
				'Non-SQLite/MySQL source sites still fall back to WXR/content-only until a database dump and restore path is implemented.',
				'The generated /wp-admin/ web handoff still imports WXR content when the effective WXR URL is reachable by WordPress Playground.',
			);
		}

		return array(
			'WXR restores content but not the full WordPress database or plugin settings yet.',
			'Without a readable SQLite database, this source-state export does not bundle a full-site restore snapshot.',
			'The owner-only Blueprint bundle imports bundled WXR content only; it is not a full database, plugin settings, users, secrets, or runtime restore.',
			'Non-SQLite/MySQL source sites still fall back to WXR/content-only until a database dump and restore path is implemented.',
			'The generated /wp-admin/ handoff imports this WXR only when the effective WXR URL is reachable by WordPress Playground.',
		);
	}

	/**
	 * Build owner/access policy metadata shared by source-state manifests.
	 *
	 * @param array $args Export args.
	 * @return array<string,mixed>
	 */
	private function playground_source_policy_metadata( array $args ) {
		$wxr_url = isset( $args['playground_source_wxr_url'] ) ? $this->sanitize_playground_source_url( $args['playground_source_wxr_url'] ) : '';
		$expiry  = $this->sanitize_playground_source_expires_at( isset( $args['playground_source_expires_at'] ) ? $args['playground_source_expires_at'] : '' );
		$mode    = '' === $wxr_url ? 'runtime-relative-export-path' : 'provided-url';

		$metadata = array(
			'wxr_url_mode' => $mode,
			'source_access_expires_at' => $expiry['value'],
			'source_access_expires_at_status' => $expiry['status'],
			'owner_access_policy' => array(
				'artifact_directory' => '_playground-source',
				'owner_only' => true,
				'may_expose_editable_source_content' => true,
				'serve_only_through' => 'intentional-public-signed-or-private-url',
				'generated_artifacts_authorize_redeploy' => false,
				'deploy_credentials_stored' => false,
				'owner_identity_stored' => false,
				'authorization_tokens_stored' => false,
				'access_note' => '_playground-source/ is owner-only restore material and should be served only through an intentional public, signed, or private URL depending on the workflow.',
				'secrets_note' => 'No deploy credentials, owner identity, or authorization tokens are generated or stored separately in this export.',
				'redeploy_authorization_note' => 'Redeploy is a local/generated workflow that must be run by an authorized owner/operator with credentials; generated artifacts do not authorize a redeploy by themselves.',
			),
		);

		if ( '' !== $wxr_url ) {
			$metadata['effective_wxr_url'] = $wxr_url;
			$metadata['owner_access_policy']['provided_url_sensitivity_note'] = 'The provided WXR URL is stored as the effective handoff URL; treat it as sensitive if it embeds signed access material.';
		}

		return $metadata;
	}

	/**
	 * Sanitize a caller-provided URL without checking remote reachability.
	 *
	 * @param mixed $url Candidate URL.
	 * @return string Safe absolute HTTP(S) URL, or empty string.
	 */
	private function sanitize_playground_source_url( $url ) {
		$url = $this->sanitize_plain_export_value( $url );

		if ( '' === $url || $this->contains_control_character( $url ) ) {
			return '';
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Sanitize a scalar export metadata value.
	 *
	 * @param mixed $value Candidate value.
	 * @return string Sanitized value.
	 */
	private function sanitize_plain_export_value( $value ) {
		return trim( strip_tags( (string) $value ) );
	}

	/**
	 * Return whether a string contains ASCII control characters.
	 *
	 * @param string $value Candidate value.
	 * @return bool Whether a control character was found.
	 */
	private function contains_control_character( $value ) {
		$length = strlen( $value );

		for ( $i = 0; $i < $length; ++$i ) {
			$ord = ord( $value[ $i ] );

			if ( $ord < 32 || 127 === $ord ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate optional source access expiry metadata.
	 *
	 * @param mixed $timestamp Candidate ISO-ish timestamp.
	 * @return array{value:string|null,status:string}
	 */
	private function sanitize_playground_source_expires_at( $timestamp ) {
		$timestamp = $this->sanitize_plain_export_value( $timestamp );

		if ( '' === $timestamp ) {
			return array(
				'value' => null,
				'status' => 'not-provided',
			);
		}

		if ( ! $this->is_valid_playground_source_timestamp( $timestamp ) ) {
			return array(
				'value' => null,
				'status' => 'invalid-ignored',
			);
		}

		return array(
			'value' => $timestamp,
			'status' => 'valid',
		);
	}

	/**
	 * Conservatively validate YYYY-MM-DDTHH:MM:SSZ or timezone-offset timestamps.
	 *
	 * @param string $timestamp Candidate timestamp.
	 * @return bool Whether the timestamp is valid.
	 */
	private function is_valid_playground_source_timestamp( $timestamp ) {
		$length = strlen( $timestamp );

		if ( 20 !== $length && 25 !== $length ) {
			return false;
		}

		if (
			'-' !== $timestamp[4]
			|| '-' !== $timestamp[7]
			|| 'T' !== $timestamp[10]
			|| ':' !== $timestamp[13]
			|| ':' !== $timestamp[16]
		) {
			return false;
		}

		$digit_parts = substr( $timestamp, 0, 4 )
			. substr( $timestamp, 5, 2 )
			. substr( $timestamp, 8, 2 )
			. substr( $timestamp, 11, 2 )
			. substr( $timestamp, 14, 2 )
			. substr( $timestamp, 17, 2 );

		if ( ! ctype_digit( $digit_parts ) ) {
			return false;
		}

		$year   = (int) substr( $timestamp, 0, 4 );
		$month  = (int) substr( $timestamp, 5, 2 );
		$day    = (int) substr( $timestamp, 8, 2 );
		$hour   = (int) substr( $timestamp, 11, 2 );
		$minute = (int) substr( $timestamp, 14, 2 );
		$second = (int) substr( $timestamp, 17, 2 );

		if ( ! checkdate( $month, $day, $year ) || $hour > 23 || $minute > 59 || $second > 59 ) {
			return false;
		}

		if ( 20 === $length ) {
			return 'Z' === $timestamp[19];
		}

		if ( '+' !== $timestamp[19] && '-' !== $timestamp[19] ) {
			return false;
		}

		if ( ':' !== $timestamp[22] ) {
			return false;
		}

		$offset_digits = substr( $timestamp, 20, 2 ) . substr( $timestamp, 23, 2 );

		if ( ! ctype_digit( $offset_digits ) ) {
			return false;
		}

		$offset_hour   = (int) substr( $timestamp, 20, 2 );
		$offset_minute = (int) substr( $timestamp, 23, 2 );

		return $offset_hour <= 14 && $offset_minute <= 59 && ( 14 !== $offset_hour || 0 === $offset_minute );
	}

	/**
	 * Return active theme metadata when WordPress exposes it.
	 *
	 * @return array<string,mixed>
	 */
	private function get_active_theme_metadata() {
		$theme = array(
			'stylesheet' => function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : null,
			'template' => function_exists( 'get_template' ) ? (string) get_template() : null,
		);

		if ( function_exists( 'wp_get_theme' ) ) {
			$wp_theme = wp_get_theme();

			if ( is_object( $wp_theme ) && method_exists( $wp_theme, 'get' ) ) {
				$theme['name']    = (string) $wp_theme->get( 'Name' );
				$theme['version'] = (string) $wp_theme->get( 'Version' );
			}
		}

		return $theme;
	}

	/**
	 * Return active plugin metadata when WordPress exposes it.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_active_plugin_metadata() {
		$plugins = array();
		$seen    = array();
		$active  = function_exists( 'get_option' ) ? get_option( 'active_plugins', array() ) : array();

		if ( is_array( $active ) ) {
			foreach ( $active as $plugin_file ) {
				$this->add_active_plugin_metadata( $plugins, $seen, $plugin_file, false );
			}
		}

		if ( function_exists( 'get_site_option' ) ) {
			$network_active = get_site_option( 'active_sitewide_plugins', array() );

			if ( is_array( $network_active ) ) {
				foreach ( array_keys( $network_active ) as $plugin_file ) {
					$this->add_active_plugin_metadata( $plugins, $seen, $plugin_file, true );
				}
			}
		}

		return $plugins;
	}

	/**
	 * Add one active plugin to a metadata list.
	 *
	 * @param array<int,array<string,mixed>> $plugins        Plugin metadata list.
	 * @param array<string,int>              $seen           Existing plugin indexes by file.
	 * @param mixed                          $plugin_file    Plugin file value from WordPress options.
	 * @param bool                           $network_active Whether the plugin is network-active.
	 */
	private function add_active_plugin_metadata( array &$plugins, array &$seen, $plugin_file, $network_active ) {
		$plugin_file = trim( (string) $plugin_file );

		if ( '' === $plugin_file ) {
			return;
		}

		if ( isset( $seen[ $plugin_file ] ) ) {
			$plugins[ $seen[ $plugin_file ] ]['network_active'] = $plugins[ $seen[ $plugin_file ] ]['network_active'] || (bool) $network_active;
			return;
		}

		$metadata = array(
			'plugin' => $plugin_file,
			'network_active' => (bool) $network_active,
		);

		if ( defined( 'WP_PLUGIN_DIR' ) && function_exists( 'get_plugin_data' ) ) {
			$plugin_path = trailingslashit( WP_PLUGIN_DIR ) . $plugin_file;

			if ( is_readable( $plugin_path ) ) {
				$plugin_data = get_plugin_data( $plugin_path, false, false );

				if ( is_array( $plugin_data ) ) {
					$metadata['name']    = isset( $plugin_data['Name'] ) ? (string) $plugin_data['Name'] : '';
					$metadata['version'] = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '';
				}
			}
		}

		$seen[ $plugin_file ] = count( $plugins );
		$plugins[]           = $metadata;
	}

	/**
	 * Generate WXR with WordPress core when possible, otherwise use a testable fallback.
	 *
	 * @return array{content:string,method:string}
	 */
	private function generate_site_content_wxr() {
		$core_wxr = $this->generate_core_export_wxr();

		if ( null !== $core_wxr ) {
			return array(
				'content' => $core_wxr,
				'method' => 'wordpress-core-export',
			);
		}

		return array(
			'content' => $this->generate_fallback_wxr(),
			'method' => 'fallback-published-posts-pages',
		);
	}

	/**
	 * Generate WXR through WordPress core export_wp() when it is available.
	 *
	 * @return string|null WXR XML, or null when the core API cannot be used.
	 */
	private function generate_core_export_wxr() {
		if ( $this->is_http_response_context() ) {
			return null;
		}

		if ( ! function_exists( 'export_wp' ) && defined( 'ABSPATH' ) ) {
			$export_file = trailingslashit( ABSPATH ) . 'wp-admin/includes/export.php';

			if ( is_readable( $export_file ) ) {
				require_once $export_file;
			}
		}

		if ( ! function_exists( 'export_wp' ) ) {
			return null;
		}

		$buffer_level = ob_get_level();
		ob_start();

		try {
			export_wp( array( 'content' => 'all' ) );
		} catch ( Throwable $throwable ) {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}

			return null;
		}

		$wxr = ob_get_clean();

		if ( is_string( $wxr ) && false !== strpos( $wxr, '<rss' ) && false !== strpos( $wxr, '</rss>' ) ) {
			return $wxr;
		}

		return null;
	}

	/**
	 * Determine whether the exporter is running inside an HTTP response.
	 *
	 * WordPress core export_wp() sends WXR download headers. In HTTP requests,
	 * source-state generation must remain a file-generation step and leave the
	 * caller's response headers untouched, so those requests use fallback WXR.
	 *
	 * @return bool Whether this appears to be a web request.
	 */
	private function is_http_response_context() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		return isset( $_SERVER['REQUEST_METHOD'] ) && '' !== trim( (string) $_SERVER['REQUEST_METHOD'] );
	}

	/**
	 * Generate deterministic WXR from published posts and pages.
	 *
	 * @return string WXR XML.
	 * @throws Exception When XMLWriter is unavailable.
	 */
	private function generate_fallback_wxr() {
		if ( ! class_exists( 'XMLWriter' ) ) {
			throw new Exception( 'The PHP XMLWriter extension is required to generate fallback WXR source-state artifacts.' );
		}

		$xml = new XMLWriter();
		$xml->openMemory();
		$xml->setIndent( true );
		$xml->setIndentString( "\t" );
		$xml->startDocument( '1.0', 'UTF-8' );
		$xml->startElement( 'rss' );
		$xml->writeAttribute( 'version', '2.0' );
		$xml->writeAttribute( 'xmlns:excerpt', 'http://wordpress.org/export/1.2/excerpt/' );
		$xml->writeAttribute( 'xmlns:content', 'http://purl.org/rss/1.0/modules/content/' );
		$xml->writeAttribute( 'xmlns:wfw', 'http://wellformedweb.org/CommentAPI/' );
		$xml->writeAttribute( 'xmlns:dc', 'http://purl.org/dc/elements/1.1/' );
		$xml->writeAttribute( 'xmlns:wp', 'http://wordpress.org/export/1.2/' );
		$xml->startElement( 'channel' );
		$xml->writeElement( 'title', $this->wxr_site_title() );
		$xml->writeElement( 'link', home_url( '/' ) );
		$xml->writeElement( 'description', function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'description' ) : '' );
		$xml->writeElement( 'pubDate', 'Thu, 01 Jan 1970 00:00:00 +0000' );
		$xml->writeElement( 'language', function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'language' ) : '' );
		$xml->writeElement( 'wp:wxr_version', '1.2' );
		$xml->writeElement( 'wp:base_site_url', site_url( '/' ) );
		$xml->writeElement( 'wp:base_blog_url', home_url( '/' ) );

		foreach ( $this->get_wxr_export_posts() as $post ) {
			$this->write_wxr_post_item( $xml, $post );
		}

		$xml->endElement();
		$xml->endElement();
		$xml->endDocument();

		return $xml->outputMemory() . "\n";
	}

	/**
	 * Return the WXR channel title.
	 *
	 * @return string Site title.
	 */
	private function wxr_site_title() {
		$title = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';

		return '' === $title ? home_url( '/' ) : $title;
	}

	/**
	 * Return published posts and pages for fallback WXR.
	 *
	 * @return array<int,object>
	 */
	private function get_wxr_export_posts() {
		$posts = array();

		if ( function_exists( 'get_posts' ) ) {
			$queried_posts = get_posts(
				array(
					'post_type' => array( 'post', 'page' ),
					'post_status' => 'publish',
					'posts_per_page' => -1,
					'numberposts' => -1,
					'orderby' => 'ID',
					'order' => 'ASC',
				)
			);

			if ( is_array( $queried_posts ) ) {
				foreach ( $queried_posts as $post ) {
					if ( is_object( $post ) ) {
						$posts[] = $post;
					}
				}
			}
		} elseif ( class_exists( 'WP_Query' ) ) {
			foreach ( array( 'post', 'page' ) as $post_type ) {
				$paged = 1;

				do {
					$query = new WP_Query(
						array(
							'post_type' => $post_type,
							'post_status' => 'publish',
							'posts_per_page' => 100,
							'paged' => $paged,
							'orderby' => 'ID',
							'order' => 'ASC',
							'fields' => 'ids',
							'no_found_rows' => true,
						)
					);

					$query_posts = isset( $query->posts ) && is_array( $query->posts ) ? $query->posts : array();

					foreach ( $query_posts as $post ) {
						if ( is_object( $post ) ) {
							$posts[] = $post;
						} elseif ( function_exists( 'get_post' ) ) {
							$post_object = get_post( $post );

							if ( is_object( $post_object ) ) {
								$posts[] = $post_object;
							}
						}
					}

					++$paged;
				} while ( count( $query_posts ) >= 100 && $paged <= 1000 );
			}
		}

		usort(
			$posts,
			static function ( $a, $b ) {
				$a_id = isset( $a->ID ) ? (int) $a->ID : 0;
				$b_id = isset( $b->ID ) ? (int) $b->ID : 0;

				if ( $a_id === $b_id ) {
					return 0;
				}

				return $a_id < $b_id ? -1 : 1;
			}
		);

		return $posts;
	}

	/**
	 * Write one WXR item for a post or page.
	 *
	 * @param XMLWriter $xml  XML writer.
	 * @param object    $post Post object.
	 */
	private function write_wxr_post_item( XMLWriter $xml, $post ) {
		$post_id       = (int) $this->post_field( $post, 'ID', 0 );
		$post_date     = (string) $this->post_field( $post, 'post_date', '1970-01-01 00:00:00' );
		$post_date_gmt = (string) $this->post_field( $post, 'post_date_gmt', $post_date );
		$modified      = (string) $this->post_field( $post, 'post_modified', $post_date );
		$modified_gmt  = (string) $this->post_field( $post, 'post_modified_gmt', $post_date_gmt );
		$link          = function_exists( 'get_permalink' ) ? (string) get_permalink( $post ) : home_url( '?p=' . $post_id );

		$xml->startElement( 'item' );
		$xml->writeElement( 'title', (string) $this->post_field( $post, 'post_title', '' ) );
		$xml->writeElement( 'link', $link );
		$xml->writeElement( 'pubDate', $this->format_wxr_pub_date( $post_date_gmt ) );
		$xml->writeElement( 'dc:creator', $this->post_author_login( $post ) );
		$xml->writeElement( 'guid', (string) $this->post_field( $post, 'guid', $link ) );
		$xml->writeElement( 'description', '' );
		$xml->writeElement( 'content:encoded', (string) $this->post_field( $post, 'post_content', '' ) );
		$xml->writeElement( 'excerpt:encoded', (string) $this->post_field( $post, 'post_excerpt', '' ) );
		$xml->writeElement( 'wp:post_id', (string) $post_id );
		$xml->writeElement( 'wp:post_date', $post_date );
		$xml->writeElement( 'wp:post_date_gmt', $post_date_gmt );
		$xml->writeElement( 'wp:post_modified', $modified );
		$xml->writeElement( 'wp:post_modified_gmt', $modified_gmt );
		$xml->writeElement( 'wp:comment_status', (string) $this->post_field( $post, 'comment_status', 'closed' ) );
		$xml->writeElement( 'wp:ping_status', (string) $this->post_field( $post, 'ping_status', 'closed' ) );
		$xml->writeElement( 'wp:post_name', $this->post_name( $post ) );
		$xml->writeElement( 'wp:status', (string) $this->post_field( $post, 'post_status', 'publish' ) );
		$xml->writeElement( 'wp:post_parent', (string) (int) $this->post_field( $post, 'post_parent', 0 ) );
		$xml->writeElement( 'wp:menu_order', (string) (int) $this->post_field( $post, 'menu_order', 0 ) );
		$xml->writeElement( 'wp:post_type', (string) $this->post_field( $post, 'post_type', 'post' ) );
		$xml->writeElement( 'wp:post_password', (string) $this->post_field( $post, 'post_password', '' ) );
		$xml->endElement();
	}

	/**
	 * Return a post field with a fallback.
	 *
	 * @param object $post    Post object.
	 * @param string $field   Field name.
	 * @param mixed  $default Default value.
	 * @return mixed Field value.
	 */
	private function post_field( $post, $field, $default ) {
		return is_object( $post ) && isset( $post->{$field} ) ? $post->{$field} : $default;
	}

	/**
	 * Format a WXR pubDate value.
	 *
	 * @param string $mysql_date MySQL-style date.
	 * @return string RFC 2822 date.
	 */
	private function format_wxr_pub_date( $mysql_date ) {
		$timestamp = strtotime( (string) $mysql_date . ' UTC' );

		if ( false === $timestamp ) {
			$timestamp = 0;
		}

		return gmdate( 'D, d M Y H:i:s +0000', $timestamp );
	}

	/**
	 * Return the WXR author login for a post.
	 *
	 * @param object $post Post object.
	 * @return string Author login.
	 */
	private function post_author_login( $post ) {
		$author_id = (int) $this->post_field( $post, 'post_author', 0 );

		if ( $author_id > 0 && function_exists( 'get_the_author_meta' ) ) {
			$login = (string) get_the_author_meta( 'login', $author_id );

			if ( '' !== $login ) {
				return $login;
			}
		}

		return 'admin';
	}

	/**
	 * Return the post slug for WXR.
	 *
	 * @param object $post Post object.
	 * @return string Post slug.
	 */
	private function post_name( $post ) {
		$post_name = (string) $this->post_field( $post, 'post_name', '' );

		if ( '' !== $post_name ) {
			return $post_name;
		}

		$post_id = (int) $this->post_field( $post, 'ID', 0 );

		return 'post-' . $post_id;
	}

	/**
	 * Write the opt-in static /wp-admin/ handoff route for Playground editing.
	 *
	 * Source-state handoffs can import WXR content, but they still are not full
	 * database, plugin-settings, users, secrets, or runtime restore bundles.
	 *
	 * @param string $output_dir Static export directory.
	 * @param array  $args       Export args.
	 * @param array<string,mixed>|null $source_state Source-state artifact summary.
	 * @return array<string,mixed> Public export summary.
	 * @throws Exception When files cannot be written.
	 */
	private function write_playground_admin_handoff( $output_dir, array $args, $source_state = null ) {
		$source_bundle_url = isset( $args['playground_source_bundle_url'] ) ? trim( (string) $args['playground_source_bundle_url'] ) : '';
		$source_manifest    = $this->playground_source_manifest( $source_bundle_url, ! empty( $args['include_manifest'] ), ! empty( $args['include_cloudflare_publish'] ), $source_state );
		$blueprint          = $this->playground_handoff_blueprint( $source_manifest['source_state']['status'], $source_manifest['source_state'], ! empty( $args['include_cloudflare_publish'] ) );
		$wxr_import_enabled = ! empty( $source_manifest['source_state']['wxr_import_enabled'] );

		$this->write_file(
			trailingslashit( $output_dir ) . 'wp-admin/playground-source-manifest.json',
			$this->encode_static_json( $source_manifest )
		);
		$this->write_file(
			trailingslashit( $output_dir ) . 'wp-admin/playground-blueprint.json',
			$this->encode_static_json( $blueprint )
		);
		$this->write_file(
			trailingslashit( $output_dir ) . 'wp-admin/index.html',
			$this->playground_handoff_html( $source_manifest['source_state']['status'], $blueprint, $source_manifest['source_state'] )
		);

		$summary = array(
			'included'                => true,
			'handoff_path'            => 'wp-admin/index.html',
			'blueprint_path'          => 'wp-admin/playground-blueprint.json',
			'source_manifest_path'    => 'wp-admin/playground-source-manifest.json',
			'playground_url_template' => $wxr_import_enabled ? 'https://playground.wordpress.net/#{urlencoded_inline_blueprint_json}' : 'https://playground.wordpress.net/?blueprint-url={urlencoded_absolute_blueprint_url}',
			'source_state_status'     => $source_manifest['source_state']['status'],
			'wxr_import_enabled'      => $wxr_import_enabled,
			'source_bundle_url'       => $source_bundle_url,
		);

		if ( isset( $source_manifest['source_state']['wordpress_files_snapshot'] ) ) {
			$snapshot = $source_manifest['source_state']['wordpress_files_snapshot'];
			$summary['wordpress_files_snapshot_status'] = isset( $snapshot['status'] ) ? $snapshot['status'] : null;
			$summary['wordpress_files_snapshot_path'] = isset( $snapshot['path'] ) ? $snapshot['path'] : null;
			$summary['wordpress_files_snapshot_sha256'] = isset( $snapshot['sha256'] ) ? $snapshot['sha256'] : null;
			$summary['wordpress_files_snapshot_mode'] = isset( $snapshot['mode'] ) ? $snapshot['mode'] : null;
			$summary['wordpress_files_snapshot_sqlite_database_captured'] = ! empty( $snapshot['sqlite_database_captured'] );
		}

		if ( $wxr_import_enabled ) {
			$summary['wxr_url_mode'] = isset( $source_manifest['source_state']['wxr_url_mode'] ) ? $source_manifest['source_state']['wxr_url_mode'] : 'runtime-relative-export-path';
			$summary['source_access_expires_at'] = isset( $source_manifest['source_state']['source_access_expires_at'] ) ? $source_manifest['source_state']['source_access_expires_at'] : null;
			$summary['source_access_expires_at_status'] = isset( $source_manifest['source_state']['source_access_expires_at_status'] ) ? $source_manifest['source_state']['source_access_expires_at_status'] : 'not-provided';

			if ( isset( $source_manifest['source_state']['blueprint_bundle'] ) && is_array( $source_manifest['source_state']['blueprint_bundle'] ) ) {
				$summary['blueprint_bundle_path'] = $source_manifest['source_state']['blueprint_bundle']['path'];
				$summary['blueprint_bundle_sha256'] = $source_manifest['source_state']['blueprint_bundle']['sha256'];
				$summary['blueprint_bundle_mode'] = $source_manifest['source_state']['blueprint_bundle']['mode'];
				$summary['blueprint_bundle_status'] = $source_manifest['source_state']['blueprint_bundle']['status'];
			}

			if ( isset( $source_manifest['source_state']['effective_wxr_url'] ) ) {
				$summary['effective_wxr_url'] = $source_manifest['source_state']['effective_wxr_url'];
			}
		}

		return $summary;
	}

	/**
	 * Build the deterministic source-state manifest for the Playground handoff.
	 *
	 * @param string $source_bundle_url Optional durable bundle URL supplied by caller.
	 * @param bool   $static_manifest_included Whether static-export.json will be written.
	 * @param bool   $cloudflare_publish_included Whether Cloudflare artifacts are enabled.
	 * @param array<string,mixed>|null $source_state Source-state artifact summary.
	 * @return array<string,mixed>
	 */
	private function playground_source_manifest( $source_bundle_url, $static_manifest_included, $cloudflare_publish_included, $source_state = null ) {
		if ( is_array( $source_state ) ) {
			$wxr_url_mode               = isset( $source_state['wxr_url_mode'] ) ? (string) $source_state['wxr_url_mode'] : 'runtime-relative-export-path';
			$source_access_expires_at  = isset( $source_state['source_access_expires_at'] ) ? $source_state['source_access_expires_at'] : null;
			$expires_at_status         = isset( $source_state['source_access_expires_at_status'] ) ? (string) $source_state['source_access_expires_at_status'] : 'not-provided';
			$wordpress_files_snapshot  = isset( $source_state['wordpress_files_snapshot'] ) && is_array( $source_state['wordpress_files_snapshot'] )
				? $source_state['wordpress_files_snapshot']
				: $this->playground_wordpress_files_snapshot_empty_metadata();
			$has_full_site_snapshot    = $this->is_playground_wordpress_files_snapshot_available( $wordpress_files_snapshot );
			$is_full_site_bundle       = $has_full_site_snapshot && 'sqlite-full-site-playground-blueprint-bundle' === ( isset( $source_state['blueprint_bundle_mode'] ) ? (string) $source_state['blueprint_bundle_mode'] : '' );
			$owner_access_policy       = isset( $source_state['owner_access_policy'] ) && is_array( $source_state['owner_access_policy'] )
				? $source_state['owner_access_policy']
				: $this->playground_source_policy_metadata( array() )['owner_access_policy'];
			$source_state_manifest = array(
				'status' => 'source-state-generated',
				'bundle_url' => '' === $source_bundle_url ? null : $source_bundle_url,
				'source_state_path' => '../_playground-source/source-state.json',
				'wxr_path' => '../_playground-source/site-content.wxr',
				'wxr_sha256' => isset( $source_state['wxr_sha256'] ) ? $source_state['wxr_sha256'] : null,
				'wxr_import_enabled' => true,
				'wxr_url_mode' => $wxr_url_mode,
				'wxr_url_requirement' => 'The WXR URL must be reachable by WordPress Playground through a public, signed, or private URL. Do not blindly expose _playground-source/ on generic hosting.',
				'source_access_expires_at' => $source_access_expires_at,
				'source_access_expires_at_status' => $expires_at_status,
				'wordpress_files_snapshot' => $this->playground_wordpress_files_snapshot_public_metadata( $wordpress_files_snapshot ),
				'full_site_snapshot_available' => $has_full_site_snapshot,
				'sqlite_database_captured' => ! empty( $wordpress_files_snapshot['sqlite_database_captured'] ),
				'content_restore' => array(
					'type' => 'wxr',
					'enabled' => true,
					'blueprint_step' => 'importWxr',
					'file_resource' => 'url',
				),
				'blueprint_bundle' => array(
					'path' => isset( $source_state['blueprint_bundle_path'] ) ? $source_state['blueprint_bundle_path'] : null,
					'sha256' => isset( $source_state['blueprint_bundle_sha256'] ) ? $source_state['blueprint_bundle_sha256'] : null,
					'mode' => isset( $source_state['blueprint_bundle_mode'] ) ? $source_state['blueprint_bundle_mode'] : 'content-only-playground-blueprint-bundle',
					'status' => isset( $source_state['blueprint_bundle_status'] ) ? $source_state['blueprint_bundle_status'] : 'content-only-blueprint-bundle-generated',
					'content_only' => ! $is_full_site_bundle,
					'full_site_restore' => $is_full_site_bundle,
					'not_full_restore_bundle' => ! $is_full_site_bundle,
					'owner_only' => true,
					'playground_url_usage' => isset( $source_state['blueprint_bundle_url_usage'] ) ? $source_state['blueprint_bundle_url_usage'] : 'Owner/operator may intentionally host this ZIP and use its URL as a WordPress Playground ?blueprint-url= bundle URL.',
				),
				'owner_access_policy' => $owner_access_policy,
				'not_full_restore_bundle' => true,
				'note' => $is_full_site_bundle
					? 'The /wp-admin/ web handoff still imports WXR content when reachable. The separate owner-only Blueprint bundle can restore wp-content plus the SQLite database with importWordPressFiles.'
					: 'The /wp-admin/ handoff can import WXR content when the WXR URL is reachable by WordPress Playground, but this is not a full Playground restore bundle.',
				'limitations' => $is_full_site_bundle
					? array(
						'The /wp-admin/ web handoff remains WXR/content-only.',
						'The owner-only Blueprint bundle restores wp-content plus the SQLite database for SQLite-backed sites.',
						'The SQLite full-site bundle is not a credential, authentication, or redeploy authorization system.',
						'Non-SQLite/MySQL source sites still fall back to WXR/content-only until a database dump and restore path exists.',
					)
					: array(
						'WXR imports content only.',
						'The handoff does not restore the full database, plugin settings, users, secrets, or runtime configuration.',
						'The owner-only Blueprint bundle imports bundled WXR content only and can be used with Playground ?blueprint-url= only when intentionally exposed.',
						'Non-SQLite/MySQL source sites still fall back to WXR/content-only until a database dump and restore path exists.',
						'_playground-source/ must be served through an intentional public, signed, or private URL for Playground to fetch the WXR.',
					),
			);

			if ( isset( $source_state['effective_wxr_url'] ) ) {
				$source_state_manifest['effective_wxr_url'] = $source_state['effective_wxr_url'];
			} else {
				$source_state_manifest['wxr_url_runtime_expression'] = 'new URL("../_playground-source/site-content.wxr", window.location.href).href';
			}
		} else {
			$source_state_manifest = array(
				'status' => '' === $source_bundle_url ? 'manifest-pointer-only' : 'bundle-url-provided',
				'bundle_url' => '' === $source_bundle_url ? null : $source_bundle_url,
				'todo' => 'Persist a durable WordPress Playground site export bundle and replace this manifest pointer before treating the handoff as a complete source-site restore.',
			);
		}

		return array(
			'schema' => 'https://stillpress.local/playground-admin-handoff/v1',
			'version' => 1,
			'home_url' => home_url( '/' ),
			'site_url' => site_url( '/' ),
			'handoff' => array(
				'route' => '/wp-admin/',
				'html' => 'wp-admin/index.html',
				'blueprint' => 'wp-admin/playground-blueprint.json',
				'blueprint_url_mode' => is_array( $source_state ) ? 'inline-fragment' : 'blueprint-url',
			),
			'static_export' => array(
				'manifest_path' => $static_manifest_included ? '../static-export.json' : null,
				'manifest_included' => (bool) $static_manifest_included,
			),
			'source_state' => $source_state_manifest,
			'publish' => array(
				'cloudflare_manifest_path' => $cloudflare_publish_included ? '../_cloudflare-publish/cloudflare-publish.json' : null,
				'cloudflare_publish_included' => (bool) $cloudflare_publish_included,
			),
		);
	}

	/**
	 * Build the Playground Blueprint used by the static admin handoff.
	 *
	 * @param string $source_state_status Source-state status for the generated handoff.
	 * @param array<string,mixed> $source_state_manifest Source-state manifest section.
	 * @return array<string,mixed>
	 */
	private function playground_handoff_blueprint( $source_state_status = 'manifest-pointer-only', array $source_state_manifest = array(), $cloudflare_publish_included = false ) {
		$has_source_state = 'source-state-generated' === $source_state_status;
		$content_restore  = isset( $source_state_manifest['content_restore'] ) && is_array( $source_state_manifest['content_restore'] )
			? $source_state_manifest['content_restore']
			: array();
		$restore_step     = isset( $content_restore['blueprint_step'] ) ? (string) $content_restore['blueprint_step'] : 'importWxr';
		$wxr_import_enabled = $has_source_state
			&& 'importWxr' === $restore_step
			&& ( ! array_key_exists( 'wxr_import_enabled', $source_state_manifest ) || ! empty( $source_state_manifest['wxr_import_enabled'] ) );
		$wordpress_files_import_enabled = $has_source_state
			&& 'importWordPressFiles' === $restore_step;
		$steps              = array(
			array(
				'step' => 'installPlugin',
				'pluginData' => array(
					'resource' => 'git:directory',
					'url' => 'https://github.com/adamziel/wp-extensions',
					'ref' => 'main',
					'refType' => 'branch',
					'path' => 'static-site-generator',
				),
				'options' => array(
					'activate' => true,
					'targetFolderName' => 'static-site-generator',
				),
			),
		);

		if ( $has_source_state ) {
			if ( $wordpress_files_import_enabled ) {
				$steps[] = array(
					'step' => 'importWordPressFiles',
					'wordPressFilesZip' => array(
						'resource' => 'bundled',
						'path' => isset( $content_restore['path'] ) ? (string) $content_restore['path'] : '/wordpress-files.zip',
					),
				);
			} elseif ( $wxr_import_enabled ) {
				$wxr_file_resource = isset( $content_restore['file_resource'] )
					? (string) $content_restore['file_resource']
					: 'url';
				$wxr_file = array(
					'resource' => 'url',
					'url' => self::PLAYGROUND_WXR_URL_PLACEHOLDER,
				);

				if ( 'bundled' === $wxr_file_resource || 'bundled-resource' === ( isset( $source_state_manifest['wxr_url_mode'] ) ? (string) $source_state_manifest['wxr_url_mode'] : '' ) ) {
					$wxr_file = array(
						'resource' => 'bundled',
						'path' => isset( $content_restore['path'] ) ? (string) $content_restore['path'] : '/content/site-content.wxr',
					);
				}

				$steps[] = array(
					'step' => 'importWxr',
					'file' => $wxr_file,
				);
			}

			$steps[] = array(
				'step' => 'wp-cli',
				'command' => $this->playground_source_handoff_option_command(
					$this->playground_source_handoff_option_payload( $source_state_manifest, $cloudflare_publish_included )
				),
			);
		}

		$steps[] = array(
			'step' => 'wp-cli',
			'command' => "wp option update permalink_structure '/%postname%/'",
		);
		$steps[] = array(
			'step' => 'wp-cli',
			'command' => 'wp rewrite flush --hard',
		);

		return array(
			'$schema' => 'https://playground.wordpress.net/blueprint-schema.json',
			'meta' => array(
				'title' => 'StillPress editable source handoff',
				'description' => 'Installs StillPress in WordPress Playground and records the static export source-state handoff.',
				'author' => 'StillPress',
				'categories' => array( 'static-sites', 'publishing', 'tools' ),
			),
			'landingPage' => '/wp-admin/tools.php?page=playground-static-site-generator',
			'login' => true,
			'preferredVersions' => array(
				'wp' => 'latest',
				'php' => '8.3',
			),
			'extraLibraries' => array( 'wp-cli' ),
			'steps' => $steps,
		);
	}

	/**
	 * Build the non-secret source handoff context stored inside restored Playgrounds.
	 *
	 * This intentionally does not copy effective WXR URLs from the handoff
	 * manifest. Provided URLs may contain signed access material and are needed
	 * only by the inline Blueprint import step, not by the restored database.
	 *
	 * @param array<string,mixed> $source_state_manifest Source-state manifest section.
	 * @param bool                $cloudflare_publish_included Whether Cloudflare artifacts are enabled.
	 * @return array<string,mixed>
	 */
	private function playground_source_handoff_option_payload( array $source_state_manifest, $cloudflare_publish_included ) {
		$source_access_expires_at = isset( $source_state_manifest['source_access_expires_at'] )
			? $source_state_manifest['source_access_expires_at']
			: null;
		$wordpress_files_snapshot = isset( $source_state_manifest['wordpress_files_snapshot'] ) && is_array( $source_state_manifest['wordpress_files_snapshot'] )
			? $source_state_manifest['wordpress_files_snapshot']
			: null;
		$full_site_restore = ! empty( $source_state_manifest['full_site_restore'] )
			|| ( is_array( $wordpress_files_snapshot ) && $this->is_playground_wordpress_files_snapshot_available( $wordpress_files_snapshot ) && ! empty( $source_state_manifest['content_restore']['blueprint_step'] ) && 'importWordPressFiles' === $source_state_manifest['content_restore']['blueprint_step'] );

		$payload = array(
			'schema' => 'https://stillpress.local/playground-source-handoff/v1',
			'version' => 1,
			'source_state' => array(
				'status' => isset( $source_state_manifest['status'] ) ? (string) $source_state_manifest['status'] : 'source-state-generated',
				'not_full_restore_bundle' => ! empty( $source_state_manifest['not_full_restore_bundle'] ),
			),
			'wxr' => array(
				'import_enabled' => ! empty( $source_state_manifest['wxr_import_enabled'] ),
				'url_mode' => isset( $source_state_manifest['wxr_url_mode'] ) ? (string) $source_state_manifest['wxr_url_mode'] : 'runtime-relative-export-path',
				'sha256' => isset( $source_state_manifest['wxr_sha256'] ) ? $source_state_manifest['wxr_sha256'] : null,
			),
			'source_access' => array(
				'expires_at' => is_string( $source_access_expires_at ) && '' !== $source_access_expires_at ? $source_access_expires_at : null,
				'expires_at_status' => isset( $source_state_manifest['source_access_expires_at_status'] ) ? (string) $source_state_manifest['source_access_expires_at_status'] : 'not-provided',
				'metadata_only' => true,
			),
			'publish' => array(
				'cloudflare_publish_included' => (bool) $cloudflare_publish_included,
			),
			'restore' => array(
				'content_only' => ! $full_site_restore,
				'full_site_restore' => $full_site_restore,
				'not_full_restore_bundle' => ! $full_site_restore,
				'mode' => $full_site_restore ? 'sqlite-full-site-wordpress-files' : 'wxr-content-only',
				'note' => $full_site_restore
					? 'SQLite full-site restore imports wp-content plus the SQLite database. It is owner-only source material, not a credential or authorization system.'
					: 'WXR import restores content only. It does not restore the full database, plugin settings, users, secrets, or runtime configuration.',
			),
			'security' => array(
				'credentials_stored' => false,
				'tokens_stored' => false,
				'owner_identity_stored' => false,
				'effective_wxr_url_stored' => false,
				'note' => 'No deploy credentials, authorization tokens, owner identity, or explicit WXR URL are stored in this option.',
			),
			'redeploy' => array(
				'workflow' => 'owner-operator',
				'requires_external_credentials' => true,
				'automatic_cloudflare_deploy' => false,
				'note' => 'Redeploy is an owner/operator workflow. Generated artifacts do not authorize deployment and Cloudflare deploy or rollback commands require credentials outside the export.',
			),
		);

		if ( is_array( $wordpress_files_snapshot ) ) {
			$payload['wordpress_files_snapshot'] = $this->playground_wordpress_files_snapshot_public_metadata( $wordpress_files_snapshot );
		}

		return $payload;
	}

	/**
	 * Build a WP-CLI command that writes the restored Playground context option.
	 *
	 * @param array<string,mixed> $payload Handoff context payload.
	 * @return string WP-CLI command.
	 */
	private function playground_source_handoff_option_command( array $payload ) {
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );

		return 'wp option update ' . self::PLAYGROUND_SOURCE_HANDOFF_OPTION . ' ' . escapeshellarg( $json ) . ' --format=json --autoload=off';
	}

	/**
	 * Build the static /wp-admin/ handoff page.
	 *
	 * @param string $source_state_status Source-state status for this export.
	 * @param array<string,mixed> $blueprint Generated Blueprint template.
	 * @param array<string,mixed> $source_state_manifest Source-state manifest section.
	 * @return string HTML.
	 */
	private function playground_handoff_html( $source_state_status = 'manifest-pointer-only', array $blueprint = array(), array $source_state_manifest = array() ) {
		$playground_base = 'https://playground.wordpress.net/';
		$has_source_state = 'source-state-generated' === $source_state_status;
		$source_message  = $has_source_state
			? '<p>This export includes owner-only source-state artifacts at <code>_playground-source/source-state.json</code> and <code>_playground-source/site-content.wxr</code>. The handoff imports WXR content when the WXR URL is reachable by WordPress Playground through a public, signed, or private URL, but it is still not a full database, plugin settings, users, secrets, or runtime restore.</p>'
			: '<p>The handoff stores a deterministic source-state manifest at <code>wp-admin/playground-source-manifest.json</code>. This first slice records a manifest pointer and TODO for durable site bundle storage.</p>';
		$noscript_message = $has_source_state
			? '<noscript><p>Enable JavaScript and serve <code>_playground-source/site-content.wxr</code> through a public, signed, or private URL so this handoff can build an inline Blueprint for WordPress Playground.</p></noscript>'
			: '<noscript><p>Enable JavaScript or publish this export and append the absolute <code>wp-admin/playground-blueprint.json</code> URL to <code>https://playground.wordpress.net/?blueprint-url=</code>.</p></noscript>';
		$script = $has_source_state
			? $this->playground_inline_blueprint_script( $playground_base, $blueprint, isset( $source_state_manifest['effective_wxr_url'] ) ? (string) $source_state_manifest['effective_wxr_url'] : '' )
			: $this->playground_remote_blueprint_script( $playground_base );

		return implode(
			"\n",
			array(
				'<!doctype html>',
				'<html lang="en">',
				'<head>',
				'<meta charset="UTF-8" />',
				'<meta name="viewport" content="width=device-width, initial-scale=1" />',
				'<title>Open editable WordPress source</title>',
				'<style>',
				'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:#f6f7f7;color:#1d2327;}',
				'main{max-width:720px;margin:0 auto;padding:48px 24px;}',
				'a.button{display:inline-block;background:#2271b1;color:#fff;padding:10px 14px;text-decoration:none;border-radius:3px;}',
				'code{background:#fff;border:1px solid #dcdcde;padding:2px 4px;}',
				'</style>',
				'</head>',
				'<body>',
				'<main>',
				'<h1>Open editable WordPress source</h1>',
				'<p>This static admin route opens WordPress Playground with the export handoff Blueprint.</p>',
				'<p><a class="button" id="ssgwp-playground-link" href="' . esc_attr( $playground_base ) . '">Open in WordPress Playground</a></p>',
				'<p>The handoff manifest is <code>wp-admin/playground-source-manifest.json</code>.</p>',
				$source_message,
				'<p>Treat source-state files as owner-only because they may expose editable source content. Do not blindly expose <code>_playground-source/</code> on generic static hosting.</p>',
				$noscript_message,
				'</main>',
				$script,
				'</body>',
				'</html>',
				''
			)
		);
	}

	/**
	 * Build the source-state handoff script with an inline Blueprint fragment.
	 *
	 * @param string $playground_base Playground base URL.
	 * @param array<string,mixed> $blueprint Blueprint template.
	 * @param string $provided_wxr_url Explicit WXR URL, or empty to use runtime relative URL.
	 * @return string Script HTML.
	 */
	private function playground_inline_blueprint_script( $playground_base, array $blueprint, $provided_wxr_url = '' ) {
		$blueprint_json = wp_json_encode( $blueprint, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		$wxr_url_line   = '' === $provided_wxr_url
			? 'var wxrUrl=new URL("../_playground-source/site-content.wxr", window.location.href).href;'
			: 'var wxrUrl=' . wp_json_encode( $provided_wxr_url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';';

		return implode(
			"\n",
			array(
				'<script>',
				'(function(){',
				'var link=document.getElementById("ssgwp-playground-link");',
				'if(!link){return;}',
				$wxr_url_line,
				'var blueprint=' . $blueprint_json . ';',
				'for(var i=0;i<blueprint.steps.length;i++){',
				'var step=blueprint.steps[i];',
				'if(step&&step.step==="importWxr"&&step.file&&step.file.url==="' . esc_attr( self::PLAYGROUND_WXR_URL_PLACEHOLDER ) . '"){step.file.url=wxrUrl;}',
				'}',
				'link.href="' . esc_attr( $playground_base ) . '#"+window.encodeURIComponent(JSON.stringify(blueprint));',
				'}());',
				'</script>'
			)
		);
	}

	/**
	 * Build the non-source-state handoff script with a remote Blueprint URL.
	 *
	 * @param string $playground_base Playground base URL.
	 * @return string Script HTML.
	 */
	private function playground_remote_blueprint_script( $playground_base ) {
		return implode(
			"\n",
			array(
				'<script>',
				'(function(){',
				'var link=document.getElementById("ssgwp-playground-link");',
				'if(!link){return;}',
				'var blueprintUrl=new URL("playground-blueprint.json",window.location.href).href;',
				'link.href="' . esc_attr( $playground_base ) . '?blueprint-url="+window.encodeURIComponent(blueprintUrl);',
				'}());',
				'</script>'
			)
		);
	}

	/**
	 * Write deterministic Cloudflare Workers static publishing artifacts.
	 *
	 * @param string $output_dir Static export directory.
	 * @param array  $args       Export args.
	 * @return array<string,mixed> Public export summary.
	 * @throws Exception When files cannot be written.
	 */
	private function write_cloudflare_publish_contract( $output_dir, array $args ) {
		$worker_name        = $this->sanitize_cloudflare_worker_name( isset( $args['cloudflare_worker_name'] ) ? $args['cloudflare_worker_name'] : '' );
		$compatibility_date = $this->sanitize_cloudflare_compatibility_date( isset( $args['cloudflare_compatibility_date'] ) ? $args['cloudflare_compatibility_date'] : '' );
		$deploy_directory   = '_cloudflare-publish';
		$asset_directory    = 'site';
		$deploy_dir         = trailingslashit( $output_dir ) . $deploy_directory;
		$asset_dir          = trailingslashit( $deploy_dir ) . $asset_directory;
		$package_scripts    = $this->cloudflare_deploy_package_scripts();
		$workflow_commands  = $this->cloudflare_deploy_workflow_commands( $deploy_directory );

		$wrangler = array(
			'name' => $worker_name,
			'compatibility_date' => $compatibility_date,
			'main' => './cloudflare-worker.js',
			'assets' => array(
				'directory' => './' . $asset_directory,
				'binding' => 'ASSETS',
				'html_handling' => 'auto-trailing-slash',
				'not_found_handling' => '404-page',
			),
			'workers_dev' => true,
		);

		$this->reset_cloudflare_deploy_directory( $output_dir, $deploy_dir );
		$this->copy_cloudflare_site_assets( $output_dir, $deploy_dir, $asset_dir );
		$asset_inventory = $this->cloudflare_site_asset_inventory( $asset_dir );
		$contract        = $this->cloudflare_publish_manifest( $worker_name, $compatibility_date, $deploy_directory, $asset_directory, $workflow_commands, $package_scripts, $asset_inventory );

		$this->write_file( trailingslashit( $deploy_dir ) . 'package.json', $this->encode_static_json( $this->cloudflare_deploy_package_json( $worker_name, $package_scripts ) ) );
		$this->write_file( trailingslashit( $deploy_dir ) . 'cloudflare-deploy-check.mjs', $this->cloudflare_deploy_check_script() );
		$this->write_file( trailingslashit( $deploy_dir ) . 'cloudflare-worker.js', $this->cloudflare_worker_script() );
		$this->write_file( trailingslashit( $deploy_dir ) . 'wrangler.jsonc', $this->encode_static_json( $wrangler ) );
		$this->write_file( trailingslashit( $deploy_dir ) . 'cloudflare-publish.json', $this->encode_static_json( $contract ) );
		$this->write_file( trailingslashit( $deploy_dir ) . 'CLOUDFLARE-WORKERS.md', $this->cloudflare_publish_readme( $worker_name, $deploy_directory, $asset_directory, $workflow_commands ) );

		return array(
			'included'             => true,
			'deploy_directory'     => $deploy_directory,
			'asset_directory'      => $deploy_directory . '/' . $asset_directory,
			'manifest_path'        => $deploy_directory . '/cloudflare-publish.json',
			'package_json_path'    => $deploy_directory . '/package.json',
			'deploy_check_path'    => $deploy_directory . '/cloudflare-deploy-check.mjs',
			'wrangler_config_path' => $deploy_directory . '/wrangler.jsonc',
			'worker_script_path'   => $deploy_directory . '/cloudflare-worker.js',
			'readme_path'          => $deploy_directory . '/CLOUDFLARE-WORKERS.md',
			'deploy_command'       => $workflow_commands['deploy'],
			'workflow_commands'    => $workflow_commands,
			'asset_inventory'      => $asset_inventory,
			'worker_name'          => $worker_name,
			'network_calls'        => false,
		);
	}

	/**
	 * Reset the Cloudflare deploy package directory without following symlinks.
	 *
	 * @param string $output_dir Export root.
	 * @param string $deploy_dir Cloudflare deploy package directory.
	 * @throws Exception When the deploy directory cannot be prepared safely.
	 */
	private function reset_cloudflare_deploy_directory( $output_dir, $deploy_dir ) {
		$output_dir = wp_normalize_path( $output_dir );
		$deploy_dir = wp_normalize_path( $deploy_dir );

		if ( SSGWP_Path_Utils::has_parent_segment( $deploy_dir ) || ! $this->is_inside_export_root( $deploy_dir ) ) {
			throw new Exception( 'Refusing to prepare the Cloudflare deploy directory outside of the export directory.' );
		}

		if ( is_link( $deploy_dir ) ) {
			if ( ! unlink( $deploy_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				throw new Exception( 'Could not remove the existing Cloudflare deploy directory symlink.' );
			}
		} elseif ( file_exists( $deploy_dir ) ) {
			if ( ! is_dir( $deploy_dir ) ) {
				throw new Exception( 'The Cloudflare deploy path exists and is not a directory.' );
			}

			$real_deploy_dir = realpath( $deploy_dir );

			if ( false === $real_deploy_dir || ! $this->is_inside_export_root( $real_deploy_dir ) ) {
				throw new Exception( 'Refusing to delete a Cloudflare deploy directory outside of the export directory.' );
			}

			$this->delete_cloudflare_deploy_directory( $deploy_dir );
		}

		if ( ! wp_mkdir_p( $deploy_dir ) ) {
			throw new Exception( 'Could not create the Cloudflare deploy directory.' );
		}

		$this->assert_cloudflare_deploy_directory( $output_dir, $deploy_dir );
	}

	/**
	 * Delete a Cloudflare deploy package directory without following symlinks.
	 *
	 * @param string $directory Directory path.
	 * @throws Exception When a path cannot be deleted.
	 */
	private function delete_cloudflare_deploy_directory( $directory ) {
		if ( is_link( $directory ) ) {
			if ( ! unlink( $directory ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				throw new Exception( 'Could not remove the existing Cloudflare deploy directory symlink.' );
			}

			return;
		}

		if ( ! is_dir( $directory ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$path = $item->getPathname();

			if ( $item->isLink() || ! $item->isDir() ) {
				if ( ! unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					throw new Exception( 'Could not delete a file from the Cloudflare deploy directory.' );
				}
				continue;
			}

			if ( ! rmdir( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				throw new Exception( 'Could not delete a subdirectory from the Cloudflare deploy directory.' );
			}
		}

		if ( ! rmdir( $directory ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			throw new Exception( 'Could not delete the existing Cloudflare deploy directory.' );
		}
	}

	/**
	 * Ensure the Cloudflare deploy package directory resolves under the export root.
	 *
	 * @param string $output_dir Export root.
	 * @param string $deploy_dir Cloudflare deploy package directory.
	 * @throws Exception When the deploy directory is not safe to write.
	 */
	private function assert_cloudflare_deploy_directory( $output_dir, $deploy_dir ) {
		if ( is_link( $deploy_dir ) || ! is_dir( $deploy_dir ) ) {
			throw new Exception( 'The Cloudflare deploy directory must be a real directory.' );
		}

		$real_output_dir = realpath( $output_dir );
		$real_deploy_dir = realpath( $deploy_dir );

		if (
			false === $real_output_dir
			|| false === $real_deploy_dir
			|| ! SSGWP_Path_Utils::is_path_inside_directory( $real_deploy_dir, $real_output_dir )
		) {
			throw new Exception( 'The Cloudflare deploy directory must resolve inside the export directory.' );
		}
	}

	/**
	 * Build the deterministic Cloudflare publish manifest.
	 *
	 * @param string $worker_name        Worker script name.
	 * @param string $compatibility_date Worker compatibility date.
	 * @param string $deploy_directory   Deploy package directory relative to export root.
	 * @param string $asset_directory    Served asset directory relative to deploy package.
	 * @param array  $workflow_commands  Local workflow commands keyed by step.
	 * @param array  $package_scripts    Package script commands keyed by script name.
	 * @param array  $asset_inventory    Served static asset inventory.
	 * @return array<string,mixed>
	 */
	private function cloudflare_publish_manifest( $worker_name, $compatibility_date, $deploy_directory, $asset_directory, array $workflow_commands, array $package_scripts, array $asset_inventory ) {
		return array(
			'schema' => 'https://stillpress.local/cloudflare-workers-publish/v1',
			'version' => 1,
			'publish_target' => 'cloudflare-workers-static-assets',
			'network_calls' => false,
			'artifacts' => array(
				'deploy_directory' => $deploy_directory,
				'package_json' => $deploy_directory . '/package.json',
				'deploy_check_script' => $deploy_directory . '/cloudflare-deploy-check.mjs',
				'wrangler_config' => $deploy_directory . '/wrangler.jsonc',
				'worker_script' => $deploy_directory . '/cloudflare-worker.js',
				'publish_manifest' => $deploy_directory . '/cloudflare-publish.json',
				'readme' => $deploy_directory . '/CLOUDFLARE-WORKERS.md',
				'asset_directory' => $deploy_directory . '/' . $asset_directory,
				'asset_directory_from_wrangler_config' => './' . $asset_directory,
				'deploy_command' => $workflow_commands['deploy'],
			),
			'asset_inventory' => $asset_inventory,
			'deploy_workflow' => array(
				'status' => 'generated',
				'package_manager' => 'npm scripts',
				'export_generation_network_calls' => false,
				'local_validation_network_calls' => false,
				'wrangler_commands_may_call_cloudflare' => true,
				'commands' => $workflow_commands,
				'package_scripts' => $package_scripts,
			),
			'local_validation' => array(
				'offline' => array(
					'command' => $workflow_commands['offline_validation'],
					'package_script' => 'validate:offline',
					'requires_credentials' => false,
					'network_calls' => false,
				),
				'credentials' => array(
					'command' => $workflow_commands['credentials_validation'],
					'package_script' => 'validate:credentials',
					'requires_credentials' => true,
					'network_calls' => false,
				),
			),
			'wrangler' => array(
				'name' => $worker_name,
				'compatibility_date' => $compatibility_date,
				'assets_binding' => 'ASSETS',
				'not_found_handling' => '404-page',
				'workers_dev' => true,
			),
			'credentials' => array(
				'required_environment_variables' => array(
					'CLOUDFLARE_ACCOUNT_ID',
					'CLOUDFLARE_API_TOKEN',
				),
				'account_permissions' => array(
					'Workers Scripts:Edit',
				),
				'zone_permissions_for_routes_or_custom_domains' => array(
					'Workers Routes:Edit',
					'Zone:Read',
				),
				'optional_zone_permissions' => array(
					'DNS:Edit if the deploy process must create or change DNS records separately from a Workers custom domain',
				),
			),
			'routing' => array(
				'default' => 'workers.dev preview is enabled by workers_dev=true',
				'production' => 'Add routes with zone_name or zone_id, or add custom_domain=true routes, in wrangler.jsonc before a real production deploy.',
			),
			'free_tier_limits' => array(
				'requests_per_day' => 100000,
				'cpu_time_per_request' => '10 ms',
				'memory_per_isolate' => '128 MB',
				'workers_per_account' => 100,
				'static_asset_files_per_worker_version' => 20000,
				'individual_static_asset_file_size' => '25 MiB',
				'individual_static_asset_file_size_bytes' => 26214400,
			),
			'source_docs' => array(
				'https://developers.cloudflare.com/workers/wrangler/configuration/',
				'https://developers.cloudflare.com/workers/static-assets/',
				'https://developers.cloudflare.com/workers/platform/limits/',
				'https://developers.cloudflare.com/fundamentals/api/reference/permissions/',
				'https://developers.cloudflare.com/fundamentals/api/reference/template/',
				'https://developers.cloudflare.com/workers/configuration/routing/',
			),
			'known_limitations' => array(
				'This artifact is local-only and does not call Cloudflare.',
				'Running the generated Wrangler deploy, inspect, or rollback scripts may call Cloudflare.',
				'Secrets are not stored in the export.',
				'Dynamic WordPress features still require a live backend or a static-compatible service.',
			),
		);
	}

	/**
	 * Build the minimal Worker script that delegates to Workers Static Assets.
	 *
	 * @return string JavaScript.
	 */
	private function cloudflare_worker_script() {
		return implode(
			"\n",
			array(
				'export default {',
				'	async fetch(request, env) {',
				'		return env.ASSETS.fetch(request);',
				'	},',
				'};',
				''
			)
		);
	}

	/**
	 * Build deterministic npm scripts for the generated deploy package.
	 *
	 * @return array<string,string>
	 */
	private function cloudflare_deploy_package_scripts() {
		return array(
			'validate:offline' => 'node cloudflare-deploy-check.mjs --offline',
			'validate:credentials' => 'node cloudflare-deploy-check.mjs --require-credentials',
			'deploy:dry-run' => 'npx wrangler deploy --config wrangler.jsonc --dry-run',
			'deploy' => 'npx wrangler deploy --config wrangler.jsonc',
			'versions' => 'npx wrangler versions list --config wrangler.jsonc',
			'deployments' => 'npx wrangler deployments list --config wrangler.jsonc',
			'rollback' => 'npx wrangler rollback --config wrangler.jsonc',
		);
	}

	/**
	 * Build commands that can be run from the export root.
	 *
	 * @param string $deploy_directory Deploy package directory relative to export root.
	 * @return array<string,string>
	 */
	private function cloudflare_deploy_workflow_commands( $deploy_directory ) {
		return array(
			'offline_validation' => 'cd ' . $deploy_directory . ' && npm run validate:offline',
			'credentials_validation' => 'cd ' . $deploy_directory . ' && npm run validate:credentials',
			'dry_run_deploy' => 'cd ' . $deploy_directory . ' && npm run deploy:dry-run',
			'deploy' => 'cd ' . $deploy_directory . ' && npm run deploy',
			'versions_list' => 'cd ' . $deploy_directory . ' && npm run versions',
			'deployments_list' => 'cd ' . $deploy_directory . ' && npm run deployments',
			'rollback' => 'cd ' . $deploy_directory . ' && npm run rollback',
		);
	}

	/**
	 * Build package.json data for the generated deploy package.
	 *
	 * @param string $worker_name     Worker script name.
	 * @param array  $package_scripts Package script commands keyed by script name.
	 * @return array<string,mixed>
	 */
	private function cloudflare_deploy_package_json( $worker_name, array $package_scripts ) {
		return array(
			'name' => $worker_name . '-cloudflare-publish',
			'private' => true,
			'type' => 'module',
			'scripts' => $package_scripts,
		);
	}

	/**
	 * Build the deterministic local deploy package validation script.
	 *
	 * @return string JavaScript.
	 */
	private function cloudflare_deploy_check_script() {
		return implode(
			"\n",
			array(
				'import { existsSync, lstatSync, readdirSync, readFileSync, statSync } from "node:fs";',
				'import { join } from "node:path";',
				'import { fileURLToPath } from "node:url";',
				'',
				'const root = fileURLToPath(new URL(".", import.meta.url));',
				'const args = new Set(process.argv.slice(2));',
				'const requireCredentials = args.has("--require-credentials");',
				'const offline = args.has("--offline") || requireCredentials;',
				'const maxStaticAssetBytes = 25 * 1024 * 1024;',
				'',
				'if (!offline) {',
				'	console.error("Usage: node cloudflare-deploy-check.mjs --offline|--require-credentials");',
				'	process.exit(2);',
				'}',
				'',
				'function fail(message) {',
				'	console.error("Cloudflare deploy package check failed: " + message);',
				'	process.exit(1);',
				'}',
				'',
				'function readJson(file) {',
				'	try {',
				'		return JSON.parse(readFileSync(join(root, file), "utf8"));',
				'	} catch (error) {',
				'		fail("Could not read valid JSON from " + file + ": " + error.message);',
				'	}',
				'}',
				'',
				'function assertFile(file) {',
				'	const path = join(root, file);',
				'	if (!existsSync(path) || !statSync(path).isFile()) {',
				'		fail("Missing required file " + file);',
				'	}',
				'}',
				'',
				'function inventorySite(directory) {',
				'	const start = join(root, directory);',
				'	if (!existsSync(start) || !statSync(start).isDirectory()) {',
				'		fail("Missing served asset directory " + directory);',
				'	}',
				'',
				'	let fileCount = 0;',
				'	let largestFileSizeBytes = 0;',
				'	const pending = [start];',
				'',
				'	while (pending.length > 0) {',
				'		const current = pending.pop();',
				'		for (const name of readdirSync(current).sort()) {',
				'			const path = join(current, name);',
				'			const lstat = lstatSync(path);',
				'',
				'			if (lstat.isSymbolicLink()) {',
				'				fail("Served asset directory contains a symlink: " + name);',
				'			}',
				'',
				'			if (lstat.isDirectory()) {',
				'				pending.push(path);',
				'				continue;',
				'			}',
				'',
				'			if (!lstat.isFile()) {',
				'				continue;',
				'			}',
				'',
				'			fileCount += 1;',
				'			largestFileSizeBytes = Math.max(largestFileSizeBytes, lstat.size);',
				'		}',
				'	}',
				'',
				'	return { file_count: fileCount, largest_file_size_bytes: largestFileSizeBytes };',
				'}',
				'',
				'for (const file of ["package.json", "cloudflare-deploy-check.mjs", "wrangler.jsonc", "cloudflare-worker.js", "cloudflare-publish.json", "CLOUDFLARE-WORKERS.md"]) {',
				'	assertFile(file);',
				'}',
				'',
				'const manifest = readJson("cloudflare-publish.json");',
				'const packageJson = readJson("package.json");',
				'const wrangler = readJson("wrangler.jsonc");',
				'',
				'if (manifest.network_calls !== false || manifest.deploy_workflow?.export_generation_network_calls !== false) {',
				'	fail("Manifest must record that export generation makes no network calls");',
				'}',
				'',
				'if (wrangler.assets?.directory !== "./site") {',
				'	fail("Wrangler assets.directory must be ./site");',
				'}',
				'',
				'if (manifest.artifacts?.asset_directory_from_wrangler_config !== wrangler.assets.directory) {',
				'	fail("Manifest and Wrangler config disagree on the asset directory");',
				'}',
				'',
				'const expectedScripts = {',
				'	"validate:offline": "node cloudflare-deploy-check.mjs --offline",',
				'	"validate:credentials": "node cloudflare-deploy-check.mjs --require-credentials",',
				'	"deploy:dry-run": "npx wrangler deploy --config wrangler.jsonc --dry-run",',
				'	"deploy": "npx wrangler deploy --config wrangler.jsonc",',
				'	"versions": "npx wrangler versions list --config wrangler.jsonc",',
				'	"deployments": "npx wrangler deployments list --config wrangler.jsonc",',
				'	"rollback": "npx wrangler rollback --config wrangler.jsonc"',
				'};',
				'',
				'for (const [script, command] of Object.entries(expectedScripts)) {',
				'	if (packageJson.scripts?.[script] !== command) {',
				'		fail("package.json script " + script + " does not match the expected command");',
				'	}',
				'}',
				'',
				'for (const controlFile of ["package.json", "cloudflare-deploy-check.mjs", "wrangler.jsonc", "cloudflare-worker.js", "cloudflare-publish.json", "CLOUDFLARE-WORKERS.md"]) {',
				'	if (existsSync(join(root, "site", controlFile))) {',
				'		fail("Control file was copied into the served asset directory: " + controlFile);',
				'	}',
				'}',
				'',
				'const inventory = inventorySite("site");',
				'const fileLimit = manifest.free_tier_limits?.static_asset_files_per_worker_version;',
				'const fileSizeLimit = manifest.free_tier_limits?.individual_static_asset_file_size_bytes ?? maxStaticAssetBytes;',
				'',
				'if (inventory.file_count > fileLimit) {',
				'	fail("Static asset file count exceeds the Workers Free limit");',
				'}',
				'',
				'if (inventory.largest_file_size_bytes > fileSizeLimit) {',
				'	fail("Largest static asset exceeds the Workers Free per-file limit");',
				'}',
				'',
				'if (manifest.asset_inventory?.file_count !== inventory.file_count || manifest.asset_inventory?.largest_file_size_bytes !== inventory.largest_file_size_bytes) {',
				'	fail("Manifest asset inventory does not match the local site directory");',
				'}',
				'',
				'if (requireCredentials) {',
				'	const required = manifest.credentials?.required_environment_variables ?? ["CLOUDFLARE_ACCOUNT_ID", "CLOUDFLARE_API_TOKEN"];',
				'	const missing = required.filter((name) => !process.env[name]);',
				'',
				'	if (missing.length > 0) {',
				'		fail("Missing required environment variable(s): " + missing.join(", "));',
				'	}',
				'}',
				'',
				'console.log("Cloudflare deploy package check passed (" + (requireCredentials ? "credentials" : "offline") + ").");',
				'console.log("Files: " + inventory.file_count + "; largest file: " + inventory.largest_file_size_bytes + " bytes.");',
				''
			)
		);
	}

	/**
	 * Inspect the generated Cloudflare served asset directory.
	 *
	 * @param string $asset_dir Served asset directory path.
	 * @return array<string,int>
	 */
	private function cloudflare_site_asset_inventory( $asset_dir ) {
		$file_count              = 0;
		$largest_file_size_bytes = 0;

		if ( ! is_dir( $asset_dir ) ) {
			return array(
				'file_count' => 0,
				'largest_file_size_bytes' => 0,
			);
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $asset_dir, FilesystemIterator::SKIP_DOTS ),
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
	 * Refresh Cloudflare manifest inventory after late-written served assets.
	 *
	 * @param string              $output_dir Static export directory.
	 * @param array<string,mixed> $summary    Cloudflare publish summary.
	 * @return array<string,mixed> Updated Cloudflare publish summary.
	 * @throws Exception When the existing manifest cannot be refreshed.
	 */
	private function refresh_cloudflare_publish_asset_inventory( $output_dir, array $summary ) {
		if ( empty( $summary['asset_directory'] ) || empty( $summary['manifest_path'] ) ) {
			return $summary;
		}

		$asset_dir     = trailingslashit( $output_dir ) . $summary['asset_directory'];
		$manifest_path = trailingslashit( $output_dir ) . $summary['manifest_path'];
		$manifest_json = file_get_contents( $manifest_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$manifest      = is_string( $manifest_json ) ? json_decode( $manifest_json, true ) : null;

		if ( ! is_array( $manifest ) ) {
			throw new Exception( 'Could not refresh the Cloudflare publish manifest asset inventory.' );
		}

		$asset_inventory             = $this->cloudflare_site_asset_inventory( $asset_dir );
		$manifest['asset_inventory'] = $asset_inventory;
		$summary['asset_inventory']  = $asset_inventory;

		$this->write_file( $manifest_path, $this->encode_static_json( $manifest ), false );

		return $summary;
	}

	/**
	 * Build human-readable Cloudflare publish guidance.
	 *
	 * @param string $worker_name      Worker script name.
	 * @param string $deploy_directory Deploy package directory relative to export root.
	 * @param string $asset_directory  Served asset directory relative to deploy package.
	 * @param array  $workflow_commands Local workflow commands keyed by step.
	 * @return string Markdown.
	 */
	private function cloudflare_publish_readme( $worker_name, $deploy_directory, $asset_directory, array $workflow_commands ) {
		return implode(
			"\n",
			array(
				'# Cloudflare Workers Static Deploy Workflow',
				'',
				'This export includes deterministic local artifacts for Cloudflare Workers Static Assets. No Cloudflare network call was made while creating this export.',
				'',
				'Artifacts:',
				'',
				'- `' . $deploy_directory . '/wrangler.jsonc` configures Worker `' . $worker_name . '` with `assets.directory` set to `./' . $asset_directory . '`.',
				'- `cloudflare-worker.js` delegates requests to the `ASSETS` binding.',
				'- `package.json` exposes local validation, dry-run, deploy, versions, deployments, and rollback scripts.',
				'- `cloudflare-deploy-check.mjs` validates the local package structure and free-tier static asset limits without credentials or network.',
				'- `cloudflare-publish.json` records the credential, routing, deploy workflow, asset inventory, and free-tier contract for review and automation.',
				'- `' . $deploy_directory . '/' . $asset_directory . '` contains the served static assets; Worker/config/manifest/docs files are outside that directory.',
				'- `_playground-source/`, when generated, is owner-only restore material and is not copied into `' . $deploy_directory . '/' . $asset_directory . '`.',
				'',
				'Workflow:',
				'',
				'```bash',
				$workflow_commands['offline_validation'],
				$workflow_commands['credentials_validation'],
				$workflow_commands['dry_run_deploy'],
				$workflow_commands['deploy'],
				$workflow_commands['versions_list'],
				$workflow_commands['deployments_list'],
				$workflow_commands['rollback'],
				'```',
				'',
				'Run offline validation first. It checks package structure and Workers Free static asset limits without credentials or network. Credential validation only checks that `CLOUDFLARE_ACCOUNT_ID` and `CLOUDFLARE_API_TOKEN` are present in the environment and does not print their values.',
				'',
				'Use the dry-run deploy before a real deploy. The generated Wrangler deploy, versions list, deployments list, and rollback commands may call Cloudflare when you run them.',
				'',
				'Minimum API token permissions for deploy: Account `Workers Scripts:Edit`. Add Zone `Workers Routes:Edit` and `Zone:Read` when deploying a route or custom domain. Add Zone `DNS:Edit` only if your deploy automation must create or change DNS records separately.',
				'',
				'Production routing is not enabled by default. Add a Workers route with `zone_name` or `zone_id`, or a custom-domain route with `custom_domain=true`, before using this for a production hostname.',
				'',
				'Free-tier limits recorded for this slice include 100,000 requests per day, 10 ms CPU time per request, 128 MB memory per isolate, 100 Workers per account, 20,000 static asset files per Worker version, and 25 MiB per individual static asset file.',
				''
			)
		);
	}

	/**
	 * Sanitize a Cloudflare Worker name.
	 *
	 * @param string $name Candidate name.
	 * @return string Safe name.
	 */
	private function sanitize_cloudflare_worker_name( $name ) {
		$name   = strtolower( trim( (string) $name ) );
		$output = '';
		$length = strlen( $name );

		for ( $i = 0; $i < $length; ++$i ) {
			$char = $name[ $i ];
			if ( ( $char >= 'a' && $char <= 'z' ) || ( $char >= '0' && $char <= '9' ) || '-' === $char ) {
				$output .= $char;
			}
		}

		$output = trim( $output, '-' );

		return '' === $output ? 'stillpress-static-site' : substr( $output, 0, 63 );
	}

	/**
	 * Sanitize a Cloudflare compatibility date.
	 *
	 * @param string $date Candidate date.
	 * @return string Safe YYYY-MM-DD date.
	 */
	private function sanitize_cloudflare_compatibility_date( $date ) {
		$date = trim( (string) $date );

		if ( 10 === strlen( $date ) && ctype_digit( substr( $date, 0, 4 ) . substr( $date, 5, 2 ) . substr( $date, 8, 2 ) ) && '-' === $date[4] && '-' === $date[7] ) {
			$year  = (int) substr( $date, 0, 4 );
			$month = (int) substr( $date, 5, 2 );
			$day   = (int) substr( $date, 8, 2 );

			if ( checkdate( $month, $day, $year ) ) {
				return $date;
			}
		}

		return '2026-06-08';
	}

	/**
	 * Copy the completed static site into the Cloudflare served asset directory.
	 *
	 * @param string $output_dir Export root.
	 * @param string $deploy_dir Cloudflare deploy package directory.
	 * @param string $asset_dir  Served asset directory inside the deploy package.
	 * @throws Exception When files cannot be copied.
	 */
	private function copy_cloudflare_site_assets( $output_dir, $deploy_dir, $asset_dir ) {
		$output_dir = trailingslashit( wp_normalize_path( $output_dir ) );
		$deploy_dir = trailingslashit( wp_normalize_path( $deploy_dir ) );
		$asset_dir  = wp_normalize_path( $asset_dir );

		if ( ! wp_mkdir_p( $asset_dir ) ) {
			throw new Exception( 'Could not create the Cloudflare served asset directory.' );
		}

		$control_files = array(
			'CLOUDFLARE-WORKERS.md' => true,
			'cloudflare-deploy-check.mjs' => true,
			'cloudflare-publish.json' => true,
			'cloudflare-worker.js' => true,
			'package.json' => true,
			'wrangler.jsonc' => true,
		);
		$iterator      = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $output_dir, FilesystemIterator::SKIP_DOTS ),
				function ( $item ) use ( $output_dir, $deploy_dir, $control_files ) {
					$source_path = wp_normalize_path( $item->getPathname() );

					if ( 0 === strpos( trailingslashit( $source_path ), $deploy_dir ) || $item->isLink() ) {
						return false;
					}

					$relative = ltrim( str_replace( $output_dir, '', $source_path ), '/' );

					if ( '_playground-source' === $relative || 0 === strpos( $relative, '_playground-source/' ) ) {
						return false;
					}

					return ! isset( $control_files[ $relative ] );
				}
			),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$source_path = wp_normalize_path( $item->getPathname() );
			$relative = ltrim( str_replace( $output_dir, '', $source_path ), '/' );
			$target_path = trailingslashit( $asset_dir ) . $relative;

			if ( $item->isDir() ) {
				if ( ! wp_mkdir_p( $target_path ) ) {
					throw new Exception( 'Could not create a Cloudflare served asset subdirectory.' );
				}
				continue;
			}

			$this->write_file( $target_path, file_get_contents( $source_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
	}

	/**
	 * Encode deterministic JSON for generated static artifacts.
	 *
	 * @param array<string,mixed> $data Data to encode.
	 * @return string JSON with trailing newline.
	 */
	private function encode_static_json( array $data ) {
		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	}

	/**
	 * Warn when rendered HTML contains behavior that cannot remain dynamic.
	 *
	 * These checks do not rewrite HTML. They give users explicit export guidance
	 * for common WordPress features that need a backend after publication.
	 *
	 * @param string $html Rendered HTML.
	 * @param string $url  Exported page URL.
	 */
	private function collect_dynamic_behavior_warnings( $html, $url ) {
		$html = (string) $html;
		$url  = (string) $url;

		if ( preg_match( '#<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?#i', $html ) ) {
			$this->add_dynamic_warning_once(
				'post_form',
				'POST forms are exported as static markup and need a live backend or a static-compatible form service. Detected while exporting ' . $url . '.'
			);
		}

		if (
			preg_match( '#<form\b[^>]*\brole\s*=\s*["\']?search["\']?#i', $html )
			|| preg_match( '#<input\b[^>]*\bname\s*=\s*["\']?s["\']?#i', $html )
		) {
			$this->add_dynamic_warning_once(
				'search_form',
				'Search forms are exported as static markup and need a live backend or a static-compatible search index. Detected while exporting ' . $url . '.'
			);
		}

		if (
			preg_match( '#\b(wc-block-cart|woocommerce-cart|woocommerce-checkout|woocommerce-account|woocommerce-cart-form)\b#i', $html )
			|| preg_match( '#/(cart|checkout|my-account)(?:/|$)#i', (string) wp_parse_url( $url, PHP_URL_PATH ) )
		) {
			$this->add_dynamic_warning_once(
				'woocommerce_action_page',
				'WooCommerce cart, checkout, and account pages are exported as static snapshots and need a live backend for customer actions. Detected while exporting ' . $url . '.'
			);
		}

		if ( false !== stripos( $html, '/wp-json/' ) || false !== stripos( $html, 'rest_route=' ) ) {
			$this->add_dynamic_warning_once(
				'rest_api',
				'REST API links are exported as static references; REST API writes need a live backend. Detected while exporting ' . $url . '.'
			);
		}
	}

	/**
	 * Record one warning per dynamic behavior category.
	 *
	 * @param string $key     Warning category key.
	 * @param string $warning Warning message.
	 */
	private function add_dynamic_warning_once( $key, $warning ) {
		if ( isset( $this->dynamic_warnings[ $key ] ) ) {
			return;
		}

		$this->dynamic_warnings[ $key ] = true;
		$this->warnings[]               = $warning;
	}

	/**
	 * Add missing core block stylesheet links for rendered block classes.
	 *
	 * Internal rendering can produce block markup without separate core block
	 * stylesheet links. Static exports need those links because copied core block
	 * CSS files are otherwise present in the ZIP but never loaded by the page.
	 *
	 * @param string $html Rendered HTML.
	 * @return string HTML with missing core block stylesheet links.
	 */
	private function inject_missing_core_block_styles( $html ) {
		$html = (string) $html;
		$markup = preg_replace( '#<(style|script)\b[^>]*>.*?</\1>#is', '', $html );

		if ( ! is_string( $markup ) || false === strpos( $markup, 'wp-block-' ) ) {
			return $html;
		}

		if ( ! preg_match_all( '/\bwp-block-([a-z0-9-]+)\b/i', $markup, $matches ) ) {
			return $html;
		}

		$links = array();
		$block_names = array_unique( array_map( 'strtolower', $matches[1] ) );

		foreach ( $block_names as $block_name ) {
			if ( $this->has_core_block_style_link( $html, $block_name ) ) {
				continue;
			}

			$stylesheet = $this->core_block_stylesheet_path( $block_name );

			if ( null === $stylesheet ) {
				continue;
			}

			$href    = includes_url( 'blocks/' . $block_name . '/' . basename( $stylesheet ) );
			$href    = add_query_arg( 'ver', get_bloginfo( 'version' ), $href );
			$links[] = sprintf(
				'<link rel="stylesheet" id="%1$s" href="%2$s" media="all" />',
				esc_attr( 'wp-block-' . $block_name . '-css' ),
				esc_url( $href )
			);
		}

		if ( empty( $links ) ) {
			return $html;
		}

		$injected = implode( "\n", $links ) . "\n";

		if ( false !== stripos( $html, '</head>' ) ) {
			return preg_replace( '/<\/head>/i', $injected . '</head>', $html, 1 );
		}

		return $injected . $html;
	}

	/**
	 * Ensure exported HTML declares UTF-8 for file:// previews.
	 *
	 * @param string $html Rendered HTML.
	 * @return string HTML with a charset declaration.
	 */
	private function ensure_html_charset( $html ) {
		$html = (string) $html;

		if (
			preg_match( '#<meta\s+[^>]*charset\s*=#i', $html )
			|| preg_match( '#<meta\s+[^>]*http-equiv=["\']?content-type["\']?[^>]*>#i', $html )
		) {
			return $html;
		}

		$meta = '<meta charset="UTF-8" />' . "\n";

		if ( false !== stripos( $html, '<head' ) ) {
			return preg_replace( '#(<head\b[^>]*>)#i', '$1' . "\n" . $meta, $html, 1 );
		}

		if ( false !== stripos( $html, '<html' ) ) {
			return preg_replace( '#(<html\b[^>]*>)#i', '$1' . "\n<head>\n" . $meta . "</head>\n", $html, 1 );
		}

		return $meta . $html;
	}

	/**
	 * Determine whether a core block stylesheet is already present.
	 *
	 * @param string $html       Rendered HTML.
	 * @param string $block_name Core block name without the core/ prefix.
	 * @return bool Whether the stylesheet is already linked or inlined.
	 */
	private function has_core_block_style_link( $html, $block_name ) {
		$style_ids = array(
			'wp-block-' . $block_name . '-css',
			'wp-block-' . $block_name . '-inline-css',
		);

		foreach ( $style_ids as $style_id ) {
			if ( preg_match( '/\sid=(["\'])' . preg_quote( $style_id, '/' ) . '\1/i', $html ) ) {
				return true;
			}
		}

		return false !== stripos( $html, '/wp-includes/blocks/' . $block_name . '/style' );
	}

	/**
	 * Return the best available core block stylesheet path.
	 *
	 * @param string $block_name Core block name without the core/ prefix.
	 * @return string|null Stylesheet path, or null when none exists.
	 */
	private function core_block_stylesheet_path( $block_name ) {
		$block_name = strtolower( $block_name );

		if ( ! preg_match( '/^[a-z0-9-]+$/', $block_name ) ) {
			return null;
		}

		$base = trailingslashit( ABSPATH ) . WPINC . '/blocks/' . $block_name . '/';

		foreach ( array( 'style.min.css', 'style.css' ) as $file ) {
			$path = $base . $file;

			if ( is_readable( $path ) && is_file( $path ) ) {
				return wp_normalize_path( $path );
			}
		}

		return null;
	}

	/**
	 * Fetch a public page.
	 *
	 * @param string $url URL.
	 * @return string|WP_Error HTML or error.
	 */
	private function fetch_url( $url, array $args ) {
		$request_url = add_query_arg( 'ssgwp_export', '1', $url );

		if ( 'internal' === $args['fetch_mode'] ) {
			return $this->render_url_in_process( $request_url );
		}

		$response    = wp_remote_get(
			$request_url,
			array(
				'timeout'     => 5,
				'redirection' => 5,
				'headers'     => array(
					'X-Static-Site-Generator' => '1',
				),
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$status = (int) wp_remote_retrieve_response_code( $response );

			if ( $status >= 200 && $status < 400 ) {
				$content_type = wp_remote_retrieve_header( $response, 'content-type' );

				if ( ! $content_type || false !== stripos( $content_type, 'html' ) ) {
					return (string) wp_remote_retrieve_body( $response );
				}

				$response = new WP_Error( 'ssgwp_not_html', sprintf( 'Expected HTML, received %s', $content_type ) );
			} else {
				$response = new WP_Error( 'ssgwp_http_status', sprintf( 'HTTP %d', $status ) );
			}
		}

		$fallback = $this->render_url_in_process( $request_url );

		if ( ! is_wp_error( $fallback ) ) {
			return $fallback;
		}

		return $response;
	}

	/**
	 * Render a same-site URL inside the current PHP process.
	 *
	 * Playground CLI can deadlock or return empty loopback responses when a Blueprint
	 * runs with a small worker pool. Rendering internally keeps exports working there
	 * while retaining loopback HTTP as the first choice for regular WordPress sites.
	 *
	 * @param string $url URL to render.
	 * @return string|WP_Error Rendered HTML or error.
	 */
	private function render_url_in_process( $url ) {
		$parts      = wp_parse_url( $url );
		$home_parts = wp_parse_url( home_url( '/' ) );

		if ( empty( $parts['host'] ) || empty( $home_parts['host'] ) || strtolower( $parts['host'] ) !== strtolower( $home_parts['host'] ) ) {
			return new WP_Error( 'ssgwp_not_same_site', 'Only same-site URLs can be rendered internally.' );
		}

		$url_scheme  = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$home_scheme = isset( $home_parts['scheme'] ) ? strtolower( $home_parts['scheme'] ) : '';

		if ( $url_scheme !== $home_scheme ) {
			return new WP_Error(
				'ssgwp_not_same_site_scheme',
				'Only same-scheme URLs can be rendered internally.'
			);
		}

		if ( $this->effective_url_port( $home_parts ) !== $this->effective_url_port( $parts ) ) {
			return new WP_Error(
				'ssgwp_not_same_site_port',
				'Only same-port URLs can be rendered internally.'
			);
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '/';

		if ( ! SSGWP_Path_Utils::is_url_path_under_deployment_base( $path ) ) {
			return new WP_Error(
				'ssgwp_not_deployment_base',
				'Only URLs under the current deployment base can be rendered internally.'
			);
		}

		if ( ! class_exists( 'WP' ) || ! class_exists( 'WP_Query' ) ) {
			return new WP_Error( 'ssgwp_missing_wp', 'WordPress request classes are not available.' );
		}

		if ( ! defined( 'WP_USE_THEMES' ) ) {
			define( 'WP_USE_THEMES', true );
		}

		$single_post_render = $this->render_single_post_url_in_process( $url );

		if ( ! is_wp_error( $single_post_render ) ) {
			return $single_post_render;
		}

		$query       = isset( $parts['query'] ) ? $parts['query'] : '';
		$request_uri = $path . ( '' !== $query ? '?' . $query : '' );

		$snapshot = $this->snapshot_request_state();

		try {
			$http_host = $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );

			$_SERVER['REQUEST_URI'] = $request_uri;
			$_SERVER['HTTP_HOST']   = $http_host;
			$_SERVER['SERVER_NAME'] = $parts['host'];
			$_GET                   = array();
			$_POST                  = array();

			if ( '' !== $query ) {
				parse_str( $query, $_GET );
			}

			$_REQUEST = $_GET;

			wp_set_current_user( 0 );

			$GLOBALS['wp']           = new WP();
			$GLOBALS['wp_query']     = new WP_Query();
			$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

			$this->reset_frontend_asset_state();

			ob_start();
			wp();

			if ( is_404() ) {
				ob_end_clean();
				$this->restore_request_state( $snapshot );

				$single_post_fallback = $this->render_single_post_url_in_process( $url );

				if ( ! is_wp_error( $single_post_fallback ) ) {
					return $single_post_fallback;
				}

				return new WP_Error( 'ssgwp_internal_render_404', 'HTTP 404' );
			}

			require ABSPATH . WPINC . '/template-loader.php';
			$html = ob_get_clean();
		} catch ( Throwable $throwable ) {
			if ( ob_get_level() > $snapshot['ob_level'] ) {
				ob_end_clean();
			}

			$this->restore_request_state( $snapshot );

			return new WP_Error( 'ssgwp_internal_render_failed', $throwable->getMessage() );
		}

		$this->restore_request_state( $snapshot );

		if ( '' === trim( $html ) ) {
			return new WP_Error( 'ssgwp_internal_render_empty', 'The internal renderer returned an empty response.' );
		}

		return $html;
	}

	/**
	 * Return the effective port for a parsed URL.
	 *
	 * @param array $parts Parsed URL parts.
	 * @return int|null Effective port, or null when the scheme has no default.
	 */
	private function effective_url_port( array $parts ) {
		if ( isset( $parts['port'] ) ) {
			return (int) $parts['port'];
		}

		if ( empty( $parts['scheme'] ) ) {
			return null;
		}

		$scheme = strtolower( $parts['scheme'] );

		if ( 'https' === $scheme ) {
			return 443;
		}

		if ( 'http' === $scheme ) {
			return 80;
		}

		return null;
	}

	/**
	 * Render a single post URL directly when request parsing cannot resolve it.
	 *
	 * @param string $url URL to render.
	 * @return string|WP_Error Rendered HTML or error.
	 */
	private function render_single_post_url_in_process( $url ) {
		$post_id = $this->post_id_from_url( $url );

		if ( ! $post_id ) {
			return new WP_Error( 'ssgwp_no_single_post_match', 'No single post matched the URL.' );
		}

		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error( 'ssgwp_single_post_not_public', 'The matched post is not public.' );
		}

		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) ) {
			return new WP_Error( 'ssgwp_single_post_missing_host', 'The URL is missing a host.' );
		}

		$path        = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query       = isset( $parts['query'] ) ? $parts['query'] : '';
		$request_uri = $path . ( '' !== $query ? '?' . $query : '' );
		$snapshot    = $this->snapshot_request_state();

		try {
			$http_host = $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );

			$_SERVER['REQUEST_URI'] = $request_uri;
			$_SERVER['HTTP_HOST']   = $http_host;
			$_SERVER['SERVER_NAME'] = $parts['host'];
			$_GET                   = array();
			$_POST                  = array();

			if ( '' !== $query ) {
				parse_str( $query, $_GET );
			}

			$_REQUEST = $_GET;

			wp_set_current_user( 0 );

			$query_args = array(
				'post_type' => get_post_type( $post ),
				'p'         => $post_id,
			);

			if ( 'page' === $post->post_type ) {
				$query_args = array( 'page_id' => $post_id );
			}

			$GLOBALS['wp']           = new WP();
			$GLOBALS['wp_query']     = new WP_Query( $query_args );
			$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
			$GLOBALS['post']         = $post;

			$this->reset_frontend_asset_state();

			ob_start();
			require ABSPATH . WPINC . '/template-loader.php';
			$html = ob_get_clean();
		} catch ( Throwable $throwable ) {
			if ( ob_get_level() > $snapshot['ob_level'] ) {
				ob_end_clean();
			}

			$this->restore_request_state( $snapshot );

			return new WP_Error( 'ssgwp_single_post_render_failed', $throwable->getMessage() );
		}

		$this->restore_request_state( $snapshot );

		if ( '' === trim( $html ) ) {
			return new WP_Error( 'ssgwp_single_post_render_empty', 'The single post renderer returned an empty response.' );
		}

		return $html;
	}

	/**
	 * Reset frontend script and style registries for an internal render.
	 *
	 * Blueprint and CLI exports run inside an existing WordPress request. Reusing
	 * that request's registries can make wp_head() think frontend styles already
	 * printed, producing static HTML without loadable CSS.
	 */
	private function reset_frontend_asset_state() {
		if ( function_exists( 'wp_scripts' ) ) {
			$GLOBALS['wp_scripts'] = null;
			wp_scripts();
		}

		if ( function_exists( 'wp_styles' ) ) {
			$GLOBALS['wp_styles'] = null;
			wp_styles();
		}
	}

	/**
	 * Resolve a same-site URL to a public post ID.
	 *
	 * @param string $url URL.
	 * @return int Post ID, or 0.
	 */
	private function post_id_from_url( $url ) {
		$post_id = url_to_postid( $url );

		if ( $post_id ) {
			return (int) $post_id;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = SSGWP_Path_Utils::remove_deployment_base_path( $path );
		$path = trim( $path, '/' );

		if ( '' === $path || SSGWP_Path_Utils::has_parent_segment( $path ) ) {
			return 0;
		}

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		$post       = get_page_by_path( $path, OBJECT, $post_types );

		return $post ? (int) $post->ID : 0;
	}

	/**
	 * Snapshot request globals before internal rendering.
	 *
	 * @return array
	 */
	private function snapshot_request_state() {
		$global_names = array(
			'wp',
			'wp_query',
			'wp_the_query',
			'post',
			'id',
			'authordata',
			'currentday',
			'currentmonth',
			'page',
			'pages',
			'multipage',
			'more',
			'numpages',
			'wp_scripts',
			'wp_styles',
		);

		$globals = array();

		foreach ( $global_names as $name ) {
			$globals[ $name ] = array_key_exists( $name, $GLOBALS ) ? $GLOBALS[ $name ] : null;
		}

		return array(
			'server'          => $_SERVER,
			'get'             => $_GET,
			'post'            => $_POST,
			'request'         => $_REQUEST,
			'globals'         => $globals,
			'current_user_id' => get_current_user_id(),
			'ob_level'        => ob_get_level(),
		);
	}

	/**
	 * Restore request globals after internal rendering.
	 *
	 * @param array $snapshot Snapshot from snapshot_request_state().
	 */
	private function restore_request_state( array $snapshot ) {
		$_SERVER  = $snapshot['server'];
		$_GET     = $snapshot['get'];
		$_POST    = $snapshot['post'];
		$_REQUEST = $snapshot['request'];

		foreach ( $snapshot['globals'] as $name => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $name ] );
			} else {
				$GLOBALS[ $name ] = $value;
			}
		}

		wp_set_current_user( (int) $snapshot['current_user_id'] );
	}

	/**
	 * Convert a URL to a static file path.
	 *
	 * @param string $url URL.
	 * @return string Relative file path.
	 */
	private function url_to_file_path( $url ) {
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? $parts['query'] : '';

		return SSGWP_Path_Utils::url_to_export_file_path( $path, $query );
	}

	/**
	 * Copy frontend assets into the export directory.
	 *
	 * @param string $output_dir Output directory.
	 * @param array  $args       Export args.
	 */
	private function copy_assets( $output_dir, array $args ) {
		if ( ! empty( $args['copy_uploads'] ) ) {
			$uploads = wp_get_upload_dir();
			if ( ! empty( $uploads['basedir'] ) && is_dir( $uploads['basedir'] ) ) {
				$this->copy_path( $uploads['basedir'], trailingslashit( $output_dir ) . 'wp-content/uploads' );
			}
		}

		if ( ! empty( $args['copy_theme'] ) ) {
			$this->copy_theme_assets( $output_dir );
		}

		if ( ! empty( $args['copy_plugins'] ) ) {
			$this->copy_active_plugin_assets( $output_dir );
		}

		if ( ! empty( $args['copy_core_assets'] ) ) {
			$this->copy_core_frontend_assets( $output_dir );
		}
	}

	/**
	 * Copy same-site assets discovered in exported HTML.
	 *
	 * @param string[] $urls       Asset URLs.
	 * @param string   $output_dir Output directory.
	 * @return int Number of copied assets.
	 */
	private function copy_linked_assets( array $urls, $output_dir ) {
		$copied = 0;

		foreach ( $urls as $url ) {
			if ( $this->copy_linked_asset( $url, $output_dir ) ) {
				++$copied;
			}
		}

		return $copied;
	}

	/**
	 * Copy a same-site asset into the static export.
	 *
	 * @param string $url        Asset URL.
	 * @param string $output_dir Output directory.
	 * @return bool Whether a file was copied.
	 */
	private function copy_linked_asset( $url, $output_dir ) {
		if ( ! $this->is_same_site_asset_url( $url ) ) {
			$this->warn_linked_asset_not_copied( $url, 'not a same-site asset URL' );
			return false;
		}

		$target_path = $this->url_to_asset_path( $url );

		if ( null === $target_path ) {
			$this->warn_linked_asset_not_copied( $url, 'unsupported or unsafe target path' );
			return false;
		}

		if ( isset( $this->linked_assets_copied[ $target_path ] ) ) {
			return false;
		}

		$this->linked_assets_copied[ $target_path ] = true;
		$target = trailingslashit( $output_dir ) . $target_path;

		if ( file_exists( $target ) ) {
			return false;
		}

		$source = $this->map_url_to_local_file( $url );

		if ( null === $source ) {
			$this->warn_linked_asset_not_copied( $url, 'no matching local file was found' );
			return false;
		}

		if ( ! $this->is_exportable_asset_file( $source, $this->is_source_map_asset_url( $url ), true ) ) {
			$this->warn_linked_asset_not_copied( $url, 'the local file is not exportable' );
			return false;
		}

		$this->write_file( $target, file_get_contents( $source ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return true;
	}

	/**
	 * Determine whether a linked asset URL belongs to the current WordPress site.
	 *
	 * @param string $url Asset URL.
	 * @return bool Whether the asset URL belongs to this export.
	 */
	private function is_same_site_asset_url( $url ) {
		$url_parts  = wp_parse_url( $url );
		$home_parts = wp_parse_url( home_url( '/' ) );

		if ( empty( $url_parts['host'] ) || empty( $home_parts['host'] ) ) {
			return false;
		}

		if ( strtolower( $url_parts['host'] ) !== strtolower( $home_parts['host'] ) ) {
			return false;
		}

		$url_scheme  = isset( $url_parts['scheme'] ) ? strtolower( $url_parts['scheme'] ) : '';
		$home_scheme = isset( $home_parts['scheme'] ) ? strtolower( $home_parts['scheme'] ) : '';

		if ( $url_scheme !== $home_scheme ) {
			return false;
		}

		if ( $this->effective_url_port( $home_parts ) !== $this->effective_url_port( $url_parts ) ) {
			return false;
		}

		if ( ! SSGWP_Path_Utils::has_deployment_base_path() ) {
			return true;
		}

		$path = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';

		return SSGWP_Path_Utils::is_url_path_under_deployment_base( $path );
	}

	/**
	 * Record a warning for a referenced same-site asset that could not be copied.
	 *
	 * @param string $url    Asset URL.
	 * @param string $reason Short failure reason.
	 */
	private function warn_linked_asset_not_copied( $url, $reason ) {
		$this->warnings[] = sprintf(
			'Could not copy linked asset %1$s: %2$s.',
			$url,
			$reason
		);
	}

	/**
	 * Determine whether an asset URL points to a source map.
	 *
	 * @param string $url Asset URL.
	 * @return bool Whether the URL points to a source map file.
	 */
	private function is_source_map_asset_url( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return (bool) preg_match( '/\.map$/i', $path );
	}

	/**
	 * Convert an asset URL to a relative static path.
	 *
	 * @param string $url Asset URL.
	 * @return string|null
	 */
	private function url_to_asset_path( $url ) {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['path'] ) ) {
			return null;
		}

		$path = SSGWP_Path_Utils::map_wordpress_asset_url_path( $parts['path'] );
		$path = SSGWP_Path_Utils::remove_deployment_base_path( $path );
		$path = trim( $path, '/' );

		if ( '' === $path || SSGWP_Path_Utils::has_parent_segment( $path ) ) {
			return null;
		}

		if ( preg_match( '/\.(php|phar|phtml|sql|sqlite|log)$/i', $path ) ) {
			return null;
		}

		return SSGWP_Path_Utils::sanitize_relative_path( $path );
	}

	/**
	 * Map a same-site asset URL to a readable local file.
	 *
	 * @param string $url Asset URL.
	 * @return string|null
	 */
	private function map_url_to_local_file( $url ) {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['path'] ) ) {
			return null;
		}

		$url_path = '/' . ltrim( rawurldecode( $parts['path'] ), '/' );
		$mappings = array(
			array(
				'url' => content_url( '/' ),
				'dir' => WP_CONTENT_DIR,
			),
			array(
				'url' => includes_url( '/' ),
				'dir' => ABSPATH . WPINC,
			),
			array(
				'url' => site_url( '/' ),
				'dir' => ABSPATH,
			),
			array(
				'url' => home_url( '/' ),
				'dir' => ABSPATH,
			),
		);

		usort(
			$mappings,
			static function ( $a, $b ) {
				$a_path = (string) wp_parse_url( $a['url'], PHP_URL_PATH );
				$b_path = (string) wp_parse_url( $b['url'], PHP_URL_PATH );

				return strlen( $b_path ) <=> strlen( $a_path );
			}
		);

		foreach ( $mappings as $mapping ) {
			$base_path = '/' . trim( rawurldecode( (string) wp_parse_url( $mapping['url'], PHP_URL_PATH ) ), '/' );
			$base_path = '/' === $base_path ? '/' : trailingslashit( $base_path );

			if ( '/' !== $base_path && 0 !== strpos( trailingslashit( $url_path ), $base_path ) ) {
				continue;
			}

			$relative = '/' === $base_path ? ltrim( $url_path, '/' ) : ltrim( substr( $url_path, strlen( $base_path ) ), '/' );
			$source   = SSGWP_Path_Utils::resolve_child_file_path_preserving_requested_path( $mapping['dir'], $relative );

			if ( null !== $source ) {
				return $source;
			}
		}

		return null;
	}

	/**
	 * Determine whether a local file should be copied as a static asset.
	 *
	 * @param string $path                    File path.
	 * @param bool   $allow_source_map        Whether source maps are allowed.
	 * @param bool   $reject_symlink_segments Whether to reject symlink segments.
	 * @return bool
	 */
	private function is_exportable_asset_file( $path, $allow_source_map = false, $reject_symlink_segments = false ) {
		$name = basename( $path );

		if ( '' === $name || '.' === $name[0] ) {
			return false;
		}

		if ( $reject_symlink_segments && SSGWP_Path_Utils::path_has_symlink_segment( $path ) ) {
			return false;
		}

		if ( ! $allow_source_map && preg_match( '/\.map$/i', $name ) ) {
			return false;
		}

		if ( preg_match( '/\.(pot|po|mo|php|phar|phtml|sqlite|sql|log)$/i', $name ) ) {
			return false;
		}

		return is_readable( $path ) && is_file( $path );
	}

	/**
	 * Copy active theme files.
	 *
	 * @param string $output_dir Output directory.
	 */
	private function copy_theme_assets( $output_dir ) {
		$theme_dirs = array_unique(
			array_filter(
				array(
					get_template_directory(),
					get_stylesheet_directory(),
				)
			)
		);

		foreach ( $theme_dirs as $theme_dir ) {
			if ( ! is_dir( $theme_dir ) ) {
				continue;
			}

			$relative = ltrim( str_replace( wp_normalize_path( WP_CONTENT_DIR ), '', wp_normalize_path( $theme_dir ) ), '/' );
			$this->copy_path( $theme_dir, trailingslashit( $output_dir ) . 'wp-content/' . $relative );
		}
	}

	/**
	 * Copy active plugin files, excluding this exporter plugin.
	 *
	 * @param string $output_dir Output directory.
	 */
	private function copy_active_plugin_assets( $output_dir ) {
		$active_plugins = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active_plugins = array_merge( $active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		foreach ( array_unique( $active_plugins ) as $plugin_basename ) {
			if ( SSGWP_PLUGIN_BASENAME === $plugin_basename ) {
				continue;
			}

			$plugin_path = trailingslashit( WP_PLUGIN_DIR ) . $plugin_basename;
			$source      = is_dir( dirname( $plugin_path ) ) && '.' !== dirname( $plugin_basename ) ? dirname( $plugin_path ) : $plugin_path;

			if ( ! file_exists( $source ) ) {
				continue;
			}

			$relative = ltrim( str_replace( wp_normalize_path( WP_PLUGIN_DIR ), '', wp_normalize_path( $source ) ), '/' );
			$this->copy_path( $source, trailingslashit( $output_dir ) . 'wp-content/plugins/' . $relative );
		}
	}

	/**
	 * Copy WordPress core asset directories needed by block themes and frontend scripts.
	 *
	 * @param string $output_dir Output directory.
	 */
	private function copy_core_frontend_assets( $output_dir ) {
		$paths = array(
			ABSPATH . WPINC . '/blocks',
			ABSPATH . WPINC . '/css',
			ABSPATH . WPINC . '/js',
			ABSPATH . WPINC . '/images',
			ABSPATH . WPINC . '/fonts',
		);

		foreach ( $paths as $path ) {
			if ( ! is_dir( $path ) ) {
				continue;
			}

			$relative = ltrim( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $path ) ), '/' );
			$this->copy_path( $path, trailingslashit( $output_dir ) . $relative );
		}
	}

	/**
	 * Copy a file or directory recursively.
	 *
	 * @param string $source Source path.
	 * @param string $target Target path.
	 */
	private function copy_path( $source, $target ) {
		$source = wp_normalize_path( $source );
		$target = wp_normalize_path( $target );

		if ( is_file( $source ) ) {
			if ( ! $this->filter_copied_path( new SplFileInfo( $source ) ) ) {
				return;
			}

			$this->write_file( $target, file_get_contents( $source ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
				array( $this, 'filter_copied_path' )
			),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$relative = ltrim( str_replace( $source, '', wp_normalize_path( $item->getPathname() ) ), '/' );
			$dest     = trailingslashit( $target ) . $relative;

			if ( $item->isDir() ) {
				wp_mkdir_p( $dest );
				continue;
			}

			$this->write_file( $dest, file_get_contents( $item->getPathname() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
	}

	/**
	 * Filter paths that should not be copied into a static export.
	 *
	 * @param SplFileInfo $file File info.
	 * @return bool
	 */
	public function filter_copied_path( SplFileInfo $file ) {
		$name = $file->getFilename();

		if ( '' === $name || '.' === $name[0] ) {
			return false;
		}

		if ( $file->isLink() ) {
			return false;
		}

		if ( $file->isFile() ) {
			return $this->is_exportable_asset_file( $file->getPathname() );
		}

		if ( in_array( $name, array( 'node_modules', 'vendor', 'tests', '__tests__' ), true ) ) {
			return false;
		}

		if ( preg_match( '/\.(map|pot|po|mo|phar|php|phtml|sqlite|sql|log)$/i', $name ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Rewrite copied text assets and copy assets they discover.
	 *
	 * @param string              $output_dir Output directory.
	 * @param SSGWP_URL_Rewriter $rewriter   URL rewriter.
	 */
	private function rewrite_copied_text_assets_and_copy_dependencies( $output_dir, SSGWP_URL_Rewriter $rewriter ) {
		$max_passes = 5;

		for ( $pass = 1; $pass <= $max_passes; $pass++ ) {
			$this->report_progress(
				'rewrite_assets',
				'Rewriting URLs in copied text assets.',
				array(
					'output_dir' => $output_dir,
					'pass'       => $pass,
				)
			);

			$asset_urls = $this->rewrite_copied_text_assets( $output_dir, $rewriter );

			if ( empty( $asset_urls ) ) {
				return;
			}

			$this->report_progress(
				'copy_text_asset_dependencies',
				'Copying assets discovered in copied text assets.',
				array(
					'asset_count' => count( $asset_urls ),
					'pass'        => $pass,
				)
			);

			if ( 0 === $this->copy_linked_assets( $asset_urls, $output_dir ) ) {
				return;
			}
		}

		$this->warnings[] = sprintf(
			'Stopped text asset dependency discovery after %d passes.',
			$max_passes
		);
	}

	/**
	 * Rewrite URLs inside copied text assets.
	 *
	 * @param string              $output_dir Output directory.
	 * @param SSGWP_URL_Rewriter $rewriter   URL rewriter.
	 * @return string[] Asset URLs discovered while rewriting.
	 */
	private function rewrite_copied_text_assets( $output_dir, SSGWP_URL_Rewriter $rewriter ) {
		$asset_urls = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $output_dir, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$extension = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );

			if (
				! in_array(
					$extension,
					array( 'css', 'js', 'json', 'svg', 'html', 'webmanifest', 'xml' ),
					true
				)
			) {
				continue;
			}

			if ( $file->getSize() > 2 * MB_IN_BYTES ) {
				continue;
			}

			$path     = wp_normalize_path( $file->getPathname() );
			$relative = ltrim( str_replace( wp_normalize_path( $output_dir ), '', $path ), '/' );
			$content  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$rewritten = $rewriter->rewrite_text_asset_with_assets( $content, $relative );

			if ( isset( $this->linked_assets_copied[ $relative ] ) ) {
				foreach ( $rewritten['assets'] as $asset_url ) {
					$asset_urls[ $asset_url ] = $asset_url;
				}
			}

			$this->write_file( $path, $rewritten['content'], false );
		}

		return array_values( $asset_urls );
	}

	/**
	 * Write a file and increment count.
	 *
	 * @param string $path          Path.
	 * @param string $contents      Contents.
	 * @param bool   $increment     Whether to increment the export count.
	 * @param bool   $replace_leaf_symlink Whether to replace a final-path symlink instead of following it.
	 * @throws Exception When writing fails.
	 */
	private function write_file( $path, $contents, $increment = true, $replace_leaf_symlink = false ) {
		$path = wp_normalize_path( $path );

		if ( SSGWP_Path_Utils::has_parent_segment( $path ) || ! $this->is_inside_export_root( $path ) ) {
			throw new Exception( 'Refusing to write outside of the export directory.' );
		}

		if ( ! wp_mkdir_p( dirname( $path ) ) ) {
			throw new Exception( 'Could not create a directory while writing the static export.' );
		}

		$directory = realpath( dirname( $path ) );

		if ( false === $directory || ! $this->is_inside_export_root( $directory ) ) {
			throw new Exception( 'Refusing to write outside of the export directory.' );
		}

		if ( $replace_leaf_symlink && is_link( $path ) ) {
			if ( ! unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				throw new Exception( sprintf( 'Could not replace existing generated file symlink %s.', $path ) );
			}
		}

		if ( false === file_put_contents( $path, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			throw new Exception( sprintf( 'Could not write %s.', $path ) );
		}

		if ( $increment ) {
			++$this->files_exported;
		}
	}

	/**
	 * Determine whether a path is inside the current export root.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function is_inside_export_root( $path ) {
		if ( '' === $this->current_output_dir ) {
			return true;
		}

		return SSGWP_Path_Utils::is_path_inside_directory( $path, $this->current_output_dir );
	}

	/**
	 * Create a temporary working directory.
	 *
	 * @return string
	 * @throws Exception When directory creation fails.
	 */
	private function make_temp_dir() {
		$base = trailingslashit( get_temp_dir() ) . 'static-site-generator-' . wp_generate_uuid4();

		if ( ! wp_mkdir_p( $base ) ) {
			throw new Exception( 'Could not create a temporary export directory.' );
		}

		return wp_normalize_path( $base );
	}

	/**
	 * Zip a directory.
	 *
	 * @param string $source_dir  Source directory.
	 * @param string $output_file Output ZIP path.
	 * @throws Exception When zipping fails.
	 */
	private function zip_directory( $source_dir, $output_file ) {
		$zip = new ZipArchive();

		if ( true !== $zip->open( $output_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new Exception( 'Could not open the export zip for writing.' );
		}

		$source_dir = trailingslashit( wp_normalize_path( $source_dir ) );
		$iterator   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$path     = wp_normalize_path( $file->getPathname() );
			$relative = ltrim( str_replace( $source_dir, '', $path ), '/' );
			$zip->addFile( $path, $relative );
		}

		$zip->close();
	}

	/**
	 * Delete a directory recursively.
	 *
	 * @param string $directory Directory path.
	 */
	private function delete_directory( $directory ) {
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
}
