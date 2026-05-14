<?php
/**
 * WordPress media persistence gateway.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Creates WordPress attachments for importer media files.
 */
final class WordPressMediaGateway implements ImportMediaGatewayInterface {
	/**
	 * Whether media persistence is available in the current runtime.
	 *
	 * @return bool
	 */
	public function is_available() {
		return function_exists( 'wp_insert_attachment' )
			&& function_exists( 'wp_upload_dir' )
			&& function_exists( 'wp_unique_filename' )
			&& function_exists( 'wp_get_attachment_url' );
	}

	/**
	 * Returns a diagnostic when persistence is unavailable.
	 *
	 * @return string
	 */
	public function get_unavailable_reason() {
		return 'WordPress media APIs are not loaded; run the importer inside WordPress, WP-Cron, or WP-CLI.';
	}

	/**
	 * Finds an existing attachment by importer metadata.
	 *
	 * @param ImportSessionId $session_id    Session id.
	 * @param string          $reference_key Media reference key.
	 * @return int|null
	 */
	public function find_existing_attachment_id( ImportSessionId $session_id, $reference_key ) {
		if ( ! function_exists( 'get_posts' ) ) {
			return null;
		}

		$ids = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Importer recovery needs deterministic lookup by importer metadata.
				'meta_query'             => array(
					array(
						'key'   => '_universal_importer_session_id',
						'value' => $session_id->to_string(),
					),
					array(
						'key'   => '_universal_importer_media_reference_key',
						'value' => (string) $reference_key,
					),
				),
			)
		);

		return empty( $ids ) ? null : (int) $ids[0];
	}

	/**
	 * Imports or updates one local media file.
	 *
	 * @param ImportMediaReference $reference     Media reference.
	 * @param int|null             $attachment_id Existing attachment id.
	 * @return array{id:int,url:string,source_hash:string}
	 * @throws RuntimeException When WordPress rejects the attachment.
	 */
	public function import_local_file( ImportMediaReference $reference, $attachment_id = null ) {
		if ( ! $this->is_available() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( $this->get_unavailable_reason() );
		}

		$source_path = $reference->get_resolved_source_uri();

		if ( ! is_file( $source_path ) || ! is_readable( $source_path ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'Media source file is missing or unreadable: ' . $source_path );
		}

		$hash = hash_file( 'sha256', $source_path );

		if ( ! is_string( $hash ) || '' === $hash ) {
			throw new RuntimeException( 'Unable to hash media source file before import.' );
		}

		if ( null === $attachment_id || (int) $attachment_id < 1 ) {
			$attachment_id = $this->insert_attachment_for_file( $reference, $source_path );
		}

		$attachment_id = (int) $attachment_id;
		$this->update_import_meta( $attachment_id, $reference, $hash );

		$url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $attachment_id ) : '';

		if ( ! is_string( $url ) || '' === $url ) {
			throw new RuntimeException( 'WordPress did not return a URL for the imported attachment.' );
		}

		return array(
			'id'          => $attachment_id,
			'url'         => $url,
			'source_hash' => $hash,
		);
	}

	/**
	 * Imports or reuses one remote media URL.
	 *
	 * @param ImportMediaReference $reference     Media reference.
	 * @param int|null             $attachment_id Existing attachment id.
	 * @return array{id:int,url:string,source_hash:string}
	 * @throws RuntimeException When WordPress rejects the remote attachment.
	 */
	public function import_remote_url( ImportMediaReference $reference, $attachment_id = null ) {
		if ( ! $this->is_available() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( $this->get_unavailable_reason() );
		}

		$url = $reference->get_resolved_source_uri();

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			throw new RuntimeException( 'Remote media source must be an HTTP or HTTPS URL.' );
		}

		$download = $this->download_remote_file( $url );

		try {
			$hash = hash_file( 'sha256', $download['tmp_name'] );

			if ( ! is_string( $hash ) || '' === $hash ) {
				throw new RuntimeException( 'Unable to hash downloaded media before import.' );
			}

			if ( null === $attachment_id || (int) $attachment_id < 1 ) {
				$attachment_id        = $this->insert_attachment_for_sideload( $reference, $download['tmp_name'], $download['name'] );
				$download['tmp_name'] = '';
			}

			$attachment_id = (int) $attachment_id;
			$this->update_import_meta( $attachment_id, $reference, $hash );

			$attachment_url = wp_get_attachment_url( $attachment_id );

			if ( ! is_string( $attachment_url ) || '' === $attachment_url ) {
				throw new RuntimeException( 'WordPress did not return a URL for the imported remote attachment.' );
			}

			return array(
				'id'          => $attachment_id,
				'url'         => $attachment_url,
				'source_hash' => $hash,
			);
		} finally {
			if ( '' !== $download['tmp_name'] && is_file( $download['tmp_name'] ) ) {
				if ( function_exists( 'wp_delete_file' ) ) {
					wp_delete_file( $download['tmp_name'] );
				} else {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Fallback only when WordPress' wrapper is unavailable.
					unlink( $download['tmp_name'] );
				}
			}
		}
	}

	/**
	 * Applies staged metadata such as captions, descriptions, alt text, and source WXR metadata to an imported attachment.
	 *
	 * @param int                  $attachment_id Local attachment id.
	 * @param ImportMediaReference $reference     Source media reference.
	 * @return void
	 * @throws RuntimeException When WordPress rejects the attachment metadata update.
	 */
	public function apply_attachment_metadata( $attachment_id, ImportMediaReference $reference ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id < 1 ) {
			return;
		}

		$attachment_metadata = ImportWxrAttachment::metadata_from_reference( $reference );

		if ( empty( $attachment_metadata ) ) {
			return;
		}

		$post_update = array( 'ID' => $attachment_id );

		if ( isset( $attachment_metadata['title'] ) && '' !== trim( (string) $attachment_metadata['title'] ) ) {
			$post_update['post_title'] = trim( (string) $attachment_metadata['title'] );
		}

		if ( isset( $attachment_metadata['caption'] ) && '' !== trim( (string) $attachment_metadata['caption'] ) ) {
			$post_update['post_excerpt'] = trim( (string) $attachment_metadata['caption'] );
		}

		if ( isset( $attachment_metadata['description'] ) && '' !== trim( (string) $attachment_metadata['description'] ) ) {
			$post_update['post_content'] = trim( (string) $attachment_metadata['description'] );
		}

		if ( count( $post_update ) > 1 ) {
			if ( ! function_exists( 'wp_update_post' ) ) {
				throw new RuntimeException( 'WordPress post update APIs are not loaded; cannot apply WXR attachment captions or descriptions.' );
			}

			$result = wp_update_post( $post_update, true );

			if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
				throw new RuntimeException( 'WordPress rejected the WXR attachment metadata update: ' . $result->get_error_message() );
			}

			if ( (int) $result < 1 ) {
				throw new RuntimeException( 'WordPress returned an invalid attachment id while applying WXR attachment metadata.' );
			}
		}

		if ( ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		if ( isset( $attachment_metadata['alt_text'] ) && '' !== trim( (string) $attachment_metadata['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', trim( (string) $attachment_metadata['alt_text'] ) );
		}

		update_post_meta( $attachment_id, '_universal_importer_wxr_attachment_metadata', $attachment_metadata );

		if ( isset( $attachment_metadata['source_attached_file'] ) ) {
			update_post_meta( $attachment_id, '_universal_importer_wxr_attached_file', (string) $attachment_metadata['source_attached_file'] );
		}

		if ( isset( $attachment_metadata['source_attachment_metadata'] ) ) {
			update_post_meta( $attachment_id, '_universal_importer_wxr_attachment_source_metadata', (string) $attachment_metadata['source_attachment_metadata'] );
		}
	}

	/**
	 * Applies a remapped parent post to an imported attachment.
	 *
	 * @param int                  $attachment_id  Local attachment id.
	 * @param int                  $parent_post_id Local parent post id.
	 * @param ImportMediaReference $reference      Source media reference.
	 * @return void
	 * @throws RuntimeException When WordPress rejects the attachment update.
	 */
	public function apply_attachment_parent( $attachment_id, $parent_post_id, ImportMediaReference $reference ) {
		$attachment_id  = (int) $attachment_id;
		$parent_post_id = (int) $parent_post_id;

		if ( $attachment_id < 1 || $parent_post_id < 1 ) {
			return;
		}

		if ( ! function_exists( 'wp_update_post' ) ) {
			throw new RuntimeException( 'WordPress post update APIs are not loaded; cannot restore WXR attachment parent relationships.' );
		}

		$result = wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_parent' => $parent_post_id,
			),
			true
		);

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'WordPress rejected the attachment parent update: ' . $result->get_error_message() );
		}

		if ( (int) $result < 1 ) {
			throw new RuntimeException( 'WordPress returned an invalid attachment id while restoring the WXR attachment parent.' );
		}

		if ( function_exists( 'update_post_meta' ) ) {
			$metadata = $reference->get_metadata();
			update_post_meta( $attachment_id, '_universal_importer_wxr_parent_post_id', isset( $metadata['wxr_post_parent'] ) ? (string) $metadata['wxr_post_parent'] : '' );
			update_post_meta( $attachment_id, '_universal_importer_local_parent_post_id', (string) $parent_post_id );
		}
	}

	/**
	 * Copies a file into uploads and creates an attachment row.
	 *
	 * @param ImportMediaReference $reference  Media reference.
	 * @param string               $source_path Source file path.
	 * @return int
	 * @throws RuntimeException When upload or attachment creation fails.
	 */
	private function insert_attachment_for_file( ImportMediaReference $reference, $source_path ) {
		$upload = wp_upload_dir();

		if ( ! empty( $upload['error'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'WordPress upload directory is unavailable: ' . (string) $upload['error'] );
		}

		$upload_dir = rtrim( (string) $upload['path'], '/\\' );

		if ( ! wp_mkdir_p( $upload_dir ) ) {
			throw new RuntimeException( 'Unable to create WordPress upload directory for imported media.' );
		}

		$filename    = wp_unique_filename( $upload_dir, basename( $source_path ) );
		$target_path = $upload_dir . '/' . $filename;

		if ( ! copy( $source_path, $target_path ) ) {
			throw new RuntimeException( 'Unable to copy media source file into the WordPress uploads directory.' );
		}

		$filetype = function_exists( 'wp_check_filetype' ) ? wp_check_filetype( $target_path ) : array( 'type' => '' );
		$title    = preg_replace( '/\.[^.]+$/', '', $filename );
		$title    = is_string( $title ) && '' !== $title ? $title : $filename;

		$attachment = array(
			'post_mime_type' => isset( $filetype['type'] ) ? (string) $filetype['type'] : '',
			'post_title'     => $title,
			'post_status'    => 'inherit',
		);

		$result = wp_insert_attachment( $attachment, $target_path, 0, true );

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'WordPress rejected the imported attachment: ' . $result->get_error_message() );
		}

		$attachment_id = (int) $result;

		if ( $attachment_id < 1 ) {
			throw new RuntimeException( 'WordPress returned an invalid attachment id for imported media.' );
		}

		$this->generate_attachment_metadata( $attachment_id, $target_path );

		return $attachment_id;
	}

	/**
	 * Downloads a remote URL into a temporary file.
	 *
	 * @param string $url Remote URL.
	 * @return array{name:string,tmp_name:string}
	 * @throws RuntimeException When the download fails.
	 */
	private function download_remote_file( $url ) {
		if ( ! function_exists( 'download_url' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'download_url' ) ) {
			throw new RuntimeException( 'WordPress remote download APIs are not loaded; include wp-admin/includes/file.php before remote media import.' );
		}

		$tmp_name = download_url( $url, 300 );

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $tmp_name ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'Unable to download remote media URL: ' . $tmp_name->get_error_message() );
		}

		if ( ! is_string( $tmp_name ) || '' === $tmp_name || ! is_file( $tmp_name ) ) {
			throw new RuntimeException( 'WordPress did not return a downloaded media file.' );
		}

		return array(
			'name'     => $this->filename_from_url( $url, $tmp_name ),
			'tmp_name' => $tmp_name,
		);
	}

	/**
	 * Creates a WordPress attachment from a sideloaded temporary file.
	 *
	 * @param ImportMediaReference $reference Media reference.
	 * @param string               $tmp_name  Temporary file path.
	 * @param string               $filename  Original filename.
	 * @return int
	 * @throws RuntimeException When sideloading fails.
	 */
	private function insert_attachment_for_sideload( ImportMediaReference $reference, $tmp_name, $filename ) {
		if ( ! function_exists( 'media_handle_sideload' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			throw new RuntimeException( 'WordPress media sideload APIs are not loaded; include wp-admin/includes/media.php before remote media import.' );
		}

		$file = array(
			'name'     => $filename,
			'tmp_name' => $tmp_name,
		);

		$result = media_handle_sideload( $file, 0, null, array( 'post_status' => 'inherit' ) );

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'WordPress rejected the remote media sideload: ' . $result->get_error_message() );
		}

		$attachment_id = (int) $result;

		if ( $attachment_id < 1 ) {
			throw new RuntimeException( 'WordPress returned an invalid attachment id for remote media.' );
		}

		return $attachment_id;
	}

	/**
	 * Determines a stable filename for a remote URL.
	 *
	 * @param string $url      Remote URL.
	 * @param string $tmp_name Temporary file path.
	 * @return string
	 */
	private function filename_from_url( $url, $tmp_name ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback only when WordPress' wrapper is unavailable.
		$path     = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_PATH ) : parse_url( $url, PHP_URL_PATH );
		$filename = is_string( $path ) ? basename( $path ) : '';
		$filename = '' === $filename || '.' === $filename ? basename( $tmp_name ) : $filename;

		return function_exists( 'sanitize_file_name' ) ? sanitize_file_name( $filename ) : preg_replace( '/[^A-Za-z0-9._-]/', '-', $filename );
	}

	/**
	 * Generates attachment metadata when the image helpers are available.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $target_path   Uploaded file path.
	 * @return void
	 */
	private function generate_attachment_metadata( $attachment_id, $target_path ) {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		if ( function_exists( 'wp_generate_attachment_metadata' ) && function_exists( 'wp_update_attachment_metadata' ) ) {
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $target_path ) );
		}
	}

	/**
	 * Stores importer metadata on the attachment.
	 *
	 * @param int                  $attachment_id Attachment id.
	 * @param ImportMediaReference $reference     Media reference.
	 * @param string               $hash          Source hash.
	 * @return void
	 */
	private function update_import_meta( $attachment_id, ImportMediaReference $reference, $hash ) {
		if ( ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		update_post_meta( $attachment_id, '_universal_importer_session_id', $reference->get_session_id()->to_string() );
		update_post_meta( $attachment_id, '_universal_importer_media_reference_key', $reference->get_key() );
		update_post_meta( $attachment_id, '_universal_importer_source_item_key', $reference->get_source_item_key() );
		update_post_meta( $attachment_id, '_universal_importer_original_url', $reference->get_original_url() );
		update_post_meta( $attachment_id, '_universal_importer_source_hash', $hash );
	}
}
