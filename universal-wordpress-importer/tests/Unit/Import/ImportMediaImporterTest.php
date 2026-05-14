<?php
/**
 * Tests for queued media attachment import.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Unit fixtures use isolated temporary files without WordPress loaded.

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\ImportMediaImporter;
use UniversalImporter\Import\ImportMediaReference;
use UniversalImporter\Import\ImportPreparedDocument;
use UniversalImporter\Import\ImportRunnerControls;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportSimulatedCrashException;
use UniversalImporter\Import\WordPressImportSessionStore;

/**
 * Covers local media attachment import without WordPress loaded.
 */
final class ImportMediaImporterTest extends TestCase {
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
	 * Fake media gateway.
	 *
	 * @var FakeMediaGateway
	 */
	private $media;

	/**
	 * Temporary paths to remove after tests.
	 *
	 * @var array<int,string>
	 */
	private $temporary_paths = array();

	/**
	 * Sets up test dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wpdb  = new FakeWpdb();
		$this->store = new WordPressImportSessionStore( $this->wpdb );
		$this->media = new FakeMediaGateway();
	}

	/**
	 * Cleans temporary filesystem fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( array_reverse( $this->temporary_paths ) as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			} elseif ( is_dir( $path ) ) {
				rmdir( $path );
			}
		}

		parent::tearDown();
	}

	/**
	 * Queued local media is imported once, remembered, and rewritten in the prepared document.
	 *
	 * @return void
	 */
	public function test_importer_creates_attachment_and_rewrites_prepared_document() {
		$session    = ImportSession::start_for_source( '/tmp/book/chapter.md' );
		$image_path = $this->temporary_file( 'photo.jpg', 'image-bytes' );
		$reference  = ImportMediaReference::queued(
			$session->get_id(),
			'media:photo',
			'local:chapter',
			'images/photo.jpg',
			$image_path,
			ImportMediaReference::TYPE_IMAGE
		);

		$this->store->save( $session );
		$this->store->save_prepared_document( $this->document( $session, '<!-- wp:image --><img src="images/photo.jpg"><!-- /wp:image -->' ) );
		$this->store->save_media_reference( $reference );

		$summary  = ( new ImportMediaImporter( $this->store, $this->media ) )->advance( $session );
		$restored = $this->store->find_media_reference( $session->get_id(), 'media:photo' );
		$document = $this->store->find_prepared_document( $session->get_id(), 'local:chapter' );
		$record   = $this->store->find_idempotency_record( $session->get_id(), 'attachment:media:photo' );
		$events   = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 3 )
		);

		$this->assertSame( 1, $summary['imported'] );
		$this->assertSame( 1, $summary['rewritten'] );
		$this->assertSame( 1, $this->media->count_attachments() );
		$this->assertSame( ImportMediaReference::STATUS_IMPORTED, $restored->get_status() );
		$this->assertSame( 'https://local.example.test/wp-content/uploads/photo.jpg', $restored->get_metadata()['attachment_url'] );
		$this->assertStringContainsString( 'https://local.example.test/wp-content/uploads/photo.jpg', $document->get_block_markup() );
		$this->assertStringNotContainsString( 'images/photo.jpg', $document->get_block_markup() );
		$this->assertNotNull( $record );
		$this->assertSame( 'attachment', $record->get_resource_type() );
		$this->assertContains( 'media.attachment_created', $events );
		$this->assertContains( 'media.reference_rewritten', $events );
	}

	/**
	 * Queued confirmed first-party media URLs are sideloaded and rewritten before post persistence.
	 *
	 * @return void
	 */
	public function test_importer_sideloads_confirmed_first_party_remote_media() {
		$session   = ImportSession::start_for_source( '/tmp/book/chapter.html' );
		$reference = ImportMediaReference::queued(
			$session->get_id(),
			'media:remote-photo',
			'local:chapter',
			'https://source.example.test/uploads/photo.jpg',
			'https://source.example.test/uploads/photo.jpg',
			ImportMediaReference::TYPE_IMAGE,
			array( 'reference_scope' => 'confirmed-first-party-url' )
		);

		$this->store->save( $session );
		$this->store->save_prepared_document( $this->document( $session, '<img src="https://source.example.test/uploads/photo.jpg">' ) );
		$this->store->save_media_reference( $reference );
		$this->media->add_remote_media( 'https://source.example.test/uploads/photo.jpg', 'remote-image-bytes' );

		$summary  = ( new ImportMediaImporter( $this->store, $this->media ) )->advance( $session );
		$restored = $this->store->find_media_reference( $session->get_id(), 'media:remote-photo' );
		$document = $this->store->find_prepared_document( $session->get_id(), 'local:chapter' );
		$record   = $this->store->find_idempotency_record( $session->get_id(), 'attachment:media:remote-photo' );

		$this->assertSame( 1, $summary['imported'] );
		$this->assertSame( 1, $summary['rewritten'] );
		$this->assertSame( ImportMediaReference::STATUS_IMPORTED, $restored->get_status() );
		$this->assertSame( 'https://local.example.test/wp-content/uploads/photo.jpg', $restored->get_metadata()['attachment_url'] );
		$this->assertStringContainsString( 'https://local.example.test/wp-content/uploads/photo.jpg', $document->get_block_markup() );
		$this->assertStringNotContainsString( 'https://source.example.test/uploads/photo.jpg', $document->get_block_markup() );
		$this->assertNotNull( $record );
		$this->assertSame( 'remote-attachment', $record->get_resource_type() );
	}

	/**
	 * Existing attachments discovered by importer metadata are reused after an interruption before idempotency is written.
	 *
	 * @return void
	 */
	public function test_importer_reuses_existing_attachment_found_by_gateway_metadata() {
		$session    = ImportSession::start_for_source( '/tmp/book/chapter.md' );
		$image_path = $this->temporary_file( 'photo.jpg', 'image-bytes' );
		$reference  = ImportMediaReference::queued(
			$session->get_id(),
			'media:photo',
			'local:chapter',
			'images/photo.jpg',
			$image_path,
			ImportMediaReference::TYPE_IMAGE
		);

		$this->store->save( $session );
		$this->store->save_prepared_document( $this->document( $session, '<img src="images/photo.jpg">' ) );
		$this->store->save_media_reference( $reference );
		$this->media->import_local_file( $reference );

		$summary = ( new ImportMediaImporter( $this->store, $this->media ) )->advance( $session );
		$record  = $this->store->find_idempotency_record( $session->get_id(), 'attachment:media:photo' );

		$this->assertSame( 1, $this->media->count_attachments() );
		$this->assertSame( 1, $summary['imported'] );
		$this->assertSame( '100', $record->get_resource_id() );
	}

	/**
	 * A crash after the attachment write but before idempotency is recovered by metadata lookup.
	 *
	 * @return void
	 */
	public function test_importer_recovers_after_media_write_idempotency_gap() {
		$session    = ImportSession::start_for_source( '/tmp/book/chapter.md' );
		$image_path = $this->temporary_file( 'photo.jpg', 'image-bytes' );
		$reference  = ImportMediaReference::queued(
			$session->get_id(),
			'media:photo',
			'local:chapter',
			'images/photo.jpg',
			$image_path,
			ImportMediaReference::TYPE_IMAGE
		);

		$this->store->save( $session );
		$this->store->save_prepared_document( $this->document( $session, '<img src="images/photo.jpg">' ) );
		$this->store->save_media_reference( $reference );

		try {
			( new ImportMediaImporter( $this->store, $this->media, new ImportRunnerControls( false, false, 0, null, false, true ) ) )->advance( $session );
			$this->fail( 'Expected a controlled crash after the attachment write.' );
		} catch ( ImportSimulatedCrashException $exception ) {
			$this->assertSame( 'Simulated importer crash after attachment write and before idempotency record.', $exception->getMessage() );
		}

		$this->assertSame( 1, $this->media->count_attachments() );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'attachment:media:photo' ) );
		$this->assertSame( ImportMediaReference::STATUS_QUEUED, $this->store->find_media_reference( $session->get_id(), 'media:photo' )->get_status() );
		$this->assertSame( 'media.simulated_crash_after_write', $this->store->list_events( $session->get_id(), 1 )[0]->get_type() );

		$summary = ( new ImportMediaImporter( $this->store, $this->media ) )->advance( $session );
		$record  = $this->store->find_idempotency_record( $session->get_id(), 'attachment:media:photo' );

		$this->assertSame( 1, $this->media->count_attachments() );
		$this->assertSame( 1, $summary['imported'] );
		$this->assertSame( 1, $summary['rewritten'] );
		$this->assertNotNull( $record );
		$this->assertSame( '100', $record->get_resource_id() );
		$this->assertSame( ImportMediaReference::STATUS_IMPORTED, $this->store->find_media_reference( $session->get_id(), 'media:photo' )->get_status() );
	}

	/**
	 * Remote attachment writes are reused after a crash before idempotency is written.
	 *
	 * @return void
	 */
	public function test_importer_recovers_remote_sideload_idempotency_gap() {
		$session   = ImportSession::start_for_source( '/tmp/book/chapter.html' );
		$reference = ImportMediaReference::queued(
			$session->get_id(),
			'media:remote-photo',
			'local:chapter',
			'https://source.example.test/uploads/photo.jpg',
			'https://source.example.test/uploads/photo.jpg',
			ImportMediaReference::TYPE_IMAGE,
			array( 'reference_scope' => 'confirmed-first-party-url' )
		);

		$this->store->save( $session );
		$this->store->save_prepared_document( $this->document( $session, '<img src="https://source.example.test/uploads/photo.jpg">' ) );
		$this->store->save_media_reference( $reference );
		$this->media->add_remote_media( 'https://source.example.test/uploads/photo.jpg', 'remote-image-bytes' );

		try {
			( new ImportMediaImporter( $this->store, $this->media, new ImportRunnerControls( false, false, 0, null, false, true ) ) )->advance( $session );
			$this->fail( 'Expected a controlled crash after the remote attachment write.' );
		} catch ( ImportSimulatedCrashException $exception ) {
			$this->assertSame( 'Simulated importer crash after attachment write and before idempotency record.', $exception->getMessage() );
		}

		$this->assertSame( 1, $this->media->count_attachments() );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'attachment:media:remote-photo' ) );
		$this->assertSame( ImportMediaReference::STATUS_QUEUED, $this->store->find_media_reference( $session->get_id(), 'media:remote-photo' )->get_status() );

		$summary = ( new ImportMediaImporter( $this->store, $this->media ) )->advance( $session );
		$record  = $this->store->find_idempotency_record( $session->get_id(), 'attachment:media:remote-photo' );

		$this->assertSame( 1, $this->media->count_attachments() );
		$this->assertSame( 1, $summary['imported'] );
		$this->assertSame( 1, $summary['rewritten'] );
		$this->assertNotNull( $record );
		$this->assertSame( '100', $record->get_resource_id() );
		$this->assertSame( ImportMediaReference::STATUS_IMPORTED, $this->store->find_media_reference( $session->get_id(), 'media:remote-photo' )->get_status() );
	}

	/**
	 * Media import failures are recorded with actionable diagnostics.
	 *
	 * @return void
	 */
	public function test_importer_records_gateway_failures() {
		$session    = ImportSession::start_for_source( '/tmp/book/chapter.md' );
		$image_path = $this->temporary_file( 'photo.jpg', 'image-bytes' );
		$reference  = ImportMediaReference::queued(
			$session->get_id(),
			'media:photo',
			'local:chapter',
			'images/photo.jpg',
			$image_path,
			ImportMediaReference::TYPE_IMAGE
		);

		$this->store->save( $session );
		$this->store->save_media_reference( $reference );
		$this->media->fail_imports_with( 'WordPress rejected the attachment.' );

		$summary  = ( new ImportMediaImporter( $this->store, $this->media ) )->advance( $session );
		$restored = $this->store->find_media_reference( $session->get_id(), 'media:photo' );
		$event    = $this->store->list_events( $session->get_id(), 1 )[0];

		$this->assertSame( 1, $summary['failed'] );
		$this->assertSame( ImportMediaReference::STATUS_FAILED, $restored->get_status() );
		$this->assertSame( 'media.attachment_failed', $event->get_type() );
		$this->assertSame( 'WordPress rejected the attachment.', $event->get_message() );
	}

	/**
	 * Missing WordPress media APIs produce a friendly no-op diagnostic.
	 *
	 * @return void
	 */
	public function test_importer_reports_unavailable_media_gateway_without_failing() {
		$session   = ImportSession::start_for_source( '/tmp/book/chapter.md' );
		$reference = ImportMediaReference::queued(
			$session->get_id(),
			'media:photo',
			'local:chapter',
			'images/photo.jpg',
			'/tmp/book/images/photo.jpg',
			ImportMediaReference::TYPE_IMAGE
		);

		$this->store->save( $session );
		$this->store->save_media_reference( $reference );
		$this->media->make_unavailable();

		$summary = ( new ImportMediaImporter( $this->store, $this->media ) )->advance( $session );

		$this->assertSame( 0, $summary['imported'] );
		$this->assertSame( 0, $summary['failed'] );
		$this->assertTrue( $summary['blocked'] );
		$this->assertSame( 'Fake media gateway is unavailable.', $summary['message'] );
	}

	/**
	 * Builds a prepared document fixture.
	 *
	 * @param ImportSession $session Session.
	 * @param string        $markup  Block markup.
	 * @return ImportPreparedDocument
	 */
	private function document( ImportSession $session, $markup ) {
		return new ImportPreparedDocument(
			$session->get_id(),
			'local:chapter',
			'markdown',
			'Chapter',
			$markup,
			1,
			'hash-a',
			array( 'relative_path' => 'chapter.md' )
		);
	}

	/**
	 * Creates a temporary file fixture.
	 *
	 * @param string $basename Fixture basename.
	 * @param string $content  Fixture content.
	 * @return string
	 */
	private function temporary_file( $basename, $content ) {
		$directory = sys_get_temp_dir() . '/universal-importer-media-' . bin2hex( random_bytes( 6 ) );
		mkdir( $directory );
		$this->temporary_paths[] = $directory;

		$path = $directory . '/' . $basename;
		file_put_contents( $path, $content );
		$this->temporary_paths[] = $path;

		return $path;
	}
}
