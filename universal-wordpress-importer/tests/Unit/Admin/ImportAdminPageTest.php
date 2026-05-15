<?php
/**
 * Tests for the WordPress admin importer surface.
 *
 * @package UniversalImporter\Tests\Unit\Admin
 */

namespace UniversalImporter\Tests\Unit\Admin;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Unit fixtures use isolated temporary files without WordPress loaded.

use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use UniversalImporter\Admin\ImportAdminPage;
use UniversalImporter\Import\ImportDecision;
use UniversalImporter\Import\ImportCacheDirectory;
use UniversalImporter\Import\ImportIdempotencyRecord;
use UniversalImporter\Import\ImportPreparedDocument;
use UniversalImporter\Import\ImportProgressEvent;
use UniversalImporter\Import\ImportRelationshipMappingDecision;
use UniversalImporter\Import\ImportRunner;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportSessionId;
use UniversalImporter\Import\ImportSourceItem;
use UniversalImporter\Import\WordPressImportSessionStore;
use UniversalImporter\Tests\Unit\Import\FakePostGateway;
use UniversalImporter\Tests\Unit\Import\FakeWpdb;

/**
 * Covers browser-session operations without a full WordPress runtime.
 */
final class ImportAdminPageTest extends TestCase {
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
	 * Captured scheduled session ids.
	 *
	 * @var array<int,string>
	 */
	private $scheduled_sessions;

	/**
	 * Fake current timestamp.
	 *
	 * @var int
	 */
	private $now;

	/**
	 * Temporary paths to remove after tests.
	 *
	 * @var array<int,string>
	 */
	private $temporary_paths = array();

	/**
	 * Sets up admin dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wpdb               = new FakeWpdb();
		$this->now                = 1000;
		$this->store              = new WordPressImportSessionStore(
			$this->wpdb,
			null,
			function () {
				return $this->now;
			}
		);
		$this->scheduled_sessions = array();
	}

	/**
	 * Removes temporary filesystem fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( array_reverse( $this->temporary_paths ) as $path ) {
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			} elseif ( is_dir( $path ) ) {
				$this->remove_tree( $path );
			}
		}

		parent::tearDown();
	}

	/**
	 * Admin creation stores a durable session, decision, event, and schedule.
	 *
	 * @return void
	 */
	public function test_create_import_session_queues_session_and_returns_snapshot() {
		$page = $this->create_page();

		$snapshot = $page->create_import_session(
			'/tmp/book.md',
			array( 'Example.com', 'example.com', 'www.example.com' ),
			true
		);

		$session = $this->store->find( ImportSessionId::from_string( $snapshot['id'] ) );

		$this->assertNotNull( $session );
		$this->assertTrue( $session->is_dry_run() );
		$this->assertSame( ImportSession::STATUS_PENDING, $snapshot['status'] );
		$this->assertTrue( $snapshot['dry_run'] );
		$this->assertSame( array( $snapshot['id'] ), $this->scheduled_sessions );
		$this->assertSame( 'session.created', $snapshot['recent_events'][0]['type'] );

		$decision = $this->store->find_decision( $session->get_id(), 'confirm-first-party-domains' );

		$this->assertNotNull( $decision );
		$this->assertSame( ImportDecision::STATUS_RESOLVED, $decision->get_status() );
		$this->assertSame(
			array( 'confirmed_domains' => array( 'example.com', 'www.example.com' ) ),
			$decision->get_answer()
		);
	}

	/**
	 * Browser-uploaded folder files are staged as a local directory import.
	 *
	 * @return void
	 */
	public function test_create_import_session_from_uploaded_files_stages_browser_folder() {
		$cache_root = $this->temporary_directory();
		$page       = $this->create_page( null, new ImportCacheDirectory( $cache_root, 'unit-test' ) );

		$snapshot = $page->create_import_session_from_uploaded_files(
			array( $this->uploaded_fixture( 'chapter.md', '# Browser chapter' ) ),
			array( 'Dropped Folder/chapters/chapter.md' ),
			array(),
			true
		);

		$session = $this->store->find( ImportSessionId::from_string( $snapshot['id'] ) );
		$staged  = $session->get_source() . '/Dropped-Folder/chapters/chapter.md';

		$this->assertNotNull( $session );
		$this->assertTrue( $session->is_dry_run() );
		$this->assertSame( array( $snapshot['id'] ), $this->scheduled_sessions );
		$this->assertFileExists( $staged );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Unit test reads an isolated staged fixture.
		$this->assertSame( '# Browser chapter', file_get_contents( $staged ) );
		$this->assertStringContainsString( '/browser-uploads/' . $snapshot['id'] . '/tree', $session->get_source() );
		$this->assertSame( 'session.created', $snapshot['recent_events'][0]['type'] );
	}

	/**
	 * Browser-uploaded folder sessions continue through the shared runner.
	 *
	 * @return void
	 */
	public function test_keepalive_completes_dry_run_browser_uploaded_folder_session() {
		$page = $this->create_page(
			function ( WordPressImportSessionStore $store ) {
				return new ImportRunner( $store, 'admin-test', 60 );
			},
			new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' )
		);

		$snapshot = $page->create_import_session_from_uploaded_files(
			array( $this->uploaded_fixture( 'chapter.md', "# Browser upload\n\nFolder body." ) ),
			array( 'Dropped Folder/chapters/chapter.md' ),
			array(),
			true
		);
		$result   = null;

		for ( $i = 0; $i < 5; $i++ ) {
			$result = $page->run_keepalive( $snapshot['id'] );

			if ( ImportSession::STATUS_DONE === $result['session']['status'] ) {
				break;
			}
		}

		$this->assertSame( ImportSession::STATUS_DONE, $result['session']['status'] );
		$this->assertTrue( $result['session']['dry_run'] );
		$this->assertSame( 'session.done', $result['session']['recent_events'][0]['type'] );
	}

	/**
	 * Browser-uploaded PDF files continue through draft creation without a stuck dashboard state.
	 *
	 * @return void
	 */
	public function test_keepalive_imports_browser_uploaded_pdf_file_session() {
		$posts = new FakePostGateway();
		$page  = $this->create_page(
			function ( WordPressImportSessionStore $store ) use ( $posts ) {
				return new ImportRunner( $store, 'admin-test', 60, null, $posts );
			},
			new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' )
		);

		$snapshot = $page->create_import_session_from_uploaded_files(
			array(
				$this->uploaded_fixture(
					'Annual Report.pdf',
					$this->temporary_pdf_contents(
						"BT\n/F1 12 Tf\n72 720 Td\n(A) Tj\n(n) Tj\n(n) Tj\n(u) Tj\n(a) Tj\n(l) Tj\n( ) Tj\n(R) Tj\n(e) Tj\n(p) Tj\n(o) Tj\n(r) Tj\n(t) Tj\n0 -14 Td\n(Body text from uploaded PDF.) Tj\nET"
					)
				),
			),
			array( 'Annual Report.pdf' ),
			array(),
			false,
			'preserve'
		);
		$result   = null;

		for ( $i = 0; $i < 8; $i++ ) {
			$result = $page->run_keepalive( $snapshot['id'] );

			if ( ImportSession::STATUS_DONE === $result['session']['status'] ) {
				break;
			}
		}

		$session  = $this->store->find( ImportSessionId::from_string( $snapshot['id'] ) );
		$document = $this->store->list_recent_prepared_documents( $session->get_id(), 1 )[0];

		$this->assertSame( ImportSession::STATUS_DONE, $result['session']['status'] );
		$this->assertFalse( $result['session']['dry_run'] );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertSame( 'pdf', $document->get_format() );
		$this->assertStringContainsString( 'Annual Report', $document->get_block_markup() );
		$this->assertStringContainsString( 'Body text from uploaded PDF.', $document->get_block_markup() );
		$this->assertSame( 'Import complete. Review the created drafts and any warnings.', $result['session']['dashboard']['current_action'] );
		$this->assertFalse( $result['session']['dashboard']['needs_keepalive'] );
		$this->assertStringNotContainsString( 'Checking remaining importer work', $result['session']['dashboard']['current_action'] );
	}

	/**
	 * Failed source items render as attention states instead of vague background work.
	 *
	 * @return void
	 */
	public function test_status_snapshot_explains_failed_source_attention() {
		$page     = $this->create_page();
		$snapshot = $page->create_import_session( '/tmp/broken.pdf' );
		$session  = $this->store->find( ImportSessionId::from_string( $snapshot['id'] ) );

		$this->store->save( $session->mark_running() );
		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				'local:broken.pdf',
				null,
				'/tmp/broken.pdf',
				'broken.pdf',
				ImportSourceItem::TYPE_FILE
			)->with_status( ImportSourceItem::STATUS_FAILED )->with_metadata(
				array(
					'error' => 'PDF text extraction produced no importable text.',
				)
			)
		);

		$details = $page->get_status_snapshot( $session->get_id() );

		$this->assertStringContainsString( 'Import needs attention', $details['dashboard']['current_action'] );
		$this->assertStringContainsString( 'source item failed', $details['dashboard']['attention_message'] );
		$this->assertFalse( $details['dashboard']['needs_keepalive'] );
		$this->assertSame( 'blocked', $details['dashboard']['checklist'][0]['state'] );
		$this->assertSame( 'blocked', $details['dashboard']['checklist'][5]['state'] );
		$this->assertSame( 'PDF text extraction produced no importable text.', $details['source_items']['recent'][0]['metadata']['error'] );
		$this->assertStringNotContainsString( 'Checking remaining importer work', $details['dashboard']['current_action'] );
	}

	/**
	 * The admin page declares browser folder upload and drag/drop controls.
	 *
	 * @return void
	 */
	public function test_admin_page_declares_browser_folder_drop_controls() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Unit test inspects the admin source template.
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/ImportAdminPage.php' );

		$this->assertIsString( $source );
		$this->assertStringContainsString( 'id="universal-importer-dropzone"', $source );
		$this->assertStringContainsString( 'id="universal-importer-file-picker"', $source );
		$this->assertStringContainsString( 'id="universal-importer-folder-picker"', $source );
		$this->assertStringContainsString( 'accept=".pdf,.epub,.html,.htm,.md,.markdown,.txt,.xml,.wxr,.zip', $source );
		$this->assertStringContainsString( 'id="universal-importer-folder-picker" class="universal-importer-file-input" multiple webkitdirectory directory', $source );
		$this->assertStringContainsString( 'Choose files', $source );
		$this->assertStringContainsString( 'Choose folder', $source );
		$this->assertStringContainsString( 'universal-importer-file-preview', $source );
		$this->assertStringContainsString( "dropzone.addEventListener('drop'", $source );
		$this->assertStringContainsString( 'readDirectoryEntries', $source );
		$this->assertStringContainsString( 'webkitGetAsEntry', $source );
		$this->assertStringContainsString( 'Or upload files from this computer', $source );
		$this->assertStringContainsString( 'countFilesByExtension(browserFiles, \'.pdf\')', $source );
		$this->assertStringContainsString( 'Import source', $source );
		$this->assertStringContainsString( 'URL rewriting', $source );
		$this->assertStringContainsString( 'Ask when URLs are found', $source );
		$this->assertStringContainsString( 'Keep imported URLs unchanged', $source );
		$this->assertStringContainsString( 'Rewrite known source domains', $source );
		$this->assertStringContainsString( 'url_rewrite_mode', $source );
		$this->assertStringContainsString( 'universal-importer-progressbar', $source );
		$this->assertStringContainsString( 'universal-importer-current-action', $source );
		$this->assertStringContainsString( 'data-url-choice="none"', $source );
		$this->assertStringContainsString( "'sessions' => \$sessions", $source );
		$this->assertStringContainsString( 'wp_json_encode( $config )', $source );
		$this->assertStringContainsString( 'function sessionNeedsKeepalive(session)', $source );
		$this->assertStringContainsString( 'function reattachActiveSession()', $source );
		$this->assertStringContainsString( 'reattachActiveSession();', $source );
	}

	/**
	 * Preserve mode stores an explicit "do not rewrite" URL decision.
	 *
	 * @return void
	 */
	public function test_create_import_session_can_preserve_imported_urls() {
		$page     = $this->create_page();
		$snapshot = $page->create_import_session( '/tmp/book.md', array(), false, 'preserve' );
		$session  = $this->store->find( ImportSessionId::from_string( $snapshot['id'] ) );
		$decision = $this->store->find_decision( $session->get_id(), 'confirm-first-party-domains' );

		$this->assertNotNull( $decision );
		$this->assertSame( ImportDecision::STATUS_RESOLVED, $decision->get_status() );
		$this->assertSame( array( 'confirmed_domains' => array() ), $decision->get_answer() );
	}

	/**
	 * Rewrite mode requires an explicit source domain list.
	 *
	 * @return void
	 */
	public function test_create_import_session_requires_domains_for_rewrite_mode() {
		$page = $this->create_page();

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Enter at least one source domain to rewrite, or choose to be asked later.' );

		$page->create_import_session( '/tmp/book.md', array(), false, 'rewrite' );
	}

	/**
	 * The inline admin script builds relative upload paths for dropped folders.
	 *
	 * @return void
	 */
	public function test_admin_browser_script_builds_relative_paths_from_dropped_directory() {
		$node = $this->node_binary();

		if ( '' === $node ) {
			$this->markTestSkipped( 'Node.js is not available for the admin browser upload script harness.' );
		}

		$command = implode(
			' ',
			array_map(
				'escapeshellarg',
				array(
					$node,
					__DIR__ . '/admin-browser-upload-ui-test.js',
					dirname( __DIR__, 3 ),
				)
			)
		);
		$pipes   = array();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Unit test executes a local Node harness for inline browser JavaScript.
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			dirname( __DIR__, 3 )
		);

		$this->assertIsResource( $process );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Unit test closes process pipes it owns.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Unit test closes process pipes it owns.
		fclose( $pipes[2] );

		$this->assertSame( 0, proc_close( $process ), ( false === $stdout ? '' : $stdout ) . ( false === $stderr ? '' : $stderr ) );
	}

	/**
	 * The inline admin browser upload flow works in a real headless browser.
	 *
	 * @return void
	 */
	public function test_admin_browser_script_handles_dropped_directory_in_chromium() {
		$node     = $this->node_binary();
		$chromium = $this->chromium_binary();

		if ( '1' === getenv( 'UNIVERSAL_IMPORTER_SKIP_CHROMIUM_TEST' ) ) {
			$this->markTestSkipped( 'Chromium browser upload integration harness is disabled for this environment.' );
		}

		if ( '' === $node ) {
			$this->markTestSkipped( 'Node.js is not available for the Chromium browser upload harness.' );
		}

		if ( '' === $chromium ) {
			$this->markTestSkipped( 'Chromium is not available for the browser upload integration harness.' );
		}

		$command = implode(
			' ',
			array_map(
				'escapeshellarg',
				array(
					$node,
					__DIR__ . '/admin-browser-upload-chromium-test.js',
					dirname( __DIR__, 3 ),
					$chromium,
				)
			)
		);
		$pipes   = array();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Unit test executes a local Chromium browser integration harness.
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			dirname( __DIR__, 3 )
		);

		$this->assertIsResource( $process );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Unit test closes process pipes it owns.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Unit test closes process pipes it owns.
		fclose( $pipes[2] );

		$this->assertSame( 0, proc_close( $process ), ( false === $stdout ? '' : $stdout ) . ( false === $stderr ? '' : $stderr ) );
	}

	/**
	 * Browser upload staging rejects unsafe relative paths before cache writes.
	 *
	 * @return void
	 */
	public function test_create_import_session_from_uploaded_files_rejects_parent_paths() {
		$page = $this->create_page( null, new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Browser upload paths cannot contain parent directory segments.' );

		$page->create_import_session_from_uploaded_files(
			array( $this->uploaded_fixture( 'chapter.md', '# Browser chapter' ) ),
			array( 'Dropped Folder/../chapter.md' )
		);
	}

	/**
	 * Browser upload staging rejects duplicate normalized paths.
	 *
	 * @return void
	 */
	public function test_create_import_session_from_uploaded_files_rejects_duplicate_paths() {
		$page = $this->create_page( null, new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Browser upload contains a duplicate file path.' );

		$page->create_import_session_from_uploaded_files(
			array(
				$this->uploaded_fixture( 'one.md', '# One' ),
				$this->uploaded_fixture( 'two.md', '# Two' ),
			),
			array(
				'Dropped Folder/chapter.md',
				'Dropped-Folder/chapter.md',
			)
		);
	}

	/**
	 * Browser upload staging rejects PHP upload errors.
	 *
	 * @return void
	 */
	public function test_create_import_session_from_uploaded_files_rejects_upload_errors() {
		$page = $this->create_page( null, new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'A browser-uploaded file could not be read; PHP reported an upload error.' );

		$page->create_import_session_from_uploaded_files(
			array(
				array(
					'name'     => 'chapter.md',
					'tmp_name' => '',
					'error'    => UPLOAD_ERR_INI_SIZE,
					'size'     => 0,
				),
			),
			array( 'chapter.md' )
		);
	}

	/**
	 * Browser upload staging rejects excessive batches before reading temp files.
	 *
	 * @return void
	 */
	public function test_create_import_session_from_uploaded_files_rejects_too_many_files() {
		$page  = $this->create_page( null, new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' ) );
		$files = array_fill(
			0,
			ImportAdminPage::MAX_UPLOAD_FILES + 1,
			array(
				'name'     => 'chapter.md',
				'tmp_name' => '',
				'error'    => UPLOAD_ERR_OK,
				'size'     => 0,
			)
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Too many files were submitted in one browser import.' );

		$page->create_import_session_from_uploaded_files( $files );
	}

	/**
	 * Browser upload staging rejects oversized batches before reading temp files.
	 *
	 * @return void
	 */
	public function test_create_import_session_from_uploaded_files_rejects_too_many_bytes() {
		$page = $this->create_page( null, new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Browser upload import is limited to 128 MB per session.' );

		$page->create_import_session_from_uploaded_files(
			array(
				array(
					'name'     => 'chapter.md',
					'tmp_name' => '',
					'error'    => UPLOAD_ERR_OK,
					'size'     => ImportAdminPage::MAX_UPLOAD_BYTES + 1,
				),
			),
			array( 'chapter.md' )
		);
	}

	/**
	 * Browser keepalive uses the shared runner contract and returns a fresh snapshot.
	 *
	 * @return void
	 */
	public function test_keepalive_runs_runner_and_returns_snapshot() {
		$page     = $this->create_page();
		$snapshot = $page->create_import_session( '/tmp/book.md' );
		$result   = $page->run_keepalive( $snapshot['id'] );

		$this->assertSame(
			array(
				'processed' => 1,
				'locked'    => 0,
				'skipped'   => 0,
				'errors'    => 0,
			),
			$result['summary']
		);
		$this->assertSame( ImportSession::STATUS_RUNNING, $result['session']['status'] );
		$this->assertFalse( $result['session']['dry_run'] );
		$this->assertSame( 'admin-runner.tick', $result['session']['recent_events'][0]['type'] );
	}

	/**
	 * Browser keepalive can complete a local filesystem dry-run session.
	 *
	 * @return void
	 */
	public function test_keepalive_completes_dry_run_local_filesystem_session() {
		$page        = $this->create_page(
			function ( WordPressImportSessionStore $store ) {
				return new ImportRunner( $store, 'admin-test', 60 );
			}
		);
		$source_file = $this->temporary_file( 'admin-dry-run.md', "# Admin dry run\n\nBrowser body." );
		$snapshot    = $page->create_import_session( $source_file, array(), true );
		$result      = $page->run_keepalive( $snapshot['id'] );

		$this->assertSame(
			array(
				'processed' => 1,
				'locked'    => 0,
				'skipped'   => 0,
				'errors'    => 0,
			),
			$result['summary']
		);
		$this->assertSame( ImportSession::STATUS_DONE, $result['session']['status'] );
		$this->assertTrue( $result['session']['dry_run'] );
		$this->assertSame( 'session.done', $result['session']['recent_events'][0]['type'] );
	}

	/**
	 * Browser keepalive can reattach to a pre-existing pending dry-run session.
	 *
	 * @return void
	 */
	public function test_keepalive_reattaches_to_existing_pending_dry_run_session() {
		$page        = $this->create_page(
			function ( WordPressImportSessionStore $store ) {
				return new ImportRunner( $store, 'admin-test', 60 );
			}
		);
		$source_file = $this->temporary_file( 'admin-existing-dry-run.md', "# Existing dry run\n\nReload body." );
		$session     = ImportSession::start_for_source( $source_file, true );

		$this->store->save( $session );

		$result = $page->run_keepalive( $session->get_id()->to_string() );

		$this->assertSame( ImportSession::STATUS_DONE, $result['session']['status'] );
		$this->assertTrue( $result['session']['dry_run'] );
		$this->assertSame( 'session.done', $result['session']['recent_events'][0]['type'] );
	}

	/**
	 * Status snapshots expose detailed pipeline progress for the browser UI.
	 *
	 * @return void
	 */
	public function test_status_snapshot_includes_source_document_and_post_details() {
		$page     = $this->create_page();
		$snapshot = $page->create_import_session( '/tmp/book' );
		$session  = $this->store->find( ImportSessionId::from_string( $snapshot['id'] ) );

		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				'local:root',
				null,
				'/tmp/book',
				'',
				ImportSourceItem::TYPE_DIRECTORY
			)->with_status( ImportSourceItem::STATUS_SKIPPED )
		);
		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				'local:chapter',
				'local:root',
				'/tmp/book/chapter.md',
				'chapter.md',
				ImportSourceItem::TYPE_FILE
			)->with_status( ImportSourceItem::STATUS_IMPORTED )
		);
		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				'local:book.epub',
				null,
				'/tmp/book/book.epub',
				'book.epub',
				ImportSourceItem::TYPE_FILE,
				array(
					'document_format'  => 'epub',
					'epub_title'       => 'Snapshot Book',
					'epub_toc_source'  => 'ncx',
					'epub_toc_entry'   => 'OEBPS/toc.ncx',
					'epub_toc_count'   => 2,
					'epub_toc_entries' => array(
						array(
							'label'           => 'Intro',
							'target_path'     => 'OEBPS/intro.xhtml',
							'target_fragment' => '',
						),
						array(
							'label'           => 'Chapter',
							'target_path'     => 'OEBPS/chapter.xhtml',
							'target_fragment' => 'section',
						),
					),
				)
			)->with_status( ImportSourceItem::STATUS_IMPORTED )
		);
		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				'local:scanned.pdf',
				null,
				'/tmp/book/scanned.pdf',
				'scanned.pdf',
				ImportSourceItem::TYPE_FILE,
				array(
					'document_format'         => 'pdf',
					'title'                   => 'Scanned PDF',
					'pdf_text_engine'         => 'ocr',
					'pdf_ocr_status'          => 'succeeded',
					'pdf_layout_warning'      => 'PDF contains table/vector layout signals; first-pass PDF processing imports normalized text blocks.',
					'pdf_embedded_media_hint' => 'PDF contains embedded image references; first-pass PDF processing records text only.',
				)
			)->with_status( ImportSourceItem::STATUS_IMPORTED )
		);
		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				'remote:root',
				null,
				'https://source.example.test/wp-json/',
				'https://source.example.test/wp-json/',
				ImportSourceItem::TYPE_DIRECTORY,
				array(
					'remote_status'     => 'rate-limited',
					'remote_rate_limit' => array(
						'url'                 => 'https://source.example.test/wp-json/wp/v2/posts?page=2',
						'status_code'         => 429,
						'retry_after_header'  => '120',
						'retry_after_seconds' => 120,
						'next_retry_at'       => gmdate( 'c', time() + 120 ),
						'next_retry_unix'     => time() + 120,
					),
				)
			)->with_status( ImportSourceItem::STATUS_PROCESSING )
		);
		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				'local:image-only.pdf',
				null,
				'/tmp/book/image-only.pdf',
				'image-only.pdf',
				ImportSourceItem::TYPE_FILE,
				array(
					'document_format' => 'pdf',
					'pdf_text_engine' => 'native',
					'pdf_ocr_status'  => 'not_configured',
					'error'           => 'PDF text extraction produced no importable text.',
					'pdf_ocr_hint'    => 'Set UNIVERSAL_IMPORTER_PDF_OCR_COMMAND to enable OCR.',
				)
			)->with_status( ImportSourceItem::STATUS_FAILED )
		);
		$this->store->save_prepared_document(
			new ImportPreparedDocument(
				$session->get_id(),
				'local:chapter',
				'markdown',
				'Chapter',
				'<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->',
				1,
				'hash-a',
				array( 'relative_path' => 'chapter.md' )
			)
		);
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:local:chapter', 'post', '123', 'hash-a' )
		);

		$details = $page->get_status_snapshot( $session->get_id() );

		$this->assertSame( 6, $details['source_items']['total'] );
		$this->assertSame( 3, $details['source_items']['statuses'][ ImportSourceItem::STATUS_IMPORTED ] );
		$this->assertSame( 1, $details['source_items']['statuses'][ ImportSourceItem::STATUS_PROCESSING ] );
		$this->assertSame( 1, $details['source_items']['statuses'][ ImportSourceItem::STATUS_SKIPPED ] );
		$this->assertSame( 1, $details['source_items']['statuses'][ ImportSourceItem::STATUS_FAILED ] );
		$this->assertSame( 1, $details['remote_backoff']['total'] );
		$this->assertSame( 429, $details['remote_backoff']['recent'][0]['status_code'] );
		$this->assertSame( '120', $details['remote_backoff']['recent'][0]['retry_after_header'] );
		$this->assertGreaterThan( 0, $details['remote_backoff']['recent'][0]['remaining_seconds'] );
		$this->assertStringContainsString( '/wp/v2/posts?page=2', $details['remote_backoff']['recent'][0]['url'] );
		$this->assertSame( 1, $details['prepared_documents']['total'] );
		$this->assertSame( 'Chapter', $details['prepared_documents']['recent'][0]['title'] );
		$this->assertSame( 1, $details['epub_tocs']['total'] );
		$this->assertSame( 'Snapshot Book', $details['epub_tocs']['recent'][0]['title'] );
		$this->assertSame( 'ncx', $details['epub_tocs']['recent'][0]['source'] );
		$this->assertSame( 'OEBPS/chapter.xhtml#section', $details['epub_tocs']['recent'][0]['entries'][1]['target'] );
		$this->assertSame( 2, $details['pdf_documents']['total'] );
		$this->assertSame( 'Scanned PDF', $details['pdf_documents']['recent'][0]['title'] );
		$this->assertSame( 'ocr', $details['pdf_documents']['recent'][0]['engine'] );
		$this->assertStringContainsString( 'table/vector layout signals', $details['pdf_documents']['recent'][0]['hint'] );
		$this->assertStringContainsString( 'embedded image references', $details['pdf_documents']['recent'][0]['hint'] );
		$this->assertSame( 'not_configured', $details['pdf_documents']['recent'][1]['ocr_status'] );
		$this->assertStringContainsString( 'UNIVERSAL_IMPORTER_PDF_OCR_COMMAND', $details['pdf_documents']['recent'][1]['hint'] );
		$this->assertSame( 1, $details['posts']['persisted'] );
		$this->assertArrayHasKey( 'dashboard', $details );
		$this->assertArrayHasKey( 'current_action', $details['dashboard'] );
		$this->assertArrayHasKey( 'checklist', $details['dashboard'] );
		$this->assertNotEmpty( $details['dashboard']['checklist'] );
		$this->assertSame( 6, $details['dashboard']['summary']['total'] );
	}

	/**
	 * Status snapshots expose relationship warnings for browser operators.
	 *
	 * @return void
	 */
	public function test_status_snapshot_includes_relationship_warnings() {
		$page     = $this->create_page();
		$snapshot = $page->create_import_session( 'https://example.com/wp-json/' );
		$session  = $this->store->find( ImportSessionId::from_string( $snapshot['id'] ) );

		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_WARNING,
				ImportRelationshipMappingDecision::WARNING_EVENT,
				'Remote REST author or taxonomy relationships need operator review after draft creation.',
				array(
					'post_id'         => 456,
					'source_item_key' => 'remote:book:9',
					'relationships'   => array(
						'author' => array(
							'status' => 'unmapped',
						),
						'terms'  => array(
							'series' => array(
								'status' => 'unmapped',
							),
						),
					),
				)
			)
		);
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				ImportRelationshipMappingDecision::decision_key( 'remote:book:9' ),
				'Map the remote REST author and taxonomy relationships for imported draft post 456.',
				array(
					'source_item_key' => 'remote:book:9',
					'answer_template' => array(
						'author' => array(
							'local_user_id' => 0,
						),
					),
				)
			)
		);

		$details = $page->get_status_snapshot( $session->get_id() );

		$this->assertCount( 1, $details['relationship_warnings'] );
		$this->assertSame(
			'Post 456 from remote:book:9: author unmapped; series unmapped',
			$details['relationship_warnings'][0]['summary']
		);
		$this->assertSame( 'post.relationships_partially_mapped', $details['relationship_warnings'][0]['event']['type'] );
		$this->assertSame( ImportRelationshipMappingDecision::decision_key( 'remote:book:9' ), $details['pending_decisions'][0]['key'] );
		$this->assertSame( 0, $details['pending_decisions'][0]['options']['answer_template']['author']['local_user_id'] );
	}

	/**
	 * Admin abort persists a terminal state and observable event.
	 *
	 * @return void
	 */
	public function test_abort_import_session_marks_session_aborted() {
		$page     = $this->create_page();
		$snapshot = $page->create_import_session( '/tmp/book.md' );
		$aborted  = $page->abort_import_session( $snapshot['id'] );

		$this->assertSame( ImportSession::STATUS_ABORTED, $aborted['status'] );
		$this->assertSame( 'session.aborted', $aborted['recent_events'][0]['type'] );
	}

	/**
	 * Admin decision resolution stores the answer, records an event, and schedules continuation.
	 *
	 * @return void
	 */
	public function test_resolve_import_decision_updates_pending_decision() {
		$page     = $this->create_page();
		$snapshot = $page->create_import_session( '/tmp/book.md' );
		$session  = $this->store->find( ImportSessionId::from_string( $snapshot['id'] ) );

		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Confirm first-party domains before URL rewriting.',
				array( 'domains' => array( 'example.com', 'www.example.com' ) )
			)
		);

		$resolved = $page->resolve_import_decision(
			$snapshot['id'],
			'confirm-first-party-domains',
			array( 'confirmed_domains' => array( 'example.com' ) )
		);

		$decision = $this->store->find_decision( $session->get_id(), 'confirm-first-party-domains' );

		$this->assertSame( array(), $resolved['pending_decisions'] );
		$this->assertSame( ImportDecision::STATUS_RESOLVED, $decision->get_status() );
		$this->assertSame( array( 'confirmed_domains' => array( 'example.com' ) ), $decision->get_answer() );
		$this->assertSame( 'decision.resolved', $resolved['recent_events'][0]['type'] );
		$this->assertSame( array( $snapshot['id'], $snapshot['id'] ), $this->scheduled_sessions );
	}

	/**
	 * Unknown admin decisions produce actionable diagnostics.
	 *
	 * @return void
	 */
	public function test_resolve_import_decision_rejects_unknown_decision() {
		$page     = $this->create_page();
		$snapshot = $page->create_import_session( '/tmp/book.md' );

		$this->expectExceptionMessage( 'Import decision not found: choose-author' );

		$page->resolve_import_decision( $snapshot['id'], 'choose-author', array( 'user_id' => 42 ) );
	}

	/**
	 * Recent sessions are returned newest first for the admin page.
	 *
	 * @return void
	 */
	public function test_list_recent_session_snapshots_returns_newest_first() {
		$page  = $this->create_page();
		$first = $page->create_import_session( '/tmp/first.md' );
		++$this->now;
		$second = $page->create_import_session( '/tmp/second.md' );

		$recent = $page->list_recent_session_snapshots( 2 );

		$this->assertSame( array( $second['id'], $first['id'] ), array( $recent[0]['id'], $recent[1]['id'] ) );
	}

	/**
	 * Empty sources produce actionable diagnostics.
	 *
	 * @return void
	 */
	public function test_create_import_session_rejects_empty_source() {
		$page = $this->create_page();

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'A source path or URL is required.' );

		$page->create_import_session( '   ' );
	}

	/**
	 * Creates an admin page with fake dependencies.
	 *
	 * @param callable|null             $runner_factory  Optional runner factory.
	 * @param ImportCacheDirectory|null $cache_directory Optional upload cache directory.
	 * @return ImportAdminPage
	 */
	private function create_page( callable $runner_factory = null, ImportCacheDirectory $cache_directory = null ) {
		return new ImportAdminPage(
			$this->store,
			function ( ImportSessionId $session_id ) {
				$this->scheduled_sessions[] = $session_id->to_string();
			},
			null === $runner_factory ? function ( WordPressImportSessionStore $store ) {
				return new FakeAdminRunner( $store );
			} : $runner_factory,
			$cache_directory
		);
	}

	/**
	 * Returns an available Node.js binary for browser script tests.
	 *
	 * @return string
	 */
	private function node_binary() {
		$configured = trim( (string) getenv( 'UNIVERSAL_IMPORTER_NODE_BINARY' ) );

		if ( '' !== $configured && is_executable( $configured ) ) {
			return $configured;
		}

		foreach ( explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ) as $path ) {
			if ( '' === $path ) {
				continue;
			}

			$candidate = rtrim( $path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'node';

			if ( is_executable( $candidate ) ) {
				return $candidate;
			}
		}

		foreach ( array( '/usr/bin/node', '/usr/local/bin/node', '/run/current-system/sw/bin/node' ) as $candidate ) {
			if ( is_executable( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Returns an available Chromium binary for browser integration tests.
	 *
	 * @return string
	 */
	private function chromium_binary() {
		$configured = trim( (string) getenv( 'UNIVERSAL_IMPORTER_CHROMIUM_BINARY' ) );

		if ( '' !== $configured && is_executable( $configured ) ) {
			return $configured;
		}

		foreach ( explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ) as $path ) {
			if ( '' === $path ) {
				continue;
			}

			foreach ( array( 'chromium', 'chromium-browser', 'google-chrome' ) as $binary ) {
				$candidate = rtrim( $path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $binary;

				if ( is_executable( $candidate ) ) {
					return $candidate;
				}
			}
		}

		foreach ( array( '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome', '/usr/local/bin/chromium', '/usr/local/bin/chromium-browser', '/usr/local/bin/google-chrome', '/run/current-system/sw/bin/chromium', '/run/current-system/sw/bin/chromium-browser' ) as $candidate ) {
			if ( is_executable( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Creates a temporary local source file.
	 *
	 * @param string $basename File basename.
	 * @param string $contents File contents.
	 * @return string
	 */
	private function temporary_file( $basename, $contents ) {
		$path = sys_get_temp_dir() . '/universal-importer-admin-' . bin2hex( random_bytes( 6 ) ) . '-' . $basename;

		$this->assertNotFalse( file_put_contents( $path, $contents ) );
		$this->temporary_paths[] = $path;

		return $path;
	}

	/**
	 * Creates an uploaded file row backed by a temporary fixture.
	 *
	 * @param string $name     Uploaded filename.
	 * @param string $contents File contents.
	 * @return array<string,mixed>
	 */
	private function uploaded_fixture( $name, $contents ) {
		$path = $this->temporary_file( $name, $contents );

		return array(
			'name'     => $name,
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $path ),
		);
	}

	/**
	 * Creates a minimal single-stream PDF fixture body.
	 *
	 * @param string $content_stream PDF content stream.
	 * @return string
	 */
	private function temporary_pdf_contents( $content_stream ) {
		$stream = gzcompress( $content_stream );

		return "%PDF-1.4\n"
			. "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R /Contents 4 0 R >> endobj\n"
			. '4 0 obj << /Length ' . strlen( $stream ) . " /Filter /FlateDecode >>\nstream\n"
			. $stream
			. "\nendstream\nendobj\n%%EOF\n";
	}

	/**
	 * Creates a temporary directory.
	 *
	 * @return string
	 */
	private function temporary_directory() {
		$path = sys_get_temp_dir() . '/universal-importer-admin-' . bin2hex( random_bytes( 6 ) );

		$this->assertTrue( mkdir( $path, 0777, true ) );
		$this->assertTrue( chmod( $path, 0777 ) );
		$this->temporary_paths[] = $path;

		return $path;
	}

	/**
	 * Removes a directory tree.
	 *
	 * @param string $path Directory path.
	 * @return void
	 */
	private function remove_tree( $path ) {
		if ( ! is_dir( $path ) ) {
			return;
		}

		foreach ( scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$child = $path . '/' . $entry;
			if ( is_dir( $child ) && ! is_link( $child ) ) {
				$this->remove_tree( $child );
			} else {
				unlink( $child );
			}
		}

		rmdir( $path );
	}
}
