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
use UniversalImporter\Import\SourceItemDocumentProcessor;
use UniversalImporter\Import\WordPressImportSessionStore;
use UniversalImporter\Tests\Unit\Import\FakeGitRepositoryFetcher;
use UniversalImporter\Tests\Unit\Import\FakeRemoteContentFetcher;
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
		$this->assertSame( 'Import complete.', $result['session']['dashboard']['current_action'] );
		$this->assertFalse( $result['session']['dashboard']['needs_keepalive'] );
		$this->assertStringNotContainsString( 'Checking remaining importer work', $result['session']['dashboard']['current_action'] );
	}

	/**
	 * Browser keepalive runs enough bounded PDF ticks to avoid leaving large uploads at discovered/unknown.
	 *
	 * @return void
	 */
	public function test_keepalive_bursts_large_browser_uploaded_pdf_to_draft() {
		$posts = new FakePostGateway();
		$page  = $this->create_page(
			function ( WordPressImportSessionStore $store ) use ( $posts ) {
				return new ImportRunner( $store, 'admin-test', 60, null, $posts );
			},
			new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' )
		);
		$count = SourceItemDocumentProcessor::PDF_STRUCTURE_SCAN_LIMIT + 10;

		$snapshot = $page->create_import_session_from_uploaded_files(
			array(
				$this->uploaded_fixture(
					'Screenplay.pdf',
					$this->temporary_multi_stream_pdf_contents( $count )
				),
			),
			array( 'Screenplay.pdf' ),
			array(),
			false,
			'preserve'
		);
		$result   = $page->run_keepalive( $snapshot['id'] );
		$session  = $this->store->find( ImportSessionId::from_string( $snapshot['id'] ) );
		$document = $this->store->list_recent_prepared_documents( $session->get_id(), 1 )[0];

		$this->assertSame( ImportSession::STATUS_DONE, $result['session']['status'] );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertSame( 'pdf', $document->get_format() );
		$this->assertStringContainsString( 'Scene 1', $document->get_block_markup() );
		$this->assertStringContainsString( 'Scene ' . $count, $document->get_block_markup() );
		$this->assertFalse( $result['session']['dashboard']['needs_keepalive'] );
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

		$this->assertStringContainsString( 'source item needs attention', $details['dashboard']['current_action'] );
		$this->assertStringContainsString( 'source item failed', $details['dashboard']['attention_message'] );
		$this->assertFalse( $details['dashboard']['needs_keepalive'] );
		$this->assertSame( 'blocked', $details['dashboard']['checklist'][0]['state'] );
		$this->assertSame( array( 'blocked', 'pending', 'pending', 'pending', 'pending', 'pending' ), array_column( $details['dashboard']['checklist'], 'state' ) );
		$this->assertSame( 'broken.pdf: PDF text extraction produced no importable text.', $details['dashboard']['checklist'][0]['note'] );
		$this->assertFalse( $this->invoke_private_admin_method( $page, 'is_active_admin_session', array( $details ) ) );
		$this->assertSame( 'PDF text extraction produced no importable text.', $details['source_items']['recent'][0]['metadata']['error'] );
		$this->assertStringNotContainsString( 'Checking remaining importer work', $details['dashboard']['current_action'] );
	}

	/**
	 * Pending URL choices block the next stage without marking later stages.
	 *
	 * @return void
	 */
	public function test_status_snapshot_keeps_checklist_sequential_for_url_choices() {
		$page     = $this->create_page();
		$snapshot = $page->create_import_session( '/tmp/book.md' );
		$session  = $this->store->find( ImportSessionId::from_string( $snapshot['id'] ) );

		$this->store->save( $session->mark_running() );
		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				'local:book.md',
				null,
				'/tmp/book.md',
				'book.md',
				ImportSourceItem::TYPE_FILE
			)->with_status( ImportSourceItem::STATUS_IMPORTED )
		);
		$this->store->save_prepared_document(
			new ImportPreparedDocument(
				$session->get_id(),
				'local:book.md',
				'markdown',
				'Book',
				'<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
				1,
				'hash-book'
			)
		);
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Confirm first-party domains before URL rewriting.',
				array( 'domains' => array( 'example.com' ) )
			)
		);

		$details = $page->get_status_snapshot( $session->get_id() );

		$this->assertSame( 'Choose URL treatment to continue.', $details['dashboard']['current_action'] );
		$this->assertSame( array( 'done', 'done', 'blocked', 'pending', 'pending', 'pending' ), array_column( $details['dashboard']['checklist'], 'state' ) );
		$this->assertSame( 'URL treatment', $details['dashboard']['checklist'][2]['label'] );
		$this->assertSame( 'url_treatment', $details['dashboard']['checklist'][2]['key'] );
		$this->assertTrue( $this->invoke_private_admin_method( $page, 'is_active_admin_session', array( $details ) ) );
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
		// One combined affordance now: a single Source card with text-link "Choose file" / "Choose folder".
		$this->assertStringContainsString( 'Choose file', $source );
		$this->assertStringContainsString( 'Choose folder', $source );
		$this->assertStringContainsString( 'universal-importer-file-preview', $source );
		$this->assertStringContainsString( "dropzone.addEventListener('drop'", $source );
		$this->assertStringContainsString( 'readDirectoryEntries', $source );
		$this->assertStringContainsString( 'webkitGetAsEntry', $source );
		// The dual-card layout, numbered badges, OR divider, shortcut chips, and verbose dropzone copy are all gone.
		$this->assertStringNotContainsString( 'Upload a file or folder', $source );
		$this->assertStringNotContainsString( 'Drop a file or folder anywhere on this card', $source );
		$this->assertStringNotContainsString( 'What should I import?', $source );
		$this->assertStringNotContainsString( 'Two ways in. Use one.', $source );
		$this->assertStringNotContainsString( 'universal-importer-memo-num', $source );
		$this->assertStringNotContainsString( 'universal-importer-source-shortcut', $source );
		$this->assertStringNotContainsString( 'universal-importer-divider', $source );
		$this->assertStringNotContainsString( 'data-source-placeholder', $source );
		// Shortcut chip labels are gone (the chips themselves have been removed).
		$this->assertStringNotContainsString( '>GitHub repo<', $source );
		$this->assertStringNotContainsString( 'Feed or OPML', $source );
		$this->assertStringNotContainsString( 'WXR export', $source );
		// Clear selection is still rendered, but lives inside a hidden wrapper until files are selected.
		$this->assertStringContainsString( 'id="universal-importer-clear-files"', $source );
		$this->assertStringContainsString( 'Clear selection', $source );
		$this->assertStringContainsString( 'id="universal-importer-upload-actions" hidden', $source );
		$this->assertStringContainsString( 'countFilesByExtension(browserFiles, \'.pdf\')', $source );
		$this->assertStringContainsString( 'type="url"', $source );
		// The terse "accepts" line replaces the old shortcut/explainer chrome.
		$this->assertStringContainsString( 'universal-importer-accepts', $source );
		$this->assertStringContainsString( 'GitHub repos · feeds · sitemaps', $source );
		$this->assertStringContainsString( 'Selected file tree', $source );
		$this->assertStringContainsString( 'role="tree"', $source );
		$this->assertStringContainsString( "filePreview.addEventListener('keydown'", $source );
		$this->assertStringContainsString( "event.key === 'ArrowDown'", $source );
		$this->assertStringContainsString( 'data-tree-kind', $source );
		$this->assertStringContainsString( 'response.text()', $source );
		$this->assertStringContainsString( 'function nonJsonResponseMessage(response, text)', $source );
		$this->assertStringContainsString( 'Importer request returned a non-JSON response.', $source );
		$this->assertStringContainsString( 'URL treatment', $source );
		$this->assertStringContainsString( 'Ask when old URLs are found', $source );
		$this->assertStringContainsString( 'Keep URLs unchanged', $source );
		$this->assertStringContainsString( 'Rewrite listed domains', $source );
		$this->assertStringContainsString( 'Old site domains', $source );
		$this->assertStringContainsString( 'url_rewrite_mode', $source );
		$this->assertStringContainsString( 'Import as drafts', $source );
		$this->assertStringContainsString( 'import_as_drafts', $source );
		$this->assertStringContainsString( 'Publishes pages', $source );
		$this->assertStringContainsString( 'View imported content', $source );
		$this->assertStringContainsString( 'universal_importer_session_id', $source );
		$this->assertStringContainsString( 'universal-importer-progressbar', $source );
		$this->assertStringContainsString( 'universal-importer-current-action', $source );
		$this->assertStringContainsString( 'universal-importer-stage-title', $source );
		$this->assertStringContainsString( 'universal-importer-step-state', $source );
		$this->assertStringContainsString( 'function checklistStateLabel(state)', $source );
		$this->assertStringContainsString( 'function syncPrimaryView(session)', $source );
		$this->assertStringContainsString( 'function isImportLocked(session)', $source );
		$this->assertStringContainsString( 'function canStartAnotherImport(session)', $source );
		$this->assertStringContainsString( 'function renderStageDecision(session, stageKey)', $source );
		$this->assertStringContainsString( 'universal-importer-stage-decision', $source );
		$this->assertStringContainsString( 'universal-importer-start-over', $source );
		$this->assertStringContainsString( 'universal-importer-card is-importing', $source );
		$this->assertStringContainsString( 'universal-importer-start-form" class="universal-importer-start', $source );
		$this->assertStringContainsString( 'data-url-choice="none"', $source );
		$this->assertStringContainsString( "'primary_session_id' =>", $source );
		$this->assertStringContainsString( 'wp_json_encode( $config )', $source );
		$this->assertStringContainsString( 'function sessionNeedsKeepalive(session)', $source );
		$this->assertStringContainsString( 'function reattachActiveSession()', $source );
		$this->assertStringContainsString( 'reattachActiveSession();', $source );
		$this->assertStringContainsString( 'universal-importer-github-picker', $source );
		// Inline GitHub path picker on the Source card: "Path: ... [change]".
		$this->assertStringContainsString( 'universal-importer-github-picker-label', $source );
		$this->assertStringContainsString( 'id="universal-importer-github-browse"', $source );
		$this->assertStringContainsString( 'universal-importer-github-modal', $source );
		$this->assertStringContainsString( 'Choose GitHub directory', $source );
		$this->assertStringContainsString( 'Filter directories', $source );
		$this->assertStringContainsString( 'universal-importer-github-search', $source );
		$this->assertStringContainsString( 'universal-importer-github-use', $source );
		$this->assertStringContainsString( 'universal-importer-github-tree', $source );
		$this->assertStringContainsString( 'GitHub repository directories', $source );
		$this->assertStringContainsString( 'function loadGithubDirectories()', $source );
		$this->assertStringContainsString( 'function renderGithubDirectories(data)', $source );
		$this->assertStringContainsString( 'function renderGithubDirectoryRows()', $source );
		$this->assertStringContainsString( 'function chooseGithubDirectory(button)', $source );
		$this->assertStringContainsString( 'function applyGithubDirectorySelection()', $source );
		$this->assertStringContainsString( 'function moveGithubDirectoryFocus(current, offset)', $source );
		$this->assertStringContainsString( "event.key === 'ArrowDown'", $source );
		$this->assertStringContainsString( "event.key === 'Escape'", $source );
		$this->assertStringContainsString( "event.key === 'Home'", $source );
		$this->assertStringContainsString( "event.key === 'End'", $source );
		$this->assertStringContainsString( 'AJAX_GITHUB_DIRS', $source );
		$this->assertStringContainsString( 'var keepaliveInFlight = false', $source );
		$this->assertStringContainsString( 'if (!activeSessionId || keepaliveInFlight)', $source );
		// Transcript / memo column structure (option-30 design).
		$this->assertStringContainsString( 'universal-importer-convo', $source );
		$this->assertStringContainsString( 'universal-importer-turn', $source );
		$this->assertStringContainsString( 'universal-importer-memo', $source );
		// Single combined source affordance — one terse prompt instead of dual cards with explainers.
		$this->assertStringContainsString( 'Paste a URL or drop a file', $source );
		$this->assertStringContainsString( 'Past imports', $source );
		// Inline inferred-type chip with a Change override popover lives on the Source card itself.
		$this->assertStringContainsString( 'id="universal-importer-inferred"', $source );
		$this->assertStringContainsString( 'id="universal-importer-inferred-chip"', $source );
		$this->assertStringContainsString( 'id="universal-importer-inferred-change"', $source );
		$this->assertStringContainsString( 'id="universal-importer-inferred-popover"', $source );
		$this->assertStringContainsString( 'function refreshInferredType()', $source );
		// Picker modal renders a shimmer skeleton while directories are being fetched.
		$this->assertStringContainsString( 'universal-importer-github-skeleton', $source );
		$this->assertStringContainsString( 'universal-importer-github-skeleton-row', $source );
		$this->assertStringContainsString( '@keyframes universal-importer-shimmer', $source );
		$this->assertStringContainsString( 'function setGithubSkeletonVisible(visible)', $source );
		// Configure step no longer carries the "Configure the run." headline + "Defaults are sensible." lede.
		$this->assertStringNotContainsString( 'Configure the run.', $source );
		$this->assertStringNotContainsString( 'Defaults are sensible.', $source );
		// Source capture is URL or upload only — no typed server paths in the UI.
		$this->assertStringNotContainsString( 'Server path', $source );
		$this->assertStringNotContainsString( '/path/to/export', $source );
		$this->assertStringNotContainsString( 'URL or server path', $source );
		// Drag handler only reacts when the drag has Files (so dragging text/URLs doesn't light up the card).
		$this->assertStringContainsString( "types.indexOf('Files')", $source );
		// Progressive turn flow: only the source turn lives in the initial DOM.
		$this->assertStringContainsString( 'id="universal-importer-turn-source" data-turn-key="source"', $source );
		// Configure and Confirm are JS templates, not rendered upfront. Classify step has been removed.
		$this->assertStringNotContainsString( '<template id="universal-importer-template-classify">', $source );
		$this->assertStringContainsString( '<template id="universal-importer-template-configure">', $source );
		$this->assertStringContainsString( '<template id="universal-importer-template-confirm">', $source );
		// Next button on the source memo; the initial Clear button has been removed.
		$this->assertStringContainsString( 'id="universal-importer-source-continue"', $source );
		$this->assertStringNotContainsString( 'id="universal-importer-source-clear"', $source );
		// State-machine hooks: locked summary bubbles render without Edit links — Back buttons are now the way back.
		$this->assertStringContainsString( 'universal-importer-turn.is-past', $source );
		$this->assertStringNotContainsString( 'data-edit-key', $source );
		$this->assertStringContainsString( 'function jumpBack(key)', $source );
		$this->assertStringContainsString( 'function dropTurnsAfter(key)', $source );
		$this->assertStringNotContainsString( 'function renderClassifyTurn()', $source );
		// Every non-Source step carries a Back button.
		$this->assertStringContainsString( 'data-action="back"', $source );
		$this->assertStringContainsString( 'function renderConfigureTurn()', $source );
		$this->assertStringContainsString( 'function renderConfirmTurn()', $source );
		$this->assertStringContainsString( 'function inferSourceType()', $source );
		// Classify step is gone, but the inferred-type chip ships with an inline override popover.
		// `Server path` was never a valid public source and stays excluded.
		$this->assertStringContainsString( 'data-type="GitHub repository"', $source );
		$this->assertStringContainsString( 'data-type="WordPress site URL"', $source );
		$this->assertStringNotContainsString( 'data-type="Server path"', $source );
		// URL input is a real type="url" field (no typed-path UI).
		$this->assertStringContainsString( 'type="url" id="universal-importer-source"', $source );
		// Hidden form state inputs carry the configure choices to submit.
		$this->assertStringContainsString( 'id="universal-importer-state-url-mode"', $source );
		$this->assertStringContainsString( 'id="universal-importer-state-drafts"', $source );
		$this->assertStringContainsString( 'id="universal-importer-state-domains"', $source );
		// Dry-run option has been intentionally removed from the admin UI.
		$this->assertStringNotContainsString( 'id="universal-importer-state-dry"', $source );
		$this->assertStringNotContainsString( 'data-toggle="dry"', $source );
		// "Edit anything above" has been removed from the Confirm turn.
		$this->assertStringNotContainsString( 'Edit anything above', $source );
	}

	/**
	 * GitHub directory browsing resolves HEAD via the Git plumbing fetcher and never hits the GitHub REST API.
	 *
	 * @return void
	 */
	public function test_list_github_directories_returns_default_branch_tree_picker() {
		$content_fetcher = new FakeRemoteContentFetcher();
		$git_fetcher     = new FakeGitRepositoryFetcher();
		$git_fetcher->add_directory_listing(
			'HEAD',
			'',
			'main',
			array( 'docs', 'docs/api' )
		);

		$result = $this->create_page( null, null, $content_fetcher, $git_fetcher )
			->list_github_directories( 'https://github.com/example/repository' );

		$this->assertSame( 'main', $result['ref'] );
		$this->assertSame( '', $result['selected_path'] );
		$this->assertSame( 'https://github.com/example/repository/tree/main', $result['selected_source_url'] );
		$this->assertSame( array( '', 'docs', 'docs/api' ), array_column( $result['directories'], 'path' ) );
		$this->assertSame( 'https://github.com/example/repository/tree/main/docs/api', $result['directories'][2]['source_url'] );

		// The directory picker must NOT issue any GitHub REST API requests.
		$this->assertSame( array(), $content_fetcher->get_requested_urls() );

		// One Git plumbing request was made, with the HEAD ref the parser produced.
		$requests = $git_fetcher->get_directory_requests();
		$this->assertCount( 1, $requests );
		$this->assertSame( 'example', $requests[0]['owner'] );
		$this->assertSame( 'repository', $requests[0]['name'] );
		$this->assertSame( 'HEAD', $requests[0]['ref'] );
		$this->assertSame( '', $requests[0]['source_path'] );
	}

	/**
	 * GitHub directory browsing falls back to branch + path candidates when the first ref does not resolve.
	 *
	 * @return void
	 */
	public function test_list_github_directories_falls_back_to_branch_plus_path() {
		$content_fetcher = new FakeRemoteContentFetcher();
		$git_fetcher     = new FakeGitRepositoryFetcher();

		// trunk/docs is not a real ref — the Git plumbing fails to resolve it.
		$git_fetcher->fail_directory_listing( 'trunk/docs', '', 'php-toolkit Git directory listing could not resolve a branch on the remote.' );

		// The branch + path fallback succeeds.
		$git_fetcher->add_directory_listing(
			'trunk',
			'docs',
			'trunk',
			array( 'docs', 'docs/reference' )
		);

		$result = $this->create_page( null, null, $content_fetcher, $git_fetcher )
			->list_github_directories( 'https://github.com/WordPress/gutenberg/tree/trunk/docs' );

		$this->assertSame( 'trunk', $result['ref'] );
		$this->assertSame( 'trunk/docs', $result['requested_ref'] );
		$this->assertSame( 'docs', $result['selected_path'] );
		$this->assertSame( 'https://github.com/WordPress/gutenberg/tree/trunk/docs', $result['selected_source_url'] );
		$this->assertSame( array( '', 'docs', 'docs/reference' ), array_column( $result['directories'], 'path' ) );

		// No GitHub REST API hits.
		$this->assertSame( array(), $content_fetcher->get_requested_urls() );

		// Both candidates were tried via the Git fetcher.
		$requests = $git_fetcher->get_directory_requests();
		$this->assertCount( 2, $requests );
		$this->assertSame( 'trunk/docs', $requests[0]['ref'] );
		$this->assertSame( '', $requests[0]['source_path'] );
		$this->assertSame( 'trunk', $requests[1]['ref'] );
		$this->assertSame( 'docs', $requests[1]['source_path'] );
	}

	/**
	 * Browsing GitHub directories never constructs an api.github.com URL via the remote content fetcher.
	 *
	 * @return void
	 */
	public function test_list_github_directories_never_hits_api_github_com() {
		$content_fetcher = new FakeRemoteContentFetcher();
		$git_fetcher     = new FakeGitRepositoryFetcher();
		$git_fetcher->add_directory_listing( 'HEAD', '', 'main', array( 'docs' ) );

		$this->create_page( null, null, $content_fetcher, $git_fetcher )
			->list_github_directories( 'https://github.com/example/repository' );

		foreach ( $content_fetcher->get_requested_urls() as $url ) {
			$this->assertStringNotContainsString( 'api.github.com', $url );
		}
		$this->assertCount( 0, $content_fetcher->get_requested_urls() );
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
	 * Pending GitHub imports explain repository discovery before the first worker response returns.
	 *
	 * @return void
	 */
	public function test_status_snapshot_explains_pending_github_repository_discovery() {
		$page     = $this->create_page();
		$snapshot = $page->create_import_session( 'https://github.com/WordPress/gutenberg/tree/trunk/docs' );

		$this->assertSame( ImportSession::STATUS_PENDING, $snapshot['status'] );
		$this->assertSame( 0, $snapshot['source_items']['total'] );
		$this->assertSame( 0, $snapshot['dashboard']['summary']['total'] );
		$this->assertTrue( $snapshot['dashboard']['indeterminate'] );
		$this->assertSame( 'Starting', $snapshot['dashboard']['status_label'] );
		$this->assertSame( 'Queued to fetch GitHub repository files.', $snapshot['dashboard']['current_action'] );
		$this->assertSame( 'File count appears after GitHub repository discovery.', $snapshot['dashboard']['progress_note'] );
		$this->assertTrue( $snapshot['dashboard']['needs_keepalive'] );
		$this->assertSame( 'active', $snapshot['dashboard']['checklist'][0]['state'] );
		$this->assertSame( 'Waiting to fetch repository files from GitHub.', $snapshot['dashboard']['checklist'][0]['detail'] );
		$this->assertSame( 'github.fetch_queued', $snapshot['recent_events'][0]['type'] );
		$this->assertSame( 'trunk/docs', $snapshot['recent_events'][0]['context']['github_ref'] );
		$this->assertSame( '', $snapshot['recent_events'][0]['context']['github_source_path'] );
		$this->assertStringNotContainsString( 'Queued.', $snapshot['dashboard']['current_action'] );
	}

	/**
	 * GitHub sparse checkout progress is shown without counting internal state as a source file.
	 *
	 * @return void
	 */
	public function test_status_snapshot_exposes_active_github_sparse_checkout() {
		$page     = $this->create_page();
		$snapshot = $page->create_import_session( 'https://github.com/WordPress/gutenberg/tree/trunk/docs/explanations/architecture' );
		$session  = $this->store->find( ImportSessionId::from_string( $snapshot['id'] ) );
		$session  = $session->mark_running();
		$this->store->save( $session );

		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				'github-git:state',
				null,
				$session->get_source(),
				$session->get_source(),
				ImportSourceItem::TYPE_DIRECTORY,
				array(
					'github_git_status'        => 'pulling',
					'github_git_status_detail' => 'Fetching repository files through sparse Git checkout.',
					'github_git_started_at'    => '2026-05-20T11:00:00+00:00',
					'github_ref'               => 'trunk',
					'github_source_path'       => 'docs/explanations/architecture',
				)
			)->with_status( ImportSourceItem::STATUS_PROCESSING )
		);
		$details = $page->get_status_snapshot( $session->get_id() );

		$this->assertSame( 0, $details['source_items']['total'] );
		$this->assertSame( 0, $details['source_items']['statuses'][ ImportSourceItem::STATUS_PROCESSING ] );
		$this->assertTrue( $details['github_git']['active'] );
		$this->assertTrue( $details['dashboard']['indeterminate'] );
		$this->assertSame( 'Fetching', $details['dashboard']['status_label'] );
		$this->assertSame( 'Fetching repository files; file count appears after discovery.', $details['dashboard']['progress_note'] );
		$this->assertSame( 'Fetching repository files with sparse Git.', $details['dashboard']['current_action'] );
		$this->assertSame( 'Fetching repository files with sparse Git.', $details['dashboard']['checklist'][0]['detail'] );
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
	 * @param callable|null                 $runner_factory  Optional runner factory.
	 * @param ImportCacheDirectory|null     $cache_directory Optional upload cache directory.
	 * @param FakeRemoteContentFetcher|null $content_fetcher Optional remote content fetcher.
	 * @param FakeGitRepositoryFetcher|null $git_fetcher     Optional Git repository fetcher (admin directory picker).
	 * @return ImportAdminPage
	 */
	private function create_page( callable $runner_factory = null, ImportCacheDirectory $cache_directory = null, FakeRemoteContentFetcher $content_fetcher = null, FakeGitRepositoryFetcher $git_fetcher = null ) {
		return new ImportAdminPage(
			$this->store,
			function ( ImportSessionId $session_id ) {
				$this->scheduled_sessions[] = $session_id->to_string();
			},
			null === $runner_factory ? function ( WordPressImportSessionStore $store ) {
				return new FakeAdminRunner( $store );
			} : $runner_factory,
			$cache_directory,
			$content_fetcher,
			$git_fetcher
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
	 * Invokes a private ImportAdminPage method for focused dashboard state assertions.
	 *
	 * @param ImportAdminPage  $page      Admin page under test.
	 * @param string           $method    Method name.
	 * @param array<int,mixed> $arguments Method arguments.
	 * @return mixed
	 */
	private function invoke_private_admin_method( ImportAdminPage $page, $method, array $arguments = array() ) {
		$reflection = new \ReflectionMethod( $page, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $page, $arguments );
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
	 * Creates a minimal PDF fixture body with many text streams.
	 *
	 * @param int $count Number of content streams.
	 * @return string
	 */
	private function temporary_multi_stream_pdf_contents( $count ) {
		$count        = max( 1, (int) $count );
		$objects      = array(
			"1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
			"2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n",
		);
		$content_refs = array();

		for ( $index = 0; $index < $count; ++$index ) {
			$object_id      = 4 + $index;
			$content_refs[] = $object_id . ' 0 R';
			$scene          = $index + 1;
			$stream         = "BT\n/F1 12 Tf\n72 720 Td\n(Scene " . $scene . ") Tj\n0 -14 Td\n(Action line " . $scene . ".) Tj\nET\n";
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

		return "%PDF-1.4\n" . implode( '', $objects ) . "%%EOF\n";
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
