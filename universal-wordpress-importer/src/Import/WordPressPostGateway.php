<?php
/**
 * WordPress post persistence gateway.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Creates and updates imported WordPress pages.
 */
final class WordPressPostGateway implements ImportPostGatewayInterface {
	/**
	 * Relationship diagnostics from the most recent write.
	 *
	 * @var array<string,mixed>
	 */
	private $last_relationship_diagnostics = array();

	/**
	 * Whether post persistence is available in the current runtime.
	 *
	 * @return bool
	 */
	public function is_available() {
		return function_exists( 'wp_insert_post' ) && function_exists( 'wp_update_post' );
	}

	/**
	 * Returns a diagnostic when persistence is unavailable.
	 *
	 * @return string
	 */
	public function get_unavailable_reason() {
		return 'WordPress post APIs are not loaded; run the importer inside WordPress, WP-Cron, or WP-CLI.';
	}

	/**
	 * Finds an already-imported post by importer metadata.
	 *
	 * @param ImportSessionId $session_id      Session id.
	 * @param string          $source_item_key Source item key.
	 * @return int|null
	 */
	public function find_existing_post_id( ImportSessionId $session_id, $source_item_key ) {
		if ( ! function_exists( 'get_posts' ) ) {
			return null;
		}

		$ids = get_posts(
			array(
				'post_type'              => array( 'page', 'post' ),
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Importer recovery needs a deterministic lookup by importer metadata.
				'meta_query'             => array(
					array(
						'key'   => '_universal_importer_session_id',
						'value' => $session_id->to_string(),
					),
					array(
						'key'   => '_universal_importer_source_item_key',
						'value' => (string) $source_item_key,
					),
				),
			)
		);

		return empty( $ids ) ? null : (int) $ids[0];
	}

	/**
	 * Returns the public permalink for an imported post when available.
	 *
	 * @param int $post_id Post id.
	 * @return string|null
	 */
	public function get_permalink( $post_id ) {
		$post_id = (int) $post_id;

		if ( $post_id < 1 || ! function_exists( 'get_permalink' ) ) {
			return null;
		}

		$permalink = get_permalink( $post_id );

		return false === $permalink || '' === trim( (string) $permalink ) ? null : (string) $permalink;
	}

	/**
	 * Inserts or updates a WordPress page from a prepared document.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @param int|null               $post_id  Existing post id to update.
	 * @param string                 $post_status Post status to assign.
	 * @return int Persisted post id.
	 * @throws RuntimeException When WordPress rejects the post.
	 */
	public function insert_or_update( ImportPreparedDocument $document, $post_id = null, $post_status = 'publish' ) {
		if ( ! $this->is_available() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( $this->get_unavailable_reason() );
		}

		$this->last_relationship_diagnostics = array();
		$post_status                         = 'draft' === (string) $post_status ? 'draft' : 'publish';

		$post = array(
			'post_type'    => $this->post_type_for_document( $document ),
			'post_status'  => $post_status,
			'post_title'   => $document->get_title(),
			'post_content' => $document->get_block_markup(),
			'post_name'    => $this->slug_for_document( $document ),
		);

		$post_date = $this->post_date_for_document( $document );
		if ( '' !== $post_date ) {
			$post['post_date'] = $post_date;
		}

		$menu_order = $this->menu_order_for_document( $document );
		if ( null !== $menu_order ) {
			$post['menu_order'] = $menu_order;
		}

		$author_id = $this->resolve_remote_author_id( $document );

		if ( null !== $author_id ) {
			$post['post_author'] = $author_id;
		}

		if ( null === $post_id ) {
			$result = wp_insert_post( $post, true );
		} else {
			$post['ID'] = (int) $post_id;
			$result     = wp_update_post( $post, true );
		}

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'WordPress rejected the imported post: ' . $result->get_error_message() );
		}

		$result = (int) $result;

		if ( $result < 1 ) {
			throw new RuntimeException( 'WordPress returned an invalid post id for the imported document.' );
		}

		$this->update_import_meta( $result, $document );
		$this->apply_remote_terms( $result, $document );

		return $result;
	}

	/**
	 * Applies staged postmeta from a prepared document to an imported post.
	 *
	 * @param int                    $post_id  Post id.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return array{applied:int,skipped:int,skipped_keys:array<int,string>}
	 */
	public function apply_post_meta( $post_id, ImportPreparedDocument $document ) {
		$post_id = (int) $post_id;
		$result  = array(
			'applied'      => 0,
			'skipped'      => 0,
			'skipped_keys' => array(),
		);

		if ( $post_id < 1 || ! function_exists( 'update_post_meta' ) ) {
			return $result;
		}

		foreach ( ImportWxrPostMeta::entries_from_document( $document ) as $entry ) {
			if ( ImportWxrPostMeta::should_skip_key( $entry['key'] ) ) {
				++$result['skipped'];
				$result['skipped_keys'][] = $entry['key'];
				continue;
			}

			update_post_meta( $post_id, $entry['key'], ImportWxrPostMeta::maybe_unserialize_value( $entry['value'] ) );
			++$result['applied'];
		}

		$result['skipped_keys'] = array_values( array_unique( $result['skipped_keys'] ) );

		return $result;
	}

	/**
	 * Applies a remapped featured media attachment to an imported post.
	 *
	 * @param int                    $post_id       Post id.
	 * @param int                    $attachment_id Local attachment id.
	 * @param ImportPreparedDocument $document      Prepared document.
	 * @return void
	 */
	public function apply_featured_media( $post_id, $attachment_id, ImportPreparedDocument $document ) {
		unset( $document );

		$post_id       = (int) $post_id;
		$attachment_id = (int) $attachment_id;

		if ( $post_id < 1 || $attachment_id < 1 ) {
			return;
		}

		if ( function_exists( 'set_post_thumbnail' ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
			return;
		}

		if ( function_exists( 'update_post_meta' ) ) {
			update_post_meta( $post_id, '_thumbnail_id', $attachment_id );
		}
	}

	/**
	 * Finds or creates a local taxonomy term for an imported source term.
	 *
	 * @param string              $taxonomy Taxonomy name.
	 * @param string              $slug     Term slug.
	 * @param string              $name     Term display name.
	 * @param array<string,mixed> $source   Source metadata.
	 * @return int|null Local term id, or null when it cannot be resolved.
	 * @throws RuntimeException When WordPress rejects the term.
	 */
	public function find_or_create_term( $taxonomy, $slug, $name, array $source = array() ) {
		$taxonomy = trim( (string) $taxonomy );
		$slug     = trim( (string) $slug );
		$name     = trim( (string) $name );

		if ( '' === $taxonomy || '' === $slug ) {
			return null;
		}

		if ( function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}

		if ( function_exists( 'get_term_by' ) ) {
			$existing = get_term_by( 'slug', $slug, $taxonomy );

			if ( is_object( $existing ) && isset( $existing->term_id ) && (int) $existing->term_id > 0 ) {
				return (int) $existing->term_id;
			}
		}

		if ( ! function_exists( 'wp_insert_term' ) ) {
			return null;
		}

		if ( '' === $name ) {
			$name = $slug;
		}

		$args = array(
			'slug' => $slug,
		);

		if ( isset( $source['description'] ) && '' !== trim( (string) $source['description'] ) ) {
			$args['description'] = (string) $source['description'];
		}

		$result = wp_insert_term( $name, $taxonomy, $args );

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			$existing_id = $result->get_error_data( 'term_exists' );

			if ( (int) $existing_id > 0 ) {
				return (int) $existing_id;
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'WordPress rejected the imported taxonomy term "' . $slug . '": ' . $result->get_error_message() );
		}

		if ( ! is_array( $result ) || empty( $result['term_id'] ) || (int) $result['term_id'] < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'WordPress returned an invalid term id for the imported taxonomy term "' . $slug . '".' );
		}

		$term_id = (int) $result['term_id'];

		if ( function_exists( 'update_term_meta' ) ) {
			update_term_meta( $term_id, '_universal_importer_source_term', $source );
		}

		return $term_id;
	}

	/**
	 * Returns the public link for a local taxonomy term when available.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy name.
	 * @return string|null
	 */
	public function get_term_link( $term_id, $taxonomy ) {
		$term_id  = (int) $term_id;
		$taxonomy = trim( (string) $taxonomy );

		if ( $term_id < 1 || '' === $taxonomy || ! function_exists( 'get_term_link' ) ) {
			return null;
		}

		$link = get_term_link( $term_id, $taxonomy );

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $link ) ) {
			return null;
		}

		return false === $link || '' === trim( (string) $link ) ? null : (string) $link;
	}

	/**
	 * Finds or creates a WordPress navigation menu.
	 *
	 * @param string $slug Menu slug.
	 * @param string $name Menu display name.
	 * @return int Local menu term id.
	 * @throws RuntimeException When WordPress menu APIs are unavailable or reject the menu.
	 */
	public function ensure_navigation_menu( $slug, $name ) {
		if ( ! function_exists( 'wp_get_nav_menu_object' ) || ! function_exists( 'wp_create_nav_menu' ) ) {
			throw new RuntimeException( 'WordPress navigation menu APIs are not loaded; cannot persist WXR menus.' );
		}

		$slug = trim( (string) $slug );
		$name = trim( (string) $name );

		if ( '' === $name ) {
			$name = '' === $slug ? 'Imported Menu' : $slug;
		}

		$menu = '' === $slug ? false : wp_get_nav_menu_object( $slug );

		if ( false === $menu || null === $menu ) {
			$menu = wp_get_nav_menu_object( $name );
		}

		if ( is_object( $menu ) && isset( $menu->term_id ) && (int) $menu->term_id > 0 ) {
			return (int) $menu->term_id;
		}

		$result = wp_create_nav_menu( $name );

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'WordPress rejected the imported navigation menu: ' . $result->get_error_message() );
		}

		$result = (int) $result;

		if ( $result < 1 ) {
			throw new RuntimeException( 'WordPress returned an invalid navigation menu id.' );
		}

		return $result;
	}

	/**
	 * Inserts or updates a WordPress navigation menu item.
	 *
	 * @param int                 $menu_id      Local menu term id.
	 * @param array<string,mixed> $item         Normalized menu item data.
	 * @param int|null            $menu_item_id Existing local menu item id.
	 * @return int Local menu item id.
	 * @throws RuntimeException When WordPress rejects the menu item.
	 */
	public function insert_or_update_navigation_menu_item( $menu_id, array $item, $menu_item_id = null ) {
		if ( ! function_exists( 'wp_update_nav_menu_item' ) ) {
			throw new RuntimeException( 'WordPress navigation menu item APIs are not loaded; cannot persist WXR menu items.' );
		}

		$menu_id      = (int) $menu_id;
		$menu_item_id = null === $menu_item_id ? 0 : (int) $menu_item_id;
		$args         = array(
			'menu-item-title'     => isset( $item['title'] ) ? (string) $item['title'] : 'Imported menu item',
			'menu-item-url'       => isset( $item['url'] ) ? (string) $item['url'] : '',
			'menu-item-status'    => isset( $item['status'] ) ? (string) $item['status'] : 'publish',
			'menu-item-type'      => isset( $item['type'] ) ? (string) $item['type'] : 'custom',
			'menu-item-object'    => isset( $item['object'] ) ? (string) $item['object'] : 'custom',
			'menu-item-object-id' => isset( $item['object_id'] ) ? (int) $item['object_id'] : 0,
			'menu-item-parent-id' => isset( $item['parent_id'] ) ? (int) $item['parent_id'] : 0,
			'menu-item-target'    => isset( $item['target'] ) ? (string) $item['target'] : '',
			'menu-item-classes'   => isset( $item['classes'] ) && is_array( $item['classes'] ) ? $item['classes'] : array(),
			'menu-item-xfn'       => isset( $item['xfn'] ) ? (string) $item['xfn'] : '',
		);

		$result = wp_update_nav_menu_item( $menu_id, $menu_item_id, $args );

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'WordPress rejected the imported navigation menu item: ' . $result->get_error_message() );
		}

		$result = (int) $result;

		if ( $result < 1 ) {
			throw new RuntimeException( 'WordPress returned an invalid navigation menu item id.' );
		}

		if ( function_exists( 'update_post_meta' ) ) {
			$source = isset( $item['source'] ) && is_array( $item['source'] ) ? $item['source'] : array();
			update_post_meta( $result, '_universal_importer_wxr_nav_menu_item', $source );
		}

		return $result;
	}

	/**
	 * Assigns a navigation menu to a matching theme location when safe.
	 *
	 * @param int    $menu_id   Local menu term id.
	 * @param string $menu_slug Menu slug.
	 * @param string $menu_name Menu display name.
	 * @return array{status:string,location:string,message:string}
	 */
	public function assign_navigation_menu_location( $menu_id, $menu_slug, $menu_name ) {
		$menu_id   = (int) $menu_id;
		$menu_slug = trim( (string) $menu_slug );
		$menu_name = trim( (string) $menu_name );

		if ( $menu_id < 1 ) {
			return array(
				'status'   => 'unavailable',
				'location' => '',
				'message'  => 'Cannot assign a WXR navigation menu to a theme location without a valid local menu id.',
			);
		}

		if ( ! function_exists( 'get_registered_nav_menus' ) || ! function_exists( 'get_nav_menu_locations' ) || ! function_exists( 'set_theme_mod' ) ) {
			return array(
				'status'   => 'unavailable',
				'location' => '',
				'message'  => 'WordPress navigation menu location APIs are not loaded; cannot assign the imported menu to a theme location.',
			);
		}

		$registered = get_registered_nav_menus();

		if ( empty( $registered ) || ! is_array( $registered ) ) {
			return array(
				'status'   => 'no_match',
				'location' => '',
				'message'  => 'No registered theme navigation menu locations are available for the imported WXR menu.',
			);
		}

		$assigned = get_nav_menu_locations();

		if ( ! is_array( $assigned ) ) {
			$assigned = array();
		}

		foreach ( $registered as $location => $label ) {
			unset( $label );

			if ( isset( $assigned[ $location ] ) && (int) $assigned[ $location ] === $menu_id ) {
				return array(
					'status'   => 'already_assigned',
					'location' => (string) $location,
					'message'  => 'Imported WXR navigation menu is already assigned to the matching theme location.',
				);
			}
		}

		$location = $this->matching_navigation_menu_location( $registered, $assigned, $menu_id, $menu_slug, $menu_name );

		if ( null === $location ) {
			return array(
				'status'   => 'no_match',
				'location' => '',
				'message'  => 'No registered theme navigation menu location clearly matches the imported WXR menu.',
			);
		}

		if ( isset( $assigned[ $location ] ) && (int) $assigned[ $location ] > 0 && (int) $assigned[ $location ] !== $menu_id ) {
			return array(
				'status'   => 'occupied',
				'location' => (string) $location,
				'message'  => 'Matching theme navigation menu location already has a different menu assigned; the importer left it unchanged.',
			);
		}

		$assigned[ $location ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $assigned );

		return array(
			'status'   => 'assigned',
			'location' => (string) $location,
			'message'  => 'Imported WXR navigation menu was assigned to the matching theme location.',
		);
	}

	/**
	 * Applies an operator-approved relationship mapping answer to an existing post.
	 *
	 * @param int                 $post_id Post id.
	 * @param array<string,mixed> $answer  Structured relationship mapping answer.
	 * @return void
	 * @throws RuntimeException When WordPress rejects the mapping.
	 */
	public function apply_relationship_mapping( $post_id, array $answer ) {
		if ( ! $this->is_available() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( $this->get_unavailable_reason() );
		}

		$post_id = (int) $post_id;

		if ( $post_id < 1 ) {
			throw new RuntimeException( 'Cannot apply relationship mapping without a valid imported post id.' );
		}

		$this->last_relationship_diagnostics = array();
		$this->apply_mapped_author( $post_id, $answer );
		$this->apply_mapped_terms( $post_id, $answer );

		if ( function_exists( 'update_post_meta' ) ) {
			update_post_meta( $post_id, '_universal_importer_relationship_mapping_answer', $answer );
		}
	}

	/**
	 * Returns diagnostics from the most recent relationship application.
	 *
	 * @return array<string,mixed>
	 */
	public function get_last_relationship_diagnostics() {
		return $this->last_relationship_diagnostics;
	}

	/**
	 * Builds a stable slug from the source path and title.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return string
	 */
	private function slug_for_document( ImportPreparedDocument $document ) {
		$metadata = $document->get_metadata();

		if ( isset( $metadata['wp_post_name'] ) && '' !== trim( (string) $metadata['wp_post_name'] ) ) {
			return trim( (string) $metadata['wp_post_name'] );
		}

		$seed = isset( $metadata['relative_path'] ) && '' !== trim( (string) $metadata['relative_path'] )
			? (string) $metadata['relative_path']
			: $document->get_title();

		if ( function_exists( 'sanitize_title' ) ) {
			return sanitize_title( $seed );
		}

		$slug = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $seed ) );
		$slug = trim( (string) $slug, '-' );

		return '' === $slug ? 'imported-document' : $slug;
	}

	/**
	 * Returns the WordPress post type for a prepared document.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return string
	 */
	private function post_type_for_document( ImportPreparedDocument $document ) {
		$metadata  = $document->get_metadata();
		$post_type = isset( $metadata['wp_post_type'] ) ? trim( (string) $metadata['wp_post_type'] ) : '';

		return 'post' === $post_type ? 'post' : 'page';
	}

	/**
	 * Returns a safe local post date for a prepared document.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return string
	 */
	private function post_date_for_document( ImportPreparedDocument $document ) {
		$metadata  = $document->get_metadata();
		$post_date = isset( $metadata['wp_post_date'] ) ? trim( (string) $metadata['wp_post_date'] ) : '';

		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $post_date ) ? $post_date : '';
	}

	/**
	 * Returns an optional menu order for a prepared document.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return int|null
	 */
	private function menu_order_for_document( ImportPreparedDocument $document ) {
		$metadata = $document->get_metadata();

		if ( ! isset( $metadata['menu_order'] ) || ! is_numeric( $metadata['menu_order'] ) ) {
			return null;
		}

		return (int) $metadata['menu_order'];
	}

	/**
	 * Finds a safe registered location for an imported WXR menu.
	 *
	 * @param array<string,string> $registered Registered theme locations.
	 * @param array<string,int>    $assigned   Current location assignments.
	 * @param int                  $menu_id    Local menu id.
	 * @param string               $menu_slug  Imported menu slug.
	 * @param string               $menu_name  Imported menu display name.
	 * @return string|null
	 */
	private function matching_navigation_menu_location( array $registered, array $assigned, $menu_id, $menu_slug, $menu_name ) {
		$candidates = array_filter(
			array(
				$this->normalize_menu_location_token( $menu_slug ),
				$this->normalize_menu_location_token( $menu_name ),
			)
		);

		foreach ( $registered as $location => $label ) {
			$normalized_location = $this->normalize_menu_location_token( $location );
			$normalized_label    = $this->normalize_menu_location_token( $label );

			if ( in_array( $normalized_location, $candidates, true ) || in_array( $normalized_label, $candidates, true ) ) {
				return (string) $location;
			}
		}

		if ( function_exists( 'wp_map_nav_menu_locations' ) ) {
			$old_location = '' === trim( (string) $menu_slug ) ? trim( (string) $menu_name ) : trim( (string) $menu_slug );
			$mapped       = wp_map_nav_menu_locations( $assigned, array( $old_location => (int) $menu_id ) );

			if ( is_array( $mapped ) ) {
				foreach ( $registered as $location => $label ) {
					unset( $label );

					if ( isset( $mapped[ $location ] ) && (int) $mapped[ $location ] === (int) $menu_id ) {
						return (string) $location;
					}
				}
			}
		}

		return $this->matching_common_navigation_menu_location( $registered, $menu_slug, $menu_name );
	}

	/**
	 * Falls back to WordPress core's common location groups when mapping helper is unavailable.
	 *
	 * @param array<string,string> $registered Registered theme locations.
	 * @param string               $menu_slug  Imported menu slug.
	 * @param string               $menu_name  Imported menu display name.
	 * @return string|null
	 */
	private function matching_common_navigation_menu_location( array $registered, $menu_slug, $menu_name ) {
		$source_tokens = array_filter(
			array(
				$this->normalize_menu_location_token( $menu_slug ),
				$this->normalize_menu_location_token( $menu_name ),
			)
		);

		$common_groups = array(
			array( 'primary', 'menu-1', 'main', 'header', 'navigation', 'top' ),
			array( 'secondary', 'menu-2', 'footer', 'subsidiary', 'bottom' ),
			array( 'social' ),
		);

		foreach ( $common_groups as $group ) {
			if ( empty( array_intersect( $group, $source_tokens ) ) ) {
				continue;
			}

			foreach ( $registered as $location => $label ) {
				$target_tokens = array(
					$this->normalize_menu_location_token( $location ),
					$this->normalize_menu_location_token( $label ),
				);

				if ( ! empty( array_intersect( $group, $target_tokens ) ) ) {
					return (string) $location;
				}
			}
		}

		return null;
	}

	/**
	 * Normalizes menu labels and locations for conservative matching.
	 *
	 * @param string $value Raw token.
	 * @return string
	 */
	private function normalize_menu_location_token( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9]+/', '-', $value );

		return trim( is_string( $value ) ? $value : '', '-' );
	}

	/**
	 * Stores importer metadata on the created or updated post.
	 *
	 * @param int                    $post_id  Post id.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return void
	 */
	private function update_import_meta( $post_id, ImportPreparedDocument $document ) {
		if ( ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		$metadata = $document->get_metadata();

		update_post_meta( $post_id, '_universal_importer_session_id', $document->get_session_id()->to_string() );
		update_post_meta( $post_id, '_universal_importer_source_item_key', $document->get_source_item_key() );
		update_post_meta( $post_id, '_universal_importer_content_hash', $document->get_content_hash() );
		update_post_meta( $post_id, '_universal_importer_document_format', $document->get_format() );

		if ( isset( $metadata['remote_author'] ) && is_array( $metadata['remote_author'] ) ) {
			update_post_meta( $post_id, '_universal_importer_remote_author', $metadata['remote_author'] );
		}

		if ( isset( $metadata['remote_terms'] ) && is_array( $metadata['remote_terms'] ) ) {
			update_post_meta( $post_id, '_universal_importer_remote_terms', $metadata['remote_terms'] );
		}

		if ( isset( $metadata['markdown_docs_profile'] ) && '' !== trim( (string) $metadata['markdown_docs_profile'] ) ) {
			update_post_meta( $post_id, '_universal_importer_source_profile', trim( (string) $metadata['markdown_docs_profile'] ) );
		}
	}

	/**
	 * Resolves staged remote author metadata to an existing local user.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return int|null
	 */
	private function resolve_remote_author_id( ImportPreparedDocument $document ) {
		$metadata = $document->get_metadata();

		if ( empty( $metadata['remote_author'] ) || ! is_array( $metadata['remote_author'] ) ) {
			return null;
		}

		$author = $metadata['remote_author'];
		$slug   = isset( $author['slug'] ) ? trim( (string) $author['slug'] ) : '';
		$name   = isset( $author['name'] ) ? trim( (string) $author['name'] ) : '';

		$user = null;

		if ( '' !== $slug && function_exists( 'get_user_by' ) ) {
			$user = get_user_by( 'slug', $slug );

			if ( false === $user ) {
				$user = get_user_by( 'login', $slug );
			}
		}

		if ( false === $user && '' !== $name && function_exists( 'get_users' ) ) {
			$matches = get_users(
				array(
					'search'         => $name,
					'search_columns' => array( 'display_name', 'user_nicename', 'user_login' ),
					'number'         => 1,
					'fields'         => array( 'ID' ),
				)
			);
			$user    = empty( $matches ) ? false : $matches[0];
		}

		if ( $user && isset( $user->ID ) && (int) $user->ID > 0 ) {
			$this->last_relationship_diagnostics['author'] = array(
				'status'           => 'mapped',
				'local_user_id'    => (int) $user->ID,
				'remote_author_id' => isset( $author['id'] ) ? (int) $author['id'] : null,
				'remote_slug'      => $slug,
			);

			return (int) $user->ID;
		}

		$this->last_relationship_diagnostics['author'] = array(
			'status'           => 'unmapped',
			'remote_author_id' => isset( $author['id'] ) ? (int) $author['id'] : null,
			'remote_name'      => $name,
			'remote_slug'      => $slug,
			'message'          => 'No matching local user was found; the draft keeps the current WordPress author and stores remote author metadata for operator mapping.',
		);

		return null;
	}

	/**
	 * Creates and assigns staged remote taxonomy terms where local taxonomies exist.
	 *
	 * @param int                    $post_id  Post id.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return void
	 * @throws RuntimeException When WordPress rejects imported terms.
	 */
	private function apply_remote_terms( $post_id, ImportPreparedDocument $document ) {
		$metadata = $document->get_metadata();

		if ( empty( $metadata['remote_terms'] ) || ! is_array( $metadata['remote_terms'] ) ) {
			return;
		}

		$diagnostics = array();

		foreach ( $metadata['remote_terms'] as $taxonomy => $terms ) {
			$taxonomy = trim( (string) $taxonomy );

			if ( '' === $taxonomy || ! is_array( $terms ) ) {
				continue;
			}

			if ( function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( $taxonomy ) ) {
				$diagnostics[ $taxonomy ] = array(
					'status'  => 'taxonomy_missing',
					'message' => 'Local taxonomy does not exist; remote terms were left as importer metadata for operator mapping.',
				);
				continue;
			}

			$term_ids = array();
			$created  = 0;
			$mapped   = 0;

			foreach ( $terms as $term ) {
				if ( ! is_array( $term ) ) {
					continue;
				}

				$term_id = $this->resolve_remote_term_id( $taxonomy, $term, $created );

				if ( null !== $term_id ) {
					$term_ids[] = $term_id;
					++$mapped;
				}
			}

			$term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );

			if ( empty( $term_ids ) ) {
				$diagnostics[ $taxonomy ] = array(
					'status'  => 'unmapped',
					'message' => 'No local terms could be matched or created; remote terms were left as importer metadata for operator mapping.',
				);
				continue;
			}

			if ( function_exists( 'wp_set_object_terms' ) ) {
				$result = wp_set_object_terms( (int) $post_id, $term_ids, $taxonomy, false );

				if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
					throw new RuntimeException( 'WordPress rejected imported terms for taxonomy "' . $taxonomy . '": ' . $result->get_error_message() );
				}
			}

			$diagnostics[ $taxonomy ] = array(
				'status'         => 'assigned',
				'local_term_ids' => $term_ids,
				'mapped'         => $mapped,
				'created'        => $created,
			);
		}

		if ( ! empty( $diagnostics ) ) {
			$this->last_relationship_diagnostics['terms'] = $diagnostics;
		}
	}

	/**
	 * Resolves one staged remote term to a local term id.
	 *
	 * @param string              $taxonomy Taxonomy.
	 * @param array<string,mixed> $term     Remote term metadata.
	 * @param int                 $created  Created counter.
	 * @return int|null
	 * @throws RuntimeException When WordPress rejects an inserted term.
	 */
	private function resolve_remote_term_id( $taxonomy, array $term, &$created ) {
		$name = isset( $term['name'] ) ? trim( (string) $term['name'] ) : '';
		$slug = isset( $term['slug'] ) ? trim( (string) $term['slug'] ) : '';

		if ( '' === $name && '' === $slug ) {
			return null;
		}

		$existing = null;

		if ( '' !== $slug && function_exists( 'term_exists' ) ) {
			$existing = term_exists( $slug, $taxonomy );
		}

		if ( ( null === $existing || 0 === $existing ) && '' !== $name && function_exists( 'term_exists' ) ) {
			$existing = term_exists( $name, $taxonomy );
		}

		if ( is_array( $existing ) && isset( $existing['term_id'] ) ) {
			return (int) $existing['term_id'];
		}

		if ( is_int( $existing ) && $existing > 0 ) {
			return $existing;
		}

		if ( ! function_exists( 'wp_insert_term' ) || '' === $name ) {
			return null;
		}

		$args = array();

		if ( '' !== $slug ) {
			$args['slug'] = $slug;
		}

		$result = wp_insert_term( $name, $taxonomy, $args );

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'WordPress rejected imported term "' . $name . '" for taxonomy "' . $taxonomy . '": ' . $result->get_error_message() );
		}

		if ( is_array( $result ) && isset( $result['term_id'] ) && (int) $result['term_id'] > 0 ) {
			++$created;
			return (int) $result['term_id'];
		}

		return null;
	}

	/**
	 * Applies an explicit local author mapping from a resolved decision.
	 *
	 * @param int                 $post_id Post id.
	 * @param array<string,mixed> $answer  Structured decision answer.
	 * @return void
	 * @throws RuntimeException When WordPress rejects the author update.
	 */
	private function apply_mapped_author( $post_id, array $answer ) {
		if ( empty( $answer['author'] ) || ! is_array( $answer['author'] ) ) {
			return;
		}

		$user_id = isset( $answer['author']['local_user_id'] ) ? (int) $answer['author']['local_user_id'] : 0;

		if ( $user_id < 1 ) {
			$this->last_relationship_diagnostics['author'] = array(
				'status'  => 'unmapped',
				'message' => 'No local user id was provided in the resolved relationship mapping answer.',
			);
			return;
		}

		if ( function_exists( 'get_user_by' ) && false === get_user_by( 'id', $user_id ) ) {
			$this->last_relationship_diagnostics['author'] = array(
				'status'        => 'local_user_missing',
				'local_user_id' => $user_id,
				'message'       => 'The resolved relationship mapping references a local user that does not exist.',
			);
			return;
		}

		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_author' => $user_id,
			),
			true
		);

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'WordPress rejected the mapped post author: ' . $result->get_error_message() );
		}

		$this->last_relationship_diagnostics['author'] = array(
			'status'        => 'mapped',
			'local_user_id' => $user_id,
		);
	}

	/**
	 * Applies explicit local term mappings from a resolved decision.
	 *
	 * @param int                 $post_id Post id.
	 * @param array<string,mixed> $answer  Structured decision answer.
	 * @return void
	 * @throws RuntimeException When WordPress rejects term assignment.
	 */
	private function apply_mapped_terms( $post_id, array $answer ) {
		if ( empty( $answer['terms'] ) || ! is_array( $answer['terms'] ) ) {
			return;
		}

		$assignments = array();
		$diagnostics = array();

		foreach ( $answer['terms'] as $remote_taxonomy => $mappings ) {
			if ( ! is_array( $mappings ) ) {
				continue;
			}

			$mapped = 0;

			foreach ( $mappings as $mapping ) {
				if ( ! is_array( $mapping ) ) {
					continue;
				}

				$term_id  = isset( $mapping['local_term_id'] ) ? (int) $mapping['local_term_id'] : 0;
				$taxonomy = isset( $mapping['local_taxonomy'] ) && '' !== trim( (string) $mapping['local_taxonomy'] )
					? trim( (string) $mapping['local_taxonomy'] )
					: trim( (string) $remote_taxonomy );

				if ( $term_id < 1 || '' === $taxonomy ) {
					continue;
				}

				if ( function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( $taxonomy ) ) {
					$diagnostics[ (string) $remote_taxonomy ] = array(
						'status'         => 'taxonomy_missing',
						'local_taxonomy' => $taxonomy,
						'message'        => 'The resolved relationship mapping references a local taxonomy that does not exist.',
					);
					continue;
				}

				if ( ! isset( $assignments[ $taxonomy ] ) ) {
					$assignments[ $taxonomy ] = array();
				}

				$assignments[ $taxonomy ][] = $term_id;
				++$mapped;
			}

			if ( 0 === $mapped && ! isset( $diagnostics[ (string) $remote_taxonomy ] ) ) {
				$diagnostics[ (string) $remote_taxonomy ] = array(
					'status'  => 'unmapped',
					'message' => 'No local term ids were provided in the resolved relationship mapping answer.',
				);
			} elseif ( $mapped > 0 && ! isset( $diagnostics[ (string) $remote_taxonomy ] ) ) {
				$diagnostics[ (string) $remote_taxonomy ] = array(
					'status' => 'assigned',
					'mapped' => $mapped,
				);
			}
		}

		foreach ( $assignments as $taxonomy => $term_ids ) {
			$term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );

			if ( function_exists( 'wp_set_object_terms' ) ) {
				$result = wp_set_object_terms( (int) $post_id, $term_ids, $taxonomy, false );

				if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
					throw new RuntimeException( 'WordPress rejected mapped terms for taxonomy "' . $taxonomy . '": ' . $result->get_error_message() );
				}
			}
		}

		if ( ! empty( $diagnostics ) ) {
			$this->last_relationship_diagnostics['terms'] = $diagnostics;
		}
	}
}
