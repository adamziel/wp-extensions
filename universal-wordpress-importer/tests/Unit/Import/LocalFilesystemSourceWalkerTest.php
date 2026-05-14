<?php
/**
 * Tests for resumable local filesystem source traversal.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Unit fixtures use isolated temporary files without WordPress loaded.

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportSourceItem;
use UniversalImporter\Import\LocalFilesystemSourceWalker;
use UniversalImporter\Import\WordPressImportSessionStore;

/**
 * Covers local directory cursor persistence without a full WordPress runtime.
 */
final class LocalFilesystemSourceWalkerTest extends TestCase {
	/**
	 * Store under test.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * Temporary paths to remove after tests.
	 *
	 * @var array<int,string>
	 */
	private $temporary_paths = array();

	/**
	 * Sets up durable store dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->store = new WordPressImportSessionStore( new FakeWpdb() );
	}

	/**
	 * Removes temporary filesystem fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( array_reverse( $this->temporary_paths ) as $path ) {
			$this->remove_path( $path );
		}

		parent::tearDown();
	}

	/**
	 * Directory traversal persists a child cursor and resumes without duplicate items.
	 *
	 * @return void
	 */
	public function test_directory_traversal_resumes_from_child_cursor() {
		$root = $this->temporary_directory();
		$this->write_file( $root . '/001.md', '# One' );
		$this->write_file( $root . '/002.md', '# Two' );
		$this->write_file( $root . '/003.md', '# Three' );

		$session = ImportSession::start_for_source( $root );
		$walker  = new LocalFilesystemSourceWalker( $this->store );

		$this->store->save( $session );

		$first     = $walker->advance( $session, 1 );
		$root_item = $this->root_item( $session );
		$metadata  = $root_item->get_metadata();

		$this->assertSame( 0, $first['discovered'] );
		$this->assertSame( 1, $first['queued'] );
		$this->assertSame( ImportSourceItem::STATUS_PROCESSING, $root_item->get_status() );
		$this->assertSame( 'scanning', $metadata['directory_status'] );
		$this->assertNotSame( '', $metadata['directory_cursor'] );
		$this->assertSame( 1, $this->count_root_children( $session, $root_item->get_key() ) );

		for ( $i = 0; $i < 10; ++$i ) {
			if ( ImportSourceItem::STATUS_SKIPPED === $this->root_item( $session )->get_status() ) {
				break;
			}

			$walker->advance( $session, 1 );
		}

		$root_item = $this->root_item( $session );
		$metadata  = $root_item->get_metadata();

		$this->assertSame( ImportSourceItem::STATUS_SKIPPED, $root_item->get_status() );
		$this->assertSame( 'complete', $metadata['directory_status'] );
		$this->assertSame( 3, $this->count_root_children( $session, $root_item->get_key() ) );

		$walker->advance( $session, 1 );

		$this->assertSame( 3, $this->count_root_children( $session, $root_item->get_key() ) );
	}

	/**
	 * Directory traversal uses stable filename order for resumable cursors.
	 *
	 * @return void
	 */
	public function test_directory_traversal_uses_stable_filename_order() {
		$root = $this->temporary_directory();
		$this->write_file( $root . '/003.md', '# Three' );
		$this->write_file( $root . '/001.md', '# One' );
		$this->write_file( $root . '/002.md', '# Two' );

		$session = ImportSession::start_for_source( $root );
		$walker  = new LocalFilesystemSourceWalker( $this->store );

		$this->store->save( $session );

		$walker->advance( $session, 1 );
		$root_item = $this->root_item( $session );

		$this->assertSame( '001.md', $root_item->get_metadata()['directory_cursor'] );
		$this->assertSame( array( '001.md' ), $this->root_child_relative_paths( $session, $root_item->get_key() ) );

		$walker->advance( $session, 1 );
		$root_item = $this->root_item( $session );

		$this->assertSame( '002.md', $root_item->get_metadata()['directory_cursor'] );
		$this->assertSame( array( '001.md', '002.md' ), $this->root_child_relative_paths( $session, $root_item->get_key() ) );
	}

	/**
	 * Missing directory cursors restart scanning without duplicating children.
	 *
	 * @return void
	 */
	public function test_directory_traversal_recovers_when_cursor_entry_is_deleted() {
		$root = $this->temporary_directory();
		$this->write_file( $root . '/001.md', '# One' );
		$this->write_file( $root . '/002.md', '# Two' );
		$this->write_file( $root . '/003.md', '# Three' );

		$session = ImportSession::start_for_source( $root );
		$walker  = new LocalFilesystemSourceWalker( $this->store );

		$this->store->save( $session );

		$walker->advance( $session, 1 );
		$root_item = $this->root_item( $session );
		$this->assertSame( '001.md', $root_item->get_metadata()['directory_cursor'] );

		$this->remove_path( $root . '/001.md' );

		for ( $i = 0; $i < 10; ++$i ) {
			if ( ImportSourceItem::STATUS_SKIPPED === $this->root_item( $session )->get_status() ) {
				break;
			}

			$walker->advance( $session, 1 );
		}

		$root_item = $this->root_item( $session );

		$this->assertSame( ImportSourceItem::STATUS_SKIPPED, $root_item->get_status() );
		$this->assertSame( array( '001.md', '002.md', '003.md' ), $this->root_child_relative_paths( $session, $root_item->get_key() ) );
	}

	/**
	 * Returns the root source item.
	 *
	 * @param ImportSession $session Session.
	 * @return ImportSourceItem
	 */
	private function root_item( ImportSession $session ) {
		$items = $this->store->list_source_items_by_statuses(
			$session->get_id(),
			array(
				ImportSourceItem::STATUS_QUEUED,
				ImportSourceItem::STATUS_PROCESSING,
				ImportSourceItem::STATUS_DISCOVERED,
				ImportSourceItem::STATUS_IMPORTED,
				ImportSourceItem::STATUS_SKIPPED,
				ImportSourceItem::STATUS_FAILED,
			),
			50
		);

		foreach ( $items as $item ) {
			if ( null === $item->get_parent_key() ) {
				return $item;
			}
		}

		$this->fail( 'Root source item was not found.' );
	}

	/**
	 * Counts root child source items.
	 *
	 * @param ImportSession $session    Session.
	 * @param string        $parent_key Parent source item key.
	 * @return int
	 */
	private function count_root_children( ImportSession $session, $parent_key ) {
		$count = 0;
		$items = $this->store->list_source_items_by_statuses(
			$session->get_id(),
			array(
				ImportSourceItem::STATUS_QUEUED,
				ImportSourceItem::STATUS_PROCESSING,
				ImportSourceItem::STATUS_DISCOVERED,
				ImportSourceItem::STATUS_IMPORTED,
				ImportSourceItem::STATUS_SKIPPED,
				ImportSourceItem::STATUS_FAILED,
			),
			50
		);

		foreach ( $items as $item ) {
			if ( $parent_key === $item->get_parent_key() ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Returns root child relative paths.
	 *
	 * @param ImportSession $session    Session.
	 * @param string        $parent_key Parent source item key.
	 * @return array<int,string>
	 */
	private function root_child_relative_paths( ImportSession $session, $parent_key ) {
		$paths = array();
		$items = $this->store->list_source_items_by_statuses(
			$session->get_id(),
			array(
				ImportSourceItem::STATUS_QUEUED,
				ImportSourceItem::STATUS_PROCESSING,
				ImportSourceItem::STATUS_DISCOVERED,
				ImportSourceItem::STATUS_IMPORTED,
				ImportSourceItem::STATUS_SKIPPED,
				ImportSourceItem::STATUS_FAILED,
			),
			50
		);

		foreach ( $items as $item ) {
			if ( $parent_key === $item->get_parent_key() ) {
				$paths[] = $item->get_relative_path();
			}
		}

		sort( $paths, SORT_STRING );

		return $paths;
	}

	/**
	 * Creates a temporary directory.
	 *
	 * @return string
	 */
	private function temporary_directory() {
		$path = sys_get_temp_dir() . '/universal-importer-local-walker-' . bin2hex( random_bytes( 6 ) );

		$this->assertTrue( mkdir( $path, 0777, true ) );
		$this->assertTrue( chmod( $path, 0777 ) );
		$this->temporary_paths[] = $path;

		return $path;
	}

	/**
	 * Writes a fixture file.
	 *
	 * @param string $path     File path.
	 * @param string $contents File contents.
	 * @return void
	 */
	private function write_file( $path, $contents ) {
		$this->assertNotFalse( file_put_contents( $path, $contents ) );
	}

	/**
	 * Removes a temporary file or directory tree.
	 *
	 * @param string $path Path to remove.
	 * @return void
	 */
	private function remove_path( $path ) {
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			unlink( $path );
			return;
		}

		foreach ( scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$this->remove_path( $path . '/' . $entry );
		}

		rmdir( $path );
	}
}
