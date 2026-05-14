<?php
/**
 * Zip archive source walker.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Archive traversal uses importer-managed cache files.

use RuntimeException;
use ZipArchive;

/**
 * Expands discovered zip files into durable source queue items.
 */
final class ZipArchiveSourceWalker {
	const DEFAULT_ITEM_LIMIT = 100;
	const MAX_ENTRY_BYTES    = 67108864;

	/**
	 * Durable store.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * Directory used for extracted archive entries.
	 *
	 * @var ImportCacheDirectory
	 */
	private $cache_directory;

	/**
	 * Hidden failure simulation controls for adversarial tests.
	 *
	 * @var ImportRunnerControls
	 */
	private $controls;

	/**
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore      $store    Durable store.
	 * @param string|ImportCacheDirectory|null $cache_root Optional cache root.
	 * @param ImportRunnerControls|null        $controls Optional hidden test controls.
	 */
	public function __construct( WordPressImportSessionStore $store, $cache_root = null, ImportRunnerControls $controls = null ) {
		$this->store           = $store;
		$this->cache_directory = $cache_root instanceof ImportCacheDirectory ? $cache_root : ( null === $cache_root ? ImportCacheDirectory::from_environment() : new ImportCacheDirectory( $cache_root ) );
		$this->controls        = null === $controls ? ImportRunnerControls::none() : $controls;
	}

	/**
	 * Advances archive discovery for a session.
	 *
	 * @param ImportSession $session Session to advance.
	 * @param int           $limit   Maximum archive entries to inspect per archive item.
	 * @return array{expanded:int,queued:int,failed:int,message:string}
	 */
	public function advance( ImportSession $session, $limit = self::DEFAULT_ITEM_LIMIT ) {
		$limit   = max( 1, min( 100, (int) $limit ) );
		$summary = array(
			'expanded' => 0,
			'queued'   => 0,
			'failed'   => 0,
			'message'  => 'No discovered zip archives were ready to expand.',
		);

		if ( ! class_exists( ZipArchive::class ) ) {
			$summary['message'] = 'Zip traversal is unavailable because the PHP zip extension is not loaded.';
			return $summary;
		}

		foreach ( $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED, ImportSourceItem::STATUS_PROCESSING ), $limit ) as $item ) {
			if ( ! $this->is_zip_item( $item ) ) {
				continue;
			}

			$item_summary         = $this->expand_item( $session, $item, $limit );
			$summary['expanded'] += $item_summary['expanded'];
			$summary['queued']   += $item_summary['queued'];
			$summary['failed']   += $item_summary['failed'];
		}

		if ( 0 < $summary['expanded'] || 0 < $summary['queued'] || 0 < $summary['failed'] ) {
			$summary['message'] = 'Zip archive walker expanded discovered archive items.';
		}

		return $summary;
	}

	/**
	 * Expands one archive source item.
	 *
	 * @param ImportSession    $session Session.
	 * @param ImportSourceItem $item    Archive item.
	 * @param int              $limit   Maximum archive entries to inspect.
	 * @return array{expanded:int,queued:int,failed:int}
	 * @throws RuntimeException When zip handling fails unexpectedly.
	 */
	private function expand_item( ImportSession $session, ImportSourceItem $item, $limit ) {
		$summary = array(
			'expanded' => 0,
			'queued'   => 0,
			'failed'   => 0,
		);

		try {
			if ( ! is_file( $item->get_source_uri() ) || ! is_readable( $item->get_source_uri() ) ) {
				throw new RuntimeException( 'Discovered zip archive is no longer a readable file.' );
			}

			$zip = new ZipArchive();

			if ( true !== $zip->open( $item->get_source_uri() ) ) {
				throw new RuntimeException( 'Unable to open discovered zip archive.' );
			}

			$next_index = $this->next_entry_index( $item );
			$inspected  = 0;
			$item       = $item->with_status( ImportSourceItem::STATUS_PROCESSING )->with_metadata(
				array(
					'archive_status'        => 'expanding',
					'archive_next_index'    => $next_index,
					'archive_total_entries' => $zip->numFiles,
				)
			);
			$this->store->save_source_item( $item );

			try {
				for ( $index = $next_index; $index < $zip->numFiles; ++$index ) {
					$entry = $zip->statIndex( $index );
					++$inspected;

					if ( false === $entry || empty( $entry['name'] ) || $this->is_directory_entry( $entry['name'] ) ) {
						$item = $this->save_entry_cursor( $session, $item, $index + 1, $zip->numFiles );
						if ( $inspected >= $limit && $index + 1 < $zip->numFiles ) {
							return $summary;
						}
						continue;
					}

					if ( ! $this->entry_matches_archive_prefix( $item, $entry['name'] ) ) {
						$item = $this->save_entry_cursor( $session, $item, $index + 1, $zip->numFiles );
						if ( $inspected >= $limit && $index + 1 < $zip->numFiles ) {
							return $summary;
						}
						continue;
					}

					if ( $this->is_unsafe_entry_name( $entry['name'] ) ) {
						$this->record_event( $session, 'archive.entry_skipped_unsafe', 'Zip entry was skipped because its path is unsafe.', $item, array( 'entry' => $entry['name'] ) );
						$item = $this->save_entry_cursor( $session, $item, $index + 1, $zip->numFiles );
						if ( $inspected >= $limit && $index + 1 < $zip->numFiles ) {
							return $summary;
						}
						continue;
					}

					if ( isset( $entry['size'] ) && self::MAX_ENTRY_BYTES < (int) $entry['size'] ) {
						$this->record_event(
							$session,
							'archive.entry_skipped_large',
							'Zip entry was skipped because it exceeds the per-entry extraction limit.',
							$item,
							array(
								'entry' => $entry['name'],
								'bytes' => (int) $entry['size'],
							)
						);
						$item = $this->save_entry_cursor( $session, $item, $index + 1, $zip->numFiles );
						if ( $inspected >= $limit && $index + 1 < $zip->numFiles ) {
							return $summary;
						}
						continue;
					}

					if ( $this->queue_entry( $session, $item, $zip, $entry ) ) {
						++$summary['queued'];
					}

					$item = $this->save_entry_cursor( $session, $item, $index + 1, $zip->numFiles );
					if ( $inspected >= $limit && $index + 1 < $zip->numFiles ) {
						return $summary;
					}
				}
			} finally {
				$zip->close();
			}

			$this->store->save_source_item(
				$item->with_status( ImportSourceItem::STATUS_SKIPPED )->with_metadata(
					array(
						'archive_status' => 'expanded',
						'skip_reason'    => 'Zip archive container expanded; child entries were queued separately.',
					)
				)
			);
			$this->record_event( $session, 'archive.expanded', 'Zip archive was expanded into source queue items.', $item, array( 'queued' => $summary['queued'] ) );
			++$summary['expanded'];
		} catch ( RuntimeException $exception ) {
			$this->store->save_source_item(
				$item->with_status( ImportSourceItem::STATUS_FAILED )->with_metadata(
					array(
						'archive_status' => 'failed',
						'error'          => $exception->getMessage(),
					)
				)
			);
			$this->record_event( $session, 'archive.failed', $exception->getMessage(), $item, array() );
			++$summary['failed'];
		}

		return $summary;
	}

	/**
	 * Extracts and queues one archive entry.
	 *
	 * @param ImportSession       $session Session.
	 * @param ImportSourceItem    $parent_item Parent archive item.
	 * @param ZipArchive          $zip         Open archive.
	 * @param array<string,mixed> $entry   Zip entry metadata.
	 * @return bool Whether a new item was queued.
	 * @throws RuntimeException When extraction fails.
	 */
	private function queue_entry( ImportSession $session, ImportSourceItem $parent_item, ZipArchive $zip, array $entry ) {
		$entry_name = str_replace( '\\', '/', (string) $entry['name'] );
		$item_key   = $this->entry_key( $parent_item, $entry_name );

		if ( null !== $this->store->find_source_item( $session->get_id(), $item_key ) ) {
			return false;
		}

		$target = $this->extracted_entry_path( $session, $parent_item, $entry_name );
		$this->cache_directory->ensure_parent_directory( $target );

		$stream = $zip->getStream( $entry['name'] );

		if ( false === $stream ) {
			throw new RuntimeException( 'Unable to open zip entry stream.' );
		}

		$output = fopen( $target, 'wb' );

		if ( false === $output ) {
			fclose( $stream );
			throw new RuntimeException( 'Unable to create extracted zip entry cache file.' );
		}

		try {
			while ( ! feof( $stream ) ) {
				$chunk = fread( $stream, 65536 );

				if ( false === $chunk ) {
					throw new RuntimeException( 'Unable to read zip entry stream.' );
				}

				if ( false === fwrite( $output, $chunk ) ) {
					throw new RuntimeException( 'Unable to write extracted zip entry cache file.' );
				}
			}
		} finally {
			fclose( $stream );
			fclose( $output );
		}

		$relative_path = $this->entry_relative_path( $parent_item, $entry_name );
		$extension     = strtolower( pathinfo( $entry_name, PATHINFO_EXTENSION ) );

		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				$item_key,
				$parent_item->get_key(),
				$target,
				$relative_path,
				ImportSourceItem::TYPE_FILE,
				array(
					'basename'           => basename( $entry_name ),
					'bytes'              => filesize( $target ),
					'extension'          => $extension,
					'archive_parent_key' => $parent_item->get_key(),
					'archive_entry'      => $entry_name,
					'archive_source_uri' => $parent_item->get_source_uri(),
				)
				+ $this->cache_directory->metadata_for( 'archives', $target )
			)
		);

		return true;
	}

	/**
	 * Whether a source item points to a zip archive.
	 *
	 * @param ImportSourceItem $item Source item.
	 * @return bool
	 */
	private function is_zip_item( ImportSourceItem $item ) {
		if ( ImportSourceItem::TYPE_FILE !== $item->get_type() ) {
			return false;
		}

		$metadata  = $item->get_metadata();
		$extension = isset( $metadata['extension'] ) ? strtolower( (string) $metadata['extension'] ) : strtolower( pathinfo( $item->get_source_uri(), PATHINFO_EXTENSION ) );

		return 'zip' === $extension;
	}

	/**
	 * Returns the next archive entry index to inspect.
	 *
	 * @param ImportSourceItem $item Archive item.
	 * @return int
	 */
	private function next_entry_index( ImportSourceItem $item ) {
		$metadata = $item->get_metadata();

		return isset( $metadata['archive_next_index'] ) ? max( 0, (int) $metadata['archive_next_index'] ) : 0;
	}

	/**
	 * Persists archive expansion progress after one entry has been inspected.
	 *
	 * @param ImportSession    $session     Session.
	 * @param ImportSourceItem $item        Archive item.
	 * @param int              $next_index  Next entry index.
	 * @param int              $total_count Total archive entry count.
	 * @return ImportSourceItem
	 */
	private function save_entry_cursor( ImportSession $session, ImportSourceItem $item, $next_index, $total_count ) {
		$item = $item->with_status( ImportSourceItem::STATUS_PROCESSING )->with_metadata(
			array(
				'archive_status'        => 'expanding',
				'archive_next_index'    => max( 0, (int) $next_index ),
				'archive_total_entries' => max( 0, (int) $total_count ),
			)
		);

		$this->store->save_source_item( $item );

		if ( $this->controls->should_simulate_fatal_after_zip_entry_cursor() && (int) $next_index < (int) $total_count ) {
			$this->record_event(
				$session,
				'runner.simulated_fatal_after_zip_entry_cursor',
				'Runner is terminating PHP after a durable zip entry cursor write for recovery testing.',
				$item,
				array(
					'cursor' => max( 0, (int) $next_index ),
					'total'  => max( 0, (int) $total_count ),
				)
			);

			exit( 124 );
		}

		return $item;
	}

	/**
	 * Returns whether a zip entry is a directory.
	 *
	 * @param string $name Entry name.
	 * @return bool
	 */
	private function is_directory_entry( $name ) {
		return '/' === substr( (string) $name, -1 );
	}

	/**
	 * Returns whether a zip entry could escape the extraction cache.
	 *
	 * @param string $name Entry name.
	 * @return bool
	 */
	private function is_unsafe_entry_name( $name ) {
		$name = str_replace( '\\', '/', (string) $name );

		if ( '' === trim( $name ) || '/' === substr( $name, 0, 1 ) || false !== strpos( $name, "\0" ) ) {
			return true;
		}

		foreach ( explode( '/', $name ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether an archive entry matches an optional parent prefix filter.
	 *
	 * @param ImportSourceItem $item       Parent archive item.
	 * @param string           $entry_name Archive entry name.
	 * @return bool
	 */
	private function entry_matches_archive_prefix( ImportSourceItem $item, $entry_name ) {
		$metadata = $item->get_metadata();

		if ( empty( $metadata['archive_entry_prefix'] ) ) {
			return true;
		}

		$prefix = $this->normalize_entry_path( (string) $metadata['archive_entry_prefix'] );
		$entry  = $this->normalize_entry_path( $entry_name );

		if ( '' === $prefix || '' === $entry ) {
			return true;
		}

		if ( $this->path_matches_prefix( $entry, $prefix ) ) {
			return true;
		}

		if ( ! empty( $metadata['archive_strip_root_segment'] ) ) {
			return $this->path_matches_prefix( $this->strip_first_entry_segment( $entry ), $prefix );
		}

		return false;
	}

	/**
	 * Normalizes an archive path for prefix comparison only.
	 *
	 * @param string $path Entry or prefix path.
	 * @return string
	 */
	private function normalize_entry_path( $path ) {
		return trim( str_replace( '\\', '/', (string) $path ), '/' );
	}

	/**
	 * Returns whether a path is exactly at or below a prefix.
	 *
	 * @param string $path   Normalized path.
	 * @param string $prefix Normalized prefix.
	 * @return bool
	 */
	private function path_matches_prefix( $path, $prefix ) {
		return $path === $prefix || 0 === strpos( $path, rtrim( $prefix, '/' ) . '/' );
	}

	/**
	 * Removes the first path segment from a normalized archive entry.
	 *
	 * @param string $path Normalized path.
	 * @return string
	 */
	private function strip_first_entry_segment( $path ) {
		$position = strpos( $path, '/' );

		return false === $position ? '' : substr( $path, $position + 1 );
	}

	/**
	 * Builds a stable source item key for an archive entry.
	 *
	 * @param ImportSourceItem $parent_item Parent archive item.
	 * @param string           $entry_name Entry name.
	 * @return string
	 */
	private function entry_key( ImportSourceItem $parent_item, $entry_name ) {
		return 'zip:' . hash( 'sha256', $parent_item->get_key() . "\n" . str_replace( '\\', '/', (string) $entry_name ) );
	}

	/**
	 * Builds a stable cache path for an extracted archive entry.
	 *
	 * @param ImportSession    $session    Session.
	 * @param ImportSourceItem $parent_item Parent archive item.
	 * @param string           $entry_name Entry name.
	 * @return string
	 */
	private function extracted_entry_path( ImportSession $session, ImportSourceItem $parent_item, $entry_name ) {
		$extension = strtolower( pathinfo( $entry_name, PATHINFO_EXTENSION ) );
		$suffix    = '' === $extension ? '' : '.' . preg_replace( '/[^a-z0-9]+/', '', $extension );

		return $this->cache_directory->path_for(
			$session->get_id(),
			'archives',
			array(
				hash( 'sha256', $parent_item->get_key() ),
				hash( 'sha256', str_replace( '\\', '/', (string) $entry_name ) ) . $suffix,
			)
		);
	}

	/**
	 * Builds a display relative path for an archive entry.
	 *
	 * @param ImportSourceItem $parent_item Parent archive item.
	 * @param string           $entry_name Entry name.
	 * @return string
	 */
	private function entry_relative_path( ImportSourceItem $parent_item, $entry_name ) {
		$entry_name  = str_replace( '\\', '/', (string) $entry_name );
		$parent_path = $parent_item->get_relative_path();

		if ( '' === $parent_path ) {
			$parent_path = basename( $parent_item->get_source_uri() );
		}

		return rtrim( str_replace( '\\', '/', $parent_path ), '/' ) . '!' . $entry_name;
	}

	/**
	 * Records an archive progress event.
	 *
	 * @param ImportSession       $session Session.
	 * @param string              $type    Event type.
	 * @param string              $message Event message.
	 * @param ImportSourceItem    $item    Source item.
	 * @param array<string,mixed> $context Additional context.
	 * @return void
	 */
	private function record_event( ImportSession $session, $type, $message, ImportSourceItem $item, array $context ) {
		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_INFO,
				$type,
				$message,
				array_merge(
					array(
						'item_key'      => $item->get_key(),
						'relative_path' => $item->get_relative_path(),
					),
					$context
				)
			)
		);
	}
}
