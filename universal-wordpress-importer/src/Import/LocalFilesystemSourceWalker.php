<?php
/**
 * Local filesystem source walker.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Discovers local files and directories incrementally into the source queue.
 */
final class LocalFilesystemSourceWalker {
	const DEFAULT_ITEM_LIMIT = 25;

	/**
	 * Durable store.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore $store Durable store.
	 */
	public function __construct( WordPressImportSessionStore $store ) {
		$this->store = $store;
	}

	/**
	 * Advances local source discovery for a session.
	 *
	 * @param ImportSession $session Session to advance.
	 * @param int           $limit   Maximum queued items to inspect.
	 * @return array{discovered:int,queued:int,failed:int,complete:bool,message:string}
	 */
	public function advance( ImportSession $session, $limit = self::DEFAULT_ITEM_LIMIT ) {
		$limit   = max( 1, min( 250, (int) $limit ) );
		$summary = array(
			'discovered' => 0,
			'queued'     => 0,
			'failed'     => 0,
			'complete'   => false,
			'message'    => '',
		);

		$is_local_source = $this->is_local_source( $session->get_source() );
		$root_path       = $is_local_source ? $this->normalize_local_path( $session->get_source() ) : '';

		if ( $is_local_source ) {
			if ( ! file_exists( $root_path ) ) {
				$this->store->save_source_item(
					ImportSourceItem::queued(
						$session->get_id(),
						$this->item_key_for_path( $root_path ),
						null,
						$root_path,
						'',
						ImportSourceItem::TYPE_FILE
					)->with_status( ImportSourceItem::STATUS_FAILED )->with_metadata( array( 'error' => 'Source path does not exist.' ) )
				);
				$summary['failed']   = 1;
				$summary['complete'] = true;
				$summary['message']  = 'Source path does not exist.';
				return $summary;
			}

			$this->ensure_root_item( $session, $root_path );
		}

		foreach ( $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_QUEUED, ImportSourceItem::STATUS_PROCESSING ), $limit ) as $item ) {
			if ( ! $this->is_local_source( $item->get_source_uri() ) ) {
				continue;
			}

			$item_summary           = $this->process_item( $session, $root_path, $item, $limit );
			$summary['discovered'] += $item_summary['discovered'];
			$summary['queued']     += $item_summary['queued'];
			$summary['failed']     += $item_summary['failed'];
		}

		$handled_work        = $is_local_source || 0 < $summary['discovered'] || 0 < $summary['queued'] || 0 < $summary['failed'];
		$summary['complete'] = $handled_work && 0 === $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_QUEUED, ImportSourceItem::STATUS_PROCESSING ) );
		$summary['message']  = $handled_work
			? ( $summary['complete'] ? 'Local source discovery is complete.' : 'Local source discovery has more queued work.' )
			: 'Source traversal is not available for this source type yet.';

		return $summary;
	}

	/**
	 * Ensures the traversal root exists in the durable queue.
	 *
	 * @param ImportSession $session   Session.
	 * @param string        $root_path Root path.
	 * @return void
	 */
	private function ensure_root_item( ImportSession $session, $root_path ) {
		$key = $this->item_key_for_path( $root_path );

		if ( null !== $this->store->find_source_item( $session->get_id(), $key ) ) {
			return;
		}

		$type = is_dir( $root_path ) ? ImportSourceItem::TYPE_DIRECTORY : ImportSourceItem::TYPE_FILE;

		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				$key,
				null,
				$root_path,
				'',
				$type,
				$this->metadata_for_path( $root_path )
			)
		);
	}

	/**
	 * Processes one queued item.
	 *
	 * @param ImportSession    $session   Session.
	 * @param string           $root_path Root path.
	 * @param ImportSourceItem $item      Queued item.
	 * @param int              $limit     Maximum directory children to inspect.
	 * @return array{discovered:int,queued:int,failed:int}
	 */
	private function process_item( ImportSession $session, $root_path, ImportSourceItem $item, $limit ) {
		$summary = array(
			'discovered' => 0,
			'queued'     => 0,
			'failed'     => 0,
		);

		$this->store->save_source_item( $item->with_status( ImportSourceItem::STATUS_PROCESSING ) );

		try {
			if ( ImportSourceItem::TYPE_FILE === $item->get_type() ) {
				$this->store->save_source_item( $item->with_status( ImportSourceItem::STATUS_DISCOVERED )->with_metadata( $this->metadata_for_path( $item->get_source_uri() ) ) );
				++$summary['discovered'];
				return $summary;
			}

			$item = $item->with_status( ImportSourceItem::STATUS_PROCESSING )->with_metadata(
				array_merge(
					$this->metadata_for_path( $item->get_source_uri() ),
					array( 'directory_status' => 'scanning' )
				)
			);
			$this->store->save_source_item( $item );
			$scan_result        = $this->queue_directory_children( $session, $root_path, $item, $limit );
			$summary['queued'] += $scan_result['queued'];
			$item               = $scan_result['item'];

			if ( ! $scan_result['complete'] ) {
				return $summary;
			}

			$this->store->save_source_item(
				$item->with_status( ImportSourceItem::STATUS_SKIPPED )->with_metadata(
					array_merge(
						$this->metadata_for_path( $item->get_source_uri() ),
						array(
							'directory_status' => 'complete',
							'skip_reason'      => 'Directory container discovered; child items were queued separately.',
						)
					)
				)
			);
			++$summary['discovered'];
		} catch ( RuntimeException $exception ) {
			$this->store->save_source_item( $item->with_status( ImportSourceItem::STATUS_FAILED )->with_metadata( array( 'error' => $exception->getMessage() ) ) );
			++$summary['failed'];
		}

		return $summary;
	}

	/**
	 * Queues a bounded page of directory children.
	 *
	 * @param ImportSession    $session   Session.
	 * @param string           $root_path Root path.
	 * @param ImportSourceItem $item      Directory item.
	 * @param int              $limit     Maximum children to inspect.
	 * @return array{queued:int,complete:bool,item:ImportSourceItem}
	 * @throws RuntimeException When a directory cannot be read.
	 */
	private function queue_directory_children( ImportSession $session, $root_path, ImportSourceItem $item, $limit ) {
		$result = $this->queue_directory_children_after_cursor( $session, $root_path, $item, $limit, $this->directory_cursor( $item ) );

		if ( ! $result['cursor_missing'] ) {
			return array(
				'queued'   => $result['queued'],
				'complete' => $result['complete'],
				'item'     => $result['item'],
			);
		}

		$result = $this->queue_directory_children_after_cursor( $session, $root_path, $item, $limit, '' );

		return array(
			'queued'   => $result['queued'],
			'complete' => $result['complete'],
			'item'     => $result['item'],
		);
	}

	/**
	 * Queues children after a known cursor.
	 *
	 * @param ImportSession    $session Session.
	 * @param string           $root_path Root path.
	 * @param ImportSourceItem $item    Directory item.
	 * @param int              $limit   Maximum children to inspect.
	 * @param string           $cursor  Last inspected child basename.
	 * @return array{queued:int,complete:bool,cursor_missing:bool,item:ImportSourceItem}
	 * @throws RuntimeException When a directory cannot be read.
	 */
	private function queue_directory_children_after_cursor( ImportSession $session, $root_path, ImportSourceItem $item, $limit, $cursor ) {
		$cursor = (string) $cursor;
		$page   = $this->directory_child_page_after_cursor( $item->get_source_uri(), $cursor, $limit );

		if ( ! $page['cursor_found'] ) {
			return array(
				'queued'         => 0,
				'complete'       => false,
				'cursor_missing' => true,
				'item'           => $item,
			);
		}

		$queued       = 0;
		$current_item = $item;

		foreach ( $page['entries'] as $entry_name => $child_path ) {
			$child_key = $this->item_key_for_path( $child_path );

			if ( null === $this->store->find_source_item( $session->get_id(), $child_key ) ) {
				$this->store->save_source_item(
					ImportSourceItem::queued(
						$session->get_id(),
						$child_key,
						$item->get_key(),
						$child_path,
						$this->relative_path( $root_path, $child_path ),
						is_dir( $child_path ) ? ImportSourceItem::TYPE_DIRECTORY : ImportSourceItem::TYPE_FILE,
						$this->metadata_for_path( $child_path )
					)
				);
				++$queued;
			}

			$current_item = $this->save_directory_cursor( $current_item, $entry_name );
		}

		return array(
			'queued'         => $queued,
			'complete'       => count( $page['entries'] ) < $limit,
			'cursor_missing' => false,
			'item'           => $current_item,
		);
	}

	/**
	 * Returns a bounded stable filename page after a basename cursor.
	 *
	 * @param string $path   Directory path.
	 * @param string $cursor Last inspected child basename.
	 * @param int    $limit  Maximum children to return.
	 * @return array{entries:array<string,string>,cursor_found:bool}
	 * @throws RuntimeException When a directory cannot be read.
	 */
	private function directory_child_page_after_cursor( $path, $cursor, $limit ) {
		if ( ! is_dir( $path ) ) {
			throw new RuntimeException( 'Queued directory item is no longer a directory.' );
		}

		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( 'Directory is not readable.' );
		}

		try {
			$iterator = new \DirectoryIterator( $path );
		} catch ( \UnexpectedValueException $exception ) {
			throw new RuntimeException( 'Unable to read directory entries.' );
		}

		$cursor       = (string) $cursor;
		$cursor_found = '' === $cursor;
		$entries      = array();

		foreach ( $iterator as $entry ) {
			if ( $entry->isDot() ) {
				continue;
			}

			$entry_name = $entry->getFilename();

			if ( $entry_name === $cursor ) {
				$cursor_found = true;
				continue;
			}

			if ( '' !== $cursor && strcmp( $entry_name, $cursor ) <= 0 ) {
				continue;
			}

			$entries[ $entry_name ] = $entry->getPathname();
			ksort( $entries, SORT_STRING );

			if ( count( $entries ) > $limit ) {
				end( $entries );
				unset( $entries[ key( $entries ) ] );
			}
		}

		ksort( $entries, SORT_STRING );

		return array(
			'entries'      => $entries,
			'cursor_found' => $cursor_found,
		);
	}

	/**
	 * Returns the last inspected directory child basename.
	 *
	 * @param ImportSourceItem $item Directory item.
	 * @return string
	 */
	private function directory_cursor( ImportSourceItem $item ) {
		$metadata = $item->get_metadata();

		return isset( $metadata['directory_cursor'] ) ? (string) $metadata['directory_cursor'] : '';
	}

	/**
	 * Persists directory cursor progress.
	 *
	 * @param ImportSourceItem $item       Directory item.
	 * @param string           $entry_name Last inspected child basename.
	 * @return ImportSourceItem
	 */
	private function save_directory_cursor( ImportSourceItem $item, $entry_name ) {
		$item = $item->with_status( ImportSourceItem::STATUS_PROCESSING )->with_metadata(
			array(
				'directory_status' => 'scanning',
				'directory_cursor' => (string) $entry_name,
			)
		);

		$this->store->save_source_item( $item );

		return $item;
	}

	/**
	 * Builds metadata for a path without loading file content.
	 *
	 * @param string $path Path.
	 * @return array<string,mixed>
	 */
	private function metadata_for_path( $path ) {
		$metadata = array(
			'basename' => basename( $path ),
		);

		if ( is_file( $path ) ) {
			$metadata['bytes']     = filesize( $path );
			$metadata['extension'] = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		}

		return $metadata;
	}

	/**
	 * Returns whether a source can be treated as local filesystem input.
	 *
	 * @param string $source Source descriptor.
	 * @return bool
	 */
	private function is_local_source( $source ) {
		$source = (string) $source;

		return '' !== $source && ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $source );
	}

	/**
	 * Normalizes a local source path.
	 *
	 * @param string $source Source descriptor.
	 * @return string
	 */
	private function normalize_local_path( $source ) {
		$path = (string) $source;
		$real = realpath( $path );

		return false === $real ? $path : $real;
	}

	/**
	 * Builds a stable key for a path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function item_key_for_path( $path ) {
		return 'local:' . hash( 'sha256', $this->normalize_local_path( $path ) );
	}

	/**
	 * Returns a path relative to the traversal root.
	 *
	 * @param string $root_path Root path.
	 * @param string $path      Child path.
	 * @return string
	 */
	private function relative_path( $root_path, $path ) {
		$root = rtrim( $this->normalize_local_path( $root_path ), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		$path = $this->normalize_local_path( $path );

		if ( 0 === strpos( $path, $root ) ) {
			return substr( $path, strlen( $root ) );
		}

		return basename( $path );
	}
}
