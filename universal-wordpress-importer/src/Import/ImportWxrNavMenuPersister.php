<?php
/**
 * WXR navigation menu persister.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Persists staged WXR nav_menu_item posts as local WordPress navigation menus.
 */
final class ImportWxrNavMenuPersister {
	const DEFAULT_SOURCE_LIMIT = 100;

	/**
	 * Durable import store.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * WordPress post/menu gateway.
	 *
	 * @var ImportPostGatewayInterface
	 */
	private $posts;

	/**
	 * Local public site URL.
	 *
	 * @var string
	 */
	private $local_site_url;

	/**
	 * Location assignment attempts already made in this persister run.
	 *
	 * @var array<string,bool>
	 */
	private $location_assignment_attempts = array();

	/**
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore     $store          Durable store.
	 * @param ImportPostGatewayInterface|null $posts          Optional post gateway.
	 * @param string|null                     $local_site_url Optional local public site URL.
	 */
	public function __construct( WordPressImportSessionStore $store, ImportPostGatewayInterface $posts = null, $local_site_url = null ) {
		$this->store          = $store;
		$this->posts          = null === $posts ? new WordPressPostGateway() : $posts;
		$this->local_site_url = $this->normalize_local_site_url( $local_site_url );
	}

	/**
	 * Advances WXR navigation menu persistence.
	 *
	 * @param ImportSession $session Session.
	 * @param int           $limit   Maximum WXR source items to inspect.
	 * @return array{applied:int,skipped:int,deferred:int,failed:int,message:string}
	 */
	public function advance( ImportSession $session, $limit = self::DEFAULT_SOURCE_LIMIT ) {
		$limit   = max( 1, min( 250, (int) $limit ) );
		$summary = array(
			'applied'  => 0,
			'skipped'  => 0,
			'deferred' => 0,
			'failed'   => 0,
			'message'  => 'No staged WXR navigation menu items were ready to persist.',
		);

		if ( ! $this->posts->is_available() ) {
			$summary['message'] = $this->posts->get_unavailable_reason();
			return $summary;
		}

		$after_item_key         = null;
		$processed_source_items = 0;

		do {
			$source_items      = $this->store->list_source_items_by_statuses_after_item_key( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), $after_item_key, $limit );
			$source_item_count = count( $source_items );

			foreach ( $source_items as $source_item ) {
				$after_item_key = $source_item->get_key();
				$metadata       = $source_item->get_metadata();

				if ( empty( $metadata['wxr_nav_menu_items_by_id'] ) || ! is_array( $metadata['wxr_nav_menu_items_by_id'] ) ) {
					continue;
				}

				$source_item_had_action = false;

				foreach ( $this->group_items_by_menu( $metadata['wxr_nav_menu_items_by_id'] ) as $menu ) {
					foreach ( $this->sort_menu_items( $menu['items'] ) as $item ) {
						$result = $this->persist_item( $session, $source_item, $menu, $item );
						++$summary[ $result ];

						if ( 'skipped' !== $result ) {
							$source_item_had_action = true;
						}
					}
				}

				if ( ! $source_item_had_action ) {
					continue;
				}

				++$processed_source_items;

				if ( $processed_source_items >= $limit ) {
					break 2;
				}
			}
		} while ( $source_item_count === $limit );

		if ( 0 < $summary['applied'] || 0 < $summary['skipped'] || 0 < $summary['deferred'] || 0 < $summary['failed'] ) {
			$summary['message'] = 'Staged WXR navigation menus were inspected.';
		}

		return $summary;
	}

	/**
	 * Persists one staged WXR menu item.
	 *
	 * @param ImportSession       $session     Session.
	 * @param ImportSourceItem    $source_item Source WXR item.
	 * @param array<string,mixed> $menu        Menu data.
	 * @param array<string,mixed> $item        Menu item data.
	 * @return string Summary bucket.
	 */
	private function persist_item( ImportSession $session, ImportSourceItem $source_item, array $menu, array $item ) {
		$remote_id = isset( $item['id'] ) ? (string) $item['id'] : '';

		if ( '' === $remote_id ) {
			return 'skipped';
		}

		$menu_slug       = isset( $menu['slug'] ) ? (string) $menu['slug'] : 'imported-menu';
		$menu_name       = isset( $menu['name'] ) ? (string) $menu['name'] : $menu_slug;
		$idempotency_key = $this->item_idempotency_key( $source_item, $remote_id );
		$target          = $this->normalize_item_target( $session, $source_item, $item );

		if ( 'deferred' === $target['status'] ) {
			$this->record_item_event( $session, ImportProgressEvent::LEVEL_WARNING, 'nav_menu_item.deferred', $target['message'], $source_item, $item, array( 'reason' => $target['reason'] ) );
			return 'deferred';
		}

		if ( 'failed' === $target['status'] ) {
			$this->record_item_event( $session, ImportProgressEvent::LEVEL_WARNING, 'nav_menu_item.skipped', $target['message'], $source_item, $item, array( 'reason' => $target['reason'] ) );
			return 'failed';
		}

		$parent_id = $this->local_parent_menu_item_id( $session, $source_item, $item );

		if ( false === $parent_id ) {
			$this->record_item_event( $session, ImportProgressEvent::LEVEL_WARNING, 'nav_menu_item.deferred', 'WXR navigation menu item is waiting for its parent menu item to be created.', $source_item, $item, array( 'reason' => 'parent_not_ready' ) );
			return 'deferred';
		}

		$normalized_item = array(
			'title'     => $this->menu_item_title( $item, $target ),
			'url'       => isset( $target['url'] ) ? $target['url'] : '',
			'status'    => $this->menu_item_status( $item ),
			'type'      => isset( $target['menu_type'] ) ? $target['menu_type'] : 'custom',
			'object'    => isset( $target['object'] ) ? $target['object'] : 'custom',
			'object_id' => isset( $target['object_id'] ) ? (int) $target['object_id'] : 0,
			'parent_id' => $parent_id,
			'target'    => $this->menu_item_meta_value( $item, '_menu_item_target' ),
			'classes'   => $this->menu_item_classes( $item ),
			'xfn'       => $this->menu_item_meta_value( $item, '_menu_item_xfn' ),
			'source'    => array(
				'wxr_post_id'         => $remote_id,
				'wxr_source_item_key' => $source_item->get_key(),
				'target_status'       => $target['status'],
				'target_type'         => $target['type'],
			),
		);
		$payload_hash    = $this->payload_hash( $menu_slug, $normalized_item );
		$record          = $this->store->find_idempotency_record( $session->get_id(), $idempotency_key );

		if ( null !== $record && $record->get_payload_hash() === $payload_hash ) {
			$menu_record = $this->store->find_idempotency_record( $session->get_id(), 'nav-menu:' . $source_item->get_key() . ':' . $menu_slug );

			if ( null !== $menu_record && (int) $menu_record->get_resource_id() > 0 ) {
				if ( ! $this->maybe_assign_menu_location( $session, $source_item, $menu_slug, $menu_name, (int) $menu_record->get_resource_id() ) ) {
					return 'deferred';
				}
			}

			return 'skipped';
		}

		$existing_item_id = null === $record ? null : (int) $record->get_resource_id();

		try {
			$menu_id           = $this->posts->ensure_navigation_menu( $menu_slug, $menu_name );
			$menu_item_id      = $this->posts->insert_or_update_navigation_menu_item( $menu_id, $normalized_item, $existing_item_id );
			$location_assigned = $this->maybe_assign_menu_location( $session, $source_item, $menu_slug, $menu_name, $menu_id );

			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord(
					$idempotency_key,
					'nav-menu-item',
					(string) $menu_item_id,
					$payload_hash
				)
			);
			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord(
					'nav-menu:' . $source_item->get_key() . ':' . $menu_slug,
					'nav-menu',
					(string) $menu_id,
					hash( 'sha256', $menu_slug . ':' . $menu_name )
				)
			);
			$this->record_item_event(
				$session,
				ImportProgressEvent::LEVEL_INFO,
				'nav_menu_item.applied',
				'WXR navigation menu item was persisted to a local WordPress menu.',
				$source_item,
				$item,
				array(
					'menu_id'      => $menu_id,
					'menu_item_id' => $menu_item_id,
					'menu_slug'    => $menu_slug,
				)
			);

			return $location_assigned ? 'applied' : 'deferred';
		} catch ( RuntimeException $exception ) {
			$this->record_item_event( $session, ImportProgressEvent::LEVEL_ERROR, 'nav_menu_item.failed', $exception->getMessage(), $source_item, $item, array( 'menu_slug' => $menu_slug ) );
			return 'failed';
		}
	}

	/**
	 * Assigns an imported WXR menu to a matching theme location once per menu payload.
	 *
	 * @param ImportSession    $session     Session.
	 * @param ImportSourceItem $source_item Source WXR item.
	 * @param string           $menu_slug   Menu slug.
	 * @param string           $menu_name   Menu display name.
	 * @param int              $menu_id     Local menu id.
	 * @return bool Whether the menu location is assigned or already assigned.
	 */
	private function maybe_assign_menu_location( ImportSession $session, ImportSourceItem $source_item, $menu_slug, $menu_name, $menu_id ) {
		$key     = 'nav-menu-location:' . $source_item->get_key() . ':' . (string) $menu_slug;
		$payload = hash( 'sha256', (string) $menu_id . ':' . (string) $menu_slug . ':' . (string) $menu_name );
		$record  = $this->store->find_idempotency_record( $session->get_id(), $key );

		if ( isset( $this->location_assignment_attempts[ $key ] ) ) {
			return false;
		}

		if ( null !== $record && $record->get_payload_hash() === $payload ) {
			return true;
		}

		$this->location_assignment_attempts[ $key ] = true;
		$result                                     = $this->posts->assign_navigation_menu_location( $menu_id, $menu_slug, $menu_name );
		$status                                     = isset( $result['status'] ) ? (string) $result['status'] : 'unavailable';
		$level                                      = 'assigned' === $status || 'already_assigned' === $status ? ImportProgressEvent::LEVEL_INFO : ImportProgressEvent::LEVEL_WARNING;

		if ( 'assigned' === $status || 'already_assigned' === $status ) {
			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord(
					$key,
					'nav-menu-location',
					(string) $menu_id,
					$payload
				)
			);
		}

		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				$level,
				'nav_menu.location_' . $status,
				isset( $result['message'] ) ? (string) $result['message'] : 'Imported WXR navigation menu location assignment was inspected.',
				array(
					'item_key'  => $source_item->get_key(),
					'menu_id'   => (int) $menu_id,
					'menu_slug' => (string) $menu_slug,
					'location'  => isset( $result['location'] ) ? (string) $result['location'] : '',
				)
			)
		);

		return 'assigned' === $status || 'already_assigned' === $status;
	}

	/**
	 * Groups staged menu items by WXR nav menu term.
	 *
	 * @param array<string,array<string,mixed>> $items_by_id Staged menu items.
	 * @return array<string,array{slug:string,name:string,items:array<int,array<string,mixed>>}>
	 */
	private function group_items_by_menu( array $items_by_id ) {
		$menus = array();

		foreach ( $items_by_id as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$slug = isset( $item['menu_slug'] ) && '' !== trim( (string) $item['menu_slug'] ) ? trim( (string) $item['menu_slug'] ) : 'imported-menu';
			$name = isset( $item['menu_name'] ) && '' !== trim( (string) $item['menu_name'] ) ? trim( (string) $item['menu_name'] ) : $slug;

			if ( ! isset( $menus[ $slug ] ) ) {
				$menus[ $slug ] = array(
					'slug'  => $slug,
					'name'  => $name,
					'items' => array(),
				);
			}

			$menus[ $slug ]['items'][] = $item;
		}

		return $menus;
	}

	/**
	 * Sorts WXR menu items in parent-friendly order.
	 *
	 * @param array<int,array<string,mixed>> $items Menu items.
	 * @return array<int,array<string,mixed>>
	 */
	private function sort_menu_items( array $items ) {
		usort(
			$items,
			function ( $left, $right ) {
				$left_parent  = isset( $left['meta']['_menu_item_menu_item_parent'] ) ? (int) $left['meta']['_menu_item_menu_item_parent'] : 0;
				$right_parent = isset( $right['meta']['_menu_item_menu_item_parent'] ) ? (int) $right['meta']['_menu_item_menu_item_parent'] : 0;

				if ( 0 === $left_parent && 0 !== $right_parent ) {
					return -1;
				}

				if ( 0 !== $left_parent && 0 === $right_parent ) {
					return 1;
				}

				$left_order  = isset( $left['menu_order'] ) ? (int) $left['menu_order'] : 0;
				$right_order = isset( $right['menu_order'] ) ? (int) $right['menu_order'] : 0;

				if ( $left_order === $right_order ) {
					return (int) $left['id'] <=> (int) $right['id'];
				}

				return $left_order <=> $right_order;
			}
		);

		return $items;
	}

	/**
	 * Normalizes one menu item target into a custom URL menu item.
	 *
	 * @param ImportSession       $session     Session.
	 * @param ImportSourceItem    $source_item Source item.
	 * @param array<string,mixed> $item        Staged menu item.
	 * @return array<string,mixed>
	 */
	private function normalize_item_target( ImportSession $session, ImportSourceItem $source_item, array $item ) {
		$type      = $this->menu_item_meta_value( $item, '_menu_item_type' );
		$object_id = $this->menu_item_meta_value( $item, '_menu_item_object_id' );
		$url       = $this->menu_item_meta_value( $item, '_menu_item_url' );

		if ( '' === $type ) {
			$type = '' === $url ? 'post_type' : 'custom';
		}

		if ( 'post_type' === $type && '' !== $object_id && (int) $object_id > 0 ) {
			$document_key = $source_item->get_key() . ':wxr-post:' . (string) $object_id;
			$record       = $this->store->find_idempotency_record( $session->get_id(), 'post:' . $document_key );

			if ( null === $record || (int) $record->get_resource_id() < 1 ) {
				return array(
					'status'  => 'deferred',
					'type'    => 'post_type',
					'reason'  => 'target_post_not_imported',
					'message' => 'WXR navigation menu item is waiting for its imported post target.',
				);
			}

			$permalink = $this->posts->get_permalink( (int) $record->get_resource_id() );

			if ( null === $permalink ) {
				return array(
					'status'  => 'deferred',
					'type'    => 'post_type',
					'reason'  => 'target_permalink_unavailable',
					'message' => 'WXR navigation menu item target was imported, but its local permalink is not available yet.',
				);
			}

			return array(
				'status'        => 'remapped_post',
				'type'          => 'post_type',
				'url'           => $permalink,
				'local_post_id' => (int) $record->get_resource_id(),
			);
		}

		if ( 'taxonomy' === $type && '' !== $object_id && (int) $object_id > 0 ) {
			return $this->normalize_taxonomy_target( $session, $source_item, $item, $object_id, $url );
		}

		if ( '' === $url ) {
			return array(
				'status'  => 'deferred',
				'type'    => $type,
				'reason'  => 'unsupported_target_without_url',
				'message' => 'WXR navigation menu item has a target type that cannot be remapped yet and no fallback URL.',
			);
		}

		if ( $this->is_executable_url( $url ) ) {
			return array(
				'status'  => 'failed',
				'type'    => $type,
				'reason'  => 'unsafe_url',
				'message' => 'WXR navigation menu item URL was skipped because executable URLs are not preserved.',
			);
		}

		return array(
			'status' => 'custom_url',
			'type'   => $type,
			'url'    => $this->rewrite_confirmed_url( $session, $url ),
		);
	}

	/**
	 * Normalizes a WXR taxonomy menu item target.
	 *
	 * @param ImportSession       $session      Session.
	 * @param ImportSourceItem    $source_item  Source item.
	 * @param array<string,mixed> $item     Staged menu item.
	 * @param string              $object_id    Remote term id.
	 * @param string              $fallback_url Source fallback URL.
	 * @return array<string,mixed>
	 */
	private function normalize_taxonomy_target( ImportSession $session, ImportSourceItem $source_item, array $item, $object_id, $fallback_url ) {
		$taxonomy = $this->menu_item_meta_value( $item, '_menu_item_object' );
		$term     = $this->source_term_for_menu_item( $source_item, $taxonomy, $object_id, $item, $fallback_url );

		if ( null === $term ) {
			if ( '' !== $fallback_url && ! $this->is_executable_url( $fallback_url ) ) {
				return array(
					'status' => 'custom_url',
					'type'   => 'taxonomy',
					'url'    => $this->rewrite_confirmed_url( $session, $fallback_url ),
				);
			}

			return array(
				'status'  => 'deferred',
				'type'    => 'taxonomy',
				'reason'  => 'target_taxonomy_term_not_staged',
				'message' => 'WXR navigation menu item is waiting for its source taxonomy term metadata.',
			);
		}

		try {
			$term_id = $this->posts->find_or_create_term(
				$term['taxonomy'],
				$term['slug'],
				$term['name'],
				array(
					'source'      => 'wxr',
					'remote_id'   => isset( $term['id'] ) ? $term['id'] : null,
					'taxonomy'    => $term['taxonomy'],
					'slug'        => $term['slug'],
					'name'        => $term['name'],
					'description' => isset( $term['description'] ) ? $term['description'] : '',
				)
			);
		} catch ( RuntimeException $exception ) {
			return array(
				'status'  => 'failed',
				'type'    => 'taxonomy',
				'reason'  => 'target_taxonomy_term_rejected',
				'message' => $exception->getMessage(),
			);
		}

		if ( null === $term_id || (int) $term_id < 1 ) {
			if ( '' !== $fallback_url && ! $this->is_executable_url( $fallback_url ) ) {
				return array(
					'status' => 'custom_url',
					'type'   => 'taxonomy',
					'url'    => $this->rewrite_confirmed_url( $session, $fallback_url ),
				);
			}

			return array(
				'status'  => 'deferred',
				'type'    => 'taxonomy',
				'reason'  => 'target_taxonomy_unavailable',
				'message' => 'WXR navigation menu item target taxonomy is not available locally.',
			);
		}

		$link = $this->posts->get_term_link( (int) $term_id, $term['taxonomy'] );

		return array(
			'status'        => 'remapped_taxonomy',
			'type'          => 'taxonomy',
			'menu_type'     => 'taxonomy',
			'object'        => $term['taxonomy'],
			'object_id'     => (int) $term_id,
			'url'           => null === $link ? '' : $link,
			'local_term_id' => (int) $term_id,
			'source_term'   => $term,
		);
	}

	/**
	 * Finds a staged WXR term for a taxonomy menu item.
	 *
	 * @param ImportSourceItem    $source_item  Source item.
	 * @param string              $taxonomy     WXR taxonomy name.
	 * @param string|int          $object_id    WXR term id.
	 * @param array<string,mixed> $item      Staged menu item.
	 * @param string              $fallback_url Source fallback URL.
	 * @return array<string,mixed>|null
	 */
	private function source_term_for_menu_item( ImportSourceItem $source_item, $taxonomy, $object_id, array $item, $fallback_url ) {
		$taxonomy  = trim( (string) $taxonomy );
		$object_id = (string) (int) $object_id;
		$metadata  = $source_item->get_metadata();

		if ( '' === $taxonomy || '0' === $object_id || empty( $metadata['wxr_terms_by_taxonomy_slug'][ $taxonomy ] ) || ! is_array( $metadata['wxr_terms_by_taxonomy_slug'][ $taxonomy ] ) ) {
			return null;
		}

		$slug_candidates = $this->term_slug_candidates_for_menu_item( $item, $fallback_url );

		foreach ( $metadata['wxr_terms_by_taxonomy_slug'][ $taxonomy ] as $term ) {
			if ( ! is_array( $term ) ) {
				continue;
			}

			$term_id = isset( $term['id'] ) ? (string) (int) $term['id'] : '';

			if ( $term_id === $object_id ) {
				return $this->normalize_source_term( $term, $taxonomy );
			}
		}

		foreach ( $metadata['wxr_terms_by_taxonomy_slug'][ $taxonomy ] as $term ) {
			if ( ! is_array( $term ) || empty( $term['slug'] ) ) {
				continue;
			}

			if ( in_array( (string) $term['slug'], $slug_candidates, true ) ) {
				return $this->normalize_source_term( $term, $taxonomy );
			}
		}

		return null;
	}

	/**
	 * Normalizes a staged WXR source term.
	 *
	 * @param array<string,mixed> $term     Raw staged term.
	 * @param string              $taxonomy Fallback taxonomy.
	 * @return array<string,mixed>
	 */
	private function normalize_source_term( array $term, $taxonomy ) {
		return array(
			'id'          => isset( $term['id'] ) ? (int) $term['id'] : null,
			'taxonomy'    => isset( $term['taxonomy'] ) ? (string) $term['taxonomy'] : (string) $taxonomy,
			'slug'        => isset( $term['slug'] ) ? (string) $term['slug'] : '',
			'name'        => isset( $term['name'] ) ? (string) $term['name'] : '',
			'description' => isset( $term['description'] ) ? (string) $term['description'] : '',
		);
	}

	/**
	 * Builds conservative source-term slug candidates from WXR menu item fields.
	 *
	 * @param array<string,mixed> $item         Staged menu item.
	 * @param string              $fallback_url Source fallback URL.
	 * @return array<int,string>
	 */
	private function term_slug_candidates_for_menu_item( array $item, $fallback_url ) {
		$candidates = array();

		if ( isset( $item['title'] ) && '' !== trim( (string) $item['title'] ) ) {
			$candidates[] = $this->sanitize_slug( $item['title'] );
		}

		if ( '' !== trim( (string) $fallback_url ) ) {
			$parts = $this->parse_url( html_entity_decode( (string) $fallback_url, ENT_QUOTES, 'UTF-8' ) );

			if ( is_array( $parts ) && ! empty( $parts['path'] ) ) {
				$path = trim( (string) $parts['path'], '/' );

				if ( '' !== $path ) {
					$segments     = explode( '/', $path );
					$candidates[] = $this->sanitize_slug( end( $segments ) );
				}
			}
		}

		return array_values( array_unique( array_filter( $candidates ) ) );
	}

	/**
	 * Sanitizes a source label into a slug.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_slug( $value ) {
		$value = trim( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) );

		if ( function_exists( 'sanitize_title' ) ) {
			return sanitize_title( $value );
		}

		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
		$value = trim( is_string( $value ) ? $value : '', '-' );

		return $value;
	}

	/**
	 * Returns a local parent menu item id, false when the parent is not ready.
	 *
	 * @param ImportSession       $session     Session.
	 * @param ImportSourceItem    $source_item Source item.
	 * @param array<string,mixed> $item        Menu item.
	 * @return int|false
	 */
	private function local_parent_menu_item_id( ImportSession $session, ImportSourceItem $source_item, array $item ) {
		$parent_remote_id = $this->menu_item_meta_value( $item, '_menu_item_menu_item_parent' );

		if ( '' === $parent_remote_id || (int) $parent_remote_id < 1 ) {
			return 0;
		}

		$record = $this->store->find_idempotency_record( $session->get_id(), $this->item_idempotency_key( $source_item, $parent_remote_id ) );

		if ( null === $record || (int) $record->get_resource_id() < 1 ) {
			return false;
		}

		return (int) $record->get_resource_id();
	}

	/**
	 * Builds a readable menu item title.
	 *
	 * @param array<string,mixed> $item   Menu item.
	 * @param array<string,mixed> $target Target.
	 * @return string
	 */
	private function menu_item_title( array $item, array $target ) {
		$title = isset( $item['title'] ) ? trim( $this->strip_scripts_and_tags( (string) $item['title'] ) ) : '';

		if ( '' !== $title ) {
			return $title;
		}

		if ( isset( $target['url'] ) && '' !== trim( (string) $target['url'] ) ) {
			return trim( (string) $target['url'] );
		}

		return 'Imported menu item';
	}

	/**
	 * Returns a menu item post status safe for local insertion.
	 *
	 * @param array<string,mixed> $item Menu item.
	 * @return string
	 */
	private function menu_item_status( array $item ) {
		$status = isset( $item['status'] ) ? trim( (string) $item['status'] ) : '';

		return in_array( $status, array( 'publish', 'draft', 'private' ), true ) ? $status : 'publish';
	}

	/**
	 * Returns one staged menu-item meta value.
	 *
	 * @param array<string,mixed> $item Menu item.
	 * @param string              $key  Meta key.
	 * @return string
	 */
	private function menu_item_meta_value( array $item, $key ) {
		return isset( $item['meta'], $item['meta'][ $key ] ) ? trim( (string) $item['meta'][ $key ] ) : '';
	}

	/**
	 * Returns normalized menu item class names.
	 *
	 * @param array<string,mixed> $item Menu item.
	 * @return array<int,string>
	 */
	private function menu_item_classes( array $item ) {
		$value = $this->menu_item_meta_value( $item, '_menu_item_classes' );

		if ( '' === $value ) {
			return array();
		}

		$classes = $this->parse_serialized_string_list( $value );

		if ( empty( $classes ) ) {
			$classes = preg_split( '/\s+/', $value );
		}

		$normalized = array();

		foreach ( is_array( $classes ) ? $classes : array() as $class ) {
			$class = trim( preg_replace( '/[^a-z0-9_-]+/i', '', (string) $class ) );

			if ( '' !== $class && ! in_array( $class, $normalized, true ) ) {
				$normalized[] = $class;
			}
		}

		return $normalized;
	}

	/**
	 * Parses the simple serialized string arrays used by WordPress menu item classes.
	 *
	 * @param string $value Serialized or plain value.
	 * @return array<int,string>
	 */
	private function parse_serialized_string_list( $value ) {
		if ( ! preg_match( '/^a:\d+:\{/', (string) $value ) ) {
			return array();
		}

		preg_match_all( '/s:\d+:"([^"]*)";/', (string) $value, $matches );

		return isset( $matches[1] ) && is_array( $matches[1] ) ? $matches[1] : array();
	}

	/**
	 * Rewrites confirmed first-party URLs to the local site URL.
	 *
	 * @param ImportSession $session Session.
	 * @param string        $url     Source URL.
	 * @return string
	 */
	private function rewrite_confirmed_url( ImportSession $session, $url ) {
		$parts = $this->parse_url( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return (string) $url;
		}

		$host = $this->normalize_domain( $parts['host'] );

		if ( ! in_array( $host, $this->confirmed_domains( $session ), true ) ) {
			return (string) $url;
		}

		$path      = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$rewritten = rtrim( $this->local_site_url, '/' ) . '/' . ltrim( $path, '/' );

		if ( isset( $parts['query'] ) && '' !== (string) $parts['query'] ) {
			$rewritten .= '?' . (string) $parts['query'];
		}

		if ( isset( $parts['fragment'] ) && '' !== (string) $parts['fragment'] ) {
			$rewritten .= '#' . (string) $parts['fragment'];
		}

		return $rewritten;
	}

	/**
	 * Returns confirmed first-party domains from the existing decision.
	 *
	 * @param ImportSession $session Session.
	 * @return array<int,string>
	 */
	private function confirmed_domains( ImportSession $session ) {
		$decision = $this->store->find_decision( $session->get_id(), ImportUrlInference::DECISION_KEY );

		if ( null === $decision || ImportDecision::STATUS_RESOLVED !== $decision->get_status() ) {
			return array();
		}

		$answer = $decision->get_answer();

		if ( ! isset( $answer['confirmed_domains'] ) || ! is_array( $answer['confirmed_domains'] ) ) {
			return array();
		}

		$domains = array();

		foreach ( $answer['confirmed_domains'] as $domain ) {
			$domain = $this->normalize_domain( $domain );

			if ( '' !== $domain && ! in_array( $domain, $domains, true ) ) {
				$domains[] = $domain;
			}
		}

		sort( $domains );

		return $domains;
	}

	/**
	 * Builds the item idempotency key.
	 *
	 * @param ImportSourceItem $source_item Source item.
	 * @param string|int       $remote_id   Remote WXR nav menu item id.
	 * @return string
	 */
	private function item_idempotency_key( ImportSourceItem $source_item, $remote_id ) {
		return 'nav-menu-item:' . $source_item->get_key() . ':' . (string) $remote_id;
	}

	/**
	 * Builds a stable payload hash.
	 *
	 * @param string              $menu_slug Menu slug.
	 * @param array<string,mixed> $item      Normalized item.
	 * @return string
	 */
	private function payload_hash( $menu_slug, array $item ) {
		return hash( 'sha256', (string) $menu_slug . "\n" . $this->encode_json( $item ) );
	}

	/**
	 * Records a menu item event.
	 *
	 * @param ImportSession       $session     Session.
	 * @param string              $level       Event level.
	 * @param string              $type        Event type.
	 * @param string              $message     Event message.
	 * @param ImportSourceItem    $source_item Source item.
	 * @param array<string,mixed> $item        Menu item.
	 * @param array<string,mixed> $context     Additional context.
	 * @return void
	 */
	private function record_item_event( ImportSession $session, $level, $type, $message, ImportSourceItem $source_item, array $item, array $context ) {
		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				$level,
				$type,
				$message,
				array_merge(
					array(
						'item_key'             => $source_item->get_key(),
						'wxr_nav_menu_item_id' => isset( $item['id'] ) ? (string) $item['id'] : '',
					),
					$context
				)
			)
		);
	}

	/**
	 * Whether a URL has an executable scheme.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_executable_url( $url ) {
		return (bool) preg_match( '/^\s*(?:javascript|vbscript|data):/i', (string) $url );
	}

	/**
	 * Removes script blocks and tags from imported text labels.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function strip_scripts_and_tags( $value ) {
		$value = preg_replace( '#<script\b[^>]*>.*?</script\s*>#is', '', (string) $value );

		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$value = wp_strip_all_tags( is_string( $value ) ? $value : '' );
		} else {
			$value = preg_replace( '/<[^>]*>/', '', is_string( $value ) ? $value : '' );
		}

		return trim( html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Parses a URL using WordPress' compatibility wrapper when available.
	 *
	 * @param string $url URL.
	 * @return array<string,mixed>|false
	 */
	private function parse_url( $url ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			return wp_parse_url( $url );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit tests run without WordPress loaded.
		return parse_url( $url );
	}

	/**
	 * Encodes data for hashing.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return string
	 */
	private function encode_json( array $data ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			return (string) wp_json_encode( $data );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit tests run without WordPress loaded.
		return (string) json_encode( $data );
	}

	/**
	 * Normalizes a domain.
	 *
	 * @param string $domain Raw domain.
	 * @return string
	 */
	private function normalize_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = preg_replace( '/:\d+$/', '', $domain );

		return is_string( $domain ) ? $domain : '';
	}

	/**
	 * Normalizes the local public site URL.
	 *
	 * @param string|null $local_site_url Optional local URL.
	 * @return string
	 */
	private function normalize_local_site_url( $local_site_url ) {
		if ( null === $local_site_url && function_exists( 'home_url' ) ) {
			$local_site_url = home_url( '/' );
		}

		$local_site_url = trim( (string) $local_site_url );

		if ( '' === $local_site_url ) {
			$local_site_url = 'http://example.org/';
		}

		return rtrim( $local_site_url, '/' ) . '/';
	}
}
