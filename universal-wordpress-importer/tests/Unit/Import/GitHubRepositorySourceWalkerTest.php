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
	 * Ambiguous slash-ref tree URLs try sparse Git candidates before the tree API.
	 *
	 * @return void
	 */
	public function test_walker_uses_tree_api_for_ambiguous_slash_ref_subdirectory() {
		$git_fetcher = new FakeGitRepositoryFetcher();
		$git_fetcher->fail_ref( 'main/docs/reference', 'Git ref was not found.' );
		$git_fetcher->fail_ref( 'main/docs', 'Git ref was not found.' );
		$git_fetcher->fail_ref( 'main', 'Git ref was not found.' );

		$content_fetcher = new FakeRemoteContentFetcher();
		$content_fetcher->add_json_error(
			'https://api.github.com/repos/example/repository/git/trees/main/docs/reference?recursive=1',
			'GitHub tree ref was not found.'
		);
		$content_fetcher->add_json_error(
			'https://api.github.com/repos/example/repository/git/trees/main/docs?recursive=1',
			'GitHub tree ref was not found.'
		);
		$content_fetcher->add_json_error(
			'https://api.github.com/repos/example/repository/git/trees/main?recursive=1',
			'GitHub root tree response was too large.'
		);
		$content_fetcher->add_json_error(
			'https://api.github.com/repos/example/repository/contents/reference?ref=main/docs',
			'GitHub contents ref was not found.'
		);
		$content_fetcher->add_json(
			'https://api.github.com/repos/example/repository/contents/docs/reference?ref=main',
			array(
				array(
					'path'    => 'docs/reference/api.md',
					'type'    => 'file',
					'git_url' => 'https://api.github.com/repos/example/repository/git/blobs/api',
					'size'    => 25,
				),
				array(
					'path' => 'docs/reference/images',
					'type' => 'dir',
				),
			)
		);
		$content_fetcher->add_json(
			'https://api.github.com/repos/example/repository/contents/docs/reference/images?ref=main',
			array(
				array(
					'path'    => 'docs/reference/images/diagram.md',
					'type'    => 'file',
					'git_url' => 'https://api.github.com/repos/example/repository/git/blobs/diagram',
					'size'    => 30,
				),
			)
		);
		$content_fetcher->add_json(
			'https://api.github.com/repos/example/repository/git/blobs/api',
			array(
				'encoding' => 'base64',
				'content'  => base64_encode( "# API\n\nReference content." ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- GitHub's blob API returns base64 encoded content.
			)
		);
		$content_fetcher->add_json(
			'https://api.github.com/repos/example/repository/git/blobs/diagram',
			array(
				'encoding' => 'base64',
				'content'  => base64_encode( "# Diagram\n\nReference diagram." ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- GitHub's blob API returns base64 encoded content.
			)
		);

		$archive_fetcher = new FakeRemoteArchiveFetcher( null, 'Archive fetcher should not be called.' );
		$session         = ImportSession::start_for_source( 'https://github.com/example/repository/tree/main/docs/reference' );
		$cache           = new ImportCacheDirectory( $this->temporary_directory() . '/managed-cache' );
		$this->store->save( $session );

		$summary = ( new GitHubRepositorySourceWalker( $this->store, $archive_fetcher, $cache, $content_fetcher, ImportRunnerControls::none(), $git_fetcher ) )->advance( $session );

		$items  = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED ), 10 );
		$paths  = array_map(
			function ( ImportSourceItem $item ) {
				return $item->get_relative_path();
			},
			$items
		);
		$events = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		sort( $paths );

		$this->assertSame( 2, $summary['queued'] );
		$this->assertTrue( $summary['complete'] );
		$this->assertSame( array( 'api.md', 'images/diagram.md' ), $paths );
		$this->assertCount( 3, $git_fetcher->get_requests() );
		$this->assertSame( array(), $archive_fetcher->get_requested_urls() );
		$this->assertContains( 'github.tree_queued', $events );
		$this->assertContains( 'github.git_unavailable', $events );
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
