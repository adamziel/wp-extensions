<?php
/**
 * WordPress comment persistence gateway.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Creates and updates imported WordPress comments.
 */
final class WordPressCommentGateway implements ImportCommentGatewayInterface {
	/**
	 * Whether comment persistence is available in the current runtime.
	 *
	 * @return bool
	 */
	public function is_available() {
		return function_exists( 'wp_insert_comment' ) && function_exists( 'wp_update_comment' );
	}

	/**
	 * Returns a diagnostic when persistence is unavailable.
	 *
	 * @return string
	 */
	public function get_unavailable_reason() {
		return 'WordPress comment APIs are not loaded; run the importer inside WordPress, WP-Cron, or WP-CLI.';
	}

	/**
	 * Finds an already-imported comment by importer metadata.
	 *
	 * @param ImportSessionId $session_id        Session id.
	 * @param string          $source_item_key   Source item key.
	 * @param int             $remote_comment_id Remote comment id.
	 * @return int|null
	 */
	public function find_existing_comment_id( ImportSessionId $session_id, $source_item_key, $remote_comment_id ) {
		if ( ! function_exists( 'get_comments' ) ) {
			return null;
		}

		$remote_comment_id = (int) $remote_comment_id;

		if ( $remote_comment_id < 1 ) {
			return null;
		}

		$ids = get_comments(
			array(
				'status'        => 'all',
				'number'        => 1,
				'fields'        => 'ids',
				'no_found_rows' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Importer recovery needs a deterministic lookup by importer metadata.
				'meta_query'    => array(
					array(
						'key'   => '_universal_importer_session_id',
						'value' => $session_id->to_string(),
					),
					array(
						'key'   => '_universal_importer_source_item_key',
						'value' => (string) $source_item_key,
					),
					array(
						'key'   => '_universal_importer_remote_comment_id',
						'value' => (string) $remote_comment_id,
					),
				),
			)
		);

		return empty( $ids ) ? null : (int) $ids[0];
	}

	/**
	 * Inserts or updates a WordPress comment from staged remote comment metadata.
	 *
	 * @param ImportPreparedDocument $document          Prepared document containing the staged comment.
	 * @param array<string,mixed>    $comment           Normalized remote comment metadata.
	 * @param int                    $post_id           Local post id.
	 * @param int                    $parent_comment_id Local parent comment id.
	 * @param int|null               $comment_id        Existing comment id to update.
	 * @return int Persisted comment id.
	 * @throws RuntimeException When WordPress rejects the comment.
	 */
	public function insert_or_update( ImportPreparedDocument $document, array $comment, $post_id, $parent_comment_id = 0, $comment_id = null ) {
		if ( ! $this->is_available() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( $this->get_unavailable_reason() );
		}

		$post_id           = (int) $post_id;
		$parent_comment_id = max( 0, (int) $parent_comment_id );

		if ( $post_id < 1 ) {
			throw new RuntimeException( 'Cannot import a comment without a valid local post id.' );
		}

		$data = array(
			'comment_post_ID'      => $post_id,
			'comment_parent'       => $parent_comment_id,
			'comment_author'       => isset( $comment['author_name'] ) ? (string) $comment['author_name'] : '',
			'comment_author_url'   => $this->sanitize_url( isset( $comment['author_url'] ) ? (string) $comment['author_url'] : '' ),
			'comment_content'      => $this->sanitize_content( isset( $comment['content'] ) ? (string) $comment['content'] : '' ),
			'comment_approved'     => $this->approval_for_status( isset( $comment['status'] ) ? (string) $comment['status'] : '' ),
			'comment_type'         => $this->comment_type( isset( $comment['type'] ) ? (string) $comment['type'] : '' ),
			'comment_author_email' => '',
			'user_id'              => 0,
		);

		if ( ! empty( $comment['date'] ) && is_string( $comment['date'] ) ) {
			$data['comment_date'] = $this->date_for_wordpress( $comment['date'] );
		}

		if ( ! empty( $comment['date_gmt'] ) && is_string( $comment['date_gmt'] ) ) {
			$data['comment_date_gmt'] = $this->date_for_wordpress( $comment['date_gmt'] );
		}

		if ( null === $comment_id ) {
			$result = wp_insert_comment( $data );
		} else {
			$data['comment_ID'] = (int) $comment_id;
			$result             = wp_update_comment( $data, true );
		}

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'WordPress rejected the imported comment: ' . $result->get_error_message() );
		}

		$result = (int) $result;

		if ( $result < 1 ) {
			throw new RuntimeException( 'WordPress returned an invalid comment id for the imported comment.' );
		}

		$this->update_import_meta( $result, $document, $comment, $post_id, $parent_comment_id );

		return $result;
	}

	/**
	 * Stores importer metadata on the created or updated comment.
	 *
	 * @param int                    $comment_id        Comment id.
	 * @param ImportPreparedDocument $document          Prepared document.
	 * @param array<string,mixed>    $comment           Remote comment metadata.
	 * @param int                    $post_id           Local post id.
	 * @param int                    $parent_comment_id Local parent comment id.
	 * @return void
	 */
	private function update_import_meta( $comment_id, ImportPreparedDocument $document, array $comment, $post_id, $parent_comment_id ) {
		if ( ! function_exists( 'update_comment_meta' ) ) {
			return;
		}

		update_comment_meta( $comment_id, '_universal_importer_session_id', $document->get_session_id()->to_string() );
		update_comment_meta( $comment_id, '_universal_importer_source_item_key', $document->get_source_item_key() );
		update_comment_meta( $comment_id, '_universal_importer_remote_comment_id', isset( $comment['remote_comment_id'] ) ? (string) (int) $comment['remote_comment_id'] : '' );
		update_comment_meta( $comment_id, '_universal_importer_remote_parent_id', isset( $comment['remote_parent_id'] ) ? (string) (int) $comment['remote_parent_id'] : '0' );
		update_comment_meta( $comment_id, '_universal_importer_local_post_id', (string) (int) $post_id );
		update_comment_meta( $comment_id, '_universal_importer_local_parent_comment_id', (string) (int) $parent_comment_id );
		update_comment_meta( $comment_id, '_universal_importer_remote_comment', $comment );
	}

	/**
	 * Sanitizes imported comment content.
	 *
	 * @param string $content Comment content.
	 * @return string
	 */
	private function sanitize_content( $content ) {
		if ( function_exists( 'wp_kses_post' ) ) {
			return wp_kses_post( $content );
		}

		return (string) $content;
	}

	/**
	 * Sanitizes an imported author URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function sanitize_url( $url ) {
		if ( function_exists( 'esc_url_raw' ) ) {
			return esc_url_raw( $url );
		}

		return trim( (string) $url );
	}

	/**
	 * Converts REST ISO-like dates into WordPress/MySQL datetime strings.
	 *
	 * @param string $date Date from REST comment payload.
	 * @return string
	 */
	private function date_for_wordpress( $date ) {
		$date = trim( (string) $date );

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $date ) ) {
			return str_replace( 'T', ' ', substr( $date, 0, 19 ) );
		}

		return $date;
	}

	/**
	 * Maps REST comment status to WordPress comment approval values.
	 *
	 * @param string $status Remote status.
	 * @return string
	 */
	private function approval_for_status( $status ) {
		$status = strtolower( trim( (string) $status ) );

		if ( 'approved' === $status || 'approve' === $status ) {
			return '1';
		}

		if ( 'spam' === $status || 'trash' === $status ) {
			return $status;
		}

		return '0';
	}

	/**
	 * Returns a safe WordPress comment type.
	 *
	 * @param string $type Remote comment type.
	 * @return string
	 */
	private function comment_type( $type ) {
		$type = strtolower( trim( (string) $type ) );

		return 'comment' === $type ? '' : $type;
	}
}
