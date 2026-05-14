<?php
/**
 * Tests for WXR attachment metadata persistence.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\ImportMediaReference;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportWxrAttachmentMetadataPersister;
use UniversalImporter\Import\WordPressImportSessionStore;

/**
 * Covers retry-safe WXR attachment caption and alt-text persistence.
 */
final class ImportWxrAttachmentMetadataPersisterTest extends TestCase {
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
	 * Imported WXR attachments receive staged captions, alt text, and source metadata idempotently.
	 *
	 * @return void
	 */
	public function test_persister_applies_staged_wxr_attachment_metadata() {
		$session   = ImportSession::start_for_source( '/tmp/export.wxr' );
		$reference = ImportMediaReference::queued(
			$session->get_id(),
			'wxr-attachment:photo',
			'wxr:export:wxr-attachment:31',
			'https://source.example.test/uploads/photo.jpg',
			'https://source.example.test/uploads/photo.jpg',
			ImportMediaReference::TYPE_IMAGE,
			array(
				'source'                  => 'wxr',
				'wxr_attachment_id'       => '31',
				'reference_scope'         => 'confirmed-first-party-url',
				'wxr_attachment_metadata' => array(
					'title'                => 'Remote photo',
					'caption'              => 'Remote caption',
					'description'          => 'Remote description',
					'alt_text'             => 'Remote alt',
					'source_attached_file' => '2024/05/photo.jpg',
				),
			)
		);

		$this->store->save( $session );
		$this->media->add_remote_media( 'https://source.example.test/uploads/photo.jpg', 'photo-bytes' );
		$imported = $this->media->import_remote_url( $reference );
		$this->store->save_media_reference( $reference->mark_imported( $imported['id'], $imported['url'], $imported['source_hash'] ) );

		$summary    = ( new ImportWxrAttachmentMetadataPersister( $this->store, $this->media ) )->advance( $session );
		$attachment = $this->media->get_attachment( 100 );
		$record     = $this->store->find_idempotency_record( $session->get_id(), 'attachment-metadata:wxr-attachment:photo' );

		$this->assertSame( 1, $summary['applied'] );
		$this->assertSame( 'Remote photo', $attachment['post_title'] );
		$this->assertSame( 'Remote caption', $attachment['post_excerpt'] );
		$this->assertSame( 'Remote description', $attachment['post_content'] );
		$this->assertSame( 'Remote alt', $attachment['alt_text'] );
		$this->assertSame( '2024/05/photo.jpg', $attachment['wxr_attachment_metadata']['source_attached_file'] );
		$this->assertNotNull( $record );

		$second_summary = ( new ImportWxrAttachmentMetadataPersister( $this->store, $this->media ) )->advance( $session );

		$this->assertSame( 1, $second_summary['skipped'] );
	}

	/**
	 * Later WXR attachment metadata is reached after earlier idempotent rows.
	 *
	 * @return void
	 */
	public function test_persister_reaches_unapplied_wxr_attachment_metadata_after_applied_rows() {
		$session = ImportSession::start_for_source( '/tmp/export.wxr' );
		$this->store->save( $session );

		$first = $this->imported_reference( $session, 'media:001', '31', 100, 'First title' );
		$this->store->save_media_reference( $first );
		( new ImportWxrAttachmentMetadataPersister( $this->store, $this->media ) )->advance( $session, 1 );

		$second = $this->imported_reference( $session, 'media:002', '32', 101, 'Second title' );
		$this->store->save_media_reference( $second );

		$summary    = ( new ImportWxrAttachmentMetadataPersister( $this->store, $this->media ) )->advance( $session, 1 );
		$attachment = $this->media->get_attachment( 101 );

		$this->assertSame( 1, $summary['applied'] );
		$this->assertSame( 'Second title', $attachment['post_title'] );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'attachment-metadata:media:002' ) );
	}

	/**
	 * Builds and imports a WXR attachment metadata reference.
	 *
	 * @param ImportSession $session       Session.
	 * @param string        $reference_key Media reference key.
	 * @param string        $attachment_id WXR attachment id.
	 * @param int           $local_id      Local attachment id.
	 * @param string        $title         Staged attachment title.
	 * @return ImportMediaReference
	 */
	private function imported_reference( ImportSession $session, $reference_key, $attachment_id, $local_id, $title ) {
		$url       = 'https://source.example.test/uploads/attachment-' . (string) $attachment_id . '.jpg';
		$reference = ImportMediaReference::queued(
			$session->get_id(),
			$reference_key,
			'local:export:wxr-attachment:' . (string) $attachment_id,
			$url,
			$url,
			ImportMediaReference::TYPE_IMAGE,
			array(
				'source'                  => 'wxr',
				'wxr_attachment_id'       => (string) $attachment_id,
				'reference_scope'         => 'confirmed-first-party-url',
				'wxr_attachment_metadata' => array( 'title' => $title ),
			)
		);

		$this->media->add_remote_media( $url, 'image-bytes-' . (string) $attachment_id );
		$imported = $this->media->import_remote_url( $reference, $local_id );

		return $reference->mark_imported( $imported['id'], $imported['url'], $imported['source_hash'] );
	}
}
