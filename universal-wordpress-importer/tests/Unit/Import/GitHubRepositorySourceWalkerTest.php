<?php
/**
 * Tests for GitHub repository source traversal.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Unit fixtures use isolated temporary files without WordPress loaded.

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\GitHubRepositorySourceWalker;
use UniversalImporter\Import\ImportCacheDirectory;
use UniversalImporter\Import\ImportRunnerControls;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportSourceItem;
use UniversalImporter\Import\WordPressImportSessionStore;

/**
 * Covers GitHub walker behavior that is easier to test outside the full runner.
 */
final class GitHubRepositorySourceWalkerTest extends TestCase {
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
	 * Sets up walker dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->store = new WordPressImportSessionStore( new FakeWpdb() );
	}

	/**
	 * Cleans temporary filesystem fixtures.
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
	 * GitHub tree URLs can queue a subdirectory through php-toolkit Git plumbing.
	 *
	 * @return void
	 */
	public function test_walker_queues_github_subdirectory_through_git_plumbing() {
		$git_fetcher = new FakeGitRepositoryFetcher();
		$git_fetcher->fail_ref( 'main/docs', 'Git ref was not found.' );
		$git_fetcher->add_file( 'README.md', "# Root\n\nNot imported." );
		$git_fetcher->add_file( 'docs/page.md', "# Repo Page\n\nNested documentation." );
		$git_fetcher->add_file( 'docs/reference/api.md', "# API\n\nReference documentation." );
		$git_fetcher->add_file( 'src/internal.md', "# Internal\n\nNot imported." );

		$archive_fetcher = new FakeRemoteArchiveFetcher( null, 'Archive fetcher should not be called.' );
		$session         = ImportSession::start_for_source( 'https://github.com/example/repository/tree/main/docs' );
		$cache           = new ImportCacheDirectory( $this->temporary_directory() . '/managed-cache' );
		$this->store->save( $session );

		$summary = ( new GitHubRepositorySourceWalker( $this->store, $archive_fetcher, $cache, null, ImportRunnerControls::none(), $git_fetcher ) )->advance( $session );

		$items = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED ), 10 );
		$paths = array_map(
			function ( ImportSourceItem $item ) {
				return $item->get_relative_path();
			},
			$items
		);
		sort( $paths );

		$events   = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);
		$requests = $git_fetcher->get_requests();
		$metadata = $items[0]->get_metadata();

		$this->assertSame( 2, $summary['queued'] );
		$this->assertTrue( $summary['complete'] );
		$this->assertSame( array( 'page.md', 'reference/api.md' ), $paths );
		$this->assertSame( array(), $archive_fetcher->get_requested_urls() );
		$this->assertCount( 2, $requests );
		$this->assertSame( 'main/docs', $requests[0]['ref'] );
		$this->assertSame( 'main', $requests[1]['ref'] );
		$this->assertSame( 'docs', $requests[1]['source_path'] );
		$this->assertTrue( $metadata['github_git_fetch'] );
		$this->assertSame( 'docs', $metadata['github_source_path'] );
		$this->assertContains( 'github.git_unavailable', $events );
		$this->assertContains( 'github.git_queued', $events );
		$this->assertNotContains( 'github.archive_downloaded', $events );
	}

	/**
	 * Creates a temporary directory tracked for cleanup.
	 *
	 * @return string
	 */
	private function temporary_directory() {
		$path = sys_get_temp_dir() . '/universal-importer-' . bin2hex( random_bytes( 6 ) );

		mkdir( $path );
		$this->temporary_paths[] = $path;

		return $path;
	}

	/**
	 * Removes a fixture path recursively.
	 *
	 * @param string $path Path.
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

			$this->remove_path( rtrim( $path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $entry );
		}

		rmdir( $path );
	}
}
