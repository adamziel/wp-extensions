<?php
/**
 * Tests for the shared import continuation runner.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Unit fixtures use isolated temporary files without WordPress loaded.

use PHPUnit\Framework\TestCase;
use ZipArchive;
use UniversalImporter\Import\ImportDecision;
use UniversalImporter\Import\ImportCacheDirectory;
use UniversalImporter\Import\ImportIdempotencyRecord;
use UniversalImporter\Import\ImportMediaReference;
use UniversalImporter\Import\ImportPreparedDocument;
use UniversalImporter\Import\ImportProgressEvent;
use UniversalImporter\Import\ImportRelationshipMappingDecision;
use UniversalImporter\Import\ImportRemoteContentFetcherInterface;
use UniversalImporter\Import\ImportRunner;
use UniversalImporter\Import\ImportRunnerControls;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportSessionId;
use UniversalImporter\Import\ImportSourceItem;
use UniversalImporter\Import\ImportUrlInference;
use UniversalImporter\Import\ImportWxrAttachment;
use UniversalImporter\Import\SourceItemDocumentProcessor;
use UniversalImporter\Import\WordPressImportSessionStore;
use UniversalImporter\Import\WordPressImportSessionSchema;

/**
 * Covers one-tick continuation behavior without a full WordPress runtime.
 */
final class ImportRunnerTest extends TestCase {
	/**
	 * Fake database object.
	 *
	 * @var FakeWpdb
	 */
	private $wpdb;

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
	 * Sets up runner dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wpdb  = new FakeWpdb();
		$this->store = new WordPressImportSessionStore( $this->wpdb );
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
	 * Pending sessions move to running and emit observable runner events.
	 *
	 * @return void
	 */
	public function test_runner_starts_pending_session_and_releases_lock() {
		$source_file = $this->temporary_file( 'book.md' );
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		$summary = ( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$restored = $this->store->find( $session->get_id() );
		$events   = $this->store->list_events( $session->get_id(), 5 );

		$this->assertSame(
			array(
				'processed' => 1,
				'locked'    => 0,
				'skipped'   => 0,
				'errors'    => 0,
			),
			$summary
		);
		$this->assertSame( ImportSession::STATUS_RUNNING, $restored->get_status() );
		$this->assertSame(
			array(
				'total'     => 1,
				'completed' => 1,
				'errors'    => 0,
			),
			$restored->get_progress()->to_array()
		);
		$this->assertSame( 1, $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ) ) );
		$this->assertSame( 'source.discovery_complete', $events[0]->get_type() );
		$this->assertSame( 'document.prepared', $events[1]->get_type() );
		$this->assertSame( 'session.started', $events[2]->get_type() );
		$this->assertNotNull( $this->store->acquire_lock( $session->get_id(), 'after-runner', 60 ) );
	}

	/**
	 * Runnable sessions schedule another continuation tick after one bounded pass.
	 *
	 * @return void
	 */
	public function test_runner_schedules_follow_up_tick_for_runnable_sessions() {
		$source_file = $this->temporary_file( 'book.md' );
		$session     = ImportSession::start_for_source( $source_file );
		$scheduled   = array();
		$this->store->save( $session );

		$scheduler = function ( ImportSessionId $session_id ) use ( &$scheduled ) {
			$scheduled[] = $session_id->to_string();
		};

		( new ImportRunner( $this->store, 'unit-test', 60, null, null, null, null, null, null, null, null, $scheduler ) )->run( $session->get_id() );

		$this->assertSame( array( $session->get_id()->to_string() ), $scheduled );
	}

	/**
	 * Deferred staged comments schedule another continuation tick.
	 *
	 * @return void
	 */
	public function test_runner_schedules_follow_up_tick_for_deferred_remote_comment_parent() {
		$source_file     = $this->temporary_file( 'comments.md', '# Comments' );
		$source_item_key = 'local:' . hash( 'sha256', realpath( $source_file ) );
		$session         = ImportSession::start_for_source( $source_file );
		$posts           = new FakePostGateway();
		$comments        = new FakeCommentGateway();
		$scheduled       = array();

		$this->store->save( $session );
		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				$source_item_key,
				null,
				$source_file,
				basename( $source_file ),
				ImportSourceItem::TYPE_FILE
			)->with_status( ImportSourceItem::STATUS_IMPORTED )
		);

		$document = new ImportPreparedDocument(
			$session->get_id(),
			$source_item_key,
			'markdown',
			'Comments',
			'<!-- wp:paragraph -->' . "\n" . '<p>Comments</p>' . "\n" . '<!-- /wp:paragraph -->',
			1,
			'hash-comments',
			array(
				'remote_comments'          => array(
					array(
						'remote_comment_id' => 102,
						'remote_parent_id'  => 101,
						'author_name'       => 'Child',
						'content'           => 'Child comment.',
					),
					array(
						'remote_comment_id' => 101,
						'remote_parent_id'  => 0,
						'author_name'       => 'Parent',
						'content'           => 'Parent comment.',
					),
				),
				'remote_comments_complete' => true,
			)
		);
		$this->store->save_prepared_document( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord(
				'prepared-document:' . $source_item_key,
				'prepared_document',
				$source_item_key,
				'hash-comments'
			)
		);

		$post_id = $posts->insert_or_update( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord(
				'post:' . $source_item_key,
				'post',
				(string) $post_id,
				'hash-comments'
			)
		);

		$scheduler = function ( ImportSessionId $session_id ) use ( &$scheduled ) {
			$scheduled[] = $session_id->to_string();
		};

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, null, $comments, null, $scheduler ) )->run( $session->get_id() );

		$event_types = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( ImportSession::STATUS_RUNNING, $this->store->find( $session->get_id() )->get_status() );
		$this->assertSame( array( $session->get_id()->to_string() ), $scheduled );
		$this->assertContains( 'comment.deferred_parent_missing', $event_types );
		$this->assertSame( 1, $comments->count_comments() );
		$this->assertNotNull( $comments->find_existing_comment_id( $session->get_id(), $source_item_key, 101 ) );
		$this->assertNull( $comments->find_existing_comment_id( $session->get_id(), $source_item_key, 102 ) );
	}

	/**
	 * Pending WXR nav menus beyond one bounded page schedule another tick.
	 *
	 * @return void
	 */
	public function test_runner_schedules_follow_up_tick_for_pending_wxr_nav_menu_page() {
		$source_file     = $this->temporary_file( 'navigation.wxr', '<rss></rss>' );
		$source_item_key = 'local:' . hash( 'sha256', realpath( $source_file ) );
		$session         = ImportSession::start_for_source( $source_file );
		$posts           = new FakePostGateway();
		$scheduled       = array();

		$this->store->save( $session );
		$posts->register_menu_location( 'primary', 'Primary Menu' );
		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				$source_item_key,
				null,
				$source_file,
				basename( $source_file ),
				ImportSourceItem::TYPE_FILE
			)->with_status( ImportSourceItem::STATUS_IMPORTED )
		);

		for ( $index = 1; $index <= 101; ++$index ) {
			$key = 'local:nav:' . str_pad( (string) $index, 3, '0', STR_PAD_LEFT );
			$this->store->save_source_item(
				ImportSourceItem::queued(
					$session->get_id(),
					$key,
					null,
					$source_file,
					'navigation-' . $index . '.wxr',
					ImportSourceItem::TYPE_FILE,
					array(
						'wxr_nav_menu_items_by_id' => array(
							(string) $index => array(
								'id'         => $index,
								'title'      => 'Menu Item ' . $index,
								'menu_order' => $index,
								'menu_slug'  => 'primary',
								'menu_name'  => 'Primary Menu',
								'meta'       => array(
									'_menu_item_type' => 'custom',
									'_menu_item_url'  => 'https://example.org/item-' . $index . '/',
									'_menu_item_menu_item_parent' => '0',
								),
							),
						),
					)
				)->with_status( ImportSourceItem::STATUS_IMPORTED )
			);
		}

		$scheduler = function ( ImportSessionId $session_id ) use ( &$scheduled ) {
			$scheduled[] = $session_id->to_string();
		};

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', null, null, null, null, null, $scheduler ) )->run( $session->get_id() );

		$this->assertSame( ImportSession::STATUS_RUNNING, $this->store->find( $session->get_id() )->get_status() );
		$this->assertSame( array( $session->get_id()->to_string() ), $scheduled );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'nav-menu-item:local:nav:100:100' ) );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'nav-menu-item:local:nav:101:101' ) );
	}

	/**
	 * Completed sessions do not schedule unnecessary follow-up ticks.
	 *
	 * @return void
	 */
	public function test_runner_does_not_schedule_follow_up_tick_for_completed_sessions() {
		$source_file = $this->temporary_file( 'done.md', '# Done' );
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$scheduled   = array();
		$this->store->save( $session );

		$scheduler = function ( ImportSessionId $session_id ) use ( &$scheduled ) {
			$scheduled[] = $session_id->to_string();
		};

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, null, null, null, $scheduler ) )->run( $session->get_id() );

		$this->assertSame( ImportSession::STATUS_DONE, $this->store->find( $session->get_id() )->get_status() );
		$this->assertSame( array(), $scheduled );
	}

	/**
	 * Directory traversal is durable and bounded across runner ticks.
	 *
	 * @return void
	 */
	public function test_runner_discovers_local_directory_tree_incrementally() {
		$root = $this->temporary_directory();
		mkdir( $root . '/chapters' );
		$this->temporary_paths[] = $root . '/chapters';
		file_put_contents( $root . '/chapters/one.md', '# One' );
		$this->temporary_paths[] = $root . '/chapters/one.md';
		file_put_contents( $root . '/index.md', '# Index' );
		$this->temporary_paths[] = $root . '/index.md';

		$session = ImportSession::start_for_source( $root );
		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60 );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );

		$restored = $this->store->find( $session->get_id() );
		$queued   = $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_QUEUED ) );
		$imported = $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ) );
		$skipped  = $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_SKIPPED ) );

		$this->assertSame( 0, $queued );
		$this->assertSame( 2, $imported );
		$this->assertSame( 2, $skipped );
		$this->assertSame(
			array(
				'total'     => 4,
				'completed' => 4,
				'errors'    => 0,
			),
			$restored->get_progress()->to_array()
		);
		$this->assertSame( 'source-discovery:complete', $restored->get_checkpoint()->get_cursor() );
	}

	/**
	 * Empty local directory imports finish once traversal has no remaining work.
	 *
	 * @return void
	 */
	public function test_runner_completes_empty_local_directory_sources() {
		$root      = $this->temporary_directory();
		$session   = ImportSession::start_for_source( $root );
		$scheduled = array();
		$this->store->save( $session );

		$scheduler = function ( ImportSessionId $session_id ) use ( &$scheduled ) {
			$scheduled[] = $session_id->to_string();
		};

		( new ImportRunner( $this->store, 'unit-test', 60, null, new FakePostGateway(), null, null, null, null, null, null, $scheduler ) )->run( $session->get_id() );

		$restored = $this->store->find( $session->get_id() );
		$events   = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertSame( 0, $this->store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 0, $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_QUEUED, ImportSourceItem::STATUS_PROCESSING, ImportSourceItem::STATUS_DISCOVERED ) ) );
		$this->assertContains( 'session.done', $events );
		$this->assertSame( array(), $scheduled );
	}

	/**
	 * Zip archives are expanded into durable source items before document processing.
	 *
	 * @return void
	 */
	public function test_runner_expands_zip_archive_documents() {
		$archive = $this->temporary_zip(
			'book.zip',
			array(
				'chapters/one.md' => "# One\n\nFrom zip.",
				'notes.txt'       => 'Plain zip notes.',
				'payload.bin'     => 'unsupported',
			)
		);
		$session = ImportSession::start_for_source( $archive );
		$posts   = new FakePostGateway();

		$this->temporary_paths[] = sys_get_temp_dir() . '/universal-importer-archives/' . $session->get_id()->to_string();
		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, $posts );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$restored  = $this->store->find( $session->get_id() );
		$skipped   = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_SKIPPED ), 10 );
		$events    = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertCount( 2, $documents );
		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertSame( 2, $posts->count_posts() );
		$this->assertSame( 2, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'post' ) );
		$this->assertSame( 0, $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_QUEUED, ImportSourceItem::STATUS_PROCESSING ) ) );
		$this->assertContains( 'archive.expanded', $events );
		$this->assertContains( 'session.done', $events );
		$this->assertGreaterThanOrEqual( 1, $this->count_archive_container_items( $skipped ) );
		$this->assertGreaterThanOrEqual( 1, $this->count_unsupported_document_items( $skipped ) );
	}

	/**
	 * Unsupported-only archives finish once all source items are terminal.
	 *
	 * @return void
	 */
	public function test_runner_completes_unsupported_only_zip_archives() {
		$archive = $this->temporary_zip(
			'unsupported-only.zip',
			array(
				'payload.bin' => 'unsupported',
			)
		);
		$session = ImportSession::start_for_source( $archive );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, $posts );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );

		$restored = $this->store->find( $session->get_id() );
		$skipped  = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_SKIPPED ), 10 );
		$events   = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertSame( 0, $this->store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 0, $posts->count_posts() );
		$this->assertSame( 0, $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_QUEUED, ImportSourceItem::STATUS_PROCESSING, ImportSourceItem::STATUS_DISCOVERED ) ) );
		$this->assertSame( 1, $this->count_archive_container_items( $skipped ) );
		$this->assertSame( 1, $this->count_unsupported_document_items( $skipped ) );
		$this->assertContains( 'session.done', $events );
	}

	/**
	 * Nested zip archives are resumed across ticks and expanded without duplicate documents.
	 *
	 * @return void
	 */
	public function test_runner_expands_nested_zip_archives_across_ticks() {
		$inner_zip = $this->temporary_zip(
			'inner.zip',
			array(
				'nested/chapter.md' => "# Nested\n\nNested zip body.",
			)
		);
		$outer_zip = $this->temporary_zip(
			'outer.zip',
			array(
				'archives/inner.zip' => file_get_contents( $inner_zip ),
			)
		);
		$session   = ImportSession::start_for_source( $outer_zip );
		$posts     = new FakePostGateway();

		$this->temporary_paths[] = sys_get_temp_dir() . '/universal-importer-archives/' . $session->get_id()->to_string();
		$this->store->save( $session );

		for ( $tick = 0; $tick < 5; ++$tick ) {
			( new ImportRunner( $this->store, 'unit-test', 60, null, $posts ) )->run( $session->get_id() );
		}

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$skipped   = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_SKIPPED ), 10 );

		$this->assertCount( 1, $documents );
		$this->assertSame( 'Nested', $documents[0]->get_title() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertSame( 2, $this->count_archive_container_items( $skipped ) );
	}

	/**
	 * GitHub repository URLs never fall back to zipball downloads.
	 *
	 * @return void
	 */
	public function test_runner_does_not_download_github_repository_zipballs() {
		$fetcher = new FakeRemoteArchiveFetcher( null, 'Archive fetcher should not be called.' );
		$session = ImportSession::start_for_source( 'https://github.com/example/repository/tree/main' );
		$posts   = new FakePostGateway();
		$cache   = new ImportCacheDirectory( $this->temporary_directory() . '/managed-cache' );

		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, $fetcher, null, null, $cache );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$failed    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ), 10 );
		$events    = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertCount( 0, $documents );
		$this->assertSame( 0, $posts->count_posts() );
		$this->assertSame( array(), $fetcher->get_requested_urls() );
		$this->assertCount( 1, $failed );
		$this->assertSame( false, $failed[0]->get_metadata()['github_zipball'] );
		$this->assertContains( 'github.traversal_failed', $events );
		$this->assertNotContains( 'github.archive_downloaded', $events );
		$this->assertNotContains( 'archive.expanded', $events );
	}

	/**
	 * GitHub tree URLs do not download zipballs when sparse Git is unavailable.
	 *
	 * @return void
	 */
	public function test_runner_does_not_download_github_tree_subpath_zipballs() {
		$fetcher = new FakeRemoteArchiveFetcher( null, 'Archive fetcher should not be called.' );
		$session = ImportSession::start_for_source( 'https://github.com/example/repository/tree/main/docs' );
		$posts   = new FakePostGateway();
		$cache   = new ImportCacheDirectory( $this->temporary_directory() . '/managed-cache' );

		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, $fetcher, null, null, $cache );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$titles    = array_map(
			function ( $document ) {
				return $document->get_title();
			},
			$documents
		);
		sort( $titles );
		$items   = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED, ImportSourceItem::STATUS_SKIPPED ), 20 );
		$failed  = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ), 10 );
		$events  = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);
		$archive = $this->find_github_archive_item( $items );

		$this->assertSame( array(), $titles );
		$this->assertSame( 0, $posts->count_posts() );
		$this->assertSame( array(), $fetcher->get_requested_urls() );
		$this->assertCount( 1, $failed );
		$this->assertNull( $archive );
		$this->assertContains( 'github.traversal_failed', $events );
		$this->assertNotContains( 'github.archive_downloaded', $events );
		$this->assertNotContains( 'archive.expanded', $events );
	}

	/**
	 * Missing GitHub traversal support is durable and actionable.
	 *
	 * @return void
	 */
	public function test_runner_records_github_traversal_failures_without_zipballs() {
		$fetcher = new FakeRemoteArchiveFetcher( null, 'GitHub archive download returned HTTP 404.' );
		$session = ImportSession::start_for_source( 'https://github.com/example/missing?ref=release/1.0' );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, null, null, null, $fetcher ) )->run( $session->get_id() );

		$failed = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ), 1 );
		$events = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertCount( 1, $failed );
		$this->assertStringContainsString( 'do not use tree/blob', $failed[0]->get_metadata()['error'] );
		$this->assertSame( 'release/1.0', $failed[0]->get_metadata()['github_ref'] );
		$this->assertSame( array(), $fetcher->get_requested_urls() );
		$this->assertContains( 'github.traversal_failed', $events );
		$this->assertNotContains( 'github.archive_failed', $events );
	}

	/**
	 * Previously failed GitHub traversal does not retry zipball downloads.
	 *
	 * @return void
	 */
	public function test_runner_does_not_retry_failed_github_traversal_as_zipball_download() {
		$cache   = new ImportCacheDirectory( $this->temporary_directory() . '/managed-cache' );
		$session = ImportSession::start_for_source( 'https://github.com/example/repository?ref=main' );
		$this->store->save( $session );

		$failed_fetcher = new FakeRemoteArchiveFetcher( null, 'Temporary GitHub archive outage.' );
		( new ImportRunner( $this->store, 'unit-test', 60, null, null, null, null, $failed_fetcher, null, null, $cache ) )->run( $session->get_id() );

		$failed = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ), 10 );
		$this->assertCount( 1, $failed );
		$this->assertStringContainsString( 'do not use tree/blob', $failed[0]->get_metadata()['error'] );

		$posts           = new FakePostGateway();
		$success_fetcher = new FakeRemoteArchiveFetcher( null, 'Archive fetcher should not be called.' );
		$runner          = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, $success_fetcher, null, null, $cache );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );

		$remaining_failed = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ), 10 );
		$documents        = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$items            = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED, ImportSourceItem::STATUS_SKIPPED ), 20 );
		$github_archive   = $this->find_github_archive_item( $items );
		$events           = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertCount( 1, $remaining_failed );
		$this->assertCount( 0, $documents );
		$this->assertSame( 0, $posts->count_posts() );
		$this->assertSame( array(), $success_fetcher->get_requested_urls() );
		$this->assertNull( $github_archive );
		$this->assertContains( 'github.traversal_failed', $events );
		$this->assertNotContains( 'github.archive_retrying', $events );
		$this->assertNotContains( 'github.archive_downloaded', $events );
		$this->assertNotContains( 'archive.expanded', $events );
	}

	/**
	 * GitHub traversal failures do not call the archive fetcher even with an unavailable cache.
	 *
	 * @return void
	 */
	public function test_runner_does_not_download_zipball_when_cache_directory_is_unavailable() {
		$cache   = ImportCacheDirectory::from_wordpress_upload_dir( array( 'error' => 'Upload path is not writable.' ) );
		$fetcher = new FakeRemoteArchiveFetcher( null );
		$session = ImportSession::start_for_source( 'https://github.com/example/repository' );
		$this->store->save( $session );

		$summary = ( new ImportRunner( $this->store, 'unit-test', 60, null, null, null, null, $fetcher, null, null, $cache ) )->run( $session->get_id() );

		$failed = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ), 1 );
		$events = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( 1, $summary['processed'] );
		$this->assertCount( 1, $failed );
		$this->assertStringContainsString( 'do not use tree/blob', $failed[0]->get_metadata()['error'] );
		$this->assertSame( array(), $fetcher->get_requested_urls() );
		$this->assertContains( 'github.traversal_failed', $events );
		$this->assertNotContains( 'github.archive_failed', $events );
	}

	/**
	 * WordPress REST sites are paginated into prepared documents.
	 *
	 * @return void
	 */
	public function test_runner_imports_remote_wordpress_rest_posts_and_pages() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_json(
			'https://source.example.test/wp-json/',
			array(
				'namespaces' => array( 'wp/v2' ),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/pages?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			array(
				array(
					'id'      => 7,
					'link'    => 'https://source.example.test/about/',
					'title'   => array( 'rendered' => 'About Source' ),
					'content' => array( 'rendered' => '<p>About body.</p><script>alert("x")</script>' ),
				),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			array(
				array(
					'id'      => 11,
					'link'    => 'https://source.example.test/post/',
					'title'   => array( 'rendered' => 'Remote Post' ),
					'content' => array( 'rendered' => '<p>Post body.</p>' ),
				),
			)
		);
		$session = ImportSession::start_for_source( 'https://source.example.test/' );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, $fetcher );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$events    = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertCount( 2, $documents );
		$this->assertSame( 'wp-rest', $documents[0]->get_format() );
		$this->assertSame( 'About Source', $documents[0]->get_title() );
		$this->assertSame( 'structured', $documents[0]->get_metadata()['html_block_conversion'] );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $documents[0]->get_block_markup() );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $documents[0]->get_block_markup() );
		$this->assertStringNotContainsString( '<script', $documents[0]->get_block_markup() );
		$this->assertSame( 2, $posts->count_posts() );
		$this->assertContains( 'remote.wp_rest_detected', $events );
		$this->assertContains( 'remote.wp_rest_complete', $events );
		$this->assertContains( 'https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia', $fetcher->get_requested_urls() );
	}

	/**
	 * Embedded REST authors and terms are staged as prepared document metadata.
	 *
	 * @return void
	 */
	public function test_runner_stages_remote_wordpress_rest_author_and_terms() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_json(
			'https://source.example.test/wp-json/',
			array(
				'namespaces' => array( 'wp/v2' ),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/types?context=view',
			array(
				'post' => array(
					'slug'      => 'post',
					'rest_base' => 'posts',
					'viewable'  => true,
				),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			array(
				array(
					'id'         => 31,
					'author'     => 9,
					'categories' => array( 4 ),
					'tags'       => array( 12 ),
					'link'       => 'https://source.example.test/story/',
					'title'      => array( 'rendered' => 'Relationship Story' ),
					'content'    => array( 'rendered' => '<p>Story body.</p>' ),
					'_embedded'  => array(
						'author'  => array(
							array(
								'id'   => 9,
								'name' => 'Ada Editor',
								'slug' => 'ada-editor',
								'link' => 'https://source.example.test/author/ada-editor/',
							),
						),
						'wp:term' => array(
							array(
								array(
									'id'       => 4,
									'taxonomy' => 'category',
									'name'     => 'Research',
									'slug'     => 'research',
									'link'     => 'https://source.example.test/category/research/',
								),
							),
							array(
								array(
									'id'       => 12,
									'taxonomy' => 'post_tag',
									'name'     => 'Migration',
									'slug'     => 'migration',
									'link'     => 'https://source.example.test/tag/migration/',
								),
							),
						),
					),
				),
			)
		);
		$session = ImportSession::start_for_source( 'https://source.example.test/' );
		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, new FakePostGateway(), null, null, null, $fetcher );
		$runner->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$items     = $this->store->list_source_items_by_statuses( $session->get_id(), array( 'imported' ), 10 );
		$metadata  = $documents[0]->get_metadata();

		$this->assertCount( 1, $documents );
		$this->assertSame( 9, $metadata['remote_author_id'] );
		$this->assertSame( 'Ada Editor', $metadata['remote_author']['name'] );
		$this->assertSame( 'research', $metadata['remote_terms']['category'][0]['slug'] );
		$this->assertSame( 'Migration', $metadata['remote_terms']['post_tag'][0]['name'] );
		$this->assertSame( array( 4 ), $metadata['remote_term_ids']['category'] );
		$this->assertSame( array( 12 ), $metadata['remote_term_ids']['post_tag'] );
		$this->assertSame( 9, $items[0]->get_metadata()['remote_author_id'] );
		$this->assertSame( 'research', $items[0]->get_metadata()['remote_terms']['category'][0]['slug'] );
		$this->assertContains( 'https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia', $fetcher->get_requested_urls() );
	}

	/**
	 * Remote REST comments are staged durably against their imported document source key.
	 *
	 * @return void
	 */
	public function test_runner_stages_remote_wordpress_rest_comments_across_ticks() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_json(
			'https://source.example.test/wp-json/',
			array(
				'namespaces' => array( 'wp/v2' ),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/types?context=view',
			array(
				'post' => array(
					'slug'      => 'post',
					'rest_base' => 'posts',
					'viewable'  => true,
				),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			array(
				array(
					'id'      => 31,
					'link'    => 'https://source.example.test/story/',
					'title'   => array( 'rendered' => 'Commented Story' ),
					'content' => array( 'rendered' => '<p>Story body.</p>' ),
				),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/comments?context=view&post=31&per_page=25&page=1&order=asc&orderby=date_gmt',
			array(
				array(
					'id'          => 101,
					'post'        => 31,
					'parent'      => 0,
					'author'      => 7,
					'author_name' => 'First Reader',
					'author_url'  => 'https://reader.example.test/',
					'content'     => array( 'rendered' => '<p>First comment.</p><script>alert("x")</script>' ),
					'date'        => '2025-01-01T10:00:00',
					'date_gmt'    => '2025-01-01T10:00:00',
					'link'        => 'https://source.example.test/story/#comment-101',
					'status'      => 'approved',
					'type'        => 'comment',
				),
				array(
					'id'          => 102,
					'post'        => 31,
					'parent'      => 101,
					'author_name' => 'Reply Reader',
					'content'     => array( 'rendered' => '<p>Nested reply.</p>' ),
					'date_gmt'    => '2025-01-01T11:00:00',
				),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/comments?context=view&post=31&per_page=25&page=2&order=asc&orderby=date_gmt',
			array(
				array(
					'id'          => 103,
					'post'        => 31,
					'parent'      => 0,
					'author_name' => 'Late Reader',
					'content'     => array( 'rendered' => '<p>Second page comment.</p>' ),
					'date_gmt'    => '2025-01-02T10:00:00',
				),
			)
		);
		$session = ImportSession::start_for_source( 'https://source.example.test/' );
		$this->store->save( $session );

		$comments = new FakeCommentGateway();
		$runner   = new ImportRunner( $this->store, 'unit-test', 60, null, new FakePostGateway(), null, null, null, $fetcher, $comments );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$metadata  = $documents[0]->get_metadata();
		$item      = $this->store->find_source_item( $session->get_id(), $documents[0]->get_source_item_key() );
		$root      = $this->store->find_source_item( $session->get_id(), 'remote:' . hash( 'sha256', 'https://source.example.test/' ) );
		$events    = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertCount( 1, $documents );
		$this->assertCount( 3, $metadata['remote_comments'] );
		$this->assertSame( 3, $metadata['remote_comment_count'] );
		$this->assertTrue( $metadata['remote_comments_complete'] );
		$this->assertSame( 101, $metadata['remote_comments'][1]['remote_parent_id'] );
		$this->assertSame( $documents[0]->get_source_item_key(), $metadata['remote_comments'][0]['source_item_key'] );
		$this->assertStringNotContainsString( '<script', $metadata['remote_comments'][0]['content'] );
		$this->assertSame( 3, $item->get_metadata()['remote_comment_count'] );
		$this->assertTrue( $item->get_metadata()['remote_comments_complete'] );
		$this->assertTrue( $root->get_metadata()['remote_comments_complete'] );
		$this->assertSame( 3, $comments->count_comments() );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'comment:' . $documents[0]->get_source_item_key() . ':101' ) );
		$this->assertContains( 'remote.wp_rest_comments_staged', $events );
		$this->assertContains( 'comment.created', $events );
		$this->assertContains( 'remote.wp_rest_comments_complete', $events );
		$this->assertContains( 'remote.wp_rest_complete', $events );
	}

	/**
	 * Runner ticks apply resolved REST relationship mapping decisions to existing drafts.
	 *
	 * @return void
	 */
	public function test_runner_applies_resolved_rest_relationship_mapping_decisions() {
		$session  = ImportSession::start_for_source( 'https://source.example.test/' );
		$posts    = new FakePostGateway();
		$document = new ImportPreparedDocument(
			$session->get_id(),
			'remote:book:9',
			'wp-rest',
			'Remote Book',
			'<!-- wp:paragraph --><p>Remote Book</p><!-- /wp:paragraph -->',
			1,
			'hash-book',
			array( 'relative_path' => 'remote-book' )
		);

		$this->store->save( $session );
		$posts->insert_or_update( $document );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				ImportRelationshipMappingDecision::decision_key( 'remote:book:9' ),
				'Map the remote REST author and taxonomy relationships for imported draft post 1.',
				array(
					'post_id'         => 1,
					'source_item_key' => 'remote:book:9',
				)
			)->resolve(
				array(
					'author' => array(
						'local_user_id' => 42,
					),
					'terms'  => array(
						'genre' => array(
							array(
								'local_taxonomy' => 'category',
								'local_term_id'  => 15,
							),
						),
					),
				)
			)
		);

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts ) )->run( $session->get_id() );

		$events = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 5 )
		);

		$this->assertSame( 42, $posts->get_post( 1 )['post_author'] );
		$this->assertSame( array( 15 ), $posts->get_post( 1 )['terms']['category'] );
		$this->assertContains( 'post.relationships_mapping_applied', $events );
	}

	/**
	 * REST roots can be discovered from WordPress Link headers when /wp-json/ is unavailable.
	 *
	 * @return void
	 */
	public function test_runner_discovers_remote_wordpress_rest_root_from_link_header() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_text(
			'https://source.example.test/articles/welcome/',
			'<html><head><title>Welcome</title></head><body>Fallback body.</body></html>',
			array(
				'Link' => '<https://source.example.test/index.php?rest_route=/>; rel="https://api.w.org/"',
			)
		);
		$fetcher->add_json(
			'https://source.example.test/index.php?rest_route=/',
			array(
				'namespaces' => array( 'wp/v2' ),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/index.php?rest_route=/wp/v2/types&context=view',
			array(
				'post' => array(
					'slug'      => 'post',
					'rest_base' => 'posts',
					'viewable'  => true,
				),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/index.php?rest_route=/wp/v2/posts&context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			array(
				array(
					'id'      => 77,
					'link'    => 'https://source.example.test/articles/welcome/',
					'title'   => array( 'rendered' => 'Header Discovered Post' ),
					'content' => array( 'rendered' => '<p>Imported through plain permalinks.</p>' ),
				),
			)
		);
		$session = ImportSession::start_for_source( 'https://source.example.test/articles/welcome/' );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, $fetcher );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$root      = $this->store->find_source_item( $session->get_id(), 'remote:' . hash( 'sha256', 'https://source.example.test/articles/welcome/' ) );
		$events    = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);
		$requests  = $fetcher->get_requested_urls();

		$this->assertCount( 1, $documents );
		$this->assertSame( 'Header Discovered Post', $documents[0]->get_title() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertSame( 'https://source.example.test/index.php?rest_route=/', $root->get_metadata()['remote_rest_url'] );
		$this->assertSame( 'link-header', $root->get_metadata()['remote_rest_detected_by'] );
		$this->assertContains( 'remote.wp_rest_detected', $events );
		$this->assertContains( 'https://source.example.test/wp-json/', $requests );
		$this->assertContains( 'https://source.example.test/index.php?rest_route=/wp/v2/posts&context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia', $requests );
	}

	/**
	 * HTML REST discovery links are used when header discovery is unavailable.
	 *
	 * @return void
	 */
	public function test_runner_discovers_remote_wordpress_rest_root_from_html_link() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_text(
			'https://source.example.test/custom-permalink/',
			'<html><head><link rel="https://api.w.org/" href="https://source.example.test/custom-api/wp-json/" /></head><body>Fallback body.</body></html>'
		);
		$fetcher->add_json(
			'https://source.example.test/custom-api/wp-json/',
			array(
				'namespaces' => array( 'wp/v2' ),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/custom-api/wp-json/wp/v2/types?context=view',
			array()
		);
		$fetcher->add_json(
			'https://source.example.test/custom-api/wp-json/wp/v2/pages?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			array()
		);
		$fetcher->add_json(
			'https://source.example.test/custom-api/wp-json/wp/v2/posts?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			array(
				array(
					'id'      => 88,
					'link'    => 'https://source.example.test/custom-permalink/',
					'title'   => array( 'rendered' => 'HTML Discovered Post' ),
					'content' => array( 'rendered' => '<p>Imported from HTML discovery.</p>' ),
				),
			)
		);
		$session = ImportSession::start_for_source( 'https://source.example.test/custom-permalink/' );
		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, new FakePostGateway(), null, null, null, $fetcher );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$root      = $this->store->find_source_item( $session->get_id(), 'remote:' . hash( 'sha256', 'https://source.example.test/custom-permalink/' ) );

		$this->assertCount( 1, $documents );
		$this->assertSame( 'HTML Discovered Post', $documents[0]->get_title() );
		$this->assertSame( 'https://source.example.test/custom-api/wp-json/', $root->get_metadata()['remote_rest_url'] );
		$this->assertSame( 'html-link', $root->get_metadata()['remote_rest_detected_by'] );
		$this->assertContains( 'https://source.example.test/custom-api/wp-json/wp/v2/posts?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia', $fetcher->get_requested_urls() );
	}

	/**
	 * Cross-host REST discovery links are ignored so hostile pages cannot pivot traversal.
	 *
	 * @return void
	 */
	public function test_runner_ignores_cross_host_rest_discovery_links() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_text(
			'https://source.example.test/hostile/',
			'<html><head><title>Hostile Link</title><link rel="https://api.w.org/" href="https://evil.example.test/wp-json/" /></head><body><h1>Source Body</h1><p>Import the requested page only.</p></body></html>',
			array(
				'Link' => '<https://api.evil.example.test/wp-json/>; rel="https://api.w.org/"',
			)
		);
		$session = ImportSession::start_for_source( 'https://source.example.test/hostile/' );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, $fetcher ) )->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$root      = $this->store->find_source_item( $session->get_id(), 'remote:' . hash( 'sha256', 'https://source.example.test/hostile/' ) );
		$requests  = $fetcher->get_requested_urls();

		$this->assertCount( 1, $documents );
		$this->assertSame( 'remote-html', $documents[0]->get_format() );
		$this->assertSame( 'Hostile Link', $documents[0]->get_title() );
		$this->assertSame( 'single-url', $root->get_metadata()['remote_mode'] );
		$this->assertContains( 'https://source.example.test/wp-json/', $requests );
		$this->assertNotContains( 'https://evil.example.test/wp-json/', $requests );
		$this->assertNotContains( 'https://api.evil.example.test/wp-json/', $requests );
		$this->assertSame( 1, $posts->count_posts() );
	}

	/**
	 * Private remote sources fail with actionable authentication diagnostics.
	 *
	 * @return void
	 */
	public function test_runner_records_remote_authentication_failure() {
		$message = 'Remote URL request returned HTTP 401. If this source requires authentication, configure UNIVERSAL_IMPORTER_REMOTE_AUTH_HOSTS with the exact host.';
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_json_error( 'https://private.example.test/wp-json/', $message );
		$fetcher->add_text_error( 'https://private.example.test/', $message );
		$session = ImportSession::start_for_source( 'https://private.example.test/' );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		$summary = ( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, $fetcher ) )->run( $session->get_id() );
		$failed  = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ), 5 );
		$events  = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( 1, $summary['processed'] );
		$this->assertCount( 1, $failed );
		$this->assertStringContainsString( 'UNIVERSAL_IMPORTER_REMOTE_AUTH_HOSTS', $failed[0]->get_metadata()['error'] );
		$this->assertContains( 'remote.failed', $events );
		$this->assertSame( 0, $posts->count_posts() );
	}

	/**
	 * Malformed REST indexes fall back to importing the requested source document.
	 *
	 * @return void
	 */
	public function test_runner_falls_back_to_remote_html_after_malformed_rest_index() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_json_error( 'https://source.example.test/wp-json/', 'Remote JSON response could not be decoded.' );
		$fetcher->add_text(
			'https://source.example.test/article/',
			'<html><head><title>Malformed REST Fallback</title></head><body><h1>Fallback</h1><p>Use the source HTML.</p></body></html>'
		);
		$session = ImportSession::start_for_source( 'https://source.example.test/article/' );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, $fetcher ) )->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$root      = $this->store->find_source_item( $session->get_id(), 'remote:' . hash( 'sha256', 'https://source.example.test/article/' ) );

		$this->assertCount( 1, $documents );
		$this->assertSame( 'remote-html', $documents[0]->get_format() );
		$this->assertSame( 'Malformed REST Fallback', $documents[0]->get_title() );
		$this->assertStringContainsString( 'Remote JSON response could not be decoded.', $root->get_metadata()['remote_rest_fallback'] );
		$this->assertSame( 1, $posts->count_posts() );
	}

	/**
	 * Later REST pagination failures are observable and traversal continues safely.
	 *
	 * @return void
	 */
	public function test_runner_records_late_rest_pagination_failures() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_json(
			'https://source.example.test/wp-json/',
			array(
				'namespaces' => array( 'wp/v2' ),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/types?context=view',
			array(
				'post' => array(
					'slug'      => 'post',
					'rest_base' => 'posts',
					'viewable'  => true,
				),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			array(
				array(
					'id'      => 11,
					'link'    => 'https://source.example.test/post/',
					'title'   => array( 'rendered' => 'First Page Post' ),
					'content' => array( 'rendered' => '<p>Post body.</p>' ),
				),
			)
		);
		$fetcher->add_json_error(
			'https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=2&_embed=author,wp:term,wp:featuredmedia',
			'Remote URL request returned HTTP 500.'
		);
		$session = ImportSession::start_for_source( 'https://source.example.test/' );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, $fetcher );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );

		$root   = $this->store->find_source_item( $session->get_id(), 'remote:' . hash( 'sha256', 'https://source.example.test/' ) );
		$events = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( 'complete', $root->get_metadata()['remote_status'] );
		$this->assertSame( 'posts', $root->get_metadata()['remote_rest_page_warnings'][0]['endpoint'] );
		$this->assertSame( 2, $root->get_metadata()['remote_rest_page_warnings'][0]['page'] );
		$this->assertSame( 'Remote URL request returned HTTP 500.', $root->get_metadata()['remote_rest_page_warnings'][0]['error'] );
		$this->assertContains( 'remote.wp_rest_page_unavailable', $events );
		$this->assertSame( 1, $posts->count_posts() );
	}

	/**
	 * First-page REST collection failures are warnings and do not block later collections.
	 *
	 * @return void
	 */
	public function test_runner_skips_unavailable_first_rest_collection_pages() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_json(
			'https://source.example.test/wp-json/',
			array(
				'namespaces' => array( 'wp/v2' ),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/types?context=view',
			array(
				'page' => array(
					'slug'      => 'page',
					'rest_base' => 'pages',
					'viewable'  => true,
				),
				'post' => array(
					'slug'      => 'post',
					'rest_base' => 'posts',
					'viewable'  => true,
				),
			)
		);
		$fetcher->add_json_error(
			'https://source.example.test/wp-json/wp/v2/pages?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			'Remote URL request returned HTTP 401.'
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			array(
				array(
					'id'      => 12,
					'link'    => 'https://source.example.test/post/',
					'title'   => array( 'rendered' => 'Public Post' ),
					'content' => array( 'rendered' => '<p>Post body after an unavailable pages collection.</p>' ),
				),
			)
		);
		$session = ImportSession::start_for_source( 'https://source.example.test/' );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, $fetcher ) )->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$root      = $this->store->find_source_item( $session->get_id(), 'remote:' . hash( 'sha256', 'https://source.example.test/' ) );
		$events    = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertCount( 1, $documents );
		$this->assertSame( 'Public Post', $documents[0]->get_title() );
		$this->assertSame( 'pages', $root->get_metadata()['remote_rest_page_warnings'][0]['endpoint'] );
		$this->assertSame( 1, $root->get_metadata()['remote_rest_page_warnings'][0]['page'] );
		$this->assertSame( 'Remote URL request returned HTTP 401.', $root->get_metadata()['remote_rest_page_warnings'][0]['error'] );
		$this->assertContains( 'remote.wp_rest_page_unavailable', $events );
		$this->assertSame( 1, $posts->count_posts() );
	}

	/**
	 * Remote REST rate limits preserve the cursor and defer retries instead of failing the source.
	 *
	 * @return void
	 */
	public function test_runner_defers_remote_rest_rate_limits_with_backoff_metadata() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_json(
			'https://source.example.test/wp-json/',
			array(
				'namespaces' => array( 'wp/v2' ),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/types?context=view',
			array(
				'post' => array(
					'slug'      => 'post',
					'rest_base' => 'posts',
					'viewable'  => true,
				),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			array(
				array(
					'id'      => 11,
					'link'    => 'https://source.example.test/post/',
					'title'   => array( 'rendered' => 'First Page Post' ),
					'content' => array( 'rendered' => '<p>Post body.</p>' ),
				),
			)
		);
		$rate_limited_url = 'https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=2&_embed=author,wp:term,wp:featuredmedia';
		$fetcher->add_json_rate_limit( $rate_limited_url, 429, '120', 120 );
		$session = ImportSession::start_for_source( 'https://source.example.test/' );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, $fetcher );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );

		$root      = $this->store->find_source_item( $session->get_id(), 'remote:' . hash( 'sha256', 'https://source.example.test/' ) );
		$metadata  = $root->get_metadata();
		$events    = $this->store->list_events( $session->get_id(), 20 );
		$event_map = array();

		foreach ( $events as $event ) {
			$event_map[ $event->get_type() ] = $event;
		}

		$request_counts = array_count_values( $fetcher->get_requested_urls() );

		$this->assertSame( 'rate-limited', $metadata['remote_status'] );
		$this->assertSame( 0, $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ) ) );
		$this->assertSame( 0, $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_SKIPPED ) ) );
		$this->assertSame( 2, $metadata['endpoint_page'] );
		$this->assertSame( 429, $metadata['remote_rate_limit']['status_code'] );
		$this->assertSame( '120', $metadata['remote_rate_limit']['retry_after_header'] );
		$this->assertSame( 120, $metadata['remote_rate_limit']['retry_after_seconds'] );
		$this->assertArrayHasKey( 'next_retry_at', $metadata['remote_rate_limit'] );
		$this->assertSame( 1, $request_counts[ $rate_limited_url ] );
		$this->assertSame( ImportProgressEvent::LEVEL_WARNING, $event_map['remote.rate_limited']->get_level() );
		$this->assertSame( 1, $posts->count_posts() );
	}

	/**
	 * Associative REST collection payloads are skipped with diagnostics instead of being treated as item lists.
	 *
	 * @return void
	 */
	public function test_runner_skips_non_list_rest_collection_payloads() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_json(
			'https://source.example.test/wp-json/',
			array(
				'namespaces' => array( 'wp/v2' ),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/types?context=view',
			array(
				'page' => array(
					'slug'      => 'page',
					'rest_base' => 'pages',
					'viewable'  => true,
				),
				'post' => array(
					'slug'      => 'post',
					'rest_base' => 'posts',
					'viewable'  => true,
				),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/pages?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			array(
				'code'    => 'rest_invalid_param',
				'message' => 'Unexpected but HTTP 200 error payload.',
				'data'    => array( 'status' => 400 ),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			array(
				array(
					'id'      => 22,
					'link'    => 'https://source.example.test/post/',
					'title'   => array( 'rendered' => 'Valid Post' ),
					'content' => array( 'rendered' => '<p>Imported after a malformed pages payload.</p>' ),
				),
			)
		);
		$session = ImportSession::start_for_source( 'https://source.example.test/' );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, $fetcher ) )->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$root      = $this->store->find_source_item( $session->get_id(), 'remote:' . hash( 'sha256', 'https://source.example.test/' ) );
		$events    = $this->store->list_events( $session->get_id(), 20 );
		$event_map = array();

		foreach ( $events as $event ) {
			$event_map[ $event->get_type() ] = $event;
		}

		$this->assertCount( 1, $documents );
		$this->assertSame( 'Valid Post', $documents[0]->get_title() );
		$this->assertSame( 'pages', $root->get_metadata()['remote_rest_page_warnings'][0]['endpoint'] );
		$this->assertSame( 'Remote WordPress REST collection endpoint returned a non-list payload.', $root->get_metadata()['remote_rest_page_warnings'][0]['error'] );
		$this->assertSame( ImportProgressEvent::LEVEL_WARNING, $event_map['remote.wp_rest_page_unavailable']->get_level() );
		$this->assertSame( 'object:code,message,data', $event_map['remote.wp_rest_page_unavailable']->get_context()['payload_shape'] );
		$this->assertSame( 1, $posts->count_posts() );
	}

	/**
	 * Associative REST comment payloads complete comment staging with an observable warning.
	 *
	 * @return void
	 */
	public function test_runner_marks_non_list_rest_comment_payloads_unavailable() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_json(
			'https://source.example.test/wp-json/',
			array(
				'namespaces' => array( 'wp/v2' ),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/types?context=view',
			array(
				'post' => array(
					'slug'      => 'post',
					'rest_base' => 'posts',
					'viewable'  => true,
				),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			array(
				array(
					'id'      => 31,
					'link'    => 'https://source.example.test/story/',
					'title'   => array( 'rendered' => 'Comment Payload Story' ),
					'content' => array( 'rendered' => '<p>Story body.</p>' ),
				),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=2&_embed=author,wp:term,wp:featuredmedia',
			array()
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/comments?context=view&post=31&per_page=25&page=1&order=asc&orderby=date_gmt',
			array(
				'code'    => 'rest_forbidden',
				'message' => 'Comments are not exposed.',
				'data'    => array( 'status' => 403 ),
			)
		);
		$session = ImportSession::start_for_source( 'https://source.example.test/' );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, $fetcher, new FakeCommentGateway() );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$item      = $this->store->find_source_item( $session->get_id(), $documents[0]->get_source_item_key() );
		$events    = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertCount( 1, $documents );
		$this->assertTrue( $documents[0]->get_metadata()['remote_comments_complete'] );
		$this->assertSame( 'Remote WordPress REST comments endpoint returned a non-list payload.', $documents[0]->get_metadata()['remote_comments_error'] );
		$this->assertSame( 0, $documents[0]->get_metadata()['remote_comment_count'] );
		$this->assertTrue( $item->get_metadata()['remote_comments_complete'] );
		$this->assertSame( 'Remote WordPress REST comments endpoint returned a non-list payload.', $item->get_metadata()['remote_comments_error'] );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertContains( 'remote.wp_rest_comments_unavailable', $events );
		$this->assertContains( 'remote.wp_rest_complete', $events );
	}

	/**
	 * REST post type discovery imports custom public collections using their REST base.
	 *
	 * @return void
	 */
	public function test_runner_imports_remote_wordpress_rest_custom_post_types() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_json(
			'https://source.example.test/wp-json/',
			array(
				'namespaces' => array( 'wp/v2' ),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/types?context=view',
			array(
				'post'       => array(
					'slug'      => 'post',
					'rest_base' => 'posts',
					'viewable'  => true,
				),
				'page'       => array(
					'slug'      => 'page',
					'rest_base' => 'pages',
					'viewable'  => true,
				),
				'book'       => array(
					'slug'      => 'book',
					'rest_base' => 'library-books',
					'viewable'  => true,
				),
				'attachment' => array(
					'slug'      => 'attachment',
					'rest_base' => 'media',
					'viewable'  => true,
				),
				'private'    => array(
					'slug'      => 'private',
					'rest_base' => 'private-items',
					'viewable'  => false,
				),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/pages?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			array(
				array(
					'id'      => 7,
					'link'    => 'https://source.example.test/about/',
					'title'   => array( 'rendered' => 'About Source' ),
					'content' => array( 'rendered' => '<p>About body.</p>' ),
				),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			array()
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/library-books?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			array(
				array(
					'id'      => 42,
					'link'    => 'https://source.example.test/books/answer/',
					'title'   => array( 'rendered' => 'Custom Book' ),
					'content' => array( 'rendered' => '<p>Custom body.</p><script>alert("x")</script>' ),
				),
			)
		);
		$session = ImportSession::start_for_source( 'https://source.example.test/' );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, $fetcher );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$events    = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);
		$requests  = $fetcher->get_requested_urls();

		$this->assertCount( 2, $documents );
		$this->assertSame( 'Custom Book', $documents[1]->get_title() );
		$this->assertSame( 'library-books', $documents[1]->get_metadata()['remote_rest_endpoint'] );
		$this->assertStringNotContainsString( '<script', $documents[1]->get_block_markup() );
		$this->assertSame( 2, $posts->count_posts() );
		$this->assertContains( 'remote.wp_rest_types_detected', $events );
		$this->assertContains( 'remote.wp_rest_complete', $events );
		$this->assertContains( 'https://source.example.test/wp-json/wp/v2/types?context=view', $requests );
		$this->assertContains( 'https://source.example.test/wp-json/wp/v2/library-books?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia', $requests );
		$this->assertNotContains( 'https://source.example.test/wp-json/wp/v2/media?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia', $requests );
		$this->assertNotContains( 'https://source.example.test/wp-json/wp/v2/private-items?context=view&per_page=25&page=1', $requests );
	}

	/**
	 * Successful REST collection pagination continues through page two for every public endpoint.
	 *
	 * @return void
	 */
	public function test_runner_imports_successful_second_pages_from_remote_wordpress_rest_collections() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_json(
			'https://source.example.test/wp-json/',
			array(
				'namespaces' => array( 'wp/v2' ),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/types?context=view',
			array(
				'post' => array(
					'slug'      => 'post',
					'rest_base' => 'posts',
					'viewable'  => true,
				),
				'page' => array(
					'slug'      => 'page',
					'rest_base' => 'pages',
					'viewable'  => true,
				),
				'book' => array(
					'slug'      => 'book',
					'rest_base' => 'library-books',
					'viewable'  => true,
				),
			)
		);

		$collections = array(
			'pages'         => array( 'Page One', 'Page Two' ),
			'posts'         => array( 'Post One', 'Post Two' ),
			'library-books' => array( 'Book One', 'Book Two' ),
		);
		foreach ( $collections as $endpoint => $titles ) {
			foreach ( $titles as $page_index => $title ) {
				$page = $page_index + 1;
				$body = '<p>' . $title . ' body.</p>';
				$item = array(
					'id'      => ( 100 * $page ) + strlen( $title ),
					'link'    => 'https://source.example.test/' . $endpoint . '/page-' . $page . '/',
					'title'   => array( 'rendered' => $title ),
					'content' => array( 'rendered' => $body ),
				);

				if ( 'posts' === $endpoint && 2 === $page ) {
					$item['featured_media'] = 44;
					$item['content']        = array(
						'rendered' => '<p><a href="https://source.example.test/page-two-link">Page two link</a></p>',
					);
					$item['_embedded']      = array(
						'wp:featuredmedia' => array(
							array(
								'id'         => 44,
								'source_url' => 'https://source.example.test/wp-content/uploads/page-two.jpg',
								'alt_text'   => 'Page two alt',
							),
						),
					);
				}

				$fetcher->add_json(
					'https://source.example.test/wp-json/wp/v2/' . $endpoint . '?context=view&per_page=25&page=' . $page . '&_embed=author,wp:term,wp:featuredmedia',
					array( $item )
				);
			}

			$fetcher->add_json(
				'https://source.example.test/wp-json/wp/v2/' . $endpoint . '?context=view&per_page=25&page=3&_embed=author,wp:term,wp:featuredmedia',
				array()
			);
		}

		$session = ImportSession::start_for_source( 'https://source.example.test/' );
		$posts   = new FakePostGateway();
		$media   = new FakeMediaGateway();

		$media->add_remote_media( 'https://source.example.test/wp-content/uploads/page-two.jpg', 'page-two-image-bytes' );
		$this->store->save( $session );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Confirm first-party domains before URL rewriting.',
				array( 'domains' => array( 'source.example.test' ) )
			)->resolve( array( 'confirmed_domains' => array( 'source.example.test' ) ) )
		);

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, $fetcher );
		for ( $tick = 0; $tick < 14; ++$tick ) {
			$runner->run( $session->get_id() );
		}

		$requests      = $fetcher->get_requested_urls();
		$documents     = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$references    = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );
		$restored      = $this->store->find( $session->get_id() );
		$page_two      = null;
		$page_two_post = null;
		$post_count    = $posts->count_posts();

		foreach ( $documents as $document ) {
			if ( 'Post Two' === $document->get_title() ) {
				$page_two = $document;
				break;
			}
		}

		for ( $post_id = 1; $post_id <= $post_count; ++$post_id ) {
			$post = $posts->get_post( $post_id );
			if ( null !== $post && false !== strpos( $post['post_content'], 'page-two-link' ) ) {
				$page_two_post = $post;
				break;
			}
		}

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertCount( 6, $documents );
		$this->assertNotNull( $page_two );
		$this->assertNotNull( $page_two_post );
		$this->assertSame( 'posts', $page_two->get_metadata()['remote_rest_endpoint'] );
		$this->assertSame( 6, $post_count );
		$this->assertSame( 6, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'post' ) );
		$this->assertCount( 1, $references );
		$this->assertSame( 1, $media->count_attachments() );
		$this->assertContains( 'https://source.example.test/wp-json/wp/v2/pages?context=view&per_page=25&page=2&_embed=author,wp:term,wp:featuredmedia', $requests );
		$this->assertContains( 'https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=2&_embed=author,wp:term,wp:featuredmedia', $requests );
		$this->assertContains( 'https://source.example.test/wp-json/wp/v2/library-books?context=view&per_page=25&page=2&_embed=author,wp:term,wp:featuredmedia', $requests );
		$this->assertStringContainsString( 'https://local.example.test/page-two-link', $page_two_post['post_content'] );
		$this->assertStringContainsString( 'https://local.example.test/wp-content/uploads/page-two.jpg', $page_two_post['post_content'] );
		$this->assertStringNotContainsString( 'https://source.example.test/wp-content/uploads/page-two.jpg', $page_two_post['post_content'] );
	}

	/**
	 * Embedded REST featured media is staged as an image block and sideloaded through the media pipeline.
	 *
	 * @return void
	 */
	public function test_runner_imports_remote_wordpress_rest_featured_media() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_json(
			'https://source.example.test/wp-json/',
			array(
				'namespaces' => array( 'wp/v2' ),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/types?context=view',
			array(
				'post' => array(
					'slug'      => 'post',
					'rest_base' => 'posts',
					'viewable'  => true,
				),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			array(
				array(
					'id'             => 11,
					'featured_media' => 44,
					'link'           => 'https://source.example.test/post/',
					'title'          => array( 'rendered' => 'Featured Post' ),
					'content'        => array( 'rendered' => '<p>Post body.</p>' ),
					'_embedded'      => array(
						'wp:featuredmedia' => array(
							array(
								'id'         => 44,
								'source_url' => 'https://source.example.test/wp-content/uploads/featured.jpg',
								'alt_text'   => 'Featured alt',
							),
						),
					),
				),
			)
		);
		$session = ImportSession::start_for_source( 'https://source.example.test/' );
		$posts   = new FakePostGateway();
		$media   = new FakeMediaGateway();

		$media->add_remote_media( 'https://source.example.test/wp-content/uploads/featured.jpg', 'featured-bytes' );
		$this->store->save( $session );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Confirm first-party domains before URL rewriting.',
				array( 'domains' => array( 'source.example.test' ) )
			)->resolve( array( 'confirmed_domains' => array( 'source.example.test' ) ) )
		);

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, $fetcher );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );

		$documents  = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );

		$this->assertCount( 1, $documents );
		$this->assertStringContainsString( '<!-- wp:image', $documents[0]->get_block_markup() );
		$this->assertSame( 44, $documents[0]->get_metadata()['remote_featured_media_id'] );
		$this->assertCount( 1, $references );
		$this->assertSame( 1, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertStringContainsString( 'https://local.example.test/wp-content/uploads/featured.jpg', $posts->get_post( 1 )['post_content'] );
		$this->assertStringNotContainsString( 'https://source.example.test/wp-content/uploads/featured.jpg', $posts->get_post( 1 )['post_content'] );
	}

	/**
	 * REST featured media is fetched explicitly when a site omits _embedded media.
	 *
	 * @return void
	 */
	public function test_runner_fetches_remote_wordpress_rest_featured_media_fallback() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_json(
			'https://source.example.test/wp-json/',
			array(
				'namespaces' => array( 'wp/v2' ),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/types?context=view',
			array(
				'post' => array(
					'slug'      => 'post',
					'rest_base' => 'posts',
					'viewable'  => true,
				),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia',
			array(
				array(
					'id'             => 12,
					'featured_media' => 45,
					'link'           => 'https://source.example.test/fallback/',
					'title'          => array( 'rendered' => 'Fallback Featured Post' ),
					'content'        => array( 'rendered' => '<p>Fallback body.</p>' ),
				),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/media/45?context=view',
			array(
				'id'         => 45,
				'source_url' => 'https://source.example.test/wp-content/uploads/fallback.jpg',
				'alt_text'   => 'Fallback alt',
			)
		);
		$session = ImportSession::start_for_source( 'https://source.example.test/' );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, new FakePostGateway(), null, null, null, $fetcher ) )->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$decision  = $this->store->find_decision( $session->get_id(), 'confirm-first-party-domains' );

		$this->assertCount( 1, $documents );
		$this->assertSame( 45, $documents[0]->get_metadata()['remote_featured_media_id'] );
		$this->assertStringContainsString( 'https://source.example.test/wp-content/uploads/fallback.jpg', $documents[0]->get_block_markup() );
		$this->assertContains( 'https://source.example.test/wp-json/wp/v2/media/45?context=view', $fetcher->get_requested_urls() );
		$this->assertNotNull( $decision );
		$this->assertSame( ImportDecision::STATUS_PENDING, $decision->get_status() );
		$this->assertSame( array( 'source.example.test' ), $decision->get_options()['domains'] );
	}

	/**
	 * Non-REST remote HTML falls back to a single prepared classic block document.
	 *
	 * @return void
	 */
	public function test_runner_imports_single_remote_html_url_when_rest_is_unavailable() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_text(
			'https://example.test/article',
			'<html><head><title>Remote Article</title></head><body><h1>Remote Article</h1><script>alert("x")</script><p>Body.</p></body></html>'
		);
		$session = ImportSession::start_for_source( 'https://example.test/article' );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, $fetcher ) )->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$events    = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertCount( 1, $documents );
		$this->assertSame( 'remote-html', $documents[0]->get_format() );
		$this->assertSame( 'Remote Article', $documents[0]->get_title() );
		$this->assertSame( 'structured', $documents[0]->get_metadata()['html_block_conversion'] );
		$this->assertStringContainsString( '<!-- wp:heading {"level":1} -->', $documents[0]->get_block_markup() );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $documents[0]->get_block_markup() );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $documents[0]->get_block_markup() );
		$this->assertStringNotContainsString( '<script', $documents[0]->get_block_markup() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertContains( 'remote.url_prepared', $events );
	}

	/**
	 * Direct RSS feed URLs are imported as one prepared document per item.
	 *
	 * @return void
	 */
	public function test_runner_imports_direct_remote_rss_feed_items() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_text(
			'https://feed.example.test/rss.xml',
			'<?xml version="1.0" encoding="UTF-8"?>'
			. '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">'
			. '<channel><title>Example Feed</title>'
			. '<item><guid>item-1</guid><title>First Story</title><link>https://feed.example.test/first/</link><pubDate>Sat, 16 May 2026 00:00:00 +0000</pubDate><content:encoded><![CDATA[<h2>First Story</h2><p>Feed body with <a href="/about/">link</a>.</p>]]></content:encoded></item>'
			. '<item><guid>item-2</guid><title>Second Story</title><link>/second/</link><description><![CDATA[Plain second body.]]></description></item>'
			. '</channel></rss>'
		);
		$session = ImportSession::start_for_source( 'https://feed.example.test/rss.xml' );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, $fetcher ) )->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$root      = $this->store->find_source_item( $session->get_id(), 'remote:' . hash( 'sha256', 'https://feed.example.test/rss.xml' ) );
		$events    = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertCount( 2, $documents );
		$this->assertSame( 'rss', $documents[0]->get_format() );
		$this->assertSame( 'First Story', $documents[0]->get_title() );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2} -->', $documents[0]->get_block_markup() );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $documents[1]->get_block_markup() );
		$this->assertSame( 'https://feed.example.test/rss.xml', $documents[0]->get_metadata()['remote_feed_url'] );
		$this->assertSame( 'https://feed.example.test/second/', $documents[1]->get_metadata()['remote_source_url'] );
		$this->assertSame( 'direct-feed', $root->get_metadata()['remote_feed_discovered_by'] );
		$this->assertSame( 'rss', $root->get_metadata()['remote_mode'] );
		$this->assertSame( 2, $posts->count_posts() );
		$this->assertContains( 'remote.feed_prepared', $events );
	}

	/**
	 * OPML feed lists import items from the feeds they reference.
	 *
	 * @return void
	 */
	public function test_runner_imports_remote_opml_feed_lists() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_text(
			'https://notes.example.test/subscriptions.opml',
			'<?xml version="1.0" encoding="UTF-8"?>'
			. '<opml version="2.0"><head><title>Reading List</title></head><body>'
			. '<outline text="Notes" type="rss" xmlUrl="/feeds/notes.xml"/>'
			. '<outline text="Ideas" type="rss" xmlUrl="https://ideas.example.test/feed.xml"/>'
			. '</body></opml>'
		);
		$fetcher->add_text(
			'https://notes.example.test/feeds/notes.xml',
			'<?xml version="1.0" encoding="UTF-8"?>'
			. '<rss version="2.0"><channel><title>Notes</title>'
			. '<item><guid>note-1</guid><title>Notebook Entry</title><link>https://notes.example.test/notebook/</link><description><![CDATA[<p>Notebook body.</p>]]></description></item>'
			. '</channel></rss>'
		);
		$fetcher->add_text(
			'https://ideas.example.test/feed.xml',
			'<?xml version="1.0" encoding="UTF-8"?>'
			. '<feed xmlns="http://www.w3.org/2005/Atom"><title>Ideas</title>'
			. '<entry><id>tag:ideas.example.test,2026:1</id><title>Idea Entry</title><link href="https://ideas.example.test/idea/"/><content type="html">&lt;p&gt;Idea body.&lt;/p&gt;</content></entry>'
			. '</feed>'
		);
		$session = ImportSession::start_for_source( 'https://notes.example.test/subscriptions.opml' );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, $fetcher ) )->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$titles    = array_map(
			function ( $document ) {
				return $document->get_title();
			},
			$documents
		);
		sort( $titles );
		$root   = $this->store->find_source_item( $session->get_id(), 'remote:' . hash( 'sha256', 'https://notes.example.test/subscriptions.opml' ) );
		$events = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( array( 'Idea Entry', 'Notebook Entry' ), $titles );
		$this->assertSame( 'opml', $root->get_metadata()['remote_mode'] );
		$this->assertSame( 2, $root->get_metadata()['remote_opml_feed_count'] );
		$this->assertSame( 2, $root->get_metadata()['remote_opml_feeds_fetched'] );
		$this->assertSame( 2, $posts->count_posts() );
		$this->assertContains( 'https://notes.example.test/feeds/notes.xml', $fetcher->get_requested_urls() );
		$this->assertContains( 'remote.opml_prepared', $events );
	}

	/**
	 * Relative media references inside feed item content are resolved against the item URL.
	 *
	 * @return void
	 */
	public function test_runner_absolutizes_relative_rss_media_urls() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_text(
			'https://feed.example.test/rss.xml',
			'<?xml version="1.0" encoding="UTF-8"?>'
			. '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">'
			. '<channel><title>Example Feed</title>'
			. '<item><guid>item-1</guid><title>First Story</title><link>https://feed.example.test/posts/first/</link><content:encoded><![CDATA[<p><img src="../uploads/feed.jpg" srcset="../uploads/feed-small.jpg 400w, /uploads/feed-large.jpg 800w" alt="Feed image"></p>]]></content:encoded></item>'
			. '</channel></rss>'
		);
		$session = ImportSession::start_for_source( 'https://feed.example.test/rss.xml' );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, new FakePostGateway(), null, null, null, $fetcher ) )->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );

		$this->assertCount( 1, $documents );
		$this->assertStringContainsString( 'src="https://feed.example.test/uploads/feed.jpg"', $documents[0]->get_block_markup() );
		$this->assertStringContainsString( 'https://feed.example.test/uploads/feed-small.jpg 400w', $documents[0]->get_block_markup() );
		$this->assertStringContainsString( 'https://feed.example.test/uploads/feed-large.jpg 800w', $documents[0]->get_block_markup() );
		$this->assertSame( array( 'feed.example.test' ), $documents[0]->get_metadata()['absolute_url_domains'] );
	}

	/**
	 * RDF/RSS 1.0 feeds are accepted as generic feed sources.
	 *
	 * @return void
	 */
	public function test_runner_imports_remote_rdf_feed_items() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_text(
			'https://rdf.example.test/feed.rdf',
			'<?xml version="1.0" encoding="UTF-8"?>'
			. '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns="http://purl.org/rss/1.0/">'
			. '<channel rdf:about="https://rdf.example.test/"><title>RDF Feed</title></channel>'
			. '<item rdf:about="https://rdf.example.test/rdf-entry/"><title>RDF Entry</title><link>https://rdf.example.test/rdf-entry/</link><description><![CDATA[<p>RDF body.</p>]]></description></item>'
			. '</rdf:RDF>'
		);
		$session = ImportSession::start_for_source( 'https://rdf.example.test/feed.rdf' );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, $fetcher ) )->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$root      = $this->store->find_source_item( $session->get_id(), 'remote:' . hash( 'sha256', 'https://rdf.example.test/feed.rdf' ) );

		$this->assertCount( 1, $documents );
		$this->assertSame( 'rss', $documents[0]->get_format() );
		$this->assertSame( 'RDF Entry', $documents[0]->get_title() );
		$this->assertStringContainsString( '<p>RDF body.</p>', $documents[0]->get_block_markup() );
		$this->assertSame( 'RDF Feed', $root->get_metadata()['remote_feed_title'] );
		$this->assertSame( 1, $posts->count_posts() );
	}

	/**
	 * Ordinary site URLs advertising RSS are imported through the linked feed.
	 *
	 * @return void
	 */
	public function test_runner_imports_rss_feed_advertised_by_remote_site_url() {
		$fetcher = new FakeRemoteContentFetcher();
		$fetcher->add_text(
			'https://site.example.test/',
			'<html><head><title>Feed Landing</title><link rel="alternate" type="application/rss+xml" href="/feed/"></head><body><p>Landing page.</p></body></html>'
		);
		$fetcher->add_text(
			'https://site.example.test/feed/',
			'<?xml version="1.0" encoding="UTF-8"?>'
			. '<feed xmlns="http://www.w3.org/2005/Atom"><title>Atom Feed</title>'
			. '<entry><id>tag:site.example.test,2026:1</id><title>Atom Entry</title><link href="https://site.example.test/atom-entry/"/><updated>2026-05-16T00:00:00Z</updated><content type="html">&lt;p&gt;Atom body.&lt;/p&gt;</content></entry>'
			. '</feed>'
		);
		$session = ImportSession::start_for_source( 'https://site.example.test/' );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, $fetcher ) )->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$root      = $this->store->find_source_item( $session->get_id(), 'remote:' . hash( 'sha256', 'https://site.example.test/' ) );

		$this->assertCount( 1, $documents );
		$this->assertSame( 'rss', $documents[0]->get_format() );
		$this->assertSame( 'Atom Entry', $documents[0]->get_title() );
		$this->assertStringContainsString( '<p>Atom body.</p>', $documents[0]->get_block_markup() );
		$this->assertSame( 'https://site.example.test/feed/', $root->get_metadata()['remote_feed_url'] );
		$this->assertSame( 'html-feed-link', $root->get_metadata()['remote_feed_discovered_by'] );
		$this->assertContains( 'https://site.example.test/feed/', $fetcher->get_requested_urls() );
		$this->assertSame( 1, $posts->count_posts() );
	}

	/**
	 * WXR exports are parsed into one prepared document per importable post entity.
	 *
	 * @return void
	 */
	public function test_runner_prepares_wxr_post_documents() {
		$source_file = $this->temporary_file(
			'export.wxr',
			$this->wxr_export(
				array(
					array(
						'id'      => 10,
						'title'   => 'Exported Page',
						'type'    => 'page',
						'content' => '<!-- wp:paragraph --><p>Block content.</p><!-- /wp:paragraph --><script>alert("x")</script>',
					),
					array(
						'id'      => 11,
						'title'   => 'Legacy Post',
						'type'    => 'post',
						'content' => '<p>Legacy HTML with <a href="https://source.example.test/path">a link</a>.</p>',
					),
					array(
						'id'      => 12,
						'title'   => 'Attachment',
						'type'    => 'attachment',
						'content' => '<p>Attachment body is not imported as a draft page.</p>',
					),
				)
			)
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$items     = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata  = $items[0]->get_metadata();

		$this->assertCount( 2, $documents );
		$this->assertSame( 'wxr', $documents[0]->get_format() );
		$this->assertSame( 'Exported Page', $documents[0]->get_title() );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $documents[0]->get_block_markup() );
		$this->assertStringNotContainsString( '<script', $documents[0]->get_block_markup() );
		$this->assertSame( 'Legacy Post', $documents[1]->get_title() );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $documents[1]->get_block_markup() );
		$this->assertSame( 2, $metadata['wxr_posts_prepared'] );
		$this->assertSame( 1, $metadata['wxr_attachments_skipped'] );
		$this->assertArrayNotHasKey( 'wxr_cursor', $metadata );
	}

	/**
	 * WXR author, term, postmeta, and comment entities enrich prepared documents.
	 *
	 * @return void
	 */
	public function test_runner_stages_wxr_related_entities_for_post_and_comment_persistence() {
		$source_file = $this->temporary_file(
			'related.wxr',
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0"'
			. ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
			. ' xmlns:wp="http://wordpress.org/export/1.2/">'
			. "\n<channel>\n<title>Related Export</title>\n"
			. "<wp:wxr_version>1.2</wp:wxr_version>\n"
			. "<wp:author>\n"
			. "<wp:author_id>7</wp:author_id>\n"
			. "<wp:author_login><![CDATA[editor]]></wp:author_login>\n"
			. "<wp:author_email><![CDATA[editor@example.test]]></wp:author_email>\n"
			. "<wp:author_display_name><![CDATA[Ed Editor]]></wp:author_display_name>\n"
			. "</wp:author>\n"
			. "<wp:category>\n"
			. "<wp:term_id>44</wp:term_id>\n"
			. "<wp:category_nicename><![CDATA[research]]></wp:category_nicename>\n"
			. "<wp:cat_name><![CDATA[Research]]></wp:cat_name>\n"
			. "</wp:category>\n"
			. "<item>\n"
			. "<title>Related WXR Post</title>\n"
			. "<link>https://source.example.test/related/</link>\n"
			. "<pubDate>Wed, 05 Jun 2024 16:04:48 +0000</pubDate>\n"
			. "<dc:creator><![CDATA[editor]]></dc:creator>\n"
			. "<guid isPermaLink=\"false\">https://source.example.test/?p=70</guid>\n"
			. "<content:encoded><![CDATA[<p>Related body.</p>]]></content:encoded>\n"
			. "<wp:post_id>70</wp:post_id>\n"
			. "<wp:status>publish</wp:status>\n"
			. "<wp:post_name>related-wxr-post</wp:post_name>\n"
			. "<wp:post_type>post</wp:post_type>\n"
			. "<category domain=\"category\" nicename=\"research\"><![CDATA[Research]]></category>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_seo_title]]></wp:meta_key><wp:meta_value><![CDATA[SEO Related]]></wp:meta_value></wp:postmeta>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_edit_lock]]></wp:meta_key><wp:meta_value><![CDATA[1717600000:1]]></wp:meta_value></wp:postmeta>\n"
			. "<wp:comment>\n"
			. "<wp:comment_id>700</wp:comment_id>\n"
			. "<wp:comment_author><![CDATA[Reader]]></wp:comment_author>\n"
			. "<wp:comment_author_url>https://reader.example.test/</wp:comment_author_url>\n"
			. "<wp:comment_date>2024-06-06 10:00:00</wp:comment_date>\n"
			. "<wp:comment_date_gmt>2024-06-06 10:00:00</wp:comment_date_gmt>\n"
			. "<wp:comment_content><![CDATA[<p>Useful note.</p><script>alert(\"x\")</script>]]></wp:comment_content>\n"
			. "<wp:comment_approved>1</wp:comment_approved>\n"
			. "<wp:comment_parent>0</wp:comment_parent>\n"
			. "</wp:comment>\n"
			. "</item>\n"
			. "</channel>\n</rss>\n"
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$comments    = new FakeCommentGateway();

		$posts->add_user( 'editor', 42 );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, null, null, null, $comments ) )->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$items     = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata  = $documents[0]->get_metadata();
		$post      = $posts->get_post( 1 );
		$events    = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertCount( 1, $documents );
		$this->assertSame( 'Ed Editor', $metadata['remote_author']['name'] );
		$this->assertSame( 'editor', $metadata['remote_author']['slug'] );
		$this->assertSame( 'research', $metadata['remote_terms']['category'][0]['slug'] );
		$this->assertSame( '_seo_title', $metadata['wxr_postmeta'][0]['key'] );
		$this->assertSame( 'SEO Related', $metadata['wxr_postmeta'][0]['value'] );
		$this->assertSame( 2, $metadata['wxr_postmeta_count'] );
		$this->assertSame( 700, $metadata['remote_comments'][0]['remote_comment_id'] );
		$this->assertStringNotContainsString( '<script', $metadata['remote_comments'][0]['content'] );
		$this->assertSame( 1, $comments->count_comments() );
		$this->assertSame( 42, $post['post_author'] );
		$this->assertSame( array( 100 ), $post['terms']['category'] );
		$this->assertSame( 'SEO Related', $post['meta']['_seo_title'] );
		$this->assertArrayNotHasKey( '_edit_lock', $post['meta'] );
		$this->assertSame( 1, $items[0]->get_metadata()['wxr_author_count'] );
		$this->assertSame( 1, $items[0]->get_metadata()['wxr_term_count'] );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'comment:' . $documents[0]->get_source_item_key() . ':700' ) );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'postmeta:' . $documents[0]->get_source_item_key() ) );
		$this->assertContains( 'postmeta.applied', $events );
	}

	/**
	 * WXR postmeta-only URLs participate in first-party confirmation and rewriting.
	 *
	 * @return void
	 */
	public function test_runner_confirms_and_rewrites_first_party_urls_found_only_in_wxr_postmeta() {
		$source_file = $this->temporary_file(
			'postmeta-url.wxr',
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0"'
			. ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
			. ' xmlns:wp="http://wordpress.org/export/1.2/">'
			. "\n<channel>\n<title>Postmeta URL Export</title>\n"
			. "<wp:wxr_version>1.2</wp:wxr_version>\n"
			. "<item>\n"
			. "<title>Postmeta URL Post</title>\n"
			. "<link>https://source.example.test/postmeta-url-post/</link>\n"
			. "<guid isPermaLink=\"false\">https://source.example.test/?p=88</guid>\n"
			. "<content:encoded><![CDATA[<p>Body without absolute URLs.</p>]]></content:encoded>\n"
			. "<wp:post_id>88</wp:post_id>\n"
			. "<wp:status>publish</wp:status>\n"
			. "<wp:post_type>post</wp:post_type>\n"
			. '<wp:postmeta><wp:meta_key><![CDATA[_builder_data]]></wp:meta_key><wp:meta_value><![CDATA['
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- This fixture verifies serialized WXR postmeta URL rewriting.
			. serialize(
				array(
					'landing_url' => 'https://source.example.test/landing/?from=wxr#hero',
					'external'    => 'https://outside.example.test/landing/',
				)
			)
			. "]]></wp:meta_value></wp:postmeta>\n"
			. "</item>\n"
			. "</channel>\n</rss>\n"
		);
		$session = ImportSession::start_for_source( $source_file );
		$posts   = new FakePostGateway();

		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/site/' );
		$runner->run( $session->get_id() );

		$decision = $this->store->find_decision( $session->get_id(), 'confirm-first-party-domains' );

		$this->assertNotNull( $decision );
		$this->assertSame( ImportDecision::STATUS_PENDING, $decision->get_status() );
		$this->assertSame( array( 'source.example.test' ), $decision->get_options()['domains'] );
		$this->assertSame( 0, $posts->count_posts() );

		$this->store->save_decision(
			$session->get_id(),
			$decision->resolve( array( 'confirmed_domains' => array( 'source.example.test' ) ) )
		);
		$runner->run( $session->get_id() );

		$post = $posts->get_post( 1 );
		$meta = $post['meta']['_builder_data'];

		$this->assertSame( 'https://local.example.test/site/landing/?from=wxr#hero', $meta['landing_url'] );
		$this->assertSame( 'https://outside.example.test/landing/', $meta['external'] );
	}

	/**
	 * WXR nav_menu_item posts are staged, confirmed, remapped, and persisted as local menus.
	 *
	 * @return void
	 */
	public function test_runner_imports_wxr_navigation_menu_items() {
		$source_file = $this->temporary_file(
			'navigation-menu.wxr',
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0"'
			. ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
			. ' xmlns:wp="http://wordpress.org/export/1.2/">'
			. "\n<channel>\n<title>Menu Export</title>\n"
			. "<wp:wxr_version>1.2</wp:wxr_version>\n"
			. "<wp:category>\n"
			. "<wp:term_id>7</wp:term_id>\n"
			. "<wp:category_nicename><![CDATA[news]]></wp:category_nicename>\n"
			. "<wp:cat_name><![CDATA[News]]></wp:cat_name>\n"
			. "</wp:category>\n"
			. "<item>\n"
			. "<title>About Source</title>\n"
			. "<link>https://source.example.test/about/</link>\n"
			. "<content:encoded><![CDATA[<p>About body.</p>]]></content:encoded>\n"
			. "<wp:post_id>10</wp:post_id>\n"
			. "<wp:status>publish</wp:status>\n"
			. "<wp:post_name>about-source</wp:post_name>\n"
			. "<wp:post_type>page</wp:post_type>\n"
			. "</item>\n"
			. "<item>\n"
			. "<title>About Menu Item</title>\n"
			. "<wp:post_id>50</wp:post_id>\n"
			. "<wp:status>publish</wp:status>\n"
			. "<wp:post_type>nav_menu_item</wp:post_type>\n"
			. "<wp:menu_order>1</wp:menu_order>\n"
			. "<category domain=\"nav_menu\" nicename=\"primary\"><![CDATA[Primary]]></category>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_menu_item_type]]></wp:meta_key><wp:meta_value><![CDATA[post_type]]></wp:meta_value></wp:postmeta>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_menu_item_object]]></wp:meta_key><wp:meta_value><![CDATA[page]]></wp:meta_value></wp:postmeta>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_menu_item_object_id]]></wp:meta_key><wp:meta_value><![CDATA[10]]></wp:meta_value></wp:postmeta>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_menu_item_menu_item_parent]]></wp:meta_key><wp:meta_value><![CDATA[0]]></wp:meta_value></wp:postmeta>\n"
			. "</item>\n"
			. "<item>\n"
			. "<title>Contact</title>\n"
			. "<wp:post_id>51</wp:post_id>\n"
			. "<wp:status>publish</wp:status>\n"
			. "<wp:post_type>nav_menu_item</wp:post_type>\n"
			. "<wp:menu_order>2</wp:menu_order>\n"
			. "<category domain=\"nav_menu\" nicename=\"primary\"><![CDATA[Primary]]></category>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_menu_item_type]]></wp:meta_key><wp:meta_value><![CDATA[custom]]></wp:meta_value></wp:postmeta>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_menu_item_url]]></wp:meta_key><wp:meta_value><![CDATA[https://source.example.test/contact/?from=menu#lead]]></wp:meta_value></wp:postmeta>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_menu_item_menu_item_parent]]></wp:meta_key><wp:meta_value><![CDATA[50]]></wp:meta_value></wp:postmeta>\n"
			. "</item>\n"
			. "<item>\n"
			. "<title>News</title>\n"
			. "<wp:post_id>52</wp:post_id>\n"
			. "<wp:status>publish</wp:status>\n"
			. "<wp:post_type>nav_menu_item</wp:post_type>\n"
			. "<wp:menu_order>3</wp:menu_order>\n"
			. "<category domain=\"nav_menu\" nicename=\"primary\"><![CDATA[Primary]]></category>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_menu_item_type]]></wp:meta_key><wp:meta_value><![CDATA[taxonomy]]></wp:meta_value></wp:postmeta>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_menu_item_object]]></wp:meta_key><wp:meta_value><![CDATA[category]]></wp:meta_value></wp:postmeta>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_menu_item_object_id]]></wp:meta_key><wp:meta_value><![CDATA[7]]></wp:meta_value></wp:postmeta>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_menu_item_menu_item_parent]]></wp:meta_key><wp:meta_value><![CDATA[0]]></wp:meta_value></wp:postmeta>\n"
			. "</item>\n"
			. "</channel>\n</rss>\n"
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$posts->register_menu_location( 'primary', 'Primary Menu' );

		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/site/' );
		$runner->run( $session->get_id() );

		$decision = $this->store->find_decision( $session->get_id(), 'confirm-first-party-domains' );
		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );

		$this->assertNotNull( $decision );
		$this->assertSame( array( 'source.example.test' ), $decision->get_options()['domains'] );
		$this->assertSame( 0, $posts->count_posts() );
		$this->assertSame( 3, $items[0]->get_metadata()['wxr_nav_menu_item_count'] );

		$this->store->save_decision(
			$session->get_id(),
			$decision->resolve( array( 'confirmed_domains' => array( 'source.example.test' ) ) )
		);
		$runner->run( $session->get_id() );

		$menu   = $posts->get_menu_by_slug( 'primary' );
		$events = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 30 )
		);

		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $menu );
		$menu_items     = array_values( $menu['items'] );
		$items_by_title = array();

		foreach ( $menu_items as $menu_item ) {
			$items_by_title[ $menu_item['title'] ] = $menu_item;
		}

		$this->assertCount( 3, $menu_items );
		$this->assertSame( 'https://local.example.test/imported/1/', $items_by_title['About Menu Item']['url'] );
		$this->assertSame( 'https://local.example.test/site/contact/?from=menu#lead', $items_by_title['Contact']['url'] );
		$this->assertSame( $items_by_title['About Menu Item']['ID'], $items_by_title['Contact']['parent_id'] );
		$this->assertSame( 'taxonomy', $items_by_title['News']['type'] );
		$this->assertSame( 'category', $items_by_title['News']['object'] );
		$this->assertSame( 100, $items_by_title['News']['object_id'] );
		$this->assertSame( 'https://local.example.test/category/100/', $items_by_title['News']['url'] );
		$this->assertSame( array( 'primary' => 200 ), $posts->get_menu_locations() );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'nav-menu-item:' . $items[0]->get_key() . ':50' ) );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'nav-menu-item:' . $items[0]->get_key() . ':51' ) );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'nav-menu-item:' . $items[0]->get_key() . ':52' ) );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'nav-menu-location:' . $items[0]->get_key() . ':primary' ) );
		$this->assertContains( 'document.wxr_nav_menu_item_staged', $events );
		$this->assertContains( 'nav_menu_item.applied', $events );
		$this->assertContains( 'nav_menu.location_assigned', $events );
	}

	/**
	 * WXR menu location assignment can retry after menu items are already idempotent.
	 *
	 * @return void
	 */
	public function test_runner_retries_wxr_navigation_menu_location_assignment_after_item_skip() {
		$source_file = $this->temporary_file(
			'navigation-menu-location-retry.wxr',
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0"'
			. ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
			. ' xmlns:wp="http://wordpress.org/export/1.2/">'
			. "\n<channel>\n<title>Menu Export</title>\n"
			. "<wp:wxr_version>1.2</wp:wxr_version>\n"
			. "<item>\n"
			. "<title>About Source</title>\n"
			. "<content:encoded><![CDATA[<p>About body.</p>]]></content:encoded>\n"
			. "<wp:post_id>10</wp:post_id>\n"
			. "<wp:status>publish</wp:status>\n"
			. "<wp:post_name>about-source</wp:post_name>\n"
			. "<wp:post_type>page</wp:post_type>\n"
			. "</item>\n"
			. "<item>\n"
			. "<title>About Menu Item</title>\n"
			. "<wp:post_id>50</wp:post_id>\n"
			. "<wp:status>publish</wp:status>\n"
			. "<wp:post_type>nav_menu_item</wp:post_type>\n"
			. "<wp:menu_order>1</wp:menu_order>\n"
			. "<category domain=\"nav_menu\" nicename=\"primary\"><![CDATA[Primary]]></category>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_menu_item_type]]></wp:meta_key><wp:meta_value><![CDATA[post_type]]></wp:meta_value></wp:postmeta>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_menu_item_object]]></wp:meta_key><wp:meta_value><![CDATA[page]]></wp:meta_value></wp:postmeta>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_menu_item_object_id]]></wp:meta_key><wp:meta_value><![CDATA[10]]></wp:meta_value></wp:postmeta>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_menu_item_menu_item_parent]]></wp:meta_key><wp:meta_value><![CDATA[0]]></wp:meta_value></wp:postmeta>\n"
			. "</item>\n"
			. "</channel>\n</rss>\n"
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$runner      = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/site/' );

		$this->store->save( $session );
		$runner->run( $session->get_id() );

		$items = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );

		$this->assertSame( array(), $posts->get_menu_locations() );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'nav-menu-item:' . $items[0]->get_key() . ':50' ) );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'nav-menu-location:' . $items[0]->get_key() . ':primary' ) );

		$posts->register_menu_location( 'primary', 'Primary Menu' );
		$runner->run( $session->get_id() );

		$events = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 30 )
		);

		$this->assertSame( array( 'primary' => 200 ), $posts->get_menu_locations() );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'nav-menu-location:' . $items[0]->get_key() . ':primary' ) );
		$this->assertContains( 'nav_menu.location_no_match', $events );
		$this->assertContains( 'nav_menu.location_assigned', $events );
	}

	/**
	 * WXR attachment posts are queued through media import and thumbnail postmeta is remapped.
	 *
	 * @return void
	 */
	public function test_runner_imports_wxr_attachment_and_remaps_thumbnail_postmeta() {
		$attachment_url = 'https://source.example.test/uploads/featured.jpg';
		$source_file    = $this->temporary_file(
			'attachment-thumbnail.wxr',
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0"'
			. ' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
			. ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
			. ' xmlns:wp="http://wordpress.org/export/1.2/">'
			. "\n<channel>\n<title>Attachment Export</title>\n"
			. "<wp:wxr_version>1.2</wp:wxr_version>\n"
			. "<item>\n"
			. "<title>featured.jpg</title>\n"
			. "<link>https://source.example.test/featured/</link>\n"
			. "<guid isPermaLink=\"false\">$attachment_url</guid>\n"
			. "<content:encoded><![CDATA[Attachment description.<script>alert('x')</script>]]></content:encoded>\n"
			. "<excerpt:encoded><![CDATA[Attachment caption.<script>alert('x')</script>]]></excerpt:encoded>\n"
			. "<wp:post_id>31</wp:post_id>\n"
			. "<wp:status>inherit</wp:status>\n"
			. "<wp:post_name>featured-jpg</wp:post_name>\n"
			. "<wp:post_type>attachment</wp:post_type>\n"
			. "<wp:attachment_url>$attachment_url</wp:attachment_url>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_wp_attachment_image_alt]]></wp:meta_key><wp:meta_value><![CDATA[Featured alt <b>text</b><script>alert('x')</script>]]></wp:meta_value></wp:postmeta>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_wp_attached_file]]></wp:meta_key><wp:meta_value><![CDATA[2024/05/featured.jpg]]></wp:meta_value></wp:postmeta>\n"
			. "</item>\n"
			. "<item>\n"
			. "<title>Post With Thumbnail</title>\n"
			. "<link>https://source.example.test/post-with-thumbnail/</link>\n"
			. "<dc:creator><![CDATA[admin]]></dc:creator>\n"
			. "<guid isPermaLink=\"false\">https://source.example.test/?p=70</guid>\n"
			. "<content:encoded><![CDATA[<p>Body.</p>]]></content:encoded>\n"
			. "<wp:post_id>70</wp:post_id>\n"
			. "<wp:status>publish</wp:status>\n"
			. "<wp:post_name>post-with-thumbnail</wp:post_name>\n"
			. "<wp:post_type>post</wp:post_type>\n"
			. "<wp:postmeta><wp:meta_key><![CDATA[_thumbnail_id]]></wp:meta_key><wp:meta_value><![CDATA[31]]></wp:meta_value></wp:postmeta>\n"
			. "</item>\n"
			. "</channel>\n</rss>\n"
		);
		$session        = ImportSession::start_for_source( $source_file );
		$posts          = new FakePostGateway();
		$media          = new FakeMediaGateway();

		$this->store->save( $session );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Confirm domains.',
				array( 'domains' => array( 'source.example.test' ) )
			)->resolve( array( 'confirmed_domains' => array( 'source.example.test' ) ) )
		);
		$media->add_remote_media( $attachment_url, 'featured-bytes' );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, $media ) )->run( $session->get_id() );

		$documents     = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$metadata      = $documents[0]->get_metadata();
		$post          = $posts->get_post( 1 );
		$attachment    = $media->get_attachment( 100 );
		$reference_key = ImportWxrAttachment::reference_key( $metadata['wxr_source_item_key'], 31 );
		$reference     = $this->store->find_media_reference( $session->get_id(), $reference_key );
		$events        = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertCount( 1, $documents );
		$this->assertSame( ImportMediaReference::STATUS_IMPORTED, $reference->get_status() );
		$this->assertSame( ImportWxrAttachment::SCOPE_CANDIDATE_FIRST_PARTY, $reference->get_metadata()['reference_scope'] );
		$this->assertSame( 'featured.jpg', $attachment['post_title'] );
		$this->assertSame( 'Attachment caption.', $attachment['post_excerpt'] );
		$this->assertSame( 'Attachment description.', $attachment['post_content'] );
		$this->assertSame( 'Featured alt text', $attachment['alt_text'] );
		$this->assertSame( '2024/05/featured.jpg', $attachment['wxr_attachment_metadata']['source_attached_file'] );
		$this->assertSame( 100, $post['meta']['_thumbnail_id'] );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'thumbnail:' . $documents[0]->get_source_item_key() ) );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'attachment-metadata:' . $reference->get_key() ) );
		$this->assertContains( 'media.wxr_attachment_queued', $events );
		$this->assertContains( 'attachment_metadata.applied', $events );
		$this->assertContains( 'thumbnail.applied', $events );
	}

	/**
	 * WXR attachment parent ids are remapped once the parent draft and attachment exist.
	 *
	 * @return void
	 */
	public function test_runner_restores_wxr_attachment_parent_relationships() {
		$attachment_url = 'https://source.example.test/uploads/child.jpg';
		$source_file    = $this->temporary_file(
			'attachment-parent.wxr',
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0"'
			. ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
			. ' xmlns:wp="http://wordpress.org/export/1.2/">'
			. "\n<channel>\n<title>Attachment Parent Export</title>\n"
			. "<wp:wxr_version>1.2</wp:wxr_version>\n"
			. "<item>\n"
			. "<title>Parent Post</title>\n"
			. "<link>https://source.example.test/parent/</link>\n"
			. "<dc:creator><![CDATA[admin]]></dc:creator>\n"
			. "<guid isPermaLink=\"false\">https://source.example.test/?p=70</guid>\n"
			. "<content:encoded><![CDATA[<p>Parent body.</p>]]></content:encoded>\n"
			. "<wp:post_id>70</wp:post_id>\n"
			. "<wp:status>publish</wp:status>\n"
			. "<wp:post_name>parent-post</wp:post_name>\n"
			. "<wp:post_type>post</wp:post_type>\n"
			. "</item>\n"
			. "<item>\n"
			. "<title>child.jpg</title>\n"
			. "<link>https://source.example.test/parent/child/</link>\n"
			. "<guid isPermaLink=\"false\">$attachment_url</guid>\n"
			. "<content:encoded><![CDATA[]]></content:encoded>\n"
			. "<wp:post_id>31</wp:post_id>\n"
			. "<wp:post_parent>70</wp:post_parent>\n"
			. "<wp:status>inherit</wp:status>\n"
			. "<wp:post_name>child-jpg</wp:post_name>\n"
			. "<wp:post_type>attachment</wp:post_type>\n"
			. "<wp:attachment_url>$attachment_url</wp:attachment_url>\n"
			. "</item>\n"
			. "</channel>\n</rss>\n"
		);
		$session        = ImportSession::start_for_source( $source_file );
		$posts          = new FakePostGateway();
		$media          = new FakeMediaGateway();

		$this->store->save( $session );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Confirm domains.',
				array( 'domains' => array( 'source.example.test' ) )
			)->resolve( array( 'confirmed_domains' => array( 'source.example.test' ) ) )
		);
		$media->add_remote_media( $attachment_url, 'child-bytes' );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, null, $media ) )->run( $session->get_id() );

		$attachment = $media->get_attachment( 100 );
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 10 );
		$events     = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( 1, $attachment['post_parent'] );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'attachment-parent:' . $references[0]->get_key() ) );
		$this->assertContains( 'attachment_parent.applied', $events );
	}

	/**
	 * Pending WXR attachment metadata beyond one bounded pass keeps the session runnable.
	 *
	 * @return void
	 */
	public function test_runner_schedules_follow_up_tick_for_pending_wxr_attachment_metadata_page() {
		$root      = $this->temporary_directory();
		$root_key  = 'local:' . hash( 'sha256', realpath( $root ) );
		$session   = ImportSession::start_for_source( $root );
		$media     = new FakeMediaGateway();
		$scheduled = array();

		$this->store->save( $session );
		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				$root_key,
				null,
				$root,
				'',
				ImportSourceItem::TYPE_DIRECTORY,
				array( 'directory_status' => 'complete' )
			)->with_status( ImportSourceItem::STATUS_SKIPPED )
		);

		for ( $index = 1; $index <= 101; ++$index ) {
			$reference = $this->imported_wxr_attachment_reference(
				$session,
				'media:' . str_pad( (string) $index, 3, '0', STR_PAD_LEFT ),
				(string) $index,
				100 + $index,
				array( 'wxr_attachment_metadata' => array( 'title' => 'Attachment ' . $index ) )
			);
			$media->add_remote_media( $reference->get_resolved_source_uri(), 'image-bytes-' . $index );
			$imported = $media->import_remote_url( $reference, 100 + $index );
			$this->store->save_media_reference( $reference->mark_imported( $imported['id'], $imported['url'], $imported['source_hash'] ) );
		}

		$scheduler = function ( ImportSessionId $session_id ) use ( &$scheduled ) {
			$scheduled[] = $session_id->to_string();
		};

		( new ImportRunner( $this->store, 'unit-test', 60, null, new FakePostGateway(), null, $media, null, null, null, null, $scheduler ) )->run( $session->get_id() );

		$this->assertSame( ImportSession::STATUS_RUNNING, $this->store->find( $session->get_id() )->get_status() );
		$this->assertSame( array( $session->get_id()->to_string() ), $scheduled );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'attachment-metadata:media:100' ) );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'attachment-metadata:media:101' ) );
	}

	/**
	 * Pending WXR attachment parents beyond one bounded pass keep the session runnable.
	 *
	 * @return void
	 */
	public function test_runner_schedules_follow_up_tick_for_pending_wxr_attachment_parent_page() {
		$root      = $this->temporary_directory();
		$root_key  = 'local:' . hash( 'sha256', realpath( $root ) );
		$session   = ImportSession::start_for_source( $root );
		$media     = new FakeMediaGateway();
		$scheduled = array();

		$this->store->save( $session );
		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				$root_key,
				null,
				$root,
				'',
				ImportSourceItem::TYPE_DIRECTORY,
				array( 'directory_status' => 'complete' )
			)->with_status( ImportSourceItem::STATUS_SKIPPED )
		);
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:local:export:wxr-post:70', 'post', '1', 'parent-hash' )
		);

		for ( $index = 1; $index <= 101; ++$index ) {
			$reference = $this->imported_wxr_attachment_reference(
				$session,
				'media:' . str_pad( (string) $index, 3, '0', STR_PAD_LEFT ),
				(string) $index,
				100 + $index,
				array( 'wxr_post_parent' => '70' )
			);
			$media->add_remote_media( $reference->get_resolved_source_uri(), 'image-bytes-' . $index );
			$imported = $media->import_remote_url( $reference, 100 + $index );
			$this->store->save_media_reference( $reference->mark_imported( $imported['id'], $imported['url'], $imported['source_hash'] ) );
		}

		$scheduler = function ( ImportSessionId $session_id ) use ( &$scheduled ) {
			$scheduled[] = $session_id->to_string();
		};

		( new ImportRunner( $this->store, 'unit-test', 60, null, new FakePostGateway(), null, $media, null, null, null, null, $scheduler ) )->run( $session->get_id() );

		$this->assertSame( ImportSession::STATUS_RUNNING, $this->store->find( $session->get_id() )->get_status() );
		$this->assertSame( array( $session->get_id()->to_string() ), $scheduled );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'attachment-parent:media:100' ) );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'attachment-parent:media:101' ) );
	}

	/**
	 * Large WXR files store a cursor and resume on the next runner tick.
	 *
	 * @return void
	 */
	public function test_runner_resumes_wxr_exports_across_ticks() {
		$posts = array();
		for ( $index = 1; $index <= 30; ++$index ) {
			$posts[] = array(
				'id'      => $index,
				'title'   => 'WXR Post ' . $index,
				'type'    => 'post',
				'content' => '<p>Body ' . $index . '</p>',
			);
		}

		$source_file = $this->temporary_file( 'large.xml', $this->wxr_export( $posts ) );
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60 );
		$runner->run( $session->get_id() );

		$partial_items = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED ), 1 );
		$this->assertCount( 1, $partial_items );
		$this->assertSame( 25, $this->store->count_prepared_documents( $session->get_id() ) );
		$this->assertArrayHasKey( 'wxr_cursor', $partial_items[0]->get_metadata() );

		$runner->run( $session->get_id() );

		$complete_items = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$this->assertCount( 1, $complete_items );
		$this->assertSame( 30, $this->store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 30, $complete_items[0]->get_metadata()['wxr_posts_prepared'] );
		$this->assertArrayNotHasKey( 'wxr_cursor', $complete_items[0]->get_metadata() );
	}

	/**
	 * EPUB archives are parsed into one prepared document per spine item.
	 *
	 * @return void
	 */
	public function test_runner_prepares_epub_spine_documents() {
		$source_file = $this->temporary_epub(
			'book.epub',
			'Fixture Book',
			array(
				'chapter-one' => array(
					'href'    => 'chapter-one.xhtml',
					'title'   => 'Chapter One',
					'content' => '<h1>Chapter One</h1><p>Body with <a href="https://source.example.test/page">a link</a>.</p><script>alert("x")</script>',
				),
				'chapter-two' => array(
					'href'    => 'chapter-two.xhtml',
					'title'   => 'Chapter Two',
					'content' => '<h1>Chapter Two</h1><p>More body.</p>',
				),
			)
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$items     = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata  = $items[0]->get_metadata();

		$this->assertCount( 2, $documents );
		$this->assertSame( 'epub', $documents[0]->get_format() );
		$this->assertSame( 'Chapter One', $documents[0]->get_title() );
		$this->assertStringContainsString( '<!-- wp:heading {"level":1} -->', $documents[0]->get_block_markup() );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $documents[0]->get_block_markup() );
		$this->assertStringContainsString( 'https://source.example.test/page', $documents[0]->get_block_markup() );
		$this->assertStringNotContainsString( '<script', $documents[0]->get_block_markup() );
		$this->assertSame( 'epub', $metadata['document_format'] );
		$this->assertSame( 'Fixture Book', $metadata['epub_title'] );
		$this->assertSame( 2, $metadata['epub_spine_count'] );
		$this->assertSame( 2, $metadata['epub_chapters_prepared'] );
		$this->assertArrayNotHasKey( 'epub_spine_index', $metadata );
	}

	/**
	 * EPUB 3 navigation documents are staged as source and spine-document metadata.
	 *
	 * @return void
	 */
	public function test_runner_stages_epub_navigation_toc_metadata() {
		$source_file = $this->temporary_epub(
			'nav-book.epub',
			'Navigation Book',
			array(
				'chapter-one' => array(
					'href'    => 'chapter-one.xhtml',
					'title'   => 'Chapter One',
					'content' => '<h1 id="start">Chapter One</h1><p>Body.</p>',
				),
				'chapter-two' => array(
					'href'    => 'chapter-two.xhtml',
					'title'   => 'Chapter Two',
					'content' => '<h1>Chapter Two</h1><p>More body.</p>',
				),
			),
			array(),
			array(
				'nav' => '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><body>'
					. '<nav epub:type="toc"><ol>'
					. '<li><a href="chapter-one.xhtml#start">Opening</a><ol><li><a href="chapter-two.xhtml">Next Chapter</a></li></ol></li>'
					. '</ol></nav></body></html>',
			)
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items     = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$first     = $this->find_prepared_document_by_epub_entry( $documents, 'OEBPS/chapter-one.xhtml' );
		$second    = $this->find_prepared_document_by_epub_entry( $documents, 'OEBPS/chapter-two.xhtml' );
		$events    = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);
		$metadata  = $items[0]->get_metadata();

		$this->assertSame( 'nav', $metadata['epub_toc_source'] );
		$this->assertSame( 'OEBPS/nav.xhtml', $metadata['epub_toc_entry'] );
		$this->assertSame( 2, $metadata['epub_toc_count'] );
		$this->assertSame( 'Opening', $metadata['epub_toc_entries'][0]['label'] );
		$this->assertSame( 1, $metadata['epub_toc_entries'][0]['depth'] );
		$this->assertSame( 0, $metadata['epub_toc_entries'][0]['epub_target_spine_index'] );
		$this->assertSame( 'start', $metadata['epub_toc_entries'][0]['target_fragment'] );
		$this->assertSame( 2, $metadata['epub_toc_entries'][1]['depth'] );
		$this->assertSame( 'Opening', $first->get_metadata()['epub_toc_label'] );
		$this->assertSame( 'Next Chapter', $second->get_metadata()['epub_toc_label'] );
		$this->assertContains( 'document.epub_toc_staged', $events );
	}

	/**
	 * EPUB 2 NCX manifests are used when no EPUB 3 nav document is available.
	 *
	 * @return void
	 */
	public function test_runner_stages_epub_ncx_toc_metadata() {
		$source_file = $this->temporary_epub(
			'ncx-book.epub',
			'NCX Book',
			array(
				'chapter-one' => array(
					'href'    => 'chapter-one.xhtml',
					'title'   => 'Chapter One',
					'content' => '<h1>Chapter One</h1><p>Body.</p>',
				),
				'chapter-two' => array(
					'href'    => 'chapter-two.xhtml',
					'title'   => 'Chapter Two',
					'content' => '<h1>Chapter Two</h1><p>More body.</p>',
				),
			),
			array(),
			array(
				'ncx' => '<?xml version="1.0" encoding="UTF-8"?><ncx xmlns="http://www.daisy.org/z3986/2005/ncx/">'
					. '<navMap>'
					. '<navPoint id="one" playOrder="1"><navLabel><text>NCX One</text></navLabel><content src="chapter-one.xhtml"/></navPoint>'
					. '<navPoint id="two" playOrder="2"><navLabel><text>NCX Two</text></navLabel><content src="chapter-two.xhtml#part"/></navPoint>'
					. '</navMap></ncx>',
			)
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts ) )->run( $session->get_id() );

		$restored  = $this->store->find( $session->get_id() );
		$items     = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$second    = $this->find_prepared_document_by_epub_entry( $documents, 'OEBPS/chapter-two.xhtml' );
		$events    = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);
		$metadata  = $items[0]->get_metadata();

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertSame( 'ncx', $metadata['epub_toc_source'] );
		$this->assertSame( 'OEBPS/toc.ncx', $metadata['epub_toc_entry'] );
		$this->assertSame( 2, $metadata['epub_toc_count'] );
		$this->assertSame( 'NCX Two', $metadata['epub_toc_entries'][1]['label'] );
		$this->assertSame( 2, $metadata['epub_toc_entries'][1]['play_order'] );
		$this->assertSame( 'part', $metadata['epub_toc_entries'][1]['target_fragment'] );
		$this->assertSame( 'NCX Two', $second->get_metadata()['epub_toc_label'] );
		$this->assertSame( 2, $posts->count_posts() );
		$this->assertSame( 2, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' ) );
		$this->assertSame( 2, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'post' ) );
		$this->assertContains( 'session.done', $events );
	}

	/**
	 * Embedded EPUB media is extracted into the media pipeline and internal spine links are rewritten.
	 *
	 * @return void
	 */
	public function test_runner_extracts_epub_assets_and_rewrites_internal_links() {
		$source_file = $this->temporary_epub(
			'asset-book.epub',
			'Asset Book',
			array(
				'chapter-one' => array(
					'href'    => 'chapter-one.xhtml',
					'title'   => 'Chapter One',
					'content' => '<h1>Chapter One</h1><p><img src="images/photo.jpg" alt="Photo" /></p><p><a href="chapter-two.xhtml#section">Next</a></p>',
				),
				'chapter-two' => array(
					'href'    => 'chapter-two.xhtml',
					'title'   => 'Chapter Two',
					'content' => '<h1 id="section">Chapter Two</h1><p>More body.</p>',
				),
			),
			array(
				'photo' => array(
					'href'       => 'images/photo.jpg',
					'media_type' => 'image/jpeg',
					'content'    => 'epub-image-bytes',
				),
			)
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();
		$cache       = new ImportCacheDirectory( $this->temporary_directory() . '/managed-cache' );

		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache ) )->run( $session->get_id() );

		$restored   = $this->store->find( $session->get_id() );
		$documents  = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 10 );
		$first      = $this->find_prepared_document_by_epub_entry( $documents, 'OEBPS/chapter-one.xhtml' );
		$events     = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertNotNull( $first );
		$this->assertCount( 1, $references );
		$this->assertSame( 'images/photo.jpg', $references[0]->get_original_url() );
		$this->assertSame( 'epub-embedded-asset', $references[0]->get_metadata()['reference_scope'] );
		$this->assertSame( 'epub', $references[0]->get_metadata()['cache_namespace'] );
		$this->assertFileExists( $references[0]->get_resolved_source_uri() );
		$this->assertStringContainsString( 'https://local.example.test/wp-content/uploads/photo.jpg', $posts->get_post( 1 )['post_content'] );
		$this->assertStringNotContainsString( 'images/photo.jpg', $posts->get_post( 1 )['post_content'] );
		$this->assertStringNotContainsString( 'images/photo.jpg', $first->get_block_markup() );
		$this->assertStringNotContainsString( 'chapter-two.xhtml', $first->get_block_markup() );
		$this->assertStringNotContainsString( 'href="#universal-importer-epub-', $first->get_block_markup() );
		$this->assertStringContainsString( 'href="https://local.example.test/imported/2/#section"', $first->get_block_markup() );
		$this->assertStringContainsString( 'https://local.example.test/imported/2/#section', $posts->get_post( 1 )['post_content'] );
		$this->assertSame( 1, $first->get_metadata()['epub_assets_queued'] );
		$this->assertSame( 'resolved', $first->get_metadata()['epub_internal_links_status'] );
		$this->assertCount( 0, $first->get_metadata()['epub_internal_links'] );
		$this->assertCount( 1, $first->get_metadata()['epub_internal_links_resolved'] );
		$this->assertContains( 'session.done', $events );
	}

	/**
	 * Large EPUB spines store a cursor and resume on the next runner tick.
	 *
	 * @return void
	 */
	public function test_runner_resumes_epub_spine_across_ticks() {
		$chapters = array();

		for ( $index = 1; $index <= 30; ++$index ) {
			$chapters[ 'chapter-' . $index ] = array(
				'href'    => 'chapter-' . $index . '.xhtml',
				'title'   => 'EPUB Chapter ' . $index,
				'content' => '<h1>EPUB Chapter ' . $index . '</h1><p>Body ' . $index . '</p>',
			);
		}

		$source_file = $this->temporary_epub( 'large.epub', 'Large EPUB', $chapters );
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60 );
		$runner->run( $session->get_id() );

		$first_tick    = $this->store->find( $session->get_id() );
		$partial_items = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED ), 1 );
		$first_events  = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);
		$this->assertSame( ImportSession::STATUS_RUNNING, $first_tick->get_status() );
		$this->assertCount( 1, $partial_items );
		$this->assertSame( 25, $this->store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 25, $partial_items[0]->get_metadata()['epub_spine_index'] );
		$this->assertContains( 1, $this->epub_cursor_updates_for_item( $partial_items[0] ) );
		$this->assertContains( 25, $this->epub_cursor_updates_for_item( $partial_items[0] ) );
		$this->assertNotContains( 'session.done', $first_events );

		$runner->run( $session->get_id() );

		$second_tick    = $this->store->find( $session->get_id() );
		$complete_items = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$second_events  = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 30 )
		);
		$this->assertSame( ImportSession::STATUS_RUNNING, $second_tick->get_status() );
		$this->assertCount( 1, $complete_items );
		$this->assertSame( 30, $this->store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 30, $complete_items[0]->get_metadata()['epub_chapters_prepared'] );
		$this->assertArrayNotHasKey( 'epub_spine_index', $complete_items[0]->get_metadata() );
		$this->assertNotContains( 'session.done', $second_events );
	}

	/**
	 * EPUB completion scans all prepared documents when no staged internal links remain.
	 *
	 * @return void
	 */
	public function test_runner_completes_epub_with_more_than_500_clean_prepared_documents() {
		$source_file = $this->temporary_file( 'clean-large.epub', 'seeded epub placeholder' );
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();

		$this->store->save( $session );
		$this->seed_imported_epub_prepared_documents( $session, 501 );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts ) )->run( $session->get_id() );

		$restored = $this->store->find( $session->get_id() );
		$events   = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertContains( 'session.done', $events );
	}

	/**
	 * EPUB completion keeps running when unresolved internal links appear after the first 500 documents.
	 *
	 * @return void
	 */
	public function test_runner_keeps_epub_running_when_document_501_has_unresolved_internal_link() {
		$source_file = $this->temporary_file( 'unresolved-large.epub', 'seeded epub placeholder' );
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();

		$this->store->save( $session );
		$this->seed_imported_epub_prepared_documents( $session, 501, 501 );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts ) )->run( $session->get_id() );

		$restored = $this->store->find( $session->get_id() );
		$events   = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( ImportSession::STATUS_RUNNING, $restored->get_status() );
		$this->assertNotContains( 'session.done', $events );
	}

	/**
	 * Invalid EPUB package paths fail with durable diagnostics.
	 *
	 * @return void
	 */
	public function test_runner_fails_epub_with_unsafe_package_path() {
		$source_file = $this->temporary_zip(
			'unsafe.epub',
			array(
				'META-INF/container.xml' => '<?xml version="1.0"?><container><rootfiles><rootfile full-path="../content.opf"/></rootfiles></container>',
			)
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$restored = $this->store->find( $session->get_id() );
		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ), 1 );
		$events   = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( ImportSession::STATUS_RUNNING, $restored->get_status() );
		$this->assertCount( 1, $items );
		$this->assertSame( 'epub', $items[0]->get_metadata()['document_format'] );
		$this->assertSame( 'EPUB package document path is missing or unsafe.', $items[0]->get_metadata()['error'] );
		$this->assertContains( 'document.failed', $events );
		$this->assertNotContains( 'session.done', $events );
	}

	/**
	 * PDF files are parsed into first-pass text block documents.
	 *
	 * @return void
	 */
	public function test_runner_prepares_pdf_text_documents() {
		$source_file = $this->temporary_pdf(
			'document.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(PDF Title) Tj\n0 -14 Td\n[(Body with ) 120 (https://source.example.test/pdf)] TJ\nET",
			true
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$this->store->save( $session );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Confirm first-party domains before URL rewriting.',
				array( 'domains' => array( 'source.example.test' ) )
			)->resolve( array( 'confirmed_domains' => array( 'source.example.test' ) ) )
		);

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/' ) )->run( $session->get_id() );

		$restored = $this->store->find( $session->get_id() );
		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata = $items[0]->get_metadata();
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$events   = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertSame( 'pdf', $metadata['document_format'] );
		$this->assertSame( 'document', $metadata['title'] );
		$this->assertSame( 'native', $metadata['pdf_text_engine'] );
		$this->assertNotNull( $document );
		$this->assertSame( 'pdf', $document->get_format() );
		$this->assertSame( 'native', $document->get_metadata()['pdf_text_engine'] );
		$this->assertStringContainsString( 'PDF Title', $document->get_block_markup() );
		$this->assertStringContainsString( 'Body with https://local.example.test/pdf', $document->get_block_markup() );
		$this->assertSame( array( 'source.example.test' ), $document->get_metadata()['absolute_url_domains'] );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'document-blocks:' . $items[0]->get_key() ) );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertContains( 'session.done', $events );
	}

	/**
	 * PDF glyph-by-glyph text operators are joined into readable lines.
	 *
	 * @return void
	 */
	public function test_runner_joins_pdf_glyph_level_text_operators() {
		$source_file = $this->temporary_pdf(
			'glyphs.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(G) Tj\n(l) Tj\n(y) Tj\n(p) Tj\n(h) Tj\n( ) Tj\n(P) Tj\n(D) Tj\n(F) Tj\n0 -14 Td\n(Body) Tj\n( ) Tj\n(text) Tj\nET",
			true
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );

		$this->assertNotNull( $document );
		$this->assertStringContainsString( 'Glyph PDF', $document->get_block_markup() );
		$this->assertStringContainsString( 'Body text', $document->get_block_markup() );
		$this->assertStringNotContainsString( "G\nl\ny\np\nh", $document->get_block_markup() );
	}

	/**
	 * PDF glyphs positioned with text matrices on one baseline stay on one line.
	 *
	 * @return void
	 */
	public function test_runner_joins_pdf_glyphs_positioned_with_text_matrices() {
		$source_file = $this->temporary_pdf(
			'matrix-glyphs.pdf',
			"BT\n/F1 12 Tf\n1 0 0 1 72 720 Tm\n(M) Tj\n1 0 0 1 80 720 Tm\n(a) Tj\n1 0 0 1 88 720 Tm\n(t) Tj\n1 0 0 1 96 720 Tm\n(r) Tj\n1 0 0 1 104 720 Tm\n(i) Tj\n1 0 0 1 112 720 Tm\n(x) Tj\n1 0 0 1 72 700 Tm\n(Second line) Tj\nET",
			true
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );

		$this->assertNotNull( $document );
		$this->assertStringContainsString( 'Matrix', $document->get_block_markup() );
		$this->assertStringContainsString( 'Second line', $document->get_block_markup() );
		$this->assertStringNotContainsString( "M\na\nt\nr\ni\nx", $document->get_block_markup() );
	}

	/**
	 * PDF text stream extraction honors direct /Length values when stream bytes contain sentinel text.
	 *
	 * @return void
	 */
	public function test_pdf_text_stream_segments_honor_direct_lengths_with_literal_endstream_bytes() {
		$content_stream = "BT\n/F1 12 Tf\n72 720 Td\n(Literal endstream marker remains inside the stream) Tj\nET";
		$pdf            = "%PDF-1.4\n"
			. "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. '4 0 obj << /Length ' . strlen( $content_stream ) . " >>\nstream\n"
			. $content_stream
			. "\nendstream\nendobj\n%%EOF\n";
		$processor      = new SourceItemDocumentProcessor( $this->store, new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' ) );
		$reflection     = new \ReflectionClass( $processor );
		$method         = $reflection->getMethod( 'extract_pdf_stream_segments' );
		$diagnostics    = array();

		$method->setAccessible( true );
		$segments = $method->invokeArgs( $processor, array( $pdf, &$diagnostics ) );

		$this->assertSame( array( $content_stream ), $segments );
		$this->assertSame( 1, $diagnostics['matched_streams'] );
		$this->assertSame( 0, $diagnostics['decode_failures'] );
	}

	/**
	 * PDF stream extraction honors direct lengths after lone carriage-return stream delimiters.
	 *
	 * @return void
	 */
	public function test_pdf_text_stream_segments_honor_direct_lengths_after_lone_cr_delimiters() {
		$content_stream = "BT\r/F1 12 Tf\r72 720 Td\r(Literal endstream marker remains inside CR-delimited stream) Tj\rET";
		$pdf            = "%PDF-1.4\r"
			. '1 0 obj << /Length ' . strlen( $content_stream ) . " >>\rstream\r"
			. $content_stream
			. "\rendstream\rendobj\r%%EOF\r";
		$processor      = new SourceItemDocumentProcessor( $this->store, new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' ) );
		$reflection     = new \ReflectionClass( $processor );
		$method         = $reflection->getMethod( 'extract_pdf_stream_segments' );
		$diagnostics    = array();

		$method->setAccessible( true );
		$segments = $method->invokeArgs( $processor, array( $pdf, &$diagnostics ) );

		$this->assertSame( array( $content_stream ), $segments );
		$this->assertSame( 1, $diagnostics['matched_streams'] );
		$this->assertSame( 0, $diagnostics['malformed_streams'] );
		$this->assertSame( 0, $diagnostics['decode_failures'] );
	}

	/**
	 * A malformed PDF text stream does not hide later valid content streams.
	 *
	 * @return void
	 */
	public function test_pdf_text_stream_segments_continue_after_malformed_stream_objects() {
		$valid_stream = "BT\n/F1 12 Tf\n72 720 Td\n(Valid later stream) Tj\nET";
		$pdf          = "%PDF-1.4\n"
			. "1 0 obj << /Length 12 >>\nstream\nunterminated text stream\nendobj\n"
			. '2 0 obj << /Length ' . strlen( $valid_stream ) . " >>\nstream\n"
			. $valid_stream
			. "\nendstream\nendobj\n%%EOF\n";
		$processor    = new SourceItemDocumentProcessor( $this->store, new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' ) );
		$reflection   = new \ReflectionClass( $processor );
		$method       = $reflection->getMethod( 'extract_pdf_stream_segments' );
		$diagnostics  = array();

		$method->setAccessible( true );
		$segments = $method->invokeArgs( $processor, array( $pdf, &$diagnostics ) );

		$this->assertSame( array( $valid_stream ), $segments );
		$this->assertSame( 1, $diagnostics['matched_streams'] );
		$this->assertSame( 1, $diagnostics['malformed_streams'] );
		$this->assertSame( 0, $diagnostics['decode_failures'] );
	}

	/**
	 * Direct-length PDF streams may contain bytes that look like object headers.
	 *
	 * @return void
	 */
	public function test_pdf_text_stream_segments_keep_literal_object_markers_inside_direct_length_streams() {
		$content_stream = "BT\n/F1 12 Tf\n72 720 Td\n(Literal object marker follows) Tj\nET\n2 0 obj << /NotARealObject true >>";
		$pdf            = "%PDF-1.4\n"
			. '1 0 obj << /Length ' . strlen( $content_stream ) . " >>\nstream\n"
			. $content_stream
			. "\nendstream\nendobj\n%%EOF\n";
		$processor      = new SourceItemDocumentProcessor( $this->store, new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' ) );
		$reflection     = new \ReflectionClass( $processor );
		$method         = $reflection->getMethod( 'extract_pdf_stream_segments' );
		$diagnostics    = array();

		$method->setAccessible( true );
		$segments = $method->invokeArgs( $processor, array( $pdf, &$diagnostics ) );

		$this->assertSame( array( $content_stream ), $segments );
		$this->assertSame( 1, $diagnostics['matched_streams'] );
		$this->assertSame( 0, $diagnostics['malformed_streams'] );
		$this->assertSame( 0, $diagnostics['decode_failures'] );
	}

	/**
	 * PDF stream diagnostics use the same length-aware stream boundaries as extraction.
	 *
	 * @return void
	 */
	public function test_runner_counts_pdf_streams_with_length_aware_boundaries() {
		$source_file = $this->temporary_pdf(
			'length-aware-stream-count.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(Literal endstream << /Length 1 >> stream X endstream marker stays text) Tj\nET",
			false
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata = $items[0]->get_metadata();
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );

		$this->assertSame( 1, $metadata['pdf_stream_count'] );
		$this->assertArrayNotHasKey( 'pdf_malformed_stream_count', $metadata );
		$this->assertNotNull( $document );
		$this->assertStringContainsString( 'Literal endstream', $document->get_block_markup() );
		$this->assertStringContainsString( 'marker stays text', $document->get_block_markup() );
	}

	/**
	 * PDF malformed-stream diagnostics ignore fake stream markers inside direct-length payloads.
	 *
	 * @return void
	 */
	public function test_runner_ignores_literal_stream_markers_when_diagnosing_malformed_pdf_streams() {
		$source_file = $this->temporary_pdf(
			'length-aware-malformed-stream-count.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(Literal << /Length 1 >> stream X marker stays text) Tj\nET",
			false
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata = $items[0]->get_metadata();
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );

		$this->assertSame( 1, $metadata['pdf_stream_count'] );
		$this->assertArrayNotHasKey( 'pdf_malformed_stream_count', $metadata );
		$this->assertArrayNotHasKey( 'pdf_structure_warning', $metadata );
		$this->assertNotNull( $document );
		$this->assertStringContainsString( 'Literal', $document->get_block_markup() );
		$this->assertStringContainsString( 'marker stays text', $document->get_block_markup() );
	}

	/**
	 * PDF processing records layout and embedded-media diagnostics for operator follow-up.
	 *
	 * @return void
	 */
	public function test_runner_records_pdf_layout_and_embedded_media_diagnostics() {
		$source_file = $this->temporary_pdf(
			'table-media.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(Table PDF) Tj\nET\n0 0 100 20 re S\n0 20 100 20 re S\n0 40 100 20 re S\n/Im1 Do",
			true
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata = $items[0]->get_metadata();
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );

		$this->assertSame( 'pdf', $metadata['document_format'] );
		$this->assertSame( 'native', $metadata['pdf_text_engine'] );
		$this->assertSame( 1, $metadata['pdf_stream_count'] );
		$this->assertGreaterThanOrEqual( 1, $metadata['pdf_text_operator_count'] );
		$this->assertTrue( $metadata['pdf_embedded_media_detected'] );
		$this->assertGreaterThanOrEqual( 1, $metadata['pdf_image_reference_count'] );
		$this->assertStringContainsString( 'embedded image references', $metadata['pdf_embedded_media_hint'] );
		$this->assertGreaterThanOrEqual( 3, $metadata['pdf_vector_drawing_count'] );
		$this->assertStringContainsString( 'table/vector layout signals', $metadata['pdf_layout_warning'] );
		$this->assertNotNull( $document );
		$this->assertSame( $metadata['pdf_layout_warning'], $document->get_metadata()['pdf_layout_warning'] );
		$this->assertSame( $metadata['pdf_embedded_media_hint'], $document->get_metadata()['pdf_embedded_media_hint'] );
	}

	/**
	 * PDF object streams are surfaced as fidelity diagnostics because the first-pass parser does not expand them.
	 *
	 * @return void
	 */
	public function test_runner_records_pdf_object_stream_diagnostics() {
		$source_file = $this->temporary_pdf_with_object_stream(
			'object-stream.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(Object Stream PDF) Tj\nET"
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata = $items[0]->get_metadata();
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$events   = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( 'pdf', $metadata['document_format'] );
		$this->assertSame( 'limited', $metadata['pdf_structure_status'] );
		$this->assertSame( array( 'object_streams_present' ), $metadata['pdf_structure_reasons'] );
		$this->assertSame( 1, $metadata['pdf_object_stream_count'] );
		$this->assertStringContainsString( 'Compressed object streams were detected', $metadata['pdf_structure_warning'] );
		$this->assertNotNull( $document );
		$this->assertSame( $metadata['pdf_structure_warning'], $document->get_metadata()['pdf_structure_warning'] );
		$this->assertStringContainsString( 'Object Stream PDF', $document->get_block_markup() );
		$this->assertContains( 'document.pdf_structure_warning', $events );
	}

	/**
	 * Corrupt compressed PDF streams fail with durable structure diagnostics instead of looking like ordinary scanned PDFs.
	 *
	 * @return void
	 */
	public function test_runner_records_corrupt_pdf_stream_diagnostics() {
		$source_file   = $this->temporary_corrupt_flate_pdf( 'corrupt-stream.pdf', 'not-valid-deflate-data' );
		$previous_text = getenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND' );
		$previous_ocr  = getenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );
		$session       = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates helper configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates OCR configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );

		try {
			( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );
		} finally {
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND', $previous_text );
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND', $previous_ocr );
		}

		$restored = $this->store->find( $session->get_id() );
		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ), 1 );
		$events   = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( ImportSession::STATUS_RUNNING, $restored->get_status() );
		$this->assertCount( 1, $items );
		$metadata = $items[0]->get_metadata();

		$this->assertSame( 'pdf', $metadata['document_format'] );
		$this->assertSame( 'limited', $metadata['pdf_structure_status'] );
		$this->assertSame( array( 'stream_decode_failure' ), $metadata['pdf_structure_reasons'] );
		$this->assertSame( 1, $metadata['pdf_stream_decode_failure_count'] );
		$this->assertStringContainsString( 'compressed streams could not be decoded', $metadata['pdf_structure_warning'] );
		$this->assertStringContainsString( 'PDF text extraction produced no importable text', $metadata['error'] );
		$this->assertContains( 'document.pdf_structure_warning', $events );
		$this->assertContains( 'document.failed', $events );
		$this->assertNotContains( 'session.done', $events );
	}

	/**
	 * PDF JPEG image XObjects are extracted into the shared media pipeline before post persistence.
	 *
	 * @return void
	 */
	public function test_runner_extracts_pdf_jpeg_images_as_media_references() {
		$source_file = $this->temporary_pdf_with_jpeg_image(
			'embedded-image.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(# PDF With Image\\n\\nBody before image.) Tj\nET\nq 1 0 0 1 0 0 cm /Im1 Do Q"
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();
		$cache       = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$runner      = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache );
		$this->store->save( $session );

		$runner->run( $session->get_id() );

		$restored   = $this->store->find( $session->get_id() );
		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );
		$document   = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$events     = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertSame( 'queued', $metadata['pdf_embedded_media_extraction_status'] );
		$this->assertSame( 1, $metadata['pdf_embedded_media_queued'] );
		$this->assertStringContainsString( 'queued for media attachment import', $metadata['pdf_embedded_media_hint'] );
		$this->assertCount( 1, $references );
		$this->assertSame( 'pdf-embedded-asset', $references[0]->get_metadata()['reference_scope'] );
		$this->assertSame( 'DCTDecode', $references[0]->get_metadata()['pdf_image_filter'] );
		$this->assertSame( 'pdf', $references[0]->get_metadata()['source'] );
		$this->assertSame( 1, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $document );
		$this->assertStringContainsString( 'https://local.example.test/wp-content/uploads/embedded-image-1-', $posts->get_post( 1 )['post_content'] );
		$this->assertStringNotContainsString( 'uwi-pdf-asset://', $posts->get_post( 1 )['post_content'] );
		$this->assertStringNotContainsString( 'uwi-pdf-asset://', $document->get_block_markup() );
		$this->assertContains( 'media.pdf_asset_queued', $events );
		$this->assertContains( 'media.attachment_created', $events );
		$this->assertContains( 'session.done', $events );
	}

	/**
	 * PDF embedded media scanning stores a durable cursor and resumes without duplicating media references.
	 *
	 * @return void
	 */
	public function test_runner_resumes_pdf_embedded_media_scan_from_durable_cursor() {
		$image_count  = SourceItemDocumentProcessor::PDF_MEDIA_SCAN_LIMIT * 2 + 1;
		$queued_count = SourceItemDocumentProcessor::PDF_MEDIA_LIMIT;
		$source_file  = $this->temporary_pdf_with_jpeg_images(
			'resume-embedded-images.pdf',
			$image_count
		);
		$session      = ImportSession::start_for_source( $source_file );
		$posts        = new FakePostGateway();
		$media        = new FakeMediaGateway();
		$cache        = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$runner       = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache );
		$this->store->save( $session );

		$partial_metadata = $this->run_pdf_until_processing_phase( $runner, $session->get_id(), 'media_scan', 5 );
		$events           = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 40 )
		);

		$this->assertSame( 'partial', $partial_metadata['processor_status'] );
		$this->assertSame( 'pdf', $partial_metadata['document_format'] );
		$this->assertSame( 'media_scan', $partial_metadata['pdf_processing_phase'] );
		$this->assertGreaterThan( 0, $partial_metadata['pdf_media_next_offset'] );
		$this->assertSame( SourceItemDocumentProcessor::PDF_MEDIA_SCAN_LIMIT, $partial_metadata['pdf_media_next_index'] );
		$this->assertSame( 0, $partial_metadata['pdf_media_scan_read_offset'] );
		$this->assertGreaterThan( $partial_metadata['pdf_media_next_offset'], $partial_metadata['pdf_media_scan_read_bytes'] );
		$this->assertSame( SourceItemDocumentProcessor::PDF_MEDIA_SCAN_LIMIT, $partial_metadata['pdf_embedded_media_queued'] );
		$this->assertCount( SourceItemDocumentProcessor::PDF_MEDIA_SCAN_LIMIT, $partial_metadata['pdf_embedded_media_assets'] );
		$this->assertSame( 0, $this->store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( SourceItemDocumentProcessor::PDF_MEDIA_SCAN_LIMIT, $media->count_attachments() );
		$this->assertContains( 'document.pdf_media_progress', $events );

		$runner->run( $session->get_id() );

		$second_partial_items = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED ), 1 );
		$this->assertCount( 1, $second_partial_items );
		$second_partial_metadata = $second_partial_items[0]->get_metadata();
		$this->assertSame( 'partial', $second_partial_metadata['processor_status'] );
		$this->assertSame( $partial_metadata['pdf_media_next_offset'], $second_partial_metadata['pdf_media_scan_read_offset'] );
		$this->assertGreaterThan( $partial_metadata['pdf_media_next_offset'], $second_partial_metadata['pdf_media_next_offset'] );
		$this->assertSame( SourceItemDocumentProcessor::PDF_MEDIA_SCAN_LIMIT * 2, $second_partial_metadata['pdf_media_next_index'] );
		$this->assertSame( SourceItemDocumentProcessor::PDF_MEDIA_SCAN_LIMIT * 2, $second_partial_metadata['pdf_embedded_media_queued'] );

		$second_references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 20 );
		$resumed_reference = null;
		foreach ( $second_references as $reference ) {
			if ( SourceItemDocumentProcessor::PDF_MEDIA_SCAN_LIMIT === $reference->get_metadata()['pdf_image_index'] ) {
				$resumed_reference = $reference;
				break;
			}
		}
		$this->assertNotNull( $resumed_reference );
		$this->assertSame( 'pdf-embedded-asset', $resumed_reference->get_metadata()['reference_scope'] );
		$this->assertSame( 'DCTDecode', $resumed_reference->get_metadata()['pdf_image_filter'] );
		$this->assertSame( 'pdf', $resumed_reference->get_metadata()['source'] );
		$this->assertGreaterThan( $partial_metadata['pdf_media_next_offset'], $resumed_reference->get_metadata()['pdf_image_next_offset'] );

		$runner->run( $session->get_id() );

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 20 );
		$document   = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );

		$this->assertArrayNotHasKey( 'pdf_media_next_offset', $metadata );
		$this->assertArrayNotHasKey( 'pdf_media_next_index', $metadata );
		$this->assertSame( $second_partial_metadata['pdf_media_next_offset'], $metadata['pdf_media_scan_read_offset'] );
		$this->assertLessThan( $partial_metadata['pdf_media_scan_read_bytes'], $metadata['pdf_media_scan_read_bytes'] );
		$this->assertSame( $queued_count, $metadata['pdf_embedded_media_queued'] );
		$this->assertSame( 1, $metadata['pdf_unsupported_embedded_media_count'] );
		$this->assertSame( array( 'extraction_limit' ), $metadata['pdf_unsupported_embedded_media_reasons'] );
		$this->assertCount( $queued_count, $metadata['pdf_embedded_media_assets'] );
		$this->assertCount( $queued_count, array_unique( array_column( $metadata['pdf_embedded_media_assets'], 'original_url' ) ) );
		$this->assertCount( $queued_count, $references );
		$this->assertSame( $queued_count, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $document );
		$this->assertSame( $queued_count, substr_count( $document->get_block_markup(), '<!-- wp:image -->' ) );
	}

	/**
	 * PDF structure scanning stores a durable cursor before media and text work.
	 *
	 * @return void
	 */
	public function test_runner_resumes_pdf_structure_scan_from_durable_cursor() {
		$source_file = $this->temporary_pdf_with_text_streams_and_object_stream(
			'resume-structure-scan.pdf',
			SourceItemDocumentProcessor::PDF_STRUCTURE_SCAN_LIMIT + 1
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$runner      = new ImportRunner( $this->store, 'unit-test', 60, null, $posts );
		$this->store->save( $session );

		$runner->run( $session->get_id() );

		$partial_items = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED ), 1 );
		$events        = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 40 )
		);

		$this->assertCount( 1, $partial_items );
		$partial_metadata = $partial_items[0]->get_metadata();
		$this->assertSame( 'partial', $partial_metadata['processor_status'] );
		$this->assertSame( 'pdf', $partial_metadata['document_format'] );
		$this->assertSame( 'pdf_structure_scan', $partial_metadata['pdf_processing_phase'] );
		$this->assertGreaterThan( 0, $partial_metadata['pdf_structure_next_offset'] );
		$this->assertSame( SourceItemDocumentProcessor::PDF_STRUCTURE_SCAN_LIMIT, $partial_metadata['pdf_structure_stream_index'] );
		$this->assertSame( 0, $partial_metadata['pdf_structure_scan_read_offset'] );
		$this->assertSame( SourceItemDocumentProcessor::PDF_STRUCTURE_SCAN_LIMIT, $partial_metadata['pdf_structure_streams_scanned'] );
		$this->assertSame( 0, $this->store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 0, $posts->count_posts() );
		$this->assertContains( 'document.pdf_structure_progress', $events );

		$runner->run( $session->get_id() );

		$text_scan_items = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED ), 1 );
		$this->assertCount( 1, $text_scan_items );
		$text_scan_metadata = $text_scan_items[0]->get_metadata();

		$this->assertArrayNotHasKey( 'pdf_structure_next_offset', $text_scan_metadata );
		$this->assertArrayNotHasKey( 'pdf_structure_stream_index', $text_scan_metadata );
		$this->assertTrue( $text_scan_metadata['pdf_structure_scan_complete'] );
		$this->assertSame( 'text_scan', $text_scan_metadata['pdf_processing_phase'] );
		$this->assertSame( 'limited', $text_scan_metadata['pdf_structure_status'] );
		$this->assertContains( 'object_streams_present', $text_scan_metadata['pdf_structure_reasons'] );
		$this->assertSame( 1, $text_scan_metadata['pdf_object_stream_count'] );
		$this->assertStringContainsString( 'Compressed object streams were detected', $text_scan_metadata['pdf_structure_warning'] );
		$this->assertSame( 0, $this->store->count_prepared_documents( $session->get_id() ) );

		$attempt = 0;
		while ( $attempt < 10 && ImportSession::STATUS_DONE !== $this->store->find( $session->get_id() )->get_status() ) {
			$runner->run( $session->get_id() );
			++$attempt;
		}

		$items          = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata       = $items[0]->get_metadata();
		$prepared_count = $this->store->count_prepared_documents( $session->get_id() );
		$documents      = $this->store->list_prepared_documents( $session->get_id(), 10 );

		$this->assertSame( ImportSession::STATUS_DONE, $this->store->find( $session->get_id() )->get_status() );
		$this->assertArrayNotHasKey( 'pdf_structure_next_offset', $metadata );
		$this->assertArrayNotHasKey( 'pdf_structure_stream_index', $metadata );
		$this->assertSame( 'limited', $metadata['pdf_structure_status'] );
		$this->assertSame( 1, $metadata['pdf_object_stream_count'] );
		$this->assertSame( $prepared_count, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' ) );
		$this->assertSame( 1, $prepared_count );
		$this->assertSame( $metadata['pdf_structure_warning'], $documents[0]->get_metadata()['pdf_structure_warning'] );
	}

	/**
	 * PDFs larger than one native scan window stream across runner ticks instead of failing immediately.
	 *
	 * @return void
	 */
	public function test_runner_streams_large_pdf_before_embedded_media_scan() {
		$source_file = $this->temporary_pdf_with_jpeg_image(
			'large-embedded-image.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(# Large PDF\\n\\nBody before image.) Tj\nET\nq 1 0 0 1 0 0 cm /Im1 Do Q"
		);
		file_put_contents( $source_file, str_repeat( '0', SourceItemDocumentProcessor::PDF_FILE_LIMIT ), FILE_APPEND );
		$marker           = $this->temporary_directory() . '/external-helper-ran';
		$previous_text    = getenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND' );
		$previous_timeout = getenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_TIMEOUT' );
		$previous_ocr     = getenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );
		$marker_literal   = "'" . addcslashes( $marker, "\\'" ) . "'";
		$php_code         = 'file_put_contents(' . $marker_literal . ', "ran"); file_put_contents($argv[2], "helper text");';
		$command          = escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $php_code ) . ' {input} {output}';
		$session          = ImportSession::start_for_source( $source_file );
		$posts            = new FakePostGateway();
		$media            = new FakeMediaGateway();
		$cache            = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$this->store->save( $session );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test verifies oversized PDFs are rejected before helper execution.
		putenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND=' . $command );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates external PDF text timeout configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_TIMEOUT=5' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test ensures OCR is not reached.
		putenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND=' . $command );

		try {
			$runner  = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache );
			$attempt = 0;
			while ( $attempt < 20 && ImportSession::STATUS_DONE !== $this->store->find( $session->get_id() )->get_status() ) {
				$runner->run( $session->get_id() );
				++$attempt;
			}
		} finally {
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND', $previous_text );
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_TEXT_TIMEOUT', $previous_timeout );
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND', $previous_ocr );
		}

		$items = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$this->assertCount( 1, $items );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );
		$document   = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );

		$this->assertSame( ImportSession::STATUS_DONE, $this->store->find( $session->get_id() )->get_status() );
		$this->assertSame( 'pdf', $metadata['document_format'] );
		$this->assertSame( 'native', $metadata['pdf_text_engine'] );
		$this->assertSame( 1, $metadata['pdf_embedded_media_queued'] );
		$this->assertGreaterThanOrEqual( SourceItemDocumentProcessor::PDF_FILE_LIMIT, $metadata['pdf_structure_scan_read_offset'] );
		$this->assertGreaterThanOrEqual( SourceItemDocumentProcessor::PDF_FILE_LIMIT, $metadata['pdf_media_scan_read_offset'] );
		$this->assertGreaterThanOrEqual( SourceItemDocumentProcessor::PDF_FILE_LIMIT, $metadata['pdf_text_scan_read_offset'] );
		$this->assertCount( 1, $references );
		$this->assertSame( 1, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $document );
		$this->assertStringContainsString( 'Large PDF', $document->get_block_markup() );
		$this->assertStringContainsString( '<!-- wp:image -->', $document->get_block_markup() );
		$this->assertFileDoesNotExist( $marker );
	}

	/**
	 * PDF filter abbreviations are normalized before embedded JPEG media extraction.
	 *
	 * @return void
	 */
	public function test_runner_extracts_pdf_jpeg_images_with_abbreviated_filter_names() {
		$image = base64_decode( $this->tiny_jpeg_base64(), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Unit test embeds a tiny binary JPEG fixture in a generated PDF.
		$this->assertIsString( $image );

		$source_file = $this->temporary_pdf_with_embedded_image(
			'abbreviated-filter-image.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(# PDF With Abbreviated Image Filter\\n\\nBody before image.) Tj\nET\nq 1 0 0 1 0 0 cm /Im1 Do Q",
			'DCT',
			$image
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();
		$cache       = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$runner      = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache );
		$this->store->save( $session );

		$runner->run( $session->get_id() );

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );
		$document   = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$events     = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( 'queued', $metadata['pdf_embedded_media_extraction_status'] );
		$this->assertSame( 1, $metadata['pdf_embedded_media_queued'] );
		$this->assertArrayNotHasKey( 'pdf_unsupported_embedded_media_count', $metadata );
		$this->assertCount( 1, $references );
		$this->assertSame( 'DCTDecode', $references[0]->get_metadata()['pdf_image_filter'] );
		$this->assertSame( 1, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $document );
		$this->assertStringContainsString( 'https://local.example.test/wp-content/uploads/embedded-image-1-', $posts->get_post( 1 )['post_content'] );
		$this->assertContains( 'media.pdf_asset_queued', $events );
		$this->assertContains( 'media.attachment_created', $events );
	}

	/**
	 * Embedded image extraction honors direct /Length values when JPEG bytes contain the stream sentinel text.
	 *
	 * @return void
	 */
	public function test_runner_extracts_pdf_jpeg_images_with_literal_endstream_bytes() {
		$image = "\xff\xd8literal endstream marker inside jpeg payload\xff\xd9";

		$source_file = $this->temporary_pdf_with_embedded_image(
			'embedded-image-with-sentinel-bytes.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(# PDF With Sentinel Bytes In Image\\n\\nBody before image.) Tj\nET\nq 1 0 0 1 0 0 cm /Im1 Do Q",
			'DCTDecode',
			$image
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();
		$cache       = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$runner      = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache );
		$this->store->save( $session );

		$runner->run( $session->get_id() );

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );
		$attachment = $media->get_attachment( 100 );
		$hash       = hash( 'sha256', $image );

		$this->assertSame( 'queued', $metadata['pdf_embedded_media_extraction_status'] );
		$this->assertSame( 1, $metadata['pdf_embedded_media_queued'] );
		$this->assertArrayNotHasKey( 'pdf_unsupported_embedded_media_count', $metadata );
		$this->assertCount( 1, $references );
		$this->assertSame( $hash, $references[0]->get_metadata()['pdf_image_source_hash'] );
		$this->assertSame( $hash, $attachment['source_hash'] );
		$this->assertSame( $image, file_get_contents( $attachment['resolved_source_uri'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Unit test verifies importer cache payload bytes.
		$this->assertSame( 1, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
	}

	/**
	 * Embedded image extraction honors direct /Length values when JPEG bytes contain object-looking markers.
	 *
	 * @return void
	 */
	public function test_runner_extracts_pdf_jpeg_images_with_literal_object_markers_inside_direct_length_streams() {
		$image = "\xff\xd8\n6 0 obj << /NotARealObject true >>\nembedded jpeg payload\xff\xd9";

		$source_file = $this->temporary_pdf_with_embedded_image(
			'embedded-image-with-object-marker-bytes.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(# PDF With Object Marker In Image\\n\\nBody before image.) Tj\nET\nq 1 0 0 1 0 0 cm /Im1 Do Q",
			'DCTDecode',
			$image
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();
		$cache       = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache ) )->run( $session->get_id() );

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );
		$attachment = $media->get_attachment( 100 );
		$hash       = hash( 'sha256', $image );

		$this->assertSame( 'queued', $metadata['pdf_embedded_media_extraction_status'] );
		$this->assertSame( 1, $metadata['pdf_embedded_media_queued'] );
		$this->assertArrayNotHasKey( 'pdf_unsupported_embedded_media_count', $metadata );
		$this->assertCount( 1, $references );
		$this->assertSame( $hash, $references[0]->get_metadata()['pdf_image_source_hash'] );
		$this->assertSame( $hash, $attachment['source_hash'] );
		$this->assertSame( $image, file_get_contents( $attachment['resolved_source_uri'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Unit test verifies importer cache payload bytes.
		$this->assertSame( 1, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
	}

	/**
	 * Embedded image extraction honors direct lengths after lone carriage-return stream delimiters.
	 *
	 * @return void
	 */
	public function test_runner_extracts_pdf_jpeg_images_after_lone_cr_stream_delimiters() {
		$image          = "\xff\xd8literal endstream marker inside CR-delimited jpeg payload\xff\xd9";
		$content_stream = "BT\n/F1 12 Tf\n72 720 Td\n(# PDF With CR Image Stream\\n\\nBody before image.) Tj\nET\nq 1 0 0 1 0 0 cm /Im1 Do Q";
		$pdf            = "%PDF-1.4\n"
			. "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R /Resources << /XObject << /Im1 5 0 R >> >> /Contents 4 0 R >> endobj\n"
			. '4 0 obj << /Length ' . strlen( $content_stream ) . " >>\nstream\n"
			. $content_stream
			. "\nendstream\nendobj\n"
			. '5 0 obj << /Type /XObject /Subtype /Image /Width 64 /Height 64 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $image ) . " >>\rstream\r"
			. $image
			. "\rendstream\rendobj\r%%EOF\r";
		$source_file    = $this->temporary_file( 'embedded-image-cr-delimiter.pdf', $pdf );
		$session        = ImportSession::start_for_source( $source_file );
		$posts          = new FakePostGateway();
		$media          = new FakeMediaGateway();
		$cache          = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache ) )->run( $session->get_id() );

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );
		$attachment = $media->get_attachment( 100 );
		$hash       = hash( 'sha256', $image );

		$this->assertSame( 'queued', $metadata['pdf_embedded_media_extraction_status'] );
		$this->assertSame( 1, $metadata['pdf_embedded_media_queued'] );
		$this->assertArrayNotHasKey( 'pdf_unsupported_embedded_media_count', $metadata );
		$this->assertCount( 1, $references );
		$this->assertSame( $hash, $references[0]->get_metadata()['pdf_image_source_hash'] );
		$this->assertSame( $hash, $attachment['source_hash'] );
		$this->assertSame( $image, file_get_contents( $attachment['resolved_source_uri'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Unit test verifies importer cache payload bytes.
		$this->assertSame( 1, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
	}

	/**
	 * PDFs without extractable text can still import directly embedded JPEG image streams.
	 *
	 * @return void
	 */
	public function test_runner_imports_image_only_pdf_when_embedded_jpeg_can_be_extracted() {
		$source_file  = $this->temporary_pdf_with_jpeg_image(
			'image-only-embedded.pdf',
			'q 1 0 0 1 0 0 cm /Im1 Do Q'
		);
		$previous_ocr = getenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );
		$session      = ImportSession::start_for_source( $source_file );
		$posts        = new FakePostGateway();
		$media        = new FakeMediaGateway();
		$cache        = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$this->store->save( $session );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test ensures OCR is not required when PDF image assets can be extracted.
		putenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );

		try {
			( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache ) )->run( $session->get_id() );
		} finally {
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND', $previous_ocr );
		}

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );

		$this->assertCount( 1, $references );
		$this->assertSame( 'no_text_assets_imported', $metadata['pdf_text_extraction_status'] );
		$this->assertSame( 'queued', $metadata['pdf_embedded_media_extraction_status'] );
		$this->assertSame( 'not_configured', $metadata['pdf_ocr_status'] );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertStringContainsString( '<!-- wp:image -->', $posts->get_post( 1 )['post_content'] );
		$this->assertStringContainsString( 'https://local.example.test/wp-content/uploads/embedded-image-1-', $posts->get_post( 1 )['post_content'] );
	}

	/**
	 * PDFs without text can import simple FlateDecode bitmap images without an external PDF tool.
	 *
	 * @return void
	 */
	public function test_runner_imports_image_only_pdf_when_embedded_flate_bitmap_can_be_converted() {
		$raw_pixels = str_repeat( "\xff\xff\xff", 64 * 64 );
		$compressed = gzcompress( $raw_pixels );
		$this->assertIsString( $compressed );
		$source_file  = $this->temporary_pdf_with_embedded_image(
			'image-only-flate-bitmap.pdf',
			'q 1 0 0 1 0 0 cm /Im1 Do Q',
			'FlateDecode',
			$compressed
		);
		$previous_ocr = getenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );
		$session      = ImportSession::start_for_source( $source_file );
		$posts        = new FakePostGateway();
		$media        = new FakeMediaGateway();
		$cache        = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$this->store->save( $session );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test ensures OCR is not required when PDF image assets can be extracted.
		putenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );

		try {
			( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache ) )->run( $session->get_id() );
		} finally {
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND', $previous_ocr );
		}

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );
		$attachment = $media->get_attachment( 100 );

		$this->assertCount( 1, $references );
		$this->assertSame( 'FlateDecode', $references[0]->get_metadata()['pdf_image_filter'] );
		$this->assertSame( 'png', $references[0]->get_metadata()['extension'] );
		$this->assertStringEndsWith( '.png', $attachment['resolved_source_uri'] );
		$this->assertStringStartsWith( "\x89PNG\r\n\x1a\n", file_get_contents( $attachment['resolved_source_uri'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Unit test verifies converted importer cache payload bytes.
		$this->assertSame( 'no_text_assets_imported', $metadata['pdf_text_extraction_status'] );
		$this->assertSame( 'queued', $metadata['pdf_embedded_media_extraction_status'] );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertStringContainsString( '.png', $posts->get_post( 1 )['post_content'] );
	}

	/**
	 * Large PDF image streams are not tokenized as native text content.
	 *
	 * @return void
	 */
	public function test_runner_does_not_import_large_pdf_image_bytes_as_text() {
		$image       = "\xff\xd8BT\n/F1 12 Tf\n72 720 Td\n(THIS SHOULD NOT BECOME TEXT) Tj\nET\xff\xd9";
		$source_file = $this->temporary_pdf_with_embedded_image(
			'large-image-bytes-as-text.pdf',
			'q 1 0 0 1 0 0 cm /Im1 Do Q',
			'DCTDecode',
			$image
		);
		file_put_contents( $source_file, str_repeat( '0', SourceItemDocumentProcessor::PDF_FILE_LIMIT ), FILE_APPEND );
		$previous_text = getenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND' );
		$previous_ocr  = getenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );
		$session       = ImportSession::start_for_source( $source_file );
		$posts         = new FakePostGateway();
		$media         = new FakeMediaGateway();
		$cache         = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$this->store->save( $session );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates PDF fallback command configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates OCR command configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );

		try {
			$runner  = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache );
			$attempt = 0;
			while ( $attempt < 20 && ImportSession::STATUS_DONE !== $this->store->find( $session->get_id() )->get_status() ) {
				$runner->run( $session->get_id() );
				++$attempt;
			}
		} finally {
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND', $previous_text );
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND', $previous_ocr );
		}

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$document   = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$post       = $posts->get_post( 1 );
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );

		$this->assertSame( ImportSession::STATUS_DONE, $this->store->find( $session->get_id() )->get_status() );
		$this->assertSame( 'native', $metadata['pdf_text_engine'] );
		$this->assertSame( 'no_text_assets_imported', $metadata['pdf_text_extraction_status'] );
		$this->assertCount( 1, $references );
		$this->assertSame( 1, $media->count_attachments() );
		$this->assertNotNull( $document );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertStringContainsString( '<!-- wp:image -->', $post['post_content'] );
		$this->assertStringContainsString( 'https://local.example.test/wp-content/uploads/embedded-image-1-', $post['post_content'] );
		$this->assertStringNotContainsString( 'uwi-pdf-asset://', $post['post_content'] );
		$this->assertStringNotContainsString( 'THIS SHOULD NOT BECOME TEXT', $post['post_content'] );
		$this->assertStringNotContainsString( 'THIS SHOULD NOT BECOME TEXT', $document->get_block_markup() );
	}

	/**
	 * Large PDF font program streams are not tokenized as page text content.
	 *
	 * @return void
	 */
	public function test_runner_does_not_import_large_pdf_font_program_bytes_as_text() {
		$source_file   = $this->temporary_pdf_with_font_program_stream( 'large-font-program-bytes-as-text.pdf' );
		$previous_text = getenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND' );
		$previous_ocr  = getenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );
		$session       = ImportSession::start_for_source( $source_file );
		$posts         = new FakePostGateway();
		$cache         = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$this->store->save( $session );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates PDF fallback command configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates OCR command configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );

		try {
			$runner  = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', null, null, null, null, $cache );
			$attempt = 0;
			while ( $attempt < 20 && ImportSession::STATUS_DONE !== $this->store->find( $session->get_id() )->get_status() ) {
				$runner->run( $session->get_id() );
				++$attempt;
			}
		} finally {
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND', $previous_text );
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND', $previous_ocr );
		}

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );

		$this->assertSame( ImportSession::STATUS_DONE, $this->store->find( $session->get_id() )->get_status() );
		$this->assertNotNull( $document );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertStringContainsString( 'Readable PDF', $document->get_block_markup() );
		$this->assertStringContainsString( 'Actual page body', $document->get_block_markup() );
		$this->assertStringNotContainsString( 'THIS SHOULD NOT BECOME TEXT', $document->get_block_markup() );
		$this->assertStringNotContainsString( 'THIS SHOULD NOT BECOME TEXT', $posts->get_post( 1 )['post_content'] );
	}

	/**
	 * Streamed native PDF text uses early font resources to decode one-byte glyph strings.
	 *
	 * @return void
	 */
	public function test_runner_decodes_large_pdf_one_byte_text_with_to_unicode_map() {
		$source_file   = $this->temporary_large_pdf_with_to_unicode_text( 'large-to-unicode-one-byte-text.pdf' );
		$previous_text = getenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND' );
		$previous_ocr  = getenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );
		$session       = ImportSession::start_for_source( $source_file );
		$posts         = new FakePostGateway();
		$cache         = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$this->store->save( $session );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates PDF fallback command configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates OCR command configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );

		try {
			$runner  = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', null, null, null, null, $cache );
			$attempt = 0;
			while ( $attempt < 30 && ImportSession::STATUS_DONE !== $this->store->find( $session->get_id() )->get_status() ) {
				$runner->run( $session->get_id() );
				++$attempt;
			}
		} finally {
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND', $previous_text );
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND', $previous_ocr );
		}

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );

		$this->assertSame( ImportSession::STATUS_DONE, $this->store->find( $session->get_id() )->get_status() );
		$this->assertNotNull( $document );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertStringContainsString( 'You build', $document->get_block_markup() );
		$this->assertStringNotContainsString( '&lt;RX', $document->get_block_markup() );
		$this->assertStringNotContainsString( 'EXLOG', $document->get_block_markup() );
	}

	/**
	 * Non-JPEG embedded PDF image streams remain observable instead of disappearing silently.
	 *
	 * @return void
	 */
	public function test_runner_records_unsupported_pdf_embedded_image_filters() {
		$source_file = $this->temporary_pdf_with_embedded_image(
			'jpx-image.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(# PDF With Unsupported Image\\n\\nBody before image.) Tj\nET\nq 1 0 0 1 0 0 cm /Im1 Do Q",
			'JPXDecode',
			'not-a-real-jpeg2000-stream'
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();
		$cache       = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache ) )->run( $session->get_id() );

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );
		$document   = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$events     = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( 'unsupported', $metadata['pdf_embedded_media_extraction_status'] );
		$this->assertSame( 1, $metadata['pdf_unsupported_embedded_media_count'] );
		$this->assertSame( array( 'JPXDecode' ), $metadata['pdf_unsupported_embedded_media_filters'] );
		$this->assertSame( array( 'unsupported_filter' ), $metadata['pdf_unsupported_embedded_media_reasons'] );
		$this->assertStringContainsString( 'Unsupported filters: JPXDecode', $metadata['pdf_embedded_media_hint'] );
		$this->assertCount( 0, $references );
		$this->assertSame( 0, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $document );
		$this->assertSame( $metadata['pdf_unsupported_embedded_media_filters'], $document->get_metadata()['pdf_unsupported_embedded_media_filters'] );
		$this->assertContains( 'media.pdf_asset_unsupported', $events );
	}

	/**
	 * Chained PDF image filters are diagnosed instead of queued as raw JPEG media.
	 *
	 * @return void
	 */
	public function test_runner_records_chained_pdf_embedded_image_filters() {
		$source_file = $this->temporary_pdf_with_embedded_image_filter_expression(
			'chained-image-filter.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(# PDF With Chained Image Filter\\n\\nBody before image.) Tj\nET\nq 1 0 0 1 0 0 cm /Im1 Do Q",
			'[/ASCII85Decode /DCTDecode]',
			'not-decoded-jpeg-data'
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();
		$cache       = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache ) )->run( $session->get_id() );

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );
		$document   = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );

		$this->assertSame( 'unsupported', $metadata['pdf_embedded_media_extraction_status'] );
		$this->assertSame( 1, $metadata['pdf_unsupported_embedded_media_count'] );
		$this->assertSame( array( 'ASCII85Decode+DCTDecode' ), $metadata['pdf_unsupported_embedded_media_filters'] );
		$this->assertSame( array( 'unsupported_filter' ), $metadata['pdf_unsupported_embedded_media_reasons'] );
		$this->assertStringContainsString( 'ASCII85Decode+DCTDecode', $metadata['pdf_embedded_media_hint'] );
		$this->assertCount( 0, $references );
		$this->assertSame( 0, $media->count_attachments() );
		$this->assertNotNull( $document );
		$this->assertSame( $metadata['pdf_unsupported_embedded_media_filters'], $document->get_metadata()['pdf_unsupported_embedded_media_filters'] );
	}

	/**
	 * Abbreviated chained PDF image filters are normalized before diagnostics.
	 *
	 * @return void
	 */
	public function test_runner_records_abbreviated_chained_pdf_embedded_image_filters() {
		$source_file = $this->temporary_pdf_with_embedded_image_filter_expression(
			'abbreviated-chained-image-filter.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(# PDF With Abbreviated Chained Image Filter\\n\\nBody before image.) Tj\nET\nq 1 0 0 1 0 0 cm /Im1 Do Q",
			'[/A85 /DCT]',
			'not-decoded-jpeg-data'
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();
		$cache       = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache ) )->run( $session->get_id() );

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );
		$document   = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );

		$this->assertSame( 'unsupported', $metadata['pdf_embedded_media_extraction_status'] );
		$this->assertSame( 1, $metadata['pdf_unsupported_embedded_media_count'] );
		$this->assertSame( array( 'ASCII85Decode+DCTDecode' ), $metadata['pdf_unsupported_embedded_media_filters'] );
		$this->assertSame( array( 'unsupported_filter' ), $metadata['pdf_unsupported_embedded_media_reasons'] );
		$this->assertStringContainsString( 'ASCII85Decode+DCTDecode', $metadata['pdf_embedded_media_hint'] );
		$this->assertCount( 0, $references );
		$this->assertSame( 0, $media->count_attachments() );
		$this->assertNotNull( $document );
		$this->assertSame( $metadata['pdf_unsupported_embedded_media_filters'], $document->get_metadata()['pdf_unsupported_embedded_media_filters'] );
	}

	/**
	 * JPEG image streams with unresolved dimensions are diagnosed instead of queued with zero-sized metadata.
	 *
	 * @return void
	 */
	public function test_runner_records_pdf_embedded_image_missing_dimensions() {
		$image = base64_decode( $this->tiny_jpeg_base64(), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Unit test embeds a tiny binary JPEG fixture in a generated PDF.
		$this->assertIsString( $image );

		$source_file = $this->temporary_pdf_with_embedded_image_dictionary(
			'indirect-image-dimensions.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(# PDF With Indirect Image Dimensions\\n\\nBody before image.) Tj\nET\nq 1 0 0 1 0 0 cm /Im1 Do Q",
			'/Width 6 0 R /Height 1 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode',
			$image,
			"6 0 obj 1 endobj\n"
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();
		$cache       = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache ) )->run( $session->get_id() );

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED, ImportMediaReference::STATUS_IMPORTED, ImportMediaReference::STATUS_FAILED ), 5 );
		$document   = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$events     = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( 'unsupported', $metadata['pdf_embedded_media_extraction_status'] );
		$this->assertSame( 1, $metadata['pdf_unsupported_embedded_media_count'] );
		$this->assertSame( array( 'DCTDecode' ), $metadata['pdf_unsupported_embedded_media_filters'] );
		$this->assertSame( array( 'missing_dimensions' ), $metadata['pdf_unsupported_embedded_media_reasons'] );
		$this->assertStringContainsString( 'missing or indirect dimensions', $metadata['pdf_embedded_media_hint'] );
		$this->assertCount( 0, $references );
		$this->assertSame( 0, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $document );
		$this->assertSame( $metadata['pdf_unsupported_embedded_media_reasons'], $document->get_metadata()['pdf_unsupported_embedded_media_reasons'] );
		$this->assertContains( 'media.pdf_asset_unsupported', $events );
	}

	/**
	 * Small embedded JPEG streams are treated as likely layout artifacts instead of imported media.
	 *
	 * @return void
	 */
	public function test_runner_skips_small_pdf_embedded_image_streams() {
		$image = base64_decode( $this->tiny_jpeg_base64(), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Unit test embeds a tiny binary JPEG fixture in a generated PDF.
		$this->assertIsString( $image );

		$source_file = $this->temporary_pdf_with_embedded_image_dictionary(
			'small-embedded-image.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(# PDF With Small Image\\n\\nBody before image.) Tj\nET\nq 1 0 0 1 0 0 cm /Im1 Do Q",
			'/Width 49 /Height 64 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode',
			$image,
			''
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();
		$cache       = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache ) )->run( $session->get_id() );

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED, ImportMediaReference::STATUS_IMPORTED, ImportMediaReference::STATUS_FAILED ), 5 );
		$document   = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$events     = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( 'unsupported', $metadata['pdf_embedded_media_extraction_status'] );
		$this->assertSame( 1, $metadata['pdf_unsupported_embedded_media_count'] );
		$this->assertSame( array( 'DCTDecode' ), $metadata['pdf_unsupported_embedded_media_filters'] );
		$this->assertSame( array( 'small_dimensions' ), $metadata['pdf_unsupported_embedded_media_reasons'] );
		$this->assertStringContainsString( 'smaller than 50x50px', $metadata['pdf_embedded_media_hint'] );
		$this->assertCount( 0, $references );
		$this->assertSame( 0, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $document );
		$this->assertSame( $metadata['pdf_unsupported_embedded_media_reasons'], $document->get_metadata()['pdf_unsupported_embedded_media_reasons'] );
		$this->assertContains( 'media.pdf_asset_unsupported', $events );
	}

	/**
	 * Empty JPEG image streams are diagnosed instead of disappearing from embedded media metadata.
	 *
	 * @return void
	 */
	public function test_runner_records_empty_pdf_embedded_image_streams() {
		$source_file = $this->temporary_pdf_with_embedded_image(
			'empty-embedded-image.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(# PDF With Empty Image Stream\\n\\nBody before image.) Tj\nET\nq 1 0 0 1 0 0 cm /Im1 Do Q",
			'DCTDecode',
			''
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();
		$cache       = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache ) )->run( $session->get_id() );

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED, ImportMediaReference::STATUS_IMPORTED, ImportMediaReference::STATUS_FAILED ), 5 );
		$document   = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$events     = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( 'unsupported', $metadata['pdf_embedded_media_extraction_status'] );
		$this->assertSame( 1, $metadata['pdf_unsupported_embedded_media_count'] );
		$this->assertSame( array( 'DCTDecode' ), $metadata['pdf_unsupported_embedded_media_filters'] );
		$this->assertSame( array( 'empty_stream' ), $metadata['pdf_unsupported_embedded_media_reasons'] );
		$this->assertStringContainsString( 'embedded JPEG image stream was empty', $metadata['pdf_embedded_media_hint'] );
		$this->assertCount( 0, $references );
		$this->assertSame( 0, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $document );
		$this->assertSame( $metadata['pdf_unsupported_embedded_media_reasons'], $document->get_metadata()['pdf_unsupported_embedded_media_reasons'] );
		$this->assertContains( 'media.pdf_asset_unsupported', $events );
	}

	/**
	 * Malformed JPEG image streams at EOF are diagnosed instead of disappearing from embedded media metadata.
	 *
	 * @return void
	 */
	public function test_runner_records_malformed_pdf_embedded_image_streams() {
		$image = base64_decode( $this->tiny_jpeg_base64(), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Unit test embeds a tiny binary JPEG fixture in a generated PDF.
		$this->assertIsString( $image );

		$source_file = $this->temporary_pdf_with_malformed_embedded_image_stream(
			'malformed-embedded-image.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(# PDF With Malformed Image Stream\\n\\nBody before image.) Tj\nET\nq 1 0 0 1 0 0 cm /Im1 Do Q",
			$image
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();
		$cache       = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache ) )->run( $session->get_id() );

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED, ImportMediaReference::STATUS_IMPORTED, ImportMediaReference::STATUS_FAILED ), 5 );
		$document   = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$events     = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( 'unsupported', $metadata['pdf_embedded_media_extraction_status'] );
		$this->assertSame( 1, $metadata['pdf_unsupported_embedded_media_count'] );
		$this->assertSame( array( 'DCTDecode' ), $metadata['pdf_unsupported_embedded_media_filters'] );
		$this->assertSame( array( 'malformed_stream' ), $metadata['pdf_unsupported_embedded_media_reasons'] );
		$this->assertStringContainsString( 'missing its stream terminator', $metadata['pdf_embedded_media_hint'] );
		$this->assertCount( 0, $references );
		$this->assertSame( 0, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $document );
		$this->assertSame( $metadata['pdf_unsupported_embedded_media_reasons'], $document->get_metadata()['pdf_unsupported_embedded_media_reasons'] );
		$this->assertContains( 'media.pdf_asset_unsupported', $events );
	}

	/**
	 * A malformed image stream does not hide later valid embedded JPEG streams.
	 *
	 * @return void
	 */
	public function test_runner_continues_after_malformed_pdf_embedded_image_streams() {
		$image = base64_decode( $this->tiny_jpeg_base64(), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Unit test embeds a tiny binary JPEG fixture in a generated PDF.
		$this->assertIsString( $image );

		$source_file = $this->temporary_pdf_with_malformed_then_valid_embedded_images(
			'malformed-then-valid-image.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(# PDF With One Broken And One Valid Image\\n\\nBody before images.) Tj\nET\nq 1 0 0 1 0 0 cm /Im1 Do Q\nq 1 0 0 1 0 0 cm /Im2 Do Q",
			$image
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();
		$cache       = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache ) )->run( $session->get_id() );

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );
		$document   = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$events     = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 30 )
		);

		$this->assertSame( 'partial', $metadata['pdf_embedded_media_extraction_status'] );
		$this->assertSame( 1, $metadata['pdf_embedded_media_queued'] );
		$this->assertSame( 1, $metadata['pdf_unsupported_embedded_media_count'] );
		$this->assertSame( array( 'DCTDecode' ), $metadata['pdf_unsupported_embedded_media_filters'] );
		$this->assertSame( array( 'malformed_stream' ), $metadata['pdf_unsupported_embedded_media_reasons'] );
		$this->assertStringContainsString( 'missing its stream terminator', $metadata['pdf_embedded_media_hint'] );
		$this->assertCount( 1, $references );
		$this->assertSame( '6', $references[0]->get_metadata()['pdf_image_object'] );
		$this->assertSame( 1, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $document );
		$this->assertSame( $metadata['pdf_unsupported_embedded_media_reasons'], $document->get_metadata()['pdf_unsupported_embedded_media_reasons'] );
		$this->assertContains( 'media.pdf_asset_queued', $events );
		$this->assertContains( 'media.pdf_asset_unsupported', $events );
	}

	/**
	 * DCTDecode streams without JPEG bytes are diagnosed instead of queued as invalid attachments.
	 *
	 * @return void
	 */
	public function test_runner_records_invalid_pdf_jpeg_payloads() {
		$source_file = $this->temporary_pdf_with_embedded_image(
			'invalid-jpeg-payload.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(# PDF With Invalid JPEG Payload\\n\\nBody before image.) Tj\nET\nq 1 0 0 1 0 0 cm /Im1 Do Q",
			'DCTDecode',
			'not-a-jpeg-payload'
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();
		$cache       = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache ) )->run( $session->get_id() );

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED, ImportMediaReference::STATUS_IMPORTED, ImportMediaReference::STATUS_FAILED ), 5 );
		$document   = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$events     = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( 'unsupported', $metadata['pdf_embedded_media_extraction_status'] );
		$this->assertSame( 1, $metadata['pdf_unsupported_embedded_media_count'] );
		$this->assertSame( array( 'DCTDecode' ), $metadata['pdf_unsupported_embedded_media_filters'] );
		$this->assertSame( array( 'invalid_jpeg' ), $metadata['pdf_unsupported_embedded_media_reasons'] );
		$this->assertStringContainsString( 'recognizable JPEG payload', $metadata['pdf_embedded_media_hint'] );
		$this->assertCount( 0, $references );
		$this->assertSame( 0, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $document );
		$this->assertSame( $metadata['pdf_unsupported_embedded_media_reasons'], $document->get_metadata()['pdf_unsupported_embedded_media_reasons'] );
		$this->assertContains( 'media.pdf_asset_unsupported', $events );
	}

	/**
	 * Oversized embedded PDF JPEG streams are diagnosed without entering the media pipeline.
	 *
	 * @return void
	 */
	public function test_runner_records_oversized_pdf_embedded_image_streams() {
		$source_file = $this->temporary_pdf_with_embedded_image(
			'oversized-jpeg.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(# PDF With Oversized Image\\n\\nBody before image.) Tj\nET\nq 1 0 0 1 0 0 cm /Im1 Do Q",
			'DCTDecode',
			str_repeat( "\xff", SourceItemDocumentProcessor::PDF_MEDIA_FILE_LIMIT + 1 )
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();
		$cache       = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$this->store->save( $session );

		$this->assertLessThan( SourceItemDocumentProcessor::PDF_FILE_LIMIT, filesize( $source_file ) );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache ) )->run( $session->get_id() );

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED, ImportMediaReference::STATUS_IMPORTED, ImportMediaReference::STATUS_FAILED ), 5 );
		$document   = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$events     = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( 'unsupported', $metadata['pdf_embedded_media_extraction_status'] );
		$this->assertSame( 1, $metadata['pdf_unsupported_embedded_media_count'] );
		$this->assertSame( array( 'DCTDecode' ), $metadata['pdf_unsupported_embedded_media_filters'] );
		$this->assertSame( array( 'file_size_limit' ), $metadata['pdf_unsupported_embedded_media_reasons'] );
		$this->assertSame( SourceItemDocumentProcessor::PDF_MEDIA_FILE_LIMIT, $metadata['pdf_embedded_media_file_limit_bytes'] );
		$this->assertStringContainsString( '8 MiB', $metadata['pdf_embedded_media_hint'] );
		$this->assertCount( 0, $references );
		$this->assertSame( 0, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $document );
		$this->assertSame( $metadata['pdf_embedded_media_file_limit_bytes'], $document->get_metadata()['pdf_embedded_media_file_limit_bytes'] );
		$this->assertContains( 'media.pdf_asset_unsupported', $events );
	}

	/**
	 * The bounded per-PDF embedded media limit is persisted when a PDF contains many JPEG streams.
	 *
	 * @return void
	 */
	public function test_runner_records_pdf_embedded_media_extraction_limit() {
		$source_file = $this->temporary_pdf_with_jpeg_images(
			'many-images.pdf',
			SourceItemDocumentProcessor::PDF_MEDIA_LIMIT + 1
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();
		$cache       = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$runner      = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache );
		$this->store->save( $session );

		$attempt = 0;
		while ( $attempt < 10 && ImportSession::STATUS_DONE !== $this->store->find( $session->get_id() )->get_status() ) {
			$runner->run( $session->get_id() );
			++$attempt;
		}

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata   = $items[0]->get_metadata();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 20 );
		$document   = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$events     = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 40 )
		);

		$this->assertSame( 'partial', $metadata['pdf_embedded_media_extraction_status'] );
		$this->assertSame( SourceItemDocumentProcessor::PDF_MEDIA_LIMIT, $metadata['pdf_embedded_media_queued'] );
		$this->assertSame( 1, $metadata['pdf_unsupported_embedded_media_count'] );
		$this->assertSame( array( 'extraction_limit' ), $metadata['pdf_unsupported_embedded_media_reasons'] );
		$this->assertSame( SourceItemDocumentProcessor::PDF_MEDIA_LIMIT, $metadata['pdf_embedded_media_limit'] );
		$this->assertStringContainsString( 'limit of ' . SourceItemDocumentProcessor::PDF_MEDIA_LIMIT . ' assets', $metadata['pdf_embedded_media_hint'] );
		$this->assertCount( SourceItemDocumentProcessor::PDF_MEDIA_LIMIT, $references );
		$this->assertSame( SourceItemDocumentProcessor::PDF_MEDIA_LIMIT, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $document );
		$this->assertSame( $metadata['pdf_embedded_media_limit'], $document->get_metadata()['pdf_embedded_media_limit'] );
		$this->assertContains( 'media.pdf_asset_queued', $events );
		$this->assertContains( 'media.pdf_asset_unsupported', $events );
	}

	/**
	 * Native PDF text streams are scanned through a durable cursor when they exceed one bounded pass.
	 *
	 * @return void
	 */
	public function test_runner_resumes_pdf_native_text_scan_from_durable_cursor() {
		$source_file = $this->temporary_pdf_with_text_streams(
			'many-text-streams.pdf',
			SourceItemDocumentProcessor::PDF_TEXT_SCAN_LIMIT + 1
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$runner      = new ImportRunner( $this->store, 'unit-test', 60, null, $posts );
		$this->store->save( $session );

		$partial_metadata = $this->run_pdf_until_processing_phase( $runner, $session->get_id(), 'text_scan', 5 );
		$events           = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( 'partial', $partial_metadata['processor_status'] );
		$this->assertSame( 'text_scan', $partial_metadata['pdf_processing_phase'] );
		$this->assertSame( SourceItemDocumentProcessor::PDF_TEXT_SCAN_LIMIT, $partial_metadata['pdf_text_stream_index'] );
		$this->assertSame( SourceItemDocumentProcessor::PDF_TEXT_SCAN_LIMIT, $partial_metadata['pdf_text_chunk_index'] );
		$this->assertGreaterThan( 0, $partial_metadata['pdf_text_next_offset'] );
		$this->assertSame( 0, $partial_metadata['pdf_text_scan_read_offset'] );
		$this->assertSame( SourceItemDocumentProcessor::PDF_TEXT_SCAN_LIMIT, count( $partial_metadata['pdf_text_fragments'] ) );
		$this->assertSame( 0, $this->store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 0, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' ) );
		$this->assertSame( 0, $posts->count_posts() );
		$this->assertContains( 'document.pdf_text_progress', $events );

		$runner->run( $session->get_id() );

		$items  = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$events = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 40 )
		);

		$this->assertSame( ImportSession::STATUS_DONE, $this->store->find( $session->get_id() )->get_status() );
		$this->assertCount( 1, $items );
		$metadata = $items[0]->get_metadata();
		$this->assertSame( 'imported', $metadata['processor_status'] );
		$this->assertSame( $partial_metadata['pdf_text_next_offset'], $metadata['pdf_text_scan_read_offset'] );
		$this->assertArrayNotHasKey( 'pdf_text_next_offset', $metadata );
		$this->assertArrayNotHasKey( 'pdf_text_stream_index', $metadata );
		$this->assertArrayNotHasKey( 'pdf_text_chunk_index', $metadata );
		$this->assertArrayNotHasKey( 'pdf_text_fragments', $metadata );
		$this->assertSame( SourceItemDocumentProcessor::PDF_TEXT_SCAN_LIMIT + 1, $metadata['pdf_text_chunks_prepared'] );
		$this->assertSame( 1, $this->store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 1, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' ) );
		$this->assertSame( 1, $posts->count_posts() );
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$this->assertNotNull( $document );
		$this->assertStringNotContainsString( 'part 1', $document->get_title() );
		$this->assertStringContainsString( 'Chunk body 1', $document->get_block_markup() );
		$this->assertStringContainsString( 'Chunk body 6', $document->get_block_markup() );
		$this->assertContains( 'document.pdf_text_complete', $events );
	}

	/**
	 * Incremental native PDF text scans retain object-stream fallback diagnostics.
	 *
	 * @return void
	 */
	public function test_runner_records_pdf_object_stream_diagnostics_during_native_text_scan() {
		$source_file = $this->temporary_pdf_with_text_streams_and_object_stream(
			'many-text-streams-object-stream.pdf',
			SourceItemDocumentProcessor::PDF_TEXT_SCAN_LIMIT + 1
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$runner      = new ImportRunner( $this->store, 'unit-test', 60, null, $posts );
		$this->store->save( $session );

		$partial_metadata = $this->run_pdf_until_processing_phase( $runner, $session->get_id(), 'text_scan', 5 );
		$events           = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 30 )
		);

		$this->assertSame( 'partial', $partial_metadata['processor_status'] );
		$this->assertSame( 'text_scan', $partial_metadata['pdf_processing_phase'] );
		$this->assertSame( 'limited', $partial_metadata['pdf_structure_status'] );
		$this->assertSame( array( 'object_streams_present' ), $partial_metadata['pdf_structure_reasons'] );
		$this->assertSame( 1, $partial_metadata['pdf_object_stream_count'] );
		$this->assertSame( 0, $partial_metadata['pdf_text_scan_read_offset'] );
		$this->assertStringContainsString( 'Compressed object streams were detected', $partial_metadata['pdf_structure_warning'] );
		$this->assertContains( 'document.pdf_structure_warning', $events );
		$this->assertContains( 'document.pdf_text_progress', $events );

		$runner->run( $session->get_id() );

		$items  = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$events = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 50 )
		);

		$this->assertSame( ImportSession::STATUS_DONE, $this->store->find( $session->get_id() )->get_status() );
		$this->assertCount( 1, $items );
		$metadata = $items[0]->get_metadata();
		$this->assertSame( 'imported', $metadata['processor_status'] );
		$this->assertSame( $partial_metadata['pdf_text_next_offset'], $metadata['pdf_text_scan_read_offset'] );
		$this->assertArrayNotHasKey( 'pdf_text_next_offset', $metadata );
		$this->assertArrayNotHasKey( 'pdf_text_stream_index', $metadata );
		$this->assertArrayNotHasKey( 'pdf_text_chunk_index', $metadata );
		$this->assertArrayNotHasKey( 'pdf_text_fragments', $metadata );
		$this->assertSame( SourceItemDocumentProcessor::PDF_TEXT_SCAN_LIMIT + 1, $metadata['pdf_text_chunks_prepared'] );
		$this->assertSame( 'limited', $metadata['pdf_structure_status'] );
		$this->assertSame( array( 'object_streams_present' ), $metadata['pdf_structure_reasons'] );
		$this->assertSame( 1, $metadata['pdf_object_stream_count'] );
		$this->assertStringContainsString( 'Compressed object streams were detected', $metadata['pdf_structure_warning'] );
		$this->assertSame( 1, $this->store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 1, $posts->count_posts() );

		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$this->assertNotNull( $document );
		$this->assertSame( $metadata['pdf_structure_warning'], $document->get_metadata()['pdf_structure_warning'] );
		$this->assertStringContainsString( 'Chunk body 1', $document->get_block_markup() );
		$this->assertStringContainsString( 'Chunk body 6', $document->get_block_markup() );
		$this->assertContains( 'document.pdf_structure_warning', $events );
		$this->assertContains( 'document.pdf_text_complete', $events );
	}

	/**
	 * Textless PDFs can use an operator-configured external text extractor before OCR.
	 *
	 * @return void
	 */
	public function test_runner_prepares_pdf_with_configured_external_text_command() {
		$source_file      = $this->temporary_pdf( 'external.pdf', 'q 10 0 0 10 0 0 cm /Im1 Do Q', true );
		$previous_text    = getenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND' );
		$previous_timeout = getenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_TIMEOUT' );
		$previous_ocr     = getenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );
		$php_code         = 'if (!is_file($argv[1])) { fwrite(STDERR, "missing input"); exit(3); } file_put_contents($argv[2], "External PDF Title\n\nExternal body https://source.example.test/external");';
		$command          = escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $php_code ) . ' {input} {output}';
		$session          = ImportSession::start_for_source( $source_file );
		$posts            = new FakePostGateway();
		$this->store->save( $session );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Confirm first-party domains before URL rewriting.',
				array( 'domains' => array( 'source.example.test' ) )
			)->resolve( array( 'confirmed_domains' => array( 'source.example.test' ) ) )
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates external PDF text command configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND=' . $command );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates external PDF text timeout configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_TIMEOUT=5' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test ensures OCR is not needed for this path.
		putenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );

		try {
			( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/' ) )->run( $session->get_id() );
		} finally {
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND', $previous_text );
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_TEXT_TIMEOUT', $previous_timeout );
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND', $previous_ocr );
		}

		$restored = $this->store->find( $session->get_id() );
		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata = $items[0]->get_metadata();
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$events   = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertSame( 'pdf', $metadata['document_format'] );
		$this->assertSame( 'external', $metadata['pdf_text_engine'] );
		$this->assertSame( 'succeeded', $metadata['pdf_external_text_status'] );
		$this->assertNotNull( $document );
		$this->assertSame( 'external', $document->get_metadata()['pdf_text_engine'] );
		$this->assertSame( 'succeeded', $document->get_metadata()['pdf_external_text_status'] );
		$this->assertStringContainsString( 'External PDF Title', $document->get_block_markup() );
		$this->assertStringContainsString( 'External body https://local.example.test/external', $document->get_block_markup() );
		$this->assertSame( array( 'source.example.test' ), $document->get_metadata()['absolute_url_domains'] );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertSame( 1, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' ) );
		$this->assertSame( 1, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'post' ) );
		$this->assertContains( 'session.done', $events );
	}

	/**
	 * Object-stream PDFs can use the configured external text extractor while preserving diagnostics.
	 *
	 * @return void
	 */
	public function test_runner_uses_external_text_command_for_pdf_object_stream_fallback() {
		$source_file      = $this->temporary_pdf_with_object_stream(
			'object-stream-external.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n(Native Object Stream Text) Tj\nET"
		);
		$previous_text    = getenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND' );
		$previous_timeout = getenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_TIMEOUT' );
		$previous_ocr     = getenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );
		$php_code         = 'if (!is_file($argv[1])) { fwrite(STDERR, "missing input"); exit(3); } file_put_contents($argv[2], "Object Stream External Title\n\nRecovered object stream body.");';
		$command          = escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $php_code ) . ' {input} {output}';
		$session          = ImportSession::start_for_source( $source_file );
		$posts            = new FakePostGateway();
		$this->store->save( $session );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates external PDF text command configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND=' . $command );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates external PDF text timeout configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_TIMEOUT=5' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test ensures OCR is not needed for this path.
		putenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );

		try {
			( new ImportRunner( $this->store, 'unit-test', 60, null, $posts ) )->run( $session->get_id() );
		} finally {
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND', $previous_text );
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_TEXT_TIMEOUT', $previous_timeout );
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND', $previous_ocr );
		}

		$restored = $this->store->find( $session->get_id() );
		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata = $items[0]->get_metadata();
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$events   = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertSame( 'external', $metadata['pdf_text_engine'] );
		$this->assertSame( 'succeeded', $metadata['pdf_external_text_status'] );
		$this->assertSame( 'limited', $metadata['pdf_structure_status'] );
		$this->assertSame( array( 'object_streams_present' ), $metadata['pdf_structure_reasons'] );
		$this->assertSame( 1, $metadata['pdf_object_stream_count'] );
		$this->assertStringContainsString( 'Compressed object streams were detected', $metadata['pdf_structure_warning'] );
		$this->assertNotNull( $document );
		$this->assertSame( $metadata['pdf_structure_warning'], $document->get_metadata()['pdf_structure_warning'] );
		$this->assertStringContainsString( 'Object Stream External Title', $document->get_block_markup() );
		$this->assertStringContainsString( 'Recovered object stream body.', $document->get_block_markup() );
		$this->assertStringNotContainsString( 'Native Object Stream Text', $document->get_block_markup() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertContains( 'document.pdf_structure_warning', $events );
		$this->assertContains( 'session.done', $events );
	}

	/**
	 * Layout-aware external PDF text can become first-pass table blocks.
	 *
	 * @return void
	 */
	public function test_runner_converts_layout_aware_pdf_text_tables_to_blocks() {
		$source_file      = $this->temporary_pdf( 'table-external.pdf', 'q 10 0 0 10 0 0 cm /Im1 Do Q', true );
		$previous_text    = getenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND' );
		$previous_timeout = getenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_TIMEOUT' );
		$previous_ocr     = getenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );
		$php_code         = 'file_put_contents($argv[2], "# Layout PDF\n\nName      Count    Total\nAlpha     2        \$10\nBeta      3        \$12\n\nAfter table.");';
		$command          = escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $php_code ) . ' {input} {output}';
		$session          = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates external PDF text command configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND=' . $command );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates external PDF text timeout configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_TIMEOUT=5' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test ensures OCR is not needed for this path.
		putenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );

		try {
			( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );
		} finally {
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND', $previous_text );
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_TEXT_TIMEOUT', $previous_timeout );
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND', $previous_ocr );
		}

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata = $items[0]->get_metadata();
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$events   = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( 'external', $metadata['pdf_text_engine'] );
		$this->assertSame( 1, $metadata['pdf_table_block_count'] );
		$this->assertSame( 3, $metadata['pdf_table_row_count'] );
		$this->assertSame( 3, $metadata['pdf_table_max_column_count'] );
		$this->assertStringContainsString( 'converted to WordPress table blocks', $metadata['pdf_layout_warning'] );
		$this->assertNotNull( $document );
		$this->assertStringContainsString( '<!-- wp:table -->', $document->get_block_markup() );
		$this->assertStringContainsString( '<td>Name</td><td>Count</td><td>Total</td>', $document->get_block_markup() );
		$this->assertStringContainsString( '<td>Alpha</td><td>2</td><td>$10</td>', $document->get_block_markup() );
		$this->assertStringContainsString( 'After table.', $document->get_block_markup() );
		$this->assertContains( 'document.pdf_table_blocks', $events );
	}

	/**
	 * Failed external PDF text helpers preserve actionable diagnostics.
	 *
	 * @return void
	 */
	public function test_runner_records_failed_external_pdf_text_command_diagnostics() {
		$source_file      = $this->temporary_pdf( 'external-broken.pdf', 'q 10 0 0 10 0 0 cm /Im1 Do Q', true );
		$previous_text    = getenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND' );
		$previous_timeout = getenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_TIMEOUT' );
		$previous_ocr     = getenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );
		$php_code         = 'fwrite(STDERR, "external helper exploded"); exit(7);';
		$command          = escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $php_code ) . ' {input} {output}';
		$session          = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates external PDF text command configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND=' . $command );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates external PDF text timeout configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_TIMEOUT=5' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test ensures OCR does not mask the external helper failure.
		putenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );

		try {
			( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );
		} finally {
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND', $previous_text );
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_TEXT_TIMEOUT', $previous_timeout );
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND', $previous_ocr );
		}

		$items = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ), 1 );

		$this->assertCount( 1, $items );
		$metadata = $items[0]->get_metadata();

		$this->assertSame( 'pdf', $metadata['document_format'] );
		$this->assertSame( 'failed', $metadata['pdf_external_text_status'] );
		$this->assertStringContainsString( 'External PDF text command failed with exit code 7', $metadata['error'] );
		$this->assertStringContainsString( 'external helper exploded', $metadata['error'] );
		$this->assertStringContainsString( 'external helper exploded', $metadata['pdf_external_text_error'] );
		$this->assertSame( 'not_configured', $metadata['pdf_ocr_status'] );
		$this->assertStringContainsString( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND', $metadata['pdf_ocr_hint'] );
	}

	/**
	 * Scanned PDFs can use an operator-configured OCR command.
	 *
	 * @return void
	 */
	public function test_runner_prepares_scanned_pdf_with_configured_ocr_command() {
		$source_file      = $this->temporary_pdf( 'scanned.pdf', 'q 10 0 0 10 0 0 cm /Im1 Do Q', true );
		$previous_command = getenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );
		$previous_timeout = getenv( 'UNIVERSAL_IMPORTER_PDF_OCR_TIMEOUT' );
		$php_code         = 'if (!is_file($argv[1])) { fwrite(STDERR, "missing input"); exit(3); } file_put_contents($argv[2], "OCR Title\n\nOCR body https://source.example.test/scan");';
		$command          = escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $php_code ) . ' {input} {output}';
		$session          = ImportSession::start_for_source( $source_file );
		$posts            = new FakePostGateway();
		$this->store->save( $session );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Confirm first-party domains before URL rewriting.',
				array( 'domains' => array( 'source.example.test' ) )
			)->resolve( array( 'confirmed_domains' => array( 'source.example.test' ) ) )
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates OCR command configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND=' . $command );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates OCR timeout configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_OCR_TIMEOUT=5' );

		try {
			( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/' ) )->run( $session->get_id() );
		} finally {
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND', $previous_command );
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_OCR_TIMEOUT', $previous_timeout );
		}

		$restored = $this->store->find( $session->get_id() );
		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$metadata = $items[0]->get_metadata();
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$events   = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertSame( 'pdf', $metadata['document_format'] );
		$this->assertSame( 'ocr', $metadata['pdf_text_engine'] );
		$this->assertSame( 'succeeded', $metadata['pdf_ocr_status'] );
		$this->assertNotNull( $document );
		$this->assertSame( 'ocr', $document->get_metadata()['pdf_text_engine'] );
		$this->assertSame( 'succeeded', $document->get_metadata()['pdf_ocr_status'] );
		$this->assertStringContainsString( 'OCR Title', $document->get_block_markup() );
		$this->assertStringContainsString( 'OCR body https://local.example.test/scan', $document->get_block_markup() );
		$this->assertSame( array( 'source.example.test' ), $document->get_metadata()['absolute_url_domains'] );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertSame( 1, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' ) );
		$this->assertSame( 1, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'post' ) );
		$this->assertContains( 'session.done', $events );
	}

	/**
	 * Image-only PDFs fail with actionable OCR diagnostics when OCR is not configured.
	 *
	 * @return void
	 */
	public function test_runner_fails_pdf_without_extractable_text() {
		$source_file      = $this->temporary_pdf( 'scanned.pdf', 'q 10 0 0 10 0 0 cm /Im1 Do Q', true );
		$previous_command = getenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );
		$session          = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates OCR command configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );

		try {
			( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );
		} finally {
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND', $previous_command );
		}

		$restored = $this->store->find( $session->get_id() );
		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ), 1 );
		$events   = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( ImportSession::STATUS_RUNNING, $restored->get_status() );
		$this->assertCount( 1, $items );
		$this->assertSame( 'pdf', $items[0]->get_metadata()['document_format'] );
		$this->assertSame( 'PDF text extraction produced no importable text. Configure UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND for a text extractor such as pdftotext, or UNIVERSAL_IMPORTER_PDF_OCR_COMMAND for scanned PDFs.', $items[0]->get_metadata()['error'] );
		$this->assertSame( 'native', $items[0]->get_metadata()['pdf_text_engine'] );
		$this->assertSame( 'not_configured', $items[0]->get_metadata()['pdf_external_text_status'] );
		$this->assertStringContainsString( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND', $items[0]->get_metadata()['pdf_external_text_hint'] );
		$this->assertSame( 'not_configured', $items[0]->get_metadata()['pdf_ocr_status'] );
		$this->assertStringContainsString( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND', $items[0]->get_metadata()['pdf_ocr_hint'] );
		$this->assertContains( 'document.failed', $events );
		$this->assertNotContains( 'session.done', $events );
	}

	/**
	 * Obvious glyph-code garbage is rejected instead of being written as page content.
	 *
	 * @return void
	 */
	public function test_runner_rejects_low_quality_pdf_glyph_garbage() {
		$source_file   = $this->temporary_pdf(
			'glyph-garbage.pdf',
			"BT\n/F1 12 Tf\n72 720 Td\n($) Tj\nT*\n(L%%\") Tj\nT*\n(L) Tj\nT*\n(.VnN,) Tj\nT*\n('23J/a^$4&) Tj\nT*\n($) Tj\nT*\n(.) Tj\nT*\n(.) Tj\nT*\n(.) Tj\nT*\n(.) Tj\nT*\n(4) Tj\nT*\n(4) Tj\nT*\n(4) Tj\nT*\n(4) Tj\nET",
			false
		);
		$previous_text = getenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND' );
		$previous_ocr  = getenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );
		$session       = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates PDF fallback command configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test isolates OCR command configuration.
		putenv( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND' );

		try {
			( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );
		} finally {
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND', $previous_text );
			$this->restore_environment_variable( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND', $previous_ocr );
		}

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ), 1 );
		$metadata = $items[0]->get_metadata();

		$this->assertCount( 1, $items );
		$this->assertSame( 0, $this->store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 'rejected', $metadata['pdf_native_text_quality'] );
		$this->assertStringContainsString( 'rejected that output', $metadata['pdf_native_text_warning'] );
		$this->assertStringContainsString( 'PDF text extraction produced no importable text', $metadata['error'] );
	}

	/**
	 * Unsafe zip entry paths are skipped with diagnostics instead of being extracted.
	 *
	 * @return void
	 */
	public function test_runner_skips_unsafe_zip_entries() {
		$archive = $this->temporary_zip(
			'unsafe.zip',
			array(
				'../escape.md' => '# Escape',
				'safe.md'      => '# Safe',
			)
		);
		$session = ImportSession::start_for_source( $archive );

		$this->temporary_paths[] = sys_get_temp_dir() . '/universal-importer-archives/' . $session->get_id()->to_string();
		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60 );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );
		$events = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( 1, $this->store->count_prepared_documents( $session->get_id() ) );
		$this->assertContains( 'archive.entry_skipped_unsafe', $events );
	}

	/**
	 * Markdown files are classified, converted to block markup, and remembered idempotently.
	 *
	 * @return void
	 */
	public function test_runner_prepares_markdown_document_items() {
		$source_file = $this->temporary_file( 'chapter.md', "# Chapter\n\nBody text." );
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$item     = $items[0];
		$meta     = $item->get_metadata();
		$document = $this->store->find_prepared_document( $session->get_id(), $item->get_key() );

		$this->assertSame( 'markdown', $meta['document_format'] );
		$this->assertSame( 'Chapter', $meta['title'] );
		$this->assertArrayNotHasKey( 'block_markup', $meta );
		$this->assertNotNull( $document );
		$this->assertStringContainsString( '<!-- wp:heading {"level":1,"anchor":"chapter"} -->', $document->get_block_markup() );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $document->get_block_markup() );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'document-blocks:' . $item->get_key() ) );
	}

	/**
	 * Leading Markdown front matter supplies title metadata without becoming blocks.
	 *
	 * @return void
	 */
	public function test_runner_uses_markdown_front_matter_title_without_importing_front_matter() {
		$source_file = $this->temporary_file(
			'front-matter.md',
			"\xEF\xBB\xBF---\ntitle: \"Front Matter Title\"\nsource: https://source.example.test/private\n---\n\n# Visible Heading\n\nBody text."
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$item     = $items[0];
		$meta     = $item->get_metadata();
		$document = $this->store->find_prepared_document( $session->get_id(), $item->get_key() );
		$markup   = $document->get_block_markup();

		$this->assertSame( 'Front Matter Title', $document->get_title() );
		$this->assertSame( 'Front Matter Title', $meta['title'] );
		$this->assertTrue( $meta['markdown_front_matter'] );
		$this->assertSame( 'Front Matter Title', $meta['markdown_front_matter_title'] );
		$this->assertStringContainsString( '<h1 id="visible-heading">Visible Heading</h1>', $markup );
		$this->assertStringContainsString( '<p>Body text.</p>', $markup );
		$this->assertStringNotContainsString( 'source.example.test/private', $markup );
		$this->assertStringNotContainsString( '<p>title:', $markup );
		$this->assertSame( array(), $document->get_metadata()['absolute_url_domains'] );
	}

	/**
	 * Docs-flavored Markdown fixtures import without requiring full MDX support.
	 *
	 * @return void
	 */
	public function test_runner_prepares_docs_flavored_markdown_sources() {
		$root = $this->temporary_directory();
		mkdir( $root . '/_posts' );
		mkdir( $root . '/src' );
		mkdir( $root . '/src/content' );
		mkdir( $root . '/src/content/docs' );
		mkdir( $root . '/docs' );
		mkdir( $root . '/docs/blueprints' );
		mkdir( $root . '/docs/blueprints/tutorial' );
		mkdir( $root . '/vault' );
		mkdir( $root . '/vault/Guides' );
		mkdir( $root . '/vault/assets' );

		file_put_contents(
			$root . '/index.md',
			"# Docs Home\n\nRead [Getting Started](./getting-started)."
		);
		file_put_contents(
			$root . '/getting-started.md',
			"# Getting Started\n\nGetting started body."
		);
		file_put_contents(
			$root . '/_posts/2026-06-08-release.md',
			"---\ntitle: Jekyll Release Notes\nlayout: post\npermalink: /release-notes/\n---\n\n# Release Notes\n\nJekyll body."
		);
		file_put_contents(
			$root . '/src/content/docs/overview.mdoc',
			"---\ntitle: Astro Overview\n---\n\n# Astro Overview\n\nAstro docs body."
		);
		file_put_contents(
			$root . '/docs/api.mdx',
			"---\ntitle: Docusaurus API\nsidebar_position: 2\n---\n\nimport {\n  Tabs,\n  TabItem,\n} from '@theme/Tabs';\n\n# Docusaurus API\n\n:::note Stable API\nUse the stable docs path.\n:::\n\n<Tabs>\n</Tabs>\n\nContinue to [Astro](../src/content/docs/overview.mdoc)."
		);
		file_put_contents(
			$root . '/docs/blueprints/tutorial/index.mdx',
			"---\ntitle: Blueprints Tutorial\nslug: /blueprints/tutorial\n---\n\n# Blueprints Tutorial\n\nStart with [your first Blueprint](/blueprints/tutorial/build-your-first-blueprint) and continue at [step two](./build-your-first-blueprint#step-two)."
		);
		file_put_contents(
			$root . '/docs/blueprints/tutorial/build-your-first-blueprint.mdx',
			"---\ntitle: Build your first Blueprint\nslug: /blueprints/tutorial/build-your-first-blueprint\n---\n\n# Build your first Blueprint\n\n## Step Two\n\nBlueprint body."
		);
		file_put_contents(
			$root . '/docs/fenced-sample.mdx',
			"---\ntitle: MDX Fence Fixture\n---\n\n# MDX Fence Fixture\n\n````mdx\n```js\nimport Sample from './sample';\n```\n\nexport controls are configured in Wrangler for the sample.\n[[Guide (v2)|Guide inside fence]]\n````\n\nimport RealComponent from './RealComponent';\nexport const metadata = {\n  title: 'Docs page',\n  sidebar_position: 2,\n};\n\nimport controls are configured by prose.\nexport controls are configured in Wrangler.\n    import indentedCode from './kept';\n\n<Note>Keep this warning.</Note>\n\n<Cards>\n</Cards>"
		);
		file_put_contents(
			$root . '/vault/Concepts.md',
			"# Concepts\n\nSee [[Guides/Setup|Setup guide]], [[Guide (v2)|Guide [v2]]], and [[Guide [v2]|Guide [stable]]].\n\n![[assets/diagram.png]]\n\n> [!NOTE] Field note\n> Works offline."
		);
		file_put_contents(
			$root . '/vault/Guides/Setup.md',
			"# Setup\n\nBack to [[../Concepts|Concepts]]."
		);
		file_put_contents(
			$root . '/vault/Guide (v2).md',
			"# Guide (v2)\n\nBack to [[Concepts]]."
		);
		file_put_contents(
			$root . '/vault/Guide [v2].md',
			"# Guide [v2]\n\nBack to [[Concepts]]."
		);
		file_put_contents( $root . '/vault/assets/diagram.png', 'png-bytes' );

		$session = ImportSession::start_for_source( $root );
		$posts   = new FakePostGateway();
		$media   = new FakeMediaGateway();
		$this->store->save( $session );

		for ( $tick = 0; $tick < 10; ++$tick ) {
			( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media ) )->run( $session->get_id() );

			if ( ImportSession::STATUS_DONE === $this->store->find( $session->get_id() )->get_status() ) {
				break;
			}
		}

		$documents = $this->store->list_prepared_documents( $session->get_id(), 20 );
		$by_title  = array();

		foreach ( $documents as $document ) {
			$by_title[ $document->get_title() ] = $document;
		}

		$this->assertSame( ImportSession::STATUS_DONE, $this->store->find( $session->get_id() )->get_status() );
		$this->assertArrayHasKey( 'Docs Home', $by_title );
		$this->assertArrayHasKey( 'Getting Started', $by_title );
		$this->assertArrayHasKey( 'Jekyll Release Notes', $by_title );
		$this->assertArrayHasKey( 'Astro Overview', $by_title );
		$this->assertArrayHasKey( 'Docusaurus API', $by_title );
		$this->assertArrayHasKey( 'Blueprints Tutorial', $by_title );
		$this->assertArrayHasKey( 'Build your first Blueprint', $by_title );
		$this->assertArrayHasKey( 'MDX Fence Fixture', $by_title );
		$this->assertArrayHasKey( 'Concepts', $by_title );
		$this->assertArrayHasKey( 'Setup', $by_title );
		$this->assertArrayHasKey( 'Guide (v2)', $by_title );
		$this->assertArrayHasKey( 'Guide [v2]', $by_title );

		$home            = $by_title['Docs Home'];
		$getting_started = $by_title['Getting Started'];
		$jekyll          = $by_title['Jekyll Release Notes'];
		$astro           = $by_title['Astro Overview'];
		$docusaurus      = $by_title['Docusaurus API'];
		$blueprints      = $by_title['Blueprints Tutorial'];
		$fenced          = $by_title['MDX Fence Fixture'];
		$obsidian        = $by_title['Concepts'];
		$posts_by_title  = $this->posts_by_title( $posts );

		$this->assertSame( '/', $home->get_metadata()['markdown_route_path'] );
		$this->assertSame( '/getting-started', $getting_started->get_metadata()['markdown_route_path'] );
		$this->assertContains( 'jekyll', $jekyll->get_metadata()['markdown_docs_flavors'] );
		$this->assertSame( '/release-notes', $jekyll->get_metadata()['markdown_route_path'] );
		$this->assertSame( '/release-notes/', $jekyll->get_metadata()['markdown_front_matter_permalink'] );
		$this->assertContains( 'astro', $astro->get_metadata()['markdown_docs_flavors'] );
		$this->assertContains( 'docusaurus', $docusaurus->get_metadata()['markdown_docs_flavors'] );
		$this->assertContains( 'docusaurus', $blueprints->get_metadata()['markdown_docs_flavors'] );
		$this->assertSame( '/blueprints/tutorial', $blueprints->get_metadata()['markdown_front_matter_slug'] );
		$this->assertSame( '/blueprints/tutorial', $blueprints->get_metadata()['markdown_route_path'] );
		$this->assertContains( 'obsidian', $obsidian->get_metadata()['markdown_docs_flavors'] );
		$this->assertSame( 1, $docusaurus->get_metadata()['markdown_docs_admonition_count'] );
		$this->assertSame( 6, $docusaurus->get_metadata()['markdown_mdx_lines_removed'] );
		$this->assertStringContainsString( '<blockquote class="wp-block-quote"><p><strong>Note:</strong> Stable API<br>', $docusaurus->get_block_markup() );
		$this->assertStringNotContainsString( 'Tabs', $docusaurus->get_block_markup() );
		$this->assertStringNotContainsString( '@theme/Tabs', $docusaurus->get_block_markup() );
		$this->assertStringNotContainsString( 'Tabs,', $docusaurus->get_block_markup() );
		$this->assertStringNotContainsString( 'TabItem', $docusaurus->get_block_markup() );
		$this->assertStringNotContainsString( '<Tabs', $docusaurus->get_block_markup() );
		$this->assertSame( 7, $fenced->get_metadata()['markdown_mdx_lines_removed'] );
		$this->assertStringContainsString( 'import Sample from', $fenced->get_block_markup() );
		$this->assertStringContainsString( 'export controls are configured in Wrangler for the sample.', $fenced->get_block_markup() );
		$this->assertStringContainsString( '[[Guide (v2)|Guide inside fence]]', $fenced->get_block_markup() );
		$this->assertStringContainsString( 'import controls are configured by prose.', $fenced->get_block_markup() );
		$this->assertStringContainsString( 'export controls are configured in Wrangler.', $fenced->get_block_markup() );
		$this->assertStringContainsString( 'import indentedCode from', $fenced->get_block_markup() );
		$this->assertStringContainsString( '&lt;Note&gt;Keep this warning.&lt;/Note&gt;', $fenced->get_block_markup() );
		$this->assertStringNotContainsString( 'RealComponent', $fenced->get_block_markup() );
		$this->assertStringNotContainsString( 'metadata', $fenced->get_block_markup() );
		$this->assertStringNotContainsString( 'Docs page', $fenced->get_block_markup() );
		$this->assertStringNotContainsString( 'sidebar_position', $fenced->get_block_markup() );
		$this->assertStringNotContainsString( '&lt;Cards', $fenced->get_block_markup() );
		$this->assertSame( 3, $obsidian->get_metadata()['markdown_obsidian_wikilink_count'] );
		$this->assertSame( 1, $obsidian->get_metadata()['markdown_obsidian_embed_count'] );
		$this->assertSame( 1, $obsidian->get_metadata()['markdown_obsidian_callout_count'] );
		$this->assertStringContainsString( '<figure class="wp-block-image"><img src="https://local.example.test/wp-content/uploads/diagram.png" alt="diagram"/></figure>', $obsidian->get_block_markup() );
		$this->assertStringContainsString( '<strong>Note:</strong> Field note', $obsidian->get_block_markup() );
		$this->assertStringNotContainsString( '[[Guides/Setup', $obsidian->get_block_markup() );
		$this->assertArrayHasKey( 'Concepts', $posts_by_title );
		$this->assertArrayHasKey( 'Setup', $posts_by_title );
		$this->assertArrayHasKey( 'Guide (v2)', $posts_by_title );
		$this->assertArrayHasKey( 'Guide [v2]', $posts_by_title );
		$this->assertArrayHasKey( 'Docs Home', $posts_by_title );
		$this->assertArrayHasKey( 'Getting Started', $posts_by_title );
		$this->assertArrayHasKey( 'Blueprints Tutorial', $posts_by_title );
		$this->assertArrayHasKey( 'Build your first Blueprint', $posts_by_title );
		$this->assertStringContainsString( $posts->get_permalink( $posts_by_title['Getting Started']['ID'] ), $posts_by_title['Docs Home']['post_content'] );
		$this->assertStringNotContainsString( './getting-started', $posts_by_title['Docs Home']['post_content'] );
		$this->assertStringContainsString( $posts->get_permalink( $posts_by_title['Setup']['ID'] ), $posts_by_title['Concepts']['post_content'] );
		$this->assertStringContainsString( $posts->get_permalink( $posts_by_title['Guide (v2)']['ID'] ), $posts_by_title['Concepts']['post_content'] );
		$this->assertStringContainsString( $posts->get_permalink( $posts_by_title['Guide [v2]']['ID'] ), $posts_by_title['Concepts']['post_content'] );
		$this->assertStringContainsString( $posts->get_permalink( $posts_by_title['Build your first Blueprint']['ID'] ), $posts_by_title['Blueprints Tutorial']['post_content'] );
		$this->assertStringContainsString( $posts->get_permalink( $posts_by_title['Build your first Blueprint']['ID'] ) . '#step-two', $posts_by_title['Blueprints Tutorial']['post_content'] );
		$this->assertStringNotContainsString( 'href="/blueprints/tutorial/build-your-first-blueprint"', $posts_by_title['Blueprints Tutorial']['post_content'] );
		$this->assertStringNotContainsString( './build-your-first-blueprint#step-two', $posts_by_title['Blueprints Tutorial']['post_content'] );
		$this->assertStringContainsString( '>Guide [v2]<', $posts_by_title['Concepts']['post_content'] );
		$this->assertStringContainsString( '>Guide [stable]<', $posts_by_title['Concepts']['post_content'] );
		$this->assertSame( 1, $media->count_attachments() );
	}

	/**
	 * Markdown setext headings become native Heading blocks and document titles.
	 *
	 * @return void
	 */
	public function test_runner_infers_markdown_setext_headings() {
		$source_file = $this->temporary_file(
			'setext.md',
			"Setext Title\n===\n\nSetext Subtitle with **strong**\n---\n\n---\n\nBody text."
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$item     = $items[0];
		$document = $this->store->find_prepared_document( $session->get_id(), $item->get_key() );
		$markup   = $document->get_block_markup();

		$this->assertSame( 'Setext Title', $document->get_title() );
		$this->assertSame( 'Setext Title', $item->get_metadata()['title'] );
		$this->assertStringContainsString( '<h1 id="setext-title">Setext Title</h1>', $markup );
		$this->assertStringContainsString( '<h2 id="setext-subtitle-with-strong">Setext Subtitle with <strong>strong</strong></h2>', $markup );
		$this->assertStringContainsString( '<!-- wp:separator -->', $markup );
		$this->assertStringContainsString( '<p>Body text.</p>', $markup );
		$this->assertSame( $document->get_block_count(), $item->get_metadata()['block_count'] );
	}

	/**
	 * Duplicate and punctuation-only Markdown headings receive stable unique anchors.
	 *
	 * @return void
	 */
	public function test_runner_assigns_unique_markdown_heading_anchors() {
		$source_file = $this->temporary_file(
			'anchors.md',
			"# API\n\n## Attributes\n\n## Attributes\n\n### !!!"
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$markup   = $document->get_block_markup();

		$this->assertStringContainsString( '<h1 id="api">API</h1>', $markup );
		$this->assertStringContainsString( '<h2 id="attributes">Attributes</h2>', $markup );
		$this->assertStringContainsString( '<h2 id="attributes-2">Attributes</h2>', $markup );
		$this->assertStringContainsString( '<h3 id="section">!!!</h3>', $markup );
	}

	/**
	 * Ambiguous setext-looking chunks do not over-promote other Markdown shapes.
	 *
	 * @return void
	 */
	public function test_runner_keeps_ambiguous_setext_candidates_as_paragraphs() {
		$source_file = $this->temporary_file(
			'ambiguous-setext.md',
			"Name | Notes\n---\n\n- Alpha\n---"
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$markup   = $document->get_block_markup();

		$this->assertStringNotContainsString( '<h2>Name | Notes</h2>', $markup );
		$this->assertStringNotContainsString( '<h2>- Alpha</h2>', $markup );
		$this->assertStringContainsString( '<p>Name | Notes<br>', $markup );
		$this->assertStringContainsString( '<p>- Alpha<br>', $markup );
	}

	/**
	 * Markdown files infer common safe native block structures.
	 *
	 * @return void
	 */
	public function test_runner_infers_common_markdown_block_structures() {
		$source_file = $this->temporary_file(
			'structured.md',
			"# Structured\n\n- Alpha\n- Beta\n\n1. First\n2. Second\n\n| Name | Notes |\n| --- | --- |\n| Alpha | **Strong** note |\n| Beta | `code` and [link](https://example.test) |\n| Gamma | <tag>unsafe</tag> |\n| Delta | <script>alert(1)</script> |\n\n> Quoted line\n> More quote\n\n```php\nreturn true;\n```\n\n---"
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$markup   = $document->get_block_markup();

		$this->assertStringContainsString( '<!-- wp:list -->', $markup );
		$this->assertStringContainsString( '<ul><li>Alpha</li><li>Beta</li></ul>', $markup );
		$this->assertStringContainsString( '<!-- wp:list {"ordered":true} -->', $markup );
		$this->assertStringContainsString( '<ol><li>First</li><li>Second</li></ol>', $markup );
		$this->assertStringContainsString( '<!-- wp:table -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-table"><table><thead><tr><th>Name</th><th>Notes</th></tr></thead><tbody>', $markup );
		$this->assertStringContainsString( '<tr><td>Alpha</td><td><strong>Strong</strong> note</td></tr>', $markup );
		$this->assertStringContainsString( '<tr><td>Beta</td><td><code>code</code> and <a href="https://example.test">link</a></td></tr>', $markup );
		$this->assertStringContainsString( '<tr><td>Gamma</td><td>&lt;tag&gt;unsafe&lt;/tag&gt;</td></tr>', $markup );
		$this->assertStringContainsString( '<tr><td>Delta</td><td></td></tr>', $markup );
		$this->assertStringNotContainsString( '<script>', $markup );
		$this->assertStringContainsString( '<!-- wp:quote -->', $markup );
		$this->assertStringContainsString( '<blockquote class="wp-block-quote"><p>Quoted line<br>', $markup );
		$this->assertStringContainsString( '<!-- wp:code -->', $markup );
		$this->assertStringContainsString( '<pre class="wp-block-code"><code>return true;</code></pre>', $markup );
		$this->assertStringContainsString( '<!-- wp:separator -->', $markup );
		$this->assertSame( $document->get_block_count(), $items[0]->get_metadata()['block_count'] );
	}

	/**
	 * Standalone Markdown images become native Image blocks with alt/title attributes.
	 *
	 * @return void
	 */
	public function test_runner_infers_markdown_image_blocks() {
		$source_file = $this->temporary_file(
			'image.md',
			"# Image\n\n![Cover alt](images/cover.jpg \"Cover title\")\n\n![Encoded &amp;lt;tag&amp;gt; &amp;amp; entity](images/encoded.jpg)\n\nBody after image."
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$markup   = $document->get_block_markup();

		$this->assertStringContainsString( '<!-- wp:image -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image"><img src="images/cover.jpg" alt="Cover alt" title="Cover title"/></figure>', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image"><img src="images/encoded.jpg" alt="Encoded &amp;lt;tag&amp;gt; &amp;amp; entity"/></figure>', $markup );
		$this->assertStringNotContainsString( '![Cover alt]', $markup );
		$this->assertSame( $document->get_block_count(), $items[0]->get_metadata()['block_count'] );
	}

	/**
	 * Markdown inline links and emphasis become safe HTML in inferred blocks.
	 *
	 * @return void
	 */
	public function test_runner_formats_markdown_inline_links_and_emphasis() {
		$source_file = $this->temporary_file(
			'inline.md',
			"# **Linked** [Heading](https://source.example.test/heading)\n\nParagraph with **strong** and *emphasis* plus `literal **not strong** <tag>` and [Report](files/report.pdf \"Annual report\").\n\n- [List link](/local/path)\n- __Strong item__\n\n> Quote with _emphasis_ and [Unsafe](java&#x73;cript:alert)"
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$markup   = $document->get_block_markup();

		$this->assertStringContainsString( '<h1 id="linked-heading"><strong>Linked</strong> <a href="https://source.example.test/heading">Heading</a></h1>', $markup );
		$this->assertStringContainsString( '<p>Paragraph with <strong>strong</strong> and <em>emphasis</em> plus <code>literal **not strong** &lt;tag&gt;</code> and <a href="files/report.pdf" title="Annual report">Report</a>.</p>', $markup );
		$this->assertStringContainsString( '<li><a href="/local/path">List link</a></li>', $markup );
		$this->assertStringContainsString( '<li><strong>Strong item</strong></li>', $markup );
		$this->assertStringContainsString( '<blockquote class="wp-block-quote"><p>Quote with <em>emphasis</em> and Unsafe</p></blockquote>', $markup );
		$this->assertStringNotContainsString( 'javascript:', $markup );
		$this->assertStringNotContainsString( 'href="java', $markup );
	}

	/**
	 * Ambiguous pipe-delimited Markdown stays as a paragraph, not a table block.
	 *
	 * @return void
	 */
	public function test_runner_keeps_ambiguous_markdown_pipe_text_as_paragraph() {
		$source_file = $this->temporary_file(
			'not-table.md',
			"Name | Notes\nAlpha | Missing delimiter row"
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$markup   = $document->get_block_markup();

		$this->assertStringNotContainsString( '<!-- wp:table -->', $markup );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $markup );
		$this->assertStringContainsString( '<p>Name | Notes<br>', $markup );
	}

	/**
	 * Markdown links to local media files are queued after inline link formatting.
	 *
	 * @return void
	 */
	public function test_runner_queues_local_media_references_from_markdown_links() {
		$root = $this->temporary_directory();
		mkdir( $root . '/files' );
		$this->temporary_paths[] = $root . '/files';
		file_put_contents( $root . '/files/report.pdf', '%PDF-1.4 report bytes %%EOF' );
		$this->temporary_paths[] = $root . '/files/report.pdf';
		file_put_contents( $root . '/chapter.md', "# Chapter\n\n[Download report](files/report.pdf)" );
		$this->temporary_paths[] = $root . '/chapter.md';

		$session = ImportSession::start_for_source( $root . '/chapter.md' );
		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60 );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );

		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED ), 5 );

		$this->assertCount( 1, $references );
		$this->assertSame( 'files/report.pdf', $references[0]->get_original_url() );
		$this->assertSame( realpath( $root . '/files/report.pdf' ), $references[0]->get_resolved_source_uri() );
		$this->assertSame( ImportMediaReference::TYPE_FILE, $references[0]->get_media_type() );
		$this->assertSame( 'local-relative-path', $references[0]->get_metadata()['reference_scope'] );
	}

	/**
	 * Markdown reference-style links and images resolve through safe definitions.
	 *
	 * @return void
	 */
	public function test_runner_formats_markdown_reference_links_and_images() {
		$root = $this->temporary_directory();
		mkdir( $root . '/files' );
		mkdir( $root . '/images' );
		file_put_contents( $root . '/files/report.pdf', '%PDF-1.4 report bytes %%EOF' );
		file_put_contents( $root . '/images/photo.jpg', 'image-bytes' );
		file_put_contents(
			$root . '/chapter.md',
			"# References\n\n[Download report][report] and [Unsafe][unsafe] and [Missing][missing].\n\n![Photo][photo]\n\n[report]: files/report.pdf \"Annual report\"\n[photo]: images/photo.jpg \"Photo title\"\n[unsafe]: java&#x73;cript:alert(1)"
		);

		$session = ImportSession::start_for_source( $root . '/chapter.md' );
		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60 );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );

		$items      = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$document   = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$markup     = $document->get_block_markup();
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED ), 5 );
		$urls       = array_map(
			function ( ImportMediaReference $reference ) {
				return $reference->get_original_url();
			},
			$references
		);

		sort( $urls, SORT_STRING );

		$this->assertStringContainsString( '<a href="files/report.pdf" title="Annual report">Download report</a>', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image"><img src="images/photo.jpg" alt="Photo" title="Photo title"/></figure>', $markup );
		$this->assertStringContainsString( 'Unsafe', $markup );
		$this->assertStringContainsString( '[Missing][missing]', $markup );
		$this->assertStringNotContainsString( '[report]:', $markup );
		$this->assertStringNotContainsString( 'javascript:', $markup );
		$this->assertSame( array( 'files/report.pdf', 'images/photo.jpg' ), $urls );
		$this->assertSame( 3, $document->get_metadata()['markdown_reference_count'] );
	}

	/**
	 * Local Markdown document links are rewritten to imported draft permalinks.
	 *
	 * @return void
	 */
	public function test_runner_resolves_markdown_document_links_to_imported_pages() {
		$root = $this->temporary_directory();
		mkdir( $root . '/reference' );
		file_put_contents(
			$root . '/index.md',
			"# Handbook\n\n## Overview\n\nContinue to "
				. '[Block API](reference/block-api.md#attributes), '
				. '[Guide](guide.markdown), and [Missing](missing.md).'
		);
		file_put_contents(
			$root . '/guide.markdown',
			"# Guide\n\nBack to [Home](./index.md) or jump to "
				. '[Root API](/reference/block-api.md).'
		);
		file_put_contents(
			$root . '/reference/block-api.md',
			"# Block API\n\n## Attributes\n\n## Supports\n\nReturn to "
				. '[Handbook](../index.md#overview).'
		);

		$session = ImportSession::start_for_source( $root );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		for ( $tick = 0; $tick < 8; ++$tick ) {
			( new ImportRunner( $this->store, 'unit-test', 60, null, $posts ) )->run( $session->get_id() );

			if ( ImportSession::STATUS_DONE === $this->store->find( $session->get_id() )->get_status() ) {
				break;
			}
		}

		$posts_by_title = $this->posts_by_title( $posts );
		$events         = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 30 )
		);

		$this->assertSame( ImportSession::STATUS_DONE, $this->store->find( $session->get_id() )->get_status() );
		$this->assertArrayHasKey( 'Handbook', $posts_by_title );
		$this->assertArrayHasKey( 'Block API', $posts_by_title );
		$this->assertArrayHasKey( 'Guide', $posts_by_title );

		$handbook = $posts_by_title['Handbook'];
		$api      = $posts_by_title['Block API'];
		$guide    = $posts_by_title['Guide'];

		$this->assertStringContainsString( $posts->get_permalink( $api['ID'] ) . '#attributes', $handbook['post_content'] );
		$this->assertStringContainsString( $posts->get_permalink( $guide['ID'] ), $handbook['post_content'] );
		$this->assertStringContainsString( $posts->get_permalink( $handbook['ID'] ), $guide['post_content'] );
		$this->assertStringContainsString( $posts->get_permalink( $api['ID'] ), $guide['post_content'] );
		$this->assertStringContainsString( $posts->get_permalink( $handbook['ID'] ) . '#overview', $api['post_content'] );
		$this->assertStringContainsString( 'id="overview"', $handbook['post_content'] );
		$this->assertStringContainsString( 'id="attributes"', $api['post_content'] );
		$this->assertStringContainsString( 'id="supports"', $api['post_content'] );
		$this->assertStringContainsString( 'missing.md', $handbook['post_content'] );
		$this->assertStringNotContainsString( 'reference/block-api.md', $handbook['post_content'] );
		$this->assertStringNotContainsString( 'guide.markdown', $handbook['post_content'] );
		$this->assertStringNotContainsString( '../index.md', $api['post_content'] );
		$this->assertContains( 'markdown.internal_links_resolved', $events );
	}

	/**
	 * Unsafe direct Markdown image URLs are not emitted as Image blocks.
	 *
	 * @return void
	 */
	public function test_runner_rejects_unsafe_markdown_image_urls() {
		$source_file = $this->temporary_file(
			'unsafe-image.md',
			"# Unsafe image\n\n![Unsafe](java&#x73;cript:alert(1))"
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$markup   = $document->get_block_markup();

		$this->assertStringNotContainsString( '<!-- wp:image -->', $markup );
		$this->assertStringNotContainsString( 'javascript:', $markup );
		$this->assertStringContainsString( '![Unsafe](java&amp;#x73;cript:alert(1))', $markup );
	}

	/**
	 * Large Markdown files store a byte cursor and resume on later ticks.
	 *
	 * @return void
	 */
	public function test_runner_streams_large_markdown_document_items() {
		$paragraph   = "Long paragraph content with a [download](files/report.pdf).\n\n";
		$body        = str_repeat( $paragraph, (int) ceil( SourceItemDocumentProcessor::TEXT_CHUNK_BYTES / strlen( $paragraph ) ) + 200 );
		$body       .= "\nFinal resumed Markdown paragraph.";
		$source_file = $this->temporary_file( 'large.md', "# Large\n\n" . $body );
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts ) )->run( $session->get_id() );

		$partial_items = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED ), 1 );
		$this->assertCount( 1, $partial_items );
		$partial_meta = $partial_items[0]->get_metadata();

		$this->assertSame( 'partial', $partial_meta['processor_status'] );
		$this->assertSame( 'markdown', $partial_meta['document_format'] );
		$this->assertGreaterThan( 0, $partial_meta['markdown_next_offset'] );
		$this->assertSame( 1, $this->store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 1, $posts->count_posts() );

		for ( $tick = 0; $tick < 5; ++$tick ) {
			if ( ImportSession::STATUS_DONE === $this->store->find( $session->get_id() )->get_status() ) {
				break;
			}

			( new ImportRunner( $this->store, 'unit-test', 60, null, $posts ) )->run( $session->get_id() );
		}

		$restored      = $this->store->find( $session->get_id() );
		$items         = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$documents     = $this->store->list_prepared_documents_after_source_item_key( $session->get_id(), null, 10 );
		$events        = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 30 )
		);
		$final_meta    = $items[0]->get_metadata();
		$last_document = $documents[ count( $documents ) - 1 ];

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertGreaterThanOrEqual( 2, count( $documents ) );
		$this->assertSame( count( $documents ), $posts->count_posts() );
		$this->assertSame( 'imported', $final_meta['processor_status'] );
		$this->assertSame( count( $documents ), $final_meta['markdown_chunks_prepared'] );
		$this->assertArrayNotHasKey( 'markdown_next_offset', $final_meta );
		$this->assertStringContainsString( ':markdown-chunk:0', $documents[0]->get_source_item_key() );
		$this->assertStringContainsString( ':markdown-chunk:1', $documents[1]->get_source_item_key() );
		$this->assertStringContainsString( '<!-- wp:heading {"level":1,"anchor":"large"} -->', $documents[0]->get_block_markup() );
		$this->assertStringContainsString( '<a href="files/report.pdf">download</a>', $documents[0]->get_block_markup() );
		$this->assertStringContainsString( 'Final resumed Markdown paragraph.', $last_document->get_block_markup() );
		$this->assertContains( 'document.markdown_progress', $events );
		$this->assertContains( 'document.markdown_complete', $events );
		$this->assertSame( 0, $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ) ) );
	}

	/**
	 * Large Markdown front matter is applied only to the first resumed chunk.
	 *
	 * @return void
	 */
	public function test_runner_resumes_large_markdown_with_front_matter_title_on_first_chunk() {
		$paragraph   = "Front matter body paragraph.\n\n";
		$body        = str_repeat( $paragraph, (int) ceil( SourceItemDocumentProcessor::TEXT_CHUNK_BYTES / strlen( $paragraph ) ) + 200 );
		$source_file = $this->temporary_file( 'frontmatter-large.md', "---\ntitle: Front Matter Title\n---\n\n" . $body );
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		for ( $tick = 0; $tick < 5; ++$tick ) {
			( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

			if ( ImportSession::STATUS_DONE === $this->store->find( $session->get_id() )->get_status() ) {
				break;
			}
		}

		$documents = $this->store->list_prepared_documents_after_source_item_key( $session->get_id(), null, 10 );
		$metadata  = $documents[0]->get_metadata();

		$this->assertGreaterThanOrEqual( 2, count( $documents ) );
		$this->assertSame( 'Front Matter Title', $documents[0]->get_title() );
		$this->assertSame( 'Front Matter Title', $metadata['markdown_front_matter_title'] );
		$this->assertTrue( $metadata['markdown_front_matter'] );
		$this->assertStringContainsString( 'part 2', $documents[1]->get_title() );
		$this->assertStringNotContainsString( 'title: Front Matter Title', $documents[0]->get_block_markup() );
		$this->assertStringNotContainsString( '---', $documents[0]->get_block_markup() );
	}

	/**
	 * Large plain-text files store a byte cursor and resume on later ticks.
	 *
	 * @return void
	 */
	public function test_runner_resumes_large_text_document_items_across_ticks() {
		$line        = "Plain text resume line.\n";
		$body        = str_repeat( $line, (int) ceil( SourceItemDocumentProcessor::TEXT_CHUNK_BYTES / strlen( $line ) ) + 200 );
		$body       .= "\nFinal resumed paragraph.";
		$source_file = $this->temporary_file( 'large.txt', $body );
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts ) )->run( $session->get_id() );

		$partial_items = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED ), 1 );
		$this->assertCount( 1, $partial_items );
		$partial_meta = $partial_items[0]->get_metadata();

		$this->assertSame( 'partial', $partial_meta['processor_status'] );
		$this->assertSame( 'text', $partial_meta['document_format'] );
		$this->assertGreaterThan( 0, $partial_meta['text_next_offset'] );
		$this->assertSame( 1, $this->store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 1, $posts->count_posts() );

		for ( $tick = 0; $tick < 5; ++$tick ) {
			if ( ImportSession::STATUS_DONE === $this->store->find( $session->get_id() )->get_status() ) {
				break;
			}

			( new ImportRunner( $this->store, 'unit-test', 60, null, $posts ) )->run( $session->get_id() );
		}

		$restored      = $this->store->find( $session->get_id() );
		$items         = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$documents     = $this->store->list_prepared_documents_after_source_item_key( $session->get_id(), null, 10 );
		$events        = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);
		$final_meta    = $items[0]->get_metadata();
		$last_document = $documents[ count( $documents ) - 1 ];

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertGreaterThanOrEqual( 2, count( $documents ) );
		$this->assertSame( count( $documents ), $posts->count_posts() );
		$this->assertSame( 'imported', $final_meta['processor_status'] );
		$this->assertSame( count( $documents ), $final_meta['text_chunks_prepared'] );
		$this->assertArrayNotHasKey( 'text_next_offset', $final_meta );
		$this->assertStringContainsString( ':text-chunk:0', $documents[0]->get_source_item_key() );
		$this->assertStringContainsString( ':text-chunk:1', $documents[1]->get_source_item_key() );
		$this->assertStringContainsString( 'Plain text resume line.', $documents[0]->get_block_markup() );
		$this->assertStringContainsString( 'Final resumed paragraph.', $last_document->get_block_markup() );
		$this->assertContains( 'document.text_progress', $events );
		$this->assertContains( 'document.text_complete', $events );
	}

	/**
	 * HTML files infer obvious block types and strip script tags.
	 *
	 * @return void
	 */
	public function test_runner_prepares_html_without_scripts() {
		$source_file = $this->temporary_file( 'legacy.html', '<html><head><title>Legacy Title</title></head><body><h1>Legacy</h1><script>alert("x")</script><p>Body</p></body></html>' );
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$metadata = $items[0]->get_metadata();
		$this->assertNotNull( $document );
		$markup = $document->get_block_markup();

		$this->assertSame( 'structured', $metadata['html_block_conversion'] );
		$this->assertSame( 'Legacy', $metadata['title'] );
		$this->assertSame( 2, $metadata['html_inferred_block_count'] );
		$this->assertSame( 0, $metadata['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:heading {"level":1} -->', $markup );
		$this->assertStringContainsString( '<h1>Legacy</h1>', $markup );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $markup );
		$this->assertStringNotContainsString( '<title>', $markup );
		$this->assertStringNotContainsString( '<script', $markup );
	}

	/**
	 * HTML conversion keeps classic fallback blocks when structure cannot be inferred.
	 *
	 * @return void
	 */
	public function test_runner_keeps_classic_fallback_for_uninferred_html_nodes() {
		$source_file = $this->temporary_file( 'mixed.html', '<h2>Known</h2><section><custom-card>Opaque</custom-card></section><ul><li>One</li><li>Two</li></ul>' );
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$metadata = $items[0]->get_metadata();
		$this->assertNotNull( $document );
		$markup = $document->get_block_markup();

		$this->assertSame( 'mixed', $metadata['html_block_conversion'] );
		$this->assertSame( 2, $metadata['html_inferred_block_count'] );
		$this->assertSame( 1, $metadata['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2} -->', $markup );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<section><custom-card>Opaque</custom-card></section>', $markup );
		$this->assertStringContainsString( '<!-- wp:list -->', $markup );
	}

	/**
	 * Large HTML files store a byte cursor and resume on later ticks.
	 *
	 * @return void
	 */
	public function test_runner_resumes_large_html_document_items_across_ticks() {
		$paragraph   = '<p>Large HTML paragraph with <a href="report.html">a link</a>.</p>' . "\n";
		$body        = str_repeat( $paragraph, (int) ceil( SourceItemDocumentProcessor::TEXT_CHUNK_BYTES / strlen( $paragraph ) ) + 200 );
		$body       .= '<script>alert("x")</script><p>Final resumed HTML paragraph.</p>';
		$source_file = $this->temporary_file( 'large.html', '<html><head><title>Large HTML</title></head><body><h1>Large HTML</h1>' . $body . '</body></html>' );
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts ) )->run( $session->get_id() );

		$partial_items = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED ), 1 );
		$this->assertCount( 1, $partial_items );
		$partial_meta = $partial_items[0]->get_metadata();

		$this->assertSame( 'partial', $partial_meta['processor_status'] );
		$this->assertSame( 'html', $partial_meta['document_format'] );
		$this->assertGreaterThan( 0, $partial_meta['html_next_offset'] );
		$this->assertSame( 1, $this->store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 1, $posts->count_posts() );

		for ( $tick = 0; $tick < 5; ++$tick ) {
			if ( ImportSession::STATUS_DONE === $this->store->find( $session->get_id() )->get_status() ) {
				break;
			}

			( new ImportRunner( $this->store, 'unit-test', 60, null, $posts ) )->run( $session->get_id() );
		}

		$restored      = $this->store->find( $session->get_id() );
		$items         = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$documents     = $this->store->list_prepared_documents_after_source_item_key( $session->get_id(), null, 10 );
		$events        = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 30 )
		);
		$final_meta    = $items[0]->get_metadata();
		$last_document = $documents[ count( $documents ) - 1 ];

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertGreaterThanOrEqual( 2, count( $documents ) );
		$this->assertSame( count( $documents ), $posts->count_posts() );
		$this->assertSame( 'imported', $final_meta['processor_status'] );
		$this->assertSame( count( $documents ), $final_meta['html_chunks_prepared'] );
		$this->assertArrayNotHasKey( 'html_next_offset', $final_meta );
		$this->assertStringContainsString( ':html-chunk:0', $documents[0]->get_source_item_key() );
		$this->assertStringContainsString( ':html-chunk:1', $documents[1]->get_source_item_key() );
		$this->assertStringContainsString( '<!-- wp:heading {"level":1} -->', $documents[0]->get_block_markup() );
		$this->assertStringNotContainsString( '<title>', $documents[0]->get_block_markup() );
		$this->assertStringContainsString( 'Final resumed HTML paragraph.', $last_document->get_block_markup() );
		$this->assertContains( 'document.html_progress', $events );
		$this->assertContains( 'document.html_complete', $events );

		foreach ( $documents as $document ) {
			$this->assertStringNotContainsString( '<script', $document->get_block_markup() );
			$this->assertStringNotContainsString( 'alert("x")', $document->get_block_markup() );
		}
	}

	/**
	 * Importable local single-file document sessions are marked done after posts persist.
	 *
	 * @return void
	 */
	public function test_runner_completes_importable_local_single_file_sources() {
		$cases = array(
			'notes.txt'   => 'Plain title paragraph.',
			'notes.text'  => 'Plain text extension paragraph.',
			'notes.mdown' => "# MDown title\n\nMarkdown body.",
			'legacy.html' => '<h1>Legacy</h1><p>Body</p>',
			'legacy.htm'  => '<h1>Legacy HTM</h1><p>Body</p>',
		);

		foreach ( $cases as $basename => $content ) {
			$source_file = $this->temporary_file( $basename, $content );
			$session     = ImportSession::start_for_source( $source_file );
			$posts       = new FakePostGateway();
			$this->store->save( $session );

			( new ImportRunner( $this->store, 'unit-test', 60, null, $posts ) )->run( $session->get_id() );

			$restored  = $this->store->find( $session->get_id() );
			$documents = $this->store->list_prepared_documents( $session->get_id(), 5 );
			$events    = array_map(
				function ( ImportProgressEvent $event ) {
					return $event->get_type();
				},
				$this->store->list_events( $session->get_id(), 10 )
			);

			$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status(), $basename );
			$this->assertCount( 1, $documents, $basename );
			$this->assertSame( 1, $posts->count_posts(), $basename );
			$this->assertSame( 1, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' ), $basename );
			$this->assertSame( 1, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'post' ), $basename );
			$this->assertContains( 'session.done', $events, $basename );
		}
	}

	/**
	 * Non-dry-run directory tree sessions are marked done after all posts persist.
	 *
	 * @return void
	 */
	public function test_runner_completes_importable_local_directory_sources() {
		$root = $this->temporary_directory();
		mkdir( $root . '/chapters' );
		$this->temporary_paths[] = $root . '/chapters';
		file_put_contents( $root . '/chapters/one.md', "# One\n\nBody one." );
		$this->temporary_paths[] = $root . '/chapters/one.md';
		file_put_contents( $root . '/index.md', "# Index\n\nBody index." );
		$this->temporary_paths[] = $root . '/index.md';

		$session = ImportSession::start_for_source( $root );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, $posts );
		for ( $tick = 0; $tick < 5; ++$tick ) {
			$runner->run( $session->get_id() );
		}

		$restored  = $this->store->find( $session->get_id() );
		$documents = $this->store->list_prepared_documents( $session->get_id(), 10 );
		$events    = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertCount( 2, $documents );
		$this->assertSame( 2, $posts->count_posts() );
		$this->assertSame( 2, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'post' ) );
		$this->assertSame( 0, $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_QUEUED, ImportSourceItem::STATUS_PROCESSING, ImportSourceItem::STATUS_DISCOVERED ) ) );
		$this->assertContains( 'session.done', $events );
	}

	/**
	 * Prepared document downstream stages continue beyond the first runner batch.
	 *
	 * @return void
	 */
	public function test_runner_processes_prepared_document_stages_beyond_first_batch() {
		$root       = $this->temporary_directory();
		$root_key   = 'local:' . hash( 'sha256', realpath( $root ) );
		$session    = ImportSession::start_for_source( $root );
		$posts      = new FakePostGateway();
		$media      = new FakeMediaGateway();
		$late_media = 'https://source.example.test/uploads/late.jpg';

		$media->add_remote_media( $late_media, 'late-image-bytes' );
		$this->store->save( $session );
		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				$root_key,
				null,
				$root,
				'',
				ImportSourceItem::TYPE_DIRECTORY,
				array( 'directory_status' => 'complete' )
			)->with_status( ImportSourceItem::STATUS_IMPORTED )
		);

		for ( $index = 1; $index <= 30; ++$index ) {
			$source_item_key = 'bulk:' . str_pad( (string) $index, 3, '0', STR_PAD_LEFT );
			$markup          = '<!-- wp:paragraph -->' . "\n" . '<p>Document ' . $index . '</p>' . "\n" . '<!-- /wp:paragraph -->';
			$metadata        = array(
				'relative_path' => 'doc-' . $index . '.md',
				'source_uri'    => 'https://source.example.test/doc-' . $index,
			);

			if ( 26 === $index ) {
				$markup                           = '<!-- wp:paragraph -->' . "\n"
					. '<p>Late document <a href="https://source.example.test/late-page">Late link</a> <img src="' . $late_media . '" alt="Late image"></p>' . "\n"
					. '<!-- /wp:paragraph -->';
				$metadata['absolute_url_domains'] = array( 'source.example.test' );
			}

			$this->store->save_source_item(
				ImportSourceItem::queued(
					$session->get_id(),
					$source_item_key,
					$root_key,
					$metadata['source_uri'],
					$metadata['relative_path'],
					ImportSourceItem::TYPE_FILE
				)->with_status( ImportSourceItem::STATUS_IMPORTED )
			);
			$this->store->save_prepared_document(
				new ImportPreparedDocument(
					$session->get_id(),
					$source_item_key,
					'markdown',
					'Document ' . $index,
					$markup,
					1,
					'hash-' . $index,
					$metadata
				)
			);
			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord(
					'prepared-document:' . $source_item_key,
					'prepared_document',
					$source_item_key,
					'hash-' . $index
				)
			);
		}

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media );
		$runner->run( $session->get_id() );

		$decision = $this->store->find_decision( $session->get_id(), 'confirm-first-party-domains' );

		$this->assertNotNull( $decision );
		$this->assertSame( array( 'source.example.test' ), $decision->get_options()['domains'] );
		$this->assertSame( 0, $posts->count_posts() );

		$this->store->save_decision(
			$session->get_id(),
			$decision->resolve( array( 'confirmed_domains' => array( 'source.example.test' ) ) )
		);

		for ( $tick = 0; $tick < 8; ++$tick ) {
			$runner->run( $session->get_id() );
		}

		$restored   = $this->store->find( $session->get_id() );
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );
		$late_post  = null;
		$post_count = $posts->count_posts();

		for ( $post_id = 1; $post_id <= $post_count; ++$post_id ) {
			$post = $posts->get_post( $post_id );
			if ( null !== $post && false !== strpos( $post['post_content'], 'Late document' ) ) {
				$late_post = $post;
				break;
			}
		}

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertSame( 30, $post_count );
		$this->assertSame( 30, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'post' ) );
		$this->assertSame( 1, $media->count_attachments() );
		$this->assertCount( 1, $references );
		$this->assertNotNull( $late_post );
		$this->assertStringContainsString( 'https://local.example.test/late-page', $late_post['post_content'] );
		$this->assertStringContainsString( 'https://local.example.test/wp-content/uploads/late.jpg', $late_post['post_content'] );
		$this->assertStringNotContainsString( 'https://source.example.test/uploads/late.jpg', $late_post['post_content'] );
	}

	/**
	 * Absolute URL domains are surfaced for confirmation before posts are written.
	 *
	 * @return void
	 */
	public function test_runner_requires_first_party_domain_confirmation_before_post_persistence() {
		$source_file = $this->temporary_file(
			'linked.html',
			'<h1>Linked</h1><p><a href="https://source.example.test/page">Internal</a> <img src="https://cdn.source.example.test/image.jpg"></p>'
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts ) )->run( $session->get_id() );

		$decision = $this->store->find_decision( $session->get_id(), 'confirm-first-party-domains' );
		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$events   = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertNotNull( $document );
		$this->assertSame(
			array( 'cdn.source.example.test', 'source.example.test' ),
			$document->get_metadata()['absolute_url_domains']
		);
		$this->assertNotNull( $decision );
		$this->assertSame( ImportDecision::STATUS_PENDING, $decision->get_status() );
		$this->assertSame(
			array(
				'domains'  => array( 'cdn.source.example.test', 'source.example.test' ),
				'examples' => array(
					'cdn.source.example.test' => array( 'https://cdn.source.example.test/image.jpg' ),
					'source.example.test'     => array( 'https://source.example.test/page' ),
				),
			),
			$decision->get_options()
		);
		$this->assertContains( 'url.confirmation_required', $events );
		$this->assertSame( 0, $posts->count_posts() );

		$this->store->resolve_decision(
			$session->get_id(),
			'confirm-first-party-domains',
			array( 'confirmed_domains' => array( 'source.example.test' ) )
		);
		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/' ) )->run( $session->get_id() );

		$this->assertSame( 1, $posts->count_posts() );
		$this->assertStringContainsString( 'https://local.example.test/page', $posts->get_post( 1 )['post_content'] );
		$this->assertStringContainsString( 'https://cdn.source.example.test/image.jpg', $posts->get_post( 1 )['post_content'] );
	}

	/**
	 * First-party URL inference scans queued media references beyond the first page.
	 *
	 * @return void
	 */
	public function test_url_inference_scans_late_candidate_first_party_media_references() {
		$session = ImportSession::start_for_source( 'https://source.example.test/' );
		$this->store->save( $session );

		for ( $index = 1; $index <= 30; ++$index ) {
			$is_late_candidate = 30 === $index;
			$domain            = $is_late_candidate ? 'source.example.test' : 'outside.example.test';
			$key               = 'media:' . str_pad( (string) $index, 3, '0', STR_PAD_LEFT );
			$url               = 'https://' . $domain . '/uploads/image-' . $index . '.jpg';
			$metadata          = $is_late_candidate
				? array(
					'reference_scope'     => ImportWxrAttachment::SCOPE_CANDIDATE_FIRST_PARTY,
					'absolute_url_domain' => $domain,
					'attachment_url'      => $url,
				)
				: array( 'reference_scope' => 'local-relative-path' );

			$this->store->save_media_reference(
				ImportMediaReference::queued(
					$session->get_id(),
					$key,
					'wxr:attachment:' . $index,
					$url,
					$url,
					ImportMediaReference::TYPE_IMAGE,
					$metadata
				)
			);
		}

		$result   = ( new ImportUrlInference( $this->store ) )->advance( $session, 25 );
		$decision = $this->store->find_decision( $session->get_id(), 'confirm-first-party-domains' );

		$this->assertTrue( $result['blocked'] );
		$this->assertSame( array( 'source.example.test' ), $result['domains'] );
		$this->assertNotNull( $decision );
		$this->assertSame( array( 'source.example.test' ), $decision->get_options()['domains'] );
	}

	/**
	 * Local media references in prepared Markdown are queued durably and idempotently.
	 *
	 * @return void
	 */
	public function test_runner_queues_local_media_references_from_markdown_documents() {
		$workspace = $this->temporary_directory();
		$root      = $workspace . '/import-root';
		mkdir( $root );
		mkdir( $root . '/images' );
		file_put_contents( $root . '/images/photo.jpg', 'image-bytes' );
		file_put_contents( $workspace . '/outside.jpg', 'outside-image-bytes' );
		file_put_contents(
			$root . '/chapter.md',
			"# Chapter\n\n![Photo](images/photo.jpg)\n\n![Outside](../outside.jpg)\n\n![Absolute outside](" . $workspace . '/outside.jpg)'
		);

		$session = ImportSession::start_for_source( $root . '/chapter.md' );
		$this->store->save( $session );

		$runner = new ImportRunner( $this->store, 'unit-test', 60 );
		$runner->run( $session->get_id() );
		$runner->run( $session->get_id() );

		$references         = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED ), 5 );
		$skipped_references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_SKIPPED ), 5 );
		$events             = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertCount( 1, $references );
		$this->assertSame( 'images/photo.jpg', $references[0]->get_original_url() );
		$this->assertSame( realpath( $root . '/images/photo.jpg' ), $references[0]->get_resolved_source_uri() );
		$this->assertSame( ImportMediaReference::TYPE_IMAGE, $references[0]->get_media_type() );
		$this->assertSame( 'local-relative-path', $references[0]->get_metadata()['reference_scope'] );
		$this->assertCount( 2, $skipped_references );
		$this->assertContains( 'media.reference_queued', $events );
		$this->assertContains( 'media.reference_skipped_outside_source', $events );
	}

	/**
	 * Local media attachments are imported and prepared documents are rewritten before draft posts are persisted.
	 *
	 * @return void
	 */
	public function test_runner_imports_local_media_before_post_persistence() {
		$root = $this->temporary_directory();
		mkdir( $root . '/images' );
		$this->temporary_paths[] = $root . '/images';
		file_put_contents( $root . '/images/photo.jpg', 'image-bytes' );
		$this->temporary_paths[] = $root . '/images/photo.jpg';
		file_put_contents( $root . '/chapter.md', "# Chapter\n\n![Photo](images/photo.jpg \"Photo title\")" );
		$this->temporary_paths[] = $root . '/chapter.md';

		$session = ImportSession::start_for_source( $root . '/chapter.md' );
		$posts   = new FakePostGateway();
		$media   = new FakeMediaGateway();
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media ) )->run( $session->get_id() );

		$restored   = $this->store->find( $session->get_id() );
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );
		$events     = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertCount( 1, $references );
		$this->assertSame( 1, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertStringContainsString( '<!-- wp:image -->', $posts->get_post( 1 )['post_content'] );
		$this->assertStringContainsString( 'https://local.example.test/wp-content/uploads/photo.jpg', $posts->get_post( 1 )['post_content'] );
		$this->assertStringContainsString( 'alt="Photo"', $posts->get_post( 1 )['post_content'] );
		$this->assertStringContainsString( 'title="Photo title"', $posts->get_post( 1 )['post_content'] );
		$this->assertStringNotContainsString( 'images/photo.jpg', $posts->get_post( 1 )['post_content'] );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'attachment:' . $references[0]->get_key() ) );
		$this->assertContains( 'session.done', $events );
	}

	/**
	 * Draft post persistence waits when queued local media cannot be imported in the current runtime.
	 *
	 * @return void
	 */
	public function test_runner_blocks_post_persistence_when_local_media_import_is_unavailable() {
		$root = $this->temporary_directory();
		mkdir( $root . '/images' );
		$this->temporary_paths[] = $root . '/images';
		file_put_contents( $root . '/images/photo.jpg', 'image-bytes' );
		$this->temporary_paths[] = $root . '/images/photo.jpg';
		file_put_contents( $root . '/chapter.md', "# Chapter\n\n![Photo](images/photo.jpg)" );
		$this->temporary_paths[] = $root . '/chapter.md';

		$session = ImportSession::start_for_source( $root . '/chapter.md' );
		$posts   = new FakePostGateway();
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts ) )->run( $session->get_id() );

		$restored   = $this->store->find( $session->get_id() );
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED ), 5 );
		$events     = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( ImportSession::STATUS_RUNNING, $restored->get_status() );
		$this->assertCount( 1, $references );
		$this->assertSame( 0, $posts->count_posts() );
		$this->assertNotContains( 'session.done', $events );
	}

	/**
	 * Confirmed first-party media URLs are queued while outside and lookalike hosts are ignored.
	 *
	 * @return void
	 */
	public function test_runner_queues_only_confirmed_first_party_media_urls() {
		$source_file = $this->temporary_file(
			'media.html',
			'<img src="https://source.example.test/uploads/photo.jpg">'
			. '<img src="https://source.example.test.evil/uploads/phish.jpg">'
			. '<img src="https://outside.example.test/uploads/outside.jpg">'
			. '<img src="javascript:alert(1)">'
			. '<script><img src="https://source.example.test/uploads/scripted.jpg"></script>'
		);
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Confirm first-party domains before URL rewriting.',
				array( 'domains' => array( 'outside.example.test', 'source.example.test' ) )
			)->resolve( array( 'confirmed_domains' => array( 'source.example.test' ) ) )
		);

		( new ImportRunner( $this->store, 'unit-test', 60, null, new FakePostGateway(), 'https://local.example.test/' ) )->run( $session->get_id() );

		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED ), 5 );
		$document   = $this->store->list_prepared_documents( $session->get_id(), 1 )[0];

		$this->assertCount( 1, $references );
		$this->assertSame( 'https://source.example.test/uploads/photo.jpg', $references[0]->get_original_url() );
		$this->assertSame( 'confirmed-first-party-url', $references[0]->get_metadata()['reference_scope'] );
		$this->assertStringContainsString( 'https://local.example.test/uploads/photo.jpg', $document->get_block_markup() );
		$this->assertStringContainsString( 'https://source.example.test.evil/uploads/phish.jpg', $document->get_block_markup() );
		$this->assertStringContainsString( 'https://outside.example.test/uploads/outside.jpg', $document->get_block_markup() );
		$this->assertStringNotContainsString( 'scripted.jpg', $document->get_block_markup() );
	}

	/**
	 * Confirmed first-party media URLs are sideloaded before draft posts are persisted.
	 *
	 * @return void
	 */
	public function test_runner_sideloads_confirmed_first_party_media_before_post_persistence() {
		$source_file = $this->temporary_file( 'media.html', '<img src="https://source.example.test/uploads/photo.jpg">' );
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();

		$media->add_remote_media( 'https://source.example.test/uploads/photo.jpg', 'remote-image-bytes' );
		$this->store->save( $session );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Confirm first-party domains before URL rewriting.',
				array( 'domains' => array( 'source.example.test' ) )
			)->resolve( array( 'confirmed_domains' => array( 'source.example.test' ) ) )
		);

		$runner = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media );
		$runner->run( $session->get_id() );

		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );

		$this->assertCount( 1, $references );
		$this->assertSame( 1, $media->count_attachments() );
		$this->assertSame( 0, $posts->count_posts() );

		$runner->run( $session->get_id() );

		$this->assertSame( 1, $posts->count_posts() );
		$this->assertStringContainsString( 'https://local.example.test/wp-content/uploads/photo.jpg', $posts->get_post( 1 )['post_content'] );
		$this->assertStringNotContainsString( 'https://source.example.test/uploads/photo.jpg', $posts->get_post( 1 )['post_content'] );
	}

	/**
	 * Dry-run sessions stage importer state without mutating WordPress content.
	 *
	 * @return void
	 */
	public function test_runner_dry_run_stages_documents_and_skips_wordpress_content_writes() {
		$source_file = $this->temporary_file( 'dry-run-media.html', '<img src="https://source.example.test/uploads/photo.jpg">' );
		$session     = ImportSession::start_for_source( $source_file, true );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();
		$comments    = new FakeCommentGateway();

		$media->add_remote_media( 'https://source.example.test/uploads/photo.jpg', 'remote-image-bytes' );
		$media->fail_imports_with( 'Dry run attempted media write.' );
		$posts->fail_writes_with( 'Dry run attempted post write.' );
		$posts->fail_postmeta_writes_with( 'Dry run attempted postmeta write.' );
		$comments->fail_writes_with( 'Dry run attempted comment write.' );
		$this->store->save( $session );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Confirm first-party domains before URL rewriting.',
				array( 'domains' => array( 'source.example.test' ) )
			)->resolve( array( 'confirmed_domains' => array( 'source.example.test' ) ) )
		);

		( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, $comments ) )->run( $session->get_id() );

		$restored   = $this->store->find( $session->get_id() );
		$documents  = $this->store->list_prepared_documents( $session->get_id(), 1 );
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED ), 5 );
		$events     = $this->store->list_events( $session->get_id(), 10 );
		$types      = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$events
		);

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertCount( 1, $documents );
		$this->assertStringContainsString( 'https://local.example.test/uploads/photo.jpg', $documents[0]->get_block_markup() );
		$this->assertCount( 1, $references );
		$this->assertSame( 0, $media->count_attachments() );
		$this->assertSame( 0, $posts->count_posts() );
		$this->assertSame( 0, $comments->count_comments() );
		$this->assertSame( 0, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'post' ) );
		$this->assertSame( 0, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'remote-attachment' ) );
		$this->assertContains( 'session.done', $types );
		$this->assertContains( 'session.dry_run_write_skipped', $types );
		$this->assertContains( 'url.rewritten', $types );
		$this->assertNotContains( 'media.attachment_failed', $types );
		$this->assertNotContains( 'post.failed', $types );
		$this->assertNotContains( 'comment.failed', $types );
	}

	/**
	 * Dry-run sessions wait for pending first-party URL decisions before completion.
	 *
	 * @return void
	 */
	public function test_runner_dry_run_waits_for_pending_url_decisions_before_completion() {
		$source_file = $this->temporary_file( 'dry-run-pending-decision.html', '<img src="https://source.example.test/uploads/photo.jpg">' );
		$session     = ImportSession::start_for_source( $source_file, true );

		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60, null, new FakePostGateway(), 'https://local.example.test/', new FakeMediaGateway() ) )->run( $session->get_id() );

		$restored  = $this->store->find( $session->get_id() );
		$decisions = $this->store->list_pending_decisions( $session->get_id() );

		$this->assertSame( ImportSession::STATUS_RUNNING, $restored->get_status() );
		$this->assertCount( 1, $decisions );
		$this->assertSame( 'confirm-first-party-domains', $decisions[0]->get_key() );
	}

	/**
	 * Script tags split across byte-stream chunks are still stripped.
	 *
	 * @return void
	 */
	public function test_runner_strips_html_scripts_split_across_stream_chunks() {
		$prefix      = str_repeat( 'A', 65530 );
		$source_file = $this->temporary_file( 'split-script.html', '<p>' . $prefix . '</p><script>alert("x")</script><p>After</p>' );
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items    = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$document = $this->store->find_prepared_document( $session->get_id(), $items[0]->get_key() );
		$this->assertNotNull( $document );
		$markup = $document->get_block_markup();

		$this->assertStringContainsString( '<p>After</p>', $markup );
		$this->assertStringNotContainsString( '<script', $markup );
		$this->assertStringNotContainsString( 'alert("x")', $markup );
	}

	/**
	 * Unsupported files become skipped items with actionable metadata.
	 *
	 * @return void
	 */
	public function test_runner_skips_unsupported_document_items() {
		$source_file = $this->temporary_file( 'archive.bin', 'binary-ish' );
		$session     = ImportSession::start_for_source( $source_file );
		$this->store->save( $session );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$items = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_SKIPPED ), 1 );
		$meta  = $items[0]->get_metadata();

		$this->assertSame( 'skipped', $meta['processor_status'] );
		$this->assertSame( 'Unsupported file extension for the first document processor pass.', $meta['skip_reason'] );
	}

	/**
	 * Existing locks are reported instead of doing concurrent work.
	 *
	 * @return void
	 */
	public function test_runner_reports_locked_sessions() {
		$session = ImportSession::start_for_source( 'local://book.md' );
		$this->store->save( $session );
		$this->store->acquire_lock( $session->get_id(), 'other-worker', 60 );

		$summary = ( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );
		$events  = $this->store->list_events( $session->get_id(), 1 );

		$this->assertSame( 1, $summary['locked'] );
		$this->assertSame( 'session.locked', $events[0]->get_type() );
		$this->assertSame( ImportSession::STATUS_PENDING, $this->store->find( $session->get_id() )->get_status() );
	}

	/**
	 * Repeated lock collisions do not flood the activity log.
	 *
	 * @return void
	 */
	public function test_runner_deduplicates_consecutive_locked_session_events() {
		$session = ImportSession::start_for_source( 'local://book.md' );
		$this->store->save( $session );
		$this->store->acquire_lock( $session->get_id(), 'other-worker', 60 );

		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );
		( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );

		$event_types = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame(
			array( 'session.locked' ),
			array_values(
				array_filter(
					$event_types,
					function ( $type ) {
						return 'session.locked' === $type;
					}
				)
			)
		);
	}

	/**
	 * Locked runnable sessions request another tick so stale locks are revisited.
	 *
	 * @return void
	 */
	public function test_runner_schedules_follow_up_tick_for_locked_sessions() {
		$session   = ImportSession::start_for_source( 'local://book.md' );
		$scheduled = array();
		$this->store->save( $session );
		$this->store->acquire_lock( $session->get_id(), 'other-worker', 60 );

		$scheduler = function ( ImportSessionId $session_id ) use ( &$scheduled ) {
			$scheduled[] = $session_id->to_string();
		};

		$summary = ( new ImportRunner( $this->store, 'unit-test', 60, null, null, null, null, null, null, null, null, $scheduler ) )->run( $session->get_id() );

		$this->assertSame( 1, $summary['locked'] );
		$this->assertSame( array( $session->get_id()->to_string() ), $scheduled );
		$this->assertSame( ImportSession::STATUS_PENDING, $this->store->find( $session->get_id() )->get_status() );
	}

	/**
	 * Stale locks left behind by interrupted workers can be replaced after expiry.
	 *
	 * @return void
	 */
	public function test_runner_recovers_after_stale_lock_expires() {
		$now   = 1000;
		$wpdb  = new FakeWpdb();
		$store = new WordPressImportSessionStore(
			$wpdb,
			null,
			function () use ( &$now ) {
				return $now;
			}
		);

		$source_file = $this->temporary_file( 'book.md', "# Recovered\n\nBody." );
		$session     = ImportSession::start_for_source( $source_file );
		$store->save( $session );

		$dead_worker_lock = $store->acquire_lock( $session->get_id(), 'dead-worker', 5 );
		$this->assertNotNull( $dead_worker_lock );

		$locked_summary = ( new ImportRunner( $store, 'before-expiry', 5 ) )->run( $session->get_id() );

		$this->assertSame( 1, $locked_summary['locked'] );
		$this->assertSame( ImportSession::STATUS_PENDING, $store->find( $session->get_id() )->get_status() );

		$now += 6;

		$recovered_summary = ( new ImportRunner( $store, 'recovery-worker', 5 ) )->run( $session->get_id() );
		$restored          = $store->find( $session->get_id() );
		$events            = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$store->list_events( $session->get_id(), 5 )
		);

		$this->assertSame( 1, $recovered_summary['processed'] );
		$this->assertSame( 0, $recovered_summary['locked'] );
		$this->assertSame( ImportSession::STATUS_RUNNING, $restored->get_status() );
		$this->assertSame( 1, $store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ) ) );
		$this->assertContains( 'session.started', $events );
		$this->assertContains( 'source.discovery_complete', $events );
		$this->assertFalse( $store->release_lock( $dead_worker_lock ) );
		$this->assertNotNull( $store->acquire_lock( $session->get_id(), 'after-recovery', 5 ) );
	}

	/**
	 * Long ticks renew their lock between bounded phases.
	 *
	 * @return void
	 */
	public function test_runner_renews_lock_during_long_ticks() {
		$now   = 1000;
		$wpdb  = new FakeWpdb();
		$store = new WordPressImportSessionStore(
			$wpdb,
			null,
			function () use ( &$now ) {
				return $now;
			}
		);

		$source_url = 'https://source.example.test/remote-page/';
		$session    = ImportSession::start_for_source( $source_url );
		$posts      = new FakePostGateway();
		$competing  = null;
		$store->save( $session );

		$fetcher = new class( $source_url, $now ) implements ImportRemoteContentFetcherInterface {
			/**
			 * Remote source URL.
			 *
			 * @var string
			 */
			private $source_url;

			/**
			 * Mutable fake timestamp.
			 *
			 * @var int
			 */
			private $now;

			/**
			 * Constructor.
			 *
			 * @param string $source_url Remote source URL.
			 * @param int    $now        Mutable fake timestamp.
			 */
			public function __construct( $source_url, &$now ) {
				$this->source_url = $source_url;
				$this->now        = &$now;
			}

			/**
			 * Fetches fake JSON.
			 *
			 * @param string $url URL.
			 * @return void
			 * @throws \RuntimeException Always, to trigger single-URL fallback.
			 */
			public function fetch_json( $url ) {
				unset( $url );

				throw new \RuntimeException( 'No REST index for long tick renewal test.' );
			}

			/**
			 * Fetches fake HTML and advances the fake clock.
			 *
			 * @param string $url URL.
			 * @return array{body:string,headers:array<string,string>,status_code:int}
			 */
			public function fetch_text( $url ) {
				$this->assert_source_url( $url );
				$this->now += 4;

				return array(
					'body'        => '<html><head><title>Remote Recovery</title></head><body><h1>Remote Recovery</h1><p>Body.</p></body></html>',
					'headers'     => array(),
					'status_code' => 200,
				);
			}

			/**
			 * Asserts that the expected source URL is fetched.
			 *
			 * @param string $url URL.
			 * @return void
			 * @throws \RuntimeException When the wrong URL is fetched.
			 */
			private function assert_source_url( $url ) {
				if ( $this->source_url !== $url ) {
					throw new \RuntimeException( 'Unexpected fake fetch URL.' );
				}
			}
		};

		$posts->before_insert_or_update(
			function () use ( &$now, $store, $session, &$competing ) {
				$now      += 2;
				$competing = $store->acquire_lock( $session->get_id(), 'competing-worker', 5 );
			}
		);

		$summary = ( new ImportRunner( $store, 'renewing-worker', 5, null, $posts, 'https://local.example.test', null, null, $fetcher ) )->run( $session->get_id() );

		$this->assertSame( 1, $summary['processed'] );
		$this->assertNull( $competing );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $store->acquire_lock( $session->get_id(), 'after-renewed-run', 5 ) );
	}

	/**
	 * Terminal stale cron events are ignored with a diagnostic.
	 *
	 * @return void
	 */
	public function test_runner_skips_terminal_sessions() {
		$session = ImportSession::start_for_source( 'local://book.md' )->mark_done();
		$this->store->save( $session );

		$summary = ( new ImportRunner( $this->store, 'unit-test', 60 ) )->run( $session->get_id() );
		$events  = $this->store->list_events( $session->get_id(), 1 );

		$this->assertSame( 1, $summary['skipped'] );
		$this->assertSame( 'session.skipped_terminal', $events[0]->get_type() );
	}

	/**
	 * Batch ticks process queued pending and running sessions.
	 *
	 * @return void
	 */
	public function test_runner_batch_processes_queued_sessions() {
		$pending = ImportSession::start_for_source( 'local://pending.md' );
		$running = ImportSession::start_for_source( 'local://running.md' )->mark_running();
		$paused  = ImportSession::start_for_source( 'local://paused.md' )->mark_paused();

		$this->store->save( $pending );
		$this->store->save( $running );
		$this->store->save( $paused );

		$summary = ( new ImportRunner( $this->store, 'unit-test', 60 ) )->run();

		$this->assertSame( 2, $summary['processed'] );
		$this->assertSame( ImportSession::STATUS_RUNNING, $this->store->find( $pending->get_id() )->get_status() );
		$this->assertSame( ImportSession::STATUS_PAUSED, $this->store->find( $paused->get_id() )->get_status() );
	}

	/**
	 * Max tick controls limit batch processing for interruption tests.
	 *
	 * @return void
	 */
	public function test_runner_respects_max_tick_control() {
		$first  = ImportSession::start_for_source( 'local://first.md' );
		$second = ImportSession::start_for_source( 'local://second.md' );

		$this->store->save( $first );
		$this->store->save( $second );

		$summary = ( new ImportRunner( $this->store, 'unit-test', 60, new ImportRunnerControls( false, false, 0, 1 ) ) )->run();

		$this->assertSame( 1, $summary['processed'] );
		$this->assertSame( ImportSession::STATUS_RUNNING, $this->store->find( $first->get_id() )->get_status() );
		$this->assertSame( ImportSession::STATUS_PENDING, $this->store->find( $second->get_id() )->get_status() );
	}

	/**
	 * Failure controls leave durable diagnostics without losing the session.
	 *
	 * @return void
	 */
	public function test_runner_records_simulated_failure_events() {
		$session = ImportSession::start_for_source( 'local://book.md' );
		$this->store->save( $session );

		$controls = new ImportRunnerControls( true, true, 1024 );
		$summary  = ( new ImportRunner( $this->store, 'unit-test', 60, $controls ) )->run( $session->get_id() );
		$events   = $this->store->list_events( $session->get_id(), 6 );

		$this->assertSame(
			array(
				'processed' => 0,
				'locked'    => 0,
				'skipped'   => 0,
				'errors'    => 1,
			),
			$summary
		);
		$this->assertSame( ImportSession::STATUS_RUNNING, $this->store->find( $session->get_id() )->get_status() );
		$this->assertSame( 'runner.error', $events[0]->get_type() );
		$this->assertSame( 'runner.simulated_crash', $events[1]->get_type() );
		$this->assertSame( 'runner.simulated_timeout', $events[2]->get_type() );
		$this->assertSame( 'runner.simulated_memory_pressure', $events[3]->get_type() );
		$this->assertNotNull( $this->store->acquire_lock( $session->get_id(), 'after-runner', 60 ) );
	}

	/**
	 * Runner-level post write crash simulation leaves a recoverable draft and releases the lock.
	 *
	 * @return void
	 */
	public function test_runner_recovers_from_post_write_idempotency_crash_gap() {
		$source_file = $this->temporary_file( 'chapter.md', "# Chapter\n\nBody text." );
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();

		$this->store->save( $session );

		$summary = ( new ImportRunner(
			$this->store,
			'unit-test',
			60,
			new ImportRunnerControls( false, false, 0, null, true ),
			$posts
		) )->run( $session->get_id() );

		$events = $this->store->list_events( $session->get_id(), 4 );
		$items  = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );

		$this->assertSame( 1, $summary['errors'] );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertCount( 1, $items );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'post:' . $items[0]->get_key() ) );
		$this->assertSame( 'runner.error', $events[0]->get_type() );
		$this->assertSame( 'post.simulated_crash_after_write', $events[1]->get_type() );
		$lock = $this->store->acquire_lock( $session->get_id(), 'after-crash', 60 );
		$this->assertNotNull( $lock );
		$this->store->release_lock( $lock );

		$summary = ( new ImportRunner( $this->store, 'unit-test', 60, null, $posts ) )->run( $session->get_id() );
		$record  = $this->store->find_idempotency_record( $session->get_id(), 'post:' . $items[0]->get_key() );

		$this->assertSame( 1, $summary['processed'] );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $record );
		$this->assertSame( '1', $record->get_resource_id() );
	}

	/**
	 * A real PHP exit after a post write leaves a held lock and recovers without duplicating the draft.
	 *
	 * @return void
	 */
	public function test_runner_recovers_from_real_fatal_exit_after_post_write() {
		$source_file   = $this->temporary_file( 'fatal-post.md', "# Fatal Post\n\nBody text." );
		$db_snapshot   = $this->temporary_file( 'fatal-post-wpdb.snapshot', '' );
		$post_snapshot = $this->temporary_file( 'fatal-post-gateway.snapshot', '' );
		$session       = ImportSession::start_for_source( $source_file );
		$posts         = new FakePostGateway();

		$this->wpdb->persist_to_file( $db_snapshot );
		$posts->persist_to_file( $post_snapshot );
		$this->store->save( $session );

		$result = $this->run_fatal_post_write_child_process( $db_snapshot, $post_snapshot, $session->get_id()->to_string(), 1700000000 );

		$this->assertSame( 119, $result['exit_code'], $result['stdout'] . $result['stderr'] );

		$wpdb_after_exit  = FakeWpdb::from_persisted_file( $db_snapshot );
		$posts_after_exit = FakePostGateway::from_persisted_file( $post_snapshot );
		$locked_store     = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000001;
			}
		);
		$items            = $locked_store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );
		$events           = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$locked_store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( ImportSession::STATUS_RUNNING, $locked_store->find( $session->get_id() )->get_status() );
		$this->assertNull( $locked_store->acquire_lock( $session->get_id(), 'before-post-ttl', 60 ) );
		$this->assertSame( 1, $posts_after_exit->count_posts() );
		$this->assertCount( 1, $items );
		$this->assertNull( $locked_store->find_idempotency_record( $session->get_id(), 'post:' . $items[0]->get_key() ) );
		$this->assertContains( 'post.simulated_fatal_after_write', $events );

		$recovery_store = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000301;
			}
		);
		$summary        = ( new ImportRunner( $recovery_store, 'unit-test', 60, null, $posts_after_exit ) )->run( $session->get_id() );
		$record         = $recovery_store->find_idempotency_record( $session->get_id(), 'post:' . $items[0]->get_key() );

		$this->assertSame( 1, $summary['processed'] );
		$this->assertSame( ImportSession::STATUS_DONE, $recovery_store->find( $session->get_id() )->get_status() );
		$this->assertSame( 1, $posts_after_exit->count_posts() );
		$this->assertNotNull( $record );
		$this->assertSame( '1', $record->get_resource_id() );
	}

	/**
	 * Runner-level media write crash simulation leaves a recoverable attachment and releases the lock.
	 *
	 * @return void
	 */
	public function test_runner_recovers_from_media_write_idempotency_crash_gap() {
		$root = $this->temporary_directory();
		mkdir( $root . '/images' );
		$this->temporary_paths[] = $root . '/images';
		file_put_contents( $root . '/images/photo.jpg', 'image-bytes' );
		$this->temporary_paths[] = $root . '/images/photo.jpg';
		file_put_contents( $root . '/chapter.md', "# Chapter\n\n![Photo](images/photo.jpg)" );
		$this->temporary_paths[] = $root . '/chapter.md';

		$session = ImportSession::start_for_source( $root . '/chapter.md' );
		$posts   = new FakePostGateway();
		$media   = new FakeMediaGateway();

		$this->store->save( $session );

		$summary = ( new ImportRunner(
			$this->store,
			'unit-test',
			60,
			new ImportRunnerControls( false, false, 0, null, false, true ),
			$posts,
			'https://local.example.test/',
			$media
		) )->run( $session->get_id() );

		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED ), 5 );
		$events     = $this->store->list_events( $session->get_id(), 4 );

		$this->assertSame( 1, $summary['errors'] );
		$this->assertSame( 1, $media->count_attachments() );
		$this->assertSame( 0, $posts->count_posts() );
		$this->assertCount( 1, $references );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'attachment:' . $references[0]->get_key() ) );
		$this->assertSame( 'runner.error', $events[0]->get_type() );
		$this->assertSame( 'media.simulated_crash_after_write', $events[1]->get_type() );
		$lock = $this->store->acquire_lock( $session->get_id(), 'after-media-crash', 60 );
		$this->assertNotNull( $lock );
		$this->store->release_lock( $lock );

		$summary = ( new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media ) )->run( $session->get_id() );
		$record  = $this->store->find_idempotency_record( $session->get_id(), 'attachment:' . $references[0]->get_key() );

		$this->assertSame( 1, $summary['processed'] );
		$this->assertSame( 1, $media->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $record );
		$this->assertSame( '100', $record->get_resource_id() );
	}

	/**
	 * A real PHP exit after a media write leaves a held lock and recovers without duplicating the attachment.
	 *
	 * @return void
	 */
	public function test_runner_recovers_from_real_fatal_exit_after_media_write() {
		$root = $this->temporary_directory();
		mkdir( $root . '/images' );
		$this->temporary_paths[] = $root . '/images';
		file_put_contents( $root . '/images/photo.jpg', 'image-bytes' );
		$this->temporary_paths[] = $root . '/images/photo.jpg';
		file_put_contents( $root . '/chapter.md', "# Chapter\n\n![Photo](images/photo.jpg)" );
		$this->temporary_paths[] = $root . '/chapter.md';

		$db_snapshot    = $this->temporary_file( 'fatal-media-wpdb.snapshot', '' );
		$media_snapshot = $this->temporary_file( 'fatal-media-gateway.snapshot', '' );
		$session        = ImportSession::start_for_source( $root . '/chapter.md' );
		$media          = new FakeMediaGateway();

		$this->wpdb->persist_to_file( $db_snapshot );
		$media->persist_to_file( $media_snapshot );
		$this->store->save( $session );

		$result = $this->run_fatal_media_write_child_process( $db_snapshot, $media_snapshot, $session->get_id()->to_string(), 1700000000 );

		$this->assertSame( 120, $result['exit_code'], $result['stdout'] . $result['stderr'] );

		$wpdb_after_exit  = FakeWpdb::from_persisted_file( $db_snapshot );
		$media_after_exit = FakeMediaGateway::from_persisted_file( $media_snapshot );
		$locked_store     = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000001;
			}
		);
		$references       = $locked_store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED ), 1 );
		$events           = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$locked_store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( ImportSession::STATUS_RUNNING, $locked_store->find( $session->get_id() )->get_status() );
		$this->assertNull( $locked_store->acquire_lock( $session->get_id(), 'before-media-ttl', 60 ) );
		$this->assertSame( 1, $media_after_exit->count_attachments() );
		$this->assertCount( 1, $references );
		$this->assertNull( $locked_store->find_idempotency_record( $session->get_id(), 'attachment:' . $references[0]->get_key() ) );
		$this->assertContains( 'media.simulated_fatal_after_write', $events );

		$posts          = new FakePostGateway();
		$recovery_store = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000301;
			}
		);
		$summary        = ( new ImportRunner( $recovery_store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media_after_exit ) )->run( $session->get_id() );
		$record         = $recovery_store->find_idempotency_record( $session->get_id(), 'attachment:' . $references[0]->get_key() );

		$this->assertSame( 1, $summary['processed'] );
		$this->assertSame( ImportSession::STATUS_DONE, $recovery_store->find( $session->get_id() )->get_status() );
		$this->assertSame( 1, $media_after_exit->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $record );
		$this->assertSame( '100', $record->get_resource_id() );
	}

	/**
	 * A real PHP exit after a comment write leaves a held lock and recovers without duplicating the comment.
	 *
	 * @return void
	 */
	public function test_runner_recovers_from_real_fatal_exit_after_comment_write() {
		$source_file      = $this->temporary_file( 'fatal-comment.md', '# Fatal Comment' );
		$source_item_key  = 'local:' . hash( 'sha256', realpath( $source_file ) );
		$content_hash     = 'hash-fatal-comment';
		$db_snapshot      = $this->temporary_file( 'fatal-comment-wpdb.snapshot', '' );
		$comment_snapshot = $this->temporary_file( 'fatal-comment-gateway.snapshot', '' );
		$session          = ImportSession::start_for_source( $source_file );
		$comments         = new FakeCommentGateway();

		$this->wpdb->persist_to_file( $db_snapshot );
		$comments->persist_to_file( $comment_snapshot );
		$this->store->save( $session );
		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				$source_item_key,
				null,
				$source_file,
				basename( $source_file ),
				ImportSourceItem::TYPE_FILE
			)->with_status( ImportSourceItem::STATUS_IMPORTED )
		);

		$document = new ImportPreparedDocument(
			$session->get_id(),
			$source_item_key,
			'markdown',
			'Fatal Comment',
			'<!-- wp:paragraph -->' . "\n" . '<p>Fatal Comment</p>' . "\n" . '<!-- /wp:paragraph -->',
			1,
			$content_hash,
			array(
				'remote_comments'          => array(
					array(
						'remote_comment_id' => 101,
						'remote_parent_id'  => 0,
						'author_name'       => 'Commenter',
						'content'           => 'Recover this comment.',
					),
				),
				'remote_comments_complete' => true,
			)
		);
		$this->store->save_prepared_document( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'prepared-document:' . $source_item_key, 'prepared_document', $source_item_key, $content_hash )
		);
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $source_item_key, 'post', '1', $content_hash )
		);

		$result = $this->run_fatal_comment_write_child_process( $db_snapshot, $comment_snapshot, $session->get_id()->to_string(), 1700000000 );

		$this->assertSame( 121, $result['exit_code'], $result['stdout'] . $result['stderr'] );

		$wpdb_after_exit     = FakeWpdb::from_persisted_file( $db_snapshot );
		$comments_after_exit = FakeCommentGateway::from_persisted_file( $comment_snapshot );
		$locked_store        = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000001;
			}
		);
		$events              = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$locked_store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( ImportSession::STATUS_RUNNING, $locked_store->find( $session->get_id() )->get_status() );
		$this->assertNull( $locked_store->acquire_lock( $session->get_id(), 'before-comment-ttl', 60 ) );
		$this->assertSame( 1, $comments_after_exit->count_comments() );
		$this->assertNull( $locked_store->find_idempotency_record( $session->get_id(), 'comment:' . $source_item_key . ':101' ) );
		$this->assertContains( 'comment.simulated_fatal_after_write', $events );

		$recovery_store = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000301;
			}
		);
		$summary        = ( new ImportRunner( $recovery_store, 'unit-test', 60, null, new FakePostGateway(), null, null, null, null, $comments_after_exit ) )->run( $session->get_id() );
		$record         = $recovery_store->find_idempotency_record( $session->get_id(), 'comment:' . $source_item_key . ':101' );

		$this->assertSame( 1, $summary['processed'] );
		$this->assertSame( ImportSession::STATUS_DONE, $recovery_store->find( $session->get_id() )->get_status() );
		$this->assertSame( 1, $comments_after_exit->count_comments() );
		$this->assertNotNull( $record );
		$this->assertSame( '1000', $record->get_resource_id() );
	}

	/**
	 * Timeout simulation stops before normal source work for recovery tests.
	 *
	 * @return void
	 */
	public function test_runner_timeout_simulation_stops_before_noop_work() {
		$session = ImportSession::start_for_source( 'local://book.md' );
		$this->store->save( $session );

		$controls = new ImportRunnerControls( false, true );
		$summary  = ( new ImportRunner( $this->store, 'unit-test', 60, $controls ) )->run( $session->get_id() );
		$events   = $this->store->list_events( $session->get_id(), 5 );

		$this->assertSame( 1, $summary['skipped'] );
		$this->assertSame( ImportSession::STATUS_RUNNING, $this->store->find( $session->get_id() )->get_status() );
		$this->assertSame( 'runner.simulated_timeout', $events[0]->get_type() );
		$this->assertSame( 'session.started', $events[1]->get_type() );
	}

	/**
	 * Missing explicit scheduled sessions do not fail the whole tick.
	 *
	 * @return void
	 */
	public function test_missing_explicit_session_is_noop() {
		$summary = ( new ImportRunner( $this->store, 'unit-test', 60 ) )->run(
			ImportSessionId::from_string( 'import_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' )
		);

		$this->assertSame(
			array(
				'processed' => 0,
				'locked'    => 0,
				'skipped'   => 0,
				'errors'    => 0,
			),
			$summary
		);
	}

	/**
	 * Runs one runner tick in a child PHP process that exits after a post write.
	 *
	 * @param string $db_snapshot_path   Persisted fake database snapshot path.
	 * @param string $post_snapshot_path Persisted fake post gateway snapshot path.
	 * @param string $session_id         Import session id.
	 * @param int    $now                Child process timestamp.
	 * @return array{exit_code:int,stdout:string,stderr:string}
	 */
	private function run_fatal_post_write_child_process( $db_snapshot_path, $post_snapshot_path, $session_id, $now ) {
		$repository_root = dirname( __DIR__, 3 );
		$root_literal    = "'" . addcslashes( $repository_root, "\\'" ) . "'";
		$script          = $this->temporary_file(
			'fatal-post-write-child.php',
			'<?php
$root = ' . $root_literal . ';
require_once $root . "/vendor/autoload.php";
require_once $root . "/tests/bootstrap.php";

use UniversalImporter\Import\ImportRunner;
use UniversalImporter\Import\ImportRunnerControls;
use UniversalImporter\Import\ImportSessionId;
use UniversalImporter\Import\WordPressImportSessionStore;
use UniversalImporter\Tests\Unit\Import\FakePostGateway;
use UniversalImporter\Tests\Unit\Import\FakeWpdb;

$db_snapshot_path = $argv[1];
$post_snapshot_path = $argv[2];
$session_id = $argv[3];
$now = (int) $argv[4];
$wpdb = FakeWpdb::from_persisted_file( $db_snapshot_path );
$posts = FakePostGateway::from_persisted_file( $post_snapshot_path );
$store = new WordPressImportSessionStore(
	$wpdb,
	null,
	function () use ( $now ) {
		return $now;
	}
);
$controls = new ImportRunnerControls( false, false, 0, null, false, false, false, false, false, true );
( new ImportRunner( $store, "unit-test", 60, $controls, $posts ) )->run( ImportSessionId::from_string( $session_id ) );
exit( 99 );
'
		);
		$command         = implode(
			' ',
			array_map(
				'escapeshellarg',
				array(
					PHP_BINARY,
					$script,
					$db_snapshot_path,
					$post_snapshot_path,
					$session_id,
					(string) $now,
				)
			)
		);
		$pipes           = array();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Unit test proves recovery across a real PHP child process exit.
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$repository_root
		);

		$this->assertIsResource( $process );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array(
			'exit_code' => proc_close( $process ),
			'stdout'    => false === $stdout ? '' : $stdout,
			'stderr'    => false === $stderr ? '' : $stderr,
		);
	}

	/**
	 * Runs one runner tick in a child PHP process that exits after a media write.
	 *
	 * @param string $db_snapshot_path    Persisted fake database snapshot path.
	 * @param string $media_snapshot_path Persisted fake media gateway snapshot path.
	 * @param string $session_id          Import session id.
	 * @param int    $now                 Child process timestamp.
	 * @return array{exit_code:int,stdout:string,stderr:string}
	 */
	private function run_fatal_media_write_child_process( $db_snapshot_path, $media_snapshot_path, $session_id, $now ) {
		$repository_root = dirname( __DIR__, 3 );
		$root_literal    = "'" . addcslashes( $repository_root, "\\'" ) . "'";
		$script          = $this->temporary_file(
			'fatal-media-write-child.php',
			'<?php
$root = ' . $root_literal . ';
require_once $root . "/vendor/autoload.php";
require_once $root . "/tests/bootstrap.php";

use UniversalImporter\Import\ImportRunner;
use UniversalImporter\Import\ImportRunnerControls;
use UniversalImporter\Import\ImportSessionId;
use UniversalImporter\Import\WordPressImportSessionStore;
use UniversalImporter\Tests\Unit\Import\FakeMediaGateway;
use UniversalImporter\Tests\Unit\Import\FakePostGateway;
use UniversalImporter\Tests\Unit\Import\FakeWpdb;

$db_snapshot_path = $argv[1];
$media_snapshot_path = $argv[2];
$session_id = $argv[3];
$now = (int) $argv[4];
$wpdb = FakeWpdb::from_persisted_file( $db_snapshot_path );
$media = FakeMediaGateway::from_persisted_file( $media_snapshot_path );
$store = new WordPressImportSessionStore(
	$wpdb,
	null,
	function () use ( $now ) {
		return $now;
	}
);
$controls = new ImportRunnerControls( false, false, 0, null, false, false, false, false, false, false, true );
( new ImportRunner( $store, "unit-test", 60, $controls, new FakePostGateway(), "https://local.example.test/", $media ) )->run( ImportSessionId::from_string( $session_id ) );
exit( 99 );
'
		);
		$command         = implode(
			' ',
			array_map(
				'escapeshellarg',
				array(
					PHP_BINARY,
					$script,
					$db_snapshot_path,
					$media_snapshot_path,
					$session_id,
					(string) $now,
				)
			)
		);
		$pipes           = array();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Unit test proves recovery across a real PHP child process exit.
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$repository_root
		);

		$this->assertIsResource( $process );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array(
			'exit_code' => proc_close( $process ),
			'stdout'    => false === $stdout ? '' : $stdout,
			'stderr'    => false === $stderr ? '' : $stderr,
		);
	}

	/**
	 * Runs one runner tick in a child PHP process that exits after a comment write.
	 *
	 * @param string $db_snapshot_path      Persisted fake database snapshot path.
	 * @param string $comment_snapshot_path Persisted fake comment gateway snapshot path.
	 * @param string $session_id            Import session id.
	 * @param int    $now                   Child process timestamp.
	 * @return array{exit_code:int,stdout:string,stderr:string}
	 */
	private function run_fatal_comment_write_child_process( $db_snapshot_path, $comment_snapshot_path, $session_id, $now ) {
		$repository_root = dirname( __DIR__, 3 );
		$root_literal    = "'" . addcslashes( $repository_root, "\\'" ) . "'";
		$script          = $this->temporary_file(
			'fatal-comment-write-child.php',
			'<?php
$root = ' . $root_literal . ';
require_once $root . "/vendor/autoload.php";
require_once $root . "/tests/bootstrap.php";

use UniversalImporter\Import\ImportRunner;
use UniversalImporter\Import\ImportRunnerControls;
use UniversalImporter\Import\ImportSessionId;
use UniversalImporter\Import\WordPressImportSessionStore;
use UniversalImporter\Tests\Unit\Import\FakeCommentGateway;
use UniversalImporter\Tests\Unit\Import\FakePostGateway;
use UniversalImporter\Tests\Unit\Import\FakeWpdb;

$db_snapshot_path = $argv[1];
$comment_snapshot_path = $argv[2];
$session_id = $argv[3];
$now = (int) $argv[4];
$wpdb = FakeWpdb::from_persisted_file( $db_snapshot_path );
$comments = FakeCommentGateway::from_persisted_file( $comment_snapshot_path );
$store = new WordPressImportSessionStore(
	$wpdb,
	null,
	function () use ( $now ) {
		return $now;
	}
);
$controls = new ImportRunnerControls( false, false, 0, null, false, false, false, false, false, false, false, true );
( new ImportRunner( $store, "unit-test", 60, $controls, new FakePostGateway(), null, null, null, null, $comments ) )->run( ImportSessionId::from_string( $session_id ) );
exit( 99 );
'
		);
		$command         = implode(
			' ',
			array_map(
				'escapeshellarg',
				array(
					PHP_BINARY,
					$script,
					$db_snapshot_path,
					$comment_snapshot_path,
					$session_id,
					(string) $now,
				)
			)
		);
		$pipes           = array();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Unit test proves recovery across a real PHP child process exit.
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$repository_root
		);

		$this->assertIsResource( $process );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array(
			'exit_code' => proc_close( $process ),
			'stdout'    => false === $stdout ? '' : $stdout,
			'stderr'    => false === $stderr ? '' : $stderr,
		);
	}

	/**
	 * Indexes fake posts by title.
	 *
	 * @param FakePostGateway $posts Fake post gateway.
	 * @return array<string,array<string,mixed>>
	 */
	private function posts_by_title( FakePostGateway $posts ) {
		$posts_by_title = array();
		$post_count     = $posts->count_posts();

		for ( $post_id = 1; $post_id <= $post_count; ++$post_id ) {
			$post = $posts->get_post( $post_id );

			if ( null !== $post ) {
				$posts_by_title[ $post['post_title'] ] = $post;
			}
		}

		return $posts_by_title;
	}

	/**
	 * Creates a temporary file fixture.
	 *
	 * @param string $basename Fixture basename.
	 * @param string $content  Fixture content.
	 * @return string
	 */
	private function temporary_file( $basename, $content = 'fixture' ) {
		$directory = $this->temporary_directory();
		$path      = $directory . '/' . $basename;

		file_put_contents( $path, $content );
		$this->temporary_paths[] = $path;

		return $path;
	}

	/**
	 * Creates a temporary zip archive fixture.
	 *
	 * @param string               $basename Archive basename.
	 * @param array<string,string> $entries  Entry names and contents.
	 * @return string
	 */
	private function temporary_zip( $basename, array $entries ) {
		$directory = $this->temporary_directory();
		$path      = $directory . '/' . $basename;
		$zip       = new ZipArchive();

		$this->assertTrue( true === $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );

		foreach ( $entries as $entry_name => $content ) {
			$this->assertTrue( $zip->addFromString( $entry_name, $content ) );
		}

		$this->assertTrue( $zip->close() );
		$this->temporary_paths[] = $path;

		return $path;
	}

	/**
	 * Seeds an imported EPUB source item with prepared spine documents and idempotency records.
	 *
	 * @param ImportSession $session               Session.
	 * @param int           $document_count        Number of prepared documents.
	 * @param int|null      $unresolved_link_index Optional 1-based document index with an unresolved link.
	 * @return void
	 */
	private function seed_imported_epub_prepared_documents( ImportSession $session, $document_count, $unresolved_link_index = null ) {
		$source_file = realpath( $session->get_source() );
		$source_file = false === $source_file ? $session->get_source() : $source_file;
		$source_key  = 'local:' . hash( 'sha256', $source_file );

		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				$source_key,
				null,
				$source_file,
				'',
				ImportSourceItem::TYPE_FILE,
				array(
					'basename'        => basename( $source_file ),
					'extension'       => 'epub',
					'document_format' => 'epub',
				)
			)->with_status( ImportSourceItem::STATUS_IMPORTED )
		);

		for ( $index = 1; $index <= $document_count; ++$index ) {
			$spine_index   = $index - 1;
			$document_key  = $source_key . ':epub-spine:' . str_pad( (string) $spine_index, 4, '0', STR_PAD_LEFT );
			$block_markup  = '<!-- wp:paragraph --><p>Chapter ' . $index . '</p><!-- /wp:paragraph -->';
			$content_hash  = hash( 'sha256', $document_key . "\n" . $block_markup );
			$document_meta = array(
				'epub_entry'                 => 'OEBPS/chapter-' . $index . '.xhtml',
				'epub_spine_index'           => $spine_index,
				'epub_internal_links_status' => 'resolved',
				'epub_internal_links'        => array(),
			);

			if ( $index === $unresolved_link_index ) {
				$document_meta['epub_internal_links_status'] = 'partial';
				$document_meta['epub_internal_links']        = array(
					array(
						'original_href'           => 'chapter-1.xhtml',
						'epub_target_entry'       => 'OEBPS/chapter-1.xhtml',
						'epub_target_spine_index' => 0,
						'target_fragment'         => '',
						'rewritten_href'          => '#universal-importer-epub-chapter-1',
					),
				);
			}

			$this->store->save_prepared_document(
				new ImportPreparedDocument(
					$session->get_id(),
					$document_key,
					'epub',
					'Chapter ' . $index,
					$block_markup,
					1,
					$content_hash,
					$document_meta
				)
			);
			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord(
					'document-blocks:' . $document_key,
					'prepared_document',
					$document_key,
					$content_hash
				)
			);
			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord(
					'post:' . $document_key,
					'post',
					(string) $index,
					$content_hash
				)
			);
		}
	}

	/**
	 * Creates a minimal EPUB archive fixture.
	 *
	 * @param string                                                            $basename Archive basename.
	 * @param string                                                            $title    EPUB title.
	 * @param array<string,array{href:string,title:string,content:string}>      $chapters   Chapter fixtures.
	 * @param array<string,array{href:string,media_type:string,content:string}> $assets     Embedded asset fixtures.
	 * @param array<string,string>                                              $navigation Optional nav/ncx XML fixtures.
	 * @return string
	 */
	private function temporary_epub( $basename, $title, array $chapters, array $assets = array(), array $navigation = array() ) {
		$entries  = array(
			'mimetype'               => 'application/epub+zip',
			'META-INF/container.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
				. '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'
				. '<rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles>'
				. '</container>',
		);
		$manifest = '';
		$spine    = '';

		foreach ( $chapters as $id => $chapter ) {
			$manifest .= '<item id="' . htmlspecialchars( $id, ENT_XML1, 'UTF-8' ) . '" href="' . htmlspecialchars( $chapter['href'], ENT_XML1, 'UTF-8' ) . '" media-type="application/xhtml+xml"/>';
			$spine    .= '<itemref idref="' . htmlspecialchars( $id, ENT_XML1, 'UTF-8' ) . '"/>';

			$entries[ 'OEBPS/' . $chapter['href'] ] = '<?xml version="1.0" encoding="UTF-8"?>'
				. '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>'
				. htmlspecialchars( $chapter['title'], ENT_XML1, 'UTF-8' )
				. '</title></head><body>' . $chapter['content'] . '</body></html>';
		}

		foreach ( $assets as $id => $asset ) {
			$manifest .= '<item id="' . htmlspecialchars( $id, ENT_XML1, 'UTF-8' ) . '" href="' . htmlspecialchars( $asset['href'], ENT_XML1, 'UTF-8' ) . '" media-type="' . htmlspecialchars( $asset['media_type'], ENT_XML1, 'UTF-8' ) . '"/>';

			$entries[ 'OEBPS/' . $asset['href'] ] = $asset['content'];
		}

		if ( isset( $navigation['nav'] ) ) {
			$manifest                  .= '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>';
			$entries['OEBPS/nav.xhtml'] = $navigation['nav'];
		}

		if ( isset( $navigation['ncx'] ) ) {
			$manifest                .= '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>';
			$entries['OEBPS/toc.ncx'] = $navigation['ncx'];
		}

		$spine_attr = isset( $navigation['ncx'] ) ? ' toc="ncx"' : '';

		$entries['OEBPS/content.opf'] = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<package version="3.0" xmlns="http://www.idpf.org/2007/opf" unique-identifier="book-id">'
			. '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>' . htmlspecialchars( $title, ENT_XML1, 'UTF-8' ) . '</dc:title></metadata>'
			. '<manifest>' . $manifest . '</manifest><spine' . $spine_attr . '>' . $spine . '</spine></package>';

		return $this->temporary_zip( $basename, $entries );
	}

	/**
	 * Finds a prepared document by EPUB entry path.
	 *
	 * @param array<int,ImportPreparedDocument> $documents Prepared documents.
	 * @param string                            $entry     EPUB entry path.
	 * @return ImportPreparedDocument|null
	 */
	private function find_prepared_document_by_epub_entry( array $documents, $entry ) {
		foreach ( $documents as $document ) {
			$metadata = $document->get_metadata();

			if ( isset( $metadata['epub_entry'] ) && $entry === $metadata['epub_entry'] ) {
				return $document;
			}
		}

		return null;
	}

	/**
	 * Returns recorded EPUB spine cursor updates for a source item.
	 *
	 * @param ImportSourceItem $item Source item.
	 * @return array<int,int>
	 */
	private function epub_cursor_updates_for_item( ImportSourceItem $item ) {
		$tables  = WordPressImportSessionSchema::get_table_names_for_prefix( $this->wpdb->prefix );
		$updates = array();

		foreach ( $this->wpdb->get_update_calls( $tables['source_items'] ) as $call ) {
			if ( ! isset( $call['where']['item_key'] ) || $item->get_key() !== $call['where']['item_key'] ) {
				continue;
			}

			if ( empty( $call['data']['metadata_json'] ) ) {
				continue;
			}

			$metadata = json_decode( (string) $call['data']['metadata_json'], true );
			if ( is_array( $metadata ) && isset( $metadata['epub_spine_index'] ) ) {
				$updates[] = (int) $metadata['epub_spine_index'];
			}
		}

		return $updates;
	}

	/**
	 * Runs a PDF import until the source item reaches the expected processing phase.
	 *
	 * @param ImportRunner    $runner       Runner under test.
	 * @param ImportSessionId $session_id   Session id.
	 * @param string          $phase        Expected PDF processing phase.
	 * @param int             $max_attempts Maximum runner ticks.
	 * @return array<string,mixed>
	 */
	private function run_pdf_until_processing_phase( ImportRunner $runner, ImportSessionId $session_id, $phase, $max_attempts ) {
		$metadata = array();

		for ( $attempt = 0; $attempt < $max_attempts; ++$attempt ) {
			$runner->run( $session_id );
			$items = $this->store->list_source_items_by_statuses( $session_id, array( ImportSourceItem::STATUS_DISCOVERED ), 1 );

			if ( empty( $items ) ) {
				continue;
			}

			$metadata = $items[0]->get_metadata();
			if ( isset( $metadata['pdf_processing_phase'] ) && $phase === $metadata['pdf_processing_phase'] ) {
				return $metadata;
			}
		}

		$this->fail( 'PDF source item did not reach processing phase ' . $phase . '. Last metadata: ' . wp_json_encode( $metadata ) );
	}

	/**
	 * Creates a minimal PDF fixture with one content stream.
	 *
	 * @param string $basename       Fixture basename.
	 * @param string $content_stream PDF content stream body.
	 * @param bool   $compressed     Whether to Flate-compress the stream.
	 * @return string
	 */
	private function temporary_pdf( $basename, $content_stream, $compressed ) {
		$stream = $compressed ? gzcompress( $content_stream ) : $content_stream;
		$filter = $compressed ? ' /Filter /FlateDecode' : '';
		$pdf    = "%PDF-1.4\n"
			. "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R /Contents 4 0 R >> endobj\n"
			. '4 0 obj << /Length ' . strlen( $stream ) . $filter . " >>\nstream\n"
			. $stream
			. "\nendstream\nendobj\n%%EOF\n";

		return $this->temporary_file( $basename, $pdf );
	}

	/**
	 * Creates a minimal PDF fixture with many native text streams.
	 *
	 * @param string $basename Fixture basename.
	 * @param int    $count    Number of text streams.
	 * @return string
	 */
	private function temporary_pdf_with_text_streams( $basename, $count ) {
		$count        = max( 1, (int) $count );
		$objects      = array(
			"1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
			"2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n",
		);
		$content_refs = array();

		for ( $index = 0; $index < $count; ++$index ) {
			$object_id      = 4 + $index;
			$content_refs[] = $object_id . ' 0 R';
			$stream         = "BT\n/F1 12 Tf\n72 720 Td\n(# PDF Native Cursor " . ( $index + 1 ) . "\\n\\nChunk body " . ( $index + 1 ) . ".) Tj\nET\n";
			$objects[]      = $object_id . ' 0 obj << /Length ' . strlen( $stream ) . " >>\nstream\n"
				. $stream
				. "\nendstream\nendobj\n";
		}

		array_splice(
			$objects,
			2,
			0,
			array(
				'3 0 obj << /Type /Page /Parent 2 0 R /Contents [' . implode( ' ', $content_refs ) . "] >> endobj\n",
			)
		);

		return $this->temporary_file( $basename, "%PDF-1.4\n" . implode( '', $objects ) . "%%EOF\n" );
	}

	/**
	 * Creates a PDF fixture with many native text streams plus one compressed object stream.
	 *
	 * @param string $basename Fixture basename.
	 * @param int    $count    Number of text streams.
	 * @return string
	 */
	private function temporary_pdf_with_text_streams_and_object_stream( $basename, $count ) {
		$count         = max( 1, (int) $count );
		$objects       = array(
			"1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
			"2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n",
		);
		$content_refs  = array();
		$object_stream = gzcompress( '99 0 << /Type /Example /Name /NestedObject >>' );

		for ( $index = 0; $index < $count; ++$index ) {
			$object_id      = 4 + $index;
			$content_refs[] = $object_id . ' 0 R';
			$stream         = "BT\n/F1 12 Tf\n72 720 Td\n(# PDF Native Cursor With Object Stream " . ( $index + 1 ) . "\\n\\nChunk body " . ( $index + 1 ) . ".) Tj\nET\n";
			$objects[]      = $object_id . ' 0 obj << /Length ' . strlen( $stream ) . " >>\nstream\n"
				. $stream
				. "\nendstream\nendobj\n";
		}

		$object_stream_id = 4 + $count;
		$objects[]        = $object_stream_id . ' 0 obj << /Type /ObjStm /N 1 /First 5 /Filter /FlateDecode /Length ' . strlen( $object_stream ) . " >>\nstream\n"
			. $object_stream
			. "\nendstream\nendobj\n";

		array_splice(
			$objects,
			2,
			0,
			array(
				'3 0 obj << /Type /Page /Parent 2 0 R /Contents [' . implode( ' ', $content_refs ) . "] >> endobj\n",
			)
		);

		return $this->temporary_file( $basename, "%PDF-1.5\n" . implode( '', $objects ) . "%%EOF\n" );
	}

	/**
	 * Creates a minimal PDF fixture with one ordinary text stream and one compressed object stream.
	 *
	 * @param string $basename       Fixture basename.
	 * @param string $content_stream PDF content stream body.
	 * @return string
	 */
	private function temporary_pdf_with_object_stream( $basename, $content_stream ) {
		$object_stream = gzcompress( '6 0 << /Type /Example /Name /NestedObject >>' );
		$pdf           = "%PDF-1.5\n"
			. "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R /Contents 4 0 R >> endobj\n"
			. '4 0 obj << /Length ' . strlen( $content_stream ) . " >>\nstream\n"
			. $content_stream
			. "\nendstream\nendobj\n"
			. '5 0 obj << /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode /Length ' . strlen( $object_stream ) . " >>\nstream\n"
			. $object_stream
			. "\nendstream\nendobj\n%%EOF\n";

		return $this->temporary_file( $basename, $pdf );
	}

	/**
	 * Creates a minimal PDF fixture with an invalid FlateDecode stream.
	 *
	 * @param string $basename Fixture basename.
	 * @param string $stream   Invalid compressed stream bytes.
	 * @return string
	 */
	private function temporary_corrupt_flate_pdf( $basename, $stream ) {
		$pdf = "%PDF-1.4\n"
			. "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R /Contents 4 0 R >> endobj\n"
			. '4 0 obj << /Filter /FlateDecode /Length ' . strlen( $stream ) . " >>\nstream\n"
			. $stream
			. "\nendstream\nendobj\n%%EOF\n";

		return $this->temporary_file( $basename, $pdf );
	}

	/**
	 * Creates a minimal PDF fixture with one text stream and one JPEG image XObject.
	 *
	 * @param string $basename       Fixture basename.
	 * @param string $content_stream PDF content stream body.
	 * @return string
	 */
	private function temporary_pdf_with_jpeg_image( $basename, $content_stream ) {
		$image = base64_decode( $this->tiny_jpeg_base64(), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Unit test embeds a tiny binary JPEG fixture in a generated PDF.
		$this->assertIsString( $image );

		return $this->temporary_pdf_with_embedded_image( $basename, $content_stream, 'DCTDecode', $image );
	}

	/**
	 * Creates a minimal PDF fixture with many JPEG image XObjects.
	 *
	 * @param string $basename Fixture basename.
	 * @param int    $count    Number of embedded JPEG image streams.
	 * @return string
	 */
	private function temporary_pdf_with_jpeg_images( $basename, $count ) {
		$image = base64_decode( $this->tiny_jpeg_base64(), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Unit test embeds a tiny binary JPEG fixture in a generated PDF.
		$this->assertIsString( $image );

		$count       = max( 1, (int) $count );
		$xobjects    = array();
		$draws       = array();
		$image_objs  = '';
		$next_object = 5;

		for ( $index = 1; $index <= $count; ++$index ) {
			$name        = 'Im' . $index;
			$object      = $next_object++;
			$xobjects[]  = '/' . $name . ' ' . $object . ' 0 R';
			$draws[]     = 'q 1 0 0 1 0 0 cm /' . $name . ' Do Q';
			$image_objs .= $object . ' 0 obj << /Type /XObject /Subtype /Image /Width 64 /Height 64 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $image ) . " >>\nstream\n"
				. $image
				. "\nendstream\nendobj\n";
		}

		$content_stream = "BT\n/F1 12 Tf\n72 720 Td\n(# PDF With Many Images\\n\\nBody before images.) Tj\nET\n" . implode( "\n", $draws );
		$pdf            = "%PDF-1.4\n"
			. "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
			. '3 0 obj << /Type /Page /Parent 2 0 R /Resources << /XObject << ' . implode( ' ', $xobjects ) . " >> >> /Contents 4 0 R >> endobj\n"
			. '4 0 obj << /Length ' . strlen( $content_stream ) . " >>\nstream\n"
			. $content_stream
			. "\nendstream\nendobj\n"
			. $image_objs
			. "%%EOF\n";

		return $this->temporary_file( $basename, $pdf );
	}

	/**
	 * Creates a minimal PDF fixture with one text stream and one image XObject.
	 *
	 * @param string $basename       Fixture basename.
	 * @param string $content_stream PDF content stream body.
	 * @param string $filter         PDF image stream filter.
	 * @param string $image          Image stream bytes.
	 * @return string
	 */
	private function temporary_pdf_with_embedded_image( $basename, $content_stream, $filter, $image ) {
		return $this->temporary_pdf_with_embedded_image_filter_expression(
			$basename,
			$content_stream,
			'/' . $filter,
			$image
		);
	}

	/**
	 * Creates a minimal PDF fixture with one text stream and one image XObject filter expression.
	 *
	 * @param string $basename          Fixture basename.
	 * @param string $content_stream    PDF content stream body.
	 * @param string $filter_expression Raw PDF filter expression.
	 * @param string $image             Image stream bytes.
	 * @return string
	 */
	private function temporary_pdf_with_embedded_image_filter_expression( $basename, $content_stream, $filter_expression, $image ) {
		return $this->temporary_pdf_with_embedded_image_dictionary(
			$basename,
			$content_stream,
			'/Width 64 /Height 64 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter ' . $filter_expression,
			$image,
			''
		);
	}

	/**
	 * Creates a minimal PDF fixture with one text stream and one custom image XObject dictionary.
	 *
	 * @param string $basename         Fixture basename.
	 * @param string $content_stream   PDF content stream body.
	 * @param string $image_dictionary Raw PDF image dictionary entries.
	 * @param string $image            Image stream bytes.
	 * @param string $extra_objects    Extra PDF objects appended after the image object.
	 * @return string
	 */
	private function temporary_pdf_with_embedded_image_dictionary( $basename, $content_stream, $image_dictionary, $image, $extra_objects ) {
		$pdf = "%PDF-1.4\n"
			. "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R /Resources << /XObject << /Im1 5 0 R >> >> /Contents 4 0 R >> endobj\n"
			. '4 0 obj << /Length ' . strlen( $content_stream ) . " >>\nstream\n"
			. $content_stream
			. "\nendstream\nendobj\n"
			. '5 0 obj << /Type /XObject /Subtype /Image ' . $image_dictionary . ' /Length ' . strlen( $image ) . " >>\nstream\n"
			. $image
			. "\nendstream\nendobj\n"
			. $extra_objects
			. "%%EOF\n";

		return $this->temporary_file( $basename, $pdf );
	}

	/**
	 * Creates a large PDF fixture with a compressed font program that looks like page text.
	 *
	 * @param string $basename Fixture basename.
	 * @return string
	 */
	private function temporary_pdf_with_font_program_stream( $basename ) {
		$content      = "BT\n/F1 12 Tf\n72 720 Td\n(# Readable PDF\\n\\nActual page body.) Tj\nET\n";
		$font_program = gzcompress( "BT\n/F1 12 Tf\n72 720 Td\n(THIS SHOULD NOT BECOME TEXT) Tj\nET\n" );
		$pdf          = "%PDF-1.4\n"
			. "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >> endobj\n"
			. '4 0 obj << /Length ' . strlen( $content ) . " >>\nstream\n"
			. $content
			. "\nendstream\nendobj\n"
			. "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica /FontDescriptor 6 0 R >> endobj\n"
			. "6 0 obj << /Type /FontDescriptor /FontName /Helvetica /FontFile 7 0 R >> endobj\n"
			. '7 0 obj << /Length ' . strlen( $font_program ) . ' /Length1 1024 /Filter /FlateDecode' . " >>\nstream\n"
			. $font_program
			. "\nendstream\nendobj\n"
			. str_repeat( '0', SourceItemDocumentProcessor::PDF_FILE_LIMIT )
			. "\n%%EOF\n";

		return $this->temporary_file( $basename, $pdf );
	}

	/**
	 * Creates a large PDF fixture whose early content needs a late ToUnicode map.
	 *
	 * @param string $basename Fixture basename.
	 * @return string
	 */
	private function temporary_large_pdf_with_to_unicode_text( $basename ) {
		$cmap           = "/CIDInit /ProcSet findresource begin\n"
			. "12 dict begin\n"
			. "begincmap\n"
			. "1 beginbfchar\n"
			. "<0003> <0020>\n"
			. "endbfchar\n"
			. "8 beginbfchar\n"
			. "<003C> <0059>\n"
			. "<0052> <006F>\n"
			. "<0058> <0075>\n"
			. "<0045> <0062>\n"
			. "<004C> <0069>\n"
			. "<004F> <006C>\n"
			. "<0047> <0064>\n"
			. "<0020> <0020>\n"
			. "endbfchar\n"
			. "endcmap\n"
			. "CMapName currentdict /CMap defineresource pop\n"
			. "end end\n";
		$content        = "BT\n/F6 12 Tf\n72 720 Td\n(<RX\\003EXLOG) Tj\nET\n";
		$alternate_cmap = "/CIDInit /ProcSet findresource begin\n"
			. "12 dict begin\n"
			. "begincmap\n"
			. "1 begincodespacerange\n"
			. "<0000> <FFFF>\n"
			. "endcodespacerange\n"
			. "4 beginbfchar\n"
			. "<0003> <0020>\n"
			. "<003C> <0059>\n"
			. "<0052> <006F>\n"
			. "<0058> <0075>\n"
			. "endbfchar\n"
			. "endcmap\n"
			. "CMapName currentdict /CMap defineresource pop\n"
			. "end end\n";
		$prefix         = "%PDF-1.4\n"
			. "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Kids [3 0 R 9 0 R] /Count 2 >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R /Resources << /Font << /F6 6 0 R >> >> /Contents 8 0 R >> endobj\n"
			. '8 0 obj << /Length ' . strlen( $content ) . " >>\nstream\n"
			. $content
			. "\nendstream\nendobj\n";
		$suffix         = "6 0 obj << /Type /Font /Subtype /Type0 /BaseFont /Subset /Encoding /Identity-H /ToUnicode 7 0 R >> endobj\n"
			. '7 0 obj << /Length ' . strlen( $cmap ) . " >>\nstream\n"
			. $cmap
			. "\nendstream\nendobj\n"
			. "9 0 obj << /Type /Page /Parent 2 0 R /Resources << /Font << /F6 10 0 R >> >> /Contents 11 0 R >> endobj\n"
			. "10 0 obj << /Type /Font /Subtype /Type0 /BaseFont /OtherSubset /Encoding /Identity-H /ToUnicode 12 0 R >> endobj\n"
			. "11 0 obj << /Length 0 >>\nstream\n\nendstream\nendobj\n"
			. '12 0 obj << /Length ' . strlen( $alternate_cmap ) . " >>\nstream\n"
			. $alternate_cmap
			. "\nendstream\nendobj\n";
		$pdf            = $prefix
			. str_repeat( '0', SourceItemDocumentProcessor::PDF_FILE_LIMIT )
			. "\n"
			. $suffix
			. "%%EOF\n";

		return $this->temporary_file( $basename, $pdf );
	}

	/**
	 * Creates a minimal PDF fixture with one text stream and an unterminated image XObject stream.
	 *
	 * @param string $basename       Fixture basename.
	 * @param string $content_stream PDF content stream body.
	 * @param string $image          Image stream bytes.
	 * @return string
	 */
	private function temporary_pdf_with_malformed_embedded_image_stream( $basename, $content_stream, $image ) {
		$pdf = "%PDF-1.4\n"
			. "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R /Resources << /XObject << /Im1 5 0 R >> >> /Contents 4 0 R >> endobj\n"
			. '4 0 obj << /Length ' . strlen( $content_stream ) . " >>\nstream\n"
			. $content_stream
			. "\nendstream\nendobj\n"
			. '5 0 obj << /Type /XObject /Subtype /Image /Width 64 /Height 64 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $image ) . " >>\nstream\n"
			. $image
			. "\n%%EOF\n";

		return $this->temporary_file( $basename, $pdf );
	}

	/**
	 * Creates a minimal PDF fixture with one malformed image stream followed by one valid image stream.
	 *
	 * @param string $basename       Fixture basename.
	 * @param string $content_stream PDF content stream body.
	 * @param string $image          Image stream bytes.
	 * @return string
	 */
	private function temporary_pdf_with_malformed_then_valid_embedded_images( $basename, $content_stream, $image ) {
		$pdf = "%PDF-1.4\n"
			. "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R /Resources << /XObject << /Im1 5 0 R /Im2 6 0 R >> >> /Contents 4 0 R >> endobj\n"
			. '4 0 obj << /Length ' . strlen( $content_stream ) . " >>\nstream\n"
			. $content_stream
			. "\nendstream\nendobj\n"
			. '5 0 obj << /Type /XObject /Subtype /Image /Width 64 /Height 64 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $image ) . " >>\nstream\n"
			. $image
			. "\nendobj\n"
			. '6 0 obj << /Type /XObject /Subtype /Image /Width 64 /Height 64 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $image ) . " >>\nstream\n"
			. $image
			. "\nendstream\nendobj\n%%EOF\n";

		return $this->temporary_file( $basename, $pdf );
	}

	/**
	 * Returns a tiny valid JPEG fixture as base64.
	 *
	 * @return string
	 */
	private function tiny_jpeg_base64() {
		return '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/ASP/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/ASP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Ar//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/IV//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z';
	}

	/**
	 * Builds a GitHub blob API response fixture.
	 *
	 * @param string $content File content.
	 * @return array{content:string,encoding:string,size:int}
	 */
	private function github_blob_response( $content ) {
		$content = (string) $content;

		return array(
			'content'  => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- GitHub's blob API returns base64 encoded content.
			'encoding' => 'base64',
			'size'     => strlen( $content ),
		);
	}

	/**
	 * Restores an environment variable after a test override.
	 *
	 * @param string      $name           Environment variable name.
	 * @param string|bool $previous_value Previous getenv() value.
	 * @return void
	 */
	private function restore_environment_variable( $name, $previous_value ) {
		if ( false === $previous_value ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test cleanup restores process state.
			putenv( $name );
			return;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unit test cleanup restores process state.
		putenv( $name . '=' . $previous_value );
	}

	/**
	 * Builds a minimal WXR export fixture.
	 *
	 * @param array<int,array{id:int,title:string,type:string,content:string}> $posts Post fixtures.
	 * @return string
	 */
	private function wxr_export( array $posts ) {
		$items = '';

		foreach ( $posts as $post ) {
			$items .= "\n<item>\n"
				. '<title>' . htmlspecialchars( $post['title'], ENT_XML1, 'UTF-8' ) . "</title>\n"
				. '<link>https://source.example.test/?p=' . (int) $post['id'] . "</link>\n"
				. '<pubDate>Wed, 05 Jun 2024 16:04:48 +0000</pubDate>' . "\n"
				. '<dc:creator><![CDATA[admin]]></dc:creator>' . "\n"
				. '<guid isPermaLink="false">https://source.example.test/?p=' . (int) $post['id'] . "</guid>\n"
				. '<description></description>' . "\n"
				. '<content:encoded><![CDATA[' . $post['content'] . ']]></content:encoded>' . "\n"
				. '<wp:post_id>' . (int) $post['id'] . '</wp:post_id>' . "\n"
				. '<wp:post_date>2024-06-05 16:04:48</wp:post_date>' . "\n"
				. '<wp:status>publish</wp:status>' . "\n"
				. '<wp:post_name>post-' . (int) $post['id'] . '</wp:post_name>' . "\n"
				. '<wp:post_type>' . htmlspecialchars( $post['type'], ENT_XML1, 'UTF-8' ) . '</wp:post_type>' . "\n"
				. "</item>\n";
		}

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0"'
			. ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
			. ' xmlns:wp="http://wordpress.org/export/1.2/">'
			. "\n<channel>\n<title>Fixture Export</title>\n"
			. '<wp:wxr_version>1.2</wp:wxr_version>' . "\n"
			. $items
			. "</channel>\n</rss>\n";
	}

	/**
	 * Creates a temporary directory fixture.
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

	/**
	 * Counts skipped archive container items.
	 *
	 * @param array<int,ImportSourceItem> $items Source items.
	 * @return int
	 */
	private function count_archive_container_items( array $items ) {
		$count = 0;

		foreach ( $items as $item ) {
			$metadata = $item->get_metadata();

			if ( isset( $metadata['archive_status'] ) && 'expanded' === $metadata['archive_status'] ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Returns whether source items include a managed cache namespace.
	 *
	 * @param array<int,ImportSourceItem> $items     Source items.
	 * @param string                      $cache_namespace Expected cache namespace.
	 * @return bool
	 */
	private function source_items_include_managed_cache_namespace( array $items, $cache_namespace ) {
		foreach ( $items as $item ) {
			$metadata = $item->get_metadata();

			if ( isset( $metadata['cache_namespace'] ) && $cache_namespace === $metadata['cache_namespace'] && false !== strpos( $item->get_source_uri(), '/managed-cache/' . $cache_namespace . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Counts skipped unsupported document items.
	 *
	 * @param array<int,ImportSourceItem> $items Source items.
	 * @return int
	 */
	private function count_unsupported_document_items( array $items ) {
		$count = 0;

		foreach ( $items as $item ) {
			$metadata = $item->get_metadata();

			if ( isset( $metadata['processor_status'] ) && 'skipped' === $metadata['processor_status'] ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Counts skipped GitHub archive source items.
	 *
	 * @param array<int,ImportSourceItem> $items Source items.
	 * @return int
	 */
	private function count_github_archive_items( array $items ) {
		$count = 0;

		foreach ( $items as $item ) {
			$metadata = $item->get_metadata();

			if ( isset( $metadata['github_repository'], $metadata['archive_status'] ) && 'expanded' === $metadata['archive_status'] ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Counts source items imported from the GitHub tree/blob API.
	 *
	 * @param array<int,ImportSourceItem> $items Source items.
	 * @return int
	 */
	private function count_github_tree_items( array $items ) {
		$count = 0;

		foreach ( $items as $item ) {
			$metadata = $item->get_metadata();

			if ( ! empty( $metadata['github_tree_fetch'] ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Builds a WXR attachment media reference for runner-level persistence tests.
	 *
	 * @param ImportSession       $session       Session.
	 * @param string              $reference_key Media reference key.
	 * @param string              $attachment_id WXR attachment id.
	 * @param int                 $local_id      Local attachment id.
	 * @param array<string,mixed> $metadata      Additional media reference metadata.
	 * @return ImportMediaReference
	 */
	private function imported_wxr_attachment_reference( ImportSession $session, $reference_key, $attachment_id, $local_id, array $metadata ) {
		$url = 'https://source.example.test/uploads/attachment-' . (string) $attachment_id . '.jpg';

		return ImportMediaReference::queued(
			$session->get_id(),
			$reference_key,
			ImportWxrAttachment::source_item_key( 'local:export', $attachment_id ),
			$url,
			$url,
			ImportMediaReference::TYPE_IMAGE,
			array_merge(
				array(
					'source'              => 'wxr',
					'wxr_attachment_id'   => (string) $attachment_id,
					'reference_scope'     => ImportWxrAttachment::SCOPE_CANDIDATE_FIRST_PARTY,
					'absolute_url_domain' => 'source.example.test',
					'attachment_id'       => $local_id,
				),
				$metadata
			)
		);
	}

	/**
	 * Finds a skipped GitHub archive source item.
	 *
	 * @param array<int,ImportSourceItem> $items Source items.
	 * @return ImportSourceItem|null
	 */
	private function find_github_archive_item( array $items ) {
		foreach ( $items as $item ) {
			$metadata = $item->get_metadata();

			if ( isset( $metadata['github_repository'], $metadata['archive_status'] ) && 'expanded' === $metadata['archive_status'] ) {
				return $item;
			}
		}

		return null;
	}
}
