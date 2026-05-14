<?php
/**
 * Tests for WXR postmeta persistence.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\ImportDecision;
use UniversalImporter\Import\ImportIdempotencyRecord;
use UniversalImporter\Import\ImportMediaReference;
use UniversalImporter\Import\ImportPostMetaPersister;
use UniversalImporter\Import\ImportPreparedDocument;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportSourceItem;
use UniversalImporter\Import\ImportWxrAttachment;
use UniversalImporter\Import\WordPressImportSessionStore;

/**
 * Covers idempotent WXR postmeta persistence without WordPress loaded.
 */
final class ImportPostMetaPersisterTest extends TestCase {
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
	 * Sets up test dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->store = new WordPressImportSessionStore( new FakeWpdb() );
		$this->posts = new FakePostGateway();
	}

	/**
	 * Staged WXR postmeta is applied to the imported draft and volatile keys are skipped.
	 *
	 * @return void
	 */
	public function test_persister_applies_wxr_postmeta_to_imported_draft() {
		$session  = ImportSession::start_for_source( '/tmp/export.xml' );
		$document = $this->document(
			$session,
			array(
				array(
					'key'   => '_seo_title',
					'value' => 'SEO Title',
				),
				array(
					'key'   => '_edit_last',
					'value' => '42',
				),
				array(
					'key'   => '_universal_importer_session_id',
					'value' => 'bad-overwrite',
				),
			)
		);

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );
		$this->posts->insert_or_update( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $document->get_source_item_key(), 'post', '1', $document->get_content_hash() )
		);

		$summary = ( new ImportPostMetaPersister( $this->store, $this->posts ) )->advance( $session );
		$post    = $this->posts->get_post( 1 );
		$record  = $this->store->find_idempotency_record( $session->get_id(), 'postmeta:' . $document->get_source_item_key() );
		$event   = $this->store->list_events( $session->get_id(), 1 )[0];

		$this->assertSame( 1, $summary['applied'] );
		$this->assertSame( 'SEO Title', $post['meta']['_seo_title'] );
		$this->assertArrayNotHasKey( '_edit_last', $post['meta'] );
		$this->assertArrayNotHasKey( '_universal_importer_session_id', $post['meta'] );
		$this->assertNotNull( $record );
		$this->assertSame( 'postmeta', $record->get_resource_type() );
		$this->assertSame( 'postmeta.applied', $event->get_type() );
		$this->assertSame( 1, $event->get_context()['applied'] );
		$this->assertSame( 2, $event->get_context()['skipped'] );
		$this->assertContains( '_edit_last', $event->get_context()['skipped_keys'] );
	}

	/**
	 * Re-running postmeta persistence skips unchanged metadata by idempotency hash.
	 *
	 * @return void
	 */
	public function test_persister_skips_unchanged_wxr_postmeta() {
		$session  = ImportSession::start_for_source( '/tmp/export.xml' );
		$document = $this->document(
			$session,
			array(
				array(
					'key'   => '_seo_title',
					'value' => 'SEO Title',
				),
			)
		);

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );
		$this->posts->insert_or_update( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $document->get_source_item_key(), 'post', '1', $document->get_content_hash() )
		);

		$persister = new ImportPostMetaPersister( $this->store, $this->posts );
		$persister->advance( $session );
		$summary = $persister->advance( $session );

		$this->assertSame( 0, $summary['applied'] );
		$this->assertSame( 1, $summary['skipped'] );
	}

	/**
	 * Postmeta persistence scans beyond the first prepared-document page.
	 *
	 * @return void
	 */
	public function test_persister_finds_wxr_postmeta_after_first_document_page() {
		$session = ImportSession::start_for_source( '/tmp/export.xml' );

		$this->store->save( $session );

		for ( $index = 1; $index <= 30; ++$index ) {
			$post_id  = str_pad( (string) $index, 3, '0', STR_PAD_LEFT );
			$metadata = 30 === $index
				? array(
					array(
						'key'   => '_seo_title',
						'value' => 'Late SEO Title',
					),
				)
				: array();

			$this->store->save_prepared_document( $this->document( $session, $metadata, $post_id ) );
		}

		$late_document = $this->store->find_prepared_document( $session->get_id(), 'local:export:wxr-post:030' );
		$post_id       = $this->posts->insert_or_update( $late_document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $late_document->get_source_item_key(), 'post', (string) $post_id, $late_document->get_content_hash() )
		);

		$summary = ( new ImportPostMetaPersister( $this->store, $this->posts ) )->advance( $session, 25 );

		$this->assertSame( 1, $summary['applied'] );
		$this->assertSame( 'Late SEO Title', $this->posts->get_post( $post_id )['meta']['_seo_title'] );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'postmeta:local:export:wxr-post:030' ) );
	}

	/**
	 * Postmeta waits for the draft post when WXR metadata arrives after content persistence.
	 *
	 * @return void
	 */
	public function test_persister_defers_until_imported_draft_exists() {
		$session  = ImportSession::start_for_source( '/tmp/export.xml' );
		$document = $this->document(
			$session,
			array(
				array(
					'key'   => '_seo_title',
					'value' => 'SEO Title',
				),
			)
		);

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );

		$summary = ( new ImportPostMetaPersister( $this->store, $this->posts ) )->advance( $session );
		$event   = $this->store->list_events( $session->get_id(), 1 )[0];

		$this->assertSame( 1, $summary['deferred'] );
		$this->assertSame( 'postmeta.deferred', $event->get_type() );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'postmeta:' . $document->get_source_item_key() ) );
	}

	/**
	 * Postmeta write failures are observable and retryable.
	 *
	 * @return void
	 */
	public function test_persister_records_wxr_postmeta_failures() {
		$session  = ImportSession::start_for_source( '/tmp/export.xml' );
		$document = $this->document(
			$session,
			array(
				array(
					'key'   => '_seo_title',
					'value' => 'SEO Title',
				),
			)
		);

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );
		$this->posts->insert_or_update( $document );
		$this->posts->fail_postmeta_writes_with( 'WordPress rejected postmeta.' );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $document->get_source_item_key(), 'post', '1', $document->get_content_hash() )
		);

		$summary = ( new ImportPostMetaPersister( $this->store, $this->posts ) )->advance( $session );
		$event   = $this->store->list_events( $session->get_id(), 1 )[0];

		$this->assertSame( 1, $summary['failed'] );
		$this->assertSame( 'postmeta.failed', $event->get_type() );
		$this->assertSame( 'WordPress rejected postmeta.', $event->get_message() );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'postmeta:' . $document->get_source_item_key() ) );
	}

	/**
	 * WXR `_thumbnail_id` is remapped to the imported local attachment id.
	 *
	 * @return void
	 */
	public function test_persister_applies_wxr_featured_media_after_attachment_import() {
		$session   = ImportSession::start_for_source( '/tmp/export.xml' );
		$document  = $this->document(
			$session,
			array(
				array(
					'key'   => '_thumbnail_id',
					'value' => '31',
				),
				array(
					'key'   => '_seo_title',
					'value' => 'SEO Title',
				),
			)
		);
		$reference = ImportMediaReference::queued(
			$session->get_id(),
			ImportWxrAttachment::reference_key( 'local:export', 31 ),
			ImportWxrAttachment::source_item_key( 'local:export', 31 ),
			'https://source.example.test/uploads/photo.jpg',
			'https://source.example.test/uploads/photo.jpg',
			ImportMediaReference::TYPE_IMAGE,
			array(
				'reference_scope'   => ImportWxrAttachment::SCOPE_CANDIDATE_FIRST_PARTY,
				'wxr_attachment_id' => '31',
			)
		)->mark_imported( 100, 'https://local.example.test/wp-content/uploads/photo.jpg', 'hash-photo' );

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );
		$this->store->save_media_reference( $reference );
		$this->posts->insert_or_update( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $document->get_source_item_key(), 'post', '1', $document->get_content_hash() )
		);

		$summary = ( new ImportPostMetaPersister( $this->store, $this->posts ) )->advance( $session );
		$post    = $this->posts->get_post( 1 );
		$events  = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 5 )
		);

		$this->assertSame( 1, $summary['applied'] );
		$this->assertSame( 100, $post['meta']['_thumbnail_id'] );
		$this->assertSame( 'SEO Title', $post['meta']['_seo_title'] );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'thumbnail:' . $document->get_source_item_key() ) );
		$this->assertContains( 'thumbnail.applied', $events );
	}

	/**
	 * Attachment-oriented WXR postmeta values are remapped before direct persistence.
	 *
	 * @return void
	 */
	public function test_persister_remaps_attachment_ids_and_urls_inside_wxr_postmeta() {
		$session        = ImportSession::start_for_source( '/tmp/export.xml' );
		$attachment_url = 'https://source.example.test/uploads/gallery-one.jpg';
		$document       = $this->document(
			$session,
			array(
				array(
					'key'   => '_wp_image_gallery',
					'value' => '31, 32',
				),
				array(
					'key'   => '_builder_data',
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- This fixture verifies WXR PHP-serialized postmeta remapping.
					'value' => serialize(
						array(
							'image_id' => '31',
							'image'    => array(
								'id'  => '32',
								'url' => $attachment_url,
							),
							'caption'  => 'Keep remote ids that are not attachment-oriented: 31',
						)
					),
				),
			)
		);

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );
		$this->store->save_media_reference( $this->imported_wxr_attachment_reference( $session, 31, $attachment_url, 100, 'https://local.example.test/wp-content/uploads/gallery-one.jpg' ) );
		$this->store->save_media_reference( $this->imported_wxr_attachment_reference( $session, 32, 'https://source.example.test/uploads/gallery-two.jpg', 101, 'https://local.example.test/wp-content/uploads/gallery-two.jpg' ) );
		$this->posts->insert_or_update( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $document->get_source_item_key(), 'post', '1', $document->get_content_hash() )
		);

		$summary = ( new ImportPostMetaPersister( $this->store, $this->posts ) )->advance( $session );
		$post    = $this->posts->get_post( 1 );
		$event   = $this->store->list_events( $session->get_id(), 1 )[0];

		$this->assertSame( 1, $summary['applied'] );
		$this->assertSame( '100,101', $post['meta']['_wp_image_gallery'] );
		$this->assertSame( 100, $post['meta']['_builder_data']['image_id'] );
		$this->assertSame( 101, $post['meta']['_builder_data']['image']['id'] );
		$this->assertSame( 'https://local.example.test/wp-content/uploads/gallery-one.jpg', $post['meta']['_builder_data']['image']['url'] );
		$this->assertSame( 'Keep remote ids that are not attachment-oriented: 31', $post['meta']['_builder_data']['caption'] );
		$this->assertSame( 'postmeta.applied', $event->get_type() );
		$this->assertGreaterThanOrEqual( 5, $event->get_context()['remapped'] );
	}

	/**
	 * Attachment remapping scans media references beyond the first page.
	 *
	 * @return void
	 */
	public function test_persister_remaps_attachment_ids_after_first_media_reference_page() {
		$session  = ImportSession::start_for_source( '/tmp/export.xml' );
		$document = $this->document(
			$session,
			array(
				array(
					'key'   => '_wp_image_gallery',
					'value' => '501',
				),
			)
		);

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );

		for ( $index = 1; $index <= 501; ++$index ) {
			$this->store->save_media_reference(
				$this->imported_wxr_attachment_reference(
					$session,
					$index,
					'https://source.example.test/uploads/gallery-' . $index . '.jpg',
					400 + $index,
					'https://local.example.test/wp-content/uploads/gallery-' . $index . '.jpg'
				)
			);
		}

		$this->posts->insert_or_update( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $document->get_source_item_key(), 'post', '1', $document->get_content_hash() )
		);

		$summary = ( new ImportPostMetaPersister( $this->store, $this->posts ) )->advance( $session );
		$post    = $this->posts->get_post( 1 );

		$this->assertSame( 1, $summary['applied'] );
		$this->assertSame( '901', $post['meta']['_wp_image_gallery'] );
	}

	/**
	 * Post/page-oriented WXR postmeta ids are remapped to imported draft ids.
	 *
	 * @return void
	 */
	public function test_persister_remaps_wxr_post_and_page_ids_inside_postmeta() {
		$session          = ImportSession::start_for_source( '/tmp/export.xml' );
		$document         = $this->document(
			$session,
			array(
				array(
					'key'   => '_builder_data',
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- This fixture verifies WXR PHP-serialized post id remapping.
					'value' => serialize(
						array(
							'featured_post_id' => '12',
							'related_posts'    => array( '12', '99' ),
							'page'             => array(
								'id'    => '12',
								'title' => 'Related source page',
							),
							'author_id'        => '12',
							'term_id'          => '12',
							'caption'          => 'Keep arbitrary source post id 12 in prose.',
						)
					),
				),
			),
			11
		);
		$related_document = $this->document( $session, array(), 12 );

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );
		$this->store->save_prepared_document( $related_document );
		$this->posts->insert_or_update( $document );
		$related_post_id = $this->posts->insert_or_update( $related_document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $document->get_source_item_key(), 'post', '1', $document->get_content_hash() )
		);
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $related_document->get_source_item_key(), 'post', (string) $related_post_id, $related_document->get_content_hash() )
		);

		$summary = ( new ImportPostMetaPersister( $this->store, $this->posts ) )->advance( $session );
		$post    = $this->posts->get_post( 1 );
		$meta    = $post['meta']['_builder_data'];
		$event   = $this->store->list_events( $session->get_id(), 1 )[0];

		$this->assertSame( 1, $summary['applied'] );
		$this->assertSame( (string) $related_post_id, $meta['featured_post_id'] );
		$this->assertSame( array( (string) $related_post_id, '99' ), $meta['related_posts'] );
		$this->assertSame( (string) $related_post_id, $meta['page']['id'] );
		$this->assertSame( '12', $meta['author_id'] );
		$this->assertSame( '12', $meta['term_id'] );
		$this->assertSame( 'Keep arbitrary source post id 12 in prose.', $meta['caption'] );
		$this->assertSame( 'postmeta.applied', $event->get_type() );
		$this->assertGreaterThanOrEqual( 3, $event->get_context()['remapped'] );
	}

	/**
	 * Confirmed first-party URLs in WXR postmeta are rewritten before persistence.
	 *
	 * @return void
	 */
	public function test_persister_rewrites_confirmed_first_party_urls_inside_wxr_postmeta() {
		$session  = ImportSession::start_for_source( '/tmp/export.xml' );
		$document = $this->document(
			$session,
			array(
				array(
					'key'   => '_builder_data',
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- This fixture verifies URL rewriting inside WXR PHP-serialized postmeta.
					'value' => serialize(
						array(
							'permalink' => 'https://source.example.test/articles/source-page/?preview=true#intro',
							'external'  => 'https://outside.example.test/articles/source-page/',
							'copy'      => 'See https://source.example.test/related/.',
						)
					),
				),
			)
		);

		$this->store->save( $session );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Confirm first-party domains.',
				array( 'domains' => array( 'source.example.test' ) )
			)->resolve( array( 'confirmed_domains' => array( 'source.example.test' ) ) )
		);
		$this->store->save_prepared_document( $document );
		$this->posts->insert_or_update( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $document->get_source_item_key(), 'post', '1', $document->get_content_hash() )
		);

		$summary = ( new ImportPostMetaPersister( $this->store, $this->posts, 'https://local.example.test/site/' ) )->advance( $session );
		$post    = $this->posts->get_post( 1 );
		$meta    = $post['meta']['_builder_data'];
		$event   = $this->store->list_events( $session->get_id(), 1 )[0];

		$this->assertSame( 1, $summary['applied'] );
		$this->assertSame( 'https://local.example.test/site/articles/source-page/?preview=true#intro', $meta['permalink'] );
		$this->assertSame( 'https://outside.example.test/articles/source-page/', $meta['external'] );
		$this->assertSame( 'See https://local.example.test/site/related/.', $meta['copy'] );
		$this->assertSame( 'postmeta.applied', $event->get_type() );
		$this->assertSame( 2, $event->get_context()['url_rewritten'] );
		$this->assertGreaterThanOrEqual( 2, $event->get_context()['remapped'] );
	}

	/**
	 * Post/page-sensitive WXR postmeta waits for referenced drafts to be imported.
	 *
	 * @return void
	 */
	public function test_persister_defers_wxr_post_id_references_until_related_post_imports() {
		$session          = ImportSession::start_for_source( '/tmp/export.xml' );
		$document         = $this->document(
			$session,
			array(
				array(
					'key'   => '_builder_data',
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- This fixture verifies WXR PHP-serialized post id deferral.
					'value' => serialize(
						array(
							'related_post_id' => '12',
						)
					),
				),
			),
			11
		);
		$related_document = $this->document( $session, array(), 12 );

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );
		$this->store->save_prepared_document( $related_document );
		$this->posts->insert_or_update( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $document->get_source_item_key(), 'post', '1', $document->get_content_hash() )
		);

		$summary = ( new ImportPostMetaPersister( $this->store, $this->posts ) )->advance( $session );
		$post    = $this->posts->get_post( 1 );
		$event   = $this->store->list_events( $session->get_id(), 1 )[0];

		$this->assertSame( 1, $summary['deferred'] );
		$this->assertArrayNotHasKey( '_builder_data', isset( $post['meta'] ) ? $post['meta'] : array() );
		$this->assertSame( 'postmeta.deferred_references', $event->get_type() );
		$this->assertSame( array( '12' ), $event->get_context()['deferred_post_ids'] );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'postmeta:' . $document->get_source_item_key() ) );
	}

	/**
	 * Unknown WXR post ids wait while the WXR source item is only partially parsed.
	 *
	 * @return void
	 */
	public function test_persister_defers_unknown_wxr_post_ids_while_source_is_partial() {
		$session  = ImportSession::start_for_source( '/tmp/export.xml' );
		$document = $this->document(
			$session,
			array(
				array(
					'key'   => 'related_post_id',
					'value' => '77',
				),
			)
		);
		$item     = ImportSourceItem::queued(
			$session->get_id(),
			'local:export',
			null,
			'/tmp/export.xml',
			'export.xml',
			ImportSourceItem::TYPE_FILE,
			array(
				'document_format'  => 'wxr',
				'processor_status' => 'partial',
			)
		)->with_status( ImportSourceItem::STATUS_DISCOVERED );

		$this->store->save( $session );
		$this->store->save_source_item( $item );
		$this->store->save_prepared_document( $document );
		$this->posts->insert_or_update( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $document->get_source_item_key(), 'post', '1', $document->get_content_hash() )
		);

		$summary = ( new ImportPostMetaPersister( $this->store, $this->posts ) )->advance( $session );
		$event   = $this->store->list_events( $session->get_id(), 1 )[0];

		$this->assertSame( 1, $summary['deferred'] );
		$this->assertSame( 'postmeta.deferred_references', $event->get_type() );
		$this->assertSame( array( '77' ), $event->get_context()['deferred_post_ids'] );
	}

	/**
	 * WXR postmeta waits when attachment-sensitive values reference queued media.
	 *
	 * @return void
	 */
	public function test_persister_defers_attachment_sensitive_wxr_postmeta_until_media_imports() {
		$session        = ImportSession::start_for_source( '/tmp/export.xml' );
		$attachment_url = 'https://source.example.test/uploads/pending.jpg';
		$document       = $this->document(
			$session,
			array(
				array(
					'key'   => '_builder_data',
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- This fixture verifies WXR PHP-serialized postmeta deferral.
					'value' => serialize(
						array(
							'image_id' => '31',
							'image'    => array(
								'url' => $attachment_url,
							),
						)
					),
				),
			)
		);

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );
		$this->store->save_media_reference( $this->queued_wxr_attachment_reference( $session, 31, $attachment_url ) );
		$this->posts->insert_or_update( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $document->get_source_item_key(), 'post', '1', $document->get_content_hash() )
		);

		$summary = ( new ImportPostMetaPersister( $this->store, $this->posts ) )->advance( $session );
		$post    = $this->posts->get_post( 1 );
		$event   = $this->store->list_events( $session->get_id(), 1 )[0];

		$this->assertSame( 1, $summary['deferred'] );
		$this->assertArrayNotHasKey( '_builder_data', isset( $post['meta'] ) ? $post['meta'] : array() );
		$this->assertSame( 'postmeta.deferred_references', $event->get_type() );
		$this->assertSame( array( '31' ), $event->get_context()['deferred_ids'] );
		$this->assertSame( array( $attachment_url ), $event->get_context()['deferred_urls'] );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'postmeta:' . $document->get_source_item_key() ) );
	}

	/**
	 * WXR `_thumbnail_id` waits until the matching attachment media reference is imported.
	 *
	 * @return void
	 */
	public function test_persister_defers_wxr_featured_media_until_attachment_exists() {
		$session  = ImportSession::start_for_source( '/tmp/export.xml' );
		$document = $this->document(
			$session,
			array(
				array(
					'key'   => '_thumbnail_id',
					'value' => '31',
				),
			)
		);

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );
		$this->posts->insert_or_update( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $document->get_source_item_key(), 'post', '1', $document->get_content_hash() )
		);

		$summary = ( new ImportPostMetaPersister( $this->store, $this->posts ) )->advance( $session );
		$events  = array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 2 )
		);

		$this->assertSame( 1, $summary['deferred'] );
		$this->assertContains( 'thumbnail.deferred', $events );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'thumbnail:' . $document->get_source_item_key() ) );
	}

	/**
	 * Builds a WXR prepared document fixture.
	 *
	 * @param ImportSession $session Session.
	 * @param array         $postmeta Staged postmeta entries.
	 * @param int|string    $post_id  Remote WXR post id.
	 * @return ImportPreparedDocument
	 */
	private function document( ImportSession $session, array $postmeta, $post_id = 11 ) {
		$post_id = (string) $post_id;

		return new ImportPreparedDocument(
			$session->get_id(),
			'local:export:wxr-post:' . $post_id,
			'wxr',
			'Imported WXR Post ' . $post_id,
			'<!-- wp:paragraph --><p>Imported WXR Post ' . $post_id . '</p><!-- /wp:paragraph -->',
			1,
			'hash-wxr-post-' . $post_id,
			array(
				'relative_path'       => 'export.xml',
				'wxr_source_item_key' => 'local:export',
				'wxr_post_id'         => $post_id,
				'wxr_postmeta'        => $postmeta,
				'wxr_postmeta_count'  => count( $postmeta ),
			)
		);
	}

	/**
	 * Builds an imported WXR attachment media reference fixture.
	 *
	 * @param ImportSession $session        Session.
	 * @param int           $remote_id      Remote WXR attachment id.
	 * @param string        $source_url     Source attachment URL.
	 * @param int           $attachment_id  Local attachment id.
	 * @param string        $attachment_url Local attachment URL.
	 * @return ImportMediaReference
	 */
	private function imported_wxr_attachment_reference( ImportSession $session, $remote_id, $source_url, $attachment_id, $attachment_url ) {
		return $this->queued_wxr_attachment_reference( $session, $remote_id, $source_url )->mark_imported( $attachment_id, $attachment_url, 'hash-' . (string) $remote_id );
	}

	/**
	 * Builds a queued WXR attachment media reference fixture.
	 *
	 * @param ImportSession $session    Session.
	 * @param int           $remote_id  Remote WXR attachment id.
	 * @param string        $source_url Source attachment URL.
	 * @return ImportMediaReference
	 */
	private function queued_wxr_attachment_reference( ImportSession $session, $remote_id, $source_url ) {
		return ImportMediaReference::queued(
			$session->get_id(),
			ImportWxrAttachment::reference_key( 'local:export', $remote_id ),
			ImportWxrAttachment::source_item_key( 'local:export', $remote_id ),
			$source_url,
			$source_url,
			ImportMediaReference::TYPE_IMAGE,
			array(
				'reference_scope'     => ImportWxrAttachment::SCOPE_CANDIDATE_FIRST_PARTY,
				'wxr_attachment_id'   => (string) $remote_id,
				'wxr_guid'            => $source_url,
				'absolute_url_domain' => 'source.example.test',
				'source'              => 'wxr',
			)
		);
	}
}
