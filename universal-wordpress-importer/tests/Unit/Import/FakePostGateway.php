<?php
/**
 * Fake WordPress post gateway for import tests.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use RuntimeException;
use UniversalImporter\Import\ImportPostGatewayInterface;
use UniversalImporter\Import\ImportPreparedDocument;
use UniversalImporter\Import\ImportSessionId;
use UniversalImporter\Import\ImportWxrPostMeta;

/**
 * In-memory post gateway for prepared document persistence tests.
 */
final class FakePostGateway implements ImportPostGatewayInterface {
	/**
	 * Whether post persistence is available.
	 *
	 * @var bool
	 */
	private $available = true;

	/**
	 * Stored fake posts keyed by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $posts = array();

	/**
	 * Stored fake navigation menus keyed by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $menus = array();

	/**
	 * Fake navigation menu ids keyed by slug.
	 *
	 * @var array<string,int>
	 */
	private $menu_ids_by_slug = array();

	/**
	 * Fake registered navigation menu locations keyed by slug.
	 *
	 * @var array<string,string>
	 */
	private $registered_menu_locations = array();

	/**
	 * Fake navigation menu location assignments keyed by location slug.
	 *
	 * @var array<string,int>
	 */
	private $menu_locations = array();

	/**
	 * Next fake navigation menu id.
	 *
	 * @var int
	 */
	private $next_menu_id = 200;

	/**
	 * Next fake navigation menu item id.
	 *
	 * @var int
	 */
	private $next_menu_item_id = 300;

	/**
	 * Next fake post id.
	 *
	 * @var int
	 */
	private $next_id = 1;

	/**
	 * Source index keyed by session id and source item key.
	 *
	 * @var array<string,int>
	 */
	private $source_index = array();

	/**
	 * Optional failure message.
	 *
	 * @var string|null
	 */
	private $failure_message;

	/**
	 * Optional postmeta failure message.
	 *
	 * @var string|null
	 */
	private $postmeta_failure_message;

	/**
	 * Optional callback invoked before fake post writes.
	 *
	 * @var callable|null
	 */
	private $before_insert_callback;

	/**
	 * Optional file path for write-through persistence across child processes.
	 *
	 * @var string|null
	 */
	private $persistence_path;

	/**
	 * Local fake users keyed by remote slug.
	 *
	 * @var array<string,int>
	 */
	private $users_by_slug = array();

	/**
	 * Local fake terms keyed by taxonomy and slug.
	 *
	 * @var array<string,array<string,int>>
	 */
	private $terms_by_taxonomy = array();

	/**
	 * Existing fake taxonomies.
	 *
	 * @var array<string,bool>
	 */
	private $taxonomies = array(
		'category' => true,
		'post_tag' => true,
	);

	/**
	 * Next fake term id.
	 *
	 * @var int
	 */
	private $next_term_id = 100;

	/**
	 * Relationship diagnostics from the most recent write.
	 *
	 * @var array<string,mixed>
	 */
	private $last_relationship_diagnostics = array();

	/**
	 * Loads a fake post gateway from a persisted snapshot file.
	 *
	 * @param string $path Snapshot path.
	 * @return self
	 */
	public static function from_persisted_file( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Test-only snapshot reads a local fake gateway file.
		$contents = is_file( $path ) ? file_get_contents( $path ) : false;

		if ( false === $contents ) {
			$instance = new self();
			$instance->persist_to_file( $path );

			return $instance;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Test-only snapshot is written by this fake gateway in the same test process.
		$instance = unserialize( $contents, array( 'allowed_classes' => array( self::class ) ) );

		if ( ! $instance instanceof self ) {
			$instance = new self();
		}

		$instance->persist_to_file( $path );

		return $instance;
	}

	/**
	 * Enables write-through persistence to a snapshot file.
	 *
	 * @param string $path Snapshot path.
	 * @return void
	 */
	public function persist_to_file( $path ) {
		$this->persistence_path = (string) $path;
		$this->persist();
	}

	/**
	 * Excludes test callbacks from snapshots.
	 *
	 * @return array<int,string>
	 */
	public function __sleep() {
		return array(
			'available',
			'posts',
			'menus',
			'menu_ids_by_slug',
			'registered_menu_locations',
			'menu_locations',
			'next_menu_id',
			'next_menu_item_id',
			'next_id',
			'source_index',
			'failure_message',
			'postmeta_failure_message',
			'users_by_slug',
			'terms_by_taxonomy',
			'taxonomies',
			'next_term_id',
			'last_relationship_diagnostics',
			'persistence_path',
		);
	}

	/**
	 * Marks the gateway unavailable.
	 *
	 * @return void
	 */
	public function make_unavailable() {
		$this->available = false;
	}

	/**
	 * Makes future writes fail.
	 *
	 * @param string $message Failure message.
	 * @return void
	 */
	public function fail_writes_with( $message ) {
		$this->failure_message = (string) $message;
	}

	/**
	 * Makes future postmeta writes fail.
	 *
	 * @param string $message Failure message.
	 * @return void
	 */
	public function fail_postmeta_writes_with( $message ) {
		$this->postmeta_failure_message = (string) $message;
	}

	/**
	 * Invokes a callback before future fake post writes.
	 *
	 * @param callable $callback Callback to invoke.
	 * @return void
	 */
	public function before_insert_or_update( callable $callback ) {
		$this->before_insert_callback = $callback;
	}

	/**
	 * Adds a local fake user match.
	 *
	 * @param string $slug    User slug.
	 * @param int    $user_id User id.
	 * @return void
	 */
	public function add_user( $slug, $user_id ) {
		$this->users_by_slug[ (string) $slug ] = (int) $user_id;
	}

	/**
	 * Marks a taxonomy as missing locally.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function remove_taxonomy( $taxonomy ) {
		unset( $this->taxonomies[ (string) $taxonomy ] );
	}

	/**
	 * Registers a fake theme menu location.
	 *
	 * @param string $location Location slug.
	 * @param string $label    Display label.
	 * @return void
	 */
	public function register_menu_location( $location, $label ) {
		$this->registered_menu_locations[ (string) $location ] = (string) $label;
	}

	/**
	 * Assigns an existing fake menu location to another menu id.
	 *
	 * @param string $location Location slug.
	 * @param int    $menu_id  Menu id.
	 * @return void
	 */
	public function occupy_menu_location( $location, $menu_id ) {
		$this->menu_locations[ (string) $location ] = (int) $menu_id;
	}

	/**
	 * Whether post persistence is available in the current runtime.
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->available;
	}

	/**
	 * Returns a diagnostic when persistence is unavailable.
	 *
	 * @return string
	 */
	public function get_unavailable_reason() {
		return 'Fake post gateway is unavailable.';
	}

	/**
	 * Finds an already-imported post by importer metadata.
	 *
	 * @param ImportSessionId $session_id      Session id.
	 * @param string          $source_item_key Source item key.
	 * @return int|null
	 */
	public function find_existing_post_id( ImportSessionId $session_id, $source_item_key ) {
		$key = $this->index_key( $session_id, $source_item_key );

		return isset( $this->source_index[ $key ] ) ? $this->source_index[ $key ] : null;
	}

	/**
	 * Returns the fake public permalink for an imported post.
	 *
	 * @param int $post_id Post id.
	 * @return string|null
	 */
	public function get_permalink( $post_id ) {
		$post_id = (int) $post_id;

		return isset( $this->posts[ $post_id ] ) ? 'https://local.example.test/imported/' . $post_id . '/' : null;
	}

	/**
	 * Inserts or updates a fake post from a prepared document.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @param int|null               $post_id  Existing post id to update.
	 * @param string                 $post_status Post status to assign.
	 * @return int Persisted post id.
	 * @throws RuntimeException When configured to fail writes.
	 */
	public function insert_or_update( ImportPreparedDocument $document, $post_id = null, $post_status = 'publish' ) {
		if ( null !== $this->failure_message ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( $this->failure_message );
		}

		if ( null !== $this->before_insert_callback ) {
			call_user_func( $this->before_insert_callback, $document, $post_id );
		}

		$this->last_relationship_diagnostics = array();
		$post_status                         = 'draft' === (string) $post_status ? 'draft' : 'publish';

		if ( null === $post_id ) {
			$post_id = $this->next_id;
			++$this->next_id;
		}

		$post_id = (int) $post_id;

		$this->posts[ $post_id ] = array(
			'ID'              => $post_id,
			'post_type'       => 'page',
			'post_status'     => $post_status,
			'post_title'      => $document->get_title(),
			'post_content'    => $document->get_block_markup(),
			'source_item_key' => $document->get_source_item_key(),
			'content_hash'    => $document->get_content_hash(),
		);

		$this->apply_relationships( $post_id, $document );

		$this->source_index[ $this->index_key( $document->get_session_id(), $document->get_source_item_key() ) ] = $post_id;
		$this->persist();

		return $post_id;
	}

	/**
	 * Applies staged postmeta from a prepared document to an imported fake post.
	 *
	 * @param int                    $post_id  Post id.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return array{applied:int,skipped:int,skipped_keys:array<int,string>}
	 * @throws RuntimeException When configured to fail writes.
	 */
	public function apply_post_meta( $post_id, ImportPreparedDocument $document ) {
		if ( null !== $this->postmeta_failure_message ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( $this->postmeta_failure_message );
		}

		$post_id = (int) $post_id;

		if ( ! isset( $this->posts[ $post_id ] ) ) {
			throw new RuntimeException( 'Fake post does not exist for postmeta persistence.' );
		}

		$result = array(
			'applied'      => 0,
			'skipped'      => 0,
			'skipped_keys' => array(),
		);

		foreach ( ImportWxrPostMeta::entries_from_document( $document ) as $entry ) {
			if ( ImportWxrPostMeta::should_skip_key( $entry['key'] ) ) {
				++$result['skipped'];
				$result['skipped_keys'][] = $entry['key'];
				continue;
			}

			$this->posts[ $post_id ]['meta'][ $entry['key'] ] = ImportWxrPostMeta::maybe_unserialize_value( $entry['value'] );
			++$result['applied'];
		}

		$result['skipped_keys'] = array_values( array_unique( $result['skipped_keys'] ) );

		return $result;
	}

	/**
	 * Applies a remapped featured media attachment to an imported fake post.
	 *
	 * @param int                    $post_id       Post id.
	 * @param int                    $attachment_id Local attachment id.
	 * @param ImportPreparedDocument $document      Prepared document.
	 * @return void
	 * @throws RuntimeException When configured to fail writes.
	 */
	public function apply_featured_media( $post_id, $attachment_id, ImportPreparedDocument $document ) {
		unset( $document );

		if ( null !== $this->postmeta_failure_message ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( $this->postmeta_failure_message );
		}

		$post_id = (int) $post_id;

		if ( ! isset( $this->posts[ $post_id ] ) ) {
			throw new RuntimeException( 'Fake post does not exist for featured media persistence.' );
		}

		$this->posts[ $post_id ]['meta']['_thumbnail_id'] = (int) $attachment_id;
	}

	/**
	 * Finds or creates a fake taxonomy term.
	 *
	 * @param string              $taxonomy Taxonomy name.
	 * @param string              $slug     Term slug.
	 * @param string              $name     Term display name.
	 * @param array<string,mixed> $source   Source metadata.
	 * @return int|null Term id.
	 */
	public function find_or_create_term( $taxonomy, $slug, $name, array $source = array() ) {
		unset( $name, $source );

		$taxonomy = trim( (string) $taxonomy );
		$slug     = trim( (string) $slug );

		if ( '' === $taxonomy || '' === $slug || ! isset( $this->taxonomies[ $taxonomy ] ) ) {
			return null;
		}

		if ( ! isset( $this->terms_by_taxonomy[ $taxonomy ] ) ) {
			$this->terms_by_taxonomy[ $taxonomy ] = array();
		}

		if ( ! isset( $this->terms_by_taxonomy[ $taxonomy ][ $slug ] ) ) {
			$this->terms_by_taxonomy[ $taxonomy ][ $slug ] = $this->next_term_id;
			++$this->next_term_id;
		}

		return $this->terms_by_taxonomy[ $taxonomy ][ $slug ];
	}

	/**
	 * Returns the fake public link for a taxonomy term.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy name.
	 * @return string|null
	 */
	public function get_term_link( $term_id, $taxonomy ) {
		$term_id  = (int) $term_id;
		$taxonomy = trim( (string) $taxonomy );

		return $term_id > 0 && '' !== $taxonomy ? 'https://local.example.test/' . $taxonomy . '/' . $term_id . '/' : null;
	}

	/**
	 * Finds or creates a fake navigation menu.
	 *
	 * @param string $slug Menu slug.
	 * @param string $name Menu display name.
	 * @return int Menu id.
	 */
	public function ensure_navigation_menu( $slug, $name ) {
		$slug = trim( (string) $slug );
		$name = trim( (string) $name );

		if ( '' === $slug ) {
			$slug = 'imported-menu';
		}

		if ( isset( $this->menu_ids_by_slug[ $slug ] ) ) {
			return $this->menu_ids_by_slug[ $slug ];
		}

		$menu_id = $this->next_menu_id;
		++$this->next_menu_id;
		$this->menu_ids_by_slug[ $slug ] = $menu_id;
		$this->menus[ $menu_id ]         = array(
			'term_id' => $menu_id,
			'slug'    => $slug,
			'name'    => '' === $name ? $slug : $name,
			'items'   => array(),
		);

		return $menu_id;
	}

	/**
	 * Inserts or updates a fake navigation menu item.
	 *
	 * @param int                 $menu_id      Menu id.
	 * @param array<string,mixed> $item         Item data.
	 * @param int|null            $menu_item_id Existing item id.
	 * @return int Menu item id.
	 * @throws RuntimeException When the fake menu does not exist.
	 */
	public function insert_or_update_navigation_menu_item( $menu_id, array $item, $menu_item_id = null ) {
		$menu_id = (int) $menu_id;

		if ( ! isset( $this->menus[ $menu_id ] ) ) {
			throw new RuntimeException( 'Fake navigation menu does not exist.' );
		}

		if ( null === $menu_item_id || (int) $menu_item_id < 1 ) {
			$menu_item_id = $this->next_menu_item_id;
			++$this->next_menu_item_id;
		}

		$menu_item_id = (int) $menu_item_id;
		$item['ID']   = $menu_item_id;

		$this->menus[ $menu_id ]['items'][ $menu_item_id ] = $item;

		return $menu_item_id;
	}

	/**
	 * Assigns a fake navigation menu to a matching fake theme location when safe.
	 *
	 * @param int    $menu_id   Menu id.
	 * @param string $menu_slug Menu slug.
	 * @param string $menu_name Menu display name.
	 * @return array{status:string,location:string,message:string}
	 */
	public function assign_navigation_menu_location( $menu_id, $menu_slug, $menu_name ) {
		$menu_id = (int) $menu_id;

		if ( empty( $this->registered_menu_locations ) ) {
			return array(
				'status'   => 'no_match',
				'location' => '',
				'message'  => 'No fake theme navigation menu locations are available for the imported WXR menu.',
			);
		}

		foreach ( $this->registered_menu_locations as $location => $label ) {
			unset( $label );

			if ( isset( $this->menu_locations[ $location ] ) && $this->menu_locations[ $location ] === $menu_id ) {
				return array(
					'status'   => 'already_assigned',
					'location' => (string) $location,
					'message'  => 'Imported WXR navigation menu is already assigned to the matching fake theme location.',
				);
			}
		}

		$location = $this->matching_menu_location( $menu_slug, $menu_name );

		if ( null === $location ) {
			return array(
				'status'   => 'no_match',
				'location' => '',
				'message'  => 'No fake theme navigation menu location clearly matches the imported WXR menu.',
			);
		}

		if ( isset( $this->menu_locations[ $location ] ) && $this->menu_locations[ $location ] > 0 && $this->menu_locations[ $location ] !== $menu_id ) {
			return array(
				'status'   => 'occupied',
				'location' => (string) $location,
				'message'  => 'Matching fake theme navigation menu location already has a different menu assigned.',
			);
		}

		$this->menu_locations[ $location ] = $menu_id;

		return array(
			'status'   => 'assigned',
			'location' => (string) $location,
			'message'  => 'Imported WXR navigation menu was assigned to the matching fake theme location.',
		);
	}

	/**
	 * Applies an operator-approved relationship mapping answer to an existing fake post.
	 *
	 * @param int                 $post_id Post id.
	 * @param array<string,mixed> $answer  Structured relationship mapping answer.
	 * @return void
	 * @throws RuntimeException When the fake post does not exist or writes are configured to fail.
	 */
	public function apply_relationship_mapping( $post_id, array $answer ) {
		if ( null !== $this->failure_message ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( $this->failure_message );
		}

		$post_id = (int) $post_id;

		if ( ! isset( $this->posts[ $post_id ] ) ) {
			throw new RuntimeException( 'Fake post does not exist for relationship mapping.' );
		}

		$this->last_relationship_diagnostics = array();

		if ( isset( $answer['author'] ) && is_array( $answer['author'] ) ) {
			$user_id = isset( $answer['author']['local_user_id'] ) ? (int) $answer['author']['local_user_id'] : 0;

			if ( $user_id > 0 ) {
				$this->posts[ $post_id ]['post_author']           = $user_id;
				$this->last_relationship_diagnostics['author']    = array(
					'status'        => 'mapped',
					'local_user_id' => $user_id,
				);
				$this->users_by_slug[ 'mapped-user-' . $user_id ] = $user_id;
			} else {
				$this->last_relationship_diagnostics['author'] = array( 'status' => 'unmapped' );
			}
		}

		if ( empty( $answer['terms'] ) || ! is_array( $answer['terms'] ) ) {
			return;
		}

		foreach ( $answer['terms'] as $remote_taxonomy => $mappings ) {
			if ( ! is_array( $mappings ) ) {
				continue;
			}

			$mapped              = 0;
			$assigned_taxonomies = array();

			foreach ( $mappings as $mapping ) {
				if ( ! is_array( $mapping ) ) {
					continue;
				}

				$term_id  = isset( $mapping['local_term_id'] ) ? (int) $mapping['local_term_id'] : 0;
				$taxonomy = isset( $mapping['local_taxonomy'] ) && '' !== trim( (string) $mapping['local_taxonomy'] )
					? trim( (string) $mapping['local_taxonomy'] )
					: (string) $remote_taxonomy;

				if ( $term_id < 1 ) {
					continue;
				}

				if ( ! isset( $this->taxonomies[ $taxonomy ] ) ) {
					$this->last_relationship_diagnostics['terms'][ (string) $remote_taxonomy ] = array( 'status' => 'taxonomy_missing' );
					continue;
				}

				if ( ! isset( $this->posts[ $post_id ]['terms'][ $taxonomy ] ) ) {
					$this->posts[ $post_id ]['terms'][ $taxonomy ] = array();
				}

				$this->posts[ $post_id ]['terms'][ $taxonomy ][] = $term_id;
				$assigned_taxonomies[]                           = $taxonomy;
				++$mapped;
			}

			if ( $mapped > 0 ) {
				foreach ( array_unique( $assigned_taxonomies ) as $assigned_taxonomy ) {
					$this->posts[ $post_id ]['terms'][ $assigned_taxonomy ] = array_values( array_unique( $this->posts[ $post_id ]['terms'][ $assigned_taxonomy ] ) );
				}
				$this->last_relationship_diagnostics['terms'][ (string) $remote_taxonomy ] = array(
					'status' => 'assigned',
					'mapped' => $mapped,
				);
			} elseif ( ! isset( $this->last_relationship_diagnostics['terms'][ (string) $remote_taxonomy ] ) ) {
				$this->last_relationship_diagnostics['terms'][ (string) $remote_taxonomy ] = array( 'status' => 'unmapped' );
			}
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
	 * Returns a stored fake post.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,mixed>|null
	 */
	public function get_post( $post_id ) {
		return isset( $this->posts[ $post_id ] ) ? $this->posts[ $post_id ] : null;
	}

	/**
	 * Returns the number of fake posts.
	 *
	 * @return int
	 */
	public function count_posts() {
		return count( $this->posts );
	}

	/**
	 * Returns a stored fake navigation menu by slug.
	 *
	 * @param string $slug Menu slug.
	 * @return array<string,mixed>|null
	 */
	public function get_menu_by_slug( $slug ) {
		$slug = (string) $slug;

		if ( ! isset( $this->menu_ids_by_slug[ $slug ] ) ) {
			return null;
		}

		$menu_id = $this->menu_ids_by_slug[ $slug ];

		return isset( $this->menus[ $menu_id ] ) ? $this->menus[ $menu_id ] : null;
	}

	/**
	 * Returns fake menu-location assignments.
	 *
	 * @return array<string,int>
	 */
	public function get_menu_locations() {
		return $this->menu_locations;
	}

	/**
	 * Builds the source index key.
	 *
	 * @param ImportSessionId $session_id      Session id.
	 * @param string          $source_item_key Source item key.
	 * @return string
	 */
	private function index_key( ImportSessionId $session_id, $source_item_key ) {
		return $session_id->to_string() . ':' . (string) $source_item_key;
	}

	/**
	 * Finds a matching fake menu location.
	 *
	 * @param string $menu_slug Menu slug.
	 * @param string $menu_name Menu name.
	 * @return string|null
	 */
	private function matching_menu_location( $menu_slug, $menu_name ) {
		$candidates = array_filter(
			array(
				$this->normalize_menu_location_token( $menu_slug ),
				$this->normalize_menu_location_token( $menu_name ),
			)
		);

		foreach ( $this->registered_menu_locations as $location => $label ) {
			if ( in_array( $this->normalize_menu_location_token( $location ), $candidates, true ) || in_array( $this->normalize_menu_location_token( $label ), $candidates, true ) ) {
				return (string) $location;
			}
		}

		$common_groups = array(
			array( 'primary', 'menu-1', 'main', 'header', 'navigation', 'top' ),
			array( 'secondary', 'menu-2', 'footer', 'subsidiary', 'bottom' ),
			array( 'social' ),
		);

		foreach ( $common_groups as $group ) {
			if ( empty( array_intersect( $group, $candidates ) ) ) {
				continue;
			}

			foreach ( $this->registered_menu_locations as $location => $label ) {
				if ( ! empty( array_intersect( $group, array( $this->normalize_menu_location_token( $location ), $this->normalize_menu_location_token( $label ) ) ) ) ) {
					return (string) $location;
				}
			}
		}

		return null;
	}

	/**
	 * Normalizes fake menu locations.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function normalize_menu_location_token( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9]+/', '-', $value );

		return trim( is_string( $value ) ? $value : '', '-' );
	}

	/**
	 * Persists the gateway snapshot when write-through mode is enabled.
	 *
	 * @return void
	 */
	private function persist() {
		if ( null === $this->persistence_path ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.DiscouragedPHPFunctions -- Test-only write-through snapshot for child-process recovery tests.
		file_put_contents( $this->persistence_path, serialize( $this ) );
	}

	/**
	 * Applies staged remote relationship metadata to the fake post.
	 *
	 * @param int                    $post_id  Post id.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return void
	 */
	private function apply_relationships( $post_id, ImportPreparedDocument $document ) {
		$metadata = $document->get_metadata();

		if ( isset( $metadata['remote_author'] ) && is_array( $metadata['remote_author'] ) ) {
			$slug = isset( $metadata['remote_author']['slug'] ) ? (string) $metadata['remote_author']['slug'] : '';

			if ( isset( $this->users_by_slug[ $slug ] ) ) {
				$this->posts[ $post_id ]['post_author']                  = $this->users_by_slug[ $slug ];
				$this->last_relationship_diagnostics['author']['status'] = 'mapped';
			} else {
				$this->last_relationship_diagnostics['author']['status'] = 'unmapped';
			}
		}

		if ( empty( $metadata['remote_terms'] ) || ! is_array( $metadata['remote_terms'] ) ) {
			return;
		}

		foreach ( $metadata['remote_terms'] as $taxonomy => $terms ) {
			$taxonomy = (string) $taxonomy;

			if ( ! isset( $this->taxonomies[ $taxonomy ] ) ) {
				$this->last_relationship_diagnostics['terms'][ $taxonomy ] = array( 'status' => 'taxonomy_missing' );
				continue;
			}

			if ( ! isset( $this->terms_by_taxonomy[ $taxonomy ] ) ) {
				$this->terms_by_taxonomy[ $taxonomy ] = array();
			}

			$term_ids = array();
			$created  = 0;

			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( ! is_array( $term ) ) {
					continue;
				}

				$slug = isset( $term['slug'] ) ? (string) $term['slug'] : '';

				if ( '' === $slug ) {
					continue;
				}

				if ( ! isset( $this->terms_by_taxonomy[ $taxonomy ][ $slug ] ) ) {
					$this->terms_by_taxonomy[ $taxonomy ][ $slug ] = $this->next_term_id;
					++$this->next_term_id;
					++$created;
				}

				$term_ids[] = $this->terms_by_taxonomy[ $taxonomy ][ $slug ];
			}

			if ( empty( $term_ids ) ) {
				$this->last_relationship_diagnostics['terms'][ $taxonomy ] = array( 'status' => 'unmapped' );
				continue;
			}

			$this->posts[ $post_id ]['terms'][ $taxonomy ]             = $term_ids;
			$this->last_relationship_diagnostics['terms'][ $taxonomy ] = array(
				'status'         => 'assigned',
				'local_term_ids' => $term_ids,
				'created'        => $created,
			);
		}
	}
}
