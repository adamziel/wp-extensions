<?php
/**
 * Tests for WXR attachment parent restoration.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\ImportIdempotencyRecord;
use UniversalImporter\Import\ImportMediaReference;
use UniversalImporter\Import\ImportPreparedDocument;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportWxrAttachment;
use UniversalImporter\Import\ImportWxrAttachmentParentPersister;
use UniversalImporter\Import\WordPressImportSessionStore;

/**
 * Covers idempotent WXR attachment parent restoration without WordPress loaded.
 */
final class ImportWxrAttachmentParentPersisterTest extends TestCase {
	/**
	 * Store under test.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * Fake post gateway.
	 *
	 * @var FakePostGateway
	 */
	private $posts;

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

		$this->store = new WordPressImportSessionStore( new FakeWpdb() );
		$this->posts = new FakePostGateway();
		$this->media = new FakeMediaGateway();
	}

	/**
	 * Imported WXR attachments get their local parent post restored.
	 *
	 * @return void
	 */
	public function test_persister_applies_wxr_attachment_parent_after_parent_post_import() {
		$session   = ImportSession::start_for_source( '/tmp/export.xml' );
		$document  = $this->document( $session, '70' );
		$reference = $this->imported_reference( $session, '31', '70', 100 );

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );
		$this->store->save_media_reference( $reference );
		$this->posts->insert_or_update( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $document->get_source_item_key(), 'post', '1', $document->get_content_hash() )
		);
		$this->media->add_remote_media( $reference->get_resolved_source_uri(), 'image-bytes' );
		$this->media->import_remote_url( $reference );

		$summary    = ( new ImportWxrAttachmentParentPersister( $this->store, $this->posts, $this->media ) )->advance( $session );
		$attachment = $this->media->get_attachment( 100 );
		$event      = $this->store->list_events( $session->get_id(), 1 )[0];

		$this->assertSame( 1, $summary['applied'] );
		$this->assertSame( 1, $attachment['post_parent'] );
		$this->assertSame( 'attachment_parent.applied', $event->get_type() );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'attachment-parent:' . $reference->get_key() ) );
	}

	/**
	 * Attachment parent restoration waits until the parent draft exists.
	 *
	 * @return void
	 */
	public function test_persister_defers_until_parent_post_exists() {
		$session   = ImportSession::start_for_source( '/tmp/export.xml' );
		$reference = $this->imported_reference( $session, '31', '70', 100 );

		$this->store->save( $session );
		$this->store->save_media_reference( $reference );

		$summary = ( new ImportWxrAttachmentParentPersister( $this->store, $this->posts, $this->media ) )->advance( $session );
		$event   = $this->store->list_events( $session->get_id(), 1 )[0];

		$this->assertSame( 1, $summary['deferred'] );
		$this->assertSame( 'attachment_parent.deferred', $event->get_type() );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'attachment-parent:' . $reference->get_key() ) );
	}

	/**
	 * Re-running attachment parent restoration skips an unchanged relationship.
	 *
	 * @return void
	 */
	public function test_persister_skips_unchanged_parent_mapping() {
		$session   = ImportSession::start_for_source( '/tmp/export.xml' );
		$document  = $this->document( $session, '70' );
		$reference = $this->imported_reference( $session, '31', '70', 100 );

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );
		$this->store->save_media_reference( $reference );
		$this->posts->insert_or_update( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $document->get_source_item_key(), 'post', '1', $document->get_content_hash() )
		);
		$this->media->add_remote_media( $reference->get_resolved_source_uri(), 'image-bytes' );
		$this->media->import_remote_url( $reference );

		$persister = new ImportWxrAttachmentParentPersister( $this->store, $this->posts, $this->media );
		$persister->advance( $session );
		$summary = $persister->advance( $session );

		$this->assertSame( 0, $summary['applied'] );
		$this->assertSame( 1, $summary['skipped'] );
	}

	/**
	 * Later WXR attachment parents are reached after earlier idempotent rows.
	 *
	 * @return void
	 */
	public function test_persister_reaches_unapplied_wxr_attachment_parent_after_applied_rows() {
		$session  = ImportSession::start_for_source( '/tmp/export.xml' );
		$document = $this->document( $session, '70' );

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );
		$this->posts->insert_or_update( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $document->get_source_item_key(), 'post', '1', $document->get_content_hash() )
		);

		$first = $this->imported_reference_with_key( $session, 'media:001', '31', '70', 100 );
		$this->store->save_media_reference( $first );
		$this->media->add_remote_media( $first->get_resolved_source_uri(), 'first-image-bytes' );
		$this->media->import_remote_url( $first, 100 );
		( new ImportWxrAttachmentParentPersister( $this->store, $this->posts, $this->media ) )->advance( $session, 1 );

		$second = $this->imported_reference_with_key( $session, 'media:002', '32', '70', 101 );
		$this->store->save_media_reference( $second );
		$this->media->add_remote_media( $second->get_resolved_source_uri(), 'second-image-bytes' );
		$this->media->import_remote_url( $second, 101 );

		$summary    = ( new ImportWxrAttachmentParentPersister( $this->store, $this->posts, $this->media ) )->advance( $session, 1 );
		$attachment = $this->media->get_attachment( 101 );

		$this->assertSame( 1, $summary['applied'] );
		$this->assertSame( 1, $attachment['post_parent'] );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'attachment-parent:media:002' ) );
	}

	/**
	 * Builds a WXR prepared document fixture.
	 *
	 * @param ImportSession $session Session.
	 * @param string        $post_id WXR post id.
	 * @return ImportPreparedDocument
	 */
	private function document( ImportSession $session, $post_id ) {
		return new ImportPreparedDocument(
			$session->get_id(),
			'local:export:wxr-post:' . (string) $post_id,
			'wxr',
			'Parent WXR Post',
			'<!-- wp:paragraph --><p>Parent WXR Post</p><!-- /wp:paragraph -->',
			1,
			'hash-wxr-parent',
			array(
				'relative_path'       => 'export.xml',
				'wxr_source_item_key' => 'local:export',
				'wxr_post_id'         => (string) $post_id,
			)
		);
	}

	/**
	 * Builds an imported WXR attachment reference fixture.
	 *
	 * @param ImportSession $session       Session.
	 * @param string        $attachment_id WXR attachment id.
	 * @param string        $parent_id     WXR parent post id.
	 * @param int           $local_id      Local attachment id.
	 * @return ImportMediaReference
	 */
	private function imported_reference( ImportSession $session, $attachment_id, $parent_id, $local_id ) {
		return $this->imported_reference_with_key(
			$session,
			ImportWxrAttachment::reference_key( 'local:export', $attachment_id ),
			$attachment_id,
			$parent_id,
			$local_id
		);
	}

	/**
	 * Builds an imported WXR attachment reference fixture with a specific reference key.
	 *
	 * @param ImportSession $session       Session.
	 * @param string        $reference_key Media reference key.
	 * @param string        $attachment_id WXR attachment id.
	 * @param string        $parent_id     WXR parent post id.
	 * @param int           $local_id      Local attachment id.
	 * @return ImportMediaReference
	 */
	private function imported_reference_with_key( ImportSession $session, $reference_key, $attachment_id, $parent_id, $local_id ) {
		$url = 'https://source.example.test/uploads/attachment-' . (string) $attachment_id . '.jpg';

		return ImportMediaReference::queued(
			$session->get_id(),
			$reference_key,
			ImportWxrAttachment::source_item_key( 'local:export', $attachment_id ),
			$url,
			$url,
			ImportMediaReference::TYPE_IMAGE,
			array(
				'reference_scope'   => ImportWxrAttachment::SCOPE_CANDIDATE_FIRST_PARTY,
				'wxr_attachment_id' => (string) $attachment_id,
				'wxr_post_parent'   => (string) $parent_id,
				'source'            => 'wxr',
			)
		)->mark_imported( $local_id, 'https://local.example.test/wp-content/uploads/attachment-' . (string) $attachment_id . '.jpg', 'hash-image' );
	}
}
