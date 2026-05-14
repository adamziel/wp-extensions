<?php
/**
 * WordPress media persistence gateway contract.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Imports source media into the WordPress media library.
 */
interface ImportMediaGatewayInterface {
	/**
	 * Whether media persistence is available in the current runtime.
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
	 * Finds an existing attachment by importer metadata.
	 *
	 * @param ImportSessionId $session_id    Session id.
	 * @param string          $reference_key Media reference key.
	 * @return int|null
	 */
	public function find_existing_attachment_id( ImportSessionId $session_id, $reference_key );

	/**
	 * Imports or updates one local media file.
	 *
	 * @param ImportMediaReference $reference     Media reference.
	 * @param int|null             $attachment_id Existing attachment id.
	 * @return array{id:int,url:string,source_hash:string}
	 */
	public function import_local_file( ImportMediaReference $reference, $attachment_id = null );

	/**
	 * Imports or reuses one remote media URL.
	 *
	 * @param ImportMediaReference $reference     Media reference.
	 * @param int|null             $attachment_id Existing attachment id.
	 * @return array{id:int,url:string,source_hash:string}
	 */
	public function import_remote_url( ImportMediaReference $reference, $attachment_id = null );

	/**
	 * Applies staged metadata such as captions, descriptions, alt text, and source WXR metadata to an imported attachment.
	 *
	 * @param int                  $attachment_id Local attachment id.
	 * @param ImportMediaReference $reference     Source media reference.
	 * @return void
	 */
	public function apply_attachment_metadata( $attachment_id, ImportMediaReference $reference );

	/**
	 * Applies a remapped parent post to an imported attachment.
	 *
	 * @param int                  $attachment_id  Local attachment id.
	 * @param int                  $parent_post_id Local parent post id.
	 * @param ImportMediaReference $reference      Source media reference.
	 * @return void
	 */
	public function apply_attachment_parent( $attachment_id, $parent_post_id, ImportMediaReference $reference );
}
