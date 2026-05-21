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
	 * GitHub branch URLs can queue repository files through php-toolkit Git plumbing.
	 *
	 * @return void
	 */
	public function test_walker_queues_github_branch_through_git_plumbing() {
		$git_fetcher = new FakeGitRepositoryFetcher();
		$git_fetcher->add_file( 'README.md', "# Root\n\nNot imported." );
		$git_fetcher->add_file( 'docs/page.md', "# Repo Page\n\nNested documentation." );
		$git_fetcher->add_file( 'docs/reference/api.md', "# API\n\nReference documentation." );
		$git_fetcher->add_file( 'src/internal.md', "# Internal\n\nNot imported." );

		$archive_fetcher = new FakeRemoteArchiveFetcher( null, 'Archive fetcher should not be called.' );
		$session         = ImportSession::start_for_source( 'https://github.com/example/repository/tree/main' );
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

		$this->assertSame( 4, $summary['queued'] );
		$this->assertTrue( $summary['complete'] );
		$this->assertSame( array( 'README.md', 'docs/page.md', 'docs/reference/api.md', 'src/internal.md' ), $paths );
		$this->assertSame( array(), $archive_fetcher->get_requested_urls() );
		$this->assertCount( 1, $requests );
		$this->assertSame( 'main', $requests[0]['ref'] );
		$this->assertSame( '', $requests[0]['source_path'] );
		$this->assertTrue( $metadata['github_git_fetch'] );
		$this->assertSame( '', $metadata['github_source_path'] );
		$this->assertNotContains( 'github.git_unavailable', $events );
		$this->assertContains( 'github.git_queued', $events );
		$this->assertNotContains( 'github.archive_downloaded', $events );
	}

	/**
	 * Ambiguous slash-ref tree URLs try sparse Git candidates only.
	 *
	 * @return void
	 */
	public function test_walker_fails_ambiguous_slash_ref_subdirectory_without_sparse_git_match() {
		$git_fetcher = new FakeGitRepositoryFetcher();
		$git_fetcher->fail_ref( 'main/docs/reference', 'Git ref was not found.' );
		$git_fetcher->fail_ref( 'main/docs', 'Git ref was not found.' );
		$git_fetcher->fail_ref( 'main', 'Git ref was not found.' );

		$archive_fetcher = new FakeRemoteArchiveFetcher( null, 'Archive fetcher should not be called.' );
		$session         = ImportSession::start_for_source( 'https://github.com/example/repository/tree/main/docs/reference' );
		$cache           = new ImportCacheDirectory( $this->temporary_directory() . '/managed-cache' );
		$this->store->save( $session );

		$summary = ( new GitHubRepositorySourceWalker( $this->store, $archive_fetcher, $cache, null, ImportRunnerControls::none(), $git_fetcher ) )->advance( $session );

		$items  = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED ), 10 );
		$failed = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ), 10 );
		$events = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( 0, $summary['queued'] );
		$this->assertSame( 1, $summary['failed'] );
		$this->assertTrue( $summary['complete'] );
		$this->assertSame( array(), $items );
		$this->assertCount( 1, $failed );
		$this->assertStringContainsString( 'sparse Git', $failed[0]->get_metadata()['error'] );
		$this->assertStringContainsString( 'do not use tree/blob', $failed[0]->get_metadata()['error'] );
		$this->assertCount( 3, $git_fetcher->get_requests() );
		$this->assertSame( array(), $archive_fetcher->get_requested_urls() );
		$this->assertContains( 'github.git_unavailable', $events );
		$this->assertContains( 'github.traversal_failed', $events );
		$this->assertNotContains( 'github.tree_queued', $events );
	}

	/**
	 * Sparse Git failures include the attempted ref, path, and exception detail.
	 *
	 * @return void
	 */
	public function test_walker_records_sparse_git_failure_reason() {
		$git_fetcher = new FakeGitRepositoryFetcher();
		$git_fetcher->fail_ref( 'trunk/docs/explanations/architecture', 'Git ref was not found.' );
		$git_fetcher->fail_ref( 'trunk/docs/explanations', 'Git ref was not found.' );
		$git_fetcher->fail_ref( 'trunk/docs', 'Git ref was not found.' );
		$git_fetcher->fail_ref( 'trunk', 'Remote upload-pack returned HTTP 500.' );

		$session = ImportSession::start_for_source( 'https://github.com/WordPress/gutenberg/tree/trunk/docs/explanations/architecture' );
		$cache   = new ImportCacheDirectory( $this->temporary_directory() . '/managed-cache' );
		$this->store->save( $session );

		( new GitHubRepositorySourceWalker( $this->store, null, $cache, null, ImportRunnerControls::none(), $git_fetcher ) )->advance( $session );

		$events = $this->store->list_events( $session->get_id(), 20 );
		$messages = array_map(
			function ( $event ) {
				return $event->get_message();
			},
			$events
		);

		$git_failure_messages = array_values(
			array_filter(
				$messages,
				function ( $message ) {
					return false !== strpos( $message, 'Remote upload-pack returned HTTP 500.' );
				}
			)
		);

		$this->assertNotEmpty( $git_failure_messages );
		$this->assertStringContainsString( 'ref "trunk"', $git_failure_messages[0] );
		$this->assertStringContainsString( 'path "docs/explanations/architecture"', $git_failure_messages[0] );
		$this->assertStringContainsString( 'Remote upload-pack returned HTTP 500.', $git_failure_messages[0] );
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
