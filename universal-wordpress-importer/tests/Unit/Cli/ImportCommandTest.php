<?php
/**
 * Tests for the WP-CLI import command surface.
 *
 * @package UniversalImporter\Tests\Unit\Cli
 */

namespace UniversalImporter\Tests\Unit\Cli;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Unit fixtures use isolated temporary files without WordPress loaded.

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UniversalImporter\Cli\ImportCommand;
use UniversalImporter\Import\ImportCacheDirectory;
use UniversalImporter\Import\ImportDecision;
use UniversalImporter\Import\ImportIdempotencyRecord;
use UniversalImporter\Import\ImportMediaReference;
use UniversalImporter\Import\ImportPreparedDocument;
use UniversalImporter\Import\ImportProgressEvent;
use UniversalImporter\Import\ImportRelationshipMappingDecision;
use UniversalImporter\Import\ImportRunner;
use UniversalImporter\Import\ImportRunnerControls;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportSessionId;
use UniversalImporter\Import\ImportSourceItem;
use UniversalImporter\Import\SourceItemDocumentProcessor;
use UniversalImporter\Import\WordPressImportSessionSchema;
use UniversalImporter\Import\WordPressImportSessionStore;
use UniversalImporter\Tests\Unit\Import\FakeCommentGateway;
use UniversalImporter\Tests\Unit\Import\FakeMediaGateway;
use UniversalImporter\Tests\Unit\Import\FakePostGateway;
use UniversalImporter\Tests\Unit\Import\FakeRemoteArchiveFetcher;
use UniversalImporter\Tests\Unit\Import\FakeRemoteContentFetcher;
use UniversalImporter\Tests\Unit\Import\FakeWpdb;

/**
 * Covers durable WP-CLI session operations without a full WordPress runtime.
 */
final class ImportCommandTest extends TestCase {
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
	 * Fake WP-CLI facade.
	 *
	 * @var FakeCli
	 */
	private $cli;

	/**
	 * Temporary paths to remove after tests.
	 *
	 * @var array<int,string>
	 */
	private $temporary_paths = array();

	/**
	 * Sets up command dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wpdb               = new FakeWpdb();
		$this->store              = new WordPressImportSessionStore( $this->wpdb );
		$this->scheduled_sessions = array();
		$this->cli                = new FakeCli();
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
	 * Import creates a durable pending session and schedules continuation.
	 *
	 * @return void
	 */
	public function test_import_creates_session_records_decision_and_schedules_continuation() {
		$command = $this->create_command();

		$command->import(
			array( 'https://example.com/wp-json' ),
			array(
				'confirm-first-party-domains' => 'example.com, www.example.com, example.com',
				'dry-run'                     => true,
			)
		);

		$rows = $this->wpdb->get_table_rows( WordPressImportSessionSchema::get_table_names_for_prefix( $this->wpdb->prefix )['sessions'] );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'https://example.com/wp-json', $rows[0]['source'] );
		$this->assertSame( 1, $rows[0]['dry_run'] );
		$this->assertSame( ImportSession::STATUS_PENDING, $rows[0]['status'] );
		$this->assertSame( array( $rows[0]['id'] ), $this->scheduled_sessions );
		$this->assertStringContainsString( 'Created import session ' . $rows[0]['id'], implode( "\n", $this->cli->messages ) );

		$session  = $this->store->find( ImportSessionId::from_string( $rows[0]['id'] ) );
		$decision = $this->store->find_decision( $session->get_id(), 'confirm-first-party-domains' );

		$this->assertTrue( $session->is_dry_run() );
		$this->assertNotNull( $decision );
		$this->assertSame( ImportDecision::STATUS_RESOLVED, $decision->get_status() );
		$this->assertSame(
			array( 'confirmed_domains' => array( 'example.com', 'www.example.com' ) ),
			$decision->get_answer()
		);
		$this->assertSame( 'session.created', $this->store->list_events( $session->get_id(), 1 )[0]->get_type() );
	}

	/**
	 * Status prints a useful snapshot with pending decisions and recent events.
	 *
	 * @return void
	 */
	public function test_status_renders_snapshot_decisions_and_events() {
		$session = ImportSession::start_for_source( 'local://book.md', true );
		$this->store->save( $session );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Confirm first-party domains before URL rewriting.',
				array( 'domains' => array( 'example.test' ) )
			)
		);

		$command = $this->create_command();
		$command->status( array( $session->get_id()->to_string() ), array() );

		$output = implode( "\n", $this->cli->messages );

		$this->assertStringContainsString( 'Session: ' . $session->get_id()->to_string(), $output );
		$this->assertStringContainsString( 'Status: pending', $output );
		$this->assertStringContainsString( 'Dry run: yes', $output );
		$this->assertStringContainsString( 'Pending decisions:', $output );
		$this->assertStringContainsString( 'confirm-first-party-domains', $output );
	}

	/**
	 * Status prints actionable REST relationship warnings.
	 *
	 * @return void
	 */
	public function test_status_renders_relationship_warnings() {
		$session = ImportSession::start_for_source( 'https://example.com/wp-json/' );
		$this->store->save( $session );
		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_WARNING,
				ImportRelationshipMappingDecision::WARNING_EVENT,
				'Remote REST author or taxonomy relationships need operator review after draft creation.',
				array(
					'post_id'         => 123,
					'source_item_key' => 'remote:post:7',
					'relationships'   => array(
						'author' => array(
							'status' => 'unmapped',
						),
						'terms'  => array(
							'genre' => array(
								'status' => 'taxonomy_missing',
							),
						),
					),
				)
			)
		);
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				ImportRelationshipMappingDecision::decision_key( 'remote:post:7' ),
				'Map the remote REST author and taxonomy relationships for imported draft post 123.',
				array(
					'answer_template' => array(
						'author' => array(
							'local_user_id' => 0,
						),
					),
				)
			)
		);

		$command = $this->create_command();
		$command->status( array( $session->get_id()->to_string() ), array() );

		$output = implode( "\n", $this->cli->messages );

		$this->assertStringContainsString( 'Relationship warnings:', $output );
		$this->assertStringContainsString( 'post 123 from remote:post:7: author unmapped; genre taxonomy_missing', $output );
		$this->assertStringContainsString( ImportRelationshipMappingDecision::decision_key( 'remote:post:7' ), $output );
		$this->assertStringContainsString( 'answer template: {"author":{"local_user_id":0}}', $output );
	}

	/**
	 * Status prints active remote rate-limit backoff diagnostics.
	 *
	 * @return void
	 */
	public function test_status_renders_remote_rate_limit_backoff() {
		$session    = ImportSession::start_for_source( 'https://source.example.test/wp-json/' );
		$retry_time = time() + 120;
		$this->store->save( $session );
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
						'next_retry_at'       => gmdate( 'c', $retry_time ),
						'next_retry_unix'     => $retry_time,
					),
				)
			)->with_status( ImportSourceItem::STATUS_PROCESSING )
		);

		$command = $this->create_command();
		$command->status( array( $session->get_id()->to_string() ), array() );

		$output = implode( "\n", $this->cli->messages );

		$this->assertStringContainsString( 'Remote backoff:', $output );
		$this->assertStringContainsString( 'HTTP 429, retry in', $output );
		$this->assertStringContainsString( 'Retry-After: 120', $output );
		$this->assertStringContainsString( '/wp/v2/posts?page=2', $output );
	}

	/**
	 * Status prints staged EPUB table-of-contents metadata.
	 *
	 * @return void
	 */
	public function test_status_renders_epub_toc_summaries() {
		$session = ImportSession::start_for_source( '/tmp/book.epub' );
		$this->store->save( $session );
		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				'local:book.epub',
				null,
				'/tmp/book.epub',
				'book.epub',
				ImportSourceItem::TYPE_FILE,
				array(
					'document_format'  => 'epub',
					'epub_title'       => 'Operator Book',
					'epub_toc_source'  => 'nav',
					'epub_toc_entry'   => 'OEBPS/nav.xhtml',
					'epub_toc_count'   => 2,
					'epub_toc_entries' => array(
						array(
							'label'           => 'Start',
							'target_path'     => 'OEBPS/start.xhtml',
							'target_fragment' => '',
						),
						array(
							'label'           => 'Part Two',
							'target_path'     => 'OEBPS/part-two.xhtml',
							'target_fragment' => 'top',
						),
					),
				)
			)->with_status( ImportSourceItem::STATUS_IMPORTED )
		);

		$command = $this->create_command();
		$command->status( array( $session->get_id()->to_string() ), array() );

		$output = implode( "\n", $this->cli->messages );

		$this->assertStringContainsString( 'EPUB TOCs:', $output );
		$this->assertStringContainsString( 'Operator Book: 2 entries from nav at OEBPS/nav.xhtml', $output );
		$this->assertStringContainsString( 'Part Two -> OEBPS/part-two.xhtml#top', $output );
	}

	/**
	 * Status prints PDF text extraction and OCR diagnostics.
	 *
	 * @return void
	 */
	public function test_status_renders_pdf_ocr_summaries() {
		$session = ImportSession::start_for_source( '/tmp/pdfs' );
		$this->store->save( $session );
		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				'local:scan.pdf',
				null,
				'/tmp/pdfs/scan.pdf',
				'scan.pdf',
				ImportSourceItem::TYPE_FILE,
				array(
					'document_format'         => 'pdf',
					'title'                   => 'Scanned Contract',
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
				'local:image-only.pdf',
				null,
				'/tmp/pdfs/image-only.pdf',
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

		$command = $this->create_command();
		$command->status( array( $session->get_id()->to_string() ), array() );

		$output = implode( "\n", $this->cli->messages );

		$this->assertStringContainsString( 'PDF/OCR:', $output );
		$this->assertStringContainsString( 'Scanned Contract: imported, ocr / succeeded', $output );
		$this->assertStringContainsString( 'table/vector layout signals', $output );
		$this->assertStringContainsString( 'embedded image references', $output );
		$this->assertStringContainsString( 'image-only.pdf: failed, native / not_configured', $output );
		$this->assertStringContainsString( 'PDF text extraction produced no importable text.', $output );
		$this->assertStringContainsString( 'hint: Set UNIVERSAL_IMPORTER_PDF_OCR_COMMAND to enable OCR.', $output );
	}

	/**
	 * Resume moves paused sessions back to running and schedules another tick.
	 *
	 * @return void
	 */
	public function test_resume_requeues_paused_session() {
		$session = ImportSession::start_for_source( 'local://book.md' )->mark_paused();
		$this->store->save( $session );

		$command = $this->create_command();
		$command->resume( array( $session->get_id()->to_string() ), array() );

		$restored = $this->store->find( $session->get_id() );

		$this->assertSame( ImportSession::STATUS_RUNNING, $restored->get_status() );
		$this->assertSame( array( $session->get_id()->to_string() ), $this->scheduled_sessions );
		$this->assertSame( 'session.resume_requested', $this->store->list_events( $session->get_id(), 1 )[0]->get_type() );
	}

	/**
	 * Abort stores a terminal aborted state and records an operator event.
	 *
	 * @return void
	 */
	public function test_abort_marks_session_aborted() {
		$session = ImportSession::start_for_source( 'local://book.md' );
		$this->store->save( $session );

		$command = $this->create_command();
		$command->abort( array( $session->get_id()->to_string() ), array() );

		$restored = $this->store->find( $session->get_id() );

		$this->assertSame( ImportSession::STATUS_ABORTED, $restored->get_status() );
		$this->assertSame( 'session.aborted', $this->store->list_events( $session->get_id(), 1 )[0]->get_type() );
	}

	/**
	 * Decide resolves a pending first-party domain decision and schedules continuation.
	 *
	 * @return void
	 */
	public function test_decide_resolves_confirmed_domain_decision() {
		$session = ImportSession::start_for_source( 'https://example.com/wp-json' )->mark_paused();
		$this->store->save( $session );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Confirm first-party domains before URL rewriting.',
				array( 'domains' => array( 'example.com', 'www.example.com' ) )
			)
		);

		$command = $this->create_command();
		$command->decide(
			array( $session->get_id()->to_string(), 'confirm-first-party-domains' ),
			array( 'confirmed-domains' => 'example.com, www.example.com, example.com' )
		);

		$decision = $this->store->find_decision( $session->get_id(), 'confirm-first-party-domains' );
		$output   = implode( "\n", $this->cli->messages );

		$this->assertSame( ImportDecision::STATUS_RESOLVED, $decision->get_status() );
		$this->assertSame(
			array( 'confirmed_domains' => array( 'example.com', 'www.example.com' ) ),
			$decision->get_answer()
		);
		$this->assertSame( array( $session->get_id()->to_string() ), $this->scheduled_sessions );
		$this->assertStringContainsString( 'Resolved decision confirm-first-party-domains', $output );
		$this->assertSame( 'decision.resolved', $this->store->list_events( $session->get_id(), 1 )[0]->get_type() );
	}

	/**
	 * Decide accepts a generic JSON object for future decision types.
	 *
	 * @return void
	 */
	public function test_decide_resolves_generic_json_answer() {
		$session = ImportSession::start_for_source( 'local://book.md' );
		$this->store->save( $session );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'choose-author',
				'Choose the author mapping.',
				array( 'candidates' => array( 42 ) )
			)
		);

		$command = $this->create_command();
		$command->decide(
			array( $session->get_id()->to_string(), 'choose-author' ),
			array( 'answer' => '{"user_id":42}' )
		);

		$decision = $this->store->find_decision( $session->get_id(), 'choose-author' );

		$this->assertSame( ImportDecision::STATUS_RESOLVED, $decision->get_status() );
		$this->assertSame( array( 'user_id' => 42 ), $decision->get_answer() );
	}

	/**
	 * Decide reports missing pending decisions clearly.
	 *
	 * @return void
	 */
	public function test_decide_errors_when_decision_is_missing() {
		$session = ImportSession::start_for_source( 'local://book.md' );
		$this->store->save( $session );

		$command = $this->create_command();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Import decision not found: choose-author' );

		$command->decide(
			array( $session->get_id()->to_string(), 'choose-author' ),
			array( 'answer' => '{"user_id":42}' )
		);
	}

	/**
	 * Tick runs the shared continuation runner from WP-CLI.
	 *
	 * @return void
	 */
	public function test_tick_runs_continuation_for_session() {
		$session = ImportSession::start_for_source( 'local://book.md' );
		$this->store->save( $session );

		$command = $this->create_command();
		$command->tick( array( $session->get_id()->to_string() ), array() );

		$restored = $this->store->find( $session->get_id() );
		$output   = implode( "\n", $this->cli->messages );

		$this->assertSame( ImportSession::STATUS_RUNNING, $restored->get_status() );
		$this->assertStringContainsString( '1 processed, 0 locked, 0 skipped, 0 errors', $output );
		$this->assertSame( 'source.discovery_progress', $this->store->list_events( $session->get_id(), 1 )[0]->get_type() );
	}

	/**
	 * Tick can complete a local filesystem dry-run session and status renders it.
	 *
	 * @return void
	 */
	public function test_tick_completes_dry_run_local_filesystem_session_and_status_renders_done() {
		$source_file = $this->temporary_file( 'dry-run.md', "# Dry run\n\nCLI body." );
		$session     = ImportSession::start_for_source( $source_file, true );
		$this->store->save( $session );

		$command = $this->create_command();
		$command->tick( array( $session->get_id()->to_string() ), array() );

		$restored = $this->store->find( $session->get_id() );
		$events   = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status() );
		$this->assertTrue( $restored->is_dry_run() );
		$this->assertContains( 'session.done', $events );

		$this->cli->messages = array();
		$command->status( array( $session->get_id()->to_string() ), array() );

		$output = implode( "\n", $this->cli->messages );

		$this->assertStringContainsString( 'Status: done', $output );
		$this->assertStringContainsString( 'Dry run: yes', $output );
	}

	/**
	 * Tick forwards hidden failure simulation controls into the runner.
	 *
	 * @return void
	 */
	public function test_tick_runs_with_hidden_failure_simulation_controls() {
		$session = ImportSession::start_for_source( 'local://book.md' );
		$this->store->save( $session );

		$command = $this->create_command();
		$command->tick(
			array( $session->get_id()->to_string() ),
			array(
				'simulate-memory-pressure' => '1024',
				'simulate-crash'           => true,
			)
		);

		$events = $this->store->list_events( $session->get_id(), 5 );
		$output = implode( "\n", $this->cli->messages );

		$this->assertStringContainsString( '0 processed, 0 locked, 0 skipped, 1 errors', $output );
		$this->assertSame( ImportSession::STATUS_RUNNING, $this->store->find( $session->get_id() )->get_status() );
		$this->assertSame( 'runner.error', $events[0]->get_type() );
		$this->assertSame( 'runner.simulated_crash', $events[1]->get_type() );
		$this->assertSame( 'runner.simulated_memory_pressure', $events[2]->get_type() );
	}

	/**
	 * Re-running the CLI tick recovers after a simulated timeout.
	 *
	 * @return void
	 */
	public function test_tick_rerun_recovers_after_simulated_timeout() {
		$source_file = $this->temporary_file( 'timeout-recovery.md', "# Timeout\n\nCLI retry body." );
		$session     = ImportSession::start_for_source( $source_file, true );
		$command     = $this->create_command();

		$this->store->save( $session );

		$command->tick( array( $session->get_id()->to_string() ), array( 'simulate-timeout' => true ) );
		$events = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 5 )
		);

		$this->assertSame( ImportSession::STATUS_RUNNING, $this->store->find( $session->get_id() )->get_status() );
		$this->assertContains( 'runner.simulated_timeout', $events );

		$this->cli->messages = array();
		$command->tick( array( $session->get_id()->to_string() ), array() );
		$output = implode( "\n", $this->cli->messages );

		$this->assertStringContainsString( '1 processed, 0 locked, 0 skipped, 0 errors', $output );
		$this->assertSame( ImportSession::STATUS_DONE, $this->store->find( $session->get_id() )->get_status() );
		$this->assertSame( 1, $this->store->count_prepared_documents( $session->get_id() ) );
	}

	/**
	 * Re-running the CLI tick recovers after simulated memory pressure plus crash.
	 *
	 * @return void
	 */
	public function test_tick_rerun_recovers_after_simulated_memory_pressure_crash() {
		$source_file = $this->temporary_file( 'crash-recovery.md', "# Crash\n\nCLI retry body." );
		$session     = ImportSession::start_for_source( $source_file, true );
		$command     = $this->create_command();

		$this->store->save( $session );

		$command->tick(
			array( $session->get_id()->to_string() ),
			array(
				'simulate-memory-pressure' => '1024',
				'simulate-crash'           => true,
			)
		);

		$events = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 6 )
		);
		$lock   = $this->store->acquire_lock( $session->get_id(), 'after-cli-crash', 60 );

		$this->assertSame( ImportSession::STATUS_RUNNING, $this->store->find( $session->get_id() )->get_status() );
		$this->assertContains( 'runner.simulated_memory_pressure', $events );
		$this->assertContains( 'runner.simulated_crash', $events );
		$this->assertNotNull( $lock );
		$this->store->release_lock( $lock );

		$this->cli->messages = array();
		$command->tick( array( $session->get_id()->to_string() ), array() );
		$output = implode( "\n", $this->cli->messages );

		$this->assertStringContainsString( '1 processed, 0 locked, 0 skipped, 0 errors', $output );
		$this->assertSame( ImportSession::STATUS_DONE, $this->store->find( $session->get_id() )->get_status() );
		$this->assertSame( 1, $this->store->count_prepared_documents( $session->get_id() ) );
	}

	/**
	 * Re-running the CLI tick recovers after the PHP process terminates under a held lock.
	 *
	 * @return void
	 */
	public function test_tick_rerun_recovers_after_real_fatal_exit_process() {
		$source_file   = $this->temporary_file( 'fatal-exit-recovery.md', "# Fatal\n\nCLI retry body." );
		$snapshot_path = $this->temporary_file( 'fatal-exit-wpdb.snapshot', '' );
		$session       = ImportSession::start_for_source( $source_file, true );

		$this->wpdb->persist_to_file( $snapshot_path );
		$this->store->save( $session );

		$result = $this->run_fatal_tick_child_process( $snapshot_path, $session->get_id()->to_string(), 1700000000 );

		$this->assertSame( 117, $result['exit_code'], $result['stdout'] . $result['stderr'] );

		$wpdb_after_exit = FakeWpdb::from_persisted_file( $snapshot_path );
		$locked_store    = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000001;
			}
		);
		$events          = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$locked_store->list_events( $session->get_id(), 5 )
		);

		$this->assertSame( ImportSession::STATUS_RUNNING, $locked_store->find( $session->get_id() )->get_status() );
		$this->assertContains( 'runner.simulated_fatal_exit', $events );
		$this->assertNull( $locked_store->acquire_lock( $session->get_id(), 'before-ttl', 60 ) );

		$recovery_store = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000301;
			}
		);
		$command        = new ImportCommand( $recovery_store, null, $this->cli );

		$command->tick( array( $session->get_id()->to_string() ), array() );
		$output = implode( "\n", $this->cli->messages );

		$this->assertStringContainsString( '1 processed, 0 locked, 0 skipped, 0 errors', $output );
		$this->assertSame( ImportSession::STATUS_DONE, $recovery_store->find( $session->get_id() )->get_status() );
		$this->assertSame( 1, $recovery_store->count_prepared_documents( $session->get_id() ) );
	}

	/**
	 * Re-running the CLI tick recovers after PHP exits following a durable Markdown cursor write.
	 *
	 * @return void
	 */
	public function test_tick_rerun_recovers_after_real_fatal_exit_after_markdown_cursor() {
		$source_file   = $this->temporary_file( 'fatal-markdown-cursor.md', "# Fatal Cursor\n\n" . str_repeat( "This paragraph forces the Markdown byte-cursor path to prepare a durable partial chunk before the process exits.\n\n", 3500 ) );
		$snapshot_path = $this->temporary_file( 'fatal-markdown-cursor-wpdb.snapshot', '' );
		$session       = ImportSession::start_for_source( $source_file, true );

		$this->wpdb->persist_to_file( $snapshot_path );
		$this->store->save( $session );

		$result = $this->run_fatal_tick_child_process( $snapshot_path, $session->get_id()->to_string(), 1700000000, 'simulate-fatal-after-markdown-cursor' );

		$this->assertSame( 118, $result['exit_code'], $result['stdout'] . $result['stderr'] );

		$wpdb_after_exit = FakeWpdb::from_persisted_file( $snapshot_path );
		$locked_store    = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000001;
			}
		);
		$partial_items   = $locked_store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED ), 1 );
		$events          = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$locked_store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( ImportSession::STATUS_RUNNING, $locked_store->find( $session->get_id() )->get_status() );
		$this->assertNull( $locked_store->acquire_lock( $session->get_id(), 'before-markdown-ttl', 60 ) );
		$this->assertCount( 1, $partial_items );
		$partial_metadata = $partial_items[0]->get_metadata();
		$this->assertSame( 'partial', $partial_metadata['processor_status'] );
		$this->assertSame( 'markdown', $partial_metadata['document_format'] );
		$this->assertGreaterThan( 0, $partial_metadata['markdown_next_offset'] );
		$this->assertSame( 1, $partial_metadata['markdown_chunk_index'] );
		$this->assertSame( 1, $locked_store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 1, $locked_store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' ) );
		$this->assertContains( 'runner.simulated_fatal_after_markdown_cursor', $events );

		$recovery_store = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000301;
			}
		);
		$command        = new ImportCommand( $recovery_store, null, $this->cli );

		$attempt = 0;
		while ( $attempt < 10 && ImportSession::STATUS_DONE !== $recovery_store->find( $session->get_id() )->get_status() ) {
			$command->tick( array( $session->get_id()->to_string() ), array() );
			++$attempt;
		}
		$prepared_count = $recovery_store->count_prepared_documents( $session->get_id() );

		$this->assertSame( ImportSession::STATUS_DONE, $recovery_store->find( $session->get_id() )->get_status() );
		$this->assertGreaterThan( 1, $prepared_count );
		$this->assertSame( $prepared_count, $recovery_store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' ) );
		$this->assertCount( 1, $recovery_store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 ) );
	}

	/**
	 * Re-running the CLI tick recovers after PHP exits following a durable WXR cursor write.
	 *
	 * @return void
	 */
	public function test_tick_rerun_recovers_after_real_fatal_exit_after_wxr_cursor() {
		$posts = array();
		for ( $index = 1; $index <= 30; ++$index ) {
			$posts[] = array(
				'id'      => $index,
				'title'   => 'Fatal WXR Post ' . $index,
				'type'    => 'post',
				'content' => '<p>Body ' . $index . '</p>',
			);
		}

		$source_file   = $this->temporary_file( 'fatal-wxr-cursor.xml', $this->wxr_export( $posts ) );
		$snapshot_path = $this->temporary_file( 'fatal-wxr-cursor-wpdb.snapshot', '' );
		$session       = ImportSession::start_for_source( $source_file, true );

		$this->wpdb->persist_to_file( $snapshot_path );
		$this->store->save( $session );

		$result = $this->run_fatal_tick_child_process( $snapshot_path, $session->get_id()->to_string(), 1700000000, 'simulate-fatal-after-wxr-cursor' );

		$this->assertSame( 122, $result['exit_code'], $result['stdout'] . $result['stderr'] );

		$wpdb_after_exit = FakeWpdb::from_persisted_file( $snapshot_path );
		$locked_store    = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000001;
			}
		);
		$partial_items   = $locked_store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED ), 1 );
		$events          = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$locked_store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( ImportSession::STATUS_RUNNING, $locked_store->find( $session->get_id() )->get_status() );
		$this->assertNull( $locked_store->acquire_lock( $session->get_id(), 'before-wxr-ttl', 60 ) );
		$this->assertCount( 1, $partial_items );
		$partial_metadata = $partial_items[0]->get_metadata();
		$this->assertSame( 'partial', $partial_metadata['processor_status'] );
		$this->assertSame( 'wxr', $partial_metadata['document_format'] );
		$this->assertArrayHasKey( 'wxr_cursor', $partial_metadata );
		$this->assertSame( 25, $locked_store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 25, $locked_store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' ) );
		$this->assertContains( 'runner.simulated_fatal_after_wxr_cursor', $events );

		$recovery_store = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000301;
			}
		);
		$command        = new ImportCommand( $recovery_store, null, $this->cli );

		$attempt = 0;
		while ( $attempt < 10 && ImportSession::STATUS_DONE !== $recovery_store->find( $session->get_id() )->get_status() ) {
			$command->tick( array( $session->get_id()->to_string() ), array() );
			++$attempt;
		}
		$prepared_count = $recovery_store->count_prepared_documents( $session->get_id() );
		$complete_items = $recovery_store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );

		$this->assertSame( ImportSession::STATUS_DONE, $recovery_store->find( $session->get_id() )->get_status() );
		$this->assertSame( 30, $prepared_count );
		$this->assertSame( $prepared_count, $recovery_store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' ) );
		$this->assertCount( 1, $complete_items );
		$this->assertArrayNotHasKey( 'wxr_cursor', $complete_items[0]->get_metadata() );
	}

	/**
	 * Re-running the CLI tick recovers after PHP exits following a durable EPUB spine cursor write.
	 *
	 * @return void
	 */
	public function test_tick_rerun_recovers_after_real_fatal_exit_after_epub_spine_cursor() {
		if ( ! class_exists( \ZipArchive::class ) ) {
			$this->markTestSkipped( 'The PHP zip extension is required for EPUB fatal cursor coverage.' );
		}

		$chapters = array();
		for ( $index = 1; $index <= 30; ++$index ) {
			$chapters[ 'chapter-' . $index ] = array(
				'href'    => 'chapter-' . $index . '.xhtml',
				'title'   => 'Fatal EPUB Chapter ' . $index,
				'content' => '<h1>Fatal EPUB Chapter ' . $index . '</h1><p>Body ' . $index . '</p>',
			);
		}

		$source_file   = $this->temporary_epub( 'fatal-epub-cursor.epub', 'Fatal EPUB', $chapters );
		$snapshot_path = $this->temporary_file( 'fatal-epub-cursor-wpdb.snapshot', '' );
		$session       = ImportSession::start_for_source( $source_file, true );

		$this->wpdb->persist_to_file( $snapshot_path );
		$this->store->save( $session );

		$result = $this->run_fatal_tick_child_process( $snapshot_path, $session->get_id()->to_string(), 1700000000, 'simulate-fatal-after-epub-spine-cursor' );

		$this->assertSame( 123, $result['exit_code'], $result['stdout'] . $result['stderr'] );

		$wpdb_after_exit = FakeWpdb::from_persisted_file( $snapshot_path );
		$locked_store    = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000001;
			}
		);
		$partial_items   = $locked_store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED ), 1 );
		$events          = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$locked_store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( ImportSession::STATUS_RUNNING, $locked_store->find( $session->get_id() )->get_status() );
		$this->assertNull( $locked_store->acquire_lock( $session->get_id(), 'before-epub-ttl', 60 ) );
		$this->assertCount( 1, $partial_items );
		$partial_metadata = $partial_items[0]->get_metadata();
		$this->assertSame( 'partial', $partial_metadata['processor_status'] );
		$this->assertSame( 'epub', $partial_metadata['document_format'] );
		$this->assertSame( 1, $partial_metadata['epub_spine_index'] );
		$this->assertSame( 1, $locked_store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 1, $locked_store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' ) );
		$this->assertContains( 'runner.simulated_fatal_after_epub_spine_cursor', $events );

		$recovery_store = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000301;
			}
		);
		$command        = new ImportCommand( $recovery_store, null, $this->cli );

		$attempt = 0;
		while ( $attempt < 10 && ImportSession::STATUS_DONE !== $recovery_store->find( $session->get_id() )->get_status() ) {
			$command->tick( array( $session->get_id()->to_string() ), array() );
			++$attempt;
		}
		$prepared_count = $recovery_store->count_prepared_documents( $session->get_id() );
		$complete_items = $recovery_store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 );

		$this->assertSame( ImportSession::STATUS_DONE, $recovery_store->find( $session->get_id() )->get_status() );
		$this->assertSame( 30, $prepared_count );
		$this->assertSame( $prepared_count, $recovery_store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' ) );
		$this->assertCount( 1, $complete_items );
		$this->assertArrayNotHasKey( 'epub_spine_index', $complete_items[0]->get_metadata() );
	}

	/**
	 * Re-running the CLI tick recovers after PHP exits following a durable zip entry cursor write.
	 *
	 * @return void
	 */
	public function test_tick_rerun_recovers_after_real_fatal_exit_after_zip_entry_cursor() {
		if ( ! class_exists( \ZipArchive::class ) ) {
			$this->markTestSkipped( 'The PHP zip extension is required for zip fatal cursor coverage.' );
		}

		$source_file   = $this->temporary_zip(
			'fatal-zip-cursor.zip',
			array(
				'001.md' => '# One',
				'002.md' => '# Two',
				'003.md' => '# Three',
			)
		);
		$snapshot_path = $this->temporary_file( 'fatal-zip-cursor-wpdb.snapshot', '' );
		$session       = ImportSession::start_for_source( $source_file, true );

		$this->wpdb->persist_to_file( $snapshot_path );
		$this->store->save( $session );

		$result = $this->run_fatal_tick_child_process( $snapshot_path, $session->get_id()->to_string(), 1700000000, 'simulate-fatal-after-zip-entry-cursor' );

		$this->assertSame( 124, $result['exit_code'], $result['stdout'] . $result['stderr'] );

		$wpdb_after_exit = FakeWpdb::from_persisted_file( $snapshot_path );
		$locked_store    = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000001;
			}
		);
		$archive_items   = $locked_store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_PROCESSING ), 5 );
		$events          = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$locked_store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( ImportSession::STATUS_RUNNING, $locked_store->find( $session->get_id() )->get_status() );
		$this->assertNull( $locked_store->acquire_lock( $session->get_id(), 'before-zip-ttl', 60 ) );
		$this->assertCount( 1, $archive_items );
		$archive_metadata = $archive_items[0]->get_metadata();
		$this->assertSame( 'expanding', $archive_metadata['archive_status'] );
		$this->assertSame( 1, $archive_metadata['archive_next_index'] );
		$this->assertSame( 3, $archive_metadata['archive_total_entries'] );
		$this->assertSame( 1, $this->count_archive_children( $locked_store, $session, $archive_items[0]->get_key() ) );
		$this->assertContains( 'runner.simulated_fatal_after_zip_entry_cursor', $events );

		$recovery_store = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000301;
			}
		);
		$command        = new ImportCommand( $recovery_store, null, $this->cli );

		$attempt = 0;
		while ( $attempt < 10 && ImportSession::STATUS_DONE !== $recovery_store->find( $session->get_id() )->get_status() ) {
			$command->tick( array( $session->get_id()->to_string() ), array() );
			++$attempt;
		}
		$expanded_archives = $recovery_store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_SKIPPED ), 5 );

		$this->assertSame( ImportSession::STATUS_DONE, $recovery_store->find( $session->get_id() )->get_status() );
		$this->assertSame( 3, $recovery_store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 3, $recovery_store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' ) );
		$this->assertSame( 3, $this->count_archive_children( $recovery_store, $session, $archive_items[0]->get_key() ) );
		$this->assertNotEmpty( $expanded_archives );
		$this->assertSame( 'expanded', $expanded_archives[0]->get_metadata()['archive_status'] );
	}

	/**
	 * Re-running the CLI tick recovers after PHP exits following a durable REST page cursor write.
	 *
	 * @return void
	 */
	public function test_tick_rerun_recovers_after_real_fatal_exit_after_rest_page_cursor() {
		$source_url    = 'https://source.example.test/';
		$snapshot_path = $this->temporary_file( 'fatal-rest-page-cursor-wpdb.snapshot', '' );
		$session       = ImportSession::start_for_source( $source_url, true );

		$this->wpdb->persist_to_file( $snapshot_path );
		$this->store->save( $session );

		$result = $this->run_fatal_rest_page_cursor_child_process( $snapshot_path, $session->get_id()->to_string(), 1700000000 );

		$this->assertSame( 125, $result['exit_code'], $result['stdout'] . $result['stderr'] );

		$wpdb_after_exit = FakeWpdb::from_persisted_file( $snapshot_path );
		$locked_store    = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000001;
			}
		);
		$root            = $locked_store->find_source_item( $session->get_id(), 'remote:' . hash( 'sha256', $source_url ) );
		$events          = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$locked_store->list_events( $session->get_id(), 15 )
		);

		$this->assertSame( ImportSession::STATUS_RUNNING, $locked_store->find( $session->get_id() )->get_status() );
		$this->assertNull( $locked_store->acquire_lock( $session->get_id(), 'before-rest-ttl', 60 ) );
		$this->assertNotNull( $root );
		$metadata = $root->get_metadata();
		$this->assertSame( 'partial', $metadata['remote_status'] );
		$this->assertSame( 0, $metadata['endpoint_index'] );
		$this->assertSame( 2, $metadata['endpoint_page'] );
		$this->assertSame( 1, $metadata['remote_documents_prepared'] );
		$this->assertSame( 1, $locked_store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 1, $locked_store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' ) );
		$this->assertContains( 'runner.simulated_fatal_after_rest_page_cursor', $events );

		$recovery_store = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000301;
			}
		);
		$fetcher        = $this->rest_pagination_fetcher();
		$command        = new ImportCommand(
			$recovery_store,
			null,
			$this->cli,
			function ( WordPressImportSessionStore $store, ImportRunnerControls $controls ) use ( $fetcher ) {
				return new ImportRunner( $store, 'wp-cli', 60, $controls, new FakePostGateway(), null, null, null, $fetcher );
			}
		);

		$attempt = 0;
		while ( $attempt < 10 && ImportSession::STATUS_DONE !== $recovery_store->find( $session->get_id() )->get_status() ) {
			$command->tick( array( $session->get_id()->to_string() ), array() );
			++$attempt;
		}
		$complete_root = $recovery_store->find_source_item( $session->get_id(), 'remote:' . hash( 'sha256', $source_url ) );

		$this->assertSame( ImportSession::STATUS_DONE, $recovery_store->find( $session->get_id() )->get_status() );
		$this->assertSame( 1, $recovery_store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 1, $recovery_store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' ) );
		$this->assertNotNull( $complete_root );
		$this->assertSame( ImportSourceItem::STATUS_SKIPPED, $complete_root->get_status() );
		$this->assertArrayNotHasKey( 'endpoint_page', $complete_root->get_metadata() );
	}

	/**
	 * Re-running the CLI tick recovers after PHP exits following a durable PDF structure cursor write.
	 *
	 * @return void
	 */
	public function test_tick_rerun_recovers_after_real_fatal_exit_after_pdf_structure_cursor() {
		$source_file   = $this->temporary_pdf_with_text_streams( 'fatal-pdf-structure-cursor.pdf', SourceItemDocumentProcessor::PDF_STRUCTURE_SCAN_LIMIT + 1 );
		$snapshot_path = $this->temporary_file( 'fatal-pdf-structure-cursor-wpdb.snapshot', '' );
		$session       = ImportSession::start_for_source( $source_file, true );

		$this->wpdb->persist_to_file( $snapshot_path );
		$this->store->save( $session );

		$result = $this->run_fatal_tick_child_process( $snapshot_path, $session->get_id()->to_string(), 1700000000, 'simulate-fatal-after-pdf-structure-cursor' );

		$this->assertSame( 119, $result['exit_code'], $result['stdout'] . $result['stderr'] );

		$wpdb_after_exit = FakeWpdb::from_persisted_file( $snapshot_path );
		$locked_store    = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000001;
			}
		);
		$partial_items   = $locked_store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED ), 1 );
		$events          = array_map(
			function ( ImportProgressEvent $event ) {
				return $event->get_type();
			},
			$locked_store->list_events( $session->get_id(), 10 )
		);

		$this->assertSame( ImportSession::STATUS_RUNNING, $locked_store->find( $session->get_id() )->get_status() );
		$this->assertNull( $locked_store->acquire_lock( $session->get_id(), 'before-pdf-structure-ttl', 60 ) );
		$this->assertCount( 1, $partial_items );
		$partial_metadata = $partial_items[0]->get_metadata();
		$this->assertSame( 'partial', $partial_metadata['processor_status'] );
		$this->assertSame( 'pdf', $partial_metadata['document_format'] );
		$this->assertSame( 'pdf_structure_scan', $partial_metadata['pdf_processing_phase'] );
		$this->assertGreaterThan( 0, $partial_metadata['pdf_structure_next_offset'] );
		$this->assertSame( SourceItemDocumentProcessor::PDF_STRUCTURE_SCAN_LIMIT, $partial_metadata['pdf_structure_stream_index'] );
		$this->assertSame( 0, $locked_store->count_prepared_documents( $session->get_id() ) );
		$this->assertContains( 'runner.simulated_fatal_after_pdf_structure_cursor', $events );

		$recovery_store = new WordPressImportSessionStore(
			$wpdb_after_exit,
			null,
			function () {
				return 1700000301;
			}
		);
		$command        = new ImportCommand( $recovery_store, null, $this->cli );

		$attempt = 0;
		while ( $attempt < 10 && ImportSession::STATUS_DONE !== $recovery_store->find( $session->get_id() )->get_status() ) {
			$command->tick( array( $session->get_id()->to_string() ), array() );
			++$attempt;
		}
		$prepared_count = $recovery_store->count_prepared_documents( $session->get_id() );
		$documents      = $recovery_store->list_prepared_documents( $session->get_id(), 10 );

		$this->assertSame( ImportSession::STATUS_DONE, $recovery_store->find( $session->get_id() )->get_status() );
		$this->assertSame( 1, $prepared_count );
		$this->assertSame( $prepared_count, $recovery_store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' ) );
		$this->assertStringContainsString( 'Chunk body 1', $documents[0]->get_block_markup() );
		$this->assertStringContainsString( 'Chunk body 6', $documents[0]->get_block_markup() );
		$this->assertCount( 1, $recovery_store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), 1 ) );
	}

	/**
	 * Re-running the CLI tick recovers after the post-write fatal option exits PHP.
	 *
	 * @return void
	 */
	public function test_tick_rerun_recovers_after_real_fatal_exit_after_post_write_option() {
		$source_file   = $this->temporary_file( 'fatal-cli-post.md', "# Fatal CLI Post\n\nBody text." );
		$db_snapshot   = $this->temporary_file( 'fatal-cli-post-wpdb.snapshot', '' );
		$post_snapshot = $this->temporary_file( 'fatal-cli-post-gateway.snapshot', '' );
		$session       = ImportSession::start_for_source( $source_file );
		$posts         = new FakePostGateway();

		$this->wpdb->persist_to_file( $db_snapshot );
		$posts->persist_to_file( $post_snapshot );
		$this->store->save( $session );

		$result = $this->run_fatal_write_tick_child_process( 'post', $db_snapshot, $post_snapshot, $session->get_id()->to_string(), 1700000000, 'simulate-fatal-after-post-write' );

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
		$this->assertNull( $locked_store->acquire_lock( $session->get_id(), 'before-cli-post-ttl', 60 ) );
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
		$command        = new ImportCommand(
			$recovery_store,
			null,
			$this->cli,
			function ( WordPressImportSessionStore $store, ImportRunnerControls $controls ) use ( $posts_after_exit ) {
				return new ImportRunner( $store, 'wp-cli', 60, $controls, $posts_after_exit );
			}
		);

		$command->tick( array( $session->get_id()->to_string() ), array() );
		$record = $recovery_store->find_idempotency_record( $session->get_id(), 'post:' . $items[0]->get_key() );

		$this->assertSame( ImportSession::STATUS_DONE, $recovery_store->find( $session->get_id() )->get_status() );
		$this->assertSame( 1, $posts_after_exit->count_posts() );
		$this->assertNotNull( $record );
		$this->assertSame( '1', $record->get_resource_id() );
	}

	/**
	 * Re-running the CLI tick recovers after the media-write fatal option exits PHP.
	 *
	 * @return void
	 */
	public function test_tick_rerun_recovers_after_real_fatal_exit_after_media_write_option() {
		$root = $this->temporary_directory();
		mkdir( $root . '/images' );
		chmod( $root . '/images', 0700 );
		file_put_contents( $root . '/images/photo.jpg', 'image-bytes' );
		file_put_contents( $root . '/chapter.md', "# Fatal CLI Media\n\n![Photo](images/photo.jpg)" );

		$db_snapshot    = $this->temporary_file( 'fatal-cli-media-wpdb.snapshot', '' );
		$media_snapshot = $this->temporary_file( 'fatal-cli-media-gateway.snapshot', '' );
		$session        = ImportSession::start_for_source( $root . '/chapter.md' );
		$media          = new FakeMediaGateway();

		$this->wpdb->persist_to_file( $db_snapshot );
		$media->persist_to_file( $media_snapshot );
		$this->store->save( $session );

		$result = $this->run_fatal_write_tick_child_process( 'media', $db_snapshot, $media_snapshot, $session->get_id()->to_string(), 1700000000, 'simulate-fatal-after-media-write' );

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
		$this->assertNull( $locked_store->acquire_lock( $session->get_id(), 'before-cli-media-ttl', 60 ) );
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
		$command        = new ImportCommand(
			$recovery_store,
			null,
			$this->cli,
			function ( WordPressImportSessionStore $store, ImportRunnerControls $controls ) use ( $posts, $media_after_exit ) {
				return new ImportRunner( $store, 'wp-cli', 60, $controls, $posts, 'https://local.example.test/', $media_after_exit );
			}
		);

		$command->tick( array( $session->get_id()->to_string() ), array() );
		$record = $recovery_store->find_idempotency_record( $session->get_id(), 'attachment:' . $references[0]->get_key() );

		$this->assertSame( ImportSession::STATUS_DONE, $recovery_store->find( $session->get_id() )->get_status() );
		$this->assertSame( 1, $media_after_exit->count_attachments() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertNotNull( $record );
		$this->assertSame( '100', $record->get_resource_id() );
	}

	/**
	 * Re-running the CLI tick recovers after the comment-write fatal option exits PHP.
	 *
	 * @return void
	 */
	public function test_tick_rerun_recovers_after_real_fatal_exit_after_comment_write_option() {
		$source_file      = $this->temporary_file( 'fatal-cli-comment.md', '# Fatal CLI Comment' );
		$source_item_key  = 'local:' . hash( 'sha256', realpath( $source_file ) );
		$content_hash     = 'hash-fatal-cli-comment';
		$db_snapshot      = $this->temporary_file( 'fatal-cli-comment-wpdb.snapshot', '' );
		$comment_snapshot = $this->temporary_file( 'fatal-cli-comment-gateway.snapshot', '' );
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
			'Fatal CLI Comment',
			'<!-- wp:paragraph -->' . "\n" . '<p>Fatal CLI Comment</p>' . "\n" . '<!-- /wp:paragraph -->',
			1,
			$content_hash,
			array(
				'remote_comments'          => array(
					array(
						'remote_comment_id' => 101,
						'remote_parent_id'  => 0,
						'author_name'       => 'Commenter',
						'content'           => 'Recover this CLI comment.',
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

		$result = $this->run_fatal_write_tick_child_process( 'comment', $db_snapshot, $comment_snapshot, $session->get_id()->to_string(), 1700000000, 'simulate-fatal-after-comment-write' );

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
		$this->assertNull( $locked_store->acquire_lock( $session->get_id(), 'before-cli-comment-ttl', 60 ) );
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
		$command        = new ImportCommand(
			$recovery_store,
			null,
			$this->cli,
			function ( WordPressImportSessionStore $store, ImportRunnerControls $controls ) use ( $comments_after_exit ) {
				return new ImportRunner( $store, 'wp-cli', 60, $controls, new FakePostGateway(), null, null, null, null, $comments_after_exit );
			}
		);

		$command->tick( array( $session->get_id()->to_string() ), array() );
		$record = $recovery_store->find_idempotency_record( $session->get_id(), 'comment:' . $source_item_key . ':101' );

		$this->assertSame( ImportSession::STATUS_DONE, $recovery_store->find( $session->get_id() )->get_status() );
		$this->assertSame( 1, $comments_after_exit->count_comments() );
		$this->assertNotNull( $record );
		$this->assertSame( '1000', $record->get_resource_id() );
	}

	/**
	 * Missing sessions produce actionable CLI diagnostics.
	 *
	 * @return void
	 */
	public function test_status_errors_when_session_is_missing() {
		$command = $this->create_command();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Import session not found: import_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' );

		$command->status( array( 'import_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ), array() );
	}

	/**
	 * Creates a command with fake dependencies.
	 *
	 * @return ImportCommand
	 */
	private function create_command() {
		return new ImportCommand(
			$this->store,
			function ( ImportSessionId $session_id ) {
				$this->scheduled_sessions[] = $session_id->to_string();
			},
			$this->cli
		);
	}

	/**
	 * Creates a temporary local source file.
	 *
	 * @param string $basename File basename.
	 * @param string $contents File contents.
	 * @return string
	 */
	private function temporary_file( $basename, $contents ) {
		$path = sys_get_temp_dir() . '/universal-importer-cli-' . bin2hex( random_bytes( 6 ) ) . '-' . $basename;

		$this->assertNotFalse( file_put_contents( $path, $contents ) );
		$this->temporary_paths[] = $path;

		return $path;
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
	 * Creates a temporary EPUB archive fixture.
	 *
	 * @param string                                                       $basename Archive basename.
	 * @param string                                                       $title    EPUB title.
	 * @param array<string,array{href:string,title:string,content:string}> $chapters Chapter fixtures.
	 * @return string
	 */
	private function temporary_epub( $basename, $title, array $chapters ) {
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

		$entries['OEBPS/content.opf'] = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<package version="3.0" xmlns="http://www.idpf.org/2007/opf" unique-identifier="book-id">'
			. '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>' . htmlspecialchars( $title, ENT_XML1, 'UTF-8' ) . '</dc:title></metadata>'
			. '<manifest>' . $manifest . '</manifest><spine>' . $spine . '</spine></package>';

		return $this->temporary_zip( $basename, $entries );
	}

	/**
	 * Creates a temporary zip archive fixture.
	 *
	 * @param string               $basename Archive basename.
	 * @param array<string,string> $entries  Entry names and contents.
	 * @return string
	 */
	private function temporary_zip( $basename, array $entries ) {
		$path = sys_get_temp_dir() . '/universal-importer-cli-' . bin2hex( random_bytes( 6 ) ) . '-' . $basename;
		$zip  = new \ZipArchive();

		$this->assertTrue( true === $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );

		foreach ( $entries as $name => $contents ) {
			$this->assertTrue( $zip->addFromString( $name, $contents ) );
		}

		$this->assertTrue( $zip->close() );
		$this->temporary_paths[] = $path;

		return $path;
	}

	/**
	 * Counts child source items queued from a zip archive parent.
	 *
	 * @param WordPressImportSessionStore $store      Store to inspect.
	 * @param ImportSession               $session    Session.
	 * @param string                      $parent_key Archive parent source item key.
	 * @return int
	 */
	private function count_archive_children( WordPressImportSessionStore $store, ImportSession $session, $parent_key ) {
		$count = 0;
		$items = $store->list_source_items_by_statuses(
			$session->get_id(),
			array(
				ImportSourceItem::STATUS_QUEUED,
				ImportSourceItem::STATUS_DISCOVERED,
				ImportSourceItem::STATUS_PROCESSING,
				ImportSourceItem::STATUS_IMPORTED,
				ImportSourceItem::STATUS_SKIPPED,
				ImportSourceItem::STATUS_FAILED,
			),
			50
		);

		foreach ( $items as $item ) {
			$metadata = $item->get_metadata();

			if ( isset( $metadata['archive_parent_key'] ) && $parent_key === $metadata['archive_parent_key'] ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Builds a fake REST pagination fixture.
	 *
	 * @return FakeRemoteContentFetcher
	 */
	private function rest_pagination_fetcher() {
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
					'id'      => 101,
					'link'    => 'https://source.example.test/rest-page-one/',
					'title'   => array( 'rendered' => 'REST Page One' ),
					'content' => array( 'rendered' => '<p>Body one.</p>' ),
				),
			)
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=2&_embed=author,wp:term,wp:featuredmedia',
			array()
		);
		$fetcher->add_json(
			'https://source.example.test/wp-json/wp/v2/comments?context=view&post=101&per_page=25&page=1&order=asc&orderby=date_gmt',
			array()
		);

		return $fetcher;
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
	 * Creates a temporary directory fixture.
	 *
	 * @return string
	 */
	private function temporary_directory() {
		$path = sys_get_temp_dir() . '/universal-importer-cli-' . bin2hex( random_bytes( 6 ) );

		mkdir( $path );
		chmod( $path, 0700 );
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
			$stream         = "BT\n/F1 12 Tf\n72 720 Td\n(# CLI PDF Structure Cursor " . ( $index + 1 ) . "\\n\\nChunk body " . ( $index + 1 ) . ".) Tj\nET\n";
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
	 * Runs one CLI tick in a child PHP process that is expected to terminate itself.
	 *
	 * @param string $snapshot_path Persisted fake database snapshot path.
	 * @param string $session_id    Import session id.
	 * @param int    $now           Child process timestamp.
	 * @param string $option        Fatal simulation option name.
	 * @return array{exit_code:int,stdout:string,stderr:string}
	 */
	private function run_fatal_tick_child_process( $snapshot_path, $session_id, $now, $option = 'simulate-fatal-exit' ) {
		$repository_root = dirname( __DIR__, 3 );
		$root_literal    = "'" . addcslashes( $repository_root, "\\'" ) . "'";
		$script          = $this->temporary_file(
			'fatal-exit-child.php',
			'<?php
$root = ' . $root_literal . ';
require_once $root . "/vendor/autoload.php";
require_once $root . "/tests/bootstrap.php";

use UniversalImporter\Cli\ImportCommand;
use UniversalImporter\Import\WordPressImportSessionStore;
use UniversalImporter\Tests\Unit\Cli\FakeCli;
use UniversalImporter\Tests\Unit\Import\FakeWpdb;

$snapshot_path = $argv[1];
$session_id = $argv[2];
$now = (int) $argv[3];
$option = $argv[4];
$wpdb = FakeWpdb::from_persisted_file( $snapshot_path );
$store = new WordPressImportSessionStore(
	$wpdb,
	null,
	function () use ( $now ) {
		return $now;
	}
);
$command = new ImportCommand( $store, null, new FakeCli() );
$command->tick( array( $session_id ), array( $option => true ) );
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
					$snapshot_path,
					$session_id,
					(string) $now,
					$option,
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
	 * Runs one CLI tick in a child PHP process that exits after a write boundary.
	 *
	 * @param string $mode                  Gateway mode: post, media, or comment.
	 * @param string $db_snapshot_path      Persisted fake database snapshot path.
	 * @param string $gateway_snapshot_path Persisted fake gateway snapshot path.
	 * @param string $session_id            Import session id.
	 * @param int    $now                   Child process timestamp.
	 * @param string $option                Fatal simulation option name.
	 * @return array{exit_code:int,stdout:string,stderr:string}
	 */
	private function run_fatal_write_tick_child_process( $mode, $db_snapshot_path, $gateway_snapshot_path, $session_id, $now, $option ) {
		$repository_root = dirname( __DIR__, 3 );
		$root_literal    = "'" . addcslashes( $repository_root, "\\'" ) . "'";
		$script          = $this->temporary_file(
			'fatal-write-child.php',
			'<?php
$root = ' . $root_literal . ';
require_once $root . "/vendor/autoload.php";
require_once $root . "/tests/bootstrap.php";

use UniversalImporter\Cli\ImportCommand;
use UniversalImporter\Import\ImportRunner;
use UniversalImporter\Import\ImportRunnerControls;
use UniversalImporter\Import\WordPressImportSessionStore;
use UniversalImporter\Tests\Unit\Cli\FakeCli;
use UniversalImporter\Tests\Unit\Import\FakeCommentGateway;
use UniversalImporter\Tests\Unit\Import\FakeMediaGateway;
use UniversalImporter\Tests\Unit\Import\FakePostGateway;
use UniversalImporter\Tests\Unit\Import\FakeWpdb;

$mode = $argv[1];
$db_snapshot_path = $argv[2];
$gateway_snapshot_path = $argv[3];
$session_id = $argv[4];
$now = (int) $argv[5];
$option = $argv[6];
$wpdb = FakeWpdb::from_persisted_file( $db_snapshot_path );
$store = new WordPressImportSessionStore(
	$wpdb,
	null,
	function () use ( $now ) {
		return $now;
	}
);

if ( "post" === $mode ) {
	$posts = FakePostGateway::from_persisted_file( $gateway_snapshot_path );
	$factory = function ( WordPressImportSessionStore $store, ImportRunnerControls $controls ) use ( $posts ) {
		return new ImportRunner( $store, "wp-cli", 60, $controls, $posts );
	};
} elseif ( "media" === $mode ) {
	$media = FakeMediaGateway::from_persisted_file( $gateway_snapshot_path );
	$factory = function ( WordPressImportSessionStore $store, ImportRunnerControls $controls ) use ( $media ) {
		return new ImportRunner( $store, "wp-cli", 60, $controls, new FakePostGateway(), "https://local.example.test/", $media );
	};
} elseif ( "comment" === $mode ) {
	$comments = FakeCommentGateway::from_persisted_file( $gateway_snapshot_path );
	$factory = function ( WordPressImportSessionStore $store, ImportRunnerControls $controls ) use ( $comments ) {
		return new ImportRunner( $store, "wp-cli", 60, $controls, new FakePostGateway(), null, null, null, null, $comments );
	};
} else {
	fwrite( STDERR, "Unsupported fatal write mode: " . $mode . "\n" );
	exit( 2 );
}

$command = new ImportCommand( $store, null, new FakeCli(), $factory );
$command->tick( array( $session_id ), array( $option => true ) );
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
					$mode,
					$db_snapshot_path,
					$gateway_snapshot_path,
					$session_id,
					(string) $now,
					$option,
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
	 * Runs one CLI tick in a child PHP process that exits after a REST page cursor write.
	 *
	 * @param string $snapshot_path Persisted fake database snapshot path.
	 * @param string $session_id    Import session id.
	 * @param int    $now           Child process timestamp.
	 * @return array{exit_code:int,stdout:string,stderr:string}
	 */
	private function run_fatal_rest_page_cursor_child_process( $snapshot_path, $session_id, $now ) {
		$repository_root = dirname( __DIR__, 3 );
		$root_literal    = "'" . addcslashes( $repository_root, "\\'" ) . "'";
		$script          = $this->temporary_file(
			'fatal-rest-page-cursor-child.php',
			'<?php
$root = ' . $root_literal . ';
require_once $root . "/vendor/autoload.php";
require_once $root . "/tests/bootstrap.php";

use UniversalImporter\Cli\ImportCommand;
use UniversalImporter\Import\ImportRunner;
use UniversalImporter\Import\ImportRunnerControls;
use UniversalImporter\Import\WordPressImportSessionStore;
use UniversalImporter\Tests\Unit\Cli\FakeCli;
use UniversalImporter\Tests\Unit\Import\FakePostGateway;
use UniversalImporter\Tests\Unit\Import\FakeRemoteContentFetcher;
use UniversalImporter\Tests\Unit\Import\FakeWpdb;

$snapshot_path = $argv[1];
$session_id = $argv[2];
$now = (int) $argv[3];
$wpdb = FakeWpdb::from_persisted_file( $snapshot_path );
$store = new WordPressImportSessionStore(
	$wpdb,
	null,
	function () use ( $now ) {
		return $now;
	}
);
$fetcher = new FakeRemoteContentFetcher();
$fetcher->add_json(
	"https://source.example.test/wp-json/",
	array(
		"namespaces" => array( "wp/v2" ),
	)
);
$fetcher->add_json(
	"https://source.example.test/wp-json/wp/v2/types?context=view",
	array(
		"post" => array(
			"slug" => "post",
			"rest_base" => "posts",
			"viewable" => true,
		),
	)
);
$fetcher->add_json(
	"https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=1&_embed=author,wp:term,wp:featuredmedia",
	array(
		array(
			"id" => 101,
			"link" => "https://source.example.test/rest-page-one/",
			"title" => array( "rendered" => "REST Page One" ),
			"content" => array( "rendered" => "<p>Body one.</p>" ),
		),
	)
);
$fetcher->add_json(
	"https://source.example.test/wp-json/wp/v2/posts?context=view&per_page=25&page=2&_embed=author,wp:term,wp:featuredmedia",
	array()
);
$fetcher->add_json(
	"https://source.example.test/wp-json/wp/v2/comments?context=view&post=101&per_page=25&page=1&order=asc&orderby=date_gmt",
	array()
);
$factory = function ( WordPressImportSessionStore $store, ImportRunnerControls $controls ) use ( $fetcher ) {
	return new ImportRunner( $store, "wp-cli", 60, $controls, new FakePostGateway(), null, null, null, $fetcher );
};
$command = new ImportCommand( $store, null, new FakeCli(), $factory );
$command->tick( array( $session_id ), array( "simulate-fatal-after-rest-page-cursor" => true ) );
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
					$snapshot_path,
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
}
