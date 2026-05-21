<?php
/**
 * WordPress post persistence gateway contract.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Persists prepared import documents into WordPress posts.
 */
interface ImportPostGatewayInterface {
	/**
	 * Whether post persistence is available in the current runtime.
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
	 * Finds an already-imported post by importer metadata.
	 *
	 * @param ImportSessionId $session_id      Session id.
	 * @param string          $source_item_key Source item key.
	 * @return int|null
	 */
	public function find_existing_post_id( ImportSessionId $session_id, $source_item_key );

	/**
	 * Returns the public permalink for an imported post when available.
	 *
	 * @param int $post_id Post id.
	 * @return string|null
	 */
	public function get_permalink( $post_id );

	/**
	 * Inserts or updates a WordPress post from a prepared document.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @param int|null               $post_id  Existing post id to update.
	 * @param string                 $post_status Post status to assign.
	 * @return int Persisted post id.
	 */
	public function insert_or_update( ImportPreparedDocument $document, $post_id = null, $post_status = 'publish' );

	/**
	 * Applies staged postmeta from a prepared document to an imported post.
	 *
	 * @param int                    $post_id  Post id.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return array{applied:int,skipped:int,skipped_keys:array<int,string>}
	 */
	public function apply_post_meta( $post_id, ImportPreparedDocument $document );

	/**
	 * Applies a remapped featured media attachment to an imported post.
	 *
	 * @param int                    $post_id       Post id.
	 * @param int                    $attachment_id Local attachment id.
	 * @param ImportPreparedDocument $document      Prepared document.
	 * @return void
	 */
	public function apply_featured_media( $post_id, $attachment_id, ImportPreparedDocument $document );

	/**
	 * Finds or creates a local taxonomy term for an imported source term.
	 *
	 * @param string              $taxonomy Taxonomy name.
	 * @param string              $slug     Term slug.
	 * @param string              $name     Term display name.
	 * @param array<string,mixed> $source   Source metadata.
	 * @return int|null Local term id, or null when it cannot be resolved.
	 */
	public function find_or_create_term( $taxonomy, $slug, $name, array $source = array() );

	/**
	 * Returns the public link for a local taxonomy term when available.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy name.
	 * @return string|null
	 */
	public function get_term_link( $term_id, $taxonomy );

	/**
	 * Finds or creates a WordPress navigation menu for staged WXR menu items.
	 *
	 * @param string $slug Menu slug.
	 * @param string $name Menu display name.
	 * @return int Local menu term id.
	 */
	public function ensure_navigation_menu( $slug, $name );

	/**
	 * Inserts or updates a WordPress navigation menu item.
	 *
	 * @param int                 $menu_id      Local menu term id.
	 * @param array<string,mixed> $item         Normalized menu item data.
	 * @param int|null            $menu_item_id Existing local menu item id.
	 * @return int Local menu item id.
	 */
	public function insert_or_update_navigation_menu_item( $menu_id, array $item, $menu_item_id = null );

	/**
	 * Assigns a navigation menu to a matching theme location when safe.
	 *
	 * @param int    $menu_id   Local menu term id.
	 * @param string $menu_slug Menu slug.
	 * @param string $menu_name Menu display name.
	 * @return array{status:string,location:string,message:string}
	 */
	public function assign_navigation_menu_location( $menu_id, $menu_slug, $menu_name );

	/**
	 * Applies an operator-approved relationship mapping answer to an existing post.
	 *
	 * @param int                 $post_id Post id.
	 * @param array<string,mixed> $answer  Structured relationship mapping answer.
	 * @return void
	 */
	public function apply_relationship_mapping( $post_id, array $answer );

	/**
	 * Returns diagnostics from the most recent relationship application.
	 *
	 * @return array<string,mixed>
	 */
	public function get_last_relationship_diagnostics();
}
