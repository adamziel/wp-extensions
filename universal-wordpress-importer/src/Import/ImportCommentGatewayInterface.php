<?php
/**
 * WordPress comment persistence gateway contract.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Persists staged remote comments into WordPress comments.
 */
interface ImportCommentGatewayInterface {
	/**
	 * Whether comment persistence is available in the current runtime.
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Returns a diagnostic when persistence is unavailable.
	 *
	 * @return string
	 */
	public function get_unavailable_reason();

	/**
	 * Finds an already-imported comment by importer metadata.
	 *
	 * @param ImportSessionId $session_id        Session id.
	 * @param string          $source_item_key   Source item key.
	 * @param int             $remote_comment_id Remote comment id.
	 * @return int|null
	 */
	public function find_existing_comment_id( ImportSessionId $session_id, $source_item_key, $remote_comment_id );

	/**
	 * Inserts or updates a WordPress comment from staged remote comment metadata.
	 *
	 * @param ImportPreparedDocument $document          Prepared document containing the staged comment.
	 * @param array<string,mixed>    $comment           Normalized remote comment metadata.
	 * @param int                    $post_id           Local post id.
	 * @param int                    $parent_comment_id Local parent comment id.
	 * @param int|null               $comment_id        Existing comment id to update.
	 * @return int Persisted comment id.
	 */
	public function insert_or_update( ImportPreparedDocument $document, array $comment, $post_id, $parent_comment_id = 0, $comment_id = null );
}
