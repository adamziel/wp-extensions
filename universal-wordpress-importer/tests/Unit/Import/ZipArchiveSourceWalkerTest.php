<?php
/**
 * Tests for resumable zip archive source traversal.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Unit fixtures use isolated temporary files without WordPress loaded.

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\ImportCacheDirectory;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportSourceItem;
use UniversalImporter\Import\WordPressImportSessionStore;
use UniversalImporter\Import\ZipArchiveSourceWalker;
use ZipArchive;

/**
 * Covers archive entry cursor persistence without a full WordPress runtime.
 */
final class ZipArchiveSourceWalkerTest extends TestCase {
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

		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'The PHP zip extension is required for archive traversal tests.' );
		}

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
	 * Zip expansion persists an entry cursor and resumes without duplicate child items.
	 *
	 * @return void
	 */
	public function test_zip_expansion_resumes_from_entry_cursor() {
		$archive = $this->temporary_zip(
			'large.zip',
			array(
				'001.md' => '# One',
				'002.md' => '# Two',
				'003.md' => '# Three',
			)
		);
		$session = ImportSession::start_for_source( $archive );
		$item    = ImportSourceItem::queued(
			$session->get_id(),
			'zip:test',
			null,
			$archive,
			'large.zip',
			ImportSourceItem::TYPE_FILE,
			array(
				'basename'  => 'large.zip',
				'extension' => 'zip',
			)
		)->with_status( ImportSourceItem::STATUS_DISCOVERED );
		$walker  = new ZipArchiveSourceWalker( $this->store, new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' ) );

		$this->store->save( $session );
		$this->store->save_source_item( $item );

		$first = $walker->advance( $session, 1 );

		$this->assertSame( 0, $first['expanded'] );
		$this->assertSame( 1, $first['queued'] );
		$this->assertArchiveCursor( $session, 'zip:test', 1, 3, ImportSourceItem::STATUS_PROCESSING );
		$this->assertSame( 1, $this->count_archive_children( $session, 'zip:test' ) );

		$second = $walker->advance( $session, 1 );

		$this->assertSame( 0, $second['expanded'] );
		$this->assertSame( 1, $second['queued'] );
		$this->assertArchiveCursor( $session, 'zip:test', 2, 3, ImportSourceItem::STATUS_PROCESSING );
		$this->assertSame( 2, $this->count_archive_children( $session, 'zip:test' ) );

		$third = $walker->advance( $session, 1 );

		$this->assertSame( 1, $third['expanded'] );
		$this->assertSame( 1, $third['queued'] );
		$this->assertArchiveCursor( $session, 'zip:test', 3, 3, ImportSourceItem::STATUS_SKIPPED );
		$this->assertSame( 3, $this->count_archive_children( $session, 'zip:test' ) );

		$fourth = $walker->advance( $session, 1 );

		$this->assertSame( 0, $fourth['expanded'] );
		$this->assertSame( 0, $fourth['queued'] );
		$this->assertSame( 3, $this->count_archive_children( $session, 'zip:test' ) );
	}

	/**
	 * Asserts the durable archive cursor state.
	 *
	 * @param ImportSession $session    Session.
	 * @param string        $item_key   Source item key.
	 * @param int           $next_index Expected next entry index.
	 * @param int           $total      Expected total entry count.
	 * @param string        $status     Expected item status.
	 * @return void
	 */
	private function assertArchiveCursor( ImportSession $session, $item_key, $next_index, $total, $status ) {
		$item     = $this->store->find_source_item( $session->get_id(), $item_key );
		$metadata = $item->get_metadata();

		$this->assertSame( $status, $item->get_status() );
		$this->assertSame( $next_index, $metadata['archive_next_index'] );
		$this->assertSame( $total, $metadata['archive_total_entries'] );
	}

	/**
	 * Counts archive child source items.
	 *
	 * @param ImportSession $session    Session.
	 * @param string        $parent_key Parent source item key.
	 * @return int
	 */
	private function count_archive_children( ImportSession $session, $parent_key ) {
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
	 * Creates a temporary zip archive fixture.
	 *
	 * @param string               $basename Archive basename.
	 * @param array<string,string> $entries  Entry contents keyed by entry name.
	 * @return string
	 */
	private function temporary_zip( $basename, array $entries ) {
		$path = sys_get_temp_dir() . '/universal-importer-zip-walker-' . bin2hex( random_bytes( 6 ) ) . '-' . $basename;
		$zip  = new ZipArchive();

		$this->assertTrue( true === $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );

		foreach ( $entries as $entry_name => $content ) {
			$this->assertTrue( $zip->addFromString( $entry_name, $content ) );
		}

		$this->assertTrue( $zip->close() );
		$this->temporary_paths[] = $path;

		return $path;
	}

	/**
	 * Creates a temporary directory.
	 *
	 * @return string
	 */
	private function temporary_directory() {
		$path = sys_get_temp_dir() . '/universal-importer-zip-walker-' . bin2hex( random_bytes( 6 ) );

		$this->assertTrue( mkdir( $path, 0777, true ) );
		$this->assertTrue( chmod( $path, 0777 ) );
		$this->temporary_paths[] = $path;

		return $path;
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
